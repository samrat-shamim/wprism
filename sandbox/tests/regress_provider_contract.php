<?php
declare(strict_types=1);

/**
 * Offline regression for DUO-3338's native-action vocabulary and plugin-owned
 * provider contract, extended by DUO-3369's structured capability arguments
 * (`list<object>`) and engine batch context channels. No WordPress target, no
 * WP-CLI, no docker: the pieces under test are the closed vocabulary (pure PHP
 * by construction — it runs inside Policy's offline validation pass), the
 * negotiation gate (whose only live inputs are the four WordPress lifecycle
 * primitives stubbed below), and Apply's per-channel batch assembly (driven
 * through reflection against a two-query fake wpdb, the same idiom
 * regress_woocommerce_regen_engine.php uses for the regeneration dispatch).
 *
 * Same idiom as regress_adapter_contract.php: real, unmodified engine files
 * against a scratch DUO_MANIFESTS_DIR holding real fixture bytes.
 *
 * What this deliberately does NOT cover, because it genuinely needs a live
 * WordPress: the shipped providers' own invoke() bodies (WooCommerce's cache
 * boundary is exercised against a fake public API by
 * regress_woocommerce_deletion_authority.php; the CLI-backed ones need a
 * pair), and Apply's placement of the negotiation gate ahead of the first
 * mutation — plus the rebuild pass that surrounds the assembly proven here
 * (object-cache flush, receipt emission), both live-apply properties owned by
 * regress_provider_contract_live.sh.
 */

$root = dirname(__DIR__, 2);
define('DUO_SPEC_VERSION', 2);

// ---- WordPress lifecycle primitives Deploy::plugin_runtime_state() reads ----
// Defining validate_plugin() also short-circuits Deploy's wp-admin include.
$GLOBALS['duo_test_plugins'] = [];
$GLOBALS['duo_test_active'] = [];
$GLOBALS['duo_test_providers'] = [];

function validate_plugin(string $plugin): mixed {
    return isset($GLOBALS['duo_test_plugins'][$plugin])
        ? 0
        : new \WP_Error("plugin '$plugin' does not exist");
}
function get_plugins(): array {
    return $GLOBALS['duo_test_plugins'];
}
function get_option(string $name, mixed $default = false): mixed {
    return $name === 'active_plugins' ? $GLOBALS['duo_test_active'] : $default;
}
function is_wp_error(mixed $thing): bool {
    return $thing instanceof \WP_Error;
}
function apply_filters(string $hook, mixed $value): mixed {
    return $hook === 'duo_providers' ? $GLOBALS['duo_test_providers'] : $value;
}
class WP_Error {
    public function __construct(public string $message = '') {}
}

require $root . '/agent/src/Canon.php';
require $root . '/agent/src/OptionState.php';
require $root . '/agent/src/Policy.php';
require $root . '/agent/src/CodeCompatibility.php';
require $root . '/agent/src/Deploy.php';
require $root . '/agent/src/Providers.php';

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$condition) {
        $failures++;
    }
};

// ---- scratch manifests dir with a real manifest-shipped provider file ----
$dir = sys_get_temp_dir() . '/duo-provider-contract-' . getmypid();
@mkdir($dir . '/providers', 0700, true);
register_shutdown_function(static function () use ($dir): void {
    array_map('unlink', glob($dir . '/providers/*.php') ?: []);
    @rmdir($dir . '/providers');
    @rmdir($dir);
});
file_put_contents($dir . '/providers/probe-cache.php', <<<'PHP'
<?php
namespace Duo\Providers;

final class ProbeCache {
    public array $calls = [];
    public static array $capabilityOverrides = [];
    public static array $identityOverrides = [];
    public static mixed $receiptOverride = null;
    public static float $sleepSeconds = 0.0;

    public function __construct(\Duo\Policy $policy) {}

    public function identity(): array {
        return self::$identityOverrides + [
            'id' => 'probe-cache',
            'plugin' => 'probe/probe.php',
            'version' => '1.0.0',
        ];
    }

    public function capabilities(): array {
        return [
            'flush' => self::$capabilityOverrides + [
                'args' => ['groups' => ['type' => 'list<string>', 'required' => true]],
                'reads' => ['option:probe_setting'],
                'writes' => ['entity:probe-cache-groups'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 30,
            ],
        ];
    }

    public function invoke(string $capability, array $args): array {
        $this->calls[] = [$capability, $args];
        if (self::$sleepSeconds > 0.0) {
            usleep((int) (self::$sleepSeconds * 1000000));
        }
        if (self::$receiptOverride !== null) {
            return self::$receiptOverride;
        }
        return ['before' => ['groups' => []], 'after' => ['groups' => $args['groups'] ?? []], 'verified' => true];
    }
}
PHP);
// The plugin-sourced anchor check (Providers::plugin_anchor_problem())
// resolves the provider class's file against WP_PLUGIN_DIR/<plugin-dir>, so
// the harness models a real plugins tree: ProbeSupplied lives under
// wp-plugins/probe/ (anchored, negotiates clean), while ProbeCache's own
// file lives in the manifests providers/ dir (posing it as plugin-sourced
// must therefore refuse).
define('WP_PLUGIN_DIR', $dir . '/wp-plugins');
@mkdir($dir . '/wp-plugins/probe', 0700, true);
register_shutdown_function(static function () use ($dir): void {
    array_map('unlink', glob($dir . '/wp-plugins/probe/*.php') ?: []);
    @rmdir($dir . '/wp-plugins/probe');
    @rmdir($dir . '/wp-plugins');
});
file_put_contents($dir . '/wp-plugins/probe/duo-provider.php', <<<'PHP'
<?php
namespace Duo\Providers;

final class ProbeSupplied {
    public function identity(): array {
        return ['id' => 'probe-cache', 'plugin' => 'probe/probe.php', 'version' => '1.0.0'];
    }

    public function capabilities(): array {
        return [
            'flush' => [
                'args' => ['groups' => ['type' => 'list<string>', 'required' => true]],
                'reads' => ['option:probe_setting'],
                'writes' => ['entity:probe-cache-groups'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 30,
            ],
        ];
    }

    public function invoke(string $capability, array $args): array {
        return ['before' => [], 'after' => ['groups' => $args['groups'] ?? []], 'verified' => true];
    }
}
PHP);
require_once $dir . '/wp-plugins/probe/duo-provider.php';
putenv('DUO_MANIFESTS_DIR=' . $dir);
// Loaded up front so the per-case reset below can address the fixture's static
// override slots; Providers::negotiate() require_once's the same file itself.
require_once $dir . '/providers/probe-cache.php';

$manifest = [
    'name' => 'probe',
    'spec_version' => 2,
    'plugin' => 'probe/probe.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    'providers' => [[
        'id' => 'probe-cache',
        'version' => '1.0.0',
        'source' => 'manifest',
        'plugin' => 'probe/probe.php',
        'capabilities' => ['flush'],
    ]],
    'actions' => [[
        'kind' => 'provider',
        'provider' => 'probe-cache',
        'capability' => 'flush',
        'args' => ['groups' => ['probe-group']],
    ]],
];
$policyFor = static function (array $manifest): \Duo\Policy {
    return \Duo\Policy::from_snapshot([
        'format' => 'duo-policy-snapshot/v4',
        'adapter_sources' => ['format' => 'duo-adapter-sources/v1', 'out_of_tree' => []],
        'capabilities' => null,
        'dispositions' => null,
        'site' => [
            'manifests' => [$manifest['name']],
            'spec_version' => 2,
            'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
        ],
        'manifests' => [$manifest],
    ]);
};
$reset = static function (): void {
    $GLOBALS['duo_test_plugins'] = ['probe/probe.php' => ['Version' => '1.5.0']];
    $GLOBALS['duo_test_active'] = ['probe/probe.php'];
    $GLOBALS['duo_test_providers'] = [];
    \Duo\Providers\ProbeCache::$capabilityOverrides = [];
    \Duo\Providers\ProbeCache::$identityOverrides = [];
    \Duo\Providers\ProbeCache::$receiptOverride = null;
    \Duo\Providers\ProbeCache::$sleepSeconds = 0.0;
};

