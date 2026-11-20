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
		private \OCA\UserPods\Service\PodAccess $podAccess,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		return new TemplateResponse('user_pods', 'index', [
			'mayUsePods' => $this->podAccess->mayUsePods($uid),
			'noAccessMessage' => \OCA\UserPods\Service\PodService::NO_ACCESS_MESSAGE,
		]);
	}

	/**
	 * The page behind "Run this notebook" (catalogue landing pages, notebook
	 * viewer). Answers at once with a waiting page - starting a server takes
	 * a while, and a link that seemed dead invited more clicks - which then
	 * asks for the work (logged in: POST api/run-notebook; visitors:
	 * runGuestStart) and goes to the open notebook, polling until a new server
	 * is up. A link to this page from a public page works for anonymous
	 * visitors too: the login redirect brings them back here afterwards.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function run(string $token = '', string $path = '', string $name = '', string $file = ''): TemplateResponse|RedirectResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
		if ($uid === '') {
			// Not logged in. With guest pods enabled, a public link runs in a
			// throwaway pod; otherwise log in first and come back here.
			if (!$this->pods->guestPodsEnabled() || $token === '') {
				return new RedirectResponse($this->urlGenerator->linkToRoute('core.login.showLoginForm',
					['redirect_url' => $this->request->getRequestUri()]));
			}
			return new TemplateResponse('user_pods', 'run', $this->guestVars([
				'error' => '',
				'start_url' => $this->urlGenerator->linkToRouteAbsolute('user_pods.page.runGuestStart'),
				'params' => ['token' => $token, 'path' => $path, 'name' => $name],
				'status_url' => $this->urlGenerator->linkToRouteAbsolute('user_pods.page.guestStatus'),
			]), TemplateResponse::RENDER_AS_GUEST);
		}
		return new TemplateResponse('user_pods', 'run', [
			'error' => '',
			'start_url' => $this->urlGenerator->linkToRouteAbsolute('user_pods.api.runNotebook'),
			'params' => ['token' => $token, 'path' => $path, 'name' => $name, 'file' => $file],
			// the app's API is a plain route under index.php (not OCS)
			'containers_url' => $this->urlGenerator->linkToRouteAbsolute('user_pods.api.containers'),
			'pods_url' => $this->urlGenerator->linkToRoute('user_pods.page.index'),
		]);
	}

	/**
	 * Asked by the guest waiting page (run): start - or find, one per visitor
	 * address - the throwaway pod for a public notebook link. See
	 * PodService::runNotebookAsGuest() for the result; {error} otherwise.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	#[AnonRateLimit(limit: 10, period: 60)]
	public function runGuestStart(string $token = '', string $path = '', string $name = ''): JSONResponse {
		if (!$this->pods->guestPodsEnabled() || $token === '') {
			return new JSONResponse(['error' => $this->l->t('Log in to run this notebook.')], 403);
		}
		try {
			return new JSONResponse($this->pods->runNotebookAsGuest($token, $path, $name, $this->request->getRemoteAddress()));
		} catch (PodHostException $e) {
			return new JSONResponse(['error' => $e->getMessage()], 502);
		}
	}

	/**
	 * An image's guide (<image>.guide.md beside its manifest), rendered here -
	 * public, like the catalog; linked from the image's description in the
	 * dialog and from the catalog.
	 */
	#[PublicPage]
	#[NoCSRFRequired]
	public function guide(string $image): TemplateResponse {
		$md = $this->pods->guideMarkdown($image);
		$title = \OCA\UserPods\Service\MarkdownLite::title($md);
		$response = new PublicTemplateResponse('user_pods', 'guide', [
			'html' => $md === '' ? '' : \OCA\UserPods\Service\MarkdownLite::toHtml($md,
				fn (string $other) => $this->urlGenerator->linkToRoute('user_pods.page.guide', ['image' => $other])),
			'catalogUrl' => $this->urlGenerator->linkToRoute('user_pods.page.catalog'),
		]);
		$response->setHeaderTitle($title !== '' ? $title : $this->l->t('Guide'));
		if ($md === '') {
			$response->setStatus(404);
		}
		return $response;
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
