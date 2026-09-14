<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once "$root/sandbox/tests/lib/check.php";
require_once dirname(__DIR__, 2) . '/fixtures/apply-evidence.php';
$uuid = static fn(int $n): string => sprintf('12345678-1234-4123-8123-%012d', $n);
$intent = [];
foreach (['export', 'import'] as $i => $kind) $intent[] = ['id' => 101 + $i, 'uuid' => $uuid($i + 1), 'kind' => $kind,
    'hash' => str_repeat((string) ($i + 1), 64), 'form' => ['mapping_form_data' => ['mapping_selected_fields' => ['billing_city' => 'New city']],
        'method_' . $kind . '_form_data' => []]];
$session = static fn(string $nonce, int $time): string => json_encode(['artifact_hash' => str_repeat('a', 64),
    'begun_at' => $time, 'owner' => 'direct-' . str_repeat($nonce, 32), 'session_id' => 'ps-' . str_repeat($nonce, 32)], JSON_THROW_ON_ERROR);
$before = [
    'wp_wt_iew_mapping_template' => [
        ['id' => 101, 'template_type' => 'export', 'item_type' => 'user', 'name' => 'Selected users', 'data' => '{"old":true}'],
        ['id' => 102, 'template_type' => 'import', 'item_type' => 'user', 'name' => 'Reusable input mapping', 'data' => '{"old":true}'],
        ['id' => 103, 'template_type' => 'export', 'item_type' => 'product', 'name' => 'Local product mapping', 'data' => 'keep-excluded'],
    ],
    'wp_wprism_state' => [
        ['uuid' => $uuid(1), 'entity_type' => 'wt_iew_mapping_template', 'content_hash' => str_repeat('d', 64)],
        ['uuid' => $uuid(2), 'entity_type' => 'wt_iew_mapping_template', 'content_hash' => str_repeat('e', 64)],
        ['uuid' => $uuid(3), 'entity_type' => 'product', 'content_hash' => str_repeat('f', 64)],
    ],
    'wp_wprism_kv' => [['k' => 'applied_revision', 'v' => str_repeat('b', 40)], ['k' => 'promotion_session', 'v' => $session('1', 50)]],
];
$neighbors = ['wp_options', 'wp_posts', 'wp_postmeta', 'wp_users', 'wp_usermeta', 'wp_wt_iew_action_history',
    'wp_wc_customer_lookup', 'wp_wc_orders', 'wp_wc_orders_meta', 'wp_wc_order_addresses', 'wp_wc_order_operational_data',
    'wp_unrecognized_extension', 'wp_wprism_map', 'wp_wprism_journal'];
foreach ($neighbors as $name) $before[$name] = [['id' => 11, 'local_value' => 'keep-local']];
ksort($before, SORT_STRING);
// The fixture's SQL emitter is deliberately independent of the production
// reader. Neighbor amounts stay raw DECIMAL and UINT64 literals, not floats.
$read = static function (array $rows, ?string $mutateTable = null, bool $schema = false): array {
    $inventory = ''; $columns = []; $dump = "-- MariaDB dump 10.19 Distrib 11.4\n";
    $quote = static fn(string $value): string => "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], $value) . "'";
    foreach ($rows as $table => $data) {
        $inventory .= "$table\tBASE TABLE\n";
        $fields = array_keys($data[0]);
        $columns[$table] = ''; $definitions = [];
        foreach ($fields as $field) {
            $type = is_int($data[0][$field]) ? 'bigint' : 'longtext';
            $columns[$table] .= "$field\t$type\tNULL\tYES\t\tNULL\t\tselect,insert,update,references\t\n";
            $definitions[] = "  `$field` $type";
        }
        $columns[$table] .= "precision_canary\tdecimal(26,8)\tNULL\tNO\t\t0\t\tselect,insert,update,references\t\n";
        $control = in_array($table, ['wp_wt_iew_mapping_template', 'wp_wprism_state', 'wp_wprism_kv'], true);
        if ($control) $columns[$table] = substr($columns[$table], 0, strpos($columns[$table], 'precision_canary'));
        else {
            $definitions[] = '  `precision_canary` decimal(26,8)';
            $definitions[] = '  `wide_identity` bigint unsigned';
            $columns[$table] .= "wide_identity\tbigint unsigned\tNULL\tNO\t\t0\t\tselect,insert,update,references\t\n";
        }
        $dump .= "-- Table structure for table `$table`\nCREATE TABLE `$table` (\n" . implode(",\n", $definitions) . "\n) ENGINE=InnoDB" . ($schema && $table === $mutateTable ? ' ROW_FORMAT=DYNAMIC' : '') . ";\n-- Dumping data for table `$table`\n";
        foreach ($data as $row) {
            $values = array_map(static fn(mixed $value): string => is_int($value) ? (string) $value : $quote($value), array_values($row));
            $names = $fields;
            if (!$control) { $names[] = 'precision_canary'; $values[] = $table === $mutateTable && !$schema ? '20.00000002' : '20.00000001'; $names[] = 'wide_identity'; $values[] = '18446744073709551615'; }
            $dump .= 'INSERT INTO `' . $table . '` (`' . implode('`, `', $names) . '`) VALUES (' . implode(',', $values) . ");\n";
        }
    }
    return ImporterWooApplyEvidence::read($dump . "-- Dump completed\n", $inventory, $columns);
};
$after = $before;
foreach ($intent as $i => $entity) {
    $after['wp_wt_iew_mapping_template'][$i]['data'] = json_encode($entity['form'], JSON_THROW_ON_ERROR);
    $after['wp_wprism_state'][$i]['content_hash'] = $entity['hash'];
}
$after['wp_wprism_kv'][0]['v'] = str_repeat('c', 40);
$after['wp_wprism_kv'][1]['v'] = $session('2', 105);
$oldImage = $read($before); $newImage = $read($after);
$verify = static fn(array $candidate) => ImporterWooApplyEvidence::transition($oldImage, $candidate, $intent,
    str_repeat('c', 40), str_repeat('a', 64), ['before' => 100, 'after' => 110]);
