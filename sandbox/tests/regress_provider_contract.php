<?php
declare(strict_types=1);

/**
 * Offline regression for DUO-3338's native-action vocabulary and plugin-owned
 * provider contract, extended by DUO-3369's structured capability arguments
 * (`list<object>`) and engine batch context channels. No WordPress target, no
 * WP-CLI, no docker: the pieces under test are the closed vocabulary (pure PHP
 * by construction — it runs inside Policy's offline validation pass), the
 * negotiation gate (whose only live inputs are the four WordPress lifecycle
 * primitives stubbed below), NativeActions' exact transient/cache plus checked
 * option-read runtime boundary, and Apply's per-channel batch assembly (driven
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
// wpdb::get_results()'s output mode, which Ledger's own checked reads pass.
define('ARRAY_A', 'ARRAY_A');

// ---- WordPress lifecycle primitives Deploy::plugin_runtime_state() reads ----
// Defining validate_plugin() also short-circuits Deploy's wp-admin include.
$GLOBALS['duo_test_plugins'] = [];
$GLOBALS['duo_test_active'] = [];
$GLOBALS['duo_test_providers'] = [];
$GLOBALS['duo_test_provider_registry_throw'] = null;

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
    if ($hook === 'duo_providers' && $GLOBALS['duo_test_provider_registry_throw'] !== null) {
        throw new \RuntimeException($GLOBALS['duo_test_provider_registry_throw']);    }
    return $hook === 'duo_providers' ? $GLOBALS['duo_test_providers'] : $value;
}
// Apply::rebuild() flushes the object cache before and after the action loop
// and hard-fails on a false return; the count is asserted by the drive below,
// so the stub is evidence rather than a silencer.
$GLOBALS['duo_test_cache_flushes'] = 0;
function wp_cache_flush(): bool {
    $GLOBALS['duo_test_cache_flushes']++;
    return true;
}
// Apply::rebuild() reschedules future posts for every post-kind work row it is
// given. The DUO-3342 drives below hand it real work rows, so these three are
// the narrow WordPress cron surface that pass touches; each returns the
// non-failure value the pass hard-fails without.
function wp_clear_scheduled_hook(string $hook, array $args = []): int {
    return 0;
}
function wp_schedule_single_event(int $timestamp, string $hook, array $args = []): bool {
    return true;
}
function wp_next_scheduled(string $hook, array $args = []): int|false {
    return false;
}
class WP_Error {
    public function __construct(public string $message = '') {}
}

// ---- WordPress transient primitives NativeActions::execute() reads ----
//
// Model an external/persistent object cache faithfully: array_key_exists(),
// rather than a truthiness check, is what distinguishes a cached boolean
// false from a cache miss. That distinction is the product contract under
// test; transient.delete must not report a false-valued survivor as absent.
final class NativeActionFakeWpdb {
    public string $options = 'wp_options';
    public string $last_error = '';
    /** @var array<string,mixed> option name => stored value */
    public array $optionRows = [];
    /** @var list<string> */
    public array $optionReadNames = [];
    public bool $failNextOptionRead = false;

    public function prepare(string $query, mixed ...$args): string {
        foreach ($args as $arg) {
            $query = preg_replace(
                '/%s/',
                "'" . addslashes((string) $arg) . "'",
                $query,
                1
            ) ?? $query;
        }
        return $query;
    }

    public function get_var(string $query): string|false|null {
        if (!preg_match("/FROM wp_options WHERE option_name = '((?:[^'\\\\]|\\\\.)*)' LIMIT 1/", $query, $match)) {
            throw new \RuntimeException("unexpected NativeActions option query: $query");
        }
        $name = stripslashes($match[1]);
        $this->optionReadNames[] = $name;
        if ($this->failNextOptionRead) {
            $this->failNextOptionRead = false;
            $this->last_error = 'injected transient option read failure';
            return false;
        }
        return array_key_exists($name, $this->optionRows) ? $name : null;
    }
}

$GLOBALS['duo_native_cache'] = [];
$GLOBALS['duo_native_cache_reads'] = [];
$GLOBALS['duo_native_delete_calls'] = [];
$GLOBALS['duo_native_delete_mode'] = 'delete';
$GLOBALS['duo_native_cache_sets_found'] = true;

function wp_cache_get($key, $group = '', $force = false, &$found = null): mixed {
    $group = (string) $group;
    $key = (string) $key;
    $entries = $GLOBALS['duo_native_cache'][$group] ?? [];
    $present = array_key_exists($key, $entries);
    if ($GLOBALS['duo_native_cache_sets_found']) {
        $found = $present;
    }
    $value = $present ? $entries[$key] : false;
    $GLOBALS['duo_native_cache_reads'][] = [
        'key' => $key,
        'group' => $group,
        'arity' => func_num_args(),
        'found' => $present,
        'value' => $value,
    ];
    return $value;
}

function delete_transient($transient): bool {
    global $wpdb;
    $name = (string) $transient;
    $GLOBALS['duo_native_delete_calls'][] = $name;
    if ($GLOBALS['duo_native_delete_mode'] === 'no-op') {
        return false;
    }
    $wasPresent = array_key_exists('_transient_' . $name, $wpdb->optionRows)
        || array_key_exists('_transient_timeout_' . $name, $wpdb->optionRows)
        || array_key_exists($name, $GLOBALS['duo_native_cache']['transient'] ?? []);
    unset($wpdb->optionRows['_transient_' . $name]);
    unset($wpdb->optionRows['_transient_timeout_' . $name]);
    unset($GLOBALS['duo_native_cache']['transient'][$name]);
    return $wasPresent;
}

