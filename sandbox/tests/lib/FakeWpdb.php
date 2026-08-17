<?php
/**
 * \DuoTest\FakeWpdb -- one duck-typed $wpdb for the offline suites.
 *
 * WHY THIS EXISTS
 * ---------------
 * 42 of the 204 offline regress_*.php suites carry a bespoke fake $wpdb.
 * They converge on the same public shape ($prefix, per-table name properties,
 * $last_error, $insert_id, prepare/get_var/get_row/get_col/get_results/query/
 * insert/update/delete) and then diverge on the interesting part: each one
 * regex-matches the handful of SQL strings ITS subject happens to emit and
 * returns null, [], or false for everything else. Concretely:
 *
 *   - ApplyFieldMaterializerFakeWpdb regex-parses "meta_key = '...'" out of
 *     the rendered SQL to find a row;
 *   - MenuCaptureFakeWpdb / OptionsCaptureFakeWpdb / TypedCaptureWpdb make
 *     prepare() return an ARRAY ['sql'=>..,'args'=>..] so the fake can bind
 *     late, which is not what wpdb does and means those suites never exercise
 *     the real placeholder rendering;
 *   - WooEngineFakeWpdb returns [] plus a last_error on an injected read
 *     failure, while SnapshotPrunerFakeWpdb returns false for the same case.
 *
 * The cost is not the duplication, it is that a fake only answers the exact
 * SQL its author transcribed. Change a WHERE clause in the engine and the
 * fake silently returns null; the suite then asserts against a "no row"
 * branch that the live gate never takes.
 *
 * This class inverts that: it holds ROWS, not answers, and interprets the SQL
 * against them. Every SQL shape it cannot interpret raises \LogicException
 * naming the statement, so a suite author extends the grammar instead of
 * silently getting null. That loudness is the whole point -- it is the same
 * posture as agent/src/Db.php, which refuses to treat wpdb's false as a
 * survivable value.
 *
 * INTEROPERATION WITH agent/src/Db.php
 * ------------------------------------
 * Db::checked() tests the strict `=== false` and, when it sees it, reads
 * $wpdb->last_error and maps 'Deadlock found' / 'Lock wait timeout' to
 * TransientDbException and everything else to DatabaseMutationException.
 * So the failure seams here must set BOTH: a false return AND the driver
 * text. simulateDeadlock() / simulateLockWaitTimeout() emit the literal
 * MySQL 1213 / 1205 strings that stripos() in Db.php matches.
 *
 * READ-FAILURE RETURN VALUES (a real fork in the existing fakes, settled
 * here by copying wpdb rather than by picking a side): only query() reports
 * false. wpdb::get_col() builds and returns array() unconditionally and
 * wpdb::get_results() returns $this->last_result, which wpdb::flush() already
 * reset to array() at the top of the failing query -- so BOTH return an empty
 * array on a driver error, and get_var()/get_row() return null. last_error is
 * the only positive signal, and it is always set here.
 *
 * The consequence for a suite author: `if (!is_array($rows))` after a
 * get_col() is dead code against live wpdb (RegenerationContextStore and
 * ProviderSdk both carry one), so this class must not make it reachable.
 * Drive the failure branch through last_error -- which is what ScopedApply.php
 * (`$rows === false || !empty($wpdb->last_error)`) and SnapshotPruner.php
 * (`$live === false || $live === null || !empty($wpdb->last_error)`) actually
 * survive on.
 *
 * RESULT VALUE TYPES: wpdb runs mysqli over the text protocol without
 * MYSQLI_OPT_INT_AND_FLOAT_NATIVE, so every non-NULL column value reaches PHP
 * as a STRING -- COUNT(*) included. get_var()/get_col()/get_row()/
 * get_results() therefore stringify scalars on the way out (NULL stays null),
 * which matters because check.php pushes strict ===: `duo_check_same(19, ...)`
 * against a live meta_id is false, and a fake that returned int 19 would pin
 * it green. rows() reads the STORE, not a result set, so it keeps the seeded
 * PHP types; assert against get_*() when you are characterizing what the
 * engine sees. insert_id / rows_affected / num_rows stay ints, as on wpdb.
 *
 * SUPPORTED SQL GRAMMAR (everything else throws \LogicException):
 *
 *   SELECT [DISTINCT] <items> [FROM <table> [[AS] alias]]
 *          [WHERE <cond>] [GROUP BY <cols>] [ORDER BY <cols> [ASC|DESC]]
 *          [LIMIT n [OFFSET m] | LIMIT m, n]
 *     items: * | alias.* | COUNT(*) | <literal> | [alias.]col | LENGTH(col)
 *            | GET_LOCK(..) | RELEASE_LOCK(..)   , each with an optional AS alias
 *     cond:  AND / OR / parentheses over
 *            <operand> = != <> < <= > >= <operand>
 *            <operand> [NOT] IN (<values>)
 *            <operand> [NOT] LIKE <string>
 *            <operand> IS [NOT] NULL
 *     operand: column | literal | BINARY <operand> | LENGTH(<operand>)
 *              | <operand> + - <operand>
 *   INSERT [IGNORE] INTO t (cols) VALUES (...)[, (...)]
 *          [ON DUPLICATE KEY UPDATE col = <expr> ...]   (needs setUniqueKey)
 *   REPLACE INTO t (cols) VALUES (...)                  (needs setUniqueKey)
 *   UPDATE t SET col = <expr> [, ...] [WHERE <cond>] [LIMIT n]
 *   DELETE FROM t [WHERE <cond>] [LIMIT n]
 *   SHOW TABLES LIKE '<pattern>'      -> the table name, or null
 *   SHOW [FULL] COLUMNS FROM t        -> Field/Type rows (see setColumns)
 *   START TRANSACTION | BEGIN | COMMIT | ROLLBACK  (single-level, snapshotting)
 *   SET ...                           (accepted no-op)
 *   CREATE / ALTER / DROP / TRUNCATE  (recorded in ddlLog; DROP/TRUNCATE clear rows)
 *
 * JOINs, subqueries, UNION, HAVING and aggregate functions other than
 * COUNT(*) are deliberately NOT supported: a suite that needs one is
 * characterizing a query whose behaviour belongs in the live certification,
 * not in an in-memory reimplementation of MySQL.
 *
 * Neither are schema-qualified reads (information_schema.COLUMNS /
 * .STATISTICS) or SHOW INDEX -- parseTableRef() refuses the `db.table` form
 * outright. That is not an oversight to route around: the facts those queries
 * return live in setColumns() / setUniqueKey() / setPrimaryKey(), and a
 * synthetic information_schema fed from them would be asserting this file's
 * bookkeeping rather than the target's schema. Concretely it means
 * Ledger::assert_read_only_schema(), Ledger::prune_dead_table_map() (a
 * multi-table DELETE) and Snapshot::assert_all_mapped_rows_managed() (a LEFT
 * JOIN) cannot be migrated to this fake; they stay live-certification paths.
 * SHOW TABLES LIKE / SHOW COLUMNS FROM are supported and are the intended way
 * to probe existence and column shape offline.
 *
 * TABLE REGISTRATION: reads against a table that was never seeded throw,
 * because "SELECT from a table you forgot to seed" silently returning [] is
 * precisely the failure mode this class exists to remove. Seed an empty table
 * with seedTable($name, []) to say "this table exists and is empty". Writes
 * auto-register, and SHOW TABLES LIKE never throws -- existence probing is
 * its job.
 *
 * NO DEPENDENCIES: plain PHP, no composer, no WordPress, no agent/ include.
 */
declare(strict_types=1);

namespace {
    // wpdb output constants, guarded so a suite may require this file after
    // wp_stubs.php (or after its own defines) without a redefinition notice.
    if (!defined('OBJECT')) {
        define('OBJECT', 'OBJECT');
    }
    if (!defined('OBJECT_K')) {
        define('OBJECT_K', 'OBJECT_K');
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    if (!defined('ARRAY_N')) {
        define('ARRAY_N', 'ARRAY_N');
    }
}

namespace DuoTest {

final class FakeWpdb {
    /** Literal MySQL 1213 text; Db.php matches 'Deadlock found' case-insensitively. */
    public const DEADLOCK_ERROR = 'Deadlock found when trying to get lock; try restarting transaction';

    /** Literal MySQL 1205 text; Db.php matches 'Lock wait timeout'. */
    public const LOCK_TIMEOUT_ERROR = 'Lock wait timeout exceeded; try restarting transaction';

    /**
     * Fixed (not random) stand-in for wpdb's per-request placeholder escape
     * hash. Deterministic so a logged query is byte-stable across runs; the
     * token is stripped before any statement is interpreted or logged, so it
     * never reaches an assertion.
     */
    private const PLACEHOLDER_ESCAPE = '{duo-fakewpdb-placeholder}';

    /** Core tables wpdb exposes as properties, with their primary keys. */
    private const CORE_TABLES = [
        'posts' => 'ID',
        'postmeta' => 'meta_id',
        'comments' => 'comment_ID',
        'commentmeta' => 'meta_id',
        'terms' => 'term_id',
        'term_taxonomy' => 'term_taxonomy_id',
        'term_relationships' => null,
        'termmeta' => 'meta_id',
        'options' => 'option_id',
        'users' => 'ID',
        'usermeta' => 'umeta_id',
        'links' => 'link_id',
    ];

    public string $prefix = 'wp_';
    public string $base_prefix = 'wp_';
    public string $last_error = '';
    public string $last_query = '';
    public int $insert_id = 0;
    public int $num_rows = 0;
    public int $rows_affected = 0;

    // Table-name properties, assigned from the prefix in the constructor.
    public string $posts = 'wp_posts';
    public string $postmeta = 'wp_postmeta';
    public string $comments = 'wp_comments';
    public string $commentmeta = 'wp_commentmeta';
    public string $terms = 'wp_terms';
    public string $term_taxonomy = 'wp_term_taxonomy';
    public string $term_relationships = 'wp_term_relationships';
    public string $termmeta = 'wp_termmeta';
    public string $options = 'wp_options';
    public string $users = 'wp_users';
    public string $usermeta = 'wp_usermeta';
    public string $links = 'wp_links';

