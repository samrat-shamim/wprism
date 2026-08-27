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
    define('DUO_SPEC_VERSION', 3);
}
$root = dirname(__DIR__, 4);
putenv('DUO_MANIFESTS_DIR=' . $root . '/manifests');

require $root . '/agent/src/Kernel/Canon.php';
require $root . '/agent/src/Kernel/OptionState.php';
require $root . '/agent/src/Policy/Policy.php';
require $root . '/agent/src/Adapter/Providers.php';

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
    && $declaration['version'] === '3.0.0'
    && $declaration['plugin'] === 'woocommerce/woocommerce.php'
    && $declaration['capabilities'] === ['rebuild_product_lookups'],
    'the lookup repair is declared as a manifest-sourced provider pinned to the same plugin the manifest claims');
$expectedRequires = [
    'functions' => [
        'wc_get_product',
        'wc_get_filename_from_url',
        'wc_get_container',
        'wc_get_attribute_taxonomies',
        'wc_attribute_taxonomy_name',
        'get_option',
        'wp_parse_args',
        'untrailingslashit',
        'add_filter',
        'remove_filter',
        'get_post_meta',
        'delete_post_meta',
        'add_post_meta',
        'wc_maybe_schedule_product_sale_events',
        'as_unschedule_all_actions',
        'as_get_scheduled_actions',
        'as_next_scheduled_action',
        'wp_cache_get',
        'wp_cache_delete',
        'wp_add_object_terms',
        'wp_remove_object_terms',
    ],
    'classes' => [
        'Automattic\\WooCommerce\\Internal\\CostOfGoodsSold\\CostOfGoodsSoldController',
        'Automattic\\WooCommerce\\Internal\\ProductDownloads\\ApprovedDirectories\\Register',
        'Automattic\\WooCommerce\\Internal\\ProductAttributesLookup\\LookupDataStore',
        'Automattic\\WooCommerce\\Internal\\Utilities\\URL',
        'Automattic\\WooCommerce\\Utilities\\NumberUtil',
        'WC_Data_Store',
        'WC_Cache_Helper',
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
$adapter = new \Duo\Providers\WoocommerceProductLookups($declaration);
check($adapter->identity() === [
    'id' => 'woocommerce-product-lookups',
    'plugin' => 'woocommerce/woocommerce.php',
    'version' => '3.0.0',
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
check(str_contains($code, '\\Duo\\PlainData::decode_serialized(')
    && !str_contains($code, 'maybe_unserialize('),
    'raw downloadable metadata crosses the shared class-disabled plain-data boundary before native product hooks');
$needles = [
    'refresh_product_lookup_table' => 'public product meta lookup refresh API is used',
    'sync_price' => 'variable roots use Woo variable data-store price sync',
    "'product-grouped'" => 'grouped roots use Woo grouped data-store price sync',
    'groupedRoots' => 'grouped roots are deduplicated before synthesis and lookup refresh',
    'refresh_grouped_children_for_sync' => 'grouped children are reloaded after variable price synthesis',
    "meta_key = '_children'" => 'grouped parent discovery is restricted to the _children meta relation',
    'find_grouped_parent_ids' => 'changed children discover grouped roots through a bounded reverse lookup',
    '\\Duo\\ProviderSdk::checked_get_col' => 'grouped reverse discovery reads only candidate parent ids through the generic provider SDK',
    '$wpdb->posts' => 'grouped reverse discovery validates product post candidates in SQL',
    'delete_from_lookup_table' => 'product lookup deletion uses the public delete API',
    'wc_get_attribute_taxonomies' => 'Woo attribute definitions are refreshed through the public API',
    "delete_transient('wc_attribute_taxonomies')" => 'Woo attribute transient is invalidated before regeneration',
    "invalidate_cache_group('woocommerce-attributes')" => 'Woo attribute object-cache group is invalidated',
    "is_callable(['\\\\WC_Cache_Helper', 'invalidate_cache_group'])" => 'Woo cache helper method availability fails closed beyond the declared class requirement',
    'register_taxonomy' => 'new Woo attribute taxonomies are registered for products',
    "'update_count_callback' => '_update_post_term_count'" => 'registered Woo attribute taxonomy keeps its count callback',
    'attribute_public' => 'attribute registration branches on Woo\'s authored public flag',
    'isset($attribute->attribute_public) ? $attribute->attribute_public : 1' => 'legacy Woo attributes default to public exactly as WooCommerce does',
    'woocommerce_taxonomy_objects_{$taxonomy}' => 'Woo taxonomy object types remain filterable by taxonomy',
    'woocommerce_taxonomy_args_{$taxonomy}' => 'Woo taxonomy args remain filterable by taxonomy',
    'woocommerce_attribute_show_in_nav_menus' => 'public Woo attributes retain the nav-menu filter seam',
    "get_option('woocommerce_permalinks', [])" => 'public Woo attributes read the reviewed permalink setting without persisting defaults',
    'wp_parse_args' => 'the option-write-free projection applies exact Woo 11.0.x permalink defaults',
    'untrailingslashit' => 'the attribute rewrite base mirrors Woo permalink normalization',
    'sanitize_title' => 'public Woo attribute rewrites use Woo slug sanitization',
    'trailingslashit' => 'public Woo attribute rewrites preserve Woo trailing-slash composition',
    "'show_in_quick_edit' => false" => 'attribute taxonomy quick edit stays disabled like WooCommerce',
    "'show_in_menu' => false" => 'attribute taxonomy menu stays disabled like WooCommerce',
    "'meta_box_cb' => false" => 'attribute taxonomy meta box stays disabled like WooCommerce',
    "'query_var' => 1 ===" => 'attribute queryability follows attribute_public exactly',
    "'rewrite' => false" => 'non-public attributes explicitly disable rewrites',
    "'sort' => false" => 'attribute taxonomy sorting stays disabled like WooCommerce',
    "'public' => 1 ===" => 'attribute taxonomy public visibility follows attribute_public exactly',
    "'show_in_nav_menus' => 1 ===" => 'attribute nav visibility follows public status and Woo filter output',
    "'capabilities' =>" => 'attribute taxonomy capabilities stay on Woo product-term capabilities',
    "is_on_sale('edit')" => 'simple active-price selection comes from WooCommerce public sale semantics',
    "get_sale_price('edit')" => 'simple sale-price value comes from WooCommerce public accessors',
    "get_regular_price('edit')" => 'simple regular-price value comes from WooCommerce public accessors',
    'sync_parent_price_from_woocommerce' => 'parent price synthesis has a named WooCommerce-owned boundary',
    "add_filter('delete_post_metadata'" => 'authored parent rows are protected at the public WordPress metadata boundary',
    "query('START TRANSACTION')" => 'parent public sync establishes provider-local failure atomicity after Apply commit',
    "query('ROLLBACK')" => 'a throwing parent sync rolls back its partial derived-price mutation',
    'wc_maybe_schedule_product_sale_events' => 'sale actions use Woo public per-product scheduling',
    'as_unschedule_all_actions' => 'deleted sale actions use bounded public unscheduling',
    'as_get_scheduled_actions' => 'sale action verification detects duplicate active rows with bounded public queries',
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
    // refresh. Pin the order separately below: the refresh runs with a
    // cleared cache, so it always REPLACEs, and a row read only afterwards
    // would be one this verification had just written. The sibling-variation
    // case below covers the separate DUO-3373 finite-set decision.
    'read_lookup_row($table, $id)' => 'the stored-row read has a single named boundary',
    'create_data_for_product($root, false)' => 'attribute rows use Woo public synchronous scoped synthesis',
    'get_last_create_operation_failed' => 'Woo native attribute insert failures remain loud and retryable',
    'observe_attribute_lookup_state' => 'exact scoped attribute rows are independently read and receipt-bound',
    'observe_cogs_state' => 'authored Cost of Goods rows and native feature/schema state are receipt-bound',
    'feature_is_enabled' => 'COGS support is admitted only through WooCommerce native feature state',
    'product_meta_lookup_table_cogs_value_columns_exist' => 'COGS lookup verification requires the exact native schema check',
    "meta_key IN ('_cogs_total_value', '_cogs_value_is_additive')" => 'COGS observation is bounded to the two exact core-authored keys',
    'assert_cogs_product_types' => 'COGS receipt observation enforces native subtype-dependent storage invariants',
    'SELECT ID, post_type FROM {$wpdb->posts}' => 'COGS subtype evidence independently binds the exact posts-table owner type',
    '$product = \\wc_get_product($id)' => 'COGS subtype evidence resolves every authored owner through the native WC product factory',
    '$value !== (string) $number' => 'COGS repository bytes are restricted to exact native float-writer spellings',
    'variation-only additive Cost of Goods metadata' => 'post-materialization additive state cannot be certified on a base product',
    'Cost of Goods zero that native storage deletes' => 'post-materialization base zero cannot be certified by a scoped receipt',
    "tt.taxonomy IN ('product_type', 'product_visibility', 'pos_product_visibility')" => 'visibility inventory reads the whole native type/visibility taxonomies instead of filtering unknown slugs away',
    'native visibility-term inventory exceeds exact core cardinality' => 'the whole inventory has a strict four-type-plus-nine-visibility-plus-optional-POS bound',
    'assert_product_type_projection' => 'every product and variation parent binds exact authored core product_type identity',
    '$this->assert_visibility_projection($after, null, null)' => 'final verification recomputes native stock/rating/type/downloadable/POS projection from its fresh snapshot',
    'MERCHANT_VISIBILITY_TERMS' => 'merchant featured/catalog relationships have an explicit immutable subset',
    'DERIVED_VISIBILITY_TERMS' => 'only stock/rating relationships enter root native repair',
    'wp_remove_object_terms' => 'derived visibility removals use the exact native incremental writer',
    'wp_add_object_terms' => 'derived visibility additions use the exact native incremental writer',
    'supported-root POS merchant intent changed before repair' => 'supported-root POS intent is validated rather than replaced from a stale snapshot',
];
foreach ($needles as $needle => $message) {
    check(is_string($source) && str_contains($source, $needle), $message);
}
check(!str_contains($code, 'wc_get_permalink_structure')
    && !str_contains($code, "update_option('woocommerce_permalinks'"),
    'late taxonomy repair cannot normalize or persist the unrelated Woo permalink option');
$verifyStart = is_string($source) ? strpos($source, 'private function verify_meta_row') : false;
$beforeRead = $verifyStart !== false ? strpos($source, '$applied = $this->read_lookup_row($table, $id);', $verifyStart) : false;
$forcedRefresh = $verifyStart !== false ? strpos($source, '$derived = $this->woo_republished_lookup_row($productStore, $id);', $verifyStart) : false;
check($verifyStart !== false && $beforeRead !== false && $forcedRefresh !== false && $beforeRead < $forcedRefresh,
    'lookup verification snapshots the apply row before Woo re-derives it');
// The retired rebuild of Woo's lookup columns, stated as absences so a future
// change cannot quietly reintroduce a second, drifting copy of rules that live
// in a protected WooCommerce method. Deliberately narrow: wc_format_decimal()
// is the RIGHT helper for any future price math here, so its absence is not a
// property worth pinning.
$retired = [
    'lookup_values_equal' => 'no Duo-authored per-column tolerance table for lookup values',
    "get_option('woocommerce_schema_version'" => 'no copied global_unique_id schema-version gate',
    'recompute_simple_price' => 'no Duo-authored simple-price sale/date rule',
    'sync_price_preserving_authored_meta' => 'no snapshot/restore copy around Woo parent price synthesis',
    'restore_authored_price_meta' => 'no Duo-authored metadata restore loop around Woo parent price synthesis',
    'expected_attribute_rows' => 'no Duo-authored attribute lookup row synthesis',
    'append_attribute_rows' => 'no Duo-authored attribute lookup row builder',
    'term_slug_ids' => 'no Duo-authored variation term fallback map',
    'wp_set_post_terms' => 'no full-taxonomy writer can overwrite concurrent merchant visibility intent',
];
foreach ($retired as $needle => $message) {
    check(is_string($source) && !str_contains($source, $needle), $message);
}
check(is_string($source) && !str_contains($code, 'generate_lookup_cogs_columns'),
    'the bounded product provider never starts WooCommerce whole-catalog COGS schema migration');
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
    'provider:woocommerce-hierarchy-lookups/rebuild_hierarchy_lookups',
    'provider:woocommerce-hierarchy-lookups/rebuild_hierarchy_lookups',
    'provider:woocommerce-fulfillment-prerequisites/verify_fulfillment_prerequisites',
    'provider:woocommerce-scheduler-settings/reconcile_analytics_import_schedule',
    'provider:woocommerce-scheduler-settings/reconcile_stock_notification_retention',
    'provider:woocommerce-product-lookups/rebuild_product_lookups',
    'native:rewrite.flush',
    'provider:woocommerce-hierarchy-lookups/rebuild_product_permalink_routes',
], 'Woo policy declares only the bounded transient, cache, hierarchy/route, fulfillment prerequisite, scheduler-setting, '
    . 'product-lookup, and authored product-permalink repairs, and no whole-catalog projection');
check(array_filter($actions, static fn(array $row): bool => array_key_exists('command', $row)) === [],
    'no Woo action carries an executable command string');

$lookupActions = array_values(array_filter(
    $actions,
    static fn(array $row): bool => ($row['provider'] ?? null) === 'woocommerce-product-lookups'
));
$lookupAction = $lookupActions[0] ?? [];
check(($lookupAction['triggers'] ?? null) === ['post:product', 'post:product_variation'],
    'the lookup action is narrowed to exactly the two post types the regen_dependency declarations covered');
$effectIds = array_map(static fn(array $e): string => (string) $e['id'], (array) ($lookupAction['effects'] ?? []));
check(count($effectIds) === 128 && count(array_unique($effectIds)) === 128,
    'both post types\' supported effect lists remain distinct (64 + 64), including exact visibility, attribute lookup, approved-directory repair, bounded late taxonomy registration filters, and permalink reads — the manifest note '
        . 'records why product and variation ids stay separate even where they name the same resource');
check(count(array_filter($effectIds, static fn(string $id): bool => str_starts_with($id, 'woocommerce-product-'))) === 64
    && count(array_filter($effectIds, static fn(string $id): bool => str_starts_with($id, 'woocommerce-variation-'))) === 64,
    'and neither half was dropped or renamed on the way');
$registrationFilterSelector = [
    'scope' => 'external',
    'type' => 'provider_resource',
    'value' => 'woocommerce-attribute-taxonomy-registration-filters:v1',
];
foreach (['product', 'variation'] as $kind) {
    $byId = [];
    foreach ((array) ($lookupAction['effects'] ?? []) as $effect) {
        $byId[(string) ($effect['id'] ?? '')] = $effect;
    }
    check(($byId["woocommerce-$kind-attribute-nav-menu-filter"]['selector'] ?? null) === [
        'scope' => 'external',
        'type' => 'hook',
        'value' => 'woocommerce_attribute_show_in_nav_menus',
    ], "the $kind action declares Woo's exact public attribute nav-menu filter callback");
    check(($byId["woocommerce-$kind-attribute-taxonomy-registration-filters"]['selector'] ?? null)
        === $registrationFilterSelector,
        "the $kind action bounds every valid Woo taxonomy object/args callback, including multibyte slugs, as one provider-owned filter-family resource");
    check(($byId["woocommerce-$kind-permalink-pre-option-filter"]['selector']['value'] ?? null)
            === 'pre_option_woocommerce_permalinks'
        && ($byId["woocommerce-$kind-permalink-pre-option-generic-filter"]['selector']['value'] ?? null)
            === 'pre_option'
        && ($byId["woocommerce-$kind-permalink-option-filter"]['selector']['value'] ?? null)
            === 'option_woocommerce_permalinks'
        && ($byId["woocommerce-$kind-permalink-default-option-filter"]['selector']['value'] ?? null)
            === 'default_option_woocommerce_permalinks',
        "the $kind action declares the complete option-write-free WordPress option-read callback boundary");
}
$inventory = $policy->effects_inventory();
check(array_filter($inventory, static fn(array $row): bool =>
    $row['manifest'] === 'woocommerce' && $row['phase'] === 'regenerator') === [],
    'the effects inventory now carries them under the rebuild phase of the declaring action, with no '
    . 'orphaned regenerator-phase rows left behind');
check(count(array_filter($inventory, static fn(array $row): bool =>
    $row['source'] === 'provider:woocommerce-product-lookups/rebuild_product_lookups')) === 128,
    'every one of them is attributed to the exact provider capability a recovery operator would re-run');

if ($failures > 0) {
    echo "FAIL: $failures check(s) failed\n";
    exit(1);
}
echo "ALL PASSED\n";
