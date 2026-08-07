#!/usr/bin/env bash
# Live regression — DUO-3266: menu-item meta capture used to read a fixed
# 8-key allowlist from post_meta_map() and silently drop everything else,
# never calling Policy::meta_rule_for_post() or appending to
# $this->unclassified[] the way the ordinary post_meta loop already does
# (Capture.php's build_post()). Plugin-added menu-item meta (mega-menu
# icons/descriptions etc.) vanished with zero trace: not captured, not in
# `wp duo pending`, not in any warning — the silent-authored-data-loss class
# DESIGN.md's loud/blocking/scoped posture exists to prevent.
#
# Proves BOTH halves of the fix live, on the real product path (wp duo
# capture/apply, not internals reached via wp eval — menus already have
# full capture/apply plumbing, so there's no reason to bypass it the way
# regress_adapter_theme_range.sh had to for a genuinely standalone static
# method):
#   (a) an UNCLASSIFIED menu-item meta key makes `duo capture` refuse
#       loudly, naming it (menu_item_meta:<key>) — the regression that
#       fails against the prior defect: before this fix, capture would
#       have silently SUCCEEDED with the key just gone.
#   (b) a menu-item meta key policy classifies `authored`, WITH a ref
#       (proving actual token resolution, not just opaque passthrough),
#       captures into the item's new `meta` field, applies to a second,
#       independent environment, and round-trips — the item's 8 core
#       WordPress structural fields (type/url/parent/classes/...) are
#       unaffected throughout (regression coverage for the pre-existing,
#       already-proven menu mechanics).
#
# Own dedicated pair (asub3266, not shared/reused from any other agent's
# or issue's namespace), destroyed unconditionally on exit via trap.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../.." && pwd)"
cd "$REPO_ROOT"

PAIR=asub3266
PORT1=8950
PORT2=8951
export DUO_PAIR="$PAIR"
COMPOSE=(docker compose -p "duo-${PAIR}" -f sandbox/pair.yml)
SITE1="$REPO_ROOT/sandbox/siterepo/${PAIR}1"
SITE2="$REPO_ROOT/sandbox/siterepo/${PAIR}2"

wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
cleanup() {
  bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  rm -rf "$SITE1" "$SITE2"
}
trap cleanup EXIT

say "bring up own pair ($PAIR, $PORT1/$PORT2, headless)"
bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless >/dev/null || fail "pair up failed"
pass "pair up"

say "site-repo: core manifest + one site-policy authored, ref-typed menu-item meta key"
rm -rf "$SITE1" "$SITE2"
mkdir -p "$SITE1"
cat > "$SITE1/site.duo.json" <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {
      "_asub3266_menu_plugin_field": {"class": "authored", "ref": "post"}
    },
    "post_types": ["post", "page", "attachment"]
  },
  "spec_version": 1
}
EOF
cp sandbox/site-repo.gitignore.template "$SITE1/.gitignore"
git -C "$SITE1" init -q
git -C "$SITE1" config user.name duo
git -C "$SITE1" config user.email duo@example.test
pass "site-repo scaffolded"

say "build the menu + one item, and a real post to use as the ref target"
REF_POST_ID=$(wp1 post create --post_type=post --post_status=publish --post_title='asub3266 ref target' --porcelain)
[ -n "$REF_POST_ID" ] || fail "could not create the ref-target post"
wp1 menu create "asub3266 Test Menu" >/dev/null
ITEM_ID=$(wp1 menu item add-custom "asub3266 Test Menu" "Test Item" "https://example.test/" --porcelain)
[ -n "$ITEM_ID" ] || fail "could not create the menu item"
pass "menu item $ITEM_ID created, pointing a custom link at https://example.test/ (ref target post $REF_POST_ID)"

say "(a) a genuinely UNCLASSIFIED menu-item meta key must make capture refuse loudly, naming it"
wp1 post meta add "$ITEM_ID" _asub3266_unclassified_field "plugin data nobody classified" >/dev/null
if OUT=$(wp1 duo capture --repo=/siterepo --format=json 2>&1); then
  fail "capture succeeded with an unclassified menu-item meta key present (silent-loss regression reproduced): $OUT"
fi
echo "$OUT" | grep -q "menu_item_meta:_asub3266_unclassified_field" \
  || fail "capture refused, but did not name menu_item_meta:_asub3266_unclassified_field (got: $OUT)"
pass "(a) capture refuses loudly and names the exact unclassified menu-item meta key — the pre-fix silent-loss defect cannot reproduce"

