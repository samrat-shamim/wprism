#!/usr/bin/env bash
# Spike D — WooCommerce authored round-trip + referential delete guard:
#   a product catalog (meta/terms/media refs, string/csv cast variants) round-
#   trips A -> B byte-for-byte; runtime meta (_stock) is never reconciled; a
#   manifest delete_guard blocks removing a product an order references until
#   explicitly forced, then applies loudly.
set -euo pipefail
cd "$(dirname "$0")/.."
COMPOSE="docker compose -f docker-compose.yml"
wp_a() { $COMPOSE run --rm -T cli-a wp "$@"; }
wp_b() { $COMPOSE run --rm -T cli-b wp "$@"; }
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
GIT_A="git -C siterepo/a -c user.name=duo-a -c user.email=a@example.test"
GIT_B="git -C siterepo/b -c user.name=duo-b -c user.email=b@example.test"

[ -d siterepo/b/.git ] || fail "run spike A/B first (make spike-a spike-b)"

say "install WooCommerce on A and B"
wp_a plugin is-installed woocommerce || wp_a plugin install woocommerce
wp_a plugin activate woocommerce >/dev/null 2>&1 || true
wp_b plugin is-installed woocommerce || wp_b plugin install woocommerce
wp_b plugin activate woocommerce >/dev/null 2>&1 || true
pass "WooCommerce active on A and B"

say "enable HPOS on A and B (must run before any order exists)"
wp_a wc hpos enable || fail "could not enable HPOS on A"
wp_b wc hpos enable || fail "could not enable HPOS on B"
pass "HPOS (custom order tables) enabled on both"

say "policy change as a repo commit: pin the woocommerce manifest, own the catalog"
# product_visibility is deliberately NOT added — see manifests/woocommerce.json
# "notes": its terms are partly stock/rating-derived and WooCommerce maintains
# them itself independent of duo.
cat > siterepo/a/site.duo.json <<'EOF'
{
  "manifests": ["core", "woocommerce"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product"],
    "taxonomies": ["category", "post_tag", "product_cat", "product_tag", "product_type"]
  },
  "spec_version": 0
}
EOF
$GIT_A add -A && $GIT_A commit -qm "policy: manage the WooCommerce catalog" && $GIT_A push -q origin main
$GIT_B checkout -q main && $GIT_B pull -q origin main
pass "policy committed on A, pulled on B"

say "seed the catalog on A: a category + 3 virtual products exercising every ref/cast variant"
CAT_ID=$(wp_a term create product_cat Gadgets --slug=gadgets --porcelain)

WIDGET_A=$(wp_a wc product create \
  --name='Duo Widget' --slug=duo-widget --type=simple --status=publish --virtual=true \
  --sku=DUO-WIDGET --regular_price=19.99 --manage_stock=true --stock_quantity=5 \
  --purchase_note='Thanks for buying a widget!' --tax_status=taxable --user=admin --porcelain)

GIZMO_A=$(wp_a wc product create \
  --name='Duo Gizmo' --slug=duo-gizmo --type=simple --status=publish --virtual=true \
  --sku=DUO-GIZMO --regular_price=29.99 --sale_price=24.99 \
  --date_on_sale_from=2026-08-01T00:00:00 --date_on_sale_to=2026-12-31T00:00:00 \
  --tax_status=taxable --user=admin --porcelain)

GADGET_A=$(wp_a wc product create \
  --name='Duo Gadget' --slug=duo-gadget --type=simple --status=publish --virtual=true \
  --sku=DUO-GADGET --regular_price=39.99 --categories="[{\"id\":$CAT_ID}]" \
  --downloadable=true --download_limit=3 --download_expiry=30 --user=admin --porcelain)

# Fields the bundled wc-cli `product create` doesn't reliably expose (dimensions/
# weight silently no-op through it on this WC version) or has no flag for at all
# (cross-refs, GTIN, low-stock override): set directly so every classified key
# in the manifest actually has live data to round-trip.
wp_a eval "
update_post_meta($WIDGET_A, '_weight', '0.5');
update_post_meta($WIDGET_A, '_length', '10');
update_post_meta($WIDGET_A, '_width', '5');
update_post_meta($WIDGET_A, '_height', '2');
update_post_meta($WIDGET_A, '_low_stock_amount', '2');
update_post_meta($GIZMO_A, '_crosssell_ids', array('$WIDGET_A'));
update_post_meta($GIZMO_A, '_upsell_ids', array('$GADGET_A'));
update_post_meta($GIZMO_A, '_global_unique_id', '0012345678905');
echo \"meta set\n\";
" >/dev/null

