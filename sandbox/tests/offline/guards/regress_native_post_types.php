<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/native_post_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once dirname(__DIR__, 4) . '/agent/src/Adapter/ProviderDatabaseSession.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/NativePostTypes.php';

use WPrism\DatabaseQueryIsolation;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\NativePostTypes;
use WPrism\ProviderDatabaseSession;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

final class NativePostCloneProbe {
    public static int $clones = 0;
    public function __clone(): void { self::$clones++; }
}

function native_post_row(array $changes = []): array {
    return array_replace([
        'ID' => 7, 'post_author' => 1, 'post_date' => '2026-09-08 00:00:00',
        'post_date_gmt' => '2026-09-08 00:00:00', 'post_content' => 'private fixture',
        'post_title' => '東京', 'post_excerpt' => '', 'post_status' => 'publish',
        'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => '',
        'post_name' => 'fixture', 'to_ping' => '', 'pinged' => '',
        'post_modified' => '2026-09-08 00:00:00', 'post_modified_gmt' => '2026-09-08 00:00:00',
        'post_content_filtered' => '', 'post_parent' => 0, 'guid' => 'https://fixture.invalid/?p=7',
        'menu_order' => 0, 'post_type' => 'fixture_form', 'post_mime_type' => '', 'comment_count' => '0',
    ], $changes);
}

function native_post_fixture(?array $rows = null): FakeWpdb {
    Db::forget_transaction_tracking();
    WpStore::reset();
    $GLOBALS['wp_filter'] = [];
    $GLOBALS['wp_object_cache'] = new WP_Object_Cache();
    $GLOBALS['native_option_external_cache'] = false;
    $GLOBALS['native_option_installing'] = false;
    unset($GLOBALS['post']);
    NativePostCloneProbe::$clones = 0;
    return FakeWpdb::install()->enableInformationSchema()
        ->seedTable('wp_posts', $rows ?? [native_post_row()])
        ->setColumns('wp_posts', array_fill_keys(array_keys(native_post_row()), 'text'))
        ->setTableEngine('wp_posts', 'InnoDB');
}

function native_post_scope(array $ids = [7], array $tables = ['wp_posts']): array {
    return ProviderDatabaseSession::read_only_snapshot('native post fixture', NativeDatabaseProfile::read_only($tables),
        static fn(): array => NativePostTypes::read($ids, 'native post fixture'));
}

function native_post_refuses(string $label, array $ids = [7], string $message = ''): void {
    global $wpdb;
    $before = $wpdb->rows('wp_posts');
    wprism_check_throws(static fn() => native_post_scope($ids), RuntimeException::class, $label, $message);
    wprism_check_same($before, $wpdb->rows('wp_posts'), $label . ': physical rows survive');
    wprism_check(!DatabaseQueryIsolation::is_active(), $label . ': the owner settles isolation');
}

function native_post_payload_queries(FakeWpdb $db): array {
    return array_values(array_filter($db->queries(), static fn(string $sql): bool => str_contains($sql, 'SELECT * FROM wp_posts')));
}

$db = native_post_fixture([native_post_row(), native_post_row(['ID' => 19, 'post_type' => 'page'])]);
wprism_check_same(['page', false, 'fixture_form'], native_post_scope([19, 8, 7]), 'cold native post types preserve caller order and exact absence');
wprism_check_same(3, count(native_post_payload_queries($db)), 'each cold ID uses the admitted core SELECT * path');
$db->resetLog();
wprism_check_same(['page', false, 'fixture_form'], native_post_scope([19, 8, 7]), 'warm native post types have the same physical meaning');
wprism_check_same(1, count(native_post_payload_queries($db)), 'only absence is queried again; core has no negative post cache');
wprism_check_same([], $GLOBALS['wp_filter'], 'successful post admission leaves no engine hooks');

foreach (['stdClass', 'WP_Post'] as $class) {
    $db = native_post_fixture();
    $row = (object) array_replace(native_post_row(), ['filter' => 'raw']);
    wprism_wp_store()->cache['posts'][7] = $class === 'WP_Post' ? new WP_Post($row) : $row;
    wprism_check_same(['fixture_form'], native_post_scope(), 'exact warm raw cache class is admitted: ' . $class);
    wprism_check_same([], native_post_payload_queries($db), 'admitted warm cache does not force a database repair');
}

