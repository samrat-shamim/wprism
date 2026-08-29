<?php
namespace WPrism;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Repository/Snapshot.php';
require_once __DIR__ . '/../Apply/RelationshipMaterializer.php';
require_once __DIR__ . '/../Apply/MenuMaterializer.php';
require_once __DIR__ . '/../Apply/CacheInvalidationTransaction.php';
require_once __DIR__ . '/../Apply/ApplyFieldMaterializer.php';
// Deliberately NOT require_once('Ledger.php') or require_once('Db.php') here:
// sandbox/tests/offline/code-half/regress_code_revision_enforcement.php and
// regress_scoped_promotion_target.php both reach this file transitively
// through Apply.php (direct requires, verified) and both stub a fake
// WPrism\Ledger; regress_scoped_promotion_target.php additionally stubs a fake
// WPrism\Db. Requiring either here would fatal with "Cannot redeclare class"
// against whichever of the two a given suite fakes -- the identical
// exclusion PostMaterializer.php/RelationshipMaterializer.php already
// document for the same reason (verified via `grep -rlE '^\s*(final\s+)?class\s+
// (Db|Ledger)\s*(\{|extends|implements)'` against each file individually,
// not assumed from the combined match). None of the four newly-required
// files above (Policy, Snapshot, RelationshipMaterializer, MenuMaterializer)
// require Db.php or Ledger.php themselves either, so this file introduces
// no new transitive path to either -- verified the same way.

