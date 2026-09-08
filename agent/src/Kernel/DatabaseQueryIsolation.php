<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/DatabaseExceptions.php';
require_once __DIR__ . '/DatabaseTransportBoundary.php';
require_once __DIR__ . '/NativeDatabaseProfile.php';
require_once __DIR__ . '/DatabaseWorkAuthority.php';

/**
 * Transaction-local hook object used in place of WordPress's final WP_Hook.
 *
 * WordPress's public add/remove/has/apply functions duck-call the object in
 * `$wp_filter`. Keeping that surface here closes the add_filter()+immediate
 * query race that a fresh permissive WP_Hook would leave open. The query gate
 * exposes only wpdb's own placeholder-unescape callback; the `all` gate is a
 * no-op. Any attempted mutation poisons the enclosing database boundary.
 *
 * Iterator and ArrayAccess preserve the observable read surface used by core
 * diagnostics. Writes through ArrayAccess remain topology mutations and are
 * refused.
 */
final class DatabaseHookGate implements \Iterator, \ArrayAccess {
    /** @var array<int,array<string,array{function:array{0:object,1:string},accepted_args:int}>> */
    public array $callbacks;

    private int $iteratorPosition = 0;

    public function __construct(
        private readonly string $hookName,
        private readonly object $database
    ) {
        if ($hookName !== 'all' && $hookName !== 'query') {
            throw new \InvalidArgumentException('unsupported database hook gate');
        }
        $this->reset();
    }

    public function hook_name(): string {
        return $this->hookName;
    }

    public function reset(): void {
        $this->callbacks = $this->expected_callbacks();
        $this->iteratorPosition = 0;
    }

    public function intact(): bool {
        return $this->callbacks === $this->expected_callbacks();
    }

    public function add_filter($hookName, $callback, $priority, $acceptedArgs): void {
        if ($this->hookName === 'query'
            && $hookName === 'query'
            && $this->is_placeholder_callback($callback)
            && (int) $priority === 0
            && (int) $acceptedArgs === 1) {
            return;
        }
        DatabaseQueryIsolation::violation(
            "WordPress attempted to install a {$this->hookName} callback inside the authored transaction"
        );
    }

    public function remove_filter($hookName, $callback, $priority): bool {
        DatabaseQueryIsolation::violation(
            "WordPress attempted to remove a {$this->hookName} callback inside the authored transaction"
        );
    }

    public function has_filter($hookName = '', $callback = false): bool|int {
        DatabaseQueryIsolation::assert_gate_use($this, $this->hookName . ' hook inspection');
        if ($callback === false) {
            return $this->hookName === 'query';
        }
        return $this->hookName === 'query' && $this->is_placeholder_callback($callback)
            ? 0
            : false;
    }

    public function has_filters(): bool {
        DatabaseQueryIsolation::assert_gate_use($this, $this->hookName . ' hook inspection');
        return $this->hookName === 'query';
    }

    public function remove_all_filters($priority = false): void {
        DatabaseQueryIsolation::violation(
            "WordPress attempted to clear {$this->hookName} callbacks inside the authored transaction"
        );
    }

    public function apply_filters($value, $args): mixed {
        DatabaseQueryIsolation::assert_gate_use($this, $this->hookName . ' hook dispatch');
        if ($this->hookName !== 'query') {
            return $value;
        }
        if (!is_string($value) || !method_exists($this->database, 'remove_placeholder_escape')) {
            DatabaseQueryIsolation::violation(
                'wpdb placeholder removal is unavailable inside the authored transaction'
            );
        }
        DatabaseQueryIsolation::authorize_query($value);
        $clean = $this->database->remove_placeholder_escape($value);
        if (!is_string($clean)) {
            DatabaseQueryIsolation::violation(
                'wpdb placeholder removal returned malformed SQL inside the authored transaction'
            );
        }
        return $clean;
    }

    public function do_action($args): void {
        DatabaseQueryIsolation::violation(
            "WordPress attempted action dispatch through the {$this->hookName} database hook gate"
        );
    }

    public function do_all_hook(&$args): void {
        DatabaseQueryIsolation::assert_gate_use($this, $this->hookName . ' catch-all dispatch');
        if ($this->hookName !== 'all') {
            DatabaseQueryIsolation::violation(
                'WordPress attempted catch-all dispatch through the query database hook gate'
            );
        }
    }

    public function current_priority(): int|false {
        DatabaseQueryIsolation::assert_gate_use($this, $this->hookName . ' priority inspection');
        return false;
    }

    public function current(): mixed {
        return array_values($this->callbacks)[$this->iteratorPosition] ?? false;
    }

    public function next(): void {
        $this->iteratorPosition++;
    }

    public function key(): mixed {
        return array_keys($this->callbacks)[$this->iteratorPosition] ?? null;
    }

    public function valid(): bool {
        return array_key_exists($this->iteratorPosition, array_values($this->callbacks));
    }

    public function rewind(): void {
        $this->iteratorPosition = 0;
    }

    public function offsetExists(mixed $offset): bool {
        return isset($this->callbacks[$offset]);
    }

    public function &offsetGet(mixed $offset): mixed {
        if (!array_key_exists($offset, $this->callbacks)) {
            static $missing = null;
            return $missing;
        }
        return $this->callbacks[$offset];
    }

    public function offsetSet(mixed $offset, mixed $value): void {
        DatabaseQueryIsolation::violation(
            "WordPress attempted to mutate {$this->hookName} callbacks inside the authored transaction"
        );
    }

    public function offsetUnset(mixed $offset): void {
        DatabaseQueryIsolation::violation(
            "WordPress attempted to mutate {$this->hookName} callbacks inside the authored transaction"
        );
    }

    /** @return array<int,array<string,array{function:array{0:object,1:string},accepted_args:int}>> */
    private function expected_callbacks(): array {
        if ($this->hookName === 'all') {
            return [];
        }
        return [
            0 => [
                spl_object_hash($this->database) . 'remove_placeholder_escape' => [
                    'function' => [$this->database, 'remove_placeholder_escape'],
                    'accepted_args' => 1,
                ],
            ],
        ];
    }

    private function is_placeholder_callback(mixed $callback): bool {
        return is_array($callback)
            && array_keys($callback) === [0, 1]
            && $callback[0] === $this->database
            && $callback[1] === 'remove_placeholder_escape';
    }
}

/**
 * Hold WordPress's mutable database hook surfaces outside an authored SQL
 * transaction.
 *
 * wpdb applies `all` and `query` immediately before server I/O. Either can
 * rewrite a continuity proof or issue COMMIT between Db's preflight and its
 * mutation. Exact original objects are retained and restored only after the
 * physical transaction is positively terminal. PHP requests are
 * single-threaded, so an engine-owned gate closes public hook mutation for the
 * short CLI-owned boundary without altering ordinary request topology.
 */
