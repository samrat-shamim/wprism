<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseExceptions.php';

/**
 * Keep an authored transaction on WordPress's one reviewed mysqli transport.
 *
 * Stock wpdb retries errno 2006 after `_do_query()` and replays the same SQL on
 * a replacement autocommit connection. MYSQLI_REPORT_STRICT makes the original
 * mysqli call throw before wpdb reaches that replay branch. The exact wpdb,
 * query implementation, mysqli handle and report mode are retained until a
 * positive terminal proof; a custom database drop-in or replacement handle is
 * outside this in-process atomicity model.
 */
final class DatabaseTransportBoundary {
    private const REQUIRED_REPORT_MODE = 3; // MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT.

    private static bool $active = false;
    private static ?object $database = null;
    private static ?object $handle = null;
    private static ?int $previousReportMode = null;
    private static bool $offline = false;
    private static ?bool $offlinePreviousStrict = null;

    public static function begin(string $context): void {
        if (self::$active) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context found an already-bound database transport"
            );
        }
        global $wpdb;
        if (!is_object($wpdb)) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context requires one WordPress database object"
            );
        }

        // Offline suites intentionally run without WordPress's wpdb class.
        // That absence cannot occur after a real WordPress bootstrap, so it is
        // a structural test seam rather than a runtime compatibility bypass.
        if (!class_exists('wpdb', false)) {
            self::$database = $wpdb;
            self::$offline = true;
            self::$offlinePreviousStrict = method_exists($wpdb, 'wprism_test_set_strict_transport')
                ? $wpdb->wprism_test_set_strict_transport(true)
                : null;
            self::$active = true;
            return;
        }

        if (get_class($wpdb) !== 'wpdb') {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context refuses a custom wpdb implementation inside an authored transaction"
            );
        }
        if (defined('WP_CONTENT_DIR') && is_file((string) WP_CONTENT_DIR . '/db.php')) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context refuses a custom database drop-in inside an authored transaction"
            );
        }
        try {
            $query = new \ReflectionMethod($wpdb, 'query');
            $dbh = new \ReflectionProperty($wpdb, 'dbh');
            $handle = $dbh->getValue($wpdb);
        } catch (\Throwable $failure) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context could not inspect the WordPress database transport",
                0,
                $failure
            );
        }
        if ($query->getDeclaringClass()->getName() !== 'wpdb'
            || !class_exists('mysqli', false)
            || !$handle instanceof \mysqli
            || !class_exists('mysqli_driver', false)) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context requires stock wpdb over mysqli"
            );
        }

        $driver = new \mysqli_driver();
        $previous = $driver->report_mode;
        $driver->report_mode = self::REQUIRED_REPORT_MODE;
        if ($driver->report_mode !== self::REQUIRED_REPORT_MODE) {
            $driver->report_mode = $previous;
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context could not enable strict mysqli failure reporting"
            );
        }
        self::$database = $wpdb;
        self::$handle = $handle;
        self::$previousReportMode = $previous;
        self::$offline = false;
        self::$offlinePreviousStrict = null;
        self::$active = true;
    }

    public static function assert_intact(string $context): void {
        if (!self::$active || self::$database === null || ($GLOBALS['wpdb'] ?? null) !== self::$database) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context changed the database transport identity"
            );
        }
        if (self::$offline) {
            if (class_exists('wpdb', false)
                || (method_exists(self::$database, 'wprism_test_strict_transport')
                    && self::$database->wprism_test_strict_transport() !== true)) {
                throw new DatabaseQueryIsolationViolationException(
                    "wprism: $context changed the offline database transport boundary"
                );
            }
            return;
        }
        if (get_class(self::$database) !== 'wpdb'
            || self::database_handle(self::$database, $context) !== self::$handle
            || (new \mysqli_driver())->report_mode !== self::REQUIRED_REPORT_MODE) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context changed the strict mysqli transport boundary"
            );
        }
    }

    /** Reassert only mutable driver mode; object/handle replacement is terminal. */
    public static function prepare_cleanup(string $context): void {
        if (!self::$active || self::$database === null || ($GLOBALS['wpdb'] ?? null) !== self::$database) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' lost the database transport required for cleanup'
            );
        }
        if (self::$offline) {
            if (method_exists(self::$database, 'wprism_test_set_strict_transport')) {
                self::$database->wprism_test_set_strict_transport(true);
            }
            self::assert_intact($context);
            return;
        }
        if (self::database_handle(self::$database, $context) !== self::$handle) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' lost the mysqli handle required for cleanup'
            );
        }
        $driver = new \mysqli_driver();
        $driver->report_mode = self::REQUIRED_REPORT_MODE;
        self::assert_intact($context);
    }

    /** Restore process-global driver state only after positive SQL settlement. */
    public static function finish(string $context): void {
        if (!self::$active) {
            return;
        }
        self::assert_intact($context);
        if (self::$offline) {
            if (self::$offlinePreviousStrict !== null
                && self::$database !== null
                && method_exists(self::$database, 'wprism_test_set_strict_transport')) {
                self::$database->wprism_test_set_strict_transport(self::$offlinePreviousStrict);
            }
        } else {
            $driver = new \mysqli_driver();
            $driver->report_mode = self::$previousReportMode ?? self::REQUIRED_REPORT_MODE;
        }
        self::$active = false;
        self::$database = null;
        self::$handle = null;
        self::$previousReportMode = null;
        self::$offline = false;
        self::$offlinePreviousStrict = null;
    }

    private static function database_handle(object $database, string $context): mixed {
        try {
            return (new \ReflectionProperty($database, 'dbh'))->getValue($database);
        } catch (\Throwable $failure) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context could not re-read the WordPress database handle",
                0,
                $failure
            );
        }
    }
}
