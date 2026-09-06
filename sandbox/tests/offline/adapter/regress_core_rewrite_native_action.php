<?php
declare(strict_types=1);

/**
 * Core permalink regeneration through the real NativeActions public boundary.
 *
 * Apply materializes authored options with direct SQL. WordPress therefore
 * does not run Settings -> Permalinks' flush, and a non-empty target
 * rewrite_rules row continues serving the old grammar. This fixture models
 * the exact WordPress 7.0.3 WP_Rewrite contract the engine calls: a fresh
 * process boots against permalink_structure, a soft flush regenerates and
 * persists ordered rules (or WordPress's exact empty-string sentinel for
 * plain permalinks), and wp_rewrite_rules reads them back. Hostile storage/
 * runtime states and dropped writes prove the checked receipt, not a child-
 * process return code, is the gate.
 */

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/agent_version.php';
require_once __DIR__ . '/../../support/wp_cli_child_process_fake.php';

wprism_test_define_agent_versions();
define('ARRAY_A', 'ARRAY_A');

$GLOBALS['core_rewrite_filters'] = [];
$GLOBALS['core_rewrite_filter_calls'] = [];
$GLOBALS['core_rewrite_action_calls'] = [];
$GLOBALS['core_rewrite_wp_loaded'] = 1;
$GLOBALS['core_rewrite_drop_write'] = false;
$GLOBALS['core_rewrite_mutate_structure'] = false;
$GLOBALS['core_rewrite_child_launches'] = 0;
$GLOBALS['core_rewrite_child_flushes'] = 0;
$GLOBALS['core_rewrite_child_hard_flushes'] = 0;
$GLOBALS['core_rewrite_child_stdout_prefix'] = '';
$GLOBALS['core_rewrite_child_stderr'] = '';
$GLOBALS['core_rewrite_active_cache_bucket'] = 'parent';
$GLOBALS['core_rewrite_option_caches'] = ['parent' => [], 'child' => []];
$GLOBALS['core_rewrite_cache_deletes'] = [];
$GLOBALS['wp_filter'] = [];

final class WP_Hook {
    /** @var array<int,array<string,array{function:mixed,accepted_args:int}>> */
    public array $callbacks = [];
}

function did_action(string $hook): int {
    return $hook === 'wp_loaded' ? (int) $GLOBALS['core_rewrite_wp_loaded'] : 0;
}

function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool {
    $GLOBALS['core_rewrite_filters'][$hook][$priority][] = $callback;
    ksort($GLOBALS['core_rewrite_filters'][$hook], SORT_NUMERIC);
    return true;
}

function remove_filter(string $hook, callable $callback, int $priority = 10): bool {
    foreach (($GLOBALS['core_rewrite_filters'][$hook][$priority] ?? []) as $index => $candidate) {
        if ($candidate === $callback) {
            unset($GLOBALS['core_rewrite_filters'][$hook][$priority][$index]);
            return true;
        }
    }
    return false;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
    $GLOBALS['core_rewrite_filter_calls'][] = $hook;
    foreach (($GLOBALS['core_rewrite_filters'][$hook] ?? []) as $callbacks) {
        foreach ($callbacks as $callback) {
            $value = $callback($value, ...$args);
        }
    }
    return $value;
}

function do_action(string $hook, mixed ...$args): void {
    $GLOBALS['core_rewrite_action_calls'][] = $hook;
}

function maybe_unserialize(mixed $value): mixed {
    if (!is_string($value) || !preg_match('/^(?:a|O|s|b|i|d|N):/', $value)) {
        return $value;
    }
    $decoded = @unserialize($value, ['allowed_classes' => false]);
    return $decoded === false && $value !== 'b:0;' ? $value : $decoded;
}

function core_rewrite_serialize(mixed $value): string {
    return is_array($value) || is_object($value) || is_bool($value) || is_int($value) || is_float($value)
        ? serialize($value)
        : (string) $value;
}

final class CoreRewriteFakeWpdb {
    public string $options = 'wp_options';
    public string $last_error = '';
    /** @var array<string,string> */
    public array $optionRows = [];
    /** @var array<string,int> */
    public array $failReads = [];
    /** @var list<string> */
    public array $readNames = [];

    public function prepare(string $query, mixed ...$args): string {
        foreach ($args as $arg) {
            $query = preg_replace('/%s/', "'" . addslashes((string) $arg) . "'", $query, 1) ?? $query;
        }
        return $query;
    }

    public function get_var(string $query): string|false|null {
        if (!preg_match(
            "/SELECT option_(?:name|value) FROM wp_options WHERE option_name = '((?:[^'\\\\]|\\\\.)*)' LIMIT 1/",
            $query,
            $match
        )) {
            throw new RuntimeException("unexpected core rewrite option query: $query");
        }
        $name = stripslashes($match[1]);
        $this->readNames[] = $name;
        if (($this->failReads[$name] ?? 0) > 0) {
            $this->failReads[$name]--;
            $this->last_error = 'injected database detail which must not escape';
            return false;
        }
        $this->last_error = '';
        if (!array_key_exists($name, $this->optionRows)) {
            return null;
        }
        $value = str_starts_with($query, 'SELECT option_name') ? $name : $this->optionRows[$name];
        // WordPress wpdb::get_var() deliberately returns null for an exact
        // empty string, which makes it unusable for presence-sensitive reads.
        return $value === '' ? null : $value;
    }

    public function get_row(string $query, string $output = ARRAY_A): ?array {
        if ($output !== ARRAY_A || !preg_match(
            "/SELECT option_value FROM wp_options WHERE option_name = '((?:[^'\\\\]|\\\\.)*)' LIMIT 1/",
            $query,
            $match
        )) {
            throw new RuntimeException("unexpected core rewrite option row query: $query");
        }
        $name = stripslashes($match[1]);
        $this->readNames[] = $name;
        if (($this->failReads[$name] ?? 0) > 0) {
            $this->failReads[$name]--;
            $this->last_error = 'injected database detail which must not escape';
            return null;
        }
        $this->last_error = '';
        return array_key_exists($name, $this->optionRows)
            ? ['option_value' => $this->optionRows[$name]]
            : null;
    }
}

