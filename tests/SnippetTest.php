<?php

use Kirby\Cms\App;
use Kirby\Filesystem\Dir;
use Kirby\Template\Snippet;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Sayamaapps\Koe\Options;
use Sayamaapps\Koe\Places;
use Sayamaapps\Koe\Plugin;
use Sayamaapps\Koe\Store;

/**
 * The default snippet in Kirby's debug mode with the new option names: title
 * and logo are read without the deprecated koe_option(), so no deprecation is
 * raised for the page.
 *   vendor/bin/phpunit
 */
#[CoversClass(\Sayamaapps\Koe\Options::class)]
final class SnippetTest extends TestCase
{
	private string $tmp;

	protected function setUp(): void
	{
		$this->tmp = sys_get_temp_dir() . '/koe-snippet-' . bin2hex(random_bytes(6));
		foreach (['public', 'content/home', 'site/plugins', 'site/templates', 'storage'] as $dir) {
			mkdir($this->tmp . '/' . $dir, 0700, true);
		}
		symlink(dirname(__DIR__), $this->tmp . '/site/plugins/koe');
		file_put_contents($this->tmp . '/site/templates/default.php', 'Fixture');
		file_put_contents($this->tmp . '/content/home/home.txt', 'Title: Home');
	}

	protected function tearDown(): void
	{
		App::destroy();
		Snippet::$cache = [];
		Dir::remove($this->tmp);
	}

	public function testDebugModeRendersTitleAndLogoWithoutDeprecation(): void
	{
		$key = 'AIza-not-a-real-key-0000';
		$details = (string)file_get_contents(__DIR__ . '/fixtures/places-details.json');
		Snippet::$cache = [];
		$kirby = new App(['roots' => [
			'index' => $this->tmp . '/public',
			'base' => $this->tmp,
			'content' => $this->tmp . '/content',
			'site' => $this->tmp . '/site',
			'storage' => $this->tmp . '/storage',
		], 'options' => [
			'url' => 'http://example.invalid',
			'debug' => true,
			'sayamaapps.koe.active' => true,
			'sayamaapps.koe.placeId' => 'ChIJTestPlace_Id0',
			'sayamaapps.koe.key' => $key,
			'sayamaapps.koe.title' => 'Voices',
			'sayamaapps.koe.logo' => 'assets/logo.svg',
		]]);
		// The cache is filled the way a first visitor's refresh fills it.
		$transport = function (string $url, array $headers, string|null $body) use ($details): array {
			return str_starts_with($url, Places::ENDPOINT) ? ['status' => 200, 'body' => $details] : ['status' => 404, 'body' => ''];
		};
		Store::refresh(
			$kirby->cache(Plugin::CACHE),
			$transport,
			$key,
			['placeId' => 'ChIJTestPlace_Id0', 'language' => 'en', 'minutes' => 1440],
			Options::rules($kirby),
			new DateTimeImmutable('2026-10-05', new DateTimeZone('Europe/Berlin'))
		);

		$deprecations = [];
		set_error_handler(function (int $level, string $message) use (&$deprecations): bool {
			$deprecations[] = $message;
			return true;
		});
		try {
			$html = html_entity_decode((string)$kirby->snippet('koe/reviews', [], true));
		} finally {
			restore_error_handler();
		}

		$this->assertSame([], $deprecations, 'the default snippet raises no deprecation in debug mode');
		$this->assertStringContainsString('<h2 id="koe-title">Voices</h2>', $html);
		$this->assertStringContainsString('<img class="koe-logo" src="http://example.invalid/assets/logo.svg"', $html);
	}
}
