<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';

/**
 * Live SCHEMA facts about one target's candidate tables (`wprism-adapter-probe/v1`).
 *
 * `wprism adapter-draft` is WordPress-free by construction, so every fact that
 * needs a live server — a column's MySQL type, the real PRIMARY KEY,
 * whether a delete guard's predicate column is covered by an index, whether
 * a natural key is unique across the whole keyspace — leaves that generator
 * as a `proposal` carrying a NAMED question
 * (`cli/src/Adapter/AdapterDraft.php`, `propose_tables()`). This class is the
 * only place those named questions can be answered, because it is the only
 * half that runs on the target, and its output is what `adapter-draft
 * --evidence=<file>` consumes.
 *
 * The queries are not new: `TableSchema::live_column_types()` already reads
 * `SHOW COLUMNS FROM` (`agent/src/Kernel/TableSchema.php:132-146`),
 * `DeleteGuardEvaluator::lock_index()` already reads `SHOW INDEX FROM` and
 * interprets `Seq_in_index`/`Sub_part` (`agent/src/Delete/DeleteGuardEvaluator.php:425-459`),
 * and `Ledger::assert_read_only_schema()` already reads
 * `information_schema` for schema truth (`agent/src/Repository/Ledger.php:148-190`).
 * Reading them in the *same* terms is the point: an index-coverage answer
 * phrased differently from `lock_index()` would answer a question the
 * deletion guard does not ask.
 *
 * THREE boundaries are structural, not stylistic:
 *
 *   1. NO AUTHORITY. The document declares `authority: false` and carries no
 *      `class`, `identity`, `deletion` or capability word anywhere — a probe
 *      converts "pk='id' is a structural guess" into "the live PRIMARY KEY is
 *      (id)", which makes the human ratification better founded; it never
 *      performs it. `AdapterDraft` enforces the same thing from its side by
 *      validating this document against a CLOSED key set.
 *   2. NO VALUES. Row values are never read. The only row-derived numbers are
 *      `COUNT(*)` and `COUNT(DISTINCT <column>)`, and a MySQL `enum(…)`/`set(…)`
 *      type — which carries site values inside the type string itself — is
 *      reduced to its base word by normalized_type().
 *   3. NO REPAIR and no write. Every statement here is a read; the caller
 *      (`Cli::adapter_probe()`) additionally suspends the provenance journal,
 *      so asking a target what its schema is cannot become a WPrism INSERT.
 */
final class AdapterProbe {
    public const FORMAT = 'wprism-adapter-probe/v1';

    /** Same word `AdapterObservation` publishes: values never enter the document. */
    public const REDACTION = 'values_omitted';

    /**
     * A probe is an authoring step over the tables one draft proposes, not a
     * schema dump. The bound is loud rather than silently truncating: a
     * request for more tables than this is a different tool.
     */
    public const MAX_TABLES = 64;

    /**
     * The portable identifier grammar every name in the document must match.
     * `lock_index()` sanitizes server identifiers with the same character
     * class before it will interpolate one (`DeleteGuardEvaluator.php:433`),
     * so a name outside it is a name the deletion guard would not use either.
     */
    private const IDENTIFIER = '/^[A-Za-z0-9_]{1,64}$/D';

