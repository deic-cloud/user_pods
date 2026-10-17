<?php

declare(strict_types=1);

namespace OCA\UserPods\Service;

use OCA\UserPods\Exception\PodHostException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
use OCP\Files\IRootFolder;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * Thin client over the sciencedata_kubernetes host service. Each method maps to
 * one of the host's `*.php` endpoints (reached at http://<podManagementIP>/...), or to
 * the GitHub manifest library. The host service (the battle-tested run_pod bash
 * script et al.) is NOT touched — this only preserves the request contract.
 *
 * Port of the OC7 OC_Kubernetes_Util.
 */
class PodService {
	private string $publicIP;
	private string $podManagementIP;
	private string $storageDir;
	private string $manifestsURL;
	private string $rawManifestsURL;
	private string $getContainersPassword;
	private int $maxPodsPerUser;
	/** @var string[] */
	private array $unlimitedUsers = [];

	public function __construct(
		IAppConfig $appConfig,
		private IClientService $clientService,
		private GroupBridge $groups,
		private LoggerInterface $logger,
		private ICacheFactory $cacheFactory,
		private ?IGroupManager $groupManager = null,
		private ?IRootFolder $rootFolder = null,
	) {
		$this->publicIP = $appConfig->getValueString('user_pods', 'publicIP', '');
		// Renamed from the vague 'privateIP'; fall back to it for existing installs.
		$this->podManagementIP = $appConfig->getValueString('user_pods', 'podManagementIP', '')
			?: $appConfig->getValueString('user_pods', 'privateIP', '');
		$this->storageDir = $appConfig->getValueString('user_pods', 'storageDir', '');
		// Manifest library defaults point at the deic-dk pod_manifests repo.
		$this->manifestsURL = $appConfig->getValueString('user_pods', 'manifestsURL',
			'https://api.github.com/repos/deic-dk/pod_manifests/contents');
		$this->rawManifestsURL = $appConfig->getValueString('user_pods', 'rawManifestsURL',
			'https://raw.githubusercontent.com/deic-dk/pod_manifests/main/');
		// Shared secret proving the caller is authorised to query the host's
		// get_containers endpoint. The endpoint lives on the 10.2 pod network
		// where any pod can reach it, so this gates access (low-value data — a
		// weak password by design). Sent as a plain ?password= GET param, matching
		// the host side. Empty = don't send (endpoint then open, as before).
		$this->getContainersPassword = $appConfig->getValueString('user_pods', 'getContainersPassword', '');
		// Per-user cap on simultaneous pods (admin setting; 0 = unlimited). The host
		// side has no cap of its own beyond its pod-naming scheme, so this is where
		// the limit is set and explained to the user.
		$this->maxPodsPerUser = max(0, (int)$appConfig->getValueString('user_pods', 'maxPodsPerUser', '5'));
		// Accounts the limit does not apply to (service accounts such as 'cloud'
		// or 'batch'); administrators are always exempt.
		$this->unlimitedUsers = array_values(array_filter(array_map('trim',
			preg_split('/[\s,;]+/', $appConfig->getValueString('user_pods', 'unlimitedPodUsers', '')) ?: [])));
	}

	/** Does the per-user pod limit apply to this account? */
	public function limitApplies(string $uid): bool {
		if ($this->maxPodsPerUser <= 0 || in_array($uid, $this->unlimitedUsers, true)) {
			return false;
		}
		try {
			if ($this->groupManager !== null && $this->groupManager->isAdmin($uid)) {
				return false;
			}
		} catch (\Throwable) {
		}
		return true;
	}

	public function getMaxPodsPerUser(): int {
		return $this->maxPodsPerUser;
	}

	public function getRawManifestsURL(): string {
		return $this->rawManifestsURL;
	}

	/**
	 * GET a URL and return the body as a string.
	 *
	 * A successful but empty body returns '' (e.g. get_containers with no pods —
	 * a legitimate result, not an error). A transport-level failure throws
	 * PodHostException so the caller/controller can surface it to the user
	 * instead of it vanishing into an empty response. (Previously this swallowed
	 * every failure into '', which is exactly how the IClientService SSRF block
	 * silently turned the whole app into a no-op — see allow_local_address below.)
	 */
	private function httpGet(string $url, bool $verify = true): string {
		try {
			$response = $this->clientService->newClient()->get($url, [
				'verify' => $verify,
				'timeout' => 60,
				'headers' => ['User-Agent' => 'Nextcloud-user_pods'],
				// The host service lives on a private management IP (podManagementIP,
				// e.g. 10.0.0.12). NC's IClientService blocks local/private hosts
				// as SSRF targets by default, which silently turns every pod call
				// into an empty response. Opt this trusted endpoint back in per
				// request rather than forcing admins to set the instance-wide
				// allow_local_remote_servers. Harmless for the public GitHub
				// manifest URLs (they are not local addresses).
				'nextcloud' => ['allow_local_address' => true],
			]);
			return (string)$response->getBody();
		} catch (\Throwable $e) {
			$host = parse_url($url, PHP_URL_HOST) ?: $url;
			$this->logger->error('user_pods: host call failed (GET ' . $url . '): ' . $e->getMessage(),
				['app' => 'user_pods', 'exception' => $e]);
			throw new PodHostException('Pod host service unreachable (' . $host . '): ' . $e->getMessage(), 0, $e);
		}
	}

