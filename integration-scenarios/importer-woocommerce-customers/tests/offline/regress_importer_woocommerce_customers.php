<?php
declare(strict_types=1);

// A target-only WooCommerce neighbor must not change saved user selections or
// acquire ownership of customers, orders, and import/export job history.
$root = dirname(__DIR__, 4);
$capsule = $root . '/adapter-packages/users-customers-import-export-for-wp-woocommerce';
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
wprism_test_define_agent_versions();
foreach (['Policy/Policy', 'Grammar/Tokens', 'Repository/Ledger', 'Repository/Snapshot',
    'Repository/IdentityNotes'] as $file) {
    require_once "$root/agent/src/$file.php";
}

use WPrism\Canon;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\Snapshot;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

$fixture = Canon::decode(Canon::read_file($capsule . '/fixtures/native-export-templates.json'));
$policy = WPrism\Policy::load(null, ['core', 'users-customers-import-export-for-wp-woocommerce'],
    adapterLibrary: WPrism\AdapterLibrary::fromSourcePackage($root, 'users-customers-import-export-for-wp-woocommerce'));
$native = json_encode($fixture['form'], JSON_THROW_ON_ERROR);
wprism_check_same($fixture['native_data_sha256'], hash('sha256', $native), 'fixture reproduces complete native Saved data');
// Woo's native data-user-columns.php enables these address/statistic keys.
// Exercise their field-label transport without pretending synthetic rows ran CSV.
$fields = Canon::decode(Canon::read_file(dirname(__DIR__, 2) . '/fixtures/customer-mapping-fields.json'));
foreach ($fields['export'] as $field) {
    $fixture['form']['mapping_form_data']['mapping_fields'][$field] = [$field, 1];
    $fixture['form']['mapping_form_data']['mapping_selected_fields'][$field] = $field;
}
$native = json_encode($fixture['form'], JSON_THROW_ON_ERROR);
$table = 'wt_iew_mapping_template';
$db = FakeWpdb::install()->enableInformationSchema()->enableJoinedCaptureSql()
    ->setPrimaryKey($table, 'id')->setTableEngine($table, 'InnoDB')
    ->setColumns($table, ['id' => 'int(11)', 'template_type' => 'varchar(255)', 'item_type' => 'varchar(255)',
        'name' => 'varchar(255)', 'data' => 'longtext'])
    ->setColumns('wprism_map', ['uuid' => 'char(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned'])
    ->setUniqueKey('wprism_map', ['uuid', 'id_kind'])->setTableEngine('wprism_map', 'InnoDB')->seedTable('wprism_map', [])
    ->seedTable('wp_users', $fixture['source_users'])->setTableEngine('wp_users', 'InnoDB')
    ->seedTable('wp_usermeta', [['umeta_id' => 7, 'user_id' => 2, 'meta_key' => 'session_tokens', 'meta_value' => 'local-session']])
    ->seedTable('wt_iew_action_history', [['id' => 8, 'data' => '{"file":"local-export.csv"}']]);
$rows = array_map(static fn(array $row): array => $row + ['data' => $native], $fixture['templates']);
$foreign = [
    ['id' => 3, 'template_type' => 'Import', 'item_type' => 'user', 'name' => 'Case-variant input', 'data' => '{broken'],
    ['id' => 4, 'template_type' => 'export', 'item_type' => 'product', 'name' => 'Selected users', 'data' => 'local-product'],
    ['id' => 5, 'template_type' => 'Export', 'item_type' => 'user', 'name' => 'Selected users', 'data' => 'case-variant'],
];
$db->seedTable($table, [...$rows, ...$foreign]);
WpStore::reset()->seedOptions(['home' => 'https://source.test']);
$sourceTokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
$transaction = static function (callable $action) use ($table): mixed {
    Db::start_repeatable_read('importer templates', new NativeDatabaseProfile(
        ['wp_' . $table, 'wp_wprism_map', 'wp_users'], ['wp_' . $table, 'wp_wprism_map']));
    try {
        $result = $action();
        Db::commit('importer templates');
        return $result;
    } catch (Throwable $failure) {
        Db::rollback_after_failure($failure, 'importer templates');
        throw $failure;
    }
};
$census = static function () use ($db, $table): array {
    $out = [];
    foreach ([$table, 'wprism_map', 'wp_users', 'wp_usermeta', 'wt_iew_action_history'] as $name) $out[$name] = $db->rows($name);
    return $out;
};
$capture = static fn(Tokens $tokens): array => $transaction(static fn() => Snapshot::capture($policy, $tokens, true));
$before = $census();
$entities = $capture($sourceTokens);
wprism_check_same(2, count($entities), 'Snapshot captures only the two exact export-user rows');
wprism_check_same($before[$table], $db->rows($table), 'Capture preserves every native template byte');
foreach ($entities as $entity) {
    $value = json_decode(Canon::decode($entity['content'])['columns']['data'], true, flags: JSON_THROW_ON_ERROR);
    $expected = $fixture['form'];
    $expected['filter_form_data']['wt_iew_email'] = ['user:template-reader', 'user:template-editor'];
    unset($expected['method_export_form_data']['selected_template']);
    wprism_check_same($expected, $value, 'all native form fields survive except the regenerated cursor and bound user IDs');
}

