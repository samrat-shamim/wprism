<?php
namespace WPrism;

require_once __DIR__ . '/CacheInvalidationTransaction.php';

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Grammar/Tokens.php';
require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';
require_once __DIR__ . '/../Kernel/MetaRows.php';
require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Kernel/NativeValueValidation.php';
require_once __DIR__ . '/MetaOwnerRangeLock.php';

/**
 * Policy-owned authored field materialization used by Apply's entity paths.
 *
 * This is deliberately narrower than an entity manager: it owns only the
 * shared post/term authored-meta reconciliation and the checked SQL/cache
 * primitives used by posts, menus, terms, users, attachments, and options.
 * Entity identity, lifecycle, deletion authority, and transaction sequencing
 * remain in Apply and its future entity-specific collaborators.
 */
final class ApplyFieldMaterializer {
    private Policy $policy;
    private Tokens $tokens;
    /** @var array<string,true> */
    private array $engineLockProofs = [];
    /** @var array<string,string> */
    private array $indexLockProofs = [];
    /** @var array<string,MetaOwnerRangeLock> */
    private array $ownerRangeLocks = [];

    public function __construct(Policy $policy, Tokens $tokens) {
        $this->policy = $policy;
        $this->tokens = $tokens;
    }

    /** Reset every schema descriptor at the start of one authored DB transaction. */
    public function begin_authored_transaction(): void {
        $this->clear_lock_descriptors();
        DeleteGuardEvaluator::begin_authored_transaction();
    }

    /** Never retain a schema descriptor beyond the transaction that proved it. */
    public function end_authored_transaction(): void {
        $this->clear_lock_descriptors();
        DeleteGuardEvaluator::end_authored_transaction();
    }

    private function clear_lock_descriptors(): void {
        $this->engineLockProofs = [];
        $this->indexLockProofs = [];
        $this->ownerRangeLocks = [];
    }

    /** @param list<string> $tables */
    public function prove_lock_tables(array $tables, string $purpose): void {
        DeleteGuardEvaluator::assert_table_identifiers($tables, $purpose);
        // Activity/isolation are connection state, not schema descriptors:
        // an intervening callback or direct public caller can end/change the
        // transaction between two owners. Only engine/index facts are cached.
        $unproven = array_values(array_filter(
            array_unique($tables),
            fn(string $table): bool => !isset($this->engineLockProofs[$table])
        ));
        if ($unproven !== []) {
            DeleteGuardEvaluator::assert_innodb_tables($unproven, $purpose);
            foreach ($unproven as $table) {
                $this->engineLockProofs[$table] = true;
            }
        }
        // The metadata descriptor may be cached; transaction activity and
        // savepoint continuity never are.
        DeleteGuardEvaluator::assert_transaction_isolation($purpose);
    }

    public function proven_lock_index(
        string $table,
        string $column,
        string $purpose,
        bool $requireUnique = false
    ): string {
        $this->prove_lock_tables([$table], $purpose);
        $key = $table . "\0" . $column . "\0" . ($requireUnique ? 'unique' : 'range');
        return $this->indexLockProofs[$key] ??= DeleteGuardEvaluator::full_width_lock_index(
            $table,
            $column,
            $purpose,
            $requireUnique
        );
    }

    public function meta_owner_range_lock(
        string $table,
        string $ownerColumn,
        string $purpose
    ): MetaOwnerRangeLock {
        $key = $table . "\0" . $ownerColumn;
        if (!isset($this->ownerRangeLocks[$key])) {
            $index = $this->proven_lock_index($table, $ownerColumn, $purpose);
            $this->ownerRangeLocks[$key] = MetaOwnerRangeLock::from_proven_descriptor(
                $table,
                $ownerColumn,
                $index,
                $purpose
            );
        }
        return $this->ownerRangeLocks[$key];
    }

    /**
     * issue #3266: authored postmeta reconciliation for one owner ($id) —
     * factored out of finalize_post() so a second postmeta owner (menu
     * items, finalize_menu() below) gets the SAME ownership discipline
     * instead of a second, drift-prone copy. "We own exactly the
     * authored-classified keys": every key in $frontMeta is resolved
     * (ref/json_refs/key_refs/detokenize as its rule declares) and
     * upserted; any row ALREADY on the target that policy classifies
     * `authored` but is no longer in $frontMeta is deleted (removed from
     * policy, or from this owner's captured state, since the last apply);
     * everything else on the target — non-authored, or a key this owner's
     * own structural fields already handle bespoke — is left byte-untouched.
     */
    public function reconcile_authored_meta(int $id, array $frontMeta, string $ownerLabel): void {
        global $wpdb;
        $this->reconcileMetaTable(
            $wpdb->postmeta,
            'post_id',
            $id,
            $frontMeta,
            false,
            $ownerLabel
        );
    }

