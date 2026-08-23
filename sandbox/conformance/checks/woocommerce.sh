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
WOOCOMMERCE_EXPECTED_VERSION="${WOOCOMMERCE_EXPECTED_VERSION:-11.0.0}"

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
$groupedId = wc_get_product_id_by_sku('CONF-GROUPED-KIT');
$smallId = wc_get_product_id_by_sku('CONF-VAR-S-RED');
$largeId = wc_get_product_id_by_sku('CONF-VAR-L-BLUE');
$variableId = (int) get_post_field('post_parent', $smallId);
$simple = wc_get_product($simpleId);
$precision = wc_get_product($precisionId);
$grouped = wc_get_product($groupedId);
$variable = wc_get_product($variableId);
$small = wc_get_product($smallId);
$large = wc_get_product($largeId);
$coupon = new WC_Coupon('CONF-WELCOME10');
if (!$simple || !$precision || !$grouped || !$variable || !$small || !$large || !$coupon->get_id()) {
    throw new RuntimeException('WooCommerce native product/coupon fixture is incomplete');
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
    "SELECT attribute_id,attribute_label,attribute_name,attribute_orderby,attribute_public " .
    "FROM {$wpdb->prefix}woocommerce_attribute_taxonomies " .
    "WHERE attribute_name IN ('conf-color','conf-size') ORDER BY attribute_name",
    ARRAY_A
);
$lookup = $wpdb->get_row($wpdb->prepare(
    "SELECT product_id,sku,min_price,max_price FROM {$wpdb->prefix}wc_product_meta_lookup WHERE product_id=%d",
    $precisionId
), ARRAY_A);
$category = get_term_by('slug', 'conformance-widgets', 'product_cat');
$tag = get_term_by('slug', 'portable-tokyo', 'product_tag');
$shipping = get_term_by('slug', 'oversize-portable', 'product_shipping_class');
$thumbnailId = $category ? (int) get_term_meta($category->term_id, 'thumbnail_id', true) : 0;
$sourceOrders = wc_get_orders(['billing_email' => 'source-runtime@example.test', 'limit' => -1, 'return' => 'ids']);
$targetOrders = wc_get_orders(['billing_email' => 'target-runtime@example.test', 'limit' => -1, 'return' => 'ids']);

