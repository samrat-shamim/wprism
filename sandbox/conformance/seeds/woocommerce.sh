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
# taxonomy_patterns shipped (docs/grind/r1b-shop.md's original conformance-
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

CAT_ID=$(wp_conf1 term create product_cat "Conformance Widgets" --slug=conformance-widgets --porcelain)

# Real WooCommerce-authored product-category image. The stored termmeta is
# thumbnail_id -> attachment post ID, so this exercises termmeta ref
# tokenization and target-local resolution rather than opaque passthrough.
for side in conf1 conf2; do
  wp_env "$side" eval 'foreach (glob(wp_upload_dir()["basedir"] . "/*/*/conf-woo-category*.png") as $f) { unlink($f); }' >/dev/null
done
cat > "${CONF_REPO1:-siterepo/conf1}"/.tmp-make-woo-category-image.php <<'EOF'
<?php
$im = imagecreatetruecolor(48, 48);
imagefilledrectangle($im, 0, 0, 47, 47, imagecolorallocate($im, 115, 70, 175));
imagepng($im, '/tmp/conf-woo-category.png');
EOF
THUMB_ID=$($COMPOSE run --rm -T cli1 bash -c \
  "wp eval-file /siterepo/.tmp-make-woo-category-image.php >/dev/null && wp media import /tmp/conf-woo-category.png --title='Woo Category Thumbnail' --porcelain")
rm -f "${CONF_REPO1:-siterepo/conf1}"/.tmp-make-woo-category-image.php
wp_conf1 term meta update "$CAT_ID" thumbnail_id "$THUMB_ID" >/dev/null

PID=$(wp_conf1 wc product create --name='Conformance Widget' --type=simple \
  --regular_price=19.99 --sale_price=14.99 --sku=CONF-WIDGET-1 \
  --manage_stock=true --stock_quantity=25 --virtual=false \
  --tax_status=taxable --backorders=no --sold_individually=false \
  --status=publish --user=admin --porcelain)

wp_conf1 post term add "$PID" product_cat conformance-widgets --by=slug

COUPON_ID=$(wp_conf1 wc shop_coupon create --code=CONF-WELCOME10 \
  --discount_type=percent --amount=10 \
  --product_ids="$PID" --product_categories="$CAT_ID" \
  --usage_limit=50 --minimum_amount=10.00 --free_shipping=true \
  --date_expires=2027-06-30T00:00:00 \
  --status=publish --user=admin --porcelain)


# Variable product with global attributes (task #92): exercises
# taxonomy_patterns' zero-provisioning end-to-end as part of the ordinary
# conformance sweep, not just the dedicated r3e regression. Attribute ids
# are NOT hardcoded — a fresh conf1 mints them in creation order, so this
# reads them back rather than assuming 1/2 (conf's own reset cycle can
# leave a different starting id across repeated runs).
SIZE_ATTR_ID=$(wp_conf1 wc product_attribute create --name="Conf Size" --slug="conf-size" --type=select --order_by=menu_order --has_archives=false --porcelain --user=admin)
COLOR_ATTR_ID=$(wp_conf1 wc product_attribute create --name="Conf Color" --slug="conf-color" --type=select --order_by=menu_order --has_archives=false --porcelain --user=admin)
wp_conf1 wc product_attribute_term create "$SIZE_ATTR_ID" --name=Small --user=admin >/dev/null
wp_conf1 wc product_attribute_term create "$SIZE_ATTR_ID" --name=Large --user=admin >/dev/null
wp_conf1 wc product_attribute_term create "$COLOR_ATTR_ID" --name=Red --user=admin >/dev/null
wp_conf1 wc product_attribute_term create "$COLOR_ATTR_ID" --name=Blue --user=admin >/dev/null

VPID=$(wp_conf1 wc product create --name='Conformance Variable Widget' --type=variable \
  --attributes="[{\"id\":$SIZE_ATTR_ID,\"variation\":true,\"visible\":true,\"options\":[\"Small\",\"Large\"]},{\"id\":$COLOR_ATTR_ID,\"variation\":true,\"visible\":true,\"options\":[\"Red\",\"Blue\"]}]" \
  --status=publish --user=admin --porcelain)
wp_conf1 wc product_variation create "$VPID" \
  --attributes="[{\"id\":$SIZE_ATTR_ID,\"option\":\"Small\"},{\"id\":$COLOR_ATTR_ID,\"option\":\"Red\"}]" \
  --regular_price=9.99 --sku=CONF-VAR-S-RED --manage_stock=true --stock_quantity=10 --user=admin --porcelain >/dev/null
wp_conf1 wc product_variation create "$VPID" \
  --attributes="[{\"id\":$SIZE_ATTR_ID,\"option\":\"Large\"},{\"id\":$COLOR_ATTR_ID,\"option\":\"Blue\"}]" \
  --regular_price=12.99 --sku=CONF-VAR-L-BLUE --manage_stock=true --stock_quantity=8 --user=admin --porcelain >/dev/null

echo "woocommerce seed: category=$CAT_ID thumbnail=$THUMB_ID product=$PID coupon=$COUPON_ID variable_product=$VPID (attrs size=$SIZE_ATTR_ID color=$COLOR_ATTR_ID)"
