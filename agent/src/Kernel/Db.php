<?php
namespace Duo;

/**
 * Typed failure for a database mutation whose result WordPress reported as
 * false (or whose required auto-increment id was not produced). The context
 * is supplied by the caller and deliberately contains no SQL values: wpdb's
 * last_error and rendered SQL can echo option/meta payloads, including the
 * secrets Duo is specifically responsible for keeping out of diagnostics.
 */
final class DatabaseMutationException extends \RuntimeException {
    public string $mutationContext;

    public function __construct(string $context, ?\Throwable $previous = null) {
        $this->mutationContext = $context;
        parent::__construct("duo: database mutation failed: $context", 0, $previous);
    }
}

/**
 * A transaction control statement crossed a connection/outcome boundary
 * that cannot be repaired by issuing another SQL statement. Retrying a
 * COMMIT or running rollback callbacks can corrupt either the committed or
 * rolled-back world, so callers must stop with recovery_required.
 */
final class DatabaseTransactionOutcomeException extends \RuntimeException {
    public string $transactionContext;

    public function __construct(string $context, ?\Throwable $previous = null) {
        $this->transactionContext = $context;
        parent::__construct(
            "duo: database transaction outcome is uncertain: $context; recovery_required",
            0,
            $previous
        );
    }
}

/**
 * The only sanctioned path for product database mutations. WordPress's wpdb
 * methods return false rather than throwing, while 0 is a valid successful
 * result for UPDATE/DELETE, so every method below uses the strict false
 * check and retains an operation-level (never value-level) context.
 */
final class Db {
    private static ?string $transactionConnectionId = null;

    private static function before(string $context): void {
        // Deterministic integration-test seam. Both switches are required so
        // a stray context variable can never affect a normal installation.
        $targets = array_map('trim', explode(',', (string) getenv('DUO_TEST_FAIL_DB_CONTEXT')));
        if (getenv('DUO_TEST_MODE') === '1'
            && in_array($context, $targets, true)) {
            throw new DatabaseMutationException($context . ' (injected)');
        }
    }

    private static function checked($result, string $context) {
        if ($result === false) {
            global $wpdb;
            $error = isset($wpdb->last_error) ? (string) $wpdb->last_error : '';
            if (stripos($error, 'Deadlock found') !== false
                || stripos($error, 'Lock wait timeout') !== false) {
                // Capture retries this typed class from a fresh consistent
                // snapshot. Keep the driver text out of the exception: it
                // can contain rendered SQL values, while the caller-supplied
                // operation context is enough to locate the failure.
                throw new TransientDbException("duo: transient DB contention at $context");
            }
            throw new DatabaseMutationException($context);
        }
        return $result;
    }

    public static function query($sql, string $context) {
        global $wpdb;
        self::before($context);
        return self::checked($wpdb->query($sql), $context);
    }

    public static function insert(string $table, array $data, $format = null, ?string $context = null): int {
        global $wpdb;
        $context ??= 'insert into ' . self::table_label($table);
        self::before($context);
        return (int) self::checked($wpdb->insert($table, $data, $format), $context);
    }

    public static function update(string $table, array $data, array $where, $format = null, $whereFormat = null, ?string $context = null): int {
        global $wpdb;
        $context ??= 'update ' . self::table_label($table);
        self::before($context);
        return (int) self::checked($wpdb->update($table, $data, $where, $format, $whereFormat), $context);
    }

    public static function delete(string $table, array $where, $whereFormat = null, ?string $context = null): int {
        global $wpdb;
        $context ??= 'delete from ' . self::table_label($table);
        self::before($context);
        return (int) self::checked($wpdb->delete($table, $where, $whereFormat), $context);
    }

    public static function insert_id(string $context): int {
        global $wpdb;
        $id = (int) $wpdb->insert_id;
        if ($id <= 0) {
            throw new DatabaseMutationException($context . ' did not produce an id');
        }
        return $id;
    }

