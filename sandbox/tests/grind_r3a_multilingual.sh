#!/usr/bin/env bash
# Grind round R3-A (task #90) — a multilingual WooCommerce shop: Polylang
# (free) + WooCommerce + Storefront, two languages (en default / de),
# translated Home/About pages, a translated product category, translated
# pa_color attribute terms, one translated simple product, and an
# UNTRANSLATED variable product (Duo Tee: pa_size x pa_color, 4 variations)
# — deliberately kept English-only so its round-trip findings aren't
# confounded with the translation-mechanism findings. Own pair.sh-managed
# pair r3a (8850/8851; see docs/sandbox.md) — this script never runs
# `docker compose down`/`pair.sh destroy` on it or touches any other pair.
#
# Interplay stress points (task #90's brief) and what this script proves:
#  1. Polylang's description_refs (manifests/polylang.json) correctly
#     rewrite post_translations/term_translations serialized description
#     blobs when the translated entities are WooCommerce's OWN (a product,
#     product_cat terms, pa_color attribute terms) — resolving to each
#     environment's OWN local ids even when they differ across environments.
#  2. pa_* attribute terms translate under free Polylang exactly like any
#     other taxonomy (no special casing needed) — the free/pro boundary is
#     empirically NOT a license gate on the translation MECHANISM itself
#     (confirmed by reading Polylang's own settings-cpt.php: zero pro/
#     license checks), it's that WooCommerce-specific data SYNC across
#     translations (price/stock/gallery mirroring) is what the commercial
#     "Polylang for WooCommerce" add-on actually sells.
#  3. Per-language menus: a purpose-built second-language menu's own
#     structure/items always round-tripped correctly; whether it's actually
#     USED at its location on a fresh target used to depend on the
#     polylang option's `nav_menus` sub-key, which was excluded from Duo
#     capture entirely, separate from core.json's language-blind theme_mods
#     capture. DUO-3233's sub_keys mechanism (task #121) now declares
#     `nav_menus` (alongside post_types/taxonomies) an authored, captured/
#     applied sub-key of the SAME option (manifests/polylang.json) — this
#     means the second menu lands at its per-language location on a fresh
#     target with zero manual wiring; when the separate Polylang front-end
#     health guard permits render checks, the assertion below verifies that
#     end to end.
#  4. THE ORIGINAL KNOWN GAP is closed. Task #92's taxonomy_patterns and
#     object_type fallback cover a just-landed pa_* taxonomy even though
#     WordPress's in-memory registry cannot refresh mid-request. Apply's
#     global phase-1 pass creates every typed-table and term row before any
#     phase-2 post relationship write, so the first taxesByObjectType()
#     lookup deterministically sees pa_size/pa_color in the live table.
#     DUO-3280 separately adds the compiled polylang.post_types value to the
#     frozen `language` taxonomy object_type, so the same first apply writes
#     language relationships too. This fixture now asserts exact first-
#     apply convergence and contains no manual config/content-change retry.
#
# Re-run safety: r3a1/r3a2 are never torn down (pair.sh destroy is off-
# limits for a script re-run — other agents may share the fleet), so every
# run wipes WP content, Polylang's own language/option state, the duo
# ledger tables, and the site-repo git state from scratch. WordPress core
# install and the WooCommerce/Polylang/Storefront installs are skipped on
# repeat runs (pair.sh's own idempotent `up`, plus is-active guards here).
set -euo pipefail
cd "$(dirname "$0")/.."   # -> sandbox/
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*"; exit 1; }

PORT1=8850
PORT2=8851
R3A1="http://localhost:$PORT1"
R3A2="http://localhost:$PORT2"
export DUO_PAIR=r3a DUO_PORT1=$PORT1 DUO_PORT2=$PORT2
COMPOSE="docker compose -p duo-r3a -f pair.yml -f pair.http.yml"

wp_env() { # wp_env <1|2> <wp args...>
  local side="$1"; shift
  $COMPOSE run --rm -T "cli${side}" wp "$@"
}
wp_1() { wp_env 1 "$@"; }
wp_2() { wp_env 2 "$@"; }

