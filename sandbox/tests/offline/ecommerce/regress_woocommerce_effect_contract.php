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

define('DUO_SPEC_VERSION', 2);
require dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
require dirname(__DIR__, 4) . '/agent/src/Kernel/OptionState.php';
require dirname(__DIR__, 4) . '/agent/src/Policy/Policy.php';
require dirname(__DIR__, 4) . '/recovery/rollback-control.php';
require __DIR__ . '/../../lib/frozen_policy.php';

use Duo\Policy;
use DuoTest\FrozenPolicy;
use Duo\Recovery\EffectBundle;
use Duo\Recovery\RecoveryExecutor;
use Duo\Recovery\RollbackControl;

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

/** @return array<string,mixed> */
function woo_effect_hook(string $id, string $hook): array {
    return woo_effect_irreversible($id, 'external', 'hook', $hook);
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
            'product_tag_relationships:{positive_uint}',
            'product_shipping_class_relationships:{positive_uint}',
            'pa_{slug}_relationships:{positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=product_type_relationships;key={positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=product_visibility_relationships;key={positive_uint}',
            'woocommerce-product-cache-event:v1:cache_group=product_cat_relationships;key={positive_uint}',
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
        woo_effect_db_table($prefix . '-download-directories', 'wc_product_download_directories'),
        woo_effect_db_table($prefix . '-meta-lookup', 'wc_product_meta_lookup'),
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
/** @return array<string,mixed> */
function woo_effect_policy_for_manifest(array $manifest): Policy {
    return Policy::from_snapshot(FrozenPolicy::envelope(
        [$manifest],
        FrozenPolicy::site([$manifest], DUO_SPEC_VERSION)
    ));
}

/** @return array<string,mixed> */
function woo_effect_signed_request(string $root, array $payload, string $keyId, string $secret, bool $effects): array {
    $path = tempnam(sys_get_temp_dir(), 'duo-woo-effect-request-');
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

putenv('DUO_MANIFESTS_DIR');
$wooPolicy = Policy::load(null, ['woocommerce']);
$wooRows = array_values(array_filter(
    $wooPolicy->effects_inventory(),
    static fn(array $row): bool => ($row['manifest'] ?? null) === 'woocommerce'
));
$manifestPath = dirname(__DIR__, 4) . '/manifests/woocommerce.json';
$wooManifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
// DUO-3338: the effect inventory's `source` is now each action's closed
// identity (the native vocabulary entry, or provider/capability) rather than
// the wp-cli command text the retired channel carried.
$attributeSource = 'native:transient.delete';
$cacheSource = 'provider:woocommerce-cache/invalidate_cache_groups';
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
// DUO-3342: these effects moved with the dispatch; DUO-3411 then removed the
// two unsupported attribute-lookup table writes from the verified capability.
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
usort($expectedWooRows, static fn(array $a, array $b): int => strcmp(
    implode("\0", [$a['phase'], $a['manifest'], (string) $a['effect']['id']]),
    implode("\0", [$b['phase'], $b['manifest'], (string) $b['effect']['id']])
));
woo_effect_check($wooRows === $expectedWooRows, 'Woo manifest compiles the exact lifecycle, rebuild, and regenerator inventory');
woo_effect_check(
    count(array_filter($wooRows, static fn(array $row): bool => ($row['effect']['mode'] ?? '') === 'restorable')) === 42
        && count(array_filter($wooRows, static fn(array $row): bool => ($row['effect']['mode'] ?? '') === 'irreversible')) === 97
        && count(array_unique(array_map(static fn(array $row): string => (string) ($row['effect']['id'] ?? ''), $wooRows))) === 139,
    'Woo inventory exposes exact transient/version, bounded sale-action, and Action Scheduler hook boundaries, keeps every unproven boundary irreversible, and uses unique effect IDs'
);
$cacheProviderSource = (string) file_get_contents(dirname(__DIR__, 4) . '/manifests/providers/woocommerce-cache.php');
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
// The DUO-3338 migration had to preserve the retired channel's scoping
// BYTE-FOR-BYTE: this is the exact trigger list manifests/woocommerce.json
// carried as `rebuilders[1].triggers` at the last pre-migration revision
// (da93360), pinned here so a later edit that widens or narrows Woo's cache
// invalidation has to say so out loud instead of arriving as a diff nobody
// reads. The key-set assertions close the entries: a leftover `command`, or a
// native key on the provider entry, is not merely refused at load — it cannot
// be present at all.
$cacheTriggers = [
    'table:woocommerce_attribute_taxonomies',
    'table:woocommerce_shipping_zone_locations',
    'table:woocommerce_shipping_zone_methods',
    'table:woocommerce_shipping_zones',
    'table:woocommerce_tax_rate_locations',
    'table:woocommerce_tax_rates',
    'option:woocommerce_all_except_countries',
    'option:woocommerce_allowed_countries',
    'option:woocommerce_calc_taxes',
    'option:woocommerce_default_country',
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
$lookupKeys = array_keys((array) ($wooManifest['actions'][2] ?? []));
sort($nativeKeys, SORT_STRING);
sort($providerKeys, SORT_STRING);
sort($lookupKeys, SORT_STRING);
woo_effect_check(
    count((array) ($wooManifest['actions'] ?? [])) === 3
        && $nativeKeys === ['action', 'args', 'effects', 'kind', 'triggers']
        && $providerKeys === ['args', 'capability', 'effects', 'kind', 'provider', 'triggers']
        && $lookupKeys === ['args', 'capability', 'effects', 'kind', 'provider', 'triggers']
        && ($wooManifest['actions'][1]['triggers'] ?? null) === $cacheTriggers
        && ($wooManifest['actions'][1]['args'] ?? null) === ['groups' => ['woocommerce-attributes', 'shipping_zones', 'taxes']],
    'the migrated Woo actions carry exactly the retired channel\'s trigger surfaces and argument values, and no key beyond the two closed entry shapes'
);
// DUO-3342: the lookup entry carries NO arguments at all. Every input it
// receives is engine-assembled (the entity batch and the declared channels),
// and a capability may not declare the reserved `entities` argument, so an
// args map here would be a claim the contract cannot honor.
woo_effect_check(
    ($wooManifest['actions'][2]['provider'] ?? null) === 'woocommerce-product-lookups'
        && ($wooManifest['actions'][2]['capability'] ?? null) === 'rebuild_product_lookups'
        && ($wooManifest['actions'][2]['args'] ?? null) === []
        && ($wooManifest['actions'][2]['triggers'] ?? null) === ['post:product', 'post:product_variation'],
    'the migrated lookup action names the provider capability and stays bounded to the two post types its '
        . 'retired regen_dependency declarations covered'
);
$lookupEffectsById = [];
foreach ((array) ($wooManifest['actions'][2]['effects'] ?? []) as $effect) {
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
woo_effect_check(
    ($wooManifest['providers'] ?? null) === [
        [
            'id' => 'woocommerce-cache',
            'version' => '1.0.0',
            'source' => 'manifest',
            'plugin' => 'woocommerce/woocommerce.php',
            'capabilities' => ['invalidate_cache_groups'],
        ],
        [
            'id' => 'woocommerce-product-lookups',
            'version' => '2.0.0',
            'source' => 'manifest',
            'plugin' => 'woocommerce/woocommerce.php',
            'requires' => [
                'functions' => [
                    'wc_get_product',
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
                    'as_next_scheduled_action',
                    'wp_cache_get',
                    'wp_cache_delete',
                ],
                'classes' => [
                    'Automattic\\WooCommerce\\Internal\\ProductDownloads\\ApprovedDirectories\\Register',
                    'Automattic\\WooCommerce\\Internal\\Utilities\\URL',
                    'WC_Data_Store',
                    'WC_Product_Variable',
                    'WC_Product_Grouped',
                    'WC_Cache_Helper',
                ],
            ],
            'capabilities' => ['rebuild_product_lookups'],
        ],
    ],
    'the Woo provider declarations are manifest-shipped identities owned by the version-pinned plugin, with the lookup runtime contract declared as exact requirements rather than provider code'
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
    str_contains((string) ($wooManifest['notes']['category lookup boundary (WooCommerce 11.0.0)'] ?? ''), 'outside the automatic convergence guarantee')
        && !str_contains((string) ($wooManifest['notes']['category lookup boundary (WooCommerce 11.0.0)'] ?? ''), 'automatic Duo repair'),
    'Woo manifest states category lookup as an explicit manual boundary rather than automatic authority'
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
    $providerValue = (string) ($cacheProviderResources[0]['selector']['value'] ?? '');
    woo_effect_check(
        count($providerResources) === 2
            && count($cacheProviderResources) === 1
            && count($registrationProviderResources) === 1
            && $providerValue === (string) woo_effect_product_cache_aggregate(
                $postType === 'product' ? 'woocommerce-product-cache-provider-resource' : 'woocommerce-variation-cache-provider-resource'
            )['selector']['value']
            && !str_contains($providerValue, '*')
            && str_contains($providerValue, 'wc_products_onsale')
            && str_contains($providerValue, 'wc_product_children')
            && str_contains($providerValue, 'product-transient-version')
            && str_contains($providerValue, 'wc_layered_nav_counts')
            && !array_key_exists('members', (array) ($registrationProviderResources[0]['selector'] ?? [])),
        "$postType provider-resource aggregates are explicit, finite, wildcard-free, and do not falsely narrow valid multibyte Woo filter names"
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
            && !$selectorMatches(['scope' => 'external', 'type' => 'provider_resource', 'value' => 'product_brand_relationships:42'])
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
    // DUO-3437: WooCommerce >=11.0.0 permits multibyte attribute taxonomy
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
// DUO-3342 moved Woo off the regen_dependency channel, so the channel's own
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
foreach (($wooManifest['actions'][2]['effects'] ?? []) as $index => $effect) {
    if (($effect['id'] ?? null) === 'woocommerce-product-cache-provider-resource') {
        $providerEffectIndex = (int) $index;
        break;
    }
}
woo_effect_check($providerEffectIndex !== null, 'Woo manifest test locates the bounded provider aggregate by effect id');
$providerEffectIndex ??= 0;
$badMembers = $wooManifest;
$badMembers['actions'][2]['effects'][$providerEffectIndex]['selector']['members']['templates'][0] = 'item_{unknown}';
woo_effect_expect_throw(
    fn() => woo_effect_policy_for_manifest($badMembers),
    'unknown placeholder',
    'provider-resource members reject unknown typed placeholders during policy compilation'
);
$badMembers = $wooManifest;
$badMembers['actions'][2]['effects'][$providerEffectIndex]['selector']['members']['templates'][0] = 'item_*';
woo_effect_expect_throw(
    fn() => woo_effect_policy_for_manifest($badMembers),
    'malformed, broad, or secret-shaped',
    'provider-resource members reject wildcard templates during policy compilation'
);
$badMembers = $wooManifest;
$badMembers['actions'][2]['effects'][$providerEffectIndex]['selector']['members']['unexpected'] = [];
woo_effect_expect_throw(
    fn() => woo_effect_policy_for_manifest($badMembers),
    'requires exactly exact and templates',
    'provider-resource members reject extra grammar keys during policy compilation'
);
$badMembers = $wooManifest;
$badMembers['actions'][2]['effects'][$providerEffectIndex]['selector']['unexpected'] = true;
woo_effect_expect_throw(
    fn() => woo_effect_policy_for_manifest($badMembers),
    'requires exactly scope, type, value, and optional members',
    'provider-resource selectors reject extra top-level keys during policy compilation'
);
$badMembers = $wooManifest;
$badMembers['actions'][2]['effects'][$providerEffectIndex]['selector']['members']['exact'][] = 'wc_products_onsale';
woo_effect_expect_throw(
    fn() => woo_effect_policy_for_manifest($badMembers),
    'duplicate member',
    'provider-resource members reject duplicate exact values during policy compilation'
);
$badMembers = $wooManifest;
$aggregateValue = (string) $badMembers['actions'][2]['effects'][$providerEffectIndex]['selector']['value'];
$badMembers['actions'][2]['effects'][$providerEffectIndex]['selector']['members']['exact'][] = $aggregateValue;
woo_effect_expect_throw(
    fn() => woo_effect_policy_for_manifest($badMembers),
    'malformed, broad, or secret-shaped',
    'provider-resource members reject aggregate-self exact values during policy compilation'
);

// ======================================================================
echo "\n== automatic-profile refusal ==\n";

$tmp = sys_get_temp_dir() . '/duo-woocommerce-effect-contract-' . bin2hex(random_bytes(6));
$keyPair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keyPair);
$public = sodium_crypto_sign_publickey($keyPair);
$keyId = 'woo-effect-test';
$root = $tmp . '/site/.duo/control';
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
            [PHP_BINARY, __DIR__ . '/../../fixtures/recovery-adapter.php']
        ),
        'exclusion_provider' => [
            PHP_BINARY,
            __DIR__ . '/../../fixtures/recovery-exclusion-provider.php',
            $tmp . '/exclusion.json',
        ],
        'format' => 'duo-recovery-config/v1',
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
        'format' => 'duo-exclusion-request/v1',
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
            'format' => 'duo-effect-bundle-request/v1',
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