echo "\n== closed native-action vocabulary ==\n";
$check(\Duo\NativeActions::vocabulary() === ['transient.delete'],
    'v1 vocabulary is exactly transient.delete — a plugin cannot mint an action name');
$expectMessage = static function (callable $body, string $needle, string $label) use ($check): void {
    try {
        $body();
        $check(false, $label);
    } catch (\Throwable $t) {
        $check(str_contains($t->getMessage(), $needle), $label . ' (message: ' . $t->getMessage() . ')');
    }
};
$expectMessage(
    static fn() => \Duo\NativeActions::validate('shell.exec', ['cmd' => 'rm -rf /'], 'probe'),
    'vocabulary is closed',
    'an unknown native action is refused with the closed vocabulary named'
);
$expectMessage(
    static fn() => \Duo\NativeActions::validate('transient.delete', ['name' => 'ok', 'ttl' => 5], 'probe'),
    'unknown key(s)',
    'an unknown argument key is refused rather than ignored'
);
$expectMessage(
    static fn() => \Duo\NativeActions::validate('transient.delete', [], 'probe'),
    'missing required key',
    'a missing required argument is refused'
);
$expectMessage(
    static fn() => \Duo\NativeActions::validate('transient.delete', ['name' => "a'; DROP TABLE wp_options; --"], 'probe'),
    'bounded string',
    'an argument outside the bounded charset is refused before any target contact'
);

echo "\n== negotiation: the supported path ==\n";
$reset();
$policy = $policyFor($manifest);
$selected = $policy->actions_for(['post:probe']);
$check(count($selected) === 1, 'the unscoped probe action is selected by a non-empty surface set');
$check($policy->actions_for([]) === [], 'an empty surface set selects nothing, so nothing is negotiated');
$negotiation = \Duo\Providers::negotiate($policy, $policy->actions_for([]));
$check($negotiation === ['problems' => [], 'providers' => [], 'capabilities' => []],
    'a run selecting no provider action touches no provider code at all');

$negotiation = \Duo\Providers::negotiate($policy, $selected);
$check($negotiation['problems'] === [], 'an installed, active, in-range provider with a matching identity negotiates clean');
$check($negotiation['providers']['probe-cache'] instanceof \Duo\Providers\ProbeCache,
    'the manifest-shipped provider class is loaded from <manifests_dir>/providers/<id>.php');
$check(($negotiation['capabilities']['probe-cache']['flush']['scope'] ?? null) === 'site',
    'the negotiated capability declaration is bound for the rebuild pass');

echo "\n== negotiation: every refusal names expected, found, and a remediation ==\n";
$problemFor = static function (array $mutate, ?callable $before = null) use ($policyFor, $manifest, $reset): array {
    $reset();
    if ($before !== null) {
        $before();
    }
    $m = $mutate === [] ? $manifest : array_replace_recursive($manifest, $mutate);
    $policy = $policyFor($m);
    $negotiation = \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
    return $negotiation['problems'];
};
$one = static function (array $problems) use ($check): array {
    $check(count($problems) === 1, 'exactly one problem row is reported');
    return $problems[0] ?? [];
};

$p = $one($problemFor([], static function (): void {
    $GLOBALS['duo_test_plugins'] = [];
    $GLOBALS['duo_test_active'] = [];
}));
$check(($p['code'] ?? '') === 'missing_plugin' && str_contains($p['remediation'] ?? '', 'install and activate'),
    'an uninstalled owning plugin is refused with an install remediation');

