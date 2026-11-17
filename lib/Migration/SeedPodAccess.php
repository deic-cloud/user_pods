<?php

declare(strict_types=1);

namespace OCA\UserPods\Migration;

use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Once, on upgrade to the vouched-accounts rule (PodAccess): start the allow
 * list with the service accounts that already had no pod limit
 * ('unlimitedPodUsers', e.g. cloud, batch), so they keep starting pods.
 */
class SeedPodAccess implements IRepairStep {
	public function __construct(
		private IAppConfig $appConfig,
	) {
	}

	public function getName(): string {
		return 'Containers: allow the service accounts to start containers';
	}

	public function run(IOutput $output): void {
		if ($this->appConfig->hasKey('user_pods', 'podAllowUsers')) {
			return;
		}
		$seed = trim($this->appConfig->getValueString('user_pods', 'unlimitedPodUsers', ''));
		$this->appConfig->setValueString('user_pods', 'podAllowUsers', $seed);
		$output->info('Allowed to start containers: ' . ($seed === '' ? '(none)' : $seed));
	}
}
