<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Koe\Options;

/**
 * Options are read as `koe.<key>`. Nothing else is read: a key with another
 * prefix is ignored, and reading an option raises no notice in debug mode.
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

	public function testOtherPrefixIsNotRead(): void
	{
		[$kirby, $tmp] = $this->app(['sayamaapps.koe.placeId' => 'ChIJOther', 'debug' => true]);
		try {
			[$placeId, $deprecations] = $this->collectDeprecations(fn () => Options::placeId($kirby));
			Assert::assertNull($placeId, 'the next major version reads sayamaapps.koe.*, not this one');
			Assert::assertSame([], $deprecations, 'no notice in debug mode');
		} finally {
			App::destroy();
			Dir::remove($tmp);
		}
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
}
