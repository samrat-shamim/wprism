<?php
namespace Duo\Providers;

use Duo\Policy;

/**
 * WooCommerce 11.x product lookup adapter.
 *
 * Duo writes product posts and postmeta with SQL, intentionally bypassing the
 * save hooks Woo normally uses to maintain derived product state. This
 * adapter is the manifest-owned boundary for the product-meta lookup, price,
 * and sale-schedule surfaces that have independent synchronous verification.
 * It deliberately does not call on_product_changed():
 * Woo's public hook-facing method schedules Action Scheduler work by default,
 * which would leave a successful Duo apply with a pending, non-deterministic
 * lookup update.  The public data-store methods used here are synchronous.
 *
 * Dispatch note (DUO-3342): this file is now reached through the DUO-3338
 * provider contract — identity binding, negotiation before the first target
 * mutation, a declared timeout budget, and a verified receipt — rather than
 * the engine's regenerator channel it was written against. The two things
 * that forced the older channel are both gone: DUO-3369 gave a capability
 * structured arguments and the engine batch CHANNELS (`deletions`,
 * `reparents`, `retry`, `always_on_write`), and DUO-3342 made those channels
 * carry the pre-delete inventory and own their durable markers, so the
 * deletion and reparent context this adapter requires is expressible without
 * loss. Everything below invoke() is the regenerator code MOVED, not
 * rewritten: the batch entry point keeps its `regenerate_batch(array
 * $liveIds, array $deletionContext, ?callable $heartbeat = null)` shape and
 * its internal contract with the deletion-context rows, and invoke() is the
 * thin mapping from the engine envelope onto exactly those two arguments.
 *
 * What did NOT survive the move: the single-id `regenerate()` boundary, which
 * existed only for the regenerator channel's per-entity path. Nothing calls it
 * now, and leaving it would advertise participation in a contract this class
 * no longer has.
 */
final class WoocommerceProductLookups {
    private Policy $policy;

    private const META_LOOKUP = 'wc_product_meta_lookup';
    private const CAPABILITY = 'rebuild_product_lookups';

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    /** @return array{id:string, plugin:string, version:string} */
    public function identity(): array {
        return [
            'id' => 'woocommerce-product-lookups',
            'plugin' => 'woocommerce/woocommerce.php',
            'version' => '1.0.0',
        ];
    }

    /**
     * `reads`/`writes` are the capability-level summary in the canonical
     * surface vocabulary; the precise restorable/irreversible inventory of one
     * invocation is the declaring action's own `effects` list in
     * manifests/woocommerce.json — the migrated set minus the two attribute
     * lookup writes DUO-3411 made explicitly unsupported.
     *
     * `context` names every channel this repair actually consumes, and each
     * one is load-bearing rather than aspirational:
     *   - `deletions`  drives the public product-meta lookup deletion path —
     *     the row of a removed product/variation, its captured
     *     `child_ids`, and the still-live parent whose derived price the
     *     removal changed;
     *   - `reparents`  refreshes BOTH roots of a moved variation. One row per
     *     root is how the engine normalizes a chained A->B->C move, and
     *     invoke() regroups them per entity so the accumulated root set reaches
     *     the batch entry point exactly as the durable receipt held it;
     *   - `retry`      states that a previous apply committed something and
     *     failed, which is the run on which the durable receipts above are the
     *     only evidence left;
     *   - `always_on_write` states the basis this fired on, mirroring the
     *     `batch.always_on_write` flag the retired regen_dependency declared.
     *
     * `args` is empty deliberately: every input is engine-assembled. A
     * capability may not declare the reserved `entities` argument at all
     * (\Duo\Providers::validate_capability_declaration() refuses it), because
     * the batch the engine assembled and the batch a manifest asked for must
     * not be able to disagree.
     *
     * 300 seconds is the promotion lease TTL (PromotionLock::DEFAULT_TTL), and
     * that is the honest bound rather than a guess about catalog size. The
     * provider contract has no heartbeat parameter, so the engine renews the
     * lease immediately before and after this call and cannot renew during it.
     * What that does NOT mean — stated because the obvious reading is wrong —
     * is that overrunning the TTL self-aborts: PromotionLock::heartbeat()
     * tolerates an EXPIRED lease while the promotion's own process fence is
     * continuous (same process, same owner, same artifact), so the renewal on
     * the far side of a long call still succeeds unless another writer actually
     * took the lock while this one was expired. The real exposure of a long
     * invocation is therefore that window of acquirability, and pinning the
     * budget to the same number keeps the two bounds from disagreeing: an
     * overrun is reported once, as a declared-budget failure with the measured
     * duration attached, rather than later as a lock loss whose cause nobody
     * recorded.
     */
    public function capabilities(): array {
        return [
            self::CAPABILITY => [
                'args' => [],
                'reads' => [
                    'post:product',
                    'post:product_variation',
                    'table:postmeta',
                    'table:posts',
                    'table:term_relationships',
                ],
                'writes' => [
                    'table:actionscheduler_actions',
                    'table:postmeta',
                    'table:wc_product_meta_lookup',
                ],
                'scope' => 'entity',
                'idempotent' => true,
                'timeout_seconds' => 300,
                'context' => ['always_on_write', 'deletions', 'reparents', 'retry'],
            ],
        ];
    }

    /**
     * Map the engine batch envelope onto the batch entry point below, run it,
     * and return a receipt whose `verified` is true only because
     * verify_exact_state()/verify_sale_schedules() proved every supported
     * value surface. The attribute lookup table is outside this provider's
     * authority because Woo exposes no independent bounded value oracle; its
     * apply surface is explicitly unsupported in the manifest disposition.
     *
     * before/after are observed row cardinalities in the supported product
     * meta lookup over exactly the ids this invocation was handed — a cheap,
     * value-level statement about what changed. They are deliberately NOT the
     * verification: a count is not proof that a row holds what WooCommerce
     * derives. The exact-state pass verifies the supported product-meta and
     * sale-schedule surfaces only.
     *
     * @param array<string,mixed> $args
     * @return array{before:array, after:array, verified:true}
     */
    public function invoke(string $capability, array $args): array {
        if ($capability !== self::CAPABILITY) {
            throw new \RuntimeException(
                "duo: WooCommerce product lookup provider does not implement capability '$capability'"
            );
        }
        $this->assert_runtime_contract();
        $envelope = $args[\Duo\Providers::ENTITIES_ARG] ?? null;
        if (!is_array($envelope) || !array_key_exists('entities', $envelope)
            || !array_key_exists('deletions', $envelope) || !array_key_exists('reparents', $envelope)) {
            // Unreachable through the engine, which assembles exactly the
            // declared channels or refuses. Fail closed rather than repair an
            // empty batch and report it as done: a missing channel here would
            // mean this adapter silently stopped seeing tombstones.
            throw new \RuntimeException(
                'duo: WooCommerce product lookup repair received no engine batch envelope; expected the '
                . 'entities/deletions/reparents channels its capability declares'
            );
        }
        $liveIds = [];
        foreach ((array) $envelope['entities'] as $entity) {
            $id = (int) ($entity['id'] ?? 0);
            if ($id > 0) {
                $liveIds[$id] = $id;
            }
        }
        $deletionContext = $this->deletion_context_from_channels(
            (array) $envelope['deletions'],
            (array) $envelope['reparents']
        );

        $observed = array_values($liveIds);
        foreach ($deletionContext as $context) {
            foreach (array_merge(
                [(int) ($context['id'] ?? 0)],
                array_map('intval', (array) ($context['child_ids'] ?? [])),
                array_map('intval', (array) ($context['root_ids'] ?? []))
            ) as $id) {
                if ($id > 0) {
                    $observed[] = $id;
                }
            }
        }
        $observed = array_values(array_unique($observed));
        sort($observed, SORT_NUMERIC);

        $before = $this->observe_lookup_state($observed);
        $this->regenerate_batch(array_values($liveIds), $deletionContext);
        return [
            'before' => $before,
            'after' => $this->observe_lookup_state($observed),
            'verified' => true,
        ];
    }