$p = $one($problemFor([], static function (): void {
    $GLOBALS['duo_test_active'] = [];
}));
$check(($p['code'] ?? '') === 'inactive_plugin' && str_contains($p['remediation'] ?? '', 'duo deploy'),
    'an inactive owning plugin is refused and pointed at deploy');

$p = $one($problemFor([], static function (): void {
    $GLOBALS['duo_test_plugins']['probe/probe.php']['Version'] = '2.4.0';
}));
$check(($p['code'] ?? '') === 'outside_version_range'
    && ($p['expected'] ?? '') === '>=1.0.0 <2.0.0'
    && ($p['found'] ?? '') === '2.4.0',
    'a live plugin version outside the declaring manifest range is refused with both versions named');

$p = $one($problemFor([], static function (): void {
    \Duo\Providers\ProbeCache::$identityOverrides = ['version' => '2.0.0'];
}));
$check(($p['code'] ?? '') === 'identity_mismatch'
    && str_contains($p['expected'] ?? '', 'version=1.0.0')
    && str_contains($p['found'] ?? '', 'version=2.0.0'),
    'a provider whose identity() disagrees with its declaration is refused, expected vs found');

$p = $one($problemFor(['actions' => [['capability' => 'purge']], 'providers' => [['capabilities' => ['purge']]]]));
$check(($p['code'] ?? '') === 'missing_capability' && str_contains($p['found'] ?? '', 'advertised: flush'),
    'a capability the installed provider does not advertise is refused, listing what it does advertise');

$p = $one($problemFor([], static function (): void {
    \Duo\Providers\ProbeCache::$capabilityOverrides = ['idempotent' => false];
}));
$check(($p['code'] ?? '') === 'non_idempotent_capability' && str_contains($p['remediation'] ?? '', 'retry'),
    "a non-idempotent capability is refused because apply's retry re-fires the rebuild pass");

$p = $one($problemFor(['actions' => [['args' => ['groups' => 'not-a-list']]]]));
$check(($p['code'] ?? '') === 'invalid_capability_args' && str_contains($p['found'] ?? '', 'list<string>'),
    'manifest arguments that do not match the provider-declared schema are refused');

$p = $one($problemFor([], static function (): void {
    \Duo\Providers\ProbeCache::$capabilityOverrides = ['sabotage' => true];
}));
$check(($p['code'] ?? '') === 'malformed_capability',
    'a capability declaration with an unknown key is refused rather than partially honored');

