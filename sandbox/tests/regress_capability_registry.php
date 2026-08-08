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
$currentFixture = $loadedRegistry->data();
$currentFixture['evidence']['status'] = 'current';
foreach ($currentFixture['manifests'] as &$claim) {
    $claim['evidence']['status'] = 'current';
}
unset($claim);
foreach ($currentFixture['profiles'] as &$profile) {
    $profile['evidence']['status'] = 'current';
}
unset($profile);
$registry = CapabilityRegistry::from_snapshot($currentFixture, $dispositions, array_values($manifests));

$target = [
    'wordpress' => '7.0.2',
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

echo "\n== partially certified / experimental ==\n";
$partial = $registry->report([$manifests['paid-memberships-pro']], ['operation' => 'apply'], $target);
check($partial['ready'] === false, 'experimental authored state cannot report ready');
check(in_array('authored_state_not_certified', reason_codes($partial), true), 'partial verdict explains its authored-state boundary');

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
