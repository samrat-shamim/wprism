#!/usr/bin/env bash
# WooCommerce target-only runtime fixture. Deployment has activated the
# plugin, but apply has not run yet. Enable HPOS first, then author one order
# that canonical state must neither delete nor replace with conf1's order.
set -euo pipefail

# Manufacture divergent, >2^31 target identities before apply. Posts/terms
# use same native slugs and typed rows use the same natural keys, but every
# value is hostile; `--adopt-by-slug` and typed-table identity must converge
# them without ever copying source-local integers.
for table in \
  wp_posts wp_terms wp_term_taxonomy \
  wp_woocommerce_attribute_taxonomies wp_woocommerce_shipping_zones \
  wp_woocommerce_shipping_zone_locations wp_woocommerce_shipping_zone_methods \
  wp_wc_tax_rate_classes wp_woocommerce_tax_rates wp_woocommerce_tax_rate_locations
do
  wp_conf2 db query "ALTER TABLE $table AUTO_INCREMENT=3147484000" >/dev/null
done

TARGET_CAT_PARENT_ID=$(wp_conf2 term create product_cat 'Hostile target catalog parent' --slug=conformance-catalog --porcelain)
TARGET_CAT_ID=$(wp_conf2 term create product_cat 'Hostile target category' --slug=conformance-widgets --porcelain)
TARGET_TAG_ID=$(wp_conf2 term create product_tag 'Hostile target tag' --slug=portable-tokyo --porcelain)
TARGET_SHIP_CLASS_ID=$(wp_conf2 term create product_shipping_class 'Hostile target class' --slug=oversize-portable --porcelain)
TARGET_BRAND_PARENT_ID=$(wp_conf2 term create product_brand 'Hostile target maker parent' --slug=conformance-makers --porcelain)
TARGET_BRAND_EXCLUDED_ID=$(wp_conf2 term create product_brand 'Hostile target excluded brand' --slug=excluded-merchant-brand --porcelain)
TARGET_BRAND_CHILD_ID=$(wp_conf2 term create product_brand 'Hostile target atelier' --slug=atelier-tokyo --parent="$TARGET_BRAND_EXCLUDED_ID" --porcelain)
TARGET_COLOR_ATTR_ID=$(wp_conf2 wc product_attribute create --name='Hostile Color' --slug=conf-color --type=select --order_by=name --has_archives=true --porcelain --user=admin)
TARGET_SIZE_ATTR_ID=$(wp_conf2 wc product_attribute create --name='Hostile Size' --slug=conf-size --type=select --order_by=name --has_archives=true --porcelain --user=admin)
require_fixture_ids TARGET_CAT_PARENT_ID TARGET_CAT_ID TARGET_TAG_ID TARGET_SHIP_CLASS_ID \
  TARGET_BRAND_PARENT_ID TARGET_BRAND_CHILD_ID TARGET_BRAND_EXCLUDED_ID \
  TARGET_COLOR_ATTR_ID TARGET_SIZE_ATTR_ID
