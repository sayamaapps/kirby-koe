<?php
/**
 * Selection rules, approval, links, the profile URL and the notice —
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
$today = new DateTimeImmutable('2026-10-05', new DateTimeZone('Europe/Berlin'));
$long = str_repeat('Very nice place, great food and friendly people. ', 2); // > 80 characters
// A review as Places::parse() delivers it.
$r = fn (string $name, int $rating, string $date, ?string $text = null, ?string $id = null, mixed $url = null) =>
    ['id' => $id, 'name' => $name, 'date' => $date, 'rating' => $rating, 'text' => $text ?? $long, 'source' => 'google', 'url' => $url];
$rules = Reviews::defaultRules();

try {
    I18n::setLanguage('en');
    expect([5, 80, 24, 3, 280, null], array_slice(array_values($rules), 0, 6), 'selection defaults are the pre-1.0 constants');
    expect(null, $rules['placeId'], 'no place by default');
    expect(false, array_key_exists('maxAgeDays', $rules), 'no file, so no file age');

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
    $out = Reviews::select([$r('Ancient', 5, '2009-01-01')], ['maxAgeMonths' => 0] + $rules, $today);
    expect(['Ancient'], array_column($out, 'name'), 'maxAgeMonths 0: no age limit');

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

    // filter(): the raw entries that pass the rules, newest first, at most maxCount, untruncated —
    // what the cache keeps. select() on them gives the same result as select() on all of them.
    $pool = [$r('Old', 5, '2024-01-01'), $r('A', 5, '2026-03-01', null, 'id-a'), $r('Four', 4, '2026-09-01'), $r('B', 5, '2026-09-02', $wall, 'id-b'), $r('C', 5, '2026-05-01'), $r('D', 5, '2026-01-01')];
    $kept = Reviews::filter($pool, $rules, $today);
    expect(['B', 'C', 'A'], array_column($kept, 'name'), 'filter: rules, newest first, maxCount');
    expect($wall, $kept[0]['text'], 'filter: text kept whole (truncated only when shown)');
    expect('google', $kept[0]['source'], 'filter: entries stay as they came');
    expect(Reviews::select($pool, $rules, $today), Reviews::select($kept, $rules, $today), 'select() on the filtered entries = select() on all');
    expect(['B'], array_column(Reviews::filter($pool, ['approved' => ['id-b']], $today), 'name'), 'filter: approved list');
    expect([], Reviews::filter([], $rules, $today), 'filter: nothing in, nothing out');

    // The avatar key travels with the review; without one it is null.
    $out = Reviews::select([$r('Pic', 5, '2026-09-01') + ['avatar' => 'abc123'], $r('NoPic', 5, '2026-09-02')], $rules, $today);
    expect(['NoPic' => null, 'Pic' => 'abc123'], array_column($out, 'avatar', 'name'), 'avatar key passed through');

    // The notice for visitors is worded from the rules in force, not fixed text.
    expect('Selection: 5 stars, 80+ characters, at most 24 months old, newest first.', Reviews::notice($rules), 'notice for the default rules');
    expect(Reviews::notice($rules), Reviews::notice([]), 'notice without rules = the defaults');
    expect('Selection: 4+ stars, 120+ characters, at most 12 months old, newest first.',
        Reviews::notice(['minRating' => 4, 'minLength' => 120, 'maxAgeMonths' => 12] + $rules), 'notice follows stars, length and age');
    expect(false, str_contains(Reviews::notice(['minRating' => 3] + $rules), '5 stars'), 'notice never says 5 stars when 3 are enough');
    expect(true, str_contains(Reviews::notice(['minRating' => 3] + $rules), '3+ stars'), 'minimum of 3 stars named');
    expect(false, str_contains(Reviews::notice(['minRating' => 1] + $rules), 'star'), 'minRating 1 is no filter: stars not mentioned');
    expect(false, str_contains(Reviews::notice(['minLength' => 0] + $rules), 'characters'), 'minLength 0 is no filter: length not mentioned');
    expect(true, str_contains(Reviews::notice(['minLength' => 1] + $rules), 'at least 1 character,'), 'singular for 1 character');
    expect(true, str_contains(Reviews::notice(['maxAgeMonths' => 1] + $rules), 'at most 1 month old,'), 'singular for 1 month');
    expect(false, str_contains(Reviews::notice(['maxAgeMonths' => 0] + $rules), 'old'), 'maxAgeMonths 0 is no filter: age not mentioned');
    expect('Selection: newest first.', Reviews::notice(['minRating' => 1, 'minLength' => 0, 'maxAgeMonths' => 0] + $rules), 'no filter at all: only the order');
    expect(false, str_contains(Reviews::notice($rules), 'approved'), 'no approved list: nothing about approval');
    expect(true, str_contains(Reviews::notice(['approved' => ['id-a']] + $rules), 'at most 24 months old, approved by the business, newest first.'), 'approved list: named as a criterion');
    foreach (['minRating' => 3, 'minLength' => 10, 'maxAgeMonths' => 6, 'approved' => ['x']] as $rule => $value) {
        expect(true, Reviews::notice([$rule => $value] + $rules) !== Reviews::notice($rules), "notice changes with the rule $rule");
    }
    expect(Reviews::notice($rules), Reviews::notice(['maxCount' => 9, 'truncate' => 0, 'placeId' => 'x'] + $rules), 'count, cut and place id do not change the notice');

    // German month names when the language is German.
    I18n::setLanguage('de');
    expect('September 2026', Reviews::select([$r('A', 5, '2026-09-01')], $rules, $today)[0]['month'], 'German month');
    expect('Google-Nutzer', Reviews::select([$r('', 5, '2026-09-01')], $rules, $today)[0]['name'], 'German anonymous label');
    expect('Auswahl: 5 Sterne, ab 80 Zeichen, höchstens 24 Monate alt, neueste zuerst.', Reviews::notice($rules), 'German notice for the default rules');
    expect('Auswahl: ab 4 Sternen, ab 1 Zeichen, höchstens 1 Monat alt, vom Betrieb freigegeben, neueste zuerst.',
        Reviews::notice(['minRating' => 4, 'minLength' => 1, 'maxAgeMonths' => 1, 'maxCount' => 2, 'approved' => []] + $rules), 'German notice follows the rules, with an approved list');
    I18n::setLanguage('en');

    echo "Koe reviews: all $checks checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Koe reviews: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
