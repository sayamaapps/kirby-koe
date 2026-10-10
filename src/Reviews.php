<?php

namespace Koe;

use DateTimeImmutable;
use DateTimeZone;

/**
 * The data file and the selection of what is shown.
 *
 * The nightly fetch (bin/koe-fetch) stores every review Google returns,
 * with text. Which of them appear on the page is decided at render time by
 * the rules below (Options::rules(), defaults = the constants here). Kirby-
 * free, so tests/ReviewsTest.php can check it directly.
 *
 * Two file formats are read:
 *
 * - format 2 (written by koe-fetch): `format`, `fetchedAt`, `placeId`,
 *   `rating`, `count`, `reviews[]` with `id`, `name`, `date`, `rating`,
 *   `text`, `source`, `url`, `authorUrl`.
 * - format 1, the file of the pre-1.0 copy (scripts/bewertungen-holen.py):
 *   `stand`, `gesamtwertung`, `anzahl`, `bewertungen[]` with `name`, `datum`,
 *   `sterne`, `text`, `quelle`, `link`, `autorLink`. No ids, no place id.
 *   It is rewritten in format 2 by the first fetch after the switch.
 */
final class Reviews
{
    // ---- Default selection rules (the pre-1.0 constants) -------------------
    /** Only reviews with at least this many stars. */
    public const MIN_RATING = 5;
    /** Minimum text length in characters (after trim). */
    public const MIN_LENGTH = 80;
    /** Maximum age in months, counted from "today" at render time. */
    public const MAX_AGE_MONTHS = 24;
    /** Maximum number of single reviews. */
    public const MAX_COUNT = 3;
    /** Longer texts are cut at a word boundary. */
    public const TRUNCATE = 280;

    public const FORMAT = 2;

    /**
     * Reads the file and selects the reviews to show.
     *
     * @return array{rating: float, count: int, placeId: ?string, reviews: list<array{id: ?string, name: string, rating: int, text: string, date: string, month: string, url: ?string}>, limit: int}|null
     *         null when the file is missing, unreadable or has no reviews: the
     *         page then shows nothing.
     */
    public static function load(string $file, array $rules = [], ?DateTimeImmutable $today = null): ?array
    {
        $data = self::read($file);
        if ($data === null) {
            return null;
        }
        $rules += self::defaultRules();
        $today ??= new DateTimeImmutable('now', new DateTimeZone('Europe/Berlin'));
        return [
            'rating'  => $data['rating'],
            'count'   => $data['count'],
            'placeId' => $data['placeId'],
            'reviews' => self::select($data['reviews'], $rules, $today),
            'limit'   => $rules['maxCount'],
        ];
    }

    /** @return array{minRating:int, minLength:int, maxAgeMonths:int, maxCount:int, truncate:int, approved:?array} */
    public static function defaultRules(): array
    {
        return [
            'minRating'    => self::MIN_RATING,
            'minLength'    => self::MIN_LENGTH,
            'maxAgeMonths' => self::MAX_AGE_MONTHS,
            'maxCount'     => self::MAX_COUNT,
            'truncate'     => self::TRUNCATE,
            'approved'     => null,
        ];
    }

    /**
     * The file as format 2, whichever format it is in — or null when it is
     * missing, unreadable, has no rating or no reviews.
     *
     * @return array{format: int, fetchedAt: ?string, placeId: ?string, rating: float, count: int, reviews: list<array<string,mixed>>}|null
     */
    public static function read(string $file): ?array
    {
        if ($file === '' || !is_file($file)) {
            return null;
        }
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? self::normalize($data) : null;
    }

