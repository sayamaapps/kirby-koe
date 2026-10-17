# Koe

Google reviews for [Kirby CMS](https://getkirby.com) 5, served from your own server.

*Koe* (声) is Japanese for voice. The plugin shows what customers say about a business on Google: the overall rating, the number of reviews and a few selected voices. The visitor's browser never talks to Google.

- **Server-side copy.** A small CLI (`koe-fetch`) reads the place once a night through the Places API (New) and writes one JSON file into the site. The page renders from that file.
- **No request to Google from the visitor.** No script, no embed, no font, no image from Google. The "Google Maps" logo, if shown, is served from your own site. Links to the reviews open on Google only when clicked.
- **Rule-based selection.** By default only five-star reviews with at least 80 characters, not older than 24 months, the three newest, cut at 280 characters. Optionally a list of approved review ids, so the business decides what is shown. The default snippet tells visitors how the reviews shown were selected and sorted (see [Notice on the selection](#notice-on-the-selection)).
- **Follows the Places policies.** Author named and linked to the review on Google Maps, "Google Maps" attribution, nothing cached for longer than about a day: every run replaces the file completely, there is no archive.
- **The API key never reaches the website.** It lives only where the fetch runs (a repository secret, a cron environment).
- **English and German.** The default snippet follows the site's language.
- **Runs anywhere.** No database, no extension beyond `mbstring` (and `curl` for the fetch, with a stream fallback).

## Requirements

- PHP 8.2–8.4 with `mbstring`; `curl` recommended for the fetch
- Kirby 5
- A Google Cloud project with the **Places API (New)** enabled (see [Google setup](#google-setup))

## Installation

```sh
composer require sayamaapps/kirby-koe
```

Composer installs the plugin into `site/plugins/koe/` (via `getkirby/composer-installer`, which ships with `getkirby/cms`) and links the CLI as `vendor/bin/koe-fetch`. Add `/site/plugins/koe/` to `.gitignore` and make sure your deploy ships it, just as it ships `kirby/` and `vendor/`.

Until the package is on Packagist, add the repository first:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/simsimmal/kirby-koe" }]
```

Manual installation (download into `site/plugins/koe/`) also works; the CLI is then `php site/plugins/koe/bin/koe-fetch`.

## Google setup

1. **One Google Cloud project per client**, not one project for all sites. Each project has its own key, its own quota and its own billing line, so a leaked or revoked key, a quota cap or a cost question only ever concerns one business. The project can live in the client's Google account or in yours — decide that before creating it.
2. Enable **Places API (New)** in that project (not the legacy Places API).
3. Create an **API key** and restrict it: API restriction = Places API (New) only. No application restriction is needed; the key is used from a CI runner or a server, not from a browser.
4. **Cap the quota.** In *APIs & Services → Places API (New) → Quotas*, set a daily limit of a handful of requests. One run needs two (text search + details), so 10 per day leaves room for a manual run. A capped project can never produce a surprising bill, even if the key leaks.
5. Store the key where the fetch runs — as the GitHub repository secret **`GOOGLE_PLACES_API_KEY`** for the workflow below. Never put it into `site/config/config.php`: the website does not need it.

Google's terms for showing Places content on your own site: name the author, let readers reach the original review (the author name links to it), show the "Google Maps" attribution (logo or text), and do not keep the content for longer than about 24 hours — hence the nightly full replacement.

## Nightly fetch

```sh
GOOGLE_PLACES_API_KEY=… KOE_QUERY="Business name, Street 1, 12345 Town" vendor/bin/koe-fetch
```

Run it from the project root. Settings come from the environment or from arguments:

| Variable | Argument | Meaning |
|---|---|---|
| `GOOGLE_PLACES_API_KEY` | — | Required. Missing: exit code 3 and a message (a workflow treats that as "skipped"). |
| `KOE_QUERY` | `--query=` | Name and address **as in the Google profile**, not as on the website. A text search instead of a fixed place id survives the listing being recreated; if the profile changes, change the query (with `KOE_PLACE_ID` set, a recreated listing stops the fetch until you set its new id). Missing: exit code 1 before any request. |
| `KOE_FILE` | `--file=` | Target, relative to the current directory. Default `site/data/reviews.json`. Must match `koe.file`. |
| `KOE_LANGUAGE` | `--language=` | Language code for the API (`de`, `en`, …), default `en`. Affects translated texts and the display name; the review text itself is always the original. |
| `KOE_PLACE_ID` | `--place-id=` | The expected place, the same value as `koe.placeId`. Recommended. If the search or the details lead to another place, the fetch exits with `1`, says which ids differ and writes nothing. Without it the first hit of the text search is taken, whatever it is. Take the id from the `Found:` line of a first run. When the business really gets a new Google profile, change it here and in `koe.placeId`. |

Exit codes: `0` done, `1` error (no query, nothing found, other place than expected, API or write failure), `3` no key. The log names the place (`Found: Name (ChIJ…)` — the place id for `koe.placeId`), lists every review with its id (for `koe.approved`), and says whether the file was written. The key is never printed; Google's error messages are scrubbed of it.

**What Google answers is the current state.** Every successful run replaces the file, also when the answer has no usable review (then the page shows the new rating and count without single reviews). The file records the day of the last successful run as `checkedAt`; when nothing else changed, that is the only line that differs, and a second run on the same day leaves the file alone. A workflow that commits and deploys when the file changed therefore does so every night, which is intended: the deployed file must say when it was last checked (see `maxAgeDays`). A failed run (quota, revoked key, outage) exits with `1` and leaves the file as it is; the page stops showing it once `checkedAt` is older than `koe.maxAgeDays`.

The Places API returns at most five reviews per place. Reviews without text are skipped (they are counted in Google's total anyway).

### Workflow template (GitHub Actions)

The workflow belongs to the site, because the deploy it triggers differs per site. A push with `GITHUB_TOKEN` deliberately starts no push workflows, so the deploy is dispatched explicitly.

```yaml
name: Update reviews

on:
  workflow_dispatch:
  schedule:
    - cron: '17 3 * * *'   # 03:17 UTC, off the full hour

permissions:
  contents: write
  actions: write   # to dispatch the deploy

concurrency: koe

env:
  KOE_FILE: site/data/reviews.json
  KOE_QUERY: "Business name, Street 1, 12345 Town"   # as in the Google profile
  KOE_PLACE_ID: "ChIJ…"                               # the expected place, same as koe.placeId
  KOE_LANGUAGE: de

jobs:
  fetch:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4

      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'

      - name: Install the package (it carries the fetch)
        run: composer install --no-dev --no-interaction --no-progress

      - name: Fetch reviews
        env:
          GOOGLE_PLACES_API_KEY: ${{ secrets.GOOGLE_PLACES_API_KEY }}
        run: |
          set +e
          vendor/bin/koe-fetch
          status=$?
          set -e
          if [ "$status" = "3" ]; then
            echo "::notice::No GOOGLE_PLACES_API_KEY stored — reviews stay as they are."
            exit 0
          fi
          exit "$status"

      - name: Commit and deploy when changed
        env:
          GH_TOKEN: ${{ github.token }}
        run: |
          if git diff --quiet -- "$KOE_FILE" && [ -z "$(git ls-files --others --exclude-standard -- "$KOE_FILE")" ]; then
            echo "Unchanged — nothing to commit, no deploy."
            exit 0
          fi
          git config user.name "koe-fetch"
          git config user.email "actions@users.noreply.github.com"
          git add "$KOE_FILE"
          git commit -m "Reviews: nightly update from Google"
          git push
          gh workflow run deploy-live.yml --ref main   # ← the site's deploy workflow
```

Run it once by hand after setting the secret; the log shows `Found: … (ChIJ…)`.

## Configuration

Everything is off by default. Minimal setup in `site/config/config.php`:

```php
return [
    'koe.active'  => true,
    'koe.placeId' => 'ChIJ…',   // from the fetch log; the place the data must be about, see below
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
| `active` | `false` | Master switch. Off: `$site->koeReviews()` is `null` and the snippet prints nothing, even with a data file present. Only `true` switches on. |
| `file` | `site/data/reviews.json` | Data file: absolute, relative to the project root (not the working directory), or a closure. Must match `KOE_FILE` of the fetch. While the default file is missing and the pre-1.0 `site/daten/bewertungen.json` exists, that one is read (and the fallback is logged). |
| `placeId` | from the data file | The expected place, and the id of the profile link and the "read all on Google" tile. The fetch records the place id in the data file. **If the option is set and the file records a different id, the page shows nothing** (in debug mode the log names both ids), so reviews of one business never appear under the profile link of another; pass the same id to the fetch as `KOE_PLACE_ID`, which then refuses to write another place's data at all. A file that records no place id (pre-1.0 format) cannot be checked and is shown. Without the option the id from the file is used. Without any valid id the profile link and the tile are omitted. |
| `maxAgeDays` | `7` | How old the last successful fetch (`checkedAt` in the data file) may be, in days, at least `1`. Older: the page shows nothing and, in Kirby's debug mode (`'debug' => true`), the PHP error log says why. Files from before `checkedAt` count by `fetchedAt`; a file without any valid date counts as too old. Not a selection rule: it is about the age of the file, not of single reviews. |
| `minRating` | `5` | Minimum stars. |
| `minLength` | `80` | Minimum text length in characters (after trim). |
| `maxAgeMonths` | `24` | Maximum age, counted from today (Europe/Berlin). |
| `maxCount` | `3` | Maximum number of single reviews shown; also the `limit` the snippet fills with the tile. |
| `truncate` | `280` | Longer texts are cut at a word boundary with "…". `0` = never. |
| `approved` | `null` | `null`: rules only. A list of review ids (from the fetch log or the file): only those, still subject to the rules above. `[]`: no single review, only rating, count and the tile. Reviews from a pre-1.0 file have no ids and can never be approved. |
| `title` | translated | Heading of the default snippet (`Reviews on Google` / `Bewertungen auf Google`). Snippet data `title` wins. |
| `logo` | `null` | Path under `public/` to the official Google Maps logo, e.g. `assets/GoogleMaps_Logo_Gray.svg`. Without it the attribution is the text "Google Maps". Snippet data `logo` wins. |
| `language` | site language | `en` or `de`. Otherwise the default language of a multi-language site, then the first two letters of Kirby's `locale` option, then English. |

The strings of the default snippet are also registered as Kirby translations (`t('koe.readAll')`), and `koe_option('name', $default)` reads an option from a site snippet.

### Notice on the selection

Google's Places policies ask for a clear notice on how reviews are filtered and ordered. The default snippet prints one below the single reviews (`<p class="koe-notice">`), built from the options actually in force, so it cannot go out of date when the configuration changes. With the defaults it reads (German sites get the German text):

> Selection of the reviews shown: 5 stars, at least 80 characters of text, not older than 24 months. Newest first, at most 3. The rating and the number of reviews refer to all reviews on Google.

It names `minRating` (not when it is `1`, which filters nothing), `minLength` (not when `0`), `maxAgeMonths`, an `approved` list ("approved by the business"), the order (newest first) and `maxCount`. Rating and count are Google's totals, which the last sentence says. There is no notice when no single review is shown. It cannot be switched off; for other wording use your own snippet. The text is also in `$r['notice']` (see below). The strings are the Kirby translations `koe.notice.*`.

### Your own markup

Create `site/snippets/koe/reviews.php` (Kirby takes it before the package's snippet), or any snippet that calls the site method:

```php
$r = site()->koeReviews();   // null = show nothing
// ['rating' => 4.8, 'count' => 201, 'placeId' => 'ChIJ…'|null, 'limit' => 3,
//  'profileUrl' => 'https://www.google.com/maps/place/?q=place_id:…'|null,
//  'notice' => 'Selection of the reviews shown: 5 stars, …',
//  'reviews' => [['id' => 'ChdD…'|null, 'name' => 'Anna B.', 'rating' => 5,
//                 'text' => '…', 'date' => '2026-07-14', 'month' => 'July 2026',
//                 'url' => 'https://www.google.com/maps/reviews/…'|null], …]]
```

Keep the obligations: print the author, link the name to `url` when it is set (it is `null` unless it is an `http(s)` address), show the attribution, print `$r['notice']` where single reviews are listed, and fill empty slots with a link to all reviews (`count($r['reviews']) < $r['limit']`). `$site->koeProfileUrl()` returns the profile address from `koe.placeId` alone. `koeReviews()` is `null` when `koe.placeId` names another place than the data file.

## Data file

`site/data/reviews.json`, written by the fetch, read by the plugin. Under `site/` on purpose: it travels with the code deploy, is not reachable through the web (document root is `public/`), and is not affected when `content/` is excluded from deploys after handing the site over to the client.

```json
{
  "format": 2,
  "fetchedAt": "2026-10-09",
  "checkedAt": "2026-10-16",
  "note": "Replaced completely by koe-fetch on every run …",
  "placeId": "ChIJ…",
  "rating": 4.8,
  "count": 201,
  "reviews": [
    { "id": "ChdD…", "name": "Anna B.", "date": "2026-07-14", "rating": 5, "text": "…",
      "source": "google", "url": "https://www.google.com/maps/reviews/…", "authorUrl": "https://www.google.com/maps/contrib/…" }
  ]
}
```

`fetchedAt` is the day the content last changed, `checkedAt` the day of the last successful fetch (see `maxAgeDays`). Files written before `checkedAt` existed have only `fetchedAt` and are aged by that. `reviews` may be empty.

The pre-1.0 file (`format` 1, German keys, written by `scripts/bewertungen-holen.py`) is read as well; see [Upgrading](#upgrading-from-the-bewertungen-copy).

## Upgrading from the `bewertungen` copy

Before 1.0.0 the plugin lived as a copy named `bewertungen` in each site (`site/plugins/bewertungen/`, `scripts/bewertungen-holen.py`), with German names throughout. Options, site methods, snippet, CSS classes, environment variables and the data file were renamed; the [CHANGELOG](CHANGELOG.md#100--unreleased) has the full old → new table.

No review data is lost: the plugin reads the old file at its old place (`site/daten/bewertungen.json`) until `site/data/reviews.json` exists. The first fetch after the switch writes the new file in the new format. Move the old file with `git mv site/daten/bewertungen.json site/data/reviews.json` in the switch commit so there is only one, and delete `site/plugins/bewertungen/` — while it still exists, the package logs a reminder.

## Upgrading to a version with `checkedAt` and the place check

Run `koe-fetch` with the new version and get the resulting file deployed before (or in the same deploy as) the new plugin. A site whose reviews have not changed for more than `koe.maxAgeDays` days has a `fetchedAt` that is already too old and no `checkedAt`; with the new plugin and the old file the section stays hidden until the next fetch is committed and deployed. Alternatively set `koe.maxAgeDays` high for the update and remove it afterwards.

If `koe.placeId` is set, it must now equal the `placeId` in the data file (the `Found:` line of the fetch shows it); otherwise the section is hidden. Set `KOE_PLACE_ID` to the same value in the workflow.

## Privacy

See [PRIVACY.md](PRIVACY.md) for what is fetched, stored and shown, and [DATENSCHUTZ.md](DATENSCHUTZ.md) for the German technical text block for privacy policies. They describe the implementation and are not legal advice.

## Development

```sh
composer install --ignore-platform-req=ext-gd   # Kirby is a dev dependency used as a test fixture
composer test                                   # or: sh tests/run.sh
```

The tests are plain PHP scripts (exit code 0 = pass). The fetch is tested against recorded Places API answers in `tests/fixtures/`; no test needs a network or a key.

## License

MIT, see [LICENSE](LICENSE).