    /** @var array<string,list<array<string,mixed>>> full table name => rows */
    private array $store = [];
    /** @var array<string,string> full table name => primary key column */
    private array $primaryKeys = [];
    /** @var array<string,int> full table name => next auto-increment value */
    private array $autoIncrement = [];
    /** @var array<string,list<list<string>>> full table name => unique key column sets */
    private array $uniqueKeys = [];
    /** @var array<string,array<string,string>> full table name => column => SQL type */
    private array $columnTypes = [];
    /** @var list<array{method:string,sql:string,error:string}> */
    private array $queryLog = [];
    /** @var list<string> */
    private array $ddlLog = [];
    /** @var null|callable(string,string,self):(bool|string|null) */
    private $queryHook = null;
    /** @var list<array{match:?string,error:string,remaining:int}> */
    private array $injectedFailures = [];
    /**
     * Row snapshot taken at START TRANSACTION. Deliberately does NOT include
     * $autoIncrement -- see execTransaction().
     *
     * @var null|array<string,list<array<string,mixed>>>
     */
    private ?array $transactionSnapshot = null;
    private int $lockResult = 1;

    public function __construct(string $prefix = 'wp_') {
        $this->prefix = $prefix;
        $this->base_prefix = $prefix;
        foreach (self::CORE_TABLES as $name => $pk) {
            $this->$name = $prefix . $name;
            if ($pk !== null) {
                $this->primaryKeys[$prefix . $name] = $pk;
            }
        }
    }

    /**
     * Build one and publish it as $GLOBALS['wpdb'], which is where every
     * agent/src collaborator (and Db.php) reads it from.
     */
    public static function install(string $prefix = 'wp_'): self {
        $db = new self($prefix);
        $GLOBALS['wpdb'] = $db;
        return $db;
    }

    // ------------------------------------------------------------- fixtures

    /** Prefix a bare table suffix: table('duo_kv') === 'wp_duo_kv'. */
    public function tableName(string $table): string {
        $table = trim($table, '`');
        return str_starts_with($table, $this->prefix) ? $table : $this->prefix . $table;
    }

    /**
     * Register a table and its rows. Rows are plain column => value maps;
     * they may be sparse (an absent column reads as SQL NULL) but every
     * column named in a query must appear in at least one row or in
     * setColumns(), or the read throws rather than inventing a NULL.
     *
     * seedTable('wp_postmeta', []) is the way to say "exists and is empty".
     *
     * @param list<array<string,mixed>> $rows
     */
    public function seedTable(string $table, array $rows = []): self {
        $name = $this->tableName($table);
        $this->store[$name] = array_values(array_map(static fn(array $row): array => $row, $rows));
        $pk = $this->primaryKeys[$name] ?? null;
        if ($pk !== null) {
            $max = 0;
            foreach ($this->store[$name] as $row) {
                $max = max($max, (int) ($row[$pk] ?? 0));
            }
            $this->autoIncrement[$name] = $max + 1;
        }
        return $this;
    }

    /** @return list<array<string,mixed>> the live rows, in insertion order */
    public function rows(string $table): array {
        $name = $this->tableName($table);
        if (!array_key_exists($name, $this->store)) {
            throw new \LogicException("FakeWpdb: table '$name' was never seeded; call seedTable('$name', []) first");
        }
        return $this->store[$name];
    }

    /** True when the table has been registered (seeded or written to). */
    public function hasTable(string $table): bool {
        return array_key_exists($this->tableName($table), $this->store);
    }

    /**
     * Declare the auto-increment primary key and the next id it will hand
     * out. The wp_* core tables already have theirs (see CORE_TABLES); this
     * is for the product's own tables (duo_map, duo_state, duo_kv, ...).
     */
    public function setAutoIncrement(string $table, int $next, ?string $primaryKey = null): self {
        $name = $this->tableName($table);
        if ($primaryKey !== null) {
            $this->primaryKeys[$name] = $primaryKey;
        }
        $this->autoIncrement[$name] = $next;
        return $this;
    }

    /** Name the primary key without changing the counter. */
    public function setPrimaryKey(string $table, string $column): self {
        $this->primaryKeys[$this->tableName($table)] = $column;
        return $this;
    }

    /**
     * Declare a unique key. Required before INSERT IGNORE, REPLACE, or
     * ON DUPLICATE KEY UPDATE will run: without it there is no fact in this
     * object that says which rows collide, and guessing would make the
     * assertion meaningless.
     *
     * @param list<string> $columns
     */
    public function setUniqueKey(string $table, array $columns): self {
        $this->uniqueKeys[$this->tableName($table)][] = array_values($columns);
        return $this;
    }

    /**
     * Declare column => SQL type, as SHOW COLUMNS should report it. Also
     * widens the known-column set, so a column that is NULL in every seeded
     * row still resolves instead of throwing.
     *
     * @param array<string,string> $types
     */
    public function setColumns(string $table, array $types): self {
        $name = $this->tableName($table);
        $this->columnTypes[$name] = $types;
        $this->store[$name] ??= [];
        return $this;
    }

    /** Result GET_LOCK() reports; 0 makes the engine's lock acquisition fail. */
    public function setLockResult(int $result): self {
        $this->lockResult = $result;
        return $this;
    }

    // -------------------------------------------------------- failure seams

    /**
     * Inspect (and optionally veto) every statement before it runs.
     *
     * The hook receives (string $sql, string $method, self $db) and returns
     * null to proceed, false to fail with a generic driver error, or a string
     * to fail with that string as $last_error. Pass null to clear.
     *
     * @param null|callable(string,string,self):(bool|string|null) $hook
     */
    public function onQuery(?callable $hook): self {
        $this->queryHook = $hook;
        return $this;
    }

    /**
     * Fail the next $times statements (optionally only those containing
     * $matching) with $error as $last_error, then resume normally.
     */
    public function failNextQuery(
        string $error = 'injected wpdb failure',
        ?string $matching = null,
        int $times = 1
    ): self {
        $this->injectedFailures[] = ['match' => $matching, 'error' => $error, 'remaining' => $times];
        return $this;
    }

    /**
     * Raise MySQL's deadlock text, which Db.php maps to TransientDbException
     * -- the retryable class the capture path re-runs from a fresh snapshot.
     */
    public function simulateDeadlock(?string $matching = null, int $times = 1): self {
        return $this->failNextQuery(self::DEADLOCK_ERROR, $matching, $times);
    }

    /** The 1205 sibling of simulateDeadlock(); also maps to TransientDbException. */
    public function simulateLockWaitTimeout(?string $matching = null, int $times = 1): self {
        return $this->failNextQuery(self::LOCK_TIMEOUT_ERROR, $matching, $times);
    }

    /** @return list<array{method:string,sql:string,error:string}> */
    public function queryLog(): array {
        return $this->queryLog;
    }

    /** @return list<string> just the SQL, for a compact sequence assertion */
    public function queries(): array {
        return array_map(static fn(array $entry): string => $entry['sql'], $this->queryLog);
    }

    /** @return list<string> recorded CREATE/ALTER/DROP/TRUNCATE statements */
    public function ddlLog(): array {
        return $this->ddlLog;
    }

    public function resetLog(): self {
        $this->queryLog = [];
        $this->ddlLog = [];
        return $this;
    }

    // ------------------------------------------------------- wpdb surface

    /**
     * wpdb::prepare(), including the properties suites get wrong when they
     * hand-roll it:
     *
     *  - %s renders WITH the surrounding quotes (WordPress >= 4.8.3 behaviour;
     *    the engine writes `WHERE option_name = %s`, unquoted);
     *  - %d casts to int, %f/%F to float, %i renders a backtick-quoted
     *    identifier;
     *  - %% is a literal percent, and a percent inside a VALUE is protected
     *    by the placeholder escape so it is not re-parsed as a placeholder;
     *  - a single array argument is unpacked, which is how the engine passes
     *    a variadic IN (...) list;
     *  - positional %1$s is honoured.
     *
     * A placeholder/argument count mismatch throws instead of rendering a
     * half-bound statement: a stray %d that silently stays in the SQL is a
     * bug the interpreter would then report as unparseable far from its cause.
     */
    public function prepare(string $query, mixed ...$args): string {
        if (count($args) === 1 && is_array($args[0])) {
            $args = array_values($args[0]);
        }
        $out = '';
        $offset = 0;
        $next = 0;
        $used = [];
        $length = strlen($query);
        while ($offset < $length) {
            if ($query[$offset] !== '%') {
                $out .= $query[$offset];
                $offset++;
                continue;
            }
            $matched = preg_match(
                '/\G%(?:(\d+)\$)?[-+0-9]*(?:\.[0-9]+)?([sdfFi%])/',
                $query,
                $m,
                0,
                $offset
            );
            if ($matched !== 1) {
                // A bare % that is not a placeholder. Real wpdb would treat
                // this as a literal too.
                $out .= '%';
                $offset++;
                continue;
            }
            $offset += strlen($m[0]);
            if ($m[2] === '%') {
                $out .= self::PLACEHOLDER_ESCAPE;
                continue;
            }
            $index = $m[1] !== '' ? ((int) $m[1]) - 1 : $next++;
            if (!array_key_exists($index, $args)) {
                throw new \LogicException(
                    'FakeWpdb::prepare(): placeholder ' . $m[0] . ' has no argument #' . ($index + 1)
                    . ' in: ' . $query
                );
            }
            $used[$index] = true;
            $out .= $this->renderPlaceholder($m[2], $args[$index]);
        }
        if (count($used) !== count($args)) {
            throw new \LogicException(
                'FakeWpdb::prepare(): ' . count($args) . ' argument(s) for ' . count($used)
                . ' placeholder(s) in: ' . $query
            );
        }
        return $out;
    }

    /** Escape LIKE wildcards, as wpdb::esc_like() does, before %s binding. */
    public function esc_like(string $text): string {
        return addcslashes($text, '_%\\');
    }

    /** Turn the placeholder-escape token back into a literal percent sign. */
    public function remove_placeholder_escape(string $query): string {
        return str_replace(self::PLACEHOLDER_ESCAPE, '%', $query);
    }

    public function get_charset_collate(): string {
        return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
    }

    public function db_version(): string {
        return '8.0.36';
    }

