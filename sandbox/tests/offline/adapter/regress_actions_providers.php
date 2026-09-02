<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for issue #3338's
 * LOAD-TIME half: the structured `actions`/`providers` manifest grammar, the
 * retirement of the free-form `rebuilders` channel, the effect inventory the
 * new channel feeds, the bytes the shipped adapters actually declare, and the
 * digest that binds manifest-shipped code to its adapter identity — provider
 * files (issue #3338) and, on the same terms and in the same row, regenerator
 * files (issue #3360). The identity row lives here rather than beside each
 * mechanism because it is ONE row built by TWO implementations that cannot
 * call each other; splitting its coverage is how they would drift apart.
 *
 * The regression this exists for is concrete and was live until this change:
 * a manifest could carry `rebuilders: [{"command": "eval '<php>'"}]` and Apply
 * would hand that string to WP-CLI verbatim, with no load-time validation of
 * any kind. Section 1 below loads that exact shape and requires a refusal.
 *
 * Everything here is pure PHP by construction. Policy::load() runs in offline
 * contexts with no WordPress bootstrap (RepositoryCompiler's own docblock: the
 * repository becomes a validated IR "before Tokens, Ledger, Capture, or a
 * target query can be constructed"), so the validators, effects_inventory(),
 * Policy::action_source(), and the digest computation are all reachable
 * against REAL fixture bytes closed into an explicit scratch AdapterLibrary,
 * using the REAL, unmodified engine files. Same idiom as
 * sandbox/tests/offline/adapter/regress_adapter_contract.php.
 *
 * The RUNTIME half of the same contract — negotiation against live plugin
 * state, plugin-sourced `wprism_providers` discovery, invocation receipts,
 * value-level verification, and the timeout budget — is
 * sandbox/tests/offline/adapter/regress_provider_contract.php, which the .sh wrapper runs
 * alongside this file under the one Makefile target. The two are deliberately
 * disjoint: this one never stubs a WordPress function, that one does.
 *
 * Out of reach offline, and covered live instead: Apply's placement of the
 * negotiation gate before the first mutation and the post-commit fatality of a
 * failing action (sandbox/tests/live/regress_fatal_mutations_live.sh), the per-action
 * confirmation lines (regress_option_subkeys.sh, regress_woo_attribute_deletion.sh),
 * and the shipped providers' own invoke() bodies against real plugins
 * (conformance sweeps; the Woo one is exercised against a fake public API by
 * regress_woocommerce_deletion_authority.php).
 *
 * Exit 0 and "ALL PASSED" on success; any failed check prints "FAIL: ..." and
 * the script exits 1.
 */

// WordPress supplies this in production. Policy::load()'s real v1 single-site
// gate is exercised without bootstrapping WordPress, exactly as
// regress_adapter_contract.php does.
function is_multisite(): bool {
    return false;
}

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/agent_version.php';
wprism_test_define_agent_versions();
require $root . '/agent/src/Kernel/Canon.php';
require $root . '/agent/src/Kernel/OptionState.php';
require $root . '/agent/src/Kernel/Db.php';
// Policy.php require_once's NativeActions.php itself (its validators call the
// closed vocabulary at load time), so this file must not require it a second
// time.
require $root . '/agent/src/Policy/Policy.php';
require_once $root . '/sandbox/tests/offline/policy/manifest_fixtures.php';
require $root . '/agent/src/Adapter/Providers.php';
require $root . '/agent/src/Repository/Ledger.php';
require $root . '/agent/src/Repository/RepositoryCompiler.php';
require_once $root . '/agent/src/Repository/SidebarState.php';
require $root . '/agent/src/Repository/RepositoryAuthorization.php';
require $root . '/agent/src/Promotion/Deploy.php';
require $root . '/agent/src/Adapter/SchemaSettlement.php';
// ManifestDispositions.php is deliberately NOT required here, for the same
// reason as NativeActions.php above: Policy.php require_once's
// AdapterRegistry.php, which now names Canon, ManifestDispositions,
// AdapterSources, and TargetProbe as its own file-scope requires — so a second
// bare `require` of it here is a redeclaration fatal, not a safety net.

use WPrism\Canon;
use WPrism\AdapterLibrary;
use WPrism\Deploy;
use WPrism\NativeActions;
use WPrism\Policy;
use WPrism\Providers;
use WPrism\RepositoryCompiler;

$failures = 0;
function check(bool $cond, string $msg): void {
    global $failures;
    if ($cond) {
        echo "ok: $msg\n";
    } else {
        echo "FAIL: $msg\n";
        $failures++;
    }
}

function expect_throw(callable $fn, string $needle, string $msg): void {
    try {
        $fn();
        check(false, "$msg (expected a RuntimeException containing '$needle', none thrown)");
    } catch (\RuntimeException $e) {
        check(str_contains($e->getMessage(), $needle), "$msg (message: {$e->getMessage()})");
    }
}

echo "\n== schema settlement: retained recovery identity is one canonical release pair ==\n";
$releaseRepo = sys_get_temp_dir() . '/wprism_schema_release_pair_' . bin2hex(random_bytes(6));
mkdir($releaseRepo . '/.wprism/artifacts', 0700, true);
mkdir($releaseRepo . '/.wprism/checkpoints', 0700, true);
$releaseRepo = (string) realpath($releaseRepo);
$artifact = $releaseRepo . '/.wprism/artifacts/deploy-release-owner.json';
$checkpoint = $releaseRepo . '/.wprism/checkpoints/deploy-release-owner.sql.enc';
$otherCheckpoint = $releaseRepo . '/.wprism/checkpoints/deploy-stale-owner.sql.enc';
$materializeArtifact = $releaseRepo . '/.wprism/artifacts/materialize-operation-7.json';
$materializeCheckpoint = $releaseRepo . '/.wprism/checkpoints/materialize-operation-7.sql.enc';
foreach ([$artifact, $checkpoint, $otherCheckpoint, $materializeArtifact, $materializeCheckpoint] as $path) {
    file_put_contents($path, 'fixture');
}
$releasePair = new ReflectionMethod(\WPrism\SchemaSettlement::class, 'assert_release_pair');
try {
    $releasePair->invoke(null, $releaseRepo, $artifact, $checkpoint, 'release-owner');
    $releasePair->invoke(
        null,
        $releaseRepo,
        $materializeArtifact,
        $materializeCheckpoint,
        'wprism-env-promotion-operation-7'
    );
    check(true, 'deploy and frozen-materialization releases bind their canonical artifact/checkpoint owner');
} catch (Throwable $failure) {
    check(false, 'canonical schema release pairs were refused: ' . $failure->getMessage());
}
expect_throw(
    static fn() => $releasePair->invoke(null, $releaseRepo, $artifact, $otherCheckpoint, 'release-owner'),
    'do not name the same release',
    'a valid same-site checkpoint from another release refuses before it can become schema recovery debt'
);
expect_throw(
    static fn() => $releasePair->invoke(null, $releaseRepo, $artifact, $checkpoint, 'stale-owner'),
    'does not match the promotion owner',
    'a sibling checkpoint cannot be rebound to another promotion owner'
);
$schemaSource = (string) file_get_contents($root . '/agent/src/Adapter/SchemaSettlement.php');
$pairGateAt = strpos($schemaSource, 'self::assert_release_pair($repo, $artifactPath, $checkpointPath, $promotionOwner);');
$authenticationAt = strpos($schemaSource, 'RetainedCheckpointCipher::verify($repo, $checkpointPath)');
check(
    is_int($pairGateAt) && is_int($authenticationAt) && $pairGateAt < $authenticationAt,
    'the release-pair gate runs before checkpoint authentication, lease continuation or schema intent publication'
);
register_shutdown_function(static function () use ($releaseRepo): void {
    manifest_fixture_remove_tree($releaseRepo);
});

/**
 * Fresh scratch manifests dir for one group, optionally with real
 * manifest-shipped provider files under providers/ and regenerator files under
 * regenerators/ — the exact layout Providers::manifest_provider(),
 * Policy::regenerators(), and both digest implementations resolve.
 *
 * @param array<string, array|string> $files manifest name => decoded manifest or raw JSON
 * @param array<string, string> $providers provider id => PHP source
 * @param array<string, string> $regenerators regenerator name => PHP source
 */
function fresh_manifests_dir(array $files, array $providers = [], array $regenerators = []): string {
    $root = sys_get_temp_dir() . '/wprism_regress_actions_providers_' . bin2hex(random_bytes(4));
    mkdir($root . '/providers', 0777, true);
    mkdir($root . '/regenerators', 0777, true);
    foreach ($files as $name => $content) {
        Canon::write_file(
            "$root/$name.json",
            is_string($content) ? $content : json_encode($content, JSON_PRETTY_PRINT)
        );
    }
    foreach ($providers as $id => $source) {
        file_put_contents("$root/providers/$id.php", $source);
    }
    foreach ($regenerators as $name => $source) {
        file_put_contents("$root/regenerators/$name.php", $source);
    }
    register_shutdown_function(static function () use ($root): void {
        manifest_fixture_remove_tree($root);
    });
    $GLOBALS['actions_providers_adapter_library_root'] = $root;
    $GLOBALS['actions_providers_adapter_library'] = null;
    return $root;
}

/** Select one explicit closed library for subsequent fixture loads. */
function select_fixture_library(string $root): AdapterLibrary {
    $GLOBALS['actions_providers_adapter_library_root'] = $root;
    // The shared closer inventories valid runtime declarations. This suite
    // also feeds Policy a deliberately scalar providers section; mask only
    // that malformed value while closing the physical inventory, then restore
    // the exact authored bytes so Policy's real grammar is what refuses it.
    $masked = [];
    foreach (glob(rtrim($root, '/') . '/*.json') ?: [] as $manifestPath) {
        $raw = Canon::read_file($manifestPath);
        $manifest = Canon::decode($raw);
        if (is_array($manifest) && array_key_exists('providers', $manifest) && !is_array($manifest['providers'])) {
            $masked[$manifestPath] = $raw;
            $manifest['providers'] = [];
            Canon::write_file($manifestPath, Canon::encode($manifest));
        }
    }
    try {
        $library = manifest_fixture_adapter_library($root);
    } finally {
        foreach ($masked as $manifestPath => $raw) {
            Canon::write_file($manifestPath, $raw);
        }
    }
    return $GLOBALS['actions_providers_adapter_library'] = $library;
}

/** Load through the exact fixture inventory rather than process-global path state. */
function fixture_policy_load(?string $repo, ?array $names = null): Policy {
    $library = $GLOBALS['actions_providers_adapter_library'] ?? null;
    if (!$library instanceof AdapterLibrary) {
        $root = $GLOBALS['actions_providers_adapter_library_root'] ?? null;
        if (!is_string($root)) {
            throw new \RuntimeException('actions/providers fixture selected no adapter library');
        }
        $library = select_fixture_library($root);
    }
    return Policy::load($repo, $names, adapterLibrary: $library);
}

/** A minimal, valid effect declaration for an action that wants one. */
function probe_effect(string $id): array {
    return [
        'id' => $id,
        'kind' => 'database',
        'mode' => 'restorable',
        'selector' => ['scope' => 'database_checkpoint', 'type' => 'option', 'value' => 'probe_option'],
    ];
}

/**
 * The fixture every validation case mutates: one manifest declaring one
 * manifest-sourced provider and one action of each kind. Written as a builder
 * so each case shows only the ONE thing it changes.
 *
 * @param array<string,mixed> $overrides
 */
function probe_manifest(array $overrides = []): array {
    return $overrides + [
        'name' => 'probe',
        'spec_version' => WPRISM_SPEC_VERSION,
        'plugin' => 'probe/probe.php',
        'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
        'providers' => [[
            'id' => 'probe-cache-offline',
            'version' => '1.0.0',
            'source' => 'manifest',
            'plugin' => 'probe/probe.php',
            'capabilities' => ['flush', 'warm'],
        ]],
        'actions' => [
            [
                'kind' => 'native',
                'action' => 'transient.delete',
                'args' => ['name' => 'probe_transient'],
                'triggers' => ['table:probe_rows'],
                'effects' => [probe_effect('probe-native-effect')],
            ],
            [
                'kind' => 'provider',
                'provider' => 'probe-cache-offline',
                'capability' => 'flush',
                'args' => ['groups' => ['a', 'b'], 'deep' => true],
            ],
        ],
    ];
}

/** One provider-only schema phase with exact table/effect authority. */
function schema_probe_manifest(): array {
    $manifest = probe_manifest();
    $manifest['engine_features'] = ['schema-settlement/v1', 'spec-window/v1'];
    $manifest['providers'][0]['capabilities'] = ['inspect_schema', 'prepare_schema'];
    $manifest['tables'] = ['probe_projection' => ['class' => 'derived']];
    $manifest['actions'] = [[
        'args' => [],
        'capability' => 'prepare_schema',
        'effects' => [[
            'id' => 'probe-schema',
            'kind' => 'database',
            'mode' => 'restorable',
            'selector' => [
                'scope' => 'database_checkpoint',
                'type' => 'table',
                'value' => 'probe_projection',
            ],
        ]],
        'kind' => 'provider',
        'phase' => 'schema_settle',
        'prepares' => ['probe_projection'],
        'provider' => 'probe-cache-offline',
        'readiness' => 'inspect_schema',
    ]];
    return $manifest;
}

/** Load one probe fixture with the given mutation applied to the whole manifest. */
function load_probe(array $manifest): Policy {
    fresh_manifests_dir(['probe' => $manifest]);
    return fixture_policy_load(null, ['probe']);
}

/** Assert one probe fixture mutation is refused at Policy::load(). */
function refuse_probe(array $manifest, string $needle, string $msg): void {
    expect_throw(fn() => load_probe($manifest), $needle, $msg);
}

// ======================================================================
echo "\n== THE regression: the retired free-form `rebuilders` channel no longer loads ==\n";

// Byte-for-byte the shape manifests/woocommerce.json carried before this
// change, eval'd PHP payload and all. It loaded, and Apply handed the string
// straight to WP_CLI::runcommand(). Refusal — not silent ignoring — is the
// requirement: manifests carry no unknown-top-level-key validator, so dropping
// the key quietly would leave a pinned adapter's derived-state repair not
// happening and not reporting itself.
$retired = [
    'name' => 'retired',
    'spec_version' => WPRISM_SPEC_VERSION,
    'plugin' => 'probe/probe.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    'rebuilders' => [
        ['command' => 'transient delete wc_attribute_taxonomies', 'triggers' => ['table:probe_rows']],
        ['command' => 'eval \'if (!class_exists("WC_Cache_Helper")) { WP_CLI::error("nope"); } WC_Cache_Helper::invalidate_cache_group("taxes");\''],
    ],
];
fresh_manifests_dir(['retired' => $retired]);
expect_throw(
    fn() => fixture_policy_load(null, ['retired']),
    'declares the retired free-form `rebuilders` channel',
    'a manifest declaring `rebuilders` is REFUSED at load (this exact shape loaded and executed before issue #3338)'
);
try {
    fixture_policy_load(null, ['retired']);
} catch (\RuntimeException $e) {
    check(
        str_contains($e->getMessage(), 'migrate to structured `actions` (native or provider)')
            && str_contains($e->getMessage(), 'spec/repo-format.md'),
        'the refusal carries the migration path and where the new grammar is specified'
    );
}
// An EMPTY rebuilders list is refused too: the-events-calendar.json carried
// exactly that, and "present but empty" must not be a way to keep the key.
fresh_manifests_dir(['retired-empty' => ['name' => 'retired-empty', 'spec_version' => WPRISM_SPEC_VERSION, 'rebuilders' => []]]);
expect_throw(
    fn() => fixture_policy_load(null, ['retired-empty']),
    'retired free-form `rebuilders` channel',
    'an EMPTY rebuilders list is refused as well — presence of the key is the refusal, not its contents'
);
// The frozen-policy path re-validates rather than trusting the snapshot, so a
// pinned revision captured before the migration cannot be replayed either.
expect_throw(
    // No manifest is published for this one: ManifestValidator runs ahead of
    // the adapter-source reconstruction, so the `rebuilders` refusal fires
    // before anything reads the manifest library at all — which is the point.
    fn() => Policy::from_snapshot([
        'format' => 'wprism-policy-snapshot/v6',
        'adapter_sources' => ['certificates' => [], 'format' => 'wprism-adapter-sources/v2', 'out_of_tree' => []],
        'dispositions' => null,
        'site' => ['manifests' => ['retired'], 'spec_version' => WPRISM_SPEC_VERSION, 'policy' => []],
        'manifests' => [$retired],
    ]),
    'retired free-form `rebuilders` channel',
    'a FROZEN policy snapshot carrying `rebuilders` is refused too — the snapshot path re-validates, it does not trust'
);

// ======================================================================
echo "\n== the supported shape loads, and every declaration is reachable ==\n";

$policy = load_probe(probe_manifest());
check(true, 'a manifest declaring providers + native and provider actions loads without throwing');
$actions = $policy->actions();
check(count($actions) === 2, 'actions() flattens both declarations');
check(
    ($actions[0]['manifest'] ?? null) === 'probe' && ($actions[0]['index'] ?? null) === 0
        && ($actions[1]['index'] ?? null) === 1,
    'every action row is annotated with its declaring manifest and its index in that manifest'
);
$declarations = $policy->provider_declarations();
check(
    array_keys($declarations) === ['probe-cache-offline']
        && $declarations['probe-cache-offline']['manifest'] === 'probe'
        && $declarations['probe-cache-offline']['version_range'] === ['min' => '1.0.0', 'max' => '2.0.0'],
    'provider_declarations() keys by id and carries the declaring manifest plus the version_range that bounds it'
);
$noRange = probe_manifest();
unset($noRange['plugin'], $noRange['version_range']);
check(
    load_probe($noRange)->provider_declarations()['probe-cache-offline']['version_range'] === null,
    'a manifest with no plugin/version_range yields a null provider range (negotiation then skips the range check by design)'
);

$schemaPolicy = load_probe(schema_probe_manifest());
check(
    count($schemaPolicy->schema_settle_actions()) === 1
        && $schemaPolicy->schema_settle_tables() === [
            'probe_projection' => [
                'manifest' => 'probe',
                'index' => 0,
                'provider' => 'probe-cache-offline',
                'capability' => 'prepare_schema',
            ],
        ],
    'schema settlement projects one exact action/table authority from the loaded policy'
);
check(
    $schemaPolicy->actions_for(['table:probe_projection']) === [],
    'schema settlement never leaks into ordinary state-triggered rebuild selection'
);
$schemaEffects = array_values(array_filter(
    $schemaPolicy->effects_inventory(),
    static fn(array $row): bool => ($row['phase'] ?? null) === 'schema-settle'
));
check(
    count($schemaEffects) === 1
        && ($schemaEffects[0]['effect']['selector']['value'] ?? null) === 'probe_projection',
    'the compiled effect inventory always signs schema DDL even before the table exists'
);
$directDeployBoundary = new ReflectionMethod(Deploy::class, 'assert_direct_provider_boundary');
expect_throw(
    static fn() => $directDeployBoundary->invoke(null, $schemaPolicy, 'all'),
    'host-owned provider settlement',
    'direct all-phase deploy refuses schema settlement at Deploy ownership, before compilation or lifecycle mutation'
);
try {
    $directDeployBoundary->invoke(null, $schemaPolicy, 'activate');
    check(true, 'a host-ordered lifecycle phase may enter the same Deploy path with schema settlement declared');
} catch (Throwable $failure) {
    check(false, 'host-ordered deploy phase was mistaken for direct deploy: ' . $failure->getMessage());
}
$schemaReceiptGate = new ReflectionMethod(\WPrism\SchemaSettlement::class, 'assert_receipts');
$schemaAction = ['prepares' => ['probe_projection']];
$existingSchemaRow = [
    'present' => true,
    'row_count' => 1,
    'rows_sha256' => str_repeat('1', 64),
    'schema_hash' => str_repeat('2', 64),
];
$schemaReceiptGate->invoke(null, [$schemaAction], [[
    'receipt' => [
        'before' => ['probe_projection' => $existingSchemaRow],
        'after' => ['probe_projection' => $existingSchemaRow],
        'verified' => true,
    ],
]], [['table' => 'probe_projection', 'present' => true]]);
check(true, 'schema settlement accepts a complete unchanged structure-and-row witness');
$changedRows = $existingSchemaRow;
$changedRows['rows_sha256'] = str_repeat('3', 64);
expect_throw(
    static fn() => $schemaReceiptGate->invoke(null, [$schemaAction], [[
        'receipt' => [
            'before' => ['probe_projection' => $existingSchemaRow],
            'after' => ['probe_projection' => $changedRows],
            'verified' => true,
        ],
    ]], [['table' => 'probe_projection', 'present' => true]]),
    'changed existing table',
    'an unchanged schema hash cannot hide row mutation in an existing prepared table'
);
$structureOnly = $existingSchemaRow;
unset($structureOnly['row_count'], $structureOnly['rows_sha256']);
expect_throw(
    static fn() => $schemaReceiptGate->invoke(null, [$schemaAction], [[
        'receipt' => [
            'before' => ['probe_projection' => $structureOnly],
            'after' => ['probe_projection' => $structureOnly],
            'verified' => true,
        ],
    ]], [['table' => 'probe_projection', 'present' => true]]),
    'malformed table evidence',
    'schema settlement refuses create-only success without a complete row witness'
);
$duplicateSchema = schema_probe_manifest();
$duplicateSchema['actions'][] = $duplicateSchema['actions'][0];
$duplicateSchema['actions'][1]['effects'][0]['id'] = 'probe-schema-second';
expect_throw(
    fn() => load_probe($duplicateSchema),
    "both settle schema for table 'probe_projection'",
    'one table cannot acquire two schema-settlement authorities even inside one manifest'
);

// ======================================================================
echo "\n== actions: closed entry grammar ==\n";

refuse_probe(probe_manifest(['actions' => ['not-an-object']]), 'must be an object', 'a non-object action entry is refused');
refuse_probe(probe_manifest(['actions' => ['zero' => ['kind' => 'native']]]), 'actions must be a list', 'an actions map (not a list) is refused');
$m = probe_manifest();
unset($m['actions'][0]['kind']);
refuse_probe($m, 'must be "native" or "provider"', 'an action with no kind is refused');
$m = probe_manifest();
$m['actions'][0]['kind'] = 'command';
refuse_probe($m, 'must be "native" or "provider"', 'an unknown action kind is refused — there is no third kind to smuggle a command through');
$m = probe_manifest();
$m['actions'][0]['command'] = 'wp eval ...';
refuse_probe($m, 'contains unknown key(s): command', 'an action carrying the retired `command` key is refused as an unknown key');
$m = probe_manifest();
$m['actions'][1]['action'] = 'transient.delete';
refuse_probe($m, 'contains unknown key(s): action', 'a provider action may not also carry native keys (the two key sets are disjoint)');
$m = probe_manifest();
$m['actions'][0]['provider'] = 'probe-cache-offline';
refuse_probe($m, 'contains unknown key(s): provider', 'a native action may not carry provider keys');
$m = probe_manifest();
$m['actions'][0]['args'] = ['probe_transient'];
refuse_probe($m, '.args must be an object', 'action args given as a list are refused');
// `{}` round-trips through JSON as an empty PHP array, which array_is_list()
// calls a list. An argument-free action (four shipped manifests declare one)
// must stay expressible, so the "args must be an object" rule has to admit it.
$m = probe_manifest();
$m['actions'][1]['args'] = new stdClass();
fresh_manifests_dir(['probe' => json_decode((string) json_encode($m), true)]);
fixture_policy_load(null, ['probe']);
check(true, 'an argument-free action stays expressible as {} (an empty array is not treated as a malformed list)');

echo "\n== actions: native entries are bound to the closed engine vocabulary ==\n";

check(
    NativeActions::vocabulary() === ['transient.delete', 'rewrite.flush'],
    'the v1 native vocabulary is exactly transient.delete and rewrite.flush'
);
$m = probe_manifest();
$m['actions'][0]['action'] = ['transient.delete'];
refuse_probe($m, '.action must be a string', 'a non-string native action name is refused');
$m = probe_manifest();
$m['actions'][0]['action'] = 'shell.exec';
refuse_probe($m, "names unknown native action 'shell.exec'", 'a native action name outside the closed vocabulary is refused AT LOAD TIME');
$m = probe_manifest();
$m['actions'][0]['args'] = ['name' => 'probe_transient', 'flush' => true];
refuse_probe($m, 'contains unknown key(s) for native action', 'an unknown native argument key is refused rather than ignored');
$m = probe_manifest();
$m['actions'][0]['args'] = [];
refuse_probe($m, "is missing required key 'name'", 'a missing required native argument is refused');
foreach ([
    ['.probe', 'a leading-dot transient name'],
    ['probe transient', 'a transient name with a space'],
    ["probe'; DROP TABLE wp_options; --", 'a SQL-shaped transient name'],
    ['probe$(id)', 'a shell-substitution-shaped transient name'],
    [str_repeat('p', 172), 'an over-long transient name'],
] as [$bad, $label]) {
    $m = probe_manifest();
    $m['actions'][0]['args'] = ['name' => $bad];
    refuse_probe($m, 'must be a bounded string matching', "$label is refused by the argument charset");
}
$m = probe_manifest();
$m['actions'][0]['args'] = ['name' => str_repeat('p', 171)];
load_probe($m);
check(true, 'the longest legal transient name (171 bytes, so _transient_timeout_ + name still fits option_name) is accepted');

echo "\n== actions: provider entries resolve inside their own manifest ==\n";

$m = probe_manifest();
$m['actions'][1]['provider'] = 'somebody-elses-provider';
refuse_probe($m, 'must name a `providers` entry declared by manifest', 'an action naming an undeclared provider id is refused');
$m = probe_manifest();
$m['actions'][1]['capability'] = 'Flush';
refuse_probe($m, '.capability must match', 'a capability name outside the bounded charset is refused');
$m = probe_manifest();
$m['actions'][1]['capability'] = 'purge';
refuse_probe($m, "is not listed in provider 'probe-cache-offline' declaration's capabilities", 'a capability the declaration does not advertise is refused at load, before any code exists');
$m = probe_manifest();
$m['actions'][1]['args'] = ['Groups' => ['a']];
refuse_probe($m, '.args keys must match', 'a provider argument key outside the bounded charset is refused');
$m = probe_manifest();
$m['actions'][1]['args'] = ['groups' => ['nested' => 'map']];
refuse_probe($m, 'must be a scalar or a list of scalars', 'a nested-object provider argument is refused — an argument may not carry a payload a provider could interpret');
$m = probe_manifest();
$m['actions'][1]['args'] = ['groups' => [['deep']]];
refuse_probe($m, 'must be a scalar or a list of scalars', 'a list-of-lists provider argument is refused');

// issue #3369 widened this load-time bound by exactly one shape: a list of FLAT
// objects, for the `list<object>` argument type the capability declaration
// grammar gained. The depth bound is what moved (from zero object levels to
// exactly one), not the principle — the negotiated field vocabulary still
// decides which fields this particular capability accepts, and
// regress_provider_contract.php owns that half.
$m = probe_manifest();
$m['actions'][1]['args'] = ['groups' => [['kind' => 'post:probe', 'id' => 7, 'purged' => true]]];
// try/catch rather than a bare check(true) after the call: a load that throws
// here is exactly the regression this asserts against, and letting it escape
// would end the run at this line with exit 255 and no FAIL line naming what
// broke.
try {
    load_probe($m);
    check(true, 'a list of FLAT objects is a legal provider argument shape at load (issue #3369 list<object> values)');
} catch (\Throwable $t) {
    check(false, 'a list of FLAT objects is a legal provider argument shape at load (issue #3369 list<object> values)'
        . ' (refused with: ' . $t->getMessage() . ')');
}
$m = probe_manifest();
$m['actions'][1]['args'] = ['groups' => [['kind' => 'post:probe', 'children' => [['id' => 8]]]]];
refuse_probe($m, "row 0 field 'children' must be a scalar", 'an object row carrying its own nested payload is still refused — one level, not arbitrary depth');
$m = probe_manifest();
$m['actions'][1]['args'] = ['groups' => [['Kind' => 'post:probe']]];
refuse_probe($m, 'row 0 field names must match', 'an object row field name outside the bounded charset is refused');

// Cross-manifest reach is refused as its own case: `providers` is a per-manifest
// namespace at load time even though provider_declarations() is global, so one
// adapter cannot make its behavior depend on another adapter's pin.
$owner = probe_manifest(['name' => 'owner']);
$owner['actions'] = [];
$borrower = [
    'name' => 'borrower',
    'spec_version' => WPRISM_SPEC_VERSION,
    'actions' => [[
        'kind' => 'provider',
        'provider' => 'probe-cache-offline',
        'capability' => 'flush',
        'args' => [],
    ]],
];
fresh_manifests_dir(['owner' => $owner, 'borrower' => $borrower]);
expect_throw(
    fn() => fixture_policy_load(null, ['owner', 'borrower']),
    'must name a `providers` entry declared by manifest',
    "an action may not reach into ANOTHER pinned manifest's provider declaration"
);

echo "\n== actions: exact triggers plus the one feature-gated post-kind primitive ==\n";

foreach ([
    [['post:product:42'], 'an id-bearing trigger'],
    [['Post:product'], 'an uppercase trigger'],
    [['widget:sidebar'], 'an unknown surface prefix'],
    [['term:*'], 'an unbounded term wildcard trigger'],
    [['probe_rows'], 'a bare name with no surface prefix'],
] as [$triggers, $label]) {
    $m = probe_manifest();
    $m['actions'][0]['triggers'] = $triggers;
    refuse_probe($m, 'must be one exact canonical surface', "$label is refused");
}
$m = probe_manifest();
$m['actions'][0]['triggers'] = ['post:*'];
refuse_probe(
    $m,
    'post-kind-action-trigger/v1',
    'post:* without its engine feature refuses by the feature name instead of becoming an inert literal'
);
$m['engine_features'] = ['post-kind-action-trigger/v1', 'spec-window/v1'];
$postKindPolicy = load_probe($m);
check(
    count($postKindPolicy->actions_for(['post:book'])) === 2
    && count($postKindPolicy->actions_for(['post:product'])) === 2
    && count($postKindPolicy->actions_for(['term:book'])) === 1
    && count($postKindPolicy->actions_for(['entity:post'])) === 1,
    'the declared post:* trigger selects every concrete post kind and no term or entity fallback; the unscoped action remains selected'
);
$m = probe_manifest();
$m['actions'][0]['triggers'] = ['table:probe_rows', 'table:probe_rows'];
refuse_probe($m, "repeats exact surface 'table:probe_rows'", 'a duplicated trigger is refused');
$m = probe_manifest();
$m['actions'][0]['triggers'] = [];
refuse_probe($m, '.triggers must be a non-empty list', 'an empty trigger list is refused — absent means unscoped, empty means nothing and is a mistake');
$m = probe_manifest();
$m['actions'][0]['triggers'] = ['table' => 'probe_rows'];
refuse_probe($m, '.triggers must be a non-empty list', 'a trigger map (not a list) is refused');

echo "\n== actions: the effect-contract grammar still gates the new channel ==\n";

$m = probe_manifest();
$m['actions'][0]['effects'] = [['id' => 'probe', 'kind' => 'telepathy', 'mode' => 'restorable', 'selector' => ['scope' => 'database_checkpoint', 'type' => 'option', 'value' => 'probe_option']]];
// issue #3318 split the old combined "kind/mode is unsupported" refusal into one
// message per closed vocabulary, each naming the rejected token and printing
// the legal set — so this needle now asserts the KIND half specifically, which
// is what this fixture actually breaks.
refuse_probe($m, "actions[0].effects[0].kind='telepathy' is not one of the engine-owned effect kinds", 'an action effect with an unsupported kind is refused, and the message names the exact declaration and the legal set');
$m = probe_manifest();
$m['actions'][0]['effects'] = [['id' => 'probe', 'kind' => 'database', 'mode' => 'telekinesis', 'selector' => ['scope' => 'database_checkpoint', 'type' => 'option', 'value' => 'probe_option']]];
refuse_probe($m, "actions[0].effects[0].mode='telekinesis' is not one of the engine-owned reversibility modes", 'an action effect with an unsupported mode is refused separately from kind, so an author is never left guessing which half failed');
$m = probe_manifest();
$m['actions'][0]['effects'] = [];
refuse_probe($m, 'may be empty only for a provider action', 'a native action cannot use the explicit read-only provider effect contract');
$m = probe_manifest();
$m['actions'][1]['effects'] = [];
$readOnlyPolicy = load_probe($m);
check(
    Policy::action_effects($readOnlyPolicy->actions()[1], 1) === [],
    'an explicit empty provider effect list survives policy projection as an intentional read-only claim'
);
$readOnlyRows = array_values(array_filter(
    $readOnlyPolicy->effects_inventory(),
    static fn(array $row): bool => ($row['manifest'] ?? null) === 'probe'
        && ($row['source'] ?? null) === 'provider:probe-cache-offline/flush'
));
check($readOnlyRows === [],
    'effects_inventory emits no fabricated recovery obligation for an explicit read-only provider action');
$m = probe_manifest();
$m['actions'][1]['effects'] = null;
refuse_probe($m, 'must be a non-empty list', 'null is not an explicit read-only provider effect list');
$m = probe_manifest();
$m['actions'][0]['effects'] = [probe_effect('probe-dup')];
$m['actions'][1]['effects'] = [probe_effect('probe-dup')];
expect_throw(fn() => load_probe($m), 'probe-dup', 'two actions declaring the same effect id are refused');

// ======================================================================
echo "\n== providers: the declaration is an identity assertion, closed on every axis ==\n";

refuse_probe(probe_manifest(['providers' => 'nope']), 'providers must be a list', 'a non-list providers section is refused');
refuse_probe(probe_manifest(['providers' => ['nope']]), 'provider 0 must be a JSON object', 'a non-object provider declaration is refused');
$m = probe_manifest();
unset($m['providers'][0]['version']);
refuse_probe($m, 'must declare exactly capabilities, id, plugin, source, version', 'a provider declaration missing a required key is refused');
$m = probe_manifest();
$m['providers'][0]['timeout_seconds'] = 30;
refuse_probe($m, 'must declare exactly capabilities, id, plugin, source, version', 'an unknown provider declaration key is refused — a claim the engine would silently ignore');
foreach ([
    ['Probe-Cache', 'an uppercase provider id'],
    ['1probe', 'a digit-leading provider id'],
    ['probe_cache', 'an underscored provider id'],
    ['../probe', 'a path-traversal-shaped provider id'],
    [str_repeat('p', 65), 'an over-long provider id'],
] as [$bad, $label]) {
    $m = probe_manifest();
    $m['providers'][0]['id'] = $bad;
    $m['actions'][1]['provider'] = $bad;
    refuse_probe(
        $m,
        match ($bad) {
            '../probe' => 'unexpected entry in adapter library root',
            'Probe-Cache' => 'adapter runtime basename',
            default => '.id must match',
        },
        "$label is refused"
    );
}
foreach ([['1.0', 'a two-part version'], ['1.0.0-beta', 'a prerelease version'], ['*', 'a wildcard version']] as [$bad, $label]) {
    $m = probe_manifest();
    $m['providers'][0]['version'] = $bad;
    refuse_probe($m, 'must be an exact <major>.<minor>.<patch> string', "$label is refused");
}
$m = probe_manifest();
$m['providers'][0]['source'] = 'engine';
refuse_probe($m, '.source must be "manifest" or "plugin"', 'a source outside {manifest, plugin} is refused — the engine is not a provider supplier');
$m = probe_manifest();
$m['providers'][0]['plugin'] = '';
refuse_probe($m, 'must be a non-empty plugin basename', 'an empty owning plugin is refused');
$m = probe_manifest();
$m['providers'][0]['plugin'] = 'other/other.php';
refuse_probe($m, "disagrees with manifest 'probe' plugin 'probe/probe.php'", "a provider naming a plugin other than its manifest's own is refused — it would escape the version_range that bounds the adapter");
$m = probe_manifest();
$m['providers'][0]['capabilities'] = [];
refuse_probe($m, '.capabilities must be a non-empty list', 'a provider advertising no capabilities is refused');
$m = probe_manifest();
$m['providers'][0]['capabilities'] = ['flush', 'flush'];
refuse_probe($m, ".capabilities repeats 'flush'", 'a repeated capability name is refused');
$m = probe_manifest();
$m['providers'][0]['capabilities'] = ['Flush'];
$m['actions'][1]['capability'] = 'flush';
refuse_probe($m, '.capabilities[0] must match', 'a capability name outside the bounded charset is refused in the declaration too');
$m = probe_manifest();
$m['providers'][] = $m['providers'][0];
refuse_probe($m, 'declares provider probe-cache-offline more than once', 'one manifest declaring the same provider id twice is refused');

// ======================================================================
echo "\n== providers: v3 manifest runtime moves protocol mechanics into core ==\n";

$contract = [
    'args' => [],
    'reads' => ['option:probe_option'],
    'writes' => ['option:probe_option'],
    'scope' => 'site',
    'idempotent' => true,
    'timeout_seconds' => 30,
    'scoped' => [
        'operation_envelope' => Providers::SCOPED_OPERATION_FORMAT,
        'reconcile' => true,
        'invoke_after' => 'reconcile',
    ],
];
$runtimeManifest = probe_manifest(['engine_features' => [
    \WPrism\ManifestProviderRuntime::FEATURE,
    'spec-window/v1',
]]);
$runtimeManifest['providers'][0]['capabilities'] = ['flush'];
$runtimeManifest['providers'][0]['contracts'] = ['flush' => $contract];
$runtimePolicy = load_probe($runtimeManifest);
$runtimeDeclaration = $runtimePolicy->provider_declarations()['probe-cache-offline'];
check(
    ($runtimeDeclaration['contracts']['flush'] ?? null) === $contract,
    'a feature-gated manifest capability contract is validated at load and reaches the runtime byte-for-byte'
);

$collidingRuntimeManifest = $runtimeManifest;
$collidingRuntimeManifest['providers'][0]['capabilities'] = ['flush', 'warm'];
$collidingRuntimeManifest['providers'][0]['contracts'] = [
    'flush' => array_merge($contract, [
        'scope' => 'entity',
        'context' => ['retry'],
    ]),
    'warm' => array_merge($contract, [
        'scope' => 'entity',
        'context' => ['retry'],
    ]),
];
$collidingRuntimeManifest['actions'][1]['triggers'] = ['post:probe'];
$collidingRuntimeManifest['actions'][] = [
    'kind' => 'provider',
    'provider' => 'probe-cache-offline',
    'capability' => 'warm',
    'args' => [],
    'triggers' => ['post:probe'],
];
refuse_probe(
    $collidingRuntimeManifest,
    "2 capabilities consuming the 'retry' channel for post:probe: probe-cache-offline/flush, probe-cache-offline/warm",
    'manifest-runtime channel collisions refuse during package load instead of waiting for a live target capability probe'
);

$m = $runtimeManifest;
$m['engine_features'] = ['spec-window/v1'];
refuse_probe(
    $m,
    "requires engine feature 'manifest-provider-runtime/v1'",
    'a contracts map without the runtime feature refuses by feature name instead of loading as inert metadata'
);
$m = $runtimeManifest;
$m['providers'][0]['source'] = 'plugin';
refuse_probe(
    $m,
    'contracts is only valid for source: manifest',
    'plugin-sourced providers keep independent identity/capability negotiation and cannot borrow manifest runtime metadata'
);
$m = $runtimeManifest;
$m['providers'][0]['capabilities'][] = 'warm';
refuse_probe(
    $m,
    'contracts keys must exactly follow capabilities',
    'every advertised capability needs exactly one declarative runtime contract in the same order'
);
$m = $runtimeManifest;
$m['providers'][0]['contracts']['flush']['timeout_seconds'] = 0;
refuse_probe(
    $m,
    'timeout_seconds must be a positive integer',
    'declarative contracts use the live negotiation grammar at manifest load, not a weaker duplicate validator'
);
$m = $runtimeManifest;
$m['providers'][0]['contracts']['flush']['scoped']['invoke_after'] = 'trust-me';
refuse_probe(
    $m,
    'invoke_after must be "receipt" or "reconcile"',
    'the scoped-after strategy is a closed engine vocabulary rather than an adapter-authored callback name'
);

$runtime = new class($runtimeDeclaration) extends \WPrism\ManifestProviderRuntime {
    protected function invoke_flush(array $args): array {
        return ['before' => ['state' => 'before'], 'after' => ['state' => 'invoke'], 'verified' => true];
    }

    protected function reconcile_flush(array $args): array {
        return ['state' => 'reconciled'];
    }
};
check(
    $runtime->identity() === [
        'id' => 'probe-cache-offline',
        'plugin' => 'probe/probe.php',
        'version' => '1.0.0',
    ] && $runtime->capabilities() === ['flush' => $contract],
    'core derives manifest-provider identity and advertised capabilities from the validated declaration'
);
$runtimeOperation = ['operation_id' => 'probe-operation'];
$runtimeReceipt = $runtime->invoke_scoped('flush', [], $runtimeOperation);
check(
    $runtimeReceipt === [
        'operation' => $runtimeOperation,
        'before' => ['state' => 'before'],
        'after' => ['state' => 'reconciled'],
        'verified' => true,
    ],
    'core owns capability dispatch, scoped receipt construction, and the declared fresh-reconcile strategy'
);
expect_throw(
    static fn() => new class($runtimeDeclaration) extends \WPrism\ManifestProviderRuntime {},
    'requires protected invoke_flush(array): array',
    'a missing behavior method is a construction-time packaging failure, before any provider mutation can run'
);

// ======================================================================
echo "\n== providers: the optional `requires` contract, closed on every axis (issue #3317) ==\n";

// A well-formed requires block loads and travels to negotiation intact.
$m = probe_manifest();
$requires = [
    'functions' => ['wc_get_container', '\\WC\\Package\\Container'],
    'classes' => ['WC_Data_Store'],
    'plugin_version' => ['min' => '8.1.0', 'max' => '9.0.0'],
    'wordpress_version' => ['min' => '6.0', 'max' => '7.0'],
    'php_version' => ['min' => '8.1', 'max' => '9.0'],
];
$m['providers'][0]['requires'] = $requires;
$policy = load_probe($m);
check(
    ($policy->provider_declarations()['probe-cache-offline']['requires'] ?? null) === $requires,
    'a well-formed requires block loads and reaches provider_declarations() intact, so negotiation sees the same bytes'
);

// requires is an OBJECT, and a non-empty one — the envelope-without-payload
// refusal, the same posture an empty capability `context` gets.
$m = probe_manifest();
$m['providers'][0]['requires'] = [];
refuse_probe($m, 'must be a non-empty object', 'an empty requires object is refused rather than meaning "no requirements"');
$m = probe_manifest();
$m['providers'][0]['requires'] = ['functions'];
refuse_probe($m, 'must be a non-empty object', 'a list where a requires object belongs is refused');

// Closed key set.
$m = probe_manifest();
$m['providers'][0]['requires'] = ['plugins' => ['x']];
refuse_probe($m, 'names unknown requirement(s) plugins', 'an unknown requirement key is refused, naming it — a claim the engine would silently ignore');

// functions/classes: non-empty list of bounded symbol names, no dupes.
$m = probe_manifest();
$m['providers'][0]['requires'] = ['functions' => 'wc_get_container'];
refuse_probe($m, '.functions must be a non-empty list', 'a scalar functions requirement is refused');
$m = probe_manifest();
$m['providers'][0]['requires'] = ['classes' => []];
refuse_probe($m, '.classes must be a non-empty list', 'an empty classes list is refused');
$m = probe_manifest();
$m['providers'][0]['requires'] = ['functions' => ['wc get container']];
refuse_probe($m, '.functions must contain only PHP symbol names', 'a function name outside the bounded charset is refused before it can land in an operator-facing string');
$m = probe_manifest();
$m['providers'][0]['requires'] = ['classes' => ['WC_Data_Store', 'WC_Data_Store']];
refuse_probe($m, ".classes repeats 'WC_Data_Store'", 'a repeated symbol name is refused');
// The charset is single-line-bounded (`$…/D`): a trailing newline must not
// slip a symbol name past the screen into the operator-facing found/expected
// string. Without the D modifier bare `$` would admit one trailing newline.
$m = probe_manifest();
$m['providers'][0]['requires'] = ['functions' => ["wc_get_container\n"]];
refuse_probe($m, '.functions must contain only PHP symbol names', 'a symbol name with a trailing newline is refused (the charset is single-line-bounded)');

// version bounds: the shared {min,max} predicate, min inclusive, max exclusive.
$m = probe_manifest();
$m['providers'][0]['requires'] = ['php_version' => ['min' => '9.0', 'max' => '8.0']];
refuse_probe($m, 'malformed range', 'a php_version window with min >= max is refused through the shared range predicate');
$m = probe_manifest();
$m['providers'][0]['requires'] = ['plugin_version' => ['min' => '1.0.0']];
refuse_probe($m, 'malformed range', 'a plugin_version window missing its max is refused');
$m = probe_manifest();
$m['providers'][0]['requires'] = ['wordpress_version' => ['min' => '', 'max' => '7.0']];
refuse_probe($m, 'malformed range', 'a wordpress_version window with an empty bound is refused, not read as unbounded');

// Two PINNED manifests claiming one id is the load-order hazard: a provider id
// resolves to concrete executable code, so which code runs may not depend on
// pin order. Same posture as a conflicting plugin/theme adapter claim.
$second = probe_manifest(['name' => 'second']);
$second['plugin'] = 'second/second.php';
$second['providers'][0]['plugin'] = 'second/second.php';
fresh_manifests_dir(['probe' => probe_manifest(), 'second' => $second]);
expect_throw(
    fn() => fixture_policy_load(null, ['probe', 'second']),
    'runtime provider probe-cache-offline is declared by both probe and second',
    'two pinned manifests declaring the same provider id are refused (load-order-independent)'
);

// ======================================================================
echo "\n== effects_inventory(): the rebuild phase now names closed action identities ==\n";

$policy = load_probe(probe_manifest());
$rows = array_values(array_filter(
    $policy->effects_inventory(),
    static fn(array $row): bool => $row['manifest'] === 'probe'
));
$sources = array_values(array_unique(array_column($rows, 'source')));
sort($sources, SORT_STRING);
check(
    $sources === ['native:transient.delete', 'probe/probe.php', 'provider:probe-cache-offline/flush'],
    "the inventory's rebuild sources are the actions' closed identities (native:<action>, provider:<id>/<capability>) — never a command string"
);
$nativeRow = array_values(array_filter($rows, static fn(array $r): bool => $r['source'] === 'native:transient.delete'))[0] ?? [];
check(
    ($nativeRow['phase'] ?? null) === 'rebuild' && ($nativeRow['effect']['id'] ?? null) === 'probe-native-effect',
    "a declared action effect keeps phase 'rebuild' and its declared effect verbatim"
);
$fallback = array_values(array_filter($rows, static fn(array $r): bool => $r['source'] === 'provider:probe-cache-offline/flush'))[0] ?? [];
check(
    $fallback === [
        'manifest' => 'probe',
        'phase' => 'rebuild',
        'source' => 'provider:probe-cache-offline/flush',
        'effect' => [
            'id' => 'probe-action-1',
            'kind' => 'external',
            'mode' => 'irreversible',
            'selector' => [
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'provider:probe-cache-offline/flush',
            ],
        ],
    ],
    'an action with NO declared effects becomes one explicit irreversible row keyed <manifest>-action-<index>, never a disappearing obligation'
);
check(
    Policy::action_source(['kind' => 'native', 'action' => 'transient.delete'], 7) === 'native:transient.delete'
        && Policy::action_source(['kind' => 'provider', 'provider' => 'p', 'capability' => 'c'], 7) === 'provider:p/c'
        && Policy::action_source(['kind' => 'unknown'], 7) === 'actions[7]',
    'action_source() is the single spelling shared by the inventory and Apply receipts, with a positional fallback for a declaration that never validated'
);

// ======================================================================
echo "\n== the shipped adapters: every committed manifest loads clean and declares no retired channel ==\n";

// The real manifests are copied into a scratch repo topology WITHOUT dispositions.json,
// which is what makes this check about the manifest GRAMMAR rather than about
// the reviewed support boundary. A disposition names entity/field sections,
// operations, and unsupported surfaces per manifest, and its coverage check is
// one-for-one; loading through it here would make this file fail for a review
// decision it does not test. regress_manifest_dispositions.php owns that.
// Runtime files are copied below only so the legacy-shaped grammar fixture can
// close one explicit library. Their self-owned dependency resolution is
// separately proved from the committed source-package topology: arbitrary
// copied directories are not a supported adapter runtime layout.
$shippedRoot = sys_get_temp_dir() . '/wprism_regress_actions_providers_shipped_' . bin2hex(random_bytes(4));
$shipped = $shippedRoot . '/manifests';
mkdir($shipped . '/providers', 0777, true);
mkdir($shippedRoot . '/agent/src/Kernel', 0777, true);
copy(
    $root . '/agent/src/Kernel/WpCliChildProcess.php',
    $shippedRoot . '/agent/src/Kernel/WpCliChildProcess.php'
);
$copied = [];
$sourceLibrary = AdapterLibrary::fromSourceTree($root);
foreach ($sourceLibrary->packages() as $package) {
    $name = $package->name();
    copy($package->manifestPath(), "$shipped/$name.json");
    $copied[] = $name;
    $manifest = Canon::decode(Canon::read_file($package->manifestPath()));
    foreach ((array) ($manifest['providers'] ?? []) as $provider) {
        $id = is_array($provider) ? ($provider['id'] ?? null) : null;
        if (is_string($id) && ($provider['source'] ?? null) === 'manifest') {
            copy($package->providerPath($id), "$shipped/providers/$id.php");
        }
    }
}
$wpCliProviderFiles = [
    'elementor' => ['elementor-css.php', '\\WPrism\\Providers\\ElementorCss'],
    'ninja-forms' => ['ninja-forms-form-cache.php', '\\WPrism\\Providers\\NinjaFormsFormCache'],
    'yoast' => ['yoast-index.php', '\\WPrism\\Providers\\YoastIndex'],
];
foreach ($wpCliProviderFiles as $packageName => [$providerFile, $providerClass]) {
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $pipes = [];
    $process = proc_open(
        [
            PHP_BINARY,
            '-d',
            'display_errors=stderr',
            '-r',
            'require $argv[1]; require $argv[2]; if (!class_exists("WPrism\\\\WpCliChildProcess", false) || !class_exists($argv[3], false)) { exit(1); }',
            $root . '/agent/src/Adapter/ManifestProviderRuntime.php',
            $root . '/adapter-packages/' . $packageName . '/package/runtime/providers/' . $providerFile,
            $providerClass,
        ],
        $descriptors,
        $pipes
    );
    if (!is_resource($process)) {
        check(false, "$providerFile starts in an isolated partial-load process");
        continue;
    }
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);
    check(
        $exitCode === 0 && $stdout === '' && $stderr === '',
        "$providerFile owns its WpCliChildProcess dependency in an isolated partial-load context"
            . ($stderr === '' ? '' : " (stderr: $stderr)")
    );
}
foreach ($sourceLibrary->packages() as $package) {
    $interpreter = $package->interpreterPath();
    if ($interpreter !== null) {
        if (!is_dir("$shipped/interpreters")) {
            mkdir("$shipped/interpreters", 0777, true);
        }
        copy($interpreter, "$shipped/interpreters/" . basename($interpreter));
    }
    foreach ($package->shippablePaths() as $runtime) {
        if (str_contains($runtime, '/runtime/regenerators/')) {
            if (!is_dir("$shipped/regenerators")) {
                mkdir("$shipped/regenerators", 0777, true);
            }
            copy($runtime, "$shipped/regenerators/" . basename($runtime));
        }
    }
}
register_shutdown_function(static function () use ($shippedRoot): void {
    manifest_fixture_remove_tree($shippedRoot);
});
select_fixture_library($shipped);
check(count($copied) >= 10, 'the shipped manifest set was copied into a scratch dir for real-byte validation (' . count($copied) . ' manifests)');
$shippedPolicies = [];
foreach ($copied as $name) {
    try {
        $shippedPolicies[$name] = fixture_policy_load(null, [$name]);
        check(true, "shipped manifest '$name' loads clean through the real Policy validators");
    } catch (\Throwable $t) {
        check(false, "shipped manifest '$name' loads clean through the real Policy validators ({$t->getMessage()})");
    }
}
$withRebuilders = [];
foreach ($copied as $name) {
    $decoded = json_decode((string) file_get_contents("$shipped/$name.json"), true);
    if (is_array($decoded) && array_key_exists('rebuilders', $decoded)) {
        $withRebuilders[] = $name;
    }
}
check($withRebuilders === [], 'no shipped manifest still declares `rebuilders` (found: ' . implode(', ', $withRebuilders) . ')');

$woo = $shippedPolicies['woocommerce'] ?? null;
check($woo !== null, 'the WooCommerce manifest is among the loadable shipped set');
if ($woo !== null) {
    $wooSources = array_map(
        static fn(array $row): string => Policy::action_source($row, (int) $row['index']),
        $woo->actions()
    );
    check(
        $wooSources === [
            'native:transient.delete',
            'provider:woocommerce-cache/invalidate_cache_groups',
            'provider:woocommerce-hierarchy-lookups/rebuild_hierarchy_lookups',
            'provider:woocommerce-hierarchy-lookups/rebuild_hierarchy_lookups',
            'provider:woocommerce-fulfillment-prerequisites/verify_fulfillment_prerequisites',
            'provider:woocommerce-scheduler-settings/reconcile_analytics_import_schedule',
            'provider:woocommerce-scheduler-settings/reconcile_stock_notification_retention',
            'provider:woocommerce-product-lookups/rebuild_product_lookups',
            'provider:woocommerce-product-lookups/cleanup_product_deletions',
            'native:rewrite.flush',
            'provider:woocommerce-hierarchy-lookups/rebuild_product_permalink_routes',
            'provider:woocommerce-lifecycle-migrations/settle_lifecycle_migrations',
        ],
        'WooCommerce declares the exact transient, hierarchy/route, fulfillment, scheduler, product lookup, product-permalink, and review-route actions in order'
    );
    // issue #3342 added the second declaration by MIGRATING a dispatch rather than
    // by adding a repair: the product lookup rebuild reached the same adapter
    // code through post_types.<type>.regen_dependency before this, so the
    // absence of that key is half of what this check is about.
    check(
        $woo->regen_batch_post_types() === []
            && $woo->regen_dependency('product') === null
            && $woo->regen_dependency('product_variation') === null,
        'and no shipped Woo post type still claims the batch regenerator channel the second capability replaced'
    );
    foreach ([
        'woocommerce-cache' => ['version' => '1.0.0', 'capabilities' => ['invalidate_cache_groups']],
        'woocommerce-hierarchy-lookups' => ['version' => '2.0.0', 'capabilities' => ['rebuild_hierarchy_lookups', 'rebuild_product_permalink_routes']],
        'woocommerce-fulfillment-prerequisites' => ['version' => '1.0.0', 'capabilities' => ['verify_fulfillment_prerequisites']],
        'woocommerce-scheduler-settings' => ['version' => '1.0.0', 'capabilities' => ['reconcile_analytics_import_schedule', 'reconcile_stock_notification_retention']],
        'woocommerce-product-lookups' => [
            'version' => '3.1.0',
            'capabilities' => ['cleanup_product_deletions', 'rebuild_product_lookups'],
        ],
        'woocommerce-lifecycle-migrations' => ['version' => '1.0.0', 'capabilities' => ['settle_lifecycle_migrations']],
    ] as $wooProviderId => $wooContract) {
        $wooDeclaration = $woo->provider_declarations()[$wooProviderId] ?? [];
        check(
            ($wooDeclaration['source'] ?? null) === 'manifest'
                && ($wooDeclaration['plugin'] ?? null) === 'woocommerce/woocommerce.php'
                && ($wooDeclaration['version'] ?? null) === $wooContract['version']
                && ($wooDeclaration['capabilities'] ?? null) === $wooContract['capabilities'],
            "the WooCommerce '$wooProviderId' declaration is manifest-shipped code owned by the version-pinned plugin"
        );
    }
}

// The sandbox agency fixture is the shipped example of the OTHER source: a
// custom plugin advertising its own provider, in a manifest that pins no
// plugin/version_range at all. Negotiation skips the range comparison for such
// a manifest by design, so the null range has to survive load as a null.
$agency = $shippedPolicies['wprism-agency-cpt'] ?? null;
check($agency !== null, 'the plugin-sourced provider fixture manifest is among the loadable shipped set');
if ($agency !== null) {
    $agencyDeclaration = $agency->provider_declarations()['wprism-agency-index'] ?? [];
    check(
        ($agencyDeclaration['source'] ?? null) === 'plugin'
            && ($agencyDeclaration['plugin'] ?? null) === 'wprism-agency-cpt/wprism-agency-cpt.php'
            && array_key_exists('version_range', $agencyDeclaration)
            && $agencyDeclaration['version_range'] === null,
        'a custom plugin may supply its own provider from a manifest with no version_range (the range check is then skipped, not faked)'
    );
    $agencySources = array_map(
        static fn(array $row): string => Policy::action_source($row, (int) $row['index']),
        $agency->actions()
    );
    check(
        $agencySources === ['provider:wprism-agency-index/rebuild_project_index', 'native:transient.delete'],
        'the fixture declares both kinds side by side: its own plugin capability and the engine-owned transient action'
    );
    check(
        $agency->actions_for(['post:project']) === $agency->actions()
            && $agency->actions_for(['post:page']) === [],
        'both fixture declarations are scoped to the exact post:project surface and fire for nothing else'
    );
}

echo "\n== the shipped provider files: real classes, real identities, real capability declarations ==\n";

$declareCapability = new \ReflectionMethod(Providers::class, 'validate_capability_declaration');
$providerCount = 0;
foreach ($shippedPolicies as $name => $shippedPolicy) {
    foreach ($shippedPolicy->provider_declarations() as $id => $declaration) {
        if (($declaration['source'] ?? null) !== 'manifest') {
            continue;
        }
        $providerCount++;
        $file = "$shipped/providers/$id.php";
        check(is_file($file), "manifest '$name' declares provider '$id' and its package runtime ships $id.php");
        if (!is_file($file)) {
            continue;
        }
        require_once $file;
        // The exact derivation Providers::manifest_provider() uses; asserted
        // here rather than hardcoded so a provider file whose class name drifts
        // from its declared id is caught offline instead of at negotiation.
        $class = '\\WPrism\\Providers\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $id)));
        check(class_exists($class), "provider '$id' defines $class");
        if (!class_exists($class)) {
            continue;
        }
        // Constructing offline must not reach WordPress: negotiation
        // instantiates before it knows whether the environment can answer.
        // Contract-migrated providers receive their declaration through the
        // core runtime; legacy manifest providers retain the Policy constructor
        // until their product semantics are migrated independently.
        $instance = array_key_exists('contracts', $declaration)
            ? new $class($declaration)
            : new $class($shippedPolicy);
        $identity = $instance->identity();
        ksort($identity, SORT_STRING);
        check(
            $identity === [
                'id' => $id,
                'plugin' => (string) $declaration['plugin'],
                'version' => (string) $declaration['version'],
            ],
            "provider '$id' identity() matches its manifest declaration exactly"
        );
        $advertised = $instance->capabilities();
        $advertisedNames = array_keys($advertised);
        sort($advertisedNames, SORT_STRING);
        $declared = (array) $declaration['capabilities'];
        sort($declared, SORT_STRING);
        check(
            $advertisedNames === $declared,
            "provider '$id' advertises exactly the capabilities its declaration lists"
        );
        foreach ($advertised as $capability => $decl) {
            try {
                $declareCapability->invoke(null, $decl, "provider '$id' capability '$capability'");
                check(true, "provider '$id' capability '$capability' is a well-formed declaration");
            } catch (\Throwable $t) {
                check(false, "provider '$id' capability '$capability' is a well-formed declaration ({$t->getMessage()})");
            }
            check(
                ($decl['idempotent'] ?? null) === true,
                "provider '$id' capability '$capability' is idempotent (apply's retry re-fires the rebuild pass)"
            );
        }
    }
}
check($providerCount === 15, "all fifteen shipped manifest-sourced providers were exercised (found $providerCount)");

// ======================================================================
echo "\n== the two identity implementations agree over the REAL shipped library ==\n";

// The row builder feeds manifest_hash(), and hashing one row alone IS the
// per-adapter digest. That used to be a claim about TWO implementations:
// CapabilityRegistry::adapter_digest() re-walked the identical interpreter /
// provider / regenerator folds because it had to hash a manifest without a
// compiled repository, and every check below paired the two so a key added to
// one and not the other failed here. The mirror is gone — resolved_adapters()
// now hashes the row directly — so what is pinned instead is that the REPORTED
// digest is exactly its own row's bytes, over the real shipped library,
// including the manifest that ships a regenerator. A row builder that started
// folding something the reported digest did not (or the reverse) still fails
// here; there is simply one walk to catch it in.
$manifestRows = new \ReflectionMethod(RepositoryCompiler::class, 'manifest_rows');
$rowDigest = static fn(array $row): string => hash('sha256', Canon::encode($row));
$declaredRegenerators = [];
foreach ($shippedPolicies as $name => $shippedPolicy) {
    $row = $manifestRows->invoke(null, $shippedPolicy)[0];
    check(
        $rowDigest($row) === RepositoryCompiler::resolved_adapters($shippedPolicy)[0]['digest'],
        "the reported digest for shipped '$name' is exactly its own manifest_rows() identity row, hashed"
    );
    check(
        $row['disposition'] === $shippedPolicy->manifest_disposition($name),
        "and that row binds '$name''s reviewed disposition, so a change of review moves the adapter's identity"
    );
    foreach ($row['regenerators'] ?? [] as $entry) {
        $declaredRegenerators[$name][] = $entry;
    }
}
// The audit this issue turns on: issue #3342 retired the WooCommerce lookup
// regenerator onto the provider contract, so exactly one shipped manifest
// still declares one. Asserted rather than assumed, so a manifest that adds or
// drops a regenerator declaration has to come back through this file.
check(
    array_keys($declaredRegenerators) === ['the-events-calendar'],
    'exactly one shipped manifest still declares a regenerator (found: '
    . (implode(', ', array_keys($declaredRegenerators)) ?: 'none') . ')'
);
check(
    ($declaredRegenerators['the-events-calendar'] ?? null) === [[
        'name' => 'the-events-calendar',
        'sha256' => hash_file('sha256', "$shipped/regenerators/the-events-calendar.php"),
    ]],
    "and the shipped regenerator file's real bytes are what its identity row carries"
);
$tecPolicy = $shippedPolicies['the-events-calendar'];
$tecBatch = $tecPolicy->regen_batch('tribe_events');
$tecRegenerator = $tecPolicy->regenerators()['the-events-calendar'] ?? null;
$tecRegeneratorSource = (string) file_get_contents("$shipped/regenerators/the-events-calendar.php");
check(
    $tecBatch === ['enabled' => true, 'always_on_write' => true],
    'TEC opts into always-on-write refresh because an existing occurrence row can still carry stale dates'
);
check(
    $tecRegenerator !== null && method_exists($tecRegenerator, 'regenerate_batch'),
    'TEC implements the batch callable selected by its refresh declaration'
);
check(
    str_contains($tecRegeneratorSource, 'private const REQUIRED_EVENT_META_KEYS')
        && str_contains($tecRegeneratorSource, "'_EventStartDate'")
        && str_contains($tecRegeneratorSource, "'_EventEndDate'")
        && str_contains($tecRegeneratorSource, 'expectedEventData($eventData, $localId)')
        && str_contains($tecRegeneratorSource, 'SELECT occurrence_id, event_id, post_id, start_date, end_date')
        && str_contains($tecRegeneratorSource, 'ORDER BY occurrence_id LIMIT 3')
        && str_contains($tecRegeneratorSource, 'checkedDriverRows(')
        && str_contains($tecRegeneratorSource, 'rowMismatches($occurrenceRows, $expectedOccurrence)'),
    'TEC verifies bounded exact occurrence values, driver shape, and cardinality after repair'
);

echo "\n== purely declarative adapters keep the pre-change behavior exactly ==\n";

foreach (['acf', 'contact-form-7'] as $name) {
    $declarative = $shippedPolicies[$name] ?? null;
    check($declarative !== null, "purely declarative manifest '$name' loads");
    if ($declarative === null) {
        continue;
    }
    check(
        $declarative->actions() === [] && $declarative->provider_declarations() === [],
        "'$name' declares no actions and no providers — both sections are genuinely optional"
    );
    check(
        $declarative->actions_for(['post:page', 'option:blogname']) === [],
        "'$name' selects nothing for any surface set, so no provider code is ever reached for it"
    );
    // A Policy accessor unrelated to the new sections still answers, proving
    // the sections were added beside the existing contract, not through it.
    check(
        $declarative->effects_inventory() !== [],
        "'$name' still projects its engine-owned and lifecycle effects unchanged"
    );
}

$corePolicy = $shippedPolicies['core'];
$coreActions = $corePolicy->actions_for(['option:permalink_structure']);
check(
    count($coreActions) === 1
        && ($coreActions[0]['kind'] ?? null) === 'native'
        && ($coreActions[0]['action'] ?? null) === 'rewrite.flush'
        && ($coreActions[0]['args'] ?? null) === [],
    'core selects its argument-free native rewrite flush only for the authored permalink surface'
);
check(
    $corePolicy->provider_declarations() === []
        && $corePolicy->actions_for(['option:blogname']) === [],
    'core needs no plugin provider and unrelated authored options select no action'
);

// ======================================================================
echo "\n== digest binding: manifest-shipped provider bytes are part of the adapter's identity ==\n";

$digestManifest = [
    'name' => 'digest',
    'spec_version' => WPRISM_SPEC_VERSION,
    'plugin' => 'probe/probe.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    'providers' => [[
        'id' => 'digest-probe',
        'version' => '1.0.0',
        'source' => 'manifest',
        'plugin' => 'probe/probe.php',
        'capabilities' => ['flush'],
    ]],
    'actions' => [[
        'kind' => 'provider',
        'provider' => 'digest-probe',
        'capability' => 'flush',
        'args' => [],
    ]],
];
$providerSource = "<?php\nnamespace WPrism\\Providers;\nfinal class DigestProbe {\n    public function __construct(\\WPrism\\Policy \$policy) {}\n}\n";
$digestDir = fresh_manifests_dir(['digest' => $digestManifest], ['digest-probe' => $providerSource]);
$digestPolicy = fixture_policy_load(null, ['digest']);
$adapterBefore = RepositoryCompiler::resolved_adapters($digestPolicy)[0]['digest'];
$rowBefore = $rowDigest($manifestRows->invoke(null, $digestPolicy)[0]);
$combinedBefore = RepositoryCompiler::manifest_hash($digestPolicy);
check(
    $adapterBefore === $rowBefore,
    'the reported per-adapter digest is its identity row hashed — one derivation, not a compiler answer checked '
    . 'against a registry answer'
);