foreach ([[], [0], [-1], ['7'], [7, 7], [true], [1 => 7], range(1, 129)] as $ids) {
    $db = native_post_fixture();
    $GLOBALS['post'] = new WP_Post((object) array_replace(native_post_row(), ['filter' => 'raw']));
    native_post_refuses('malformed ID list cannot use native global-post fallback', $ids);
    wprism_check_same([], native_post_payload_queries($db), 'malformed ID list cannot reach native SELECT *');
}

foreach ([new NativePostCloneProbe(), false, [], null,
    (object) ['ID' => 8, 'post_type' => 'fixture_form', 'filter' => 'raw'],
    (object) ['ID' => 7, 'post_type' => 'page', 'filter' => 'raw'],
    (object) ['ID' => 7, 'post_type' => 'fixture_form', 'filter' => 'display'],
    (object) ['ID' => 7, 'post_type' => 'fixture_form'],
] as $cached) {
    $db = native_post_fixture();
    wprism_wp_store()->cache['posts'][7] = $cached;
    native_post_refuses('unsafe or stale selected post cache refuses before cloning');
    wprism_check_same(0, NativePostCloneProbe::$clones, 'refusal never invokes the hostile clone');
    wprism_check_same([], wprism_wp_store()->cacheEvents, 'cache admission does not call native get or repair');
    wprism_check_same($cached, wprism_wp_store()->cache['posts'][7], 'stale cache is left exactly as found');
    wprism_check_same([], native_post_payload_queries($db), 'stale cache cannot reach native SELECT *');
}
native_post_fixture([]);
wprism_wp_store()->cache['posts'][7] = (object) ['ID' => 7, 'post_type' => 'fixture_form', 'filter' => 'raw'];
native_post_refuses('a cached ghost cannot substitute for physical absence');

foreach ([['post_content' => str_repeat('x', 1048577)], ['extra' => 'unadmitted'],
    ['post_content' => new NativePostCloneProbe()], ['post_title' => ['not scalar']]] as $changes) {
    $db = native_post_fixture();
    wprism_wp_store()->cache['posts'][7] = (object) array_replace(native_post_row(), ['filter' => 'raw'], $changes);
    native_post_refuses('an exact inert class cannot smuggle unbounded or nonplain fields');
    wprism_check_same([], wprism_wp_store()->cacheEvents, 'full cached-field preflight precedes the cloning native getter');
}
native_post_fixture();
wprism_wp_store()->cache['posts'][7] = (object) array_replace(native_post_row(), ['filter' => 'raw', 'post_title' => 'stale but unconsumed']);
wprism_check_same(['fixture_form'], native_post_scope(), 'bounded unconsumed cache values do not become a full-row semantic claim');

foreach (['post_content', 'post_content_filtered', 'guid'] as $column) {
    $db = native_post_fixture([native_post_row([$column => str_repeat('s', 1048577)])]);
    native_post_refuses('unselected native field exceeds the allocation frontier: ' . $column, message: 'allocation frontier');
    wprism_check_same([], native_post_payload_queries($db), 'oversized unselected field is refused before native SELECT *');
}
$rows = [];
for ($id = 1; $id <= 9; $id++) $rows[] = native_post_row(['ID' => $id, 'post_content' => str_repeat('s', 1048576)]);
$db = native_post_fixture($rows);
native_post_refuses('total native field bytes are bounded before payload allocation', range(1, 9), 'allocation frontier');
wprism_check_same([], native_post_payload_queries($db), 'aggregate overflow never reaches native SELECT *');
unset($rows);
$db = native_post_fixture([native_post_row(['extension_payload' => 'unadmitted'])]);
native_post_refuses('extra physical columns cannot escape SELECT * admission', message: 'column roster');
wprism_check_same([], native_post_payload_queries($db), 'nonstandard schema refuses before native SELECT *');
$row = native_post_row();
unset($row['guid']);
$db = native_post_fixture([$row])->setColumns('wp_posts', array_fill_keys(array_keys($row), 'text'));
native_post_refuses('missing physical columns are a different native schema', message: 'column roster');

