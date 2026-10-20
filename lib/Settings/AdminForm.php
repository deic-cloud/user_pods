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
 * PodService: publicIP, podManagementIP, storageDir, maxPodsPerUser, unlimitedPodUsers,
 * guestPods, guestPodLifetime, guestMaxPods, guestPodType, manifestsURL, rawManifestsURL.
 */
class AdminForm implements IDeclarativeSettingsForm {
	public function getSchema(): array {
		return [
			'id' => 'user_pods_admin',
			'priority' => 50,
			'section_type' => DeclarativeSettingsTypes::SECTION_TYPE_ADMIN,
			'section_id' => 'user-pods',
			'storage_type' => DeclarativeSettingsTypes::STORAGE_TYPE_INTERNAL,
			'title' => 'Kubernetes service and image library',
			'description' => 'Endpoints of the container service and image library',
			'fields' => [
				[
					'id' => 'podManagementIP',
					'title' => 'Management IP (private)',
					'description' => 'Private IP/hostname of the Kubernetes frontend',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '10.0.0.12',
					'default' => '',
				],
				[
					'id' => 'publicIP',
					'title' => 'Public hostname',
					'description' => 'Public hostname of the Kubernetes frontend',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'kube.example.org',
					'default' => '',
				],
				[
					'id' => 'storageDir',
					'title' => 'Storage directory',
					'description' => 'Directory exposed to pods as /storage via WebDAV and NFS.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => '/tank/storage',
					'default' => '',
				],
				[
					'id' => 'maxPodsPerUser',
					'title' => 'Pods per user',
					'description' => 'How many pods one user may run at the same time. 0 = no limit.',
					'type' => DeclarativeSettingsTypes::NUMBER,
					'placeholder' => '5',
					'default' => 5,
				],
				[
					'id' => 'unlimitedPodUsers',
					'title' => 'Users without a pod limit',
					'description' => 'Account names the limit does not apply to, separated by commas (service accounts). Administrators are always exempt.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'cloud, batch',
					'default' => '',
				],
				[
					'id' => 'guestPods',
					'title' => 'Guest notebooks',
					'description' => 'Let visitors who are not logged in run a notebook from a public link in a throwaway pod: no home server, nothing saved, deleted after the lifetime below. Off by default.',
					'type' => DeclarativeSettingsTypes::CHECKBOX,
					'label' => 'Allow guest notebook pods',
					'default' => false,
				],
				[
					'id' => 'guestPodLifetime',
					'title' => 'Guest pod lifetime (minutes)',
					'description' => 'A guest pod is deleted this many minutes after it was started, whether in use or not.',
					'type' => DeclarativeSettingsTypes::NUMBER,
					'placeholder' => '60',
					'default' => 60,
				],
				[
					'id' => 'guestMaxPods',
					'title' => 'Guest pods at the same time',
					'description' => 'Ceiling on simultaneous guest pods for the whole service; one guest pod per visitor address on top of that.',
					'type' => DeclarativeSettingsTypes::NUMBER,
					'placeholder' => '10',
					'default' => 10,
				],
				[
					'id' => 'guestPodType',
					'title' => 'Guest pod type',
					'description' => 'Pod type (size) for guest pods: small, medium or large.',
					'type' => DeclarativeSettingsTypes::TEXT,
					'placeholder' => 'medium',
					'default' => 'medium',
				],
				[
					'id' => 'manifestsURL',
					'title' => 'Manifest library — listing API',
					'description' => 'GitHub contents API URL used to list image manifests',
					'type' => 'url',
					'placeholder' => 'https://api.github.com/repos/deic-dk/pod_manifests/contents',
					'default' => 'https://api.github.com/repos/deic-dk/pod_manifests/contents',
				],
				[
					'id' => 'rawManifestsURL',
					'title' => 'Manifest library — raw file base',
					'description' => 'Base URL for fetching yaml and md (image description) files.',
					'type' => 'url',
					'placeholder' => 'https://raw.githubusercontent.com/deic-dk/pod_manifests/main/',
					'default' => 'https://raw.githubusercontent.com/deic-dk/pod_manifests/main/',
				],
			],
		];
	}
}
