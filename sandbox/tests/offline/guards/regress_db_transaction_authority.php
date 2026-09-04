<?php
/**
 * Offline proof for Db's physical-session and original-transaction authority.
 *
 * CONNECTION_ID() is reusable, wpdb may reconnect/replay a data-free control,
 * and savepoint controls can lose their client response. These cases pin the
 * fail-closed boundary without pretending that a SQL nonce predicate itself
 * proves an active transaction; real MySQL/MariaDB execute that predicate in
 * autocommit when the same session survives.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/TransientDbException.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/NativeDatabaseProfile.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/NativeTableDefinition.php';
require_once __DIR__ . '/../../../../agent/src/Kernel/Db.php';

use WPrism\DatabaseMutationException;
use WPrism\DatabaseQueryIsolationViolationException;
use WPrism\DatabaseTransactionOutcomeException;
use WPrism\DeadlockTransactionAbortedException;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\NativeTableDefinition;
use WPrism\TransactionAuthority;
use WPrism\TransientDbException;
use WPrismTest\FakeWpdb;

function db_authority_fixture(): FakeWpdb {
    Db::forget_transaction_tracking();
    return FakeWpdb::install()
        ->seedTable('wp_wprism_kv', [])
        ->setUniqueKey('wp_wprism_kv', ['k'])
        ->enableInformationSchema();
}

/** @param list<string> $reads */
function db_authority_profile(array $reads = [], bool $writeKv = false): NativeDatabaseProfile {
    return new NativeDatabaseProfile($reads, $writeKv ? ['wp_wprism_kv'] : []);
}

/** @param callable():mixed $operation */
function db_authority_failure(callable $operation): ?Throwable {
    try {
        $operation();
    } catch (Throwable $failure) {
        return $failure;
    }
    return null;
}

function db_authority_guarded_insert(
    FakeWpdb $wpdb,
    TransactionAuthority $authority,
    string $key,
    string $value
): string {
    return $wpdb->prepare(
        "INSERT INTO wp_wprism_kv (k, v)\n"
            . "SELECT %s, %s\n"
            . "WHERE CONNECTION_ID() = %s\n"
            . "AND BINARY @wprism_tx_session = BINARY %s\n"
            . 'ON DUPLICATE KEY UPDATE v = VALUES(v)',
        $key,
        $value,
        $authority->connection_id(),
        $authority->session_nonce()
    );
}

function db_authority_guarded_delete(
    FakeWpdb $wpdb,
    TransactionAuthority $authority,
    string $key
): string {
    return $wpdb->prepare(
        "DELETE FROM wp_wprism_kv\n"
            . "WHERE k = %s AND CONNECTION_ID() = %s\n"
            . 'AND BINARY @wprism_tx_session = BINARY %s',
        $key,
        $authority->connection_id(),
        $authority->session_nonce()
    );
}

$wpdb = db_authority_fixture();
$missingProfile = db_authority_failure(
    static fn() => Db::start('missing database profile')
);
wprism_check(
    $missingProfile instanceof ArgumentCountError
        && !in_array('START TRANSACTION', $wpdb->queries(), true),
    'every transaction owner must supply an explicit physical-table profile before START'
);

// Engine DDL is a closed definition boundary rather than a raw SQL escape.
// Definitions render completely before target contact, and one bounded set of
// idempotent CREATEs shares an idle physical-session witness without START.
$nativeDefinition = new NativeTableDefinition([
    'id' => [
        'type' => 'bigint',
        'unsigned' => true,
        'auto_increment' => true,
        'nullable' => false,
    ],
    'label' => [
        'type' => 'varchar',
        'length' => 8,
        'nullable' => false,
        'default' => '',
    ],
], ['id'], [], ['label_lookup' => ['label']]);
wprism_check_same(
    "CREATE TABLE IF NOT EXISTS `wp_wprism_schema_a` (\n"
        . "    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,\n"
        . "    `label` VARCHAR(8) NOT NULL DEFAULT '',\n"
        . "    PRIMARY KEY (`id`),\n"
        . "    KEY `label_lookup` (`label`)\n"
        . ') ENGINE=InnoDB DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci',
    $nativeDefinition->create_sql(
        'wp_wprism_schema_a',
        'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
    ),
    'native table definitions render one exact InnoDB statement from typed fields and indexes'
);
foreach ([
    'unsafe charset metadata' => static fn() => $nativeDefinition->create_sql(
        'wp_wprism_schema_a',
        'DEFAULT CHARACTER SET utf8mb4; DROP TABLE wp_posts'
    ),
    'nullable primary key' => static fn() => new NativeTableDefinition([
        'id' => ['type' => 'bigint', 'nullable' => true],
    ], ['id']),
    'unindexed auto increment' => static fn() => new NativeTableDefinition([
        'id' => ['type' => 'bigint', 'auto_increment' => true, 'nullable' => false],
        'key' => ['type' => 'varchar', 'length' => 8, 'nullable' => false],
    ], ['key']),
] as $label => $invalidDefinition) {
    wprism_check(
        db_authority_failure($invalidDefinition) instanceof InvalidArgumentException,
        "$label is refused by the closed native table grammar"
    );
}

$wpdb = db_authority_fixture();
$queryHookBefore = new \stdClass();
$allHookBefore = new \stdClass();
$GLOBALS['wp_filter'] = ['query' => $queryHookBefore, 'all' => $allHookBefore];
$wpdb->resetLog();
Db::ensure_tables([
    'wp_wprism_schema_a' => $nativeDefinition,
    'wp_wprism_schema_b' => new NativeTableDefinition([
        'k' => ['type' => 'varchar', 'length' => 191, 'nullable' => false],
        'v' => ['type' => 'longtext', 'nullable' => true],
    ], ['k']),
], $wpdb->get_charset_collate(), 'typed native schema');
$schemaQueries = $wpdb->queries();
$schemaCreates = array_values(array_filter(
    $schemaQueries,
    static fn(string $query): bool => str_starts_with($query, 'CREATE TABLE IF NOT EXISTS')
));
wprism_check(
    count($schemaCreates) === 2
        && count(array_filter(
            $schemaQueries,
            static fn(string $query): bool => str_starts_with($query, 'SET @wprism_tx_session =')
        )) === 1
        && !in_array('START TRANSACTION', $schemaQueries, true)
        && ($GLOBALS['wp_filter']['query'] ?? null) === $queryHookBefore
        && ($GLOBALS['wp_filter']['all'] ?? null) === $allHookBefore,
    'a native table batch uses one idle session authority and restores exact WordPress hooks'
);
unset($GLOBALS['wp_filter']);

