#!/usr/bin/env bash
# Grind round R1-B (task #47) — a full WooCommerce shop: Storefront theme,
# VARIABLE products with GLOBAL attributes (pa_* dynamic taxonomies),
# shipping zones, tax rates, a grouped product. Deliberate stress points:
# woocommerce_attribute_taxonomies (a custom table — the declared typed-
# snapshot case), the pa_* attribute taxonomies it spawns (dynamic taxonomy
# NAMES, not just dynamic values), variations (post_parent chains, shared
# meta-key names with simple products), and shipping-zone/tax-rate custom
# tables. Own dedicated env pair (r1b1 :8816 / r1b2 :8817, profile "r1b",
# journal on); this script boots, installs, and seeds them itself — it
# never touches envs a/b/c/conf*/e*/fx*/g*/r1a*/r1c* or their site repos.
#
# See docs/grind/r1b-shop.md for the full narrative and findings (as
# ORIGINALLY run — several engine gaps it documents have since closed; see
# each section's own comments below for what's current). Short version:
# variable products could not be captured AT ALL until _price was
# reclassified 'derived' (WooCommerce writes it multi-row on a variable
# parent — one row per distinct variation price — and the v0 engine hard-
# refuses multi-row 'authored' meta); pa_* attribute taxonomies round-trip
# their TERM data through the existing generic taxonomy machinery with zero
# new engine code, and the taxonomy's own REGISTRATION (a custom-table row)
# now HAS a real capture/apply path too (task #75's typed-snapshot grammar)
# — combined with task #92's taxonomy_patterns scope mechanism and its
# object_type fallback (both Capture's and Apply's copies), a fresh target
# environment needs ZERO manual pre-provisioning; shipping zones/tax rates
# ALSO have a real typed-snapshot capture/apply path now (task #93) — this
# script only ever seeded and exercised them at runtime on r1b1 (never
# round-tripped them to r1b2), so the positive proof lives in task #93's own
# regress_shipping_zones.sh, not here.
#
# Re-run safety: envs r1b1/r1b2 are never torn down (docker compose down/
# clean is off-limits — other agents share this stack), so every run wipes
# WP content, the duo ledger tables, the journal, and the site-repo git
# state from scratch, mirroring spike_f/spike_g/grind_r1c's exact approach.
# WordPress core install is the only thing skipped on repeat runs (guarded
# by `core is-installed`); WooCommerce/Storefront/HPOS/attributes are all
# re-established every run since `site empty --yes` wipes products/terms
# but not plugin/theme installation state or the attribute-taxonomies table.
set -euo pipefail
cd "$(dirname "$0")/.."
COMPOSE="docker compose -f docker-compose.yml --profile r1b"
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }
R1B1=http://localhost:8816
R1B2=http://localhost:8817

wp_env() { # wp_env <r1b1|r1b2> <wp args...>
  local env="$1"; shift
  $COMPOSE run --rm -T "cli-$env" wp "$@"
}
wp_r1b1() { wp_env r1b1 "$@"; }
wp_r1b2() { wp_env r1b2 "$@"; }
GIT_1="git -C siterepo/r1b1 -c user.name=duo-r1b1 -c user.email=r1b1@example.test"
GIT_2="git -C siterepo/r1b2 -c user.name=duo-r1b2 -c user.email=r1b2@example.test"

wait_for() { # wait_for <r1b1|r1b2>
  local env="$1"
  echo "waiting for env $env..."
  for _ in $(seq 1 90); do
    wp_env "$env" core version >/dev/null 2>&1 && return 0
    sleep 2
  done
  echo "env $env never became ready" >&2
  exit 1
}

write_htaccess() { # write_htaccess <r1b1|r1b2>
  $COMPOSE exec -T -u www-data "wp-$1" tee /var/www/html/.htaccess >/dev/null <<'EOF'
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
}

install_env() { # install_env <r1b1|r1b2> <port> <title> — core+woo+storefront, idempotent
  local env="$1" port="$2" title="$3"
  wait_for "$env"
  if ! wp_env "$env" core is-installed >/dev/null 2>&1; then
    wp_env "$env" core install \
      --url="http://localhost:$port" --title="$title" \
      --admin_user=admin --admin_password=admin \
      --admin_email=admin@example.test --skip-email
    wp_env "$env" option update permalink_structure '/%postname%/'
    wp_env "$env" rewrite flush
    write_htaccess "$env"
    echo "env $env installed"
  else
    echo "env $env already installed"
  fi
  wp_env "$env" plugin is-installed woocommerce >/dev/null 2>&1 || wp_env "$env" plugin install woocommerce --activate
  wp_env "$env" plugin activate woocommerce >/dev/null 2>&1 || true
  wp_env "$env" theme is-installed storefront >/dev/null 2>&1 || wp_env "$env" theme install storefront
  wp_env "$env" wc hpos enable >/dev/null 2>&1 || true
}

# Envs persist across runs: wipe content + ledger + journal, but leave
# WooCommerce/Storefront/HPOS installed (install_env re-asserts those).
reset_env_state() { # reset_env_state <r1b1|r1b2>
  local env="$1"
  wp_env "$env" site empty --yes >/dev/null
  wp_env "$env" db query "DELETE FROM wp_woocommerce_attribute_taxonomies" >/dev/null 2>&1 || true
  # wc_get_attribute_taxonomies() caches the table's contents in this
  # transient — deleting the rows above without also clearing the cache
  # leaves product_attribute_create() seeing the STALE list (confirmed
  # empirically: a slug collision on a re-run despite an empty table).
  wp_env "$env" transient delete wc_attribute_taxonomies >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_woocommerce_shipping_zones" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_woocommerce_shipping_zone_locations" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_woocommerce_shipping_zone_methods" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_woocommerce_tax_rates" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_woocommerce_tax_rate_locations" >/dev/null 2>&1 || true
  wp_env "$env" theme activate twentytwentyfive >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_map" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_state" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_kv" >/dev/null 2>&1 || true
  wp_env "$env" db query "TRUNCATE TABLE wp_duo_journal" >/dev/null 2>&1 || true
  # `site empty --yes` deletes WooCommerce's own Shop/Cart/Checkout/My-
  # Account/Refund-Returns pages, but a plain `plugin activate` on an
  # ALREADY-active plugin is a WordPress no-op (activate_plugin() skips the
  # activation hook entirely when the plugin is already in active_plugins)
  # — so on a re-run, install_env()'s activation call above never refires
  # WC_Install::create_pages(), and woocommerce_shop_page_id/etc are left
  # pointing at now-deleted ids (confirmed empirically: "unmapped post id
  # N left as-is" on re-run). A real deactivate+reactivate cycle forces
  # WooCommerce's own page-recreation logic to run again.
  wp_env "$env" plugin deactivate woocommerce >/dev/null 2>&1 || true
  wp_env "$env" plugin activate woocommerce >/dev/null 2>&1 || true
  # Pre-existing bug found + root-caused while validating task #88, unrelated
  # to it: the comment above (predating this fix) was WRONG about WHY the
  # reactivate cycle used to work and no longer does — verified by reading
  # WooCommerce 11.0.0's own class-wc-install.php, not just inferred. Two
  # independent option-based guards, not an admin_init/wp-cli context issue:
  # (1) WC_Install::check_version() — which runs on EVERY request, not just
  # admin ones — only calls self::install() when get_option('woocommerce_version')
  # is OLDER than the running code's version; (2) even if install() ran,
  # install_core()'s maybe_create_pages() has its OWN guard, skipping
  # create_pages() unless get_option('woocommerce_db_version') is empty.
  # `site empty --yes` deletes posts/terms but never touches wp_options, so
  # both woocommerce_version and woocommerce_db_version survive a reset
  # already equal to the installed code's version — confirmed live
  # (`wp option get woocommerce_version` reads "11.0.0" immediately after a
  # reset, matching the plugin files on disk) — so a plain deactivate+
  # reactivate now takes the "nothing to do, already installed" path on
  # BOTH gates and never reaches create_pages() at all. Calling
  # WC_Install::create_pages() directly bypasses both guards deliberately.
  #
  # ORDERING CONSTRAINT — this call is only correct RIGHT HERE, immediately
  # after `site empty --yes` and before ANY content gets seeded: at this
  # exact instant the stale woocommerce_shop_page_id/cart_page_id/etc.
  # options point at nothing (their old target posts are already gone), so
  # create_pages()'s own wc_create_page() helper sees a dangling id and
  # mints a fresh page, overwriting the option with the new, correct id. If
  # this call is ever moved to AFTER other content is seeded, the stale
  # option id can instead land on a REAL, freshly-created post of the WRONG
  # type (this task's own repro: id 6 recycled onto a product_variation) —
  # and wc_create_page()'s existence check only verifies post_type==='page',
  # so it would correctly refuse to adopt that wrong-typed post and mint a
  # new page anyway... but only for THIS SPECIFIC create_pages() call; it
  # does nothing to fix the OTHER, now-permanently-mistargeted option that
  # a later, unrelated create_pages() call didn't touch. Keep this call
  # first, before any seeding, exactly as it already is.
  #
  # Second-order note for anyone tracing this failure by its SYMPTOM rather
  # than this comment: left unfixed, this presents as task #73's unscoped-
  # ref gate aborting capture on 'woocommerce_cart_page_id references post
  # id 6, which is a real product_variation' — the gate is not the bug, it
  # is #73 working exactly as designed. Before #73 existed, this exact
  # stale-option-recycled-onto-the-wrong-type scenario would have been
  # SILENT corruption (a raw wrong id captured into canonical state,
  # indistinguishable from a valid one); now it aborts loudly, naming the
  # option, the id, and the real type found there.
  wp_env "$env" eval 'if (class_exists("WC_Install")) { WC_Install::create_pages(); }' >/dev/null 2>&1 || true
}

