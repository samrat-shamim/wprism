<?php
namespace WPrism;

require_once __DIR__ . '/DatabaseExceptions.php';
require_once __DIR__ . '/DatabaseLockBoundary.php';
require_once __DIR__ . '/DatabaseQueryIsolation.php';
require_once __DIR__ . '/NativeDatabaseProfile.php';
require_once __DIR__ . '/NativeTableDefinition.php';
require_once __DIR__ . '/TransactionAuthority.php';
require_once __DIR__ . '/TransientDbException.php';
require_once __DIR__ . '/WpdbFieldCodec.php';

/**
 * The only sanctioned path for product database mutations. WordPress's wpdb
 * methods return false rather than throwing, while 0 is a valid successful
 * result for UPDATE/DELETE, so every method below uses the strict false
 * check and retains an operation-level (never value-level) context.
 */
final class Db {
    private const BATCH_ROW_LIMIT = 256;
    private const WITNESS_VALID = 'valid';
    private const WITNESS_LOST_UNKNOWN = 'lost_unknown';
    private const WITNESS_INACTIVE = 'inactive';

    private static ?TransactionAuthority $transactionAuthority = null;
    private static ?string $transactionSavepoint = null;
    private static ?string $transactionWitnessState = null;
    private static bool $transactionCleanupOnly = false;
    private static bool $idleCurrentSessionProven = false;
    private static ?TransactionAuthority $pendingStartAuthority = null;
    private static ?TransactionAuthority $pendingIsolationAuthority = null;
    private static ?TransactionAuthority $pendingCleanupAuthority = null;
    private static bool $pendingIsolationStartAttempted = false;
    /** @var ?\WeakMap<\Throwable,array{authority:TransactionAuthority,errno:int}> */
    private static ?\WeakMap $serverAbortEvidence = null;

    private static function clear_transaction_tracking_state(): void {
        self::$transactionAuthority = null;
        self::$transactionSavepoint = null;
        self::$transactionWitnessState = null;
        self::$transactionCleanupOnly = false;
        self::$idleCurrentSessionProven = false;
    }

    private static function before(string $context): void {
        // Deterministic integration-test seam. Both switches are required so
        // a stray context variable can never affect a normal installation.
        $targets = array_map('trim', explode(',', (string) getenv('WPRISM_TEST_FAIL_DB_CONTEXT')));
        if (getenv('WPRISM_TEST_MODE') === '1'
            && in_array($context, $targets, true)) {
            throw new DatabaseMutationException($context . ' (injected)');
        }
    }

