<?php
namespace Duo;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/CacheInvalidationTransaction.php';
require_once __DIR__ . '/ApplyFieldMaterializer.php';
// Deliberately NOT require_once('Ledger.php') or require_once('Db.php') here:
// sandbox/tests/offline/code-half/regress_code_revision_enforcement.php and
// regress_scoped_promotion_target.php both reach this file transitively
// through Apply.php (direct requires, verified) and both stub a fake
// Duo\Ledger; regress_scoped_promotion_target.php additionally stubs a fake
// Duo\Db. Requiring either here would fatal with "Cannot redeclare class"
// against whichever of the two a given suite fakes -- the identical
// exclusion TermMaterializer.php already documents for the same reason
// (verified via `grep -rlE '^\s*(final\s+)?class\s+(Db|Ledger)\s*(\{|extends|implements)'`
// against each file individually, not assumed from the combined match).

/**
 * The relationship materializer (DUO-3347 slice 8, one of the "Entity
 * materializers: posts, relationships, attachments, typed tables" target
 * seams): reconciles a post's own term_relationships rows against its
 * captured `terms`/`term_orders` front matter, and deletes a post's or
 * term's own outbound term_relationships rows during entity deletion.
 *
 * Extracted from Apply.php: reconcile_relationships() had exactly one
 * caller (finalize_post(), which stays on Apply for now); delete_post_
 * relationships()/delete_term_relationships() are called only from
 * delete_entity() (also staying on Apply -- part of the still-unextracted
 * DeleteGuardEvaluator/DeleteExecutor seam) -- twice for the former (the
 * deleted post itself, and once per deleted nav_menu_item child in a
 * loop), once for the latter. All three were otherwise fully
 * self-contained: their only *injected* collaborator is Policy
 * (taxonomy_object_keyspace()), plus the static Db/Ledger facades, the
 * live WordPress taxonomy registry, and $wpdb -- no Tokens, no
 * ApplyFieldMaterializer, narrower than the (Policy, Tokens,
 * ApplyFieldMaterializer) contract Menu/UserMeta/Term/Options established.
 *
 * Scope: the one dependency outside the narrow contract.
 * reconcile_relationships() needs the policy-scoped taxonomy roster for
 * ONE post type, which Apply computes via its shared, memoized, WordPress-
 * registry-reading, warnings-collecting taxes_by_object_type() (the same
 * method TermMaterializer's own docblock describes -- also consumed by the
 * term-object side of that same roster). Not a narrow, injectable
 * dependency, so reconcile_relationships() takes the resolved
 * $taxesForPostType array as an explicit parameter instead, matching
 * DUO-3347's own guardrail ("dependency injection and narrow data
 * contracts") and TermMaterializer's identical precedent for
 * $termObjectTaxes. delete_post_relationships()/delete_term_relationships()
 * need no such parameter: unlike reconcile_relationships(), they compute
 * their own taxonomy list directly from the live WordPress registry
 * (get_taxonomies()/get_taxonomy()) rather than through the shared memoized
 * roster, so they were already fully self-contained.
 *
 * assert_zero() is duplicated here as a private method rather than shared
 * with Apply's own copy: Apply's delete_entity() (unextracted this slice)
 * still calls its own copy at 10 other call sites, so removing it from
 * Apply would require delete_entity() to route through this class for a
 * behavior-preserving extraction that isn't otherwise in scope. The
 * duplicated body is five lines, generic, and has no state of its own.
 *
 * Moved verbatim; Apply keeps all three methods as thin compatibility
 * facades via a lazily-constructed instance (relationship_materializer()),
 * the same pattern field_materializer()/menu_materializer()/
 * user_meta_materializer()/term_materializer()/options_materializer()
 * already established.
 */
final class RelationshipMaterializer {
    private const MAX_OWNER_RELATIONSHIPS = 100000;

    public function __construct(
        private readonly Policy $policy,
        private readonly ApplyFieldMaterializer $fieldMaterializer
    ) {
    }

