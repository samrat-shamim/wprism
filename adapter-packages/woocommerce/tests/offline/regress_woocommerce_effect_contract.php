<?php
declare(strict_types=1);

/**
 * Offline WooCommerce rollback-effect contract regression.
 *
 * The committed Woo manifest owns lifecycle, bounded attribute/shipping/tax
 * cache actions, and product/variation lookup plus sale-action
 * regeneration. This test pins the exact database-checkpoint and external-
 * cache surfaces for all of them.
 *
 * This test never boots WordPress or loads the Woo adapter. It proves policy
 * compilation, the bounded runtime selector grammar used by EffectBundle's
 * observer, and automatic-profile refusal against an irreversible row.
 */

define('WPRISM_SPEC_VERSION', 3);
$repoRoot = dirname(__DIR__, 4);
require dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
require dirname(__DIR__, 4) . '/agent/src/Kernel/OptionState.php';
require dirname(__DIR__, 4) . '/agent/src/Policy/Policy.php';
require dirname(__DIR__, 4) . '/recovery/rollback-control.php';
require dirname(__DIR__, 4) . '/sandbox/tests/lib/frozen_policy.php';

use WPrism\Policy;
use WPrismTest\FrozenPolicy;
use WPrism\Recovery\EffectBundle;
use WPrism\Recovery\RecoveryExecutor;
use WPrism\Recovery\RollbackControl;

$failures = 0;

function woo_effect_check(bool $condition, string $message): void {
    global $failures;
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
}

function woo_effect_expect_throw(callable $callback, string $needle, string $message): void {
    global $failures;
    try {
        $callback();
        echo "FAIL: $message (did not throw)\n";
        $failures++;
    } catch (Throwable $exception) {
        $ok = str_contains($exception->getMessage(), $needle);
        woo_effect_check($ok, $message . ($ok ? '' : ' (message: ' . $exception->getMessage() . ')'));
    }
}

/** @return array<string,mixed> */
function woo_effect_db_table(string $id, string $table): array {
    return [
        'id' => $id,
        'kind' => 'database',
        'mode' => 'restorable',
        'selector' => [
            'scope' => 'database_checkpoint',
            'type' => 'table',
            'value' => $table,
        ],
    ];
}

/** @return array<string,mixed> */
function woo_effect_db_option(string $id, string $option): array {
    return [
        'id' => $id,
        'kind' => 'database',
        'mode' => 'restorable',
        'selector' => [
            'scope' => 'database_checkpoint',
            'type' => 'option',
            'value' => $option,
        ],
    ];
}

/** @return array<string,mixed> */
function woo_effect_irreversible(string $id, string $kind, string $type, string $value, ?array $members = null): array {
    $selector = [
        'scope' => 'external',
        'type' => $type,
        'value' => $value,
    ];
    if ($members !== null) {
        $selector['members'] = $members;
    }
    return [
        'id' => $id,
        'kind' => $kind,
        'mode' => 'irreversible',
        'selector' => $selector,
    ];
}

/** @return array<string,mixed> */
function woo_effect_lifecycle(string $id = 'woocommerce-plugin-lifecycle'): array {
    return woo_effect_irreversible($id, 'external', 'plugin_lifecycle', 'woocommerce/woocommerce.php');
}

/** @return list<array<string,mixed>> */
function woo_effect_lifecycle_settlement(): array {
    return [
        woo_effect_db_table('woocommerce-lifecycle-migration-options', 'options'),
        woo_effect_db_table('woocommerce-lifecycle-migration-actions', 'actionscheduler_actions'),
        woo_effect_db_table('woocommerce-lifecycle-migration-claims', 'actionscheduler_claims'),
        woo_effect_db_table('woocommerce-lifecycle-migration-groups', 'actionscheduler_groups'),
        woo_effect_db_table('woocommerce-lifecycle-migration-logs', 'actionscheduler_logs'),
    ];
}

/** @return array<string,mixed> */
function woo_effect_hook(string $id, string $hook): array {
    return woo_effect_irreversible($id, 'external', 'hook', $hook);
}

/** @return list<array<string,mixed>> */
function woo_effect_rewrite(string $prefix): array {
    return [
        woo_effect_db_option($prefix . '-rewrite-rules', 'rewrite_rules'),
        // TEC's exact Cache_Listener updates these three timestamps while
        // WordPress generates and persists rewrite_rules, then may delete an
        // unbounded set of expired tribe_* transient rows at request shutdown.
        // The table checkpoint is therefore the rollback boundary; the
        // request-local purge flag remains an irreversible external receipt.
        woo_effect_db_table($prefix . '-tec-expired-transient-options', 'options'),
        woo_effect_db_option($prefix . '-tec-last-generate-rewrite-rules', 'tribe_last_generate_rewrite_rules'),
        woo_effect_db_option($prefix . '-tec-last-save-post', 'tribe_last_save_post'),
        woo_effect_db_option($prefix . '-tec-last-updated-option', 'tribe_last_updated_option'),
        woo_effect_irreversible(
            $prefix . '-rewrite-rules-cache',
            'cache',
            'provider_resource',
            'wordpress-option:v1:rewrite_rules'
        ),
        woo_effect_irreversible(
            $prefix . '-tec-cache-purge-request',
            'cache',
            'provider_resource',
            'the-events-calendar-cache-purge-request:v1'
        ),
        woo_effect_hook($prefix . '-permalink-pre-option-filter', 'pre_option_permalink_structure'),
        woo_effect_hook($prefix . '-permalink-pre-option-generic-filter', 'pre_option'),
        woo_effect_hook($prefix . '-permalink-option-filter', 'option_permalink_structure'),
        woo_effect_hook($prefix . '-permalink-default-option-filter', 'default_option_permalink_structure'),
        woo_effect_hook($prefix . '-generate-rewrite-hook', 'generate_rewrite_rules'),
        woo_effect_hook($prefix . '-rewrite-rules-array-filter', 'rewrite_rules_array'),
        woo_effect_hook($prefix . '-rewrite-sanitize-filter', 'sanitize_option_rewrite_rules'),
        woo_effect_hook($prefix . '-rewrite-option-filter', 'option_rewrite_rules'),
        woo_effect_hook($prefix . '-rewrite-pre-update-filter', 'pre_update_option_rewrite_rules'),
        woo_effect_hook($prefix . '-rewrite-pre-update-generic-filter', 'pre_update_option'),
        woo_effect_hook($prefix . '-rewrite-update-option-hook', 'update_option'),
        woo_effect_hook($prefix . '-rewrite-update-specific-hook', 'update_option_rewrite_rules'),
        woo_effect_hook($prefix . '-rewrite-updated-option-hook', 'updated_option'),
    ];
}

/** @return list<array<string,mixed>> */
function woo_effect_product_permalink_route(): array {
    $effects = woo_effect_rewrite('woocommerce-product-route');
    array_splice($effects, 7, 0, [
        woo_effect_irreversible('woocommerce-product-route-options-cache', 'cache', 'namespace', 'options'),
    ]);
    return [
        ...$effects,
        woo_effect_hook('woocommerce-product-route-permalink-allowed-protocols-filter', 'kses_allowed_protocols'),
        woo_effect_hook('woocommerce-product-route-permalink-clean-url-filter', 'clean_url'),
    ];
}

/** @return list<array<string,mixed>> */
function woo_effect_product_transient_options(string $prefix): array {
    return [
        woo_effect_db_option($prefix . '-onsale-transient', '_transient_wc_products_onsale'),
        woo_effect_db_option($prefix . '-onsale-transient-timeout', '_transient_timeout_wc_products_onsale'),
        woo_effect_db_option($prefix . '-featured-transient', '_transient_wc_featured_products'),
        woo_effect_db_option($prefix . '-featured-transient-timeout', '_transient_timeout_wc_featured_products'),
        woo_effect_db_option($prefix . '-outofstock-transient', '_transient_wc_outofstock_count'),
        woo_effect_db_option($prefix . '-outofstock-transient-timeout', '_transient_timeout_wc_outofstock_count'),
        woo_effect_db_option($prefix . '-lowstock-transient', '_transient_wc_low_stock_count'),
        woo_effect_db_option($prefix . '-lowstock-transient-timeout', '_transient_timeout_wc_low_stock_count'),
        woo_effect_db_option($prefix . '-transient-version', '_transient_product-transient-version'),
        woo_effect_db_option($prefix . '-transient-version-timeout', '_transient_timeout_product-transient-version'),
        woo_effect_db_option($prefix . '-attribute-transient-value', '_transient_wc_attribute_taxonomies'),
        woo_effect_db_option($prefix . '-attribute-transient-timeout', '_transient_timeout_wc_attribute_taxonomies'),
    ];
}