function get_option(string $name, mixed $default = false): mixed {
    global $wpdb;
    $pre = apply_filters("pre_option_$name", false, $name, $default);
    $pre = apply_filters('pre_option', $pre, $name, $default);
    if ($pre !== false) {
        return $pre;
    }
    $bucket = $GLOBALS['core_rewrite_active_cache_bucket'];
    $cache = & $GLOBALS['core_rewrite_option_caches'][$bucket];
    if (is_array($cache['alloptions'] ?? null)
        && array_key_exists($name, $cache['alloptions'])) {
        $value = $cache['alloptions'][$name];
    } elseif (is_array($cache['notoptions'] ?? null)
        && !empty($cache['notoptions'][$name])) {
        return apply_filters("default_option_$name", $default, $name, false);
    } elseif (array_key_exists($name, $cache)) {
        $value = $cache[$name];
    } elseif (!array_key_exists($name, $wpdb->optionRows)) {
        $cache['notoptions'][$name] = true;
        return apply_filters("default_option_$name", $default, $name, false);
    } else {
        $value = maybe_unserialize($wpdb->optionRows[$name]);
        $cache[$name] = $value;
    }
    return apply_filters("option_$name", $value, $name);
}

function wp_cache_delete(int|string $key, string $group = ''): bool {
    if ($group !== 'options') {
        throw new RuntimeException("unexpected core rewrite cache group: $group");
    }
    $bucket = $GLOBALS['core_rewrite_active_cache_bucket'];
    $GLOBALS['core_rewrite_cache_deletes'][] = [$bucket, (string) $key, $group];
    $present = array_key_exists((string) $key, $GLOBALS['core_rewrite_option_caches'][$bucket]);
    unset($GLOBALS['core_rewrite_option_caches'][$bucket][(string) $key]);
    return $present;
}

function sanitize_option(string $name, mixed $value): mixed {
    return apply_filters("sanitize_option_$name", $value, $name);
}

function update_option(string $name, mixed $value): bool {
    global $wpdb;
    $value = sanitize_option($name, $value);
    $old = get_option($name, null);
    $value = apply_filters("pre_update_option_$name", $value, $old, $name);
    $value = apply_filters('pre_update_option', $value, $name, $old);
    if ($old === $value) {
        return false;
    }
    do_action('update_option', $name, $old, $value);
    if ($GLOBALS['core_rewrite_drop_write'] && $name === 'rewrite_rules') {
        return false;
    }
    $wpdb->optionRows[$name] = core_rewrite_serialize($value);
    wp_cache_delete($name, 'options');
    wp_cache_delete('alloptions', 'options');
    wp_cache_delete('notoptions', 'options');
    do_action("update_option_$name", $old, $value, $name);
    do_action('updated_option', $name, $old, $value);
    return true;
}

final class CoreRewriteRuntime {
    public mixed $permalink_structure = '/target-old/%post_id%/';
    public mixed $rules = ['^target-old/([0-9]+)/?$' => 'index.php?p=$matches[1]'];
    public int $initCalls = 0;
    public int $flushCalls = 0;
    public int $hardFlushes = 0;
    /** @var array<string,array{}> */
    public array $extra_permastructs = [];

    public function init(): void {
        $this->initCalls++;
        $this->permalink_structure = get_option('permalink_structure', false);
    }

    /** @return array<string,string> */
    private function generated_rules(): array {
        $structure = $this->permalink_structure === false ? '' : (string) $this->permalink_structure;
        if ($structure === '') {
            return [];
        }
        $rules = ['^wp-json/?$' => 'index.php?rest_route=/'];
        $fingerprint = substr(hash('sha256', $structure), 0, 16);
        $rules['^portable/([^/]+)/?$'] = 'index.php?name=$matches[1]&grammar=' . $fingerprint;
        $rules['^portable/page/([0-9]+)/?$'] = 'index.php?paged=$matches[1]&grammar=' . $fingerprint;
        return $rules;
    }

    public function flush_rules(bool $hard = true): void {
        $this->flushCalls++;
        if ($hard) {
            $this->hardFlushes++;
        }
        if (!did_action('wp_loaded')) {
            return;
        }
        $generated = $this->generated_rules();
        // WP_Rewrite::refresh_rewrite_rules() sets rules='' before calling
        // rewrite_rules(); the latter returns [] early for plain permalinks
        // without assigning that return value back to the property.
        $this->rules = $generated === [] ? '' : apply_filters('rewrite_rules_array', $generated);
        update_option('rewrite_rules', $this->rules);
        if ($GLOBALS['core_rewrite_mutate_structure']) {
            $GLOBALS['wpdb']->optionRows['permalink_structure'] = '/raced/%postname%/';
        }
    }

    public function wp_rewrite_rules(): mixed {
        $this->rules = get_option('rewrite_rules');
        if (empty($this->rules)) {
            $this->flush_rules(false);
        }
        return $this->rules;
    }
}

final class WP_CLI {
    use \WPrismTest\WpCliChildRuntime;

    public static function add_command(string $name, string $class): void {}

    public static function line(string $line): void { echo $line, PHP_EOL; }

    public static function halt(int $status): never {
        throw new CoreRewriteCliHalt($status);
    }

    /** @param array<string,mixed> $args */
    public static function runcommand(string $command, array $args): object {
        global $wp_rewrite;
        if (!str_contains($command, 'NativeActions::execute("rewrite.flush", [])')
            || $args !== ['launch' => true, 'return' => 'all', 'exit_error' => false]) {
            throw new RuntimeException('unexpected rewrite child-process command');
        }
        $GLOBALS['core_rewrite_child_launches']++;
        if (($GLOBALS['core_rewrite_child_launch_failure'] ?? null) instanceof Throwable) {
            throw $GLOBALS['core_rewrite_child_launch_failure'];
        }
        $parentRuntime = $wp_rewrite;
        $parentCacheBucket = $GLOBALS['core_rewrite_active_cache_bucket'];
        $GLOBALS['core_rewrite_active_cache_bucket'] = 'child';
        $GLOBALS['core_rewrite_option_caches']['child'] = [];
        $freshRuntime = new CoreRewriteRuntime();
        $freshRuntime->permalink_structure = get_option('permalink_structure', false);
        $freshRuntime->rules = null;
        $wp_rewrite = $freshRuntime;
        try {
            $method = new ReflectionMethod(WPrism\NativeActions::class, 'flush_rewrite_in_fresh_process');
            $receipt = $method->invoke(null);
            $report = [
                'format' => 'wprism-rewrite-flush-fresh/v1',
                'after' => $receipt['after'],
            ];
            $response = (object) [
                'return_code' => 0,
                'stdout' => $GLOBALS['core_rewrite_child_stdout_prefix']
                    . json_encode($report, JSON_THROW_ON_ERROR),
                'stderr' => $GLOBALS['core_rewrite_child_stderr'],
            ];
            $mutator = $GLOBALS['core_rewrite_child_response_mutator'] ?? null;
            return $mutator === null ? $response : $mutator($response);
        } catch (Throwable $failure) {
            return (object) [
                'return_code' => 1,
                'stdout' => '',
                'stderr' => $failure->getMessage(),
            ];
        } finally {
            $GLOBALS['core_rewrite_child_flushes'] += $freshRuntime->flushCalls;
            $GLOBALS['core_rewrite_child_hard_flushes'] += $freshRuntime->hardFlushes;
            $wp_rewrite = $parentRuntime;
            $GLOBALS['core_rewrite_active_cache_bucket'] = $parentCacheBucket;
        }
    }
}

