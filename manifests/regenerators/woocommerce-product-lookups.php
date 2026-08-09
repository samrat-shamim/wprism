<?php
namespace Duo\Regenerators;

use Duo\Policy;

/**
 * WooCommerce 11.x product lookup adapter.
 *
 * Duo writes product posts and postmeta with SQL, intentionally bypassing the
 * save hooks Woo normally uses to maintain its two product lookup tables.
 * This adapter is the manifest-owned boundary for repairing that derived
 * state synchronously.  It deliberately does not call on_product_changed():
 * Woo's public hook-facing method schedules Action Scheduler work by default,
 * which would leave a successful Duo apply with a pending, non-deterministic
 * lookup update.  The public data-store methods used here are synchronous.
 *
 * Dispatch note: this file is reached through the engine's regenerator channel
 * (Apply::regen_batch_dependencies()), not the DUO-3338 provider contract, and
 * that is currently forced rather than chosen. A provider would bring identity
 * binding, negotiation before the first target mutation, and receipts — but a
 * capability's arguments are limited to bool/int/string/list<string>
 * (Providers::ARG_TYPES), and its engine-assembled entity batch carries only
 * created/updated entities, so the deletion and reparent context this adapter
 * requires cannot be expressed at all. Closing that structured-argument gap is
 * DUO-3369; until then, migrating the channel would trade a real capability
 * for the identity binding.
 */
final class WoocommerceProductLookups {
    private Policy $policy;

    private const META_LOOKUP = 'wc_product_meta_lookup';
    private const ATTR_LOOKUP = 'wc_product_attributes_lookup';

