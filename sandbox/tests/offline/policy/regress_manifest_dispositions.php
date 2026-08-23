<?php
/** Offline contract for DUO-3224's external manifest ratification registry. */

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}
function is_multisite(): bool { return false; }

require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require __DIR__ . '/../../../../agent/src/Policy/ManifestDispositions.php';
require __DIR__ . '/../../../../agent/src/Adapter/AdapterRegistry.php';
require __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ArtifactPolicyIdentity.php';
require __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require __DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php';
require __DIR__ . '/../../../../cli/src/Plan/PlanSummary.php';
require __DIR__ . '/../../../../cli/src/Transport/CodeDeploy.php';

use Duo\AdapterRegistry;
use Duo\Canon;
use Duo\ManifestDispositions;
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
require __DIR__ . '/../../../../agent/src/Command/Cli.php';

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

$repo = realpath(__DIR__ . '/../../../..');
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

echo "\n== the reviewed dispositions are the WHOLE authored claim source ==\n";
// This group replaces the suite's former premise. Until the evidence chain was
// retired, `Policy::load()` REFUSED a library that had dispositions and no
// generated capability registry beside them ("missing registry data is
// unsupported"), so "dispositions present" always implied a second, derived
// document that had to agree. There is no second document: these bytes are the
// only authored source of a product capability claim, and a library carrying
// them is complete. Asserted rather than assumed, because the whole refactor
// turns on it.
check(
    $registry instanceof ManifestDispositions
    && !is_file($manifestDir . '/capabilities/registry.json')
    && !is_file($manifestDir . '/capabilities/evidence.json')
    && !is_dir($manifestDir . '/capabilities/scoped'),
    'the shipped library loads its dispositions with no generated registry, attestation, or bundle tree present'
);
check(
    count(Policy::load(null, ['core'])->manifests) === 1,
    'and Policy::load() accepts that library — dispositions ALONE are a complete, valid state'
);
check(
    hash_equals($registry->sha256(), hash('sha256', Canon::encode($data))),
    'registry_sha256 is the content address of these exact reviewed bytes, with one definition rather than one per '
    . 'report producer'
);

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
$tecManifest = $manifestsByName['the-events-calendar'];
$tecDisposition = $data['manifests']['the-events-calendar'];
check(
    ($tecManifest['plugin'] ?? null) === 'the-events-calendar/the-events-calendar.php'
        && ($tecManifest['version_range'] ?? null) === ['min' => '6.17.2', 'max' => '6.17.3'],
    'The Events Calendar is bound to the one exact artifact its regenerator was reviewed against'
);
check(
    ($tecDisposition['supported_versions'] ?? null) === [
        'plugin' => 'the-events-calendar/the-events-calendar.php',
        'range' => ['max' => '6.17.3', 'min' => '6.17.2'],
    ],
    'The Events Calendar disposition repeats the enforceable plugin identity instead of an unbound placeholder'
);
check(
    ($tecDisposition['evidence']['tests'] ?? null) === ['conformance-the-events-calendar', 'regress-tec-regen']
        && in_array('deploy', $tecDisposition['capabilities']['operations'] ?? [], true)
        && in_array('render-api', $tecDisposition['capabilities']['operations'] ?? [], true),
    'The Events Calendar names both its exact target round-trip and injected regeneration-recovery exercise'
);
$pmproManifest = $manifestsByName['paid-memberships-pro'];
$pmproDisposition = $data['manifests']['paid-memberships-pro'];
check(
    ($pmproManifest['plugin'] ?? null) === 'paid-memberships-pro/paid-memberships-pro.php'
        && ($pmproManifest['version_range'] ?? null) === ['min' => '3.8.2', 'max' => '3.8.4'],
    'Paid Memberships Pro is bound to the two schema-audited upstream artifacts its table/reference evidence exercises'
);
check(
    ($pmproDisposition['supported_versions'] ?? null) === [
        'plugin' => 'paid-memberships-pro/paid-memberships-pro.php',
        'range' => ['max' => '3.8.4', 'min' => '3.8.2'],
    ]
        && ($pmproDisposition['evidence']['tests'] ?? null) === [
            'conformance-paid-memberships-pro',
            'exact-artifact-version-matrix',
        ],
    'Paid Memberships Pro repeats the enforceable exact range and names its adjacent-tag refusal evidence'
);
foreach ([
    'advanced-editor-tools' => ['plugin' => 'tinymce-advanced/tinymce-advanced.php', 'min' => '5.9.2', 'max' => '5.9.3'],
    'classic-editor' => ['plugin' => 'classic-editor/classic-editor.php', 'min' => '1.7.0', 'max' => '1.7.1'],
] as $name => $expected) {
    $entry = $data['manifests'][$name];
    check(
        ($entry['status'] ?? null) === 'certified'
            && ($entry['supported_versions'] ?? null) === [
                'plugin' => $expected['plugin'],
                'range' => ['max' => $expected['max'], 'min' => $expected['min']],
            ]
            && ($entry['evidence']['tests'] ?? null) === [
                "conformance-$name",
                'exact-artifact-version-matrix',
            ]
            && ($entry['capabilities']['lifecycle_phases'] ?? null) === ['retire', 'activate', 'verify']
            && in_array('deploy', $entry['capabilities']['operations'] ?? [], true)
            && in_array('apply', $entry['capabilities']['operations'] ?? [], true)
            && in_array('render-api', $entry['capabilities']['operations'] ?? [], true),
        "$name binds exact code identity to isolated round-trip, adjacent-version, lifecycle, and plugin-visible evidence"
    );
}
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
    // A third arm used to fall back to sandbox/certification/tests/$test.sh.
    // That whole directory went with the certification-evidence apparatus, so
    // the fallback could only ever return false — it read as a real lookup
    // while being an unconditional refusal, which is the trap this deletes.
    // The two arms above answer every id the certified set cites today: 11
    // `conformance-*` ids (ten manifests plus the FSE profile), 9 exact-
    // version ids, and core's `multisite-refusal`. A new KIND of id is
    // undiscoverable until this function learns where that kind lives, and
    // the callers below say so by name rather than the suite quietly passing
    // on a path nobody maintains.
    return false;
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
    ($data['manifests']['paid-memberships-pro']['evidence']['tests'] ?? null) === [
        'conformance-paid-memberships-pro',
        'exact-artifact-version-matrix',
    ],
    'PMPro conformance and exact-boundary matrix are bundle-bound while its disposition remains experimental'
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
// Unconditional now, and that is the repair. This assertion used to BRANCH on
// whether the generated attestation happened to read `current` on this branch,
// so it passed either way and pinned nothing: on a bundle-owing checkout it
// asserted `evidence_not_current` instead. A reviewed certified claim is
// current by construction — a reviewer wrote it — so there is one answer.
check($coreBlockers === [], 'a certified core pin contributes no capability blocker, on any checkout');
$coreRow = null;
foreach ($corePolicy->capability_report()['manifests'] as $row) {
    if (($row['name'] ?? null) === 'core') {
        $coreRow = $row;
    }
}
check(
    ($coreRow['verdict']['status'] ?? null) === 'certified'
    && ($coreRow['evidence_scope'] ?? null) === 'authored_disposition'
    && ($coreRow['evidence'] ?? null) === ($data['manifests']['core']['evidence'] ?? null)
    && !array_key_exists('adapter_digest', $coreRow),
    "the certified row cites the reviewer's own words verbatim and carries no generated adapter digest — a "
    . 'synthesized currency status here would be the agent vouching for itself'
);
$deadCodes = [
    'evidence_not_current', 'revision_not_certified', 'profile_evidence_not_current',
    'wordpress_version_mismatch', 'php_version_mismatch', 'database_version_mismatch',
    'multisite_unsupported', 'theme_version_mismatch', 'theme_not_active',
    'missing_capability_registry',
];
$agentSource = '';
foreach (['Adapter/AdapterRegistry', 'Policy/ManifestDispositions', 'Policy/Policy'] as $file) {
    $agentSource .= (string) file_get_contents("$repo/agent/src/$file.php");
}
$liveDeadCodes = array_values(array_filter(
    $deadCodes,
    static fn(string $code): bool => str_contains($agentSource, "'$code'")
));
check(
    $liveDeadCodes === [],
    'every blocker code that reported on a GENERATED evidence or platform record is gone from the engine rather '
    . 'than left emitting on data nothing produces (still present: ' . implode(', ', $liveDeadCodes) . ')'
);
check(
    str_contains($agentSource, "'missing_disposition_entry'")
    && str_contains($agentSource, "'authored_state_not_certified'")
    && str_contains($agentSource, "'operation_not_certified'")
    && str_contains($agentSource, "'surface_not_registered'")
    && str_contains($agentSource, "'plugin_version_mismatch'")
    && str_contains($agentSource, "'plugin_not_active'"),
    'while every code that reports a REVIEWED or live-target fact survives — the deletions above are the evidence '
    . 'apparatus, not a relaxation of the gate'
);
$pmproPolicy = Policy::load(null, ['paid-memberships-pro']);
$pmproBlockers = $pmproPolicy->adapter_readiness_blockers();
$pmproAuthoredBlocker = array_values(array_filter(
    $pmproBlockers,
    fn(array $row): bool => ($row['code'] ?? null) === 'authored_state_not_certified'
));
check(($pmproAuthoredBlocker[0]['name'] ?? null) === 'paid-memberships-pro', 'an experimental pin is a structured readiness blocker');
check($pmproPolicy->capability_report()['ready'] === false, 'experimental capability output can never report ready');