final class CoreRewriteCliHalt extends RuntimeException {
    public function __construct(public readonly int $status) {
        parent::__construct('core rewrite CLI fixture halted');
    }
}

/** Reset one dirty target after Apply has already written the source grammar. */
function core_rewrite_reset(string|false $structure = '/source/%postname%/'): void {
    global $wpdb, $wp_rewrite;
    $wpdb = new CoreRewriteFakeWpdb();
    if ($structure !== false) {
        $wpdb->optionRows['permalink_structure'] = $structure;
    }
    $wpdb->optionRows['rewrite_rules'] = serialize([
        '^target-old/([0-9]+)/?$' => 'index.php?p=$matches[1]',
    ]);
    $wp_rewrite = new CoreRewriteRuntime();
    $GLOBALS['core_rewrite_filters'] = [];
    $GLOBALS['core_rewrite_filter_calls'] = [];
    $GLOBALS['core_rewrite_action_calls'] = [];
    $GLOBALS['core_rewrite_wp_loaded'] = 1;
    $GLOBALS['core_rewrite_drop_write'] = false;
    $GLOBALS['core_rewrite_mutate_structure'] = false;
    $GLOBALS['core_rewrite_child_launches'] = 0;
    $GLOBALS['core_rewrite_child_flushes'] = 0;
    $GLOBALS['core_rewrite_child_hard_flushes'] = 0;
    $GLOBALS['core_rewrite_child_stdout_prefix'] = '';
    $GLOBALS['core_rewrite_child_stderr'] = '';
    $GLOBALS['core_rewrite_child_launch_failure'] = null;
    $GLOBALS['core_rewrite_child_response_mutator'] = null;
    $GLOBALS['core_rewrite_active_cache_bucket'] = 'parent';
    $GLOBALS['core_rewrite_option_caches'] = ['parent' => [], 'child' => []];
    $GLOBALS['core_rewrite_cache_deletes'] = [];
    $GLOBALS['wprism_wp_cli_child_fake_stderr_first'] = false;
}

/** @param callable():mixed $callback */
function core_rewrite_refuses(callable $callback, string $needle, string $message): void {
    try {
        $callback();
        wprism_check(false, "$message (expected refusal containing '$needle')");
    } catch (RuntimeException $e) {
        wprism_check(
            str_contains($e->getMessage(), $needle),
            "$message ({$e->getMessage()})"
        );
    }
}

$root = dirname(__DIR__, 4);
$runtimeRoot = isset($argv[1]) ? realpath($argv[1]) : $root;
if (!is_string($runtimeRoot) || !is_dir($runtimeRoot . '/agent/src')) {
    throw new RuntimeException('core rewrite regression needs one complete runtime tree');
}
require_once $runtimeRoot . '/agent/src/Policy/Policy.php';
require_once $runtimeRoot . '/agent/src/Rebuild/RebuildActionNegotiator.php';
require_once $runtimeRoot . '/agent/src/Kernel/PrivateRefusalEvidence.php';

$policy = WPrism\Policy::load(
    null,
    ['core'],
    adapterLibrary: WPrism\AdapterLibrary::fromSourceTree($runtimeRoot)
);
$selected = $policy->actions_for(['option:permalink_structure']);
wprism_check_same(1, count($selected), 'core selects exactly one action for the permalink surface');
wprism_check_same('rewrite.flush', $selected[0]['action'] ?? null, 'core selects the closed rewrite.flush operation');
wprism_check_same([], $selected[0]['args'] ?? null, 'rewrite.flush has no manifest-controlled payload');
wprism_check_same(
    [],
    $policy->actions_for(['option:blogname']),
    'unrelated authored options never cause a blanket rewrite flush'
);
wprism_check_same([], $policy->actions_for([]), 'a read-only apply never fires rewrite.flush');
$effects = array_column($selected[0]['effects'] ?? [], 'id');
wprism_check_same(
    [
        'core-rewrite-rules',
        'core-tec-last-generate-rewrite-rules',
        'core-tec-last-updated-option',
        'core-tec-last-save-post',
        'core-rewrite-rules-cache',
        'core-tec-last-generate-rewrite-rules-cache',
        'core-tec-last-updated-option-cache',
        'core-tec-last-save-post-cache',
        'core-tec-rewrite-listener-runtime',
        'core-permalink-pre-option-filter',
        'core-permalink-pre-option-generic-filter',
        'core-permalink-option-filter',
        'core-permalink-default-option-filter',
        'core-rewrite-rules-array-filter',
        'core-rewrite-generate-hook',
        'core-rewrite-generation-runtime',
        'core-rewrite-pre-option-filter',
        'core-rewrite-preload-filter',
        'core-rewrite-cache-preload-filter',
        'core-rewrite-alloptions-filter',
        'core-rewrite-default-option-filter',
        'core-rewrite-sanitize-filter',
        'core-rewrite-option-filter',
        'core-rewrite-pre-update-filter',
        'core-rewrite-pre-update-generic-filter',
        'core-rewrite-update-option-hook',
        'core-rewrite-autoload-values-filter',
        'core-rewrite-default-autoload-filter',
        'core-rewrite-autoload-size-filter',
        'core-rewrite-update-specific-hook',
        'core-rewrite-updated-option-hook',
        'core-rewrite-add-option-hook',
        'core-rewrite-add-specific-hook',
        'core-rewrite-added-option-hook',
    ],
    $effects,
    'core inventories the database, cache, filters, and hooks reached by the native path'
);

wprism_check_same(
    ['transient.delete', 'rewrite.flush'],
    WPrism\NativeActions::vocabulary(),
    'the native vocabulary stays closed at its two reviewed WordPress-core operations'
);
wprism_check_same([], WPrism\NativeActions::arg_schemas()['rewrite.flush'] ?? null, 'rewrite.flush publishes an empty argument schema');
WPrism\NativeActions::validate('rewrite.flush', [], 'core.actions[0]');
core_rewrite_refuses(
    static fn() => WPrism\NativeActions::validate('rewrite.flush', ['hard' => true], 'core.actions[0]'),
    'unknown key(s)',
    'a manifest cannot turn the soft flush into a filesystem-writing hard flush'
);