    /**
     * Rebuild the regenerator-shaped deletion context from the two engine
     * channels.
     *
     * The deletions channel is a straight relabel — the engine row already
     * carries the captured `post_type`, `parent_id`, and `child_ids` this
     * adapter's delete path reads (DUO-3342 is what put them there).
     *
     * The reparents channel needs regrouping, and that is the load-bearing
     * half. The engine delivers ONE ROW PER ROOT, which is how a chained
     * A->B->C move survives a row grammar whose fields are scalars; the batch
     * entry point below consumes a single `root_ids` list per entity, falling
     * back to old/new only for receipts that predate it. Collapsing to the
     * old/new pair here would silently drop root A and leave its price/meta
     * lookup stale forever — the exact bug the engine's durable-marker
     * merge exists to prevent.
     *
     * @param array<int,array<string,mixed>> $deletions
     * @param array<int,array<string,mixed>> $reparents
     * @return array<int,array<string,mixed>>
     */
    private function deletion_context_from_channels(array $deletions, array $reparents): array {
        $out = [];
        foreach ($deletions as $row) {
            if (!is_array($row)) {
                continue;
            }
            $childIds = [];
            foreach ((array) ($row['child_ids'] ?? []) as $childId) {
                $childId = (int) $childId;
                if ($childId > 0) {
                    $childIds[$childId] = $childId;
                }
            }
            $childIds = array_values($childIds);
            sort($childIds, SORT_NUMERIC);
            $out[] = [
                'kind' => 'delete',
                'uuid' => (string) ($row['uuid'] ?? ''),
                'id' => (int) ($row['id'] ?? 0),
                'post_type' => (string) ($row['post_type'] ?? ''),
                'parent_id' => (int) ($row['parent_id'] ?? 0),
                'child_ids' => $childIds,
            ];
        }

        $moves = [];
        foreach ($reparents as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uuid = (string) ($row['uuid'] ?? '');
            $id = (int) ($row['id'] ?? 0);
            $key = $uuid !== '' ? 'uuid:' . $uuid : 'id:' . $id;
            $surface = (string) ($row['kind'] ?? '');
            if (!isset($moves[$key])) {
                $moves[$key] = [
                    'kind' => 'reparent',
                    'uuid' => $uuid,
                    'id' => $id,
                    'post_type' => str_starts_with($surface, 'post:')
                        ? substr($surface, strlen('post:'))
                        : '',
                    'parent_id' => (int) ($row['old_parent_id'] ?? 0),
                    'old_parent_id' => (int) ($row['old_parent_id'] ?? 0),
                    'new_parent_id' => (int) ($row['new_parent_id'] ?? 0),
                    'root_ids' => [],
                    'child_ids' => [],
                ];
            }
            $rootId = (int) ($row['root_id'] ?? 0);
            if ($rootId > 0) {
                $moves[$key]['root_ids'][$rootId] = $rootId;
            }
        }
        foreach ($moves as $move) {
            $move['root_ids'] = array_values($move['root_ids']);
            sort($move['root_ids'], SORT_NUMERIC);
            $out[] = $move;
        }
        return $out;
    }

