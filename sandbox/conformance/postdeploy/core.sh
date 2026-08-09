#!/usr/bin/env bash
# DUO-3209: parent-aware adoption and ambiguous full-key refusal.
set -euo pipefail

# Reverse creation order from the source so a slug-only LIMIT 1 lookup is
# guaranteed to choose the wrong branch for at least one child.
B=$(wp_conf2 post create --post_type=page --post_title='Branch B' --post_name=branch-b --post_status=publish --porcelain)
require_fixture_ids B
B_CHILD=$(wp_conf2 post create --post_type=page --post_title='Child B target' --post_name=shared-child --post_parent="$B" --post_status=publish --porcelain)
A=$(wp_conf2 post create --post_type=page --post_title='Branch A' --post_name=branch-a --post_status=publish --porcelain)
require_fixture_ids A
A_CHILD=$(wp_conf2 post create --post_type=page --post_title='Child A target' --post_name=shared-child --post_parent="$A" --post_status=publish --porcelain)
# Each branch id is checked BEFORE the child that consumes it as
# --post_parent: an empty capture there is the one failure wp-cli would
# accept in silence (`--post_parent=` casts to 0, so the child is created
# successfully, in the wrong place, with a perfectly numeric id of its own).
require_fixture_ids B_CHILD A_CHILD

# Manufacture an otherwise-impossible duplicate full natural key directly;
# wp_insert_post() would helpfully suffix it. Planning must report the
# ambiguity instead of choosing a row by database order.
DUP=$(wp_conf2 post create --post_type=page --post_title='Ambiguous Child' --post_name=temporary-child --post_parent="$A" --post_status=publish --porcelain)
require_fixture_ids DUP
wp_conf2 db query "UPDATE wp_posts SET post_name='shared-child' WHERE ID=$DUP" >/dev/null

# DUO-3381: the premise, asserted before the behavior. Everything above is a
# `docker compose run` that can fail silently under host load (see run.sh's
# require_fixture_ids for the live case this cost), and the refusal below
# CANNOT fire unless the ambiguity actually exists in conf2's database: two
# published `shared-child` pages under branch A (the real child plus the
# renamed DUP row — this is also the only check that the raw UPDATE landed)
# and exactly one under branch B. Read back through the same table the
# planner reads, so a fixture that never landed reports itself as a fixture
# failure instead of being reported as a broken engine.
SHAPE=$(wp_conf2 db query "SELECT CONCAT(
  (SELECT COUNT(*) FROM wp_posts WHERE post_type='page' AND post_status='publish' AND post_name='shared-child' AND post_parent=$A), '/',
  (SELECT COUNT(*) FROM wp_posts WHERE post_type='page' AND post_status='publish' AND post_name='shared-child' AND post_parent=$B))" \
  --skip-column-names | tr -d '[:space:]')
require_fixture_state "conf2's ambiguous adoption key (published 'shared-child' pages under branch A ($A) / branch B ($B))" "2/1" "$SHAPE"

RC=0
OUT=$(wp_conf2 duo plan --repo=/siterepo --adopt-by-slug=posts 2>&1) || RC=$?
[ "$RC" -ne 0 ] && grep -q 'conflicting adoption key.*parent' <<<"$OUT" \
  || fail "duplicate full hierarchical adoption key was not rejected: $OUT"
wp_conf2 post delete "$DUP" --force >/dev/null

# DUO-3278: unrelated target defaults deliberately reuse every source
# counter. Planning must expose their removal, and apply must allocate the
# canonical UUIDs at free target-local counters instead of copying 21.
#
# Read the manufactured shape straight back out through WordPress's own
# option API in the SAME eval (DUO-3381 — no extra container run): these
# rows are the premise for checks/core.sh's own "colliding target widget
# defaults survived apply" and free-target-counter assertions, and a
# silently unseeded counter 21 would make both of those pass VACUOUSLY
# rather than fail — the same fixture-manufacture blind spot as above, in
# its quieter form.
WIDGET_DEFAULTS=$(wp_conf2 eval '
update_option("widget_block", [21=>["content"=>"<!-- wp:paragraph --><p>Target default block</p><!-- /wp:paragraph -->"],"_multiwidget"=>1]);
update_option("widget_text", [21=>["title"=>"Target default","text"=>"Do not merge","filter"=>false,"visual"=>true],"_multiwidget"=>1]);
update_option("widget_nav_menu", [21=>["title"=>"Target default menu","nav_menu"=>0],"_multiwidget"=>1]);
update_option("sidebars_widgets", ["sidebar-1"=>["block-21","text-21","nav_menu-21"],"wp_inactive_widgets"=>[],"array_version"=>3]);
$seeded = 0;
foreach (["widget_block", "widget_text", "widget_nav_menu"] as $option) {
    if (array_key_exists(21, (array) get_option($option))) { $seeded++; }
}
echo implode(",", (array) (get_option("sidebars_widgets")["sidebar-1"] ?? [])) . "|" . $seeded;
')
require_fixture_state "conf2's colliding widget defaults at counter 21 (DUO-3278)" \
  "block-21,text-21,nav_menu-21|3" "$WIDGET_DEFAULTS"

echo "target hierarchy and colliding widget defaults seeded; ambiguous-key refusal verified"