$wpdb = db_authority_fixture();
$wpdb->resetLog();
$foreignSchema = db_authority_failure(static fn() => Db::ensure_tables([
    'wp_plugin_state' => $nativeDefinition,
], $wpdb->get_charset_collate(), 'foreign schema refusal'));
wprism_check(
    $foreignSchema instanceof InvalidArgumentException && $wpdb->queries() === [],
    'typed schema reconciliation cannot target a plugin-owned table or contact the database'
);

$wpdb = db_authority_fixture()->setColumnDefinitions('wp_wprism_schema_width', [
    'name' => [
        'Type' => 'varchar(32)',
        'Null' => 'NO',
        'Default' => null,
        'Extra' => '',
    ],
]);
$wpdb->resetLog();
$widened = Db::ensure_varchar_column_width(
    'wp_wprism_schema_width',
    'name',
    64,
    false,
    'typed native width'
);
$widenQueries = $wpdb->queries();
$wpdb->resetLog();
$alreadyWide = Db::ensure_varchar_column_width(
    'wp_wprism_schema_width',
    'name',
    64,
    false,
    'typed native width no-op'
);
wprism_check(
    $widened
        && !$alreadyWide
        && count(array_filter(
            $widenQueries,
            static fn(string $query): bool => $query
                === 'ALTER TABLE `wp_wprism_schema_width` MODIFY COLUMN `name` VARCHAR(64) NOT NULL'
        )) === 1
        && array_filter(
            $wpdb->queries(),
            static fn(string $query): bool => str_starts_with($query, 'ALTER TABLE')
        ) === [],
    'native VARCHAR reconciliation widens once from fresh metadata and never narrows or repeats DDL'
);

$wpdb = db_authority_fixture();
Db::start('schema-during-transaction start', db_authority_profile(writeKv: true));
$schemaDuringTransaction = db_authority_failure(static fn() => Db::ensure_tables([
    'wp_wprism_schema_a' => $nativeDefinition,
], $wpdb->get_charset_collate(), 'schema during transaction'));
wprism_check(
    $schemaDuringTransaction instanceof DatabaseTransactionOutcomeException
        && Db::transaction_active('schema-during-transaction continuity'),
    'native DDL is refused without disturbing an existing authored transaction'
);
Db::rollback('schema-during-transaction rollback');

$wpdb = db_authority_fixture()->failNextQuery(
    'injected native DDL failure',
    'CREATE TABLE IF NOT EXISTS `wp_wprism_schema_failed`'
);
$schemaFailure = db_authority_failure(static fn() => Db::ensure_tables([
    'wp_wprism_schema_failed' => $nativeDefinition,
], $wpdb->get_charset_collate(), 'failed typed native schema'));
Db::start('post-schema-failure start', db_authority_profile());
$postSchemaFailureActive = Db::transaction_active('post-schema-failure continuity');
Db::rollback('post-schema-failure rollback');
wprism_check(
    $schemaFailure instanceof DatabaseMutationException && $postSchemaFailureActive,
    'a same-session DDL failure restores isolation and leaves the next transaction boundary usable'
);

// The shared fake must preserve the destructive semantics that exposed this
// boundary: ROLLBACK TO rewinds row state to the named savepoint, while
// RELEASE of an older savepoint removes every later one.
$wpdb = db_authority_fixture();
$wpdb->query('START TRANSACTION');
$wpdb->query('SAVEPOINT `fake_before_write`');
$wpdb->insert('wp_wprism_kv', ['k' => 'fake-write', 'v' => 'must-rewind']);
$wpdb->query('ROLLBACK TO SAVEPOINT `fake_before_write`');
wprism_check_same(
    [],
    $wpdb->rows('wp_wprism_kv'),
    'the shared fake rewinds row DML on ROLLBACK TO SAVEPOINT like InnoDB'
);
$wpdb->query('SAVEPOINT `fake_later`');
$wpdb->query('RELEASE SAVEPOINT `fake_before_write`');
wprism_check_same(
    false,
    $wpdb->query('RELEASE SAVEPOINT `fake_later`'),
    'the shared fake removes later savepoints when an older savepoint is released'
);
$wpdb->query('ROLLBACK AND NO CHAIN NO RELEASE');

// Batch insertion is a structured engine primitive, not a raw-SQL escape.
// Validate the complete shape before START so a malformed later row cannot
// leave an earlier row pending in a caller-owned transaction.
$wpdb = db_authority_fixture();
$wpdb->resetLog();
$malformedBatch = db_authority_failure(static fn() => Db::insert_rows(
    'wp_wprism_kv',
    [
        ['k' => 'first', 'v' => 'one'],
        ['v' => 'two', 'k' => 'second'],
    ],
    ['%s', '%s'],
    'malformed ordered batch'
));
wprism_check(
    $malformedBatch instanceof InvalidArgumentException && $wpdb->queries() === [],
    'a malformed later insert row is refused before transaction or data transport'
);
$oversizedBatch = db_authority_failure(static fn() => Db::insert_rows(
    'wp_wprism_kv',
    array_fill(0, 257, ['k' => 'bounded', 'v' => 'row']),
    ['%s', '%s'],
    'oversized batch'
));
wprism_check(
    $oversizedBatch instanceof InvalidArgumentException && $wpdb->queries() === [],
    'structured batch insertion enforces its fixed 256-row boundary before START'
);

$wpdb = db_authority_fixture();
$wpdb->resetLog();
$batchAffected = Db::insert_rows(
    'wp_wprism_kv',
    [
        ['k' => 'standalone-first', 'v' => 'one'],
        ['k' => 'standalone-second', 'v' => 'two'],
    ],
    ['%s', '%s'],
    'standalone structured batch'
);
$batchQueries = $wpdb->queries();
$batchInserts = array_values(array_filter(
    $batchQueries,
    static fn(string $query): bool => str_starts_with($query, 'INSERT INTO `wp_wprism_kv`')
));
wprism_check(
    $batchAffected === 2
        && $wpdb->rows('wp_wprism_kv') === [
            ['k' => 'standalone-first', 'v' => 'one'],
            ['k' => 'standalone-second', 'v' => 'two'],
        ]
        && count($batchInserts) === 2
        && count(array_filter(
            $batchQueries,
            static fn(string $query): bool => $query === 'START TRANSACTION'
        )) === 1
        && count(array_filter(
            $batchQueries,
            static fn(string $query): bool => $query === 'COMMIT AND NO CHAIN NO RELEASE'
        )) === 1,
    'a standalone row batch publishes through one profiled all-or-nothing transaction'
);