echo "\n== typed table identities, closed keyspaces, and parent-delete limits are explicit ==\n";
$pmproUnsupported = array_fill_keys(array_column($data['manifests']['paid-memberships-pro']['unsupported'], 'surface'), true);
foreach (['pmpro_discount_codes', 'pmpro_discount_codes_levels', 'pmpro_groups', 'pmpro_membership_levels_groups', 'pmpro_memberships_categories'] as $table) {
    check(
        ($pmproManifest['tables'][$table]['class'] ?? null) === 'authored_snapshot',
        "$table is a real typed snapshot declaration rather than an intent marker"
    );
}
check(
    isset($pmproUnsupported['tables.pmpro_membership_levels|pmpro_discount_codes|pmpro_groups']),
    'PMPro parent-table deletion remains one explicit unsupported reverse-reference boundary'
);
$ninjaDefaults = array_column($data['manifests']['ninja-forms']['default_authored_keyspaces'], 'status', 'table');
foreach (['nf3_action_meta', 'nf3_field_meta', 'nf3_form_meta'] as $table) {
    check(($ninjaDefaults[$table] ?? null) === 'justified', "$table has exact default-authored justification");
}
$ninjaDeletes = $data['manifests']['ninja-forms']['capabilities']['deletion_semantics'];
check($ninjaDeletes['supported'] === ['table:nf3_actions', 'table:nf3_fields'], 'Ninja Forms certifies only independently safe child-row deletion');
check(in_array('table:nf3_forms', $ninjaDeletes['unsupported'], true), 'Ninja Forms parent deletion is explicitly unsupported');
check(Policy::load(null, ['ninja-forms'])->deletion_capability('table:nf3_forms') === null, 'Ninja Forms manifest cannot authorize parent deletion');
check(
    ($data['manifests']['paid-memberships-pro']['default_authored_keyspaces'] ?? null) === []
        && ($pmproManifest['tables']['pmpro_membership_levelmeta']['default_class'] ?? null) === 'runtime',
    'PMPro has no default-authored keyspace: only the exact reviewed core keys can enter canonical state'
);

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
// The shipped platform boundary verbatim; a claim cannot be projected without
// one, and re-authoring it here would describe a runtime nobody is running.
copy($manifestDir . '/capabilities/platform.json', $fixture . '/capabilities/platform.json');
$coreRegistry = $data;
$coreRegistry['manifests'] = ['core' => $data['manifests']['core']];
$coreRegistry['profiles'] = [];
Canon::write_file($fixture . '/dispositions.json', Canon::encode($coreRegistry));
putenv("DUO_MANIFESTS_DIR=$fixture");
$before = RepositoryCompiler::resolved_adapters(Policy::load(null, ['core']))[0]['digest'];
$beforeSha = ManifestDispositions::load($fixture)->sha256();
// One document to edit now. The former version of this check had to rewrite
// the generated registry's `dispositions_sha256` and re-derive its
// `adapter_digest` alongside the edit, or the load refused before the digest
// could be compared — that bookkeeping was the mirror this refactor removed.
$coreRegistry['manifests']['core']['reason'] .= ' Reviewed wording change.';
Canon::write_file($fixture . '/dispositions.json', Canon::encode($coreRegistry));
$after = RepositoryCompiler::resolved_adapters(Policy::load(null, ['core']))[0]['digest'];
check($before !== $after, 'changing only disposition bytes moves the per-adapter digest');
check(
    $beforeSha !== ManifestDispositions::load($fixture)->sha256(),
    'and moves the content address a host contract pins, so the change is visible to a consumer that never opens '
    . 'the file'
);
// The one thing the frozen path may not lose: manifest_hash() is what an
// artifact binds, and the v6 snapshot round trip drops the `capabilities`
// record — so the hash must survive it unchanged.
$roundTripped = Policy::from_snapshot(Policy::load(null, ['core'])->export_snapshot());
check(
    \Duo\ArtifactPolicyIdentity::manifest_hash($roundTripped)
        === \Duo\ArtifactPolicyIdentity::manifest_hash(Policy::load(null, ['core'])),
    'manifest_hash survives the v6 snapshot round trip byte for byte — dropping the frozen generated registry moved '
    . 'no artifact identity'
);

