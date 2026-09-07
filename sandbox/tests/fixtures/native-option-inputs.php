<?php
declare(strict_types=1);

use WPrism\ManifestProviderRuntime;
use WPrism\ProviderSdk;
use WPrism\Providers;

function native_option_expected(string $engine): array {
    return ['format' => 'wprism-native-option-inputs/v1', 'engine' => $engine,
        'values' => [['id' => 7, 'text' => "raw\0東京"], [], false, null, '', '0'],
        'cold_and_warm' => true, 'absent_and_present_null_distinct' => true,
        'refusals' => ['alternating_filter', 'catch_all', 'alloptions_filter', 'stale_cache',
            'cached_object', 'serialized_cache_object', 'physical_object', 'oversized_row', 'extra_read', 'observer_callback'],
        'object_hooks' => ['clone' => 0, 'wakeup' => 0],
        'caught_failure_rollback' => true, 'foreign_hook_preserved' => true, 'complete_options_restored' => true];
}

if (($argv[1] ?? '') === '--admit') {
    require_once __DIR__ . '/../lib/PrivateCommandOutput.php';
    $engine = $argv[3] ?? '';
    $record = json_decode(WPrismTest\PrivateCommandOutput::readObject($argv[2] ?? '',
        '/^ ?Container wprism-[a-z0-9]+-cli1-run-[a-z0-9]+ (Creating|Created) *$/D'), true, 32, JSON_THROW_ON_ERROR);
    if (!in_array($engine, ['MariaDB', 'MySQL'], true) || $record !== native_option_expected($engine)) {
        throw new RuntimeException('native option-input record does not prove the complete declared contract');
    }
    exit(0);
}

if (!defined('ABSPATH') || !class_exists(ProviderSdk::class)) {
    throw new RuntimeException('native option-input fixture requires loaded WordPress and the product SDK');
}

/** Fixture-only loader join; transaction/observer/DML behavior uses the real SDK. */
final class NativeOptionInputProbe extends ManifestProviderRuntime {
    private static ?Closure $operation = null;
    private static mixed $result = null;

    public static function run(bool $write, callable $operation): mixed {
        $runtime = new self(['source' => 'manifest', 'id' => 'native-option-input-probe', 'plugin' => 'fixture/fixture.php',
            'version' => '1.0.0', 'capabilities' => ['native_options'], 'contracts' => ['native_options' => [
                'args' => [], 'idempotent' => true, 'scope' => 'site', 'timeout_seconds' => 60,
                'reads' => $write ? [] : ['table:options'], 'writes' => $write ? ['table:options'] : [],
            ]]]);
        (new ReflectionMethod(Providers::class, 'bind_manifest_runtime_contracts'))->invoke(null, $runtime, $runtime->capabilities());
        self::$operation = $write
            ? static fn(): mixed => ProviderSdk::database_write_contract_transaction('native option fixture mutation', $operation,
                static fn() => ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN)
            : static fn(): mixed => ProviderSdk::database_read_contract_snapshot('native option fixture observation', $operation);
        try { $runtime->invoke('native_options', []); return self::$result; }
        finally { self::$operation = null; self::$result = null; }
    }

    protected function invoke_native_options(array $args): array {
        self::$result = (self::$operation)();
        return ['before' => [], 'after' => [], 'verified' => true];
    }
}

final class NativeOptionObjectProbe {
    public static int $clones = 0;
    public static int $wakeups = 0;
    public function __clone(): void { self::$clones++; }
    public function __wakeup(): void { self::$wakeups++; }
}

function native_option_require(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('native option-input proof failed: ' . $label);
}

function native_option_refuse(callable $operation, string $reason): void {
    $failure = null;
    try { $operation(); } catch (Throwable $caught) { $failure = $caught; }
    native_option_require($failure instanceof RuntimeException && str_contains($failure->getMessage(), $reason), $reason);
}

function native_option_descriptor(): array {
    return [['name' => 'wprism_native_input_fixture', 'default' => [], 'passed_default' => true, 'reads' => 1]];
}

function native_option_observe(?callable $native = null): mixed {
    return NativeOptionInputProbe::run(true, static fn() => ProviderSdk::native_option_inputs(native_option_descriptor(),
        $native ?? static fn() => get_option('wprism_native_input_fixture', []), 'native option fixture input'));
}

/** Complete physical table proof, including every unowned option, never a getter. */
function native_option_rows(): array {
    global $wpdb;
    return NativeOptionInputProbe::run(false, static fn() => ProviderSdk::physical_table_rows([
        'table' => $wpdb->options, 'columns' => ['option_id', 'option_name', 'option_value', 'autoload'],
        'identity' => ['option_id'], 'max_rows' => 4096, 'max_raw_bytes' => 8388608, 'mode' => 'digest',
    ], 'native option complete physical table'));
}

/** Explicit test-only cold-cache setup; the production witness never does this. */
function native_option_cold_cache(): void {
    foreach (['alloptions', 'notoptions', 'wprism_native_input_fixture'] as $name) wp_cache_delete($name, 'options');
}

function native_option_seed(mixed $value): void {
    global $wpdb;
    $raw = is_string($value) ? $value : serialize($value);
    NativeOptionInputProbe::run(true, static fn() => ProviderSdk::database_update($wpdb->options,
        ['option_value' => $raw], ['option_name' => 'wprism_native_input_fixture'], 'native option fixture seed', '%s', '%s'));
    native_option_cold_cache();
}

global $wpdb;
$name = 'wprism_native_input_fixture';
$present = NativeOptionInputProbe::run(false, static fn() => ProviderSdk::checked_get_results($wpdb->prepare(
    "SELECT option_name FROM `$wpdb->options` WHERE option_name = %s LIMIT 2", $name), 'native option fixture ownership'));
