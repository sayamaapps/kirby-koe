<?php

use Sayamaapps\Koe\Site;

// Site methods. Each closure only delegates; the logic lives in Site.
return [
	/**
	 * Overall rating, count and the selected reviews (possibly none), or
	 * null (= show nothing: switched off, no place id, no key,
	 * nothing cached yet — then the reviews are fetched after this
	 * response — or a place without rating; the debug log says why).
	 *
	 *   ['rating' => 4.8, 'count' => 201, 'placeId' => 'ChIJ…',
	 *    'reviews' => [['id', 'name', 'rating', 'text', 'date', 'month', 'url', 'avatar'], …],
	 *    'limit' => 3, 'profileUrl' => 'https://…'|null, 'notice' => '…', 'authenticityNotice' => '…', 'demo' => false]
	 *
	 * `limit` is the configured maximum: a snippet fills the remaining
	 * slots with a "read all on Google" tile when count(reviews) < limit.
	 * `notice` tells visitors how the reviews were selected and sorted,
	 * worded from the options in force (plain text, in the snippet's
	 * language); a snippet that lists single reviews prints it.
	 * `authenticityNotice` says whether the business checks that the
	 * reviewers really used its services (section 5b (3) UWG): by default
	 * that the reviews come from Google and are not checked, or the text of
	 * the `authenticityNotice` option; a snippet prints it, also when no
	 * single review is listed. In demo mode it says the samples are invented.
	 * `demo` is true for the sample reviews of the `demo` option: then
	 * there is no profile link, and a snippet must not show Google's name
	 * or logo but say that these are samples.
	 */
	'koeReviews' => function (): array|null {
		return Site::reviews($this->kirby(), $this->koeProfileUrl());
	},

	/**
	 * Address of the Google profile from the `placeId` option, or null (then no
	 * profile link and no tile).
	 */
	'koeProfileUrl' => function (): string|null {
		return Site::profileUrl($this->kirby());
	},
];
