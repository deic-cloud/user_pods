<?php

declare(strict_types=1);

namespace OCA\UserPods\Controller;

use OCA\UserPods\Exception\PodHostException;
use OCA\UserPods\Service\PodService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Template\PublicTemplateResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IL10N;
use OCP\IRequest;
use OCP\IURLGenerator;

class PageController extends Controller {
	public function __construct(
		string $appName,
		IRequest $request,
		private PodService $pods,
		private IURLGenerator $urlGenerator,
		private IL10N $l,
	) {
		parent::__construct($appName, $request);
	}

	#[NoAdminRequired]
	#[NoCSRFRequired]
	public function index(): TemplateResponse {
		return new TemplateResponse('user_pods', 'index');
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
		return $response;
	}
}