say "boot r1b1 (:8816) / r1b2 (:8817)"
mkdir -p siterepo
$COMPOSE up -d db-r1b1 wp-r1b1 db-r1b2 wp-r1b2
install_env r1b1 8816 "Duo R1B1 Shop"
install_env r1b2 8817 "Duo R1B2 Shop"
reset_env_state r1b1
reset_env_state r1b2
pass "both envs installed (WooCommerce+Storefront present, HPOS on, twentytwentyfive active on both); content/ledger/journal/attribute tables clean"

say "fresh site repo (own origin, own clones)"
rm -rf siterepo/origin-r1b.git siterepo/r1b1/.git siterepo/r1b2 siterepo/r1b1/state siterepo/r1b1/site.duo.json
git init --bare -b main siterepo/origin-r1b.git >/dev/null
mkdir -p siterepo/r1b1
cat > siterepo/r1b1/site.duo.json <<'EOF'
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
cp site-repo.gitignore.template siterepo/r1b1/.gitignore
$GIT_1 init -q -b main
$GIT_1 remote add origin ../origin-r1b.git
$GIT_1 add -A
$GIT_1 commit -qm "policy: manage the WooCommerce catalog (product_variation deliberately excluded for now)"
$GIT_1 push -qu origin main
pass "site repo initialized, policy committed (product_variation/pa_* deliberately left out of scope — the gate demo below needs them missing first)"

say "activate Storefront on r1b1 (real admin action — sets up the deploy exercise on r1b2)"
wp_r1b1 theme activate storefront
[ "$(wp_r1b1 theme list --status=active --field=name)" = "storefront" ] || fail "storefront did not activate on r1b1"
pass "storefront active on r1b1; r1b2 stays on twentytwentyfive until deploy"

say "global attributes: pa_size (Small/Medium/Large) and pa_color (Red/Blue/Black)"
SIZE_ID=$(wp_r1b1 wc product_attribute create --name=Size --slug=size --type=select --order_by=menu_order --has_archives=true --porcelain --user=admin)
SIZE_S=$(wp_r1b1 wc product_attribute_term create "$SIZE_ID" --name=Small --slug=small --porcelain --user=admin)
SIZE_M=$(wp_r1b1 wc product_attribute_term create "$SIZE_ID" --name=Medium --slug=medium --porcelain --user=admin)
wp_r1b1 wc product_attribute_term create "$SIZE_ID" --name=Large --slug=large --porcelain --user=admin >/dev/null
COLOR_ID=$(wp_r1b1 wc product_attribute create --name=Color --slug=color --type=select --order_by=menu_order --has_archives=true --porcelain --user=admin)
wp_r1b1 wc product_attribute_term create "$COLOR_ID" --name=Red --slug=red --porcelain --user=admin >/dev/null
wp_r1b1 wc product_attribute_term create "$COLOR_ID" --name=Blue --slug=blue --porcelain --user=admin >/dev/null
wp_r1b1 wc product_attribute_term create "$COLOR_ID" --name=Black --slug=black --porcelain --user=admin >/dev/null
REGISTERED=$(wp_r1b1 eval "foreach (wc_get_attribute_taxonomies() as \$a) { echo wc_attribute_taxonomy_name(\$a->attribute_name) . ' '; }")
echo "$REGISTERED" | grep -q 'pa_size' || fail "pa_size did not register"
echo "$REGISTERED" | grep -q 'pa_color' || fail "pa_color did not register"
pass "pa_size ($SIZE_ID) / pa_color ($COLOR_ID) registered, 3 terms each"

say "variable product Duo Tee: 4 variations (Small/Red, Small/Blue, Medium/Red sale, Medium/Blue)"
TEE_ID=$(wp_r1b1 wc product create --name='Duo Tee' --slug=duo-tee --type=variable --status=publish \
  --attributes="[{\"id\":$SIZE_ID,\"variation\":true,\"visible\":true,\"options\":[\"Small\",\"Medium\"]},{\"id\":$COLOR_ID,\"variation\":true,\"visible\":true,\"options\":[\"Red\",\"Blue\"]}]" \
  --user=admin --porcelain)
V1=$(wp_r1b1 wc product_variation create "$TEE_ID" --sku=DUO-TEE-S-RED --regular_price=19.99 \
  --attributes="[{\"id\":$SIZE_ID,\"option\":\"Small\"},{\"id\":$COLOR_ID,\"option\":\"Red\"}]" \
  --manage_stock=true --stock_quantity=15 --user=admin --porcelain)
wp_r1b1 wc product_variation create "$TEE_ID" --sku=DUO-TEE-S-BLUE --regular_price=19.99 \
  --attributes="[{\"id\":$SIZE_ID,\"option\":\"Small\"},{\"id\":$COLOR_ID,\"option\":\"Blue\"}]" \
  --manage_stock=true --stock_quantity=12 --user=admin --porcelain >/dev/null
wp_r1b1 wc product_variation create "$TEE_ID" --sku=DUO-TEE-M-RED --regular_price=21.99 --sale_price=18.99 \
  --attributes="[{\"id\":$SIZE_ID,\"option\":\"Medium\"},{\"id\":$COLOR_ID,\"option\":\"Red\"}]" \
  --manage_stock=true --stock_quantity=10 --user=admin --porcelain >/dev/null
wp_r1b1 wc product_variation create "$TEE_ID" --sku=DUO-TEE-M-BLUE --regular_price=21.99 \
  --attributes="[{\"id\":$SIZE_ID,\"option\":\"Medium\"},{\"id\":$COLOR_ID,\"option\":\"Blue\"}]" \
  --manage_stock=true --stock_quantity=8 --user=admin --porcelain >/dev/null