native_post_fixture();
$GLOBALS['wp_object_cache']->multisite = true;
$GLOBALS['wp_object_cache']->blog_prefix = '2:';
wprism_wp_store()->cache['posts']['1:7'] = new NativePostCloneProbe();
wprism_check_same(['fixture_form'], native_post_scope(), 'multisite selects only the current numeric blog prefix');
wprism_check_same(0, NativePostCloneProbe::$clones, 'a foreign blog cache entry is not inspected through a cloning getter');
native_post_fixture();
$GLOBALS['wp_object_cache']->multisite = true;
$GLOBALS['wp_object_cache']->blog_prefix = '2:';
$GLOBALS['wp_object_cache']->global_groups = ['posts' => true];
wprism_check_same(['fixture_form'], native_post_scope(), 'global posts routing uses the unprefixed cache key');
wprism_check(isset(wprism_wp_store()->cache['posts'][7]), 'global-group native cache population matches admitted routing');
foreach (['native_option_external_cache', 'native_option_installing'] as $flag) {
    native_post_fixture();
    $GLOBALS[$flag] = true;
    native_post_refuses('nonstandard native environment refuses');
}
native_post_fixture();
$GLOBALS['wp_object_cache'] = new class extends WP_Object_Cache {};
native_post_refuses('a cache subclass is not native core');
native_post_fixture();
add_filter('all', static fn($value) => $value);
native_post_refuses('a saved all hook cannot be hidden by the isolation gate', message: 'catch-all');

$columnSql = "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() "
    . "AND BINARY TABLE_NAME = BINARY 'wp_posts' ORDER BY ORDINAL_POSITION LIMIT 24";
$schemaRead = static fn(string $sql, NativeDatabaseProfile $profile): array => ProviderDatabaseSession::read_only_snapshot(
    'bounded column roster authority', $profile, static fn(): array => $GLOBALS['wpdb']->get_results($sql, ARRAY_A));
native_post_fixture();
wprism_check_same(array_map(static fn(string $column): array => ['COLUMN_NAME' => $column], array_keys(native_post_row())),
    $schemaRead($columnSql, NativeDatabaseProfile::read_only(['wp_posts'])), 'exact bounded column roster borrows only existing readable-table authority');
foreach ([str_replace("'wp_posts'", "'wp_options'", $columnSql),
    str_replace('LIMIT 24', 'LIMIT 129', $columnSql), str_replace(' LIMIT 24', '', $columnSql),
    str_replace('SELECT COLUMN_NAME', 'SELECT COLUMN_DEFAULT', $columnSql),
    str_replace('DATABASE()', "'foreign_database'", $columnSql),
    $columnSql . ' UNION SELECT user_pass FROM wp_users',
    str_replace("'wp_posts'", "'wp_posts' OR 1=1", $columnSql)] as $sql) {
    $db = native_post_fixture();
    $transported = false;
    $db->onQuery(static function (string $actual) use ($sql, &$transported): void {
        if ($actual === $sql) $transported = true;
    });
    wprism_check_throws(static fn() => $schemaRead($sql, NativeDatabaseProfile::read_only(['wp_posts'])),
        RuntimeException::class, 'column roster cannot widen its table, schema, projection, count or expression authority');
    wprism_check(!$transported, 'invalid column census is rejected before driver transport');
}
native_post_fixture();
wprism_check_throws(static fn() => $schemaRead($columnSql, NativeDatabaseProfile::schema_read_only([], ['wp_posts'])),
    RuntimeException::class, 'table-presence-only authority cannot read its column inventory');

