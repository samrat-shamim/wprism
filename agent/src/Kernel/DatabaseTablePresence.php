<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseServerDiagnostics.php';
require_once __DIR__ . '/DatabaseTableIdentifier.php';
require_once __DIR__ . '/DatabaseTransportBoundary.php';

/** Typed failure facts from an exact physical-table presence proof. */
final class DatabaseTablePresenceException extends \RuntimeException {
    public const RESOLUTION_UNREADABLE = 'resolution_unreadable';
    public const NOT_PLAIN_BASE_TABLE = 'not_plain_base_table';
    public const ABSENCE_AMBIGUOUS = 'absence_ambiguous';
    public const DIAGNOSTIC_UNREADABLE = 'diagnostic_unreadable';
    public const DIAGNOSTIC_MALFORMED = 'diagnostic_malformed';
    public const DIAGNOSTIC_UNEXPECTED = 'diagnostic_unexpected';

    /** @param list<int> $errorCodes */
    public function __construct(
        private readonly string $reason,
        private readonly array $errorCodes = [],
        ?\Throwable $previous = null,
        private readonly ?string $detail = null
    ) {
        parent::__construct("wprism: database table presence proof failed ($reason)", 0, $previous);
    }

    public function reason(): string {
        return $this->reason;
    }

    /** @return list<int> */
    public function error_codes(): array {
        return $this->errorCodes;
    }

    public function detail(): ?string {
        return $this->detail;
    }
}

/** Exact, read-only proof that one session resolves a plain physical table. */
final class DatabaseTablePresence {
    /**
     * Return false only for the server's exact ER_NO_SUCH_TABLE fact.
     *
     * Metadata inventories can silently omit privilege-hidden tables. An
     * exact zero-row read therefore selects the branch: success proves this
     * session can resolve the name, while only the server's numeric 1146
     * diagnostic proves absence. SHOW CREATE then rejects views and temporary
     * shadows before a present name becomes physical-table evidence.
    */
    public static function base_table_exists(string $table): bool {
        DatabaseTableIdentifier::assert_many([$table], 'database table presence');

        if (!self::exact_session_presence($table)) {
            return false;
        }
        self::assert_plain_base_table($table);
        return true;
    }

    /** Return false only from one exact ER_NO_SUCH_TABLE diagnostic. */
    private static function exact_session_presence(string $table): bool {
        global $wpdb;
        $previousSuppression = null;
        if (method_exists($wpdb, 'suppress_errors')) {
            $previousSuppression = $wpdb->suppress_errors(true);
        }
        try {
            $wpdb->last_error = '';
            $result = false;
            $failure = null;
            try {
                $result = $wpdb->get_var("SELECT 1 FROM `$table` LIMIT 0");
            } catch (\Throwable $caught) {
                if (DatabaseTransportBoundary::synchronous_driver_errno($caught) === 0) {
                    throw new DatabaseTablePresenceException(
                        DatabaseTablePresenceException::RESOLUTION_UNREADABLE,
                        [],
                        $caught
                    );
                }
                $failure = $caught;
            }
            $probeError = trim((string) ($wpdb->last_error ?? ''));
            if ($failure === null && $probeError === '' && $result !== false) {
                return true;
            }
            if ($failure === null && $probeError === '') {
                throw new DatabaseTablePresenceException(
                    DatabaseTablePresenceException::ABSENCE_AMBIGUOUS
                );
            }

            $strictCode = $failure === null
                ? 0
                : DatabaseTransportBoundary::synchronous_driver_errno($failure);
            try {
                $diagnosticCode = DatabaseServerDiagnostics::sole_error_code(
                    'database table presence'
                );
            } catch (DatabaseServerDiagnosticException $diagnosticFailure) {
                $reason = match ($diagnosticFailure->reason()) {
                    DatabaseServerDiagnosticException::UNREADABLE =>
                        DatabaseTablePresenceException::DIAGNOSTIC_UNREADABLE,
                    DatabaseServerDiagnosticException::MALFORMED =>
                        DatabaseTablePresenceException::DIAGNOSTIC_MALFORMED,
                    default => DatabaseTablePresenceException::DIAGNOSTIC_UNEXPECTED,
                };
                $errorCodes = $diagnosticFailure->error_codes();
                if ($strictCode > 0 && !in_array($strictCode, $errorCodes, true)) {
                    $errorCodes[] = $strictCode;
                }
                sort($errorCodes, SORT_NUMERIC);
                throw new DatabaseTablePresenceException(
                    $reason,
                    $errorCodes,
                    $diagnosticFailure
                );
            }
            if ($diagnosticCode !== 1146 || ($strictCode !== 0 && $strictCode !== $diagnosticCode)) {
                $errorCodes = array_values(array_unique(array_filter(
                    [$strictCode, $diagnosticCode],
                    static fn(int $code): bool => $code > 0
                )));
                sort($errorCodes, SORT_NUMERIC);
                throw new DatabaseTablePresenceException(
                    DatabaseTablePresenceException::DIAGNOSTIC_UNEXPECTED,
                    $errorCodes,
                    $failure
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

    /** Refuse temporary-table shadows, views, and malformed driver evidence. */
    public static function assert_plain_base_table(string $table): void {
        global $wpdb;
        DatabaseTableIdentifier::assert_many([$table], 'database table presence');
        $wpdb->last_error = '';
        $row = null;
        $failure = null;
        try {
            $row = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
        } catch (\Throwable $caught) {
            $failure = $caught;
        }
        $error = trim((string) ($wpdb->last_error ?? ''));
        if (!is_array($row)
            || !array_is_list($row)
            || count($row) < 2
            || !is_string($row[0])
            || !hash_equals($table, $row[0])
            || !is_string($row[1])
            || $error !== ''
            || $failure !== null) {
            throw new DatabaseTablePresenceException(
                DatabaseTablePresenceException::RESOLUTION_UNREADABLE,
                [],
                $failure,
                $error !== '' ? $error : 'malformed SHOW CREATE TABLE result'
            );
        }
        $quoted = preg_quote($table, '/');
        if (preg_match("/^CREATE TABLE `$quoted`\\s*\\(/D", $row[1]) !== 1) {
            throw new DatabaseTablePresenceException(
                DatabaseTablePresenceException::NOT_PLAIN_BASE_TABLE
            );
        }
        // MariaDB and MySQL append view-only charset/collation columns to
        // SHOW CREATE TABLE on a view. Inspect the definition before enforcing
        // the plain-table two-column shape so a real view is typed accurately;
        // extra columns can never turn a CREATE TABLE response into authority.
        if (count($row) !== 2) {
            throw new DatabaseTablePresenceException(
                DatabaseTablePresenceException::RESOLUTION_UNREADABLE,
                [],
                null,
                'malformed SHOW CREATE TABLE result'
            );
        }
    }
}