    /**
     * Row cardinality in the supported product-meta lookup for a bounded id set.
     *
     * Batched into `IN (...)` chunks rather than two COUNTs per id (independent
     * review, F7): a receipt is observation, and observation must not cost
     * 4 × |batch| round trips on a catalog-sized apply. The chunk bound keeps
     * the statement well inside any placeholder limit while staying one query
     * per chunk per side. Nothing branches on this observation; exact
     * verification remains the separate gate below.
     *
     * @param array<int,int> $ids
     * @return array{scoped_products:int, meta_lookup_rows:int}
     */
    private function observe_lookup_state(array $ids): array {
        global $wpdb;
        $ids = array_values(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0));
        $metaRows = 0;
        foreach (array_chunk($ids, 200) as $chunk) {
            $placeholders = implode(', ', array_fill(0, count($chunk), '%d'));
            $metaRows += (int) $this->checked_get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::META_LOOKUP
                . " WHERE product_id IN ($placeholders)",
                ...$chunk
            ), 'product lookup receipt observation');
        }
        return [
            'scoped_products' => count($ids),
            'meta_lookup_rows' => $metaRows,
        ];
    }

    /**
     * WordPress database reads return empty-looking values on SQL failure.
     * Every decision-making read in this adapter must distinguish a real
     * empty result from a failed query before a durable receipt can clear.
     */
    private function checked_get_var(string $sql, string $context): mixed {
        global $wpdb;
        $wpdb->last_error = '';
        $value = $wpdb->get_var($sql);
        if ($value === false || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("duo: WooCommerce $context query failed");
        }
        return $value;
    }

    /** @return array<int,mixed> */
    private function checked_get_col(string $sql, string $context): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_col($sql);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("duo: WooCommerce $context query failed");
        }
        return $rows;
    }

    private function checked_get_row(string $sql, string $context): ?array {
        global $wpdb;
        $wpdb->last_error = '';
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (($row !== null && !is_array($row)) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("duo: WooCommerce $context query failed");
        }
        return $row;
    }

    /** @return array<int,array<string,mixed>> */
    private function checked_get_results(string $sql, string $context): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("duo: WooCommerce $context query failed");
        }
        return $rows;
    }

    /**
     * Reconcile all supplied live product ids plus pre-delete/reparent cleanup
     * context. The engine may pass a parent and several variations in any
     * order; roots are deduplicated here so variable products are synthesized
     * once. A `kind=reparent` context refreshes every root in `root_ids`
     * (falling back to old/new ids for older receipts) without treating the
     * still-live variation as deleted.
     *
     * @param array<int,int|string> $liveIds
     * @param array<int,array> $deletionContext
     * @param callable|null $heartbeat Lease-renewal callback. The provider
     *   contract has no heartbeat parameter — \Duo\Providers::invoke() passes a
     *   capability name and typed args and nothing else — so invoke() above
     *   supplies null and the engine brackets the whole call with a lease
     *   renewal instead (Apply::renew_provider_lease()). The parameter and
     *   every call site stay because they are the moved code's own shape and
     *   because a future contract that CAN pass one needs nothing rewritten
     *   here; a null heartbeat is a no-op by construction (heartbeat()).
     */
    public function regenerate_batch(array $liveIds, array $deletionContext, ?callable $heartbeat = null): void {
        global $wpdb;
        $this->assert_runtime_contract();
        // Typed Woo attribute definitions can be applied after Woo's init
        // registration pass. Refresh the public attribute caches and register
        // any newly-created pa_* taxonomies before wc_get_product() parses
        // _product_attributes; otherwise Woo treats a global attribute as a
        // local one for the rest of this apply process, corrupting attribute
        // cache invalidation and product object semantics.
        $registeredAttributeKeys = $this->refresh_attribute_taxonomy_registry();
        $this->heartbeat($heartbeat);

        $liveIds = array_values(array_unique(array_filter(
            array_map('intval', $liveIds),
            static fn(int $id): bool => $id > 0
        )));
        sort($liveIds, SORT_NUMERIC);

        $deletionIds = [];
        $deletedParents = [];
        $reparentedParents = [];
        foreach ($deletionContext as $context) {
            if (($context['kind'] ?? 'delete') === 'reparent') {
                $rootIds = array_map('intval', (array) ($context['root_ids'] ?? []));
                if (!$rootIds) {
                    $rootIds = [
                        (int) ($context['old_parent_id'] ?? $context['parent_id'] ?? 0),
                        (int) ($context['new_parent_id'] ?? 0),
                    ];
                }
                foreach (array_unique($rootIds) as $rootId) {
                    if ($rootId > 0) {
                        $reparentedParents[$rootId] = $rootId;
                    }
                }
                continue;
            }
            $id = (int) ($context['id'] ?? 0);
            if ($id > 0) {
                $deletionIds[$id] = $id;
            }
            foreach ((array) ($context['child_ids'] ?? []) as $childId) {
                $childId = (int) $childId;
                if ($childId > 0) {
                    $deletionIds[$childId] = $childId;
                }
            }
            $parentId = (int) ($context['parent_id'] ?? 0);
            if (($context['post_type'] ?? '') === 'product_variation' && $parentId > 0) {
                $deletedParents[$parentId] = $parentId;
            }
        }

        // Delete the supported product-meta lookup rows first, while no WC
        // object is needed. This works even though the post itself has already
        // been removed by raw SQL.
        if ($deletionIds) {
            $productStore = \WC_Data_Store::load('product');
            foreach ($deletionIds as $id) {
                $this->heartbeat($heartbeat);
                $this->delete_meta_lookup($productStore, $id);
                $this->heartbeat($heartbeat);
            }
        }

        // Invalidate all objects before reading authored prices/attributes.
        // Raw SQL does not run Woo's usual cache invalidation hooks.
        $affectedIds = array_fill_keys($liveIds, true);
        // A deleted variation can still be present in a cached parent
        // object's child list while the parent lookup is being rebuilt. Clear
        // the deleted product instances too, so price/root synthesis sees the
        // post's absence rather than resurrecting derived product state.
        foreach ($deletionIds as $deletedId) {
            $deletedId = (int) $deletedId;
            if ($deletedId > 0) {
                $affectedIds[$deletedId] = true;
            }
        }
        foreach (array_unique(array_merge($deletedParents, $reparentedParents)) as $parentId) {
            $affectedIds[$parentId] = true;
        }
        foreach (array_keys($affectedIds) as $id) {
            $this->heartbeat($heartbeat);
            $this->invalidate_product_caches((int) $id);
        }

        $products = [];
        $variableRoots = [];
        $groupedRoots = [];
        $attributeRoots = [];
        $priceIds = [];

        foreach ($liveIds as $id) {
            $this->heartbeat($heartbeat);
            $product = $this->load_product($id);
            if (!$product) {
                throw new \RuntimeException("duo: WooCommerce product lookup regeneration could not load live product $id");
            }
            $products[$id] = $product;
            $type = (string) $product->get_type();
            if ($type === 'variation') {
                $parentId = (int) $product->get_parent_id('edit');
                // A same-parent variation write can still change the
                // parent's visible-child set (status/catalog visibility) or
                // other cached root inputs. Invalidate the current root
                // before the first wc_get_product(parent), just as the
                // durable reparent path does for old/new roots.
                if ($parentId > 0) {
                    $this->heartbeat($heartbeat);
                    $this->invalidate_product_caches($parentId);
                }
                $parent = $parentId > 0 ? $this->load_product($parentId) : false;
                if ($parent && $this->is_variable($parent)) {
                    $variableRoots[$parentId] = $parent;
                    $attributeRoots[$parentId] = $parent;
                } else {
                    $attributeRoots[$id] = $product;
                }
                $priceIds[$id] = true;
                continue;
            }
            if ($this->is_variable($product)) {
                $variableRoots[$id] = $product;
                $attributeRoots[$id] = $product;
                // sync_price() reads every child _price row.  Recompute all
                // child prices first, including children not in this batch.
                foreach ((array) $product->get_children() as $childId) {
                    $childId = (int) $childId;
                    if ($childId <= 0 || isset($deletionIds[$childId])) {
                        continue;
                    }
                    $this->heartbeat($heartbeat);
                    $this->invalidate_product_caches($childId);
                    $child = $this->load_product($childId);
                    if ($child) {
                        $products[$childId] = $child;
                        $priceIds[$childId] = true;
                    }
                    $this->heartbeat($heartbeat);
                }
            } elseif ($this->is_grouped($product)) {
                $this->collect_grouped_root(
                    $product,
                    $groupedRoots,
                    $variableRoots,
                    $attributeRoots,
                    $products,
                    $priceIds,
                    $deletionIds,
                    $heartbeat
                );
            } else {
                $attributeRoots[$id] = $product;
            }
            $priceIds[$id] = true;
            $this->heartbeat($heartbeat);
        }

        // Grouped products store their child ids in _children rather than a
        // post_parent relation.  There is no reverse public Woo index, so use
        // a targeted meta-key query for each changed/deleted id and validate
        // candidates through the public grouped product object.  This is
        // bounded by the batch, never a catalog-wide WC_Product query.
        $groupedCandidateIds = array_values(array_unique(array_merge(
            $liveIds,
            array_keys($deletionIds),
            array_keys($variableRoots),
            array_keys($deletedParents),
            array_keys($reparentedParents)
        )));
        foreach ($groupedCandidateIds as $childId) {
            $childId = (int) $childId;
            if ($childId <= 0) {
                continue;
            }
            $this->heartbeat($heartbeat);
            foreach ($this->find_grouped_parent_ids($childId) as $parentId) {
                $this->heartbeat($heartbeat);
                $this->invalidate_product_caches($parentId);
                $parent = $this->load_product($parentId);
                if (!$parent || !$this->is_grouped($parent)) {
                    continue;
                }
                $children = array_map('intval', (array) $parent->get_children());
                if (!in_array($childId, $children, true)) {
                    continue;
                }
                $this->collect_grouped_root(
                    $parent,
                    $groupedRoots,
                    $variableRoots,
                    $attributeRoots,
                    $products,
                    $priceIds,
                    $deletionIds,
                    $heartbeat
                );
            }
            $this->heartbeat($heartbeat);
        }

        // A deleted variation, or a variation moved away from an old root,
        // changes its still-live variable parent. Reload each affected root
        // after cache invalidation and deduplicate it with any parent already
        // in the batch. Reparent contexts deliberately do not add the live
        // variation to deletionIds.
        foreach (array_unique(array_merge($deletedParents, $reparentedParents)) as $parentId) {
            $this->heartbeat($heartbeat);
            $parent = $this->load_product($parentId);
            if ($parent && $this->is_variable($parent)) {
                $variableRoots[$parentId] = $parent;
                $attributeRoots[$parentId] = $parent;
                foreach ((array) $parent->get_children() as $childId) {
                    $childId = (int) $childId;
                    if ($childId <= 0 || isset($deletionIds[$childId])) {
                        continue;
                    }
                    $this->heartbeat($heartbeat);
                    $this->invalidate_product_caches($childId);
                    $child = $this->load_product($childId);
                    if ($child) {
                        $products[$childId] = $child;
                        $priceIds[$childId] = true;
                    }
                    $this->heartbeat($heartbeat);
                }
                $priceIds[$parentId] = true;
            }
            $this->heartbeat($heartbeat);
        }

        // WooCommerce's WC_Product_Data_Store_CPT::clear_caches() queues
        // layered-navigation count transients from the product's own
        // attribute keys.  Raw SQL writes do not reach that boundary.  Keep
        // the call synchronous at the adapter boundary (Woo itself flushes
        // the queued keys at shutdown) and include every live product/root we
        // loaded.  A variation's own keys are required by Woo 11.x; its
        // parent is also present in attributeRoots when it is a variable
        // product, so parent-derived lookup changes invalidate the same
        // layered-nav family without guessing attribute names.
        $this->invalidate_attribute_counts(
            array_merge($products, $attributeRoots),
            $deletionIds ? $registeredAttributeKeys : []
        );

        // Ask WooCommerce to decide the active price from its authored
        // regular/sale/date props. WC_Product::get_price('edit') is not an
        // independent derivation in Woo 11 — it reads the stored _price prop —
        // so the public is_on_sale()/regular/sale accessors are the stable
        // business-rule boundary here.
        foreach ($priceIds as $id => $_) {
            if (isset($variableRoots[$id]) || isset($groupedRoots[$id])) {
                continue;
            }
            $this->heartbeat($heartbeat);
            $product = $products[(int) $id] ?? $this->load_product((int) $id);
            if (!$product) {
                throw new \RuntimeException("duo: WooCommerce could not load product $id for public price synthesis");
            }
            $this->sync_simple_price_from_woocommerce($product);
            $this->heartbeat($heartbeat);
        }

        $variableStore = \WC_Data_Store::load('product-variable');
        if (!is_object($variableStore) || !$this->store_has($variableStore, 'sync_price')) {
            throw new \RuntimeException(
                'duo: WooCommerce product-variable data store lacks public sync_price(); '
                . 'the installed WooCommerce version is outside the adapter contract'
            );
        }
        foreach ($variableRoots as $rootId => $root) {
            $this->heartbeat($heartbeat);
            // sync_price() is public in WooCommerce 11.x and updates the
            // parent _price set from all visible child prices. Pass the object
            // by reference as the data-store contract requires. Woo's own
            // implementation also attempts to delete parent regular/sale
            // rows; protect those repository-authored rows at WordPress's
            // public metadata boundary while Woo owns every price decision.
            $this->sync_parent_price_from_woocommerce($variableStore, $root);
            $this->heartbeat($heartbeat);
        }

        if ($groupedRoots) {
            // A variable child's public sync_price() writes its new raw
            // _price, but Woo's product cache controller may deliberately
            // retain the cached product instance for that price-only write.
            // Evict and reload every grouped child after all variable roots
            // have synced, immediately before grouped sync_price() asks Woo
            // for those child objects. Otherwise a grouped root can be
            // synthesized from a stale variable-child price.
            $this->refresh_grouped_children_for_sync(
                $groupedRoots,
                $variableRoots,
                $attributeRoots,
                $products,
                $deletionIds,
                $heartbeat
            );
            $groupedStore = \WC_Data_Store::load('product-grouped');
            if (!is_object($groupedStore) || !$this->store_has($groupedStore, 'sync_price')) {
                throw new \RuntimeException(
                    'duo: WooCommerce product-grouped data store lacks public sync_price(); '
                    . 'the installed WooCommerce version is outside the adapter contract'
                );
            }
            foreach ($groupedRoots as $rootId => $root) {
                $this->heartbeat($heartbeat);
                // sync_price() is the public grouped-product boundary; it
                // derives min/max _price from the current child objects while
                // preserving the grouped root's runtime stock/order fields.
                // It also clears authored regular/sale rows, which must stay
                // target-identical just like variable roots above.
                $this->sync_parent_price_from_woocommerce($groupedStore, $root);
                $this->heartbeat($heartbeat);
            }
        }

        $productStore = \WC_Data_Store::load('product');
        if (!is_object($productStore) || !$this->store_has($productStore, 'refresh_product_lookup_table')) {
            throw new \RuntimeException(
                'duo: WooCommerce product data store lacks public refresh_product_lookup_table(); '
                . 'the installed WooCommerce version is outside the adapter contract'
            );
        }
        foreach (array_keys($priceIds + $variableRoots + $groupedRoots) as $id) {
            $id = (int) $id;
            $this->heartbeat($heartbeat);
            $this->invalidate_product_caches($id);
            $productStore->refresh_product_lookup_table($id);
            $this->heartbeat($heartbeat);
        }

        // Sale actions are operational state derived from the exact sale-date
        // inputs on the affected products. Woo's public helper is
        // product-scoped and idempotently clears/recreates the two Action
        // Scheduler hooks; invoke it only for the bounded live/root/deletion
        // set assembled above, with a lease heartbeat around every call.
        $saleProducts = $products + $variableRoots + $groupedRoots + $attributeRoots;
        $saleIds = [];
        foreach (array_merge(
            array_keys($saleProducts),
            array_keys($deletionIds)
        ) as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $saleIds[$id] = $id;
            }
        }
        ksort($saleIds, SORT_NUMERIC);
        $this->schedule_sale_events(array_values($saleIds), $deletionIds, $saleProducts, $heartbeat);

        $this->verify_exact_state(
            $productStore,
            $products,
            $variableRoots,
            $deletionIds,
            $heartbeat
        );
        $this->verify_sale_schedules(array_values($saleIds), $deletionIds, $saleProducts, $heartbeat);
    }

    /**
     * Re-read grouped roots and children after variable price synthesis. The
     * grouped data store loads children through wc_get_product(), so cache
     * invalidation must happen after product-variable sync_price(), not only
     * while the initial graph is collected.
     */
    private function refresh_grouped_children_for_sync(
        array &$groupedRoots,
        array &$variableRoots,
        array &$attributeRoots,
        array &$products,
        array $deletionIds,
        ?callable $heartbeat = null
    ): void {
        foreach ($groupedRoots as $rootId => $root) {
            $rootId = (int) $rootId;
            if ($rootId <= 0) {
                continue;
            }
            $this->heartbeat($heartbeat);
            $this->invalidate_product_caches($rootId);
            $freshRoot = $this->load_product($rootId);
            if (!$freshRoot || !$this->is_grouped($freshRoot)) {
                throw new \RuntimeException(
                    "duo: grouped product $rootId disappeared before public grouped price synchronization"
                );
            }
            $groupedRoots[$rootId] = $freshRoot;
            $attributeRoots[$rootId] = $freshRoot;
            $products[$rootId] = $freshRoot;
            foreach ((array) $freshRoot->get_children() as $childId) {
                $childId = (int) $childId;
                if ($childId <= 0 || isset($deletionIds[$childId])) {
                    continue;
                }
                $this->heartbeat($heartbeat);
                $this->invalidate_product_caches($childId);
                $child = $this->load_product($childId);
                if ($child) {
                    $products[$childId] = $child;
                    if ($this->is_variable($child)) {
                        $variableRoots[$childId] = $child;
                        $attributeRoots[$childId] = $child;
                    }
                }
                $this->heartbeat($heartbeat);
            }
            $this->heartbeat($heartbeat);
        }
    }

    private function heartbeat(?callable $heartbeat): void {
        if ($heartbeat !== null) {
            $heartbeat();
        }
    }

    /**
     * Reconcile per-product sale actions through Woo's public bounded API.
     * Deleted ids use Action Scheduler's public unschedule boundary because
     * Woo's maybe-schedule helper returns before clearing actions when the
     * product no longer exists.
     *
     * @param list<int> $saleIds
     * @param array<int,int> $deletionIds
     * @param array<int,mixed> $products
     */
    private function schedule_sale_events(
        array $saleIds,
        array $deletionIds,
        array $products,
        ?callable $heartbeat = null
    ): void {
        foreach ($saleIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $this->heartbeat($heartbeat);
            if (isset($deletionIds[$id])) {
                foreach (['wc_product_start_scheduled_sale', 'wc_product_end_scheduled_sale'] as $hook) {
                    $cleared = \as_unschedule_all_actions($hook, ['product_id' => $id], 'woocommerce-sales');
                    if ($cleared === false) {
                        throw new \RuntimeException(
                            "duo: WooCommerce sale-action cleanup failed for deleted product $id"
                        );
                    }
                    $this->heartbeat($heartbeat);
                }
                continue;
            }
            $product = $products[$id] ?? null;
            if (!is_object($product)) {
                $product = \wc_get_product($id);
            }
            if (!is_object($product)) {
                throw new \RuntimeException(
                    "duo: WooCommerce sale scheduling could not load affected product $id"
                );
            }
            \wc_maybe_schedule_product_sale_events($id, $product);
            $this->heartbeat($heartbeat);
        }
    }

    /**
     * Verify both exact hook presence/absence and the timestamp derived from
     * the product's current sale dates. This is intentionally a bounded
     * Action Scheduler read; no catalog-wide scheduled-sale scan is used.
     *
     * @param list<int> $saleIds
     * @param array<int,int> $deletionIds
     * @param array<int,mixed> $products
     */
    private function verify_sale_schedules(
        array $saleIds,
        array $deletionIds,
        array $products,
        ?callable $heartbeat = null
    ): void {
        foreach ($saleIds as $id) {
            $id = (int) $id;
            if ($id <= 0) {
                continue;
            }
            $this->heartbeat($heartbeat);
            $product = isset($deletionIds[$id]) ? false : ($products[$id] ?? null);
            if ($product === null) {
                $product = \wc_get_product($id);
            }
            $expected = [
                'wc_product_start_scheduled_sale' => null,
                'wc_product_end_scheduled_sale' => null,
            ];
            if (is_object($product)) {
                if (!is_callable([$product, 'get_date_on_sale_from'])
                    || !is_callable([$product, 'get_date_on_sale_to'])) {
                    throw new \RuntimeException(
                        "duo: WooCommerce sale scheduling verification API is unavailable for product $id"
                    );
                }
                $expected['wc_product_start_scheduled_sale'] = $this->future_sale_timestamp(
                    $product->get_date_on_sale_from('edit')
                );
                $expected['wc_product_end_scheduled_sale'] = $this->future_sale_timestamp(
                    $product->get_date_on_sale_to('edit')
                );
            } elseif (!isset($deletionIds[$id])) {
                throw new \RuntimeException(
                    "duo: WooCommerce sale scheduling verification could not load affected product $id"
                );
            }

            foreach ($expected as $hook => $timestamp) {
                $actual = \as_next_scheduled_action($hook, ['product_id' => $id], 'woocommerce-sales');
                $this->heartbeat($heartbeat);
                $hasActual = $actual !== false && $actual !== null;
                if ($timestamp === null) {
                    if ($hasActual) {
                        throw new \RuntimeException(
                            "duo: WooCommerce sale schedule verification found unexpected $hook for product $id"
                        );
                    }
                    continue;
                }
                if (!$hasActual || (int) $actual !== $timestamp) {
                    throw new \RuntimeException(
                        "duo: WooCommerce sale schedule verification mismatch for product $id ($hook)"
                    );
                }
            }
        }
    }

    private function future_sale_timestamp(mixed $date): ?int {
        if (!is_object($date) || !is_callable([$date, 'getTimestamp'])) {
            return null;
        }
        $timestamp = (int) $date->getTimestamp();
        return $timestamp > time() ? $timestamp : null;
    }

    /**
     * Invalidate WooCommerce's layered-navigation count transients for every
     * product object participating in this bounded batch.  The public Woo
     * boundary accepts attribute keys (for example `pa_color`), not product
     * ids; preserve those concrete keys so an effect observer can reconcile
     * the exact dynamic transient rather than treating it as a wildcard.
     *
     * @param array<int,mixed> $products
     * @param list<string> $deletionAttributeKeys Registered taxonomy keys used
     *   as a bounded fallback when a deleted product can no longer be loaded.
     */
    private function invalidate_attribute_counts(array $products, array $deletionAttributeKeys = []): void {
        if (!class_exists('WC_Cache_Helper')
            || !is_callable(['WC_Cache_Helper', 'invalidate_attribute_count'])) {
            return;
        }
        $seenProducts = [];
        foreach ($products as $product) {
            if (!is_object($product)
                || !is_callable([$product, 'get_id'])
                || !is_callable([$product, 'get_attributes'])) {
                continue;
            }
            $id = (int) $product->get_id();
            if ($id <= 0 || isset($seenProducts[$id])) {
                continue;
            }
            $seenProducts[$id] = true;
            // This exact Woo 11.x public call is intentionally kept visible
            // to the runtime effect observer. Do not collapse the keys into
            // a generic transient namespace.
            \WC_Cache_Helper::invalidate_attribute_count(array_keys((array) $product->get_attributes()));
        }
        if ($deletionAttributeKeys !== []) {
            // A deleted product has no WC_Product object after raw SQL.  Its
            // former attribute keys are therefore unavailable to this
            // adapter; invalidate the finite, currently registered Woo
            // taxonomy set rather than leaving one potentially stale count
            // alive.  The runtime observer receives each concrete taxonomy
            // key and can reconcile it individually.
            \WC_Cache_Helper::invalidate_attribute_count(array_values(array_unique(array_map(
                'strval',
                $deletionAttributeKeys
            ))));
        }
    }

    /**
     * Let WooCommerce own parent price synthesis while preserving the exact
     * authored metadata boundary declared by this adapter.
     *
     * WooCommerce 11.x's public variable/grouped sync_price() methods derive
     * _price and unconditionally delete parent _regular_price/_sale_price.
     * Those two keys are repository-authored here, so intercept only those
     * exact deletion attempts through WordPress's public metadata filter. This
     * keeps absent, empty, duplicate and multiple row shapes byte-identical
     * without snapshotting, restoring, or copying any Woo price rule. The
     * filter is synchronous and removed even when Woo's call throws. The
     * provider runs the public call in its own database transaction because
     * provider dispatch happens after Apply's authored-state commit; a failed
     * sync must not leave a durable partial derived-price mutation.
     */
    private function sync_parent_price_from_woocommerce(object $store, object $product): void {
        global $wpdb;
        $id = (int) $product->get_id();
        $inTransaction = $this->checked_get_var(
            'SELECT @@in_transaction',
            "transaction-state inspection for product $id"
        );
        if ($inTransaction === null) {
            throw new \RuntimeException(
                "duo: transaction-state inspection returned no value for WooCommerce product $id"
            );
        }
        if ((string) $inTransaction === '1') {
            throw new \RuntimeException(
                "duo: cannot establish an atomic WooCommerce price sync boundary for product $id inside an active transaction"
            );
        }
        if (!is_callable([$wpdb, 'query']) || $wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException(
                "duo: could not start an atomic WooCommerce price sync transaction for product $id"
            );
        }
        $transactionActive = true;
        $preserveAuthoredParentPrice = static function (
            mixed $check,
            int $objectId,
            string $metaKey,
            mixed $_metaValue,
            bool $_deleteAll
        ) use ($id): mixed {
            if ($objectId === $id && in_array($metaKey, ['_regular_price', '_sale_price'], true)) {
                return true;
            }
            return $check;
        };
        add_filter('delete_post_metadata', $preserveAuthoredParentPrice, PHP_INT_MAX, 5);
        try {
            $store->sync_price($product);
            remove_filter('delete_post_metadata', $preserveAuthoredParentPrice, PHP_INT_MAX);
            if ($wpdb->query('COMMIT') === false) {
                throw new \RuntimeException(
                    "duo: could not commit the atomic WooCommerce price sync transaction for product $id"
                );
            }
            $transactionActive = false;
        } catch (\Throwable $failure) {
            remove_filter('delete_post_metadata', $preserveAuthoredParentPrice, PHP_INT_MAX);
            if ($transactionActive && $wpdb->query('ROLLBACK') === false) {
                throw new \RuntimeException(
                    "duo: WooCommerce price sync failed and its transaction could not be rolled back for product $id",
                    0,
                    $failure
                );
            }
            throw $failure;
        } finally {
            remove_filter('delete_post_metadata', $preserveAuthoredParentPrice, PHP_INT_MAX);
            $this->invalidate_product_caches($id);
        }
    }

    private function assert_runtime_contract(): void {
        if (!function_exists('wc_get_product')
            || !class_exists('WC_Data_Store')
            || !class_exists('WC_Product_Variable')
            || !class_exists('WC_Product_Grouped')
            || !function_exists('wc_get_container')
            || !function_exists('add_filter')
            || !function_exists('remove_filter')
            || !function_exists('get_post_meta')
            || !function_exists('delete_post_meta')
            || !function_exists('add_post_meta')
            || !function_exists('wc_maybe_schedule_product_sale_events')
            || !function_exists('as_unschedule_all_actions')
            || !function_exists('as_next_scheduled_action')
            // Meta-lookup verification reads WooCommerce's own derived row out
            // of the object cache Woo writes it to; without the cache API
            // there is no channel through which Woo can state what it derived,
            // and this adapter refuses rather than re-deriving it itself.
            || !function_exists('wp_cache_get')
            || !function_exists('wp_cache_delete')) {
            throw new \RuntimeException(
                'duo: WooCommerce product lookup regenerator requires WooCommerce 11.x public data-store and sale-schedule APIs'
            );
        }
    }

    /**
     * Load the public Woo product class implied by authored source shape.
     *
     * Woo stores `product_type` as a derived taxonomy relationship. On a
     * fresh target that row does not exist until Woo hooks run, but Duo must
     * rebuild projections before its receipt can advance. Variation children
     * and grouped `_children` metadata are authored, deterministic evidence
     * for the only two parent classes whose lookup synthesis differs from a
     * simple product. Construct the public class in memory; never persist a
     * guessed product_type relationship.
     */
    private function load_product(int $id): object|false {
        global $wpdb;
        $product = \wc_get_product($id);
        if (!$product || !is_callable([$product, 'is_type']) || !$product->is_type('simple')) {
            return $product;
        }

        $postType = $this->checked_get_var($wpdb->prepare(
            "SELECT post_type FROM {$wpdb->posts} WHERE ID = %d LIMIT 1",
            $id
        ), "product source-shape read for product $id");
        if ((string) $postType !== 'product') {
            return $product;
        }

        $variationId = $this->checked_get_var($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_parent = %d AND post_type = 'product_variation' ORDER BY ID LIMIT 1",
            $id
        ), "variation-child discovery for product $id");
        if ($variationId !== null) {
            return new \WC_Product_Variable($id);
        }

        $children = get_post_meta($id, '_children', true);
        if (is_array($children) && array_filter(
            array_map('intval', $children),
            static fn(int $childId): bool => $childId > 0
        ) !== []) {
            return new \WC_Product_Grouped($id);
        }

        return $product;
    }

    /**
     * Make Woo's runtime taxonomy registry reflect definitions applied after
     * plugin init. WooCommerce itself registers pa_* taxonomies during init
     * from wc_get_attribute_taxonomies(); Duo's typed-table apply can land a
     * new definition later in the same request. The public cache invalidators
     * plus register_taxonomy() restore the same product/cache callback
     * contract for the remainder of this bounded product repair.
     */
    /** @return list<string> registered Woo attribute taxonomy names */
    private function refresh_attribute_taxonomy_registry(): array {
        if (!function_exists('wc_get_attribute_taxonomies')
            || !function_exists('wc_attribute_taxonomy_name')
            || !function_exists('taxonomy_exists')
            || !function_exists('register_taxonomy')) {
            return [];
        }

        if (function_exists('delete_transient')) {
            delete_transient('wc_attribute_taxonomies');
        }
        $cacheHelperClass = '\\WC_Cache_Helper';
        if (class_exists($cacheHelperClass)
            && is_callable([$cacheHelperClass, 'invalidate_cache_group'])) {
            \WC_Cache_Helper::invalidate_cache_group('woocommerce-attributes');
        }

        $attributes = (array) wc_get_attribute_taxonomies();
        $registeredTaxonomies = [];
        global $wc_product_attributes;
        if (!is_array($wc_product_attributes)) {
            $wc_product_attributes = [];
        }
        foreach ($attributes as $attribute) {
            $attributeName = is_object($attribute)
                ? (string) ($attribute->attribute_name ?? '')
                : (string) ($attribute['attribute_name'] ?? '');
            if ($attributeName === '') {
                continue;
            }
            $taxonomy = (string) wc_attribute_taxonomy_name($attributeName);
            if ($taxonomy === '') {
                continue;
            }
            if (!taxonomy_exists($taxonomy)) {
                $registered = register_taxonomy($taxonomy, ['product'], [
                    'hierarchical' => false,
                    'update_count_callback' => '_update_post_term_count',
                ]);
                if (is_wp_error($registered) || !taxonomy_exists($taxonomy)) {
                    throw new \RuntimeException(
                        "duo: WooCommerce attribute taxonomy '$taxonomy' could not be registered for product-object reconciliation"
                    );
                }
            }
            // WC_Product_Attribute::get_taxonomy_object() reads this global;
            // keep it coherent when the definition was created post-init.
            $wc_product_attributes[$taxonomy] = $attribute;
            $registeredTaxonomies[$taxonomy] = $taxonomy;
        }
        return array_values($registeredTaxonomies);
    }

    /**
     * Ask a WooCommerce data store whether it really implements a method.
     *
     * WC_Data_Store is a proxy that defines __call(), so is_callable() on one
     * of its instances is true for every name and proves nothing about the
     * store it wraps — a version-compatibility guard written that way passes
     * right up to the fatal. WooCommerce exposes has_callable() for exactly
     * this question and asks the wrapped instance. The is_callable() fallback
     * is for the plugin objects reached outside the data-store registry (the
     * ProductAttributesLookup store, WC_Product instances), which are ordinary
     * objects where it is a real check.
     */
    private function store_has(object $store, string $method): bool {
        return is_callable([$store, 'has_callable'])
            ? (bool) $store->has_callable($method)
            : is_callable([$store, $method]);
    }

    private function delete_meta_lookup(object $store, int $id): void {
        if (!$this->store_has($store, 'delete_from_lookup_table')) {
            throw new \RuntimeException(
                'duo: WooCommerce product data store lacks delete_from_lookup_table(); cannot clean deleted lookup rows'
            );
        }
        $store->delete_from_lookup_table($id, self::META_LOOKUP);
    }

    private function invalidate_product_caches(int $id): void {
        if ($id <= 0) {
            return;
        }
        if (function_exists('clean_post_cache')) {
            clean_post_cache($id);
        }
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($id);
        }
        if (class_exists('WC_Cache_Helper')
            && is_callable(['WC_Cache_Helper', 'invalidate_cache_group'])) {
            \WC_Cache_Helper::invalidate_cache_group('product_' . $id);
        }
        // WC_Product_Data_Store_CPT::update_lookup_table() keeps its own
        // derived-row snapshot in this object-scoped cache and otherwise
        // may decide a stale row is already current.
        if (function_exists('wp_cache_delete')) {
            wp_cache_delete('lookup_table', 'object_' . $id);
        }
        // WooCommerce 11.x's product-instance cache can return a stale
        // WC_Product (especially a variable parent with an old child list)
        // even after the ordinary WP/transient/group invalidators above.
        // Match WC_Product_Data_Store_CPT::clear_caches(): remove this object
        // last, after operations that may themselves re-cache it.
        $productCacheClass = '\\Automattic\\WooCommerce\\Internal\\Caches\\ProductCache';
        if (class_exists($productCacheClass) && function_exists('wc_get_container')) {
            try {
                $productCache = \wc_get_container()->get($productCacheClass);
            } catch (\Throwable $t) {
                throw new \RuntimeException('duo: failed to load WooCommerce ProductCache for lookup regeneration', 0, $t);
            }
            if (!is_object($productCache) || !is_callable([$productCache, 'remove'])) {
                throw new \RuntimeException(
                    'duo: WooCommerce ProductCache lacks public remove(); cannot safely read product lookup inputs'
                );
            }
            $productCache->remove($id);
        }
    }

    private function is_variable(object $product): bool {
        return is_callable([$product, 'is_type']) && $product->is_type('variable');
    }

    private function is_grouped(object $product): bool {
        return is_callable([$product, 'is_type']) && $product->is_type('grouped');
    }

    /**
     * Find grouped roots that reference one changed/deleted child.  Grouped
     * membership is serialized in _children, so this deliberately uses a
     * narrow meta-key/value lookup instead of loading every product.
     *
     * @return array<int,int>
     */
    private function find_grouped_parent_ids(int $childId): array {
        global $wpdb;
        if ($childId <= 0) {
            return [];
        }
        $integerNeedle = '%i:' . $childId . ';%';
        $stringNeedle = '%s:' . strlen((string) $childId) . ':"' . $childId . '";%';
        $rows = $this->checked_get_col($wpdb->prepare(
            "SELECT DISTINCT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_children'
               AND p.post_type = 'product'
               AND (pm.meta_value LIKE %s OR pm.meta_value LIKE %s)",
            $integerNeedle,
            $stringNeedle
        ), 'grouped parent discovery');
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) $row;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    private function collect_grouped_root(
        object $root,
        array &$groupedRoots,
        array &$variableRoots,
        array &$attributeRoots,
        array &$products,
        array &$priceIds,
        array $deletionIds,
        ?callable $heartbeat = null
    ): void {
        $rootId = (int) $root->get_id();
        if ($rootId <= 0) {
            return;
        }
        $groupedRoots[$rootId] = $root;
        $attributeRoots[$rootId] = $root;
        $products[$rootId] = $root;
        $priceIds[$rootId] = true;
        foreach ((array) $root->get_children() as $childId) {
            $childId = (int) $childId;
            if ($childId <= 0 || isset($deletionIds[$childId])) {
                continue;
            }
            $this->heartbeat($heartbeat);
            $this->invalidate_product_caches($childId);
            $child = $this->load_product($childId);
            if ($child) {
                $products[$childId] = $child;
                $priceIds[$childId] = true;
                if ($this->is_variable($child)) {
                    $variableRoots[$childId] = $child;
                    $attributeRoots[$childId] = $child;
                    // A grouped child may itself be a variable product. Its
                    // variation prices must be repaired before the grouped
                    // store reads the variable root's effective price.
                    foreach ((array) $child->get_children() as $variationId) {
                        $variationId = (int) $variationId;
                        if ($variationId <= 0 || isset($deletionIds[$variationId])) {
                            continue;
                        }
                        $this->heartbeat($heartbeat);
                        $this->invalidate_product_caches($variationId);
                        $variation = $this->load_product($variationId);
                        if ($variation) {
                            $products[$variationId] = $variation;
                            $priceIds[$variationId] = true;
                        }
                        $this->heartbeat($heartbeat);
                    }
                }
            }
            $this->heartbeat($heartbeat);
        }
    }

    /**
     * Persist the active price WooCommerce derives for one simple product or
     * variation without invoking WC_Product::save().
     *
     * is_on_sale('edit') owns Woo's comparison/date behavior; the regular and
     * sale accessors own the selected authored value. The adapter only
     * materializes that public result into Woo's derived _price key through
     * WordPress metadata APIs. save() is deliberately not the boundary: after
     * raw apply the freshly loaded object has no dirty price props, and a
     * forced save also fires asynchronous lookup hooks.
     */
    private function sync_simple_price_from_woocommerce(object $product): void {
        $id = (int) $product->get_id();
        foreach (['is_on_sale', 'get_sale_price', 'get_regular_price'] as $method) {
            if (!is_callable([$product, $method])) {
                throw new \RuntimeException(
                    "duo: WooCommerce product $id lacks public $method(); the installed version is outside the adapter contract"
                );
            }
        }
        $price = $product->is_on_sale('edit')
            ? (string) $product->get_sale_price('edit')
            : (string) $product->get_regular_price('edit');

        if (!delete_post_meta($id, '_price')) {
            $remaining = (array) get_post_meta($id, '_price', false);
            if ($remaining !== []) {
                throw new \RuntimeException("duo: failed to clear stale WooCommerce _price meta for product $id");
            }
        }
        if ($price !== '' && !add_post_meta($id, '_price', $price, false)) {
            throw new \RuntimeException("duo: failed to write WooCommerce-derived _price meta for product $id");
        }
        $this->invalidate_product_caches($id);
    }

    /**
     * Verify lookup values rather than merely checking that a row exists.
     * The Woo data-store calls above are the source of truth for synthesis;
     * these checks catch stale/partial writes and preserve runtime fields by
     * comparing them to target-local postmeta, not repository-authored data.
     */
    private function verify_exact_state(
        object $productStore,
        array $products,
        array $variableRoots,
        array $deletionIds,
        ?callable $heartbeat = null
    ): void {
        global $wpdb;
        foreach ($deletionIds as $id) {
            $this->heartbeat($heartbeat);
            $metaCountValue = $this->checked_get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::META_LOOKUP . " WHERE product_id = %d",
                (int) $id
            ), "product lookup deletion verification for product $id");
            if ($metaCountValue === null) {
                throw new \RuntimeException(
                    "duo: WooCommerce product lookup deletion verification returned no count for product $id"
                );
            }
            $metaCount = (int) $metaCountValue;
            if ($metaCount !== 0) {
                throw new \RuntimeException(
                    "duo: WooCommerce product lookup deletion verification failed for product $id "
                    . "(meta rows=$metaCount)"
                );
            }
            $this->heartbeat($heartbeat);
        }

        foreach ($products as $id => $product) {
            $id = (int) $id;
            $this->heartbeat($heartbeat);
            $this->verify_meta_row($productStore, $id, $heartbeat);
        }
        foreach ($variableRoots as $id => $root) {
            $id = (int) $id;
            $this->heartbeat($heartbeat);
            $this->verify_meta_row($productStore, $id, $heartbeat);
            foreach ((array) $root->get_children() as $childId) {
                $childId = (int) $childId;
                if ($childId > 0 && !isset($deletionIds[$childId])) {
                    $this->heartbeat($heartbeat);
                    $this->verify_meta_row($productStore, $childId, $heartbeat);
                }
            }
        }
    }

    /**
     * Prove one wc_product_meta_lookup row is what WooCommerce derives for
     * this product, on both axes that can independently go wrong.
     *
     * Two reads bracket one forced re-derivation, and each proves a different
     * thing:
     *
     *   1. The row the apply left behind is snapshotted BEFORE the refresh and
     *      compared back afterwards, over the columns Woo derives. This is the
     *      check that says "the apply converged this row". It has to be taken
     *      first: the refresh below
     *      runs with a cleared cache, so WC_Data_Store_WP::update_lookup_table()
     *      always REPLACEs, and a readback taken only afterwards would be
     *      reading a row this verification had itself just written — passing
     *      for a row the apply left divergent. That is reachable rather than
     *      theoretical: sibling variations of a variable root reached through
     *      the variation path are verified here but are not in the batch's own
     *      refresh set above.
     *
     *   2. The derivation WooCommerce published is compared against what is
     *      actually stored after the refresh. update_lookup_table() does not
     *      check $wpdb->replace()'s return value and sets its cache
     *      unconditionally, so a REPLACE that failed leaves Woo advertising a
     *      row that never landed. This is the check that says "Woo's write
     *      landed".
     *
     * Neither comparison rebuilds Woo's column rules, which is the point. This
     * adapter used to: the sku / virtual / onsale / stock / rating / tax column
     * set, the Cost of Goods Sold feature gate, the woocommerce_schema_version
     * >= 920 global_unique_id gate, and a per-column tolerance table for
     * reading DECIMAL/BIGINT columns back were a hand-copy of
     * WC_Product_Data_Store_CPT::get_data_for_lookup_table(), a protected
     * method whose shape this adapter is not entitled to depend on. That copy
     * could only drift one of two ways against a Woo point release: silently
     * under-verifying a column it never learned about, or failing an apply over
     * a gate Woo had since changed.
     *
     * A failure here leaves the row itself repaired — the refresh already ran —
     * while the apply fails, which is the same self-healing-on-retry shape the
     * engine's regen_pending markers already assume.
     */
    private function verify_meta_row(object $productStore, int $id, ?callable $heartbeat = null): void {
        global $wpdb;
        $table = $wpdb->prefix . self::META_LOOKUP;

        $applied = $this->read_lookup_row($table, $id);
        if ($applied === null) {
            throw new \RuntimeException("duo: WooCommerce product lookup row missing for product $id");
        }
        $countValue = $this->checked_get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$table` WHERE product_id = %d",
            $id
        ), "product lookup cardinality verification for product $id");
        if ($countValue === null) {
            throw new \RuntimeException(
                "duo: WooCommerce product lookup cardinality returned no count for product $id"
            );
        }
        $count = (int) $countValue;
        if ($count !== 1) {
            throw new \RuntimeException("duo: WooCommerce product lookup has $count rows for product $id; expected exactly one");
        }

        $derived = $this->woo_republished_lookup_row($productStore, $id);
        $this->heartbeat($heartbeat);
        $stored = $this->read_lookup_row($table, $id);
        if ($stored === null) {
            throw new \RuntimeException(
                "duo: WooCommerce product lookup row for product $id disappeared during its own refresh"
            );
        }

        // Scoped to the columns WooCommerce actually derived, deliberately.
        // update_lookup_table() writes with $wpdb->replace(), which is a
        // DELETE plus INSERT, so any column OUTSIDE the derived set — a
        // cogs_total_value still holding data while the COGS feature is off, a
        // column some other extension maintains — comes back at its schema
        // default whatever the apply did. Comparing those would turn Woo's own
        // write into a refusal blaming the apply for a divergence it did not
        // cause. They are outside this check's authority; the derivation-bound
        // check below already covers exactly the set Woo does own.
        $this->assert_lookup_row_matches(
            $id,
            $table,
            $stored,
            array_intersect_key($applied, $derived),
            'the apply left this WooCommerce product lookup row divergent from what WooCommerce derives'
        );
        $this->assert_lookup_row_matches(
            $id,
            $table,
            $stored,
            $derived,
            'the WooCommerce product lookup write did not land the values WooCommerce derived'
        );
    }

    /** @return array<string,mixed>|null */
    private function read_lookup_row(string $table, int $id): ?array {
        global $wpdb;
        $row = $this->checked_get_row($wpdb->prepare(
            "SELECT * FROM `$table` WHERE product_id = %d LIMIT 1",
            $id
        ), "product lookup verification for product $id");
        return is_array($row) ? $row : null;
    }

    /**
     * Make WooCommerce re-derive this product's lookup row, and return the
     * derivation it published while doing so.
     *
     * get_data_for_lookup_table() is protected, so its result cannot be asked
     * for directly. It is observable through Woo's own public write path
     * instead: update_lookup_table() stores the row it is about to write under
     * the `lookup_table`/`object_<id>` cache key, and skips both the write and
     * that cache set only when the cache already equals the fresh derivation.
     *
     * Both cache assertions are the mechanism, not defensive noise. A surviving
     * entry means the refresh would short-circuit, so the caller's before/after
     * pair would compare a row with itself and prove nothing; an absent entry
     * afterwards means Woo published no derivation to check the write against.
     * Either one silently empties this verification, so both refuse.
     *
     * The refresh is a deliberate cost: one extra REPLACE per verified product
     * on top of the batch's own refresh pass, over a set bounded by this
     * apply's authored work with a lease heartbeat around every product.
     *
     * @return array<string,mixed>
     */
    private function woo_republished_lookup_row(object $productStore, int $id): array {
        $this->invalidate_product_caches($id);
        if (wp_cache_get('lookup_table', 'object_' . $id) !== false) {
            throw new \RuntimeException(
                "duo: WooCommerce's product lookup derivation cache survived invalidation for product $id; "
                . 'the refresh would short-circuit and the verification below would prove nothing'
            );
        }
        $productStore->refresh_product_lookup_table($id);
        $derived = wp_cache_get('lookup_table', 'object_' . $id);
        if (!is_array($derived) || $derived === []) {
            throw new \RuntimeException(
                "duo: WooCommerce published no product lookup derivation for product $id after a public "
                . 'refresh with a cleared cache; the installed WooCommerce version is outside the adapter contract'
            );
        }
        return $derived;
    }

    /**
     * Compare one set of expected values against the stored row in the
     * database rather than in PHP.
     *
     * The two callers supply values of different provenance — a previous read
     * of this same row, and Woo's PHP-typed derivation (ints, floats, nulls,
     * unformatted decimal strings) — and neither can be compared to a wpdb row
     * of strings and NULLs without a normalization rule. Writing that rule per
     * column is precisely the copied WooCommerce semantics this adapter stopped
     * carrying, so one NULL-safe `<=>` predicate per column delegates it to the
     * column's own type, which is the same coercion $wpdb->replace() applied on
     * the way in.
     *
     * Equality here is therefore the column's, not PHP's: a varchar comparison
     * follows the collation, so on a PAD SPACE collation two skus differing
     * only in trailing spaces would compare equal. That is unreachable through
     * this path — every value bound here was either read from that same column
     * or written to it by Woo — and it is the right definition of "equal" for a
     * table whose only consumers are SQL queries against these columns.
     *
     * An expected column the stored row does not have is a refusal, not a skip.
     * The old row-keyed loop skipped unknown columns, which is what let a
     * schema and an installed WooCommerce disagree without anyone noticing.
     *
     * @param array<string,mixed> $stored the row as currently stored
     * @param array<string,mixed> $expected values that row must already equal
     * @param string $failure what a mismatch means, in the caller's terms
     */
    private function assert_lookup_row_matches(
        int $id,
        string $table,
        array $stored,
        array $expected,
        string $failure
    ): void {
        global $wpdb;
        $conditions = ['product_id = %d'];
        $params = [$id];
        foreach ($expected as $column => $value) {
            $column = (string) $column;
            if (preg_match('/^[a-z][a-z0-9_]{0,62}$/D', $column) !== 1) {
                throw new \RuntimeException(
                    "duo: unusable WooCommerce product lookup column name '$column' for product $id"
                );
            }
            if (!array_key_exists($column, $stored)) {
                throw new \RuntimeException(
                    "duo: WooCommerce product lookup column '$column' for product $id is absent from $table; "
                    . 'the lookup schema and the installed WooCommerce disagree'
                );
            }
            if ($value === null) {
                $conditions[] = "`$column` IS NULL";
                continue;
            }
            if (!is_scalar($value)) {
                throw new \RuntimeException(
                    "duo: non-scalar WooCommerce product lookup value for product $id.$column"
                );
            }
            $conditions[] = "`$column` <=> %s";
            // (string) is how $wpdb->replace() bound this same value on the
            // way in, so ints, floats and false reach MySQL identically here.
            $params[] = (string) $value;
        }
        $matches = $this->checked_get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$table` WHERE " . implode(' AND ', $conditions),
            ...$params
        ), "product lookup value verification for product $id");
        if ($matches === null) {
            throw new \RuntimeException(
                "duo: WooCommerce product lookup value verification returned no count for product $id"
            );
        }
        if ((int) $matches === 1) {
            return;
        }
        throw new \RuntimeException(
            "duo: WooCommerce product lookup verification mismatch for product $id — $failure "
            . '(expected ' . var_export($expected, true)
            . ', stored ' . var_export($stored, true) . ')'
        );
    }

}
