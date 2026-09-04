<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseQueryIsolation.php';

/**
 * Storage and index proof shared by every authoritative locking read.
 *
 * A SELECT ... FOR UPDATE is only an authority boundary when the table is
 * transactional and the requested equality/range is backed by a visible
 * BTREE that covers the complete predicate. This class owns those database
 * facts; feature layers retain the meaning of the rows they lock.
 */
final class DatabaseLockBoundary {
    private const FOREIGN_KEY_METADATA_PROFILE = [
        'profile' => 'complete-innodb-foreign-key-census/v1',
        'required_global_privilege' => 'PROCESS',
        'metadata_sources' => [
            'MariaDB' => 'INNODB_SYS_FOREIGN',
            'MySQL' => 'INNODB_FOREIGN',
        ],
    ];

    /**
     * Machine-readable product prerequisite shared with compatibility checks.
     *
     * @return array{
     *   profile:string,
     *   required_global_privilege:string,
     *   metadata_sources:array{MariaDB:string,MySQL:string}
     * }
     */
    public static function foreign_key_metadata_profile(): array {
        return self::FOREIGN_KEY_METADATA_PROFILE;
    }

    /**
     * Prove tables used only for authoritative reads/locks.
     *
     * Trigger topology is irrelevant to SELECT semantics and requiring its
     * visibility here would make a read-only lock depend on mutation
     * privileges. Call assert_atomic_mutation_tables() for every write target.
     *
     * @param list<string> $tables
     */
    public static function assert_innodb_tables(
        array $tables,
        string $purpose,
        ?callable $continuity = null
    ): void {
        global $wpdb;

        self::assert_table_identifiers($tables, $purpose);
        $tables = array_values(array_unique($tables));
        if ($tables === []) {
            return;
        }
        if (DatabaseQueryIsolation::has_bound_profile()) {
            DatabaseQueryIsolation::assert_profile_contains($tables, false, $purpose);
            self::prove_continuity($continuity);
            return;
        }
        sort($tables, SORT_STRING);
        $tableSet = array_fill_keys($tables, true);
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));

        // A harmless read retains a metadata lock through the caller's
        // transaction, so the following engine/index evidence cannot be
        // invalidated by a concurrent ALTER/RENAME/DROP before use.
        foreach ($tables as $table) {
            self::touch_table($table, $purpose, $continuity);
            self::assert_plain_physical_table($table, $purpose, $continuity);
        }

        self::prove_continuity($continuity);
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES\n"
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)\n"
                . 'ORDER BY TABLE_NAME ASC',
            ...$tables
        ), ARRAY_A);
        $error = trim((string) ($wpdb->last_error ?? ''));
        self::prove_continuity($continuity);
        if (!is_array($rows) || !array_is_list($rows) || $error !== '') {
            $detail = $error !== '' ? $error : 'malformed result returned';
            throw new \RuntimeException(
                "wprism: $purpose refused — storage-engine introspection failed for "
                    . implode(', ', $tables) . ": $detail"
            );
        }

        $engines = [];
        $duplicates = [];
        foreach ($rows as $row) {
            $name = is_array($row) ? ($row['TABLE_NAME'] ?? null) : null;
            if (!is_string($name) || !isset($tableSet[$name])) {
                throw new \RuntimeException(
                    "wprism: $purpose refused — storage-engine introspection returned a malformed table identity"
                );
            }
            if (array_key_exists($name, $engines)) {
                $duplicates[$name] = true;
                continue;
            }
            $engine = $row['ENGINE'] ?? null;
            $engines[$name] = $engine === null || !is_string($engine) || trim($engine) === ''
                ? null
                : strtoupper(trim($engine));
        }

        $missing = array_values(array_diff($tables, array_keys($engines)));
        $unknown = [];
        $unsupported = [];
        foreach ($engines as $name => $engine) {
            if ($engine === null) {
                $unknown[] = "$name (engine: NULL/unknown)";
            } elseif ($engine !== 'INNODB') {
                $unsupported[] = "$name (engine: $engine)";
            }
        }
        foreach (array_keys($duplicates) as $name) {
            $unknown[] = "$name (duplicate information_schema rows)";
        }
        sort($missing, SORT_STRING);
        sort($unknown, SORT_STRING);
        sort($unsupported, SORT_STRING);
        if ($missing !== [] || $unknown !== [] || $unsupported !== []) {
            $details = [];
            if ($missing !== []) {
                $details[] = 'missing from information_schema.TABLES: ' . implode(', ', $missing);
            }
            if ($unknown !== []) {
                $details[] = 'unknown engine: ' . implode(', ', $unknown);
            }
            if ($unsupported !== []) {
                $details[] = 'unsupported engine (InnoDB required): ' . implode(', ', $unsupported);
            }
            throw new \RuntimeException("wprism: $purpose refused — " . implode('; ', $details));
        }
    }

    /**
     * Prove that rollback cannot escape through a table trigger.
     *
     * @param list<string> $tables
     */
    public static function assert_atomic_mutation_tables(
        array $tables,
        string $purpose,
        ?callable $continuity = null
    ): void {
        self::assert_table_identifiers($tables, $purpose);
        $tables = array_values(array_unique($tables));
        if ($tables === []) {
            return;
        }
        if (DatabaseQueryIsolation::has_bound_profile()) {
            DatabaseQueryIsolation::assert_profile_contains($tables, true, $purpose);
            self::prove_continuity($continuity);
            return;
        }
        sort($tables, SORT_STRING);
        self::assert_innodb_tables($tables, $purpose, $continuity);
        self::assert_no_triggers($tables, $purpose, $continuity);
        self::assert_closed_foreign_key_destinations($tables, $purpose, $continuity);
    }

    public static function full_width_lock_index(
        string $table,
        string $column,
        string $purpose,
        bool $requireUnique = false,
        ?callable $continuity = null
    ): string {
        return self::locking_index(
            $table,
            $column,
            $purpose,
            $requireUnique ? [$column] : null,
            null,
            $continuity
        );
    }

    /** @param list<string> $columns */
    public static function full_width_composite_unique_lock_index(
        string $table,
        array $columns,
        string $purpose,
        ?callable $continuity = null
    ): string {
        if (!array_is_list($columns) || count($columns) < 2 || count($columns) > 16) {
            throw new \RuntimeException("wprism: $purpose index proof received invalid compound columns");
        }
        foreach ($columns as $column) {
            if (!is_string($column) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $column) !== 1) {
                throw new \RuntimeException("wprism: $purpose index proof received invalid compound columns");
            }
        }
        if (count(array_unique($columns, SORT_STRING)) !== count($columns)) {
            throw new \RuntimeException("wprism: $purpose index proof received invalid compound columns");
        }
        return self::locking_index($table, $columns[0], $purpose, $columns, null, $continuity);
    }

    public static function bounded_prefix_lock_index(
        string $table,
        string $column,
        int $minimumPrefixCharacters,
        string $purpose,
        ?callable $continuity = null
    ): string {
        if ($minimumPrefixCharacters < 1 || $minimumPrefixCharacters > 65535) {
            throw new \RuntimeException("wprism: $purpose index proof received an invalid prefix frontier");
        }
        return self::locking_index(
            $table,
            $column,
            $purpose,
            null,
            $minimumPrefixCharacters,
            $continuity
        );
    }

    /** @param list<string> $tables */
    public static function assert_table_identifiers(array $tables, string $purpose): void {
        foreach ($tables as $table) {
            if (!is_string($table) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
                throw new \RuntimeException("wprism: $purpose refused — unsafe table identifier");
            }
        }
    }

    private static function touch_table(
        string $table,
        string $purpose,
        ?callable $continuity
    ): void {
        global $wpdb;
        self::prove_continuity($continuity);
        $wpdb->last_error = '';
        $probe = $wpdb->get_var("SELECT 1 FROM `$table` LIMIT 1");
        $error = trim((string) ($wpdb->last_error ?? ''));
        self::prove_continuity($continuity);
        if ($probe === false || $error !== '') {
            $detail = $error !== '' ? $error : 'no result returned';
            $tableLabel = $purpose === 'deletion guard locking' ? 'guard table ' : 'table ';
            throw new \RuntimeException(
                "wprism: $purpose refused — unable to acquire metadata lock for "
                    . $tableLabel . "$table: $detail"
            );
        }
    }

    /** Refuse session-local shadows, views, and SHOW/info_schema disagreement. */
    private static function assert_plain_physical_table(
        string $table,
        string $purpose,
        ?callable $continuity
    ): void {
        global $wpdb;
        self::prove_continuity($continuity);
        $wpdb->last_error = '';
        $row = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_N);
        $error = trim((string) ($wpdb->last_error ?? ''));
        self::prove_continuity($continuity);
        if (!is_array($row)
            || !array_is_list($row)
            || count($row) !== 2
            || !is_string($row[0])
            || !hash_equals($table, $row[0])
            || !is_string($row[1])
            || $error !== '') {
            $detail = $error !== '' ? $error : 'malformed SHOW CREATE TABLE result';
            throw new \RuntimeException(
                "wprism: $purpose refused — physical-table introspection failed for $table: $detail"
            );
        }
        $quoted = preg_quote($table, '/');
        if (preg_match("/^CREATE TABLE `$quoted`\\s*\\(/D", $row[1]) !== 1) {
            throw new \RuntimeException(
                "wprism: $purpose refused — $table is not the plain base table resolved by this session"
            );
        }
    }

    /**
     * A trigger can write outside the certified table set and make ROLLBACK
     * non-atomic (for example, InnoDB source -> MyISAM destination). Refuse the
     * entire trigger surface until the engine can prove a closed destination
     * graph without parsing arbitrary stored-program SQL.
     *
     * @param list<string> $tables
     */
    private static function assert_no_triggers(
        array $tables,
        string $purpose,
        ?callable $continuity
    ): void {
        global $wpdb;
        self::assert_trigger_metadata_visibility($tables, $purpose, $continuity);
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        self::prove_continuity($continuity);
        $wpdb->last_error = '';
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM information_schema.TRIGGERS\n"
                . "WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE IN ($placeholders)",
            ...$tables
        ));
        $error = trim((string) ($wpdb->last_error ?? ''));
        self::prove_continuity($continuity);
        $canonical = is_int($count)
            ? (string) $count
            : (is_string($count) ? $count : null);
        if ($canonical === null
            || preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $canonical) !== 1
            || $error !== '') {
            $detail = $error !== '' ? $error : 'malformed trigger census';
            throw new \RuntimeException(
                "wprism: $purpose refused — trigger introspection failed for "
                    . implode(', ', $tables) . ": $detail"
            );
        }
        if ($canonical !== '0') {
            throw new \RuntimeException(
                "wprism: $purpose refused — transaction mutation tables have $canonical trigger(s); "
                    . 'a closed transactional destination graph cannot be proven'
            );
        }
    }

    /**
     * MySQL hides trigger metadata from an account without TRIGGER privilege,
     * returning the same empty census as a trigger-free schema. Accept only a
     * direct grant to CURRENT_USER(); role-derived/ambiguous authority remains
     * fail-closed because information_schema does not expose a portable,
     * cross-engine effective-role expansion.
     *
     * @param list<string> $tables
     */
    private static function assert_trigger_metadata_visibility(
        array $tables,
        string $purpose,
        ?callable $continuity
    ): void {
        global $wpdb;
        self::prove_continuity($continuity);
        $wpdb->last_error = '';
        $account = $wpdb->get_var('SELECT CURRENT_USER()');
        $accountError = trim((string) ($wpdb->last_error ?? ''));
        self::prove_continuity($continuity);
        if (!is_string($account)
            || $account === ''
            || strlen($account) > 288
            || str_contains($account, "\0")
            || strrpos($account, '@') === false
            || $accountError !== '') {
            throw new \RuntimeException(
                "wprism: $purpose refused — current database account could not be proven for trigger visibility"
            );
        }
        $separator = strrpos($account, '@');
        if (!is_int($separator) || $separator < 1 || $separator === strlen($account) - 1) {
            throw new \RuntimeException(
                "wprism: $purpose refused — current database account is malformed for trigger visibility"
            );
        }
        $grantee = "'" . substr($account, 0, $separator) . "'@'"
            . substr($account, $separator + 1) . "'";
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        $sql = $wpdb->prepare(
            "SELECT scope_type, table_name FROM (\n"
                . "SELECT 'global' AS scope_type, '' AS table_name\n"
                . "FROM information_schema.USER_PRIVILEGES\n"
                . "WHERE BINARY GRANTEE = BINARY %s AND PRIVILEGE_TYPE = 'TRIGGER'\n"
                . "UNION ALL\n"
                . "SELECT 'schema' AS scope_type, '' AS table_name\n"
                . "FROM information_schema.SCHEMA_PRIVILEGES\n"
                . "WHERE BINARY GRANTEE = BINARY %s AND BINARY TABLE_SCHEMA = BINARY DATABASE()\n"
                . "AND PRIVILEGE_TYPE = 'TRIGGER'\n"
                . "UNION ALL\n"
                . "SELECT 'table' AS scope_type, TABLE_NAME AS table_name\n"
                . "FROM information_schema.TABLE_PRIVILEGES\n"
                . "WHERE BINARY GRANTEE = BINARY %s AND BINARY TABLE_SCHEMA = BINARY DATABASE()\n"
                . "AND PRIVILEGE_TYPE = 'TRIGGER' AND TABLE_NAME IN ($placeholders)\n"
                . ') AS direct_trigger_grants ORDER BY scope_type, table_name',
            $grantee,
            $grantee,
            $grantee,
            ...$tables
        );
        self::prove_continuity($continuity);
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $error = trim((string) ($wpdb->last_error ?? ''));
        self::prove_continuity($continuity);
        if (!is_array($rows)
            || !array_is_list($rows)
            || count($rows) > count($tables) + 2
            || $error !== '') {
            throw new \RuntimeException(
                "wprism: $purpose refused — direct trigger-visibility grants could not be proven"
            );
        }
        $broad = false;
        $tableGrants = [];
        $allowed = array_fill_keys($tables, true);
        foreach ($rows as $row) {
            if (!is_array($row)
                || array_keys($row) !== ['scope_type', 'table_name']
                || !is_string($row['scope_type'])
                || !is_string($row['table_name'])) {
                throw new \RuntimeException(
                    "wprism: $purpose refused — trigger-visibility grants returned malformed evidence"
                );
            }
            if (in_array($row['scope_type'], ['global', 'schema'], true)
                && $row['table_name'] === '') {
                $broad = true;
                continue;
            }
            if ($row['scope_type'] !== 'table' || !isset($allowed[$row['table_name']])) {
                throw new \RuntimeException(
                    "wprism: $purpose refused — trigger-visibility grants returned foreign evidence"
                );
            }
            $tableGrants[$row['table_name']] = true;
        }
        if (!$broad && count($tableGrants) !== count($tables)) {
            throw new \RuntimeException(
                "wprism: $purpose refused — current database account lacks direct TRIGGER metadata visibility"
            );
        }
    }

    /**
     * Close the implicit write graph created by referential actions.
     *
     * Updating or deleting a referenced row can make InnoDB mutate a child
     * table without that table appearing in the provider's SQL. Every CASCADE
     * or SET NULL/DEFAULT destination must therefore already be one of the
     * declared, InnoDB-proven mutation tables above. Because every declared
     * table is queried as a possible parent, accepting this one bounded census
     * proves the complete transitive graph is closed.
     *
     * @param list<string> $tables
     */
    private static function assert_closed_foreign_key_destinations(
        array $tables,
        string $purpose,
        ?callable $continuity
    ): void {
        global $wpdb;
        self::assert_foreign_key_metadata_authority($purpose, $continuity);
        $source = self::foreign_key_metadata_source($purpose, $continuity);
        $schema = self::foreign_key_internal_schema_name($source, $purpose, $continuity);
        $parentNames = array_map(
            static fn(string $table): string => $schema . '/' . $table,
            $tables
        );
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        $sql = $wpdb->prepare(
            "SELECT ID AS CONSTRAINT_NAME,\n"
                . "SUBSTRING_INDEX(FOR_NAME, '/', -1) AS TABLE_NAME,\n"
                . "SUBSTRING_INDEX(REF_NAME, '/', -1) AS REFERENCED_TABLE_NAME,\n"
                . "(BINARY SUBSTRING_INDEX(FOR_NAME, '/', 1) = BINARY %s)\n"
                . "AS DESTINATION_IN_CURRENT_SCHEMA, TYPE\n"
                . "FROM information_schema.$source\n"
                . "WHERE BINARY REF_NAME IN ($placeholders) AND (TYPE & 15) <> 0\n"
                . 'ORDER BY REF_NAME, FOR_NAME, ID LIMIT 4097',
            $schema,
            ...$parentNames
        );
        self::prove_continuity($continuity);
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $error = trim((string) ($wpdb->last_error ?? ''));
        self::prove_continuity($continuity);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 4096 || $error !== '') {
            throw new \RuntimeException(
                "wprism: $purpose refused — foreign-key mutation-destination census could not be proven"
            );
        }

        $allowed = array_fill_keys($tables, true);
        $seen = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || array_keys($row) !== [
                    'CONSTRAINT_NAME',
                    'TABLE_NAME',
                    'REFERENCED_TABLE_NAME',
                    'DESTINATION_IN_CURRENT_SCHEMA',
                    'TYPE',
                ]) {
                throw new \RuntimeException(
                    "wprism: $purpose refused — foreign-key mutation-destination census returned malformed evidence"
                );
            }
            $constraint = $row['CONSTRAINT_NAME'];
            $destination = $row['TABLE_NAME'];
            $source = $row['REFERENCED_TABLE_NAME'];
            $destinationInCurrentSchema = $row['DESTINATION_IN_CURRENT_SCHEMA'];
            $type = self::canonical_index_integer($row['TYPE'], 63, true);
            if (!self::bounded_metadata_name($constraint)
                || !is_string($destination)
                || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $destination) !== 1
                || !is_string($source)
                || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $source) !== 1
                || !is_string($destinationInCurrentSchema)
                || !in_array($destinationInCurrentSchema, ['0', '1'], true)
                || !isset($allowed[$source])
                || $type === null
                || ($type & 15) === 0) {
                throw new \RuntimeException(
                    "wprism: $purpose refused — foreign-key mutation-destination census returned malformed evidence"
                );
            }
            $identity = $constraint . "\0" . $destination . "\0" . $source;
            if (isset($seen[$identity])) {
                throw new \RuntimeException(
                    "wprism: $purpose refused — foreign-key mutation-destination census returned duplicate evidence"
                );
            }
            $seen[$identity] = true;
            if ($destinationInCurrentSchema !== '1' || !isset($allowed[$destination])) {
                throw new \RuntimeException(
                    "wprism: $purpose refused — a foreign-key referential action escapes the declared mutation tables"
                );
            }
        }
    }

    /**
     * PROCESS exposes the storage engine's complete foreign-key dictionary.
     * Object-scoped grants are insufficient: MySQL 8.4 hides an unprivileged
     * cross-schema child from REFERENTIAL_CONSTRAINTS while still executing
     * its cascade from a visible parent. Role-derived authority remains closed
     * so this premise cannot disappear during the transaction.
     */
    private static function assert_foreign_key_metadata_authority(
        string $purpose,
        ?callable $continuity
    ): void {
        global $wpdb;
        self::prove_continuity($continuity);
        $wpdb->last_error = '';
        $account = $wpdb->get_var('SELECT CURRENT_USER()');
        $accountError = trim((string) ($wpdb->last_error ?? ''));
        self::prove_continuity($continuity);
        if (!is_string($account)
            || $account === ''
            || strlen($account) > 288
            || str_contains($account, "\0")
            || strrpos($account, '@') === false
            || $accountError !== '') {
            throw new \RuntimeException(
                "wprism: $purpose refused — current database account could not be proven for foreign-key visibility"
            );
        }
        $separator = strrpos($account, '@');
        if (!is_int($separator) || $separator < 1 || $separator === strlen($account) - 1) {
            throw new \RuntimeException(
                "wprism: $purpose refused — current database account is malformed for foreign-key visibility"
            );
        }
        $grantee = "'" . substr($account, 0, $separator) . "'@'"
            . substr($account, $separator + 1) . "'";
        $sql = $wpdb->prepare(
            "SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES\n"
                . "WHERE BINARY GRANTEE = BINARY %s AND PRIVILEGE_TYPE = 'PROCESS'\n"
                . 'ORDER BY PRIVILEGE_TYPE',
            $grantee
        );
        self::prove_continuity($continuity);
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $error = trim((string) ($wpdb->last_error ?? ''));
        self::prove_continuity($continuity);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 1 || $error !== '') {
            throw new \RuntimeException(
                "wprism: $purpose refused — direct global PROCESS foreign-key metadata authority could not be proven"
            );
        }
        foreach ($rows as $row) {
            if (!is_array($row)
                || array_keys($row) !== ['PRIVILEGE_TYPE']
                || $row['PRIVILEGE_TYPE'] !== 'PROCESS') {
                throw new \RuntimeException(
                    "wprism: $purpose refused — direct global PROCESS foreign-key metadata authority returned malformed evidence"
                );
            }
        }
        if ($rows === []) {
            throw new \RuntimeException(
                "wprism: $purpose refused — current database account lacks direct global PROCESS foreign-key metadata authority"
            );
        }
    }

    private static function foreign_key_metadata_source(
        string $purpose,
        ?callable $continuity
    ): string {
        global $wpdb;
        self::prove_continuity($continuity);
        $wpdb->last_error = '';
        $server = $wpdb->get_var('SELECT VERSION()');
        $versionError = trim((string) ($wpdb->last_error ?? ''));
        self::prove_continuity($continuity);
        if (!is_string($server)
            || $server === ''
            || strlen($server) > 255
            || preg_match('/[\x00-\x1f\x7f]/', $server) === 1
            || preg_match('/^\s*\d+(?:\.\d+){1,3}/D', $server) !== 1
            || $versionError !== '') {
            throw new \RuntimeException(
                "wprism: $purpose refused — database engine identity could not be proven for foreign-key metadata"
            );
        }
        $family = stripos($server, 'mariadb') !== false ? 'MariaDB' : 'MySQL';
        $expected = self::FOREIGN_KEY_METADATA_PROFILE['metadata_sources'][$family];
        $sources = array_values(self::FOREIGN_KEY_METADATA_PROFILE['metadata_sources']);
        $placeholders = implode(',', array_fill(0, count($sources), '%s'));
        self::prove_continuity($continuity);
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT TABLE_NAME FROM information_schema.TABLES\n"
                . "WHERE BINARY TABLE_SCHEMA = BINARY 'information_schema'\n"
                . "AND TABLE_NAME IN ($placeholders) ORDER BY TABLE_NAME",
            ...$sources
        ), ARRAY_A);
        $error = trim((string) ($wpdb->last_error ?? ''));
        self::prove_continuity($continuity);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > count($sources) || $error !== '') {
            throw new \RuntimeException(
                "wprism: $purpose refused — InnoDB foreign-key metadata source could not be proven"
            );
        }
        if (count($rows) !== 1
            || !is_array($rows[0])
            || array_keys($rows[0]) !== ['TABLE_NAME']
            || !is_string($rows[0]['TABLE_NAME'])
            || !hash_equals($expected, $rows[0]['TABLE_NAME'])) {
            throw new \RuntimeException(
                "wprism: $purpose refused — the $family InnoDB foreign-key metadata source is unavailable, mismatched, or ambiguous"
            );
        }
        return $rows[0]['TABLE_NAME'];
    }

    /** Resolve the source-specific schema representation used by InnoDB. */
    private static function foreign_key_internal_schema_name(
        string $source,
        string $purpose,
        ?callable $continuity
    ): string {
        global $wpdb;
        self::prove_continuity($continuity);
        $wpdb->last_error = '';
        $database = $wpdb->get_var($source === 'INNODB_SYS_FOREIGN'
            ? 'SELECT CAST(CONVERT(DATABASE() USING filename) AS BINARY)'
            : 'SELECT DATABASE()');
        $error = trim((string) ($wpdb->last_error ?? ''));
        self::prove_continuity($continuity);
        if (!is_string($database)
            || $database === ''
            || !self::bounded_metadata_name($database)
            || str_contains($database, '/')
            || $error !== '') {
            throw new \RuntimeException(
                "wprism: $purpose refused — active database identity cannot be represented by the closed foreign-key metadata grammar"
            );
        }
        return $database;
    }

    /**
     * @param ?list<string> $requiredUniqueColumns
     */
    private static function locking_index(
        string $table,
        string $column,
        string $purpose,
        ?array $requiredUniqueColumns,
        ?int $minimumPrefixCharacters,
        ?callable $continuity
    ): string {
        global $wpdb;
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1
            || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $column) !== 1) {
            throw new \RuntimeException("wprism: $purpose index proof received an unsafe table/column name");
        }
        self::prove_continuity($continuity);
        $wpdb->last_error = '';
        $rows = $wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A);
        $error = trim((string) ($wpdb->last_error ?? ''));
        self::prove_continuity($continuity);
        if (!is_array($rows) || !array_is_list($rows) || $error !== '') {
            throw new \RuntimeException("wprism: $purpose index introspection failed");
        }
        if (count($rows) > 1024) {
            throw new \RuntimeException("wprism: $purpose index introspection exceeded its bounded row limit");
        }
        $groups = [];
        foreach ($rows as $row) {
            $name = is_array($row) ? ($row['Key_name'] ?? null) : null;
            if (!is_array($row) || !self::bounded_server_identifier($name)) {
                throw new \RuntimeException("wprism: $purpose index introspection returned a malformed row");
            }
            $groupKey = hash('sha256', $name);
            if (isset($groups[$groupKey]) && !hash_equals($groups[$groupKey]['name'], $name)) {
                throw new \RuntimeException("wprism: $purpose index introspection identity fingerprint collided");
            }
            $groups[$groupKey]['name'] = $name;
            $groups[$groupKey]['safe_name'] = preg_match('/^[A-Za-z0-9_]{1,64}$/D', $name) === 1;
            $groups[$groupKey]['rows'][] = $row;
        }

        $candidates = [];
        foreach ($groups as $group) {
            $name = $group['name'];
            if (!$group['safe_name']) {
                continue;
            }
            $firstColumnMatches = false;
            $hasFunctionalPart = false;
            foreach ($group['rows'] as $row) {
                $columnName = $row['Column_name'] ?? null;
                $seq = self::canonical_index_integer($row['Seq_in_index'] ?? null, 64, false);
                if ($columnName === $column && $seq === null) {
                    throw new \RuntimeException("wprism: $purpose index introspection returned a malformed row");
                }
                if ($columnName === $column && $seq === 1) {
                    $firstColumnMatches = true;
                }
                if ($columnName === null && array_key_exists('Expression', $row)) {
                    $hasFunctionalPart = true;
                }
            }
            if (!$firstColumnMatches || $hasFunctionalPart) {
                continue;
            }

            $indexRows = [];
            foreach ($group['rows'] as $row) {
                $seq = $row['Seq_in_index'] ?? null;
                $nonUnique = $row['Non_unique'] ?? null;
                $subPart = $row['Sub_part'] ?? null;
                $indexType = $row['Index_type'] ?? null;
                $visible = $row['Visible'] ?? null;
                $ignored = $row['Ignored'] ?? null;
                $columnName = $row['Column_name'] ?? null;
                $canonicalSeq = self::canonical_index_integer($seq, 64, false);
                $canonicalNonUnique = self::canonical_index_integer($nonUnique, 1, true);
                $canonicalSubPart = $subPart === null
                    ? null
                    : self::canonical_index_integer($subPart, 65535, false);
                if (!self::bounded_server_identifier($columnName)
                    || $canonicalSeq === null
                    || $canonicalNonUnique === null
                    || ($subPart !== null && $canonicalSubPart === null)
                    || !is_string($indexType)
                    || preg_match('/^[A-Z]{2,16}$/D', $indexType) !== 1
                    || (array_key_exists('Visible', $row) && !in_array($visible, ['YES', 'NO'], true))
                    || (array_key_exists('Ignored', $row) && !in_array($ignored, ['YES', 'NO'], true))) {
                    throw new \RuntimeException("wprism: $purpose index introspection returned a malformed row");
                }
                if (isset($indexRows[$canonicalSeq])) {
                    throw new \RuntimeException(
                        "wprism: $purpose index introspection returned duplicate index positions"
                    );
                }
                $indexRows[$canonicalSeq] = [
                    'column' => $columnName,
                    'non_unique' => $canonicalNonUnique,
                    'sub_part' => $canonicalSubPart,
                    'index_type' => $indexType,
                    'has_visible' => array_key_exists('Visible', $row),
                    'visible' => $visible,
                    'has_ignored' => array_key_exists('Ignored', $row),
                    'ignored' => $ignored,
                ];
            }
            ksort($indexRows, SORT_NUMERIC);
            $expectedPosition = 1;
            $first = $indexRows[1] ?? null;
            $firstMetadata = null;
            foreach ($indexRows as $position => $row) {
                if ($position !== $expectedPosition) {
                    throw new \RuntimeException(
                        "wprism: $purpose index introspection returned noncontiguous index positions"
                    );
                }
                $metadata = [
                    $row['non_unique'],
                    $row['index_type'],
                    $row['has_visible'],
                    $row['visible'],
                    $row['has_ignored'],
                    $row['ignored'],
                ];
                $firstMetadata ??= $metadata;
                if ($metadata !== $firstMetadata) {
                    throw new \RuntimeException(
                        "wprism: $purpose index introspection returned inconsistent composite-index metadata"
                    );
                }
                ++$expectedPosition;
            }

            $matchesRequiredUniqueColumns = $requiredUniqueColumns === null;
            if ($requiredUniqueColumns !== null && count($indexRows) === count($requiredUniqueColumns)) {
                $matchesRequiredUniqueColumns = true;
                foreach ($requiredUniqueColumns as $offset => $requiredColumn) {
                    $part = $indexRows[$offset + 1] ?? null;
                    if (($part['column'] ?? null) !== $requiredColumn
                        || ($part['sub_part'] ?? null) !== null) {
                        $matchesRequiredUniqueColumns = false;
                        break;
                    }
                }
            }
            if ($first === null
                || !$matchesRequiredUniqueColumns
                || $first['column'] !== $column
                || ($minimumPrefixCharacters === null
                    ? $first['sub_part'] !== null
                    : ($first['sub_part'] !== null && $first['sub_part'] < $minimumPrefixCharacters))
                || ($requiredUniqueColumns !== null && $first['non_unique'] !== 0)
                || ($first['has_visible'] && $first['visible'] !== 'YES')
                || ($first['has_ignored'] && $first['ignored'] !== 'NO')
                || $first['index_type'] !== 'BTREE') {
                continue;
            }
            $candidates[(string) $name] = true;
        }

        if ($candidates === []) {
            $indexLabel = $requiredUniqueColumns === null
                ? "first-column index on $column"
                : (count($requiredUniqueColumns) === 1
                    ? "unique first-column index on $column"
                    : 'unique ordered-columns index on (' . implode(', ', $requiredUniqueColumns) . ')');
            throw new \RuntimeException(
                "wprism: $purpose lacks a visible "
                    . ($minimumPrefixCharacters === null
                        ? 'full-width '
                        : "at-least-$minimumPrefixCharacters-character ")
                    . $indexLabel
            );
        }
        $names = array_keys($candidates);
        sort($names, SORT_STRING);
        return $names[0];
    }

    private static function bounded_server_identifier(mixed $value): bool {
        if (!is_string($value)
            || $value === ''
            || strlen($value) > 256
            || str_contains($value, "\0")
            || preg_match('//u', $value) !== 1) {
            return false;
        }
        $characters = preg_match_all('/./us', $value);
        return is_int($characters) && $characters <= 64;
    }

    private static function bounded_metadata_name(mixed $value): bool {
        return is_string($value)
            && $value !== ''
            && strlen($value) <= 1024
            && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1
            && preg_match('//u', $value) === 1;
    }

    private static function canonical_index_integer(mixed $value, int $max, bool $allowZero): ?int {
        if (is_int($value)) {
            $integer = $value;
        } elseif (is_string($value)
            && preg_match($allowZero ? '/^(?:0|[1-9][0-9]*)$/D' : '/^[1-9][0-9]*$/D', $value) === 1) {
            $integer = filter_var($value, FILTER_VALIDATE_INT);
            if (!is_int($integer)) {
                return null;
            }
        } else {
            return null;
        }
        return $integer >= ($allowZero ? 0 : 1) && $integer <= $max ? $integer : null;
    }

    private static function prove_continuity(?callable $continuity): void {
        if ($continuity !== null) {
            $continuity();
        }
    }
}
