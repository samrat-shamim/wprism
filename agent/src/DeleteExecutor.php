<?php
namespace Duo;

require_once __DIR__ . '/Policy.php';
require_once __DIR__ . '/Snapshot.php';
require_once __DIR__ . '/RelationshipMaterializer.php';
require_once __DIR__ . '/MenuMaterializer.php';
// Deliberately NOT require_once('Ledger.php') or require_once('Db.php') here:
// sandbox/tests/regress_code_revision_enforcement.php and
// regress_scoped_promotion_target.php both reach this file transitively
// through Apply.php (direct requires, verified) and both stub a fake
// Duo\Ledger; regress_scoped_promotion_target.php additionally stubs a fake
// Duo\Db. Requiring either here would fatal with "Cannot redeclare class"
// against whichever of the two a given suite fakes -- the identical
// exclusion PostMaterializer.php/RelationshipMaterializer.php already
// document for the same reason (verified via `grep -rlE '^\s*(final\s+)?class\s+
// (Db|Ledger)\s*(\{|extends|implements)'` against each file individually,
// not assumed from the combined match). None of the four newly-required
// files above (Policy, Snapshot, RelationshipMaterializer, MenuMaterializer)
// require Db.php or Ledger.php themselves either, so this file introduces
// no new transitive path to either -- verified the same way.

/**
 * The delete executor (DUO-3347 slice 12, the "DeleteGuardEvaluator/
 * DeleteExecutor" target seam -- executor half only): deletes one entity's
 * live rows (typed-table row, post, or term/menu) and verifies every row is
 * gone before returning. Moved verbatim from Apply::delete_entity()/
 * assert_zero(), which become thin facades (below).
 *
 * The guard-evaluation half of that seam -- build_plan()'s own collision
 * detection, which decides WHETHER a delete is authorized in the first
 * place -- is NOT part of this slice. It is deeply entangled with
 * ApplyPlanner's plan-construction code, a materially larger and riskier
 * cut than the already-decided, already-authorized row deletion this class
 * performs; DUO-3347's own guardrail ("No change to conflict semantics or
 * deletion authority in extraction PRs") is exactly why it stays out.
 *
 * Two things this move deliberately does NOT carry over, both left on
 * AuthoredTransactionExecutor rather than folded in here:
 *
 * - The trailing REGEN_PENDING_PREFIX marker cleanup (DUO-3234), previously
 *   the last statement of the 'post' branch. Clearing a stale regen-pending
 *   marker for a uuid that no longer resolves to anything is reconciliation
 *   bookkeeping -- this issue's own separately-named ReconciliationCoordinator
 *   territory, not yet extracted -- not "execute this entity's row
 *   deletion." A real conceptual boundary, not just a convenient place to
 *   stop: this class has no reason to know REGEN_PENDING_PREFIX exists at
 *   all. The transaction executor runs it immediately after delegating here, which
 *   only reorders it after the (unrelated) "deleted $type $uuid" warning
 *   append rather than before -- the two have no interaction, so this is
 *   not an observable behavior change.
 *
 * - $scopeContract itself: the only place delete_entity() previously read
 *   it was that same regen-marker guard, which stayed on the transaction executor. This class
 *   never needed scopeContract as a dependency at all once that one line
 *   moved out with it -- narrower than a mechanical carry-over would have
 *   produced.
 *
 * assign_locations() is called directly on a constructor-injected
 * MenuMaterializer here, NOT through Apply's own assign_locations() facade
 * (which stays, see Apply.php) -- calling back through Apply would be
 * circular, the same reasoning PostMaterializer's finalize_post() already
 * established for its own sibling-materializer calls (slice 11).
 * delete_post_relationships()/delete_term_relationships() are called the
 * same way, directly on a constructor-injected RelationshipMaterializer;
 * unlike assign_locations(), Apply's own facades over those two are dead
 * code once this moves (delete_entity() was their only caller, verified by
 * grep, with no reflection-based test caller either) and were removed
 * entirely rather than kept, the same "no other caller, no facade needed"
 * treatment TermMaterializer's encode_description()/
 * reconcile_term_relationships() established (slice 6).
 */
final class DeleteExecutor {
    public function __construct(
        private readonly Policy $policy,
        private readonly RelationshipMaterializer $relationshipMaterializer,
        private readonly MenuMaterializer $menuMaterializer
    ) {
    }

