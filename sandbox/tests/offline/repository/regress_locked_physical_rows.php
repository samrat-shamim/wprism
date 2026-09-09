<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/Db.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/PhysicalTableRows.php';

use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\PhysicalTableRows;
use WPrism\TransactionAuthority;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

wprism_check(method_exists(PhysicalTableRows::class, 'observe_locked_selected'), 'locked selected rows are a real shared runtime entry point');
if (!method_exists(PhysicalTableRows::class, 'observe_locked_selected')) wprism_check_summary('locked physical rows');

function locked_physical_index(string $column = 'ID', int $position = 1): array {
    return ['Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => $position,
        'Column_name' => $column, 'Sub_part' => null, 'Index_type' => 'BTREE'];
}

function locked_physical_fixture(): FakeWpdb {
    Db::forget_transaction_tracking();
    WpStore::reset();
    return FakeWpdb::install()->enableInformationSchema()
        ->seedTable('wp_posts', [
            ['ID' => 7, 'post_content' => "binary\0\xff", 'post_title' => null],
            ['ID' => 11, 'post_content' => 'unselected', 'post_title' => 'outside'],
            ['ID' => 19, 'post_content' => 'a:0:{}', 'post_title' => ''],
        ])
        ->setColumns('wp_posts', ['ID' => 'bigint', 'post_content' => 'longtext', 'post_title' => 'text'])
        ->setTableEngine('wp_posts', 'InnoDB')
        ->setIndexes('wp_posts', [locked_physical_index()]);
}

function locked_physical_descriptor(array $changes = []): array {
    return array_replace(['table' => 'wp_posts', 'columns' => ['ID', 'post_content', 'post_title'], 'identity' => ['ID'],
        'max_rows' => 64, 'max_raw_bytes' => 8388608, 'mode' => 'rows'], $changes);
}

function locked_physical_begin(bool $controlled = true): TransactionAuthority {
    $profile = NativeDatabaseProfile::read_only(['wp_posts']);
    if ($controlled) Db::start_repeatable_read('locked physical fixture', $profile);
    else Db::start('plain physical fixture', $profile);
    return Db::transaction_authority('physical fixture authority');
}

function locked_physical_observe(TransactionAuthority $authority, array $tuples = [[7], [19]], array $changes = []): array {
    return PhysicalTableRows::observe_locked_selected(locked_physical_descriptor($changes), $tuples, $authority, 'locked physical fixture');
}

function locked_physical_queries(FakeWpdb $db): array {
    return array_values(array_filter($db->queries(), static fn(string $sql): bool =>
        str_contains($sql, '_wprism_size_') || str_contains($sql, '_wprism_hash_')
        || str_starts_with($sql, 'SELECT `ID`, `post_content`, `post_title` FROM')));
}

/** An independent byte frame; no production hash/normalizer is used. */
function locked_physical_hash(array $descriptor, array $tuples, array $rows): string {
    $string = static fn(string $bytes): string => pack('N', strlen($bytes)) . $bytes;
    $frame = "wprism-physical-table-selected-rows/v1\0" . $string($descriptor['table']);
    foreach (['identity', 'columns'] as $field) {
        $frame .= pack('N', count($descriptor[$field]));
        foreach ($descriptor[$field] as $column) $frame .= $string($column);
    }
    $frame .= 'S' . pack('N', count($tuples));
    foreach ($tuples as $tuple) foreach ($tuple as $id) $frame .= $string((string) $id);
    foreach ($rows as $row) {
        $frame .= 'R';
        foreach ($descriptor['columns'] as $column) $frame .= $row[$column] === null ? "\0" : "\1" . $string($row[$column]);
    }
    return hash('sha256', $frame . 'E' . pack('N', count($rows)));
}

$db = locked_physical_fixture();
$before = $db->rows('wp_posts');
$authority = locked_physical_begin();
$actual = locked_physical_observe($authority);
$expected = [['ID' => '7', 'post_content' => "binary\0\xff", 'post_title' => null],
    ['ID' => '19', 'post_content' => 'a:0:{}', 'post_title' => '']];