echo "\n== plugin-sourced providers (a custom plugin advertising its own) ==\n";
$pluginSourced = array_replace_recursive($manifest, ['providers' => [['source' => 'plugin']]]);
$reset();
$policy = $policyFor($pluginSourced);
$p = $one(\Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']))['problems']);
$check(($p['code'] ?? '') === 'missing_plugin_provider' && str_contains($p['expected'] ?? '', 'duo_providers'),
    'a plugin-sourced provider nobody registered is a negotiation problem, not a load crash');

$reset();
$GLOBALS['duo_test_providers'] = [new \Duo\Providers\ProbeSupplied()];
$negotiation = \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
$check($negotiation['problems'] === [] && isset($negotiation['providers']['probe-cache']),
    'a provider advertised on the duo_providers filter is discovered and negotiated by its own identity()');

$reset();
$GLOBALS['duo_test_providers'] = [new \Duo\Providers\ProbeCache($policy)];
$p = $one(\Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']))['problems']);
$check(($p['code'] ?? '') === 'provider_outside_owning_plugin'
    && str_contains($p['remediation'] ?? '', 'source: manifest'),
    'a plugin-sourced registration whose class file lives outside the owning plugin directory is refused (identity stays falsifiable)');

$reset();
$GLOBALS['duo_test_providers'] = [new class {
    public function identity(): array {
        throw new \RuntimeException('third-party provider exploding on discovery');
    }
}, new \Duo\Providers\ProbeSupplied()];
$negotiation = \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
$check($negotiation['problems'] === [] && isset($negotiation['providers']['probe-cache']),
    "an unrelated registration whose identity() throws is skipped, not a fatal for the provider actually wanted");

echo "\n== entity scope negotiates its batch preconditions before any mutation ==\n";
$reset();
\Duo\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity'];
$policy = $policyFor($manifest);
$p = $one(\Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']))['problems']);
$check(($p['code'] ?? '') === 'entity_scope_unscoped_action'
    && str_contains($p['remediation'] ?? '', 'scope: site'),
    'an entity-scoped capability on an unscoped action refuses at negotiation (its batch would always be empty)');

$reset();
\Duo\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity'];
$entityManifest = $manifest;
$entityManifest['actions'][0]['triggers'] = ['option:probe_setting'];
$policy = $policyFor($entityManifest);
$p = $one(\Duo\Providers::negotiate($policy, $policy->actions_for(['option:probe_setting']))['problems']);
$check(($p['code'] ?? '') === 'entity_scope_unresolvable_trigger'
    && str_contains($p['found'] ?? '', 'option:probe_setting'),
    'an entity-scoped capability triggered on a surface with no per-entity id refuses at negotiation, not post-commit');
$reset();
$policy = $policyFor($manifest);

echo "\n== invocation: receipts, value-level verification, and the timeout budget ==\n";
$reset();
$policy = $policyFor($manifest);
$action = $policy->actions_for(['post:probe'])[0];
$negotiation = \Duo\Providers::negotiate($policy, [$action]);
$provider = $negotiation['providers']['probe-cache'];
$declaration = $negotiation['capabilities']['probe-cache']['flush'];
$receipt = \Duo\Providers::invoke($provider, $action, $declaration, []);
$check(($receipt['verified'] ?? null) === true
    && ($receipt['after']['groups'] ?? null) === ['probe-group']
    && is_float($receipt['duration_seconds'] ?? null),
    'a verified receipt carries the observed after-state and the measured duration');
$check($provider->calls === [['flush', ['groups' => ['probe-group']]]],
    'the capability receives exactly the manifest-declared arguments, and no engine-invented ones');

$expectInvokeFailure = static function (callable $before, string $needle, string $label) use (
    $check, $provider, $action, $declaration, $reset
): void {
    $reset();
    $before();
    try {
        \Duo\Providers::invoke($provider, $action, $declaration, []);
        $check(false, $label);
    } catch (\Throwable $t) {
        $check(str_contains($t->getMessage(), $needle), $label . ' (message: ' . $t->getMessage() . ')');
    }
};
$expectInvokeFailure(
    static fn() => \Duo\Providers\ProbeCache::$receiptOverride = ['before' => [], 'after' => [], 'verified' => false],
    'no value-level verification',
    'a receipt with verified !== true is refused: command success is not evidence the effect landed'
);
$expectInvokeFailure(
    static fn() => \Duo\Providers\ProbeCache::$receiptOverride = ['ok' => true],
    'malformed receipt',
    'a receipt missing before/after/verified is refused'
);
// timeout_seconds is a positive integer, so the smallest honest overrun test
// is a one-second budget deliberately exceeded. Worth the wall-clock second:
// this is the only check that the post-hoc budget is enforced at all rather
// than merely declared.
$reset();
\Duo\Providers\ProbeCache::$sleepSeconds = 1.1;
try {
    \Duo\Providers::invoke($provider, $action, ['timeout_seconds' => 1] + $declaration, []);
    $check(false, 'an invocation past its declared timeout_seconds budget is a hard failure');
} catch (\Throwable $t) {
    $check(str_contains($t->getMessage(), 'overran its declared budget'),
        'an invocation past its declared timeout_seconds budget is a hard failure (message: '
        . $t->getMessage() . ')');
}
\Duo\Providers\ProbeCache::$sleepSeconds = 0.0;

// ======================================================================
// DUO-3369: structured capability arguments and engine batch context.
//
// Two additions to the same contract, both closed the way everything else
// here is closed: an argument may be a list of TYPED OBJECTS with a declared
// per-capability field vocabulary, and an entity-scoped capability may opt
// into engine batch channels (deletions/reparents/retry/always_on_write) that
// previously only the regenerator channel could receive. The load-bearing
// compatibility claim — an existing capability's declaration and injected
// batch are byte-identical — is a frozen-bytes check below, not prose.
// ======================================================================

echo "\n== list<object> arguments: the declaration's own field vocabulary ==\n";
$rowFields = [
    'kind' => ['type' => 'string', 'required' => true],
    'id' => ['type' => 'int', 'required' => true],
    'purged' => ['type' => 'bool', 'required' => false],
];
$rowsArgs = ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => $rowFields]];
// Declared args and action args move together: the capability declaration is
// validated before the manifest arguments are checked against it, so a
// malformed DECLARATION refuses first regardless of what the action passes.
$rowsNegotiation = static function (array $declaredArgs, array $actionArgs) use (
    $policyFor, $manifest, $reset
): array {
    $reset();
    \Duo\Providers\ProbeCache::$capabilityOverrides = ['args' => $declaredArgs];
    $m = $manifest;
    $m['actions'][0]['args'] = $actionArgs;
    $policy = $policyFor($m);
    return \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
};
$rowsProblem = static function (array $declaredArgs, array $actionArgs) use ($rowsNegotiation, $one): array {
    return $one($rowsNegotiation($declaredArgs, $actionArgs)['problems']);
};

$goodRows = [['kind' => 'post:probe', 'id' => 7], ['kind' => 'term:probe_tax', 'id' => 3, 'purged' => true]];
$negotiation = $rowsNegotiation($rowsArgs, ['rows' => $goodRows]);
$check($negotiation['problems'] === []
    && ($negotiation['capabilities']['probe-cache']['flush']['args']['rows']['type'] ?? null) === 'list<object>',
    'a well-formed list<object> argument negotiates clean and binds with its field vocabulary');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true]],
    ['rows' => $goodRows]
);
$check(($p['code'] ?? '') === 'malformed_capability' && str_contains($p['found'] ?? '', 'closed field vocabulary'),
    'a list<object> declaration without `fields` is refused — an object list with no field vocabulary is a free-form payload');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => []]],
    ['rows' => $goodRows]
);
$check(($p['code'] ?? '') === 'malformed_capability' && str_contains($p['found'] ?? '', 'non-empty object'),
    'an EMPTY fields map is refused rather than read as "accepts anything"');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => ['kind', 'id']]],
    ['rows' => $goodRows]
);
$check(($p['code'] ?? '') === 'malformed_capability' && str_contains($p['found'] ?? '', 'non-empty object'),
    'a fields LIST (rather than a name => rule map) is refused');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => [
        'Kind Of Thing' => ['type' => 'string', 'required' => true],
    ]]],
    ['rows' => $goodRows]
);
$check(($p['code'] ?? '') === 'malformed_capability'
    && str_contains($p['found'] ?? '', "malformed field name 'Kind Of Thing'"),
    'a field name outside the bounded argument-name charset is refused, naming the token');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => [
        'kind' => ['type' => 'string', 'required' => true, 'default' => 'post'],
    ]]],
    ['rows' => $goodRows]
);
$check(($p['code'] ?? '') === 'malformed_capability'
    && str_contains($p['found'] ?? '', 'fields.kind must declare exactly type and required'),
    'an unknown key inside a field rule is refused rather than ignored');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => [
        'nested' => ['type' => 'list<object>', 'required' => true],
    ]]],
    ['rows' => $goodRows]
);
$check(($p['code'] ?? '') === 'malformed_capability'
    && str_contains($p['found'] ?? '', 'nests exactly one level'),
    'a list<object> INSIDE a list<object> is refused — the object grammar is exactly one level deep');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => [
        'tags' => ['type' => 'list<string>', 'required' => true],
    ]]],
    ['rows' => $goodRows]
);
$check(($p['code'] ?? '') === 'malformed_capability'
    && str_contains($p['found'] ?? '', 'must be one of bool, int, string'),
    'a list-typed row field is refused too: one level means scalars only inside a row');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => [
        'kind' => ['type' => 'string', 'required' => 'yes'],
    ]]],
    ['rows' => $goodRows]
);
$check(($p['code'] ?? '') === 'malformed_capability'
    && str_contains($p['found'] ?? '', 'fields.kind.required must be a boolean'),
    'a non-boolean field `required` is refused');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<string>', 'required' => true, 'fields' => $rowFields]],
    ['rows' => ['a']]
);
$check(($p['code'] ?? '') === 'malformed_capability'
    && str_contains($p['found'] ?? '', 'must declare exactly type and required'),
    'a scalar-typed argument may not carry a `fields` map — only list<object> declares one');

