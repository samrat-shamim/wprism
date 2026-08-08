<?php
/**
 * Offline contract regression for the WooCommerce derived lookup adapter.
 *
 * WordPress/WooCommerce itself is intentionally not bootstrapped here.  The
 * live pair test owns the real data-store calls; this check protects the
 * manifest/Policy dispatch boundary and the adapter's public API choices from
 * being silently replaced with an asynchronous hook or a blanket rebuilder.
 */

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}
$root = dirname(__DIR__, 2);
putenv('DUO_MANIFESTS_DIR=' . $root . '/manifests');

require $root . '/agent/src/Canon.php';
require $root . '/agent/src/OptionState.php';
require $root . '/agent/src/Policy.php';

use Duo\Policy;

$failures = 0;
function check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
    } else {
        echo "FAIL: $message\n";
        $failures++;
    }
}

$policy = Policy::load(null, ['woocommerce']);
$productBatch = $policy->regen_batch('product');
$variationBatch = $policy->regen_batch('product_variation');
check($productBatch === ['enabled' => true, 'always_on_write' => true],
    'product opts into enabled always-on-write batch regeneration');
check($variationBatch === ['enabled' => true, 'always_on_write' => true],
    'product_variation opts into the same batch contract');
check(array_keys($policy->regen_batch_post_types()) === ['product', 'product_variation'],
    'batch post-type discovery is deterministic and covers both Woo product entities');

$regenerators = $policy->regenerators();
$adapter = $regenerators['woocommerce-product-lookups'] ?? null;
check(is_object($adapter), 'manifest-shipped Woo lookup regenerator loads through Policy');
check(is_object($adapter) && method_exists($adapter, 'regenerate'),
    'Woo adapter retains the compatible single-id regenerate() boundary');
check(is_object($adapter) && method_exists($adapter, 'regenerate_batch'),
    'Woo adapter exposes the synchronous regenerate_batch() boundary');

$source = file_get_contents($root . '/manifests/regenerators/woocommerce-product-lookups.php');
check(is_string($source), 'Woo adapter source is readable');
$needles = [
    'refresh_product_lookup_table' => 'public product meta lookup refresh API is used',
    'create_data_for_product' => 'attribute lookup generation is synchronous',
    'sync_price' => 'variable roots use Woo variable data-store price sync',
    "'product-grouped'" => 'grouped roots use Woo grouped data-store price sync',
    'groupedRoots' => 'grouped roots are deduplicated before synthesis and lookup refresh',
    'refresh_grouped_children_for_sync' => 'grouped children are reloaded after variable price synthesis',
    "meta_key = '_children'" => 'grouped parent discovery is restricted to the _children meta relation',
    'find_grouped_parent_ids' => 'changed children discover grouped roots through a bounded reverse lookup',
    '$wpdb->get_col' => 'grouped reverse discovery reads only candidate parent ids',
    '$wpdb->posts' => 'grouped reverse discovery validates product post candidates in SQL',
    'delete_from_lookup_table' => 'product lookup deletion uses the public delete API',
    'ACTION_DELETE' => 'attribute lookup deletion uses ACTION_DELETE',
    'wc_get_attribute_taxonomies' => 'Woo attribute definitions are refreshed through the public API',
    "delete_transient('wc_attribute_taxonomies')" => 'Woo attribute transient is invalidated before regeneration',
    "invalidate_cache_group('woocommerce-attributes')" => 'Woo attribute object-cache group is invalidated',
    'register_taxonomy' => 'new Woo attribute taxonomies are registered for products',
    "'update_count_callback' => '_update_post_term_count'" => 'registered Woo attribute taxonomy keeps its count callback',
    'recompute_simple_price' => 'derived _price is recomputed from authored inputs',
    '_regular_price' => 'price recomputation reads authored regular price',
    '_sale_price_dates_from' => 'price recomputation honors sale windows',
];
foreach ($needles as $needle => $message) {
    check(is_string($source) && str_contains($source, $needle), $message);
}
check(is_string($source) && !str_contains($source, '->on_product_changed('),
    'adapter does not enqueue Woo asynchronous on_product_changed() work');
check(is_string($source) && !str_contains($source, 'wc_get_products(')
    && !str_contains($source, 'get_posts('),
    'grouped reconciliation does not perform a catalog-wide Woo product scan');
$refreshGrouped = is_string($source) ? strpos($source, 'refresh_grouped_children_for_sync(') : false;
$groupedStoreLoad = is_string($source) ? strpos($source, "WC_Data_Store::load('product-grouped')") : false;
check($refreshGrouped !== false && $groupedStoreLoad !== false && $refreshGrouped < $groupedStoreLoad,
    'grouped child cache refresh runs before the grouped public sync_price() call');

if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
