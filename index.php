<?php

use Kirby\Cms\App;

require_once __DIR__ . '/src/I18n.php';
require_once __DIR__ . '/src/Options.php';
require_once __DIR__ . '/src/Reviews.php';

use Koe\I18n;
use Koe\Options;
use Koe\Reviews;

/**
 * Koe (声, "voice") — Google reviews served from your own server. Off by
 * default: without `'koe.active' => true`, $site->koeReviews() is null and
 * the snippet prints nothing, even when the data file is there.
 *
 * The website never talks to Google. bin/koe-fetch (nightly, on a CI runner
 * or a server cron) writes the data file; the API key lives only there, as a
 * secret, never in a Kirby option.
 *
 * Defaults are passed as fallback values directly at each option call, NOT
 * in an 'options' block (Kirby would file them under sayamaapps.koe.active
 * otherwise).
 */

/*
 * Old copy still there? site/plugins/bewertungen/ (the pre-1.0 copy of this
 * plugin) can sit next to the package after an incomplete switch. Both load
 * without conflict — different plugin id, snippet name and site methods — so
 * the package stays active (a template calling $site->koeReviews() must not
 * fail). It only says so in the error log until the copy is deleted.
 */
if (class_exists('Bewertungen\\Stimmen', false)) {
    error_log('Koe: site/plugins/bewertungen/ (the pre-1.0 copy) still exists next to the package — delete it.');
}

/** Reads `koe.<key>`. Also meant for site snippets with their own markup. */
function koe_option(string $key, mixed $default = null): mixed
{
    return Options::get(App::instance(), $key, $default);
}

$koeTranslations = [];
foreach (I18n::LANGUAGES as $koeLanguage) {
    foreach (I18n::table($koeLanguage) as $koeKey => $koeText) {
        $koeTranslations[$koeLanguage]['koe.' . $koeKey] = $koeText;
    }
}
unset($koeLanguage, $koeKey, $koeText);

App::plugin('sayamaapps/koe', [
    // The snippet strings, also usable as t('koe.<key>').
    'translations' => $koeTranslations,

    /**
     * A plain default. A site with its own markup creates
     * site/snippets/koe/reviews.php — Kirby looks there first — or any
     * snippet of its own that calls $site->koeReviews().
     */
    'snippets' => [
        'koe/reviews' => __DIR__ . '/snippets/reviews.php',
    ],

    'siteMethods' => [
        /**
         * Overall rating, count and the selected reviews, or null (= show
         * nothing: switched off, file missing, unreadable or empty).
         *
         *   ['rating' => 4.8, 'count' => 201, 'placeId' => 'ChIJ…'|null,
         *    'reviews' => [['id', 'name', 'rating', 'text', 'date', 'month', 'url'], …],
         *    'limit' => 3, 'profileUrl' => 'https://…'|null]
         *
         * `limit` is the configured maximum: a snippet fills the remaining
         * slots with a "read all on Google" tile when count(reviews) < limit.
         */
        'koeReviews' => function (): ?array {
            $kirby = $this->kirby();
            if (Options::get($kirby, 'active', false) !== true) {
                return null;
            }
            I18n::setLanguage(Options::language($kirby));
            $result = Reviews::load(Options::file($kirby), Options::rules($kirby));
            if ($result === null) {
                return null;
            }
            $result['profileUrl'] = $this->koeProfileUrl($result['placeId']);
            return $result;
        },

        /**
         * Address of the Google profile, or null (then no profile link and no
         * tile). From `koe.placeId`, otherwise from the place id the fetch
         * recorded in the data file (passed in by koeReviews()).
         */
        'koeProfileUrl' => function (?string $fromFile = null): ?string {
            $placeId = Options::get($this->kirby(), 'placeId');
            return Reviews::profileUrl(is_string($placeId) && trim($placeId) !== '' ? $placeId : $fromFile);
        },
    ],
]);
