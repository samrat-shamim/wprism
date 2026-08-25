<?php
namespace Duo;

require_once __DIR__ . '/CacheInvalidationTransaction.php';

// Production closes every direct dependency here. Some regressions preload
// narrow doubles before Snapshot reaches this boundary; preserve those test
// seams instead of redeclaring the doubles.
if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}
if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
if (!class_exists(ReferenceRules::class, false)) {
    require_once __DIR__ . '/../Kernel/ReferenceRules.php';
}
if (!class_exists(StructuredValue::class, false)) {
    require_once __DIR__ . '/../Kernel/StructuredValue.php';
}
if (!class_exists(TableGraph::class, false)) {
    require_once __DIR__ . '/../Kernel/TableGraph.php';
}
if (!class_exists(TableSchema::class, false)) {
    require_once __DIR__ . '/../Kernel/TableSchema.php';
}
if (!class_exists(ColumnCodecGrammar::class, false)) {
    require_once __DIR__ . '/../Grammar/ColumnCodecGrammar.php';
}

/**
 * Write-side materialization boundary for authored typed tables (DUO-3349).
 *
 * This class owns the complete canonical-entity-to-live-row pipeline:
 * phase-1 placeholder creation, phase-2 reference and attached-meta
 * reconciliation, composite-reference upserts, declared invalidation,
 * deletion/cascade verification, and operator-authorized reparenting.
 * Declaration discovery, graph ordering, identity policy, and transaction
 * ownership stay with their existing callers and are injected explicitly.
 *
 * The boundary has no dependency on Snapshot, Policy, Ledger, Tokens, or a
 * WordPress bootstrap. Direct regressions can supply the same narrow runtime
 * capabilities that Snapshot binds in production.
 */
final class TypedTableMaterializer {
    private \Closure $rowTables;
    private \Closure $metaTables;
    private \Closure $ledgerIdFor;
    private \Closure $ledgerSet;
    private \Closure $packCompositeId;
    private \Closure $unpackCompositeId;
    private \Closure $metaKeyInKeyspace;
    private \Closure $serializeValue;
    private \Closure $cacheDelete;
    /**
     * `(string $table): array<string,array{container:string,leaves:string}>` —
     * the table's `column_codecs` projection (WP-6.1).
     *
     * REQUIRED, not defaulted. A boundary whose codec source may be omitted
     * would write the canonical container's bytes straight into the column on
     * any wiring that forgot it, which is the corruption this primitive exists
     * to prevent, arriving silently through a constructor default. Every
     * construction site names it.
     */
    private \Closure $columnCodecs;

    public function __construct(
        \Closure $rowTables,
        \Closure $metaTables,
        \Closure $ledgerIdFor,
        \Closure $ledgerSet,
        \Closure $packCompositeId,
        \Closure $unpackCompositeId,
        \Closure $metaKeyInKeyspace,
        \Closure $serializeValue,
        \Closure $cacheDelete,
        \Closure $columnCodecs
    ) {
        $this->rowTables = $rowTables;
        $this->metaTables = $metaTables;
        $this->ledgerIdFor = $ledgerIdFor;
        $this->ledgerSet = $ledgerSet;
        $this->packCompositeId = $packCompositeId;
        $this->unpackCompositeId = $unpackCompositeId;
        $this->metaKeyInKeyspace = $metaKeyInKeyspace;
        $this->serializeValue = $serializeValue;
        $this->cacheDelete = $cacheDelete;
        $this->columnCodecs = $columnCodecs;
    }

    /**
     * One authored column's value on the way back to the live row.
     *
     * The no-codec arm is byte for byte the treatment every authored column had
     * before WP-6.1. With a codec the canonical container is opened, its
     * tokenized leaves rebound for THIS target, and the container re-encoded —
     * so a `{{site_url}}` that expands to a different byte length leaves the
     * `s:<n>:` prefixes correct, which is the whole reason the codec exists.
     *
     * @param array<string,array{container:string,leaves:string}> $codecs
     */
    private static function applyColumn(mixed $value, array $codecs, string $column, object $tokens, string $where): mixed {
        if (!isset($codecs[$column])) {
            return is_string($value) ? $tokens->detokenize_text($value) : $value;
        }
        return ColumnCodecGrammar::apply_value($value, $codecs[$column], $tokens, $where);
    }