final class DatabaseQueryIsolation {
    /** Provider callbacks are bounded independently of engine control SQL. */
    private const PROFILE_STATEMENT_LIMIT = 1024;
    private const PROFILE_SQL_BYTE_LIMIT = 16777216;
    private const PROFILE_STATEMENT_BYTE_LIMIT = 1048576;
    // SQL cannot carry NUL in an identifier. Retaining this internal prefix
    // keeps a quoted routine name distinct from the same unquoted built-in;
    // table_token() is the only path that intentionally unwraps it.
    private const QUOTED_IDENTIFIER_TOKEN_PREFIX = "\0";

    private static bool $active = false;
    private static bool $poisoned = false;
    private static bool $cleanupAttempt = false;
    private static ?string $permittedQuery = null;
    private static ?string $permittedContext = null;
    private static ?NativeDatabaseProfile $profile = null;
    private static int $profileStatements = 0;
    private static int $profileSqlBytes = 0;
    private static ?DatabaseWorkAuthority $workAuthority = null;
    private static ?object $engineWorkScope = null;
    private static ?object $workUnit = null;
    /** @var array<string,array{present:bool,value:mixed}> */
    private static array $hooks = [];
    /** @var array{all:DatabaseHookGate,query:DatabaseHookGate}|array{} */
    private static array $gates = [];
    /** @var array{present:bool,value:mixed}|array{} */
    private static array $currentFilterStack = [];

    public static function is_active(): bool {
        return self::$active;
    }

    public static function has_bound_profile(): bool {
        return self::$active && self::$profile !== null;
    }

    /** Whether the current isolated query boundary proves a read-only profile. */
    public static function bound_profile_is_read_only(): bool {
        return self::has_bound_profile() && self::$profile->is_read_only();
    }

    /**
     * Admit independently bounded engine work without splitting its snapshot.
     *
     * A capture combines a finite discovered entity/option roster; charging
     * that entire roster as one native callback refused the 1,025th statement
     * while publishing an ordinary 11-post/26-term candidate. Table authority,
     * the SQL grammar, and the transaction lifetime still cover every unit.
     * SQL outside explicit units keeps the original finite aggregate budget.
     * Native provider sessions never enter this engine orchestration scope.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function with_engine_work_units(DatabaseWorkAuthority $authority, callable $work): mixed {
        self::assert_active('engine database work');
        if (self::$profile === null || self::$workAuthority !== $authority
            || self::$engineWorkScope !== null || self::$workUnit !== null) {
            self::violation('wprism: engine database work requires one newly bound transaction profile');
        }
        $scope = new \stdClass();
        $profile = self::$profile;
        self::$engineWorkScope = $scope;
        try {
            $result = $work();
            self::assert_active('engine database work completion');
            if (self::$engineWorkScope !== $scope || self::$profile !== $profile || self::$workUnit !== null) {
                self::violation('wprism: engine database work changed its bound transaction profile');
            }
            return $result;
        } finally {
            if (self::$engineWorkScope === $scope) {
                self::$engineWorkScope = null;
            }
        }
    }

    /**
     * Charge one complete semantic work item, including its native callbacks.
     *
     * Only the outermost item gets an independent budget. A native filter
     * reentering an engine helper, or a provider calling the same helper in
     * its own snapshot, therefore cannot refresh its enclosing quota. Owners
     * call this around complete items from their already-bounded rosters, not
     * around individual SQL statements or arbitrary portions of a callback.
     *
     * @template T
     * @param callable():T $work
     * @return T
     */
    public static function work_unit(?DatabaseWorkAuthority $authority, callable $work): mixed {
        if ($authority === null) {
            return $work();
        }
        self::assert_active('engine database work item authority');
        if (self::$workAuthority !== $authority) {
            self::violation('wprism: engine database work item has no authority for its bound profile');
        }
        if (self::$engineWorkScope === null || self::$workUnit !== null) {
            return $work();
        }
        self::assert_active('engine database work item');
        $scope = self::$engineWorkScope;
        $profile = self::$profile;
        $unit = new \stdClass();
        $statements = self::$profileStatements;
        $bytes = self::$profileSqlBytes;
        self::$workUnit = $unit;
        self::$profileStatements = 0;
        self::$profileSqlBytes = 0;
        try {
            $result = $work();
            self::assert_active('engine database work item completion');
            if (self::$engineWorkScope !== $scope || self::$profile !== $profile || self::$workUnit !== $unit) {
                self::violation('wprism: engine database work item changed its bound transaction profile');
            }
            return $result;
        } finally {
            // A lost/replaced transaction cannot inherit the prior counters.
            // A poisoned *same* profile still restores only its enclosing
            // accounting; poisoning and the exact rollback permit remain.
            if (self::$engineWorkScope === $scope && self::$profile === $profile && self::$workUnit === $unit) {
                self::$workUnit = null;
                self::$profileStatements = $statements;
                self::$profileSqlBytes = $bytes;
            }
        }
    }

