<?php

namespace Sayamaapps\Koe;

/**
 * Strings of the default snippet, Kirby-free.
 *
 * The tables live in translations/<language>.php and are also registered as
 * Kirby plugin translations under `koe.<key>` (index.php), so site snippets
 * can use t('koe.readAll'). Koe itself reads its own tables: a missing Kirby
 * translation must never silently turn a German section English.
 *
 * The language is set once per request (index.php, from Options::language())
 * and defaults to English. Missing keys fall back to English, then to the key.
 *
 * @package   Sayamaapps\Koe
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-koe
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class I18n
{
	/** Languages Koe ships. English is the default and the fallback. */
	public const LANGUAGES = ['en', 'de'];

	private static string $language = 'en';

	/** @var array<string, array<string,string>> */
	private static array $tables = [];

	public static function language(): string
	{
		return self::$language;
	}

	/** "2026-07-10" becomes "July 2026" / "Juli 2026". */
	public static function monthYear(string $isoDate): string
	{
		$month = (int)substr($isoDate, 5, 2);
		if ($month < 1 || $month > 12) {
			return substr($isoDate, 0, 4);
		}
		return trim(self::t('month.' . $month) . ' ' . substr($isoDate, 0, 4));
	}

	/** Number with the decimal separator of the current language ("4,8" / "4.8"). */
	public static function number(int|float $value, int $decimals = 1): string
	{
		return self::$language === 'de'
			? number_format($value, $decimals, ',', '')
			: number_format($value, $decimals, '.', '');
	}

	public static function setLanguage(string $language): void
	{
		self::$language = in_array($language, self::LANGUAGES, true) ? $language : 'en';
	}

	/** `{name}` placeholders are replaced with the given values, unescaped. */
	public static function t(string $key, array $values = []): string
	{
		$text = self::table(self::$language)[$key] ?? self::table('en')[$key] ?? $key;
		if ($values === []) {
			return $text;
		}
		$pairs = [];
		foreach ($values as $name => $value) {
			$pairs['{' . $name . '}'] = (string)$value;
		}
		return strtr($text, $pairs);
	}

	/** @return array<string,string> */
	public static function table(string $language): array
	{
		if (!in_array($language, self::LANGUAGES, true)) {
			return [];
		}
		return self::$tables[$language] ??= require dirname(__DIR__) . '/translations/' . $language . '.php';
	}
}