foreach (['SELECT COLUMN_NAME', ' AS _wprism_size_0', ' AS _wprism_type', 'SELECT * FROM wp_posts'] as $query) {
    $db = native_post_fixture()->failNextQuery('private input failure', $query);
    native_post_refuses('a failed metadata, size, type or native payload read cannot be absence');
}
$db = native_post_fixture();
$db->onQuery(static function (string $sql): void {
    if (str_contains($sql, 'SELECT * FROM wp_posts')) {
        wprism_wp_store()->cache['posts'][7] = (object) ['ID' => 7, 'post_type' => 'page', 'filter' => 'raw'];
    }
});
native_post_refuses('cache population drift cannot hide behind a matching native return');
$db = native_post_fixture();
$db->onQuery(static function (string $sql): void {
    if (str_contains($sql, 'SELECT * FROM wp_posts')) $GLOBALS['wp_object_cache'] = new WP_Object_Cache();
});
native_post_refuses('cache object replacement during native work refuses');
$db = native_post_fixture();
$db->onQuery(static function (string $sql, string $method, FakeWpdb $db): void {
    if (str_contains($sql, 'SELECT * FROM wp_posts')) {
        $db->seedTable('wp_posts', [native_post_row(['post_type' => 'page'])]);
    }
});
native_post_refuses('a native return cannot replace the admitted physical type');

$db = native_post_fixture();
$originalRows = $db->rows('wp_posts');
wprism_check_throws(static fn() => ProviderDatabaseSession::repeatable_read_write('swallowed native post refusal',
    new NativeDatabaseProfile([], ['wp_posts']), static function (): void {
        try { NativePostTypes::read([0], 'swallowed native input'); } catch (Throwable) {}
    }, static fn(): string => ProviderDatabaseSession::POSTIMAGE_UNKNOWN), RuntimeException::class,
    'a swallowed native input refusal poisons the outer mutation transaction');
wprism_check_same($originalRows, $db->rows('wp_posts'), 'swallowed refusal leaves the complete original physical rows');
wprism_check(!DatabaseQueryIsolation::is_active(), 'swallowed refusal is settled by the transaction owner');
native_post_fixture();
wprism_check_throws(static fn() => NativePostTypes::read([7], 'outside native scope'), RuntimeException::class,
    'kernel native admission cannot invent a database snapshot');

$scratch = dirname(__DIR__, 3) . '/tmp/native-post-reader-' . bin2hex(random_bytes(6));
mkdir($scratch, 0700, true);
$stem = $scratch . '/native';
$record = ['format' => 'wprism-native-post-types/v1', 'engine' => 'MariaDB',
    'cold_warm_and_absent' => true, 'exact_stdclass_and_wp_post' => true,
    'bounded_unconsumed_cache_fields' => true,
    'refusals' => ['zero_id', 'duplicate_id', 'stale_type', 'cached_ghost', 'unsafe_class',
        'cached_object_field', 'cached_oversized_field', 'cached_extra_field', 'cold_oversized_field', 'observer_callback'],
    'object_clones' => 0, 'caught_failure_rollback' => true, 'complete_post_rows_restored' => true];
$admit = static function (array $data, string $stderr = '', string $exit = '0') use ($stem): int {
    foreach (['stdout' => json_encode($data, JSON_THROW_ON_ERROR) . "\n", 'stderr' => $stderr, 'exit' => $exit . "\n"] as $suffix => $bytes) {
        file_put_contents($stem . '.' . $suffix, $bytes);
        chmod($stem . '.' . $suffix, 0600);
    }
    $process = proc_open([PHP_BINARY, dirname(__DIR__, 2) . '/fixtures/native-post-types.php', '--admit', $stem, 'MariaDB'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    stream_get_contents($pipes[1]); stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    return proc_close($process);
};
try {
    wprism_check_same(0, $admit($record), 'native record admission accepts the independently specified complete contract');
    foreach (array_keys($record) as $key) {
        $incomplete = $record;
        unset($incomplete[$key]);
        wprism_check($admit($incomplete) !== 0, 'native evidence cannot omit required field: ' . $key);
    }
    foreach ([['engine' => 'MySQL'], ['refusals' => []], ['object_clones' => 1],
        ['complete_post_rows_restored' => false], ['unknown' => true]] as $changes) {
        wprism_check($admit(array_replace($record, $changes)) !== 0, 'native admission rejects altered, incomplete or surplus evidence');
    }
    wprism_check($admit($record, "PHP Warning: fixture warning\n") !== 0, 'a success object cannot hide native warnings');
    wprism_check($admit($record, '', '1') !== 0, 'a success object cannot hide native command failure');
} finally {
    foreach (['stdout', 'stderr', 'exit'] as $suffix) unlink($stem . '.' . $suffix);
    rmdir($scratch);
}

wprism_check_summary('regress_native_post_types');
