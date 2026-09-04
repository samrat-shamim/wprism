<?php
declare(strict_types=1);

/**
 * Offline regression for issue #3338's native-action vocabulary and plugin-owned
 * provider contract, extended by issue #3369's structured capability arguments
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
 * against an explicit AdapterLibrary holding caller-owned fixture bytes.
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

$root = dirname(__DIR__, 4);
define('WPRISM_SPEC_VERSION', 3);
require __DIR__ . '/../../lib/agent_version.php';
wprism_test_define_agent_versions();
// wpdb::get_results()'s output mode, which Ledger's own checked reads pass.
define('ARRAY_A', 'ARRAY_A');

// ---- WordPress lifecycle primitives Deploy::plugin_runtime_state() reads ----
// Defining validate_plugin() also short-circuits Deploy's wp-admin include.
$GLOBALS['wprism_test_plugins'] = [];
$GLOBALS['wprism_test_active'] = [];
$GLOBALS['wprism_test_providers'] = [];
$GLOBALS['wprism_test_provider_registry_throw'] = null;

function validate_plugin(string $plugin): mixed {
    return isset($GLOBALS['wprism_test_plugins'][$plugin])
        ? 0
        : new \WP_Error("plugin '$plugin' does not exist");
}
function get_plugins(): array {
    return $GLOBALS['wprism_test_plugins'];
}
function get_option(string $name, mixed $default = false): mixed {
    return $name === 'active_plugins' ? $GLOBALS['wprism_test_active'] : $default;
}
function untrailingslashit(string $value): string {
    return rtrim($value, '/\\');
}
function wp_upload_dir(mixed $time = null, bool $refresh = false): array {
    return ['baseurl' => 'https://fixture.invalid/uploads'];
}
function is_wp_error(mixed $thing): bool {
    return $thing instanceof \WP_Error;
}
function apply_filters(string $hook, mixed $value): mixed {
    $gate = is_array($GLOBALS['wp_filter'] ?? null)
        ? ($GLOBALS['wp_filter'][$hook] ?? null)
        : null;
    if (is_object($gate) && method_exists($gate, 'apply_filters')) {
        return $gate->apply_filters($value, [$value]);
    }
    if ($hook === 'wprism_providers' && $GLOBALS['wprism_test_provider_registry_throw'] !== null) {
        throw new \RuntimeException($GLOBALS['wprism_test_provider_registry_throw']);    }
    return $hook === 'wprism_providers' ? $GLOBALS['wprism_test_providers'] : $value;
}
// Apply::rebuild() flushes the object cache before and after the action loop
// and hard-fails on a false return; the count is asserted by the drive below,
// so the stub is evidence rather than a silencer.
$GLOBALS['wprism_test_cache_flushes'] = 0;
function wp_cache_flush(): bool {
    $GLOBALS['wprism_test_cache_flushes']++;
    return true;
}
// Apply::rebuild() reschedules future posts for every post-kind work row it is
// given. The issue #3342 drives below hand it real work rows, so these three are
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
// issue #3317: the WordPress version read a provider `requires.wordpress_version`
// negotiation makes — TargetProbe::probe_target() reads
// get_bloginfo('version') the same way. Controlled by a global so the requires
// cases below can place the site's version inside or outside a declared
// window. Every other check leaves the requirement path untouched (a
// declaration with no `requires` never consults it), so defining it here
// changes nothing they observe.
$GLOBALS['wprism_test_wp_version'] = '';
function get_bloginfo(string $show = 'version'): string {
    return (string) ($GLOBALS['wprism_test_wp_version'] ?? '');
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

// issue #3317: a wpdb whose four read methods return exactly what a test tells
// them to, plus a settable last_error and a record of the SQL it was handed —
// the fixture \WPrism\ProviderSdk's checked reads run against. Deliberately does
// NOT model any query grammar: the SDK's whole contract is that it never reads
// the SQL or the driver text into its failure, so the fake need only prove the
// SDK distinguishes a real value from every failure shape.
final class CheckedReadFakeWpdb {
    public string $last_error = '';
    public mixed $varReturn = null;
    public mixed $colReturn = null;
    public mixed $rowReturn = null;
    public mixed $resultsReturn = null;
    /** A driver error to raise DURING the read (after the SDK clears it). */
    public string $errorOnRead = '';
    /** @var list<string> */
    public array $sqlSeen = [];

    private function ran(string $sql): void {
        $this->sqlSeen[] = $sql;
        if ($this->errorOnRead !== '') {
            $this->last_error = $this->errorOnRead;
        }
    }

    public function get_var(string $sql): mixed {
        $this->ran($sql);
        return $this->varReturn;
    }

    public function get_col(string $sql): mixed {
        $this->ran($sql);
        return $this->colReturn;
    }

    public function get_row(string $sql, mixed $output = null): mixed {
        $this->ran($sql);
        return $this->rowReturn;
    }

    public function get_results(string $sql, mixed $output = null): mixed {
        $this->ran($sql);
        return $this->resultsReturn;
    }
}

$GLOBALS['wprism_native_cache'] = [];
$GLOBALS['wprism_native_cache_reads'] = [];
$GLOBALS['wprism_native_delete_calls'] = [];
$GLOBALS['wprism_native_delete_mode'] = 'delete';
$GLOBALS['wprism_native_cache_sets_found'] = true;

function wp_cache_get($key, $group = '', $force = false, &$found = null): mixed {
    $group = (string) $group;
    $key = (string) $key;
    $entries = $GLOBALS['wprism_native_cache'][$group] ?? [];
    $present = array_key_exists($key, $entries);
    if ($GLOBALS['wprism_native_cache_sets_found']) {
        $found = $present;
    }
    $value = $present ? $entries[$key] : false;
    $GLOBALS['wprism_native_cache_reads'][] = [
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
    $GLOBALS['wprism_native_delete_calls'][] = $name;
    if ($GLOBALS['wprism_native_delete_mode'] === 'no-op') {
        return false;
    }
    $wasPresent = array_key_exists('_transient_' . $name, $wpdb->optionRows)
        || array_key_exists('_transient_timeout_' . $name, $wpdb->optionRows)
        || array_key_exists($name, $GLOBALS['wprism_native_cache']['transient'] ?? []);
    unset($wpdb->optionRows['_transient_' . $name]);
    unset($wpdb->optionRows['_transient_timeout_' . $name]);
    unset($GLOBALS['wprism_native_cache']['transient'][$name]);
    return $wasPresent;
}

require $root . '/agent/src/Kernel/Canon.php';
require $root . '/agent/src/Kernel/OptionState.php';
require $root . '/agent/src/Policy/ManifestDispositions.php';
require $root . '/agent/src/Policy/Policy.php';
require $root . '/agent/src/Code/CodeCompatibility.php';
require $root . '/agent/src/Promotion/Deploy.php';
require_once $root . '/agent/src/Adapter/ProviderSdk.php';
require_once $root . '/agent/src/Adapter/Providers.php';
// issue #3339: `wprism status`'s renderer is pure and is one half of the documented
// two-renderer lockstep for plan rows, so it is driven directly below.
require $root . '/cli/src/Plan/PlanSummary.php';
require __DIR__ . '/../../lib/frozen_policy.php';
require __DIR__ . '/certification_fixture.php';
// Providers::invoke() now reads the capability's own declared `option:`
// surfaces either side of the call, so the invocation drives below need a
// $wpdb that INTERPRETS that read against seeded rows rather than one that
// pattern-matches a transcribed statement — a fake answering []
// indistinguishably from a real absent row would pin the new refusals green
// without ever exercising them.
require __DIR__ . '/../../lib/FakeWpdb.php';

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$condition) {
        $failures++;
    }
};