/** @return array{exact:list<string>,templates:list<string>} */
function woo_effect_product_cache_members(): array {
    return [
        'exact' => [
            'wc_products_onsale',
            'wc_featured_products',
            'wc_outofstock_count',
            'wc_low_stock_count',
            'product-transient-version',
            'wc_attribute_taxonomies',
            'posts:last_changed',
            'terms:last_changed',
            'general:wp_get_archives',
            'woocommerce-product-cache-event:v1:transient=wc_products_onsale',
            'woocommerce-product-cache-event:v1:transient=wc_featured_products',
            'woocommerce-product-cache-event:v1:transient=wc_outofstock_count',
            'woocommerce-product-cache-event:v1:transient=wc_low_stock_count',
            'woocommerce-product-cache-event:v1:transient=product-transient-version',
            'woocommerce-product-cache-event:v1:transient=wc_attribute_taxonomies',
            'woocommerce-product-cache-event:v1:cache_group=posts;key=last_changed',
            'woocommerce-product-cache-event:v1:cache_group=terms;key=last_changed',
            'woocommerce-product-cache-event:v1:cache_group=general;key=wp_get_archives',
        ],
        'templates' => [
            'product_{positive_uint}',
            'object_{positive_uint}',
            'products:{positive_uint}',
            'product_objects:{positive_uint}',
            'woocommerce-product-cache-event:v1:group=product;id={positive_uint}',
            'woocommerce-product-cache-event:v1:group=object;id={positive_uint}',
            'woocommerce-product-cache-event:v1:group=products;id={positive_uint}',
            'woocommerce-product-cache-event:v1:group=product_objects;id={positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=product;key={positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=object;key={positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=products;key={positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=product_objects;key={positive_uint}',
            'wc_product_children_{positive_uint}',
            'wc_var_prices_{positive_uint}',
            'wc_related_{positive_uint}',
            'wc_child_has_weight_{positive_uint}',
            'wc_child_has_dimensions_{positive_uint}',
            'wc_layered_nav_counts_pa_{slug}',
            'woocommerce-product-cache-event:v1:transient=wc_product_children_{positive_uint}',
            'woocommerce-product-cache-event:v1:transient=wc_var_prices_{positive_uint}',
            'woocommerce-product-cache-event:v1:transient=wc_related_{positive_uint}',
            'woocommerce-product-cache-event:v1:transient=wc_child_has_weight_{positive_uint}',
            'woocommerce-product-cache-event:v1:transient=wc_child_has_dimensions_{positive_uint}',
            'woocommerce-product-cache-event:v1:transient=wc_layered_nav_counts_pa_{slug}',
            'posts:{positive_uint}',
            'posts:post_parent:{positive_uint}',
            'post_meta:{positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=posts;key={positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=posts;key=post_parent:{positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=post_meta;key={positive_uint}',
            'product_type_relationships:{positive_uint}',
            'product_visibility_relationships:{positive_uint}',
            'product_cat_relationships:{positive_uint}',
            'product_brand_relationships:{positive_uint}',
            'product_tag_relationships:{positive_uint}',
            'product_shipping_class_relationships:{positive_uint}',
            'pa_{slug}_relationships:{positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=product_type_relationships;key={positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=product_visibility_relationships;key={positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=product_cat_relationships;key={positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=product_brand_relationships;key={positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=product_tag_relationships;key={positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=product_shipping_class_relationships;key={positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=pa_{slug}_relationships;key={positive_uint}',
        ],
    ];
}

function woo_effect_product_cache_aggregate(string $id): array {
    return woo_effect_irreversible(
        $id,
        'external',
        'provider_resource',
        'woocommerce-product-cache-aggregate:v1:groups=product,object,products,product_objects;fixed=wc_products_onsale,wc_featured_products,wc_outofstock_count,wc_low_stock_count;specific=wc_product_children,wc_var_prices,wc_related,wc_child_has_weight,wc_child_has_dimensions;version=product-transient-version;attribute_registry=wc_attribute_taxonomies;layered_nav=wc_layered_nav_counts;wp_clean=posts,post_meta,terms,general;wp_clean_dynamic=post_parent,taxonomy_relationships',
        woo_effect_product_cache_members()
    );
}

/** @return list<array<string,mixed>> */
function woo_effect_product_fixed_transient_hooks(string $prefix): array {
    return [
        woo_effect_hook($prefix . '-delete-onsale-transient-hook', 'delete_transient_wc_products_onsale'),
        woo_effect_hook($prefix . '-delete-featured-transient-hook', 'delete_transient_wc_featured_products'),
        woo_effect_hook($prefix . '-delete-outofstock-transient-hook', 'delete_transient_wc_outofstock_count'),
        woo_effect_hook($prefix . '-delete-lowstock-transient-hook', 'delete_transient_wc_low_stock_count'),
        woo_effect_hook($prefix . '-deleted-transient-hook', 'deleted_transient'),
    ];
}

/** @return list<array<string,mixed>> */
function woo_effect_product_attribute_refresh_hooks(string $prefix): array {
    return [
        woo_effect_hook($prefix . '-attribute-delete-transient-hook', 'delete_transient_wc_attribute_taxonomies'),
        woo_effect_hook($prefix . '-attribute-pre-transient-filter', 'pre_transient_wc_attribute_taxonomies'),
        woo_effect_hook($prefix . '-attribute-transient-filter', 'transient_wc_attribute_taxonomies'),
        woo_effect_hook($prefix . '-attribute-pre-set-transient-filter', 'pre_set_transient_wc_attribute_taxonomies'),
        woo_effect_hook($prefix . '-attribute-expiration-transient-filter', 'expiration_of_transient_wc_attribute_taxonomies'),
        woo_effect_hook($prefix . '-attribute-set-transient-hook', 'set_transient_wc_attribute_taxonomies'),
        woo_effect_hook($prefix . '-attribute-taxonomies-filter', 'woocommerce_attribute_taxonomies'),
        woo_effect_hook($prefix . '-attribute-nav-menu-filter', 'woocommerce_attribute_show_in_nav_menus'),
        woo_effect_hook($prefix . '-permalink-pre-option-filter', 'pre_option_woocommerce_permalinks'),
        woo_effect_hook($prefix . '-permalink-pre-option-generic-filter', 'pre_option'),
        woo_effect_hook($prefix . '-permalink-option-filter', 'option_woocommerce_permalinks'),
        woo_effect_hook($prefix . '-permalink-default-option-filter', 'default_option_woocommerce_permalinks'),
        woo_effect_hook($prefix . '-attribute-deleted-transient-hook', 'deleted_transient'),
        woo_effect_hook($prefix . '-attribute-set-transient-generic-hook', 'set_transient'),
        woo_effect_hook($prefix . '-attribute-setted-transient-hook', 'setted_transient'),
    ];
}

/** @return array<string,mixed> */
function woo_effect_attribute_registration_filter_family(string $prefix): array {
    return woo_effect_irreversible(
        $prefix . '-attribute-taxonomy-registration-filters',
        'external',
        'provider_resource',
        'woocommerce-attribute-taxonomy-registration-filters:v1'
    );
}

/** @return list<array<string,mixed>> */
function woo_effect_sale_schedule_hooks(string $prefix): array {
    return [
        woo_effect_hook($prefix . '-sale-pre-schedule-filter', 'pre_as_schedule_single_action'),
        woo_effect_hook($prefix . '-sale-stored-action-hook', 'action_scheduler_stored_action'),
        woo_effect_hook($prefix . '-sale-canceled-action-hook', 'action_scheduler_canceled_action'),
        woo_effect_hook($prefix . '-sale-stored-action-class-filter', 'action_scheduler_stored_action_class'),
        woo_effect_hook($prefix . '-sale-stored-action-instance-filter', 'action_scheduler_stored_action_instance'),
        woo_effect_hook($prefix . '-sale-failed-fetch-action-hook', 'action_scheduler_failed_fetch_action'),
    ];
}

/**
 * The product adapter writes/repairs these database surfaces and clears
 * product caches. Woo 11's dynamic cache families and callback boundaries
 * are intentionally provider resources/hooks: no provider inverse/readback
 * contract exists for them yet.
 *
 * @return list<array<string,mixed>>
 */
function woo_effect_product_regenerator(string $postType): array {
    $prefix = $postType === 'product' ? 'woocommerce-product' : 'woocommerce-variation';
    $readHookPrefix = $postType === 'product' ? $prefix : $prefix . '-product';
    $effects = [
        woo_effect_db_table($prefix . '-posts', 'posts'),
        woo_effect_db_table($prefix . '-postmeta', 'postmeta'),
        woo_effect_db_table($prefix . '-visibility-relationships', 'term_relationships'),
        woo_effect_db_table($prefix . '-visibility-term-counts', 'term_taxonomy'),
        woo_effect_irreversible(
            $prefix . '-visibility-native-writer',
            'external',
            'provider_resource',
            'woocommerce-product-visibility-writer:v2:wp-add-remove-object-terms,term-count-hooks,relationship-caches'
        ),
        woo_effect_db_table($prefix . '-download-directories', 'wc_product_download_directories'),
        woo_effect_db_table($prefix . '-meta-lookup', 'wc_product_meta_lookup'),
        woo_effect_db_table($prefix . '-attributes-lookup', 'wc_product_attributes_lookup'),
        woo_effect_db_table($prefix . '-sale-actions', 'actionscheduler_actions'),
        woo_effect_db_table($prefix . '-sale-action-groups', 'actionscheduler_groups'),
        woo_effect_db_table($prefix . '-sale-action-logs', 'actionscheduler_logs'),
        ...woo_effect_sale_schedule_hooks($prefix),
        ...woo_effect_product_transient_options($prefix),
        woo_effect_irreversible($prefix . '-attributes-cache', 'cache', 'namespace', 'woocommerce-attributes'),
        woo_effect_product_cache_aggregate($prefix . '-cache-provider-resource'),
        woo_effect_hook($readHookPrefix . '-read-hook', 'woocommerce_product_read'),
        woo_effect_hook($prefix . '-delete-transients-hook', 'woocommerce_delete_product_transients'),
        woo_effect_hook($prefix . '-updated-price-hook', 'woocommerce_updated_product_price'),
        ...woo_effect_product_fixed_transient_hooks($prefix),
        ...woo_effect_product_attribute_refresh_hooks($prefix),
        woo_effect_attribute_registration_filter_family($prefix),
        woo_effect_hook($prefix . '-transient-version-pre-filter', 'pre_transient_product-transient-version'),
        woo_effect_hook($prefix . '-transient-version-filter', 'transient_product-transient-version'),
        woo_effect_hook($prefix . '-transient-version-pre-set-filter', 'pre_set_transient_product-transient-version'),
        woo_effect_hook($prefix . '-transient-version-expiration-filter', 'expiration_of_transient_product-transient-version'),
        woo_effect_hook($prefix . '-transient-version-set-hook', 'set_transient_product-transient-version'),
        woo_effect_hook($prefix . '-transient-version-set-generic-hook', 'set_transient'),
        woo_effect_hook($prefix . '-transient-version-setted-hook', 'setted_transient'),
        woo_effect_hook($prefix . '-clean-post-cache-hook', 'clean_post_cache'),
        woo_effect_hook($prefix . '-clean-object-term-cache-hook', 'clean_object_term_cache'),
    ];
    return $effects;
}

/**
 * @return list<array<string,mixed>>
 */
function woo_effect_attribute_action(): array {
    return [
        woo_effect_db_option('woocommerce-attribute-transient-value', '_transient_wc_attribute_taxonomies'),
        woo_effect_db_option('woocommerce-attribute-transient-timeout', '_transient_timeout_wc_attribute_taxonomies'),
        woo_effect_irreversible('woocommerce-attribute-transient-cache', 'cache', 'provider_resource', 'wordpress-transient:v1:wc_attribute_taxonomies'),
        woo_effect_hook('woocommerce-attribute-delete-transient-hook', 'delete_transient_wc_attribute_taxonomies'),
        woo_effect_hook('woocommerce-attribute-deleted-transient-hook', 'deleted_transient'),
    ];
}

