<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 2) . '/fixtures/location-provider/current-home-evidence.php';

// Synthetic admission inputs only. Native evidence is the separately invoked
// capsule live test, with all three complete private command records retained.
$initialHome = 'http://wpfhome051.invalid';
$current = 'http://current.example.test/base';
$next = 'http://next.example.test/other';
$path = '/wprism-current-home-page/';
$html = static fn(string $url): string => '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_HTML5, 'UTF-8')
    . '" target="_blank" class="wpforms-locations-link">fixture</a>';
$rows = [['type' => 'page', 'title' => 'WPrism current-home page', 'form_id' => 4, 'id' => 6, 'status' => 'publish', 'url' => $path]];
$canonical = $rows;
$canonical[0]['title'] = 'Current home changed title';
$historical = $canonical;
$historical[0]['url'] = $current . $path;
$record = static fn(string $phase): array => ['format' => 'wprism-wpforms-current-home/v1', 'phase' => $phase, 'version' => '2.0.1.1'];
$observation = static fn(string $home, array $locations, string $link): array => ['home' => $home, 'url' => $home . $path,
    'locations' => $locations, 'html' => $html($link)];
$seed = $record('seed') + ['initial' => $observation($initialHome, $rows, $initialHome . $path),
    'historical' => $observation($current, $historical, $initialHome . $current . $path), 'canonical' => $canonical];
$observe = $record('observe') + ['fresh' => $observation($current, $canonical, $current . $path), 'cases' => []];
$rehome = $record('rehome') + ['fresh' => $observation($next, $canonical, $next . $path)];
foreach ([
    'plain' => ['/probe', '/probe', '/probe'],
    'query_home' => ['/probe?next=' . $current . '/path', '/probe?next=' . $current . '/path', '/probe?next=' . $current . '/path'],
    'utf8' => ['/%E6%9D%B1%E4%BA%AC', '/東京', '/%E6%9D%B1%E4%BA%AC'],
    'unreserved' => ['/%41%7a%30%2d%5f%7e', '/Az0-_~', '/%41%7a%30%2d%5f%7e'],
    'encoded_question' => ['/probe%3Fnext', '/probe?next', null],
    'encoded_ampersand' => ['/probe?q=a%26b', '/probe?q=a&b', null],
    'literal_plus' => ['/probe+a', '/probe a', null],
    'encoded_plus' => ['/probe%2Ba', '/probe+a', null],
    'encoded_percent' => ['/probe%252foutside', '/probe%2foutside', null],
    'encoded_quote' => ['/probe?q=%22%20x%3D%22y', '/probe?q=', null],
    'encoded_hash' => ['/probe%23tail', '/probe#tail', null],
] as $name => [$input, $target, $candidate]) {
    $caseRows = $canonical;
    $caseRows[0]['url'] = $input;
    $observe['cases'][$name] = ['url' => $current . $input, 'candidate' => $candidate,
        'locations' => $caseRows, 'html' => $html($current . $target)];
}
$verify = static fn(array $a, array $b, array $c): mixed => WPFormsCurrentHomeEvidence::verify($a, $b, $c, $initialHome);
$verify($seed, $observe, $rehome);
wprism_check(true, 'independent complete native observation shape is admitted');
foreach (['format', 'phase', 'version'] as $key) {
    $bad = $seed;
    $bad[$key] = 'wrong';
    wprism_check_throws(static fn() => $verify($bad, $observe, $rehome), RuntimeException::class, 'wrong native record identity refuses');
}
foreach ([
    static function (array &$a, array &$b, array &$c): void { $a['extra'] = true; },
    static function (array &$a, array &$b, array &$c): void { $a['historical']['locations'][0]['url'] = '/guessed'; },
    static function (array &$a, array &$b, array &$c): void { $a['historical']['html'] = $a['initial']['html']; },
    static function (array &$a, array &$b, array &$c): void { $a['canonical'][0]['id'] = 7; },
    static function (array &$a, array &$b, array &$c): void { $b['fresh']['home'] = 'http://stale.example.test'; },
    static function (array &$a, array &$b, array &$c): void { $c['fresh']['locations'][0]['form_id'] = 5; },
    static function (array &$a, array &$b, array &$c): void { $c['fresh']['html'] = $b['fresh']['html']; },
    static function (array &$a, array &$b, array &$c): void { unset($b['cases']['encoded_question']); },
    static function (array &$a, array &$b, array &$c): void { $b['cases']['encoded_question']['candidate'] = '/probe%3Fnext'; },
    static function (array &$a, array &$b, array &$c): void { $b['cases']['encoded_ampersand']['locations'][0]['url'] = '/changed'; },
    static function (array &$a, array &$b, array &$c): void { $b['cases']['plain']['candidate'] = null; },
    static function (array &$a, array &$b, array &$c): void { $b['cases']['utf8']['html'] = $b['cases']['plain']['html']; },
    static function (array &$a, array &$b, array &$c): void { $b['cases']['plain']['html'] .= $b['cases']['plain']['html']; },
    static function (array &$a, array &$b, array &$c): void { $b['cases']['plain']['html'] = str_repeat('x', 16385); },
    static function (array &$a, array &$b, array &$c): void { $b['cases']['plain']['html'] = "\xff"; },
    static function (array &$a, array &$b, array &$c): void { $b['cases']['plain']['extra'] = true; },
] as $mutate) {
    [$a, $b, $c] = [$seed, $observe, $rehome];
    $mutate($a, $b, $c);
    wprism_check_throws(static fn() => $verify($a, $b, $c), RuntimeException::class,
        'missing, stale, forged or oversized native target evidence refuses');
}
wprism_check_summary('wpforms_location_renderer_evidence');