    /**
     * Termmeta counterpart of reconcile_authored_meta(). Only keys the
     * current policy still classifies authored are deletion-owned; every
     * undeclared/runtime/env/derived target row remains byte-untouched.
     */
    public function reconcile_authored_term_meta(int $termId, array $frontMeta): void {
        global $wpdb;
        $this->reconcileMetaTable(
            $wpdb->termmeta,
            'term_id',
            $termId,
            $frontMeta,
            true,
            'term'
        );
    }

    /**
     * Reconcile the complete byte-exact authored key roster under one owner
     * range lock. Repeated rows own physical order; ordinary rules retain the
     * historical first-row-wins duplicate collapse. Collation-equal aliases
     * remain separate target rows because every mutation addresses the exact
     * meta_id witnessed by the locked owner range.
     */
    private function reconcileMetaTable(
        string $table,
        string $ownerColumn,
        int $ownerId,
        array $frontMeta,
        bool $termMeta,
        string $ownerLabel
    ): void {
        $desired = [];
        foreach ($frontMeta as $key => $value) {
            $key = (string) $key;
            $rule = $termMeta
                ? ($this->policy->meta_rule_for_term($key, $frontMeta) ?? [])
                : ($this->policy->meta_rule_for_post($key, $frontMeta) ?? []);
            $repeated = array_key_exists('repeated_rows', $rule);
            $values = $repeated ? $value : [$value];
            if ($repeated && (!is_array($values) || !array_is_list($values) || $values === [])) {
                throw new \RuntimeException(
                    "wprism: repeated-row authored $ownerLabel meta '$key' must be a non-empty canonical list"
                );
            }
            $wireValues = [];
            $nativeValues = [];
            $seenCanonical = [];
            foreach ($values as $one) {
                if ($repeated && !is_scalar($one)) {
                    throw new \RuntimeException(
                        "wprism: repeated-row authored $ownerLabel meta '$key' requires one scalar value per row"
                    );
                }
                if ($repeated && is_string($one)
                    && PlainData::decode($one, "$ownerLabel meta $key") !== $one) {
                    throw new \RuntimeException(
                        "wprism: repeated-row authored $ownerLabel meta '$key' requires canonical decoded scalar values"
                    );
                }
                $canonicalFingerprint = "v\0" . serialize($one);
                if ($repeated && isset($seenCanonical[$canonicalFingerprint])) {
                    throw new \RuntimeException(
                        "wprism: repeated-row authored $ownerLabel meta '$key' contains a duplicate value"
                    );
                }
                $seenCanonical[$canonicalFingerprint] = true;
            }
            $seenWire = [];
            foreach ($values as $one) {
                $resolved = $this->resolveMetaValue($one, $rule, "$ownerLabel meta $key");
                NativeValueValidation::assert_native($resolved, $rule, "$ownerLabel meta $key");
                if (array_key_exists(NativeValueValidation::FIELD, $rule)) $nativeValues[] = $resolved;
                $wire = maybe_serialize($resolved);
                $wire = $wire === null ? null : (string) $wire;
                $wireFingerprint = "v\0" . serialize($wire);
                if ($repeated && isset($seenWire[$wireFingerprint])) {
                    throw new \RuntimeException(
                        "wprism: repeated-row authored $ownerLabel meta '$key' resolves to a duplicate target wire value"
                    );
                }
                $seenWire[$wireFingerprint] = true;
                $wireValues[] = $wire;
            }
            $desired[$key] = ['repeated' => $repeated, 'values' => $wireValues, 'native_values' => $nativeValues, 'rule' => $rule];
        }

        $purpose = $termMeta
            ? 'authored term-meta row locking'
            : "authored $ownerLabel meta row locking";
        $ownerRange = $this->meta_owner_range_lock($table, $ownerColumn, $purpose);
        $envMeta = $ownerRange->read($ownerId);
        $envFlat = [];
        $envByKey = [];
        foreach ($envMeta as $row) {
            if (!array_key_exists($row['meta_key'], $envFlat)) {
                $envFlat[$row['meta_key']] = $row['meta_value'];
            }
            $envByKey[$row['meta_key']][] = $row;
        }
        // The locked target context is the map this reconciliation ESTABLISHES
        // — the witnessed rows with this roster's own first value per key laid
        // over them — not the pre-write rows alone. Interpreter classification
        // is sibling-dependent (manifests/interpreters/acf.php:400-427 makes
        // '_<field>' authored only while its '<field>' sibling is present, and
        // '<field>' only while the '_<field>' pointer names a field the
        // revision defines), so asking the pre-write map rejects exactly the
        // keys whose siblings this same roster supplies: a post an apply is
        // about to create holds no rows at all. `VMATRIX_MANIFEST=acf bash
        // sandbox/tests/certify/certify_version_matrix.sh` died there on the
        // acf 6.0.0 target apply — "wprism: authored post meta '_wprism_related'
        // disagrees with the locked target context" — as did the independent
        // bisector probe (sandbox/bin/adapter-boundary.sh:44-69). The terminal
        // repeated-row readback below already classifies against the
        // established map ($finalFlat); this asks it the same question.
        //
        // Every check #556 (18f32d13) added survives, because the overlay only
        // replaces keys this roster actually writes: a static rule is
        // context-free and still refuses, and a target row whose OWN siblings
        // leave it non-authored still refuses when the roster does not name
        // those siblings. Rows this reconciliation is about to DELETE stay in
        // the map on purpose — which sibling a repository may drop is the
        // compiler's question about repository shape, not a decision this
        // materializer may make while holding one owner's range lock.
        $lockedContext = $envFlat;
        foreach ($desired as $key => $declaration) {
            $lockedContext[$key] = $declaration['values'][0];
        }
        foreach ($desired as $key => $declaration) {
            $rule = $termMeta
                ? $this->policy->meta_rule_for_term($key, $lockedContext)
                : $this->policy->meta_rule_for_post($key, $lockedContext);
            if (($rule['class'] ?? null) !== 'authored'
                || array_key_exists('repeated_rows', (array) $rule) !== $declaration['repeated']) {
                throw new \RuntimeException(
                    "wprism: authored $ownerLabel meta '$key' disagrees with the locked target context"
                );
            }
            NativeValueValidation::assert_same_predicate($declaration['rule'], $rule, "$ownerLabel meta $key");
            foreach ($declaration['native_values'] as $value) {
                NativeValueValidation::assert_native($value, $rule, "$ownerLabel meta $key");
            }
        }
        // Validate every owned preimage before the first deletion, including
        // duplicates and omitted keys. Reconciliation cannot launder a value
        // that Capture would refuse under the same native contract.
        foreach ($envMeta as $row) {
            $rule = ($termMeta
                ? $this->policy->meta_rule_for_term($row['meta_key'], $envFlat)
                : $this->policy->meta_rule_for_post($row['meta_key'], $envFlat)) ?? [];
            if (array_key_exists(NativeValueValidation::FIELD, $rule)) {
                $where = "$ownerLabel meta " . $row['meta_key'];
                NativeValueValidation::assert_native(PlainData::decode($row['meta_value'], $where), $rule, $where);
            }
        }
        $expectedRepeated = [];
        foreach ($desired as $key => $declaration) {
            if ($declaration['repeated']) {
                $expectedRepeated[$key] = $declaration['values'];
            }
        }
        foreach ($envMeta as $row) {
            $rule = $termMeta
                ? $this->policy->meta_rule_for_term($row['meta_key'], $envFlat)
                : $this->policy->meta_rule_for_post($row['meta_key'], $envFlat);
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            $key = (string) $row['meta_key'];
            if (!array_key_exists($key, $desired)) {
                if (array_key_exists('repeated_rows', (array) $rule)) {
                    $expectedRepeated[$key] = [];
                }
                Db::delete($table, ['meta_id' => $row['meta_id']], null, "apply delete authored $ownerLabel meta");
            }
        }
        foreach ($desired as $key => $declaration) {
            $currentRows = $envByKey[$key] ?? [];
            if ($declaration['repeated']) {
                $currentValues = array_map(
                    static fn(array $row): ?string => $row['meta_value'],
                    $currentRows
                );
                if ($currentValues === $declaration['values']) {
                    continue;
                }
                foreach ($currentRows as $row) {
                    Db::delete(
                        $table,
                        ['meta_id' => $row['meta_id']],
                        null,
                        "apply delete repeated $ownerLabel meta"
                    );
                }
                foreach ($declaration['values'] as $wireValue) {
                    Db::insert(
                        $table,
                        [$ownerColumn => $ownerId, 'meta_key' => $key, 'meta_value' => $wireValue],
                        null,
                        "apply insert repeated $ownerLabel meta"
                    );
                }
                continue;
            }

            $first = $currentRows[0] ?? null;
            foreach (array_slice($currentRows, 1) as $duplicate) {
                Db::delete(
                    $table,
                    ['meta_id' => $duplicate['meta_id']],
                    null,
                    "apply delete authored $ownerLabel meta"
                );
            }
            $wireValue = $declaration['values'][0];
            if ($first !== null && $first['meta_value'] === $wireValue) {
                continue;
            }
            $this->upsert_locked_authored_meta(
                $table,
                $ownerColumn,
                $ownerId,
                $key,
                $wireValue,
                $first === null ? null : MetaRows::positive_id($first['meta_id']),
                "apply reconcile authored $ownerLabel meta"
            );
        }
        if ($expectedRepeated !== []) {
            $finalByKey = [];
            $finalRows = $ownerRange->read($ownerId);
            $finalFlat = [];
            foreach ($finalRows as $row) {
                if (!array_key_exists($row['meta_key'], $finalFlat)) {
                    $finalFlat[$row['meta_key']] = $row['meta_value'];
                }
            }
            foreach ($finalRows as $row) {
                $key = (string) $row['meta_key'];
                $rule = $termMeta
                    ? $this->policy->meta_rule_for_term($key, $finalFlat)
                    : $this->policy->meta_rule_for_post($key, $finalFlat);
                if (($rule['class'] ?? null) === 'authored'
                    && array_key_exists('repeated_rows', (array) $rule)
                    && !array_key_exists($key, $expectedRepeated)) {
                    throw new \RuntimeException(
                        "wprism: omitted repeated-row authored $ownerLabel meta '$key' survived exact locked readback"
                    );
                }
                if (array_key_exists($key, $expectedRepeated)) {
                    $finalByKey[$key][] = $row['meta_value'];
                }
            }
            foreach ($expectedRepeated as $key => $expectedValues) {
                if (($finalByKey[$key] ?? []) !== $expectedValues) {
                    throw new \RuntimeException(
                        "wprism: repeated-row authored $ownerLabel meta '$key' failed exact locked readback"
                    );
                }
            }
        }
        CacheInvalidationTransaction::queue(
            $ownerId,
            $termMeta ? 'term_meta' : 'post_meta',
            "authored $ownerLabel meta reconciliation"
        );
    }