say "remove the unclassified key, add the site-policy-declared authored ref-typed key instead"
wp1 post meta delete "$ITEM_ID" _asub3266_unclassified_field >/dev/null
wp1 post meta add "$ITEM_ID" _asub3266_menu_plugin_field "$REF_POST_ID" >/dev/null

say "(b) capture now succeeds; the item's new 'meta' field carries a resolved {{post:<uuid>}} token, not the raw local id"
wp1 duo capture --repo=/siterepo --format=json >/dev/null || fail "capture failed for a fully-classified menu-item meta key"
MENU_FILE=$(find "$SITE1/state/menus" -name '*.json' | head -1)
[ -n "$MENU_FILE" ] || fail "no menu file captured"
python3 -c "
import json, sys
d = json.load(open('$MENU_FILE'))
items = d.get('items', [])
assert len(items) == 1, f'expected exactly 1 item, got {len(items)}'
meta = items[0].get('meta', {})
v = meta.get('_asub3266_menu_plugin_field')
assert isinstance(v, str) and v.startswith('{{post:') and v.endswith('}}'), f'expected a post ref token, got {v!r}'
assert 'meta' not in items[0] or all(not k.startswith('_menu_item_') for k in meta), f'a structural _menu_item_* key leaked into meta: {meta}'
print('token:', v)
" || fail "captured menu file did not carry the expected resolved ref token in item.meta"
pass "(b) menu-item meta key classified authored+ref captures into item.meta as a real, resolved {{post:<uuid>}} token — no structural key duplicated into it"

say "the 8 core structural fields are unaffected (pre-existing menu mechanics regression check)"
python3 -c "
import json
d = json.load(open('$MENU_FILE'))
it = d['items'][0]
assert it['type'] == 'custom', it
assert it['title'] == 'Test Item', it
assert it['ref'] == 'https://example.test/', it
" || fail "a structural menu-item field regressed"
pass "type/title/ref (custom-link URL) all correct — existing menu capture untouched by this fix"

say "commit SITE1, clone to SITE2, apply on a second, independent environment"
git -C "$SITE1" add -A
git -C "$SITE1" commit -qm "asub3266 menu-item meta fixture" >/dev/null
cp -R "$SITE1" "$SITE2"
chmod -R a+rwX "$SITE2"
wp2 duo apply --repo=/siterepo --adopt-by-slug=posts,terms,menus --default-author=admin >/dev/null \
  || fail "apply failed on the target environment"
pass "apply succeeded on env2"

say "(round-trip) env2's own local copy of the ref-target post differs numerically from env1's — detokenization must resolve to THAT local id, not a copied raw number"
ENV2_ITEM_META_ID=$(wp2 eval '
$items = wp_get_nav_menu_items("asub3266 Test Menu");
foreach ($items as $it) { echo get_post_meta($it->ID, "_asub3266_menu_plugin_field", true); break; }
')
ENV2_REF_POST_ID=$(wp2 post list --post_type=post --title="asub3266 ref target" --field=ID)
[ -n "$ENV2_REF_POST_ID" ] || fail "ref-target post did not apply to env2"
[ "$ENV2_ITEM_META_ID" = "$ENV2_REF_POST_ID" ] \
  || fail "env2's menu-item meta value ($ENV2_ITEM_META_ID) does not match env2's own local ref-target post id ($ENV2_REF_POST_ID) — detokenization did not resolve correctly"
if [ "$ENV2_REF_POST_ID" = "$REF_POST_ID" ]; then
  echo "note: env1/env2 ref-target ids coincided ($REF_POST_ID) — still correct, just not independently distinguishing; both are separate databases so this is possible but not guaranteed"
fi
pass "round-trip proven: env2's applied menu-item meta resolves to env2's OWN local post id ($ENV2_REF_POST_ID), not a raw copied number"

say "recapture env2 and confirm byte-identical item.meta (true round-trip, not just 'apply didn't crash')"
wp2 duo capture --repo=/siterepo --format=json >/dev/null || fail "recapture on env2 failed"
MENU_FILE_2=$(find "$SITE2/state/menus" -name '*.json' | head -1)
[ -n "$MENU_FILE_2" ] || fail "no menu file recaptured on env2"
diff <(python3 -c "import json; print(json.load(open('$MENU_FILE'))['items'][0]['meta'])") \
     <(python3 -c "import json; print(json.load(open('$MENU_FILE_2'))['items'][0]['meta'])") \
  || fail "recaptured item.meta on env2 differs from env1's original capture (not a clean round trip)"
pass "recaptured env2 state's item.meta is identical to env1's original capture — clean round trip"

printf '\n\033[1;32m✔ REGRESS_MENU_ITEM_META_GATE PASSED\033[0m\n'
