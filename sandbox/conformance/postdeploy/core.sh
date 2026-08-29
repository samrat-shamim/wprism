#!/usr/bin/env bash
# issue #3209: parent-aware adoption and ambiguous full-key refusal.
set -euo pipefail

# Dirty-target rewrite premise: a real, non-empty target-native rule set for a
# different grammar. A direct SQL apply used to change permalink_structure
# while this row survived untouched, so the site continued serving /target/N/.
wp_conf2 eval '
global $wp_rewrite;
$wp_rewrite->set_permalink_structure("/target/%post_id%/");
$wp_rewrite->flush_rules(false);
$rules = get_option("rewrite_rules");
if (get_option("permalink_structure") !== "/target/%post_id%/" || !is_array($rules) || count($rules) < 1) {
    throw new RuntimeException("target permalink fixture did not reach native WordPress state");
}
' >/dev/null

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

# issue #3381: the premise, asserted before the behavior. Everything above is a
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
OUT=$(wp_conf2 wprism plan --repo=/siterepo --adopt-by-slug=posts 2>&1) || RC=$?
# issue #3391: `|| RC=$?` is what lets the assertion below inspect $OUT, and it
# is also what stops `set -e` from firing when this `docker compose run` dies
# at the docker layer with nothing but container-creation chatter in $OUT.
# Assert the invocation was answered before asserting what the answer was.
require_wprism_answered "conf2 wprism plan --adopt-by-slug=posts" human "$OUT"
[ "$RC" -ne 0 ] && grep -q 'conflicting adoption key.*parent' <<<"$OUT" \
  || fail "duplicate full hierarchical adoption key was not rejected: $OUT"
wp_conf2 post delete "$DUP" --force >/dev/null

# Production dirty-target matrix: force every core natural-identity family
# through a target-local row before the harness's explicit adoption apply.
# conformance/run.sh removes starter posts before both adapter hooks. Author
# hostile versions of WordPress's activation-default keys only on conf2 after
# the hierarchy above consumed ids, so post/page adoption can pass only by
# retaining these target-local ids and replacing their values.
TARGET_UNCAT_OLD=$(wp_conf2 term get category uncategorized --by=slug --field=term_id | tr -d '[:space:]')
require_fixture_ids TARGET_UNCAT_OLD
TARGET_HELLO=$(wp_conf2 post create --post_type=post --post_status=publish --post_name=hello-world \
  --post_title='Hostile target hello' --post_content='Target activation default must not win.' --porcelain)
TARGET_SAMPLE=$(wp_conf2 post create --post_type=page --post_status=publish --post_name=sample-page \
  --post_title='Hostile target sample' --post_content='Target sample must be explicitly adopted.' --porcelain)

# WordPress refuses deletion of the current default category. Move the pointer
# to a disposable term, replace Uncategorized, then move it back before
# deleting the disposable row. The final target has one exact activation key,
# but its id and value both diverge from the source install.
TARGET_DEFAULT_SCRATCH=$(wp_conf2 term create category 'Target Scratch Default' \
  --slug=target-scratch-default --porcelain)
require_fixture_ids TARGET_HELLO TARGET_SAMPLE TARGET_DEFAULT_SCRATCH
wp_conf2 option update default_category "$TARGET_DEFAULT_SCRATCH" >/dev/null
wp_conf2 term delete category "$TARGET_UNCAT_OLD" >/dev/null
TARGET_UNCAT=$(wp_conf2 term create category Uncategorized --slug=uncategorized \
  --description='Hostile target activation category' --porcelain)
require_fixture_ids TARGET_UNCAT
wp_conf2 option update default_category "$TARGET_UNCAT" >/dev/null
wp_conf2 term delete category "$TARGET_DEFAULT_SCRATCH" >/dev/null

# The remaining collision families use ordinary public WordPress APIs: both
# declared taxonomies, a nav menu, an attachment upload, and the active theme's
# custom_css post. Each row carries hostile content so an adoption that merely
# installs identity without materializing the repository value also fails.
TARGET_NEWS=$(wp_conf2 term create category News --slug=news \
  --description='Hostile target news' --porcelain)
