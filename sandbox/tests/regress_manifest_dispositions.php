<?php
/** Offline contract for DUO-3224's external manifest ratification registry. */

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}
function is_multisite(): bool { return false; }

require __DIR__ . '/../../agent/src/Canon.php';
require __DIR__ . '/../../agent/src/OptionState.php';
require __DIR__ . '/../../agent/src/Db.php';
require __DIR__ . '/../../agent/src/ManifestDispositions.php';
require __DIR__ . '/../../agent/src/CapabilityRegistry.php';
require __DIR__ . '/../../agent/src/Policy.php';
require __DIR__ . '/../../agent/src/Ledger.php';
require __DIR__ . '/../../agent/src/RepositoryCompiler.php';
require __DIR__ . '/../../cli/src/PlanSummary.php';
require __DIR__ . '/../../cli/src/CodeDeploy.php';

use Duo\Canon;
use Duo\ManifestDispositions;
use Duo\CapabilityRegistry;
use Duo\Policy;
use Duo\RepositoryCompiler;
use Duo\Orchestrator\CodeDeploy;
use Duo\Orchestrator\PlanSummary;

final class WP_CLI {
    public static array $lines = [];
    public static function add_command($name, $class): void {}
    public static function line($line): void { self::$lines[] = (string) $line; }
    public static function error($message): void { throw new RuntimeException((string) $message); }
    public static function halt($code): void { throw new RuntimeException("halt:$code"); }
}
require __DIR__ . '/../../agent/src/Cli.php';

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
function expect_throw(callable $fn, string $needle, string $message): void {
    try {
        $fn();
        check(false, "$message (no exception)");
    } catch (Throwable $e) {
        check(str_contains($e->getMessage(), $needle), "$message ({$e->getMessage()})");
    }
}
function remove_fixture_tree(string $path): void {
    if (!is_dir($path)) { return; }
    foreach (new FilesystemIterator($path) as $item) {
        if ($item->isDir() && !$item->isLink()) {
            remove_fixture_tree($item->getPathname());
        } else {
            unlink($item->getPathname());
        }
    }
    rmdir($path);
}

$repo = realpath(__DIR__ . '/../..');
$manifestDir = $repo . '/manifests';
putenv("DUO_MANIFESTS_DIR=$manifestDir");
$registry = ManifestDispositions::load($manifestDir);
$data = $registry->data();
$manifestFiles = array_values(array_filter(
    glob($manifestDir . '/*.json') ?: [],
    fn(string $path): bool => basename($path) !== 'dispositions.json'
));
$manifests = array_map(fn(string $path): array => Canon::decode(Canon::read_file($path)), $manifestFiles);
$manifestsByName = [];
foreach ($manifests as $manifest) {
    $manifestsByName[(string) ($manifest['name'] ?? '')] = $manifest;
}
$capabilityRegistry = CapabilityRegistry::load($manifestDir, $registry, $manifests);

echo "\n== complete external matrix and honest classifications ==\n";
check(count($data['manifests']) === count($manifestFiles), 'every shipped manifest has exactly one external disposition');
check(
    array_diff(array_column($data['manifests'], 'status'), ['certified', 'experimental', 'excluded']) === [],
    'every shipped manifest has a closed reviewed disposition'
);
check(
    ($data['manifests']['paid-memberships-pro']['status'] ?? null) === 'experimental'
        && ($data['manifests']['the-events-calendar']['status'] ?? null) === 'experimental',
    'PMPro and The Events Calendar remain explicitly experimental'
);
check(($data['profiles']['fse']['status'] ?? null) === 'certified', 'FSE is a named certified profile of core');
foreach ($data['manifests'] as $name => $entry) {
    check(isset($entry['supported_versions'], $entry['capabilities']['entity_sections'], $entry['capabilities']['field_sections']), "$name names versions, entities, and fields");
    check(isset($entry['capabilities']['operations'], $entry['capabilities']['lifecycle_phases'], $entry['capabilities']['deletion_semantics']), "$name names operations, lifecycle, and deletion semantics");
    check(($entry['unsupported'] ?? []) !== [], "$name explicitly names unsupported behavior");
}