core_rewrite_reset();
$foreignPreflightHook = new WP_Hook();
$foreignPreflightHook->callbacks[10]['foreign'] = [
    'function' => static fn(array $rules): array => $rules,
    'accepted_args' => 1,
];
$GLOBALS['wp_filter']['rewrite_rules_array'] = $foreignPreflightHook;
$preflight = new ReflectionMethod(WPrism\RebuildActionNegotiator::class, 'preflight_rewrite_actions');
$preflightRows = $GLOBALS['wpdb']->optionRows;
core_rewrite_refuses(
    static fn() => $preflight->invoke(null, [[
        'kind' => 'native',
        'action' => 'rewrite.flush',
        'args' => [],
    ]]),
    'apply refused before target mutation',
    'batch negotiation refuses an open Polylang rewrite callback before an earlier provider can mutate'
);
wprism_check_same($preflightRows, $GLOBALS['wpdb']->optionRows, 'native batch preflight performs no durable mutation');
wprism_check_same(0, $GLOBALS['core_rewrite_child_launches'], 'native batch preflight launches no rewrite child');
$negotiatorSource = (string) file_get_contents($root . '/agent/src/Rebuild/RebuildActionNegotiator.php');
wprism_check(
    strpos($negotiatorSource, 'self::preflight_rewrite_actions($selectedActions);')
        < strpos($negotiatorSource, 'Providers::negotiate_scoped'),
    'ordinary negotiation invokes rewrite topology preflight before provider negotiation returns mutation authority'
);
unset($GLOBALS['wp_filter']['rewrite_rules_array']);

$wooPolicy = WPrism\Policy::load(null, ['core', 'woocommerce']);
foreach ([
    'option:woocommerce_brand_permalink' => 'hierarchy provider with flush_rewrite authority',
    'option:woocommerce_permalinks' => 'product-route provider',
] as $surface => $label) {
    core_rewrite_reset();
    $foreignProviderHook = new WP_Hook();
    $foreignProviderHook->callbacks[10]['foreign'] = [
        'function' => static fn(array $rules): array => $rules,
        'accepted_args' => 1,
    ];
    $GLOBALS['wp_filter']['rewrite_rules_array'] = $foreignProviderHook;
    $providerSelection = $wooPolicy->actions_for([$surface]);
    wprism_check_same(1, count($providerSelection), "$label selects one exact WooCommerce action");
    wprism_check_same('provider', $providerSelection[0]['kind'] ?? null, "$label is provider-owned");
    $providerRows = $GLOBALS['wpdb']->optionRows;
    core_rewrite_refuses(
        static fn() => $preflight->invoke(null, $providerSelection),
        'apply refused before target mutation',
        "$label refuses hostile rewrite topology before the authored option transaction"
    );
    wprism_check_same(
        $providerRows,
        $GLOBALS['wpdb']->optionRows,
        "$label preflight performs no durable mutation"
    );
    wprism_check_same(0, $GLOBALS['core_rewrite_child_launches'], "$label preflight launches no rewrite child");
    unset($GLOBALS['wp_filter']['rewrite_rules_array']);
}

core_rewrite_reset();
$foreignUnrelatedHook = new WP_Hook();
$foreignUnrelatedHook->callbacks[10]['foreign'] = [
    'function' => static fn(array $rules): array => $rules,
    'accepted_args' => 1,
];
$GLOBALS['wp_filter']['rewrite_rules_array'] = $foreignUnrelatedHook;
$preflight->invoke(null, [[
    'kind' => 'provider',
    'provider' => 'unrelated-provider',
    'capability' => 'rebuild_without_rewrite',
    'effects' => [[
        'kind' => 'database',
        'mode' => 'restorable',
        'selector' => ['scope' => 'database_checkpoint', 'type' => 'option', 'value' => 'unrelated'],
    ]],
]]);
wprism_check_same(
    [],
    $GLOBALS['wpdb']->readNames,
    'an unrelated provider action does not acquire rewrite-topology authority or perform preflight reads'
);
unset($GLOBALS['wp_filter']['rewrite_rules_array']);

