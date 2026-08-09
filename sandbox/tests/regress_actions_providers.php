<?php
/**
 * Offline (no docker, no WordPress bootstrap) regression harness for DUO-3338's
 * LOAD-TIME half: the structured `actions`/`providers` manifest grammar, the
 * retirement of the free-form `rebuilders` channel, the effect inventory the
 * new channel feeds, the bytes the shipped adapters actually declare, and the
 * digest that binds manifest-shipped provider code to its adapter identity.
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
 * against REAL fixture bytes written to a scratch DUO_MANIFESTS_DIR, using the
 * REAL, unmodified engine files. Same idiom as
 * sandbox/tests/regress_adapter_contract.php.
 *
 * The RUNTIME half of the same contract — negotiation against live plugin
 * state, plugin-sourced `duo_providers` discovery, invocation receipts,
 * value-level verification, and the timeout budget — is
 * sandbox/tests/regress_provider_contract.php, which the .sh wrapper runs
 * alongside this file under the one Makefile target. The two are deliberately
 * disjoint: this one never stubs a WordPress function, that one does.
 *
 * Out of reach offline, and covered live instead: Apply's placement of the
 * negotiation gate before the first mutation and the post-commit fatality of a
 * failing action (sandbox/tests/regress_fatal_mutations.sh), the per-action
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

$root = dirname(__DIR__, 2);
require $root . '/agent/src/Canon.php';
require $root . '/agent/src/OptionState.php';
require $root . '/agent/src/Db.php';
// Policy.php require_once's NativeActions.php itself (its validators call the
// closed vocabulary at load time), so this file must not require it a second
// time.
require $root . '/agent/src/Policy.php';
require $root . '/agent/src/Providers.php';
require $root . '/agent/src/Ledger.php';
require $root . '/agent/src/RepositoryCompiler.php';
require $root . '/agent/src/SidebarState.php';
require $root . '/agent/src/RepositoryAuthorization.php';
require $root . '/agent/src/Deploy.php';
require $root . '/agent/src/ManifestDispositions.php';
require $root . '/agent/src/CapabilityRegistry.php';

use Duo\Canon;
use Duo\CapabilityRegistry;
use Duo\NativeActions;
use Duo\Policy;
use Duo\Providers;
use Duo\RepositoryCompiler;

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}

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

/**
 * Fresh scratch manifests dir for one group, optionally with real
 * manifest-shipped provider files under providers/ — the exact layout
 * Providers::manifest_provider() and both digest implementations resolve.
 *
 * @param array<string, array|string> $files manifest name => decoded manifest or raw JSON
 * @param array<string, string> $providers provider id => PHP source
 */