	private function podEndpoint(string $script, array $params): string {
		return 'http://' . $this->podManagementIP . '/' . $script . '?' . http_build_query($params);
	}

	/**
	 * Make sure the user's storage folder, and $sub inside it, exist before a
	 * container attaches it over NFS: this server is the user's home server, so
	 * the folder is local. Refuses a $sub that climbs out of the user's folder.
	 */
	public function createStorageDir(string $uid, string $sub = ''): void {
		if ($this->storageDir === '') {
			return;
		}
		$sub = trim($sub, '/');
		if (in_array('..', explode('/', $sub), true) || str_contains($uid, '/') || str_starts_with($uid, '.')) {
			throw new PodHostException('Invalid storage folder: ' . $sub);
		}
		$path = rtrim($this->storageDir, '/') . '/' . $uid . ($sub === '' ? '' : '/' . $sub);
		if (!is_dir($path) && !@mkdir($path, 0755, true) && !is_dir($path)) {
			throw new PodHostException('Could not create the storage folder /storage/' . $sub);
		}
	}

	/**
	 * The user's pods. Parses the host's pipe-delimited table and builds https/ssh
	 * URLs from the public IP. Owner-checked: never surfaces another user's pods.
	 *
	 * @param string[]|null $podNames optional filter
	 * @return array<int, array<string, string>>
	 */
	public function getContainers(string $uid, ?array $podNames = null): array {
		$params = ['fields' => 'include', 'user_id' => $uid];
		if ($this->getContainersPassword !== '') {
			$params['password'] = $this->getContainersPassword;
		}
		$url = $this->podEndpoint('get_containers.php', $params);
		$response = trim($this->httpGet($url, false));
		if ($response === '') {
			return [];
		}
		$rows = explode("\n", $response);
		$fields = explode('|', array_shift($rows));
		$containers = [];
		foreach ($rows as $row) {
			if ($row === '') {
				continue;
			}
			$values = explode('|', $row);
			$c = [];
			foreach ($values as $i => $value) {
				$c[$fields[$i] ?? $i] = $value;
			}
			// Defence in depth: the host already filters by user_id.
			if (($c['owner'] ?? '') !== $uid) {
				$this->logger->error('user_pods: host returned a pod not owned by ' . $uid
					. ' (owner ' . ($c['owner'] ?? '') . ')', ['app' => 'user_pods']);
				continue;
			}
			if (!empty($c['uri']) || !empty($c['https_port'])) {
				$c['url'] = 'https://' . $this->publicIP
					. (empty($c['https_port']) ? '' : ':' . $c['https_port'])
					. '/' . ($c['uri'] ?? '');
			} else {
				$c['url'] = '';
			}
			unset($c['uri'], $c['https_port']);
			if (!empty($c['ssh_port'])) {
				$c['ssh_url'] = 'ssh://'
					. (empty($c['ssh_username']) ? '' : $c['ssh_username'] . '@')
					. $this->publicIP . ':' . $c['ssh_port'];
			} else {
				$c['ssh_url'] = '';
			}
			unset($c['ssh_port'], $c['ssh_username']);
			$c['age_seconds'] = (int)($c['age'] ?? 0);
			if (!empty($c['age'])) {
				$c['age'] = floor((int)$c['age'] / 3600) . gmdate(':i:s', (int)$c['age'] % 3600);
			}
			if (empty($podNames) || in_array($c['pod_name'] ?? '', $podNames, true)) {
				$containers[] = $c;
			}
		}
		return $containers;
	}

	/** Available manifest filenames (*.yaml) from the GitHub manifest library. */
	private function getManifests(): array {
		$json = $this->httpGet($this->manifestsURL);
		$arr = json_decode($json, true);
		if (!is_array($arr)) {
			return [];
		}
		$names = [];
		foreach ($arr as $entry) {
			$name = $entry['name'] ?? '';
			if (substr($name, -5) === '.yaml') {
				$names[] = $name;
			}
		}
		sort($names);
		return $names;
	}

