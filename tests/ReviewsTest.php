<?php
/**
 * Selection rules, both file formats, approval, links and the profile URL —
 * without Kirby.
 *   php tests/ReviewsTest.php
 */
require_once __DIR__ . '/../src/I18n.php';
require_once __DIR__ . '/../src/Reviews.php';

use Koe\I18n;
use Koe\Reviews;

$checks = 0;
function expect($expected, $actual, string $what): void
{
    global $checks;
    if ($expected !== $actual) { throw new RuntimeException($what . ': ' . var_export($actual, true)); }
    $checks++;
}
$files = [];
// Files carry the day of the last fetch unless a test says otherwise ($today below is 2026-10-05).
$tmpFile = function (array $data, bool $dated = true) use (&$files): string {
    if ($dated && !isset($data['stand'])) {
        $data += ['fetchedAt' => '2026-10-05'];
    }
    $file = tempnam(sys_get_temp_dir(), 'koe');
    file_put_contents($file, json_encode($data));
    return $files[] = $file;
};

$today = new DateTimeImmutable('2026-10-05', new DateTimeZone('Europe/Berlin'));
$long = str_repeat('Very nice place, great food and friendly people. ', 2); // > 80 characters
// Normalized (format 2) review.
$r = fn (string $name, int $rating, string $date, ?string $text = null, ?string $id = null, mixed $url = null) =>
    ['id' => $id, 'name' => $name, 'date' => $date, 'rating' => $rating, 'text' => $text ?? $long, 'source' => 'google', 'url' => $url];
// Legacy (format 1) review as scripts/bewertungen-holen.py wrote it.
$legacy = fn (string $name, int $stars, string $date, ?string $text = null, ?string $link = null) =>
    array_filter(['name' => $name, 'datum' => $date, 'sterne' => $stars, 'text' => $text ?? $long, 'quelle' => 'google', 'link' => $link], fn ($v) => $v !== null);
$rules = Reviews::defaultRules();

