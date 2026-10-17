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
    public const PREFIX = 'koe.';

    /** Data file the fetch writes, relative to the project root. */
    public const DEFAULT_FILE = 'site/data/reviews.json';

    /**
     * Where the pre-1.0 copy (`site/plugins/bewertungen`) kept the file,
     * relative to the project root. Read when the default file does not exist
     * yet, so a site keeps showing its reviews between the switch to the
     * package and the first nightly fetch (or the `git mv` in the switch PR).
     */
    public const LEGACY_FILE = 'site/daten/bewertungen.json';

    public static function get(App $kirby, string $key, mixed $default = null): mixed
    {
        return $kirby->option(self::PREFIX . $key) ?? $default;
    }

    /** Project root: Kirby's `base` root, otherwise the directory above `site/`. */
    public static function base(App $kirby): string
    {
        return $kirby->root('base') ?? dirname($kirby->root('site'));
    }

    /**
     * Absolute path of the data file.
     *
     * `koe.file` may be an absolute path, a path relative to the project root
     * (NOT to the working directory, which is public/ under Apache), or a
     * closure returning either. Without the option: site/data/reviews.json, or
     * the legacy site/daten/bewertungen.json while only that one exists (the
     * fallback is logged so it does not go unnoticed).
     */
    public static function file(App $kirby): string
    {
        $option = self::get($kirby, 'file');
        if ($option instanceof Closure) {
            $option = $option();
        }
        $base = self::base($kirby);
        if ($option !== null) {
            return self::resolve((string) $option, $base);
        }
        $default = self::resolve(self::DEFAULT_FILE, $base);
        $legacy = self::resolve(self::LEGACY_FILE, $base);
        if (!is_file($default) && is_file($legacy)) {
            error_log('Koe: reading the legacy data file ' . self::LEGACY_FILE . ' — move it to ' . self::DEFAULT_FILE . ' (git mv) or set koe.file.');
            return $legacy;
        }
        return $default;
    }

    /** Absolute path stays, anything else is taken relative to $base. */
    public static function resolve(string $path, string $base): string
    {
        $path = trim($path);
        return str_starts_with($path, '/') ? $path : rtrim($base, '/') . '/' . $path;
    }

    /**
     * Selection rules, each with the default that the pre-1.0 copy had as a
     * constant. `approved` is null (off) or a list of review ids. `maxAgeDays`
     * is not a selection rule but the freshness limit of the data file (at
     * least 1 day).
     *
     * @return array{minRating:int, minLength:int, maxAgeMonths:int, maxCount:int, truncate:int, approved:?array, maxAgeDays:int}
     */
    public static function rules(App $kirby): array
    {
        $approved = self::get($kirby, 'approved');
        return [
            'minRating'    => max(1, min(5, (int) self::get($kirby, 'minRating', Reviews::MIN_RATING))),
            'minLength'    => max(0, (int) self::get($kirby, 'minLength', Reviews::MIN_LENGTH)),
            'maxAgeMonths' => max(0, (int) self::get($kirby, 'maxAgeMonths', Reviews::MAX_AGE_MONTHS)),
            'maxCount'     => max(0, (int) self::get($kirby, 'maxCount', Reviews::MAX_COUNT)),
            'truncate'     => max(0, (int) self::get($kirby, 'truncate', Reviews::TRUNCATE)),
            'approved'     => is_array($approved) ? array_values(array_map('strval', $approved)) : null,
            'maxAgeDays'   => max(1, (int) self::get($kirby, 'maxAgeDays', Reviews::MAX_AGE_DAYS)),
        ];
    }

    /**
     * Logs why nothing is shown, but only in Kirby's debug mode: on a live
     * site a data file that is withheld on purpose must not fill the error log
     * on every page view.
     */
    public static function debugLog(App $kirby, string $message): void
    {
        if ($kirby->option('debug') === true) {
            error_log('koe: ' . $message);
        }
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
}