    private static function assert_product_transaction_usable(string $context): void {
        if (self::$transactionAuthority !== null && self::$transactionCleanupOnly) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' reached a cleanup-only transaction after an uncertain continuity control'
            );
        }
    }

    private static function enter_query_isolation(string $context): void {
        if (DatabaseQueryIsolation::is_active()) {
            DatabaseQueryIsolation::assert_active($context . ' query-filter boundary');
            return;
        }
        DatabaseQueryIsolation::begin($context . ' query-filter boundary');
    }

    /** Restore hooks only when no active or resumable SQL boundary remains. */
    private static function finish_query_isolation_if_settled(): void {
        if (self::$transactionAuthority === null
            && self::$pendingStartAuthority === null
            && self::$pendingIsolationAuthority === null
            && self::$pendingCleanupAuthority === null) {
            DatabaseQueryIsolation::finish();
        }
    }

    private static function checked($result, string $context) {
        if ($result === false) {
            global $wpdb;
            $error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
            $errno = self::driver_errno();
            if ($errno === 1213 || stripos($error, 'Deadlock found') !== false) {
                $failure = new DeadlockTransactionAbortedException(
                    "wprism: database deadlock aborted the transaction at $context"
                );
                self::record_server_abort_evidence($failure, $errno);
                throw $failure;
            }
            if ($errno === 1205 || stripos($error, 'Lock wait timeout') !== false) {
                // Capture retries this typed class from a fresh consistent
                // snapshot. Keep the driver text out of the exception: it
                // can contain rendered SQL values, while the caller-supplied
                // operation context is enough to locate the failure.
                $failure = new TransientDbException("wprism: transient DB contention at $context");
                self::record_server_abort_evidence($failure, $errno);
                throw $failure;
            }
            throw new DatabaseMutationException($context);
        }
        return $result;
    }

    /** Execute one exact engine control through the quarantined query hook. */
    private static function permitted_control_query(string $sql, string $context): mixed {
        global $wpdb;
        return self::permitted_transport(
            $sql,
            $context,
            static fn(string $statement): mixed => $wpdb->query($statement)
        );
    }

    /** @param callable(string):mixed $operation */
    private static function permitted_transport(
        string $sql,
        string $context,
        callable $operation
    ): mixed {
        DatabaseQueryIsolation::permit_once($sql, $context);
        $result = null;
        $failure = null;
        try {
            $result = $operation($sql);
        } catch (\Throwable $caught) {
            $failure = $caught;
        }
        try {
            DatabaseQueryIsolation::assert_permit_consumed($context);
        } catch (\Throwable $transportFailure) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' did not cross the exact WordPress query-filter transport',
                $failure ?? $transportFailure
            );
        }
        if ($failure !== null) {
            throw $failure;
        }
        return $result;
    }

    /**
     * Create one bounded set of engine-owned tables from value-typed
     * definitions under a single idle physical-session proof.
     *
     * @param array<string,NativeTableDefinition> $definitions
     */
    public static function ensure_tables(
        array $definitions,
        string $charsetCollate,
        string $context
    ): void {
        if ($definitions === [] || array_is_list($definitions) || count($definitions) > 32) {
            throw new \InvalidArgumentException(
                "wprism: $context requires between 1 and 32 named native table definitions"
            );
        }
        $statements = [];
        foreach ($definitions as $table => $definition) {
            if (!$definition instanceof NativeTableDefinition) {
                throw new \InvalidArgumentException(
                    "wprism: $context received a malformed native table definition"
                );
            }
            $table = self::native_table_identifier($table, $context);
            $statements[$table] = $definition->create_sql($table, $charsetCollate);
        }
        self::idle_schema_scope(
            $context,
            static function (TransactionAuthority $authority) use ($statements, $context): void {
                foreach ($statements as $table => $sql) {
                    self::idle_schema_statement(
                        $sql,
                        $authority,
                        $context . ' create ' . self::table_label($table)
                    );
                }
            }
        );
    }

    /**
     * Reconcile one engine-owned VARCHAR without exposing an ALTER fragment.
     * The live width is re-read inside the same isolated physical-session
     * boundary that emits DDL, so ordinary callers cannot accidentally narrow
     * a column from stale metadata.
     */
    public static function ensure_varchar_column_width(
        string $table,
        string $column,
        int $minimumWidth,
        bool $nullable,
        string $context
    ): bool {
        global $wpdb;
        $table = self::native_table_identifier($table, $context);
        $column = self::column_identifier($column, $context);
        if ($minimumWidth < 1 || $minimumWidth > 16383) {
            throw new \InvalidArgumentException(
                "wprism: $context received an invalid VARCHAR width"
            );
        }

        return self::idle_schema_scope(
            $context,
            static function (TransactionAuthority $authority) use (
                $wpdb,
                $table,
                $column,
                $minimumWidth,
                $nullable,
                $context
            ): bool {
                $inventorySql = $wpdb->prepare(
                    'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE '
                        . 'FROM information_schema.COLUMNS '
                        . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
                    $table,
                    $column
                );
                if (!is_string($inventorySql) || $inventorySql === '') {
                    throw new DatabaseMutationException(
                        $context . ' could not prepare native column inventory'
                    );
                }
                $row = self::idle_schema_transport(
                    $inventorySql,
                    $authority,
                    $context . ' live column inventory',
                    static fn(string $statement): mixed => $wpdb->get_row($statement, ARRAY_A)
                );
                if (!is_array($row)
                    || array_keys($row) !== ['DATA_TYPE', 'CHARACTER_MAXIMUM_LENGTH', 'IS_NULLABLE']
                    || !is_string($row['DATA_TYPE'])
                    || strtolower($row['DATA_TYPE']) !== 'varchar'
                    || (!is_int($row['CHARACTER_MAXIMUM_LENGTH'])
                        && (!is_string($row['CHARACTER_MAXIMUM_LENGTH'])
                            || preg_match('/^[1-9][0-9]*$/D', $row['CHARACTER_MAXIMUM_LENGTH']) !== 1))
                    || !is_string($row['IS_NULLABLE'])
                    || !in_array($row['IS_NULLABLE'], ['YES', 'NO'], true)) {
                    throw new DatabaseMutationException(
                        $context . ' found a malformed or absent native VARCHAR column'
                    );
                }
                $currentWidth = (int) $row['CHARACTER_MAXIMUM_LENGTH'];
                $currentNullable = $row['IS_NULLABLE'] === 'YES';
                if ($currentWidth >= $minimumWidth && $currentNullable === $nullable) {
                    return false;
                }
                if ($currentWidth > 16383) {
                    throw new DatabaseMutationException(
                        $context . ' cannot safely reconcile the live VARCHAR width'
                    );
                }
                $targetWidth = max($currentWidth, $minimumWidth);
                $sql = "ALTER TABLE `$table` MODIFY COLUMN `$column` VARCHAR($targetWidth) "
                    . ($nullable ? 'NULL' : 'NOT NULL');
                self::idle_schema_statement($sql, $authority, $context);
                return true;
            }
        );
    }

    public static function insert(string $table, array $data, $format = null, ?string $context = null): int {
        global $wpdb;
        $context ??= 'insert into ' . self::table_label($table);
        self::before($context);
        self::assert_product_transaction_usable($context);
        [$columns, $values] = self::write_fields($table, $data, $format, $context);
        $head = 'INSERT INTO `' . self::table_identifier($table, $context) . "` ($columns) SELECT $values";
        if (self::$transactionAuthority !== null) {
            return (int) self::transactional_mutation(
                $head,
                '',
                '',
                self::$transactionAuthority,
                $context
            );
        }
        return self::profiled_mutation_scope(
            $table,
            $context,
            static fn(TransactionAuthority $authority): int => self::transactional_mutation(
                $head,
                '',
                '',
                $authority,
                $context
            )
        );
    }

    /**
     * Insert one bounded, shape-identical row set through authority-guarded
     * one-row statements. Rendering every row before the first statement
     * keeps malformed input data-free; a standalone call then wraps the
     * complete set in one profiled transaction, while an existing authored
     * transaction retains its original physical-session authority.
     *
     * @param list<array<string,mixed>> $rows
     */
    public static function insert_rows(
        string $table,
        array $rows,
        mixed $format = null,
        ?string $context = null
    ): int {
        $context ??= 'insert rows into ' . self::table_label($table);
        self::before($context);
        self::assert_product_transaction_usable($context);
        if (!array_is_list($rows)
            || $rows === []
            || count($rows) > self::BATCH_ROW_LIMIT
            || !is_array($rows[0])
            || $rows[0] === []) {
            throw new \InvalidArgumentException(
                "wprism: $context requires between 1 and " . self::BATCH_ROW_LIMIT
                    . ' ordered insert rows'
            );
        }

        $table = self::table_identifier($table, $context);
        $expectedColumns = array_keys($rows[0]);
        $heads = [];
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== $expectedColumns) {
                throw new \InvalidArgumentException(
                    "wprism: $context requires one exact ordered insert row shape"
                );
            }
            [$columns, $values] = self::write_fields($table, $row, $format, $context);
            $heads[] = "INSERT INTO `$table` ($columns) SELECT $values";
        }

        $insert = static function (TransactionAuthority $authority) use ($heads, $context): int {
            $affected = 0;
            foreach ($heads as $head) {
                $affected += self::transactional_mutation(
                    $head,
                    '',
                    '',
                    $authority,
                    $context
                );
            }
            return $affected;
        };
        if (self::$transactionAuthority !== null) {
            return $insert(self::$transactionAuthority);
        }
        return self::profiled_mutation_scope($table, $context, $insert);
    }

    public static function update(string $table, array $data, array $where, $format = null, $whereFormat = null, ?string $context = null): int {
        global $wpdb;
        $context ??= 'update ' . self::table_label($table);
        self::before($context);
        self::assert_product_transaction_usable($context);
        [$set] = self::write_fields($table, $data, $format, $context, true);
        [$predicate] = self::where_fields($table, $where, $whereFormat, $context);
        $head = 'UPDATE `' . self::table_identifier($table, $context) . "` SET $set";
        if (self::$transactionAuthority !== null) {
            return (int) self::transactional_mutation(
                $head,
                $predicate,
                '',
                self::$transactionAuthority,
                $context
            );
        }
        return self::profiled_mutation_scope(
            $table,
            $context,
            static fn(TransactionAuthority $authority): int => self::transactional_mutation(
                $head,
                $predicate,
                '',
                $authority,
                $context
            )
        );
    }

    public static function delete(string $table, array $where, $whereFormat = null, ?string $context = null): int {
        global $wpdb;
        $context ??= 'delete from ' . self::table_label($table);
        self::before($context);
        self::assert_product_transaction_usable($context);
        [$predicate] = self::where_fields($table, $where, $whereFormat, $context);
        $head = 'DELETE FROM `' . self::table_identifier($table, $context) . '`';
        if (self::$transactionAuthority !== null) {
            return (int) self::transactional_mutation(
                $head,
                $predicate,
                '',
                self::$transactionAuthority,
                $context
            );
        }
        return self::profiled_mutation_scope(
            $table,
            $context,
            static fn(TransactionAuthority $authority): int => self::transactional_mutation(
                $head,
                $predicate,
                '',
                $authority,
                $context
            )
        );
    }

    /** Delete every row through one profiled, authority-guarded transaction. */
    public static function delete_all(string $table, ?string $context = null): int {
        $context ??= 'delete all rows from ' . self::table_label($table);
        self::before($context);
        self::assert_product_transaction_usable($context);
        $table = self::table_identifier($table, $context);
        $head = "DELETE FROM `$table`";
        if (self::$transactionAuthority !== null) {
            return (int) self::transactional_mutation(
                $head,
                '',
                '',
                self::$transactionAuthority,
                $context
            );
        }
        return self::profiled_mutation_scope(
            $table,
            $context,
            static fn(TransactionAuthority $authority): int => self::transactional_mutation(
                $head,
                '',
                '',
                $authority,
                $context
            )
        );
    }

    /**
     * Execute one structured DML statement, adding transaction authority when
     * this process owns an active transaction. The caller supplies a statement
     * head, an optional WHERE condition (without `WHERE`), and an optional
     * statement tail such as `ON DUPLICATE KEY UPDATE ...`. Standalone DML
     * that reads through a subquery declares those physical sources so the
     * temporary transaction can prove and bind them before the statement.
     *
     * @param list<string> $readTables
     */
    public static function mutation(
        string $head,
        string $condition,
        string $tail,
        string $context,
        array $readTables = []
    ): int {
        self::before($context);
        self::assert_product_transaction_usable($context);
        self::assert_mutation_fragments($head, $condition, $tail, $context);
        $table = self::mutation_table_from_head($head, $context);
        $standaloneProfile = new NativeDatabaseProfile($readTables, [$table]);
        if (self::$transactionAuthority !== null) {
            return (int) self::transactional_mutation(
                $head,
                $condition,
                $tail,
                self::$transactionAuthority,
                $context
            );
        }
        return self::profiled_mutation_scope(
            $table,
            $context,
            static fn(TransactionAuthority $authority): int => self::transactional_mutation(
                $head,
                $condition,
                $tail,
                $authority,
                $context
            ),
            $standaloneProfile
        );
    }

    /** Execute DML only on the exact physical transaction named by authority. */
    public static function transactional_mutation(
        string $head,
        string $condition,
        string $tail,
        TransactionAuthority $authority,
        string $context
    ): int {
        self::before($context);
        self::assert_product_transaction_usable($context);
        self::assert_mutation_fragments($head, $condition, $tail, $context);
        $current = self::transaction_authority($context . ' preflight');
        if (!$authority->equals($current)) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' changed database session authority before mutation'
            );
        }
        global $wpdb;
        $sql = self::assemble_mutation($head, $condition, $tail, $authority);
        DatabaseQueryIsolation::assert_active($context . ' prepared-query boundary');
        try {
            $transportResult = $wpdb->query($sql);
        } catch (\Throwable $failure) {
            throw self::normalize_transaction_failure($failure, $context);
        }
        $result = self::checked($transportResult, $context);
        $after = self::transaction_authority($context . ' postflight');
        if (!$authority->equals($after)) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' changed database session authority after mutation'
            );
        }
        return (int) $result;
    }

    /**
     * Convert only a synchronous strict-mysqli failure that crossed the bound
     * wpdb query gate. This keeps retry/abort semantics identical whether PHP
     * exposes the driver error as wpdb false+errno or mysqli_sql_exception.
     */
    private static function normalize_transaction_failure(
        \Throwable $failure,
        string $context
    ): \Throwable {
        $errno = self::strict_driver_errno($failure);
        if ($errno === 1213) {
            $normalized = new DeadlockTransactionAbortedException(
                "wprism: database deadlock aborted the transaction at $context",
                0,
                $failure
            );
            self::record_server_abort_evidence($normalized, $errno);
            return $normalized;
        }
        if ($errno === 1205) {
            $normalized = new TransientDbException(
                "wprism: transient DB contention at $context",
                0,
                $failure
            );
            self::record_server_abort_evidence($normalized, $errno);
            return $normalized;
        }
        if (class_exists('mysqli_sql_exception', false)
            && $failure instanceof \mysqli_sql_exception) {
            return new DatabaseMutationException($context, $failure);
        }
        return $failure;
    }

    /**
     * Publish a bounded row set through generated one-row upserts.
     *
     * Keeping the UNION/derived-table grammar out of the public fragment API
     * means every authority predicate remains attached by construction. The
     * caller's transaction supplies all-or-nothing semantics across the
     * generated statements, while wpdb still owns field formats and validation.
     *
     * @param list<array<string,mixed>> $rows
     * @param list<string> $updateColumns
     */
    public static function transactional_upsert_rows(
        string $table,
        array $rows,
        array $updateColumns,
        TransactionAuthority $authority,
        string $context
    ): int {
        if (!array_is_list($rows)
            || $rows === []
            || count($rows) > self::BATCH_ROW_LIMIT) {
            throw new \InvalidArgumentException(
                "wprism: $context requires between 1 and " . self::BATCH_ROW_LIMIT
                    . ' ordered upsert rows'
            );
        }
        if (!array_is_list($updateColumns)
            || $updateColumns === []
            || count($updateColumns) !== count(array_unique($updateColumns, SORT_STRING))) {
            throw new \InvalidArgumentException("wprism: $context received malformed upsert columns");
        }

        $expectedColumns = array_keys($rows[0]);
        if ($expectedColumns === [] || !array_is_list($expectedColumns)) {
            throw new \InvalidArgumentException("wprism: $context received malformed upsert rows");
        }
        foreach ($updateColumns as $column) {
            if (!is_string($column)
                || !in_array($column, $expectedColumns, true)
                || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $column) !== 1) {
                throw new \InvalidArgumentException("wprism: $context received malformed upsert columns");
            }
        }

        $affected = 0;
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== $expectedColumns) {
                throw new \InvalidArgumentException(
                    "wprism: $context requires one exact ordered upsert row shape"
                );
            }
            [$columns, $values] = self::write_fields($table, $row, null, $context);
            $tail = 'ON DUPLICATE KEY UPDATE ' . implode(', ', array_map(
                static fn(string $column): string => "`$column` = VALUES(`$column`)",
                $updateColumns
            ));
            $affected += self::transactional_mutation(
                'INSERT INTO `' . self::table_identifier($table, $context) . "` ($columns) SELECT $values",
                '',
                $tail,
                $authority,
                $context
            );
        }
        return $affected;
    }

    /**
     * Give an otherwise-autocommit DML operation the same declared physical
     * scope, trigger/FK census, query gate, and terminal-outcome semantics as
     * a larger product transaction. The operation may contain a bounded row
     * batch, but the profile still names exactly one physical write target.
     *
     * @param callable(TransactionAuthority):int $operation
     */
    private static function profiled_mutation_scope(
        string $table,
        string $context,
        callable $operation,
        ?NativeDatabaseProfile $profile = null
    ): int {
        $table = self::table_identifier($table, $context);
        $open = false;
        $terminalAttempted = false;
        try {
            self::start(
                $context . ' profiled transaction start',
                $profile ?? new NativeDatabaseProfile([], [$table])
            );
            $open = true;
            $authority = self::transaction_authority($context . ' profiled transaction authority');
            $result = $operation($authority);
            $terminalAttempted = true;
            try {
                self::commit($context . ' profiled transaction commit');
            } catch (DatabaseMutationException|TransientDbException $notCommitted) {
                self::rollback_after_failure(
                    $notCommitted,
                    $context . ' profiled transaction rollback after refused commit'
                );
                $open = false;
                $terminalAttempted = false;
                throw $notCommitted;
            }
            $open = false;
            return $result;
        } catch (\Throwable $failure) {
            if ($open && !$terminalAttempted) {
                self::rollback_after_failure(
                    $failure,
                    $context . ' profiled transaction rollback'
                );
            }
            throw $failure;
        }
    }

    private static function mutation_table_from_head(string $head, string $context): string {
        $structure = self::mutation_structure($head, $context);
        foreach ([
            '/^INSERT(?: IGNORE)? INTO `?([A-Za-z0-9_]{1,64})`?(?=\s|\(|$)/iD',
            '/^UPDATE `?([A-Za-z0-9_]{1,64})`?(?=\s|$)/iD',
            '/^DELETE(?: `?[A-Za-z0-9_]{1,64}`?)? FROM `?([A-Za-z0-9_]{1,64})`?(?=\s|$)/iD',
        ] as $pattern) {
            if (preg_match($pattern, $structure, $match) === 1) {
                return self::table_identifier($match[1], $context);
            }
        }
        throw new \InvalidArgumentException(
            "wprism: $context could not derive one physical mutation table"
        );
    }

    public static function insert_id(string $context): int {
        global $wpdb;
        $id = (int) $wpdb->insert_id;
        if ($id <= 0) {
            throw new DatabaseMutationException($context . ' did not produce an id');
        }
        return $id;
    }

    public static function start(
        string $context,
        NativeDatabaseProfile $profile
    ): void {
        self::before($context);
        self::assert_product_transaction_usable($context);
        self::enter_query_isolation($context);
        try {
            $authority = self::prepare_transaction_start($context);
            self::$pendingStartAuthority = $authority;
            try {
                self::start_control('START TRANSACTION', $context, $authority);
            } catch (\Throwable $startFailure) {
                self::settle_pending_start_or_refuse(
                    $authority,
                    $context . ' refused-start cleanup',
                    $startFailure
                );
                throw $startFailure;
            }
            self::$pendingStartAuthority = null;
            self::establish_profile($profile, $context);
        } catch (\Throwable $failure) {
            self::finish_query_isolation_if_settled();
            throw $failure;
        }
    }

    /** Start a write transaction whose exact next-transaction isolation is controlled. */
    public static function start_repeatable_read(
        string $context,
        NativeDatabaseProfile $profile
    ): void {
        // `@@transaction_isolation`/`@@tx_isolation` report the session
        // default, not a one-shot active-transaction override, while
        // information_schema.innodb_trx requires PROCESS on ordinary WP DB
        // accounts. SET TRANSACTION is accepted by those accounts and applies
        // only to the immediately following START TRANSACTION.
        self::before($context . ' isolation');
        self::before($context);
        self::assert_product_transaction_usable($context);
        self::enter_query_isolation($context);
        try {
            $authority = self::prepare_transaction_start($context);
            self::set_next_transaction_repeatable_read($context, $authority);
            self::start_after_repeatable_read('START TRANSACTION', $context, $authority);
            self::establish_profile($profile, $context);
        } catch (\Throwable $failure) {
            self::finish_query_isolation_if_settled();
            throw $failure;
        }
    }

    /** Start a repeatable-read consistent snapshot through the same outcome proof. */
    public static function start_consistent_snapshot(
        string $context,
        NativeDatabaseProfile $profile
    ): void {
        self::before($context . ' isolation');
        self::before($context);
        self::assert_product_transaction_usable($context);
        self::enter_query_isolation($context);
        try {
            $authority = self::prepare_transaction_start($context);
            self::set_next_transaction_repeatable_read($context, $authority);
            self::start_after_repeatable_read(
                'START TRANSACTION WITH CONSISTENT SNAPSHOT',
                $context,
                $authority
            );
            self::establish_profile($profile, $context);
        } catch (\Throwable $failure) {
            self::finish_query_isolation_if_settled();
            throw $failure;
        }
    }

    /** Start a server-enforced read-only snapshot with exact outcome proof. */
    public static function start_read_only_consistent_snapshot(
        string $context,
        NativeDatabaseProfile $profile
    ): void {
        if (!$profile->is_read_only()) {
            throw new \InvalidArgumentException(
                "wprism: $context received mutation tables for a server-enforced read-only transaction"
            );
        }
        self::before($context . ' isolation');
        self::before($context);
        self::assert_product_transaction_usable($context);
        self::enter_query_isolation($context);
        try {
            $authority = self::prepare_transaction_start($context);
            self::set_next_transaction_repeatable_read($context, $authority);
            self::start_after_repeatable_read(
                'START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT',
                $context,
                $authority
            );
            self::establish_profile($profile, $context);
        } catch (\Throwable $failure) {
            self::finish_query_isolation_if_settled();
            throw $failure;
        }
    }

    public static function commit(string $context = 'transaction commit'): void {
        self::assert_product_transaction_usable($context);
        self::finish_transaction('COMMIT', $context);
    }

    public static function rollback(string $context = 'transaction rollback'): void {
        try {
            self::finish_transaction('ROLLBACK', $context);
        } catch (DatabaseQueryIsolationViolationException $_violation) {
            // The gate quarantined the attempted mutation before WordPress
            // could dispatch it. Permit only this synchronous proof/rollback
            // sequence; a failed attempt leaves the boundary poisoned.
            DatabaseQueryIsolation::prepare_cleanup($context . ' hook-topology cleanup');
            try {
                self::finish_transaction(
                    'ROLLBACK',
                    $context . ' after hook-topology violation'
                );
            } catch (\Throwable $cleanupFailure) {
                DatabaseQueryIsolation::cleanup_failed();
                throw $cleanupFailure;
            }
        }
    }

    /**
     * Roll back only while the exact tracked transaction is still active.
     *
     * A failed/throwing COMMIT can leave the same connection inactive without
     * proving whether authored bytes committed or rolled back. Callers must
     * never run compensation in autocommit in that state. This boundary keeps
     * the original failure in the exception chain and retains unresolved
     * tracking so a later START cannot bury the terminal uncertainty.
     */
    public static function rollback_after_failure(
        \Throwable $primary,
        string $context
    ): void {
        // A throwable's public type/code is provider-constructible. Only the
        // private WeakMap entry minted around WPrism's exact wpdb call proves
        // that errno 1205/1213 came from the bound server transport rather
        // than plugin PHP after an out-of-band COMMIT.
        $serverAbortProven = self::consume_server_abort_evidence($primary);
        try {
            $active = self::failure_cleanup_transaction_active($context);
        } catch (DatabaseQueryIsolationViolationException $_violation) {
            // failure_cleanup_transaction_active() consumes this type itself;
            // retaining this catch protects the public exception contract if
            // a future gate check is added around it.
            DatabaseQueryIsolation::cleanup_failed();
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not establish isolated rollback authority after '
                    . self::failure_fingerprint($primary),
                $primary
            );
        } catch (\Throwable $stateFailure) {
            if (self::$transactionAuthority !== null
                && self::$transactionSavepoint !== null
                && self::$transactionWitnessState === self::WITNESS_VALID
                && self::$transactionCleanupOnly) {
                try {
                    self::rollback($context . ' cleanup-only replacement rollback');
                } catch (\Throwable $rollbackFailure) {
                    DatabaseQueryIsolation::cleanup_failed();
                    throw new DatabaseTransactionOutcomeException(
                        $context . ' could not settle the cleanup-only replacement after '
                            . self::failure_fingerprint($primary)
                            . '; rollback=' . self::failure_fingerprint($rollbackFailure),
                        $primary
                    );
                }
                throw new DatabaseTransactionOutcomeException(
                    $context . ' found the original transaction ended before cleanup after '
                        . self::failure_fingerprint($primary),
                    $primary
                );
            }
            DatabaseQueryIsolation::cleanup_failed();
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not prove rollback authority after '
                    . self::failure_fingerprint($primary)
                    . '; state=' . self::failure_fingerprint($stateFailure),
                $primary
            );
        }
        if (!$active && $serverAbortProven) {
            self::clear_transaction_tracking_state();
            DatabaseQueryIsolation::finish();
            return;
        }
        if (!$active) {
            DatabaseQueryIsolation::cleanup_failed();
            throw new DatabaseTransactionOutcomeException(
                $context . ' found the tracked transaction inactive after '
                    . self::failure_fingerprint($primary),
                $primary
            );
        }
        try {
            self::rollback($context);
        } catch (\Throwable $rollbackFailure) {
            DatabaseQueryIsolation::cleanup_failed();
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not confirm rollback after '
                    . self::failure_fingerprint($primary)
                    . '; rollback=' . self::failure_fingerprint($rollbackFailure),
                $primary
            );
        }
    }

    /**
     * Prove and bind every base table before start*() returns to a feature
     * owner. A failed proof is settled inside the core because the
     * caller cannot yet know that START succeeded and therefore cannot safely
     * decide whether rollback is authorized.
     */
    private static function establish_profile(
        NativeDatabaseProfile $profile,
        string $context
    ): void {
        try {
            $authority = self::transaction_authority($context . ' mutation-scope authority');
            $readTables = $profile->read_tables();
            $writeTables = $profile->write_tables();
            if ($readTables !== [] || $writeTables !== []) {
                // DatabaseLockBoundary prepares account/schema identifiers.
                // Prove the lexer premise before any of those rendered string
                // literals cross wpdb, then keep every metadata read tied to
                // the exact transaction generation established above.
                DatabaseQueryIsolation::assert_profile_sql_mode($context . ' mutation-scope');
                $continuity = static function () use ($authority, $context): void {
                    $current = self::transaction_authority(
                        $context . ' mutation-scope continuity'
                    );
                    if (!$authority->equals($current)) {
                        throw new DatabaseTransactionOutcomeException(
                            $context . ' changed database session authority during mutation-scope proof'
                        );
                    }
                };
                if ($readTables !== []) {
                    DatabaseLockBoundary::assert_innodb_tables(
                        $readTables,
                        $context . ' read scope',
                        $continuity
                    );
                }
                if ($writeTables !== []) {
                    DatabaseLockBoundary::assert_atomic_mutation_tables(
                        $writeTables,
                        $context . ' mutation scope',
                        $continuity
                    );
                }
            }
            DatabaseQueryIsolation::bind_profile($profile, $context . ' database profile');
        } catch (\Throwable $failure) {
            self::rollback_after_failure($failure, $context . ' mutation-scope rollback');
            throw $failure;
        }
    }

    /** Prove cleanup state, entering the gate's one rollback mode if needed. */
    private static function failure_cleanup_transaction_active(string $context): bool {
        try {
            return self::transaction_active($context . ' state proof');
        } catch (DatabaseQueryIsolationViolationException $_violation) {
            DatabaseQueryIsolation::prepare_cleanup($context . ' hook-topology cleanup');
            try {
                return self::transaction_active(
                    $context . ' state proof after hook-topology violation'
                );
            } catch (\Throwable $stateFailure) {
                DatabaseQueryIsolation::cleanup_failed();
                throw $stateFailure;
            }
        }
    }

    /**
     * Recheck the exact connection and active state tracked by start*().
     * Callers use this before invoking rollback participants: an already
     * ended transaction must never be "restored" through autocommit writes.
     */
    public static function transaction_active(string $context): bool {
        $expected = self::$transactionAuthority;
        if ($expected === null) {
            throw new DatabaseTransactionOutcomeException($context . ' has no tracked transaction identity');
        }
        $state = self::transaction_state($context . ' state proof');
        return $state['active'];
    }

    /** Observe the current connection for failure cleanup without requiring ownership. */
    public static function connection_transaction_active(string $context): bool {
        $ownedIsolation = !DatabaseQueryIsolation::is_active();
        if ($ownedIsolation) {
            DatabaseQueryIsolation::begin($context . ' query-filter boundary');
        }
        try {
            $active = self::current_connection_transaction_state($context . ' state proof')['active'];
            if (!$active && self::$transactionAuthority !== null) {
                self::$idleCurrentSessionProven = true;
            }
            return $active;
        } finally {
            if ($ownedIsolation) {
                DatabaseQueryIsolation::finish();
            }
        }
    }

    /** Return the exact active session authority established by start*(). */
    public static function transaction_authority(string $context): TransactionAuthority {
        self::assert_product_transaction_usable($context);
        $expected = self::$transactionAuthority;
        if ($expected === null) {
            throw new DatabaseTransactionOutcomeException($context . ' has no tracked transaction identity');
        }
        $state = self::transaction_state($context . ' state proof');
        if (!$state['active']) {
            throw new DatabaseTransactionOutcomeException($context . ' transaction is not active');
        }
        return $expected;
    }

    /** Forget tracking only after an inactive original or idle replacement proof. */
    public static function forget_transaction_tracking(): void {
        if (self::$pendingStartAuthority !== null
            || self::$pendingIsolationAuthority !== null
            || self::$pendingCleanupAuthority !== null) {
            throw new DatabaseTransactionOutcomeException(
                'transaction tracking cannot be forgotten while cleanup authority is pending'
            );
        }
        if (self::$transactionAuthority !== null
            && self::$transactionWitnessState !== self::WITNESS_INACTIVE
            && !self::$idleCurrentSessionProven) {
            throw new DatabaseTransactionOutcomeException(
                'transaction tracking cannot be forgotten before an idle-session proof'
            );
        }
        self::clear_transaction_tracking_state();
        self::finish_query_isolation_if_settled();
    }

    /** Deterministic failure seam for required non-SQL phases in live tests. */
    public static function checkpoint(string $context): void {
        self::before($context);
        self::assert_product_transaction_usable($context);
    }

    /**
     * @template T
     * @param callable(TransactionAuthority):T $operation
     * @return T
     */
    private static function idle_schema_scope(string $context, callable $operation): mixed {
        self::before($context);
        self::assert_product_transaction_usable($context);
        if (self::$transactionAuthority !== null
            || self::$pendingStartAuthority !== null
            || self::$pendingIsolationAuthority !== null
            || self::$pendingCleanupAuthority !== null) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' requires a settled database transaction boundary'
            );
        }
        if (DatabaseQueryIsolation::is_active()) {
            throw new DatabaseMutationException(
                $context . ' cannot enter an existing database query boundary'
            );
        }

        DatabaseQueryIsolation::begin($context . ' schema query-filter boundary');
        try {
            $authority = self::prepare_transaction_start($context . ' schema preflight');
            return $operation($authority);
        } finally {
            DatabaseQueryIsolation::finish();
        }
    }

    private static function idle_schema_statement(
        string $sql,
        TransactionAuthority $authority,
        string $context
    ): void {
        global $wpdb;
        self::idle_schema_transport(
            $sql,
            $authority,
            $context,
            static fn(string $statement): mixed => $wpdb->query($statement)
        );
    }

    /**
     * @template T
     * @param callable(string):T $operation
     * @return T
     */
    private static function idle_schema_transport(
        string $sql,
        TransactionAuthority $authority,
        string $context,
        callable $operation
    ): mixed {
        global $wpdb;
        self::before($context);
        DatabaseQueryIsolation::assert_active($context . ' schema query-filter boundary');
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $result = null;
        $failure = null;
        try {
            $result = self::permitted_transport($sql, $context, $operation);
        } catch (\Throwable $caught) {
            $failure = $caught;
        }
        $queryError = trim((string) ($wpdb->last_error ?? ''));
        $queryErrno = $failure === null
            ? self::driver_errno()
            : self::strict_driver_errno($failure);

        try {
            $state = self::authority_transaction_state(
                $authority,
                $context . ' schema outcome proof'
            );
        } catch (\Throwable $proofFailure) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not prove the idle schema-operation outcome',
                $failure ?? $proofFailure
            );
        }
        if ($state['active']) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' unexpectedly entered a transaction during schema reconciliation',
                $failure
            );
        }
        if ($failure === null && $result !== false && $queryError === '') {
            return $result;
        }
        if (in_array($queryErrno, [1205, 1213], true)
            || stripos($queryError, 'Deadlock found') !== false
            || stripos($queryError, 'Lock wait timeout') !== false) {
            throw new TransientDbException(
                "wprism: transient DB contention at $context",
                0,
                $failure
            );
        }
        if ($failure instanceof DatabaseQueryIsolationViolationException
            || $failure instanceof DatabaseTransactionOutcomeException) {
            throw $failure;
        }
        throw new DatabaseMutationException($context, $failure);
    }

    private static function table_label(string $table): string {
        global $wpdb;
        $prefix = isset($wpdb->prefix) ? (string) $wpdb->prefix : '';
        return $prefix !== '' && str_starts_with($table, $prefix)
            ? substr($table, strlen($prefix))
            : $table;
    }

    private static function table_identifier(string $table, string $context): string {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
            throw new \InvalidArgumentException("wprism: $context received an unsafe table identifier");
        }
        return $table;
    }

    private static function native_table_identifier(string $table, string $context): string {
        global $wpdb;
        $table = self::table_identifier($table, $context);
        $prefix = isset($wpdb->prefix) ? (string) $wpdb->prefix : '';
        if (($prefix !== '' && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $prefix) !== 1)
            || !str_starts_with($table, $prefix . 'wprism_')
            || strlen($table) <= strlen($prefix . 'wprism_')) {
            throw new \InvalidArgumentException(
                "wprism: $context may reconcile only an engine-owned native table"
            );
        }
        return $table;
    }

    private static function column_identifier(string $column, string $context): string {
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $column) !== 1) {
            throw new \InvalidArgumentException(
                "wprism: $context received an unsafe column identifier"
            );
        }
        return $column;
    }

    /**
     * Render INSERT values or UPDATE assignments with wpdb's format choices.
     *
     * @return array{0:string,1:string} INSERT columns/values, or UPDATE set/''
     */
    private static function write_fields(
        string $table,
        array $data,
        mixed $format,
        string $context,
        bool $assignments = false
    ): array {
        if ($data === []) {
            throw new \InvalidArgumentException("wprism: $context received an empty mutation payload");
        }
        global $wpdb;
        $fields = WpdbFieldCodec::process($wpdb, $table, $data, $format, $context);
        $columns = [];
        $values = [];
        $set = [];
        foreach ($fields as $column => $field) {
            $value = $field['value'];
            $rendered = $value === null
                ? 'NULL'
                : $wpdb->prepare($field['format'], $value);
            if (!is_string($rendered) || $rendered === '') {
                throw new DatabaseMutationException($context . ' could not prepare a database value');
            }
            $quoted = "`$column`";
            $columns[] = $quoted;
            $values[] = $rendered;
            $set[] = "$quoted = $rendered";
        }
        return $assignments
            ? [implode(', ', $set), '']
            : [implode(', ', $columns), implode(', ', $values)];
    }

    /** @return array{0:string} */
    private static function where_fields(
        string $table,
        array $where,
        mixed $format,
        string $context
    ): array {
        if ($where === []) {
            throw new \InvalidArgumentException("wprism: $context received an empty mutation predicate");
        }
        global $wpdb;
        $fields = WpdbFieldCodec::process($wpdb, $table, $where, $format, $context);
        $parts = [];
        foreach ($fields as $column => $field) {
            $value = $field['value'];
            if ($value === null) {
                $parts[] = "`$column` IS NULL";
                continue;
            }
            $rendered = $wpdb->prepare($field['format'], $value);
            if (!is_string($rendered) || $rendered === '') {
                throw new DatabaseMutationException($context . ' could not prepare a predicate value');
            }
            $parts[] = "`$column` = $rendered";
        }
        return [implode(' AND ', $parts)];
    }

    private static function assert_mutation_fragments(
        string $head,
        string $condition,
        string $tail,
        string $context
    ): void {
        $maskedHead = self::mutation_structure($head, $context);
        $maskedCondition = self::mutation_structure($condition, $context);
        $maskedTail = self::mutation_structure($tail, $context);
        $isInsert = preg_match(
            '/^INSERT(?: IGNORE)? INTO `?[A-Za-z0-9_]{1,64}`? '
                . '\(`?[A-Za-z0-9_]{1,64}`?(?:, `?[A-Za-z0-9_]{1,64}`?)*\) SELECT .+$/isD',
            $maskedHead
        ) === 1
            && preg_match('/\b(?:FROM|WHERE|UNION|OUTFILE|DUMPFILE|RETURNING)\b/i', $maskedHead) === 0
            && substr_count(strtoupper($maskedHead), ' SELECT ') === 1;
        $isUpdate = preg_match(
            '/^UPDATE `?[A-Za-z0-9_]{1,64}`? SET .+$/isD',
            $maskedHead
        ) === 1
            && preg_match('/\b(?:WHERE|ORDER\s+BY|LIMIT|RETURNING)\b/i', $maskedHead) === 0;
        $isDelete = preg_match(
            '/^DELETE(?: `?[A-Za-z0-9_]{1,64}`?)? FROM .+$/isD',
            $maskedHead
        ) === 1
            && preg_match('/\b(?:WHERE|ORDER\s+BY|LIMIT|RETURNING)\b/i', $maskedHead) === 0;
        if ((!$isInsert && !$isUpdate && !$isDelete)
            || preg_match('/^WHERE\b/i', $maskedCondition) === 1
            || preg_match(
                '/\b(?:UNION|INTO\s+(?:OUTFILE|DUMPFILE)|PROCEDURE|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE|RETURNING)\b/i',
                $maskedCondition
            ) === 1
            || ($maskedTail !== ''
                && preg_match('/^(?:ON DUPLICATE KEY UPDATE|ORDER BY|LIMIT)\b/i', $maskedTail) !== 1)
            || preg_match(
                '/\b(?:UNION|INTO\s+(?:OUTFILE|DUMPFILE)|PROCEDURE|FOR\s+UPDATE|LOCK\s+IN\s+SHARE\s+MODE|RETURNING)\b/i',
                $maskedTail
            ) === 1) {
            throw new \InvalidArgumentException("wprism: $context received malformed mutation fragments");
        }
    }

    /**
     * Mask quoted values while rejecting statement/comment boundaries outside
     * them. Authority is appended after these fragments; an unquoted comment
     * or semicolon would otherwise be able to detach that predicate on replay.
     */
    private static function mutation_structure(string $sql, string $context): string {
        $masked = '';
        $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            $byte = $sql[$index];
            $ord = ord($byte);
            if (($ord < 32 && !in_array($byte, ["\t", "\n", "\r"], true)) || $ord === 127) {
                throw new \InvalidArgumentException(
                    "wprism: $context received control bytes in mutation SQL"
                );
            }
            if ($byte === ';' || $byte === '#'
                || ($byte === '-' && ($sql[$index + 1] ?? '') === '-')
                || ($byte === '/' && ($sql[$index + 1] ?? '') === '*')) {
                throw new \InvalidArgumentException(
                    "wprism: $context received a comment or statement delimiter in mutation SQL"
                );
            }
            if ($byte === '`') {
                $end = strpos($sql, '`', $index + 1);
                if ($end === false
                    || preg_match('/^[A-Za-z0-9_$]{1,64}$/D', substr($sql, $index + 1, $end - $index - 1)) !== 1) {
                    throw new \InvalidArgumentException(
                        "wprism: $context received an unsafe quoted identifier in mutation SQL"
                    );
                }
                $masked .= substr($sql, $index, $end - $index + 1);
                $index = $end;
                continue;
            }
            if ($byte === '"') {
                throw new \InvalidArgumentException(
                    "wprism: $context received dialect-dependent double quotes in mutation SQL"
                );
            }
            if ($byte !== "'") {
                $masked .= $byte;
                continue;
            }
            $masked .= "''";
            $closed = false;
            for ($index++; $index < $length; $index++) {
                if ($sql[$index] === '\\') {
                    if ($index + 1 >= $length) {
                        break;
                    }
                    $index++;
                    continue;
                }
                if ($sql[$index] !== "'") {
                    continue;
                }
                if (($sql[$index + 1] ?? '') === "'") {
                    $index++;
                    continue;
                }
                $closed = true;
                break;
            }
            if (!$closed) {
                throw new \InvalidArgumentException(
                    "wprism: $context received an unterminated mutation value"
                );
            }
        }
        $normalized = preg_replace('/\s+/', ' ', trim($masked));
        return is_string($normalized) ? $normalized : trim($masked);
    }

    private static function assemble_mutation(
        string $head,
        string $condition,
        string $tail,
        ?TransactionAuthority $authority
    ): string {
        $clauses = [];
        if ($authority !== null) {
            global $wpdb;
            $clauses[] = $wpdb->prepare(
                'CONNECTION_ID() = %s AND BINARY @wprism_tx_session = BINARY %s',
                $authority->connection_id(),
                $authority->session_nonce()
            );
        }
        if (trim($condition) !== '') {
            $clauses[] = '(' . trim($condition) . ')';
        }
        return rtrim($head)
            . ($clauses === [] ? '' : "\nWHERE " . implode("\nAND ", $clauses))
            . (trim($tail) === '' ? '' : "\n" . ltrim($tail));
    }

    private static function prepare_transaction_start(string $context): TransactionAuthority {
        if (self::$pendingStartAuthority !== null) {
            $pending = self::$pendingStartAuthority;
            $unresolved = new DatabaseTransactionOutcomeException(
                $context . ' found an unresolved data-free transaction start'
            );
            self::settle_pending_start_or_refuse(
                $pending,
                $context . ' unresolved-start cleanup',
                $unresolved,
                false
            );
            throw $unresolved;
        }
        // Settle a possibly replayed one-shot SET before consulting the old
        // transaction witness. The old witness can legitimately be missing
        // after START was replayed on a replacement session; probing it first
        // would prevent the only bounded, data-free cleanup still available.
        if (self::$pendingIsolationAuthority !== null) {
            $pending = self::$pendingIsolationAuthority;
            $unresolved = new DatabaseTransactionOutcomeException(
                $context . ' found an unresolved one-shot transaction isolation control'
            );
            self::settle_pending_isolation_or_refuse(
                $pending,
                $context . ' unresolved-isolation cleanup',
                $unresolved,
                self::$pendingIsolationStartAttempted,
                false
            );
            throw $unresolved;
        }
        if (self::$transactionAuthority !== null) {
            $state = self::transaction_state($context . ' preflight');
            if ($state['active']) {
                throw new DatabaseMutationException($context . ' found an already-active transaction');
            }
            // An inactive connection with retained tracking is not an idle
            // slate: a prior terminal control crossed an outcome boundary and
            // the caller has not supplied its product-specific physical
            // classification yet. Silently clearing that witness would let a
            // new transaction bury commit-vs-rollback uncertainty.
            throw new DatabaseTransactionOutcomeException(
                $context . ' found an unresolved inactive transaction outcome'
            );
        }
        $state = self::current_connection_transaction_state($context . ' preflight');
        if ($state['active']) {
            throw new DatabaseMutationException($context . ' requires an idle database connection');
        }
        return self::bind_session_authority($context . ' session authority', false);
    }

    /**
     * Converge an ambiguous plain START. No product DML can run before this
     * internal boundary returns, so an active replacement transaction may be
     * freshly bound and rolled back; an idle current session already proves
     * that no live transaction remains.
     */
    private static function settle_pending_start_or_refuse(
        TransactionAuthority $originalAuthority,
        string $context,
        \Throwable $primary,
        bool $mayAdoptCurrent = true
    ): void {
        try {
            if (self::$pendingStartAuthority === null
                || !self::$pendingStartAuthority->equals($originalAuthority)) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' no longer owns the pending transaction start',
                    $primary
                );
            }
            if (self::$pendingCleanupAuthority !== null) {
                self::finish_pending_cleanup($context, $primary);
                self::$pendingStartAuthority = null;
                return;
            }
            if (!$mayAdoptCurrent) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' has no exact retained cleanup authority',
                    $primary
                );
            }
            $state = self::current_connection_transaction_state($context . ' preflight');
            if (!$state['active']) {
                self::clear_transaction_tracking_state();
                self::$pendingStartAuthority = null;
                return;
            }
            self::clear_transaction_tracking_state();
            $cleanupAuthority = self::bind_session_authority(
                $context . ' adopted session authority',
                true
            );
            self::$transactionAuthority = $cleanupAuthority;
            self::install_transaction_witness($context . ' adopted continuity witness');
            self::$pendingCleanupAuthority = $cleanupAuthority;
            self::finish_transaction('ROLLBACK', $context . ' rollback');
            self::$pendingCleanupAuthority = null;
            self::$pendingStartAuthority = null;
        } catch (\Throwable $cleanupFailure) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not settle an uncertain data-free transaction start; cleanup='
                    . self::failure_fingerprint($cleanupFailure),
                $primary
            );
        }
    }

    private static function set_next_transaction_repeatable_read(
        string $context,
        TransactionAuthority $authority
    ): void {
        global $wpdb;
        DatabaseQueryIsolation::assert_active($context . ' isolation query-filter boundary');
        $isolationContext = $context . ' isolation';
        self::$pendingIsolationAuthority = $authority;
        self::$pendingIsolationStartAttempted = false;
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $result = false;
        $queryFailure = null;
        try {
            $result = self::permitted_control_query(
                'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
                $isolationContext
            );
        } catch (\Throwable $failure) {
            $queryFailure = $failure;
        }
        $queryError = trim((string) ($wpdb->last_error ?? ''));
        try {
            $state = self::authority_transaction_state(
                $authority,
                $context . ' isolation connection proof'
            );
        } catch (\Throwable $proofFailure) {
            self::settle_pending_isolation_or_refuse(
                $authority,
                $isolationContext . ' state-proof cleanup',
                $proofFailure,
                false,
                true
            );
            throw new DatabaseTransactionOutcomeException(
                $context . ' isolation control outcome could not be proven',
                $proofFailure
            );
        }
        if ($state['active']) {
            $unexpected = new DatabaseTransactionOutcomeException(
                $context . ' isolation control unexpectedly entered a transaction'
            );
            self::settle_pending_isolation_or_refuse(
                $authority,
                $isolationContext . ' unexpected-active cleanup',
                $unexpected,
                false,
                true
            );
            throw $unexpected;
        }
        if ($queryFailure === null && $result !== false && $queryError === '') {
            return;
        }

        // SET TRANSACTION is a one-shot session instruction. A driver can
        // report false/throw after the server accepted it; returning to the
        // caller would contaminate the next unrelated transaction on this WP
        // connection. Consume either the pending override or the ordinary
        // default through one same-connection transaction, then roll it back.
        // Both worlds converge to an idle connection with no pending one-shot
        // state before the original SET failure is reported.
        self::settle_pending_isolation_or_refuse(
            $authority,
            $isolationContext . ' failed-control cleanup',
            $queryFailure ?? new DatabaseMutationException($isolationContext),
            false,
            true
        );
        if ($queryFailure !== null) {
            throw new DatabaseMutationException($isolationContext, $queryFailure);
        }
        self::throw_control_failure($isolationContext, $queryError);
    }

    private static function start_after_repeatable_read(
        string $sql,
        string $context,
        TransactionAuthority $authority
    ): void {
        self::$pendingIsolationStartAttempted = true;
        try {
            self::start_control($sql, $context, $authority);
            // An active transaction positively consumes the one-shot setting.
            self::$pendingIsolationAuthority = null;
            self::$pendingIsolationStartAttempted = false;
        } catch (\Throwable $startFailure) {
            self::settle_pending_isolation_or_refuse(
                $authority,
                $context . ' refused-start isolation cleanup',
                $startFailure,
                true,
                true
            );
            throw $startFailure;
        }
    }

    private static function settle_pending_isolation_or_refuse(
        TransactionAuthority $originalAuthority,
        string $context,
        \Throwable $primary,
        bool $startWasAttempted,
        bool $mayAdoptCurrent = true
    ): void {
        try {
            if (self::$pendingIsolationAuthority === null
                || !self::$pendingIsolationAuthority->equals($originalAuthority)) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' no longer owns the pending isolation control',
                    $primary
                );
            }
            if (self::$pendingCleanupAuthority !== null) {
                self::finish_pending_cleanup($context, $primary);
                self::$pendingIsolationAuthority = null;
                self::$pendingIsolationStartAttempted = false;
                return;
            }
            if (!$mayAdoptCurrent) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' has no exact retained cleanup authority',
                    $primary
                );
            }
            // wpdb can reconnect and replay either SET TRANSACTION or START
            // TRANSACTION. The replacement session may therefore hold the
            // one-shot characteristic or an empty active transaction. Probe
            // the CURRENT generation, consume/rollback it, and only then
            // clear process-local pending state.
            $state = self::current_connection_transaction_state($context . ' preflight');
            if ($state['active']) {
                if (!$startWasAttempted) {
                    throw new DatabaseTransactionOutcomeException(
                        $context . ' found an active transaction before START was attempted',
                        $primary
                    );
                }
                // No product DML can precede this internal START boundary.
                // Bind the replacement session, witness its empty transaction,
                // and roll it back instead of abandoning a replayed START.
                self::clear_transaction_tracking_state();
                $cleanupAuthority = self::bind_session_authority(
                    $context . ' adopted session authority',
                    true
                );
                self::$transactionAuthority = $cleanupAuthority;
                self::install_transaction_witness($context . ' adopted continuity witness');
                self::$pendingCleanupAuthority = $cleanupAuthority;
            } else {
                // Starting one data-free transaction consumes a replayed
                // one-shot override; it is also harmless when no replay
                // occurred. A replaced/disconnected generation can retain no
                // live transaction, so its local witness is safe to retire.
                self::clear_transaction_tracking_state();
                $cleanupAuthority = self::bind_session_authority(
                    $context . ' cleanup session authority',
                    false
                );
                // Persist this transition before the control crosses the
                // client/server boundary. If its outcome proof is lost, the
                // next call may safely adopt and roll back the data-free
                // cleanup transaction instead of refusing forever.
                self::$pendingIsolationStartAttempted = true;
                self::start_control(
                    'START TRANSACTION',
                    $context . ' start',
                    $cleanupAuthority
                );
                self::$pendingCleanupAuthority = $cleanupAuthority;
            }
            self::finish_transaction('ROLLBACK', $context . ' rollback');
            // START has consumed a replayed one-shot setting and ROLLBACK has
            // positively returned the current session to idle. Only this
            // terminal proof permits the process-local pending state to clear.
            self::$pendingIsolationAuthority = null;
            self::$pendingCleanupAuthority = null;
            self::$pendingIsolationStartAttempted = false;
        } catch (\Throwable $cleanupFailure) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not consume an uncertain one-shot isolation control; cleanup='
                    . self::failure_fingerprint($cleanupFailure),
                $primary
            );
        }
    }

    /** Retry only a cleanup transaction whose exact witness survived. */
    private static function finish_pending_cleanup(
        string $context,
        \Throwable $primary
    ): void {
        $cleanup = self::$pendingCleanupAuthority;
        if ($cleanup === null
            || self::$transactionAuthority === null
            || self::$transactionSavepoint === null
            || !$cleanup->equals(self::$transactionAuthority)) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' lost its exact retained cleanup authority',
                $primary
            );
        }
        self::finish_transaction('ROLLBACK', $context . ' retained rollback');
        self::$pendingCleanupAuthority = null;
    }

    private static function start_control(
        string $sql,
        string $context,
        TransactionAuthority $authority
    ): void {
        global $wpdb;
        DatabaseQueryIsolation::assert_active($context . ' query-filter boundary');
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $result = false;
        $queryFailure = null;
        try {
            $result = self::permitted_control_query($sql, $context);
        } catch (\Throwable $failure) {
            $queryFailure = $failure;
        }
        $queryError = trim((string) ($wpdb->last_error ?? ''));
        try {
            $state = self::authority_transaction_state($authority, $context . ' outcome proof');
        } catch (\Throwable $proofFailure) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not prove whether START was applied',
                $proofFailure
            );
        }
        if ($state['active']) {
            // The server state is authoritative even if the client returned
            // false after applying START. The tracked identity lets later
            // COMMIT/ROLLBACK distinguish connection replacement.
            self::$transactionAuthority = $authority;
            self::install_transaction_witness($context . ' continuity witness');
            return;
        }
        if ($queryFailure !== null) {
            $normalized = self::normalize_transaction_failure($queryFailure, $context);
            if ($normalized instanceof DeadlockTransactionAbortedException
                || $normalized instanceof TransientDbException) {
                throw $normalized;
            }
            throw new DatabaseMutationException($context, $queryFailure);
        }
        if ($result === false || $queryError !== '') {
            self::throw_control_failure($context, $queryError);
        }
        throw new DatabaseTransactionOutcomeException($context . ' reported success without starting');
    }

    private static function finish_transaction(string $control, string $context): void {
        global $wpdb;
        DatabaseQueryIsolation::assert_active($context . ' query-filter boundary');
        // completion_type is a mutable session setting on both supported
        // engines. Bare COMMIT/ROLLBACK may therefore chain a new transaction
        // or release the connection, which would make the witness post-proof
        // describe a different boundary than the control the caller asked us
        // to finish. Pin both modifiers on every terminal control.
        $sql = match ($control) {
            'COMMIT' => 'COMMIT AND NO CHAIN NO RELEASE',
            'ROLLBACK' => 'ROLLBACK AND NO CHAIN NO RELEASE',
            default => throw new \LogicException('unsupported transaction terminal control'),
        };
        self::before($context);
        $expected = self::$transactionAuthority;
        if ($expected === null) {
            throw new DatabaseTransactionOutcomeException($context . ' has no tracked transaction identity');
        }
        $before = self::transaction_state($context . ' preflight');
        if (!$before['active']) {
            throw new DatabaseTransactionOutcomeException($context . ' transaction ended before control');
        }

        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $result = false;
        $queryFailure = null;
        try {
            $result = self::permitted_control_query($sql, $context);
        } catch (\Throwable $failure) {
            $queryFailure = $failure;
        }
        $queryError = trim((string) ($wpdb->last_error ?? ''));
        try {
            $after = self::transaction_state($context . ' outcome proof');
        } catch (\Throwable $proofFailure) {
            self::$transactionCleanupOnly = true;
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not prove the terminal transaction outcome',
                $proofFailure
            );
        }
        if (!$after['active']) {
            if ($control === 'ROLLBACK') {
                // Every same-connection inactive outcome of an attempted
                // ROLLBACK is safe for the caller's compensation boundary:
                // authored bytes cannot have committed through this control.
                self::clear_transaction_tracking_state();
                DatabaseQueryIsolation::finish();
                return;
            }
            if ($queryFailure === null && $result !== false && $queryError === '') {
                self::clear_transaction_tracking_state();
                DatabaseQueryIsolation::finish();
                return;
            }
            // Active->inactive alone cannot tell an accepted COMMIT from a
            // server-side rollback/rejection. Retain tracking so no later
            // transaction can erase the ambiguity before the caller checks a
            // physical product postimage and explicitly forgets it.
            self::$transactionCleanupOnly = true;
            throw new DatabaseTransactionOutcomeException(
                $context . ' ended after an unconfirmed commit control',
                $queryFailure
            );
        }
        if ($queryFailure !== null) {
            throw new DatabaseMutationException($context, $queryFailure);
        }
        if ($result === false || $queryError !== '') {
            self::throw_control_failure($context, $queryError);
        }
        self::$transactionCleanupOnly = true;
        throw new DatabaseTransactionOutcomeException($context . ' reported success without ending');
    }

    private static function throw_control_failure(string $context, string $driverError): never {
        if (stripos($driverError, 'Deadlock found') !== false) {
            throw new DeadlockTransactionAbortedException(
                "wprism: database deadlock aborted the transaction at $context"
            );
        }
        if (stripos($driverError, 'Lock wait timeout') !== false) {
            throw new TransientDbException("wprism: transient DB contention at $context");
        }
        throw new DatabaseMutationException($context);
    }

    private static function failure_fingerprint(\Throwable $failure): string {
        $message = $failure->getMessage();
        return get_class($failure) . ':' . strlen($message) . ':'
            . substr(hash('sha256', $message), 0, 16);
    }

    private static function record_server_abort_evidence(\Throwable $failure, int $errno): void {
        if (!in_array($errno, [1205, 1213], true) || self::$transactionAuthority === null) {
            return;
        }
        self::$serverAbortEvidence ??= new \WeakMap();
        self::$serverAbortEvidence[$failure] = [
            'authority' => self::$transactionAuthority,
            'errno' => $errno,
        ];
    }

    private static function consume_server_abort_evidence(\Throwable $failure): bool {
        if (self::$serverAbortEvidence === null || !isset(self::$serverAbortEvidence[$failure])) {
            return false;
        }
        $evidence = self::$serverAbortEvidence[$failure];
        unset(self::$serverAbortEvidence[$failure]);
        return in_array($evidence['errno'], [1205, 1213], true)
            && self::$transactionAuthority !== null
            && $evidence['authority']->equals(self::$transactionAuthority);
    }

    /** Read the numeric driver state without issuing another SQL statement. */
    private static function driver_errno(): int {
        global $wpdb;
        if (is_object($wpdb)
            && get_class($wpdb) === 'WPrismTest\\FakeWpdb'
            && method_exists($wpdb, 'wprism_test_driver_errno')) {
            $errno = $wpdb->wprism_test_driver_errno();
            return is_int($errno) && $errno >= 0 ? $errno : 0;
        }
        if (!is_object($wpdb) || !property_exists($wpdb, 'dbh') || !class_exists('mysqli', false)) {
            return 0;
        }
        try {
            $property = new \ReflectionProperty($wpdb, 'dbh');
            $handle = $property->getValue($wpdb);
            return $handle instanceof \mysqli ? mysqli_errno($handle) : 0;
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * Read an errno only from the exception type emitted synchronously by the
     * strict mysqli transport. A provider-created RuntimeException with a
     * convenient numeric code is not server evidence.
     */
    private static function strict_driver_errno(\Throwable $failure): int {
        if (!class_exists('mysqli_sql_exception', false)
            || !$failure instanceof \mysqli_sql_exception) {
            return 0;
        }
        $errno = $failure->getCode();
        return is_int($errno) && $errno >= 0 ? $errno : 0;
    }

    /**
     * Read transaction state without engine-specific session variables.
     *
     * MariaDB exposes @@in_transaction; MySQL 8.4 does not. Both engines
     * accept SAVEPOINT outside a transaction but create nothing, so RELEASE
     * returns error 1305. A tracked transaction keeps a random savepoint and
     * rotates it on every check; unlike a fresh state probe, that proves the
     * ORIGINAL transaction survived rather than merely finding a replacement
     * transaction on the same connection.
     *
     * @return array{identity:array{connection_id:string,autocommit:string,session_nonce_bytes:?string,session_nonce:?string},active:bool}
     */
    private static function transaction_state(string $context): array {
        $authority = self::$transactionAuthority;
        if ($authority === null) {
            throw new DatabaseTransactionOutcomeException($context . ' has no tracked transaction identity');
        }
        if (self::$transactionWitnessState === self::WITNESS_LOST_UNKNOWN) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' lost the original transaction continuity witness'
            );
        }
        if (self::$transactionWitnessState === self::WITNESS_INACTIVE) {
            return [
                'identity' => [
                    'connection_id' => $authority->connection_id(),
                    'autocommit' => '1',
                    'session_nonce_bytes' => '64',
                    'session_nonce' => $authority->session_nonce(),
                ],
                'active' => false,
            ];
        }
        if (self::$transactionWitnessState !== self::WITNESS_VALID) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' has no valid transaction continuity witness'
            );
        }
        return self::observe_transaction_state($context, $authority, true);
    }

    /**
     * Prove a just-bound session before a persistent transaction witness exists.
     *
     * @return array{identity:array{connection_id:string,autocommit:string,session_nonce_bytes:?string,session_nonce:?string},active:bool}
     */
    private static function authority_transaction_state(
        TransactionAuthority $authority,
        string $context
    ): array {
        return self::observe_transaction_state($context, $authority, false);
    }

    /**
     * Probe whatever transaction is active now, independent of an older
     * tracked generation. Failure cleanup uses this distinction: a missing
     * old witness does not mean a replacement transaction is safe to write in.
     *
     * @return array{identity:array{connection_id:string,autocommit:string,session_nonce_bytes:?string,session_nonce:?string},active:bool}
     */
    private static function current_connection_transaction_state(string $context): array {
        return self::observe_transaction_state($context, null, false);
    }

    /**
     * @return array{identity:array{connection_id:string,autocommit:string,session_nonce_bytes:?string,session_nonce:?string},active:bool}
     */
    private static function observe_transaction_state(
        string $context,
        ?TransactionAuthority $expected,
        bool $useTrackedWitness
    ): array {
        DatabaseQueryIsolation::assert_active($context . ' query-filter boundary');
        $before = self::read_session_identity($context . ' identity preflight');
        if ($expected !== null && !self::identity_matches_authority($before, $expected)) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' changed database session authority'
            );
        }
        if ($useTrackedWitness) {
            if (self::$transactionAuthority === null
                || $expected === null
                || !self::$transactionAuthority->equals($expected)
                || self::$transactionSavepoint === null
                || self::$transactionWitnessState !== self::WITNESS_VALID) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' has no tracked transaction continuity witness'
                );
            }
            $active = self::rotate_transaction_witness($context);
        } else {
            $probe = self::savepoint_name();
            self::require_savepoint_control(
                "SAVEPOINT `$probe`",
                $context . ' state-probe create'
            );
            $active = self::release_savepoint(
                $probe,
                $context . ' state-probe release',
                true
            );
        }
        $after = self::read_session_identity($context . ' identity postflight');
        // wpdb exposes connection identity and the savepoint controls through
        // separate calls. Sandwich both the numeric id and private session
        // nonce so even a reconnect that reuses an id cannot authorize an
        // outcome belonging to the old physical session.
        if (!self::same_session_identity($before, $after)
            || ($expected !== null && !self::identity_matches_authority($after, $expected))) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' changed database session while reading transaction state'
            );
        }
        return ['identity' => $after, 'active' => $active];
    }

    /**
     * Establish a nonce on the current session and accept ambiguous client
     * results only when a subsequent server observation proves that exact
     * nonce and the expected transaction state on the same numeric id.
     */
    private static function bind_session_authority(
        string $context,
        bool $expectedActive
    ): TransactionAuthority {
        global $wpdb;
        DatabaseQueryIsolation::assert_active($context . ' query-filter boundary');
        $before = self::current_connection_transaction_state($context . ' preflight');
        if ($before['active'] !== $expectedActive) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' encountered an unexpected transaction state'
            );
        }
        $authority = new TransactionAuthority(
            $before['identity']['connection_id'],
            bin2hex(random_bytes(32))
        );
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $queryFailure = null;
        try {
            $sql = $wpdb->prepare(
                'SET @wprism_tx_session = %s',
                $authority->session_nonce()
            );
            if (!is_string($sql) || $sql === '') {
                throw new DatabaseMutationException($context . ' could not prepare session authority');
            }
            DatabaseQueryIsolation::assert_active($context . ' prepared-query boundary');
            self::permitted_control_query($sql, $context);
        } catch (\Throwable $failure) {
            $queryFailure = $failure;
        }
        try {
            $after = self::authority_transaction_state($authority, $context . ' outcome proof');
        } catch (\Throwable $proofFailure) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not bind the database session authority',
                $queryFailure ?? $proofFailure
            );
        }
        if ($after['active'] !== $expectedActive) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' changed transaction state while binding session authority',
                $queryFailure
            );
        }
        return $authority;
    }

    /**
     * Read a bounded session fingerprint. LEFT prevents an unrelated writer
     * from making this diagnostic transfer unbounded; only a 64-byte lowercase
     * hexadecimal value can ever match an authored authority.
     *
     * @return array{connection_id:string,autocommit:string,session_nonce_bytes:?string,session_nonce:?string}
     */
    private static function read_session_identity(string $context): array {
        global $wpdb;
        DatabaseQueryIsolation::assert_active($context . ' query-filter boundary');
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $sql = 'SELECT CONNECTION_ID() AS connection_id, @@SESSION.autocommit AS autocommit, '
                . 'OCTET_LENGTH(@wprism_tx_session) AS session_nonce_bytes, '
                . 'LEFT(@wprism_tx_session, 64) AS session_nonce';
        $row = self::permitted_transport(
            $sql,
            $context,
            static fn(string $statement): mixed => $wpdb->get_row($statement, ARRAY_A)
        );
        $error = trim((string) ($wpdb->last_error ?? ''));
        if (!is_array($row) || $error !== '') {
            throw new DatabaseTransactionOutcomeException(
                $context . ' returned a malformed database session identity'
            );
        }
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        $connectionId = $row['connection_id'] ?? null;
        $autocommit = $row['autocommit'] ?? null;
        $nonceBytes = $row['session_nonce_bytes'] ?? null;
        $nonce = $row['session_nonce'] ?? null;
        if ($keys !== ['autocommit', 'connection_id', 'session_nonce', 'session_nonce_bytes']
            || !is_string($connectionId)
            || preg_match('/^[1-9][0-9]*$/D', $connectionId) !== 1
            || $autocommit !== '1'
            || ($nonceBytes !== null
                && (!is_string($nonceBytes)
                    || preg_match('/^(0|[1-9][0-9]*)$/D', $nonceBytes) !== 1))
            || ($nonce !== null && !is_string($nonce))
            || ($nonceBytes === null) !== ($nonce === null)) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' returned a malformed database session identity'
            );
        }
        return [
            'connection_id' => $connectionId,
            'autocommit' => $autocommit,
            'session_nonce_bytes' => $nonceBytes,
            'session_nonce' => $nonce,
        ];
    }

    /**
     * @param array{connection_id:string,autocommit:string,session_nonce_bytes:?string,session_nonce:?string} $identity
     */
    private static function identity_matches_authority(
        array $identity,
        TransactionAuthority $authority
    ): bool {
        return hash_equals($authority->connection_id(), $identity['connection_id'])
            && $identity['session_nonce_bytes'] === '64'
            && is_string($identity['session_nonce'])
            && hash_equals($authority->session_nonce(), $identity['session_nonce']);
    }

    /**
     * @param array{connection_id:string,autocommit:string,session_nonce_bytes:?string,session_nonce:?string} $left
     * @param array{connection_id:string,autocommit:string,session_nonce_bytes:?string,session_nonce:?string} $right
     */
    private static function same_session_identity(array $left, array $right): bool {
        if (!hash_equals($left['connection_id'], $right['connection_id'])
            || $left['autocommit'] !== $right['autocommit']
            || $left['session_nonce_bytes'] !== $right['session_nonce_bytes']) {
            return false;
        }
        if ($left['session_nonce'] === null || $right['session_nonce'] === null) {
            return $left['session_nonce'] === $right['session_nonce'];
        }
        return hash_equals($left['session_nonce'], $right['session_nonce']);
    }

    /** Install the persistent witness immediately after START is proven active. */
    private static function install_transaction_witness(string $context): void {
        $name = self::savepoint_name();
        self::$transactionWitnessState = self::WITNESS_LOST_UNKNOWN;
        self::require_savepoint_control("SAVEPOINT `$name`", $context . ' create');
        // SAVEPOINT alone also succeeds outside a transaction. ROLLBACK TO is
        // the positive existence proof and leaves the witness installed.
        self::require_savepoint_control(
            "ROLLBACK TO SAVEPOINT `$name`",
            $context . ' existence proof'
        );
        // Publish process-local authority only after the server proved this
        // exact name exists. A partial install must never be resumable as a
        // trusted cleanup transaction on a later public call.
        self::$transactionSavepoint = $name;
        self::$transactionWitnessState = self::WITNESS_VALID;
        self::$transactionCleanupOnly = false;
    }

    /** Return false only when server error 1305 proves the old witness is gone. */
    private static function rotate_transaction_witness(string $context): bool {
        $old = self::$transactionSavepoint;
        $authority = self::$transactionAuthority;
        if ($old === null) {
            throw new DatabaseTransactionOutcomeException($context . ' has no transaction witness to rotate');
        }
        if ($authority === null || self::$transactionWitnessState !== self::WITNESS_VALID) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' cannot rotate an unproven transaction witness'
            );
        }
        // RELEASE proves the old generation without rewinding product DML.
        // MySQL and MariaDB also discard every later savepoint when an older
        // one is released, so the replacement MUST be installed afterwards.
        // The session nonce intentionally survives COMMIT; the old random
        // savepoint is what distinguishes the original transaction from a new
        // transaction on that same session.
        self::$transactionWitnessState = self::WITNESS_LOST_UNKNOWN;
        try {
            $released = self::release_savepoint(
                $old,
                $context . ' retained witness proof',
                true
            );
        } catch (\Throwable $releaseFailure) {
            self::converge_lost_witness_for_cleanup(
                $context . ' retained witness recovery',
                $releaseFailure
            );
        }
        if (!$released) {
            return self::classify_missing_transaction_witness(
                $context . ' missing retained witness',
                $authority
            );
        }

        // Reuse the process-local name so a SAVEPOINT/ROLLBACK-TO response
        // lost after server apply remains discoverable on the next proof. The
        // existence proof cannot rewind product state: RELEASE removed the old
        // checkpoint first, this replacement was created after every caller
        // statement, and no DML runs between SAVEPOINT and ROLLBACK TO.
        try {
            self::require_savepoint_control(
                "SAVEPOINT `$old`",
                $context . ' witness replacement create'
            );
            self::require_savepoint_control(
                "ROLLBACK TO SAVEPOINT `$old`",
                $context . ' witness replacement existence proof'
            );
        } catch (\Throwable $replacementFailure) {
            self::converge_lost_witness_for_cleanup(
                $context . ' witness replacement recovery',
                $replacementFailure
            );
        }
        self::$transactionWitnessState = self::WITNESS_VALID;
        return true;
    }

    /**
     * Distinguish an idle session from COMMIT/ROLLBACK AND CHAIN.
     *
     * The session nonce survives both controls, while either erases every old
     * savepoint. A never-before-used savepoint exists only in an active
     * replacement transaction; keeping it as rollback-only authority prevents
     * the caller from accepting that replacement as the original snapshot.
     */
    private static function classify_missing_transaction_witness(
        string $context,
        TransactionAuthority $authority
    ): bool {
        $fresh = self::savepoint_name();
        $createFailure = null;
        try {
            $before = self::read_session_identity($context . ' identity preflight');
            if (!self::identity_matches_authority($before, $authority)) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' changed database session authority'
                );
            }
            try {
                self::require_savepoint_control(
                    "SAVEPOINT `$fresh`",
                    $context . ' replacement-state probe create'
                );
            } catch (\Throwable $failure) {
                $createFailure = $failure;
            }
            $active = self::rollback_to_savepoint(
                $fresh,
                $context . ' replacement-state probe existence',
                true
            );
            $after = self::read_session_identity($context . ' identity postflight');
            if (!self::same_session_identity($before, $after)
                || !self::identity_matches_authority($after, $authority)) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' changed database session during replacement-state proof'
                );
            }
            if (!$active) {
                if ($createFailure !== null) {
                    throw new DatabaseTransactionOutcomeException(
                        $context . ' could not distinguish an idle session from a failed active-session probe',
                        $createFailure
                    );
                }
                self::$transactionWitnessState = self::WITNESS_INACTIVE;
                return false;
            }
            self::$transactionSavepoint = $fresh;
            self::$transactionWitnessState = self::WITNESS_VALID;
            self::$transactionCleanupOnly = true;
        } catch (\Throwable $failure) {
            self::$transactionWitnessState = self::WITNESS_LOST_UNKNOWN;
            self::$transactionCleanupOnly = true;
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not prove whether a replacement transaction is active',
                $failure
            );
        }
        throw new DatabaseTransactionOutcomeException(
            $context . ' found a replacement transaction after the original witness ended'
        );
    }

    /**
     * Re-establish a cleanup-only witness before an ambiguous rotation unwinds.
     *
     * The fresh random name has never denoted the pre-product checkpoint. A
     * successful ROLLBACK TO therefore proves the new witness exists without
     * rewinding authored DML, whether the preceding RELEASE reached the server
     * or not. Any uncertain step leaves LOST_UNKNOWN, after which public calls
     * send no more SQL on this session.
     */
    private static function converge_lost_witness_for_cleanup(
        string $context,
        \Throwable $primary
    ): never {
        $authority = self::$transactionAuthority;
        if ($authority === null) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' has no authority for witness recovery',
                $primary
            );
        }
        $fresh = self::savepoint_name();
        try {
            $before = self::read_session_identity($context . ' identity preflight');
            if (!self::identity_matches_authority($before, $authority)) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' changed database session authority'
                );
            }

            // A lost response from SAVEPOINT is resolved by ROLLBACK TO the
            // never-before-used name. 1305 means no witness was installed;
            // success proves existence at the post-product position.
            try {
                self::require_savepoint_control(
                    "SAVEPOINT `$fresh`",
                    $context . ' fresh witness create'
                );
            } catch (\Throwable $_createFailure) {
                // Continue only to the non-destructive existence proof below.
            }
            if (!self::rollback_to_savepoint(
                $fresh,
                $context . ' fresh witness existence proof',
                true
            )) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' could not establish a fresh transaction witness'
                );
            }
            $after = self::read_session_identity($context . ' identity postflight');
            if (!self::same_session_identity($before, $after)
                || !self::identity_matches_authority($after, $authority)) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' changed database session during witness recovery'
                );
            }
            self::$transactionSavepoint = $fresh;
            self::$transactionWitnessState = self::WITNESS_VALID;
            self::$transactionCleanupOnly = true;
        } catch (\Throwable $recoveryFailure) {
            self::$transactionWitnessState = self::WITNESS_LOST_UNKNOWN;
            self::$transactionCleanupOnly = true;
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not recover a cleanup-only transaction witness; recovery='
                    . self::failure_fingerprint($recoveryFailure),
                $primary
            );
        }
        throw new DatabaseTransactionOutcomeException(
            $context . ' restored authority for rollback only',
            $primary
        );
    }

    /**
     * @return bool true when released, false only for the allowed missing case
     */
    private static function release_savepoint(
        string $name,
        string $context,
        bool $allowMissing
    ): bool {
        return self::classified_savepoint_control(
            "RELEASE SAVEPOINT `$name`",
            $context,
            $allowMissing
        );
    }

    /** @return bool true when the witness exists, false only for error 1305 */
    private static function rollback_to_savepoint(
        string $name,
        string $context,
        bool $allowMissing
    ): bool {
        return self::classified_savepoint_control(
            "ROLLBACK TO SAVEPOINT `$name`",
            $context,
            $allowMissing
        );
    }

    /**
     * Execute one savepoint existence control and classify absence solely by
     * the stable ER_SP_DOES_NOT_EXIST number shared by MySQL and MariaDB.
     */
    private static function classified_savepoint_control(
        string $sql,
        string $context,
        bool $allowMissing
    ): bool {
        global $wpdb;
        DatabaseQueryIsolation::assert_active($context . ' query-filter boundary');
        $previousSuppression = null;
        if (method_exists($wpdb, 'suppress_errors')) {
            $previousSuppression = $wpdb->suppress_errors(true);
        }
        try {
            if (property_exists($wpdb, 'last_error')) {
                $wpdb->last_error = '';
            }
            $result = false;
            $failure = null;
            try {
                $result = self::permitted_control_query($sql, $context);
            } catch (\Throwable $caught) {
                $failure = $caught;
            }
            $error = trim((string) ($wpdb->last_error ?? ''));
            $errno = $failure === null
                ? self::driver_errno()
                : self::strict_driver_errno($failure);
            if ($failure === null && $result !== false && $error === '') {
                return true;
            }
            if ($allowMissing && $errno === 1305) {
                $wpdb->last_error = '';
                return false;
            }
            if (!$allowMissing || $failure !== null || $error === '') {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' could not prove savepoint state',
                    $failure
                );
            }

            // Server messages are localized and can contain the generated
            // witness. SHOW WARNINGS exposes the stable numeric MySQL/MariaDB
            // classification: exactly ER_SP_DOES_NOT_EXIST (1305) proves an
            // idle connection or a transaction that lost the old witness.
            $wpdb->last_error = '';
            $warnings = self::permitted_transport(
                'SHOW WARNINGS',
                $context . ' diagnostics',
                static fn(string $statement): mixed => $wpdb->get_results($statement, ARRAY_A)
            );
            $warningError = trim((string) ($wpdb->last_error ?? ''));
            if (!is_array($warnings)
                || !array_is_list($warnings)
                || count($warnings) !== 1
                || $warningError !== '') {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' returned ambiguous savepoint diagnostics'
                );
            }
            $warning = $warnings[0];
            $level = is_array($warning) ? ($warning['Level'] ?? null) : null;
            $code = is_array($warning) ? ($warning['Code'] ?? null) : null;
            if (!is_string($level)
                || strcasecmp($level, 'Error') !== 0
                || (!is_int($code) && !is_string($code))
                || preg_match('/^[0-9]+$/D', (string) $code) !== 1
                || (int) $code !== 1305) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' returned an unexpected savepoint diagnostic'
                );
            }
            $wpdb->last_error = '';
            return false;
        } finally {
            if ($previousSuppression !== null) {
                $wpdb->suppress_errors((bool) $previousSuppression);
            }
        }
    }

    private static function require_savepoint_control(string $sql, string $context): void {
        global $wpdb;
        DatabaseQueryIsolation::assert_active($context . ' query-filter boundary');
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $result = false;
        $failure = null;
        try {
            $result = self::permitted_control_query($sql, $context);
        } catch (\Throwable $caught) {
            $failure = $caught;
        }
        if ($failure !== null
            || $result === false
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new DatabaseTransactionOutcomeException(
                $context . ' failed',
                $failure
            );
        }
    }

    private static function savepoint_name(): string {
        return 'wprism_tx_' . bin2hex(random_bytes(16));
    }

}
