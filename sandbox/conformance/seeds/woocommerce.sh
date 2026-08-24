#!/usr/bin/env bash
# WooCommerce manifest conformance seed: one product category, one simple
# product, and one representative coupon exercising
# manifests/woocommerce.json's authored/runtime/derived split — regular +
# sale price, SKU, stock management, tax status, backorders (product) and
# discount type/amount, product+category restriction, usage limit, minimum
# amount, free shipping, and expiry (coupon) — all authored — alongside
# _stock/_price/_wc_average_rating/usage_count/_used_by etc., which the
# manifest excludes as runtime/derived and this seed deliberately leaves for
# WooCommerce itself to populate (never touched here).
#
# The coupon is never redeemed here (that A/B runtime-isolation scenario —
# usage_count/_used_by must not propagate a redemption across environments —
# belongs to spike_d_woo.sh's coupon section, not this single-environment
# seed) and is given a concrete date_expires rather than left unset: a
# non-expiring coupon's date_expires meta is a real, common WooCommerce
# shape (WooCommerce itself writes the postmeta row unconditionally, with an
# actual SQL NULL when no expiry is set) that Apply currently cannot write
# back (see manifests/woocommerce.json's date_expires note for the tracked
# gap). This seed stays clear of that separately-tracked issue so
# conformance keeps testing what it's for — manifest coverage against real
# WooCommerce releases — rather than re-tripping a known, already-reported
# engine bug on every run.
#
# A VARIABLE product with global attributes (pa_size/pa_color) is added
# below — task #92/#117's own recommendation, deliberately deferred until
# taxonomy_patterns shipped (grind round R1-B's original conformance-
# extension note flagged this needed a generic "pre-provision named global
# attributes" setup hook first; that recommendation is now OBSOLETE, not
# just unneeded — taxonomy_patterns + task #75's woocommerce_attribute_
# taxonomies typed-snapshot together make pa_size/pa_color travel with ZERO
# manual pre-provisioning on conf2, the same zero-provisioning proof already
# run live on the r3e pair for this task; conf2's manifests.json entry
# deliberately does NOT list pa_size/pa_color in "taxonomies" either — the
# whole point is that the pattern, not a hand-added exact name, is what
# puts them in scope). Does not edit manifests/woocommerce.json — that
# manifest is owned by the Spike D/task #92/#93 work. Invoked by
# conformance/run.sh with wp_conf1/wp_conf2/$COMPOSE exported.
set -euo pipefail

# Cross the signed-32-bit boundary on every identity family this fixture
# creates. WordPress and WooCommerce use BIGINT ids here; keeping all product,
# media, term, attribute, zone, method, tax, and tax-class references above
# 2^31 proves the adapter never narrows them through a 32-bit cast.
for table in \
  wp_posts wp_terms wp_term_taxonomy \
  wp_woocommerce_attribute_taxonomies wp_woocommerce_shipping_zones \
  wp_woocommerce_shipping_zone_locations wp_woocommerce_shipping_zone_methods \
  wp_wc_tax_rate_classes wp_woocommerce_tax_rates wp_woocommerce_tax_rate_locations
do
  wp_conf1 db query "ALTER TABLE $table AUTO_INCREMENT=2147484000" >/dev/null
done

CAT_PARENT_ID=$(wp_conf1 term create product_cat "Conformance Catalog" --slug=conformance-catalog --porcelain)
CAT_ID=$(wp_conf1 term create product_cat "Conformance Widgets" --slug=conformance-widgets --parent="$CAT_PARENT_ID" --porcelain)
TAG_ID=$(wp_conf1 term create product_tag "Portable 東京" --slug=portable-tokyo --porcelain)
SHIP_CLASS_ID=$(wp_conf1 term create product_shipping_class "Oversize Portable" --slug=oversize-portable --porcelain)

