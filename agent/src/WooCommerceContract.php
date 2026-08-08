<?php
namespace Duo;

/**
 * Deterministic WooCommerce 11.x projections owned by the Duo adapter.
 *
 * Apply writes authored rows without firing plugin hooks. WooCommerce normally
 * maintains these projections from those hooks, so a branch apply must rebuild
 * them explicitly and refuse ledger advancement unless their source-derived
 * invariants hold. This class deliberately uses only WooCommerce's public 11.x
 * entry points plus checked writes to the derived `_price` rows.
 */
final class WooCommerceContract {
    /** @return array<string,int> */
    public static function rebuild(): array {
        self::assert_runtime();

        $ids = get_posts([
            'fields' => 'ids',
            'nopaging' => true,
            'orderby' => 'ID',
            'order' => 'ASC',
            'post_status' => 'any',
            'post_type' => ['product', 'product_variation'],
            'suppress_filters' => true,
        ]);
        $ids = array_values(array_map('intval', $ids));

        // Child prices must exist before variable/grouped parents are reduced.
        $parents = [];
        foreach ($ids as $id) {
            clean_post_cache($id);
            $product = wc_get_product($id);
            if (!$product) {
                throw new \RuntimeException("duo: WooCommerce contract cannot load product $id");
            }
            if ($product->is_type(['variable', 'grouped'])) {
                $parents[] = $id;
                continue;
            }
            self::replace_prices($id, [self::leaf_price($product)]);
            clean_post_cache($id);
        }
        foreach ($parents as $id) {
            clean_post_cache($id);
            $product = wc_get_product($id);
            if (!$product) {
                throw new \RuntimeException("duo: WooCommerce contract cannot reload parent product $id");
            }
            self::replace_prices($id, self::parent_prices($product));
            clean_post_cache($id);
        }

        foreach ($ids as $id) {
            clean_post_cache($id);
            wc_maybe_schedule_product_sale_events($id);
        }

        wc_update_product_lookup_tables();

        $regeneratorClass = 'Automattic\\WooCommerce\\Internal\\ProductAttributesLookup\\DataRegenerator';
        /** @var object $regenerator */
        $regenerator = wc_get_container()->get($regeneratorClass);
        $regenerator->initiate_regeneration(false);
        do {
            $more = (bool) $regenerator->do_regeneration_step(null, true);
            if ($regenerator->get_last_regeneration_step_failed()) {
                throw new \RuntimeException('duo: WooCommerce attribute lookup regeneration failed');
            }
        } while ($more);
        $regenerator->finalize_regeneration(true);

        $categoryClass = 'Automattic\\WooCommerce\\Internal\\Admin\\CategoryLookup';
        $categoryClass::instance()->regenerate();
        delete_transient('wc_attribute_taxonomies');

        self::verify($ids);
        return ['products' => count($ids), 'parents' => count($parents)];
    }

    private static function assert_runtime(): void {
        if (!defined('WC_VERSION')
            || version_compare((string) WC_VERSION, '11.0.0', '<')
            || version_compare((string) WC_VERSION, '12.0.0', '>=')) {
            throw new \RuntimeException('duo: WooCommerce projection rebuild requires WooCommerce [11.0.0, 12.0.0)');
        }
        foreach (['wc_get_product', 'wc_update_product_lookup_tables', 'wc_maybe_schedule_product_sale_events', 'wc_get_container'] as $fn) {
            if (!function_exists($fn)) {
                throw new \RuntimeException("duo: WooCommerce projection API $fn is unavailable");
            }
        }
        foreach ([
            'Automattic\\WooCommerce\\Internal\\ProductAttributesLookup\\DataRegenerator',
            'Automattic\\WooCommerce\\Internal\\Admin\\CategoryLookup',
        ] as $class) {
            if (!class_exists($class)) {
                throw new \RuntimeException("duo: WooCommerce projection API $class is unavailable");
            }
        }
    }

    private static function leaf_price(object $product): string {
        return (string) ($product->is_on_sale('edit')
            ? $product->get_sale_price('edit')
            : $product->get_regular_price('edit'));
    }

