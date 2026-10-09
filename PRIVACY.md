# Koe: privacy notes

This is an English summary of [DATENSCHUTZ.md](DATENSCHUTZ.md), the German text
block for privacy policies, which is the authoritative version. Both describe
the implementation. They are not legal advice. Each operator has to determine
the legal basis and the wording for their own site.

## What the visitor's browser does

Nothing towards Google. The page shows a copy of the reviews that was fetched
on the server. No script, embed, font or image is loaded from Google; the
"Google Maps" logo, if shown, is served from the site's own server. Only when
a visitor clicks the author name, the profile link or the "read all" link does
the browser open Google Maps — a normal outbound link.

The plugin sets no cookies, uses no browser storage and records nothing about
the visitor.

## What is fetched from Google, and when

Once a night (or when started by hand), the fetch sends two requests to the
Places API (New) from a CI runner or a server, authenticated with the site's
own API key: a text search for the business, then the details of the found
place. The request carries the business name and address, never visitor data.

Received and stored: the place id, the overall rating, the number of reviews,
and up to five reviews (the API's maximum) with author display name, author
profile URL, link to the review on Google Maps, publication date, star rating,
review text and Google's review id. Reviews without text are not stored.

## Where it is stored, and for how long

In one JSON file inside the site (`site/data/reviews.json` by default): in the
site's repository, where the nightly run commits it, and on the web server,
where the deploy puts it. The file is outside the web root and not reachable
through the web.

Every run **replaces the file completely** with the current answer. There is
no archive: a review Google no longer returns — withdrawn by its author,
removed by Google, pushed out by newer ones — disappears from the file and
the page with the next run, usually within a day. This follows Google's
requirement not to cache Places content for longer than about 24 hours. The
repository history keeps earlier versions of the file; operators who want
withdrawn reviews gone from history as well must handle that in git.

## What is shown

Rating and count always. Of the single reviews only those that pass the
configured rules (by default five stars, at least 80 characters, not older
than 24 months, the three newest, cut at a word boundary after 280 characters
with an ellipsis) and, if an approved list is configured, only those ids. The
author is named and the name links to the review on Google Maps; without a
safe (`http(s)`) link the name is plain text. The "Google Maps" attribution
is always shown.

The review authors are Google users whose reviews are already public on
Google Maps. The page republishes name, date, rating and (possibly shortened)
text of selected reviews. The nightly replacement means a review that is
withdrawn on Google leaves the site within about a day.

## The API key

Lives only where the fetch runs (a repository secret or a server
environment), never in the Kirby configuration and never on the web server.
It is never printed; error messages from Google are scrubbed of it. The
recommendation is one Google Cloud project per client with the key restricted
to the Places API (New) and a capped daily quota.
