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
        'table' => 'wp_posts', 'columns' => ['ID', 'post_content', 'post_title'], 'identity' => ['ID'],
        'max_rows' => 128, 'max_raw_bytes' => 8388608, 'mode' => 'rows',
    ], $changes);
}

function physical_rows_observe(array $changes = []): array {
    $descriptor = physical_rows_descriptor($changes);
    return ProviderDatabaseSession::read_only_snapshot('physical rows fixture',
        NativeDatabaseProfile::read_only([$descriptor['table']]),
        static fn(): array => PhysicalTableRows::observe($descriptor, 'physical rows fixture'));
}

/** Independent framing oracle: no serialized PHP arrays or canonical JSON can select the byte meaning. */
function physical_rows_expected_hash(array $descriptor, array $rows): string {
    $frame = "wprism-physical-table-rows/v1\0";
    $frame .= pack('N', strlen($descriptor['table'])) . $descriptor['table'];
    $frame .= pack('N', count($descriptor['identity']));
    foreach ($descriptor['identity'] as $name) $frame .= pack('N', strlen($name)) . $name;
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

// A 32-column table reaches MAX_CELLS at 8,192 rows, before MAX_ROWS.
// Admission must bound the driver transfer, not merely refuse the allocated roster.
$wideColumns = ['ID'];
for ($index = 1; $index < PhysicalTableRows::MAX_COLUMNS; $index++) $wideColumns[] = 'column_' . $index;
$wideRows = [];
for ($id = 1; $id <= 8193; $id++) $wideRows[] = array_replace(array_fill_keys($wideColumns, null), ['ID' => $id]);
$db = physical_rows_fixture($wideRows)->setColumns('wp_posts', array_fill_keys($wideColumns, 'bigint'));
wprism_check_throws(static fn() => physical_rows_observe(['columns' => $wideColumns, 'max_rows' => PhysicalTableRows::MAX_ROWS]),
    RuntimeException::class, 'cell frontier refuses the complete over-budget roster', 'cell-count budget exceeded');
$sizeQueries = array_values(array_filter($db->queries(), static fn(string $sql): bool => str_contains($sql, ' AS _wprism_size_0')));
wprism_check_same(1, count($sizeQueries), 'wide-table refusal performs just one size admission');
wprism_check(str_ends_with($sizeQueries[0], ' LIMIT 8193'), 'cell frontier constrains the initial query to its 8,192 rows plus one overflow witness');
wprism_check(count(array_filter($db->queries(), static fn(string $sql): bool => str_contains($sql, ' AS _wprism_hash_'))) === 0,
    'cell-count overflow never hashes target payloads');
unset($wideRows);

$tupleDescriptor = ['table' => 'wp_term_relationships', 'columns' => ['object_id', 'term_taxonomy_id', 'term_order'],
    'identity' => ['object_id', 'term_taxonomy_id']];
$tupleExpected = [
    ['object_id' => '2', 'term_taxonomy_id' => '3', 'term_order' => '0'],
    ['object_id' => '2', 'term_taxonomy_id' => '100', 'term_order' => '1'],
    ['object_id' => '9', 'term_taxonomy_id' => '11', 'term_order' => '0'],
    ['object_id' => '10', 'term_taxonomy_id' => '1', 'term_order' => '0'],
];
$tupleFixture = static function (array $rows) use ($tupleDescriptor): FakeWpdb {
    // FakeWpdb derives comparison types from stored PHP scalars. Native bigint
    // coordinates are integers in the fixture and strings only at driver egress.
    foreach ($rows as &$row) {
        foreach ($tupleDescriptor['identity'] as $column) {
            if (is_string($row[$column]) && (string) (int) $row[$column] === $row[$column]) $row[$column] = (int) $row[$column];
        }
    }
    unset($row);
    return physical_rows_fixture()->seedTable('wp_term_relationships', $rows)
        ->setColumns('wp_term_relationships', array_fill_keys($tupleDescriptor['columns'], 'bigint'))
        ->setTableEngine('wp_term_relationships', 'InnoDB');
};
$tupleFixture(array_reverse($tupleExpected));
$tupleObserved = physical_rows_observe($tupleDescriptor);
wprism_check_same($tupleExpected, $tupleObserved['rows'], 'composite physical identities use numeric lexicographic order and retain shared first coordinates');
wprism_check_same(physical_rows_expected_hash(physical_rows_descriptor($tupleDescriptor), $tupleExpected),
    $tupleObserved['rows_sha256'], 'composite identity arity and each exact name bind the independent binary-frame oracle');
$reversedIdentity = physical_rows_observe(array_replace($tupleDescriptor, ['identity' => array_reverse($tupleDescriptor['identity'])]));
wprism_check($tupleObserved['rows_sha256'] !== $reversedIdentity['rows_sha256'], 'identity order is part of the physical witness');
$tupleFixture([$tupleExpected[0], $tupleExpected[0]]);
wprism_check_throws(static fn() => physical_rows_observe($tupleDescriptor), RuntimeException::class,
    'duplicate complete identity tuple refuses', 'duplicate or unordered');
foreach (['0', '-1', '03', '3.0', '3e0', '9223372036854775808', str_repeat('9', 30), null] as $badId) {
    $db = $tupleFixture([array_replace($tupleExpected[0], ['term_taxonomy_id' => $badId])]);
    wprism_check_throws(static fn() => physical_rows_observe($tupleDescriptor), RuntimeException::class,
        'every composite coordinate must be a bounded canonical positive integer: ' . json_encode($badId));
    wprism_check(count(array_filter($db->queries(), static fn(string $sql): bool => str_contains($sql, ' AS _wprism_hash_'))) === 0,
        'malformed second identity coordinate cannot reach hashing');
}
$tupleSizes = $tupleHashes = [];
foreach ($tupleExpected as $row) {
    $size = $hash = ['_wprism_identity_0' => $row['object_id'], '_wprism_identity_1' => $row['term_taxonomy_id']];
    foreach ($tupleDescriptor['columns'] as $index => $column) {
        $size['_wprism_size_' . $index] = (string) strlen($row[$column]);
        $hash['_wprism_hash_' . $index] = hash('sha256', $row[$column]);
    }
    $tupleSizes[] = $size;
    $tupleHashes[] = $hash;
}
$tupleFixture($tupleExpected)->returnNextGetResultsAs([$tupleSizes[1], $tupleSizes[0], $tupleSizes[2], $tupleSizes[3]], ' AS _wprism_size_0');
wprism_check_throws(static fn() => physical_rows_observe($tupleDescriptor), RuntimeException::class,
    'driver ordering that reverses only the second coordinate refuses', 'duplicate or unordered');
$tupleFixture($tupleExpected)->returnNextGetResultsAs([$tupleSizes[0], $tupleSizes[1], $tupleSizes[3], $tupleSizes[2]], ' AS _wprism_size_0');
wprism_check_throws(static fn() => physical_rows_observe($tupleDescriptor), RuntimeException::class,
    'driver ordering that reverses the first coordinate refuses', 'duplicate or unordered');
$tupleHashes[0]['_wprism_identity_1'] = '4';
$tupleFixture($tupleExpected)->returnNextGetResultsAs($tupleHashes, ' AS _wprism_hash_0');
wprism_check_throws(static fn() => physical_rows_observe($tupleDescriptor), RuntimeException::class,
    'hash roster must retain every identity coordinate', 'changed identities');
$changedTupleValues = $tupleExpected;
$changedTupleValues[0]['term_taxonomy_id'] = '4';
$tupleFixture($tupleExpected)->returnNextGetResultsAs($changedTupleValues, 'SELECT `object_id`, `term_taxonomy_id`, `term_order`');
wprism_check_throws(static fn() => physical_rows_observe($tupleDescriptor), RuntimeException::class,
    'payload roster must retain every identity coordinate', 'changed identities');
$manyTuples = [];
for ($id = 1; $id <= 130; $id++) $manyTuples[] = ['object_id' => (string) (1 + intdiv($id - 1, 65)),
    'term_taxonomy_id' => (string) (1 + ($id - 1) % 65), 'term_order' => '0'];
$tupleFixture(array_reverse($manyTuples));
wprism_check_same($manyTuples, physical_rows_observe(array_replace($tupleDescriptor, ['max_rows' => 130]))['rows'],
    'composite identities retain colliding first coordinates across all three transfer batches');

$invalid = [
    ['table' => 'wp_posts; DELETE'], ['columns' => []], ['columns' => ['ID', 'id']],
    ['columns' => ['ID', 'post_content AS stolen']], ['columns' => [7]],
    ['columns' => array_merge($wideColumns, ['column_33'])],
    ['columns' => ['post_content']], ['identity' => ['ID DESC']], ['identity' => 'ID'],
    ['identity' => []], ['identity' => ['ID', 'ID']], ['identity' => ['id']],
    ['identity' => [7]], ['identity' => [1 => 'ID']],
    ['identity' => ['ID', 'post_content', 'post_title', 'four', 'five']],
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

$largeColumns = ['ID', 'a', 'b', 'c', 'd'];
$largeCell = str_repeat('z', PhysicalTableRows::MAX_CELL_BYTES);
$largeRow = ['ID' => 1, 'a' => $largeCell, 'b' => $largeCell, 'c' => $largeCell, 'd' => substr($largeCell, 1)];
$largeDescriptor = ['columns' => $largeColumns, 'max_raw_bytes' => PhysicalTableRows::MAX_RAW_BYTES, 'mode' => 'digest'];
$db = physical_rows_fixture([$largeRow])->setColumns('wp_posts', array_fill_keys($largeColumns, 'longtext'));
wprism_check_same(4194304, physical_rows_observe($largeDescriptor)['raw_bytes'], 'one exactly 4 MiB row fits the bounded batch');
$db = physical_rows_fixture([array_replace($largeRow, ['d' => $largeCell])])->setColumns('wp_posts', array_fill_keys($largeColumns, 'longtext'));
wprism_check_throws(static fn() => physical_rows_observe($largeDescriptor), RuntimeException::class,
    'a row with individually valid cells but 4 MiB plus one byte refuses', 'bounded transfer size');
wprism_check(count(array_filter($db->queries(), static fn(string $sql): bool => str_contains($sql, ' AS _wprism_hash_'))) === 0,
    'per-row transfer overflow is refused before field hashing');
$largeRows = [];
for ($id = 1; $id <= 8; $id++) $largeRows[] = array_replace($largeRow, ['ID' => $id]);
$db = physical_rows_fixture($largeRows)->setColumns('wp_posts', array_fill_keys($largeColumns, 'longtext'));
$largeDigest = physical_rows_observe($largeDescriptor);
wprism_check_same(PhysicalTableRows::MAX_RAW_BYTES, $largeDigest['raw_bytes'], 'the exact 32 MiB aggregate frontier is inclusive');
wprism_check(!array_key_exists('rows', $largeDigest), 'maximum aggregate digest mode retains no payload in its result');
wprism_check_same(16, count(array_filter($db->queries(), static fn(string $sql): bool => str_contains($sql, ' AS _wprism_hash_0'))),
    'eight maximum-byte batches are bounded independently of the 64-row frontier');
$largeRows[] = ['ID' => 9, 'a' => null, 'b' => null, 'c' => null, 'd' => null];
$db = physical_rows_fixture($largeRows)->setColumns('wp_posts', array_fill_keys($largeColumns, 'longtext'));
wprism_check_throws(static fn() => physical_rows_observe($largeDescriptor), RuntimeException::class,
    'the 32 MiB aggregate plus one identity byte refuses before hashing', 'byte budget');
wprism_check(count(array_filter($db->queries(), static fn(string $sql): bool => str_contains($sql, ' AS _wprism_hash_'))) === 0,
    'aggregate byte overflow cannot reach field hashing');
unset($largeRows, $largeRow, $largeCell);

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