    /**
     * Phase 1: insert an ordinary row with authored values and zero-valued
     * structural refs. Composite-reference facts materialize wholly in phase
     * 2 because nothing can reference their bookkeeping identity early.
     *
     * @return bool true when a new row was inserted
     */
    public function ensureRow(array $entity): bool {
        global $wpdb;
        $decl = ($this->rowTables)()[$entity['type']];
        if (TableGraph::is_composite_ref($decl)) {
            return false;
        }
        $idKind = $decl['id_kind'];
        $front = $entity['data'] ?? Canon::decode($entity['content']);
        $uuid = $front['uuid'];
        $mappedId = ($this->ledgerIdFor)($uuid, $idKind);
        $prefixed = $wpdb->prefix . $entity['type'];
        $pk = $decl['pk'];

        // A retained option-name mapping is recovery evidence, not proof the
        // physical row survived. Recreate the exact id when it did not.
        if ($mappedId !== null) {
            $wpdb->last_error = '';
            $existingId = $wpdb->get_var($wpdb->prepare(
                "SELECT `$pk` FROM `$prefixed` WHERE `$pk` = %d LIMIT 1",
                $mappedId
            ));
            if ((string) ($wpdb->last_error ?? '') !== '') {
                throw new \RuntimeException(
                    "duo: failed to verify retained typed-snapshot identity for {$entity['type']}"
                );
            }
            if ($existingId !== null) {
                return false;
            }
        }
        $colTypes = TableSchema::live_column_types($entity['type']) ?? [];

        $data = [];
        if ($mappedId !== null) {
            $data[$pk] = $mappedId;
        }
        foreach ($decl['columns'] ?? [] as $col => $rule) {
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            $data[$col] = $front['columns'][$col] ?? null;
        }
        foreach ($decl['refs'] ?? [] as $ref) {
            $data[$ref['column']] = 0;
        }
        [$data, $format] = TableSchema::write_format($data, $colTypes);
        Db::insert($prefixed, $data, $format, "apply insert typed-snapshot row {$entity['type']}");
        $localId = $mappedId
            ?? Db::insert_id("apply insert typed-snapshot row {$entity['type']}");
        ($this->ledgerSet)($uuid, $entity['type'], $idKind, $localId);
        return true;
    }

    /**
     * Phase 2: resolve every structural ref, reconcile attached meta, and run
     * declared per-row invalidation. Composite rows use their tuple upsert.
     */
    public function finalizeRow(object $tokens, array $entity): void {
        global $wpdb;
        $decl = ($this->rowTables)()[$entity['type']];
        if (TableGraph::is_composite_ref($decl)) {
            $this->finalizeCompositeRow($tokens, $entity, $decl);
            return;
        }
        $idKind = $decl['id_kind'];
        $front = $entity['data'] ?? Canon::decode($entity['content']);
        $uuid = $front['uuid'];
        $localId = ($this->ledgerIdFor)($uuid, $idKind)
            ?? throw new \RuntimeException("duo: table row $uuid ({$entity['type']}) missing from ledger after phase 1");
        $prefixed = $wpdb->prefix . $entity['type'];
        $pk = $decl['pk'];
        $colTypes = TableSchema::live_column_types($entity['type']) ?? [];

        $codecs = ($this->columnCodecs)($entity['type']);
        $data = [];
        foreach ($decl['columns'] ?? [] as $col => $rule) {
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            $value = $front['columns'][$col] ?? null;
            $data[$col] = self::applyColumn(
                $value,
                $codecs,
                (string) $col,
                $tokens,
                "table '{$entity['type']}' column '$col' (row $uuid)"
            );
        }
        foreach ($decl['refs'] ?? [] as $ref) {
            $col = $ref['column'];
            $value = $front['columns'][$col] ?? null;
            $data[$col] = $value === null ? 0 : $tokens->token_to_id((string) $value);
        }
        [$data, $format] = TableSchema::write_format($data, $colTypes);
        Db::update(
            $prefixed,
            $data,
            [$pk => $localId],
            $format,
            '%d',
            "apply update typed-snapshot row {$entity['type']}"
        );

        foreach (($this->metaTables)() as $metaName => $metaDecl) {
            if (($metaDecl['attached_to']['table'] ?? null) !== $entity['type']) {
                continue;
            }
            $this->reconcileMeta(
                $metaName,
                $metaDecl,
                $localId,
                (array) ($front['meta'] ?? []),
                $tokens
            );
        }

        foreach ($decl['invalidate'] ?? [] as $invalidation) {
            $this->runInvalidation($invalidation, $localId);
        }
    }

