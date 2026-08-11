<?php

declare(strict_types=1);

namespace OCA\UserPods\AppInfo;

use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCA\UserPods\Listener\AdminSettingsStyleListener;
use OCA\UserPods\Settings\AdminForm;

class Application extends App implements IBootstrap {
	public const APP_ID = 'user_pods';

	public function __construct() {
		parent::__construct(self::APP_ID);
	}

	public function register(IRegistrationContext $context): void {
		$context->registerDeclarativeSettings(AdminForm::class);
		$context->registerEventListener(BeforeTemplateRenderedEvent::class, AdminSettingsStyleListener::class);
	}

	public function boot(IBootContext $context): void {
	}
}
