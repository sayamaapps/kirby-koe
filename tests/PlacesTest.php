<?php

use Koe\Fetch;
use Koe\Places;
use Koe\PlacesException;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * The Places client and the koe-fetch lookup against recorded Places API
 * answers, without a network: parsing, and every failure path (no key, no
 * query, nothing found, quota, API error, transport error). The CLI only
 * shows; it writes nothing. The key must never appear in any output.
 *   vendor/bin/phpunit
 */
#[CoversClass(\Koe\Fetch::class)]
#[CoversClass(\Koe\Places::class)]
#[CoversClass(\Koe\PlacesException::class)]
final class PlacesTest extends TestCase
{
	private function expect(mixed $expected, mixed $actual, string $what): void
	{
		Assert::assertSame($expected, $actual, $what);
	}

	private function holds(bool $ok, string $what): void
	{
		Assert::assertTrue($ok, $what);
	}

	public function testSuite(): void
	{
		$search = file_get_contents(__DIR__ . '/fixtures/places-search.json');
		$details = file_get_contents(__DIR__ . '/fixtures/places-details.json');
		$key = 'AIza-not-a-real-key-0000';
		$tmp = sys_get_temp_dir() . '/koe-fetch-' . bin2hex(random_bytes(6));
		mkdir($tmp, 0700);
		/** A transport that answers from a script: each entry ['status' => …, 'body' => …] or a Throwable. Records every call. */
		$calls = [];
		$transport = function (array $script) use (&$calls): Closure {
			return function (string $url, array $headers, string|null $body) use (&$calls, &$script): array {
				$calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
				$next = array_shift($script) ?? ['status' => 500, 'body' => 'script exhausted'];
				if ($next instanceof Throwable) {
					throw $next;
				}
				return $next;
			};
		};
		$ok = fn () => $transport([['status' => 200, 'body' => $search], ['status' => 200, 'body' => $details]]);
		/** Runs Fetch::run with captured output, in an empty working directory. */
		$run = function (array $env, Closure|null $t, array $argv = ['koe-fetch']) use ($tmp): array {
			$out = fopen('php://memory', 'w+');
			$err = fopen('php://memory', 'w+');
			$cwd = getcwd();
			chdir($tmp);
			try {
				$code = Fetch::run($env, $argv, $t, $out, $err, '2026-10-05');
			} finally {
				chdir($cwd);
			}
			rewind($out);
			rewind($err);
			return [$code, stream_get_contents($out), stream_get_contents($err)];
		};
		$env = [Fetch::ENV_KEY => $key, Fetch::ENV_QUERY => 'Example Café, Street 1, 12345 Town'];
		// ---- Parsing the recorded answer ------------------------------------
		['data' => $data, 'messages' => $messages] = Places::parse(json_decode($details, true), 'ChIJTestPlace_Id0', '2026-10-05');
		$this->expect(['placeId', 'rating', 'count', 'reviews'], array_keys($data), 'only place, rating, count and reviews: no file fields');
		$this->expect('ChIJTestPlace_Id0', $data['placeId'], 'place id');
		$this->expect([4.8, 201], [$data['rating'], $data['count']], 'rating and count from Google');
		$this->expect(4, count($data['reviews']), 'four of five kept (one has no text)');
		$this->expect(['Carla Kurz', 'Bernd Vier', 'Anna Beispiel', ''], array_column($data['reviews'], 'name'), 'newest first, empty name kept empty');
		$anna = $data['reviews'][2];
		$this->expect('ChdDSUhNMG9nS0VJQ0FnSUNfQUFB', $anna['id'], 'review id = last segment of the resource name');
		$this->expect('2026-07-14', $anna['date'], 'date = first ten characters of publishTime');
		$this->expect(5, $anna['rating'], 'rating');
		$this->expect(true, str_starts_with($anna['text'], 'Wunderschönes Café') && str_contains($anna['text'], "\n"), 'original text preferred, line breaks kept');
		$this->expect('https://www.google.com/maps/reviews/data=!4m6!14m5!1m4!2m3!1sChdDSUhNMG9nS0VJQ0FnSUNfQUFB!2m1!1s0x0:0x1', $anna['url'], 'link = googleMapsUri');
		$this->expect('https://lh3.googleusercontent.com/a/example=s128-c0x00000000-cc-rp-mo', $anna['photo'], 'profile picture address, for the server to fetch');
		$this->expect(['id', 'name', 'date', 'rating', 'text', 'url', 'photo'], array_keys($anna), 'no author profile address, no source field');
		$noMaps = $data['reviews'][3];
		$this->expect(false, array_key_exists('url', $noMaps), 'without googleMapsUri no link at all (no author page as a stand-in)');
		$this->expect(true, (bool)array_filter($messages, fn ($m) => str_contains($m, 'skipped (no text): Dora Stumm')), 'skipped review is reported');
		$this->expect(true, str_contains(end($messages), '4 reviews with text; Google reports 201 with an average of 4.8'), 'summary line');
		// Odd answers do not break parsing.
		$odd = Places::parse(['reviews' => [['rating' => 5, 'text' => ['text' => 'x'], 'authorAttribution' => ['photoUri' => 'javascript:alert(1)']], 'junk', ['publishTime' => 'soon', 'originalText' => ['text' => 'y'], 'authorAttribution' => ['photoUri' => '//lh3.example.com/a']]]], 'P', '2026-10-05')['data'];
		$this->expect([null, 'P', 2], [$odd['rating'], $odd['placeId'], $odd['count']], 'no rating → null, count falls back to the list');
		$this->expect(['2026-10-05', '2026-10-05'], array_column($odd['reviews'], 'date'), 'unparsable publishTime → today');
		$this->expect([null, null], array_column($odd['reviews'], 'id'), 'no resource name → no id');
		$this->expect([], array_column($odd['reviews'], 'photo'), 'only https picture addresses are kept');

		// ---- The client: requests and errors ----------------------------------
		$calls = [];
		$places = new Places($ok(), $key);
		$this->expect(['id' => 'ChIJTestPlace_Id0', 'name' => 'Example Café'], $places->search('Example Café, Street 1', 'de'), 'search returns the first hit');
		$this->expect(Places::ENDPOINT . 'places:searchText', $calls[0]['url'], 'search URL');
		$this->expect(['textQuery' => 'Example Café, Street 1', 'languageCode' => 'de'], json_decode($calls[0]['body'], true), 'search body');
		$this->expect(true, in_array('X-Goog-Api-Key: ' . $key, $calls[0]['headers'], true) && in_array('X-Goog-FieldMask: places.id,places.displayName', $calls[0]['headers'], true), 'key and field mask as headers');
		$d = $places->details('ChIJTestPlace_Id0', 'de');
		$this->expect(4.8, $d['rating'], 'details decoded');
		$this->expect(Places::ENDPOINT . 'places/ChIJTestPlace_Id0?languageCode=de', $calls[1]['url'], 'details URL');
		$this->expect(null, $calls[1]['body'], 'details is a GET');
		$this->expect(true, in_array('X-Goog-FieldMask: ' . Places::DETAILS_MASK, $calls[1]['headers'], true), 'details field mask');

		$fail = function (array $script, string $what) use ($transport, $key): string {
			try {
				(new Places($transport($script), $key))->search('x');
				throw new RuntimeException("$what: no exception");
			} catch (PlacesException $e) {
				return $e->getMessage();
			}
		};
		$m = $fail([['status' => 200, 'body' => '{"places": []}']], 'empty search');
		$this->expect('Places search found nothing for: x', $m, 'nothing found');
		$m = $fail([['status' => 403, 'body' => json_encode(['error' => ['code' => 403, 'message' => 'API key not valid: ' . $key, 'status' => 'PERMISSION_DENIED']])]], '403');
		$this->expect('Places API answered 403 PERMISSION_DENIED API key not valid: [key]', $m, '403 with Google status and message, key scrubbed');
		$m = $fail([['status' => 429, 'body' => json_encode(['error' => ['code' => 429, 'message' => 'Quota exceeded for quota metric', 'status' => 'RESOURCE_EXHAUSTED']])]], '429');
		$this->expect(true, str_starts_with($m, 'Places API answered 429 RESOURCE_EXHAUSTED Quota exceeded') && str_contains($m, 'daily quota'), 'quota: status plus hint');
		$m = $fail([['status' => 502, 'body' => '<html>Bad Gateway</html>']], '502');
		$this->expect('Places API answered 502', $m, 'non-JSON error body: status only');
		$m = $fail([['status' => 200, 'body' => 'not json']], 'bad body');
		$this->expect('Places API answered 200 with a body that is not JSON.', $m, '200 without JSON');
		$m = $fail([new RuntimeException('Could not resolve host: places.googleapis.com (' . $key . ')')], 'transport');
		$this->expect('Places API not reachable: Could not resolve host: places.googleapis.com ([key])', $m, 'transport failure, key scrubbed');

		// ---- The CLI: a lookup, it writes nothing ------------------------------
		[$code, $out] = $run([], null);
		$this->expect(Fetch::EXIT_NO_KEY, $code, 'no key: exit 3');
		$this->expect(true, str_contains($out, 'No GOOGLE_PLACES_API_KEY set'), 'no key: message names the variable');

		$calls = [];
		[$code, $out, $err] = $run([Fetch::ENV_KEY => $key], $ok());
		$this->expect(Fetch::EXIT_ERROR, $code, 'no query: exit 1');
		$this->expect(true, str_contains($err, 'KOE_QUERY is missing'), 'no query: message');
		$this->expect(0, count($calls), 'no query: no request was made');
		$this->expect(false, str_contains($out . $err, $key), 'no query: key not printed');

		[$code, $out] = $run([Fetch::ENV_KEY => $key], null, ['koe-fetch', '--help']);
		$this->expect(Fetch::EXIT_OK, $code, '--help');
		$this->expect(true, str_contains($out, 'Usage: koe-fetch') && str_contains($out, 'writes nothing'), 'usage text says it only shows');

		$calls = [];
		[$code, $out, $err] = $run($env, $ok());
		$this->expect(Fetch::EXIT_OK, $code, 'success: exit 0 (' . $err . ')');
		$this->expect(true, str_contains($out, 'Found: Example Café (ChIJTestPlace_Id0)'), 'success: found line with the id for koe.placeId');
		$this->expect(true, str_contains($out, 'ChdDSUhNMG9nS0VJQ0FnSUNfQUFB  Anna Beispiel, 2026-07-14, 5 stars'), 'success: ids listed for koe.approved');
		$this->expect(true, str_contains($out, 'Nothing is written'), 'success: says that nothing is stored');
		$this->expect([], array_values(array_diff(scandir($tmp), ['.', '..'])), 'success: no file, no directory created');
		$this->expect('en', json_decode($calls[0]['body'], true)['languageCode'], 'language defaults to en');
		$this->expect(false, str_contains($out . $err, $key), 'success: key not printed');

		$calls = [];
		[$code] = $run($env + [Fetch::ENV_LANGUAGE => 'de'], $ok());
		$this->expect('de', json_decode($calls[0]['body'], true)['languageCode'], 'KOE_LANGUAGE passed on');
		$calls = [];
		[$code] = $run($env, $ok(), ['koe-fetch', '--language=fr', '--query=Other Place']);
		$this->expect(['textQuery' => 'Other Place', 'languageCode' => 'fr'], json_decode($calls[0]['body'], true), '--query and --language win over the environment');

		$empty = json_encode(['id' => 'ChIJTestPlace_Id0', 'rating' => 2.0, 'userRatingCount' => 9, 'reviews' => []]);
		[$code, $out] = $run($env, $transport([['status' => 200, 'body' => $search], ['status' => 200, 'body' => $empty]]));
		$this->expect(true, $code === Fetch::EXIT_OK && str_contains($out, 'Google returned no usable reviews'), 'empty answer: exit 0, said so');

		// Failures end with 1, the key stays secret.
		foreach ([
			'nothing found' => [['status' => 200, 'body' => '{}']],
			'quota' => [['status' => 429, 'body' => json_encode(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded']])]],
			'details fail' => [['status' => 200, 'body' => $search], ['status' => 404, 'body' => json_encode(['error' => ['status' => 'NOT_FOUND', 'message' => 'No such place']])]],
			'network' => [new RuntimeException('timeout ' . $key)],
		] as $what => $script) {
			[$code, $out, $err] = $run($env, $transport($script));
			$this->expect(Fetch::EXIT_ERROR, $code, "$what: exit 1");
			$this->expect(true, trim($err) !== '', "$what: message on stderr");
			$this->expect(false, str_contains($out . $err, $key), "$what: key not printed");
		}
		$this->expect(true, str_contains($err, 'not reachable'), 'network: message');
		foreach (['KOE_FILE', 'KOE_PLACE_ID', '--file', '--place-id'] as $gone) {
			$this->expect(false, str_contains(Fetch::usage(), $gone), "usage no longer names $gone");
		}

		// The real transport exists and uses curl when available (no request is made here).
		$this->expect(true, Places::defaultTransport() instanceof Closure, 'default transport');
	}
}