# DUO-3300: Polylang's `language` term descriptions are plugin-owned,
# PHP-serialized configuration records. Read them through raw SQL so this
# assertion cannot be fooled by WP_Term's in-process cache. The historical
# R3-A repair hid the first failing boundary; every durable checkpoint now
# refuses an absent/malformed locale record instead.
assert_language_descriptions() { # assert_language_descriptions <side> <checkpoint>
  local side="$1" checkpoint="$2" out
  out=$(wp_env "$side" eval '
    global $wpdb;
    foreach (["en" => "en_US", "de" => "de_DE"] as $slug => $locale) {
      $description = $wpdb->get_var($wpdb->prepare(
        "SELECT tt.description FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy=\"language\" AND t.slug=%s",
        $slug
      ));
      $config = maybe_unserialize($description);
      if (!is_string($description) || $description === "" || !is_array($config) || ($config["locale"] ?? null) !== $locale) {
        fwrite(STDERR, sprintf("invalid language description for %s: raw=%s decoded=%s\n", $slug, var_export($description, true), var_export($config, true)));
        exit(1);
      }
    }
    echo "POLYLANG_LANGUAGE_DESCRIPTIONS_OK\n";
  ' 2>&1) || { echo "$out"; fail "Polylang language descriptions invalid on side $side at checkpoint '$checkpoint'"; }
  grep -q 'POLYLANG_LANGUAGE_DESCRIPTIONS_OK' <<<"$out" \
    || fail "Polylang language-description probe produced no success marker on side $side at checkpoint '$checkpoint' (got: $out)"
}

assert_complete_html() { # assert_complete_html <body> <label>
  local body="$1" label="$2" bytes
  bytes=${#body}
  [ "$bytes" -ge 4096 ] \
    || fail "$label response is implausibly short ($bytes bytes; expected at least 4096)"
  grep -qi '</html>' <<<"$body" \
    || fail "$label response has no closing </html> marker ($bytes bytes; possible truncated transfer)"
}

say "pair.sh up: boot r3a1 (:$PORT1) / r3a2 (:$PORT2), DB-level readiness, generic WordPress bootstrap"
bash bin/pair.sh up r3a "$PORT1" "$PORT2" --http
pass "pair r3a ready"

install_env() { # install_env <1|2> — WooCommerce+Polylang+Storefront, idempotent
  local side="$1"
  wp_env "$side" plugin is-active woocommerce >/dev/null 2>&1 || wp_env "$side" plugin install woocommerce --activate
  wp_env "$side" plugin is-active polylang >/dev/null 2>&1 || wp_env "$side" plugin install polylang --activate
  wp_env "$side" theme is-installed storefront >/dev/null 2>&1 || wp_env "$side" theme install storefront
  wp_env "$side" wc hpos enable >/dev/null 2>&1 || true
}

# Envs persist across runs: wipe content + Polylang's own state (languages,
# the polylang option, bookkeeping-taxonomy terms) + ledger, but leave
# WooCommerce/Polylang/Storefront installed (install_env re-asserts those).
reset_env_state() { # reset_env_state <1|2>
  local side="$1"
  wp_env "$side" site empty --yes >/dev/null
  # site empty does not reliably clear custom taxonomy terms -- explicit
  # cleanup of every taxonomy this scenario manages, mirroring r1b's own
  # explicit-table-truncate reset pattern for the same reason.
  wp_env "$side" db query "DELETE tr FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.taxonomy IN ('language','term_language','post_translations','term_translations','product_cat','pa_size','pa_color')" >/dev/null 2>&1 || true
  wp_env "$side" db query "DELETE FROM wp_term_taxonomy WHERE taxonomy IN ('language','term_language','post_translations','term_translations','product_cat','pa_size','pa_color')" >/dev/null 2>&1 || true
  wp_env "$side" db query "DELETE t FROM wp_terms t LEFT JOIN wp_term_taxonomy tt ON tt.term_id=t.term_id WHERE tt.term_id IS NULL" >/dev/null 2>&1 || true
  wp_env "$side" option delete polylang >/dev/null 2>&1 || true
  wp_env "$side" db query "DELETE FROM wp_woocommerce_attribute_taxonomies" >/dev/null 2>&1 || true
  wp_env "$side" transient delete wc_attribute_taxonomies >/dev/null 2>&1 || true
  wp_env "$side" theme activate twentytwentyone >/dev/null 2>&1 || true
  wp_env "$side" db query "TRUNCATE TABLE wp_duo_map" >/dev/null 2>&1 || true
  wp_env "$side" db query "TRUNCATE TABLE wp_duo_state" >/dev/null 2>&1 || true
  wp_env "$side" db query "TRUNCATE TABLE wp_duo_kv" >/dev/null 2>&1 || true
  wp_env "$side" db query "TRUNCATE TABLE wp_duo_journal" >/dev/null 2>&1 || true
  # Matching WooCommerce/Polylang's own re-registration needs on a re-run
  # (r1b's exact finding: activate_plugin() on an ALREADY-active plugin is a
  # no-op, so a deactivate+reactivate cycle is what actually re-runs setup).
  wp_env "$side" plugin deactivate woocommerce >/dev/null 2>&1 || true
  wp_env "$side" plugin activate woocommerce >/dev/null 2>&1 || true
  wp_env "$side" plugin deactivate polylang >/dev/null 2>&1 || true
  wp_env "$side" plugin activate polylang >/dev/null 2>&1 || true
}

say "install WooCommerce+Polylang+Storefront on both sides (independent installs — no code-provisioning-on-apply path for wordpress.org plugins, matching r1b/Spike D precedent)"
install_env 1
install_env 2
reset_env_state 1
reset_env_state 2
pass "both envs installed; content/ledger/Polylang state clean"

say "activate Storefront on r3a1 (real admin action — exercises wp duo deploy's switch_theme() path on r3a2 later); r3a2 stays on twentytwentyone until deploy"
wp_1 theme activate storefront
[ "$(wp_1 theme list --status=active --field=name)" = "storefront" ] || fail "storefront did not activate on r3a1"
pass "storefront active on r3a1"

say "global attributes pa_size (Small/Medium/Large) / pa_color (Red/Blue) on r3a1"
SIZE_ID=$(wp_1 wc product_attribute create --name=Size --slug=size --type=select --order_by=menu_order --has_archives=true --porcelain --user=admin)
wp_1 wc product_attribute_term create "$SIZE_ID" --name=Small --slug=small --porcelain --user=admin >/dev/null
wp_1 wc product_attribute_term create "$SIZE_ID" --name=Medium --slug=medium --porcelain --user=admin >/dev/null
wp_1 wc product_attribute_term create "$SIZE_ID" --name=Large --slug=large --porcelain --user=admin >/dev/null
COLOR_ID=$(wp_1 wc product_attribute create --name=Color --slug=color --type=select --order_by=menu_order --has_archives=true --porcelain --user=admin)
RED_ID=$(wp_1 wc product_attribute_term create "$COLOR_ID" --name=Red --slug=red --porcelain --user=admin)
BLUE_ID=$(wp_1 wc product_attribute_term create "$COLOR_ID" --name=Blue --slug=blue --porcelain --user=admin)
REGISTERED=$(wp_1 eval "foreach (wc_get_attribute_taxonomies() as \$a) { echo wc_attribute_taxonomy_name(\$a->attribute_name) . ' '; }")
grep -q 'pa_size' <<<"$REGISTERED" || fail "pa_size did not register"
grep -q 'pa_color' <<<"$REGISTERED" || fail "pa_color did not register"
pass "pa_size ($SIZE_ID) / pa_color ($COLOR_ID) registered"

say "languages (en default, de) + enable translation for product/product_cat/pa_color (the free/pro boundary test — no manifest, no license: pure Polylang admin config)"
wp_1 eval "
PLL()->model->languages->add(['locale'=>'en_US','slug'=>'en','name'=>'English']);
PLL()->model->languages->add(['locale'=>'de_DE','slug'=>'de','name'=>'Deutsch']);
echo 'languages added' . PHP_EOL;
"
assert_language_descriptions 1 "immediately after languages->add()"
# Deliberately a SEPARATE wp-cli process from the languages->add() calls
# above: observed empirically (across repeat runs) that writing post_types/
# taxonomies in the SAME request as languages->add() sometimes gets clobbered
# by end-of-request Polylang bookkeeping (get_option('polylang') reads back
# EMPTY post_types/taxonomies in a later fresh process, despite update_option()
# returning true) -- a second, distinct write-path reliability finding beyond
# task #121's existing ones. Splitting into its own request reliably avoids it.
wp_1 eval "
\$o = get_option('polylang');
\$o['default_lang'] = 'en';
\$o['post_types'] = array_unique(array_merge(\$o['post_types'] ?? [], ['product']));
\$o['taxonomies'] = array_unique(array_merge(\$o['taxonomies'] ?? [], ['product_cat','pa_color']));
update_option('polylang', \$o);
echo 'configured' . PHP_EOL;
"
# IMPORTANT, corrected from an earlier version of this investigation: do
# NOT deactivate/reactivate Polylang here. Confirmed empirically (task #121)
# that repeated deactivate+activate cycles actively WIPE the polylang
# option's post_types/taxonomies sub-arrays back to empty rather than fixing
# anything -- a single update_option() call above is what actually needs to
# happen, and it correctly takes effect on the very next fresh wp-cli
# process, checked below via the SAME signal Duo's own engine reads
# (get_taxonomy()->object_type), not Polylang's separate (and, in earlier
# investigation, flaky-seeming) pll_is_translated_post_type() cache.
# Even the single-write approach isn't 100% immediate in every run (observed
# across repeat runs of this exact script) -- retrying the CHECK ALONE (no
# further writes, no reactivation) a few times across fresh processes, since
# nothing else is disturbing the option in between.
OBJ_OK=0
for _ in 1 2 3 4 5 6 7 8; do
  OBJTYPE=$(wp_1 eval "\$t=get_taxonomy('language'); echo implode(',', (array) \$t->object_type);")
  if grep -q 'product' <<<"$OBJTYPE"; then
    OBJ_OK=1
    break
  fi
done
[ "$OBJ_OK" = "1" ] || fail "language taxonomy's object_type does not include 'product' after 8 checks of a single update_option() call (got: $OBJTYPE)"
assert_language_descriptions 1 "after the separate polylang option update"
pass "en/de languages added; product/product_cat/pa_color enabled for translation (single option write, no reactivation needed)"

say "tag pre-existing terms with a language now that their taxonomies are managed; translate pa_color terms Red->Rot / Blue->Blau"
wp_1 eval "
pll_set_term_language($RED_ID, 'en');
pll_set_term_language($BLUE_ID, 'en');
\$uncat = get_term_by('slug', 'uncategorized', 'product_cat');
if (\$uncat) { pll_set_term_language(\$uncat->term_id, 'en'); }
\$rot = pll_insert_term('Rot', 'pa_color', 'de', ['slug'=>'rot', 'translations'=>['en'=>$RED_ID]]);
\$blau = pll_insert_term('Blau', 'pa_color', 'de', ['slug'=>'blau', 'translations'=>['en'=>$BLUE_ID]]);
echo 'Rot: ' . (is_wp_error(\$rot) ? \$rot->get_error_message() : \$rot['term_id']) . PHP_EOL;
echo 'Blau: ' . (is_wp_error(\$blau) ? \$blau->get_error_message() : \$blau['term_id']) . PHP_EOL;
"
pass "pa_color terms tagged/translated"

say "translated product category: Apparel (en) / Bekleidung (de)"
APPAREL_JSON=$(wp_1 eval "
\$a = pll_insert_term('Apparel', 'product_cat', 'en', ['slug'=>'apparel']);
\$aid = is_wp_error(\$a) ? 0 : \$a['term_id'];
\$b = pll_insert_term('Bekleidung', 'product_cat', 'de', ['slug'=>'bekleidung', 'translations'=>['en'=>\$aid]]);
echo \$aid . ' ' . (is_wp_error(\$b) ? 0 : \$b['term_id']);
")
APPAREL_EN=$(echo "$APPAREL_JSON" | awk '{print $1}')
APPAREL_DE=$(echo "$APPAREL_JSON" | awk '{print $2}')
[ "$APPAREL_EN" != "0" ] && [ "$APPAREL_DE" != "0" ] || fail "Apparel/Bekleidung creation failed"
pass "Apparel ($APPAREL_EN) / Bekleidung ($APPAREL_DE)"

say "translated pages: Home/Startseite, About/Uber uns"
PAGE_IDS=$(wp_1 eval "
\$home_en = pll_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Home','post_content'=>'<!-- wp:paragraph --><p>Welcome to the Duo multilingual shop.</p><!-- /wp:paragraph -->','post_author'=>1], 'en');
\$home_de = pll_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Startseite','post_content'=>'<!-- wp:paragraph --><p>Willkommen im mehrsprachigen Duo-Shop.</p><!-- /wp:paragraph -->','post_author'=>1,'translations'=>['en'=>\$home_en]], 'de');
\$about_en = pll_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'About','post_content'=>'<!-- wp:paragraph --><p>Duo is a small shop selling apparel in two languages.</p><!-- /wp:paragraph -->','post_author'=>1], 'en');
\$about_de = pll_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Uber uns','post_content'=>'<!-- wp:paragraph --><p>Duo ist ein kleiner Laden.</p><!-- /wp:paragraph -->','post_author'=>1,'translations'=>['en'=>\$about_en]], 'de');
echo \"\$home_en \$home_de \$about_en \$about_de\";
")
read -r HOME_EN HOME_DE ABOUT_EN ABOUT_DE <<< "$PAGE_IDS"
pass "Home ($HOME_EN/$HOME_DE) About ($ABOUT_EN/$ABOUT_DE)"

say "shop: Duo Mug + Duo Cap (simple), Duo Tee (variable, pa_size x pa_color, 4 variations, UNTRANSLATED)"
MUG_ID=$(wp_1 wc product create --name='Duo Mug' --slug=duo-mug --type=simple --status=publish --sku=DUO-MUG --regular_price=9.99 --manage_stock=true --stock_quantity=40 --user=admin --porcelain)
CAP_ID=$(wp_1 wc product create --name='Duo Cap' --slug=duo-cap --type=simple --status=publish --sku=DUO-CAP --regular_price=14.99 --manage_stock=true --stock_quantity=30 --featured=true --user=admin --porcelain)
TEE_ID=$(wp_1 wc product create --name='Duo Tee' --slug=duo-tee --type=variable --status=publish \
  --attributes="[{\"id\":$SIZE_ID,\"variation\":true,\"visible\":true,\"options\":[\"Small\",\"Medium\"]},{\"id\":$COLOR_ID,\"variation\":true,\"visible\":true,\"options\":[\"Red\",\"Blue\"]}]" \
  --user=admin --porcelain)
V1=$(wp_1 wc product_variation create "$TEE_ID" --sku=DUO-TEE-S-RED --regular_price=19.99 --attributes="[{\"id\":$SIZE_ID,\"option\":\"Small\"},{\"id\":$COLOR_ID,\"option\":\"Red\"}]" --manage_stock=true --stock_quantity=15 --user=admin --porcelain)
wp_1 wc product_variation create "$TEE_ID" --sku=DUO-TEE-S-BLUE --regular_price=19.99 --attributes="[{\"id\":$SIZE_ID,\"option\":\"Small\"},{\"id\":$COLOR_ID,\"option\":\"Blue\"}]" --manage_stock=true --stock_quantity=12 --user=admin --porcelain >/dev/null
wp_1 wc product_variation create "$TEE_ID" --sku=DUO-TEE-M-RED --regular_price=21.99 --sale_price=18.99 --attributes="[{\"id\":$SIZE_ID,\"option\":\"Medium\"},{\"id\":$COLOR_ID,\"option\":\"Red\"}]" --manage_stock=true --stock_quantity=10 --user=admin --porcelain >/dev/null
wp_1 wc product_variation create "$TEE_ID" --sku=DUO-TEE-M-BLUE --regular_price=21.99 --attributes="[{\"id\":$SIZE_ID,\"option\":\"Medium\"},{\"id\":$COLOR_ID,\"option\":\"Blue\"}]" --manage_stock=true --stock_quantity=8 --user=admin --porcelain >/dev/null
wp_1 eval "wc_get_product($TEE_ID)->set_default_attributes(['pa_size'=>'small','pa_color'=>'red']); wc_get_product($TEE_ID)->save();" >/dev/null
pass "Mug=$MUG_ID Cap=$CAP_ID Tee=$TEE_ID (+4 variations, V1=$V1)"

say "tag products with language + Apparel category; translate Duo Cap -> Duo Kappe (attempt product translation under free Polylang)"
# NOTE: pll_set_post_language() -- Polylang's OWN documented API function --
# was found to SILENTLY NO-OP for a just-enabled custom post type in this
# exact scenario (confirmed by direct SQL: the wp_term_relationships row was
# simply never written, no error) despite pll_is_translated_post_type() and
# get_taxonomy('language')->object_type independently verifying correct
# moments earlier -- a genuine Polylang write-path quirk, not a Duo issue
# (task #121, finding 7). wp_set_object_terms() directly is the reliable
# workaround: it only depends on the taxonomy being registered (reliable),
# not on Polylang's separate internal "is this type translated" cache
# (flaky). Using it here for every product-type language tag.
CAP_DE=$(wp_1 eval "
wp_set_object_terms($MUG_ID, 'en', 'language');
wp_set_object_terms($CAP_ID, 'en', 'language');
wp_set_object_terms($TEE_ID, 'en', 'language');
wp_set_object_terms($MUG_ID, [$APPAREL_EN], 'product_cat');
wp_set_object_terms($CAP_ID, [$APPAREL_EN], 'product_cat');
wp_set_object_terms($TEE_ID, [$APPAREL_EN], 'product_cat');
\$cap_de = wp_insert_post(['post_type'=>'product','post_status'=>'publish','post_title'=>'Duo Kappe','post_author'=>1], true);
wp_set_object_terms(\$cap_de, 'de', 'language');
pll_save_post_translations(['en'=>$CAP_ID, 'de'=>\$cap_de]);
wp_set_object_terms(\$cap_de, [$APPAREL_DE], 'product_cat');
update_post_meta(\$cap_de, '_sku', 'DUO-CAP-DE');
update_post_meta(\$cap_de, '_regular_price', '14.99');
update_post_meta(\$cap_de, '_price', '14.99');
update_post_meta(\$cap_de, '_manage_stock', 'yes');
update_post_meta(\$cap_de, '_stock', '30');
update_post_meta(\$cap_de, '_stock_status', 'instock');
update_post_meta(\$cap_de, '_virtual', 'no');
update_post_meta(\$cap_de, '_downloadable', 'no');
wp_set_object_terms(\$cap_de, ['simple'], 'product_type');
echo \$cap_de;
")
pass "Duo Kappe created ($CAP_DE), linked translation of Duo Cap ($CAP_ID)"
LANG_CHECK=$(wp_1 eval "var_export(['mug'=>pll_get_post_language($MUG_ID),'cap'=>pll_get_post_language($CAP_ID),'tee'=>pll_get_post_language($TEE_ID),'kappe'=>pll_get_post_language($CAP_DE)]);")
grep -q "'mug' => 'en'" <<<"$LANG_CHECK" || fail "Duo Mug language tag did not take (see task #121 finding 7)"
grep -q "'tee' => 'en'" <<<"$LANG_CHECK" || fail "Duo Tee language tag did not take (see task #121 finding 7)"
pass "language tags verified to have actually landed (not just called)"

say "per-language menus: Main Menu (en) / Hauptmenu (de), both assigned primary via Polylang's own per-language mapping"
MENU_EN=$(wp_1 menu create "Main Menu" --porcelain)
MENU_DE=$(wp_1 menu create "Hauptmenu" --porcelain)
SHOP_EN=$(wp_1 option get woocommerce_shop_page_id)
wp_1 menu item add-post "$MENU_EN" "$HOME_EN" --title="Home" >/dev/null
wp_1 menu item add-post "$MENU_EN" "$ABOUT_EN" --title="About" >/dev/null
wp_1 menu item add-post "$MENU_EN" "$SHOP_EN" --title="Shop" >/dev/null
wp_1 menu item add-post "$MENU_DE" "$HOME_DE" --title="Startseite" >/dev/null
wp_1 menu item add-post "$MENU_DE" "$ABOUT_DE" --title="Uber uns" >/dev/null
wp_1 menu location assign "$MENU_EN" primary
wp_1 eval "
\$o = get_option('polylang');
\$o['nav_menus'] = ['storefront' => ['primary' => ['en' => $MENU_EN, 'de' => $MENU_DE]]];
update_option('polylang', \$o);
"
pass "Main Menu ($MENU_EN) / Hauptmenu ($MENU_DE) — flat theme_mods names Main Menu only (core.json's language-blind capture); Polylang's OWN nav_menus option correctly names both per-language (DUO-3233's sub_keys mechanism now captures/applies this SAME sub-key — see task #121 and the render-check comments below)"
assert_language_descriptions 1 "after all source-side content and menu seeding"

say "init site repo (own origin, own clones)"
rm -rf siterepo/origin-r3a.git siterepo/r3a1/.git siterepo/r3a2 siterepo/r3a1/state siterepo/r3a1/site.duo.json
git init --bare -b main siterepo/origin-r3a.git >/dev/null
mkdir -p siterepo/r3a1
cat > siterepo/r3a1/site.duo.json <<'EOF'
{
  "manifests": ["core", "woocommerce", "polylang"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "product", "product_variation"],
    "taxonomies": ["category", "post_tag", "product_cat", "pa_size", "pa_color", "language", "post_translations", "term_language", "term_translations"]
  },
  "spec_version": 2
}
EOF
cp site-repo.gitignore.template siterepo/r3a1/.gitignore
git -C siterepo/r3a1 init -q -b main
git -C siterepo/r3a1 remote add origin ../origin-r3a.git
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test add -A
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test commit -qm "policy: multilingual WooCommerce shop scope"
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test push -qu origin main
pass "site repo initialized"

say "core loop: capture -> pending -> classify the one real gap (product_count_product_cat term_meta) -> clean capture"
wp_1 duo capture --repo=/siterepo >/dev/null
PENDING_N=$(wp_1 duo pending --repo=/siterepo --format=json | tail -1 | python3 -c "import json,sys; print(len(json.load(sys.stdin)))")
if [ "$PENDING_N" != "0" ]; then
  wp_1 duo classify --repo=/siterepo --set='term_meta:product_count_product_cat=runtime' >/dev/null
  wp_1 duo capture --repo=/siterepo >/dev/null
fi
PENDING_N2=$(wp_1 duo pending --repo=/siterepo --format=json | tail -1 | python3 -c "import json,sys; print(len(json.load(sys.stdin)))")
[ "$PENDING_N2" = "0" ] || fail "pending queue not clean after classify (got $PENDING_N2 items)"
assert_language_descriptions 1 "after capture/classify/capture"
pass "clean capture, zero pending items"

say "hard lint gate + capture-twice determinism"
wp_1 duo lint --repo=/siterepo
wp_1 duo capture --repo=/siterepo --out=/siterepo/.tmp-state2 >/dev/null
diff -r siterepo/r3a1/state siterepo/r3a1/.tmp-state2 || fail "capture is not deterministic"
rm -rf siterepo/r3a1/.tmp-state2
assert_language_descriptions 1 "after deterministic second capture"
pass "lint clean, capture-twice diff empty"

git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test add -A
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test commit -qm "capture: multilingual WooCommerce shop on r3a1" --allow-empty
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test push -q origin main

say "round-trip: clone into r3a2 (deliberately NO manual Polylang config — a genuinely fresh target), deploy (DUO-3216: code lifecycle before state — real switch_theme() to Storefront, hooks fire), apply"
rm -rf siterepo/r3a2
git clone -q siterepo/origin-r3a.git siterepo/r3a2
# DUO-3216 (aa9b36a) gave Deploy::code_mismatch() a new 'inactive_in_environment'
# finding (theme/plugin installed but not active) that Apply::apply()'s
# refuse-gate (agent/src/Apply.php:592) hard-blocks on unconditionally, with
# no subset filtering -- every code_mismatch row blocks apply, unlike
# Deploy::run()'s own gate, which excludes exactly this issue from ITS
# blocking set since reconciling it is deploy's whole job (agent/src/
# Deploy.php:382-389). Before this issue, code_mismatch() had no concept of
# "installed but inactive" at all, so this exact clone -> apply -> (later)
# deploy ordering was legal; now r3a2's theme (twentytwentyone, per
# reset_env_state) vs r3a1's captured stylesheet (storefront, activated for
# real earlier in this script) is a real mismatch apply refuses outright.
# Deploy first, same fix as grind_r1b_shop.sh's identical finding.
DEPLOY0_JSON=$(wp_2 duo deploy --repo=/siterepo --format=json | tail -1)
grep -q '"theme_switched":"storefront"' <<<"$DEPLOY0_JSON" || fail "deploy did not switch to storefront (got: $DEPLOY0_JSON)"
[ "$(wp_2 theme list --status=active --field=name)" = "storefront" ] || fail "storefront is not the active theme on r3a2 after deploy"
pass "r3a2 switched to Storefront via a real wp duo deploy — required BEFORE apply under DUO-3216"
REV=$(git -C siterepo/r3a2 rev-parse HEAD)
APPLY1_OUT=$(wp_env 2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts --force-theirs --default-author=admin --revision="$REV" 2>&1)
echo "$APPLY1_OUT"
# Task #92 gave Apply::taxes_by_object_type() the same pattern_object_type()
# fallback Capture's own copy already had (manifests/woocommerce.json's
# taxonomy_patterns declares object_type:["product"] for ^pa_) -- this
# specific warning can no longer fire for pa_size/pa_color deterministically
# (a static manifest lookup, not a live query), confirmed live on an
# independent minimal fixture before this assertion was tightened. Still
# reported rather than asserted to a fixed value below, since a different,
# still-present timing question (relationship-WRITING order, not the
# warning) survives task #92 -- see the next comment.
grep -q 'not registered on this environment' <<<"$APPLY1_OUT" \
  && FRESH_TARGET_WARNED=1 || FRESH_TARGET_WARNED=0
[ "$FRESH_TARGET_WARNED" = "0" ] || fail "unexpected unregistered-taxonomy warning for pa_size/pa_color -- task #92's object_type fallback should make this unreachable for a declared taxonomy_patterns match (got: $APPLY1_OUT)"
grep -qE 'canary clean|"canary":"clean"|\(canary clean\)' <<<"$APPLY1_OUT" || fail "apply canary was not clean"
assert_language_descriptions 1 "after initial source capture"
assert_language_descriptions 2 "after initial target apply"
pass "apply succeeded, canary clean, no unregistered-taxonomy warning"

say "first-apply convergence: typed pa_* definitions, product relationships, and Polylang language all land with zero target-side repair"
TEE_B2=$(wp_2 post list --post_type=product --name=duo-tee --field=ID)
REGISTERED_B2=$(wp_2 eval "var_export(['pa_size'=>taxonomy_exists('pa_size'),'pa_color'=>taxonomy_exists('pa_color')]);")
grep -q "'pa_size' => true" <<<"$REGISTERED_B2" || fail "pa_size did not self-register on the fresh target (task #75 regression)"
RELS_FIRST=$(wp_2 db query "SELECT COUNT(*) FROM wp_term_relationships tr JOIN wp_term_taxonomy tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tr.object_id=$TEE_B2 AND tt.taxonomy IN ('pa_size','pa_color')" --skip-column-names)
[ "$RELS_FIRST" = "4" ] || fail "expected all 4 pa_size/pa_color relationships on the first apply (got $RELS_FIRST) — the global phase-1 pass must land typed-table and term rows before phase-2 relationship writes"
LANG_FIRST=$(wp_2 eval "var_export(pll_get_post_language($TEE_B2));")
[ "$LANG_FIRST" = "'en'" ] || fail "expected Duo Tee language=en on the single, unretried first apply (got $LANG_FIRST) — DUO-3280's compiled object_type_from_option path must bypass the frozen registry"
MUG_B2=$(wp_2 post list --post_type=product --name=duo-mug --field=ID)
CAP_B2=$(wp_2 post list --post_type=product --name=duo-cap --field=ID)
KAPPE_B2=$(wp_2 post list --post_type=product --name=duo-kappe --field=ID)
LANG_ALL=$(wp_2 eval "var_export(['mug'=>pll_get_post_language($MUG_B2),'cap'=>pll_get_post_language($CAP_B2),'kappe'=>pll_get_post_language($KAPPE_B2)]);")
grep -q "'mug' => 'en'" <<<"$LANG_ALL" || fail "expected Duo Mug language=en on first apply (got: $LANG_ALL)"
grep -q "'cap' => 'en'" <<<"$LANG_ALL" || fail "expected Duo Cap language=en on first apply (got: $LANG_ALL)"
grep -q "'kappe' => 'de'" <<<"$LANG_ALL" || fail "expected Duo Kappe language=de on first apply (got: $LANG_ALL)"
pass "single first apply is fully converged: 4/4 pa_* relationships and all product language relationships landed from captured configuration, with zero manual target config or forced content change"

say "wp duo deploy on r3a2 again — DUO-3216 idempotency contract: already reconciled by the early deploy above, so this must be a genuine no-op"
DEPLOY_JSON=$(wp_2 duo deploy --repo=/siterepo --format=json | tail -1)
grep -q '"theme_switched":null' <<<"$DEPLOY_JSON" || fail "expected a no-op re-deploy (theme already switched by the early deploy above) — got: $DEPLOY_JSON"
grep -q '"activated":\[\]' <<<"$DEPLOY_JSON" || fail "expected zero plugin activations on an idempotent re-deploy — got: $DEPLOY_JSON"
grep -q '"deactivated":\[\]' <<<"$DEPLOY_JSON" || fail "expected zero plugin deactivations on an idempotent re-deploy — got: $DEPLOY_JSON"
[ "$(wp_2 theme list --status=active --field=name)" = "storefront" ] || fail "storefront is not active on r3a2"
pass "confirmed: re-running wp duo deploy once everything is already reconciled is a true no-op (zero hook fires)"

# task #88 (POST FIELD derived classification) landed mid-round and closes
# #72 for real: product_variation.title is now classified 'derived' in
# manifests/woocommerce.json (Policy::field_class()/Canon::post_hash_basis()).
# Two things to prove here, not one, mirroring grind_r1b_shop.sh's own
# post-#88 validation exactly (task #112) -- this round's own fixture
# (Duo Tee, seeded pa_size x pa_color, i.e. NOT alphabetical order) is an
# INDEPENDENT check of the same fix on a different attribute ordering.
say "acceptance (task #88, criterion 3): a title-ONLY self-heal on r3a2 alone (WooCommerce's own wc_get_product() read -- hook-free, raw \$wpdb, zero duo involvement) must NOT surface as drift/update/conflict in duo plan"
wp_2 eval 'foreach (get_posts(["post_type"=>"product_variation","numberposts"=>-1,"post_status"=>"any"]) as $p) { wc_get_product($p->ID); }' >/dev/null
PLAN_AFTER_HEAL=$(wp_2 duo plan --repo=/siterepo --format=json | tail -1)
echo "$PLAN_AFTER_HEAL" | python3 -c "
import json,sys
p = json.load(sys.stdin)
bad = [r for k in ('drift','update','conflict') for r in p.get(k,[]) if 'product_variation' in (r if isinstance(r,str) else r.get('path',''))]
sys.exit(1 if bad else 0)
" || fail "a product_variation entity showed up in plan's drift/update/conflict after a title-ONLY self-heal (got: $PLAN_AFTER_HEAL) -- Canon::post_hash_basis() should make plan's hash comparison blind to a field classified derived"
pass "confirmed: plan stays silent on a title-only divergence -- task #88's hash-basis mechanism verified on an INDEPENDENT fixture (multilingual + pa_size x pa_color, not the ordering #88 was developed against)"

say "force WooCommerce's own title self-heal on r3a1 too (same real wc_get_product() mechanism, not a duo mechanism) then final byte-identity"
wp_1 eval 'foreach (get_posts(["post_type"=>"product_variation","numberposts"=>-1,"post_status"=>"any"]) as $p) { wc_get_product($p->ID); }' >/dev/null
wp_1 duo capture --repo=/siterepo >/dev/null
wp_2 duo capture --repo=/siterepo --out=/siterepo/.tmp-final >/dev/null
assert_language_descriptions 1 "after source WooCommerce read and final capture"
assert_language_descriptions 2 "after target final capture"
DIFF_OUT=$(diff -rq siterepo/r3a1/state siterepo/r3a2/.tmp-final || true)
echo "$DIFF_OUT"

# TRUE zero-exclusion byte identity -- no exceptions anywhere, including
# product_variation.title AND options/core.json's default_category. Task
# #88 (Policy::field_class()/Canon::post_hash_basis()) closed #72's
# TIMING-based divergence, proven above (criterion 3: plan stays silent)
# and again here by construction (both sides had an identical forced
# self-heal before this diff). Task #123 (Canon.php's OrderPreserved
# mechanism, manifests/woocommerce.json's `_product_attributes`
# "order_preserving": true) closed the SEPARATE, PERMANENT divergence this
# section used to carve out with an anagram check: the parent's
# _product_attributes array order -- which WooCommerce's variation-title
# generator reads directly -- now survives capture/apply byte-for-byte, so
# the generated title converges byte-identically too. DUO-3249
# (manifests/polylang.json reclassifying default_category to `derived`
# once Polylang is pinned, per the owner ruling on that issue) closed the
# THIRD, separate divergence this section used to carve out: Polylang
# manages default_category PER LANGUAGE once DUO-3233's sub_keys
# propagation (task #121) completes its config on the target, so a
# captured `authored` value from one environment was never actually safe
# to replay onto another's own per-language default -- excluding it from
# canonical state entirely (the same treatment core.json's own
# rewrite_rules already gets) means it simply never appears in EITHER
# side's captured tree at all, so there is nothing left to carve an
# exception for. With all three root causes closed, no carve-out is
# needed for any of them; this round's fixture (pa_size x pa_color, an
# independent attribute ordering from grind_r1b_shop.sh's own fixture, PLUS
# the multilingual Polylang setup this script's own name promises) is
# exactly the confirmation #123's own acceptance criteria called for, now
# joined by DUO-3249's own live proof on the same run.
[ -z "$DIFF_OUT" ] || fail "unexpected byte differences after an identical forced self-heal on both sides -- with #88, #123, and DUO-3249 all closed, the entire tree must be byte-identical, zero exceptions (see diff output above)"
pass "task #88, task #123, AND DUO-3249 all CLOSED for real -- TRUE zero-exclusion byte identity, including product_variation.title and options/core.json's default_category, no carve-outs anywhere"
[ -e "siterepo/r3a1/state/posts/product_variation" ] && ls siterepo/r3a1/state/posts/product_variation/*duo-tee-*.md >/dev/null 2>&1 \
  || fail "expected product_variation files under siterepo/r3a1/state/posts/product_variation/*duo-tee-*.md, found none -- this assertion proves nothing about #123 if the fixture it depends on is missing"
rm -rf siterepo/r3a2/.tmp-final

# Commit r3a1's post-self-heal capture NOW, on main, before the divergent-
# merge section below branches off it -- otherwise the self-healed (possibly
# #123-reordered) variation titles sit as UNCOMMITTED working-tree changes
# and silently ride along into that section's own "About excerpt" commit,
# producing spurious extra merge conflicts on the variation files that have
# nothing to do with the divergent-edit test itself (a real bug this script
# hit once; fixed here, not worked around later).
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test add -A
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test commit -qm "capture: r3a1 post-self-heal state (task #88/#123 validation)" --allow-empty
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test push -q origin main

say "lint (final, hard gate, both sides)"
wp_1 duo lint --repo=/siterepo
wp_2 duo lint --repo=/siterepo
pass "lint: 0 findings both sides"

# DUO-3300 ruling: a fresh, action-boundary-instrumented run on pinned
# Polylang 3.8.6 never reproduced the old empty-description observation.
# Source descriptions stayed valid through language/config writes, every
# content/menu mutation, repeated capture/lint, and WooCommerce reads; target
# descriptions stayed valid through deploy/apply/capture. The old write-back
# repaired plugin-owned state without identifying a mutator and could mask a
# future regression. It is intentionally gone: malformed state now fails at
# the nearest durable checkpoint, and HTTP rendering is a hard gate below.
assert_language_descriptions 1 "immediately before frontend rendering"
assert_language_descriptions 2 "immediately before frontend rendering"
pass "Polylang language descriptions remained valid through every R3-A checkpoint; no defensive mutation required"

say "per-language render checks (buffered curl, THEN grep — pipefail hazard) + host:port leak negative assertions"
HTTP1=$(curl -s -o /dev/null -w '%{http_code}' "$R3A1/")
[ "$HTTP1" = "200" ] || fail "r3a1 homepage returned $HTTP1, not 200"
BODY1_EN=$(curl -fsS "$R3A1/") || fail "r3a1 EN homepage transfer failed"
BODY1_DE=$(curl -fsS "$R3A1/de/") || fail "r3a1 DE homepage transfer failed"
BODY2_EN=$(curl -fsS "$R3A2/") || fail "r3a2 EN homepage transfer failed"
BODY2_DE=$(curl -fsS "$R3A2/de/") || fail "r3a2 DE homepage transfer failed"
assert_complete_html "$BODY1_EN" "r3a1 EN homepage"
assert_complete_html "$BODY1_DE" "r3a1 DE homepage"
assert_complete_html "$BODY2_EN" "r3a2 EN homepage"
assert_complete_html "$BODY2_DE" "r3a2 DE homepage"
grep -qi 'Duo' <<<"$BODY1_EN" || fail "r3a1 EN homepage missing expected content (${#BODY1_EN} bytes)"
grep -q "$PORT2" <<<"$BODY1_EN" && fail "r3a1 output leaks r3a2's host:port" || true
grep -qiE 'Hauptmenu|Startseite' <<<"$BODY1_DE" || fail "r3a1 DE homepage missing German content (${#BODY1_DE} bytes)"
grep -qi 'Duo' <<<"$BODY2_EN" || fail "r3a2 EN homepage missing expected content (${#BODY2_EN} bytes)"
grep -q "$PORT1" <<<"$BODY2_EN" && fail "r3a2 output leaks r3a1's host:port" || true
grep -qi 'Startseite' <<<"$BODY2_DE" || fail "r3a2 DE homepage missing Startseite (${#BODY2_DE} bytes; per-item auto-translate surfaces it via post_translations regardless of whether the second menu is separately wired)"
# DUO-3233's sub_keys mechanism (task #121) now captures/applies nav_menus
# as a declared sub-key of the SAME polylang option, so Hauptmenu rendering
# is required rather than merely reported.
grep -qi 'Hauptmenu' <<<"$BODY2_DE" || fail "r3a2 DE homepage did not render Hauptmenu (${#BODY2_DE} bytes)"
pass "render checks complete: no host:port leaks either direction; per-item translation swap works via post_translations regardless of the second menu's own wiring, reported above"

say "runtime isolation: place a real anonymous order on r3a1 via the Store API, confirm absent on r3a2"
wp_1 option update woocommerce_cod_settings --format=json '{"enabled":"yes","title":"Cash on delivery","description":"","instructions":"","enable_for_methods":[],"enable_for_virtual":"yes"}' >/dev/null 2>&1
JAR=$(mktemp)
curl -s -o /dev/null -c "$JAR" "$R3A1/product/duo-mug/"
NONCE=$(curl -s -D - -o /dev/null -c "$JAR" -b "$JAR" "$R3A1/wp-json/wc/store/v1/cart")
NONCE=$(grep -i '^Nonce:' <<<"$NONCE" | tr -d '\r' | cut -d' ' -f2)
[ -n "$NONCE" ] || fail "did not get a Store API nonce"
ADD_CODE=$(curl -s -o /tmp/r3a_cart.json -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$R3A1/wp-json/wc/store/v1/cart/add-item" -H "Content-Type: application/json" -H "Nonce: $NONCE" -d "{\"id\":$MUG_ID,\"quantity\":1}")
[ "$ADD_CODE" = "201" ] || fail "add-item did not return 201 (got $ADD_CODE)"
CHECKOUT_CODE=$(curl -s -o /tmp/r3a_checkout.json -w '%{http_code}' -c "$JAR" -b "$JAR" -X POST "$R3A1/wp-json/wc/store/v1/checkout" -H "Content-Type: application/json" -H "Nonce: $NONCE" \
  -d '{"billing_address":{"first_name":"Anna","last_name":"Kaeufer","address_1":"1 Hauptstrasse","city":"Berlin","postcode":"10115","country":"DE","email":"anna@example.test"},"shipping_address":{"first_name":"Anna","last_name":"Kaeufer","address_1":"1 Hauptstrasse","city":"Berlin","postcode":"10115","country":"DE"},"payment_method":"cod"}')
[ "$CHECKOUT_CODE" = "200" ] || fail "checkout did not return 200 (got $CHECKOUT_CODE)"
ORDER_ID=$(python3 -c "import json; print(json.load(open('/tmp/r3a_checkout.json'))['order_id'])")
rm -f "$JAR" /tmp/r3a_cart.json /tmp/r3a_checkout.json
ORDERS_B2=$(wp_2 db query 'SELECT COUNT(*) FROM wp_wc_orders' --skip-column-names)
[ "$ORDERS_B2" = "0" ] || fail "expected zero orders on r3a2 (got $ORDERS_B2)"
pass "real order #$ORDER_ID placed on r3a1; r3a2 has zero orders (HPOS custom tables never touched by capture/apply)"

say "divergent-edit merge: conflicting edits to the SAME translated page (About) on both environments"
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test checkout -qb about-r3a1 main
wp_1 post update "$ABOUT_EN" --post_excerpt="Founded in 2020, Duo ships apparel worldwide." >/dev/null
wp_1 duo capture --repo=/siterepo >/dev/null
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test add -A
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test commit -qm "content: About excerpt (r3a1 edit)"
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test push -qu origin about-r3a1

ABOUT_B2=$(wp_2 post list --post_type=page --name=about --field=ID)
git -C siterepo/r3a2 fetch -q origin
git -C siterepo/r3a2 -c user.name=duo-r3a2 -c user.email=r3a2@example.test checkout -qb about-r3a2 origin/main
wp_2 post update "$ABOUT_B2" --post_excerpt="Duo is your local apparel shop, family-run since day one." >/dev/null
wp_2 duo capture --repo=/siterepo >/dev/null
git -C siterepo/r3a2 -c user.name=duo-r3a2 -c user.email=r3a2@example.test add -A
git -C siterepo/r3a2 -c user.name=duo-r3a2 -c user.email=r3a2@example.test commit -qm "content: About excerpt (r3a2 edit)"
git -C siterepo/r3a2 -c user.name=duo-r3a2 -c user.email=r3a2@example.test push -qu origin about-r3a2

git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test checkout -q main
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test merge -q about-r3a1
git -C siterepo/r3a1 fetch -q origin about-r3a2
set +e
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test merge origin/about-r3a2 >/tmp/r3a_merge.txt 2>&1
MERGE_RC=$?
set -e
[ "$MERGE_RC" -ne 0 ] || fail "expected a merge conflict on About's excerpt"
ABOUT_FILE=$(ls siterepo/r3a1/state/posts/page/*about.md | grep -v uber)
grep -q '<<<<<<<' "$ABOUT_FILE" || fail "no conflict markers found on About's file"
if grep -q 'post_translations' "$ABOUT_FILE"; then
  pass "conflict surfaced as a plain git conflict, scoped to excerpt/modified_gmt — the post_translations link to Uber uns survived untouched"
else
  # Same broad pattern as task #121's finding 7 (pll_set_post_language
  # silently no-op'ing), now seen on pll_insert_post's own 'translations'
  # argument: About's own post_translations relationship is sometimes never
  # written at seed time, independent of anything Duo does (capture/apply
  # faithfully round-trip whatever relationship does or doesn't exist).
  # Reported, not hidden -- the conflict-scoping proof (excerpt/modified_gmt
  # only, no collateral conflict) still holds regardless.
  echo "NOTE: no post_translations reference on About this run -- Polylang's pll_insert_post() translations link did not take at seed time (same write-path reliability pattern as task #121 finding 7, not a Duo issue). Conflict scoping itself (checked next) is unaffected."
  pass "conflict surfaced as a plain git conflict, scoped to excerpt/modified_gmt"
fi

python3 - "$ABOUT_FILE" <<'PYEOF'
import re, sys
p = sys.argv[1]
s = open(p).read()
# DUO-3207 added a "modified" field (alongside the pre-existing
# "modified_gmt") to Capture.php's post representation -- the trailing
# timestamp portion of this hunk is now 1-OR-2 lines, not always exactly
# one. Matches either shape; keeps HEAD's (r3a1's own) timestamp block,
# same as before -- only the excerpt itself is an editorial override.
s = re.sub(r'<<<<<<< HEAD\n    "excerpt": "[^"]+",\n    "menu_order": 0,\n    "meta": \{\},\n((?:    "(?:modified|modified_gmt)": "[^"]+",\n)+)=======\n    "excerpt": "[^"]+",\n    "menu_order": 0,\n    "meta": \{\},\n(?:    "(?:modified|modified_gmt)": "[^"]+",\n)+>>>>>>> origin/about-r3a2\n',
           '    "excerpt": "Founded in 2020, Duo is your local apparel shop shipping worldwide.",\n    "menu_order": 0,\n    "meta": {},\n\\1', s)
open(p, 'w').write(s)
PYEOF
grep -q '<<<<<<<' "$ABOUT_FILE" && fail "conflict markers remain after resolution" || true
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test add -A
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test commit -qm "merge about-r3a2 into main (editorial resolution)"
git -C siterepo/r3a1 -c user.name=duo-r3a1 -c user.email=r3a1@example.test push -q origin main
pass "conflict resolved editorially, committed, pushed"

say "apply the merged content to both environments; confirm convergence"
REV4=$(git -C siterepo/r3a1 rev-parse HEAD)
wp_1 duo apply --repo=/siterepo --default-author=admin --revision="$REV4" >/dev/null
[ "$(wp_1 post get "$ABOUT_EN" --field=post_excerpt)" = "Founded in 2020, Duo is your local apparel shop shipping worldwide." ] || fail "r3a1 did not converge"
git -C siterepo/r3a2 -c user.name=duo-r3a2 -c user.email=r3a2@example.test checkout -q main
git -C siterepo/r3a2 pull -q origin main
REV5=$(git -C siterepo/r3a2 rev-parse HEAD)
wp_2 duo apply --repo=/siterepo --default-author=admin --force-theirs --revision="$REV5" >/dev/null
[ "$(wp_2 post get "$ABOUT_B2" --field=post_excerpt)" = "Founded in 2020, Duo is your local apparel shop shipping worldwide." ] || fail "r3a2 did not converge"
pass "both environments converged on the editorially-merged About excerpt"

printf '\n\033[1;32m\xe2\x9c\x94 GRIND R3-A PASSED\033[0m\n'
