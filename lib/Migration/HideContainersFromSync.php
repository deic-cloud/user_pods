<?php

declare(strict_types=1);

namespace OCA\UserPods\Migration;

use OCA\UserPods\Service\SyncRuleBridge;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Once, on upgrade: the sync rule for Containers for every user who already
 * has that folder (pods created before the rule existed). New pods add it
 * themselves (PodService::createPod).
 */
class HideContainersFromSync implements IRepairStep {
	public function __construct(
		private IDBConnection $db,
		private SyncRuleBridge $bridge,
	) {
	}

	public function getName(): string {
		return 'Hide the folder Containers from sync clients';
	}

	public function run(IOutput $output): void {
		if (!$this->bridge->available()) {
			return;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('s.id')
			->from('filecache', 'fc')
			->innerJoin('fc', 'storages', 's', $qb->expr()->eq('s.numeric_id', 'fc.storage'))
			->where($qb->expr()->eq('fc.path', $qb->createNamedParameter('files' . SyncRuleBridge::FOLDER)))
			->andWhere($qb->expr()->like('s.id', $qb->createNamedParameter('home::%')));
		$res = $qb->executeQuery();
		$n = 0;
		while ($id = $res->fetchOne()) {
			$this->bridge->hideContainersFromSync(substr((string)$id, strlen('home::')));
			$n++;
		}
		$res->closeCursor();
		$output->info("Containers hidden from sync clients for $n user(s)");
	}
}