echo "\n== evidence references and policy readiness ==\n";
function subject_test_is_discoverable(string $repo, string $test, string $name): bool {
    if ($test === "conformance-$name") {
        return is_file("$repo/sandbox/conformance/entries/$name.json");
    }
    if ($test === 'exact-artifact-version-matrix' || ($test === 'multisite-refusal' && $name === 'core')) {
        return true;
    }
    return is_executable("$repo/sandbox/certification/tests/$test.sh");
}
foreach ($data['manifests'] as $name => $entry) {
    if ($entry['status'] !== 'certified') { continue; }
    foreach ($entry['evidence']['tests'] as $test) {
        check(subject_test_is_discoverable($repo, $test, $name), "$name cites a discoverable subject-certification test");
    }
}
foreach ($data['profiles'] as $name => $entry) {
    foreach ($entry['evidence']['tests'] as $test) {
        check(subject_test_is_discoverable($repo, $test, $name), "$name profile cites a discoverable subject-certification test");
    }
}
foreach ($data['manifests'] as $name => $entry) {
    if (($entry['status'] ?? null) !== 'certified' || !isset($manifestsByName[$name]['plugin'])) {
        continue;
    }
    check(
        in_array("conformance-$name", $entry['evidence']['tests'] ?? [], true),
        "$name cites its convention-discovered live conformance"
    );
}
check(
    ($data['manifests']['core']['evidence']['tests'] ?? null) === ['conformance-core', 'multisite-refusal'],
    'core cites live conformance and the multisite refusal evidence it claims'
);
check(
    ($data['profiles']['fse']['evidence']['tests'] ?? null) === ['conformance-fse'],
    'FSE cites its named live conformance evidence'
);
check(
    ($data['manifests']['paid-memberships-pro']['evidence']['tests'] ?? null) === ['conformance-paid-memberships-pro'],
    'PMPro conformance is bundle-bound while its disposition remains experimental'
);
$unsafeTestClaim = $data['manifests']['core'];
$unsafeTestClaim['evidence']['tests'] = ['../escape'];
expect_throw(
    fn() => ManifestDispositions::validate_external_entry(
        'core',
        $unsafeTestClaim,
        $manifestsByName['core'],
        ManifestDispositions::EVIDENCE_SCHEMA
    ),
    'lacks current bundle evidence',
    'certification test IDs reject path traversal at disposition load time'
);
$corePolicy = Policy::load(null, ['core']);
$coreBlockers = $corePolicy->adapter_readiness_blockers();
if (($capabilityRegistry->data()['manifests']['core']['evidence']['status'] ?? null) === 'current') {
    check($coreBlockers === [], 'a certified core pin with current evidence contributes no capability blocker');
} else {
    check(($coreBlockers[0]['code'] ?? null) === 'evidence_not_current', 'candidate evidence remains a structured readiness blocker');
}
$pmproPolicy = Policy::load(null, ['paid-memberships-pro']);
$pmproBlockers = $pmproPolicy->adapter_readiness_blockers();
$pmproAuthoredBlocker = array_values(array_filter(
    $pmproBlockers,
    fn(array $row): bool => ($row['code'] ?? null) === 'authored_state_not_certified'
));
check(($pmproAuthoredBlocker[0]['name'] ?? null) === 'paid-memberships-pro', 'an experimental pin is a structured readiness blocker');
check($pmproPolicy->capability_report()['ready'] === false, 'experimental capability output can never report ready');

