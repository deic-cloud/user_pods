<?php

declare(strict_types=1);

namespace OCA\UserPods\Service;

use OCP\App\IAppManager;
use OCP\IDBConnection;
use OCP\Security\ICrypto;
use OCP\Server;
use Psr\Log\LoggerInterface;

/**
 * Logging in to a pod with ScienceData accounts (OpenID Connect).
 *
 * An image marked catalog/oidc logs people in with their ScienceData accounts.
 * Started for one of the owner's groups, only that group's members can (the
 * provider restricts the client to the group). Started "just for me", the
 * client is unrestricted - the provider can only restrict to groups - and the
 * image itself admits only the owner (the 'group' in the pod's answer is '').
 * Pods started before this have no row and keep their own local login.
 *
 * The login client needs the pod's exact address, which is only known once the
 * pod runs, so the pod asks for it at start (GET api/pod-login, from inside the
 * pod: authenticated as its owner by the pod network's address login). Then
 * the master (files_sharding OidcClients) creates the client, restricted to the
 * group, with the redirect URI https://<host>:<pod's port><callback path> - the
 * path named by the pod (Moodle: /admin/oauth2callback.php), by default
 * Overleaf's /oidc/login/callback; a path only, so always the pod's own address.
 * Deleting the pod deletes the client.
 */
class PodLogin {
	private const TABLE = 'user_pods_login';

	public function __construct(
		private IDBConnection $db,
		private ICrypto $crypto,
		private IAppManager $appManager,
		private GroupBridge $groups,
		private LoggerInterface $logger,
	) {
	}

	/** Can pods get login clients here (files_sharding with its client endpoints)? */
	public function available(): bool {
		return $this->appManager->isEnabledForAnyone('files_sharding')
			&& class_exists('\\OCA\\FilesSharding\\Service\\InterServerClient')
			&& class_exists('\\OCA\\FilesSharding\\Service\\OidcClients');
	}

	/** The groups $uid may start a group pod for: those they belong to, hidden ones (institutions) excluded. */
	public function groupsFor(string $uid): array {
		return $this->groups->visibleGroupsOf($uid);
	}

	/** Before starting: may $uid start a pod for $gid? */
	public function checkGroup(string $uid, string $gid): void {
		if (!$this->available()) {
			throw new \RuntimeException('Logging in with ScienceData accounts is not available on this server.');
		}
		if (!in_array($gid, $this->groupsFor($uid), true)) {
			throw new \RuntimeException('You can only start a container for a group you are a member of.');
		}
	}

	/** After run_pod: remember that this pod's users log in as members of $gid ('' = just the owner). */
	public function register(string $uid, string $pod, string $gid): void {
		$this->forget($uid, $pod); // a stale row of an earlier pod with the same name
		$qb = $this->db->getQueryBuilder();
		$qb->insert(self::TABLE)->values([
			'pod' => $qb->createNamedParameter($pod),
			'owner' => $qb->createNamedParameter($uid),
			'gid' => $qb->createNamedParameter($gid),
			'created_at' => $qb->createNamedParameter(time()),
		])->executeStatement();
	}

	/**
	 * For the pod asking: its login client - created on the master the first
	 * time - or null when the pod logs people in itself (not a group pod).
	 *
	 * @param array $container the pod's row from the container list (pod_name, url)
	 * @param string $callback path of the pod's login callback ('' = Overleaf's)
	 */
	public function clientFor(string $uid, array $container, string $callback = ''): ?array {
		$pod = (string)($container['pod_name'] ?? '');
		$row = $this->row($uid, $pod);
		if ($row === null) {
			return null;
		}
		if (!empty($row['client'])) {
			return json_decode($this->crypto->decrypt((string)$row['client']), true) ?: null;
		}
		$base = rtrim((string)($container['url'] ?? ''), '/');
		if (!preg_match('#^https://[^/]+:\d+$#', $base)) {
			throw new \RuntimeException('The container has no web address yet.');
		}
		if ($callback === '') {
			$callback = '/oidc/login/callback';
		}
		if (!preg_match('#^(/[A-Za-z0-9._-]+)+$#', $callback) || str_contains($callback, '/..')) {
			throw new \RuntimeException('Invalid login callback path.');
		}
		$client = $this->master('internal/oidc/clients', [
			'name' => 'Container ' . $pod,
			'redirect_uri' => $base . $callback,
			'gid' => (string)$row['gid'],
		]);
		if (empty($client['client_id'])) {
			throw new \RuntimeException('The master could not create a login client: ' . ($client['message'] ?? 'no answer'));
		}
		$client['group'] = (string)$row['gid'];
		$qb = $this->db->getQueryBuilder();
		$qb->update(self::TABLE)
			->set('client_id', $qb->createNamedParameter($client['client_id']))
			->set('client', $qb->createNamedParameter($this->crypto->encrypt(json_encode($client))))
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'])))
			->executeStatement();
		return $client;
	}

	/** The pod is gone: delete its login client on the master and the row. */
	public function forget(string $uid, string $pod): void {
		$row = $this->row($uid, $pod);
		if ($row === null) {
			return;
		}
		if (!empty($row['client_id'])) {
			$this->master('internal/oidc/clients/delete', ['client_id' => (string)$row['client_id']]);
		}
		$qb = $this->db->getQueryBuilder();
		$qb->delete(self::TABLE)
			->where($qb->expr()->eq('id', $qb->createNamedParameter((int)$row['id'])))
			->executeStatement();
	}

	private function row(string $uid, string $pod): ?array {
		if ($pod === '') {
			return null;
		}
		$qb = $this->db->getQueryBuilder();
		$qb->select('*')->from(self::TABLE)
			->where($qb->expr()->eq('pod', $qb->createNamedParameter($pod)))
			->andWhere($qb->expr()->eq('owner', $qb->createNamedParameter($uid)));
		$res = $qb->executeQuery();
		$row = $res->fetch();
		$res->closeCursor();
		return $row ?: null;
	}

	private function master(string $path, array $body): array {
		try {
			$sharding = Server::get('\\OCA\\FilesSharding\\Service\\ShardingService');
			$client = Server::get('\\OCA\\FilesSharding\\Service\\InterServerClient');
			return $client->postDirect($sharding->masterInternalUrl(), $path, $body) ?? [];
		} catch (\Throwable $e) {
			$this->logger->warning("user_pods: master call $path failed: " . $e->getMessage(), ['app' => 'user_pods']);
			return [];
		}
	}
}