    public function check_connection(bool $allow_bail = true): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    /**
     * Real wpdb returns the core table names for the current blog. Only the
     * tables this fake models are listed; the engine uses it to enumerate
     * "tables WordPress itself owns".
     *
     * @return array<string,string>
     */
    public function tables(string $scope = 'all', bool $prefix = true, int $blog_id = 0): array {
        $out = [];
        foreach (array_keys(self::CORE_TABLES) as $name) {
            $out[$name] = $prefix ? $this->prefix . $name : $name;
        }
        return $out;
    }

    /**
     * Clear the per-statement result state, as wpdb::flush() does -- which
     * means rows_affected and last_query as well, not just last_error and
     * num_rows. wpdb::query() calls flush() BEFORE running the statement, so
     * a read genuinely does reset rows_affected to 0 on the live target; a
     * fake that carried the previous write's count forward would let a suite
     * assert a rows_affected the engine never sees. log()/fail() re-assign
     * last_query immediately afterwards.
     */
    public function flush(): void {
        $this->last_error = '';
        $this->last_query = '';
        $this->num_rows = 0;
        $this->rows_affected = 0;
    }

    /**
     * Execute a statement. Returns rows affected for DML, row count for
     * SELECT, true for transaction/DDL statements, false on an injected
     * failure -- the same contract as wpdb::query(), which Db::query() reads
     * with a strict `=== false`.
     */
    public function query(string $query): int|bool {
        $result = $this->run('query', $query);
        if ($result === null) {
            return false;
        }
        return match ($result['kind']) {
            'rows' => count($result['rows']),
            'affected' => $result['affected'],
            default => true,
        };
    }

    /**
     * First column of the first row as a STRING (see the header's RESULT VALUE
     * TYPES note), or null -- for no rows, a NULL column, or a failure.
     */
    public function get_var(string $query, int $x = 0, int $y = 0): ?string {
        $result = $this->run('get_var', $query);
        if ($result === null || $result['kind'] !== 'rows') {
            return null;
        }
        $rows = $result['rows'];
        if (!isset($rows[$y])) {
            return null;
        }
        $values = array_values($rows[$y]);
        return self::outbound($values[$x] ?? null);
    }

    /** One row in $output shape, or null. */
    public function get_row(string $query, string $output = OBJECT, int $y = 0): array|object|null {
        $result = $this->run('get_row', $query);
        if ($result === null || $result['kind'] !== 'rows') {
            return null;
        }
        return isset($result['rows'][$y]) ? $this->shape($result['rows'][$y], $output) : null;
    }

    /**
     * One column across all rows, as strings.
     *
     * ALWAYS an array, including on a failure: wpdb::get_col() builds its
     * return value in a loop over an empty last_result and never reports
     * false. Read $last_error to detect the failure.
     *
     * @return list<?string>
     */
    public function get_col(string $query, int $x = 0): array {
        $result = $this->run('get_col', $query);
        if ($result === null || $result['kind'] !== 'rows') {
            return [];
        }
        $out = [];
        foreach ($result['rows'] as $row) {
            $values = array_values($row);
            $out[] = self::outbound($values[$x] ?? null);
        }
        return $out;
    }

    /**
     * Every row in $output shape, values stringified.
     *
     * ALWAYS an array, including on a failure: wpdb::get_results() returns
     * $this->last_result, which wpdb::flush() reset to array() before the
     * statement ran. Read $last_error to detect the failure.
     *
     * @return array<array-key,array<string,?string>|object>
     */
    public function get_results(string $query, string $output = OBJECT): array {
        $result = $this->run('get_results', $query);
        if ($result === null || $result['kind'] !== 'rows') {
            return [];
        }
        if ($output === OBJECT_K) {
            $keyed = [];
            foreach ($result['rows'] as $row) {
                $keyed[(string) reset($row)] = $this->shape($row, OBJECT);
            }
            return $keyed;
        }
        return array_map(fn(array $row): array|object => $this->shape($row, $output), $result['rows']);
    }

    /**
     * Array-style INSERT. Bypasses the SQL parser exactly as real wpdb does
     * (it builds the statement itself), but still honours the failure seams
     * and logs a rendered statement so a suite can assert on the sequence.
     *
     * Returns rows inserted (1) or false. $format is accepted and ignored:
     * this store is typeless, and the engine's format arrays are already
     * characterized by regress_table_schema.php against the real wpdb rules.
     */
    public function insert(string $table, array $data, mixed $format = null): int|false {
        $sql = $this->renderInsert($table, $data);
        $this->flush();
        if (($error = $this->intercept('insert', $sql)) !== null) {
            return $this->fail('insert', $sql, $error);
        }
        $this->applyInsert($this->tableName($table), $data);
        $this->log('insert', $sql);
        $this->rows_affected = 1;
        return 1;
    }

    /** Array-style REPLACE; requires a unique key to know what it replaces. */
    public function replace(string $table, array $data, mixed $format = null): int|false {
        $name = $this->tableName($table);
        $sql = 'REPLACE ' . substr($this->renderInsert($table, $data), strlen('INSERT '));
        $this->flush();
        if (($error = $this->intercept('replace', $sql)) !== null) {
            return $this->fail('replace', $sql, $error);
        }
        $removed = $this->removeUniqueConflicts($name, $data);
        $this->applyInsert($name, $data);
        $this->log('replace', $sql);
        $this->rows_affected = $removed + 1;
        return $this->rows_affected;
    }

    /**
     * Array-style UPDATE. Returns the number of rows MATCHED-and-written.
     * Note wpdb (and MySQL) report 0 when the row exists but every value was
     * already equal; that 0 is a valid success, which is why Db::update()
     * checks `=== false` and not falsiness.
     */
    public function update(
        string $table,
        array $data,
        array $where,
        mixed $format = null,
        mixed $whereFormat = null
    ): int|false {
        $sql = $this->renderUpdate($table, $data, $where);
        $this->flush();
        if (($error = $this->intercept('update', $sql)) !== null) {
            return $this->fail('update', $sql, $error);
        }
        $name = $this->requireTable($this->tableName($table));
        $affected = 0;
        foreach ($this->store[$name] as $index => $row) {
            if (!$this->matchesEquality($row, $where)) {
                continue;
            }
            $updated = array_merge($row, $data);
            if ($updated !== $row) {
                $this->store[$name][$index] = $updated;
                $affected++;
            }
        }
        $this->log('update', $sql);
        $this->rows_affected = $affected;
        return $affected;
    }

    /** Array-style DELETE. Returns rows removed, or false on an injected failure. */
    public function delete(string $table, array $where, mixed $whereFormat = null): int|false {
        $sql = $this->renderDelete($table, $where);
        $this->flush();
        if (($error = $this->intercept('delete', $sql)) !== null) {
            return $this->fail('delete', $sql, $error);
        }
        $name = $this->requireTable($this->tableName($table));
        $kept = [];
        $removed = 0;
        foreach ($this->store[$name] as $row) {
            if ($this->matchesEquality($row, $where)) {
                $removed++;
                continue;
            }
            $kept[] = $row;
        }
        $this->store[$name] = $kept;
        $this->log('delete', $sql);
        $this->rows_affected = $removed;
        return $removed;
    }

    // ---------------------------------------------------------- internals

    private function renderPlaceholder(string $type, mixed $value): string {
        return match ($type) {
            'd' => (string) (int) $value,
            'f', 'F' => (string) (float) $value,
            'i' => '`' . str_replace('`', '``', (string) $value) . '`',
            default => "'" . str_replace('%', self::PLACEHOLDER_ESCAPE, addslashes((string) $value)) . "'",
        };
    }

    /**
     * Shared entry point for every statement-executing read/write method.
     * Returns null when the statement was vetoed by a failure seam; callers
     * translate that null into their own wpdb-shaped failure value.
     *
     * @return null|array{kind:string,rows?:list<array<string,mixed>>,affected?:int}
     */
    private function run(string $method, string $query): ?array {
        $sql = $this->remove_placeholder_escape($query);
        $this->flush();
        if (($error = $this->intercept($method, $sql)) !== null) {
            $this->fail($method, $sql, $error);
            return null;
        }
        $result = $this->execute($sql);
        $this->log($method, $sql);
        if ($result['kind'] === 'rows') {
            $this->num_rows = count($result['rows']);
        } elseif ($result['kind'] === 'affected') {
            $this->rows_affected = $result['affected'];
        }
        return $result;
    }

    /** @return ?string the driver error text when a failure seam fires */
    private function intercept(string $method, string $sql): ?string {
        if ($this->queryHook !== null) {
            $verdict = ($this->queryHook)($sql, $method, $this);
            if ($verdict === false) {
                return 'injected wpdb failure';
            }
            if (is_string($verdict)) {
                return $verdict;
            }
        }
        foreach ($this->injectedFailures as $index => $failure) {
            if ($failure['remaining'] <= 0) {
                continue;
            }
            if ($failure['match'] !== null && !str_contains($sql, $failure['match'])) {
                continue;
            }
            $this->injectedFailures[$index]['remaining']--;
            return $failure['error'];
        }
        return null;
    }

    private function fail(string $method, string $sql, string $error): false {
        $this->last_error = $error;
        $this->last_query = $sql;
        $this->queryLog[] = ['method' => $method, 'sql' => $sql, 'error' => $error];
        return false;
    }

    private function log(string $method, string $sql): void {
        $this->last_query = $sql;
        $this->queryLog[] = ['method' => $method, 'sql' => $sql, 'error' => ''];
    }

    /**
     * Put one result row into the requested wpdb output shape, stringifying
     * every non-NULL value on the way (see outbound()).
     */
    private function shape(array $row, string $output): array|object {
        $row = array_map([self::class, 'outbound'], $row);
        return match ($output) {
            ARRAY_A => $row,
            ARRAY_N => array_values($row),
            default => (object) $row,
        };
    }