$wpdb = db_authority_fixture()->failNextQuery(
    'injected second batch row failure',
    "SELECT 'standalone-failing-second', 'two'"
);
$failedBatch = db_authority_failure(static fn() => Db::insert_rows(
    'wp_wprism_kv',
    [
        ['k' => 'standalone-failing-first', 'v' => 'one'],
        ['k' => 'standalone-failing-second', 'v' => 'two'],
    ],
    ['%s', '%s'],
    'failing standalone structured batch'
));
wprism_check(
    $failedBatch instanceof DatabaseMutationException
        && $wpdb->rows('wp_wprism_kv') === []
        && $wpdb->activeTransactionIsolation() === null,
    'a later standalone batch failure rolls back every earlier row and settles the transaction'
);

$wpdb = db_authority_fixture();
Db::start('caller-owned batch start', db_authority_profile(writeKv: true));
$batchAuthority = Db::transaction_authority('caller-owned batch authority');
$wpdb->resetLog();
$ownedBatchAffected = Db::insert_rows(
    'wp_wprism_kv',
    [
        ['k' => 'owned-first', 'v' => 'one'],
        ['k' => 'owned-second', 'v' => 'two'],
    ],
    ['%s', '%s'],
    'caller-owned structured batch'
);
$ownedBatchQueries = $wpdb->queries();
$ownedBatchInserts = array_values(array_filter(
    $ownedBatchQueries,
    static fn(string $query): bool => str_starts_with($query, 'INSERT INTO `wp_wprism_kv`')
));
wprism_check(
    $ownedBatchAffected === 2
        && count($ownedBatchInserts) === 2
        && !in_array('START TRANSACTION', $ownedBatchQueries, true)
        && array_reduce(
            $ownedBatchInserts,
            static fn(bool $same, string $query): bool => $same
                && str_contains($query, "BINARY '{$batchAuthority->session_nonce()}'"),
            true
        ),
    'a caller-owned batch retains one exact transaction authority without nesting START'
);
Db::rollback('caller-owned batch rollback');
wprism_check_same(
    [],
    $wpdb->rows('wp_wprism_kv'),
    'the caller remains the terminal authority for an in-transaction row batch'
);

$wpdb = db_authority_fixture()->seedTable('wp_wprism_kv', [
    ['k' => 'first-observation', 'v' => 'one'],
    ['k' => 'second-observation', 'v' => 'two'],
]);
$wpdb->resetLog();
$cleared = Db::delete_all('wp_wprism_kv', 'clear bounded table');
$clearQueries = $wpdb->queries();
wprism_check(
    $cleared === 2
        && $wpdb->rows('wp_wprism_kv') === []
        && count(array_filter(
            $clearQueries,
            static fn(string $query): bool => str_starts_with($query, 'DELETE FROM `wp_wprism_kv`')
                && str_contains($query, 'CONNECTION_ID()')
        )) === 1
        && in_array('START TRANSACTION', $clearQueries, true)
        && in_array('COMMIT AND NO CHAIN NO RELEASE', $clearQueries, true),
    'whole-table clearing is profiled, authority-guarded DML rather than implicit-commit TRUNCATE'
);
$unsafeClear = db_authority_failure(
    static fn() => Db::delete_all('wp_wprism_kv; DROP TABLE wp_posts', 'unsafe table clear')
);
wprism_check(
    $unsafeClear instanceof InvalidArgumentException,
    'whole-table clearing accepts one physical identifier rather than raw SQL'
);

// A single-target DELETE may read a separately-proven source through a
// correlated NOT EXISTS. The closed profile parser must admit that exact
// anti-join without reopening MySQL's multi-target DELETE grammar.
$antiJoinCondition = "k = 'stale' AND NOT EXISTS ("
    . 'SELECT 1 FROM `wp_posts` src '
    . 'WHERE src.`ID` = `wp_wprism_kv`.`v`)';
$wpdb = db_authority_fixture()
    ->seedTable('wp_posts', [['ID' => 1]])
    ->acknowledgeNextQueryWithoutExecution('NOT EXISTS');
$wpdb->resetLog();
$antiJoinResult = Db::mutation(
    'DELETE FROM `wp_wprism_kv`',
    $antiJoinCondition,
    '',
    'profiled single-target anti-join delete',
    ['wp_posts']
);
wprism_check(
    $antiJoinResult === 1
        && array_filter(
            $wpdb->queries(),
            static fn(string $query): bool => str_contains($query, 'NOT EXISTS')
                && str_contains($query, 'FROM `wp_posts` src')
        ) !== [],
    'a declared correlated NOT EXISTS source crosses the profiled single-target DELETE boundary'
);

$wpdb = db_authority_fixture()->seedTable('wp_posts', [['ID' => 1]]);
$undeclaredAntiJoin = db_authority_failure(static fn() => Db::mutation(
    'DELETE FROM `wp_wprism_kv`',
    $antiJoinCondition,
    '',
    'undeclared anti-join delete'
));
wprism_check(
    $undeclaredAntiJoin instanceof DatabaseQueryIsolationViolationException
        && $wpdb->rows('wp_wprism_kv') === []
        && $wpdb->activeTransactionIsolation() === null,
    'an undeclared anti-join source is refused before DML and its standalone transaction is settled'
);

$wpdb = db_authority_fixture()->seedTable('wp_posts', [['ID' => 1]]);
$multiTargetDelete = db_authority_failure(static fn() => Db::mutation(
    'DELETE target FROM `wp_wprism_kv` target '
        . 'JOIN `wp_posts` src ON src.`ID` = target.`v`',
    "target.`k` = 'stale'",
    '',
    'multi-target delete refusal',
    ['wp_posts']
));
wprism_check(
    $multiTargetDelete instanceof DatabaseQueryIsolationViolationException
        && $wpdb->rows('wp_wprism_kv') === []
        && $wpdb->activeTransactionIsolation() === null,
    'declaring every table does not reopen arbitrary or multi-target DELETE grammar'
);