# product image gallery (ref post[] cast csv) + thumbnail (ref post, core manifest) on Gadget
cat > siterepo/a/.tmp-makeimg.php <<'IMGEOF'
<?php
$im1 = imagecreatetruecolor(64, 64);
imagefilledrectangle($im1, 0, 0, 63, 63, imagecolorallocate($im1, 200, 60, 60));
imagepng($im1, '/tmp/duo-gadget-1.png');
$im2 = imagecreatetruecolor(64, 64);
imagefilledrectangle($im2, 0, 0, 63, 63, imagecolorallocate($im2, 60, 200, 60));
imagepng($im2, '/tmp/duo-gadget-2.png');
echo "made\n";
IMGEOF
IMG_IDS=$($COMPOSE run --rm -T cli-a bash -c "
  wp eval-file /siterepo/.tmp-makeimg.php >/dev/null &&
  wp media import /tmp/duo-gadget-1.png --title='Duo Gadget shot 1' --alt='Duo Gadget red' --porcelain &&
  wp media import /tmp/duo-gadget-2.png --title='Duo Gadget shot 2' --alt='Duo Gadget green' --porcelain
")
rm -f siterepo/a/.tmp-makeimg.php
IMG1=$(echo "$IMG_IDS" | sed -n 1p)
IMG2=$(echo "$IMG_IDS" | sed -n 2p)
wp_a post meta update "$GADGET_A" _thumbnail_id "$IMG1" >/dev/null
wp_a post meta update "$GADGET_A" _product_image_gallery "$IMG2" >/dev/null
pass "seeded (cat=$CAT_ID widget=$WIDGET_A gizmo=$GIZMO_A gadget=$GADGET_A imgs=$IMG1,$IMG2)"

say "capture A into the site repo"
wp_a duo capture --repo=/siterepo
$GIT_A add -A && $GIT_A commit -qm "capture: seed WooCommerce catalog on A (3 products, category, gallery, cross-refs)" && $GIT_A push -q origin main

say "acceptance: capture is deterministic (capture twice, zero diff)"
wp_a duo capture --repo=/siterepo --out=/siterepo/.tmp-state2 >/dev/null
diff -r siterepo/a/state siterepo/a/.tmp-state2 || fail "capture is not deterministic"
rm -rf siterepo/a/.tmp-state2
pass "capture-twice diff is empty"

say "round-trip: pull on B, adopt WooCommerce's own installer entities, apply"
# WooCommerce's OWN installer independently creates default Shop/Cart/Checkout/
# My-Account/Refund-Returns pages, a placeholder attachment, and product_type/
# product_cat default terms on every activation -- both A and B got their own
# copies. All of these collide by slug and need adoption, not just the
# product_type/product_cat terms the round-trip narrative anticipates, hence
# "posts" alongside "terms" here. Adopting them also makes the woocommerce_
# *_page_id options (ref-typed, pointing at those pages) resolve to the same
# identity on both sides -- but plan's hash-based 3-way can't know that in
# advance, so it (correctly) sees options/core.json as a genuine conflict
# (both sides' installers wrote different page-id refs from a common base).
# --force-theirs is the right, safe call: every other option in that file is
# already byte-identical between A and B.
$GIT_B checkout -q main && $GIT_B pull -q origin main
REV=$(git -C siterepo/b rev-parse HEAD)
wp_b duo apply --repo=/siterepo --adopt-by-slug=terms,posts --force-theirs --default-author=admin --revision="$REV"

say "acceptance: canonical(B) == canonical(A), byte for byte"
wp_b duo capture --repo=/siterepo --out=/siterepo/.tmp-dstate >/dev/null
diff -r siterepo/a/state siterepo/b/.tmp-dstate || fail "round-trip mismatch between A and B"
rm -rf siterepo/b/.tmp-dstate
pass "canonical state identical across environments"

say "acceptance: product data + cast-typed refs resolved correctly on B"
WIDGET_B=$(wp_b post list --post_type=product --name=duo-widget --field=ID)
GIZMO_B=$(wp_b post list --post_type=product --name=duo-gizmo --field=ID)
GADGET_B=$(wp_b post list --post_type=product --name=duo-gadget --field=ID)
[ "$(wp_b wc product get "$WIDGET_B" --field=price --user=admin)" = "19.99" ] || fail "widget price wrong on B"
[ "$(wp_b wc product get "$GADGET_B" --field=sku --user=admin)" = "DUO-GADGET" ] || fail "gadget sku wrong on B"
# _product_image_gallery (ref post[] cast csv) resolved to a real attachment
GALLERY_OK=$(wp_b eval "echo get_post_type((int) get_post_meta($GADGET_B, '_product_image_gallery', true));")
[ "$GALLERY_OK" = "attachment" ] || fail "gadget's image gallery did not resolve to a real attachment on B"
# _upsell_ids (ref post[] cast string) resolved to Gadget's B-local id
UPSELL_OK=$(wp_b eval "
\$ids = get_post_meta($GIZMO_B, '_upsell_ids', true);
echo (is_array(\$ids) && in_array('$GADGET_B', \$ids, true)) ? 'ok' : 'bad';
")
[ "$UPSELL_OK" = "ok" ] || fail "gizmo's upsell_ids did not resolve to gadget's B-local id"
WIDGET_URL=$(wp_b eval "echo get_permalink($WIDGET_B);")
# Buffer the body into a variable before grepping it: under `pipefail`,
# `curl | grep -q` can fail spuriously even on a real match — grep -q exits
# the instant it finds 'Duo Widget', and if that's near the top of the page
# curl is still writing the rest when the pipe closes, dies with EPIPE (23),
# and pipefail fails the pipeline despite the match having succeeded.
WIDGET_BODY=$(curl -fs "$WIDGET_URL") || fail "product page did not return 200 on B"
grep -q 'Duo Widget' <<<"$WIDGET_BODY" || fail "product page does not render on B"
pass "prices/SKUs correct; csv and string cast refs resolved; product page renders"

say "acceptance: runtime _stock is never reconciled"
wp_b wc product update "$WIDGET_B" --stock_quantity=2 --user=admin >/dev/null
APPLIED=$(wp_b duo apply --repo=/siterepo --default-author=admin --json | tail -1 | jq -r '.applied')
[ "$APPLIED" = "0" ] || fail "re-apply touched $APPLIED entities, expected 0"
[ "$(wp_b wc product get "$WIDGET_B" --field=stock_quantity --user=admin)" = "2" ] || fail "B-local stock was clobbered by apply"
pass "re-apply is a no-op; B's local stock edit survived"

say "referential guard: place an order on B against the stocked product"
ORDER_B=$(wp_b eval "
\$order = wc_create_order();
\$product = wc_get_product($WIDGET_B);
\$order->add_product(\$product, 1);
\$order->calculate_totals();
\$order->set_status('processing');
\$order->save();
echo \$order->get_id();
")
# WooCommerce syncs analytics lookup tables via an Action Scheduler async job
# -- NOT synchronously on save(), and not even immediately queued: it debounces
# a few seconds into the future (observed: order-save time + 5s) rather than
# scheduling for "now", so calling the runner right after save() can find
# nothing due yet. Poll instead of a single fixed sleep, since the exact
# debounce is a WooCommerce implementation detail, not a documented constant.
LOOKUP_ROWS=0
for _ in $(seq 1 8); do
  wp_b action-scheduler run >/dev/null
  LOOKUP_ROWS=$(wp_b db query "SELECT COUNT(*) FROM wp_wc_order_product_lookup WHERE product_id=$WIDGET_B" --skip-column-names)
  [ "$LOOKUP_ROWS" -ge "1" ] && break
  sleep 2
done
[ "$LOOKUP_ROWS" -ge "1" ] || fail "no wc_order_product_lookup row for the order (after polling the action scheduler)"
pass "order #$ORDER_B placed; wc_order_product_lookup has $LOOKUP_ROWS row(s)"

say "on A: delete the referenced product, capture, propagate"
WIDGET_A_ID=$(wp_a post list --post_type=product --name=duo-widget --field=ID)
wp_a post delete "$WIDGET_A_ID" --force >/dev/null
wp_a duo capture --repo=/siterepo >/dev/null
$GIT_A add -A && $GIT_A commit -qm "delete: retire Duo Widget" && $GIT_A push -q origin main

say "on B: plan must show the delete BLOCKED"
$GIT_B pull -q origin main
PLAN_TEXT=$(wp_b duo plan --repo=/siterepo)
echo "$PLAN_TEXT"
echo "$PLAN_TEXT" | grep -q '\[BLOCKED:' || fail "plan did not surface the referential guard"
pass "plan blocks the delete (order references this product)"

say "apply --with-deletes must fail loudly without --force-delete-referenced"
set +e
APPLY_ERR=$(wp_b duo apply --repo=/siterepo --with-deletes --default-author=admin 2>&1)
APPLY_RC=$?
set -e
[ "$APPLY_RC" -ne 0 ] || fail "apply succeeded despite the referential guard"
echo "$APPLY_ERR" | grep -qi 'referential guard' || fail "failure did not mention the referential guard"
pass "apply refused the guarded delete"

say "apply --force-delete-referenced must succeed with a FORCED warning"
FORCE_OUT=$(wp_b duo apply --repo=/siterepo --with-deletes --force-delete-referenced --default-author=admin 2>&1)
echo "$FORCE_OUT"
echo "$FORCE_OUT" | grep -qi 'FORCED' || fail "no FORCED warning printed"
pass "forced delete applied with a loud warning"

say "acceptance: product gone on B, order row intact"
REMAINING=$(wp_b post list --post_type=product --name=duo-widget --field=ID)
[ -z "$REMAINING" ] || fail "product still present on B"
STILL=$(wp_b db query "SELECT COUNT(*) FROM wp_wc_order_product_lookup WHERE order_id=$ORDER_B" --skip-column-names)
[ "$STILL" = "$LOOKUP_ROWS" ] || fail "order's lookup row(s) were disturbed by the delete"
ORDER_STATUS=$(wp_b wc shop_order get "$ORDER_B" --field=status --user=admin)
[ -n "$ORDER_STATUS" ] || fail "order no longer retrievable"
pass "product removed on B; order #$ORDER_B (status=$ORDER_STATUS) is untouched"

printf '\n\033[1;32m✔ SPIKE D PASSED\033[0m\n'