    /**
     * One column value as a caller sees it.
     *
     * wpdb reads results through mysqli's TEXT protocol and never sets
     * MYSQLI_OPT_INT_AND_FLOAT_NATIVE, so a non-NULL column always arrives in
     * PHP as a string -- '1', '0', '3' -- while NULL arrives as null. The
     * store keeps the fixture's real PHP types (rows() and every internal
     * comparison want them); this is the single boundary where they become
     * what the engine actually receives, so that check.php's strict === means
     * the same thing offline as it does live.
     */
    private static function outbound(mixed $value): ?string {
        if ($value === null) {
            return null;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return (string) $value;
    }

    private function requireTable(string $name): string {
        if (!array_key_exists($name, $this->store)) {
            $known = array_keys($this->store);
            sort($known);
            throw new \LogicException(
                "FakeWpdb: table '$name' was never seeded. Known tables: "
                . ($known === [] ? '(none)' : implode(', ', $known))
                . ". Call seedTable('$name', []) to declare it empty."
            );
        }
        return $name;
    }

    /**
     * WHERE evaluation for the array-style update()/delete(), which is NOT the
     * interpreter's: wpdb renders a null $where value as `col IS NULL`, so it
     * is an IS NULL test here too (see renderWhere()). Everything else is the
     * shared MySQL-ish comparison.
     *
     * @param array<string,mixed> $where column => expected value
     */
    private function matchesEquality(array $row, array $where): bool {
        foreach ($where as $column => $expected) {
            $actual = $row[$column] ?? null;
            if ($expected === null) {
                if ($actual !== null) {
                    return false;
                }
                continue;
            }
            if (self::compare($actual, $expected) !== 0) {
                return false;
            }
        }
        return true;
    }

    private function applyInsert(string $name, array $data): void {
        $this->store[$name] ??= [];
        $pk = $this->primaryKeys[$name] ?? null;
        if ($pk !== null && !array_key_exists($pk, $data)) {
            $next = $this->autoIncrement[$name] ?? 1;
            $data = [$pk => $next] + $data;
            $this->autoIncrement[$name] = $next + 1;
            $this->insert_id = $next;
        } elseif ($pk !== null) {
            $this->insert_id = (int) $data[$pk];
            $this->autoIncrement[$name] = max($this->autoIncrement[$name] ?? 1, (int) $data[$pk] + 1);
        } else {
            // No AUTO_INCREMENT column: wpdb reports 0, and Db::insert_id()
            // turns that into a DatabaseMutationException rather than a
            // silently wrong id.
            $this->insert_id = 0;
        }
        $this->store[$name][] = $data;
    }

    /**
     * Remove rows colliding with $data on any declared unique key. Throws
     * when no unique key is declared, because REPLACE / INSERT IGNORE /
     * ON DUPLICATE KEY have no meaning without one.
     */
    private function removeUniqueConflicts(string $name, array $data): int {
        $keys = $this->uniqueKeys[$name] ?? [];
        if ($keys === []) {
            throw new \LogicException(
                "FakeWpdb: table '$name' has no unique key, so REPLACE / INSERT IGNORE /"
                . " ON DUPLICATE KEY UPDATE is undefined. Call setUniqueKey('$name', [...]) first."
            );
        }
        $kept = [];
        $removed = 0;
        foreach ($this->store[$name] ?? [] as $row) {
            if ($this->conflicts($row, $data, $keys)) {
                $removed++;
                continue;
            }
            $kept[] = $row;
        }
        $this->store[$name] = $kept;
        return $removed;
    }

    /** @param list<list<string>> $keys */
    private function conflicts(array $row, array $data, array $keys): bool {
        foreach ($keys as $columns) {
            $all = true;
            foreach ($columns as $column) {
                if (!array_key_exists($column, $data)
                    || self::compare($row[$column] ?? null, $data[$column]) !== 0) {
                    $all = false;
                    break;
                }
            }
            if ($all) {
                return true;
            }
        }
        return false;
    }

    private function renderInsert(string $table, array $data): string {
        $columns = implode(', ', array_map(static fn(string $c): string => "`$c`", array_keys($data)));
        $values = implode(', ', array_map([self::class, 'literal'], array_values($data)));
        return 'INSERT INTO `' . $this->tableName($table) . "` ($columns) VALUES ($values)";
    }

    private function renderUpdate(string $table, array $data, array $where): string {
        $set = [];
        foreach ($data as $column => $value) {
            $set[] = "`$column` = " . self::literal($value);
        }
        return 'UPDATE `' . $this->tableName($table) . '` SET ' . implode(', ', $set)
            . ' WHERE ' . $this->renderWhere($where);
    }

    private function renderDelete(string $table, array $where): string {
        return 'DELETE FROM `' . $this->tableName($table) . '` WHERE ' . $this->renderWhere($where);
    }

    private function renderWhere(array $where): string {
        $parts = [];
        foreach ($where as $column => $value) {
            // wpdb::update()/::delete() special-case is_null($value) and render
            // `col IS NULL`, NOT `col = NULL` -- so the array-style writers DO
            // match rows whose column is NULL, even though the interpreter's
            // three-valued `col = NULL` matches nothing. matchesEquality() is
            // aligned with this branch, not with the interpreter.
            $parts[] = $value === null
                ? "`$column` IS NULL"
                : "`$column` = " . self::literal($value);
        }
        return $parts === [] ? '1 = 1' : implode(' AND ', $parts);
    }

    private static function literal(mixed $value): string {
        if ($value === null) {
            return 'NULL';
        }
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        return "'" . addslashes((string) $value) . "'";
    }

    /**
     * MySQL-ish comparison. Returns null when either side is SQL NULL (the
     * unknown of three-valued logic, which excludes the row), otherwise -1/0/1.
     *
     * Numeric coercion happens only when at least one operand is a PHP int or
     * float, which is MySQL's rule: a number on either side coerces the other,
     * but STRING op STRING is a collation comparison. That is the case this
     * fake actually needs -- the store holds 19 while a %d-rendered literal
     * lexes to int 19, and those must match. It is emphatically NOT license to
     * compare two strings numerically: '7' = '007' and '1e2' = '100' are FALSE
     * in MySQL, and the columns this class is built to model (duo_kv `k`,
     * option_name, meta_key, packed composite ids rendered through %s) are
     * exactly where a false match would let a suite assert that a lookup found
     * a row the live target never returns -- or that a uniqueness guard holds
     * when it does not (conflicts()/removeUniqueConflicts() route through here
     * too, so INSERT IGNORE / ON DUPLICATE KEY / REPLACE inherit the rule).
     *
     * Strings compare CASE-INSENSITIVELY, because a stock WordPress install
     * runs utf8mb4_*_ci collations and `WHERE user_login = %s` really does
     * match 'Admin' against 'admin' on the live target. That is precisely why
     * the engine writes `BINARY user_login = BINARY %s` where identity must be
     * byte-exact (see agent/src, user and duo_kv lookups): a fake whose plain
     * `=` were case-sensitive would make those BINARY guards look like dead
     * code and let a case-collision bug pass the offline gate.
     */
    private static function compare(mixed $a, mixed $b): ?int {
        if ($a === null || $b === null) {
            return null;
        }
        if (is_bool($a)) {
            $a = $a ? 1 : 0;
        }
        if (is_bool($b)) {
            $b = $b ? 1 : 0;
        }
        if (is_int($a) || is_float($a) || is_int($b) || is_float($b)) {
            // A number on either side coerces the other, including a
            // non-numeric string: MySQL reads that as 0 (with a warning)
            // rather than falling back to a collation compare, and an explicit
            // PHP float cast reproduces it without emitting a diagnostic.
            return (float) $a <=> (float) $b;
        }
        return strcasecmp((string) $a, (string) $b);
    }

    /** Byte-exact comparison for the engine's `BINARY col = BINARY %s` reads. */
    private static function compareBinary(mixed $a, mixed $b): ?int {
        if ($a === null || $b === null) {
            return null;
        }
        return strcmp((string) $a, (string) $b);
    }

    // ------------------------------------------------- SQL interpreter

    /** @var list<array{t:string,v:mixed}> token stream of the statement being parsed */
    private array $tk = [];
    private int $tp = 0;
    private string $currentSql = '';

    /**
     * Interpret one statement against the in-memory store.
     *
     * Statement kinds that carry no rows (transactions, DDL, SET) are
     * dispatched on the leading keyword BEFORE lexing, because a CREATE TABLE
     * body contains column-definition syntax this lexer has no reason to
     * understand.
     *
     * @return array{kind:string,rows?:list<array<string,mixed>>,affected?:int}
     */
    private function execute(string $sql): array {
        $trimmed = rtrim(trim($sql), "; \t\n\r");
        if ($trimmed === '') {
            throw new \LogicException('FakeWpdb: empty SQL statement');
        }
        $this->currentSql = $trimmed;
        $head = preg_match('/^[A-Za-z_]+/', $trimmed, $m) === 1 ? strtoupper($m[0]) : '';
        switch ($head) {
            case 'CREATE':
            case 'ALTER':
            case 'DROP':
            case 'TRUNCATE':
                return $this->execDdl($head);
            case 'SET':
            case 'LOCK':
            case 'UNLOCK':
            case 'ANALYZE':
            case 'OPTIMIZE':
                $this->ddlLog[] = $trimmed;
                return ['kind' => 'ok'];
            case 'START':
            case 'BEGIN':
            case 'COMMIT':
            case 'ROLLBACK':
                return $this->execTransaction($head);
        }
        $this->tk = $this->lex($trimmed);
        $this->tp = 0;
        return match ($head) {
            'SELECT' => $this->execSelect(),
            'INSERT' => $this->execInsert(false),
            'REPLACE' => $this->execInsert(true),
            'UPDATE' => $this->execUpdate(),
            'DELETE' => $this->execDelete(),
            'SHOW' => $this->execShow(),
            default => throw $this->unsupported('statement type ' . ($head === '' ? '(none)' : $head)),
        };
    }

    private function unsupported(string $what): \LogicException {
        return new \LogicException(
            "FakeWpdb: unsupported SQL -- $what. Extend the interpreter in"
            . ' sandbox/tests/lib/FakeWpdb.php rather than letting this read return null.'
            . " Statement: {$this->currentSql}"
        );
    }

    // ------------------------------------------------------------- lexer

    /**
     * Hand-written lexer. A regex-per-clause parser (which is what all 42
     * bespoke fakes do) cannot tell a keyword from the same bytes inside a
     * quoted option value, and captured option values in this product
     * routinely contain SQL-looking text.
     *
     * @return list<array{t:string,v:mixed}>
     */
    private function lex(string $sql): array {
        $tokens = [];
        $length = strlen($sql);
        $i = 0;
        while ($i < $length) {
            $c = $sql[$i];
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $i++;
                continue;
            }
            if ($c === '-' && substr($sql, $i, 3) === '-- ') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }
            if (substr($sql, $i, 2) === '/*') {
                $end = strpos($sql, '*/', $i);
                if ($end === false) {
                    throw $this->unsupported('unterminated comment');
                }
                $i = $end + 2;
                continue;
            }
            if ($c === "'" || $c === '"') {
                [$value, $i] = $this->lexString($sql, $i, $c);
                $tokens[] = ['t' => 'str', 'v' => $value];
                continue;
            }
            if ($c === '`') {
                $end = strpos($sql, '`', $i + 1);
                if ($end === false) {
                    throw $this->unsupported('unterminated identifier');
                }
                $tokens[] = ['t' => 'id', 'v' => substr($sql, $i + 1, $end - $i - 1)];
                $i = $end + 1;
                continue;
            }
            if (ctype_digit($c) || ($c === '.' && isset($sql[$i + 1]) && ctype_digit($sql[$i + 1]))) {
                preg_match('/\G[0-9]*\.?[0-9]+(?:[eE][-+]?[0-9]+)?/', $sql, $m, 0, $i);
                $text = $m[0];
                $tokens[] = ['t' => 'num', 'v' => str_contains($text, '.') || stripos($text, 'e') !== false
                    ? (float) $text
                    : (int) $text];
                $i += strlen($text);
                continue;
            }
            if (ctype_alpha($c) || $c === '_' || $c === '@') {
                preg_match('/\G[A-Za-z_@][A-Za-z0-9_$]*/', $sql, $m, 0, $i);
                $tokens[] = ['t' => 'word', 'v' => $m[0]];
                $i += strlen($m[0]);
                continue;
            }
            foreach (['<=>', '!=', '<>', '<=', '>='] as $operator) {
                if (substr($sql, $i, strlen($operator)) === $operator) {
                    $tokens[] = ['t' => 'op', 'v' => $operator];
                    $i += strlen($operator);
                    continue 2;
                }
            }
            if (str_contains('=<>(),.*+-/%', $c)) {
                $tokens[] = ['t' => 'op', 'v' => $c];
                $i++;
                continue;
            }
            throw $this->unsupported("unexpected character '$c'");
        }
        $tokens[] = ['t' => 'eof', 'v' => ''];
        return $tokens;
    }