    public function __construct(Policy $policy) {
        $this->policy = $policy;
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

    /** Backward-compatible single-id boundary for generic callers/tests. */
    public function regenerate(int $localId): void {
        $this->regenerate_batch([$localId], []);
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
     * @param callable|null $heartbeat Lease-renewal callback supplied by the
     *   generic apply engine; invoked around bounded and potentially large
     *   plugin-owned loops.
     */
    public function regenerate_batch(array $liveIds, array $deletionContext, ?callable $heartbeat = null): void {
        global $wpdb;
        $this->assert_runtime_contract();
        // Typed Woo attribute definitions can be applied after Woo's init
        // registration pass. Refresh the public attribute caches and register
        // any newly-created pa_* taxonomies before wc_get_product() parses
        // _product_attributes; otherwise Woo treats a global attribute as a
        // local one for the rest of this apply process and synthesizes no
        // attribute lookup rows.
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

        // Delete lookup rows first, while no WC object is needed.  This is
        // the synchronous equivalent of Woo's ACTION_DELETE path and works
        // even though the post itself has already been removed by raw SQL.
        if ($deletionIds) {
            $productStore = \WC_Data_Store::load('product');
            $attributeStore = $this->attribute_lookup_store();
            foreach ($deletionIds as $id) {
                $this->heartbeat($heartbeat);
                $this->delete_meta_lookup($productStore, $id);
                $this->delete_attribute_lookup($attributeStore, $id);
                $this->heartbeat($heartbeat);
            }
        }

        // Invalidate all objects before reading authored prices/attributes.
        // Raw SQL does not run Woo's usual cache invalidation hooks.
        $affectedIds = array_fill_keys($liveIds, true);
        // A deleted variation can still be present in a cached parent
        // object's child list while the parent lookup is being rebuilt. Clear
        // the deleted product instances too, so attribute regeneration sees
        // the post's absence rather than resurrecting its derived row.
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

        // Recompute the derived _price meta from authored regular/sale/date
        // inputs.  WC_Product::get_price('edit') is not an independent
        // derivation in Woo 11 — it reads the stored _price prop — so using it
        // here would preserve exactly the stale value this adapter exists to
        // repair.
        foreach ($priceIds as $id => $_) {
            if (isset($variableRoots[$id]) || isset($groupedRoots[$id])) {
                continue;
            }
            $this->heartbeat($heartbeat);
            $this->recompute_simple_price((int) $id);
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
            // parent _price set from all visible child prices.  Pass the
            // object by reference as the data-store contract requires. Woo's
            // public implementation also clears the parent's authored
            // regular/sale rows as an internal invariant. Those rows remain
            // Duo-authored state (and can be present in a real captured
            // catalog), so snapshot and restore their exact row shape even
            // when the public call fails part-way through.
            $this->sync_price_preserving_authored_meta($variableStore, $root);
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
                $this->sync_price_preserving_authored_meta($groupedStore, $root);
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

        // ProductAttributesLookup\LookupDataStore::create_data_for_product()
        // is synchronous.  Calling on_product_changed() here would enqueue
        // Action Scheduler work and make apply's convergence boundary false.
        $attributeStore = $this->attribute_lookup_store();
        $this->with_all_attribute_languages(function () use ($attributeRoots, $attributeStore, $heartbeat): void {
            foreach ($attributeRoots as $rootId => $root) {
                $this->heartbeat($heartbeat);
                // Pass the already-classified object. A fresh Duo target does
                // not yet have Woo's derived product_type relationship, so an
                // id-only call would make Woo reload a variable root as a
                // simple product and omit every variation row.
                $attributeStore->create_data_for_product($root, false);
                if (is_callable([$attributeStore, 'get_last_create_operation_failed'])
                    && $attributeStore->get_last_create_operation_failed()) {
                    throw new \RuntimeException(
                        "duo: WooCommerce product attributes lookup generation reported failure for product $rootId"
                    );
                }
                $this->heartbeat($heartbeat);
            }
        });

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

        $this->verify_exact_state($productStore, $products, $variableRoots, $attributeRoots, $deletionIds, $heartbeat);
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
     * Run a public Woo price synthesis call without erasing authored parent
     * price metadata. WooCommerce 11.x's variable and grouped stores both
     * delete _regular_price and _sale_price while deriving _price. Capture
     * every row (including an absent key, an explicitly empty row, and
     * duplicate/multiple rows) and restore the same rows before committing
     * the boundary so a failed public call is safe to retry as well.
     *
     * The snapshot/restore/transaction wrapper is Duo-native and stays that
     * way for now: WooCommerce owns the price synthesis (sync_price() below is
     * its public call) but owns no notion of preserving a parent's authored
     * rows across it, because in Woo's own flows those rows are not authored
     * state. Narrowing this is a separate parity slice (DUO-3342 continuation);
     * it is drift risk, not a divergence from any Woo rule.
     */
    private function sync_price_preserving_authored_meta(object $store, object $product): void {
        $id = (int) $product->get_id();
        $transactionStarted = false;
        try {
            $this->start_price_sync_transaction($id);
            $transactionStarted = true;
            $authored = [
                '_regular_price' => (array) get_post_meta($id, '_regular_price', false),
                '_sale_price' => (array) get_post_meta($id, '_sale_price', false),
            ];
            $syncFailure = null;
            try {
                $store->sync_price($product);
            } catch (\Throwable $t) {
                $syncFailure = $t;
            }

            try {
                $this->restore_authored_price_meta($id, $authored);
            } catch (\Throwable $restoreFailure) {
                $rollbackFailure = $this->rollback_price_sync_transaction($id, $transactionStarted);
                if ($rollbackFailure !== null) {
                    throw new \RuntimeException(
                        "duo: WooCommerce price sync failed and its authored metadata rollback failed for product $id",
                        0,
                        $rollbackFailure
                    );
                }
                $this->invalidate_product_caches($id);
                if ($syncFailure !== null) {
                    throw new \RuntimeException(
                        "duo: failed to restore authored WooCommerce price metadata for product $id after sync failure",
                        0,
                        $restoreFailure
                    );
                }
                throw $restoreFailure;
            }
            if ($syncFailure !== null) {
                $rollbackFailure = $this->rollback_price_sync_transaction($id, $transactionStarted);
                if ($rollbackFailure !== null) {
                    throw new \RuntimeException(
                        "duo: WooCommerce price sync rollback failed for product $id",
                        0,
                        $rollbackFailure
                    );
                }
                $this->invalidate_product_caches($id);
                throw $syncFailure;
            }

            try {
                $this->commit_price_sync_transaction($id, $transactionStarted);
            } catch (\Throwable $commitFailure) {
                $rollbackFailure = $this->rollback_price_sync_transaction($id, $transactionStarted);
                if ($rollbackFailure !== null) {
                    throw new \RuntimeException(
                        "duo: WooCommerce price sync commit and rollback failed for product $id",
                        0,
                        $rollbackFailure
                    );
                }
                $this->invalidate_product_caches($id);
                throw $commitFailure;
            }
            $this->invalidate_product_caches($id);
        } catch (\Throwable $failure) {
            if ($transactionStarted) {
                $rollbackFailure = $this->rollback_price_sync_transaction($id, $transactionStarted);
                $this->invalidate_product_caches($id);
                if ($rollbackFailure !== null) {
                    throw new \RuntimeException(
                        "duo: WooCommerce price sync transaction failed for product $id and could not be rolled back",
                        0,
                        $rollbackFailure
                    );
                }
            }
            throw $failure;
        }
    }

    private function start_price_sync_transaction(int $id): void {
        global $wpdb;
        if (is_callable([$wpdb, 'get_var'])) {
            $inTransaction = $this->checked_get_var(
                'SELECT @@in_transaction',
                "transaction-state inspection for product $id"
            );
            if ($inTransaction === null) {
                throw new \RuntimeException(
                    "duo: could not inspect transaction state before WooCommerce price sync for product $id"
                );
            }
            if ((string) $inTransaction === '1') {
                throw new \RuntimeException(
                    "duo: cannot establish an all-or-nothing WooCommerce price sync boundary for product $id inside an active transaction"
                );
            }
        }
        if (!is_callable([$wpdb, 'query']) || $wpdb->query('START TRANSACTION') === false) {
            throw new \RuntimeException(
                "duo: could not start an all-or-nothing WooCommerce price sync transaction for product $id"
            );
        }
    }

    private function commit_price_sync_transaction(int $id, bool &$transactionStarted): void {
        global $wpdb;
        if (!$transactionStarted) {
            return;
        }
        if ($wpdb->query('COMMIT') === false) {
            throw new \RuntimeException(
                "duo: could not commit the all-or-nothing WooCommerce price sync transaction for product $id"
            );
        }
        $transactionStarted = false;
    }

    private function rollback_price_sync_transaction(int $id, bool &$transactionStarted): ?\Throwable {
        global $wpdb;
        if (!$transactionStarted) {
            return null;
        }
        if (!is_callable([$wpdb, 'query']) || $wpdb->query('ROLLBACK') === false) {
            return new \RuntimeException(
                "duo: could not roll back the all-or-nothing WooCommerce price sync transaction for product $id"
            );
        }
        $transactionStarted = false;
        return null;
    }

    /** @param array<string,array<int,mixed>> $authored */
    private function restore_authored_price_meta(int $id, array $authored): void {
        foreach (['_regular_price', '_sale_price'] as $key) {
            if (function_exists('delete_post_meta') && function_exists('add_post_meta')) {
                if (!delete_post_meta($id, $key)) {
                    // WordPress returns false when there were no rows. An
                    // absent snapshot is already converged in that case.
                    $existing = (array) get_post_meta($id, $key, false);
                    if ($existing) {
                        throw new \RuntimeException(
                            "duo: failed to clear WooCommerce authored $key metadata for product $id"
                        );
                    }
                }
                foreach ((array) ($authored[$key] ?? []) as $value) {
                    if (!add_post_meta($id, $key, $value, false)) {
                        throw new \RuntimeException(
                            "duo: failed to restore WooCommerce authored $key metadata for product $id"
                        );
                    }
                }
                continue;
            }
            throw new \RuntimeException(
                'duo: WordPress metadata APIs are unavailable; cannot preserve authored WooCommerce price metadata'
            );
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
     * Keep third-party language filters from dropping valid attribute terms.
     *
     * Polylang treats an explicit empty `lang` query arg as all languages.
     * WordPress and WooCommerce safely ignore that otherwise-unknown arg.
     * Scope the filter to pa_* queries made by Woo's public lookup store and
     * always remove it, including when public regeneration throws.
     */
    private function with_all_attribute_languages(callable $callback): mixed {
        $filter = static function (array $args, array $taxonomies): array {
            foreach ($taxonomies as $taxonomy) {
                if (str_starts_with((string) $taxonomy, 'pa_')) {
                    $args['lang'] = '';
                    break;
                }
            }
            return $args;
        };
        add_filter('get_terms_args', $filter, 1, 2);
        try {
            return $callback();
        } finally {
            remove_filter('get_terms_args', $filter, 1);
        }
    }

    /**
     * Make Woo's runtime taxonomy registry reflect definitions applied after
     * plugin init. WooCommerce itself registers pa_* taxonomies during init
     * from wc_get_attribute_taxonomies(); Duo's typed-table apply can land a
     * new definition later in the same request. The public cache invalidators
     * plus register_taxonomy() restore the same product/callback contract for
     * the synchronous lookup data stores below.
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
                        "duo: WooCommerce attribute taxonomy '$taxonomy' could not be registered for lookup regeneration"
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

    private function attribute_lookup_store(): object {
        $class = '\\Automattic\\WooCommerce\\Internal\\ProductAttributesLookup\\LookupDataStore';
        if (!class_exists($class)) {
            throw new \RuntimeException(
                'duo: WooCommerce ProductAttributesLookup\\LookupDataStore is unavailable; cannot reconcile attribute lookup rows'
            );
        }
        try {
            $store = \wc_get_container()->get($class);
        } catch (\Throwable $t) {
            throw new \RuntimeException('duo: failed to load WooCommerce ProductAttributesLookup data store', 0, $t);
        }
        if (!is_object($store)
            || !is_callable([$store, 'create_data_for_product'])
            || !is_callable([$store, 'run_update_callback'])
            || !is_callable([$store, 'get_last_create_operation_failed'])) {
            throw new \RuntimeException(
                'duo: WooCommerce ProductAttributesLookup data store lacks the synchronous public contract'
            );
        }
        return $store;
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

    private function delete_attribute_lookup(object $store, int $id): void {
        $class = '\\Automattic\\WooCommerce\\Internal\\ProductAttributesLookup\\LookupDataStore';
        $action = defined($class . '::ACTION_DELETE') ? constant($class . '::ACTION_DELETE') : 3;
        $store->run_update_callback($id, $action);
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
     * Rebuild one product/variation's active price without touching stock.
     *
     * The on-sale decision below is a Duo-authored restatement of Woo's rule
     * (WC_Product::is_on_sale('edit') plus the _price branch of
     * WC_Product_Data_Store_CPT::update_post_meta()), and it was diffed against
     * WooCommerce 11.0.0 case by case — sale/regular comparison, both date
     * bounds, the empty and zero sale-price edges — and found behaviour
     * equivalent. It is therefore a drift risk rather than a live divergence,
     * and re-homing it onto Woo's own accessors is a separate parity slice
     * (DUO-3342 continuation) rather than part of this change: the Woo-owned
     * alternative writes through $product->save(), which fires the hooks and
     * schedules the Action Scheduler work this whole adapter exists to avoid.
     */
    private function recompute_simple_price(int $id): void {
        global $wpdb;
        $regular = (string) get_post_meta($id, '_regular_price', true);
        $sale = (string) get_post_meta($id, '_sale_price', true);
        $from = get_post_meta($id, '_sale_price_dates_from', true);
        $to = get_post_meta($id, '_sale_price_dates_to', true);
        $now = time();
        $fromActive = $from === '' || $from === null || (int) $from <= $now;
        $toActive = $to === '' || $to === null || (int) $to >= $now;
        $onSale = $sale !== '' && $regular !== ''
            && (float) $regular > (float) $sale && $fromActive && $toActive;
        $price = $onSale ? $sale : $regular;

        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_price'",
            $id
        ));
        if ($deleted === false) {
            throw new \RuntimeException(
                "duo: failed to clear stale WooCommerce _price meta for product $id: {$wpdb->last_error}"
            );
        }
        if ($price === '') {
            $this->invalidate_product_caches($id);
            return;
        }
        $inserted = $wpdb->insert(
            $wpdb->postmeta,
            ['post_id' => $id, 'meta_key' => '_price', 'meta_value' => $price],
            ['%d', '%s', '%s']
        );
        if ($inserted === false) {
            throw new \RuntimeException(
                "duo: failed to write recomputed WooCommerce _price meta for product $id: {$wpdb->last_error}"
            );
        }
        // The write intentionally bypasses update_post_meta() hooks; clear
        // the post/product caches before any verifier or subsequent parent
        // sync reads the freshly derived value.
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
        array $attributeRoots,
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
            $attrCountValue = $this->checked_get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::ATTR_LOOKUP . " WHERE product_id = %d OR product_or_parent_id = %d",
                (int) $id,
                (int) $id
            ), "product attribute deletion verification for product $id");
            if ($metaCountValue === null || $attrCountValue === null) {
                throw new \RuntimeException(
                    "duo: WooCommerce lookup deletion verification returned no count for product $id"
                );
            }
            $metaCount = (int) $metaCountValue;
            $attrCount = (int) $attrCountValue;
            if ($metaCount !== 0 || $attrCount !== 0) {
                throw new \RuntimeException(
                    "duo: WooCommerce lookup deletion verification failed for product $id "
                    . "(meta rows=$metaCount, attribute rows=$attrCount)"
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

        foreach ($attributeRoots as $rootId => $root) {
            $this->heartbeat($heartbeat);
            $expected = $this->expected_attribute_rows($root, $deletionIds);
            $actual = $this->actual_attribute_rows((int) $rootId);
            if ($expected !== $actual) {
                throw new \RuntimeException(
                    "duo: WooCommerce product attributes lookup verification failed for product $rootId"
                );
            }
            $this->heartbeat($heartbeat);
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
     *      compared back afterwards. This is the check that says "the apply
     *      converged this row". It has to be taken first: the refresh below
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

        $this->assert_lookup_row_matches(
            $id,
            $table,
            $stored,
            $applied,
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

    /**
     * Rebuild the attribute lookup rows WooCommerce should have synthesized.
     *
     * This is a Duo-authored mirror of WooCommerce's own row synthesis, and it
     * stays one deliberately, unlike the meta-lookup column rules that moved
     * onto Woo's published derivation. Two reasons, both structural:
     *
     * Woo's synthesis has no observable derivation channel. create_data_for_
     * product() is the only public entry point (LookupDataStore.php:307);
     * every method that decides what a row should contain —
     * insert_lookup_table_data_for_variation() (:474), insert_lookup_table_
     * data() (:616), create_data_for_product_cpt() (:816) and its core (:847)
     * — is private, writes straight to the table, and caches nothing a caller
     * could read back. There is no equivalent of update_lookup_table()'s
     * published row here.
     *
     * And this check is the only guard on the failure it exists for: Woo
     * synthesizes NO rows, silently and successfully, for a pa_* taxonomy that
     * is not registered at the moment it runs — exactly the post-init
     * typed-table bootstrap case refresh_attribute_taxonomy_registry() above
     * exists to prevent. A comparison against Woo's own output would agree with
     * Woo that zero rows were correct.
     *
     * @return array<int,array<string,int|string>>
     */
    private function expected_attribute_rows(object $root, array $deletionIds = []): array {
        $rows = [];
        $type = (string) $root->get_type();
        if ($type === 'variation') {
            $parentId = (int) $root->get_parent_id('edit');
            $parent = $parentId > 0 ? $this->load_product($parentId) : false;
            if (!$parent || !$this->is_variable($parent)) {
                return $rows;
            }
            $root = $parent;
            $type = 'variable';
        }
        $attributes = (array) $root->get_attributes();
        if ($type !== 'variable') {
            foreach ($attributes as $taxonomy => $attribute) {
                if (!is_object($attribute) || !is_callable([$attribute, 'get_id']) || !(int) $attribute->get_id()) {
                    continue;
                }
                $this->append_attribute_rows(
                    $rows,
                    (int) $root->get_id(),
                    (int) $root->get_id(),
                    (string) $taxonomy,
                    array_map('intval', (array) $attribute->get_options()),
                    false,
                    $root->is_in_stock()
                );
            }
            return $this->sort_attribute_rows($rows);
        }

        $variationAttributes = [];
        foreach ($attributes as $taxonomy => $attribute) {
            if (!is_object($attribute) || !is_callable([$attribute, 'get_id']) || !(int) $attribute->get_id()) {
                continue;
            }
            $termIds = array_map('intval', (array) $attribute->get_options());
            $isVariation = is_callable([$attribute, 'get_variation']) && (bool) $attribute->get_variation();
            if ($isVariation) {
                $variationAttributes[(string) $taxonomy] = $termIds;
            } else {
                $this->append_attribute_rows(
                    $rows,
                    (int) $root->get_id(),
                    (int) $root->get_id(),
                    (string) $taxonomy,
                    $termIds,
                    false,
                    $root->is_in_stock()
                );
            }
        }
        $termSlugIds = $this->term_slug_ids(array_keys($variationAttributes));
        foreach ((array) $root->get_children() as $childId) {
            if (isset($deletionIds[(int) $childId])) {
                continue;
            }
            $child = $this->load_product((int) $childId);
            if (!$child) {
                continue;
            }
            $childAttrs = (array) $child->get_attributes();
            foreach ($variationAttributes as $taxonomy => $termIds) {
                $slug = $childAttrs[$taxonomy] ?? ($childAttrs['attribute_' . $taxonomy] ?? '');
                $ids = [];
                if (is_string($slug) && $slug !== '' && isset($termSlugIds[$taxonomy][$slug])) {
                    $ids = [(int) $termSlugIds[$taxonomy][$slug]];
                } else {
                    $ids = $termIds;
                }
                $this->append_attribute_rows(
                    $rows,
                    (int) $child->get_id(),
                    (int) $root->get_id(),
                    (string) $taxonomy,
                    $ids,
                    true,
                    $child->is_in_stock()
                );
            }
        }
        return $this->sort_attribute_rows($rows);
    }

    private function append_attribute_rows(
        array &$rows,
        int $productId,
        int $parentId,
        string $taxonomy,
        array $termIds,
        bool $variation,
        bool $inStock
    ): void {
        foreach ($termIds as $termId) {
            $termId = (int) $termId;
            if ($termId <= 0) {
                continue;
            }
            $rows[] = [
                'product_id' => $productId,
                'product_or_parent_id' => $parentId,
                'taxonomy' => $taxonomy,
                'term_id' => $termId,
                'is_variation_attribute' => $variation ? 1 : 0,
                'in_stock' => $inStock ? 1 : 0,
            ];
        }
    }

    /** @return array<string,array<string,int>> */
    private function term_slug_ids(array $taxonomies): array {
        $out = [];
        foreach ($taxonomies as $taxonomy) {
            $terms = get_terms([
                'taxonomy' => function_exists('wc_sanitize_taxonomy_name')
                    ? wc_sanitize_taxonomy_name($taxonomy)
                    : $taxonomy,
                'hide_empty' => false,
                'fields' => 'id=>slug',
                // Polylang interprets an explicit empty language as an
                // unfiltered term query; core safely ignores the extra arg.
                'lang' => '',
            ]);
            if (is_wp_error($terms)) {
                throw new \RuntimeException("duo: failed to read WooCommerce attribute terms for $taxonomy");
            }
            $out[$taxonomy] = array_flip((array) $terms);
        }
        return $out;
    }

    /** @return array<int,array<string,int|string>> */
    private function actual_attribute_rows(int $rootId): array {
        global $wpdb;
        $table = $wpdb->prefix . self::ATTR_LOOKUP;
        $rows = $this->checked_get_results($wpdb->prepare(
            "SELECT product_id, product_or_parent_id, taxonomy, term_id, is_variation_attribute, in_stock
             FROM `$table` WHERE product_or_parent_id = %d OR product_id = %d",
            $rootId,
            $rootId
        ), "product attribute lookup verification for product $rootId");
        $normalized = [];
        foreach ($rows as $row) {
            $normalized[] = [
                'product_id' => (int) $row['product_id'],
                'product_or_parent_id' => (int) $row['product_or_parent_id'],
                'taxonomy' => (string) $row['taxonomy'],
                'term_id' => (int) $row['term_id'],
                'is_variation_attribute' => (int) $row['is_variation_attribute'],
                'in_stock' => (int) $row['in_stock'],
            ];
        }
        return $this->sort_attribute_rows($normalized);
    }

    private function sort_attribute_rows(array $rows): array {
        usort($rows, static function (array $a, array $b): int {
            foreach (['product_id', 'product_or_parent_id', 'taxonomy', 'term_id', 'is_variation_attribute', 'in_stock'] as $key) {
                $cmp = ((string) $a[$key]) <=> ((string) $b[$key]);
                if ($cmp !== 0) {
                    return $cmp;
                }
            }
            return 0;
        });
        return $rows;
    }
}