/** @return list<array<string,mixed>> */
function woo_effect_shipping_tax_action(): array {
    return [
        woo_effect_db_option('woocommerce-shipping-transient-value', '_transient_shipping-transient-version'),
        woo_effect_db_option('woocommerce-shipping-transient-timeout', '_transient_timeout_shipping-transient-version'),
        woo_effect_irreversible('woocommerce-shipping-transient-cache', 'cache', 'provider_resource', 'wordpress-transient:v1:shipping-transient-version'),
        woo_effect_irreversible('woocommerce-attributes-cache', 'cache', 'namespace', 'woocommerce-attributes'),
        woo_effect_irreversible('woocommerce-shipping-zones-cache', 'cache', 'namespace', 'shipping_zones'),
        woo_effect_irreversible('woocommerce-taxes-cache', 'cache', 'namespace', 'taxes'),
        woo_effect_hook('woocommerce-shipping-pre-transient-filter', 'pre_transient_shipping-transient-version'),
        woo_effect_hook('woocommerce-shipping-transient-filter', 'transient_shipping-transient-version'),
        woo_effect_hook('woocommerce-shipping-pre-set-transient-filter', 'pre_set_transient_shipping-transient-version'),
        woo_effect_hook('woocommerce-shipping-expiration-transient-filter', 'expiration_of_transient_shipping-transient-version'),
        woo_effect_hook('woocommerce-shipping-set-transient-hook', 'set_transient_shipping-transient-version'),
        woo_effect_hook('woocommerce-shipping-set-transient-generic-hook', 'set_transient'),
        woo_effect_hook('woocommerce-shipping-setted-transient-hook', 'setted_transient'),
    ];
}

/** @return list<array<string,mixed>> */
function woo_effect_hierarchy_action(string $prefix, bool $rewrite): array {
    $effects = [
        woo_effect_db_table($prefix . '-category-lookup', 'wc_category_lookup'),
        woo_effect_db_option($prefix . '-product-cat-children', 'product_cat_children'),
        woo_effect_db_option($prefix . '-product-brand-children', 'product_brand_children'),
        woo_effect_irreversible($prefix . '-terms-cache', 'cache', 'namespace', 'terms'),
        woo_effect_irreversible($prefix . '-term-query-cache', 'cache', 'namespace', 'term-queries'),
        woo_effect_irreversible($prefix . '-product-cat-cache', 'cache', 'namespace', 'product_cat'),
        woo_effect_irreversible($prefix . '-product-brand-cache', 'cache', 'namespace', 'product_brand'),
        woo_effect_irreversible($prefix . '-options-cache', 'cache', 'namespace', 'options'),
        woo_effect_hook($prefix . '-clean-taxonomy-hook', 'clean_taxonomy_cache'),
        woo_effect_hook($prefix . '-pre-get-terms-hook', 'pre_get_terms'),
        woo_effect_hook($prefix . '-get-terms-args-filter', 'get_terms_args'),
        woo_effect_hook($prefix . '-terms-clauses-filter', 'terms_clauses'),
        woo_effect_hook($prefix . '-get-terms-filter', 'get_terms'),
        woo_effect_hook($prefix . '-delete-cat-option-hook', 'delete_option_product_cat_children'),
        woo_effect_hook($prefix . '-delete-brand-option-hook', 'delete_option_product_brand_children'),
        woo_effect_hook($prefix . '-deleted-option-hook', 'deleted_option'),
        woo_effect_hook($prefix . '-pre-update-option-filter', 'pre_update_option'),
        woo_effect_hook($prefix . '-pre-update-cat-option-filter', 'pre_update_option_product_cat_children'),
        woo_effect_hook($prefix . '-pre-update-brand-option-filter', 'pre_update_option_product_brand_children'),
        woo_effect_hook($prefix . '-update-cat-option-hook', 'update_option_product_cat_children'),
        woo_effect_hook($prefix . '-update-brand-option-hook', 'update_option_product_brand_children'),
        woo_effect_hook($prefix . '-update-option-hook', 'update_option'),
        woo_effect_hook($prefix . '-updated-option-hook', 'updated_option'),
        woo_effect_hook($prefix . '-pre-add-cat-option-filter', 'pre_add_option_product_cat_children'),
        woo_effect_hook($prefix . '-pre-add-brand-option-filter', 'pre_add_option_product_brand_children'),
        woo_effect_hook($prefix . '-add-cat-option-hook', 'add_option_product_cat_children'),
        woo_effect_hook($prefix . '-add-brand-option-hook', 'add_option_product_brand_children'),
        woo_effect_hook($prefix . '-add-option-hook', 'add_option'),
        woo_effect_hook($prefix . '-added-option-hook', 'added_option'),
    ];
    if (!$rewrite) {
        return $effects;
    }
    array_splice($effects, 3, 0, [
        woo_effect_db_option($prefix . '-rewrite-rules', 'rewrite_rules'),
    ]);
    array_splice($effects, 4, 0, [
        woo_effect_db_table($prefix . '-tec-expired-transient-options', 'options'),
        woo_effect_db_option($prefix . '-tec-last-generate-rewrite-rules', 'tribe_last_generate_rewrite_rules'),
        woo_effect_db_option($prefix . '-tec-last-save-post', 'tribe_last_save_post'),
        woo_effect_db_option($prefix . '-tec-last-updated-option', 'tribe_last_updated_option'),
        woo_effect_irreversible(
            $prefix . '-tec-cache-purge-request',
            'cache',
            'provider_resource',
            'the-events-calendar-cache-purge-request:v1'
        ),
    ]);
    return [
        ...$effects,
        woo_effect_hook($prefix . '-generate-rewrite-hook', 'generate_rewrite_rules'),
        woo_effect_hook($prefix . '-rewrite-array-filter', 'rewrite_rules_array'),
        woo_effect_hook($prefix . '-pre-update-rewrite-filter', 'pre_update_option_rewrite_rules'),
        woo_effect_hook($prefix . '-update-rewrite-hook', 'update_option_rewrite_rules'),
        woo_effect_hook($prefix . '-pre-add-rewrite-filter', 'pre_add_option_rewrite_rules'),
        woo_effect_hook($prefix . '-add-rewrite-hook', 'add_option_rewrite_rules'),
    ];
}

/** @return list<array<string,mixed>> */
function woo_effect_analytics_scheduler_action(): array {
    return [
        woo_effect_db_option('woocommerce-analytics-scheduler-marker', '_wprism_woocommerce_scheduler_settings_state'),
        woo_effect_db_option('woocommerce-analytics-scheduler-date-cursor', 'woocommerce_admin_scheduler_last_processed_order_modified_date'),
        woo_effect_db_option('woocommerce-analytics-scheduler-id-cursor', 'woocommerce_admin_scheduler_last_processed_order_id'),
        woo_effect_db_table('woocommerce-analytics-scheduler-actions', 'actionscheduler_actions'),
        woo_effect_db_table('woocommerce-analytics-scheduler-claims', 'actionscheduler_claims'),
        woo_effect_db_table('woocommerce-analytics-scheduler-groups', 'actionscheduler_groups'),
        woo_effect_db_table('woocommerce-analytics-scheduler-logs', 'actionscheduler_logs'),
        woo_effect_irreversible(
            'woocommerce-analytics-scheduler-native-frontier',
            'external',
            'provider_resource',
            'woocommerce-analytics-scheduler-native-frontier:v1',
            [
                'exact' => [
                    'action_scheduler_canceled_action',
                    'action_scheduler_claim_actions_order_by',
                    'action_scheduler_db_supports_skip_locked',
                    'action_scheduler_failed_fetch_action',
                    'action_scheduler_logger_class',
                    'action_scheduler_store_class',
                    'action_scheduler_stored_action',
                    'action_scheduler_stored_action_class',
                    'action_scheduler_stored_action_instance',
                    'pre_as_schedule_recurring_action',
                    'pre_as_schedule_single_action',
                    'woocommerce_analytics_disable_action_scheduling',
                    'woocommerce_analytics_import_interval',
                    'woocommerce_queue_class',
                ],
                'templates' => [],
            ]
        ),
        woo_effect_irreversible(
            'woocommerce-analytics-scheduler-option-frontier',
            'external',
            'provider_resource',
            'woocommerce-analytics-scheduler-option-frontier:v1',
            [
                'exact' => [
                    'add_option',
                    'add_option__wprism_woocommerce_scheduler_settings_state',
                    'add_option_woocommerce_admin_scheduler_last_processed_order_id',
                    'add_option_woocommerce_admin_scheduler_last_processed_order_modified_date',
                    'added_option',
                    'alloptions',
                    'default_option',
                    'default_option__wprism_woocommerce_scheduler_settings_state',
                    'default_option_schema-ActionScheduler_StoreSchema',
                    'default_option_woocommerce_admin_scheduler_last_processed_order_id',
                    'default_option_woocommerce_admin_scheduler_last_processed_order_modified_date',
                    'default_option_woocommerce_analytics_scheduled_import',
                    'option__wprism_woocommerce_scheduler_settings_state',
                    'option_schema-ActionScheduler_StoreSchema',
                    'option_woocommerce_admin_scheduler_last_processed_order_id',
                    'option_woocommerce_admin_scheduler_last_processed_order_modified_date',
                    'option_woocommerce_analytics_scheduled_import',
                    'pre_add_option',
                    'pre_add_option__wprism_woocommerce_scheduler_settings_state',
                    'pre_add_option_woocommerce_admin_scheduler_last_processed_order_id',
                    'pre_add_option_woocommerce_admin_scheduler_last_processed_order_modified_date',
                    'pre_option',
                    'pre_option__wprism_woocommerce_scheduler_settings_state',
                    'pre_option_schema-ActionScheduler_StoreSchema',
                    'pre_option_woocommerce_admin_scheduler_last_processed_order_id',
                    'pre_option_woocommerce_admin_scheduler_last_processed_order_modified_date',
                    'pre_option_woocommerce_analytics_scheduled_import',
                    'pre_update_option',
                    'pre_update_option__wprism_woocommerce_scheduler_settings_state',
                    'pre_update_option_woocommerce_admin_scheduler_last_processed_order_id',
                    'pre_update_option_woocommerce_admin_scheduler_last_processed_order_modified_date',
                    'pre_wp_load_alloptions',
                    'sanitize_option',
                    'sanitize_option__wprism_woocommerce_scheduler_settings_state',
                    'sanitize_option_woocommerce_admin_scheduler_last_processed_order_id',
                    'sanitize_option_woocommerce_admin_scheduler_last_processed_order_modified_date',
                    'update_option',
                    'update_option__wprism_woocommerce_scheduler_settings_state',
                    'update_option_woocommerce_admin_scheduler_last_processed_order_id',
                    'update_option_woocommerce_admin_scheduler_last_processed_order_modified_date',
                    'updated_option',
                    'wp_default_autoload_value',
                ],
                'templates' => [],
            ]
        ),
    ];
}

