<?php
declare(strict_types=1);

namespace Duo\Interpreters;

use Duo\Canon;
use Duo\Policy;

/**
 * Exact WooCommerce 11.0.0 and 11.0.1 read `_product_attributes` as a
 * six-field row map.
 * Its native reader silently drops a non-array value and fills missing row
 * fields with defaults, so canonical JSON can otherwise be valid while the
 * promoted catalog loses merchant-authored attributes. Both native attribute
 * and download writers merge extension `extra_data` into postmeta, so unknown
 * row fields are addon-owned schema and must refuse rather than inherit this
 * core adapter's production claim.
 */
final class Woocommerce {
    private const REQUIRED_FIELDS = [
        'name',
        'value',
        'position',
        'is_visible',
        'is_variation',
        'is_taxonomy',
    ];

    private const DOWNLOAD_FIELDS = [
        'id',
        'name',
        'file',
        'enabled',
    ];

    private const HANDLED_POST_META = [
        '_button_text',
        '_cogs_total_value',
        '_cogs_value_is_additive',
        '_downloadable_files',
        '_product_attributes',
        '_product_url',
    ];

    private const HANDLED_TERM_META = [
        'color',
        'display_type',
        'icon',
        'image',
        'order',
        'product_ids',
        'thumbnail_id',
        'tracking_url_template',
    ];

    private const FULFILLMENT_OPTIONS = [
        'auto_fulfill_downloadable',
        'auto_fulfill_virtual',
    ];

    private const STOCK_NOTIFICATION_CYCLE_PREFIX = 'wc_stock_notifications_cycle_state_';

    private const YES_NO_OPTIONS = [
        'auto_fulfill_downloadable',
        'auto_fulfill_virtual',
        'woocommerce_analytics_scheduled_import',
        'woocommerce_customer_stock_notifications_allow_signups',
        'woocommerce_customer_stock_notifications_create_account_on_signup',
        'woocommerce_customer_stock_notifications_require_account',
        'woocommerce_customer_stock_notifications_require_double_opt_in',
        'woocommerce_enable_order_comments',
        'woocommerce_graphql_apq_enabled',
        'woocommerce_graphql_get_endpoint_enabled',
        'woocommerce_graphql_object_cache_enabled',
        'woocommerce_graphql_opcache_enabled',
        'woocommerce_rest_api_enable_cache_headers',
    ];

    private const ENUM_OPTIONS = [
        'woocommerce_category_archive_display' => ['', 'subcategories', 'both'],
        'woocommerce_date_type' => ['date_created', 'date_paid', 'date_completed'],
        'woocommerce_default_catalog_orderby' => [
            'menu_order',
            'popularity',
            'rating',
            'date',
            'price',
            'price-desc',
        ],
        'woocommerce_shop_page_display' => ['', 'subcategories', 'both'],
        'woocommerce_thumbnail_cropping' => ['1:1', 'custom', 'uncropped'],
    ];

    private const IMAGE_DIMENSION_OPTIONS = [
        'woocommerce_single_image_width' => 32768,
        'woocommerce_thumbnail_cropping_custom_height' => 1000,
        'woocommerce_thumbnail_cropping_custom_width' => 1000,
        'woocommerce_thumbnail_image_width' => 32768,
    ];

    private const MAX_IMAGE_DIMENSION = 32768;

    private const ORDER_STATUS_OPTIONS = [
        'woocommerce_actionable_order_statuses',
        'woocommerce_excluded_report_order_statuses',
    ];

    private const NATIVE_TEXT_OPTIONS = [
        'woocommerce_default_date_range' => 1024,
        'woocommerce_email_from_name' => 4096,
        'woocommerce_pos_store_name' => 4096,
    ];

    private const POSITIVE_INTEGER_OPTIONS = [
        'woocommerce_graphql_max_query_complexity',
        'woocommerce_graphql_max_query_depth',
        'woocommerce_graphql_query_cache_ttl',
    ];

    private const PICKUP_SETTINGS_FIELDS = [
        'cost',
        'enabled',
        'tax_status',
        'title',
    ];

    private const PICKUP_LOCATION_FIELDS = [
        'address',
        'details',
        'enabled',
        'name',
    ];

    private const PICKUP_ADDRESS_FIELDS = [
        'address_1',
        'city',
        'country',
        'postcode',
        'state',
    ];

    private const MAX_PICKUP_LOCATIONS = 256;
    private const MAX_PICKUP_BYTES = 1048576;

    private const PRODUCT_VISIBILITY_TERMS = [
        'exclude-from-search',
        'exclude-from-catalog',
        'featured',
        'outofstock',
        'rated-1',
        'rated-2',
        'rated-3',
        'rated-4',
        'rated-5',
    ];

    public function __construct(private readonly Policy $policy) {}

    public function post_meta_rule(string $key, array $allMeta): ?array {
        // Woo 11's optional Variation Gallery consumes the legacy extension
        // row only until its core migration sentinel is present. Excluding the
        // inert residue is safe; a populated unsentinelled row remains
        // deliberately unclassified so capture refuses before copying an
        // extension-owned attachment schema under the core adapter claim.
        if ($key === '_wc_additional_variation_images') {
            return ($allMeta['_wc_variation_gallery_legacy_fallback_disabled'] ?? null) === 'yes'
                ? ['class' => 'env']
                : null;
        }
        if (!in_array($key, self::HANDLED_POST_META, true)) {
            return null;
        }
        return $this->policy->post_meta_rule($key);
    }

    public function term_meta_rule(string $key, array $allMeta): ?array {
        // WC_Term_Data_Store writes this cache family only as term metadata.
        // Manifest meta_patterns are shared by post and term policy lookup, so
        // keeping this exact context ruling here prevents an identically named
        // post row from being silently discarded as derived state.
        if (preg_match('/^product_count_(?:product_cat|product_tag|product_brand)$/D', $key) === 1) {
            return ['class' => 'derived'];
        }
        if (!in_array($key, self::HANDLED_TERM_META, true)) {
            return null;
        }
        return $this->policy->term_meta_rule($key);
    }

    public function option_rule(string $name, array $allOptions): ?array {
        if (str_starts_with($name, self::STOCK_NOTIFICATION_CYCLE_PREFIX)) {
            $suffix = substr($name, strlen(self::STOCK_NOTIFICATION_CYCLE_PREFIX));
            $productId = Policy::strict_positive_local_id($suffix);
            return $productId !== null && (string) $productId === $suffix
                ? ['class' => 'runtime']
                : null;
        }
        if (!in_array($name, self::FULFILLMENT_OPTIONS, true)) {
            return null;
        }
        return $this->policy->option_rule($name);
    }

