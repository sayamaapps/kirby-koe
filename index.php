<?php

use Kirby\Filesystem\F;

/**
 * Plugin registration for Koe (声, "voice"): Google reviews served from your
 * own server. Off by default: without `'koe.active' => true`,
 * $site->koeReviews() is null and the snippet prints nothing and nothing is
 * fetched.
 *
 * Kirby loads this file. It registers the class map, the old names and the
 * definitions in config/. The logic lives in src/.
 */
F::loadClasses(require __DIR__ . '/autoload.php', __DIR__);
require_once __DIR__ . '/legacy.php';

/*
 * Old copy still there? site/plugins/bewertungen/ (the pre-1.0 copy of this
 * plugin) can sit next to the package after an incomplete switch. Both load
 * without conflict — different plugin id, snippet name and site methods — so
 * the package stays active (a template calling $site->koeReviews() must not
 * fail). It only says so in the error log until the copy is deleted.
 */
if (class_exists('Bewertungen\\Stimmen', false)) {
	error_log('Koe: site/plugins/bewertungen/ (the pre-1.0 copy) still exists next to the package — delete it.');
}

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