# Real WooCommerce-authored product-category image. The stored termmeta is
# thumbnail_id -> attachment post ID, so this exercises termmeta ref
# tokenization and target-local resolution rather than opaque passthrough.
for side in conf1 conf2; do
  wp_env "$side" eval 'foreach (glob(wp_upload_dir()["basedir"] . "/*/*/conf-woo-category*.png") as $f) { unlink($f); }' >/dev/null
done
cat > "${CONF_REPO1:-siterepo/conf1}"/.tmp-make-woo-category-image.php <<'EOF'
<?php
$im = imagecreatetruecolor(800, 800);
imagefilledrectangle($im, 0, 0, 799, 799, imagecolorallocate($im, 115, 70, 175));
imagepng($im, '/tmp/conf-woo-category.png');
EOF
THUMB_ID=$($COMPOSE run --rm -T cli1 bash -c \
  "wp eval-file /siterepo/.tmp-make-woo-category-image.php >/dev/null && wp media import /tmp/conf-woo-category.png --title='Woo Category Thumbnail' --porcelain")
rm -f "${CONF_REPO1:-siterepo/conf1}"/.tmp-make-woo-category-image.php
# DUO-3381: assert the premise before anything consumes it. `term meta update
# <id> thumbnail_id ""` succeeds silently, so an empty capture from a
# load-starved `docker compose run` (see the shared conformance/asserts.sh's require_fixture_ids) would
# author a termmeta ref that points nowhere — and checks/woocommerce.sh would
# then report "thumbnail_id did not resolve to a local attachment" as an
# engine failure.
require_fixture_ids CAT_PARENT_ID CAT_ID TAG_ID SHIP_CLASS_ID THUMB_ID

# Brands is unconditional core in both admitted artifacts. Build a hierarchy,
# exercise the inherited category-controller REST fields on the child, and use
# the same source attachment for category and brand thumbnails so both refs
# must converge to one target-local media identity.
BRAND_PARENT_ID=$(wp_conf1 term create product_brand 'Conformance Makers' --slug=conformance-makers --porcelain)
BRAND_CHILD_ID=$(wp_conf1 term create product_brand 'Atelier 東京' --slug=atelier-tokyo --parent="$BRAND_PARENT_ID" --description='<strong>Portable brand 東京</strong>' --porcelain)
BRAND_EXCLUDED_ID=$(wp_conf1 term create product_brand 'Excluded Merchant Brand' --slug=excluded-merchant-brand --porcelain)
require_fixture_ids BRAND_PARENT_ID BRAND_CHILD_ID BRAND_EXCLUDED_ID
wp_conf1 eval "
\$admin = get_user_by('login', 'admin');
wp_set_current_user(\$admin ? (int) \$admin->ID : 0);
\$update = static function (string \$route, array \$values): array {
    \$request = new WP_REST_Request('PUT', \$route);
    foreach (\$values as \$name => \$value) {
        \$request->set_param(\$name, \$value);
    }
    \$response = rest_do_request(\$request);
    if (\$response->is_error() || \$response->get_status() !== 200) {
        throw new RuntimeException('WooCommerce term REST update failed for ' . \$route);
    }
    return \$response->get_data();
};
\$category = \$update('/wc/v3/products/categories/$CAT_ID', [
    'display' => 'both',
    'menu_order' => 17,
    'parent' => $CAT_PARENT_ID,
    'image' => ['id' => $THUMB_ID],
]);
\$brand = \$update('/wc/v3/products/brands/$BRAND_CHILD_ID', [
    'display' => 'subcategories',
    'menu_order' => 23,
    'parent' => $BRAND_PARENT_ID,
    'image' => ['id' => $THUMB_ID],
]);
if ((int) (\$category['image']['id'] ?? 0) !== $THUMB_ID
    || (string) (\$category['display'] ?? '') !== 'both'
    || (int) (\$category['menu_order'] ?? -1) !== 17
    || (int) (\$brand['image']['id'] ?? 0) !== $THUMB_ID
    || (string) (\$brand['display'] ?? '') !== 'subcategories'
    || (int) (\$brand['menu_order'] ?? -1) !== 23) {
    throw new RuntimeException('WooCommerce category/brand REST update did not round-trip native fields');
}
" >/dev/null
wp_conf1 option update woocommerce_brand_permalink maker-houses >/dev/null
wp_conf1 option update wc_brands_show_description yes >/dev/null

