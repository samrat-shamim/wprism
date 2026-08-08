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
 */
final class WoocommerceProductLookups {
    private Policy $policy;

    private const META_LOOKUP = 'wc_product_meta_lookup';
    private const ATTR_LOOKUP = 'wc_product_attributes_lookup';

    public function __construct(Policy $policy) {
        $this->policy = $policy;
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
            $product = \wc_get_product($id);
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
                $parent = $parentId > 0 ? \wc_get_product($parentId) : false;
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
                    $child = \wc_get_product($childId);
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
                $parent = \wc_get_product($parentId);
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
            $parent = \wc_get_product($parentId);
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
                    $child = \wc_get_product($childId);
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
        if (!is_object($variableStore) || !is_callable([$variableStore, 'sync_price'])) {
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
            if (!is_object($groupedStore) || !is_callable([$groupedStore, 'sync_price'])) {
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
        if (!is_object($productStore) || !is_callable([$productStore, 'refresh_product_lookup_table'])) {
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
        foreach ($attributeRoots as $rootId => $root) {
            $this->heartbeat($heartbeat);
            $attributeStore->create_data_for_product((int) $rootId, false);
            if (is_callable([$attributeStore, 'get_last_create_operation_failed'])
                && $attributeStore->get_last_create_operation_failed()) {
                throw new \RuntimeException(
                    "duo: WooCommerce product attributes lookup generation reported failure for product $rootId"
                );
            }
            $this->heartbeat($heartbeat);
        }

        $this->verify_exact_state($products, $variableRoots, $attributeRoots, $deletionIds, $heartbeat);
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
            $freshRoot = \wc_get_product($rootId);
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
                $child = \wc_get_product($childId);
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
            $inTransaction = $wpdb->get_var('SELECT @@in_transaction');
            if ($inTransaction === false || ($inTransaction === null && (string) ($wpdb->last_error ?? '') !== '')) {
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
        if (!function_exists('wc_get_product') || !class_exists('WC_Data_Store') || !function_exists('wc_get_container')) {
            throw new \RuntimeException(
                'duo: WooCommerce product lookup regenerator requires WooCommerce 11.x public data-store APIs'
            );
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

    private function delete_meta_lookup(object $store, int $id): void {
        if (!is_callable([$store, 'delete_from_lookup_table'])) {
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
        $rows = $wpdb->get_col($wpdb->prepare(
            "SELECT DISTINCT pm.post_id
             FROM {$wpdb->postmeta} pm
             INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
             WHERE pm.meta_key = '_children'
               AND p.post_type = 'product'
               AND (pm.meta_value LIKE %s OR pm.meta_value LIKE %s)",
            $integerNeedle,
            $stringNeedle
        )) ?: [];
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
            $child = \wc_get_product($childId);
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
                        $variation = \wc_get_product($variationId);
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

    /** Rebuild one product/variation's active price without touching stock. */
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
        array $products,
        array $variableRoots,
        array $attributeRoots,
        array $deletionIds,
        ?callable $heartbeat = null
    ): void {
        global $wpdb;
        foreach ($deletionIds as $id) {
            $this->heartbeat($heartbeat);
            $metaCount = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::META_LOOKUP . " WHERE product_id = %d",
                (int) $id
            ));
            $attrCount = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}" . self::ATTR_LOOKUP . " WHERE product_id = %d OR product_or_parent_id = %d",
                (int) $id,
                (int) $id
            ));
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
            $this->verify_meta_row($id);
        }
        foreach ($variableRoots as $id => $root) {
            $id = (int) $id;
            $this->heartbeat($heartbeat);
            $this->verify_meta_row($id);
            foreach ((array) $root->get_children() as $childId) {
                $childId = (int) $childId;
                if ($childId > 0 && !isset($deletionIds[$childId])) {
                    $this->heartbeat($heartbeat);
                    $this->verify_meta_row($childId);
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

    private function verify_meta_row(int $id): void {
        global $wpdb;
        $table = $wpdb->prefix . self::META_LOOKUP;
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM `$table` WHERE product_id = %d LIMIT 1",
            $id
        ), ARRAY_A);
        if (!is_array($row)) {
            throw new \RuntimeException("duo: WooCommerce product lookup row missing for product $id");
        }
        $count = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM `$table` WHERE product_id = %d",
            $id
        ));
        if ($count !== 1) {
            throw new \RuntimeException("duo: WooCommerce product lookup has $count rows for product $id; expected exactly one");
        }

        $priceMeta = (array) get_post_meta($id, '_price', false);
        $expected = [
            'product_id' => $id,
            'sku' => (string) get_post_meta($id, '_sku', true),
            'virtual' => get_post_meta($id, '_virtual', true) === 'yes' ? 1 : 0,
            'downloadable' => get_post_meta($id, '_downloadable', true) === 'yes' ? 1 : 0,
            'min_price' => $priceMeta ? reset($priceMeta) : null,
            'max_price' => $priceMeta ? end($priceMeta) : null,
            'onsale' => $this->is_on_sale_from_meta($id) ? 1 : 0,
            'stock_quantity' => $this->stock_quantity_from_meta($id),
            'stock_status' => (string) get_post_meta($id, '_stock_status', true),
            'rating_count' => array_sum(array_map('intval', (array) get_post_meta($id, '_wc_rating_count', true))),
            'average_rating' => (string) get_post_meta($id, '_wc_average_rating', true),
            'total_sales' => (string) get_post_meta($id, 'total_sales', true),
            'tax_status' => (string) get_post_meta($id, '_tax_status', true),
            'tax_class' => (string) get_post_meta($id, '_tax_class', true),
        ];
        if ((int) get_option('woocommerce_schema_version', 0) >= 920) {
            $expected['global_unique_id'] = (string) get_post_meta($id, '_global_unique_id', true);
        }
        if (array_key_exists('cogs_total_value', $row) && $this->cogs_lookup_enabled()) {
            $cogs = (string) get_post_meta($id, '_cogs_total_value', true);
            $expected['cogs_total_value'] = $cogs === '' ? null : (float) $cogs;
        }
        foreach ($expected as $column => $want) {
            if (!array_key_exists($column, $row)) {
                continue;
            }
            if (!$this->lookup_values_equal($want, $row[$column], $column)) {
                throw new \RuntimeException(
                    "duo: WooCommerce product lookup verification mismatch for product $id.$column "
                    . '(expected ' . var_export($want, true) . ', found ' . var_export($row[$column], true) . ')'
                );
            }
        }
    }

    private function stock_quantity_from_meta(int $id): ?float {
        if (get_post_meta($id, '_manage_stock', true) !== 'yes') {
            return null;
        }
        $value = get_post_meta($id, '_stock', true);
        if (function_exists('wc_stock_amount')) {
            return (float) wc_stock_amount($value);
        }
        return (float) $value;
    }

    private function cogs_lookup_enabled(): bool {
        $controllerClass = '\\Automattic\\WooCommerce\\Internal\\CostOfGoodsSold\\CostOfGoodsSoldController';
        if (!class_exists($controllerClass) || !function_exists('wc_get_container')) {
            return false;
        }
        try {
            $controller = \wc_get_container()->get($controllerClass);
            return is_object($controller)
                && is_callable([$controller, 'feature_is_enabled'])
                && (bool) $controller->feature_is_enabled()
                && is_callable([$controller, 'product_meta_lookup_table_cogs_value_columns_exist'])
                && (bool) $controller->product_meta_lookup_table_cogs_value_columns_exist();
        } catch (\Throwable $t) {
            return false;
        }
    }

    private function is_on_sale_from_meta(int $id): bool {
        // Mirror WC_Product_Data_Store_CPT::get_data_for_lookup_table()
        // exactly: lookup onsale is based on the authored sale value being
        // truthy and matching the already-recomputed effective _price.  Do
        // not re-derive regular-vs-sale or dates here; that would disagree
        // with Woo for temporary prices and the zero sale-price edge case.
        $price = function_exists('wc_format_decimal')
            ? (string) wc_format_decimal(get_post_meta($id, '_price', true))
            : (string) get_post_meta($id, '_price', true);
        $sale = function_exists('wc_format_decimal')
            ? (string) wc_format_decimal(get_post_meta($id, '_sale_price', true))
            : (string) get_post_meta($id, '_sale_price', true);
        return (bool) $sale && $price === $sale;
    }

    private function lookup_values_equal($expected, $actual, string $column): bool {
        if ($expected === null) {
            if (in_array($column, ['min_price', 'max_price'], true)) {
                // Woo's DECIMAL(19,4) lookup columns are NOT NULL with a
                // zero default, so a product with no effective price is
                // read back as 0.0000 after the public refresh call.
                return $actual === null || $actual === ''
                    || (is_numeric($actual) && abs((float) $actual) < 0.000001);
            }
            return $actual === null || $actual === '';
        }
        if (in_array($column, ['product_id', 'virtual', 'downloadable', 'onsale', 'rating_count'], true)) {
            return (int) $expected === (int) $actual;
        }
        if ($column === 'average_rating') {
            // Woo stores this DECIMAL(3,2) lookup column as 0.00 when the
            // source meta is empty. Preserve strictness for non-numeric
            // values, but compare valid decimal values by their numeric
            // meaning so 4.5 and 4.50 remain equivalent.
            if ($expected === '' && ($actual === ''
                || (is_numeric($actual) && abs((float) $actual) < 0.000001))) {
                return true;
            }
            if (is_numeric($expected) && is_numeric($actual)) {
                return abs((float) $expected - (float) $actual) < 0.000001;
            }
            return (string) $expected === (string) $actual;
        }
        if ($column === 'total_sales') {
            // Woo stores this BIGINT lookup column as 0 when the source meta
            // is absent. Never cast malformed source text to zero: only
            // numeric values may take the numeric comparison path.
            if ($expected === '') {
                return $actual === ''
                    || (is_numeric($actual) && abs((float) $actual) < 0.000001);
            }
            if (!is_numeric($expected) || !is_numeric($actual)) {
                return false;
            }
            return abs((float) $expected - (float) $actual) < 0.000001;
        }
        if ($column === 'stock_quantity' || $column === 'cogs_total_value') {
            if ($expected === null) {
                return $actual === null || $actual === '';
            }
            return abs((float) $expected - (float) $actual) < 0.000001;
        }
        if (in_array($column, ['min_price', 'max_price'], true)) {
            if ($expected === null) {
                return $actual === null || $actual === ''
                    || (is_numeric($actual) && abs((float) $actual) < 0.000001);
            }
            if (!is_numeric($expected) || !is_numeric($actual)) {
                return (string) $expected === (string) $actual;
            }
            return (string) $expected === (string) $actual
                || abs((float) $expected - (float) $actual) < 0.000001;
        }
        return (string) $expected === (string) $actual;
    }

    /** @return array<int,array<string,int|string>> */
    private function expected_attribute_rows(object $root, array $deletionIds = []): array {
        $rows = [];
        $type = (string) $root->get_type();
        if ($type === 'variation') {
            $parentId = (int) $root->get_parent_id('edit');
            $parent = $parentId > 0 ? \wc_get_product($parentId) : false;
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
            $child = \wc_get_product((int) $childId);
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
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT product_id, product_or_parent_id, taxonomy, term_id, is_variation_attribute, in_stock
             FROM `$table` WHERE product_or_parent_id = %d OR product_id = %d",
            $rootId,
            $rootId
        ), ARRAY_A) ?: [];
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