$wpdb = db_authority_fixture()->seedTable('wp_wprism_kv', [[
    'k' => 'force-index',
    'v' => 'visible',
]]);
Db::start(
    'profiled index-hint read start',
    db_authority_profile(['wp_wprism_kv'])
);
foreach ([
    'FORCE INDEX',
    'USE KEY',
    'IGNORE INDEX',
] as $hint) {
    $hintedRows = $wpdb->get_results(
        "SELECT k, v FROM wp_wprism_kv $hint (`kind_local`) WHERE k = 'force-index'",
        ARRAY_A
    );
    wprism_check_same(
        [['k' => 'force-index', 'v' => 'visible']],
        $hintedRows,
        "$hint with a quoted index name remains a table hint, not a stored-routine call"
    );
}
Db::rollback('profiled index-hint read rollback');

foreach ([
    'spaced' => 'SELECT COUNT () FROM wp_wprism_kv',
    'quoted' => 'SELECT `COUNT`() FROM wp_wprism_kv',
    'bare INDEX callable' => 'SELECT INDEX(k) FROM wp_wprism_kv',
    'bare KEY callable' => 'SELECT KEY(k) FROM wp_wprism_kv',
] as $routineKind => $routineSql) {
    $wpdb = db_authority_fixture();
    Db::start(
        "$routineKind routine refusal start",
        db_authority_profile(['wp_wprism_kv'])
    );
    $routineFailure = db_authority_failure(
        static fn() => $wpdb->get_var($routineSql)
    );
    wprism_check(
        $routineFailure instanceof DatabaseQueryIsolationViolationException,
        "$routineKind SQL routine spelling remains outside the closed profiled grammar"
    );
    Db::rollback("$routineKind routine refusal rollback");
}

// Every authority recheck rotates its persistent witness. Product DML made
// before that recheck must survive both the proof and the final COMMIT.
$wpdb = db_authority_fixture();
Db::start('non-destructive witness start', db_authority_profile(writeKv: true));
$writeAuthority = Db::transaction_authority('non-destructive witness initial authority');
$wpdb->query(db_authority_guarded_insert(
    $wpdb,
    $writeAuthority,
    'survives-authority-proof',
    'committed'
));
wprism_check_same(
    [['k' => 'survives-authority-proof', 'v' => 'committed']],
    $wpdb->rows('wp_wprism_kv'),
    'the authority fixture contains product DML before its continuity recheck'
);
Db::transaction_authority('non-destructive witness post-write authority');
wprism_check_same(
    [['k' => 'survives-authority-proof', 'v' => 'committed']],
    $wpdb->rows('wp_wprism_kv'),
    'transaction continuity proof never rewinds product DML'
);
Db::commit('non-destructive witness commit');
wprism_check_same(
    [['k' => 'survives-authority-proof', 'v' => 'committed']],
    $wpdb->rows('wp_wprism_kv'),
    'product DML remains durable after continuity proof and COMMIT'
);

// The fake first proves completion_type is not a decorative fixture: a bare
// terminal control obeys it, while Db's explicit modifiers below override it.
$wpdb = db_authority_fixture()->setCompletionType('CHAIN');
$wpdb->query('START TRANSACTION');
$wpdb->query('COMMIT');
wprism_check(
    $wpdb->activeTransactionIsolation() !== null,
    'the shared fake models a bare COMMIT chaining under completion_type=CHAIN'
);
$wpdb->query('ROLLBACK AND NO CHAIN NO RELEASE');

$wpdb = db_authority_fixture()->setCompletionType('RELEASE');
$connectionBeforeRelease = $wpdb->get_var('SELECT CONNECTION_ID()');
$wpdb->query('START TRANSACTION');
$wpdb->query('COMMIT');
wprism_check_same(
    (string) ((int) $connectionBeforeRelease + 1),
    $wpdb->get_var('SELECT CONNECTION_ID()'),
    'the shared fake models a bare COMMIT releasing its session under completion_type=RELEASE'
);

foreach (['CHAIN', 'RELEASE'] as $completionType) {
    $wpdb = db_authority_fixture()->setCompletionType($completionType);
    Db::start("$completionType terminal start", db_authority_profile());
    $firstAuthority = Db::transaction_authority("$completionType terminal authority");
    $wpdb->resetLog();
    Db::commit("$completionType terminal commit");
    wprism_check(
        in_array('COMMIT AND NO CHAIN NO RELEASE', $wpdb->queries(), true),
        "$completionType cannot alter Db's exact COMMIT boundary"
    );

    Db::start("$completionType post-commit start", db_authority_profile());
    $secondAuthority = Db::transaction_authority("$completionType post-commit authority");
    wprism_check_same(
        $firstAuthority->connection_id(),
        $secondAuthority->connection_id(),
        "$completionType neither chains nor releases Db's completed session"
    );
    $wpdb->resetLog();
    Db::rollback("$completionType terminal rollback");
    wprism_check(
        in_array('ROLLBACK AND NO CHAIN NO RELEASE', $wpdb->queries(), true),
        "$completionType cannot alter Db's exact ROLLBACK boundary"
    );
}

foreach (['COMMIT', 'ROLLBACK'] as $externalControl) {
    $wpdb = db_authority_fixture();
    Db::start(
        "external $externalControl chain start",
        db_authority_profile(writeKv: true)
    );
    Db::insert(
        'wp_wprism_kv',
        ['k' => strtolower($externalControl) . '-chain', 'v' => 'candidate'],
        null,
        "external $externalControl chain write"
    );
    $wpdb->simulateExternalTransactionControl("$externalControl AND CHAIN NO RELEASE");
    $chainFailure = db_authority_failure(
        static fn() => Db::transaction_active("external $externalControl chain proof")
    );
    wprism_check(
        $chainFailure instanceof DatabaseTransactionOutcomeException,
        "$externalControl AND CHAIN cannot masquerade as an inactive original transaction"
    );
    wprism_check(
        $wpdb->activeTransactionIsolation() !== null,
        "$externalControl AND CHAIN replacement stays explicit until cleanup"
    );
    $chainContinuation = db_authority_failure(
        static fn() => Db::transaction_authority("external $externalControl chain continuation")
    );
    wprism_check(
        $chainContinuation instanceof DatabaseTransactionOutcomeException,
        "$externalControl AND CHAIN replacement is rollback-only"
    );
    Db::rollback("external $externalControl chain cleanup");
    wprism_check_same(
        null,
        $wpdb->activeTransactionIsolation(),
        "$externalControl AND CHAIN replacement is explicitly rolled back"
    );
    wprism_check_same(
        $externalControl === 'COMMIT' ? 1 : 0,
        count($wpdb->rows('wp_wprism_kv')),
        "$externalControl AND CHAIN preserves the original terminal outcome"
    );
}