function fresh_manifests_dir(array $files, array $providers = []): string {
    $root = sys_get_temp_dir() . '/duo_regress_actions_providers_' . bin2hex(random_bytes(4));
    mkdir($root . '/providers', 0777, true);
    foreach ($files as $name => $content) {
        Canon::write_file(
            "$root/$name.json",
            is_string($content) ? $content : json_encode($content, JSON_PRETTY_PRINT)
        );
    }
    foreach ($providers as $id => $source) {
        file_put_contents("$root/providers/$id.php", $source);
    }
    register_shutdown_function(function () use ($root) {
        foreach (glob("$root/providers/*") ?: [] as $f) {
            unlink($f);
        }
        @rmdir("$root/providers");
        foreach (glob("$root/*") ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($root);
    });
    putenv("DUO_MANIFESTS_DIR=$root");
    return $root;
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
        'spec_version' => DUO_SPEC_VERSION,
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

/** Load one probe fixture with the given mutation applied to the whole manifest. */
function load_probe(array $manifest): Policy {
    fresh_manifests_dir(['probe' => $manifest]);
    return Policy::load(null, ['probe']);
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
    'spec_version' => DUO_SPEC_VERSION,
    'plugin' => 'probe/probe.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    'rebuilders' => [
        ['command' => 'transient delete wc_attribute_taxonomies', 'triggers' => ['table:probe_rows']],
        ['command' => 'eval \'if (!class_exists("WC_Cache_Helper")) { WP_CLI::error("nope"); } WC_Cache_Helper::invalidate_cache_group("taxes");\''],
    ],
];
fresh_manifests_dir(['retired' => $retired]);
expect_throw(
    fn() => Policy::load(null, ['retired']),
    'declares the retired free-form `rebuilders` channel',
    'a manifest declaring `rebuilders` is REFUSED at load (this exact shape loaded and executed before DUO-3338)'
);
try {
    Policy::load(null, ['retired']);
} catch (\RuntimeException $e) {
    check(
        str_contains($e->getMessage(), 'migrate to structured `actions` (native or provider)')
            && str_contains($e->getMessage(), 'spec/repo-format.md'),
        'the refusal carries the migration path and where the new grammar is specified'
    );
}
// An EMPTY rebuilders list is refused too: the-events-calendar.json carried
// exactly that, and "present but empty" must not be a way to keep the key.
fresh_manifests_dir(['retired-empty' => ['name' => 'retired-empty', 'spec_version' => DUO_SPEC_VERSION, 'rebuilders' => []]]);
expect_throw(
    fn() => Policy::load(null, ['retired-empty']),
    'retired free-form `rebuilders` channel',
    'an EMPTY rebuilders list is refused as well — presence of the key is the refusal, not its contents'
);
// The frozen-policy path re-validates rather than trusting the snapshot, so a
// pinned revision captured before the migration cannot be replayed either.
expect_throw(
    fn() => Policy::from_snapshot([
        'format' => 'duo-policy-snapshot/v4',
        'adapter_sources' => ['format' => 'duo-adapter-sources/v1', 'out_of_tree' => []],
        'capabilities' => null,
        'dispositions' => null,
        'site' => ['manifests' => ['retired'], 'spec_version' => DUO_SPEC_VERSION, 'policy' => []],
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
Policy::load(null, ['probe']);
check(true, 'an argument-free action stays expressible as {} (an empty array is not treated as a malformed list)');

echo "\n== actions: native entries are bound to the closed engine vocabulary ==\n";

check(NativeActions::vocabulary() === ['transient.delete'], 'the v1 native vocabulary is exactly transient.delete');
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

// DUO-3369 widened this load-time bound by exactly one shape: a list of FLAT
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
    check(true, 'a list of FLAT objects is a legal provider argument shape at load (DUO-3369 list<object> values)');
} catch (\Throwable $t) {
    check(false, 'a list of FLAT objects is a legal provider argument shape at load (DUO-3369 list<object> values)'
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
    'spec_version' => DUO_SPEC_VERSION,
    'actions' => [[
        'kind' => 'provider',
        'provider' => 'probe-cache-offline',
        'capability' => 'flush',
        'args' => [],
    ]],
];
fresh_manifests_dir(['owner' => $owner, 'borrower' => $borrower]);
expect_throw(
    fn() => Policy::load(null, ['owner', 'borrower']),
    'must name a `providers` entry declared by manifest',
    "an action may not reach into ANOTHER pinned manifest's provider declaration"
);

echo "\n== actions: the trigger grammar is unchanged from the retired channel ==\n";

foreach ([
    [['post:*'], 'a wildcard trigger'],
    [['post:product:42'], 'an id-bearing trigger'],
    [['Post:product'], 'an uppercase trigger'],
    [['widget:sidebar'], 'an unknown surface prefix'],
    [['probe_rows'], 'a bare name with no surface prefix'],
] as [$triggers, $label]) {
    $m = probe_manifest();
    $m['actions'][0]['triggers'] = $triggers;
    refuse_probe($m, 'must be one exact canonical surface', "$label is refused");
}
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
// DUO-3318 split the old combined "kind/mode is unsupported" refusal into one
// message per closed vocabulary, each naming the rejected token and printing
// the legal set — so this needle now asserts the KIND half specifically, which
// is what this fixture actually breaks.
refuse_probe($m, "actions[0].effects[0].kind='telepathy' is not one of the engine-owned effect kinds", 'an action effect with an unsupported kind is refused, and the message names the exact declaration and the legal set');
$m = probe_manifest();
$m['actions'][0]['effects'] = [['id' => 'probe', 'kind' => 'database', 'mode' => 'telekinesis', 'selector' => ['scope' => 'database_checkpoint', 'type' => 'option', 'value' => 'probe_option']]];
refuse_probe($m, "actions[0].effects[0].mode='telekinesis' is not one of the engine-owned reversibility modes", 'an action effect with an unsupported mode is refused separately from kind, so an author is never left guessing which half failed');
$m = probe_manifest();
$m['actions'][0]['effects'] = [];
refuse_probe($m, 'actions[0].effects must be a non-empty list', 'a present-but-empty action effects list is refused');
$m = probe_manifest();
$m['actions'][0]['effects'] = [probe_effect('probe-dup')];
$m['actions'][1]['effects'] = [probe_effect('probe-dup')];
expect_throw(fn() => load_probe($m), 'probe-dup', 'two actions declaring the same effect id are refused');

// ======================================================================
echo "\n== providers: the declaration is an identity assertion, closed on every axis ==\n";

refuse_probe(probe_manifest(['providers' => 'nope']), 'providers must be a list', 'a non-list providers section is refused');
refuse_probe(probe_manifest(['providers' => ['nope']]), 'must be an object', 'a non-object provider declaration is refused');
$m = probe_manifest();
unset($m['providers'][0]['version']);
refuse_probe($m, 'requires exactly capabilities, id, plugin, source, and version', 'a provider declaration missing a required key is refused');
$m = probe_manifest();
$m['providers'][0]['timeout_seconds'] = 30;
refuse_probe($m, 'requires exactly capabilities, id, plugin, source, and version', 'an unknown provider declaration key is refused — a claim the engine would silently ignore');
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
    refuse_probe($m, '.id must match', "$label is refused");
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
refuse_probe($m, "declares provider id 'probe-cache-offline' more than once", 'one manifest declaring the same provider id twice is refused');

// Two PINNED manifests claiming one id is the load-order hazard: a provider id
// resolves to concrete executable code, so which code runs may not depend on
// pin order. Same posture as a conflicting plugin/theme adapter claim.
$second = probe_manifest(['name' => 'second']);
$second['plugin'] = 'second/second.php';
$second['providers'][0]['plugin'] = 'second/second.php';
fresh_manifests_dir(['probe' => probe_manifest(), 'second' => $second]);
expect_throw(
    fn() => Policy::load(null, ['probe', 'second']),
    "both declare provider id 'probe-cache-offline'",
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

// The real manifests are copied into a scratch dir WITHOUT dispositions.json,
// which is what makes this check about the manifest grammar rather than about
// capability-registry freshness. The generated registry is regenerated by
// scripts/capability-registry.php after any manifest edit and has its own
// suite (regress-capability-registry); loading through it here would make this
// file fail for a reason it does not test.
$shipped = sys_get_temp_dir() . '/duo_regress_actions_providers_shipped_' . bin2hex(random_bytes(4));
mkdir($shipped . '/providers', 0777, true);
$copied = [];
foreach (glob($root . '/manifests/*.json') ?: [] as $file) {
    $name = basename($file, '.json');
    if ($name === 'dispositions') {
        continue;
    }
    copy($file, "$shipped/$name.json");
    $copied[] = $name;
}
foreach (glob($root . '/manifests/providers/*.php') ?: [] as $file) {
    copy($file, $shipped . '/providers/' . basename($file));
}
foreach (['interpreters', 'regenerators'] as $sub) {
    if (!is_dir($root . '/manifests/' . $sub)) {
        continue;
    }
    mkdir("$shipped/$sub", 0777, true);
    foreach (glob($root . '/manifests/' . $sub . '/*.php') ?: [] as $file) {
        copy($file, "$shipped/$sub/" . basename($file));
    }
}
register_shutdown_function(function () use ($shipped) {
    foreach (['providers', 'interpreters', 'regenerators'] as $sub) {
        foreach (glob("$shipped/$sub/*") ?: [] as $f) {
            unlink($f);
        }
        @rmdir("$shipped/$sub");
    }
    foreach (glob("$shipped/*") ?: [] as $f) {
        if (is_file($f)) {
            unlink($f);
        }
    }
    @rmdir($shipped);
});
putenv("DUO_MANIFESTS_DIR=$shipped");
check(count($copied) >= 10, 'the shipped manifest set was copied into a scratch dir for real-byte validation (' . count($copied) . ' manifests)');
$shippedPolicies = [];
foreach ($copied as $name) {
    try {
        $shippedPolicies[$name] = Policy::load(null, [$name]);
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
        $wooSources === ['native:transient.delete', 'provider:woocommerce-cache/invalidate_cache_groups'],
        'WooCommerce declares exactly the migrated native action and the migrated provider capability, in that order'
    );
    $wooDeclaration = $woo->provider_declarations()['woocommerce-cache'] ?? [];
    check(
        ($wooDeclaration['source'] ?? null) === 'manifest'
            && ($wooDeclaration['plugin'] ?? null) === 'woocommerce/woocommerce.php'
            && ($wooDeclaration['version'] ?? null) === '1.0.0'
            && ($wooDeclaration['capabilities'] ?? null) === ['invalidate_cache_groups'],
        'the WooCommerce provider declaration is manifest-shipped code owned by the version-pinned plugin'
    );
}

// The sandbox agency fixture is the shipped example of the OTHER source: a
// custom plugin advertising its own provider, in a manifest that pins no
// plugin/version_range at all. Negotiation skips the range comparison for such
// a manifest by design, so the null range has to survive load as a null.
$agency = $shippedPolicies['duo-agency-cpt'] ?? null;
check($agency !== null, 'the plugin-sourced provider fixture manifest is among the loadable shipped set');
if ($agency !== null) {
    $agencyDeclaration = $agency->provider_declarations()['duo-agency-index'] ?? [];
    check(
        ($agencyDeclaration['source'] ?? null) === 'plugin'
            && ($agencyDeclaration['plugin'] ?? null) === 'duo-agency-cpt/duo-agency-cpt.php'
            && array_key_exists('version_range', $agencyDeclaration)
            && $agencyDeclaration['version_range'] === null,
        'a custom plugin may supply its own provider from a manifest with no version_range (the range check is then skipped, not faked)'
    );
    $agencySources = array_map(
        static fn(array $row): string => Policy::action_source($row, (int) $row['index']),
        $agency->actions()
    );
    check(
        $agencySources === ['provider:duo-agency-index/rebuild_project_index', 'native:transient.delete'],
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
        check(is_file($file), "manifest '$name' declares provider '$id' and manifests/providers/$id.php ships with it");
        if (!is_file($file)) {
            continue;
        }
        require_once $file;
        // The exact derivation Providers::manifest_provider() uses; asserted
        // here rather than hardcoded so a provider file whose class name drifts
        // from its declared id is caught offline instead of at negotiation.
        $class = '\\Duo\\Providers\\' . str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $id)));
        check(class_exists($class), "provider '$id' defines $class");
        if (!class_exists($class)) {
            continue;
        }
        // Constructing offline must not reach WordPress: negotiation
        // instantiates before it knows whether the environment can answer.
        $instance = new $class($shippedPolicy);
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
check($providerCount === 5, "all five shipped manifest-sourced providers were exercised (found $providerCount)");

echo "\n== purely declarative adapters keep the pre-change behavior exactly ==\n";

foreach (['acf', 'core', 'contact-form-7'] as $name) {
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

// ======================================================================
echo "\n== digest binding: manifest-shipped provider bytes are part of the adapter's identity ==\n";

$digestManifest = [
    'name' => 'digest',
    'spec_version' => DUO_SPEC_VERSION,
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
$providerSource = "<?php\nnamespace Duo\\Providers;\nfinal class DigestProbe {\n    public function __construct(\\Duo\\Policy \$policy) {}\n}\n";
$digestDir = fresh_manifests_dir(['digest' => $digestManifest], ['digest-probe' => $providerSource]);
$digestPolicy = Policy::load(null, ['digest']);
$adapterBefore = RepositoryCompiler::resolved_adapters($digestPolicy)[0]['digest'];
$registryBefore = CapabilityRegistry::adapter_digest($digestPolicy->manifests[0], null, $digestDir);
$combinedBefore = RepositoryCompiler::manifest_hash($digestPolicy);
check(
    $adapterBefore === $registryBefore,
    'RepositoryCompiler::resolved_adapters() and CapabilityRegistry::adapter_digest() agree on the per-adapter digest'
);

// Only the provider file changes; the manifest bytes are untouched.
file_put_contents("$digestDir/providers/digest-probe.php", $providerSource . "// drift\n");
$digestPolicyAfter = Policy::load(null, ['digest']);
check(
    $digestPolicyAfter->manifests[0] === $digestPolicy->manifests[0],
    'the manifest bytes are byte-identical across the two loads — only the provider file moved'
);
$adapterAfter = RepositoryCompiler::resolved_adapters($digestPolicyAfter)[0]['digest'];
$registryAfter = CapabilityRegistry::adapter_digest($digestPolicyAfter->manifests[0], null, $digestDir);
check(
    $adapterAfter !== $adapterBefore,
    'changing manifest-shipped provider file BYTES changes the per-adapter digest — a changed provider is a changed adapter, not invisible drift'
);
check(
    $registryAfter !== $registryBefore && $adapterAfter === $registryAfter,
    'CapabilityRegistry::adapter_digest() moves identically (the two implementations must never split)'
);
// The bytes are folded in TWO places that cannot call each other (the registry
// loads without a compiled repository), so the compiler's own row builder is
// asserted directly rather than only through the digest it feeds.
$manifestRows = new \ReflectionMethod(RepositoryCompiler::class, 'manifest_rows');
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
$missingRow = $manifestRows->invoke(null, Policy::load(null, ['digest']))[0];
check(
    ($missingRow['providers'] ?? null) === [['id' => 'digest-probe', 'sha256' => null]],
    'a missing provider file hashes as null in the identity row rather than silently vanishing from it'
);
file_put_contents("$digestDir/providers/digest-probe.php", $providerSource . "// drift\n");
$adapterRepeat = RepositoryCompiler::resolved_adapters(Policy::load(null, ['digest']))[0]['digest'];
check($adapterRepeat === $adapterAfter, 'the digest is deterministic — identical provider bytes re-hash identically');

// A plugin-sourced provider's trust anchor is the installed plugin, not a file
// in the manifests tree, so its bytes are deliberately NOT folded in. Proven
// by planting a same-named file and requiring the digest to stay put.
$pluginSourced = $digestManifest;
$pluginSourced['name'] = 'digestplugin';
$pluginSourced['providers'][0]['source'] = 'plugin';
$pluginDir = fresh_manifests_dir(['digestplugin' => $pluginSourced]);
$pluginPolicy = Policy::load(null, ['digestplugin']);
$pluginBefore = RepositoryCompiler::resolved_adapters($pluginPolicy)[0]['digest'];
file_put_contents("$pluginDir/providers/digest-probe.php", $providerSource);
check(
    RepositoryCompiler::resolved_adapters(Policy::load(null, ['digestplugin']))[0]['digest'] === $pluginBefore,
    'a plugin-sourced provider is NOT file-hashed — its identity anchor is the installed plugin the code half already version-bounds'
);

// ======================================================================
echo "\n";
if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
exit(0);
