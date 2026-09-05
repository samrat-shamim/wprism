<?php
/** Ledger metadata must remain inside the export's real snapshot/table gate. */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
$sourceRoot = $argv[1] ?? dirname(__DIR__, 4);
require_once $sourceRoot . '/agent/src/Kernel/Uuid.php';
require_once $sourceRoot . '/agent/src/Repository/Ledger.php';
require_once $sourceRoot . '/agent/src/Capture/RefreshExport.php';

use WPrism\DatabaseQueryIsolation;
use WPrism\DatabaseQueryIsolationViolationException;
use WPrism\DatabaseTransactionOutcomeException;
use WPrism\Db;
use WPrism\Ledger;
use WPrism\NativeDatabaseProfile;
use WPrism\RefreshExport;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

function ledger_schema_columns(): array {
    return [
        'wp_wprism_map' => ['uuid' => 'char(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint(20) unsigned'],
        'wp_wprism_state' => ['uuid' => 'varchar(64)', 'entity_type' => 'varchar(64)', 'content_hash' => 'char(64)'],
        'wp_wprism_kv' => ['k' => 'varchar(191)', 'v' => 'longtext'],
    ];
}

function ledger_schema_indexes(): array {
    $row = static fn(string $name, int $sequence, string $column): array => [
        'Key_name' => $name, 'Non_unique' => 0, 'Seq_in_index' => $sequence,
        'Column_name' => $column, 'Sub_part' => null, 'Index_type' => 'BTREE',
    ];
    return [
        'wp_wprism_map' => [
            $row('PRIMARY', 1, 'uuid'), $row('PRIMARY', 2, 'id_kind'),
            $row('kind_local', 1, 'id_kind'), $row('kind_local', 2, 'local_id'),
        ],
        'wp_wprism_state' => [$row('PRIMARY', 1, 'uuid')],
        'wp_wprism_kv' => [$row('PRIMARY', 1, 'k')],
    ];
}

function ledger_schema_fixture(): FakeWpdb {
    Db::forget_transaction_tracking();
    WpStore::reset();
    $db = FakeWpdb::install()->enableInformationSchema();
    $indexes = ledger_schema_indexes();
    foreach (ledger_schema_columns() as $table => $columns) {
        $db->seedTable($table, [])->setColumns($table, $columns)
            ->setIndexes($table, $indexes[$table])->setTableEngine($table, 'InnoDB');
    }
    return $db->seedTable('wp_wprism_map', [[
        'uuid' => '11111111-1111-7111-8111-111111111111',
        'entity_type' => 'post', 'id_kind' => 'post', 'local_id' => 41,
    ]])->seedTable('wp_wprism_kv', [['k' => 'untouched', 'v' => 'retained']]);
}

/** Exercise the actual export snapshot owner, including its exceptional cleanup. */
function ledger_schema_observe(FakeWpdb $db, string $label): array {
    $before = array_map($db->rows(...), array_keys(ledger_schema_columns()));
    $answer = null;
    $failure = null;
    try {
        $answer = (new ReflectionMethod(RefreshExport::class, 'in_read_only_snapshot'))->invoke(
            null,
            NativeDatabaseProfile::read_only(array_keys(ledger_schema_columns())),
            static function (): array {
                Ledger::assert_read_only_schema();
                return ['schema_verified' => true];
            }
        );
    } catch (Throwable $caught) {
        $failure = $caught;
    }
    wprism_check_same($before, array_map($db->rows(...), array_keys(ledger_schema_columns())), "$label never mutates ledger rows");
    wprism_check_same([], array_values(array_filter($db->ddlLog(),
        static fn(string $sql): bool => preg_match('/^(?:CREATE|ALTER|DROP|TRUNCATE)\b/i', $sql) === 1)),
        "$label never repairs the ledger schema");
    if (!$failure instanceof DatabaseTransactionOutcomeException) {
        wprism_check_same(null, $db->transactionIsolationState()['active'], "$label settles the snapshot");
        wprism_check(!DatabaseQueryIsolation::is_active(), "$label releases the query gate");
    }
    return [$answer, $failure];
}