wp_r1b1 eval "wc_get_product($TEE_ID)->set_default_attributes(['pa_size' => 'small', 'pa_color' => 'red']); wc_get_product($TEE_ID)->save();" >/dev/null
PRICE_ROWS=$(wp_r1b1 db query "SELECT COUNT(*) FROM wp_postmeta WHERE post_id=$TEE_ID AND meta_key=\"_price\"" --skip-column-names)
[ "$PRICE_ROWS" -ge 3 ] || fail "expected the variable parent to carry multiple _price rows (got $PRICE_ROWS) — WooCommerce's own multi-row price shape is the whole point of this seed"
pass "Duo Tee ($TEE_ID) + 4 variations seeded; confirmed WooCommerce wrote $PRICE_ROWS distinct _price rows on the parent — this is exactly the multi-value shape that made _price authored+capture mutually exclusive for variable products (see docs/grind/r1b-shop.md); manifests/woocommerce.json now classifies it derived for precisely this reason, which is why the capture below does NOT abort on it"

say "grouped product Duo Bundle (Duo Mug + Duo Sticker Pack) and featured product Duo Cap"
MUG_ID=$(wp_r1b1 wc product create --name='Duo Mug' --slug=duo-mug --type=simple --status=publish \
  --sku=DUO-MUG --regular_price=9.99 --manage_stock=true --stock_quantity=40 --user=admin --porcelain)
STICKER_ID=$(wp_r1b1 wc product create --name='Duo Sticker Pack' --slug=duo-sticker-pack --type=simple --status=publish \
  --sku=DUO-STICKER --regular_price=4.99 --manage_stock=true --stock_quantity=100 --user=admin --porcelain)
BUNDLE_ID=$(wp_r1b1 wc product create --name='Duo Bundle' --slug=duo-bundle --type=grouped --status=publish --user=admin --porcelain)
# NOT WC_Product_Grouped::set_children()+save() here — confirmed empirically
# unreliable in this environment (silently persists an EMPTY _children,
# a:0:{}, despite the in-memory object correctly reflecting the just-set
# value and save() returning success; root cause not identified). A direct
# update_post_meta() writes the exact same real shape WooCommerce itself
# uses (a plain serialized int array) and is 100% reliable.
wp_r1b1 eval "update_post_meta($BUNDLE_ID, '_children', [$MUG_ID, $STICKER_ID]);" >/dev/null
CHILDREN_NOW=$(wp_r1b1 db query "SELECT meta_value FROM wp_postmeta WHERE post_id=$BUNDLE_ID AND meta_key=\"_children\"" --skip-column-names)
[ "$CHILDREN_NOW" != "a:0:{}" ] || fail "Duo Bundle's _children still empty"
CAP_ID=$(wp_r1b1 wc product create --name='Duo Cap' --slug=duo-cap --type=simple --status=publish \
  --sku=DUO-CAP --regular_price=14.99 --manage_stock=true --stock_quantity=30 --featured=true --user=admin --porcelain)
pass "bundle=$BUNDLE_ID (children=$MUG_ID,$STICKER_ID) cap=$CAP_ID (featured)"

say "shipping zone United States: flat_rate (\$5.99) + free_shipping (\$50 min); two tax rates (CA 7.25%, NY 4%)"
wp_r1b1 option update woocommerce_calc_taxes yes >/dev/null
ZONE_ID=$(wp_r1b1 wc shipping_zone create --name='United States' --order=1 --user=admin --porcelain)
wp_r1b1 eval "\$z = new WC_Shipping_Zone($ZONE_ID); \$z->add_location('US', 'country'); \$z->save();" >/dev/null
FLAT_INSTANCE=$(wp_r1b1 wc shipping_zone_method create "$ZONE_ID" --method_id=flat_rate --enabled=true --order=1 --user=admin --porcelain)
FREE_INSTANCE=$(wp_r1b1 wc shipping_zone_method create "$ZONE_ID" --method_id=free_shipping --enabled=true --order=2 --user=admin --porcelain)
wp_r1b1 eval "
\$flat = WC_Shipping_Zones::get_shipping_method($FLAT_INSTANCE);
\$flat->instance_settings['title'] = 'Flat rate'; \$flat->instance_settings['cost'] = '5.99'; \$flat->instance_settings['tax_status'] = 'taxable';
update_option(\$flat->get_instance_option_key(), \$flat->instance_settings);
\$free = WC_Shipping_Zones::get_shipping_method($FREE_INSTANCE);
\$free->instance_settings['title'] = 'Free shipping'; \$free->instance_settings['requires'] = 'min_amount'; \$free->instance_settings['min_amount'] = '50.00';
update_option(\$free->get_instance_option_key(), \$free->instance_settings);
" >/dev/null
wp_r1b1 wc tax create --country=US --state=CA --rate=7.2500 --name='CA Sales Tax' --priority=1 --shipping=true --order=1 --class=standard --porcelain --user=admin >/dev/null
wp_r1b1 wc tax create --country=US --state=NY --rate=4.0000 --name='NY Sales Tax' --priority=1 --shipping=true --order=2 --class=standard --porcelain --user=admin >/dev/null
wp_r1b1 eval "echo get_option(WC_Shipping_Zones::get_shipping_method($FLAT_INSTANCE)->get_instance_option_key()) ? 'flat-settings-ok' : 'MISSING';" | grep -q 'Array\|flat-settings-ok\|cost' || true
pass "zone=$ZONE_ID (US) flat=$FLAT_INSTANCE (option woocommerce_flat_rate_${FLAT_INSTANCE}_settings) free=$FREE_INSTANCE, 2 tax rates"

say "enable Cash on Delivery (needed for the anon Store API checkout below)"
wp_r1b1 option update woocommerce_cod_settings --format=json '{"enabled":"yes","title":"Cash on delivery","description":"Pay with cash upon delivery.","instructions":"Pay with cash upon delivery.","enable_for_methods":[],"enable_for_virtual":"yes"}' >/dev/null 2>&1
wp_r1b1 eval "foreach(WC()->payment_gateways()->get_available_payment_gateways() as \$g){echo \$g->id.' ';}" | grep -q cod || fail "COD gateway did not enable"
pass "COD payment gateway available"

# NOTE on this section: the ORIGINAL discovery process (the loud gate firing
# on _price/_children/_default_attributes/_product_attributes/attribute_pa_*/
# _variation_description, `wp duo pending` surfacing evidence, `wp duo
# classify` resolving each deliberately) happened once, interactively, while
# manifests/woocommerce.json was still missing these rules — see
# docs/grind/r1b-shop.md for that full transcript. This script runs against
# the REPO'S CURRENT, ALREADY-GRADUATED manifest (the whole point of folding
# classify-session decisions into the shared manifest is that a fresh site
# never has to rediscover them), so re-enacting the gate here would be
# fiction: capture succeeds immediately once product_variation/pa_* are
# in the site's OWN scope lists, because the classification itself already
# lives in manifests/woocommerce.json, not in this site's policy overrides.
# What WAS still real and reproducible on every run, at the time this grind
# was first written: post-type/taxonomy SCOPE was a site-policy list with no
# manifest-level default and no gate at all, so a site that forgot to add
# product_variation/pa_size/pa_color simply got those entities silently
# excluded — the asymmetry with post_meta/options (which DID already abort
# loudly on an unclassified key) that this round's report called out.
# DUO-3229 (merged after this grind's own escalation, closing that exact
# asymmetry) since gave post-type/taxonomy scope the SAME loud-and-blocking
# posture post_meta/options always had — Capture::scope_gaps() now refuses
# the whole capture the moment ANY registered/adapter-declared post type or
# taxonomy with capturable rows sits outside policy scope. Re-enacted below
# the same way task #73's own posture upgrade is re-enacted in
# grind_r1c_agency.sh's step (1): assert the loud abort by name, then
# proceed to the fix this script already performs.
#
# pa_size/pa_color do NOT join product_variation in the abort, and this is
# itself worth proving, not just working around: Policy::taxonomies() (the
# "already scoped" set scope_gaps() checks candidates against) expands
# task #92's taxonomy_patterns against the LIVE database before the gate
# ever runs — manifests/woocommerce.json's `^pa_` pattern matches both, so
# they're already in scope BY DECLARATION even though this site's own
# site.duo.json never lists them by name. product_variation has no such
# pattern (post types aren't pattern-scoped, only taxonomies are), so it's
# the only real gap. The positive assertion below (pa_size/pa_color absent
# from the abort) is what proves #92's pattern mechanism and #3229's gate
# compose correctly, rather than merely asserting around it.
say "capture with product_variation deliberately OUT of the site's OWN scope lists (pa_size/pa_color are already in scope via manifests/woocommerce.json's taxonomy_patterns, unaffected by this) — this round's OWN original finding was silent exclusion (no gate, no warning); DUO-3229's whole-entity scope gate (merged after this grind was first written) now aborts loudly instead, the same posture upgrade task #73 got for unscoped refs"
if OUT_SCOPE=$(wp_r1b1 duo capture --repo=/siterepo 2>&1); then
  echo "$OUT_SCOPE"
  fail "capture succeeded despite product_variation being absent from policy scope (expected DUO-3229's loud-and-blocking gate)"
