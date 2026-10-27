<?php

use Kirby\Filesystem\F;

// Loads Composer (with Kirby as a fixture) and the package classes from the
// class map that index.php and bin/koe-fetch use: the tests see the same map.
$base = dirname(__DIR__);
require $base . '/vendor/autoload.php';
F::loadClasses(require $base . '/autoload.php', $base);

// Kirby's Whoops error page would count as a risky test; PHPUnit reports errors itself.
Kirby\Cms\App::$enableWhoops = false;