$library = WPrism\AdapterLibrary::fromSourceTree($root);
$operational = [
    'wp_users' => $fixture['target_users'],
    'wp_usermeta' => [
        ['umeta_id' => 1, 'user_id' => 82, 'meta_key' => 'billing_city', 'meta_value' => 'Dhaka'],
        ['umeta_id' => 2, 'user_id' => 82, 'meta_key' => 'session_tokens', 'meta_value' => 'target-session'],
        ['umeta_id' => 3, 'user_id' => 93, 'meta_key' => 'shipping_city', 'meta_value' => 'Tokyo'],
    ],
    'wc_customer_lookup' => [
        ['customer_id' => 409, 'user_id' => 82, 'email' => 'local@example.test'],
        ['customer_id' => 410, 'user_id' => 93, 'email' => 'other@example.test'],
    ],
    'wc_orders' => [['id' => 711, 'customer_id' => 82, 'status' => 'wc-processing', 'total_amount' => '123.45']],
    'wc_orders_meta' => [['id' => 7, 'order_id' => 711, 'meta_key' => 'local-note', 'meta_value' => 'retain']],
    'wc_order_addresses' => [['id' => 8, 'order_id' => 711, 'city' => 'Dhaka']],
    'wt_iew_action_history' => [['id' => 8, 'data' => '{"file":"local-export.csv"}']],
];
$orders = [
    ['core', 'users-customers-import-export-for-wp-woocommerce', 'woocommerce'],
    ['core', 'woocommerce', 'users-customers-import-export-for-wp-woocommerce'],
];
foreach ($orders as $pins) {
    $combined = WPrism\Policy::load(null, $pins, adapterLibrary: $library);
    wprism_check_same($policy->table_rule($table), $combined->table_rule($table),
        'both pin orders retain the complete Importer table contract');
    foreach (['wc_customer_lookup', 'wc_orders', 'wc_orders_meta', 'wc_order_addresses'] as $name) {
        wprism_check_same('runtime', $combined->table_rule($name)['class'] ?? null,
            "$name remains operational under the combined policy");
    }
    $db->seedTable($table, $foreign)->setAutoIncrement($table, 800, 'id')->seedTable('wprism_map', []);
    foreach ($operational as $name => $rows) $db->seedTable($name, $rows);
    $census = static function () use ($db, $table, $operational): array {
        $out = [];
        foreach ([$table, 'wprism_map', ...array_keys($operational)] as $name) $out[$name] = $db->rows($name);
        return $out;
    };
    $work = array_map(static function (array $entity): array {
        $entity['data'] = Canon::decode($entity['content']);
        return $entity;
    }, $entities);
    $write = static function (array $work) use ($transaction, $combined): void {
        $targetTokens = new Tokens('https://target.test', 'https://target.test/wp-content/uploads');
        $transaction(static function () use ($work, $combined, $targetTokens): void {
            foreach ($work as $entity) {
                Snapshot::ensure_row($combined, $entity);
                Snapshot::finalize_row($combined, $targetTokens, $entity);
            }
        });
    };
    $write($work);
    wprism_check_same([800, 801], array_column(array_slice($db->rows($table), count($foreign)), 'id'),
        'both target templates exist under independent target IDs');
    foreach (array_slice($db->rows($table), count($foreign)) as $row) {
        $expected = $fixture['form'];
        $expected['filter_form_data']['wt_iew_email'] = ['82', '93'];
        unset($expected['method_export_form_data']['selected_template']);
        wprism_check_same($expected, json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR),
            'combined materializer resolves WordPress user IDs, not Woo customer lookup IDs');
    }
    foreach ($operational as $name => $rows) wprism_check_same($rows, $db->rows($name),
        "combined materialization preserves every seeded $name value");
    wprism_check_same($foreign, array_slice($db->rows($table), 0, count($foreign)),
        'product-template and case-variant neighbors stay outside user row ownership');
    $after = $census();
    $write($work);
    wprism_check_same($after, $census(), 'combined repeat is an exact no-op');
    $db->seedTable('wp_users', [['ID' => 82, 'user_login' => 'template-reader']]);
    $missingBefore = $census();
    wprism_check_throws(static fn() => $write($work), RuntimeException::class,
        'a Woo customer row cannot satisfy a missing WordPress login reference');
    wprism_check_same($missingBefore, $census(), 'missing target login refuses without operational mutation');
}
require_once dirname(__DIR__, 2) . '/fixtures/database-evidence.php';
$tables = ['wp_options', 'wp_posts', 'wp_postmeta', 'wp_users', 'wp_usermeta',
    'wp_wt_iew_mapping_template', 'wp_wt_iew_action_history', 'wp_wc_customer_lookup',
    'wp_wc_orders', 'wp_wc_orders_meta', 'wp_wc_order_addresses', 'wp_wc_order_operational_data',
    'wp_neighbor_canary'];
