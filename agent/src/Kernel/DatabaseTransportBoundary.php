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
    /**
     * Parser and schema state bound immediately before a provider profile.
     *
     * @var ?array{
     *   database:string,
     *   sql_mode:string,
     *   character_set_client:string,
     *   character_set_connection:string,
     *   character_set_results:string,
     *   collation_connection:string,
     *   character_set_client_max_bytes:int
     * }
     */
    private static ?array $sessionState = null;

    /**
     * Read errno only from the exception type emitted synchronously by mysqli.
     * A provider-created Throwable with a convenient numeric code is never
     * database evidence, whether or not a transport boundary is active.
     */
    public static function synchronous_driver_errno(\Throwable $failure): int {
        if (!class_exists('mysqli_sql_exception', false)
            || !$failure instanceof \mysqli_sql_exception) {
            return 0;
        }
        $errno = $failure->getCode();
        return is_int($errno) && $errno >= 0 ? $errno : 0;
    }

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
            self::$sessionState = null;
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
        self::$sessionState = null;
        self::$active = true;
    }

    public static function assert_intact(string $context): void {
        self::assert_transport_intact($context);
    }

    /** Recheck parser/schema state immediately before one SQL transport. */
    public static function assert_session_intact(string $context): void {
        self::assert_transport_intact($context);
        if (self::$sessionState !== null
            && self::observe_session_state($context) !== self::$sessionState) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context changed the bound database session state"
            );
        }
    }

    /**
     * Bind the SQL parser and selected-schema premises to this exact handle.
     *
     * @return array{database:string,sql_mode:string,character_set_client:string,character_set_connection:string,character_set_results:string,collation_connection:string,character_set_client_max_bytes:int}
     */
    public static function bind_session_state(string $context): array {
        self::assert_transport_intact($context);
        if (self::$sessionState !== null) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context found an already-bound database session state"
            );
        }
        self::$sessionState = self::observe_session_state($context);
        return self::$sessionState;
    }

    /** Identity-only proof used for the exact SHOW WARNINGS diagnostic read. */
    public static function assert_transport_intact(string $context): void {
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

    /** Reassert driver mode and restore bound session state for one cleanup. */
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
            self::restore_session_state($context);
            self::assert_session_intact($context);
            return;
        }
        if (self::database_handle(self::$database, $context) !== self::$handle) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' lost the mysqli handle required for cleanup'
            );
        }
        $driver = new \mysqli_driver();
        $driver->report_mode = self::REQUIRED_REPORT_MODE;
        self::restore_session_state($context);
        self::assert_session_intact($context);
    }

    /** Restore process-global driver state only after positive SQL settlement. */
    public static function finish(string $context): void {
        if (!self::$active) {
            return;
        }
        self::assert_session_intact($context);
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
        self::$sessionState = null;
    }

    /**
     * Observe session state without crossing WordPress's mutable query hooks.
     *
     * @return array{
     *   database:string,
     *   sql_mode:string,
     *   character_set_client:string,
     *   character_set_connection:string,
     *   character_set_results:string,
     *   collation_connection:string,
     *   character_set_client_max_bytes:int
     * }
     */
    private static function observe_session_state(string $context): array {
        if (self::$database === null) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context lost the database object required for session observation"
            );
        }
        if (self::$offline) {
            if (!method_exists(self::$database, 'wprism_test_database_session_state')) {
                throw new DatabaseQueryIsolationViolationException(
                    "wprism: $context requires an exact offline database session-state seam"
                );
            }
            $state = self::$database->wprism_test_database_session_state();
            return self::validated_session_state($state, $context);
        }
        if (!self::$handle instanceof \mysqli) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context lost the mysqli handle required for session observation"
            );
        }
        $sql = 'SELECT DATABASE() AS database_name, @@SESSION.sql_mode AS sql_mode, '
            . '@@SESSION.character_set_client AS character_set_client, '
            . '@@SESSION.character_set_connection AS character_set_connection, '
            . '@@SESSION.character_set_results AS character_set_results, '
            . '@@SESSION.collation_connection AS collation_connection, '
            . 'cs.MAXLEN AS character_set_client_max_bytes '
            . 'FROM information_schema.CHARACTER_SETS AS cs '
            . 'WHERE BINARY cs.CHARACTER_SET_NAME = BINARY @@SESSION.character_set_client';
        try {
            $result = self::$handle->query($sql);
            if (!$result instanceof \mysqli_result) {
                throw new \RuntimeException('session-state query returned no result set');
            }
            try {
                $row = $result->fetch_assoc();
                $extra = $result->fetch_assoc();
            } finally {
                $result->free();
            }
        } catch (\Throwable $failure) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context could not observe the bound database session state",
                0,
                $failure
            );
        }
        if (!is_array($row) || $extra !== null) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context received malformed database session-state evidence"
            );
        }
        return self::validated_session_state([
            'database' => $row['database_name'] ?? null,
            'sql_mode' => $row['sql_mode'] ?? null,
            'character_set_client' => $row['character_set_client'] ?? null,
            'character_set_connection' => $row['character_set_connection'] ?? null,
            'character_set_results' => $row['character_set_results'] ?? null,
            'collation_connection' => $row['collation_connection'] ?? null,
            'character_set_client_max_bytes' => $row['character_set_client_max_bytes'] ?? null,
        ], $context);
    }

    /** @param mixed $state */
    private static function validated_session_state(mixed $state, string $context): array {
        if (!is_array($state) || array_keys($state) !== [
            'database',
            'sql_mode',
            'character_set_client',
            'character_set_connection',
            'character_set_results',
            'collation_connection',
            'character_set_client_max_bytes',
        ]) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context received malformed database session-state evidence"
            );
        }
        $database = $state['database'];
        $mode = $state['sql_mode'];
        $client = $state['character_set_client'];
        $connection = $state['character_set_connection'];
        $results = $state['character_set_results'];
        $collation = $state['collation_connection'];
        $maxBytesText = is_int($state['character_set_client_max_bytes'])
            || is_string($state['character_set_client_max_bytes'])
            ? (string) $state['character_set_client_max_bytes']
            : '';
        if (!is_string($database) || $database === '' || strlen($database) > 256
            || preg_match('/[\x00-\x1f\x7f]/', $database) === 1
            || !is_string($mode) || strlen($mode) > 4096
            || preg_match('/^[A-Za-z0-9_, ]*$/D', $mode) !== 1
            || !is_string($client) || preg_match('/^[a-z0-9_]{1,32}$/D', $client) !== 1
            || !is_string($connection) || preg_match('/^[a-z0-9_]{1,32}$/D', $connection) !== 1
            || !is_string($results) || preg_match('/^[a-z0-9_]{1,32}$/D', $results) !== 1
            || !is_string($collation) || preg_match('/^[a-z0-9_]{1,64}$/D', $collation) !== 1
            || preg_match('/^[1-9][0-9]?$/D', $maxBytesText) !== 1) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context received malformed database session-state evidence"
            );
        }
        return [
            'database' => $database,
            'sql_mode' => $mode,
            'character_set_client' => $client,
            'character_set_connection' => $connection,
            'character_set_results' => $results,
            'collation_connection' => $collation,
            'character_set_client_max_bytes' => (int) $maxBytesText,
        ];
    }

    /** Restore only after object and handle identity remain exact. */
    private static function restore_session_state(string $context): void {
        if (self::$sessionState === null || self::$database === null) {
            return;
        }
        if (self::$offline) {
            if (!method_exists(self::$database, 'wprism_test_restore_database_session_state')) {
                throw new DatabaseTransactionOutcomeException(
                    $context . ' cannot restore the offline database session state'
                );
            }
            self::$database->wprism_test_restore_database_session_state(self::$sessionState);
            return;
        }
        if (!self::$handle instanceof \mysqli) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' lost the mysqli handle required for session restoration'
            );
        }
        $state = self::$sessionState;
        try {
            if (!self::$handle->select_db($state['database'])) {
                throw new \RuntimeException('database selection failed');
            }
            foreach ([
                'sql_mode' => $state['sql_mode'],
                'character_set_client' => $state['character_set_client'],
                'character_set_connection' => $state['character_set_connection'],
                'character_set_results' => $state['character_set_results'],
                'collation_connection' => $state['collation_connection'],
            ] as $variable => $value) {
                if (self::$handle->query("SET SESSION $variable = '$value'") !== true) {
                    throw new \RuntimeException('session variable restoration failed');
                }
            }
        } catch (\Throwable $failure) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' could not restore the bound database session state',
                $failure
            );
        }
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
