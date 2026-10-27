<?php

namespace Sayamaapps\Koe;

use Closure;
use RuntimeException;

/**
 * Places API (New): find the place by text, read rating, count and reviews,
 * and turn the answer into what Koe keeps (parse()).
 *
 * The transport is a closure (url, headers, body|null) → ['status' => int,
 * 'body' => string], so tests run against recorded answers without a
 * network. The real one (defaultTransport()) uses ext-curl when present,
 * otherwise PHP's HTTP stream wrapper.
 *
 * Errors are PlacesException with a message that never contains the request
 * or the key: only Google's status and message, or the transport failure.
 *
 * @package   Sayamaapps\Koe
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-koe
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Places
{
	/** Fields the details call asks for. `reviews` returns all sub-fields, including googleMapsUri. */
	public const DETAILS_MASK = 'id,displayName,rating,userRatingCount,reviews';
	public const ENDPOINT = 'https://places.googleapis.com/v1/';

	public function __construct(private readonly Closure $transport, private readonly string $key)
	{
	}

	/** @throws PlacesException */
	private function call(string $path, string $fieldMask, array|null $body = null): array
	{
		$headers = [
			'Content-Type: application/json',
			'X-Goog-Api-Key: ' . $this->key,
			'X-Goog-FieldMask: ' . $fieldMask,
		];
		try {
			$answer = ($this->transport)(self::ENDPOINT . $path, $headers, $body === null ? null : json_encode($body, JSON_THROW_ON_ERROR));
		} catch (PlacesException $e) {
			throw $e;
		} catch (\Throwable $e) {
			throw new PlacesException('Places API not reachable: ' . self::scrub($e->getMessage(), $this->key));
		}
		$status = (int)($answer['status'] ?? 0);
		$decoded = json_decode((string)($answer['body'] ?? ''), true);
		if ($status !== 200) {
			$error = is_array($decoded) && is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
			$detail = trim((string)($error['status'] ?? '') . ' ' . (string)($error['message'] ?? ''));
			$hint = $status === 429 || ($error['status'] ?? '') === 'RESOURCE_EXHAUSTED'
				? ' — the daily quota of the Google Cloud project is used up.'
				: '';
			throw new PlacesException('Places API answered ' . $status . ($detail !== '' ? ' ' . self::scrub($detail, $this->key) : '') . $hint);
		}
		if (!is_array($decoded)) {
			throw new PlacesException('Places API answered 200 with a body that is not JSON.');
		}
		return $decoded;
	}

	/** ext-curl when available, otherwise the HTTP stream wrapper. */
	public static function defaultTransport(int $timeout = 30): Closure
	{
		return function (string $url, array $headers, string|null $body) use ($timeout): array {
			if (function_exists('curl_init')) {
				$ch = curl_init($url);
				if ($ch === false) {
					throw new RuntimeException('curl could not start');
				}
				$options = [
					CURLOPT_RETURNTRANSFER => true,
					CURLOPT_HTTPHEADER => $headers,
					CURLOPT_TIMEOUT => $timeout,
				];
				if ($body === null) {
					// Never set CURLOPT_POSTFIELDS for a GET: libcurl switches the
					// method to POST as soon as the option is set, even to an empty
					// string — Google then answers 404 for places/{id} (seen 10.10.2026).
					$options[CURLOPT_HTTPGET] = true;
				} else {
					$options[CURLOPT_POST] = true;
					$options[CURLOPT_POSTFIELDS] = $body;
				}
				curl_setopt_array($ch, $options);
				$response = curl_exec($ch);
				if ($response === false) {
					$error = curl_error($ch);
					curl_close($ch);
					throw new RuntimeException($error);
				}
				$status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
				curl_close($ch);
				return ['status' => $status, 'body' => (string)$response];
			}
			$context = stream_context_create(['http' => [
				'method' => $body === null ? 'GET' : 'POST',
				'header' => implode("\r\n", $headers),
				'content' => $body ?? '',
				'timeout' => $timeout,
				'ignore_errors' => true,
			]]);
			// Warnings are collected instead of silenced: the first one says why the request failed.
			$warnings = [];
			set_error_handler(static function (int $level, string $message) use (&$warnings): bool {
				$warnings[] = [$level, $message];
				return true;
			});
			try {
				$response = file_get_contents($url, false, $context);
			} finally {
				restore_error_handler();
			}
			if ($response === false) {
				throw new RuntimeException($warnings === [] ? 'request failed' : $warnings[0][1]);
			}
			$status = 0;
			foreach ($http_response_header ?? [] as $line) {
				if (preg_match('~^HTTP/\S+\s+(\d{3})~', $line, $m)) {
					$status = (int)$m[1];
				}
			}
			return ['status' => $status, 'body' => $response];
		};
	}

	/**
	 * Details of a place (rating, count, reviews), decoded.
	 *
	 * @throws PlacesException
	 */
	public function details(string $placeId, string $language = 'en'): array
	{
		return $this->call('places/' . rawurlencode($placeId) . '?languageCode=' . rawurlencode($language), self::DETAILS_MASK);
	}

	/**
	 * Rating, count and the reviews with text from a details answer. Always
	 * the complete current answer, nothing merged with earlier ones. Reviews
	 * without text are skipped visibly — they are worthless as a quote and
	 * already part of Google's count. An answer without any usable review is
	 * a complete answer too: the list is simply empty.
	 *
	 * Per review: `id`, `name`, `date`, `rating`, `text`, `url` (googleMapsUri,
	 * only when Google sends one) and `photo` (the profile picture address,
	 * https only), which the refresh fetches and replaces by its own copy.
	 *
	 * @return array{data: array{placeId: string, rating: ?float, count: int, reviews: list<array<string,mixed>>}, messages: list<string>}
	 */
	public static function parse(array $details, string $placeId, string $today): array
	{
		$messages = [];
		$reviews = [];
		foreach ((array)($details['reviews'] ?? []) as $review) {
			if (!is_array($review)) {
				continue;
			}
			$author = is_array($review['authorAttribution'] ?? null) ? $review['authorAttribution'] : [];
			$name = trim((string)($author['displayName'] ?? ''));
			$date = substr((string)($review['publishTime'] ?? ''), 0, 10);
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
				$date = $today;
			}
			$text = trim((string)(($review['originalText'] ?? $review['text'] ?? [])['text'] ?? ''));
			$label = ($name !== '' ? $name : 'anonymous') . ', ' . $date;
			if ($text === '') {
				$messages[] = '  skipped (no text): ' . $label;
				continue;
			}
			// `name` is "places/<place>/reviews/<id>"; the id is what sayamaapps.koe.approved lists.
			$id = (string)($review['name'] ?? '');
			$slash = strrpos($id, '/');
			$id = $slash === false ? $id : substr($id, $slash + 1);
			// The link to the review and the author is googleMapsUri (Places
			// policies); there is no stand-in when it is missing.
			$url = trim((string)($review['googleMapsUri'] ?? ''));
			$photo = trim((string)($author['photoUri'] ?? ''));
			$entry = [
				'id'     => $id !== '' ? $id : null,
				'name'   => $name,
				'date'   => $date,
				'rating' => (int)($review['rating'] ?? 5),
				'text'   => $text,
			];
			if ($url !== '') {
				$entry['url'] = $url;
			}
			if (preg_match('~^https://[^\s"<>]+$~i', $photo)) {
				$entry['photo'] = $photo;
			}
			$reviews[] = $entry;
			$messages[] = '  ' . ($entry['id'] ?? '(no id)') . '  ' . $label . ', ' . $entry['rating'] . ' stars' . ($url === '' ? ' (no link)' : '');
		}
		usort($reviews, fn ($a, $b) => strcmp($b['date'], $a['date']));
		$rating = $details['rating'] ?? null;
		$count = $details['userRatingCount'] ?? null;
		$data = [
			'placeId' => $placeId,
			'rating'  => is_numeric($rating) ? (float)$rating : null,
			'count'   => is_numeric($count) ? (int)$count : count($reviews),
			'reviews' => $reviews,
		];
		$messages[] = count($reviews) . ' reviews with text; Google reports ' . $data['count']
			. ' with an average of ' . (string)($data['rating'] ?? '?') . '.';
		return ['data' => $data, 'messages' => $messages];
	}

	/** The key must never appear in any output — not even via a quoted error message. */
	public static function scrub(string $text, string $key): string
	{
		return $key === '' ? $text : str_replace($key, '[key]', $text);
	}

	/**
	 * First hit of a text search: `['id' => 'ChIJ…', 'name' => 'Business']`.
	 *
	 * Text search instead of a fixed place id on purpose: it survives the
	 * listing being recreated. If the Google profile changes its name or
	 * address, the query must follow, otherwise nothing is found. Whoever
	 * wants the opposite (a different place must never be taken silently)
	 * sets the expected place id, which Fetch checks against this hit.
	 *
	 * @throws PlacesException when nothing is found or the API fails
	 */
	public function search(string $query, string $language = 'en'): array
	{
		$answer = $this->call('places:searchText', 'places.id,places.displayName', [
			'textQuery' => $query,
			'languageCode' => $language,
		]);
		$places = $answer['places'] ?? [];
		if (!is_array($places) || $places === [] || !isset($places[0]['id'])) {
			throw new PlacesException('Places search found nothing for: ' . $query);
		}
		return [
			'id' => (string)$places[0]['id'],
			'name' => (string)($places[0]['displayName']['text'] ?? ''),
		];
	}
}