sort($tables, SORT_STRING);
$inventory = ''; $columns = []; $dump = "-- MariaDB dump 10.19 Distrib 11.4\n";
foreach ($tables as $name) {
    $inventory .= "$name\tBASE TABLE\n";
    $columns[$name] = "id\tbigint(20) unsigned\tNULL\tNO\tPRI\tNULL\t\tselect,insert,update,references\t\n"
        . "amount\tdecimal(26,8)\tNULL\tNO\t\t0\t\tselect,insert,update,references\t\n";
    $dump .= "-- Table structure for table `$name`\nCREATE TABLE `$name` (\n  `id` bigint unsigned NOT NULL,\n  `amount` decimal(26,8) NOT NULL\n);\n"
        . "-- Dumping data for table `$name`\nINSERT INTO `$name` (`id`, `amount`) VALUES (18446744073709551615,20.00000001);\n";
}
$dump .= "-- Dump completed\n";
$image = ImporterWooDatabaseEvidence::read($dump, $inventory, $columns);
wprism_check_same($tables, array_keys($image['rows']), 'complete comparison image includes every neighbor table');
foreach ($tables as $name) {
    wprism_check_same([['id' => '18446744073709551615', 'amount' => '20.00000001']], $image['rows'][$name],
        'complete image retains unsigned identity and decimal precision for ' . $name);
    $changed = str_replace("INSERT INTO `$name` (`id`, `amount`) VALUES (18446744073709551615,20.00000001);",
        "INSERT INTO `$name` (`id`, `amount`) VALUES (18446744073709551615,20.00000002);", $dump);
    wprism_check($image !== ImporterWooDatabaseEvidence::read($changed, $inventory, $columns),
        'one least-significant decimal mutation changes the complete image for ' . $name);
}
$missingColumns = $columns; unset($missingColumns['wp_neighbor_canary']);
wprism_check_throws(static fn() => ImporterWooDatabaseEvidence::read($dump, $inventory, $missingColumns), RuntimeException::class,
    'an unowned neighbor cannot disappear from the independent column census');
$wrongColumns = $columns;
$wrongColumns['wp_wc_orders'] = explode("\n", $wrongColumns['wp_wc_orders'])[0] . "\n";
wprism_check_throws(static fn() => ImporterWooDatabaseEvidence::read($dump, $inventory, $wrongColumns), RuntimeException::class,
    'a omitted HPOS amount column cannot hide changes');
$emptyOrders = str_replace("INSERT INTO `wp_wc_orders` (`id`, `amount`) VALUES (18446744073709551615,20.00000001);\n", '', $dump);
wprism_check_throws(static fn() => ImporterWooDatabaseEvidence::read($emptyOrders, $inventory, $columns), RuntimeException::class,
    'an empty order witness cannot establish native order preservation');