core_rewrite_reset();
$foreignRewriteHook = new WP_Hook();
$foreignRewriteHook->callbacks[10]['foreign'] = [
    'function' => static fn(object $runtime): object => $runtime,
    'accepted_args' => 1,
];
$GLOBALS['wp_filter']['generate_rewrite_rules'] = $foreignRewriteHook;
$launchesBeforeForeignRewrite = $GLOBALS['core_rewrite_child_launches'];
$flushesBeforeForeignRewrite = $GLOBALS['core_rewrite_child_flushes'];
core_rewrite_refuses(
    static fn() => WPrism\NativeActions::execute('rewrite.flush', []),
    'fresh WordPress process exited 1',
    'an unknown plugin rewrite callback refuses even when TEC is absent'
);
unset($GLOBALS['wp_filter']['generate_rewrite_rules']);
wprism_check_same(
    $launchesBeforeForeignRewrite + 1,
    $GLOBALS['core_rewrite_child_launches'],
    'the TEC-absent topology refusal stays inside the bounded fresh child'
);
wprism_check_same(
    $flushesBeforeForeignRewrite,
    $GLOBALS['core_rewrite_child_flushes'],
    'the TEC-absent topology refusal executes no rewrite generation'
);
core_rewrite_reset();
$first = WPrism\NativeActions::execute('rewrite.flush', []);
wprism_check(($first['verified'] ?? null) === true, 'dirty target rewrite regeneration returns only after verified readback');
wprism_check_same('rewrite.flush', $first['action'] ?? null, 'receipt names the exact closed action');
wprism_check_same([], $first['args'] ?? null, 'receipt carries no attacker-controlled action arguments');
wprism_check_same(1, $GLOBALS['core_rewrite_child_launches'], 'the action launches exactly one fresh WordPress process');
wprism_check_same(0, $wp_rewrite->initCalls, 'the stale apply runtime is never destructively reinitialized');
wprism_check_same(1, $GLOBALS['core_rewrite_child_flushes'], 'the dirty target is flushed exactly once in the fresh process');
wprism_check_same(0, $GLOBALS['core_rewrite_child_hard_flushes'], 'rewrite.flush never writes target-owned web-server configuration');
$parentCacheDeletes = array_values(array_map(
    static fn(array $row): string => $row[1],
    array_filter(
        $GLOBALS['core_rewrite_cache_deletes'],
        static fn(array $row): bool => $row[0] === 'parent' && $row[2] === 'options'
    )
));
$nativeActionConstants = new ReflectionClass(WPrism\NativeActions::class);
$parentCacheKeys = $nativeActionConstants->getReflectionConstant('REWRITE_PARENT_CACHE_KEYS')?->getValue();
$rewriteEffectConstants = new ReflectionClass(WPrism\NativeRewriteEffects::class);
$tecMarkerOptions = $rewriteEffectConstants->getReflectionConstant('MARKER_OPTIONS')?->getValue();
wprism_check_same(
    $tecMarkerOptions,
    is_array($parentCacheKeys) ? array_slice($parentCacheKeys, 1, 3) : null,
    'the private parent handoff roster stays exact with the TEC marker options the child may write'
);
wprism_check_same(
    $parentCacheKeys,
    array_values(array_unique($parentCacheDeletes)),
    'the parent discards every child-written named and aggregate option cache before parity readback'
);
wprism_check_same('/source/%postname%/', get_option('permalink_structure'), 'the authored permalink grammar remains exact');
wprism_check(is_array(get_option('rewrite_rules')), 'stale target rules are replaced by an array-valued native projection');
wprism_check(
    !array_key_exists('^target-old/([0-9]+)/?$', get_option('rewrite_rules')),
    'the old target URL grammar no longer survives as a non-empty cache row'
);
wprism_check(
    ($first['before']['runtime_permalink_matches'] ?? null) === false
        && ($first['after']['runtime_permalink_matches'] ?? null) === true,
    'receipt proves the hostile in-process rewrite runtime converged too'
);
$publicReceipt = json_encode($first, JSON_THROW_ON_ERROR);
wprism_check(
    !str_contains($publicReceipt, '/source/%postname%/')
        && !str_contains($publicReceipt, 'target-old')
        && !str_contains($publicReceipt, 'portable'),
    'receipt publishes hashes, counts, and types without permalink or rule plaintext'
);
wprism_check(
    in_array('rewrite_rules_array', $GLOBALS['core_rewrite_filter_calls'], true)
        && in_array('sanitize_option_rewrite_rules', $GLOBALS['core_rewrite_filter_calls'], true)
        && in_array('option_rewrite_rules', $GLOBALS['core_rewrite_filter_calls'], true)
        && in_array('pre_update_option_rewrite_rules', $GLOBALS['core_rewrite_filter_calls'], true)
        && in_array('update_option_rewrite_rules', $GLOBALS['core_rewrite_action_calls'], true)
        && in_array('updated_option', $GLOBALS['core_rewrite_action_calls'], true),
    'the fake observes the exact native rewrite/update extension points inventoried by core.json'
);

$evidenceRows = $wpdb->optionRows;
$evidenceLaunches = $GLOBALS['core_rewrite_child_launches'];
$evidenceFlushes = $GLOBALS['core_rewrite_child_flushes'];
$readOnlyEvidence = WPrism\NativeActions::rewrite_evidence();
wprism_check_same($first['after'], $readOnlyEvidence, 'the public read-only accessor returns the exact validated-after projection');
wprism_check_same($evidenceRows, $wpdb->optionRows, 'read-only rewrite evidence performs no durable mutation');
wprism_check_same($evidenceLaunches, $GLOBALS['core_rewrite_child_launches'], 'read-only rewrite evidence launches no child process');
wprism_check_same($evidenceFlushes, $GLOBALS['core_rewrite_child_flushes'], 'read-only rewrite evidence generates no rewrite rules');

$stableRows = $wpdb->optionRows;
$second = WPrism\NativeActions::execute('rewrite.flush', []);
wprism_check_same($stableRows, $wpdb->optionRows, 'an immediate retry is byte-idempotent in persistent storage');
wprism_check_same($first['after'], $second['before'], 'retry begins from the exact previously verified postcondition');
wprism_check_same($first['after'], $second['after'], 'retry preserves the exact verified rewrite evidence');

foreach (['alloptions', 'notoptions'] as $staleCacheKey) {
    core_rewrite_reset();
    $oldRules = ['^target-old/([0-9]+)/?$' => 'index.php?p=$matches[1]'];
    $GLOBALS['core_rewrite_option_caches']['parent'][$staleCacheKey] = $staleCacheKey === 'alloptions'
        ? ['rewrite_rules' => $oldRules]
        : ['rewrite_rules' => true];
    $cacheBoundary = WPrism\NativeActions::execute('rewrite.flush', []);
    wprism_check(
        ($cacheBoundary['verified'] ?? null) === true
            && ($cacheBoundary['after']['rules_hash'] ?? null)
                === ($cacheBoundary['after']['runtime_rules_hash'] ?? null),
        "a stale parent $staleCacheKey entry cannot contradict the fresh child's durable/runtime evidence"
    );
}

core_rewrite_reset();
$dynamicRules = ['^sitemap_index\\.xml$' => 'index.php?sitemap=1'];
add_filter(
    'rewrite_rules_array',
    static fn(array $rules): array => $GLOBALS['core_rewrite_dynamic_rules'] + $rules
);
add_filter(
    'sanitize_option_rewrite_rules',
    static fn(array $rules): array => array_diff_key($rules, $GLOBALS['core_rewrite_dynamic_rules'])
);
add_filter(
    'option_rewrite_rules',
    static fn(array $rules): array => $GLOBALS['core_rewrite_dynamic_rules'] + $rules
);
$GLOBALS['core_rewrite_dynamic_rules'] = $dynamicRules;
$dynamic = WPrism\NativeActions::execute('rewrite.flush', []);
$dynamicStored = maybe_unserialize($wpdb->optionRows['rewrite_rules']);
$dynamicEffective = get_option('rewrite_rules');
wprism_check(
    is_array($dynamicStored)
        && !array_key_exists('^sitemap_index\\.xml$', $dynamicStored)
        && is_array($dynamicEffective)
        && ($dynamicEffective['^sitemap_index\\.xml$'] ?? null) === 'index.php?sitemap=1',
    'a plugin may intentionally sanitize dynamic routes out of durable storage and restore them on option read'
);
wprism_check(
    ($dynamic['after']['rules_hash'] ?? null) !== ($dynamic['after']['runtime_rules_hash'] ?? null)
        && ($dynamic['after']['rules_count'] ?? null) + 1 === ($dynamic['after']['runtime_rules_count'] ?? null)
        && $wp_rewrite->rules === $dynamicEffective,
    'the receipt independently verifies sanitized storage and the larger effective runtime projection'
);
$dynamicRows = $wpdb->optionRows;
$dynamicRetry = WPrism\NativeActions::execute('rewrite.flush', []);
wprism_check_same($dynamicRows, $wpdb->optionRows, 'a dynamic-route retry is byte-idempotent in persistent storage');
wprism_check_same($dynamic['after'], $dynamicRetry['after'], 'a dynamic-route retry preserves both verified projections');

