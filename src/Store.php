<?php

namespace Sayamaapps\Koe;

use Closure;
use DateTimeImmutable;
use Kirby\Cache\Cache;
use Kirby\Cache\FileCache;

/**
 * The short-lived server-side cache of the reviews — the only place Koe keeps
 * anything from Google. Kirby's plugin cache (`sayamaapps.koe`, by default
 * files under site/cache/), so it is not in git, not deployed and not part
 * of a backup of content/ or storage/.
 *
 * - `reviews`: place id, rating, count and only the reviews the rules let
 *   through (Reviews::filter()), each with the key of its profile picture.
 * - `avatar-<key>`: one profile picture, fetched by the server and served by
 *   the site's own route, so the visitor's browser never asks Google.
 * - `retry`: set while a refresh runs and after it failed, so neither a crowd
 *   of visitors nor a broken key sends a request on every page view.
 *
 * Everything expires after the configured minutes. A refresh first empties
 * the whole cache, so nothing of an earlier answer survives it (a picture of
 * a review that is gone included). Kirby removes an expired entry when it is
 * read, which happens on the next page view.
 *
 * @package   Sayamaapps\Koe
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-koe
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Store
{
	public const DATA = 'reviews';

	/** Profile pictures above this size are not kept (Google's are a few KB). */
	public const MAX_AVATAR_BYTES = 262144;
	public const RETRY = 'retry';

	/** Pause after a failed refresh (and the longest a running one may take). */
	public const RETRY_MINUTES = 15;

	/** Changes when the cached structure changes, so an old entry is fetched anew. */
	private const VERSION = 1;

	/** Lock of the refresh this process runs; held from claim() until refresh() has ended. */
	private static mixed $lock = null;

	/**
	 * A cached profile picture, or null.
	 *
	 * @return array{type: string, body: string, expires: int}|null
	 */
	public static function avatar(Cache $cache, string $id): array|null
	{
		if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
			return null;
		}
		$picture = $cache->get('avatar-' . $id);
		$body = is_array($picture) ? base64_decode((string)($picture['body'] ?? ''), true) : false;
		$type = $body === false ? null : self::type($body);
		if ($body === false || $type === null) {
			return null;
		}
		return ['type' => $type, 'body' => $body, 'expires' => (int)$cache->expires('avatar-' . $id)];
	}

	/**
	 * Whether this request may start a refresh (it found no data that fits).
	 * True at most once per RETRY_MINUTES, and never while another request
	 * checks or refreshes: the check and the mark happen under a lock that
	 * the claimer keeps until refresh() has ended. Without a file cache there
	 * is no file to lock, and the check is not atomic.
	 */
	public static function claim(Cache $cache): bool
	{
		$lock = self::openLock($cache);
		if ($lock === false) {
			return false;
		}
		if ($cache->exists(self::RETRY)) {
			self::closeLock($lock);
			return false;
		}
		$cache->set(self::RETRY, true, self::RETRY_MINUTES);
		self::$lock = $lock;
		return true;
	}

	/** @param resource|false|null $handle */
	private static function closeLock($handle): void
	{
		if (is_resource($handle)) {
			flock($handle, LOCK_UN);
			fclose($handle);
		}
	}

	private static function fail(Cache $cache, string $problem): string
	{
		$cache->flush();
		$cache->set(self::RETRY, true, self::RETRY_MINUTES);
		return $problem;
	}

	/**
	 * The settings and rules the cached data must have been fetched for. The
	 * text length cut (`truncate`) is applied when shown and does not count.
	 *
	 * @param array{placeId: string, language: string, minutes: int} $settings
	 */
	public static function fingerprint(array $settings, array $rules): string
	{
		$rules += Reviews::defaultRules();
		return md5((string)json_encode([
			self::VERSION,
			$settings['placeId'], $settings['language'], $settings['minutes'],
			$rules['minRating'], $rules['minLength'], $rules['maxAgeMonths'], $rules['maxCount'], $rules['approved'],
		]));
	}

	/**
	 * The lock file of a file cache, taken without waiting: false when another
	 * request holds it, null when there is no file to lock. The file lies
	 * beside the cache directory, not in it, because flush() removes that
	 * directory. The operating system drops the lock when the process ends,
	 * so a crashed refresh cannot keep it.
	 *
	 * @return resource|false|null
	 */
	private static function openLock(Cache $cache)
	{
		if (!$cache instanceof FileCache) {
			return null;
		}
		// A missing directory is reported by the null return, not by a warning.
		set_error_handler(static fn (): bool => true);
		try {
			$handle = fopen($cache->root() . '.lock', 'c');
		} finally {
			restore_error_handler();
		}
		if ($handle === false) {
			return null;
		}
		if (!flock($handle, LOCK_EX | LOCK_NB)) {
			fclose($handle);
			return false;
		}
		return $handle;
	}

	/** Fetches a picture without any header; null unless it is a small JPEG, PNG, GIF or WebP. */
	private static function picture(Closure $transport, string $url): array|null
	{
		try {
			$answer = $transport($url, [], null);
		} catch (\Throwable) {
			return null;
		}
		$body = (string)($answer['body'] ?? '');
		if ((int)($answer['status'] ?? 0) !== 200 || strlen($body) > self::MAX_AVATAR_BYTES || self::type($body) === null) {
			return null;
		}
		// Base64: the cache stores JSON, which cannot hold raw bytes.
		return ['body' => base64_encode($body)];
	}

	/**
	 * The cached data, or null when there is none, it expired or it was
	 * fetched for other settings.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function read(Cache $cache, string $fingerprint): array|null
	{
		$data = $cache->get(self::DATA);
		if (!is_array($data) || ($data['fingerprint'] ?? null) !== $fingerprint || !is_array($data['reviews'] ?? null)) {
			return null;
		}
		unset($data['fingerprint']);
		// JSON turns 2.0 into 2.
		$data['rating'] = is_numeric($data['rating'] ?? null) ? (float)$data['rating'] : null;
		$data['count'] = (int)($data['count'] ?? 0);
		$data['expires'] = (int)$cache->expires(self::DATA);
		return $data;
	}

	/**
	 * Fetches the place's details, keeps what the rules let through with the
	 * profile pictures, and replaces the whole cache with it. On an error the
	 * cache is emptied and the retry marker set.
	 *
	 * @param $transport see Places; also fetches the pictures, without any header
	 * @param array{placeId: string, language: string, minutes: int} $settings
	 * @return ?string null when done, otherwise what went wrong (the key scrubbed)
	 */
	public static function refresh(Cache $cache, Closure $transport, string $key, array $settings, array $rules, DateTimeImmutable $today): string|null
	{
		try {
			return self::replace($cache, $transport, $key, $settings, $rules, $today);
		} finally {
			self::closeLock(self::$lock);
			self::$lock = null;
		}
	}

	private static function replace(Cache $cache, Closure $transport, string $key, array $settings, array $rules, DateTimeImmutable $today): string|null
	{
		$placeId = $settings['placeId'];
		try {
			$details = (new Places($transport, $key))->details($placeId, $settings['language']);
		} catch (PlacesException $e) {
			return self::fail($cache, Places::scrub($e->getMessage(), $key));
		}
		// The place the details are about must be the configured one.
		$answered = trim((string)($details['id'] ?? ''));
		if ($answered !== '' && $answered !== $placeId) {
			return self::fail($cache, 'Google answered for place ' . $answered . ', but sayamaapps.koe.placeId is ' . $placeId
				. ' — the listing may have been recreated; check the id with koe-fetch');
		}

		$data = Places::parse($details, $placeId, $today->format('Y-m-d'))['data'];
		$pictures = [];
		$kept = [];
		foreach (Reviews::filter($data['reviews'], $rules, $today) as $review) {
			$review['avatar'] = null;
			$picture = isset($review['photo']) ? self::picture($transport, $review['photo']) : null;
			if ($picture !== null) {
				// Named by its content: says nothing about the author or Google's address.
				$review['avatar'] = substr(hash('sha256', $picture['body']), 0, 32);
				$pictures[$review['avatar']] = $picture;
			}
			unset($review['photo']);
			$kept[] = $review;
		}

		$cache->flush();
		$minutes = max(1, (int)$settings['minutes']);
		foreach ($pictures as $id => $picture) {
			$cache->set('avatar-' . $id, $picture, $minutes);
		}
		$cache->set(self::DATA, [
			'fingerprint' => self::fingerprint($settings, $rules),
			'placeId'     => $placeId,
			'rating'      => $data['rating'],
			'count'       => $data['count'],
			'reviews'     => $kept,
		], $minutes);
		return null;
	}

	/** Image type from the first bytes; the server never trusts a declared type. */
	private static function type(string $body): string|null
	{
		return match (true) {
			str_starts_with($body, "\xFF\xD8\xFF") => 'image/jpeg',
			str_starts_with($body, "\x89PNG\r\n\x1A\n") => 'image/png',
			str_starts_with($body, 'GIF87a'), str_starts_with($body, 'GIF89a') => 'image/gif',
			str_starts_with($body, 'RIFF') && substr($body, 8, 4) === 'WEBP' => 'image/webp',
			default => null,
		};
	}
}