echo "\n== list<object> VALUES: two gates, load-time shape then negotiated vocabulary ==\n";
// Depth is bounded at LOAD (Policy::validate_provider_action(), where no
// provider code exists yet) and the field vocabulary at NEGOTIATION (where the
// capability's schema does). Both halves are asserted here so the split is
// visible rather than assumed: a shape the load gate refuses can never reach
// the negotiated check at all.
$expectLoadRefusal = static function (array $actionArgs, string $needle, string $label) use (
    $check, $policyFor, $manifest, $reset
): void {
    $reset();
    $m = $manifest;
    $m['actions'][0]['args'] = $actionArgs;
    try {
        $policyFor($m);
        $check(false, $label);
    } catch (\Throwable $t) {
        $check(str_contains($t->getMessage(), $needle), $label . ' (message: ' . $t->getMessage() . ')');
    }
};
$expectLoadRefusal(
    ['rows' => [['kind' => 'post:probe', 'nested' => [['deep' => 1]]]]],
    "row 0 field 'nested' must be a scalar",
    'a list<object> row carrying its own nested payload is refused at LOAD — one level is the manifest-side bound'
);
$expectLoadRefusal(
    ['rows' => [['Kind' => 'post:probe']]],
    'row 0 field names must match',
    'a row field name outside the bounded charset is refused at load'
);
$expectLoadRefusal(
    ['rows' => ['kind' => 'post:probe', 'id' => 7]],
    'must be a scalar or a list of scalars',
    'a single object where a LIST of objects belongs is refused at load'
);

$p = $rowsProblem($rowsArgs, ['rows' => [['kind' => 'post:probe', 'id' => 7, 'flush' => true]]]);
$check(($p['code'] ?? '') === 'invalid_capability_args'
    && str_contains($p['found'] ?? '', 'does not declare: flush')
    && str_contains($p['found'] ?? '', 'declared: kind, id, purged'),
    'an undeclared row field is refused, naming both the unknown field and the declared vocabulary');

$p = $rowsProblem($rowsArgs, ['rows' => [['kind' => 'post:probe']]]);
$check(($p['code'] ?? '') === 'invalid_capability_args'
    && str_contains($p['found'] ?? '', "row 0 is missing required field 'id'"),
    'a missing required row field is refused, naming the row index');

$p = $rowsProblem($rowsArgs, ['rows' => [['kind' => 'post:probe', 'id' => '7']]]);
$check(($p['code'] ?? '') === 'invalid_capability_args'
    && str_contains($p['found'] ?? '', "field 'id' must be of type int"),
    'a mistyped row field is refused (a numeric string is not an int)');

$p = $rowsProblem($rowsArgs, ['rows' => [['kind' => 'post:probe', 'id' => 7], 'not-an-object']]);
$check(($p['code'] ?? '') === 'invalid_capability_args'
    && str_contains($p['found'] ?? '', 'row 1 must be an object'),
    'a scalar where a row belongs passes the load gate (it is a scalar) and is refused against the declared type');

// The negotiated half of the one-level bound, reached directly because the
// load gate above already refuses this shape in a manifest: a capability
// negotiated from a non-manifest caller must still not receive nested rows.
$reset();
\Duo\Providers\ProbeCache::$capabilityOverrides = ['args' => $rowsArgs];
$validateArgs = new \ReflectionMethod(\Duo\Providers::class, 'validate_args');
try {
    $validateArgs->invoke(
        null,
        ['rows' => [['kind' => 'post:probe', 'id' => 7, 'purged' => [['deep' => 1]]]]],
        $rowsArgs,
        'probe args'
    );
    $check(false, 'a nested object list smuggled into a row VALUE is refused at negotiation too');
} catch (\Throwable $t) {
    $check(str_contains($t->getMessage(), "field 'purged' must be of type bool"),
        'a nested object list smuggled into a row VALUE is refused at negotiation too (message: '
        . $t->getMessage() . ')');
}
try {
    $validateArgs->invoke(null, ['rows' => [['post:probe', 7]]], $rowsArgs, 'probe args');
    $check(false, 'a positional row is refused as unknown fields rather than read positionally');
} catch (\Throwable $t) {
    $check(str_contains($t->getMessage(), 'does not declare: 0, 1'),
        'a positional row is refused as unknown fields rather than read positionally (message: '
        . $t->getMessage() . ')');
}

$optionalRows = ['rows' => ['type' => 'list<object>', 'required' => false, 'fields' => $rowFields]];
$negotiation = $rowsNegotiation($optionalRows, []);
$check($negotiation['problems'] === [], 'an optional list<object> argument the manifest omits negotiates clean');
$negotiation = $rowsNegotiation($rowsArgs, ['rows' => []]);
$check($negotiation['problems'] === [], 'an empty row list is a valid value — emptiness is the manifest\'s to state');