$wpdb = db_authority_fixture()->setAutocommit(false);
$autocommitFailure = db_authority_failure(
    static fn() => Db::start('autocommit-disabled start', db_authority_profile())
);
wprism_check(
    $autocommitFailure instanceof DatabaseTransactionOutcomeException
        && !in_array('START TRANSACTION', $wpdb->queries(), true),
    'autocommit=0 is refused from the atomic session fingerprint before START'
);
$wpdb->setAutocommit(true);
Db::start('autocommit-restored start', db_authority_profile());
Db::rollback('autocommit-restored rollback');

$wpdb = db_authority_fixture();
$wpdb->query('RELEASE SAVEPOINT `missing_first`');
$wpdb->failNextQuery('generic control failure', 'RELEASE SAVEPOINT `missing_second`');
$wpdb->query('RELEASE SAVEPOINT `missing_second`');
wprism_check_same(
    [],
    $wpdb->get_results('SHOW WARNINGS', ARRAY_A),
    'an unrelated failed statement clears stale numeric savepoint diagnostics in the shared fake'
);

foreach (['after_false', 'after_throw'] as $outcome) {
    $wpdb = db_authority_fixture()
        ->injectTransactionOutcome('SET SESSION AUTHORITY', $outcome);
    Db::start("session authority $outcome", db_authority_profile());
    $authority = Db::transaction_authority("session authority $outcome proof");
    wprism_check(
        strlen($authority->session_nonce()) === 64
            && Db::transaction_active("session authority $outcome active proof"),
        "a lost $outcome response after SET authority is accepted only from its server postimage"
    );
    Db::rollback("session authority $outcome rollback");
}

foreach (['after_false', 'after_throw'] as $outcome) {
    $wpdb = db_authority_fixture()
        ->injectTransactionOutcome('SET TRANSACTION', $outcome);
    $isolationFailure = db_authority_failure(
        static fn() => Db::start_repeatable_read("isolation $outcome", db_authority_profile())
    );
    $isolation = $wpdb->transactionIsolationState();
    wprism_check(
        $isolationFailure instanceof DatabaseMutationException
            && $isolation['next'] === null
            && $isolation['active'] === null,
        "a lost $outcome isolation response is consumed by one data-free rollback before refusal"
    );
    Db::start("post-isolation-$outcome start", db_authority_profile());
    Db::rollback("post-isolation-$outcome rollback");
}

foreach (['after_false', 'after_throw'] as $outcome) {
    $wpdb = db_authority_fixture()
        ->injectTransactionOutcome('START', $outcome);
    Db::start("start response $outcome", db_authority_profile());
    wprism_check(
        Db::transaction_active("start response $outcome proof"),
        "a lost $outcome START response is accepted only while the authored session and witness survive"
    );
    Db::rollback("start response $outcome rollback");
}

$wpdb = db_authority_fixture()->simulateDeadlock('START TRANSACTION');
$startDeadlock = db_authority_failure(
    static fn() => Db::start('deadlocked transaction start', db_authority_profile())
);
wprism_check(
    $startDeadlock instanceof DeadlockTransactionAbortedException
        && $wpdb->activeTransactionIsolation() === null,
    'strict mysqli errno 1213 at START remains a retryable typed deadlock after idle-state proof'
);
Db::start('post-start-deadlock retry', db_authority_profile());
Db::rollback('post-start-deadlock rollback');

$wpdb = db_authority_fixture()->injectTransactionOutcome('COMMIT', 'before_false');
Db::start('unapplied commit start', db_authority_profile());
$unappliedCommitFailure = db_authority_failure(
    static fn() => Db::commit('unapplied commit')
);
wprism_check(
    $unappliedCommitFailure instanceof DatabaseMutationException
        && Db::transaction_active('unapplied commit recovery proof'),
    'a failed unapplied explicit COMMIT remains rollback-recoverable in the original transaction'
);
Db::rollback('unapplied commit rollback');

$wpdb = db_authority_fixture()->injectTransactionOutcome('COMMIT', 'after_false');
Db::start('ambiguous commit start', db_authority_profile());
$ambiguousCommitFailure = db_authority_failure(
    static fn() => Db::commit('ambiguous commit')
);
wprism_check(
    $ambiguousCommitFailure instanceof DatabaseTransactionOutcomeException,
    'an applied explicit COMMIT with a lost response remains recovery-required rather than guessed'
);
$ambiguousCleanupFailure = db_authority_failure(static fn() => Db::rollback_after_failure(
    $ambiguousCommitFailure,
    'ambiguous commit rollback classification'
));
wprism_check(
    $ambiguousCleanupFailure instanceof DatabaseTransactionOutcomeException,
    'rollback cleanup retains an inactive ambiguous-COMMIT outcome for product postimage classification'
);
$queriesBeforeAmbiguousRetry = $wpdb->queries();
$ambiguousRetryFailure = db_authority_failure(static fn() => Db::start(
    'ambiguous commit retry start',
    db_authority_profile()
));
wprism_check(
    $ambiguousRetryFailure instanceof DatabaseTransactionOutcomeException
        && !$ambiguousRetryFailure instanceof DatabaseQueryIsolationViolationException
        && $wpdb->queries() === $queriesBeforeAmbiguousRetry,
    'a later START reports retained terminal ambiguity before touching the poisoned query gate or server'
);
Db::forget_transaction_tracking();

foreach (['after_false', 'after_throw'] as $outcome) {
    $wpdb = db_authority_fixture()->injectTransactionOutcome('ROLLBACK', $outcome);
    Db::start("rollback response $outcome start", db_authority_profile());
    $rollbackFailure = db_authority_failure(
        static fn() => Db::rollback("rollback response $outcome")
    );
    wprism_check_same(
        null,
        $rollbackFailure,
        "an applied explicit ROLLBACK with a lost $outcome response has one safe terminal outcome"
    );
}

