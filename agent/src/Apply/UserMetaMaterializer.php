<?php
namespace WPrism;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Grammar/Tokens.php';
require_once __DIR__ . '/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../Kernel/StructuredValue.php';
require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';
require_once __DIR__ . '/../Kernel/MetaRows.php';
require_once __DIR__ . '/MetaOwnerRangeLock.php';
require_once __DIR__ . '/CacheInvalidationTransaction.php';
// Deliberately NOT require_once('Db.php') here: sandbox/tests/offline/reference-scope/regress_scoped_promotion_target.php
// and regress_adapter_observation.php both stub a fake WPrism\Db and reach this
// file transitively through Apply.php without ever loading the real Db.php;
// requiring it here fatals both suites with "Cannot redeclare class WPrism\Db"
// (caught by regress-offline-all while verifying this file). No test stubs
// Policy/Tokens/ApplyFieldMaterializer/StructuredValue the same way, so
// those four are required above; a caller that needs Db (like this file's
// own test, or Apply.php itself) must require it explicitly.

/**
 * The user entity materializer (issue #3347 slice 5, one of the "Entity
 * materializers: posts, terms, menus, options/meta/users, relationships,
 * attachments, typed tables" target seams): reconciles one exact-login user's
 * authored user-meta rows against the live target. No user creation,
 * adoption, rename, fallback, capability change, or deletion exists on this
 * path -- the owning user is target-local, resolved by exact byte/case login.
 *
 * Extracted from Apply.php on top of issue #3347 slice 3's ApplyFieldMaterializer
 * (for the shared upsert_meta() write) -- finalize_user_meta() and its own
 * exact-login resolver (resolve_exact_login())
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
    private const MAX_COLLATION_CANDIDATES = 3;
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
        DeleteGuardEvaluator::assert_table_identifiers(
            [$wpdb->users, $wpdb->usermeta],
            'authored user-meta row locking'
        );
        try {
            $this->fieldMaterializer->prove_lock_tables(
                [$wpdb->users, $wpdb->usermeta],
                'authored user-meta row locking'
            );
        } catch (\RuntimeException $failure) {
            if (str_contains($failure->getMessage(), 'requires an active transaction')) {
                throw new \RuntimeException(
                    "wprism: authored user-meta for exact login '$login' requires an active transaction",
                    0,
                    $failure
                );
            }
            throw $failure;
        }
        $loginIndex = $this->fieldMaterializer->proven_lock_index(
            $wpdb->users,
            'user_login',
            'exact-login user row'
        );
        $userMetaRange = $this->fieldMaterializer->meta_owner_range_lock(
            $wpdb->usermeta,
            'user_id',
            'authored user-meta row locking'
        );
        $userId = $this->resolve_exact_login($login, $loginIndex);
        if ($userId === null) {
            throw new \RuntimeException(
                "wprism: user-meta exact login '$login' disappeared after preflight; transaction rolled back"
            );
        }
        $frontMeta = (array) ($front['meta'] ?? []);
        $desired = [];
        foreach ($frontMeta as $key => $value) {
            $rule = $this->policy->meta_rule_for_user((string) $key, $frontMeta) ?? [];
            if (($rule['class'] ?? '') !== 'authored') {
                throw new \RuntimeException(
                    "wprism: user-meta '$key' for exact login '$login' is not authorized authored at apply"
                );
            }
            if (!empty($rule['json_refs']) || !empty($rule['key_refs'])) {
                $value = $this->tokens->struct_apply(
                    $value,
                    $rule['json_refs'] ?? [],
                    $rule['key_refs'] ?? null
                );
                $value = StructuredValue::encode($value, $rule, "user '$login' meta $key");
            } elseif (!empty($rule['plain_data'])) {
                $value = $this->tokens->plain_data_apply($value);
            } elseif (!empty($rule['ref'])) {
                // User refs need the meta decoder: unlike option refs it
                // understands user:<login>, arrays, and storage casts.
                $value = $this->tokens->meta_tokens_to_value($value, $rule);
            } elseif (is_string($value)) {
                $value = $this->tokens->detokenize_text($value);
            }
            $desired[(string) $key] = maybe_serialize($value);
        }

        $rows = $userMetaRange->read($userId, 'umeta_id');
        $flat = [];
        $exactMetaIds = [];
        foreach ($rows as $row) {
            if (!array_key_exists($row['meta_key'], $flat)) {
                // WordPress historically exposes SQL NULL usermeta values as
                // empty strings; match UserMetaCapture while retaining the
                // first physical row as policy context when duplicates exist.
                $flat[$row['meta_key']] = $row['meta_value'] ?? '';
            }
            $slot = "k\0" . $row['meta_key'];
            if (!isset($exactMetaIds[$slot])) {
                $exactMetaIds[$slot] = MetaRows::positive_id($row['meta_id']);
            }
        }
        $kept = [];
        foreach ($rows as $row) {
            $rule = $this->policy->meta_rule_for_user((string) $row['meta_key'], $flat);
            if (($rule['class'] ?? '') !== 'authored') {
                continue;
            }
            $key = (string) $row['meta_key'];
            $slot = "k\0" . $key;
            // Canonical authored user meta is deliberately single-valued.
            // Remove every absent owned row and all but the first existing
            // row before upsert, so a dirty target cannot retain duplicates
            // that would make the verification capture refuse.
            if (!array_key_exists($key, $desired) || isset($kept[$slot])) {
                Db::delete(
                    $wpdb->usermeta,
                    ['umeta_id' => (int) $row['meta_id']],
                    null,
                    "apply delete authored user meta for exact login '$login'"
                );
                continue;
            }
            $kept[$slot] = true;
        }
        // Same locked-context rule as ApplyFieldMaterializer::reconcileMetaTable()
        // (see the rationale there): recheck against the map this reconciliation
        // establishes, so a sibling-classified key whose sibling THIS roster
        // supplies is not rejected on a user that does not carry the pair yet.
        // ACF fields on users are a shipped claim (manifests/interpreters/acf.php's
        // user_meta_rule() reaching the same shadow-key machinery), and the
        // deletion pass above deliberately keeps asking the witnessed map,
        // because those rows exist now.
        $lockedContext = $flat;
        foreach ($desired as $key => $value) {
            $lockedContext[(string) $key] = $value ?? '';
        }
        foreach ($desired as $key => $value) {
            $rule = $this->policy->meta_rule_for_user((string) $key, $lockedContext);
            if (($rule['class'] ?? null) !== 'authored') {
                throw new \RuntimeException(
                    "wprism: user-meta '$key' for exact login '$login' is not authored in the locked target context"
                );
            }
            $this->fieldMaterializer->upsert_locked_authored_meta(
                $wpdb->usermeta,
                'user_id',
                $userId,
                $key,
                $value,
                $exactMetaIds["k\0" . $key] ?? null,
                "apply reconcile authored user meta for exact login '$login'",
                'umeta_id'
            );
        }
        CacheInvalidationTransaction::queue_user_meta(
            $userId,
            "authored user meta reconciliation for exact login '$login'"
        );
    }

    /**
     * Lock the complete collation-equal login range, then choose one exact
     * byte/case identity in PHP. The indexed equality predicate preserves
     * next-key/gap locking; putting BINARY around the indexed column would
     * make that proof optimizer-dependent.
     */
    private function resolve_exact_login(string $login, string $index): ?int {
        global $wpdb;
        if ($login === '') {
            return null;
        }
        DeleteGuardEvaluator::assert_transaction_isolation('exact-login user row');
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, user_login FROM {$wpdb->users} FORCE INDEX (`$index`) "
            . 'WHERE user_login = %s ORDER BY ID ASC LIMIT ' . self::MAX_COLLATION_CANDIDATES . ' FOR UPDATE',
            $login
        ), ARRAY_A);
        if (!is_array($rows) || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException(
                'wprism: exact-login locked user read failed; transaction rolled back'
            );
        }
        return $this->exact_user_id_from_locked_rows($login, $rows);
    }

    /** @param list<array<string,mixed>> $rows */
    private function exact_user_id_from_locked_rows(string $login, array $rows): ?int {
        if (count($rows) > 1) {
            throw new \RuntimeException(
                'wprism: exact-login lookup is ambiguous under the target collation; transaction rolled back'
            );
        }
        if ($rows === []) {
            return null;
        }
        $row = $rows[0];
        if (!is_array($row)
            || MetaRows::positive_id($row['ID'] ?? null) === null
            || !is_string($row['user_login'] ?? null)) {
            throw new \RuntimeException(
                'wprism: exact-login locked user row is malformed; transaction rolled back'
            );
        }
        return hash_equals($login, $row['user_login'])
            ? MetaRows::positive_id($row['ID'])
            : null;
    }

}
