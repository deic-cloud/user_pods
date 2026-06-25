<?php

declare(strict_types=1);

namespace OCA\UserPods\Settings;

use OCP\Settings\DeclarativeSettingsTypes;
use OCP\Settings\IDeclarativeSettingsForm;

/**
 * Admin settings for the Containers (user_pods) app, rendered by core as a
 * declarative settings form under Administration → Additional settings.
 *
 * storage_type = internal means core reads/writes each field straight to this
 * app's config (user_pods/<field id>) — the exact keys PodService consumes —
 * so no controller or JS is needed. Field ids therefore MUST match the keys in
 * PodService: publicIP, privateIP, storageDir, manifestsURL, rawManifestsURL.
 */
class AdminForm implements IDeclarativeSettingsForm {
	public function getSchema(): array {
		return [
			'id' => 'user_pods_admin',
			'priority' => 50,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'additional',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Containers',
			'description' => 'Connection to the ScienceData Kubernetes host service and the manifest (image) library.',
			'fields' => [
				[
					'id' => 'privateIP',
					'title' => 'Host service IP (private)',
					'description' => 'Private management IP/host where the sciencedata_kubernetes endpoints (run_pod.php, get_containers.php, …) are reached, e.g. 10.0.0.12.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '10.0.0.12',
					'default' => '',
				],
				[
					'id' => 'publicIP',
					'title' => 'Public hostname',
					'description' => 'Public hostname/IP used to build the URLs (pod web + SSH) shown to users, e.g. kube.sciencedata.dk.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'kube.sciencedata.dk',
					'default' => '',
				],
				[
					'id' => 'storageDir',
					'title' => 'Storage directory',
					'description' => 'Directory exposed to pods as /storage via WebDAV and NFS (NFSv4.1).',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '/tank/storage',
					'default' => '',
				],
				[
					'id' => 'manifestsURL',
					'title' => 'Manifest library — listing API',
					'description' => 'GitHub contents API URL used to list the available image manifests (*.yaml).',
					'type' => 'url',
					'placeholder' => 'https://api.github.com/repos/deic-dk/pod_manifests/contents',
					'default' => 'https://api.github.com/repos/deic-dk/pod_manifests/contents',
				],
				[
					'id' => 'rawManifestsURL',
					'title' => 'Manifest library — raw file base',
					'description' => 'Base URL for fetching a manifest .yaml and its sibling .md (raw files).',
					'type' => 'url',
					'placeholder' => 'https://raw.githubusercontent.com/deic-dk/pod_manifests/main/',
					'default' => 'https://raw.githubusercontent.com/deic-dk/pod_manifests/main/',
				],
			],
		];
	}
}
