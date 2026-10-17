<?php
/**
 * The plugin with real Kirby, in a throwaway directory: the switch (off by
 * default), the options for file, place id, rules, approval, title, logo and
 * language, the default snippet with the Places obligations (author, link
 * only when safe, attribution), the legacy file fallback, and that a site's
 * own site/snippets/koe/reviews.php wins — sites with their own markup rely
 * on that.
 *   php tests/KirbyTest.php
 */
$base = dirname(__DIR__);
if (!is_file($base . '/vendor/autoload.php')) { fwrite(STDERR, "Composer dependencies missing; run composer install first\n"); exit(1); }
require $base . '/vendor/autoload.php';

use Kirby\Cms\App;
use Kirby\Template\Snippet;

$checks = 0;
function expect($expected, $actual, string $what): void
{
    global $checks;
    if ($expected !== $actual) { throw new RuntimeException($what . ': ' . var_export($actual, true)); }
    $checks++;
}
function holds(bool $ok, string $what): void
{
    global $checks;
    if (!$ok) { throw new RuntimeException($what); }
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

$tmp = sys_get_temp_dir() . '/koe-kirby-' . bin2hex(random_bytes(6));
$long = str_repeat('Friendly, competent and fast, happy to come back. ', 3);
/** Format 2 review. */
$review = fn (string $name, string $ago, ?string $url = null, int $rating = 5, ?string $id = null) => array_filter([
    'id' => $id, 'name' => $name, 'date' => date('Y-m-d', strtotime($ago)), 'rating' => $rating, 'text' => $long, 'source' => 'google', 'url' => $url,
], fn ($v) => $v !== null);
/** A day relative to today, Y-m-d. */
$day = fn (string $ago) => date('Y-m-d', strtotime($ago));
/** Format 2 file, fetched and checked today unless $dates says otherwise (a null value leaves the key out). */
$writeData = function (string $file, array $reviews, float $rating = 4.8, int $count = 201, ?string $placeId = 'ChIJFromFile', array $dates = []) use ($day): void {
    @mkdir(dirname($file), 0700, true);
    $data = array_merge(['format' => 2, 'fetchedAt' => $day('now'), 'checkedAt' => $day('now'), 'placeId' => $placeId, 'rating' => $rating, 'count' => $count, 'reviews' => $reviews], $dates);
    file_put_contents($file, json_encode(array_filter($data, fn ($v) => $v !== null)));
};
/** New Kirby instance with these options; returns [koeReviews(), snippet HTML (entities decoded)]. */
$run = function (array $options, array $snippetData = [], bool $withBase = true) use ($tmp): array {
    Snippet::$cache = []; // Kirby remembers snippet paths per process
    $roots = ['index' => $tmp . '/public', 'content' => $tmp . '/content', 'site' => $tmp . '/site', 'storage' => $tmp . '/storage'];
    if ($withBase) {
        $roots['base'] = $tmp;
    }
    $kirby = new App(['roots' => $roots, 'options' => ['url' => 'http://example.invalid'] + $options]);
    $html = html_entity_decode((string) $kirby->snippet('koe/reviews', $snippetData, true));
    return [$kirby->site()->koeReviews(), $html, $kirby];
};

try {
    foreach (['public', 'content/home', 'site/plugins', 'site/snippets', 'site/templates', 'storage'] as $dir) {
        mkdir($tmp . '/' . $dir, 0700, true);
    }
    symlink($base, $tmp . '/site/plugins/koe');
    file_put_contents($tmp . '/site/templates/default.php', 'Fixture');
    file_put_contents($tmp . '/content/home/home.txt', 'Title: Home');

    $default = $tmp . '/site/data/reviews.json';
    $writeData($default, [
        $review('With Link', '-10 days', 'https://maps.google.com/?cid=42', 5, 'id-with'),
        $review('Without Link', '-20 days', null, 5, 'id-without'),
        $review('Evil Link', '-30 days', 'javascript:alert(1)', 5, 'id-evil'),
        $review('Four Stars', '-5 days', null, 4, 'id-four'),
    ]);

    // 1. Plugin registered; off by default: file is there, still nothing.
    [$r, $html, $kirby] = $run([]);
    holds($kirby->plugin('sayamaapps/koe') !== null, 'plugin sayamaapps/koe registered');
    expect(null, $r, 'without koe.active koeReviews() is null');
    expect('', trim($html), 'without koe.active the snippet prints nothing');
    expect('Read all on Google', $kirby->translation('en')->get('koe.readAll'), 'strings registered as Kirby translations');
    expect('Alle auf Google lesen', $kirby->translation('de')->get('koe.readAll'), 'German Kirby translation');
    holds(function_exists('koe_option'), 'koe_option() helper');
    // Only a real true switches on (like kizami.active).
    [$r] = $run(['koe.active' => 'yes']);
    expect(null, $r, 'koe.active = "yes" must not switch on');
    // No German fallback: the pre-1.0 keys do nothing.
    [$r] = $run(['bewertungen.aktiv' => true]);
    expect(null, $r, 'bewertungen.aktiv is not read');

    // 2. On, default file, place id from the file; English by default.
    [$r, $html] = $run(['koe.active' => true]);
    holds($r !== null && $r['count'] === 201 && $r['rating'] === 4.8 && count($r['reviews']) === 3, 'default file read, four stars out');
    expect(3, $r['limit'], 'limit = maxCount');
    expect('https://www.google.com/maps/place/?q=place_id:ChIJFromFile', $r['profileUrl'], 'profile URL from the place id the fetch recorded');
    holds(str_contains($html, '<strong>4.8 out of 5</strong>') && str_contains($html, '201 reviews on Google Maps'), 'rating, count and text attribution in English');
    holds((bool) preg_match('~<a href="https://maps\.google\.com/\?cid=42" target="_blank" rel="noopener">With Link<span class="sr-only"> \(opens in a new tab\)</span></a>~', $html), 'name with link, target/rel and hint');
    foreach (['Without Link', 'Evil Link'] as $name) {
        holds((bool) preg_match('~<p class="koe-author">' . $name . ' ·~', $html), "$name as plain text");
    }
    holds(!str_contains($html, 'javascript:'), 'javascript: link never printed');
    holds(!str_contains($html, 'Four Stars'), 'four-star review not shown');
    holds(str_contains($html, 'View profile') && !str_contains($html, 'Read all on Google'), 'three reviews: profile link, no tile');
    holds(!str_contains($html, '<img'), 'without logo no image');
    holds(str_contains($html, '<h2 id="koe-title">Reviews on Google</h2>'), 'default title');
    holds((bool) preg_match('~<time datetime="\d{4}-\d{2}-\d{2}">[A-Z][a-z]+ \d{4}</time>~', $html), 'English month and year');
    holds(str_contains($html, 'aria-label="5 out of 5 stars"'), 'stars label');

    // 3. koe.placeId is the expected place: equal to the file it changes nothing, different it hides the section.
    [$r, $html, $kirby] = $run(['koe.active' => true, 'koe.placeId' => 'ChIJFromFile']);
    expect('https://www.google.com/maps/place/?q=place_id:ChIJFromFile', $r['profileUrl'], 'koe.placeId equal to the file: profile link as before');
    holds(count($r['reviews']) === 3, 'koe.placeId equal to the file: reviews shown');
    expect('https://www.google.com/maps/place/?q=place_id:ChIJFromFile', $kirby->site()->koeProfileUrl(), 'koeProfileUrl() from the option');
    expect(null, (new App(['roots' => ['index' => $tmp . '/public', 'site' => $tmp . '/site', 'content' => $tmp . '/content', 'storage' => $tmp . '/storage']]))->site()->koeProfileUrl(), 'koeProfileUrl() without option and argument');
    $idLog = $tmp . '/identity.log';
    $previousLog = ini_set('error_log', $idLog);
    file_put_contents($idLog, '');
    [$r, $html] = $run(['koe.active' => true, 'koe.placeId' => 'ChIJOption']);
    holds($r === null && trim($html) === '', 'koe.placeId differs from the place in the file: nothing is shown (not another business under this profile link)');
    expect('', (string) file_get_contents($idLog), 'place mismatch without debug: nothing logged');
    [$r] = $run(['koe.active' => true, 'koe.placeId' => ' ChIJOption ', 'debug' => true]);
    holds($r === null, 'place mismatch in debug mode: still nothing');
    holds(str_contains((string) file_get_contents($idLog), 'koe: nothing shown: the data file is about place ChIJFromFile, but koe.placeId is ChIJOption'), 'place mismatch in debug mode: the log names both ids');
    [$r] = $run(['koe.active' => true, 'koe.placeId' => ' ChIJFromFile ']);
    holds($r !== null, 'koe.placeId is trimmed');
    [$r] = $run(['koe.active' => true, 'koe.placeId' => '']);
    holds($r !== null && $r['placeId'] === 'ChIJFromFile', 'empty koe.placeId counts as not set: the id of the file is used');
    ini_set('error_log', (string) $previousLog);
    // A file that records no place id (the pre-1.0 format) cannot be checked; the option then supplies the profile link.
    $writeData($tmp . '/content/no-id.json', [$review('Anon', '-3 days')], 4.8, 5, null);
    [$r] = $run(['koe.active' => true, 'koe.file' => 'content/no-id.json', 'koe.placeId' => 'bad id']);
    holds($r !== null && $r['profileUrl'] === null, 'file without place id, invalid koe.placeId: shown, no profile (no fallback)');

    // 4. German: locale, site language option, or koe.language.
    [, $html] = $run(['koe.active' => true, 'locale' => 'de_DE.UTF-8']);
    holds(str_contains($html, '<strong>4,8 von 5</strong>') && str_contains($html, '201 Bewertungen auf Google Maps') && str_contains($html, 'Profil ansehen') && str_contains($html, '(öffnet neuen Tab)'), 'German via locale');
    holds(str_contains($html, '>Bewertungen auf Google</h2>'), 'German default title');
    [, $html] = $run(['koe.active' => true, 'locale' => 'de_DE', 'koe.language' => 'en']);
    holds(str_contains($html, '4.8 out of 5'), 'koe.language wins over locale');

    // 5. One review plus place id: tile fills the gap; title and logo from snippet data or options.
    $writeData($tmp . '/content/reviews.json', [$review('Only', '-3 days')], 5.0, 22, null);
    $opt = ['koe.active' => true, 'koe.placeId' => 'ChIJOption', 'koe.file' => 'content/reviews.json'];
    [$r, $html] = $run($opt, ['title' => 'What our customers say', 'logo' => 'assets/GoogleMaps_Logo_Gray.svg']);
    holds($r !== null && $r['count'] === 22 && array_column($r['reviews'], 'name') === ['Only'] && $r['placeId'] === null, 'koe.file relative to the project root');
    holds(str_contains($html, 'Read all on Google') && str_contains($html, '<p>22 reviews</p>'), 'tile with count below three reviews');
    holds(str_contains($html, '>What our customers say</h2>'), 'title from snippet data');
    holds((bool) preg_match('~<img class="koe-logo" src="[^"]*/assets/GoogleMaps_Logo_Gray\.svg"[^>]*alt="Google Maps">~', $html), 'logo with alt text');
    holds(str_contains($html, '5.0 out of 5'), 'integer rating with one decimal');
    [, $html] = $run($opt + ['koe.title' => 'Voices', 'koe.logo' => 'assets/logo.svg']);
    holds(str_contains($html, '>Voices</h2>') && str_contains($html, '/assets/logo.svg"'), 'title and logo from options');
    [, $html] = $run($opt + ['koe.logo' => 'assets/logo.svg'], ['logo' => null]);
    holds(str_contains($html, '/assets/logo.svg"'), 'snippet data null falls back to the option');
    // Without a base root (sites with their own index.php) the directory above site/ counts.
    [$r] = $run($opt, [], false);
    holds($r !== null && $r['count'] === 22, 'without base root relative to the directory above site/');
    [$r] = $run(['koe.active' => true, 'koe.file' => $tmp . '/content/reviews.json']);
    holds($r !== null && $r['count'] === 22, 'koe.file absolute');
    [$r] = $run(['koe.active' => true, 'koe.file' => fn () => kirby()->root('content') . '/reviews.json']);
    holds($r !== null && $r['count'] === 22, 'koe.file as closure');
    [$r, $html] = $run(['koe.active' => true, 'koe.file' => 'does/not/exist.json']);
    holds($r === null && trim($html) === '', 'missing file: section omitted, no error');

    // 6. Rules and approval as options.
    [$r] = $run(['koe.active' => true, 'koe.minRating' => 4, 'koe.maxCount' => 2]);
    expect(['Four Stars', 'With Link'], array_column($r['reviews'], 'name'), 'minRating 4 and maxCount 2');
    expect(2, $r['limit'], 'limit follows maxCount');
    [$r, $html] = $run(['koe.active' => true, 'koe.approved' => ['id-without']]);
    expect(['Without Link'], array_column($r['reviews'], 'name'), 'approved list');
    holds(str_contains($html, 'Read all on Google'), 'approved: tile fills the gap');
    [$r, $html] = $run(['koe.active' => true, 'koe.approved' => []]);
    expect([], $r['reviews'], 'approved []: no single review');
    holds(str_contains($html, '4.8 out of 5') && !str_contains($html, '<ul'), 'approved []: rating and count still shown, no list');
    [$r] = $run(['koe' => ['active' => true, 'approved' => ['id-with', 'id-four'], 'minRating' => 4]]);
    expect(['Four Stars', 'With Link'], array_column($r['reviews'], 'name'), 'nested spelling, approved with lower rating');

    // 7. Legacy file: read while the default file is missing, German keys, no place id → option needed.
    // The fallback is logged (not silent); capture the log to check and to keep the test output clean.
    $log = $tmp . '/php-error.log';
    $previousLog = ini_set('error_log', $log);
    unlink($default);
    $legacy = $tmp . '/site/daten/bewertungen.json';
    mkdir(dirname($legacy), 0700, true);
    file_put_contents($legacy, json_encode(['stand' => $day('-1 day'), 'gesamtwertung' => 4.9, 'anzahl' => 77, 'bewertungen' => [
        ['name' => 'Alt', 'datum' => date('Y-m-d', strtotime('-15 days')), 'sterne' => 5, 'text' => $long, 'quelle' => 'google', 'link' => 'https://maps.google.com/?cid=7'],
    ]]));
    [$r, $html] = $run(['koe.active' => true, 'locale' => 'de_DE']);
    holds($r !== null && $r['count'] === 77 && $r['rating'] === 4.9 && array_column($r['reviews'], 'name') === ['Alt'], 'legacy file read');
    expect(null, $r['profileUrl'], 'legacy file carries no place id');
    holds(str_contains($html, '4,9 von 5') && str_contains($html, 'cid=7') && !str_contains($html, 'Alle auf Google lesen'), 'legacy rendered in German, no tile without place id');
    [$r] = $run(['koe.active' => true, 'koe.placeId' => 'ChIJOption']);
    holds(str_contains((string) $r['profileUrl'], 'ChIJOption') , 'legacy plus koe.placeId: profile link');
    [$r] = $run(['koe.active' => true, 'koe.approved' => ['anything']]);
    expect([], $r['reviews'], 'legacy reviews have no ids, so an approved list shows none');
    // Default file back: it wins over the legacy one.
    $writeData($default, [$review('New', '-1 day')], 4.8, 201);
    [$r] = $run(['koe.active' => true]);
    expect(['New'], array_column($r['reviews'], 'name'), 'default file wins over the legacy file');
    unlink($legacy);
    ini_set('error_log', (string) $previousLog);
    holds(is_file($log) && str_contains(file_get_contents($log), 'reading the legacy data file site/daten/bewertungen.json'), 'legacy fallback is logged');

    // 8. An answer without review texts is a valid state: rating and count stay, the list is gone.
    $emptyFile = $tmp . '/content/empty.json';
    $writeData($emptyFile, [], 2.0, 9);
    [$r, $html] = $run(['koe.active' => true, 'koe.file' => $emptyFile]);
    holds($r !== null && $r['reviews'] === [] && $r['rating'] === 2.0 && $r['count'] === 9, 'file with an empty review list: rating and count are returned');
    holds(str_contains($html, '<strong>2.0 out of 5</strong>') && str_contains($html, '9 reviews on Google Maps') && !str_contains($html, '<ul'), 'empty review list: rating and count rendered, no list');
    $writeData($emptyFile, [], 4.8, 201, 'ChIJFromFile', ['rating' => null]);
    [$r, $html] = $run(['koe.active' => true, 'koe.file' => $emptyFile]);
    holds($r === null && trim($html) === '', 'no rating at all (a place without reviews): nothing is shown');

    // 9. Freshness: the last successful fetch (checkedAt) decides, not the day the content changed.
    $freshFile = $tmp . '/content/fresh.json';
    $freshLog = $tmp . '/fresh.log';
    $previousLog = ini_set('error_log', $freshLog);
    $fresh = ['koe.active' => true, 'koe.file' => $freshFile];
    $shown = function (array $dates, array $options = []) use ($writeData, $run, $freshFile, $fresh, $review) {
        $writeData($freshFile, [$review('Fresh', '-3 days')], 4.8, 201, 'ChIJFromFile', $dates);
        [$r, $html] = $run($options + $fresh);
        return $r !== null && str_contains($html, 'Fresh');
    };
    holds($shown(['fetchedAt' => $day('-40 days'), 'checkedAt' => $day('-1 day')]), 'content unchanged for 40 days but checked yesterday: shown');
    holds($shown(['checkedAt' => $day('-7 days')]), 'checked 7 days ago: still shown (limit is 7)');
    holds(!$shown(['checkedAt' => $day('-8 days')]), 'checked 8 days ago: hidden');
    holds(!$shown(['fetchedAt' => $day('-10 days'), 'checkedAt' => null]), 'file from before checkedAt: falls back to fetchedAt, 10 days: hidden');
    holds($shown(['fetchedAt' => $day('-3 days'), 'checkedAt' => null]), 'file from before checkedAt, 3 days: shown');
    holds(!$shown(['fetchedAt' => null, 'checkedAt' => null]), 'no date at all: age unknown, hidden');
    holds($shown(['checkedAt' => $day('-20 days')], ['koe.maxAgeDays' => 30]), 'koe.maxAgeDays 30: 20 days old is shown');
    holds(!$shown(['checkedAt' => $day('-3 days')], ['koe.maxAgeDays' => 2]), 'koe.maxAgeDays 2: 3 days old is hidden');
    holds(!$shown(['checkedAt' => $day('-2 days')], ['koe.maxAgeDays' => 0]), 'koe.maxAgeDays 0 counts as 1, never as "off"');
    holds($shown(['checkedAt' => $day('-1 day')], ['koe' => ['maxAgeDays' => 2]]), 'nested spelling');
    // A hidden file is explained in the log, in debug mode only.
    $writeData($freshFile, [$review('Fresh', '-3 days')], 4.8, 201, 'ChIJFromFile', ['checkedAt' => $day('-9 days')]);
    file_put_contents($freshLog, '');
    [$r] = $run($fresh);
    expect('', (string) file_get_contents($freshLog), 'stale file without debug: nothing logged');
    [$r, $html] = $run($fresh + ['debug' => true]);
    holds($r === null && trim($html) === '', 'stale file with debug: still nothing rendered');
    holds(str_contains((string) file_get_contents($freshLog), 'koe: nothing shown: the data file was last checked on ' . $day('-9 days') . ' (9 days ago, koe.maxAgeDays is 7)'), 'stale file with debug: the log names the date and the limit');
    ini_set('error_log', (string) $previousLog);

    // 10. A site's own snippet wins over the package's default.
    mkdir($tmp . '/site/snippets/koe', 0700);
    file_put_contents($tmp . '/site/snippets/koe/reviews.php', '<?php $r = site()->koeReviews(); ?>OWN <?= $r === null ? "off" : count($r["reviews"]) . "/" . $r["limit"] ?>');
    [, $html] = $run(['koe.active' => true]);
    expect('OWN 1/3', trim($html), 'site/snippets/koe/reviews.php must override the package snippet');

    echo "Koe with Kirby: all $checks checks passed\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Koe with Kirby: ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine() . "\n");
    exit(1);
} finally {
    removeTree($tmp);
}
