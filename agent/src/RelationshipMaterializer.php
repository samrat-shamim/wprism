<?php
namespace Duo;

require_once __DIR__ . '/Policy.php';
// Deliberately NOT require_once('Ledger.php') or require_once('Db.php') here:
// sandbox/tests/regress_code_revision_enforcement.php and
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
 * relationships()/delete_term_relationships() are each called once, from
 * delete_entity() (also staying on Apply -- part of the still-unextracted
 * DeleteGuardEvaluator/DeleteExecutor seam). All three were otherwise
 * fully self-contained: their only external collaborator is Policy
 * (taxonomy_object_keyspace()) plus the live WordPress taxonomy registry
 * and $wpdb -- no Tokens, no ApplyFieldMaterializer, narrower than the
 * (Policy, Tokens, ApplyFieldMaterializer) contract Menu/UserMeta/Term/
 * Options established.
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
 * still calls its own copy at 8 other call sites, so removing it from
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
    public function __construct(private readonly Policy $policy) {
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
        $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
        $currentRows = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.term_taxonomy_id, tr.term_order FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            $postId
        ), ARRAY_A) ?: [];
        $current = [];
        foreach ($currentRows as $row) {
            $current[(int) $row['term_taxonomy_id']] = (int) $row['term_order'];
        }
        foreach (array_keys($current) as $tt) {
            if (!isset($desiredTt[(int) $tt])) {
                Db::delete($wpdb->term_relationships, ['object_id' => $postId, 'term_taxonomy_id' => (int) $tt], null, 'apply delete post relationship');
            }
        }
        foreach ($desiredTt as $tt => $order) {
            if (!array_key_exists($tt, $current)) {
                Db::insert($wpdb->term_relationships, [
                    'object_id' => $postId, 'term_taxonomy_id' => $tt, 'term_order' => $order,
                ], null, 'apply insert post relationship');
            } elseif ($current[$tt] !== $order) {
                Db::update(
                    $wpdb->term_relationships,
                    ['term_order' => $order],
                    ['object_id' => $postId, 'term_taxonomy_id' => $tt],
                    null,
                    null,
                    'apply update post relationship order'
                );
            }
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
        $taxes = array_values(array_filter(get_taxonomies(), function (string $tax) use ($postType) {
            $taxObj = get_taxonomy($tax);
            if ($taxObj === false || !in_array($postType, (array) $taxObj->object_type, true)) {
                return false;
            }
            return $this->policy->taxonomy_object_keyspace($tax, (array) $taxObj->object_type) === 'post';
        }));
        if (!$taxes) {
            return;
        }
        $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
        Db::query($wpdb->prepare(
            "DELETE tr FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            $id
        ), 'apply delete post relationships');
        $this->assert_zero(
            "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            [$id],
            "post $id term relationships"
        );
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
        $taxes = array_values(array_filter(get_taxonomies(), function (string $tax) {
            $taxObj = get_taxonomy($tax);
            return $taxObj !== false
                && $this->policy->taxonomy_object_keyspace($tax, (array) $taxObj->object_type) === 'term';
        }));
        if (!$taxes) {
            return;
        }
        $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
        Db::query($wpdb->prepare(
            "DELETE tr FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            $termId
        ), 'apply delete term-object relationships');
        $this->assert_zero(
            "SELECT COUNT(*) FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            [$termId],
            "term $termId outbound relationships"
        );
    }

    /** A post-delete assertion inside the active transaction. */
    private function assert_zero(string $sql, array $args, string $label): void {
        global $wpdb;
        $count = (int) $wpdb->get_var($wpdb->prepare($sql, ...$args));
        if ($count !== 0) {
            throw new \RuntimeException("duo: deletion verification failed: $count $label row(s) remain");
        }
    }
}
