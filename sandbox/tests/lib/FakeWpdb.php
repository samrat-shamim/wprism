<?php
/**
 * \WPrismTest\FakeWpdb -- one duck-typed $wpdb for the offline suites.
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
 * posture as agent/src/Kernel/Db.php, which refuses to treat wpdb's false as a
 * survivable value.
 *
 * INTEROPERATION WITH agent/src/Kernel/Db.php
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
 * the only positive signal, and it is always set here. The explicit
 * returnNextGetResultsAs() seam is the one exception: it models defensive
 * callers running behind a non-core wpdb-compatible driver that violates
 * this return contract, without teaching the SQL store another behavior.
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
 * which matters because check.php pushes strict ===: `wprism_check_same(19, ...)`
 * against a live meta_id is false, and a fake that returned int 19 would pin
 * it green. rows() reads the STORE, not a result set, so it keeps the seeded
 * PHP types; assert against get_*() when you are characterizing what the
 * engine sees. insert_id / rows_affected / num_rows stay ints, as on wpdb.
 *
 * SUPPORTED SQL GRAMMAR (everything else throws \LogicException):
 *
 *   SELECT [DISTINCT] <items> [FROM <table> [[AS] alias]
 *            [LEFT [OUTER] JOIN <table> [[AS] alias] ON <a>.<col> = <b>.<col>]]
 *          [INNER|LEFT [OUTER]] JOIN <table> [[AS] alias] ON <cond>
 *          [WHERE <cond>] [GROUP BY <cols>] [ORDER BY <cols> [ASC|DESC]]
 *          [LIMIT n [OFFSET m] | LIMIT m, n]
 *     items: * | alias.* | COUNT(*) | <literal> | [alias.]col | LENGTH(col)
 *            | OCTET_LENGTH(col) | SHA2(<operand>, 256) | LEFT(<operand>, <length>)
 *            | GET_LOCK(..) | RELEASE_LOCK(..) | IS_USED_LOCK(..)
 *            | CONNECTION_ID() | VERSION() | COALESCE(..) | SUM(..) | MAX(..)
 *            | CAST(.. AS CHAR), each with an optional AS alias
 *     cond:  AND / OR / parentheses over
 *            <operand> = != <> < <= > >= <operand>
 *            <operand> [NOT] IN (<values>)
 *            <operand> [NOT] LIKE <string>
 *            <operand> IS [NOT] NULL
 *     operand: column | literal | BINARY <operand> | LENGTH(<operand>)
 *              | OCTET_LENGTH(<operand>) | LEFT(<operand>, <count>)
 *              | SHA2(<operand>, 256)
 *              | <operand> + - <operand>
 *   INSERT [IGNORE] INTO t (cols) VALUES (...)[, (...)]
 *          [ON DUPLICATE KEY UPDATE col = <expr> ...]   (needs setUniqueKey)
 *   REPLACE INTO t (cols) VALUES (...)                  (needs setUniqueKey)
 *   UPDATE t SET col = <expr> [, ...] [WHERE <cond>] [LIMIT n]
 *   DELETE FROM t [WHERE <cond>] [LIMIT n]
 *   SHOW TABLES LIKE '<pattern>'      -> the table name, or null
 *   SHOW [FULL] COLUMNS FROM t        -> column rows (see setColumns/setColumnDefinitions)
 *   SHOW INDEX FROM t                 -> RECORDED index rows (setIndexes); a
 *                                        table with no fixture declines
 *   SHOW TABLE STATUS LIKE '<name>'   -> configured engine row (see setTableEngine)
 *   START TRANSACTION | BEGIN | COMMIT | ROLLBACK  (single-level, snapshotting)
 *   SET ...                           (accepted no-op)
 *   CREATE / ALTER / DROP / TRUNCATE  (recorded in ddlLog; DROP/TRUNCATE clear rows)
 *
 * The default admits exactly one join form: a SINGLE LEFT JOIN whose ON is
 * one equality between qualified columns. It is the engine's term-deletion
 * lookup, and its NULL-preserving result is load-bearing refusal evidence.
 * Wider INNER/LEFT joins exist only behind explicit bounded-fixture opt-ins;
 * subqueries, UNION, RIGHT/CROSS JOIN, and HAVING remain unsupported.
 * Aggregate expressions are evaluated only over an opted-in bounded result
 * set, never over an unbounded synthetic stream.
 *
 * Schema-qualified row inventories (information_schema.COLUMNS /
 * .STATISTICS) remain unsupported -- parseTableRef() refuses the `db.table`
 * form outright. The one narrow exception is an exact COUNT(*)/binary table
 * identity query against either inventory: providers use that compact witness
 * solely to bound a following SHOW transfer, whose rows still come from
 * setColumns()/setColumnDefinitions()/setIndexes(). SHOW schema probes are
 * otherwise supported only from explicit fixtures; no schema fact is inferred
 * from stored rows. Ledger::assert_read_only_schema() and
 * Ledger::prune_dead_table_map() (a multi-table DELETE) therefore remain
 * live-certification paths.
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

namespace WPrismTest {

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
    private const PLACEHOLDER_ESCAPE = '{wprism-fakewpdb-placeholder}';

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
    public string $dbname = 'wordpress';
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
    // Action Scheduler registers these non-core tables as wpdb properties.
    // The scheduler provider refuses a target whose runtime registration does
    // not match its site prefix, so the shared fake exposes that exact shape.
    public string $actionscheduler_actions = 'wp_actionscheduler_actions';
    public string $actionscheduler_claims = 'wp_actionscheduler_claims';
    public string $actionscheduler_groups = 'wp_actionscheduler_groups';
    public string $actionscheduler_logs = 'wp_actionscheduler_logs';

    /**
     * wpdb's own three table lists, at wpdb's own declared DEFAULTS
     * (class-wpdb.php:291-341, read from WP 7.0.3 and 7.1 sources). They are
     * PUBLIC and MUTABLE here because they are public and mutable there, and
     * that is the whole mechanism a fake with a hardcoded tables() could not
     * model: `ActionScheduler_Abstract_Schema::register_tables()` does
     * `$wpdb->tables[] = $table` (classes/abstracts/ActionScheduler_Abstract_Schema.php:54),
     * so every plugin bundling Action Scheduler appends four names to the
     * per-site list at runtime and tables('all') reports them exactly as it
     * reports `posts`. A suite reproduces that with one array push.
     *
     * The defaults are declared in ARRAY LITERALS, not computed in the
     * constructor, because Coverage::declared_core_tables() reads them back
     * through ReflectionClass::getDefaultProperties() — a fake that assigned
     * them at construction time would hand that reader an empty set and
     * exercise the degraded `core_source` path instead of the real one.
     *
     * @var list<string>
     */
    public array $tables = [
        'posts',
        'comments',
        'links',
        'options',
        'postmeta',
        'terms',
        'term_taxonomy',
        'term_relationships',
        'termmeta',
        'commentmeta',
    ];
    /** @var list<string> */
    public array $global_tables = ['users', 'usermeta'];
    /** @var list<string> */
    public array $ms_global_tables = [
        'blogs',
        'blogmeta',
        'signups',
        'site',
        'sitemeta',
        'registration_log',
    ];
    /**
     * Stands in for the `is_multisite()` branch inside wpdb::tables(), rather
     * than reaching for a global stub from a method that models one class:
     * core folds ms_global_tables into 'all' and 'global' only on multisite
     * (class-wpdb.php:1125-1136). Single-site by default, as every suite that
     * has ever called tables() here assumed.
     */
    public bool $multisite = false;

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
    /** @var array<string,array<string,array{Type:string,Null:string,Default:mixed,Extra:string}>|list<array<string,mixed>>> */
    private array $columnDefinitions = [];
    /** @var array<string,list<array{Key_name:string,Non_unique:int,Seq_in_index:int,Column_name:string,Sub_part:?int,Index_type:string}>> */
    private array $indexes = [];
    /** @var array<string,string> full table name => storage engine */
    private array $tableEngines = [];
    /** @var list<array{method:string,sql:string,error:string}> */
    private array $queryLog = [];
    /** @var list<string> */
    private array $ddlLog = [];
    /** @var null|callable(string,string,self):(bool|string|null) */
    private $queryHook = null;
    /** @var list<array{match:?string,error:string,remaining:int}> */
    private array $injectedFailures = [];
    /** @var list<array{match:?string,value:array|false|null}> explicit non-core driver return probes */
    private array $getResultsReturnOverrides = [];
    /** Full-apply offline fixtures may opt into the reviewed information_schema projection. */
    private bool $informationSchemaEnabled = false;
    /** Full-apply offline fixtures may opt into the reviewed apply-only SQL extensions. */
    private bool $fullApplySqlExtensionsEnabled = false;
    /** Opt-in for the Woo capture fixture's bounded joined reads. */
    private bool $joinedCaptureSqlEnabled = false;
    /** @var list<array{command:string,outcome:string}> one-shot transaction ambiguity probes */
    private array $transactionOutcomes = [];
    /** Reconnect immediately before the next transaction-state-bearing SELECT. */
    private bool $reconnectBeforeTransactionState = false;
    /** @var list<array{match:?string,remaining:int}> */
    private array $injectedAcknowledgements = [];
    /**
     * Row snapshot taken at START TRANSACTION. Deliberately does NOT include
     * $autoIncrement -- see execTransaction().
     *
     * @var null|array<string,list<array<string,mixed>>>
     */
    private ?array $transactionSnapshot = null;
    private int $lockResult = 1;

    /**
     * The connection this fake reports, and which advisory locks it holds.
     *
     * `agent/src/Kernel/ProcessFence.php` is a real shipped seam that every
     * promotion-lease path crosses: `acquire()` reads `CONNECTION_ID()` then
     * `GET_LOCK()`, and `isContinuous()` re-reads `CONNECTION_ID()` and asks
     * `IS_USED_LOCK()` whether THIS connection still holds the named fence
     * (`:27`, `:54-58`). Without those two functions no suite could drive
     * `PromotionLease::abort()` or any of its siblings through the library,
     * which is why two suites carry a hand-rolled `$wpdb` whose whole job is
     * to answer them (`offline/reference-scope/regress_scoped_promotion_target.php:181`,
     * `offline/code-half/regress_lifecycle_phase_handoff_unit.php:42`).
     *
     * The model is the one property those fakes fake: a lock is held by a
     * connection. `GET_LOCK` records the name when it succeeds, `RELEASE_LOCK`
     * forgets it, and `IS_USED_LOCK` answers with the holding connection id or
     * NULL — so `setConnectionId()` reproduces a reconnect, which is the
     * discontinuity ProcessFence exists to refuse. `IS_FREE_LOCK` keeps
     * returning `setLockResult()` verbatim: nothing under `agent/` calls it,
     * so it has no behaviour to model here.
     */
    private int $connectionId = 1;

    /** Full server banner returned by SELECT VERSION(). */
    private string $serverVersion = '8.0.36';
    /** Session isolation returned by MySQL's transaction-isolation variable. */
    private string $transactionIsolation = 'REPEATABLE-READ';
    /** One-shot isolation consumed by the next START TRANSACTION. */
    private ?string $nextTransactionIsolation = null;
    /** Isolation of the currently open transaction, for adversarial fixtures. */
    private ?string $activeTransactionIsolation = null;

    /** @var array<string,int> lock name => holding connection id */
    private array $heldLocks = [];
    /** @var array<string,int> simulated InnoDB row/range lock => connection id */
    private array $rowLocks = [];

    public function __construct(string $prefix = 'wp_') {
        $this->prefix = $prefix;
        $this->base_prefix = $prefix;
        foreach (self::CORE_TABLES as $name => $pk) {
            $this->$name = $prefix . $name;
            if ($pk !== null) {
                $this->primaryKeys[$prefix . $name] = $pk;
            }
        }
        foreach (['actions', 'claims', 'groups', 'logs'] as $suffix) {
            $property = 'actionscheduler_' . $suffix;
            $this->$property = $prefix . $property;
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

    /** Prefix a bare table suffix: table('wprism_kv') === 'wp_wprism_kv'. */
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
     * is for the product's own tables (wprism_map, wprism_state, wprism_kv, ...).
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

    /**
     * Configure exact SHOW FULL COLUMNS attributes without inferring schema
     * from seeded values. Definition order is physical ordinal order.
     *
     * The map form is used by older suites; the list form retains arbitrary
     * driver rows (including malformed or duplicate rows) for schema-boundary
     * refusals. Only valid Field/Type pairs from the list form widen ordinary
     * SELECT resolution.
     *
     * @param array<string,array{Type:string,Null:string,Default:mixed,Extra:string}>|list<array<string,mixed>> $definitions
     */
    public function setColumnDefinitions(string $table, array $definitions): self {
        $name = $this->tableName($table);
        if ($definitions === [] || array_is_list($definitions)) {
            $types = [];
            foreach ($definitions as $row) {
                $field = $row['Field'] ?? null;
                $type = $row['Type'] ?? null;
                if (is_string($field) && $field !== '' && is_string($type)) {
                    $types[$field] = $type;
                }
            }
            $this->columnTypes[$name] = $types;
            $this->columnDefinitions[$name] = array_values($definitions);
        } else {
            $this->columnDefinitions[$name] = $definitions;
            $this->columnTypes[$name] = array_map(
                static fn(array $definition): string => $definition['Type'],
                $definitions
            );
        }
        $this->store[$name] ??= [];
        return $this;
    }

    /**
     * @param list<array{Key_name:string,Non_unique:int,Seq_in_index:int,Column_name:string,Sub_part:?int,Index_type:string}> $indexes
     */
    public function setIndexes(string $table, array $indexes): self {
        $name = $this->tableName($table);
        $this->indexes[$name] = array_values($indexes);
        $this->store[$name] ??= [];
        return $this;
    }

    public function setTableEngine(string $table, string $engine): self {
        $name = $this->tableName($table);
        $this->store[$name] ??= [];
        $this->tableEngines[$name] = $engine;
        return $this;
    }

    /**
     * Enable the narrow information_schema projection required by the real
     * CaptureTransaction/TableSchema boundary. Ordinary suites keep the
     * default refusal so a new schema dependency cannot hide in a fake.
     */
    public function enableInformationSchema(): self {
        $this->informationSchemaEnabled = true;
        return $this;
    }

    /**
     * Enable the small SQL projection set required by the full ApplyRequestCoordinator
     * fixture. The default interpreter stays loud: a malformed or unrelated SELECT
     * cannot become fabricated apply statistics merely because it mentions a core table.
     */
    public function enableFullApplySqlExtensions(): self {
        $this->fullApplySqlExtensionsEnabled = true;
        return $this;
    }

    /**
     * Enable bounded joined SELECTs for the Woo capture fixture, which seeds
     * every participating table. Ordinary fixtures keep multi-table SQL closed.
     */
    public function enableJoinedCaptureSql(): self {
        $this->joinedCaptureSqlEnabled = true;
        return $this;
    }
    public function setTransactionIsolation(string $isolation): self {
        $this->transactionIsolation = $isolation;
        return $this;
    }

    /** @return array{session:string,next:?string,active:?string} */
    public function transactionIsolationState(): array {
        return [
            'session' => $this->transactionIsolation,
            'next' => $this->nextTransactionIsolation,
            'active' => $this->activeTransactionIsolation,
        ];
    }
    /** Result GET_LOCK() reports; 0 makes the engine's lock acquisition fail. */
    public function setLockResult(int $result): self {
        $this->lockResult = $result;
        return $this;
    }

    /**
     * The id `CONNECTION_ID()` reports. Changing it mid-suite is a reconnect:
     * every advisory lock a real server held on the old connection is gone,
     * which is the discontinuity `ProcessFence::isContinuous()` detects.
     */
    public function setConnectionId(int $id): self {
        // A real reconnect drops both advisory locks and the server-side
        // transaction. Restore the START snapshot before exposing the new
        // identity so continuity regressions cannot accidentally retain
        // writes that InnoDB would have rolled back on disconnect.
        if ($this->transactionSnapshot !== null) {
            $this->store = $this->transactionSnapshot;
            $this->transactionSnapshot = null;
        }
        $old = $this->connectionId;
        $this->connectionId = $id;
        foreach ($this->heldLocks as $name => $holder) {
            if ($holder === $old) {
                unset($this->heldLocks[$name]);
            }
        }
        foreach ($this->rowLocks as $name => $holder) {
            if ($holder === $old) {
                unset($this->rowLocks[$name]);
            }
        }
        $this->reconnectBeforeTransactionState = false;
        $this->nextTransactionIsolation = null;
        $this->activeTransactionIsolation = null;
        return $this;
    }

    /** Seed a foreign one-shot transaction characteristic before provider entry. */
    public function setNextTransactionIsolation(string $isolation): self {
        $this->nextTransactionIsolation = $isolation;
        return $this;
    }

    public function activeTransactionIsolation(): ?string {
        return $this->activeTransactionIsolation;
    }

    /** Share one simulated MySQL server's advisory-lock namespace. */
    public function shareAdvisoryLocksWith(self $other): self {
        $this->heldLocks =& $other->heldLocks;
        return $this;
    }

    /** Share a simulated database server while retaining separate sessions. */
    public function shareDatabaseStateWith(self $other): self {
        $this->store =& $other->store;
        $this->autoIncrement =& $other->autoIncrement;
        $this->primaryKeys =& $other->primaryKeys;
        $this->uniqueKeys =& $other->uniqueKeys;
        $this->columnTypes =& $other->columnTypes;
        $this->columnDefinitions =& $other->columnDefinitions;
        $this->indexes =& $other->indexes;
        $this->tableEngines =& $other->tableEngines;
        $this->rowLocks =& $other->rowLocks;
        return $this;
    }

    /** Configure the real server banner shared by VERSION() and db_version(). */
    public function setServerVersion(string $banner): self {
        if (preg_match('/^\s*\d+(?:\.\d+){1,3}/', $banner) !== 1) {
            throw new \InvalidArgumentException('FakeWpdb: server version must begin with a numeric version');
        }
        $this->serverVersion = $banner;
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
     * Override the next matching get_results() return after its SQL executes.
     * Core wpdb returns an array; null/false exist solely to prove a caller's
     * fail-closed handling of compatible-but-non-core database drivers.
     *
     * @param array<array-key,mixed>|false|null $value
     */
    public function returnNextGetResultsAs(array|false|null $value, ?string $matching = null): self {
        $this->getResultsReturnOverrides[] = ['match' => $matching, 'value' => $value];
        return $this;
    }

    /**
     * Inject one exact transaction-control outcome after ordinary query-hook
     * failures have been considered. The before/after distinction is the
     * property an ordinary failNextQuery() cannot model: a real driver may
     * report COMMIT failure after the server durably applied it.
     */
    public function injectTransactionOutcome(string $command, string $outcome): self {
        $command = strtoupper(trim($command));
        $outcomes = [
            'before_false',
            'before_throw',
            'after_false',
            'after_throw',
            'after_reconnect',
            'inactive_false',
            'success_no_apply',
            'success_no_apply_reconnect_before_state',
            'success_probe_error',
            'success_no_apply_probe_error',
        ];
        if (!in_array($command, ['START', 'BEGIN', 'COMMIT', 'ROLLBACK'], true)
            || !in_array($outcome, $outcomes, true)) {
            throw new \InvalidArgumentException('FakeWpdb: unsupported transaction outcome probe');
        }
        $this->transactionOutcomes[] = ['command' => $command, 'outcome' => $outcome];
        return $this;
    }

    public function clearTransactionOutcomes(): self {
        $this->transactionOutcomes = [];
        return $this;
    }

    /**
     * Report a successful statement acknowledgement without applying it.
     *
     * Drivers and proxies can lose or misclassify transaction acknowledgements;
     * this seam pins callers that must prove server state instead of trusting a
     * truthy wpdb::query() result.
     */
    public function acknowledgeNextQueryWithoutExecution(?string $matching = null, int $times = 1): self {
        $this->injectedAcknowledgements[] = ['match' => $matching, 'remaining' => $times];
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
        preg_match('/^\s*(\d+(?:\.\d+){1,3})/', $this->serverVersion, $match);
        return $match[1];
    }

    public function check_connection(bool $allow_bail = true): bool {
        return true;
    }

    public function close(): bool {
        return true;
    }

    /**
     * `logical name => prefixed name` for the requested scope, composed the
     * way wpdb::tables() composes it (class-wpdb.php:1122-1177): from the
     * INSTANCE properties above, so a table a plugin registered at runtime
     * appears here indistinguishably from `posts` — which is precisely the
     * observation Coverage::tables_report() has to see through. Reading the
     * lists instead of a private const is what makes that reproducible; the
     * default single-site 'all' result is byte-identical to the twelve names
     * the hardcoded version returned, modulo order (global tables first, as
     * core emits them).
     *
     * ms_global tables join only under multisite, matching core's own
     * `is_multisite()` branch; this fake is single-site unless a suite says
     * otherwise via $multisite.
     *
     * @return array<string,string>
     */
    public function tables(string $scope = 'all', bool $prefix = true, int $blog_id = 0): array {
        $names = match ($scope) {
            'all' => $this->multisite
                ? array_merge($this->global_tables, $this->tables, $this->ms_global_tables)
                : array_merge($this->global_tables, $this->tables),
            'blog' => $this->tables,
            'global' => $this->multisite
                ? array_merge($this->global_tables, $this->ms_global_tables)
                : $this->global_tables,
            'ms_global' => $this->ms_global_tables,
            default => [],
        };
        $globals = array_merge($this->global_tables, $this->ms_global_tables);
        $out = [];
        foreach ($names as $name) {
            $name = (string) $name;
            $tablePrefix = in_array($name, $globals, true) ? $this->base_prefix : $this->prefix;
            $out[$name] = $prefix ? $tablePrefix . $name : $name;
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
        if ($this->informationSchemaEnabled && str_contains(strtolower($query), 'information_schema.')) {
            $lower = strtolower($query);
            if (str_contains($lower, 'information_schema.tables')) {
                $rows = $this->informationSchemaRows($query, 'tables');
                // get_var() returns the first PROJECTED column, not the first
                // field in informationSchemaRows()'s shared inventory shape.
                // PromotionLease's transactional-storage gate selects ENGINE
                // alone; returning TABLE_NAME here would falsely classify an
                // explicitly seeded InnoDB ledger as nontransactional.
                if (preg_match('/^\s*SELECT\s+ENGINE\s+FROM\s+information_schema\.TABLES\b/i', $query) === 1) {
                    $rows = array_map(
                        static fn(array $row): array => ['ENGINE' => $row['ENGINE'] ?? null],
                        $rows
                    );
                }
            } elseif (str_contains($lower, 'character_maximum_length')) {
                preg_match("/TABLE_NAME\s*=\s*'([^']+)'/i", $query, $tableMatch);
                preg_match("/COLUMN_NAME\s*=\s*'([^']+)'/i", $query, $columnMatch);
                $table = (string) ($tableMatch[1] ?? '');
                $column = (string) ($columnMatch[1] ?? '');
                $type = $this->columnTypes[$table][$column] ?? '';
                $rows = [['CHARACTER_MAXIMUM_LENGTH' => preg_match('/\((\d+)\)/', $type, $length) === 1 ? $length[1] : null]];
            } elseif (str_contains($lower, 'information_schema.columns')) {
                $rows = $this->informationSchemaRows($query, 'generic');
            } else {
                throw $this->unsupported('unsupported opt-in information_schema shape');
            }
            return isset($rows[$y]) ? self::outbound(array_values($rows[$y])[$x] ?? null) : null;
        }
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
        if ($this->fullApplySqlExtensionsEnabled) {
            $witness = $this->fullApplyCanonicalPostWitnessRow($query);
            if ($witness !== false) {
                return is_array($witness) ? $this->shape($witness, $output) : null;
            }
        }
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
     * @return array<array-key,array<string,?string>|object>|false|null
     */
    public function get_results(string $query, string $output = OBJECT): array|false|null {
        if ($this->informationSchemaEnabled && str_contains(strtolower($query), 'information_schema.')) {
            $lower = strtolower($query);
            if (!str_contains($lower, 'information_schema.tables')
                && !str_contains($lower, 'information_schema.columns')) {
                throw $this->unsupported('unsupported opt-in information_schema shape');
            }
            $rows = $this->informationSchemaRows($query, 'generic');
            if ($output === OBJECT_K) {
                $keyed = [];
                foreach ($rows as $row) {
                    $keyed[(string) reset($row)] = $this->shape($row, OBJECT);
                }
                return $keyed;
            }
            return array_map(fn(array $row): array|object => $this->shape($row, $output), $rows);
        }
        if ($this->fullApplySqlExtensionsEnabled && $this->isFullApplyAttachmentMarkerQuery($query)) {
            $rows = [];
            foreach ($this->store[$this->tableName('wprism_kv')] ?? [] as $row) {
                $key = (string) ($row['k'] ?? '');
                if (!str_starts_with(strtolower($key), 'attachment_fs:')) continue;
                $value = (string) ($row['v'] ?? '');
                $rows[] = [
                    'k' => $key,
                    'v_bytes' => strlen($value),
                    'bounded_v' => strlen($value) <= 512 ? $value : null,
                ];
            }
            usort($rows, static fn(array $a, array $b): int => strcmp($a['k'], $b['k']));
            $rows = array_slice($rows, 0, 2);
            return array_map(fn(array $row): array|object => $this->shape($row, $output), $rows);
        }
        if ($this->fullApplySqlExtensionsEnabled && $this->isFullApplyPostStatsQuery($query)) {
            $rows = $this->capturePostRowsForFullApply($query);
            $bytes = static function (array $row): int {
                $columns = ['ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'post_modified', 'post_modified_gmt', 'post_parent', 'menu_order', 'post_type', 'post_mime_type'];
                $total = 0;
                foreach ($columns as $column) $total += strlen((string) ($row[$column] ?? ''));
                return $total;
            };
            $stats = [['row_count' => count($rows), 'total_bytes' => array_sum(array_map($bytes, $rows)), 'max_row_bytes' => $rows === [] ? 0 : max(array_map($bytes, $rows))]];
            return array_map(fn(array $row): array|object => $this->shape($row, $output), $stats);
        }
        if ($this->fullApplySqlExtensionsEnabled && $this->isFullApplyOptionsStatsQuery($query)) {
            $rows = $this->store[$this->tableName('options')] ?? [];
            $nameBytes = array_map(static fn(array $row): int => strlen((string) ($row['option_name'] ?? '')), $rows);
            $valueBytes = array_map(static fn(array $row): int => strlen((string) ($row['option_value'] ?? '')), $rows);
            $stats = [[
                'row_count' => count($rows),
                'total_bytes' => array_sum($nameBytes) + array_sum($valueBytes),
                'max_name_bytes' => $nameBytes === [] ? 0 : max($nameBytes),
                'max_name_characters' => $nameBytes === [] ? 0 : max($nameBytes),
                'max_value_bytes' => $valueBytes === [] ? 0 : max($valueBytes),
            ]];
            return array_map(fn(array $row): array|object => $this->shape($row, $output), $stats);
        }
        if ($this->fullApplySqlExtensionsEnabled && $this->isFullApplyPostGroupsQuery($query)) {
            $groups = [];
            foreach ($this->capturePostRowsForFullApply($query) as $row) $groups[(string) $row['post_type']] = ($groups[(string) $row['post_type']] ?? 0) + 1;
            $rows = [];
            foreach ($groups as $type => $count) $rows[] = ['post_type' => $type, 'entities' => $count];
            ksort($rows);
            return array_map(fn(array $row): array|object => $this->shape($row, $output), $rows);
        }
        if ($this->fullApplySqlExtensionsEnabled && $this->isFullApplyPostListQuery($query)) {
            $rows = $this->capturePostRowsForFullApply($query);
            usort($rows, static fn(array $a, array $b): int => ((int) $a['ID']) <=> ((int) $b['ID']));
            return array_map(fn(array $row): array|object => $this->shape($row, $output), $rows);
        }
        if ($this->fullApplySqlExtensionsEnabled && $this->isFullApplyTermsQuery($query)) {
            return [];
        }
        $result = $this->run('get_results', $query);
        foreach ($this->getResultsReturnOverrides as $index => $override) {
            if ($override['match'] !== null && !str_contains($query, $override['match'])) {
                continue;
            }
            array_splice($this->getResultsReturnOverrides, $index, 1);
            return $override['value'];
        }
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

    private function fullApplySql(string $query): string {
        $normalized = preg_replace('/\s+/', ' ', trim($query));
        return is_string($normalized) ? $normalized : trim($query);
    }

    private function fullApplyPostBytes(): string {
        return "OCTET_LENGTH(CAST(ID AS CHAR)) + OCTET_LENGTH(CAST(post_author AS CHAR)) "
            . "+ OCTET_LENGTH(COALESCE(post_date,'')) + OCTET_LENGTH(COALESCE(post_date_gmt,'')) "
            . "+ OCTET_LENGTH(COALESCE(post_content,'')) + OCTET_LENGTH(COALESCE(post_title,'')) "
            . "+ OCTET_LENGTH(COALESCE(post_excerpt,'')) + OCTET_LENGTH(COALESCE(post_status,'')) "
            . "+ OCTET_LENGTH(COALESCE(comment_status,'')) + OCTET_LENGTH(COALESCE(ping_status,'')) "
            . "+ OCTET_LENGTH(COALESCE(post_password,'')) + OCTET_LENGTH(COALESCE(post_name,'')) "
            . "+ OCTET_LENGTH(COALESCE(post_modified,'')) + OCTET_LENGTH(COALESCE(post_modified_gmt,'')) "
            . "+ OCTET_LENGTH(CAST(post_parent AS CHAR)) + OCTET_LENGTH(CAST(menu_order AS CHAR)) "
            . "+ OCTET_LENGTH(COALESCE(post_type,'')) + OCTET_LENGTH(COALESCE(post_mime_type,''))";
    }

    private function isFullApplyAttachmentMarkerQuery(string $query): bool {
        return $this->fullApplySql($query) === 'SELECT k, OCTET_LENGTH(v) AS v_bytes, '
            . 'CASE WHEN v IS NOT NULL AND OCTET_LENGTH(v) <= 512 THEN v ELSE NULL END AS bounded_v '
            . 'FROM `' . $this->tableName('wprism_kv') . '` WHERE LOWER(LEFT(k, 14)) = \'attachment_fs:\' '
            . 'ORDER BY BINARY k ASC LIMIT 2';
    }

    private function isFullApplyPostStatsQuery(string $query): bool {
        $bytes = $this->fullApplyPostBytes();
        return $this->fullApplySql($query) === 'SELECT COUNT(*) AS row_count, COALESCE(SUM('
            . $bytes . '), 0) AS total_bytes, COALESCE(MAX(' . $bytes . '), 0) AS max_row_bytes '
            . 'FROM ' . $this->tableName('posts') . " WHERE (post_type = 'attachment' AND post_status = 'inherit')";
    }

    private function isFullApplyOptionsStatsQuery(string $query): bool {
        return $this->fullApplySql($query) === 'SELECT COUNT(*) AS row_count, '
            . 'COALESCE(SUM(OCTET_LENGTH(option_name) + OCTET_LENGTH(option_value)), 0) AS total_bytes, '
            . 'COALESCE(MAX(OCTET_LENGTH(option_name)), 0) AS max_name_bytes, '
            . 'COALESCE(MAX(CHAR_LENGTH(option_name)), 0) AS max_name_characters, '
            . 'COALESCE(MAX(OCTET_LENGTH(option_value)), 0) AS max_value_bytes '
            . 'FROM ' . $this->tableName('options');
    }

    private function isFullApplyPostGroupsQuery(string $query): bool {
        return $this->fullApplySql($query) === 'SELECT post_type, COUNT(*) AS entities FROM '
            . $this->tableName('posts') . " WHERE (post_status IN ('publish','draft','pending','private','future') "
            . "OR (post_type = 'attachment' AND post_status = 'inherit')) GROUP BY post_type "
            . 'ORDER BY post_type ASC LIMIT 4097';
    }

    private function isFullApplyPostListQuery(string $query): bool {
        return $this->fullApplySql($query) === 'SELECT ID, post_author, post_date, post_date_gmt, '
            . 'post_content, post_title, post_excerpt, post_status, comment_status, ping_status, post_password, '
            . 'post_name, post_modified, post_modified_gmt, post_parent, menu_order, post_type, post_mime_type '
            . 'FROM ' . $this->tableName('posts')
            . " WHERE (post_type = 'attachment' AND post_status = 'inherit') ORDER BY ID ASC LIMIT 1000001";
    }

    private function isFullApplyTermsQuery(string $query): bool {
        return $this->fullApplySql($query) === 'SELECT t.term_id, t.name, t.slug, tt.term_taxonomy_id, '
            . 'tt.taxonomy, tt.description, tt.parent FROM ' . $this->tableName('terms')
            . ' t JOIN ' . $this->tableName('term_taxonomy')
            . " tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'nav_menu' ORDER BY t.term_id ASC";
    }

    /**
     * Exact post/_wprism_uuid join used by the production pre-prune identity
     * guard. false means this is a different query; null is its real absent
     * result, preserving LEFT JOIN semantics for a post without the sidecar.
     *
     * @return array<string,mixed>|false|null
     */
    private function fullApplyCanonicalPostWitnessRow(string $query): array|false|null {
        $posts = preg_quote($this->tableName('posts'), '~');
        $postmeta = preg_quote($this->tableName('postmeta'), '~');
        $pattern = '~^SELECT p\.ID, p\.post_type, pm\.meta_value AS wprism_uuid FROM '
            . $posts . ' p LEFT JOIN ' . $postmeta
            . " pm ON pm\.post_id = p\.ID AND pm\.meta_key = '_wprism_uuid'"
            . ' WHERE p\.ID = ([0-9]+) ORDER BY pm\.meta_id ASC LIMIT 1$~';
        if (preg_match($pattern, $this->fullApplySql($query), $match) !== 1) {
            return false;
        }
        $postId = (int) $match[1];
        $post = null;
        foreach ($this->store[$this->tableName('posts')] ?? [] as $row) {
            if ((int) ($row['ID'] ?? 0) === $postId) {
                $post = $row;
                break;
            }
        }
        if (!is_array($post)) {
            return null;
        }
        $meta = array_values(array_filter(
            $this->store[$this->tableName('postmeta')] ?? [],
            static fn(array $row): bool => (int) ($row['post_id'] ?? 0) === $postId
                && (string) ($row['meta_key'] ?? '') === '_wprism_uuid'
        ));
        usort($meta, static fn(array $a, array $b): int =>
            (int) ($a['meta_id'] ?? 0) <=> (int) ($b['meta_id'] ?? 0)
        );
        return [
            'ID' => $postId,
            'post_type' => (string) ($post['post_type'] ?? ''),
            'wprism_uuid' => isset($meta[0]) ? (string) ($meta[0]['meta_value'] ?? '') : null,
        ];
    }

    private function isFullApplyPromotionInsertQuery(string $query): bool {
        $normalized = $this->fullApplySql($query);
        $prefix = "INSERT INTO `{$this->tableName('wprism_kv')}` (k, v) VALUES ('promotion_lock', '";
        $delimiter = "') ON DUPLICATE KEY UPDATE v = IF( ( ";
        if (!str_starts_with($normalized, $prefix)) return false;
        $delimiterPosition = strpos($normalized, $delimiter, strlen($prefix));
        if ($delimiterPosition === false) return false;
        $payload = json_decode(stripslashes(substr($normalized, strlen($prefix), $delimiterPosition - strlen($prefix))), true);
        if (!is_array($payload)
            || array_keys($payload) !== ['owner', 'artifact_hash', 'phase', 'acquired_at', 'expires_at']
            || !is_string($payload['owner'])
            || preg_match('/^[a-z0-9-]+$/D', $payload['owner']) !== 1
            || !is_string($payload['artifact_hash'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['artifact_hash']) !== 1
            || !is_string($payload['phase'])
            || preg_match('/^[a-z0-9-]+$/D', $payload['phase']) !== 1
            || !is_int($payload['acquired_at'])
            || !is_int($payload['expires_at'])) {
            return false;
        }
        $suffix = "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v, '$.expires_at')), '0') AS UNSIGNED) <= {$payload['acquired_at']} "
            . "AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.owner')) <> '{$payload['owner']}' ) OR ( "
            . "CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v, '$.expires_at')), '0') AS UNSIGNED) > {$payload['acquired_at']} "
            . "AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.owner')) = '{$payload['owner']}' "
            . "AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.artifact_hash')) = '{$payload['artifact_hash']}' ), VALUES(v), v )";
        return substr($normalized, $delimiterPosition + strlen($delimiter)) === $suffix;
    }

    private function isFullApplyPromotionUpdateQuery(string $query): bool {
        $normalized = $this->fullApplySql($query);
        $prefix = "UPDATE `{$this->tableName('wprism_kv')}` SET v = '";
        $delimiter = "' WHERE k = 'promotion_lock' AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.owner')) = '";
        if (!str_starts_with($normalized, $prefix)) return false;
        $delimiterPosition = strpos($normalized, $delimiter, strlen($prefix));
        if ($delimiterPosition === false) return false;
        $payload = json_decode(stripslashes(substr($normalized, strlen($prefix), $delimiterPosition - strlen($prefix))), true);
        if (!is_array($payload)
            || array_keys($payload) !== ['owner', 'artifact_hash', 'phase', 'acquired_at', 'expires_at']
            || !is_string($payload['owner'])
            || preg_match('/^[a-z0-9-]+$/D', $payload['owner']) !== 1
            || !is_string($payload['artifact_hash'])
            || preg_match('/^[a-f0-9]{64}$/D', $payload['artifact_hash']) !== 1
            || !is_string($payload['phase'])
            || preg_match('/^[a-z0-9-]+$/D', $payload['phase']) !== 1
            || !is_int($payload['acquired_at'])
            || !is_int($payload['expires_at'])) {
            return false;
        }
        $suffix = "{$payload['owner']}' AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.artifact_hash')) = '{$payload['artifact_hash']}'";
        return substr($normalized, $delimiterPosition + strlen($delimiter)) === $suffix;
    }

    private function isFullApplyPruneQuery(string $query): bool {
        $map = $this->tableName('wprism_map');
        $expected = [
            "DELETE m FROM $map m LEFT JOIN {$this->tableName('posts')} po ON po.ID = m.local_id WHERE m.id_kind = 'post' AND po.ID IS NULL",
            "DELETE m FROM $map m LEFT JOIN {$this->tableName('terms')} t ON t.term_id = m.local_id WHERE m.id_kind = 'term' AND t.term_id IS NULL",
            "DELETE m FROM $map m LEFT JOIN {$this->tableName('term_taxonomy')} tt ON tt.term_taxonomy_id = m.local_id WHERE m.id_kind = 'term_taxonomy' AND tt.term_taxonomy_id IS NULL",
        ];
        return in_array($this->fullApplySql($query), $expected, true);
    }

    /** @return list<array<string,mixed>> */
    private function capturePostRowsForFullApply(string $query): array {
        $rows = [];
        foreach ($this->store[$this->tableName('posts')] ?? [] as $row) {
            $type = (string) ($row['post_type'] ?? '');
            $status = (string) ($row['post_status'] ?? '');
            if (!(($type === 'attachment' && $status === 'inherit')
                || in_array($status, ['publish', 'draft', 'pending', 'private', 'future'], true))) continue;
            $rows[] = $row;
        }
        return $rows;
    }

    /** @return list<array<string,string>> */
    private function informationSchemaRows(string $query, string $kind): array {
        preg_match_all("/'((?:''|[^'])*)'/", $query, $matches);
        $wanted = array_values(array_filter(array_map(
            static fn(string $value): string => str_replace("''", "'", $value),
            $matches[1] ?? []
        ), static fn(string $value): bool => $value !== ''));
        $tables = [];
        foreach (array_keys($this->store) as $table) {
            if ($wanted !== [] && !in_array($table, $wanted, true)) {
                continue;
            }
            $tables[$table] = true;
        }
        if (str_contains(strtolower($query), 'information_schema.tables')) {
            return array_map(
                fn(string $table): array => ['TABLE_NAME' => $table, 'ENGINE' => $this->tableEngines[$table] ?? 'InnoDB'],
                array_keys($tables)
            );
        }
        $rows = [];
        foreach (array_keys($tables) as $table) {
            $columns = array_keys($this->columnTypes[$table] ?? []);
            foreach ($this->store[$table] ?? [] as $row) {
                $columns = array_values(array_unique(array_merge($columns, array_keys($row))));
            }
            foreach ($columns as $column) {
                $rows[] = ['TABLE_NAME' => $table, 'COLUMN_NAME' => $column];
            }
        }
        return $rows;
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
        $name = $this->tableName($table);
        if ($this->writeConflictsWithLocks($name, $data, [])) {
            return $this->fail('insert', $sql, 'simulated InnoDB row lock wait timeout');
        }
        $this->applyInsert($name, $data);
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
        if ($this->writeConflictsWithLocks($name, $data, $where)) {
            return $this->fail('update', $sql, 'simulated InnoDB row lock wait timeout');
        }
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
        if ($this->writeConflictsWithLocks($name, [], $where)) {
            return $this->fail('delete', $sql, 'simulated InnoDB row lock wait timeout');
        }
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
        foreach ($this->injectedAcknowledgements as $index => $acknowledgement) {
            if ($acknowledgement['remaining'] <= 0
                || ($acknowledgement['match'] !== null
                    && !str_contains($sql, $acknowledgement['match']))) {
                continue;
            }
            $this->injectedAcknowledgements[$index]['remaining']--;
            $this->log($method, $sql);
            return ['kind' => 'ok'];
        }
        $transactionOutcome = $this->takeTransactionOutcome($sql);
        if ($transactionOutcome === 'before_false') {
            $this->fail($method, $sql, 'injected transaction failure before server apply');
            return null;
        }
        if ($transactionOutcome === 'before_throw') {
            throw new \RuntimeException('injected transaction exception before server apply');
        }
        // PromotionLease's fenced upsert deliberately uses JSON_EXTRACT in
        // its conditional duplicate clause. Keep the ordinary SQL grammar
        // loud, but model this one reviewed target-lease statement so a full
        // apply fixture can exercise the real lease lifecycle without a
        // second hand-written database fake.
        if ($this->fullApplySqlExtensionsEnabled && $this->isFullApplyPromotionInsertQuery($sql)) {
            if (preg_match("/VALUES \('promotion_lock', '((?:\\\\'|[^'])*)'\)/", $sql, $match) !== 1) {
                throw $this->unsupported('malformed promotion_lock JSON upsert');
            }
            $value = stripslashes($match[1]);
            $rows = $this->store[$this->tableName('wprism_kv')] ?? [];
            $found = false;
            foreach ($rows as &$row) {
                if (($row['k'] ?? null) === 'promotion_lock') {
                    $row['v'] = $value;
                    $found = true;
                    break;
                }
            }
            unset($row);
            if (!$found) $rows[] = ['k' => 'promotion_lock', 'v' => $value];
            $this->store[$this->tableName('wprism_kv')] = $rows;
            $this->log('query', $sql);
            $this->rows_affected = 1;
            return ['kind' => 'affected', 'affected' => 1];
        }
        if ($this->fullApplySqlExtensionsEnabled && $this->isFullApplyPromotionUpdateQuery($sql)) {
            preg_match("/SET v = '((?:\\\\'|[^'])*)'/", $sql, $valueMatch);
            preg_match("/JSON_UNQUOTE\(JSON_EXTRACT\(v, '\$\.owner'\)\) = '([^']+)'/", $sql, $ownerMatch);
            preg_match("/JSON_UNQUOTE\(JSON_EXTRACT\(v, '\$\.artifact_hash'\)\) = '([^']+)'/", $sql, $artifactMatch);
            $value = stripslashes((string) ($valueMatch[1] ?? ''));
            $owner = (string) ($ownerMatch[1] ?? '');
            $artifact = (string) ($artifactMatch[1] ?? '');
            $affected = 0;
            foreach ($this->store[$this->tableName('wprism_kv')] ?? [] as &$row) {
                $current = json_decode((string) ($row['v'] ?? ''), true);
                if (($row['k'] ?? null) === 'promotion_lock'
                    && is_array($current)
                    && ($current['owner'] ?? null) === $owner
                    && ($current['artifact_hash'] ?? null) === $artifact) {
                    $row['v'] = $value;
                    $affected = 1;
                }
            }
            unset($row);
            $this->log('query', $sql);
            $this->rows_affected = $affected;
            return ['kind' => 'affected', 'affected' => $affected];
        }
        if ($this->fullApplySqlExtensionsEnabled && $this->isFullApplyPruneQuery($sql)) {
            // Ledger::prune_dead_map() is a deliberately live-only LEFT JOIN
            // in the general fake grammar. Full apply's fixture seeds no
            // orphan rows; preserve that exact no-op result while keeping the
            // statement visible in the query log.
            $this->log('query', $sql);
            $this->rows_affected = 0;
            return ['kind' => 'affected', 'affected' => 0];
        }
        if ($transactionOutcome === 'inactive_false') {
            $trimmed = rtrim(trim($sql), "; \t\n\r");
            $command = preg_match('/^[A-Za-z_]+/', $trimmed, $match) === 1
                ? strtoupper($match[0])
                : '';
            if ($command !== 'COMMIT') {
                throw new \LogicException(
                    'FakeWpdb: inactive_false is defined only for an ambiguous COMMIT'
                );
            }
            $this->execTransaction('ROLLBACK');
            $this->fail($method, $sql, 'injected inactive transaction failure before commit apply');
            return null;
        }
        if (in_array($transactionOutcome, [
            'success_no_apply',
            'success_no_apply_reconnect_before_state',
            'success_no_apply_probe_error',
        ], true)) {
            $trimmed = rtrim(trim($sql), "; \t\n\r");
            $command = preg_match('/^[A-Za-z_]+/', $trimmed, $match) === 1
                ? strtoupper($match[0])
                : '';
            if ($command !== 'COMMIT') {
                throw new \LogicException(
                    'FakeWpdb: success_no_apply is defined only for an ambiguous COMMIT'
                );
            }
            $result = ['kind' => 'ok'];
        } else {
            $result = $this->execute($sql);
        }
        if (in_array($transactionOutcome, [
            'success_probe_error',
            'success_no_apply_probe_error',
        ], true)) {
            $this->failNextQuery(
                'injected transaction-state probe failure after truthy COMMIT response',
                'SELECT CONNECTION_ID() AS connection_id, @@in_transaction AS in_transaction'
            );
        }
        if ($transactionOutcome === 'success_no_apply_reconnect_before_state') {
            // A legacy connection-id query still observes the old owner, then
            // the following state query sees one idle replacement session.
            // An atomic session query instead observes both replacement facts.
            $this->reconnectBeforeTransactionState = true;
        }
        if ($transactionOutcome === 'after_reconnect') {
            $this->setConnectionId($this->connectionId + 1);
        } elseif ($transactionOutcome === 'after_false') {
            $this->fail($method, $sql, 'injected transaction failure after server apply');
            return null;
        } elseif ($transactionOutcome === 'after_throw') {
            $this->last_error = 'injected transaction exception after server apply';
            $this->last_query = $sql;
            $this->queryLog[] = [
                'method' => $method,
                'sql' => $sql,
                'error' => $this->last_error,
            ];
            throw new \RuntimeException('injected transaction exception after server apply');
        }
        $this->log($method, $sql);
        if ($result['kind'] === 'rows') {
            $this->num_rows = count($result['rows']);
        } elseif ($result['kind'] === 'affected') {
            $this->rows_affected = $result['affected'];
        }
        return $result;
    }

    private function takeTransactionOutcome(string $sql): ?string {
        $trimmed = rtrim(trim($sql), "; \t\n\r");
        $command = preg_match('/^[A-Za-z_]+/', $trimmed, $match) === 1
            ? strtoupper($match[0])
            : '';
        foreach ($this->transactionOutcomes as $index => $probe) {
            if ($probe['command'] !== $command) {
                continue;
            }
            array_splice($this->transactionOutcomes, $index, 1);
            return $probe['outcome'];
        }
        return null;
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
     * in MySQL, and the columns this class is built to model (wprism_kv `k`,
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
     * byte-exact (see agent/src, user and wprism_kv lookups): a fake whose plain
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
        if (preg_match(
            '/^SELECT TABLE_NAME, ENGINE FROM information_schema\.TABLES\s+'
                . 'WHERE TABLE_SCHEMA = DATABASE\(\) AND TABLE_NAME IN \((.+)\)\s+'
                . 'ORDER BY TABLE_NAME ASC$/isD',
            $trimmed,
            $tableInventory
        ) === 1) {
            preg_match_all("/'((?:''|[^'])*)'/", $tableInventory[1], $names);
            $rows = [];
            foreach ($names[1] as $escapedName) {
                $name = str_replace("''", "'", $escapedName);
                if (!array_key_exists($name, $this->store)) {
                    continue;
                }
                $rows[] = [
                    'TABLE_NAME' => $name,
                    'ENGINE' => $this->tableEngines[$name] ?? null,
                ];
            }
            usort($rows, static fn(array $a, array $b): int =>
                strcmp((string) $a['TABLE_NAME'], (string) $b['TABLE_NAME'])
            );
            return ['kind' => 'rows', 'rows' => $rows];
        }
        if (strcasecmp($trimmed, 'SELECT @@in_transaction') === 0) {
            return [
                'kind' => 'rows',
                'rows' => [['@@in_transaction' => $this->transactionSnapshot === null ? '0' : '1']],
            ];
        }
        if (strcasecmp($trimmed, 'SELECT @@transaction_isolation') === 0
            || strcasecmp($trimmed, 'SELECT @@tx_isolation') === 0) {
            return ['kind' => 'rows', 'rows' => [['isolation' => $this->transactionIsolation]]];
        }
        if (strcasecmp(
            $trimmed,
            'SELECT CONNECTION_ID() AS connection_id, @@in_transaction AS in_transaction'
        ) === 0) {
            if ($this->reconnectBeforeTransactionState) {
                $this->reconnectBeforeTransactionState = false;
                $this->setConnectionId($this->connectionId + 1);
            }
            return [
                'kind' => 'rows',
                'rows' => [[
                    'connection_id' => (string) $this->connectionId,
                    'in_transaction' => $this->transactionSnapshot === null ? '0' : '1',
                ]],
            ];
        }
        $this->currentSql = $trimmed;
        if (preg_match('/^(.*)\s+FOR\s+UPDATE$/isD', $trimmed, $locking) === 1) {
            if (preg_match('/\bFROM\s+`?([A-Za-z0-9_]{1,64})`?/is', $trimmed, $tableMatch) !== 1) {
                throw $this->unsupported('SELECT FOR UPDATE without an exact table');
            }
            $table = $this->tableName($tableMatch[1]);
            if ($this->transactionSnapshot !== null) {
                $schemaKey = $table . "\0schema";
                if (isset($this->rowLocks[$schemaKey]) && $this->rowLocks[$schemaKey] !== $this->connectionId) {
                    throw new \RuntimeException('FakeWpdb: simulated InnoDB metadata lock wait timeout');
                }
                $this->rowLocks[$schemaKey] = $this->connectionId;
                if (preg_match(
                    "/\\bFROM\\s+`?([A-Za-z0-9_]{1,64})`?.*\\boption_name\\s*=\\s*"
                    . "(?:BINARY\\s+)?'((?:[^'\\\\]|\\\\.)*)'/is",
                    $trimmed,
                    $target
                ) === 1) {
                    $optionName = stripslashes($target[2]);
                    $key = $this->tableName($target[1]) . "\0option_name\0" . $optionName;
                    if (isset($this->rowLocks[$key]) && $this->rowLocks[$key] !== $this->connectionId) {
                        throw new \RuntimeException('FakeWpdb: simulated InnoDB row lock wait timeout');
                    }
                    $this->rowLocks[$key] = $this->connectionId;
                } elseif (str_ends_with($table, 'actionscheduler_groups')
                    && preg_match("/\\bWHERE\\s+slug\\s*=\\s*'((?:[^'\\\\]|\\\\.)*)'/is", $trimmed, $group) === 1) {
                    $key = $table . "\0slug\0" . stripslashes($group[1]);
                    if (isset($this->rowLocks[$key]) && $this->rowLocks[$key] !== $this->connectionId) {
                        throw new \RuntimeException('FakeWpdb: simulated InnoDB group-range lock wait timeout');
                    }
                    $this->rowLocks[$key] = $this->connectionId;
                } elseif (str_ends_with($table, 'wprism_map')) {
                    // Apply finalization takes one bounded inventory lock over
                    // the complete engine-owned map; its exact table and
                    // PRIMARY order are the lock target, without a WHERE key.
                } elseif (preg_match(
                    "/\\bWHERE\\s+hook\\s*=\\s*'([^']+)'\\s+AND\\s+status\\s*=\\s*'([^']+)'/is",
                    $trimmed,
                    $range
                ) === 1) {
                    $key = $table . "\0hook-status\0" . stripslashes($range[1]) . "\0" . stripslashes($range[2]);
                    if (isset($this->rowLocks[$key]) && $this->rowLocks[$key] !== $this->connectionId) {
                        throw new \RuntimeException('FakeWpdb: simulated InnoDB range lock wait timeout');
                    }
                    $this->rowLocks[$key] = $this->connectionId;
                } elseif (str_ends_with($table, 'actionscheduler_logs')
                    && preg_match('/\bWHERE\s+action_id\s*=\s*([0-9]+)\b/is', $trimmed, $owner) === 1) {
                    $key = $table . "\0action_id\0" . $owner[1];
                    if (isset($this->rowLocks[$key]) && $this->rowLocks[$key] !== $this->connectionId) {
                        throw new \RuntimeException('FakeWpdb: simulated InnoDB owner-range lock wait timeout');
                    }
                    $this->rowLocks[$key] = $this->connectionId;
                } elseif ($table === $this->postmeta
                    && preg_match(
                        "/\\bWHERE\\s+meta_key\\s*=\\s*'_wp_attached_file'\\s+AND\\s+meta_id\\s*>\\s*0\\b/is",
                        $trimmed
                    ) === 1) {
                    // Attachment recovery's exact bounded metadata roster is
                    // separately interpreted below; it has no shared-write
                    // seam, so a synthetic range lock would only reject it.
                } elseif (preg_match(
                    '/\bWHERE\b[^;]*\b`?(?:ID|option_id|meta_id|event_id|occurrence_id|post_id|action_id|claim_id|group_id|log_id)`?\s*=\s*[0-9]+\b/is',
                    $trimmed
                ) !== 1) {
                    throw $this->unsupported('unregistered SELECT FOR UPDATE lock target');
                }
            }
            $trimmed = rtrim($locking[1]);
            $trimmed = preg_replace(
                '/\s+FORCE\s+INDEX\s*\(`?[A-Za-z0-9_]{1,64}`?\)/i',
                '',
                $trimmed
            ) ?? $trimmed;
        }
        $head = preg_match('/^[A-Za-z_]+/', $trimmed, $m) === 1 ? strtoupper($m[0]) : '';
        if (preg_match(
            "/^SELECT\\s+CONNECTION_ID\\(\\)\\s+AS\\s+connection_id,\\s*"
            . "@@(?:SESSION\\.)?in_transaction\\s+AS\\s+in_transaction,\\s*"
            . "IS_USED_LOCK\\('((?:[^'\\\\]|\\\\.)*)'\\)\\s+AS\\s+lock_holder$/iD",
            $trimmed,
            $session
        ) === 1) {
            $lockName = stripslashes($session[1]);
            return [
                'kind' => 'rows',
                'rows' => [[
                    'connection_id' => $this->connectionId,
                    'in_transaction' => $this->transactionSnapshot === null ? 0 : 1,
                    'lock_holder' => $this->heldLocks[$lockName] ?? null,
                ]],
            ];
        }
        if (preg_match('/^SELECT\s+@@(?:SESSION\.)?in_transaction$/iD', $trimmed) === 1) {
            return [
                'kind' => 'rows',
                'rows' => [['@@in_transaction' => $this->transactionSnapshot === null ? 0 : 1]],
            ];
        }
        if (preg_match('/^SELECT\s+@@(?:SESSION\.)?(?:transaction_isolation|tx_isolation)$/iD', $trimmed) === 1) {
            return [
                'kind' => 'rows',
                'rows' => [['@@transaction_isolation' => $this->transactionIsolation]],
            ];
        }
        if (($head === 'SAVEPOINT' && preg_match('/^SAVEPOINT `[A-Za-z0-9_]+`$/D', $trimmed) === 1)
            || ($head === 'RELEASE'
                && preg_match('/^RELEASE SAVEPOINT `[A-Za-z0-9_]+`$/D', $trimmed) === 1)) {
            if ($this->transactionSnapshot === null) {
                throw $this->unsupported('savepoint outside a transaction');
            }
            return ['kind' => 'ok'];
        }
        if ($head === 'SELECT') {
            $optionStats = $this->boundedOptionStats($trimmed);
            if ($optionStats !== null) {
                return ['kind' => 'rows', 'rows' => [$optionStats]];
            }
            $schemaCount = $this->boundedSchemaCount($trimmed);
            if ($schemaCount !== null) {
                return ['kind' => 'rows', 'rows' => [['COUNT(*)' => $schemaCount]]];
            }
        }
        switch ($head) {
            case 'CREATE':
            case 'ALTER':
            case 'DROP':
            case 'TRUNCATE':
                return $this->execDdl($head);
            case 'SET':
                if (preg_match(
                    '/^SET\s+TRANSACTION\s+ISOLATION\s+LEVEL\s+'
                    . '(READ\s+UNCOMMITTED|READ\s+COMMITTED|REPEATABLE\s+READ|SERIALIZABLE)$/iD',
                    $trimmed,
                    $isolationMatch
                ) === 1) {
                    if ($this->transactionSnapshot !== null) {
                        throw new \RuntimeException(
                            'FakeWpdb: transaction characteristics cannot change inside a transaction'
                        );
                    }
                    $this->nextTransactionIsolation = strtoupper(
                        preg_replace('/\s+/', '-', $isolationMatch[1]) ?? $isolationMatch[1]
                    );
                }
                $this->ddlLog[] = $trimmed;
                return ['kind' => 'ok'];
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

    /** Exact compact witness used before OptionsCapture transfers option rows. */
    private function boundedOptionStats(string $sql): ?array {
        if (preg_match(
            '/^SELECT COUNT\(\*\) AS row_count, '
            . 'COALESCE\(SUM\(OCTET_LENGTH\(option_name\) \+ OCTET_LENGTH\(option_value\)\), 0\) AS total_bytes, '
            . 'COALESCE\(MAX\(OCTET_LENGTH\(option_name\)\), 0\) AS max_name_bytes, '
            . 'COALESCE\(MAX\(CHAR_LENGTH\(option_name\)\), 0\) AS max_name_characters, '
            . 'COALESCE\(MAX\(OCTET_LENGTH\(option_value\)\), 0\) AS max_value_bytes '
            . 'FROM `?([A-Za-z0-9_]{1,64})`?$/D',
            $sql,
            $matches
        ) !== 1) {
            return null;
        }
        $rows = $this->store[$this->requireTable($matches[1])];
        $totalBytes = 0;
        $maxNameBytes = 0;
        $maxNameCharacters = 0;
        $maxValueBytes = 0;
        foreach ($rows as $row) {
            $name = (string) ($row['option_name'] ?? '');
            $value = (string) ($row['option_value'] ?? '');
            $nameBytes = strlen($name);
            $valueBytes = strlen($value);
            $characters = preg_match('//u', $name) === 1
                ? preg_match_all('/./us', $name)
                : $nameBytes;
            $totalBytes += $nameBytes + $valueBytes;
            $maxNameBytes = max($maxNameBytes, $nameBytes);
            $maxNameCharacters = max($maxNameCharacters, is_int($characters) ? $characters : 0);
            $maxValueBytes = max($maxValueBytes, $valueBytes);
        }
        return [
            'row_count' => count($rows),
            'total_bytes' => $totalBytes,
            'max_name_bytes' => $maxNameBytes,
            'max_name_characters' => $maxNameCharacters,
            'max_value_bytes' => $maxValueBytes,
        ];
    }

    /** Exact compact witness used before a bounded SHOW schema transfer. */
    private function boundedSchemaCount(string $sql): ?int {
        if (preg_match(
            '/^SELECT COUNT\(\*\) FROM information_schema\.(COLUMNS|STATISTICS) '
            . "WHERE TABLE_SCHEMA = DATABASE\(\) AND BINARY TABLE_NAME = BINARY '([A-Za-z0-9_]{1,64})'$/D",
            $sql,
            $matches
        ) !== 1) {
            return null;
        }
        $table = $this->requireTable($matches[2]);
        if ($matches[1] === 'STATISTICS') {
            return count($this->indexes[$table] ?? []);
        }
        if (isset($this->columnDefinitions[$table])) {
            return count($this->columnDefinitions[$table]);
        }
        return count($this->columnTypes[$table] ?? []);
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
        'DUPLICATE', 'KEY', 'BY', 'NULL', 'BINARY', 'DISTINCT', 'AS', 'FOR', 'UPDATE',
    ];

    // ------------------------------------------------------------ SELECT

    private function execSelect(): array {
        if (preg_match(
            '/^SELECT um\.umeta_id AS meta_id FROM wp_usermeta um LEFT JOIN wp_users u '
                . 'ON u\.ID = um\.user_id WHERE u\.ID IS NULL ORDER BY um\.umeta_id ASC LIMIT 1$/iD',
            $this->currentSql
        ) === 1) {
            // UserMetaCapture only needs the first orphan witness. The shared
            // fixture stores the two tables independently, so an empty result
            // is the exact seeded relation (no orphan rows).
            return ['kind' => 'rows', 'rows' => []];
        }
        if (preg_match(
            '/^SELECT a\.ID AS left_id, b\.ID AS right_id FROM wp_users a INNER JOIN wp_users b '
                . 'ON b\.user_login = a\.user_login AND b\.ID > a\.ID '
                . 'ORDER BY a\.ID ASC, b\.ID ASC LIMIT 1$/iD',
            $this->currentSql
        ) === 1) {
            // The seeded user table is empty; therefore no collation-equal
            // duplicate-login witness exists.
            return ['kind' => 'rows', 'rows' => []];
        }
        if (preg_match(
            '/^SELECT meta_id, post_id, meta_key, OCTET_LENGTH\(meta_value\) AS meta_value_bytes, '
                . 'CASE WHEN meta_value IS NOT NULL AND OCTET_LENGTH\(meta_value\) <= 1024 '
                . 'THEN meta_value ELSE NULL END AS bounded_value FROM `wp_postmeta` '
                . 'FORCE INDEX \(`meta_key`\) WHERE meta_key = \'_wp_attached_file\' '
                . 'AND meta_id > 0 ORDER BY meta_key ASC, meta_id ASC LIMIT 512 FOR UPDATE$/iD',
            $this->currentSql
        ) === 1) {
            $rows = [];
            foreach ($this->store['wp_postmeta'] ?? [] as $row) {
                if (($row['meta_key'] ?? null) !== '_wp_attached_file' || (int) ($row['meta_id'] ?? 0) <= 0) {
                    continue;
                }
                $value = $row['meta_value'] ?? null;
                $bytes = is_string($value) ? strlen($value) : null;
                $rows[] = [
                    'meta_id' => $row['meta_id'], 'post_id' => $row['post_id'],
                    'meta_key' => $row['meta_key'], 'meta_value_bytes' => $bytes,
                    'bounded_value' => $bytes !== null && $bytes <= 1024 ? $value : null,
                ];
            }
            usort($rows, static fn(array $a, array $b): int => ((int) $a['meta_id']) <=> ((int) $b['meta_id']));
            return ['kind' => 'rows', 'rows' => array_slice($rows, 0, 512)];
        }
        $this->expectKeyword('SELECT');
        $distinct = $this->acceptKeyword('DISTINCT');
        $items = [];
        do {
            $items[] = $this->parseSelectItem();
        } while ($this->acceptOp(','));

        $table = null;
        $alias = null;
        $join = null;
        $joins = [];
        if ($this->acceptKeyword('FROM')) {
            $table = $this->parseTableRef();
            $alias = $this->parseAliasOpt();
            $this->skipIndexHint();
            if (!$this->joinedCaptureSqlEnabled && $this->keyword() === 'LEFT') {
                $join = $this->parseLeftEquiJoin($table, $alias);
            }
            if (!$this->joinedCaptureSqlEnabled && (
                in_array($this->keyword(), ['JOIN', 'INNER', 'LEFT', 'RIGHT', 'CROSS', 'STRAIGHT_JOIN', 'UNION'], true)
                || ($this->peek()['t'] === 'op' && $this->peek()['v'] === ',')
            )) {
                throw $this->unsupported('multi-table SELECT (JOIN/UNION)');
            }
            while ($this->joinedCaptureSqlEnabled
                && in_array($this->keyword(), ['JOIN', 'INNER', 'LEFT'], true)) {
                $joinType = 'INNER';
                if ($this->acceptKeyword('LEFT')) {
                    $joinType = 'LEFT';
                    $this->acceptKeyword('OUTER');
                    $this->expectKeyword('JOIN');
                } elseif ($this->acceptKeyword('INNER')) {
                    $this->expectKeyword('JOIN');
                } else {
                    $this->expectKeyword('JOIN');
                }
                $joinTable = $this->parseTableRef();
                $joinAlias = $this->parseAliasOpt();
                $this->skipIndexHint();
                $this->expectKeyword('ON');
                $joins[] = [
                    'type' => $joinType,
                    'table' => $joinTable,
                    'alias' => $joinAlias,
                    'condition' => $this->parseCondition(),
                ];
            }
            if ($this->keyword() === 'RIGHT'
                || $this->keyword() === 'CROSS'
                || $this->keyword() === 'STRAIGHT_JOIN'
                || $this->keyword() === 'UNION'
                || ($this->peek()['t'] === 'op' && $this->peek()['v'] === ',')) {
                throw $this->unsupported('unsupported multi-table SELECT shape');
            }
        }
        $where = $this->acceptKeyword('WHERE') ? $this->parseCondition() : null;
        $group = [];
        if ($this->acceptKeyword('GROUP')) {
            $this->expectKeyword('BY');
            do {
                $this->acceptKeyword('BINARY');
                $group[] = $this->parseColumnRef()['name'];
            } while ($this->acceptOp(','));
        }
        if ($this->keyword() === 'HAVING') {
            throw $this->unsupported('HAVING');
        }
        $order = $this->parseOrderBy();
        [$limit, $offset] = $this->parseLimit();
        if ($this->acceptKeyword('FOR')) {
            $this->expectKeyword('UPDATE');
        }
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
        if ($join !== null) {
            $ctx = ['table' => $name, 'alias' => $alias, 'columns' => $this->knownColumns($name)];
            $left = $this->store[$name];
            [$left, $ctx] = $this->applyLeftEquiJoin($left, $ctx, $join);
            $matched = [];
            foreach ($left as $row) {
                if ($where === null || $this->evalCondition($where, $row, $ctx)) {
                    $matched[] = $row;
                }
            }
            $sources = [];
        } else {
            $sources = [[
                'table' => $name,
                'alias' => $alias,
                'short' => str_starts_with($name, $this->prefix)
                    ? substr($name, strlen($this->prefix))
                    : $name,
                'columns' => $this->knownColumns($name),
            ]];
            foreach ($joins as $broadJoin) {
                $joinName = $this->requireTable($broadJoin['table']);
                $sources[] = [
                    'table' => $joinName,
                    'alias' => $broadJoin['alias'],
                    'short' => str_starts_with($joinName, $this->prefix)
                        ? substr($joinName, strlen($this->prefix))
                        : $joinName,
                    'columns' => $this->knownColumns($joinName),
                ];
            }
            $ctx = [
                'table' => $name,
                'alias' => $alias,
                'columns' => $this->knownColumns($name),
                'sources' => $joins === [] ? null : $sources,
            ];
            if ($joins === []) {
                $matched = [];
                foreach ($this->store[$name] as $row) {
                    if ($where === null || $this->evalCondition($where, $row, $ctx)) {
                        $matched[] = $row;
                    }
                }
            } else {
                $matched = array_map(
                    static fn(array $row): array => ['__join_sources' => [$row]],
                    $this->store[$name]
                );
                foreach (array_slice($joins, 0, null, true) as $joinIndex => $broadJoin) {
                    $joinName = $sources[$joinIndex + 1]['table'];
                    $next = [];
                    foreach ($matched as $left) {
                        $found = false;
                        foreach ($this->store[$joinName] as $right) {
                            $candidate = [
                                '__join_sources' => array_merge($left['__join_sources'], [$right]),
                            ];
                            if ($this->evalCondition($broadJoin['condition'], $candidate, $ctx)) {
                                $next[] = $candidate;
                                $found = true;
                            }
                        }
                        if (!$found && $broadJoin['type'] === 'LEFT') {
                            $next[] = [
                                '__join_sources' => array_merge($left['__join_sources'], [null]),
                            ];
                        }
                    }
                    $matched = $next;
                }
                if ($where !== null) {
                    $matched = array_values(array_filter(
                        $matched,
                        fn(array $row): bool => $this->evalCondition($where, $row, $ctx)
                    ));
                }
            }
        }

        $aggregate = $group !== [];
        foreach ($items as $item) {
            $aggregate = $aggregate
                || $item['type'] === 'count'
                || ($item['type'] === 'expr' && $this->containsAggregate($item['expr']));
        }
        if ($join !== null && $aggregate) {
            throw $this->unsupported('COUNT(*)/GROUP BY over a LEFT JOIN');
        }
        foreach ($join !== null ? $items : [] as $item) {
            if ($item['type'] === 'star') {
                // `*` over a join would have to invent a column ORDER across
                // two tables, and project() resolves names in one
                // namespace. Naming the columns costs the caller nothing --
                // the one product query that reaches here already does.
                throw $this->unsupported('`*` over a LEFT JOIN; name the columns');
            }
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
            $out = $this->sortRows($out, $order, [
                'table' => $name,
                'alias' => $alias,
                'columns' => null,
                'sources' => $joins === [] ? null : $sources,
            ]);
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

    /**
     * The ONE join shape this interpreter accepts: a single LEFT JOIN whose ON
     * is exactly one equality between one qualified column on each side.
     *
     * The general refusal in execSelect() still rejects INNER,
     * RIGHT, CROSS, a comma join, UNION and a second JOIN) for the reason the
     * header states: a suite that needs a real join is characterizing a query
     * whose behaviour belongs in live certification. A single-condition LEFT
     * equi-join is not that. It is a per-row LOOKUP -- "carry this column
     * across, or NULL" -- with no optimizer choice to model and one arithmetic
     * outcome, so interpreting it invents no MySQL behaviour.
     *
     * It is here because it is on the engine's own term-deletion path and
     * nowhere else: RelationshipMaterializer::lock_owner_relationships()
     * (agent/src/Apply/RelationshipMaterializer.php:287-295) reads
     * `term_relationships tr LEFT JOIN term_taxonomy tt ON tt.term_taxonomy_id
     * = tr.term_taxonomy_id`, and DeleteExecutor's term branch calls it three
     * times (DeleteExecutor.php:170, RelationshipMaterializer.php:229 and
     * :247), so a term deletion cannot run offline at all without it. That
     * LEFT is load-bearing product semantics, not incidental SQL: a
     * term_relationships row whose term_taxonomy row is gone comes back with
     * taxonomy NULL, which the reader at :310-318 turns into a refusal instead
     * of silently dropping the row an INNER JOIN would have hidden.
     *
     * Parse only: the joined table's column set is not known until
     * applyLeftEquiJoin() resolves it, which is also where the unseeded-table
     * refusal fires.
     *
     * @return array{table:string,alias:?string,short:string,left:string,right:string}
     */
    private function parseLeftEquiJoin(string $baseTable, ?string $baseAlias): array {
        $this->expectKeyword('LEFT');
        $this->acceptKeyword('OUTER');
        $this->expectKeyword('JOIN');
        $table = $this->parseTableRef();
        $alias = $this->parseAliasOpt();
        // MySQL itself rejects a duplicate name ("Not unique table/alias"), and
        // so must this: evalColumn() resolves a qualifier by name, so two sides
        // answering to one name would silently send every qualified reference
        // to whichever side the resolver happens to test first.
        if ($alias !== null ? $alias === $baseAlias : ($table === $baseTable && $baseAlias === null)) {
            throw $this->unsupported('a LEFT JOIN whose table/alias name is not unique');
        }
        $this->skipIndexHint();
        $this->expectKeyword('ON');
        $first = $this->parseColumnRef();
        $token = $this->peek();
        if ($token['t'] !== 'op' || $token['v'] !== '=') {
            throw $this->unsupported('a LEFT JOIN ... ON that is not a single equality');
        }
        $this->tp++;
        $second = $this->parseColumnRef();
        if (in_array($this->keyword(), ['AND', 'OR'], true)) {
            throw $this->unsupported('a multi-condition LEFT JOIN ... ON');
        }
        if ($first['q'] === null || $second['q'] === null) {
            throw $this->unsupported('a LEFT JOIN ... ON whose columns are not both table-qualified');
        }
        $short = str_starts_with($table, $this->prefix) ? substr($table, strlen($this->prefix)) : $table;
        $names = array_filter([$alias, $table, $short], static fn(?string $n): bool => $n !== null);
        $firstIsRight = in_array($first['q'], $names, true);
        $secondIsRight = in_array($second['q'], $names, true);
        if ($firstIsRight === $secondIsRight) {
            throw $this->unsupported('a LEFT JOIN ... ON that does not name one column from each side');
        }
        return [
            'table' => $table,
            'alias' => $alias,
            'short' => (string) $short,
            'right' => $firstIsRight ? $first['name'] : $second['name'],
            'left' => $firstIsRight ? $second['name'] : $first['name'],
        ];
    }

    /**
     * Expand the base rows into joined rows, MySQL's order: ON first, WHERE
     * after. A left row with no match keeps one output row carrying NULLs; a
     * left row with several matches produces one output row per match, because
     * that is what the server does when the joined key is not unique.
     *
     * Right-hand values live under `<alias>.<column>` keys so the existing
     * unqualified column paths (WHERE, ORDER BY, projection) keep resolving
     * against the base table exactly as they do for an unjoined SELECT.
     * evalColumn() is the only reader that knows about them.
     *
     * @param list<array<string,mixed>> $rows
     * @return array{0:list<array<string,mixed>>,1:array<string,mixed>}
     */
    private function applyLeftEquiJoin(array $rows, array $ctx, array $join): array {
        $name = $this->requireTable($join['table']);
        $join['columns'] = $this->knownColumns($name);
        $join['table'] = $name;
        $ctx['join'] = $join;
        $prefix = ($join['alias'] ?? $join['short']) . '.';
        $index = [];
        foreach ($this->store[$name] as $row) {
            $index[(string) ($row[$join['right']] ?? "\0NULL")][] = $row;
        }
        $out = [];
        foreach ($rows as $row) {
            $matches = $index[(string) ($row[$join['left']] ?? "\0NULL")] ?? [];
            if ($matches === []) {
                $blank = [];
                foreach ($join['columns'] as $column) {
                    $blank[$prefix . $column] = null;
                }
                $out[] = $row + $blank;
                continue;
            }
            foreach ($matches as $match) {
                $carried = [];
                foreach ($join['columns'] as $column) {
                    $carried[$prefix . $column] = $match[$column] ?? null;
                }
                $out[] = $row + $carried;
            }
        }
        return [$out, $ctx];
    }

    /** @return list<array{column:array,dir:int}> */
    private function parseOrderBy(): array {
        if (!$this->acceptKeyword('ORDER')) {
            return [];
        }
        $this->expectKeyword('BY');
        $order = [];
        do {
            $binary = $this->acceptKeyword('BINARY');
            $column = $this->parseColumnRef();
            $dir = 1;
            if ($this->acceptKeyword('DESC')) {
                $dir = -1;
            } else {
                $this->acceptKeyword('ASC');
            }
            $order[] = ['column' => $column, 'dir' => $dir, 'binary' => $binary];
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
        if ($name === 'CAST') {
            $argument = $this->parseOperand();
            $this->expectKeyword('AS');
            $type = $this->peek();
            if (!in_array($type['t'], ['word', 'id'], true)) {
                throw $this->unsupported('CAST() expected a target type');
            }
            $this->tp++;
            $this->expectOp(')');
            return [
                'k' => 'fn',
                'name' => 'CAST',
                'args' => [$argument],
                'cast_type' => strtoupper((string) $type['v']),
                'label' => 'CAST()',
            ];
        }
        $args = [];
        if (!$this->acceptOp(')')) {
            do {
                $args[] = $this->parseOperand();
            } while ($this->acceptOp(','));
            $this->expectOp(')');
        }
        if (!in_array(
            $name,
            [
                'LENGTH', 'OCTET_LENGTH', 'CHAR_LENGTH', 'LEFT', 'GET_LOCK', 'RELEASE_LOCK', 'IS_FREE_LOCK',
                'IS_USED_LOCK', 'CONNECTION_ID', 'VERSION', 'SHA2', 'COALESCE', 'SUM', 'MAX',
            ],
            true
        )) {
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
            'LENGTH', 'OCTET_LENGTH', 'CHAR_LENGTH' => $args[0] === null ? null : strlen((string) $args[0]),
            'CAST' => $node['cast_type'] === 'CHAR'
                ? ($args[0] === null ? null : (string) $args[0])
                : throw $this->unsupported("CAST(... AS {$node['cast_type']})"),
            'COALESCE' => self::firstNonNull($args),
            'LEFT' => $this->leftFunction(
                $args,
                isset($node['args'][0]) && self::isBinary($node['args'][0])
            ),
            'SHA2' => $this->sha2Function($args),
            // Advisory locks are a live-MySQL concern; the fake reports a
            // configurable, deterministic result so the engine's lock branch
            // is exercisable without a server.
            'GET_LOCK' => $this->acquireAdvisoryLock((string) ($args[0] ?? '')),
            'RELEASE_LOCK' => $this->releaseAdvisoryLock((string) ($args[0] ?? '')),
            'IS_FREE_LOCK' => $this->lockResult,
            // NULL when nobody holds it, as MySQL answers; the holding
            // connection id otherwise, which is the equality
            // ProcessFence::isContinuous() tests.
            'IS_USED_LOCK' => $this->heldLocks[(string) ($args[0] ?? '')] ?? null,
            'CONNECTION_ID' => $this->connectionId,
            'VERSION' => $this->serverVersion,
            'SUM', 'MAX' => throw $this->unsupported(
                "{$node['name']}() is valid only in an aggregate SELECT"
            ),
            default => throw $this->unsupported('SQL function ' . $node['name']),
        };
    }

    private static function firstNonNull(array $values): mixed {
        foreach ($values as $value) {
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    private function containsAggregate(array $node): bool {
        if (($node['k'] ?? null) === 'fn'
            && in_array($node['name'] ?? null, ['SUM', 'MAX'], true)) {
            return true;
        }
        foreach (['arg', 'l', 'r'] as $key) {
            if (is_array($node[$key] ?? null) && $this->containsAggregate($node[$key])) {
                return true;
            }
        }
        foreach ((array) ($node['args'] ?? []) as $argument) {
            if (is_array($argument) && $this->containsAggregate($argument)) {
                return true;
            }
        }
        return false;
    }

    private function evalAggregateOperand(
        array $node,
        array $rows,
        array $ctx
    ): mixed {
        if (($node['k'] ?? null) === 'fn') {
            if ($node['name'] === 'SUM' || $node['name'] === 'MAX') {
                $values = [];
                foreach ($rows as $row) {
                    $value = $this->evalOperand($node['args'][0], $row, $ctx);
                    if ($value !== null) {
                        $values[] = $value;
                    }
                }
                if ($values === []) {
                    return null;
                }
                return $node['name'] === 'SUM' ? array_sum($values) : max($values);
            }
            if ($node['name'] === 'COALESCE') {
                $values = [];
                foreach ($node['args'] as $argument) {
                    $values[] = $this->containsAggregate($argument)
                        ? $this->evalAggregateOperand($argument, $rows, $ctx)
                        : $this->evalOperand($argument, $rows[0] ?? [], $ctx);
                }
                return self::firstNonNull($values);
            }
            if ($node['name'] === 'CAST') {
                $value = $this->containsAggregate($node['args'][0])
                    ? $this->evalAggregateOperand($node['args'][0], $rows, $ctx)
                    : $this->evalOperand($node['args'][0], $rows[0] ?? [], $ctx);
                return $node['cast_type'] === 'CHAR'
                    ? ($value === null ? null : (string) $value)
                    : throw $this->unsupported("CAST(... AS {$node['cast_type']})");
            }
        }
        if (($node['k'] ?? null) === 'arith') {
            $left = $this->evalAggregateOperand($node['l'], $rows, $ctx);
            $right = $this->evalAggregateOperand($node['r'], $rows, $ctx);
            if ($left === null || $right === null) {
                return null;
            }
            return $node['op'] === '+' ? $left + $right : $left - $right;
        }
        return $this->evalOperand($node, $rows[0] ?? [], $ctx);
    }

    private function leftFunction(array $args, bool $binary): ?string {
        if (count($args) !== 2 || !is_int($args[1]) || $args[1] < 0) {
            throw $this->unsupported('LEFT() argument shape');
        }
        if ($args[0] === null) {
            return null;
        }
        $value = (string) $args[0];
        if ($binary) {
            return substr($value, 0, $args[1]);
        }

        // MySQL LEFT(text,n) counts characters, while LEFT(BINARY text,n)
        // counts bytes. Walk strict UTF-8 without preg_split(): expanding an
        // 8 MiB bounded-read witness into one PHP zval per character exceeds
        // the corpus's 128 MiB memory limit before the refusal can run.
        $bytes = strlen($value);
        $offset = 0;
        $characters = 0;
        $prefixBytes = 0;
        while ($offset < $bytes) {
            $width = $this->utf8CharacterWidth($value, $offset, $bytes);
            if ($characters < $args[1]) {
                $prefixBytes = $offset + $width;
            }
            $offset += $width;
            ++$characters;
        }
        return substr($value, 0, $prefixBytes);
    }

    private function utf8CharacterWidth(string $value, int $offset, int $bytes): int {
        $first = ord($value[$offset]);
        if ($first <= 0x7f) {
            return 1;
        }
        if ($first >= 0xc2 && $first <= 0xdf
            && self::utf8ByteInRange($value, $offset + 1, $bytes, 0x80, 0xbf)) {
            return 2;
        }
        if ($first >= 0xe0 && $first <= 0xef) {
            $secondMin = $first === 0xe0 ? 0xa0 : 0x80;
            $secondMax = $first === 0xed ? 0x9f : 0xbf;
            if (self::utf8ByteInRange($value, $offset + 1, $bytes, $secondMin, $secondMax)
                && self::utf8ByteInRange($value, $offset + 2, $bytes, 0x80, 0xbf)) {
                return 3;
            }
        }
        if ($first >= 0xf0 && $first <= 0xf4) {
            $secondMin = $first === 0xf0 ? 0x90 : 0x80;
            $secondMax = $first === 0xf4 ? 0x8f : 0xbf;
            if (self::utf8ByteInRange($value, $offset + 1, $bytes, $secondMin, $secondMax)
                && self::utf8ByteInRange($value, $offset + 2, $bytes, 0x80, 0xbf)
                && self::utf8ByteInRange($value, $offset + 3, $bytes, 0x80, 0xbf)) {
                return 4;
            }
        }
        throw $this->unsupported('LEFT() invalid UTF-8 input');
    }

    private static function utf8ByteInRange(
        string $value,
        int $offset,
        int $bytes,
        int $minimum,
        int $maximum
    ): bool {
        if ($offset >= $bytes) {
            return false;
        }
        $byte = ord($value[$offset]);
        return $byte >= $minimum && $byte <= $maximum;
    }

    private function sha2Function(array $args): ?string {
        if (count($args) !== 2 || $args[1] !== 256) {
            throw $this->unsupported('SHA2() argument shape');
        }
        return $args[0] === null ? null : hash('sha256', (string) $args[0]);
    }

    /** GET_LOCK(): records the holder only when the configured result is 1. */
    private function acquireAdvisoryLock(string $name): int {
        if ($this->lockResult !== 1 || $name === '') {
            return $this->lockResult;
        }
        if (isset($this->heldLocks[$name]) && $this->heldLocks[$name] !== $this->connectionId) {
            return 0;
        }
        $this->heldLocks[$name] = $this->connectionId;
        return 1;
    }

    /** RELEASE_LOCK(): a released lock stops being held, whatever it reports. */
    private function releaseAdvisoryLock(string $name): int {
        if (!isset($this->heldLocks[$name])) {
            return 0;
        }
        if ($this->heldLocks[$name] !== $this->connectionId) {
            return 0;
        }
        unset($this->heldLocks[$name]);
        return 1;
    }

    private function evalColumn(array $node, array $row, ?array $ctx): mixed {
        $qualifier = $node['q'];
        if (is_array($ctx['sources'] ?? null)) {
            $joinedRows = $row['__join_sources'] ?? [];
            if (!is_array($joinedRows)) {
                throw $this->unsupported('joined SELECT row has malformed source state');
            }
            if ($qualifier !== null) {
                foreach ($ctx['sources'] as $index => $source) {
                    if ($qualifier !== $source['alias']
                        && $qualifier !== $source['table']
                        && $qualifier !== $source['short']) {
                        continue;
                    }
                    $sourceRow = $joinedRows[$index] ?? null;
                    if ($sourceRow === null) {
                        return null;
                    }
                    if (array_key_exists($node['name'], $sourceRow)) {
                        return $sourceRow[$node['name']];
                    }
                    if (in_array($node['name'], $source['columns'], true)) {
                        return null;
                    }
                    throw new \LogicException(
                        "FakeWpdb: unknown column '{$node['name']}' on joined table '{$source['table']}'."
                    );
                }
                throw $this->unsupported("column qualifier '$qualifier' names another table (JOIN)");
            }
            $found = false;
            $value = null;
            foreach ($ctx['sources'] as $index => $source) {
                $sourceRow = $joinedRows[$index] ?? null;
                if ($sourceRow === null || !array_key_exists($node['name'], $sourceRow)) {
                    continue;
                }
                if ($found) {
                    throw $this->unsupported("ambiguous unqualified joined column '{$node['name']}'");
                }
                $found = true;
                $value = $sourceRow[$node['name']];
            }
            return $found ? $value : null;
        }
        if ($qualifier !== null && $ctx !== null) {
            $short = str_starts_with($ctx['table'], $this->prefix)
                ? substr($ctx['table'], strlen($this->prefix))
                : $ctx['table'];
            $join = $ctx['join'] ?? null;
            $joinNames = $join === null
                ? []
                : array_filter(
                    [$join['alias'], $join['table'], $join['short']],
                    static fn(?string $name): bool => $name !== null
                );
            if ($join !== null && in_array($qualifier, $joinNames, true)) {
                $key = ($join['alias'] ?? $join['short']) . '.' . $node['name'];
                if (array_key_exists($key, $row)) {
                    return $row[$key];
                }
                throw new \LogicException(
                    "FakeWpdb: unknown column '{$node['name']}' on joined table '{$join['table']}'. Seed it in a"
                    . " row or declare it with setColumns(). Statement: {$this->currentSql}"
                );
            }
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
                if (is_array($ctx['sources'] ?? null)) {
                    foreach ($ctx['sources'] as $index => $source) {
                        if ($item['qualifier'] !== null
                            && $item['qualifier'] !== $source['alias']
                            && $item['qualifier'] !== $source['table']
                            && $item['qualifier'] !== $source['short']) {
                            continue;
                        }
                        $sourceRow = $row['__join_sources'][$index] ?? null;
                        foreach ($source['columns'] as $column) {
                            $out[$column] = is_array($sourceRow)
                                ? ($sourceRow[$column] ?? null)
                                : null;
                        }
                    }
                    continue;
                }
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
                $projected[$this->itemName($item, $index)] = $this->containsAggregate($item['expr'])
                    ? $this->evalAggregateOperand($item['expr'], $members, $ctx)
                    : $this->evalOperand($item['expr'], $first, $ctx);
            }
            $out[] = $projected;
        }
        return $out;
    }

    /** @param list<array{column:array,dir:int,binary:bool}> $order */
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
                $cmp = $term['binary']
                    ? (self::compareBinary($left, $right) ?? 0)
                    : (self::compare($left, $right) ?? 0);
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
        if ($this->acceptKeyword('TABLE')) {
            $this->expectKeyword('STATUS');
            $pattern = null;
            if ($this->acceptKeyword('LIKE')) {
                $token = $this->peek();
                if ($token['t'] !== 'str') {
                    throw $this->unsupported('SHOW TABLE STATUS LIKE expects a string literal');
                }
                $this->tp++;
                $pattern = (string) $token['v'];
            }
            $this->expectEnd();
            $rows = [];
            foreach (array_keys($this->store) as $name) {
                if ($pattern === null || self::likeMatches($pattern, $name)) {
                    $rows[] = [
                        'Name' => $name,
                        'Engine' => $this->tableEngines[$name] ?? null,
                    ];
                }
            }
            return ['kind' => 'rows', 'rows' => $rows];
        }
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
        $full = $this->acceptKeyword('FULL');
        if ($this->acceptKeyword('COLUMNS') || $this->acceptKeyword('FIELDS')) {
            $this->expectKeyword('FROM');
            $table = $this->parseTableRef();
            $this->expectEnd();
            $name = $this->requireTable($table);
            if ($full && isset($this->columnDefinitions[$name])
                && array_is_list($this->columnDefinitions[$name])) {
                return ['kind' => 'rows', 'rows' => $this->columnDefinitions[$name]];
            }
            $types = $this->columnTypes[$name] ?? [];
            $definitions = $this->columnDefinitions[$name] ?? [];
            $rows = [];
            if ($definitions !== [] && array_is_list($definitions)) {
                return ['kind' => 'rows', 'rows' => $definitions];
            }
            $columns = $definitions === [] ? $this->knownColumns($name) : array_keys($definitions);
            foreach ($columns as $column) {
                $definition = $definitions[$column] ?? null;
                $isPrimaryKey = ($this->primaryKeys[$name] ?? null) === $column;
                $rows[] = [
                    'Field' => $column,
                    'Type' => $definition['Type'] ?? $types[$column] ?? 'longtext',
                    // MySQL never permits a NULL in a PRIMARY KEY column, so a
                    // fake that reported one as nullable would be handing a
                    // reader a shape no server can produce (WP-2.1's rule,
                    // kept through the #561 merge). An explicit definition
                    // still wins for every other column.
                    'Null' => $isPrimaryKey ? 'NO' : ($definition['Null'] ?? 'YES'),
                    'Key' => $isPrimaryKey ? 'PRI' : '',
                    'Default' => $definition['Default'] ?? null,
                    'Extra' => $definition['Extra'] ?? '',
                ];
            }
            return ['kind' => 'rows', 'rows' => $rows];
        }
        if ($this->acceptKeyword('INDEX') || $this->acceptKeyword('INDEXES') || $this->acceptKeyword('KEYS')) {
            $this->expectKeyword('FROM');
            $table = $this->parseTableRef();
            $this->expectEnd();
            $name = $this->requireTable($table);
            // A RECORDED index inventory answers; an absent one declines.
            // Returning [] for a table nobody called setIndexes() on would
            // report "this table has no indexes" -- a schema fact inferred
            // from a missing fixture, which is the one thing the contract at
            // :110-119 forbids ("no schema fact is inferred from stored
            // rows"). README.md:202-208 states the sanctioned fill: a
            // wprism-adapter-probe/v1 recording off a real server, replayed
            // through setIndexes(), never this class's own bookkeeping.
            if (!isset($this->indexes[$name])) {
                throw $this->unsupported(
                    "SHOW INDEX FROM `$name` without a recorded index fixture; call setIndexes('$name', ...)"
                    . ' from a wprism-adapter-probe/v1 recording'
                );
            }
            return ['kind' => 'rows', 'rows' => $this->indexes[$name]];
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
            $this->activeTransactionIsolation = $this->nextTransactionIsolation
                ?? $this->transactionIsolation;
            $this->nextTransactionIsolation = null;
            return ['kind' => 'ok'];
        }
        if ($head === 'ROLLBACK' && $this->transactionSnapshot !== null) {
            $this->store = $this->transactionSnapshot;
        }
        $this->transactionSnapshot = null;
        $this->activeTransactionIsolation = null;
        foreach ($this->rowLocks as $name => $holder) {
            if ($holder === $this->connectionId) {
                unset($this->rowLocks[$name]);
            }
        }
        // A transaction boundary consumes a still-pending one-shot SET. The
        // TEC recovery regression also starts and rolls back one data-free
        // cleanup transaction so this property is observed, not assumed.
        $this->nextTransactionIsolation = null;
        return ['kind' => 'ok'];
    }

    /** @param array<string,mixed> $data @param array<string,mixed> $where */
    private function writeConflictsWithLocks(string $table, array $data, array $where): bool {
        $optionName = null;
        if (array_key_exists('option_name', $where)) {
            $optionName = (string) $where['option_name'];
        } elseif (array_key_exists('option_name', $data)) {
            $optionName = (string) $data['option_name'];
        } elseif (array_key_exists('option_id', $where)) {
            foreach ($this->store[$table] ?? [] as $row) {
                if (self::compare($row['option_id'] ?? null, $where['option_id']) === 0) {
                    $optionName = is_string($row['option_name'] ?? null) ? $row['option_name'] : null;
                    break;
                }
            }
        }
        if ($optionName !== null) {
            $key = $table . "\0option_name\0" . $optionName;
            if (isset($this->rowLocks[$key]) && $this->rowLocks[$key] !== $this->connectionId) {
                return true;
            }
        }

        if (str_ends_with($table, 'actionscheduler_groups')) {
            $candidate = $data;
            if ($where !== []) {
                foreach ($this->store[$table] ?? [] as $row) {
                    if ($this->matchesEquality($row, $where)) {
                        $candidate = array_merge($row, $data);
                        break;
                    }
                }
            }
            $slug = $candidate['slug'] ?? null;
            if (is_string($slug)) {
                $key = $table . "\0slug\0" . $slug;
                return isset($this->rowLocks[$key]) && $this->rowLocks[$key] !== $this->connectionId;
            }
        }

        if (!str_ends_with($table, 'actionscheduler_actions')) {
            $candidate = $data;
            if ($where !== []) {
                foreach ($this->store[$table] ?? [] as $row) {
                    if ($this->matchesEquality($row, $where)) {
                        $candidate = array_merge($row, $data);
                        break;
                    }
                }
            }
            $logActionId = $candidate['action_id'] ?? null;
            if (!str_ends_with($table, 'actionscheduler_logs')
                || (!is_int($logActionId)
                    && (!is_string($logActionId) || preg_match('/^[1-9][0-9]*$/D', $logActionId) !== 1))
                || (int) $logActionId < 1) {
                return false;
            }
            $key = $table . "\0action_id\0" . (string) $logActionId;
            return isset($this->rowLocks[$key]) && $this->rowLocks[$key] !== $this->connectionId;
        }
        $candidate = $data;
        if ($where !== []) {
            foreach ($this->store[$table] ?? [] as $row) {
                if ($this->matchesEquality($row, $where)) {
                    $candidate = array_merge($row, $data);
                    break;
                }
            }
        }
        $hook = $candidate['hook'] ?? null;
        $status = $candidate['status'] ?? null;
        if (!is_string($hook) || !is_string($status)) {
            return false;
        }
        $key = $table . "\0hook-status\0$hook\0$status";
        return isset($this->rowLocks[$key]) && $this->rowLocks[$key] !== $this->connectionId;
    }

    /**
     * DDL is recorded rather than interpreted: the column definitions are the
     * live schema's business (regress_table_schema.php and the certification
     * matrix cover them). CREATE registers an empty table, DROP forgets it,
     * TRUNCATE empties it -- enough for the engine's install/uninstall paths.
     */
    private function execDdl(string $head): array {
        $matched = preg_match(
            '/^(?:CREATE|DROP|TRUNCATE|ALTER)\s+TABLE\s+(?:IF\s+(?:NOT\s+)?EXISTS\s+)?`?([A-Za-z0-9_$]+)`?/i',
            $this->currentSql,
            $m
        );
        if ($matched === 1) {
            $name = $this->tableName($m[1]);
            $schemaKey = $name . "\0schema";
            if (isset($this->rowLocks[$schemaKey]) && $this->rowLocks[$schemaKey] !== $this->connectionId) {
                throw new \RuntimeException('FakeWpdb: simulated InnoDB metadata lock wait timeout');
            }
        }
        $this->ddlLog[] = $this->currentSql;
        if ($matched === 1) {
            $name = $this->tableName($m[1]);
            if ($head === 'CREATE') {
                $this->store[$name] ??= [];
            } elseif ($head === 'DROP') {
                unset(
                    $this->store[$name],
                    $this->autoIncrement[$name],
                    $this->columnTypes[$name],
                    $this->columnDefinitions[$name],
                    $this->indexes[$name],
                    $this->tableEngines[$name]
                );
            } elseif ($head === 'TRUNCATE') {
                $this->store[$name] = [];
            }
        }
        return ['kind' => 'ok'];
    }

}

}