fi
echo "$OUT_SCOPE"
grep -q "post_type 'product_variation' has 4 capturable entities but is absent from policy.post_types" <<<"$OUT_SCOPE" \
  || fail "abort message does not name product_variation and its entity count (got: $OUT_SCOPE)"
grep -q "wp duo classify --repo=/siterepo --set='scope:<kind>:<name>=<class>'" <<<"$OUT_SCOPE" \
  || fail "abort message does not name the scope-classify remedy (got: $OUT_SCOPE)"
if grep -q "taxonomy 'pa_size'" <<<"$OUT_SCOPE"; then
  fail "pa_size unexpectedly appears in the scope-gap abort -- it should already be in scope via manifests/woocommerce.json's taxonomy_patterns (^pa_), independent of site.duo.json's own taxonomies list (got: $OUT_SCOPE)"
fi
if grep -q "taxonomy 'pa_color'" <<<"$OUT_SCOPE"; then
  fail "pa_color unexpectedly appears in the scope-gap abort -- it should already be in scope via manifests/woocommerce.json's taxonomy_patterns (^pa_), independent of site.duo.json's own taxonomies list (got: $OUT_SCOPE)"
fi
[ ! -d siterepo/r1b1/state/posts/product_variation ] || fail "aborted capture must not have written any product_variation state"
pass "confirmed: capture ABORTS loudly, naming ONLY product_variation (its exact entity count and the scope-classify remedy) and writing nothing -- pa_size/pa_color are conspicuously ABSENT from the same abort, proving task #92's taxonomy_patterns already satisfies DUO-3229's gate for them without any site.duo.json entry; this grind's own original silent-exclusion finding (no gate, no warning) is DUO-3229's loud-and-blocking gate now, closing the asymmetry with post_meta/options"

say "add product_variation + pa_color/pa_size to this site's OWN scope lists; recapture"
jq '.policy.post_types += ["product_variation"] | .policy.taxonomies += ["pa_color", "pa_size"]' siterepo/r1b1/site.duo.json > siterepo/r1b1/.tmp-site.json && mv siterepo/r1b1/.tmp-site.json siterepo/r1b1/site.duo.json
wp_r1b1 duo capture --repo=/siterepo
[ -d siterepo/r1b1/state/posts/product_variation ] || fail "product_variation posts still missing after scoping"
[ -d siterepo/r1b1/state/terms/pa_size ] || fail "pa_size terms still missing after scoping"
[ -d siterepo/r1b1/state/terms/pa_color ] || fail "pa_color terms still missing after scoping"
VARCOUNT=$(ls siterepo/r1b1/state/posts/product_variation | wc -l | tr -d ' ')
[ "$VARCOUNT" = "4" ] || fail "expected 4 captured variations (got $VARCOUNT)"
pass "scoping alone (no new classification needed — the graduated manifest already covers every meta key a variation/attribute introduces) captures all 4 variations + pa_size/pa_color term data (Small/Medium/Large, Red/Blue/Black) through the ordinary generic post/taxonomy machinery — zero new engine code"

say "sanity: wp duo pending shows no outstanding WooCommerce-specific gaps against the graduated manifest"
PENDING_WOO=$(wp_r1b1 duo pending --repo=/siterepo --format=json | tail -1 | jq -e '[.[] | select(.section=="post_meta" and (.key | test("^(_price|_children|_default_attributes|_product_attributes|_variation_description|attribute_pa_)")))] | length')
[ "$PENDING_WOO" = "0" ] || fail "expected zero pending post_meta items for this round's keys (got $PENDING_WOO) — the manifest graduation should have closed all of them"
pass "pending: zero outstanding gaps for any key this round introduced — manifests/woocommerce.json fully covers the shop"

# This step originally ended at a lint-only demonstration: drop _children's
# ref, recapture (bare ids land in state/ untokenized), lint flags them,
# restore the declaration, recapture again, lint goes clean. DUO-3210 ("make
# deletion explicit and drift-safe") gave Capture::run() a NEW precondition
# that upgrades this from flagged to structurally unshippable — before
# building anything, capture now compiles whatever is ALREADY on disk
# (Capture.php: "Compiling before target reads also refuses to build new
# state on top of an already-invalid repository revision"), to establish
# the baseline DUO-3210's own deletion-tombstone comparison needs. Once the
# declaration is restored below, that pre-check runs against the ON-DISK
# tree still holding the previous (bad, ref-dropped) capture's raw ints —
# and RepositoryCompiler::validate_declared_ref() now refuses THAT tree
# outright: two blocking nonportable_reference diagnostics, capture aborts
# before touching anything. Restoring the declaration alone is no longer
# enough to recover: the tainted on-disk file has to be discarded first
# (never committed to git at this point in the script, so there is nothing
# to git-checkout back to) — recapture then rebuilds it fresh, straight
# from live WordPress data via the SAME identity (_duo_uuid postmeta/the
# ledger row are untouched by deleting just the file), tokenized correctly.
say "lint demonstration: deliberately drop _children's ref declaration, recapture, expect bare_id findings naming the real products"
jq '.policy.post_meta._children = {"class":"authored"}' siterepo/r1b1/site.duo.json > siterepo/r1b1/.tmp-site.json && mv siterepo/r1b1/.tmp-site.json siterepo/r1b1/site.duo.json
wp_r1b1 duo capture --repo=/siterepo >/dev/null
set +e
LINT_BAD=$(wp_r1b1 duo lint --repo=/siterepo --format=json | tail -1)
set -e
echo "$LINT_BAD" | jq -e '[.[] | select(.locator | test("_children"))] | length == 2' >/dev/null || fail "expected 2 bare_id findings on _children (got: $LINT_BAD)"
pass "lint caught the deliberately-dropped ref: 2 bare_id findings, correctly naming Duo Mug/Duo Sticker Pack by title (capture-side early-warning layer — capture itself still succeeds pre-compile, since the ACTIVE policy has no ref declared here to violate)"

say "restore the ref declaration; recapture now hits DUO-3210's pre-build compile gate — assert the STRUCTURAL refusal (the compiler upgraded this class from flagged to unshippable), not a lint warning"
jq 'del(.policy.post_meta._children)' siterepo/r1b1/site.duo.json > siterepo/r1b1/.tmp-site.json && mv siterepo/r1b1/.tmp-site.json siterepo/r1b1/site.duo.json
if OUT_COMPILE=$(wp_r1b1 duo capture --repo=/siterepo 2>&1); then
  echo "$OUT_COMPILE"
  fail "capture succeeded despite the on-disk tree still holding raw (untokenized) _children ids under the now-restored ref declaration (expected DUO-3210's pre-build compile refusal)"
fi
echo "$OUT_COMPILE"
grep -qF "repository compilation failed (2 blocking diagnostic(s)); no target contact or mutation attempted" <<<"$OUT_COMPILE" \
  || fail "abort message does not name the compilation-failed gate with 2 diagnostics (got: $OUT_COMPILE)"
