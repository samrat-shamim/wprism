<?php
declare(strict_types=1);

/**
 * Offline regression for DUO-3338's native-action vocabulary and plugin-owned
 * provider contract. No WordPress target, no WP-CLI, no docker: the pieces
 * under test are the closed vocabulary (pure PHP by construction — it runs
 * inside Policy's offline validation pass) and the negotiation gate, whose
 * only live inputs are the four WordPress lifecycle primitives stubbed below.
 *
 * Same idiom as regress_adapter_contract.php: real, unmodified engine files
 * against a scratch DUO_MANIFESTS_DIR holding real fixture bytes.
 *
 * What this deliberately does NOT cover, because it genuinely needs a live
 * WordPress: the shipped providers' own invoke() bodies (WooCommerce's cache
 * boundary is exercised against a fake public API by
 * regress_woocommerce_deletion_authority.php; the CLI-backed ones need a
 * pair), and Apply's placement of the negotiation gate ahead of the first
 * mutation, which is a live-apply property.
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
        'format' => 'duo-policy-snapshot/v3',
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
