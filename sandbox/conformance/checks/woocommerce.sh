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
grep -Eq 'woocommerce-product-lookups@2\.0\.0 rebuild_product_lookups .*verified' <<<"$PROVIDER_RECEIPT" \
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
      "\"woocommerce_calc_taxes\",\"woocommerce_cod_settings\",\"woocommerce_paypal_settings\"," .
      "\"woocommerce_price_num_decimals\",\"duo_target_environment_neighbor\") ORDER BY option_name",
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

# Corrupt structured product metadata, introduce a credential-shaped checkout
# setting, and remove one repository product row. Each independent capture must
# refuse before changing canonical state; exact raw restoration must recapture
# byte-identically.
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

COD_BACKUP=$(wp_conf1 eval '
  global $wpdb; echo base64_encode((string)$wpdb->get_var(
    "SELECT option_value FROM {$wpdb->options} WHERE option_name=\"woocommerce_cod_settings\""
  ));
')
require_observed_nonempty 'WooCommerce raw COD option backup' "$COD_BACKUP"
FAKE_SECRET='AKIAABCDEFGHIJKLMNOP'
wp_conf1 eval '
  $settings=(array)get_option("woocommerce_cod_settings",[]);
  $settings["instructions"]="AKIAABCDEFGHIJKLMNOP";
  update_option("woocommerce_cod_settings",$settings);
' >/dev/null
SECRET_RC=0
SECRET_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || SECRET_RC=$?
require_duo_answered 'WooCommerce credential-shaped checkout capture' human "$SECRET_OUT"
[ "$SECRET_RC" -ne 0 ] && grep -q 'secret guard tripped' <<<"$SECRET_OUT" \
  && ! grep -Fq "$FAKE_SECRET" <<<"$SECRET_OUT" \
  || fail "WooCommerce credential-shaped checkout setting did not refuse and redact: $SECRET_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$CAPTURE_BASELINE" ] \
  || fail 'WooCommerce secret refusal partially published canonical state'
wp_conf1 eval "
  global \$wpdb;
  \$wpdb->update(\$wpdb->options,['option_value'=>base64_decode('$COD_BACKUP')],['option_name'=>'woocommerce_cod_settings']);
  wp_cache_delete('woocommerce_cod_settings','options');
" >/dev/null

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
  || fail 'WooCommerce source did not restore byte-identically after malformed/secret/deletion probes'
rm -rf "$CONF_REPO1/.tmp-woocommerce-restored"
pass 'malformed attributes, credential-shaped checkout data, and unsupported product deletion refuse atomically and redact values'

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

# Two real processes race one new product title. At least one must succeed; a
# loser may only refuse at the named promotion lock, and the final native state
# and plan must be exact.
wp_conf1 eval '
  $product=wc_get_product(wc_get_product_id_by_sku("CONF-ADOPT-PRODUCT"));
  $product->set_name("Concurrent WooCommerce intent 東京 🚀"); $product->save();
' >/dev/null
commit_woocommerce_source 'conformance: concurrent WooCommerce apply intent'
CONCURRENT_A="$CONF_REPO2/.tmp-woocommerce-concurrent-a.log"
CONCURRENT_B="$CONF_REPO2/.tmp-woocommerce-concurrent-b.log"
set +e
wp_conf2 duo apply --repo=/siterepo --default-author=admin >"$CONCURRENT_A" 2>&1 & PID_A=$!
wp_conf2 duo apply --repo=/siterepo --default-author=admin >"$CONCURRENT_B" 2>&1 & PID_B=$!
wait "$PID_A"; RC_A=$?
wait "$PID_B"; RC_B=$?
set -e
if [ "$RC_A" -ne 0 ] && [ "$RC_B" -ne 0 ]; then
  fail "both competing WooCommerce applies failed: A=$(cat "$CONCURRENT_A") B=$(cat "$CONCURRENT_B")"
fi
for result in A B; do
  eval "rc=\$RC_$result"; eval "log=\$CONCURRENT_$result"
  if [ "$rc" -eq 0 ]; then
    grep -q 'canary clean' "$log" || fail "successful competing WooCommerce apply lacked a clean canary: $(cat "$log")"
  else
    grep -Eqi 'lock|another apply|in progress|promotion' "$log" \
      || fail "competing WooCommerce apply failed outside the named lock: $(cat "$log")"
  fi
done
rm -f "$CONCURRENT_A" "$CONCURRENT_B"
CONCURRENT=$(observe_woocommerce_adoption)
jq -e '.product.name == "Concurrent WooCommerce intent 東京 🚀"' <<<"$CONCURRENT" >/dev/null \
  || fail "competing WooCommerce applies lost repository intent: $CONCURRENT"
CONCURRENT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce plan after competing applies' json "$CONCURRENT_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$CONCURRENT_PLAN" >/dev/null \
  || fail "WooCommerce competing applies left retained work: $CONCURRENT_PLAN"
pass 'competing WooCommerce applies serialize and leave one exact idempotent result'

# Deactivation is repaired by deploy. Woo's default uninstall keeps catalog,
# typed configuration, lookup, approved-directory, and HPOS data unless the
# merchant explicitly enables destructive cleanup; absent code must refuse,
# then the digest-bound cached artifact must recover the retained state.
wp_conf2 plugin deactivate woocommerce >/dev/null
wp_conf2 plugin is-active woocommerce >/dev/null 2>&1 && fail 'WooCommerce deactivation premise did not land'
REACTIVATE=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce deploy after deactivation' json "$REACTIVATE"
wp_conf2 plugin is-active woocommerce >/dev/null || fail 'Duo deploy did not reactivate exact WooCommerce code'
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
WOO_SHA=ba08c7fc58c98a11f22866269c5832d85c52b664806ec206036f09737ba21666
WOO_ARTIFACT="/artifacts-cache/plugin-woocommerce-11.0.0-${WOO_SHA}.zip"
[ "$(wp_conf2 eval "echo hash_file('sha256','$WOO_ARTIFACT');")" = "$WOO_SHA" ] \
  || fail 'cached WooCommerce reinstall artifact digest moved'
wp_conf2 plugin install "$WOO_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get woocommerce --field=version)" = 11.0.0 ] \
  || fail 'WooCommerce exact reinstall reported the wrong version'
REINSTALL_DEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered 'WooCommerce deploy after exact reinstall' json "$REINSTALL_DEPLOY"
wp_conf2 plugin is-active woocommerce >/dev/null || fail 'WooCommerce exact reinstall was not active after deploy'
RECOVERED=$(observe_woocommerce_adoption)
jq -e --argjson ids "$TARGET_ADOPT" '
  .product.id == $ids.product and .product.name == "Concurrent WooCommerce intent 東京 🚀" and
  .product.regular == "55.123456" and .product.sale == "49.123456" and
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
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-woocommerce-final" \
  || fail 'WooCommerce final recovered state was not byte-identical'
rm -rf "$CONF_REPO2/.tmp-woocommerce-final"
pass 'deactivate/reactivate, retained-data uninstall, absent-code refusal, exact reinstall, optional-extension isolation, and final retry are clean'
