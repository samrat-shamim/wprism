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
 * The only sanctioned path for product database mutations. WordPress's wpdb
 * methods return false rather than throwing, while 0 is a valid successful
 * result for UPDATE/DELETE, so every method below uses the strict false
 * check and retains an operation-level (never value-level) context.
 */
final class Db {
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
        self::query('START TRANSACTION', $context);
    }

    /** Start a write transaction whose exact next-transaction isolation is controlled. */
    public static function start_repeatable_read(string $context = 'transaction start'): void {
        // `@@transaction_isolation`/`@@tx_isolation` report the session
        // default, not a one-shot active-transaction override, while
        // information_schema.innodb_trx requires PROCESS on ordinary WP DB
        // accounts. SET TRANSACTION is accepted by those accounts and applies
        // only to the immediately following START TRANSACTION.
        self::query(
            'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
            $context . ' isolation'
        );
        self::query('START TRANSACTION', $context);
    }

    public static function commit(string $context = 'transaction commit'): void {
        self::query('COMMIT', $context);
    }

    public static function rollback(string $context = 'transaction rollback'): void {
        self::query('ROLLBACK', $context);
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
}
