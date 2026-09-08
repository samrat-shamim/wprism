<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/native_option_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once dirname(__DIR__, 4) . '/agent/src/Adapter/ProviderDatabaseSession.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/NativeOptionInputs.php';

use WPrism\DatabaseQueryIsolation;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\NativeOptionInputs;
use WPrism\ProviderDatabaseSession;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

final class NativeInputObjectProbe {
    public static int $clones = 0;
    public static int $wakeups = 0;
    public function __clone(): void { self::$clones++; }
    public function __wakeup(): void { self::$wakeups++; }
}

function native_input_fixture(array $values = ['widget_fixture' => ['id' => 7]]): FakeWpdb {
    Db::forget_transaction_tracking();
    WpStore::reset();
    $GLOBALS['wp_filter'] = [];
    $GLOBALS['wp_object_cache'] = new WP_Object_Cache();
    $GLOBALS['native_option_external_cache'] = false;
    $GLOBALS['native_option_installing'] = false;
    NativeInputObjectProbe::$clones = NativeInputObjectProbe::$wakeups = 0;
    $rows = [];
    foreach ($values as $name => $value) {
        $rows[] = ['option_id' => count($rows) + 1, 'option_name' => $name,
            'option_value' => is_string($value) ? $value : serialize($value), 'autoload' => 'yes'];
    }
    return FakeWpdb::install()->enableInformationSchema()->seedTable('wp_options', $rows)
        ->setTableEngine('wp_options', 'InnoDB');
}

function native_input_descriptor(array $changes = []): array {
    return array_replace(['name' => 'widget_fixture', 'default' => [], 'passed_default' => true, 'reads' => 1], $changes);
}

function native_input_scope(?callable $native = null, ?array $inputs = null, array $tables = ['wp_options']): mixed {
    return ProviderDatabaseSession::read_only_snapshot('native input fixture', NativeDatabaseProfile::read_only($tables),
        static fn(): mixed => NativeOptionInputs::observe($inputs ?? [native_input_descriptor()],
            $native ?? static fn(): mixed => get_option('widget_fixture', []), 'native input fixture'));
}

function native_input_refuses(callable $native, string $label, ?array $inputs = null, string $message = ''): void {
    global $wpdb;
    $before = $wpdb->rows('wp_options');
    wprism_check_throws(static fn() => native_input_scope($native, $inputs), RuntimeException::class, $label, $message);
    wprism_check_same($before, $wpdb->rows('wp_options'), $label . ': every physical option byte survives');
    wprism_check(!DatabaseQueryIsolation::is_active(), $label . ': the database owner settles the scope');
}

// Reproduce why bracketed getters cannot be a native-input oracle.
native_input_fixture();
$reads = 0;
add_filter('option_widget_fixture', static function ($value) use (&$reads) { return ++$reads === 2 ? ['id' => 99] : $value; });
$before = get_option('widget_fixture', []);
$consumed = get_option('widget_fixture', []);
$after = get_option('widget_fixture', []);
wprism_check_same($before, $after, 'before/after getters can agree despite a different intermediate native input');
wprism_check_same(['id' => 99], $consumed, 'the counterexample really supplies the alternating value to native code');
$called = false;
native_input_refuses(static function () use (&$called) { $called = true; return get_option('widget_fixture', []); },
    'the witness refuses the alternating hook before native invocation', message: 'pre-existing');
wprism_check(!$called, 'the alternating consumer was never called');

foreach ([['id' => 7], [], false, null, '', '0', "raw\0東京"] as $plain) {
    native_input_fixture(['widget_fixture' => $plain]);
    wprism_check_same($plain, native_input_scope(), 'cold native input preserves present scalar/null/plain value semantics');
    wprism_check_same($plain, native_input_scope(), 'warm native input consumes the same exact witnessed bytes');
    wprism_check_same([], $GLOBALS['wp_filter'], 'successful scopes leave no engine hooks installed');
}
native_input_fixture([]);
wprism_check_same([], native_input_scope(), 'absent physical row follows the declared explicit default path');
wprism_check_same(['widget_fixture' => true], wprism_wp_store()->cache['options']['notoptions'],
    'native missing-row reads honestly warm request-local notoptions');
wprism_check_same([], native_input_scope(), 'warm notoptions still proves absent/default rather than present empty');
native_input_fixture([]);
wprism_check_same(false, native_input_scope(static fn() => get_option('widget_fixture'),
    [native_input_descriptor(['default' => false, 'passed_default' => false])]), 'an omitted false default is independently declared');
native_input_fixture([]);
native_input_refuses(static fn() => get_option('widget_fixture', false), 'explicit false cannot stand in for an omitted default',
    [native_input_descriptor(['default' => false, 'passed_default' => false])], 'presence/default');