	/**
	 * Inspect one manifest: enforce its access-control labels (domain/user/group)
	 * for $uid, then surface what the pod-creation form needs (accepted env vars,
	 * mounts, peers, etc.) plus the human-readable .md description. Returns [] if
	 * the user may not launch it.
	 */
	public function checkManifest(string $uid, string $yamlFile): array {
		if ($yamlFile === '') {
			return [];
		}
		if (!function_exists('yaml_parse')) {
			throw new PodHostException('This server cannot read container manifests: the PHP yaml extension is not installed.');
		}
		$yamlUrl = $this->rawManifestsURL . $yamlFile;
		$arr = yaml_parse($this->httpGet($yamlUrl));
		if (!is_array($arr)) {
			return [];
		}
		$labels = $arr['metadata']['labels'] ?? [];
		$podTypes = empty($labels['types']) ? [] : explode('-', (string)$labels['types']);
		if (!$this->mayLaunch($uid, is_array($labels) ? $labels : [])) {
			$this->logger->info('user_pods: ' . $uid . ' not allowed manifest ' . $yamlFile, ['app' => 'user_pods']);
			return [];
		}

		$mdFile = preg_replace('/\.yaml$/', '.md', $yamlFile);
		$manifestInfo = $this->httpGet($this->rawManifestsURL . $mdFile);

		$podAcceptsPublicKey = false;
		$podAcceptsFile = false;
		$nfsRw = false;
		$podFile = '';
		$podPeers = null;
		$podPeersImage = null;
		$podUsername = '';
		$podMountPath = [];
		$podMountSrc = '';
		$cvmfsRepos = '';
		$setupScript = '';
		$containerInfos = [];

		foreach (($arr['spec']['containers'] ?? []) as $container) {
			$acceptsPublicKey = false;
			$username = '';
			$mountPaths = [];
			$imageName = (string)($container['image'] ?? '');
			foreach (($container['env'] ?? []) as $env) {
				$name = $env['name'] ?? '';
				$value = $env['value'] ?? '';
				switch ($name) {
					case 'SSH_PUBLIC_KEY': $acceptsPublicKey = true; $podAcceptsPublicKey = true; break;
					case 'USERNAME': if ($value !== '') { $username = $value; $podUsername = $value; } break;
					case 'MOUNT_SRC': if ($value !== '') { $podMountSrc = $value; } break;
					case 'CVMFS_REPOS': if ($value !== '') { $cvmfsRepos = $value; } break;
					case 'SETUP_SCRIPT': if ($value !== '') { $setupScript = $value; } break;
					case 'NFS_RW': if ($value !== '') { $nfsRw = $value; } break;
					case 'FILE': $podAcceptsFile = true; if ($value !== '') { $podFile = $value; } break;
					case 'PEERS': if ($value !== '') { $podPeers = $value; } break;
					case 'PEERS_IMAGE': if ($value !== '') { $podPeersImage = $value; } break;
				}
			}
			if (!empty($container['volumeMounts'])) {
				$podMountPath[$container['volumeMounts'][0]['name']] = $container['volumeMounts'][0]['mountPath'];
				foreach ($container['volumeMounts'] as $vm) {
					$mountPaths[$vm['name']] = $vm['mountPath'];
				}
			}
			$containerInfos[] = [
				'image_name' => $imageName,
				'accepts_public_key' => $acceptsPublicKey,
				'username' => $username,
				'mount_paths' => $mountPaths,
			];
		}

		$ret = [
			'manifest_url' => $yamlUrl,
			'manifest_info' => $manifestInfo,
			'pod_accepts_public_key' => $podAcceptsPublicKey,
			'pod_accepts_file' => $podAcceptsFile,
			'pod_file' => $podFile,
			'pod_peers' => $podPeers,
			'pod_peers_image' => $podPeersImage,
			'pod_username' => $podUsername,
			'pod_mount_path' => $podMountPath,
			'pod_mount_src' => $podMountSrc,
			'container_infos' => $containerInfos,
			'cvmfs_repos' => $cvmfsRepos,
			'setup_script' => $setupScript,
			'nfs_rw' => $nfsRw,
			'pod_types' => $podTypes,
		];
		return array_filter($ret, static fn ($v) => $v !== null);
	}

