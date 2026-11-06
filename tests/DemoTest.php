<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Koe\Demo;
use Sayamaapps\Koe\I18n;
use Sayamaapps\Koe\Places;
use Sayamaapps\Koe\Reviews;

/**
 * The sample reviews of the demo mode: the form Places::parse() delivers,
 * dated relative to today, and through the selection rules unchanged —
 * without Kirby.
 *   vendor/bin/phpunit
 *
 * Global classes (DateTimeImmutable, ...) are used without import: a use
 * statement for a global name has no effect in PHP, and PHP warns about it.
 * @SuppressWarnings(PHPMD.MissingImport)
 */
#[CoversClass(Demo::class)]
final class DemoTest extends TestCase
{
	protected function tearDown(): void
	{
		I18n::setLanguage('en');
	}

	public function testSampleDataHasTheFormOfAParsedAnswer(): void
	{
		$today = new DateTimeImmutable('2026-11-06');
		$demo = Demo::data($today, 'ChIJExample');
		$live = Places::parse(json_decode((string)file_get_contents(__DIR__ . '/fixtures/places-details.json'), true), 'ChIJExample', '2026-11-06')['data'];
		$this->assertSame(array_keys($live), array_keys($demo), 'same top-level keys as Places::parse()');
		$this->assertGreaterThanOrEqual(5, count($demo['reviews']));
		$this->assertLessThanOrEqual(6, count($demo['reviews']));
		foreach ($demo['reviews'] as $review) {
			foreach (['id', 'name', 'date', 'rating', 'text'] as $key) {
				$this->assertArrayHasKey($key, $review);
			}
			$this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $review['date']);
			$this->assertLessThan('2026-11-06', $review['date'], 'dated before today');
			$this->assertNull($review['url'], 'no link to Google');
			$this->assertNull($review['avatar'], 'no profile picture');
		}
		$this->assertSame(Demo::RATING, $demo['rating']);
		$this->assertSame(Demo::COUNT, $demo['count']);
		$this->assertCount(3, array_unique(array_column($demo['reviews'], 'rating')), 'samples with 3, 4 and 5 stars');
	}

	public function testSampleDataPassesTheRulesUnchanged(): void
	{
		$today = new DateTimeImmutable('2026-11-06');
		$data = Demo::data($today);
		$shown = Reviews::select($data['reviews'], Reviews::defaultRules(), $today);
		$this->assertCount(3, $shown, 'default rules: three of the samples');
		$this->assertSame(['demo-1', 'demo-2', 'demo-4'], array_column($shown, 'id'), '5 stars, 80+ characters, newest first');
		$approved = Reviews::select($data['reviews'], ['approved' => ['demo-4'], 'minRating' => 1, 'minLength' => 0] + Reviews::defaultRules(), $today);
		$this->assertSame(['demo-4'], array_column($approved, 'id'), 'the approved list works on the sample ids');
		$this->assertSame(['demo-1', 'demo-2', 'demo-3'], array_column(Reviews::select($data['reviews'], ['minRating' => 1, 'minLength' => 0] + Reviews::defaultRules(), $today), 'id'));
	}
}