    public static function start(string $context = 'transaction start'): void {
        self::before($context);
        $connectionId = self::prepare_transaction_start($context);
        self::start_control('START TRANSACTION', $context, $connectionId);
    }

    /** Start a write transaction whose exact next-transaction isolation is controlled. */
    public static function start_repeatable_read(string $context = 'transaction start'): void {
        // `@@transaction_isolation`/`@@tx_isolation` report the session
        // default, not a one-shot active-transaction override, while
        // information_schema.innodb_trx requires PROCESS on ordinary WP DB
        // accounts. SET TRANSACTION is accepted by those accounts and applies
        // only to the immediately following START TRANSACTION.
        self::before($context . ' isolation');
        $connectionId = self::prepare_transaction_start($context);
        self::set_next_transaction_repeatable_read($context, $connectionId);
        self::before($context);
        self::start_control('START TRANSACTION', $context, $connectionId);
    }

    /** Start a repeatable-read consistent snapshot through the same outcome proof. */
    public static function start_consistent_snapshot(string $context = 'transaction start'): void {
        self::before($context . ' isolation');
        $connectionId = self::prepare_transaction_start($context);
        self::set_next_transaction_repeatable_read($context, $connectionId);
        self::before($context);
        self::start_control(
            'START TRANSACTION WITH CONSISTENT SNAPSHOT',
            $context,
            $connectionId
        );
    }

    /** Start a server-enforced read-only snapshot with exact outcome proof. */
    public static function start_read_only_consistent_snapshot(
        string $context = 'read-only transaction start'
    ): void {
        self::before($context . ' isolation');
        $connectionId = self::prepare_transaction_start($context);
        self::set_next_transaction_repeatable_read($context, $connectionId);
        self::before($context);
        self::start_control(
            'START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT',
            $context,
            $connectionId
        );
    }

    public static function commit(string $context = 'transaction commit'): void {
        self::finish_transaction('COMMIT', $context);
    }

    public static function rollback(string $context = 'transaction rollback'): void {
        self::finish_transaction('ROLLBACK', $context);
    }

    /**
     * Recheck the exact connection and active state tracked by start*().
     * Callers use this before invoking rollback participants: an already
     * ended transaction must never be "restored" through autocommit writes.
     */
    public static function transaction_active(string $context): bool {
        $expected = self::$transactionConnectionId;
        if ($expected === null) {
            throw new DatabaseTransactionOutcomeException($context . ' has no tracked transaction identity');
        }
        $state = self::transaction_state($context . ' state proof');
        if (!hash_equals($expected, $state['connection_id'])) {
            throw new DatabaseTransactionOutcomeException($context . ' changed database connection');
        }
        return $state['active'];
    }

    /** Forget only process-local tracking after a terminal recovery refusal. */
    public static function forget_transaction_tracking(): void {
        self::$transactionConnectionId = null;
    }

    /** Deterministic failure seam for required non-SQL phases in live tests. */
    public static function checkpoint(string $context): void {
        self::before($context);
    }

    private static function table_label(string $table): string {
        global $wpdb;
        $prefix = isset($wpdb->prefix) ? (string) $wpdb->prefix : '';
        return $prefix !== '' && str_starts_with($table, $prefix)
            ? substr($table, strlen($prefix))
            : $table;
    }

