<?php
/**
 * WPrism's greenfield identity baseline.
 *
 * Adapter package identity is the exact manifest, disposition, interpreter,
 * provider, and regenerator byte projection that a repository pin and a
 * compiled artifact bind. This suite names the current product baseline as
 * literals: it does not retain a prior-product transition or silently compare
 * two fresh derivations. A package change must therefore re-pin the exact
 * affected digest, compatible-world manifest hashes, registry address, and
 * frozen-policy snapshots deliberately.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/agent_version.php';
wprism_test_define_agent_versions();
if (!function_exists('is_multisite')) {
    function is_multisite(): bool {
        return false;
    }
}

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ManifestDispositions.php';
require_once __DIR__ . '/../../../../agent/src/Adapter/AdapterRegistry.php';
require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ArtifactPolicyIdentity.php';

use WPrism\AdapterLibrary;
use WPrism\ArtifactPolicyIdentity;
use WPrism\Canon;
use WPrism\ManifestDispositions;
use WPrism\Policy;

// Adding WPForms expands the two maximal worlds and the whole-registry
// snapshot. Every pre-existing adapter digest and smaller pin set stays exact;
// the registry-wide addressing cost is still the explicit WP-4.5 boundary.
const BASELINE_FIXTURE_SHA256 = '34476da56458ccf242c87955c0076c4cd6db5a7837fbc7007692d973d7b70c5f';
const RANK_WORLD_MANIFEST_HASH = 'bd6022a133930e5e6cb970bc5def723734b180b53434ce294c3c6f169801b715';
const YOAST_WORLD_MANIFEST_HASH = '9d7ab5625b12c6fa4bfbb3cfde3ab3a48e0340a94254a13e657d158e577f04b6';
const REGISTRY_SHA256 = '5f923b7b5e6b2decaf3272fcda467fb57e45066fca7f04f96078ebe7d1f66c95';
const RANK_WORLD_SNAPSHOT_SHA256 = '984a3fa2c761b931e71ee174d02e31ceec4b045d371813f5a5a00db3c4cfa6c0';
const YOAST_WORLD_SNAPSHOT_SHA256 = 'd708a9b06155619aa7e9864be72e21ea4b9c9250b93d7bba889a1c191bb581a6';

$repo = dirname(__DIR__, 4);
$fixturePath = $repo . '/sandbox/tests/fixtures/spec-v3/wprism-greenfield-identity.json';
$adapterLibrary = AdapterLibrary::fromSourceTree($repo);

wprism_check(is_file($fixturePath), 'the WPrism greenfield identity baseline is tracked');
wprism_check_same(
    BASELINE_FIXTURE_SHA256,
    hash_file('sha256', $fixturePath),
    'the baseline fixture itself is byte-pinned, so changing its exact maps is a visible re-pin'
);
$baseline = Canon::decode(Canon::read_file($fixturePath));
wprism_check_same('wprism-greenfield-identity/v2', $baseline['format'] ?? null, 'the baseline declares its WPrism-only format');
wprism_check_same(RANK_WORLD_MANIFEST_HASH, $baseline['pin_sets']['rank-world']['manifest_hash'] ?? null, 'the Rank-compatible maximal world has an explicit current address');
wprism_check_same(YOAST_WORLD_MANIFEST_HASH, $baseline['pin_sets']['yoast-world']['manifest_hash'] ?? null, 'the Yoast-compatible maximal world has an explicit current address');
wprism_check_same(REGISTRY_SHA256, $baseline['registry_sha256'] ?? null, 'the registry baseline is the explicit current address');
wprism_check_same(RANK_WORLD_SNAPSHOT_SHA256, $baseline['pin_sets']['rank-world']['snapshot_sha256'] ?? null, 'the Rank-compatible frozen-policy snapshot has an explicit current address');
wprism_check_same(YOAST_WORLD_SNAPSHOT_SHA256, $baseline['pin_sets']['yoast-world']['snapshot_sha256'] ?? null, 'the Yoast-compatible frozen-policy snapshot has an explicit current address');

// Pin order is part of each exported policy snapshot. Do not sort the world
// lists themselves: an order change is an identity change this suite exposes.
$rankPins = $baseline['pin_sets']['rank-world']['pins'] ?? null;
$yoastPins = $baseline['pin_sets']['yoast-world']['pins'] ?? null;
wprism_check(is_array($rankPins) && array_is_list($rankPins), 'the Rank-compatible world carries one ordered pin list');
wprism_check(is_array($yoastPins) && array_is_list($yoastPins), 'the Yoast-compatible world carries one ordered pin list');
if (!is_array($rankPins) || !is_array($yoastPins)) {
    throw new RuntimeException('baseline compatible-world pins are unavailable');
}
$baselineNames = array_keys((array) ($baseline['adapter_digests'] ?? []));
$actualNames = array_map(static fn(\WPrism\AdapterPackage $package): string => $package->name(), $adapterLibrary->packages());
sort($actualNames, SORT_STRING);
wprism_check_same($actualNames, $baselineNames, 'the literal identity baseline covers the actual library, including newly added capsules');
wprism_check_same(array_values(array_diff($baselineNames, ['yoast'])), $rankPins, 'the Rank-compatible world is exactly all shipped adapters except Yoast');
wprism_check_same(array_values(array_diff($baselineNames, ['rank-math'])), $yoastPins, 'the Yoast-compatible world is exactly all shipped adapters except Rank Math');
wprism_check_same(18, count($rankPins), 'the Rank-compatible maximal world contains 18 adapters');
wprism_check_same(18, count($yoastPins), 'the Yoast-compatible maximal world contains 18 adapters');
$worldUnion = array_values(array_unique(array_merge($rankPins, $yoastPins)));
sort($worldUnion, SORT_STRING);
wprism_check_same($baselineNames, $worldUnion, 'the two compatible worlds jointly cover all shipped adapters');
wprism_check_same(['rank-math'], array_values(array_diff($rankPins, $yoastPins)), 'only Rank Math distinguishes the Rank-compatible world');
wprism_check_same(['yoast'], array_values(array_diff($yoastPins, $rankPins)), 'only Yoast distinguishes the Yoast-compatible world');

echo "\nPART 1 — exact adapter identity map\n";
$worldPolicies = [
    'rank-world' => Policy::load(null, $rankPins, adapterLibrary: $adapterLibrary),
    'yoast-world' => Policy::load(null, $yoastPins, adapterLibrary: $adapterLibrary),
];
$observedDigests = [];
foreach ($worldPolicies as $world => $policy) {
    foreach (ArtifactPolicyIdentity::resolved_adapters($policy) as $row) {
        $name = (string) $row['name'];
        $digest = (string) $row['digest'];
        if (isset($observedDigests[$name]) && $observedDigests[$name] !== $digest) {
            throw new RuntimeException("adapter '$name' has inconsistent identity across compatible world '$world'");
        }
        $observedDigests[$name] = $digest;
    }
}
ksort($observedDigests, SORT_STRING);
wprism_check_same(
    $baseline['adapter_digests'] ?? null,
    $observedDigests,
    'the union of both valid maximal worlds exactly matches all 19 shipped adapter digests'
);
wprism_check_same(
    array_keys($baseline['adapter_digests'] ?? []),
    array_keys($observedDigests),
    'the digest map admits neither a missing shipped adapter nor an unreviewed extra subject'
);

echo "\nPART 2 — exact manifest and pin maps\n";
$manifestBytes = [];
foreach (array_keys($baseline['adapter_digests'] ?? []) as $name) {
    $package = $adapterLibrary->package((string) $name);
    if ($package === null) {
        throw new RuntimeException("baseline adapter package '$name' is absent");
    }
    $manifestBytes[(string) $name] = hash_file('sha256', $package->manifestPath());
}
ksort($manifestBytes, SORT_STRING);
wprism_check_same(
    $baseline['manifest_bytes_sha256'] ?? null,
    $manifestBytes,
    'every shipped manifest file byte address exactly matches the baseline upstream of its adapter digest'
);

$observedPinHashes = [];
foreach ((array) ($baseline['pin_sets'] ?? []) as $label => $expected) {
    $pins = is_array($expected) ? ($expected['pins'] ?? null) : null;
    wprism_check(is_array($pins) && array_is_list($pins), "pin set '$label' is an ordered list");
    if (!is_array($pins)) {
        continue;
    }
    $observedPinHashes[(string) $label] = ArtifactPolicyIdentity::manifest_hash(
        Policy::load(null, $pins, adapterLibrary: $adapterLibrary)
    );
}
$expectedPinHashes = [];
foreach ((array) ($baseline['pin_sets'] ?? []) as $label => $expected) {
    $expectedPinHashes[(string) $label] = is_array($expected) ? ($expected['manifest_hash'] ?? null) : null;
}
wprism_check_same(
    $expectedPinHashes,
    $observedPinHashes,
    'every representative repository pin map exactly binds its current WPrism manifest hash'
);
$worldManifestHashes = [
    'rank-world' => RANK_WORLD_MANIFEST_HASH,
    'yoast-world' => YOAST_WORLD_MANIFEST_HASH,
];
foreach ($worldManifestHashes as $world => $expectedHash) {
    wprism_check_same(
        $expectedHash,
        ArtifactPolicyIdentity::manifest_hash($worldPolicies[$world]),
        "the ordered $world pin map binds its supplied current manifest hash"
    );
}

echo "\nPART 3 — exact reviewed-registry and snapshot maps\n";
wprism_check_same(
    REGISTRY_SHA256,
    ManifestDispositions::load_library($adapterLibrary)->sha256(),
    'the reviewed disposition registry exactly matches the current WPrism address every host contract pins'
);
$worldSnapshotHashes = [
    'rank-world' => RANK_WORLD_SNAPSHOT_SHA256,
    'yoast-world' => YOAST_WORLD_SNAPSHOT_SHA256,
];
foreach ($worldSnapshotHashes as $world => $expectedHash) {
    wprism_check_same(
        $expectedHash,
        hash('sha256', Canon::encode($worldPolicies[$world]->export_snapshot())),
        "the ordered $world frozen policy snapshot exactly matches its current WPrism address"
    );
}

echo "\nPART 4 — mutated platform boundaries refuse\n";
$scratch = $repo . '/sandbox/tmp/wprism-greenfield-identity-mixed';
$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        return;
    }
    foreach (new FilesystemIterator($path) as $item) {
        if ($item->isDir() && !$item->isLink()) {
            $removeTree($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
};
$removeTree($scratch);
register_shutdown_function(static function () use ($removeTree, $scratch): void {
    if (wprism_check_failed() === 0) {
        $removeTree($scratch);
    }
});
if (!mkdir($scratch . '/capabilities', 0777, true) && !is_dir($scratch . '/capabilities')) {
    throw new RuntimeException("cannot create $scratch");
}

$platformPath = $scratch . '/capabilities/platform.json';
$platform = Canon::decode(Canon::read_file($adapterLibrary->platformBoundaryPath()));
$platform['platform']['spec_version'] = WPRISM_SPEC_VERSION - 1;
Canon::write_file($platformPath, Canon::encode($platform));
$specMismatch = null;
try {
    ManifestDispositions::platform_boundary($scratch);
} catch (Throwable $error) {
    $specMismatch = $error->getMessage();
}
wprism_check(
    is_string($specMismatch) && str_contains($specMismatch, 'platform version disagrees with the loaded agent'),
    'a platform spec mutation refuses through the shipped platform-boundary sentence'
);
wprism_check(
    is_string($specMismatch) && str_contains($specMismatch, $platformPath),
    'the spec-mutation refusal names the exact malformed platform boundary'
);

$agentOnly = Canon::decode(Canon::read_file($adapterLibrary->platformBoundaryPath()));
$agentOnly['platform']['agent_version'] = WPRISM_AGENT_VERSION . '-mismatch';
Canon::write_file($platformPath, Canon::encode($agentOnly));
$agentMismatch = null;
try {
    ManifestDispositions::platform_boundary($scratch);
} catch (Throwable $error) {
    $agentMismatch = $error->getMessage();
}
wprism_check(
    is_string($agentMismatch) && str_contains($agentMismatch, 'platform version disagrees with the loaded agent'),
    'an agent-version mutation refuses through the same shipped platform-boundary sentence'
);

Canon::write_file($platformPath, Canon::read_file($adapterLibrary->platformBoundaryPath()));
$restored = ManifestDispositions::platform_boundary($scratch);
wprism_check_same(
    [WPRISM_AGENT_VERSION, WPRISM_SPEC_VERSION],
    [$restored['agent_version'] ?? null, $restored['spec_version'] ?? null],
    'the unmodified WPrism platform boundary loads, proving the two refusals are mutation-specific'
);

wprism_check_summary('spec v3 digest neutrality');