wprism_check_same($expected, $actual['rows'], 'only the exact selected positive identities are retained, including binary/null/empty values');
wprism_check_same(locked_physical_hash(locked_physical_descriptor(), [[7], [19]], $expected), $actual['rows_sha256'], 'selected digest domain and tuple binding match independent binary framing');
$digest = $actual; unset($digest['rows']);
wprism_check_same($digest, locked_physical_observe($authority, [[7], [19]], ['mode' => 'digest']), 'digest-only mode has the same selected witness without retained bodies');
wprism_check_same(10, count(locked_physical_queries($db)), 'each observation exercises all five nonempty physical read boundaries');
foreach (locked_physical_queries($db) as $query) {
    wprism_check(str_contains($query, ' FROM `wp_posts` FORCE INDEX (`PRIMARY`) WHERE ')
        && str_ends_with($query, ' LIMIT 3 FOR UPDATE'), 'every size/hash/value and repeated read uses the proven index and current-read locking');
}
wprism_check_same($before, $db->rows('wp_posts'), 'successful selected observation mutates no row');
wprism_check_same([], array_values(array_filter($db->queries(), static fn(string $sql): bool => preg_match('/^(INSERT|UPDATE|DELETE|REPLACE|ALTER|CREATE|DROP)\b/', $sql) === 1)), 'selected observation has no DML/DDL');
$whole = PhysicalTableRows::observe(locked_physical_descriptor(), 'whole fixture');
$allSelected = locked_physical_observe($authority, [[7], [11], [19]]);
wprism_check_same($whole['rows'], $allSelected['rows'], 'selecting every row preserves the whole-table payload');
wprism_check($whole['rows_sha256'] !== $allSelected['rows_sha256'], 'same rows cannot confuse the selected and whole-table digest domains');
Db::rollback('locked physical fixture rollback');
wprism_check_throws(static fn() => locked_physical_observe($authority), RuntimeException::class, 'settled transaction cannot reuse selected-row authority');

$db = locked_physical_fixture(); $authority = locked_physical_begin();
foreach ([[], ['named' => [7]], [7], [[0]], [[-1]], [['7']], [[7.0]], [[true]], [[7, 19]], [[7], [7]], [[19], [7]], [[7], ['named' => 19]], array_map(static fn(int $id): array => [$id], range(1, 65))] as $invalid) {
    $db->resetLog();
    wprism_check_throws(static fn() => locked_physical_observe($authority, $invalid), InvalidArgumentException::class, 'noncanonical or unbounded identity selection refuses');
    wprism_check_same([], locked_physical_queries($db), 'invalid selection consumes no physical rows');
}
wprism_check_throws(static fn() => locked_physical_observe($authority, [[7], [19]], ['max_rows' => 1]), InvalidArgumentException::class, 'caller row budget also bounds selection');
foreach ([[[7], [21]], [[5], [19]], [[7], [11], [19], [21]]] as $missing) {
    wprism_check_throws(static fn() => locked_physical_observe($authority, $missing), RuntimeException::class, 'every requested identity must exist, never an accepted partial selection');
}
Db::rollback('invalid selections rollback');

$db = locked_physical_fixture(); $authority = locked_physical_begin(false);
wprism_check_throws(static fn() => locked_physical_observe($authority), RuntimeException::class, 'plain START does not grant selected current-read authority', 'controlled repeatable-read');
Db::rollback('plain fixture rollback');

$db = locked_physical_fixture(); $authority = locked_physical_begin();
wprism_check_throws(static fn() => locked_physical_observe(new TransactionAuthority('999', str_repeat('a', 64))),
    RuntimeException::class, 'caller-constructed unrelated authority is not the active controlled transaction');
Db::rollback('unrelated authority rollback');

foreach ([['Non_unique' => 1], ['Sub_part' => 2], ['Index_type' => 'HASH'], ['Visible' => 'NO'], ['Column_name' => 'post_content']] as $badIndex) {
    $db = locked_physical_fixture(); $authority = locked_physical_begin();
    $db->setIndexes('wp_posts', [array_replace(locked_physical_index(), $badIndex)]);
    wprism_check_throws(static fn() => locked_physical_observe($authority), RuntimeException::class, 'index proof refuses nonunique, partial, hidden, wrong-type and wrong-column witnesses');
    wprism_check_same([], locked_physical_queries($db), 'invalid index cannot authorize a payload read');
    Db::rollback('invalid index rollback');
}
$db = locked_physical_fixture()->setTableEngine('wp_posts', 'MyISAM');
wprism_check_throws(static fn() => locked_physical_observe(locked_physical_begin()), RuntimeException::class,
    'profile admission refuses MyISAM before it can supply a metadata-lock-bound InnoDB premise');
wprism_check_same([], locked_physical_queries($db), 'unsupported physical engine cannot reach selected payloads');

