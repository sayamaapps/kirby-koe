<?php

use Kirby\Cms\App;
use Kirby\Template\Snippet;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Koe\Plugin;

/**
 * The plugin with real Kirby, in a throwaway directory: the switch (off by
 * default), place id and key, the fetch on demand (the first view after the
 * cache expired starts it after the response and shows nothing itself), the
 * holding time, the profile picture route, the options for rules, approval,
 * title, logo and language, the default snippet with the Places obligations
 * (author with picture and link only when safe, attribution, notice), and
 * that a site's own site/snippets/koe/reviews.php wins.
 *   vendor/bin/phpunit
 */
#[CoversClass(\Sayamaapps\Koe\Plugin::class)]
final class KirbyTest extends TestCase
{
	private function expect(mixed $expected, mixed $actual, string $what): void
	{
		Assert::assertSame($expected, $actual, $what);
	}

	private function holds(bool $ok, string $what): void
	{
		Assert::assertTrue($ok, $what);
	}

	private static function removeTree(string $path): void
	{
		if (!is_dir($path)) {
			return;
		}
		$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
		foreach ($it as $file) {
			$file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
		}
		rmdir($path);
	}

	public function testSuite(): void
	{
		$base = dirname(__DIR__);
		$tmp = sys_get_temp_dir() . '/koe-kirby-' . bin2hex(random_bytes(6));
		$key = 'AIza-not-a-real-key-0000';
		$long = str_repeat('Friendly, competent and fast, happy to come back. ', 3);
		$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat('j', 100);
		/** One review of a Places details answer. */
		$review = fn (string $name, string $ago, string|null $url = null, int $rating = 5, string|null $id = null, string|null $photo = null) => array_filter([
			'name' => $id === null ? null : 'places/ChIJExample/reviews/' . $id,
			'rating' => $rating,
			'originalText' => ['text' => $long],
			'authorAttribution' => array_filter(['displayName' => $name, 'photoUri' => $photo]),
			'publishTime' => date('Y-m-d', strtotime($ago)) . 'T10:00:00Z',
			'googleMapsUri' => $url,
		], fn ($v) => $v !== null);
		/** What Google answers next: the details for ChIJExample (or a raw ['status', 'body']), plus pictures. Records every call. */
		$answer = ['id' => 'ChIJExample', 'rating' => 4.8, 'userRatingCount' => 201, 'reviews' => []];
		$pictures = [];
		$calls = [];
		Plugin::$transport = function (string $url, array $headers, string|null $body) use (&$answer, &$pictures, &$calls): array {
			$calls[] = ['url' => $url, 'headers' => $headers];
			if (str_starts_with($url, Sayamaapps\Koe\Places::ENDPOINT)) {
				return isset($answer['status']) ? $answer : ['status' => 200, 'body' => json_encode($answer)];
			}
			return $pictures[$url] ?? ['status' => 404, 'body' => ''];
		};
		/** The refresh is queued instead of running after the response. */
		$queue = [];
		Plugin::$defer = function (Closure $job) use (&$queue): void {
			$queue[] = $job;
		};
		$on = ['sayamaapps.koe.active' => true, 'sayamaapps.koe.placeId' => 'ChIJExample', 'sayamaapps.koe.key' => $key];
		/** New Kirby instance with these options; returns [koeReviews(), snippet HTML (entities decoded), Kirby]. */
		$run = function (array $options, array $snippetData = []) use ($tmp): array {
			Snippet::$cache = []; // Kirby remembers snippet paths per process
			$roots = ['index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content', 'site' => $tmp . '/site', 'storage' => $tmp . '/storage'];
			$kirby = new App(['roots' => $roots, 'options' => ['url' => 'http://example.invalid'] + $options]);
			$html = html_entity_decode((string)$kirby->snippet('koe/reviews', $snippetData, true));
			return [$kirby->site()->koeReviews(), $html, $kirby];
		};
		/** Runs the queued refreshes (as after the response). */
		$drain = function () use (&$queue): void {
			while ($job = array_shift($queue)) {
				$job();
			}
		};
		/** Empties the cache and fills it with $answer for these options: the state after a first visitor. */
		$fill = function (array $options) use ($run, $drain, $tmp, &$queue): void {
			$this->removeTree($tmp . '/site/cache');
			$queue = [];
			$run($options);
			$drain();
		};
		// The lock files (`*.lock`, beside each cache directory) hold nothing and are not counted.
		$cacheFiles = fn () => is_dir($tmp . '/site/cache') ? count(array_filter(iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($tmp . '/site/cache', FilesystemIterator::SKIP_DOTS))), fn (SplFileInfo $file) => !str_ends_with($file->getFilename(), '.lock'))) : 0;
		foreach (['public', 'content/home', 'site/plugins', 'site/snippets', 'site/templates', 'storage'] as $dir) {
			mkdir($tmp . '/' . $dir, 0700, true);
		}
		symlink($base, $tmp . '/site/plugins/koe');
		file_put_contents($tmp . '/site/templates/default.php', 'Fixture');
		file_put_contents($tmp . '/content/home/home.txt', 'Title: Home');
		$log = $tmp . '/php-error.log';
		ini_set('error_log', $log);
		$logged = function () use ($log): string {
			$text = is_file($log) ? (string)file_get_contents($log) : '';
			file_put_contents($log, '');
			return $text;
		};

		$answer['reviews'] = [
			$review('With Link', '-10 days', 'https://www.google.com/maps/reviews/42', 5, 'id-with', 'https://lh3.googleusercontent.com/a/with=s128'),
			$review('Without Link', '-20 days', null, 5, 'id-without'),
			$review('Evil Link', '-30 days', 'javascript:alert(1)', 5, 'id-evil'),
			$review('Four Stars', '-5 days', null, 4, 'id-four'),
		];
		$pictures = ['https://lh3.googleusercontent.com/a/with=s128' => ['status' => 200, 'body' => $jpeg]];

		// 1. Plugin registered; off by default: nothing shown, nothing fetched.
		[$r, $html, $kirby] = $run(['sayamaapps.koe.placeId' => 'ChIJExample', 'sayamaapps.koe.key' => $key]);
		$this->holds($kirby->plugin('sayamaapps/koe') !== null, 'plugin sayamaapps/koe registered');
		$this->expect(null, $r, 'without koe.active koeReviews() is null');
		$this->expect('', trim($html), 'without koe.active the snippet prints nothing');
		$this->expect([], $queue, 'without koe.active nothing is fetched');
		$this->expect('Read all on Google', $kirby->translation('en')->get('sayamaapps.koe.readAll'), 'strings registered as Kirby translations');
		$this->expect('Alle auf Google lesen', $kirby->translation('de')->get('sayamaapps.koe.readAll'), 'German Kirby translation');
		$this->holds(function_exists('koe_option'), 'koe_option() helper');
		[$r] = $run(['sayamaapps.koe.active' => 'yes'] + $on);
		$this->expect(null, $r, 'sayamaapps.koe.active = "yes" must not switch on');
		$this->expect([], $queue, 'sayamaapps.koe.active = "yes": nothing fetched');

		// 2. Without a place id or without a key: nothing, no request; debug mode says why.
		$logged();
		[$r, $html] = $run(['sayamaapps.koe.active' => true, 'sayamaapps.koe.key' => $key, 'debug' => true]);
		$this->holds($r === null && trim($html) === '' && $queue === [], 'no koe.placeId: nothing shown, nothing fetched');
		$this->holds(str_contains($logged(), 'sayamaapps.koe.placeId is not set'), 'no koe.placeId: debug log says so');
		[$r] = $run(['sayamaapps.koe.active' => true, 'sayamaapps.koe.placeId' => 'ChIJExample', 'debug' => true]);
		$this->holds($r === null && $queue === [], 'no key: nothing shown, nothing fetched');
		$this->holds(str_contains($logged(), 'no API key'), 'no key: debug log says so');
		[$r] = $run(['sayamaapps.koe.active' => true, 'sayamaapps.koe.placeId' => 'ChIJExample']);
		$this->expect('', $logged(), 'without debug nothing is logged for a missing key');

		// 3. On demand: the first view shows nothing and queues one refresh; it runs after the response.
		$calls = [];
		[$r, $html, $kirby] = $run($on);
		$this->holds($r === null && trim($html) === '', 'empty cache: the first view shows nothing');
		$this->expect(1, count($queue), 'empty cache: one refresh queued, not two (snippet and method both asked)');
		$this->expect([], $calls, 'empty cache: no request during the page view');
		$this->expect(false, $kirby->response()->cache(), 'empty cache: the page without reviews is not put into Kirby\'s page cache');
		[$r] = $run($on);
		$this->expect(1, count($queue), 'a second view before the refresh ran: no second refresh');
		$drain();
		$this->expect(Sayamaapps\Koe\Places::ENDPOINT . 'places/ChIJExample?languageCode=en', $calls[0]['url'], 'the refresh asks for the details of koe.placeId, in the site language');
		$this->holds(in_array('X-Goog-Api-Key: ' . $key, $calls[0]['headers'], true), 'the refresh uses koe.key');
		$this->expect([], $calls[1]['headers'], 'the picture is fetched without the key');
		$this->holds(is_dir($tmp . '/site/cache') && !is_dir($tmp . '/site/data') && !is_dir($tmp . '/content/reviews'), 'kept in site/cache, nowhere else');
		$calls = [];

		// 4. Now shown, from the cache, without any request.
		[$r, $html, $kirby] = $run($on);
		$this->expect([], $calls, 'cached: no request');
		$this->expect([], $queue, 'cached: no refresh');
		$this->holds($r !== null && $r['count'] === 201 && $r['rating'] === 4.8 && count($r['reviews']) === 3, 'rating, count, three reviews; four stars out');
		$this->expect(3, $r['limit'], 'limit = maxCount');
		$this->expect('https://www.google.com/maps/place/?q=place_id:ChIJExample', $r['profileUrl'], 'profile URL from koe.placeId');
		$expires = $kirby->response()->expires();
		$this->holds(is_int($expires) && $expires > time() + 23 * 3600 && $expires <= time() + 24 * 3600, 'Kirby\'s page cache must not keep the page longer than the reviews (24 h by default)');
		$this->holds(str_contains($html, '<strong>4.8 out of 5</strong>') && str_contains($html, '201 reviews on Google Maps'), 'rating, count and text attribution in English');
		$with = $r['reviews'][0];
		$this->holds((bool)preg_match('~^http://example\.invalid/koe/avatar/[a-f0-9]{32}$~', (string)$with['avatar']), 'avatar is an address on the own site');
		$this->holds((bool)preg_match('~<a href="https://www\.google\.com/maps/reviews/42" target="_blank" rel="noopener"><img class="koe-avatar" src="' . preg_quote($with['avatar'], '~') . '" width="32" height="32" alt="" loading="lazy">With Link<span class="sr-only"> \(opens in a new tab\)</span></a>~', $html), 'picture and name inside the link to the review, target/rel and hint');
		$this->holds(!str_contains($html, 'googleusercontent'), 'no picture address of Google in the page');
		foreach (['Without Link', 'Evil Link'] as $name) {
			$this->holds((bool)preg_match('~<p class="koe-author">' . $name . ' ·~', $html), "$name as plain text, without picture");
		}
		$this->holds(!str_contains($html, 'javascript:'), 'javascript: link never printed');
		$this->holds(!str_contains($html, 'Four Stars'), 'four-star review not shown');
		$this->holds(str_contains($html, 'View profile') && !str_contains($html, 'Read all on Google'), 'three reviews: profile link, no tile');
		$this->holds(!str_contains($html, 'koe-logo'), 'without logo no logo image');
		$this->holds(str_contains($html, '<h2 id="koe-title">Reviews on Google</h2>'), 'default title');
		$this->holds((bool)preg_match('~<time datetime="\d{4}-\d{2}-\d{2}">[A-Z][a-z]+ \d{4}</time>~', $html), 'English month and year');

		// 5. The picture route serves the cached picture; anything else is a 404.
		$response = $kirby->call('koe/avatar/' . basename($with['avatar']));
		$this->expect([200, 'image/jpeg', $jpeg], [$response->code(), $response->type(), $response->body()], 'picture served from the own server');
		$this->holds((bool)preg_match('/^public, max-age=(\d+)$/', (string)($response->headers()['Cache-Control'] ?? ''), $m) && (int)$m[1] > 23 * 3600 && (int)$m[1] <= 24 * 3600, 'browser may keep it only as long as the cache');
		$expires = strtotime((string)($response->headers()['Expires'] ?? ''));
		$this->holds($expires !== false && $expires > time() + 23 * 3600 && $expires <= time() + 24 * 3600 + 5, 'Expires matches, so mod_expires adds no year');
		$this->expect('nosniff', $response->headers()['X-Content-Type-Options'] ?? null, 'nosniff');
		$this->expect(404, $kirby->call('koe/avatar/ffffffffffffffffffffffffffffffff')->code(), 'unknown picture: 404');
		[, , $off] = $run(['sayamaapps.koe.active' => false] + $on);
		$this->expect(404, $off->call('koe/avatar/' . basename($with['avatar']))->code(), 'module off: 404');

		// 6. The key from the environment ($_SERVER for Apache SetEnv) or a closure.
		$_SERVER['GOOGLE_PLACES_API_KEY'] = 'AIza-from-server';
		$calls = [];
		$fill(['sayamaapps.koe.active' => true, 'sayamaapps.koe.placeId' => 'ChIJExample']);
		$this->holds(in_array('X-Goog-Api-Key: AIza-from-server', $calls[0]['headers'] ?? [], true), 'key from $_SERVER');
		unset($_SERVER['GOOGLE_PLACES_API_KEY']);
		putenv('GOOGLE_PLACES_API_KEY=AIza-from-env');
		$calls = [];
		$fill(['sayamaapps.koe.active' => true, 'sayamaapps.koe.placeId' => 'ChIJExample']);
		$this->holds(in_array('X-Goog-Api-Key: AIza-from-env', $calls[0]['headers'] ?? [], true), 'key from getenv()');
		putenv('GOOGLE_PLACES_API_KEY');
		$calls = [];
		$fill(['sayamaapps.koe.key' => fn () => 'AIza-from-closure'] + $on);
		$this->holds(in_array('X-Goog-Api-Key: AIza-from-closure', $calls[0]['headers'] ?? [], true), 'sayamaapps.koe.key as closure');

		// 7. Holding time: koe.cacheHours, 1 to 24.
		foreach ([[2, 2], [0, 1], [48, 24], ['6', 6]] as [$hours, $expected]) {
			$fill(['sayamaapps.koe.cacheHours' => $hours] + $on);
			[, , $kirby] = $run(['sayamaapps.koe.cacheHours' => $hours] + $on);
			$left = $kirby->response()->expires() - time();
			$this->holds($left > ($expected * 3600 - 120) && $left <= $expected * 3600, "koe.cacheHours $hours → $expected h");
		}

		// 8. Settings changed: the cached data no longer fits, the next view fetches anew.
		$fill($on);
		$queue = [];
		[$r] = $run(['sayamaapps.koe.minRating' => 4] + $on);
		$this->holds($r === null && count($queue) === 1, 'other rules than cached: nothing shown, refresh queued');
		$drain();
		[$r] = $run(['sayamaapps.koe.minRating' => 4] + $on);
		$this->expect(['Four Stars', 'With Link', 'Without Link'], array_column($r['reviews'], 'name'), 'refreshed for the new rules');
		$queue = [];
		[$r] = $run(['sayamaapps.koe.placeId' => 'ChIJOther'] + $on);
		$this->holds($r === null && count($queue) === 1, 'other place than cached: nothing shown, refresh queued');
		$queue = [];

		// 9. A failed refresh is logged (also without debug), without the key, and the cache stays empty.
		$this->removeTree($tmp . '/site/cache');
		$answer = ['status' => 403, 'body' => json_encode(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'API key not valid: ' . $key]])];
		$logged();
		$run($on);
		$drain();
		$text = $logged();
		$this->holds(str_contains($text, 'koe: fetching the reviews failed: Places API answered 403') && !str_contains($text, $key), 'failure logged, key scrubbed');
		[$r] = $run($on);
		$this->holds($r === null && $queue === [], 'after a failure: nothing shown, no new attempt during the pause');
		$this->expect(1, $cacheFiles(), 'after a failure only the retry marker is left');
		$answer = ['id' => 'ChIJExample', 'rating' => 4.8, 'userRatingCount' => 201, 'reviews' => [
			$review('With Link', '-10 days', 'https://www.google.com/maps/reviews/42', 5, 'id-with', 'https://lh3.googleusercontent.com/a/with=s128'),
			$review('Without Link', '-20 days', null, 5, 'id-without'),
			$review('Evil Link', '-30 days', 'javascript:alert(1)', 5, 'id-evil'),
			$review('Four Stars', '-5 days', null, 4, 'id-four'),
		]];

		// 10. German: locale, or koe.language; the request asks Google in that language.
		$calls = [];
		$fill(['locale' => 'de_DE.UTF-8'] + $on);
		$this->expect(Sayamaapps\Koe\Places::ENDPOINT . 'places/ChIJExample?languageCode=de', $calls[0]['url'], 'German site asks in German');
		[, $html] = $run(['locale' => 'de_DE.UTF-8'] + $on);
		$this->holds(str_contains($html, '<strong>4,8 von 5</strong>') && str_contains($html, '201 Bewertungen auf Google Maps') && str_contains($html, 'Profil ansehen') && str_contains($html, '(öffnet neuen Tab)'), 'German via locale');
		$this->holds(str_contains($html, '>Bewertungen auf Google</h2>'), 'German default title');
		$fill(['locale' => 'de_DE', 'sayamaapps.koe.language' => 'en'] + $on);
		[, $html] = $run(['locale' => 'de_DE', 'sayamaapps.koe.language' => 'en'] + $on);
		$this->holds(str_contains($html, '4.8 out of 5'), 'sayamaapps.koe.language wins over locale');

		// 11. Approval, the tile, title and logo.
		$approved = ['sayamaapps.koe.approved' => ['id-without']] + $on;
		$fill($approved);
		[$r, $html] = $run($approved, ['title' => 'What our customers say', 'logo' => 'assets/GoogleMaps_Logo_Gray.svg']);
		$this->expect(['Without Link'], array_column($r['reviews'], 'name'), 'approved list');
		$this->holds(str_contains($html, 'Read all on Google') && str_contains($html, '<p>201 reviews</p>'), 'tile with count below three reviews');
		$this->holds(str_contains($html, '>What our customers say</h2>'), 'title from snippet data');
		$this->holds((bool)preg_match('~<img class="koe-logo" src="[^"]*/assets/GoogleMaps_Logo_Gray\.svg"[^>]*alt="Google Maps">~', $html), 'logo with alt text');
		[, $html] = $run($approved + ['sayamaapps.koe.title' => 'Voices', 'sayamaapps.koe.logo' => 'assets/logo.svg']);
		$this->holds(str_contains($html, '>Voices</h2>') && str_contains($html, '/assets/logo.svg"'), 'title and logo from options');
		[, $html] = $run($approved + ['sayamaapps.koe.logo' => 'assets/logo.svg'], ['logo' => null]);
		$this->holds(str_contains($html, '/assets/logo.svg"'), 'snippet data null falls back to the option');
		$none = ['sayamaapps.koe.approved' => []] + $on;
		$fill($none);
		[$r, $html] = $run($none);
		$this->expect([], $r['reviews'], 'approved []: no single review');
		$this->holds(str_contains($html, '4.8 out of 5') && !str_contains($html, '<ul') && !str_contains($html, 'koe-notice'), 'approved []: rating and count still shown, no list, no notice');
		$nested = ['koe' => ['active' => true, 'placeId' => 'ChIJExample', 'key' => $key, 'approved' => ['id-with', 'id-four'], 'minRating' => 4]];
		$fill($nested);
		[$r] = $run($nested);
		$this->expect(['Four Stars', 'With Link'], array_column($r['reviews'], 'name'), 'nested spelling, approved with lower rating');

		// 12. An answer without review texts: rating and count; without any rating: nothing.
		$answer['reviews'] = [];
		$answer['rating'] = 2.0;
		$answer['userRatingCount'] = 9;
		$fill($on);
		[$r, $html] = $run($on);
		$this->holds($r !== null && $r['reviews'] === [] && $r['rating'] === 2.0 && $r['count'] === 9, 'empty review list: rating and count');
		$this->holds(str_contains($html, '<strong>2.0 out of 5</strong>') && !str_contains($html, '<ul'), 'empty review list: rendered without list');
		unset($answer['rating'], $answer['userRatingCount']);
		$fill($on);
		[$r, $html] = $run($on);
		$this->holds($r === null && trim($html) === '' && $queue === [], 'no rating at all: nothing shown, and no refresh on every view');

		// 13. The notice, worded from the options.
		$answer = ['id' => 'ChIJExample', 'rating' => 4.8, 'userRatingCount' => 201, 'reviews' => [$review('Notice', '-3 days', null, 5, 'id-notice')]];
		$noticeOf = fn (string $html) => preg_match('~<p class="koe-notice">(.*?)</p>~s', $html, $m) ? $m[1] : null;
		$defaultEn = 'Selection: 5 stars, 80+ characters, at most 24 months old, newest first.';
		$fill($on);
		[$r, $html] = $run($on);
		$this->expect($defaultEn, $noticeOf($html), 'default snippet: notice for the default options');
		$this->expect($defaultEn, $r['notice'], 'koeReviews() hands the notice to own snippets as well');
		$this->holds(strpos($html, 'koe-notice') > strpos($html, '</ul>'), 'the notice stands below the reviews');
		$strict = ['sayamaapps.koe.minRating' => 4, 'sayamaapps.koe.minLength' => 120, 'sayamaapps.koe.maxAgeMonths' => 12, 'sayamaapps.koe.maxCount' => 2] + $on;
		$fill($strict);
		[, $html] = $run($strict);
		$this->expect('Selection: 4+ stars, 120+ characters, at most 12 months old, newest first.', $noticeOf($html), 'notice follows the options');
		$this->holds(!str_contains(file_get_contents($base . '/snippets/reviews.php'), 'at least 80'), 'the snippet itself carries no fixed rule text');

		// 14. A site's own snippet wins over the package's default.
		$fill($on);
		mkdir($tmp . '/site/snippets/koe', 0700);
		file_put_contents($tmp . '/site/snippets/koe/reviews.php', '<?php $r = site()->koeReviews(); ?>OWN <?= $r === null ? "off" : count($r["reviews"]) . "/" . $r["limit"] ?>');
		[, $html] = $run($on);
		$this->expect('OWN 1/3', trim($html), 'site/snippets/koe/reviews.php must override the package snippet');
	}
}
