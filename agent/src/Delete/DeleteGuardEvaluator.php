<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/DatabaseLockBoundary.php';
require_once __DIR__ . '/../Kernel/DatabaseTablePresence.php';
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}

/**
 * Lock-boundary proof for manifest-declared deletion guards.
 *
 * A deletion guard's final `SELECT ... FOR UPDATE` is only authoritative
 * when its first equality/range column is covered by an index. This small
 * evaluator owns that schema proof, generic findings, plan annotation, the
 * final recheck decision, and the locked witness comparison; Apply keeps the
 * transaction lifecycle, query construction, target-reference decoding, and
 * forced-warning formatting.
 *
 * The class deliberately has no constructor and no dependency on Apply or
 * Policy. It evaluates only the manifest guard plus the target's inspected
 * index metadata, making the race-boundary rule directly characterizable.
 */
final class DeleteGuardEvaluator {
    private static bool $authoredTransaction = false;

    /**
     * Census exact guard-table topology without turning a read failure or a
     * case-fold/LIKE near-match into destructive authority.
     *
     * @param list<string> $tables
     * @return array<string,string> exact table name => absent|present
     */
    public static function guard_table_topology(
        array $tables,
        string $purpose = 'deletion guard topology'
    ): array {
        self::assert_table_identifiers($tables, $purpose);
        $tables = array_values(array_unique($tables));
        sort($tables, SORT_STRING);
        if ($tables === []) {
            return [];
        }

        $topology = [];
        foreach ($tables as $table) {
            try {
                $present = DatabaseTablePresence::base_table_exists($table);
            } catch (DatabaseTablePresenceException $failure) {
                throw self::table_presence_refusal($table, $purpose, $failure);
            }
            $topology[$table] = $present ? 'present' : 'absent';
        }
        return $topology;
    }

    private static function table_presence_refusal(
        string $table,
        string $purpose,
        DatabaseTablePresenceException $failure
    ): \RuntimeException {
        return match ($failure->reason()) {
            DatabaseTablePresenceException::ABSENCE_AMBIGUOUS => new \RuntimeException(
                "wprism: $purpose refused — exact absence confirmation returned an ambiguous result for $table",
                0,
                $failure
            ),
            DatabaseTablePresenceException::DIAGNOSTIC_UNREADABLE => new \RuntimeException(
                "wprism: $purpose refused — exact absence confirmation could not read the server diagnostic for $table",
                0,
                $failure
            ),
            DatabaseTablePresenceException::DIAGNOSTIC_MALFORMED => new \RuntimeException(
                "wprism: $purpose refused — exact absence confirmation returned a malformed server diagnostic for $table",
                0,
                $failure
            ),
            DatabaseTablePresenceException::DIAGNOSTIC_UNEXPECTED => new \RuntimeException(
                "wprism: $purpose refused — exact absence confirmation failed for $table ("
                    . (count($failure->error_codes()) === 1
                        ? 'server code ' . $failure->error_codes()[0]
                        : 'ambiguous server diagnostics')
                    . ')',
                0,
                $failure
            ),
            DatabaseTablePresenceException::NOT_PLAIN_BASE_TABLE => new \RuntimeException(
                "wprism: $purpose refused — exact guard-table topology census returned an ambiguous table identity",
                0,
                $failure
            ),
            DatabaseTablePresenceException::RESOLUTION_UNREADABLE => new \RuntimeException(
                "wprism: $purpose refused — exact guard-table topology census failed: "
                    . "could not resolve physical table $table",
                0,
                $failure
            ),
            default => new \RuntimeException(
                "wprism: $purpose refused — exact absence confirmation failed for $table (ambiguous server diagnostics)",
                0,
                $failure
            ),
        };
    }

    /** Establish the transaction identity immediately after START TRANSACTION. */
    public static function begin_authored_transaction(): void {
        self::$authoredTransaction = false;
        self::assert_active_transaction('authored transaction continuity');
        self::$authoredTransaction = true;
    }