// Only the provider file changes; the manifest bytes are untouched.
file_put_contents("$digestDir/providers/digest-probe.php", $providerSource . "// drift\n");
$digestPolicyAfter = fixture_policy_load(null, ['digest']);
check(
    $digestPolicyAfter->manifests[0] === $digestPolicy->manifests[0],
    'the manifest bytes are byte-identical across the two loads — only the provider file moved'
);
$adapterAfter = RepositoryCompiler::resolved_adapters($digestPolicyAfter)[0]['digest'];
$rowAfter = $rowDigest($manifestRows->invoke(null, $digestPolicyAfter)[0]);
check(
    $adapterAfter !== $adapterBefore,
    'changing manifest-shipped provider file BYTES changes the per-adapter digest — a changed provider is a changed adapter, not invisible drift'
);
check(
    $rowAfter !== $rowBefore && $adapterAfter === $rowAfter,
    'and the identity row moved with it — the reported digest cannot drift away from the bytes it addresses'
);
// The fold is asserted on the row itself and not only through the digest it
// feeds ($manifestRows is the same reflection handle the real-library pin above
// uses): a digest that moved for the wrong reason would satisfy the check
// above and fail this one.
$row = $manifestRows->invoke(null, $digestPolicyAfter)[0];
check(
    ($row['providers'] ?? null) === [[
        'id' => 'digest-probe',
        'sha256' => hash_file('sha256', "$digestDir/providers/digest-probe.php"),
    ]],
    'RepositoryCompiler::manifest_rows() carries the manifest-sourced provider file hash in the identity row itself'
);
check(
    RepositoryCompiler::manifest_hash($digestPolicyAfter) !== $combinedBefore,
    "the combined manifest_hash() moves too — the per-adapter digests are the same bytes exposed per adapter, not a second notion of identity"
);
unlink("$digestDir/providers/digest-probe.php");
$missingRow = $manifestRows->invoke(null, fixture_policy_load(null, ['digest']))[0];
check(
    ($missingRow['providers'] ?? null) === [['id' => 'digest-probe', 'sha256' => null]],
    'a missing provider file hashes as null in the identity row rather than silently vanishing from it'
);
file_put_contents("$digestDir/providers/digest-probe.php", $providerSource . "// drift\n");
$adapterRepeat = RepositoryCompiler::resolved_adapters(fixture_policy_load(null, ['digest']))[0]['digest'];
check($adapterRepeat === $adapterAfter, 'the digest is deterministic — identical provider bytes re-hash identically');