    /** @return array{format: int, fetchedAt: ?string, placeId: ?string, rating: float, count: int, reviews: list<array<string,mixed>>}|null */
    public static function normalize(array $data): ?array
    {
        // A format this reader does not know (a newer one, or a typo) is not
        // guessed at: the section stays empty and the log names the format.
        if (isset($data['format']) && !in_array($data['format'], [1, 2], true)) {
            error_log('koe: unknown data file format ' . json_encode($data['format']) . ' (this version reads formats 1 and 2)');
            return null;
        }
        $legacy = !isset($data['reviews']) && isset($data['bewertungen']);
        $reviews = $legacy ? ($data['bewertungen'] ?? null) : ($data['reviews'] ?? null);
        $rating = $legacy ? ($data['gesamtwertung'] ?? null) : ($data['rating'] ?? null);
        if (!is_array($reviews) || $reviews === [] || !is_numeric($rating)) {
            return null;
        }
        $count = $legacy ? ($data['anzahl'] ?? null) : ($data['count'] ?? null);
        $list = [];
        foreach ($reviews as $review) {
            if (!is_array($review)) {
                continue;
            }
            $list[] = $legacy ? [
                'id'        => null,
                'name'      => $review['name'] ?? '',
                'date'      => $review['datum'] ?? '',
                'rating'    => $review['sterne'] ?? 0,
                'text'      => $review['text'] ?? '',
                'source'    => $review['quelle'] ?? 'google',
                'url'       => $review['link'] ?? null,
                'authorUrl' => $review['autorLink'] ?? null,
            ] : [
                'id'        => isset($review['id']) ? (string) $review['id'] : null,
                'name'      => $review['name'] ?? '',
                'date'      => $review['date'] ?? '',
                'rating'    => $review['rating'] ?? 0,
                'text'      => $review['text'] ?? '',
                'source'    => $review['source'] ?? 'google',
                'url'       => $review['url'] ?? null,
                'authorUrl' => $review['authorUrl'] ?? null,
            ];
        }
        $placeId = $legacy ? null : ($data['placeId'] ?? null);
        return [
            'format'    => $legacy ? 1 : (int) ($data['format'] ?? self::FORMAT),
            'fetchedAt' => $legacy ? ($data['stand'] ?? null) : ($data['fetchedAt'] ?? null),
            'placeId'   => is_string($placeId) && $placeId !== '' ? $placeId : null,
            'rating'    => (float) $rating,
            'count'     => is_numeric($count) ? (int) $count : count($list),
            'reviews'   => $list,
        ];
    }

    /**
     * Filters (rating, length, age, approval), sorts newest first, limits and
     * truncates. Takes normalized reviews (see read()).
     *
     * @return list<array{id: ?string, name: string, rating: int, text: string, date: string, month: string, url: ?string}>
     */
    public static function select(array $reviews, array $rules, DateTimeImmutable $today): array
    {
        $rules += self::defaultRules();
        $oldest = $today->modify('-' . $rules['maxAgeMonths'] . ' months')->format('Y-m-d');
        $approved = $rules['approved'];
        $hits = [];
        foreach ($reviews as $review) {
            if (!is_array($review)) {
                continue;
            }
            $text = trim((string) ($review['text'] ?? ''));
            $date = (string) ($review['date'] ?? '');
            $id = isset($review['id']) ? (string) $review['id'] : null;
            if ((int) ($review['rating'] ?? 0) < $rules['minRating']
                || mb_strlen($text) < $rules['minLength']
                || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)
                || $date < $oldest
                || ($approved !== null && ($id === null || !in_array($id, $approved, true)))) {
                continue;
            }
            $hits[] = [
                'id'     => $id,
                'name'   => trim((string) ($review['name'] ?? '')) ?: I18n::t('author.anonymous'),
                'rating' => (int) $review['rating'],
                'text'   => self::truncate($text, $rules['truncate']),
                'date'   => $date,
                'month'  => I18n::monthYear($date),
                // Link to the single review on Google Maps (Places policies:
                // users must be able to reach it). Without one the name stays text.
                'url'    => self::safeUrl($review['url'] ?? null),
            ];
        }
        usort($hits, fn ($a, $b) => strcmp($b['date'], $a['date']));
        return array_slice($hits, 0, $rules['maxCount']);
    }

    /**
     * Address of the Google profile from the place id (the place id may be
     * stored). Without one, or with a nonsensical one, null: then the profile
     * link and the "read all on Google" tile are omitted.
     */
    public static function profileUrl(?string $placeId): ?string
    {
        $placeId = trim((string) $placeId);
        return preg_match('/^[A-Za-z0-9_-]+$/', $placeId)
            ? 'https://www.google.com/maps/place/?q=place_id:' . $placeId
            : null;
    }

    /** Only http(s) addresses, otherwise null (no javascript: or the like from the file). */
    public static function safeUrl(mixed $url): ?string
    {
        $url = is_string($url) ? trim($url) : '';
        return preg_match('~^https?://[^\s"<>]+$~i', $url) ? $url : null;
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