    /** Forget process-local proof state after either COMMIT or ROLLBACK. */
    public static function end_authored_transaction(): void {
        self::$authoredTransaction = false;
    }
    /**
     * Prove that each fully-qualified guard table can sustain the locking
     * boundary. A successful `SHOW TABLES` is not enough: a target can have
     * a visible MyISAM table, where `SELECT ... FOR UPDATE` cannot give the
     * transaction the gap-lock guarantee that a destructive guard promises.
     *
     * This evaluator deliberately does not read a Policy or infer a plugin
     * table: adapter-owned declarations remain the source of that meaning.
     * It does validate every resolved identifier before interpolation because
     * wpdb table properties are runtime input at this boundary.
     *
     * @param list<string> $tables
     */
    public static function assert_innodb_tables(
        array $tables,
        string $purpose = 'deletion guard locking'
    ): void {
        DatabaseLockBoundary::assert_innodb_tables($tables, $purpose);
    }

    /** @param list<string> $tables */
    public static function assert_atomic_mutation_tables(
        array $tables,
        string $purpose
    ): void {
        DatabaseLockBoundary::assert_atomic_mutation_tables($tables, $purpose);
    }

    /**
     * Recheck the transaction established by Db::start_repeatable_read().
     * SET TRANSACTION positively controls the active transaction without a
     * privileged server-introspection query; the unique savepoint proves no
     * callback committed and replaced that transaction before this lock.
     */
    public static function assert_transaction_isolation(
        string $purpose = 'deletion guard locking'
    ): void {
        if (!self::$authoredTransaction) {
            throw new \RuntimeException(
                "wprism: $purpose requires the authored transaction boundary"
            );
        }
        try {
            Db::transaction_authority($purpose . ' authored transaction continuity');
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                "wprism: $purpose lost authored transaction continuity",
                0,
                $failure
            );
        }
    }

    public static function assert_active_transaction(string $purpose): void {
        try {
            Db::transaction_authority($purpose . ' active transaction');
        } catch (\Throwable $failure) {
            throw new \RuntimeException("wprism: $purpose requires an active transaction");
        }
    }

    /** @param list<string> $tables */
    public static function assert_table_identifiers(array $tables, string $purpose): void {
        DatabaseLockBoundary::assert_table_identifiers($tables, $purpose);
    }

    /**
     * Collect generic deletion-guard findings from one locked or ordinary
     * read. Manifest capability resolution and reference decoding stay with
     * Apply; this contract only combines each guard's count/error result into
     * the deterministic block and warning-witness shapes used by the facade.
     *
     * @param list<array<string,mixed>> $guards
     * @param callable(array<string,mixed>,bool):array{count:int,error:?string,rows:list<string>,witness?:string} $countRefs
     * @param callable(string):bool $isRepairable
     * @return array{blocks:list<string>,non_forceable_blocks:list<string>,guard_refs:list<array{table:string,rows:list<string>,repairable:bool,option_name_ref:bool}>,guard_witnesses:array<string,string>}
     */
    public static function reference_findings(
        array $guards,
        callable $countRefs,
        callable $isRepairable,
        bool $forUpdate = false,
        string $emptyWitness = ''
    ): array {
        $blocks = [];
        $nonForceableBlocks = [];
        $guardRefs = [];
        $guardWitnesses = [];
        foreach ($guards as $guardIndex => $guard) {
            $result = $countRefs($guard, $forUpdate);
            $guardWitnesses[(string) $guardIndex] = (string) ($result['witness'] ?? $emptyWitness);
            if ($result['error'] !== null) {
                $block = $result['error'];
                $blocks[] = $block;
                // An unreadable non-forceable guard is at least as unsafe as
                // an observed live row. Force cannot turn failed evidence
                // into proof that Woo's owning lifecycle is clear.
                if (($guard['forceable'] ?? null) === false) {
                    $nonForceableBlocks[] = $block;
                }
            } elseif ($result['count'] > 0) {
                $block = ($guard['reason'] ?? "referenced by {$guard['table']}.{$guard['column']}")
                    . " — {$result['count']} row(s)";
                $blocks[] = $block;
                if (($guard['forceable'] ?? null) === false) {
                    $nonForceableBlocks[] = $block;
                }
                $guardRefs[] = [
                    'table' => (string) $guard['table'],
                    'rows' => $result['rows'],
                    'repairable' => $isRepairable((string) $guard['table']),
                    'option_name_ref' => !empty($guard['option_name_ref']),
                ];
            }
        }
        return [
            'blocks' => $blocks,
            'non_forceable_blocks' => $nonForceableBlocks,
            'guard_refs' => $guardRefs,
            'guard_witnesses' => $guardWitnesses,
        ];
    }

    /**
     * Attach the per-tombstone guard findings to a completed plan. The
     * evaluator owns the shared delete/delete-conflict bucket walk, witness
     * attachment, and the rule that a blocked conflict may not advertise its
     * destructive repository choice. Apply still owns capability resolution,
     * target SQL/reference decoding, and the callbacks which supply those
     * facts; this boundary therefore changes no deletion authority.
     *
     * @param array<string,mixed> $plan
     * @param array<string,array<string,mixed>> $deletionCapabilities
     * @param callable(array<string,mixed>,string,bool):array{count:int,error:?string,rows:list<string>,witness?:string} $countRefs
     * @param callable(string):bool $isRepairable
     * @return array<string,mixed>
     */
    public static function annotate_plan_guard_findings(
        array $plan,
        array $deletionCapabilities,
        callable $countRefs,
        callable $isRepairable,
        string $emptyWitness
    ): array {
        foreach (['delete', 'delete_conflict'] as $bucket) {
            foreach ($plan[$bucket] as &$row) {
                $uuid = (string) $row['uuid'];
                $findings = self::reference_findings(
                    (array) ($deletionCapabilities[$uuid]['guards'] ?? []),
                    static function (array $guard, bool $forUpdate) use ($countRefs, $uuid): array {
                        return $countRefs($guard, $uuid, $forUpdate);
                    },
                    $isRepairable,
                    false,
                    $emptyWitness
                );
                $blocks = $findings['blocks'];
                $guardRefs = $findings['guard_refs'];
                if ($blocks) {
                    $row['blocked'] = implode('; ', $blocks);
                    if ($findings['non_forceable_blocks'] !== []) {
                        $row['non_forceable_guard'] = implode('; ', $findings['non_forceable_blocks']);
                    }
                    if ($bucket === 'delete_conflict' && isset($row['conflict_view']['choices'])) {
                        // A referential guard is a separate authorization
                        // boundary. Do not advertise the destructive
                        // repository-delete choice until those declared
                        // references are repaired; --force-delete-referenced
                        // is report-not-hide but never a "safe choice."
                        $row['conflict_view']['choices'] = array_values(array_filter(
                            (array) $row['conflict_view']['choices'],
                            static fn($choice): bool => is_array($choice)
                                && ($choice['id'] ?? null) !== 'apply_repository'
                        ));
                    }
                }
                if ($guardRefs) {
                    $row['guard_refs'] = $guardRefs;
                }
                $row['guard_witnesses'] = $findings['guard_witnesses'];
            }
            unset($row);
        }
        return $plan;
    }

    /**
     * Re-read every guard for the delete work under the transaction's locking
     * boundary and compare it with the witness captured by the completed
     * plan. The evaluator owns only this deterministic race decision: Apply
     * still establishes the transaction/isolation/storage prerequisites and
     * supplies the target-specific reference-count callback.
     *
     * A missing/error witness is fail-closed and never forceable. The caller's
     * callback receives the same `forUpdate=true` contract used by the
     * ordinary guard reader, so the re-read remains the authoritative locked
     * boundary rather than a second consistent snapshot.
     *
     * @param list<array<string,mixed>> $deleteWork
     * @param callable(array<string,mixed>):list<array<string,mixed>> $guardsForRow
     * @param callable(array<string,mixed>,string,bool):array{error:?string,witness?:string} $countRefs
     */
    public static function assert_revalidated_witnesses(
        array $deleteWork,
        callable $guardsForRow,
        callable $countRefs
    ): void {
        foreach ($deleteWork as $row) {
            $uuid = (string) $row['uuid'];
            foreach ((array) $guardsForRow($row) as $guardIndex => $guard) {
                $result = $countRefs($guard, $uuid, true);
                if ($result['error'] !== null) {
                    throw new \RuntimeException(
                        "wprism: deletion guard lock refused for {$row['type']} {$row['uuid']}: {$result['error']}"
                    );
                }
                $expected = (string) (($row['guard_witnesses'] ?? [])[(string) $guardIndex] ?? '');
                $actual = (string) ($result['witness'] ?? '');
                if ($expected === '' || $actual === '' || !hash_equals($expected, $actual)) {
                    throw new \RuntimeException(
                        "wprism: deletion guard witness changed after planning for {$row['type']} {$row['uuid']}; "
                        . 'no mutation attempted — recompile and retry (force flags cannot bypass this race boundary)'
                    );
                }
            }
        }
    }

    /**
     * Evaluate the last guard read immediately before an explicit tombstone
     * delete. The generic findings evaluator supplies the deterministic
     * blocks and enumerable repair witnesses; this method owns the one
     * fail-closed decision which cannot be bypassed by a force flag. Apply
     * still resolves the capability, supplies target-specific SQL facts, and
     * formats any authorized forced warning from the returned findings.
     *
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $guards
     * @param callable(array<string,mixed>,bool):array{count:int,error:?string,rows:list<string>,witness?:string} $countRefs
     * @param callable(string):bool $isRepairable
     * @return array{blocks:list<string>,non_forceable_blocks:list<string>,guard_refs:list<array{table:string,rows:list<string>,repairable:bool,option_name_ref:bool}>,guard_witnesses:array<string,string>}
     */
    public static function final_recheck_findings(
        array $row,
        array $guards,
        callable $countRefs,
        callable $isRepairable,
        bool $forced,
        bool $forUpdate = false
    ): array {
        $findings = self::reference_findings(
            $guards,
            $countRefs,
            $isRepairable,
            $forUpdate
        );
        if ($findings['blocks'] && (!$forced || $findings['non_forceable_blocks'] !== [])) {
            $blocks = $findings['non_forceable_blocks'] !== []
                ? $findings['non_forceable_blocks']
                : $findings['blocks'];
            $reason = implode('; ', $blocks);
            throw new \RuntimeException(
                "wprism: delete guard changed before mutation for {$row['type']} {$row['uuid']}: $reason"
                . ($findings['non_forceable_blocks'] !== [] ? ' (this semantic guard is not forceable)' : '')
            );
        }
        return $findings;
    }

    /**
     * Resolve an index which covers the first equality/range column of a
     * manifest guard. A prefix index is accepted only when the declared
     * metadata key fits entirely inside that prefix; otherwise inserts with
     * the same visible prefix could still evade the gap lock.
     */
    public static function lock_index(array $guard, string $table): ?string {
        global $wpdb;
        $lockColumn = isset($guard['lock_column'])
            ? (string) $guard['lock_column']
            : (array_key_exists('meta_key', $guard) || array_key_exists('ref', $guard)
            ? 'meta_key'
            : (!empty($guard['option_name_ref']) ? 'option_name' : (string) ($guard['column'] ?? '')));
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
            if ($prefix !== null && $lockColumn === 'meta_key') {
                $literal = (string) ($guard['meta_key'] ?? (($guard['where'] ?? [])['meta_key'] ?? ''));
                if ($literal === '' || strlen($literal) > $prefix) {
                    continue;
                }
            }
            return $name;
        }
        return null;
    }

    /**
     * Resolve a visible, full-width index whose first column is the exact
     * locking predicate. Unlike lock_index()'s deletion-guard prefix rules,
     * this boundary never accepts a prefix: mixed options and owner ranges
     * need the complete equality range and its terminal gap locked. The
     * caller can additionally require uniqueness where duplicate rows would
     * make a singleton materialization ambiguous (wp_options.option_name).
     */
    public static function full_width_lock_index(
        string $table,
        string $column,
        string $purpose,
        bool $requireUnique = false
    ): string {
        return DatabaseLockBoundary::full_width_lock_index(
            $table,
            $column,
            $purpose,
            $requireUnique
        );
    }

    /**
     * Resolve a visible full-width unique BTREE whose complete ordered
     * columns equal the locking predicate. This proves compound identities
     * such as wprism_map(uuid, id_kind) without pretending the leading UUID
     * column is unique by itself.
     *
     * @param list<string> $columns
     */
    public static function full_width_composite_unique_lock_index(
        string $table,
        array $columns,
        string $purpose
    ): string {
        return DatabaseLockBoundary::full_width_composite_unique_lock_index(
            $table,
            $columns,
            $purpose
        );
    }

    /**
     * Resolve a visible first-column BTREE whose declared prefix completely
     * covers one fixed locking literal. WordPress postmeta normally exposes
     * `meta_key(191)`: it is not full-width for arbitrary keys, but it is a
     * complete equality/gap authority for a reviewed 17-character key.
     */
    public static function bounded_prefix_lock_index(
        string $table,
        string $column,
        int $minimumPrefixCharacters,
        string $purpose
    ): string {
        return DatabaseLockBoundary::bounded_prefix_lock_index(
            $table,
            $column,
            $minimumPrefixCharacters,
            $purpose
        );
    }

}