// A plugin-sourced provider's trust anchor is the installed plugin, not a file
// in the manifests tree, so its bytes are deliberately NOT folded in. Proven
// by planting a same-named file and requiring the digest to stay put.
$pluginSourced = $digestManifest;
$pluginSourced['name'] = 'digestplugin';
$pluginSourced['providers'][0]['source'] = 'plugin';
$pluginDir = fresh_manifests_dir(['digestplugin' => $pluginSourced]);
$pluginPolicy = fixture_policy_load(null, ['digestplugin']);
$pluginBefore = RepositoryCompiler::resolved_adapters($pluginPolicy)[0]['digest'];
file_put_contents("$pluginDir/providers/digest-probe.php", $providerSource);
check(
    RepositoryCompiler::resolved_adapters(fixture_policy_load(null, ['digestplugin']))[0]['digest'] === $pluginBefore,
    'a plugin-sourced provider is NOT file-hashed — its identity anchor is the installed plugin the code half already version-bounds'
);

// ======================================================================
echo "\n== digest binding: manifest-shipped regenerator bytes are part of the adapter's identity (issue #3360) ==\n";

// Same trust boundary as the interpreter and provider entries above:
// executable code that ships, versions, and pins with its manifest. Until
// issue #3360 nothing hashed it, so two regenerator implementations could share
// one manifest revision's identity — a certified claim could not tell them
// apart. The fixture declares one regenerator on TWO post types (the entry
// must appear once) plus a second on a third (count and order are load-
// bearing: the row is a list, so Canon::encode() preserves order rather than
// normalizing it), and a fourth post type declares none.
$regenSource = static fn(string $class): string => "<?php\nnamespace WPrism\\Regenerators;\n"
    . "final class $class {\n    public function __construct(\\WPrism\\Policy \$policy) {}\n"
    . "    public function regenerate(int \$localId): void {}\n}\n";
