<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use Kirby\Template\Snippet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Koe\Options;
use Sayamaapps\Koe\Places;
use Sayamaapps\Koe\Plugin;

/**
 * The plugin with real Kirby, in a throwaway directory: the switch (off by
 * default), place id and key, the fetch on demand (the first view after the
 * cache expired starts it after the response and shows nothing itself), the
 * holding time, the profile picture route, the options for rules, approval,
 * title, logo and language, the default snippet with the Places obligations
 * (author with picture and link only when safe, attribution, notice), and
 * that a site's own site/snippets/koe/reviews.php wins.
 *
 * The HTTP transport and the deferred refresh are passed in by the test
 * (Plugin::reviews()); the snippet is only rendered on a cache that is
 * already filled, so no request leaves the test.
 *   vendor/bin/phpunit
 *
 * One test method per case makes this class large on purpose (31 methods, 17 public).
 * @SuppressWarnings(PHPMD.ExcessiveClassComplexity)
 * @SuppressWarnings(PHPMD.TooManyMethods)
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * Global classes (RuntimeException, DateTimeImmutable, ...) are used without import: a use
 * statement for a global name has no effect in PHP, and PHP warns about it.
 * @SuppressWarnings(PHPMD.MissingImport)
 */
#[CoversClass(\Sayamaapps\Koe\Plugin::class)]
final class KirbyTest extends TestCase
{
	private const KEY = 'AIza-not-a-real-key-0000';

	/** Google's answer to the details call; a raw ['status', 'body'] answers the call as it is. */
	private array $answer = [];

	/** Every request the transport answered. */
	private array $calls = [];
	private string $log;

	/** Picture address → response. */
	private array $pictures = [];

	/** Deferred refresh jobs, run by drain(). */
	private array $queue = [];
	private string $tmp;

	/** A Kirby app in the throwaway directory with these options. */
	private function app(array $options): App
	{
		Snippet::$cache = []; // Kirby remembers snippet paths per process
		return new App(['roots' => [
			'index' => $this->tmp . '/public',
			'base' => $this->tmp,
			'content' => $this->tmp . '/content',
			'site' => $this->tmp . '/site',
			'storage' => $this->tmp . '/storage',
		], 'options' => ['url' => 'http://example.invalid'] + $options]);
	}

