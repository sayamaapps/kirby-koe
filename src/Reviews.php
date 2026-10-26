<?php

namespace Koe;

use DateTimeImmutable;

/**
 * The selection of what is shown.
 *
 * The refresh (Store::refresh()) keeps only the reviews that pass the rules
 * (filter()); the page selects again when it renders (select()), which adds
 * the shortened text and the month. Kirby-free, so tests/ReviewsTest.php can
 * check it directly.
 *
 * A review, as Places::parse() delivers it: `id`, `name`, `date`, `rating`,
 * `text`, optionally `url` (googleMapsUri) and, once the refresh has fetched
 * the profile picture, `avatar` (its key in the cache).
 */
final class Reviews
{
	/** Maximum age in months, counted from "today" at render time. */
	public const MAX_AGE_MONTHS = 24;

	/** Maximum number of single reviews. */
	public const MAX_COUNT = 3;

	/** Minimum text length in characters (after trim). */
	public const MIN_LENGTH = 80;

	// ---- Default selection rules (the pre-1.0 constants) -------------------
	/** Only reviews with at least this many stars. */
	public const MIN_RATING = 5;

	/** Longer texts are cut at a word boundary. */
	public const TRUNCATE = 280;

	/** @return array{minRating:int, minLength:int, maxAgeMonths:int, maxCount:int, truncate:int, approved:?array, placeId:?string} */
	public static function defaultRules(): array
	{
		return [
			'minRating'    => self::MIN_RATING,
			'minLength'    => self::MIN_LENGTH,
			'maxAgeMonths' => self::MAX_AGE_MONTHS,
			'maxCount'     => self::MAX_COUNT,
			'truncate'     => self::TRUNCATE,
			'approved'     => null,
			'placeId'      => null,
		];
	}

	/**
	 * The reviews that pass the rules (rating, length, age, approval), newest
	 * first, at most `maxCount`, otherwise as they came: what the cache keeps.
	 *
	 * @return list<array<string,mixed>>
	 */
	public static function filter(array $reviews, array $rules, DateTimeImmutable $today): array
	{
		$rules += self::defaultRules();
		// 0 = no age limit.
		$oldest = $rules['maxAgeMonths'] > 0
			? $today->modify('-' . $rules['maxAgeMonths'] . ' months')->format('Y-m-d')
			: null;
		$approved = $rules['approved'];
		$hits = [];
		foreach ($reviews as $review) {
			if (!is_array($review)) {
				continue;
			}
			$text = trim((string)($review['text'] ?? ''));
			$date = (string)($review['date'] ?? '');
			$id = isset($review['id']) ? (string)$review['id'] : null;
			if ((int)($review['rating'] ?? 0) < $rules['minRating'] ||
				mb_strlen($text) < $rules['minLength'] ||
				!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ||
				($oldest !== null && $date < $oldest) ||
				($approved !== null && ($id === null || !in_array($id, $approved, true)))) {
				continue;
			}
			$hits[] = $review;
		}
		usort($hits, fn ($a, $b) => strcmp((string)$b['date'], (string)$a['date']));
		return array_slice($hits, 0, $rules['maxCount']);
	}

	/**
	 * The notice for visitors: how the reviews shown were selected and in what
	 * order, worded from the rules actually in force (never a fixed text, so it
	 * cannot say "5 stars" on a site that lets 4 stars in). Plain text in the
	 * current language (I18n), to be escaped by the template.
	 *
	 * Covers the rules that decide which reviews appear and in what order
	 * (stars, text length, age, an approved list, newest first), as Google's
	 * Places policies ask. Kept to one short sentence: the number shown is
	 * visible anyway, and rating and count stand next to Google's name.
	 */
	public static function notice(array $rules): string
	{
		$rules += self::defaultRules();
		$criteria = [];
		if ($rules['minRating'] >= 5) {
			$criteria[] = I18n::t('notice.stars.exact', ['stars' => 5]);
		} elseif ($rules['minRating'] > 1) {
			$criteria[] = I18n::t('notice.stars.min', ['stars' => $rules['minRating']]);
		}
		if ($rules['minLength'] > 0) {
			$criteria[] = $rules['minLength'] === 1
				? I18n::t('notice.length.one')
				: I18n::t('notice.length', ['chars' => $rules['minLength']]);
		}
		if ($rules['maxAgeMonths'] > 0) {
			$criteria[] = $rules['maxAgeMonths'] === 1
				? I18n::t('notice.age.one')
				: I18n::t('notice.age', ['months' => $rules['maxAgeMonths']]);
		}
		if ($rules['approved'] !== null) {
			$criteria[] = I18n::t('notice.approved');
		}
		$criteria[] = I18n::t('notice.order');
		return I18n::t('notice.selection', ['criteria' => implode(', ', $criteria)]);
	}

	/**
	 * Address of the Google profile from the place id. Without one, or with a nonsensical one, null: then the profile
	 * link and the "read all on Google" tile are omitted.
	 */
	public static function profileUrl(string|null $placeId): string|null
	{
		$placeId = trim((string)$placeId);
		return preg_match('/^[A-Za-z0-9_-]+$/', $placeId)
			? 'https://www.google.com/maps/place/?q=place_id:' . $placeId
			: null;
	}

	/** Only http(s) addresses, otherwise null (no javascript: or the like from an answer). */
	public static function safeUrl(mixed $url): string|null
	{
		$url = is_string($url) ? trim($url) : '';
		return preg_match('~^https?://[^\s"<>]+$~i', $url) ? $url : null;
	}

	/**
	 * filter(), then what the page shows: trimmed and shortened text, the
	 * month, a safe link and the avatar key.
	 *
	 * @return list<array{id: ?string, name: string, rating: int, text: string, date: string, month: string, url: ?string, avatar: ?string}>
	 */
	public static function select(array $reviews, array $rules, DateTimeImmutable $today): array
	{
		$rules += self::defaultRules();
		$shown = [];
		foreach (self::filter($reviews, $rules, $today) as $review) {
			$avatar = $review['avatar'] ?? null;
			$shown[] = [
				'id'     => isset($review['id']) ? (string)$review['id'] : null,
				'name'   => trim((string)($review['name'] ?? '')) ?: I18n::t('author.anonymous'),
				'rating' => (int)$review['rating'],
				'text'   => self::truncate(trim((string)$review['text']), $rules['truncate']),
				'date'   => (string)$review['date'],
				'month'  => I18n::monthYear((string)$review['date']),
				// Link to the single review on Google Maps (googleMapsUri). Without
				// one the name stays text.
				'url'    => self::safeUrl($review['url'] ?? null),
				'avatar' => is_string($avatar) && $avatar !== '' ? $avatar : null,
			];
		}
		return $shown;
	}

	/** Cuts at the last word boundary before $max and appends "…". 0 = never. */
	public static function truncate(string $text, int $max = self::TRUNCATE): string
	{
		if ($max <= 0 || mb_strlen($text) <= $max) {
			return $text;
		}
		$part = mb_substr($text, 0, $max);
		$space = mb_strrpos($part, ' ');
		if ($space !== false && $space > $max / 2) {
			$part = mb_substr($part, 0, $space);
		}
		return rtrim($part, " \t\n\r,.;:–-") . '…';
	}
}