// Error 1213 has already rolled back the complete InnoDB transaction when
// wpdb reports it. Pin the snapshot, savepoint and lock effects together so
// cleanup can prove inactivity without risking a second terminal statement.
$wpdb = db_authority_fixture()->seedTable('wp_lock_probe', [['id' => 1]]);
$contender = (new FakeWpdb())->setConnectionId(2)->shareDatabaseStateWith($wpdb);
Db::start(
    'deadlock-abort start',
    db_authority_profile(['wp_lock_probe'], true)
);
$deadlockAuthority = Db::transaction_authority('deadlock-abort authority');
$wpdb->query('SELECT id FROM wp_lock_probe WHERE id = 1 FOR UPDATE');
$lockedDdlFailure = db_authority_failure(
    static fn() => $contender->query('DROP TABLE wp_lock_probe')
);
wprism_check(
    $lockedDdlFailure instanceof RuntimeException,
    'the deadlock fixture first holds a transaction-scoped server lock'
);
$wpdb->query(db_authority_guarded_insert($wpdb, $deadlockAuthority, 'deadlock-write', 'uncommitted'));
$wpdb->simulateDeadlock("INSERT INTO wp_wprism_kv (k, v) SELECT 'deadlock-trigger', 'x'");
$deadlockFailure = db_authority_failure(
    static fn() => Db::mutation(
        "INSERT INTO wp_wprism_kv (k, v) SELECT 'deadlock-trigger', 'x'",
        '',
        '',
        'deadlock-abort mutation'
    )
);
wprism_check(
    $deadlockFailure instanceof DeadlockTransactionAbortedException,
    'MySQL 1213 maps to the server-aborted deadlock type'
);
wprism_check_same(
    [],
    $wpdb->rows('wp_wprism_kv'),
    'MySQL 1213 restores the complete transaction snapshot before reporting failure'
);
wprism_check_same(
    true,
    $contender->query('DROP TABLE wp_lock_probe'),
    'MySQL 1213 releases the aborted transaction server locks'
);
$wpdb->resetLog();
$deadlockCleanupFailure = db_authority_failure(
    static fn() => Db::rollback_after_failure($deadlockFailure, 'deadlock-abort cleanup')
);
wprism_check(
    $deadlockCleanupFailure === null
        && !in_array('ROLLBACK AND NO CHAIN NO RELEASE', $wpdb->queries(), true),
    'deadlock cleanup proves the original savepoint absent without issuing a second ROLLBACK'
);
Db::start('post-deadlock start', db_authority_profile());
Db::rollback('post-deadlock rollback');

// Error 1205 is transaction-preserving by default: prior writes and the
// persistent witness remain until rollback_after_failure performs one exact
// explicit rollback.
$wpdb = db_authority_fixture();
Db::start('lock-timeout start', db_authority_profile(writeKv: true));
$timeoutAuthority = Db::transaction_authority('lock-timeout authority');
$wpdb->query(db_authority_guarded_insert($wpdb, $timeoutAuthority, 'timeout-write', 'uncommitted'));
$wpdb->simulateLockWaitTimeout("INSERT INTO wp_wprism_kv (k, v) SELECT 'timeout-trigger', 'x'");
$timeoutFailure = db_authority_failure(
    static fn() => Db::mutation(
        "INSERT INTO wp_wprism_kv (k, v) SELECT 'timeout-trigger', 'x'",
        '',
        '',
        'lock-timeout mutation'
    )
);
wprism_check(
    $timeoutFailure instanceof TransientDbException
        && !$timeoutFailure instanceof DeadlockTransactionAbortedException
        && Db::transaction_active('lock-timeout preserved transaction proof'),
    'MySQL 1205 remains a transaction-preserving transient failure'
);
wprism_check_same(
    [['k' => 'timeout-write', 'v' => 'uncommitted']],
    $wpdb->rows('wp_wprism_kv'),
    'MySQL 1205 leaves prior transaction writes pending'
);
$wpdb->resetLog();
$timeoutCleanupFailure = db_authority_failure(
    static fn() => Db::rollback_after_failure($timeoutFailure, 'lock-timeout cleanup')
);
wprism_check(
    $timeoutCleanupFailure === null
        && in_array('ROLLBACK AND NO CHAIN NO RELEASE', $wpdb->queries(), true),
    'lock-timeout cleanup explicitly rolls back the still-active transaction'
);
wprism_check_same(
    [],
    $wpdb->rows('wp_wprism_kv'),
    'the explicit lock-timeout rollback restores the transaction snapshot'
);

// innodb_rollback_on_timeout=ON makes the same 1205 abort the entire
// transaction. The old witness distinguishes that valid configuration from
// the default statement-only rollback without pinning a global server option.
$wpdb = db_authority_fixture();
Db::start('rollback-on-timeout start', db_authority_profile(writeKv: true));
$timeoutAbortAuthority = Db::transaction_authority('rollback-on-timeout authority');
$wpdb->query(db_authority_guarded_insert(
    $wpdb,
    $timeoutAbortAuthority,
    'timeout-abort-write',
    'uncommitted'
));
$wpdb->simulateLockWaitTimeout(
    "INSERT INTO wp_wprism_kv (k, v) SELECT 'timeout-abort-trigger', 'x'",
    abortTransaction: true
);
$timeoutAbortFailure = db_authority_failure(
    static fn() => Db::mutation(
        "INSERT INTO wp_wprism_kv (k, v) SELECT 'timeout-abort-trigger', 'x'",
        '',
        '',
        'rollback-on-timeout mutation'
    )
);
wprism_check(
    $timeoutAbortFailure instanceof TransientDbException
        && !$timeoutAbortFailure instanceof DeadlockTransactionAbortedException,
    '1205 remains retryable when the server rolls the entire transaction back'
);
wprism_check_same(
    [],
    $wpdb->rows('wp_wprism_kv'),
    'rollback-on-timeout restores the complete transaction snapshot before reporting 1205'
);
$wpdb->resetLog();
$timeoutAbortCleanupFailure = db_authority_failure(
    static fn() => Db::rollback_after_failure(
        $timeoutAbortFailure,
        'rollback-on-timeout cleanup'
    )
);
wprism_check(
    $timeoutAbortCleanupFailure === null
        && !in_array('ROLLBACK AND NO CHAIN NO RELEASE', $wpdb->queries(), true),
    '1205 cleanup proves an already-aborted transaction without issuing a second ROLLBACK'
);
Db::start('post-rollback-on-timeout start', db_authority_profile());
Db::rollback('post-rollback-on-timeout rollback');