    private function resolveMetaValue(mixed $value, array $rule, string $context): mixed {
        if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
            $value = $this->tokens->struct_apply($value, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null);
            return StructuredValue::encode($value, $rule, $context);
        }
        if (!empty($rule['plain_data'])) {
            return $this->tokens->plain_data_apply($value);
        }
        if (!empty($rule['ref'])) {
            return $this->tokens->meta_tokens_to_value($value, $rule);
        }
        return is_string($value) ? $this->tokens->detokenize_text($value) : $value;
    }

    /** $value null writes a real SQL NULL — byte-faithful to plugins that store
     *  NULL meta_value themselves (WooCommerce's date_expires on non-expiring
     *  coupons); never a "delete the row" semantic. */
    public function upsert_meta(
        string $table,
        string $fkCol,
        int $objectId,
        string $key,
        ?string $value,
        ?string $context = null,
        string $idCol = 'meta_id'
    ): void {
        global $wpdb;
        foreach ([$table, $fkCol, $idCol] as $identifier) {
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $identifier) !== 1) {
                throw new \RuntimeException(
                    ($context ?? 'apply authored meta') . ' received an unsafe SQL identifier'
                );
            }
        }
        if ($objectId <= 0) {
            throw new \RuntimeException(
                ($context ?? 'apply authored meta') . ' received a nonpositive owner identity'
            );
        }
        $wpdb->last_error = '';
        $metaId = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT `$idCol` FROM `$table` WHERE `$fkCol` = %d AND meta_key = %s LIMIT 1",
                $objectId,
                $key
            )
        );
        if (trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException(
                ($context ?? 'apply authored meta') . ' lookup failed; refusing insert/update ambiguity'
            );
        }
        $canonicalMetaId = $metaId === null ? null : MetaRows::positive_id($metaId);
        if ($metaId !== null && $canonicalMetaId === null) {
            throw new \RuntimeException(
                ($context ?? 'apply authored meta') . ' lookup returned a malformed identity'
            );
        }
        if ($canonicalMetaId !== null) {
            Db::update(
                $table,
                ['meta_value' => $value],
                [$idCol => $canonicalMetaId],
                null,
                null,
                $context ?? 'apply update authored meta'
            );
        } else {
            Db::insert(
                $table,
                [$fkCol => $objectId, 'meta_key' => $key, 'meta_value' => $value],
                null,
                $context ?? 'apply insert authored meta'
            );
        }
        $this->invalidate_meta_owner($table, $objectId, $context ?? 'apply authored meta');
    }

    /**
     * Write one authored key from the exact identity witnessed by the locked
     * owner range. A new collation-equality lookup could select a case/accent
     * alias and corrupt a target-owned sibling; callers pass only the first
     * byte-exact row identity, or null to insert beside preserved aliases.
     */
    public function upsert_locked_authored_meta(
        string $table,
        string $fkCol,
        int $objectId,
        string $key,
        ?string $value,
        ?int $exactMetaId,
        string $context,
        string $idCol = 'meta_id'
    ): void {
        foreach ([$table, $fkCol, $idCol] as $identifier) {
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $identifier) !== 1) {
                throw new \RuntimeException("$context received an unsafe SQL identifier");
            }
        }
        if ($objectId <= 0 || ($exactMetaId !== null && $exactMetaId <= 0)) {
            throw new \RuntimeException("$context received a nonpositive metadata identity");
        }
        if ($exactMetaId !== null) {
            Db::update(
                $table,
                ['meta_value' => $value],
                [$idCol => $exactMetaId],
                null,
                null,
                $context
            );
            return;
        }
        Db::insert(
            $table,
            [$fkCol => $objectId, 'meta_key' => $key, 'meta_value' => $value],
            null,
            $context
        );
    }

    public function upsert_option(string $name, string $value, string $autoload): void {
        global $wpdb;
        CacheInvalidationTransaction::assert_local_option_cache('authored option materialization');
        $locked = CacheInvalidationTransaction::lock_option_row($name, 'authored option materialization');
        if ($locked !== null) {
            Db::update(
                $wpdb->options,
                ['option_value' => $value, 'autoload' => $autoload],
                ['option_name' => $name],
                null,
                null,
                'apply update authored option'
            );
        } else {
            Db::insert(
                $wpdb->options,
                ['option_name' => $name, 'option_value' => $value, 'autoload' => $autoload],
                null,
                'apply insert authored option'
            );
        }
        CacheInvalidationTransaction::queue_option($name, 'authored option materialization');
        CacheInvalidationTransaction::assert_option_row(
            $name,
            $value,
            $autoload,
            'authored option materialization readback'
        );
    }

    /**
     * WordPress's maybe_serialize() deliberately leaves scalar null/false
     * alone, after which wpdb coerces both to an empty SQL string. That is
     * lossy for a canonical format which explicitly distinguishes null,
     * false, and "". Serialize those two scalar types explicitly; retain
     * WordPress's ordinary encoding for arrays/objects and string scalars.
     */
    public function option_wire_value($value): string {
        if ($value === null || is_bool($value)) {
            return serialize($value);
        }
        return (string) maybe_serialize($value);
    }

    private function invalidate_meta_owner(string $table, int $objectId, string $purpose): void {
        global $wpdb;
        $group = match ($table) {
            $wpdb->postmeta => 'post_meta',
            $wpdb->termmeta => 'term_meta',
            $wpdb->usermeta => 'user_meta',
            default => null,
        };
        if ($group === null) {
            return;
        }
        if (CacheInvalidationTransaction::is_active()) {
            CacheInvalidationTransaction::queue($objectId, $group, $purpose);
            return;
        }
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete($objectId, $group);
        }
    }
}
