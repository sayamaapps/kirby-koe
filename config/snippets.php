<?php

// A plain default. A site with its own markup creates
// site/snippets/koe/reviews.php (Kirby looks there first), or any snippet of
// its own that calls $site->koeReviews().
return [
	'koe/reviews' => __DIR__ . '/../snippets/reviews.php',
];
