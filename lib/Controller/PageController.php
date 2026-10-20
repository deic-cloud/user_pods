<?php

declare(strict_types=1);

namespace OCA\UserPods\Controller;

use OCA\UserPods\Exception\PodHostException;
use OCA\UserPods\Service\PodService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\RedirectResponse;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;

class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private PodService $pods,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
		private IUserSession $userSession,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		return new TemplateResponse('user_pods', 'index');
	}

	/**
	 * The page behind "Run this notebook" (catalogue landing pages, notebook
	 * viewer): does the whole job server-side and either sends the browser
	 * straight to the open notebook, or shows a waiting page that polls until
	 * the new server is up and then goes there. A link to this page from a
	 * public page works for anonymous visitors too: the login redirect brings
	 * them back here afterwards.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function run(string $token = '', string $path = '', string $name = '', string $file = ''): TemplateResponse|RedirectResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			// Not logged in. With guest pods enabled, a public link runs in a
			// throwaway pod; otherwise log in first and come back here.
			if (!$this->pods->guestPodsEnabled() || $token === '') {
				return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm',
					['redirect_url' => $this->request->getRequestUri()]));
			}
			try {
				$res = $this->pods->runNotebookAsGuest($token, $path, $name, $this->request->getRemoteAddress());
			} catch (PodHostException $e) {
				return new TemplateResponse('user_pods', 'run', $this->guestVars(['error' => $e->getMessage()]), TemplateResponse::RENDER_AS_GUEST);
			}
			if (($res['status'] ?? '') === 'ready' && !empty($res['url'])) {
				return new RedirectResponse((string)$res['url']);
			}
			return new TemplateResponse('user_pods', 'run', $this->guestVars([
				'error' => '',
				'pod' => (string)($res['pod'] ?? ''),
				'file' => (string)($res['file'] ?? ''),
				'image' => (string)($res['image'] ?? ''),
				'status_url' => $this->urlGenerator->linkToRouteAbsolute('user_pods.page.guestStatus', ['gt' => (string)($res['guest'] ?? '')]),
				'expires' => (int)($res['expires'] ?? 0),
			]), TemplateResponse::RENDER_AS_GUEST);
		}
		try {
			$res = $this->pods->runNotebook($uid, $token, $path, $name, $file);
		} catch (PodHostException $e) {
			return new TemplateResponse('user_pods', 'run', ['error' => $e->getMessage(), 'pod' => '', 'file' => '']);
		}
		if (($res['status'] ?? '') === 'ready' && !empty($res['url'])) {
			return new RedirectResponse((string)$res['url']);
		}
		return new TemplateResponse('user_pods', 'run', [
			'error' => '',
			'pod' => (string)($res['pod'] ?? ''),
			'file' => (string)($res['file'] ?? ''),
			'image' => (string)($res['image'] ?? ''),
			// the app's API is a plain route under index.php (not OCS)
			'containers_url' => $this->urlGenerator->linkToRouteAbsolute('user_pods.api.containers'),
			'pods_url' => $this->urlGenerator->linkToRoute('user_pods.page.index'),
		]);
	}

	/** Template variables common to the guest variant of the run page. */
	private function guestVars(array $vars): array {
		return $vars + [
			'guest' => true, 'pod' => '', 'file' => '', 'image' => '', 'status_url' => '', 'expires' => 0,
			'lifetime' => $this->pods->guestPodLifetime(),
			'login_url' => $this->urlGenerator->linkToRoute('core.login.showLoginForm', ['redirect_url' => $this->request->getRequestUri()]),
			'pods_url' => '', 'containers_url' => '',
		];
	}

	/** Polled by the guest waiting page: the pod's state and, once up, its address. */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 60, period: 60)]
	public function guestStatus(string $gt = ''): JSONResponse {
		return new JSONResponse($this->pods->guestStatus($gt));
	}

	/**
	 * The image catalog for everyone, logged in or not: what can be run, by
	 * category, each with a Launch link into the app (which asks for a login).
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function catalog(): TemplateResponse {
		$error = '';
		$catalog = [];
		try {
			$catalog = $this->pods->getCatalog(null);
		} catch (PodHostException $e) {
			$error = $e->getMessage();
		}
		$response = new PublicTemplateResponse('user_pods', 'catalog', [
			'catalog' => $catalog,
			'error' => $error,
			'launchUrl' => $this->urlGenerator->linkToRouteAbsolute('user_pods.page.index'),
		]);
		$response->setHeaderTitle($this->l->t('Containers'));
		$response->setHeaderDetails($this->l->t('Images you can run next to your data'));
		// No instance footer (slogan, sign-up link) on this page.
		$response->setFooterVisible(false);
		return $response;
	}
}
