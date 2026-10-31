<?php

// Options Koe reads, relative to the plugin: `sayamaapps.koe.<key>`.
// Each value is null on purpose. Options::get() gives the default at every read,
// and a default here would hide the 2.3 spelling `koe.<key>` (fallback).
// `cache` is Kirby's plugin cache (`sayamaapps.koe.cache`), not a Koe option: it stays true.
return [
	'active'       => null,
	'approved'     => null,
	'cacheHours'   => null,
	'key'          => null,
	'language'     => null,
	'logo'         => null,
	'maxAgeMonths' => null,
	'maxCount'     => null,
	'minLength'    => null,
	'minRating'    => null,
	'placeId'      => null,
	'title'        => null,
	'truncate'     => null,
	'cache'        => true,
];
