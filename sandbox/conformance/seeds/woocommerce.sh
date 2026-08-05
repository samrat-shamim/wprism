#!/usr/bin/env bash
# WooCommerce manifest conformance seed: one product category and one simple
# product exercising manifests/woocommerce.json's authored/runtime/derived
# split — regular + sale price, SKU, stock management, tax status, backorders
# (all authored) alongside _stock/_price/_wc_average_rating etc., which the
# manifest excludes as runtime/derived and this seed deliberately leaves for
# WooCommerce itself to populate (never touched here). Does not edit
# manifests/woocommerce.json — that manifest is owned by the Spike D work.
# Invoked by conformance/run.sh with wp_conf1/wp_conf2/$COMPOSE exported.
set -euo pipefail

CAT_ID=$(wp_conf1 term create product_cat "Conformance Widgets" --slug=conformance-widgets --porcelain)

PID=$(wp_conf1 wc product create --name='Conformance Widget' --type=simple \
  --regular_price=19.99 --sale_price=14.99 --sku=CONF-WIDGET-1 \
  --manage_stock=true --stock_quantity=25 --virtual=false \
  --tax_status=taxable --backorders=no --sold_individually=false \
  --status=publish --user=admin --porcelain)

wp_conf1 post term add "$PID" product_cat conformance-widgets --by=slug

echo "woocommerce seed: category=$CAT_ID product=$PID"