// Actual row-backed composite selection, including shared leading coordinates.
$db = locked_physical_fixture()->seedTable('wp_posts', [
    ['ID' => 2, 'other_id' => 9, 'post_content' => 'unselected', 'post_title' => 'outside'],
    ['ID' => 9, 'other_id' => 1, 'post_content' => 'selected two', 'post_title' => null],
    ['ID' => 2, 'other_id' => 3, 'post_content' => 'selected one', 'post_title' => ''],
])->setColumns('wp_posts', ['ID' => 'bigint', 'other_id' => 'bigint', 'post_content' => 'longtext', 'post_title' => 'text'])
    ->setIndexes('wp_posts', [locked_physical_index(), locked_physical_index('other_id', 2)]);
$authority = locked_physical_begin();
$tupleChanges = ['columns' => ['ID', 'other_id', 'post_content', 'post_title'], 'identity' => ['ID', 'other_id']];
$tuples = [[2, 3], [9, 1]];
$observed = locked_physical_observe($authority, $tuples, $tupleChanges);
wprism_check_same([['ID' => '2', 'other_id' => '3', 'post_content' => 'selected one', 'post_title' => ''],
    ['ID' => '9', 'other_id' => '1', 'post_content' => 'selected two', 'post_title' => null]], $observed['rows'],
    'compound selection keeps exact tuples rather than a cross-product of individual coordinates');
wprism_check_same(locked_physical_hash(locked_physical_descriptor($tupleChanges), $tuples, $observed['rows']), $observed['rows_sha256'], 'compound identity arity, order and values bind the selected digest');
Db::rollback('compound selection rollback');

$sizeRows = $hashRows = [];
foreach ($expected as $row) {
    $size = $hash = ['_wprism_identity_0' => $row['ID']];
    foreach (['ID', 'post_content', 'post_title'] as $position => $column) {
        $size['_wprism_size_' . $position] = $row[$column] === null ? null : (string) strlen($row[$column]);
        $hash['_wprism_hash_' . $position] = $row[$column] === null ? null : hash('sha256', $row[$column]);
    }
    $sizeRows[] = $size; $hashRows[] = $hash;
}
foreach ([
    'missing' => [], 'extra' => [$sizeRows[0], $sizeRows[1], $sizeRows[1]], 'duplicate' => [$sizeRows[0], $sizeRows[0]],
    'swapped' => array_reverse($sizeRows), 'substituted' => [array_replace($sizeRows[0], ['_wprism_identity_0' => '6']), $sizeRows[1]],
    'numeric-id' => [array_replace($sizeRows[0], ['_wprism_identity_0' => 7]), $sizeRows[1]],
    'padded-id' => [array_replace($sizeRows[0], ['_wprism_identity_0' => '07']), $sizeRows[1]],
    'overflow-id' => [array_replace($sizeRows[0], ['_wprism_identity_0' => '9223372036854775808']), $sizeRows[1]],
    'field-roster' => [array_reverse($sizeRows[0], true), $sizeRows[1]],
    'numeric-size' => [array_replace($sizeRows[0], ['_wprism_size_1' => 8]), $sizeRows[1]],
    'negative-size' => [array_replace($sizeRows[0], ['_wprism_size_1' => '-1']), $sizeRows[1]],
    'huge-cell' => [array_replace($sizeRows[0], ['_wprism_size_1' => '1048577']), $sizeRows[1]],
] as $case => $badRows) {
    $db = locked_physical_fixture(); $authority = locked_physical_begin();
    $db->returnNextGetResultsAs($badRows, '_wprism_size_0');
    wprism_check_throws(static fn() => locked_physical_observe($authority), RuntimeException::class, "$case size roster cannot manufacture an exact selected preimage");
    wprism_check_same(1, count(locked_physical_queries($db)), 'bad size admission stops before payload hash/value allocation');
    Db::rollback('bad size roster rollback');
}

foreach (['hash-missing', 'hash-order', 'hash-null', 'hash-upper', 'value-missing', 'value-order', 'value-bytes', 'value-null', 'value-type'] as $case) {
    $db = locked_physical_fixture(); $authority = locked_physical_begin();
    $rows = str_starts_with($case, 'hash-') ? $hashRows : $expected;
    $matching = str_starts_with($case, 'hash-') ? '_wprism_hash_0' : 'SELECT `ID`, `post_content`, `post_title`';
    if (str_ends_with($case, '-missing')) array_pop($rows);
    elseif (str_ends_with($case, '-order')) $rows = array_reverse($rows);
    elseif ($case === 'hash-null') $rows[0]['_wprism_hash_2'] = hash('sha256', '');
    elseif ($case === 'hash-upper') $rows[0]['_wprism_hash_1'] = strtoupper($rows[0]['_wprism_hash_1']);
    elseif ($case === 'value-bytes') $rows[0]['post_content'] = str_repeat('z', strlen($rows[0]['post_content']));
    elseif ($case === 'value-null') $rows[0]['post_title'] = '';
    elseif ($case === 'value-type') $rows[0]['ID'] = 7;
    $db->returnNextGetResultsAs($rows, $matching);
    wprism_check_throws(static fn() => locked_physical_observe($authority), RuntimeException::class, "$case cannot pass the shared exact byte/shape admission");
    Db::rollback('bad payload rollback');
}