	/** Number of cache files, the lock files beside them not counted. */
	private function cacheFiles(): int
	{
		$dir = $this->tmp . '/site/cache';
		if (!is_dir($dir)) {
			return 0;
		}
		$files = iterator_to_array(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)));
		return count(array_filter($files, fn (\SplFileInfo $file) => !str_ends_with($file->getFilename(), '.lock')));
	}

	/** Runs the queued refreshes, as after the response. */
	private function drain(): void
	{
		while ($job = array_shift($this->queue)) {
			$job();
		}
	}

	/** Empties the cache and fills it the way a first visitor does: one view, then the queued refresh runs. */
	private function fill(array $options): void
	{
		Dir::remove($this->tmp . '/site/cache');
		$this->queue = [];
		$this->firstView($options);
		$this->drain();
	}

	/**
	 * A page view that finds nothing cached: returns [koeReviews() result, Kirby].
	 * The refresh is only queued, never started.
	 *
	 * @return array{0: array|null, 1: App}
	 */
	private function firstView(array $options): array
	{
		$kirby = $this->app($options);
		return [Plugin::reviews($kirby, Options::rules($kirby), $this->transport(), $this->queueing()), $kirby];
	}

	private function jpeg(): string
	{
		return "\xFF\xD8\xFF\xE0" . str_repeat('j', 100);
	}

	/** The log written so far, emptied. */
	private function logged(): string
	{
		$text = is_file($this->log) ? (string)file_get_contents($this->log) : '';
		file_put_contents($this->log, '');
		return $text;
	}

	/** The options that switch the module on for the place, with the key. */
	private function on(): array
	{
		return ['koe.active' => true, 'koe.placeId' => 'ChIJExample', 'koe.key' => self::KEY];
	}

	/** Puts the refresh into the queue instead of running it after the response. */
	private function queueing(): Closure
	{
		return function (Closure $job): void {
			$this->queue[] = $job;
		};
	}

	/**
	 * Renders the default snippet as a page does, entities decoded.
	 * Returns [koeReviews(), HTML, Kirby]. Only on a filled cache: an empty
	 * one would start a refresh, which is not run here.
	 *
	 * @return array{0: array|null, 1: string, 2: App}
	 */
	private function render(array $options, array $snippetData = []): array
	{
		$kirby = $this->app($options);
		$html = html_entity_decode((string)$kirby->snippet('koe/reviews', $snippetData, true));
		return [$kirby->site()->koeReviews(), $html, $kirby];
	}

	/** One review of a Places details answer. */
	private function review(string $name, string $ago, string|null $url = null, int $rating = 5, string|null $id = null, string|null $photo = null): array
	{
		return array_filter([
			'name' => $id === null ? null : 'places/ChIJExample/reviews/' . $id,
			'rating' => $rating,
			'originalText' => ['text' => str_repeat('Friendly, competent and fast, happy to come back. ', 3)],
			'authorAttribution' => array_filter(['displayName' => $name, 'photoUri' => $photo]),
			'publishTime' => date('Y-m-d', strtotime($ago)) . 'T10:00:00Z',
			'googleMapsUri' => $url,
		], fn ($v) => $v !== null);
	}

	protected function setUp(): void
	{
		$this->tmp = self::site();
		Dir::remove($this->tmp . '/site/cache');
		$this->log = $this->tmp . '/php-error.log';
		ini_set('error_log', $this->log);
		$this->answer = ['id' => 'ChIJExample', 'rating' => 4.8, 'userRatingCount' => 201, 'reviews' => [
			$this->review('With Link', '-10 days', 'https://www.google.com/maps/reviews/42', 5, 'id-with', 'https://lh3.googleusercontent.com/a/with=s128'),
			$this->review('Without Link', '-20 days', null, 5, 'id-without'),
			$this->review('Evil Link', '-30 days', 'javascript:alert(1)', 5, 'id-evil'),
			$this->review('Four Stars', '-5 days', null, 4, 'id-four'),
		]];
		$this->pictures = ['https://lh3.googleusercontent.com/a/with=s128' => ['status' => 200, 'body' => $this->jpeg()]];
	}

	/**
	 * The site folder that all tests of the process share. Kirby registers the
	 * plugin once per process, from the folder it finds on the first app, so
	 * the folder must outlive each test. Each test clears its own cache and
	 * snippets; the folder goes at the end of the process.
	 */
	private static function site(): string
	{
		$root = sys_get_temp_dir() . '/koe-kirby-' . getmypid();
		if (is_dir($root)) {
			return $root;
		}
		foreach (['public', 'content/home', 'site/plugins', 'site/snippets', 'site/templates', 'storage'] as $dir) {
			mkdir($root . '/' . $dir, 0700, true);
		}
		symlink(dirname(__DIR__), $root . '/site/plugins/koe');
		file_put_contents($root . '/site/templates/default.php', 'Fixture');
		file_put_contents($root . '/content/home/home.txt', 'Title: Home');
		register_shutdown_function(fn () => Dir::remove($root));
		return $root;
	}

	protected function tearDown(): void
	{
		Snippet::$cache = [];
		Dir::remove($this->tmp . '/site/cache');
		Dir::remove($this->tmp . '/site/snippets');
		if (is_file($this->log)) {
			unlink($this->log);
		}
		ini_restore('error_log');
		unset($_SERVER['GOOGLE_PLACES_API_KEY']);
		putenv('GOOGLE_PLACES_API_KEY');
	}

	public function testAnswerWithoutAnyRatingShowsNothingAndDoesNotAskAgain(): void
	{
		unset($this->answer['rating'], $this->answer['userRatingCount']);
		$this->fill($this->on());
		[$r, $html] = $this->render($this->on());
		$this->assertTrue($r === null && trim($html) === '' && $this->queue === [], 'no rating at all: nothing shown, and no refresh on every view');
	}

	public function testAnswerWithoutTextsShowsRatingAndCount(): void
	{
		$this->answer['reviews'] = [];
		$this->answer['rating'] = 2.0;
		$this->answer['userRatingCount'] = 9;
		$this->fill($this->on());
		[$r, $html] = $this->render($this->on());
		$this->assertTrue($r !== null && $r['reviews'] === [] && $r['rating'] === 2.0 && $r['count'] === 9, 'empty review list: rating and count');
		$this->assertTrue(str_contains($html, '<strong>2.0 out of 5</strong>') && !str_contains($html, '<ul'), 'empty review list: rendered without list');
	}

	public function testApprovedListTileTitleAndLogo(): void
	{
		$approved = ['koe.approved' => ['id-without']] + $this->on();
		$this->fill($approved);
		[$r, $html] = $this->render($approved, ['title' => 'What our customers say', 'logo' => 'assets/GoogleMaps_Logo_Gray.svg']);
		$this->assertSame(['Without Link'], array_column($r['reviews'], 'name'), 'approved list');
		$this->assertTrue(str_contains($html, 'Read all on Google') && str_contains($html, '<p>201 reviews</p>'), 'tile with count below three reviews');
		$this->assertTrue(str_contains($html, '>What our customers say</h2>'), 'title from snippet data');
		$this->assertTrue((bool)preg_match('~<img class="koe-logo" src="[^"]*/assets/GoogleMaps_Logo_Gray\.svg"[^>]*alt="Google Maps">~', $html), 'logo with alt text');
		[, $html] = $this->render($approved + ['koe.title' => 'Voices', 'koe.logo' => 'assets/logo.svg']);
		$this->assertTrue(str_contains($html, '>Voices</h2>') && str_contains($html, '/assets/logo.svg"'), 'title and logo from options');
		[, $html] = $this->render($approved + ['koe.logo' => 'assets/logo.svg'], ['logo' => null]);
		$this->assertTrue(str_contains($html, '/assets/logo.svg"'), 'snippet data null falls back to the option');
	}

	public function testCachedReviewsAreShownWithoutAnyRequest(): void
	{
		$this->fill($this->on());
		$this->calls = [];
		[$r, $html, $kirby] = $this->render($this->on());
		$this->assertSame([], $this->calls, 'cached: no request');
		$this->assertSame([], $this->queue, 'cached: no refresh');
		$this->assertTrue($r !== null && $r['count'] === 201 && $r['rating'] === 4.8 && count($r['reviews']) === 3, 'rating, count, three reviews; four stars out');
		$this->assertSame(3, $r['limit'], 'limit = maxCount');
		$this->assertSame('https://www.google.com/maps/place/?q=place_id:ChIJExample', $r['profileUrl'], 'profile URL from koe.placeId');
		$expires = $kirby->response()->expires();
		$this->assertTrue(is_int($expires) && $expires > time() + 23 * 3600 && $expires <= time() + 24 * 3600, 'Kirby\'s page cache must not keep the page longer than the reviews (24 h by default)');
		$this->assertTrue(str_contains($html, '<strong>4.8 out of 5</strong>') && str_contains($html, '201 reviews on Google Maps'), 'rating, count and text attribution in English');
		$with = $r['reviews'][0];
		$this->assertTrue((bool)preg_match('~^http://example\.invalid/koe/avatar/[a-f0-9]{32}$~', (string)$with['avatar']), 'avatar is an address on the own site');
		$this->assertTrue((bool)preg_match('~<a href="https://www\.google\.com/maps/reviews/42" target="_blank" rel="noopener"><img class="koe-avatar" src="' . preg_quote($with['avatar'], '~') . '" width="32" height="32" alt="" loading="lazy">With Link<span class="sr-only"> \(opens in a new tab\)</span></a>~', $html), 'picture and name inside the link to the review, target/rel and hint');
		$this->assertFalse(str_contains($html, 'googleusercontent'), 'no picture address of Google in the page');
		foreach (['Without Link', 'Evil Link'] as $name) {
			$this->assertTrue((bool)preg_match('~<p class="koe-author">' . $name . ' ·~', $html), "$name as plain text, without picture");
		}
		$this->assertFalse(str_contains($html, 'javascript:'), 'javascript: link never printed');
		$this->assertFalse(str_contains($html, 'Four Stars'), 'four-star review not shown');
		$this->assertTrue(str_contains($html, 'View profile') && !str_contains($html, 'Read all on Google'), 'three reviews: profile link, no tile');
		$this->assertFalse(str_contains($html, 'koe-logo'), 'without logo no logo image');
		$this->assertTrue(str_contains($html, '<h2 id="koe-title">Reviews on Google</h2>'), 'default title');
		$this->assertTrue((bool)preg_match('~<time datetime="\d{4}-\d{2}-\d{2}">[A-Z][a-z]+ \d{4}</time>~', $html), 'English month and year');
	}

	public function testChangedRulesOrPlaceAreFetchedAnew(): void
	{
		$this->fill($this->on());
		$this->queue = [];
		[$r] = $this->firstView(['koe.minRating' => 4] + $this->on());
		$this->assertTrue($r === null && count($this->queue) === 1, 'other rules than cached: nothing shown, refresh queued');
		$this->drain();
		[$r] = $this->render(['koe.minRating' => 4] + $this->on());
		$this->assertSame(['Four Stars', 'With Link', 'Without Link'], array_column($r['reviews'], 'name'), 'refreshed for the new rules');
		$this->queue = [];
		[$r] = $this->firstView(['koe.placeId' => 'ChIJOther'] + $this->on());
		$this->assertTrue($r === null && count($this->queue) === 1, 'other place than cached: nothing shown, refresh queued');
	}

	public function testDebugModeRendersTheDefaultSnippetWithoutDeprecation(): void
	{
		// The title and logo come from the option names; the default snippet must stay silent in debug mode.
		$options = ['debug' => true, 'koe.title' => 'Voices', 'koe.logo' => 'assets/logo.svg'] + $this->on();
		$this->fill($options);
		$deprecations = [];
		set_error_handler(function (int $level, string $message) use (&$deprecations): bool {
			if ($level === E_USER_DEPRECATED) {
				$deprecations[] = $message;
			}
			return true;
		});
		try {
			[, $html] = $this->render($options);
		} finally {
			restore_error_handler();
		}
		$this->assertSame([], $deprecations, 'the default snippet raises no deprecation in debug mode');
		$this->assertTrue(str_contains($html, '<h2 id="koe-title">Voices</h2>'), 'title from the option');
		$this->assertTrue(str_contains($html, '<img class="koe-logo" src="http://example.invalid/assets/logo.svg"'), 'logo from the option');
	}

	public function testEmptyApprovalAndNestedSpelling(): void
	{
		$none = ['koe.approved' => []] + $this->on();
		$this->fill($none);
		[$r, $html] = $this->render($none);
		$this->assertSame([], $r['reviews'], 'approved []: no single review');
		$this->assertTrue(str_contains($html, '4.8 out of 5') && !str_contains($html, '<ul') && !str_contains($html, 'koe-notice'), 'approved []: rating and count still shown, no list, no notice');
		$nested = ['koe' => ['active' => true, 'placeId' => 'ChIJExample', 'key' => self::KEY, 'approved' => ['id-with', 'id-four'], 'minRating' => 4]];
		$this->fill($nested);
		[$r] = $this->render($nested);
		$this->assertSame(['Four Stars', 'With Link'], array_column($r['reviews'], 'name'), 'nested spelling, approved with lower rating');
	}

	public function testFailedRefreshIsLoggedWithoutDebugAndKeepsTheCacheEmpty(): void
	{
		$on = $this->on();
		Dir::remove($this->tmp . '/site/cache');
		$this->answer = ['status' => 403, 'body' => json_encode(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'API key not valid: ' . self::KEY]])];
		$this->logged();
		$this->firstView($on);
		$this->drain();
		$text = $this->logged();
		$this->assertTrue(str_contains($text, 'koe: fetching the reviews failed: Places API answered 403') && !str_contains($text, self::KEY), 'failure logged, key scrubbed');
		[$r] = $this->firstView($on);
		$this->assertTrue($r === null && $this->queue === [], 'after a failure: nothing shown, no new attempt during the pause');
		$this->assertSame(1, $this->cacheFiles(), 'after a failure only the retry marker is left');
	}

	public function testFirstViewShowsNothingAndQueuesOneRefreshThatFillsTheCache(): void
	{
		[$r, $kirby] = $this->firstView($this->on());
		$this->assertNull($r, 'empty cache: the first view shows nothing');
		$this->assertSame('', trim(html_entity_decode((string)$kirby->snippet('koe/reviews', [], true))), 'empty cache: the snippet prints nothing');
		$this->assertCount(1, $this->queue, 'empty cache: one refresh queued, not two (snippet and method both asked)');
		$this->assertSame([], $this->calls, 'empty cache: no request during the page view');
		$this->assertFalse($kirby->response()->cache(), 'empty cache: the page without reviews is not put into Kirby\'s page cache');
		$this->firstView($this->on());
		$this->assertCount(1, $this->queue, 'a second view before the refresh ran: no second refresh');
		$this->drain();
		$this->assertSame(Places::ENDPOINT . 'places/ChIJExample?languageCode=en', $this->calls[0]['url'], 'the refresh asks for the details of koe.placeId, in the site language');
		$this->assertTrue(in_array('X-Goog-Api-Key: ' . self::KEY, $this->calls[0]['headers'], true), 'the refresh uses koe.key');
		$this->assertSame([], $this->calls[1]['headers'], 'the picture is fetched without the key');
		$this->assertTrue(is_dir($this->tmp . '/site/cache') && !is_dir($this->tmp . '/site/data') && !is_dir($this->tmp . '/content/reviews'), 'kept in site/cache, nowhere else');
	}

	public function testGermanFromLocaleOrLanguageOption(): void
	{
		$this->calls = [];
		$this->fill(['locale' => 'de_DE.UTF-8'] + $this->on());
		$this->assertSame(Places::ENDPOINT . 'places/ChIJExample?languageCode=de', $this->calls[0]['url'], 'German site asks in German');
		[, $html] = $this->render(['locale' => 'de_DE.UTF-8'] + $this->on());
		$this->assertTrue(str_contains($html, '<strong>4,8 von 5</strong>') && str_contains($html, '201 Bewertungen auf Google Maps') && str_contains($html, 'Profil ansehen') && str_contains($html, '(öffnet neuen Tab)'), 'German via locale');
		$this->assertTrue(str_contains($html, '>Bewertungen auf Google</h2>'), 'German default title');
		$this->fill(['locale' => 'de_DE', 'koe.language' => 'en'] + $this->on());
		[, $html] = $this->render(['locale' => 'de_DE', 'koe.language' => 'en'] + $this->on());
		$this->assertTrue(str_contains($html, '4.8 out of 5'), 'koe.language wins over locale');
	}

	public function testHoldingTimeFromCacheHours(): void
	{
		foreach ([[2, 2], [0, 1], [48, 24], ['6', 6]] as [$hours, $expected]) {
			$this->fill(['koe.cacheHours' => $hours] + $this->on());
			[, , $kirby] = $this->render(['koe.cacheHours' => $hours] + $this->on());
			$left = $kirby->response()->expires() - time();
			$this->assertTrue($left > ($expected * 3600 - 120) && $left <= $expected * 3600, "koe.cacheHours $hours → $expected h");
		}
	}

	public function testKeyComesFromTheEnvironmentOrAClosure(): void
	{
		$_SERVER['GOOGLE_PLACES_API_KEY'] = 'AIza-from-server';
		$this->fill(['koe.active' => true, 'koe.placeId' => 'ChIJExample']);
		$this->assertTrue(in_array('X-Goog-Api-Key: AIza-from-server', $this->calls[0]['headers'] ?? [], true), 'key from $_SERVER');
		unset($_SERVER['GOOGLE_PLACES_API_KEY']);
		putenv('GOOGLE_PLACES_API_KEY=AIza-from-env');
		$this->calls = [];
		$this->fill(['koe.active' => true, 'koe.placeId' => 'ChIJExample']);
		$this->assertTrue(in_array('X-Goog-Api-Key: AIza-from-env', $this->calls[0]['headers'] ?? [], true), 'key from getenv()');
		putenv('GOOGLE_PLACES_API_KEY');
		$this->calls = [];
		$this->fill(['koe.key' => fn () => 'AIza-from-closure'] + $this->on());
		$this->assertTrue(in_array('X-Goog-Api-Key: AIza-from-closure', $this->calls[0]['headers'] ?? [], true), 'koe.key as closure');
	}

	public function testKeyFromTheEnvironmentIsReadWhenSetAfterTheAppBooted(): void
	{
		$kirby = $this->app(['koe.active' => true, 'koe.placeId' => 'ChIJExample']);
		$_SERVER['GOOGLE_PLACES_API_KEY'] = 'AIza-set-after-boot';
		Plugin::reviews($kirby, Options::rules($kirby), $this->transport(), $this->queueing());
		$this->drain();
		$this->assertTrue(in_array('X-Goog-Api-Key: AIza-set-after-boot', $this->calls[0]['headers'] ?? [], true), 'key from $_SERVER at fetch time');
	}

	public function testNoticeIsWordedFromTheOptions(): void
	{
		$this->answer = ['id' => 'ChIJExample', 'rating' => 4.8, 'userRatingCount' => 201, 'reviews' => [$this->review('Notice', '-3 days', null, 5, 'id-notice')]];
		$noticeOf = fn (string $html): string|null => preg_match('~<p class="koe-notice">(.*?)</p>~s', $html, $m) ? $m[1] : null;
		$defaultEn = 'Selection: 5 stars, 80+ characters, at most 24 months old, newest first.';
		$this->fill($this->on());
		[$r, $html] = $this->render($this->on());
		$this->assertSame($defaultEn, $noticeOf($html), 'default snippet: notice for the default options');
		$this->assertSame($defaultEn, $r['notice'], 'koeReviews() hands the notice to own snippets as well');
		$this->assertTrue(strpos($html, 'koe-notice') > strpos($html, '</ul>'), 'the notice stands below the reviews');
		$strict = ['koe.minRating' => 4, 'koe.minLength' => 120, 'koe.maxAgeMonths' => 12, 'koe.maxCount' => 2] + $this->on();
		$this->fill($strict);
		[, $html] = $this->render($strict);
		$this->assertSame('Selection: 4+ stars, 120+ characters, at most 12 months old, newest first.', $noticeOf($html), 'notice follows the options');
		$this->assertFalse(str_contains((string)file_get_contents(dirname(__DIR__) . '/snippets/reviews.php'), 'at least 80'), 'the snippet itself carries no fixed rule text');
	}

	public function testPictureRouteServesTheCachedPictureOrNothing(): void
	{
		$this->fill($this->on());
		[$r, , $kirby] = $this->render($this->on());
		$with = $r['reviews'][0];
		$response = $kirby->call('koe/avatar/' . basename($with['avatar']));
		$this->assertSame([200, 'image/jpeg', $this->jpeg()], [$response->code(), $response->type(), $response->body()], 'picture served from the own server');
		$this->assertTrue((bool)preg_match('/^public, max-age=(\d+)$/', (string)($response->headers()['Cache-Control'] ?? ''), $m) && (int)$m[1] > 23 * 3600 && (int)$m[1] <= 24 * 3600, 'browser may keep it only as long as the cache');
		$expires = strtotime((string)($response->headers()['Expires'] ?? ''));
		$this->assertTrue($expires !== false && $expires > time() + 23 * 3600 && $expires <= time() + 24 * 3600 + 5, 'Expires matches, so mod_expires adds no year');
		$this->assertSame('nosniff', $response->headers()['X-Content-Type-Options'] ?? null, 'nosniff');
		$this->assertSame(404, $kirby->call('koe/avatar/ffffffffffffffffffffffffffffffff')->code(), 'unknown picture: 404');
		[, , $off] = $this->render(['koe.active' => false] + $this->on());
		$this->assertSame(404, $off->call('koe/avatar/' . basename($with['avatar']))->code(), 'module off: 404');
	}

	public function testPluginRegistersTheSnippetUnderACleanPath(): void
	{
		$kirby = $this->app($this->on());
		$this->assertSame(dirname(__DIR__) . '/snippets/reviews.php', $kirby->plugin('sayamaapps/koe')->extends()['snippets']['koe/reviews'], 'no ../ in the snippet path');
	}

	public function testSiteSnippetOverridesThePackageSnippet(): void
	{
		$this->answer['reviews'] = [$this->review('Notice', '-3 days', null, 5, 'id-notice')];
		$this->fill($this->on());
		mkdir($this->tmp . '/site/snippets/koe', 0700, true);
		file_put_contents($this->tmp . '/site/snippets/koe/reviews.php', '<?php $r = site()->koeReviews(); ?>OWN <?= $r === null ? "off" : count($r["reviews"]) . "/" . $r["limit"] ?>');
		[, $html] = $this->render($this->on());
		$this->assertSame('OWN 1/3', trim($html), 'site/snippets/koe/reviews.php must override the package snippet');
	}

	public function testSwitchedOffByDefaultNothingIsShownOrFetched(): void
	{
		[$r, $html, $kirby] = $this->render(['koe.placeId' => 'ChIJExample', 'koe.key' => self::KEY]);
		$this->assertTrue($kirby->plugin('sayamaapps/koe') !== null, 'plugin sayamaapps/koe registered');
		$this->assertNull($r, 'without koe.active koeReviews() is null');
		$this->assertSame('', trim($html), 'without koe.active the snippet prints nothing');
		$this->assertSame([], $this->queue, 'without koe.active nothing is fetched');
		$this->assertSame('Read all on Google', $kirby->translation('en')->get('koe.readAll'), 'strings registered as Kirby translations');
		$this->assertSame('Read all on Google', $kirby->translation('en')->get('sayamaapps.koe.readAll'), 'the sayamaapps.koe prefix too');
		$this->assertSame('Alle auf Google lesen', $kirby->translation('de')->get('koe.readAll'), 'German Kirby translation');
		$this->assertTrue(function_exists('koe_option'), 'koe_option() helper');
		[$r] = $this->render(['koe.active' => 'yes'] + $this->on());
		$this->assertNull($r, 'koe.active = "yes" must not switch on');
		$this->assertSame([], $this->queue, 'koe.active = "yes": nothing fetched');
	}

	public function testWithoutPlaceIdOrKeyNothingIsFetchedAndDebugModeSaysWhy(): void
	{
		$this->logged();
		[$r, $html] = $this->render(['koe.active' => true, 'koe.key' => self::KEY, 'debug' => true]);
		$this->assertTrue($r === null && trim($html) === '' && $this->queue === [], 'no koe.placeId: nothing shown, nothing fetched');
		$this->assertTrue(str_contains($this->logged(), 'koe.placeId is not set'), 'no koe.placeId: debug log says so');
		[$r] = $this->render(['koe.active' => true, 'koe.placeId' => 'ChIJExample', 'debug' => true]);
		$this->assertTrue($r === null && $this->queue === [], 'no key: nothing shown, nothing fetched');
		$this->assertTrue(str_contains($this->logged(), 'no API key'), 'no key: debug log says so');
		[$r] = $this->render(['koe.active' => true, 'koe.placeId' => 'ChIJExample']);
		$this->assertSame('', $this->logged(), 'without debug nothing is logged for a missing key');
	}

	/** Google's answer: the details of ChIJExample (or the raw answer), and the pictures. Records every call. */
	private function transport(): Closure
	{
		return function (string $url, array $headers): array {
			$this->calls[] = ['url' => $url, 'headers' => $headers];
			if (str_starts_with($url, Places::ENDPOINT)) {
				return isset($this->answer['status']) ? $this->answer : ['status' => 200, 'body' => json_encode($this->answer)];
			}
			return $this->pictures[$url] ?? ['status' => 404, 'body' => ''];
		};
	}
}