/** @return list<array<string,mixed>> */
function woo_effect_retention_scheduler_action(): array {
    return [
        woo_effect_db_option('woocommerce-stock-notification-retention-cron', 'cron'),
        woo_effect_irreversible(
            'woocommerce-stock-notification-retention-frontier',
            'external',
            'provider_resource',
            'woocommerce-stock-notification-retention-frontier:v1',
            [
                'exact' => [
                    'alloptions',
                    'cron_schedules',
                    'default_option',
                    'default_option_cron',
                    'default_option_woocommerce_customer_stock_notifications_unverified_deletions_days_threshold',
                    'option_cron',
                    'option_woocommerce_customer_stock_notifications_unverified_deletions_days_threshold',
                    'pre_clear_scheduled_hook',
                    'pre_get_scheduled_event',
                    'pre_option',
                    'pre_option_cron',
                    'pre_option_woocommerce_customer_stock_notifications_unverified_deletions_days_threshold',
                    'pre_schedule_event',
                    'pre_update_option',
                    'pre_update_option_cron',
                    'pre_wp_load_alloptions',
                    'sanitize_option_cron',
                    'schedule_event',
                    'update_option',
                    'update_option_cron',
                    'updated_option',
                    'wp_next_scheduled',
                ],
                'templates' => [],
            ]
        ),
    ];
}

/** @return list<array<string,mixed>> */
/** @return array<string,mixed> */
function woo_effect_policy_for_manifest(array $manifest): Policy {
    global $repoRoot;
    return FrozenPolicy::policy(
        [$manifest],
        FrozenPolicy::site([$manifest], WPRISM_SPEC_VERSION),
        null,
        \WPrism\AdapterLibrary::fromSourcePackage($repoRoot, 'woocommerce')
    );
}

/** @return array<string,mixed> */
function woo_effect_signed_request(string $root, array $payload, string $keyId, string $secret, bool $effects): array {
    $path = tempnam(sys_get_temp_dir(), 'wprism-woo-effect-request-');
    if ($path === false) {
        throw new RuntimeException('could not allocate signed request path');
    }
    file_put_contents($path, RollbackControl::canonical(RollbackControl::sign($payload, $keyId, $secret)) . "\n");
    try {
        return $effects
            ? EffectBundle::handleRequest($root, $path)
            : RecoveryExecutor::handleExclusionRequest($root, $path);
    } finally {
        @unlink($path);
    }
}

function woo_effect_remove_tree(string $path): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            woo_effect_remove_tree($path . '/' . $name);
        }
    }
    @rmdir($path);
}

// ======================================================================
echo "\n== committed WooCommerce manifest inventory ==\n";

