<?php

use Kirby\Filesystem\F;

/**
 * Plugin registration for Koe (声, "voice"): Google reviews served from your
 * own server. Off by default: without `'sayamaapps.koe.active' => true` (or
 * the older `koe.active`), $site->koeReviews() is null and the snippet prints
 * nothing and nothing is fetched.
 *
 * Kirby loads this file. It registers the class map, the legacy names and the
 * definitions in config/. The logic lives in src/.
 */
F::loadClasses(require __DIR__ . '/autoload.php', __DIR__);
require_once __DIR__ . '/legacy.php';

Kirby::plugin(
	name: 'sayamaapps/koe',
	extends: [
		'options'      => require __DIR__ . '/config/options.php',
		'translations' => require __DIR__ . '/config/translations.php',
		'snippets'     => require __DIR__ . '/config/snippets.php',
		'routes'       => require __DIR__ . '/config/routes.php',
		'siteMethods'  => require __DIR__ . '/config/site-methods.php',
	],
	root: __DIR__
);
