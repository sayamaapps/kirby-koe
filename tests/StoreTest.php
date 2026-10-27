<?php

use DateTimeImmutable;
use DateTimeZone;
use FilesystemIterator;
use Kirby\Cache\FileCache;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Sayamaapps\Koe\Places;
use Sayamaapps\Koe\Reviews;
use Sayamaapps\Koe\Store;

/**
 * The cache: one refresh against recorded Places API answers, what is kept
 * (only the reviews the rules let through, their profile pictures, nothing
 * else), how long, what happens on errors, and that a refresh leaves nothing
 * of the previous one behind. Kirby's FileCache, no Kirby app, no network.
 *   vendor/bin/phpunit
 */
#[CoversClass(\Sayamaapps\Koe\Store::class)]
final class StoreTest extends TestCase
{
	private const ANNA_PHOTO = 'https://lh3.googleusercontent.com/a/example=s128-c0x00000000-cc-rp-mo';
	private const KEY = 'AIza-not-a-real-key-0000';

	private FileCache $cache;

	/** Every request the transport answered. */
	private array $calls = [];
	private string $tmp;
	private DateTimeImmutable $today;

	private function details(): array
	{
		return json_decode((string)file_get_contents(__DIR__ . '/fixtures/places-details.json'), true);
	}

	/** Every file below the cache folder, relative. */
	private function filesIn(string $dir): array
	{
		if (!is_dir($dir)) {
			return [];
		}
		$files = [];
		foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
			$files[] = substr($file->getPathname(), strlen($dir) + 1);
		}
		sort($files);
		return $files;
	}

	private function jpeg(): string
	{
		return "\xFF\xD8\xFF\xE0" . str_repeat('j', 200);
	}

	/** Answers with the raw status and body, as Google does on errors. */
	private function raw(int $status, string $raw): Closure
	{
		return function (string $url, array $headers) use ($status, $raw): array {
			$this->calls[] = ['url' => $url, 'headers' => $headers];
			return ['status' => $status, 'body' => $raw];
		};
	}

	private function settings(): array
	{
		return ['placeId' => 'ChIJTestPlace_Id0', 'language' => 'de', 'minutes' => 60];
	}

	protected function setUp(): void
	{
		$this->tmp = sys_get_temp_dir() . '/koe-store-' . bin2hex(random_bytes(6));
		$this->cache = new FileCache(['root' => $this->tmp, 'prefix' => 'koe', 'extension' => 'cache']);
		$this->today = new DateTimeImmutable('2026-10-05', new DateTimeZone('Europe/Berlin'));
		$this->calls = [];
	}

	protected function tearDown(): void
	{
		Dir::remove($this->tmp);
		Dir::remove($this->tmp . '-race');
	}

	public function testAnswersWithoutTextsOrRatingAreKeptAsTheCurrentState(): void
	{
		$key = self::KEY;
		$rules = Reviews::defaultRules();
		$settings = $this->settings();
		$fingerprint = Store::fingerprint($settings, $rules);
		$problem = Store::refresh($this->cache, $this->transport(['id' => 'ChIJTestPlace_Id0', 'rating' => 2.0, 'userRatingCount' => 9, 'reviews' => []]), $key, $settings, $rules, $this->today);
		$data = Store::read($this->cache, $fingerprint);
		$this->assertSame([null, 2.0, 9, []], [$problem, $data['rating'], $data['count'], $data['reviews']], 'empty answer kept as it is');
		Store::refresh($this->cache, $this->transport(['id' => 'ChIJTestPlace_Id0']), $key, $settings, $rules, $this->today);
		$data = Store::read($this->cache, $fingerprint);
		$this->assertSame([null, 0], [$data['rating'], $data['count']], 'no rating at all: kept (the page shows nothing, and does not ask again on every view)');
	}

	public function testApiErrorIsReportedWithTheKeyScrubbedAndKeepsNothing(): void
	{
		$key = self::KEY;
		$rules = Reviews::defaultRules();
		$settings = $this->settings();
		$fingerprint = Store::fingerprint($settings, $rules);
		$problem = Store::refresh($this->cache, $this->raw(403, json_encode(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'API key not valid: ' . $key]])), $key, $settings, $rules, $this->today);
		$this->assertTrue(str_contains((string)$problem, 'Places API answered 403') && !str_contains((string)$problem, $key), 'API error: reported, key scrubbed');
		$this->assertFalse(Store::claim($this->cache), 'after a failure no new attempt before the retry pause');
		$this->assertNull(Store::read($this->cache, $fingerprint), 'after a failure nothing is shown');
		$this->assertSame(['retry.cache'], $this->filesIn($this->tmp . '/koe'), 'after a failure nothing but the retry marker is left (no old reviews kept)');
	}

	public function testBrokenPicturesAreLeftOutAndThePreviousDataIsGone(): void
	{
		$key = self::KEY;
		$rules = Reviews::defaultRules();
		$settings = $this->settings();
		$fingerprint = Store::fingerprint($settings, $rules);
		$jpeg = $this->jpeg();
		Store::refresh($this->cache, $this->transport($this->details(), [self::ANNA_PHOTO => ['status' => 200, 'body' => $jpeg]]), $key, $settings, $rules, $this->today);
		$previousAvatar = Store::read($this->cache, $fingerprint)['reviews'][0]['avatar'];

		// More lenient rules keep more; a picture that is no image, too big or missing is left out, the review stays.
		$lenient = ['minRating' => 4, 'minLength' => 0, 'maxAgeMonths' => 36, 'maxCount' => 5] + $rules;
		$pictures = [
			self::ANNA_PHOTO => ['status' => 200, 'body' => "\x89PNG\r\n\x1A\n" . str_repeat('p', 200)],
			'https://lh3.googleusercontent.com/a/example2=s128' => ['status' => 200, 'body' => '<html>not an image</html>'],
			'https://lh3.googleusercontent.com/a/example3=s128' => ['status' => 200, 'body' => "\xFF\xD8\xFF" . str_repeat('x', Store::MAX_AVATAR_BYTES)],
			'https://lh3.googleusercontent.com/a/example5=s128' => new RuntimeException('timeout'),
		];
		$problem = Store::refresh($this->cache, $this->transport($this->details(), $pictures), $key, $settings, $lenient, $this->today);
		$this->assertNull($problem, 'refresh with broken pictures still succeeds');
		$data = Store::read($this->cache, Store::fingerprint($settings, $lenient));
		$this->assertSame(['Carla Kurz', 'Bernd Vier', 'Anna Beispiel', ''], array_column($data['reviews'], 'name'), 'lenient rules keep four');
		$this->assertSame([false, false, true, false], array_map(fn ($r) => $r['avatar'] !== null, $data['reviews']), 'only the valid picture is kept');
		$this->assertSame('image/png', Store::avatar($this->cache, $data['reviews'][2]['avatar'])['type'], 'png recognised');
		$this->assertNull(Store::read($this->cache, $fingerprint), 'the previous data is gone');
		$this->assertNull(Store::avatar($this->cache, $previousAvatar), 'the previous picture is gone, not left to linger');
		$this->assertSame(2, count($this->filesIn($this->tmp . '/koe')), 'exactly the data and one picture on disk');
	}

	public function testDetailsAboutAnotherPlaceAreRefused(): void
	{
		$key = self::KEY;
		$rules = Reviews::defaultRules();
		$settings = $this->settings();
		$fingerprint = Store::fingerprint($settings, $rules);
		$moved = $this->details();
		$moved['id'] = 'ChIJExampleMoved';
		$problem = Store::refresh($this->cache, $this->transport($moved), $key, $settings, $rules, $this->today);
		$this->assertTrue(str_contains((string)$problem, 'ChIJExampleMoved') && str_contains((string)$problem, 'ChIJTestPlace_Id0'), 'details about another place: refused, both ids named');
		$this->assertNull(Store::read($this->cache, $fingerprint), 'details about another place: nothing kept');
	}

	public function testEmptyCacheShowsNothingAndOnlyTheFirstVisitorMayRefresh(): void
	{
		$fingerprint = Store::fingerprint($this->settings(), Reviews::defaultRules());
		$this->assertNull(Store::read($this->cache, $fingerprint), 'empty cache: nothing to show');
		$this->assertTrue(Store::claim($this->cache), 'empty cache: the first visitor may start a refresh');
		$this->assertFalse(Store::claim($this->cache), 'a second visitor in the meantime may not (no request storm)');
	}

	public function testExpiredEntryIsGoneOnTheNextRead(): void
	{
		$key = self::KEY;
		$rules = Reviews::defaultRules();
		$settings = $this->settings();
		$fingerprint = Store::fingerprint($settings, $rules);
		Store::refresh($this->cache, $this->transport($this->details(), [self::ANNA_PHOTO => ['status' => 200, 'body' => $this->jpeg()]]), $key, $settings, $rules, $this->today);
		$file = $this->tmp . '/koe/reviews.cache';
		$raw = json_decode((string)file_get_contents($file), true);
		$raw['created'] = time() - 61 * 60;
		file_put_contents($file, json_encode($raw));
		$this->assertNull(Store::read($this->cache, $fingerprint), 'expired: nothing shown');
		$this->assertFalse(is_file($file), 'expired: the file is deleted on that read');
		$this->assertTrue(Store::claim($this->cache), 'expired: the next visitor starts a refresh');
	}

	public function testFingerprintIsStableAndNamesEverySetting(): void
	{
		$rules = Reviews::defaultRules();
		$fingerprint = Store::fingerprint($this->settings(), $rules);
		$this->assertSame($fingerprint, Store::fingerprint($this->settings(), $rules), 'fingerprint is stable');
		foreach (['placeId' => 'ChIJOther', 'language' => 'en', 'minutes' => 30] as $setting => $value) {
			$this->assertFalse(Store::fingerprint([$setting => $value] + $this->settings(), $rules) === $fingerprint, "fingerprint changes with $setting");
		}
		foreach (['minRating' => 4, 'minLength' => 10, 'maxAgeMonths' => 6, 'maxCount' => 5, 'approved' => ['x']] as $rule => $value) {
			$this->assertFalse(Store::fingerprint($this->settings(), [$rule => $value] + $rules) === $fingerprint, "fingerprint changes with the rule $rule");
		}
		$this->assertSame($fingerprint, Store::fingerprint($this->settings(), ['truncate' => 0] + $rules), 'truncate is applied when shown, not when kept');
		$this->assertTrue((bool)preg_match('/^[a-f0-9]+$/', $fingerprint), 'fingerprint is plain hex');
	}

	public function testNetworkErrorIsReportedWithTheKeyScrubbed(): void
	{
		$key = self::KEY;
		$problem = Store::refresh($this->cache, $this->transport(new RuntimeException('Could not resolve host (' . $key . ')')), $key, $this->settings(), Reviews::defaultRules(), $this->today);
		$this->assertTrue(str_contains((string)$problem, 'not reachable') && !str_contains((string)$problem, $key), 'network error: reported, key scrubbed');
	}

	public function testRefreshKeepsOnlyTheReviewsTheRulesLetThrough(): void
	{
		$key = self::KEY;
		$rules = Reviews::defaultRules();
		$settings = $this->settings();
		$fingerprint = Store::fingerprint($settings, $rules);
		$jpeg = $this->jpeg();
		$problem = Store::refresh($this->cache, $this->transport($this->details(), [self::ANNA_PHOTO => ['status' => 200, 'body' => $jpeg]]), $key, $settings, $rules, $this->today);
		$this->assertNull($problem, 'refresh succeeds');
		$this->assertSame(Places::ENDPOINT . 'places/ChIJTestPlace_Id0?languageCode=de', $this->calls[0]['url'], 'one details call for the configured place, in the site language, no text search');
		$this->assertSame([self::ANNA_PHOTO], array_slice(array_column($this->calls, 'url'), 1), 'only the picture of the review that is kept is fetched');
		$this->assertSame([], $this->calls[1]['headers'], 'the picture request carries no key and no other header');
		$data = Store::read($this->cache, $fingerprint);
		$this->assertSame(['ChIJTestPlace_Id0', 4.8, 201], [$data['placeId'], $data['rating'], $data['count']], 'place, rating and count kept');
		$this->assertSame(['Anna Beispiel'], array_column($data['reviews'], 'name'), 'only reviews the rules let through are kept (4 stars, too short, too old are not)');
		$anna = $data['reviews'][0];
		$this->assertSame(['id', 'name', 'date', 'rating', 'text', 'url', 'avatar'], array_keys($anna), 'per review: no picture address of Google, the own avatar key instead');
		$this->assertTrue((bool)preg_match('/^[a-f0-9]{32}$/', $anna['avatar']), 'avatar key is hex');
		$avatar = Store::avatar($this->cache, $anna['avatar']);
		$this->assertSame(['image/jpeg', $jpeg], [$avatar['type'], $avatar['body']], 'the picture is served from the cache with its type');
		$this->assertTrue($avatar['expires'] >= time() + 59 * 60 && $avatar['expires'] <= time() + 60 * 60, 'the picture expires with the reviews');
		$this->assertTrue($data['expires'] >= time() + 59 * 60 && $data['expires'] <= time() + 60 * 60, 'the reviews expire after the configured minutes');
		$this->assertNull(Store::avatar($this->cache, 'ffffffffffffffffffffffffffffffff'), 'unknown avatar: nothing');
		$this->assertNull(Store::avatar($this->cache, '../reviews'), 'avatar key with other characters: nothing');
		$this->assertNull(Store::read($this->cache, Store::fingerprint(['placeId' => 'ChIJOther'] + $settings, $rules)), 'data fetched for other settings is not shown');
		$stored = implode('', array_map(fn ($f) => (string)file_get_contents($this->tmp . '/koe/' . $f), $this->filesIn($this->tmp . '/koe')));
		$this->assertFalse(str_contains($stored, $key), 'the key is never stored');
		$this->assertFalse(str_contains($stored, 'Bernd Vier') || str_contains($stored, 'Carla Kurz') || str_contains($stored, 'googleusercontent'), 'nothing of the reviews not shown, no Google picture address');
	}

	public function testTwoRequestsAtTheSameMomentOnlyOneStartsTheRefresh(): void
	{
		$key = self::KEY;
		$rules = Reviews::defaultRules();
		$settings = $this->settings();
		$raceRoot = $this->tmp . '-race';
		// A file cache that runs afterExists once, right after its first check: the moment another request could slip in.
		$racy = new class (['root' => $raceRoot, 'prefix' => 'koe', 'extension' => 'cache']) extends FileCache {
			public Closure|null $afterExists = null;

			public function exists(string $key): bool
			{
				$found = parent::exists($key);
				if ($this->afterExists !== null) {
					$hook = $this->afterExists;
					$this->afterExists = null;
					$hook();
				}
				return $found;
			}
		};
		$rival = null;
		$racy->afterExists = function () use (&$rival, $raceRoot): void {
			// The other request checks and marks between our check and our mark.
			$rival = Store::claim(new FileCache(['root' => $raceRoot, 'prefix' => 'koe', 'extension' => 'cache']));
		};
		$winner = Store::claim($racy);
		$this->assertSame(1, (int)$winner + (int)$rival, 'two requests at the same moment: exactly one may start the refresh');
		// The winner's refresh ends the lock; it fails here on purpose (nothing to keep).
		Store::refresh($racy, $this->transport(new RuntimeException('Could not resolve host (' . $key . ')')), $key, $settings, $rules, $this->today);
	}

	/** A Google-like answer: the details (an array = 200 with it, or a Throwable) and the pictures. Records every call. */
	private function transport(array|Throwable $answer, array $pictures = []): Closure
	{
		return function (string $url, array $headers) use ($answer, $pictures): array {
			$this->calls[] = ['url' => $url, 'headers' => $headers];
			if (str_starts_with($url, Places::ENDPOINT)) {
				if ($answer instanceof Throwable) {
					throw $answer;
				}
				return ['status' => 200, 'body' => json_encode($answer)];
			}
			$picture = $pictures[$url] ?? ['status' => 404, 'body' => ''];
			if ($picture instanceof Throwable) {
				throw $picture;
			}
			return $picture;
		};
	}
}