// ---- explicit scratch library with a real manifest-shipped provider file ----
$scratchRoot = sys_get_temp_dir() . '/wprism-provider-contract-' . getmypid();
$dir = wprism_cert_project_library(\WPrism\AdapterLibrary::fromSourceTree($root), $scratchRoot);
register_shutdown_function(static function () use ($scratchRoot): void {
    wprism_cert_remove_tree($scratchRoot);
});
file_put_contents($dir . '/providers/probe-cache.php', <<<'PHP'
<?php
namespace WPrism\Providers;

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
    public static ?\Throwable $lastInvokeThrowable = null;
    public static mixed $receiptOverride = null;
    public static float $sleepSeconds = 0.0;
    // WPRISM-3.3: real wp_options writes the capability performs INSIDE invoke(),
    // so the engine's own before/after reading of the declared surfaces has
    // something to disagree with. option name => new value, or null to delete
    // the row. Empty by default, so every check written before this one sees
    // the same inert provider it was written against.
    public static array $optionWrites = [];

    public function __construct(\WPrism\Policy $policy) {}

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
            self::$lastInvokeThrowable = new \RuntimeException(self::$invokeThrows);
            throw self::$lastInvokeThrowable;
        }
        if (self::$sleepSeconds > 0.0) {
            usleep((int) (self::$sleepSeconds * 1000000));
        }
        // Written before the receipt is chosen, so an over-budget or malformed
        // receipt still leaves a real surface change behind it — which is what
        // makes the precedence checks below prove an ordering rather than an
        // absence.
        global $wpdb;
        foreach (self::$optionWrites as $name => $value) {
            if ($value === null) {
                $wpdb->delete('options', ['option_name' => $name]);
                continue;
            }
            if ($wpdb->update('options', ['option_value' => $value], ['option_name' => $name]) === 0) {
                $wpdb->insert('options', [
                    'option_name' => $name,
                    'option_value' => $value,
                    'autoload' => 'yes',
                ]);
            }
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
define('WP_PLUGIN_DIR', $scratchRoot . '/wp-plugins');
// issue #3339: this harness models a target that HAS WordPress loaded — that is
// what makes negotiating plugin state meaningful here at all — and
// Providers::runtime_negotiation_available() reads exactly the four symbols
// that say so. Three were already present; ABSPATH is the fourth, and without
// it the plan-time diagnosis correctly short-circuits to no findings (the
// group below pins that short-circuit in its own process, where the constant
// genuinely is absent).
define('ABSPATH', $scratchRoot . '/wp/');
@mkdir(WP_PLUGIN_DIR . '/probe', 0700, true);
file_put_contents(WP_PLUGIN_DIR . '/probe/wprism-provider.php', <<<'PHP'
<?php
namespace WPrism\Providers;

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
require_once WP_PLUGIN_DIR . '/probe/wprism-provider.php';
// A SECOND manifest-shipped provider, for the one shape a single provider with
// two capabilities cannot express: a channel collision ACROSS providers, which
// is the realistic form (two adapters, one surface) and the only one that can
// tell a complete unbind from a partial one.
file_put_contents($dir . '/providers/probe-index.php', <<<'PHP'
<?php
namespace WPrism\Providers;

final class ProbeIndex {
    public static array $capabilityOverrides = [];

    public function __construct(\WPrism\Policy $policy) {}

    public function identity(): array {
        return ['id' => 'probe-index', 'plugin' => 'probe/probe.php', 'version' => '1.0.0'];
    }

    public function capabilities(): array {
        $overrides = $GLOBALS['wprism_test_probe_index_capability_overrides']
            ?? self::$capabilityOverrides;
        return [
            'reindex' => $overrides + [
                'args' => [],
                'reads' => ['option:probe_setting'],
                'writes' => ['entity:probe-index'],
                'scope' => 'entity',
                'idempotent' => true,
                'timeout_seconds' => 30,
            ],
        ];
    }

    public function invoke(string $capability, array $args): array {
        return ['before' => [], 'after' => [], 'verified' => true];
    }
}
PHP);
file_put_contents($dir . '/regenerators/probe-lookups.php', "<?php\n// Test-owned packaging fixture; negotiation never invokes this regenerator.\n");

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
// The probe joins the projected source packages only inside this scratch
// library. Its disposition bytes are structural here; reviewed cases freeze
// their own authored disposition below.
copy($dir . '/dispositions/core.json', $dir . '/dispositions/probe.json');

/** @var array<string,\WPrism\AdapterLibrary> */
$providerLibraries = [];
$libraryFor = static function (array $manifest) use ($dir, &$providerLibraries): \WPrism\AdapterLibrary {
    $key = hash('sha256', \WPrism\Canon::encode($manifest));
    file_put_contents($dir . '/probe.json', \WPrism\Canon::encode($manifest));
    if (isset($providerLibraries[$key])) {
        return $providerLibraries[$key];
    }
    $declared = [];
    foreach ((array) ($manifest['providers'] ?? []) as $provider) {
        if (($provider['source'] ?? null) === 'manifest' && is_string($provider['id'] ?? null)) {
            $declared[] = $provider['id'];
        }
    }
    $hidden = [];
    foreach (['probe-cache', 'probe-index'] as $provider) {
        $path = $dir . '/providers/' . $provider . '.php';
        if (!in_array($provider, $declared, true) && is_file($path)) {
            $away = dirname($dir) . '/.' . $provider . '.php';
            rename($path, $away);
            $hidden[$away] = $path;
        }
    }
    $declaredRegenerators = [];
    foreach ((array) ($manifest['post_types'] ?? []) as $postType) {
        $regenerator = is_array($postType) && is_array($postType['regen_dependency'] ?? null)
            ? ($postType['regen_dependency']['regenerator'] ?? null)
            : null;
        if (is_string($regenerator)) {
            $declaredRegenerators[] = $regenerator;
        }
    }
    $regeneratorPath = $dir . '/regenerators/probe-lookups.php';
    if (!in_array('probe-lookups', $declaredRegenerators, true) && is_file($regeneratorPath)) {
        $away = dirname($dir) . '/.probe-lookups.php';
        rename($regeneratorPath, $away);
        $hidden[$away] = $regeneratorPath;
    }
    try {
        return $providerLibraries[$key] = \WPrism\AdapterLibrary::fromLegacyFlatDirectory($dir);
    } finally {
        foreach ($hidden as $away => $path) {
            rename($away, $path);
        }
    }
};

$policyFor = static function (array $manifest) use ($libraryFor): \WPrism\Policy {
    return \WPrism\Policy::from_snapshot([
        'adapter_sources' => \WPrismTest\FrozenPolicy::adapterSources(),
        'dispositions' => null,
        'format' => \WPrismTest\FrozenPolicy::SNAPSHOT_FORMAT,
        'manifests' => [$manifest],
        'site' => \WPrismTest\FrozenPolicy::site([$manifest]),
    ], $libraryFor($manifest));
};
$reset = static function (): void {
    $GLOBALS['wprism_test_plugins'] = ['probe/probe.php' => ['Version' => '1.5.0']];
    $GLOBALS['wprism_test_active'] = ['probe/probe.php'];
    $GLOBALS['wprism_test_providers'] = [];
    $GLOBALS['wprism_test_provider_registry_throw'] = null;
    $GLOBALS['wprism_test_probe_index_capability_overrides'] = [];
    if (class_exists(\WPrism\Providers\ProbeCache::class, false)) {
        \WPrism\Providers\ProbeCache::$capabilityMapOverride = null;
        \WPrism\Providers\ProbeCache::$capabilityOverrides = [];
        \WPrism\Providers\ProbeCache::$capabilitiesThrows = null;
        \WPrism\Providers\ProbeCache::$extraCapabilities = [];
        \WPrism\Providers\ProbeCache::$identityOverrides = [];
        \WPrism\Providers\ProbeCache::$identityThrows = null;
        \WPrism\Providers\ProbeCache::$invokeThrows = null;
        \WPrism\Providers\ProbeCache::$lastInvokeThrowable = null;
        \WPrism\Providers\ProbeCache::$receiptOverride = null;
        \WPrism\Providers\ProbeCache::$sleepSeconds = 0.0;
        \WPrism\Providers\ProbeCache::$optionWrites = [];
    }
};

echo "\n== closed native-action vocabulary ==\n";
$check(\WPrism\NativeActions::vocabulary() === ['transient.delete', 'rewrite.flush'],
    'v1 vocabulary is exactly transient.delete and rewrite.flush — a plugin cannot mint an action name');
$expectMessage = static function (callable $body, string $needle, string $label) use ($check): void {
    try {
        $body();
        $check(false, $label);
    } catch (\Throwable $t) {
        $check(str_contains($t->getMessage(), $needle), $label . ' (message: ' . $t->getMessage() . ')');
    }
};
$expectMessage(
    static fn() => \WPrism\NativeActions::validate('shell.exec', ['cmd' => 'rm -rf /'], 'probe'),
    'vocabulary is closed',
    'an unknown native action is refused with the closed vocabulary named'
);
$expectMessage(
    static fn() => \WPrism\NativeActions::validate('transient.delete', ['name' => 'ok', 'ttl' => 5], 'probe'),
    'unknown key(s)',
    'an unknown argument key is refused rather than ignored'
);
$expectMessage(
    static fn() => \WPrism\NativeActions::validate('transient.delete', [], 'probe'),
    'missing required key',
    'a missing required argument is refused'
);
$expectMessage(
    static fn() => \WPrism\NativeActions::validate('transient.delete', ['name' => "a'; DROP TABLE wp_options; --"], 'probe'),
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
    $GLOBALS['wprism_native_cache'] = [];
    $GLOBALS['wprism_native_cache_reads'] = [];
    $GLOBALS['wprism_native_delete_calls'] = [];
    $GLOBALS['wprism_native_delete_mode'] = 'delete';
    $GLOBALS['wprism_native_cache_sets_found'] = true;
};
$deleteNativeTransient = static fn(string $name): array => \WPrism\NativeActions::execute(
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
$check($GLOBALS['wprism_native_delete_calls'] === [],
    'a checked option-row read failure refuses before delete_transient() is called');

// The public cache API owes callers a boolean presence flag. A legacy or
// nonconforming wrapper that leaves it unset cannot prove absence, so the
// action must stop before delete_transient() rather than publish a guess.
$resetNativeActionRuntime();
$GLOBALS['wprism_native_cache_sets_found'] = false;
try {
    $deleteNativeTransient('native_missing_found_flag');
    $check(false, 'a cache wrapper that omits the found flag is refused');
} catch (\Throwable $t) {
    $check(str_contains($t->getMessage(), 'did not provide its required found flag'),
        'a cache wrapper that omits the found flag is refused');
}
$check($GLOBALS['wprism_native_delete_calls'] === [],
    'an unverifiable cache read refuses before delete_transient() is called');

// A conventional non-false cache value and both option rows must be observed
// in the receipt, then removed by the real NativeActions execution path.
$resetNativeActionRuntime();
$ordinaryName = 'native_ordinary_value';
$wpdb->optionRows = [
    '_transient_' . $ordinaryName => 'persisted-value',
    '_transient_timeout_' . $ordinaryName => '4102444800',
];
$GLOBALS['wprism_native_cache']['transient'] = [$ordinaryName => 'cached-value'];
$ordinaryReceipt = $deleteNativeTransient($ordinaryName);
$check(
    ($ordinaryReceipt['before'] ?? null) === ['value_row' => true, 'timeout_row' => true, 'cached' => true]
        && ($ordinaryReceipt['after'] ?? null) === ['value_row' => false, 'timeout_row' => false, 'cached' => false]
        && ($ordinaryReceipt['verified'] ?? null) === true,
    'an ordinary persistent cache value and both option rows are observed then removed'
);
$check($GLOBALS['wprism_native_delete_calls'] === [$ordinaryName]
    && $wpdb->optionRows === []
    && !array_key_exists($ordinaryName, $GLOBALS['wprism_native_cache']['transient'] ?? []),
    'ordinary transient deletion removes the exact cache key and both option rows');

// A persistent cache can legitimately store boolean false. wp_cache_get()
// returns false for both that value and a miss, so NativeActions must pass and
// honor WordPress's by-reference $found flag rather than test the return value.
$resetNativeActionRuntime();
$falseName = 'native_false_value';
$GLOBALS['wprism_native_cache']['transient'] = [$falseName => false];
$falseReceipt = $deleteNativeTransient($falseName);
$check(
    ($falseReceipt['before'] ?? null) === ['value_row' => false, 'timeout_row' => false, 'cached' => true]
        && ($falseReceipt['after'] ?? null) === ['value_row' => false, 'timeout_row' => false, 'cached' => false]
        && ($falseReceipt['verified'] ?? null) === true,
    'a boolean-false cache entry is observed as present and is removed'
);
$check($GLOBALS['wprism_native_cache_reads'] === [
    ['key' => $falseName, 'group' => 'transient', 'arity' => 4, 'found' => true, 'value' => false],
    ['key' => $falseName, 'group' => 'transient', 'arity' => 4, 'found' => false, 'value' => false],
], 'NativeActions uses wp_cache_get(..., &$found) to distinguish false from a miss');

// A false return from delete_transient() is ambiguous. If a false-valued cache
// entry survives a no-op/failed delete, post-action readback must throw and no
// receipt may claim verified=true merely because the cached value is false.
$resetNativeActionRuntime();
$failedFalseName = 'native_false_survivor';
$GLOBALS['wprism_native_cache']['transient'] = [$failedFalseName => false];
$GLOBALS['wprism_native_delete_mode'] = 'no-op';
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
$check(array_key_exists($failedFalseName, $GLOBALS['wprism_native_cache']['transient'] ?? [])
    && $GLOBALS['wprism_native_cache']['transient'][$failedFalseName] === false,
    'the failed-delete fixture genuinely leaves the boolean-false cache entry present for readback');

echo "\n== provider checked reads: the read twin of Db, same message hygiene (issue #3317) ==\n";
// The SQL a provider hands the SDK can carry option/meta payloads (the value
// kind WPrism keeps out of diagnostics), so this string embeds a secret the
// message must never echo — the read twin of Db's operation-level context rule.
$secretSql = "SELECT option_value FROM wp_options WHERE option_name='wprism_secret_CHECKED_READ_SECRET'";
$readContext = 'probe cache group lookup';
$readFake = new CheckedReadFakeWpdb();

$readFake->varReturn = '42';
$check(\WPrism\ProviderSdk::checked_get_var($secretSql, $readContext, $readFake) === '42',
    'checked_get_var returns a real scalar value untouched');
$readFake->colReturn = ['a', 'b'];
$check(\WPrism\ProviderSdk::checked_get_col($secretSql, $readContext, $readFake) === ['a', 'b'],
    'checked_get_col returns the column array');
$readFake->colReturn = [];
$check(\WPrism\ProviderSdk::checked_get_col($secretSql, $readContext, $readFake) === [],
    'checked_get_col passes a genuinely empty column through — an empty result is not a failure');
$readFake->rowReturn = ['id' => '7', 'slug' => 'x'];
$check(\WPrism\ProviderSdk::checked_get_row($secretSql, $readContext, $readFake) === ['id' => '7', 'slug' => 'x'],
    'checked_get_row returns an ARRAY_A row');
$readFake->rowReturn = null;
$check(\WPrism\ProviderSdk::checked_get_row($secretSql, $readContext, $readFake) === null,
    'checked_get_row passes a genuine null (no matching row) through');
$readFake->resultsReturn = [['id' => '1'], ['id' => '2']];
$check(\WPrism\ProviderSdk::checked_get_results($secretSql, $readContext, $readFake) === [['id' => '1'], ['id' => '2']],
    'checked_get_results returns the ARRAY_A rows');

// last_error is cleared before the read: a stale error from a prior query does
// not doom a clean one (the same posture Db's mutations take).
$readFake = new CheckedReadFakeWpdb();
$readFake->last_error = 'stale DRIVER_SECRET from an earlier query';
$readFake->varReturn = 'ok';
$check(\WPrism\ProviderSdk::checked_get_var($secretSql, $readContext, $readFake) === 'ok',
    'a stale last_error from a previous query is cleared before the read and does not fail a clean one');

$checkedReadThrows = static function (callable $body, string $label) use ($check, $readContext): void {
    try {
        $body();
        $check(false, "$label (expected a RuntimeException, none thrown)");
    } catch (\RuntimeException $e) {
        $msg = $e->getMessage();
        $check(
            str_contains($msg, "provider checked read failed: $readContext")
                && !str_contains($msg, 'CHECKED_READ_SECRET')  // no SQL text
                && !str_contains($msg, 'DRIVER_SECRET')        // no last_error text
                && !str_contains($msg, 'WooCommerce'),         // no plugin literal
            "$label (message: $msg)"
        );
    }
};

// A driver error is a failure even when the value itself looks fine — and
// neither the SQL nor the driver text may appear in the message.
$readFake = new CheckedReadFakeWpdb();
$readFake->varReturn = '42';
$readFake->errorOnRead = 'MySQL error near DRIVER_SECRET';
$checkedReadThrows(fn() => \WPrism\ProviderSdk::checked_get_var($secretSql, $readContext, $readFake),
    'a non-empty last_error throws even behind a plausible value, and the message carries neither the SQL nor the driver text');

// Each read's own failure shape throws, and each redacts identically.
$readFake = new CheckedReadFakeWpdb();
$readFake->varReturn = false;
$checkedReadThrows(fn() => \WPrism\ProviderSdk::checked_get_var($secretSql, $readContext, $readFake),
    'checked_get_var throws on a false return (the wpdb failure sentinel), naming only the context');
$readFake = new CheckedReadFakeWpdb();
$readFake->colReturn = false;
$checkedReadThrows(fn() => \WPrism\ProviderSdk::checked_get_col($secretSql, $readContext, $readFake),
    'checked_get_col throws on a non-array return');
$readFake = new CheckedReadFakeWpdb();
$readFake->rowReturn = 'not-an-array';
$checkedReadThrows(fn() => \WPrism\ProviderSdk::checked_get_row($secretSql, $readContext, $readFake),
    'checked_get_row throws on a non-array, non-null return');
$readFake = new CheckedReadFakeWpdb();
$readFake->resultsReturn = null;
$checkedReadThrows(fn() => \WPrism\ProviderSdk::checked_get_results($secretSql, $readContext, $readFake),
    'checked_get_results throws on a non-array return');
$readFake = new CheckedReadFakeWpdb();
$readFake->resultsReturn = ['aliased' => ['id' => '1']];
$checkedReadThrows(fn() => \WPrism\ProviderSdk::checked_get_results($secretSql, $readContext, $readFake),
    'checked_get_results throws on an associative outer result instead of silently reindexing it');

echo "\n== negotiation: the supported path ==\n";
$reset();
$policy = $policyFor($manifest);
$selected = $policy->actions_for(['post:probe']);
$check(count($selected) === 1, 'the unscoped probe action is selected by a non-empty surface set');
$check($policy->actions_for([]) === [], 'an empty surface set selects nothing, so nothing is negotiated');
$negotiation = \WPrism\Providers::negotiate($policy, $policy->actions_for([]));
// `surface_observation` is the fourth key negotiation carries: per bound
// capability, which of its declared surfaces the engine will read either side
// of invoke(). Nothing bound here, so it is empty for the same reason the other
// three are — the exact equality is what proves no provider code ran.
$check($negotiation === [
    'problems' => [],
    'providers' => [],
    'capabilities' => [],
    'surface_observation' => [],
], 'a run selecting no provider action touches no provider code at all');

$negotiation = \WPrism\Providers::negotiate($policy, $selected);
$check($negotiation['problems'] === [], 'an installed, active, in-range provider with a matching identity negotiates clean');
$check($negotiation['providers']['probe-cache'] instanceof \WPrism\Providers\ProbeCache,
    'the manifest-shipped provider class is loaded from <manifests_dir>/providers/<id>.php');
$check(($negotiation['capabilities']['probe-cache']['flush']['scope'] ?? null) === 'site',
    'the negotiated capability declaration is bound for the rebuild pass');

// Schema settlement is the only provider phase allowed to create a table
// before capture can observe it. The manifest therefore binds both the exact
// site scope and every prepared table to the live provider declaration; a
// generic provider that merely happens to expose the same capability name is
// not enough authority to perform pre-observation DDL.
$schemaManifest = $manifest;
$schemaManifest['engine_features'] = ['schema-settlement/v1', 'spec-window/v1'];
$schemaManifest['spec_version'] = 3;
$schemaManifest['providers'][0]['capabilities'] = ['flush', 'inspect_schema'];
$schemaManifest['tables'] = ['probe_projection' => ['class' => 'derived']];
$schemaManifest['actions'][0] = [
    'args' => [],
    'capability' => 'flush',
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
    'provider' => 'probe-cache',
    'readiness' => 'inspect_schema',
];
$schemaReadiness = [
    'args' => [],
    'idempotent' => true,
    'reads' => ['table:probe_projection'],
    'scope' => 'site',
    'timeout_seconds' => 30,
    'writes' => [],
];
$reset();

\WPrism\Providers\ProbeCache::$extraCapabilities = ['inspect_schema' => $schemaReadiness];
\WPrism\Providers\ProbeCache::$capabilityOverrides = [
    'args' => [],
    'reads' => ['table:probe_projection'],
];
$schemaPolicy = $policyFor($schemaManifest);
$schemaNegotiation = \WPrism\Providers::negotiate($schemaPolicy, $schemaPolicy->schema_settle_actions());
$check(count($schemaNegotiation['problems']) === 1
    && ($schemaNegotiation['problems'][0]['code'] ?? null) === 'schema_settlement_contract'
    && str_contains((string) ($schemaNegotiation['problems'][0]['found'] ?? ''), 'non-exact schema surfaces'),
    'schema settlement refuses a provider that does not advertise every exact prepared table write');

$reset();
\WPrism\Providers\ProbeCache::$extraCapabilities = ['inspect_schema' => $schemaReadiness];
\WPrism\Providers\ProbeCache::$capabilityOverrides = [
    'args' => [],
    'reads' => ['table:probe_projection'],
    'scope' => 'entity',
    'writes' => ['table:probe_projection'],
];
$schemaNegotiation = \WPrism\Providers::negotiate($schemaPolicy, $schemaPolicy->schema_settle_actions());
$check(count($schemaNegotiation['problems']) === 1
    && ($schemaNegotiation['problems'][0]['code'] ?? null) === 'schema_settlement_contract'
    && str_contains((string) ($schemaNegotiation['problems'][0]['found'] ?? ''), 'entity scope'),
    'schema settlement refuses a table-writing capability whose live scope is narrower than the whole site');

$reset();
\WPrism\Providers\ProbeCache::$extraCapabilities = ['inspect_schema' => $schemaReadiness];
\WPrism\Providers\ProbeCache::$capabilityOverrides = [
    'args' => ['mode' => ['type' => 'string', 'required' => false]],
    'reads' => ['table:probe_projection'],
    'writes' => ['table:probe_projection'],
];
$schemaNegotiation = \WPrism\Providers::negotiate($schemaPolicy, $schemaPolicy->schema_settle_actions());
$check(count($schemaNegotiation['problems']) === 1
    && ($schemaNegotiation['problems'][0]['code'] ?? null) === 'schema_settlement_contract'
    && str_contains((string) ($schemaNegotiation['problems'][0]['found'] ?? ''), 'optional arguments'),
    'schema settlement refuses a plugin-sourced prepare capability that advertises even optional arguments');

$reset();
\WPrism\Providers\ProbeCache::$extraCapabilities = ['inspect_schema' => $schemaReadiness];
\WPrism\Providers\ProbeCache::$capabilityOverrides = [
    'args' => [],
    'reads' => ['table:probe_projection'],
    'writes' => ['table:probe_projection'],
];
$schemaNegotiation = \WPrism\Providers::negotiate($schemaPolicy, $schemaPolicy->schema_settle_actions());
$check($schemaNegotiation['problems'] === []
    && isset($schemaNegotiation['capabilities']['probe-cache']['flush']),
    'schema settlement binds only when the live capability is idempotent, site-scoped, and names every prepared table');
$reset();

// `effects: []` is deliberately a two-sided contract: the manifest opts out
// of recovery inventory, and the selected live provider must independently
// prove that it writes no canonical state. Omission remains the legacy
// irreversible fallback and an unselected action does not contact its code.
$readOnlyManifest = $manifest;
$readOnlyManifest['actions'][0]['effects'] = [];
$reset();
$readOnlyPolicy = $policyFor($readOnlyManifest);
$notSelected = \WPrism\Providers::negotiate($readOnlyPolicy, $readOnlyPolicy->actions_for([]));
$check($notSelected === ['problems' => [], 'providers' => [], 'capabilities' => [], 'surface_observation' => []],
    'an unselected explicit read-only action loads and negotiates no provider');
$writeMismatch = \WPrism\Providers::negotiate(
    $readOnlyPolicy,
    $readOnlyPolicy->actions_for(['post:probe'])
);
$check(count($writeMismatch['problems']) === 1
    && ($writeMismatch['problems'][0]['code'] ?? null) === 'read_only_effect_mismatch'
    && str_contains((string) ($writeMismatch['problems'][0]['expected'] ?? ''), 'writes: []')
    && $writeMismatch['providers'] === [],
    'a selected empty-effect action refuses before invocation when its capability advertises a write');
$mixedEffectManifest = $manifest;
$mixedEffectManifest['actions'][0]['effects'] = [];
$mixedEffectManifest['actions'][] = [
    'kind' => 'provider',
    'provider' => 'probe-cache',
    'capability' => 'flush',
    'args' => ['groups' => ['second-selection']],
    'effects' => [[
        'id' => 'probe-effectful-selection',
        'kind' => 'database',
        'mode' => 'restorable',
        'selector' => ['scope' => 'database_checkpoint', 'type' => 'option', 'value' => 'probe_setting'],
    ]],
];
$reset();
$mixedEffectPolicy = $policyFor($mixedEffectManifest);
$mixedEffectNegotiation = \WPrism\Providers::negotiate(
    $mixedEffectPolicy,
    $mixedEffectPolicy->actions_for(['post:probe'])
);
$check(count($mixedEffectNegotiation['problems']) === 1
    && ($mixedEffectNegotiation['problems'][0]['code'] ?? null) === 'read_only_effect_mismatch'
    && $mixedEffectNegotiation['providers'] === [],
    'two selected actions sharing one capability validate independently, so an effectful sibling cannot hide an empty-effect/write mismatch');
$reset();
\WPrism\Providers\ProbeCache::$capabilityOverrides = ['writes' => []];
$readOnlyNegotiation = \WPrism\Providers::negotiate(
    $readOnlyPolicy,
    $readOnlyPolicy->actions_for(['post:probe'])
);
$check($readOnlyNegotiation['problems'] === []
    && ($readOnlyNegotiation['capabilities']['probe-cache']['flush']['writes'] ?? null) === [],
    'a selected empty-effect action binds only when the exact capability advertises writes: []');
$reset();

echo "\n== negotiation: every refusal names expected, found, and a remediation ==\n";
$problemFor = static function (array $mutate, ?callable $before = null) use ($policyFor, $manifest, $reset): array {
    $reset();
    if ($before !== null) {
        $before();
    }
    $m = $mutate === [] ? $manifest : array_replace_recursive($manifest, $mutate);
    $policy = $policyFor($m);
    $negotiation = \WPrism\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
    return $negotiation['problems'];
};
$one = static function (array $problems) use ($check): array {
    $check(count($problems) === 1, 'exactly one problem row is reported');
    return $problems[0] ?? [];
};
$providerSecret = "https://provider.example.test/rebuild?access_token=WPRISM_PROVIDER_SECRET\nINJECTED_PROVIDER_LINE";
$opaqueProviderProblem = static function (array $problem): bool {
    $serialized = json_encode($problem, JSON_THROW_ON_ERROR);
    return !str_contains($serialized, 'WPRISM_PROVIDER_SECRET')
        && !str_contains($serialized, 'INJECTED_PROVIDER_LINE')
        && !str_contains((string) ($problem['found'] ?? ''), "\n");
};
$opaqueProviderRefusal = static function (array $problem, string $code, string $found) use ($opaqueProviderProblem): bool {
    return ($problem['code'] ?? '') === $code
        && ($problem['found'] ?? '') === $found
        && $opaqueProviderProblem($problem);
};

$p = $one($problemFor([], static function (): void {
    $GLOBALS['wprism_test_plugins'] = [];
    $GLOBALS['wprism_test_active'] = [];
}));
$check(($p['code'] ?? '') === 'missing_plugin' && str_contains($p['remediation'] ?? '', 'install and activate'),
    'an uninstalled owning plugin is refused with an install remediation');

$p = $one($problemFor([], static function (): void {
    $GLOBALS['wprism_test_active'] = [];
}));
$check(($p['code'] ?? '') === 'inactive_plugin' && str_contains($p['remediation'] ?? '', 'wprism deploy'),
    'an inactive owning plugin is refused and pointed at deploy');

$p = $one($problemFor([], static function (): void {
    $GLOBALS['wprism_test_plugins']['probe/probe.php']['Version'] = '2.4.0';
}));
$check(($p['code'] ?? '') === 'outside_version_range'
    && ($p['expected'] ?? '') === '>=1.0.0 <2.0.0'
    && ($p['found'] ?? '') === '2.4.0',
    'a live plugin version outside the declaring manifest range is refused with both versions named');

$p = $one($problemFor([], static function () use ($providerSecret): void {
    \WPrism\Providers\ProbeCache::$identityOverrides = ['version' => $providerSecret];
}));
$check(($p['code'] ?? '') === 'identity_mismatch'
    && str_contains($p['expected'] ?? '', 'version=1.0.0')
    && ($p['found'] ?? '') === 'identity() did not match the declared provider identity'
    && $opaqueProviderProblem($p),
    'a provider identity mismatch is structured without exposing returned identity values');

$p = $one($problemFor(
    ['actions' => [['capability' => 'purge']], 'providers' => [['capabilities' => ['purge']]]],
    static function () use ($providerSecret): void {
        \WPrism\Providers\ProbeCache::$capabilityMapOverride = [$providerSecret => []];
    }
));
$check(($p['code'] ?? '') === 'missing_capability'
    && ($p['found'] ?? '') === 'provider did not advertise the declared capability'
    && $opaqueProviderProblem($p),
    'a missing capability is structured without exposing advertised capability names');

$p = $one($problemFor([], static function (): void {
    \WPrism\Providers\ProbeCache::$capabilityMapOverride = [];
}));
$check(($p['code'] ?? '') === 'missing_capability'
    && ($p['found'] ?? '') === 'provider did not advertise the declared capability',
    'an empty advertised capability map is an honest unavailable-target refusal, not a malformed-list diagnosis');

$p = $one($problemFor([], static function (): void {
    \WPrism\Providers\ProbeCache::$capabilityMapOverride = [[]];
}));
$check(($p['code'] ?? '') === 'contract_shape'
    && ($p['found'] ?? '') === 'capabilities() did not return a name => declaration map',
    'a non-empty positional capability list remains a malformed provider contract');

$p = $one($problemFor([], static function (): void {
    \WPrism\Providers\ProbeCache::$capabilityOverrides = ['idempotent' => false];
}));
$check(($p['code'] ?? '') === 'non_idempotent_capability' && str_contains($p['remediation'] ?? '', 'retry'),
    "a non-idempotent capability is refused because apply's retry re-fires the rebuild pass");

$p = $one($problemFor(['actions' => [['args' => ['groups' => 'not-a-list']]]]));
$check(($p['code'] ?? '') === 'invalid_capability_args'
    && ($p['found'] ?? '') === 'action arguments do not match advertised schema',
    'manifest arguments that do not match the provider-declared schema are refused without echoing validator text');

$p = $one($problemFor([], static function () use ($providerSecret): void {
    \WPrism\Providers\ProbeCache::$capabilityOverrides = [$providerSecret => true];
}));
$check(($p['code'] ?? '') === 'malformed_capability'
    && ($p['found'] ?? '') === 'provider advertised a malformed capability declaration'
    && $opaqueProviderProblem($p),
    'a malformed capability is refused without exposing provider-controlled schema keys');

$p = $one($problemFor([], static function () use ($providerSecret): void {
    \WPrism\Providers\ProbeCache::$capabilitiesThrows = $providerSecret;
}));
$check(($p['code'] ?? '') === 'contract_shape'
    && ($p['expected'] ?? '') === 'capabilities() returning a name => declaration map'
    && ($p['found'] ?? '') === 'capabilities() threw'
    && $opaqueProviderProblem($p),
    'a provider whose capabilities() throws becomes a structured, redacted contract problem');

$p = $one($problemFor([], static function () use ($providerSecret): void {
    \WPrism\Providers\ProbeCache::$identityThrows = $providerSecret;
}));
$check(($p['code'] ?? '') === 'contract_shape'
    && ($p['expected'] ?? '') === 'identity() returning an array'
    && ($p['found'] ?? '') === 'identity() threw'
    && $opaqueProviderProblem($p),
    'a provider whose identity() throws becomes a structured, redacted contract problem');

echo "\n== negotiation: the declared `requires` contract, enforced before the provider loads (issue #3317) ==\n";

// A required function absent in this environment: one aggregated
// provider_requirement_unmet naming the function, before the provider file is
// ever loaded (this manifest source would otherwise construct it below).
$p = $one($problemFor(['providers' => [['requires' => ['functions' => ['wprism_absent_probe_function']]]]]));
$check(($p['code'] ?? '') === 'provider_requirement_unmet'
    && str_contains($p['expected'] ?? '', 'functions wprism_absent_probe_function')
    && str_contains($p['found'] ?? '', 'missing functions: wprism_absent_probe_function')
    && trim($p['remediation'] ?? '') !== ''
    && $opaqueProviderProblem($p),
    'a required function absent here refuses with provider_requirement_unmet, naming the function and a remediation');

$p = $one($problemFor(['providers' => [['requires' => ['classes' => ['WPrismAbsentProbeClass']]]]]));
$check(($p['code'] ?? '') === 'provider_requirement_unmet'
    && str_contains($p['found'] ?? '', 'missing classes: WPrismAbsentProbeClass'),
    'a required class absent here refuses the same way, naming the class');

// plugin_version bounds INDEPENDENTLY of the manifest version_range: installed
// 1.5.0 satisfies the manifest 1.0.0–2.0.0 window (no outside_version_range
// row), yet fails the tighter requires window — so the code proves it is the
// requirement gate, not the range gate, that refused.
$p = $one($problemFor(['providers' => [['requires' => ['plugin_version' => ['min' => '1.6.0', 'max' => '2.0.0']]]]]));
$check(($p['code'] ?? '') === 'provider_requirement_unmet'
    && str_contains($p['expected'] ?? '', 'plugin_version >=1.6.0 <2.0.0')
    && str_contains($p['found'] ?? '', 'plugin 1.5.0'),
    'a plugin_version requirement bounds independently of the manifest version_range — 1.5.0 passes the manifest window, fails the tighter requires window, and refuses as provider_requirement_unmet not outside_version_range');

// wordpress_version reads get_bloginfo('version') the way probe_target does.
$p = $one($problemFor(
    ['providers' => [['requires' => ['wordpress_version' => ['min' => '6.0', 'max' => '7.0']]]]],
    static function (): void { $GLOBALS['wprism_test_wp_version'] = '5.0'; }
));
$check(($p['code'] ?? '') === 'provider_requirement_unmet'
    && str_contains($p['expected'] ?? '', 'wordpress_version >=6.0 <7.0')
    && str_contains($p['found'] ?? '', 'wordpress 5.0'),
    'a WordPress version below the declared window refuses, distinct from the plugin version_range gate');

// php_version reads PHP_VERSION directly; an impossible-high window is unmet on
// any runner.
$p = $one($problemFor(['providers' => [['requires' => ['php_version' => ['min' => '99.0', 'max' => '99.1']]]]]));
$check(($p['code'] ?? '') === 'provider_requirement_unmet'
    && str_contains($p['expected'] ?? '', 'php_version >=99.0 <99.1')
    && str_contains($p['found'] ?? '', 'php ' . PHP_VERSION),
    'a PHP version outside the declared window refuses, reading PHP_VERSION directly');

// Every unmet requirement in ONE row (negotiate names the whole gap at once).
$p = $one($problemFor(['providers' => [['requires' => [
    'functions' => ['wprism_absent_probe_function'],
    'php_version' => ['min' => '99.0', 'max' => '99.1'],
]]]]));
$check(($p['code'] ?? '') === 'provider_requirement_unmet'
    && str_contains($p['found'] ?? '', 'missing functions: wprism_absent_probe_function')
    && str_contains($p['found'] ?? '', 'php ' . PHP_VERSION),
    'multiple unmet requirements aggregate into one problem row, so an operator sees the whole environment gap at once');

// The ordering proof: identity() is rigged to throw, yet the refusal is the
// requirement row, not contract_shape — the requirement gate ran BEFORE the
// provider was constructed and asked for identity().
$p = $one($problemFor(
    ['providers' => [['requires' => ['functions' => ['wprism_absent_probe_function']]]]],
    static function () use ($providerSecret): void {
        \WPrism\Providers\ProbeCache::$identityThrows = $providerSecret;
    }
));
$check(($p['code'] ?? '') === 'provider_requirement_unmet' && $opaqueProviderProblem($p),
    'the requirement gate fires before the provider is loaded: identity() is rigged to throw, yet the row is '
    . 'provider_requirement_unmet — a contract_shape "identity() threw" here would mean the object had already been built');

// Every declared requirement satisfied negotiates byte-for-byte like a
// declaration with no requires: the provider loads and binds.
$reset();
$GLOBALS['wprism_test_wp_version'] = '6.5';
$satisfied = array_replace_recursive($manifest, ['providers' => [['requires' => [
    'functions' => ['strlen'],
    'classes' => ['stdClass'],
    'plugin_version' => ['min' => '1.0.0', 'max' => '2.0.0'],
    'wordpress_version' => ['min' => '6.0', 'max' => '7.0'],
    'php_version' => ['min' => '8.0', 'max' => '99.0'],
]]]]);
$negotiation = \WPrism\Providers::negotiate($policyFor($satisfied), $policyFor($satisfied)->actions_for(['post:probe']));
$check($negotiation['problems'] === [],
    'a provider whose every declared requirement is satisfied negotiates clean, exactly as one with no requires block');
$check(($negotiation['providers']['probe-cache'] ?? null) instanceof \WPrism\Providers\ProbeCache,
    'and the provider is loaded and bound once the requirement gate passes');
$GLOBALS['wprism_test_wp_version'] = '';

echo "\n== the same detection, reported at plan (issue #3339) ==\n";
// spec/repo-format.md's bound (4) was that negotiation ran at APPLY only, so a
// missing or incompatible provider was invisible until the promotion that
// needed it. It now runs as a read-only question at plan too — and the whole
// value of that depends on it being the SAME question. These checks are what
// makes "same" falsifiable: negotiate() must BE diagnose(), and the rows plan
// reports must be the rows apply would refuse on.
$reset();
$GLOBALS['wprism_test_active'] = [];
$policy = $policyFor($manifest);
$selected = $policy->actions_for(['post:probe']);
$negotiated = \WPrism\Providers::negotiate($policy, $selected);
$diagnosed = \WPrism\Providers::diagnose($policy, $selected);
$check($negotiated == $diagnosed && $negotiated['problems'] !== [],
    'diagnose() and negotiate() return the identical result for a broken selection — one body, not two implementations');
$check(array_column($diagnosed['problems'], 'code') === ['inactive_plugin'],
    'and it is the real problem row, with the real code, not an empty stand-in');

$negotiateSource = implode("\n", array_slice(
    (array) file($root . '/agent/src/Adapter/Providers.php', FILE_IGNORE_NEW_LINES),
    (new \ReflectionMethod(\WPrism\Providers::class, 'negotiate'))->getStartLine() - 1,
    2
));
$check((bool) preg_match('/return self::diagnose\(\$policy, \$selectedActions\);/', $negotiateSource),
    'negotiate() is literally the delegation — a second copy of the loop could pass the equality check above on the '
    . 'day it was written and drift the day after, so the sharing itself is pinned');

$reset();
$GLOBALS['wprism_test_active'] = [];
$policy = $policyFor($manifest);
$check(\WPrism\Providers::negotiate($policy, $policy->actions_for([]))['problems'] === [],
    'a read-only apply selects no action, so apply negotiates nothing and refuses nothing');
$planProblems = \WPrism\Providers::problems($policy);
$check(array_column($planProblems, 'code') === ['inactive_plugin'],
    'while the PLAN view still reports the inactive plugin: it covers every provider action the PINNED manifests '
    . 'declare, so "no problems" can never mean "this run happened to look at nothing"');
$check(($planProblems[0]['manifest'] ?? '') === 'probe' && ($planProblems[0]['plugin'] ?? '') === 'probe/probe.php'
    && trim($planProblems[0]['remediation'] ?? '') !== '',
    'and each row names the declaring manifest, the owning plugin, and a remediation — the three things an operator '
    . 'needs to know which pin to go fix');

$reset();
$policy = $policyFor($manifest);
$check(\WPrism\Providers::problems($policy) === [], 'a healthy environment reports no provider problems at plan');

echo "\n== missing manifest provider: reporting stays visible while apply stays fail-closed ==\n";

// Policy promotes narrowed provider findings only when the loaded library
// carries the reviewed dispositions that real plan / status consumers do.
// Since the evidence apparatus was retired those dispositions are the WHOLE
// authored claim source — there is no second generated registry to freeze
// beside them — so the smallest valid experimental entry is the entire
// fixture, and it exercises Policy::provider_readiness_blockers() rather than
// only the wider Providers::problems() helper above.
$disposition = [
    'status' => 'experimental',
    'reason' => 'provider readiness fixture',
    'supported_versions' => [
        'plugin' => 'probe/probe.php',
        'range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    ],
    'capabilities' => [
        'entity_sections' => [],
        'field_sections' => [],
        'operations' => ['apply'],
        'lifecycle_phases' => [],
        'deletion_semantics' => ['supported' => [], 'unsupported' => ['fixture-delete']],
    ],
    'unsupported' => [[
        'surface' => 'fixture',
        'operation' => 'apply',
        'reason' => 'test-only capability claim',
    ]],
    'default_authored_keyspaces' => [],
];
$dispositionSnapshot = [
    'format' => \WPrism\ManifestDispositions::FORMAT,
    'manifests' => ['probe' => $disposition],
    'profiles' => [],
];
file_put_contents($dir . '/probe.json', \WPrism\Canon::encode($manifest));
$reviewedPolicy = \WPrism\Policy::from_snapshot([
    // v6 is the generation that carries dispositions and NO `capabilities`
    // record. The key set is closed, so a snapshot still carrying the retired
    // generated registry is refused rather than read with the record ignored —
    // pinned directly below.
    'format' => 'wprism-policy-snapshot/v6',
    'adapter_sources' => [
        'format' => 'wprism-adapter-sources/v2',
        'certificates' => [],
        'out_of_tree' => [],
    ],
    'dispositions' => $dispositionSnapshot,
    'site' => [
        'manifests' => ['probe'],
        'spec_version' => WPRISM_SPEC_VERSION,
        'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
    ],
    'manifests' => [$manifest],
], $libraryFor($manifest));
$v6Snapshot = $reviewedPolicy->export_snapshot();
$check(!array_key_exists('capabilities', $v6Snapshot)
    && ($v6Snapshot['format'] ?? null) === 'wprism-policy-snapshot/v6',
    'a v6 snapshot round trip emits dispositions and no `capabilities` record at all');
// The retired generation is a REFUSAL, not an ignored key. A v5 snapshot froze
// a generated registry this agent no longer validates against anything, so
// reading it would verify a record nobody checked; the closed key set is what
// makes that loud.
$v5Snapshot = $v6Snapshot;
$v5Snapshot['format'] = 'wprism-policy-snapshot/v5';
$v5Snapshot['capabilities'] = ['format' => 'wprism-capability-registry/v2', 'manifests' => []];
$v5Refusal = '';
try {
    \WPrism\Policy::from_snapshot($v5Snapshot, $reviewedPolicy->adapter_library());
} catch (\Throwable $t) {
    $v5Refusal = $t->getMessage();
}
$check(str_contains($v5Refusal, 'frozen policy snapshot has an unsupported or malformed shape'),
    'a v5 snapshot carrying the retired generated registry is refused by name, not silently accepted');
// v4 is refused by FORMAT, ahead of the key gate, and this suite owns that
// contract for the whole corpus. Two shapes reach it and both must be named:
//
//  - the six-key document every v4 export any engine version ever wrote (v4 was
//    current from 55538ad, and export_snapshot() emitted `capabilities` for its
//    whole life), which the closed key set would otherwise answer with the
//    generic "malformed shape" and never mention the format; and
//  - the five-key document nothing ever wrote, which is the ONLY shape the
//    retired read path could still accept and therefore the only thing it was
//    still verifying.
//
// The refusal names wprism-adapter-sources/v1 because that record — not the
// envelope — is what made v4 worth removing: a manifest absent from
// `out_of_tree` took shipped authority there with no proof at all.
foreach ([
    'a genuine v4 document (six keys, `capabilities` included)' => static function (array $s): array {
        $s['format'] = 'wprism-policy-snapshot/v4';
        $s['capabilities'] = ['format' => 'wprism-capability-registry/v2', 'manifests' => []];
        $s['adapter_sources'] = ['format' => 'wprism-adapter-sources/v1', 'out_of_tree' => []];
        return $s;
    },
    'a hand-built v4 document (five keys, the only shape the retired path accepted)' => static function (array $s): array {
        $s['format'] = 'wprism-policy-snapshot/v4';
        $s['adapter_sources'] = ['format' => 'wprism-adapter-sources/v1', 'out_of_tree' => []];
        return $s;
    },
] as $label => $mutate) {
    $v4Refusal = '';
    try {
        \WPrism\Policy::from_snapshot($mutate($v6Snapshot), $reviewedPolicy->adapter_library());
    } catch (\Throwable $t) {
        $v4Refusal = $t->getMessage();
    }
    $check(str_contains($v4Refusal, 'wprism-policy-snapshot/v4 is retired and is no longer read')
        && str_contains($v4Refusal, 'wprism-adapter-sources/v1')
        && str_contains($v4Refusal, 'wprism-policy-snapshot/v6'),
        "$label is refused with the retired format named, the reason given, and the current format offered "
        . "(got: $v4Refusal)");
}
// The matching half — that the retired v1 adapter-source RECORD has no reader
// left either — is pinned by regress_adapter_sources.php, whose subject the
// adapter-source wire is. This suite owns the snapshot generations only.
$reset();
$providerFile = $dir . '/providers/probe-cache.php';
rename($providerFile, $providerFile . '.hidden');
$readiness = $reviewedPolicy->provider_readiness_blockers(
    $reviewedPolicy->actions_for(['post:probe'])
);
$check(count($readiness) === 1
    && ($readiness[0]['code'] ?? '') === 'provider_code_unavailable'
    && ($readiness[0]['provider'] ?? '') === 'probe-cache'
    && ($readiness[0]['manifest'] ?? '') === 'probe',
    'plan/status readiness reports a missing manifest provider as a structured blocker instead of throwing');
$applyRefusal = false;
try {
    \WPrism\Providers::negotiate($reviewedPolicy, $reviewedPolicy->actions_for(['post:probe']));
} catch (\WPrism\ProviderPackagingException $failure) {
    $applyRefusal = str_contains($failure->getMessage(), 'provider code ships with its manifest');
}
$check($applyRefusal,
    'direct negotiation still throws the packaging fault for apply\'s fail-before-mutation gate');
$offlinePackaging = \WPrism\Providers::packaging_problems(
    $reviewedPolicy,
    $reviewedPolicy->actions_for(['post:probe'])
);
$check(count($offlinePackaging) === 1
    && ($offlinePackaging[0]['code'] ?? '') === 'provider_code_unavailable'
    && ($offlinePackaging[0]['provider'] ?? '') === 'probe-cache',
    'offline packaging inspection reports the missing manifest provider without loading its PHP');
rename($providerFile . '.hidden', $providerFile);
@unlink($dir . '/probe.json');

// issue #3314 shipped the NARROWED, gating diagnosis: build_plan() merges
// Policy::provider_readiness_blockers($selectedActions) into
// adapter_dispositions, which wprism status's exit code counts. The wide set must
// therefore not restate what the narrow one already gated on — one fact, one
// row, the same discipline AdapterSources::refuse() applies to installed files.
//
// That method needs a reviewed disposition set and a generated registry, which
// this harness's synthetic manifests dir deliberately has neither of (it exists
// to exercise the negotiation contract, not certification). So the row it would
// promote is BUILT here from the same problem row it starts from, and the field
// mapping is pinned against the real source rather than assumed.
$reset();
$GLOBALS['wprism_test_active'] = [];
$policy = $policyFor($manifest);
$wide = \WPrism\Providers::problems($policy);
$check(array_column($wide, 'code') === ['inactive_plugin'],
    'the wide plan view reports the inactive plugin when nothing has gated on it yet');
// issue #3348 slice 4: provider_readiness_blockers()'s row-building body moved
// from Policy.php into AdapterRegistry.php; Policy::provider_readiness_
// blockers() is still the public entry point this comment block describes,
// but the bytes pinned below now live in the file that actually builds them.
$adapterRegistrySource = (string) file_get_contents($root . '/agent/src/Adapter/AdapterRegistry.php');
$check(str_contains($adapterRegistrySource, "'name' => \$manifest,")
    && str_contains($adapterRegistrySource, "'provider' => (string) (\$problem['provider'] ?? '?'),")
    && str_contains($adapterRegistrySource, "'manifest' => \$manifest,")
    && str_contains($adapterRegistrySource, "'code' => (string) (\$problem['code'] ?? 'provider_negotiation_failed'),"),
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
$check(\WPrism\Providers::problems($policy, $promoted) === [],
    'so once that row is gating, the wide plan view reports NOTHING for it — a selected inactive plugin is one '
    . 'finding, not a BLOCKED disposition row plus a PROVIDER_PROBLEM row about the same provider');
$check(array_column(\WPrism\Providers::problems($policy), 'code') === ['inactive_plugin'],
    'while the same call with no gating rows still reports it, so the dedupe is subtraction and never suppression');
$check(\WPrism\Providers::problems($policy, [['provider' => 'probe-cache', 'manifest' => 'probe', 'code' => 'other']])
    !== [],
    'and the key is (provider, manifest, code): a DIFFERENT code for the same provider is a different finding and survives');
$reset();

// The runtime gate issue #3314 put on the narrowed diagnosis applies here too:
// with no loaded WordPress there is no plugin state to negotiate against, so
// every declared provider would report `missing_plugin` and an offline
// manifest-library load would manufacture a wall of findings about an
// environment it cannot see. This harness deliberately DOES define the four
// symbols that say WordPress is loaded, so the short-circuit is proved in a
// child process that defines three of them and omits ABSPATH — a constant
// cannot be undefined once set.
$gateProbe = $scratchRoot . '/gate-probe.php';
file_put_contents($gateProbe, <<<'PROBE'
<?php
// Deliberately NO define('ABSPATH', ...) — that is the whole subject.
define('WPRISM_SPEC_VERSION', 2);
define('WP_PLUGIN_DIR', __DIR__ . '/wp-plugins');
function apply_filters(string $hook, mixed $value): mixed { return $value; }
function get_option(string $name, mixed $default = false): mixed { return $default; }
function is_multisite(): bool { return false; }
$root = dirname(__DIR__, 1);
PROBE
. "\n\$engine = " . var_export($root, true) . ";\n"
. "\n\$libraryDir = " . var_export($dir, true) . ";\n"
. <<<'PROBE'
require $engine . '/agent/src/Kernel/Canon.php';
require $engine . '/agent/src/Kernel/OptionState.php';
require $engine . '/agent/src/Policy/Policy.php';
require $engine . '/agent/src/Code/CodeCompatibility.php';
require $engine . '/agent/src/Promotion/Deploy.php';
require $engine . '/agent/src/Adapter/Providers.php';
$manifest = json_decode(getenv('WPRISM_PROBE_MANIFEST'), true);
// $libraryDir is the parent's scratch package projection. The v6 wire proves shipped
// membership against that library instead of trusting the snapshot, so publish
// the frozen bytes before freezing them; the parent's shutdown removes it.
file_put_contents($libraryDir . '/' . $manifest['name'] . '.json', WPrism\Canon::encode($manifest));
$index = $libraryDir . '/providers/probe-index.php';
$hiddenIndex = dirname($libraryDir) . '/.probe-index.php';
$regenerator = $libraryDir . '/regenerators/probe-lookups.php';
$hiddenRegenerator = dirname($libraryDir) . '/.probe-lookups.php';
if (is_file($index)) {
    rename($index, $hiddenIndex);
}
if (is_file($regenerator)) {
    rename($regenerator, $hiddenRegenerator);
}
try {
    $library = WPrism\AdapterLibrary::fromLegacyFlatDirectory($libraryDir);
} finally {
    if (is_file($hiddenIndex)) {
        rename($hiddenIndex, $index);
    }
    if (is_file($hiddenRegenerator)) {
        rename($hiddenRegenerator, $regenerator);
    }
}
$policy = WPrism\Policy::from_snapshot([
    'format' => 'wprism-policy-snapshot/v6',
    'adapter_sources' => ['certificates' => [], 'format' => 'wprism-adapter-sources/v2', 'out_of_tree' => []],
    'dispositions' => null,
    'site' => [
        'manifests' => [$manifest['name']],
        'spec_version' => 2,
        'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []],
    ],
    'manifests' => [$manifest],
], $library);
echo json_encode([
    'gate' => WPrism\Providers::runtime_negotiation_available(),
    'problems' => count(WPrism\Providers::problems($policy)),
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
    'WPRISM_PROBE_MANIFEST=' . escapeshellarg((string) json_encode($manifest)) . ' '
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
$faultProblems = \WPrism\Providers::problems($policy);
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

// The OTHER branch, and the reason the two are not one code. issue #3314 has
// since converted every previously reachable foreign-throw path in diagnose()
// into a structured problem row of its own — a `wprism_providers` registry that
// throws is now `provider_registry_unavailable`, and identity()/capabilities()
// throwing are `contract_shape` — so the generic branch is a backstop with no
// reachable trigger left in this fixture. It is asserted against source rather
// than faked with a contrived throw: what matters is that a future unexpected
// throw is NOT labelled as the adapter's packaging fault and does NOT invent a
// providers/<id>.php coordinate for an identity nobody established.
$problemsSource = implode("\n", array_slice(
    (array) file($root . '/agent/src/Adapter/Providers.php', FILE_IGNORE_NEW_LINES),
    (new \ReflectionMethod(\WPrism\Providers::class, 'problems'))->getStartLine() - 1,
    (new \ReflectionMethod(\WPrism\Providers::class, 'problems'))->getEndLine()
        - (new \ReflectionMethod(\WPrism\Providers::class, 'problems'))->getStartLine() + 1
));
$check(str_contains($problemsSource, 'catch (ProviderPackagingException $t)')
    && str_contains($problemsSource, 'catch (\Throwable $t)'),
    'problems() catches the adapter packaging fault SEPARATELY from anything else that could throw');
$check(str_contains($problemsSource, "'provider_diagnosis_failed'")
    && str_contains($problemsSource, "'see the message"),
    'and the generic branch has its own code and points at the message instead of inventing a file to repair');
$packagingSource = implode("\n", array_slice(
    (array) file($root . '/agent/src/Adapter/Providers.php', FILE_IGNORE_NEW_LINES),
    (new \ReflectionMethod(\WPrism\Providers::class, 'packaging_problem'))->getStartLine() - 1,
    (new \ReflectionMethod(\WPrism\Providers::class, 'packaging_problem'))->getEndLine()
        - (new \ReflectionMethod(\WPrism\Providers::class, 'packaging_problem'))->getStartLine() + 1
));
$check(str_contains($problemsSource, 'self::packaging_problem($t)')
    && substr_count($packagingSource, 'providers/') === 1
    && !str_contains($packagingSource, '<id>'),
    'while only the shared packaging projection names a providers/ path, and never as a literal <id> placeholder');

// issue #3403 (PR #176 finding 5, site 3): the generic branch's `found` field is
// the ONE deliberately third-party-transparent string a problem row carries —
// it publishes a message from code the engine does not own, on purpose, on the
// `capabilities --format=json` surface. That transparency is kept, but a
// third-party exception embedding a credential or an absolute path is now
// floored by the shared secret/path screen reviewed typed refusals use.
// Tested
// directly because the branch is a backstop with no reachable trigger in this
// fixture (asserted above); the floor is a named helper so it is unit-drivable.
$check(str_contains($problemsSource, 'self::publishable_foreign_detail(get_class($t)'),
    'issue #3403: the generic diagnosis branch routes its third-party detail through the secret/path floor');
$foreignFloor = new \ReflectionMethod(\WPrism\Providers::class, 'publishable_foreign_detail');
$cleanDetail = 'RuntimeException: wprism_providers callback for adapter "acme" returned no identity';
$check($foreignFloor->invoke(null, $cleanDetail) === $cleanDetail,
    'issue #3403: a clean third-party diagnosis message publishes verbatim — the intended transparency is preserved');
$credentialLeak = 'RuntimeException: upstream rejected token sk_live_0123456789abcdef during identity()';
$pathLeak = 'RuntimeException: could not read /Users/deployer/.aws/credentials during capabilities()';
$redactedCredential = $foreignFloor->invoke(null, $credentialLeak);
$redactedPath = $foreignFloor->invoke(null, $pathLeak);
$check($redactedCredential !== $credentialLeak
    && !\WPrism\CommandRefusalException::containsSensitivePublicDetail($redactedCredential),
    'issue #3403: a credential-shaped third-party message is replaced by a bounded, secret-free placeholder');
$check($redactedPath !== $pathLeak
    && !\WPrism\CommandRefusalException::containsSensitivePublicDetail($redactedPath),
    'issue #3403: an absolute-path-shaped third-party message is replaced by a bounded, secret-free placeholder');
$check($redactedCredential === $redactedPath,
    'issue #3403: both trip to the same bounded placeholder, which carries no captured third-party bytes');
$reset();

// Apply::plan() is not offline-drivable (it loads policy, compiles the
// repository, snapshots a live target, and ensures a ledger), so its one
// threading edge is asserted against its own source — the idiom this file
// already uses for Apply::run() further down. Read textually rather than by
// reflection because Apply.php is not loaded until the batch-assembly group
// below; the slice boundaries are the two method signatures themselves, so a
// moved method does not silently widen what is being asserted.
$applySource = (string) file_get_contents($root . '/agent/src/Apply/ApplyRequestCoordinator.php');
$planAt = strpos($applySource, 'public static function plan(');
$buildPlanAt = strpos($applySource, 'private function build_plan(');
$check($planAt !== false && $buildPlanAt !== false && $planAt < $buildPlanAt,
    'Apply::plan() and Apply::build_plan() are both present, in that order (the slice below depends on it)');
$planSource = substr($applySource, (int) $planAt, (int) $buildPlanAt - (int) $planAt);
$formatAt = strpos($planSource, "$" . "plan['format'] = ScopedApply::PLAN_FORMAT;");
$artifactAt = strpos($planSource, "$" . "plan['artifact_hash'] = $" . "compiled->artifact_hash();");
$adaptersAt = strpos($planSource, "$" . "plan['resolved_adapters'] = $" . "compiled->resolved_adapters();");
$check($formatAt !== false && $artifactAt !== false && $adaptersAt !== false
    && $formatAt < $artifactAt && $artifactAt < $adaptersAt,
    'scoped Apply::plan() publishes the exact compiled artifact hash and resolved adapter witnesses required by scoped rollback authority');
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
// pure and is driven for real; agent/src/Command/Cli.php's half runs only inside a
// wp-cli plan, so it is asserted against its source.
$summary = \WPrism\Orchestrator\PlanSummary::render(['provider_problems' => $planProblems]);
$summaryText = implode("\n", $summary['lines']);
$check(str_contains($summaryText, '1 provider_problems'), 'wprism status counts provider problems in its summary line');
$check(str_contains($summaryText, 'PROVIDER_PROBLEM (')
    && str_contains($summaryText, 'manifest=probe plugin=probe/probe.php')
    && str_contains($summaryText, '[inactive_plugin]')
    && str_contains($summaryText, '    remediation: '),
    'and renders the provider, its declaring manifest, its owning plugin, the code, and the remediation');
$check($summary['ok'] === false,
    'and flips readiness: a known provider defect cannot share exit 0 with a green environment status');
$check(\WPrism\Orchestrator\PlanSummary::render(['conflict' => [['uuid' => 'x', 'type' => 'post']]])['ok'] === false,
    'while a bucket that DOES predict a refusal still flips it — the exclusion above is about width, not severity');

$cliSource = (string) file_get_contents($root . '/agent/src/Command/Cli.php');
$check(str_contains($cliSource, "foreach (\$plan['provider_problems'] ?? [] as \$r) {")
    && str_contains($cliSource, "'PROVIDER_PROBLEM '")
    && str_contains($cliSource, "count(\$plan['provider_problems'] ?? []) . ' provider_problems'"),
    'and `wp wprism plan` renders and counts the same rows, so the two commands never give one operator different advice');

echo "\n== plugin-sourced providers (a custom plugin advertising its own) ==\n";
$pluginSourced = array_replace_recursive($manifest, ['providers' => [['source' => 'plugin']]]);
$reset();
$policy = $policyFor($pluginSourced);
$p = $one(\WPrism\Providers::negotiate($policy, $policy->actions_for(['post:probe']))['problems']);
$check(($p['code'] ?? '') === 'missing_plugin_provider' && str_contains($p['expected'] ?? '', 'wprism_providers'),
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
$GLOBALS['wprism_test_provider_registry_throw'] = $providerSecret;
$registryUnavailablePolicy = $policyFor($registryUnavailableManifest);
$registryProblems = \WPrism\Providers::negotiate(
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
        && ($problem['expected'] ?? '') === 'a readable `wprism_providers` registry'
        && ($problem['found'] ?? '') === 'provider registry callback failed'
        && ($problem['remediation'] ?? '') === 'upgrade or disable the faulty provider plugin and retry'
        && !str_contains((string) ($problem['found'] ?? ''), "\n");
}
$check(
    $registryProblemsAreOpaque
    && $registryProblemProviders === ['probe-cache', 'probe-second']
    && !str_contains($registryProblemJson, 'WPRISM_PROVIDER_SECRET')
    && !str_contains($registryProblemJson, 'INJECTED_PROVIDER_LINE'),
    'a throwing wprism_providers registry blocks every selected plugin provider with one structured, redacted remediation'
);

$reset();
$GLOBALS['wprism_test_providers'] = [new \WPrism\Providers\ProbeSupplied()];
$negotiation = \WPrism\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
$check($negotiation['problems'] === [] && isset($negotiation['providers']['probe-cache']),
    'a provider advertised on the wprism_providers filter is discovered and negotiated by its own identity()');

$reset();
$GLOBALS['wprism_test_providers'] = [new \WPrism\Providers\ProbeCache($policy)];
$p = $one(\WPrism\Providers::negotiate($policy, $policy->actions_for(['post:probe']))['problems']);
$check(($p['code'] ?? '') === 'provider_outside_owning_plugin'
    && str_contains($p['remediation'] ?? '', 'source: manifest'),
    'a plugin-sourced registration whose class file lives outside the owning plugin directory is refused (identity stays falsifiable)');

$reset();
$GLOBALS['wprism_test_providers'] = [new class {
    public function identity(): array {
        throw new \RuntimeException('third-party provider exploding on discovery');
    }
}, new \WPrism\Providers\ProbeSupplied()];
$negotiation = \WPrism\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
$check($negotiation['problems'] === [] && isset($negotiation['providers']['probe-cache']),
    "an unrelated registration whose identity() throws is skipped, not a fatal for the provider actually wanted");

$reset();
$GLOBALS['wprism_test_providers'] = [
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
    new \WPrism\Providers\ProbeSupplied(),
];
$negotiation = \WPrism\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
$malformedIdentityJson = json_encode($negotiation, JSON_THROW_ON_ERROR);
$check(
    $negotiation['problems'] === []
    && isset($negotiation['providers']['probe-cache'])
    && !str_contains($malformedIdentityJson, 'WPRISM_PROVIDER_SECRET')
    && !str_contains($malformedIdentityJson, 'INJECTED_PROVIDER_LINE'),
    'malformed or throwing Stringable plugin registration ids are skipped without a fatal or public payload leak'
);

echo "\n== entity scope negotiates its batch preconditions before any mutation ==\n";
$reset();
\WPrism\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity'];
$policy = $policyFor($manifest);
$p = $one(\WPrism\Providers::negotiate($policy, $policy->actions_for(['post:probe']))['problems']);
$check(($p['code'] ?? '') === 'entity_scope_unscoped_action'
    && str_contains($p['remediation'] ?? '', 'scope: site'),
    'an entity-scoped capability on an unscoped action refuses at negotiation (its batch would always be empty)');

$reset();
\WPrism\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity'];
$entityManifest = $manifest;
$entityManifest['actions'][0]['triggers'] = ['option:probe_setting'];
$policy = $policyFor($entityManifest);
$p = $one(\WPrism\Providers::negotiate($policy, $policy->actions_for(['option:probe_setting']))['problems']);
$check(($p['code'] ?? '') === 'entity_scope_unresolvable_trigger'
    && str_contains($p['found'] ?? '', 'option:probe_setting'),
    'an entity-scoped capability triggered on a surface with no per-entity id refuses at negotiation, not post-commit');
$reset();
$policy = $policyFor($manifest);

// The target every invocation drive from here to the Apply section runs
// against. ProbeCache declares `reads: [option:probe_setting]`, so the engine
// observes that one row either side of every invoke() below; a $wpdb that
// could not answer would refuse ahead of the provider call and displace the
// receipt refusals this section exists to pin. `probe_written` is deliberately
// NOT seeded — it is the surface a capability declares under `writes` further
// down, and its absence is the "before" half of the observed delta.
$optionsBaseline = [
    ['option_id' => 1, 'option_name' => 'probe_setting', 'option_value' => 'declared-read', 'autoload' => 'yes'],
];
$wpdb = \WPrismTest\FakeWpdb::install();
$wpdb->seedTable('options', $optionsBaseline);

echo "\n== invocation: receipts, value-level verification, and the timeout budget ==\n";
$reset();
$policy = $policyFor($manifest);
$action = $policy->actions_for(['post:probe'])[0];
$negotiation = \WPrism\Providers::negotiate($policy, [$action]);
$provider = $negotiation['providers']['probe-cache'];
$declaration = $negotiation['capabilities']['probe-cache']['flush'];
$receipt = \WPrism\Providers::invoke($provider, $action, $declaration, []);
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
        \WPrism\Providers::invoke($provider, $action, $declaration, []);
        $check(false, $label);
    } catch (\Throwable $t) {
        $check(str_contains($t->getMessage(), $needle), $label . ' (message: ' . $t->getMessage() . ')');
    }
};
$expectInvokeFailure(
    static fn() => \WPrism\Providers\ProbeCache::$receiptOverride = ['before' => [], 'after' => [], 'verified' => false],
    'no value-level verification',
    'a receipt with verified !== true is refused: command success is not evidence the effect landed'
);
$expectInvokeFailure(
    static fn() => \WPrism\Providers\ProbeCache::$receiptOverride = ['ok' => true],
    'malformed receipt',
    'a receipt missing before/after/verified is refused'
);
$receiptSecret = "https://provider.example.test/receipt?access_token=WPRISM_RECEIPT_SECRET\nINJECTED_RECEIPT_LINE";
$reset();
\WPrism\Providers\ProbeCache::$receiptOverride = [$receiptSecret => true];
try {
    \WPrism\Providers::invoke($provider, $action, $declaration, []);
    $check(false, 'a malformed receipt never publishes provider-returned receipt keys');
} catch (\Throwable $t) {
    $message = $t->getMessage();
    $check(
        str_contains($message, "provider 'probe-cache' capability 'flush'")
        && str_contains($message, 'exactly before, after, and verified are required')
        && !str_contains($message, 'WPRISM_RECEIPT_SECRET')
        && !str_contains($message, 'INJECTED_RECEIPT_LINE')
        && !str_contains($message, "\n"),
        'a malformed receipt error preserves provider/capability and required shape without exposing returned keys'
    );
}
$invokeSecret = "https://provider.example.test/invoke?access_token=WPRISM_INVOKE_SECRET\nINJECTED_INVOKE_LINE";
$reset();
\WPrism\Providers\ProbeCache::$invokeThrows = $invokeSecret;
try {
    \WPrism\Providers::invoke($provider, $action, $declaration, []);
    $check(false, 'a provider invocation failure never publishes the provider throwable');
} catch (\Throwable $t) {
    $message = $t->getMessage();
    $rendered = (string) $t;
    $check(
        str_contains($message, "provider 'probe-cache' capability 'flush' failed")
        && !str_contains($message, 'WPRISM_INVOKE_SECRET')
        && !str_contains($message, 'INJECTED_INVOKE_LINE')
        && !str_contains($message, "\n")
        && !str_contains($rendered, 'WPRISM_INVOKE_SECRET')
        && !str_contains($rendered, 'INJECTED_INVOKE_LINE')
        && $t instanceof \WPrism\PrivateEvidenceException
        && $t->getPrevious() === null
        && $t->private_evidence_causes() === [\WPrism\Providers\ProbeCache::$lastInvokeThrowable],
        'a provider invocation failure keeps its exact cause private without exposing it through printable Throwable state'
    );
}
// timeout_seconds is a positive integer, so the smallest honest overrun test
// is a one-second budget deliberately exceeded. Worth the wall-clock second:
// this is the only check that the post-hoc budget is enforced at all rather
// than merely declared.
$reset();
\WPrism\Providers\ProbeCache::$sleepSeconds = 1.1;
try {
    \WPrism\Providers::invoke($provider, $action, ['timeout_seconds' => 1] + $declaration, []);
    $check(false, 'an invocation past its declared timeout_seconds budget is a hard failure');
} catch (\Throwable $t) {
    $check(str_contains($t->getMessage(), 'overran its declared budget'),
        'an invocation past its declared timeout_seconds budget is a hard failure (message: '
        . $t->getMessage() . ')');
}
\WPrism\Providers\ProbeCache::$sleepSeconds = 0.0;

// ======================================================================
// WP-3.3: the receipt is checked against the engine's OWN reading of the
// surfaces the capability declared.
//
// Everything above this point takes `verified === true` on trust: the receipt's
// `before`/`after` are provider bytes, so "a receipt must prove the state it
// wrote" could only ever mean "the provider says it did". The engine now reads
// each declared `option:` surface either side of invoke() and compares its two
// readings, which is a fact the provider did not author.
//
// The reach is deliberately narrow and is stated rather than implied: only
// `option:<name>` names an extent this engine can witness both COMPLETELY (a
// partial witness cannot tell "unchanged" from "a change I cannot see", and the
// unshown-write refusal is exactly a claim that nothing changed) and AFFORDABLY
// (a complete witness over `table:postmeta` or `post:product` is a core-table
// scan, twice per invoke, on every apply). So the nine shipped adapters — whose
// writes are all `table:`/`entity:` — pay zero queries and keep byte-identical
// receipts, and negotiation publishes which of their surfaces the check did not
// reach instead of leaving the gap unsaid.
// ======================================================================

echo "\n== WP-3.3: the receipt, checked against the declared surfaces ==\n";
$reset();
$wpdb->seedTable('options', $optionsBaseline);

// The declaration grammar's five kinds, and which of them this engine has a
// complete bounded reader for. A behaviour pin rather than a constant pin:
// widening this is what would make the refusals below unsound, so the property
// under guard is what observable() ANSWERS.
$check(\WPrism\ProviderSurfaces::observable('option:probe_setting') === true
    && \WPrism\ProviderSurfaces::observable('table:postmeta') === false
    && \WPrism\ProviderSurfaces::observable('post:product') === false
    && \WPrism\ProviderSurfaces::observable('term:probe_tax') === false
    && \WPrism\ProviderSurfaces::observable('entity:probe-cache-groups') === false,
    'exactly one of the five declared surface kinds is observable: `option:` names a bounded extent, the other four name a table, a post type, a taxonomy or an adapter-minted name');

// The plan is decided from the DECLARATION alone — no query, no target, no
// provider call — which is what lets negotiation publish it read-only and
// invoke() re-derive the identical one instead of the two agreeing by
// convention.
$shippedShapePlan = \WPrism\ProviderSurfaces::observation_plan($declaration);
$check($shippedShapePlan['watched'] === ['option:probe_setting']
    && $shippedShapePlan['writes'] === []
    && $shippedShapePlan['read_only'] === ['option:probe_setting']
    && $shippedShapePlan['unobservable'] === ['entity:probe-cache-groups']
    && $shippedShapePlan['writes_fully_observable'] === false,
    'a capability declaring an `option:` read and an `entity:` write is watched on the read and NAMED as unobservable on the write — the engine says which half of the declaration its check reaches');

// The measured cost, as a number rather than an impression: one checked read
// per watched surface per pass, two passes per invoke.
$check($shippedShapePlan['queries_per_invoke'] === 2,
    'the declared cost of the check is 2 queries per invoke for this capability (1 watched surface x 2 passes)');

// Counted by the reader's own statement rather than by the log length, so the
// number is the ENGINE's added cost and stays that even when the drive also
// makes the provider write.
$observationQueries = static fn(): int => count(array_filter(
    $wpdb->queries(),
    static fn(string $sql): bool => str_contains($sql, 'SHA2(option_value, 256)')
));
$wpdb->resetLog();
$reset();
$observedReceipt = \WPrism\Providers::invoke($provider, $action, $declaration, []);
$measuredQueries = $observationQueries();
$check($measuredQueries === 2 && count($wpdb->queries()) === 2,
    "and the MEASURED cost matches it: $measuredQueries added queries across one invoke, and nothing else ran, so a future regression in the reader's query count is visible here rather than on a customer's target");

// The shipped library's shape: every declared surface is one this engine has no
// reader for, so the observation is silent — observe() returns before it
// touches $wpdb at all. This is the check that keeps the nine shipped provider
// suites' cost claim honest.
$opaqueDeclaration = ['reads' => ['table:posts'], 'writes' => ['entity:probe-cache-groups']] + $declaration;
$wpdb->resetLog();
$reset();
$opaqueReceipt = \WPrism\Providers::invoke($provider, $action, $opaqueDeclaration, []);
$check($wpdb->queries() === []
    && \WPrism\ProviderSurfaces::observation_plan($opaqueDeclaration)['queries_per_invoke'] === 0,
    'a capability whose declared surfaces are all `table:`/`post:`/`entity:` costs ZERO added queries — the nine shipped adapters pay nothing for a check that cannot reach them');
$check(array_diff_key($observedReceipt, ['duration_seconds' => true])
    === array_diff_key($opaqueReceipt, ['duration_seconds' => true]),
    'and the receipt an observed capability publishes is byte-identical to the unobserved one: the check refuses or it is invisible, it never edits');

// ---- the observed delta: a write the surface DOES show ----
// `option:probe_written` is absent in the baseline, so the provider creating it
// is a real, engine-measured change on a surface the capability itself named
// under `writes`.
$writesDeclaration = ['writes' => ['option:probe_written']] + $declaration;
$reset();
\WPrism\Providers\ProbeCache::$optionWrites = ['probe_written' => 'landed'];
$wpdb->resetLog();
$deltaReceipt = \WPrism\Providers::invoke($provider, $action, $writesDeclaration, []);
$check(array_diff_key($deltaReceipt, ['duration_seconds' => true])
    === array_diff_key($observedReceipt, ['duration_seconds' => true]),
    'a receipt whose claimed change the declared writes surface actually shows passes, and publishes the same bytes it always did');
$check($observationQueries() === 4,
    'costing 4 added queries (2 watched surfaces x 2 passes) — the number a wider reader would move, recorded so the move is visible');

// ---- the write the surface does NOT show ----
$reset();
$wpdb->seedTable('options', $optionsBaseline);
try {
    \WPrism\Providers::invoke($provider, $action, $writesDeclaration, []);
    $check(false, 'a receipt claiming a value-level change its own declared writes surface does not show is refused');
} catch (\Throwable $t) {
    $check(str_contains($t->getMessage(), "provider 'probe-cache' capability 'flush'")
        && str_contains($t->getMessage(), 'declared writes surfaces do not show')
        && str_contains($t->getMessage(), 'option:probe_written unchanged')
        && !str_contains($t->getMessage(), "\n"),
        'a receipt claiming a value-level change its own declared writes surface does not show is refused, naming the surface that stayed put (message: '
        . $t->getMessage() . ')');
}

// The converged case is not a claim to have written anything, so the same
// unmoved surface is no contradiction.
$reset();
\WPrism\Providers\ProbeCache::$receiptOverride = ['before' => ['groups' => []], 'after' => ['groups' => []], 'verified' => true];
$convergedReceipt = \WPrism\Providers::invoke($provider, $action, $writesDeclaration, []);
$check(($convergedReceipt['verified'] ?? null) === true,
    'a receipt reporting before === after passes with the surface unmoved: `already converged` is not a write claim, and only the positive claim is checkable');

// The risk this check carries, mitigated where the declaration is made: one
// unobservable surface in `writes` means the write may legitimately have landed
// where this engine cannot see, so the refusal does not fire at all rather than
// punishing a provider for the reader's gap.
$reset();
$mixedDeclaration = ['writes' => ['option:probe_written', 'entity:probe-cache-groups']] + $declaration;
$mixedPlan = \WPrism\ProviderSurfaces::observation_plan($mixedDeclaration);
$mixedReceipt = \WPrism\Providers::invoke($provider, $action, $mixedDeclaration, []);
$check($mixedPlan['writes_fully_observable'] === false
    && $mixedPlan['unobservable'] === ['entity:probe-cache-groups']
    && ($mixedReceipt['verified'] ?? null) === true,
    'a capability with even one unobservable surface among its writes is never refused for an unshown write — the gap is the reader\'s, and it is named in the plan instead');

// ---- the surface the capability declared it would only READ ----
$reset();
\WPrism\Providers\ProbeCache::$optionWrites = ['probe_setting' => 'scribbled-by-the-provider'];
try {
    \WPrism\Providers::invoke($provider, $action, $declaration, []);
    $check(false, 'a provider that changes a surface it declared under reads is refused');
} catch (\Throwable $t) {
    $check(str_contains($t->getMessage(), "provider 'probe-cache' capability 'flush'")
        && str_contains($t->getMessage(), "declared 'option:probe_setting' under reads")
        && str_contains($t->getMessage(), 'the surface changed across the call')
        && !str_contains($t->getMessage(), 'scribbled-by-the-provider')
        && !str_contains($t->getMessage(), "\n"),
        'a provider that changes a surface it declared under reads is refused, naming the surface without carrying the value it wrote (message: '
        . $t->getMessage() . ')');
}
// autoload is part of the row, so flipping it alone is still a write to the
// surface: a witness that only digested option_value would report this
// unchanged and let the scribble through.
$reset();
$wpdb->seedTable('options', $optionsBaseline);
$witnessBeforeFlip = \WPrism\ProviderSurfaces::observe(['option:probe_setting'], 'probe-cache', 'flush');
$wpdb->update('options', ['autoload' => 'no'], ['option_name' => 'probe_setting']);
$check($witnessBeforeFlip !== \WPrism\ProviderSurfaces::observe(['option:probe_setting'], 'probe-cache', 'flush'),
    'the witness folds autoload as well as the value: an apply that left an option\'s bytes alone and made it autoload changed the surface, and a value-only witness would report it unchanged');
$wpdb->seedTable('options', $optionsBaseline);

// ---- precedence: the three refusals this section inherited still win ----
// Each case arms a REAL surface violation and then also breaks the receipt, so
// what it proves is an ordering rather than the absence of a second fault.
$precedence = static function (callable $arm, string $needle, string $absent, string $label) use (
    $check, $provider, $action, $declaration, $reset, $wpdb, $optionsBaseline
): void {
    $reset();
    $wpdb->seedTable('options', $optionsBaseline);
    \WPrism\Providers\ProbeCache::$optionWrites = ['probe_setting' => 'scribbled-by-the-provider'];
    $arm();
    try {
        \WPrism\Providers::invoke($provider, $action, ['timeout_seconds' => 1] + $declaration, []);
        $check(false, $label);
    } catch (\Throwable $t) {
        $check(str_contains($t->getMessage(), $needle) && !str_contains($t->getMessage(), $absent),
            $label . ' (message: ' . $t->getMessage() . ')');
    }
};
$precedence(
    static fn() => \WPrism\Providers\ProbeCache::$receiptOverride = ['ok' => true],
    'exactly before, after, and verified are required',
    'declared \'option:probe_setting\' under reads',
    'a malformed receipt still refuses as malformed, even with a real read-only surface violation behind it'
);
$precedence(
    static fn() => \WPrism\Providers\ProbeCache::$receiptOverride = ['before' => [], 'after' => [], 'verified' => false],
    'a receipt must prove the state it wrote, not that a call returned',
    'declared \'option:probe_setting\' under reads',
    'an unverified receipt still refuses as unverified, ahead of the surface check that would also have refused it'
);
// Worth the second wall-clock second for the same reason the budget check above
// is worth the first: the post-hoc budget is the refusal the surface check was
// most likely to displace, because both are decided after the call returns.
$precedence(
    static fn() => \WPrism\Providers\ProbeCache::$sleepSeconds = 1.1,
    'overran its declared budget',
    'declared \'option:probe_setting\' under reads',
    'an over-budget invocation still refuses as over-budget: the surface check is decided strictly after all three'
);
$reset();

// ---- an unread surface must never pass as an unchanged one ----
// Injected on the BEFORE pass, so the refusal lands ahead of the provider call:
// a target that cannot answer the engine's checked read is a fact discovered
// before the mutation, not one discovered in the middle of it.
$wpdb->seedTable('options', $optionsBaseline);
$callsBefore = count($provider->calls);
$wpdb->failNextQuery('injected surface observation failure', 'probe_setting');
try {
    \WPrism\Providers::invoke($provider, $action, $declaration, []);
    $check(false, 'a failed checked read of a declared surface refuses before the provider is called');
} catch (\Throwable $t) {
    $check(str_contains($t->getMessage(), 'provider checked read failed')
        && str_contains($t->getMessage(), "surface observation for provider 'probe-cache' capability 'flush'")
        && !str_contains($t->getMessage(), 'injected surface observation failure')
        && count($provider->calls) === $callsBefore,
        'a failed checked read of a declared surface refuses through ProviderSdk\'s hygiene contract and BEFORE the provider is called (message: '
        . $t->getMessage() . ')');
}

// Two collation-equal rows mean the extent the witness claims to cover
// completely is not the extent it read, and completeness is the whole
// justification for the unshown-write refusal — so it refuses rather than
// picking one, the same verdict CacheInvalidationTransaction reaches.
$reset();
$wpdb->seedTable('options', [
    ['option_id' => 1, 'option_name' => 'probe_setting', 'option_value' => 'declared-read', 'autoload' => 'yes'],
    ['option_id' => 2, 'option_name' => 'probe_setting', 'option_value' => 'the-duplicate', 'autoload' => 'yes'],
]);
try {
    \WPrism\Providers::invoke($provider, $action, $declaration, []);
    $check(false, 'an ambiguous collation-equal option row refuses rather than folding to one witness');
} catch (\Throwable $t) {
    $check(str_contains($t->getMessage(), 'could not be checked against its own declared surfaces')
        && str_contains($t->getMessage(), 'an unread surface must not pass as an unchanged one')
        && !str_contains($t->getMessage(), 'the-duplicate'),
        'an ambiguous collation-equal option row refuses rather than folding to one witness, without carrying either row\'s value (message: '
        . $t->getMessage() . ')');
}

// Negotiation is the read-only half: the plan is published there, before Apply
// has mutated anything, and computing it touches the target zero times.
$reset();
$wpdb->seedTable('options', $optionsBaseline);
$wpdb->resetLog();
$observationNegotiation = \WPrism\Providers::negotiate($policy, [$action]);
$check($observationNegotiation['surface_observation']['probe-cache']['flush'] === $shippedShapePlan
    && $wpdb->queries() === [],
    'negotiation publishes the same plan invoke() re-derives, and reaches it with zero queries — an unobservable declared surface is legible at read-only negotiation time, not discovered mid-mutation');

$reset();
$wpdb->seedTable('options', $optionsBaseline);
$wpdb->resetLog();

// ======================================================================
// issue #3383: publication bounds on a SUCCESSFUL receipt.
//
// issue #3314 hardened the FAILURE diagnostics above — every one of those checks
// proves a refusal keeps provider/capability and drops provider bytes. The
// SUCCESS path had no such contract: `before`/`after` were propagated verbatim
// into Apply's `actions` rows and out through `wp wprism apply --format=json`, so
// a provider returning a live key, a newline, or a megabyte published it.
//
// The load-bearing claim is not "secrets are gone" — dropping the fields
// entirely would achieve that and destroy the evidence. It is that the
// projection is INJECTIVE: equal raw values publish equal bytes and unequal
// ones publish unequal bytes, so a reader can still decide before === after
// (the question these receipts exist to answer) without the plaintext. The
// matrix below is that property, exercised once per bounded shape.
// ======================================================================

echo "\n== issue #3383: successful receipt values are bounded before publication ==\n";
// The house secret fixture (Secrets::HARD_PATTERNS' first entry, the same
// shape regress_capture_secret_scan.php plants), a control-bearing value whose
// second line would forge a warning if it ever reached a human summary, and a
// string one byte past the published bound.
$receiptSecretValue = 'sk_live_' . str_repeat('A', 24);
$receiptOtherSecret = 'sk_live_' . str_repeat('B', 24);
$receiptControlValue = "flushed\nWarning: INJECTED_RECEIPT_WARNING\r\x00";
$receiptOversized = str_repeat('z', \WPrism\Providers::RECEIPT_MAX_STRING_BYTES + 1);
$receiptDeep = static function (int $leaf): array {
    $node = ['leaf' => $leaf];
    for ($i = 0; $i < \WPrism\Providers::RECEIPT_MAX_DEPTH; $i++) {
        $node = ['nested' => $node];
    }
    return $node;
};
$receiptWide = static fn(int $leaf): array => ['rows' => array_fill(
    0,
    \WPrism\Providers::RECEIPT_MAX_ENTRIES + 1,
    $leaf
)];

/** Invoke with a planted successful receipt and return what the engine publishes. */
$publish = static function (mixed $before, mixed $after) use ($reset, $provider, $action, $declaration): array {
    $reset();
    \WPrism\Providers\ProbeCache::$receiptOverride = [
        'before' => $before,
        'after' => $after,
        'verified' => true,
    ];
    return \WPrism\Providers::invoke($provider, $action, $declaration, []);
};
/** Every published byte a machine caller and a human caller could ever see. */
$publishedJson = static fn(array $receipt): string => (string) json_encode(
    $receipt,
    JSON_UNESCAPED_SLASHES // the exact flags Cli::apply() publishes with (review F1)
);
$isWitness = static fn(mixed $v): bool => is_string($v)
    && str_starts_with($v, \WPrism\Providers::RECEIPT_WITNESS_PREFIX)
    && str_ends_with($v, '>');
$witnessReason = static function (mixed $v): string {
    $rest = substr((string) $v, strlen(\WPrism\Providers::RECEIPT_WITNESS_PREFIX));
    return (string) strstr($rest, ':', true);
};

$inBounds = $publish(['groups' => []], ['groups' => ['probe-group']]);
$check(
    serialize([$inBounds['before'], $inBounds['after'], $inBounds['verified']])
        === 'a:3:{i:0;a:1:{s:6:"groups";a:0:{}}i:1;a:1:{s:6:"groups";a:1:{i:0;s:11:"probe-group";}}i:2;b:1;}',
    'a receipt already inside every bound publishes byte-identically to the pre-issue #3383 engine — no key added, '
    . 'none reordered, no value rewritten'
);

/**
 * The projection matrix. Each row plants one shape twice — the SAME raw value
 * on both sides, then two DIFFERENT raw values of that shape — and requires
 * three things at once: the plaintext is absent from every published byte, the
 * value is replaced by a witness naming the bound it broke, and the
 * before/after relation survives the substitution in both directions.
 */
$bounded = [
    'secret' => ['secret', $receiptSecretValue, $receiptOtherSecret],
    'control' => ['control', $receiptControlValue, "flushed\nWarning: OTHER_INJECTED_LINE"],
    'oversized string' => ['oversized', $receiptOversized, $receiptOversized . 'z'],
    'invalid UTF-8' => ['binary', "probe\xC3\x28", "probe\xC3\x29"],
    'witness-shaped' => ['ambiguous',
        \WPrism\Providers::RECEIPT_WITNESS_PREFIX . 'secret:sha256:' . str_repeat('0', 64) . '>',
        \WPrism\Providers::RECEIPT_WITNESS_PREFIX . 'secret:sha256:' . str_repeat('1', 64) . '>'],
];
foreach ($bounded as $label => [$reason, $planted, $otherPlanted]) {
    $same = $publish(['v' => $planted], ['v' => $planted]);
    $differs = $publish(['v' => $planted], ['v' => $otherPlanted]);
    $json = $publishedJson($same) . $publishedJson($differs);
    $check(
        // The non-empty requirement is load-bearing for the invalid-UTF-8 row:
        // json_encode() answers false there, so "the plaintext is absent" is
        // satisfied vacuously by publishing nothing at all, which is the OTHER
        // half of that bug rather than a fix for it.
        $json !== ''
        && !str_contains($json, $planted) && !str_contains($json, $otherPlanted)
        && !str_contains($json, 'INJECTED_RECEIPT_WARNING')
        && preg_match('/[\x00-\x1F\x7F]/', $json) !== 1,
        "a $label receipt value never publishes verbatim, in any published byte"
    );
    $check(
        $isWitness($same['before']['v'] ?? null) && $witnessReason($same['before']['v']) === $reason,
        "a $label receipt value publishes as a witness naming the bound it broke ($reason)"
    );
    $check(
        $same['before'] === $same['after'] && $differs['before'] !== $differs['after'],
        "and the before/after relation survives the substitution for a $label value — equal raw values publish "
        . 'equal witnesses, unequal ones do not'
    );
}

$deepSame = $publish($receiptDeep(1), $receiptDeep(1));
$deepDiffers = $publish($receiptDeep(1), $receiptDeep(2));
$check(
    $isWitness($deepSame['before']['nested']['nested']['nested']['nested'] ?? null)
    && $witnessReason($deepSame['before']['nested']['nested']['nested']['nested']) === 'deep',
    'a container nested past RECEIPT_MAX_DEPTH is summarized exactly at that boundary — the levels above it still '
    . 'publish, so a deep receipt is bounded rather than discarded'
);
$check(
    $deepSame['before'] === $deepSame['after'] && $deepDiffers['before'] !== $deepDiffers['after'],
    'and a difference living BELOW the published depth still changes the witness: the digest is taken over the raw '
    . 'value at every level, so a repair that changed something deep cannot publish as one that changed nothing'
);

// The digest is TYPE-TAGGED (review F2): two containers identical except one
// scalar's TYPE must witness differently, or a changed receipt could publish
// as unchanged — an untyped hash of (string) casts collides int 1 with "1".
$typedInt = array_fill(0, 129, 0);
$typedInt[0] = 1;
$typedStr = $typedInt;
$typedStr[0] = '1';
$typed = $publish(['rows' => $typedInt], ['rows' => $typedStr]);
$check(
    $isWitness($typed['before']['rows'] ?? null) && $isWitness($typed['after']['rows'] ?? null)
    && $typed['before']['rows'] !== $typed['after']['rows'],
    'the witness digest is type-tagged: containers differing only in one scalar TYPE witness differently'
);

// The KEY half of the tag (review round-2 F2b): serialize($key)'s length
// prefix is what keeps the container stream unambiguous. These two arrays are
// a constructed collision for an UNTYPED key concatenation — each embeds the
// other's `=<child-digest>;` boundary inside a key, so `key . '=' . digest
// . ';'` streams byte-identically for both — and only the length prefix
// separates them. Nested past publication depth so the digest is what decides.
$dOne = hash('sha256', serialize('one'));
$dWww = hash('sha256', serialize('www'));
$bury = static fn(array $v): array => ['n1' => ['n2' => ['n3' => ['n4' => $v]]]];
$keyed = $publish(
    $bury(['a=' . $dOne . ';P' => 'www', 'Q' => 'two']),
    $bury(['a' => 'one', 'P=' . $dWww . ';Q' => 'two'])
);
$check(
    $keyed['before'] !== $keyed['after'],
    'the witness digest type-tags map KEYS too: a key-boundary collision pair witnesses differently'
);

// Bounding runs LAST in invoke() (review F3): a receipt that is both
// unverified and unpublishable must refuse as unverified — the pinned
// precedence, defended behaviorally rather than by source text alone.
$expectInvokeFailure(
    static fn() => \WPrism\Providers\ProbeCache::$receiptOverride = ['before' => (object) [], 'after' => [], 'verified' => false],
    'no value-level verification',
    'a receipt both unverified and unpublishable refuses as unverified — bounding stays last'
);

$wideSame = $publish($receiptWide(1), $receiptWide(1));
$wideDiffers = $publish($receiptWide(1), $receiptWide(2));
$check(
    $isWitness($wideSame['before']['rows'] ?? null) && $witnessReason($wideSame['before']['rows']) === 'wide'
    && $wideSame['before'] === $wideSame['after'] && $wideDiffers['before'] !== $wideDiffers['after'],
    'a container holding more than RECEIPT_MAX_ENTRIES is summarized as one witness, and its contents still decide '
    . 'the before/after relation'
);

$manyRows = static fn(string $fill): array => array_map(
    static fn(int $i): string => $fill . $i,
    range(1, \WPrism\Providers::RECEIPT_MAX_ENTRIES)
);
$hugeSame = $publish($manyRows(str_repeat('a', 100)), $manyRows(str_repeat('a', 100)));
$hugeDiffers = $publish($manyRows(str_repeat('a', 100)), $manyRows(str_repeat('b', 100)));
$check(
    $isWitness($hugeSame['before']) && $witnessReason($hugeSame['before']) === 'oversized'
    && strlen($publishedJson($hugeSame)) < \WPrism\Providers::RECEIPT_MAX_VALUE_BYTES
    && $hugeSame['before'] === $hugeSame['after'] && $hugeDiffers['before'] !== $hugeDiffers['after'],
    'a value that is legal entry by entry but still oversized as a whole is summarized once at the top, and the '
    . 'published receipt is then smaller than the bound it broke'
);

$secretKeyReceipt = $publish([$receiptSecretValue => 1], ["probe\nInjected: key" => 1]);
$check(
    !str_contains($publishedJson($secretKeyReceipt), $receiptSecretValue)
    && !str_contains($publishedJson($secretKeyReceipt), 'Injected: key')
    && $isWitness(array_key_first($secretKeyReceipt['before']))
    && $isWitness(array_key_first($secretKeyReceipt['after'])),
    'receipt map KEYS are bounded by the same rules as values — a secret or a control byte is no safer for being a '
    . 'key, and json_encode publishes both'
);

$malformed = [
    'an object' => new \stdClass(),
    'a resource' => STDERR,
    'a non-finite number' => INF,
];
foreach ($malformed as $label => $value) {
    $reset();
    \WPrism\Providers\ProbeCache::$receiptOverride = ['before' => ['v' => $value], 'after' => [], 'verified' => true];
    try {
        \WPrism\Providers::invoke($provider, $action, $declaration, []);
        $check(false, "a successful receipt carrying $label fails closed rather than publishing");
    } catch (\Throwable $t) {
        $check(
            str_contains($t->getMessage(), "provider 'probe-cache' capability 'flush'")
            && str_contains($t->getMessage(), 'cannot publish')
            && !str_contains($t->getMessage(), "\n"),
            "a successful receipt carrying $label fails closed, naming provider and capability and nothing else "
            . '(message: ' . $t->getMessage() . ')'
        );
    }
}
$reset();

$check(
    json_encode($publish(['v' => "probe\xC3\x28"], [])) !== false,
    'and a published receipt always survives json_encode() — invalid UTF-8 used to make Cli::apply() print an empty '
    . 'line where the whole summary should be, so bounding closes a correctness hole as well as a secrecy one'
);

$providersSource = (string) file_get_contents($root . '/agent/src/Adapter/Providers.php');
$check(
    str_contains($providersSource, 'CommandRefusalException::containsSensitivePublicDetail($value)')
    && preg_match('/sk_live_|AKIA|ghp_|xox[baprs]|BEGIN [A-Z ]*PRIVATE KEY/', $providersSource) !== 1,
    'the secret grammar is the shared one (Secrets, reached through the issue #3345 public-output screen) — this file '
    . 'declares no vendor token pattern of its own, so a pattern added there covers receipts for free'
);
$check(
    (bool) preg_match(
        '/return self::bound_receipt\(\$receipt, \$id, \$capability\) \+ \[\'duration_seconds\'/',
        $providersSource
    ),
    'invoke() returns the PROJECTION, so the raw provider value never crosses into the engine at all — there is no '
    . 'downstream path that could retain it and no protected copy to guard'
);
$check(
    \WPrism\Providers::RECEIPT_WITNESS_REASONS === ['ambiguous', 'binary', 'control', 'deep', 'oversized', 'secret', 'wide']
    && \WPrism\Providers::RECEIPT_MAX_DEPTH === 4
    && \WPrism\Providers::RECEIPT_MAX_ENTRIES === 128
    && \WPrism\Providers::RECEIPT_MAX_STRING_BYTES === 512
    && \WPrism\Providers::RECEIPT_MAX_KEY_BYTES === 128
    && \WPrism\Providers::RECEIPT_MAX_VALUE_BYTES === 8192,
    'the bounds and the reason vocabulary are canonical constants, not numbers spelled out at each call site'
);

// ======================================================================
// issue #3369: structured capability arguments and engine batch context.
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
    \WPrism\Providers\ProbeCache::$capabilityOverrides = ['args' => $declaredArgs];
    $m = $manifest;
    $m['actions'][0]['args'] = $actionArgs;
    $policy = $policyFor($m);
    return \WPrism\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
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
\WPrism\Providers\ProbeCache::$capabilityOverrides = ['args' => $rowsArgs];
$validateArgs = new \ReflectionMethod(\WPrism\Providers::class, 'validate_args');
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
    \WPrism\Providers\ProbeCache::$capabilityOverrides = $overrides;
    $m = $manifest;
    $m['actions'][0]['triggers'] = ['post:probe'];
    $policy = $policyFor($m);
    return \WPrism\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
};
$channelProblem = static function (mixed $context, string $scope = 'entity') use ($channelNegotiation, $one): array {
    return $one($channelNegotiation($context, $scope)['problems']);
};

$negotiation = $channelNegotiation(['deletions', 'retry']);
$check($negotiation['problems'] === []
    && ($negotiation['capabilities']['probe-cache']['flush']['context'] ?? null) === ['deletions', 'retry'],
    'an entity-scoped capability may declare engine batch channels, and the declaration binds for the rebuild pass');
$check(\WPrism\Providers::CONTEXT_CHANNELS === ['always_on_write', 'deletions', 'reparents', 'retry'],
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
$validateCapabilityDeclaration = new \ReflectionMethod(\WPrism\Providers::class, 'validate_capability_declaration');
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
    \WPrism\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity', 'context' => $channels];
    $policy = $policyFor($manifest);
    $provider = new \WPrism\Providers\ProbeCache($policy);
    $declaration = $provider->capabilities()['flush'];
    \WPrism\Providers::invoke($provider, $entityAction, $declaration, $batch, $context);
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
require_once $root . '/agent/src/Kernel/Db.php';
require_once $root . '/agent/src/Repository/Ledger.php';
require_once $root . '/agent/src/Apply/Apply.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';

final class ProbeBatchWpdb extends \WPrismTest\FakeWpdb {
    public string $prefix = 'wp_';
    public string $options = 'wp_options';
    public string $posts = 'wp_posts';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $last_error = '';
    /** @var array<string,int> "<uuid>\0<id_kind>" => local id */
    public array $map = [];
    /** @var array<int,array{post_type:string,post_parent:int}> */
    public array $postsRows = [];
    /** @var array<string,string> the wprism_kv keyspace (markers) */
    public array $kv = [];
    /** @var array<string,mixed> option name => stored value */
    public array $optionRows = [];
    /** @var list<string> */
    public array $optionReadNames = [];
    private string $fixtureState = '';

    public function __construct() {
        parent::__construct('wp_');
        $this->enableInformationSchema();
        foreach (['wprism_map', 'wprism_kv', 'posts', 'options', 'term_taxonomy'] as $table) {
            $this->setTableEngine($table, 'InnoDB');
        }
        $this->setUniqueKey('wprism_map', ['uuid', 'id_kind']);
        $this->setUniqueKey('wprism_kv', ['k']);
        $this->setColumns('wprism_map', [
            'uuid' => 'char(36)',
            'id_kind' => 'varchar(32)',
            'local_id' => 'bigint unsigned',
        ]);
        $this->setColumns('wprism_kv', ['k' => 'varchar(191)', 'v' => 'longtext']);
        $this->setColumns('posts', ['ID' => 'bigint unsigned', 'post_type' => 'varchar(20)', 'post_parent' => 'bigint unsigned']);
        $this->setColumns('options', ['option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)']);
        $this->setColumns('term_taxonomy', ['term_taxonomy_id' => 'bigint unsigned']);
        $this->syncFixtureToStore();
    }

    public function prepare(string $query, ...$args): string {
        return parent::prepare($query, ...$args);
    }

    public function query(string $query): int|bool {
        $this->syncFixtureToStore();
        $result = parent::query($query);
        $this->syncStoreToFixture();
        return $result;
    }

    public function get_results(string $query, string $output = OBJECT): array|false|null {
        $this->syncFixtureToStore();
        $result = parent::get_results($query, $output);
        $this->syncStoreToFixture();
        return $result;
    }

    public function get_row(string $query, string $output = OBJECT, int $y = 0): array|object|null {
        $this->syncFixtureToStore();
        $result = parent::get_row($query, $output, $y);
        $this->syncStoreToFixture();
        return $result;
    }

    /** Term recounts find no term_taxonomy rows, so the rebuild pass walks past them. */
    public function get_col(string $query, int $x = 0): array {
        $this->syncFixtureToStore();
        $result = parent::get_col($query, $x);
        $this->syncStoreToFixture();
        return $result;
    }

    public function get_var(string $query, int $x = 0, int $y = 0): ?string {
        if (preg_match("/SELECT option_value FROM wp_options WHERE option_name = '((?:[^'\\\\]|\\\\.)*)' LIMIT 1/", $query, $match) === 1) {
            $this->optionReadNames[] = stripslashes($match[1]);
        }
        $this->syncFixtureToStore();
        $result = parent::get_var($query, $x, $y);
        $this->syncStoreToFixture();
        return $result;
    }

    private function syncFixtureToStore(): void {
        $state = $this->fixtureState();
        if (hash_equals($this->fixtureState, $state)) {
            return;
        }
        $mapRows = [];
        foreach ($this->map as $key => $localId) {
            [$uuid, $kind] = explode("\0", $key, 2);
            $mapRows[] = ['uuid' => $uuid, 'id_kind' => $kind, 'local_id' => $localId];
        }
        $postRows = [];
        foreach ($this->postsRows as $id => $row) {
            $postRows[] = ['ID' => $id] + $row;
        }
        $optionRows = [];
        foreach ($this->optionRows as $name => $value) {
            $optionRows[] = ['option_name' => $name, 'option_value' => $value, 'autoload' => 'no'];
        }
        $kvRows = [];
        foreach ($this->kv as $key => $value) {
            $kvRows[] = ['k' => $key, 'v' => $value];
        }
        $this->seedTable('wprism_map', $mapRows);
        $this->seedTable('wprism_kv', $kvRows);
        $this->seedTable('posts', $postRows);
        $this->seedTable('options', $optionRows);
        $this->seedTable('term_taxonomy', []);
        $this->fixtureState = $state;
    }

    private function syncStoreToFixture(): void {
        $this->map = [];
        foreach ($this->rows('wprism_map') as $row) {
            $this->map[(string) $row['uuid'] . "\0" . (string) $row['id_kind']] = (int) $row['local_id'];
        }
        $this->kv = [];
        foreach ($this->rows('wprism_kv') as $row) {
            $this->kv[(string) $row['k']] = (string) $row['v'];
        }
        $this->optionRows = [];
        foreach ($this->rows('options') as $row) {
            $this->optionRows[(string) $row['option_name']] = $row['option_value'];
        }
        $this->postsRows = [];
        foreach ($this->rows('posts') as $row) {
            $id = (int) $row['ID'];
            unset($row['ID']);
            $this->postsRows[$id] = $row;
        }
        $this->fixtureState = $this->fixtureState();
    }

    private function fixtureState(): string {
        return hash('sha256', serialize([
            $this->map,
            $this->postsRows,
            $this->kv,
            $this->optionRows,
        ]));
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

$batchBuilder = new \WPrism\ProviderActionBatchBuilder($policyFor($manifest), []);

$customPost = '88888888-8888-4888-8888-888888888888';
$wpdb->map[$customPost . "\0post"] = 808;
$customBatch = $batchBuilder->action_entities(
    ['provider' => 'probe-cache', 'capability' => 'flush', 'triggers' => ['post:*']],
    [['uuid' => $customPost]],
    [$customPost => ['type' => 'post', 'data' => ['type' => 'book']]],
    includeGenericPending: false
);
$check($customBatch['entities'] === [['kind' => 'post:book', 'id' => 808]]
    && $customBatch['markers'] === [$customPost => 'book'],
    'the bounded post:* primitive carries a scoped custom CPT through concrete-surface batch assembly; the provider never receives a wildcard row');

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
// issue #3342: every row carries all six keys, including for a tombstone the
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
$deletions = $batchBuilder->action_deletions($batchAction, $deleteWork);
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
$reparents = $batchBuilder->action_reparents($batchAction, $regenContext);
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

$context = $batchBuilder->action_context(
    $batchAction,
    ['scope' => 'entity', 'context' => ['deletions']],
    $deleteWork,
    $regenContext
);
$check(array_keys($context) === ['deletions'] && $context['deletions'] === $deletions,
    'only the declared channel is assembled — an undeclared one costs no work and delivers nothing');
$check($batchBuilder->action_context($batchAction, ['scope' => 'entity'], $deleteWork, $regenContext) === [],
    'a capability declaring no context assembles nothing at all (the pre-issue #3369 path)');
$check($batchBuilder->action_context(
        $batchAction,
        ['scope' => 'entity', 'context' => ['retry']],
        [],
        [],
        [],
        [],
        true
    )
    === ['retry' => true],
    "the retry channel reports the apply_in_progress marker this run's selection already consulted");
$check($batchBuilder->action_context(
        $batchAction,
        ['scope' => 'entity', 'context' => ['always_on_write']],
        [],
        []
    ) === ['always_on_write' => true],
    'always_on_write is assembled as the flag it is — true because it was declared, not because anything happened');

echo "\n== the empty-batch skip: narrowed, not loosened ==\n";
$noChannels = ['scope' => 'entity'];
$withDeletions = ['scope' => 'entity', 'context' => ['deletions']];
$alwaysOn = ['scope' => 'entity', 'context' => ['deletions', 'always_on_write']];
$alwaysOnAlone = ['scope' => 'entity', 'context' => ['always_on_write']];
$hasWork = static fn(array $declaration, array $entities, array $channels): bool =>
    $batchBuilder->action_batch_has_work($declaration, $entities, $channels);
$check($hasWork($noChannels, [], []) === false,
    'the issue #3338 skip survives verbatim: a channel-less capability with an empty entity batch is still skipped');
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
 *
 * $receipt plants what the capability returns (issue #3383), so the publication
 * bounds can be exercised through the whole pass rather than at invoke() alone.
 */
$driveRebuild = static function (
    array $channels,
    array $rebuildArgs,
    bool $retrying = false,
    mixed $receiptOverride = null,
    ?\WPrism\Policy $policyOverride = null
) use ($drivePolicy, $driveAction, $reset, $dir): array {
    $reset();
    \WPrism\Providers\ProbeCache::$receiptOverride = $receiptOverride;
    \WPrism\Providers\ProbeCache::$capabilityOverrides = $channels === []
        ? ['scope' => 'entity']
        : ['scope' => 'entity', 'context' => $channels];
    // The policy the PASS reads (pinned actions, marker ownership) may differ
    // from the one that built $driveAction: the sweep is deliberately
    // run-independent, so proving a narrowing needs a pinned claimant the
    // selection does not contain.
    $passPolicy = $policyOverride ?? $drivePolicy;
    $provider = new \WPrism\Providers\ProbeCache($passPolicy);
    $selection = new \WPrism\RebuildSelection($passPolicy);
    $selection->set_selected_actions([$driveAction]);
    $negotiated = [
        'providers' => ['probe-cache' => $provider],
        'capabilities' => ['probe-cache' => ['flush' => $provider->capabilities()['flush']]],
    ];
    $selection->set_negotiated_providers($negotiated);
    [$attachmentIds, $work, $tree, $regenContext, $deleteWork, $withDeletes, $absentTombstones] = $rebuildArgs;
    $warnings = [];
    $receipts = [];
    $error = '';
    try {
        $compiledSentinel = (new \ReflectionClass(\WPrism\CompiledRepository::class))
            ->newInstanceWithoutConstructor();
        $callbacks = new \WPrism\ApplyServiceCallbacks(
            taxonomyOwnership: static fn(): array => [],
            renewPromotionLock: static function (string $phase): void {},
            renewRegenerationLease: static function (): void {},
            renewProviderLease: static function (): void {},
            lockDeleteGuards: static function (array $a, array $b, array $c, array $d, array $e): void {},
            recheckDeleteGuard: static function (array $a, array $b, array $c, bool $d, array $e, array $f, bool $g): void {},
            selectionDeclaresChannelFor: fn(string $channel, string $surface): bool => $selection->declares_channel_for($channel, $surface),
            selectionDeclaresEntityBatchFor: fn(string $surface): bool => $selection->declares_entity_batch_for($surface),
            selectionTriggersProviderActionFor: fn(string $surface): bool => $selection->triggers_provider_action_for($surface),
            pinnedProviderActionOwns: fn(string $surface): bool => $selection->pinned_action_owns($surface),
            upsertMeta: static function (string $table, string $keyColumn, int $id, string $metaKey, ?string $value, ?string $phase, string $metaIdColumn): void {}
        );
        // The real composition root now binds durable attachment control
        // state to the caller-owned repository even when this particular
        // rebuild has no attachments. Keep the product-path drive exact
        // instead of bypassing ApplyServices with hand-built collaborators.
        $services = new \WPrism\ApplyServices($passPolicy, $compiledSentinel, $callbacks, $dir);
        $coordinator = new \WPrism\ApplyRebuildCoordinator($services, $selection);
        $coordinator->rebuild(
            new \WPrism\RebuildRequest(
                attachmentIds: $attachmentIds,
                work: $work,
                tree: $tree,
                regenerationContext: $regenContext,
                deleteWork: $deleteWork,
                withDeletes: $withDeletes,
                absentTombstones: $absentTombstones,
                retryingIncompleteApply: $retrying,
                scoped: false,
                skipScopedCore: false,
                scopedCoreComplete: null,
                suppressScopedExternalEffects: false,
                scopedSession: null,
                scopedObservation: null
            ),
            $warnings,
            $receipts
        );
    } catch (\Throwable $t) {
        $error = $t->getMessage();
    }
    return [
        'args' => $provider->calls[0][1] ?? null,
        'calls' => count($provider->calls),
        'warnings' => implode("\n", $warnings),
        'receipts' => $receipts,
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
$flushesBefore = $GLOBALS['wprism_test_cache_flushes'];
$run = $driveRebuild(['deletions'], [[], [], [], [], $driveTombstones, true, []]);
$check($run['error'] === '' && $run['calls'] === 1
    && ($run['args']['entities']['deletions'] ?? null)
        === [$deletionRow('post:probe', $liveDeleted, 41, 'probe')],
    'the tombstones a --with-deletes run APPLIED reach the capability through the whole pass, not just the projection');
$check(array_keys((array) ($run['args']['entities'] ?? [])) === ['entities', 'deletions']
    && ($run['args']['entities']['entities'] ?? null) === [],
    'the assembled context reaches invoke() as the envelope — a bare batch here would mean the pass dropped it');
$check($GLOBALS['wprism_test_cache_flushes'] === $flushesBefore + 2,
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
// Isolate the coordinator's actual private run() source so an unrelated
// constructor, comment, or dead helper cannot satisfy these named mappings.
// Attachment recovery deliberately gives this request a name before invoking
// the coordinator: its native authority must be discarded on every earlier
// failure, but becomes the coordinator's responsibility once entry is marked.
// The direct `rebuild(new RebuildRequest(...))` spelling therefore cannot be
// the test's boundary; prove both the request's exact inputs and its one live
// handoff instead.
$runSource = (string) file_get_contents($root . '/agent/src/Apply/ApplyRequestCoordinator.php');
$runMethodStart = strpos($runSource, 'private function run(');
$runMethodEnd = strpos($runSource, "\n    private static function assert_expected_artifact(", (int) $runMethodStart);
$runMethodSource = substr(
    $runSource,
    (int) $runMethodStart,
    (int) $runMethodEnd - (int) $runMethodStart
);
$expectedRebuildRequest = <<<'PHP'
        $rebuildRequest = new RebuildRequest(
                attachmentIds: $attachmentIds,
                work: $work,
                tree: $tree,
                regenerationContext: $regenContext,
                deleteWork: $deleteWork,
                withDeletes: $executeDeletes,
                absentTombstones: $plan['deleted'],
                retryingIncompleteApply: $this->retryingIncompleteApply,
                scoped: $scoped,
                skipScopedCore: $skipScopedCore,
                scopedCoreComplete: $scopedCoreComplete === null
                    ? null
                    : \Closure::fromCallable($scopedCoreComplete),
                suppressScopedExternalEffects: $scopedPromotion,
                scopedSession: $this->scopedWorkflow->session,
                scopedObservation: $this->scopedWorkflow->observation
        );
PHP;
$expectedRebuildHandoff = <<<'PHP'
        $this->rebuildCoordinator->rebuild(
            $rebuildRequest,
            $this->warnings,
            $this->actionReceipts
        );
PHP;
$check(
    $runMethodStart !== false
        && $runMethodEnd !== false
        && str_contains($runMethodSource, $expectedRebuildRequest)
        && str_contains($runMethodSource, $expectedRebuildHandoff),
    "run() hands the rebuild pass this run's tombstones, the with_deletes gate, and the already-absent set — never "
    . 'the wider set the pre-mutation selection projected surfaces from; scoped promotion also retains its '
    . 'checkpoint-only external-effects profile');
$check((bool) preg_match('/\$this->retryingIncompleteApply\s*=\s*\$retryingIncompleteApply;/', $runMethodSource),
    'run() records its apply_in_progress read on the instance, which is the only path by which the retry channel '
    . 'can ever be true');

echo "\n== issue #3383: every surface a successful receipt reaches, and the ones it must not ==\n";
// The bounds above are proved at invoke(). These prove the pass that consumes
// it publishes nothing else: Apply's `actions` rows ARE `wp wprism apply
// --format=json`'s `actions` (Apply::rebuild() copies the receipt fields
// straight in, Cli::apply() serializes the summary whole), and the human line
// is the warning this same loop appends.
$plantedReceipt = [
    'before' => [
        'token' => $receiptSecretValue,
        'note' => $receiptControlValue,
        'blob' => $receiptOversized,
    ],
    'after' => [
        'token' => $receiptSecretValue,
        'note' => 'flushed',
        'blob' => $receiptOversized,
    ],
    'verified' => true,
];
$plantedRun = $driveRebuild(['deletions'], [[], [], [], [], $driveTombstones, true, []], false, $plantedReceipt);
$plantedJson = (string) json_encode($plantedRun["receipts"], JSON_UNESCAPED_SLASHES);
$check($plantedRun['error'] === '' && $plantedRun['calls'] === 1
    && ($plantedRun['receipts'][0]['verified'] ?? null) === true,
    'a receipt whose every value is out of bounds is still a SUCCESSFUL receipt: the pass fires, verifies, and '
    . 'records it — bounding publishes less, it does not refuse more');
$check(
    !str_contains($plantedJson, $receiptSecretValue)
    && !str_contains($plantedJson, 'INJECTED_RECEIPT_WARNING')
    && !str_contains($plantedJson, $receiptOversized)
    && preg_match('/[\x00-\x1F\x7F]/', $plantedJson) !== 1,
    'the JSON public output — Apply\'s `actions` rows, which Cli::apply() serializes whole — carries no planted '
    . 'secret, no injected line, no control byte, and no oversized blob'
);
$check(
    !str_contains($plantedRun['warnings'], $receiptSecretValue)
    && !str_contains($plantedRun['warnings'], 'INJECTED_RECEIPT_WARNING')
    && !str_contains($plantedRun['warnings'], $receiptOversized)
    && str_contains($plantedRun['warnings'], 'provider capability fired: probe-cache@1.0.0 flush')
    && str_contains($plantedRun['warnings'], 'verified)'),
    'and the human public output still says the capability fired and verified, carrying duration only — the human '
    . 'line never rendered receipt VALUES, and this is what keeps that true'
);
$check(
    ($plantedRun['receipts'][0]['before']['token'] ?? null)
        === ($plantedRun['receipts'][0]['after']['token'] ?? false)
    && ($plantedRun['receipts'][0]['before']['note'] ?? null)
        !== ($plantedRun['receipts'][0]['after']['note'] ?? null),
    'while the receipt still proves what it is for: through the whole pass, the unchanged secret publishes as one '
    . 'witness on both sides and the changed note publishes as two — before/after remains decidable without the values'
);

// Stored evidence: ordinary apply keeps the bounded public projection in one
// in-memory list. Scoped apply adds only reviewed hash projections to that
// same public list and persists only a hash-bound outer receipt in its closed
// recovery session. These pins keep either path from acquiring a second raw
// provider-value sink or a host re-renderer.
$applyReceiptLines = (array) file($root . '/agent/src/Apply/ApplyRequestCoordinator.php', FILE_IGNORE_NEW_LINES);
$dispatcherReceiptLines = (array) file($root . '/agent/src/Rebuild/RebuildActionDispatcher.php', FILE_IGNORE_NEW_LINES);
$check(
    count(array_filter(
        $applyReceiptLines,
        static fn(string $line): bool => str_contains($line, 'private array $actionReceipts = [];')
    )) === 1
    && count(array_filter(
        $dispatcherReceiptLines,
        static fn(string $line): bool => str_contains($line, '$actionReceipts[] = [')
    )) === 3
    && count(array_filter(
        $dispatcherReceiptLines,
        static fn(string $line): bool => str_contains($line, '$actionReceipts[] = ScopedApplyCoordinator::public_action_receipt(')
    )) === 3
    && count(array_filter(
        $applyReceiptLines,
        static fn(string $line): bool => str_contains($line, "'actions' => \$this->actionReceipts,")
    )) === 1,
    'Apply holds public receipts in exactly one in-memory list: three legacy bounded projections and three scoped '
    . 'hash-only projections, returned once; no durable scoped record keeps provider before/after values'
);
$scopedCoordinatorSource = (string) file_get_contents($root . '/agent/src/Scope/ScopedApplyCoordinator.php');
$scopedReceiptMethodStart = strpos($scopedCoordinatorSource, 'public static function public_action_receipt(');
$scopedReceiptMethodEnd = $scopedReceiptMethodStart === false
    ? false
    : strpos($scopedCoordinatorSource, "\n    public static function receipt_at(", $scopedReceiptMethodStart);
$scopedReceiptMethod = ($scopedReceiptMethodStart === false || $scopedReceiptMethodEnd === false)
    ? ''
    : substr($scopedCoordinatorSource, $scopedReceiptMethodStart, $scopedReceiptMethodEnd - $scopedReceiptMethodStart);
$check(
    $scopedReceiptMethod !== ''
    && str_contains($scopedReceiptMethod, "'operation_hash' =>")
    && str_contains($scopedReceiptMethod, "'receipt_hash' =>")
    && !str_contains($scopedReceiptMethod, "'before' =>")
    && !str_contains($scopedReceiptMethod, "'after' =>"),
    'the scoped public receipt helper exposes operation/receipt hashes and never provider before/after values'
);
$cliSource = (string) file_get_contents($root . '/agent/src/Command/Cli.php');
$applyMethodStart = strpos($cliSource, 'public function apply(');
$applyMethodEnd = $applyMethodStart === false
    ? false
    : strpos($cliSource, "\n    public function ", $applyMethodStart + 1);
$applyMethod = ($applyMethodStart === false || $applyMethodEnd === false)
    ? ''
    : substr($cliSource, $applyMethodStart, $applyMethodEnd - $applyMethodStart);
$check(
    $applyMethod !== '' && !str_contains($applyMethod, "\$summary['actions']"),
    "the agent's human apply render never reads the receipt rows at all — its JSON arm publishes the summary whole, "
    . 'and that is the surface the bounds above cover'
);
$hostSource = (string) file_get_contents($root . '/cli/wprism');
preg_match_all('/\$applySummary\[[^\]]+\]/', $hostSource, $hostReads);
$check(
    array_values(array_unique($hostReads[0])) === ["\$applySummary['artifact']"],
    'and the host reads the apply summary for its artifact identity only — the promotion receipt it retains has a '
    . 'locked schema that no provider-returned value enters'
);

echo "\n== the capture behind the reparents channel: scoped to DECLARED consumers ==\n";
// issue #3369 review, F2(a): scoping the capture to batch regen_dependency post
// types alone made the channel structurally empty for a provider-only
// manifest — a capability could declare `reparents`, negotiate clean, and
// never receive a row no matter what the revision moved.
$captureWork = [['uuid' => $moved]];
$captureTree = [$moved => ['type' => 'post', 'data' => ['type' => 'probe', 'parent' => '{{post:' . $newParent . '}}']]];
$captureWith = static function (array $channels, array $triggers = ['post:probe']) use (
    $drivePolicy, $driveAction, $captureWork, $captureTree, &$wpdb
): array {
    $wpdb->kv = [];
    $selection = new \WPrism\RebuildSelection($drivePolicy);
    $selection->set_selected_actions([['triggers' => $triggers] + $driveAction]);
    $declaration = $channels === []
        ? ['scope' => 'entity']
        : ['scope' => 'entity', 'context' => $channels];
    $selection->set_negotiated_providers([
        'providers' => [],
        'capabilities' => ['probe-cache' => ['flush' => $declaration]],
    ]);
    $store = new \WPrism\RegenerationContextStore(
        $drivePolicy,
        fn(string $channel, string $surface): bool => $selection->declares_channel_for($channel, $surface)
    );
    return $store->capture_reparents($captureWork, $captureTree);
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
// issue #3342: the provider dispatch gains the crash-safety the regen-batch
// path has — as channel semantics. Four properties, each of which the
// provider path structurally lacked while the regenerator channel had it:
// the pre-delete inventory is CAPTURED for a provider-only manifest, the
// durable receipts are DELIVERED (not just this run's tombstones), the
// markers are OWNED (cleared only on a verified receipt, retained on
// failure, re-delivered next run), and a deleted id is never handed over
// as live work.
// ======================================================================

echo "\n== the capture behind the deletions channel: the same declared-consumer gate ==\n";
$captureDeleteWith = static function (array $channels, array $triggers = ['post:probe']) use (
    $drivePolicy, $driveAction, $liveDeleted, &$wpdb
): array {
    $wpdb->kv = [];
    $selection = new \WPrism\RebuildSelection($drivePolicy);
    $selection->set_selected_actions([['triggers' => $triggers] + $driveAction]);
    $declaration = $channels === []
        ? ['scope' => 'entity']
        : ['scope' => 'entity', 'context' => $channels];
    $selection->set_negotiated_providers([
        'providers' => [],
        'capabilities' => ['probe-cache' => ['flush' => $declaration]],
    ]);
    $store = new \WPrism\RegenerationContextStore(
        $drivePolicy,
        fn(string $channel, string $surface): bool => $selection->declares_channel_for($channel, $surface)
    );
    return $store->capture_deletions([[
        ['type' => 'post', 'uuid' => $liveDeleted],
    ][0]]);
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
$driveSweep = static function (array $sweepManifest, array $negotiated = [], ?array $markers = null) use (
    $policyFor, $sweepMarkers, &$wpdb
): string {
    $wpdb->kv = $markers ?? $sweepMarkers;
    $sweepPolicy = $policyFor($sweepManifest);
    $selection = new \WPrism\RebuildSelection($sweepPolicy);
    $selection->set_selected_actions([]);
    $selection->set_negotiated_providers(['providers' => [], 'capabilities' => $negotiated]);
    $store = new \WPrism\RegenerationContextStore(
        $sweepPolicy,
        fn(string $channel, string $surface): bool => $selection->declares_channel_for($channel, $surface)
    );
    $regenerator = new \WPrism\DependencyRegenerator(
        $sweepPolicy,
        $store,
        fn(string $surface): bool => $selection->declares_entity_batch_for($surface),
        fn(string $channel, string $surface): bool => $selection->declares_channel_for($channel, $surface),
        fn(string $surface): bool => $selection->triggers_provider_action_for($surface),
        fn(string $surface): bool => $selection->pinned_action_owns($surface),
        static function (): void {}
    );
    $warnings = [];
    $regenerator->run([], [], [], $warnings);
    return implode("\n", $warnings);
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
$malformedWarnings = $driveSweep($triggeredManifest, [], $wpdb->kv);
$check(!isset($wpdb->kv['regen_delete_context:malformed-probe-uuid'])
    && str_contains(
        $malformedWarnings,
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
    \WPrism\Providers\ProbeCache::$capabilityOverrides = $overrides;
    $policy = $policyFor($claimantManifest);
    return \WPrism\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
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
$postKindManifest = $manifest;
$postKindManifest['spec_version'] = 3;
$postKindManifest['engine_features'] = ['post-kind-action-trigger/v1', 'spec-window/v1'];
$postKindManifest['actions'][0]['triggers'] = ['post:*'];
$reset();
\WPrism\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity', 'context' => ['deletions']];
$postKindPolicy = $policyFor($postKindManifest);
$postKindProblem = $one(\WPrism\Providers::negotiate(
    $postKindPolicy,
    $postKindPolicy->actions_for(['post:book'])
)['problems']);
$check(($postKindProblem['code'] ?? null) === 'post_kind_trigger_context_unsupported'
    && str_contains((string) ($postKindProblem['remediation'] ?? ''), 'use exact post-type triggers'),
    'a context-bearing provider cannot claim the generic post-kind trigger because durable marker ownership is per concrete post type');
$reset();

echo "\n== outstanding receipts are legible in plan and status (independent review F3) ==\n";
// issue #3342 made these markers SURVIVE a failed apply instead of being swept in
// the same pass that read them. That is the point — and it is also what makes
// them worth surfacing: a marker can now stand between a failure and its retry,
// and an operator deciding "is this safe to promote" must be able to see it.
$drivePlanProjection = static function (\WPrism\Policy $projectionPolicy, ?array $negotiated = []) use ($scratchRoot): array {
    $selection = new \WPrism\RebuildSelection($projectionPolicy);
    $selection->set_negotiated_providers($negotiated);
    return (new \WPrism\ApplyPlanEnvironment($projectionPolicy, $selection, $scratchRoot))
        ->regeneration_debt_projection();
};
$wpdb->kv = $sweepMarkers + [
    'regen_delete_context:malformed' => (string) json_encode(['kind' => 'delete', 'post_type' => 'probe']),
];
$planProjection = $drivePlanProjection($policyFor($claimantManifest));
$check($planProjection['regen_pending'] === [[
    'uuid' => $moved, 'type' => 'post', 'post_type' => 'probe',
]], 'the Apply boundary reads the real Ledger pending keyspace and Policy declaration before handing rows to the planner');
$rows = $planProjection['regen_context'];
$check($rows === [
    ['uuid' => $liveDeleted, 'type' => 'post', 'post_type' => 'probe', 'kind' => 'delete'],
    ['uuid' => $moved, 'type' => 'post', 'post_type' => 'probe', 'kind' => 'reparent'],
], 'plan projects one row per outstanding receipt, both keyspaces, ordered and carrying its kind');
$check(count($rows) === 2,
    'and a malformed receipt is NOT surfaced — apply sweeps that one itself, loudly, so a plan reader has '
    . 'nothing to do about it');
$check($planProjection['warnings'] === [
    "regen_pending: post $moved (type 'probe') has a regeneration retry pending from a prior failed verify",
    "regen_context: post $liveDeleted (type 'probe') has an outstanding delete receipt awaiting a verified derived-state repair",
    "regen_context: post $moved (type 'probe') has an outstanding reparent receipt awaiting a verified derived-state repair",
], 'the Apply boundary preserves the planner warning projection and its pending-before-context ordering');
$noClaimantProjection = $drivePlanProjection($policyFor($manifest));
$check($noClaimantProjection['regen_context'] === []
    && $noClaimantProjection['regen_pending'] === [],
    'a receipt no pinned claimant owns is not surfaced either — the projection shows outstanding DEBT, never '
    . 'orphaned bookkeeping');

echo "\n== env_missing is driven through Apply's real read-only boundary ==\n";
$envManifest = $manifest;
$envManifest['options'] = [
    'z_optional' => ['class' => 'env', 'required' => false],
    'a_required' => ['class' => 'env', 'required' => true],
    'm_present' => ['class' => 'env', 'required' => true],
];
$envPolicy = $policyFor($envManifest);
$wpdb->optionRows = ['m_present' => 'configured', 'z_optional' => ''];
$wpdb->optionReadNames = [];
\WPrism\EnvironmentValues::set($scratchRoot, 'm_present', 'configured');
$envFacade = (new \WPrism\ApplyPlanEnvironment(
    $envPolicy,
    new \WPrism\RebuildSelection($envPolicy),
    $scratchRoot
))->env_missing_projection();
$check($envFacade === [
    'env_missing' => [
        ['name' => 'a_required', 'required' => true],
        ['name' => 'z_optional', 'required' => false],
    ],
    'warnings' => [
        "env_missing: option 'a_required' is required and not yet provisioned on "
            . "this environment — see 'wp wprism env-set --name=a_required --stdin'",
    ],
], 'the real Apply facade combines Policy env declarations with live wp_options values');
$check(
    $wpdb->optionReadNames === ['a_required', 'm_present', 'z_optional'],
    'the Apply facade reads every resolved env option in Policy order through the wpdb boundary'
);
$wpdb->optionRows = [];
$wpdb->optionReadNames = [];

// The projection is hashed into the promotion precondition BEFORE negotiation
// (run()'s first plan) and after it (freshPlan), so it must be a pure function
// of policy bytes + the keyspace: a negotiation-dependent answer makes the two
// plans disagree over an unmutated keyspace and wedges the apply behind a
// "preconditions changed" refusal that repeats forever (delta review, N1 —
// driven: a marker whose only pinned claimant negotiates scope:site).
$projectionAcross = [];
foreach ([
    'pre-negotiation (null map)' => null,
    'empty negotiation' => ['providers' => [], 'capabilities' => []],
    'claimant negotiated scope:site' => ['providers' => [], 'capabilities' => [
        'probe-cache' => ['flush' => ['scope' => 'site', 'idempotent' => true, 'args' => []]],
    ]],
] as $state => $negotiated) {
    $wpdb->kv = $sweepMarkers;
    $projectionAcross[$state] = $drivePlanProjection($policyFor($triggeredManifest), $negotiated);
}
$check(count($projectionAcross['pre-negotiation (null map)']['regen_context']) === 2
    && count(array_unique(array_map('serialize', $projectionAcross))) === 1,
    'the planner projection is identical before negotiation, after an empty one, and after the claimant negotiates '
    . 'scope:site — plan and freshPlan can never disagree over an unmutated keyspace');
$wpdb->kv = [];

// The status half, driven through the real summariser: a plan carrying one of
// these rows must render it and must not report ok.
require_once $root . '/cli/src/Plan/PlanSummary.php';
$statusPlan = array_fill_keys([
    'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict',
    'collision', 'delete', 'delete_conflict', 'deleted',
], []);
$statusPlan['regen_context'] = [
    ['uuid' => $liveDeleted, 'type' => 'post', 'post_type' => 'probe', 'kind' => 'delete'],
];
$rendered = \WPrism\Orchestrator\PlanSummary::render($statusPlan);
$renderedText = implode("\n", (array) $rendered['lines']);
$check(($rendered['ok'] ?? null) === false,
    'wprism status refuses to call an environment clean while a derived-state receipt is outstanding — same '
    . 'footing as regen_pending, and for the same reason');
$check(str_contains($renderedText, '1 regen_context')
    && str_contains($renderedText, 'REGEN_CONTEXT')
    && str_contains($renderedText, "post type 'probe', delete receipt"),
    'and names the entity, its post type, and which receipt is outstanding');
$statusPlan['regen_context'] = [];
$check((\WPrism\Orchestrator\PlanSummary::render($statusPlan)['ok'] ?? null) === true,
    'an empty bucket is not a blocker — the row is the signal, never the key');
// build_plan() itself is not drivable offline (it needs a compiled repository
// and a live target), so its one edge into the planner projection is asserted
// against its own source — the idiom this suite already uses for run()'s
// threading. Without it, deleting the call site while keeping the method passes
// every behavioural check in this section (proven: that mutation survived).
$buildPlanMethod = (new ReflectionClass(\WPrism\ApplyPlanBuilder::class))->getMethod('build');
$buildPlanSource = implode("\n", array_slice(
    (array) file((string) $buildPlanMethod->getFileName(), FILE_IGNORE_NEW_LINES),
    $buildPlanMethod->getStartLine() - 1,
    $buildPlanMethod->getEndLine() - $buildPlanMethod->getStartLine() + 1
));
$check((bool) preg_match(
    "/\\\$regenDebt\\s*=\\s*\\\$this->regeneration_debt_projection\\(\\);/",
    $buildPlanSource
), 'ApplyPlanBuilder actually invokes the Apply boundary facade for the shared debt projection — the plan a human reads is the one those checks '
    . 'just exercised');
$check((bool) preg_match(
    "/foreach \\(\\\$regenDebt\\['warnings'\\] as \\\$warning\\) \\{\\s*\\\$this->warnings\\[\\] = \\\$warning;/",
    $buildPlanSource
), 'and carries the projection warnings into Apply, so a plain `wprism plan` says it out loud rather than only in a '
    . 'structured bucket a script has to look for');

// Lockstep with the agent-side renderer and the precondition hash: `wprism status`
// and a plain `wp wprism plan` must never give an operator different advice, and a
// receipt appearing between plan and apply must invalidate the plan.
$cliSource = (string) file_get_contents($root . '/agent/src/Command/Cli.php');
$check(str_contains($cliSource, "REGEN_CONTEXT ")
    && str_contains($cliSource, "count(\$plan['regen_context'] ?? []) . ' regen_context'"),
    'the agent-side plan renderer carries the same bucket, count line included');
$plannerHashMethod = (new ReflectionClass(\WPrism\ApplyPlanner::class))->getMethod('plan_precondition_hash');
$hashSource = implode("\n", array_slice(
    (array) file((string) $plannerHashMethod->getFileName(), FILE_IGNORE_NEW_LINES),
    $plannerHashMethod->getStartLine() - 1,
    $plannerHashMethod->getEndLine()
        - $plannerHashMethod->getStartLine() + 1
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
    \WPrism\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity', 'context' => $firstContext];
    $m = $manifest;
    $m['actions'][0]['triggers'] = ['post:probe'];
    if ($secondCapabilityName === 'flush_again') {
        \WPrism\Providers\ProbeCache::$extraCapabilities = ['flush_again' => $secondCapability($secondContext)];
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
    return \WPrism\Providers::negotiate($policy, $policy->actions_for(['post:probe', 'post:probe_other']));
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
// issue #3314 made a negotiation problem row operator-facing wire data:
// Policy::provider_readiness_blockers() promotes exactly these keys into the
// adapter_dispositions rows `wprism status` and `wprism capabilities` render. A row
// missing one of them renders as '?' or blank, so the two codes this migration
// added have to carry the same shape every other refusal does — including
// carrying NO extra key a renderer would have to know to ignore.
$promotedKeys = ['provider', 'manifest', 'plugin', 'code', 'expected', 'found', 'remediation', 'message'];
$rowShapeOk = static function (array $row) use ($promotedKeys): bool {
    $keys = array_keys($row);
    sort($keys, SORT_STRING);
    $expected = $promotedKeys;
    sort($expected, SORT_STRING);
    if ($keys !== $expected) {
        return false;
    }
    foreach ($promotedKeys as $key) {
        if (!is_string($row[$key]) || $row[$key] === '') {
            return false;
        }
    }
    return true;
};
$check($rowShapeOk($p),
    'and the refusal row carries exactly the keys the readiness projection reads, each non-empty — a '
    . 'channel collision is legible in `wprism status`, not just in an apply that refused');
$claimantRow = $one($claimantNegotiation(['deletions'])['problems']);
$check($rowShapeOk($claimantRow),
    'the dual-claimant refusal carries the same wire shape for the same reason');
// The other half of issue #3314's posture: a refusal an operator reads must not
// carry third-party free-form text. Both rows are built only from closed
// vocabularies and pattern-validated identities. Pinned as the two markers that
// betray a leak rather than as a full charset (the engine's own prose uses em
// dashes and backticks): a PHP class name or namespaced type carries a
// backslash, and an exception message or stack trace carries a newline.
$leakFree = static fn(array $row): bool =>
    !str_contains($row['expected'] . $row['found'] . $row['remediation'] . $row['message'], '\\')
    && !str_contains($row['expected'] . $row['found'] . $row['remediation'] . $row['message'], "\n");
$check($leakFree($p) && $leakFree($claimantRow),
    'and neither carries a backslash or a newline — the two shapes a leaked class name, PHP type, or '
    . 'exception message would arrive in');

$check($twoConsumers(['deletions'], ['deletions'], ['post:probe_other'])['problems'] === [],
    'the same channel on DIFFERENT surfaces is two keyspaces, not one: no collision');
$check($twoConsumers(['deletions'], ['reparents'])['problems'] === [],
    'different channels on the same surface touch different marker prefixes: no collision either');
$check($twoConsumers(['deletions'], [], ['post:probe'], 'flush')['problems'] === [],
    'and ONE capability selected by two actions on the same surface is one consumer, not two — the dedupe is '
    . 'by capability identity, never by action count');

// The cross-PROVIDER shape: two adapters claiming one channel on one surface.
// This is the realistic collision, and the only one that can tell a complete
// unbind from a partial one — a single provider's two capabilities both live
// under one key, so unbinding "the first claimant" alone would look identical.
$reset();
\WPrism\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity', 'context' => ['deletions']];
$GLOBALS['wprism_test_probe_index_capability_overrides'] = ['context' => ['deletions']];
$crossManifest = $manifest;
$crossManifest['actions'][0]['triggers'] = ['post:probe'];
$crossManifest['providers'][] = [
    'id' => 'probe-index',
    'version' => '1.0.0',
    'source' => 'manifest',
    'plugin' => 'probe/probe.php',
    'capabilities' => ['reindex'],
];
$crossManifest['actions'][] = [
    'kind' => 'provider',
    'provider' => 'probe-index',
    'capability' => 'reindex',
    'args' => [],
    'triggers' => ['post:probe'],
];
$crossPolicy = $policyFor($crossManifest);
$crossNegotiation = \WPrism\Providers::negotiate($crossPolicy, $crossPolicy->actions_for(['post:probe']));
$crossProblem = $one($crossNegotiation['problems']);
$check(($crossProblem['code'] ?? '') === 'channel_claimed_twice'
    && str_contains($crossProblem['found'] ?? '', 'probe-cache/flush, probe-index/reindex'),
    'two DIFFERENT providers claiming one channel on one surface collide too — the accumulator is keyed by '
    . 'channel and surface, never by provider');
$check(!isset($crossNegotiation['providers']['probe-cache'])
    && !isset($crossNegotiation['providers']['probe-index'])
    && !isset($crossNegotiation['capabilities']['probe-cache'])
    && !isset($crossNegotiation['capabilities']['probe-index']),
    'and EVERY claimant is unbound, not just the one the refusal is attributed to — a partial unbind would '
    . 'leave one of them holding a marker keyspace the refusal says has no owner');
// issue #3339 gave these refusals a SECOND surface: Providers::problems() runs the
// same diagnosis over every pinned action for plan/status, and subtracts rows
// the narrowed gating diagnosis already reported. Both new codes have to travel
// that path like any other — they are ordinary problem rows, and the moment
// they were not, a collision would be reported twice to one operator, or not at
// all.
$crossWide = \WPrism\Providers::problems($crossPolicy);
$check(array_column($crossWide, 'code') === ['channel_claimed_twice'],
    'a channel collision reaches the wide plan/status view too, so a half-finished migration is visible before '
    . 'the apply that would refuse on it');
$crossGating = [[
    'name' => $crossWide[0]['manifest'],
    'provider' => $crossWide[0]['provider'],
    'manifest' => $crossWide[0]['manifest'],
    'plugin' => $crossWide[0]['plugin'],
    'code' => $crossWide[0]['code'],
    'status' => 'blocked',
]];
$check(\WPrism\Providers::problems($crossPolicy, $crossGating) === [],
    'and once the scoped diagnosis has gated on it, the wide view drops it — one collision is one finding, '
    . 'even though the row is attributed to only one of its claimants');
$check(\WPrism\Providers::problems($crossPolicy) !== [],
    'subtraction, never suppression: with nothing gating, the same call still reports it');
$GLOBALS['wprism_test_probe_index_capability_overrides'] = [];

$reset();
\WPrism\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity', 'context' => ['deletions']];
$claimantWide = \WPrism\Providers::problems($policyFor($claimantManifest));
$check(array_column($claimantWide, 'code') === ['post_type_claimed_by_regen_batch'],
    'the dual-claimant refusal travels the same surface, for the same reason');
$check(\WPrism\Providers::problems($policyFor($claimantManifest), [[
    'provider' => $claimantWide[0]['provider'],
    'manifest' => $claimantWide[0]['manifest'],
    'code' => $claimantWide[0]['code'],
]]) === [],
    'and subtracts on the same (provider, manifest, code) key every other row uses');
$reset();

echo "\n== byte-compatibility with the pre-issue #3369 contract, in frozen bytes ==\n";
// Both literals below were captured by running THIS harness's fixtures through
// the engine as of main@40b54fe (the commit before issue #3369) and printing
// serialize() of the negotiated declaration map and of the arguments the
// capability received. They are the acceptance criterion in executable form:
// an existing scalar-args, channel-less provider must not be able to observe
// that either channel grew.
$reset();
$policy = $policyFor($manifest);
$negotiation = \WPrism\Providers::negotiate($policy, $policy->actions_for(['post:probe']));
$check(
    serialize($negotiation['capabilities'])
        === 'a:1:{s:11:"probe-cache";a:1:{s:5:"flush";a:6:{s:4:"args";a:1:{s:6:"groups";a:2:{s:4:"type";'
            . 's:12:"list<string>";s:8:"required";b:1;}}s:5:"reads";a:1:{i:0;s:20:"option:probe_setting";}'
            . 's:6:"writes";a:1:{i:0;s:25:"entity:probe-cache-groups";}s:5:"scope";s:4:"site";s:10:'
            . '"idempotent";b:1;s:15:"timeout_seconds";i:30;}}}',
    'a scalar-args capability negotiates to declaration bytes identical to the pre-change engine (no key added, none reordered)'
);
$reset();
\WPrism\Providers\ProbeCache::$capabilityOverrides = ['scope' => 'entity'];
$policy = $policyFor($manifest);
$byteAction = $policy->actions_for(['post:probe'])[0];
$byteAction['triggers'] = ['post:probe'];
$byteProvider = new \WPrism\Providers\ProbeCache($policy);
\WPrism\Providers::invoke($byteProvider, $byteAction, $byteProvider->capabilities()['flush'], $batch);
$check(
    serialize($byteProvider->calls[0][1])
        === 'a:2:{s:6:"groups";a:1:{i:0;s:11:"probe-group";}s:8:"entities";a:2:{i:0;a:2:{s:4:"kind";'
            . 's:10:"post:probe";s:2:"id";i:7;}i:1;a:2:{s:4:"kind";s:14:"term:probe_tax";s:2:"id";i:3;}}}',
    'a channel-less entity-scoped capability receives the bare batch, byte-identical to the pre-change engine'
);
$reset();

echo "\n== manifest-shipped provider code is part of the manifest artifact ==\n";
unlink($dir . '/providers/probe-index.php');
unlink($dir . '/providers/probe-cache.php');
$reset();
$policy = $policyFor($manifest);
$expectMessage(
    static fn() => \WPrism\Providers::negotiate($policy, $policy->actions_for(['post:probe'])),
    'provider code ships with its manifest, not the engine',
    'a missing manifest-shipped provider file is a packaging fault that throws, not an environment problem'
);

echo $failures === 0 ? "\nALL PASSED\n" : "\n$failures check(s) failed\n";
exit($failures === 0 ? 0 : 1);
