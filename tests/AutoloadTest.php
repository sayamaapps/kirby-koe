<?php

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;

/**
 * The class map in autoload.php lists every class of src/, under the right
 * name, and nothing else.
 */
#[CoversNothing]
final class AutoloadTest extends TestCase
{
	public function testClassMapListsEverySourceFile(): void
	{
		$base = dirname(__DIR__);
		$map = require $base . '/autoload.php';

		$sources = [];
		foreach (glob($base . '/src/*.php') ?: [] as $file) {
			$sources[] = 'src/' . basename($file);
		}
		sort($sources);

		$listed = array_values($map);
		sort($listed);
		Assert::assertSame($sources, $listed, 'every file in src/ is in the class map, and nothing else');
	}

	public function testClassMapKeysMatchTheClassNames(): void
	{
		$map = require dirname(__DIR__) . '/autoload.php';

		foreach ($map as $class => $file) {
			$expected = strtolower('Sayamaapps\\Koe\\' . basename($file, '.php'));
			Assert::assertSame($expected, $class, "$file is listed under the wrong key");
		}
	}
}