	/** Whether a manifest's access labels (domain/user/group) let $uid launch it. */
	private function mayLaunch(string $uid, array $labels): bool {
		$yamlGroup = (string)($labels['group'] ?? '');
		$yamlDomain = (string)($labels['domain'] ?? '');
		$yamlUser = (string)($labels['user'] ?? '');
		if ($yamlDomain === '' && $yamlUser === '' && $yamlGroup === '') {
			return true; // no restriction
		}
		$shortUser = $uid;
		$domain = '';
		if (str_contains($uid, '@')) {
			[$shortUser, $domain] = explode('@', $uid, 2);
		}
		if ($yamlDomain === '' && $yamlUser !== '' && $yamlUser === $shortUser) {
			return true; // system user
		}
		if ($yamlUser === '' && $yamlDomain !== '' && $yamlDomain === $domain) {
			return true; // whole domain
		}
		// Specific user within a domain. (OC7 returned [] here — a bug; it had
		// just confirmed a match. Treat as allowed.)
		if ($yamlUser !== '' && $yamlDomain !== '' && $yamlUser === $shortUser && $yamlDomain === $domain) {
			return true;
		}
		return $yamlGroup !== '' && $this->groups->inGroup($uid, $yamlGroup); // group member
	}

	private const LIBRARY_TTL = 600;

	/**
	 * The whole manifest library — labels, annotations and description of every
	 * manifest — fetched in parallel and cached for LIBRARY_TTL seconds, so the
	 * catalog does not cost one listing call (rate-limited) plus two fetches per
	 * image on every page load.
	 *
	 * @return array<string, array{labels: array, annotations: array, md: string}>
	 */
	private function library(): array {
		$cache = $this->cacheFactory->createDistributed('user_pods');
		$hit = $cache->get('library');
		if (is_array($hit)) {
			return $hit;
		}
		if (!function_exists('yaml_parse')) {
			throw new PodHostException('This server cannot read container manifests: the PHP yaml extension is not installed.');
		}
		try {
			$names = $this->getManifests();
		} catch (PodHostException $e) {
			// The library cannot be listed (e.g. GitHub unreachable): serve the
			// last good copy if there is one.
			$stale = $cache->get('library_stale');
			if (is_array($stale)) {
				return $stale;
			}
			throw $e;
		}
		$urls = [];
		foreach ($names as $name) {
			$urls[$name] = $this->rawManifestsURL . $name;
			$urls[$name . '#md'] = $this->rawManifestsURL . preg_replace('/\.yaml$/', '.md', $name);
		}
		$bodies = $this->fetchAll($urls);
		$lib = [];
		foreach ($names as $name) {
			$arr = isset($bodies[$name]) ? @yaml_parse($bodies[$name]) : null;
			if (!is_array($arr)) {
				$this->logger->warning('user_pods: could not read manifest ' . $name, ['app' => 'user_pods']);
				continue;
			}
			$meta = $arr['metadata'] ?? [];
			$lib[$name] = [
				'name' => (string)($meta['name'] ?? ''),
				'labels' => is_array($meta['labels'] ?? null) ? $meta['labels'] : [],
				'annotations' => is_array($meta['annotations'] ?? null) ? $meta['annotations'] : [],
				'md' => $bodies[$name . '#md'] ?? '',
			];
		}
		$this->addIcons($lib);
		if ($lib !== []) {
			$cache->set('library', $lib, self::LIBRARY_TTL);
			$cache->set('library_stale', $lib, 86400);
		}
		return $lib;
	}

	private const ICON_TYPES = ['svg' => 'image/svg+xml', 'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp'];
	private const ICON_MAX_BYTES = 100 * 1024;

	/**
	 * Fetch the catalog/icon of each manifest that has one - a path in the
	 * library, e.g. icons/immich.svg - and keep it as a data: URI. Inlined
	 * because the page's content policy only allows images from this server,
	 * and so a page view costs no request to the library. Only image types by
	 * extension, at most ICON_MAX_BYTES; anything else is ignored.
	 */
	private function addIcons(array &$lib): void {
		$urls = [];
		foreach ($lib as $name => $m) {
			$path = trim((string)($m['annotations']['catalog/icon'] ?? ''), " \t/");
			$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
			if ($path !== '' && !str_contains($path, '..') && isset(self::ICON_TYPES[$ext])) {
				$urls[$name] = $this->rawManifestsURL . implode('/', array_map('rawurlencode', explode('/', $path)));
			}
		}
		foreach ($this->fetchAll($urls) as $name => $body) {
			$ext = strtolower(pathinfo((string)$lib[$name]['annotations']['catalog/icon'], PATHINFO_EXTENSION));
			if ($body !== '' && strlen($body) <= self::ICON_MAX_BYTES) {
				$lib[$name]['icon'] = 'data:' . self::ICON_TYPES[$ext] . ';base64,' . base64_encode($body);
			}
		}
	}

