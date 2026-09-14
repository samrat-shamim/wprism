<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Promotion/LifecyclePlanner.php';
wprism_test_define_agent_versions();

$slug = 'users-customers-import-export-for-wp-woocommerce';
$plugin = $slug . '/' . $slug . '.php';
$policy = WPrism\Policy::load(null, ['core', $slug], adapterLibrary: WPrism\AdapterLibrary::fromSourceTree($root));
$desired = ['active_plugins' => [$plugin]];
$facts = ['active_plugins' => [$plugin], 'plugins' => [$plugin => '2.7.5'], 'plugin_exists' => [$plugin => true],
    'template' => 'fixture', 'stylesheet' => 'fixture', 'themes' => ['fixture' => '1.0'],
    'theme_exists' => ['fixture' => true], 'recorded_raw' => null];
$inspect = static fn(array $changes) => WPrism\LifecyclePlanner::deployment_status_from_observation(
    $policy, $desired, false, array_replace($facts, $changes));
$ready = $inspect([]);
wprism_check_same([], $ready['reasons'], 'exact active Importer artifact requires no activation work');
wprism_check_same([], $ready['warnings'], 'exact Importer artifact requires no compatibility override');
$inactive = $inspect(['active_plugins' => []]);
wprism_check_same(['inactive_in_environment'], $inactive['reasons'], 'inactive exact Importer requires lifecycle settlement before Apply');
wprism_check_same([], $inactive['warnings'], 'ordinary activation is not a compatibility override');

// Both installed-inactive and currently-active code must obey the same pinned
// interval; otherwise an activation path could admit the refused artifact.
foreach ([true, false] as $active) {
    foreach (['2.7.4', '2.7.5-beta', '2.7.6', '3.0.0', ''] as $version) {
        wprism_check_throws(static fn() => $inspect(['active_plugins' => $active ? [$plugin] : [],
            'plugins' => [$plugin => $version]]), RuntimeException::class,
            'Importer refuses ' . ($active ? 'active ' : 'inactive ') . ($version === '' ? 'unreadable version' : $version),
            'declared version_range');
    }
    wprism_check_throws(static fn() => $inspect(['active_plugins' => $active ? [$plugin] : [],
        'plugins' => [], 'plugin_exists' => [$plugin => false]]), RuntimeException::class,
        'missing Importer refuses before activation or authored work', 'does not exist');
    $wrong = 'import-export-for-woocommerce/import-export-for-woocommerce.php';
    wprism_check_throws(static fn() => $inspect(['active_plugins' => $active ? [$wrong] : [],
        'plugins' => [$wrong => '2.7.5'], 'plugin_exists' => [$wrong => true]]), RuntimeException::class,
        'a version-matching different basename cannot satisfy the Importer dependency', 'does not exist');
}
wprism_check_same($ready, $inspect([]), 'refused dependency observations do not poison a subsequent exact-artifact preflight');
wprism_check_summary('Importer dependency preflight');