TARGET_TOPIC=$(wp_conf2 term create post_tag 'Core Topic' --slug=core-topic \
  --description='Hostile target topic' --porcelain)
TARGET_MENU=$(wp_conf2 menu create 'Conformance Widget Menu' --porcelain)
require_fixture_ids TARGET_NEWS TARGET_TOPIC TARGET_MENU
wp_conf2 menu item add-custom "$TARGET_MENU" 'Target-only menu item' \
  'https://target.example.invalid/only-before-adoption' >/dev/null

cat > "$CONF_REPO2/.tmp-core-target-image.php" <<'PHP'
<?php
$im = imagecreatetruecolor(64, 48);
imagefilledrectangle($im, 0, 0, 63, 47, imagecolorallocate($im, 180, 35, 35));
imagepng($im, '/tmp/conf-core-logo.png');
PHP
TARGET_ATTACHMENT=$($COMPOSE run --rm -T cli2 bash -c \
  "wp eval-file /siterepo/.tmp-core-target-image.php >/dev/null && wp media import /tmp/conf-core-logo.png --title='Conformance Logo' --alt='Hostile target logo' --porcelain")
rm "$CONF_REPO2/.tmp-core-target-image.php"
# Source and target had each allocated custom_css at post id 10 in the first
# live dirty-target run, making the retained-target-id assertion vacuous even
# though adoption succeeded. Burn one target-local id through WordPress's
# public post API, then remove the row so the final fixture shape is unchanged.
TARGET_CUSTOM_CSS_ID_GAP=$(wp_conf2 post create --post_type=post --post_status=draft \
  --post_title='Target custom CSS id gap' --porcelain)
require_fixture_ids TARGET_CUSTOM_CSS_ID_GAP
wp_conf2 post delete "$TARGET_CUSTOM_CSS_ID_GAP" --force >/dev/null
TARGET_CUSTOM_CSS=$(wp_conf2 eval '
$post = wp_update_custom_css_post("body { background: #ff00ff; }");
if (is_wp_error($post) || !$post instanceof WP_Post) {
    throw new RuntimeException("target custom CSS fixture did not create a post");
}
echo $post->ID;
')
require_fixture_ids TARGET_ATTACHMENT TARGET_CUSTOM_CSS
TARGET_ATTACHMENT_HASH=$(wp_conf2 eval "echo hash_file('sha256', get_attached_file($TARGET_ATTACHMENT));")
require_fixture_values TARGET_ATTACHMENT_HASH

# Authored state must converge while runtime/derived/env-local state stays on
# the target. These sentinels cover option, postmeta, and comments-table
# sovereignty on rows that the same apply will otherwise update.
wp_conf2 post meta update "$A" _wp_page_template 'default' >/dev/null
wp_conf2 post meta update "$A" _edit_lock 'target-lock:77' >/dev/null
wp_conf2 post meta update "$A" _wp_old_slug 'target-old-branch-a' >/dev/null
wp_conf2 post meta update "$TARGET_HELLO" _edit_last '424242' >/dev/null
TARGET_COMMENT=$(wp_conf2 comment create --comment_post_ID="$TARGET_HELLO" \
  --comment_author='Target Runtime' --comment_author_email='runtime@example.invalid' \
  --comment_content='Target-only operational comment' --comment_approved=1 --porcelain)
require_fixture_ids TARGET_COMMENT
wp_conf2 option update blogname 'Hostile Target Blog' >/dev/null
wp_conf2 option update recently_edited '["target-only-runtime-entry"]' --format=json >/dev/null
wp_conf2 option update _wp_session_core_dirty 'target-only-session-secret' >/dev/null

DIRTY_TARGET_FILE="$CONF_REPO2/.tmp-core-dirty-target.json"
jq -n \
  --argjson branch_a "$A" --argjson hello "$TARGET_HELLO" --argjson sample "$TARGET_SAMPLE" \
  --argjson uncategorized "$TARGET_UNCAT" --argjson news "$TARGET_NEWS" \
  --argjson topic "$TARGET_TOPIC" --argjson menu "$TARGET_MENU" \
  --argjson attachment "$TARGET_ATTACHMENT" --arg attachment_hash "$TARGET_ATTACHMENT_HASH" \
  --argjson custom_css "$TARGET_CUSTOM_CSS" --argjson comment "$TARGET_COMMENT" \
  '{branch_a:$branch_a,hello:$hello,sample:$sample,uncategorized:$uncategorized,
    news:$news,topic:$topic,menu:$menu,attachment:$attachment,
    attachment_hash:$attachment_hash,custom_css:$custom_css,comment:$comment}' \
  > "$DIRTY_TARGET_FILE"