echo "\n== intent-only tables and default-authored keyspaces remain unsupported/justified ==\n";
$pmproUnsupported = array_fill_keys(array_column($data['manifests']['paid-memberships-pro']['unsupported'], 'surface'), true);
foreach (['pmpro_discount_codes', 'pmpro_discount_codes_levels', 'pmpro_groups', 'pmpro_membership_levels_groups', 'pmpro_memberships_categories'] as $table) {
    check(isset($pmproUnsupported["tables.$table"]), "$table is not presented as implemented table support");
}
$ninjaDefaults = array_column($data['manifests']['ninja-forms']['default_authored_keyspaces'], 'status', 'table');
foreach (['nf3_action_meta', 'nf3_field_meta', 'nf3_form_meta'] as $table) {
    check(($ninjaDefaults[$table] ?? null) === 'justified', "$table has exact default-authored justification");
}
$ninjaDeletes = $data['manifests']['ninja-forms']['capabilities']['deletion_semantics'];
check($ninjaDeletes['supported'] === ['table:nf3_actions', 'table:nf3_fields'], 'Ninja Forms certifies only independently safe child-row deletion');
check(in_array('table:nf3_forms', $ninjaDeletes['unsupported'], true), 'Ninja Forms parent deletion is explicitly unsupported');
check(Policy::load(null, ['ninja-forms'])->deletion_capability('table:nf3_forms') === null, 'Ninja Forms manifest cannot authorize parent deletion');
check(($data['manifests']['paid-memberships-pro']['default_authored_keyspaces'][0]['status'] ?? null) === 'unsupported', 'PMPro default-authored keyspace is explicitly unsupported');

echo "\n== disposition bytes are frozen and content-addressed ==\n";
$snapshotPolicy = Policy::from_snapshot($corePolicy->export_snapshot());
check(
    RepositoryCompiler::resolved_adapters($snapshotPolicy)[0]['disposition']['status'] === 'certified',
    'frozen policy snapshots retain the external disposition'
);
$fixture = sys_get_temp_dir() . '/duo_dispositions_' . bin2hex(random_bytes(5));
mkdir($fixture, 0777, true);
mkdir($fixture . '/capabilities', 0777, true);
register_shutdown_function(fn() => remove_fixture_tree($fixture));
copy($manifestDir . '/core.json', $fixture . '/core.json');
$coreRegistry = $data;
$coreRegistry['manifests'] = ['core' => $data['manifests']['core']];
$coreRegistry['profiles'] = [];
Canon::write_file($fixture . '/dispositions.json', Canon::encode($coreRegistry));
$coreCapabilities = $capabilityRegistry->data();
$coreCapabilities['manifests'] = ['core' => $coreCapabilities['manifests']['core']];
$coreCapabilities['profiles'] = [];
$coreCapabilities['manifests']['core']['evidence']['bundle_digest'] = null;
$coreCapabilities['manifests']['core']['evidence']['closure_digest'] = null;
$coreCapabilities['manifests']['core']['evidence']['git_revision'] = null;
$coreCapabilities['manifests']['core']['evidence']['status'] = 'candidate';
$coreCapabilities['manifests']['core']['evidence']['subject_digest'] = null;
$coreCapabilities['generated_from']['dispositions_sha256'] = hash_file('sha256', $fixture . '/dispositions.json');
Canon::write_file($fixture . '/capabilities/registry.json', Canon::encode($coreCapabilities));
putenv("DUO_MANIFESTS_DIR=$fixture");
$before = RepositoryCompiler::resolved_adapters(Policy::load(null, ['core']))[0]['digest'];
$coreRegistry['manifests']['core']['reason'] .= ' Reviewed wording change.';
Canon::write_file($fixture . '/dispositions.json', Canon::encode($coreRegistry));
$coreCapabilities['generated_from']['dispositions_sha256'] = hash_file('sha256', $fixture . '/dispositions.json');
$coreCapabilities['manifests']['core']['adapter_digest'] = CapabilityRegistry::adapter_digest(
    Canon::decode(Canon::read_file($fixture . '/core.json')),
    $coreRegistry['manifests']['core'],
    $fixture
);
Canon::write_file($fixture . '/capabilities/registry.json', Canon::encode($coreCapabilities));
$after = RepositoryCompiler::resolved_adapters(Policy::load(null, ['core']))[0]['digest'];
check($before !== $after, 'changing only disposition bytes moves the per-adapter digest');

