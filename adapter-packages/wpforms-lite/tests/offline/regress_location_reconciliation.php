<?php
declare(strict_types=1);

// Executable candidate mechanism evidence only. The provider is deliberately
// outside package/ until native permalink/home and cache-effect premises close.
$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/agent/src/Adapter/ManifestProviderRuntime.php';
require_once dirname(__DIR__, 2) . '/package/runtime/providers/wpforms-form-locations.php';

use WPrism\Providers\WpformsFormLocations;

// Deterministic core transformation fixture, not a native WordPress run.
function wp_unslash(mixed $value): mixed {
    if (is_array($value)) return array_map('wp_unslash', $value);
    return is_string($value) ? stripslashes($value) : $value;
}

$call = static fn(string $method, mixed ...$args): mixed => (new ReflectionMethod(WpformsFormLocations::class, $method))->invoke(null, ...$args);
foreach ([1, '1', '01', '0001'] as $value) {
    wprism_check_same(1, $call('native_target_id', $value), 'native target coordinates normalize leading zeros without changing raw location fields');
    wprism_check_same(1, $call('form_id', $value, [1 => ['post_type' => 'wpforms']]), 'normalized target must resolve to an actual form');
}
foreach ([0, '0', '000', '-1', true, '1e0', '1.0', '1suffix', '9223372036854775808', str_repeat('0', 33) . '1'] as $value) {
    wprism_check_throws(static fn() => $call('native_target_id', $value), RuntimeException::class, 'malformed/zero/overflow native target refuses');
}
wprism_check_throws(static fn() => $call('positive_id', '01'), RuntimeException::class, 'physical identities retain canonical integer rules');
wprism_check_throws(static fn() => $call('form_id', '01', []), RuntimeException::class, 'normalization cannot invent a missing form');
$slashed = [['type' => 'widget', 'title' => "a\\b \\\"quote\\\"", 'form_id' => '01', 'id' => 'text-7']];
$stored = [['type' => 'widget', 'title' => 'ab "quote"', 'form_id' => '01', 'id' => 'text-7']];
wprism_check_same(serialize($stored), $call('location_bytes', $slashed), 'location storage matches recursive core metadata unslashing and preserves native raw ID type');
wprism_check($call('location_bytes', $slashed) !== serialize($slashed), 'literal serialization cannot masquerade as native metadata storage');

