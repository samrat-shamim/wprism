#!/usr/bin/env bash
# Live regression — DUO-3278: sidebar-owned block/text/nav-menu widgets,
# per-type ledger identity, collision-free target counters, recovery, and
# closed/malformed option refusal. Own pair; always destroyed on exit.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/../../.." && pwd)"
cd "$ROOT"

PAIR=awid3278
PORT1=8960
PORT2=8961
export DUO_PAIR="$PAIR"
COMPOSE=(docker compose -p "duo-${PAIR}" -f sandbox/pair.yml)
SITE1="$ROOT/sandbox/siterepo/${PAIR}1"
SITE2="$ROOT/sandbox/siterepo/${PAIR}2"
wp1() { "${COMPOSE[@]}" run --rm -T cli1 wp "$@"; }
wp2() { "${COMPOSE[@]}" run --rm -T cli2 wp "$@"; }
pass() { printf '\033[1;32mok: %s\033[0m\n' "$*"; }
fail() { printf '\033[1;31mFAIL: %s\033[0m\n' "$*" >&2; exit 1; }
cleanup() {
  bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
  rm -rf "$SITE1" "$SITE2"
}
trap cleanup EXIT

# Clear only this fixture's prior footprint before Docker establishes the
# bind mounts. Replacing a mounted host directory after `up` leaves the
# container attached to the unlinked inode on Docker Desktop.
bash sandbox/bin/pair.sh destroy "$PAIR" >/dev/null 2>&1 || true
rm -rf "$SITE1" "$SITE2"
bash sandbox/bin/pair.sh up "$PAIR" "$PORT1" "$PORT2" --headless >/dev/null
pass "issue-scoped pair is ready"

mkdir -p "$SITE1"
cat > "$SITE1/site.duo.json" <<'EOF'
{
  "manifests": ["core"],
  "policy": {
    "options": {},
    "post_meta": {},
    "post_types": ["post", "page", "attachment", "wp_block"],
    "taxonomies": ["category", "post_tag"]
  },
  "spec_version": 2
}
EOF
cp sandbox/site-repo.gitignore.template "$SITE1/.gitignore"
git -C "$SITE1" init -q
git -C "$SITE1" config user.name duo
git -C "$SITE1" config user.email duo@example.test

# Remove installer content so this fixture tests widgets, not post adoption.
wp1 eval 'foreach (get_posts(["post_type"=>"any","post_status"=>"any","numberposts"=>-1]) as $p) wp_delete_post($p->ID, true);'
wp2 eval 'foreach (get_posts(["post_type"=>"any","post_status"=>"any","numberposts"=>-1]) as $p) wp_delete_post($p->ID, true);'

REUSABLE_ID=$(wp1 post create --post_type=wp_block --post_status=publish --post_title='Widget reusable' --post_content='<!-- wp:paragraph --><p>Portable reusable</p><!-- /wp:paragraph -->' --porcelain)
MENU_ID=$(wp1 menu create 'Widget Menu' --porcelain)
[ -n "$REUSABLE_ID" ] && [ -n "$MENU_ID" ] || fail "source refs could not be created"

wp1 eval "
\$ref=$REUSABLE_ID; \$menu=$MENU_ID;
update_option('widget_block', [21=>['content'=>'<!-- wp:block {\"ref\":'.\$ref.'} /-->'],99=>['content'=>'<!-- wp:paragraph --><p>Parked source-only</p><!-- /wp:paragraph -->'],'_multiwidget'=>1]);
update_option('widget_text', [22=>['title'=>'About','text'=>'Visit '.home_url('/about'),'filter'=>false,'visual'=>true],'_multiwidget'=>1]);
update_option('widget_nav_menu', [23=>['title'=>'Navigation','nav_menu'=>\$menu],'_multiwidget'=>1]);
update_option('sidebars_widgets', ['sidebar-1'=>['block-21','text-22','nav_menu-23'],'wp_inactive_widgets'=>['block-99'],'array_version'=>3]);
"