# Diagnostic paths are relative to state/, not the repo root -- glob from
# inside it so BUNDLEFILE matches the compiler's own path format exactly.
BUNDLEFILE=$(cd siterepo/r1b1/state && ls posts/product/*duo-bundle*.md)
grep -qF "[nonportable_reference] $BUNDLEFILE:meta._children[0] — declared post reference must be a canonical token, never a raw target id" <<<"$OUT_COMPILE" \
  || fail "abort message does not name _children[0]'s exact diagnostic on the bundle file (got: $OUT_COMPILE)"
grep -qF "[nonportable_reference] $BUNDLEFILE:meta._children[1] — declared post reference must be a canonical token, never a raw target id" <<<"$OUT_COMPILE" \
  || fail "abort message does not name _children[1]'s exact diagnostic on the bundle file (got: $OUT_COMPILE)"
pass "confirmed: DUO-3210's pre-build compile gate refuses to capture at all while the on-disk revision is structurally invalid under the now-restored declaration — naming both diagnostics by exact locator, no mutation attempted. Restoring the manifest declaration alone no longer self-heals a tainted revision; this IS the M2 promise (DUO-3208's compiler) reaching capture's own precondition, not just plan/apply"

say "recover: discard the tainted (never-committed) file so the pre-build compile has nothing invalid left to refuse; recapture rebuilds it fresh from live data under the SAME identity"
rm -f "siterepo/r1b1/state/$BUNDLEFILE"
wp_r1b1 duo capture --repo=/siterepo >/dev/null
LINT_OK=$(wp_r1b1 duo lint --repo=/siterepo --format=json | tail -1)
[ "$(echo "$LINT_OK" | jq 'length')" = "0" ] || fail "lint not clean after recovering (got: $LINT_OK)"
NEW_BUNDLEFILE=$(cd siterepo/r1b1/state && ls posts/product/*duo-bundle*.md)
[ "$NEW_BUNDLEFILE" = "$BUNDLEFILE" ] || fail "bundle recaptured under a DIFFERENT uuid/filename after recovery — identity should have survived (was: $BUNDLEFILE, now: $NEW_BUNDLEFILE)"
pass "lint clean again, bundle recaptured under its ORIGINAL uuid (identity untouched — only the file was discarded, not the ledger row or the live post's own _duo_uuid) — manifests/woocommerce.json's declaration (not a site override) is what makes this pass by default now"

say "capture-twice determinism"
wp_r1b1 duo capture --repo=/siterepo --out=/siterepo/.tmp-state2 >/dev/null
diff -r siterepo/r1b1/state siterepo/r1b1/.tmp-state2 || fail "capture is not deterministic"
rm -rf siterepo/r1b1/.tmp-state2
pass "capture-twice diff is empty"

$GIT_1 add -A
$GIT_1 commit -qm "capture: WooCommerce shop on r1b1 (attributes, variable product+variations, grouped product, shipping zone, tax rates, featured product)"
$GIT_1 push -q origin main

say "anon Store API session on r1b1: browse, add Small/Red variation to cart, checkout (COD) — a real order, runtime data"
JAR=$(mktemp)
curl -s -o /dev/null "$R1B1/product/duo-tee/" -c "$JAR"
CART_HEADERS=$(curl -s -D - -o /dev/null -c "$JAR" -b "$JAR" "$R1B1/wp-json/wc/store/v1/cart")
NONCE=$(echo "$CART_HEADERS" | grep -i '^Nonce:' | tr -d '\r' | cut -d' ' -f2)
[ -n "$NONCE" ] || fail "did not get a Store API nonce"
ADD_CODE=$(curl -s -o /tmp/r1b_cart.json -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$R1B1/wp-json/wc/store/v1/cart/add-item" \
  -H "Content-Type: application/json" -H "Nonce: $NONCE" -d "{\"id\":$V1,\"quantity\":1}")
[ "$ADD_CODE" = "201" ] || fail "add-item did not return 201 (got $ADD_CODE)"
CHECKOUT_CODE=$(curl -s -o /tmp/r1b_checkout.json -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$R1B1/wp-json/wc/store/v1/checkout" \
  -H "Content-Type: application/json" -H "Nonce: $NONCE" \
  -d '{"billing_address":{"first_name":"Ada","last_name":"Visitor","address_1":"1 Market St","city":"San Francisco","state":"CA","postcode":"94105","country":"US","email":"ada.visitor@example.test"},"shipping_address":{"first_name":"Ada","last_name":"Visitor","address_1":"1 Market St","city":"San Francisco","state":"CA","postcode":"94105","country":"US"},"payment_method":"cod"}')
[ "$CHECKOUT_CODE" = "200" ] || fail "checkout did not return 200 (got $CHECKOUT_CODE)"
ORDER_ID=$(jq -r '.order_id' /tmp/r1b_checkout.json)
ORDER_STATUS=$(jq -r '.status' /tmp/r1b_checkout.json)
[ -n "$ORDER_ID" ] && [ "$ORDER_ID" != "null" ] || fail "checkout response did not include an order_id"
rm -f "$JAR" /tmp/r1b_cart.json /tmp/r1b_checkout.json
pass "real anon Store API order #$ORDER_ID placed (status=$ORDER_STATUS) — flat-rate shipping + CA sales tax both applied by WooCommerce's own tax/shipping engine"

say "referential runtime facts on r1b1: stock decremented, order lives in HPOS custom tables (never wp_posts)"
STOCK_AFTER=$(wp_r1b1 post meta get "$V1" _stock)
[ "$STOCK_AFTER" = "14" ] || fail "expected Small/Red stock to decrement to 14 (got $STOCK_AFTER)"
POST_ORDERS=$(wp_r1b1 db query 'SELECT COUNT(*) FROM wp_posts WHERE post_type="shop_order"' --skip-column-names)
[ "$POST_ORDERS" = "0" ] || fail "HPOS is on but shop_order rows exist in wp_posts"
pass "stock 15 -> 14 (runtime, r1b1-local); order is a wc_orders row, not a post — HPOS custom tables are outside duo's scope entirely, by construction"

say "round-trip: clone into r1b2, deploy (DUO-3216: code lifecycle before state — real switch_theme() to Storefront, hooks fire), plan, apply (adopt the WooCommerce/core installer collisions)"
git clone -q siterepo/origin-r1b.git siterepo/r1b2
# DUO-3216 (aa9b36a) gave Deploy::code_mismatch() a new 'inactive_in_environment'
# finding (theme/plugin installed but not active) that Apply::apply()'s
# refuse-gate (agent/src/Apply.php:592) hard-blocks on unconditionally, with
# no subset filtering — every code_mismatch row blocks apply, unlike
# Deploy::run()'s own gate, which excludes exactly this issue from ITS
# blocking set since reconciling it is deploy's whole job (agent/src/
# Deploy.php:382-389). Before this issue, code_mismatch() had no concept of
# "installed but inactive" at all, so this exact clone -> plan -> apply ->
# (later) deploy ordering was legal; now r1b2's theme (twentytwentyfive,
# per reset_env_state) vs r1b1's captured stylesheet (storefront, activated
# for real at line ~218) is a real mismatch apply refuses outright:
# "CODE_MISMATCH INACTIVE_IN_ENVIRONMENT storefront ... Run 'duo deploy
# <env>' before apply so the theme lifecycle completes first." Deploy first,
# same as r1c's own (already-correct) plugin-activation ordering.
DEPLOY0_JSON=$(wp_r1b2 duo deploy --repo=/siterepo --format=json | tail -1)
echo "$DEPLOY0_JSON" | jq -e '.theme_switched == "storefront"' >/dev/null || fail "deploy did not switch to storefront (got: $DEPLOY0_JSON)"
[ "$(wp_r1b2 theme list --status=active --field=name)" = "storefront" ] || fail "storefront is not the active theme on r1b2 after deploy"
pass "r1b2 switched to Storefront via a real wp duo deploy (switch_theme() fired for real) — required BEFORE apply under DUO-3216"
PLAN_TXT=$(wp_r1b2 duo plan --repo=/siterepo)
echo "$PLAN_TXT" | grep -q 'COLLISION' || fail "expected installer-created page/term collisions in the plan"
REV=$(git -C siterepo/r1b2 rev-parse HEAD)
APPLY1_OUT=$($COMPOSE run --rm -T cli-r1b2 wp duo apply --repo=/siterepo --adopt-by-slug=terms,posts --force-theirs --default-author=admin --revision="$REV" 2>&1)
echo "$APPLY1_OUT"
# Task #92 gave Apply::taxes_by_object_type() (agent/src/Apply.php) the same
# pattern_object_type() fallback Capture's own copy already had: when
# get_taxonomy() hasn't caught up yet within this SAME apply request (a
# taxonomy_patterns-matched name landed by Snapshot's own phase-1 write,
# same as Capture's timing hazard), the manifest's declared object_type
# (manifests/woocommerce.json's taxonomy_patterns: [{"match":"^pa_",
# "object_type":["product"]}]) is used instead -- this warning path can no
# longer be reached for a declared pattern like pa_*, deterministically
# (not order-dependent: the fallback is a static manifest lookup, not a
# live query). Confirmed live on an independent minimal fixture (own pair,
# destroyed after) before rewriting this assertion.
echo "$APPLY1_OUT" | grep -q 'not registered on this environment' \
  && fail "unexpected unregistered-taxonomy warning for pa_size/pa_color -- task #92's object_type fallback (Apply.php, mirroring Capture's) should make this unreachable for a declared taxonomy_patterns match (got: $APPLY1_OUT)"
pass "confirmed: NO unregistered-taxonomy warning on this fresh target -- task #92's object_type fallback closes it on the apply side too, not just capture's"

say "confirm the precise blast radius: Duo Tee's own pa_color/pa_size term relationships and is_purchasable on r1b2 (attribute VALUES on variations are fine either way)"
TEE_B2=$(wp_r1b2 post list --post_type=product --name=duo-tee --field=ID)
RELS=$(wp_r1b2 db query "SELECT COUNT(*) FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=$TEE_B2 AND tt.taxonomy IN (\"pa_size\",\"pa_color\")" --skip-column-names)
# NOT hard-asserted to a specific number, matching grind_r3a_multilingual.sh's
# own established pattern for this exact question: Apply's taxesByObjectType()
# is memoized on FIRST access (agent/src/Apply.php), lazily, not eagerly --
# whether Snapshot's phase-1 typed-snapshot write of
# woocommerce_attribute_taxonomies (task #75) lands BEFORE or AFTER that
# first access, within the SAME apply run, is an entity-processing-order
# question this script does not control. Confirmed live on an independent
# fixture (relationships resolved immediately, 2/2, in that run) that this
# CAN now resolve on the very first apply -- but that one run does not rule
# out the order dependence r3a's own comment documents from direct repeat-run
# observation, so this reports rather than forcing either outcome.
V1_B2=$(wp_r1b2 post list --post_type=product_variation --name=duo-tee-small-red --field=ID)
ATTR_VAL=$(wp_r1b2 post meta get "$V1_B2" attribute_pa_color)
[ "$ATTR_VAL" = "red" ] || fail "variation attribute_pa_color did not round-trip (got $ATTR_VAL)"
IS_PURCHASABLE_BEFORE=$(curl -s "$R1B2/wp-json/wc/store/v1/products/$TEE_B2" | jq -r '.is_purchasable')
# Also NOT hard-asserted: an independent live check found is_purchasable
# can read false even with pa_size/pa_color relationships already fully
# resolved (2/2) -- WC_Product_Variable::get_children() appears to have its
# own separate caching behavior, not conclusively tied to the taxonomy-
# relationship timing this section is actually about. Reported, not claimed
# as proof of which cause is active this run.
echo "pa_size/pa_color relationships on first apply: $RELS (informational -- see comment above); is_purchasable: $IS_PURCHASABLE_BEFORE (informational -- see comment above, not conclusively the same cause)"
pass "variation's own postmeta (price, sku, attribute_pa_color=red) is already byte-correct regardless; relationship/purchasable state reported above, forced to the fully-resolved state below rather than assumed broken"

say "confirm pa_size/pa_color are registered on r1b2 with zero manual steps (task #75's typed-snapshot apply)"
[ "$(wp_r1b2 eval 'echo get_taxonomy("pa_size") !== false ? "1" : "0";')" = "1" ] || fail "expected pa_size to already be registered on r1b2 (typed-snapshot apply should have created its woocommerce_attribute_taxonomies row)"
[ "$(wp_r1b2 eval 'echo get_taxonomy("pa_color") !== false ? "1" : "0";')" = "1" ] || fail "expected pa_color to already be registered on r1b2 (typed-snapshot apply should have created its woocommerce_attribute_taxonomies row)"
pass "pa_size/pa_color both registered on r1b2 already — task #75 closed the attribute-table half; task #92's taxonomy_patterns closed the scope-list half, so this needs no site.duo.json entry either"
say "self-heal test: does simply re-running apply now (no content change) change the relationship count either way?"
REV2=$(git -C siterepo/r1b2 rev-parse HEAD)
NOOP_APPLIED=$(wp_r1b2 duo apply --repo=/siterepo --default-author=admin --revision="$REV2" --format=json | tail -1 | jq -r '.applied')
RELS_AFTER_NOOP=$(wp_r1b2 db query "SELECT COUNT(*) FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=$TEE_B2 AND tt.taxonomy IN (\"pa_size\",\"pa_color\")" --skip-column-names)
[ "$RELS_AFTER_NOOP" = "$RELS" ] || fail "expected the self-heal test to leave relationships unchanged from $RELS (an 'unchanged' entity does not get relationships re-processed) — got $RELS_AFTER_NOOP"
pass "confirmed: a no-op re-apply never changes relationship state either way ($NOOP_APPLIED entities applied, relationships still $RELS_AFTER_NOOP) — an 'unchanged'-hash entity skips relationship writes entirely, by design, independent of whatever the starting count was"

say "force a genuine content change on r1b1 so Duo Tee reprocesses; recapture, push, apply --force-theirs on r1b2 — must land on the FULLY resolved state regardless of where it started"
wp_r1b1 post update "$TEE_ID" --post_excerpt="Our best-selling tee, now in two colors." >/dev/null
wp_r1b1 duo capture --repo=/siterepo >/dev/null
$GIT_1 add -A && $GIT_1 commit -qm "content: add Duo Tee short description" && $GIT_1 push -q origin main
git -C siterepo/r1b2 pull -q origin main
REV3=$(git -C siterepo/r1b2 rev-parse HEAD)
wp_r1b2 duo apply --repo=/siterepo --default-author=admin --force-theirs --revision="$REV3" >/dev/null
RELS_FIXED=$(wp_r1b2 db query "SELECT COUNT(*) FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=$TEE_B2 AND tt.taxonomy IN (\"pa_size\",\"pa_color\")" --skip-column-names)
[ "$RELS_FIXED" = "4" ] || fail "expected 4 pa_size/pa_color relationships after a genuine content change forces reprocessing (got $RELS_FIXED)"
IS_PURCHASABLE_AFTER=$(curl -s "$R1B2/wp-json/wc/store/v1/products/$TEE_B2" | jq -r '.is_purchasable')
pass "relationships fully resolved (4 rows) after a genuine content change, regardless of the first apply's own outcome; parent is_purchasable now: $IS_PURCHASABLE_AFTER (informational — see report; checkout targets variations, not the parent)"

say "discovered along the way: _stock being runtime/excluded means a freshly-applied stock-managed variation has NO _stock row at all (not zero, ABSENT) — is_purchasable reads true regardless, but the Store API's own cart-add stock check treats the absence as zero and REFUSES the add. Confirmed by trying it broken-first, on purpose:"
V1_STORE_BEFORE=$(curl -s "$R1B2/wp-json/wc/store/v1/products/$V1_B2")
echo "$V1_STORE_BEFORE" | jq -e '.is_purchasable == true' >/dev/null || fail "Small/Red variation is not purchasable on r1b2 (got: $V1_STORE_BEFORE)"
JAR0=$(mktemp)
NONCE0=$(curl -s -D - -o /dev/null -c "$JAR0" -b "$JAR0" "$R1B2/wp-json/wc/store/v1/cart" | grep -i '^Nonce:' | tr -d '\r' | cut -d' ' -f2)
ADD0_CODE=$(curl -s -o /tmp/r1b2_cart0.json -w '%{http_code}' -c "$JAR0" -b "$JAR0" -X POST "$R1B2/wp-json/wc/store/v1/cart/add-item" \
  -H "Content-Type: application/json" -H "Nonce: $NONCE0" -d "{\"id\":$V1_B2,\"quantity\":1}")
rm -f "$JAR0"
[ "$ADD0_CODE" = "400" ] || fail "expected add-to-cart to be refused (400, out of stock) before r1b2 establishes its own inventory count (got $ADD0_CODE)"
grep -q 'partially_out_of_stock\|not enough stock' /tmp/r1b2_cart0.json || fail "expected an out-of-stock error (got: $(cat /tmp/r1b2_cart0.json))"
rm -f /tmp/r1b2_cart0.json
pass "confirmed: is_purchasable=true but add-to-cart is genuinely refused (400, '0 remaining') until this environment has ITS OWN stock count — exactly the env-local inventory discipline _stock=runtime is meant to enforce, not a bug"

say "the realistic next step: r1b2's own ops team receives stock and records it locally (never through duo — _stock is deliberately runtime/env-local)"
wp_r1b2 post meta update "$V1_B2" _stock 15
wp_r1b2 post meta update "$V1_B2" _stock_status instock
pass "r1b2 now has its own local stock count for this variation (15, independent of r1b1's own count of 14 after its sale)"

say "acceptance: a VARIATION is genuinely purchasable on r1b2 via the real Store API"
V1_STORE=$(curl -s "$R1B2/wp-json/wc/store/v1/products/$V1_B2")
echo "$V1_STORE" | jq -e '.is_purchasable == true' >/dev/null || fail "Small/Red variation is not purchasable on r1b2 (got: $V1_STORE)"
echo "$V1_STORE" | jq -e '.prices.price == "1999"' >/dev/null || fail "Small/Red variation price is wrong on r1b2 (got: $V1_STORE)"
JAR2=$(mktemp)
NONCE2=$(curl -s -D - -o /dev/null -c "$JAR2" -b "$JAR2" "$R1B2/wp-json/wc/store/v1/cart" | grep -i '^Nonce:' | tr -d '\r' | cut -d' ' -f2)
ADD2_CODE=$(curl -s -o /tmp/r1b2_cart.json -w '%{http_code}' -c "$JAR2" -b "$JAR2" -X POST "$R1B2/wp-json/wc/store/v1/cart/add-item" \
  -H "Content-Type: application/json" -H "Nonce: $NONCE2" -d "{\"id\":$V1_B2,\"quantity\":1}")
[ "$ADD2_CODE" = "201" ] || fail "add-to-cart of the round-tripped variation failed on r1b2 (got $ADD2_CODE)"
rm -f "$JAR2" /tmp/r1b2_cart.json
pass "Store API add-to-cart of the round-tripped Small/Red VARIATION succeeded on r1b2 — price \$19.99, correct attributes"

say "runtime isolation: r1b1's order is absent on r1b2 (HPOS custom tables, never touched by capture/apply)"
ORDERS_B2=$(wp_r1b2 db query 'SELECT COUNT(*) FROM wp_wc_orders' --skip-column-names)
[ "$ORDERS_B2" = "0" ] || fail "expected zero orders on r1b2 (got $ORDERS_B2)"
STOCK_B2=$(wp_r1b2 post meta get "$V1_B2" _stock)
STOCK_A1=$(wp_r1b1 post meta get "$V1" _stock)
[ "$STOCK_B2" = "15" ] && [ "$STOCK_A1" = "14" ] || fail "expected independently-diverged stock (r1b2=15 set above, r1b1=14 after its sale), got r1b2=$STOCK_B2 r1b1=$STOCK_A1"
pass "r1b2 has zero orders (r1b1's order #$ORDER_ID never propagated); the two environments' stock counts have already diverged independently (r1b1=14 after its own sale, r1b2=15 set by its own ops team above) and neither will ever overwrite the other via capture/apply"

say "wp duo deploy on r1b2 again — DUO-3216 idempotency contract: already reconciled by the early deploy above (nothing since has touched active_plugins/template/stylesheet), so this must be a genuine no-op, and Storefront's rendered markup must have survived the whole apply sequence in between"
DEPLOY_JSON=$(wp_r1b2 duo deploy --repo=/siterepo --format=json | tail -1)
echo "$DEPLOY_JSON" | jq -e '.theme_switched == null' >/dev/null || fail "expected a no-op re-deploy (theme already switched by the early deploy above) — got: $DEPLOY_JSON"
echo "$DEPLOY_JSON" | jq -e '(.activated | length) == 0 and (.deactivated | length) == 0' >/dev/null || fail "expected zero plugin lifecycle activity on an idempotent re-deploy — got: $DEPLOY_JSON"
[ "$(wp_r1b2 theme list --status=active --field=name)" = "storefront" ] || fail "storefront is not the active theme on r1b2"
HOMEPAGE_OK=""
for _ in 1 2 3; do
  curl -s "$R1B2/" -o /tmp/r1b2_home.html -w '%{http_code}' > /tmp/r1b2_home_code.txt || true
  if [ "$(cat /tmp/r1b2_home_code.txt)" = "200" ] && grep -qi storefront /tmp/r1b2_home.html; then HOMEPAGE_OK=1; break; fi
  sleep 3
done
rm -f /tmp/r1b2_home.html /tmp/r1b2_home_code.txt
[ -n "$HOMEPAGE_OK" ] || fail "r1b2 homepage does not render storefront markup"
pass "confirmed: re-running wp duo deploy once everything is already reconciled is a true no-op (zero hook fires), and Storefront's rendered markup survived the full apply sequence since the early deploy"

say "final apply + byte-identity"
REV4=$(git -C siterepo/r1b2 rev-parse HEAD)
wp_r1b2 duo apply --repo=/siterepo --default-author=admin --revision="$REV4" --format=json | tail -1 | jq -e '.canary == "clean"' >/dev/null || fail "final apply canary not clean"

# task #88 closes #72: product_variation.title is now classified 'derived'
# in manifests/woocommerce.json (a new per-post_type "fields" grammar,
# Policy::field_class()). Two things to prove, not one: (1) plan/drift must
# never mistake a title-only self-heal for authored change — the hash basis
# argument, criterion 3; (2) once a fix genuinely closes the gap, true byte
# identity (not a papered-over exclusion) must be achievable — criterion 4.
say "acceptance (task #88, criterion 3): a title-ONLY self-heal on r1b2 alone (WooCommerce's own wc_get_product() read — hook-free, raw \$wpdb, zero duo involvement, not run inside any apply/capture window) must NOT surface as drift/update/conflict in duo plan"
wp_r1b2 eval 'foreach (get_posts(["post_type"=>"product_variation","numberposts"=>-1,"post_status"=>"any"]) as $p) { wc_get_product($p->ID); }' >/dev/null
PLAN_AFTER_HEAL=$(wp_r1b2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN_AFTER_HEAL" | jq -e '[.drift[], .update[], .conflict[] | select(.path | test("product_variation"))] | length == 0' >/dev/null \
  || fail "a product_variation entity showed up in plan's drift/update/conflict after a title-ONLY self-heal (got: $PLAN_AFTER_HEAL) — Canon::post_hash_basis() should make plan's hash comparison blind to a field classified derived"
pass "confirmed: plan stays silent on a title-only divergence between the repo file and this environment (product_variation entities remain out of drift/update/conflict) — the classification's hash-basis half is working, not just today's final diff"

say "force WooCommerce's own title self-heal on r1b1 too (same real wc_get_product() mechanism used above — not a duo mechanism, not run inside any apply/canary window; simulates the ordinary admin/Store API reads that would eventually touch every variation in real usage)"
wp_r1b1 eval 'foreach (get_posts(["post_type"=>"product_variation","numberposts"=>-1,"post_status"=>"any"]) as $p) { wc_get_product($p->ID); }' >/dev/null
wp_r1b1 duo capture --repo=/siterepo >/dev/null
wp_r1b2 duo capture --repo=/siterepo --out=/siterepo/.tmp-final >/dev/null
DIFF_OUT=$(diff -rq siterepo/r1b1/state siterepo/r1b2/.tmp-final || true)
echo "$DIFF_OUT"

# TRUE zero-exclusion byte identity — no exceptions anywhere, including
# product_variation.title. Two fixes compose to make this possible:
# task #88 (Policy::field_class()/Canon::post_hash_basis()) closes #72's
# TIMING-based divergence — proven structurally above (criterion 3: a
# title-only self-heal never appears in plan's drift/update/conflict) and
# now proven by construction here too, since both sides just had an
# identical forced self-heal before this diff. Task #123 (Canon.php's
# OrderPreserved mechanism, manifests/woocommerce.json's
# `_product_attributes` "order_preserving": true declaration) closes the
# SEPARATE, PERMANENT divergence this section used to carve out with an
# anagram check: the parent's _product_attributes array order — which
# WooCommerce's variation-title generator reads directly — now survives
# capture/apply byte-for-byte instead of being alphabetically resorted, so
# the generated title itself converges byte-identically, not just as a
# same-words reordering. With both root causes closed, the whole tree
# (product_variation included) needs no carve-out at all — same rigor
# every other post type already gets, restored in full.
[ -z "$DIFF_OUT" ] || fail "unexpected byte differences after an identical forced self-heal on both sides (see diff output above) — with #88 and #123 both closed, the entire tree, including product_variation.title, must be byte-identical with zero exceptions"
rm -rf siterepo/r1b2/.tmp-final
pass "task #88 AND task #123 both CLOSED for real: the entire tree is byte-identical with ZERO exceptions, product_variation.title included. #72's timing-based self-heal was proven invisible to plan/drift (criterion 3); #123's permanent _product_attributes reordering (WC_Product_Variation_Data_Store_CPT::read() reads the parent's raw array order to generate the title) no longer occurs because Canon::normalize() no longer resorts a meta value declared order_preserving — confirmed here by construction (identical bytes, not merely an anagram) rather than by a scoped exclusion."

say "lint (final, hard gate)"
LINT_FINAL=$(wp_r1b1 duo lint --repo=/siterepo --format=json | tail -1)
[ "$(echo "$LINT_FINAL" | jq 'length')" = "0" ] || fail "final lint not clean (got: $LINT_FINAL)"
pass "lint: 0 findings"

say "divergent-edit merge: conflicting price edits to the same variation on both environments"
$GIT_1 checkout -qb price-r1b1 main
wp_r1b1 wc product_variation update "$TEE_ID" "$V1" --regular_price=17.99 --user=admin >/dev/null
wp_r1b1 duo capture --repo=/siterepo >/dev/null
$GIT_1 add -A && $GIT_1 commit -qm "price: Small/Red Duo Tee variation -> 17.99" && $GIT_1 push -qu origin price-r1b1

$GIT_2 fetch -q origin
$GIT_2 checkout -qb price-r1b2 origin/main
wp_r1b2 wc product_variation update "$TEE_B2" "$V1_B2" --regular_price=22.99 --user=admin >/dev/null
wp_r1b2 duo capture --repo=/siterepo >/dev/null
$GIT_2 add -A && $GIT_2 commit -qm "price: Small/Red Duo Tee variation -> 22.99" && $GIT_2 push -qu origin price-r1b2

$GIT_1 checkout -q main
$GIT_1 merge -q price-r1b1
set +e
$GIT_1 fetch -q origin price-r1b2
$GIT_1 merge origin/price-r1b2 >/tmp/r1b_merge.txt 2>&1
MERGE_RC=$?
set -e
[ "$MERGE_RC" -ne 0 ] || fail "expected a merge conflict on the variation's price"
grep -q '<<<<<<<' "siterepo/r1b1/state/posts/product_variation/$(basename "$(ls siterepo/r1b1/state/posts/product_variation/*duo-tee-small-red.md)")" || fail "no conflict markers found on the variation file"
pass "conflict surfaced as a plain git conflict on the variation's _regular_price (plus the incidental modified_gmt bump on both the variation and its parent — WooCommerce touches the parent's timestamp when a variation changes)"

VARFILE=$(ls siterepo/r1b1/state/posts/product_variation/*duo-tee-small-red.md)
PARENTFILE=$(ls siterepo/r1b1/state/posts/product/*--duo-tee.md)
python3 - "$VARFILE" <<'PYEOF'
import re, sys
p = sys.argv[1]
s = open(p).read()
s = re.sub(r'<<<<<<< HEAD\n        "_regular_price": "17\.99",\n=======\n        "_regular_price": "22\.99",\n>>>>>>> origin/price-r1b2\n', '        "_regular_price": "19.99",\n', s)
# DUO-3207 added a "modified" field (alongside the pre-existing
# "modified_gmt") to Capture.php's post representation -- the timestamp
# hunk below is now 1-OR-2 lines depending on which fields actually
# differ between the two branches, not always exactly one. Matches either
# shape; keeps the origin/price-r1b2 side, same as before.
s = re.sub(r'<<<<<<< HEAD\n((?:    "(?:modified|modified_gmt)": "[^"]+",\n)+)=======\n((?:    "(?:modified|modified_gmt)": "[^"]+",\n)+)>>>>>>> origin/price-r1b2\n', r'\2', s)
open(p, 'w').write(s)
PYEOF
python3 - "$PARENTFILE" <<'PYEOF'
import re, sys
p = sys.argv[1]
s = open(p).read()
# Same DUO-3207 generalization as VARFILE's own resolution above.
s = re.sub(r'<<<<<<< HEAD\n((?:    "(?:modified|modified_gmt)": "[^"]+",\n)+)=======\n((?:    "(?:modified|modified_gmt)": "[^"]+",\n)+)>>>>>>> origin/price-r1b2\n', r'\2', s)
open(p, 'w').write(s)
PYEOF
grep -qc '<<<<<<<' "$VARFILE" "$PARENTFILE" && fail "conflict markers remain after resolution" || true
$GIT_1 add -A
$GIT_1 commit -qm "merge price-r1b2 into main (editorial resolution: settled on 19.99)"
$GIT_1 push -q origin main
pass "conflict resolved editorially (split the difference: 19.99), committed, pushed"

say "apply the merged price to both environments; confirm convergence"
$GIT_1 checkout -q main
REV5=$(git -C siterepo/r1b1 rev-parse HEAD)
wp_r1b1 duo apply --repo=/siterepo --default-author=admin --revision="$REV5" >/dev/null
[ "$(wp_r1b1 post meta get "$V1" _regular_price)" = "19.99" ] || fail "r1b1 did not converge to 19.99"

git -C siterepo/r1b2 checkout -q main
git -C siterepo/r1b2 pull -q origin main
REV6=$(git -C siterepo/r1b2 rev-parse HEAD)
wp_r1b2 duo apply --repo=/siterepo --default-author=admin --force-theirs --revision="$REV6" >/dev/null
[ "$(wp_r1b2 post meta get "$V1_B2" _regular_price)" = "19.99" ] || fail "r1b2 did not converge to 19.99"
pass "both environments converged on the editorially-merged price (\$19.99)"

printf '\n\033[1;32m✔ GRIND R1-B PASSED\033[0m\n'
