<?php

use Kirby\Cache\FileCache;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Koe\Store;

/**
 * The cache: one refresh against recorded Places API answers, what is kept
 * (only the reviews the rules let through, their profile pictures, nothing
 * else), how long, what happens on errors, and that a refresh leaves nothing
 * of the previous one behind. Kirby's FileCache, no Kirby app, no network.
 *   vendor/bin/phpunit
 */
/** A file cache that runs $afterExists once, right after its first check: the moment another request could slip in. */
final class InterleavedCache extends FileCache
{
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
}

#[CoversClass(\Sayamaapps\Koe\Store::class)]
final class StoreTest extends TestCase
{
	private function expect(mixed $expected, mixed $actual, string $what): void
	{
		Assert::assertSame($expected, $actual, $what);
	}

	/** Every file below $dir, relative. */
	private static function filesIn(string $dir): array
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
		$details = json_decode(file_get_contents(__DIR__ . '/fixtures/places-details.json'), true);
		$key = 'AIza-not-a-real-key-0000';
		$tmp = sys_get_temp_dir() . '/koe-store-' . bin2hex(random_bytes(6));
		$cache = new FileCache(['root' => $tmp, 'prefix' => 'koe', 'extension' => 'cache']);
		$today = new DateTimeImmutable('2026-10-05', new DateTimeZone('Europe/Berlin'));
		$jpeg = "\xFF\xD8\xFF\xE0" . str_repeat('j', 200);
		$png = "\x89PNG\r\n\x1A\n" . str_repeat('p', 200);
		/**
		 * A transport like the real one: the details call answers $answer, picture
		 * addresses answer from $pictures (address → response, a Throwable, or
		 * missing = 404). Records every call.
		 */
		$calls = [];
		$transport = function (array|Throwable $answer, array $pictures = []) use (&$calls): Closure {
			return function (string $url, array $headers, string|null $body) use (&$calls, $answer, $pictures): array {
				$calls[] = ['url' => $url, 'headers' => $headers];
				if (str_starts_with($url, Sayamaapps\Koe\Places::ENDPOINT)) {
					if ($answer instanceof Throwable) {
						throw $answer;
					}
					return $answer;
				}
				$picture = $pictures[$url] ?? ['status' => 404, 'body' => ''];
				if ($picture instanceof Throwable) {
					throw $picture;
				}
				return $picture;
			};
		};
		$ok = fn (array $d) => ['status' => 200, 'body' => json_encode($d)];
		$settings = ['placeId' => 'ChIJTestPlace_Id0', 'language' => 'de', 'minutes' => 60];
		$rules = Sayamaapps\Koe\Reviews::defaultRules();
		$fingerprint = Store::fingerprint($settings, $rules);
		$annaPhoto = 'https://lh3.googleusercontent.com/a/example=s128-c0x00000000-cc-rp-mo';
		// ---- Fingerprint: the settings the data was fetched for ------------------
		$this->expect($fingerprint, Store::fingerprint($settings, $rules), 'fingerprint is stable');
		foreach (['placeId' => 'ChIJOther', 'language' => 'en', 'minutes' => 30] as $setting => $value) {
			$this->expect(false, Store::fingerprint([$setting => $value] + $settings, $rules) === $fingerprint, "fingerprint changes with $setting");
		}
		foreach (['minRating' => 4, 'minLength' => 10, 'maxAgeMonths' => 6, 'maxCount' => 5, 'approved' => ['x']] as $rule => $value) {
			$this->expect(false, Store::fingerprint($settings, [$rule => $value] + $rules) === $fingerprint, "fingerprint changes with the rule $rule");
		}
		$this->expect($fingerprint, Store::fingerprint($settings, ['truncate' => 0] + $rules), 'truncate is applied when shown, not when kept');
		$this->expect(true, (bool)preg_match('/^[a-f0-9]+$/', $fingerprint), 'fingerprint is plain hex');

		// ---- Nothing there yet ----------------------------------------------------
		$this->expect(null, Store::read($cache, $fingerprint), 'empty cache: nothing to show');
		$this->expect(true, Store::claim($cache), 'empty cache: the first visitor may start a refresh');
		$this->expect(false, Store::claim($cache), 'a second visitor in the meantime may not (no request storm)');

