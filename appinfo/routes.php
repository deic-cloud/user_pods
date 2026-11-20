<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],
		['name' => 'page#run',   'url' => '/run', 'verb' => 'GET'],
		// Guest (not logged in) run: the waiting page polls this by the guest token.
		['name' => 'page#guestStatus', 'url' => '/guest/status', 'verb' => 'GET'],
		// Guest run, asked by the waiting page: start (or find) the throwaway pod.
		['name' => 'page#runGuestStart', 'url' => '/guest/start', 'verb' => 'POST'],
		// The image catalog as a public page, with Launch links into the app.
		['name' => 'page#catalog', 'url' => '/catalog', 'verb' => 'GET'],
		// An image's guide (<image>.guide.md beside its manifest), public like the catalog.
		['name' => 'page#guide', 'url' => '/guide/{image}', 'verb' => 'GET'],

		// Pod management — thin JSON API over the sciencedata_kubernetes host service.
		['name' => 'api#containers',  'url' => '/api/containers',      'verb' => 'GET'],
		['name' => 'api#catalog',     'url' => '/api/catalog',         'verb' => 'GET'],
		['name' => 'api#manifest',    'url' => '/api/manifest',        'verb' => 'GET'],
		['name' => 'api#create',      'url' => '/api/pod',             'verb' => 'POST'],
		// asked by a pod at start: its login client for ScienceData accounts (PodLogin)
		['name' => 'api#podLogin',    'url' => '/api/pod-login',       'verb' => 'GET'],
		['name' => 'api#podMail',     'url' => '/api/pod-mail',        'verb' => 'POST'],
		['name' => 'api#podMembers',  'url' => '/api/pod-members',     'verb' => 'GET'],
		['name' => 'api#runNotebook', 'url' => '/api/run-notebook',    'verb' => 'POST'],
		['name' => 'api#delete',      'url' => '/api/pod/delete',      'verb' => 'POST'],
		['name' => 'api#allowedIps',  'url' => '/api/pod/allowed-ips', 'verb' => 'POST'],
		['name' => 'api#ports',       'url' => '/api/pod/ports',       'verb' => 'POST'],
		['name' => 'api#logs',        'url' => '/api/pod/logs',        'verb' => 'GET'],
	],
];