foreach ([
    ['https://target.example.test', 'https://target.example.test/a', '/a'],
    ['https://target.example.test/base', 'https://target.example.test/base/a', '/a'],
    ['https://target.example.test', 'https://target.example.test/?p=1', '/?p=1'],
    ['https://target.example.test/base', 'https://target.example.test/base?q=1#here', '?q=1#here'],
    ['https://target.example.test', 'https://target.example.test/#here', '/#here'],
    ['https://target.example.test:8443', 'https://target.example.test:8443/a', '/a'],
    ['http://[::1]:8080/base', 'http://[::1]:8080/base/a', '/a'],
    ['https://target.example.test', 'https://target.example.test/%E6%9D%B1%E4%BA%AC', '/%E6%9D%B1%E4%BA%AC'],
    ['https://target.example.test', 'https://target.example.test/%41%7a%30%2d%5f%7e', '/%41%7a%30%2d%5f%7e'],
    ['https://target.example.test', 'https://target.example.test/a?next=https://target.example.test/b', '/a?next=https://target.example.test/b'],
    ['https://target.example.test', 'https://target.example.test', ''],
    ['https://target.example.test', '', ''],
] as [$home, $url, $relative]) {
    wprism_check_same($relative, $call('relative_location_url', $home, $url), 'canonical policy removes one exact leading current home and preserves suffix bytes');
    if ($url !== '') wprism_check_same($url, $home . $relative, 'fresh Locator home-plus-suffix renders the exact admitted native permalink');
}
$historicalHome = 'https://old.example.test';
$currentHome = 'https://new.example.test';
$currentUrl = $currentHome . '/page';
$nativeHistorical = str_replace($historicalHome, '', $currentUrl);
$canonicalCurrent = $call('relative_location_url', $currentHome, $currentUrl);
wprism_check($nativeHistorical !== $canonicalCurrent, 'historical private-home replay is an explicitly different policy');
wprism_check_same($currentUrl, $currentHome . $canonicalCurrent, 'fresh consumer renders the canonical current-home policy correctly');
wprism_check($historicalHome . $nativeHistorical !== $currentUrl, 'replaying the stale writer can produce the historical double-home bug');
foreach ([
    ['', 'https://target.example.test/a'], ['not-a-url', 'https://target.example.test/a'],
    ['https://target.example.test/', 'https://target.example.test/a'],
    ['https://target.example.test?x=1', 'https://target.example.test/a'],
    ['https://target.example.test#fragment', 'https://target.example.test/a'],
    ['https://user:pass@target.example.test', 'https://user:pass@target.example.test/a'],
    ['https://target.example.test', 'https://outside.example.test/a'],
    ['https://target.example.test', 'https://target.example.test.evil/a'],
    ['https://target.example.test/base', 'https://target.example.test/baseball/a'],
    ['https://target.example.test', 'https://target.example.test:443/a'],
    ['https://target.example.test', '/a'],
    ['https://target.example.test', 'https://target.example.test/a b'],
    ['https://target.example.test', 'https://target.example.test/a\\outside'],
    ['https://target.example.test', 'https://target.example.test/%zz'],
    ['https://target.example.test/base', 'https://target.example.test/base/../outside'],
    ['https://target.example.test/base', 'https://target.example.test/base/%2e%2e/outside'],
    ['https://target.example.test/base', 'https://target.example.test/base/%2Foutside'],
    ['https://target.example.test/base', 'https://target.example.test/base/%5coutside'],
    ['https://target.example.test', "https://target.example.test/\n"],
    ['https://target.example.test', 'https://target.example.test/%00'],
] as [$home, $url]) {
    wprism_check_throws(static fn() => $call('relative_location_url', $home, $url), RuntimeException::class,
        'malformed, external, prefix-trapped or path-escaping native URLs refuse before mutation planning');
}
// Locator.php:647 URL-decodes the whole link markup before KSES. These
// were admitted at bedfd61c but the real public column renderer changes the
// target; a lexical home-plus-suffix equality cannot prove native UI behavior.
foreach (['/a%3Fb', '/a%23frag', '/a?x=a%26b', '/a?x=a%3Db', '/a+b',
    '/a?x=a%2Bb', '/a%252foutside', '/a%22%20title%3D%22x', '/a%27b', '/a%20b',
    '/a%80', '/a%C0%AF', '/a%ED%A0%80'] as $suffix) {
    wprism_check_throws(static fn() => $call('relative_location_url', 'https://target.example.test', 'https://target.example.test' . $suffix),
        RuntimeException::class, 'native whole-markup URL decoding cannot alter the admitted navigation target', 'renderer');
}
foreach (['https://target.example.test/base+part', 'https://target.example.test/base%3Fpart'] as $home) {
    wprism_check_throws(static fn() => $call('relative_location_url', $home, $home . '/a'), RuntimeException::class,
        'the renderer decodes the current home as well as the stored suffix', 'renderer');
}
$owned = [
    ['meta_id' => '2', 'post_id' => '1', 'meta_value' => 'old'],
    ['meta_id' => '5', 'post_id' => '1', 'meta_value' => 'duplicate'],
    ['meta_id' => '7', 'post_id' => '2', 'meta_value' => 'stable'],
    ['meta_id' => '8', 'post_id' => '99', 'meta_value' => 'orphan'],
    ['meta_id' => '9', 'post_id' => '0', 'meta_value' => 'zero orphan'],
];
$desired = [1 => 'new', 2 => 'stable', 3 => 'missing'];
wprism_check_same([['update', 2, 'new'], ['delete', 5, null], ['delete', 8, null], ['delete', 9, null], ['insert', 3, 'missing']],
    $call('reconciliation_plan', $owned, $desired), 'plan retains lowest meta identity, avoids unchanged writes and removes duplicate/orphan coordinates');
$settled = [['meta_id' => '2', 'post_id' => '1', 'meta_value' => 'new'],
    ['meta_id' => '7', 'post_id' => '2', 'meta_value' => 'stable'],
    ['meta_id' => '10', 'post_id' => '3', 'meta_value' => 'missing']];
wprism_check_same([], $call('reconciliation_plan', $settled, $desired), 'exact fixed point has no delete/reinsert or update work');
wprism_check_same([['delete', 2, null], ['delete', 7, null], ['delete', 10, null]],
    $call('reconciliation_plan', $settled, []), 'no placements means removal of every owned key');
$many = [];
for ($id = 1; $id <= 257; $id++) $many[] = ['meta_id' => (string) $id, 'post_id' => '0', 'meta_value' => 'stale'];
wprism_check_same(256, count($call('reconciliation_plan', array_slice($many, 0, 256), [])), 'exact mutation frontier is admitted');
wprism_check_throws(static fn() => $call('reconciliation_plan', $many, []), RuntimeException::class,
    'all mutation intent is bounded before any DML can begin', 'pre-write mutation frontier');
$disposition = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/package/disposition.json'), true, 512, JSON_THROW_ON_ERROR);
wprism_check($disposition['status'] === 'experimental' && !in_array('apply', $disposition['capabilities']['operations'], true),
    'the bounded experimental provider does not advertise complete authored-state Apply readiness');
// The SDK checks core function provenance before its single-site refusal,
// including home-only batches. A capsule precheck would execute it too soon.
$source = (string) file_get_contents(dirname(__DIR__, 2) . '/package/runtime/providers/wpforms-form-locations.php');
wprism_check(preg_match('/\\bis_multisite\\s*\\(/', $source) === 0,
    'single-site admission belongs to the source-checked engine reader, never a candidate precheck');
wprism_check_summary('wpforms_location_reconciliation');