$wpdb = db_authority_fixture()
    ->injectTransactionOutcome('SET SESSION AUTHORITY', 'after_reconnect_same_id_replay');
$replayedAuthorityFailure = db_authority_failure(
    static fn() => Db::start('replayed session-authority start', db_authority_profile())
);
wprism_check(
    $replayedAuthorityFailure instanceof DatabaseTransactionOutcomeException
        && $wpdb->activeTransactionIsolation() === null,
    'strict transport refuses same-id wpdb replay while binding session authority'
);
Db::start('post-replayed-session-authority start', db_authority_profile());
Db::rollback('post-replayed-session-authority rollback');

$wpdb = db_authority_fixture()
    ->injectTransactionOutcome('SET TRANSACTION', 'after_reconnect_same_id_replay');
$replayedIsolationFailure = db_authority_failure(
    static fn() => Db::start_repeatable_read('replayed isolation start', db_authority_profile())
);
$replayedIsolation = $wpdb->transactionIsolationState();
wprism_check(
    $replayedIsolationFailure instanceof DatabaseTransactionOutcomeException
        && $replayedIsolation['next'] === null
        && $replayedIsolation['active'] === null,
    'same-id replay of SET TRANSACTION loses the old nonce and is consumed before refusal'
);
Db::start('post-replayed-isolation start', db_authority_profile());
Db::rollback('post-replayed-isolation rollback');

foreach (['after_reconnect_replay', 'after_reconnect_same_id_replay'] as $outcome) {
    $wpdb = db_authority_fixture()->injectTransactionOutcome('START', $outcome);
    $replayedStartFailure = db_authority_failure(
        static fn() => Db::start("replayed START $outcome", db_authority_profile())
    );
    wprism_check(
        $replayedStartFailure instanceof DatabaseTransactionOutcomeException
            && $wpdb->activeTransactionIsolation() === null,
        "$outcome START cannot inherit authority and its data-free replacement is rolled back"
    );
    Db::start("post-replayed-START-$outcome start", db_authority_profile());
    Db::rollback("post-replayed-START-$outcome rollback");
}

$wpdb = db_authority_fixture()
    ->injectTransactionOutcome('START', 'after_reconnect_same_id_replay');
$strictReplayFailure = db_authority_failure(
    static fn() => Db::start('strict-replay initial start', db_authority_profile())
);
wprism_check(
    $strictReplayFailure instanceof DatabaseTransactionOutcomeException
        && $wpdb->activeTransactionIsolation() === null,
    'strict transport leaves no replayed START transaction requiring retained cleanup'
);
Db::start('post-strict-replay start', db_authority_profile());
Db::rollback('post-strict-replay rollback');

$wpdb = db_authority_fixture();
Db::start('same-id replacement original start', db_authority_profile());
$oldAuthority = Db::transaction_authority('same-id replacement original authority');
$wpdb->setConnectionId((int) $oldAuthority->connection_id());
$wpdb->simulateExternalTransactionControl('START TRANSACTION');
$sameIdFailure = db_authority_failure(
    static fn() => Db::transaction_active('same-id replacement authority proof')
);
wprism_check(
    $sameIdFailure instanceof DatabaseTransactionOutcomeException,
    'a replacement transaction reusing the numeric connection id cannot satisfy the private nonce'
);
$wpdb->simulateExternalTransactionControl('ROLLBACK AND NO CHAIN NO RELEASE');
Db::connection_transaction_active('same-id replacement idle settlement');
Db::forget_transaction_tracking();

$wpdb = db_authority_fixture();
Db::start('implicit-loss start', db_authority_profile());
$wpdb->simulateImplicitCommit();
wprism_check_same(
    false,
    Db::transaction_active('implicit-loss proof'),
    'loss of the original transaction is detected from both missing savepoint witnesses'
);
Db::forget_transaction_tracking();

// The SQL predicate authenticates a session, not a transaction. Preserve that
// real-server distinction: Db's pre/post proofs are what make product writes
// transaction-only, while the statement itself may execute in autocommit.
$wpdb = db_authority_fixture();
Db::start('guarded statement start', db_authority_profile(writeKv: true));
$guardAuthority = Db::transaction_authority('guarded statement authority');
$guardedInsert = db_authority_guarded_insert($wpdb, $guardAuthority, 'probe', 'inside');
wprism_check_same(1, $wpdb->query($guardedInsert), 'the guarded statement matches its live transaction session');
Db::rollback('guarded statement rollback');
wprism_check_same([], $wpdb->rows('wp_wprism_kv'), 'rollback removes the guarded in-transaction write');
wprism_check_same(
    1,
    $wpdb->query($guardedInsert),
    'the same-session nonce predicate still executes under autocommit after a confirmed terminal control'
);
wprism_check_same(
    [['k' => 'probe', 'v' => 'inside']],
    $wpdb->rows('wp_wprism_kv'),
    'the fake does not invent transaction authorization absent from the guarded SQL'
);
$guardedDelete = db_authority_guarded_delete($wpdb, $guardAuthority, 'probe');
wprism_check_same(
    1,
    $wpdb->query($guardedDelete),
    'the same-session guarded deletion also follows SQL predicate semantics in autocommit'
);
wprism_check_same([], $wpdb->rows('wp_wprism_kv'), 'the matching guarded deletion removes its row');

$wpdb = db_authority_fixture();
Db::start('guarded reconnect start', db_authority_profile(writeKv: true));
$staleAuthority = Db::transaction_authority('guarded reconnect authority');
$staleInsert = db_authority_guarded_insert($wpdb, $staleAuthority, 'probe', 'stale');
$wpdb->setConnectionId((int) $staleAuthority->connection_id());
wprism_check_same(
    0,
    $wpdb->query($staleInsert),
    'a reconnect with a reused numeric id makes the stale guarded statement match zero rows'
);
wprism_check_same([], $wpdb->rows('wp_wprism_kv'), 'the stale guarded statement publishes no bytes');
Db::connection_transaction_active('guarded reconnect idle settlement');
Db::forget_transaction_tracking();

