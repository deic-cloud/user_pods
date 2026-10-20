<?php

declare(strict_types=1);

namespace OCA\UserPods\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\DB\Types;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

/**
 * Guest pods: one row per throwaway pod started for an anonymous visitor
 * ("Run this notebook" without a login). The row is what the waiting page
 * polls by its token, what limits visitors per address, and what the cull job
 * works through: a guest pod is deleted when its expires_at has passed.
 */
class Version000019Date20261020000000 extends SimpleMigrationStep {
	public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
		/** @var ISchemaWrapper $schema */
		$schema = $schemaClosure();
		if ($schema->hasTable('user_pods_guests')) {
			return null;
		}
		$table = $schema->createTable('user_pods_guests');
		$table->addColumn('id', Types::BIGINT, ['autoincrement' => true, 'notnull' => true]);
		$table->addColumn('token', Types::STRING, ['notnull' => true, 'length' => 32]);      // the visitor's handle (URL)
		$table->addColumn('owner', Types::STRING, ['notnull' => true, 'length' => 64]);      // guest-<hex>, the pod owner on the host
		$table->addColumn('pod', Types::STRING, ['notnull' => true, 'length' => 128]);       // pod name as returned by run_pod
		$table->addColumn('image', Types::STRING, ['notnull' => true, 'length' => 128]);     // manifest file name
		$table->addColumn('file', Types::STRING, ['notnull' => true, 'length' => 255]);      // notebook file name
		$table->addColumn('client_ip', Types::STRING, ['notnull' => true, 'length' => 45]);
		$table->addColumn('created_at', Types::BIGINT, ['notnull' => true]);
		$table->addColumn('expires_at', Types::BIGINT, ['notnull' => true]);
		$table->setPrimaryKey(['id']);
		$table->addUniqueIndex(['token'], 'user_pods_guests_token');
		$table->addIndex(['client_ip'], 'user_pods_guests_ip');
		$table->addIndex(['expires_at'], 'user_pods_guests_exp');
		return $schema;
	}
}
