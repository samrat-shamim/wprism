<?php
/**
 * Offline contract regression for the WooCommerce derived lookup adapter.
 *
 * WordPress/WooCommerce itself is intentionally not bootstrapped here.  The
 * live pair test owns the real data-store calls; this check protects the
 * manifest/Policy dispatch boundary and the adapter's public API choices from
 * being silently replaced with an asynchronous hook or a blanket rebuild action.
 *
 * DUO-3342 moved that dispatch boundary from the regenerator channel to the
 * provider contract. The adapter's own public-API choices below are unchanged
 * (same needles, same file, moved to manifests/providers/); what changed is
 * which engine channel reaches it, so the declaration half of this suite now
 * asserts the provider contract and the ABSENCE of the batch regen_dependency
 * that used to claim the same two post types.
 */

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}
$root = dirname(__DIR__, 2);
putenv('DUO_MANIFESTS_DIR=' . $root . '/manifests');

require $root . '/agent/src/Canon.php';
require $root . '/agent/src/OptionState.php';
require $root . '/agent/src/Policy.php';
require $root . '/agent/src/Providers.php';

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
check($policy->regen_dependency('product') === null
    && $policy->regen_dependency('product_variation') === null,
    'neither Woo product post type declares a regen_dependency any more — batch, verify, and effects all '
    . 'migrated to the provider action');
check($policy->regen_batch('product') === null && $policy->regen_batch('product_variation') === null
    && $policy->regen_batch_post_types() === [],
    'and the batch channel claims no Woo post type, which is what keeps the negotiation-time dual-claimant '
    . 'refusal from firing on a manifest that finished its migration');

$declaration = $policy->provider_declarations()['woocommerce-product-lookups'] ?? null;
check(is_array($declaration)
    && $declaration['source'] === 'manifest'
    && $declaration['version'] === '1.0.0'
    && $declaration['plugin'] === 'woocommerce/woocommerce.php'
    && $declaration['capabilities'] === ['rebuild_product_lookups'],
    'the lookup repair is declared as a manifest-sourced provider pinned to the same plugin the manifest claims');
$expectedRequires = [
    'functions' => [
        'wc_get_product',
        'wc_get_container',
        'add_filter',
        'remove_filter',
        'wc_maybe_schedule_product_sale_events',
        'as_unschedule_all_actions',
        'as_next_scheduled_action',
        'wp_cache_get',
        'wp_cache_delete',
    ],
    'classes' => [
        'WC_Data_Store',
        'WC_Product_Variable',
        'WC_Product_Grouped',
    ],
];
check(is_array($declaration)
    && ($declaration['requires'] ?? null) === $expectedRequires
    && !array_key_exists('plugin_version', (array) ($declaration['requires'] ?? [])),
    'the Woo lookup provider declares exactly its required functions/classes and does not duplicate the manifest plugin_version range');

$providerFile = $root . '/manifests/providers/woocommerce-product-lookups.php';
check(is_file($providerFile), 'provider code ships beside its manifest, under providers/');
check(!is_file($root . '/manifests/regenerators/woocommerce-product-lookups.php'),
    'and the retired regenerator file is gone, not left behind as a second copy of the same adapter');
require $providerFile;
$adapter = new \Duo\Providers\WoocommerceProductLookups($policy);
check($adapter->identity() === [
    'id' => 'woocommerce-product-lookups',
    'plugin' => 'woocommerce/woocommerce.php',
    'version' => '1.0.0',
], "the provider's self-reported identity matches its declaration exactly (negotiation compares these)");
$capabilities = $adapter->capabilities();
$capability = $capabilities['rebuild_product_lookups'] ?? null;
check(array_keys($capabilities) === ['rebuild_product_lookups'],
    'it advertises exactly the one capability the manifest names');
check(is_array($capability) && $capability['scope'] === 'entity' && $capability['idempotent'] === true,
    'entity-scoped and idempotent — apply re-fires the rebuild pass on retry, so anything else refuses');
