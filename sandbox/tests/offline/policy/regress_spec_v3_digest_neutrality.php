<?php
/**
 * WPrism's greenfield identity baseline.
 *
 * Adapter package identity is the exact manifest, disposition, interpreter,
 * provider, and regenerator byte projection that a repository pin and a
 * compiled artifact bind. This suite names the current product baseline as
 * literals: it does not retain a prior-product transition or silently compare
 * two fresh derivations. A package change must therefore re-pin the exact
 * affected digest, manifest hash, registry address, and frozen-policy
 * snapshot deliberately.
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

const BASELINE_FIXTURE_SHA256 = 'b43b18502ae0bf4307841d062647fcf83268b68327709c6d37208dfb104f9134';
const ALL_MANIFEST_HASH = '62e19eaf7adc81e3eed8bd119a7b665f72cd0c5021b3b2fd91d736285de2d1b1';
const REGISTRY_SHA256 = '17678292d74210f0c1996d5f27f0a08911e6fa3ec6e5ac7cde8aa4bf8f49962d';
const SNAPSHOT_SHA256 = '7b97dd3fef2e40fd8aed0dd7004212a084b34771a68f299b9cba4607d26c1741';

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
wprism_check_same('wprism-greenfield-identity/v1', $baseline['format'] ?? null, 'the baseline declares its WPrism-only format');
wprism_check_same(ALL_MANIFEST_HASH, $baseline['pin_sets']['all-17']['manifest_hash'] ?? null, 'the all-manifest baseline is the explicit current address');
wprism_check_same(REGISTRY_SHA256, $baseline['registry_sha256'] ?? null, 'the registry baseline is the explicit current address');
wprism_check_same(SNAPSHOT_SHA256, $baseline['snapshot_sha256'] ?? null, 'the frozen-policy snapshot baseline is the explicit current address');

// The order of this set is part of the exported policy snapshot and therefore
// its hash. Do not sort it: a pin-order change is an identity change that this
// suite must expose rather than normalize away.
$allPins = $baseline['pin_sets']['all-17']['pins'] ?? null;
wprism_check(is_array($allPins) && array_is_list($allPins), 'the all-manifest baseline carries one ordered pin list');
if (!is_array($allPins)) {
    throw new RuntimeException('baseline all-17 pins are unavailable');
}

echo "\nPART 1 — exact adapter identity map\n";
$allPolicy = Policy::load(null, $allPins, adapterLibrary: $adapterLibrary);
$observedDigests = [];
foreach (ArtifactPolicyIdentity::resolved_adapters($allPolicy) as $row) {
    $observedDigests[(string) $row['name']] = (string) $row['digest'];
}
ksort($observedDigests, SORT_STRING);
wprism_check_same(
    $baseline['adapter_digests'] ?? null,
    $observedDigests,
    'all 17 shipped WPrism adapter digests exactly match the greenfield baseline'
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
wprism_check_same(
    ALL_MANIFEST_HASH,
    ArtifactPolicyIdentity::manifest_hash($allPolicy),
    'the ordered all-17 pin map binds the supplied current all-manifest hash'
);

echo "\nPART 3 — exact reviewed-registry and snapshot maps\n";
wprism_check_same(
    REGISTRY_SHA256,
    ManifestDispositions::load_library($adapterLibrary)->sha256(),
    'the reviewed disposition registry exactly matches the current WPrism address every host contract pins'
);
wprism_check_same(
    SNAPSHOT_SHA256,
    hash('sha256', Canon::encode($allPolicy->export_snapshot())),
    'the ordered all-17 frozen policy snapshot exactly matches the current WPrism address'
);

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