# Explicit adoption is authority, not an implicit best effort. Prove the same
# hostile target refuses before mutation when the operator omits that
# authority, then leave the harness's real apply to adopt posts, terms, and
# menus deliberately.
DIRTY_BEFORE=$(wp_conf2 eval '
global $wpdb;
$branch = get_page_by_path("branch-a", OBJECT, "page");
$hello = get_page_by_path("hello-world", OBJECT, "post");
echo hash("sha256", wp_json_encode([
    "blogname" => get_option("blogname"),
    "recently_edited" => get_option("recently_edited"),
    "session" => get_option("_wp_session_core_dirty"),
    "branch_template" => $branch ? get_post_meta($branch->ID, "_wp_page_template", true) : null,
    "branch_lock" => $branch ? get_post_meta($branch->ID, "_edit_lock", true) : null,
    "hello" => $hello ? [$hello->ID, $hello->post_title, get_post_meta($hello->ID, "_edit_last", true)] : null,
    "comments" => $hello ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID=%d", $hello->ID)) : -1,
]));
')
DIRTY_REFUSAL_RC=0
DIRTY_REFUSAL=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) \
  || DIRTY_REFUSAL_RC=$?
require_wprism_answered "conf2 core dirty target without adoption authority" human "$DIRTY_REFUSAL"
[ "$DIRTY_REFUSAL_RC" -ne 0 ] \
  && grep -Fq 'slug collisions need explicit resolution' <<<"$DIRTY_REFUSAL" \
  && grep -Fq 'posts/attachment/' <<<"$DIRTY_REFUSAL" \
  && grep -Fq 'terms/category/' <<<"$DIRTY_REFUSAL" \
  && grep -Fq 'menus/conformance-widget-menu.json' <<<"$DIRTY_REFUSAL" \
  || fail "core dirty target did not refuse every unmanaged collision family before apply: $DIRTY_REFUSAL"
DIRTY_AFTER=$(wp_conf2 eval '
global $wpdb;
$branch = get_page_by_path("branch-a", OBJECT, "page");
$hello = get_page_by_path("hello-world", OBJECT, "post");
echo hash("sha256", wp_json_encode([
    "blogname" => get_option("blogname"),
    "recently_edited" => get_option("recently_edited"),
    "session" => get_option("_wp_session_core_dirty"),
    "branch_template" => $branch ? get_post_meta($branch->ID, "_wp_page_template", true) : null,
    "branch_lock" => $branch ? get_post_meta($branch->ID, "_edit_lock", true) : null,
    "hello" => $hello ? [$hello->ID, $hello->post_title, get_post_meta($hello->ID, "_edit_last", true)] : null,
    "comments" => $hello ? (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_post_ID=%d", $hello->ID)) : -1,
]));
')
require_fixture_state "core dirty-target refusal target digest" "$DIRTY_BEFORE" "$DIRTY_AFTER"
[ "$(wp_conf2 eval 'echo null === \WPrism\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = clear ] \
  || fail "pre-mutation collision refusal retained apply_in_progress"

# issue #3278: unrelated target defaults deliberately reuse every source
# counter. Planning must expose their removal, and apply must allocate the
# canonical UUIDs at free target-local counters instead of copying 21.
#
# Read the manufactured shape straight back out through WordPress's own
# option API in the SAME eval (issue #3381 — no extra container run): these
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
require_fixture_state "conf2's colliding widget defaults at counter 21 (issue #3278)" \
  "block-21,text-21,nav_menu-21|3" "$WIDGET_DEFAULTS"

echo "target hierarchy, activation defaults, post/term/menu/media/custom-CSS collisions, target-runtime sentinels, /target/%post_id%/ rewrite state, and colliding widget defaults seeded; no-authority and ambiguous-key refusals verified"