foreach (['pre_option', 'pre_option_widget_fixture', 'option_widget_fixture', 'default_option_widget_fixture',
    'pre_wp_load_alloptions', 'pre_cache_alloptions', 'alloptions', 'wp_autoload_values_to_autoload', 'all'] as $hook) {
    native_input_fixture();
    add_filter($hook, static fn($value) => $value);
    $original = $GLOBALS['wp_filter'][$hook];
    $called = false;
    native_input_refuses(static function () use (&$called) { $called = true; }, 'existing protected hook refuses: ' . $hook);
    wprism_check(!$called && $GLOBALS['wp_filter'][$hook] === $original, 'refusal preserves the existing hook object without invoking native');
}
native_input_fixture();
add_filter('unrelated_fixture_hook', static fn($value) => $value);
$unrelated = $GLOBALS['wp_filter']['unrelated_fixture_hook'];
wprism_check_same(['id' => 7], native_input_scope(), 'unrelated native hooks do not expand the refusal frontier');
wprism_check_same(['unrelated_fixture_hook' => $unrelated], $GLOBALS['wp_filter'], 'unrelated hook identity survives successful cleanup');

foreach ([
    ['alloptions' => ['widget_fixture' => serialize(['id' => 99])]],
    ['widget_fixture' => serialize(['id' => 99])],
    ['notoptions' => ['widget_fixture' => true]],
    ['alloptions' => new NativeInputObjectProbe()],
    ['notoptions' => new NativeInputObjectProbe()],
    ['alloptions' => null],
    ['notoptions' => null],
    ['widget_fixture' => new NativeInputObjectProbe()],
    ['alloptions' => ['widget_fixture' => serialize(new NativeInputObjectProbe())]],
    ['widget_fixture' => serialize(new NativeInputObjectProbe())],
    ['alloptions' => ['widget_fixture' => ['id' => 7]]],
] as $cache) {
    native_input_fixture();
    wprism_wp_store()->cache['options'] = $cache;
    $called = false;
    native_input_refuses(static function () use (&$called) { $called = true; return get_option('widget_fixture', []); }, 'stale/non-plain cache preflight refuses');
    wprism_check(!$called && NativeInputObjectProbe::$clones === 0 && NativeInputObjectProbe::$wakeups === 0,
        'cache admission neither calls a cloning getter nor deserializes target objects');
    wprism_check_same([], wprism_wp_store()->cacheEvents, 'cache preflight performs no get/set/flush repair');
    wprism_check_same($cache, wprism_wp_store()->cache['options'], 'refusal leaves stale cache bytes as found');
}
native_input_fixture(['widget_fixture' => serialize(new NativeInputObjectProbe())]);
native_input_refuses(static fn() => get_option('widget_fixture', []), 'physical serialized objects refuse before native', message: 'witness failed');
wprism_check_same(0, NativeInputObjectProbe::$wakeups, 'physical admission never constructs the serialized object');

foreach ([
    static fn() => null,
    static fn() => get_option('undeclared_fixture', []),
    static fn() => get_option('widget_fixture', false),
    static function () { get_option('widget_fixture', []); return get_option('widget_fixture', []); },
    static fn() => apply_filters('option_widget_fixture', ['id' => 7], 'widget_fixture'),
    static function () { apply_filters('pre_option', false, 'widget_fixture', []); return null; },
    static function () {
        apply_filters('pre_option', false, 'widget_fixture', []);
        return apply_filters('option_widget_fixture', ['id' => 7], 'widget_fixture');
    },
] as $native) {
    native_input_fixture();
    native_input_refuses($native, 'missing, extra, renamed, default-changed or unpaired native reads refuse');
    wprism_check_same([], $GLOBALS['wp_filter'], 'read-shape refusal removes all owned observers');
}
native_input_fixture();
wprism_check_same([['id' => 7], ['id' => 7]], native_input_scope(
    static fn() => [get_option('widget_fixture', []), get_option('widget_fixture', [])],
    [native_input_descriptor(['reads' => 2])]), 'exact repeated native reads are admitted when declared');

native_input_fixture();
$syntheticPreReturned = false;
add_filter('pre_option_unselected_fixture', static function ($value) use (&$syntheticPreReturned) {
    apply_filters('pre_option', false, 'widget_fixture', []);
    $syntheticPreReturned = true;
    return apply_filters('option_widget_fixture', ['id' => 7], 'widget_fixture');
});
native_input_refuses(static fn() => get_option('unselected_fixture', []),
    'a forged inner filter pair cannot borrow an authentic outer getter frame', message: 'not called directly');
