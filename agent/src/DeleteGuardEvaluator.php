<?php
namespace Duo;

/**
 * Lock-boundary proof for manifest-declared deletion guards.
 *
 * A deletion guard's final `SELECT ... FOR UPDATE` is only authoritative
 * when its first equality/range column is covered by an index. This small
 * evaluator owns that schema proof; Apply keeps the transaction lifecycle,
 * query construction, witness capture, and forced-warning orchestration.
 *
 * The class deliberately has no constructor and no dependency on Apply or
 * Policy. It evaluates only the manifest guard plus the target's inspected
 * index metadata, making the race-boundary rule directly characterizable.
 */
final class DeleteGuardEvaluator {
    /**
     * Prove that each fully-qualified guard table can sustain the locking
     * boundary. A successful `SHOW TABLES` is not enough: a target can have
     * a visible MyISAM table, where `SELECT ... FOR UPDATE` cannot give the
     * transaction the gap-lock guarantee that a destructive guard promises.
     *
     * The caller supplies the already-sanitized, prefixed table names. This
     * evaluator deliberately does not read a Policy or infer a plugin table:
     * adapter-owned deletion declarations remain the source of that meaning.
     *
     * @param list<string> $tables
     */
    public static function assert_innodb_tables(array $tables): void {
        global $wpdb;

        $tables = array_values(array_unique(array_filter(
            $tables,
            static fn(mixed $table): bool => is_string($table) && $table !== ''
        )));
        if (!$tables) {
            return;
        }
        sort($tables, SORT_STRING);
        $tableSet = array_fill_keys($tables, true);
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));

        // Lock each table's metadata before asking information_schema for its
        // engine. A concurrent ALTER/RENAME/DROP must either finish before
        // this read (so we inspect the resulting table) or wait for this
        // transaction to end; otherwise an engine row could become stale
        // between the check and the first SELECT ... FOR UPDATE. This is a
        // harmless data read, not a row lock, and occurs before authored
        // target mutations.
        foreach ($tables as $table) {
            $wpdb->last_error = '';
            $probe = $wpdb->get_var("SELECT 1 FROM `$table` LIMIT 1");
            $error = trim((string) ($wpdb->last_error ?? ''));
            if ($probe === false || $error !== '') {
                $detail = $error !== '' ? $error : 'no result returned';
                throw new \RuntimeException(
                    'duo: deletion guard locking refused — unable to acquire metadata lock for guard table '
                    . "$table: $detail"
                );
            }
        }

        // wpdb can retain the previous failed-query message (notably when
        // the modern isolation variable probe falls back to tx_isolation),
        // so clear it before this independent introspection query.
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT TABLE_NAME, ENGINE FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ($placeholders)
             ORDER BY TABLE_NAME ASC",
            ...$tables
        ), ARRAY_A);
        $error = trim((string) ($wpdb->last_error ?? ''));
        if ($rows === false || $rows === null || $error !== '') {
            $detail = $error !== '' ? $error : 'no result returned';
            throw new \RuntimeException(
                'duo: deletion guard locking refused — storage-engine introspection failed for '
                . implode(', ', $tables) . ": $detail"
            );
        }

        $engines = [];
        $duplicateRows = [];
        foreach ((array) $rows as $row) {
            $name = (string) ($row['TABLE_NAME'] ?? '');
            if ($name === '' || !isset($tableSet[$name])) {
                continue;
            }
            if (array_key_exists($name, $engines)) {
                $duplicateRows[$name] = true;
                continue;
            }
            $engine = $row['ENGINE'] ?? null;
            $engines[$name] = $engine === null || trim((string) $engine) === ''
                ? null
                : strtoupper(trim((string) $engine));
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
        foreach (array_keys($duplicateRows) as $name) {
            $unknown[] = "$name (duplicate information_schema rows)";
        }
        sort($missing, SORT_STRING);
        sort($unknown, SORT_STRING);
        sort($unsupported, SORT_STRING);
        if ($missing || $unknown || $unsupported) {
            $details = [];
            if ($missing) {
                $details[] = 'missing from information_schema.TABLES: ' . implode(', ', $missing);
            }
            if ($unknown) {
                $details[] = 'unknown engine: ' . implode(', ', $unknown);
            }
            if ($unsupported) {
                $details[] = 'unsupported engine (InnoDB required): ' . implode(', ', $unsupported);
            }
            throw new \RuntimeException(
                'duo: deletion guard locking refused — ' . implode('; ', $details)
            );
        }
    }

    /**
     * Prove that the current transaction supplies gap locks for the guard
     * boundary. Record locks alone leave a concurrent insert able to pass a
     * checked reference range and become dangling after the target delete.
     * The server-family fallback is kept here with the storage proof so every
     * caller receives the same fail-closed isolation contract.
     */
    public static function assert_transaction_isolation(): void {
        global $wpdb;

        $level = $wpdb->get_var('SELECT @@transaction_isolation');
        if ($level === null || !empty($wpdb->last_error)) {
            // MariaDB and older MySQL expose the same session setting under
            // the historical tx_isolation name; MySQL 8 keeps the modern
            // transaction_isolation spelling. Probe both without assuming a
            // particular server family.
            $wpdb->last_error = '';
            $level = $wpdb->get_var('SELECT @@tx_isolation');
        }
        if ($level === null || !in_array(strtoupper((string) $level), ['REPEATABLE-READ', 'SERIALIZABLE'], true)) {
            throw new \RuntimeException(
                'duo: deletion guard locking requires REPEATABLE-READ or SERIALIZABLE transaction isolation; refusing unsafe target'
            );
        }
    }

    /**
     * Collect generic deletion-guard findings from one locked or ordinary
     * read. Manifest capability resolution and reference decoding stay with
     * Apply; this contract only combines each guard's count/error result into
     * the deterministic block and warning-witness shapes used by the facade.
     *
     * @param list<array<string,mixed>> $guards
     * @param callable(array<string,mixed>,bool):array{count:int,error:?string,rows:list<string>} $countRefs
     * @param callable(string):bool $isRepairable
     * @return array{blocks:list<string>,guard_refs:list<array{table:string,rows:list<string>,repairable:bool,option_name_ref:bool}>}
     */
    public static function reference_findings(
        array $guards,
        callable $countRefs,
        callable $isRepairable,
        bool $forUpdate = false
    ): array {
        $blocks = [];
        $guardRefs = [];
        foreach ($guards as $guard) {
            $result = $countRefs($guard, $forUpdate);
            if ($result['error'] !== null) {
                $blocks[] = $result['error'];
            } elseif ($result['count'] > 0) {
                $blocks[] = ($guard['reason'] ?? "referenced by {$guard['table']}.{$guard['column']}")
                    . " — {$result['count']} row(s)";
                $guardRefs[] = [
                    'table' => (string) $guard['table'],
                    'rows' => $result['rows'],
                    'repairable' => $isRepairable((string) $guard['table']),
                    'option_name_ref' => !empty($guard['option_name_ref']),
                ];
            }
        }
        return ['blocks' => $blocks, 'guard_refs' => $guardRefs];
    }

    /**
     * Resolve an index which covers the first equality/range column of a
     * manifest guard. A prefix index is accepted only when the declared
     * metadata key fits entirely inside that prefix; otherwise inserts with
     * the same visible prefix could still evade the gap lock.
     */
    public static function lock_index(array $guard, string $table): ?string {
        global $wpdb;
        $lockColumn = array_key_exists('meta_key', $guard) || array_key_exists('ref', $guard)
            ? 'meta_key'
            : (!empty($guard['option_name_ref']) ? 'option_name' : (string) ($guard['column'] ?? ''));
        $rows = $wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A) ?: [];
        $indexes = [];
        foreach ($rows as $row) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($row['Key_name'] ?? ''));
            $seq = (int) ($row['Seq_in_index'] ?? 0);
            if ($name === '' || $seq <= 0) {
                continue;
            }
            $indexes[$name][$seq] = [
                'column' => preg_replace('/[^A-Za-z0-9_]/', '', (string) ($row['Column_name'] ?? '')),
                'prefix' => isset($row['Sub_part']) && $row['Sub_part'] !== null
                    ? (int) $row['Sub_part']
                    : null,
            ];
        }
        foreach ($indexes as $name => $parts) {
            ksort($parts, SORT_NUMERIC);
            $first = reset($parts);
            if (($first['column'] ?? '') !== $lockColumn) {
                continue;
            }
            $prefix = $first['prefix'] ?? null;
            if ($prefix !== null && array_key_exists('meta_key', $guard)
                && strlen((string) $guard['meta_key']) > $prefix) {
                continue;
            }
            return $name;
        }
        return null;
    }
}