    /** @return list<string> */
    private static function parent_prices(object $product): array {
        global $wpdb;
        $children = $product->is_type('variable')
            ? $product->get_visible_children()
            : $product->get_children('edit');
        $prices = [];
        foreach (array_map('intval', $children) as $childId) {
            $rows = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_price' ORDER BY meta_id",
                $childId
            ));
            foreach ($rows as $price) {
                if ($price !== null && $price !== '') {
                    $prices[] = (string) $price;
                }
            }
        }
        if ($prices === []) {
            return [];
        }
        usort($prices, static fn(string $a, string $b): int => (float) $a <=> (float) $b);
        if ($product->is_type('variable')) {
            return array_values(array_unique($prices, SORT_STRING));
        }
        // WooCommerce's grouped data store writes both bounds, even when equal.
        return [$prices[0], $prices[count($prices) - 1]];
    }

    /** @param list<string> $prices */
    private static function replace_prices(int $productId, array $prices): void {
        global $wpdb;
        Db::delete(
            $wpdb->postmeta,
            ['post_id' => $productId, 'meta_key' => '_price'],
            ['%d', '%s'],
            'WooCommerce derived price delete'
        );
        foreach ($prices as $price) {
            Db::insert(
                $wpdb->postmeta,
                ['post_id' => $productId, 'meta_key' => '_price', 'meta_value' => $price],
                ['%d', '%s', '%s'],
                'WooCommerce derived price insert'
            );
        }
    }

    /** @param list<int> $ids */
    private static function verify(array $ids): void {
        global $wpdb;
        $productLookupTable = $wpdb->prefix . 'wc_product_meta_lookup';
        foreach ($ids as $id) {
            clean_post_cache($id);
            $product = wc_get_product($id);
            if (!$product) {
                throw new \RuntimeException("duo: WooCommerce projection verification cannot load product $id");
            }
            $expected = $product->is_type(['variable', 'grouped'])
                ? self::parent_prices($product)
                : [self::leaf_price($product)];
            $actual = array_map('strval', $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_price' ORDER BY meta_value+0, meta_id",
                $id
            )));
            $sortedExpected = $expected;
            usort($sortedExpected, static fn(string $a, string $b): int => (float) $a <=> (float) $b);
            if ($actual !== $sortedExpected) {
                throw new \RuntimeException("duo: WooCommerce price projection mismatch for product $id");
            }
            $lookup = $wpdb->get_row($wpdb->prepare(
                "SELECT min_price, max_price, stock_quantity, stock_status FROM {$productLookupTable} WHERE product_id = %d",
                $id
            ), ARRAY_A);
            if (!is_array($lookup)) {
                throw new \RuntimeException("duo: WooCommerce product lookup row missing for product $id");
            }
            $nonEmpty = array_values(array_filter($expected, static fn(string $v): bool => $v !== ''));
            if ($nonEmpty !== []) {
                $numbers = array_map('floatval', $nonEmpty);
                if ((float) $lookup['min_price'] !== min($numbers) || (float) $lookup['max_price'] !== max($numbers)) {
                    throw new \RuntimeException("duo: WooCommerce product lookup price mismatch for product $id");
                }
            }
            $expectedStatus = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_stock_status' ORDER BY meta_id DESC LIMIT 1",
                $id
            ));
            if (($lookup['stock_status'] === null ? null : (string) $lookup['stock_status'])
                !== ($expectedStatus === null ? null : (string) $expectedStatus)) {
                throw new \RuntimeException("duo: WooCommerce product lookup stock mismatch for product $id");
            }
            $expectedStock = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key='_stock' ORDER BY meta_id DESC LIMIT 1",
                $id
            ));
            $actualStock = $lookup['stock_quantity'] === null ? null : (float) $lookup['stock_quantity'];
            if (($expectedStock === null && $actualStock !== null)
                || ($expectedStock !== null && $actualStock !== (float) $expectedStock)) {
                throw new \RuntimeException("duo: WooCommerce product lookup quantity mismatch for product $id");
            }
        }

        self::verify_attribute_lookup();
        self::verify_category_lookup();
        self::verify_sale_schedules($ids);
    }

    private static function verify_attribute_lookup(): void {
        global $wpdb;
        $lookupTable = $wpdb->prefix . 'wc_product_attributes_lookup';
        $rows = $wpdb->get_results(
            "SELECT pm.post_id, p.post_parent, pm.meta_key, pm.meta_value
             FROM {$wpdb->postmeta} pm
             JOIN {$wpdb->posts} p ON p.ID = pm.post_id AND p.post_type = 'product_variation'
             WHERE pm.meta_key LIKE 'attribute\\_pa\\_%' AND pm.meta_value <> ''",
            ARRAY_A
        );
        foreach ($rows as $row) {
            $taxonomy = substr((string) $row['meta_key'], strlen('attribute_'));
            $termId = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT t.term_id FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy=%s AND t.slug=%s LIMIT 1",
                $taxonomy,
                (string) $row['meta_value']
            ));
            $count = $termId > 0 ? (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$lookupTable} WHERE product_id=%d AND product_or_parent_id=%d AND taxonomy=%s AND term_id=%d AND is_variation_attribute=1",
                (int) $row['post_id'],
                (int) $row['post_parent'],
                $taxonomy,
                $termId
            )) : 0;
            if ($count !== 1) {
                throw new \RuntimeException('duo: WooCommerce variation attribute lookup projection mismatch');
            }
        }
    }

    private static function verify_category_lookup(): void {
        global $wpdb;
        $lookupTable = $wpdb->prefix . 'wc_category_lookup';
        $categoryIds = array_map('intval', $wpdb->get_col(
            "SELECT DISTINCT tt.term_id FROM {$wpdb->term_taxonomy} tt JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id=tt.term_taxonomy_id JOIN {$wpdb->posts} p ON p.ID=tr.object_id WHERE tt.taxonomy='product_cat' AND p.post_type='product'"
        ));
        foreach ($categoryIds as $categoryId) {
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$lookupTable} WHERE category_id=%d",
                $categoryId
            ));
            if ($count < 1) {
                throw new \RuntimeException("duo: WooCommerce category lookup row missing for category $categoryId");
            }
        }
    }

    /** @param list<int> $ids */
    private static function verify_sale_schedules(array $ids): void {
        foreach ($ids as $id) {
            clean_post_cache($id);
            $product = wc_get_product($id);
            if (!$product) {
                continue;
            }
            $expected = [
                'wc_product_start_scheduled_sale' => $product->get_date_on_sale_from('edit'),
                'wc_product_end_scheduled_sale' => $product->get_date_on_sale_to('edit'),
            ];
            foreach ($expected as $hook => $date) {
                $next = as_next_scheduled_action($hook, ['product_id' => $id], 'woocommerce-sales');
                $future = $date && $date->getTimestamp() > time();
                if (($future && $next === false) || (!$future && $next !== false)) {
                    throw new \RuntimeException("duo: WooCommerce sale schedule projection mismatch for product $id");
                }
            }
        }
    }
}