$regenDependency = static fn(string $name): array => [
    'regen_dependency' => [
        'regenerator' => $name,
        'verify' => ['table' => 'probe_rows', 'column' => 'post_id'],
    ],
];
// Declaration order is deliberately NOT name order here: `second-regen` is
// declared first and must still sort second.
$regenManifest = [
    'name' => 'digestregen',
    'spec_version' => WPRISM_SPEC_VERSION,
    'plugin' => 'probe/probe.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    'post_types' => [
        'probe_gamma' => $regenDependency('second-regen'),
        'probe_alpha' => $regenDependency('digest-regen'),
        'probe_beta' => $regenDependency('digest-regen'),
        'probe_delta' => ['class' => 'authored'],
    ],
];
// A neighbour pinned in the same load that declares no regenerator at all:
// the sensitivity check below has to show the perturbation moves the DECLARING
// manifest's digest and nothing else's.
$quietManifest = [
    'name' => 'digestquiet',
    'spec_version' => WPRISM_SPEC_VERSION,
    'post_types' => ['probe_quiet' => ['class' => 'authored']],
];
$regenDir = fresh_manifests_dir(
    ['digestregen' => $regenManifest, 'digestquiet' => $quietManifest],
    [],
    ['digest-regen' => $regenSource('DigestRegen'), 'second-regen' => $regenSource('SecondRegen')]
);
$regenPins = ['digestregen', 'digestquiet'];
$regenPolicy = fixture_policy_load(null, $regenPins);
$regenRow = $manifestRows->invoke(null, $regenPolicy)[0];
check(
    ($regenRow['regenerators'] ?? null) === [
        ['name' => 'digest-regen', 'sha256' => hash_file('sha256', "$regenDir/regenerators/digest-regen.php")],
        ['name' => 'second-regen', 'sha256' => hash_file('sha256', "$regenDir/regenerators/second-regen.php")],
    ],
    'manifest_rows() carries every distinct declared regenerator ONCE, sorted by name, with its real file bytes'
);
check(
    $rowDigest($regenRow) === RepositoryCompiler::resolved_adapters($regenPolicy)[0]['digest'],
    'and the reported digest is exactly that row hashed, regenerator fold included'
);
$regenBefore = RepositoryCompiler::resolved_adapters($regenPolicy)[0]['digest'];
$quietBefore = RepositoryCompiler::resolved_adapters($regenPolicy)[1]['digest'];
$regenCombinedBefore = RepositoryCompiler::manifest_hash($regenPolicy);
check(
    ($manifestRows->invoke(null, $regenPolicy)[1]['regenerators'] ?? 'absent') === 'absent',
    'a manifest declaring no regenerator carries no regenerators key at all — the row shape of every existing adapter is untouched'
);

