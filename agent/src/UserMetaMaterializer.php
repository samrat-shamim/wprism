<?php
namespace Duo;

/**
 * The user entity materializer (DUO-3347 slice 5, one of the "Entity
 * materializers: posts, terms, menus, options/meta/users, relationships,
 * attachments, typed tables" target seams): reconciles one exact-login user's
 * authored user-meta rows against the live target. No user creation,
 * adoption, rename, fallback, capability change, or deletion exists on this
 * path -- the owning user is target-local, resolved by exact byte/case login.
 *
 * Extracted from Apply.php on top of DUO-3347 slice 3's ApplyFieldMaterializer
 * (for the shared upsert_meta() write) -- finalize_user_meta() and its own
 * exact-login resolver (resolve_exact_login(), plus its memoization cache)
 * were otherwise fully self-contained: their only external collaborators were
 * Policy (meta_rule_for_user), Tokens (ref/struct decoding), and the shared
 * meta writer, and resolve_exact_login() had exactly one caller.
 *
 * Moved verbatim; Apply keeps finalize_user_meta() as a thin compatibility
 * facade via a lazily-constructed instance (user_meta_materializer()), the
 * same pattern field_materializer()/menu_materializer()/convergence_verifier()
 * already established.
 */
final class UserMetaMaterializer {
    /** @var array<string,int> exact (binary) login -> user id */
    private array $exactUserIds = [];

    public function __construct(
        private readonly Policy $policy,
        private readonly Tokens $tokens,
        private readonly ApplyFieldMaterializer $fieldMaterializer
    ) {
    }

    /**
     * Reconcile only explicitly-authored user-meta keys for one exact login.
     * The owning user is target-local: no creation, adoption, rename,
     * fallback, capability change, or user deletion exists on this path.
     */
    public function finalize_user_meta(array $front): void {
        global $wpdb;
        $login = (string) ($front['login'] ?? '');
        $userId = $this->resolve_exact_login($login);
        if ($userId === null) {
            throw new \RuntimeException(
                "duo: user-meta exact login '$login' disappeared after preflight; transaction rolled back"
            );
        }
        $frontMeta = (array) ($front['meta'] ?? []);
        $desired = [];
        foreach ($frontMeta as $key => $value) {
            $rule = $this->policy->meta_rule_for_user((string) $key, $frontMeta) ?? [];
            if (($rule['class'] ?? '') !== 'authored') {
                throw new \RuntimeException(
                    "duo: user-meta '$key' for exact login '$login' is not authorized authored at apply"
                );
            }
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                $value = $this->tokens->struct_apply(
                    $value,
                    $rule['json_refs'] ?? [],
                    $rule['key_refs'] ?? null
                );
                $value = StructuredValue::encode($value, $rule, "user '$login' meta $key");
            } elseif (!empty($rule['ref'])) {
                // User refs need the meta decoder: unlike option refs it
                // understands user:<login>, arrays, and storage casts.
                $value = $this->tokens->meta_tokens_to_value($value, $rule);
            } elseif (is_string($value)) {
                $value = $this->tokens->detokenize_text($value);
            }
            $desired[(string) $key] = maybe_serialize($value);
        }

        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT umeta_id AS meta_id, meta_key, meta_value FROM {$wpdb->usermeta} "
            . 'WHERE user_id = %d ORDER BY umeta_id ASC',
            $userId
        ), ARRAY_A) ?: [];
        $flat = [];
        foreach ($rows as $row) {
            $flat[$row['meta_key']] ??= $row['meta_value'];
        }
        $kept = [];
        foreach ($rows as $row) {
            $rule = $this->policy->meta_rule_for_user((string) $row['meta_key'], $flat);
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            $key = (string) $row['meta_key'];
            // Canonical authored user meta is deliberately single-valued.
            // Remove every absent owned row and all but the first existing
            // row before upsert, so a dirty target cannot retain duplicates
            // that would make the verification capture refuse.
            if (!array_key_exists($key, $desired) || isset($kept[$key])) {
                Db::delete(
                    $wpdb->usermeta,
                    ['umeta_id' => (int) $row['meta_id']],
                    null,
                    "apply delete authored user meta for exact login '$login'"
                );
                continue;
            }
            $kept[$key] = true;
        }
        foreach ($desired as $key => $value) {
            $this->fieldMaterializer->upsert_meta(
                $wpdb->usermeta,
                'user_id',
                $userId,
                $key,
                $value,
                "apply reconcile authored user meta for exact login '$login'",
                'umeta_id'
            );
        }
        wp_cache_delete($userId, 'user_meta');
    }

    /** Exact byte/case login lookup for the user-meta owning-user boundary. */
    private function resolve_exact_login(string $login): ?int {
        global $wpdb;
        if ($login === '') {
            return null;
        }
        if (!array_key_exists($login, $this->exactUserIds)) {
            $id = $wpdb->get_var($wpdb->prepare(
                "SELECT ID FROM {$wpdb->users} WHERE BINARY user_login = BINARY %s LIMIT 1",
                $login
            ));
            $this->exactUserIds[$login] = $id ? (int) $id : 0;
        }
        return $this->exactUserIds[$login] ?: null;
    }
}
