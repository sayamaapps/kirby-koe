# Koe

Google reviews for [Kirby CMS](https://getkirby.com), served from your own server.

**Kirby 5 · PHP 8.2–8.5 · `sayamaapps/kirby-koe` 2.x**

Koe shows a business's Google rating, its review count and a few selected reviews on a Kirby site. The server fetches them from the Places API (New) and keeps them in a short-lived cache, so visitors' browsers never contact Google.

## Features

- Server-side fetch from the Places API (New), held in a short-lived cache (24 hours by default).
- Rule-based selection: by default five-star reviews of at least 80 characters, the three newest, not older than 24 months.
- Optional approved list, so the business decides which reviews appear.
- Google attribution built in: author with profile picture, links to the review, "Google Maps" attribution, a notice on the selection.
- API key kept on the server; profile pictures served from your own site.
- English and German; no database, no cookies.

## Requirements

- PHP 8.2–8.5 with `mbstring`; `curl` recommended. Outgoing HTTPS to `places.googleapis.com` and `*.googleusercontent.com`.
- Kirby 5 with a writable cache directory (`site/cache/` by default).
- A Google Cloud project with **Places API (New)** enabled and an API key restricted to that API. Use one project and key per site, and cap the daily quota: a fetch is one request, so 30 per day is enough at any `koe.cacheHours`.

## Installation

With Composer (recommended):

```sh
composer require sayamaapps/kirby-koe
```

Composer installs to `site/plugins/koe/` and links the CLI as `vendor/bin/koe-fetch`. Add that path to `.gitignore` and deploy it like `vendor/`.

As a ZIP: download the release from GitHub and unzip it to `site/plugins/koe/`. The CLI is then `site/plugins/koe/bin/koe-fetch`.

As a Git submodule: `git submodule add https://github.com/sayamaapps/kirby-koe.git site/plugins/koe`.

Ignore `site/cache/` in git too, and leave it out of backups.

## Usage

**Configure** in `site/config/config.php`. Koe is off by default:

```php
return [
    'koe.active'  => true,
    'koe.placeId' => 'ChIJ…',   // from koe-fetch, see below
];
```

Keep the key out of git: set `koe.key` in a file your deploy writes, or the environment variable `GOOGLE_PLACES_API_KEY` on the web server.

**Show** the reviews in a template:

```php
<?php snippet('koe/reviews') ?>
<?php snippet('koe/reviews', ['title' => 'What our guests say', 'logo' => 'assets/GoogleMaps_Logo_Gray.svg']) ?>
```

The section is omitted while Koe is off, misconfigured or the cache is empty. The first view after the cache expires starts the fetch after its response; later views show the reviews. A failed fetch is logged and retried after 15 minutes at the earliest.

**Finding the place id**

```sh
GOOGLE_PLACES_API_KEY=… KOE_QUERY="Business name, Street 1, 12345 Town" vendor/bin/koe-fetch
```

`koe-fetch` prints the place (`Found: Name (ChIJ…)`, the value for `koe.placeId`) and its reviews with their ids (for `koe.approved`). It writes nothing. Use the name and address exactly as in the Google profile. Options: `--query=` or `KOE_QUERY`; `--language=` or `KOE_LANGUAGE` (default `en`). Exit codes: `0` done, `1` error, `3` no key. Google returns at most five reviews per place; reviews without text are skipped.

**Your own markup.** Create `site/snippets/koe/reviews.php` (Kirby takes it before the package's snippet), or call the site method from any snippet:

```php
$r = site()->koeReviews();   // null: show nothing
// rating, count, limit, profileUrl, notice, reviews[] with name, rating, text, date, month, url, avatar
```

`$site->koeProfileUrl()` returns the profile link. Site snippets read options with `Sayamaapps\Koe\Options::get(kirby(), 'name', $default)`.

**Google attribution.** Google's Places policies require the author with profile picture, both linked to `url`, the "Google Maps" attribution and the selection notice (`$r['notice']`, e.g. "Selection: 5 stars, 80+ characters, at most 24 months old, newest first."). The default snippet does all of it; your own markup must do the same.

## Options

Keys are `koe.<name>`, written flat (`'koe.active' => true`) or grouped (`'koe' => ['active' => true]`). `null` means "not set".

| Option | Default | Description |
|---|---|---|
| `active` | `false` | Master switch. Only `true` turns Koe on; otherwise nothing is shown and nothing is fetched. |
| `placeId` | `null` | Required. The Google place id, from `koe-fetch`. If Google answers for another id, nothing is kept. |
| `key` | `GOOGLE_PLACES_API_KEY` | Places API key, a string or a closure. Without it, the environment variable is read. |
| `cacheHours` | `24` | Whole hours, 1 to 24: how long reviews and pictures stay cached. |
| `minRating` | `5` | Minimum stars, 1 to 5. |
| `minLength` | `80` | Minimum text length in characters; `0` = no limit. |
| `maxAgeMonths` | `24` | Maximum age in months, counted from today (Europe/Berlin); `0` = no limit. |
| `maxCount` | `3` | Maximum number of single reviews shown. |
| `truncate` | `280` | Longer texts are cut at a word boundary and get "…"; `0` = never. |
| `approved` | `null` | `null`: rules only. A list of review ids: only those, still subject to the rules. `[]`: no single reviews. |
| `title` | translated | Heading of the default snippet (`Reviews on Google` / `Bewertungen auf Google`). |
| `logo` | `null` | Path under `public/` to the official Google Maps logo. Without it, the attribution is the text "Google Maps". |
| `language` | site language | `en` or `de`. Otherwise the site's default language, then Kirby's `locale`, then English. |

Changing `placeId`, `language`, `cacheHours` or a selection rule makes the next view fetch anew. Kirby's `debug` option makes the error log say why nothing is shown; failed fetches are always logged. Other cache drivers work too, e.g. `'sayamaapps.koe.cache' => ['type' => 'apcu']`.

## Names of earlier versions

| Name | Now | Notes |
|---|---|---|
| `Koe\*` classes | `Sayamaapps\Koe\*` | Class aliases, loaded with the plugin. |
| `koe_option($key, $default)` | `Sayamaapps\Koe\Options::get(kirby(), $key, $default)` | Deprecated. Keeps working and raises no notice. |
| `Koe\PlacesException` | unchanged | Not an alias: the exception is declared under this name. |

## Planned for the next major version

Option keys `sayamaapps.koe.<name>` will replace `koe.<name>`, which is then no longer read. Translation keys `sayamaapps.koe.<key>` already work and replace `koe.<key>`, which is then removed. The names above will be removed. Sites must rename their option keys with that update.

Tests: `composer test`; all checks: `composer ci` (after `composer install --ignore-platform-req=ext-gd`). Issues and pull requests are welcome, without a support promise. Report security problems privately through GitHub's vulnerability reporting. Changes: [CHANGELOG.md](CHANGELOG.md).

## Implementation notes

The Places client (`Sayamaapps\Koe\Places`, with its own transport: curl, or streams without curl) and the translation table (`Sayamaapps\Koe\I18n`) do not use Kirby's `Remote` and `I18n`. The Places client throws `Koe\PlacesException`, the name it had before, a plain `RuntimeException`, and not one of Kirby's exceptions, because the core also runs without Kirby in the `koe-fetch` CLI. The snippet texts must match what the CLI prints. Everything else follows the Kirby standard: options, translations, site methods, routes and the cache.

## Privacy

- **No request from the visitor's browser.** No script, embed, font or image comes from Google. Profile pictures and the optional "Google Maps" logo are served from your site; Google opens only when a visitor clicks a link.
- **Cache only.** Reviews and pictures stay in Kirby's cache for `koe.cacheHours` at most, then they are fetched anew. Nothing else from Google is kept. The plugin sets no cookies and uses no browser storage.

See [PRIVACY.md](PRIVACY.md) (English) and [docs/DATENSCHUTZ.md](docs/DATENSCHUTZ.md) (German text for privacy policies). They are not legal advice.

## License

MIT, see [LICENSE.md](LICENSE.md).
