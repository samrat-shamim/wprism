<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/PhysicalTableRows.php';
require_once dirname(__DIR__, 4) . '/agent/src/Adapter/ProviderDatabaseSession.php';

use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\PhysicalTableRows;
use WPrism\ProviderDatabaseSession;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

/** @param list<array<string,mixed>>|null $rows */
function physical_rows_fixture(?array $rows = null): FakeWpdb {
    Db::forget_transaction_tracking();
    WpStore::reset();
    return FakeWpdb::install()->enableInformationSchema()
        ->seedTable('wp_posts', $rows ?? [
            ['ID' => 7, 'post_content' => "raw\0\xff東京", 'post_title' => null],
            ['ID' => 19, 'post_content' => 'a:0:{}', 'post_title' => ''],
        ])
        ->setColumns('wp_posts', ['ID' => 'bigint', 'post_content' => 'longtext', 'post_title' => 'text'])
        ->setTableEngine('wp_posts', 'InnoDB');
}

function physical_rows_descriptor(array $changes = []): array {
    return array_replace([
        'table' => 'wp_posts', 'columns' => ['ID', 'post_content', 'post_title'], 'identity' => 'ID',
        'max_rows' => 128, 'max_raw_bytes' => 8388608, 'mode' => 'rows',
    ], $changes);
}

function physical_rows_observe(array $changes = []): array {
    return ProviderDatabaseSession::read_only_snapshot('physical rows fixture',
        NativeDatabaseProfile::read_only(['wp_posts']),
        static fn(): array => PhysicalTableRows::observe(physical_rows_descriptor($changes), 'physical rows fixture'));
}

/** Independent framing oracle: no serialized PHP arrays or canonical JSON can select the byte meaning. */
function physical_rows_expected_hash(array $descriptor, array $rows): string {
    $frame = "wprism-physical-table-rows/v1\0";
    foreach ([$descriptor['table'], $descriptor['identity']] as $name) $frame .= pack('N', strlen($name)) . $name;
    $frame .= pack('N', count($descriptor['columns']));
    foreach ($descriptor['columns'] as $name) $frame .= pack('N', strlen($name)) . $name;
    foreach ($rows as $row) {
        $frame .= 'R';
        foreach ($descriptor['columns'] as $name) {
            $value = $row[$name];
            $frame .= $value === null ? "\0" : "\1" . pack('N', strlen($value)) . $value;
        }
    }
    return hash('sha256', $frame . 'E' . pack('N', count($rows)));
}

$db = physical_rows_fixture();
$expected = [['ID' => '7', 'post_content' => "raw\0\xff東京", 'post_title' => null],
    ['ID' => '19', 'post_content' => 'a:0:{}', 'post_title' => '']];
$observed = physical_rows_observe();
$bytes = array_sum(array_map(static fn(array $row): int => array_sum(array_map(
    static fn(?string $value): int => $value === null ? 0 : strlen($value), $row)), $expected));
wprism_check_same($expected, $observed['rows'], 'physical rows preserve exact driver strings, null, empty, serialized-looking and binary values');
wprism_check_same(['row_count' => 2, 'raw_bytes' => $bytes,
    'rows_sha256' => physical_rows_expected_hash(physical_rows_descriptor(), $expected), 'rows' => $expected],
    $observed, 'versioned physical witness matches an independent typed binary frame');
$digestOnly = $observed;
unset($digestOnly['rows']);
wprism_check_same($digestOnly, physical_rows_observe(['mode' => 'digest']), 'digest mode has the same complete witness and no retained row payload');
wprism_check_same($observed, physical_rows_observe(['max_rows' => 2, 'max_raw_bytes' => $bytes]), 'exact row and raw-byte limits are inclusive');
foreach ([['max_rows' => 1], ['max_raw_bytes' => $bytes - 1]] as $limit) {
    $db->resetLog();
    wprism_check_throws(static fn() => physical_rows_observe($limit), RuntimeException::class,
        'one beyond the caller row/byte frontier refuses before hashes or values');
    wprism_check(count(array_filter($db->queries(), static fn(string $sql): bool => str_contains($sql, ' AS _wprism_hash_'))) === 0,
        'an over-budget size roster never reaches hashing');
}
physical_rows_fixture([]);
$empty = physical_rows_observe();
wprism_check_same(['row_count' => 0, 'raw_bytes' => 0,
    'rows_sha256' => physical_rows_expected_hash(physical_rows_descriptor(), []), 'rows' => []],
    $empty, 'an exactly observed empty table has a framed identity, not an absent answer');