		// ---- A refresh -------------------------------------------------------------
		$calls = [];
		$problem = Store::refresh($cache, $transport($ok($details), [$annaPhoto => ['status' => 200, 'body' => $jpeg]]), $key, $settings, $rules, $today);
		$this->expect(null, $problem, 'refresh succeeds');
		$this->expect(Sayamaapps\Koe\Places::ENDPOINT . 'places/ChIJTestPlace_Id0?languageCode=de', $calls[0]['url'], 'one details call for the configured place, in the site language, no text search');
		$this->expect([$annaPhoto], array_slice(array_column($calls, 'url'), 1), 'only the picture of the review that is kept is fetched');
		$this->expect([], $calls[1]['headers'], 'the picture request carries no key and no other header');
		$data = Store::read($cache, $fingerprint);
		$this->expect(['ChIJTestPlace_Id0', 4.8, 201], [$data['placeId'], $data['rating'], $data['count']], 'place, rating and count kept');
		$this->expect(['Anna Beispiel'], array_column($data['reviews'], 'name'), 'only reviews the rules let through are kept (4 stars, too short, too old are not)');
		$anna = $data['reviews'][0];
		$this->expect(['id', 'name', 'date', 'rating', 'text', 'url', 'avatar'], array_keys($anna), 'per review: no picture address of Google, the own avatar key instead');
		$this->expect(true, (bool)preg_match('/^[a-f0-9]{32}$/', $anna['avatar']), 'avatar key is hex');
		$avatar = Store::avatar($cache, $anna['avatar']);
		$this->expect(['image/jpeg', $jpeg], [$avatar['type'], $avatar['body']], 'the picture is served from the cache with its type');
		$this->expect(true, $avatar['expires'] >= time() + 59 * 60 && $avatar['expires'] <= time() + 60 * 60, 'the picture expires with the reviews');
		$this->expect(true, $data['expires'] >= time() + 59 * 60 && $data['expires'] <= time() + 60 * 60, 'the reviews expire after the configured minutes');
		$this->expect(null, Store::avatar($cache, 'ffffffffffffffffffffffffffffffff'), 'unknown avatar: nothing');
		$this->expect(null, Store::avatar($cache, '../reviews'), 'avatar key with other characters: nothing');
		$this->expect(null, Store::read($cache, Store::fingerprint(['placeId' => 'ChIJOther'] + $settings, $rules)), 'data fetched for other settings is not shown');
		$stored = implode('', array_map(fn ($f) => file_get_contents($tmp . '/koe/' . $f), $this->filesIn($tmp . '/koe')));
		$this->expect(false, str_contains($stored, $key), 'the key is never stored');
		$this->expect(false, str_contains($stored, 'Bernd Vier') || str_contains($stored, 'Carla Kurz') || str_contains($stored, 'googleusercontent'), 'nothing of the reviews not shown, no Google picture address');

		// More lenient rules keep more; a picture that is no image, too big or missing is left out, the review stays.
		$lenient = ['minRating' => 4, 'minLength' => 0, 'maxAgeMonths' => 36, 'maxCount' => 5] + $rules;
		$pictures = [
			$annaPhoto => ['status' => 200, 'body' => $png],
			'https://lh3.googleusercontent.com/a/example2=s128' => ['status' => 200, 'body' => '<html>not an image</html>'],
			'https://lh3.googleusercontent.com/a/example3=s128' => ['status' => 200, 'body' => "\xFF\xD8\xFF" . str_repeat('x', Store::MAX_AVATAR_BYTES)],
			'https://lh3.googleusercontent.com/a/example5=s128' => new RuntimeException('timeout'),
		];
		$problem = Store::refresh($cache, $transport($ok($details), $pictures), $key, $settings, $lenient, $today);
		$this->expect(null, $problem, 'refresh with broken pictures still succeeds');
		$data = Store::read($cache, Store::fingerprint($settings, $lenient));
		$this->expect(['Carla Kurz', 'Bernd Vier', 'Anna Beispiel', ''], array_column($data['reviews'], 'name'), 'lenient rules keep four');
		$this->expect([false, false, true, false], array_map(fn ($r) => $r['avatar'] !== null, $data['reviews']), 'only the valid picture is kept');
		$this->expect('image/png', Store::avatar($cache, $data['reviews'][2]['avatar'])['type'], 'png recognised');
		$this->expect(null, Store::read($cache, $fingerprint), 'the previous data is gone');
		$this->expect(null, Store::avatar($cache, $anna['avatar']), 'the previous picture is gone, not left to linger');
		$this->expect(2, count($this->filesIn($tmp . '/koe')), 'exactly the data and one picture on disk');

