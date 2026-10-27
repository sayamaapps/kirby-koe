<?php

use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Koe\Fetch;
use Sayamaapps\Koe\Places;

/**
 * The Places client and the koe-fetch lookup against recorded Places API
 * answers, without a network: parsing, and every failure path (no key, no
 * query, nothing found, quota, API error, transport error). The CLI only
 * shows; it writes nothing. The key must never appear in any output.
 *   vendor/bin/phpunit
 *
 * One test method per case makes this class large on purpose (13 public methods).
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)
 *
 * Global classes (RuntimeException, DateTimeImmutable, ...) are used without import: a use
 * statement for a global name has no effect in PHP, and PHP warns about it.
 * @SuppressWarnings(PHPMD.MissingImport)
 */
#[CoversClass(\Sayamaapps\Koe\Fetch::class)]
#[CoversClass(\Sayamaapps\Koe\Places::class)]
#[CoversClass(\Koe\PlacesException::class)]
final class PlacesTest extends TestCase
{
	private const KEY = 'AIza-not-a-real-key-0000';

	/** Every request the transport answered. */
	private array $calls = [];
	private string $tmp;

	/** Runs Fetch::run with captured output, in an empty working directory. */
	private function cli(array $env, Closure|null $t, array $argv = ['koe-fetch']): array
	{
		$out = fopen('php://memory', 'w+');
		$err = fopen('php://memory', 'w+');
		$cwd = getcwd();
		chdir($this->tmp);
		try {
			$code = Fetch::run($env, $argv, $t, $out, $err, '2026-10-05');
		} finally {
			chdir($cwd);
		}
		rewind($out);
		rewind($err);
		return [$code, stream_get_contents($out), stream_get_contents($err)];
	}

	/** The environment of a complete lookup. */
	private function env(): array
	{
		return [Fetch::ENV_KEY => self::KEY, Fetch::ENV_QUERY => 'Example Café, Street 1, 12345 Town'];
	}

	/** The message of the exception a failing search throws. */
	private function failure(array $script): string
	{
		try {
			(new Places($this->transport($script), self::KEY))->search('x');
		} catch (\Koe\PlacesException $e) {
			return $e->getMessage();
		}
		throw new \RuntimeException('no exception');
	}

	private function fixture(string $name): string
	{
		return (string)file_get_contents(__DIR__ . '/fixtures/' . $name);
	}

	/** A successful search and details call. */
	private function ok(): Closure
	{
		return $this->transport([
			['status' => 200, 'body' => $this->fixture('places-search.json')],
			['status' => 200, 'body' => $this->fixture('places-details.json')],
		]);
	}

	protected function setUp(): void
	{
		$this->tmp = sys_get_temp_dir() . '/koe-fetch-' . bin2hex(random_bytes(6));
		mkdir($this->tmp, 0700);
		$this->calls = [];
	}

	protected function tearDown(): void
	{
		Dir::remove($this->tmp);
	}

	public function testCliEmptyAnswerExitsWithZeroAndSaysSo(): void
	{
		$empty = json_encode(['id' => 'ChIJTestPlace_Id0', 'rating' => 2.0, 'userRatingCount' => 9, 'reviews' => []]);
		[$code, $out] = $this->cli($this->env(), $this->transport([
			['status' => 200, 'body' => $this->fixture('places-search.json')],
			['status' => 200, 'body' => $empty],
		]));
		$this->assertTrue($code === Fetch::EXIT_OK && str_contains($out, 'Google returned no usable reviews'), 'empty answer: exit 0, said so');
	}

	public function testClientReportsEachFailureWithTheKeyScrubbed(): void
	{
		$this->assertSame('Places search found nothing for: x', $this->failure([['status' => 200, 'body' => '{"places": []}']]), 'nothing found');
		$this->assertSame('Places API answered 403 PERMISSION_DENIED API key not valid: [key]', $this->failure([['status' => 403, 'body' => json_encode(['error' => ['code' => 403, 'message' => 'API key not valid: ' . self::KEY, 'status' => 'PERMISSION_DENIED']])]]), '403 with Google status and message, key scrubbed');
		$quota = $this->failure([['status' => 429, 'body' => json_encode(['error' => ['code' => 429, 'message' => 'Quota exceeded for quota metric', 'status' => 'RESOURCE_EXHAUSTED']])]]);
		$this->assertTrue(str_starts_with($quota, 'Places API answered 429 RESOURCE_EXHAUSTED Quota exceeded') && str_contains($quota, 'daily quota'), 'quota: status plus hint');
		$this->assertSame('Places API answered 502', $this->failure([['status' => 502, 'body' => '<html>Bad Gateway</html>']]), 'non-JSON error body: status only');
		$this->assertSame('Places API answered 200 with a body that is not JSON.', $this->failure([['status' => 200, 'body' => 'not json']]), '200 without JSON');
		$this->assertSame('Places API not reachable: Could not resolve host: places.googleapis.com ([key])', $this->failure([new \RuntimeException('Could not resolve host: places.googleapis.com (' . self::KEY . ')')]), 'transport failure, key scrubbed');
	}