    /**
     * MySQL string literal. Doubling ('') and backslash escapes are both
     * honoured; an unrecognised backslash escape KEEPS the backslash, which
     * is what makes esc_like()'s "\%" survive as two characters for the LIKE
     * evaluator to interpret.
     *
     * @return array{0:string,1:int}
     */
    private function lexString(string $sql, int $i, string $quote): array {
        $length = strlen($sql);
        $buffer = '';
        $i++;
        while ($i < $length) {
            $c = $sql[$i];
            if ($c === '\\' && $i + 1 < $length) {
                $next = $sql[$i + 1];
                $buffer .= match ($next) {
                    'n' => "\n",
                    't' => "\t",
                    'r' => "\r",
                    '0' => "\0",
                    'b' => chr(8),
                    'Z' => chr(26),
                    '\\', "'", '"' => $next,
                    default => '\\' . $next,
                };
                $i += 2;
                continue;
            }
            if ($c === $quote) {
                if (isset($sql[$i + 1]) && $sql[$i + 1] === $quote) {
                    $buffer .= $quote;
                    $i += 2;
                    continue;
                }
                return [$buffer, $i + 1];
            }
            $buffer .= $c;
            $i++;
        }
        throw $this->unsupported('unterminated string literal');
    }

    // ------------------------------------------------------ token cursor

    /** @return array{t:string,v:mixed} */
    private function peek(int $ahead = 0): array {
        return $this->tk[$this->tp + $ahead] ?? ['t' => 'eof', 'v' => ''];
    }

    private function keyword(int $ahead = 0): ?string {
        $token = $this->peek($ahead);
        return $token['t'] === 'word' ? strtoupper((string) $token['v']) : null;
    }

    private function acceptKeyword(string ...$words): bool {
        foreach ($words as $offset => $word) {
            if ($this->keyword($offset) !== $word) {
                return false;
            }
        }
        $this->tp += count($words);
        return true;
    }

    private function expectKeyword(string $word): void {
        if (!$this->acceptKeyword($word)) {
            throw $this->unsupported("expected $word");
        }
    }

    private function acceptOp(string $op): bool {
        $token = $this->peek();
        if ($token['t'] === 'op' && $token['v'] === $op) {
            $this->tp++;
            return true;
        }
        return false;
    }

    private function expectOp(string $op): void {
        if (!$this->acceptOp($op)) {
            throw $this->unsupported("expected '$op'");
        }
    }

    private function expectEnd(): void {
        if ($this->peek()['t'] !== 'eof') {
            throw $this->unsupported('trailing tokens after the statement');
        }
    }

    /** Clause keywords that can never be a table or column alias. */
    private const RESERVED = [
        'WHERE', 'GROUP', 'ORDER', 'LIMIT', 'OFFSET', 'SET', 'VALUES', 'FROM', 'INTO',
        'ON', 'AND', 'OR', 'NOT', 'IN', 'IS', 'LIKE', 'JOIN', 'INNER', 'LEFT', 'RIGHT',
        'OUTER', 'CROSS', 'UNION', 'HAVING', 'FORCE', 'USE', 'IGNORE', 'ASC', 'DESC',
        'DUPLICATE', 'KEY', 'BY', 'NULL', 'BINARY', 'DISTINCT', 'AS',
    ];

    // ------------------------------------------------------------ SELECT

    private function execSelect(): array {
        $this->expectKeyword('SELECT');
        $distinct = $this->acceptKeyword('DISTINCT');
        $items = [];
        do {
            $items[] = $this->parseSelectItem();
        } while ($this->acceptOp(','));

        $table = null;
        $alias = null;
        if ($this->acceptKeyword('FROM')) {
            $table = $this->parseTableRef();
            $alias = $this->parseAliasOpt();
            $this->skipIndexHint();
            if (in_array($this->keyword(), ['JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS', 'STRAIGHT_JOIN', 'UNION'], true)
                || ($this->peek()['t'] === 'op' && $this->peek()['v'] === ',')) {
                throw $this->unsupported('multi-table SELECT (JOIN/UNION)');
            }
        }
        $where = $this->acceptKeyword('WHERE') ? $this->parseCondition() : null;
        $group = [];
        if ($this->acceptKeyword('GROUP')) {
            $this->expectKeyword('BY');
            do {
                $group[] = $this->parseColumnRef()['name'];
            } while ($this->acceptOp(','));
        }
        if ($this->keyword() === 'HAVING') {
            throw $this->unsupported('HAVING');
        }
        $order = $this->parseOrderBy();
        [$limit, $offset] = $this->parseLimit();
        $this->expectEnd();

        if ($table === null) {
            // SELECT 1, SELECT GET_LOCK(...), SELECT RELEASE_LOCK(...)
            $row = [];
            foreach ($items as $index => $item) {
                if ($item['type'] !== 'expr') {
                    throw $this->unsupported('SELECT without FROM must select expressions');
                }
                $row[$this->itemName($item, $index)] = $this->evalOperand($item['expr'], [], null);
            }
            return ['kind' => 'rows', 'rows' => [$row]];
        }

        $name = $this->requireTable($table);
        $ctx = ['table' => $name, 'alias' => $alias, 'columns' => $this->knownColumns($name)];
        $matched = [];
        foreach ($this->store[$name] as $row) {
            if ($where === null || $this->evalCondition($where, $row, $ctx)) {
                $matched[] = $row;
            }
        }

        $aggregate = $group !== [];
        foreach ($items as $item) {
            $aggregate = $aggregate || $item['type'] === 'count';
        }

        if ($aggregate) {
            $out = $this->aggregate($items, $matched, $group, $ctx);
        } else {
            if ($order !== []) {
                $matched = $this->sortRows($matched, $order, $ctx);
            }
            $out = [];
            foreach ($matched as $row) {
                $out[] = $this->project($items, $row, $ctx);
            }
        }
        if ($distinct) {
            $seen = [];
            $unique = [];
            foreach ($out as $row) {
                $fingerprint = serialize($row);
                if (isset($seen[$fingerprint])) {
                    continue;
                }
                $seen[$fingerprint] = true;
                $unique[] = $row;
            }
            $out = $unique;
        }
        if ($aggregate && $order !== []) {
            $out = $this->sortRows($out, $order, ['table' => $name, 'alias' => $alias, 'columns' => null]);
        }
        if ($offset > 0) {
            $out = array_slice($out, $offset);
        }
        if ($limit !== null) {
            $out = array_slice($out, 0, $limit);
        }
        return ['kind' => 'rows', 'rows' => array_values($out)];
    }

    /** @return array{type:string,expr?:array,alias:?string,qualifier?:?string} */
    private function parseSelectItem(): array {
        if ($this->acceptOp('*')) {
            return ['type' => 'star', 'alias' => null, 'qualifier' => null];
        }
        // alias.*
        if (in_array($this->peek()['t'], ['word', 'id'], true)
            && $this->peek(1)['t'] === 'op' && $this->peek(1)['v'] === '.'
            && $this->peek(2)['t'] === 'op' && $this->peek(2)['v'] === '*') {
            $qualifier = (string) $this->peek()['v'];
            $this->tp += 3;
            return ['type' => 'star', 'alias' => null, 'qualifier' => $qualifier];
        }
        if ($this->keyword() === 'COUNT' && $this->peek(1)['t'] === 'op' && $this->peek(1)['v'] === '(') {
            $this->tp += 2;
            if (!$this->acceptOp('*')) {
                throw $this->unsupported('only COUNT(*) is interpreted');
            }
            $this->expectOp(')');
            return ['type' => 'count', 'alias' => $this->parseAliasOpt()];
        }
        $expr = $this->parseOperand();
        return ['type' => 'expr', 'expr' => $expr, 'alias' => $this->parseAliasOpt()];
    }

    private function parseAliasOpt(): ?string {
        if ($this->acceptKeyword('AS')) {
            $token = $this->peek();
            if (!in_array($token['t'], ['word', 'id'], true)) {
                throw $this->unsupported('expected an alias after AS');
            }
            $this->tp++;
            return (string) $token['v'];
        }
        $token = $this->peek();
        if ($token['t'] === 'id') {
            $this->tp++;
            return (string) $token['v'];
        }
        if ($token['t'] === 'word' && !in_array(strtoupper((string) $token['v']), self::RESERVED, true)) {
            $this->tp++;
            return (string) $token['v'];
        }
        return null;
    }