/**
 * The delete executor (issue #3347 slice 12, the "DeleteGuardEvaluator/
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
 * performs; issue #3347's own guardrail ("No change to conflict semantics or
 * deletion authority in extraction PRs") is exactly why it stays out.
 *
 * Two things this move deliberately does NOT carry over, both left on
 * AuthoredTransactionExecutor rather than folded in here:
 *
 * - The trailing REGEN_PENDING_PREFIX marker cleanup (issue #3234), previously
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
    private const MAX_REVISIONS = 100000;
    private const MAX_TERM_TAXONOMIES = 1024;
    public function __construct(
        private readonly Policy $policy,
        private readonly RelationshipMaterializer $relationshipMaterializer,
        private readonly MenuMaterializer $menuMaterializer,
        private readonly ApplyFieldMaterializer $fieldMaterializer
    ) {
    }

    /** @param array<string,array> $rowTables Apply::snapshotRowTables()'s roster, entity type => declared table info. */
    public function delete_entity(string $uuid, string $type, array $rowTables, array &$warnings): void {
        global $wpdb;
        if (isset($rowTables[$type])) {
            $idKind = (string) $rowTables[$type]['id_kind'];
            $localId = Ledger::id_for($uuid, $idKind);
            if ($localId === null) {
                throw new \RuntimeException("wprism: cannot delete $type $uuid: target identity mapping is missing");
            }
            Snapshot::delete_row($this->policy, $uuid, $type);
            Snapshot::assert_row_deleted($this->policy, $type, $localId);
            $warnings[] = "deleted $type $uuid";
            return;
        }
        if ($type === 'post') {
            $id = Ledger::id_for($uuid, Ledger::KIND_POST);
            if ($id === null) {
                throw new \RuntimeException("wprism: cannot delete post $uuid: target identity mapping is missing");
            }
            $post = $this->locked_post_row($id, "post $uuid deletion parent locking");
            if ($post === null) {
                throw new \RuntimeException("wprism: cannot delete post $uuid: exact target row is missing");
            }
            $postType = $post['post_type'];
            $children = $this->locked_child_posts($id, "post $uuid revision roster locking");
            $revisionIds = [];
            $preservedChildren = [];
            foreach ($children as $child) {
                if ($child['post_type'] === 'revision') {
                    $revisionIds[] = $child['ID'];
                } else {
                    $preservedChildren[] = $child;
                }
            }
            $metaLock = $this->fieldMaterializer->meta_owner_range_lock(
                $wpdb->postmeta,
                'post_id',
                "post $uuid deletion metadata locking"
            );
            foreach (array_merge([$id], $revisionIds) as $ownerId) {
                $metaLock->read($ownerId);
                $this->relationshipMaterializer->lock_owner_relationships(
                    $ownerId,
                    "post $uuid deletion relationship locking"
                );
            }
            foreach ($revisionIds as $revisionId) {
                $this->relationshipMaterializer->delete_post_relationships($revisionId, 'revision');
                Db::delete(
                    $wpdb->postmeta,
                    ['post_id' => $revisionId],
                    null,
                    'apply delete post revision meta'
                );
                Db::delete($wpdb->posts, ['ID' => $revisionId], null, 'apply delete post revision');
                CacheInvalidationTransaction::queue_post($revisionId, 'revision', 'apply delete post revision');
                if ($this->locked_post_row($revisionId, "post $uuid revision readback") !== null
                    || $metaLock->read($revisionId) !== []) {
                    throw new \RuntimeException("wprism: post $uuid revision $revisionId deletion readback was nonempty");
                }
            }
            $this->relationshipMaterializer->delete_post_relationships($id, $postType);
            Db::delete($wpdb->postmeta, ['post_id' => $id], null, 'apply delete post meta');
            Db::delete($wpdb->posts, ['ID' => $id], null, 'apply delete post');
            CacheInvalidationTransaction::queue_post($id, $postType, 'apply delete post');
            if ($this->locked_post_row($id, "post $uuid deletion readback") !== null
                || $metaLock->read($id) !== []
                || $this->locked_child_posts($id, "post $uuid revision roster readback") !== $preservedChildren) {
                throw new \RuntimeException("wprism: post $uuid exact locked deletion readback disagrees with the mutation roster");
            }
        } elseif ($type === 'term' || $type === 'menu') {
            $termId = Ledger::id_for($uuid, Ledger::KIND_TERM);
            $tt = Ledger::id_for($uuid, Ledger::KIND_TT);
            if ($termId === null || $tt === null) {
                throw new \RuntimeException("wprism: cannot delete $type $uuid: target term identity mapping is incomplete");
            }
            [$taxonomy, $relationshipObjectIds] = $this->deletion_relationship_witness($termId, $tt);
            CacheInvalidationTransaction::assert_term_taxonomy_prepared($taxonomy, "apply delete $type");
            $termMetaLock = $this->fieldMaterializer->meta_owner_range_lock(
                $wpdb->termmeta,
                'term_id',
                "apply delete $type metadata locking"
            );
            $termMetaLock->read($termId);
            $this->relationshipMaterializer->lock_owner_relationships(
                $termId,
                "apply delete $type outbound relationship locking"
            );
            $itemIds = [];
            if ($type === 'menu') {
                $postMetaLock = $this->fieldMaterializer->meta_owner_range_lock(
                    $wpdb->postmeta,
                    'post_id',
                    'apply delete menu item metadata locking'
                );
                foreach ($relationshipObjectIds as $itemId) {
                    $item = $this->locked_post_row($itemId, 'apply delete menu item post locking');
                    if ($item === null || !hash_equals('nav_menu_item', $item['post_type'])) {
                        throw new \RuntimeException('wprism: menu deletion relationship points at a non-menu-item post');
                    }
                    $postMetaLock->read($itemId);
                    $relationships = $this->relationshipMaterializer->lock_owner_relationships(
                        $itemId,
                        'apply delete menu item relationship ownership locking'
                    );
                    foreach ($relationships as $relationship) {
                        if ($relationship['term_taxonomy_id'] !== $tt) {
                            throw new \RuntimeException(
                                "wprism: menu deletion refuses item $itemId shared with another taxonomy/menu"
                            );
                        }
                    }
                    $itemIds[] = $itemId;
                }
                // Authored menu locations are part of this selected menu's
                // owned state. Remove only slots whose current value is this
                // exact term id before deleting it; assign_locations() keeps
                // every other menu's location byte-for-byte. Derived
                // locations remain wholly adapter-owned and are never
                // rewritten by the generic engine.
                if ($this->policy->menu_field_class('locations') !== 'derived') {
                    $this->menuMaterializer->assign_locations($termId, []);
                }
                foreach ($itemIds as $itemId) {
                    $itemUuid = Ledger::uuid_for($itemId, Ledger::KIND_POST);
                    $this->relationshipMaterializer->delete_post_relationships($itemId, 'nav_menu_item');
                    Db::delete($wpdb->postmeta, ['post_id' => $itemId], null, 'apply delete menu item meta');
                    Db::delete($wpdb->posts, ['ID' => $itemId], null, 'apply delete menu item');
                    CacheInvalidationTransaction::queue_post($itemId, 'nav_menu_item', 'apply delete menu item');
                    if ($itemUuid !== null) {
                        Ledger::forget($itemUuid);
                    }
                    if ($this->locked_post_row($itemId, 'apply delete menu item readback') !== null
                        || $postMetaLock->read($itemId) !== []) {
                        throw new \RuntimeException("wprism: menu $uuid item $itemId deletion readback was nonempty");
                    }
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
            if ($this->locked_inbound_relationship_ids(
                $tt,
                'apply delete taxonomy relationships readback'
            ) !== []) {
                throw new \RuntimeException('wprism: taxonomy relationship deletion locked readback was nonempty');
            }
            foreach ($relationshipObjectIds as $objectId) {
                CacheInvalidationTransaction::queue_relationship(
                    $objectId,
                    $taxonomy,
                    'apply delete taxonomy relationships'
                );
            }
            Db::delete($wpdb->term_taxonomy, ['term_taxonomy_id' => $tt], null, 'apply delete term taxonomy');
            Db::delete($wpdb->termmeta, ['term_id' => $termId], null, 'apply delete term meta');
            Db::delete($wpdb->terms, ['term_id' => $termId], null, 'apply delete term');
            CacheInvalidationTransaction::queue_term($termId, $taxonomy, 'apply delete term');
            if ($this->locked_term_row($termId, "apply delete $type term readback") !== null
                || $this->locked_term_taxonomy_rows($termId, "apply delete $type taxonomy readback") !== []
                || $termMetaLock->read($termId) !== []
                || $this->locked_inbound_relationship_ids($tt, "apply delete $type inbound readback") !== []) {
                throw new \RuntimeException("wprism: $type $uuid exact locked deletion readback was nonempty");
            }
        } else {
            throw new \RuntimeException("wprism: cannot delete unsupported entity type '$type'");
        }
        $warnings[] = "deleted $type $uuid";
    }

    /** @return array{0:string,1:list<int>} */
    private function deletion_relationship_witness(int $termId, int $termTaxonomyId): array {
        if ($this->locked_term_row($termId, 'term deletion exact term locking') === null) {
            throw new \RuntimeException('wprism: term deletion exact term row is missing');
        }
        $taxonomyRows = $this->locked_term_taxonomy_rows($termId, 'term deletion taxonomy owner-range locking');
        if (count($taxonomyRows) !== 1 || $taxonomyRows[0]['term_taxonomy_id'] !== $termTaxonomyId) {
            throw new \RuntimeException('wprism: term deletion requires one exact unshared taxonomy row');
        }
        $taxonomy = $taxonomyRows[0]['taxonomy'];

        $ids = $this->locked_inbound_relationship_ids(
            $termTaxonomyId,
            'term deletion relationship witness'
        );
        return [$taxonomy, $ids];
    }

    /** @return ?array{ID:int,post_type:string} */
    private function locked_post_row(int $postId, string $purpose): ?array {
        global $wpdb;
        $index = $this->fieldMaterializer->proven_lock_index($wpdb->posts, 'ID', $purpose, true);
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_type FROM {$wpdb->posts} FORCE INDEX (`$index`) "
            . 'WHERE ID = %d ORDER BY ID ASC LIMIT 2 FOR UPDATE',
            $postId
        ), ARRAY_A);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: $purpose failed");
        }
        if ($rows === []) return null;
        $row = $rows[0];
        $id = is_array($row) ? self::canonical_positive_id($row['ID'] ?? null) : null;
        $postType = is_array($row) ? ($row['post_type'] ?? null) : null;
        if (!is_array($row) || array_keys($row) !== ['ID', 'post_type'] || $id !== $postId
            || !is_string($postType) || preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $postType) !== 1) {
            throw new \RuntimeException("wprism: $purpose returned a malformed/aliased row");
        }
        return ['ID' => $id, 'post_type' => $postType];
    }

    /** @return list<array{ID:int,post_type:string}> */
    private function locked_child_posts(int $parentId, string $purpose): array {
        global $wpdb;
        $index = $this->fieldMaterializer->proven_lock_index($wpdb->posts, 'post_parent', $purpose);
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT ID, post_type FROM {$wpdb->posts} FORCE INDEX (`$index`) "
            . 'WHERE post_parent = %d ORDER BY ID ASC LIMIT ' . (self::MAX_REVISIONS + 1) . ' FOR UPDATE',
            $parentId
        ), ARRAY_A);
        if (!is_array($rows) || !array_is_list($rows) || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: $purpose failed");
        }
        if (count($rows) > self::MAX_REVISIONS) {
            throw new \RuntimeException("wprism: $purpose exceeds the bounded child-row limit");
        }
        $out = [];
        $seen = [];
        foreach ($rows as $position => $row) {
            $id = is_array($row) ? self::canonical_positive_id($row['ID'] ?? null) : null;
            $postType = is_array($row) ? ($row['post_type'] ?? null) : null;
            if (!is_array($row) || array_keys($row) !== ['ID', 'post_type'] || $id === null
                || !is_string($postType) || preg_match('/^[A-Za-z0-9_-]{1,20}$/D', $postType) !== 1
                || isset($seen[$id])) {
                throw new \RuntimeException("wprism: $purpose returned a malformed/duplicate row at position $position");
            }
            $seen[$id] = true;
            $out[] = ['ID' => $id, 'post_type' => $postType];
        }
        return $out;
    }

    /** @return ?array{term_id:int} */
    private function locked_term_row(int $termId, string $purpose): ?array {
        global $wpdb;
        $index = $this->fieldMaterializer->proven_lock_index($wpdb->terms, 'term_id', $purpose, true);
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT term_id FROM {$wpdb->terms} FORCE INDEX (`$index`) "
            . 'WHERE term_id = %d ORDER BY term_id ASC LIMIT 2 FOR UPDATE',
            $termId
        ), ARRAY_A);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > 1
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: $purpose failed");
        }
        if ($rows === []) return null;
        $row = $rows[0];
        if (!is_array($row) || array_keys($row) !== ['term_id']
            || self::canonical_positive_id($row['term_id'] ?? null) !== $termId) {
            throw new \RuntimeException("wprism: $purpose returned a malformed/aliased row");
        }
        return ['term_id' => $termId];
    }

    /** @return list<array{term_taxonomy_id:int,taxonomy:string}> */
    private function locked_term_taxonomy_rows(int $termId, string $purpose): array {
        global $wpdb;
        $index = $this->fieldMaterializer->proven_lock_index($wpdb->term_taxonomy, 'term_id', $purpose);
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT term_taxonomy_id, taxonomy FROM {$wpdb->term_taxonomy} FORCE INDEX (`$index`) "
            . 'WHERE term_id = %d ORDER BY term_taxonomy_id ASC LIMIT '
            . (self::MAX_TERM_TAXONOMIES + 1) . ' FOR UPDATE',
            $termId
        ), ARRAY_A);
        if (!is_array($rows) || !array_is_list($rows) || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: $purpose failed");
        }
        if (count($rows) > self::MAX_TERM_TAXONOMIES) {
            throw new \RuntimeException("wprism: $purpose exceeds the bounded taxonomy-row limit");
        }
        $out = [];
        $seen = [];
        foreach ($rows as $position => $row) {
            $id = is_array($row) ? self::canonical_positive_id($row['term_taxonomy_id'] ?? null) : null;
            $taxonomy = is_array($row) ? ($row['taxonomy'] ?? null) : null;
            if (!is_array($row) || array_keys($row) !== ['term_taxonomy_id', 'taxonomy'] || $id === null
                || !is_string($taxonomy) || preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $taxonomy) !== 1
                || isset($seen[$id])) {
                throw new \RuntimeException("wprism: $purpose returned a malformed/duplicate row at position $position");
            }
            $seen[$id] = true;
            $out[] = ['term_taxonomy_id' => $id, 'taxonomy' => $taxonomy];
        }
        return $out;
    }

    /** @return list<int> */
    private function locked_inbound_relationship_ids(int $termTaxonomyId, string $purpose): array {
        global $wpdb;
        $index = $this->fieldMaterializer->proven_lock_index(
            $wpdb->term_relationships,
            'term_taxonomy_id',
            $purpose
        );
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT object_id FROM {$wpdb->term_relationships} FORCE INDEX (`$index`) "
            . 'WHERE term_taxonomy_id = %d ORDER BY object_id ASC LIMIT 100001 FOR UPDATE',
            $termTaxonomyId
        ), ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: $purpose failed");
        }
        if (count($rows) > 100000) {
            throw new \RuntimeException("wprism: $purpose exceeds the bounded row limit");
        }
        $ids = [];
        foreach ($rows as $position => $relationship) {
            $rawId = is_array($relationship) && array_keys($relationship) === ['object_id']
                ? $relationship['object_id']
                : null;
            $id = self::canonical_positive_id($rawId);
            if ($id === null || isset($ids[$id])) {
                throw new \RuntimeException(
                    "wprism: $purpose contains a malformed/duplicate row at position $position"
                );
            }
            $ids[$id] = true;
        }
        return array_map('intval', array_keys($ids));
    }

    private static function canonical_positive_id(mixed $value): ?int {
        if (is_int($value)) return $value > 0 ? $value : null;
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) return null;
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($id) && (string) $id === $value ? $id : null;
    }

}
