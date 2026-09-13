<?php
/** Offline contract for issue #3224's external manifest ratification registry. */

require_once __DIR__ . '/../../lib/agent_version.php';
wprism_test_define_agent_versions();
function is_multisite(): bool { return false; }

require __DIR__ . '/../../../../agent/src/Kernel/Canon.php';
require __DIR__ . '/../../../../agent/src/Kernel/OptionState.php';
require __DIR__ . '/../../../../agent/src/Kernel/Db.php';
require __DIR__ . '/../../../../agent/src/Policy/AdapterLibrary.php';
require __DIR__ . '/../../../../agent/src/Policy/ManifestDispositions.php';
require __DIR__ . '/../../../../agent/src/Adapter/AdapterRegistry.php';
require __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Policy/ArtifactPolicyIdentity.php';
require __DIR__ . '/../../../../agent/src/Repository/Ledger.php';
require __DIR__ . '/../../../../agent/src/Repository/RepositoryCompiler.php';
require __DIR__ . '/../../../../cli/src/Plan/PlanSummary.php';
require __DIR__ . '/../../../../cli/src/Transport/CodeDeploy.php';
require __DIR__ . '/../../../../tools/src/AdapterPackageValidator.php';

use WPrism\AdapterRegistry;
use WPrism\AdapterLibrary;
use WPrism\Canon;
use WPrism\ManifestDispositions;
use WPrism\Policy;
use WPrism\RepositoryCompiler;
use WPrism\Orchestrator\CodeDeploy;
use WPrism\Orchestrator\PlanSummary;
use WPrism\Tooling\AdapterPackageValidator;

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
/**
 * Publish a whole-registry array into the split layout WP-4.4 shipped: one
 * `dispositions/<name>.json` per subject plus `dispositions/profiles.json`
 * (spec/repo-format.md § v3.4). The directory is emptied first, so a registry
 * that drops a subject drops its document — the state a reader must be able to
 * reach to prove the coverage refusal still fires.
 */
function write_registry(string $manifestDir, array $registry): void {
    $dir = $manifestDir . '/dispositions';
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("cannot create $dir");
    }
    foreach (glob($dir . '/*.json') ?: [] as $stale) {
        unlink($stale);
    }
    foreach ($registry['manifests'] as $name => $entry) {
        Canon::write_file($dir . '/' . $name . '.json', Canon::encode($entry));
    }
    Canon::write_file($dir . '/profiles.json', Canon::encode($registry['profiles'] ?? []));
}

