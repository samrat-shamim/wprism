#!/usr/bin/env bash
# Spike D — WooCommerce authored round-trip + fail-closed product deletion:
#   a product catalog (meta/terms/media refs, string/csv cast variants) round-
#   trips A -> B byte-for-byte; runtime meta (_stock) is never reconciled; a
#   real source-side product deletion is refused before repository or target
#   mutation because the open extension ecosystem is not a closed reverse-
#   reference inventory. Coupons section: a product+
#   category-restricted percent coupon and an expiring free-shipping
#   fixed_cart coupon round-trip their ref/cast fields (incl. exclude_
#   variants) A -> B byte-for-byte; a redemption's usage_count/_used_by stay
#   A-local runtime data and never propagate to B.
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
    "post_types": ["post", "page", "attachment", "product", "shop_coupon"],
    "taxonomies": ["category", "post_tag", "product_cat", "product_tag", "product_type"]
  },
  "spec_version": 2
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

say "seed coupons on A: percent (product+category restricted, email-restricted) and fixed_cart (expiring, free shipping)"
CLEARANCE_A=$(wp_a term create product_cat Clearance --slug=clearance --porcelain)
EXPIRES_TS=$(wp_a eval "echo strtotime('2026-12-31 23:59:59');")

CPN_IDS=$(wp_a eval "
\$pct = new WC_Coupon();
\$pct->set_code('duo10off');
\$pct->set_discount_type('percent');
\$pct->set_amount('10');
\$pct->set_product_ids([$GADGET_A]);
\$pct->set_product_categories([$CAT_ID]);
\$pct->set_excluded_product_ids([$GIZMO_A]);
\$pct->set_excluded_product_categories([$CLEARANCE_A]);
\$pct->set_usage_limit(100);
\$pct->set_usage_limit_per_user(1);
\$pct->set_limit_usage_to_x_items(2);
\$pct->set_minimum_amount('20.00');
\$pct->set_individual_use(true);
\$pct->set_exclude_sale_items(true);
\$pct->set_email_restrictions(['vip@example.test', '*@wholesale.example.test']);
\$pct->set_status('publish');
\$pct->save();
echo \$pct->get_id() . PHP_EOL;

\$ship = new WC_Coupon();
\$ship->set_code('duo5ship');
\$ship->set_discount_type('fixed_cart');
\$ship->set_amount('5');
\$ship->set_date_expires($EXPIRES_TS);
\$ship->set_free_shipping(true);
\$ship->set_status('publish');
\$ship->save();
echo \$ship->get_id() . PHP_EOL;
")
CPN_PCT=$(echo "$CPN_IDS" | sed -n 1p)
CPN_SHIP=$(echo "$CPN_IDS" | sed -n 2p)
pass "coupons seeded (clearance=$CLEARANCE_A pct=$CPN_PCT ship=$CPN_SHIP, expires=$EXPIRES_TS)"

say "redeem duo10off against an order on A (usage_count/_used_by become A-local runtime data)"
# Applying the coupon (not just saving the order) is what actually increments
# usage_count/_used_by -- WC_Order::apply_coupon() triggers
# wc_update_coupon_usage_counts() synchronously for any non-cancelled/failed
# order, no status transition required. Billing email must match the coupon's
# email_restrictions above or WC rejects the apply with a WP_Error.
REDEEM_OUT=$(wp_a eval "
\$order = wc_create_order();
\$order->set_billing_email('vip@example.test');
\$order->add_product(wc_get_product($GADGET_A), 1);
\$applied = \$order->apply_coupon('duo10off');
echo is_wp_error(\$applied) ? ('FAILED:' . \$applied->get_error_message()) : 'OK';
echo PHP_EOL;
\$order->calculate_totals();
\$order->set_status('processing');
\$order->save();
echo 'order=' . \$order->get_id() . PHP_EOL;
")
grep -q '^OK$' <<<"$REDEEM_OUT" || fail "coupon redemption on A did not succeed: $REDEEM_OUT"
[ "$(wp_a eval "echo (int) get_post_meta($CPN_PCT, 'usage_count', true);")" = "1" ] || fail "coupon usage_count did not increment on A after redemption"
pass "duo10off redeemed on A ($REDEEM_OUT)"

say "capture coupons into the site repo, commit, push"
wp_a duo capture --repo=/siterepo
$GIT_A add -A && $GIT_A commit -qm "capture: seed WooCommerce coupons on A (percent w/ product+category restriction, fixed_cart w/ expiry+free shipping; one redemption)" && $GIT_A push -q origin main

say "pull on B, apply coupons"
$GIT_B checkout -q main && $GIT_B pull -q origin main
REV=$(git -C siterepo/b rev-parse HEAD)
wp_b duo apply --repo=/siterepo --adopt-by-slug=terms,posts --force-theirs --default-author=admin --revision="$REV"

say "acceptance: canonical(B) == canonical(A) including coupons, byte for byte"
wp_b duo capture --repo=/siterepo --out=/siterepo/.tmp-cstate >/dev/null
diff -r siterepo/a/state siterepo/b/.tmp-cstate || fail "coupon round-trip mismatch between A and B"
rm -rf siterepo/b/.tmp-cstate
pass "canonical state (incl. coupons) identical across environments"

say "acceptance: coupon refs resolved to B-local ids (product_ids/product_categories + exclude_ variants)"
CPN_PCT_B=$(wp_b post list --post_type=shop_coupon --name=duo10off --field=ID)
CPN_SHIP_B=$(wp_b post list --post_type=shop_coupon --name=duo5ship --field=ID)
CAT_GADGETS_B=$(wp_b term list product_cat --slug=gadgets --field=term_id)
CLEARANCE_B=$(wp_b term list product_cat --slug=clearance --field=term_id)

# product_ids/exclude_product_ids: ref post[] cast csv -- WooCommerce always
# writes these as a comma-joined string (single id here, so just the bare id).
[ "$(wp_b eval "echo get_post_meta($CPN_PCT_B, 'product_ids', true);")" = "$GADGET_B" ] || fail "duo10off product_ids did not resolve to gadget's B-local id"
[ "$(wp_b eval "echo get_post_meta($CPN_PCT_B, 'exclude_product_ids', true);")" = "$GIZMO_B" ] || fail "duo10off exclude_product_ids did not resolve to gizmo's B-local id"
# product_categories/exclude_product_categories: ref term[], no cast --
# WooCommerce stores a real serialized int array, unlike product_ids above.
PCATS_OK=$(wp_b eval "
\$c = array_map('intval', (array) get_post_meta($CPN_PCT_B, 'product_categories', true));
echo (\$c === [(int) '$CAT_GADGETS_B']) ? 'ok' : ('bad:' . implode(',', \$c));
")
[ "$PCATS_OK" = "ok" ] || fail "duo10off product_categories did not resolve to Gadgets' B-local term id ($PCATS_OK)"
XCATS_OK=$(wp_b eval "
\$c = array_map('intval', (array) get_post_meta($CPN_PCT_B, 'exclude_product_categories', true));
echo (\$c === [(int) '$CLEARANCE_B']) ? 'ok' : ('bad:' . implode(',', \$c));
")
[ "$XCATS_OK" = "ok" ] || fail "duo10off exclude_product_categories did not resolve to Clearance's B-local term id ($XCATS_OK)"
EMAILS_OK=$(wp_b eval "
\$e = get_post_meta($CPN_PCT_B, 'customer_email', true);
echo (is_array(\$e) && in_array('vip@example.test', \$e, true) && in_array('*@wholesale.example.test', \$e, true)) ? 'ok' : 'bad';
")
[ "$EMAILS_OK" = "ok" ] || fail "duo10off customer_email restriction list did not round-trip"
[ "$(wp_b eval "echo get_post_meta($CPN_SHIP_B, 'free_shipping', true);")" = "yes" ] || fail "duo5ship free_shipping did not round-trip"
[ "$(wp_b eval "echo (int) get_post_meta($CPN_SHIP_B, 'date_expires', true);")" = "$EXPIRES_TS" ] || fail "duo5ship date_expires did not round-trip"
pass "product/category refs (incl. exclude_ variants), customer_email, and fixed_cart fields all resolved correctly on B"

say "acceptance: coupon runtime data (usage_count, _used_by) does not propagate A's redemption to B"
USAGE_B=$(wp_b eval "echo (int) get_post_meta($CPN_PCT_B, 'usage_count', true);")
[ "$USAGE_B" = "0" ] || fail "duo10off usage_count leaked onto B (got $USAGE_B, expected B's local value 0)"
USED_BY_B=$(wp_b eval "echo count(get_post_meta($CPN_PCT_B, '_used_by'));")
[ "$USED_BY_B" = "0" ] || fail "duo10off _used_by leaked onto B ($USED_BY_B row(s), expected 0 — A's redemption must stay A-local)"
pass "A's coupon redemption (usage_count=1, _used_by=vip@example.test) stayed A-local; B's usage_count is its own local value (0)"

say "fail-closed deletion boundary: place a target-local order, then delete the source product"
ORDER_B=$(wp_b eval "
\$order = wc_create_order();
\$product = wc_get_product($WIDGET_B);
\$order->add_product(\$product, 1);
\$order->calculate_totals();
\$order->set_status('processing');
\$order->save();
echo \$order->get_id();
")
# WooCommerce debounces analytics lookup updates through Action Scheduler.
# Poll the public runner so the target-local order evidence is concrete before
# the source deletion attempt.
LOOKUP_ROWS=0
for _ in $(seq 1 8); do
  wp_b action-scheduler run >/dev/null
  LOOKUP_ROWS=$(wp_b db query "SELECT COUNT(*) FROM wp_wc_order_product_lookup WHERE product_id=$WIDGET_B" --skip-column-names)
  [ "$LOOKUP_ROWS" -ge "1" ] && break
  sleep 2
done
[ "$LOOKUP_ROWS" -ge "1" ] || fail "no target order lookup row for the product after polling Action Scheduler"

WIDGET_STATE=$(find siterepo/a/state/posts/product -type f -name '*--duo-widget.md' -print -quit)
[ -n "$WIDGET_STATE" ] || fail "canonical Duo Widget state file is missing before the refusal probe"
WIDGET_UUID=$(basename "$WIDGET_STATE")
WIDGET_UUID=${WIDGET_UUID%%--*}
STATE_HASH_BEFORE=$(find siterepo/a/state -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | awk '{print $1}')
HEAD_BEFORE=$($GIT_A rev-parse HEAD)
ORIGIN_BEFORE=$($GIT_A rev-parse origin/main)
STATUS_BEFORE=$($GIT_A status --porcelain=v1 --untracked-files=all)

wp_a wc product delete "$WIDGET_A" --force=true --user=admin >/dev/null
if wp_a post get "$WIDGET_A" --field=ID >/dev/null 2>&1; then
  fail "source product still exists after the public WooCommerce delete"
fi
set +e
CAPTURE_OUT=$(wp_a duo capture --repo=/siterepo 2>&1)
CAPTURE_RC=$?
set -e
[ "$CAPTURE_RC" -ne 0 ] || fail "capture accepted unsupported WooCommerce product deletion intent"
grep -Fq 'deletion intent for post:product is unsupported' <<<"$CAPTURE_OUT" \
  || fail "capture refusal did not name the unsupported post:product boundary: $CAPTURE_OUT"

STATE_HASH_AFTER=$(find siterepo/a/state -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | awk '{print $1}')
[ "$STATE_HASH_AFTER" = "$STATE_HASH_BEFORE" ] || fail "refused capture changed the canonical state tree"
[ "$($GIT_A rev-parse HEAD)" = "$HEAD_BEFORE" ] || fail "refused capture changed local HEAD"
[ "$($GIT_A rev-parse origin/main)" = "$ORIGIN_BEFORE" ] || fail "refused capture changed origin/main"
[ "$($GIT_A status --porcelain=v1 --untracked-files=all)" = "$STATUS_BEFORE" ] \
  || fail "refused capture changed repository worktree status"
[ -f "$WIDGET_STATE" ] || fail "refused capture removed the canonical product file"
[ ! -e "siterepo/a/state/deletions/$WIDGET_UUID.json" ] \
  || fail "refused capture published an unauthorized product tombstone"
[ "$(wp_b post get "$WIDGET_B" --field=post_status)" = "publish" ] \
  || fail "refused source capture changed the target product"
[ "$(wp_b db query "SELECT COUNT(*) FROM wp_wc_product_meta_lookup WHERE product_id=$WIDGET_B" --skip-column-names)" = "1" ] \
  || fail "refused source capture changed the target product lookup"
[ "$(wp_b db query "SELECT COUNT(*) FROM wp_wc_order_product_lookup WHERE order_id=$ORDER_B" --skip-column-names)" = "$LOOKUP_ROWS" ] \
  || fail "refused source capture changed the target-local order lookup"
pass "public source delete is refused before repo/target mutation; target product and order remain intact"

printf '\n\033[1;32m✔ SPIKE D PASSED\033[0m\n'
