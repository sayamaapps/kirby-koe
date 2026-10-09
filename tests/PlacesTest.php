<?php
/**
 * The nightly fetch against recorded Places API answers, without a network:
 * parsing, the data file, "unchanged" detection, the legacy file, and every
 * failure path (no key, no query, nothing found, quota, API error, transport
 * error). The key must never appear in any output.
 *   php tests/PlacesTest.php
 */
require_once __DIR__ . '/../src/I18n.php';
require_once __DIR__ . '/../src/Reviews.php';
require_once __DIR__ . '/../src/Options.php';
require_once __DIR__ . '/../src/PlacesException.php';
require_once __DIR__ . '/../src/Places.php';
require_once __DIR__ . '/../src/Fetch.php';

use Koe\Fetch;
use Koe\Options;
use Koe\Places;
use Koe\PlacesException;

$checks = 0;
function expect($expected, $actual, string $what): void
{
    global $checks;
    if ($expected !== $actual) { throw new RuntimeException($what . ': ' . var_export($actual, true)); }
    $checks++;
}
function removeTree(string $path): void
{
    if (!is_dir($path)) { return; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($path);
}

$search = file_get_contents(__DIR__ . '/fixtures/places-search.json');
$details = file_get_contents(__DIR__ . '/fixtures/places-details.json');
$key = 'AIza-not-a-real-key-0000';
$tmp = sys_get_temp_dir() . '/koe-fetch-' . bin2hex(random_bytes(6));
mkdir($tmp, 0700);

/** A transport that answers from a script: each entry ['status' => …, 'body' => …] or a Throwable. Records every call. */
$calls = [];
$transport = function (array $script) use (&$calls): Closure {
    return function (string $url, array $headers, ?string $body) use (&$calls, &$script): array {
        $calls[] = ['url' => $url, 'headers' => $headers, 'body' => $body];
        $next = array_shift($script) ?? ['status' => 500, 'body' => 'script exhausted'];
        if ($next instanceof Throwable) { throw $next; }
        return $next;
    };
};
$ok = fn () => $transport([['status' => 200, 'body' => $search], ['status' => 200, 'body' => $details]]);
/** Runs Fetch::run with captured output. */
$run = function (array $env, ?Closure $t, array $argv = ['koe-fetch'], ?string $today = '2026-10-05') use ($tmp): array {
    $out = fopen('php://memory', 'w+');
    $err = fopen('php://memory', 'w+');
    $code = Fetch::run($env, $argv, $t, $tmp, $out, $err, $today);
    rewind($out);
    rewind($err);
    return [$code, stream_get_contents($out), stream_get_contents($err)];
};
$env = [Fetch::ENV_KEY => $key, Fetch::ENV_QUERY => 'Example Café, Street 1, 12345 Town'];
$file = $tmp . '/' . Options::DEFAULT_FILE;

try {
    // ---- Parsing the recorded answer ------------------------------------
    ['data' => $data, 'messages' => $messages] = Places::parse(json_decode($details, true), 'ChIJTestPlace_Id0', '2026-10-05');
    expect(2, $data['format'], 'format 2');
    expect('2026-10-05', $data['fetchedAt'], 'fetch date');
    expect('ChIJTestPlace_Id0', $data['placeId'], 'place id stored');
    expect([4.8, 201], [$data['rating'], $data['count']], 'rating and count from Google');
    expect(4, count($data['reviews']), 'four of five stored (one has no text)');
    expect(['Carla Kurz', 'Bernd Vier', 'Anna Beispiel', ''], array_column($data['reviews'], 'name'), 'newest first, empty name kept empty');
    $anna = $data['reviews'][2];
    expect('ChdDSUhNMG9nS0VJQ0FnSUNfQUFB', $anna['id'], 'review id = last segment of the resource name');
    expect('2026-07-14', $anna['date'], 'date = first ten characters of publishTime');
    expect(5, $anna['rating'], 'rating');
    expect(true, str_starts_with($anna['text'], 'Wunderschönes Café') && str_contains($anna['text'], "\n"), 'original text preferred, line breaks kept');
    expect('https://www.google.com/maps/reviews/data=!4m6!14m5!1m4!2m3!1sChdDSUhNMG9nS0VJQ0FnSUNfQUFB!2m1!1s0x0:0x1', $anna['url'], 'link to the single review');
    expect('https://www.google.com/maps/contrib/100000000000000000001/reviews', $anna['authorUrl'], 'author link');
    expect('google', $anna['source'], 'source');
    $noMaps = $data['reviews'][3];
    expect('https://www.google.com/maps/contrib/100000000000000000005/reviews', $noMaps['url'], 'without googleMapsUri the author profile stands in');
    expect(true, (bool) array_filter($messages, fn ($m) => str_contains($m, 'skipped (no text): Dora Stumm')), 'skipped review is reported');
    expect(true, str_contains(end($messages), '4 reviews stored; Google reports 201 with an average of 4.8'), 'summary line');
    // Odd answers do not break parsing.
    $odd = Places::parse(['reviews' => [['rating' => 5, 'text' => ['text' => 'x']], 'junk', ['publishTime' => 'soon', 'originalText' => ['text' => 'y']]]], 'P', '2026-10-05')['data'];
    expect([null, 'P', 2], [$odd['rating'], $odd['placeId'], $odd['count']], 'no rating → null, count falls back to the list');
    expect(['2026-10-05', '2026-10-05'], array_column($odd['reviews'], 'date'), 'unparsable publishTime → today');
    expect([null, null], array_column($odd['reviews'], 'id'), 'no resource name → no id');

    // ---- The client: requests and errors ----------------------------------
    $calls = [];
    $places = new Places($ok(), $key);
    expect(['id' => 'ChIJTestPlace_Id0', 'name' => 'Example Café'], $places->search('Example Café, Street 1', 'de'), 'search returns the first hit');
    expect(Places::ENDPOINT . 'places:searchText', $calls[0]['url'], 'search URL');
    expect(['textQuery' => 'Example Café, Street 1', 'languageCode' => 'de'], json_decode($calls[0]['body'], true), 'search body');
    expect(true, in_array('X-Goog-Api-Key: ' . $key, $calls[0]['headers'], true) && in_array('X-Goog-FieldMask: places.id,places.displayName', $calls[0]['headers'], true), 'key and field mask as headers');
    $d = $places->details('ChIJTestPlace_Id0', 'de');
    expect(4.8, $d['rating'], 'details decoded');
    expect(Places::ENDPOINT . 'places/ChIJTestPlace_Id0?languageCode=de', $calls[1]['url'], 'details URL');
    expect(null, $calls[1]['body'], 'details is a GET');
    expect(true, in_array('X-Goog-FieldMask: ' . Places::DETAILS_MASK, $calls[1]['headers'], true), 'details field mask');

    $fail = function (array $script, string $what) use ($transport, $key): string {
        try {
            (new Places($transport($script), $key))->search('x');
            throw new RuntimeException("$what: no exception");
        } catch (PlacesException $e) {
            return $e->getMessage();
        }
    };
    $m = $fail([['status' => 200, 'body' => '{"places": []}']], 'empty search');
    expect('Places search found nothing for: x', $m, 'nothing found');
    $m = $fail([['status' => 403, 'body' => json_encode(['error' => ['code' => 403, 'message' => 'API key not valid: ' . $key, 'status' => 'PERMISSION_DENIED']])]], '403');
    expect('Places API answered 403 PERMISSION_DENIED API key not valid: [key]', $m, '403 with Google status and message, key scrubbed');
    $m = $fail([['status' => 429, 'body' => json_encode(['error' => ['code' => 429, 'message' => 'Quota exceeded for quota metric', 'status' => 'RESOURCE_EXHAUSTED']])]], '429');
    expect(true, str_starts_with($m, 'Places API answered 429 RESOURCE_EXHAUSTED Quota exceeded') && str_contains($m, 'daily quota'), 'quota: status plus hint');
    $m = $fail([['status' => 502, 'body' => '<html>Bad Gateway</html>']], '502');
    expect('Places API answered 502', $m, 'non-JSON error body: status only');
    $m = $fail([['status' => 200, 'body' => 'not json']], 'bad body');
    expect('Places API answered 200 with a body that is not JSON.', $m, '200 without JSON');
    $m = $fail([new RuntimeException('Could not resolve host: places.googleapis.com (' . $key . ')')], 'transport');
    expect('Places API not reachable: Could not resolve host: places.googleapis.com ([key])', $m, 'transport failure, key scrubbed');

    // ---- The CLI logic ------------------------------------------------------
    [$code, $out] = $run([], null);
    expect(Fetch::EXIT_NO_KEY, $code, 'no key: exit 3');
    expect(true, str_contains($out, 'No GOOGLE_PLACES_API_KEY set'), 'no key: message names the variable');

    $calls = [];
    [$code, $out, $err] = $run([Fetch::ENV_KEY => $key], $ok());
    expect(Fetch::EXIT_ERROR, $code, 'no query: exit 1');
    expect(true, str_contains($err, 'KOE_QUERY is missing'), 'no query: message');
    expect(0, count($calls), 'no query: no request was made');
    expect(false, str_contains($out . $err, $key), 'no query: key not printed');

    [$code, $out] = $run([Fetch::ENV_KEY => $key], null, ['koe-fetch', '--help']);
    expect(Fetch::EXIT_OK, $code, '--help');
    expect(true, str_contains($out, 'Usage: koe-fetch'), 'usage text');

    $calls = [];
    [$code, $out, $err] = $run($env, $ok());
    expect(Fetch::EXIT_OK, $code, 'success: exit 0 (' . $err . ')');
    expect(true, str_contains($out, 'Found: Example Café (ChIJTestPlace_Id0)'), 'success: found line');
    expect(true, str_contains($out, 'ChdDSUhNMG9nS0VJQ0FnSUNfQUFB  Anna Beispiel, 2026-07-14, 5 stars'), 'success: ids listed for koe.approved');
    expect(true, str_contains($out, 'Written: ' . $file), 'success: written line');
    expect(true, is_file($file), 'success: default file site/data/reviews.json created with its directory');
    $written = json_decode(file_get_contents($file), true);
    expect([2, '2026-10-05', 'ChIJTestPlace_Id0', 4], [$written['format'], $written['fetchedAt'], $written['placeId'], count($written['reviews'])], 'success: file content');
    expect(true, str_contains(file_get_contents($file), 'Wunderschönes Café'), 'success: unicode unescaped');
    expect('en', json_decode($calls[0]['body'], true)['languageCode'], 'language defaults to en');
    expect(false, str_contains($out . $err, $key), 'success: key not printed');

    // Same answer the next day: nothing but the date would change → untouched.
    $before = file_get_contents($file);
    [$code, $out] = $run($env, $ok(), ['koe-fetch'], '2026-10-06');
    expect(Fetch::EXIT_OK, $code, 'unchanged: exit 0');
    expect(true, str_contains($out, 'Unchanged'), 'unchanged: says so');
    expect($before, file_get_contents($file), 'unchanged: file byte-identical, fetch date kept');

    // A new review → written again.
    $more = json_decode($details, true);
    $more['userRatingCount'] = 202;
    [$code, $out] = $run($env, $transport([['status' => 200, 'body' => $search], ['status' => 200, 'body' => json_encode($more)]]), ['koe-fetch'], '2026-10-07');
    expect(true, $code === 0 && str_contains($out, 'Written:'), 'changed count: written');
    expect(['2026-10-07', 202], [json_decode(file_get_contents($file), true)['fetchedAt'], json_decode(file_get_contents($file), true)['count']], 'changed count: new date and count');

    // A format 1 file at the target (git mv in the switch PR) is replaced by format 2.
    file_put_contents($file, json_encode(['stand' => '2026-10-01', 'gesamtwertung' => 4.8, 'anzahl' => 201, 'bewertungen' => [['name' => 'Anna Beispiel', 'datum' => '2026-07-14', 'sterne' => 5, 'text' => 'x', 'quelle' => 'google']]]));
    [$code, $out] = $run($env, $ok());
    expect(true, $code === 0 && str_contains($out, 'Written:') && json_decode(file_get_contents($file), true)['format'] === 2, 'legacy content at the target is upgraded');

    // Legacy file next to it: a note, and the target is still written.
    $legacy = $tmp . '/' . Options::LEGACY_FILE;
    mkdir(dirname($legacy), 0700, true);
    file_put_contents($legacy, '{}');
    [$code, $out] = $run($env, $ok(), ['koe-fetch'], '2026-10-08');
    expect(true, $code === 0 && str_contains($out, 'legacy file site/daten/bewertungen.json still exists'), 'legacy file is pointed out');
    expect(true, str_contains($out, 'Unchanged'), 'legacy note does not change the outcome (same content → left alone)');
    unlink($legacy);

    // --file and KOE_FILE: relative to the working directory, or absolute; --language passed on.
    $calls = [];
    [$code, $out] = $run($env + [Fetch::ENV_FILE => 'content/reviews.json', Fetch::ENV_LANGUAGE => 'de'], $ok());
    expect(true, $code === 0 && is_file($tmp . '/content/reviews.json'), 'KOE_FILE relative to cwd');
    expect('de', json_decode($calls[0]['body'], true)['languageCode'], 'KOE_LANGUAGE passed on');
    $calls = [];
    [$code] = $run($env, $ok(), ['koe-fetch', '--file=' . $tmp . '/abs.json', '--language=fr', '--query=Other Place']);
    expect(true, $code === 0 && is_file($tmp . '/abs.json'), '--file absolute');
    expect(['textQuery' => 'Other Place', 'languageCode' => 'fr'], json_decode($calls[0]['body'], true), '--query and --language win over the environment');

    // Failures end with 1, the file stays, the key stays secret.
    $before = file_get_contents($file);
    foreach ([
        'nothing found' => [['status' => 200, 'body' => '{}']],
        'quota' => [['status' => 429, 'body' => json_encode(['error' => ['status' => 'RESOURCE_EXHAUSTED', 'message' => 'Quota exceeded']])]],
        'details fail' => [['status' => 200, 'body' => $search], ['status' => 404, 'body' => json_encode(['error' => ['status' => 'NOT_FOUND', 'message' => 'No such place']])]],
        'network' => [new RuntimeException('timeout ' . $key)],
    ] as $what => $script) {
        [$code, $out, $err] = $run($env, $transport($script), ['koe-fetch'], '2026-12-24');
        expect(Fetch::EXIT_ERROR, $code, "$what: exit 1");
        expect(true, trim($err) !== '', "$what: message on stderr");
        expect(false, str_contains($out . $err, $key), "$what: key not printed");
        expect($before, file_get_contents($file), "$what: file untouched");
    }
    expect(true, str_contains($err, 'not reachable'), 'network: message');

    // Unwritable target → 1 with a message (skipped as root, where chmod has no effect).
    if (function_exists('posix_geteuid') && posix_geteuid() !== 0) {
        $locked = $tmp . '/locked';
        mkdir($locked, 0500);
        [$code, , $err] = $run($env, $ok(), ['koe-fetch', '--file=' . $locked . '/x/reviews.json']);
        expect(Fetch::EXIT_ERROR, $code, 'unwritable: exit 1');
        expect(true, str_contains($err, 'Cannot create directory'), 'unwritable: message');
        chmod($locked, 0700);
    }

    // The real transport exists and uses curl when available (no request is made here).
    expect(true, Places::defaultTransport() instanceof Closure, 'default transport');

    echo "Koe fetch: all $checks checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Koe fetch: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine() . "\n");
    exit(1);
} finally {
    removeTree($tmp);
}
