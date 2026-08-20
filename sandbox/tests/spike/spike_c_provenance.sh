#!/usr/bin/env bash
# Spike C — provenance journal with WooCommerce as the stress test:
#   the capability×surface classifier proposes 'authored' for an admin's
#   REST settings write and 'runtime' for an anonymous Store API checkout
#   (orders, stock decrement), scored against the Woo manifest ground truth.
#   Also demonstrates the review queue (unclassified writes) and measures
#   journal overhead.
set -euo pipefail
cd "$(dirname "$0")/../.."
COMPOSE="docker compose -f docker-compose.yml --profile spikec"
wp_c() { $COMPOSE run --rm -T cli-c wp "$@"; }
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
C=http://localhost:8803

say "boot env C"
mkdir -p siterepo/c
$COMPOSE up -d db-c wp-c
for _ in $(seq 1 90); do
  wp_c core version >/dev/null 2>&1 && break
  sleep 2
done
if ! wp_c core is-installed >/dev/null 2>&1; then
  wp_c core install --url="$C" --title="Duo C" --admin_user=admin --admin_password=admin \
    --admin_email=admin@example.test --skip-email
fi

say "configure env C for REST (pretty permalinks + Basic Auth passthrough)"
# Fresh installs default to Plain permalinks, under which /wp-json/... doesn't
# route to the REST API at all (WordPress falls back to index.php?rest_route=
# — it 200s with the homepage instead of dispatching, which is what broke
# scenario 1/2 below). wp-cli can't write .htaccess without extra config, and
# apache needs the HTTP_AUTHORIZATION line for Basic Auth (application
# passwords) to reach PHP at all — same two steps sandbox/setup.sh performs
# for envs A/B on this identical docker image.
wp_c option update permalink_structure '/%postname%/' >/dev/null
wp_c rewrite flush --hard >/dev/null
$COMPOSE exec -T -u www-data wp-c tee /var/www/html/.htaccess >/dev/null <<'EOF'
# BEGIN WordPress
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule . /index.php [L]
</IfModule>
# END WordPress
EOF

say "install WooCommerce (the stress test)"
wp_c plugin is-installed woocommerce >/dev/null 2>&1 || wp_c plugin install woocommerce
wp_c plugin activate woocommerce >/dev/null 2>&1 || true

say "enable HPOS (wc_orders custom table) — must run before any order exists"
# WooCommerce still defaults new installs to legacy post-based order storage
# unless HPOS is explicitly turned on. `wc hpos enable`'s auto-create-tables
# path only fires for a genuinely new shop (zero existing orders) — this is
# the modern custom-table architecture DESIGN.md calls out as the point of
# using WooCommerce as the stress test, so it needs to be live before
# scenario 2 places an order, not patched in after.
wp_c wc hpos enable || fail "could not enable HPOS (custom order tables)"

say "store setup (baseline writes; journaled as cli => review)"
wp_c option update woocommerce_store_address '1 Duo Way' >/dev/null
wp_c option update woocommerce_store_city 'Duotown' >/dev/null
wp_c option update woocommerce_store_postcode '10115' >/dev/null
wp_c option update woocommerce_default_country 'DE:BE' >/dev/null
wp_c option update woocommerce_currency 'EUR' >/dev/null
wp_c option update woocommerce_coming_soon 'no' >/dev/null 2>&1 || true
wp_c option update --format=json woocommerce_cod_settings \
  '{"enabled":"yes","title":"COD","description":"","instructions":"","enable_for_methods":[],"enable_for_virtual":"yes"}' >/dev/null
PID=$(wp_c wc product create --name='Duo Widget' --type=simple --virtual=true \
  --regular_price=10 --manage_stock=true --stock_quantity=5 --status=publish \
  --user=admin --porcelain)
pass "product $PID created with stock 5"

say "reset journal — scenario traffic starts here"
wp_c duo journal-reset >/dev/null

say "scenario 1: ADMIN authored actions over authenticated REST"
APP_PASS=$(wp_c user application-password create admin duo-spike --porcelain)
CITY=$(curl -fsu "admin:$APP_PASS" -X PUT "$C/wp-json/wc/v3/settings/general/woocommerce_store_city" \
  -H 'Content-Type: application/json' -d '{"value":"Berlin"}' | jq -r '.value')
[ "$CITY" = "Berlin" ] || fail "admin REST settings write failed"
NAME=$(curl -fsu "admin:$APP_PASS" -X PUT "$C/wp-json/wc/v3/products/$PID" \
  -H 'Content-Type: application/json' -d '{"name":"Duo Widget Pro"}' | jq -r '.name')