    /** @param array<string,array> $rowTables Apply::snapshotRowTables()'s roster, entity type => declared table info. */
    public function delete_entity(string $uuid, string $type, array $rowTables, array &$warnings): void {
        global $wpdb;
        if (isset($rowTables[$type])) {
            $idKind = (string) $rowTables[$type]['id_kind'];
            $localId = Ledger::id_for($uuid, $idKind);
            if ($localId === null) {
                throw new \RuntimeException("duo: cannot delete $type $uuid: target identity mapping is missing");
            }
            Snapshot::delete_row($this->policy, $uuid, $type);
            Snapshot::assert_row_deleted($this->policy, $type, $localId);
            $warnings[] = "deleted $type $uuid";
            return;
        }
        if ($type === 'post') {
            $id = Ledger::id_for($uuid, Ledger::KIND_POST);
            if ($id === null) {
                throw new \RuntimeException("duo: cannot delete post $uuid: target identity mapping is missing");
            }
            $postType = (string) $wpdb->get_var($wpdb->prepare(
                "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d", $id
            ));
            $revisionIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'revision' ORDER BY ID ASC",
                $id
            )) ?: []);
            foreach ($revisionIds as $revisionId) {
                Db::delete(
                    $wpdb->postmeta,
                    ['post_id' => $revisionId],
                    null,
                    'apply delete post revision meta'
                );
                Db::delete($wpdb->posts, ['ID' => $revisionId], null, 'apply delete post revision');
                $this->assert_zero(
                    "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d",
                    [$revisionId],
                    "post $uuid revision $revisionId"
                );
                $this->assert_zero(
                    "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d",
                    [$revisionId],
                    "post $uuid revision $revisionId metadata"
                );
            }
            $this->relationshipMaterializer->delete_post_relationships($id, $postType);
            Db::delete($wpdb->postmeta, ['post_id' => $id], null, 'apply delete post meta');
            Db::delete($wpdb->posts, ['ID' => $id], null, 'apply delete post');
            $this->assert_zero(
                "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d",
                [$id],
                "post $uuid row"
            );
            $this->assert_zero(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d",
                [$id],
                "post $uuid metadata"
            );
        } elseif ($type === 'term' || $type === 'menu') {
            $termId = Ledger::id_for($uuid, Ledger::KIND_TERM);
            $tt = Ledger::id_for($uuid, Ledger::KIND_TT);
            if ($termId === null || $tt === null) {
                throw new \RuntimeException("duo: cannot delete $type $uuid: target term identity mapping is incomplete");
            }
            if ($type === 'menu') {
                // Authored menu locations are part of this selected menu's
                // owned state. Remove only slots whose current value is this
                // exact term id before deleting it; assign_locations() keeps
                // every other menu's location byte-for-byte. Derived
                // locations remain wholly adapter-owned and are never
                // rewritten by the generic engine.
                if ($this->policy->menu_field_class('locations') !== 'derived') {
                    $this->menuMaterializer->assign_locations($termId, []);
                }
                $itemIds = array_map('intval', $wpdb->get_col($wpdb->prepare(
                    "SELECT p.ID FROM {$wpdb->posts} p
                     JOIN {$wpdb->term_relationships} tr ON tr.object_id = p.ID
                     WHERE tr.term_taxonomy_id = %d AND p.post_type = 'nav_menu_item'
                     ORDER BY p.ID ASC",
                    $tt
                )) ?: []);
                foreach ($itemIds as $itemId) {
                    $itemUuid = Ledger::uuid_for($itemId, Ledger::KIND_POST);
                    $this->relationshipMaterializer->delete_post_relationships($itemId, 'nav_menu_item');
                    Db::delete($wpdb->postmeta, ['post_id' => $itemId], null, 'apply delete menu item meta');
                    Db::delete($wpdb->posts, ['ID' => $itemId], null, 'apply delete menu item');
                    if ($itemUuid !== null) {
                        Ledger::forget($itemUuid);
                    }
                    $this->assert_zero(
                        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE ID = %d",
                        [$itemId],
                        "menu $uuid item $itemId"
                    );
                    $this->assert_zero(
                        "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE post_id = %d",
                        [$itemId],
                        "menu $uuid item $itemId metadata"
                    );
                }
            }
            // This term's OWN outbound relationships (term-object taxonomies
            // where THIS term is object_id) go before target-side cleanup.
            $this->relationshipMaterializer->delete_term_relationships($termId);
            Db::delete(
                $wpdb->term_relationships,
                ['term_taxonomy_id' => $tt],
                null,
                'apply delete taxonomy relationships'
            );
            Db::delete($wpdb->term_taxonomy, ['term_taxonomy_id' => $tt], null, 'apply delete term taxonomy');
            Db::delete($wpdb->termmeta, ['term_id' => $termId], null, 'apply delete term meta');
            Db::delete($wpdb->terms, ['term_id' => $termId], null, 'apply delete term');
            $this->assert_zero(
                "SELECT COUNT(*) FROM {$wpdb->terms} WHERE term_id = %d",
                [$termId],
                "$type $uuid term row"
            );
            $this->assert_zero(
                "SELECT COUNT(*) FROM {$wpdb->term_taxonomy} WHERE term_taxonomy_id = %d",
                [$tt],
                "$type $uuid taxonomy row"
            );
            $this->assert_zero(
                "SELECT COUNT(*) FROM {$wpdb->termmeta} WHERE term_id = %d",
                [$termId],
                "$type $uuid metadata"
            );
            $this->assert_zero(
                "SELECT COUNT(*) FROM {$wpdb->term_relationships} WHERE term_taxonomy_id = %d",
                [$tt],
                "$type $uuid inbound relationships"
            );
        } else {
            throw new \RuntimeException("duo: cannot delete unsupported entity type '$type'");
        }
        $warnings[] = "deleted $type $uuid";
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