    /** Resolve and upsert a pure join fact by this environment's local tuple. */
    private function finalizeCompositeRow(object $tokens, array $entity, array $decl): void {
        global $wpdb;
        $idKind = $decl['id_kind'];
        $front = $entity['data'] ?? Canon::decode($entity['content']);
        $uuid = $front['uuid'];
        $columns = $decl['identity']['columns'];
        $prefixed = $wpdb->prefix . $entity['type'];
        $colTypes = TableSchema::live_column_types($entity['type']) ?? [];

        $localByColumn = [];
        foreach ($columns as $column) {
            $token = (string) ($front['columns'][$column] ?? '');
            $localByColumn[$column] = $tokens->token_to_id($token);
        }

        $codecs = ($this->columnCodecs)($entity['type']);
        $authored = [];
        foreach ($decl['columns'] ?? [] as $column => $rule) {
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            $value = $front['columns'][$column] ?? null;
            $authored[$column] = self::applyColumn(
                $value,
                $codecs,
                (string) $column,
                $tokens,
                "table '{$entity['type']}' column '$column' (composite row $uuid)"
            );
        }

        $where = [
            $columns[0] => $localByColumn[$columns[0]],
            $columns[1] => $localByColumn[$columns[1]],
        ];
        $exists = (bool) $wpdb->get_var($wpdb->prepare(
            "SELECT 1 FROM `$prefixed` WHERE `{$columns[0]}` = %d AND `{$columns[1]}` = %d LIMIT 1",
            $localByColumn[$columns[0]],
            $localByColumn[$columns[1]]
        ));
        if ($exists) {
            if ($authored) {
                [$data, $format] = TableSchema::write_format($authored, $colTypes);
                $wpdb->update($prefixed, $data, $where, $format);
            }
        } else {
            [$data, $format] = TableSchema::write_format($where + $authored, $colTypes);
            $wpdb->insert($prefixed, $data, $format);
        }

        $packed = ($this->packCompositeId)($entity['type'], $localByColumn);
        ($this->ledgerSet)($uuid, $entity['type'], $idKind, $packed);
    }

    /** Reconcile one attached-meta table to the desired authored key set. */
    private function reconcileMeta(
        string $metaTable,
        array $decl,
        int $ownerLocalId,
        array $desiredMeta,
        object $tokens
    ): void {
        global $wpdb;
        $prefixed = $wpdb->prefix . $metaTable;
        $attachCol = $decl['attached_to']['column'];
        $idCol = $decl['id_column'] ?? 'id';
        $keyCol = $decl['key_column'] ?? 'meta_key';
        $valCol = $decl['value_column'] ?? 'meta_value';
        $default = $decl['default_class'] ?? 'authored';

        $existing = $wpdb->get_results($wpdb->prepare(
            "SELECT `$idCol` AS id, `$keyCol` AS k FROM `$prefixed` WHERE `$attachCol` = %d",
            $ownerLocalId
        ), ARRAY_A) ?: [];
        $existingByKey = [];
        foreach ($existing as $row) {
            $existingByKey[(string) $row['k']] = (int) $row['id'];
        }

        $desiredRaw = [];
        foreach ($desiredMeta as $key => $value) {
            if (!(($this->metaKeyInKeyspace)($decl, (string) $key))) {
                throw new \RuntimeException(
                    "duo: repository asks apply to write table_meta:$metaTable:$key outside its declared keyspace"
                );
            }
            $rule = ReferenceRules::attached_meta_key($decl, $key);
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                $resolved = $tokens->struct_apply(
                    $value,
                    $rule['json_refs'] ?? [],
                    $rule['key_refs'] ?? null
                );
                $encoded = StructuredValue::encode(
                    $resolved,
                    $rule,
                    "table '$metaTable' key '$key'"
                );
                $desiredRaw[$key] = ($this->serializeValue)($encoded);
            } elseif (!empty($rule['plain_data'])) {
                // The injected serializer is WordPress' maybe_serialize() in
                // production, so native arrays regain canonical storage only
                // after every nested URL has been rebound for this target.
                $desiredRaw[$key] = ($this->serializeValue)(
                    $tokens->plain_data_apply($value)
                );
            } elseif (!empty($rule['ref'])) {
                $desiredRaw[$key] = $value === null ? '0' : (string) $tokens->token_to_id((string) $value);
            } elseif (is_string($value)) {
                $desiredRaw[$key] = $tokens->detokenize_text($value);
            } else {
                $desiredRaw[$key] = $value === null ? null : (string) $value;
            }
        }