// Why the entries sort by name instead of keeping discovery order, the way
// providers[] keeps its authored order: providers[] is a JSON array, so its
// order IS canonical content, while these names come off a JSON OBJECT whose
// key order Canon::encode() normalizes away — including inside the `manifest`
// slot of this very row. Two manifests that differ only by post_types{} key
// order are the same canonical manifest, so they must be the same adapter.
$reshuffled = $regenManifest;
$reshuffled['post_types'] = [
    'probe_alpha' => $regenDependency('digest-regen'),
    'probe_delta' => ['class' => 'authored'],
    'probe_beta' => $regenDependency('digest-regen'),
    'probe_gamma' => $regenDependency('second-regen'),
];
$reshuffledDir = fresh_manifests_dir(
    ['digestregen' => $reshuffled],
    [],
    ['digest-regen' => $regenSource('DigestRegen'), 'second-regen' => $regenSource('SecondRegen')]
);
$reshuffledPolicy = fixture_policy_load(null, ['digestregen']);
check(
    $reshuffledPolicy->manifests[0] !== $regenPolicy->manifests[0]
        && Canon::encode($reshuffledPolicy->manifests[0]) === Canon::encode($regenPolicy->manifests[0]),
    'the reshuffled fixture really does differ only in post_types{} key order (same canonical bytes, different PHP array)'
);
check(
    RepositoryCompiler::resolved_adapters($reshuffledPolicy)[0]['digest'] === $regenBefore
        && $rowDigest($manifestRows->invoke(null, $reshuffledPolicy)[0]) === $regenBefore,
    'and its digest is unchanged, row and reported alike — a no-op key reshuffle must never move a certified adapter'
);
select_fixture_library($regenDir);

