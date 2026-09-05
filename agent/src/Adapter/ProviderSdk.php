<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/ExactOptionReader.php';
require_once __DIR__ . '/../Kernel/DatabaseExceptions.php';
require_once __DIR__ . '/../Kernel/DatabaseQueryIsolation.php';
require_once __DIR__ . '/../Kernel/DatabaseTablePresence.php';
require_once __DIR__ . '/../Kernel/FilesystemTreeSnapshot.php';
require_once __DIR__ . '/../Kernel/NativeDatabaseProfile.php';
require_once __DIR__ . '/../Kernel/PhpLiteralData.php';
require_once __DIR__ . '/../Policy/LegacyRuntimeExecutionDebt.php';
if (!class_exists(ManifestProviderRuntime::class, false)) {
    require_once __DIR__ . '/ManifestProviderRuntime.php';
}
if (!class_exists(Providers::class, false)) {
    require_once __DIR__ . '/Providers.php';
}

/**
 * The sanctioned provider path for checked reads and contract-scoped DML,
 * plugin-agnostic and shared by every adapter rather than re-copied into each.
 *
 * WordPress's wpdb read methods return an empty-looking value on a failed
 * query rather than throwing: get_var()/get_row() collapse to null, while
 * get_col()/get_results() expose the empty last_result reset before transport;
 * compatible drivers may additionally return an unexpected shape. A provider
 * that trusted those bare values could clear a durable receipt on a query that
 * never ran. Before transport, the shared closed SQL lexer also proves one
 * non-mutating SELECT/SHOW/DESCRIBE/EXPLAIN statement; a dynamically assembled
 * DELETE cannot turn a wpdb read method into an untyped mutation channel. Each
 * method then clears last_error, runs the read, and refuses either an invalid
 * result shape or a driver-reported error.
 *
 * Like Db, the context is caller-supplied and OPERATION-level, never
 * value-level: wpdb's last_error and the rendered SQL can echo option/meta
 * payloads, including the secrets WPrism is specifically responsible for keeping
 * out of diagnostics, so neither the SQL nor the driver text is ever placed
 * in the message — only the caller's operation context, which locates the
 * failure without carrying a value. "provider" names the caller generically
 * so no plugin identity leaks into the string either.
 *
 * The failure is a plain \RuntimeException — the same class the retired
 * per-adapter helpers threw — so \WPrism\Providers::invoke()'s catch(\Throwable)
 * puts it behind a provider/capability-only printable wrapper. The exact cause
 * remains available only to the CLI's 0600 private refusal record; a migrating
 * adapter's calls are otherwise a pure substitution.
 *
 * $wpdb defaults to the global (matching Db.php) and is injectable so an
 * offline test can drive a fake. checked_get_results() additionally accepts
 * one fixed caller-owned refusal sentence: migrated adapters retain their
 * reviewed public diagnostics without reimplementing the checked read.
 */
final class ProviderSdk {
    public const DATABASE_POSTIMAGE_APPLIED = 'applied';
    public const DATABASE_POSTIMAGE_NOT_APPLIED = 'not_applied';
    public const DATABASE_POSTIMAGE_UNKNOWN = 'unknown';

    /**
     * Observe one exact confined generated-file tree through the shared
     * bounded/race-checked engine walker.
     *
     * @return array{
     *   root:string,
     *   directories:list<string>,
     *   files:list<array{path:string,bytes:int,mtime:int,sha256:string}>
     * }
     */
    public static function filesystem_tree_snapshot(
        string $containmentRoot,
        string $absoluteRoot,
        string $canonicalRoot
    ): array {
        return FilesystemTreeSnapshot::observe(
            $containmentRoot,
            $absoluteRoot,
            $canonicalRoot,
            'provider filesystem',
            'provider filesystem containment root'
        );
    }

    /** Read a digest-witnessed PHP return-literal without executing target bytes. */
    public static function php_literal_data(string $path, string $expectedSha256): mixed {
        return PhpLiteralData::read($path, $expectedSha256);
    }

