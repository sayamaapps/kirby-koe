<?php

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Koe\I18n;

/**
 * Translations: every language has the same keys and placeholders as
 * English, and numbers and months follow the language.
 *   vendor/bin/phpunit
 */
#[CoversClass(\Sayamaapps\Koe\I18n::class)]
final class I18nTest extends TestCase
{
	/** The placeholder names of a text, sorted. */
	private function placeholders(string $text): array
	{
		preg_match_all('/\{(\w+)\}/', $text, $m);
		$names = array_unique($m[1]);
		sort($names);
		return $names;
	}

	protected function tearDown(): void
	{
		I18n::setLanguage('en');
	}

	public function testEnglishFormattingAndFallback(): void
	{
		I18n::setLanguage('fr');
		$this->assertSame('en', I18n::language(), 'unknown language falls back to English');
		$this->assertSame('Read all on Google', I18n::t('readAll'), 'English default');
		$this->assertSame('no.such.key', I18n::t('no.such.key'), 'missing key returns the key');
		$this->assertSame('201 reviews on', I18n::t('countOn', ['count' => 201]), 'placeholder replaced');
		$this->assertSame('4.8', I18n::number(4.8), 'English decimal point');
		$this->assertSame('5.0', I18n::number(5), 'one decimal always');
		$this->assertSame('July 2026', I18n::monthYear('2026-07-14'), 'English month');
		$this->assertSame('2026', I18n::monthYear('2026-13-01'), 'nonsense month: year only');
	}

	public function testEveryLanguageHasTheEnglishKeysAndPlaceholders(): void
	{
		$en = I18n::table('en');
		$this->assertTrue(count($en) > 10, 'English table loaded');
		foreach (I18n::LANGUAGES as $language) {
			$table = I18n::table($language);
			$this->assertSame([], array_values(array_diff(array_keys($en), array_keys($table))), "$language: no missing keys");
			$this->assertSame([], array_values(array_diff(array_keys($table), array_keys($en))), "$language: no extra keys");
			foreach ($en as $key => $text) {
				$this->assertTrue(is_string($table[$key]) && trim($table[$key]) !== '', "$language.$key not empty");
				$this->assertSame($this->placeholders($text), $this->placeholders($table[$key]), "$language.$key: same placeholders as English");
			}
		}
	}

	public function testGermanStringsNumbersAndMonths(): void
	{
		I18n::setLanguage('de');
		$this->assertSame('de', I18n::language(), 'German selected');
		$this->assertSame('Alle auf Google lesen', I18n::t('readAll'), 'German string');
		$this->assertSame('4,8', I18n::number(4.8), 'German decimal comma');
		$this->assertSame('Juli 2026', I18n::monthYear('2026-07-14'), 'German month');
		$this->assertSame('März 2027', I18n::monthYear('2027-03-01'), 'German month with umlaut');
	}

	public function testUnknownLanguageHasNoTable(): void
	{
		$this->assertSame([], I18n::table('fr'), 'unknown language: no table');
	}
}