	public function testClientSendsSearchAndDetailsRequests(): void
	{
		$places = new Places($this->ok(), self::KEY);
		$this->assertSame(['id' => 'ChIJTestPlace_Id0', 'name' => 'Example Café'], $places->search('Example Café, Street 1', 'de'), 'search returns the first hit');
		$this->assertSame(Places::ENDPOINT . 'places:searchText', $this->calls[0]['url'], 'search URL');
		$this->assertSame(['textQuery' => 'Example Café, Street 1', 'languageCode' => 'de'], json_decode($this->calls[0]['body'], true), 'search body');
		$this->assertTrue(in_array('X-Goog-Api-Key: ' . self::KEY, $this->calls[0]['headers'], true) && in_array('X-Goog-FieldMask: places.id,places.displayName', $this->calls[0]['headers'], true), 'key and field mask as headers');
		$details = $places->details('ChIJTestPlace_Id0', 'de');
		$this->assertSame(4.8, $details['rating'], 'details decoded');
		$this->assertSame(Places::ENDPOINT . 'places/ChIJTestPlace_Id0?languageCode=de', $this->calls[1]['url'], 'details URL');
		$this->assertNull($this->calls[1]['body'], 'details is a GET');
		$this->assertTrue(in_array('X-Goog-FieldMask: ' . Places::DETAILS_MASK, $this->calls[1]['headers'], true), 'details field mask');
	}

