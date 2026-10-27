<?php

namespace Sayamaapps\Koe;

use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Kirby\Cms\App;
use Kirby\Http\Response;

/**
 * Between Kirby and the cache: what a page view shows, when the reviews are
 * fetched, and the profile picture route.
 *
 * Fetching on demand: a page view that finds no fitting data in the cache
 * shows no reviews and starts one refresh after its response is sent
 * (fastcgi_finish_request() under PHP-FPM, litespeed_finish_request() under
 * LiteSpeed; elsewhere that one visitor waits for it). The views after it
 * show the reviews until the cache expires.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-koe
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Plugin
{
	/** Kirby's cache for the plugin `sayamaapps/koe` (option `sayamaapps.koe.cache`). */
	public const CACHE = 'sayamaapps.koe';

	/**
	 * The route `koe/avatar/<key>`: a cached profile picture, or 404. The
	 * browser may keep it only as long as the cache. `Expires` is sent next
	 * to `max-age` because Apache's mod_expires leaves a response alone that
	 * already has one; otherwise a site's `ExpiresByType image/png "access
	 * plus 1 year"` would add a year to the picture.
	 */
	public static function avatar(App $kirby, string $id): Response
	{
		$picture = Options::get($kirby, 'active', false) === true
			? Store::avatar($kirby->cache(self::CACHE), $id)
			: null;
		if ($picture === null) {
			return new Response('', 'text/plain', 404);
		}
		return new Response($picture['body'], $picture['type'], 200, [
			'Cache-Control' => 'public, max-age=' . max(0, $picture['expires'] - time()),
			'Expires' => gmdate('D, d M Y H:i:s', max(time(), $picture['expires'])) . ' GMT',
			'X-Content-Type-Options' => 'nosniff',
		]);
	}

	/**
	 * Runs $job after the response has been sent, where the server allows it.
	 * $runner, when given, runs the job instead (the caller decides when).
	 */
	private static function defer(Closure $job, Closure|null $runner = null): void
	{
		if ($runner !== null) {
			$runner($job);
			return;
		}
		register_shutdown_function(function () use ($job): void {
			if (function_exists('fastcgi_finish_request')) {
				fastcgi_finish_request();
			} elseif (function_exists('litespeed_finish_request')) {
				litespeed_finish_request();
			}
			ignore_user_abort(true);
			try {
				$job();
			} catch (\Throwable $e) {
				error_log('koe: fetching the reviews failed: ' . $e->getMessage());
			}
		});
	}

	/**
	 * Fetches the reviews into the cache. Failures go to the error log (also
	 * outside debug mode: they need someone to act), the key scrubbed.
	 *
	 * @param $transport the HTTP client (see Places); null = the real one
	 * @return ?string null when done, otherwise what went wrong
	 */
	public static function refresh(App $kirby, array $rules, Closure|null $transport = null): string|null
	{
		$key = Options::key($kirby);
		$placeId = $rules['placeId'] ?? null;
		if ($key === null || $placeId === null) {
			return 'no key or no place id';
		}
		try {
			$problem = Store::refresh(
				$kirby->cache(self::CACHE),
				$transport ?? Places::defaultTransport(10),
				$key,
				self::settings($kirby, $placeId),
				$rules,
				self::today()
			);
		} catch (\Throwable $e) {
			$problem = Places::scrub($e->getMessage(), $key);
		}
		if ($problem !== null) {
			error_log('koe: fetching the reviews failed: ' . $problem . ' (next attempt in ' . Store::RETRY_MINUTES . ' minutes)');
		}
		return $problem;
	}

	/**
	 * Rating, count and the selected reviews, or null (= show nothing: no
	 * place id, no key, nothing cached yet, or a place without rating).
	 *
	 * @param $transport passed on to refresh() (null = the real one)
	 * @param $defer runs the refresh job instead of deferring it after the response (null = the server way)
	 * @return array{rating: float, count: int, placeId: string, reviews: array<int, array<string, mixed>>, limit: int}|null
	 */
	public static function reviews(App $kirby, array $rules, Closure|null $transport = null, Closure|null $defer = null): array|null
	{
		$placeId = $rules['placeId'] ?? null;
		if ($placeId === null) {
			Options::debugLog($kirby, 'nothing shown: sayamaapps.koe.placeId is not set (koe-fetch shows the id)');
			return null;
		}
		$cache = $kirby->cache(self::CACHE);
		$data = Store::read($cache, Store::fingerprint(self::settings($kirby, $placeId), $rules));
		if ($data === null) {
			// The page without reviews must not stay in Kirby's page cache.
			$kirby->response()->cache(false);
			if (Options::key($kirby) === null) {
				Options::debugLog($kirby, 'nothing shown: no API key (sayamaapps.koe.key or ' . Options::ENV_KEY . ')');
				return null;
			}
			if (Store::claim($cache)) {
				self::defer(fn () => self::refresh($kirby, $rules, $transport), $defer);
			}
			return null;
		}
		// Nor the page with reviews longer than the reviews themselves.
		$kirby->response()->expires($data['expires']);
		if ($data['rating'] === null) {
			return null;
		}
		$reviews = Reviews::select($data['reviews'], $rules, self::today());
		foreach ($reviews as $i => $review) {
			$reviews[$i]['avatar'] = $review['avatar'] === null ? null : $kirby->url() . '/koe/avatar/' . $review['avatar'];
		}
		return [
			'rating'  => (float)$data['rating'],
			'count'   => (int)$data['count'],
			'placeId' => $placeId,
			'reviews' => $reviews,
			'limit'   => (int)$rules['maxCount'],
		];
	}

	/** @return array{placeId: string, language: string, minutes: int} */
	private static function settings(App $kirby, string $placeId): array
	{
		return ['placeId' => $placeId, 'language' => Options::language($kirby), 'minutes' => Options::minutes($kirby)];
	}

	private static function today(): DateTimeImmutable
	{
		return new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
	}
}