require $root . '/agent/src/Canon.php';
require $root . '/agent/src/OptionState.php';
require $root . '/agent/src/Policy.php';
require $root . '/agent/src/CodeCompatibility.php';
require $root . '/agent/src/Deploy.php';
require $root . '/agent/src/Providers.php';
// DUO-3339: `duo status`'s renderer is pure and is one half of the documented
// two-renderer lockstep for plan rows, so it is driven directly below.
require $root . '/cli/src/PlanSummary.php';

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
    public static ?array $capabilityMapOverride = null;
    public static array $capabilityOverrides = [];
    public static ?string $capabilitiesThrows = null;
    // A SECOND advertised capability, off by default so every check above sees
    // the one-capability provider it was written against. The channel-collision
    // refusal needs two consumers of one channel on one surface, and two
    // capabilities of one provider is the smallest honest way to build that.
    public static array $extraCapabilities = [];
    public static array $identityOverrides = [];
    public static ?string $identityThrows = null;
    public static ?string $invokeThrows = null;
    public static mixed $receiptOverride = null;
    public static float $sleepSeconds = 0.0;

    public function __construct(\Duo\Policy $policy) {}

    public function identity(): array {
        if (self::$identityThrows !== null) {
            throw new \RuntimeException(self::$identityThrows);
        }
        return self::$identityOverrides + [
            'id' => 'probe-cache',
            'plugin' => 'probe/probe.php',
            'version' => '1.0.0',
        ];
    }

    public function capabilities(): array {
        if (self::$capabilitiesThrows !== null) {
            throw new \RuntimeException(self::$capabilitiesThrows);
        }
        $default = [
            'flush' => self::$capabilityOverrides + [
                'args' => ['groups' => ['type' => 'list<string>', 'required' => true]],
                'reads' => ['option:probe_setting'],
                'writes' => ['entity:probe-cache-groups'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 30,
            ],
        ] + self::$extraCapabilities;
        return self::$capabilityMapOverride ?? $default;
    }

    public function invoke(string $capability, array $args): array {
        $this->calls[] = [$capability, $args];
        if (self::$invokeThrows !== null) {
            throw new \RuntimeException(self::$invokeThrows);
        }
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
// DUO-3339: this harness models a target that HAS WordPress loaded — that is
// what makes negotiating plugin state meaningful here at all — and
// Providers::runtime_negotiation_available() reads exactly the four symbols
// that say so. Three were already present; ABSPATH is the fourth, and without
// it the plan-time diagnosis correctly short-circuits to no findings (the
// group below pins that short-circuit in its own process, where the constant
// genuinely is absent).
define('ABSPATH', $dir . '/wp/');
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
    $GLOBALS['duo_test_provider_registry_throw'] = null;
    \Duo\Providers\ProbeCache::$capabilityMapOverride = null;
    \Duo\Providers\ProbeCache::$capabilityOverrides = [];
    \Duo\Providers\ProbeCache::$capabilitiesThrows = null;
    \Duo\Providers\ProbeCache::$extraCapabilities = [];
    \Duo\Providers\ProbeCache::$identityOverrides = [];
    \Duo\Providers\ProbeCache::$identityThrows = null;
    \Duo\Providers\ProbeCache::$invokeThrows = null;
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

echo "\n== native transient deletion: persistent-cache presence verification ==\n";

// Keep this product-path runtime fake independent from the provider fixture
// below. NativeActions reads wp_options directly so a value-level receipt can
// distinguish real row absence from an empty-looking database failure.
$resetNativeActionRuntime = static function (): void {
    global $wpdb;
    $wpdb = new NativeActionFakeWpdb();
    $GLOBALS['duo_native_cache'] = [];
    $GLOBALS['duo_native_cache_reads'] = [];
    $GLOBALS['duo_native_delete_calls'] = [];
    $GLOBALS['duo_native_delete_mode'] = 'delete';
    $GLOBALS['duo_native_cache_sets_found'] = true;
};
$deleteNativeTransient = static fn(string $name): array => \Duo\NativeActions::execute(
    'transient.delete',
    ['name' => $name]
);

// An absent transient is already converged. delete_transient() may return
// false in that case, so only the fresh cache+option readback proves success.
$resetNativeActionRuntime();
$missName = 'native_cache_miss';
$missReceipt = $deleteNativeTransient($missName);
$check(
    ($missReceipt['before'] ?? null) === ['value_row' => false, 'timeout_row' => false, 'cached' => false]
        && ($missReceipt['after'] ?? null) === ['value_row' => false, 'timeout_row' => false, 'cached' => false]
        && ($missReceipt['verified'] ?? null) === true,
    'a cache miss is verified absent before and after transient.delete'
);
$check($wpdb->optionReadNames === [
    '_transient_' . $missName,
    '_transient_timeout_' . $missName,
    '_transient_' . $missName,
    '_transient_timeout_' . $missName,
], 'cache-miss verification reads both option rows before and after through checked wpdb reads');
$resetNativeActionRuntime();
$wpdb->failNextOptionRead = true;
try {
    $deleteNativeTransient('native_option_read_failure');
    $check(false, 'an option-row read failure is not mistaken for an absent transient');
} catch (\Throwable $t) {
    $check(str_contains($t->getMessage(), 'option-row read failed'),
        'an option-row read failure is not mistaken for an absent transient');
}
$check($GLOBALS['duo_native_delete_calls'] === [],
    'a checked option-row read failure refuses before delete_transient() is called');

// The public cache API owes callers a boolean presence flag. A legacy or
// nonconforming wrapper that leaves it unset cannot prove absence, so the
// action must stop before delete_transient() rather than publish a guess.
$resetNativeActionRuntime();
$GLOBALS['duo_native_cache_sets_found'] = false;
try {
    $deleteNativeTransient('native_missing_found_flag');
    $check(false, 'a cache wrapper that omits the found flag is refused');
} catch (\Throwable $t) {
    $check(str_contains($t->getMessage(), 'did not provide its required found flag'),
        'a cache wrapper that omits the found flag is refused');
}
$check($GLOBALS['duo_native_delete_calls'] === [],
    'an unverifiable cache read refuses before delete_transient() is called');

// A conventional non-false cache value and both option rows must be observed
// in the receipt, then removed by the real NativeActions execution path.
$resetNativeActionRuntime();
$ordinaryName = 'native_ordinary_value';
$wpdb->optionRows = [
    '_transient_' . $ordinaryName => 'persisted-value',
    '_transient_timeout_' . $ordinaryName => '4102444800',
];
$GLOBALS['duo_native_cache']['transient'] = [$ordinaryName => 'cached-value'];
$ordinaryReceipt = $deleteNativeTransient($ordinaryName);
$check(
    ($ordinaryReceipt['before'] ?? null) === ['value_row' => true, 'timeout_row' => true, 'cached' => true]
        && ($ordinaryReceipt['after'] ?? null) === ['value_row' => false, 'timeout_row' => false, 'cached' => false]
        && ($ordinaryReceipt['verified'] ?? null) === true,
    'an ordinary persistent cache value and both option rows are observed then removed'
);
$check($GLOBALS['duo_native_delete_calls'] === [$ordinaryName]
    && $wpdb->optionRows === []
    && !array_key_exists($ordinaryName, $GLOBALS['duo_native_cache']['transient'] ?? []),
    'ordinary transient deletion removes the exact cache key and both option rows');

// A persistent cache can legitimately store boolean false. wp_cache_get()
// returns false for both that value and a miss, so NativeActions must pass and
// honor WordPress's by-reference $found flag rather than test the return value.
$resetNativeActionRuntime();
$falseName = 'native_false_value';
$GLOBALS['duo_native_cache']['transient'] = [$falseName => false];
$falseReceipt = $deleteNativeTransient($falseName);
$check(
    ($falseReceipt['before'] ?? null) === ['value_row' => false, 'timeout_row' => false, 'cached' => true]
        && ($falseReceipt['after'] ?? null) === ['value_row' => false, 'timeout_row' => false, 'cached' => false]
        && ($falseReceipt['verified'] ?? null) === true,
    'a boolean-false cache entry is observed as present and is removed'
);
$check($GLOBALS['duo_native_cache_reads'] === [
    ['key' => $falseName, 'group' => 'transient', 'arity' => 4, 'found' => true, 'value' => false],
    ['key' => $falseName, 'group' => 'transient', 'arity' => 4, 'found' => false, 'value' => false],
], 'NativeActions uses wp_cache_get(..., &$found) to distinguish false from a miss');

// A false return from delete_transient() is ambiguous. If a false-valued cache
// entry survives a no-op/failed delete, post-action readback must throw and no
// receipt may claim verified=true merely because the cached value is false.
$resetNativeActionRuntime();
$failedFalseName = 'native_false_survivor';
$GLOBALS['duo_native_cache']['transient'] = [$failedFalseName => false];
$GLOBALS['duo_native_delete_mode'] = 'no-op';
$failedFalseReceipt = null;
$failedFalseError = null;
try {
    $failedFalseReceipt = $deleteNativeTransient($failedFalseName);
} catch (\Throwable $t) {
    $failedFalseError = $t;
}
$check($failedFalseError instanceof \Throwable
    && str_contains($failedFalseError->getMessage(), 'object cache entry transient/' . $failedFalseName),
    'a no-op delete that leaves a false-valued persistent cache entry is refused');
$check($failedFalseReceipt === null,
    'a surviving false-valued cache entry never returns a verified=true receipt');
$check(array_key_exists($failedFalseName, $GLOBALS['duo_native_cache']['transient'] ?? [])
    && $GLOBALS['duo_native_cache']['transient'][$failedFalseName] === false,
    'the failed-delete fixture genuinely leaves the boolean-false cache entry present for readback');

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
$providerSecret = "https://provider.example.test/rebuild?access_token=DUO_PROVIDER_SECRET\nINJECTED_PROVIDER_LINE";
$opaqueProviderProblem = static function (array $problem): bool {
    $serialized = json_encode($problem, JSON_THROW_ON_ERROR);
    return !str_contains($serialized, 'DUO_PROVIDER_SECRET')
        && !str_contains($serialized, 'INJECTED_PROVIDER_LINE')
        && !str_contains((string) ($problem['found'] ?? ''), "\n");
};
$opaqueProviderRefusal = static function (array $problem, string $code, string $found) use ($opaqueProviderProblem): bool {
    return ($problem['code'] ?? '') === $code
        && ($problem['found'] ?? '') === $found
        && $opaqueProviderProblem($problem);
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

$p = $one($problemFor([], static function () use ($providerSecret): void {
    \Duo\Providers\ProbeCache::$identityOverrides = ['version' => $providerSecret];
}));
$check(($p['code'] ?? '') === 'identity_mismatch'
    && str_contains($p['expected'] ?? '', 'version=1.0.0')
    && ($p['found'] ?? '') === 'identity() did not match the declared provider identity'
    && $opaqueProviderProblem($p),
    'a provider identity mismatch is structured without exposing returned identity values');

$p = $one($problemFor(
    ['actions' => [['capability' => 'purge']], 'providers' => [['capabilities' => ['purge']]]],
    static function () use ($providerSecret): void {
        \Duo\Providers\ProbeCache::$capabilityMapOverride = [$providerSecret => []];
    }
));
$check(($p['code'] ?? '') === 'missing_capability'
    && ($p['found'] ?? '') === 'provider did not advertise the declared capability'
    && $opaqueProviderProblem($p),
    'a missing capability is structured without exposing advertised capability names');

$p = $one($problemFor([], static function (): void {
    \Duo\Providers\ProbeCache::$capabilityOverrides = ['idempotent' => false];
}));
$check(($p['code'] ?? '') === 'non_idempotent_capability' && str_contains($p['remediation'] ?? '', 'retry'),
    "a non-idempotent capability is refused because apply's retry re-fires the rebuild pass");

$p = $one($problemFor(['actions' => [['args' => ['groups' => 'not-a-list']]]]));
$check(($p['code'] ?? '') === 'invalid_capability_args'
    && ($p['found'] ?? '') === 'action arguments do not match advertised schema',
    'manifest arguments that do not match the provider-declared schema are refused without echoing validator text');

$p = $one($problemFor([], static function () use ($providerSecret): void {
    \Duo\Providers\ProbeCache::$capabilityOverrides = [$providerSecret => true];
}));
$check(($p['code'] ?? '') === 'malformed_capability'
    && ($p['found'] ?? '') === 'provider advertised a malformed capability declaration'
    && $opaqueProviderProblem($p),
    'a malformed capability is refused without exposing provider-controlled schema keys');

$p = $one($problemFor([], static function () use ($providerSecret): void {
    \Duo\Providers\ProbeCache::$capabilitiesThrows = $providerSecret;
}));
$check(($p['code'] ?? '') === 'contract_shape'
    && ($p['expected'] ?? '') === 'capabilities() returning a name => declaration map'
    && ($p['found'] ?? '') === 'capabilities() threw'
    && $opaqueProviderProblem($p),
    'a provider whose capabilities() throws becomes a structured, redacted contract problem');

$p = $one($problemFor([], static function () use ($providerSecret): void {
    \Duo\Providers\ProbeCache::$identityThrows = $providerSecret;
}));
$check(($p['code'] ?? '') === 'contract_shape'
    && ($p['expected'] ?? '') === 'identity() returning an array'
    && ($p['found'] ?? '') === 'identity() threw'
    && $opaqueProviderProblem($p),
    'a provider whose identity() throws becomes a structured, redacted contract problem');

echo "\n== the same detection, reported at plan (DUO-3339) ==\n";
// spec/repo-format.md's bound (4) was that negotiation ran at APPLY only, so a
// missing or incompatible provider was invisible until the promotion that
// needed it. It now runs as a read-only question at plan too — and the whole
// value of that depends on it being the SAME question. These checks are what
// makes "same" falsifiable: negotiate() must BE diagnose(), and the rows plan
// reports must be the rows apply would refuse on.
$reset();
$GLOBALS['duo_test_active'] = [];
$policy = $policyFor($manifest);
$selected = $policy->actions_for(['post:probe']);
$negotiated = \Duo\Providers::negotiate($policy, $selected);
$diagnosed = \Duo\Providers::diagnose($policy, $selected);
$check($negotiated == $diagnosed && $negotiated['problems'] !== [],
    'diagnose() and negotiate() return the identical result for a broken selection — one body, not two implementations');
$check(array_column($diagnosed['problems'], 'code') === ['inactive_plugin'],
    'and it is the real problem row, with the real code, not an empty stand-in');

$negotiateSource = implode("\n", array_slice(
    (array) file($root . '/agent/src/Providers.php', FILE_IGNORE_NEW_LINES),
    (new \ReflectionMethod(\Duo\Providers::class, 'negotiate'))->getStartLine() - 1,
    2
));
$check((bool) preg_match('/return self::diagnose\(\$policy, \$selectedActions\);/', $negotiateSource),
    'negotiate() is literally the delegation — a second copy of the loop could pass the equality check above on the '
    . 'day it was written and drift the day after, so the sharing itself is pinned');

$reset();
$GLOBALS['duo_test_active'] = [];
$policy = $policyFor($manifest);
$check(\Duo\Providers::negotiate($policy, $policy->actions_for([]))['problems'] === [],
    'a read-only apply selects no action, so apply negotiates nothing and refuses nothing');
$planProblems = \Duo\Providers::problems($policy);
$check(array_column($planProblems, 'code') === ['inactive_plugin'],
    'while the PLAN view still reports the inactive plugin: it covers every provider action the PINNED manifests '
    . 'declare, so "no problems" can never mean "this run happened to look at nothing"');
$check(($planProblems[0]['manifest'] ?? '') === 'probe' && ($planProblems[0]['plugin'] ?? '') === 'probe/probe.php'
    && trim($planProblems[0]['remediation'] ?? '') !== '',
    'and each row names the declaring manifest, the owning plugin, and a remediation — the three things an operator '
    . 'needs to know which pin to go fix');

$reset();
$policy = $policyFor($manifest);
$check(\Duo\Providers::problems($policy) === [], 'a healthy environment reports no provider problems at plan');

// DUO-3314 shipped the NARROWED, gating diagnosis: build_plan() merges
// Policy::provider_readiness_blockers($selectedActions) into
// adapter_dispositions, which duo status's exit code counts. The wide set must
// therefore not restate what the narrow one already gated on — one fact, one
// row, the same discipline AdapterSources::refuse() applies to installed files.
//
// That method needs a reviewed disposition set and a generated registry, which
// this harness's synthetic manifests dir deliberately has neither of (it exists
// to exercise the negotiation contract, not certification). So the row it would
// promote is BUILT here from the same problem row it starts from, and the field
// mapping is pinned against Policy's own source rather than assumed.
$reset();
$GLOBALS['duo_test_active'] = [];
$policy = $policyFor($manifest);
$wide = \Duo\Providers::problems($policy);
$check(array_column($wide, 'code') === ['inactive_plugin'],
    'the wide plan view reports the inactive plugin when nothing has gated on it yet');
$policySource = (string) file_get_contents($root . '/agent/src/Policy.php');
$check(str_contains($policySource, "'name' => \$manifest,")
    && str_contains($policySource, "'provider' => (string) (\$problem['provider'] ?? '?'),")
    && str_contains($policySource, "'manifest' => \$manifest,")
    && str_contains($policySource, "'code' => (string) (\$problem['code'] ?? 'provider_negotiation_failed'),"),
    'and the gating row Policy promotes carries the same provider, manifest, and code the problem row does — the '
    . 'three fields the dedupe below keys on');
$promoted = [[
    'name' => $wide[0]['manifest'],
    'provider' => $wide[0]['provider'],
    'manifest' => $wide[0]['manifest'],
    'plugin' => $wide[0]['plugin'],
    'code' => $wide[0]['code'],
    'status' => 'blocked',
]];
$check(\Duo\Providers::problems($policy, $promoted) === [],
    'so once that row is gating, the wide plan view reports NOTHING for it — a selected inactive plugin is one '
    . 'finding, not a BLOCKED disposition row plus a PROVIDER_PROBLEM row about the same provider');
$check(array_column(\Duo\Providers::problems($policy), 'code') === ['inactive_plugin'],
    'while the same call with no gating rows still reports it, so the dedupe is subtraction and never suppression');
$check(\Duo\Providers::problems($policy, [['provider' => 'probe-cache', 'manifest' => 'probe', 'code' => 'other']])
    !== [],
    'and the key is (provider, manifest, code): a DIFFERENT code for the same provider is a different finding and survives');
$reset();

// The runtime gate DUO-3314 put on the narrowed diagnosis applies here too:
// with no loaded WordPress there is no plugin state to negotiate against, so
// every declared provider would report `missing_plugin` and an offline
// manifest-library load would manufacture a wall of findings about an
// environment it cannot see. This harness deliberately DOES define the four
// symbols that say WordPress is loaded, so the short-circuit is proved in a
// child process that defines three of them and omits ABSPATH — a constant
// cannot be undefined once set.
$gateProbe = $dir . '/gate-probe.php';
file_put_contents($gateProbe, <<<'PROBE'
<?php
// Deliberately NO define('ABSPATH', ...) — that is the whole subject.
define('DUO_SPEC_VERSION', 2);
define('WP_PLUGIN_DIR', __DIR__ . '/wp-plugins');
function apply_filters(string $hook, mixed $value): mixed { return $value; }
function get_option(string $name, mixed $default = false): mixed { return $default; }
function is_multisite(): bool { return false; }
$root = dirname(__DIR__, 1);
PROBE
. "\n\$engine = " . var_export($root, true) . ";\n"
. <<<'PROBE'
require $engine . '/agent/src/Canon.php';
require $engine . '/agent/src/OptionState.php';
require $engine . '/agent/src/Policy.php';
require $engine . '/agent/src/CodeCompatibility.php';
require $engine . '/agent/src/Deploy.php';
require $engine . '/agent/src/Providers.php';
putenv('DUO_MANIFESTS_DIR=' . __DIR__);
$manifest = json_decode(getenv('DUO_PROBE_MANIFEST'), true);
$policy = Duo\Policy::from_snapshot([
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
echo json_encode([
    'gate' => Duo\Providers::runtime_negotiation_available(),
    'problems' => count(Duo\Providers::problems($policy)),
    'declared' => count(array_filter(
        $policy->actions(),
        static fn(array $a): bool => ($a['kind'] ?? null) === 'provider'
    )),
]), "
";
PROBE
);
$gateOut = [];
exec(
    'DUO_PROBE_MANIFEST=' . escapeshellarg((string) json_encode($manifest)) . ' '
    . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($gateProbe) . ' 2>&1',
    $gateOut,
    $gateRc
);
$gate = json_decode(implode("\n", $gateOut), true);
$check(is_array($gate) && $gateRc === 0 && $gate['gate'] === false,
    'without ABSPATH the runtime negotiation gate reads false (child process said: ' . implode(' ', $gateOut) . ')');
$check(is_array($gate) && ($gate['declared'] ?? 0) === 1 && ($gate['problems'] ?? null) === 0,
    'and problems() reports NOTHING there even though the pinned manifest declares a provider action — an offline '
    . 'library load cannot manufacture findings about an environment it cannot see');
@unlink($gateProbe);

// Both halves of the plan-time posture, on one fixture: apply throws the
// packaging fault (asserted in this file's final group, unchanged), and the
// reporting surface turns it into a row instead of dying on it.
$reset();
$policy = $policyFor($manifest);
rename($dir . '/providers/probe-cache.php', $dir . '/providers/probe-cache.php.hidden');
$faultProblems = \Duo\Providers::problems($policy);
rename($dir . '/providers/probe-cache.php.hidden', $dir . '/providers/probe-cache.php');
$check(count($faultProblems) === 1 && ($faultProblems[0]['code'] ?? '') === 'provider_code_unavailable'
    && str_contains($faultProblems[0]['found'] ?? '', 'provider code ships with its manifest'),
    'a packaging fault reaches the plan view as a ROW carrying the engine\'s own message, rather than taking the '
    . 'whole plan down the way it (correctly) takes an apply down');
$check(($faultProblems[0]['provider'] ?? '') === 'probe-cache'
    && ($faultProblems[0]['manifest'] ?? '') === 'probe'
    && str_contains($faultProblems[0]['remediation'] ?? '', 'providers/probe-cache.php')
    && !str_contains($faultProblems[0]['remediation'] ?? '', '<id>'),
    'and the row names the real provider, the real declaring manifest, and the real file to repair — not a '
    . 'literal <id> placeholder standing in for a coordinate nobody looked up');

// The OTHER branch, and the reason the two are not one code. DUO-3314 has
// since converted every previously reachable foreign-throw path in diagnose()
// into a structured problem row of its own — a `duo_providers` registry that
// throws is now `provider_registry_unavailable`, and identity()/capabilities()
// throwing are `contract_shape` — so the generic branch is a backstop with no
// reachable trigger left in this fixture. It is asserted against source rather
// than faked with a contrived throw: what matters is that a future unexpected
// throw is NOT labelled as the adapter's packaging fault and does NOT invent a
// providers/<id>.php coordinate for an identity nobody established.
$problemsSource = implode("\n", array_slice(
    (array) file($root . '/agent/src/Providers.php', FILE_IGNORE_NEW_LINES),
    (new \ReflectionMethod(\Duo\Providers::class, 'problems'))->getStartLine() - 1,
    (new \ReflectionMethod(\Duo\Providers::class, 'problems'))->getEndLine()
        - (new \ReflectionMethod(\Duo\Providers::class, 'problems'))->getStartLine() + 1
));
$check(str_contains($problemsSource, 'catch (ProviderPackagingException $t)')
    && str_contains($problemsSource, 'catch (\Throwable $t)'),
    'problems() catches the adapter packaging fault SEPARATELY from anything else that could throw');
$check(str_contains($problemsSource, "'provider_diagnosis_failed'")
    && str_contains($problemsSource, "'see the message"),
    'and the generic branch has its own code and points at the message instead of inventing a file to repair');
$check(substr_count($problemsSource, 'providers/') === 1
    && !str_contains($problemsSource, '<id>'),
    'while only the packaging branch names a providers/ path at all, and never as a literal <id> placeholder');
$reset();

// Apply::plan() is not offline-drivable (it loads policy, compiles the
// repository, snapshots a live target, and ensures a ledger), so its one
// threading edge is asserted against its own source — the idiom this file
// already uses for Apply::run() further down. Read textually rather than by
// reflection because Apply.php is not loaded until the batch-assembly group
// below; the slice boundaries are the two method signatures themselves, so a
// moved method does not silently widen what is being asserted.
$applySource = (string) file_get_contents($root . '/agent/src/Apply.php');
$planAt = strpos($applySource, 'public static function plan(');
$buildPlanAt = strpos($applySource, 'private function build_plan(');
$check($planAt !== false && $buildPlanAt !== false && $planAt < $buildPlanAt,
    'Apply::plan() and Apply::build_plan() are both present, in that order (the slice below depends on it)');
$planSource = substr($applySource, (int) $planAt, (int) $buildPlanAt - (int) $planAt);
$check((bool) preg_match(
    "/\\\$plan\\['provider_problems'\\]\s*=\s*Providers::problems\(\s*\\\$policy,"
    . "\s*\\\$plan\\['adapter_dispositions'\\] \?\? \[\]\s*\);/",
    $planSource
),
    "Apply::plan() attaches the plan-time diagnosis AND hands it the plan's already-gating rows, so the wide set "
    . 'and the narrowed one cannot report the same fact twice');
$afterPlan = substr($applySource, (int) $buildPlanAt);
$check(!str_contains($afterPlan, 'Providers::problems') && !str_contains($afterPlan, 'Providers::diagnose'),
    'and nothing from build_plan() onward calls it: run() calls build_plan() twice around its own negotiation gate, '
    . 'so diagnosing there would construct every declared provider three times per apply and move the first '
    . 'construction ahead of the promotion lease, for a report apply never reads');

// The two plan-row renderers are required to stay in lockstep (they are the
// same advice to one operator through two commands). PlanSummary::render() is
// pure and is driven for real; agent/src/Cli.php's half runs only inside a
// wp-cli plan, so it is asserted against its source.
$summary = \Duo\Orchestrator\PlanSummary::render(['provider_problems' => $planProblems]);
$summaryText = implode("\n", $summary['lines']);
$check(str_contains($summaryText, '1 provider_problems'), 'duo status counts provider problems in its summary line');
$check(str_contains($summaryText, 'PROVIDER_PROBLEM (')
    && str_contains($summaryText, 'manifest=probe plugin=probe/probe.php')
    && str_contains($summaryText, '[inactive_plugin]')
    && str_contains($summaryText, '    remediation: '),
    'and renders the provider, its declaring manifest, its owning plugin, the code, and the remediation');
$check($summary['ok'] === true,
    'but does NOT by itself flip readiness: the diagnosis covers every DECLARED provider action, which is wider than '
    . 'the set any one apply negotiates, so "will promoting this revision refuse?" is still answered by the buckets '
    . 'that predict a refusal');
$check(\Duo\Orchestrator\PlanSummary::render(['conflict' => [['uuid' => 'x', 'type' => 'post']]])['ok'] === false,
    'while a bucket that DOES predict a refusal still flips it — the exclusion above is about width, not severity');

$cliSource = (string) file_get_contents($root . '/agent/src/Cli.php');
$check(str_contains($cliSource, "foreach (\$plan['provider_problems'] ?? [] as \$r) {")
    && str_contains($cliSource, "'PROVIDER_PROBLEM '")
    && str_contains($cliSource, "count(\$plan['provider_problems'] ?? []) . ' provider_problems'"),
    'and `wp duo plan` renders and counts the same rows, so the two commands never give one operator different advice');

echo "\n== plugin-sourced providers (a custom plugin advertising its own) ==\n";
$pluginSourced = array_replace_recursive($manifest, ['providers' => [['source' => 'plugin']]]);
$reset();
$policy = $policyFor($pluginSourced);
$p = $one(\Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']))['problems']);
$check(($p['code'] ?? '') === 'missing_plugin_provider' && str_contains($p['expected'] ?? '', 'duo_providers'),
    'a plugin-sourced provider nobody registered is a negotiation problem, not a load crash');

$registryUnavailableManifest = $pluginSourced;
$registryUnavailableManifest['providers'][] = [
    'id' => 'probe-second',
    'version' => '1.0.0',
    'source' => 'plugin',
    'plugin' => 'probe/probe.php',
    'capabilities' => ['flush'],
];
$registryUnavailableManifest['actions'][] = [
    'kind' => 'provider',
    'provider' => 'probe-second',
    'capability' => 'flush',
    'args' => ['groups' => ['probe-group']],
];
$reset();
$GLOBALS['duo_test_provider_registry_throw'] = $providerSecret;
$registryUnavailablePolicy = $policyFor($registryUnavailableManifest);
$registryProblems = \Duo\Providers::negotiate(
    $registryUnavailablePolicy,
    $registryUnavailablePolicy->actions_for(['post:probe'])
)['problems'];
$registryProblemJson = json_encode($registryProblems, JSON_THROW_ON_ERROR);
$registryProblemsAreOpaque = count($registryProblems) === 2;
$registryProblemProviders = array_map(
    static fn(array $problem): string => (string) ($problem['provider'] ?? ''),
    $registryProblems
);
sort($registryProblemProviders, SORT_STRING);
foreach ($registryProblems as $problem) {
    $registryProblemsAreOpaque = $registryProblemsAreOpaque
        && ($problem['code'] ?? '') === 'provider_registry_unavailable'
        && ($problem['expected'] ?? '') === 'a readable `duo_providers` registry'
        && ($problem['found'] ?? '') === 'provider registry callback failed'
        && ($problem['remediation'] ?? '') === 'upgrade or disable the faulty provider plugin and retry'
        && !str_contains((string) ($problem['found'] ?? ''), "\n");
}
$check(
    $registryProblemsAreOpaque
    && $registryProblemProviders === ['probe-cache', 'probe-second']
    && !str_contains($registryProblemJson, 'DUO_PROVIDER_SECRET')
    && !str_contains($registryProblemJson, 'INJECTED_PROVIDER_LINE'),
    'a throwing duo_providers registry blocks every selected plugin provider with one structured, redacted remediation'
);

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

$reset();
$GLOBALS['duo_test_providers'] = [
    new class {
        public function identity(): array {
            return ['id' => new \stdClass()];
        }
    },
    new class($providerSecret) {
        public function __construct(private string $secret) {}

        public function identity(): array {
            return ['id' => new class($this->secret) {
                public function __construct(private string $secret) {}

                public function __toString(): string {
                    throw new \RuntimeException($this->secret);
                }
            }];
        }
    },
    new \Duo\Providers\ProbeSupplied(),
];
$negotiation = \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
$malformedIdentityJson = json_encode($negotiation, JSON_THROW_ON_ERROR);
$check(
    $negotiation['problems'] === []
    && isset($negotiation['providers']['probe-cache'])
    && !str_contains($malformedIdentityJson, 'DUO_PROVIDER_SECRET')
    && !str_contains($malformedIdentityJson, 'INJECTED_PROVIDER_LINE'),
    'malformed or throwing Stringable plugin registration ids are skipped without a fatal or public payload leak'
);

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
$receiptSecret = "https://provider.example.test/receipt?access_token=DUO_RECEIPT_SECRET\nINJECTED_RECEIPT_LINE";
$reset();
\Duo\Providers\ProbeCache::$receiptOverride = [$receiptSecret => true];
try {
    \Duo\Providers::invoke($provider, $action, $declaration, []);
    $check(false, 'a malformed receipt never publishes provider-returned receipt keys');
} catch (\Throwable $t) {
    $message = $t->getMessage();
    $check(
        str_contains($message, "provider 'probe-cache' capability 'flush'")
        && str_contains($message, 'exactly before, after, and verified are required')
        && !str_contains($message, 'DUO_RECEIPT_SECRET')
        && !str_contains($message, 'INJECTED_RECEIPT_LINE')
        && !str_contains($message, "\n"),
        'a malformed receipt error preserves provider/capability and required shape without exposing returned keys'
    );
}
$invokeSecret = "https://provider.example.test/invoke?access_token=DUO_INVOKE_SECRET\nINJECTED_INVOKE_LINE";
$reset();
\Duo\Providers\ProbeCache::$invokeThrows = $invokeSecret;
try {
    \Duo\Providers::invoke($provider, $action, $declaration, []);
    $check(false, 'a provider invocation failure never publishes the provider throwable');
} catch (\Throwable $t) {
    $message = $t->getMessage();
    $rendered = (string) $t;
    $check(
        str_contains($message, "provider 'probe-cache' capability 'flush' failed")
        && !str_contains($message, 'DUO_INVOKE_SECRET')
        && !str_contains($message, 'INJECTED_INVOKE_LINE')
        && !str_contains($message, "\n")
        && !str_contains($rendered, 'DUO_INVOKE_SECRET')
        && !str_contains($rendered, 'INJECTED_INVOKE_LINE')
        && $t->getPrevious() === null,
        'a provider invocation failure preserves provider/capability but redacts its throwable chain'
    );
}
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
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a list<object> declaration without `fields` is refused through an opaque public capability diagnostic');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => []]],
    ['rows' => $goodRows]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'an EMPTY fields map is refused through an opaque public capability diagnostic');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => ['kind', 'id']]],
    ['rows' => $goodRows]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a fields LIST (rather than a name => rule map) is refused through an opaque public capability diagnostic');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => [
        'Kind Of Thing' => ['type' => 'string', 'required' => true],
    ]]],
    ['rows' => $goodRows]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a field name outside the bounded argument-name charset is refused without publishing the provider field name');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => [
        'kind' => ['type' => 'string', 'required' => true, 'default' => 'post'],
    ]]],
    ['rows' => $goodRows]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'an unknown key inside a field rule is refused without publishing the provider schema');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => [
        'nested' => ['type' => 'list<object>', 'required' => true],
    ]]],
    ['rows' => $goodRows]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a list<object> INSIDE a list<object> is refused through an opaque public capability diagnostic');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => [
        'tags' => ['type' => 'list<string>', 'required' => true],
    ]]],
    ['rows' => $goodRows]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a list-typed row field is refused through an opaque public capability diagnostic');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<object>', 'required' => true, 'fields' => [
        'kind' => ['type' => 'string', 'required' => 'yes'],
    ]]],
    ['rows' => $goodRows]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a non-boolean field `required` is refused through an opaque public capability diagnostic');