    private static function prepare_transaction_start(string $context): string {
        $state = self::transaction_state($context . ' preflight');
        if (self::$transactionConnectionId !== null) {
            if (!hash_equals(self::$transactionConnectionId, $state['connection_id'])) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' found unresolved transaction state on a replaced connection'
                );
            }
            if ($state['active']) {
                throw new DatabaseMutationException($context . ' found an already-active transaction');
            }
            self::$transactionConnectionId = null;
        }
        if ($state['active']) {
            throw new DatabaseMutationException($context . ' requires an idle database connection');
        }
        return $state['connection_id'];
    }

    private static function set_next_transaction_repeatable_read(
        string $context,
        string $connectionId
    ): void {
        self::query(
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            $context . ' isolation'
        );
        $state = self::transaction_state($context . ' isolation connection proof');
        if (!hash_equals($connectionId, $state['connection_id']) || $state['active']) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' isolation control did not remain on the idle connection'
            );
        }
    }

    private static function start_control(string $sql, string $context, string $connectionId): void {
        global $wpdb;
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $result = $wpdb->query($sql);
        $queryError = trim((string) ($wpdb->last_error ?? ''));
        try {
            $state = self::transaction_state($context . ' outcome proof');
        } catch (\Throwable $proofFailure) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not prove whether START was applied',
                $proofFailure
            );
        }
        if (!hash_equals($connectionId, $state['connection_id'])) {
            throw new DatabaseTransactionOutcomeException($context . ' changed connection while starting');
        }
        if ($state['active']) {
            // The server state is authoritative even if the client returned
            // false after applying START. The tracked identity lets later
            // COMMIT/ROLLBACK distinguish connection replacement.
            self::$transactionConnectionId = $connectionId;
            return;
        }
        if ($result === false || $queryError !== '') {
            self::throw_control_failure($context, $queryError);
        }
        throw new DatabaseTransactionOutcomeException($context . ' reported success without starting');
    }

    private static function finish_transaction(string $sql, string $context): void {
        global $wpdb;
        self::before($context);
        $expected = self::$transactionConnectionId;
        if ($expected === null) {
            throw new DatabaseTransactionOutcomeException($context . ' has no tracked transaction identity');
        }
        $before = self::transaction_state($context . ' preflight');
        if (!hash_equals($expected, $before['connection_id'])) {
            throw new DatabaseTransactionOutcomeException($context . ' database connection changed before control');
        }
        if (!$before['active']) {
            throw new DatabaseTransactionOutcomeException($context . ' transaction ended before control');
        }

        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $result = $wpdb->query($sql);
        $queryError = trim((string) ($wpdb->last_error ?? ''));
        try {
            $after = self::transaction_state($context . ' outcome proof');
        } catch (\Throwable $proofFailure) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not prove the terminal transaction outcome',
                $proofFailure
            );
        }
        if (!hash_equals($expected, $after['connection_id'])) {
            throw new DatabaseTransactionOutcomeException($context . ' database connection changed during control');
        }
        if (!$after['active']) {
            // Same-connection active->inactive is the exact server outcome.
            // A false client result after application must not cause a
            // second COMMIT or an autocommit rollback participant.
            self::$transactionConnectionId = null;
            return;
        }
        if ($result === false || $queryError !== '') {
            self::throw_control_failure($context, $queryError);
        }
        throw new DatabaseTransactionOutcomeException($context . ' reported success without ending');
    }

    private static function throw_control_failure(string $context, string $driverError): never {
        if (stripos($driverError, 'Deadlock found') !== false
            || stripos($driverError, 'Lock wait timeout') !== false) {
            throw new TransientDbException("duo: transient DB contention at $context");
        }
        throw new DatabaseMutationException($context);
    }

    /** @return array{connection_id:string,active:bool} */
    private static function transaction_state(string $context): array {
        global $wpdb;
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $connectionId = $wpdb->get_var('SELECT CONNECTION_ID()');
        $connectionError = trim((string) ($wpdb->last_error ?? ''));
        if (!is_string($connectionId)
            || preg_match('/^[1-9][0-9]*$/D', $connectionId) !== 1
            || $connectionError !== '') {
            throw new DatabaseTransactionOutcomeException($context . ' returned a malformed connection identity');
        }
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $active = $wpdb->get_var('SELECT @@in_transaction');
        $activeError = trim((string) ($wpdb->last_error ?? ''));
        if (!is_string($active)
            || !in_array($active, ['0', '1'], true)
            || $activeError !== '') {
            throw new DatabaseTransactionOutcomeException($context . ' returned malformed transaction state');
        }
        return ['connection_id' => $connectionId, 'active' => $active === '1'];
    }
}