foreach (['SAVEPOINT', 'ROLLBACK TO SAVEPOINT', 'RELEASE SAVEPOINT'] as $control) {
    foreach (['after_false', 'after_throw'] as $outcome) {
        $wpdb = db_authority_fixture();
        Db::start("$control $outcome rotation start", db_authority_profile());
        $wpdb->injectTransactionOutcome($control, $outcome);
        $controlFailure = db_authority_failure(
            static fn() => Db::transaction_active("$control $outcome rotation proof")
        );
        wprism_check(
            $controlFailure instanceof DatabaseTransactionOutcomeException,
            "$control $outcome response loss is never guessed successful"
        );
        $survives = Db::transaction_active("$control $outcome surviving witness proof");
        wprism_check(
            $survives,
            "$control $outcome retains a fresh positively-proven witness for rollback"
        );
        $continued = db_authority_failure(
            static fn() => Db::transaction_authority("$control $outcome product continuation")
        );
        wprism_check(
            $continued instanceof DatabaseTransactionOutcomeException,
            "$control $outcome cannot resume product work after healed control ambiguity"
        );
        Db::rollback("$control $outcome rotation rollback");
    }
}

$wpdb = db_authority_fixture();
Db::start('unapplied witness rotation start', db_authority_profile());
$wpdb->injectTransactionOutcome('SAVEPOINT', 'success_no_apply');
$unappliedRotationFailure = db_authority_failure(
    static fn() => Db::transaction_active('unapplied witness rotation proof')
);
wprism_check(
    $unappliedRotationFailure instanceof DatabaseTransactionOutcomeException,
    'a truthy-but-unapplied replacement SAVEPOINT cannot falsely classify the transaction inactive'
);
wprism_check(
    Db::transaction_active('unapplied witness recovery proof'),
    'a fresh name recovers rollback authority after a truthy-but-unapplied replacement SAVEPOINT'
);
$unappliedContinuation = db_authority_failure(
    static fn() => Db::transaction_authority('unapplied witness product continuation')
);
wprism_check(
    $unappliedContinuation instanceof DatabaseTransactionOutcomeException,
    'truthy-but-unapplied replacement recovery remains rollback-only'
);
Db::rollback('unapplied witness cleanup rollback');

// WordPress's mutable `all`/`query` hook topology is quarantined for the whole
// physical transaction. The exact preexisting objects return only after a
// positive terminal control, and wpdb's placeholder escaping still round-trips.
$wpdb = db_authority_fixture();
$queryHookBefore = new \stdClass();
$allHookBefore = new \stdClass();
$GLOBALS['wp_filter'] = ['query' => $queryHookBefore, 'all' => $allHookBefore];
Db::start('query-hook restoration start', db_authority_profile(writeKv: true));
wprism_check(
    $GLOBALS['wp_filter']['query'] !== $queryHookBefore
        && $GLOBALS['wp_filter']['all'] !== $allHookBefore,
    'authored transaction replaces both mutable database hook surfaces'
);
Db::insert(
    'wp_wprism_kv',
    ['k' => 'percent-value', 'v' => '100% literal'],
    ['%s', '%s'],
    'query-hook placeholder-safe insert'
);
Db::commit('query-hook restoration commit');
wprism_check(
    ($GLOBALS['wp_filter']['query'] ?? null) === $queryHookBefore
        && ($GLOBALS['wp_filter']['all'] ?? null) === $allHookBefore,
    'commit restores the exact pre-transaction hook objects'
);
wprism_check_same(
    '100% literal',
    $wpdb->rows('wp_wprism_kv')[0]['v'] ?? null,
    'query quarantine preserves wpdb placeholder-unescape semantics'
);
unset($GLOBALS['wp_filter']);

foreach (['dangerous-query', 'add-query-filter', 'clear-all-filter', 'replace-query-object'] as $attack) {
    $wpdb = db_authority_fixture();
    Db::start("$attack start", db_authority_profile(writeKv: true));
    $queriesBefore = $wpdb->queries();
    $attackFailure = db_authority_failure(static function () use ($attack, $wpdb): void {
        match ($attack) {
            'dangerous-query' => $wpdb->query('COMMIT AND CHAIN NO RELEASE'),
            'add-query-filter' => add_filter('query', static fn(string $sql): string => $sql),
            'clear-all-filter' => remove_all_filters('all'),
            'replace-query-object' => $GLOBALS['wp_filter']['query'] = new \stdClass(),
        };
        if ($attack === 'replace-query-object') {
            Db::transaction_active('replaced query-hook topology proof');
        }
    });
    wprism_check(
        $attackFailure instanceof DatabaseQueryIsolationViolationException,
        "$attack poisons the authored query boundary"
    );
    $restart = db_authority_failure(
        static fn() => Db::start("$attack nested restart", db_authority_profile())
    );
    wprism_check(
        $restart instanceof DatabaseQueryIsolationViolationException,
        "$attack remains a query-isolation violation when code attempts another START while active"
    );
    $continued = db_authority_failure(
        static fn() => Db::insert(
            'wp_wprism_kv',
            ['k' => $attack, 'v' => 'must-not-write'],
            null,
            "$attack product continuation"
        )
    );
    wprism_check(
        $continued instanceof DatabaseQueryIsolationViolationException,
        "$attack cannot resume product DML"
    );
    if ($attack === 'dangerous-query') {
        wprism_check_same(
            $queriesBefore,
            $wpdb->queries(),
            'forbidden transaction control never reaches the database transport'
        );
    }
    Db::rollback("$attack cleanup rollback");
    wprism_check_same([], $wpdb->rows('wp_wprism_kv'), "$attack cleanup leaves no product row");
}

// This refusal is intentionally last: after strict transport prevented replay,
// a later foreign transaction on the same numeric id is still not ours to
// adopt or roll back. The caller that opened it remains its only authority.
$wpdb = db_authority_fixture()
    ->injectTransactionOutcome('START', 'after_reconnect_same_id_replay')
    ->injectTransactionOutcome('ROLLBACK', 'before_false');
db_authority_failure(static fn() => Db::start(
    'replaced-cleanup initial start',
    db_authority_profile()
));
$wpdb->setConnectionId(1);
$wpdb->simulateExternalTransactionControl('START TRANSACTION');
$replacedCleanupFailure = db_authority_failure(
    static fn() => Db::start('replaced-cleanup retry', db_authority_profile())
);
wprism_check(
    $replacedCleanupFailure instanceof DatabaseMutationException
        && $wpdb->activeTransactionIsolation() !== null,
    'an idle-boundary retry never adopts or rolls back a foreign active session even when its numeric id is reused'
);
$wpdb->simulateExternalTransactionControl('ROLLBACK AND NO CHAIN NO RELEASE');

wprism_check_summary('database transaction authority');
