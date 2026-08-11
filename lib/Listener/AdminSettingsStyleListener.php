<?php

declare(strict_types=1);

namespace OCA\UserPods\Listener;

use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IRequest;
use OCP\Util;

/**
 * Loads css/admin-settings.css, but only on this app's own admin settings
 * section page (Administration → Containers, i.e. section=user-pods). Scoping
 * it to that one page means the stylesheet's .declarative-settings-section
 * selector can only ever match our form, never another app's section.
 *
 * @implements IEventListener<BeforeTemplateRenderedEvent>
 */
class AdminSettingsStyleListener implements IEventListener {
	public function __construct(
		private IRequest $request,
	) {}

	public function handle(Event $event): void {
		if (!($event instanceof BeforeTemplateRenderedEvent) || !$event->isLoggedIn()) {
			return;
		}
		// The settings admin route is /settings/admin/{section}; the section
		// placeholder surfaces as a request param.
		if ($this->request->getParam('section') !== 'user-pods') {
			return;
		}
		Util::addStyle('user_pods', 'admin-settings');
	}
}
