<?php
/**
 * Translations: every language has the same keys and placeholders as
 * English, and numbers and months follow the language.
 *   php tests/I18nTest.php
 */
require_once __DIR__ . '/../src/I18n.php';

use Koe\I18n;

$checks = 0;
function expect($expected, $actual, string $what): void
{
    global $checks;
    if ($expected !== $actual) { throw new RuntimeException($what . ': ' . var_export($actual, true)); }
    $checks++;
}
$placeholders = function (string $text): array {
    preg_match_all('/\{(\w+)\}/', $text, $m);
    $names = array_unique($m[1]);
    sort($names);
    return $names;
};

try {
    $en = I18n::table('en');
    expect(true, count($en) > 10, 'English table loaded');
    foreach (I18n::LANGUAGES as $language) {
        $table = I18n::table($language);
        expect([], array_values(array_diff(array_keys($en), array_keys($table))), "$language: no missing keys");
        expect([], array_values(array_diff(array_keys($table), array_keys($en))), "$language: no extra keys");
        foreach ($en as $key => $text) {
            expect(true, is_string($table[$key]) && trim($table[$key]) !== '', "$language.$key not empty");
            expect($placeholders($text), $placeholders($table[$key]), "$language.$key: same placeholders as English");
        }
    }
    expect([], I18n::table('fr'), 'unknown language: no table');

    I18n::setLanguage('fr');
    expect('en', I18n::language(), 'unknown language falls back to English');
    expect('Read all on Google', I18n::t('readAll'), 'English default');
    expect('no.such.key', I18n::t('no.such.key'), 'missing key returns the key');
    expect('201 reviews on', I18n::t('countOn', ['count' => 201]), 'placeholder replaced');
    expect('4.8', I18n::number(4.8), 'English decimal point');
    expect('5.0', I18n::number(5), 'one decimal always');
    expect('July 2026', I18n::monthYear('2026-07-14'), 'English month');
    expect('2026', I18n::monthYear('2026-13-01'), 'nonsense month: year only');

    I18n::setLanguage('de');
    expect('de', I18n::language(), 'German selected');
    expect('Alle auf Google lesen', I18n::t('readAll'), 'German string');
    expect('4,8', I18n::number(4.8), 'German decimal comma');
    expect('Juli 2026', I18n::monthYear('2026-07-14'), 'German month');
    expect('März 2027', I18n::monthYear('2027-03-01'), 'German month with umlaut');
    I18n::setLanguage('en');

    echo "Koe I18n: all $checks checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Koe I18n: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
}
