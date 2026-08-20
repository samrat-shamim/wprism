<?php
declare(strict_types=1);

// DUO-3225: offline contract inventory for the exact WooCommerce 11.0.0
// fixture. The live conformance suite proves behavior; this fast test keeps a
// future option/table addition from becoming invisible by accident.

define('DUO_SPEC_VERSION', 2);
require dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
require dirname(__DIR__, 4) . '/agent/src/Code/Code.php';
require dirname(__DIR__, 4) . '/agent/src/Policy/Policy.php';

use Duo\Policy;

function woo_fail(string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
function woo_ok(bool $condition, string $message): void {
    if (!$condition) woo_fail($message);
    echo "ok: $message\n";
}

$root = dirname(__DIR__, 4);
$manifest = json_decode((string) file_get_contents($root . '/manifests/woocommerce.json'), true, flags: JSON_THROW_ON_ERROR);
$dispositions = json_decode((string) file_get_contents($root . '/manifests/dispositions.json'), true, flags: JSON_THROW_ON_ERROR);
// No scratch library: $manifest IS manifests/woocommerce.json, so the v2
// shipped-membership proof compares the frozen bytes against the very file they
// were read from — the strongest form of the claim this snapshot makes.
$policy = Policy::from_snapshot([
    'dispositions' => null,
    'format' => 'duo-policy-snapshot/v6',
    'adapter_sources' => ['certificates' => [], 'format' => 'duo-adapter-sources/v2', 'out_of_tree' => []],
    'manifests' => [$manifest],
    'site' => ['manifests' => ['woocommerce'], 'policy' => ['options' => [], 'post_meta' => [], 'term_meta' => [], 'user_meta' => []], 'spec_version' => DUO_SPEC_VERSION],
]);

// DUO-3315: this is a manifest declaration, not an engine convention. The
// generic engine must obtain Woo's product/variation edge through Policy in
// exactly the same way an unrelated adapter obtains its own CPT relationship.
woo_ok(($manifest['post_types']['product']['children'] ?? null) === ['product_variation'],
    'Woo product declares product_variation as its manifest-owned child type');
woo_ok(method_exists($policy, 'child_post_types')
    && method_exists($policy, 'parent_post_types')
    && method_exists($policy, 'post_type_relation_closure'),
    'Policy exposes the generic parent/child relationship APIs used by the engine');
woo_ok($policy->child_post_types('product') === ['product_variation'],
    'Woo product children resolve from the shipped manifest');
woo_ok($policy->parent_post_types('product_variation') === ['product'],
    'Woo variation resolves its manifest-declared parent through the plural inverse API');
woo_ok($policy->post_type_relation_closure(['product_variation']) === ['product', 'product_variation'],
    'Woo relation closure walks the declared edge in both directions');

$optionNames = preg_split('/\s+/', trim(<<<'OPTIONS'
action_scheduler_hybrid_store_demarkation action_scheduler_migration_status
wc_downloads_approved_directories_mode wc_pending_batch_processes
wc_feature_woocommerce_additional_variation_images_enabled
woocommerce_address_autocomplete_enabled woocommerce_admin_install_timestamp woocommerce_admin_notices
woocommerce_all_except_countries woocommerce_allow_bulk_remove_personal_data woocommerce_allow_tracking woocommerce_allowed_countries woocommerce_analytics_enabled
woocommerce_anonymize_completed_orders woocommerce_anonymize_refunded_orders woocommerce_attribute_lookup_direct_updates woocommerce_attribute_lookup_enabled woocommerce_attribute_lookup_optimized_updates
woocommerce_calc_discounts_sequentially woocommerce_calc_taxes woocommerce_cart_page_id woocommerce_cart_redirect_after_add woocommerce_cart_save_for_later_enabled woocommerce_catalog_columns woocommerce_catalog_rows
woocommerce_checkout_address_2_field woocommerce_checkout_company_field woocommerce_checkout_highlight_required_fields woocommerce_checkout_order_received_endpoint woocommerce_checkout_page_id woocommerce_checkout_pay_endpoint woocommerce_checkout_phone_field woocommerce_checkout_privacy_policy_text
woocommerce_cod_settings woocommerce_currency woocommerce_currency_pos woocommerce_custom_orders_table_created woocommerce_custom_orders_table_enabled woocommerce_db_version woocommerce_default_country woocommerce_default_customer_address woocommerce_delete_inactive_accounts woocommerce_demo_store woocommerce_dimension_unit
woocommerce_downloads_add_hash_to_filename woocommerce_downloads_count_partial woocommerce_downloads_deliver_inline woocommerce_downloads_grant_access_after_payment woocommerce_downloads_redirect_fallback_allowed woocommerce_downloads_require_login
woocommerce_email_auto_sync_with_theme woocommerce_email_background_color woocommerce_email_base_color woocommerce_email_body_background_color woocommerce_email_font_family woocommerce_email_footer_text woocommerce_email_footer_text_color woocommerce_email_from_address woocommerce_email_header_alignment woocommerce_email_header_image woocommerce_email_header_image_width woocommerce_email_improvements_disabled_count woocommerce_email_improvements_first_disabled_at woocommerce_email_improvements_last_disabled_at woocommerce_email_reply_to_address woocommerce_email_reply_to_enabled woocommerce_email_reply_to_name woocommerce_email_text_color
woocommerce_enable_ajax_add_to_cart woocommerce_enable_checkout_login_reminder woocommerce_enable_coupons woocommerce_enable_delayed_account_creation woocommerce_enable_guest_checkout woocommerce_enable_myaccount_registration woocommerce_enable_review_rating woocommerce_enable_reviews woocommerce_enable_shipping_calc woocommerce_enable_signup_and_login_from_checkout
woocommerce_erasure_request_removes_download_data woocommerce_erasure_request_removes_order_data
woocommerce_feature_abandoned_cart_recovery_enabled woocommerce_feature_block_email_editor_enabled woocommerce_feature_blueprint_enabled woocommerce_feature_cost_of_goods_sold_enabled woocommerce_feature_customer_review_request_enabled woocommerce_feature_deferred_transactional_emails_enabled woocommerce_feature_destroy-empty-sessions_enabled woocommerce_feature_email_improvements_enabled woocommerce_feature_mcp_integration_enabled woocommerce_feature_order_attribution_enabled woocommerce_feature_product_instance_caching_enabled woocommerce_feature_rate_limit_checkout_enabled woocommerce_feature_remote_logging_enabled woocommerce_feature_rest_api_caching_enabled woocommerce_feature_wc_visual_attribute_enabled
woocommerce_file_download_method woocommerce_force_ssl_checkout woocommerce_hide_out_of_stock_items woocommerce_hold_stock_minutes woocommerce_hooked_blocks_version woocommerce_hpos_datastore_caching_enabled woocommerce_hpos_fts_index_enabled woocommerce_inbox_variant_assignment woocommerce_logout_endpoint woocommerce_manage_stock woocommerce_maxmind_geolocation_settings
woocommerce_myaccount_add_payment_method_endpoint woocommerce_myaccount_delete_payment_method_endpoint woocommerce_myaccount_downloads_endpoint woocommerce_myaccount_edit_account_endpoint woocommerce_myaccount_edit_address_endpoint woocommerce_myaccount_lost_password_endpoint woocommerce_myaccount_orders_endpoint woocommerce_myaccount_page_id woocommerce_myaccount_payment_methods_endpoint woocommerce_myaccount_set_default_payment_method_endpoint woocommerce_myaccount_view_order_endpoint
woocommerce_newly_installed woocommerce_notify_backorder woocommerce_notify_low_stock woocommerce_notify_low_stock_amount woocommerce_notify_no_stock woocommerce_notify_no_stock_amount woocommerce_order_stats_has_fulfillment_column woocommerce_paypal_settings woocommerce_permalinks woocommerce_placeholder_image woocommerce_pickup_location_settings
woocommerce_pos_refund_returns_policy woocommerce_pos_store_address woocommerce_pos_store_email woocommerce_pos_store_phone
woocommerce_price_decimal_sep woocommerce_price_display_suffix woocommerce_price_num_decimals woocommerce_price_thousand_sep woocommerce_prices_include_tax woocommerce_product_match_featured_image_by_sku woocommerce_product_wishlist_enabled woocommerce_queue_flush_rewrite_rules woocommerce_refund_returns_page_id
woocommerce_registration_generate_password woocommerce_registration_generate_username woocommerce_registration_privacy_policy_text woocommerce_remote_variant_assignment woocommerce_review_rating_required woocommerce_review_rating_verification_label woocommerce_review_rating_verification_required woocommerce_schema_version
woocommerce_ship_to_countries woocommerce_ship_to_destination woocommerce_shipping_cost_requires_address woocommerce_shipping_debug_mode woocommerce_shipping_hide_rates_when_free woocommerce_shipping_tax_class woocommerce_shop_page_id woocommerce_show_marketplace_suggestions woocommerce_single_image_width woocommerce_specific_allowed_countries woocommerce_specific_ship_to_countries
woocommerce_stock_email_recipient woocommerce_stock_format woocommerce_store_address woocommerce_store_address_2 woocommerce_store_city woocommerce_store_id woocommerce_store_postcode woocommerce_task_list_tracked_completed_actions woocommerce_task_list_tracked_completed_tasks
woocommerce_tax_based_on woocommerce_tax_classes woocommerce_tax_display_cart woocommerce_tax_display_shop woocommerce_tax_round_at_subtotal woocommerce_tax_total_display woocommerce_terms_page_id woocommerce_thumbnail_image_width woocommerce_trash_cancelled_orders woocommerce_trash_failed_orders woocommerce_trash_pending_orders woocommerce_unforce_ssl_checkout woocommerce_version woocommerce_weight_unit
OPTIONS));

foreach ($optionNames as $name) {
    woo_ok($policy->option_namespace($name) !== null, "$name is discovery-owned");
    woo_ok($policy->owned_option_rule($name) !== null, "$name has an explicit class");
}
woo_ok($policy->option_namespace('woocommerce_future_unreviewed') !== null, 'future Woo option remains visible to discovery');
woo_ok($policy->owned_option_rule('woocommerce_future_unreviewed') === null, 'future Woo option is pending, never silently classified');
foreach (['action_scheduler_migration_status', 'woocommerce_paypal_settings', 'woocommerce_maxmind_geolocation_settings', 'woocommerce_email_from_address', 'woocommerce_stock_email_recipient'] as $name) {
    woo_ok(($policy->owned_option_rule($name)['class'] ?? '') === 'env', "$name stays environment-local");
}
foreach (['wc_pending_batch_processes', 'woocommerce_admin_notices', 'woocommerce_task_list_tracked_completed_tasks', 'woocommerce_unforce_ssl_checkout'] as $name) {
    woo_ok(($policy->owned_option_rule($name)['class'] ?? '') === 'runtime', "$name stays runtime-local");
}
foreach (['woocommerce_catalog_columns', 'woocommerce_catalog_rows', 'woocommerce_cod_settings', 'woocommerce_enable_delayed_account_creation', 'woocommerce_feature_wc_visual_attribute_enabled', 'woocommerce_hooked_blocks_version', 'woocommerce_pickup_location_settings'] as $name) {
    woo_ok(($policy->owned_option_rule($name)['class'] ?? '') === 'authored', "$name stays portable merchant-authored state");
}
foreach (['woocommerce_catalog_columns', 'woocommerce_catalog_rows'] as $name) {
    woo_ok(($policy->owned_option_rule($name)['lint_ok'] ?? false) === true, "$name is audited as a numeric grid count, not an entity reference");
}
woo_ok(($policy->owned_option_rule('woocommerce_cod_settings')['ref'] ?? null) === null, 'core COD settings remain an opaque ref-free settings record');
woo_ok(($policy->owned_option_rule('woocommerce_hooked_blocks_version')['ref'] ?? null) === null, 'hooked-block rendering policy remains an opaque ref-free authored record');
woo_ok(($policy->owned_option_rule('woocommerce_pickup_location_settings')['ref'] ?? null) === null, 'local-pickup merchant settings remain an opaque ref-free authored record');
woo_ok(($policy->option_rule('woocommerce_placeholder_image')['ref'] ?? '') === 'post', 'placeholder image uses portable post identity');
woo_ok(($policy->option_rule('woocommerce_refund_returns_page_id')['ref'] ?? '') === 'post', 'refund page uses portable post identity');
woo_ok(($policy->option_rule('woocommerce_flat_rate_41_settings')['ref'] ?? null) === null
    && $policy->match_option_name_ref('woocommerce_flat_rate_41_settings') !== null,
    'shipping instance settings use option-name embedded typed identity');

$expectedTables = preg_split('/\s+/', trim(<<<'TABLES'
actionscheduler_actions actionscheduler_claims actionscheduler_groups actionscheduler_logs
wc_admin_note_actions wc_admin_notes wc_category_lookup wc_customer_lookup wc_download_log wc_email_unsubscribes wc_order_addresses wc_order_coupon_lookup wc_order_operational_data wc_order_product_lookup wc_order_stats wc_order_tax_lookup wc_orders wc_orders_meta wc_product_attributes_lookup wc_product_download_directories wc_product_meta_lookup wc_rate_limits wc_reserved_stock wc_tax_rate_classes wc_webhooks
woocommerce_api_keys woocommerce_attribute_taxonomies woocommerce_downloadable_product_permissions woocommerce_log woocommerce_order_itemmeta woocommerce_order_items woocommerce_payment_tokenmeta woocommerce_payment_tokens woocommerce_sessions woocommerce_shipping_zone_locations woocommerce_shipping_zone_methods woocommerce_shipping_zones woocommerce_tax_rate_locations woocommerce_tax_rates
TABLES));
$declaredTables = $policy->declared_tables();
foreach ($expectedTables as $table) woo_ok(isset($declaredTables[$table]), "$table has an explicit disposition");
foreach (['wc_product_meta_lookup', 'wc_product_attributes_lookup', 'wc_category_lookup'] as $table) {
    woo_ok(($declaredTables[$table]['class'] ?? '') === 'derived', "$table is rebuilt derived state");
}
woo_ok(($declaredTables['wc_tax_rate_classes']['class'] ?? '') === 'authored_snapshot', 'merchant tax classes are portable authored state');

$actions = $policy->actions();
$actionSources = array_map(
    static fn(array $row): string => \Duo\Policy::action_source($row, (int) $row['index']),
    $actions
);
woo_ok($actionSources === [
    'native:transient.delete',
    'provider:woocommerce-cache/invalidate_cache_groups',
    'provider:woocommerce-product-lookups/rebuild_product_lookups',
], 'manifest keeps only the bounded attribute-transient, shipping/tax cache, and per-product lookup repairs; '
    . 'the whole-catalog projection is not automatic');
// DUO-3342: the third entry is a MIGRATED dispatch, not a new repair. It is
// bounded by the same two post-type triggers the retired regen_dependency
// declarations covered, and those declarations are gone — a manifest carrying
// both would be two dispatchers over one post type, which negotiation refuses.
woo_ok(($actions[2]['triggers'] ?? null) === ['post:product', 'post:product_variation'],
    'the product lookup repair stays bounded to the two product post types it always covered');
woo_ok($policy->regen_batch_post_types() === [] && $policy->regen_dependency('product') === null,
    'and the batch regenerator channel it replaced claims no Woo post type any more');
// DUO-3341: the legacy whole-catalog projection class is deleted outright,
// not quarantined. Engine core must carry no WooCommerce-named production
// source and the bootstrap must not load one; Woo semantics live in
// manifests/woocommerce.json and the provider/native-action contract.
// These assertions fail against the pre-DUO-3341 tree (class present,
// require_once in agent/duo.php), which is this issue's regression proof.
// Recursive since the module move (ROUND 3 TRAIN 1): agent/src is a tree of
// module directories, so a flat scandir() would enumerate module names and let
// both checks below pass for the wrong reason. The deleted class is now looked
// for ANYWHERE under agent/src, which is the claim DUO-3341 actually makes.
$engineSrcEntries = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/agent/src', FilesystemIterator::SKIP_DOTS)) as $engineSrcEntry) {
    if ($engineSrcEntry instanceof SplFileInfo && $engineSrcEntry->isFile() && $engineSrcEntry->getExtension() === 'php') {
        $engineSrcEntries[] = $engineSrcEntry->getFilename();
    }
}
woo_ok(!in_array('WooCommerceContract.php', $engineSrcEntries, true), 'the whole-catalog Woo projection class is deleted from engine core (DUO-3341)');
woo_ok(count($engineSrcEntries) > 2, 'agent/src enumerates non-empty for the WooCommerce-named source scan');
$wooNamedEngineSources = array_values(array_filter(
    $engineSrcEntries,
    static fn(string $name): bool => stripos($name, 'woocommerce') !== false || stripos($name, 'woo') === 0
));
woo_ok($wooNamedEngineSources === [], 'no WooCommerce-named production class remains under agent/src (DUO-3341)');
woo_ok(!str_contains((string) file_get_contents($root . '/agent/duo.php'), 'WooCommerce'), 'agent bootstrap loads no WooCommerce-named engine source (DUO-3341)');
$unsupportedDeletes = [
    'post:product',
    'post:product_variation',
    'table:woocommerce_attribute_taxonomies',
    'table:woocommerce_shipping_zone_locations',
    'table:woocommerce_shipping_zone_methods',
    'table:woocommerce_shipping_zones',
    'table:woocommerce_tax_rate_locations',
    'table:woocommerce_tax_rates',
];
woo_ok(!array_key_exists('deletions', $manifest), 'shipped Woo manifest declares no deletion authority');
foreach ($unsupportedDeletes as $selector) {
    woo_ok($policy->deletion_capability($selector) === null, "$selector deletion is fail-closed");
}
$wooDisposition = $dispositions['manifests']['woocommerce'] ?? [];
woo_ok(!in_array('delete', $wooDisposition['capabilities']['operations'] ?? [], true), 'external capability registry does not advertise Woo deletion');
woo_ok(($wooDisposition['capabilities']['deletion_semantics']['supported'] ?? null) === [], 'external capability registry declares no supported Woo deletion surface');
$declaredUnsupportedDeletes = $wooDisposition['capabilities']['deletion_semantics']['unsupported'] ?? null;
woo_ok($declaredUnsupportedDeletes === $unsupportedDeletes,
    'external capability registry enumerates every shipped Woo deletion selector as unsupported');
$unsupportedApplySurfaces = array_values(array_filter(
    (array) ($wooDisposition['unsupported'] ?? []),
    static fn(array $row): bool => ($row['operation'] ?? null) === 'apply'
));
woo_ok(array_column($unsupportedApplySurfaces, 'surface') === [
    'derived.wc_category_lookup',
    'derived.wc_product_attributes_lookup',
], 'external capability registry makes the two Woo derived tables without bounded independent value oracles explicit');
$attributeLookupBoundary = $unsupportedApplySurfaces[1] ?? [];
woo_ok(str_contains((string) ($attributeLookupBoundary['reason'] ?? ''), 'does not mutate this table')
    && str_contains((string) ($attributeLookupBoundary['reason'] ?? ''), 'explicitly'),
    'attribute lookup disposition forbids best-effort mutation under a verified receipt and names manual repair');
$matrixHarness = (string) file_get_contents($root . '/sandbox/tests/certify/certify_version_matrix.sh');
woo_ok(str_contains($matrixHarness, 'update_option("default_category", (int) $category->term_id)'), 'version-matrix resets the core default-category reference before each plugin boundary');

echo "PASS: WooCommerce 11.x option/table inventory and rebuild contract are explicit\n";
