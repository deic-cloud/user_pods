<?php

declare(strict_types=1);

namespace OCA\UserPods\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * user_pods_login: pods whose users log in with their ScienceData accounts
 * (images marked catalog/oidc, started for a group) - the group, and the login
 * client the master made for the pod (PodLogin).
 */
class Version000020Date20261118000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('user_pods_login')) {
			return null;
		}
		$table = $schema->createTable('user_pods_login');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('pod', Types::STRING, ['notnull' => true, 'length' => 128]);       // pod name as returned by run_pod
		$table->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 64]);
		$table->addColumn('gid', Types::STRING, ['notnull' => true, 'length' => 64]);        // who may log in
		$table->addColumn('client_id', Types::STRING, ['notnull' => false, 'length' => 64]); // made when the pod first asks
		$table->addColumn('client', Types::TEXT, ['notnull' => false]);                      // encrypted JSON: secret + addresses
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['pod'], 'user_pods_login_pod');
		return $schema;
	}
}
