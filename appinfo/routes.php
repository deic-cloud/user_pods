<?php

declare(strict_types=1);

return [
	'routes' => [
		['name' => 'page#index', 'url' => '/', 'verb' => 'GET'],

		// Pod management — thin JSON API over the sciencedata_kubernetes host service.
		['name' => 'api#containers',  'url' => '/api/containers',      'verb' => 'GET'],
		['name' => 'api#manifests',   'url' => '/api/manifests',       'verb' => 'GET'],
		['name' => 'api#manifest',    'url' => '/api/manifest',        'verb' => 'GET'],
		['name' => 'api#create',      'url' => '/api/pod',             'verb' => 'POST'],
		['name' => 'api#delete',      'url' => '/api/pod/delete',      'verb' => 'POST'],
		['name' => 'api#allowedIps',  'url' => '/api/pod/allowed-ips', 'verb' => 'POST'],
		['name' => 'api#ports',       'url' => '/api/pod/ports',       'verb' => 'POST'],
		['name' => 'api#logs',        'url' => '/api/pod/logs',        'verb' => 'GET'],
	],
];