$db = ledger_schema_fixture();
$metadata = [];
$db->onQuery(static function (string $sql) use ($db, &$metadata): ?string {
    if (preg_match('/^SHOW (?:FULL COLUMNS|INDEX) FROM /D', $sql) === 1) {
        $metadata[] = [$sql, DatabaseQueryIsolation::bound_profile_is_read_only(), $db->transactionIsolationState()['active']];
    }
    return null;
});
[$answer, $failure] = ledger_schema_observe($db, 'healthy exact schema');
if (($argv[2] ?? '') === '--expect-prior-profile-refusal') {
    wprism_check_same(null, $answer, 'the actual prior Ledger publishes no answer');
    wprism_check($failure instanceof DatabaseQueryIsolationViolationException,
        'the actual prior Ledger reaches the real active-profile query gate');
    wprism_check_same('wprism: a database table reference is outside the native profile grammar', $failure?->getMessage(),
        'the prior-source failure is the real schema-qualified transport refusal, not a fake width answer');
    wprism_check_same([], $metadata, 'the prior-source refusal occurs before any SHOW proof');
    wprism_check_summary('prior ledger native-profile counterfactual');
}
wprism_check_same(null, $failure?->getMessage(), 'healthy installed schema survives the actual native-profile grammar');
wprism_check_same(['schema_verified' => true], $answer, 'the export snapshot owner returns the schema proof');
$expected = [];
foreach (['FULL COLUMNS', 'INDEX'] as $form) {
    foreach (array_keys(ledger_schema_columns()) as $table) {
        $expected[] = ["SHOW $form FROM `$table`", true, 'REPEATABLE-READ'];
    }
}
wprism_check_same($expected, $metadata, 'all six metadata reads use only the three granted tables inside the original snapshot');
wprism_check_same(1, count(array_filter($db->queries(), static fn(string $sql): bool => $sql === 'ROLLBACK AND NO CHAIN NO RELEASE')),
    'the healthy observer closes the same transaction exactly once');

foreach (['bigint unsigned', 'BIGINT(20) UNSIGNED', 'bigint(20) unsigned zerofill'] as $type) {
    $db = ledger_schema_fixture();
    $columns = ledger_schema_columns()['wp_wprism_map'];
    $columns['local_id'] = $type;
    $db->setColumns('wp_wprism_map', $columns);
    [$answer, $failure] = ledger_schema_observe($db, 'unsigned identity ' . $type);
    wprism_check($failure === null && $answer !== null, 'supported unsigned BIGINT metadata retains identity capacity');
}

$db = ledger_schema_fixture();
foreach (ledger_schema_indexes() as $table => $indexes) {
    $db->setIndexes($table, array_reverse($indexes));
}
[$answer, $failure] = ledger_schema_observe($db, 'unordered SHOW inventory');
wprism_check($failure === null && $answer !== null, 'Seq_in_index rather than transport order proves each ordered UNIQUE tuple');

foreach (ledger_schema_columns() as $table => $columns) {
    foreach ($columns as $column => $type) {
        foreach (['missing', 'narrow'] as $mode) {
            $db = ledger_schema_fixture();
            $changed = $columns;
            if ($mode === 'missing') unset($changed[$column]);
            else {
                preg_match('/\(([0-9]+)\)/', $type, $width);
                $changed[$column] = $column === 'local_id' ? 'bigint(20)'
                    : 'varchar(' . ((int) ($width[1] ?? 1) - 1) . ')';
            }
            $db->setColumnDefinitions($table, array_map(
                static fn(string $field, string $type): array => ['Field' => $field, 'Type' => $type],
                array_keys($changed), array_values($changed)
            ));
            [$answer, $failure] = ledger_schema_observe($db, "$table.$column $mode");
            $expectedReason = $mode === 'missing' ? 'is missing; run the existing capture gate'
                : ($column === 'local_id' ? 'is not an unsigned BIGINT identity' : 'is narrower than the supported durable identity schema');
            wprism_check($answer === null && $failure instanceof RuntimeException
                && str_contains($failure->getMessage(), $expectedReason), "$table.$column $mode retains the precise schema refusal");
        }
    }
}

foreach (['absent', 'non_unique', 'wrong_order', 'prefix', 'extra_column', 'duplicate_position', 'gap', 'inconsistent_unique', 'functional'] as $mode) {
    $db = ledger_schema_fixture();
    $indexes = ledger_schema_indexes()['wp_wprism_map'];
    switch ($mode) {
        case 'absent': $indexes = array_slice($indexes, 2);
        break;
        case 'non_unique': $indexes[0]['Non_unique'] = $indexes[1]['Non_unique'] = 1;
        break;
        case 'wrong_order': $indexes[0]['Column_name'] = 'id_kind';
        $indexes[1]['Column_name'] = 'uuid';
        break;
        case 'prefix': $indexes[0]['Sub_part'] = 1;
        break;
        case 'extra_column': $indexes[] = array_replace($indexes[0], ['Seq_in_index' => 3, 'Column_name' => 'local_id']);
        break;
        case 'duplicate_position': $indexes[] = $indexes[0];
        break;
        case 'gap': $indexes[1]['Seq_in_index'] = 3;
        break;
        case 'inconsistent_unique': $indexes[1]['Non_unique'] = 1;
        break;
        case 'functional': $indexes[0]['Column_name'] = null;
        $indexes[0]['Expression'] = 'lower(uuid)';
        break;
    }
    $db->setIndexes('wp_wprism_map', $indexes);
    [$answer, $failure] = ledger_schema_observe($db, "$mode index");
    wprism_check($answer === null && $failure instanceof RuntimeException, "$mode cannot stand in for an exact ordered full-column UNIQUE identity");
}