echo "\n== omissions fail loud; CLI/status/promotion consume the same result ==\n";
Canon::write_file($fixture . '/dispositions.json', Canon::encode($data));
expect_throw(fn() => ManifestDispositions::load($fixture), 'coverage mismatch', 'a shipped manifest without a matching exact registry set fails closed');
putenv("DUO_MANIFESTS_DIR=$manifestDir");
WP_CLI::$lines = [];
(new Duo\Cli())->capabilities([], ['all' => true, 'format' => 'json']);
$cliReport = json_decode(WP_CLI::$lines[0] ?? '', true);
// Derived from the same on-disk glob line 110 counts against, not a literal:
// a literal here silently becomes a weaker assertion every time the shipped
// library gains or loses a manifest (it was 15 while four demo-manifest
// fixtures shipped), which is the opposite of "reports EVERY disposition".
check(count($cliReport['manifests'] ?? []) === count($manifestFiles), 'wp duo capabilities --all reports every shipped disposition');
check(
    ($cliReport['registry_sha256'] ?? null) === $registry->sha256()
    && ($cliReport['schema_version'] ?? null) === AdapterRegistry::REPORT_FORMAT
    && !array_key_exists('revision', $cliReport['query'] ?? []),
    'CLI capability output addresses the exact checked-in reviewed bytes, under the report wire version that '
    . 'announces it carries no generated digest, subject record, or bound evidence status'
);
$summary = PlanSummary::render(['adapter_dispositions' => $pmproBlockers]);
check($summary['ok'] === false && str_contains(implode("\n", $summary['lines']), 'ADAPTER_DISPOSITIONS'), 'host status is non-green and explains the experimental adapter');
// DUO-3485: the host's section label and the agent's own plan warning report
// the same $plan['adapter_dispositions'] rows, so they must name the same
// mechanism. Both used to say "capability registry" — the document the
// teardown deleted — and nothing pinned the agent half, which is exactly how
// `duo status` and `wp duo plan` could come to describe it in two words.
$agentCliSource = (string) file_get_contents("$repo/agent/src/Command/Cli.php");
check(
    str_contains($agentCliSource, "'adapter disposition blocker(s) selected — readiness is not green and host promotion will refuse'")
    && !str_contains($agentCliSource, 'capability registry blocker'),
    "the agent's plan warning over those same rows names adapter dispositions too, never the deleted capability registry"
);
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