    /** @param string[] $taxesForPostType policy-scoped taxonomies this post type owns */
    public function reconcile_relationships(
        int $postId,
        string $postType,
        array $termsField,
        array $termOrders,
        array $taxesForPostType
    ): void {
        global $wpdb;
        foreach (array_keys($termsField) as $tax) {
            $keyspace = $this->policy->taxonomy_object_keyspace((string) $tax);
            if ($keyspace !== 'post') {
                throw new \RuntimeException(
                    "duo: post $postId declares terms.$tax, but manifest object_keyspace is '$keyspace' "
                    . '— post terms require object_keyspace=post'
                );
            }
        }
        $taxes = $taxesForPostType;
        if (!$taxes) {
            return;
        }
        $desiredTt = [];
        foreach ($termsField as $tax => $uuids) {
            if (!in_array($tax, $taxes, true)) {
                // Not a taxonomy this post type actually owns (stale file from
                // before the object-type filter existed, or a hand edit) —
                // never let it reach the ledger lookup / INSERT below.
                continue;
            }
            foreach ((array) $uuids as $u) {
                $tt = Ledger::id_for($u, Ledger::KIND_TT)
                    ?? throw new \RuntimeException("duo: post $postId references unresolvable term $u ($tax)");
                $desiredTt[$tt] = (int) (($termOrders[$tax] ?? [])[$u] ?? 0);
            }
        }
        $currentRows = $this->locked_relationship_rows(
            $postId,
            $taxes,
            'apply reconcile post relationships'
        );
        $current = [];
        foreach ($currentRows as $row) {
            $current[(int) $row['term_taxonomy_id']] = (int) $row['term_order'];
        }
        $mutated = false;
        foreach (array_keys($current) as $tt) {
            if (!isset($desiredTt[(int) $tt])) {
                Db::delete($wpdb->term_relationships, ['object_id' => $postId, 'term_taxonomy_id' => (int) $tt], null, 'apply delete post relationship');
                $mutated = true;
            }
        }
        foreach ($desiredTt as $tt => $order) {
            if (!array_key_exists($tt, $current)) {
                Db::insert($wpdb->term_relationships, [
                    'object_id' => $postId, 'term_taxonomy_id' => $tt, 'term_order' => $order,
                ], null, 'apply insert post relationship');
                $mutated = true;
            } elseif ($current[$tt] !== $order) {
                Db::update(
                    $wpdb->term_relationships,
                    ['term_order' => $order],
                    ['object_id' => $postId, 'term_taxonomy_id' => $tt],
                    null,
                    null,
                    'apply update post relationship order'
                );
                $mutated = true;
            }
        }
        if ($mutated) {
            foreach ($taxes as $taxonomy) {
                CacheInvalidationTransaction::queue_relationship(
                    $postId,
                    (string) $taxonomy,
                    'apply reconcile post relationships'
                );
            }
        }
        $after = [];
        foreach ($this->locked_relationship_rows(
            $postId,
            $taxes,
            'apply reconcile post relationships readback'
        ) as $row) {
            $after[(int) $row['term_taxonomy_id']] = (int) $row['term_order'];
        }
        ksort($after, SORT_NUMERIC);
        ksort($desiredTt, SORT_NUMERIC);
        if ($after !== $desiredTt) {
            throw new \RuntimeException('duo: post relationship locked readback disagrees with desired storage');
        }
    }

    /**
     * Delete a post's own term_relationships rows only — scoped to every
     * taxonomy REGISTERED on this runtime whose object_type includes this
     * post's type and whose resolved object_keyspace is `post` (deliberately
     * not policy-scoped: a full post delete must clean up every taxonomy
     * that legitimately relates to it, same as wp_delete_post(), not just
     * the ones Duo happens to manage).
     *
     * An unfiltered `DELETE ... WHERE object_id = $id` (the previous code)
     * hits every term_relationships row with that raw id regardless of
     * taxonomy — including a term-object taxonomy's rows for a completely
     * different TERM that happens to have the same id, since posts and
     * terms are minted from independent auto-increment counters sharing
     * one numeric space. That would silently destroy the colliding term's
     * genuine data as a side effect of deleting an unrelated post.
     */
    public function delete_post_relationships(int $id, string $postType): void {
        global $wpdb;
        $taxes = $this->post_deletion_taxonomies($postType);
        $rows = $this->lock_owner_relationships($id, 'apply delete post relationships');
        foreach ($rows as $row) {
            if (!in_array($row['taxonomy'], $taxes, true)) continue;
            Db::delete(
                $wpdb->term_relationships,
                ['object_id' => $id, 'term_taxonomy_id' => $row['term_taxonomy_id']],
                null,
                'apply delete post relationship'
            );
        }
        foreach ($taxes as $taxonomy) {
            CacheInvalidationTransaction::queue_relationship(
                $id,
                (string) $taxonomy,
                'apply delete post relationships'
            );
        }
        if ($this->filter_taxonomies(
            $this->lock_owner_relationships($id, 'apply delete post relationships readback'),
            $taxes
        ) !== []) {
            throw new \RuntimeException("duo: post $id relationship deletion locked readback was nonempty");
        }
    }