	/**
	 * The built-in icon for a category (img/categories/<key>.svg), by words in
	 * its name, so close variants of a name get the same icon; 'other' if none.
	 */
	public static function categoryIcon(string $category): string {
		$c = strtolower($category);
		$keys = [
			'physics' => ['physics', 'hep', 'cern', 'lhc'],
			'science' => ['scientific', 'science', 'math'],
			'notebook' => ['notebook', 'jupyter'],
			'learning' => ['learning', 'machine', 'gpu', 'neural'],
			'batch' => ['batch', 'job', 'pipeline'],
			'web' => ['web', 'http', 'site'],
			'database' => ['database', 'sql', 'db'],
			'storage' => ['storage', 'object', 's3', 'file server', 'file'],
			'media' => ['media', 'photo', 'video', 'music', 'book'],
			'tools' => ['develop', 'tool', 'utilit', 'code'],
			'linux' => ['linux', 'ubuntu', 'base', 'shell', 'terminal'],
		];
		foreach ($keys as $key => $words) {
			foreach ($words as $w) {
				if (str_contains($c, $w)) {
					return $key;
				}
			}
		}
		return 'other';
	}

	/**
	 * GET many URLs at once. Nextcloud's HTTP client runs requests one after
	 * another (its Guzzle handler is synchronous), which makes reading a library
	 * of a few dozen manifests take many seconds; curl_multi fetches them in
	 * parallel. The URLs come from the app's own config (the manifest library),
	 * and the instance's proxy setting is honoured.
	 *
	 * @param array<string, string> $urls key => URL
	 * @return array<string, string> key => body, for the requests that returned 200
	 */
	private function fetchAll(array $urls): array {
		$multi = curl_multi_init();
		$handles = [];
		$proxy = (string)\OCP\Server::get(\OCP\IConfig::class)->getSystemValue('proxy', '');
		foreach ($urls as $key => $u) {
			$h = curl_init($u);
			curl_setopt_array($h, [
				CURLOPT_RETURNTRANSFER => true,
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_TIMEOUT => 30,
				CURLOPT_USERAGENT => 'Nextcloud-user_pods',
				CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
			]);
			if ($proxy !== '') {
				curl_setopt($h, CURLOPT_PROXY, $proxy);
			}
			curl_multi_add_handle($multi, $h);
			$handles[$key] = $h;
		}
		do {
			$status = curl_multi_exec($multi, $running);
			if ($running) {
				curl_multi_select($multi, 1.0);
			}
		} while ($running && $status === CURLM_OK);
		$out = [];
		foreach ($handles as $key => $h) {
			if (curl_getinfo($h, CURLINFO_RESPONSE_CODE) === 200) {
				$out[$key] = (string)curl_multi_getcontent($h);
			}
			curl_multi_remove_handle($multi, $h);
		}
		curl_multi_close($multi);
		return $out;
	}

	// ── "Run this notebook": copy, pick an image, reuse or start a pod ────────────

	/**
	 * One action behind the "Run this notebook" button: a notebook from a public
	 * link (token, and a path inside it for folder shares) is copied into the
	 * user's own files under Notebooks/ (a real copy, so it can be saved), or an
	 * own file ($ownFile) is used as it is; an image is chosen from the
	 * notebook's kernel; a running server of that image is reused, else one is
	 * started with the file set. Returns
	 *   ['status' => 'ready',    'url' => <Jupyter URL opening the notebook>, 'file' => <path>]
	 *   ['status' => 'starting', 'pod' => <name>, 'file' => <path>]
	 * and throws PodHostException with a message meant for the user otherwise.
	 */
	public function runNotebook(string $uid, string $token = '', string $path = '', string $name = '', string $ownFile = ''): array {
		if ($this->rootFolder === null) {
			throw new PodHostException('Files unavailable');
		}
		$userFolder = $this->rootFolder->getUserFolder($uid);
		if ($ownFile !== '') {
			$file = ltrim($ownFile, '/');
			try {
				$node = $userFolder->get($file);
			} catch (\Throwable) {
				throw new PodHostException('Notebook not found: ' . $file);
			}
		} elseif ($token !== '') {
			[$file, $node] = $this->copyFromPublicLink($userFolder, $token, $path, $name);
		} else {
			throw new PodHostException('No notebook given');
		}
		if (!str_ends_with(strtolower($file), '.ipynb')) {
			throw new PodHostException('Not a notebook: ' . $file);
		}
		$kernel = '';
		try {
			$meta = json_decode((string)$node->getContent(), true);
			$kernel = (string)($meta['metadata']['kernelspec']['name'] ?? '');
		} catch (\Throwable) {
		}
		$entry = $this->imageForKernel($uid, $kernel);
		if ($entry === null) {
			throw new PodHostException('No notebook image is available for the kernel "' . $kernel . '"');
		}
		if (($url = $this->runningServerUrl($uid, $entry, $file)) !== '') {
			return ['status' => 'ready', 'url' => $url, 'file' => $file, 'image' => $entry['file']];
		}
		$res = $this->createPod($uid, $this->rawManifestsURL . $entry['file'], '', '', '', '', $file, '', '', '', 'medium');
		$pod = (string)($res['name'] ?? $res['pod_name'] ?? ($res['data']['name'] ?? ''));
		return ['status' => 'starting', 'pod' => $pod, 'file' => $file, 'image' => $entry['file']];
	}