[ "$NAME" = "Duo Widget Pro" ] || fail "admin REST product write failed"
pass "admin REST writes done (store city, product rename)"

say "scenario 2: ANONYMOUS Store API checkout (runtime writes)"
CART_TOKEN=$(curl -si "$C/wp-json/wc/store/v1/cart" | grep -i '^cart-token:' | awk '{print $2}' | tr -d '\r')
[ -n "$CART_TOKEN" ] || fail "no Cart-Token from Store API"
curl -fs -X POST "$C/wp-json/wc/store/v1/cart/add-item" \
  -H "Cart-Token: $CART_TOKEN" -H 'Content-Type: application/json' \
  -d "{\"id\":$PID,\"quantity\":2}" >/dev/null || fail "add-item failed"
ORDER_ID=$(curl -fs -X POST "$C/wp-json/wc/store/v1/checkout" \
  -H "Cart-Token: $CART_TOKEN" -H 'Content-Type: application/json' \
  -d '{"billing_address":{"first_name":"Ann","last_name":"Anon","address_1":"Nowhere 1","city":"Berlin","postcode":"10115","country":"DE","email":"ann@example.test"},"payment_method":"cod"}' | jq -r '.order_id')
[ -n "$ORDER_ID" ] && [ "$ORDER_ID" != "null" ] || fail "anonymous checkout failed"
STOCK=$(wp_c wc product get "$PID" --field=stock_quantity --user=admin)
[ "$STOCK" = "3" ] || fail "stock not decremented (got '$STOCK', expected 3)"
pass "anonymous order #$ORDER_ID placed; stock 5 -> 3"

say "journal report vs manifest ground truth"
wp_c duo journal-report --manifests=core,woocommerce
REPORT=$(wp_c duo journal-report --manifests=core,woocommerce --json | tail -1)

check_row() { # jq filter, description
  echo "$REPORT" | jq -e "$1" >/dev/null || fail "$2"
}
check_row '[.rows[] | select(.table=="options" and .item=="woocommerce_store_city" and .surface=="rest" and .proposal=="authored" and .verdict=="agree")] | length >= 1' \
  "admin REST settings write not proposed authored / not agreeing with manifest"
check_row '[.rows[] | select(.table=="wc_orders" and .proposal=="runtime" and .verdict=="agree")] | length >= 1' \
  "anonymous order rows not proposed runtime"
check_row '[.rows[] | select(.table=="postmeta" and .item=="_stock" and .proposal=="runtime" and .verdict=="agree")] | length >= 1' \
  "anonymous stock decrement not proposed runtime"
check_row '.unclassified > 0' \
  "expected a non-empty review queue (unclassified writes) with Woo active"
AGREE=$(echo "$REPORT" | jq -r '.agreement_pct')
DISAGREE_ROWS=$(echo "$REPORT" | jq -r '[.rows[] | select(.verdict=="disagree")] | length')
pass "key rows agree; agreement on manifest-classified writes: ${AGREE}% (disagreeing row groups: $DISAGREE_ROWS — mixed-class requests, e.g. transients written during admin REST, exactly design finding #2)"

say "journal overhead (30 anonymous front-page requests, on vs off)"
# DUO_JOURNAL (wp-config.php) is sourced from WORDPRESS_CONFIG_EXTRA and
# eval()'d fresh every request straight from the container's environment —
# neither `wp config set` nor editing the file can override it without
# recreating the container (verified: PHP's define() keeps the first value
# and just warns on the second; the file has no literal DUO_JOURNAL line to
# edit in the first place). Journal::boot() checks this option as a live
# kill switch for exactly this measurement instead.
t_on=$(for _ in $(seq 1 30); do curl -so /dev/null -w '%{time_total}\n' "$C/"; done | awk '{s+=$1} END {printf "%.1f", s/NR*1000}')
wp_c option update duo_journal_disabled 1 >/dev/null
t_off=$(for _ in $(seq 1 30); do curl -so /dev/null -w '%{time_total}\n' "$C/"; done | awk '{s+=$1} END {printf "%.1f", s/NR*1000}')
wp_c option delete duo_journal_disabled >/dev/null
echo "avg request: journal ON ${t_on}ms vs OFF ${t_off}ms"

printf '\n\033[1;32m✔ SPIKE C PASSED\033[0m\n'
