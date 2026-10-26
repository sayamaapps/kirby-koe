<?php

// Loads Composer (with Kirby as a fixture) and the package sources. The
// sources are required here, so every test finds them without a class map.
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';

foreach (['I18n', 'Options', 'Reviews', 'PlacesException', 'Places', 'Store', 'Plugin', 'Fetch'] as $class) {
	require_once $base . '/src/' . $class . '.php';
}

// Kirby's Whoops error page would count as a risky test; PHPUnit reports errors itself.
Kirby\Cms\App::$enableWhoops = false;