$many = [];
for ($id = 1; $id <= 130; $id++) $many[] = ['ID' => $id, 'post_content' => 'body-' . $id, 'post_title' => null];
$db = physical_rows_fixture($many);
$manyObserved = physical_rows_observe(['max_rows' => 130]);
wprism_check_same(130, $manyObserved['row_count'], 'three batches retain the complete table');
wprism_check_same('130', $manyObserved['rows'][129]['ID'], 'the final partial batch cannot disappear behind a count-only witness');
wprism_check_same(6, count(array_filter($db->queries(), static fn(string $sql): bool => str_contains($sql, ' AS _wprism_hash_0'))),
    'each batch independently reads field hashes before and after its payload');

$invalid = [
    ['table' => 'wp_posts; DELETE'], ['columns' => []], ['columns' => ['ID', 'id']],
    ['columns' => ['ID', 'post_content AS stolen']], ['columns' => [7]],
    ['columns' => ['post_content']], ['identity' => 'ID DESC'], ['identity' => ['ID']],
    ['max_rows' => 0], ['max_rows' => PhysicalTableRows::MAX_ROWS + 1], ['max_rows' => '1'],
    ['max_raw_bytes' => 0], ['max_raw_bytes' => PhysicalTableRows::MAX_RAW_BYTES + 1], ['max_raw_bytes' => 1.0],
    ['mode' => 'values'], ['mode' => false], ['where' => '1=1'], ['visitor' => static fn() => null],
];
foreach ($invalid as $index => $changes) {
    $db = physical_rows_fixture();
    wprism_check_throws(static fn() => physical_rows_observe($changes), Throwable::class,
        'malformed/SQL/callback descriptor refuses before target values: ' . $index);
    wprism_check(count(array_filter($db->queries(), static fn(string $sql): bool => str_contains($sql, ' AS _wprism_identity'))) === 0,
        'invalid descriptor ' . $index . ' cannot issue the roster query');
}

foreach (['0', '-1', '07', '7.0', '7e0', '9223372036854775808', '184467440737095516150', null] as $badId) {
    $db = physical_rows_fixture([['ID' => $badId, 'post_content' => 'private-row', 'post_title' => null]]);
    wprism_check_throws(static fn() => physical_rows_observe(), RuntimeException::class,
        'noncanonical or out-of-machine-range physical identity refuses before hashing: ' . json_encode($badId));
    wprism_check(count(array_filter($db->queries(), static fn(string $sql): bool => str_contains($sql, ' AS _wprism_hash_'))) === 0,
        'a malformed identity does not reach value hashing');
}
$db = physical_rows_fixture([['ID' => 7, 'post_content' => 'first', 'post_title' => null],
    ['ID' => 7, 'post_content' => 'other', 'post_title' => null]]);
wprism_check_throws(static fn() => physical_rows_observe(), RuntimeException::class, 'duplicate physical identity is never silently collapsed', 'duplicate or unordered');
$db = physical_rows_fixture([['ID' => 7, 'post_content' => str_repeat('x', PhysicalTableRows::MAX_CELL_BYTES), 'post_title' => null]]);
wprism_check_same(PhysicalTableRows::MAX_CELL_BYTES + 1, physical_rows_observe()['raw_bytes'], 'one exact maximum-size native cell is admitted');
$db = physical_rows_fixture([['ID' => 7, 'post_content' => str_repeat('x', PhysicalTableRows::MAX_CELL_BYTES + 1), 'post_title' => null]]);
wprism_check_throws(static fn() => physical_rows_observe(), RuntimeException::class, 'one oversized native cell refuses before its payload');