PID=$(wp_conf1 wc product create --name='Conformance Widget' --type=simple \
  --regular_price=19.99 --sale_price=14.99 --date_on_sale_to=2030-01-01T00:00:00 --sku=CONF-WIDGET-1 \
  --manage_stock=true --stock_quantity=25 --virtual=false \
  --tax_status=taxable --backorders=no --sold_individually=false \
  --status=publish --user=admin --porcelain)
require_fixture_ids PID
wp_conf1 eval "
\$product = wc_get_product($PID);
if (!\$product) { throw new RuntimeException('missing seeded simple product'); }
\$product->set_image_id($THUMB_ID);
\$product->save();
" >/dev/null

wp_conf1 post term add "$PID" product_cat conformance-widgets --by=slug
wp_conf1 post term add "$PID" product_tag portable-tokyo --by=slug
wp_conf1 post term add "$PID" product_shipping_class oversize-portable --by=slug
wp_conf1 post term add "$PID" product_brand atelier-tokyo --by=slug

# Precision, long UTF-8, local-attribute, nested-download URL, and product-ref
# fixture. `_downloadable_files` is serialized structured data: the file URL
# deliberately names conf1 so the target assertion can prove recursive
# `{{home}}` rebinding rather than byte-identical source-host leakage.
wp_conf1 option update woocommerce_price_num_decimals 6 >/dev/null
# The store notice is ordinary Customizer-authored HTML, not launch/runtime
# state. Persist bytes that already equal the exact wp_kses_post writer and
# verify them later through Woo's site-wide native frontend renderer.
wp_conf1 option update woocommerce_demo_store yes >/dev/null
wp_conf1 eval "update_option('woocommerce_demo_store_notice', wp_kses_post('<strong>افتتاح المتجر 東京</strong><br>الشحن مجاني'));" >/dev/null
# Customer Review Request is optional target runtime, but its native host-page
# selection is merchant-authored content. Resolve the exact 11.0.x service,
# create the real shortcode page, then give it a non-default Unicode title and
# stable slug so capture must transport both the page and its option reference.
SOURCE_REVIEW_PAGE_ID=$(wp_conf1 eval '
update_option("woocommerce_feature_customer_review_request_enabled", "yes");
$endpoint = wc_get_container()->get(\Automattic\WooCommerce\Internal\OrderReviews\Endpoint::class);
$endpoint->maybe_create_host_page();
$page_id = (int) get_option("woocommerce_review_order_page_id", 0);
$page = $page_id > 0 ? get_post($page_id) : null;
if (!$page instanceof WP_Post || "page" !== $page->post_type || false === strpos((string) $page->post_content, "[woocommerce_review_order]")) {
    throw new RuntimeException("WooCommerce did not create its native Review Order host page");
}
$updated = wp_update_post([
    "ID" => $page_id,
    "post_title" => "استعراض الطلب 東京",
    "post_name" => "review-order-source",
], true);
if (is_wp_error($updated) || (int) $updated !== $page_id) {
    throw new RuntimeException("WooCommerce Review Order host-page update failed");
}
update_option("woocommerce_review_order_page_id", $page_id);
update_option("woocommerce_review_order_flush_rewrite_pending", "yes");
$endpoint->add_rewrite_rule();
$endpoint->maybe_flush_pending_rewrite();
if (false !== get_option("woocommerce_review_order_flush_rewrite_pending", false)) {
    throw new RuntimeException("WooCommerce Review Order rewrite marker survived native flush");
}
echo $page_id;
')
require_fixture_ids SOURCE_REVIEW_PAGE_ID
# Exact Woo 11.0.x Customizer-native thumbnail state. The background queue is
# deliberately not invoked here: checks/woocommerce.sh proves the always-on
# request path is sufficient when the target starts with stale 300px metadata.
wp_conf1 option update woocommerce_thumbnail_cropping custom >/dev/null
wp_conf1 option update woocommerce_thumbnail_cropping_custom_width 1 >/dev/null
wp_conf1 option update woocommerce_thumbnail_cropping_custom_height 1 >/dev/null
wp_conf1 option update woocommerce_thumbnail_image_width 500 >/dev/null
PRECISION_ID=$(wp_conf1 wc product create --name='Conformance Precision Download 東京 🚀' \
  --slug=conformance-precision-download --type=simple \
  --regular_price=123456789.123456 --sale_price=123456788.654321 \
  --sku=CONF-PRECISION-UTF8 --downloadable=true --virtual=true \
  --status=publish --user=admin --porcelain)
require_fixture_ids PRECISION_ID
wp_conf1 eval "
\$product = wc_get_product($PRECISION_ID);
\$attribute = new WC_Product_Attribute();
\$attribute->set_id(0);
\$attribute->set_name('Material 東京');
\$attribute->set_options(['Cotton', 'Wool', '麻 | literal delimiter']);
\$attribute->set_position(2147483647);
\$attribute->set_visible(true);
\$attribute->set_variation(false);
\$download = new WC_Product_Download();
\$download->set_id(md5('duo-woocommerce-portable-download'));
\$download->set_enabled(true);
\$download->set_name('Portable catalog 日本語 🚀.png');
\$download->set_file(wp_get_attachment_url($THUMB_ID) . '?download=1&label=' . rawurlencode('東京 🚀'));
\$admin = get_user_by('login', 'admin');
wp_set_current_user(\$admin ? (int) \$admin->ID : 0);
add_filter('woocommerce_downloadable_file_exists', '__return_true');
\$product->set_attributes([\$attribute]);
\$product->set_downloadable(true);
\$product->set_downloads([\$download]);
remove_filter('woocommerce_downloadable_file_exists', '__return_true');
\$product->set_purchase_note(str_repeat('Portable purchase note 東京 🚀 |%| {{literal}} — ', 128));
\$product->set_description(str_repeat('Long catalog body مرحبا こんにちは 🚀. ', 512));
\$product->set_category_ids([$CAT_ID]);
\$product->set_tag_ids([$TAG_ID]);
\$product->set_shipping_class_id($SHIP_CLASS_ID);
\$product->save();
" >/dev/null

# Core external products persist their merchant destination and call-to-action
# in `_product_url`/`_button_text`. Use the source home deliberately: capture
# must tokenize it and the target must expose only its own host through CRUD,
# REST, Store API, and the single-product form.
EXTERNAL_ID=$(wp_conf1 eval "
\$product = new WC_Product_External();
\$product->set_name('Conformance External Partner 東京');
\$product->set_slug('conformance-external-partner');
\$product->set_status('publish');
\$product->set_sku('CONF-EXTERNAL-1');
\$product->set_regular_price('88.88');
\$product->set_product_url(home_url('/partner/bootstrap'));
\$product->set_button_text('Bootstrap purchase');
\$product->set_description('Portable external catalog destination 東京 🚀');
\$product->set_image_id($THUMB_ID);
\$product->set_category_ids([$CAT_ID]);
echo \$product->save();
")
require_fixture_ids EXTERNAL_ID
wp_conf1 eval "
\$admin = get_user_by('login', 'admin');
wp_set_current_user(\$admin ? (int) \$admin->ID : 0);
\$request = new WP_REST_Request('PUT', '/wc/v3/products/$EXTERNAL_ID');
\$request->set_param('external_url', home_url('/partner/checkout?campaign=summer&locale=ja'));
\$request->set_param('button_text', 'اشتر الآن — 東京');
\$response = rest_do_request(\$request);
if (\$response->is_error()) {
    throw new RuntimeException('WooCommerce external-product REST update failed');
}
\$data = \$response->get_data();
if ((string) (\$data['external_url'] ?? '') !== home_url('/partner/checkout?campaign=summer&locale=ja')
    || (string) (\$data['button_text'] ?? '') !== 'اشتر الآن — 東京') {
    throw new RuntimeException('WooCommerce external-product REST update did not round-trip native values');
}
" >/dev/null
wp_conf1 post term add "$EXTERNAL_ID" product_tag portable-tokyo --by=slug

GROUPED_ID=$(wp_conf1 eval "
\$group = new WC_Product_Grouped();
\$group->set_name('Conformance Grouped Kit');
\$group->set_slug('conformance-grouped-kit');
\$group->set_status('publish');
\$group->set_sku('CONF-GROUPED-KIT');
\$group->set_children([$PID, $PRECISION_ID]);
\$id = \$group->save();
\$simple = wc_get_product($PID);
\$simple->set_upsell_ids([$PRECISION_ID]);
\$simple->set_cross_sell_ids([\$id]);
\$simple->save();
echo \$id;
")
require_fixture_ids GROUPED_ID

COUPON_ID=$(wp_conf1 wc shop_coupon create --code=CONF-WELCOME10 \
  --discount_type=percent --amount=10 \
  --product_ids="$PID" --product_categories="$CAT_ID" \
  --usage_limit=50 --minimum_amount=10.00 --free_shipping=true \
  --date_expires=2027-06-30T00:00:00 \
  --status=publish --user=admin --porcelain)
require_fixture_ids COUPON_ID
wp_conf1 eval "
update_post_meta($COUPON_ID, 'product_brands', [$BRAND_CHILD_ID]);
update_post_meta($COUPON_ID, 'exclude_product_brands', [$BRAND_EXCLUDED_ID]);
" >/dev/null


# Variable product with global attributes (task #92): exercises
# taxonomy_patterns' zero-provisioning end-to-end as part of the ordinary
# conformance sweep, not just the dedicated r3e regression. Attribute ids
# are NOT hardcoded — a fresh conf1 mints them in creation order, so this
# reads them back rather than assuming 1/2 (conf's own reset cycle can
# leave a different starting id across repeated runs).
wp_conf1 option update woocommerce_feature_wc_visual_attribute_enabled yes >/dev/null
SIZE_ATTR_ID=$(wp_conf1 wc product_attribute create --name="Conf Size" --slug="conf-size" --type=select --order_by=menu_order --has_archives=false --porcelain --user=admin)
COLOR_ATTR_ID=$(wp_conf1 wc product_attribute create --name="Conf Color" --slug="conf-color" --type=wc-visual --order_by=menu_order --has_archives=false --porcelain --user=admin)
require_fixture_ids COUPON_ID SIZE_ATTR_ID COLOR_ATTR_ID
wp_conf1 wc product_attribute_term create "$SIZE_ATTR_ID" --name=Small --user=admin >/dev/null
wp_conf1 wc product_attribute_term create "$SIZE_ATTR_ID" --name=Large --user=admin >/dev/null
wp_conf1 wc product_attribute_term create "$COLOR_ATTR_ID" --name=Red --user=admin >/dev/null
wp_conf1 wc product_attribute_term create "$COLOR_ATTR_ID" --name=Blue --user=admin >/dev/null

COLOR_TERM_IDS=$(wp_conf1 eval '
$red = get_term_by("slug", "red", "pa_conf-color");
$blue = get_term_by("slug", "blue", "pa_conf-color");
echo ($red ? (int) $red->term_id : 0) . "|" . ($blue ? (int) $blue->term_id : 0);
')
IFS='|' read -r COLOR_RED_ID COLOR_BLUE_ID <<<"$COLOR_TERM_IDS"
require_fixture_ids COLOR_RED_ID COLOR_BLUE_ID
wp_conf1 eval "
\Automattic\WooCommerce\Internal\ProductAttributes\VisualAttributeTermMeta::save_term_visual_from_request(
    $COLOR_RED_ID,
    'pa_conf-color',
    ['wc_visual_attribute_type' => 'color', 'term_color' => '#d92f2f']
);
\Automattic\WooCommerce\Internal\ProductAttributes\VisualAttributeTermMeta::save_term_visual_from_request(
    $COLOR_BLUE_ID,
    'pa_conf-color',
    ['wc_visual_attribute_type' => 'image', 'term_image' => '$THUMB_ID']
);
\$admin = get_user_by('login', 'admin');
wp_set_current_user(\$admin ? (int) \$admin->ID : 0);
foreach ([$COLOR_RED_ID => 7, $COLOR_BLUE_ID => 3] as \$termId => \$order) {
    \$request = new WP_REST_Request('PUT', '/wc/v3/products/attributes/$COLOR_ATTR_ID/terms/' . \$termId);
    \$request->set_param('menu_order', \$order);
    \$response = rest_do_request(\$request);
    \$data = \$response->get_data();
    if (\$response->is_error() || \$response->get_status() !== 200 || (int) (\$data['menu_order'] ?? -1) !== \$order) {
        throw new RuntimeException('WooCommerce visual attribute term REST order update failed');
    }
}
" >/dev/null

VPID=$(wp_conf1 wc product create --name='Conformance Variable Widget' --type=variable \
  --attributes="[{\"id\":$SIZE_ATTR_ID,\"variation\":true,\"visible\":true,\"options\":[\"Small\",\"Large\"]},{\"id\":$COLOR_ATTR_ID,\"variation\":true,\"visible\":true,\"options\":[\"Red\",\"Blue\"]}]" \
  --status=publish --user=admin --porcelain)
require_fixture_ids VPID
wp_conf1 wc product_variation create "$VPID" \
  --attributes="[{\"id\":$SIZE_ATTR_ID,\"option\":\"Small\"},{\"id\":$COLOR_ATTR_ID,\"option\":\"Red\"}]" \
  --regular_price=9.99 --sku=CONF-VAR-S-RED --manage_stock=true --stock_quantity=10 --user=admin --porcelain >/dev/null
wp_conf1 wc product_variation create "$VPID" \
  --attributes="[{\"id\":$SIZE_ATTR_ID,\"option\":\"Large\"},{\"id\":$COLOR_ATTR_ID,\"option\":\"Blue\"}]" \
  --regular_price=12.99 --sku=CONF-VAR-L-BLUE --manage_stock=true --stock_quantity=8 --user=admin --porcelain >/dev/null

# Direct scalar tax enablement is portable merchant state. Built-in gateway
# settings are plugin-managed records with mixed secrets/references and are
# exercised later as an atomic populated-source refusal, never in the success
# fixture.
wp_conf1 option update woocommerce_calc_taxes yes >/dev/null

# Exercise the exact native Settings REST route rather than manufacturing the
# serialized option bytes. ShippingController intentionally declares no
# `required` list: Woo completes the omitted tax status while consuming the
# record, and the target check proves that partial-record path in a fresh
# process. Location rows remain complete because the same controller and the
# shipping method index every row field directly.
wp_conf1 eval '
$admin = get_user_by("login", "admin");
if (!$admin) {
    throw new RuntimeException("Woo local-pickup REST seed requires the admin user");
}
wp_set_current_user((int) $admin->ID);
rest_get_server();
$request = new WP_REST_Request("POST", "/wp/v2/settings");
$settings = [
    "enabled" => "yes",
    "title" => "استلام 東京",
    "cost" => "-12.50",
];
$locations = [[
    "name" => "<strong>مخزن</strong> 東京",
    "address" => [
        "address_1" => "١٢ شارع الاختبار",
        "city" => "東京",
        "state" => "13",
        "postcode" => "100-0001",
        "country" => "JP",
    ],
    "details" => "<em>بوابة ٢</em><br>南口",
    "enabled" => true,
]];
$request->set_param("pickup_location_settings", $settings);
$request->set_param("pickup_locations", $locations);
$response = rest_do_request($request);
if ($response->is_error() || $response->get_status() !== 200) {
    throw new RuntimeException("Woo local-pickup Settings REST write failed");
}
$data = $response->get_data();
if (($data["pickup_location_settings"] ?? null) !== $settings
    || ($data["pickup_locations"] ?? null) !== $locations
    || get_option("woocommerce_pickup_location_settings") !== $settings
    || get_option("pickup_location_pickup_locations") !== $locations) {
    throw new RuntimeException("Woo local-pickup Settings REST round-trip changed native bytes");
}
' >/dev/null
ZONE_ID=$(wp_conf1 wc shipping_zone create --name='Conformance United States' --order=1 --user=admin --porcelain)
# WC_Shipping_Zone's constructor argument is optional, so an empty id here
# would silently construct (and save) a SECOND, unrelated zone rather than
# configure this one — checked before it is interpolated, not after.
require_fixture_ids ZONE_ID
wp_conf1 eval "\$z = new WC_Shipping_Zone($ZONE_ID); \$z->add_location('US', 'country'); \$z->save();" >/dev/null
FLAT_INSTANCE=$(wp_conf1 wc shipping_zone_method create "$ZONE_ID" --method_id=flat_rate --enabled=true --order=1 --user=admin --porcelain)
FREE_INSTANCE=$(wp_conf1 wc shipping_zone_method create "$ZONE_ID" --method_id=free_shipping --enabled=true --order=2 --user=admin --porcelain)
require_fixture_ids FLAT_INSTANCE FREE_INSTANCE
wp_conf1 eval "
\$flat = WC_Shipping_Zones::get_shipping_method($FLAT_INSTANCE);
\$flat->instance_settings['title'] = 'Conformance Flat Rate';
\$flat->instance_settings['cost'] = '5.99';
\$flat->instance_settings['tax_status'] = 'taxable';
update_option(\$flat->get_instance_option_key(), \$flat->instance_settings);
\$free = WC_Shipping_Zones::get_shipping_method($FREE_INSTANCE);
\$free->instance_settings['title'] = 'Conformance Free Shipping';
\$free->instance_settings['requires'] = 'min_amount';
\$free->instance_settings['min_amount'] = '50.00';
update_option(\$free->get_instance_option_key(), \$free->instance_settings);
" >/dev/null
wp_conf1 eval '
$result = WC_Tax::create_tax_class("Conformance Reduced Rate");
if (is_wp_error($result)) { throw new RuntimeException($result->get_error_message()); }
' >/dev/null
TAX_CLASS_ID=$(wp_conf1 db query "SELECT tax_rate_class_id FROM wp_wc_tax_rate_classes WHERE slug='conformance-reduced-rate'" --skip-column-names)
require_observed_nonempty "conf1 WooCommerce tax-class id" "$TAX_CLASS_ID"
TAX_ID=$(wp_conf1 wc tax create --country=US --state=CA --rate=7.2500 \
  --name='Conformance CA Sales Tax' --priority=1 --shipping=true --order=1 \
  --class=conformance-reduced-rate --porcelain --user=admin)

# HPOS orders are runtime by contract. A source-only order must therefore
# stay source-only after promotion; the target hook creates its own distinct
# runtime order so the check can prove apply preserves target runtime too.
SOURCE_ORDER_ID=$(wp_conf1 eval "
\$order = wc_create_order();
\$order->set_billing_email('source-runtime@example.test');
\$order->add_product(wc_get_product($PID), 1);
\$order->calculate_totals();
\$order->save();
echo \$order->get_id();
")
require_fixture_ids SOURCE_ORDER_ID

# Reviews, sessions, and arbitrary queued jobs are commerce runtime. Populate
# all three on the source so promotion evidence proves they do not leak to the
# target; the target hook creates independent session/queue counterparts whose
# survival is checked after apply.
SOURCE_REVIEW_ID=$(wp_conf1 comment create --comment_post_ID="$PID" \
  --comment_content='Source-only runtime review' --comment_author='Source Reviewer' \
  --comment_author_email='source-review@example.test' --comment_type=review \
  --comment_approved=1 --porcelain)
# The source-only order and review are premises for checks/woocommerce.sh's
# runtime-sovereignty assertions ("source order propagated", "Woo runtime
# sovereignty failed for reviews, sessions, or queues"): a row this seed
# never created would make those pass VACUOUSLY rather than fail, which is
# the quieter half of the same fixture-manufacture blind spot (DUO-3381).
require_fixture_ids TAX_ID SOURCE_ORDER_ID SOURCE_REVIEW_ID
wp_conf1 comment meta update "$SOURCE_REVIEW_ID" rating 5 >/dev/null
wp_conf1 eval '
global $wpdb;
$wpdb->replace($wpdb->prefix . "woocommerce_sessions", [
  "session_key" => "duo-source-runtime-session",
  "session_value" => "a:1:{s:5:\"probe\";s:6:\"source\";}",
  "session_expiry" => time() + 7200,
], ["%s", "%s", "%d"]);
as_schedule_single_action(time() + 7200, "duo_woo_source_runtime_probe", [], "duo-woo-runtime");
' >/dev/null

wp_conf1 eval "
file_put_contents('/siterepo/.tmp-woocommerce-source.json', wp_json_encode([
  'brand_child' => $BRAND_CHILD_ID,
  'brand_excluded' => $BRAND_EXCLUDED_ID,
  'brand_parent' => $BRAND_PARENT_ID,
  'category' => $CAT_ID,
  'category_parent' => $CAT_PARENT_ID,
  'color_blue' => $COLOR_BLUE_ID,
  'color_red' => $COLOR_RED_ID,
  'coupon' => $COUPON_ID,
  'external' => $EXTERNAL_ID,
  'grouped' => $GROUPED_ID,
  'precision' => $PRECISION_ID,
  'product' => $PID,
  'review_page' => $SOURCE_REVIEW_PAGE_ID,
  'shipping_class' => $SHIP_CLASS_ID,
  'tag' => $TAG_ID,
  'thumbnail' => $THUMB_ID,
  'variable' => $VPID,
  'variation_large' => wc_get_product_id_by_sku('CONF-VAR-L-BLUE'),
  'variation_small' => wc_get_product_id_by_sku('CONF-VAR-S-RED'),
  'attribute_color' => $COLOR_ATTR_ID,
  'attribute_size' => $SIZE_ATTR_ID,
  'zone' => $ZONE_ID,
  'flat_method' => $FLAT_INSTANCE,
  'free_method' => $FREE_INSTANCE,
  'tax_class' => $TAX_CLASS_ID,
  'tax_rate' => $TAX_ID,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
" >/dev/null

echo "woocommerce seed: category=$CAT_PARENT_ID/$CAT_ID brands=$BRAND_PARENT_ID/$BRAND_CHILD_ID/$BRAND_EXCLUDED_ID tag=$TAG_ID shipping_class=$SHIP_CLASS_ID thumbnail=$THUMB_ID product=$PID precision=$PRECISION_ID external=$EXTERNAL_ID grouped=$GROUPED_ID coupon=$COUPON_ID review_page=$SOURCE_REVIEW_PAGE_ID variable_product=$VPID (attrs size=$SIZE_ATTR_ID color=$COLOR_ATTR_ID terms=$COLOR_RED_ID,$COLOR_BLUE_ID) zone=$ZONE_ID methods=$FLAT_INSTANCE,$FREE_INSTANCE tax_class=$TAX_CLASS_ID tax=$TAX_ID source_runtime_order=$SOURCE_ORDER_ID source_runtime_review=$SOURCE_REVIEW_ID"
