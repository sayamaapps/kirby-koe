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
$writeData = function (string $file, array $reviews, float $rating = 4.8, int $count = 201, ?string $placeId = 'ChIJFromFile'): void {
    @mkdir(dirname($file), 0700, true);
    file_put_contents($file, json_encode(array_filter(['format' => 2, 'placeId' => $placeId, 'rating' => $rating, 'count' => $count, 'reviews' => $reviews])));
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

    // 3. koe.placeId wins over the file; koeProfileUrl() alone.
    [$r, $html, $kirby] = $run(['koe.active' => true, 'koe.placeId' => 'ChIJOption']);
    expect('https://www.google.com/maps/place/?q=place_id:ChIJOption', $r['profileUrl'], 'koe.placeId has precedence');
    expect('https://www.google.com/maps/place/?q=place_id:ChIJOption', $kirby->site()->koeProfileUrl(), 'koeProfileUrl() from the option');
    expect(null, (new App(['roots' => ['index' => $tmp . '/public', 'site' => $tmp . '/site', 'content' => $tmp . '/content', 'storage' => $tmp . '/storage']]))->site()->koeProfileUrl(), 'koeProfileUrl() without option and argument');
    [$r] = $run(['koe.active' => true, 'koe.placeId' => 'bad id']);
    expect(null, $r['profileUrl'], 'invalid koe.placeId: no profile (no fallback to the file)');

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
    file_put_contents($legacy, json_encode(['stand' => '2026-10-04', 'gesamtwertung' => 4.9, 'anzahl' => 77, 'bewertungen' => [
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

    // 8. A site's own snippet wins over the package's default.
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
