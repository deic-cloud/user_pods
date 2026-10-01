<?php

declare(strict_types=1);

namespace OCA\UserPods\Service;

use OCA\UserPods\Exception\PodHostException;
use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use OCP\ICacheFactory;
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

	public function __construct(
		IAppConfig $appConfig,
		private IClientService $clientService,
		private GroupBridge $groups,
		private LoggerInterface $logger,
		private ICacheFactory $cacheFactory,
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
	public function getManifests(): array {
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
			'notebook' => ['notebook', 'jupyter', 'science', 'math'],
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

	/**
	 * The image catalog: one entry per listed manifest, from its catalog/*
	 * annotations, with fallbacks (title from the file name, summary from the
	 * first paragraph of its description, category "Other") for manifests
	 * without them. catalog/hidden manifests are left out (still launchable by
	 * URL). With $uid, each entry says whether that user may launch it.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function getCatalog(?string $uid): array {
		$out = [];
		foreach ($this->library() as $file => $m) {
			$a = $m['annotations'];
			if (in_array(strtolower(trim((string)($a['catalog/hidden'] ?? ''))), ['true', '1', 'yes'], true)) {
				continue;
			}
			$l = $m['labels'];
			$featured = trim((string)($a['catalog/featured'] ?? ''));
			$entry = [
				'file' => $file,
				'title' => trim((string)($a['catalog/title'] ?? '')) ?: self::titleFromFile($file),
				'category' => trim((string)($a['catalog/category'] ?? '')) ?: 'Other',
				'summary' => trim((string)($a['catalog/summary'] ?? '')) ?: self::summaryFromMd($m['md']),
				'featured' => is_numeric($featured) ? (int)$featured : null,
				'kernels' => trim((string)($a['catalog/notebook-kernels'] ?? '')),
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