        foreach ($existingByKey as $key => $rowId) {
            if (array_key_exists($key, $desiredRaw)) {
                continue;
            }
            if (!(($this->metaKeyInKeyspace)($decl, $key))) {
                continue;
            }
            $rule = ReferenceRules::attached_meta_key($decl, $key);
            if (($rule['class'] ?? $default) !== 'authored') {
                continue;
            }
            Db::delete($prefixed, [$idCol => $rowId], null, "apply delete authored $metaTable sidecar row");
        }
        foreach ($desiredRaw as $key => $value) {
            $data = [$attachCol => $ownerLocalId, $keyCol => $key, $valCol => $value];
            if (isset($decl['legacy_key_column'])) {
                $data[$decl['legacy_key_column']] = $key;
            }
            if (isset($decl['legacy_value_column'])) {
                $data[$decl['legacy_value_column']] = $value;
            }
            if (isset($existingByKey[$key])) {
                Db::update(
                    $prefixed,
                    $data,
                    [$idCol => $existingByKey[$key]],
                    null,
                    null,
                    "apply update $metaTable sidecar row"
                );
            } else {
                Db::insert($prefixed, $data, null, "apply insert $metaTable sidecar row");
            }
        }
    }

    /** Run one generic table-row, option-name, or object-cache-entry invalidation rule. */
    private function runInvalidation(array $invalidation, int $localId): void {
        global $wpdb;
        if (isset($invalidation['cache_group'])) {
            $this->runCacheEntryInvalidation($invalidation, $localId);
        }
        if (isset($invalidation['table'])) {
            $table = preg_replace('/[^A-Za-z0-9_]/', '', $invalidation['table']);
            $column = preg_replace('/[^A-Za-z0-9_]/', '', $invalidation['column'] ?? 'id');
            $prefixed = $wpdb->prefix . $table;
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefixed))) {
                Db::query(
                    $wpdb->prepare("DELETE FROM `$prefixed` WHERE `$column` = %d", $localId),
                    "apply invalidate $table cache row"
                );
            }
        }
        if (isset($invalidation['option_pattern'])) {
            $name = str_replace('{id}', (string) $localId, (string) $invalidation['option_pattern']);
            if (CacheInvalidationTransaction::is_active()) {
                $locked = CacheInvalidationTransaction::lock_option_row(
                    $name,
                    'apply invalidate typed-snapshot option cache row'
                );
                if ($locked !== null) {
                    Db::delete($wpdb->options, ['option_name' => $name], null, 'apply invalidate option cache row');
                }
                CacheInvalidationTransaction::queue_option($name, 'apply invalidate option cache row');
                if (CacheInvalidationTransaction::lock_option_row(
                    $name,
                    'apply invalidate typed-snapshot option cache row readback'
                ) !== null) {
                    throw new \RuntimeException('duo: typed-snapshot option cache row remained after invalidation');
                }
            } else {
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1",
                    $name
                ));
                if ($exists !== null) {
                    Db::delete($wpdb->options, ['option_name' => $name], null, 'apply invalidate option cache row');
                }
                ($this->cacheDelete)($name, 'options');
                ($this->cacheDelete)('alloptions', 'options');
            }
        }
    }

    /**
     * Drop ONE object-cache entry named by a `{cache_group, cache_key}` verb
     * (WP-6.2), and PROVE it is gone.
     *
     * The readback is the whole point, and it is what makes the declarative
     * verb equal in strength to the provider it replaces rather than a weaker
     * imitation of it: `manifests/providers/paid-memberships-pro-cache.php`
     * refuses with "cache invalidation left cached membership level id(s)"
     * (:96-99) when an entry survives, and so does this. A `wp_cache_delete()`
     * that quietly returns false against a backend that kept the value is
     * precisely the stale-read the declaration exists to prevent, so an
     * unverified delete would ship the bug in a shorter spelling.
     *
     * Two runtime paths, matching the option branch above for the same reason.
     * Under the authored transaction the delete goes through
     * CacheInvalidationTransaction::queue(), which registers the entry BEFORE
     * attempting it and re-attempts every registered primitive after the
     * database outcome (:372-397, :400-408) — a pre-COMMIT purge alone is
     * insufficient because a later read in the same request repopulates it.
     * Outside one, the injected cacheDelete closure is used, exactly as the
     * option branch does, so a directly-constructed materializer stays testable
     * without a transaction.
     *
     * `wp_cache_get` is required rather than guarded away: this engine refuses
     * loudly instead of skipping a check it cannot make, and the function is
     * unconditionally present in any WordPress that loaded wp-includes/cache.php
     * — which is every context this branch can be reached from.
     */
    private function runCacheEntryInvalidation(array $invalidation, int $localId): void {
        $group = str_replace('{id}', (string) $localId, (string) $invalidation['cache_group']);
        $key = str_replace('{id}', (string) $localId, (string) $invalidation['cache_key']);
        $purpose = 'apply invalidate declared object-cache entry';
        if (!function_exists('wp_cache_get')) {
            throw new \RuntimeException(
                "duo: $purpose cannot verify the drop of '$key' in group '$group' — wp_cache_get() is absent, "
                . 'and an unverified cache invalidation proves nothing about the stale read it exists to prevent'
            );
        }
        if (CacheInvalidationTransaction::is_active()) {
            CacheInvalidationTransaction::queue($key, $group, $purpose);
        } else {
            ($this->cacheDelete)($key, $group);
        }
        $found = false;
        wp_cache_get($key, $group, false, $found);
        if ($found) {
            throw new \RuntimeException(
                "duo: $purpose left '$key' cached in group '$group'; the declared invalidation did not take, so "
                . "the plugin's own read path would still serve the pre-apply value"
            );
        }
    }

    /** Delete one row selected by canonical UUID. */
    public function deleteRow(string $uuid, string $table): void {
        $decl = ($this->rowTables)()[$table] ?? null;
        if ($decl === null) {
            return;
        }
        $localId = ($this->ledgerIdFor)($uuid, $decl['id_kind']);
        if ($localId === null) {
            return;
        }
        $this->deleteLocalRow($table, $localId);
    }

    /** Delete one operator-authorized local row and its owned sidecars. */
    public function deleteLocalRow(string $table, int $localId): void {
        global $wpdb;
        $decl = ($this->rowTables)()[$table] ?? null;
        if ($decl === null) {
            throw new \RuntimeException("duo: cannot delete row from undeclared table '$table'");
        }
        if (TableGraph::is_composite_ref($decl)) {
            $columns = $decl['identity']['columns'];
            [$left, $right] = ($this->unpackCompositeId)($localId);
            Db::delete(
                $wpdb->prefix . $table,
                [$columns[0] => $left, $columns[1] => $right],
                null,
                "apply delete composite typed-snapshot row $table"
            );
            return;
        }
        foreach (($this->metaTables)() as $metaName => $metaDecl) {
            if (($metaDecl['attached_to']['table'] ?? null) !== $table) {
                continue;
            }
            Db::delete(
                $wpdb->prefix . $metaName,
                [$metaDecl['attached_to']['column'] => $localId],
                null,
                "apply delete $metaName sidecar rows"
            );
        }
        foreach ($decl['invalidate'] ?? [] as $invalidation) {
            $this->runInvalidation($invalidation, $localId);
        }
        Db::delete(
            $wpdb->prefix . $table,
            [$decl['pk'] => $localId],
            null,
            "apply delete typed-snapshot row $table"
        );
    }

    /** Reparent one scalar typed row and any declared attached-meta mirror. */
    public function reparentLocalRow(string $table, int $localId, string $column, int $targetId): void {
        global $wpdb;
        $decl = ($this->rowTables)()[$table] ?? null;
        if ($decl === null) {
            throw new \RuntimeException("duo: cannot reparent row in undeclared table '$table'");
        }
        if (TableGraph::is_composite_ref($decl)) {
            throw new \RuntimeException(
                "duo: reparenting composite_ref table '$table' changes the row's identity; delete the orphaned fact instead"
            );
        }
        $ref = null;
        foreach ($decl['refs'] ?? [] as $candidate) {
            if (($candidate['column'] ?? '') === $column) {
                $ref = $candidate;
                break;
            }
        }
        if ($ref === null) {
            throw new \RuntimeException("duo: '$column' is not a declared structural ref column of '$table'");
        }
        Db::update(
            $wpdb->prefix . $table,
            [$column => $targetId],
            [$decl['pk'] => $localId],
            ['%d'],
            ['%d'],
            "orphans reparent typed-snapshot row $table"
        );

        foreach (($this->metaTables)() as $metaName => $metaDecl) {
            if (($metaDecl['attached_to']['table'] ?? null) !== $table) {
                continue;
            }
            $rule = $metaDecl['keys'][$column] ?? null;
            if (($rule['ref'] ?? null) !== ($ref['kind'] ?? null)) {
                continue;
            }
            $data = [$metaDecl['value_column'] => (string) $targetId];
            if (isset($metaDecl['legacy_value_column'])) {
                $data[$metaDecl['legacy_value_column']] = (string) $targetId;
            }
            Db::update(
                $wpdb->prefix . $metaName,
                $data,
                [
                    $metaDecl['attached_to']['column'] => $localId,
                    $metaDecl['key_column'] => $column,
                ],
                null,
                null,
                "orphans reparent $metaName ref mirror"
            );
        }
        foreach ($decl['invalidate'] ?? [] as $invalidation) {
            $this->runInvalidation($invalidation, $localId);
        }
    }

    /** Prove a typed-table delete and every attached-meta cascade. */
    public function assertRowDeleted(string $table, int $localId): void {
        global $wpdb;
        $decl = ($this->rowTables)()[$table] ?? null;
        if ($decl === null) {
            throw new \RuntimeException("duo: cannot verify deletion of undeclared table '$table'");
        }
        $prefixed = $wpdb->prefix . $table;
        if (TableGraph::is_composite_ref($decl)) {
            $columns = $decl['identity']['columns'];
            [$left, $right] = ($this->unpackCompositeId)($localId);
            $remaining = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `$prefixed` WHERE `{$columns[0]}` = %d AND `{$columns[1]}` = %d",
                $left,
                $right
            ));
        } else {
            $pk = preg_replace('/[^A-Za-z0-9_]/', '', (string) $decl['pk']);
            $remaining = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `$prefixed` WHERE `$pk` = %d",
                $localId
            ));
        }
        if ($remaining !== 0) {
            throw new \RuntimeException("duo: deletion verification failed for $table local id $localId");
        }
        foreach (($this->metaTables)() as $metaName => $metaDecl) {
            if (($metaDecl['attached_to']['table'] ?? null) !== $table) {
                continue;
            }
            $foreignKey = preg_replace(
                '/[^A-Za-z0-9_]/',
                '',
                (string) $metaDecl['attached_to']['column']
            );
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM `{$wpdb->prefix}$metaName` WHERE `$foreignKey` = %d",
                $localId
            ));
            if ($count !== 0) {
                throw new \RuntimeException(
                    "duo: deletion verification failed for $table local id $localId: $count attached $metaName row(s) remain"
                );
            }
        }
    }
}
