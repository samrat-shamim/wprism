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
/**
 * The refusal SENTENCE, for the checks that compare bytes rather than a
 * needle. A refusal whose wording is the contract (AGENTS.md rule 8) has to be
 * asserted whole; str_contains() would pass on a sentence that had quietly
 * gained or lost a clause.
 */
function message_of(callable $fn): string {
    try {
        $fn();
    } catch (Throwable $e) {
        return $e->getMessage();
    }
    return '<no refusal thrown>';
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
    ($data['manifests']['paid-memberships-pro']['status'] ?? null) === 'certified',
    'PMPro is explicitly certified after its isolated readiness closure'
);
$tecManifest = $manifestsByName['the-events-calendar'];
$tecDisposition = $data['manifests']['the-events-calendar'];
check(
    ($tecManifest['plugin'] ?? null) === 'the-events-calendar/the-events-calendar.php'
        // Widened 2026-08-24 from the one-release [6.17.2, 6.17.3): 6.17.3 is
        // the schema-identical upstream release the manifest's own widening
        // note byte-probes (Custom_Tables/V1 identical, SCHEMA_VERSIONs
        // unchanged), exercised at both edges by the version matrix.
        && ($tecManifest['version_range'] ?? null) === ['min' => '6.17.2', 'max' => '6.17.4'],
    'The Events Calendar is bound to the two schema-probed artifacts its regenerator evidence exercises'
);
check(
    ($tecDisposition['supported_versions'] ?? null) === [
        'plugin' => 'the-events-calendar/the-events-calendar.php',
        'range' => ['max' => '6.17.4', 'min' => '6.17.2'],
    ],
    'The Events Calendar disposition repeats the enforceable plugin identity instead of an unbound placeholder'
);
check(
    ($tecDisposition['evidence']['tests'] ?? null) === ['conformance-the-events-calendar', 'exact-artifact-version-matrix', 'regress-tec-regen']
        && in_array('deploy', $tecDisposition['capabilities']['operations'] ?? [], true)
        && in_array('render-api', $tecDisposition['capabilities']['operations'] ?? [], true),
    'The Events Calendar names its exact round-trip, boundary-matrix, and injected regeneration-recovery exercises'
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
        && ($pmproDisposition['status'] ?? null) === 'certified'
        && ($pmproDisposition['evidence']['tests'] ?? null) === [
            'conformance-paid-memberships-pro',
            'exact-artifact-version-matrix',
        ]
        && ($pmproDisposition['capabilities']['lifecycle_phases'] ?? null) === ['retire', 'activate', 'verify']
        && in_array('deploy', $pmproDisposition['capabilities']['operations'] ?? [], true)
        && in_array('render-api', $pmproDisposition['capabilities']['operations'] ?? [], true),
    'Paid Memberships Pro binds its exact range to adversarial, lifecycle, native, and adjacent-tag evidence'
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
    // The two arms above answer every evidence kind the certified set cites
    // today. A new KIND of id is undiscoverable until this function learns
    // where that kind lives, and the callers below say so by name rather than
    // the suite quietly passing on a path nobody maintains.
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
    'PMPro conformance and exact-boundary matrix are the reviewed certification evidence'
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
check($pmproBlockers === [], 'the certified PMPro pin contributes no readiness blocker');
check($pmproPolicy->capability_report()['ready'] === true, 'certified PMPro capability output reports ready');

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
$experimentalRegistry = $coreRegistry;
$experimentalRegistry['manifests']['core']['status'] = 'experimental';
$experimentalRegistry['manifests']['core']['reason'] = 'Synthetic experimental disposition for blocker-path evidence.';
Canon::write_file($fixture . '/dispositions.json', Canon::encode($experimentalRegistry));
putenv("DUO_MANIFESTS_DIR=$fixture");
$experimentalPolicy = Policy::load(null, ['core']);
$experimentalBlockers = $experimentalPolicy->adapter_readiness_blockers();
check(
    ($experimentalBlockers[0]['name'] ?? null) === 'core'
        && ($experimentalBlockers[0]['code'] ?? null) === 'authored_state_not_certified',
    'a synthetic experimental disposition is a structured readiness blocker'
);
check(
    $experimentalPolicy->capability_report()['ready'] === false,
    'synthetic experimental capability output can never report ready'
);
Canon::write_file($fixture . '/dispositions.json', Canon::encode($coreRegistry));
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

echo "\n== WP-1.2: the coverage rule fires on the PIN; directory exactness is an authoring gate ==\n";
// The doctrine this file states in its own header — "a manifest cannot certify
// itself merely by existing beside the agent" — is a rule about a PINNED
// manifest, and this group is where the distinction is pinned down. Before
// WP-1.2 `load()` globbed and decoded every `*.json` beside the dispositions
// and refused on a two-way set difference, so ONE unreviewed file refused every
// unrelated pin along with itself (18 decodes for a one-pin load of the shipped
// 16-manifest library, and a refusal about a manifest nobody asked for). The
// rule split rather than relaxed:
//   runtime   — you may not USE an unreviewed adapter: assert_covers() over the
//               pinned shipped subset, same refusal class, same sentence;
//   authoring — the shipped library is exactly reviewed, both directions:
//               `make release-gate` (tools/capability-doc.php's
//               capdoc_cross_check(), asserted by tests/Tooling/
//               CapabilityDocCoverageTest.php) plus the whole-library check
//               below, which runs in the merge gate on every change.
Canon::write_file($fixture . '/dispositions.json', Canon::encode($coreRegistry));
$registryShaBeforeUncovered = hash('sha256', Canon::encode($coreRegistry));
Canon::write_file(
    $fixture . '/uncovered-adapter.json',
    Canon::encode([
        'name' => 'uncovered-adapter',
        'spec_version' => DUO_SPEC_VERSION,
        'option_autoload' => 'preserve',
        'options' => ['uncovered_adapter_layout' => ['class' => 'authored']],
    ])
);
putenv("DUO_MANIFESTS_DIR=$fixture");
check(
    count(Policy::load(null, ['core'])->manifests) === 1,
    'an uncovered manifest merely SITTING in the library no longer refuses an unrelated pin'
);
check(
    ManifestDispositions::load($fixture)?->sha256() === $registryShaBeforeUncovered,
    'and it moves no reviewed byte: the registry a host contract pins is the same content address it was'
);
// Byte-identical, asserted with === on the whole sentence rather than a needle:
// this is the refusal an operator meets, docs/guides/adapter-authoring.md
// quotes it verbatim, and AGENTS.md rule 8 says a refusal that is not the
// subject of the change does not move. `extra=[]` is part of those bytes; the
// reviewed-entry-with-no-manifest direction it used to carry is now the repo
// gate's, and no runtime caller can populate it.
check(
    message_of(fn() => Policy::load(null, ['uncovered-adapter']))
        === 'duo: manifest disposition coverage mismatch; missing=[uncovered-adapter], extra=[]',
    'PINNING it still refuses, in the same words — the doctrine is about a pinned manifest and it did not move'
);
$authoringSentence = (string) file_get_contents("$repo/docs/guides/adapter-authoring.md");
check(
    str_contains($authoringSentence, 'duo: manifest disposition coverage mismatch; missing=['),
    'and the authoring guide still quotes that sentence, so the operator-facing text and the engine agree'
);
// The library-wide REPORT is where the uncovered manifest surfaces instead:
// `wp duo capabilities --all` used to die on load()'s coverage check before it
// could describe anything, so DUO-3372's synthesized row was unreachable except
// by direct call (the group at the bottom of this file). It is the narrower
// loud answer that replaced the wider refusal — a row, and a report that is not
// ready — never a silent omission.
WP_CLI::$lines = [];
$uncoveredReport = null;
try {
    (new Duo\Cli())->capabilities([], ['all' => true, 'format' => 'json']);
    $uncoveredReport = json_decode(WP_CLI::$lines[0] ?? '', true);
} catch (Throwable $e) {
    $uncoveredReport = ['refused' => $e->getMessage()];
}
$uncoveredRow = null;
foreach (($uncoveredReport['manifests'] ?? []) as $row) {
    if (($row['name'] ?? null) === 'uncovered-adapter') {
        $uncoveredRow = $row;
    }
}
check(
    ($uncoveredReport['ready'] ?? null) === false
        && count($uncoveredReport['manifests'] ?? []) === 2
        && ($uncoveredRow['status'] ?? null) === 'unsupported'
        && ($uncoveredRow['verdict']['status'] ?? null) === 'blocked'
        && in_array(
            'missing_disposition_entry',
            array_column($uncoveredRow['verdict']['reasons'] ?? [], 'code'),
            true
        ),
    'a library-wide capability report answers the uncovered manifest with an unsupported row and '
    . '`missing_disposition_entry` rather than refusing the whole command over it'
);
// The other direction, which no longer has a runtime reader at all: a reviewed
// entry whose manifest is gone. $fixture holds core.json and the uncovered
// probe; $data reviews all 16 shipped names.
Canon::write_file($fixture . '/dispositions.json', Canon::encode($data));
check(
    count(Policy::load(null, ['core'])->manifests) === 1,
    'a reviewed entry that outlived its manifest is no longer a runtime refusal either — it is release-gate work'
);
unlink($fixture . '/uncovered-adapter.json');
putenv("DUO_MANIFESTS_DIR=$manifestDir");
// The authoring half, over the REAL shipped library, through the REAL loader.
// This is the merge gate's copy of the exactness rule that left the runtime:
// every shipped manifest is reviewed (assert_covers, which is also the nine
// per-entry rules), and every reviewed entry has a shipped manifest.
$shippedNames = array_map(fn(string $path): string => basename($path, '.json'), $manifestFiles);
$reviewedNames = array_keys($data['manifests']);
sort($shippedNames, SORT_STRING);
sort($reviewedNames, SORT_STRING);
check($shippedNames === $reviewedNames, 'the shipped library is exactly reviewed, one entry per manifest, both ways');
$coverageRefusal = message_of(fn() => $registry->assert_covers($manifests));
check(
    $coverageRefusal === '<no refusal thrown>',
    "and every one of those entries passes the real per-entry validator against its manifest ($coverageRefusal)"
);
// The two reporting projections did not move for the library that ships. They
// are computed from the registry BYTES and the manifests handed in, and
// neither ever depended on the directory walk that left load(); this pins that
// they still say the same thing about all sixteen. Derived from the statuses
// rather than transcribed, so the assertion keeps its meaning when the library
// gains an adapter.
$expectedBlockers = [];
foreach ($manifests as $manifest) {
    $name = (string) $manifest['name'];
    if (($data['manifests'][$name]['status'] ?? null) !== 'certified') {
        $expectedBlockers[] = [
            'name' => $name,
            'status' => (string) $data['manifests'][$name]['status'],
            'reason' => (string) $data['manifests'][$name]['reason'],
        ];
    }
}
$shippedReport = $registry->report($manifests);
check(
    $registry->blockers($manifests) === $expectedBlockers
        && $shippedReport['blockers'] === $expectedBlockers
        && $shippedReport['ready'] === ($expectedBlockers === [])
        && count($shippedReport['manifests']) === count($manifests)
        && array_column($shippedReport['manifests'], 'name') === array_column($manifests, 'name')
        && !in_array(
            ManifestDispositions::STATUS_UNCOVERED,
            array_column($shippedReport['manifests'], 'status'),
            true
        ),
    'blockers() and report() are unchanged for the shipped set: one row per manifest, the reviewed status verbatim, '
    . 'and no synthesized `uncovered` row anywhere in a library that is exactly reviewed'
);
// The nine per-entry rules are unmoved: they still run, and they still run on
// the PRODUCT path — Policy::load() on a pinned adapter — for every rule the
// core manifest can express. Each variant edits only the reviewed entry.
$coreEntryVariant = function (callable $edit) use ($fixture, $coreRegistry): string {
    $variant = $coreRegistry;
    $variant['manifests']['core'] = $edit($variant['manifests']['core']);
    Canon::write_file($fixture . '/dispositions.json', Canon::encode($variant));
    putenv("DUO_MANIFESTS_DIR=$fixture");
    return message_of(fn() => Policy::load(null, ['core']));
};
foreach ([
    'a reviewed entry that is not an object at all' => [
        fn(array $entry): array => ['not', 'an', 'object'],
        "duo: manifest disposition 'core' must be an object",
    ],
    'a blank reason — the human wrote down nothing' => [
        function (array $entry): array { $entry['reason'] = '   '; return $entry; },
        "duo: manifest disposition 'core' has a malformed required field",
    ],
    'an empty unsupported list — a claim with no stated boundary' => [
        function (array $entry): array { $entry['unsupported'] = []; return $entry; },
        "duo: manifest disposition 'core' has a malformed required field",
    ],
    'a capability block missing one of its five keys' => [
        function (array $entry): array { unset($entry['capabilities']['lifecycle_phases']); return $entry; },
        "duo: manifest disposition 'core' capabilities are malformed",
    ],
    'deletion semantics that name only what is supported' => [
        function (array $entry): array { unset($entry['capabilities']['deletion_semantics']['unsupported']); return $entry; },
        "duo: manifest disposition 'core' deletion semantics are malformed",
    ],
    'a reviewed section the manifest does not have' => [
        function (array $entry): array {
            $entry['capabilities']['entity_sections'][] = 'invented_section';
            return $entry;
        },
        "duo: manifest disposition 'core' names absent manifest section 'invented_section'",
    ],
    'an unsupported row with no prose reason' => [
        function (array $entry): array { $entry['unsupported'][0]['reason'] = ''; return $entry; },
        "duo: manifest disposition 'core' unsupported[0] is malformed",
    ],
    'a certified claim whose evidence citation is gone' => [
        function (array $entry): array { unset($entry['evidence']); return $entry; },
        "duo: certified manifest disposition 'core' lacks current bundle evidence",
    ],
] as $label => [$edit, $expected]) {
    check($coreEntryVariant($edit) === $expected, "$label is refused on the pinned path ($expected)");
}
// The two table-shaped rules core cannot express, driven through the same
// public entry point Policy::load() uses. load() needs no manifest FILE beside
// the registry any more, which is what makes this a two-file fixture.
$tableProbe = function (array $manifest, array $entry) use ($fixture): string {
    Canon::write_file($fixture . '/dispositions.json', Canon::encode([
        'format' => ManifestDispositions::FORMAT,
        'manifests' => ['table-probe' => $entry],
        'profiles' => [],
    ]));
    return message_of(fn() => ManifestDispositions::load($fixture)->assert_covers([$manifest]));
};
$probeEntry = [
    'capabilities' => [
        'deletion_semantics' => ['supported' => [], 'unsupported' => ['nothing is deletable here']],
        'entity_sections' => [],
        'field_sections' => [],
        'lifecycle_phases' => [],
        'operations' => ['apply'],
    ],
    'default_authored_keyspaces' => [],
    'reason' => 'Synthetic entry for the two table-shaped per-entry rules.',
    'status' => 'experimental',
    'supported_versions' => ['plugin' => 'probe/probe.php', 'range' => ['max' => '2.0.0', 'min' => '1.0.0']],
    // Deliberately NOT `tables.probe`: naming that surface is exactly what the
    // intent-only rule below demands, so a probe that named it would assert
    // nothing.
    'unsupported' => [['operation' => 'apply', 'reason' => 'fixture', 'surface' => 'options.probe_option']],
];
check(
    $tableProbe(
        ['name' => 'table-probe', 'tables' => ['probe' => ['class' => 'authored_typed_snapshot_post_v1']]],
        $probeEntry
    ) === "duo: manifest disposition 'table-probe' must mark intent-only table 'probe' unsupported",
    'an intent-only typed table with no reviewed limitation is still refused'
);
check(
    $tableProbe(
        ['name' => 'table-probe', 'tables' => ['probe' => ['class' => 'authored_snapshot', 'default_class' => 'authored']]],
        $probeEntry
    ) === "duo: manifest disposition 'table-probe' omits default authored keyspace 'probe'",
    'a default-authored keyspace with no reviewed justification is still refused'
);
Canon::write_file($fixture . '/dispositions.json', Canon::encode($coreRegistry));

echo "\n== omissions fail loud; CLI/status/promotion consume the same result ==\n";
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
$summary = PlanSummary::render(['adapter_dispositions' => $experimentalBlockers]);
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
$hostBlockers = CodeDeploy::dispositionBlockers([
    'resolved_adapters' => RepositoryCompiler::resolved_adapters($experimentalPolicy),
]);
check(($hostBlockers[0]['name'] ?? null) === 'core', 'host promotion gate refuses the same synthetic experimental disposition');

// DUO-3372: an uncovered manifest must be a fail-closed BLOCKER, never a
// silent skip — the file's own doctrine is "a manifest cannot certify itself
// merely by existing beside the agent". Tested by DIRECT call because these two
// methods take the manifests they are given: since WP-1.2 they are also
// REACHABLE through a library-wide report (`wp duo capabilities --all` over a
// directory holding an unreviewed file), where load() used to refuse the whole
// command first.
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
