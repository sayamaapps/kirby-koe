# Koe

Google reviews for [Kirby CMS](https://getkirby.com) 5, served from your own server.

*Koe* (声) is Japanese for voice. The plugin shows what customers say about a business on Google: the overall rating, the number of reviews and a few selected voices. The visitor's browser never talks to Google.

- **Short-lived server-side cache.** The site asks the Places API (New) itself when a page needs the reviews and its cache has none. The first view after the cache expired shows the section without reviews and starts the fetch after its response; the views after it read the cache. The cache holds the reviews for 24 hours by default, less if configured, lives in Kirby's cache directory (not in git, not in the content, not in a backup of `content/` or `storage/`) and is replaced as a whole by every fetch. There is no data file and no archive.
- **No request to Google from the visitor.** No script, no embed, no font, no image from Google. The reviewers' profile pictures are fetched by the server into the same cache and served from your own site; so is the "Google Maps" logo, if shown. Links to the reviews open on Google only when clicked.
- **Rule-based selection.** By default only five-star reviews with at least 80 characters, not older than 24 months, the three newest, cut at 280 characters. Optionally a list of approved review ids, so the business decides what is shown. The default snippet tells visitors how the reviews shown were selected and sorted (see [Notice on the selection](#notice-on-the-selection)).
- **Places attribution.** Author named with profile picture, both linked to the review on Google Maps (`googleMapsUri`), "Google Maps" attribution, a notice on the selection.
- **The API key stays on the server.** It is read from a Kirby option or an environment variable, never printed, never sent to the browser. Keep it out of git (see [Google setup](#google-setup)).
- **English and German.** The default snippet follows the site's language.
- **Runs anywhere.** No database, no extension beyond `mbstring` (and `curl` for the fetch, with a stream fallback).

## Requirements

- PHP 8.2–8.4 with `mbstring`; `curl` recommended for the fetch; outgoing HTTPS from the web server to `places.googleapis.com` and `*.googleusercontent.com`
- Kirby 5, with a writable cache directory (`site/cache/` by default)
- A Google Cloud project with the **Places API (New)** enabled (see [Google setup](#google-setup))

## Installation

```sh
composer require sayamaapps/kirby-koe
```

Composer installs the plugin into `site/plugins/koe/` (via `getkirby/composer-installer`, which ships with `getkirby/cms`) and links the CLI as `vendor/bin/koe-fetch`. Add `/site/plugins/koe/` to `.gitignore` and make sure your deploy ships it, just as it ships `kirby/` and `vendor/`.

Until the package is on Packagist, add the repository first:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/sayamaapps/kirby-koe" }]
```

Manual installation (download into `site/plugins/koe/`) also works; the CLI is then `php site/plugins/koe/bin/koe-fetch`.

## Google setup

1. **One Google Cloud project per client**, not one project for all sites. Each project has its own key, its own quota and its own billing line, so a leaked or revoked key, a quota cap or a cost question only ever concerns one business. The project can live in the client's Google account or in yours — decide that before creating it.
2. Enable **Places API (New)** in that project (not the legacy Places API).
3. Create an **API key** and restrict it: API restriction = Places API (New) only. If the web server has a fixed outgoing IP address, add it as an application restriction (IP addresses); the key is used from the server, never from a browser.
4. **Cap the quota.** In *APIs & Services → Places API (New) → Quotas*, set a daily limit. A fetch is one request (the details of `koe.placeId`); with the default holding time of 24 hours that is about one a day, with `koe.cacheHours` 1 about 24. A failed fetch is retried after 15 minutes at the earliest. A cap of 30 per day covers every setting; a capped project can never produce a surprising bill, even if the key leaks. `koe-fetch` needs two more per run.
5. **Give the web server the key**, outside git: either as `koe.key` in a config file that the deploy writes from a secret and git ignores (for example a `site/config/config.<host>.php`, or a file your `config.php` merges in), or as the environment variable **`GOOGLE_PLACES_API_KEY`** of the web server (Apache `SetEnv`, PHP-FPM `env[]`). `koe.key` may also be a closure.

What Google's Places policies ask of a page that shows reviews: name the author with profile picture and link (`googleMapsUri`), show the "Google Maps" attribution (logo or text), and tell visitors how the reviews were selected. The default snippet does all of it.

## Finding the place id

```sh
GOOGLE_PLACES_API_KEY=… KOE_QUERY="Business name, Street 1, 12345 Town" vendor/bin/koe-fetch
```

`koe-fetch` looks the business up and prints what Google returns now: the place (`Found: Name (ChIJ…)` — the id for `koe.placeId`) and every review with its id (for `koe.approved`). It writes nothing; the site fetches the reviews itself.

| Variable | Argument | Meaning |
|---|---|---|
| `GOOGLE_PLACES_API_KEY` | — | Required. Missing: exit code 3. |
| `KOE_QUERY` | `--query=` | Name and address **as in the Google profile**, not as on the website. Missing: exit code 1 before any request. |
| `KOE_LANGUAGE` | `--language=` | Language code for the API (`de`, `en`, …), default `en`. Affects translated texts and the display name; the review text itself is always the original. |

Exit codes: `0` done, `1` error (no query, nothing found, API failure), `3` no key. The key is never printed; Google's error messages are scrubbed of it.

The Places API returns at most five reviews per place. Reviews without text are skipped (they are counted in Google's total anyway).

## Configuration

Everything is off by default. Minimal setup in `site/config/config.php`:

```php
return [
    'koe.active'  => true,
    'koe.placeId' => 'ChIJ…',   // from koe-fetch
    // and the key, outside git: 'koe.key' in a deploy-written file, or GOOGLE_PLACES_API_KEY
];
```

In a template:

```php
<?php snippet('koe/reviews') ?>
<?php snippet('koe/reviews', ['title' => 'What our guests say', 'logo' => 'assets/GoogleMaps_Logo_Gray.svg']) ?>
```

### Options

All keys are read as `koe.<name>`, flat or nested. `null` counts as "not set".

| Option | Default | Meaning |
|---|---|---|
| `active` | `false` | Master switch. Off: `$site->koeReviews()` is `null`, the snippet prints nothing and nothing is fetched. Only `true` switches on. |
| `placeId` | `null` | Required. The place whose reviews are fetched, and the id of the profile link and the "read all on Google" tile. If Google answers for another id (the listing was recreated), nothing is kept and the error log says so; take the new id from `koe-fetch`. |
| `key` | `GOOGLE_PLACES_API_KEY` | Required. The Places API key, a string or a closure. Without the option the environment variable is read (`getenv()`, then `$_SERVER`). Keep it out of git. |
| `cacheHours` | `24` | How long the cache holds the reviews and pictures, whole hours from `1` to `24`. After that the next page view fetches anew (and shows no reviews itself). |
| `minRating` | `5` | Minimum stars. |
| `minLength` | `80` | Minimum text length in characters (after trim). |
| `maxAgeMonths` | `24` | Maximum age, counted from today (Europe/Berlin). |
| `maxCount` | `3` | Maximum number of single reviews shown; also the `limit` the snippet fills with the tile. |
| `truncate` | `280` | Longer texts are cut at a word boundary with "…". `0` = never. |
| `approved` | `null` | `null`: rules only. A list of review ids (from `koe-fetch`): only those, still subject to the rules above. `[]`: no single review, only rating, count and the tile. |
| `title` | translated | Heading of the default snippet (`Reviews on Google` / `Bewertungen auf Google`). Snippet data `title` wins. |
| `logo` | `null` | Path under `public/` to the official Google Maps logo, e.g. `assets/GoogleMaps_Logo_Gray.svg`. Without it the attribution is the text "Google Maps". Snippet data `logo` wins. |
| `language` | site language | `en` or `de`. Otherwise the default language of a multi-language site, then the first two letters of Kirby's `locale` option, then English. |

Changing `placeId`, `language`, `cacheHours` or a selection rule makes the cached reviews unfit: the next page view fetches anew. With `'debug' => true` the PHP error log says why nothing is shown (no place id, no key). A failed fetch is always logged.

The strings of the default snippet are also registered as Kirby translations (`t('koe.readAll')`), and `koe_option('name', $default)` reads an option from a site snippet.

### Notice on the selection

Google's Places policies ask for a clear notice on how reviews are filtered and ordered. The default snippet prints one below the single reviews (`<p class="koe-notice">`), built from the options actually in force, so it cannot go out of date when the configuration changes. With the defaults it reads (German sites get the German text):

> Selection: 5 stars, 80+ characters, at most 24 months old, newest first.

German: „Auswahl: 5 Sterne, ab 80 Zeichen, höchstens 24 Monate alt, neueste zuerst.“ It names `minRating` (not when it is `1`, which filters nothing), `minLength` (not when `0`), `maxAgeMonths`, an `approved` list ("approved by the business") and the order (newest first). `maxCount` is left out: visitors see how many reviews are shown. There is no notice when no single review is shown. It cannot be switched off; for other wording use your own snippet. The text is also in `$r['notice']` (see below). The strings are the Kirby translations `koe.notice.*`.

### Your own markup

Create `site/snippets/koe/reviews.php` (Kirby takes it before the package's snippet), or any snippet that calls the site method:

```php
$r = site()->koeReviews();   // null = show nothing
// ['rating' => 4.8, 'count' => 201, 'placeId' => 'ChIJ…', 'limit' => 3,
//  'profileUrl' => 'https://www.google.com/maps/place/?q=place_id:…'|null,
//  'notice' => 'Selection: 5 stars, …',
//  'reviews' => [['id' => 'ChdD…'|null, 'name' => 'Anna B.', 'rating' => 5,
//                 'text' => '…', 'date' => '2026-07-14', 'month' => 'July 2026',
//                 'url' => 'https://www.google.com/maps/reviews/…'|null,
//                 'avatar' => 'https://your.site/koe/avatar/…'|null], …]]
```

Keep the obligations: print the author with the picture (`avatar`, an address on your own site; `null` when Google sent none or it could not be fetched), link both to `url` when it is set (it is `null` unless it is an `http(s)` address), show the attribution, print `$r['notice']` where single reviews are listed, and fill empty slots with a link to all reviews (`count($r['reviews']) < $r['limit']`). `$site->koeProfileUrl()` returns the profile address from `koe.placeId`.

## Cache

Koe keeps nothing from Google but this cache: Kirby's plugin cache `sayamaapps.koe`, by default files under `site/cache/<host>/sayamaapps/koe/`. `site/cache/` belongs in `.gitignore`, must not be shipped or deleted by the deploy, and should be left out of backups.

- **`reviews`**: place id, rating, count, and only the reviews the rules let through (at most `maxCount`), each with name, date, stars, text, the link to the review and the key of its profile picture. Reviews that are not shown are not kept.
- **`avatar-<key>`**: one profile picture each, fetched by the server without the API key, kept only when it is a small JPEG, PNG, GIF or WebP, served at `koe/avatar/<key>` with the remaining holding time as `max-age`. Google's picture address is not kept.
- **`retry`**: set while a fetch runs and for 15 minutes after a failed one, so neither a crowd of visitors nor a revoked key sends a request on every page view.

Every fetch empties the whole cache first, so nothing of an earlier answer survives it; a failed fetch leaves only `retry`. Everything expires after `koe.cacheHours`. Kirby deletes an expired entry when it is next read, which is the next page view that shows the section. The page without reviews (first view after expiry) is excluded from Kirby's page cache, and a page with reviews is kept there no longer than the reviews.

The fetch runs after the response of the view that found the cache empty: under PHP-FPM (`fastcgi_finish_request()`) and LiteSpeed (`litespeed_finish_request()`) that visitor does not wait for it; on other setups (mod_php, PHP's built-in server) that one visitor waits for the fetch, about a second.

Another cache driver works too, for example `'sayamaapps.koe.cache' => ['type' => 'apcu']` (memory only).

## Upgrading from 1.x

2.0 no longer reads or writes a data file. Per site:

1. Set `koe.placeId` (the `placeId` in the old `site/data/reviews.json`, or the `Found:` line of `koe-fetch`).
2. Give the web server the key (`koe.key` from a deploy-written file outside git, or `GOOGLE_PLACES_API_KEY`), and raise the daily quota cap if it was set for one nightly run (see [Google setup](#google-setup)).
3. Make sure `site/cache/` is ignored by git, kept by the deploy and left out of backups.
4. Remove the nightly workflow and the data file (`git rm site/data/reviews.json`). Earlier versions of the file stay in the repository history; removing them there is a history rewrite.
5. Own snippets: link the name with `url` and print `avatar` (see [Your own markup](#your-own-markup)). `koe.file` and `koe.maxAgeDays` are gone and ignored.

After the deploy the first page view shows no reviews and fetches them; the next ones show them.

Sites still on the pre-1.0 `bewertungen` copy: rename options, methods and snippet as in the [1.0.0 table](CHANGELOG.md#100--2026-10-09); the old data file is not read anymore.

## Privacy

See [PRIVACY.md](PRIVACY.md) for what is fetched, kept and shown, and [DATENSCHUTZ.md](DATENSCHUTZ.md) for the German technical text block for privacy policies. They describe the implementation and are not legal advice.

## Development

```sh
composer install --ignore-platform-req=ext-gd   # Kirby is a dev dependency used as a test fixture
composer test                                   # or: sh tests/run.sh
```

The tests are plain PHP scripts (exit code 0 = pass). The fetch is tested against recorded Places API answers in `tests/fixtures/`; no test needs a network or a key.

## Maintenance

This module is open source under the MIT license and is maintained for the author's own projects. Issues and pull requests are welcome, but there is no support promise and no release schedule. Report security problems privately through GitHub's private vulnerability reporting (the "Report a vulnerability" button on the Security tab).

## License

MIT, see [LICENSE](LICENSE).