echo wp_json_encode([
    'attributes' => $attributeRows,
    'coupon' => [
        'amount' => $coupon->get_amount('edit'),
        'categories' => array_values($coupon->get_product_categories('edit')),
        'id' => $coupon->get_id(),
        'products' => array_values($coupon->get_product_ids('edit')),
        'status' => $coupon->get_status('edit'),
        'type' => $coupon->get_discount_type('edit'),
    ],
    'derived' => ['precision_lookup' => $lookup],
    'grouped' => ['children' => array_values($grouped->get_children('edit'))],
    'ids' => [
        'attribute_color' => (int) ($attributeRows[0]['attribute_id'] ?? 0),
        'attribute_size' => (int) ($attributeRows[1]['attribute_id'] ?? 0),
        'category' => $category ? (int) $category->term_id : 0,
        'coupon' => $coupon->get_id(),
        'flat_method' => (int) ($zoneMethods['flat_rate']['id'] ?? 0),
        'free_method' => (int) ($zoneMethods['free_shipping']['id'] ?? 0),
        'grouped' => $groupedId,
        'precision' => $precisionId,
        'product' => $simpleId,
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
        'neighbor' => get_option('duo_target_environment_neighbor', null),
        'paypal' => get_option('woocommerce_paypal_settings', null),
        'precision' => (string) get_option('woocommerce_price_num_decimals', ''),
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
    ],
    'shipping' => ['methods' => $zoneMethods, 'tax_class' => $taxClass, 'tax_rate' => $taxRate],
    'simple' => [
        'cross_sells' => array_values($simple->get_cross_sell_ids('edit')),
        'shipping_class' => $simple->get_shipping_class(),
        'tags' => $termSlugs($simpleId, 'product_tag'),
        'title' => $simple->get_name('edit'),
        'upsells' => array_values($simple->get_upsell_ids('edit')),
    ],
    'version' => defined('WC_VERSION') ? WC_VERSION : null,
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
$settings = (array) get_option("woocommerce_cod_settings", []);
$gateways = WC()->payment_gateways()->payment_gateways();
$cod = $gateways["cod"] ?? null;
echo wp_json_encode([
  "calc_taxes" => (string) get_option("woocommerce_calc_taxes", ""),
  "enabled" => (string) ($settings["enabled"] ?? ""),
  "title" => (string) ($settings["title"] ?? ""),
  "description" => (string) ($settings["description"] ?? ""),
  "instructions" => (string) ($settings["instructions"] ?? ""),
  "enable_for_methods" => array_values((array) ($settings["enable_for_methods"] ?? [])),
  "enable_for_virtual" => (string) ($settings["enable_for_virtual"] ?? ""),
  "gateway_enabled" => $cod ? (string) $cod->enabled : "missing",
  "gateway_title" => $cod ? (string) $cod->title : "missing",
]);
' 2>&1 | tail -1)
require_observed_nonempty "conf2 WooCommerce merchant-settings observation" "$MERCHANT_SETTINGS_OUT"
echo "conf2 merchant-settings check: $MERCHANT_SETTINGS_OUT"
echo "$MERCHANT_SETTINGS_OUT" | jq -e '
  .calc_taxes == "yes" and
  .enabled == "yes" and
  .title == "Conformance COD Desk" and
  .description == "Pay at the conformance desk." and
  .instructions == "Use code CONF-COD-7 at pickup." and
  .enable_for_methods == [] and
  .enable_for_virtual == "yes" and
  .gateway_enabled == "yes" and
  .gateway_title == "Conformance COD Desk"
' >/dev/null \
  || fail "conf2 merchant Woo settings did not round-trip through the option and COD gateway APIs (got: $MERCHANT_SETTINGS_OUT)"
pass "conf2 preserves authored tax enablement and distinctive COD settings through WooCommerce's option and gateway APIs"

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

# The automatic contract is intentionally bounded to products affected by
# this Apply. WooCommerce 11.0.0 exposes only a whole-catalog public rebuild
# for wc_category_lookup, so category lookup repair is an explicit manual
# boundary and is not claimed by this conformance run.
pass "bounded Woo price/product-meta/sale projections verified; attribute and category lookups remain explicit manual boundaries"

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
  .simple.title == "Conformance Widget" and
  .precision.title == "Conformance Precision Download 東京 🚀" and
  .precision.regular == "123456789.123456" and .precision.sale == "123456788.654321" and
  .precision.price == "123456788.654321" and .options.precision == "6" and
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
  .simple.tags == ["portable-tokyo"] and .simple.shipping_class == "oversize-portable" and
  .simple.upsells == [.ids.precision] and .simple.cross_sells == [.ids.grouped] and
  .grouped.children == [.ids.product,.ids.precision] and
  .coupon.status == "publish" and .coupon.type == "percent" and .coupon.amount == "10" and
  .coupon.products == [.ids.product] and .coupon.categories == [.ids.category] and
  (.attributes | map(select(.attribute_name == "conf-color" and .attribute_label == "Conf Color" and .attribute_orderby == "menu_order" and .attribute_public == "0")) | length) == 1 and
  (.attributes | map(select(.attribute_name == "conf-size" and .attribute_label == "Conf Size" and .attribute_orderby == "menu_order" and .attribute_public == "0")) | length) == 1 and
  (.derived.precision_lookup.min_price | tonumber) == 123456788.6543 and
  (.derived.precision_lookup.max_price | tonumber) == 123456788.6543 and
  .shipping.methods.flat_rate.cost == "5.99" and .shipping.methods.free_shipping.min_amount == "50.00" and
  .shipping.tax_class.slug == "conformance-reduced-rate" and .shipping.tax_rate.tax_rate == "7.2500" and
  .options.paypal.enabled == "yes" and
  .options.paypal.email == "target-paypal@example.test" and
  .options.paypal.receiver_email == "target-paypal@example.test" and
  .options.paypal.identity_token == "target-secret-token-preserved" and
  .options.neighbor == "target-neighbor-preserved" and
  .runtime == {"hpos":true,"source_orders":0,"source_queue":0,"source_sessions":0,"target_orders":1,"target_queue":1,"target_sessions":1} and
  (.ids | to_entries | all(.value > 2147483647))
' <<<"$TARGET" >/dev/null || fail "WooCommerce difficult values/native/runtime state did not converge: $TARGET"

for key in category tag shipping_class attribute_color attribute_size tax_class; do
  SOURCE_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$SOURCE_IDS")
  EXPECTED_TARGET_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$TARGET_IDS")
  OBSERVED_TARGET_ID=$(jq -r --arg key "$key" '.ids[$key]' <<<"$TARGET")
  require_fixture_ids SOURCE_ID EXPECTED_TARGET_ID OBSERVED_TARGET_ID
  [ "$SOURCE_ID" != "$OBSERVED_TARGET_ID" ] \
    || fail "WooCommerce source/target $key identity did not diverge ($SOURCE_ID)"
  [ "$EXPECTED_TARGET_ID" = "$OBSERVED_TARGET_ID" ] \
    || fail "WooCommerce apply replaced rather than adopted hostile target $key"
done
for key in product precision grouped variable coupon thumbnail variation_small variation_large zone flat_method free_method tax_rate; do
  SOURCE_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$SOURCE_IDS")
  OBSERVED_TARGET_ID=$(jq -r --arg key "$key" '.ids[$key]' <<<"$TARGET")
  require_fixture_ids SOURCE_ID OBSERVED_TARGET_ID
  [ "$SOURCE_ID" != "$OBSERVED_TARGET_ID" ] \
    || fail "WooCommerce generated target $key reused source-local identity $SOURCE_ID"
done
pass 'hostile terms and typed natural keys retain divergent >2^31 target identities; generated product identities and every nested reference resolve locally'
pass 'precision prices, long UTF-8, local attributes, tags, shipping class, grouped/upsell/cross-sell refs, and downloadable URLs round-trip through native APIs'

PROVIDER_RECEIPT="${APPLY_JSON:-}"
if [ -z "$PROVIDER_RECEIPT" ] && [ -n "${VMATRIX_APPLY_LOG:-}" ] && [ -f "$VMATRIX_APPLY_LOG" ]; then
  PROVIDER_RECEIPT=$(cat "$VMATRIX_APPLY_LOG")
fi
grep -Eq 'woocommerce-cache@1\.0\.0 invalidate_cache_groups .*verified' <<<"$PROVIDER_RECEIPT" \
  || fail "initial apply receipt omitted the verified WooCommerce cache provider: ${PROVIDER_RECEIPT:-<missing>}"
grep -Eq 'woocommerce-product-lookups@1\.0\.0 rebuild_product_lookups .*verified' <<<"$PROVIDER_RECEIPT" \
  || fail "initial apply receipt omitted the verified WooCommerce lookup provider: ${PROVIDER_RECEIPT:-<missing>}"
if jq -e 'type == "object"' <<<"$PROVIDER_RECEIPT" >/dev/null 2>&1; then
  jq -e '
    any(.actions[]?; .source == "provider:woocommerce-cache/invalidate_cache_groups" and .verified == true) and
    any(.actions[]?; .source == "provider:woocommerce-product-lookups/rebuild_product_lookups" and .verified == true)
  ' <<<"$PROVIDER_RECEIPT" >/dev/null \
    || fail 'WooCommerce provider JSON receipt omitted a closed verified action'
fi
pass 'both digest-bound WooCommerce providers identify themselves and return verified receipts'

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
