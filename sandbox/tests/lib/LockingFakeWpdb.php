<?php
/**
 * Transaction/schema-fact adapter for the shared row-backed FakeWpdb.
 *
 * FakeWpdb intentionally refuses information_schema, SHOW INDEX and locking
 * syntax because inventing those facts inside a row interpreter would make
 * ordinary tests prove the fake's assumptions. Product-path lock tests supply
 * the reviewed facts explicitly here while every data query/mutation still
 * runs through FakeWpdb's loud SQL grammar.
 */
declare(strict_types=1);

namespace WPrismTest;

require_once __DIR__ . '/FakeWpdb.php';

final class LockingFakeWpdb {
    public string $prefix;
    public string $base_prefix;
    public string $posts;
    public string $postmeta;
    public string $comments;
    public string $commentmeta;
    public string $terms;
    public string $term_taxonomy;
    public string $term_relationships;
    public string $termmeta;
    public string $options;
    public string $users;
    public string $usermeta;
    public string $links;
    public string $last_error = '';
    public int $insert_id = 0;
    public int $rows_affected = 0;
    public int $num_rows = 0;

    private bool $activeTransaction = false;
    private string $connectionId = '8101';
    private bool $nextRepeatableRead = false;
    private bool $savepointExists = false;
    /** @var array<string,string> */
    private array $engines = [];
    /** @var array<string,list<array<string,mixed>>> */
    private array $indexes = [];

    public function __construct(private readonly FakeWpdb $inner = new FakeWpdb()) {
        foreach ([
            'prefix', 'base_prefix', 'posts', 'postmeta', 'comments', 'commentmeta',
            'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'options',
            'users', 'usermeta', 'links',
        ] as $property) {
            $this->$property = $inner->$property;
        }
    }

    public function inner(): FakeWpdb {
        return $this->inner;
    }

    public function addInnoDbTable(string $table): self {
        $this->engines[$table] = 'InnoDB';
        return $this;
    }

    /**
     * $seq records a composite index's later parts. It exists because a row
     * LABEL can depend on the whole key, not just its first column:
     * DeleteGuardReferenceScanner::count() falls back to the PRIMARY key's
     * full column list when a guard declares no source_pk
     * (agent/src/Delete/DeleteGuardReferenceScanner.php:122-131), and
     * wp_term_relationships' PRIMARY is (object_id, term_taxonomy_id) -- a
     * single-column stand-in would silently shorten every surviving-row label
     * the forced-delete warning prints.
     */
    public function addIndex(
        string $table,
        string $name,
        string $column,
        bool $unique = false,
        int $seq = 1
    ): self {
        $this->indexes[$table][] = [
            'Key_name' => $name,
            'Seq_in_index' => (string) $seq,
            'Column_name' => $column,
            'Sub_part' => null,
            'Non_unique' => $unique ? '0' : '1',
            'Index_type' => 'BTREE',
        ];
        return $this;
    }

    public function __call(string $name, array $arguments): mixed {
        $this->syncIn();
        $result = $this->inner->$name(...$arguments);
        $this->syncOut();
        return $result === $this->inner ? $this : $result;
    }

    public function get_var(string $sql, int $x = 0, int $y = 0): mixed {
        $this->last_error = '';
        if (trim($sql) === 'SELECT CONNECTION_ID()') {
            return $this->connectionId;
        }
        if (trim($sql) === 'SELECT @@in_transaction') {
            return $this->activeTransaction ? '1' : '0';
        }
        if (trim($sql) === 'SELECT @@transaction_isolation') {
            return 'REPEATABLE-READ';
        }
        if (preg_match('/^SELECT 1 FROM `([A-Za-z0-9_]{1,64})` LIMIT 1$/D', trim($sql), $match) === 1
            && isset($this->engines[$match[1]])) {
            return '1';
        }
        return $this->forward('get_var', [$this->stripLockSyntax($sql), $x, $y]);
    }

