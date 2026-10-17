<?php

declare(strict_types=1);

namespace OCA\UserPods\Controller;

use OCA\UserPods\Exception\PodHostException;
use OCA\UserPods\Service\PodService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
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
	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function run(string $token = '', string $path = '', string $name = '', string $file = ''): TemplateResponse|RedirectResponse {
		$uid = $this->userSession->getUser()?->getUID() ?? '';
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