	/**
	 * Copy a notebook out of a public link into Notebooks/ of the user's files,
	 * reading it over the link's public WebDAV on the node that holds it (the
	 * master in a cluster), so this works whatever node the user is homed on.
	 * An existing copy with the same name is reused, not overwritten.
	 * @return array{0: string, 1: \OCP\Files\File}
	 */
	private function copyFromPublicLink(\OCP\Files\Folder $userFolder, string $token, string $path, string $name): array {
		if (!preg_match('/^[A-Za-z0-9_.-]+$/', $token)) {
			throw new PodHostException('Invalid link');
		}
		$path = trim(str_replace('\\', '/', $path), '/');
		if (str_contains($path, '..')) {
			throw new PodHostException('Invalid path');
		}
		$base = basename($path !== '' ? $path : $name);
		$base = preg_replace('/[\\\\\\/:*?"<>|]+/', '_', $base) ?: '';
		if ($base === '' || !str_ends_with(strtolower($base), '.ipynb')) {
			throw new PodHostException('The link does not point at a notebook');
		}
		$body = '';
		// Same node: read the shared node directly.
		try {
			$share = \OCP\Server::get(\OCP\Share\IManager::class)->getShareByToken($token);
			$node = $share->getNode();
			if ($path !== '' && $node instanceof \OCP\Files\Folder) {
				$node = $node->get($path);
			}
			if ($node instanceof \OCP\Files\File) {
				$body = (string)$node->getContent();
			}
		} catch (\Throwable) {
		}
		// Another node of the cluster holds the share (the public link lives on the
		// owner's node): read it over the link's public WebDAV there. The master
		// cannot answer public WebDAV for a silo's share, so each node is asked.
		if ($body === '') {
			$hosts = [];
			if (class_exists(\OCA\FilesSharding\Service\ShardingService::class)) {
				try {
					foreach (\OCP\Server::get(\OCA\FilesSharding\Service\ShardingService::class)->getAllServers() as $server) {
						$hosts[] = rtrim((string)$server->getUrl(), '/');
					}
				} catch (\Throwable) {
				}
			}
			if ($hosts === []) {
				$hosts[] = rtrim((string)\OCP\Server::get(\OCP\IURLGenerator::class)->getAbsoluteURL('/'), '/');
			}
			$rel = $path !== '' ? implode('/', array_map('rawurlencode', explode('/', $path))) : '';
			$client = $this->clientService->newClient();
			$last = '';
			foreach ($hosts as $host) {
				try {
					$resp = $client->get($host . '/public.php/webdav/' . $rel, ['auth' => [$token, ''], 'timeout' => 120, 'verify' => false]);
					if ($resp->getStatusCode() === 200) {
						$body = (string)$resp->getBody();
						break;
					}
				} catch (\Throwable $e) {
					$last = $e->getMessage();
				}
			}
			if ($body === '') {
				throw new PodHostException('Could not read the notebook from the link' . ($last !== '' ? ': ' . $last : ''));
			}
		}
		if ($body === '' || json_decode($body, true) === null) {
			throw new PodHostException('The link did not return a notebook');
		}
		try {
			$dir = $userFolder->nodeExists('Notebooks') ? $userFolder->get('Notebooks') : $userFolder->newFolder('Notebooks');
			$rel = 'Notebooks/' . $base;
			if ($dir->nodeExists($base)) {
				$node = $dir->get($base);
			} else {
				$node = $dir->newFile($base, $body);
			}
		} catch (\Throwable $e) {
			throw new PodHostException('Could not save the notebook in your files: ' . $e->getMessage());
		}
		return [$rel, $node];
	}

