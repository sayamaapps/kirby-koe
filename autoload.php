<?php

/**
 * Class map of the package: lowercase class name => file, relative to this
 * folder. Read by index.php (Kirby's F::loadClasses) and by bin/koe-fetch,
 * which runs without Kirby. tests/AutoloadTest.php checks it against src/.
 */
return [
	'sayamaapps\\koe\\fetch'          => 'src/Fetch.php',
	'sayamaapps\\koe\\i18n'           => 'src/I18n.php',
	'sayamaapps\\koe\\options'        => 'src/Options.php',
	'sayamaapps\\koe\\places'         => 'src/Places.php',
	'sayamaapps\\koe\\placesexception' => 'src/PlacesException.php',
	'sayamaapps\\koe\\plugin'         => 'src/Plugin.php',
	'sayamaapps\\koe\\reviews'        => 'src/Reviews.php',
	'sayamaapps\\koe\\site'           => 'src/Site.php',
	'sayamaapps\\koe\\store'          => 'src/Store.php',
];