$wooPolicy = Policy::load(
    null,
    ['woocommerce'],
    adapterLibrary: \WPrism\AdapterLibrary::fromSourcePackage($repoRoot, 'woocommerce')
);
$wooRows = array_values(array_filter(
    $wooPolicy->effects_inventory(),
    static fn(array $row): bool => ($row['manifest'] ?? null) === 'woocommerce'
));
$manifestPath = $repoRoot . '/adapter-packages/woocommerce/package/manifest.json';
$wooManifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
// issue #3338: the effect inventory's `source` is now each action's closed
// identity (the native vocabulary entry, or provider/capability) rather than
// the wp-cli command text the retired channel carried.
$attributeSource = 'native:transient.delete';
$cacheSource = 'provider:woocommerce-cache/invalidate_cache_groups';
$hierarchySource = 'provider:woocommerce-hierarchy-lookups/rebuild_hierarchy_lookups';
$expectedWooRows = [[
    'manifest' => 'woocommerce',
    'phase' => 'lifecycle',
    'source' => 'woocommerce/woocommerce.php',
    'effect' => woo_effect_lifecycle(),
]];
foreach (woo_effect_attribute_action() as $effect) {
    $expectedWooRows[] = [
        'manifest' => 'woocommerce',
        'phase' => 'rebuild',
        'source' => $attributeSource,
        'effect' => $effect,
    ];
}
foreach (woo_effect_shipping_tax_action() as $effect) {
    $expectedWooRows[] = [
        'manifest' => 'woocommerce',
        'phase' => 'rebuild',
        'source' => $cacheSource,
        'effect' => $effect,
    ];
}
foreach ([
    ['prefix' => 'woocommerce-hierarchy', 'rewrite' => false],
    ['prefix' => 'woocommerce-brand-route', 'rewrite' => true],
] as $hierarchyAction) {
    foreach (woo_effect_hierarchy_action($hierarchyAction['prefix'], $hierarchyAction['rewrite']) as $effect) {
        $expectedWooRows[] = [
            'manifest' => 'woocommerce',
            'phase' => 'rebuild',
            'source' => $hierarchySource,
            'effect' => $effect,
        ];
    }
}
$analyticsSchedulerSource = 'provider:woocommerce-scheduler-settings/reconcile_analytics_import_schedule';
foreach (woo_effect_analytics_scheduler_action() as $effect) {
    $expectedWooRows[] = [
        'manifest' => 'woocommerce',
        'phase' => 'rebuild',
        'source' => $analyticsSchedulerSource,
        'effect' => $effect,
    ];
}
$retentionSchedulerSource = 'provider:woocommerce-scheduler-settings/reconcile_stock_notification_retention';
foreach (woo_effect_retention_scheduler_action() as $effect) {
    $expectedWooRows[] = [
        'manifest' => 'woocommerce',
        'phase' => 'rebuild',
        'source' => $retentionSchedulerSource,
        'effect' => $effect,
    ];
}
// issue #3342: these effects moved with the dispatch. Exact Woo 11.0.0/11.0.1's
// public scoped attribute writer and raw readback now close the two attribute-
// lookup table writes as verified capability effects.
// post_types.<type>.regen_dependency.effects lists, inventoried under the
// `regenerator` phase and sourced by post type; they are now one action's
// effects list, inventoried under `rebuild` and sourced by the exact provider
// capability a recovery operator can re-run. woo_effect_product_regenerator()
// is deliberately still the per-post-type expectation builder, so a row that
// changed while moving fails here rather than being absorbed by a rewrite.
$lookupSource = 'provider:woocommerce-product-lookups/rebuild_product_lookups';
foreach (['product', 'product_variation'] as $postType) {
    foreach (woo_effect_product_regenerator($postType) as $effect) {
        $expectedWooRows[] = [
            'manifest' => 'woocommerce',
            'phase' => 'rebuild',
            'source' => $lookupSource,
            'effect' => $effect,
        ];
    }
}
$deletionCleanupSource = 'provider:woocommerce-product-lookups/cleanup_product_deletions';
foreach ([
    ['id' => 'woocommerce-product-deletion-meta-lookup', 'kind' => 'database', 'mode' => 'restorable', 'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'wc_product_meta_lookup']],
    ['id' => 'woocommerce-product-deletion-attribute-lookup', 'kind' => 'database', 'mode' => 'restorable', 'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'wc_product_attributes_lookup']],
    ['id' => 'woocommerce-product-deletion-sale-actions', 'kind' => 'database', 'mode' => 'restorable', 'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'actionscheduler_actions']],
    ['id' => 'woocommerce-product-deletion-transients', 'kind' => 'database', 'mode' => 'restorable', 'selector' => ['scope' => 'database_checkpoint', 'type' => 'table', 'value' => 'options']],
] as $effect) {
    $expectedWooRows[] = [
        'manifest' => 'woocommerce',
        'phase' => 'rebuild',
        'source' => $deletionCleanupSource,
        'effect' => $effect,
    ];
}
$reviewRewriteSource = 'native:rewrite.flush';
foreach (woo_effect_rewrite('woocommerce-review-order') as $effect) {
    $expectedWooRows[] = [
        'manifest' => 'woocommerce',
        'phase' => 'rebuild',
        'source' => $reviewRewriteSource,
        'effect' => $effect,
    ];
}
$productRouteSource = 'provider:woocommerce-hierarchy-lookups/rebuild_product_permalink_routes';
foreach (woo_effect_product_permalink_route() as $effect) {
    $expectedWooRows[] = [
        'manifest' => 'woocommerce',
        'phase' => 'rebuild',
        'source' => $productRouteSource,
        'effect' => $effect,
    ];
}
$settlementSource = 'provider:woocommerce-lifecycle-migrations/settle_lifecycle_migrations';
foreach (woo_effect_lifecycle_settlement() as $effect) {
    $expectedWooRows[] = [
        'manifest' => 'woocommerce',
        'phase' => 'lifecycle-settle',
        'source' => $settlementSource,
        'effect' => $effect,
    ];
}
usort($expectedWooRows, static fn(array $a, array $b): int => strcmp(
    implode("\0", [$a['phase'], $a['manifest'], (string) $a['effect']['id']]),
    implode("\0", [$b['phase'], $b['manifest'], (string) $b['effect']['id']])
));
woo_effect_check($wooRows === $expectedWooRows, 'Woo manifest compiles the exact lifecycle, rebuild, and regenerator inventory');
woo_effect_check(
    count(array_filter($wooRows, static fn(array $row): bool => ($row['effect']['mode'] ?? '') === 'restorable')) === 86
        && count(array_filter($wooRows, static fn(array $row): bool => ($row['effect']['mode'] ?? '') === 'irreversible')) === 194
        && count(array_unique(array_map(static fn(array $row): string => (string) ($row['effect']['id'] ?? ''), $wooRows))) === 280,
    'Woo inventory exposes exact transient/version, hierarchy/rewrite, TEC marker/purge rollback, bounded sale-action, and Action Scheduler hook boundaries, keeps every unproven boundary irreversible, and uses unique effect IDs'
);
$cacheProviderSource = (string) file_get_contents(dirname(__DIR__, 4) . '/adapter-packages/woocommerce/package/runtime/providers/woocommerce-cache.php');
woo_effect_check(
    ($wooManifest['actions'][0]['kind'] ?? null) === 'native'
        && ($wooManifest['actions'][0]['action'] ?? null) === 'transient.delete'
        && ($wooManifest['actions'][0]['args'] ?? null) === ['name' => 'wc_attribute_taxonomies']
        && ($wooManifest['actions'][0]['triggers'] ?? []) === ['table:woocommerce_attribute_taxonomies']
        && ($wooManifest['actions'][1]['kind'] ?? null) === 'provider'
        && ($wooManifest['actions'][1]['provider'] ?? null) === 'woocommerce-cache'
        && ($wooManifest['actions'][1]['capability'] ?? null) === 'invalidate_cache_groups'
        && ($wooManifest['actions'][1]['args']['groups'] ?? null)
            === ['woocommerce-attributes', 'shipping_zones', 'taxes']
        && in_array('option:woocommerce_calc_taxes', (array) ($wooManifest['actions'][1]['triggers'] ?? []), true)
        && $wooManifest['actions'] === array_values(array_filter(
            $wooManifest['actions'],
            static fn(array $action): bool => !array_key_exists('command', $action)
        )),
    'Woo rebuild actions are structured data bounded by exact authored surfaces, with no executable command string'
);
// The issue #3338 migration had to preserve the retired channel's scoping
// BYTE-FOR-BYTE except for the two exact Woo 11.0.x local-pickup REST records:
// ShippingController attaches the same shipping-version bump to both
// pre_update hooks. Keeping the complete trigger inventory pinned here makes
// a later scope change explicit. The key-set assertions close the entries: a
// leftover `command`, or a native key on the provider entry, is not merely
// refused at load — it cannot be present at all.
$cacheTriggers = [
    'table:woocommerce_attribute_taxonomies',
    'table:woocommerce_shipping_zone_locations',
    'table:woocommerce_shipping_zone_methods',
    'table:woocommerce_shipping_zones',
    'table:woocommerce_tax_rate_locations',
    'table:woocommerce_tax_rates',
    'option:pickup_location_pickup_locations',
    'option:woocommerce_all_except_countries',
    'option:woocommerce_allowed_countries',
    'option:woocommerce_calc_taxes',
    'option:woocommerce_default_country',
    'option:woocommerce_pickup_location_settings',
    'option:woocommerce_prices_include_tax',
    'option:woocommerce_ship_to_countries',
    'option:woocommerce_ship_to_destination',
    'option:woocommerce_shipping_cost_requires_address',
    'option:woocommerce_shipping_hide_rates_when_free',
    'option:woocommerce_shipping_tax_class',
    'option:woocommerce_tax_based_on',
    'option:woocommerce_tax_classes',
    'option:woocommerce_tax_display_cart',
    'option:woocommerce_tax_display_shop',
    'option:woocommerce_tax_round_at_subtotal',
    'option:woocommerce_tax_total_display',
];
$nativeKeys = array_keys((array) ($wooManifest['actions'][0] ?? []));
$providerKeys = array_keys((array) ($wooManifest['actions'][1] ?? []));
$hierarchyKeys = array_keys((array) ($wooManifest['actions'][2] ?? []));
$brandRouteKeys = array_keys((array) ($wooManifest['actions'][3] ?? []));
$fulfillmentKeys = array_keys((array) ($wooManifest['actions'][4] ?? []));
$analyticsSchedulerKeys = array_keys((array) ($wooManifest['actions'][5] ?? []));
$retentionSchedulerKeys = array_keys((array) ($wooManifest['actions'][6] ?? []));
$lookupKeys = array_keys((array) ($wooManifest['actions'][7] ?? []));
$deletionCleanupKeys = array_keys((array) ($wooManifest['actions'][8] ?? []));
$reviewRewriteKeys = array_keys((array) ($wooManifest['actions'][9] ?? []));
$productRouteKeys = array_keys((array) ($wooManifest['actions'][10] ?? []));
$settlementKeys = array_keys((array) ($wooManifest['actions'][11] ?? []));
sort($nativeKeys, SORT_STRING);
sort($providerKeys, SORT_STRING);
sort($hierarchyKeys, SORT_STRING);
sort($brandRouteKeys, SORT_STRING);
sort($fulfillmentKeys, SORT_STRING);
sort($analyticsSchedulerKeys, SORT_STRING);
sort($retentionSchedulerKeys, SORT_STRING);
sort($lookupKeys, SORT_STRING);
sort($deletionCleanupKeys, SORT_STRING);
sort($reviewRewriteKeys, SORT_STRING);
sort($productRouteKeys, SORT_STRING);
sort($settlementKeys, SORT_STRING);
woo_effect_check(
    count((array) ($wooManifest['actions'] ?? [])) === 12
        && $nativeKeys === ['action', 'args', 'effects', 'kind', 'triggers']
        && $providerKeys === ['args', 'capability', 'effects', 'kind', 'provider', 'triggers']
        && $hierarchyKeys === ['args', 'capability', 'effects', 'kind', 'provider', 'triggers']
        && $brandRouteKeys === ['args', 'capability', 'effects', 'kind', 'provider', 'triggers']
        && $fulfillmentKeys === ['args', 'capability', 'effects', 'kind', 'provider', 'triggers']
        && $analyticsSchedulerKeys === ['args', 'capability', 'effects', 'kind', 'provider', 'triggers']
        && $retentionSchedulerKeys === ['args', 'capability', 'effects', 'kind', 'provider', 'triggers']
        && $lookupKeys === ['args', 'capability', 'effects', 'kind', 'provider', 'triggers']
        && $deletionCleanupKeys === ['args', 'capability', 'effects', 'kind', 'provider', 'triggers']
        && $reviewRewriteKeys === ['action', 'args', 'effects', 'kind', 'triggers']
        && $productRouteKeys === ['args', 'capability', 'effects', 'kind', 'provider', 'triggers']
        && $settlementKeys === ['args', 'capability', 'effects', 'kind', 'phase', 'provider']
        && ($wooManifest['actions'][1]['triggers'] ?? null) === $cacheTriggers
        && ($wooManifest['actions'][1]['args'] ?? null) === ['groups' => ['woocommerce-attributes', 'shipping_zones', 'taxes']]
        && ($wooManifest['actions'][2]['provider'] ?? null) === 'woocommerce-hierarchy-lookups'
        && ($wooManifest['actions'][2]['args'] ?? null) === ['flush_rewrite' => false]
        && ($wooManifest['actions'][2]['triggers'] ?? null) === ['term:product_brand', 'term:product_cat']
        && ($wooManifest['actions'][2]['effects'] ?? null)
            === woo_effect_hierarchy_action('woocommerce-hierarchy', false)
        && ($wooManifest['actions'][3]['provider'] ?? null) === 'woocommerce-hierarchy-lookups'
        && ($wooManifest['actions'][3]['args'] ?? null) === ['flush_rewrite' => true]
        && ($wooManifest['actions'][3]['triggers'] ?? null) === ['option:woocommerce_brand_permalink']
        && ($wooManifest['actions'][3]['effects'] ?? null)
            === woo_effect_hierarchy_action('woocommerce-brand-route', true)
        && ($wooManifest['actions'][4]['provider'] ?? null) === 'woocommerce-fulfillment-prerequisites'
        && ($wooManifest['actions'][4]['capability'] ?? null) === 'verify_fulfillment_prerequisites'
        && ($wooManifest['actions'][4]['args'] ?? null) === []
        && ($wooManifest['actions'][4]['triggers'] ?? null) === ['term:wc_fulfillment_shipping_provider']
        && array_key_exists('effects', $wooManifest['actions'][4])
        && $wooManifest['actions'][4]['effects'] === []
        && ($wooManifest['actions'][5]['provider'] ?? null) === 'woocommerce-scheduler-settings'
        && ($wooManifest['actions'][5]['capability'] ?? null) === 'reconcile_analytics_import_schedule'
        && ($wooManifest['actions'][5]['args'] ?? null) === []
        && ($wooManifest['actions'][5]['triggers'] ?? null) === ['option:woocommerce_analytics_scheduled_import']
        && ($wooManifest['actions'][5]['effects'] ?? null) === woo_effect_analytics_scheduler_action()
        && ($wooManifest['actions'][6]['provider'] ?? null) === 'woocommerce-scheduler-settings'
        && ($wooManifest['actions'][6]['capability'] ?? null) === 'reconcile_stock_notification_retention'
        && ($wooManifest['actions'][6]['args'] ?? null) === []
        && ($wooManifest['actions'][6]['triggers'] ?? null)
            === ['option:woocommerce_customer_stock_notifications_unverified_deletions_days_threshold']
        && ($wooManifest['actions'][6]['effects'] ?? null) === woo_effect_retention_scheduler_action(),
    'Woo actions keep exact cache, hierarchy, fulfillment, scheduler, and review-route effect/trigger shapes'
);
// issue #3342: the lookup entry carries NO arguments at all. Every input it
// receives is engine-assembled (the entity batch and the declared channels),
// and a capability may not declare the reserved `entities` argument, so an
// args map here would be a claim the contract cannot honor.
woo_effect_check(
    ($wooManifest['actions'][7]['provider'] ?? null) === 'woocommerce-product-lookups'
        && ($wooManifest['actions'][7]['capability'] ?? null) === 'rebuild_product_lookups'
        && ($wooManifest['actions'][7]['args'] ?? null) === []
        && ($wooManifest['actions'][7]['triggers'] ?? null) === ['post:product', 'post:product_variation'],
    'the migrated lookup action names the provider capability and stays bounded to the two post types its '
        . 'retired regen_dependency declarations covered'
);
woo_effect_check(
    ($wooManifest['actions'][8]['provider'] ?? null) === 'woocommerce-product-lookups'
        && ($wooManifest['actions'][8]['capability'] ?? null) === 'cleanup_product_deletions'
        && ($wooManifest['actions'][8]['args'] ?? null) === []
        && ($wooManifest['actions'][8]['triggers'] ?? null) === ['post:product']
        && count((array) ($wooManifest['actions'][8]['effects'] ?? [])) === 4,
    'the deletion action is product-only and carries four database-checkpoint effects'
);
woo_effect_check(
    ($wooManifest['actions'][9]['kind'] ?? null) === 'native'
        && ($wooManifest['actions'][9]['action'] ?? null) === 'rewrite.flush'
        && ($wooManifest['actions'][9]['args'] ?? null) === []
        && ($wooManifest['actions'][9]['triggers'] ?? null) === ['option:woocommerce_review_order_page_id']
        && ($wooManifest['actions'][9]['effects'] ?? null)
            === woo_effect_rewrite('woocommerce-review-order'),
    'the optional customer-review page reuses the exact fresh-process rewrite boundary with no undeclared effects'
);
woo_effect_check(
    ($wooManifest['actions'][10]['provider'] ?? null) === 'woocommerce-hierarchy-lookups'
        && ($wooManifest['actions'][10]['capability'] ?? null) === 'rebuild_product_permalink_routes'
        && ($wooManifest['actions'][10]['args'] ?? null) === []
        && ($wooManifest['actions'][10]['triggers'] ?? null) === ['option:woocommerce_permalinks']
        && ($wooManifest['actions'][10]['effects'] ?? null) === woo_effect_product_permalink_route(),
    'the product-permalink child declares its sanitizer, single-generation, nested-option, and TEC rollback effects'
);
woo_effect_check(
    ($wooManifest['actions'][11]['provider'] ?? null) === 'woocommerce-lifecycle-migrations'
        && ($wooManifest['actions'][11]['capability'] ?? null) === 'settle_lifecycle_migrations'
        && ($wooManifest['actions'][11]['phase'] ?? null) === 'lifecycle_settle'
        && ($wooManifest['actions'][11]['args'] ?? null) === []
        && !array_key_exists('triggers', $wooManifest['actions'][11])
        && ($wooManifest['actions'][11]['effects'] ?? null) === woo_effect_lifecycle_settlement(),
    'the lifecycle settlement action is code-transition-selected and checkpoints every migration queue write surface'
);
$lookupEffectsById = [];
foreach ((array) ($wooManifest['actions'][7]['effects'] ?? []) as $effect) {
    $lookupEffectsById[(string) ($effect['id'] ?? '')] = $effect;
}
$registrationFilterSelector = [
    'scope' => 'external',
    'type' => 'provider_resource',
    'value' => 'woocommerce-attribute-taxonomy-registration-filters:v1',
];
woo_effect_check(
    ($lookupEffectsById['woocommerce-product-attribute-nav-menu-filter']['selector'] ?? null) === [
        'scope' => 'external',
        'type' => 'hook',
        'value' => 'woocommerce_attribute_show_in_nav_menus',
    ]
        && ($lookupEffectsById['woocommerce-variation-attribute-nav-menu-filter']['selector'] ?? null) === [
            'scope' => 'external',
            'type' => 'hook',
            'value' => 'woocommerce_attribute_show_in_nav_menus',
        ]
        && ($lookupEffectsById['woocommerce-product-attribute-taxonomy-registration-filters']['selector'] ?? null)
            === $registrationFilterSelector
        && ($lookupEffectsById['woocommerce-variation-attribute-taxonomy-registration-filters']['selector'] ?? null)
            === $registrationFilterSelector,
    'late Woo attribute registration declares the exact nav callback and one provider-owned object/args filter-family resource for both trigger halves'
);
woo_effect_check(
    !array_key_exists('regen_dependency', (array) ($wooManifest['post_types']['product'] ?? []))
        && !array_key_exists('regen_dependency', (array) ($wooManifest['post_types']['product_variation'] ?? [])),
    'and neither post type still declares the batch regenerator channel those effects moved off'
);
$providerShells = (array) ($wooManifest['providers'] ?? []);
$providerContractNames = [];
foreach ($providerShells as $index => $providerShell) {
    $providerContractNames[(string) ($providerShell['id'] ?? '')] = array_keys(
        (array) ($providerShell['contracts'] ?? [])
    );
    unset($providerShells[$index]['contracts']);
}
woo_effect_check(
    $providerShells === [
        [
            'id' => 'woocommerce-cache',
            'version' => '1.0.0',
            'source' => 'manifest',
            'plugin' => 'woocommerce/woocommerce.php',
            'capabilities' => ['invalidate_cache_groups'],
        ],
        [
            'id' => 'woocommerce-hierarchy-lookups',
            'version' => '2.0.0',
            'source' => 'manifest',
            'plugin' => 'woocommerce/woocommerce.php',
            'requires' => [
                'functions' => [
                    'clean_taxonomy_cache',
                    'delete_option',
                    'flush_rewrite_rules',
                    'get_option',
                    'wc_sanitize_permalink',
                    'wp_cache_delete',
                    'wp_cache_set_terms_last_changed',
                    '_get_term_hierarchy',
                ],
                'classes' => [
                    'WP_CLI',
                    'Automattic\WooCommerce\Internal\Admin\CategoryLookup',
                ],
            ],
            'capabilities' => ['rebuild_hierarchy_lookups', 'rebuild_product_permalink_routes'],
        ],
        [
            'id' => 'woocommerce-fulfillment-prerequisites',
            'version' => '1.0.0',
            'source' => 'manifest',
            'plugin' => 'woocommerce/woocommerce.php',
            'requires' => [
                'functions' => [
                    'get_option',
                    'get_taxonomy',
                    'taxonomy_exists',
                    'wc_get_container',
                ],
                'classes' => [
                    'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController',
                    'Automattic\\WooCommerce\\Internal\\Utilities\\DatabaseUtil',
                ],
            ],
            'capabilities' => ['verify_fulfillment_prerequisites'],
        ],
        [
            'id' => 'woocommerce-scheduler-settings',
            'version' => '1.0.0',
            'source' => 'manifest',
            'plugin' => 'woocommerce/woocommerce.php',
            'requires' => [
                'functions' => [
                    '_get_cron_array',
                    'as_get_scheduled_actions',
                    'get_option',
                    'has_filter',
                    'update_option',
                    'wc_get_container',
                    'wp_cache_delete',
                    'wp_cache_get',
                    'wp_clear_scheduled_hook',
                    'wp_get_schedules',
                    'wp_json_encode',
                    'wp_next_scheduled',
                    'wp_schedule_event',
                    'wp_using_ext_object_cache',
                ],
                'classes' => [
                    'ActionScheduler',
                    'ActionScheduler_Action',
                    'ActionScheduler_ActionClaim',
                    'ActionScheduler_ActionFactory',
                    'ActionScheduler_DBLogger',
                    'ActionScheduler_DBStore',
                    'ActionScheduler_IntervalSchedule',
                    'ActionScheduler_QueueRunner',
                    'ActionScheduler_SimpleSchedule',
                    'Automattic\\WooCommerce\\Admin\\Features\\Features',
                    'Automattic\\WooCommerce\\Internal\\Admin\\Schedulers\\OrdersScheduler',
                    'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController',
                    'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer',
                    'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController',
                    'Automattic\\WooCommerce\\Internal\\StockNotifications\\DataRetentionController',
                    'WC_Action_Queue',
                    'WC_Install',
                    'WP_Hook',
                ],
            ],
            'capabilities' => [
                'reconcile_analytics_import_schedule',
                'reconcile_stock_notification_retention',
            ],
        ],
        [
            'id' => 'woocommerce-product-lookups',
            'version' => '3.1.0',
            'source' => 'manifest',
            'plugin' => 'woocommerce/woocommerce.php',
            'requires' => [
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
            ],
            'capabilities' => ['cleanup_product_deletions', 'rebuild_product_lookups'],
        ],
        [
            'id' => 'woocommerce-lifecycle-migrations',
            'version' => '1.0.0',
            'source' => 'manifest',
            'plugin' => 'woocommerce/woocommerce.php',
            // The settlement capability moved onto the engine's own child
            // (manifest-provider-fresh-process/v1), so the adapter no longer needs
            // WP_CLI: it claims and runs through Action Scheduler's own store and
            // runner, and reads WooCommerce's own WC_Install::needs_db_update()
            // verdict rather than comparing version strings.
            'requires' => [
                'functions' => ['get_option'],
                'classes' => ['ActionScheduler', 'ActionScheduler_ActionClaim', 'WC_Install'],
            ],
            'capabilities' => ['settle_lifecycle_migrations'],
            'fresh_process_capabilities' => ['settle_lifecycle_migrations'],
        ],
    ] && $providerContractNames === [
        'woocommerce-cache' => ['invalidate_cache_groups'],
        'woocommerce-hierarchy-lookups' => [],
        'woocommerce-fulfillment-prerequisites' => [],
        'woocommerce-scheduler-settings' => [],
        'woocommerce-product-lookups' => ['cleanup_product_deletions', 'rebuild_product_lookups'],
        'woocommerce-lifecycle-migrations' => ['settle_lifecycle_migrations'],
    ],
    'Woo provider declarations retain exact identities and requirements while migrated capability contracts are manifest data consumed by the engine runtime'
);
woo_effect_check(
    str_contains($cacheProviderSource, "get_transient_version('shipping', true)")
        && str_contains($cacheProviderSource, "get_transient_version('shipping', false)")
        && str_contains($cacheProviderSource, '$freshShippingVersion !== $shippingVersion')
        && str_contains($cacheProviderSource, 'did not persist across a fresh read')
        && !str_contains($cacheProviderSource, 'WooCommerceContract::rebuild'),
    'the manifest-shipped cache provider keeps the version-pinned public boundary and its fresh-read persistence proof'
);
woo_effect_check(
    str_contains((string) ($wooManifest['notes']['category and brand hierarchy projection (WooCommerce 11.0.x)'] ?? ''), 'CategoryLookup::regenerate()')
        && str_contains((string) ($wooManifest['notes']['category and brand hierarchy projection (WooCommerce 11.0.x)'] ?? ''), 'checked raw option bytes')
        && !str_contains((string) ($wooManifest['notes']['category and brand hierarchy projection (WooCommerce 11.0.x)'] ?? ''), 'manual repair'),
    'Woo manifest states the exact fresh-process category/brand repair and independent readback authority'
);
foreach (['product', 'product_variation'] as $postType) {
    // One action now sources both halves, so the split is by the effect-id
    // prefix the manifest note keeps deliberately distinct between product and
    // variation rather than by the inventory's `source`.
    $idPrefix = $postType === 'product' ? 'woocommerce-product-' : 'woocommerce-variation-';
    $rows = array_values(array_filter(
        $wooRows,
        static fn(array $row): bool => ($row['phase'] ?? null) === 'rebuild'
            && ($row['source'] ?? null) === $lookupSource
            && str_starts_with((string) ($row['effect']['id'] ?? ''), $idPrefix)
    ));
    $effects = array_map(static fn(array $row): array => $row['effect'], $rows);
    $expectedEffects = woo_effect_product_regenerator($postType);
    usort($expectedEffects, static fn(array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));
    woo_effect_check(
        $effects === $expectedEffects,
        "$postType regenerator covers database surfaces, dynamic Woo cache families, and exact callback boundaries"
    );
    $providerResources = array_values(array_filter(
        $effects,
        static fn(array $effect): bool => ($effect['selector']['type'] ?? null) === 'provider_resource'
    ));
    $cacheProviderResources = array_values(array_filter(
        $providerResources,
        static fn(array $effect): bool => str_starts_with(
            (string) ($effect['selector']['value'] ?? ''),
            'woocommerce-product-cache-aggregate:v1:'
        )
    ));
    $registrationProviderResources = array_values(array_filter(
        $providerResources,
        static fn(array $effect): bool => ($effect['selector']['value'] ?? null)
            === 'woocommerce-attribute-taxonomy-registration-filters:v1'
    ));
    $visibilityProviderResources = array_values(array_filter(
        $providerResources,
        static fn(array $effect): bool => ($effect['selector']['value'] ?? null)
            === 'woocommerce-product-visibility-writer:v2:wp-add-remove-object-terms,term-count-hooks,relationship-caches'
    ));
    $providerValue = (string) ($cacheProviderResources[0]['selector']['value'] ?? '');
    woo_effect_check(
        count($providerResources) === 3
            && count($cacheProviderResources) === 1
            && count($registrationProviderResources) === 1
            && count($visibilityProviderResources) === 1
            && $providerValue === (string) woo_effect_product_cache_aggregate(
                $postType === 'product' ? 'woocommerce-product-cache-provider-resource' : 'woocommerce-variation-cache-provider-resource'
            )['selector']['value']
            && !str_contains($providerValue, '*')
            && str_contains($providerValue, 'wc_products_onsale')
            && str_contains($providerValue, 'wc_product_children')
            && str_contains($providerValue, 'product-transient-version')
            && str_contains($providerValue, 'wc_layered_nav_counts')
            && !array_key_exists('members', (array) ($registrationProviderResources[0]['selector'] ?? []))
            && !array_key_exists('members', (array) ($visibilityProviderResources[0]['selector'] ?? [])),
        "$postType provider-resource aggregates are explicit, finite, wildcard-free, and include the exact native visibility writer boundary"
    );
    $selectorMatcher = new ReflectionMethod(EffectBundle::class, 'matchesDeclaredSelector');
    $aggregateSelector = (array) ($cacheProviderResources[0]['selector'] ?? []);
    $selectorMatches = static function (array $actual) use ($selectorMatcher, $aggregateSelector): bool {
        return (bool) $selectorMatcher->invoke(null, $aggregateSelector, $actual);
    };
    woo_effect_check(
        $selectorMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'product_42'])
            && $selectorMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'wc_var_prices_42'])
            && $selectorMatches([
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'woocommerce-product-cache-event:v1:transient=wc_layered_nav_counts_pa_color',
            ])
            && $selectorMatches([
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'woocommerce-product-cache-event:v1:cache_group=pa_color_relationships;key=42',
            ])
            && $selectorMatches([
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'woocommerce-product-cache-event:v1:cache_group=products;key=42',
            ])
            && $selectorMatches([
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'product_cat_relationships:42',
            ])
            && $selectorMatches([
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'product_brand_relationships:42',
            ])
            && $selectorMatches([
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'woocommerce-product-cache-event:v1:cache_group=product_brand_relationships;key=42',
            ])
            && $selectorMatches([
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'woocommerce-product-cache-event:v1:cache_group=posts;key=post_parent:42',
            ]),
        "$postType aggregate matcher reconciles concrete product IDs, attribute transients, and taxonomy cache groups"
    );
    $registrationFamilySelector = (array) ($registrationProviderResources[0]['selector'] ?? []);
    woo_effect_check(
        (bool) $selectorMatcher->invoke(null, $registrationFamilySelector, $registrationFamilySelector)
            && !(bool) $selectorMatcher->invoke(null, $registrationFamilySelector, [
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'woocommerce_taxonomy_objects_pa_color',
            ])
            && !(bool) $selectorMatcher->invoke(null, $registrationFamilySelector, [
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'woocommerce_taxonomy_args_pa_尺寸',
            ]),
        "$postType registration callbacks are declared as one exact provider-owned family resource, not a false ASCII member grammar"
    );
    woo_effect_check(
        !$selectorMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'transient'])
            && !$selectorMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'wc_var_prices_*'])
            && !$selectorMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'product_00042'])
            && !$selectorMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'product_12345678901234567890'])
            && !$selectorMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'wc_unknown_42'])
            && !$selectorMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'evil_relationships:42'])
            && !$selectorMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'products'])
            && !$selectorMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'product_objects'])
            && !$selectorMatches([
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => (string) ($aggregateSelector['value'] ?? ''),
            ])
            && !$selectorMatches([
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'product_42',
                'members' => [],
            ])
            && !$selectorMatches([
                'scope' => 'external',
                'type' => 'namespace',
                'value' => 'product_42',
            ])
            && !$selectorMatches([
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'woocommerce-product-cache-event:v1:transient=wc_layered_nav_counts_*',
            ]),
        "$postType aggregate matcher rejects wildcard, leading-zero, unknown-family, and namespace broadening"
    );
    // issue #3437: WooCommerce >=11.0.0 permits multibyte attribute taxonomy
    // names (pa_<slug> within register_taxonomy()'s 32-byte limit, 29 bytes
    // after the pa_ prefix) -- a real WooCommerce install can reach every
    // one of these concrete cache effects from a non-ASCII attribute, not
    // just the ASCII slugs the rest of this suite already covers above.
    woo_effect_check(
        $selectorMatches([
            'scope' => 'external',
            'type' => 'provider_resource',
            'value' => 'wc_layered_nav_counts_pa_尺寸',
        ])
            && $selectorMatches([
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'woocommerce-product-cache-event:v1:transient=wc_layered_nav_counts_pa_尺寸',
            ])
            && $selectorMatches([
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'pa_尺寸_relationships:42',
            ])
            && $selectorMatches([
                'scope' => 'external',
                'type' => 'provider_resource',
                'value' => 'woocommerce-product-cache-event:v1:cache_group=pa_尺寸_relationships;key=42',
            ]),
        "$postType aggregate matcher reconciles a valid multibyte WooCommerce 11.0.x attribute slug"
    );
    woo_effect_check(
        // Case-lacking multibyte scripts (CJK, Arabic, Hebrew, ...) stay
        // covered; uppercase/titlecase and non-letter/non-decimal-digit
        // Unicode (symbols, Roman numerals) stay rejected exactly as their
        // ASCII equivalents already are above -- widening to Unicode did
        // not widen past what a WordPress-lowercased, sanitize_title()-
        // family slug can actually contain.
        !$selectorMatches([
            'scope' => 'external', 'type' => 'provider_resource',
            'value' => 'wc_layered_nav_counts_pa_Pa色', // mixed ASCII-upper + multibyte
        ])
            && !$selectorMatches([
                'scope' => 'external', 'type' => 'provider_resource',
                'value' => 'wc_layered_nav_counts_pa_★', // symbol, not a letter or digit
            ])
            && !$selectorMatches([
                'scope' => 'external', 'type' => 'provider_resource',
                'value' => 'wc_layered_nav_counts_pa_Ⅷ', // Roman numeral (\p{Nl}, not \p{Nd})
            ]),
        "$postType aggregate matcher rejects mixed-case and non-letter/non-decimal-digit Unicode in a slug position"
    );
    $declaredHook = woo_effect_hook('aggregate-exact-hook', 'clean_post_cache')['selector'];
    woo_effect_check(
        (bool) $selectorMatcher->invoke(null, $declaredHook, $declaredHook)
            && !(bool) $selectorMatcher->invoke(null, $declaredHook, [
                'scope' => 'external', 'type' => 'hook', 'value' => 'clean_post_cache_42',
            ]),
        "$postType exact hook selectors remain exact outside the aggregate grammar"
    );
    $genericSelector = [
        'scope' => 'external',
        'type' => 'provider_resource',
        'value' => 'generic-cache:v1',
        'members' => [
            'exact' => ['fixed-resource'],
            'templates' => ['item_{positive_uint}', 'attribute_{slug}'],
        ],
    ];
    $genericMatches = static function (array $actual) use ($selectorMatcher, $genericSelector): bool {
        return (bool) $selectorMatcher->invoke(null, $genericSelector, $actual);
    };
    woo_effect_check(
        $genericMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'fixed-resource'])
            && $genericMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'item_42'])
            && $genericMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'attribute_pa_color']),
        "$postType generic provider-resource matcher instantiates exact, positive-uint, and slug members"
    );
    woo_effect_check(
        !$genericMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'item_042'])
            && !$genericMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'item_12345678901234567890'])
            && !$genericMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'attribute_PA_color'])
            && !$genericMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'attribute_password'])
            && !$genericMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'generic-cache:v1'])
            && !$genericMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'item_*'])
            && !$genericMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'fixed-resource', 'extra' => true]),
        "$postType generic provider-resource matcher rejects malformed or secret-shaped values, aggregate-self, wildcards, and extra keys"
    );
    $compileMembers = new ReflectionMethod(EffectBundle::class, 'compileProviderResourceMembers');
    woo_effect_check(
        $compileMembers->invoke(null, [
            'exact' => ['fixed-resource'],
            'templates' => ['item_{unknown}'],
        ], 'generic-cache:v1') === null
        && $compileMembers->invoke(null, [
            'exact' => ['fixed-resource', 'fixed-resource'],
            'templates' => ['item_{positive_uint}'],
        ], 'generic-cache:v1') === null,
        "$postType runtime inventory compiler refuses unknown placeholders and duplicate members"
    );
    $hooks = array_values(array_filter(
        $effects,
        static fn(array $effect): bool => ($effect['selector']['type'] ?? null) === 'hook'
    ));
    $hookNames = array_map(static fn(array $effect): string => (string) ($effect['selector']['value'] ?? ''), $hooks);
    sort($hookNames, SORT_STRING);
    $expectedHookNames = array_map(
        static fn(array $effect): string => (string) ($effect['selector']['value'] ?? ''),
        array_merge(
            woo_effect_product_fixed_transient_hooks($postType === 'product' ? 'woocommerce-product' : 'woocommerce-variation'),
            woo_effect_product_attribute_refresh_hooks($postType === 'product' ? 'woocommerce-product' : 'woocommerce-variation'),
            woo_effect_sale_schedule_hooks($postType === 'product' ? 'woocommerce-product' : 'woocommerce-variation'),
            [
                woo_effect_hook('expected-product-read', 'woocommerce_product_read'),
                woo_effect_hook('expected-product-delete', 'woocommerce_delete_product_transients'),
                woo_effect_hook('expected-product-price', 'woocommerce_updated_product_price'),
                woo_effect_hook('expected-version-pre', 'pre_transient_product-transient-version'),
                woo_effect_hook('expected-version', 'transient_product-transient-version'),
                woo_effect_hook('expected-version-pre-set', 'pre_set_transient_product-transient-version'),
                woo_effect_hook('expected-version-expiration', 'expiration_of_transient_product-transient-version'),
                woo_effect_hook('expected-version-set', 'set_transient_product-transient-version'),
                woo_effect_hook('expected-version-generic-set', 'set_transient'),
                woo_effect_hook('expected-version-setted', 'setted_transient'),
                woo_effect_hook('expected-clean-post-cache', 'clean_post_cache'),
                woo_effect_hook('expected-clean-object-term-cache', 'clean_object_term_cache'),
            ]
        )
    );
    sort($expectedHookNames, SORT_STRING);
    woo_effect_check(
        $hookNames === $expectedHookNames,
        "$postType declares exact Woo product callbacks and product-transient-version get/set boundaries"
    );
}