	/**
	 * The image for a notebook kernel: an image whose catalog/notebook-kernels
	 * annotation matches (comma-separated patterns, * wildcard; a specific
	 * pattern beats a bare *), else by name among the jupyter* images (matlab and
	 * wolfram kernels to their images, anything else to the plain one). Images
	 * the user may launch come first. Mirrors imageForKernel() in js/main.js.
	 */
	private function imageForKernel(string $uid, string $kernel): ?array {
		$k = strtolower($kernel);
		$catalog = $this->getCatalog($uid);
		usort($catalog, static fn ($a, $b) => (int)(($a['allowed'] ?? true) === false) <=> (int)(($b['allowed'] ?? true) === false));
		$glob = static function (string $pat) use ($k): bool {
			$re = '/^' . str_replace('\\*', '.*', preg_quote(strtolower(trim($pat)), '/')) . '$/';
			return (bool)preg_match($re, $k);
		};
		foreach ($catalog as $e) {
			foreach (array_filter(array_map('trim', explode(',', (string)$e['kernels']))) as $pat) {
				if ($pat !== '*' && $glob($pat)) {
					return $e;
				}
			}
		}
		foreach ($catalog as $e) {
			foreach (array_map('trim', explode(',', (string)$e['kernels'])) as $pat) {
				if ($pat === '*') {
					return $e;
				}
			}
		}
		$jupyter = array_values(array_filter($catalog, static fn ($e) => preg_match('/^jupyter/i', (string)$e['file'])));
		usort($jupyter, static fn ($a, $b) => strlen($a['file']) <=> strlen($b['file']));
		$family = preg_match('/wolfram|mathematica/', $k) ? '/mathematica|wolfram/i' : (preg_match('/matlab/', $k) ? '/matlab/i' : null);
		foreach ($jupyter as $e) {
			if ($family !== null ? preg_match($family, $e['file']) : !preg_match('/mathematica|wolfram|matlab/i', $e['file'])) {
				return $e;
			}
		}
		return null;
	}

	/** A running server of this image for this user: its Jupyter URL opening $file, else ''. */
	private function runningServerUrl(string $uid, array $entry, string $file): string {
		$base = (string)$entry['name'] . '-' . preg_replace('/[@._]/', '-', $uid);
		if ($entry['name'] === '') {
			return '';
		}
		foreach ($this->getContainers($uid) as $c) {
			$n = (string)($c['pod_name'] ?? '');
			if ($n !== $base && !preg_match('/^' . preg_quote($base, '/') . '-\\d+$/', $n)) {
				continue;
			}
			if (!str_starts_with((string)($c['status'] ?? ''), 'Running') || empty($c['url'])) {
				continue;
			}
			$u = parse_url((string)$c['url']);
			parse_str((string)($u['query'] ?? ''), $q);
			if (empty($q['token'])) {
				continue;
			}
			$origin = ($u['scheme'] ?? 'https') . '://' . ($u['host'] ?? '') . (isset($u['port']) ? ':' . $u['port'] : '');
			$lab = str_contains((string)($u['path'] ?? ''), '/lab') || str_contains((string)$entry['file'], 'jupyterlab');
			$enc = implode('/', array_map('rawurlencode', explode('/', $file)));
			return $origin . ($lab ? '/lab/tree/' : '/notebooks/') . $enc . '?token=' . rawurlencode((string)$q['token']);
		}
		return '';
	}

	/**
	 * The image catalog: one entry per listed manifest, from its catalog/*
	 * annotations, with fallbacks (title from the file name, summary from the
	 * first paragraph of its description, category "Other") for manifests
	 * without them. With $uid, each entry says whether that user may launch it.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getCatalog(?string $uid): array {
		$out = [];
		foreach ($this->library() as $file => $m) {
			$a = $m['annotations'];
			$l = $m['labels'];
			$featured = trim((string)($a['catalog/featured'] ?? ''));
			$entry = [
				'file' => $file,
				'name' => (string)($m['name'] ?? ''),
				'title' => trim((string)($a['catalog/title'] ?? '')) ?: self::titleFromFile($file),
				'category' => trim((string)($a['catalog/category'] ?? '')) ?: 'Other',
				'summary' => trim((string)($a['catalog/summary'] ?? '')) ?: self::summaryFromMd($m['md']),
				// featured: 1, 2, … (0 or nothing = not featured)
				'featured' => is_numeric($featured) && (int)$featured > 0 ? (int)$featured : null,
				'kernels' => trim((string)($a['catalog/notebook-kernels'] ?? '')),
				// the container supplies its address's path (e.g. a Jupyter token) after it starts
				'wait_token' => in_array(strtolower(trim((string)($a['catalog/wait-for-token'] ?? ''))), ['1', 'true', 'yes'], true),
				'restricted' => ($l['group'] ?? '') !== '' || ($l['domain'] ?? '') !== '' || ($l['user'] ?? '') !== '',
				'icon' => (string)($m['icon'] ?? ''),
			];
			$entry['icon_key'] = self::categoryIcon($entry['category']);
			if ($uid !== null) {
				$entry['allowed'] = $this->mayLaunch($uid, $l);
			}
			$out[] = $entry;
		}
		usort($out, static fn ($x, $y) => [$x['category'] === 'Other', $x['category'], $x['title']]
			<=> [$y['category'] === 'Other', $y['category'], $y['title']]);
		return $out;
	}

	private static function titleFromFile(string $file): string {
		return ucfirst(str_replace(['_', '-'], ' ', preg_replace('/\.yaml$/', '', $file)));
	}

	/** First paragraph of a manifest's .md, as plain text, without the stock opening. */
	private static function summaryFromMd(string $md): string {
		foreach (preg_split('/\n\s*\n/', trim($md)) as $para) {
			$text = trim(preg_replace('/\s+/', ' ', preg_replace(['/\[([^\]]*)\]\([^)]*\)/', '/[*_`#>]/'], ['$1', ''], $para)));
			if ($text === '') {
				continue;
			}
			$text = preg_replace('/^Applying this manifest will (start|run|create) /i', '', $text);
			$text = ucfirst(rtrim($text, '.'));
			return mb_strlen($text) > 160 ? rtrim(mb_substr($text, 0, 157)) . '…' : $text;
		}
		return '';
	}

