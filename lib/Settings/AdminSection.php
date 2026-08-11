<?php

declare(strict_types=1);

namespace OCA\UserPods\Settings;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Dedicated admin settings section for the Containers (user_pods) app, so its
 * host-service/image-library form gets its own entry in Administration rather
 * than being buried under "Additional settings". The app's own app.svg is a
 * white-fill nav glyph (invisible on the light settings nav), so — like our
 * other apps — the section borrows a themed core icon.
 */
class AdminSection implements IIconSection {
	public function __construct(
		private IL10N         $l,
		private IURLGenerator $urlGenerator,
	) {}

	public function getID(): string {
		return 'user-pods';
	}

	public function getName(): string {
		return $this->l->t('Containers');
	}

	public function getPriority(): int {
		return 70;
	}

	public function getIcon(): string {
		return $this->urlGenerator->imagePath('core', 'actions/screen.svg');
	}
}