// Only the regenerator file changes; the manifest bytes are untouched.
file_put_contents("$regenDir/regenerators/digest-regen.php", $regenSource('DigestRegen') . "// drift\n");
$regenPolicyAfter = fixture_policy_load(null, $regenPins);
check(
    $regenPolicyAfter->manifests[0] === $regenPolicy->manifests[0],
    'the manifest bytes are byte-identical across the two loads — only the regenerator file moved'
);
$regenAfter = RepositoryCompiler::resolved_adapters($regenPolicyAfter)[0]['digest'];
check(
    $regenAfter !== $regenBefore,
    'changing manifest-shipped regenerator file BYTES changes the per-adapter digest — a changed regenerator is a changed adapter, not invisible drift'
);
check(
    $rowDigest($manifestRows->invoke(null, $regenPolicyAfter)[0]) === $regenAfter
        && $regenAfter !== $regenBefore,
    'and the row moved with it — the reported digest cannot certify bytes the identity row no longer recognizes'
);
check(
    RepositoryCompiler::resolved_adapters($regenPolicyAfter)[1]['digest'] === $quietBefore,
    'and ONLY the declaring manifest moves — a pinned neighbour that declares no regenerator keeps its digest'
);
check(
    RepositoryCompiler::manifest_hash($regenPolicyAfter) !== $regenCombinedBefore,
    'the combined manifest_hash() moves too — one notion of identity, exposed both per-adapter and combined'
);
check(
    RepositoryCompiler::resolved_adapters(fixture_policy_load(null, $regenPins))[0]['digest'] === $regenAfter,
    'the digest is deterministic — identical regenerator bytes re-hash identically across two loads'
);

// A missing file is Policy::regenerators()' loud refusal to make (`wprism
// manifest-validate` drives it offline, before any apply). This layer records
// identity, so the entry must stay present with a null hash — a silent skip
// would let deleting the file leave the adapter's identity unmoved.
unlink("$regenDir/regenerators/second-regen.php");
$regenMissingRow = $manifestRows->invoke(null, fixture_policy_load(null, $regenPins))[0];
check(
    ($regenMissingRow['regenerators'] ?? null) === [
        ['name' => 'digest-regen', 'sha256' => hash_file('sha256', "$regenDir/regenerators/digest-regen.php")],
        ['name' => 'second-regen', 'sha256' => null],
    ],
    'a missing regenerator file hashes as null in the identity row rather than silently vanishing from it'
);
check(
    $rowDigest($regenMissingRow) !== $regenAfter
        && $rowDigest($regenMissingRow)
            === RepositoryCompiler::resolved_adapters(fixture_policy_load(null, $regenPins))[0]['digest'],
    'deleting the file therefore MOVES the digest, and the reported digest moves with the row'
);

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