    private function skipIndexHint(): void {
        while (in_array($this->keyword(), ['FORCE', 'USE', 'IGNORE'], true)
            && in_array($this->keyword(1), ['INDEX', 'KEY'], true)) {
            $this->tp += 2;
            $this->expectOp('(');
            $depth = 1;
            while ($depth > 0) {
                $token = $this->peek();
                if ($token['t'] === 'eof') {
                    throw $this->unsupported('unterminated index hint');
                }
                if ($token['t'] === 'op' && $token['v'] === '(') {
                    $depth++;
                }
                if ($token['t'] === 'op' && $token['v'] === ')') {
                    $depth--;
                }
                $this->tp++;
            }
        }
    }

    private function parseTableRef(): string {
        $token = $this->peek();
        if (!in_array($token['t'], ['word', 'id'], true)) {
            throw $this->unsupported('expected a table name');
        }
        $this->tp++;
        if ($this->peek()['t'] === 'op' && $this->peek()['v'] === '.') {
            throw $this->unsupported('schema-qualified table names (e.g. information_schema)');
        }
        return $this->tableName((string) $token['v']);
    }

    /** @return list<array{column:array,dir:int}> */
    private function parseOrderBy(): array {
        if (!$this->acceptKeyword('ORDER')) {
            return [];
        }
        $this->expectKeyword('BY');
        $order = [];
        do {
            $column = $this->parseColumnRef();
            $dir = 1;
            if ($this->acceptKeyword('DESC')) {
                $dir = -1;
            } else {
                $this->acceptKeyword('ASC');
            }
            $order[] = ['column' => $column, 'dir' => $dir];
        } while ($this->acceptOp(','));
        return $order;
    }

    /** @return array{0:?int,1:int} [limit, offset] */
    private function parseLimit(): array {
        if (!$this->acceptKeyword('LIMIT')) {
            return [null, 0];
        }
        $first = $this->parseIntLiteral();
        if ($this->acceptOp(',')) {
            return [$this->parseIntLiteral(), $first];
        }
        if ($this->acceptKeyword('OFFSET')) {
            return [$first, $this->parseIntLiteral()];
        }
        return [$first, 0];
    }

    private function parseIntLiteral(): int {
        $token = $this->peek();
        if ($token['t'] !== 'num') {
            throw $this->unsupported('expected an integer literal');
        }
        $this->tp++;
        return (int) $token['v'];
    }

    // ------------------------------------------------------- expressions

    /** @return array{k:string,...} */
    private function parseOperand(): array {
        $left = $this->parseUnary();
        while (($this->peek()['t'] === 'op') && in_array($this->peek()['v'], ['+', '-'], true)) {
            $op = (string) $this->peek()['v'];
            $this->tp++;
            $left = ['k' => 'arith', 'op' => $op, 'l' => $left, 'r' => $this->parseUnary()];
        }
        return $left;
    }

    private function parseUnary(): array {
        if ($this->acceptKeyword('BINARY')) {
            return ['k' => 'binary', 'arg' => $this->parseUnary()];
        }
        if ($this->acceptOp('-')) {
            return ['k' => 'neg', 'arg' => $this->parseUnary()];
        }
        $token = $this->peek();
        if ($token['t'] === 'op' && $token['v'] === '(') {
            $this->tp++;
            $inner = $this->parseOperand();
            $this->expectOp(')');
            return $inner;
        }
        if ($token['t'] === 'str') {
            $this->tp++;
            return ['k' => 'lit', 'v' => $token['v'], 'label' => "'" . $token['v'] . "'"];
        }
        if ($token['t'] === 'num') {
            $this->tp++;
            return ['k' => 'lit', 'v' => $token['v'], 'label' => (string) $token['v']];
        }
        if ($token['t'] === 'word') {
            $upper = strtoupper((string) $token['v']);
            if ($upper === 'NULL') {
                $this->tp++;
                return ['k' => 'lit', 'v' => null, 'label' => 'NULL'];
            }
            if ($upper === 'TRUE' || $upper === 'FALSE') {
                $this->tp++;
                return ['k' => 'lit', 'v' => $upper === 'TRUE' ? 1 : 0, 'label' => $upper];
            }
            if ($this->peek(1)['t'] === 'op' && $this->peek(1)['v'] === '(') {
                return $this->parseFunction();
            }
        }
        return $this->parseColumnRef();
    }

    private function parseFunction(): array {
        $name = strtoupper((string) $this->peek()['v']);
        $this->tp += 2;
        $args = [];
        if (!$this->acceptOp(')')) {
            do {
                $args[] = $this->parseOperand();
            } while ($this->acceptOp(','));
            $this->expectOp(')');
        }
        if (!in_array($name, ['LENGTH', 'CHAR_LENGTH', 'GET_LOCK', 'RELEASE_LOCK', 'IS_FREE_LOCK'], true)) {
            throw $this->unsupported("SQL function $name()");
        }
        return ['k' => 'fn', 'name' => $name, 'args' => $args, 'label' => $name . '()'];
    }

    /** @return array{k:string,q:?string,name:string,label:string} */
    private function parseColumnRef(): array {
        $token = $this->peek();
        if (!in_array($token['t'], ['word', 'id'], true)) {
            throw $this->unsupported('expected a column name');
        }
        $this->tp++;
        $qualifier = null;
        $name = (string) $token['v'];
        if ($this->peek()['t'] === 'op' && $this->peek()['v'] === '.') {
            $this->tp++;
            $next = $this->peek();
            if (!in_array($next['t'], ['word', 'id'], true)) {
                throw $this->unsupported('expected a column name after "."');
            }
            $this->tp++;
            $qualifier = $name;
            $name = (string) $next['v'];
        }
        return ['k' => 'col', 'q' => $qualifier, 'name' => $name, 'label' => $name];
    }

    // -------------------------------------------------------- conditions

    private function parseCondition(): array {
        $parts = [$this->parseConjunction()];
        while ($this->acceptKeyword('OR')) {
            $parts[] = $this->parseConjunction();
        }
        return count($parts) === 1 ? $parts[0] : ['k' => 'or', 'parts' => $parts];
    }

    private function parseConjunction(): array {
        $parts = [$this->parsePredicate()];
        while ($this->acceptKeyword('AND')) {
            $parts[] = $this->parsePredicate();
        }
        return count($parts) === 1 ? $parts[0] : ['k' => 'and', 'parts' => $parts];
    }

    private function parsePredicate(): array {
        if ($this->acceptKeyword('NOT')) {
            return ['k' => 'not', 'inner' => $this->parsePredicate()];
        }
        if ($this->peek()['t'] === 'op' && $this->peek()['v'] === '('
            && $this->conditionFollows()) {
            $this->tp++;
            $inner = $this->parseCondition();
            $this->expectOp(')');
            return $inner;
        }
        $left = $this->parseOperand();
        if ($this->acceptKeyword('IS')) {
            $negate = $this->acceptKeyword('NOT');
            $this->expectKeyword('NULL');
            return ['k' => 'null', 'not' => $negate, 'l' => $left];
        }
        $negate = $this->acceptKeyword('NOT');
        if ($this->acceptKeyword('IN')) {
            $this->expectOp('(');
            $values = [];
            if (!$this->acceptOp(')')) {
                do {
                    $values[] = $this->parseOperand();
                } while ($this->acceptOp(','));
                $this->expectOp(')');
            }
            return ['k' => 'in', 'not' => $negate, 'l' => $left, 'values' => $values];
        }
        if ($this->acceptKeyword('LIKE')) {
            return ['k' => 'like', 'not' => $negate, 'l' => $left, 'r' => $this->parseOperand()];
        }
        if ($negate) {
            throw $this->unsupported('NOT without IN/LIKE');
        }
        $token = $this->peek();
        if ($token['t'] !== 'op'
            || !in_array($token['v'], ['=', '!=', '<>', '<', '<=', '>', '>=', '<=>'], true)) {
            throw $this->unsupported('expected a comparison operator');
        }
        $this->tp++;
        return ['k' => 'cmp', 'op' => (string) $token['v'], 'l' => $left, 'r' => $this->parseOperand()];
    }

    /**
     * Distinguish `( cond )` from `( expr )`: only a parenthesised group whose
     * body contains a bare comparison/AND/OR at depth 1 is a condition.
     */
    private function conditionFollows(): bool {
        $depth = 0;
        for ($i = $this->tp; $i < count($this->tk); $i++) {
            $token = $this->tk[$i];
            if ($token['t'] === 'op' && $token['v'] === '(') {
                $depth++;
                continue;
            }
            if ($token['t'] === 'op' && $token['v'] === ')') {
                $depth--;
                if ($depth === 0) {
                    return false;
                }
                continue;
            }
            if ($depth !== 1) {
                continue;
            }
            if ($token['t'] === 'op' && in_array($token['v'], ['=', '!=', '<>', '<', '<=', '>', '>='], true)) {
                return true;
            }
            if ($token['t'] === 'word'
                && in_array(strtoupper((string) $token['v']), ['AND', 'OR', 'IS', 'LIKE'], true)) {
                return true;
            }
        }
        return false;
    }

    // -------------------------------------------------------- evaluation

