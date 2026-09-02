<?php
declare(strict_types=1);

/**
 * The readiness matrix is a hand-reviewed work ledger, not generated
 * evidence. This guard makes omission and an unearned `ready` state loud;
 * the cited live/offline files remain the evidence a reviewer must inspect.
 */

require_once __DIR__ . '/../../lib/check.php';

$root = dirname(__DIR__, 4);
require_once $root . '/agent/src/Policy/AdapterLibrary.php';
require_once $root . '/tools/src/AdapterProductionReadiness.php';
$matrix = \WPrism\Tooling\AdapterProductionReadiness::load($root);
// The roster source. WP-4.4 made it a directory of one document per subject
// (spec/repo-format.md § v3.4), so the reviewed set IS the file set — read as
// a listing rather than as one document's `manifests` keys, which is what
// makes an adapter reviewed-but-absent-from-the-ledger impossible to miss.
$dispositions = [];
foreach (\WPrism\AdapterLibrary::fromSourceTree($root)->packages() as $package) {
    $subject = $package->name();
    $dispositions[$subject] = json_decode(
        (string) file_get_contents($package->dispositionPath()),
        true,
        flags: JSON_THROW_ON_ERROR
    );
}
ksort($dispositions, SORT_STRING);

wprism_check_same(
    'wprism-adapter-production-readiness/v1',
    $matrix['format'] ?? null,
    'the work ledger has one explicit schema'
);

$families = \WPrism\Tooling\AdapterProductionReadiness::SCENARIO_FAMILIES;
wprism_check_same($families, $matrix['scenario_families'] ?? null, 'the ledger carries the complete reviewed scenario taxonomy in review order');

$productAdapters = [];
foreach ($dispositions as $name => $entry) {
    if (($entry['status'] ?? null) !== 'excluded') {
        $productAdapters[] = $name;
    }
}
sort($productAdapters, SORT_STRING);
$matrixAdapters = array_keys($matrix['adapters'] ?? []);
sort($matrixAdapters, SORT_STRING);
wprism_check_same($productAdapters, $matrixAdapters, 'every shipped product adapter appears exactly once and the excluded regression fixture stays out');

foreach ($matrix['adapters'] as $name => $adapter) {
    $keys = array_keys($adapter);
    sort($keys, SORT_STRING);
    wprism_check_same(
        ['blocked', 'covered', 'gaps', 'not_applicable', 'readiness'],
        $keys,
        "$name has only the reviewed readiness fields"
    );
    wprism_check(
        in_array($adapter['readiness'], ['ready', 'unready'], true),
        "$name readiness is an explicit binary outcome"
    );

    $accounted = [];
    foreach (['covered', 'gaps', 'blocked', 'not_applicable'] as $bucket) {
        // json_decode(..., true) maps both {} and [] to an empty PHP array.
        // A non-empty bucket must be associative; an empty bucket is accepted
        // because its JSON object shape was already reviewed in the source.
        wprism_check(
            is_array($adapter[$bucket]) && ($adapter[$bucket] === [] || !array_is_list($adapter[$bucket])),
            "$name $bucket is an object keyed by scenario family"
        );
        foreach ($adapter[$bucket] as $family => $value) {
            wprism_check(in_array($family, $families, true), "$name $bucket names a real scenario family: $family");
            wprism_check(!isset($accounted[$family]), "$name accounts for $family exactly once");
            $accounted[$family] = true;
            if ($bucket === 'covered') {
                wprism_check(is_array($value) && array_is_list($value) && $value !== [], "$name covered.$family names evidence files");
                foreach ($value as $path) {
                    wprism_check(
                        is_string($path) && $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '..'),
                        "$name covered.$family evidence path is repository-relative"
                    );
                    wprism_check(is_file($root . '/' . $path), "$name covered.$family evidence exists: $path");
                }
            } else {
                wprism_check(is_string($value) && trim($value) !== '', "$name $bucket.$family carries a concrete reason");
            }
        }
    }
    $accountedFamilies = array_keys($accounted);
    sort($accountedFamilies, SORT_STRING);
    $sortedFamilies = $families;
    sort($sortedFamilies, SORT_STRING);
    wprism_check_same($sortedFamilies, $accountedFamilies, "$name accounts for every production scenario family");

    $unclosed = count($adapter['gaps']) + count($adapter['blocked']);
    wprism_check_same(
        $unclosed === 0 ? 'ready' : 'unready',
        $adapter['readiness'],
        "$name cannot be marked ready while any applicable family is missing or blocked"
    );
}

$readyAdapters = array_keys(array_filter(
    $matrix['adapters'],
    static fn(array $adapter): bool => $adapter['readiness'] === 'ready'
));
sort($readyAdapters, SORT_STRING);
wprism_check_same(
    $productAdapters,
    $readyAdapters,
    'every shipped product adapter has complete isolated adversarial and exact-version evidence'
);

wprism_check_summary('adapter production-readiness ledger');
