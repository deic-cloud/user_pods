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

	/** The groups $uid is an accepted member of, without hidden ones (institution groups). @return string[] */
	public function visibleGroupsOf(string $uid): array {
		if ($uid === '' || !$this->available() || !class_exists('\\OCA\\UserGroupAdmin\\Db\\GroupMapper')) {
			return [];
		}
		try {
			$gids = [];
			foreach (Server::get('\\OCA\\UserGroupAdmin\\Db\\GroupMapper')->findByMember($uid) as $g) {
				if (!$g->getHidden()) {
					$gids[] = (string)$g->getGid();
				}
			}
			sort($gids);
			return $gids;
		} catch (\Throwable $e) {
			return [];
		}
	}

	/** The accepted members of $gid: [['uid' => ..., 'name' => display name], ...], sorted by uid. */
	public function membersOf(string $gid): array {
		if ($gid === '' || !$this->available() || !class_exists('\\OCA\\UserGroupAdmin\\Db\\GroupMemberMapper')) {
			return [];
		}
		try {
			$users = Server::get(\OCP\IUserManager::class);
			$out = [];
			foreach (Server::get('\\OCA\\UserGroupAdmin\\Db\\GroupMemberMapper')->findByGid($gid) as $m) {
				$uid = (string)$m->getUid();
				// accepted members with an account (not pending invitations, not the
				// placeholder of e-mail invitations, uga_external)
				if ((int)$m->getStatus() !== 1 || $uid === '' || $uid === 'uga_external') {
					continue;
				}
				$u = $users->get($uid);
				$name = ($u !== null && strcasecmp($u->getUID(), $uid) === 0) ? $u->getDisplayName() : $uid;
				$out[] = ['uid' => $uid, 'name' => $name];
			}
			usort($out, static fn ($a, $b) => strcmp($a['uid'], $b['uid']));
			return $out;
		} catch (\Throwable $e) {
			return [];
		}
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
