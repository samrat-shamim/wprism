<?php
namespace WPrism;

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
    private static ?string $continuitySavepoint = null;

    /** Establish the transaction identity immediately after START TRANSACTION. */
    public static function begin_authored_transaction(): void {
        self::$continuitySavepoint = null;
        self::assert_active_transaction('authored transaction continuity');
        try {
            $name = 'wprism_authored_' . bin2hex(random_bytes(12));
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'wprism: authored transaction continuity could not allocate a unique savepoint',
                0,
                $failure
            );
        }
        try {
            self::checked_query("SAVEPOINT `$name`");
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'wprism: authored transaction continuity could not establish its savepoint',
                0,
                $failure
            );
        }
        self::$continuitySavepoint = $name;
    }

    /** Forget process-local proof state after either COMMIT or ROLLBACK. */
    public static function end_authored_transaction(): void {
        self::$continuitySavepoint = null;
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
        global $wpdb;

        self::assert_table_identifiers($tables, $purpose);
        $tables = array_values(array_unique($tables));
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
            self::touch_lock_table($table, $purpose);
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
                "wprism: $purpose refused — storage-engine introspection failed for "
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
                "wprism: $purpose refused — " . implode('; ', $details)
            );
        }
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
        self::assert_active_transaction($purpose);
        self::assert_transaction_continuity($purpose);
    }

    private static function assert_transaction_continuity(string $purpose): void {
        $name = self::$continuitySavepoint;
        if ($name === null) {
            throw new \RuntimeException(
                "wprism: $purpose requires the authored transaction continuity savepoint"
            );
        }
        try {
            // RELEASE fails if an intervening callback committed and started
            // another transaction, even when its isolation happens to match.
            self::checked_query("RELEASE SAVEPOINT `$name`");
            self::checked_query("SAVEPOINT `$name`");
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                "wprism: $purpose lost authored transaction continuity",
                0,
                $failure
            );
        }
    }

    private static function touch_lock_table(string $table, string $purpose): void {
        global $wpdb;
        $wpdb->last_error = '';
        $probe = $wpdb->get_var("SELECT 1 FROM `$table` LIMIT 1");
        $error = trim((string) ($wpdb->last_error ?? ''));
        if ($probe === false || $error !== '') {
            $detail = $error !== '' ? $error : 'no result returned';
            $tableLabel = $purpose === 'deletion guard locking' ? 'guard table ' : 'table ';
            throw new \RuntimeException(
                "wprism: $purpose refused — unable to acquire metadata lock for "
                . $tableLabel . "$table: $detail"
            );
        }
    }

    private static function checked_query(string $sql): void {
        global $wpdb;
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $result = $wpdb->query($sql);
        if ($result === false || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('wprism: authored transaction savepoint query failed');
        }
    }

    public static function assert_active_transaction(string $purpose): void {
        global $wpdb;
        $wpdb->last_error = '';
        $active = $wpdb->get_var('SELECT @@in_transaction');
        if ($active !== '1' || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: $purpose requires an active transaction");
        }
    }

    /** @param list<string> $tables */
    public static function assert_table_identifiers(array $tables, string $purpose): void {
        foreach ($tables as $table) {
            if (!is_string($table) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
                throw new \RuntimeException("wprism: $purpose refused — unsafe table identifier");
            }
        }
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
        return self::locking_index(
            $table,
            $column,
            $purpose,
            $requireUnique ? [$column] : null,
            null
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
        if (!array_is_list($columns)
            || count($columns) < 2
            || count($columns) > 16) {
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
        return self::locking_index($table, $columns[0], $purpose, $columns, null);
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
        if ($minimumPrefixCharacters < 1 || $minimumPrefixCharacters > 65535) {
            throw new \RuntimeException("wprism: $purpose index proof received an invalid prefix frontier");
        }
        return self::locking_index($table, $column, $purpose, null, $minimumPrefixCharacters);
    }

    private static function locking_index(
        string $table,
        string $column,
        string $purpose,
        ?array $requiredUniqueColumns,
        ?int $minimumPrefixCharacters
    ): string {
        global $wpdb;
        if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1
            || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $column) !== 1) {
            throw new \RuntimeException("wprism: $purpose index proof received an unsafe table/column name");
        }
        $wpdb->last_error = '';
        $rows = $wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A);
        if (!is_array($rows) || !array_is_list($rows) || trim((string) ($wpdb->last_error ?? '')) !== '') {
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
            // MySQL permits quoted Unicode/punctuated identifier names. They
            // are valid introspection rows, but an exotic index name is never
            // interpolated by this lock boundary. Group by a value-free hash
            // so such an unrelated index cannot poison a usable WP index.
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
                    // A row naming our exact predicate column but carrying an
                    // invalid ordinal makes candidate identity ambiguous.
                    throw new \RuntimeException("wprism: $purpose index introspection returned a malformed row");
                }
                if ($columnName === $column && $seq === 1) {
                    $firstColumnMatches = true;
                }
                if ($columnName === null && array_key_exists('Expression', $row)) {
                    $hasFunctionalPart = true;
                }
            }
            // Peculiarities in an unrelated index are irrelevant. A group is
            // parsed strictly only once its first physical column is the
            // requested predicate; functional groups are never interpolated.
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
                    || (array_key_exists('Visible', $row)
                        && !in_array($visible, ['YES', 'NO'], true))
                    || (array_key_exists('Ignored', $row)
                        && !in_array($ignored, ['YES', 'NO'], true))) {
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
                    $requiredPart = $indexRows[$offset + 1] ?? null;
                    if (($requiredPart['column'] ?? null) !== $requiredColumn
                        || ($requiredPart['sub_part'] ?? null) !== null) {
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
            /*
             * Nonunique owner-range indexes may be composite; equality on
             * the complete first column still locks that owner's contiguous
             * range and terminal gap. A singleton claim may not rely on a
             * composite unique index because its first column alone is not
             * necessarily unique. The compound boundary above admits one
             * only when every full-width ordered column is also a predicate.
             */
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
}