	public function testCliFailuresExitWithOneAndKeepTheKeySecret(): void
	{
		$errors = [];
		foreach ([
			'nothing found' => [['status' => 200, 'body' => '{}']],
			'quota' => [['status' => 429, 'body' => json_encode(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded']])]],
			'details fail' => [
				['status' => 200, 'body' => $this->fixture('places-search.json')],
				['status' => 404, 'body' => json_encode(['error' => ['status' => 'NOT_FOUND', 'message' => 'No such place']])],
			],
			'network' => [new \RuntimeException('timeout ' . self::KEY)],
		] as $what => $script) {
			[$code, $out, $err] = $this->cli($this->env(), $this->transport($script));
			$errors[$what] = $err;
			$this->assertSame(Fetch::EXIT_ERROR, $code, "$what: exit 1");
			$this->assertTrue(trim($err) !== '', "$what: message on stderr");
			$this->assertFalse(str_contains($out . $err, self::KEY), "$what: key not printed");
		}
		$this->assertTrue(str_contains($errors['network'], 'not reachable'), 'network: message');
	}

	public function testCliHelpSaysItOnlyShows(): void
	{
		[$code, $out] = $this->cli([Fetch::ENV_KEY => self::KEY], null, ['koe-fetch', '--help']);
		$this->assertSame(Fetch::EXIT_OK, $code, '--help');
		$this->assertTrue(str_contains($out, 'Usage: koe-fetch') && str_contains($out, 'writes nothing'), 'usage text says it only shows');
	}

	public function testCliLanguageAndQueryOptions(): void
	{
		$this->cli($this->env() + [Fetch::ENV_LANGUAGE => 'de'], $this->ok());
		$this->assertSame('de', json_decode($this->calls[0]['body'], true)['languageCode'], 'KOE_LANGUAGE passed on');
		$this->calls = [];
		$this->cli($this->env(), $this->ok(), ['koe-fetch', '--language=fr', '--query=Other Place']);
		$this->assertSame(['textQuery' => 'Other Place', 'languageCode' => 'fr'], json_decode($this->calls[0]['body'], true), '--query and --language win over the environment');
	}

	public function testCliLookupShowsTheIdsAndWritesNothing(): void
	{
		[$code, $out, $err] = $this->cli($this->env(), $this->ok());
		$this->assertSame(Fetch::EXIT_OK, $code, 'success: exit 0 (' . $err . ')');
		$this->assertTrue(str_contains($out, 'Found: Example Café (ChIJTestPlace_Id0)'), 'success: found line with the id for koe.placeId');
		$this->assertTrue(str_contains($out, 'ChdDSUhNMG9nS0VJQ0FnSUNfQUFB  Anna Beispiel, 2026-07-14, 5 stars'), 'success: ids listed for koe.approved');
		$this->assertTrue(str_contains($out, 'Nothing is written'), 'success: says that nothing is stored');
		$this->assertSame([], array_values(array_diff(scandir($this->tmp), ['.', '..'])), 'success: no file, no directory created');
		$this->assertSame('en', json_decode($this->calls[0]['body'], true)['languageCode'], 'language defaults to en');
		$this->assertFalse(str_contains($out . $err, self::KEY), 'success: key not printed');
	}

	public function testCliWithoutKeyExitsWithThree(): void
	{
		[$code, $out] = $this->cli([], null);
		$this->assertSame(Fetch::EXIT_NO_KEY, $code, 'no key: exit 3');
		$this->assertTrue(str_contains($out, 'No GOOGLE_PLACES_API_KEY set'), 'no key: message names the variable');
	}

	public function testCliWithoutQueryExitsWithOneAndMakesNoRequest(): void
	{
		[$code, $out, $err] = $this->cli([Fetch::ENV_KEY => self::KEY], $this->ok());
		$this->assertSame(Fetch::EXIT_ERROR, $code, 'no query: exit 1');
		$this->assertTrue(str_contains($err, 'KOE_QUERY is missing'), 'no query: message');
		$this->assertSame(0, count($this->calls), 'no query: no request was made');
		$this->assertFalse(str_contains($out . $err, self::KEY), 'no query: key not printed');
	}

	public function testDefaultTransportExists(): void
	{
		// The real transport uses curl when available; no request is made here.
		$this->assertTrue(Places::defaultTransport() instanceof Closure, 'default transport');
	}

	public function testOddAnswersDoNotBreakParsing(): void
	{
		$odd = Places::parse(['reviews' => [
			['rating' => 5, 'text' => ['text' => 'x'], 'authorAttribution' => ['photoUri' => 'javascript:alert(1)']],
			'junk',
			['publishTime' => 'soon', 'originalText' => ['text' => 'y'], 'authorAttribution' => ['photoUri' => '//lh3.example.com/a']],
		]], 'P', '2026-10-05')['data'];
		$this->assertSame([null, 'P', 2], [$odd['rating'], $odd['placeId'], $odd['count']], 'no rating → null, count falls back to the list');
		$this->assertSame(['2026-10-05', '2026-10-05'], array_column($odd['reviews'], 'date'), 'unparsable publishTime → today');
		$this->assertSame([null, null], array_column($odd['reviews'], 'id'), 'no resource name → no id');
		$this->assertSame([], array_column($odd['reviews'], 'photo'), 'only https picture addresses are kept');
	}

	public function testParsesTheRecordedDetails(): void
	{
		['data' => $data, 'messages' => $messages] = Places::parse(json_decode($this->fixture('places-details.json'), true), 'ChIJTestPlace_Id0', '2026-10-05');
		$this->assertSame(['placeId', 'rating', 'count', 'reviews'], array_keys($data), 'only place, rating, count and reviews: no file fields');
		$this->assertSame('ChIJTestPlace_Id0', $data['placeId'], 'place id');
		$this->assertSame([4.8, 201], [$data['rating'], $data['count']], 'rating and count from Google');
		$this->assertSame(4, count($data['reviews']), 'four of five kept (one has no text)');
		$this->assertSame(['Carla Kurz', 'Bernd Vier', 'Anna Beispiel', ''], array_column($data['reviews'], 'name'), 'newest first, empty name kept empty');
		$anna = $data['reviews'][2];
		$this->assertSame('ChdDSUhNMG9nS0VJQ0FnSUNfQUFB', $anna['id'], 'review id = last segment of the resource name');
		$this->assertSame('2026-07-14', $anna['date'], 'date = first ten characters of publishTime');
		$this->assertSame(5, $anna['rating'], 'rating');
		$this->assertTrue(str_starts_with($anna['text'], 'Wunderschönes Café') && str_contains($anna['text'], "\n"), 'original text preferred, line breaks kept');
		$this->assertSame('https://www.google.com/maps/reviews/data=!4m6!14m5!1m4!2m3!1sChdDSUhNMG9nS0VJQ0FnSUNfQUFB!2m1!1s0x0:0x1', $anna['url'], 'link = googleMapsUri');
		$this->assertSame('https://lh3.googleusercontent.com/a/example=s128-c0x00000000-cc-rp-mo', $anna['photo'], 'profile picture address, for the server to fetch');
		$this->assertSame(['id', 'name', 'date', 'rating', 'text', 'url', 'photo'], array_keys($anna), 'no author profile address, no source field');
		$this->assertFalse(array_key_exists('url', $data['reviews'][3]), 'without googleMapsUri no link at all (no author page as a stand-in)');
		$this->assertTrue((bool)array_filter($messages, fn ($m) => str_contains($m, 'skipped (no text): Dora Stumm')), 'skipped review is reported');
		$this->assertTrue(str_contains(end($messages), '4 reviews with text; Google reports 201 with an average of 4.8'), 'summary line');
	}

	public function testUsageNoLongerNamesTheOldOptions(): void
	{
		foreach (['KOE_FILE', 'KOE_PLACE_ID', '--file', '--place-id'] as $gone) {
			$this->assertFalse(str_contains(Fetch::usage(), $gone), "usage no longer names $gone");
		}
	}

	/** A transport that answers from a script: each entry ['status' => …, 'body' => …] or a Throwable. Records every call. */
	private function transport(array $script): Closure
	{
		return function (string $url, array $headers, string|null $body) use (&$script): array {
			$this->calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
			$next = array_shift($script) ?? ['status' => 500, 'body' => 'script exhausted'];
			if ($next instanceof Throwable) {
				throw $next;
			}
			return $next;
		};
	}
}