CAPTURE=$(wp1 duo capture --repo=/siterepo --format=json | tail -1) || fail "source capture failed"
echo "$CAPTURE" | jq -e '.counts.sidebar == 1 and (.warnings | any(contains("wp_inactive_widgets")))' >/dev/null \
  || fail "capture did not count the sidebar and loudly note inactive exclusion: $CAPTURE"
SIDEBAR="$SITE1/state/sidebars/sidebar-1.json"
[ -f "$SIDEBAR" ] || fail "canonical sidebar file missing"
jq -e '
  (.widgets | length == 3)
  and ([.widgets[].type] == ["block","text","nav_menu"])
  and (.widgets[] | has("uuid") and has("settings"))
  and ([.widgets[].settings | has("_duo_uuid")] | any | not)
  and (.widgets[0].settings.content | contains("{{post:"))
  and (.widgets[1].settings.text | contains("{{home}}"))
  and (.widgets[2].settings.nav_menu | startswith("{{term:"))
' "$SIDEBAR" >/dev/null || fail "sidebar wire format/ref rewriting is wrong"
pass "block/text/nav-menu capture is canonical, ordered, tokenized, and settings contain no injected identity"

ID_KIND_WIDTH=$(wp1 db query "SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_duo_map' AND COLUMN_NAME='id_kind'" --skip-column-names 2>/dev/null | tr -d '\r')
[ "$ID_KIND_WIDTH" = 32 ] || fail "duo_map.id_kind width migration did not land at 32 (got $ID_KIND_WIDTH)"
pass "id_kind schema width is migrated and budgeted for widget_media_gallery"

SIDE_HASH=$(shasum -a 256 "$SIDEBAR" | awk '{print $1}')
wp1 duo capture --repo=/siterepo --format=json >/dev/null
[ "$SIDE_HASH" = "$(shasum -a 256 "$SIDEBAR" | awk '{print $1}')" ] || fail "second capture changed sidebar bytes"
pass "capture is a fixed point"

mkdir -p "$SITE1/.duo"
IDENTITY="$SITE1/.duo/widget-identity.json"
wp1 duo identity-export --repo=/siterepo --out=/siterepo/.duo/widget-identity.json >/dev/null
jq -e '
  .format == "duo-identity-ledger/v1"
  and ([.maps[].id_kind] | index("widget_block") != null)
  and ([.maps[].id_kind] | index("widget_text") != null)
  and ([.maps[].id_kind] | index("widget_nav_menu") != null)
' "$IDENTITY" >/dev/null || fail "identity export omitted widget mappings"
pass "unchanged identity sidecar format covers every widget kind"

TEXT_UUID=$(jq -r '.widgets[] | select(.type=="text") | .uuid' "$SIDEBAR")
wp1 db query "DELETE FROM wp_duo_map WHERE uuid='$TEXT_UUID' AND id_kind='widget_text'" >/dev/null
if OUT=$(wp1 duo capture --repo=/siterepo 2>&1); then
  fail "capture silently reminted a widget after ledger loss"
fi
grep -q 'restore identity-export' <<<"$OUT" || fail "lost mapping refusal did not name recovery: $OUT"
wp1 duo identity-import --repo=/siterepo --in=/siterepo/.duo/widget-identity.json >/dev/null
wp1 duo capture --repo=/siterepo --format=json >/dev/null || fail "capture did not recover after identity import"
pass "ledger loss fails closed and identity import restores continuity"

git -C "$SITE1" add -A
git -C "$SITE1" commit -qm 'DUO-3278 widget fixture'
cp -R "$SITE1"/. "$SITE2"/
chmod -R a+rwX "$SITE2"

