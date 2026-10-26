<?php

use Kirby\Cms\App;
use Sayamaapps\Koe\Plugin;

// Profile pictures of the reviewers, from the cache: the browser never asks
// Google for them. Logic in Plugin::avatar().
return [
	[
		'pattern' => 'koe/avatar/(:alphanum)',
		'action'  => function (string $id) {
			return Plugin::avatar(App::instance(), $id);
		},
	],
];
