#!/usr/bin/env bash
# WooCommerce render/API-level acceptance (task #92): byte-identical
# canonical state alone doesn't prove pa_size/pa_color's zero-provisioning
# claim — this check confirms conf2's own WooCommerce runtime (a FRESH
# process, exactly the acceptance criterion's own wording) resolves
# wc_get_attribute_taxonomies() and the variable product's variations
# correctly, using WHATEVER local ids conf2 assigned them (never conf1's),
# with zero manual pre-provisioning step anywhere in run.sh's own flow —
# the same live proof already run on the dedicated r3e pair
# (sandbox/tests/live/regress_pa_attributes.sh), now folded into the ordinary
# conformance sweep.
#
# Invoked by conformance/run.sh after a clean apply, from the sandbox/
# directory; $COMPOSE/fail/pass are exported by run.sh itself (matching
# every other checks/*.sh file's convention — see checks/ninja-forms.sh).
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8800}"
CONF2_PORT="${CONF2_PORT:-8801}"
WOOCOMMERCE_EXPECTED_VERSION="${WOOCOMMERCE_EXPECTED_VERSION:-11.0.1}"

observe_woocommerce() { # <conf1|conf2>
  local side="$1" repo service file out
  case "$side" in
    conf1) repo="${CONF_REPO1:-siterepo/conf1}"; service=cli1 ;;
    conf2) repo="${CONF_REPO2:-siterepo/conf2}"; service=cli2 ;;
    *) fail "invalid WooCommerce observation side: $side" ;;
  esac
  file="$repo/.tmp-woocommerce-observe.php"
  cat > "$file" <<'PHPEOF'
<?php
global $wpdb;

$simpleId = wc_get_product_id_by_sku('CONF-WIDGET-1');
$precisionId = wc_get_product_id_by_sku('CONF-PRECISION-UTF8');
$externalId = wc_get_product_id_by_sku('CONF-EXTERNAL-1');
$groupedId = wc_get_product_id_by_sku('CONF-GROUPED-KIT');
$smallId = wc_get_product_id_by_sku('CONF-VAR-S-RED');
$largeId = wc_get_product_id_by_sku('CONF-VAR-L-BLUE');
$variableId = (int) get_post_field('post_parent', $smallId);
$simple = wc_get_product($simpleId);
$precision = wc_get_product($precisionId);
$external = wc_get_product($externalId);
$grouped = wc_get_product($groupedId);
$variable = wc_get_product($variableId);
$small = wc_get_product($smallId);
$large = wc_get_product($largeId);
$coupon = new WC_Coupon('CONF-WELCOME10');
if (!$simple || !$precision || !($external instanceof WC_Product_External) || !$grouped || !$variable || !$small || !$large || !$coupon->get_id()) {
    throw new RuntimeException('WooCommerce native product/coupon fixture is incomplete');
}
$admin = get_user_by('login', 'admin');
wp_set_current_user($admin ? (int) $admin->ID : 0);
$externalRestResponse = rest_do_request(new WP_REST_Request('GET', '/wc/v3/products/' . $externalId));
if ($externalRestResponse->is_error()) {
    throw new RuntimeException('WooCommerce external-product REST read failed');
}
$externalRest = $externalRestResponse->get_data();
$category = get_term_by('slug', 'conformance-widgets', 'product_cat');
$categoryParent = get_term_by('slug', 'conformance-catalog', 'product_cat');
$brand = get_term_by('slug', 'atelier-tokyo', 'product_brand');
$brandParent = get_term_by('slug', 'conformance-makers', 'product_brand');
$brandExcluded = get_term_by('slug', 'excluded-merchant-brand', 'product_brand');
$red = get_term_by('slug', 'red', 'pa_conf-color');
$blue = get_term_by('slug', 'blue', 'pa_conf-color');
if (!$category || !$categoryParent || !$brand || !$brandParent || !$brandExcluded || !$red || !$blue) {
    throw new RuntimeException('WooCommerce category, brand, or visual term fixture is incomplete');
}
$restTerm = static function (string $route): array {
    $response = rest_do_request(new WP_REST_Request('GET', $route));
    if ($response->is_error() || $response->get_status() !== 200) {
        throw new RuntimeException('WooCommerce term REST read failed for ' . $route);
    }
    return $response->get_data();
};
$categoryRest = $restTerm('/wc/v3/products/categories/' . $category->term_id);
$brandRest = $restTerm('/wc/v3/products/brands/' . $brand->term_id);
\Automattic\WooCommerce\Internal\ProductAttributes\VisualAttributeTermMeta::prime_term_visual_caches([
    (int) $red->term_id,
    (int) $blue->term_id,
]);
$visuals = \Automattic\WooCommerce\Internal\ProductAttributes\VisualAttributeTermMeta::get_term_visuals([
    (int) $red->term_id,
    (int) $blue->term_id,
]);
$catChildren = get_option('product_cat_children', []);
$brandChildren = get_option('product_brand_children', []);
$childIds = static function ($state, int $parentId): array {
    $ids = is_array($state) ? (array) ($state[$parentId] ?? []) : [];
    $ids = array_map('intval', $ids);
    sort($ids, SORT_NUMERIC);
    return array_values($ids);
};
$rewriteRules = get_option('rewrite_rules', []);
$brandRuleCount = 0;
foreach (is_array($rewriteRules) ? array_keys($rewriteRules) : [] as $rule) {
    if (is_string($rule) && str_starts_with($rule, 'maker-houses/')) {
        $brandRuleCount++;
    }
}
$reviewPageId = (int) wc_get_page_id('review_order');
$reviewPage = $reviewPageId > 0 ? get_post($reviewPageId) : null;
$reviewPermalink = $reviewPage instanceof WP_Post ? get_permalink($reviewPageId) : false;
$reviewPath = is_string($reviewPermalink)
    ? trim((string) wp_make_link_relative($reviewPermalink), '/')
    : '';
$reviewRuleKey = '' !== $reviewPath
    ? '^' . preg_quote($reviewPath, '/') . '/([0-9]+)/?$'
    : '';
$reviewRuleExpected = $reviewPageId > 0
    ? 'index.php?page_id=' . $reviewPageId . '&review-order=$matches[1]'
    : '';
$reviewRuleActual = '' !== $reviewRuleKey && is_array($rewriteRules)
    ? (string) ($rewriteRules[$reviewRuleKey] ?? '')
    : '';
$reviewRuleCount = 0;
foreach (is_array($rewriteRules) ? $rewriteRules : [] as $query) {
    if (is_string($query) && str_contains($query, '&review-order=$matches[1]')) {
        $reviewRuleCount++;
    }
}

$localAttributes = [];
foreach ($precision->get_attributes() as $attribute) {
    if ($attribute instanceof WC_Product_Attribute && !$attribute->is_taxonomy()) {
        $localAttributes[] = [
            'name' => $attribute->get_name(),
            'options' => array_values($attribute->get_options()),
            'position' => $attribute->get_position(),
            'variation' => $attribute->get_variation(),
            'visible' => $attribute->get_visible(),
        ];
    }
}
$downloads = [];
foreach ($precision->get_downloads() as $id => $download) {
    $downloads[] = [
        'enabled' => $download->get_enabled(),
        'file' => $download->get_file(),
        'id' => $download->get_id(),
        'key' => (string) $id,
        'name' => $download->get_name(),
    ];
}
$termSlugs = static function (int $id, string $taxonomy): array {
    $terms = wp_get_object_terms($id, $taxonomy, ['fields' => 'slugs']);
    if (is_wp_error($terms)) {
        throw new RuntimeException($terms->get_error_message());
    }
    sort($terms, SORT_STRING);
    return array_values($terms);
};

$zoneId = 0;
$zoneMethods = [];
foreach (WC_Shipping_Zones::get_zones() as $zone) {
    if ($zone['zone_name'] !== 'Conformance United States') {
        continue;
    }
    $zoneId = (int) $zone['id'];
    foreach ($zone['shipping_methods'] as $method) {
        $zoneMethods[$method->id] = [
            'cost' => (string) $method->get_option('cost'),
            'id' => (int) $method->get_instance_id(),
            'min_amount' => (string) $method->get_option('min_amount'),
        ];
    }
}
ksort($zoneMethods, SORT_STRING);
$taxRate = $wpdb->get_row(
    "SELECT tax_rate_id,tax_rate,tax_rate_class FROM {$wpdb->prefix}woocommerce_tax_rates " .
    "WHERE tax_rate_name='Conformance CA Sales Tax'",
    ARRAY_A
);
$taxClass = $wpdb->get_row(
    "SELECT tax_rate_class_id,name,slug FROM {$wpdb->prefix}wc_tax_rate_classes " .
    "WHERE slug='conformance-reduced-rate'",
    ARRAY_A
);
$attributeRows = $wpdb->get_results(
    "SELECT attribute_id,attribute_label,attribute_name,attribute_orderby,attribute_public,attribute_type " .
    "FROM {$wpdb->prefix}woocommerce_attribute_taxonomies " .
    "WHERE attribute_name IN ('conf-color','conf-size') ORDER BY attribute_name",
    ARRAY_A
);
$lookup = $wpdb->get_row($wpdb->prepare(
    "SELECT product_id,sku,min_price,max_price FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id=%d",
    $precisionId
), ARRAY_A);
$tag = get_term_by('slug', 'portable-tokyo', 'product_tag');
$shipping = get_term_by('slug', 'oversize-portable', 'product_shipping_class');
$thumbnailId = $category ? (int) get_term_meta($category->term_id, 'thumbnail_id', true) : 0;
$sourceOrders = wc_get_orders(['billing_email' => 'source-runtime@example.test', 'limit' => -1, 'return' => 'ids']);
$targetOrders = wc_get_orders(['billing_email' => 'target-runtime@example.test', 'limit' => -1, 'return' => 'ids']);
ob_start();
woocommerce_demo_store();
$storeNoticeHtml = (string) ob_get_clean();