echo "\n== engine batch channels: a capability opts in, and may not mint one ==\n";
$channelNegotiation = static function (mixed $context, string $scope = 'entity') use (
    $policyFor, $manifest, $reset
): array {
    $reset();
    $overrides = ['scope' => $scope];
    if ($context !== null) {
        $overrides['context'] = $context;
    }
    \Duo\Providers\ProbeCache::$capabilityOverrides = $overrides;
    $m = $manifest;
    $m['actions'][0]['triggers'] = ['post:probe'];
    $policy = $policyFor($m);
    return \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
};
$channelProblem = static function (mixed $context, string $scope = 'entity') use ($channelNegotiation, $one): array {
    return $one($channelNegotiation($context, $scope)['problems']);
};

$negotiation = $channelNegotiation(['deletions', 'retry']);
$check($negotiation['problems'] === []
    && ($negotiation['capabilities']['probe-cache']['flush']['context'] ?? null) === ['deletions', 'retry'],
    'an entity-scoped capability may declare engine batch channels, and the declaration binds for the rebuild pass');
$check(\Duo\Providers::CONTEXT_CHANNELS === ['always_on_write', 'deletions', 'reparents', 'retry'],
    'the channel vocabulary is exactly deletions, reparents, retry, always_on_write — a capability cannot mint a fifth');

$p = $channelProblem(['deletions', 'tombstones']);
$check(($p['code'] ?? '') === 'malformed_capability'
    && str_contains($p['found'] ?? '', "names 'tombstones'")
    && str_contains($p['found'] ?? '', 'always_on_write, deletions, reparents, retry'),
    'an unknown channel is refused, naming the token and the closed set');

$p = $channelProblem(['deletions', 'deletions']);
$check(($p['code'] ?? '') === 'malformed_capability'
    && str_contains($p['found'] ?? '', "repeats channel 'deletions'"),
    'a duplicated channel is refused rather than deduplicated');

$p = $channelProblem([]);
$check(($p['code'] ?? '') === 'malformed_capability'
    && str_contains($p['found'] ?? '', 'must be a non-empty list'),
    'an empty context list is refused — it would declare the envelope while carrying nothing');

$p = $channelProblem('deletions');
$check(($p['code'] ?? '') === 'malformed_capability'
    && str_contains($p['found'] ?? '', 'must be a non-empty list'),
    'a bare channel string (not a list) is refused');

$p = $channelProblem(['deletions', 'reparents'], 'site');
$check(($p['code'] ?? '') === 'malformed_capability'
    && str_contains($p['found'] ?? '', 'only meaningful for scope: entity')
    && str_contains($p['found'] ?? '', 'declared channels: deletions, reparents'),
    'context on a scope: site capability is refused, and the message names which channels were declared');

echo "\n== the injected batch: an envelope only for what was declared ==\n";
$reset();
$policy = $policyFor($manifest);
$entityAction = $policy->actions_for(['post:probe'])[0];
$entityAction['triggers'] = ['post:probe'];
$batch = [['kind' => 'post:probe', 'id' => 7], ['kind' => 'term:probe_tax', 'id' => 3]];
$deletionRows = [['kind' => 'post:probe', 'uuid' => 'aaaa', 'id' => 41]];
$invokeWith = static function (array $context, array $channels = ['deletions', 'retry']) use (
    $policyFor, $manifest, $reset, $entityAction, $batch
): array {
    $reset();
    \Duo\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity', 'context' => $channels];
    $policy = $policyFor($manifest);
    $provider = new \Duo\Providers\ProbeCache($policy);
    $declaration = $provider->capabilities()['flush'];
    \Duo\Providers::invoke($provider, $entityAction, $declaration, $batch, $context);
    return $provider->calls[0][1];
};
$args = $invokeWith(['deletions' => $deletionRows, 'retry' => true]);
$check(array_keys($args) === ['groups', 'entities'],
    'the envelope still rides under the single reserved `entities` argument key');
$check(array_keys($args['entities']) === ['entities', 'deletions', 'retry'],
    'the envelope carries entities plus ONLY the declared channels, in the engine\'s fixed order (reparents is absent, not empty)');
$check($args['entities']['entities'] === $batch
    && $args['entities']['deletions'] === $deletionRows
    && $args['entities']['retry'] === true,
    'each declared channel arrives with exactly the rows the engine assembled');
$args = $invokeWith(['deletions' => [], 'retry' => false]);
$check($args['entities'] === ['entities' => $batch, 'deletions' => [], 'retry' => false],
    'a declared-but-empty channel is present and empty — "nothing happened" is distinguishable from "never asked for"');
$args = $invokeWith([], ['always_on_write']);
$check($args['entities'] === ['entities' => $batch],
    'always_on_write is behavioral: it declares the envelope but adds no payload key of its own');

$expectPayloadRefusal = static function (array $context, array $channels, string $needle, string $label) use (
    $check, $invokeWith
): void {
    try {
        $invokeWith($context, $channels);
        $check(false, $label);
    } catch (\Throwable $t) {
        $check(str_contains($t->getMessage(), $needle), $label . ' (message: ' . $t->getMessage() . ')');
    }
};
$expectPayloadRefusal(
    ['deletions' => $deletionRows, 'reparents' => []],
    ['deletions'],
    "'reparents' batch channel it never declared",
    'context the capability never declared is refused, not quietly delivered'
);
$expectPayloadRefusal(
    ['deletions' => $deletionRows],
    ['deletions', 'reparents'],
    "declared the 'reparents' batch channel but the engine assembled none",
    'a declared channel the engine failed to assemble is refused, not silently omitted'
);
$expectPayloadRefusal(
    ['deletions' => $deletionRows, 'retry' => 'yes'],
    ['deletions', 'retry'],
    "channel 'retry' was assembled as string",
    'a malformed channel value is refused at the injection boundary'
);
$expectPayloadRefusal(
    ['tombstones' => []],
    ['deletions'],
    'the engine does not assemble: tombstones',
    'a payload name outside the closed channel set is refused'
);

