<?php
/**
 * Offline contract regression for the WooCommerce derived lookup adapter.
 *
 * WordPress/WooCommerce itself is intentionally not bootstrapped here.  The
 * live pair test owns the real data-store calls; this check protects the
 * manifest/Policy dispatch boundary and the adapter's public API choices from
 * being silently replaced with an asynchronous hook or a blanket rebuild action.
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
    'wc_maybe_schedule_product_sale_events' => 'sale actions use Woo public per-product scheduling',
    'as_unschedule_all_actions' => 'deleted sale actions use bounded public unscheduling',
    'as_next_scheduled_action' => 'sale actions have exact Action Scheduler readback',
    'verify_sale_schedules' => 'sale schedule verification is explicit and separate from lookup verification',
    // DUO-3342: meta-lookup verification is WooCommerce's own derivation read
    // back against the stored row, not a Duo-authored rebuild of Woo's column
    // rules. get_data_for_lookup_table() is protected, so the `lookup_table`
    // object-cache entry update_lookup_table() publishes is the only channel
    // through which Woo can state what it derived.
    "wp_cache_get('lookup_table'" => 'meta lookup verification reads the derivation WooCommerce itself published',
    'woo_republished_lookup_row' => 'the published WooCommerce derivation has a single named boundary',
    // The exact SQL fragment, not a bare `<=>`: this adapter has an unrelated
    // PHP spaceship in its attribute-row sort, so the operator alone would
    // match with the predicate gone (proven: swapping the predicate to `=`
    // still satisfied a bare-operator needle).
    '` <=> %s' => "stored lookup values are compared by SQL under the column's own semantics",
    '` IS NULL' => 'a derivation of SQL NULL is compared as NULL rather than coerced',
    // Only that the named read boundary exists — this string appears at BOTH
    // call sites, so it cannot and does not pin their ORDER around the
    // refresh. That ordering is the load-bearing property (the refresh runs
    // with a cleared cache, so it always REPLACEs, and a row read only
    // afterwards would be one this verification had just written), and it is
    // covered behaviorally by the sibling-variation case in
    // regress_woocommerce_product_lookups_fake.php, which fails when the
    // before-read is moved after the refresh.
    'read_lookup_row($table, $id)' => 'the stored-row read has a single named boundary',
];
foreach ($needles as $needle => $message) {
    check(is_string($source) && str_contains($source, $needle), $message);
}
// The retired rebuild of Woo's lookup columns, stated as absences so a future
// change cannot quietly reintroduce a second, drifting copy of rules that live
// in a protected WooCommerce method. Deliberately narrow: wc_format_decimal()
// is the RIGHT helper for any future price math here, so its absence is not a
// property worth pinning.
$retired = [
    'lookup_values_equal' => 'no Duo-authored per-column tolerance table for lookup values',
    "get_option('woocommerce_schema_version'" => 'no copied global_unique_id schema-version gate',
    'CostOfGoodsSoldController' => 'no copied Cost of Goods Sold lookup-column feature gate',
    '_cogs_total_value' => 'no Duo-side derivation of the COGS lookup column',
];
foreach ($retired as $needle => $message) {
    check(is_string($source) && !str_contains($source, $needle), $message);
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
// DUO-3338: the whole-catalog projection can no longer be dispatched at all,
// not merely "is not declared" — the structured action channel has no field
// that can carry a PHP callable or command string for the engine to run.
$actions = $policy->actions();
$sources = array_map(
    static fn(array $row): string => \Duo\Policy::action_source($row, (int) $row['index']),
    $actions
);
check($sources === ['native:transient.delete', 'provider:woocommerce-cache/invalidate_cache_groups'],
    'Woo policy declares only the bounded transient and cache repairs, and no whole-catalog projection');
check(array_filter($actions, static fn(array $row): bool => array_key_exists('command', $row)) === [],
    'no Woo action carries an executable command string');

if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
