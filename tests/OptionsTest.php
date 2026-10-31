<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Koe\Options;

/**
 * Options are read as `sayamaapps.koe.<key>`, with the 2.3 names `koe.<key>`
 * as fallback (OptionsPrefixTest). Reading an option raises no notice in debug mode.
 */
#[CoversClass(Options::class)]
final class OptionsTest extends TestCase
{
	/** A Kirby app with these options, in a throwaway directory. */
	private function app(array $options): array
	{
		$tmp = sys_get_temp_dir() . '/koe-options-' . bin2hex(random_bytes(6));
		foreach (['public', 'content', 'site', 'storage'] as $dir) {
			mkdir($tmp . '/' . $dir, 0700, true);
		}
		$roots = ['index' => $tmp . '/public', 'base' => $tmp, 'content' => $tmp . '/content', 'site' => $tmp . '/site', 'storage' => $tmp . '/storage'];
		$kirby = new App(['roots' => $roots, 'options' => ['url' => 'http://example.invalid'] + $options]);
		return [$kirby, $tmp];
	}

	/** Runs $read and returns its result and every deprecation it triggered. */
	private function collectDeprecations(callable $read): array
	{
		$seen = [];
		set_error_handler(function (int $level, string $message) use (&$seen): bool {
			if ($level === E_USER_DEPRECATED) {
				$seen[] = $message;
				return true;
			}
			return false;
		}, E_USER_DEPRECATED);
		try {
			$result = $read();
		} finally {
			restore_error_handler();
		}
		return [$result, $seen];
	}

	public function testDefaultAppliesWhenNeitherIsSet(): void
	{
		$this->with([], function (App $kirby): void {
			Assert::assertSame(7, Options::get($kirby, 'minRating', 7), 'the default applies');
			Assert::assertNull(Options::placeId($kirby));
		});
	}

	public function testKoeOptionIsReadAndDefaultsApply(): void
	{
		[$kirby, $tmp] = $this->app(['koe.placeId' => 'ChIJNew']);
		try {
			Assert::assertSame('ChIJNew', Options::placeId($kirby));
			Assert::assertSame(3, Options::get($kirby, 'minRating', 3), 'a missing option gives the default at the read');
			Assert::assertNull(Options::get($kirby, 'logo'), 'a missing option without default is null');
		} finally {
			App::destroy();
			Dir::remove($tmp);
		}
	}

	public function testLegacyHelperReadsTheNewName(): void
	{
		require_once dirname(__DIR__) . '/legacy.php';

		[$logo, $deprecations] = $this->with(['sayamaapps.koe.logo' => 'new.png', 'debug' => true], fn () => $this->collectDeprecations(fn () => koe_option('logo')));
		Assert::assertSame('new.png', $logo, 'koe_option() reads sayamaapps.koe.logo');
		Assert::assertSame([], $deprecations, 'no notice');
	}

	public function testNewNameIsRead(): void
	{
		[$placeId, $deprecations] = $this->with(['sayamaapps.koe.placeId' => 'ChIJNew', 'debug' => true], fn (App $kirby) => $this->collectDeprecations(fn () => Options::placeId($kirby)));
		Assert::assertSame('ChIJNew', $placeId, 'sayamaapps.koe.placeId is read');
		Assert::assertSame([], $deprecations, 'no notice for the new name');
	}

	public function testNewNameWinsWhenBothAreSet(): void
	{
		$this->with(['sayamaapps.koe.placeId' => 'ChIJNew', 'koe.placeId' => 'ChIJOld'], function (App $kirby): void {
			Assert::assertSame('ChIJNew', Options::placeId($kirby), 'the new name wins');
			Assert::assertSame('ChIJNew', Options::get($kirby, 'placeId'), 'get() as well');
		});
	}

	public function testNullNewNameFallsBackToOldName(): void
	{
		$this->with(['sayamaapps.koe.placeId' => null, 'koe.placeId' => 'ChIJOld'], function (App $kirby): void {
			Assert::assertSame('ChIJOld', Options::placeId($kirby), 'null is not set: the 2.3 name is read');
		});
	}

	public function testReadingRaisesNoNoticeInDebugMode(): void
	{
		[$kirby, $tmp] = $this->app(['koe.placeId' => 'ChIJOld', 'debug' => true]);
		try {
			[$placeId, $deprecations] = $this->collectDeprecations(fn () => Options::placeId($kirby));
			Assert::assertSame('ChIJOld', $placeId);
			Assert::assertSame([], $deprecations);
		} finally {
			App::destroy();
			Dir::remove($tmp);
		}
	}

	public function testSetKeyNamesTheSpellingTheSiteUses(): void
	{
		$names = [
			'only the 2.3 name is set: it is named' => ['koe.placeId' => 'ChIJOld'],
			'the new name is named' => ['sayamaapps.koe.placeId' => 'ChIJNew'],
			'nothing set: the new name' => [],
		];
		$expected = ['koe.placeId', 'sayamaapps.koe.placeId', 'sayamaapps.koe.placeId'];
		foreach (array_values($names) as $i => $options) {
			$name = $this->with($options, fn (App $kirby) => Options::setKey($kirby, 'placeId'));
			Assert::assertSame($expected[$i], $name, array_keys($names)[$i]);
		}
	}

	/** Runs $check with a Kirby app with these options, then removes the app and its directory. */
	private function with(array $options, callable $check): mixed
	{
		[$kirby, $tmp] = $this->app($options);
		try {
			return $check($kirby);
		} finally {
			App::destroy();
			Dir::remove($tmp);
		}
	}
}
