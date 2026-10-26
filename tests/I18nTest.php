<?php

use PHPUnit\Framework\Assert;
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
	private function expect(mixed $expected, mixed $actual, string $what): void
	{
		Assert::assertSame($expected, $actual, $what);
	}

	private function holds(bool $ok, string $what): void
	{
		Assert::assertTrue($ok, $what);
	}

	public function testSuite(): void
	{
		$placeholders = function (string $text): array {
			preg_match_all('/\{(\w+)\}/', $text, $m);
			$names = array_unique($m[1]);
			sort($names);
			return $names;
		};
		$en = I18n::table('en');
		$this->expect(true, count($en) > 10, 'English table loaded');
		foreach (I18n::LANGUAGES as $language) {
			$table = I18n::table($language);
			$this->expect([], array_values(array_diff(array_keys($en), array_keys($table))), "$language: no missing keys");
			$this->expect([], array_values(array_diff(array_keys($table), array_keys($en))), "$language: no extra keys");
			foreach ($en as $key => $text) {
				$this->expect(true, is_string($table[$key]) && trim($table[$key]) !== '', "$language.$key not empty");
				$this->expect($placeholders($text), $placeholders($table[$key]), "$language.$key: same placeholders as English");
			}
		}
		$this->expect([], I18n::table('fr'), 'unknown language: no table');

		I18n::setLanguage('fr');
		$this->expect('en', I18n::language(), 'unknown language falls back to English');
		$this->expect('Read all on Google', I18n::t('readAll'), 'English default');
		$this->expect('no.such.key', I18n::t('no.such.key'), 'missing key returns the key');
		$this->expect('201 reviews on', I18n::t('countOn', ['count' => 201]), 'placeholder replaced');
		$this->expect('4.8', I18n::number(4.8), 'English decimal point');
		$this->expect('5.0', I18n::number(5), 'one decimal always');
		$this->expect('July 2026', I18n::monthYear('2026-07-14'), 'English month');
		$this->expect('2026', I18n::monthYear('2026-13-01'), 'nonsense month: year only');

		I18n::setLanguage('de');
		$this->expect('de', I18n::language(), 'German selected');
		$this->expect('Alle auf Google lesen', I18n::t('readAll'), 'German string');
		$this->expect('4,8', I18n::number(4.8), 'German decimal comma');
		$this->expect('Juli 2026', I18n::monthYear('2026-07-14'), 'German month');
		$this->expect('März 2027', I18n::monthYear('2027-03-01'), 'German month with umlaut');
		I18n::setLanguage('en');
	}
}
