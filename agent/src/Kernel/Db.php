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
    private static ?string $pendingIsolationConnectionId = null;

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
        self::before($context);
        $connectionId = self::prepare_transaction_start($context);
        self::set_next_transaction_repeatable_read($context, $connectionId);
        self::start_after_repeatable_read('START TRANSACTION', $context, $connectionId);
    }

    /** Start a repeatable-read consistent snapshot through the same outcome proof. */
    public static function start_consistent_snapshot(string $context = 'transaction start'): void {
        self::before($context . ' isolation');
        self::before($context);
        $connectionId = self::prepare_transaction_start($context);
        self::set_next_transaction_repeatable_read($context, $connectionId);
        self::start_after_repeatable_read(
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
        self::before($context);
        $connectionId = self::prepare_transaction_start($context);
        self::set_next_transaction_repeatable_read($context, $connectionId);
        self::start_after_repeatable_read(
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
        try {
            $active = self::transaction_active($context . ' state proof');
        } catch (\Throwable $stateFailure) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not prove rollback authority after '
                    . self::failure_fingerprint($primary)
                    . '; state=' . self::failure_fingerprint($stateFailure),
                $primary
            );
        }
        if (!$active) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' found the tracked transaction inactive after '
                    . self::failure_fingerprint($primary),
                $primary
            );
        }
        try {
            self::rollback($context);
        } catch (\Throwable $rollbackFailure) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not confirm rollback after '
                    . self::failure_fingerprint($primary)
                    . '; rollback=' . self::failure_fingerprint($rollbackFailure),
                $primary
            );
        }
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
        if (self::$pendingIsolationConnectionId !== null) {
            if (hash_equals(self::$pendingIsolationConnectionId, $state['connection_id'])) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' found an unresolved one-shot transaction isolation control'
                );
            }
            // SET TRANSACTION is session-local. A positive connection-id
            // change proves the old override cannot affect the replacement
            // connection, so only the old session witness can be retired.
            self::$pendingIsolationConnectionId = null;
        }
        if (self::$transactionConnectionId !== null) {
            if (!hash_equals(self::$transactionConnectionId, $state['connection_id'])) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' found unresolved transaction state on a replaced connection'
                );
            }
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
        if ($state['active']) {
            throw new DatabaseMutationException($context . ' requires an idle database connection');
        }
        return $state['connection_id'];
    }

    private static function set_next_transaction_repeatable_read(
        string $context,
        string $connectionId
    ): void {
        global $wpdb;
        $isolationContext = $context . ' isolation';
        self::$pendingIsolationConnectionId = $connectionId;
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $result = false;
        $queryFailure = null;
        try {
            $result = $wpdb->query('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        } catch (\Throwable $failure) {
            $queryFailure = $failure;
        }
        $queryError = trim((string) ($wpdb->last_error ?? ''));
        try {
            $state = self::transaction_state($context . ' isolation connection proof');
        } catch (\Throwable $proofFailure) {
            self::settle_pending_isolation_or_refuse(
                $connectionId,
                $isolationContext . ' state-proof cleanup',
                $proofFailure
            );
            throw new DatabaseTransactionOutcomeException(
                $context . ' isolation control outcome could not be proven',
                $proofFailure
            );
        }
        if (!hash_equals($connectionId, $state['connection_id'])) {
            self::$pendingIsolationConnectionId = null;
            throw new DatabaseTransactionOutcomeException(
                $context . ' isolation control changed database connection'
            );
        }
        if ($state['active']) {
            self::settle_pending_isolation_or_refuse(
                $connectionId,
                $isolationContext . ' unexpected-active cleanup',
                new DatabaseTransactionOutcomeException(
                    $context . ' isolation control unexpectedly entered a transaction'
                )
            );
            throw new DatabaseTransactionOutcomeException(
                $context . ' isolation control unexpectedly entered a transaction'
            );
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
            $connectionId,
            $isolationContext . ' failed-control cleanup',
            $queryFailure ?? new DatabaseMutationException($isolationContext)
        );
        if ($queryFailure !== null) {
            throw new DatabaseMutationException($isolationContext, $queryFailure);
        }
        self::throw_control_failure($isolationContext, $queryError);
    }

    private static function start_after_repeatable_read(
        string $sql,
        string $context,
        string $connectionId
    ): void {
        try {
            self::start_control($sql, $context, $connectionId);
            // An active transaction positively consumes the one-shot setting.
            self::$pendingIsolationConnectionId = null;
        } catch (\Throwable $startFailure) {
            self::settle_pending_isolation_or_refuse(
                $connectionId,
                $context . ' refused-start isolation cleanup',
                $startFailure
            );
            throw $startFailure;
        }
    }

    private static function settle_pending_isolation_or_refuse(
        string $connectionId,
        string $context,
        \Throwable $primary
    ): void {
        try {
            $state = self::transaction_state($context . ' preflight');
            if (!hash_equals($connectionId, $state['connection_id'])) {
                // The one-shot instruction belongs to the replaced session
                // and cannot contaminate the current WordPress connection.
                self::$pendingIsolationConnectionId = null;
                throw new DatabaseTransactionOutcomeException(
                    $context . ' changed connection while settling isolation',
                    $primary
                );
            }
            if ($state['active']) {
                if (self::$transactionConnectionId !== null
                    && !hash_equals(self::$transactionConnectionId, $connectionId)) {
                    throw new DatabaseTransactionOutcomeException(
                        $context . ' found a different tracked transaction',
                        $primary
                    );
                }
                self::$transactionConnectionId = $connectionId;
            } else {
                self::start_control('START TRANSACTION', $context . ' start', $connectionId);
            }
            // START has now consumed the setting even if its client result was
            // ambiguous. Retain only transaction tracking until rollback is
            // positively classified.
            self::$pendingIsolationConnectionId = null;
            self::finish_transaction('ROLLBACK', $context . ' rollback');
        } catch (\Throwable $cleanupFailure) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not consume an uncertain one-shot isolation control; cleanup='
                    . self::failure_fingerprint($cleanupFailure),
                $primary
            );
        }
    }

    private static function start_control(string $sql, string $context, string $connectionId): void {
        global $wpdb;
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $result = false;
        $queryFailure = null;
        try {
            $result = $wpdb->query($sql);
        } catch (\Throwable $failure) {
            $queryFailure = $failure;
        }
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
        if ($queryFailure !== null) {
            throw new DatabaseMutationException($context, $queryFailure);
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
        $result = false;
        $queryFailure = null;
        try {
            $result = $wpdb->query($sql);
        } catch (\Throwable $failure) {
            $queryFailure = $failure;
        }
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
            if ($sql === 'ROLLBACK') {
                // Every same-connection inactive outcome of an attempted
                // ROLLBACK is safe for the caller's compensation boundary:
                // authored bytes cannot have committed through this control.
                self::$transactionConnectionId = null;
                return;
            }
            if ($queryFailure === null && $result !== false && $queryError === '') {
                self::$transactionConnectionId = null;
                return;
            }
            // Active->inactive alone cannot tell an accepted COMMIT from a
            // server-side rollback/rejection. Retain tracking so no later
            // transaction can erase the ambiguity before the caller checks a
            // physical product postimage and explicitly forgets it.
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
        throw new DatabaseTransactionOutcomeException($context . ' reported success without ending');
    }

    private static function throw_control_failure(string $context, string $driverError): never {
        if (stripos($driverError, 'Deadlock found') !== false
            || stripos($driverError, 'Lock wait timeout') !== false) {
            throw new TransientDbException("duo: transient DB contention at $context");
        }
        throw new DatabaseMutationException($context);
    }

    private static function failure_fingerprint(\Throwable $failure): string {
        $message = $failure->getMessage();
        return get_class($failure) . ':' . strlen($message) . ':'
            . substr(hash('sha256', $message), 0, 16);
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