wprism_check(!$syntheticPreReturned, 'the synthetic pre-filter refuses before a paired terminal can be forged');

foreach ([[], [native_input_descriptor(['name' => 'home'])], [native_input_descriptor(['reads' => 0])],
    [native_input_descriptor(['reads' => 33])], [native_input_descriptor(), native_input_descriptor()],
    [native_input_descriptor(['default' => new stdClass()])], [native_input_descriptor(['unknown' => true])],
    [native_input_descriptor(['passed_default' => false])]] as $inputs) {
    native_input_fixture();
    native_input_refuses(static fn() => get_option('widget_fixture', []), 'malformed/unsupported input descriptor refuses', $inputs);
}
foreach (['native_option_external_cache', 'native_option_installing'] as $flag) {
    native_input_fixture();
    $GLOBALS[$flag] = true;
    native_input_refuses(static fn() => get_option('widget_fixture', []), 'unsupported native cache/environment premise refuses');
}
native_input_fixture();
$GLOBALS['wp_object_cache'] = new class extends WP_Object_Cache {};
native_input_refuses(static fn() => get_option('widget_fixture', []), 'a custom cache subclass is not standard core');
native_input_fixture();
native_input_refuses(static function () { $GLOBALS['wp_object_cache'] = new WP_Object_Cache(); return get_option('widget_fixture', []); },
    'cache instance drift during native execution refuses');

native_input_fixture();
unset($GLOBALS['native_option_external_cache']);
$nullFlagResult = null;
try { $nullFlagResult = ['value' => native_input_scope()]; }
catch (Throwable $failure) { $nullFlagResult = ['failure' => get_class($failure)]; }
wprism_check_same(['value' => ['id' => 7]], $nullFlagResult,
    'core uninitialized external-cache flag is null while standard request-local cache remains admissible');
foreach ([0, '', '0', [], 'false'] as $badFlag) {
    native_input_fixture();
    $GLOBALS['native_option_external_cache'] = $badFlag;
    native_input_refuses(static fn() => get_option('widget_fixture', []), 'noncanonical falsy cache flags do not acquire core-cache authority');
}

native_input_fixture();
$foreign = static fn($value) => $value;
native_input_refuses(static function () use ($foreign) {
    get_option('widget_fixture', []);
    add_filter('option_widget_fixture', $foreign);
}, 'foreign observer-topology drift refuses without overwriting its hook');
wprism_check_same(1, count(wprism_wp_store()->hooks['option_widget_fixture']), 'cleanup removes the engine observer but retains the foreign callback');
wprism_check_same($foreign, wprism_wp_store()->hooks['option_widget_fixture'][0]['callback'], 'the remaining callback is exactly the foreign identity');

native_input_fixture();
$GLOBALS['wp_object_cache']->multisite = true;
$GLOBALS['wp_object_cache']->blog_prefix = '7:';
wprism_wp_store()->cache['options']['7:alloptions'] = ['widget_fixture' => serialize(['id' => 7])];
wprism_wp_store()->cache['options']['alloptions'] = ['widget_fixture' => serialize(['id' => 99])];
wprism_check_same(['id' => 7], native_input_scope(), 'multisite core routing selects the prefixed cache, not an unrelated unprefixed value');
$GLOBALS['wp_object_cache']->global_groups = ['options' => true];
native_input_refuses(static fn() => get_option('widget_fixture', []), 'global option groups use the actual unprefixed cache');
native_input_fixture();
wprism_check_throws(static fn() => NativeOptionInputs::observe([native_input_descriptor()], static fn() => null, 'outside snapshot'),
    RuntimeException::class, 'a native callback cannot mint database snapshot authority');
wprism_check_throws(static fn() => native_input_scope(tables: []), RuntimeException::class,
    'a native-input declaration cannot expand a narrower database profile', 'escaped the tables');

native_input_fixture();
$currentInput = ProviderDatabaseSession::repeatable_read_write('current transactional input fixture',
    new NativeDatabaseProfile([], ['wp_options']), static function (): mixed {
        Db::update('wp_options', ['option_value' => serialize(['id' => 99])], ['option_name' => 'widget_fixture'],
            null, null, 'fixture prior write');
        return NativeOptionInputs::observe([native_input_descriptor()], static fn() => get_option('widget_fixture', []), 'current input');
    }, static fn() => ProviderDatabaseSession::POSTIMAGE_UNKNOWN);
wprism_check_same(['id' => 99], $currentInput,
    'input witnessing observes current transaction rows and does not certify absence of earlier writes');

