<?php

use PHPUnit\Framework\Assert;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

/**
 * What the installed package ships: the German privacy text is part of the
 * dist archive (not export-ignored, directly or through its folder), the
 * README links to it, and it names the data flows the code really has.
 * Skipped outside a git checkout, where .gitattributes cannot be asked.
 */
#[CoversNothing]
final class PackageTest extends TestCase
{
	/** The export-ignore state of a path as git sees it from the working tree's .gitattributes. */
	private function exportIgnored(string $path): bool
	{
		$root = dirname(__DIR__);
		if (!is_dir($root . '/.git') && !is_file($root . '/.git')) {
			$this->markTestSkipped('not a git checkout');
		}
		$out = (string)shell_exec('git -C ' . escapeshellarg($root) . ' check-attr export-ignore -- ' . escapeshellarg($path) . ' 2>/dev/null');
		return str_contains($out, 'export-ignore: set');
	}

	/** Whether the path ends up in a dist archive: it exists and neither it nor a parent folder is export-ignored. */
	private function shipped(string $path): bool
	{
		if (!file_exists(dirname(__DIR__) . '/' . $path)) {
			return false;
		}
		for ($part = $path; $part !== '.'; $part = dirname($part)) {
			if ($this->exportIgnored($part)) {
				return false;
			}
		}
		return true;
	}

	public function testGermanPrivacyTextIsShippedInThePackage(): void
	{
		$file = dirname(__DIR__) . '/docs/DATENSCHUTZ.md';
		Assert::assertFileExists($file);
		foreach (['docs', 'docs/DATENSCHUTZ.md', 'PRIVACY.md'] as $path) {
			Assert::assertFalse($this->exportIgnored($path), "$path must not be export-ignored: the README links to it");
		}
	}

	public function testPrivacyTextNamesTheRealDataFlowsAndCurrentOptionNames(): void
	{
		$text = (string)file_get_contents(dirname(__DIR__) . '/docs/DATENSCHUTZ.md');
		$flat = preg_replace('/\s+/', ' ', $text);
		foreach (['Places', 'Zwischenspeicher', 'Profilbild', 'Internetadresse unseres Servers', 'googleusercontent'] as $needle) {
			Assert::assertStringContainsString($needle, $flat, "the privacy text must mention: $needle");
		}
		foreach (['cacheHours', 'key', 'authenticityNotice'] as $option) {
			Assert::assertDoesNotMatchRegularExpression('/(?<![\w.])koe\.' . $option . '\b/', $flat, "option $option is named sayamaapps.koe.$option since 2.4");
		}
		Assert::assertStringContainsString('sayamaapps.koe.cacheHours', $flat);
		Assert::assertStringContainsString('nicht prüfen, ob die Verfasser unsere Leistungen tatsächlich in Anspruch genommen haben', $flat, 'the text names the review notice the snippet prints');
	}

	public function testReadmeLinksBothPrivacyTexts(): void
	{
		$readme = (string)file_get_contents(dirname(__DIR__) . '/README.md');
		Assert::assertStringContainsString('(docs/DATENSCHUTZ.md)', $readme);
		Assert::assertStringContainsString('(PRIVACY.md)', $readme);
		Assert::assertFileExists(dirname(__DIR__) . '/PRIVACY.md');
	}

	public function testRelativeLinksInShippedMarkdownResolveToShippedFiles(): void
	{
		$root = dirname(__DIR__);
		$docs = array_merge(['README.md', 'PRIVACY.md'], array_map(fn (string $f) => 'docs/' . basename($f), glob($root . '/docs/*.md') ?: []));
		foreach ($docs as $doc) {
			Assert::assertTrue($this->shipped($doc), "$doc is meant to be shipped");
			preg_match_all('/\]\(([^)\s]+)\)/', (string)file_get_contents($root . '/' . $doc), $matches);
			foreach ($matches[1] as $link) {
				if (preg_match('~^([a-z][a-z0-9+.-]*:|#|//)~i', $link)) {
					continue;
				}
				$target = [];
				foreach (explode('/', dirname($doc) . '/' . explode('#', $link)[0]) as $segment) {
					if ($segment === '..') {
						array_pop($target);
					} elseif ($segment !== '' && $segment !== '.') {
						$target[] = $segment;
					}
				}
				$target = implode('/', $target);
				Assert::assertTrue($this->shipped($target), "$doc links to $link, which is not in the dist archive ($target)");
			}
		}
	}
}
