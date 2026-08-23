<?php
declare(strict_types=1);

/**
 * The readiness matrix is a hand-reviewed work ledger, not generated
 * evidence. This guard makes omission and an unearned `ready` state loud;
 * the cited live/offline files remain the evidence a reviewer must inspect.
 */

require_once __DIR__ . '/../../lib/check.php';

$root = dirname(__DIR__, 4);
$matrix = json_decode(
    (string) file_get_contents($root . '/sandbox/conformance/production-readiness.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);
$dispositions = json_decode(
    (string) file_get_contents($root . '/manifests/dispositions.json'),
    true,
    flags: JSON_THROW_ON_ERROR
);

duo_check_same(
    'duo-adapter-production-readiness/v1',
    $matrix['format'] ?? null,
    'the work ledger has one explicit schema'
);

$families = [
    'contract-dependency',
    'clean-target',
    'dirty-target',
    'identity-references',
    'native-behavior',
    'derived-state',
    'deletion',
    'failure-recovery',
    'concurrency-idempotence',
    'lifecycle',
    'data-boundary',
    'scope-platform',
];
duo_check_same($families, $matrix['scenario_families'] ?? null, 'the ledger carries the complete reviewed scenario taxonomy in review order');

$productAdapters = [];
foreach ($dispositions['manifests'] as $name => $entry) {
    if (($entry['status'] ?? null) !== 'excluded') {
        $productAdapters[] = $name;
    }
}
sort($productAdapters, SORT_STRING);
$matrixAdapters = array_keys($matrix['adapters'] ?? []);
sort($matrixAdapters, SORT_STRING);
duo_check_same($productAdapters, $matrixAdapters, 'every shipped product adapter appears exactly once and the excluded regression fixture stays out');

foreach ($matrix['adapters'] as $name => $adapter) {
    $keys = array_keys($adapter);
    sort($keys, SORT_STRING);
    duo_check_same(
        ['blocked', 'covered', 'gaps', 'not_applicable', 'readiness'],
        $keys,
        "$name has only the reviewed readiness fields"
    );
    duo_check(
        in_array($adapter['readiness'], ['ready', 'unready'], true),
        "$name readiness is an explicit binary outcome"
    );

    $accounted = [];
    foreach (['covered', 'gaps', 'blocked', 'not_applicable'] as $bucket) {
        // json_decode(..., true) maps both {} and [] to an empty PHP array.
        // A non-empty bucket must be associative; an empty bucket is accepted
        // because its JSON object shape was already reviewed in the source.
        duo_check(
            is_array($adapter[$bucket]) && ($adapter[$bucket] === [] || !array_is_list($adapter[$bucket])),
            "$name $bucket is an object keyed by scenario family"
        );
        foreach ($adapter[$bucket] as $family => $value) {
            duo_check(in_array($family, $families, true), "$name $bucket names a real scenario family: $family");
            duo_check(!isset($accounted[$family]), "$name accounts for $family exactly once");
            $accounted[$family] = true;
            if ($bucket === 'covered') {
                duo_check(is_array($value) && array_is_list($value) && $value !== [], "$name covered.$family names evidence files");
                foreach ($value as $path) {
                    duo_check(
                        is_string($path) && $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '..'),
                        "$name covered.$family evidence path is repository-relative"
                    );
                    duo_check(is_file($root . '/' . $path), "$name covered.$family evidence exists: $path");
                }
            } else {
                duo_check(is_string($value) && trim($value) !== '', "$name $bucket.$family carries a concrete reason");
            }
        }
    }
    $accountedFamilies = array_keys($accounted);
    sort($accountedFamilies, SORT_STRING);
    $sortedFamilies = $families;
    sort($sortedFamilies, SORT_STRING);
    duo_check_same($sortedFamilies, $accountedFamilies, "$name accounts for every production scenario family");

    $unclosed = count($adapter['gaps']) + count($adapter['blocked']);
    duo_check_same(
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
duo_check_same(
    ['acf', 'advanced-editor-tools', 'classic-editor', 'code-snippets', 'contact-form-7', 'core', 'elementor', 'wps-hide-login', 'yoast', 'yoast-duplicate-post'],
    $readyAdapters,
    'only the ten adapters with complete isolated adversarial and exact-version evidence are production-ready'
);

duo_check_summary('adapter production-readiness ledger');