foreach (['', '/archives/%post_id%/', '/東京/%category%/%postname%/', str_repeat('/segment', 512) . '/%postname%/'] as $structure) {
    core_rewrite_reset($structure);
    $receipt = WPrism\NativeActions::execute('rewrite.flush', []);
    wprism_check_same($structure, get_option('permalink_structure'), 'permalink boundary round-trips exact source bytes (length ' . strlen($structure) . ')');
    $after = $receipt['after'] ?? [];
    if ($structure === '') {
        wprism_check(
            ($after['rules_type'] ?? null) === 'string'
                && ($after['rules_count'] ?? null) === 0
                && get_option('rewrite_rules') === ''
                && ($after['rules_hash'] ?? null) === ($after['runtime_rules_hash'] ?? null),
            'plain permalinks preserve WordPress\'s exact empty-string rewrite sentinel with database/runtime hash parity'
        );
    } else {
        wprism_check(
            ($after['rules_type'] ?? null) === 'array'
                && ($after['rules_count'] ?? 0) >= 2
                && ($after['rules_hash'] ?? null) === ($after['runtime_rules_hash'] ?? null),
            'permalink boundary regenerates ordered rules with database/runtime hash parity (length ' . strlen($structure) . ')'
        );
    }
}

core_rewrite_reset(false);
$absent = WPrism\NativeActions::execute('rewrite.flush', []);
wprism_check(!array_key_exists('permalink_structure', $wpdb->optionRows), 'explicit authored option deletion stays deleted after regeneration');
wprism_check(($absent['after']['permalink_present'] ?? null) === false, 'deletion receipt distinguishes absence from an empty stored string');
wprism_check_same(false, $wp_rewrite->permalink_structure, 'parent readback reflects WordPress semantic plain-permalink state without recreating the row');
wprism_check_same('', get_option('rewrite_rules'), 'an absent permalink row still persists WordPress\'s exact empty rewrite sentinel');

core_rewrite_reset();
$GLOBALS['core_rewrite_wp_loaded'] = 0;
$before = $wpdb->optionRows;
core_rewrite_refuses(
    static fn() => WPrism\NativeActions::execute('rewrite.flush', []),
    'refused before wp_loaded',
    'an incomplete WordPress bootstrap refuses instead of scheduling an unreceipted later mutation'
);
wprism_check_same($before, $wpdb->optionRows, 'pre-wp_loaded refusal mutates no persistent state');
wprism_check_same(0, $wp_rewrite->initCalls, 'pre-wp_loaded refusal occurs before runtime reinitialization');

core_rewrite_reset();
$wpdb->failReads['permalink_structure'] = 1;
core_rewrite_refuses(
    static fn() => WPrism\NativeActions::execute('rewrite.flush', []),
    "checked option read failed for 'permalink_structure'",
    'permalink database failure is not mistaken for option absence'
);
wprism_check_same(0, $GLOBALS['core_rewrite_child_launches'], 'permalink checked-read failure refuses before launching the native flush');

core_rewrite_reset();
$wpdb->failReads['rewrite_rules'] = 1;
core_rewrite_refuses(
    static fn() => WPrism\NativeActions::execute('rewrite.flush', []),
    "checked option read failed for 'rewrite_rules'",
    'rewrite database failure is not mistaken for stale-but-repairable state'
);
wprism_check_same(0, $GLOBALS['core_rewrite_child_launches'], 'rewrite checked-read failure refuses before launching the native flush');

core_rewrite_reset();
$wpdb->optionRows['permalink_structure'] = serialize(['not' => 'a string']);
core_rewrite_refuses(
    static fn() => WPrism\NativeActions::execute('rewrite.flush', []),
    'plain string',
    'malformed structured permalink data refuses before WordPress consumes it'
);
wprism_check_same(0, $GLOBALS['core_rewrite_child_launches'], 'malformed permalink data reaches no rewrite process or mutation');

core_rewrite_reset();
add_filter(
    'pre_option_permalink_structure',
    static fn($pre): string => '/filtered/%post_id%/'
);
core_rewrite_refuses(
    static fn() => WPrism\NativeActions::execute('rewrite.flush', []),
    'fresh WordPress process loaded a permalink runtime',
    'an extension override that contradicts the checked permalink row refuses before rule regeneration'
);
wprism_check_same(0, $GLOBALS['core_rewrite_child_flushes'], 'contradictory permalink filter reaches no rewrite mutation');

core_rewrite_reset();
$wpdb->optionRows['rewrite_rules'] = 'hostile non-array residue';
$repaired = WPrism\NativeActions::execute('rewrite.flush', []);
wprism_check(
    ($repaired['before']['rules_type'] ?? null) === 'string'
        && ($repaired['after']['rules_type'] ?? null) === 'array',
    'non-array target rewrite residue is observable by type and repaired to the native shape'
);

core_rewrite_reset();
add_filter('rewrite_rules_array', static fn(array $rules): string => 'malformed filtered rules');
core_rewrite_refuses(
    static fn() => WPrism\NativeActions::execute('rewrite.flush', []),
    'did not generate a valid rewrite runtime',
    'a malformed extension rewrite projection cannot produce a verified native-action receipt'
);
wprism_check_same(
    'malformed filtered rules',
    maybe_unserialize($wpdb->optionRows['rewrite_rules']),
    'malformed generated rewrite residue remains visible for checkpoint recovery after refusal'
);

core_rewrite_reset();
$GLOBALS['core_rewrite_drop_write'] = true;
core_rewrite_refuses(
    static fn() => WPrism\NativeActions::execute('rewrite.flush', []),
    'generated rewrite rules disagree',
    'a dropped rewrite_rules write cannot produce a command-success receipt'
);
wprism_check_same(
    ['^target-old/([0-9]+)/?$' => 'index.php?p=$matches[1]'],
    maybe_unserialize($wpdb->optionRows['rewrite_rules']),
    'dropped-write refusal leaves the hostile persistent row visible for recovery'
);

core_rewrite_reset();
$GLOBALS['core_rewrite_mutate_structure'] = true;
core_rewrite_refuses(
    static fn() => WPrism\NativeActions::execute('rewrite.flush', []),
    'loaded permalink structure disagrees',
    'a concurrent permalink change during regeneration is detected as recovery-required drift'
);