    /** @param array{table:string,alias:?string,columns:?list<string>} $ctx */
    private function evalCondition(array $node, array $row, array $ctx): bool {
        switch ($node['k']) {
            case 'and':
                foreach ($node['parts'] as $part) {
                    if (!$this->evalCondition($part, $row, $ctx)) {
                        return false;
                    }
                }
                return true;
            case 'or':
                foreach ($node['parts'] as $part) {
                    if ($this->evalCondition($part, $row, $ctx)) {
                        return true;
                    }
                }
                return false;
            case 'not':
                return !$this->evalCondition($node['inner'], $row, $ctx);
            case 'null':
                $isNull = $this->evalOperand($node['l'], $row, $ctx) === null;
                return $node['not'] ? !$isNull : $isNull;
            case 'in':
                $left = $this->evalOperand($node['l'], $row, $ctx);
                $found = false;
                foreach ($node['values'] as $value) {
                    if (self::compare($left, $this->evalOperand($value, $row, $ctx)) === 0) {
                        $found = true;
                        break;
                    }
                }
                return $node['not'] ? !$found && $left !== null : $found;
            case 'like':
                $left = $this->evalOperand($node['l'], $row, $ctx);
                if ($left === null) {
                    return false;
                }
                $matches = self::likeMatches(
                    (string) $this->evalOperand($node['r'], $row, $ctx),
                    (string) $left
                );
                return $node['not'] ? !$matches : $matches;
            case 'cmp':
                $binary = self::isBinary($node['l']) || self::isBinary($node['r']);
                $left = $this->evalOperand($node['l'], $row, $ctx);
                $right = $this->evalOperand($node['r'], $row, $ctx);
                if ($node['op'] === '<=>') {
                    return $left === null && $right === null
                        ? true
                        : ($left !== null && $right !== null && self::compare($left, $right) === 0);
                }
                $cmp = $binary ? self::compareBinary($left, $right) : self::compare($left, $right);
                if ($cmp === null) {
                    return false;
                }
                return match ($node['op']) {
                    '=' => $cmp === 0,
                    '!=', '<>' => $cmp !== 0,
                    '<' => $cmp < 0,
                    '<=' => $cmp <= 0,
                    '>' => $cmp > 0,
                    '>=' => $cmp >= 0,
                    default => throw $this->unsupported('comparison ' . $node['op']),
                };
        }
        throw $this->unsupported('condition node ' . $node['k']);
    }

    private static function isBinary(array $node): bool {
        return $node['k'] === 'binary';
    }

    /** @param array{table:string,alias:?string,columns:?list<string>}|null $ctx */
    private function evalOperand(array $node, array $row, ?array $ctx): mixed {
        switch ($node['k']) {
            case 'lit':
                return $node['v'];
            case 'binary':
                return $this->evalOperand($node['arg'], $row, $ctx);
            case 'neg':
                $value = $this->evalOperand($node['arg'], $row, $ctx);
                return $value === null ? null : -$value;
            case 'arith':
                $left = $this->evalOperand($node['l'], $row, $ctx);
                $right = $this->evalOperand($node['r'], $row, $ctx);
                if ($left === null || $right === null) {
                    return null;
                }
                return $node['op'] === '+' ? $left + $right : $left - $right;
            case 'fn':
                return $this->evalFunction($node, $row, $ctx);
            case 'col':
                return $this->evalColumn($node, $row, $ctx);
        }
        throw $this->unsupported('expression node ' . $node['k']);
    }

    private function evalFunction(array $node, array $row, ?array $ctx): mixed {
        $args = array_map(fn(array $arg): mixed => $this->evalOperand($arg, $row, $ctx), $node['args']);
        return match ($node['name']) {
            'LENGTH', 'CHAR_LENGTH' => $args[0] === null ? null : strlen((string) $args[0]),
            // Advisory locks are a live-MySQL concern; the fake reports a
            // configurable, deterministic result so the engine's lock branch
            // is exercisable without a server.
            'GET_LOCK', 'RELEASE_LOCK', 'IS_FREE_LOCK' => $this->lockResult,
            default => throw $this->unsupported('SQL function ' . $node['name']),
        };
    }

    private function evalColumn(array $node, array $row, ?array $ctx): mixed {
        $qualifier = $node['q'];
        if ($qualifier !== null && $ctx !== null) {
            $short = str_starts_with($ctx['table'], $this->prefix)
                ? substr($ctx['table'], strlen($this->prefix))
                : $ctx['table'];
            if ($qualifier !== $ctx['alias'] && $qualifier !== $ctx['table'] && $qualifier !== $short) {
                throw $this->unsupported("column qualifier '$qualifier' names another table (JOIN)");
            }
        }
        if (array_key_exists($node['name'], $row)) {
            return $row[$node['name']];
        }
        if ($ctx === null || $ctx['columns'] === null || in_array($node['name'], $ctx['columns'], true)) {
            return null;
        }
        throw new \LogicException(
            "FakeWpdb: unknown column '{$node['name']}' on table '{$ctx['table']}'. Seed it in a row"
            . " or declare it with setColumns(). Statement: {$this->currentSql}"
        );
    }

    /** @return list<string> */
    private function knownColumns(string $name): array {
        $columns = array_keys($this->columnTypes[$name] ?? []);
        foreach ($this->store[$name] ?? [] as $row) {
            foreach (array_keys($row) as $column) {
                if (!in_array($column, $columns, true)) {
                    $columns[] = (string) $column;
                }
            }
        }
        return $columns;
    }

    /**
     * Translate a MySQL LIKE pattern into a case-insensitive PCRE.
     *
     * Anchored with \A and \z, never ^ and $: without the D modifier PCRE's $
     * also matches immediately BEFORE a trailing newline, so `LIKE 'payload'`
     * would match the stored value "payload\n". Captured option and meta
     * payloads in this product routinely end with a newline, which is exactly
     * the value a keyspace or transient-prefix assertion runs against. /s is
     * kept so `%` and `_` still span newlines, as MySQL's do.
     */
    private static function likePattern(string $pattern, bool $utf8): string {
        $regex = '';
        $length = strlen($pattern);
        for ($i = 0; $i < $length; $i++) {
            $c = $pattern[$i];
            if ($c === '\\' && $i + 1 < $length) {
                $regex .= preg_quote($pattern[$i + 1], '~');
                $i++;
                continue;
            }
            $regex .= match ($c) {
                '%' => '.*',
                '_' => '.',
                default => preg_quote($c, '~'),
            };
        }
        return '~\A' . $regex . '\z~is' . ($utf8 ? 'u' : '');
    }

    /**
     * Evaluate one LIKE, choosing the character- or byte-wise pattern.
     *
     * MySQL's `_` matches one CHARACTER, so /u is the faithful mode. It is
     * only usable when the pattern AND the subject are valid UTF-8 -- with
     * /u a malformed subject makes preg_match() return false, which would
     * read as "no match" and quietly invert an assertion, and a malformed
     * pattern would emit a PCRE compilation warning that
     * offline_diagnostics_guard.sh turns into a suite failure. preg_match with
     * an empty //u pattern is the warning-free way to test that, and the
     * byte-wise fallback is what MySQL does for a binary column anyway.
     */
    private static function likeMatches(string $pattern, string $subject): bool {
        $utf8 = preg_match('//u', $pattern) === 1 && preg_match('//u', $subject) === 1;
        return preg_match(self::likePattern($pattern, $utf8), $subject) === 1;
    }

    // ------------------------------------------------- projection/sorting

    /**
     * Project one matched row.
     *
     * `*` expands to the table's FULL known column set (setColumns() types
     * unioned with every seeded row's keys), with null for the ones this
     * particular row omits -- not to the keys this row happens to carry.
     * seedTable() advertises sparse rows, and MySQL always returns the whole
     * column list with NULL for the unset ones; a projection that echoed the
     * sparseness would hand back result rows of DIFFERENT SHAPES, so engine
     * code doing `$row['autoload']` would emit "Undefined array key" (which
     * offline_diagnostics_guard.sh fails the suite on) or silently take a `??`
     * fallback, and ARRAY_N would put different columns at the same index in
     * different rows.
     *
     * @return array<string,mixed>
     */
    private function project(array $items, array $row, array $ctx): array {
        $out = [];
        foreach ($items as $index => $item) {
            if ($item['type'] === 'star') {
                $columns = $ctx['columns'] ?? null;
                foreach ($columns ?? array_keys($row) as $column) {
                    $out[$column] = $row[$column] ?? null;
                }
                continue;
            }
            if ($item['type'] === 'count') {
                throw $this->unsupported('COUNT(*) outside an aggregate SELECT');
            }
            $out[$this->itemName($item, $index)] = $this->evalOperand($item['expr'], $row, $ctx);
        }
        return $out;
    }

    private function itemName(array $item, int $index): string {
        if ($item['alias'] !== null) {
            return $item['alias'];
        }
        if ($item['type'] === 'count') {
            return 'COUNT(*)';
        }
        $expr = $item['expr'];
        return $expr['k'] === 'col' ? $expr['name'] : (string) ($expr['label'] ?? ('col' . $index));
    }

    /**
     * COUNT(*) with or without GROUP BY. Non-aggregate items take their value
     * from the first row of each group -- MySQL's ONLY_FULL_GROUP_BY would
     * reject that, but every grouped query in this engine selects exactly the
     * grouped columns plus COUNT(*).
     *
     * @return list<array<string,mixed>>
     */
    private function aggregate(array $items, array $rows, array $group, array $ctx): array {
        $groups = [];
        foreach ($rows as $row) {
            $key = [];
            foreach ($group as $column) {
                $key[] = (string) ($row[$column] ?? "\0NULL");
            }
            $groups[implode("\1", $key)][] = $row;
        }
        if ($group === [] && $groups === []) {
            $groups[''] = [];
        }
        $out = [];
        foreach ($groups as $members) {
            $first = $members[0] ?? [];
            $projected = [];
            foreach ($items as $index => $item) {
                if ($item['type'] === 'count') {
                    $projected[$this->itemName($item, $index)] = count($members);
                    continue;
                }
                if ($item['type'] === 'star') {
                    throw $this->unsupported('SELECT * alongside an aggregate');
                }
                $projected[$this->itemName($item, $index)] = $this->evalOperand($item['expr'], $first, $ctx);
            }
            $out[] = $projected;
        }
        return $out;
    }

    /** @param list<array{column:array,dir:int}> $order */
    private function sortRows(array $rows, array $order, array $ctx): array {
        usort($rows, function (array $a, array $b) use ($order, $ctx): int {
            foreach ($order as $term) {
                $left = $this->evalColumn($term['column'], $a, $ctx['columns'] === null ? null : $ctx);
                $right = $this->evalColumn($term['column'], $b, $ctx['columns'] === null ? null : $ctx);
                if ($left === null && $right === null) {
                    continue;
                }
                // MySQL sorts NULL first in ASC order.
                if ($left === null) {
                    return -$term['dir'];
                }
                if ($right === null) {
                    return $term['dir'];
                }
                $cmp = self::compare($left, $right) ?? 0;
                if ($cmp !== 0) {
                    return $cmp * $term['dir'];
                }
            }
            return 0;
        });
        return $rows;
    }

