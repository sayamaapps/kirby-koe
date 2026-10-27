<?php

use Sayamaapps\Koe\I18n;

// The snippet strings, also usable as t('sayamaapps.koe.<key>'), and under
// the earlier prefix t('koe.<key>') until the next major version. Koe reads
// its own tables (I18n), so a missing Kirby translation never changes a text.
$translations = [];
foreach (I18n::LANGUAGES as $language) {
	foreach (I18n::table($language) as $key => $text) {
		$translations[$language]['koe.' . $key] = $text;
		$translations[$language]['sayamaapps.koe.' . $key] = $text;
	}
}

return $translations;
