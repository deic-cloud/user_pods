<?php

declare(strict_types=1);

namespace OCA\UserPods\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * user_pods_login.gids: a pod may admit the members of several groups (e.g. a
 * lecturer's Moodle: one group per course) - JSON list. Rows without it have
 * their one group in gid ('' = just the owner).
 */
class Version000021Date20261120000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if (!$schema->hasTable('user_pods_login')) {
			return null;
		}
		$table = $schema->getTable('user_pods_login');
		if ($table->hasColumn('gids')) {
			return null;
		}
		$table->addColumn('gids', Types::TEXT, ['notnull' => false]);
		return $schema;
	}
}