$sink = $root . '/sandbox/tmp/importer-woo-database-' . bin2hex(random_bytes(6));
mkdir($sink, 0700);
register_shutdown_function(static function () use ($sink): void {
    foreach (glob($sink . '/*') ?: [] as $file) unlink($file);
    rmdir($sink);
});
$record = static function (string $label, string $bytes) use ($sink): void {
    foreach (['stdout' => $bytes, 'stderr' => '', 'exit' => "0\n"] as $suffix => $value) {
        file_put_contents($sink . '/' . $label . '.' . $suffix, $value);
        chmod($sink . '/' . $label . '.' . $suffix, 0600);
    }
};
$record('before-tables', $inventory); $record('before-tables-after', $inventory);
$record('before-database', $dump);
foreach ($columns as $name => $bytes) {
    $record('before-columns-' . $name, $bytes);
    $record('before-columns-after-' . $name, $bytes);
}
wprism_check_same($image, ImporterWooDatabaseEvidence::fromSink($sink, 'impcustomer', 2, 'before'),
    'private successful native streams produce the independently verified complete image');
$record('before-tables-after', str_replace("wp_neighbor_canary\tBASE TABLE\n", '', $inventory));
wprism_check_throws(static fn() => ImporterWooDatabaseEvidence::fromSink($sink, 'impcustomer', 2, 'before'), RuntimeException::class,
    'a disappearing neighbor during the dump invalidates the observation');
$record('before-tables-after', $inventory);
$record('before-columns-after-wp_wc_orders', str_replace('decimal(26,8)', 'decimal(26,4)', $columns['wp_wc_orders']));
wprism_check_throws(static fn() => ImporterWooDatabaseEvidence::fromSink($sink, 'impcustomer', 2, 'before'), RuntimeException::class,
    'decimal precision DDL during the dump invalidates unchanged row literals');
$record('before-columns-after-wp_wc_orders', $columns['wp_wc_orders']);
file_put_contents($sink . '/before-database.stderr', "Warning: omitted native rows\n");
wprism_check_throws(static fn() => ImporterWooDatabaseEvidence::fromSink($sink, 'impcustomer', 2, 'before'), RuntimeException::class,
    'native diagnostic output cannot be accepted as complete database evidence');
$record('before-database', $dump);
file_put_contents($sink . '/before-database.exit', "1\n");
wprism_check_throws(static fn() => ImporterWooDatabaseEvidence::fromSink($sink, 'impcustomer', 2, 'before'), RuntimeException::class,
    'failed native export cannot be admitted despite complete-looking stdout');
$record('before-database', $dump);
file_put_contents($sink . '/before-database.stderr', "Container wprism-foreign-cli2-run-aaaaaaaaaaaa Created\n");
wprism_check_throws(static fn() => ImporterWooDatabaseEvidence::fromSink($sink, 'impcustomer', 2, 'before'), RuntimeException::class,
    'another pair transport does not authenticate this observation');
$record('before-database', $dump);
wprism_check_same($image, ImporterWooDatabaseEvidence::fromSink($sink, 'impcustomer', 2, 'before'),
    'restored independently successful streams reproduce the exact image');
// Compose oneoffs can consume stdin even for a non-interactive SQL command.
// Drive the real producer with a deliberately stdin-draining transport.
$script = $sink . '/observe-probe.sh';
file_put_contents($script, <<<'BASH'
#!/usr/bin/env bash
set -euo pipefail
SCENARIO_ROOT="$1"
sink="$2"
PAIR=impcustomer
fail() { printf '%s\n' "$*" >&2; exit 1; }
wp_side() { cat >/dev/null; }
combo_capture() {
    printf '%s\n' "$1"
    shift
    if [ "$1" = wp_side ]; then "$@"; fi
}
. "$SCENARIO_ROOT/fixtures/observe.sh"
combo_database_observe 2 before
BASH);
$process = proc_open(['bash', $script, dirname(__DIR__, 2), $sink],
    [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
wprism_check(is_resource($process), 'actual shell observation probe starts');
if (is_resource($process)) {
    $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    wprism_check_same(0, proc_close($process), 'stdin-draining native transport completes the producer');
    wprism_check_same('', $stderr, 'producer emits no shell diagnostics');
    $expected = ['before-tables'];
    foreach ($tables as $name) $expected[] = 'before-columns-' . $name;
    $expected[] = 'before-database'; $expected[] = 'before-tables-after';
    foreach ($tables as $name) $expected[] = 'before-columns-after-' . $name;
    $expected[] = 'before-image';
    wprism_check_same(implode("\n", $expected) . "\n", $stdout,
        'every table is observed twice despite a child command that drains stdin');
}
wprism_check_summary('Importer/WooCommerce customer reference ownership');
