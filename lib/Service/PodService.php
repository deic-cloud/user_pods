<?php

declare(strict_types=1);

namespace OCA\UserPods\Service;

use OCP\Http\Client\IClientService;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Thin client over the sciencedata_kubernetes host service. Each method maps to
 * one of the host's `*.php` endpoints (reached at http://<privateIP>/...), or to
 * the GitHub manifest library. The host service (the battle-tested run_pod bash
 * script et al.) is NOT touched — this only preserves the request contract.
 *
 * Port of the OC7 OC_Kubernetes_Util.
 */
class PodService {
	private string $publicIP;
	private string $privateIP;
	private string $storageDir;
	private string $manifestsURL;
	private string $rawManifestsURL;

	public function __construct(
		IAppConfig $appConfig,
		private IClientService $clientService,
		private GroupBridge $groups,
		private LoggerInterface $logger,
	) {
		$this->publicIP = $appConfig->getValueString('user_pods', 'publicIP', '');
		$this->privateIP = $appConfig->getValueString('user_pods', 'privateIP', '');
		$this->storageDir = $appConfig->getValueString('user_pods', 'storageDir', '');
		// Manifest library defaults point at the deic-dk pod_manifests repo.
		$this->manifestsURL = $appConfig->getValueString('user_pods', 'manifestsURL',
			'https://api.github.com/repos/deic-dk/pod_manifests/contents');
		$this->rawManifestsURL = $appConfig->getValueString('user_pods', 'rawManifestsURL',
			'https://raw.githubusercontent.com/deic-dk/pod_manifests/main/');
	}

	public function getRawManifestsURL(): string {
		return $this->rawManifestsURL;
	}

	/** GET a URL, returning the body as a string (empty string on failure). */
	private function httpGet(string $url, bool $verify = true): string {
		try {
			$response = $this->clientService->newClient()->get($url, [
				'verify' => $verify,
				'timeout' => 60,
				'headers' => ['User-Agent' => 'ScienceData-user_pods'],
			]);
			return (string)$response->getBody();
		} catch (\Throwable $e) {
			$this->logger->warning('user_pods GET ' . $url . ': ' . $e->getMessage(), ['app' => 'user_pods']);
			return '';
		}
	}

	private function podEndpoint(string $script, array $params): string {
		return 'http://' . $this->privateIP . '/' . $script . '?' . http_build_query($params);
	}

	public function createStorageDir(string $uid): void {
		if ($this->storageDir === '') {
			return;
		}
		$path = rtrim($this->storageDir, '/') . '/' . $uid;
		if (!is_dir($path)) {
			@mkdir($path, 0755, true);
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
		$url = $this->podEndpoint('get_containers.php', ['fields' => 'include', 'user_id' => $uid]);
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
		$yamlUrl = $this->rawManifestsURL . $yamlFile;
		$arr = yaml_parse($this->httpGet($yamlUrl));
		if (!is_array($arr)) {
			return [];
		}
		$labels = $arr['metadata']['labels'] ?? [];
		$yamlGroup = (string)($labels['group'] ?? '');
		$yamlDomain = (string)($labels['domain'] ?? '');
		$yamlUser = (string)($labels['user'] ?? '');
		$podTypes = empty($labels['types']) ? [] : explode('-', (string)$labels['types']);

		$shortUser = $uid;
		$domain = '';
		if (str_contains($uid, '@')) {
			[$shortUser, $domain] = explode('@', $uid, 2);
		}

		$allowed = false;
		if ($yamlDomain === '' && $yamlUser === '' && $yamlGroup === '') {
			$allowed = true; // no restriction
		} else {
			if ($yamlDomain === '' && $yamlUser !== '' && $yamlUser === $shortUser) {
				$allowed = true; // system user
			}
			if ($yamlUser === '' && $yamlDomain !== '' && $yamlDomain === $domain) {
				$allowed = true; // whole domain
			}
			// Specific user within a domain. (OC7 returned [] here — a bug; it had
			// just confirmed a match. Treat as allowed.)
			if ($yamlUser !== '' && $yamlDomain !== '' && $yamlUser === $shortUser && $yamlDomain === $domain) {
				$allowed = true;
			}
			if ($yamlGroup !== '' && $this->groups->inGroup($uid, $yamlGroup)) {
				$allowed = true; // group member
			}
		}
		if (!$allowed) {
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

	/** Create a pod via run_pod.php. Returns the host's JSON response decoded. */
	public function createPod(string $uid, string $yamlUrl, string $publicKey, string $mountRoot,
		string $mountPath, string $cvmfsRepos = '', string $file = '', string $setupScript = '',
		string $peers = '', string $allowedIp = '', string $podType = ''): array {
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
