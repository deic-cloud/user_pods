<?php

declare(strict_types=1);

namespace OCA\UserPods\Service;

use OCP\App\IAppManager;
use OCP\Server;

/**
 * Thin, guarded bridge to user_group_admin. Resolved lazily so user_pods stays
 * installable without user_group_admin (group-restricted manifests simply deny
 * when it's absent). Mirrors markdown_notes' MetaDataBridge pattern.
 */
class GroupBridge {
	public function __construct(
		private IAppManager $appManager,
	) {
	}

	public function available(): bool {
		return $this->appManager->isInstalled('user_group_admin')
			&& class_exists('\\OCA\\UserGroupAdmin\\Group\\GroupBackend');
	}

	public function inGroup(string $uid, string $gid): bool {
		if ($uid === '' || $gid === '' || !$this->available()) {
			return false;
		}
		try {
			return (bool)Server::get('\\OCA\\UserGroupAdmin\\Group\\GroupBackend')->inGroup($uid, $gid);
		} catch (\Throwable $e) {
			return false;
		}
	}
}