native_option_require($present === [], 'owned option and all collation aliases must initially be absent');
$initial = native_option_rows();
$created = false;
try {
    native_option_cold_cache();
    native_option_require(native_option_observe() === [], 'cold missing row returns the exact explicit default');
    native_option_require(native_option_observe() === [], 'warm missing row consumes the notoptions path');
    NativeOptionInputProbe::run(true, static fn() => ProviderSdk::database_insert($wpdb->options,
        ['option_name' => $name, 'option_value' => 'N;', 'autoload' => 'no'], 'native option fixture create', '%s'));
    $created = true;
    foreach (native_option_expected('MariaDB')['values'] as $value) {
        native_option_seed($value);
        $before = native_option_rows();
        native_option_require(native_option_observe() === $value, 'cold native value matches independent physical expectation');
        native_option_require(native_option_observe() === $value, 'warm native value matches independent physical expectation');
        native_option_require(native_option_rows() === $before, 'native getter leaves every option byte unchanged');
    }
    native_option_seed(['id' => 7]);
    $reads = 0;
    $alternating = static function ($value) use (&$reads) { return ++$reads === 2 ? ['id' => 99] : $value; };
    add_filter('option_' . $name, $alternating);
    try {
        $first = get_option($name, []);
        $middle = get_option($name, []);
        native_option_require($first === get_option($name, []) && $middle === ['id' => 99] && $first === ['id' => 7],
            'actual WordPress reproduces the bracketed-getter false oracle');
        $called = false;
        native_option_refuse(static fn() => native_option_observe(static function () use (&$called) { $called = true; }), 'pre-existing');
        native_option_require(!$called, 'alternating hook refusal precedes the native consumer');
    } finally { remove_filter('option_' . $name, $alternating); }
    foreach (['all', 'alloptions'] as $hook) {
        $callback = static fn($value) => $value;
        add_filter($hook, $callback);
        try { native_option_refuse(static fn() => native_option_observe(), 'pre-existing'); }
        finally { remove_filter($hook, $callback); }
    }
    foreach ([serialize(['id' => 99]), new NativeOptionObjectProbe(), serialize(new NativeOptionObjectProbe())] as $cached) {
        native_option_cold_cache();
        $cache = $GLOBALS['wp_object_cache']->__get('cache');
        $cache['options'][$name] = $cached;
        $GLOBALS['wp_object_cache']->__set('cache', $cache);
        native_option_refuse(static fn() => native_option_observe(), 'cache bytes disagree');
        native_option_require(NativeOptionObjectProbe::$clones === 0 && NativeOptionObjectProbe::$wakeups === 0,
            'raw cache admission never clones or wakes an object');
    }
    native_option_seed(serialize(new NativeOptionObjectProbe()));
    native_option_refuse(static fn() => native_option_observe(), 'witness failed');
    native_option_require(NativeOptionObjectProbe::$wakeups === 0, 'physical object admission is non-instantiating');
    // Existing oversized bytes are fixture setup, not a request to widen the
    // SDK's independently bounded SQL transport for a multi-megabyte literal.
    native_option_require($wpdb->query($wpdb->prepare("UPDATE `$wpdb->options` SET option_value = REPEAT('x', 1048577)
        WHERE option_name = %s", $name)) === 1, 'owned oversized native input setup');
    native_option_cold_cache();
    native_option_refuse(static fn() => native_option_observe(), 'witness failed');
    native_option_seed(['id' => 7]);
    native_option_refuse(static fn() => native_option_observe(static function () use ($name) {
        get_option($name, []); return get_option($name, []);
    }), 'read changed');
    native_option_refuse(static fn() => NativeOptionInputProbe::run(false, static fn() => ProviderSdk::native_option_inputs(
        native_option_descriptor(), static fn() => get_option($name, []), 'native observer refusal')), 'authorized mutation callback');
    $before = native_option_rows();
    native_option_refuse(static fn() => NativeOptionInputProbe::run(true, static function () use ($wpdb, $name): void {
        ProviderSdk::database_update($wpdb->options, ['option_value' => 'partial write'], ['option_name' => $name], 'native partial write');
        try { ProviderSdk::native_option_inputs(native_option_descriptor(), static fn() => null, 'native caught input failure'); }
        catch (Throwable) { /* The transaction must remain poisoned despite a swallowed failure. */ }
    }), 'poisoned');
    native_option_require(native_option_rows() === $before, 'swallowed native failure rolls back the complete option table');
    $foreign = static fn($value) => $value;
    try {
        native_option_refuse(static fn() => native_option_observe(static function () use ($name, $foreign): void {
            get_option($name, []); add_filter('option_' . $name, $foreign);
        }), 'cleanup found changed hook topology');
        native_option_require(has_filter('option_' . $name, $foreign) === 10
            && count($GLOBALS['wp_filter']['option_' . $name]->callbacks) === 1,
            'cleanup retains exactly the newly added foreign callback');
    } finally { remove_filter('option_' . $name, $foreign); }
} finally {
    if ($created) NativeOptionInputProbe::run(true, static fn() => ProviderSdk::database_delete($wpdb->options,
        ['option_name' => $name], 'native option fixture cleanup', '%s'));
    native_option_cold_cache();
}
native_option_require(native_option_rows() === $initial, 'complete initial option table is restored after fixture cleanup');
$engine = WPrism\PlatformCompatibility::current_facts()['database']['engine'];
echo json_encode(native_option_expected($engine), JSON_THROW_ON_ERROR), "\n";
