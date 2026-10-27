<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * The names of earlier versions still work: the old class names are aliases
 * of the new ones, and koe_option() reads the options and announces itself
 * as deprecated in debug mode.
 *
 * Global classes (RuntimeException, DateTimeImmutable, ...) are used without import: a use
 * statement for a global name has no effect in PHP, and PHP warns about it.
 * @SuppressWarnings(PHPMD.MissingImport)
 */
#[CoversNothing]
final class LegacyTest extends TestCase
{
	public function testKoeOptionReadsAndIsDeprecated(): void
	{
		require_once dirname(__DIR__) . '/legacy.php';

		$tmp = sys_get_temp_dir() . '/koe-legacy-' . bin2hex(random_bytes(6));
		foreach (['public', 'content', 'site', 'storage'] as $dir) {
			mkdir($tmp . '/' . $dir, 0700, true);
		}
		$roots = ['index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content', 'site' => $tmp . '/site', 'storage' => $tmp . '/storage'];
		$seen = [];
		set_error_handler(function (int $level, string $message) use (&$seen): bool {
			if ($level === E_USER_DEPRECATED) {
				$seen[] = $message;
				return true;
			}
			return false;
		}, E_USER_DEPRECATED);
		try {
			new App(['roots' => $roots, 'options' => ['url' => 'http://example.invalid', 'debug' => true, 'sayamaapps.koe.logo' => 'new.png']]);
			Assert::assertSame('new.png', koe_option('logo'), 'koe_option() reads the new spelling');
			Assert::assertSame('fallback', koe_option('title', 'fallback'), 'the default applies');
			Assert::assertCount(2, $seen, 'announced once per call');
			Assert::assertStringContainsString('koe_option() is deprecated', $seen[0]);
		} finally {
			restore_error_handler();
			App::destroy();
			Dir::remove($tmp);
		}
	}

	public function testOldClassNamesAreAliasesOfTheNewOnes(): void
	{
		require_once dirname(__DIR__) . '/legacy.php';

		foreach (['Fetch', 'I18n', 'Options', 'Places', 'PlacesException', 'Plugin', 'Reviews', 'Store'] as $name) {
			$class = new \ReflectionClass('Koe\\' . $name);
			Assert::assertSame('Sayamaapps\\Koe\\' . $name, $class->getName(), "Koe\\$name is an alias");
		}
		Assert::assertSame('Read all on Google', Koe\I18n::t('readAll'), 'the alias works');
	}
}