check(is_array($capability)
    && ($capability['context'] ?? null) === ['always_on_write', 'deletions', 'reparents', 'retry'],
    'and it declares every engine batch channel the regenerator channel used to hand this same code');
check(is_array($capability) && $capability['args'] === [],
    'no manifest-supplied arguments: every input is engine-assembled, and the reserved entities argument '
    . 'may not be declared at all');
// The engine's OWN declaration validator, not a restatement of it: a
// declaration this suite calls well-formed must be one negotiation accepts.
$validate = new \ReflectionMethod(\Duo\Providers::class, 'validate_capability_declaration');
$declarationValid = true;
$declarationError = '';
try {
    $validate->invoke(null, $capability, 'woocommerce-product-lookups rebuild_product_lookups');
} catch (\Throwable $t) {
    $declarationValid = false;
    $declarationError = $t->getMessage();
}
check($declarationValid, "the declaration passes the engine's own grammar check ($declarationError)");
check(is_object($adapter) && method_exists($adapter, 'regenerate_batch'),
    'Woo adapter keeps the synchronous regenerate_batch() boundary invoke() maps onto');
check(is_object($adapter) && !method_exists($adapter, 'regenerate'),
    "and drops the regenerator channel's single-id boundary rather than advertising a contract it left");

$source = file_get_contents($providerFile);
check(is_string($source), 'Woo adapter source is readable');
$code = '';
if (is_string($source)) {
    foreach (token_get_all($source) as $token) {
        if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }
        $code .= is_array($token) ? $token[1] : $token;
    }
}
preg_match_all('/checked_get_(?:var|col|row|results)\\s*\\(/', $code, $allCheckedReads);
preg_match_all('/\\\\Duo\\\\ProviderSdk::checked_get_(?:var|col|row|results)\\s*\\(/', $code, $sdkCheckedReads);
check(!preg_match('/\\bfunction\\s+checked_get_(?:var|col|row|results)\\s*\\(/', $code),
    'Woo provider owns no private checked_get_* helper');
check(!str_contains($code, 'assert_runtime_contract'),
    'Woo provider owns no adapter-specific assert_runtime_contract gate');
check(count($allCheckedReads[0] ?? []) > 0
    && count($allCheckedReads[0] ?? []) === count($sdkCheckedReads[0] ?? []),
    'every provider checked read call site uses the generic \\Duo\\ProviderSdk boundary');
check(!preg_match('/\\$this\\s*->\\s*checked_get_(?:var|col|row|results)\\s*\\(/', $code)
    && !preg_match('/\\$wpdb\\s*->\\s*get_(?:var|col|row|results)\\s*\\(/', $code),
    'Woo provider has no private or direct wpdb checked-read call sites left');
