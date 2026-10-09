<?php

namespace Koe;

use Closure;

/**
 * The nightly fetch behind bin/koe-fetch: settings from the environment (or
 * arguments), one text search, one details call, then the data file —
 * written only when something other than the fetch date changed, so a
 * workflow can decide with `git diff --quiet` whether to commit and deploy.
 *
 * Exit codes, kept from the pre-1.0 script so existing workflows keep their
 * logic:
 *   0  done (file written, or unchanged and left alone)
 *   1  error: no query, nothing found, API or write failure
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

    /** Fields that may differ between two runs without counting as a change. */
    private const VOLATILE = ['fetchedAt', 'note'];

    /**
     * @param array<string,string> $env       usually getenv()
     * @param list<string>         $argv      `--query=…`, `--file=…`, `--language=…`, `--help`
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
        $file = Options::resolve(trim((string) ($args['file'] ?? $env[self::ENV_FILE] ?? '')) ?: Options::DEFAULT_FILE, $cwd);
        $today ??= date('Y-m-d');

        try {
            $places = new Places($transport ?? Places::defaultTransport(), $key);
            $place = $places->search($query, $language);
            fwrite($out, 'Found: ' . $place['name'] . ' (' . $place['id'] . ")\n");
            $details = $places->details($place['id'], $language);
        } catch (PlacesException $e) {
            fwrite($err, Places::scrub($e->getMessage(), $key) . "\n");
            return self::EXIT_ERROR;
        }

        ['data' => $data, 'messages' => $messages] = Places::parse($details, $place['id'], $today);
        fwrite($out, implode("\n", $messages) . "\n");

        $legacy = Options::resolve(Options::LEGACY_FILE, $cwd);
        if (is_file($legacy) && realpath($legacy) !== realpath($file)) {
            fwrite($out, "Note: the legacy file " . Options::LEGACY_FILE . " still exists; the site reads it only while "
                . Options::DEFAULT_FILE . " is missing. Remove it from the repository (git rm) once this file is committed.\n");
        }

        try {
            $written = self::write($file, $data);
        } catch (\RuntimeException $e) {
            fwrite($err, Places::scrub($e->getMessage(), $key) . "\n");
            return self::EXIT_ERROR;
        }
        fwrite($out, ($written ? 'Written: ' : 'Unchanged (only the fetch date would differ), left as it is: ') . $file . "\n");
        return self::EXIT_OK;
    }

    /**
     * Writes the file unless the existing one has the same content apart from
     * the volatile fields. Returns whether it was written.
     *
     * @throws \RuntimeException when the directory or file cannot be written
     */
    public static function write(string $file, array $data): bool
    {
        $existing = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (is_array($existing) && self::comparable($existing) === self::comparable($data)) {
            return false;
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

    /** The data without the fields that change on every run — in either file format. */
    public static function comparable(array $data): ?array
    {
        $normalized = Reviews::normalize($data);
        if ($normalized === null) {
            return null;
        }
        unset($normalized['fetchedAt'], $normalized['format']);
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
            } elseif (preg_match('/^--(query|file|language)=(.*)$/s', $arg, $m)) {
                $args[$m[1]] = $m[2];
            }
        }
        return $args;
    }

    public static function usage(): string
    {
        return <<<TXT
        Usage: koe-fetch [--query="Name, Street 1, 12345 Town"] [--file=site/data/reviews.json] [--language=en]

        Environment: GOOGLE_PLACES_API_KEY (required; missing = exit 3), KOE_QUERY,
        KOE_FILE (relative to the current directory), KOE_LANGUAGE.
        Exit codes: 0 done, 1 error, 3 no key.

        TXT;
    }
}
