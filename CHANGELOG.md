# Changelog

All notable changes to this project are documented here. Versions follow
[Semantic Versioning](https://semver.org) with these project rules:

- **A change to the default selection rules** (which reviews are shown
  without configuration) is a **major** version: a site would show other
  voices after a routine update.
- **A change to the data file format** increases `format` and ships with a
  reader for the old format, so a site never shows nothing between the update
  and the next nightly fetch.
- A change to the privacy text (`DATENSCHUTZ.md` / `PRIVACY.md`) is marked
  **[privacy text]**. Every site using the plugin must then review its privacy
  policy.

## [Unreleased]

### Fixed

- **An answer without review texts is the current state.** 1.0.2 kept the old
  data file when Google returned no usable review; withdrawn or removed
  reviews, the old rating and the old count then stayed on the page, and the
  fetch still exited with 0. `koe-fetch` now replaces the file with what Google
  returns, an empty review list included. The page shows rating and count
  without single reviews (`koeReviews()` is no longer `null` for an empty
  list; only a missing rating, a missing file or an unreadable one still show
  nothing).
- A failed fetch (quota, revoked key, outage) left the file in place without
  limit. The page now hides a file that is too old, see below.

### Added

- **`checkedAt`** in the data file: the day of the last *successful* fetch,
  set on every run, also when the content is unchanged. `fetchedAt` stays the
  day the content last changed. Format stays 2; the field is optional and
  older readers ignore it.
- **`koe.maxAgeDays`** (default `7`, at least `1`): when `checkedAt` is older,
  the page shows nothing and, in Kirby's debug mode, the PHP error log names
  the dates. Files from before `checkedAt` fall back to `fetchedAt`; a file
  without any valid date is treated as too old.

### Changed

- **The file is written on every new day.** With unchanged content only the
  `checkedAt` line changes, so a workflow that commits and deploys "when the
  file changed" now commits and deploys nightly (one line). That is intended: the
  deployed file has to carry the day of the last successful fetch, otherwise
  the page would hide itself after `koe.maxAgeDays`. A second run on the same
  day still leaves the file alone.
- **Upgrade order.** A site whose content has not changed for more than seven
  days has a `fetchedAt` older than the new limit and no `checkedAt`: with the
  new plugin and the old file the section disappears until the next fetch has
  been committed and deployed. Run `koe-fetch` with the new version and deploy
  the file first (or in the same deploy), or raise `koe.maxAgeDays` for the
  update.
- **[privacy text]** `PRIVACY.md` / `DATENSCHUTZ.md`: an empty answer
  replaces the stored copy, and a stored copy is shown for at most
  `koe.maxAgeDays` after the last successful fetch.

## [1.0.2] — 2026-10-10

### Fixed

- A nightly fetch that got no usable review from Google (every review without
  text, or none at all) overwrote the data file with an empty list. The page
  then hid the rating and the count until the next run. When the file still
  holds reviews, `koe-fetch` now keeps it, prints one line on STDERR and exits
  with 0 (the workflow sees no change and does not commit). An empty or
  missing file is written as before.

### Changed

- A data file whose `format` is neither 1 nor 2 is refused: the reader logs
  `koe: unknown data file format …` and shows nothing, like a missing file.
  Before, such a file was read as format 2 and could show reviews the reader
  does not understand. A file without `format` is detected by its keys as
  before.

## [1.0.1] — 2026-10-10

### Fixed

- `koe-fetch` failed with `Places API answered 404` on the details call when
  PHP's ext-curl was used (the first nightly run on a real site).
  The transport set `CURLOPT_POSTFIELDS` to an empty string for GET requests;
  libcurl treats any `POSTFIELDS` as "this is a POST", and
  `POST places/{id}` does not exist. GET requests now never touch the POST
  options. The stream-wrapper fallback was unaffected.

## [1.0.0] — 2026-10-09

First release as a Composer package. Before 1.0.0 the code lived as a copy
named `bewertungen` in each site (`site/plugins/bewertungen/`,
`scripts/bewertungen-holen.py`, a nightly workflow per site), with German
names throughout. The changes below are measured against that copy.

### Changed — everything renamed to English (breaking)

Code, options, site methods, the snippet, environment variables and the data
file are English now. There are no fallbacks for the old names: a site
switching from the copy updates its config, templates and workflow once,
using the table below.

| Area | Before (`bewertungen` copy) | Now (Koe) |
|---|---|---|
| Plugin id | `bewertungen/google` | `sayamaapps/koe`, installed to `site/plugins/koe/` |
| Options | `bewertungen.*` | `koe.*` |
| | `aktiv`, `datei`, `placeId` | `active`, `file`, `placeId` |
| | `placeId` fell back to the site option `googlePlaceId` | no fallback; `koe.placeId`, otherwise the place id the fetch records in the file |
| Selection rules | constants `STERNE` 5, `MIN_ZEICHEN` 80, `MAX_ALTER_MONATE` 24, `MAX_STIMMEN` 3, `KUERZEN_AUF` 280 | options `minRating`, `minLength`, `maxAgeMonths`, `maxCount`, `truncate` with the same defaults |
| | — | `approved` (new): list of review ids the business cleared |
| | — | `title`, `logo` (new): defaults for the snippet; `language` |
| Site methods | `$site->googleStimmen()`, `$site->googleStimmenProfil()` | `$site->koeReviews()`, `$site->koeProfileUrl()` |
| Result of the site method | `gesamt`, `anzahl`, `stimmen[]` with `name`, `sterne`, `text`, `datum`, `monat`, `link` | `rating`, `count`, `placeId`, `limit`, `profileUrl`, `reviews[]` with `id`, `name`, `rating`, `text`, `date`, `month`, `url` |
| Default snippet | `snippet('bewertungen')`, data `titel`, `logo` | `snippet('koe/reviews')`, data `title`, `logo` |
| Own markup | `site/snippets/bewertungen.php` | `site/snippets/koe/reviews.php` (or any snippet calling `koeReviews()`) |
| CSS classes of the default snippet | `bewertungen`, `bewertungen-wertung`, `-logo`, `-liste`, `-stimme`, `-sterne`, `-autor`, `-mehr`; ids `bewertungen`, `bewertungen-titel` | `koe`, `koe-rating`, `koe-logo`, `koe-list`, `koe-review`, `koe-stars`, `koe-author`, `koe-more`; ids `koe`, `koe-title` |
| Snippet text | German only | English by default, German via `koe.language`, the site's default language or Kirby's `locale` (same wording as before) |
| Classes | `Bewertungen\Stimmen` | `Koe\Reviews`, `Koe\Options`, `Koe\I18n`, `Koe\Places`, `Koe\Fetch`, `Koe\PlacesException` |
| Helpers | — | `koe_option()` |
| Fetch | `python3 scripts/bewertungen-holen.py` (per site) | `vendor/bin/koe-fetch` (PHP CLI, ships with the package) |
| Fetch environment | `BEWERTUNGEN_SUCHE`, `BEWERTUNGEN_DATEI` | `KOE_QUERY`, `KOE_FILE`, `KOE_LANGUAGE`; `GOOGLE_PLACES_API_KEY` unchanged |
| Fetch language | fixed `de` | `KOE_LANGUAGE`, default `en` — German sites set `de` in the workflow |
| Fetch exit codes | 0 done, 1 error, 3 no key | unchanged |
| Fetch output | `Gefunden: Name (ChIJ…)` | `Found: Name (ChIJ…)`, then every review with its id, then `Written:` or `Unchanged` |
| "Unchanged" check | in the workflow (Python heredoc comparing without `stand`) | in the CLI: the file is written only when something other than the fetch date changed; the workflow tests with `git diff` |
| Data file | `site/daten/bewertungen.json`, format 1 (`stand`, `hinweis`, `gesamtwertung`, `anzahl`, `bewertungen[]` with `name`, `datum`, `sterne`, `text`, `quelle`, `link`, `autorLink`) | `site/data/reviews.json`, format 2 (`format`, `fetchedAt`, `note`, `placeId`, `rating`, `count`, `reviews[]` with `id`, `name`, `date`, `rating`, `text`, `source`, `url`, `authorUrl`) |

### Added

- Package `sayamaapps/kirby-koe`, Kirby plugin id `sayamaapps/koe`, PHP
  namespace `Koe\`.
- **`bin/koe-fetch`**, the nightly fetch as a PHP CLI linked to
  `vendor/bin/koe-fetch`. Same language as the plugin, so the Places answer,
  the file format and the selection rules are one code base tested by one
  suite; no Python on the runner. Arguments `--query`, `--file`,
  `--language`, `--help`.
- **Review ids** (`id`, the last segment of Google's review resource name) in
  the data file and in the fetch log, and the option **`koe.approved`** to
  show only reviews the business cleared.
- **Place id in the data file**, so the profile link works without
  configuration.
- **Selection rules as options** with the previous constants as defaults.
- **English and German** snippet text, also registered as Kirby translations
  (`koe.*`).
- Quota and API errors are reported with Google's status and message, the
  key scrubbed; a 429 adds a hint that the project's daily cap is reached.
  The file is left as it is on every error.
- CI on PHP 8.2–8.4.

### Unchanged (data continuity)

- **No review data is lost.** The plugin reads the format 1 file written by
  the copy, at its old place `site/daten/bewertungen.json`, as long as
  `site/data/reviews.json` does not exist (the fallback is logged). The first
  fetch after the switch writes the new file; the switch commit should
  `git mv` the old file to the new path so there is only one.
- The selection itself (five stars, 80 characters, 24 months, three newest,
  cut at 280) is unchanged by default.
- The exit codes of the fetch, the secret name `GOOGLE_PLACES_API_KEY`, the
  nightly full replacement (no archive) and the Places obligations in the
  default snippet (author, link, attribution, tile) are unchanged.
- If `site/plugins/bewertungen/` still exists next to the package, both load
  without conflict; the package logs a reminder to delete the copy.

### [privacy text]

- `DATENSCHUTZ.md` / `PRIVACY.md` are new: what is fetched, stored and shown,
  how long, and what the visitor's browser does (nothing towards Google).
