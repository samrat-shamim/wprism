<?php
/** Offline golden verdicts for DUO-3227's generated capability registry. */

define('DUO_AGENT_VERSION', '0.5.0');
define('DUO_SPEC_VERSION', 2);

require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/ManifestDispositions.php';
require __DIR__ . '/../../agent/src/CapabilityRegistry.php';
require __DIR__ . '/../../cli/src/CodeDeploy.php';
require __DIR__ . '/../../cli/src/PlanSummary.php';

use Duo\Canon;
use Duo\CapabilityRegistry;
use Duo\ManifestDispositions;
use Duo\Orchestrator\CodeDeploy;
use Duo\Orchestrator\PlanSummary;

$failures = 0;
function check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
    } else {
        echo "FAIL: $message\n";
        $failures++;
    }
}

function check_throws(callable $fn, string $needle, string $message): void {
    try {
        $fn();
        check(false, "$message (did not throw)");
    } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $needle), "$message ({$e->getMessage()})");
    }
}

function reason_codes(array $report): array {
    return array_values(array_unique(array_column($report['blockers'] ?? [], 'code')));
}

$repo = realpath(__DIR__ . '/../..');
$dir = $repo . '/manifests';
$dispositions = ManifestDispositions::load($dir);
$manifests = [];
foreach (glob($dir . '/*.json') ?: [] as $file) {
    if (basename($file) !== 'dispositions.json') {
        $manifest = Canon::decode(Canon::read_file($file));
        $manifests[$manifest['name']] = $manifest;
    }
}
$loadedRegistry = CapabilityRegistry::load($dir, $dispositions, array_values($manifests));
$candidateFixture = $loadedRegistry->data();
$candidateFixture['evidence']['status'] = 'candidate';
$candidateFixture['evidence']['tests'] = array_values(array_filter(
    $candidateFixture['evidence']['tests'],
    fn(array $test): bool => ($test['id'] ?? null) !== 'conformance-acf'
));
foreach ($candidateFixture['manifests'] as &$claim) {
    $claim['evidence']['status'] = 'candidate';
}
unset($claim);
foreach ($candidateFixture['profiles'] as &$profile) {
    $profile['evidence']['status'] = 'candidate';
}
unset($profile);
$candidateBootstrapRegistry = CapabilityRegistry::from_snapshot(
    $candidateFixture,
    $dispositions,
    array_values($manifests)
);
$currentFixture = $loadedRegistry->data();
$candidateBootstrap = $candidateBootstrapRegistry->report([$manifests['acf']], ['operation' => 'promote']);
check(
    in_array('evidence_not_current', reason_codes($candidateBootstrap), true),
    'candidate registry can load new future evidence IDs but remains promotion-blocking'
);
$currentFixture['evidence']['status'] = 'current';
$currentFixture['evidence']['tests'] = array_map(
    fn(string $id): array => ['id' => $id, 'verdict' => 'pass'],
    [
        'conformance-acf',
        'conformance-contact-form-7',
        'conformance-core',
        'conformance-elementor',
        'conformance-fse',
        'conformance-ninja-forms',
        'conformance-paid-memberships-pro',
        'conformance-polylang',
        'conformance-woocommerce',
        'conformance-yoast',
        'exact-artifact-version-matrix',
        'multisite-refusal',
    ]
);
foreach ($currentFixture['manifests'] as &$claim) {
    $claim['evidence']['status'] = 'current';
}
unset($claim);
foreach ($currentFixture['profiles'] as &$profile) {
    $profile['evidence']['status'] = 'current';
}
unset($profile);
$registry = CapabilityRegistry::from_snapshot($currentFixture, $dispositions, array_values($manifests));

$missingCurrentEvidence = $currentFixture;
$missingCurrentEvidence['evidence']['tests'] = array_values(array_filter(
    $missingCurrentEvidence['evidence']['tests'],
    fn(array $test): bool => ($test['id'] ?? null) !== 'conformance-acf'
));
check_throws(
    fn() => CapabilityRegistry::from_snapshot($missingCurrentEvidence, $dispositions, array_values($manifests)),
    "certified claim 'acf' cites absent or non-passing evidence 'conformance-acf'",
    'current registry still refuses a certified claim whose named evidence is absent'
);
$forgedCandidate = $candidateFixture;
$forgedCandidate['manifests']['acf']['evidence']['status'] = 'current';
check_throws(
    fn() => CapabilityRegistry::from_snapshot($forgedCandidate, $dispositions, array_values($manifests)),
    "evidence binding for 'acf' is malformed",
    'candidate registry cannot forge one claim-level evidence status to current'
);

$target = [
    'wordpress' => '7.0.3',
    'php' => '8.3.33',
    'database' => ['client' => '11.8.8', 'server' => '11.8.8-MariaDB', 'engine' => 'MariaDB'],
    'multisite' => false,
    'active_plugins' => ['advanced-custom-fields/acf.php'],
    'active_theme' => ['template' => 'twentytwentyfive', 'stylesheet' => 'twentytwentyfive'],
    'plugins' => ['advanced-custom-fields/acf.php' => '6.8.7'],
    'themes' => [],
];

