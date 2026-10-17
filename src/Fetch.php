<?php

namespace Koe;

use Closure;

/**
 * The nightly fetch behind bin/koe-fetch: settings from the environment (or
 * arguments), one text search, one details call, then the data file.
 *
 * The file is replaced by every successful answer, an empty one included:
 * what Google returns now is the current state. `fetchedAt` is the day the
 * content last changed, `checkedAt` the day of the last successful fetch. When
 * only that day moves, the file is rewritten with the new `checkedAt` and
 * nothing else — the renderer hides a file whose `checkedAt` is too old
 * (`koe.maxAgeDays`), so a fetch that found nothing new must still say it ran.
 * Failed fetches (transport, API) leave the file as it is.
 *
 * With an expected place id (KOE_PLACE_ID, the same value as the Kirby option
 * `koe.placeId`) the fetch stops with exit 1 and writes nothing when the search
 * or the details are about another place. Without one, the first hit of the
 * text search is taken, as before.
 *
 * Exit codes, kept from the pre-1.0 script so existing workflows keep their
 * logic:
 *   0  done (file written, or already stamped today and left alone)
 *   1  error: no query, nothing found, other place than expected, API or write failure
 *   3  no GOOGLE_PLACES_API_KEY — "skipped", a workflow turns this into a notice
 *
 * The key is never printed. Google's error messages are scrubbed of it too.
 */
final class Fetch
{
    public const EXIT_OK = 0;
    public const EXIT_ERROR = 1;
    public const EXIT_NO_KEY = 3;

    public const ENV_KEY = 'GOOGLE_PLACES_API_KEY';
    public const ENV_QUERY = 'KOE_QUERY';
    public const ENV_FILE = 'KOE_FILE';
    public const ENV_LANGUAGE = 'KOE_LANGUAGE';
    public const ENV_PLACE_ID = 'KOE_PLACE_ID';

    /** Fields that may differ between two runs without counting as a change. */
    private const VOLATILE = ['fetchedAt', 'checkedAt', 'note'];

    /**
     * @param array<string,string> $env       usually getenv()
     * @param list<string>         $argv      `--query=…`, `--file=…`, `--language=…`, `--place-id=…`, `--help`
     * @param ?Closure             $transport see Places; null = the real one
     * @param string               $cwd       the project root a relative file is taken from
     * @param resource             $out
     * @param resource             $err
     */
    public static function run(array $env, array $argv, ?Closure $transport, string $cwd, $out, $err, ?string $today = null): int
    {
        $args = self::arguments($argv);
        if (isset($args['help'])) {
            fwrite($out, self::usage());
            return self::EXIT_OK;
        }

        $key = trim((string) ($env[self::ENV_KEY] ?? ''));
        if ($key === '') {
            fwrite($out, "No " . self::ENV_KEY . " set — nothing to do.\n"
                . "Store the key as a repository secret, then the nightly update runs.\n");
            return self::EXIT_NO_KEY;
        }

        // Checked before any request: without a query the first hit would be luck.
        $query = trim((string) ($args['query'] ?? $env[self::ENV_QUERY] ?? ''));
        if ($query === '') {
            fwrite($err, self::ENV_QUERY . " is missing (name and address as in the Google profile) — set it in the workflow under env: or pass --query.\n");
            return self::EXIT_ERROR;
        }
        $language = trim((string) ($args['language'] ?? $env[self::ENV_LANGUAGE] ?? '')) ?: 'en';
        $expectedId = trim((string) ($args['place-id'] ?? $env[self::ENV_PLACE_ID] ?? ''));
        $file = Options::resolve(trim((string) ($args['file'] ?? $env[self::ENV_FILE] ?? '')) ?: Options::DEFAULT_FILE, $cwd);
        $today ??= date('Y-m-d');

        try {
            $places = new Places($transport ?? Places::defaultTransport(), $key);
            $place = $places->search($query, $language);
            fwrite($out, 'Found: ' . $place['name'] . ' (' . $place['id'] . ")\n");
            if ($expectedId !== '' && $place['id'] !== $expectedId) {
                fwrite($err, self::mismatch($expectedId, $place['id'], $query, $place['name']));
                return self::EXIT_ERROR;
            }
            $details = $places->details($place['id'], $language);
        } catch (PlacesException $e) {
            fwrite($err, Places::scrub($e->getMessage(), $key) . "\n");
            return self::EXIT_ERROR;
        }

        // The place the details are about is what the file will claim to be.
        $placeId = trim((string) ($details['id'] ?? '')) ?: $place['id'];
        if ($expectedId !== '' && $placeId !== $expectedId) {
            fwrite($err, self::mismatch($expectedId, $placeId, $query, $place['name']));
            return self::EXIT_ERROR;
        }

        ['data' => $data, 'messages' => $messages] = Places::parse($details, $placeId, $today);
        fwrite($out, implode("\n", $messages) . "\n");

        $legacy = Options::resolve(Options::LEGACY_FILE, $cwd);
        if (is_file($legacy) && realpath($legacy) !== realpath($file)) {
            fwrite($out, "Note: the legacy file " . Options::LEGACY_FILE . " still exists; the site reads it only while "
                . Options::DEFAULT_FILE . " is missing. Remove it from the repository (git rm) once this file is committed.\n");
        }

        if ($data['reviews'] === []) {
            fwrite($out, "Google returned no usable reviews; that is the current state.\n");
        }

        try {
            $written = self::write($file, $data);
        } catch (\RuntimeException $e) {
            fwrite($err, Places::scrub($e->getMessage(), $key) . "\n");
            return self::EXIT_ERROR;
        }
        fwrite($out, ($written ? 'Written: ' : 'Unchanged (already checked today), left as it is: ') . $file . "\n");
        return self::EXIT_OK;
    }