		// ---- Errors ------------------------------------------------------------------
		$before = $this->filesIn($tmp . '/koe');
		$problem = Store::refresh($cache, $transport(['status' => 403, 'body' => json_encode(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'API key not valid: ' . $key]])]), $key, $settings, $lenient, $today);
		$this->expect(true, str_contains((string)$problem, 'Places API answered 403') && !str_contains((string)$problem, $key), 'API error: reported, key scrubbed');
		$this->expect(false, Store::claim($cache), 'after a failure no new attempt before the retry pause');
		$this->expect(null, Store::read($cache, Store::fingerprint($settings, $lenient)), 'after a failure nothing is shown');
		$this->expect(['retry.cache'], $this->filesIn($tmp . '/koe'), 'after a failure nothing but the retry marker is left (no old reviews kept)');

		$problem = Store::refresh($cache, $transport(new RuntimeException('Could not resolve host (' . $key . ')')), $key, $settings, $rules, $today);
		$this->expect(true, str_contains((string)$problem, 'not reachable') && !str_contains((string)$problem, $key), 'network error: reported, key scrubbed');

		$moved = $details;
		$moved['id'] = 'ChIJExampleMoved';
		$problem = Store::refresh($cache, $transport($ok($moved)), $key, $settings, $rules, $today);
		$this->expect(true, str_contains((string)$problem, 'ChIJExampleMoved') && str_contains((string)$problem, 'ChIJTestPlace_Id0'), 'details about another place: refused, both ids named');
		$this->expect(null, Store::read($cache, $fingerprint), 'details about another place: nothing kept');

		// An answer without review texts, or without any rating, is the current state too.
		$problem = Store::refresh($cache, $transport($ok(['id' => 'ChIJTestPlace_Id0', 'rating' => 2.0, 'userRatingCount' => 9, 'reviews' => []])), $key, $settings, $rules, $today);
		$data = Store::read($cache, $fingerprint);
		$this->expect([null, 2.0, 9, []], [$problem, $data['rating'], $data['count'], $data['reviews']], 'empty answer kept as it is');
		Store::refresh($cache, $transport($ok(['id' => 'ChIJTestPlace_Id0'])), $key, $settings, $rules, $today);
		$data = Store::read($cache, $fingerprint);
		$this->expect([null, 0], [$data['rating'], $data['count']], 'no rating at all: kept (the page shows nothing, and does not ask again on every view)');

		// ---- Expiry: an expired entry is gone on the next read ------------------------
		Store::refresh($cache, $transport($ok($details), [$annaPhoto => ['status' => 200, 'body' => $jpeg]]), $key, $settings, $rules, $today);
		$file = $tmp . '/koe/reviews.cache';
		$raw = json_decode(file_get_contents($file), true);
		$raw['created'] = time() - 61 * 60;
		file_put_contents($file, json_encode($raw));
		$this->expect(null, Store::read($cache, $fingerprint), 'expired: nothing shown');
		$this->expect(false, is_file($file), 'expired: the file is deleted on that read');
		$this->expect(true, Store::claim($cache), 'expired: the next visitor starts a refresh');

		// ---- Two requests at the same moment: only one may start the refresh ----------
		$raceRoot = $tmp . '-race';
		$racy = new InterleavedCache(['root' => $raceRoot, 'prefix' => 'koe', 'extension' => 'cache']);
		$rival = null;
		$racy->afterExists = function () use (&$rival, $raceRoot): void {
			// The other request checks and marks between our check and our mark.
			$rival = Store::claim(new FileCache(['root' => $raceRoot, 'prefix' => 'koe', 'extension' => 'cache']));
		};
		$winner = Store::claim($racy);
		$this->expect(1, (int)$winner + (int)$rival, 'two requests at the same moment: exactly one may start the refresh');
		// The winner's refresh ends the lock; it fails here on purpose (nothing to keep).
		Store::refresh($racy, $transport(new RuntimeException('Could not resolve host (' . $key . ')')), $key, $settings, $rules, $today);
		$this->removeTree($raceRoot);
	}
}