// A caught witness failure cannot become a committed partial native mutation.
foreach ([false, true] as $swallow) {
    $wpdb = native_input_fixture();
    $rows = $wpdb->rows('wp_options');
    wprism_check_throws(static fn() => ProviderDatabaseSession::repeatable_read_write(
        'native input rollback fixture', new NativeDatabaseProfile([], ['wp_options']),
        static function () use ($swallow): void {
            Db::update('wp_options', ['option_value' => 'prior partial mutation'], ['option_name' => 'widget_fixture'],
                null, null, 'fixture partial typed write');
            try { NativeOptionInputs::observe([native_input_descriptor()], static fn() => null, 'fixture missing native read'); }
            catch (Throwable $failure) { if (!$swallow) throw $failure; }
        }, static fn() => ProviderDatabaseSession::POSTIMAGE_UNKNOWN), RuntimeException::class,
        'native input refusal rolls back a prior typed write even when swallowed');
    wprism_check_same($rows, $wpdb->rows('wp_options'), 'poisoned scope cannot commit partial native data');
    wprism_check_same([], $GLOBALS['wp_filter'], 'rollback restores both database and option hook topology');
}
native_input_fixture();
native_input_refuses(static fn() => NativeOptionInputs::observe([native_input_descriptor()], static fn() => null, 'nested scope'),
    'nested native option scopes refuse and remove only their own outer observers', message: 'cannot nest');
native_input_fixture();
$called = false;
$wpdb->returnNextGetResultsAs([['option_name' => 'widget_fixture', 'option_value_bytes' => '1048577',
    'option_value_sha256' => hash('sha256', 'small')]], 'option_value_bytes');
native_input_refuses(static function () use (&$called) { $called = true; return get_option('widget_fixture', []); },
    'selected input exceeds the native one-MiB frontier before callback or payload read');
wprism_check(!$called && count(array_filter($wpdb->queries(), static fn($sql) => str_contains($sql, 'SELECT option_name, option_value FROM'))) === 0,
    'native option byte preflight does not allocate the oversized selected payload');

$scratch = dirname(__DIR__, 3) . '/tmp/native-option-reader-' . bin2hex(random_bytes(6));
mkdir($scratch, 0700, true);
$stem = $scratch . '/native';
$record = ['format' => 'wprism-native-option-inputs/v1', 'engine' => 'MariaDB',
    'values' => [['id' => 7, 'text' => "raw\0東京"], [], false, null, '', '0'],
    'cold_and_warm' => true, 'absent_and_present_null_distinct' => true,
    'refusals' => ['alternating_filter', 'catch_all', 'alloptions_filter', 'stale_cache',
        'cached_object', 'serialized_cache_object', 'physical_object', 'oversized_row', 'extra_read', 'synthetic_pair', 'observer_callback'],
    'object_hooks' => ['clone' => 0, 'wakeup' => 0], 'caught_failure_rollback' => true,
    'foreign_hook_preserved' => true, 'current_transaction_inputs' => true, 'complete_option_rows_restored' => true];
$admit = static function (array $data, string $stderr = '', string $exit = '0') use ($stem): int {
    foreach (['stdout' => json_encode($data, JSON_THROW_ON_ERROR) . "\n", 'stderr' => $stderr, 'exit' => $exit . "\n"] as $suffix => $bytes) {
        file_put_contents($stem . '.' . $suffix, $bytes);
        chmod($stem . '.' . $suffix, 0600);
    }
    $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/native-option-inputs.php', '--admit', $stem, 'MariaDB'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return proc_close($process);
};
try {
    wprism_check_same(0, $admit($record), 'native raw-record admission accepts the independently specified complete success object');
    foreach ([array_replace($record, ['engine' => 'MySQL']), array_replace($record, ['values' => []]),
        array_replace($record, ['refusals' => []]), array_replace($record, ['object_hooks' => ['clone' => 1, 'wakeup' => 0]]),
        array_replace($record, ['complete_option_rows_restored' => false]), array_replace($record, ['unknown' => true])] as $bad) {
        wprism_check($admit($bad) !== 0, 'native admission refuses altered or incomplete mechanism evidence');
    }
    wprism_check($admit($record, "PHP Warning: fixture warning\n") !== 0, 'native evidence cannot hide a warning behind a passing JSON record');
    wprism_check($admit($record, '', '1') !== 0, 'native evidence cannot hide a nonzero command exit behind a passing JSON record');
} finally {
    foreach (['stdout', 'stderr', 'exit'] as $suffix) unlink($stem . '.' . $suffix);
    rmdir($scratch);
}

native_input_fixture();
define('WP_SETUP_CONFIG', false);
native_input_refuses(static fn() => get_option('widget_fixture', []), 'even false WP_SETUP_CONFIG bypasses the native terminal path');
wprism_check_summary('regress_native_option_inputs');