    /**
     * Term-object symmetry of delete_post_relationships() immediately
     * above: a deleted term's OWN relationship rows as object_id (term-
     * object taxonomies, e.g. Polylang's term_language/term_translations),
     * scoped to every taxonomy REGISTERED on this runtime whose manifest-
     * resolved object_keyspace is `term`. An undeclared runtime term/mixed
     * taxonomy refuses before mutation instead of making literal `term` an
     * engine-owned plugin sentinel. An unfiltered `DELETE ... WHERE object_id = $id`
     * would hit every term_relationships row with that raw id regardless of
     * taxonomy — including a POST-object taxonomy's row for a completely
     * different POST that happens to share this term's id.
     */
    public function delete_term_relationships(int $termId): void {
        global $wpdb;
        $taxes = $this->term_deletion_taxonomies();
        $rows = $this->lock_owner_relationships($termId, 'apply delete term-object relationships');
        foreach ($rows as $row) {
            if (!in_array($row['taxonomy'], $taxes, true)) continue;
            Db::delete(
                $wpdb->term_relationships,
                ['object_id' => $termId, 'term_taxonomy_id' => $row['term_taxonomy_id']],
                null,
                'apply delete term-object relationship'
            );
        }
        foreach ($taxes as $taxonomy) {
            CacheInvalidationTransaction::queue_relationship(
                $termId,
                (string) $taxonomy,
                'apply delete term-object relationships'
            );
        }
        if ($this->filter_taxonomies(
            $this->lock_owner_relationships($termId, 'apply delete term-object relationships readback'),
            $taxes
        ) !== []) {
            throw new \RuntimeException("duo: term $termId outbound relationship deletion locked readback was nonempty");
        }
    }