echo "\n== the engine half: what Apply assembles for each declared channel ==\n";
// The assembly is reachable offline, so it is proven here rather than deferred
// to the live leg: same idiom as regress_woocommerce_regen_engine.php, which
// drives Apply's private regeneration dispatch against a fake wpdb. Real,
// unmodified Apply.php; the only fake is the two-query wpdb Ledger::id_for()
// needs. What stays live-only is the surrounding rebuild() pass (object-cache
// flush, term recounts, receipt emission), not these projections.
require $root . '/agent/src/Db.php';
require $root . '/agent/src/Ledger.php';
require $root . '/agent/src/Apply.php';

final class ProbeBatchWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    /** @var array<string,int> "<uuid>\0<id_kind>" => local id */
    public array $map = [];

    public function prepare(string $query, ...$args): string {
        foreach ($args as $arg) {
            $value = is_int($arg) || is_float($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
            $query = (string) preg_replace('/%[dsif]/', $value, $query, 1);
        }
        return $query;
    }

    public function get_var(string $query): mixed {
        if (preg_match("/SELECT local_id FROM wp_duo_map WHERE uuid = '([^']+)' AND id_kind = '([^']+)'/", $query, $m)) {
            return $this->map[$m[1] . "\0" . $m[2]] ?? null;
        }
        return null;
    }
}

$wpdb = new ProbeBatchWpdb();
$liveDeleted = '11111111-1111-4111-8111-111111111111';
$liveTerm = '22222222-2222-4222-8222-222222222222';
$forgottenDeleted = '33333333-3333-4333-8333-333333333333';
$otherAdapters = '44444444-4444-4444-8444-444444444444';
$moved = '55555555-5555-4555-8555-555555555555';
// A tombstone applied by THIS run still resolves (Ledger::forget() runs in the
// ledger transaction after the rebuild pass); one already applied by a prior
// incomplete run may not.
$wpdb->map = [
    $liveDeleted . "\0post" => 41,
    $liveTerm . "\0term" => 9,
    $otherAdapters . "\0post" => 77,
    $moved . "\0post" => 204,
];

$applyClass = new \ReflectionClass(\Duo\Apply::class);
$apply = $applyClass->newInstanceWithoutConstructor();
$applyPolicy = $applyClass->getProperty('policy');
$applyPolicy->setValue($apply, $policyFor($manifest));
$applyRetry = $applyClass->getProperty('retryingIncompleteApply');
// No setAccessible(): reflection reaches a private directly from PHP 8.1, and
// the engine already requires 8.1 or newer (array_is_list()). Calling it would
// only bury this section's own output in deprecation notices.
$applyMethods = [];
$applyPrivate = static function (string $method, array $args) use ($applyClass, $apply, &$applyMethods): mixed {
    $applyMethods[$method] ??= $applyClass->getMethod($method);
    return $applyMethods[$method]->invokeArgs($apply, $args);
};

$batchAction = [
    'provider' => 'probe-cache',
    'capability' => 'flush',
    'triggers' => ['post:probe', 'term:probe_tax'],
];
$deleteWork = [
    ['uuid' => $liveDeleted, 'type' => 'post', 'deletion_kind' => 'post', 'deletion_type' => 'probe'],
    ['uuid' => $liveTerm, 'type' => 'term', 'deletion_kind' => 'term', 'deletion_type' => 'probe_tax'],
    ['uuid' => $forgottenDeleted, 'type' => 'post', 'deletion_kind' => 'post', 'deletion_type' => 'probe'],
    ['uuid' => $otherAdapters, 'type' => 'post', 'deletion_kind' => 'post', 'deletion_type' => 'somebody_else'],
];
$deletions = $applyPrivate('action_deletions', [$batchAction, $deleteWork]);
$check($deletions === [
    ['kind' => 'post:probe', 'uuid' => $liveDeleted, 'id' => 41],
    ['kind' => 'post:probe', 'uuid' => $forgottenDeleted, 'id' => 0],
    ['kind' => 'term:probe_tax', 'uuid' => $liveTerm, 'id' => 9],
], 'the deletions channel carries {kind, uuid, id} for triggered tombstones only, ordered by surface then uuid');
$check(array_column($deletions, 'uuid') === [$liveDeleted, $forgottenDeleted, $liveTerm]
    && !in_array($otherAdapters, array_column($deletions, 'uuid'), true),
    "a tombstone on a surface this action's triggers do not name stays invisible to it (one adapter, one window)");
$check(($deletions[1]['id'] ?? null) === 0,
    'a tombstone whose ledger mapping is already gone carries id 0 rather than failing the pass or inventing an id');

$regenContext = [
    [
        'kind' => 'reparent',
        'uuid' => $moved,
        'id' => 204,
        'post_type' => 'probe',
        'old_parent_id' => 202,
        'new_parent_id' => 203,
        'parent_id' => 202,
        // The A root of a chained A->B->C move, preserved by
        // capture_regen_reparent_context()'s merge across a failed rebuild.
        'root_ids' => [201, 202, 203],
        'child_ids' => [],
    ],
    ['kind' => 'delete', 'uuid' => $liveDeleted, 'id' => 41, 'post_type' => 'probe', 'parent_id' => 0],
    ['kind' => 'reparent', 'uuid' => $otherAdapters, 'id' => 77, 'post_type' => 'somebody_else',
     'old_parent_id' => 1, 'new_parent_id' => 2, 'root_ids' => [1, 2]],
];
$reparents = $applyPrivate('action_reparents', [$batchAction, $regenContext]);
$check($reparents === [
    ['kind' => 'post:probe', 'uuid' => $moved, 'id' => 204, 'root_id' => 201,
     'old_parent_id' => 202, 'new_parent_id' => 203],
    ['kind' => 'post:probe', 'uuid' => $moved, 'id' => 204, 'root_id' => 202,
     'old_parent_id' => 202, 'new_parent_id' => 203],
    ['kind' => 'post:probe', 'uuid' => $moved, 'id' => 204, 'root_id' => 203,
     'old_parent_id' => 202, 'new_parent_id' => 203],
], 'the reparents channel normalizes one row per root, so a chained move loses no root to the scalar field grammar');
$check(count(array_filter($reparents, static fn(array $r): bool => $r['uuid'] === $otherAdapters)) === 0,
    'a reparent receipt for an untriggered surface is not delivered either');
