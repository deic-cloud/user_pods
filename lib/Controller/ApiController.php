<?php

declare(strict_types=1);

namespace OCA\UserPods\Controller;

use OCA\UserPods\Exception\PodHostException;
use OCA\UserPods\Service\PodService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\DataDownloadResponse;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\IRequest;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class ApiController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private PodService $pods,
		private IUserSession $userSession,
		private LoggerInterface $logger,
	) {
		parent::__construct($appName, $request);
	}

	private function uid(): string {
		return $this->userSession->getUser()?->getUID() ?? '';
	}

	/**
	 * Run a PodService call and wrap the result in a JSONResponse, turning a
	 * transport-level host failure into a 502 error the frontend can display.
	 * The error body mirrors the host's own {status,data:{message}} shape so the
	 * JS success/error handling (status==='success' / hostMessage) needs no
	 * special case.
	 */
	private function host(callable $fn): JSONResponse {
		try {
			return new JSONResponse($fn());
		} catch (PodHostException $e) {
			$this->logger->error('user_pods: host call failed: ' . $e->getMessage(),
				['app' => 'user_pods', 'exception' => $e]);
			return new JSONResponse(
				['status' => 'error', 'data' => ['message' => $e->getMessage()]],
				Http::STATUS_BAD_GATEWAY,
			);
		}
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function containers(string $pods = ''): JSONResponse {
		$names = $pods === '' ? null : explode(',', $pods);
		return $this->host(fn () => $this->pods->getContainers($this->uid(), $names));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function manifests(): JSONResponse {
		return $this->host(fn () => $this->pods->getManifests());
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function manifest(string $yaml = ''): JSONResponse {
		return $this->host(fn () => $this->pods->checkManifest($this->uid(), $yaml));
	}

	#[NoAdminRequired]
	public function create(string $yaml_url, string $public_key = '', string $mount_root = '',
		string $mount_path = '', string $cvmfs_repos = '', string $file = '', string $setup_script = '',
		string $peers = '', string $allowed_ip = '', string $pod_type = ''): JSONResponse {
		return $this->host(fn () => $this->pods->createPod($this->uid(), $yaml_url, $public_key, $mount_root,
			$mount_path, $cvmfs_repos, $file, $setup_script, $peers, $allowed_ip, $pod_type));
	}

	#[NoAdminRequired]
	public function delete(string $pod): JSONResponse {
		return $this->host(fn () => $this->pods->deletePod($this->uid(), $pod));
	}

	#[NoAdminRequired]
	public function allowedIps(string $pod, string $ips = ''): JSONResponse {
		return $this->host(fn () => $this->pods->setAllowedIps($this->uid(), $pod, $ips));
	}

	#[NoAdminRequired]
	public function ports(string $pod, string $https_port = '', string $ssh_port = '', string $extra_ports = ''): JSONResponse {
		return $this->host(fn () => $this->pods->setPortNumbers($this->uid(), $pod, $https_port, $ssh_port, $extra_ports));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function logs(string $pod): Response {
		try {
			return new DataDownloadResponse($this->pods->getLogs($this->uid(), $pod), $pod . '.log', 'text/plain');
		} catch (PodHostException $e) {
			$this->logger->error('user_pods: logs host call failed: ' . $e->getMessage(),
				['app' => 'user_pods', 'exception' => $e]);
			return new JSONResponse(
				['status' => 'error', 'data' => ['message' => $e->getMessage()]],
				Http::STATUS_BAD_GATEWAY,
			);
		}
	}
}
