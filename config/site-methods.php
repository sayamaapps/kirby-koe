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
	 *    'limit' => 3, 'profileUrl' => 'https://…'|null, 'notice' => '…', 'demo' => false]
	 *
	 * `limit` is the configured maximum: a snippet fills the remaining
	 * slots with a "read all on Google" tile when count(reviews) < limit.
	 * `notice` tells visitors how the reviews were selected and sorted,
	 * worded from the options in force (plain text, in the snippet's
	 * language); a snippet that lists single reviews prints it.
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