// The key gate must admit effects, then let the existing effect validator
// reject a malformed entry. An unrelated key must still fail at the strict
// regen_dependency boundary.
// issue #3342 moved Woo off the regen_dependency channel, so the channel's own
// key gate is exercised against a synthesized declaration rather than a shipped
// one. The grammar is still engine-owned and still reachable by any manifest,
// so dropping these two would retire a validator's coverage as a side effect of
// migrating one adapter — which is exactly the silent loss this suite exists to
// prevent. The base declaration is the minimum the validator accepts.
$wooWithRegen = $wooManifest;
$wooWithRegen['post_types']['product']['regen_dependency'] = [
    'regenerator' => 'woocommerce-product-lookups',
    'verify' => ['table' => 'wc_product_meta_lookup', 'column' => 'product_id'],
];
woo_effect_check(
    woo_effect_policy_for_manifest($wooWithRegen)->regen_dependency('product') !== null,
    'the synthesized regen_dependency base loads clean, so the two refusals below are about the key they add'
);
$unknownKeyManifest = $wooWithRegen;
$unknownKeyManifest['post_types']['product']['regen_dependency']['unsupported'] = true;
woo_effect_expect_throw(
    fn() => woo_effect_policy_for_manifest($unknownKeyManifest),
    'contains unknown key(s)',
    'regen_dependency rejects an unknown top-level key'
);
$badEffectManifest = $wooWithRegen;
$badEffectManifest['post_types']['product']['regen_dependency']['effects'] = ['malformed-effect'];
woo_effect_expect_throw(
    fn() => woo_effect_policy_for_manifest($badEffectManifest),
    'must be an object',
    'regen_dependency.effects is admitted by key validation but malformed entries are rejected by effect schema validation'
);
// The bounded provider-resource aggregate now lives on the migrated action's
// own effects list; the grammar checked below is the effect validator's, which
// never cared which declaration carried the row.
$providerEffectIndex = null;
foreach (($wooManifest['actions'][7]['effects'] ?? []) as $index => $effect) {
    if (($effect['id'] ?? null) === 'woocommerce-product-cache-provider-resource') {
        $providerEffectIndex = (int) $index;
        break;
    }
}
woo_effect_check($providerEffectIndex !== null, 'Woo manifest test locates the bounded provider aggregate by effect id');
$providerEffectIndex ??= 0;
$badMembers = $wooManifest;
$badMembers['actions'][7]['effects'][$providerEffectIndex]['selector']['members']['templates'][0] = 'item_{unknown}';
woo_effect_expect_throw(
    fn() => woo_effect_policy_for_manifest($badMembers),
    'unknown placeholder',
    'provider-resource members reject unknown typed placeholders during policy compilation'
);
$badMembers = $wooManifest;
$badMembers['actions'][7]['effects'][$providerEffectIndex]['selector']['members']['templates'][0] = 'item_*';
woo_effect_expect_throw(
    fn() => woo_effect_policy_for_manifest($badMembers),
    'malformed, broad, or secret-shaped',
    'provider-resource members reject wildcard templates during policy compilation'
);
$badMembers = $wooManifest;
$badMembers['actions'][7]['effects'][$providerEffectIndex]['selector']['members']['unexpected'] = [];
woo_effect_expect_throw(
    fn() => woo_effect_policy_for_manifest($badMembers),
    'requires exactly exact and templates',
    'provider-resource members reject extra grammar keys during policy compilation'
);
$badMembers = $wooManifest;
$badMembers['actions'][7]['effects'][$providerEffectIndex]['selector']['unexpected'] = true;
woo_effect_expect_throw(
    fn() => woo_effect_policy_for_manifest($badMembers),
    'requires exactly scope, type, value, and optional members',
    'provider-resource selectors reject extra top-level keys during policy compilation'
);
$badMembers = $wooManifest;
$badMembers['actions'][7]['effects'][$providerEffectIndex]['selector']['members']['exact'][] = 'wc_products_onsale';
woo_effect_expect_throw(
    fn() => woo_effect_policy_for_manifest($badMembers),
    'duplicate member',
    'provider-resource members reject duplicate exact values during policy compilation'
);
$badMembers = $wooManifest;
$aggregateValue = (string) $badMembers['actions'][7]['effects'][$providerEffectIndex]['selector']['value'];
$badMembers['actions'][7]['effects'][$providerEffectIndex]['selector']['members']['exact'][] = $aggregateValue;
woo_effect_expect_throw(
    fn() => woo_effect_policy_for_manifest($badMembers),
    'malformed, broad, or secret-shaped',
    'provider-resource members reject aggregate-self exact values during policy compilation'
);

