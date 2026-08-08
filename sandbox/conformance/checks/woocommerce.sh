#!/usr/bin/env bash
# WooCommerce render/API-level acceptance (task #92): byte-identical
# canonical state alone doesn't prove pa_size/pa_color's zero-provisioning
# claim — this check confirms conf2's own WooCommerce runtime (a FRESH
# process, exactly the acceptance criterion's own wording) resolves
# wc_get_attribute_taxonomies() and the variable product's variations
# correctly, using WHATEVER local ids conf2 assigned them (never conf1's),
# with zero manual pre-provisioning step anywhere in run.sh's own flow —
# the same live proof already run on the dedicated r3e pair
# (sandbox/tests/regress_pa_attributes.sh), now folded into the ordinary
# conformance sweep.
#
# Invoked by conformance/run.sh after a clean apply, from the sandbox/
# directory; $COMPOSE/fail/pass are exported by run.sh itself (matching
# every other checks/*.sh file's convention — see checks/ninja-forms.sh).
set -euo pipefail

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

ORDER_OUT=$($COMPOSE run --rm -T cli2 wp eval '
$source = wc_get_orders(["billing_email" => "source-runtime@example.test", "limit" => -1, "return" => "ids"]);
$target = wc_get_orders(["billing_email" => "target-runtime@example.test", "limit" => -1, "return" => "ids"]);
echo "source=" . count($source) . "|target=" . count($target) . "|hpos=" . (get_option("woocommerce_custom_orders_table_enabled") === "yes" ? "yes" : "no");
' 2>&1 | tail -1)
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
$attrs = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wc_product_attributes_lookup WHERE product_or_parent_id=%d", $variable));
$cat = get_term_by("slug", "conformance-widgets", "product_cat");
$cat_lookup = $cat ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}wc_category_lookup WHERE category_id=%d", $cat->term_id)) : 0;
$sale = as_next_scheduled_action("wc_product_end_scheduled_sale", ["product_id" => $simple], "woocommerce-sales");
$empty_stock_cart = new WC_Cart();
$blocked_without_target_stock = !$empty_stock_cart->add_to_cart($simple, 1);
// Stock is runtime by contract, so establish target-local inventory without
// Woo product setters: those setters also bump authored post_modified bytes
// and would make the final recapture differ even though stock itself is
// correctly excluded. Keep the derived lookup in sync just as a stock write
// does, while leaving authored post state untouched.
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
  "attribute_rows" => $attrs,
  "category_rows" => $cat_lookup,
  "sale_scheduled" => $sale !== false,
  "cart_blocked_without_target_stock" => $blocked_without_target_stock,
  "cart_after_target_stock" => $simple_added && $variation_added && $cart->get_cart_contents_count() === 2,
]);
' 2>&1 | tail -1)
echo "conf2 Woo projection/cart check: $PROJECTION_OUT"
jq -e '
  .simple_price == "14.99" and .small_price == "9.99" and .large_price == "12.99" and
  .parent_prices == ["9.99", "12.99"] and
  (.lookup.min_price | tonumber) == 9.99 and (.lookup.max_price | tonumber) == 12.99 and
  .attribute_rows >= 4 and .category_rows >= 1 and .sale_scheduled == true and
  .cart_blocked_without_target_stock == true and .cart_after_target_stock == true
' <<<"$PROJECTION_OUT" >/dev/null \
  || fail "price/lookup/attribute/category/scheduling/cart projection is stale (got: $PROJECTION_OUT)"
pass "price/filter/schedule projections are current; cart refuses absent source inventory then accepts target-local simple+variation stock"

FILTER_OUT=$(curl -fsSG "http://localhost:${CONF2_PORT}/wp-json/wc/store/v1/products" \
  --data-urlencode 'attributes[0][attribute]=pa_conf-size' \
  --data-urlencode 'attributes[0][slug]=small') \
  || fail "conf2 Store API attribute-filter request failed"
jq -e 'length >= 1 and any(.[]; .name == "Conformance Variable Widget" and .is_purchasable == true)' <<<"$FILTER_OUT" >/dev/null \
  || fail "Store API filtering/catalog visibility did not return the purchasable variable product"
pass "Store API attribute filtering returns the visible, purchasable variable catalog product"

# Corrupt every adapter-owned projection, then run the same rebuilder Apply
# invokes. The verifier inside rebuild() must refuse unless all four surfaces
# are reconstructed from authored inputs.
$COMPOSE run --rm -T cli2 wp eval '
global $wpdb;
$simple = wc_get_product_id_by_sku("CONF-WIDGET-1");
$variable = (int) get_post_field("post_parent", wc_get_product_id_by_sku("CONF-VAR-S-RED"));
$wpdb->query($wpdb->prepare("UPDATE {$wpdb->postmeta} SET meta_value=9999 WHERE post_id IN (%d,%d) AND meta_key=\"_price\"", $simple, $variable));
$wpdb->query("DELETE FROM {$wpdb->prefix}wc_product_meta_lookup");
$wpdb->query("DELETE FROM {$wpdb->prefix}wc_product_attributes_lookup");
$wpdb->query("DELETE FROM {$wpdb->prefix}wc_category_lookup");
' >/dev/null
REPAIR_OUT=$($COMPOSE run --rm -T cli2 wp eval 'echo wp_json_encode(\Duo\WooCommerceContract::rebuild());' 2>&1 | tail -1)
echo "conf2 deliberate projection repair: $REPAIR_OUT"
jq -e '.products >= 4 and .parents >= 1' <<<"$REPAIR_OUT" >/dev/null \
  || fail "Woo projection rebuilder did not repair deliberate corruption (got: $REPAIR_OUT)"
pass "checked Woo rebuilder repairs deliberate price and lookup corruption"

FRONT=$(curl -fsSL "http://localhost:${CONF2_PORT}/product/conformance-widget/") \
  || fail "conf2 Conformance Widget page did not return 200"
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
