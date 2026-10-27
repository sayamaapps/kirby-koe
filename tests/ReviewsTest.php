<?php

use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Koe\I18n;
use Sayamaapps\Koe\Reviews;

/**
 * Selection rules, approval, links, the profile URL and the notice —
 * without Kirby.
 *   vendor/bin/phpunit
 */
#[CoversClass(\Sayamaapps\Koe\Reviews::class)]
final class ReviewsTest extends TestCase
{
	private function long(): string
	{
		return str_repeat('Very nice place, great food and friendly people. ', 2);
	}

	/** A review as Places::parse() delivers it. */
	private function review(string $name, int $rating, string $date, string|null $text = null, string|null $id = null, mixed $url = null): array
	{
		return ['id' => $id, 'name' => $name, 'date' => $date, 'rating' => $rating, 'text' => $text ?? $this->long(), 'source' => 'google', 'url' => $url];
	}

	protected function setUp(): void
	{
		I18n::setLanguage('en');
	}

	protected function tearDown(): void
	{
		I18n::setLanguage('en');
	}

	public function testAgeLimitIsInclusiveAndZeroMeansNoLimit(): void
	{
		// Exactly 24 months still in, one day older out.
		$out = Reviews::select([$this->review('Edge', 5, '2024-10-05'), $this->review('TooOld', 5, '2024-10-04')], Reviews::defaultRules(), $this->today());
		$this->assertSame(['Edge'], array_column($out, 'name'), 'age limit 24 months');
		$out = Reviews::select([$this->review('Ancient', 5, '2009-01-01')], ['maxAgeMonths' => 0] + Reviews::defaultRules(), $this->today());
		$this->assertSame(['Ancient'], array_column($out, 'name'), 'maxAgeMonths 0: no age limit');
	}

	public function testApprovalNullRulesOnlyListNarrowsEmptyNothing(): void
	{
		// null = rules only; a list = only those ids (still subject to the rules); [] = none.
		$today = $this->today();
		$pool = [$this->review('A', 5, '2026-09-01', null, 'id-a'), $this->review('B', 5, '2026-09-02', null, 'id-b'), $this->review('C', 4, '2026-09-03', null, 'id-c'), $this->review('NoId', 5, '2026-09-04')];
		$this->assertSame(['NoId', 'B', 'A'], array_column(Reviews::select($pool, Reviews::defaultRules(), $today), 'name'), 'approved null: rules only');
		$this->assertSame(['B'], array_column(Reviews::select($pool, ['approved' => ['id-b', 'id-c']], $today), 'name'), 'approved list narrows; 4-star C still out');
		$this->assertSame(['C', 'B'], array_column(Reviews::select($pool, ['approved' => ['id-b', 'id-c'], 'minRating' => 4], $today), 'name'), 'approved plus lower rating');
		$this->assertSame([], Reviews::select($pool, ['approved' => []], $today), 'approved []: nothing');
		$this->assertSame([], Reviews::select([$this->review('NoId', 5, '2026-09-04')], ['approved' => ['x']], $today), 'a review without id can never be approved');
	}

	public function testAvatarKeyTravelsWithTheReview(): void
	{
		$out = Reviews::select([$this->review('Pic', 5, '2026-09-01') + ['avatar' => 'abc123'], $this->review('NoPic', 5, '2026-09-02')], Reviews::defaultRules(), $this->today());
		$this->assertSame(['NoPic' => null, 'Pic' => 'abc123'], array_column($out, 'avatar', 'name'), 'avatar key passed through');
	}

	public function testDefaultRulesAreThePre10Constants(): void
	{
		$rules = Reviews::defaultRules();
		$this->assertSame([5, 80, 24, 3, 280, null], array_slice(array_values($rules), 0, 6), 'selection defaults are the pre-1.0 constants');
		$this->assertNull($rules['placeId'], 'no place by default');
		$this->assertFalse(array_key_exists('maxAgeDays', $rules), 'no file, so no file age');
	}

	public function testFilterKeepsTheEntriesAsTheyCameForTheCache(): void
	{
		// filter(): the raw entries that pass the rules, newest first, at most maxCount, untruncated —
		// what the cache keeps. select() on them gives the same result as select() on all of them.
		$today = $this->today();
		$rules = Reviews::defaultRules();
		$pool = [
			$this->review('Old', 5, '2024-01-01'), $this->review('A', 5, '2026-03-01', null, 'id-a'), $this->review('Four', 4, '2026-09-01'),
			$this->review('B', 5, '2026-09-02', $this->wall(), 'id-b'), $this->review('C', 5, '2026-05-01'), $this->review('D', 5, '2026-01-01'),
		];
		$kept = Reviews::filter($pool, $rules, $today);
		$this->assertSame(['B', 'C', 'A'], array_column($kept, 'name'), 'filter: rules, newest first, maxCount');
		$this->assertSame($this->wall(), $kept[0]['text'], 'filter: text kept whole (truncated only when shown)');
		$this->assertSame('google', $kept[0]['source'], 'filter: entries stay as they came');
		$this->assertSame(Reviews::select($pool, $rules, $today), Reviews::select($kept, $rules, $today), 'select() on the filtered entries = select() on all');
		$this->assertSame(['B'], array_column(Reviews::filter($pool, ['approved' => ['id-b']], $today), 'name'), 'filter: approved list');
		$this->assertSame([], Reviews::filter([], $rules, $today), 'filter: nothing in, nothing out');
	}

