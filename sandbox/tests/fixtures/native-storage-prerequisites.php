<?php
declare(strict_types=1);

function storage_lock_expected(string $engine): array {
    return ['format' => 'wprism-native-storage-prerequisites/v1', 'engine' => $engine,
        'distinct_connections' => true, 'cursor_update_blocked' => true,
        'update_after_commit' => true, 'stale_cursor_refused' => true,
        'absent_cursor_refused' => true, 'insertion_gap_locked' => true,
        'insert_after_rollback' => true, 'complete_option_rows_restored' => true];
}

if (($argv[1] ?? '') === '--admit') {
    require_once __DIR__ . '/../lib/PrivateCommandOutput.php';
    $engine = $argv[3] ?? '';
    $record = json_decode(WPrismTest\PrivateCommandOutput::readObject($argv[2] ?? '',
        '/^ ?Container wprism-[a-z0-9]+-cli1-run-[a-z0-9]+ (Creating|Created) *$/D'), true, 32, JSON_THROW_ON_ERROR);
    if (!in_array($engine, ['MariaDB', 'MySQL'], true) || $record !== storage_lock_expected($engine)) {
        throw new RuntimeException('native storage prerequisite record does not prove the declared contract');
    }
    exit(0);
}

if (!defined('ABSPATH') || !class_exists(WPrism\StoragePrerequisites::class)) {
    throw new RuntimeException('storage prerequisite fixture requires loaded WordPress and the product kernel');
}
$check = static function (bool $ok, string $why): void {
    if (!$ok) throw new RuntimeException('native storage prerequisite proof failed: ' . $why);
};
global $wpdb;
$name = 'wprism_storage_lock_fixture';
$table = $wpdb->options;
$check(preg_match('/^[A-Za-z0-9_]+$/D', $table) === 1, 'owned options coordinate');
$check(preg_match('/^[A-Za-z0-9.-]+$/D', DB_HOST) === 1, 'pair database hostname');
$rows = static function () use ($wpdb, $table, $check): array {
    $wpdb->last_error = '';
    $result = $wpdb->get_results("SELECT * FROM `$table` ORDER BY option_id LIMIT 4097", ARRAY_A);
    $check(is_array($result) && count($result) > 0 && count($result) <= 4096 && $wpdb->last_error === '', 'complete bounded options roster');
    return $result;
};
$initial = $rows();
$check(!in_array($name, array_column($initial, 'option_name'), true), 'fixture coordinate initially absent');
$manifests = [['name' => 'native-storage-fixture', 'engine_features' => ['storage-prerequisites/v1'],
    'options' => [$name => ['class' => 'runtime']],
    'storage_prerequisites' => [['option' => $name, 'equals' => 'settled']]]];
$report = (new mysqli_driver())->report_mode;
mysqli_report(MYSQLI_REPORT_OFF);
$other = null;
$open = false;
$created = false;
try {
    // A direct second connection bypasses WordPress's process-global query
    // filters; a product refusal before SQL transport is not lock evidence.
    $other = new mysqli(DB_HOST, DB_USER, DB_PASSWORD, DB_NAME);
    $check($other->connect_errno === 0, 'second connection opens');
    $identity = $other->query('SELECT CONNECTION_ID()');
    $check($identity instanceof mysqli_result, 'second connection identity');
    $secondId = $identity->fetch_row()[0];
    $firstId = $wpdb->get_var('SELECT CONNECTION_ID()');
    $check(is_string($firstId) && $firstId !== (string) $secondId, 'independent server connections');
    $check($other->query('SET SESSION innodb_lock_wait_timeout = 1') === true, 'bounded competing lock wait');
    $check($wpdb->insert($table, ['option_name' => $name, 'option_value' => 'settled', 'autoload' => 'no']) === 1, 'fixture row created');
    $created = true;
    $update = "UPDATE `$table` SET option_value = 'pending' WHERE option_name = '$name'";
    $authority = WPrism\Db::start_repeatable_read('storage prerequisite native proof', new WPrism\NativeDatabaseProfile([], [$table]));
    $open = true;
    WPrism\DatabaseQueryIsolation::with_engine_work_units($authority,
        static fn() => WPrism\StoragePrerequisites::lock($manifests));
    $check($other->query($update) === false && $other->errno === 1205, 'cursor UPDATE waits for product transaction');
    WPrism\Db::commit();
    $open = false;
    $check($other->query($update) === true && $other->affected_rows === 1, 'cursor UPDATE succeeds after commit');
    $refused = false;
    try { WPrism\StoragePrerequisites::assert_ready($manifests); }
    catch (WPrism\CommandRefusalException $error) { $refused = $error->reasonCode === 'storage_prerequisite_unmet'; }
    $check($refused, 'next admission observes stale durable cursor');
    $check($wpdb->delete($table, ['option_name' => $name]) === 1, 'remove fixture before absence proof');
    $created = false;
    $authority = WPrism\Db::start_repeatable_read('storage prerequisite gap proof', new WPrism\NativeDatabaseProfile([], [$table]));
    $open = true;
    $refused = false;
    try {
        WPrism\DatabaseQueryIsolation::with_engine_work_units($authority,
            static fn() => WPrism\StoragePrerequisites::lock($manifests));
    } catch (WPrism\CommandRefusalException $error) { $refused = $error->reasonCode === 'storage_prerequisite_unmet'; }
    $check($refused, 'absent cursor refuses under exact transaction authority');
    $insert = "INSERT INTO `$table` (option_name, option_value, autoload) VALUES ('$name', 'settled', 'no')";
    $check($other->query($insert) === false && $other->errno === 1205, 'cursor insertion gap remains locked until refusal rollback');
    WPrism\Db::rollback();
    $open = false;
    $check($other->query($insert) === true && $other->affected_rows === 1, 'cursor INSERT succeeds after rollback');
    $created = true;
} finally {
    if ($open) WPrism\Db::rollback();
    if ($created) $check($wpdb->delete($table, ['option_name' => $name]) === 1, 'owned fixture cleanup');
    if ($other instanceof mysqli) $other->close();
    mysqli_report($report);
}
// Allocation counters advance, but every native option row remains exact.
$check($rows() === $initial, 'complete initial options roster restored');
$engine = WPrism\PlatformCompatibility::current_facts()['database']['engine'];
echo json_encode(storage_lock_expected($engine), JSON_THROW_ON_ERROR), "\n";