    /**
     * @param list<string> $tables unprefixed logical table names, exactly as a
     *   manifest's `tables` section and a draft's `tables.<name>` target spell them
     * @param array<string,string> $naturalKeys logical table => the ONE column
     *   whose keyspace-wide uniqueness to measure (the column adapter-draft's
     *   `natural_key_uniqueness` question names)
     * @return array<string,mixed> a `wprism-adapter-probe/v1` document
     */
    public static function report(array $tables, array $naturalKeys = []): array {
        global $wpdb;
        if (!is_object($wpdb) || !method_exists($wpdb, 'get_results')) {
            // There is no target outside a loaded WordPress, and a document
            // full of defaults would be indistinguishable from a real one.
            throw new \RuntimeException('wprism: adapter probe needs a live target; $wpdb is unavailable');
        }

        $requested = [];
        foreach ($tables as $table) {
            $table = (string) $table;
            self::assert_authored_identifier($table, 'table');
            $requested[$table] = true;
        }
        if ($requested === []) {
            throw self::refuse(
                'adapter probe needs at least one table to probe',
                'name the tables one adapter draft proposes in --tables=<comma-separated names>, then rerun adapter-probe'
            );
        }
        if (count($requested) > self::MAX_TABLES) {
            throw self::refuse(
                'adapter probe refuses more than ' . self::MAX_TABLES . ' tables in one document',
                'probe at most ' . self::MAX_TABLES . ' tables at a time — a wider sweep is a different tool — '
                    . 'then rerun adapter-probe'
            );
        }
        foreach ($naturalKeys as $table => $column) {
            self::assert_authored_identifier((string) $table, 'table');
            self::assert_authored_identifier((string) $column, 'column');
            if (!isset($requested[(string) $table])) {
                throw self::refuse(
                    "adapter probe was asked for a natural key on '$table', which is not one of the probed tables",
                    "add $table to --tables=<names> — a natural key is measured on a table this run probes — "
                        . 'then rerun adapter-probe'
                );
            }
        }
        ksort($requested, SORT_STRING);

        $facts = [];
        foreach (array_keys($requested) as $table) {
            $facts[$table] = self::probe_table($table, isset($naturalKeys[$table]) ? (string) $naturalKeys[$table] : null);
        }

        $document = [
            'authority' => false,
            'deferred' => self::deferred(),
            'format' => self::FORMAT,
            'redaction' => self::REDACTION,
            'tables' => $facts,
            'target' => [
                'agent_version' => self::agent_version(),
                'spec_version' => self::spec_version(),
            ],
        ];
        $document['probe_hash'] = self::hash_document($document);
        return $document;
    }

    /** Canonical self-hash, the basis the host recomputes before consuming. */
    public static function hash_document(array $document): string {
        unset($document['probe_hash']);
        return 'sha256:' . hash('sha256', Canon::encode($document));
    }

    /**
     * One table's live facts. `present: false` is a real answer and is kept
     * rather than dropped: "the target has no such table" is exactly what an
     * author ratifying a proposed `tables.<name>` needs to hear, and an
     * omitted row would be indistinguishable from a probe that never ran.
     *
     * @return array<string,mixed>
     */
    private static function probe_table(string $table, ?string $naturalKey): array {
        global $wpdb;
        $prefixed = $wpdb->prefix . $table;
        if (!self::table_exists($prefixed)) {
            return ['present' => false];
        }

        $columns = self::columns_of($prefixed);
        [$primaryKey, $uniqueKeys, $coverage] = self::indexes_of($prefixed, array_keys($columns));

        $facts = [
            'present' => true,
            'columns' => $columns,
            'primary_key' => $primaryKey,
            'unique_keys' => $uniqueKeys,
            'index_coverage' => $coverage,
            'foreign_keys' => self::foreign_keys_of($prefixed),
            'eav_twin' => self::eav_twin_of($table, $prefixed),
        ];
        if ($naturalKey !== null && isset($columns[$naturalKey])) {
            $facts['natural_key'] = self::natural_key_uniqueness($prefixed, $naturalKey);
        }
        return $facts;
    }

