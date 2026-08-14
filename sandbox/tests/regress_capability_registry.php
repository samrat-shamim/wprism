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

function candidate_snapshot(array $registry): array {
    foreach (['manifests', 'profiles'] as $section) {
        foreach ($registry[$section] as &$claim) {
            $claim['evidence']['bundle_digest'] = null;
            $claim['evidence']['closure_digest'] = null;
            $claim['evidence']['git_revision'] = null;
            $claim['evidence']['status'] = 'candidate';
            $claim['evidence']['subject_digest'] = null;
        }
        unset($claim);
    }
    return $registry;
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
$candidateFixture = candidate_snapshot($loadedRegistry->data());
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
foreach ($currentFixture['manifests'] as $name => &$claim) {
    $claim['evidence']['bundle_digest'] = hash('sha256', "bundle:manifests.$name");
    $claim['evidence']['closure_digest'] = hash('sha256', "closure:manifests.$name");
    $claim['evidence']['git_revision'] = str_repeat('0', 40);
    $claim['evidence']['status'] = 'current';
    $claim['evidence']['subject_digest'] = $claim['adapter_digest'];
}
unset($claim);
foreach ($currentFixture['profiles'] as $name => &$profile) {
    $profile['evidence']['bundle_digest'] = hash('sha256', "bundle:profiles.$name");
    $profile['evidence']['closure_digest'] = hash('sha256', "closure:profiles.$name");
    $profile['evidence']['git_revision'] = str_repeat('0', 40);
    $profile['evidence']['status'] = 'current';
    $profile['evidence']['subject_digest'] = $profile['subject_digest'];
}
unset($profile);
$registry = CapabilityRegistry::from_snapshot($currentFixture, $dispositions, array_values($manifests));

echo "\n== per-subject certification ==\n";
$scopedFixture = $currentFixture;
foreach ($scopedFixture['manifests'] as &$claim) {
    $claim['evidence']['bundle_digest'] = null;
    $claim['evidence']['closure_digest'] = null;
    $claim['evidence']['git_revision'] = null;
    $claim['evidence']['status'] = 'candidate';
    $claim['evidence']['subject_digest'] = null;
}
unset($claim);
foreach ($scopedFixture['profiles'] as &$profile) {
    $profile['evidence']['bundle_digest'] = null;
    $profile['evidence']['closure_digest'] = null;
    $profile['evidence']['git_revision'] = null;
    $profile['evidence']['status'] = 'candidate';
    $profile['evidence']['subject_digest'] = null;
}
unset($profile);
$scopedWoo = &$scopedFixture['manifests']['woocommerce'];
$scopedWoo['evidence'] = [
    'bundle_digest' => str_repeat('c', 64),
    'bundle_schema' => 'duo-subject-certification-bundle/v1',
    'closure_digest' => str_repeat('d', 64),
    'force_hatches' => [],
    'git_revision' => str_repeat('0', 40),
    'status' => 'current',
    'subject' => 'manifests.woocommerce',
    'subject_digest' => $scopedWoo['adapter_digest'],
    'tests' => $dispositions->entry('woocommerce')['evidence']['tests'],
];
unset($scopedWoo);
$scopedRegistry = CapabilityRegistry::from_snapshot($scopedFixture, $dispositions, array_values($manifests));
$scopedTarget = $target ?? [
    'wordpress' => '7.0.3', 'php' => '8.3.33',
    'database' => ['client' => '11.8.8', 'server' => '11.8.8-MariaDB', 'engine' => 'MariaDB'],
    'multisite' => false, 'active_theme' => ['template' => 'twentytwentyfive', 'stylesheet' => 'twentytwentyfive'], 'themes' => [],
];
$scopedTarget['active_plugins'] = ['woocommerce/woocommerce.php'];
$scopedTarget['plugins'] = ['woocommerce/woocommerce.php' => '11.0.0'];
$scopedReport = $scopedRegistry->report([$manifests['woocommerce']], [
    'operation' => 'apply', 'revision' => str_repeat('f', 40),
], $scopedTarget);
check($scopedReport['ready'] === true, 'a current Woo subject record remains certifying while other evidence is candidate');
check(
    ($scopedReport['evidence_scope'] ?? null) === 'per_subject'
    && array_key_exists('evidence', $scopedReport) && $scopedReport['evidence'] === null
    && ($scopedReport['manifests'][0]['evidence_scope'] ?? null) === 'subject_record',
    'a report exposes per-subject evidence authority without a shared evidence field'
);
check(!in_array('revision_not_certified', reason_codes($scopedReport), true), 'unbound Git revision does not expire a current scoped Woo record');
$mixedScoped = $scopedRegistry->report([$manifests['woocommerce'], $manifests['acf']], ['operation' => 'apply'], $scopedTarget);
check(
    in_array('evidence_not_current', reason_codes($mixedScoped), true)
    && ($mixedScoped['manifests'][0]['evidence']['status'] ?? null) === 'current'
    && ($mixedScoped['manifests'][1]['evidence']['status'] ?? null) === 'candidate',
    'one current subject record never upgrades an unrelated adapter'
);

$missingCurrentEvidence = $currentFixture;
$missingCurrentEvidence['manifests']['acf']['evidence'] = $candidateFixture['manifests']['acf']['evidence'];
$missingRegistry = CapabilityRegistry::from_snapshot($missingCurrentEvidence, $dispositions, array_values($manifests));
$missingReport = $missingRegistry->report([$manifests['acf']], ['operation' => 'promote']);
check(
    in_array('evidence_not_current', reason_codes($missingReport), true),
    'a missing ACF record blocks ACF without invalidating the registry or another subject'
);
$forgedCandidate = $candidateFixture;
$forgedCandidate['manifests']['acf']['evidence']['status'] = 'current';
check_throws(
    fn() => CapabilityRegistry::from_snapshot($forgedCandidate, $dispositions, array_values($manifests)),
    "current evidence binding for 'acf' is malformed",
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
$candidateRegistry = CapabilityRegistry::from_snapshot($candidateFixture, $dispositions, array_values($manifests));
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
check(!in_array('revision_not_certified', reason_codes($revision), true), 'subject closure currentness does not depend on an unrelated Git revision');
$document = (string) file_get_contents($repo . '/docs/capabilities.md');
$readme = (string) file_get_contents($repo . '/README.md');
check(
    str_contains($document, 'Every row names its own independently current evidence record')
    && str_contains($readme, 'independently scoped per manifest and profile'),
    'README and generated capability document describe the same per-subject authority model'
);
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