core_rewrite_reset();
$savedRuntime = $wp_rewrite;
$wp_rewrite = null;
core_rewrite_refuses(
    static fn() => WPrism\NativeActions::execute('rewrite.flush', []),
    'loaded WordPress/WP-CLI rewrite runtime',
    'missing WordPress rewrite runtime refuses with an actionable boundary message'
);
$wp_rewrite = $savedRuntime;

core_rewrite_reset();
$GLOBALS['core_rewrite_child_stdout_prefix'] = str_repeat('credential-shaped-boot-output-', 12000);
$overflowMessage = '';
$overflowFailure = null;
try {
    WPrism\NativeActions::execute('rewrite.flush', []);
} catch (RuntimeException $failure) {
    $overflowMessage = $failure->getMessage();
    $overflowFailure = $failure;
}
wprism_check(
    str_contains($overflowMessage, 'could not launch its fresh WordPress process')
        && !str_contains($overflowMessage, 'credential-shaped'),
    'native rewrite refuses oversized child boot output through the product path without leaking it'
);
$overflowGraph = $overflowFailure === null ? [] : WPrism\PrivateRefusalEvidence::graph($overflowFailure);
wprism_check(in_array('wprism: bounded WP-CLI child output exceeded its fixed byte limit',
    array_column($overflowGraph['throwable'] ?? [], 'message'), true)
    && !str_contains((string) $overflowFailure, 'credential-shaped-boot-output-'),
    'oversized native output retains the bounded transport cause privately without relaxing the byte ceiling');

core_rewrite_reset();
$GLOBALS['core_rewrite_child_stderr'] = str_repeat('w', 100000);
$GLOBALS['wprism_wp_cli_child_fake_stderr_first'] = true;
$warningMessage = '';
try {
    WPrism\NativeActions::execute('rewrite.flush', []);
} catch (RuntimeException $failure) {
    $warningMessage = $failure->getMessage();
}
wprism_check(
    str_contains($warningMessage, 'emitted a warning') && !str_contains($warningMessage, str_repeat('w', 32)),
    'stderr-first output larger than a pipe drains concurrently then reaches the native warning refusal'
);
$GLOBALS['wprism_wp_cli_child_fake_stderr_first'] = false;

// Each rejected response traverses the real bounded child pipes after the
// existing native mutation model. A diagnostic refusal proves no acceptance,
// not rollback of the rewrite which that child has already persisted.
$privateCases = [
    'unknown exit' => [7, " private-rewrite-stdout\0\xff\n", " private-rewrite-stderr\0\n", 'fresh WordPress process exited 7; recovery_required'],
    'warning' => [0, " private-rewrite-stdout \n", " private-rewrite-stderr \n", 'fresh WordPress process emitted a warning; recovery_required'],
    'malformed JSON' => [0, "private-rewrite-boot\n{private-rewrite-json", '', 'fresh WordPress process returned malformed evidence; recovery_required'],
    'wrong envelope' => [0, "private-rewrite-boot\n{\"format\":\"private-rewrite-foreign\",\"after\":[]}", '', 'fresh WordPress process returned the wrong evidence envelope; recovery_required'],
    'invalid projection' => [0, "private-rewrite-boot\n{\"format\":\"wprism-rewrite-flush-fresh/v1\",\"after\":[]}", '', 'fresh WordPress process returned invalid hash/count evidence; recovery_required'],
    'bounded large streams' => [3, str_repeat('private-rewrite-output-', 600), str_repeat('private-rewrite-error-', 500), 'fresh WordPress process exited 3; recovery_required'],
];
$retainedFailure = null;
$retainedGraph = null;
foreach ($privateCases as $case => [$code, $stdout, $stderr, $sentence]) {
    core_rewrite_reset();
    $beforeRows = $wpdb->optionRows;
    $beforeRuntime = clone $wp_rewrite;
    $response = ['return_code' => $code, 'stdout' => $stdout, 'stderr' => $stderr];
    $GLOBALS['core_rewrite_child_response_mutator'] = static fn(object $_healthy): object => (object) $response;
    $failure = null;
    try {
        WPrism\NativeActions::execute('rewrite.flush', []);
    } catch (Throwable $caught) {
        $failure = $caught;
    }
    wprism_check($failure instanceof RuntimeException
        && $failure->getMessage() === "wprism: native action 'rewrite.flush' $sentence"
        && $failure->getPrevious() === null && $failure->getCode() === 0
        && !str_contains((string) $failure, 'private-rewrite-'),
        "$case retains the exact reviewed sentence without exposing private streams through ordinary rendering");
    wprism_check($wpdb->optionRows !== $beforeRows
        && $wpdb->optionRows['permalink_structure'] === $beforeRows['permalink_structure']
        && $GLOBALS['core_rewrite_child_launches'] === 1 && $GLOBALS['core_rewrite_child_flushes'] === 1
        && $GLOBALS['core_rewrite_child_hard_flushes'] === 0 && $wp_rewrite == $beforeRuntime,
        "$case is a post-mutation refusal with no retry, hard flush or fabricated parent runtime update");
    $graph = $failure === null ? [] : WPrism\PrivateRefusalEvidence::graph($failure);
    $nodes = array_column($graph['throwable'] ?? [], null, 'index');
    wprism_check(($graph['traversal']['scan_complete'] ?? null) === true
        && ($graph['traversal']['record_complete'] ?? null) === true
        && in_array('wprism: child process return_code=' . $code, array_column($nodes, 'message'), true),
        "$case retains a complete graph and actual child exit status");
    foreach (['stdout', 'stderr'] as $stream) {
        $streamNode = null;
        foreach ($nodes as $node) {
            $parent = $nodes[$node['parent_index'] ?? -1] ?? [];
            if (($node['relation'] ?? null) === 'private_evidence'
                && ($parent['message'] ?? null) === 'wprism: child process ' . $stream) {
                $streamNode = $node;
                break;
            }
        }
        $raw = $response[$stream];
        $encoded = $streamNode['message'] ?? null;
        $decoded = is_string($encoded)
            ? (($streamNode['message_encoding'] ?? null) === 'base64' ? base64_decode($encoded, true) : $encoded)
            : null;
        wprism_check($decoded === substr($raw, 0, 4096)
            && ($streamNode['message_encoding'] ?? null) === (preg_match('//u', substr($raw, 0, 4096)) === 1 ? 'utf-8' : 'base64')
            && ($streamNode['message_original_bytes'] ?? null) === strlen($raw)
            && ($streamNode['message_sha256'] ?? null) === hash('sha256', $raw)
            && ($streamNode['message_truncated'] ?? null) === (strlen($raw) > 4096),
            "$case $stream retains raw whitespace/binary bytes, original size/hash and explicit field truncation");
    }
    if ($case === 'malformed JSON') {
        wprism_check(in_array(JsonException::class, array_column($nodes, 'class'), true),
            'malformed JSON retains the real parser cause alongside rejected process evidence');
    }
    if ($case === 'unknown exit') {
        $retainedFailure = $failure;
        $retainedGraph = $graph;
    }
}