# Same source counters, unrelated content: these are target theme defaults,
# not the canonical widget UUIDs. Apply must allocate elsewhere and delete.
wp2 eval '
update_option("widget_block", [21=>["content"=>"<!-- wp:paragraph --><p>Target default block</p><!-- /wp:paragraph -->"],"_multiwidget"=>1]);
update_option("widget_text", [22=>["title"=>"Target default","text"=>"Do not merge","filter"=>false,"visual"=>true],"_multiwidget"=>1]);
update_option("widget_nav_menu", ["_multiwidget"=>1]);
update_option("sidebars_widgets", ["sidebar-1"=>["block-21","text-22"],"wp_inactive_widgets"=>[],"array_version"=>3]);
'
PLAN=$(wp2 duo plan --repo=/siterepo --format=json | tail -1) || fail "fresh-target plan failed"
echo "$PLAN" | jq -e '
  ([.update[],.conflict[],.drift[]] | map(select(.path=="sidebars/sidebar-1.json")) | .[0].widget_deletes | length) == 2
  and ([.update[],.conflict[],.drift[]] | map(select(.path=="sidebars/sidebar-1.json")) | .[0].widget_deletes | all(.unmanaged==true))
' >/dev/null || fail "target defaults were not plan-visible widget deletes: $PLAN"
pass "fresh target defaults surface in plan as unmanaged widget deletes"

wp2 duo apply --repo=/siterepo --adopt-by-slug=terms,posts,menus --default-author=admin >/dev/null \
  || fail "target apply failed"
TARGET_KEYS=$(wp2 eval '$s=get_option("sidebars_widgets"); echo implode(",", $s["sidebar-1"]);')
grep -q 'block-21' <<<"$TARGET_KEYS" && fail "source block counter was copied/target default survived: $TARGET_KEYS"
grep -q 'text-22' <<<"$TARGET_KEYS" && fail "source text counter was copied/target default survived: $TARGET_KEYS"
for type in block text nav_menu; do
  COUNT=$(wp2 db query "SELECT COUNT(*) FROM wp_duo_map WHERE id_kind='widget_$type'" --skip-column-names 2>/dev/null | tr -d '\r')
  [ "$COUNT" = 1 ] || fail "expected one widget_$type mapping on target, got $COUNT"
done
pass "apply allocated free per-type counters, ledger-mapped them, and removed colliding defaults"

wp2 duo capture --repo=/siterepo --format=json >/dev/null || fail "target recapture failed"
cmp "$SITE1/state/sidebars/sidebar-1.json" "$SITE2/state/sidebars/sidebar-1.json" >/dev/null \
  || fail "target sidebar did not converge byte-identically"
pass "two-environment convergence is byte-identical"

mkdir -p "$SITE2/.duo"
wp2 duo identity-export --repo=/siterepo --out=/siterepo/.duo/widget-target-identity.json >/dev/null
TARGET_TEXT_UUID=$(jq -r '.widgets[] | select(.type=="text") | .uuid' "$SITE2/state/sidebars/sidebar-1.json")
wp2 db query "DELETE FROM wp_duo_map WHERE uuid='$TARGET_TEXT_UUID' AND id_kind='widget_text'" >/dev/null
if OUT=$(wp2 duo plan --repo=/siterepo 2>&1); then
  fail "restored-target widget ledger loss did not block plan"
fi
grep -q 'Restore identity-export' <<<"$OUT" || fail "target ledger-loss refusal omitted recovery path: $OUT"
wp2 duo identity-import --repo=/siterepo --in=/siterepo/.duo/widget-target-identity.json >/dev/null
wp2 duo plan --repo=/siterepo --format=json >/dev/null || fail "target plan did not recover after identity import"
pass "restored-target ledger loss blocks plan until verified identity import"

wp2 option update widget_text 'legacy-singleton-shape' >/dev/null
if OUT=$(wp2 duo capture --repo=/siterepo 2>&1); then
  fail "malformed widget_text option was guessed instead of refused"
fi
grep -q "widget option 'widget_text'" <<<"$OUT" || fail "malformed refusal did not name widget_text: $OUT"
pass "non-multi-instance widget option refuses loudly by option name"

printf '\n\033[1;32m✔ REGRESS_WIDGETS PASSED\033[0m\n'
