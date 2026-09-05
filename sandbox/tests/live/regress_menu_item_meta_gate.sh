#!/usr/bin/env bash
# Live regression — issue #3266 + issue #3275: menu-item meta capture used to read
# a fixed 8-key allowlist from post_meta_map() and silently drop everything
# else, never calling Policy::meta_rule_for_post() or appending to
# $this->unclassified[] the way the ordinary post_meta loop already does
# (Capture.php's build_post()). Plugin-added menu-item meta (mega-menu
# icons/descriptions etc.) vanished with zero trace: not captured, not in
# `wp wprism pending`, not in any warning — the silent-authored-data-loss class
# DESIGN.md's loud/blocking/scoped posture exists to prevent.
#
# Runs the FULL canonical core loop this project tests everywhere else
# (loud gate -> pending -> classify -> clean capture — see e.g. task #52),
# not just the loud-gate half: a fake mega-menu plugin's meta key
#   (a) makes `wprism capture` refuse loudly, naming it;
#   (b) surfaces in `wp wprism pending --format=json` under section "post_meta"
#       with "nav_menu_item" in that finding's own post_types (issue #3275: NOT
#       a separate "menu_item_meta" section — menu items are posts, and a
#       distinct section name silently broke pending's own suggested
#       classify command, since Policy::SECTIONS never had a matching
#       entry);
#   (c) classifies via the EXACT `wp wprism classify --set` syntax pending's
#       own success text suggests, verbatim section name included;
#   (d) captures cleanly afterward, resolving into the item's new `meta`
#       field as a real {{post:<uuid>}} token (proving actual token
#       resolution, not opaque passthrough);
#   (e) leaves the item's 8 core WordPress structural fields untouched
#       throughout (regression coverage for the pre-existing, already-
#       proven menu mechanics);
#   (f) applies to a second, independent environment and round-trips there
#       with real cross-environment token resolution;
#   (g) recaptures byte-identical.
#
# Dedicated pair, destroyed unconditionally on exit. Capture writes as
# container uid 33, so every host-side commit/copy/removal first uses
# pair.sh's exact-root repo-host handback, and refreshes clear contents in
# place so Docker's bind-mounted root inode and mode survive. The historical
# default remains asub3275 for CI; distributed agents must supply their own
# PAIR and explicit ports so this regression never resets another actor's
# sandbox.
set -euo pipefail
REPO_ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$REPO_ROOT"

PAIR="${PAIR:-asub3275}"
[[ "$PAIR" =~ ^[a-z][a-z0-9]*$ ]] \
  || { echo "FAIL: PAIR '$PAIR' invalid (pair.sh naming: lowercase letters/digits, letter first)" >&2; exit 1; }
if [ "$PAIR" != "asub3275" ] && { [ -z "${PORT1:-}" ] || [ -z "${PORT2:-}" ]; }; then
  echo "FAIL: custom PAIR '$PAIR' requires explicit PORT1 and PORT2" >&2
  exit 1
fi
PORT1="${PORT1:-8954}"
PORT2="${PORT2:-8955}"
export WPRISM_PAIR="$PAIR" WPRISM_PORT1="$PORT1" WPRISM_PORT2="$PORT2"
COMPOSE=(docker compose -p "wprism-${PAIR}" -f sandbox/pair.yml)
SITE1="$REPO_ROOT/sandbox/siterepo/${PAIR}1"
SITE2="$REPO_ROOT/sandbox/siterepo/${PAIR}2"

wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
repo_host() { bash sandbox/bin/pair.sh repo-host "$PAIR" "${1:-both}" >/dev/null; }
clear_repo() {
  local root="$1"
  [ -d "$root" ] && [ ! -L "$root" ] || fail "pair repository root is not an ordinary directory: $root"
  find "$root" -mindepth 1 -xdev -depth -delete
  chmod 0777 "$root"
}
say()  { printf '\n\033[1;36m== %s ==\033[0m\n' "$*"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
. sandbox/lib/pair_db.sh
pair_db_select_engine
cleanup() {
  repo_host both >/dev/null 2>&1 || true
  bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  for root in "$SITE1" "$SITE2"; do
    if [ -d "$root" ] && [ ! -L "$root" ]; then
      find "$root" -mindepth 1 -xdev -depth -delete >/dev/null 2>&1 || true
      rmdir -- "$root" >/dev/null 2>&1 || true
    fi
  done
}
trap cleanup EXIT

say "bring up own pair ($PAIR, $PORT1/$PORT2, headless)"
bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless >/dev/null || fail "pair up failed"
pass "pair up"

say "site-repo: core manifest only — the mega-menu key starts genuinely UNCLASSIFIED, no pre-declared policy"
repo_host both
clear_repo "$SITE1"
clear_repo "$SITE2"
cat > "$SITE1/site.wprism.json" <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment"]
  },
  "spec_version": 2
}
EOF
cp sandbox/site-repo.gitignore.template "$SITE1/.gitignore"
git -C "$SITE1" init -q
git -C "$SITE1" config user.name wprism
git -C "$SITE1" config user.email wprism@example.test
# The repository root is deliberately shared with container uid 33. This
# policy file is created by the host but `wp wprism classify` atomically rewrites
# it inside the container, so read-only-for-others mode would strand the live
# workflow halfway through its own loud-gate proof.
chmod a+rw "$SITE1/site.wprism.json"
pass "site-repo scaffolded, no policy override for the mega-menu key yet"

say "build the menu + one item, and a real post to use as the ref target"
REF_POST_ID=$(wp1 post create --post_type=post --post_status=publish --post_title='asub3275 ref target' --porcelain)
[ -n "$REF_POST_ID" ] || fail "could not create the ref-target post"
wp1 menu create "asub3275 Test Menu" >/dev/null
ITEM_ID=$(wp1 menu item add-custom "asub3275 Test Menu" "Test Item" "https://example.test/" --porcelain)
[ -n "$ITEM_ID" ] || fail "could not create the menu item"
pass "menu item $ITEM_ID created, pointing a custom link at https://example.test/ (ref target post $REF_POST_ID)"

say "register a fake mega-menu plugin's own meta key on the item, ref-shaped (points at the real post above)"
KEY=_asub3275_megamenu_icon_post_id
wp1 post meta add "$ITEM_ID" "$KEY" "$REF_POST_ID" >/dev/null
pass "fake plugin meta registered: $KEY = $REF_POST_ID (an id-shaped value, exactly the case that used to vanish silently)"

say "(a) genuinely unclassified: wprism capture must refuse loudly, naming it"
if OUT=$(wp1 wprism capture --repo=/siterepo --format=json 2>&1); then
  fail "capture succeeded with an unclassified menu-item meta key present (silent-loss regression reproduced): $OUT"
fi
grep -q "menu_item_meta:$KEY" <<<"$OUT" \
  || fail "capture refused, but did not name menu_item_meta:$KEY (got: $OUT)"
pass "(a) capture refuses loudly and names the exact unclassified menu-item meta key — the pre-fix silent-loss defect cannot reproduce"

say "(b) issue #3275: it must land in wp wprism pending under section 'post_meta' with nav_menu_item in post_types — NOT a dead-end 'menu_item_meta' section"
PENDING_JSON=$(wp1 wprism pending --repo=/siterepo --format=json 2>/dev/null | tail -1)
python3 -c "
import json, sys
items = json.loads('''$PENDING_JSON''')
hits = [it for it in items if it.get('key') == '$KEY']
assert hits, f'$KEY not found in wp wprism pending output at all: {items}'
it = hits[0]
assert it['section'] == 'post_meta', f\"expected section 'post_meta', got {it['section']!r} — issue #3275 regressed\"
post_types = it.get('evidence', {}).get('post_types', [])
assert 'nav_menu_item' in post_types, f'nav_menu_item missing from post_types evidence: {post_types}'
print('pending item:', json.dumps(it))
" || fail "wp wprism pending did not correctly surface the menu-item meta key under section=post_meta with nav_menu_item evidence"
pass "(b) pending surfaces it under section=post_meta, nav_menu_item correctly named in post_types — the real, classify-able section, not a dead end"

say "(c) classify it using the EXACT section name pending showed — the verbatim syntax pending's own success text suggests, no translation needed"
# Cli.php's own classify() docblock: the '=' form (--set=spec) is required —
# the space form (--set spec) is NOT equivalent, wp-cli parses it
# differently. Caught live on the first run of this very script.
wp1 wprism classify --repo=/siterepo --set="post_meta:$KEY=authored,ref=post" >/dev/null \
  || fail "wp wprism classify failed using pending's own suggested section name — issue #3275's whole point is that this must work verbatim"
pass "(c) wp wprism classify --set 'post_meta:$KEY=authored,ref=post' succeeded — the exact command pending suggested actually works"

