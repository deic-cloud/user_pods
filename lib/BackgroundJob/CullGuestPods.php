<?php

declare(strict_types=1);

namespace OCA\UserPods\BackgroundJob;

use OCA\UserPods\Service\PodService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\TimedJob;
use Psr\Log\LoggerInterface;

/**
 * Deletes guest pods whose lifetime has passed, and forgets rows whose pod is
 * already gone. Runs with cron every five minutes; a guest pod therefore lives
 * its configured lifetime plus at most one cron interval.
 */
class CullGuestPods extends TimedJob {
	public function __construct(
		ITimeFactory $time,
		private PodService $pods,
		private LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(300);
		$this->setTimeSensitivity(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void {
		try {
			$n = $this->pods->cullGuests();
			if ($n > 0) {
				$this->logger->info('user_pods: removed ' . $n . ' expired guest pod(s)');
			}
		} catch (\Throwable $e) {
			$this->logger->warning('user_pods: guest cull failed: ' . $e->getMessage());
		}
	}
}
