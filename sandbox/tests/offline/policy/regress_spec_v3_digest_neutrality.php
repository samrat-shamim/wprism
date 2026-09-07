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

// Polylang's cold-cache interpreter correction changes only its executable
// address. The manifest and disposition bytes and frozen snapshots stay exact;
// regress_disposition_split.php retains the prior active executable pins.
const BASELINE_FIXTURE_SHA256 = '050d3c7092ec088d15ca1e4687786246d21b54c59258bb7396ca38925e37b6ab';
const RANK_WORLD_MANIFEST_HASH = '6c799d1d913321bb0d440d4f1413792ab8f0b6528b1186c0891498e9c6ae83ba';
const YOAST_WORLD_MANIFEST_HASH = 'dfd90f30a212b7ff026ec1f747fe4628e31f6b88c3519a08fbf17015d5842f30';
const REGISTRY_SHA256 = 'e1947725c71c22f0767a74895fe441bf801aaa3174ced53087c8a8c09ac702f3';
const RANK_WORLD_SNAPSHOT_SHA256 = 'ef66e57722b855c8ec0a8bca12324ad84032b67e44ac7ffe6598d6e767e2070b';
const YOAST_WORLD_SNAPSHOT_SHA256 = '9e139f3acb9eb3b9ee5514a4971a5ff2eb8109364923dd01951f6aed1e939ee2';

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
wprism_check_same(array_values(array_diff($baselineNames, ['yoast'])), $rankPins, 'the Rank-compatible world is exactly all shipped adapters except Yoast');
wprism_check_same(array_values(array_diff($baselineNames, ['rank-math'])), $yoastPins, 'the Yoast-compatible world is exactly all shipped adapters except Rank Math');
wprism_check_same(17, count($rankPins), 'the Rank-compatible maximal world contains 17 adapters');
wprism_check_same(17, count($yoastPins), 'the Yoast-compatible maximal world contains 17 adapters');
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
    'the union of both valid maximal worlds exactly matches all 18 shipped adapter digests'
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