    /** @return list<array{term_taxonomy_id:int,term_order:int,taxonomy:string}> */
    private function locked_relationship_rows(int $objectId, array $taxonomies, string $purpose): array {
        if ($objectId <= 0 || $taxonomies === [] || !array_is_list($taxonomies)) {
            throw new \RuntimeException("duo: $purpose received a malformed relationship owner/scope");
        }
        $taxonomies = array_values(array_unique(array_map('strval', $taxonomies)));
        sort($taxonomies, SORT_STRING);
        foreach ($taxonomies as $taxonomy) {
            if (preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $taxonomy) !== 1) {
                throw new \RuntimeException("duo: $purpose received a malformed taxonomy");
            }
        }
        return $this->filter_taxonomies($this->lock_owner_relationships($objectId, $purpose), $taxonomies);
    }

    /**
     * Lock the complete object_id range, including the absent gap. Callers
     * may then classify rows by exact taxonomy without allowing a concurrent
     * insert or an unowned colliding relationship to escape observation.
     *
     * @return list<array{term_taxonomy_id:int,term_order:int,taxonomy:string}>
     */
    public function lock_owner_relationships(int $objectId, string $purpose): array {
        global $wpdb;
        if ($objectId <= 0) {
            throw new \RuntimeException("duo: $purpose received a malformed relationship owner");
        }
        $index = $this->fieldMaterializer->proven_lock_index(
            $wpdb->term_relationships,
            'object_id',
            $purpose
        );
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT tr.term_taxonomy_id, tr.term_order, tt.taxonomy '
            . "FROM {$wpdb->term_relationships} tr FORCE INDEX (`$index`) "
            . "LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id "
            . 'WHERE tr.object_id = %d '
            . 'ORDER BY tr.term_taxonomy_id ASC LIMIT ' . (self::MAX_OWNER_RELATIONSHIPS + 1)
            . ' FOR UPDATE',
            $objectId
        ), ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("duo: $purpose locked relationship read failed");
        }
        if (count($rows) > self::MAX_OWNER_RELATIONSHIPS) {
            throw new \RuntimeException("duo: $purpose exceeds the bounded relationship limit");
        }
        $out = [];
        $seen = [];
        foreach ($rows as $position => $row) {
            $tt = is_array($row) ? self::canonical_positive_id($row['term_taxonomy_id'] ?? null) : null;
            $order = is_array($row) ? self::canonical_signed_int($row['term_order'] ?? null) : null;
            $taxonomy = is_array($row) ? ($row['taxonomy'] ?? null) : null;
            if (!is_array($row)
                || array_keys($row) !== ['term_taxonomy_id', 'term_order', 'taxonomy']
                || $tt === null
                || $order === null
                || !is_string($taxonomy)
                || preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $taxonomy) !== 1
                || isset($seen[$tt])) {
                throw new \RuntimeException("duo: $purpose returned a malformed/duplicate row at position $position");
            }
            $seen[$tt] = true;
            $out[] = ['term_taxonomy_id' => $tt, 'term_order' => $order, 'taxonomy' => $taxonomy];
        }
        return $out;
    }

    /** @return list<array{term_taxonomy_id:int,term_order:int,taxonomy:string}> */
    private function filter_taxonomies(array $rows, array $taxonomies): array {
        return array_values(array_filter(
            $rows,
            static fn(array $row): bool => in_array($row['taxonomy'], $taxonomies, true)
        ));
    }

    /** @return list<string> */
    private function post_deletion_taxonomies(string $postType): array {
        if (preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $postType) !== 1) {
            throw new \RuntimeException('duo: post relationship deletion received a malformed post type');
        }
        return array_values(array_filter(
            $this->runtime_taxonomy_roster(),
            function (string $taxonomy) use ($postType): bool {
                $object = get_taxonomy($taxonomy);
                if (!is_object($object) || !is_array($object->object_type ?? null)) {
                    throw new \RuntimeException('duo: relationship deletion encountered malformed taxonomy registration');
                }
                return in_array($postType, $object->object_type, true)
                    && $this->policy->taxonomy_object_keyspace($taxonomy, $object->object_type) === 'post';
            }
        ));
    }

    /** @return list<string> */
    private function term_deletion_taxonomies(): array {
        return array_values(array_filter(
            $this->runtime_taxonomy_roster(),
            function (string $taxonomy): bool {
                $object = get_taxonomy($taxonomy);
                if (!is_object($object) || !is_array($object->object_type ?? null)) {
                    throw new \RuntimeException('duo: relationship deletion encountered malformed taxonomy registration');
                }
                return $this->policy->taxonomy_object_keyspace($taxonomy, $object->object_type) === 'term';
            }
        ));
    }

    /** @return list<string> */
    private function runtime_taxonomy_roster(): array {
        $raw = get_taxonomies([], 'names');
        if (!is_array($raw) || count($raw) > 4096) {
            throw new \RuntimeException('duo: relationship deletion taxonomy registry is malformed or saturated');
        }
        $out = [];
        foreach ($raw as $key => $value) {
            $taxonomy = is_int($key) ? $value : $key;
            if (!is_string($taxonomy)
                || (!is_int($key) && (!is_string($value) || !hash_equals($taxonomy, $value)))
                || preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $taxonomy) !== 1
                || isset($out[$taxonomy])) {
                throw new \RuntimeException('duo: relationship deletion taxonomy registry contains a malformed/duplicate name');
            }
            $out[$taxonomy] = true;
        }
        $names = array_keys($out);
        sort($names, SORT_STRING);
        return $names;
    }

    private static function canonical_positive_id(mixed $value): ?int {
        if (is_int($value)) return $value > 0 ? $value : null;
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($id) && (string) $id === $value ? $id : null;
    }

    private static function canonical_signed_int(mixed $value): ?int {
        if (is_int($value)) return $value;
        if (!is_string($value) || preg_match('/^(?:0|-?[1-9][0-9]*)$/D', $value) !== 1) return null;
        $parsed = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($parsed) && (string) $parsed === $value ? $parsed : null;
    }
}