    // ------------------------------------------------- INSERT/UPDATE/DELETE

    private function execInsert(bool $replace): array {
        $this->tp++;
        $ignore = $this->acceptKeyword('IGNORE');
        if (!$replace) {
            $this->expectKeyword('INTO');
        } else {
            $this->acceptKeyword('INTO');
        }
        $table = $this->parseTableRef();
        if (!$this->acceptOp('(')) {
            throw $this->unsupported('INSERT without an explicit column list');
        }
        $columns = [];
        do {
            $token = $this->peek();
            if (!in_array($token['t'], ['word', 'id'], true)) {
                throw $this->unsupported('expected a column name in the INSERT column list');
            }
            $this->tp++;
            $columns[] = (string) $token['v'];
        } while ($this->acceptOp(','));
        $this->expectOp(')');
        $this->expectKeyword('VALUES');

        $tuples = [];
        do {
            $this->expectOp('(');
            $values = [];
            do {
                $values[] = $this->evalOperand($this->parseOperand(), [], null);
            } while ($this->acceptOp(','));
            $this->expectOp(')');
            if (count($values) !== count($columns)) {
                throw $this->unsupported('VALUES tuple does not match the column list');
            }
            $tuples[] = array_combine($columns, $values);
        } while ($this->acceptOp(','));

        $onDuplicate = null;
        if ($this->acceptKeyword('ON')) {
            $this->expectKeyword('DUPLICATE');
            $this->expectKeyword('KEY');
            $this->expectKeyword('UPDATE');
            $onDuplicate = $this->parseAssignments();
        }
        $this->expectEnd();

        $affected = 0;
        foreach ($tuples as $data) {
            if ($replace) {
                $affected += $this->removeUniqueConflicts($table, $data) + 1;
                $this->applyInsert($table, $data);
                continue;
            }
            if ($onDuplicate !== null || $ignore) {
                $conflict = $this->findUniqueConflict($table, $data);
                if ($conflict !== null) {
                    if ($ignore) {
                        continue;
                    }
                    $this->store[$table][$conflict] = $this->applyAssignments(
                        $this->store[$table][$conflict],
                        $onDuplicate,
                        $data,
                        $table
                    );
                    $affected++;
                    continue;
                }
            }
            $this->applyInsert($table, $data);
            $affected++;
        }
        return ['kind' => 'affected', 'affected' => $affected];
    }

    /** @return int|null index of the first row colliding on a declared unique key */
    private function findUniqueConflict(string $table, array $data): ?int {
        $keys = $this->uniqueKeys[$table] ?? [];
        if ($keys === []) {
            throw new \LogicException(
                "FakeWpdb: table '$table' has no unique key, so INSERT IGNORE / ON DUPLICATE KEY UPDATE"
                . " is undefined. Call setUniqueKey('$table', [...]) first. Statement: {$this->currentSql}"
            );
        }
        foreach ($this->store[$table] ?? [] as $index => $row) {
            if ($this->conflicts($row, $data, $keys)) {
                return $index;
            }
        }
        return null;
    }

    /** @return list<array{column:string,expr:array}> */
    private function parseAssignments(): array {
        $assignments = [];
        do {
            $column = $this->parseColumnRef();
            $this->expectOp('=');
            $assignments[] = ['column' => $column['name'], 'expr' => $this->parseAssignmentExpr()];
        } while ($this->acceptOp(','));
        return $assignments;
    }

    /** Handles the ON DUPLICATE KEY UPDATE-only VALUES(col) back-reference. */
    private function parseAssignmentExpr(): array {
        if ($this->keyword() === 'VALUES' && $this->peek(1)['t'] === 'op' && $this->peek(1)['v'] === '(') {
            $this->tp += 2;
            $column = $this->parseColumnRef();
            $this->expectOp(')');
            return ['k' => 'values', 'name' => $column['name']];
        }
        return $this->parseOperand();
    }

    /**
     * @param list<array{column:string,expr:array}> $assignments
     * @param array<string,mixed> $incoming values from the INSERT tuple
     */
    private function applyAssignments(array $row, array $assignments, array $incoming, string $table): array {
        $ctx = ['table' => $table, 'alias' => null, 'columns' => $this->knownColumns($table)];
        foreach ($assignments as $assignment) {
            $row[$assignment['column']] = $assignment['expr']['k'] === 'values'
                ? ($incoming[$assignment['expr']['name']] ?? null)
                : $this->evalOperand($assignment['expr'], $row, $ctx);
        }
        return $row;
    }

    private function execUpdate(): array {
        $this->expectKeyword('UPDATE');
        $table = $this->parseTableRef();
        $alias = $this->parseAliasOpt();
        $this->expectKeyword('SET');
        $assignments = $this->parseAssignments();
        $where = $this->acceptKeyword('WHERE') ? $this->parseCondition() : null;
        $this->parseOrderBy();
        [$limit] = $this->parseLimit();
        $this->expectEnd();

        $name = $this->requireTable($table);
        $ctx = ['table' => $name, 'alias' => $alias, 'columns' => $this->knownColumns($name)];
        $affected = 0;
        foreach ($this->store[$name] as $index => $row) {
            if ($limit !== null && $affected >= $limit) {
                break;
            }
            if ($where !== null && !$this->evalCondition($where, $row, $ctx)) {
                continue;
            }
            $updated = $this->applyAssignments($row, $assignments, [], $name);
            if ($updated !== $row) {
                $this->store[$name][$index] = $updated;
                $affected++;
            }
        }
        return ['kind' => 'affected', 'affected' => $affected];
    }

    private function execDelete(): array {
        $this->expectKeyword('DELETE');
        $this->expectKeyword('FROM');
        $table = $this->parseTableRef();
        $alias = $this->parseAliasOpt();
        $where = $this->acceptKeyword('WHERE') ? $this->parseCondition() : null;
        $this->parseOrderBy();
        [$limit] = $this->parseLimit();
        $this->expectEnd();

        $name = $this->requireTable($table);
        $ctx = ['table' => $name, 'alias' => $alias, 'columns' => $this->knownColumns($name)];
        $kept = [];
        $removed = 0;
        foreach ($this->store[$name] as $row) {
            $matches = $where === null || $this->evalCondition($where, $row, $ctx);
            if ($matches && ($limit === null || $removed < $limit)) {
                $removed++;
                continue;
            }
            $kept[] = $row;
        }
        $this->store[$name] = $kept;
        return ['kind' => 'affected', 'affected' => $removed];
    }

    // -------------------------------------------------------- SHOW / TX / DDL

    private function execShow(): array {
        $this->expectKeyword('SHOW');
        if ($this->acceptKeyword('TABLES')) {
            $pattern = null;
            if ($this->acceptKeyword('LIKE')) {
                $token = $this->peek();
                if ($token['t'] !== 'str') {
                    throw $this->unsupported('SHOW TABLES LIKE expects a string literal');
                }
                $this->tp++;
                $pattern = (string) $token['v'];
            }
            $this->expectEnd();
            $rows = [];
            foreach (array_keys($this->store) as $name) {
                if ($pattern === null || self::likeMatches($pattern, $name)) {
                    $rows[] = ['Tables_in_wordpress' => $name];
                }
            }
            return ['kind' => 'rows', 'rows' => $rows];
        }
        $this->acceptKeyword('FULL');
        if ($this->acceptKeyword('COLUMNS') || $this->acceptKeyword('FIELDS')) {
            $this->expectKeyword('FROM');
            $table = $this->parseTableRef();
            $this->expectEnd();
            $name = $this->requireTable($table);
            $types = $this->columnTypes[$name] ?? [];
            $rows = [];
            foreach ($this->knownColumns($name) as $column) {
                $rows[] = [
                    'Field' => $column,
                    'Type' => $types[$column] ?? 'longtext',
                    'Null' => 'YES',
                    'Key' => ($this->primaryKeys[$name] ?? null) === $column ? 'PRI' : '',
                    'Default' => null,
                    'Extra' => '',
                ];
            }
            return ['kind' => 'rows', 'rows' => $rows];
        }
        throw $this->unsupported('SHOW variant');
    }

    /**
     * Single-level transaction. START snapshots the ROWS, ROLLBACK restores
     * them, COMMIT drops the snapshot. Nesting is not modelled because MySQL
     * has no nested transactions either -- a second START would implicitly
     * commit, and no engine path relies on that.
     *
     * The AUTO_INCREMENT counter is deliberately NOT snapshotted: InnoDB
     * burns the ids consumed inside an aborted transaction and hands out the
     * next one after a rollback. That is load-bearing here rather than
     * pedantic -- simulateDeadlock() exists so a suite can drive Db.php's
     * TransientDbException retry, and the retry IS rollback-then-reinsert. A
     * fake that recycled the id would let a suite pin "the retry produced the
     * same local_id", which is false against the live target, where
     * Ledger::set() records a different one.
     */
    private function execTransaction(string $head): array {
        if ($head === 'START' || $head === 'BEGIN') {
            $this->transactionSnapshot = $this->store;
            return ['kind' => 'ok'];
        }
        if ($head === 'ROLLBACK' && $this->transactionSnapshot !== null) {
            $this->store = $this->transactionSnapshot;
        }
        $this->transactionSnapshot = null;
        return ['kind' => 'ok'];
    }

    /**
     * DDL is recorded rather than interpreted: the column definitions are the
     * live schema's business (regress_table_schema.php and the certification
     * matrix cover them). CREATE registers an empty table, DROP forgets it,
     * TRUNCATE empties it -- enough for the engine's install/uninstall paths.
     */
    private function execDdl(string $head): array {
        $this->ddlLog[] = $this->currentSql;
        $matched = preg_match(
            '/^(?:CREATE|DROP|TRUNCATE|ALTER)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?([A-Za-z0-9_$]+)`?/i',
            $this->currentSql,
            $m
        );
        if ($matched === 1) {
            $name = $this->tableName($m[1]);
            if ($head === 'CREATE') {
                $this->store[$name] ??= [];
            } elseif ($head === 'DROP') {
                unset($this->store[$name], $this->autoIncrement[$name], $this->columnTypes[$name]);
            } elseif ($head === 'TRUNCATE') {
                $this->store[$name] = [];
            }
        }
        return ['kind' => 'ok'];
    }

}

}