    public function get_results(string $sql, string $output = OBJECT): mixed {
        $this->last_error = '';
        if (str_contains($sql, 'information_schema.TABLES')) {
            preg_match_all("/'((?:''|[^'])+)'/", $sql, $matches);
            $requested = array_fill_keys(array_map(
                static fn(string $table): string => str_replace("''", "'", $table),
                $matches[1] ?? []
            ), true);
            $rows = [];
            foreach ($this->engines as $table => $engine) {
                if (!isset($requested[$table])) {
                    continue;
                }
                $rows[] = ['TABLE_NAME' => $table, 'ENGINE' => $engine];
            }
            usort($rows, static fn(array $a, array $b): int => strcmp($a['TABLE_NAME'], $b['TABLE_NAME']));
            return $rows;
        }
        // SHOW KEYS is SHOW INDEX's synonym, and the one caller that uses it
        // filters: DeleteGuardReferenceScanner::count() reads
        // `SHOW KEYS FROM \`t\` WHERE Key_name = 'PRIMARY'`
        // (agent/src/Delete/DeleteGuardReferenceScanner.php:124) to learn a
        // guard table's row identity when the guard declares no source_pk.
        // Answering it unfiltered would hand that reader every index and let
        // it label rows by a secondary key's columns.
        if (preg_match(
            '/^SHOW (?:INDEX|KEYS) FROM `([A-Za-z0-9_]{1,64})`'
            . "(?: WHERE Key_name = '([A-Za-z0-9_]{1,64})')?\$/D",
            trim($sql),
            $match
        ) === 1) {
            $rows = $this->indexes[$match[1]] ?? [];
            if (($match[2] ?? '') === '') {
                return $rows;
            }
            return array_values(array_filter(
                $rows,
                static fn(array $row): bool => (string) $row['Key_name'] === $match[2]
            ));
        }
        return $this->forward('get_results', [$this->stripLockSyntax($sql), $output]);
    }

    public function get_row(string $sql, string $output = OBJECT, int $y = 0): mixed {
        return $this->forward('get_row', [$this->stripLockSyntax($sql), $output, $y]);
    }

    public function get_col(string $sql, int $x = 0): mixed {
        return $this->forward('get_col', [$this->stripLockSyntax($sql), $x]);
    }

    public function query(string $sql): mixed {
        $sql = trim($sql);
        $this->last_error = '';
        if ($sql === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') {
            if ($this->activeTransaction) {
                $this->last_error = 'transaction already active';
                return false;
            }
            $this->nextRepeatableRead = true;
            return 1;
        }
        if (preg_match('/^SAVEPOINT `wprism_authored_[0-9a-f]{24}`$/D', $sql) === 1) {
            if (!$this->activeTransaction) {
                $this->last_error = 'no active transaction';
                return false;
            }
            $this->savepointExists = true;
            return 1;
        }
        if (preg_match('/^RELEASE SAVEPOINT `wprism_authored_[0-9a-f]{24}`$/D', $sql) === 1) {
            if (!$this->activeTransaction || !$this->savepointExists) {
                $this->last_error = 'SAVEPOINT does not exist';
                return false;
            }
            $this->savepointExists = false;
            return 1;
        }
        $result = $this->forward('query', [$sql]);
        if ($result !== false) {
            if (in_array(strtoupper($sql), ['START TRANSACTION', 'BEGIN'], true)) {
                if (!$this->nextRepeatableRead) {
                    $this->last_error = 'repeatable-read one-shot isolation was not set';
                    return false;
                }
                $this->nextRepeatableRead = false;
                $this->activeTransaction = true;
            } elseif (in_array(strtoupper($sql), ['COMMIT', 'ROLLBACK'], true)) {
                $this->activeTransaction = false;
                $this->savepointExists = false;
            }
        }
        return $result;
    }

    private function forward(string $method, array $arguments): mixed {
        $this->syncIn();
        $result = $this->inner->$method(...$arguments);
        $this->syncOut();
        return $result;
    }

    private function syncIn(): void {
        $this->inner->last_error = $this->last_error;
    }

    private function syncOut(): void {
        $this->last_error = $this->inner->last_error;
        $this->insert_id = $this->inner->insert_id;
        $this->rows_affected = $this->inner->rows_affected;
        $this->num_rows = $this->inner->num_rows;
    }

    private function stripLockSyntax(string $sql): string {
        $sql = (string) preg_replace('/ FORCE INDEX \(`[^`]+`\)/', '', $sql);
        return (string) preg_replace('/ FOR UPDATE\s*$/D', '', $sql);
    }
}
