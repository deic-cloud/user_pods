<?php

declare(strict_types=1);

namespace OCA\UserPods\Service;

use OCP\App\IAppManager;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Thin, guarded bridge to files_sharding's folder visibility rules: keeps the
 * folder Containers - where pods put their backups (database dumps, state
 * tarballs) - away from desktop sync clients, as user_group_admin does for
 * grant folders. A locked rule ("Managed by user_pods" in the personal
 * settings). user_pods stays installable without files_sharding.
 */
class SyncRuleBridge {
	public const FOLDER = '/Containers';
	private const LOCKED_BY = 'user_pods';
	private const MAPPER = '\\OCA\\FilesSharding\\Db\\DataFolderMapper';

	public function __construct(
		private IAppManager $appManager,
		private LoggerInterface $logger,
	) {
	}

	public function available(): bool {
		// isInstalled() is true for a disabled app: ask whether it is enabled
		return $this->appManager->isEnabledForAnyone('files_sharding') && class_exists(self::MAPPER);
	}

	public function hideContainersFromSync(string $uid): void {
		if ($uid === '' || !$this->available()) {
			return;
		}
		try {
			Server::get(self::MAPPER)->upsertLockedRule($uid, self::FOLDER, true, self::LOCKED_BY);
		} catch (\Throwable $e) {
			$this->logger->warning('user_pods: could not add the sync rule for ' . self::FOLDER . ' for ' . $uid . ': ' . $e->getMessage(), ['app' => 'user_pods']);
		}
	}
}