    /** Push preferences are site-scoped device state keyed by the current blog-table suffix. */
    public function user_meta_rule(string $key, array $allMeta): ?array {
        global $wpdb;

        if (!function_exists('get_current_blog_id')
            || !is_object($wpdb)
            || !is_callable([$wpdb, 'get_blog_prefix'])) {
            return null;
        }

        $prefix = $wpdb->get_blog_prefix((int) get_current_blog_id());
        if (!is_string($prefix) || preg_match('/^[a-z0-9_]+$/Di', $prefix) !== 1) {
            return null;
        }
        $siteSuffix = rtrim($prefix, '_');
        if ($siteSuffix === '' || strlen($siteSuffix) > 220) {
            return null;
        }

        $expected = 'wc_push_notification_preferences_' . $siteSuffix;
        return hash_equals($expected, $key) ? ['class' => 'runtime'] : null;
    }

    /** @return list<array<string,mixed>> */
    public function repository_diagnostics(array $tree): array {
        $out = [];
        $postIndex = [];
        $termIndex = [];
        $visibilityInventory = [];
        $hasProduct = false;
        foreach ($tree as $entity) {
            $entityType = (string) ($entity['type'] ?? '');
            if ($entityType === 'post') {
                $front = $entity['data'] ?? Canon::parse_post_file((string) ($entity['content'] ?? ''))[0];
                $uuid = (string) ($front['uuid'] ?? '');
                if ($uuid !== '') {
                    $postIndex[$uuid] = $front;
                }
                $hasProduct = $hasProduct
                    || in_array((string) ($front['type'] ?? ''), ['product', 'product_variation'], true);
                continue;
            }
            if ($entityType === 'term') {
                $front = (array) ($entity['data'] ?? []);
                $uuid = (string) ($front['uuid'] ?? '');
                if ($uuid !== '') {
                    $termIndex[$uuid] = $front;
                }
                if (($front['taxonomy'] ?? null) === 'product_visibility') {
                    $visibilityInventory[] = (string) ($front['slug'] ?? '');
                }
            }
        }
        if ($hasProduct || $visibilityInventory !== []) {
            sort($visibilityInventory, SORT_STRING);
            $expected = self::PRODUCT_VISIBILITY_TERMS;
            sort($expected, SORT_STRING);
            if ($visibilityInventory !== $expected) {
                $out[] = $this->diagnostic(
                    'state/terms/product_visibility',
                    'taxonomy.inventory',
                    'WooCommerce repositories with products require exactly one of every core product_visibility term'
                );
            }
        }
        foreach ($tree as $entity) {
            $entityType = (string) ($entity['type'] ?? '');
            if ($entityType === 'post') {
                $out = array_merge($out, $this->post_diagnostics($entity, $postIndex, $termIndex));
                continue;
            }
            if ($entityType === 'term') {
                $out = array_merge($out, $this->term_diagnostics($entity, $postIndex));
                continue;
            }
            if ($entityType === 'options') {
                $out = array_merge($out, $this->option_diagnostics($entity));
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function post_diagnostics(array $entity, array $postIndex, array $termIndex): array {
        $out = [];
        $front = $entity['data'] ?? Canon::parse_post_file((string) ($entity['content'] ?? ''))[0];
        $meta = (array) ($front['meta'] ?? []);
        $postType = (string) ($front['type'] ?? '');
        $path = (string) ($entity['path'] ?? '');
        if (in_array($postType, ['product', 'product_variation'], true)) {
            $out = array_merge(
                $out,
                $this->visibility_diagnostics($path, $front, $postIndex, $termIndex)
            );
        }
        if (array_key_exists('_product_attributes', $meta)) {
            if ($postType !== 'product') {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._product_attributes',
                    'WooCommerce product attributes are valid only on product entities'
                );
            } else {
                $out = array_merge($out, $this->attribute_diagnostics($path, $meta['_product_attributes']));
            }
        }
        if (array_key_exists('_downloadable_files', $meta)) {
            if (!in_array($postType, ['product', 'product_variation'], true)) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._downloadable_files',
                    'WooCommerce downloadable files are valid only on product or product_variation entities'
                );
            } else {
                $out = array_merge($out, $this->download_diagnostics($path, $meta['_downloadable_files']));
            }
        }
        foreach (['_product_url', '_button_text'] as $key) {
            if (!array_key_exists($key, $meta)) {
                continue;
            }
            if ($postType !== 'product') {
                $out[] = $this->diagnostic(
                    $path,
                    "meta.$key",
                    'WooCommerce external-product fields are valid only on product entities'
                );
                continue;
            }
            if ($key === '_product_url') {
                $out = array_merge($out, $this->url_diagnostics(
                    $path,
                    "meta.$key",
                    $meta[$key],
                    false,
                    'WooCommerce external product URL',
                    false
                ));
            } else {
                $textDiagnostics = $this->bounded_text_diagnostics(
                    $path,
                    "meta.$key",
                    $meta[$key],
                    4096,
                    'WooCommerce external product button text'
                );
                $out = array_merge($out, $textDiagnostics);
                if ($textDiagnostics === [] && is_string($meta[$key])) {
                    $out = array_merge($out, $this->native_text_canonical_diagnostics(
                        $path,
                        "meta.$key",
                        $meta[$key],
                        'WooCommerce external product button text'
                    ));
                }
            }
        }
        if (array_key_exists('_cogs_total_value', $meta)) {
            if (!in_array($postType, ['product', 'product_variation'], true)) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._cogs_total_value',
                    'WooCommerce Cost of Goods value is valid only on product or product_variation entities'
                );
            } elseif (!is_string($meta['_cogs_total_value'])
                || !self::cogs_meta_value_supported($meta['_cogs_total_value'])) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._cogs_total_value',
                    'WooCommerce Cost of Goods value must use the exact native float storage spelling and fit the DECIMAL(19,4) lookup boundary'
                );
            } elseif ($postType === 'product' && (float) $meta['_cogs_total_value'] === 0.0) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._cogs_total_value',
                    'WooCommerce base-product Cost of Goods zero must be represented by metadata absence'
                );
            }
        }
        if (array_key_exists('_cogs_value_is_additive', $meta)) {
            if ($postType !== 'product_variation') {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._cogs_value_is_additive',
                    'WooCommerce additive Cost of Goods state is valid only on product_variation entities'
                );
            } elseif ($meta['_cogs_value_is_additive'] !== 'yes') {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._cogs_value_is_additive',
                    "WooCommerce additive Cost of Goods state must be exact 'yes'; false is represented by absence"
                );
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function visibility_diagnostics(string $path, array $front, array $postIndex, array $termIndex): array {
        $out = [];
        $postType = (string) ($front['type'] ?? '');
        $terms = (array) ($front['terms'] ?? []);
        $visibility = $this->relationship_slugs(
            $path,
            'terms.product_visibility',
            $terms['product_visibility'] ?? [],
            'product_visibility',
            $termIndex,
            $out
        );
        $pos = $this->relationship_slugs(
            $path,
            'terms.pos_product_visibility',
            $terms['pos_product_visibility'] ?? [],
            'pos_product_visibility',
            $termIndex,
            $out
        );

        $ratings = array_values(array_filter(
            $visibility,
            static fn(string $slug): bool => str_starts_with($slug, 'rated-')
        ));
        if (count($ratings) > 1) {
            $out[] = $this->diagnostic(
                $path,
                'terms.product_visibility',
                'WooCommerce product visibility may contain at most one native rated-* projection term'
            );
        }
        if ($postType === 'product_variation') {
            $unsupported = array_values(array_diff($visibility, ['outofstock']));
            if ($unsupported !== []) {
                $out[] = $this->diagnostic(
                    $path,
                    'terms.product_visibility',
                    'WooCommerce variations may carry only the native outofstock visibility projection'
                );
            }
        }
        if (array_diff($pos, ['pos-hidden']) !== [] || count($pos) > 1) {
            $out[] = $this->diagnostic(
                $path,
                'terms.pos_product_visibility',
                'WooCommerce POS visibility accepts only one exact pos-hidden relationship'
            );
        }

        if ($postType === 'product') {
            $productTypes = $this->relationship_slugs(
                $path,
                'terms.product_type',
                $terms['product_type'] ?? [],
                'product_type',
                $termIndex,
                $out
            );
            $productType = count($productTypes) === 1 ? $productTypes[0] : null;
            if ($productType === null
                || !in_array($productType, ['simple', 'grouped', 'variable', 'external'], true)) {
                $out[] = $this->diagnostic(
                    $path,
                    'terms.product_type',
                    'WooCommerce products require exactly one admitted core product_type relationship'
                );
            }
            $postMeta = (array) ($front['meta'] ?? []);
            $downloadable = (($postMeta['_downloadable'] ?? null) === 'yes');
            if ($pos !== [] && ($downloadable || !in_array($productType, ['simple', 'variable'], true))) {
                $out[] = $this->diagnostic(
                    $path,
                    'terms.pos_product_visibility',
                    'WooCommerce pos-hidden is valid only for a non-downloadable simple or variable product'
                );
            }
        } else {
            $parentUuid = (string) ($front['parent'] ?? '');
            $parent = is_array($postIndex[$parentUuid] ?? null) ? $postIndex[$parentUuid] : null;
            if (($parentUuid !== '' || $pos !== [])
                && ($parent === null || ($parent['type'] ?? null) !== 'product')) {
                $out[] = $this->diagnostic(
                    $path,
                    'parent',
                    'WooCommerce variation visibility requires an exact product parent in the repository'
                );
            } elseif ($parent !== null) {
                $parentTerms = (array) ($parent['terms'] ?? []);
                $parentTypes = $this->relationship_slugs(
                    $path,
                    'parent.terms.product_type',
                    $parentTerms['product_type'] ?? [],
                    'product_type',
                    $termIndex,
                    $out
                );
                if ($parentTypes !== ['variable']) {
                    $out[] = $this->diagnostic(
                        $path,
                        'parent.terms.product_type',
                        'WooCommerce variations require exactly one variable product_type parent'
                    );
                }
                $parentPos = $this->relationship_slugs(
                    $path,
                    'parent.terms.pos_product_visibility',
                    $parentTerms['pos_product_visibility'] ?? [],
                    'pos_product_visibility',
                    $termIndex,
                    $out
                );
                if (($pos === ['pos-hidden']) !== ($parentPos === ['pos-hidden'])) {
                    $out[] = $this->diagnostic(
                        $path,
                        'terms.pos_product_visibility',
                        'WooCommerce variation POS visibility must exactly inherit its variable parent'
                    );
                }
            }
        }
        return $out;
    }

    /** @param list<array<string,mixed>> $out @return list<string> */
    private function relationship_slugs(
        string $path,
        string $locator,
        mixed $references,
        string $taxonomy,
        array $termIndex,
        array &$out
    ): array {
        if (!is_array($references) || !array_is_list($references)) {
            $out[] = $this->diagnostic(
                $path,
                $locator,
                "WooCommerce $taxonomy relationships must be a canonical list of term identities"
            );
            return [];
        }
        $slugs = [];
        $seen = [];
        foreach ($references as $reference) {
            if (!is_string($reference) || isset($seen[$reference])) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    "WooCommerce $taxonomy relationships contain a malformed or duplicate term identity"
                );
                continue;
            }
            $seen[$reference] = true;
            $term = $termIndex[$reference] ?? null;
            if (!is_array($term) || ($term['taxonomy'] ?? null) !== $taxonomy) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    "WooCommerce $taxonomy relationship does not resolve to the exact taxonomy"
                );
                continue;
            }
            $slugs[] = (string) ($term['slug'] ?? '');
        }
        sort($slugs, SORT_STRING);
        return $slugs;
    }

    private static function cogs_meta_value_supported(string $value): bool {
        if ($value === '' || strlen($value) > 128
            || preg_match('/^-?(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:E[+-]?(?:0|[1-9][0-9]*))?$/D', $value) !== 1) {
            return false;
        }
        $number = (float) $value;
        if (!is_finite($number)) {
            return false;
        }
        // Every exact 11.0.x core writer passes a float to post metadata.
        // WordPress therefore persists PHP's string cast, not an arbitrary
        // equivalent decimal spelling. Refusing e.g. 1.2300/1.0E+3/0E+9
        // keeps direct SQL apply inside the same byte grammar a native save
        // can create and makes recapture stable without normalizing data.
        if ($value !== (string) $number) {
            return false;
        }
        $mantissa = explode('E', ltrim($value, '-'), 2)[0];
        if ($number === 0.0 && preg_match('/[1-9]/', $mantissa) === 1) {
            // A source float this small reaches set_cogs_value() as zero and
            // cannot persist the exponent bytes captured in the repository.
            return false;
        }
        // wpdb binds Woo's float derivation through its string form. Reparse
        // that exact transport spelling before applying the DECIMAL bound so
        // PHP precision that renders a near-limit value as 1.0E+15 refuses.
        $transport = (float) (string) $number;
        return is_finite($transport) && abs($transport) < 1000000000000000.0;
    }

    /** @return list<array<string,mixed>> */
    private function term_diagnostics(array $entity, array $postIndex): array {
        $front = $entity['data'] ?? Canon::decode((string) ($entity['content'] ?? ''));
        $taxonomy = (string) ($front['taxonomy'] ?? '');
        $meta = (array) ($front['meta'] ?? []);
        $path = (string) ($entity['path'] ?? '');
        $out = [];

        if (in_array($taxonomy, ['product_type', 'product_visibility', 'pos_product_visibility'], true)) {
            $slug = (string) ($front['slug'] ?? '');
            $name = (string) ($front['name'] ?? '');
            $allowed = $taxonomy === 'product_type'
                ? ['simple', 'grouped', 'variable', 'external']
                : ($taxonomy === 'product_visibility' ? self::PRODUCT_VISIBILITY_TERMS : ['pos-hidden']);
            if (!in_array($slug, $allowed, true) || $name !== $slug) {
                $out[] = $this->diagnostic(
                    $path,
                    'slug',
                    "WooCommerce $taxonomy permits only its exact core slug/name identities"
                );
            }
            if (($front['parent'] ?? null) !== null
                || (string) ($front['description'] ?? '') !== ''
                || $meta !== []
                || (array) ($front['relationships'] ?? []) !== []) {
                $out[] = $this->diagnostic(
                    $path,
                    'taxonomy',
                    "WooCommerce $taxonomy core projection terms must have no parent, description, metadata, or outbound relationships"
                );
            }
        }

        $allowedTaxonomies = [
            'color' => 'global product attribute',
            'image' => 'global product attribute',
            'display_type' => 'product_cat or product_brand',
            'order' => 'product_cat, product_brand, or global product attribute',
            'thumbnail_id' => 'product_cat or product_brand',
            'tracking_url_template' => 'wc_fulfillment_shipping_provider',
            'icon' => 'wc_fulfillment_shipping_provider',
            'product_ids' => 'product taxonomy cache',
        ];
        foreach ($allowedTaxonomies as $key => $description) {
            if (!array_key_exists($key, $meta) || $this->term_key_matches_taxonomy($key, $taxonomy)) {
                continue;
            }
            $out[] = $this->diagnostic(
                $path,
                "meta.$key",
                "WooCommerce term meta $key is valid only on $description terms"
            );
        }
        if (array_key_exists('display_type', $meta)
            && (!is_string($meta['display_type'])
                || !in_array($meta['display_type'], ['', 'products', 'subcategories', 'both'], true))) {
            $out[] = $this->diagnostic(
                $path,
                'meta.display_type',
                'WooCommerce category/brand REST display type must be default, products, subcategories, or both'
            );
        }
        if (array_key_exists('order', $meta)
            && (!is_string($meta['order'])
                || preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $meta['order']) !== 1
                || (int) $meta['order'] > 2147483647)) {
            $out[] = $this->diagnostic(
                $path,
                'meta.order',
                'WooCommerce term order must be a canonical non-negative 32-bit integer string'
            );
        }
        if (array_key_exists('color', $meta)
            && (!is_string($meta['color'])
                || preg_match('/^#(?:[0-9A-Fa-f]{3}|[0-9A-Fa-f]{6})$/D', $meta['color']) !== 1)) {
            $out[] = $this->diagnostic(
                $path,
                'meta.color',
                'WooCommerce visual attribute color must be an exact three- or six-digit hex color'
            );
        }
        if (array_key_exists('color', $meta) && array_key_exists('image', $meta)) {
            $out[] = $this->diagnostic(
                $path,
                'meta',
                'WooCommerce visual attribute color and image are mutually exclusive native states'
            );
        }
        foreach (['image', 'thumbnail_id'] as $key) {
            if (array_key_exists($key, $meta)) {
                $out = array_merge($out, $this->image_attachment_ref_diagnostics(
                    $path,
                    "meta.$key",
                    $meta[$key],
                    $postIndex
                ));
            }
        }
        foreach (['tracking_url_template', 'icon'] as $key) {
            if (array_key_exists($key, $meta)) {
                $out = array_merge($out, $this->url_diagnostics(
                    $path,
                    "meta.$key",
                    $meta[$key],
                    true,
                    "WooCommerce fulfillment provider $key",
                    true
                ));
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function image_attachment_ref_diagnostics(
        string $path,
        string $locator,
        mixed $value,
        array $postIndex
    ): array {
        if (!is_string($value)
            || preg_match('/^\{\{post:([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})\}\}$/D', $value, $match) !== 1) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce term image metadata must be one canonical post reference token'
            )];
        }
        $target = $postIndex[$match[1]] ?? null;
        if (!is_array($target)
            || ($target['type'] ?? null) !== 'attachment'
            || !is_string($target['mime'] ?? null)
            || !str_starts_with($target['mime'], 'image/')
            || !is_string($target['file'] ?? null)
            || $target['file'] === '') {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce term image metadata must resolve to a live image attachment in this repository revision'
            )];
        }
        return [];
    }

    private function term_key_matches_taxonomy(string $key, string $taxonomy): bool {
        return match ($key) {
            'color', 'image' => $this->is_global_attribute_name($taxonomy),
            'display_type' => in_array($taxonomy, ['product_cat', 'product_brand'], true),
            'order' => in_array($taxonomy, ['product_cat', 'product_brand'], true)
                || $this->is_global_attribute_name($taxonomy),
            'thumbnail_id' => in_array($taxonomy, ['product_cat', 'product_brand'], true),
            'tracking_url_template', 'icon' => $taxonomy === 'wc_fulfillment_shipping_provider',
            'product_ids' => $taxonomy === 'product_cat'
                || $taxonomy === 'product_brand'
                || $taxonomy === 'product_tag'
                || $this->is_global_attribute_name($taxonomy),
            default => false,
        };
    }

    /** @return list<array<string,mixed>> */
    private function option_diagnostics(array $entity): array {
        $front = $entity['data'] ?? Canon::decode((string) ($entity['content'] ?? ''));
        $records = (array) ($front['records'] ?? []);
        $path = (string) ($entity['path'] ?? '');
        $out = [];
        foreach ($records as $name => $record) {
            if (!is_string($name) || !is_array($record) || ($record['state'] ?? null) !== 'present') {
                continue;
            }
            $value = $record['value'] ?? null;
            $locator = "records.$name.value";
            if (in_array($name, self::YES_NO_OPTIONS, true)) {
                if (is_string($value) && in_array($value, ['yes', 'no'], true)) {
                    continue;
                }
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    "WooCommerce option $name must be exact yes or no"
                );
                continue;
            }
            if (array_key_exists($name, self::ENUM_OPTIONS)) {
                if (!is_string($value) || !in_array($value, self::ENUM_OPTIONS[$name], true)) {
                    $out[] = $this->diagnostic(
                        $path,
                        $locator,
                        "WooCommerce option $name is outside its exact native value set"
                    );
                }
                continue;
            }
            if (in_array($name, self::ORDER_STATUS_OPTIONS, true)) {
                $out = array_merge($out, $this->order_statuses_diagnostics($path, $locator, $value, $name));
                continue;
            }
            if (array_key_exists($name, self::NATIVE_TEXT_OPTIONS)) {
                $textDiagnostics = $this->bounded_text_diagnostics(
                    $path,
                    $locator,
                    $value,
                    self::NATIVE_TEXT_OPTIONS[$name],
                    "WooCommerce option $name"
                );
                $out = array_merge($out, $textDiagnostics);
                if ($textDiagnostics === [] && is_string($value)) {
                    $out = array_merge($out, $this->native_text_canonical_diagnostics(
                        $path,
                        $locator,
                        $value,
                        "WooCommerce option $name"
                    ));
                }
                continue;
            }
            if (array_key_exists($name, self::IMAGE_DIMENSION_OPTIONS)) {
                $out = array_merge($out, $this->bounded_absint_diagnostics(
                    $path,
                    $locator,
                    $value,
                    $name,
                    self::IMAGE_DIMENSION_OPTIONS[$name]
                ));
                continue;
            }
            if (in_array($name, self::POSITIVE_INTEGER_OPTIONS, true)) {
                $out = array_merge($out, $this->positive_integer_diagnostics($path, $locator, $value, $name));
                continue;
            }
            if ($name === 'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold') {
                if (!is_string($value)
                    || ($value !== ''
                        && (preg_match('/^(?:0|[1-9][0-9]{0,6})$/D', $value) !== 1
                            || (int) $value > 3650000))) {
                    $out[] = $this->diagnostic(
                        $path,
                        $locator,
                        'WooCommerce stock-notification retention must be canonical whole days from 0 through 3650000'
                    );
                }
                continue;
            }
            if ($name === 'woocommerce_graphql_endpoint_url') {
                $out = array_merge($out, $this->graphql_endpoint_diagnostics($path, $locator, $value));
                continue;
            }
            if ($name === 'woocommerce_gateway_order') {
                $out = array_merge($out, $this->gateway_order_diagnostics($path, $locator, $value));
                continue;
            }
            if ($name === 'woocommerce_pickup_location_settings') {
                $out = array_merge($out, $this->pickup_settings_diagnostics($path, $locator, $value));
                continue;
            }
            if ($name === 'pickup_location_pickup_locations') {
                $out = array_merge($out, $this->pickup_locations_diagnostics($path, $locator, $value));
                continue;
            }
            if ($name === 'woocommerce_checkout_terms_and_conditions_checkbox_text') {
                $out = array_merge($out, $this->native_html_diagnostics(
                    $path,
                    $locator,
                    $value,
                    16384,
                    'WooCommerce checkout terms checkbox text'
                ));
            }
        }
        $out = array_merge($out, $this->thumbnail_projection_diagnostics($path, $records));
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function bounded_absint_diagnostics(
        string $path,
        string $locator,
        mixed $value,
        string $name,
        int $max
    ): array {
        if (!is_string($value)
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1
            || strlen($value) > 19
            || (string) (int) $value !== $value
            || (int) $value > $max) {
            return [$this->diagnostic(
                $path,
                $locator,
                "WooCommerce option $name must be a canonical native absint string from 0 through $max"
            )];
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function thumbnail_projection_diagnostics(string $path, array $records): array {
        $mode = $this->present_option_string($records, 'woocommerce_thumbnail_cropping') ?? '1:1';
        if ($mode !== 'custom') {
            return [];
        }

        $thumbnailWidth = $this->canonical_present_absint(
            $records,
            'woocommerce_thumbnail_image_width',
            300,
            self::IMAGE_DIMENSION_OPTIONS['woocommerce_thumbnail_image_width']
        );
        $ratioWidth = $this->canonical_present_absint(
            $records,
            'woocommerce_thumbnail_cropping_custom_width',
            4,
            self::IMAGE_DIMENSION_OPTIONS['woocommerce_thumbnail_cropping_custom_width']
        );
        $ratioHeight = $this->canonical_present_absint(
            $records,
            'woocommerce_thumbnail_cropping_custom_height',
            3,
            self::IMAGE_DIMENSION_OPTIONS['woocommerce_thumbnail_cropping_custom_height']
        );
        if ($thumbnailWidth === null || $ratioWidth === null || $ratioHeight === null) {
            return [];
        }

        $projectedHeight = (int) round(
            ($thumbnailWidth / max(1, $ratioWidth)) * max(1, $ratioHeight)
        );
        if ($projectedHeight <= self::MAX_IMAGE_DIMENSION) {
            return [];
        }
        return [$this->diagnostic(
            $path,
            'records.woocommerce_thumbnail_cropping_custom_height.value',
            'WooCommerce custom thumbnail ratio would exceed the 32768-pixel derived image boundary'
        )];
    }

    private function present_option_string(array $records, string $name): ?string {
        $record = $records[$name] ?? null;
        return is_array($record)
            && ($record['state'] ?? null) === 'present'
            && is_string($record['value'] ?? null)
                ? $record['value']
                : null;
    }

    private function canonical_present_absint(array $records, string $name, int $default, int $max): ?int {
        $value = $this->present_option_string($records, $name);
        if ($value === null) {
            return $default;
        }
        if (preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1
            || strlen($value) > 19
            || (string) (int) $value !== $value
            || (int) $value > $max) {
            return null;
        }
        return (int) $value;
    }

    /** @return list<array<string,mixed>> */
    private function order_statuses_diagnostics(
        string $path,
        string $locator,
        mixed $value,
        string $name
    ): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > 128) {
            return [$this->diagnostic(
                $path,
                $locator,
                "WooCommerce option $name must be a list of at most 128 order-status slugs"
            )];
        }
        $seen = [];
        foreach ($value as $status) {
            if (!is_string($status)
                || strlen($status) > 64
                || preg_match('/^[a-z0-9][a-z0-9_-]*$/D', $status) !== 1
                || isset($seen[$status])) {
                return [$this->diagnostic(
                    $path,
                    $locator,
                    "WooCommerce option $name must contain unique bounded order-status slugs"
                )];
            }
            $seen[$status] = true;
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function pickup_settings_diagnostics(string $path, string $locator, mixed $value): array {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce local-pickup settings must be the exact native named record'
            )];
        }
        if ($value === []) {
            return [];
        }
        $fields = array_keys($value);
        sort($fields, SORT_STRING);
        if (array_diff($fields, self::PICKUP_SETTINGS_FIELDS) !== []) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce local-pickup settings permit only enabled, title, tax_status, and cost'
            )];
        }

        $out = [];
        if (array_key_exists('enabled', $value)
            && (!is_string($value['enabled']) || !in_array($value['enabled'], ['yes', 'no'], true))) {
            $out[] = $this->diagnostic(
                $path,
                "$locator.enabled",
                'WooCommerce local-pickup enabled must be exact yes or no'
            );
        }
        if (array_key_exists('tax_status', $value)
            && (!is_string($value['tax_status']) || !in_array($value['tax_status'], ['taxable', 'none'], true))) {
            $out[] = $this->diagnostic(
                $path,
                "$locator.tax_status",
                'WooCommerce local-pickup tax_status must be exact taxable or none'
            );
        }
        if (array_key_exists('title', $value)) {
            $titleDiagnostics = $this->bounded_text_diagnostics(
                $path,
                "$locator.title",
                $value['title'],
                4096,
                'WooCommerce local-pickup title'
            );
            $out = array_merge($out, $titleDiagnostics);
            if ($titleDiagnostics === [] && is_string($value['title'])) {
                $out = array_merge($out, $this->native_text_canonical_diagnostics(
                    $path,
                    "$locator.title",
                    $value['title'],
                    'WooCommerce local-pickup title'
                ));
            }
        }
        if (array_key_exists('cost', $value)) {
            $out = array_merge($out, $this->pickup_cost_diagnostics(
                $path,
                "$locator.cost",
                $value['cost']
            ));
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function pickup_cost_diagnostics(string $path, string $locator, mixed $value): array {
        $textDiagnostics = $this->bounded_text_diagnostics(
            $path,
            $locator,
            $value,
            1024,
            'WooCommerce local-pickup cost'
        );
        if ($textDiagnostics !== [] || !is_string($value) || $value === '') {
            return $textDiagnostics;
        }
        if (!function_exists('wc_format_decimal')) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce local-pickup cost cannot be validated because its native calculation API is unavailable'
            )];
        }
        $formatted = \wc_format_decimal($value, false);
        if (!is_string($formatted) || !is_numeric($value) || !hash_equals($value, $formatted)) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce local-pickup cost must already equal its exact native decimal calculation bytes'
            )];
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function pickup_locations_diagnostics(string $path, string $locator, mixed $value): array {
        if (!is_array($value) || !array_is_list($value) || count($value) > self::MAX_PICKUP_LOCATIONS) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce pickup locations must be an ordered list of at most 256 native records'
            )];
        }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded) || strlen($encoded) > self::MAX_PICKUP_BYTES) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce pickup locations exceed the one-megabyte aggregate boundary'
            )];
        }

        $out = [];
        foreach ($value as $index => $location) {
            $rowLocator = "$locator.$index";
            if (!is_array($location) || array_is_list($location)) {
                $out[] = $this->diagnostic(
                    $path,
                    $rowLocator,
                    'WooCommerce pickup location must be the exact native named record'
                );
                continue;
            }
            $fields = array_keys($location);
            sort($fields, SORT_STRING);
            if ($fields !== self::PICKUP_LOCATION_FIELDS) {
                $out[] = $this->diagnostic(
                    $path,
                    $rowLocator,
                    'WooCommerce pickup location permits only name, address, details, and enabled'
                );
                continue;
            }
            foreach (['name' => 4096, 'details' => 16384] as $field => $maxBytes) {
                $textDiagnostics = $this->bounded_text_diagnostics(
                    $path,
                    "$rowLocator.$field",
                    $location[$field],
                    $maxBytes,
                    "WooCommerce pickup location $field"
                );
                $out = array_merge($out, $textDiagnostics);
                if ($textDiagnostics === [] && is_string($location[$field])) {
                    $out = array_merge(
                        $out,
                        in_array($field, ['name', 'details'], true)
                            ? $this->native_html_diagnostics(
                                $path,
                                "$rowLocator.$field",
                                $location[$field],
                                $maxBytes,
                                "WooCommerce pickup location $field"
                            )
                            : $this->native_text_canonical_diagnostics(
                                $path,
                                "$rowLocator.$field",
                                $location[$field],
                                'WooCommerce pickup location name'
                            )
                    );
                }
            }
            if (!is_bool($location['enabled'])) {
                $out[] = $this->diagnostic(
                    $path,
                    "$rowLocator.enabled",
                    'WooCommerce pickup location enabled must be a native REST boolean'
                );
            }
            $address = $location['address'];
            if (!is_array($address) || array_is_list($address)) {
                $out[] = $this->diagnostic(
                    $path,
                    "$rowLocator.address",
                    'WooCommerce pickup location address must be the exact native named record'
                );
                continue;
            }
            $addressFields = array_keys($address);
            sort($addressFields, SORT_STRING);
            if ($addressFields !== self::PICKUP_ADDRESS_FIELDS) {
                $out[] = $this->diagnostic(
                    $path,
                    "$rowLocator.address",
                    'WooCommerce pickup location address permits only address_1, city, state, postcode, and country'
                );
                continue;
            }
            foreach (self::PICKUP_ADDRESS_FIELDS as $field) {
                $textDiagnostics = $this->bounded_text_diagnostics(
                    $path,
                    "$rowLocator.address.$field",
                    $address[$field],
                    4096,
                    "WooCommerce pickup location address $field"
                );
                $out = array_merge($out, $textDiagnostics);
                if ($textDiagnostics === [] && is_string($address[$field])) {
                    $out = array_merge($out, $this->native_text_canonical_diagnostics(
                        $path,
                        "$rowLocator.address.$field",
                        $address[$field],
                        "WooCommerce pickup location address $field"
                    ));
                }
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function positive_integer_diagnostics(
        string $path,
        string $locator,
        mixed $value,
        string $name
    ): array {
        $normalized = is_string($value) ? ltrim($value, '0') : '';
        if (!is_string($value)
            || preg_match('/^[0-9]+$/D', $value) !== 1
            || strlen($value) > 19
            || (int) $value < 1
            || (string) (int) $normalized !== $normalized) {
            return [$this->diagnostic(
                $path,
                $locator,
                "WooCommerce option $name must be a positive PHP-range integer string"
            )];
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function graphql_endpoint_diagnostics(string $path, string $locator, mixed $value): array {
        $textDiagnostics = $this->bounded_text_diagnostics(
            $path,
            $locator,
            $value,
            512,
            'WooCommerce GraphQL endpoint path'
        );
        if ($textDiagnostics !== [] || !is_string($value)) {
            return $textDiagnostics;
        }
        $parts = explode('/', $value);
        if (count($parts) < 2) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce GraphQL endpoint path must contain at least two segments'
            )];
        }
        foreach ($parts as $part) {
            if ($part === '' || preg_match('/^[A-Za-z0-9_-]+$/D', $part) !== 1) {
                return [$this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce GraphQL endpoint path must already equal its native normalized segment grammar'
                )];
            }
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function gateway_order_diagnostics(string $path, string $locator, mixed $value): array {
        if (!is_array($value) || ($value !== [] && array_is_list($value)) || count($value) > 256) {
            return [$this->diagnostic(
                $path,
                $locator,
                'WooCommerce gateway order must be a named map of at most 256 stable provider ids'
            )];
        }
        $orders = [];
        foreach ($value as $gateway => $order) {
            if (!is_string($gateway)
                || strlen($gateway) > 128
                || preg_match('/^[a-z0-9_][a-z0-9_.-]*$/D', $gateway) !== 1
                || !is_int($order)
                || $order < 0
                || $order > 100000
                || isset($orders[$order])) {
                return [$this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce gateway order must contain unique bounded integer positions keyed by stable provider ids'
                )];
            }
            $orders[$order] = true;
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function native_html_diagnostics(
        string $path,
        string $locator,
        mixed $value,
        int $maxBytes,
        string $label
    ): array {
        $textDiagnostics = $this->bounded_text_diagnostics($path, $locator, $value, $maxBytes, $label);
        if ($textDiagnostics !== [] || !is_string($value)) {
            return $textDiagnostics;
        }
        if (!function_exists('wp_kses_post')) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label cannot be validated because the native WordPress HTML sanitizer is unavailable"
            )];
        }
        $sanitized = \wp_kses_post($value);
        if (!is_string($sanitized) || !hash_equals($value, $sanitized)) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label must already equal the exact native WordPress HTML-sanitized bytes"
            )];
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function bounded_text_diagnostics(
        string $path,
        string $locator,
        mixed $value,
        int $maxBytes,
        string $label
    ): array {
        if (!is_string($value)) {
            return [$this->diagnostic($path, $locator, "$label must be a string")];
        }
        if (strlen($value) > $maxBytes || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label must be valid UTF-8 without controls and at most $maxBytes bytes"
            )];
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function url_diagnostics(
        string $path,
        string $locator,
        mixed $value,
        bool $allowEmpty,
        string $label,
        bool $requireNativeFilter
    ): array {
        $textDiagnostics = $this->bounded_text_diagnostics($path, $locator, $value, 8192, $label);
        if ($textDiagnostics !== [] || !is_string($value)) {
            return $textDiagnostics;
        }
        if ($value === '' && $allowEmpty) {
            return [];
        }
        if ($value === '') {
            return [$this->diagnostic($path, $locator, "$label must be non-empty")];
        }
        $testable = str_replace('__PLACEHOLDER__', 'test', $value);
        foreach (['{{home}}', '{{uploads}}'] as $token) {
            if (str_starts_with($testable, $token)) {
                $testable = 'https://portable.invalid' . substr($testable, strlen($token));
                break;
            }
        }
        $parts = parse_url($testable);
        if (!is_array($parts)
            || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || (string) ($parts['host'] ?? '') === ''
            || array_key_exists('user', $parts)
            || array_key_exists('pass', $parts)
            || preg_match('/\s/u', $testable) === 1
            || ($requireNativeFilter && filter_var($testable, FILTER_VALIDATE_URL) === false)) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label must be a portable HTTP or HTTPS URL without credentials"
            )];
        }
        return $this->native_url_canonical_diagnostics($path, $locator, $value, $label);
    }

    /** @return list<array<string,mixed>> */
    private function native_url_canonical_diagnostics(
        string $path,
        string $locator,
        string $value,
        string $label
    ): array {
        if (!function_exists('esc_url_raw')) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label cannot be validated because the native WordPress URL sanitizer is unavailable"
            )];
        }
        $candidate = str_replace(
            ['{{home}}', '{{uploads}}'],
            ['https://portable.invalid', 'https://portable.invalid/uploads'],
            $value
        );
        $candidate = preg_replace(
            '/\{\{post:[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\}\}/D',
            '1',
            $candidate
        );
        $sanitized = is_string($candidate) ? \esc_url_raw($candidate, ['http', 'https']) : null;
        if (!is_string($sanitized) || !is_string($candidate) || !hash_equals($candidate, $sanitized)) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label must already equal the exact native WordPress URL-sanitized bytes"
            )];
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function native_text_canonical_diagnostics(
        string $path,
        string $locator,
        string $value,
        string $label
    ): array {
        if (!function_exists('sanitize_text_field')) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label cannot be validated because the native WordPress text sanitizer is unavailable"
            )];
        }
        $sanitized = \sanitize_text_field($value);
        if (!is_string($sanitized) || !hash_equals($value, $sanitized)) {
            return [$this->diagnostic(
                $path,
                $locator,
                "$label must already equal the exact native WordPress text-sanitized bytes"
            )];
        }
        return [];
    }

    /** @return list<array<string,mixed>> */
    private function download_diagnostics(string $path, mixed $downloads): array {
        if (!is_array($downloads)) {
            return [$this->diagnostic(
                $path,
                'meta._downloadable_files',
                'WooCommerce downloadable files must be an object keyed by download identity'
            )];
        }
        if ($downloads !== [] && array_is_list($downloads)) {
            return [$this->diagnostic(
                $path,
                'meta._downloadable_files',
                'WooCommerce downloadable files must be a named object, not a positional list'
            )];
        }

        $out = [];
        foreach ($downloads as $downloadKey => $row) {
            $locator = 'meta._downloadable_files.' . (is_string($downloadKey) ? $downloadKey : (string) $downloadKey);
            if (!is_string($downloadKey) || $downloadKey === '' || strlen($downloadKey) > 128) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce download identities must be non-empty strings of at most 128 bytes'
                );
                continue;
            }
            if (!is_array($row) || array_is_list($row)) {
                $out[] = $this->diagnostic($path, $locator, 'WooCommerce downloadable-file rows must be named objects');
                continue;
            }
            $unknown = array_values(array_diff(array_keys($row), self::DOWNLOAD_FIELDS));
            if ($unknown !== []) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce downloadable-file row has unsupported addon-owned field(s): '
                    . implode(', ', array_map('strval', $unknown))
                );
            }
            foreach (['name', 'file'] as $field) {
                if (!array_key_exists($field, $row) || !is_string($row[$field])) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.$field",
                        "WooCommerce downloadable-file $field must be a string"
                    );
                }
            }
            if (is_string($row['file'] ?? null)) {
                if ($row['file'] === '') {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.file",
                        'WooCommerce downloadable-file file must be non-empty'
                    );
                } elseif (str_starts_with($row['file'], '[') && str_ends_with($row['file'], ']')) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.file",
                        'WooCommerce shortcode download locators are extension-executed and outside this adapter contract'
                    );
                }
            }
            if (array_key_exists('id', $row)
                && (!is_string($row['id']) || $row['id'] !== $downloadKey)) {
                $out[] = $this->diagnostic(
                    $path,
                    "$locator.id",
                    'WooCommerce downloadable-file id must equal its object key'
                );
            }
            if (array_key_exists('enabled', $row)) {
                if (!is_bool($row['enabled'])) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.enabled",
                        'WooCommerce downloadable-file enabled must be boolean when present'
                    );
                } elseif ($row['enabled'] !== true) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.enabled",
                        'WooCommerce disabled download rows reflect site-local approval state and are outside the portable authored contract'
                    );
                }
            }
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function attribute_diagnostics(string $path, mixed $attributes): array {
        if (!is_array($attributes)) {
            return [$this->diagnostic(
                $path,
                'meta._product_attributes',
                'WooCommerce product attributes must be an object keyed by the native attribute name'
            )];
        }
        if ($attributes !== [] && array_is_list($attributes)) {
            return [$this->diagnostic(
                $path,
                'meta._product_attributes',
                'WooCommerce product attributes must be a named object, not a positional list'
            )];
        }

        $out = [];
        foreach ($attributes as $attributeKey => $row) {
            $locator = 'meta._product_attributes.' . (is_string($attributeKey) ? $attributeKey : (string) $attributeKey);
            if (!is_string($attributeKey) || $attributeKey === '') {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce product attribute keys must be non-empty strings'
                );
                continue;
            }
            if (!is_array($row) || array_is_list($row)) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce product attribute rows must be named objects'
                );
                continue;
            }
            $missing = array_values(array_diff(self::REQUIRED_FIELDS, array_keys($row)));
            if ($missing !== []) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce product attribute row is missing required field(s): ' . implode(', ', $missing)
                );
                continue;
            }
            $unknown = array_values(array_diff(array_keys($row), self::REQUIRED_FIELDS));
            if ($unknown !== []) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'WooCommerce product attribute row has unsupported addon-owned field(s): '
                    . implode(', ', array_map('strval', $unknown))
                );
            }
            if (!is_string($row['name']) || $row['name'] === '') {
                $out[] = $this->diagnostic($path, "$locator.name", 'WooCommerce product attribute name must be a non-empty string');
            }
            if (!is_string($row['value'])) {
                $out[] = $this->diagnostic($path, "$locator.value", 'WooCommerce product attribute value must be a string');
            }
            if (!is_int($row['position']) || $row['position'] < 0) {
                $out[] = $this->diagnostic($path, "$locator.position", 'WooCommerce product attribute position must be a non-negative integer');
            }
            foreach (['is_visible', 'is_variation', 'is_taxonomy'] as $flag) {
                if (!is_int($row[$flag]) || ($row[$flag] !== 0 && $row[$flag] !== 1)) {
                    $out[] = $this->diagnostic($path, "$locator.$flag", "WooCommerce product attribute $flag must be integer 0 or 1");
                }
            }
            if (($row['is_taxonomy'] ?? null) === 1) {
                if (($row['name'] ?? null) !== $attributeKey
                    || !$this->is_global_attribute_name($attributeKey)) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.name",
                        'WooCommerce global attribute name must equal its pa_* object key'
                    );
                }
                if (($row['value'] ?? null) !== '') {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.value",
                        'WooCommerce global attribute value must be empty; term relationships carry its options'
                    );
                }
            }
        }
        return $out;
    }

    /**
     * WordPress rejects taxonomy names over 32 bytes, while Woo prefixes the
     * stored attribute slug with `pa_`. Lowercase/caseless Unicode letters
     * and decimal digits are the multibyte counterpart of the prior ASCII
     * grammar; uppercase, symbols, number-letters, invalid UTF-8, and an empty
     * slug remain outside the reviewed boundary.
     */
    private function is_global_attribute_name(string $name): bool {
        return strlen($name) <= 32
            && preg_match('/^pa_[\p{Ll}\p{Lo}\p{Nd}][\p{Ll}\p{Lo}\p{Nd}_-]*$/uD', $name) === 1;
    }

    /** @return array{code:string,path:string,locator:string,message:string} */
    private function diagnostic(string $path, string $locator, string $message): array {
        return [
            'code' => 'adapter_schema_content_mismatch',
            'path' => $path,
            'locator' => $locator,
            'message' => $message,
        ];
    }
}