	/** Create a pod via run_pod.php. Returns the host's JSON response decoded. */
	public function createPod(string $uid, string $yamlUrl, string $publicKey, string $mountRoot,
		string $mountPath, string $cvmfsRepos = '', string $file = '', string $setupScript = '',
		string $peers = '', string $allowedIp = '', string $podType = ''): array {
		if ($this->limitApplies($uid)) {
			$running = count($this->getContainers($uid));
			if ($running >= $this->maxPodsPerUser) {
				throw new PodHostException(sprintf(
					'You already have %d pod%s running; the limit is %d. Delete one before starting another.',
					$running, $running === 1 ? '' : 's', $this->maxPodsPerUser));
			}
		}
		// An image that writes into its mounted folder (NFS_RW: "yes" in the
		// manifest) only works on a /storage/ mount: /files/ mounts are read-only.
		// The dialog hides /files/ for such images; the API refuses it, so a
		// direct call cannot start a pod that is bound to fail.
		if ($mountRoot === 'files') {
			$yamlFile = basename((string)(parse_url($yamlUrl, PHP_URL_PATH) ?: $yamlUrl));
			$info = $this->checkManifest($uid, $yamlFile);
			if (($info['nfs_rw'] ?? '') === 'yes') {
				throw new PodHostException('This image writes to its mounted folder; mount a /storage/ folder, not /files/ (read-only).');
			}
		}
		if ($mountRoot === 'storage' && $mountPath !== '') {
			$this->createStorageDir($uid, $mountPath);
		}
		$params = ['user_id' => $uid, 'yaml_url' => $yamlUrl];
		if ($publicKey !== '') {
			$params['public_key'] = $publicKey;
		}
		if ($mountRoot !== '') {
			$params['mount_root'] = $mountRoot;
		}
		if ($mountPath !== '') {
			$params['mount_path'] = $mountPath;
		}
		if ($cvmfsRepos !== '') {
			$params['cvmfs_repos'] = $cvmfsRepos;
		}
		if ($file !== '') {
			$params['file'] = $file;
		}
		if ($peers !== '') {
			$params['peers'] = $peers;
		}
		if ($allowedIp !== '') {
			$params['allowed_ip'] = $allowedIp;
		}
		if ($podType !== '') {
			$params['pod_type'] = $podType;
		}
		$params['setup_script'] = $setupScript === '' ? '/dev/null' : $setupScript;
		$json = $this->httpGet($this->podEndpoint('run_pod.php', $params), false);
		return json_decode($json, true) ?: [];
	}

	public function setAllowedIps(string $uid, string $podName, string $ips): array {
		$json = $this->httpGet($this->podEndpoint('set_allowed_ips.php',
			['user_id' => $uid, 'pod' => $podName, 'ips' => $ips]), false);
		return json_decode($json, true) ?: [];
	}

	public function setPortNumbers(string $uid, string $podName, string $httpsPort, string $sshPort, string $extraPorts): array {
		$json = $this->httpGet($this->podEndpoint('set_port_numbers.php',
			['user_id' => $uid, 'pod' => $podName, 'https_port' => $httpsPort, 'ssh_port' => $sshPort, 'extra_ports' => $extraPorts]), false);
		return json_decode($json, true) ?: [];
	}

	public function deletePod(string $uid, string $podName): array {
		$json = $this->httpGet($this->podEndpoint('delete_pod.php',
			['user_id' => $uid, 'pod' => $podName]), false);
		return json_decode($json, true) ?: [];
	}

	/** Raw log text for a pod (the controller turns this into a download). */
	public function getLogs(string $uid, string $podName): string {
		return $this->httpGet($this->podEndpoint('get_pod_logs.php',
			['user_id' => $uid, 'pod' => $podName]), false);
	}
}