echo "\n== omissions fail loud; CLI/status/promotion consume the same result ==\n";
Canon::write_file($fixture . '/dispositions.json', Canon::encode($data));
expect_throw(fn() => ManifestDispositions::load($fixture), 'coverage mismatch', 'a shipped manifest without a matching exact registry set fails closed');
putenv("DUO_MANIFESTS_DIR=$manifestDir");
WP_CLI::$lines = [];
(new Duo\Cli())->capabilities([], ['all' => true, 'format' => 'json']);
$cliReport = json_decode(WP_CLI::$lines[0] ?? '', true);
check(count($cliReport['manifests'] ?? []) === 15, 'wp duo capabilities --all reports every shipped disposition');
check(($cliReport['registry_sha256'] ?? null) === $capabilityRegistry->report($manifests)['registry_sha256'], 'CLI capability output resolves the exact checked-in generated registry bytes');
$summary = PlanSummary::render(['adapter_dispositions' => $pmproBlockers]);
check($summary['ok'] === false && str_contains(implode("\n", $summary['lines']), 'CAPABILITY_REGISTRY'), 'host status is non-green and explains the experimental adapter');
$hostBlockers = CodeDeploy::dispositionBlockers(['resolved_adapters' => RepositoryCompiler::resolved_adapters($pmproPolicy)]);
check(($hostBlockers[0]['name'] ?? null) === 'paid-memberships-pro', 'host promotion gate refuses the same experimental disposition');

// DUO-3372: blockers()/report() are unreachable on the live path (load()'s
// one-for-one coverage check refuses an uncovered manifest first), so they are
// tested by DIRECT call. An uncovered manifest must be a fail-closed BLOCKER,
// never a silent skip — the file's own doctrine is "a manifest cannot certify
// itself merely by existing beside the agent".
$uncovered = ['name' => 'no-such-uncovered-adapter'];
$directBlockers = $registry->blockers([$uncovered]);
check(
    count($directBlockers) === 1
        && $directBlockers[0]['name'] === 'no-such-uncovered-adapter'
        && $directBlockers[0]['status'] === ManifestDispositions::STATUS_UNCOVERED
        && str_contains($directBlockers[0]['reason'], 'cannot certify itself merely by existing'),
    'DUO-3372: blockers() surfaces an uncovered manifest as an explicit `uncovered` blocker, never a silent skip'
);
$directReport = $registry->report([$uncovered]);
check(
    $directReport['ready'] === false
        && count($directReport['blockers']) === 1
        && ($directReport['blockers'][0]['status'] ?? null) === ManifestDispositions::STATUS_UNCOVERED
        && count(array_filter(
            $directReport['manifests'],
            static fn(array $r): bool => ($r['name'] ?? null) === 'no-such-uncovered-adapter'
        )) === 1,
    'DUO-3372: report() lists the uncovered manifest as an `uncovered` row and is never `ready` while one exists'
);
// A CERTIFIED manifest is still not a blocker (regression guard on the surviving skip).
$certifiedName = null;
foreach ($manifests as $m) {
    $e = $registry->entry((string) ($m['name'] ?? ''));
    if (($e['status'] ?? null) === 'certified') { $certifiedName = (string) $m['name']; break; }
}
check(
    $certifiedName !== null && $registry->blockers([['name' => $certifiedName]]) === [],
    'DUO-3372: a certified manifest is still not a blocker (the uncovered fix did not turn the certified skip into a row)'
);

if ($failures) {
    fwrite(STDERR, "\n$failures manifest disposition regression assertion(s) failed\n");
    exit(1);
}
echo "\n✔ REGRESS_MANIFEST_DISPOSITIONS PASSED\n";
