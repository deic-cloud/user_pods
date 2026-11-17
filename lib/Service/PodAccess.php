<?php

declare(strict_types=1);

namespace OCA\UserPods\Service;

use OCP\App\IAppManager;
use OCP\IAppConfig;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Who may start containers. A container is a root shell next to the user's
 * data, so it is for accounts whose identity is vouched for:
 *
 *  - accounts that have logged in through WAYF (an institution's login),
 *    as recorded on the master by files_sharding's AccountPolicy;
 *  - external collaborators, invited by e-mail into a group by its owner,
 *    who vouches for them (user_group_admin);
 *  - accounts on the admin's allow list (service accounts such as 'cloud'
 *    and 'batch', exceptions);
 *
 * and never accounts on the deny list. Everything else - local and test
 * accounts with plain passwords - may not. With 'podRestrictToVouched' off,
 * or without files_sharding, everyone may (as before).
 */
class PodAccess {
	public function __construct(
		private IAppConfig $appConfig,
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	public function restricted(): bool {
		return in_array(strtolower($this->appConfig->getValueString('user_pods', 'podRestrictToVouched', '1')), ['1', 'true', 'yes', 'on'], true)
			&& $this->appManager->isEnabledForAnyone('files_sharding')
			&& class_exists('\\OCA\\FilesSharding\\Service\\AccountPolicy');
	}

	public function mayUsePods(string $uid): bool {
		if ($uid === '') {
			return false;
		}
		if (in_array($uid, $this->list('podDenyUsers'), true)) {
			return false;
		}
		if (!$this->restricted() || in_array($uid, $this->list('podAllowUsers'), true)) {
			return true;
		}
		return $this->isExternalCollaborator($uid) || $this->hasWayfLogin($uid);
	}

	/** @return string[] */
	private function list(string $key): array {
		return array_values(array_filter(array_map('trim',
			preg_split('/[\s,;]+/', $this->appConfig->getValueString('user_pods', $key, '')) ?: [])));
	}

	private function hasWayfLogin(string $uid): bool {
		try {
			return (bool)Server::get('\\OCA\\FilesSharding\\Service\\AccountPolicy')->hasWayfLogin($uid);
		} catch (\Throwable $e) {
			$this->logger->warning('user_pods: WAYF lookup failed for ' . $uid . ': ' . $e->getMessage(), ['app' => 'user_pods']);
			return false;
		}
	}

	/** Invited by e-mail into a group (the membership row keeps the invitation address). */
	private function isExternalCollaborator(string $uid): bool {
		if (!$this->appManager->isEnabledForAnyone('user_group_admin')
			|| !class_exists('\\OCA\\UserGroupAdmin\\Db\\GroupMemberMapper')) {
			return false;
		}
		try {
			foreach (Server::get('\\OCA\\UserGroupAdmin\\Db\\GroupMemberMapper')->findByUid($uid) as $m) {
				if ($m->getInvitationEmail() !== '') {
					return true;
				}
			}
		} catch (\Throwable $e) {
			$this->logger->warning('user_pods: membership lookup failed for ' . $uid . ': ' . $e->getMessage(), ['app' => 'user_pods']);
		}
		return false;
	}
}