	public function testGermanMonthsLabelAndNotice(): void
	{
		$rules = Reviews::defaultRules();
		$today = $this->today();
		I18n::setLanguage('de');
		$this->assertSame('September 2026', Reviews::select([$this->review('A', 5, '2026-09-01')], $rules, $today)[0]['month'], 'German month');
		$this->assertSame('Google-Nutzer', Reviews::select([$this->review('', 5, '2026-09-01')], $rules, $today)[0]['name'], 'German anonymous label');
		$this->assertSame('Auswahl: 5 Sterne, ab 80 Zeichen, höchstens 24 Monate alt, neueste zuerst.', Reviews::notice($rules), 'German notice for the default rules');
		$this->assertSame(
			'Auswahl: ab 4 Sternen, ab 1 Zeichen, höchstens 1 Monat alt, vom Betrieb freigegeben, neueste zuerst.',
			Reviews::notice(['minRating' => 4, 'minLength' => 1, 'maxAgeMonths' => 1, 'maxCount' => 2, 'approved' => []] + $rules),
			'German notice follows the rules, with an approved list'
		);
	}

	public function testLinksAreKeptOnlyWhenHttp(): void
	{
		// Kept when http(s), otherwise null (nothing like javascript: from the file).
		$out = Reviews::select([
			$this->review('With', 5, '2026-09-01', null, null, 'https://maps.google.com/?cid=1'),
			$this->review('Without', 5, '2026-09-02'),
			$this->review('Evil', 5, '2026-09-03', null, null, 'javascript:alert(1)'),
		], Reviews::defaultRules(), $this->today());
		$urls = array_column($out, 'url', 'name');
		$this->assertSame('https://maps.google.com/?cid=1', $urls['With'], 'link kept');
		$this->assertTrue($urls['Without'] === null && $urls['Evil'] === null, 'missing/unsafe link → null');
	}

	public function testNoticeIsWordedFromTheRulesInForce(): void
	{
		$rules = Reviews::defaultRules();
		$this->assertSame('Selection: 5 stars, 80+ characters, at most 24 months old, newest first.', Reviews::notice($rules), 'notice for the default rules');
		$this->assertSame(Reviews::notice($rules), Reviews::notice([]), 'notice without rules = the defaults');
		$this->assertSame('Selection: 4+ stars, 120+ characters, at most 12 months old, newest first.', Reviews::notice(['minRating' => 4, 'minLength' => 120, 'maxAgeMonths' => 12] + $rules), 'notice follows stars, length and age');
		$this->assertFalse(str_contains(Reviews::notice(['minRating' => 3] + $rules), '5 stars'), 'notice never says 5 stars when 3 are enough');
		$this->assertTrue(str_contains(Reviews::notice(['minRating' => 3] + $rules), '3+ stars'), 'minimum of 3 stars named');
		$this->assertFalse(str_contains(Reviews::notice(['minRating' => 1] + $rules), 'star'), 'minRating 1 is no filter: stars not mentioned');
		$this->assertFalse(str_contains(Reviews::notice(['minLength' => 0] + $rules), 'characters'), 'minLength 0 is no filter: length not mentioned');
		$this->assertTrue(str_contains(Reviews::notice(['minLength' => 1] + $rules), 'at least 1 character,'), 'singular for 1 character');
		$this->assertTrue(str_contains(Reviews::notice(['maxAgeMonths' => 1] + $rules), 'at most 1 month old,'), 'singular for 1 month');
		$this->assertFalse(str_contains(Reviews::notice(['maxAgeMonths' => 0] + $rules), 'old'), 'maxAgeMonths 0 is no filter: age not mentioned');
		$this->assertSame('Selection: newest first.', Reviews::notice(['minRating' => 1, 'minLength' => 0, 'maxAgeMonths' => 0] + $rules), 'no filter at all: only the order');
		$this->assertFalse(str_contains(Reviews::notice($rules), 'approved'), 'no approved list: nothing about approval');
		$this->assertTrue(str_contains(Reviews::notice(['approved' => ['id-a']] + $rules), 'at most 24 months old, approved by the business, newest first.'), 'approved list: named as a criterion');
		foreach (['minRating' => 3, 'minLength' => 10, 'maxAgeMonths' => 6, 'approved' => ['x']] as $rule => $value) {
			$this->assertTrue(Reviews::notice([$rule => $value] + $rules) !== Reviews::notice($rules), "notice changes with the rule $rule");
		}
		$this->assertSame(Reviews::notice($rules), Reviews::notice(['maxCount' => 9, 'truncate' => 0, 'placeId' => 'x'] + $rules), 'count, cut and place id do not change the notice');
	}

