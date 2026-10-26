<?php

/**
 * English strings of the default snippet — the default and the fallback for
 * missing keys. `{name}` placeholders are filled in by Koe\I18n::t().
 * tests/I18nTest.php checks that every language file has the same keys and
 * placeholders as this one.
 */
return [
	'month.1' => 'January', 'month.2' => 'February', 'month.3' => 'March', 'month.4' => 'April',
	'month.5' => 'May', 'month.6' => 'June', 'month.7' => 'July', 'month.8' => 'August',
	'month.9' => 'September', 'month.10' => 'October', 'month.11' => 'November', 'month.12' => 'December',

	'title' => 'Reviews on Google',
	'ratingOutOf' => '{rating} out of 5',
	'countOn' => '{count} reviews on',
	'reviewsCount' => '{count} reviews',
	'starsOutOf' => '{stars} out of 5 stars',
	'viewProfile' => 'View profile',
	'readAll' => 'Read all on Google',
	'opensNewTab' => '(opens in a new tab)',
	'author.anonymous' => 'Google user',

	// The notice on how the reviews shown were selected, built by Reviews::notice() from the rules in force.
	'notice.selection' => 'Selection: {criteria}.',
	'notice.stars.exact' => '{stars} stars',
	'notice.stars.min' => '{stars}+ stars',
	'notice.length' => '{chars}+ characters',
	'notice.length.one' => 'at least 1 character',
	'notice.age' => 'at most {months} months old',
	'notice.age.one' => 'at most 1 month old',
	'notice.approved' => 'approved by the business',
	'notice.order' => 'newest first',
];