$p = $rowsProblem(
    ['rows' => ['type' => 'list<string>', 'required' => true, 'fields' => $rowFields]],
    ['rows' => ['a']]
);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a scalar-typed argument may not carry a `fields` map through an opaque public capability diagnostic');

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
$check($opaqueProviderRefusal(
    $p,
    'invalid_capability_args',
    'action arguments do not match advertised schema'
), 'an undeclared row field is refused without publishing the provider field vocabulary');

$p = $rowsProblem($rowsArgs, ['rows' => [['kind' => 'post:probe']]]);
$check($opaqueProviderRefusal(
    $p,
    'invalid_capability_args',
    'action arguments do not match advertised schema'
), 'a missing required row field is refused through an opaque public argument diagnostic');

$p = $rowsProblem($rowsArgs, ['rows' => [['kind' => 'post:probe', 'id' => '7']]]);
$check($opaqueProviderRefusal(
    $p,
    'invalid_capability_args',
    'action arguments do not match advertised schema'
), 'a mistyped row field is refused through an opaque public argument diagnostic');

$p = $rowsProblem($rowsArgs, ['rows' => [['kind' => 'post:probe', 'id' => 7], 'not-an-object']]);
$check($opaqueProviderRefusal(
    $p,
    'invalid_capability_args',
    'action arguments do not match advertised schema'
), 'a scalar where a row belongs is refused through an opaque public argument diagnostic');

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
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'an unknown channel is refused without publishing the provider-declared token');