try {
    I18n::setLanguage('en');
    expect([5, 80, 24, 3, 280, null], array_slice(array_values($rules), 0, 6), 'selection defaults are the pre-1.0 constants');
    expect(7, $rules['maxAgeDays'], 'the data file may be 7 days old by default');
    expect(null, $rules['placeId'], 'no expected place by default');

    // 4 stars, too short, too old, blank text fall out.
    $out = Reviews::select([
        $r('Four', 4, '2026-09-01'),
        $r('Short', 5, '2026-09-02', 'Great place, top!'),
        $r('Old', 5, '2024-10-04'),
        $r('Blank', 5, '2026-09-03', '   '),
        $r('Fits', 5, '2026-08-01'),
    ], $rules, $today);
    expect(['Fits'], array_column($out, 'name'), '4 stars / short / old / blank fall out');

    // Age limit: exactly 24 months still in, one day older out.
    $out = Reviews::select([$r('Edge', 5, '2024-10-05'), $r('TooOld', 5, '2024-10-04')], $rules, $today);
    expect(['Edge'], array_column($out, 'name'), 'age limit 24 months');

    // Text is trimmed before the length check.
    expect([], Reviews::select([$r('Padded', 5, '2026-09-01', str_repeat(' ', 90) . 'short' . str_repeat(' ', 90))], $rules, $today), 'trim before length check');

    // More than three: the three newest, newest first.
    $out = Reviews::select([
        $r('A', 5, '2026-01-10'), $r('B', 5, '2026-09-10'), $r('C', 5, '2026-05-10'),
        $r('D', 5, '2026-07-10'), $r('E', 5, '2025-11-10'),
    ], $rules, $today);
    expect(['B', 'D', 'C'], array_column($out, 'name'), 'three newest, newest first');
    expect('September 2026', $out[0]['month'], 'month and year');
    expect('Google user', Reviews::select([$r('  ', 5, '2026-09-01')], $rules, $today)[0]['name'], 'empty name becomes the anonymous label');

    // Rules as options: lower rating, other count, no truncation.
    $out = Reviews::select([$r('Four', 4, '2026-09-01'), $r('Three', 3, '2026-09-02'), $r('Five', 5, '2026-09-03')], ['minRating' => 4, 'maxCount' => 1], $today);
    expect(['Five'], array_column($out, 'name'), 'minRating 4 lets four stars in, maxCount 1 keeps the newest');
    $out = Reviews::select([$r('Four', 4, '2026-09-01'), $r('Five', 5, '2026-09-03')], ['minRating' => 4, 'maxCount' => 5], $today);
    expect(['Five', 'Four'], array_column($out, 'name'), 'both with maxCount 5');
    expect(0, count(Reviews::select([$r('Any', 5, '2026-09-01')], ['maxCount' => 0], $today)), 'maxCount 0 shows no single review');
    $wall = trim(str_repeat('Word ', 100));
    expect($wall, Reviews::select([$r('Long', 5, '2026-09-01', $wall)], ['truncate' => 0], $today)[0]['text'], 'truncate 0 keeps the whole text');
    expect(1, count(Reviews::select([$r('Short', 5, '2026-09-02', 'Great place, top!')], ['minLength' => 0], $today)), 'minLength 0 lets short texts in');

    // Approval: null = rules only; a list = only those ids (still subject to the rules); [] = none.
    $pool = [$r('A', 5, '2026-09-01', null, 'id-a'), $r('B', 5, '2026-09-02', null, 'id-b'), $r('C', 4, '2026-09-03', null, 'id-c'), $r('NoId', 5, '2026-09-04')];
    expect(['NoId', 'B', 'A'], array_column(Reviews::select($pool, $rules, $today), 'name'), 'approved null: rules only');
    expect(['B'], array_column(Reviews::select($pool, ['approved' => ['id-b', 'id-c']], $today), 'name'), 'approved list narrows; 4-star C still out');
    expect(['C', 'B'], array_column(Reviews::select($pool, ['approved' => ['id-b', 'id-c'], 'minRating' => 4], $today), 'name'), 'approved plus lower rating');
    expect([], Reviews::select($pool, ['approved' => []], $today), 'approved []: nothing');
    expect([], Reviews::select([$r('NoId', 5, '2026-09-04')], ['approved' => ['x']], $today), 'a review without id can never be approved');

    // Truncation at a word boundary.
    $cut = Reviews::truncate($wall);
    expect(true, mb_strlen($cut) <= Reviews::TRUNCATE + 1 && str_ends_with($cut, 'Word…'), 'cut at word boundary');
    expect('Short.', Reviews::truncate('Short.'), 'short text stays');
    expect(Reviews::TRUNCATE + 1, mb_strlen(Reviews::truncate(str_repeat('ä', 400))), 'cut without spaces, multibyte safe');
    expect('Hello…', Reviews::truncate('Hello world', 8), 'trailing punctuation removed before the ellipsis');

    // Links: kept when http(s), otherwise null (nothing like javascript: from the file).
    $out = Reviews::select([
        $r('With', 5, '2026-09-01', null, null, 'https://maps.google.com/?cid=1'),
        $r('Without', 5, '2026-09-02'),
        $r('Evil', 5, '2026-09-03', null, null, 'javascript:alert(1)'),
    ], $rules, $today);
    $urls = array_column($out, 'url', 'name');
    expect('https://maps.google.com/?cid=1', $urls['With'], 'link kept');
    expect(true, $urls['Without'] === null && $urls['Evil'] === null, 'missing/unsafe link → null');

    // Profile URL from the place id; none or nonsense → null.
    expect('https://www.google.com/maps/place/?q=place_id:ChIJExample-Place_ID0', Reviews::profileUrl('ChIJExample-Place_ID0'), 'profile URL');
    expect(true, Reviews::profileUrl('') === null && Reviews::profileUrl(null) === null && Reviews::profileUrl('a b"<') === null, 'profile URL without/invalid id');

    // Files: missing, empty, broken, no rating → null.
    expect(null, Reviews::load('/does/not/exist.json', $rules, $today, $problem), 'missing file');
    expect(null, $problem, 'missing file: no problem to report, the page is simply empty');
    expect(null, Reviews::load('', $rules, $today), 'empty path');
    $f = tempnam(sys_get_temp_dir(), 'koe');
    $files[] = $f;
    expect(null, Reviews::load($f, $rules, $today), 'empty file');
    file_put_contents($f, '{broken');
    expect(null, Reviews::load($f, $rules, $today), 'broken JSON');
    // An answer without any review text is a valid state, not a broken file: rating and count are kept.
    expect(['rating' => 4.8, 'count' => 9, 'placeId' => null, 'reviews' => [], 'limit' => 3], Reviews::load($tmpFile(['format' => 2, 'rating' => 4.8, 'count' => 9, 'reviews' => []]), $rules, $today), 'empty list: rating and count, no reviews');
    expect(null, Reviews::load($tmpFile(['format' => 2, 'rating' => null, 'count' => 0, 'reviews' => []]), $rules, $today), 'empty list without a rating (place has no reviews at all): nothing');
    expect(null, Reviews::load($tmpFile(['format' => 2, 'count' => 9, 'reviews' => [$r('X', 5, '2026-09-01')]]), $rules, $today), 'no rating');

    // Format 2: rating and count only when no single review fits; place id passed through.
    $g = Reviews::load($tmpFile(['format' => 2, 'placeId' => 'ChIJFile', 'rating' => 4.7, 'count' => 42, 'reviews' => [$r('Four', 4, '2026-09-01')]]), $rules, $today);
    expect(['rating' => 4.7, 'count' => 42, 'placeId' => 'ChIJFile', 'reviews' => [], 'limit' => 3], $g, 'rating and count only');
    $g = Reviews::load($tmpFile(['format' => 2, 'rating' => 4.9, 'count' => 7, 'reviews' => [$r('One', 5, '2026-09-01', null, 'id-1')]]), $rules, $today);
    expect(true, $g !== null && count($g['reviews']) === 1 && $g['reviews'][0]['name'] === 'One' && $g['reviews'][0]['id'] === 'id-1' && $g['placeId'] === null, 'load with one review, no place id');

    // Format field: 1 and 2 are read, with or without the field; any other value is refused and logged.
    $logFile = tempnam(sys_get_temp_dir(), 'koelog');
    $files[] = $logFile;
    ini_set('error_log', $logFile);
    $g = Reviews::load($tmpFile(['rating' => 4.7, 'count' => 42, 'reviews' => [$r('NoField', 5, '2026-09-01', null, 'id-2')]]), $rules, $today);
    expect(true, $g !== null && count($g['reviews']) === 1 && $g['reviews'][0]['name'] === 'NoField', 'format 2 without format field is read');
    $g = Reviews::load($tmpFile(['format' => 2, 'rating' => 4.7, 'count' => 42, 'reviews' => [$r('Explicit', 5, '2026-09-01', null, 'id-3')]]), $rules, $today);
    expect(true, $g !== null && count($g['reviews']) === 1 && $g['reviews'][0]['name'] === 'Explicit', 'format 2 with format field is read');
    expect('', (string) file_get_contents($logFile), 'readable formats log nothing');
    $future = ['format' => 3, 'placeId' => 'ChIJFile', 'rating' => 4.7, 'count' => 42, 'fetchedAt' => '2026-10-05', 'reviews' => [$r('Future', 5, '2026-09-01', null, 'id-4')]];
    expect(null, Reviews::load($tmpFile($future), $rules, $today), 'format 3 with format 2 keys: section stays empty');
    expect(null, Reviews::normalize(['format' => 0, 'rating' => 4.7, 'reviews' => [$r('Zero', 5, '2026-09-01')]]), 'format 0 refused');
    expect(true, str_contains((string) file_get_contents($logFile), 'koe: unknown data file format 3'), 'unknown format is logged with its value');
    ini_restore('error_log');

    // Format 1 (the pre-1.0 file): German keys read as before, no ids, no place id.
    $old = ['stand' => '2026-10-04', 'hinweis' => '…', 'gesamtwertung' => 4.8, 'anzahl' => 201, 'bewertungen' => [
        $legacy('Mit Link', 5, '2026-09-01', null, 'https://maps.google.com/?cid=42'),
        $legacy('Ohne Link', 5, '2026-08-01'),
        $legacy('Böse', 5, '2026-07-01', null, 'javascript:alert(1)'),
        $legacy('Vier', 4, '2026-09-05'),
        $legacy('Kurz', 5, '2026-09-06', 'Top!'),
    ]];
    $g = Reviews::load($tmpFile($old), $rules, $today);
    expect(4.8, $g['rating'], 'legacy rating');
    expect(201, $g['count'], 'legacy count');
    expect(null, $g['placeId'], 'legacy has no place id');
    expect(['Mit Link', 'Ohne Link', 'Böse'], array_column($g['reviews'], 'name'), 'legacy selection identical to the old rules');
    expect(['https://maps.google.com/?cid=42', null, null], array_column($g['reviews'], 'url'), 'legacy links');
    expect([null, null, null], array_column($g['reviews'], 'id'), 'legacy reviews have no ids');
    $n = Reviews::normalize($old);
    expect(1, $n['format'], 'legacy detected as format 1');
    expect('2026-10-04', $n['fetchedAt'], 'legacy fetch date');
    expect([9, []], [Reviews::normalize(['gesamtwertung' => 4.8, 'anzahl' => 9, 'bewertungen' => []])['count'], Reviews::normalize(['gesamtwertung' => 4.8, 'anzahl' => 9, 'bewertungen' => []])['reviews']], 'legacy empty list is a valid state too');
    expect(5, Reviews::normalize(['gesamtwertung' => 4.8, 'bewertungen' => [$legacy('A', 5, '2026-09-01'), 'junk', $legacy('B', 5, '2026-09-01'), $legacy('C', 5, '2026-09-01'), $legacy('D', 5, '2026-09-01'), $legacy('E', 5, '2026-09-01')]])['count'], 'legacy count falls back to the list length, junk ignored');

    // Freshness: the day of the last successful fetch (checkedAt) decides; $today is 2026-10-05, the limit 7 days.
    $stale = function (array $data, array $withRules = []) use ($tmpFile, $rules, $today, $r): array {
        if (!isset($data['bewertungen'])) {
            $data += ['format' => 2, 'rating' => 4.8, 'count' => 9, 'reviews' => [$r('Fresh', 5, '2026-09-01')]];
        }
        $loaded = Reviews::load($tmpFile($data, false), $withRules + $rules, $today, $problem);
        return [$loaded !== null, $problem];
    };
    expect([true, null], $stale(['checkedAt' => '2026-10-05']), 'checked today: shown');
    expect([true, null], $stale(['checkedAt' => '2026-09-28']), 'checked 7 days ago: shown (limit is inclusive)');
    expect([false, 'the data file was last checked on 2026-09-27 (8 days ago, koe.maxAgeDays is 7)'], $stale(['checkedAt' => '2026-09-27']), 'checked 8 days ago: hidden, reason names date and limit');
    expect([true, null], $stale(['fetchedAt' => '2026-08-01', 'checkedAt' => '2026-10-04']), 'content unchanged since August, but checked yesterday: shown (checkedAt, not fetchedAt)');
    expect([true, null], $stale(['fetchedAt' => '2026-10-01']), 'file from before checkedAt: fetchedAt counts');
    expect(false, $stale(['fetchedAt' => '2026-09-20'])[0], 'file from before checkedAt, fetched 15 days ago: hidden');
    expect([true, null], $stale(['stand' => '2026-10-04', 'gesamtwertung' => 4.8, 'anzahl' => 9, 'bewertungen' => [$legacy('Alt', 5, '2026-09-01')]]), 'format 1: stand counts as the check day');
    expect(false, $stale(['stand' => '2026-09-01', 'gesamtwertung' => 4.8, 'anzahl' => 9, 'bewertungen' => [$legacy('Alt', 5, '2026-09-01')]])[0], 'format 1 with an old stand: hidden');
    [$shown, $why] = $stale([]);
    expect(false, $shown, 'no date at all: hidden');
    expect(true, str_contains((string) $why, 'age is unknown'), 'no date: the reason says the age is unknown');
    expect(false, $stale(['checkedAt' => 'yesterday', 'fetchedAt' => '2026-02-30'])[0], 'unparsable dates are no dates');
    expect([true, null], $stale(['checkedAt' => 'yesterday', 'fetchedAt' => '2026-10-03']), 'unparsable checkedAt: fetchedAt counts');
    expect([true, null], $stale(['checkedAt' => '2026-10-06']), 'a check day in the future (clock skew) is not stale');
    expect([true, null], $stale(['checkedAt' => '2026-09-15'], ['maxAgeDays' => 30]), 'maxAgeDays 30 lets a 20 day old file through');
    expect(false, $stale(['checkedAt' => '2026-10-02'], ['maxAgeDays' => 2])[0], 'maxAgeDays 2 hides a 3 day old file');
    expect(false, $stale(['format' => 2, 'checkedAt' => '2026-10-02', 'reviews' => []] + ['rating' => 4.8], ['maxAgeDays' => 2])[0], 'an empty review list does not make a stale file fresh');

    // Identity: with an expected place id (koe.placeId) the file must be about that place.
    $placeFile = $tmpFile(['format' => 2, 'placeId' => 'ChIJExampleOne', 'rating' => 4.8, 'count' => 9, 'reviews' => [$r('Own', 5, '2026-09-01')]]);
    expect(['Own'], array_column(Reviews::load($placeFile, ['placeId' => 'ChIJExampleOne'] + $rules, $today, $problem)['reviews'], 'name'), 'expected place equals the file: shown');
    expect(null, $problem, 'expected place equals the file: no problem');
    expect(null, Reviews::load($placeFile, ['placeId' => 'ChIJExampleTwo'] + $rules, $today, $problem), 'expected place differs from the file: nothing');
    expect(true, str_starts_with((string) $problem, 'the data file is about place ChIJExampleOne, but koe.placeId is ChIJExampleTwo'), 'place mismatch: the reason names both ids');
    expect(true, Reviews::load($placeFile, $rules, $today) !== null, 'no expected place: the file is taken as it is');
    expect(null, Reviews::load($placeFile, ['placeId' => 'chijexampleone'] + $rules, $today), 'place ids are compared exactly');
    $noId = $tmpFile(['format' => 2, 'rating' => 4.8, 'count' => 9, 'reviews' => [$r('Old copy', 5, '2026-09-01')]]);
    expect(true, Reviews::load($noId, ['placeId' => 'ChIJExampleTwo'] + $rules, $today, $problem) !== null && $problem === null, 'a file without a recorded place id cannot be checked and is taken as it is');
    expect(null, Reviews::load($tmpFile(['format' => 2, 'placeId' => 'ChIJExampleOne', 'checkedAt' => '2026-09-01', 'rating' => 4.8, 'count' => 9, 'reviews' => []]), ['placeId' => 'ChIJExampleOne'] + $rules, $today, $problem), 'right place but too old: still nothing');
    expect(true, str_contains((string) $problem, 'last checked on 2026-09-01'), 'right place but too old: the reason is the age');

    // German month names when the language is German.
    I18n::setLanguage('de');
    expect('September 2026', Reviews::select([$r('A', 5, '2026-09-01')], $rules, $today)[0]['month'], 'German month');
    expect('Google-Nutzer', Reviews::select([$r('', 5, '2026-09-01')], $rules, $today)[0]['name'], 'German anonymous label');
    I18n::setLanguage('en');

    echo "Koe reviews: all $checks checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Koe reviews: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
} finally {
    array_map('unlink', $files);
}