	public function testProfileUrlFromThePlaceId(): void
	{
		$this->assertSame('https://www.google.com/maps/place/?q=place_id:ChIJExample-Place_ID0', Reviews::profileUrl('ChIJExample-Place_ID0'), 'profile URL');
		$this->assertTrue(Reviews::profileUrl('') === null && Reviews::profileUrl(null) === null && Reviews::profileUrl('a b"<') === null, 'profile URL without/invalid id');
	}

	public function testRulesAsOptions(): void
	{
		$today = $this->today();
		$out = Reviews::select([$this->review('Four', 4, '2026-09-01'), $this->review('Three', 3, '2026-09-02'), $this->review('Five', 5, '2026-09-03')], ['minRating' => 4, 'maxCount' => 1], $today);
		$this->assertSame(['Five'], array_column($out, 'name'), 'minRating 4 lets four stars in, maxCount 1 keeps the newest');
		$out = Reviews::select([$this->review('Four', 4, '2026-09-01'), $this->review('Five', 5, '2026-09-03')], ['minRating' => 4, 'maxCount' => 5], $today);
		$this->assertSame(['Five', 'Four'], array_column($out, 'name'), 'both with maxCount 5');
		$this->assertSame(0, count(Reviews::select([$this->review('Any', 5, '2026-09-01')], ['maxCount' => 0], $today)), 'maxCount 0 shows no single review');
		$this->assertSame($this->wall(), Reviews::select([$this->review('Long', 5, '2026-09-01', $this->wall())], ['truncate' => 0], $today)[0]['text'], 'truncate 0 keeps the whole text');
		$this->assertSame(1, count(Reviews::select([$this->review('Short', 5, '2026-09-02', 'Great place, top!')], ['minLength' => 0], $today)), 'minLength 0 lets short texts in');
	}

	public function testTextIsTrimmedBeforeTheLengthCheck(): void
	{
		$padded = $this->review('Padded', 5, '2026-09-01', str_repeat(' ', 90) . 'short' . str_repeat(' ', 90));
		$this->assertSame([], Reviews::select([$padded], Reviews::defaultRules(), $this->today()), 'trim before length check');
	}

	public function testThreeNewestAreShownNewestFirst(): void
	{
		$out = Reviews::select([
			$this->review('A', 5, '2026-01-10'), $this->review('B', 5, '2026-09-10'), $this->review('C', 5, '2026-05-10'),
			$this->review('D', 5, '2026-07-10'), $this->review('E', 5, '2025-11-10'),
		], Reviews::defaultRules(), $this->today());
		$this->assertSame(['B', 'D', 'C'], array_column($out, 'name'), 'three newest, newest first');
		$this->assertSame('September 2026', $out[0]['month'], 'month and year');
		$this->assertSame('Google user', Reviews::select([$this->review('  ', 5, '2026-09-01')], Reviews::defaultRules(), $this->today())[0]['name'], 'empty name becomes the anonymous label');
	}

	public function testTruncationCutsAtAWordBoundary(): void
	{
		$cut = Reviews::truncate($this->wall());
		$this->assertTrue(mb_strlen($cut) <= Reviews::TRUNCATE + 1 && str_ends_with($cut, 'Word…'), 'cut at word boundary');
		$this->assertSame('Short.', Reviews::truncate('Short.'), 'short text stays');
		$this->assertSame(Reviews::TRUNCATE + 1, mb_strlen(Reviews::truncate(str_repeat('ä', 400))), 'cut without spaces, multibyte safe');
		$this->assertSame('Hello…', Reviews::truncate('Hello world', 8), 'trailing punctuation removed before the ellipsis');
	}

	public function testWeakOldAndBlankReviewsFallOut(): void
	{
		// 4 stars, too short, too old, blank text fall out.
		$out = Reviews::select([
			$this->review('Four', 4, '2026-09-01'),
			$this->review('Short', 5, '2026-09-02', 'Great place, top!'),
			$this->review('Old', 5, '2024-10-04'),
			$this->review('Blank', 5, '2026-09-03', '   '),
			$this->review('Fits', 5, '2026-08-01'),
		], Reviews::defaultRules(), $this->today());
		$this->assertSame(['Fits'], array_column($out, 'name'), '4 stars / short / old / blank fall out');
	}

	private function today(): DateTimeImmutable
	{
		return new DateTimeImmutable('2026-10-05', new DateTimeZone('Europe/Berlin'));
	}

	private function wall(): string
	{
		return trim(str_repeat('Word ', 100));
	}
}