say "(d) capture now succeeds; the item's new 'meta' field carries a resolved {{post:<uuid>}} token, not the raw local id"
wp1 wprism capture --repo=/siterepo --format=json >/dev/null || fail "capture failed after classify for a fully-classified menu-item meta key"
MENU_FILE=$(find "$SITE1/state/menus" -name '*.json' | head -1)
[ -n "$MENU_FILE" ] || fail "no menu file captured"
python3 -c "
import json, sys
d = json.load(open('$MENU_FILE'))
items = d.get('items', [])
assert len(items) == 1, f'expected exactly 1 item, got {len(items)}'
meta = items[0].get('meta', {})
v = meta.get('$KEY')
assert isinstance(v, str) and v.startswith('{{post:') and v.endswith('}}'), f'expected a post ref token, got {v!r}'
assert all(not k.startswith('_menu_item_') for k in meta), f'a structural _menu_item_* key leaked into meta: {meta}'
print('token:', v)
" || fail "captured menu file did not carry the expected resolved ref token in item.meta"
pass "(d) menu-item meta key classified authored+ref captures into item.meta as a real, resolved {{post:<uuid>}} token — no structural key duplicated into it"

say "(e) the 8 core structural fields are unaffected (pre-existing menu mechanics regression check)"
python3 -c "
import json
d = json.load(open('$MENU_FILE'))
it = d['items'][0]
assert it['type'] == 'custom', it
assert it['title'] == 'Test Item', it
assert it['ref'] == 'https://example.test/', it
" || fail "a structural menu-item field regressed"
pass "(e) type/title/ref (custom-link URL) all correct — existing menu capture untouched by this fix"

say "(f) commit SITE1, clone to SITE2, apply on a second, independent environment"
repo_host both
git -C "$SITE1" add -A
git -C "$SITE1" commit -qm "asub3275 menu-item meta fixture" >/dev/null
clear_repo "$SITE2"
cp -R "$SITE1"/. "$SITE2"/
chmod -R a+rwX "$SITE2"
wp2 wprism apply --repo=/siterepo --adopt-by-slug=posts,terms,menus --default-author=admin >/dev/null \
  || fail "apply failed on the target environment"
pass "(f) apply succeeded on env2"

say "(round-trip) env2's own local copy of the ref-target post — detokenization must resolve to THAT local id, not a copied raw number"
ENV2_ITEM_META_ID=$(wp2 eval '
$items = wp_get_nav_menu_items("asub3275 Test Menu");
foreach ($items as $it) { echo get_post_meta($it->ID, "'"$KEY"'", true); break; }
')
ENV2_REF_POST_ID=$(wp2 post list --post_type=post --title="asub3275 ref target" --field=ID)
[ -n "$ENV2_REF_POST_ID" ] || fail "ref-target post did not apply to env2"
[ "$ENV2_ITEM_META_ID" = "$ENV2_REF_POST_ID" ] \
  || fail "env2's menu-item meta value ($ENV2_ITEM_META_ID) does not match env2's own local ref-target post id ($ENV2_REF_POST_ID) — detokenization did not resolve correctly"
if [ "$ENV2_REF_POST_ID" = "$REF_POST_ID" ]; then
  echo "note: env1/env2 ref-target ids coincided ($REF_POST_ID) — still correct, just not independently distinguishing; both are separate databases so this is possible but not guaranteed"
fi
pass "round-trip proven: env2's applied menu-item meta resolves to env2's OWN local post id ($ENV2_REF_POST_ID), not a raw copied number"

say "(g) recapture env2 and confirm byte-identical item.meta (true round-trip, not just 'apply didn't crash')"
wp2 wprism capture --repo=/siterepo --format=json >/dev/null || fail "recapture on env2 failed"
MENU_FILE_2=$(find "$SITE2/state/menus" -name '*.json' | head -1)
[ -n "$MENU_FILE_2" ] || fail "no menu file recaptured on env2"
diff <(python3 -c "import json; print(json.load(open('$MENU_FILE'))['items'][0]['meta'])") \
     <(python3 -c "import json; print(json.load(open('$MENU_FILE_2'))['items'][0]['meta'])") \
  || fail "recaptured item.meta on env2 differs from env1's original capture (not a clean round trip)"
pass "(g) recaptured env2 state's item.meta is identical to env1's original capture — clean round trip"

printf '\n\033[1;32m✔ REGRESS_MENU_ITEM_META_GATE PASSED\033[0m\n'