foreach (['grow-before-hash', 'grow-before-values', 'same-size-before-values', 'same-size-after-values',
    'null-before-values', 'append-before-final-roster', 'remove-before-final-roster'] as $fault) {
    $db = physical_rows_fixture();
    $query = 0;
    $db->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$query, $fault): void {
        if ($method !== 'get_results' || !str_contains($sql, ' FROM `wp_posts`')) return;
        $query++;
        $at = match ($fault) {
            'grow-before-hash' => 2,
            'same-size-after-values' => 4,
            'append-before-final-roster', 'remove-before-final-roster' => 5,
            default => 3,
        };
        if ($query !== $at) return;
        $rows = $db->rows('wp_posts');
        if ($fault === 'append-before-final-roster') $rows[] = ['ID' => 23, 'post_content' => 'new', 'post_title' => null];
        elseif ($fault === 'remove-before-final-roster') array_pop($rows);
        elseif ($fault === 'null-before-values') $rows[0]['post_content'] = null;
        elseif (str_starts_with($fault, 'same-size')) $rows[0]['post_content'] = str_repeat('y', strlen($rows[0]['post_content']));
        else $rows[0]['post_content'] .= '-growth';
        $db->seedTable('wp_posts', $rows);
    });
    wprism_check_throws(static fn() => physical_rows_observe(), RuntimeException::class,
        $fault . ' refuses an inconsistent physical witness');
}
foreach (range(1, 5) as $failAt) {
    $db = physical_rows_fixture();
    $before = $db->rows('wp_posts');
    $query = 0;
    $db->onQuery(static function (string $sql, string $method) use (&$query, $failAt): ?string {
        return $method === 'get_results' && str_contains($sql, ' FROM `wp_posts`') && ++$query === $failAt ? 'private_driver_payload' : null;
    });
    try {
        physical_rows_observe();
        wprism_check(false, 'failed physical read ' . $failAt . ' must refuse');
    } catch (RuntimeException $failure) {
        wprism_check(str_contains($failure->getMessage(), 'checked database read failed')
            && !str_contains($failure->getMessage(), 'private_driver_payload'), 'read ' . $failAt . ' refuses without leaking driver bytes');
    }
    wprism_check_same($before, $db->rows('wp_posts'), 'failed read ' . $failAt . ' leaves all native rows unchanged');
}

foreach ([null, false, ['not-a-list' => []], [['unexpected' => 'private-row']]] as $malformed) {
    $db = physical_rows_fixture()->returnNextGetResultsAs($malformed, ' AS _wprism_identity');
    wprism_check_throws(static fn() => physical_rows_observe(), RuntimeException::class, 'malformed driver roster refuses rather than becoming an empty table');
}
foreach ([null, false, [], [array_replace($expected[0], ['ID' => 7]), $expected[1]],
    [array_replace($expected[0], ['post_content' => []]), $expected[1]],
    [array_replace($expected[0], ['extra' => 'smuggled']), $expected[1]],
    [array_replace($expected[0], ['post_title' => '']), $expected[1]],
    [$expected[1], $expected[0]]] as $malformed) {
    $db = physical_rows_fixture()->returnNextGetResultsAs($malformed, 'SELECT `ID`, `post_content`, `post_title`');
    wprism_check_throws(static fn() => physical_rows_observe(), RuntimeException::class, 'malformed driver payload or field set refuses');
}

$db = physical_rows_fixture();
wprism_check_throws(static fn() => PhysicalTableRows::observe(physical_rows_descriptor(), 'outside fixture'), RuntimeException::class,
    'direct physical reader cannot invent an absent database profile');
$db->seedTable('wp_options', [])->setTableEngine('wp_options', 'InnoDB');
wprism_check_throws(static fn() => ProviderDatabaseSession::read_only_snapshot('narrow physical fixture',
    NativeDatabaseProfile::read_only(['wp_options']),
    static fn(): array => PhysicalTableRows::observe(physical_rows_descriptor(), 'narrow physical fixture')),
    RuntimeException::class, 'physical reader cannot widen an existing profile to another table');
$db = physical_rows_fixture();
$baseDigest = physical_rows_observe()['rows_sha256'];
foreach ([['post_title' => ''], ['post_content' => "RAW\0\xff東京"], ['ID' => 8]] as $change) {
    physical_rows_fixture([array_replace(['ID' => 7, 'post_content' => "raw\0\xff東京", 'post_title' => null], $change),
        ['ID' => 19, 'post_content' => 'a:0:{}', 'post_title' => '']]);
    wprism_check($baseDigest !== physical_rows_observe()['rows_sha256'], 'null/string, raw bytes and physical identity each bind the hash');
}
$db = physical_rows_fixture();
physical_rows_observe();
wprism_check(count(array_filter($db->queries(), static fn(string $sql): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/i', $sql) === 1)) === 0,
    'complete physical observation performs no DML or DDL');
wprism_check_summary('regress_physical_table_rows');
