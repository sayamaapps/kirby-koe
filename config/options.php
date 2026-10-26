<?php

// Options Koe reads, relative to the plugin: `sayamaapps.koe.<key>`. Each value
// is null on purpose: Options::get() gives the default at every read, and a
// default here would hide the older spelling `koe.<key>` (fallback).
return [
	'cache'        => true,
	'active'       => null,
	'placeId'      => null,
	'key'          => null,
	'cacheHours'   => null,
	'minRating'    => null,
	'minLength'    => null,
	'maxAgeMonths' => null,
	'maxCount'     => null,
	'truncate'     => null,
	'approved'     => null,
	'title'        => null,
	'logo'         => null,
	'language'     => null,
];
