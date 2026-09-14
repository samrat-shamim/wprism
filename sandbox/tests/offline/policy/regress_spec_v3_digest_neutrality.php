<?php
/**
 * WPrism's greenfield identity baseline.
 *
 * Adapter package identity is the exact manifest, disposition, interpreter,
 * provider, and regenerator byte projection that a repository pin and a
 * compiled artifact bind. This suite names its historical 21-subject baseline as
 * literals: it does not retain a prior-product transition or silently compare
 * two fresh derivations. A change to a baseline package must re-pin the exact
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

// PR #614 changed TEC's reviewed 6.17.4 boundary; PR #615 changed CF7's message
// PII declaration and interpreter. Re-pin only those two capsule addresses and
// their containing worlds. TEC's disposition also moves the cohort registry,
// so every registry-addressed snapshot changes. Polylang was already re-pinned
// by PR #613; its digest, all other capsule addresses and every pin order stay.
const BASELINE_FIXTURE_SHA256 = 'cbca24cbcedc16fb02bf38b4a160f4ad76334b9ebabdb18bd87507c0385f6dfe';
const RANK_WORLD_MANIFEST_HASH = 'cfc3ba09c89c2f0b76109a28d16d92f23f6c158d892e25f6e85eeabaa69132e0';
const YOAST_WORLD_MANIFEST_HASH = '794afe9aebda7d7e6fc9533b4fbf072705b8b7ca5d1bf3ac4c174ce24fdc905a';
const REGISTRY_SHA256 = '6448cabb56ddb12ffc0a4beb3ecd2bb0d15e33807eed91c4b465dcea1478cb4a';
const RANK_WORLD_SNAPSHOT_SHA256 = 'e58bffab727e3daaeeb8f6b61bd390b8d5364435f70048b4d404bbbc7e1d67c8';
const YOAST_WORLD_SNAPSHOT_SHA256 = '8082c1919253395fd0f214fddd7e02c0cf73154200b724d34270750a95b6fbea';

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
wprism_check_same([], array_values(array_diff($baselineNames, $actualNames)), 'every historical identity fixture subject still exists in the live library');
wprism_check_same(array_values(array_diff($baselineNames, ['change-wp-admin-login', 'yoast'])), $rankPins, 'the Rank-compatible world is the original subject set except Yoast');
wprism_check_same(array_values(array_diff($baselineNames, ['change-wp-admin-login', 'rank-math'])), $yoastPins, 'the Yoast-compatible world is the original subject set except Rank Math');
wprism_check_same(19, count($rankPins), 'the Rank-compatible maximal world contains 19 adapters');
wprism_check_same(19, count($yoastPins), 'the Yoast-compatible maximal world contains 19 adapters');
$worldUnion = array_values(array_unique(array_merge($rankPins, $yoastPins, $baseline['pin_sets']['core+change-wp-admin-login']['pins'])));
sort($worldUnion, SORT_STRING);
wprism_check_same($baselineNames, $worldUnion, 'the compatible worlds jointly cover the complete historical fixture');
wprism_check_same(['rank-math'], array_values(array_diff($rankPins, $yoastPins)), 'only Rank Math distinguishes the Rank-compatible world');
wprism_check_same(['yoast'], array_values(array_diff($yoastPins, $rankPins)), 'only Yoast distinguishes the Yoast-compatible world');

// Whole-registry addressing still sees every new capsule (WP-4.5). The
// historical fixture records only its original cohort; validate its registry
// through the frozen registry reader before comparing the literal hashes.
$liveRegistry = ManifestDispositions::load_library($adapterLibrary);
$fixtureData = $liveRegistry->data();
$fixtureData['manifests'] = array_intersect_key($fixtureData['manifests'], array_flip($baselineNames));
$fixtureManifests = array_map(static fn(string $name): array => Canon::decode(Canon::read_file($adapterLibrary->package($name)->manifestPath())), $baselineNames);
$fixtureRegistry = ManifestDispositions::from_snapshot($fixtureData, $fixtureManifests);
$fixtureSnapshot = static function (Policy $policy) use ($fixtureRegistry): array {
    $snapshot = $policy->export_snapshot();
    $snapshot['dispositions'] = $fixtureRegistry->data();
    ManifestDispositions::from_snapshot($snapshot['dispositions'], $snapshot['manifests']);
    return $snapshot;
};
if (count($actualNames) > count($baselineNames)) {
    wprism_check($liveRegistry->sha256() !== $fixtureRegistry->sha256(), 'a capsule addition still changes the actual whole-registry address');
}
foreach (array_diff($actualNames, $baselineNames) as $name) {
    $current = Policy::load(null, ['core', $name], adapterLibrary: $adapterLibrary)->export_snapshot();
    // The inspection-only load override is not site intent. A frozen site
    // explicitly names its pins (Policy::from_snapshot's count invariant).
    $current['site']['manifests'] = ['core', $name];
    wprism_check_same($liveRegistry->data(), $current['dispositions'], "$name receives the complete current registry");
    wprism_check_same($current, Policy::from_snapshot($current, $adapterLibrary)->export_snapshot(), "$name survives the complete current frozen policy reader");
}

echo "\nPART 1 — exact adapter identity map\n";
$worldPolicies = [
    'core+change-wp-admin-login' => Policy::load(null, $baseline['pin_sets']['core+change-wp-admin-login']['pins'], adapterLibrary: $adapterLibrary),
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
    'the compatible-world union exactly matches all 21 historical adapter digests'
);
wprism_check_same(
    array_keys($baseline['adapter_digests'] ?? []),
    array_keys($observedDigests),
    'the historical digest map admits neither a missing subject nor an unreviewed extra subject'
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
    'every historical manifest byte address exactly matches the baseline upstream of its adapter digest'
);

$observedPinHashes = [];
$observedSnapshotHashes = [];
foreach ((array) ($baseline['pin_sets'] ?? []) as $label => $expected) {
    $pins = is_array($expected) ? ($expected['pins'] ?? null) : null;
    wprism_check(is_array($pins) && array_is_list($pins), "pin set '$label' is an ordered list");
    if (!is_array($pins)) {
        continue;
    }
    $pinPolicy = Policy::load(null, $pins, adapterLibrary: $adapterLibrary);
    $observedPinHashes[(string) $label] = ArtifactPolicyIdentity::manifest_hash($pinPolicy);
    $observedSnapshotHashes[(string) $label] = hash('sha256', Canon::encode($fixtureSnapshot($pinPolicy)));
}
$expectedPinHashes = [];
$expectedSnapshotHashes = [];
foreach ((array) ($baseline['pin_sets'] ?? []) as $label => $expected) {
    $expectedPinHashes[(string) $label] = is_array($expected) ? ($expected['manifest_hash'] ?? null) : null;
    $expectedSnapshotHashes[(string) $label] = is_array($expected) ? ($expected['snapshot_sha256'] ?? null) : null;
}
wprism_check_same(
    $expectedPinHashes,
    $observedPinHashes,
    'every representative repository pin map exactly binds its current WPrism manifest hash'
);
wprism_check_same($expectedSnapshotHashes, $observedSnapshotHashes,
    'every representative pin set, including core+Qi, binds its exact frozen policy snapshot');
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
    $fixtureRegistry->sha256(),
    'the historical reviewed registry exactly matches its recorded address'
);
$worldSnapshotHashes = [
    'core+change-wp-admin-login' => $baseline['pin_sets']['core+change-wp-admin-login']['snapshot_sha256'],
    'rank-world' => RANK_WORLD_SNAPSHOT_SHA256,
    'yoast-world' => YOAST_WORLD_SNAPSHOT_SHA256,
];
foreach ($worldSnapshotHashes as $world => $expectedHash) {
    wprism_check_same(
        $expectedHash,
        hash('sha256', Canon::encode($fixtureSnapshot($worldPolicies[$world]))),
        "the ordered $world frozen policy snapshot exactly matches its recorded fixture address"
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