// SQL current-read syntax is independently pinned above; this fake exercises
// driver/authority continuity, not InnoDB MVCC or interprocess scheduling.
foreach (range(1, 5) as $boundary) {
    foreach (['new-connection', 'same-id-reconnect', 'implicit-commit', 'throw', 'leftover-error', 'value-drift'] as $case) {
        $db = locked_physical_fixture(); $authority = locked_physical_begin(); $seen = 0; $fired = false;
        $db->onQuery(static function (string $sql) use ($db, $authority, $boundary, $case, &$seen, &$fired): ?string {
            $physical = str_contains($sql, '_wprism_size_') || str_contains($sql, '_wprism_hash_')
                || str_starts_with($sql, 'SELECT `ID`, `post_content`, `post_title` FROM');
            if (!$physical || ++$seen !== $boundary) return null;
            $fired = true;
            if ($case === 'new-connection') $db->setConnectionId((int) $authority->connection_id() + 1);
            elseif ($case === 'same-id-reconnect') $db->setConnectionId((int) $authority->connection_id());
            elseif ($case === 'implicit-commit') $db->simulateImplicitCommit();
            elseif ($case === 'throw') throw new RuntimeException('strict native transport error');
            elseif ($case === 'leftover-error') $db->last_error = 'compatible leftover error';
            else {
                // The final roster rechecks sizes, not hashes a third time.
                // Real concurrent writes are excluded by the retained row
                // locks; this direct fake-store seam asserts only what each
                // named returned witness can detect.
                $rows = $db->rows('wp_posts'); $rows[0]['post_content'] = $boundary === 5 ? 'changed!growth' : 'changed!';
                $db->seedTable('wp_posts', $rows);
            }
            return null;
        });
        if ($case === 'value-drift' && $boundary <= 2) {
            // A completed edit before the first admitted hash is a coherent
            // current preimage, not a claim that callers froze earlier content.
            $changed = locked_physical_observe($authority);
            wprism_check_same('changed!', $changed['rows'][0]['post_content'], 'complete early current-row edit is observed coherently');
        } else {
            wprism_check_throws(static fn() => locked_physical_observe($authority), RuntimeException::class, "$case at boundary $boundary cannot return a reusable locked preimage");
        }
        wprism_check($fired, 'fault reached its independently numbered physical read boundary');
        $db->onQuery(null);
        if (in_array($case, ['new-connection', 'same-id-reconnect', 'implicit-commit'], true)) {
            Db::connection_transaction_active('lost physical transaction idle proof'); Db::forget_transaction_tracking();
        } else Db::rollback('physical fault rollback');
    }
}

$db = locked_physical_fixture()->seedTable('wp_posts', [['ID' => 7, 'post_content' => str_repeat('x', PhysicalTableRows::MAX_CELL_BYTES), 'post_title' => '']]);
$authority = locked_physical_begin();
wprism_check_same(PhysicalTableRows::MAX_CELL_BYTES + 1, locked_physical_observe($authority, [[7]])['raw_bytes'], 'exact cell frontier is inclusive on selected current reads');
wprism_check_throws(static fn() => locked_physical_observe($authority, [[7]], ['max_raw_bytes' => PhysicalTableRows::MAX_CELL_BYTES]), RuntimeException::class, 'selected aggregate bound refuses before payload allocation');
Db::rollback('cell frontier rollback');

$db = locked_physical_fixture()->seedTable('wp_posts', array_map(static fn(int $id): array => ['ID' => $id, 'post_content' => '', 'post_title' => null], range(64, 1)));
$authority = locked_physical_begin();
wprism_check_same(64, locked_physical_observe($authority, array_map(static fn(int $id): array => [$id], range(1, 64)))['row_count'], 'maximum selected tuple count is inclusive and numerically ordered');
Db::rollback('tuple frontier rollback');

wprism_check_summary('locked physical rows');
