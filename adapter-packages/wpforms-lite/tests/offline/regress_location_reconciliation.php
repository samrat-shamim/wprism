<?php
declare(strict_types=1);

// Executable candidate mechanism evidence only. The provider is deliberately
// outside package/ until native permalink/home and cache-effect premises close.
$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/agent/src/Adapter/ManifestProviderRuntime.php';
require_once dirname(__DIR__, 2) . '/fixtures/location-provider/wpforms-form-locations.php';

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
$manifest = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/package/manifest.json'), true, 512, JSON_THROW_ON_ERROR);
wprism_check(!isset($manifest['providers']), 'the executable candidate cannot advertise an unproven native capability');
wprism_check_summary('wpforms_location_reconciliation');
