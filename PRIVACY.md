# Koe: privacy notes

This is an English summary of [DATENSCHUTZ.md](DATENSCHUTZ.md), the German text
block for privacy policies, which is the authoritative version. Both describe
the implementation. They are not legal advice. Each operator has to determine
the legal basis and the wording for their own site.

## What the visitor's browser does

Nothing towards Google. The page shows reviews that the site's own server
fetched and keeps in a short-lived cache. No script, embed, font or image is
loaded from Google; the reviewers' profile pictures and the "Google Maps"
logo, if shown, are served from the site's own server. Only when a visitor
clicks a reviewer, the profile link or the "read all" link does the browser
open Google Maps — a normal outbound link.

The plugin sets no cookies, uses no browser storage and records nothing about
the visitor.

## What is fetched from Google, and when

When a page shows the reviews and the cache holds none (the first time, and
after the holding time ran out), the web server sends one request to the
Places API (New), authenticated with the site's own API key: the details of
the configured place. The request carries the place id and a language code,
never visitor data. It is sent after the page was delivered to that visitor,
who sees the page without reviews; the visitors after them see the reviews.
The server then fetches the profile pictures of the reviews it keeps, from
Google's image server, without the key and without visitor data.

Received: the place id, the overall rating, the number of reviews, and up to
five reviews (the API's maximum) with author display name, author profile
and picture addresses, link to the review on Google Maps, publication date,
star rating, review text and Google's review id.

## What is kept, where, and for how long

Only in the cache of the web server (Kirby's cache directory, by default
`site/cache/`): the place id, the rating, the number of reviews, and of the
reviews only those that pass the configured rules and are shown (by default
at most three) with display name, date, stars, text, link to the review and
the profile picture itself. Reviews that are not shown, the author profile
addresses and Google's picture addresses are discarded right away. The cache
is not in the site's repository, not in the content, not reachable through
the web except the pictures at `/koe/avatar/<key>` while they are shown, and
should be excluded from backups.

Everything in the cache expires after the holding time (`koe.cacheHours`,
24 hours by default, shorter if configured) and is deleted with the next
page view after that. Every fetch empties the cache completely before it
writes the new answer, also when that answer contains no review text (the
page then shows only rating and count). There is no archive: a review Google
no longer returns — withdrawn by its author, removed by Google, pushed out by
newer ones — is gone from the site with the next fetch, at the latest one
holding time after it was fetched. If a fetch fails (quota, revoked key,
outage), the cache stays empty and the page shows no reviews; the next
attempt follows 15 minutes later at the earliest.

Google's terms name exceptions from their caching restrictions only for the
place id (and coordinates), not for review content. This cache is kept as
short as configured and holds only what is shown.

## What is shown

Rating and count always. Of the single reviews only those that pass the
configured rules (by default five stars, at least 80 characters, not older
than 24 months, the three newest, cut at a word boundary after 280 characters
with an ellipsis) and, if an approved list is configured, only those ids. The
author is named with profile picture; name and picture link to the review on
Google Maps, without a safe (`http(s)`) link they are not linked. The
"Google Maps" attribution and a notice on how the reviews were selected are
always shown with them.

The review authors are Google users whose reviews are already public on
Google Maps. The page republishes name, picture, date, rating and (possibly
shortened) text of selected reviews.

## The API key

Lives on the web server: in a Kirby option (from a configuration file that
is not in the repository) or in an environment variable. It is never sent to
the browser and never printed; error messages from Google are scrubbed of
it. The recommendation is one Google Cloud project per client with the key
restricted to the Places API (New), if possible to the server's IP address,
and a capped daily quota.
