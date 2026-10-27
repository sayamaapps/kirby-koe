<?php

namespace Sayamaapps\Koe;

use Closure;

/**
 * The lookup behind bin/koe-fetch: settings from the environment (or
 * arguments), one text search, one details call, then a report — the place
 * id (for `sayamaapps.koe.placeId`) and every review with its id (for `sayamaapps.koe.approved`).
 * It writes nothing: the site fetches on its own, when a visitor needs the
 * reviews (Store).
 *
 * Exit codes, kept from the pre-1.0 script:
 *   0  done
 *   1  error: no query, nothing found, API failure
 *   3  no GOOGLE_PLACES_API_KEY
 *
 * The key is never printed. Google's error messages are scrubbed of it too.
 *
 * @author    Simon Räder
 * @link      https://github.com/sayamaapps/kirby-koe
 * @copyright Simon Räder
 * @license   https://opensource.org/licenses/MIT
 */
class Fetch
{
	public const ENV_KEY = 'GOOGLE_PLACES_API_KEY';
	public const ENV_LANGUAGE = 'KOE_LANGUAGE';
	public const ENV_QUERY = 'KOE_QUERY';
	public const EXIT_ERROR = 1;
	public const EXIT_NO_KEY = 3;
	public const EXIT_OK = 0;

	/** @return array<string,string|true> */
	public static function arguments(array $argv): array
	{
		$args = [];
		foreach (array_slice($argv, 1) as $arg) {
			if ($arg === '-h' || $arg === '--help') {
				$args['help'] = true;
			} elseif (preg_match('/^--(query|language)=(.*)$/s', $arg, $m)) {
				$args[$m[1]] = $m[2];
			}
		}
		return $args;
	}

	/**
	 * @param array<string,string> $env usually getenv()
	 * @param list<string> $argv `--query=…`, `--language=…`, `--help`
	 * @param $transport see Places; null = the real one
	 * @param resource $out
	 * @param resource $err
	 */
	public static function run(array $env, array $argv, Closure|null $transport, $out, $err, string|null $today = null): int
	{
		$args = self::arguments($argv);
		if (isset($args['help'])) {
			fwrite($out, self::usage());
			return self::EXIT_OK;
		}

		$key = trim((string)($env[self::ENV_KEY] ?? ''));
		if ($key === '') {
			fwrite($out, 'No ' . self::ENV_KEY . " set — nothing to do.\n");
			return self::EXIT_NO_KEY;
		}

		// Checked before any request: without a query the first hit would be luck.
		$query = trim((string)($args['query'] ?? $env[self::ENV_QUERY] ?? ''));
		if ($query === '') {
			fwrite($err, self::ENV_QUERY . " is missing (name and address as in the Google profile) — set it or pass --query.\n");
			return self::EXIT_ERROR;
		}
		$language = trim((string)($args['language'] ?? $env[self::ENV_LANGUAGE] ?? '')) ?: 'en';
		$today ??= date('Y-m-d');

		try {
			$places = new Places($transport ?? Places::defaultTransport(), $key);
			$place = $places->search($query, $language);
			fwrite($out, 'Found: ' . $place['name'] . ' (' . $place['id'] . ")\n");
			$placeId = (string)$place['id'];
			$details = $places->details($placeId, $language);
		} catch (PlacesException $e) {
			fwrite($err, Places::scrub($e->getMessage(), $key) . "\n");
			return self::EXIT_ERROR;
		}

		$placeId = trim((string)($details['id'] ?? '')) ?: $placeId;
		['data' => $data, 'messages' => $messages] = Places::parse($details, $placeId, $today);
		fwrite($out, implode("\n", $messages) . "\n");
		if ($data['reviews'] === []) {
			fwrite($out, "Google returned no usable reviews; that is the current state.\n");
		}
		fwrite($out, 'Nothing is written: the site fetches the reviews itself (sayamaapps.koe.placeId ' . $placeId . ").\n");
		return self::EXIT_OK;
	}

	public static function usage(): string
	{
		return <<<TXT
        Usage: koe-fetch [--query="Name, Street 1, 12345 Town"] [--language=en]

        Shows the place id (for sayamaapps.koe.placeId) and the reviews Google returns now,
        with their ids (for sayamaapps.koe.approved). It writes nothing; the site fetches
        the reviews itself.
        Environment: GOOGLE_PLACES_API_KEY (required; missing = exit 3), KOE_QUERY,
        KOE_LANGUAGE.
        Exit codes: 0 done, 1 error, 3 no key.

        TXT;
	}
}