$p = $channelProblem(['deletions', 'deletions']);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a duplicated channel is refused through an opaque public capability diagnostic');

$p = $channelProblem([]);
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'an empty context list is refused through an opaque public capability diagnostic');

$p = $channelProblem('deletions');
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'a bare channel string is refused through an opaque public capability diagnostic');

$p = $channelProblem(['deletions', 'reparents'], 'site');
$check($opaqueProviderRefusal(
    $p,
    'malformed_capability',
    'provider advertised a malformed capability declaration'
), 'context on a scope: site capability is refused without publishing declared channels');

echo "\n== internal schema validator detail stays private ==\n";
// Public readiness rows intentionally collapse untrusted provider schemas to
// fixed labels. The private validator still has exact author-facing detail;
// keep representative assertions here so redaction does not weaken the
// list<object> or context grammar itself.
$validateCapabilityDeclaration = new \ReflectionMethod(\Duo\Providers::class, 'validate_capability_declaration');
$expectInternalDeclarationRefusal = static function (array $decl, string $needle, string $label) use (
    $check, $validateCapabilityDeclaration
): void {
    try {
        $validateCapabilityDeclaration->invoke(null, $decl, 'probe capability');
        $check(false, $label);
    } catch (\Throwable $t) {
        $check(str_contains($t->getMessage(), $needle), $label . ' (validator: ' . $t->getMessage() . ')');
    }
};
$capabilityDeclaration = static function (array $args, string $scope = 'site', mixed $context = null): array {
    $decl = [
        'args' => $args,
        'idempotent' => true,
        'reads' => ['option:probe_setting'],
        'scope' => $scope,
        'timeout_seconds' => 30,
        'writes' => ['entity:probe-cache-groups'],
    ];
    if ($context !== null) {
        $decl['context'] = $context;
    }
    return $decl;
};
$expectInternalDeclarationRefusal(
    $capabilityDeclaration(['rows' => ['type' => 'list<object>', 'required' => true]]),
    'closed field vocabulary',
    'the private declaration validator keeps the list<object> field-vocabulary detail'
);
$expectInternalDeclarationRefusal(
    $capabilityDeclaration(['groups' => ['type' => 'list<string>', 'required' => true]], 'entity', ['deletions', 'tombstones']),
    "names 'tombstones'",
    'the private declaration validator keeps the closed context-channel detail'
);

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
$args = $invokeWith(['always_on_write' => true], ['always_on_write']);
$check($args['entities'] === ['entities' => $batch, 'always_on_write' => true],
    'always_on_write rides as a boolean flag of its own — a passed fact, not a silent change of whether the capability ran');
$args = $invokeWith(
    ['always_on_write' => true, 'deletions' => $deletionRows, 'retry' => false],
    ['retry', 'deletions', 'always_on_write']
);
$check(array_keys($args['entities']) === ['entities', 'always_on_write', 'deletions', 'retry'],
    "the key order is the engine's, not the declaration's — a differently-ordered declaration receives the identical envelope");

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
// unmodified Apply.php; the only fake is the wpdb the ledger's identity and
// marker reads go through. The section after this one drives the whole
// rebuild() pass through the same fake, so the edges BETWEEN these projections
// and Providers::invoke() are covered too; what stays live-only is the rest of
// that pass (term recounts, attachment metadata, cron rescheduling).
require $root . '/agent/src/Db.php';
require $root . '/agent/src/Ledger.php';
require $root . '/agent/src/Apply.php';

final class ProbeBatchWpdb {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $last_error = '';
    /** @var array<string,int> "<uuid>\0<id_kind>" => local id */
    public array $map = [];
    /** @var array<int,array{post_type:string,post_parent:int}> */
    public array $postsRows = [];
    /** @var array<string,string> the duo_kv keyspace (markers) */
    public array $kv = [];

    public function prepare(string $query, ...$args): string {
        foreach ($args as $arg) {
            $value = is_int($arg) || is_float($arg) ? (string) $arg : "'" . addslashes((string) $arg) . "'";
            $query = (string) preg_replace('/%[dsif]/', $value, $query, 1);
        }
        return $query;
    }

    public function query(string $query): int {
        if (preg_match("/INSERT INTO wp_duo_kv .*VALUES \\('((?:[^'\\\\]|\\\\.)*)', '((?:[^'\\\\]|\\\\.)*)'\\)/", $query, $m)) {
            $this->kv[stripslashes($m[1])] = stripslashes($m[2]);
            return 1;
        }
        if (preg_match("/DELETE FROM wp_duo_kv WHERE k = '((?:[^'\\\\]|\\\\.)*)'/", $query, $m)) {
            unset($this->kv[stripslashes($m[1])]);
            return 1;
        }
        return 1;
    }

