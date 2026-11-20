<?php

declare(strict_types=1);

namespace OCA\UserPods\Controller;

use OCA\UserPods\Exception\PodHostException;
use OCA\UserPods\Service\PodMail;
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
		private PodMail $podMail,
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
	public function catalog(): JSONResponse {
		return $this->host(fn () => $this->pods->getCatalog($this->uid()));
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function manifest(string $yaml = ''): JSONResponse {
		return $this->host(fn () => $this->pods->checkManifest($this->uid(), $yaml));
	}

	/**
	 * "Run this notebook": copy a notebook from a public link (token [+ path],
	 * name for single-file links) or take an own file (file), choose the image
	 * from its kernel, reuse a running server or start one. See
	 * PodService::runNotebook() for the result shape.
	 */
	#[NoAdminRequired]
	public function runNotebook(string $token = '', string $path = '', string $name = '', string $file = ''): JSONResponse {
		return $this->host(fn () => $this->pods->runNotebook($this->uid(), $token, $path, $name, $file));
	}

	#[NoAdminRequired]
	public function create(string $yaml_url, string $public_key = '', string $mount_root = '',
		string $mount_path = '', string $cvmfs_repos = '', string $file = '', string $setup_script = '',
		string $peers = '', string $allowed_ip = '', string $pod_type = '', string $login_group = '',
		string $login_groups = ''): JSONResponse {
		// login_groups: JSON list (group names may hold any character); login_group: one (older pages)
		$groups = json_decode($login_groups, true);
		$groups = is_array($groups) ? $groups : ($login_group === '' ? [] : [$login_group]);
		return $this->host(fn () => $this->pods->createPod($this->uid(), $yaml_url, $public_key, $mount_root,
			$mount_path, $cvmfs_repos, $file, $setup_script, $peers, $allowed_ip, $pod_type, false, $groups));
	}

	/**
	 * Called by a pod at start, from inside (no credentials: the pod network's
	 * address login makes the caller the pod's owner): its login client for
	 * ScienceData accounts, or {oidc: false} when it keeps its own login.
	 * callback: the path the application in the pod takes logins back at
	 * (default Overleaf's), fixed when the client is made.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function podLogin(string $callback = ''): JSONResponse {
		try {
			$client = $this->pods->podLoginClient($this->uid(), $this->request->getRemoteAddress(), $callback);
		} catch (\Throwable $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_SERVICE_UNAVAILABLE);
		}
		return new JSONResponse($client === null ? ['oidc' => false] : ['oidc' => true] + $client);
	}

	/**
	 * Called by a pod (address login, as podLogin): the accepted members of the
	 * groups that log in to it - {groups: {gid: [{uid, name}, ...]}} - e.g. for
	 * Moodle's cohorts. {groups: {}} for a pod just for its owner.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function podMembers(): JSONResponse {
		try {
			$members = $this->pods->podMembers($this->uid(), $this->request->getRemoteAddress());
		} catch (PodHostException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		}
		return new JSONResponse(['groups' => (object)($members ?? [])]);
	}

	/**
	 * Called by a pod (address login, as podLogin): send a mail message for an
	 * application in it - JSON {to: [addresses], message: base64 of the whole
	 * message}. See PodMail for what is checked and changed.
	 */
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function podMail(array $to = [], string $message = ''): JSONResponse {
		try {
			$container = $this->pods->containerAt($this->uid(), $this->request->getRemoteAddress());
			$pod = (string)($container['pod_name'] ?? '');
			$raw = base64_decode($message, true);
			if ($raw === false) {
				throw new \InvalidArgumentException('The message must be base64');
			}
			$this->podMail->relay($this->uid(), $pod, $to, $raw, $this->pods->podAudience($this->uid(), $container));
		} catch (PodHostException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_FORBIDDEN);
		} catch (\InvalidArgumentException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		} catch (\OverflowException $e) {
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_TOO_MANY_REQUESTS);
		} catch (\Throwable $e) {
			$this->logger->error('user_pods: pod mail not sent: ' . $e->getMessage(), ['app' => 'user_pods', 'exception' => $e]);
			return new JSONResponse(['message' => 'The mail could not be sent.'], Http::STATUS_BAD_GATEWAY);
		}
		return new JSONResponse(['sent' => true]);
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
			// A container that has not started (its image still downloading,
			// or being scheduled) has no log yet - the host answers with an
			// error. Say what is going on instead.
			$state = '';
			try {
				$c = $this->pods->getContainers($this->uid(), [$pod])[0] ?? null;
				$state = $c === null ? '' : strtok((string)($c['status'] ?? ''), ':');
			} catch (\Throwable) {
			}
			if ($state === 'Pending') {
				return new DataDownloadResponse("The container has not started yet - its image may still be downloading, which takes a while for large images. Its log appears here once it runs.\n", $pod . '.log', 'text/plain');
			}
			if ($state === 'Terminating') {
				return new DataDownloadResponse("The container is stopping; its log is no longer available.\n", $pod . '.log', 'text/plain');
			}
			$this->logger->error('user_pods: logs host call failed: ' . $e->getMessage(),
				['app' => 'user_pods', 'exception' => $e]);
			return new JSONResponse(
				['status' => 'error', 'data' => ['message' => $e->getMessage()]],
				Http::STATUS_BAD_GATEWAY,
			);
		}
	}
}