    /**
     * Refuse mutation and side-effect transports before a checked wpdb read.
     *
     * This assertion is deliberately independent of an active transaction or
     * table profile. Several identity-pinned legacy providers use the checked
     * read helpers outside the newer snapshot API; they still receive the same
     * closed SQL lexer and callable roster, so runtime-assembled DML cannot
     * turn get_var/get_col/get_row/get_results into an untyped write channel.
     */
    public static function assert_provider_read_statement(string $sql): void {
        $bytes = strlen($sql);
        if ($bytes < 1 || $bytes > self::PROFILE_STATEMENT_BYTE_LIMIT) {
            self::violation('wprism: provider checked read exceeds its fixed SQL-byte boundary');
        }
        $structure = self::query_structure($sql);
        if (preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE|@)/i', $structure) === 1
            || str_contains($structure, ':=')) {
            self::violation(
                'wprism: provider checked read requires one non-mutating database statement'
            );
        }
        $lexed = self::sql_tokens($sql);
        $tokens = $lexed['tokens'];
        if ($tokens === []) {
            self::violation('wprism: provider checked read requires one non-empty database statement');
        }
        $verb = self::statement_verb($tokens, true);
        self::assert_closed_profile_grammar(
            $tokens,
            $verb,
            $lexed['call_adjacency']
        );
        if ($verb === 'SHOW'
            && in_array(strtoupper($tokens[1] ?? ''), ['TABLES', 'TABLE'], true)
            && self::table_presence_identifier($sql) === null) {
            self::violation('wprism: provider checked table-presence read requires exact LIKE evidence');
        }
    }

    /**
     * Reuse the proof Db established before returning from start*(). Feature
     * owners may still assert their narrower semantic roster, but must not
     * issue a second metadata census after the product query profile is live.
     *
     * @param list<string> $tables
     */
    public static function assert_profile_contains(
        array $tables,
        bool $write,
        string $context
    ): void {
        self::assert_active($context . ' database profile');
        if (self::$profile === null) {
            self::violation("wprism: $context has no bound native database profile");
        }
        $allowed = array_fill_keys(
            $write ? self::$profile->write_tables() : self::$profile->readable_tables(),
            true
        );
        foreach ($tables as $table) {
            if (!is_string($table)
                || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1
                || !isset($allowed[$table])) {
                self::violation(
                    "wprism: $context escaped the tables proven by its native database profile"
                );
            }
        }
    }

    public static function begin(string $context): void {
        if (self::$active) {
            throw new \RuntimeException("wprism: $context found an already-isolated database query boundary");
        }
        $currentFilterPresent = array_key_exists('wp_current_filter', $GLOBALS);
        $currentFilterValue = $currentFilterPresent ? $GLOBALS['wp_current_filter'] : null;
        $current = is_array($currentFilterValue) ? $currentFilterValue : [];
        if (in_array('query', $current, true)) {
            throw new \RuntimeException(
                "wprism: $context cannot start from inside WordPress's database query filter"
            );
        }
        $filters = $GLOBALS['wp_filter'] ?? null;
        if ($filters !== null && !is_array($filters)) {
            throw new \RuntimeException("wprism: $context found malformed WordPress hook storage");
        }
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'remove_placeholder_escape')) {
            throw new \RuntimeException(
                "wprism: $context requires wpdb placeholder-safe query transport"
            );
        }
        DatabaseTransportBoundary::begin($context . ' transport boundary');
        self::$currentFilterStack = [
            'present' => $currentFilterPresent,
            'value' => $currentFilterValue,
        ];

        foreach (['all', 'query'] as $name) {
            $present = is_array($filters) && array_key_exists($name, $filters);
            self::$hooks[$name] = [
                'present' => $present,
                'value' => $present ? $filters[$name] : null,
            ];
        }
        self::$gates = [
            'all' => new DatabaseHookGate('all', $wpdb),
            'query' => new DatabaseHookGate('query', $wpdb),
        ];
        if (!isset($GLOBALS['wp_filter']) || !is_array($GLOBALS['wp_filter'])) {
            $GLOBALS['wp_filter'] = [];
        }
        $GLOBALS['wp_filter']['all'] = self::$gates['all'];
        $GLOBALS['wp_filter']['query'] = self::$gates['query'];
        self::$poisoned = false;
        self::$cleanupAttempt = false;
        self::$permittedQuery = null;
        self::$permittedContext = null;
        self::$profile = null;
        self::$profileStatements = 0;
        self::$profileSqlBytes = 0;
        self::$workAuthority = null;
        self::$engineWorkScope = null;
        self::$workUnit = null;
        self::$active = true;
    }

    public static function bind_profile(NativeDatabaseProfile $profile, string $context): DatabaseWorkAuthority {
        self::assert_active($context . ' query profile');
        if (self::$cleanupAttempt || self::$profile !== null || self::$permittedQuery !== null) {
            self::violation("wprism: $context could not bind one isolated native database profile");
        }
        self::$profile = $profile;
        self::$profileStatements = 0;
        self::$profileSqlBytes = 0;
        self::$workAuthority = new DatabaseWorkAuthority();
        return self::$workAuthority;
    }

    /**
     * Prove the string-literal rules used by the closed SQL lexer.
     *
     * The provider cannot issue SET through the profiled gate. Establishing
     * these premises immediately before its callback therefore makes
     * backslash, quote and byte-boundary handling deterministic for every
     * admitted statement.
     */
    public static function assert_profile_sql_mode(string $context): void {
        self::assert_active($context . ' SQL-mode premise');
        if (self::$profile !== null || self::$permittedQuery !== null || self::$cleanupAttempt) {
            self::violation('wprism: native database SQL-mode premise crossed an invalid boundary state');
        }

        try {
            $session = DatabaseTransportBoundary::bind_session_state(
                $context . ' session-state premise'
            );
        } catch (\Throwable) {
            self::violation('wprism: native database session-state premise could not be bound');
        }
        $mode = $session['sql_mode'];

        $tokens = $mode === '' ? [] : explode(',', strtoupper($mode));
        foreach ($tokens as $token) {
            if ($token === '' || trim($token) !== $token
                || preg_match('/^[A-Z0-9_]+$/D', $token) !== 1) {
                self::violation('wprism: native database SQL-mode premise returned malformed evidence');
            }
        }
        if (array_intersect($tokens, [
            'ANSI',
            'ANSI_QUOTES',
            'DB2',
            'IGNORE_SPACE',
            'MAXDB',
            'MSSQL',
            'NO_BACKSLASH_ESCAPES',
            'ORACLE',
            'POSTGRESQL',
        ]) !== []) {
            self::violation('wprism: native database SQL-mode premise is incompatible with the closed SQL grammar');
        }

        // The lexer is byte-oriented and treats 0x5c as an escape byte.
        // Server-reported single-byte sets are safe by construction, as are
        // the exact UTF-8 families whose continuation bytes cannot be 0x5c.
        // Refuse every other multibyte encoding: 0x5c may instead be a trail
        // byte, making PHP and MariaDB/MySQL end a quoted token differently.
        $characterSet = $session['character_set_client'];
        $maxBytes = $session['character_set_client_max_bytes'];
        $utf8Widths = ['utf8' => 3, 'utf8mb3' => 3, 'utf8mb4' => 4];
        if ($maxBytes !== 1 && ($utf8Widths[$characterSet] ?? null) !== $maxBytes) {
            self::violation(
                'wprism: native database character-set premise is incompatible with the closed SQL grammar'
            );
        }
    }

    /**
     * Authorize one exact engine control statement.
     *
     * The permit is consumed by WordPress's `query` filter before `_do_query`.
     * A reconnect calls query() again and therefore cannot replay the control;
     * a custom transport that skips the filter leaves the permit unconsumed and
     * is refused by assert_permit_consumed().
     */
    public static function permit_once(string $sql, string $context): void {
        self::assert_active($context . ' query-filter permit');
        if (self::$permittedQuery !== null) {
            self::violation(
                "wprism: $context found an unconsumed database control permit"
            );
        }
        self::$permittedQuery = $sql;
        self::$permittedContext = $context;
    }

    public static function assert_permit_consumed(string $context): void {
        self::assert_active($context . ' query-filter permit proof');
        if (self::$permittedQuery !== null) {
            $pending = self::$permittedContext ?? 'unknown database control';
            self::violation(
                "wprism: $context did not consume the exact query-filter permit for $pending"
            );
        }
    }

    /** Called only by the installed query gate. */
    public static function authorize_query(string $sql): void {
        self::assert_active('database query authorization');
        if ($sql !== 'SHOW WARNINGS') {
            try {
                DatabaseTransportBoundary::assert_session_intact(
                    'database query authorization transport boundary'
                );
            } catch (\Throwable) {
                self::violation(
                    'wprism: database session state changed inside the authored transaction'
                );
            }
        }
        if (self::$permittedQuery !== null) {
            if (!hash_equals(self::$permittedQuery, $sql)) {
                $pending = self::$permittedContext ?? 'unknown database control';
                self::violation(
                    "wprism: a database query crossed the pending exact permit for $pending"
                );
            }
            self::$permittedQuery = null;
            self::$permittedContext = null;
            return;
        }

        if (self::$cleanupAttempt) {
            self::violation(
                'wprism: an ordinary database query crossed a cleanup-only transaction boundary'
            );
        }

        if (self::$profile !== null) {
            self::charge_profile_query($sql);
        }
        $structure = self::query_structure($sql);
        $verb = '';
        if (preg_match(
            '/^(SELECT|SHOW|DESCRIBE|DESC|EXPLAIN|INSERT|UPDATE|DELETE|REPLACE)\b/i',
            $structure,
            $head
        ) === 1) {
            $verb = strtoupper($head[1]);
        } elseif (str_starts_with($structure, '(')) {
            $verb = self::statement_verb(self::sql_tokens($sql)['tokens'], false);
        }
        if (!in_array($verb, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN', 'INSERT', 'UPDATE', 'DELETE', 'REPLACE'], true)
            || preg_match('/\bINTO\s+(?:OUTFILE|DUMPFILE)\b/i', $structure) === 1
            || preg_match('/\b(?:LOAD_FILE|GET_LOCK|RELEASE_LOCK|SLEEP|BENCHMARK)\s*\(/i', $structure) === 1
            || str_contains($structure, ':=')
            || preg_match('/\bINTO\s+@/i', $structure) === 1) {
            self::violation(
                'wprism: a non-read/DML statement crossed the authored database boundary without an engine permit'
            );
        }
        if (self::$profile !== null) {
            self::assert_profiled_query($sql, $structure, self::$profile);
        }
    }

    public static function assert_active(string $context): void {
        if (!self::$active) {
            throw new \RuntimeException("wprism: $context lost database query-filter isolation");
        }
        $filters = $GLOBALS['wp_filter'] ?? null;
        $intact = is_array($filters);
        foreach (['all', 'query'] as $name) {
            $intact = $intact
                && isset(self::$gates[$name])
                && array_key_exists($name, $filters)
                && $filters[$name] === self::$gates[$name]
                && self::$gates[$name]->intact();
        }
        if (!$intact) {
            self::violation(
                "wprism: $context found database hook topology replaced inside the authored transaction"
            );
        }
        try {
            DatabaseTransportBoundary::assert_intact($context . ' transport boundary');
        } catch (\Throwable) {
            self::violation(
                "wprism: $context found the database transport changed inside the authored transaction"
            );
        }
        if (self::$poisoned && !self::$cleanupAttempt) {
            throw new DatabaseQueryIsolationViolationException(
                "wprism: $context reached a poisoned database hook boundary"
            );
        }
    }

    /**
     * Native-input scopes must prove the preimage, not the no-op gate that
     * begin() installs at `all`. Even an empty pre-existing entry refuses:
     * no mutable saved hook object or callback exception crosses this seam.
     */
    public static function assert_original_all_hook_absent(string $context): void {
        self::assert_active($context);
        if ((self::$hooks['all']['present'] ?? true) !== false) {
            self::violation("wprism: $context found a pre-existing WordPress catch-all hook");
        }
    }

    /** Used by the transaction-local gate on every WordPress dispatch. */
    public static function assert_gate_use(DatabaseHookGate $gate, string $context): void {
        self::assert_active($context);
        if (!isset(self::$gates[$gate->hook_name()])
            || self::$gates[$gate->hook_name()] !== $gate) {
            self::violation("wprism: $context reached a foreign database hook gate");
        }
    }

    /** Quarantine unexpected topology and make product continuation impossible. */
    public static function violation(string $message): never {
        self::poison();
        throw new DatabaseQueryIsolationViolationException($message);
    }

    /** A caught multi-statement failure must not authorize a partial commit. */
    public static function poison(): void {
        if (self::$active) {
            self::$poisoned = true;
            self::$cleanupAttempt = false;
            self::$permittedQuery = null;
            self::$permittedContext = null;
            self::install_clean_gates();
        }
    }

    /** Permit one synchronous state-proof/ROLLBACK attempt after poisoning. */
    public static function prepare_cleanup(string $context): void {
        if (!self::$active || !self::$poisoned) {
            throw new DatabaseTransactionOutcomeException(
                $context . ' has no poisoned database hook boundary to clean up'
            );
        }
        self::install_clean_gates();
        DatabaseTransportBoundary::prepare_cleanup($context . ' transport boundary');
        self::$cleanupAttempt = true;
    }

    /** Keep the boundary poisoned when the synchronous cleanup did not settle. */
    public static function cleanup_failed(): void {
        if (!self::$active) {
            return;
        }
        self::$cleanupAttempt = false;
        self::$poisoned = true;
        self::$permittedQuery = null;
        self::$permittedContext = null;
        self::install_clean_gates();
    }

    /** Restore the exact pre-transaction hook topology after positive settlement. */
    public static function finish(): void {
        if (!self::$active) {
            return;
        }
        DatabaseTransportBoundary::finish('database query isolation settlement');
        // WordPress pops wp_current_filter only after a hook returns normally.
        // A gate refusal escapes before that pop, so the engine boundary must
        // restore the exact outer stack after its database transport settles.
        if ((self::$currentFilterStack['present'] ?? false) === true) {
            $GLOBALS['wp_current_filter'] = self::$currentFilterStack['value'];
        } else {
            unset($GLOBALS['wp_current_filter']);
        }
        foreach (self::$hooks as $name => $entry) {
            if ($entry['present']) {
                if (!isset($GLOBALS['wp_filter']) || !is_array($GLOBALS['wp_filter'])) {
                    $GLOBALS['wp_filter'] = [];
                }
                $GLOBALS['wp_filter'][$name] = $entry['value'];
            } elseif (isset($GLOBALS['wp_filter']) && is_array($GLOBALS['wp_filter'])) {
                unset($GLOBALS['wp_filter'][$name]);
            }
        }
        self::$active = false;
        self::$poisoned = false;
        self::$cleanupAttempt = false;
        self::$permittedQuery = null;
        self::$permittedContext = null;
        self::$profile = null;
        self::$profileStatements = 0;
        self::$profileSqlBytes = 0;
        self::$workAuthority = null;
        self::$engineWorkScope = null;
        self::$workUnit = null;
        self::$hooks = [];
        self::$gates = [];
        self::$currentFilterStack = [];
    }

    private static function install_clean_gates(): void {
        if (!isset($GLOBALS['wp_filter']) || !is_array($GLOBALS['wp_filter'])) {
            $GLOBALS['wp_filter'] = [];
        }
        foreach (self::$gates as $name => $gate) {
            $gate->reset();
            $GLOBALS['wp_filter'][$name] = $gate;
        }
    }

    /** Charge the complete rendered statement before wpdb reaches transport. */
    private static function charge_profile_query(string $sql): void {
        $bytes = strlen($sql);
        if ($bytes < 1 || $bytes > self::PROFILE_STATEMENT_BYTE_LIMIT) {
            self::violation('wprism: native database SQL exceeds its fixed one-megabyte boundary');
        }
        if (self::$profileStatements >= self::PROFILE_STATEMENT_LIMIT) {
            self::violation('wprism: native database callback exceeded its statement-count boundary');
        }
        if (self::$profileSqlBytes > self::PROFILE_SQL_BYTE_LIMIT - $bytes) {
            self::violation('wprism: native database callback exceeded its cumulative SQL-byte boundary');
        }
        self::$profileStatements++;
        self::$profileSqlBytes += $bytes;
    }

    /** Mask values while refusing comments and multi-statement boundaries. */
    private static function query_structure(string $sql): string {
        $masked = '';
        $length = strlen($sql);
        for ($index = 0; $index < $length; $index++) {
            $byte = $sql[$index];
            $ord = ord($byte);
            if (($ord < 32 && !in_array($byte, ["\t", "\n", "\r"], true)) || $ord === 127) {
                self::violation('wprism: database SQL contains a control byte');
            }
            if ($byte === ';' || $byte === '#'
                || ($byte === '-' && ($sql[$index + 1] ?? '') === '-')
                || ($byte === '/' && ($sql[$index + 1] ?? '') === '*')) {
                self::violation('wprism: database SQL contains a comment or statement delimiter');
            }
            if ($byte !== "'" && $byte !== '"' && $byte !== '`') {
                $masked .= $byte;
                continue;
            }
            $quote = $byte;
            $masked .= $quote === '`' ? '``' : "''";
            $closed = false;
            for ($index++; $index < $length; $index++) {
                if ($sql[$index] === '\\' && $quote !== '`') {
                    if ($index + 1 >= $length) {
                        break;
                    }
                    $index++;
                    continue;
                }
                if ($sql[$index] !== $quote) {
                    continue;
                }
                if (($sql[$index + 1] ?? '') === $quote) {
                    $index++;
                    continue;
                }
                $closed = true;
                break;
            }
            if (!$closed) {
                self::violation('wprism: database SQL contains an unterminated quoted token');
            }
        }
        $normalized = preg_replace('/\s+/', ' ', trim($masked));
        return is_string($normalized) ? $normalized : trim($masked);
    }

    private static function assert_profiled_query(
        string $sql,
        string $structure,
        NativeDatabaseProfile $profile
    ): void {
        try {
            self::assert_profiled_query_access($sql, $structure, $profile);
        } catch (DatabaseQueryIsolationViolationException $failure) {
            // A native co-install read can exceed the capsule's declared
            // profile. Keep the exact rejected query and authority private:
            // the typed refusal, poison state and public sentence stay intact,
            // and the existing recorder owns byte bounds and truncation.
            $failure->retain_private_evidence(
                new \RuntimeException($sql),
                new \RuntimeException('wprism: native database profile: ' . serialize([
                    'readable_tables' => $profile->readable_tables(),
                    'write_tables' => $profile->write_tables(),
                    'table_presence_reads' => $profile->table_presence_reads(),
                ]))
            );
            throw $failure;
        }
    }

    private static function assert_profiled_query_access(
        string $sql,
        string $structure,
        NativeDatabaseProfile $profile
    ): void {
        $presenceTable = self::table_presence_identifier($sql);
        if ($presenceTable !== null) {
            $allowed = array_fill_keys(array_merge(
                $profile->readable_tables(),
                $profile->table_presence_reads()
            ), true);
            if (!isset($allowed[$presenceTable])) {
                self::violation(
                    'wprism: a table-presence read escaped its declared physical-table profile'
                );
            }
            return;
        }
        // A native SELECT * preflight needs column names in physical order,
        // without transferring unbounded defaults/comments through SHOW FULL.
        // This exact bounded projection borrows only the named table's read
        // authority; arbitrary schema-qualified SQL remains outside grammar.
        if (preg_match('/^SELECT COLUMN_NAME FROM information_schema\.COLUMNS '
            . "WHERE TABLE_SCHEMA = DATABASE\\(\\) AND BINARY TABLE_NAME = BINARY '([A-Za-z0-9_]{1,64})' "
            . 'ORDER BY ORDINAL_POSITION LIMIT ([1-9][0-9]{0,2})$/D', $sql, $columns) === 1
            && (int) $columns[2] <= 128) {
            $access = ['reads' => [$columns[1]], 'writes' => []];
        } else {
            $access = self::profiled_query_tables($sql, $structure);
        }
        $readable = array_fill_keys($profile->readable_tables(), true);
        $writable = array_fill_keys($profile->write_tables(), true);
        foreach ($access['reads'] as $table) {
            if (!isset($readable[$table])) {
                self::violation(
                    'wprism: a native database read escaped its declared physical-table profile'
                );
            }
        }
        foreach ($access['writes'] as $table) {
            if (!isset($writable[$table])) {
                self::violation(
                    'wprism: a native database mutation escaped its declared physical-table profile'
                );
            }
        }
    }

    /** @return array{reads:list<string>,writes:list<string>} */
    private static function profiled_query_tables(string $sql, string $structure): array {
        $lexed = self::sql_tokens($sql);
        $tokens = $lexed['tokens'];
        if ($tokens === []) {
            self::violation('wprism: an empty query crossed a native database profile');
        }
        $verb = self::statement_verb($tokens, false);
        self::assert_closed_profile_grammar($tokens, $verb, $lexed['call_adjacency']);
        $reads = [];
        $writes = [];
        $mutationTable = self::mutation_target_table($tokens, $verb);
        if ($mutationTable !== null) {
            $writes[] = $mutationTable;
        } elseif ($verb === 'SHOW') {
            // Only reviewed table-metadata forms reach this branch. Exact
            // LIKE presence probes returned above; treating arbitrary SHOW
            // ... FROM operands as tables would confuse database names with
            // physical-table authority.
            return ['reads' => [self::profiled_show_table($tokens)], 'writes' => []];
        } elseif ($verb === 'DESCRIBE' || $verb === 'DESC') {
            $reads[] = self::table_token($tokens, 1);
        } elseif ($verb !== 'SELECT' && $verb !== 'EXPLAIN') {
            self::violation('wprism: a statement is outside the native database profile grammar');
        }

        foreach ($tokens as $offset => $token) {
            $keyword = strtoupper($token);
            if ($keyword !== 'FROM' && $keyword !== 'JOIN') {
                continue;
            }
            $next = $tokens[$offset + 1] ?? null;
            if ($next === '(') {
                // MariaDB admits both derived SELECTs and parenthesized table
                // references here. sql_tokens() intentionally normalizes
                // quoted identifiers, so `SELECT` can be a real table name
                // indistinguishable from the derived-query keyword at this
                // layer. No shipped provider needs either form; refuse the
                // whole ambiguous grammar instead of losing its first source.
                self::violation(
                    'wprism: a parenthesized table reference is outside the native database profile grammar'
                );
            }
            $reads[] = self::table_token($tokens, $offset + 1);
        }
        $reads = array_values(array_unique(array_diff($reads, $writes)));
        $writes = array_values(array_unique($writes));
        sort($reads, SORT_STRING);
        sort($writes, SORT_STRING);
        return ['reads' => $reads, 'writes' => $writes];
    }

    /**
     * Recognize the exact physical-name probes admitted by a presence scope.
     * ProviderSdk's legacy topology snapshot uses
     * wpdb::esc_like(), whose `%s` rendering carries each underscore as the
     * three SQL source bytes `\\_`. Raw underscore is a LIKE wildcard, not
     * exact physical-table evidence, and therefore has no presence authority.
     */
    private static function table_presence_identifier(string $sql): ?string {
        $trimmed = trim($sql);
        foreach ([
            '/^SELECT\\s+1\\s+FROM\\s+`([A-Za-z0-9_]{1,64})`\\s+LIMIT\\s+0\\s*$/Di',
            '/^SHOW\\s+CREATE\\s+TABLE\\s+`([A-Za-z0-9_]{1,64})`\\s*$/Di',
        ] as $pattern) {
            if (preg_match($pattern, $trimmed, $exact) === 1) {
                return $exact[1];
            }
        }
        if (preg_match(
            "/^SHOW\\s+(?:TABLES|TABLE\\s+STATUS)\\s+LIKE\\s+'([^']{1,256})'\\s*$/Di",
            $trimmed,
            $match
        ) !== 1) {
            return null;
        }
        $encoded = $match[1];
        $decoded = '';
        for ($offset = 0, $length = strlen($encoded); $offset < $length;) {
            $byte = $encoded[$offset];
            if (ctype_alnum($byte)) {
                $decoded .= $byte;
                $offset++;
                continue;
            }
            if ($byte === '\\'
                && ($encoded[$offset + 1] ?? null) === '\\'
                && ($encoded[$offset + 2] ?? null) === '_') {
                $decoded .= '_';
                $offset += 3;
                continue;
            }
            return null;
        }
        return preg_match('/^[A-Za-z0-9_]{1,64}$/D', $decoded) === 1 ? $decoded : null;
    }

    /** @param list<string> $tokens */
    private static function mutation_target_table(array $tokens, string $verb): ?string {
        if ($verb === 'INSERT' || $verb === 'REPLACE') {
            $offset = 1;
            while (isset($tokens[$offset])
                && in_array(strtoupper($tokens[$offset]), ['LOW_PRIORITY', 'DELAYED', 'HIGH_PRIORITY', 'IGNORE'], true)) {
                $offset++;
            }
            if (strtoupper($tokens[$offset] ?? '') === 'INTO') {
                $offset++;
            }
            return self::table_token($tokens, $offset);
        }
        if ($verb === 'UPDATE') {
            $offset = 1;
            while (isset($tokens[$offset])
                && in_array(strtoupper($tokens[$offset]), ['LOW_PRIORITY', 'IGNORE'], true)) {
                $offset++;
            }
            $table = self::table_token($tokens, $offset);
            $upperTokens = array_map('strtoupper', $tokens);
            $setOffset = self::keyword_offset($tokens, 'SET');
            $targetTokens = $setOffset === null
                ? array_slice($tokens, $offset + 1)
                : array_slice($tokens, $offset + 1, $setOffset - $offset - 1);
            if (in_array('JOIN', $upperTokens, true)
                || in_array(',', $targetTokens, true)) {
                self::violation('wprism: a multi-table UPDATE is outside the native database profile grammar');
            }
            return $table;
        }
        if ($verb === 'DELETE') {
            $offset = 1;
            while (isset($tokens[$offset])
                && in_array(strtoupper($tokens[$offset]), ['LOW_PRIORITY', 'QUICK', 'IGNORE'], true)) {
                $offset++;
            }
            if (strtoupper($tokens[$offset] ?? '') !== 'FROM') {
                self::violation('wprism: a multi-table DELETE is outside the native database profile grammar');
            }
            if (in_array('USING', array_map('strtoupper', $tokens), true)) {
                self::violation('wprism: a multi-table DELETE is outside the native database profile grammar');
            }
            return self::table_token($tokens, $offset + 1);
        }
        return null;
    }

    /**
     * @param list<string> $tokens
     * @param array<int,bool> $callAdjacency keyed by identifier-token offset
     */
    private static function assert_closed_profile_grammar(
        array $tokens,
        string $verb,
        array $callAdjacency
    ): void {
        $upper = array_map('strtoupper', $tokens);
        $depths = [];
        $depth = 0;
        foreach ($tokens as $offset => $token) {
            $depths[$offset] = $depth;
            if ($token === '(') {
                $depth++;
            } elseif ($token === ')') {
                $depth--;
                if ($depth < 0) {
                    self::violation('wprism: database SQL has unbalanced parentheses');
                }
            }
        }
        if ($depth !== 0) {
            self::violation('wprism: database SQL has unbalanced parentheses');
        }
        if ($verb === 'SHOW') {
            self::assert_closed_show_grammar($tokens);
        }
        foreach ($upper as $offset => $token) {
            if (in_array($token, ['NEXT', 'PREVIOUS'], true)
                && ($upper[$offset + 1] ?? null) === 'VALUE'
                && ($upper[$offset + 2] ?? null) === 'FOR') {
                self::violation(
                    'wprism: a sequence expression is outside the closed native database profile grammar'
                );
            }
        }
        if (in_array('STRAIGHT_JOIN', $upper, true)
            || in_array('NATURAL', $upper, true)
            || in_array('EXCEPT', $upper, true)
            || in_array('INTERSECT', $upper, true)
            || (!in_array($verb, ['INSERT', 'REPLACE', 'SHOW'], true)
                && in_array('TABLE', $upper, true))
            || ($verb === 'EXPLAIN' && in_array('ANALYZE', $upper, true))) {
            self::violation('wprism: a query form is outside the closed native database profile grammar');
        }
        self::assert_closed_callable_grammar($tokens, $callAdjacency);
        if (($verb === 'INSERT' || $verb === 'REPLACE')
            && (in_array('TABLE', $upper, true) || in_array('PARTITION', $upper, true))) {
            self::violation('wprism: an alternate INSERT source is outside the native database profile grammar');
        }
        if ($verb === 'DELETE' && in_array('USING', $upper, true)) {
            self::violation('wprism: a multi-table DELETE is outside the native database profile grammar');
        }

        foreach ($tokens as $offset => $token) {
            if (strtoupper($token) !== 'FROM') {
                continue;
            }
            $fromDepth = $depths[$offset];
            for ($cursor = $offset + 1, $count = count($tokens); $cursor < $count; $cursor++) {
                $cursorDepth = $depths[$cursor];
                if ($cursorDepth < $fromDepth) {
                    break;
                }
                $keyword = strtoupper($tokens[$cursor]);
                if ($cursorDepth === $fromDepth
                    && in_array($keyword, [
                        'WHERE', 'GROUP', 'HAVING', 'ORDER', 'LIMIT', 'UNION', 'FOR', 'LOCK',
                        'RETURNING', 'SET', 'VALUES',
                    ], true)) {
                    break;
                }
                if ($cursorDepth === $fromDepth && $tokens[$cursor] === ',') {
                    self::violation(
                        $verb === 'DELETE'
                            ? 'wprism: a multi-table DELETE is outside the native database profile grammar'
                            : 'wprism: a comma table source is outside the native database profile grammar'
                    );
                }
            }
        }
    }

    /** @param list<string> $tokens */
    private static function assert_closed_show_grammar(array $tokens): void {
        $upper = array_map('strtoupper', $tokens);
        $count = count($tokens);
        $valid = ($count === 4
                && $upper[1] === 'TABLES'
                && $upper[2] === 'LIKE'
                && $tokens[3] === "''")
            || ($count === 5
                && $upper[1] === 'TABLE'
                && $upper[2] === 'STATUS'
                && $upper[3] === 'LIKE'
                && $tokens[4] === "''")
            || ($count === 4
                && $upper[1] === 'CREATE'
                && $upper[2] === 'TABLE'
                && self::is_table_token($tokens, 3))
            || ($count === 4
                && in_array($upper[1], ['COLUMNS', 'INDEX'], true)
                && $upper[2] === 'FROM'
                && self::is_table_token($tokens, 3))
            || ($count === 5
                && $upper[1] === 'FULL'
                && $upper[2] === 'COLUMNS'
                && $upper[3] === 'FROM'
                && self::is_table_token($tokens, 4))
            || ($count === 8
                && $upper[1] === 'KEYS'
                && $upper[2] === 'FROM'
                && self::is_table_token($tokens, 3)
                && $upper[4] === 'WHERE'
                && $upper[5] === 'KEY_NAME'
                && $tokens[6] === '='
                && $tokens[7] === "''");
        if (!$valid) {
            self::violation('wprism: a SHOW form is outside the closed native database profile grammar');
        }
    }

    /** @param list<string> $tokens */
    private static function profiled_show_table(array $tokens): string {
        self::assert_closed_show_grammar($tokens);
        $upper = array_map('strtoupper', $tokens);
        if (($upper[1] ?? '') === 'FULL') {
            return self::table_token($tokens, 4);
        }
        if (in_array($upper[1] ?? '', ['COLUMNS', 'INDEX', 'KEYS'], true)) {
            return self::table_token($tokens, 3);
        }
        if (($upper[1] ?? '') === 'CREATE') {
            return self::table_token($tokens, 3);
        }
        self::violation('wprism: a table-presence SHOW did not carry exact LIKE evidence');
    }

    /** @param list<string> $tokens */
    private static function is_table_token(array $tokens, int $offset): bool {
        $table = $tokens[$offset] ?? null;
        if (is_string($table)
            && str_starts_with($table, self::QUOTED_IDENTIFIER_TOKEN_PREFIX)) {
            $table = substr($table, strlen(self::QUOTED_IDENTIFIER_TOKEN_PREFIX));
        }
        return is_string($table)
            && preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) === 1
            && ($tokens[$offset + 1] ?? null) !== '.';
    }

    /**
     * Return the one structural statement verb admitted by the closed lexer.
     *
     * MySQL/MariaDB query expressions may wrap each SELECT arm in parentheses
     * before UNION. Parentheses are admitted only for SELECT, and every UNION
     * arm is independently required to begin with SELECT after its optional
     * ALL/DISTINCT modifier and wrappers. This is query-expression grammar,
     * not a prefix exception: `(DELETE ...)` and `UNION (WITH ... DELETE ...)`
     * never acquire read authority.
     *
     * @param list<string> $tokens
     */
    private static function statement_verb(array $tokens, bool $readOnly): string {
        if ($tokens === []) {
            self::violation('wprism: an empty query crossed the database boundary');
        }
        $cursor = 0;
        while (($tokens[$cursor] ?? null) === '(') {
            $cursor++;
        }
        $verb = strtoupper($tokens[$cursor] ?? '');
        if ($cursor > 0 && $verb !== 'SELECT') {
            self::violation('wprism: a parenthesized non-SELECT crossed the database boundary');
        }
        if ($readOnly
            && !in_array($verb, ['SELECT', 'SHOW', 'DESCRIBE', 'DESC', 'EXPLAIN'], true)) {
            self::violation(
                'wprism: provider checked read requires one non-mutating database statement'
            );
        }
        if ($verb === 'SELECT') {
            self::assert_select_query_expression($tokens);
        } elseif ($verb === 'EXPLAIN') {
            // EXPLAIN also accepts mutating statements. Only its closed
            // SELECT form is a checked read; otherwise an empty table profile
            // could transport EXPLAIN UPDATE/INSERT as apparent diagnostics.
            if (strtoupper($tokens[$cursor + 1] ?? '') !== 'SELECT') {
                self::violation(
                    'wprism: an EXPLAIN target is outside the closed SELECT query-expression grammar'
                );
            }
            self::assert_select_query_expression(array_slice($tokens, $cursor + 1));
        }
        return $verb;
    }

    /** @param list<string> $tokens */
    private static function assert_select_query_expression(array $tokens): void {
        foreach ($tokens as $offset => $token) {
            if (strtoupper($token) !== 'UNION') {
                continue;
            }
            $cursor = $offset + 1;
            if (in_array(strtoupper($tokens[$cursor] ?? ''), ['ALL', 'DISTINCT'], true)) {
                $cursor++;
            }
            while (($tokens[$cursor] ?? null) === '(') {
                $cursor++;
            }
            if (strtoupper($tokens[$cursor] ?? '') !== 'SELECT') {
                self::violation(
                    'wprism: a UNION arm is outside the closed SELECT query-expression grammar'
                );
            }
        }
    }

    /**
     * Refuse every callable spelling that could resolve to a stored/UDF
     * routine rather than one closed server built-in.
     *
     * @param list<string> $tokens
     * @param array<int,bool> $callAdjacency
     */
    private static function assert_closed_callable_grammar(
        array $tokens,
        array $callAdjacency
    ): void {
        foreach ($callAdjacency as $offset => $adjacent) {
            $token = $tokens[$offset] ?? null;
            if (!is_string($token)) {
                self::violation('wprism: database SQL callable evidence is malformed');
            }
            if (($tokens[$offset - 1] ?? null) === '.') {
                // MySQL and MariaDB parse even reserved grammar words as
                // stored-function identifiers after a schema qualifier.
                self::violation('wprism: an unreviewed SQL function crossed the native database profile');
            }
            $previous = strtoupper($tokens[$offset - 1] ?? '');
            if (in_array($previous, ['INSERT', 'INTO', 'REPLACE', 'UPDATE'], true)) {
                continue;
            }
            $function = strtoupper($token);
            if (in_array($function, ['INDEX', 'KEY'], true)
                && in_array($previous, ['FORCE', 'IGNORE', 'USE'], true)) {
                continue;
            }
            if ($function === 'UNION'
                || (in_array($function, ['ALL', 'DISTINCT'], true) && $previous === 'UNION')) {
                // The following parenthesis opens the next query-expression
                // arm. assert_select_query_expression() already proves that
                // the first structural token inside it is SELECT.
                continue;
            }
            if ($function === 'DECIMAL' && $previous === 'AS') {
                // CAST(value AS DECIMAL(p,s)) uses call-shaped parentheses
                // for its type parameter, not a stored routine dispatch.
                continue;
            }
            // These are grammar words whose following parenthesis starts an
            // expression/subquery group, not a callable surface. Keeping the
            // list closed preserves the stored-function refusal below while
            // admitting the ordinary WHERE (...), JOIN ... ON (...), and
            // aggregate HAVING (...) forms emitted by audited providers.
            if (in_array($function, [
                'AND', 'CASE', 'ELSE', 'EXISTS', 'HAVING', 'IN', 'NOT', 'ON',
                'OR', 'OVER', 'PARTITION', 'VALUE', 'VALUES', 'WHEN', 'WHERE',
                'XOR',
            ], true)) {
                continue;
            }
            if (str_starts_with($token, self::QUOTED_IDENTIFIER_TOKEN_PREFIX)) {
                self::violation('wprism: a quoted SQL routine is outside the native database profile grammar');
            }
            if (!$adjacent) {
                // Without IGNORE_SPACE, MariaDB/MySQL reserve the adjacent
                // built-in spelling while `COUNT ()` can resolve a stored
                // routine named COUNT. assert_profile_sql_mode() excludes the
                // mode that changes that lexical resolution; preserve the
                // source-byte adjacency here instead of trusting normalized
                // tokens that intentionally discard whitespace.
                self::violation('wprism: a spaced SQL routine call is outside the native database profile grammar');
            }
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $token) !== 1
                || !in_array($function, [
                    'ABS', 'CAST', 'CEIL', 'CEILING', 'CHAR_LENGTH', 'COALESCE', 'CONCAT',
                    'CONCAT_WS', 'CONNECTION_ID', 'COUNT', 'DATABASE', 'DATE', 'DATE_FORMAT', 'FIELD', 'FIND_IN_SET',
                    'FLOOR', 'GREATEST', 'IF', 'IFNULL', 'IS_USED_LOCK', 'JSON_EXTRACT', 'JSON_UNQUOTE',
                    'LEAST', 'LEFT', 'LENGTH', 'LOWER', 'LTRIM', 'MAX', 'MD5', 'MIN',
                    'NULLIF', 'OCTET_LENGTH', 'REPLACE', 'RIGHT', 'ROUND', 'RTRIM', 'SHA1', 'SHA2',
                    'SUBSTR', 'SUBSTRING', 'SUM', 'TRIM', 'UPPER',
                ], true)) {
                self::violation('wprism: an unreviewed SQL function crossed the native database profile');
            }
        }
    }

    /** @param list<string> $tokens */
    private static function table_token(array $tokens, int $offset): string {
        $table = $tokens[$offset] ?? null;
        if (is_string($table)
            && str_starts_with($table, self::QUOTED_IDENTIFIER_TOKEN_PREFIX)) {
            $table = substr($table, strlen(self::QUOTED_IDENTIFIER_TOKEN_PREFIX));
        }
        if (!is_string($table)
            || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1
            || ($tokens[$offset + 1] ?? null) === '.') {
            self::violation('wprism: a database table reference is outside the native profile grammar');
        }
        return $table;
    }

    /** @param list<string> $tokens */
    private static function keyword_offset(array $tokens, string $keyword): ?int {
        foreach ($tokens as $offset => $token) {
            if (strtoupper($token) === $keyword) {
                return $offset;
            }
        }
        return null;
    }

    /** @return array{tokens:list<string>,call_adjacency:array<int,bool>} */
    private static function sql_tokens(string $sql): array {
        $tokens = [];
        $callAdjacency = [];
        $length = strlen($sql);
        for ($offset = 0; $offset < $length;) {
            $byte = $sql[$offset];
            if (ctype_space($byte)) {
                $offset++;
                continue;
            }
            if ($byte === "'" || $byte === '"') {
                $quote = $byte;
                $offset++;
                while ($offset < $length) {
                    if ($sql[$offset] === '\\') {
                        $offset += 2;
                        continue;
                    }
                    if ($sql[$offset] !== $quote) {
                        $offset++;
                        continue;
                    }
                    if (($sql[$offset + 1] ?? '') === $quote) {
                        $offset += 2;
                        continue;
                    }
                    $offset++;
                    break;
                }
                $tokens[] = "''";
                continue;
            }
            if ($byte === '`') {
                $identifier = '';
                $tokenOffset = count($tokens);
                $offset++;
                while ($offset < $length) {
                    if ($sql[$offset] !== '`') {
                        $identifier .= $sql[$offset];
                        $offset++;
                        continue;
                    }
                    if (($sql[$offset + 1] ?? '') === '`') {
                        $identifier .= '`';
                        $offset += 2;
                        continue;
                    }
                    $offset++;
                    break;
                }
                $tokens[] = self::QUOTED_IDENTIFIER_TOKEN_PREFIX . $identifier;
                $cursor = $offset;
                while ($cursor < $length && ctype_space($sql[$cursor])) {
                    $cursor++;
                }
                if (($sql[$cursor] ?? null) === '(') {
                    $callAdjacency[$tokenOffset] = $cursor === $offset;
                }
                continue;
            }
            if (preg_match('/[A-Za-z0-9_$]/A', $byte) === 1) {
                $start = $offset;
                $tokenOffset = count($tokens);
                while ($offset < $length
                    && preg_match('/[A-Za-z0-9_$]/A', $sql[$offset]) === 1) {
                    $offset++;
                }
                $tokens[] = substr($sql, $start, $offset - $start);
                $cursor = $offset;
                while ($cursor < $length && ctype_space($sql[$cursor])) {
                    $cursor++;
                }
                if (($sql[$cursor] ?? null) === '(') {
                    $callAdjacency[$tokenOffset] = $cursor === $offset;
                }
                continue;
            }
            if (str_contains('(),.=<>+-*/%!:@', $byte)) {
                $tokens[] = $byte;
                $offset++;
                continue;
            }
            self::violation('wprism: database SQL contains a token outside the native profile grammar');
        }
        return ['tokens' => $tokens, 'call_adjacency' => $callAdjacency];
    }
}