$verify($newImage);
wprism_check(true, 'two intended forms and baseline/revision/session transitions preserve every other observed byte');
foreach ($neighbors as $table) {
    wprism_check_throws(static fn() => $verify($read($after, $table)), RuntimeException::class, "last-digit mutation in $table refuses");
    wprism_check_throws(static fn() => $verify($read($after, $table, true)), RuntimeException::class, "schema mutation in $table refuses");
}
foreach (['template-neighbor', 'template-name', 'template-id', 'stale-form', 'extra-state', 'other-baseline', 'stale-baseline',
    'revision', 'session-replay', 'session-time', 'lease', 'incomplete', 'removed-neighbor'] as $fault) {
    $bad = $after;
    if ($fault === 'template-neighbor') $bad['wp_wt_iew_mapping_template'][2]['data'] = 'changed';
    if ($fault === 'template-name') $bad['wp_wt_iew_mapping_template'][0]['name'] = 'changed';
    if ($fault === 'template-id') $bad['wp_wt_iew_mapping_template'][0]['id'] = 104;
    if ($fault === 'stale-form') $bad['wp_wt_iew_mapping_template'][0]['data'] = '{"old":true}';
    if ($fault === 'extra-state') $bad['wp_wprism_state'][] = ['uuid' => $uuid(4), 'entity_type' => 'product', 'content_hash' => str_repeat('f', 64)];
    if ($fault === 'other-baseline') $bad['wp_wprism_state'][2]['content_hash'] = str_repeat('e', 64);
    if ($fault === 'stale-baseline') $bad['wp_wprism_state'][0]['content_hash'] = str_repeat('d', 64);
    if ($fault === 'revision') $bad['wp_wprism_kv'][0]['v'] = str_repeat('d', 40);
    if ($fault === 'session-replay') $bad['wp_wprism_kv'][1]['v'] = $session('1', 105);
    if ($fault === 'session-time') $bad['wp_wprism_kv'][1]['v'] = $session('2', 111);
    if ($fault === 'lease') $bad['wp_wprism_kv'][] = ['k' => 'promotion_lock', 'v' => '{}'];
    if ($fault === 'incomplete') $bad['wp_wprism_kv'][] = ['k' => 'apply_in_progress', 'v' => '{}'];
    if ($fault === 'removed-neighbor') unset($bad['wp_unrecognized_extension']);
    wprism_check_throws(static fn() => $verify($read($bad)), RuntimeException::class, "unexpected $fault refuses");
}
$repeated = $after; $repeated['wp_wprism_kv'][1]['v'] = $session('3', 205);
ImporterWooApplyEvidence::transition($newImage, $read($repeated), $intent, str_repeat('c', 40), str_repeat('a', 64),
    ['before' => 200, 'after' => 210], true);
wprism_check(true, 'repeat changes only its fresh direct session');
foreach (['promotion_lock', 'apply_in_progress'] as $key) {
    $unsettled = $before; $unsettled['wp_wprism_kv'][] = ['k' => $key, 'v' => '{}'];
    wprism_check_throws(static fn() => ImporterWooApplyEvidence::transition($read($unsettled), $newImage, $intent,
        str_repeat('c', 40), str_repeat('a', 64), ['before' => 100, 'after' => 110]), RuntimeException::class,
        "a lone $key cannot establish the settled premise");
}
$directory = $root . '/sandbox/tmp/importer-woo-apply-files-' . bin2hex(random_bytes(6));
mkdir($directory, 0700);
$files = [];
foreach (['webtoffee_export', 'webtoffee_import'] as $name) {
    mkdir("$directory/$name", 0700);
    file_put_contents("$directory/$name/local.csv", "local,canary\n1,retained\n");
    $files[$name] = WPrismTest\FilesystemTreeEvidence::capture($directory, $name);
}
try {
    ImporterWooApplyEvidence::files($files, $files);
    wprism_check(true, 'both nonempty native operational file trees are preserved');
    file_put_contents("$directory/webtoffee_import/local.csv", "local,canary\n1,modified\n");
    $changed = $files;
    $changed['webtoffee_import'] = WPrismTest\FilesystemTreeEvidence::capture($directory, 'webtoffee_import');
    wprism_check_throws(static fn() => ImporterWooApplyEvidence::files($files, $changed), RuntimeException::class,
        'a valid same-size native file rewrite refuses');
    $emptyFiles = $files;
    foreach ($emptyFiles as &$tree) $tree['files'] = [];
    unset($tree);
    wprism_check_throws(static fn() => ImporterWooApplyEvidence::files($emptyFiles, $emptyFiles), RuntimeException::class,
        'equal empty file witnesses refuse');
    unset($changed['webtoffee_export']);
    wprism_check_throws(static fn() => ImporterWooApplyEvidence::files($files, $changed), RuntimeException::class,
        'an omitted operational file root refuses');
} finally {
    foreach (['webtoffee_export', 'webtoffee_import'] as $name) { unlink("$directory/$name/local.csv"); rmdir("$directory/$name"); }
    rmdir($directory);
}
wprism_check_summary('Importer/Woo exact Apply database transition');