echo "\n== fully certified ==\n";
$full = $registry->report([$manifests['acf']], ['operation' => 'promote'], $target);
check($full['ready'] === true, 'current ACF evidence and an exact supported target are certified');
check(($full['manifests'][0]['plugin_execution']['mode'] ?? null) === 'unmodified', 'plugin execution is reported independently');
check(($full['manifests'][0]['authored_state']['status'] ?? null) === 'certified', 'authored-state certification is a separate field');
check(
    ($full['manifests'][0]['evidence']['tests'] ?? null) === ['conformance-acf', 'exact-artifact-version-matrix'],
    'certified ACF cites its own live conformance plus the exact-version matrix'
);

echo "\n== partially certified / experimental ==\n";
$partial = $registry->report([$manifests['paid-memberships-pro']], ['operation' => 'apply'], $target);
check($partial['ready'] === false, 'experimental authored state cannot report ready');
check(in_array('authored_state_not_certified', reason_codes($partial), true), 'partial verdict explains its authored-state boundary');
check(
    ($partial['manifests'][0]['evidence']['tests'] ?? null) === ['conformance-paid-memberships-pro'],
    'PMPro conformance enters evidence without upgrading the experimental claim'
);

echo "\n== unsupported surface ==\n";
$wooTarget = $target;
$wooTarget['plugins']['woocommerce/woocommerce.php'] = '11.0.0';
$unsupported = $registry->report(
    [$manifests['woocommerce']],
    ['operation' => 'delete', 'surface' => 'post_types.product'],
    $wooTarget
);
check($unsupported['ready'] === false, 'explicit WooCommerce product deletion boundary blocks');
check(in_array('surface_explicitly_unsupported', reason_codes($unsupported), true), 'unsupported verdict names the exact registry reason');

echo "\n== expired evidence ==\n";
$candidate = $registry->data();
$candidate['evidence']['status'] = 'candidate';
foreach ($candidate['manifests'] as &$claim) {
    $claim['evidence']['status'] = 'candidate';
}
unset($claim);
foreach ($candidate['profiles'] as &$profile) {
    $profile['evidence']['status'] = 'candidate';
}
unset($profile);
$candidateRegistry = CapabilityRegistry::from_snapshot($candidate, $dispositions, array_values($manifests));
$expired = $candidateRegistry->report([$manifests['core']], ['operation' => 'promote'], $target);
check(in_array('evidence_not_current', reason_codes($expired), true), 'candidate/expired evidence never grants a certified verdict');

echo "\n== version mismatch ==\n";
$versionTarget = $target;
$versionTarget['plugins']['advanced-custom-fields/acf.php'] = '5.12.6';
$version = $registry->report([$manifests['acf']], ['operation' => 'promote'], $versionTarget);
check(in_array('plugin_version_mismatch', reason_codes($version), true), 'out-of-range plugin version is blocked by exact target facts');

echo "\n== multisite ==\n";
$multisiteTarget = $target;
$multisiteTarget['multisite'] = true;
$multisite = $registry->report([$manifests['core']], ['operation' => 'promote'], $multisiteTarget);
check(in_array('multisite_unsupported', reason_codes($multisite), true), 'multisite receives an actionable unsupported verdict');

echo "\n== revision and product-surface agreement ==\n";
$revision = $registry->report([$manifests['core']], [
    'operation' => 'promote',
    'revision' => str_repeat('0', 40),
], $target);
check(in_array('revision_not_certified', reason_codes($revision), true), 'a different platform revision cannot borrow current evidence');
$document = (string) file_get_contents($repo . '/docs/capabilities.md');
$readme = (string) file_get_contents($repo . '/README.md');
$digest = (string) $registry->data()['evidence']['bundle_digest'];
check(str_contains($document, $digest) && str_contains($readme, $digest), 'README and generated compatibility document cite the same exact bundle');
check(!str_contains($document, 'Lifecycle phases: .'), 'generated docs never emit an empty lifecycle sentence');
check(str_contains($document, 'Lifecycle phases: none declared.'), 'generated docs name an intentionally empty lifecycle contract');
$coreEvidence = $registry->data()['manifests']['core']['evidence']['tests'] ?? [];
check(
    in_array('conformance-core', $coreEvidence, true) && in_array('multisite-refusal', $coreEvidence, true),
    'core cites both product-path conformance and the executable multisite refusal boundary'
);
$hostGreen = CodeDeploy::dispositionBlockers(['resolved_adapters' => [[
    'name' => 'acf',
    'disposition' => $dispositions->entry('acf'),
    'capability' => $full['manifests'][0],
]]]);
check($hostGreen === [], 'host promotion consumes the same certified claim as the evaluator');
$status = PlanSummary::render(['adapter_dispositions' => $partial['blockers']]);
check($status['ok'] === false && str_contains(implode("\n", $status['lines']), 'authored_state_not_certified'), 'readiness renders the same structured experimental verdict');

if ($failures) {
    fwrite(STDERR, "\n$failures capability registry regression assertion(s) failed\n");
    exit(1);
}
echo "\n✔ REGRESS_CAPABILITY_REGISTRY PASSED\n";
