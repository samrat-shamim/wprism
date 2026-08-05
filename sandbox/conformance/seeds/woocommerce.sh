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
# Does not edit manifests/woocommerce.json — that manifest is owned by the
# Spike D work. Invoked by conformance/run.sh with wp_conf1/wp_conf2/$COMPOSE
# exported.
set -euo pipefail

CAT_ID=$(wp_conf1 term create product_cat "Conformance Widgets" --slug=conformance-widgets --porcelain)

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

echo "woocommerce seed: category=$CAT_ID product=$PID coupon=$COUPON_ID"