// ======================================================================
echo "\n== automatic-profile refusal ==\n";

$tmp = sys_get_temp_dir() . '/wprism-woocommerce-effect-contract-' . bin2hex(random_bytes(6));
$keyPair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keyPair);
$public = sodium_crypto_sign_publickey($keyPair);
$keyId = 'woo-effect-test';
$root = $tmp . '/site/.wprism/control';
$receiptId = str_repeat('w', 48);
$artifactHash = hash('sha256', 'woocommerce-effect-contract');
$timestamp = '2030-01-01T00:00:00Z';
try {
    mkdir($tmp, 0700, true);
    $initial = RollbackControl::initialize($root);
    RollbackControl::installPublicKey($root, $keyId, base64_encode($public));
    $config = [
        'adapters' => array_fill_keys(
            ['code_restore', 'database_restore', 'prior_verify', 'storage_restore'],
            [PHP_BINARY, dirname(__DIR__, 4) . '/sandbox/tests/fixtures/recovery-adapter.php']
        ),
        'exclusion_provider' => [
            PHP_BINARY,
            dirname(__DIR__, 4) . '/sandbox/tests/fixtures/recovery-exclusion-provider.php',
            $tmp . '/exclusion.json',
        ],
        'format' => 'wprism-recovery-config/v1',
        'timeout_seconds' => 5,
    ];
    $configPath = $tmp . '/recovery.json';
    file_put_contents($configPath, RollbackControl::canonical($config) . "\n");
    RecoveryExecutor::configureFromFile($root, $configPath);

    woo_effect_signed_request($root, [
        'action' => 'acquire',
        'artifact_hash' => $artifactHash,
        'claim_epoch' => 1,
        'claimant' => 'worker-woo',
        'format' => 'wprism-exclusion-request/v1',
        'generation' => 1,
        'owner' => 'controller:woo-effects',
        'receipt_id' => $receiptId,
        'target_id' => $initial['target_id'],
        'timestamp' => $timestamp,
    ], $keyId, $secret, false);

    $regenInventory = array_values(array_filter(
        $wooRows,
        static fn(array $row): bool => ($row['source'] ?? null) === $lookupSource
    ));
    woo_effect_expect_throw(
        fn() => woo_effect_signed_request($root, [
            'action' => 'prepare',
            'artifact_hash' => $artifactHash,
            'claim_epoch' => 1,
            'claimant' => 'worker-woo',
            'format' => 'wprism-effect-bundle-request/v1',
            'generation' => 1,
            'inventory' => $regenInventory,
            'owner' => 'controller:woo-effects',
            'receipt_id' => $receiptId,
            'retention_until' => '2030-01-02T00:00:00Z',
            'target_id' => $initial['target_id'],
            'timestamp' => $timestamp,
        ], $keyId, $secret, true),
        'irreversible effect',
        'automatic profile refuses product regenerator inventory before provider preparation'
    );
    woo_effect_check(
        !is_dir(dirname($root) . '/rollback/' . $receiptId),
        'irreversible preflight leaves no prepared Woo effect artifacts'
    );
} finally {
    sodium_memzero($secret);
    woo_effect_remove_tree($tmp);
}

if ($failures > 0) {
    exit(1);
}
echo "\nALL WOO EFFECT CONTRACT CHECKS PASSED\n";