wp_conf2 wc product_attribute_term create "$TARGET_SIZE_ATTR_ID" --name=Small --user=admin >/dev/null
wp_conf2 wc product_attribute_term create "$TARGET_SIZE_ATTR_ID" --name=Large --user=admin >/dev/null
wp_conf2 wc product_attribute_term create "$TARGET_COLOR_ATTR_ID" --name=Red --user=admin >/dev/null
wp_conf2 wc product_attribute_term create "$TARGET_COLOR_ATTR_ID" --name=Blue --user=admin >/dev/null
TARGET_COLOR_TERM_IDS=$(wp_conf2 eval '
$red = get_term_by("slug", "red", "pa_conf-color");
$blue = get_term_by("slug", "blue", "pa_conf-color");
echo ($red ? (int) $red->term_id : 0) . "|" . ($blue ? (int) $blue->term_id : 0);
')
IFS='|' read -r TARGET_COLOR_RED_ID TARGET_COLOR_BLUE_ID <<<"$TARGET_COLOR_TERM_IDS"
require_fixture_ids TARGET_COLOR_RED_ID TARGET_COLOR_BLUE_ID

# Hostile authored rows and derived caches are deliberately internally
# inconsistent. Apply must converge the portable REST state and the fresh
# hierarchy provider must replace—not bless—the equal-cardinality stale maps.
wp_conf2 eval "
update_term_meta($TARGET_CAT_ID, 'display_type', 'products');
update_term_meta($TARGET_CAT_ID, 'order', '1');
update_term_meta($TARGET_CAT_ID, 'thumbnail_id', '9999999999');
update_term_meta($TARGET_BRAND_CHILD_ID, 'display_type', 'products');
update_term_meta($TARGET_BRAND_CHILD_ID, 'order', '1');
update_term_meta($TARGET_BRAND_CHILD_ID, 'thumbnail_id', '9999999998');
update_term_meta($TARGET_COLOR_RED_ID, 'image', '9999999997');
update_term_meta($TARGET_COLOR_RED_ID, 'order', '2');
update_term_meta($TARGET_COLOR_BLUE_ID, 'color', '#000000');
update_term_meta($TARGET_COLOR_BLUE_ID, 'order', '8');
update_option('product_cat_children', [$TARGET_CAT_ID => [$TARGET_CAT_PARENT_ID]]);
update_option('product_brand_children', [$TARGET_BRAND_EXCLUDED_ID => [$TARGET_BRAND_CHILD_ID]]);
update_option('rewrite_rules', ['^hostile-brand/(.+)$' => 'index.php?hostile_brand=\$matches[1]']);
" >/dev/null
wp_conf2 option update woocommerce_brand_permalink hostile-brand >/dev/null
wp_conf2 option update wc_brands_show_description no >/dev/null
wp_conf2 option update woocommerce_feature_wc_visual_attribute_enabled no >/dev/null

# Product/coupon same-slug adoption is exercised after the initial canonical
# round trip in checks/woocommerce.sh. An adopted product update legitimately
# receives a target-local derived post_modified value, so manufacturing that
# collision here would make run.sh's earlier byte-diff compare a declared
# derived timestamp as if it were authored state.

wp_conf2 eval '
$result = WC_Tax::create_tax_class("Conformance Reduced Rate");
if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
' >/dev/null
TARGET_TAX_CLASS_ID=$(wp_conf2 db query "SELECT tax_rate_class_id FROM wp_wc_tax_rate_classes WHERE slug='conformance-reduced-rate'" --skip-column-names | tr -d '[:space:]')
require_fixture_ids TARGET_TAX_CLASS_ID

wp_conf2 option update woocommerce_paypal_settings --format=json \
  '{"enabled":"yes","email":"target-paypal@example.test","identity_token":"target-secret-token-preserved"}' >/dev/null
wp_conf2 option update duo_target_environment_neighbor 'target-neighbor-preserved' >/dev/null
wp_conf2 option update woocommerce_thumbnail_cropping '1:1' >/dev/null
wp_conf2 option update woocommerce_thumbnail_cropping_custom_width 4 >/dev/null
wp_conf2 option update woocommerce_thumbnail_cropping_custom_height 3 >/dev/null
wp_conf2 option update woocommerce_thumbnail_image_width 300 >/dev/null
wp_conf2 option update woocommerce_maybe_regenerate_images_hash 'target-thumbnail-runtime-hash' >/dev/null

wp_conf2 eval "
file_put_contents('/siterepo/.tmp-woocommerce-target.json', wp_json_encode([
  'brand_child' => $TARGET_BRAND_CHILD_ID,
  'brand_excluded' => $TARGET_BRAND_EXCLUDED_ID,
  'brand_parent' => $TARGET_BRAND_PARENT_ID,
  'category' => $TARGET_CAT_ID,
  'category_parent' => $TARGET_CAT_PARENT_ID,
  'color_blue' => $TARGET_COLOR_BLUE_ID,
  'color_red' => $TARGET_COLOR_RED_ID,
  'shipping_class' => $TARGET_SHIP_CLASS_ID,
  'tag' => $TARGET_TAG_ID,
  'attribute_color' => $TARGET_COLOR_ATTR_ID,
  'attribute_size' => $TARGET_SIZE_ATTR_ID,
  'tax_class' => $TARGET_TAX_CLASS_ID,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
" >/dev/null

wp_conf2 wc hpos enable >/dev/null
TARGET_ORDER_ID=$(wp_conf2 eval '
$existing = wc_get_orders(["billing_email" => "target-runtime@example.test", "limit" => 1, "return" => "ids"]);
if ($existing) { echo (int) $existing[0]; return; }
$order = wc_create_order();
$order->set_billing_email("target-runtime@example.test");
$order->calculate_totals();
$order->save();
echo $order->get_id();
')
# DUO-3381: the premise, asserted before the behavior. This order IS the
# fixture checks/woocommerce.sh's "target order disappeared" assertion is
# about — if the eval above silently hands back nothing (a `docker compose
# run` starved under host load, never a non-zero exit), that check fails
# later with an accusation against apply for a row this hook never created.
require_fixture_ids TARGET_ORDER_ID
# Keep the compatibility copy current so run.sh's generic HPOS setup gate can
# re-run its preflight successfully. The authoritative order remains in HPOS.
wp_conf2 wc hpos sync >/dev/null
# Same discipline for the session/queue rows, read back through the same
# tables checks/woocommerce.sh counts, inside the SAME eval (no extra
# container run) — their assertion ("Woo runtime sovereignty failed for
# reviews, sessions, or queues") is equally unable to tell an unwritten
# fixture from a target row that apply wrongly removed.
TARGET_RUNTIME=$(wp_conf2 eval '
global $wpdb;
$wpdb->replace($wpdb->prefix . "woocommerce_sessions", [
  "session_key" => "duo-target-runtime-session",
  "session_value" => "a:1:{s:5:\"probe\";s:6:\"target\";}",
  "session_expiry" => time() + 7200,
], ["%s", "%s", "%d"]);
as_schedule_single_action(time() + 7200, "duo_woo_target_runtime_probe", [], "duo-woo-runtime");
echo (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}woocommerce_sessions WHERE session_key=\"duo-target-runtime-session\"")
  . "|" . (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}actionscheduler_actions WHERE hook=\"duo_woo_target_runtime_probe\"");
')
require_fixture_state "conf2's target-only runtime session/queue rows" "1|1" "$TARGET_RUNTIME"
echo "woocommerce postdeploy: target_runtime_order=$TARGET_ORDER_ID (HPOS enabled and compatibility-synced before apply)"