    /**
     * `SHOW TABLES LIKE` is the existence probe the engine already uses
     * (`TableSchema::live_column_types():136`). The result is compared
     * byte-exactly because `_` is a LIKE wildcard and every WordPress prefix
     * contains one — `wp_forms` as a pattern also matches `wpXforms`.
     */
    private static function table_exists(string $prefixed): bool {
        global $wpdb;
        $wpdb->last_error = '';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefixed));
        self::assert_read_ok('table existence');
        return is_string($found) && $found === $prefixed;
    }

    /**
     * The raw `SHOW COLUMNS` rows, validated. Kept raw for exactly one reason:
     * `Key` distinguishes a twin's own surrogate `meta_id` from the parent
     * reference beside it, and that distinction never belongs in the emitted
     * document — it is a means, not a fact a reviewer ratifies.
     *
     * @return list<array<string,mixed>>
     */
    private static function column_rows(string $prefixed): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results("SHOW COLUMNS FROM `$prefixed`", ARRAY_A);
        self::assert_read_ok('column shape');
        if (!is_array($rows) || $rows === []) {
            throw new \RuntimeException("wprism: adapter probe read no columns for '$prefixed'; refusing to infer an empty schema");
        }
        foreach ($rows as $row) {
            self::assert_identifier((string) ($row['Field'] ?? ''), 'column');
        }
        return array_values($rows);
    }

    /**
     * @return array<string,array{type:string,nullable:bool}> live column shape
     */
    private static function columns_of(string $prefixed): array {
        $out = [];
        foreach (self::column_rows($prefixed) as $row) {
            $out[(string) $row['Field']] = [
                // `Null` is the string 'YES'/'NO' in mysqli's text protocol.
                'nullable' => strtoupper((string) ($row['Null'] ?? '')) === 'YES',
                'type' => self::normalized_type((string) ($row['Type'] ?? '')),
            ];
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * PRIMARY KEY, unique keys and per-column lock coverage from ONE
     * `SHOW INDEX FROM` — the statement `lock_index()` itself runs, read with
     * its own two fields (`Seq_in_index`, `Sub_part`). Coverage is therefore
     * expressed in the deletion guard's terms: the covering index is the
     * first index whose FIRST physical column is that column, and `prefix` is
     * the `Sub_part` width `lock_index()` compares a meta_key length against
     * (`DeleteGuardEvaluator.php:445-456`).
     *
     * @param list<string> $columnNames
     * @return array{0:list<string>,1:array<string,list<string>>,2:array<string,array{index:?string,prefix:?int}>}
     */
    private static function indexes_of(string $prefixed, array $columnNames): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results("SHOW INDEX FROM `$prefixed`", ARRAY_A);
        self::assert_read_ok('index inventory');
        if (!is_array($rows)) {
            throw new \RuntimeException("wprism: adapter probe could not read the index inventory for '$prefixed'");
        }

        $indexes = [];
        $unique = [];
        foreach ($rows as $row) {
            $name = (string) ($row['Key_name'] ?? '');
            // `lock_index()` strips exotic characters and then interpolates
            // the surviving name; a name outside the portable grammar is one
            // no answer here could describe honestly, so the probe refuses
            // the table rather than reporting a silently rewritten index.
            self::assert_identifier($name, 'index');
            $seq = (int) ($row['Seq_in_index'] ?? 0);
            if ($seq <= 0) {
                throw new \RuntimeException("wprism: adapter probe read a malformed index ordinal on '$prefixed'");
            }
            $column = (string) ($row['Column_name'] ?? '');
            self::assert_identifier($column, 'column');
            $indexes[$name][$seq] = [
                'column' => $column,
                'prefix' => isset($row['Sub_part']) && $row['Sub_part'] !== null ? (int) $row['Sub_part'] : null,
            ];
            $unique[$name] = (int) ($row['Non_unique'] ?? 1) === 0;
        }

        $primaryKey = [];
        $uniqueKeys = [];
        foreach ($indexes as $name => $parts) {
            ksort($parts, SORT_NUMERIC);
            $ordered = array_values(array_map(static fn(array $part): string => $part['column'], $parts));
            if ($name === 'PRIMARY') {
                $primaryKey = $ordered;
                continue;
            }
            if ($unique[$name]) {
                $uniqueKeys[$name] = $ordered;
            }
        }
        ksort($uniqueKeys, SORT_STRING);

        $coverage = [];
        foreach ($columnNames as $column) {
            $coverage[$column] = ['index' => null, 'prefix' => null];
            foreach ($indexes as $name => $parts) {
                ksort($parts, SORT_NUMERIC);
                $first = reset($parts);
                if (($first['column'] ?? '') === $column) {
                    $coverage[$column] = ['index' => $name, 'prefix' => $first['prefix']];
                    break;
                }
            }
        }
        ksort($coverage, SORT_STRING);
        return [$primaryKey, $uniqueKeys, $coverage];
    }

    /**
     * Declared FOREIGN KEY constraints, as column => referenced table.
     *
     * Presence is the whole fact. WordPress schemas famously declare none, so
     * an empty map is the common answer and a non-empty one tells a reviewer
     * that this table's row lifetime is already constrained by the server —
     * which is context for a deletion decision, never authority for one.
     *
     * @return array<string,string>
     */
    private static function foreign_keys_of(string $prefixed): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT COLUMN_NAME, REFERENCED_TABLE_NAME\n"
            . 'FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() '
            . 'AND TABLE_NAME = %s AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY COLUMN_NAME',
            $prefixed
        ), ARRAY_A);
        self::assert_read_ok('foreign key inventory');
        if (!is_array($rows)) {
            throw new \RuntimeException("wprism: adapter probe could not read the foreign keys of '$prefixed'");
        }
        $out = [];
        foreach ($rows as $row) {
            $column = (string) ($row['COLUMN_NAME'] ?? '');
            $referenced = (string) ($row['REFERENCED_TABLE_NAME'] ?? '');
            self::assert_identifier($column, 'column');
            self::assert_identifier($referenced, 'table');
            $out[$column] = $referenced;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    /**
     * The EAV twin: the `<table>meta` / `<table>_meta` sidecar a plugin puts
     * its per-row key/value pairs in. A draft that declares the parent table
     * and misses its twin captures half an entity, so "does one exist, and
     * what are its three columns" is a question worth answering from the
     * target — and the answer is named in the draft's own vocabulary
     * (unprefixed), so it can be declared as `tables.<twin>` if ratified.
     *
     * @return ?array{table:string,parent_column:?string,key_column:string,value_column:string}
     */
    private static function eav_twin_of(string $table, string $prefixed): ?array {
        global $wpdb;
        foreach (self::eav_twin_candidates($table) as $candidate) {
            $twinPrefixed = $wpdb->prefix . $candidate;
            if ($twinPrefixed === $prefixed || !self::table_exists($twinPrefixed)) {
                continue;
            }
            $rows = self::column_rows($twinPrefixed);
            $columns = array_map(static fn(array $row): string => (string) $row['Field'], $rows);
            $keyColumn = self::first_matching($columns, ['meta_key', 'key'], '_key');
            $valueColumn = self::first_matching($columns, ['meta_value', 'value'], '_value');
            if ($keyColumn === null || $valueColumn === null) {
                // A neighbour table whose name merely ends in `meta` is not an
                // EAV twin; reporting it as one would invent a shape.
                continue;
            }
            $parentColumn = null;
            foreach ($rows as $row) {
                $column = (string) $row['Field'];
                // `wp_postmeta.meta_id` is the twin's OWN surrogate key, not
                // the reference to its parent — every core meta table has both,
                // and naming the wrong one would point a ratified declaration
                // at the sidecar itself.
                if ($column === $keyColumn || $column === $valueColumn
                    || strtoupper((string) ($row['Key'] ?? '')) === 'PRI') {
                    continue;
                }
                if ($column === $table . '_id' || str_ends_with($column, '_id')) {
                    $parentColumn = $column;
                    break;
                }
            }
            return [
                'key_column' => $keyColumn,
                'parent_column' => $parentColumn,
                'table' => $candidate,
                'value_column' => $valueColumn,
            ];
        }
        return null;
    }

    /**
     * The names a plugin actually spells its meta sidecar with, in order.
     *
     * The first two are the concatenations core itself uses — `wp_postmeta`
     * beside `wp_posts`, and the underscored variant `wp_wpforms_tasks_meta`.
     * The third and fourth are the SINGULARIZED stem, and they exist because
     * the first two miss a real, common shape: a plugin that pluralizes the
     * parent table usually does NOT pluralize the twin. Measured on WPForms
     * Lite 2.0.0.5, whose `wp_wpforms_payments` twin is
     * `wp_wpforms_payment_meta` (`id` PK, `payment_id`, `meta_key`,
     * `meta_value`) — a textbook EAV pair the plugin models as one entity
     * (`src/Db/Payments/Payment.php` and `Meta.php`) — this heuristic probed
     * `wp_wpforms_paymentsmeta` and `wp_wpforms_payments_meta`, found neither,
     * and reported `eav_twin: null` while
     * `docs/guides/adapter-authoring.md:1008` advertises `[eav_twin]` as one
     * of the named questions "one command can answer".
     *
     * The strip is deliberately the naive trailing `s` and nothing more: this
     * is a NAME guess whose only consequence is one extra `SHOW TABLES LIKE`,
     * and every candidate still has to pass the key/value column screen below
     * before it is reported as a twin. A wrong guess (`address` -> `addres`)
     * costs one existence probe that answers no.
     *
     * @return list<string>
     */
    private static function eav_twin_candidates(string $table): array {
        $candidates = [$table . 'meta', $table . '_meta'];
        if (strlen($table) > 1 && str_ends_with($table, 's')) {
            $stem = substr($table, 0, -1);
            $candidates[] = $stem . '_meta';
            $candidates[] = $stem . 'meta';
        }
        return array_values(array_unique($candidates));
    }

    /**
     * Keyspace-wide uniqueness of one column, as ONE statement.
     *
     * `COUNT(DISTINCT col)` is the only honest form: enumerating the keyspace
     * to compare it in PHP would read every value, which boundary 2 forbids,
     * and would be unbounded on a real table. Two properties of the answer
     * are load-bearing for the reviewer and are stated rather than smoothed:
     * NULLs are excluded from the distinct count (so a nullable column with
     * NULL rows reports `unique: false`), and equality here is the column's
     * own collation — a `utf8mb4_*_ci` column that holds both `A` and `a`
     * reports them as ONE distinct value, which is exactly what a UNIQUE
     * index on it would enforce.
     *
     * @return array{column:string,rows:int,distinct:int,unique:bool}
     */
    private static function natural_key_uniqueness(string $prefixed, string $column): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results(
            "SELECT COUNT(*) AS row_count, COUNT(DISTINCT `$column`) AS distinct_count FROM `$prefixed`",
            ARRAY_A
        );
        self::assert_read_ok('natural key uniqueness');
        if (!is_array($rows) || !isset($rows[0]['row_count'], $rows[0]['distinct_count'])) {
            throw new \RuntimeException("wprism: adapter probe could not count '$prefixed.$column'");
        }
        $total = (int) $rows[0]['row_count'];
        $distinct = (int) $rows[0]['distinct_count'];
        return [
            'column' => $column,
            'distinct' => $distinct,
            'rows' => $total,
            'unique' => $total > 0 && $total === $distinct,
        ];
    }

    /**
     * @param list<string> $columns
     * @param list<string> $exact
     */
    private static function first_matching(array $columns, array $exact, string $suffix): ?string {
        foreach ($exact as $name) {
            if (in_array($name, $columns, true)) {
                return $name;
            }
        }
        foreach ($columns as $column) {
            if (str_ends_with($column, $suffix)) {
                return $column;
            }
        }
        return null;
    }

    /**
     * Boundary 2 in one function: `enum('draft','publish')` and `set(…)`
     * carry SITE VALUES inside the type string, so anything outside the
     * bounded numeric/width form is reduced to its base word. The base word
     * is the whole fact a reviewer needs; the members are values.
     */
    private static function normalized_type(string $type): string {
        $type = strtolower(trim($type));
        if (preg_match('/^[a-z]+(\([0-9]+(,[0-9]+)?\))?( unsigned)?( zerofill)?$/D', $type) === 1) {
            return $type;
        }
        return preg_match('/^([a-z]+)/', $type, $m) === 1 ? $m[1] : 'unknown';
    }

    private static function assert_identifier(string $value, string $kind): void {
        if (preg_match(self::IDENTIFIER, $value) !== 1) {
            // The value itself is never echoed: an unsafe identifier is the
            // one string in this document nothing has vetted.
            throw new \RuntimeException("wprism: adapter probe refused a $kind name outside the portable identifier grammar");
        }
    }

    /**
     * The same grammar, for a name the AUTHOR typed into `--tables=` or
     * `--natural-keys=` rather than one the server returned. One regex, two
     * refusal classes: a malformed server identifier is operator evidence,
     * while a malformed name in an argument is the author's own typo and has
     * to reach the terminal rather than the redacted catch-all.
     */
    private static function assert_authored_identifier(string $value, string $kind): void {
        if (preg_match(self::IDENTIFIER, $value) !== 1) {
            throw self::refuse(
                "adapter probe refused a $kind name outside the portable identifier grammar",
                "spell every $kind with the portable identifier characters [A-Za-z0-9_] and WITHOUT the site's "
                    . 'table prefix, exactly as a manifest `tables` section does, then rerun adapter-probe'
            );
        }
    }

    /**
     * A refusal about the ARGUMENTS — what the author asked this probe for.
     *
     * Typed for the reason `DeletionFeasibility::refuse()` states at length:
     * `Cli::halt_json_failure()` publishes a message only from a typed refusal
     * and every other Throwable becomes "adapter-probe refused at an
     * unclassified safety gate" with `details_redacted: true`
     * (`agent/src/Command/Cli.php:88-104`). These sentences are about the
     * author's own arguments, every value they interpolate has passed
     * `IDENTIFIER` first, and the author cannot act without them.
     * `invalid_arguments` is this verb's own reason code for its argument
     * gate (`Cli.php:3221-3252`).
     */
    private static function refuse(string $publicMessage, string $remediation): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            $publicMessage,
            $remediation,
            [],
            'wprism: ' . $publicMessage
        );
    }

    /**
     * `wpdb::get_results()` answers `[]` on a failed read (it returns
     * `last_result`, which `flush()` already emptied), so an empty array is
     * not evidence of an empty schema — `$last_error` is the only positive
     * signal, and every read here checks it rather than inferring.
     */
    private static function assert_read_ok(string $what): void {
        global $wpdb;
        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: adapter probe could not read the $what; refusing to infer it");
        }
    }

    /** @return list<string> the closed limitations, stated in the document itself */
    private static function deferred(): array {
        return [
            'a probe proposes no class, identity, deletion authority or capability: it converts a structural '
                . 'guess into a live fact so the human ratification in adapter-draft is better founded',
            'row values are never read; the only row-derived numbers are COUNT(*) and COUNT(DISTINCT <column>)',
            'enum/set member lists are reduced to the base type word, because those members are site values '
                . 'carried inside the MySQL type string',
            'a table this target does not have is reported present:false rather than omitted, so a missing '
                . 'declaration stays distinguishable from a probe that never ran',
            'uniqueness is measured in the column\'s own collation and excludes NULL rows, exactly as a UNIQUE '
                . 'index on that column would',
        ];
    }

    private static function agent_version(): string {
        return defined('WPRISM_AGENT_VERSION') ? (string) WPRISM_AGENT_VERSION : 'unknown';
    }

    private static function spec_version(): int {
        return defined('WPRISM_SPEC_VERSION') ? (int) WPRISM_SPEC_VERSION : 0;
    }
}
