<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/agent/src/Policy/Policy.php';
wprism_test_define_agent_versions();

use WPrism\AdapterLibrary;
use WPrism\AdapterRegistry;
use WPrism\ManifestDispositions;
use WPrism\Policy;

$library = AdapterLibrary::fromSourcePackage($root, 'map-block-gutenberg');
$policy = Policy::load(null, ['core', 'map-block-gutenberg'], adapterLibrary: $library);
$dispositions = ManifestDispositions::load_library($library);
$plugin = 'map-block-gutenberg/map-block-gutenberg.php';
// These are explicit target facts supplied to the product capability gate,
// not a claim that an offline fixture ran native activation or filesystem IO.
foreach ([
    'exact-active' => [$plugin => '1.35'],
    'below-minimum' => [$plugin => '1.34'],
    'exclusive-maximum' => [$plugin => '1.35.1'],
    'future' => [$plugin => '2.0'],
    'unreadable-version' => [$plugin => ''],
    'absent' => [],
    'wrong-basename' => ['map-block-gutenberg/wrong.php' => '1.35'],
    'inactive' => [$plugin => '1.35'],
] as $case => $plugins) {
    $target = ['plugins' => $plugins, 'active_plugins' => $case === 'inactive' ? [] : array_keys($plugins)];
    $report = AdapterRegistry::report($dispositions, $policy->manifests, ['operation' => 'apply'], $target,
        platformBoundary: $policy->adapter_platform_boundary());
    $rows = array_values(array_filter($report['manifests'], static fn(array $row): bool => $row['name'] === 'map-block-gutenberg'));
    wprism_check_same(1, count($rows), "$case resolves exactly one capsule claim");
    $codes = array_column($rows[0]['verdict']['reasons'], 'code');
    wprism_check_same(!in_array($case, ['exact-active', 'inactive'], true), in_array('plugin_version_mismatch', $codes, true), "$case enforces the exact version window");
    wprism_check_same(in_array($case, ['inactive', 'absent', 'wrong-basename'], true), in_array('plugin_not_active', $codes, true), "$case enforces the exact active basename");
    wprism_check(in_array('authored_state_not_certified', $codes, true) && !$report['ready'], "$case cannot promote the experimental capsule");
}
wprism_check_same(null, $policy->deletion_capability('option:gmw-map-block-key'), 'environment binding does not acquire an option deletion grant');
wprism_check_summary('map-block-gutenberg runtime contract');