/** Close a mutable historical-layout fixture into one explicitly selected library. */
function fixture_library(string $directory, AdapterLibrary $sourceLibrary): AdapterLibrary {
    foreach (['capabilities', 'dispositions', 'interpreters', 'providers', 'regenerators'] as $relative) {
        $path = $directory . '/' . $relative;
        if (!is_dir($path) && !mkdir($path, 0777, true) && !is_dir($path)) {
            throw new RuntimeException("cannot create $path");
        }
    }
    copy($sourceLibrary->platformBoundaryPath(), $directory . '/capabilities/platform.json');
    copy($sourceLibrary->authoritiesPath(), $directory . '/capabilities/adapter-authorities.json');
    foreach (glob($directory . '/*.json') ?: [] as $manifestPath) {
        $manifest = Canon::decode(Canon::read_file($manifestPath));
        $name = is_array($manifest) ? ($manifest['name'] ?? null) : null;
        $package = is_string($name) ? $sourceLibrary->package($name) : null;
        if ($package === null) {
            continue;
        }
        foreach ($package->shippablePaths() as $source) {
            if (preg_match('~/runtime/(interpreters|providers|regenerators)/([^/]+\.php)$~D', $source, $matches) !== 1) {
                continue;
            }
            copy($source, $directory . '/' . $matches[1] . '/' . $matches[2]);
        }
    }
    return AdapterLibrary::fromLegacyFlatDirectory($directory);
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
$sourceLibrary = AdapterLibrary::fromSourceTree($repo);
$registry = ManifestDispositions::load_library($sourceLibrary);
$data = $registry->data();
$manifestFiles = array_map(
    static fn(\WPrism\AdapterPackage $package): string => $package->manifestPath(),
    $sourceLibrary->packages()
);
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
    && !is_file(dirname($sourceLibrary->platformBoundaryPath()) . '/registry.json')
    && !is_file(dirname($sourceLibrary->platformBoundaryPath()) . '/evidence.json')
    && !is_dir(dirname($sourceLibrary->platformBoundaryPath()) . '/scoped'),
    'the shipped library loads its dispositions with no generated registry, attestation, or bundle tree present'
);
check(
    count(Policy::load(null, ['core'], adapterLibrary: $sourceLibrary)->manifests) === 1,
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
check(
    ($data['manifests']['the-events-calendar']['status'] ?? null) === 'certified',
    'The Events Calendar is explicitly certified after its production-readiness closure'
);
$tecManifest = $manifestsByName['the-events-calendar'];
$tecDisposition = $data['manifests']['the-events-calendar'];
check(
    ($tecManifest['plugin'] ?? null) === 'the-events-calendar/the-events-calendar.php'
        // PR #614 admitted the locked 6.17.4 artifact after native conformance;
        // the exclusive ceiling must match that reviewed three-release range.
        && ($tecManifest['version_range'] ?? null) === ['min' => '6.17.2', 'max' => '6.17.5'],
    'The Events Calendar is bound to the three schema-probed artifacts its regenerator evidence exercises'
);
check(
    ($tecDisposition['supported_versions'] ?? null) === [
        'plugin' => 'the-events-calendar/the-events-calendar.php',
        'range' => ['max' => '6.17.5', 'min' => '6.17.2'],
    ],
    'The Events Calendar disposition repeats the enforceable plugin identity instead of an unbound placeholder'
);
check(
    ($tecDisposition['evidence']['tests'] ?? null) === [
        'conformance-the-events-calendar',
        'exact-artifact-version-matrix',
    ]
        && ($tecDisposition['capabilities']['lifecycle_phases'] ?? null) === ['retire', 'activate', 'verify']
        && in_array('deploy', $tecDisposition['capabilities']['operations'] ?? [], true)
        && in_array('render-api', $tecDisposition['capabilities']['operations'] ?? [], true),
    'The Events Calendar binds the native round-trip, exact-boundary, upgrade, refusal, and lifecycle suites reviewed for certification'
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
$polylangDisposition = $data['manifests']['polylang'];
check(
    ($polylangDisposition['status'] ?? null) === 'certified'
        && ($polylangDisposition['supported_versions'] ?? null) === [
            'plugin' => 'polylang/polylang.php',
            'range' => ['max' => '3.8.10', 'min' => '3.8'],
        ]
        && ($polylangDisposition['evidence']['tests'] ?? null) === [
            'conformance-polylang',
            'exact-artifact-version-matrix',
            'regress-polylang-production-readiness',
            'regress-polylang-multisite-refusal',
            'regress-polylang-tec-rewrite-coinstall',
        ],
    'Polylang is certified only with exact artifacts, readiness, multisite, and shared rewrite evidence'
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
    if (in_array($name, ['core', 'fse'], true)) {
        return ($test === "conformance-$name" && is_file("$repo/sandbox/conformance/entries/$name.json"))
            || ($name === 'core' && $test === 'multisite-refusal');
    }
    static $available = [];
    if (!isset($available[$name])) {
        $available[$name] = array_fill_keys(AdapterPackageValidator::discoverableEvidence($repo, $name), true);
    }
    return isset($available[$name][$test]);
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
$corePolicy = Policy::load(null, ['core'], adapterLibrary: $sourceLibrary);
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
$pmproPolicy = Policy::load(null, ['paid-memberships-pro'], adapterLibrary: $sourceLibrary);
$pmproBlockers = $pmproPolicy->adapter_readiness_blockers();
check($pmproBlockers === [], 'the certified PMPro pin contributes no readiness blocker');
check($pmproPolicy->capability_report()['ready'] === true, 'certified PMPro capability output reports ready');
$tecPolicy = Policy::load(null, ['the-events-calendar'], adapterLibrary: $sourceLibrary);
$tecBlockers = $tecPolicy->adapter_readiness_blockers();
check($tecBlockers === [], 'the certified TEC pin contributes no readiness blocker');
check($tecPolicy->capability_report()['ready'] === true, 'certified TEC capability output reports ready');
// #561's own synthetic-experimental fixture stood here. It wrote a monolith
// `dispositions.json`, which ManifestDispositions::load() now refuses outright
// (ManifestDispositions.php:194), and it asserted exactly what the split-aware
// fixture below already asserts through write_registry(). One mechanism, not
// two: its order-independent read of the blocker list was the better half and
// has been folded into that block.

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
check(
    Policy::load(null, ['ninja-forms'], adapterLibrary: $sourceLibrary)
        ->deletion_capability('table:nf3_forms') === null,
    'Ninja Forms manifest cannot authorize parent deletion'
);
check(
    ($data['manifests']['paid-memberships-pro']['default_authored_keyspaces'] ?? null) === []
        && ($pmproManifest['tables']['pmpro_membership_levelmeta']['default_class'] ?? null) === 'runtime',
    'PMPro has no default-authored keyspace: only the exact reviewed core keys can enter canonical state'
);

echo "\n== disposition bytes are frozen and content-addressed ==\n";
$snapshotPolicy = Policy::from_snapshot($corePolicy->export_snapshot(), $sourceLibrary);
check(
    RepositoryCompiler::resolved_adapters($snapshotPolicy)[0]['disposition']['status'] === 'certified',
    'frozen policy snapshots retain the external disposition'
);
$fixture = sys_get_temp_dir() . '/wprism_dispositions_' . bin2hex(random_bytes(5));
mkdir($fixture, 0777, true);
mkdir($fixture . '/capabilities', 0777, true);
register_shutdown_function(fn() => remove_fixture_tree($fixture));
copy($sourceLibrary->package('core')->manifestPath(), $fixture . '/core.json');
// The shipped platform boundary verbatim; a claim cannot be projected without
// one, and re-authoring it here would describe a runtime nobody is running.
copy($sourceLibrary->platformBoundaryPath(), $fixture . '/capabilities/platform.json');
$coreRegistry = $data;
$coreRegistry['manifests'] = ['core' => $data['manifests']['core']];
$coreRegistry['profiles'] = [];
$experimentalRegistry = $coreRegistry;
$experimentalRegistry['manifests']['core']['status'] = 'experimental';
$experimentalRegistry['manifests']['core']['reason'] = 'Synthetic experimental disposition for blocker-path evidence.';
write_registry($fixture, $experimentalRegistry);
$fixtureLibrary = fixture_library($fixture, $sourceLibrary);
$experimentalPolicy = Policy::load(null, ['core'], adapterLibrary: $fixtureLibrary);
$experimentalBlockers = $experimentalPolicy->adapter_readiness_blockers();
// Selected by CODE rather than by position (#561's half of this): the
// assertion is about which blocker the experimental status raises, and reading
// [0] would silently start measuring blocker ordering the day a second code
// joins the list.
$experimentalAuthoredBlocker = array_values(array_filter(
    $experimentalBlockers,
    fn(array $row): bool => ($row['code'] ?? null) === 'authored_state_not_certified'
));
check(
    ($experimentalAuthoredBlocker[0]['name'] ?? null) === 'core',
    'a synthetic experimental disposition is a structured readiness blocker, independent of the shipped '
        . 'adapter roster and of blocker ordering'
);
check(
    $experimentalPolicy->capability_report()['ready'] === false,
    'synthetic experimental capability output can never report ready'
);
write_registry($fixture, $coreRegistry);
$before = RepositoryCompiler::resolved_adapters(
    Policy::load(null, ['core'], adapterLibrary: $fixtureLibrary)
)[0]['digest'];
$beforeSha = ManifestDispositions::load_library($fixtureLibrary)->sha256();
// One document to edit now. The former version of this check had to rewrite
// the generated registry's `dispositions_sha256` and re-derive its
// `adapter_digest` alongside the edit, or the load refused before the digest
// could be compared — that bookkeeping was the mirror this refactor removed.
$coreRegistry['manifests']['core']['reason'] .= ' Reviewed wording change.';
write_registry($fixture, $coreRegistry);
$after = RepositoryCompiler::resolved_adapters(
    Policy::load(null, ['core'], adapterLibrary: $fixtureLibrary)
)[0]['digest'];
check($before !== $after, 'changing only disposition bytes moves the per-adapter digest');
check(
    $beforeSha !== ManifestDispositions::load_library($fixtureLibrary)->sha256(),
    'and moves the content address a host contract pins, so the change is visible to a consumer that never opens '
    . 'the file'
);
// The one thing the frozen path may not lose: manifest_hash() is what an
// artifact binds, and the v6 snapshot round trip drops the `capabilities`
// record — so the hash must survive it unchanged.
$roundTripped = Policy::from_snapshot(
    Policy::load(null, ['core'], adapterLibrary: $fixtureLibrary)->export_snapshot(),
    $fixtureLibrary
);
check(
    \WPrism\ArtifactPolicyIdentity::manifest_hash($roundTripped)
        === \WPrism\ArtifactPolicyIdentity::manifest_hash(
            Policy::load(null, ['core'], adapterLibrary: $fixtureLibrary)
        ),
    'manifest_hash survives the v6 snapshot round trip byte for byte — dropping the frozen generated registry moved '
    . 'no artifact identity'
);

echo "\n== the closed library inventory rejects unreviewed package bytes ==\n";
// AdapterLibrary validates the physical inventory once, before any Policy or
// reporting consumer can select a package. A stale object remains a closed
// view of the bytes it admitted; constructing a fresh view after an unreviewed
// manifest appears refuses the two-way coverage mismatch.
write_registry($fixture, $coreRegistry);
$registryShaBeforeUncovered = hash('sha256', Canon::encode($coreRegistry));
Canon::write_file(
    $fixture . '/uncovered-adapter.json',
    Canon::encode([
        'name' => 'uncovered-adapter',
        'spec_version' => WPRISM_SPEC_VERSION,
        'option_autoload' => 'preserve',
        'options' => ['uncovered_adapter_layout' => ['class' => 'authored']],
    ])
);
check(
    count(Policy::load(null, ['core'], adapterLibrary: $fixtureLibrary)->manifests) === 1,
    'the already-validated library remains a closed one-package view after an unrelated file appears'
);
check(
    ManifestDispositions::load_library($fixtureLibrary)->sha256() === $registryShaBeforeUncovered,
    'the stray file moves no reviewed byte or content address in that closed view'
);
check(
    message_of(fn() => AdapterLibrary::fromLegacyFlatDirectory($fixture))
        === 'wprism: adapter disposition coverage disagrees with manifests; missing=[uncovered-adapter], orphaned=[]',
    'a fresh library view refuses the unreviewed package before Policy or reporting can consume it'
);
unlink($fixture . '/uncovered-adapter.json');
// The authoring half, over the REAL shipped library, through the REAL loader.
// This is the merge gate's copy of the exactness rule that left the runtime:
// every shipped manifest is reviewed (assert_covers, which is also the nine
// per-entry rules), and every reviewed entry has a shipped manifest.
$shippedNames = array_column($manifests, 'name');
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
$coreEntryVariant = function (callable $edit) use ($fixture, $fixtureLibrary, $coreRegistry): string {
    $variant = $coreRegistry;
    $variant['manifests']['core'] = $edit($variant['manifests']['core']);
    write_registry($fixture, $variant);
    return message_of(fn() => Policy::load(null, ['core'], adapterLibrary: $fixtureLibrary));
};
foreach ([
    'a reviewed entry that is not an object at all' => [
        fn(array $entry): array => ['not', 'an', 'object'],
        "wprism: manifest disposition 'core' must be an object",
    ],
    'a blank reason — the human wrote down nothing' => [
        function (array $entry): array { $entry['reason'] = '   '; return $entry; },
        "wprism: manifest disposition 'core' has a malformed required field",
    ],
    'an empty unsupported list — a claim with no stated boundary' => [
        function (array $entry): array { $entry['unsupported'] = []; return $entry; },
        "wprism: manifest disposition 'core' has a malformed required field",
    ],
    'a capability block missing one of its five keys' => [
        function (array $entry): array { unset($entry['capabilities']['lifecycle_phases']); return $entry; },
        "wprism: manifest disposition 'core' capabilities are malformed",
    ],
    'deletion semantics that name only what is supported' => [
        function (array $entry): array { unset($entry['capabilities']['deletion_semantics']['unsupported']); return $entry; },
        "wprism: manifest disposition 'core' deletion semantics are malformed",
    ],
    'a reviewed section the manifest does not have' => [
        function (array $entry): array {
            $entry['capabilities']['entity_sections'][] = 'invented_section';
            return $entry;
        },
        "wprism: manifest disposition 'core' names absent manifest section 'invented_section'",
    ],
    'an unsupported row with no prose reason' => [
        function (array $entry): array { $entry['unsupported'][0]['reason'] = ''; return $entry; },
        "wprism: manifest disposition 'core' unsupported[0] is malformed",
    ],
    'a certified claim whose evidence citation is gone' => [
        function (array $entry): array { unset($entry['evidence']); return $entry; },
        "wprism: certified manifest disposition 'core' lacks current bundle evidence",
    ],
] as $label => [$edit, $expected]) {
    check($coreEntryVariant($edit) === $expected, "$label is refused on the pinned path ($expected)");
}
// The two table-shaped rules core cannot express, driven through the same
// public entry point Policy::load() uses. load() needs no manifest FILE beside
// the registry any more, which is what makes this a two-file fixture.
$tableProbe = function (array $manifest, array $entry) use ($fixture): string {
    write_registry($fixture, [
        'format' => ManifestDispositions::FORMAT,
        'manifests' => ['table-probe' => $entry],
        'profiles' => [],
    ]);
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
    ) === "wprism: manifest disposition 'table-probe' must mark intent-only table 'probe' unsupported",
    'an intent-only typed table with no reviewed limitation is still refused'
);
check(
    $tableProbe(
        ['name' => 'table-probe', 'tables' => ['probe' => ['class' => 'authored_snapshot', 'default_class' => 'authored']]],
        $probeEntry
    ) === "wprism: manifest disposition 'table-probe' omits default authored keyspace 'probe'",
    'a default-authored keyspace with no reviewed justification is still refused'
);
write_registry($fixture, $coreRegistry);

echo "\n== a reviewed entry is validated where it is PROJECTED, not only where it is pinned ==\n";
// WP-1.2 review F1. Splitting coverage moved the per-entry rules onto the
// PINNED subset, and every reader that resolves an entry by NAME was left
// projecting reviewed bytes nothing had checked: AdapterRegistry::
// shipped_claim() (the funnel under capability_claim() and report(), so `wp
// wprism capabilities --all` and `wprism adapter inspect`) and ManifestDispositions
// ::blockers()/report(). Measured on this exact fixture before the fix, a
// woocommerce entry tampered after review answered `wp wprism capabilities --all`
// with `certified`/`verified` — evidence deleted printed `evidence: []`, an
// invented entity_section printed the invention among its surfaces, and a
// fabricated version range printed a range woocommerce.json does not declare.
//
// woocommerce and not core, because two of the nine rules only exist for a
// manifest that names a `plugin`: core.json declares none, so the version
// cross-check cannot fire on it at all and a group built on core would assert
// three rules while claiming four.
$tamperFixture = sys_get_temp_dir() . '/wprism_dispositions_tamper_' . bin2hex(random_bytes(5));
mkdir($tamperFixture . '/capabilities', 0777, true);
register_shutdown_function(fn() => remove_fixture_tree($tamperFixture));
copy($sourceLibrary->package('woocommerce')->manifestPath(), $tamperFixture . '/woocommerce.json');
copy($sourceLibrary->platformBoundaryPath(), $tamperFixture . '/capabilities/platform.json');
$wooManifest = $manifestsByName['woocommerce'];
$wooRegistry = [
    'format' => ManifestDispositions::FORMAT,
    'manifests' => ['woocommerce' => $data['manifests']['woocommerce']],
    'profiles' => [],
];
$writeTamper = function (callable $edit) use ($tamperFixture, $wooRegistry): void {
    $registry = $wooRegistry;
    $registry['manifests']['woocommerce'] = $edit($registry['manifests']['woocommerce']);
    write_registry($tamperFixture, $registry);
};
$writeTamper(fn(array $entry): array => $entry);
$tamperLibrary = fixture_library($tamperFixture, $sourceLibrary);
/**
 * The library view through the PRODUCT path, exactly the idiom the group above
 * uses. A refusal here is the envelope `wp wprism capabilities --all` prints and
 * halts on; anything else is a report, and a report is the defect.
 */
$tamperedAll = function (callable $edit) use ($writeTamper, $tamperLibrary): array {
    $writeTamper($edit);
    WP_CLI::$lines = [];
    $threw = false;
    try {
        (new WPrism\Cli())->capabilities([], [
            'adapter_library' => $tamperLibrary,
            'all' => true,
            'format' => 'json',
        ]);
    } catch (Throwable $e) {
        $threw = true;
    }
    $out = json_decode(WP_CLI::$lines[0] ?? '', true);
    return [
        'refused' => $threw && ($out['ok'] ?? null) === false,
        'row' => is_array($out['manifests'][0] ?? null) ? $out['manifests'][0] : [],
    ];
};
/** The same tamper, one frame in, where the refusal SENTENCE is readable. */
$tamperedSentence = function (callable $edit) use ($writeTamper, $tamperLibrary, $wooManifest): string {
    $writeTamper($edit);
    return message_of(fn() => AdapterRegistry::report(
        ManifestDispositions::load_library($tamperLibrary),
        [$wooManifest],
        ['operation' => 'promote']
    ));
};
// The guard has to admit the untampered library, or the four refusals below
// prove only that something is broken.
$untampered = $tamperedAll(fn(array $entry): array => $entry);
check(
    $untampered['refused'] === false
        && ($untampered['row']['status'] ?? null) === 'certified'
        && ($untampered['row']['plugin_execution']['status'] ?? null) === 'verified'
        // Through Canon::encode on both sides, which is how validate_entry()
        // itself compares these two: the reviewed entry is canonical (max
        // before min) and woocommerce.json is authored (min before max), so
        // `===` on the arrays would compare key ORDER and fail on a range that
        // agrees.
        && Canon::encode($untampered['row']['supported_versions']['range'] ?? null)
            === Canon::encode($wooManifest['version_range'] ?? null),
    'the reviewed library still projects its certified woocommerce claim, over the range its manifest declares'
);
foreach ([
    'evidence deleted — a certified claim citing nothing' => [
        function (array $entry): array { unset($entry['evidence']); return $entry; },
        "wprism: certified manifest disposition 'woocommerce' lacks current bundle evidence",
    ],
    'an invented entity_section — a surface no manifest carries' => [
        function (array $entry): array {
            $entry['capabilities']['entity_sections'][] = 'invented_section';
            return $entry;
        },
        "wprism: manifest disposition 'woocommerce' names absent manifest section 'invented_section'",
    ],
    'a fabricated version range — a claim over versions nobody reviewed' => [
        function (array $entry): array {
            $entry['supported_versions']['range'] = ['max' => '99.0.0', 'min' => '1.0.0'];
            return $entry;
        },
        "wprism: certified manifest disposition 'woocommerce' versions disagree with its manifest contract",
    ],
    'capabilities deleted outright' => [
        function (array $entry): array { unset($entry['capabilities']); return $entry; },
        "wprism: manifest disposition 'woocommerce' has a malformed required field",
    ],
] as $label => [$edit, $expected]) {
    check($tamperedAll($edit)['refused'] === true, "$label REFUSES the library view instead of projecting a claim");
    check(
        $tamperedSentence($edit) === $expected,
        "and in the words load time uses, unchanged — $label ($expected)"
    );
}
// The fourth tamper had a second, worse shape: ManifestDispositions::report()
// reached $entry['capabilities']['entity_sections'] on an entry that has no
// `capabilities` and produced two PHP warnings and "resolve_sections():
// Argument #2 ($sections) must be of type array, null given" — a TypeError
// where a refusal belongs. Driven directly because that is the method that
// crashed.
$writeTamper(function (array $entry): array { unset($entry['capabilities']); return $entry; });
check(
    message_of(fn() => ManifestDispositions::load_library($tamperLibrary)->report([$wooManifest]))
        === "wprism: manifest disposition 'woocommerce' has a malformed required field",
    'report() refuses that entry in the validator\'s words rather than dying inside resolve_sections()'
);
check(
    message_of(fn() => ManifestDispositions::load_library($tamperLibrary)->blockers([$wooManifest]))
        === "wprism: manifest disposition 'woocommerce' has a malformed required field",
    'and blockers() refuses it too, so `ready` can never be computed from an entry nothing validated'
);
// WP-1.2 review F5. A reviewed entry authored as JSON `null` is PRESENT and
// malformed, not absent. assert_covers() tested it with isset(), which is
// false for null, so it was folded into the coverage list and answered
// "coverage mismatch; missing=[woocommerce]" — an operator sent to add an
// entry that is already sitting in the file. array_key_exists puts it back in
// front of the per-entry validator, which says what is actually wrong with it.
$writeTamper(fn(array $entry): ?array => null);
check(
    message_of(fn() => Policy::load(null, ['woocommerce'], adapterLibrary: $tamperLibrary))
        === "wprism: manifest disposition 'woocommerce' must be an object",
    'a null reviewed entry is refused as malformed, not reported as missing'
);
echo "\n== omissions fail loud; CLI/status/promotion consume the same result ==\n";
WP_CLI::$lines = [];
(new WPrism\Cli())->capabilities([], [
    'adapter_library' => $sourceLibrary,
    'all' => true,
    'format' => 'json',
]);
$cliReport = json_decode(WP_CLI::$lines[0] ?? '', true);
// Derived from the same on-disk glob line 110 counts against, not a literal:
// a literal here silently becomes a weaker assertion every time the shipped
// library gains or loses a manifest (it was 15 while four demo-manifest
// fixtures shipped), which is the opposite of "reports EVERY disposition".
check(count($cliReport['manifests'] ?? []) === count($manifestFiles), 'wp wprism capabilities --all reports every shipped disposition');
check(
    ($cliReport['registry_sha256'] ?? null) === $registry->sha256()
    && ($cliReport['schema_version'] ?? null) === AdapterRegistry::REPORT_FORMAT
    && !array_key_exists('revision', $cliReport['query'] ?? []),
    'CLI capability output addresses the exact checked-in reviewed bytes, under the report wire version that '
    . 'announces it carries no generated digest, subject record, or bound evidence status'
);
$summary = PlanSummary::render(['adapter_dispositions' => $experimentalBlockers]);
check($summary['ok'] === false && str_contains(implode("\n", $summary['lines']), 'ADAPTER_DISPOSITIONS'), 'host status is non-green and explains the experimental adapter');
// issue #3485: the host's section label and the agent's own plan warning report
// the same $plan['adapter_dispositions'] rows, so they must name the same
// mechanism. Both used to say "capability registry" — the document the
// teardown deleted — and nothing pinned the agent half, which is exactly how
// `wprism status` and `wp wprism plan` could come to describe it in two words.
$agentCliSource = (string) file_get_contents("$repo/agent/src/Command/Cli.php");
check(
    str_contains($agentCliSource, "'adapter disposition blocker(s) selected — readiness is not green and host promotion will refuse'")
    && !str_contains($agentCliSource, 'capability registry blocker'),
    "the agent's plan warning over those same rows names adapter dispositions too, never the deleted capability registry"
);
// No WPRISM_MANIFESTS_DIR juggling: the gate is handed the resolved rows of the
// already-loaded $experimentalPolicy and reads no library of its own. #561
// wrapped this call in a putenv pair pointing at its own monolith fixture
// directory, which is gone with that fixture.
$hostBlockers = CodeDeploy::dispositionBlockers([
    'resolved_adapters' => RepositoryCompiler::resolved_adapters($experimentalPolicy),
]);
check(($hostBlockers[0]['name'] ?? null) === 'core', 'host promotion gate refuses the same synthetic experimental disposition');

// issue #3372: an uncovered manifest must be a fail-closed BLOCKER, never a
// silent skip — the file's own doctrine is "a manifest cannot certify itself
// merely by existing beside the agent". Tested by DIRECT call because these two
// methods take the manifests they are given: since WP-1.2 they are also
// REACHABLE through a library-wide report (`wp wprism capabilities --all` over a
// directory holding an unreviewed file), where load() used to refuse the whole
// command first.
$uncovered = ['name' => 'no-such-uncovered-adapter'];
$directBlockers = $registry->blockers([$uncovered]);
check(
    count($directBlockers) === 1
        && $directBlockers[0]['name'] === 'no-such-uncovered-adapter'
        && $directBlockers[0]['status'] === ManifestDispositions::STATUS_UNCOVERED
        && str_contains($directBlockers[0]['reason'], 'cannot certify itself merely by existing'),
    'issue #3372: blockers() surfaces an uncovered manifest as an explicit `uncovered` blocker, never a silent skip'
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
    'issue #3372: report() lists the uncovered manifest as an `uncovered` row and is never `ready` while one exists'
);
// A CERTIFIED manifest is still not a blocker (regression guard on the surviving skip).
$certifiedName = null;
foreach ($manifests as $m) {
    $e = $registry->entry((string) ($m['name'] ?? ''));
    if (($e['status'] ?? null) === 'certified') { $certifiedName = (string) $m['name']; break; }
}
// The REAL manifest bytes, not `['name' => $certifiedName]`. blockers() now
// revalidates the entry it found against the manifest it was handed (review
// F1), and the nine rules cross-check the entry's reviewed sections against
// that manifest's own sections — so a name-only stub is not a lighter fixture,
// it is a manifest missing every section its entry reviews, and it refuses
// with "names absent manifest section 'post_types'". Handing over the bytes
// this adapter actually ships is what the assertion meant all along.
check(
    $certifiedName !== null && $registry->blockers([$manifestsByName[$certifiedName]]) === [],
    'issue #3372: a certified manifest is still not a blocker (the uncovered fix did not turn the certified skip into a row)'
);

if ($failures) {
    fwrite(STDERR, "\n$failures manifest disposition regression assertion(s) failed\n");
    exit(1);
}
echo "\n✔ REGRESS_MANIFEST_DISPOSITIONS PASSED\n";
