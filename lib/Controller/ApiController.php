<?php

declare(strict_types=1);

namespace OCA\UserPods\Controller;

use OCA\UserPods\Service\PodService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUserSession;

class ApiController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private PodService $pods,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function containers(string $pods = ''): JSONResponse {
		$names = $pods === '' ? null : explode(',', $pods);
		return new JSONResponse($this->pods->getContainers($this->uid(), $names));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function manifests(): JSONResponse {
		return new JSONResponse($this->pods->getManifests());
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function manifest(string $yaml = ''): JSONResponse {
		return new JSONResponse($this->pods->checkManifest($this->uid(), $yaml));
	}

	#[NoAdminRequired]
	public function create(string $yaml_url, string $public_key = '', string $mount_root = '',
		string $mount_path = '', string $cvmfs_repos = '', string $file = '', string $setup_script = '',
		string $peers = '', string $allowed_ip = '', string $pod_type = ''): JSONResponse {
		return new JSONResponse($this->pods->createPod($this->uid(), $yaml_url, $public_key, $mount_root,
			$mount_path, $cvmfs_repos, $file, $setup_script, $peers, $allowed_ip, $pod_type));
	}

	#[NoAdminRequired]
	public function delete(string $pod): JSONResponse {
		return new JSONResponse($this->pods->deletePod($this->uid(), $pod));
	}

	#[NoAdminRequired]
	public function allowedIps(string $pod, string $ips = ''): JSONResponse {
		return new JSONResponse($this->pods->setAllowedIps($this->uid(), $pod, $ips));
	}

	#[NoAdminRequired]
	public function ports(string $pod, string $https_port = '', string $ssh_port = '', string $extra_ports = ''): JSONResponse {
		return new JSONResponse($this->pods->setPortNumbers($this->uid(), $pod, $https_port, $ssh_port, $extra_ports));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function logs(string $pod): DataDownloadResponse {
		return new DataDownloadResponse($this->pods->getLogs($this->uid(), $pod), $pod . '.log', 'text/plain');
	}
}