    public static function checked_get_var(string $sql, string $context, $wpdb = null): mixed {
        $legacyPermit = self::permits_legacy_named_mutex_statement($sql);
        if (!$legacyPermit) {
            DatabaseQueryIsolation::assert_provider_read_statement($sql);
        }
        $wpdb ??= $GLOBALS['wpdb'];
        try {
            $wpdb->last_error = '';
            $value = self::checked_read_transport(
                $sql,
                $context,
                $wpdb,
                static fn(): mixed => $wpdb->get_var($sql)
            );
        } catch (\Throwable $failure) {
            throw self::checked_read_failure($context, null, $failure);
        }
        if ($value === false || (string) ($wpdb->last_error ?? '') !== '') {
            throw self::checked_read_failure($context);
        }
        return $value;
    }

    /** @return array<int,mixed> */
    public static function checked_get_col(string $sql, string $context, $wpdb = null): array {
        DatabaseQueryIsolation::assert_provider_read_statement($sql);
        $wpdb ??= $GLOBALS['wpdb'];
        try {
            $wpdb->last_error = '';
            $rows = self::checked_read_transport(
                $sql,
                $context,
                $wpdb,
                static fn(): mixed => $wpdb->get_col($sql)
            );
        } catch (\Throwable $failure) {
            throw self::checked_read_failure($context, null, $failure);
        }
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw self::checked_read_failure($context);
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public static function checked_get_row(string $sql, string $context, $wpdb = null): ?array {
        DatabaseQueryIsolation::assert_provider_read_statement($sql);
        $wpdb ??= $GLOBALS['wpdb'];
        try {
            $wpdb->last_error = '';
            $row = self::checked_read_transport(
                $sql,
                $context,
                $wpdb,
                static fn(): mixed => $wpdb->get_row($sql, ARRAY_A)
            );
        } catch (\Throwable $failure) {
            throw self::checked_read_failure($context, null, $failure);
        }
        if (($row !== null && !is_array($row)) || (string) ($wpdb->last_error ?? '') !== '') {
            throw self::checked_read_failure($context);
        }
        return $row;
    }

    /** @return list<array<string,mixed>> */
    public static function checked_get_results(
        string $sql,
        string $context,
        $wpdb = null,
        ?string $failureMessage = null
    ): array {
        DatabaseQueryIsolation::assert_provider_read_statement($sql);
        $wpdb ??= $GLOBALS['wpdb'];
        try {
            $wpdb->last_error = '';
            $rows = self::checked_read_transport(
                $sql,
                $context,
                $wpdb,
                static fn(): mixed => $wpdb->get_results($sql, ARRAY_A)
            );
        } catch (\Throwable $failure) {
            throw self::checked_read_failure($context, $failureMessage, $failure);
        }
        if (!is_array($rows) || !array_is_list($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw self::checked_read_failure($context, $failureMessage);
        }
        return $rows;
    }

    /**
     * Read and decode one option row from the durable database state.
     *
     * get_option() may be backed by a persistent object cache shared by two
     * otherwise fresh PHP processes. A physical postimage proof therefore
     * uses the option row in the engine-owned snapshot, not request freshness
     * as a proxy for storage freshness.
     */
    public static function checked_durable_option(
        string $name,
        mixed $default,
        string $context,
        $wpdb = null
    ): mixed {
        return ExactOptionReader::read_plain($name, $default, $context, $wpdb);
    }

    /**
     * Run a fresh observer inside a database read-only profile derived from
     * its complete declared read/write surface. Table and option surfaces map
     * to physical wpdb tables; every other database query is refused by the
     * transaction-local query gate, so an adapter must declare every table its
     * semantic observer actually reaches.
     *
     * @template T
     * @param callable():T $read
     * @return T
     */
    public static function database_read_contract_snapshot(
        string $context,
        callable $read
    ): mixed {
        $profile = self::active_database_profile($context);
        return self::run_database_read_snapshot(
            $context,
            NativeDatabaseProfile::read_only($profile->readable_tables()),
            $read
        );
    }

    /**
     * Run one adapter projection in an engine-owned read-only snapshot.
     *
     * @template T
     * @param list<string> $readTables
     * @param callable():T $read
     * @return T
     */
    public static function database_read_snapshot(
        string $context,
        array $readTables,
        callable $read
    ): mixed {
        $requested = NativeDatabaseProfile::read_only($readTables);
        $contract = self::active_database_profile($context);
        if (array_diff($requested->readable_tables(), $contract->readable_tables()) !== []) {
            throw new \RuntimeException(
                "wprism: $context requested database tables outside its active manifest-provider contract"
            );
        }
        return self::run_database_read_snapshot(
            $context,
            $requested,
            $read
        );
    }

    /**
     * Run one schema projection when declared tables may legitimately be
     * absent.
     *
     * An empty-profile snapshot first discovers exact table presence. A
     * second snapshot binds every present table to the ordinary InnoDB read
     * profile, rechecks the complete topology before and after the callback,
     * and hands the callback only the closed presence map. This keeps raw
     * metadata bootstrap reads out of newly authored providers without
     * pretending an absent table can be proven as an existing profile member.
     * A fresh observer already runs in a complete read-only contract snapshot;
     * in that case the same bracketed projection reuses the proven profile
     * instead of attempting a nested transaction. Writable and narrower
     * profiles never gain schema-read authority through this API.
     *
     * @template T
     * @param list<string> $tables
     * @param callable(array<string,bool>):T $read
     * @return T
     */
    public static function database_schema_snapshot(
        string $context,
        array $tables,
        callable $read
    ): mixed {
        $requested = NativeDatabaseProfile::read_only($tables)->readable_tables();
        $contract = self::active_database_profile($context);
        if (array_diff($requested, $contract->readable_tables()) !== []) {
            throw new \RuntimeException(
                "wprism: $context requested schema tables outside its active manifest-provider contract"
            );
        }
        if (DatabaseQueryIsolation::is_active()) {
            if (!DatabaseQueryIsolation::bound_profile_is_read_only()) {
                throw new \RuntimeException(
                    "wprism: $context cannot reuse a writable or unbound database profile for schema evidence"
                );
            }
            DatabaseQueryIsolation::assert_profile_contains(
                $requested,
                false,
                $context . ' existing read-only profile'
            );
            return self::database_schema_projection(
                $context,
                $requested,
                array_fill_keys($requested, true),
                $read
            );
        }
        $presence = self::run_database_read_snapshot(
            $context . ' discovery',
            NativeDatabaseProfile::schema_read_only([], $requested),
            static fn(): array => self::database_table_presence($requested, $context . ' discovery')
        );
        $present = array_keys(array_filter(
            $presence,
            static fn(bool $exists): bool => $exists
        ));
        return self::run_database_read_snapshot(
            $context,
            NativeDatabaseProfile::schema_read_only($present, $requested),
            static fn(): mixed => self::database_schema_projection(
                $context,
                $requested,
                $presence,
                $read
            )
        );
    }

    /**
     * Run one audited native callback in the complete database profile derived
     * from the active digest-bound capability contract.
     *
     * @template T
     * @param callable():T $write
     * @param callable(T):string $classifyPhysicalPostimage
     * @return T
     */
    public static function database_write_contract_transaction(
        string $context,
        callable $write,
        callable $classifyPhysicalPostimage
    ): mixed {
        self::load_database_session();
        return ProviderDatabaseSession::repeatable_read_write(
            $context,
            self::active_database_profile($context),
            $write,
            $classifyPhysicalPostimage
        );
    }

    /**
     * Delete rows by one wpdb-compatible equality predicate.
     *
     * The bound profile and transaction-authority checks intentionally happen
     * before Db sees the request. Db's ordinary standalone transaction mode is
     * an engine facility; a provider may reach this wrapper only from the
     * callback of database_write_contract_transaction().
     */
    public static function database_delete(
        string $table,
        array $where,
        string $context,
        mixed $whereFormat = null
    ): int {
        self::assert_profiled_write_table($table, $context);
        return Db::delete($table, $where, $whereFormat, $context);
    }

    /** Delete every row only when the active capability declares the table writable. */
    public static function database_delete_all(string $table, string $context): int {
        self::assert_profiled_write_table($table, $context);
        return Db::delete_all($table, $context);
    }

    private static function load_database_session(): void {
        if (!class_exists(ProviderDatabaseSession::class, false)) {
            require_once __DIR__ . '/ProviderDatabaseSession.php';
        }
    }

    /**
     * @template T
     * @param callable():T $read
     * @return T
     */
    private static function run_database_read_snapshot(
        string $context,
        NativeDatabaseProfile $profile,
        callable $read
    ): mixed {
        self::load_database_session();
        return ProviderDatabaseSession::read_only_snapshot($context, $profile, $read);
    }

    private static function active_database_profile(string $context): NativeDatabaseProfile {
        $contract = ManifestProviderRuntime::active_validated_contract($context);
        $reads = self::contract_surfaces($contract, 'reads', $context);
        $writes = self::contract_surfaces($contract, 'writes', $context);
        return new NativeDatabaseProfile(
            self::physical_tables_for_surfaces($reads, $context),
            self::physical_tables_for_surfaces($writes, $context)
        );
    }

    /** @param array<string,mixed> $contract @return list<string> */
    private static function contract_surfaces(array $contract, string $kind, string $context): array {
        $surfaces = $contract[$kind] ?? null;
        if (!is_array($surfaces) || !array_is_list($surfaces)) {
            throw new \RuntimeException(
                "wprism: $context has no closed manifest-provider $kind surface declaration"
            );
        }
        foreach ($surfaces as $surface) {
            if (!is_string($surface)) {
                throw new \RuntimeException(
                    "wprism: $context has a malformed manifest-provider $kind surface declaration"
                );
            }
        }
        return $surfaces;
    }

    private static function assert_profiled_write_table(string $table, string $context): void {
        if (!DatabaseQueryIsolation::has_bound_profile()) {
            throw new \RuntimeException(
                "wprism: $context requires an active bound provider database write profile"
            );
        }
        DatabaseQueryIsolation::assert_profile_contains([$table], true, $context);
        if (!class_exists(Db::class, false)) {
            require_once __DIR__ . '/../Kernel/Db.php';
        }
        Db::transaction_authority($context . ' provider write authority');
    }

    private static function checked_read_failure(
        string $context,
        ?string $message = null,
        ?\Throwable $previous = null
    ): \RuntimeException {
        return new \RuntimeException(
            $message ?? "wprism: provider checked read failed: $context",
            0,
            $previous
        );
    }

    /**
     * Give legacy standalone checked reads the same parser/session premises as
     * a profiled provider callback. The exact permit proves that the selected
     * wpdb object crossed the engine-owned query gate; a caller-supplied second
     * database object can neither borrow the global session proof nor bypass
     * WordPress's transport.
     *
     * @template T
     * @param callable():T $operation
     * @return T
     */
    private static function checked_read_transport(
        string $sql,
        string $context,
        mixed $wpdb,
        callable $operation
    ): mixed {
        if (!is_object($wpdb) || ($GLOBALS['wpdb'] ?? null) !== $wpdb) {
            throw new DatabaseQueryIsolationViolationException(
                'wprism: provider checked read requires the exact WordPress database object'
            );
        }
        if (DatabaseQueryIsolation::is_active()) {
            return $operation();
        }

        DatabaseQueryIsolation::begin($context . ' checked-read boundary');
        $result = null;
        $failure = null;
        try {
            DatabaseQueryIsolation::assert_profile_sql_mode($context . ' checked-read');
            DatabaseQueryIsolation::permit_once($sql, $context . ' checked-read transport');
            $result = $operation();
            DatabaseQueryIsolation::assert_permit_consumed($context . ' checked-read transport');
        } catch (\Throwable $caught) {
            $failure = $caught;
        }

        try {
            DatabaseQueryIsolation::finish();
        } catch (\Throwable $finishFailure) {
            // A getter implemented outside stock wpdb can mutate session state
            // after consuming the permit. Quarantine it, restore the bound
            // state, and only then release the hooks; ordinary stock wpdb
            // reaches this path only if its native session actually drifted.
            try {
                DatabaseQueryIsolation::violation(
                    'wprism: provider checked read changed its database session state'
                );
            } catch (DatabaseQueryIsolationViolationException) {
                // violation() exists to poison before throwing.
            }
            try {
                DatabaseQueryIsolation::prepare_cleanup($context . ' checked-read cleanup');
                DatabaseQueryIsolation::finish();
            } catch (\Throwable $cleanupFailure) {
                throw new DatabaseQueryIsolationViolationException(
                    'wprism: provider checked read could not restore its database boundary',
                    0,
                    $failure ?? $cleanupFailure
                );
            }
            if ($failure === null) {
                $failure = $finishFailure;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
        return $result;
    }

    /** @param list<string> $tables @return array<string,bool> */
    private static function database_table_presence(array $tables, string $context): array {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        if (!is_object($wpdb)) {
            throw new \RuntimeException("wprism: $context requires exact wpdb schema discovery");
        }
        $presence = [];
        foreach ($tables as $table) {
            try {
                $presence[$table] = DatabaseTablePresence::base_table_exists($table);
            } catch (DatabaseTablePresenceException $failure) {
                throw new \RuntimeException(
                    "wprism: provider database read failed: $context",
                    0,
                    $failure
                );
            }
        }
        return $presence;
    }

    /**
     * @template T
     * @param list<string> $tables
     * @param array<string,bool> $expectedPresence
     * @param callable(array<string,bool>):T $read
     * @return T
     */
    private static function database_schema_projection(
        string $context,
        array $tables,
        array $expectedPresence,
        callable $read
    ): mixed {
        $before = self::database_table_presence($tables, $context . ' topology preimage');
        if ($before !== $expectedPresence) {
            throw new \RuntimeException(
                "wprism: $context table topology changed while establishing its schema snapshot"
            );
        }
        $result = $read($before);
        $after = self::database_table_presence($tables, $context . ' topology postimage');
        if ($after !== $before) {
            throw new \RuntimeException(
                "wprism: $context table topology changed during its schema snapshot"
            );
        }
        return $result;
    }

    /**
     * Contain one byte-frozen pre-SDK provider until it migrates to an
     * engine-owned named mutex. The SQL shape alone is never authority: the
     * exact loader-bound object and engine-selected capability must also match
     * the shared legacy debt registry. Other checked getters have no exception.
     */
    private static function permits_legacy_named_mutex_statement(string $sql): bool {
        if (preg_match(
            "/\\ASELECT (?:GET_LOCK\\('wprism:woocommerce:scheduler:[a-f0-9]{32}', 0\\)"
                . "|RELEASE_LOCK\\('wprism:woocommerce:scheduler:[a-f0-9]{32}'\\))\\z/",
            $sql
        ) !== 1
            || !class_exists(Providers::class, false)
            || !method_exists(Providers::class, 'active_manifest_provider_identity')) {
            return false;
        }
        $identity = Providers::active_manifest_provider_identity();
        return is_array($identity)
            && LegacyRuntimeExecutionDebt::permits_provider(
                $identity,
                LegacyRuntimeExecutionDebt::PROVIDER_MYSQL_NAMED_MUTEX
            );
    }

    /** @param list<string> $surfaces @return list<string> */
    private static function physical_tables_for_surfaces(array $surfaces, string $context): array {
        $wpdb = $GLOBALS['wpdb'] ?? null;
        $prefix = is_object($wpdb) ? ($wpdb->prefix ?? null) : null;
        $options = is_object($wpdb) ? ($wpdb->options ?? null) : null;
        if (!is_object($wpdb)
            || !is_string($prefix)
            || preg_match('/^[A-Za-z0-9_]{0,48}$/D', $prefix) !== 1
            || !is_string($options)) {
            throw new \RuntimeException("wprism: $context requires canonical wpdb tables");
        }

        $tables = [];
        foreach ($surfaces as $surface) {
            if (str_starts_with($surface, 'option:')) {
                $table = $options;
            } elseif (str_starts_with($surface, 'table:')) {
                $logical = substr($surface, strlen('table:'));
                $registered = $wpdb->{$logical} ?? null;
                $table = is_string($registered) ? $registered : $prefix . $logical;
            } else {
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
                throw new \RuntimeException(
                    "wprism: $context resolved an unsafe physical table from its manifest-provider contract"
                );
            }
            $tables[] = $table;
        }
        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);
        return $tables;
    }
}
