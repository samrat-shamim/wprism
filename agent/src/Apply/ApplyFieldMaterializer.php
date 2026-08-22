<?php
namespace Duo;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Grammar/Tokens.php';

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

    public function __construct(Policy $policy, Tokens $tokens) {
        $this->policy = $policy;
        $this->tokens = $tokens;
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
        $envMeta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d ORDER BY meta_id ASC",
            $id
        ), ARRAY_A) ?: [];
        $envFlat = [];
        foreach ($envMeta as $m) {
            $envFlat[$m['meta_key']] ??= $m['meta_value'];
        }
        foreach ($envMeta as $m) {
            $rule = $this->policy->meta_rule_for_post($m['meta_key'], $envFlat);
            if (($rule['class'] ?? '') === 'authored' && !array_key_exists($m['meta_key'], $desired)) {
                Db::delete($wpdb->postmeta, ['meta_id' => $m['meta_id']], null, "apply delete authored $ownerLabel meta");
            }
        }
        foreach ($desired as $key => $val) {
            $this->upsert_meta($wpdb->postmeta, 'post_id', $id, $key, $val);
        }
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

        $envMeta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_id, meta_key, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d ORDER BY meta_id ASC",
            $termId
        ), ARRAY_A) ?: [];
        $envFlat = [];
        foreach ($envMeta as $row) {
            $envFlat[$row['meta_key']] ??= $row['meta_value'];
        }
        foreach ($envMeta as $row) {
            $rule = $this->policy->meta_rule_for_term($row['meta_key'], $envFlat);
            if (($rule['class'] ?? '') === 'authored' && !array_key_exists($row['meta_key'], $desired)) {
                Db::delete($wpdb->termmeta, ['meta_id' => $row['meta_id']], null, 'apply delete authored term meta');
            }
        }
        foreach ($desired as $key => $value) {
            $this->upsert_meta($wpdb->termmeta, 'term_id', $termId, $key, $value, 'apply authored term meta');
        }
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
        $metaId = $wpdb->get_var(
            $wpdb->prepare("SELECT $idCol FROM $table WHERE $fkCol = %d AND meta_key = %s LIMIT 1", $objectId, $key)
        );
        if ($metaId) {
            Db::update(
                $table,
                ['meta_value' => $value],
                [$idCol => $metaId],
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
