<?php
namespace Duo;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Grammar/Tokens.php';
require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';
require_once __DIR__ . '/../Kernel/MetaRows.php';
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
     * DUO-3266: authored postmeta reconciliation for one owner ($id) —
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
        $desired = [];
        foreach ($frontMeta as $key => $v) {
            $rule = $this->policy->meta_rule_for_post($key, $frontMeta) ?? [];
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                $v = $this->tokens->struct_apply($v, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null);
                $v = StructuredValue::encode($v, $rule, "$ownerLabel meta $key");
            } elseif (!empty($rule['plain_data'])) {
                $v = $this->tokens->plain_data_apply($v);
            } elseif (!empty($rule['ref'])) {
                $v = $this->tokens->meta_tokens_to_value($v, $rule);
            } elseif (is_string($v)) {
                $v = $this->tokens->detokenize_text($v);
            }
            $desired[$key] = maybe_serialize($v);
        }
        $envMeta = $this->meta_owner_range_lock(
            $wpdb->postmeta,
            'post_id',
            "authored $ownerLabel meta row locking"
        )->read($id);
        $envFlat = [];
        $exactMetaIds = [];
        foreach ($envMeta as $m) {
            if (!array_key_exists($m['meta_key'], $envFlat)) {
                $envFlat[$m['meta_key']] = $m['meta_value'];
            }
            $slot = "k\0" . $m['meta_key'];
            if (!isset($exactMetaIds[$slot])) {
                $exactMetaIds[$slot] = MetaRows::positive_id($m['meta_id']);
            }
        }
        $kept = [];
        foreach ($envMeta as $m) {
            $rule = $this->policy->meta_rule_for_post($m['meta_key'], $envFlat);
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            $key = (string) $m['meta_key'];
            $slot = "k\0" . $key;
            if (!array_key_exists($key, $desired) || isset($kept[$slot])) {
                Db::delete($wpdb->postmeta, ['meta_id' => $m['meta_id']], null, "apply delete authored $ownerLabel meta");
                continue;
            }
            $kept[$slot] = true;
        }
        foreach ($desired as $key => $val) {
            $rule = $this->policy->meta_rule_for_post((string) $key, $envFlat);
            if (($rule['class'] ?? null) !== 'authored') {
                throw new \RuntimeException(
                    "duo: authored $ownerLabel meta '$key' is not authored in the locked target context"
                );
            }
            $this->upsert_locked_authored_meta(
                $wpdb->postmeta,
                'post_id',
                $id,
                (string) $key,
                $val,
                $exactMetaIds["k\0" . $key] ?? null,
                "apply reconcile authored $ownerLabel meta"
            );
        }
        wp_cache_delete($id, 'post_meta');
    }

    /**
     * Termmeta counterpart of reconcile_authored_meta(). Only keys the
     * current policy still classifies authored are deletion-owned; every
     * undeclared/runtime/env/derived target row remains byte-untouched.
     */
    public function reconcile_authored_term_meta(int $termId, array $frontMeta): void {
        global $wpdb;
        $desired = [];
        foreach ($frontMeta as $key => $value) {
            $rule = $this->policy->meta_rule_for_term((string) $key, $frontMeta) ?? [];
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                $value = $this->tokens->struct_apply($value, $rule['json_refs'] ?? [], $rule['key_refs'] ?? null);
                $value = StructuredValue::encode($value, $rule, "term meta $key");
            } elseif (!empty($rule['plain_data'])) {
                $value = $this->tokens->plain_data_apply($value);
            } elseif (!empty($rule['ref'])) {
                $value = $this->tokens->meta_tokens_to_value($value, $rule);
            } elseif (is_string($value)) {
                $value = $this->tokens->detokenize_text($value);
            }
            $desired[(string) $key] = maybe_serialize($value);
        }

        $ownerRange = $this->meta_owner_range_lock(
            $wpdb->termmeta,
            'term_id',
            'authored term-meta row locking'
        );
        $envMeta = $ownerRange->read($termId);
        $envFlat = [];
        $exactMetaIds = [];
        foreach ($envMeta as $row) {
            if (!array_key_exists($row['meta_key'], $envFlat)) {
                $envFlat[$row['meta_key']] = $row['meta_value'];
            }
            $slot = "k\0" . $row['meta_key'];
            if (!isset($exactMetaIds[$slot])) {
                $exactMetaIds[$slot] = MetaRows::positive_id($row['meta_id']);
            }
        }
        $kept = [];
        foreach ($envMeta as $row) {
            $rule = $this->policy->meta_rule_for_term($row['meta_key'], $envFlat);
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            $key = (string) $row['meta_key'];
            $slot = "k\0" . $key;
            if (!array_key_exists($key, $desired) || isset($kept[$slot])) {
                Db::delete($wpdb->termmeta, ['meta_id' => $row['meta_id']], null, 'apply delete authored term meta');
                continue;
            }
            $kept[$slot] = true;
        }
        foreach ($desired as $key => $value) {
            $rule = $this->policy->meta_rule_for_term((string) $key, $envFlat);
            if (($rule['class'] ?? null) !== 'authored') {
                throw new \RuntimeException(
                    "duo: authored term meta '$key' is not authored in the locked target context"
                );
            }
            $this->upsert_locked_authored_meta(
                $wpdb->termmeta,
                'term_id',
                $termId,
                (string) $key,
                $value,
                $exactMetaIds["k\0" . $key] ?? null,
                'apply reconcile authored term meta'
            );
        }
        wp_cache_delete($termId, 'term_meta');
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
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT option_id FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $name
        ));
        if ($exists) {
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
        wp_cache_delete($name, 'options');
        wp_cache_delete('alloptions', 'options');
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
}
