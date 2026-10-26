<?php

use Kirby\Cms\App;

require_once __DIR__ . '/src/I18n.php';
require_once __DIR__ . '/src/Options.php';
require_once __DIR__ . '/src/Reviews.php';
require_once __DIR__ . '/src/PlacesException.php';
require_once __DIR__ . '/src/Places.php';
require_once __DIR__ . '/src/Store.php';
require_once __DIR__ . '/src/Plugin.php';

use Koe\I18n;
use Koe\Options;
use Koe\Plugin;
use Koe\Reviews;

/**
 * Koe (声, "voice") — Google reviews served from your own server. Off by
 * default: without `'koe.active' => true`, $site->koeReviews() is null and
 * the snippet prints nothing and nothing is fetched.
 *
 * The visitor's browser never talks to Google. The server fetches the
 * reviews when a page view finds none in its short-lived cache (Plugin,
 * Store), after that view's response; profile pictures are served from the
 * same cache through the route below.
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
	// Kirby's plugin cache `sayamaapps.koe` (site/cache/ by default): the only
	// place anything from Google is kept. A site may set
	// 'sayamaapps.koe.cache' => ['type' => 'apcu'] or the like.
	'options' => [
		'cache' => true,
	],

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

	// Profile pictures of the reviewers, from the cache: the browser never
	// asks Google for them.
	'routes' => [
		[
			'pattern' => 'koe/avatar/(:alphanum)',
			'action'  => fn (string $id) => Plugin::avatar(App::instance(), $id),
		],
	],

	'siteMethods' => [
		/**
		 * Overall rating, count and the selected reviews (possibly none), or
		 * null (= show nothing: switched off, no `koe.placeId`, no key,
		 * nothing cached yet — then the reviews are fetched after this
		 * response — or a place without rating; the debug log says why).
		 *
		 *   ['rating' => 4.8, 'count' => 201, 'placeId' => 'ChIJ…',
		 *    'reviews' => [['id', 'name', 'rating', 'text', 'date', 'month', 'url', 'avatar'], …],
		 *    'limit' => 3, 'profileUrl' => 'https://…'|null, 'notice' => '…']
		 *
		 * `limit` is the configured maximum: a snippet fills the remaining
		 * slots with a "read all on Google" tile when count(reviews) < limit.
		 * `notice` tells visitors how the reviews were selected and sorted,
		 * worded from the options in force (plain text, in the snippet's
		 * language); a snippet that lists single reviews prints it.
		 */
		'koeReviews' => function (): array|null {
			$kirby = $this->kirby();
			if (Options::get($kirby, 'active', false) !== true) {
				return null;
			}
			I18n::setLanguage(Options::language($kirby));
			$rules = Options::rules($kirby);
			$result = Plugin::reviews($kirby, $rules);
			if ($result === null) {
				return null;
			}
			$result['notice'] = Reviews::notice($rules);
			$result['profileUrl'] = $this->koeProfileUrl();
			return $result;
		},

		/**
		 * Address of the Google profile from `koe.placeId`, or null (then no
		 * profile link and no tile).
		 */
		'koeProfileUrl' => function (): string|null {
			return Reviews::profileUrl(Options::placeId($this->kirby()));
		},
	],
]);