    private static function mismatch(string $expected, string $found, string $query, string $name): string
    {
        return 'Place id mismatch: ' . self::ENV_PLACE_ID . ' (koe.placeId) is ' . $expected . ', but the search for "' . $query
            . '" led to ' . ($name !== '' ? $name . ' ' : '') . '(' . $found . "). Nothing was written.\n"
            . 'Check ' . self::ENV_QUERY . " (name and address as in the Google profile). If the business really has a new Google profile, "
            . 'set the new place id in ' . self::ENV_PLACE_ID . " and koe.placeId.\n";
    }

    /**
     * Writes the file. Same content as the existing file (apart from the
     * volatile fields): only `checkedAt` moves to the new day and `fetchedAt`,
     * the day the content last changed, stays; when that day is already in the
     * file, nothing is written. Returns whether it was written.
     *
     * @throws \RuntimeException when the directory or file cannot be written
     */
    public static function write(string $file, array $data): bool
    {
        $existing = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (is_array($existing) && self::comparable($existing) === self::comparable($data)) {
            // Same content in a format 2 file: only the check day may move. (The
            // same content in a pre-1.0 file is upgraded by the full write below.)
            $checked = (string) ($data['checkedAt'] ?? $data['fetchedAt'] ?? '');
            if (isset($existing['reviews']) && $checked !== '') {
                if (($existing['checkedAt'] ?? null) === $checked) {
                    return false;
                }
                $data = self::stamped($existing, $checked);
            }
        }
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Cannot create directory ' . $dir);
        }
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false || @file_put_contents($file, $json . "\n") === false) {
            throw new \RuntimeException('Cannot write ' . $file);
        }
        return true;
    }

    /** The existing format 2 file with only `checkedAt` set, placed right after `fetchedAt`. */
    private static function stamped(array $existing, string $checked): array
    {
        unset($existing['checkedAt']);
        if (!array_key_exists('fetchedAt', $existing)) {
            return ['checkedAt' => $checked] + $existing;
        }
        $out = [];
        foreach ($existing as $key => $value) {
            $out[$key] = $value;
            if ($key === 'fetchedAt') {
                $out['checkedAt'] = $checked;
            }
        }
        return $out;
    }

    /**
     * The data without the fields that change on every run — in either file
     * format. A file the reader cannot normalize (no rating, unknown format)
     * is compared as it is, so two such files do not count as equal.
     */
    public static function comparable(array $data): array
    {
        $normalized = Reviews::normalize($data) ?? $data;
        unset($normalized['format']);
        foreach (self::VOLATILE as $field) {
            unset($normalized[$field]);
        }
        return $normalized;
    }

    /** @return array<string,string|true> */
    public static function arguments(array $argv): array
    {
        $args = [];
        foreach (array_slice($argv, 1) as $arg) {
            if ($arg === '-h' || $arg === '--help') {
                $args['help'] = true;
            } elseif (preg_match('/^--(query|file|language|place-id)=(.*)$/s', $arg, $m)) {
                $args[$m[1]] = $m[2];
            }
        }
        return $args;
    }

    public static function usage(): string
    {
        return <<<TXT
        Usage: koe-fetch [--query="Name, Street 1, 12345 Town"] [--file=site/data/reviews.json] [--language=en] [--place-id=ChIJ…]

        Environment: GOOGLE_PLACES_API_KEY (required; missing = exit 3), KOE_QUERY,
        KOE_FILE (relative to the current directory), KOE_LANGUAGE,
        KOE_PLACE_ID (the expected place, same value as koe.placeId: another
        place = exit 1, nothing written).
        Exit codes: 0 done, 1 error, 3 no key.

        TXT;
    }
}
