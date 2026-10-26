<?php

namespace Koe;

use Closure;
use Kirby\Cms\App;

/**
 * Reads `koe.*` options.
 *
 * `null` counts as "not set", like Kirby's own option() (A::get() checks
 * isset() as well), so `'koe.logo' => null` falls back to the default.
 *
 * Both spellings work, flat (`'koe.active' => true`) and nested
 * (`'koe' => ['active' => true]`), because Kirby's option() finds both.
 */
final class Options
{
	/** Holding time of the cache in hours: the default, and the longest allowed. */
	public const CACHE_HOURS = 24;

	/** Where the API key is looked for when `koe.key` is not set. */
	public const ENV_KEY = 'GOOGLE_PLACES_API_KEY';
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

	public static function get(App $kirby, string $key, mixed $default = null): mixed
	{
		return $kirby->option(self::PREFIX . $key) ?? $default;
	}

	/**
	 * The Places API key: `koe.key` (a string or a closure returning one),
	 * otherwise the environment variable GOOGLE_PLACES_API_KEY — getenv(),
	 * then $_SERVER, where Apache's SetEnv lands under FastCGI. Null when
	 * none is set.
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
	 * Language of the snippet text: `koe.language`, otherwise the site's
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

	/** `koe.cacheHours` as minutes: whole hours from 1 to CACHE_HOURS, default CACHE_HOURS. */
	public static function minutes(App $kirby): int
	{
		return 60 * max(1, min(self::CACHE_HOURS, (int)self::get($kirby, 'cacheHours', self::CACHE_HOURS)));
	}

	/** `koe.placeId`, trimmed: the place whose reviews are fetched. Null when not configured. */
	public static function placeId(App $kirby): string|null
	{
		$placeId = self::get($kirby, 'placeId');
		return is_string($placeId) && trim($placeId) !== '' ? trim($placeId) : null;
	}

	/**
	 * Selection rules, each with the default that the pre-1.0 copy had as a
	 * constant. `approved` is null (off) or a list of review ids, and
	 * `placeId` the place (null = not configured).
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