$check(count(array_filter($reparents, static fn(array $r): bool => $r['uuid'] === $liveDeleted)) === 0,
    'a delete-kind receipt riding in the same context list is not mistaken for a reparent');

$applyRetry->setValue($apply, false);
$context = $applyPrivate('action_context', [
    $batchAction,
    ['scope' => 'entity', 'context' => ['deletions']],
    $deleteWork,
    $regenContext,
]);
$check(array_keys($context) === ['deletions'] && $context['deletions'] === $deletions,
    'only the declared channel is assembled — an undeclared one costs no work and delivers nothing');
$check($applyPrivate('action_context', [$batchAction, ['scope' => 'entity'], $deleteWork, $regenContext]) === [],
    'a capability declaring no context assembles nothing at all (the pre-DUO-3369 path)');
$applyRetry->setValue($apply, true);
$check($applyPrivate('action_context', [$batchAction, ['scope' => 'entity', 'context' => ['retry']], [], []])
    === ['retry' => true],
    "the retry channel reports the apply_in_progress marker this run's selection already consulted");

echo "\n== the empty-batch skip: narrowed, not loosened ==\n";
$noChannels = ['scope' => 'entity'];
$withDeletions = ['scope' => 'entity', 'context' => ['deletions']];
$alwaysOn = ['scope' => 'entity', 'context' => ['deletions', 'always_on_write']];
$hasWork = static fn(array $declaration, array $entities, array $channels): bool =>
    (bool) $applyPrivate('action_batch_has_work', [$declaration, $entities, $channels]);
$check($hasWork($noChannels, [], []) === false,
    'the DUO-3338 skip survives verbatim: a channel-less capability with an empty entity batch is still skipped');
$check($hasWork($noChannels, [['kind' => 'post:probe', 'id' => 7]], []) === true,
    'a non-empty entity batch is work, as before');
$check($hasWork($withDeletions, [], ['deletions' => $deletions]) === true,
    'a deletion-only selection is NO LONGER skipped once the capability declared the deletions channel');
$check($hasWork($withDeletions, [], ['deletions' => []]) === false,
    'the skip receipt still stands when every declared channel came back empty and nothing was written');
$check($hasWork($alwaysOn, [], ['deletions' => []]) === true,
    "always_on_write fires anyway — the same semantics regen_dependency's flag has for its own cheap check");
$check($hasWork(['scope' => 'entity', 'context' => ['retry']], [], ['retry' => true]) === true
    && $hasWork(['scope' => 'entity', 'context' => ['retry']], [], ['retry' => false]) === false,
    'a retry is work in its own right; an ordinary run with nothing else is not');

echo "\n== byte-compatibility with the pre-DUO-3369 contract, in frozen bytes ==\n";
// Both literals below were captured by running THIS harness's fixtures through
// the engine as of main@40b54fe (the commit before DUO-3369) and printing
// serialize() of the negotiated declaration map and of the arguments the
// capability received. They are the acceptance criterion in executable form:
// an existing scalar-args, channel-less provider must not be able to observe
// that either channel grew.
$reset();
$policy = $policyFor($manifest);
$negotiation = \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
$check(
    serialize($negotiation['capabilities'])
        === 'a:1:{s:11:"probe-cache";a:1:{s:5:"flush";a:6:{s:4:"args";a:1:{s:6:"groups";a:2:{s:4:"type";'
            . 's:12:"list<string>";s:8:"required";b:1;}}s:5:"reads";a:1:{i:0;s:20:"option:probe_setting";}'
            . 's:6:"writes";a:1:{i:0;s:25:"entity:probe-cache-groups";}s:5:"scope";s:4:"site";s:10:'
            . '"idempotent";b:1;s:15:"timeout_seconds";i:30;}}}',
    'a scalar-args capability negotiates to declaration bytes identical to the pre-change engine (no key added, none reordered)'
);
$reset();
\Duo\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity'];
$policy = $policyFor($manifest);
$byteAction = $policy->actions_for(['post:probe'])[0];
$byteAction['triggers'] = ['post:probe'];
$byteProvider = new \Duo\Providers\ProbeCache($policy);
\Duo\Providers::invoke($byteProvider, $byteAction, $byteProvider->capabilities()['flush'], $batch);
$check(
    serialize($byteProvider->calls[0][1])
        === 'a:2:{s:6:"groups";a:1:{i:0;s:11:"probe-group";}s:8:"entities";a:2:{i:0;a:2:{s:4:"kind";'
            . 's:10:"post:probe";s:2:"id";i:7;}i:1;a:2:{s:4:"kind";s:14:"term:probe_tax";s:2:"id";i:3;}}}',
    'a channel-less entity-scoped capability receives the bare batch, byte-identical to the pre-change engine'
);
$reset();

echo "\n== manifest-shipped provider code is part of the manifest artifact ==\n";
unlink($dir . '/providers/probe-cache.php');
$reset();
$policy = $policyFor($manifest);
$expectMessage(
    static fn() => \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe'])),
    'provider code ships with its manifest, not the engine',
    'a missing manifest-shipped provider file is a packaging fault that throws, not an environment problem'
);

echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
