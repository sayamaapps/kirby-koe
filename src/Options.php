<?php

namespace Sayamaapps\Koe;

use Closure;
use Kirby\Cms\App;

/**
 * Reads Koe's options: `sayamaapps.koe.<key>`, falling back to the 2.3 name
 * `koe.<key>`.
 *
 * The flat form (`'sayamaapps.koe.active' => true`) is the one to use. The
 * nested form (`'koe' => ['active' => true]`) works for the 2.3 prefix only:
 * Kirby resolves `sayamaapps.koe.*` through the plugin's own options block
 * first, which shadows a nested `sayamaapps` array.
 *
 * Defaults are given here and at the call sites. The `options` block of the
 * plugin (config/options.php) lists the keys with null: a default there would
 * hide the 2.3 spelling.
 *
 * `null` counts as "not set", like Kirby's own option() (A::get() checks
 * isset() as well), so `'sayamaapps.koe.logo' => null` falls back to the default.
 *
 * @package   Sayamaapps\Koe
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-koe
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Options
{
	/** Holding time of the cache in hours: the default, and the longest allowed. */
	public const CACHE_HOURS = 24;

	/** Where the API key is looked for when the `key` option is not set. */
	public const ENV_KEY = 'GOOGLE_PLACES_API_KEY';

	/** Option prefix since 2.4: `sayamaapps.koe.<key>`. */
	public const OPTION_PREFIX = 'sayamaapps.koe.';

	/**
	 * Option prefix up to 2.3 (`koe.<key>`). Still read as a fallback until 3.0.
	 *
	 * @deprecated 2.4 use OPTION_PREFIX; removal planned for 3.0
	 */
	public const PREFIX = 'koe.';

	/**
	 * Logs why nothing is shown, but only in Kirby's debug mode: on a live
	 * site a missing setting must not fill the error log on every page view.
	 */
	public static function debugLog(App $kirby, string $message): void
	{
		if ($kirby->option('debug') === true) {
			error_log('koe: ' . $message);
		}
	}

	/**
	 * Reads `sayamaapps.koe.<key>`, then `koe.<key>` (2.3). The 2.3 name is
	 * read without a notice. Returns $default when neither is set.
	 */
	public static function get(App $kirby, string $key, mixed $default = null): mixed
	{
		$value = $kirby->option(self::OPTION_PREFIX . $key);
		if ($value !== null) {
			return $value;
		}

		return $kirby->option(self::PREFIX . $key) ?? $default;
	}

	/**
	 * The option name as the site spelled it, for messages: the 2.3 koe.<key>
	 * name only when the site sets no sayamaapps.koe.<key> value for it.
	 */
	public static function setKey(App $kirby, string $key): string
	{
		if ($kirby->option(self::OPTION_PREFIX . $key) === null && $kirby->option(self::PREFIX . $key) !== null) {
			return self::PREFIX . $key;
		}
		return self::OPTION_PREFIX . $key;
	}

	/**
	 * The Places API key: the `key` option (a string or a closure returning one),
	 * otherwise the environment variable GOOGLE_PLACES_API_KEY — getenv(),
	 * then $_SERVER, read at call time (Apache's SetEnv lands there under
	 * FastCGI, also when it is set after the app has booted). Null when none is set.
	 */
	public static function key(App $kirby): string|null
	{
		$key = self::get($kirby, 'key');
		if ($key instanceof Closure) {
			$key = $key();
		}
		foreach ([$key, getenv(self::ENV_KEY), $_SERVER[self::ENV_KEY] ?? null] as $candidate) {
			if (is_string($candidate) && trim($candidate) !== '') {
				return trim($candidate);
			}
		}
		return null;
	}

	/**
	 * Language of the snippet text: the `language` option, otherwise the site's
	 * default language (multi-language sites), otherwise the first two letters
	 * of Kirby's `locale` option, otherwise English. Only languages Koe ships
	 * (I18n::LANGUAGES) are returned.
	 */
	public static function language(App $kirby): string
	{
		$candidates = [self::get($kirby, 'language')];
		try {
			$candidates[] = $kirby->defaultLanguage()?->code();
		} catch (\Throwable) {
			// No languages configured.
		}
		$locale = $kirby->option('locale');
		if (is_array($locale)) {
			$locale = $locale[LC_ALL] ?? reset($locale);
		}
		$candidates[] = is_string($locale) ? substr($locale, 0, 2) : null;

		foreach ($candidates as $candidate) {
			if (is_string($candidate) && in_array(strtolower($candidate), I18n::LANGUAGES, true)) {
				return strtolower($candidate);
			}
		}
		return 'en';
	}

	/** The `cacheHours` option as minutes: whole hours from 1 to CACHE_HOURS, default CACHE_HOURS. */
	public static function minutes(App $kirby): int
	{
		return 60 * max(1, min(self::CACHE_HOURS, (int)self::get($kirby, 'cacheHours', self::CACHE_HOURS)));
	}

	/** The `placeId` option, trimmed: the place whose reviews are fetched. Null when not configured. */
	public static function placeId(App $kirby): string|null
	{
		$placeId = self::get($kirby, 'placeId');
		return is_string($placeId) && trim($placeId) !== '' ? trim($placeId) : null;
	}

	/**
	 * Selection rules, each with the default of its Reviews constant.
	 * `approved` is null (off) or a list of review ids, and `placeId` the
	 * place (null = not configured).
	 *
	 * @return array{minRating:int, minLength:int, maxAgeMonths:int, maxCount:int, truncate:int, approved:?array, placeId:?string}
	 */
	public static function rules(App $kirby): array
	{
		$approved = self::get($kirby, 'approved');
		return [
			'minRating'    => max(1, min(5, (int)self::get($kirby, 'minRating', Reviews::MIN_RATING))),
			'minLength'    => max(0, (int)self::get($kirby, 'minLength', Reviews::MIN_LENGTH)),
			'maxAgeMonths' => max(0, (int)self::get($kirby, 'maxAgeMonths', Reviews::MAX_AGE_MONTHS)),
			'maxCount'     => max(0, (int)self::get($kirby, 'maxCount', Reviews::MAX_COUNT)),
			'truncate'     => max(0, (int)self::get($kirby, 'truncate', Reviews::TRUNCATE)),
			'approved'     => is_array($approved) ? array_values(array_map('strval', $approved)) : null,
			'placeId'      => self::placeId($kirby),
		];
	}
}
