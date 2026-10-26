<?php

/**
 * Names of earlier versions, kept until the next major version. Loaded by
 * index.php only: class aliases and function wrappers, no logic.
 */

class_alias(Sayamaapps\Koe\Fetch::class, 'Koe\\Fetch');
class_alias(Sayamaapps\Koe\I18n::class, 'Koe\\I18n');
class_alias(Sayamaapps\Koe\Options::class, 'Koe\\Options');
class_alias(Sayamaapps\Koe\Places::class, 'Koe\\Places');
class_alias(Sayamaapps\Koe\PlacesException::class, 'Koe\\PlacesException');
class_alias(Sayamaapps\Koe\Plugin::class, 'Koe\\Plugin');
class_alias(Sayamaapps\Koe\Reviews::class, 'Koe\\Reviews');
class_alias(Sayamaapps\Koe\Store::class, 'Koe\\Store');

if (Kirby\Cms\Helpers::hasOverride('koe_option') === false) {
	/** Reads `sayamaapps.koe.<key>`, or the older `koe.<key>`. Deprecated: use Sayamaapps\Koe\Options::get(). */
	function koe_option(string $key, mixed $default = null): mixed
	{
		Kirby\Cms\Helpers::deprecated(
			'koe_option() is deprecated, use Sayamaapps\Koe\Options::get()',
			'koe-function'
		);

		return Sayamaapps\Koe\Options::get(Kirby\Cms\App::instance(), $key, $default);
	}
}