core_rewrite_reset();
$launchCause = new RuntimeException('private-rewrite-launch-cause', 71, new LogicException('private-rewrite-launch-root'));
$GLOBALS['core_rewrite_child_launch_failure'] = $launchCause;
$launchRows = $wpdb->optionRows;
$launchFailure = null;
try {
    WPrism\NativeActions::execute('rewrite.flush', []);
} catch (Throwable $failure) {
    $launchFailure = $failure;
}
$launchGraph = $launchFailure === null ? [] : WPrism\PrivateRefusalEvidence::graph($launchFailure);
wprism_check($launchFailure instanceof RuntimeException
    && $launchFailure->getMessage() === "wprism: native action 'rewrite.flush' could not launch its fresh WordPress process; recovery_required"
    && $launchFailure->getPrevious() === null && !str_contains((string) $launchFailure, 'private-rewrite-')
    && $wpdb->optionRows === $launchRows && $GLOBALS['core_rewrite_child_flushes'] === 0,
    'launch failure preserves the public boundary and pre-mutation native state');
wprism_check(in_array('private-rewrite-launch-cause', array_column($launchGraph['throwable'] ?? [], 'message'), true)
    && in_array('private-rewrite-launch-root', array_column($launchGraph['throwable'] ?? [], 'message'), true)
    && ($launchGraph['traversal']['record_complete'] ?? null) === true,
    'launch failure keeps the original nested cause privately through the shared transport facade');

// The native business-error whitelist is not transport grammar. Its exact
// RuntimeException type, order and reviewed operator sentence stay unchanged.
$knownMessage = "wprism: native action 'rewrite.flush' refused before wp_loaded; WordPress would defer the rewrite mutation beyond the verified apply boundary";
core_rewrite_reset();
$GLOBALS['core_rewrite_child_response_mutator'] = static fn(object $_healthy): object => (object) [
    'return_code' => 1,
    'stdout' => 'private-rewrite-surrounding-stdout',
    'stderr' => 'private-rewrite-prefix ' . $knownMessage . ' private-rewrite-suffix',
];
$knownFailure = null;
try {
    WPrism\NativeActions::execute('rewrite.flush', []);
} catch (Throwable $failure) {
    $knownFailure = $failure;
}
wprism_check($knownFailure !== null && get_class($knownFailure) === RuntimeException::class
    && $knownFailure->getMessage() === $knownMessage && $knownFailure->getPrevious() === null
    && !str_contains((string) $knownFailure, 'private-rewrite-'),
    'reviewed native child diagnostics keep their exact business exception and omit unreviewed surrounding output');

require_once $runtimeRoot . '/agent/src/Command/Cli.php';
$privateRepo = sys_get_temp_dir() . '/wprism-rewrite-private-' . bin2hex(random_bytes(8));
mkdir($privateRepo, 0700);
file_put_contents($privateRepo . '/site.wprism.json', "{}\n");
$renderFailure = static function (Throwable $failure) use ($privateRepo): array {
    $status = null;
    ob_start();
    try {
        (new ReflectionMethod(WPrism\Cli::class, 'halt_json_failure'))->invoke(null,
            $failure, ['repo' => $privateRepo, 'format' => 'json'], 'apply');
    } catch (CoreRewriteCliHalt $halt) {
        $status = $halt->status;
    } finally {
        $output = ob_get_clean();
    }
    return [$status, $output];
};
try {
    if ($retainedFailure instanceof Throwable) {
        $oldPublic = $renderFailure(new RuntimeException($retainedFailure->getMessage()));
        $oldFiles = glob($privateRepo . '/.wprism/refusals/*.json') ?: [];
        $newPublic = $renderFailure($retainedFailure);
        $newFiles = array_values(array_diff(glob($privateRepo . '/.wprism/refusals/*.json') ?: [], $oldFiles));
        $record = count($newFiles) === 1 ? json_decode(file_get_contents($newFiles[0]), true, 32, JSON_THROW_ON_ERROR) : [];
        wprism_check_same($oldPublic, $newPublic, 'actual CLI refusal bytes and exit status are unchanged by private rewrite diagnostics');
        wprism_check($newPublic[0] === 1 && !str_contains($newPublic[1], 'private-rewrite-')
            && ($record['command'] ?? null) === 'apply' && ($record['reason_code'] ?? null) === 'apply_failed'
            && ($record['throwable'] ?? null) === ($retainedGraph['throwable'] ?? null)
            && ($record['traversal'] ?? null) === ($retainedGraph['traversal'] ?? null)
            && count($record['throwable'] ?? []) > 1 && count($newFiles) === 1
            && (fileperms($newFiles[0]) & 0777) === 0600
            && (fileperms(dirname($newFiles[0])) & 0777) === 0700,
            'actual CLI eligibility and kernel writer retain exactly the private rewrite graph in one protected record');
    } else {
        wprism_check(false, 'private rewrite CLI fixture requires its real preceding transport refusal');
    }
} finally {
    foreach (glob($privateRepo . '/.wprism/refusals/*.json') ?: [] as $file) {
        unlink($file);
    }
    if (is_dir($privateRepo . '/.wprism/refusals')) { rmdir($privateRepo . '/.wprism/refusals'); }
    if (is_dir($privateRepo . '/.wprism')) { rmdir($privateRepo . '/.wprism'); }
    unlink($privateRepo . '/site.wprism.json');
    rmdir($privateRepo);
}

$coreConformance = (string) file_get_contents($root . '/sandbox/conformance/checks/core.sh');
wprism_check(
    str_contains(
        $coreConformance,
        "apply refused before target mutation — native action 'rewrite.flush' runtime is unsupported"
    )
        && str_contains($coreConformance, 'native rewrite found extended rewrite_rules option topology')
        && str_contains(
            $coreConformance,
            "[ \"\$(wp_conf2 option get permalink_structure)\" = '/journal/%postname%/' ]"
        )
        && str_contains(
            $coreConformance,
            'rewrite topology refusal published apply_in_progress before mutation'
        )
        && !str_contains($coreConformance, 'dropped rewrite write did not fail through the exact required action'),
    'core live evidence pins an unreviewed rewrite hook to the before-mutation topology refusal'
);

wprism_check_summary('core rewrite native action');