    public function get_results(string $query, $output = null): array {
        if (!str_contains($query, 'SELECT k, v FROM wp_duo_kv')) {
            return [];
        }
        return array_map(
            static fn(string $k, string $v): array => ['k' => $k, 'v' => $v],
            array_keys($this->kv),
            array_values($this->kv)
        );
    }

    public function get_row(string $query, $output = null): ?array {
        if (preg_match('/FROM wp_posts WHERE ID = (\d+)/', $query, $m)) {
            return $this->postsRows[(int) $m[1]] ?? null;
        }
        return null;
    }

    /** Term recounts find no term_taxonomy rows, so the rebuild pass walks past them. */
    public function get_col(string $query): array {
        return [];
    }

    public function get_var(string $query): mixed {
        if (preg_match("/SELECT local_id FROM wp_duo_map WHERE uuid = '([^']+)' AND id_kind = '([^']+)'/", $query, $m)) {
            return $this->map[$m[1] . "\0" . $m[2]] ?? null;
        }
        if (preg_match("/SELECT uuid FROM wp_duo_map WHERE id_kind = '([^']+)' AND local_id = (\\d+)/", $query, $m)) {
            foreach ($this->map as $key => $id) {
                [$uuid, $kind] = explode("\0", $key, 2);
                if ($kind === $m[1] && (int) $id === (int) $m[2]) {
                    return $uuid;
                }
            }
            return null;
        }
        if (preg_match("/SELECT v FROM wp_duo_kv WHERE k = '((?:[^'\\\\]|\\\\.)*)'/", $query, $m)) {
            return $this->kv[stripslashes($m[1])] ?? null;
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
$oldParent = '66666666-6666-4666-8666-666666666666';
$newParent = '77777777-7777-4777-8777-777777777777';
// A tombstone applied by THIS run still resolves (Ledger::forget() runs in the
// ledger transaction after the rebuild pass); one already applied by a prior
// incomplete run may not.
$wpdb->map = [
    $liveDeleted . "\0post" => 41,
    $liveTerm . "\0term" => 9,
    $otherAdapters . "\0post" => 77,
    $moved . "\0post" => 204,
    $oldParent . "\0post" => 202,
    $newParent . "\0post" => 203,
];
// The moved post as the target still holds it: capture reads the CURRENT
// post_parent before phase 1 rewrites it.
$wpdb->postsRows = [204 => ['post_type' => 'probe', 'post_parent' => 202]];

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
// DUO-3342: every row carries all six keys, including for a tombstone the
// engine took no pre-delete inventory of — a consumer must be able to tell "no
// children" from "the engine did not say", and an absent key collapses those.
$deletionRow = static function (
    string $surface,
    string $uuid,
    int $id,
    string $postType = '',
    int $parentId = 0,
    array $childIds = []
): array {
    return [
        'kind' => $surface,
        'uuid' => $uuid,
        'id' => $id,
        'post_type' => $postType,
        'parent_id' => $parentId,
        'child_ids' => $childIds,
    ];
};
$deletions = $applyPrivate('action_deletions', [$batchAction, $deleteWork]);
$check($deletions === [
    $deletionRow('post:probe', $liveDeleted, 41, 'probe'),
    $deletionRow('post:probe', $forgottenDeleted, 0, 'probe'),
    $deletionRow('term:probe_tax', $liveTerm, 9),
], 'the deletions channel carries {kind, uuid, id, post_type, parent_id, child_ids} for triggered tombstones '
    . 'only, ordered by surface then uuid');
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
$check($applyPrivate('action_context', [
        $batchAction,
        ['scope' => 'entity', 'context' => ['always_on_write']],
        [],
        [],
    ]) === ['always_on_write' => true],
    'always_on_write is assembled as the flag it is — true because it was declared, not because anything happened');

echo "\n== the empty-batch skip: narrowed, not loosened ==\n";
$noChannels = ['scope' => 'entity'];
$withDeletions = ['scope' => 'entity', 'context' => ['deletions']];
$alwaysOn = ['scope' => 'entity', 'context' => ['deletions', 'always_on_write']];
$alwaysOnAlone = ['scope' => 'entity', 'context' => ['always_on_write']];
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
$check($hasWork($alwaysOn, [], ['always_on_write' => true, 'deletions' => []]) === false,
    'always_on_write does NOT manufacture work: with nothing written and its other channel empty, the skip still stands');
$check($hasWork($alwaysOnAlone, [], ['always_on_write' => true]) === false,
    'a capability declaring ONLY always_on_write is skipped on an empty run — the flag it mirrors suppresses a '
    . 'per-candidate check, it never creates a candidate');
$check($hasWork($alwaysOn, [], ['always_on_write' => true, 'deletions' => $deletions]) === true,
    'the same capability fires the moment a real channel carries something');
$check($hasWork(['scope' => 'entity', 'context' => ['retry']], [], ['retry' => true]) === true
    && $hasWork(['scope' => 'entity', 'context' => ['retry']], [], ['retry' => false]) === false,
    'a retry is work in its own right; an ordinary run with nothing else is not');

echo "\n== the rebuild pass: the edges between those projections and invoke() ==\n";
// Everything above proves what each helper RETURNS. Three edges live one frame
// out and are invisible to those checks: which tombstone set the deletions
// channel is composed from, whether the retry state reaches the envelope, and
// whether the assembled context reaches Providers::invoke() at all. rebuild()
// is drivable offline with the same fake wpdb (its other steps — regen
// dispatch, cron rescheduling, term recounts, attachment metadata — all walk
// past an empty work set and an empty term_taxonomy), so the edges are proven
// here rather than asserted.
$applyWarnings = $applyClass->getProperty('warnings');
$applyReceipts = $applyClass->getProperty('actionReceipts');
$applySelected = $applyClass->getProperty('selectedActions');
$applyNegotiated = $applyClass->getProperty('negotiatedProviders');
$rebuildMethod = $applyClass->getMethod('rebuild');

$drivePolicy = $policyFor($manifest);
$driveAction = $drivePolicy->actions_for(['post:probe'])[0];
$driveAction['triggers'] = ['post:probe'];

/**
 * One rebuild() pass with a scratch provider in the negotiated slot, returning
 * what the capability received plus the pass's own warnings and receipts. A
 * throw comes back as a value rather than a fatal, so a broken edge reads as a
 * FAIL line instead of exit 255.
 *
 * $rebuildArgs is rebuild()'s own parameter list:
 * [attachmentIds, work, tree, regenContext, deleteWork, withDeletes, absentTombstones].
 */
$driveRebuild = static function (
    array $channels,
    array $rebuildArgs,
    bool $retrying = false,
    mixed $receiptOverride = null,
    ?\Duo\Policy $policyOverride = null
) use (
    $applyClass, $applyPolicy, $applyRetry, $applyWarnings, $applyReceipts, $applySelected,
    $applyNegotiated, $rebuildMethod, $drivePolicy, $driveAction, $reset
): array {
    $reset();
    \Duo\Providers\ProbeCache::$receiptOverride = $receiptOverride;
    \Duo\Providers\ProbeCache::$capabilityOverrides = $channels === []
        ? ['scope' => 'entity']
        : ['scope' => 'entity', 'context' => $channels];
    // The policy the PASS reads (pinned actions, marker ownership) may differ
    // from the one that built $driveAction: the sweep is deliberately
    // run-independent, so proving a narrowing needs a pinned claimant the
    // selection does not contain.
    $passPolicy = $policyOverride ?? $drivePolicy;
    $provider = new \Duo\Providers\ProbeCache($passPolicy);
    $apply = $applyClass->newInstanceWithoutConstructor();
    $applyPolicy->setValue($apply, $passPolicy);
    $applyRetry->setValue($apply, $retrying);
    $applySelected->setValue($apply, [$driveAction]);
    $applyNegotiated->setValue($apply, [
        'providers' => ['probe-cache' => $provider],
        'capabilities' => ['probe-cache' => ['flush' => $provider->capabilities()['flush']]],
    ]);
    $error = '';
    try {
        $rebuildMethod->invokeArgs($apply, $rebuildArgs);
    } catch (\Throwable $t) {
        $error = $t->getMessage();
    }
    return [
        'args' => $provider->calls[0][1] ?? null,
        'calls' => count($provider->calls),
        'warnings' => implode("\n", (array) $applyWarnings->getValue($apply)),
        'receipts' => (array) $applyReceipts->getValue($apply),
        'error' => $error,
    ];
};

$driveTombstones = [
    ['uuid' => $liveDeleted, 'type' => 'post', 'deletion_kind' => 'post', 'deletion_type' => 'probe'],
];
$driveAbsent = [
    ['uuid' => $forgottenDeleted, 'type' => 'post', 'deletion_kind' => 'post', 'deletion_type' => 'probe'],
];

$wpdb->kv = [];
$flushesBefore = $GLOBALS['duo_test_cache_flushes'];
$run = $driveRebuild(['deletions'], [[], [], [], [], $driveTombstones, true, []]);
$check($run['error'] === '' && $run['calls'] === 1
    && ($run['args']['entities']['deletions'] ?? null)
        === [$deletionRow('post:probe', $liveDeleted, 41, 'probe')],
    'the tombstones a --with-deletes run APPLIED reach the capability through the whole pass, not just the projection');
$check(array_keys((array) ($run['args']['entities'] ?? [])) === ['entities', 'deletions']
    && ($run['args']['entities']['entities'] ?? null) === [],
    'the assembled context reaches invoke() as the envelope — a bare batch here would mean the pass dropped it');
$check($GLOBALS['duo_test_cache_flushes'] === $flushesBefore + 2,
    'the pass still flushes the object cache either side of the action loop (the drive is the real rebuild(), not a stub)');

$run = $driveRebuild(['deletions'], [[], [], [], [], $driveTombstones, false, []]);
$check($run['error'] === '' && $run['calls'] === 0,
    'THE FAIL-OPEN CASE: the identical tombstone selection without --with-deletes invokes nothing — those entities '
    . 'were planned for deletion and are all still present');
$check(($run['receipts'][0]['skipped'] ?? '')
    === 'empty entity batch and no declared batch channel carried work (deletions empty)'
    && str_contains($run['warnings'], 'no declared batch channel carried work: deletions empty'),
    'and the skip is explicit in both the receipt and the human line, naming the channel that came back empty');

$run = $driveRebuild(['deletions', 'retry'], [[], [], [], [], [], false, $driveAbsent], true);
$check($run['error'] === '' && $run['calls'] === 1
    && ($run['args']['entities']['retry'] ?? null) === true
    && ($run['args']['entities']['deletions'] ?? null)
        === [$deletionRow('post:probe', $forgottenDeleted, 0, 'probe')],
    "while retrying an incomplete apply, the run's retry state and its already-absent tombstones both reach invoke()");
$run = $driveRebuild(['deletions', 'retry'], [[], [], [], [], [], false, $driveAbsent], false);
$check($run['error'] === '' && $run['calls'] === 0,
    'the same already-absent rows on an ordinary run are not evidence about this run: nothing is delivered, nothing fires');
$check(str_contains($run['warnings'], 'deletions empty, retry false'),
    'the skip line renders a declared boolean channel as false rather than as "empty" (an empty list it is not)');

$run = $driveRebuild(['always_on_write'], [[], [], [], [], $driveTombstones, true, []]);
$check($run['error'] === '' && $run['calls'] === 0,
    'a capability declaring ONLY always_on_write is skipped on a run whose declared channels carry nothing');
$check(str_contains(
    (string) ($run['receipts'][0]['skipped'] ?? ''),
    'always_on_write (a flag; never work of its own)'
), 'and the receipt names the flag for what it is instead of calling a boolean empty');

$reparentMarker = json_encode([
    'kind' => 'reparent', 'uuid' => $moved, 'id' => 204, 'post_type' => 'probe',
    'parent_id' => 202, 'old_parent_id' => 202, 'new_parent_id' => 203,
    'root_ids' => [201, 202, 203], 'child_ids' => [],
]);
$wpdb->kv = ['regen_reparent_context:' . $moved => (string) $reparentMarker];
$run = $driveRebuild(['reparents'], [[], [], [], [], [], false, []]);
$check($run['error'] === '' && $run['calls'] === 1
    && array_column((array) ($run['args']['entities']['reparents'] ?? []), 'root_id') === [201, 202, 203],
    'an outstanding reparent marker with NO fresh capture this run still reaches a reparents-declaring capability');
$check(!isset($wpdb->kv['regen_reparent_context:' . $moved]),
    'and it had to be read before the regen pass, which sweeps a marker no batch regenerator can consume — the '
    . 'ordering is load-bearing, not incidental');

$wpdb->kv = ['regen_reparent_context:' . $moved => (string) $reparentMarker];
$run = $driveRebuild(['reparents'], [[], [], [], [
    ['kind' => 'reparent', 'uuid' => $moved, 'id' => 204, 'post_type' => 'probe',
     'old_parent_id' => 203, 'new_parent_id' => 205, 'root_ids' => [203, 205]],
], [], false, []]);
$unionRows = (array) ($run['args']['entities']['reparents'] ?? []);
$check(array_column($unionRows, 'root_id') === [201, 202, 203, 205],
    'marker and fresh capture union without duplicating a root: the dedupe key is (kind, uuid, root_id)');
$check(($unionRows[2]['new_parent_id'] ?? null) === 205,
    'a root both sources name takes the fresh receipt, which already merged the marker rather than replacing it');
$wpdb->kv = [];

// run() itself is not drivable offline (promotion lease, plan, authored
// transaction, convergence recapture), so its two threading edges into this
// pass are asserted against its own source — the idiom
// regress_woocommerce_regen_engine.php uses for the same class of claim.
// Reverting either line in Apply.php fails exactly one of these two checks.
$runMethod = $applyClass->getMethod('run');
$runSource = implode("\n", array_slice(
    (array) file((string) $runMethod->getFileName(), FILE_IGNORE_NEW_LINES),
    $runMethod->getStartLine() - 1,
    $runMethod->getEndLine() - $runMethod->getStartLine() + 1
));
$check((bool) preg_match(
    '/\$this->rebuild\(\s*\$attachmentIds,\s*\$work,\s*\$tree,\s*\$regenContext,\s*\$deleteWork,'
    . '\s*!empty\(\$opts\[\'with_deletes\'\]\),\s*\$plan\[\'deleted\'\]\s*\);/',
    $runSource
), "run() hands the rebuild pass this run's tombstones, the with_deletes gate, and the already-absent set — never "
    . 'the wider set the pre-mutation selection projected surfaces from');
$check((bool) preg_match('/\$this->retryingIncompleteApply\s*=\s*\$retryingIncompleteApply;/', $runSource),
    'run() records its apply_in_progress read on the instance, which is the only path by which the retry channel '
    . 'can ever be true');

echo "\n== the capture behind the reparents channel: scoped to DECLARED consumers ==\n";
// DUO-3369 review, F2(a): scoping the capture to batch regen_dependency post
// types alone made the channel structurally empty for a provider-only
// manifest — a capability could declare `reparents`, negotiate clean, and
// never receive a row no matter what the revision moved.
$captureMethod = $applyClass->getMethod('capture_regen_reparent_context');
$captureWork = [['uuid' => $moved]];
$captureTree = [$moved => ['type' => 'post', 'data' => ['type' => 'probe', 'parent' => '{{post:' . $newParent . '}}']]];
$captureWith = static function (array $channels, array $triggers = ['post:probe']) use (
    $applyClass, $applyPolicy, $applySelected, $applyNegotiated, $captureMethod,
    $drivePolicy, $driveAction, $captureWork, $captureTree, &$wpdb
): array {
    $wpdb->kv = [];
    $apply = $applyClass->newInstanceWithoutConstructor();
    $applyPolicy->setValue($apply, $drivePolicy);
    $applySelected->setValue($apply, [['triggers' => $triggers] + $driveAction]);
    $declaration = $channels === []
        ? ['scope' => 'entity']
        : ['scope' => 'entity', 'context' => $channels];
    $applyNegotiated->setValue($apply, [
        'providers' => [],
        'capabilities' => ['probe-cache' => ['flush' => $declaration]],
    ]);
    return (array) $captureMethod->invokeArgs($apply, [$captureWork, $captureTree]);
};
$captured = $captureWith(['reparents']);
$check(count($captured) === 1
    && ($captured[0]['post_type'] ?? null) === 'probe'
    && ($captured[0]['old_parent_id'] ?? null) === 202
    && ($captured[0]['new_parent_id'] ?? null) === 203,
    'a provider-only manifest (no regen_batch anywhere) whose capability declares reparents DOES get the capture');
$check(isset($wpdb->kv['regen_reparent_context:' . $moved]),
    'and the durable marker is written exactly as the batch path writes it, so the retry above has something to find');
$check($captureWith([]) === [] && $wpdb->kv === [],
    'a post type no declared consumer names is still not captured — the gate widened to declared consumers, not to everything');
$check($captureWith(['deletions']) === [],
    'declaring some OTHER channel does not open the reparent capture either');
$check($captureWith(['reparents'], ['post:somebody_else']) === [],
    "a reparents-declaring capability triggered on another adapter's surface captures nothing here");
$wpdb->kv = [];

// ======================================================================
// DUO-3342: the provider dispatch gains the crash-safety the regen-batch
// path has — as channel semantics. Four properties, each of which the
// provider path structurally lacked while the regenerator channel had it:
// the pre-delete inventory is CAPTURED for a provider-only manifest, the
// durable receipts are DELIVERED (not just this run's tombstones), the
// markers are OWNED (cleared only on a verified receipt, retained on
// failure, re-delivered next run), and a deleted id is never handed over
// as live work.
// ======================================================================

echo "\n== the capture behind the deletions channel: the same declared-consumer gate ==\n";
$captureDeleteMethod = $applyClass->getMethod('capture_regen_delete_context');
$captureDeleteWith = static function (array $channels, array $triggers = ['post:probe']) use (
    $applyClass, $applyPolicy, $applySelected, $applyNegotiated, $captureDeleteMethod,
    $drivePolicy, $driveAction, $liveDeleted, &$wpdb
): array {
    $wpdb->kv = [];
    $apply = $applyClass->newInstanceWithoutConstructor();
    $applyPolicy->setValue($apply, $drivePolicy);
    $applySelected->setValue($apply, [['triggers' => $triggers] + $driveAction]);
    $declaration = $channels === []
        ? ['scope' => 'entity']
        : ['scope' => 'entity', 'context' => $channels];
    $applyNegotiated->setValue($apply, [
        'providers' => [],
        'capabilities' => ['probe-cache' => ['flush' => $declaration]],
    ]);
    return (array) $captureDeleteMethod->invokeArgs($apply, [[
        ['type' => 'post', 'uuid' => $liveDeleted],
    ]]);
};
// wp_posts row for the tombstoned post: the inventory reads its post_type and
// post_parent before delete_entity() removes it.
$wpdb->postsRows[41] = ['post_type' => 'probe', 'post_parent' => 7];
$capturedDelete = $captureDeleteWith(['deletions']);
$check(count($capturedDelete) === 1
    && ($capturedDelete[0]['post_type'] ?? null) === 'probe'
    && ($capturedDelete[0]['id'] ?? null) === 41
    && ($capturedDelete[0]['parent_id'] ?? null) === 7,
    'a provider-only manifest whose capability declares `deletions` DOES get the pre-delete inventory — '
    . 'the capture is what parent_id/child_ids come from at all');
$check(isset($wpdb->kv['regen_delete_context:' . $liveDeleted]),
    'and the durable marker is written exactly as the batch path writes it');
$check($captureDeleteWith([]) === [] && $wpdb->kv === [],
    'a post type no declared consumer names is still not captured — the gate widened to declared consumers, '
    . 'not to everything');
$check($captureDeleteWith(['reparents']) === [],
    'declaring some OTHER channel does not open the delete capture either');
$check($captureDeleteWith(['deletions'], ['post:somebody_else']) === [],
    "a deletions-declaring capability triggered on another adapter's surface captures nothing here");
$wpdb->kv = [];

echo "\n== durable deletion receipts: delivered, then owned ==\n";
$deleteMarkerKey = 'regen_delete_context:' . $liveDeleted;
$deleteMarker = (string) json_encode([
    'kind' => 'delete', 'uuid' => $liveDeleted, 'id' => 41, 'post_type' => 'probe',
    'parent_id' => 7, 'child_ids' => [204, 205],
]);
$noWork = [[], [], [], [], [], false, []];
$wpdb->kv = [$deleteMarkerKey => $deleteMarker];
$run = $driveRebuild(['deletions'], $noWork);
$check($run['error'] === '' && $run['calls'] === 1
    && ($run['args']['entities']['deletions'] ?? null)
        === [$deletionRow('post:probe', $liveDeleted, 41, 'probe', 7, [204, 205])],
    'a durable delete receipt from an earlier incomplete apply reaches the capability on a run whose plan '
    . 'carries no tombstone at all, carrying the full captured inventory');
$check(!isset($wpdb->kv[$deleteMarkerKey]),
    'and a verified receipt clears it: the marker is addressed by its own key, not swept by prefix');

$wpdb->kv = [$deleteMarkerKey => $deleteMarker];
$run = $driveRebuild(['deletions'], $noWork, false, ['before' => [], 'after' => [], 'verified' => false]);
$check(str_contains($run['error'], 'no value-level verification'),
    'an unverified receipt is still a hard failure on this path');
$check(isset($wpdb->kv[$deleteMarkerKey]),
    'THE CRASH-SAFETY PROPERTY: an unverified invocation RETAINS the durable receipt');
$run = $driveRebuild(['deletions'], $noWork);
$check($run['calls'] === 1
    && ($run['args']['entities']['deletions'] ?? null)
        === [$deletionRow('post:probe', $liveDeleted, 41, 'probe', 7, [204, 205])]
    && !isset($wpdb->kv[$deleteMarkerKey]),
    'and the very next apply re-delivers the identical rows, then clears them — which is what makes '
    . 'idempotent: true load-bearing rather than decorative');

$wpdb->kv = [$deleteMarkerKey => $deleteMarker];
$run = $driveRebuild(['reparents'], $noWork);
$check($run['error'] === '' && !isset($wpdb->kv[$deleteMarkerKey]),
    'a receipt whose channel NO negotiated capability declared is still swept by the batch pass — ownership '
    . 'follows the declaration, so nothing accumulates for a consumer that does not exist');

// Independent review F4: action_marker_keys() narrows the clear by the action's
// OWN triggers, and nothing pinned that. It is what stops a verified receipt on
// one adapter's surface from retiring a marker another dispatcher owns — the
// live shape being a Woo receipt and a the-events-calendar batch marker.
$otherSurfaceMarker = 'regen_delete_context:' . $otherAdapters;
$wpdb->kv = [
    $deleteMarkerKey => $deleteMarker,
    $otherSurfaceMarker => (string) json_encode([
        'kind' => 'delete', 'uuid' => $otherAdapters, 'id' => 77, 'post_type' => 'somebody_else',
        'parent_id' => 0, 'child_ids' => [],
    ]),
];
// The other surface needs a PINNED claimant of its own, or the durable sweep
// (correctly) removes a marker nothing can consume and this check would pass
// for the wrong reason.
$otherClaimantManifest = $manifest;
$otherClaimantManifest['actions'][0]['triggers'] = ['post:probe'];
$otherClaimantManifest['providers'][0]['capabilities'] = ['flush', 'flush_other'];
$otherClaimantManifest['actions'][] = [
    'kind' => 'provider',
    'provider' => 'probe-cache',
    'capability' => 'flush_other',
    'args' => ['groups' => ['probe-group']],
    'triggers' => ['post:somebody_else'],
];
$run = $driveRebuild(['deletions'], $noWork, false, null, $policyFor($otherClaimantManifest));
$check($run['error'] === '' && $run['calls'] === 1 && !isset($wpdb->kv[$deleteMarkerKey]),
    'a verified receipt clears the marker on its own triggering surface');
$check(($wpdb->kv[$otherSurfaceMarker] ?? null) !== null,
    "and leaves a marker on a surface this action does not trigger on exactly where it was — one adapter's "
    . "verified receipt may never retire another dispatcher's outstanding evidence");
$check(count((array) ($run['args']['entities']['deletions'] ?? [])) === 1,
    'that other-surface marker was never delivered either, so the clear and the delivery agree about scope');
$wpdb->kv = [];

$wpdb->kv = [$deleteMarkerKey => $deleteMarker];
$run = $driveRebuild(['deletions'], [[], [], [], [], $driveTombstones, true, []]);
$check(($run['args']['entities']['deletions'] ?? null)
        === [$deletionRow('post:probe', $liveDeleted, 41, 'probe', 7, [204, 205])],
    'when both sources name the same tombstone the durable receipt WINS — it carries the inventory the '
    . 'tombstone projection never had, and one row is delivered, not two');
$wpdb->kv = [$deleteMarkerKey => $deleteMarker];
$run = $driveRebuild(['deletions'], [[], [], [], [], array_merge($driveTombstones, [
    ['uuid' => $forgottenDeleted, 'type' => 'post', 'deletion_kind' => 'post', 'deletion_type' => 'probe'],
]), true, []]);
$check(($run['args']['entities']['deletions'] ?? null) === [
    $deletionRow('post:probe', $liveDeleted, 41, 'probe', 7, [204, 205]),
    $deletionRow('post:probe', $forgottenDeleted, 0, 'probe'),
], 'and an applied tombstone with no receipt of its own is still delivered beside it, with an empty '
    . 'inventory rather than an absent one');
$wpdb->kv = [];

echo "\n== deleted ids are never handed over as live work ==\n";
$liveWork = [['uuid' => $moved]];
$liveTree = [$moved => ['type' => 'post', 'data' => ['type' => 'probe']]];
$run = $driveRebuild(['deletions'], [[], $liveWork, $liveTree, [], [], false, []]);
$check($run['error'] === '' && ($run['args']['entities']['entities'] ?? null)
        === [['kind' => 'post:probe', 'id' => 204]],
    'baseline: the moved post is ordinary live work for a capability triggering on its surface');
$wpdb->kv = [$deleteMarkerKey => $deleteMarker];
$run = $driveRebuild(['deletions'], [[], $liveWork, $liveTree, [], [], false, []]);
$check($run['error'] === '' && $run['calls'] === 1
    && ($run['args']['entities']['entities'] ?? null) === [],
    'the same work row is withheld once a delivered deletion receipt names its id in child_ids — a deleted '
    . 'id is evidence on the deletions channel, never live work');
$wpdb->kv = [];

echo "\n== pending markers: armed before the call, cleared only on a verified receipt ==\n";
$pendingKey = 'regen_pending:' . $moved;
$run = $driveRebuild([], [[], $liveWork, $liveTree, [], [], false, []]);
$check($run['error'] === '' && $run['calls'] === 1 && !isset($wpdb->kv[$pendingKey]),
    'a verified invocation leaves no pending marker behind');
$run = $driveRebuild([], [[], $liveWork, $liveTree, [], [], false, []], false,
    ['before' => [], 'after' => [], 'verified' => false]);
$check(($wpdb->kv[$pendingKey] ?? null) === 'probe',
    'a failed one leaves `regen_pending:<uuid>` armed with the post type as its value — the batch path\'s '
    . 'own marker, shared deliberately so one retry vocabulary covers both dispatchers');
$run = $driveRebuild([], $noWork);
$check($run['error'] === '' && $run['calls'] === 1
    && $run['args']['entities'] === [['kind' => 'post:probe', 'id' => 204]],
    'and the next apply re-delivers that entity off the marker ALONE, with no authored work at all — the '
    . "content hash never reflects derived state, so this is the only path by which a failed repair retries");
$check(!isset($wpdb->kv[$pendingKey]), 'the successful retry clears it');
$check($run['args'] !== null && array_keys((array) $run['args']) === ['groups', 'entities']
    && array_is_list($run['args']['entities']),
    'the union rides on the channel-less path too: a capability declaring nothing still receives the bare '
    . 'row list, not an envelope');

$wpdb->kv = ['regen_pending:orphan-probe-uuid' => 'probe'];
$run = $driveRebuild([], $noWork);
$check($run['error'] === '' && $run['calls'] === 0
    && !isset($wpdb->kv['regen_pending:orphan-probe-uuid']),
    'a pending marker whose uuid no longer resolves is dropped rather than replayed or left forever');
$check(str_contains($run['warnings'],
    "regen_pending marker for post orphan-probe-uuid (type 'probe') dropped: uuid no longer resolves to a "
    . 'local post id'),
    "and the sweep says so in the batch path's exact wording — one marker vocabulary, one explanation");
$wpdb->kv = [];

// The OTHER sweep — regen_dependencies()' orphan pass — must not delete a
// marker just because this particular apply selected nothing on its surface.
// That pass fires on "no regen_dependency declares this post type", which is
// permanently true for a provider-owned one, so the guard is what stands
// between a failed repair and its retry evidence. It is deliberately answered
// from the PINNED manifest rather than this run's selection: an apply that
// touched nothing on the surface has an empty selection by construction.
$triggeredManifest = $manifest;
$triggeredManifest['actions'][0]['triggers'] = ['post:probe'];
$deleteMarkerValue = (string) json_encode([
    'kind' => 'delete', 'uuid' => $liveDeleted, 'id' => 41, 'post_type' => 'probe',
    'parent_id' => 7, 'child_ids' => [],
]);
$reparentMarkerValue = (string) json_encode([
    'kind' => 'reparent', 'uuid' => $moved, 'id' => 204, 'post_type' => 'probe',
    'parent_id' => 202, 'old_parent_id' => 202, 'new_parent_id' => 203, 'root_ids' => [202, 203],
]);
$sweepMarkers = [
    'regen_pending:' . $moved => 'probe',
    'regen_delete_context:' . $liveDeleted => $deleteMarkerValue,
    'regen_reparent_context:' . $moved => $reparentMarkerValue,
];
$driveSweep = static function (array $sweepManifest, array $negotiated = []) use (
    $applyClass, $applyPolicy, $applySelected, $applyNegotiated, $applyWarnings, $policyFor, $sweepMarkers, &$wpdb
): string {
    $wpdb->kv = $sweepMarkers;
    $sweepApply = $applyClass->newInstanceWithoutConstructor();
    $applyPolicy->setValue($sweepApply, $policyFor($sweepManifest));
    $applySelected->setValue($sweepApply, []);
    $applyNegotiated->setValue($sweepApply, ['providers' => [], 'capabilities' => $negotiated]);
    $applyWarnings->setValue($sweepApply, []);
    $applyClass->getMethod('regen_dependencies')->invokeArgs($sweepApply, [[], [], []]);
    return implode("\n", (array) $applyWarnings->getValue($sweepApply));
};
$driveSweep($triggeredManifest);
$check(($wpdb->kv['regen_pending:' . $moved] ?? null) === 'probe',
    'an apply that selected nothing on the surface leaves a provider-owned pending marker armed rather than '
    . 'sweeping it as an orphan of a regen_dependency that was never there');
// Independent review F1: the durable-context sweep had the same shape of bug
// the pending sweep was already guarded against, and it was the one that lost
// data silently — both context markers were kv_deleted with no warning at all
// on an apply that simply had no work on their surface.
$check(($wpdb->kv['regen_delete_context:' . $liveDeleted] ?? null) === $deleteMarkerValue
    && ($wpdb->kv['regen_reparent_context:' . $moved] ?? null) === $reparentMarkerValue,
    'and it leaves BOTH durable context receipts alone for the same reason — the sweep is run-independent, '
    . 'so "this apply had nothing to do here" is never read as "nobody will ever consume this"');
$sweptWarnings = $driveSweep($manifest);
$check(!isset($wpdb->kv['regen_pending:' . $moved])
    && str_contains($sweptWarnings, "manifest no longer declares a regen_dependency for post type 'probe'"),
    'while a marker no pinned declaration of EITHER kind claims is still swept, loudly — the guard narrowed '
    . 'the sweep, it did not retire it');
$check(!isset($wpdb->kv['regen_delete_context:' . $liveDeleted])
    && !isset($wpdb->kv['regen_reparent_context:' . $moved]),
    'and the same holds for the context receipts: no pinned claimant, no marker');
$check(str_contains($sweptWarnings,
        "regen_delete_context marker for post $liveDeleted (type 'probe') dropped: post type 'probe' declares "
        . "no batch regen_dependency, and no capability consuming the 'deletions' channel claims post:probe")
    && str_contains($sweptWarnings,
        "regen_reparent_context marker for post $moved (type 'probe') dropped: post type 'probe' declares "
        . "no batch regen_dependency, and no capability consuming the 'reparents' channel claims post:probe"),
    'each of those sweeps says which marker went and why, naming the channel nobody consumes — it used to be '
    . 'a silent kv_delete');
// Independent review F5: only a scope: entity capability can ever receive an
// entity batch or a context channel, so a pinned action whose capability this
// run DID negotiate as scope: site owns nothing and its markers are orphans.
// Where the scope is unknowable (nothing selected reached that provider, so no
// declaration was ever loaded) the fallback keeps the marker instead — the two
// cases above are exactly that, and guessing a scope to authorize a delete is
// the fail-open a destructive sweep must not take.
$sweptWarnings = $driveSweep($triggeredManifest, ['probe-cache' => ['flush' => ['scope' => 'site']]]);
$check(!isset($wpdb->kv['regen_pending:' . $moved])
    && !isset($wpdb->kv['regen_delete_context:' . $liveDeleted])
    && !isset($wpdb->kv['regen_reparent_context:' . $moved]),
    'a pinned action whose negotiated capability is scope: site owns none of the three keyspaces — it can '
    . 'never receive an entity batch or a channel, so holding markers for it would hold them forever');

$wpdb->kv = ['regen_delete_context:malformed-probe-uuid' => (string) json_encode(['kind' => 'delete'])];
$sweptWarnings = '';
$sweepApply = $applyClass->newInstanceWithoutConstructor();
$applyPolicy->setValue($sweepApply, $policyFor($triggeredManifest));
$applySelected->setValue($sweepApply, []);
$applyNegotiated->setValue($sweepApply, ['providers' => [], 'capabilities' => []]);
$applyWarnings->setValue($sweepApply, []);
$applyClass->getMethod('regen_dependencies')->invokeArgs($sweepApply, [[], [], []]);
$check(!isset($wpdb->kv['regen_delete_context:malformed-probe-uuid'])
    && str_contains(
        implode("\n", (array) $applyWarnings->getValue($sweepApply)),
        'regen_delete_context marker for post malformed-probe-uuid dropped: the stored receipt carries no '
        . 'post type or no captured local id'
    ),
    'a receipt no dispatcher could replay at all is still swept, and now says so instead of vanishing');
$wpdb->kv = [];

echo "\n== one post type, one dispatcher: the dual-claimant refusal ==\n";
// The three marker keyspaces above are SHARED between the batch regenerator
// channel and a channel-declaring capability. That is only coherent while
// exactly one dispatcher owns a post type, so two claimants refuse before the
// first mutation rather than each consuming and clearing the other's markers.
$claimantManifest = $manifest;
$claimantManifest['post_types'] = ['probe' => ['regen_dependency' => [
    'regenerator' => 'probe-lookups',
    'verify' => ['table' => 'probe_lookup', 'column' => 'post_id'],
    'batch' => ['enabled' => true, 'always_on_write' => true],
]]];
$claimantManifest['actions'][0]['triggers'] = ['post:probe'];
$claimantNegotiation = static function (mixed $context) use ($policyFor, $claimantManifest, $reset): array {
    $reset();
    $overrides = ['scope' => 'entity'];
    if ($context !== null) {
        $overrides['context'] = $context;
    }
    \Duo\Providers\ProbeCache::$capabilityOverrides = $overrides;
    $policy = $policyFor($claimantManifest);
    return \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
};
$p = $one($claimantNegotiation(['deletions'])['problems']);
$check(($p['code'] ?? '') === 'post_type_claimed_by_regen_batch'
    && str_contains($p['found'] ?? '', 'post_types.probe declares an enabled batch regen_dependency')
    && str_contains($p['remediation'] ?? '', 'remove the batch regen_dependency for probe'),
    'a capability declaring a channel on a post type that ALSO has an enabled batch regen_dependency refuses '
    . 'at negotiation, naming both claimants and the way out');
$check(str_contains($p['expected'] ?? '', 'exactly one of')
    && str_contains($p['expected'] ?? '', 'context: deletions'),
    'and the expectation names the channels the capability declared, so a half-finished migration is legible');
$check($claimantNegotiation(['reparents'])['problems'] !== []
    && $claimantNegotiation(['always_on_write'])['problems'] !== [],
    'any declared channel claims the same markers, so any of them collides — this is not a deletions-only rule');
$check($claimantNegotiation(null)['problems'] === [],
    'a capability declaring NO channel negotiates clean on the same post type: it consumes none of that '
    . 'bookkeeping, so the batch channel keeps undisputed ownership');
$reset();

echo "\n== outstanding receipts are legible in plan and status (independent review F3) ==\n";
// DUO-3342 made these markers SURVIVE a failed apply instead of being swept in
// the same pass that read them. That is the point — and it is also what makes
// them worth surfacing: a marker can now stand between a failure and its retry,
// and an operator deciding "is this safe to promote" must be able to see it.
$planRows = $applyClass->getMethod('regen_context_plan_rows');
$planApply = $applyClass->newInstanceWithoutConstructor();
$applyPolicy->setValue($planApply, $policyFor($triggeredManifest));
$applySelected->setValue($planApply, []);
$applyNegotiated->setValue($planApply, ['providers' => [], 'capabilities' => []]);
$wpdb->kv = $sweepMarkers + [
    'regen_delete_context:malformed' => (string) json_encode(['kind' => 'delete', 'post_type' => 'probe']),
];
$rows = (array) $planRows->invoke($planApply);
$check($rows === [
    ['uuid' => $liveDeleted, 'type' => 'post', 'post_type' => 'probe', 'kind' => 'delete'],
    ['uuid' => $moved, 'type' => 'post', 'post_type' => 'probe', 'kind' => 'reparent'],
], 'plan projects one row per outstanding receipt, both keyspaces, ordered and carrying its kind');
$check(count($rows) === 2,
    'and a malformed receipt is NOT surfaced — apply sweeps that one itself, loudly, so a plan reader has '
    . 'nothing to do about it');
$planApply = $applyClass->newInstanceWithoutConstructor();
$applyPolicy->setValue($planApply, $policyFor($manifest));
$applySelected->setValue($planApply, []);
$applyNegotiated->setValue($planApply, ['providers' => [], 'capabilities' => []]);
$check((array) $planRows->invoke($planApply) === [],
    'a receipt no pinned claimant owns is not surfaced either — the projection shows outstanding DEBT, never '
    . 'orphaned bookkeeping');
$wpdb->kv = [];

// The status half, driven through the real summariser: a plan carrying one of
// these rows must render it and must not report ok.
require_once $root . '/cli/src/PlanSummary.php';
$statusPlan = array_fill_keys([
    'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict',
    'collision', 'delete', 'delete_conflict', 'deleted',
], []);
$statusPlan['regen_context'] = [
    ['uuid' => $liveDeleted, 'type' => 'post', 'post_type' => 'probe', 'kind' => 'delete'],
];
$rendered = \Duo\Orchestrator\PlanSummary::render($statusPlan);
$renderedText = implode("\n", (array) $rendered['lines']);
$check(($rendered['ok'] ?? null) === false,
    'duo status refuses to call an environment clean while a derived-state receipt is outstanding — same '
    . 'footing as regen_pending, and for the same reason');
$check(str_contains($renderedText, '1 regen_context')
    && str_contains($renderedText, 'REGEN_CONTEXT')
    && str_contains($renderedText, "post type 'probe', delete receipt"),
    'and names the entity, its post type, and which receipt is outstanding');
$statusPlan['regen_context'] = [];
$check((\Duo\Orchestrator\PlanSummary::render($statusPlan)['ok'] ?? null) === true,
    'an empty bucket is not a blocker — the row is the signal, never the key');
// build_plan() itself is not drivable offline (it needs a compiled repository
// and a live target), so its one edge into the projection above is asserted
// against its own source — the idiom this suite already uses for run()'s
// threading. Without it, deleting the call site while keeping the method passes
// every behavioural check in this section (proven: that mutation survived).
$buildPlanMethod = $applyClass->getMethod('build_plan');
$buildPlanSource = implode("\n", array_slice(
    (array) file((string) $buildPlanMethod->getFileName(), FILE_IGNORE_NEW_LINES),
    $buildPlanMethod->getStartLine() - 1,
    $buildPlanMethod->getEndLine() - $buildPlanMethod->getStartLine() + 1
));
$check((bool) preg_match(
    "/\\\$plan\\['regen_context'\\]\\s*=\\s*\\\$this->regen_context_plan_rows\\(\\);/",
    $buildPlanSource
), 'build_plan() actually fills the bucket from that projection — the plan a human reads is the one those '
    . 'checks just exercised');
$check((bool) preg_match(
    "/foreach \\(\\\$plan\\['regen_context'\\] as \\\$row\\) \\{\\s*\\\$this->warnings\\[\\] =/",
    $buildPlanSource
), 'and warns once per outstanding receipt, so a plain `duo plan` says it out loud rather than only in a '
    . 'structured bucket a script has to look for');

// Lockstep with the agent-side renderer and the precondition hash: `duo status`
// and a plain `wp duo plan` must never give an operator different advice, and a
// receipt appearing between plan and apply must invalidate the plan.
$cliSource = (string) file_get_contents($root . '/agent/src/Cli.php');
$check(str_contains($cliSource, "REGEN_CONTEXT ")
    && str_contains($cliSource, "count(\$plan['regen_context'] ?? []) . ' regen_context'"),
    'the agent-side plan renderer carries the same bucket, count line included');
$hashSource = implode("\n", array_slice(
    (array) file((string) $applyClass->getMethod('plan_precondition_hash')->getFileName(), FILE_IGNORE_NEW_LINES),
    $applyClass->getMethod('plan_precondition_hash')->getStartLine() - 1,
    $applyClass->getMethod('plan_precondition_hash')->getEndLine()
        - $applyClass->getMethod('plan_precondition_hash')->getStartLine() + 1
));
$check(str_contains($hashSource, "'regen_context'"),
    'and the bucket authorizes mutation, so a receipt that appeared during the planning window refuses the '
    . 'stale plan rather than riding along');

echo "\n== one channel, one surface, one consumer (independent review F2) ==\n";
// The engine clears a durable receipt on the FIRST verified receipt of a run,
// because a marker is bookkeeping about an entity rather than per-consumer
// state. With two consumers on one surface that retires evidence the second
// one's retry depends on, so the second consumer is refused before any
// mutation instead.
$secondCapability = static fn(array $context, string $scope = 'entity'): array => [
    'args' => ['groups' => ['type' => 'list<string>', 'required' => true]],
    'reads' => ['option:probe_setting'],
    'writes' => ['entity:probe-cache-groups'],
    'scope' => $scope,
    'idempotent' => true,
    'timeout_seconds' => 30,
] + ($context === [] ? [] : ['context' => $context]);
$twoConsumers = static function (
    array $firstContext,
    array $secondContext,
    array $secondTriggers = ['post:probe'],
    ?string $secondCapabilityName = 'flush_again'
) use ($policyFor, $manifest, $reset, $secondCapability): array {
    $reset();
    \Duo\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity', 'context' => $firstContext];
    $m = $manifest;
    $m['actions'][0]['triggers'] = ['post:probe'];
    if ($secondCapabilityName === 'flush_again') {
        \Duo\Providers\ProbeCache::$extraCapabilities = ['flush_again' => $secondCapability($secondContext)];
        $m['providers'][0]['capabilities'] = ['flush', 'flush_again'];
    }
    $m['actions'][] = [
        'kind' => 'provider',
        'provider' => 'probe-cache',
        'capability' => (string) $secondCapabilityName,
        'args' => ['groups' => ['probe-group']],
        'triggers' => $secondTriggers,
    ];
    $policy = $policyFor($m);
    return \Duo\Providers::negotiate($policy, $policy->actions_for(['post:probe', 'post:probe_other']));
};

$negotiation = $twoConsumers(['deletions'], ['deletions']);
$p = $one($negotiation['problems']);
$check(($p['code'] ?? '') === 'channel_claimed_twice'
    && str_contains($p['found'] ?? '', 'probe-cache/flush, probe-cache/flush_again')
    && str_contains($p['expected'] ?? '', "exactly one capability consuming the 'deletions' channel for post:probe"),
    'two capabilities declaring the SAME channel on the SAME surface refuse at negotiation, naming both');
$check(str_contains($p['remediation'] ?? '', 'the evidence its own retry depends on')
    && str_contains($p['remediation'] ?? '', 'Narrow the triggers'),
    'and the remediation says why one keyspace cannot serve two consumers, not merely that it may not');
$check(!isset($negotiation['providers']['probe-cache']) && !isset($negotiation['capabilities']['probe-cache']),
    'neither claimant binds — which of them would have cleared the shared marker is the question with no answer');

$check($twoConsumers(['deletions'], ['deletions'], ['post:probe_other'])['problems'] === [],
    'the same channel on DIFFERENT surfaces is two keyspaces, not one: no collision');
$check($twoConsumers(['deletions'], ['reparents'])['problems'] === [],
    'different channels on the same surface touch different marker prefixes: no collision either');
$check($twoConsumers(['deletions'], [], ['post:probe'], 'flush')['problems'] === [],
    'and ONE capability selected by two actions on the same surface is one consumer, not two — the dedupe is '
    . 'by capability identity, never by action count');
$reset();

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