foreach ($expected as [$sql]) {
    foreach (['failure', 'false', 'null', 'non_list', 'scalar_row', 'array_value', 'oversized_rows', 'oversized_bytes'] as $mode) {
        $db = ledger_schema_fixture();
        if ($mode === 'failure') $db->failNextQuery('private driver diagnostic must not leak', $sql);
        else $db->returnNextGetResultsAs(match ($mode) {
            'false' => false,
            'null' => null,
            'non_list' => [1 => ['Field' => 'uuid']],
            'scalar_row' => ['invalid'],
            'array_value' => [['Field' => ['uuid']]],
            'oversized_rows' => array_fill(0, 1025, ['Field' => 'uuid']),
            'oversized_bytes' => [['Field' => 'uuid', 'Comment' => str_repeat('x', 4194305)]],
        }, $sql);
        [$answer, $failure] = ledger_schema_observe($db, "$sql $mode");
        $context = str_contains($sql, 'COLUMNS') ? 'schema' : 'index';
        wprism_check($answer === null && $failure instanceof RuntimeException
            && $failure->getMessage() === "wprism: ledger read failed: read-only ledger $context inventory",
            "$sql $mode refuses value-free before any schema proof is published");
    }
}

foreach (['varchar(1)', 'varchar(1024)', 'tinytext', 'text', 'mediumtext', 'longtext'] as $type) {
    $db = ledger_schema_fixture()->setColumns('wp_wprism_kv', ['k' => 'varchar(191)', 'v' => $type]);
    [$answer, $failure] = ledger_schema_observe($db, "$type value capacity");
    wprism_check($answer !== null && $failure === null, 'supported nonempty value capacity does not require one exact DDL spelling');
}

foreach (['duplicate_column', 'missing_type', 'array_type', 'missing_index_prefix', 'invalid_index_sequence', 'invalid_index_unique', 'foreign_index_table'] as $mode) {
    $db = ledger_schema_fixture();
    if (str_contains($mode, 'column') || str_contains($mode, 'type')) {
        $rows = [['Field' => 'uuid', 'Type' => 'char(36)']];
        if ($mode === 'duplicate_column') $rows[] = $rows[0];
        elseif ($mode === 'missing_type') unset($rows[0]['Type']);
        else $rows[0]['Type'] = ['char(36)'];
        $db->returnNextGetResultsAs($rows, 'SHOW FULL COLUMNS FROM `wp_wprism_map`');
    } else {
        $rows = ledger_schema_indexes()['wp_wprism_map'];
        if ($mode === 'missing_index_prefix') unset($rows[0]['Sub_part']);
        elseif ($mode === 'invalid_index_sequence') $rows[0]['Seq_in_index'] = '1junk';
        elseif ($mode === 'invalid_index_unique') $rows[0]['Non_unique'] = '0junk';
        else $rows[0]['Table'] = 'wp_posts';
        $db->returnNextGetResultsAs($rows, 'SHOW INDEX FROM `wp_wprism_map`');
    }
    [$answer, $failure] = ledger_schema_observe($db, $mode);
    wprism_check($answer === null && $failure instanceof RuntimeException
        && str_starts_with($failure->getMessage(), 'wprism: ledger read failed:'),
        "$mode cannot manufacture a schema fact");
}

$db = ledger_schema_fixture()->failNextQuery('private nonthrowing read error', 'SHOW FULL COLUMNS');
wprism_check_throws(static fn() => Ledger::assert_read_only_schema(), RuntimeException::class,
    'outside strict transport wpdb last_error still refuses an empty failed metadata read',
    'wprism: ledger read failed: read-only ledger schema inventory');

$db = ledger_schema_fixture();
$db->onQuery(static function (string $sql) use ($db): ?string {
    if ($sql === 'SHOW INDEX FROM `wp_wprism_kv`') $db->setConnectionId(99);
    return null;
});
[$answer, $failure] = ledger_schema_observe($db, 'reconnected metadata transport');
wprism_check($answer === null && $failure instanceof DatabaseTransactionOutcomeException,
    'a replacement session cannot publish the metadata proof as the original snapshot');
wprism_check_throws(static fn() => Db::forget_transaction_tracking(), DatabaseTransactionOutcomeException::class,
    'the observer cannot discard unresolved replacement-session authority');

wprism_check_summary('ledger read-only schema under the export profile');
