<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseQueryIsolation.php';
require_once __DIR__ . '/DatabaseTransportBoundary.php';

/** Typed failure facts from the engine-owned numeric diagnostic channel. */
final class DatabaseServerDiagnosticException extends \RuntimeException {
    public const UNREADABLE = 'unreadable';
    public const MALFORMED = 'malformed';
    public const NOT_SOLE_ERROR = 'not_sole_error';

    /** @param list<int> $errorCodes */
    public function __construct(
        private readonly string $reason,
        private readonly array $errorCodes = [],
        ?\Throwable $previous = null
    ) {
        parent::__construct("wprism: database server diagnostic proof failed ($reason)", 0, $previous);
    }

    public function reason(): string {
        return $this->reason;
    }

    /** @return list<int> */
    public function error_codes(): array {
        return $this->errorCodes;
    }
}

/** Read one exact numeric server error without widening a profiled SQL gate. */
final class DatabaseServerDiagnostics {
    public static function sole_error_code(string $context): int {
        global $wpdb;
        $sql = 'SHOW WARNINGS';
        $isolated = DatabaseQueryIsolation::is_active();
        if ($isolated) {
            DatabaseQueryIsolation::permit_once($sql, $context . ' server diagnostic');
        }

        $wpdb->last_error = '';
        $rows = null;
        $failure = null;
        try {
            $rows = $wpdb->get_results($sql, ARRAY_A);
        } catch (\Throwable $caught) {
            $failure = $caught;
        }
        if ($isolated) {
            DatabaseQueryIsolation::assert_permit_consumed($context . ' server diagnostic');
        }
        if ($failure !== null) {
            $errno = DatabaseTransportBoundary::synchronous_driver_errno($failure);
            throw new DatabaseServerDiagnosticException(
                DatabaseServerDiagnosticException::UNREADABLE,
                $errno > 0 ? [$errno] : [],
                $failure
            );
        }
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new DatabaseServerDiagnosticException(
                DatabaseServerDiagnosticException::UNREADABLE
            );
        }

        $errors = [];
        $normalized = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || !is_string($row['Level'] ?? null)
                || (!is_int($row['Code'] ?? null) && !is_string($row['Code'] ?? null))
                || preg_match('/^[0-9]+$/D', (string) $row['Code']) !== 1) {
                throw new DatabaseServerDiagnosticException(
                    DatabaseServerDiagnosticException::MALFORMED
                );
            }
            $code = (int) $row['Code'];
            $normalized[] = [strtolower($row['Level']), $code];
            if (strcasecmp($row['Level'], 'Error') === 0) {
                $errors[] = $code;
            }
        }
        if ($normalized !== [['error', $errors[0] ?? -1]]) {
            throw new DatabaseServerDiagnosticException(
                DatabaseServerDiagnosticException::NOT_SOLE_ERROR,
                $errors
            );
        }
        return $errors[0];
    }
}