$needles = [
    'refresh_product_lookup_table' => 'public product meta lookup refresh API is used',
    'sync_price' => 'variable roots use Woo variable data-store price sync',
    "'product-grouped'" => 'grouped roots use Woo grouped data-store price sync',
    'groupedRoots' => 'grouped roots are deduplicated before synthesis and lookup refresh',
    'refresh_grouped_children_for_sync' => 'grouped children are reloaded after variable price synthesis',
    "meta_key = '_children'" => 'grouped parent discovery is restricted to the _children meta relation',
    'find_grouped_parent_ids' => 'changed children discover grouped roots through a bounded reverse lookup',
    '$wpdb->get_col' => 'grouped reverse discovery reads only candidate parent ids',
    '$wpdb->posts' => 'grouped reverse discovery validates product post candidates in SQL',
    'delete_from_lookup_table' => 'product lookup deletion uses the public delete API',
    'wc_get_attribute_taxonomies' => 'Woo attribute definitions are refreshed through the public API',
    "delete_transient('wc_attribute_taxonomies')" => 'Woo attribute transient is invalidated before regeneration',
    "invalidate_cache_group('woocommerce-attributes')" => 'Woo attribute object-cache group is invalidated',
    'register_taxonomy' => 'new Woo attribute taxonomies are registered for products',
    "'update_count_callback' => '_update_post_term_count'" => 'registered Woo attribute taxonomy keeps its count callback',
    "is_on_sale('edit')" => 'simple active-price selection comes from WooCommerce public sale semantics',
    "get_sale_price('edit')" => 'simple sale-price value comes from WooCommerce public accessors',
    "get_regular_price('edit')" => 'simple regular-price value comes from WooCommerce public accessors',
    'sync_parent_price_from_woocommerce' => 'parent price synthesis has a named WooCommerce-owned boundary',
    "add_filter('delete_post_metadata'" => 'authored parent rows are protected at the public WordPress metadata boundary',
    "query('START TRANSACTION')" => 'parent public sync establishes provider-local failure atomicity after Apply commit',
    "query('ROLLBACK')" => 'a throwing parent sync rolls back its partial derived-price mutation',
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
    'recompute_simple_price' => 'no Duo-authored simple-price sale/date rule',
    'sync_price_preserving_authored_meta' => 'no snapshot/restore copy around Woo parent price synthesis',
    'restore_authored_price_meta' => 'no Duo-authored metadata restore loop around Woo parent price synthesis',
    'expected_attribute_rows' => 'no Duo-authored attribute lookup row synthesis',
    'append_attribute_rows' => 'no Duo-authored attribute lookup row builder',
    'term_slug_ids' => 'no Duo-authored variation term fallback map',
    'create_data_for_product' => 'unsupported attribute lookup rows are not written by the verified provider',
    'ProductAttributesLookup\\LookupDataStore' => 'provider claims no private/internal attribute lookup store contract',
    'attribute_lookup_rows' => 'verified receipt does not observe or imply the unsupported attribute table',
    'table:wc_product_attributes_lookup' => 'provider capability declares no unsupported attribute-table write',
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
check($sources === [
    'native:transient.delete',
    'provider:woocommerce-cache/invalidate_cache_groups',
    'provider:woocommerce-product-lookups/rebuild_product_lookups',
], 'Woo policy declares only the bounded transient, cache, and product-lookup repairs, and no '
    . 'whole-catalog projection');
check(array_filter($actions, static fn(array $row): bool => array_key_exists('command', $row)) === [],
    'no Woo action carries an executable command string');

$lookupAction = $actions[2] ?? [];
check(($lookupAction['triggers'] ?? null) === ['post:product', 'post:product_variation'],
    'the lookup action is narrowed to exactly the two post types the regen_dependency declarations covered');
$effectIds = array_map(static fn(array $e): string => (string) $e['id'], (array) ($lookupAction['effects'] ?? []));
check(count($effectIds) === 106 && count(array_unique($effectIds)) === 106,
    'both post types\' supported effect lists remain distinct (53 + 53) after the unsupported attribute-table effects are removed — the manifest note '
    . 'records why product and variation ids stay separate even where they name the same resource');
check(count(array_filter($effectIds, static fn(string $id): bool => str_starts_with($id, 'woocommerce-product-'))) === 53
    && count(array_filter($effectIds, static fn(string $id): bool => str_starts_with($id, 'woocommerce-variation-'))) === 53,
    'and neither half was dropped or renamed on the way');
$inventory = $policy->effects_inventory();
check(array_filter($inventory, static fn(array $row): bool =>
    $row['manifest'] === 'woocommerce' && $row['phase'] === 'regenerator') === [],
    'the effects inventory now carries them under the rebuild phase of the declaring action, with no '
    . 'orphaned regenerator-phase rows left behind');
check(count(array_filter($inventory, static fn(array $row): bool =>
    $row['source'] === 'provider:woocommerce-product-lookups/rebuild_product_lookups')) === 106,
    'every one of them is attributed to the exact provider capability a recovery operator would re-run');

if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