echo wp_json_encode([
    'attributes' => $attributeRows,
    'brands' => [
        'child' => [
            'description' => $brand->description,
            'display' => (string) get_term_meta($brand->term_id, 'display_type', true),
            'menu_order' => (string) get_term_meta($brand->term_id, 'order', true),
            'parent' => (int) $brand->parent,
            'permalink' => get_term_link($brand, 'product_brand'),
            'thumbnail' => (int) get_term_meta($brand->term_id, 'thumbnail_id', true),
        ],
        'children' => $childIds($brandChildren, (int) $brandParent->term_id),
        'rest' => [
            'display' => (string) ($brandRest['display'] ?? ''),
            'image' => (int) ($brandRest['image']['id'] ?? 0),
            'image_src' => (string) ($brandRest['image']['src'] ?? ''),
            'menu_order' => (int) ($brandRest['menu_order'] ?? -1),
            'parent' => (int) ($brandRest['parent'] ?? 0),
        ],
        'simple' => $termSlugs($simpleId, 'product_brand'),
    ],
    'category' => [
        'children' => $childIds($catChildren, (int) $categoryParent->term_id),
        'display' => (string) get_term_meta($category->term_id, 'display_type', true),
        'menu_order' => (string) get_term_meta($category->term_id, 'order', true),
        'parent' => (int) $category->parent,
        'permalink' => get_term_link($category, 'product_cat'),
        'rest' => [
            'display' => (string) ($categoryRest['display'] ?? ''),
            'image' => (int) ($categoryRest['image']['id'] ?? 0),
            'image_src' => (string) ($categoryRest['image']['src'] ?? ''),
            'menu_order' => (int) ($categoryRest['menu_order'] ?? -1),
            'parent' => (int) ($categoryRest['parent'] ?? 0),
        ],
    ],
    'coupon' => [
        'amount' => $coupon->get_amount('edit'),
        'brands' => array_map('intval', (array) get_post_meta($coupon->get_id(), 'product_brands', true)),
        'categories' => array_values($coupon->get_product_categories('edit')),
        'excluded_brands' => array_map('intval', (array) get_post_meta($coupon->get_id(), 'exclude_product_brands', true)),
        'id' => $coupon->get_id(),
        'products' => array_values($coupon->get_product_ids('edit')),
        'status' => $coupon->get_status('edit'),
        'type' => $coupon->get_discount_type('edit'),
    ],
    'derived' => [
        'brand_rule_count' => $brandRuleCount,
        'category_lookup' => $wpdb->get_results($wpdb->prepare(
            "SELECT category_tree_id,category_id FROM {$wpdb->prefix}wc_category_lookup " .
            'WHERE category_id IN (%d,%d) ORDER BY category_tree_id,category_id',
            (int) $categoryParent->term_id,
            (int) $category->term_id
        ), ARRAY_A),
        'precision_lookup' => $lookup,
    ],
    'external' => [
        'button_text' => $external->get_button_text('edit'),
        'price' => $external->get_price('edit'),
        'product_url' => $external->get_product_url('edit'),
        'rest' => [
            'button_text' => (string) ($externalRest['button_text'] ?? ''),
            'external_url' => (string) ($externalRest['external_url'] ?? ''),
            'type' => (string) ($externalRest['type'] ?? ''),
        ],
        'tags' => $termSlugs($externalId, 'product_tag'),
        'title' => $external->get_name('edit'),
        'type' => $external->get_type(),
    ],
    'grouped' => ['children' => array_values($grouped->get_children('edit'))],
    'ids' => [
        'attribute_color' => (int) ($attributeRows[0]['attribute_id'] ?? 0),
        'attribute_size' => (int) ($attributeRows[1]['attribute_id'] ?? 0),
        'brand_child' => (int) $brand->term_id,
        'brand_excluded' => (int) $brandExcluded->term_id,
        'brand_parent' => (int) $brandParent->term_id,
        'category' => $category ? (int) $category->term_id : 0,
        'category_parent' => (int) $categoryParent->term_id,
        'color_blue' => (int) $blue->term_id,
        'color_red' => (int) $red->term_id,
        'coupon' => $coupon->get_id(),
        'external' => $externalId,
        'flat_method' => (int) ($zoneMethods['flat_rate']['id'] ?? 0),
        'free_method' => (int) ($zoneMethods['free_shipping']['id'] ?? 0),
        'grouped' => $groupedId,
        'precision' => $precisionId,
        'product' => $simpleId,
        'review_page' => $reviewPageId,
        'shipping_class' => $shipping ? (int) $shipping->term_id : 0,
        'tag' => $tag ? (int) $tag->term_id : 0,
        'tax_class' => (int) ($taxClass['tax_rate_class_id'] ?? 0),
        'tax_rate' => (int) ($taxRate['tax_rate_id'] ?? 0),
        'thumbnail' => $thumbnailId,
        'variable' => $variableId,
        'variation_large' => $largeId,
        'variation_small' => $smallId,
        'zone' => $zoneId,
    ],
    'options' => [
        'brand_description' => (string) get_option('wc_brands_show_description', ''),
        'brand_permalink' => (string) get_option('woocommerce_brand_permalink', ''),
        'neighbor' => get_option('duo_target_environment_neighbor', null),
        'paypal' => get_option('woocommerce_paypal_settings', null),
        'precision' => (string) get_option('woocommerce_price_num_decimals', ''),
        'store_notice' => (string) get_option('woocommerce_demo_store_notice', ''),
        'store_notice_enabled' => (string) get_option('woocommerce_demo_store', ''),
        'store_notice_html' => $storeNoticeHtml,
        'thumbnail' => [
            'cropping' => (string) get_option('woocommerce_thumbnail_cropping', ''),
            'custom_height' => (string) get_option('woocommerce_thumbnail_cropping_custom_height', ''),
            'custom_width' => (string) get_option('woocommerce_thumbnail_cropping_custom_width', ''),
            'width' => (string) get_option('woocommerce_thumbnail_image_width', ''),
        ],
        'visual_attribute' => (string) get_option('woocommerce_feature_wc_visual_attribute_enabled', ''),
    ],
    'precision' => [
        'description_bytes' => strlen($precision->get_description('edit')),
        'downloads' => $downloads,
        'local_attributes' => $localAttributes,
        'price' => $precision->get_price('edit'),
        'purchase_note_bytes' => strlen($precision->get_purchase_note('edit')),
        'regular' => $precision->get_regular_price('edit'),
        'sale' => $precision->get_sale_price('edit'),
        'shipping_class' => $precision->get_shipping_class(),
        'tags' => $termSlugs($precisionId, 'product_tag'),
        'title' => $precision->get_name('edit'),
    ],
    'runtime' => [
        'hpos' => get_option('woocommerce_custom_orders_table_enabled') === 'yes',
        'source_orders' => count($sourceOrders),
        'source_queue' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook='duo_woo_source_runtime_probe'"),
        'source_sessions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key='duo-source-runtime-session'"),
        'target_orders' => count($targetOrders),
        'target_queue' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook='duo_woo_target_runtime_probe'"),
        'target_sessions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key='duo-target-runtime-session'"),
        'thumbnail_hash' => (string) get_option('woocommerce_maybe_regenerate_images_hash', ''),
    ],
    'review_order' => [
        'content_has_shortcode' => $reviewPage instanceof WP_Post
            && false !== strpos((string) $reviewPage->post_content, '[woocommerce_review_order]'),
        'feature' => (string) get_option('woocommerce_feature_customer_review_request_enabled', ''),
        'id' => $reviewPageId,
        'marker' => get_option('woocommerce_review_order_flush_rewrite_pending', null),
        'path' => $reviewPath,
        'rule_actual' => $reviewRuleActual,
        'rule_count' => $reviewRuleCount,
        'rule_expected' => $reviewRuleExpected,
        'slug' => $reviewPage instanceof WP_Post ? $reviewPage->post_name : '',
        'status' => $reviewPage instanceof WP_Post ? $reviewPage->post_status : '',
        'title' => $reviewPage instanceof WP_Post ? $reviewPage->post_title : '',
        'type' => $reviewPage instanceof WP_Post ? $reviewPage->post_type : '',
    ],
    'shipping' => ['methods' => $zoneMethods, 'tax_class' => $taxClass, 'tax_rate' => $taxRate],
    'simple' => [
        'cross_sells' => array_values($simple->get_cross_sell_ids('edit')),
        'image' => wp_get_attachment_image_src($simple->get_image_id(), 'woocommerce_thumbnail'),
        'shipping_class' => $simple->get_shipping_class(),
        'tags' => $termSlugs($simpleId, 'product_tag'),
        'title' => $simple->get_name('edit'),
        'upsells' => array_values($simple->get_upsell_ids('edit')),
    ],
    'version' => defined('WC_VERSION') ? WC_VERSION : null,
    'visuals' => [
        'blue' => [
            'color' => (string) get_term_meta($blue->term_id, 'color', true),
            'image' => (int) get_term_meta($blue->term_id, 'image', true),
            'order' => (string) get_term_meta($blue->term_id, 'order', true),
            'semantic' => $visuals[(int) $blue->term_id] ?? null,
        ],
        'red' => [
            'color' => (string) get_term_meta($red->term_id, 'color', true),
            'image' => (int) get_term_meta($red->term_id, 'image', true),
            'order' => (string) get_term_meta($red->term_id, 'order', true),
            'semantic' => $visuals[(int) $red->term_id] ?? null,
        ],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF
  out=$($COMPOSE run --rm -T "$service" wp eval-file /siterepo/.tmp-woocommerce-observe.php)
  rm -f "$file"
  require_observed_nonempty "$side WooCommerce native observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

API_OUT=$($COMPOSE run --rm -T cli2 wp eval '
$taxes = wc_get_attribute_taxonomies();
$names = array_map(fn($t) => $t->attribute_name, $taxes);
sort($names);
echo implode(",", $names) . "|";

$products = get_posts(["post_type" => "product", "posts_per_page" => -1]);
$variable_count = 0;
$variation_total = 0;
$purchasable_variation = false;
foreach ($products as $p) {
  $prod = wc_get_product($p->ID);
  if (!$prod || $prod->get_type() !== "variable") continue;
  $variable_count++;
  $vars = $prod->get_available_variations();
  $variation_total += count($vars);
  foreach ($prod->get_children() as $child_id) {
    $variation = wc_get_product($child_id);
    if ($variation && $variation->is_purchasable()) { $purchasable_variation = true; }
  }
}
echo "$variable_count|$variation_total|" . ($purchasable_variation ? "yes" : "no");
' 2>&1 | tail -1)
require_observed_nonempty "conf2 WooCommerce attribute/variation observation" "$API_OUT"
echo "conf2 attribute/variation check: $API_OUT"

grep -qE 'conf-color,conf-size' <<<"$API_OUT" \
  || fail "conf2's wc_get_attribute_taxonomies() does not list both conf-size and conf-color (got: $API_OUT) -- taxonomy_patterns zero-provisioning did not hold"

IFS='|' read -r _ VAR_COUNT VARIATION_TOTAL PURCHASABLE <<< "$API_OUT"
[ "$VAR_COUNT" -ge 1 ] || fail "conf2 has zero variable products resolving via wc_get_product() (got: $API_OUT)"
[ "$VARIATION_TOTAL" -ge 2 ] || fail "conf2's variable product resolved fewer than 2 available variations (got: $API_OUT)"
[ "$PURCHASABLE" = "yes" ] || fail "conf2 has no purchasable variation at all (got: $API_OUT) -- _manage_stock/_stock_status/_regular_price did not round-trip correctly per variation"

pass "conf2 resolves both attribute taxonomies + the variable product's variations via WooCommerce's own APIs, zero manual pre-provisioning, at least one variation purchasable"

TERM_META_OUT=$($COMPOSE run --rm -T cli2 wp eval '
$term = get_term_by("slug", "conformance-widgets", "product_cat");
$id = $term ? (int) get_term_meta($term->term_id, "thumbnail_id", true) : 0;
$attachment = $id ? get_post($id) : null;
$file = $id ? get_attached_file($id) : "";
echo ($term ? "term" : "missing-term") . "|$id|" . ($attachment ? $attachment->post_type : "missing") . "|" . (($file && is_file($file)) ? "file" : "missing-file");
' 2>&1 | tail -1)
require_observed_nonempty "conf2 WooCommerce product-category thumbnail observation" "$TERM_META_OUT"
echo "conf2 product-category thumbnail check: $TERM_META_OUT"
grep -qE '^term\|[1-9][0-9]*\|attachment\|file$' <<<"$TERM_META_OUT" \
  || fail "conf2 product_cat thumbnail_id did not resolve to a local attachment with a real media file (got: $TERM_META_OUT)"
pass "conf2 product_cat thumbnail_id resolves through termmeta to its own local attachment and media file"

THUMBNAIL_LAZY_RC=0
THUMBNAIL_LAZY_RAW=$($COMPOSE run --rm -T cli2 wp eval '
require_once ABSPATH . "wp-admin/includes/image.php";

$has_callback = static function (string $hook, string $class, string $method, int $priority, int $accepted_args): bool {
    global $wp_filter;
    $callbacks = $wp_filter[$hook]->callbacks[$priority] ?? [];
    foreach ($callbacks as $entry) {
        $callback = $entry["function"] ?? null;
        if (is_array($callback) && $callback[0] === $class && $callback[1] === $method
            && (int) ($entry["accepted_args"] ?? -1) === $accepted_args) {
            return true;
        }
    }
    return false;
};
$set_thumbnail_options = static function (string $mode, int $width, int $ratio_width, int $ratio_height): void {
    update_option("woocommerce_thumbnail_cropping", $mode);
    update_option("woocommerce_thumbnail_cropping_custom_width", (string) $ratio_width);
    update_option("woocommerce_thumbnail_cropping_custom_height", (string) $ratio_height);
    update_option("woocommerce_thumbnail_image_width", (string) $width);
    wp_cache_delete("size-thumbnail", "woocommerce");
    wp_cache_delete("size-woocommerce_thumbnail", "woocommerce");
    WC()->add_image_sizes();
    $target = wc_get_image_size("thumbnail");
    $registered = wp_get_registered_image_subsizes()["woocommerce_thumbnail"] ?? [];
    $expected_height = $mode === "uncropped"
        ? 0
        : (int) round(($width / max(1, $mode === "custom" ? $ratio_width : 1))
            * max(1, $mode === "custom" ? $ratio_height : 1));
    if ((int) ($target["width"] ?? 0) !== $width
        || (int) ($target["height"] ?? 0) !== $expected_height
        || (int) ($registered["width"] ?? 0) !== $width
        || (int) ($registered["height"] ?? 0) !== $expected_height) {
        throw new RuntimeException("Woo thumbnail options and registered image size did not converge");
    }
};
$create_image = static function (int $width, int $height, string $label): int {
    $uploads = wp_upload_dir();
    $name = "duo-woo-lazy-" . $label . "-" . wp_generate_uuid4() . ".png";
    $file = trailingslashit($uploads["path"]) . $name;
    wp_mkdir_p(dirname($file));
    $image = imagecreatetruecolor($width, $height);
    imagefilledrectangle($image, 0, 0, $width - 1, $height - 1, imagecolorallocate($image, 40, 120, 180));
    imagepng($image, $file);
    imagedestroy($image);
    $id = wp_insert_attachment([
        "post_mime_type" => "image/png",
        "post_title" => "Duo Woo lazy image " . $label,
        "post_status" => "inherit",
    ], $file);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $metadata = wp_generate_attachment_metadata((int) $id, $file);
    if (!is_array($metadata) || !$metadata) {
        throw new RuntimeException("failed to seed native attachment metadata");
    }
    // The native update returns false both on storage failure and when the
    // value is already exact (the 240x180 no-derivative case reaches that
    // branch). Durable readback, not the ambiguous write boolean, is evidence.
    wp_update_attachment_metadata((int) $id, $metadata);
    $stored_metadata = wp_get_attachment_metadata((int) $id);
    if (!is_array($stored_metadata) || $stored_metadata !== $metadata) {
        throw new RuntimeException("native attachment metadata did not match exact readback");
    }
    return (int) $id;
};
$metadata_hash = static function (int $id): string {
    return hash("sha256", wp_json_encode(wp_get_attachment_metadata($id), JSON_UNESCAPED_SLASHES));
};
$dims = static function ($image): array {
    return is_array($image) ? [(int) $image[1], (int) $image[2]] : [0, 0];
};

$callbacks = [
    "intermediate" => $has_callback("image_get_intermediate_size", "WC_Regenerate_Images", "filter_image_get_intermediate_size", 10, 3),
    "metadata" => $has_callback("wp_generate_attachment_metadata", "WC_Regenerate_Images", "add_uncropped_metadata", 10, 1),
    "source" => $has_callback("wp_get_attachment_image_src", "WC_Regenerate_Images", "maybe_resize_image", 10, 4),
    "product_meta" => $has_callback("update_post_metadata", "WC_Post_Data", "update_post_metadata", 10, 5),
];
$product = wc_get_product(wc_get_product_id_by_sku("CONF-WIDGET-1"));
if (!$product || !$product->get_image_id()) {
    throw new RuntimeException("missing product image for lazy-regeneration product path");
}
$product_image_id = (int) $product->get_image_id();
$runtime_hash = (string) get_option("woocommerce_maybe_regenerate_images_hash", "");
// pair_bootstrap.sh:136 activates Twenty Twenty-One, whose exact Woo 11.0.x
// support fixes thumbnails at 450px. Remove that process-local precedence so
// this one probe can exercise the authored 300 -> 500 option lifecycle; every
// transition replays Woo init registration because metadata generation reads
// WordPress registered sizes, not newly-written options.
$theme_support = get_theme_support("woocommerce");
$theme_size = wc_get_image_size("thumbnail");
if ((int) ($theme_size["width"] ?? 0) !== 450 || false === $theme_support) {
    throw new RuntimeException("Twenty Twenty-One did not expose its exact 450px Woo thumbnail override");
}
if (!remove_theme_support("woocommerce")) {
    throw new RuntimeException("could not enter the process-local option-controlled Woo thumbnail scenario");
}
$temporary_ids = [];
try {
    $set_thumbnail_options("1:1", 300, 1, 1);
    $product_file = get_attached_file($product_image_id);
    $product_metadata = wp_generate_attachment_metadata($product_image_id, $product_file);
    if (!is_array($product_metadata)) {
        throw new RuntimeException("failed to manufacture stale 300px product metadata");
    }
    wp_update_attachment_metadata($product_image_id, $product_metadata);
    $stored_product_metadata = wp_get_attachment_metadata($product_image_id);
    if ((int) ($stored_product_metadata["sizes"]["woocommerce_thumbnail"]["width"] ?? 0) !== 300) {
        throw new RuntimeException("native stale product metadata did not retain the 300px preimage");
    }
    $same_before = $metadata_hash($product_image_id);
    $set_thumbnail_options("custom", 500, 1, 1);
    $same_aspect = wp_get_attachment_image_src($product_image_id, "woocommerce_thumbnail");
    $same_after = $metadata_hash($product_image_id);

    $set_thumbnail_options("1:1", 300, 1, 1);
    $failure_id = $create_image(800, 600, "failure");
    $temporary_ids[] = $failure_id;
    $set_thumbnail_options("custom", 500, 1, 1);
    $failure_before = $metadata_hash($failure_id);
    // An empty supported editor inventory makes WordPress return its native
    // image_no_editor WP_Error. A fictitious class is not equivalent: WP 7.1
    // calls its static test() and raises TypeError before Woo can fall back.
    $missing_editor = static fn(array $editors): array => [];
    add_filter("wp_image_editors", $missing_editor, PHP_INT_MAX);
    try {
        $failed = wp_get_attachment_image_src($failure_id, "woocommerce_thumbnail");
    } finally {
        remove_filter("wp_image_editors", $missing_editor, PHP_INT_MAX);
    }
    $failure_after = $metadata_hash($failure_id);
    $failure_metadata = wp_get_attachment_metadata($failure_id);
    $retry = wp_get_attachment_image_src($failure_id, "woocommerce_thumbnail");
    $retry_hash = $metadata_hash($failure_id);
    $retry_metadata = wp_get_attachment_metadata($failure_id);
    $third = wp_get_attachment_image_src($failure_id, "woocommerce_thumbnail");
    $third_hash = $metadata_hash($failure_id);

    $set_thumbnail_options("1:1", 300, 1, 1);
    $small_id = $create_image(240, 180, "small");
    $temporary_ids[] = $small_id;
    $set_thumbnail_options("custom", 500, 1, 1);
    $small_before = $metadata_hash($small_id);
    $small = wp_get_attachment_image_src($small_id, "woocommerce_thumbnail");
    $small_after = $metadata_hash($small_id);

    $set_thumbnail_options("uncropped", 500, 1, 1);
    $uncropped = wp_get_attachment_image_src($failure_id, "woocommerce_thumbnail");
    $set_thumbnail_options("custom", 500, 4, 3);
    $custom = wp_get_attachment_image_src($failure_id, "woocommerce_thumbnail");
    $set_thumbnail_options("custom", 500, 1, 1);

    echo wp_json_encode([
        "callbacks" => $callbacks,
        "theme_override_width" => (int) ($theme_size["width"] ?? 0),
        "same_aspect" => [
            "dims" => $dims($same_aspect),
            "metadata_unchanged" => hash_equals($same_before, $same_after),
        ],
        "failure_retry" => [
            "failed_dims" => $dims($failed),
            "failed_metadata_changed" => !hash_equals($failure_before, $failure_after),
            "failed_full_dims" => [
                (int) ($failure_metadata["width"] ?? 0),
                (int) ($failure_metadata["height"] ?? 0),
            ],
            "failed_filesize_positive" => (int) ($failure_metadata["filesize"] ?? 0) > 0,
            "failed_size_names" => array_values(array_keys((array) ($failure_metadata["sizes"] ?? []))),
            "retry_dims" => $dims($retry),
            "stored_dims" => [
                (int) ($retry_metadata["sizes"]["woocommerce_thumbnail"]["width"] ?? 0),
                (int) ($retry_metadata["sizes"]["woocommerce_thumbnail"]["height"] ?? 0),
            ],
            "third_dims" => $dims($third),
            "third_metadata_unchanged" => hash_equals($retry_hash, $third_hash),
        ],
        "smaller" => [
            "dims" => $dims($small),
            "metadata_unchanged" => hash_equals($small_before, $small_after),
        ],
        "transitions" => [
            "uncropped" => $dims($uncropped),
            "custom_4_3" => $dims($custom),
        ],
        "runtime_hash_preserved" => get_option("woocommerce_maybe_regenerate_images_hash", "") === $runtime_hash,
        "final_options" => [
            (string) get_option("woocommerce_thumbnail_cropping", ""),
            (string) get_option("woocommerce_thumbnail_cropping_custom_width", ""),
            (string) get_option("woocommerce_thumbnail_cropping_custom_height", ""),
            (string) get_option("woocommerce_thumbnail_image_width", ""),
        ],
    ], JSON_UNESCAPED_SLASHES);
} finally {
    $set_thumbnail_options("custom", 500, 1, 1);
    foreach ($temporary_ids as $temporary_id) {
        wp_delete_attachment($temporary_id, true);
    }
    if (is_array($theme_support)) {
        add_theme_support("woocommerce", ...$theme_support);
    } elseif (true === $theme_support) {
        add_theme_support("woocommerce");
    }
}
' 2>&1) || THUMBNAIL_LAZY_RC=$?
[ "$THUMBNAIL_LAZY_RC" -eq 0 ] \
  || fail "Woo request-time thumbnail convergence WP-CLI probe failed (exit $THUMBNAIL_LAZY_RC): $THUMBNAIL_LAZY_RAW"
THUMBNAIL_LAZY_OUT=$(awk 'NF { line=$0 } END { print line }' <<<"$THUMBNAIL_LAZY_RAW")
require_observed_nonempty "conf2 WooCommerce thumbnail lazy-convergence observation" "$THUMBNAIL_LAZY_OUT"
echo "conf2 thumbnail lazy-convergence check: $THUMBNAIL_LAZY_OUT"
jq -e '
  .callbacks == {"intermediate":true,"metadata":true,"source":true,"product_meta":true} and
  .theme_override_width == 450 and
  .same_aspect == {"dims":[500,500],"metadata_unchanged":true} and
  .failure_retry.failed_dims == [500,375] and
  .failure_retry.failed_metadata_changed == true and
  .failure_retry.failed_full_dims == [800,600] and
  .failure_retry.failed_filesize_positive == true and
  .failure_retry.failed_size_names == [] and
  .failure_retry.retry_dims == [500,500] and
  .failure_retry.stored_dims == [500,500] and
  .failure_retry.third_dims == [500,500] and
  .failure_retry.third_metadata_unchanged == true and
  .smaller == {"dims":[240,180],"metadata_unchanged":true} and
  .transitions == {"uncropped":[500,375],"custom_4_3":[500,375]} and
  .runtime_hash_preserved == true and
  .final_options == ["custom","1","1","500"]
' <<<"$THUMBNAIL_LAZY_OUT" >/dev/null \
  || fail "Woo request-time thumbnail convergence failed: $THUMBNAIL_LAZY_OUT"
pass "real wp_get_attachment_image_src converges same-aspect, failure/retry, smaller-original, uncropped, and custom product paths without a background queue"

SHIPPING_OUT=$($COMPOSE run --rm -T cli2 wp eval '
$parts = [];
foreach (WC_Shipping_Zones::get_zones() as $zone) {
  if ($zone["zone_name"] !== "Conformance United States") continue;
  foreach ($zone["shipping_methods"] as $method) {
    if ($method->id === "flat_rate") $parts[] = "flat=" . $method->get_option("cost");
    if ($method->id === "free_shipping") $parts[] = "free=" . $method->get_option("min_amount");
  }
}
$rates = WC_Tax::find_rates(["country" => "US", "state" => "CA", "tax_class" => "conformance-reduced-rate"]);
foreach ($rates as $rate) $parts[] = "tax=" . $rate["rate"];
$class = WC_Tax::get_rates_for_tax_class("conformance-reduced-rate");
if ($class) $parts[] = "taxclass=conformance-reduced-rate";
sort($parts);
echo implode("|", $parts);
' 2>&1 | tail -1)
require_observed_nonempty "conf2 WooCommerce shipping/tax observation" "$SHIPPING_OUT"
echo "conf2 shipping/tax check: $SHIPPING_OUT"
grep -qE '(^|\|)flat=5\.99(\||$)' <<<"$SHIPPING_OUT" \
  || fail "conf2 did not resolve the rematerialized flat-rate instance settings (got: $SHIPPING_OUT)"
grep -qE '(^|\|)free=50(\.00)?(\||$)' <<<"$SHIPPING_OUT" \
  || fail "conf2 did not resolve the rematerialized free-shipping settings (got: $SHIPPING_OUT)"
grep -qE '(^|\|)tax=7\.25(00)?(\||$)' <<<"$SHIPPING_OUT" \
  || fail "conf2 did not resolve the captured CA tax rate (got: $SHIPPING_OUT)"
grep -qE '(^|\|)taxclass=conformance-reduced-rate(\||$)' <<<"$SHIPPING_OUT" \
  || fail "conf2 did not resolve the authored custom tax class (got: $SHIPPING_OUT)"
pass "conf2 resolves shipping-zone methods, rematerialized instance-option names, custom tax class, and tax rate through WooCommerce APIs"

MERCHANT_SETTINGS_OUT=$($COMPOSE run --rm -T cli2 wp eval '
echo wp_json_encode([
  "calc_taxes" => (string) get_option("woocommerce_calc_taxes", ""),
]);
' 2>&1 | tail -1)
require_observed_nonempty "conf2 WooCommerce merchant-settings observation" "$MERCHANT_SETTINGS_OUT"
echo "conf2 merchant-settings check: $MERCHANT_SETTINGS_OUT"
echo "$MERCHANT_SETTINGS_OUT" | jq -e '.calc_taxes == "yes"' >/dev/null \
  || fail "conf2 merchant Woo tax setting did not round-trip (got: $MERCHANT_SETTINGS_OUT)"
pass "conf2 preserves the portable authored tax-enablement setting"

LOCAL_PICKUP_OUT=$($COMPOSE run --rm -T cli2 wp eval '
$admin = get_user_by("login", "admin");
if (!$admin) throw new RuntimeException("missing admin for Woo Settings REST readback");
wp_set_current_user((int) $admin->ID);
rest_get_server();
$response = rest_do_request(new WP_REST_Request("GET", "/wp/v2/settings"));
if ($response->is_error() || $response->get_status() !== 200) {
  throw new RuntimeException("Woo Settings REST readback failed");
}
$rest = $response->get_data();
$raw = get_option("woocommerce_pickup_location_settings");
$locations = get_option("pickup_location_pickup_locations");
$edit = \Automattic\WooCommerce\StoreApi\Utilities\LocalPickupUtils::get_local_pickup_settings("edit");
$view = \Automattic\WooCommerce\StoreApi\Utilities\LocalPickupUtils::get_local_pickup_settings();
$method = new \Automattic\WooCommerce\Blocks\Shipping\PickupLocation();
$rates = $method->get_rates_for_package([]);
$rate = reset($rates);
$rateMeta = $rate instanceof WC_Shipping_Rate ? $rate->get_meta_data() : [];
echo wp_json_encode([
  "raw" => $raw,
  "locations" => $locations,
  "rest_settings" => $rest["pickup_location_settings"] ?? null,
  "rest_locations" => $rest["pickup_locations"] ?? null,
  "edit" => $edit,
  "view" => $view,
  "rate" => $rate instanceof WC_Shipping_Rate ? [
    "cost" => (string) $rate->get_cost(),
    "label" => $rate->get_label(),
    "tax_status" => $rate->get_tax_status(),
    "location" => $rateMeta["pickup_location"] ?? null,
    "details" => $rateMeta["pickup_details"] ?? null,
  ] : null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
' 2>&1 | tail -1)
require_observed_nonempty "conf2 WooCommerce local-pickup REST/native observation" "$LOCAL_PICKUP_OUT"
echo "conf2 local-pickup REST/native check: $LOCAL_PICKUP_OUT"
jq -e '
  .raw == {"enabled":"yes","title":"استلام 東京","cost":"-12.50"} and
  .rest_settings == .raw and .edit == .raw and
  .view == {"enabled":true,"title":"استلام 東京","cost":"-12.50"} and
  (.locations | length) == 1 and .rest_locations == .locations and
  .locations[0].name == "<strong>مخزن</strong> 東京" and
  .locations[0].details == "<em>بوابة ٢</em><br>南口" and
  .rate.cost == "-12.50" and .rate.tax_status == "taxable" and
  .rate.label == "استلام 東京 (مخزن 東京)" and
  .rate.location == "مخزن 東京" and
  .rate.details == "بوابة ٢南口"
' <<<"$LOCAL_PICKUP_OUT" >/dev/null \
  || fail "conf2 local-pickup partial settings, REST records, stored HTML, defaults, or sanitized calculated rate diverged (got: $LOCAL_PICKUP_OUT)"
pass "local-pickup settings and stored HTML round-trip through the native Settings REST route while rate calculation strips display markup"

ORDER_OUT=$($COMPOSE run --rm -T cli2 wp eval '
$source = wc_get_orders(["billing_email" => "source-runtime@example.test", "limit" => -1, "return" => "ids"]);
$target = wc_get_orders(["billing_email" => "target-runtime@example.test", "limit" => -1, "return" => "ids"]);
echo "source=" . count($source) . "|target=" . count($target) . "|hpos=" . (get_option("woocommerce_custom_orders_table_enabled") === "yes" ? "yes" : "no");
' 2>&1 | tail -1)
require_observed_nonempty "conf2 WooCommerce HPOS observation" "$ORDER_OUT"
echo "conf2 HPOS runtime check: $ORDER_OUT"
[ "$ORDER_OUT" = 'source=0|target=1|hpos=yes' ] \
  || fail "HPOS runtime sovereignty failed: source order propagated, target order disappeared, or HPOS is disabled (got: $ORDER_OUT)"
pass "source-only HPOS order stayed source-only and target-only HPOS order survived apply"

# Drive the exact public helper and routed frontend without ever printing its
# bearer order key. The route must resolve through the migrated target-local
# page id and the fresh rewrite projection, not the hostile pre-apply page.
REVIEW_URL=$($COMPOSE run --rm -T cli2 wp eval '
$ids = wc_get_orders(["billing_email" => "target-runtime@example.test", "limit" => 2, "return" => "ids"]);
if (1 !== count($ids)) {
    throw new RuntimeException("target Review Order fixture is not unique");
}
$order = wc_get_order((int) $ids[0]);
if (!$order instanceof WC_Order) {
    throw new RuntimeException("target Review Order fixture did not resolve through Woo CRUD");
}
$order->set_status("completed");
$order->save();
$url = wc_get_review_order_url($order);
if (!is_string($url) || "" === $url) {
    throw new RuntimeException("WooCommerce did not generate its native Review Order URL");
}
echo $url;
')
[ -n "$REVIEW_URL" ] || fail "WooCommerce returned an empty native Review Order URL"
case "$REVIEW_URL" in
  "http://localhost:${CONF2_PORT}/review-order-source/"*"/?key="*) ;;
  *) fail "WooCommerce native Review Order URL did not use the migrated target route" ;;
esac
REVIEW_BODY=$(mktemp "${TMPDIR:-/tmp}/duo-woocommerce-review.XXXXXX")
if ! REVIEW_STATUS=$(curl -sS -o "$REVIEW_BODY" -w '%{http_code}' "$REVIEW_URL"); then
  rm -f "$REVIEW_BODY"
  fail "WooCommerce Review Order frontend request failed"
fi
if [ "$REVIEW_STATUS" != 200 ] || ! grep -Fq 'استعراض الطلب 東京' "$REVIEW_BODY"; then
  rm -f "$REVIEW_BODY"
  fail "WooCommerce Review Order frontend did not render the migrated page with HTTP 200"
fi
rm -f "$REVIEW_BODY"
pass "native Review Order URL resolves the migrated page and fresh rewrite rule without exposing the bearer key"

RUNTIME_OUT=$($COMPOSE run --rm -T cli2 wp eval '
global $wpdb;
$source_review = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_author_email=\"source-review@example.test\"");
$source_session = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key=\"duo-source-runtime-session\"");
$target_session = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key=\"duo-target-runtime-session\"");
$source_queue = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook=\"duo_woo_source_runtime_probe\"");
$target_queue = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook=\"duo_woo_target_runtime_probe\"");
echo "review=$source_review|source_session=$source_session|target_session=$target_session|source_queue=$source_queue|target_queue=$target_queue";
' 2>&1 | tail -1)
require_observed_nonempty "conf2 WooCommerce runtime-surfaces observation" "$RUNTIME_OUT"
echo "conf2 Woo runtime surfaces: $RUNTIME_OUT"
[ "$RUNTIME_OUT" = 'review=0|source_session=0|target_session=1|source_queue=0|target_queue=1' ] \
  || fail "Woo runtime sovereignty failed for reviews, sessions, or queues (got: $RUNTIME_OUT)"
pass "source reviews/sessions/queues stayed source-only and target runtime session/queue survived apply"

PROJECTION_OUT=$($COMPOSE run --rm -T cli2 wp eval '
global $wpdb;
$simple = wc_get_product_id_by_sku("CONF-WIDGET-1");
$small = wc_get_product_id_by_sku("CONF-VAR-S-RED");
$large = wc_get_product_id_by_sku("CONF-VAR-L-BLUE");
$variable = (int) get_post_field("post_parent", $small);
$parent_prices = $wpdb->get_col($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=\"_price\" ORDER BY meta_value+0", $variable));
$lookup = $wpdb->get_row($wpdb->prepare("SELECT min_price,max_price FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id=%d", $variable), ARRAY_A);
$sale = as_next_scheduled_action("wc_product_end_scheduled_sale", ["product_id" => $simple], "woocommerce-sales");
$empty_stock_cart = new WC_Cart();
$blocked_without_target_stock = !$empty_stock_cart->add_to_cart($simple, 1);
// Stock is runtime by contract, so establish target-local inventory without
// Woo product setters. Those setters also advance the observable (but
// manifest-declared derived) product/variation modified timestamps. Raw
// capture keeps those values honestly even though Canon::post_hash_basis()
// excludes them from branch-state comparison (DUO-3302); this projection
// check is about target-local stock, so do not introduce unrelated observed
// timestamp churn here. Keep the derived lookup in sync just as a stock write
// does, while leaving the post rows untouched.
foreach ([$simple, $small] as $stocked_id) {
  update_post_meta($stocked_id, "_stock", "5");
  update_post_meta($stocked_id, "_stock_status", "instock");
  $wpdb->update(
    $wpdb->prefix . "wc_product_meta_lookup",
    ["stock_quantity" => 5, "stock_status" => "instock"],
    ["product_id" => $stocked_id],
    ["%f", "%s"],
    ["%d"]
  );
  clean_post_cache($stocked_id);
  wc_delete_product_transients($stocked_id);
}
update_post_meta($variable, "_stock_status", "instock");
$wpdb->update(
  $wpdb->prefix . "wc_product_meta_lookup",
  ["stock_status" => "instock"],
  ["product_id" => $variable],
  ["%s"],
  ["%d"]
);
clean_post_cache($variable);
wc_delete_product_transients($variable);
$cart = new WC_Cart();
$simple_added = (bool) $cart->add_to_cart($simple, 1);
$variation = wc_get_product($small);
$variation_added = (bool) $cart->add_to_cart($variable, 1, $small, $variation ? $variation->get_variation_attributes() : []);
echo wp_json_encode([
  "simple_price" => wc_get_product($simple)->get_price(),
  "small_price" => wc_get_product($small)->get_price(),
  "large_price" => wc_get_product($large)->get_price(),
  "parent_prices" => $parent_prices,
  "lookup" => $lookup,
  "sale_scheduled" => $sale !== false,
  "cart_blocked_without_target_stock" => $blocked_without_target_stock,
  "cart_after_target_stock" => $simple_added && $variation_added && $cart->get_cart_contents_count() === 2,
]);
' 2>&1 | tail -1)
require_observed_nonempty "conf2 WooCommerce projection observation" "$PROJECTION_OUT"
echo "conf2 Woo projection/cart check: $PROJECTION_OUT"
jq -e '
  .simple_price == "14.99" and .small_price == "9.99" and .large_price == "12.99" and
  .parent_prices == ["9.99", "12.99"] and
  (.lookup.min_price | tonumber) == 9.99 and (.lookup.max_price | tonumber) == 12.99 and
  .sale_scheduled == true and
  .cart_blocked_without_target_stock == true and .cart_after_target_stock == true
' <<<"$PROJECTION_OUT" >/dev/null \
  || fail "price/product-meta/scheduling/cart projection is stale (got: $PROJECTION_OUT)"
pass "price/product-meta/schedule projections are current; cart refuses absent source inventory then accepts target-local simple+variation stock"

FILTER_OUT=$(curl -fsSG "http://localhost:${CONF2_PORT}/wp-json/wc/store/v1/products" \
  --data-urlencode 'attributes[0][attribute]=pa_conf-size' \
  --data-urlencode 'attributes[0][slug]=small') \
  || fail "conf2 Store API attribute-filter request failed"
require_observed_nonempty "conf2 WooCommerce Store API filter response" "$FILTER_OUT"
jq -e 'length >= 1 and any(.[]; .name == "Conformance Variable Widget" and .is_purchasable == true)' <<<"$FILTER_OUT" >/dev/null \
  || fail "Store API filtering/catalog visibility did not return the purchasable variable product"
pass "Store API attribute filtering returns the visible, purchasable variable catalog product"

PRODUCT_IMAGE_API=$(curl -fsSG "http://localhost:${CONF2_PORT}/wp-json/wc/store/v1/products" \
  --data-urlencode 'sku=CONF-WIDGET-1') \
  || fail "conf2 Store API product-image request failed"
require_observed_nonempty "conf2 WooCommerce Store API product-image response" "$PRODUCT_IMAGE_API"
jq -e --arg target "http://localhost:${CONF2_PORT}" --arg source "http://localhost:${CONF1_PORT}" '
  length == 1 and
  (.[0].images | length) >= 1 and
  (.[0].images[0].src | startswith($target + "/wp-content/uploads/")) and
  (.[0].images[0].thumbnail | startswith($target + "/wp-content/uploads/")) and
  (.[0].images[0].src | contains($source) | not) and
  (.[0].images[0].thumbnail | contains($source) | not)
' <<<"$PRODUCT_IMAGE_API" >/dev/null \
  || fail "Store API image schema did not resolve the target-local converged product image: $PRODUCT_IMAGE_API"
pass "Store API image schema reads the target-local product thumbnail through Woo's real image callback"

FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/product/conformance-widget/") \
  || fail "conf2 Conformance Widget page did not return 200"
require_observed_nonempty "conf2 WooCommerce rendered product response" "$FRONT"
[ "${#FRONT}" -ge 5000 ] \
  || fail "conf2 product response was suspiciously short (${#FRONT} bytes)"
if grep -qiE 'fatal error|uncaught' <<<"$FRONT"; then
  fail "conf2 product page contains a PHP fatal error marker"
fi
grep -qE 'Conformance Widget' <<<"$FRONT" \
  || fail "conf2 product page does not render its own product title"
if grep -qE "localhost:${CONF1_PORT}" <<<"$FRONT"; then
  fail "conf2 product page leaks the conf1 host"
fi
pass "conf2 renders its own product page with no fatal marker or conf1 host leak"

SOURCE_IDS=$(cat "${CONF_REPO1:-siterepo/conf1}/.tmp-woocommerce-source.json")
TARGET_IDS=$(cat "${CONF_REPO2:-siterepo/conf2}/.tmp-woocommerce-target.json")
TARGET=$(observe_woocommerce conf2)
require_observed_nonempty 'WooCommerce source identity record' "$SOURCE_IDS"
require_observed_nonempty 'WooCommerce hostile-target identity record' "$TARGET_IDS"

jq -e --arg version "$WOOCOMMERCE_EXPECTED_VERSION" --arg target "http://localhost:${CONF2_PORT}" --arg source "http://localhost:${CONF1_PORT}" '
  .version == $version and
  .options.brand_description == "yes" and .options.brand_permalink == "maker-houses" and
  .options.visual_attribute == "yes" and
  .options.store_notice_enabled == "yes" and
  .options.store_notice == "<strong>افتتاح المتجر 東京</strong><br>الشحن مجاني" and
  (.options.store_notice_html | contains("<strong>افتتاح المتجر 東京</strong><br>الشحن مجاني")) and
  (.options.store_notice_html | contains("woocommerce-store-notice demo_store")) and
  .review_order.content_has_shortcode == true and
  .review_order.feature == "yes" and .review_order.id == .ids.review_page and
  .review_order.marker == null and .review_order.path == "review-order-source" and
  .review_order.rule_count == 1 and .review_order.rule_actual == .review_order.rule_expected and
  .review_order.slug == "review-order-source" and .review_order.status == "publish" and
  .review_order.title == "استعراض الطلب 東京" and .review_order.type == "page" and
  .simple.title == "Conformance Widget" and
  .precision.title == "Conformance Precision Download 東京 🚀" and
  .precision.regular == "123456789.123456" and .precision.sale == "123456788.654321" and
  .precision.price == "123456788.654321" and .options.precision == "6" and
  .options.thumbnail == {"cropping":"custom","custom_height":"1","custom_width":"1","width":"500"} and
  .precision.description_bytes > 15000 and .precision.purchase_note_bytes > 5000 and
  .precision.local_attributes == [{
    "name":"Material 東京","options":["Cotton","Wool","麻","literal delimiter"],
    "position":2147483647,"variation":false,"visible":true
  }] and
  (.precision.downloads | length) == 1 and
  .precision.downloads[0].enabled == true and
  .precision.downloads[0].id == .precision.downloads[0].key and
  .precision.downloads[0].name == "Portable catalog 日本語 🚀.png" and
  (.precision.downloads[0].file | startswith($target + "/wp-content/uploads/")) and
  (.precision.downloads[0].file | contains($source) | not) and
  .precision.tags == ["portable-tokyo"] and .precision.shipping_class == "oversize-portable" and
  .external == {
    "button_text":"اشتر الآن — 東京","price":"88.88",
    "product_url":($target + "/partner/checkout?campaign=summer&locale=ja"),
    "rest":{"button_text":"اشتر الآن — 東京","external_url":($target + "/partner/checkout?campaign=summer&locale=ja"),"type":"external"},
    "tags":["portable-tokyo"],"title":"Conformance External Partner 東京","type":"external"
  } and
  .category.children == [.ids.category] and .category.display == "both" and
  .category.menu_order == "17" and .category.parent == .ids.category_parent and
  (.category.permalink | startswith($target + "/product-category/")) and
  .category.rest.display == "both" and .category.rest.image == .ids.thumbnail and
  (.category.rest.image_src | startswith($target + "/wp-content/uploads/")) and
  .category.rest.menu_order == 17 and .category.rest.parent == .ids.category_parent and
  .brands.child.description == "<strong>Portable brand 東京</strong>" and
  .brands.child.display == "subcategories" and .brands.child.menu_order == "23" and
  .brands.child.parent == .ids.brand_parent and .brands.child.thumbnail == .ids.thumbnail and
  (.brands.child.permalink | startswith($target + "/maker-houses/")) and
  .brands.children == [.ids.brand_child] and .brands.simple == ["atelier-tokyo"] and
  .brands.rest.display == "subcategories" and .brands.rest.image == .ids.thumbnail and
  (.brands.rest.image_src | startswith($target + "/wp-content/uploads/")) and
  .brands.rest.menu_order == 23 and .brands.rest.parent == .ids.brand_parent and
  .visuals.red == {"color":"#d92f2f","image":0,"order":"7","semantic":{"type":"color","value":"#d92f2f"}} and
  .visuals.blue.color == "" and .visuals.blue.image == .ids.thumbnail and .visuals.blue.order == "3" and
  .visuals.blue.semantic.type == "image" and
  (.visuals.blue.semantic.value | startswith($target + "/wp-content/uploads/")) and
  .simple.tags == ["portable-tokyo"] and .simple.shipping_class == "oversize-portable" and
  .simple.image[1] == 450 and .simple.image[2] == 450 and
  .simple.upsells == [.ids.precision] and .simple.cross_sells == [.ids.grouped] and
  .grouped.children == [.ids.product,.ids.precision] and
  .coupon.status == "publish" and .coupon.type == "percent" and .coupon.amount == "10" and
  .coupon.products == [.ids.product] and .coupon.categories == [.ids.category] and
  .coupon.brands == [.ids.brand_child] and .coupon.excluded_brands == [.ids.brand_excluded] and
  (.attributes | map(select(.attribute_name == "conf-color" and .attribute_label == "Conf Color" and .attribute_orderby == "menu_order" and .attribute_public == "0" and .attribute_type == "wc-visual")) | length) == 1 and
  (.attributes | map(select(.attribute_name == "conf-size" and .attribute_label == "Conf Size" and .attribute_orderby == "menu_order" and .attribute_public == "0" and .attribute_type == "select")) | length) == 1 and
  (.ids.category_parent as $parent | .ids.category as $child |
    (.derived.category_lookup | map([(.category_tree_id | tonumber),(.category_id | tonumber)])) ==
      [[$parent,$parent],[$parent,$child],[$child,$child]]) and
  .derived.brand_rule_count > 0 and
  (.derived.precision_lookup.min_price | tonumber) == 123456788.6543 and
  (.derived.precision_lookup.max_price | tonumber) == 123456788.6543 and
  .shipping.methods.flat_rate.cost == "5.99" and .shipping.methods.free_shipping.min_amount == "50.00" and
  .shipping.tax_class.slug == "conformance-reduced-rate" and .shipping.tax_rate.tax_rate == "7.2500" and
  .options.paypal.enabled == "yes" and
  .options.paypal.email == "target-paypal@example.test" and
  .options.paypal.receiver_email == "target-paypal@example.test" and
  .options.paypal.identity_token == "target-secret-token-preserved" and
  .options.neighbor == "target-neighbor-preserved" and
  .runtime == {"hpos":true,"source_orders":0,"source_queue":0,"source_sessions":0,"target_orders":1,"target_queue":1,"target_sessions":1,"thumbnail_hash":"target-thumbnail-runtime-hash"} and
  (.ids | to_entries | all(.value > 2147483647))
' <<<"$TARGET" >/dev/null || fail "WooCommerce difficult values/native/runtime state did not converge: $TARGET"

for key in brand_child brand_excluded brand_parent category category_parent color_blue color_red review_page tag shipping_class attribute_color attribute_size tax_class; do
  SOURCE_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$SOURCE_IDS")
  EXPECTED_TARGET_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$TARGET_IDS")
  OBSERVED_TARGET_ID=$(jq -r --arg key "$key" '.ids[$key]' <<<"$TARGET")
  require_fixture_ids SOURCE_ID EXPECTED_TARGET_ID OBSERVED_TARGET_ID
  [ "$SOURCE_ID" != "$OBSERVED_TARGET_ID" ] \
    || fail "WooCommerce source/target $key identity did not diverge ($SOURCE_ID)"
  [ "$EXPECTED_TARGET_ID" = "$OBSERVED_TARGET_ID" ] \
    || fail "WooCommerce apply replaced rather than adopted hostile target $key"
done
for key in product precision external grouped variable coupon thumbnail variation_small variation_large zone flat_method free_method tax_rate; do
  SOURCE_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$SOURCE_IDS")
  OBSERVED_TARGET_ID=$(jq -r --arg key "$key" '.ids[$key]' <<<"$TARGET")
  require_fixture_ids SOURCE_ID OBSERVED_TARGET_ID
  [ "$SOURCE_ID" != "$OBSERVED_TARGET_ID" ] \
    || fail "WooCommerce generated target $key reused source-local identity $SOURCE_ID"
done
pass 'hostile terms and typed natural keys retain divergent >2^31 target identities; generated product identities and every nested reference resolve locally'
pass 'precision prices, long UTF-8, local attributes, tags, shipping class, grouped/upsell/cross-sell refs, and downloadable URLs round-trip through native APIs'

EXTERNAL_ID=$(jq -r '.ids.external' <<<"$TARGET")
require_fixture_ids EXTERNAL_ID
EXTERNAL_STORE=$(curl -fsSL "http://localhost:${CONF2_PORT}/wp-json/wc/store/v1/products/${EXTERNAL_ID}") \
  || fail 'conf2 Store API did not return the external product'
require_observed_nonempty 'conf2 external-product Store API response' "$EXTERNAL_STORE"
jq -e '
  .type == "external" and (.add_to_cart.url | type) == "string" and
  .add_to_cart.single_text == "اشتر الآن — 東京"
' <<<"$EXTERNAL_STORE" >/dev/null \
  || fail "WooCommerce Store API did not consume external URL/button state: $EXTERNAL_STORE"
EXTERNAL_STORE_URL=$(jq -er '.add_to_cart.url' <<<"$EXTERNAL_STORE") \
  || fail 'WooCommerce Store API returned no external destination'
EXTERNAL_STORE_URL=$(php -r 'echo html_entity_decode($argv[1], ENT_QUOTES | ENT_HTML5, "UTF-8");' "$EXTERNAL_STORE_URL") \
  || fail 'external-product Store API destination could not be HTML-decoded'
[ "$EXTERNAL_STORE_URL" = "http://localhost:${CONF2_PORT}/partner/checkout?campaign=summer&locale=ja" ] \
  || fail "WooCommerce Store API did not expose the rebound target destination: $EXTERNAL_STORE_URL"
if grep -Fq "localhost:${CONF1_PORT}" <<<"$EXTERNAL_STORE"; then
  fail 'external-product Store API leaked the source host'
fi
EXTERNAL_FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/product/conformance-external-partner/") \
  || fail 'conf2 external-product frontend did not return 200'
require_observed_nonempty 'conf2 external-product frontend response' "$EXTERNAL_FRONT"
grep -Fq 'اشتر الآن — 東京' <<<"$EXTERNAL_FRONT" \
  || fail 'external-product frontend omitted the merchant button text'
grep -Fq "http://localhost:${CONF2_PORT}/partner/checkout?campaign=summer" <<<"$EXTERNAL_FRONT" \
  || fail 'external-product frontend omitted the rebound target destination'
if grep -Fq "localhost:${CONF1_PORT}" <<<"$EXTERNAL_FRONT"; then
  fail 'external-product frontend leaked the source host'
fi
pass 'external product resolves through Woo CRUD, v3 REST, Store API, and frontend using only the target-local rebound URL'

BRAND_ID=$(jq -r '.ids.brand_child' <<<"$TARGET")
COLOR_ATTRIBUTE_ID=$(jq -r '.ids.attribute_color' <<<"$TARGET")
require_fixture_ids BRAND_ID COLOR_ATTRIBUTE_ID
BRAND_STORE=$(curl -fsSL \
  "http://localhost:${CONF2_PORT}/wp-json/wc/store/v1/products/brands/atelier-tokyo") \
  || fail 'conf2 Store API did not return the core brand'
require_observed_nonempty 'conf2 core-brand Store API response' "$BRAND_STORE"
jq -e --arg target "http://localhost:${CONF2_PORT}" --argjson id "$BRAND_ID" '
  .id == $id and .slug == "atelier-tokyo" and
  (.permalink | startswith($target + "/maker-houses/")) and
  (.image.src | startswith($target + "/wp-content/uploads/"))
' <<<"$BRAND_STORE" >/dev/null \
  || fail "WooCommerce Store API did not consume brand hierarchy/image/permalink state: $BRAND_STORE"

VISUAL_STORE=$(curl -fsSG "http://localhost:${CONF2_PORT}/wp-json/wc/store/v1/products/attributes/${COLOR_ATTRIBUTE_ID}/terms" \
  --data-urlencode '__experimental_visual=true' \
  --data-urlencode 'orderby=menu_order' \
  --data-urlencode 'order=asc') \
  || fail 'conf2 Store API did not return visual attribute terms'
require_observed_nonempty 'conf2 visual-attribute Store API response' "$VISUAL_STORE"
jq -e --arg target "http://localhost:${CONF2_PORT}" '
  map(.slug) == ["blue","red"] and
  .[0].__experimentalVisual.type == "image" and
  (.[0].__experimentalVisual.value | startswith($target + "/wp-content/uploads/")) and
  .[1].__experimentalVisual == {"type":"color","value":"#d92f2f"}
' <<<"$VISUAL_STORE" >/dev/null \
  || fail "WooCommerce Store API did not consume visual term order/color/image state: $VISUAL_STORE"

BRAND_ARCHIVE=$(curl -fsSL "$(jq -r '.brands.child.permalink' <<<"$TARGET")") \
  || fail 'conf2 hierarchical brand archive did not return 200'
require_observed_nonempty 'conf2 hierarchical brand archive response' "$BRAND_ARCHIVE"
grep -Fq 'Atelier 東京' <<<"$BRAND_ARCHIVE" \
  || fail 'core brand archive omitted its native term heading'
grep -Fq 'Conformance Widget' <<<"$BRAND_ARCHIVE" \
  || fail 'core brand archive omitted its related product'
if grep -Fq "localhost:${CONF1_PORT}" <<<"$BRAND_ARCHIVE"; then
  fail 'core brand archive leaked the source host'
fi
CATEGORY_ARCHIVE=$(curl -fsSL "$(jq -r '.category.permalink' <<<"$TARGET")") \
  || fail 'conf2 hierarchical product-category archive did not return 200'
require_observed_nonempty 'conf2 hierarchical product-category archive response' "$CATEGORY_ARCHIVE"
grep -Fq 'Conformance Widgets' <<<"$CATEGORY_ARCHIVE" \
  || fail 'product-category archive omitted its native term heading'
grep -Fq 'Conformance Widget' <<<"$CATEGORY_ARCHIVE" \
  || fail 'product-category archive omitted its related product'
if grep -Fq "localhost:${CONF1_PORT}" <<<"$CATEGORY_ARCHIVE"; then
  fail 'product-category archive leaked the source host'
fi
pass 'Brands, category hierarchy, and visual attributes resolve through exact v3 REST, Store API, lookup/rewrite, image, and archive paths'

PROVIDER_RECEIPT="${APPLY_JSON:-}"
if [ -z "$PROVIDER_RECEIPT" ] && [ -n "${VMATRIX_APPLY_LOG:-}" ] && [ -f "$VMATRIX_APPLY_LOG" ]; then
  PROVIDER_RECEIPT=$(cat "$VMATRIX_APPLY_LOG")
fi
grep -Eq 'woocommerce-cache@1\.0\.0 invalidate_cache_groups .*verified' <<<"$PROVIDER_RECEIPT" \
  || fail "initial apply receipt omitted the verified WooCommerce cache provider: ${PROVIDER_RECEIPT:-<missing>}"
grep -Eq 'woocommerce-product-lookups@3\.0\.0 rebuild_product_lookups .*verified' <<<"$PROVIDER_RECEIPT" \
  || fail "initial apply receipt omitted the verified WooCommerce lookup provider: ${PROVIDER_RECEIPT:-<missing>}"
grep -Eq 'woocommerce-hierarchy-lookups@2\.0\.0 rebuild_hierarchy_lookups .*verified' <<<"$PROVIDER_RECEIPT" \
  || fail "initial apply receipt omitted the verified WooCommerce hierarchy provider: ${PROVIDER_RECEIPT:-<missing>}"
if jq -e 'type == "object"' <<<"$PROVIDER_RECEIPT" >/dev/null 2>&1; then
  jq -e '
    any(.actions[]?; .source == "provider:woocommerce-cache/invalidate_cache_groups" and .verified == true) and
    any(.actions[]?; .source == "provider:woocommerce-product-lookups/rebuild_product_lookups" and .verified == true) and
    ([.actions[]? | select(.source == "provider:woocommerce-hierarchy-lookups/rebuild_hierarchy_lookups" and .verified == true)] | length) == 2
  ' <<<"$PROVIDER_RECEIPT" >/dev/null \
    || fail 'WooCommerce provider JSON receipt omitted a closed verified action'
fi
pass 'all selected digest-bound WooCommerce cache, hierarchy, and product providers return verified receipts'

ZERO_PLAN=$($COMPOSE run --rm -T cli2 wp duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce zero-change plan' json "$ZERO_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$ZERO_PLAN" >/dev/null \
  || fail "WooCommerce retry retained work: $ZERO_PLAN"
ZERO_APPLY=$($COMPOSE run --rm -T cli2 wp duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce zero-change apply' json "$ZERO_APPLY"
jq -e '.canary == "clean" and (.actions | length) == 0' <<<"$ZERO_APPLY" >/dev/null \
  || fail "WooCommerce no-op apply was not clean and idempotent: $ZERO_APPLY"
pass 'WooCommerce zero-change plan/apply is mutation-free and does not rerun either provider'

if [ "${WOOCOMMERCE_BOUNDARY_ONLY:-0}" = 1 ]; then
  pass "WooCommerce $WOOCOMMERCE_EXPECTED_VERSION exact boundary consumed the full portable fixture"
  return 0 2>/dev/null || exit 0
fi

commit_woocommerce_source() { # <message>
  wp_conf1 duo capture --repo=/siterepo >/dev/null
  git -C "$CONF_REPO1" add -A
  git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm "$1"
  git -C "$CONF_REPO1" push -q origin main
  git -C "$CONF_REPO2" pull -q origin main
}

observe_woocommerce_adoption() {
  wp_conf2 eval '
    global $wpdb;
    $product_id=wc_get_product_id_by_sku("CONF-ADOPT-PRODUCT");
    $product=wc_get_product($product_id);
    $coupon=new WC_Coupon("CONF-ADOPT-25");
    if (!$product || !$coupon->get_id()) throw new RuntimeException("Woo adoption fixtures are incomplete");
    echo wp_json_encode([
      "product"=>[
        "id"=>(int)$product->get_id(),"name"=>$product->get_name("edit"),
        "regular"=>$product->get_regular_price("edit"),"sale"=>$product->get_sale_price("edit"),
      ],
      "coupon"=>[
        "id"=>(int)$coupon->get_id(),"amount"=>$coupon->get_amount("edit"),
        "description"=>$coupon->get_description("edit"),"type"=>$coupon->get_discount_type("edit"),
      ],
      "runtime"=>[
        "neighbor"=>get_option("duo_target_environment_neighbor"),
        "paypal"=>get_option("woocommerce_paypal_settings"),
        "orders"=>count(wc_get_orders(["billing_email"=>"target-runtime@example.test","limit"=>-1,"return"=>"ids"])),
        "sessions"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key=\"duo-target-runtime-session\""),
        "queue"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook=\"duo_woo_target_runtime_probe\""),
      ],
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  '
}

woocommerce_target_guard() {
  wp_conf2 eval '
    global $wpdb;
    $product_id=wc_get_product_id_by_sku("CONF-ADOPT-PRODUCT");
    $product=wc_get_product($product_id);
    $coupon=new WC_Coupon("CONF-ADOPT-25");
    $guard=[
      "product"=>$product ? [
        "id"=>$product->get_id(),"name"=>$product->get_name("edit"),
        "regular"=>$product->get_regular_price("edit"),"sale"=>$product->get_sale_price("edit"),
      ] : null,
      "coupon"=>$coupon->get_id() ? [
        "id"=>$coupon->get_id(),"amount"=>$coupon->get_amount("edit"),
        "description"=>$coupon->get_description("edit"),"type"=>$coupon->get_discount_type("edit"),
      ] : null,
      "paypal"=>get_option("woocommerce_paypal_settings"),
      "neighbor"=>get_option("duo_target_environment_neighbor"),
      "orders"=>count(wc_get_orders(["billing_email"=>"target-runtime@example.test","limit"=>-1,"return"=>"ids"])),
      "sessions"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key=\"duo-target-runtime-session\""),
      "queue"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook=\"duo_woo_target_runtime_probe\""),
    ];
    echo hash("sha256",wp_json_encode($guard,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  '
}

woocommerce_storage_hash() {
  wp_conf2 eval '
    global $wpdb;
    $state=[];
    $state["posts"]=$wpdb->get_results(
      "SELECT * FROM {$wpdb->posts} WHERE post_type IN (\"product\",\"product_variation\",\"shop_coupon\") ORDER BY ID",
      ARRAY_A
    );
    $state["meta"]=$wpdb->get_results(
      "SELECT pm.* FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id " .
      "WHERE p.post_type IN (\"product\",\"product_variation\",\"shop_coupon\") ORDER BY pm.meta_id",
      ARRAY_A
    );
    $state["options"]=$wpdb->get_results(
      "SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name IN (" .
      "\"pickup_location_pickup_locations\",\"woocommerce_calc_taxes\"," .
      "\"woocommerce_demo_store\",\"woocommerce_demo_store_notice\"," .
      "\"woocommerce_feature_customer_review_request_enabled\"," .
      "\"woocommerce_paypal_settings\",\"woocommerce_pickup_location_settings\"," .
      "\"woocommerce_price_num_decimals\",\"woocommerce_thumbnail_cropping\"," .
      "\"woocommerce_thumbnail_cropping_custom_height\",\"woocommerce_thumbnail_cropping_custom_width\"," .
      "\"woocommerce_thumbnail_image_width\",\"woocommerce_maybe_regenerate_images_hash\"," .
      "\"woocommerce_review_order_flush_rewrite_pending\",\"woocommerce_review_order_page_id\"," .
      "\"duo_target_environment_neighbor\") ORDER BY option_name",
      ARRAY_A
    );
    foreach ([
      "attributes"=>"woocommerce_attribute_taxonomies",
      "zones"=>"woocommerce_shipping_zones",
      "zone_locations"=>"woocommerce_shipping_zone_locations",
      "zone_methods"=>"woocommerce_shipping_zone_methods",
      "tax_classes"=>"wc_tax_rate_classes",
      "tax_rates"=>"woocommerce_tax_rates",
      "tax_locations"=>"woocommerce_tax_rate_locations",
      "lookups"=>"wc_product_meta_lookup",
      "download_directories"=>"wc_product_download_directories",
    ] as $key=>$suffix) {
      $table=$wpdb->prefix.$suffix;
      $state[$key]=$wpdb->get_results("SELECT * FROM `$table` ORDER BY 1",ARRAY_A);
    }
    echo hash("sha256",wp_json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  '
}

# The ordinary guard intentionally covers authored/runtime state only. A
# promotion race must also prove that every database checkpoint declared by
# the selected product, hierarchy, and route providers stayed byte-still behind
# the losing process's refusal.
woocommerce_provider_guard() {
  wp_conf2 eval '
    global $wpdb;
    $queries=[
      "posts"=>"SELECT * FROM {$wpdb->posts} WHERE post_type IN (\"product\",\"product_variation\") ORDER BY ID",
      "postmeta"=>"SELECT pm.* FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN (\"product\",\"product_variation\") ORDER BY pm.meta_id",
      "term_taxonomy"=>"SELECT * FROM {$wpdb->term_taxonomy} WHERE taxonomy IN (\"product_brand\",\"product_cat\",\"product_type\",\"product_visibility\") OR taxonomy LIKE \"pa\\_%\" ORDER BY term_taxonomy_id",
      "term_relationships"=>"SELECT tr.* FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.taxonomy IN (\"product_brand\",\"product_cat\",\"product_type\",\"product_visibility\") OR tt.taxonomy LIKE \"pa\\_%\" ORDER BY tr.object_id,tr.term_taxonomy_id",
      "options"=>"SELECT option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name IN (\"product_brand_children\",\"product_cat_children\",\"rewrite_rules\",\"woocommerce_brand_permalink\",\"woocommerce_permalinks\",\"_transient_wc_products_onsale\",\"_transient_timeout_wc_products_onsale\") ORDER BY option_name",
      "category_lookup"=>"SELECT * FROM {$wpdb->prefix}wc_category_lookup ORDER BY 1,2",
      "product_meta_lookup"=>"SELECT * FROM {$wpdb->prefix}wc_product_meta_lookup ORDER BY product_id",
      "product_attributes_lookup"=>"SELECT * FROM {$wpdb->prefix}wc_product_attributes_lookup ORDER BY product_or_parent_id,product_id,taxonomy,term_id",
      "product_download_directories"=>"SELECT * FROM {$wpdb->prefix}wc_product_download_directories ORDER BY id",
      "scheduler_actions"=>"SELECT * FROM {$wpdb->prefix}actionscheduler_actions ORDER BY action_id",
      "scheduler_groups"=>"SELECT * FROM {$wpdb->prefix}actionscheduler_groups ORDER BY group_id",
      "scheduler_logs"=>"SELECT * FROM {$wpdb->prefix}actionscheduler_logs ORDER BY log_id",
    ];
    $state=[];
    foreach($queries as $name=>$sql){
      $wpdb->last_error="";
      $rows=$wpdb->get_results($sql,ARRAY_A);
      if(!is_array($rows)||$wpdb->last_error!==""){throw new RuntimeException("WooCommerce provider-race guard read failed: ".$name);}
      if(count($rows)>50000){throw new RuntimeException("WooCommerce provider-race guard exceeded its fixture bound: ".$name);}
      $state[$name]=$rows;
    }
    echo hash("sha256",wp_json_encode($state,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  '
}

woocommerce_provider_state() {
  wp_conf2 eval '
    global $wpdb;
    $id=wc_get_product_id_by_sku("CONF-ADOPT-PRODUCT");
    $product=wc_get_product($id);
    $brand_parent=get_term_by("slug","conformance-makers","product_brand");
    $category=get_term_by("slug","conformance-widgets","product_cat");
    $category_parent=get_term_by("slug","conformance-catalog","product_cat");
    $children=get_option("product_brand_children",[]);
    $category_children=get_option("product_cat_children",[]);
    $rules=get_option("rewrite_rules",[]);
    $brand_rules=array_values(array_filter(array_keys(is_array($rules)?$rules:[]),static fn($rule)=>is_string($rule)&&str_starts_with($rule,"race-brand/")));
    sort($brand_rules);
    echo wp_json_encode([
      "product"=>$product ? ["regular"=>$product->get_regular_price("edit"),"sale"=>$product->get_sale_price("edit")] : null,
      "lookup"=>$wpdb->get_row($wpdb->prepare("SELECT min_price,max_price FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id=%d",$id),ARRAY_A),
      "brand_permalink"=>get_option("woocommerce_brand_permalink"),
      "product_base"=>(string)(get_option("woocommerce_permalinks",[])["product_base"]??""),
      "brand_children"=>is_array($children)?($children[(int)($brand_parent->term_id??0)]??[]):[],
      "category_children"=>is_array($category_children)?($category_children[(int)($category_parent->term_id??0)]??[]):[],
      "category_lookup"=>$wpdb->get_results($wpdb->prepare("SELECT category_tree_id,category_id FROM {$wpdb->prefix}wc_category_lookup WHERE category_id IN (%d,%d) ORDER BY category_tree_id,category_id",(int)($category_parent->term_id??0),(int)($category->term_id??0)),ARRAY_A),
      "brand_rules"=>$brand_rules,
      "hpos"=>get_option("woocommerce_custom_orders_table_enabled")==="yes",
      "runtime"=>[
        "neighbor"=>get_option("duo_target_environment_neighbor"),
        "paypal"=>get_option("woocommerce_paypal_settings"),
        "orders"=>count(wc_get_orders(["billing_email"=>"target-runtime@example.test","limit"=>-1,"return"=>"ids"])),
        "sessions"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key=\"duo-target-runtime-session\""),
        "queue"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook=\"duo_woo_target_runtime_probe\""),
      ],
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE);
  '
}

# Add two repository entities only after the first clean round trip, and put
# hostile same-slug rows on the target before their first apply. This isolates
# explicit posts adoption from Woo's legitimate derived timestamp churn.
SOURCE_ADOPT=$(wp_conf1 eval '
  $product=new WC_Product_Simple();
  $product->set_name("Repository adopted product 東京 🚀");
  $product->set_slug("duo-woo-adopt-product");
  $product->set_status("publish");
  $product->set_sku("CONF-ADOPT-PRODUCT");
  $product->set_regular_price("44.444444");
  $product->set_virtual(true);
  $product_id=$product->save();
  $coupon=new WC_Coupon();
  $coupon->set_code("CONF-ADOPT-25");
  $coupon->set_status("publish");
  $coupon->set_discount_type("fixed_cart");
  $coupon->set_amount("25.25");
  $coupon->set_description("Repository adopted coupon 東京 🚀");
  $coupon_id=$coupon->save();
  echo wp_json_encode(["product"=>$product_id,"coupon"=>$coupon_id]);
')
TARGET_ADOPT=$(wp_conf2 eval '
  $product=new WC_Product_Simple();
  $product->set_name("Hostile target adopted product");
  $product->set_slug("duo-woo-adopt-product");
  $product->set_status("publish");
  $product->set_sku("TARGET-HOSTILE-ADOPT");
  $product->set_regular_price("999.999999");
  $product->set_virtual(false);
  $product_id=$product->save();
  $coupon=new WC_Coupon();
  $coupon->set_code("CONF-ADOPT-25");
  $coupon->set_status("publish");
  $coupon->set_discount_type("percent");
  $coupon->set_amount("99");
  $coupon->set_description("Hostile target coupon");
  $coupon_id=$coupon->save();
  echo wp_json_encode(["product"=>$product_id,"coupon"=>$coupon_id]);
')
require_observed_nonempty 'WooCommerce source adoption identities' "$SOURCE_ADOPT"
require_observed_nonempty 'WooCommerce target adoption identities' "$TARGET_ADOPT"
commit_woocommerce_source 'conformance: WooCommerce same-slug adoption fixtures'
ADOPTED=$(wp_conf2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce same-slug product/coupon adoption' json "$ADOPTED"
jq -e '.canary == "clean" and .verification.result == "pass" and .plan.adopt >= 2' <<<"$ADOPTED" >/dev/null \
  || fail "WooCommerce same-slug product/coupon adoption did not converge: $ADOPTED"
ADOPTED_NATIVE=$(observe_woocommerce_adoption)
jq -e --argjson ids "$TARGET_ADOPT" '
  .product.id == $ids.product and .product.name == "Repository adopted product 東京 🚀" and
  .product.regular == "44.444444" and .coupon.id == $ids.coupon and
  .coupon.amount == "25.25" and .coupon.type == "fixed_cart" and
  .coupon.description == "Repository adopted coupon 東京 🚀" and
  .runtime.neighbor == "target-neighbor-preserved" and
  .runtime.paypal.identity_token == "target-secret-token-preserved" and
  .runtime.orders == 1 and .runtime.sessions == 1 and .runtime.queue == 1
' <<<"$ADOPTED_NATIVE" >/dev/null || fail "WooCommerce adopted native state crossed a target boundary: $ADOPTED_NATIVE"
[ "$(jq -r '.product' <<<"$SOURCE_ADOPT")" != "$(jq -r '.product' <<<"$TARGET_ADOPT")" ] \
  || fail 'WooCommerce product adoption fixture reused the source identity'
[ "$(jq -r '.coupon' <<<"$SOURCE_ADOPT")" != "$(jq -r '.coupon' <<<"$TARGET_ADOPT")" ] \
  || fail 'WooCommerce coupon adoption fixture reused the source identity'
pass 'hostile same-slug product and coupon rows retain target identities while repository-authored native values converge'

# Corrupt structured product metadata, introduce a populated closed COD record
# with one bounded unknown add-on sibling, and remove one repository product
# row. Each independent capture must refuse before changing canonical state;
# exact raw restoration must recapture byte-identically.
CAPTURE_BASELINE=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
ATTR_BACKUP=$(wp_conf1 eval '
  global $wpdb; $id=wc_get_product_id_by_sku("CONF-PRECISION-UTF8");
  echo base64_encode((string)$wpdb->get_var($wpdb->prepare(
    "SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id=%d AND meta_key=\"_product_attributes\" LIMIT 1",$id
  )));
')
require_observed_nonempty 'WooCommerce raw product-attribute backup' "$ATTR_BACKUP"
wp_conf1 eval '
  global $wpdb; $id=wc_get_product_id_by_sku("CONF-PRECISION-UTF8");
  $wpdb->update($wpdb->postmeta,["meta_value"=>"malformed-product-attributes"],["post_id"=>$id,"meta_key"=>"_product_attributes"]);
  clean_post_cache($id);
' >/dev/null
MALFORMED_RC=0
MALFORMED_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || MALFORMED_RC=$?
require_duo_answered 'WooCommerce malformed product-attribute capture' human "$MALFORMED_OUT"
[ "$MALFORMED_RC" -ne 0 ] && grep -Eqi 'product_attributes|structured|object|adapter schema' <<<"$MALFORMED_OUT" \
  || fail "WooCommerce malformed product attributes did not refuse: $MALFORMED_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$CAPTURE_BASELINE" ] \
  || fail 'WooCommerce malformed metadata refusal partially published canonical state'
wp_conf1 eval "
  global \$wpdb; \$id=wc_get_product_id_by_sku('CONF-PRECISION-UTF8');
  \$wpdb->update(\$wpdb->postmeta,['meta_value'=>base64_decode('$ATTR_BACKUP')],['post_id'=>\$id,'meta_key'=>'_product_attributes']);
  clean_post_cache(\$id);
" >/dev/null

FAKE_SECRET='AKIAABCDEFGHIJKLMNOP'
wp_conf1 eval '
  update_option("woocommerce_cod_settings", [
    "enabled" => "yes",
    "title" => "Cash on delivery",
    "description" => "Pay on delivery",
    "instructions" => "AKIAABCDEFGHIJKLMNOP",
    "enable_for_methods" => ["flat_rate:3147484001"],
    "enable_for_virtual" => "yes",
    "cod_addon_secret" => "AKIAABCDEFGHIJKLMNOP",
  ]);
' >/dev/null
SECRET_RC=0
SECRET_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || SECRET_RC=$?
require_duo_answered 'WooCommerce populated COD boundary capture' human "$SECRET_OUT"
[ "$SECRET_RC" -ne 0 ] && grep -Fq 'woocommerce_cod_settings' <<<"$SECRET_OUT" \
  && grep -Fq 'undeclared sibling key(s)' <<<"$SECRET_OUT" \
  && ! grep -Fq "$FAKE_SECRET" <<<"$SECRET_OUT" \
  || fail "WooCommerce COD closed-record undeclared sibling key did not refuse at normalization and redact: $SECRET_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$CAPTURE_BASELINE" ] \
  || fail 'WooCommerce COD undeclared-sibling refusal partially published canonical state'
wp_conf1 option delete woocommerce_cod_settings >/dev/null

DELETE_ROW=$(wp_conf1 eval '
  global $wpdb; $id=wc_get_product_id_by_sku("CONF-ADOPT-PRODUCT");
  echo base64_encode(wp_json_encode($wpdb->get_row($wpdb->prepare(
    "SELECT * FROM {$wpdb->posts} WHERE ID=%d",$id
  ),ARRAY_A),JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
')
require_observed_nonempty 'WooCommerce unsupported product-delete backup' "$DELETE_ROW"
wp_conf1 eval '
  global $wpdb; $id=wc_get_product_id_by_sku("CONF-ADOPT-PRODUCT");
  if (1 !== $wpdb->delete($wpdb->posts,["ID"=>$id],["%d"])) throw new RuntimeException($wpdb->last_error);
  clean_post_cache($id);
' >/dev/null
DELETE_RC=0
DELETE_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || DELETE_RC=$?
require_duo_answered 'WooCommerce unsupported product deletion capture' human "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && grep -Eqi 'delet|unsupported|policy scope' <<<"$DELETE_OUT" \
  || fail "WooCommerce unsupported product deletion did not refuse: $DELETE_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$CAPTURE_BASELINE" ] \
  || fail 'WooCommerce unsupported deletion refusal partially published canonical state'
wp_conf1 eval "
  global \$wpdb; \$row=json_decode(base64_decode('$DELETE_ROW'),true,512,JSON_THROW_ON_ERROR);
  if (false === \$wpdb->insert(\$wpdb->posts,\$row)) throw new RuntimeException(\$wpdb->last_error);
  clean_post_cache((int)\$row['ID']);
" >/dev/null
wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-woocommerce-restored >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO1/.tmp-woocommerce-restored" \
  || fail 'WooCommerce source did not restore byte-identically after malformed/undeclared-COD/deletion probes'
rm -rf "$CONF_REPO1/.tmp-woocommerce-restored"
pass 'malformed attributes, undeclared COD add-on sibling, and unsupported product deletion refuse atomically and redact values'

# Both branches edit one managed native price. Unforced application must be
# byte-still on the target; explicit repository authority must converge without
# crossing target payment, order, session, queue, or unrelated-option state.
wp_conf1 eval '
  $product=wc_get_product(wc_get_product_id_by_sku("CONF-ADOPT-PRODUCT"));
  $product->set_regular_price("55.123456"); $product->save();
' >/dev/null
commit_woocommerce_source 'conformance: competing WooCommerce product-price intent'
wp_conf2 eval '
  $product=wc_get_product(wc_get_product_id_by_sku("CONF-ADOPT-PRODUCT"));
  $product->set_regular_price("77.654321"); $product->save();
' >/dev/null
CONFLICT_BEFORE=$(woocommerce_target_guard)
CONFLICT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce competing product-price plan' json "$CONFLICT_PLAN"
jq -e '(.conflict | length) > 0' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "WooCommerce competing price did not produce a conflict: $CONFLICT_PLAN"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_duo_answered 'WooCommerce unforced competing product apply' human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflict' <<<"$CONFLICT_OUT" \
  || fail "WooCommerce unforced competing product apply did not refuse: $CONFLICT_OUT"
[ "$(woocommerce_target_guard)" = "$CONFLICT_BEFORE" ] \
  || fail 'WooCommerce unforced conflict partially mutated target state'
FORCED=$(wp_conf2 duo apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce forced competing product apply' json "$FORCED"
jq -e '.canary == "clean" and .verification.result == "pass" and .plan.conflict > 0' <<<"$FORCED" >/dev/null \
  || fail "WooCommerce forced repository intent did not converge: $FORCED"
CONVERGED=$(observe_woocommerce_adoption)
jq -e --argjson ids "$TARGET_ADOPT" '
  .product.id == $ids.product and .product.regular == "55.123456" and
  .runtime.neighbor == "target-neighbor-preserved" and
  .runtime.paypal.identity_token == "target-secret-token-preserved" and
  .runtime.orders == 1 and .runtime.sessions == 1 and .runtime.queue == 1
' <<<"$CONVERGED" >/dev/null || fail "WooCommerce forced conflict crossed a target boundary: $CONVERGED"
pass 'dirty native price conflicts refuse atomically; explicit repository authority preserves target runtime and environment state'

# Publish another authored price, then break the exact lookup schema after the
# commit is visible. The provider must fail loudly after retaining authored
# state, not advance the verified revision, keep retry authority, and converge
# from that authority after the schema is restored.
wp_conf1 eval '
  $product=wc_get_product(wc_get_product_id_by_sku("CONF-ADOPT-PRODUCT"));
  $product->set_sale_price("49.123456"); $product->save();
' >/dev/null
commit_woocommerce_source 'conformance: WooCommerce lookup-schema recovery intent'
wp_conf2 db query 'ALTER TABLE wp_wc_product_meta_lookup RENAME COLUMN min_price TO duo_fault_min_price' >/dev/null
SCHEMA_REV_BEFORE=$(wp_conf2 db query "SELECT v FROM wp_duo_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty 'WooCommerce applied revision before lookup-schema fault' "$SCHEMA_REV_BEFORE"
SCHEMA_RC=0
SCHEMA_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || SCHEMA_RC=$?
require_duo_answered 'WooCommerce lookup-schema provider failure' human "$SCHEMA_OUT"
[ "$SCHEMA_RC" -ne 0 ] && grep -Eq "provider 'woocommerce-product-lookups' capability 'rebuild_product_lookups' failed" <<<"$SCHEMA_OUT" \
  || fail "WooCommerce lookup-schema fault did not refuse through the provider: $SCHEMA_OUT"
[ "$(wp_conf2 db query "SELECT v FROM wp_duo_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')" = "$SCHEMA_REV_BEFORE" ] \
  || fail 'WooCommerce provider failure advanced applied_revision before verified effects'
[ "$(wp_conf2 eval 'echo null === \Duo\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = retained ] \
  || fail 'WooCommerce provider failure did not retain retry authority'
FAILED_AUTHORED=$(observe_woocommerce_adoption)
jq -e '
  .product.regular == "55.123456" and .product.sale == "49.123456" and
  .runtime.neighbor == "target-neighbor-preserved" and
  .runtime.paypal.identity_token == "target-secret-token-preserved" and
  .runtime.orders == 1 and .runtime.sessions == 1 and .runtime.queue == 1
' <<<"$FAILED_AUTHORED" >/dev/null || fail "WooCommerce provider failure lost retained authored or runtime state: $FAILED_AUTHORED"
wp_conf2 db query 'ALTER TABLE wp_wc_product_meta_lookup RENAME COLUMN duo_fault_min_price TO min_price' >/dev/null
RETRY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce retry after lookup-schema repair' json "$RETRY"
jq -e '
  .canary == "clean" and .verification.result == "pass" and .applied >= 1 and
  any(.actions[]?; .source == "provider:woocommerce-product-lookups/rebuild_product_lookups" and .verified == true)
' <<<"$RETRY" >/dev/null || fail "WooCommerce lookup-schema retry did not consume durable intent: $RETRY"
RETRIED_LOOKUP=$(wp_conf2 eval '
  global $wpdb; $id=wc_get_product_id_by_sku("CONF-ADOPT-PRODUCT"); $product=wc_get_product($id);
  $row=$wpdb->get_row($wpdb->prepare(
    "SELECT min_price,max_price FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id=%d",$id
  ),ARRAY_A);
  echo wp_json_encode(["regular"=>$product->get_regular_price("edit"),"sale"=>$product->get_sale_price("edit"),"lookup"=>$row]);
')
jq -e '
  .regular == "55.123456" and .sale == "49.123456" and
  .lookup.min_price == "49.1235" and .lookup.max_price == "49.1235"
' <<<"$RETRIED_LOOKUP" >/dev/null || fail "WooCommerce retry did not restore native price/lookup state: $RETRIED_LOOKUP"
pass 'lookup-schema failure retains authored intent and retry authority, then exact repair converges through WooCommerce readback'

# Removing one authored field is an ordinary update, never an entity tombstone.
wp_conf1 eval '
  $id=wc_get_product_id_by_sku("CONF-PRECISION-UTF8");
  delete_post_meta($id,"_purchase_note");
' >/dev/null
commit_woocommerce_source 'conformance: WooCommerce authored field absence'
FIELD_REMOVED=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce authored field-absence apply' json "$FIELD_REMOVED"
jq -e '.canary == "clean" and .verification.result == "pass" and .plan.update > 0 and .plan.delete == 0 and .plan.deleted == 0' <<<"$FIELD_REMOVED" >/dev/null \
  || fail "WooCommerce authored field absence did not converge as an update: $FIELD_REMOVED"
[ "$(wp_conf2 eval '$p=wc_get_product(wc_get_product_id_by_sku("CONF-PRECISION-UTF8")); echo $p->get_purchase_note("edit");')" = '' ] \
  || fail 'WooCommerce native product API retained the removed purchase note'
pass 'authored product-meta absence converges as an ordinary verified update'

# Pause the winner after its precondition recheck, while it has the promotion
# fence. The contender is therefore deterministic: it must refuse at the named
# fence before it can mutate authored data or either Woo provider authority.
wp_conf1 eval '
  $product=wc_get_product(wc_get_product_id_by_sku("CONF-ADOPT-PRODUCT"));
  $product->set_name("Concurrent WooCommerce intent 東京 🚀");
  $product->set_regular_price("66.123456");
  $product->set_sale_price("61.123456");
  $product->save();
  update_option("woocommerce_brand_permalink","race-brand");
  $permalinks=get_option("woocommerce_permalinks",[]);
  $permalinks["product_base"]="race-store/%product_cat%";
  update_option("woocommerce_permalinks",$permalinks);
' >/dev/null
commit_woocommerce_source 'conformance: concurrent WooCommerce apply intent'
CONCURRENT_HOLDER="$CONF_REPO2/.tmp-woocommerce-provider-holder.log"
CONCURRENT_LOSER="$CONF_REPO2/.tmp-woocommerce-provider-loser.log"
CONCURRENT_BEFORE=$(woocommerce_provider_guard)
woo_apply_test() {
  $COMPOSE run --rm -T \
    -e DUO_TEST_MODE=1 -e DUO_TEST_PROMOTION_PAUSE_MS=10000 \
    cli2 sh -c 'umask 000; exec wp "$@"' sh "$@"
}
set +e
woo_apply_test duo apply --repo=/siterepo --default-author=admin --format=json >"$CONCURRENT_HOLDER" 2>&1 & HOLDER_PID=$!
set -e
PAUSE_READY=0
for _ in $(seq 1 120); do
  if $COMPOSE run --rm -T cli2 wp eval 'echo (\Duo\PromotionLock::current()["phase"] ?? "");' 2>/dev/null | grep -q '^precondition-recheck$'; then
    PAUSE_READY=1; CONCURRENT_PAUSE_OBSERVED_AT=$(date +%s); break
  fi
  sleep 0.1
done
[ "$PAUSE_READY" -eq 1 ] || fail "WooCommerce promotion holder did not reach deterministic pause: $(cat "$CONCURRENT_HOLDER")"
[ "$($COMPOSE run --rm -T cli2 wp eval 'echo (\Duo\PromotionLock::current()["phase"] ?? "");' 2>/dev/null | tail -1)" = precondition-recheck ] \
  || fail 'WooCommerce promotion holder left the deterministic pause before the contender started'
CONCURRENT_LOSER_RC=0
wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json >"$CONCURRENT_LOSER" 2>&1 || CONCURRENT_LOSER_RC=$?
[ "$($COMPOSE run --rm -T cli2 wp eval 'echo (\Duo\PromotionLock::current()["phase"] ?? "");' 2>/dev/null | tail -1)" = precondition-recheck ] \
  || fail 'WooCommerce promotion holder left the deterministic pause before the contender refusal was observed'
CONCURRENT_LOSER_JSON=$(awk 'NF { line=$0 } END { print line }' "$CONCURRENT_LOSER")
require_duo_answered 'WooCommerce deterministic provider race loser' json "$CONCURRENT_LOSER_JSON"
[ "$CONCURRENT_LOSER_RC" -ne 0 ] || fail "WooCommerce race loser unexpectedly succeeded: $CONCURRENT_LOSER_JSON"
jq -e '
  .format == "duo-command-refusal/v1" and .ok == false and
  .error == "process_fence_held" and .reason_code == "process_fence_held" and
  .message == "another live process on this target holds the promotion fence; concurrent target mutation was refused"
' <<<"$CONCURRENT_LOSER_JSON" >/dev/null \
  || fail "WooCommerce race loser did not return the typed process_fence_held refusal: $CONCURRENT_LOSER_JSON"
CONCURRENT_AFTER_LOSER=$(woocommerce_provider_guard)
[ "$($COMPOSE run --rm -T cli2 wp eval 'echo (\Duo\PromotionLock::current()["phase"] ?? "");' 2>/dev/null | tail -1)" = precondition-recheck ] \
  || fail 'WooCommerce promotion holder left the deterministic pause during the loser mutation guard'
[ "$(($(date +%s) - CONCURRENT_PAUSE_OBSERVED_AT))" -lt 8 ] \
  || fail 'WooCommerce contender evidence exceeded the bounded promotion-pause window'
[ "$CONCURRENT_AFTER_LOSER" = "$CONCURRENT_BEFORE" ] \
  || fail 'WooCommerce race loser mutated authored or provider-derived state'
set +e
wait "$HOLDER_PID"; CONCURRENT_HOLDER_RC=$?
set -e
[ "$CONCURRENT_HOLDER_RC" -eq 0 ] || fail "WooCommerce race winner failed: $(cat "$CONCURRENT_HOLDER")"
CONCURRENT_WINNER_JSON=$(awk 'NF { line=$0 } END { print line }' "$CONCURRENT_HOLDER")
require_duo_answered 'WooCommerce deterministic provider race winner' json "$CONCURRENT_WINNER_JSON"
jq -e '
  .canary == "clean" and .verification.result == "pass" and
  any(.actions[]?; .source == "provider:woocommerce-product-lookups/rebuild_product_lookups" and .verified == true) and
  any(.actions[]?; .source == "provider:woocommerce-hierarchy-lookups/rebuild_hierarchy_lookups" and .verified == true) and
  any(.actions[]?; .source == "provider:woocommerce-hierarchy-lookups/rebuild_product_permalink_routes" and .verified == true)
' <<<"$CONCURRENT_WINNER_JSON" >/dev/null \
  || fail "WooCommerce race winner omitted verified product, hierarchy, or route providers: $CONCURRENT_WINNER_JSON"
CONCURRENT=$(observe_woocommerce_adoption)
jq -e '.product.name == "Concurrent WooCommerce intent 東京 🚀" and .product.regular == "66.123456" and .product.sale == "61.123456"' <<<"$CONCURRENT" >/dev/null \
  || fail "competing WooCommerce applies lost repository intent: $CONCURRENT"
CONCURRENT_STATE=$(woocommerce_provider_state)
jq -e '
  .product.regular == "66.123456" and .product.sale == "61.123456" and
  .lookup.min_price == "61.1235" and .lookup.max_price == "61.1235" and
  .brand_permalink == "race-brand" and .product_base == "race-store/%product_cat%" and
  (.brand_rules | length) >= 1 and (.category_lookup | length) >= 2 and
  .hpos == true and .runtime.neighbor == "target-neighbor-preserved" and
  .runtime.paypal.identity_token == "target-secret-token-preserved" and
  .runtime.orders == 1 and .runtime.sessions == 1 and .runtime.queue == 1
' <<<"$CONCURRENT_STATE" >/dev/null \
  || fail "WooCommerce race winner did not verify native lookup/hierarchy/rewrite/runtime state: $CONCURRENT_STATE"
[ "$(wp_conf2 eval 'echo null === \Duo\PromotionLock::current() ? "clear" : "held";')" = clear ] \
  || fail 'WooCommerce race winner left a stale promotion-lock marker'
[ "$(wp_conf2 eval 'echo null === \Duo\Ledger::kv_get("apply_in_progress") ? "clear" : "held";')" = clear ] \
  || fail 'WooCommerce race winner left a stale apply-in-progress marker'
CONCURRENT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce plan after competing applies' json "$CONCURRENT_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$CONCURRENT_PLAN" >/dev/null \
  || fail "WooCommerce competing applies left retained work: $CONCURRENT_PLAN"
CONCURRENT_RETRY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce provider race zero-action retry' json "$CONCURRENT_RETRY"
jq -e '.canary == "clean" and .verification.result == "pass" and .applied == 0 and (.actions | length) == 0' <<<"$CONCURRENT_RETRY" >/dev/null \
  || fail "WooCommerce provider race retry was not a zero-action no-op: $CONCURRENT_RETRY"
rm -f "$CONCURRENT_HOLDER" "$CONCURRENT_LOSER"
pass 'deterministic WooCommerce provider race refuses the loser at process_fence_held, verifies Woo lookup/hierarchy/rewrite/runtime state, and leaves one exact zero-action retry'

# Deactivation is repaired by deploy. Woo's default uninstall keeps catalog,
# typed configuration, lookup, approved-directory, and HPOS data unless the
# merchant explicitly enables destructive cleanup; absent code must refuse,
# then the digest-bound cached artifact must recover the retained state.
wp_conf2 plugin deactivate woocommerce >/dev/null
wp_conf2 plugin is-active woocommerce >/dev/null 2>&1 && fail 'WooCommerce deactivation premise did not land'
REACTIVATE=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce deploy after deactivation' json "$REACTIVATE"
wp_conf2 plugin is-active woocommerce >/dev/null || fail 'Duo deploy did not reactivate exact WooCommerce code'
normalize_woocommerce_harness_placeholder_mode wp_conf2
LIFECYCLE_BEFORE=$(woocommerce_storage_hash)
wp_conf2 plugin deactivate woocommerce >/dev/null
wp_conf2 plugin uninstall woocommerce >/dev/null
wp_conf2 plugin is-installed woocommerce >/dev/null 2>&1 && fail 'WooCommerce uninstall left plugin code installed'
[ "$(woocommerce_storage_hash)" = "$LIFECYCLE_BEFORE" ] \
  || fail 'WooCommerce default uninstall changed retained catalog/configuration storage'
MISSING_RC=0
MISSING_OUT=$(wp_conf2 duo deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_duo_answered 'WooCommerce deploy with code absent' human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing WooCommerce code did not refuse at compatibility: $MISSING_OUT"
WOO_SHA=da189b6616c610d15a2106f93151dab81b78f83e075bcefce221ac0d00b4fa21
WOO_ARTIFACT="/artifacts-cache/plugin-woocommerce-11.0.1-${WOO_SHA}.zip"
[ "$(wp_conf2 eval "echo hash_file('sha256','$WOO_ARTIFACT');")" = "$WOO_SHA" ] \
  || fail 'cached WooCommerce reinstall artifact digest moved'
wp_conf2 plugin install "$WOO_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get woocommerce --field=version)" = 11.0.1 ] \
  || fail 'WooCommerce exact reinstall reported the wrong version'
REINSTALL_DEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce deploy after exact reinstall' json "$REINSTALL_DEPLOY"
wp_conf2 plugin is-active woocommerce >/dev/null || fail 'WooCommerce exact reinstall was not active after deploy'
normalize_woocommerce_harness_placeholder_mode wp_conf2
RECOVERED=$(observe_woocommerce_adoption)
jq -e --argjson ids "$TARGET_ADOPT" '
  .product.id == $ids.product and .product.name == "Concurrent WooCommerce intent 東京 🚀" and
  .product.regular == "66.123456" and .product.sale == "61.123456" and
  .coupon.id == $ids.coupon and .runtime.neighbor == "target-neighbor-preserved" and
  .runtime.paypal.identity_token == "target-secret-token-preserved" and
  .runtime.orders == 1 and .runtime.sessions == 1 and .runtime.queue == 1
' <<<"$RECOVERED" >/dev/null || fail "WooCommerce retained native state did not recover after reinstall: $RECOVERED"
EXTRA_ACTIVE=$(wp_conf2 plugin list --status=active --field=name | grep -v '^woocommerce$' || true)
[ -z "$EXTRA_ACTIVE" ] || fail "WooCommerce scope fixture unexpectedly activated optional extensions: $EXTRA_ACTIVE"
FINAL_APPLY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce final apply after exact reinstall' json "$FINAL_APPLY"
jq -e '.canary == "clean" and .verification.result == "pass"' <<<"$FINAL_APPLY" >/dev/null \
  || fail "WooCommerce exact reinstall did not remain clean: $FINAL_APPLY"
FINAL_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce final recovery plan' json "$FINAL_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$FINAL_PLAN" >/dev/null \
  || fail "WooCommerce recovery was not idempotent: $FINAL_PLAN"
wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-woocommerce-final >/dev/null
FINAL_DIFF_RC=0
FINAL_DIFF=$(diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-woocommerce-final" 2>&1) || FINAL_DIFF_RC=$?
[ "$FINAL_DIFF_RC" -le 1 ] || fail "WooCommerce final recapture comparison errored: $FINAL_DIFF"
if [ -n "$FINAL_DIFF" ]; then
  UNEXPECTED_DIFF=$(grep -Ev \
    -e '^diff -r .*/state/posts/(product|product_variation)/[^ ]+ .*/\.tmp-woocommerce-final/posts/(product|product_variation)/[^ ]+$' \
    -e '^[0-9]+(,[0-9]+)?c[0-9]+(,[0-9]+)?$' \
    -e '^---$' \
    -e '^[<>]     "modified(_gmt)?": "[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}",$' \
    <<<"$FINAL_DIFF" || true)
  [ -z "$UNEXPECTED_DIFF" ] \
    || fail "WooCommerce final recapture diverged outside target-local product timestamps: $FINAL_DIFF"
fi
rm -rf "$CONF_REPO2/.tmp-woocommerce-final"
pass 'deactivate/reactivate, retained-data uninstall, absent-code refusal, exact reinstall, optional-extension isolation, and final retry are exact modulo declared target-local product timestamps'

# The default uninstall above proves Woo's retention contract. This separate
# exact-artifact branch proves the operator-authorized destructive inverse and
# credits only a database-matched backup with recovery.
. conformance/checks/woocommerce-destructive-lifecycle.sh
check_woocommerce_destructive_lifecycle
