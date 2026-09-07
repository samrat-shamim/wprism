#!/usr/bin/env bash
# issue #3209: copied/invalid embedded identity blocks before state publication.
set -euo pipefail

# issue #3409: concurrency-safe allocation of the host `wprism explain` envs registry
# used at the issue #3345 explain slice below (sourced like _retry_helper.sh).
source "$(dirname "${BASH_SOURCE[0]}")/_explain_registry.sh"

# issue #3509: the initial apply must run core's native rewrite repair, publish a
# bounded receipt, and leave both WordPress's API and the public HTTP route on
# the source grammar. Canonical byte equality cannot see rewrite_rules because
# that row is deliberately derived, so this is the independent behavior gate.
require_observed_nonempty "initial core apply JSON" "${APPLY_JSON:-}"
jq -e '
  .canary == "clean" and
  (.warnings | any(. == "native action fired: rewrite.flush (verified)")) and
  ([.actions[]? | select(
    .manifest == "core" and .source == "native:rewrite.flush" and
    .kind == "native" and .verified == true and
    .before.permalink_present == true and .after.permalink_present == true and
    (.after.permalink_hash | test("^[a-f0-9]{64}$")) and
    (.after.rules_hash | test("^[a-f0-9]{64}$")) and
    .after.rules_hash == .after.runtime_rules_hash and
    .after.runtime_permalink_matches == true and .after.rules_count > 0
  )] | length) == 1
' <<<"$APPLY_JSON" >/dev/null \
  || fail "initial core apply lacked its one verified hash/count-only rewrite.flush receipt: $APPLY_JSON"
! grep -Fq '/journal/%postname%/' <<<"$APPLY_JSON" \
  && ! grep -Fq '/target/%post_id%/' <<<"$APPLY_JSON" \
  || fail "rewrite.flush receipt leaked source or target permalink plaintext: $APPLY_JSON"

CORE_REWRITE_STATE=$(wp_conf2 eval '
global $wp_rewrite;
$stored = get_option("rewrite_rules");
$wp_rewrite->matches = "matches";
$generated = $wp_rewrite->rewrite_rules();
$post = get_page_by_path("hello-conformance", OBJECT, "post");
echo wp_json_encode([
  "structure" => get_option("permalink_structure"),
  "stored_type" => get_debug_type($stored),
  "stored_count" => is_array($stored) ? count($stored) : -1,
  "rules_match" => is_array($stored) && $stored === $generated,
  "post_id" => $post ? (int) $post->ID : 0,
  "permalink" => $post ? get_permalink($post) : "",
  "resolved_id" => $post ? url_to_postid(get_permalink($post)) : 0,
]);
')
require_wprism_answered "conf2 core rewrite state" json "$CORE_REWRITE_STATE"
jq -e --arg port "$CONF2_PORT" '
  .structure == "/journal/%postname%/" and
  .stored_type == "array" and .stored_count > 0 and .rules_match == true and
  .post_id > 0 and .resolved_id == .post_id and
  .permalink == ("http://localhost:" + $port + "/journal/hello-conformance/")
' <<<"$CORE_REWRITE_STATE" >/dev/null \
  || fail "core rewrite state did not converge through WordPress APIs: $CORE_REWRITE_STATE"
CORE_JOURNAL_BODY=$(curl -fsSL "http://localhost:${CONF2_PORT}/journal/hello-conformance/") \
  || fail "source permalink route did not return HTTP success after apply"
grep -Fq 'Hello from the core conformance seed.' <<<"$CORE_JOURNAL_BODY" \
  || fail "source permalink route did not render the applied post"
CORE_OLD_CODE=$(curl -sS -o /dev/null -w '%{http_code}' "http://localhost:${CONF2_PORT}/target/$(jq -r '.post_id' <<<"$CORE_REWRITE_STATE")/")
[ "$CORE_OLD_CODE" != 200 ] \
  || fail "old target permalink grammar still served the post after rewrite regeneration"
pass "dirty target permalink_structure + non-empty rewrite_rules converge through one verified soft action; API resolution and HTTP behavior use only the source grammar"

# The post-deploy hook manufactured collisions across every core
# natural-identity family and proved that omitting adoption authority refuses
# before mutation. The successful harness apply then supplied posts, terms,
# and menus explicitly. Verify it claimed the existing target-local rows
# instead of deleting/recreating them or copying source ids.
DIRTY_TARGET_FILE="$CONF_REPO2/.tmp-core-dirty-target.json"
[ -f "$DIRTY_TARGET_FILE" ] || fail "core dirty-target identity evidence is missing"

core_markdown_uuid() {
  local type="$1" slug="$2" candidate="" count="" base=""
  candidate=$(find "$CONF_REPO1/state/posts/$type" -maxdepth 1 -type f \
    -name "*--${slug}.md" -print)
  count=$(printf '%s\n' "$candidate" | awk 'NF { n++ } END { print n + 0 }')
  [ "$count" = 1 ] || fail "expected one canonical $type/$slug record, found $count"
  base=${candidate##*/}
  printf '%s\n' "${base%%--*}"
}

core_json_uuid() {
  local directory="$1" slug="$2" candidate="" count=""
  candidate=$(find "$CONF_REPO1/state/$directory" -maxdepth 1 -type f \
    -name "*--${slug}.json" -print)
  count=$(printf '%s\n' "$candidate" | awk 'NF { n++ } END { print n + 0 }')
  [ "$count" = 1 ] || fail "expected one canonical $directory/$slug record, found $count"
  jq -er '.uuid | select(test("^[0-9a-f-]{36}$"))' "$candidate"
}

core_assert_adopted() {
  local uuid="$1" kind="$2" expected_target="$3" label="$4" source_local="" target_local=""
  source_local=$(wp_conf1 eval "echo (\\WPrism\\Ledger::id_for('$uuid', '$kind') ?? '__wprism_missing__');")
  target_local=$(wp_conf2 eval "echo (\\WPrism\\Ledger::id_for('$uuid', '$kind') ?? '__wprism_missing__');")
  require_fixture_values source_local target_local
  [ "$source_local" != '__wprism_missing__' ] && [ "$target_local" != '__wprism_missing__' ] \
    || fail "$label identity was not installed in both ledgers"
  [ "$target_local" = "$expected_target" ] \
    || fail "$label was recreated/copied as local id $target_local instead of adopting target id $expected_target"
  [ "$source_local" != "$target_local" ] \
    || fail "$label source and target ids did not diverge; the adoption proof is vacuous ($source_local)"
}

TARGET_BRANCH_A=$(jq -r '.branch_a' "$DIRTY_TARGET_FILE")
TARGET_HELLO=$(jq -r '.hello' "$DIRTY_TARGET_FILE")
TARGET_SAMPLE=$(jq -r '.sample' "$DIRTY_TARGET_FILE")
TARGET_UNCAT=$(jq -r '.uncategorized' "$DIRTY_TARGET_FILE")
TARGET_NEWS=$(jq -r '.news' "$DIRTY_TARGET_FILE")
TARGET_TOPIC=$(jq -r '.topic' "$DIRTY_TARGET_FILE")
TARGET_MENU=$(jq -r '.menu' "$DIRTY_TARGET_FILE")
TARGET_ATTACHMENT=$(jq -r '.attachment' "$DIRTY_TARGET_FILE")
TARGET_CUSTOM_CSS=$(jq -r '.custom_css' "$DIRTY_TARGET_FILE")
TARGET_COMMENT=$(jq -r '.comment' "$DIRTY_TARGET_FILE")
TARGET_ATTACHMENT_HASH_BEFORE=$(jq -r '.attachment_hash' "$DIRTY_TARGET_FILE")
require_fixture_ids TARGET_BRANCH_A TARGET_HELLO TARGET_SAMPLE TARGET_UNCAT TARGET_NEWS \
  TARGET_TOPIC TARGET_MENU TARGET_ATTACHMENT TARGET_CUSTOM_CSS TARGET_COMMENT
require_fixture_values TARGET_ATTACHMENT_HASH_BEFORE

UUID_BRANCH_A=$(core_markdown_uuid page branch-a)
UUID_HELLO=$(core_markdown_uuid post hello-world)
UUID_SAMPLE=$(core_markdown_uuid page sample-page)
UUID_ATTACHMENT=$(core_markdown_uuid attachment conformance-logo)
UUID_CUSTOM_CSS=$(core_markdown_uuid custom_css twentytwentyfive)
UUID_UNCAT=$(core_json_uuid terms/category uncategorized)
UUID_NEWS=$(core_json_uuid terms/category news)
UUID_TOPIC=$(core_json_uuid terms/post_tag core-topic)
UUID_MENU=$(jq -er '.uuid | select(test("^[0-9a-f-]{36}$"))' \
  "$CONF_REPO1/state/menus/conformance-widget-menu.json")

core_assert_adopted "$UUID_BRANCH_A" post "$TARGET_BRANCH_A" 'hierarchical page'
core_assert_adopted "$UUID_HELLO" post "$TARGET_HELLO" 'Hello World activation default'
core_assert_adopted "$UUID_SAMPLE" post "$TARGET_SAMPLE" 'Sample Page activation default'
core_assert_adopted "$UUID_ATTACHMENT" post "$TARGET_ATTACHMENT" 'attachment'
core_assert_adopted "$UUID_CUSTOM_CSS" post "$TARGET_CUSTOM_CSS" 'custom CSS post'
core_assert_adopted "$UUID_UNCAT" term "$TARGET_UNCAT" 'Uncategorized activation default'
core_assert_adopted "$UUID_NEWS" term "$TARGET_NEWS" 'category'
core_assert_adopted "$UUID_TOPIC" term "$TARGET_TOPIC" 'post tag'
core_assert_adopted "$UUID_MENU" term "$TARGET_MENU" 'navigation menu'

DIRTY_COUNTS=$(wp_conf2 eval '
global $wpdb;
$counts = [];
foreach ([
    ["posts", "post_name", "hello-world", "post_type", "post"],
    ["posts", "post_name", "sample-page", "post_type", "page"],
    ["posts", "post_name", "conformance-logo", "post_type", "attachment"],
    ["posts", "post_name", "twentytwentyfive", "post_type", "custom_css"],
] as [$table, $key, $value, $type_key, $type_value]) {
    $counts[] = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->$table} WHERE {$key}=%s AND {$type_key}=%s",
        $value,
        $type_value
    ));
}
foreach ([["uncategorized", "category"], ["news", "category"], ["core-topic", "post_tag"], ["conformance-widget-menu", "nav_menu"]] as [$slug, $taxonomy]) {
    $counts[] = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE t.slug=%s AND tt.taxonomy=%s",
        $slug,
        $taxonomy
    ));
}
echo implode(",", $counts);
')
require_fixture_state "core adopted natural-key cardinalities" '1,1,1,1,1,1,1,1' "$DIRTY_COUNTS"

DIRTY_AUTHORED=$(wp_conf2 eval "
\$hello = get_post($TARGET_HELLO);
\$sample = get_post($TARGET_SAMPLE);
\$branch = get_post($TARGET_BRANCH_A);
\$news = get_term($TARGET_NEWS, 'category');
\$topic = get_term($TARGET_TOPIC, 'post_tag');
\$css = get_post($TARGET_CUSTOM_CSS);
\$items = wp_get_nav_menu_items($TARGET_MENU);
echo wp_json_encode([
  'hello_title' => \$hello ? \$hello->post_title : null,
  'hello_content' => \$hello ? \$hello->post_content : null,
  'sample_title' => \$sample ? \$sample->post_title : null,
  'sample_content' => \$sample ? \$sample->post_content : null,
  'branch_template' => \$branch ? get_post_meta(\$branch->ID, '_wp_page_template', true) : null,
  'news_description' => is_wp_error(\$news) ? null : \$news->description,
  'topic_description' => is_wp_error(\$topic) ? null : \$topic->description,
  'default_category' => (int) get_option('default_category'),
  'blogname' => get_option('blogname'),
  'custom_css' => \$css ? \$css->post_content : null,
  'menu_items' => array_map(static fn(\$item) => [
    'title' => \$item->title, 'type' => \$item->type,
    'object' => \$item->object, 'object_id' => (int) \$item->object_id,
  ], is_array(\$items) ? \$items : []),
]);
")
require_wprism_answered "conf2 core adopted authored values" json "$DIRTY_AUTHORED"
jq -e --argjson news "$TARGET_NEWS" '
  .hello_title == "Hello world!" and
  (.hello_content | contains("Welcome to WordPress")) and
  .sample_title == "Sample Page" and
  (.sample_content | contains("This is an example page")) and
  .branch_template == "page-no-title" and
  .news_description == "Conformance news" and
  .topic_description == "Conformance topic" and
  .default_category == $news and
  .blogname == "WPrism Conformance" and
  .custom_css == "body { background: #3c8c3c; }" and
  (.menu_items | length) == 1 and
  .menu_items[0].title == "Home" and .menu_items[0].type == "post_type" and
  .menu_items[0].object == "page" and .menu_items[0].object_id > 0
' <<<"$DIRTY_AUTHORED" >/dev/null \
  || fail "explicit adoption retained hostile target-authored values: $DIRTY_AUTHORED"

SOURCE_ATTACHMENT_HASH=$(wp_conf1 eval "echo hash_file('sha256', get_attached_file(\\WPrism\\Ledger::id_for('$UUID_ATTACHMENT', 'post')));")
TARGET_ATTACHMENT_HASH_AFTER=$(wp_conf2 eval "echo hash_file('sha256', get_attached_file($TARGET_ATTACHMENT));")
require_fixture_values SOURCE_ATTACHMENT_HASH TARGET_ATTACHMENT_HASH_AFTER
[ "$TARGET_ATTACHMENT_HASH_AFTER" = "$SOURCE_ATTACHMENT_HASH" ] \
  || fail "adopted attachment bytes did not converge to the source upload"
[ "$TARGET_ATTACHMENT_HASH_AFTER" != "$TARGET_ATTACHMENT_HASH_BEFORE" ] \
  || fail "adopted attachment retained the hostile target upload bytes"
[ "$(wp_conf2 post meta get "$TARGET_ATTACHMENT" _wp_attachment_image_alt)" = 'Conformance logo' ] \
  || fail "adopted attachment retained the hostile target alt text"
pass "explicit adoption keeps divergent target ids while source values replace activation defaults and hostile post/page/attachment/custom-CSS/category/tag/menu rows"

DIRTY_RUNTIME=$(wp_conf2 eval "
global \$wpdb;
\$comment = \$wpdb->get_row(\$wpdb->prepare(
  \"SELECT comment_ID,comment_post_ID,comment_content FROM {\$wpdb->comments} WHERE comment_ID=%d\",
  $TARGET_COMMENT
), ARRAY_A);
echo wp_json_encode([
  'branch_lock' => get_post_meta($TARGET_BRANCH_A, '_edit_lock', true),
  'branch_old_slug' => get_post_meta($TARGET_BRANCH_A, '_wp_old_slug', true),
  'hello_edit_last' => get_post_meta($TARGET_HELLO, '_edit_last', true),
  'recently_edited' => get_option('recently_edited'),
  'session' => get_option('_wp_session_core_dirty'),
  'comment' => \$comment,
]);
")
require_wprism_answered "conf2 core target-runtime sovereignty" json "$DIRTY_RUNTIME"
jq -e --argjson hello "$TARGET_HELLO" --argjson comment "$TARGET_COMMENT" '
  .branch_lock == "target-lock:77" and
  .branch_old_slug == "target-old-branch-a" and
  .hello_edit_last == "424242" and
  .recently_edited == ["target-only-runtime-entry"] and
  .session == "target-only-session-secret" and
  (.comment.comment_ID | tonumber) == $comment and
  (.comment.comment_post_ID | tonumber) == $hello and
  .comment.comment_content == "Target-only operational comment"
' <<<"$DIRTY_RUNTIME" >/dev/null \
  || fail "core apply overwrote target-owned runtime/derived state: $DIRTY_RUNTIME"
rm "$DIRTY_TARGET_FILE"
pass "target-owned runtime option, session, postmeta, derived residue, and operational comment survive adoption without entering canonical state"

# An unreviewed option hook must refuse during action negotiation, before the
# authored commit or retry journal. NativeRewriteEffects enumerates the exact
# rewrite_rules hook topology it can prove; allowing this MU filter through as
# a dropped-write fixture would contradict that gate. The offline native-action
# suite separately drives the post-write mismatch path through its controlled
# runtime seam, while this real-WordPress leg proves the extension topology is
# blocked and removing it permits the same repository revision to converge.
wp_conf1 eval '
global $wp_rewrite;
$wp_rewrite->set_permalink_structure("/dispatch/%postname%/");
$wp_rewrite->flush_rules(false);
' >/dev/null
wp_conf1 wprism capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: core permalink retry intent'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main

CORE_REWRITE_MU_MAY_EXIST=1
remove_core_rewrite_fault() {
  $COMPOSE exec -T --user root wp2 rm -f -- /var/www/html/wp-content/mu-plugins/wprism-core-rewrite-fault.php >/dev/null 2>&1
}
cleanup_core_rewrite_fault() {
  local status=$?
  trap - EXIT
  if [ "${CORE_REWRITE_MU_MAY_EXIST:-0}" -eq 1 ]; then
    remove_core_rewrite_fault || true
  fi
  exit "$status"
}
trap cleanup_core_rewrite_fault EXIT
$COMPOSE exec -T --user root wp2 sh -c \
  'printf "%s\n" "<?php" "add_filter(\"pre_update_option_rewrite_rules\", static function (\$new, \$old) { return \$old; }, PHP_INT_MAX, 2);" > /var/www/html/wp-content/mu-plugins/wprism-core-rewrite-fault.php'
[ "$(wp_conf2 eval 'echo has_filter("pre_update_option_rewrite_rules") ? "registered" : "missing";')" = registered ] \
  || fail "core rewrite dropped-write fault filter was not registered"
CORE_REWRITE_REV_BEFORE=$(wp_conf2 db query "SELECT v FROM wp_wprism_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty "conf2 applied revision before rewrite fault" "$CORE_REWRITE_REV_BEFORE"
CORE_REWRITE_FAIL_RC=0
CORE_REWRITE_FAIL=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || CORE_REWRITE_FAIL_RC=$?
require_wprism_answered "conf2 core rewrite topology-fault apply" human "$CORE_REWRITE_FAIL"
[ "$CORE_REWRITE_FAIL_RC" -ne 0 ] \
  && grep -Fq "apply refused before target mutation — native action 'rewrite.flush' runtime is unsupported" <<<"$CORE_REWRITE_FAIL" \
  && grep -Fq 'native rewrite found extended rewrite_rules option topology' <<<"$CORE_REWRITE_FAIL" \
  || fail "unreviewed rewrite hook did not fail through the exact topology gate: $CORE_REWRITE_FAIL"
! grep -Fq '/dispatch/%postname%/' <<<"$CORE_REWRITE_FAIL" \
  && ! grep -Fq '/journal/%postname%/' <<<"$CORE_REWRITE_FAIL" \
  || fail "rewrite failure diagnostic leaked source or previous permalink plaintext: $CORE_REWRITE_FAIL"
[ "$(wp_conf2 option get permalink_structure)" = '/journal/%postname%/' ] \
  || fail "rewrite topology refusal crossed its before-target-mutation boundary"
[ "$(wp_conf2 db query "SELECT v FROM wp_wprism_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')" = "$CORE_REWRITE_REV_BEFORE" ] \
  || fail "failed required rewrite action advanced applied_revision"
[ "$(wp_conf2 eval 'echo null === \WPrism\Ledger::kv_get("apply_in_progress") ? "missing" : "retained";')" = missing ] \
  || fail "rewrite topology refusal published apply_in_progress before mutation"

remove_core_rewrite_fault
CORE_REWRITE_MU_MAY_EXIST=0
trap - EXIT
CORE_REWRITE_RETRY=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "conf2 core rewrite retry" json "$CORE_REWRITE_RETRY"
jq -e '
  .canary == "clean" and
  (.warnings | any(. == "native action fired: rewrite.flush (verified)")) and
  ([.actions[]? | select(.source == "native:rewrite.flush" and .verified == true and .after.rules_hash == .after.runtime_rules_hash)] | length) == 1
' <<<"$CORE_REWRITE_RETRY" >/dev/null \
  || fail "core rewrite retry did not converge with one verified action receipt: $CORE_REWRITE_RETRY"
[ "$(wp_conf2 eval 'echo null === \WPrism\Ledger::kv_get("apply_in_progress") ? "cleared" : "retained";')" = cleared ] \
  || fail "successful core rewrite retry retained apply_in_progress"
CORE_DISPATCH_URL=$(wp_conf2 eval '$p=get_page_by_path("hello-conformance", OBJECT, "post"); echo $p ? get_permalink($p) : "";')
require_observed_nonempty "conf2 dispatch permalink after rewrite retry" "$CORE_DISPATCH_URL"
[ "$CORE_DISPATCH_URL" = "http://localhost:${CONF2_PORT}/dispatch/hello-conformance/" ] \
  || fail "rewrite retry did not change the public permalink: $CORE_DISPATCH_URL"
CORE_DISPATCH_BODY=$(curl -fsSL "$CORE_DISPATCH_URL") \
  || fail "rewrite retry route did not return HTTP success"
grep -Fq 'Hello from the core conformance seed.' <<<"$CORE_DISPATCH_BODY" \
  || fail "rewrite retry route did not render the repository post"
CORE_REWRITE_ZERO=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "conf2 core rewrite zero-change retry" json "$CORE_REWRITE_ZERO"
jq -e '.canary == "clean" and .actions == []' <<<"$CORE_REWRITE_ZERO" >/dev/null \
  || fail "zero-change core retry fired rewrite.flush or dirtied the canary: $CORE_REWRITE_ZERO"
pass "unreviewed native rewrite hook fails before mutation, then removal permits exact convergence and a zero-change apply fires nothing"

# issue #3264: dynamic_options.theme_mods -- proof beyond the generic
# byte-diff already run above in run.sh (which only proves conf1's
# captured tokens equal conf2's captured tokens; it can't see whether the
# target's LIVE blob merged sub-keys into conf2's own pre-existing content
# correctly, or whether a previously-active theme's own row genuinely
# never entered state/ at all). These are populated-state merge assertions.
# The later direct attachment-deletion probes prove refusal/nonmutation, not
# successful sub-key deletion: that requires signed recovery authority.
CONF2_MODS=$(wp_conf2 option get theme_mods_twentytwentyfive --format=json) || true
require_observed_nonempty "conf2 theme_mods_twentytwentyfive (target observation)" "$CONF2_MODS"
echo "$CONF2_MODS" | jq -e '.background_color == "3c8c3c"' >/dev/null \
  || fail "theme_mods_twentytwentyfive.background_color did not apply correctly on conf2: $CONF2_MODS"
echo "$CONF2_MODS" | jq -e '.custom_logo | type == "number"' >/dev/null \
  || fail "theme_mods_twentytwentyfive.custom_logo did not re-resolve to a local attachment id on conf2: $CONF2_MODS"
CONF2_LOGO_ID=$(echo "$CONF2_MODS" | jq -r '.custom_logo')
CONF2_LOGO_TYPE=$(wp_conf2 post get "$CONF2_LOGO_ID" --field=post_type 2>/dev/null) || true
require_observed_nonempty "conf2 custom_logo post type" "$CONF2_LOGO_TYPE"
[ "$CONF2_LOGO_TYPE" = "attachment" ] \
  || fail "theme_mods_twentytwentyfive.custom_logo ($CONF2_LOGO_ID) does not point at a real attachment on conf2"
echo "$CONF2_MODS" | jq -e --arg port "$CONF2_PORT" '.header_image | contains("localhost:" + $port)' >/dev/null \
  || fail "theme_mods_twentytwentyfive.header_image was not rewritten to conf2's own domain: $CONF2_MODS"
echo "$CONF2_MODS" | jq -e '.header_image_data.attachment_id == .custom_logo' >/dev/null \
  || fail "theme_mods_twentytwentyfive.header_image_data.attachment_id did not re-resolve consistently with custom_logo: $CONF2_MODS"
CONF2_CSS_ID=$(echo "$CONF2_MODS" | jq -r '.custom_css_post_id')
CONF2_CSS_TYPE=$(wp_conf2 post get "$CONF2_CSS_ID" --field=post_type 2>/dev/null) || true
require_observed_nonempty "conf2 custom_css post type" "$CONF2_CSS_TYPE"
[ "$CONF2_CSS_TYPE" = "custom_css" ] \
  || fail "theme_mods_twentytwentyfive.custom_css_post_id ($CONF2_CSS_ID) does not point at a real custom_css post on conf2"
CONF2_CSS_CONTENT=$(wp_conf2 post get "$CONF2_CSS_ID" --field=post_content 2>/dev/null) || true
require_observed_nonempty "conf2 custom_css post content" "$CONF2_CSS_CONTENT"
[ "$CONF2_CSS_CONTENT" = 'body { background: #3c8c3c; }' ] \
  || fail "custom_css post content did not round-trip to conf2"
pass "theme_mods_twentytwentyfive's declared authored sub-keys (background_color, custom_logo, header_image, header_image_data, custom_css_post_id) all apply correctly on conf2, ref-typed fields re-resolved to conf2's own local ids"

echo "$CONF2_MODS" | jq -e '.sidebars_widgets.data."sidebar-1" | length > 0' >/dev/null \
  || fail "conf2's own pre-existing sidebars_widgets did not survive the theme_mods sub-key merge untouched: $CONF2_MODS"
echo "$CONF2_MODS" | jq -e '.wp_classic_sidebars."sidebar-1".name == "Footer"' >/dev/null \
  || fail "conf2's own pre-existing wp_classic_sidebars did not survive the theme_mods sub-key merge untouched: $CONF2_MODS"
pass "conf2's own runtime-excluded sub-keys (sidebars_widgets, wp_classic_sidebars) survived the merge into the live blob untouched -- sub_keys apply is a merge, never a whole-value replace"

jq -e '.records | has("theme_mods_twentytwentyone") | not' "$CONF_REPO1/state/options/core.json" >/dev/null \
  || fail "theme_mods_twentytwentyone (a previously-active theme's own row) leaked into captured state -- residue exclusion failed"
pass "theme_mods_twentytwentyone (residue: a previously-active, now-inactive theme's own row) never entered captured state, exactly as declared"

PENDING2=$(wp_conf2 wprism pending --repo=/siterepo --format=json)
require_wprism_answered "conf2 wprism pending after apply" json "$PENDING2"
[ "$PENDING2" = "[]" ] \
  || fail "wp wprism pending on conf2 is no longer empty: $PENDING2"
pass "wp wprism pending remains empty post-apply -- empty, auto-registered widget_<type> rows (every core type not covered by widgets{}) stay unscanned by design (contentless scaffolding, never captured before this issue, not captured now); issue #3278's own declared block/nav_menu/text content applied cleanly"

# The positive seed has no unreferenced parked widget. Exercise its deliberate
# exclusion warning in one named negative window, then restore the exact native
# preimage before any ordinary capture; repeated warnings are not green.
capture_wprism_json_success PARKED_BEFORE 'core parked-widget native baseline' \
  wp_conf1 eval 'echo wp_json_encode([get_option("widget_block"), get_option("sidebars_widgets")]);'
require_observed_nonempty 'core parked-widget native baseline' "$PARKED_BEFORE"
jq -e '.[0] | has("99") | not' <<<"$PARKED_BEFORE" >/dev/null \
  && jq -e '.[1].wp_inactive_widgets == []' <<<"$PARKED_BEFORE" >/dev/null \
  || fail 'core parked-widget negative fixture was already present in the positive seed'
capture_wprism_json_success PARKED_INSTALLED 'core parked-widget fixture installation' wp_conf1 eval '
$widgets = get_option("widget_block"); $sidebars = get_option("sidebars_widgets");
$widgets[99] = ["content" => "<!-- wp:paragraph --><p>Parked source-only widget</p><!-- /wp:paragraph -->"];
$sidebars["wp_inactive_widgets"] = ["block-99"];
update_option("widget_block", $widgets); update_option("sidebars_widgets", $sidebars);
if (get_option("widget_block") !== $widgets || get_option("sidebars_widgets") !== $sidebars) {
    throw new RuntimeException("core parked-widget negative fixture did not persist");
}
echo wp_json_encode(["verified" => true]);
'
jq -e '. == {verified:true}' <<<"$PARKED_INSTALLED" >/dev/null \
  || fail 'core parked-widget installation did not verify its native write'
capture_wprism_json_checked PARKED_CAPTURE 'core explicitly excluded parked-widget capture' assert_wprism_json_required_environment \
  wp_conf1 wprism capture --repo=/siterepo --format=json
jq -e '.warnings == ["unreferenced wp_inactive_widgets entries are target-owned; parked widget content will not propagate"]' \
  <<<"$PARKED_CAPTURE" >/dev/null || fail 'parked-widget negative capture did not report exactly its declared exclusion warning'
PARKED_SCAN_RC=0
rg -Fq 'Parked source-only widget' "$CONF_REPO1/state" || PARKED_SCAN_RC=$?
[ "$PARKED_SCAN_RC" -eq 1 ] \
  || fail 'parked-widget exclusion did not prove a readable canonical tree without its private fixture content'
capture_wprism_json_success PARKED_AFTER 'core parked-widget native readback' wp_conf1 eval '
$widgets = get_option("widget_block"); $sidebars = get_option("sidebars_widgets");
if (($widgets[99] ?? null) !== ["content" => "<!-- wp:paragraph --><p>Parked source-only widget</p><!-- /wp:paragraph -->"]
    || ($sidebars["wp_inactive_widgets"] ?? null) !== ["block-99"]) {
    throw new RuntimeException("core parked-widget cleanup no longer owns the exact negative fixture");
}
unset($widgets[99]); $sidebars["wp_inactive_widgets"] = [];
update_option("widget_block", $widgets); update_option("sidebars_widgets", $sidebars);
echo wp_json_encode([get_option("widget_block"), get_option("sidebars_widgets")]);
'
require_observed_nonempty 'core parked-widget native readback' "$PARKED_AFTER"
jq -en --argjson before "$PARKED_BEFORE" --argjson after "$PARKED_AFTER" '$before == $after' >/dev/null \
  || fail 'core parked-widget negative cleanup changed another native widget or sidebar value'
capture_wprism_json_checked PARKED_CLEAN_CAPTURE 'core capture after exact parked-widget cleanup' assert_wprism_json_required_environment \
  wp_conf1 wprism capture --repo=/siterepo --format=json
jq -e '.warnings == []' <<<"$PARKED_CLEAN_CAPTURE" >/dev/null \
  || fail 'core positive capture still carries warnings after parked-widget cleanup'
pass "one explicit parked-widget exclusion warns without propagating content; exact cleanup restores warning-free positive capture"

# issue #3264 <-> issue #3278 cross-PR finding, full evolution (see manifests/
# core.json's own note at dynamic_options for the complete walk-back):
# issue #3264 first shipped its OWN blocking net here (core.json
# option_namespaces for ^sidebars_widgets$/^widget_, ~18 per-name `runtime`
# classifications) believing gate_scan()'s widgets section was informational
# only. Reverted: SidebarState::capture()'s own load_widget_options() ALREADY
# has an unconditional, independent, EARLIER-firing guard for the identical
# condition (any widget_<type> row with real instances and an undeclared
# type refuses capture) -- proven live, this exact probe's own captured
# error was SidebarState's message, not the (also shipped, at the time)
# options-layer one, because SidebarState::capture() always runs before
# build_options() in build()'s own call order. The options-layer net was
# therefore provably unreachable dead weight for this family and is gone.
# What's tested below is what remains true: SidebarState's own guard is
# sufficient on its own, AND (a second, separate finding, also issue #3264)
# its FIRST shipped message advertised a remedy that didn't work --
# "classify options:widget_<type>=runtime" did nothing, since the guard
# only ever consulted widgets{}, never options.* classification. Fixed at
# the source (SidebarState::load_widget_options() now also treats an
# explicit runtime/env options classification as first-class
# acknowledgment, same tier as a widgets{} entry) rather than dropping the
# remedy from the message -- both are asserted below, live, not assumed.
say "(issue #3264 <-> issue #3278) live probe: an unknown, non-core widget type gates loudly (SidebarState's own guard), names a remedy that actually works, then classifies clean"
wp_conf1 option update widget_regress_fake_type '{"2":{"title":"Regress Fake"}}' --format=json >/dev/null

FAKE_PENDING=$(wp_conf1 wprism pending --repo=/siterepo --format=json)
require_wprism_answered "conf1 wprism pending unknown-widget probe" json "$FAKE_PENDING"
echo "$FAKE_PENDING" | jq -e 'any(.section == "widgets" and .key == "regress_fake_type")' >/dev/null \
  || fail "unknown widget type regress_fake_type did not surface in wp wprism pending's own widgets section (issue #3278's gate_scan() diagnostic): $FAKE_PENDING"

FAKE_RC=0
FAKE_CAPTURE_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || FAKE_RC=$?
# issue #3391: the `|| FAKE_RC=$?` that lets the three assertions below read
# $FAKE_CAPTURE_OUT is the same thing that keeps `set -e` from firing on a
# compose-layer death. Assert this invocation was answered at all before
# asserting anything about the answer (all three assertions read one capture).
require_wprism_answered "conf1 wprism capture (unknown widget type probe)" human "$FAKE_CAPTURE_OUT"
[ "$FAKE_RC" -ne 0 ] && echo "$FAKE_CAPTURE_OUT" | grep -q "widget option 'widget_regress_fake_type' contains instances but type 'regress_fake_type' is undeclared" \
  || fail "capture did not loudly refuse the unknown widget type via SidebarState's own guard: $FAKE_CAPTURE_OUT"
# team-lead's own requirement: this refusal must read as widgets-aware, not
# a generic "go classify it" -- both real remedies named inline.
echo "$FAKE_CAPTURE_OUT" | grep -q "add \"regress_fake_type\" to a pinned manifest's widgets{} grammar" \
  || fail "refusal did not name the first remedy (extend widgets{} grammar), or misidentified the type: $FAKE_CAPTURE_OUT"
echo "$FAKE_CAPTURE_OUT" | grep -q "declare it a deliberate exclusion (wp wprism classify --set='options:widget_regress_fake_type=runtime')" \
  || fail "refusal did not name the second remedy (deliberate exclusion): $FAKE_CAPTURE_OUT"

# The substantive gate: does the second remedy the message names ACTUALLY
# work? (Team-lead's own requirement, after the first shipped version of
# this message was proven to advertise a dead remedy.) Classify via site
# policy exactly as the message instructs, then confirm capture proceeds.
cp "$CONF_REPO1/site.wprism.json" "$CONF_REPO1/.tmp-site-backup.json"
jq '.policy.options.widget_regress_fake_type = {"class": "runtime"}' "$CONF_REPO1/site.wprism.json" > "$CONF_REPO1/.tmp-site-new.json"
mv "$CONF_REPO1/.tmp-site-new.json" "$CONF_REPO1/site.wprism.json"
# issue #3391: the only NON-refusal assertion in this family, and at risk for the
# identical reason — `|| fail` consumes the exit status, so a compose-layer
# death reaches this engine-accusing message instead of `set -e`. Captured
# (rather than discarded) purely so the answer can be asserted first; the
# accusation itself is unchanged and still keyed on the exit status alone.
REMEDY_RC=0
REMEDY_OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || REMEDY_RC=$?
require_wprism_answered "conf1 wprism capture after the classify-runtime remedy" human "$REMEDY_OUT"
if [ "$REMEDY_RC" -ne 0 ]; then
  # Capturing must not cost the operator the refusal text the uncaptured
  # shape left in the sweep log; the accusation itself is byte-unchanged.
  printf '%s\n' "$REMEDY_OUT" >&2
  fail "capture still refused widget_regress_fake_type after following the message's own stated remedy (site policy classified it runtime) -- the escape hatch does not function"
fi
mv "$CONF_REPO1/.tmp-site-backup.json" "$CONF_REPO1/site.wprism.json"
wp_conf1 option delete widget_regress_fake_type >/dev/null
wp_conf1 wprism capture --repo=/siterepo >/dev/null
pass "unknown widget type: SidebarState's own guard refuses naming BOTH remedies, the deliberate-exclusion remedy it names actually works (verified, not assumed -- the operator-path-dishonesty class this project refuses to ship), clean again once the fake type is fully removed"

# issue #3278: the core fixture's three declared widget kinds round-trip through
# the sidebar wire format with ledger-only identity and target-local counters.
SIDEBAR_FILE="$CONF_REPO1/state/sidebars/sidebar-1.json"
[ -f "$SIDEBAR_FILE" ] || fail "canonical sidebar-1 file is missing"
jq -e '
  ([.widgets[].type] == ["block","text","nav_menu"])
  and (.widgets | length == 3)
  and ([.widgets[].settings | has("_wprism_uuid")] | any | not)
  and (.widgets[0].settings.content | contains("{{post:"))
  and (.widgets[0].settings.content | contains("{{uploads}}"))
  and (.widgets[1].settings.text | contains("{{home}}"))
  and (.widgets[2].settings.nav_menu | startswith("{{term:"))
' "$SIDEBAR_FILE" >/dev/null || fail "canonical block/text/nav-menu widget wire format is wrong"

for TYPE in block text nav_menu; do
  UUID=$(jq -r --arg type "$TYPE" '.widgets[] | select(.type == $type) | .uuid' "$SIDEBAR_FILE")
  # Ledger::id_for() legitimately returns null for a missing mapping. Emit a
  # non-empty engine sentinel for that case so an exit-0 empty from a dead
  # compose invocation remains distinguishable and routes to infrastructure.
  SOURCE_LOCAL=$(wp_conf1 eval "echo (\\WPrism\\Ledger::id_for('$UUID', 'widget_$TYPE') ?? '__wprism_missing__');") || true
  require_observed_nonempty "conf1 widget_$TYPE identity ledger" "$SOURCE_LOCAL"
  TARGET_LOCAL=$(wp_conf2 eval "echo (\\WPrism\\Ledger::id_for('$UUID', 'widget_$TYPE') ?? '__wprism_missing__');") || true
  require_observed_nonempty "conf2 widget_$TYPE identity ledger" "$TARGET_LOCAL"
  [ "$SOURCE_LOCAL" != '__wprism_missing__' ] && [ "$TARGET_LOCAL" != '__wprism_missing__' ] \
    || fail "widget_$TYPE identity is absent from one environment's ledger"
  [ "$SOURCE_LOCAL" != "$TARGET_LOCAL" ] \
    || fail "widget_$TYPE copied source counter $SOURCE_LOCAL instead of allocating target-locally"
done
TARGET_KEYS=$(wp_conf2 eval '$sidebars=get_option("sidebars_widgets"); echo implode(",", $sidebars["sidebar-1"]);') || true
require_observed_nonempty "conf2 sidebar-1 widget keys (target observation)" "$TARGET_KEYS"
[[ "$TARGET_KEYS" != *-21* ]] || fail "colliding target widget defaults survived apply: $TARGET_KEYS"
wp_conf2 eval '
foreach (["block","text","nav_menu"] as $type) {
  $stored=get_option("widget_".$type);
  foreach ($stored as $settings) {
    if (is_array($settings) && array_key_exists("_wprism_uuid", $settings)) {
      throw new RuntimeException("settings UUID leaked into widget_".$type);
    }
  }
}
' >/dev/null
pass "block/text/nav-menu widgets use portable refs, ledger-only identity, free target counters, and replace target defaults"

A=$(wp_conf1 post list --post_type=page --name=branch-a --field=ID | tr -d '[:space:]')
B=$(wp_conf1 post list --post_type=page --name=branch-b --field=ID | tr -d '[:space:]')
UA=$(wp_conf1 post meta get "$A" _wprism_uuid | tr -d '[:space:]')
UB=$(wp_conf1 post meta get "$B" _wprism_uuid | tr -d '[:space:]')
# issue #3381: the duplicate-identity condition below is manufactured from
# these four READS, and `post list --field=ID` on no match — like a
# load-starved `docker compose run` — returns empty with exit 0, while
# `post meta update <id> _wprism_uuid ""` then succeeds just as silently. The
# refusal being asserted afterwards would legitimately not fire, and its
# message would report the ENGINE for a corruption this check never managed
# to author. Asserted before the write, so a failure names the right domain.
require_fixture_ids A B
require_fixture_values UA UB

wp_conf1 post meta update "$B" _wprism_uuid "$UA" >/dev/null
RC=0
OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || RC=$?
require_wprism_answered "conf1 wprism capture (duplicate _wprism_uuid probe)" human "$OUT"
[ "$RC" -ne 0 ] && grep -q "duplicate _wprism_uuid $UA.*post:$A, post:$B" <<<"$OUT" \
  || fail "copied page identity did not fail with both owners: $OUT"
[ -z "$(git -C "$CONF_REPO1" status --porcelain -- state)" ] \
  || fail "failed duplicate-identity capture changed the published state tree"

wp_conf1 post meta update "$B" _wprism_uuid 'NOT-A-UUID' >/dev/null
RC=0
OUT=$(wp_conf1 wprism capture --repo=/siterepo 2>&1) || RC=$?
require_wprism_answered "conf1 wprism capture (invalid _wprism_uuid probe)" human "$OUT"
[ "$RC" -ne 0 ] && grep -q "invalid _wprism_uuid 'NOT-A-UUID'.*post:$B" <<<"$OUT" \
  || fail "invalid embedded identity was not rejected: $OUT"
[ -z "$(git -C "$CONF_REPO1" status --porcelain -- state)" ] \
  || fail "failed invalid-identity capture changed the published state tree"

wp_conf1 post meta update "$B" _wprism_uuid "$UB" >/dev/null
wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-identity-recovered >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO1/.tmp-identity-recovered" \
  || fail "restoring the page's original UUID did not restore deterministic capture"

pass "copied and invalid _wprism_uuid metadata block before atomic state publication; original identities recover deterministically"

# issue #3345 (plan naming + three-way conflict slices): both sides edit the
# same named WordPress entity after their shared base. The public JSON must
# identify base/repository/target roles and safe choices without serializing
# raw entity values; the human renderer must make those roles actionable.
# The explicit override then stays report-not-hide and converges the target,
# leaving the pair synchronized for the deletion scenarios below.
wp_conf2 post update "$(wp_conf2 post list --post_type=page --name=branch-a --field=ID | tr -d '[:space:]')" \
  --post_title='Target Environment Intent For Conflict' >/dev/null
wp_conf1 post update "$A" --post_title='Branch Repository Intent For Conflict' >/dev/null
wp_conf1 wprism capture --repo=/siterepo >/dev/null
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: branch-vs-target conflict intent'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
TITLE_PLAN=$(wp_conf2 wprism plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "conf2 wprism plan unforced conflict" json "$TITLE_PLAN"
jq -e --arg uuid "$UA" \
  '.conflict | any(
    .uuid == $uuid
    and .title == "Branch Repository Intent For Conflict"
    and .conflict_view.format == "wprism-plan-conflict/v1"
    and .conflict_view.kind == "concurrent_change"
    and .conflict_view.reason_code == "repository_and_target_changed_since_base"
    and .conflict_view.base.role == "last_synced"
    and .conflict_view.base.source == "wprism_state"
    and .conflict_view.base.state == "present"
    and (.conflict_view.base.content_hash | test("^[a-f0-9]{64}$"))
    and .conflict_view.repository.role == "repository_intent"
    and .conflict_view.repository.source == "compiled_repository"
    and .conflict_view.repository.intent == "update"
    and (.conflict_view.repository.content_hash | test("^[a-f0-9]{64}$"))
    and .conflict_view.repository.expected_base_hash == .conflict_view.base.content_hash
    and .conflict_view.repository.intent_receipt_hash == null
    and .conflict_view.target.role == "target_observation"
    and .conflict_view.target.source == "live_target_snapshot"
    and .conflict_view.target.intent == "preserve_target_change"
    and .conflict_view.target.state == "present"
    and (.conflict_view.target.content_hash | test("^[a-f0-9]{64}$"))
    and .conflict_view.repository.content_hash != .conflict_view.base.content_hash
    and .conflict_view.target.content_hash != .conflict_view.base.content_hash
    and .conflict_view.repository.content_hash != .conflict_view.target.content_hash
    and .conflict_view.recommended_choice == "reconcile_in_repository"
    and (.conflict_view.choices | any(.id == "reconcile_in_repository" and .destructive == false))
    and (.conflict_view.choices | any(.id == "apply_repository" and .destructive == true and .requires == ["--force-theirs"] and .effect == "replace_target_authored_state"))
  )' <<<"$TITLE_PLAN" >/dev/null \
  || fail "planned conflict row does not carry exact base/repository/target intent and safe choices: $TITLE_PLAN"
! grep -q 'Target Environment Intent For Conflict' <<<"$TITLE_PLAN" \
  || fail "plan JSON leaked the target's raw conflicting title instead of hash-only evidence: $TITLE_PLAN"
TITLE_HUMAN=$(wp_conf2 wprism plan --repo=/siterepo)
require_wprism_answered "conf2 wprism plan unforced conflict human view" human "$TITLE_HUMAN"
grep -qE "^CONFLICT +.*'Branch Repository Intent For Conflict'" <<<"$TITLE_HUMAN" \
  || fail "human plan line does not show the WordPress title: $TITLE_HUMAN"
for NEEDLE in \
  'WHY repository_and_target_changed_since_base' \
  'BASE last-synced: present sha256:' \
  'REPOSITORY intent=update state=sha256:' \
  'TARGET observation: intent=preserve_target_change state=present sha256:' \
  'SAFE CHOICE reconcile_in_repository:' \
  'DESTRUCTIVE OVERRIDE apply_repository (--force-theirs): replace target authored state'; do
  grep -Fq "$NEEDLE" <<<"$TITLE_HUMAN" \
    || fail "human conflict view is missing '$NEEDLE': $TITLE_HUMAN"
done

# issue #3345 slice 4: the host-level public explain path re-observes this exact
# conflict under a strict SELECT-only boundary. The selector printed by human
# plan is hash-safe; the explanation is a separate value-free schema and may
# not inherit plan's ledger maintenance or provider/action authority.
EXPLAIN_ENTITY_HASH=$(printf '%s' "$UA" | shasum -a 256 | awk '{print $1}')
EXPLAIN_SELECTOR="conflict:sha256:$EXPLAIN_ENTITY_HASH"
grep -Fq "EXPLAIN wp wprism explain $EXPLAIN_SELECTOR --repo=<repo>" <<<"$TITLE_HUMAN" \
  || fail "human plan did not print the copyable hash-safe explain selector: $TITLE_HUMAN"
# issue #3409: a per-run private directory (portable across GNU/BSD mktemp — see
# _explain_registry.sh), with the trap installed BEFORE the first write so an
# interrupt cannot leave the temp namespace occupied for a later sweep.
EXPLAIN_REGISTRY_DIR=$(alloc_explain_registry_dir)
EXPLAIN_TOOTH_OPTION_MAY_EXIST=0
EXPLAIN_MU_MAY_EXIST=0
remove_strict_explain_mu() {
  local output rc
  # Prefer the already-running web container. If compose exec cannot answer,
  # start a one-shot, dependency-free root command against the same volume so
  # cleanup still works when the cli service is the failed leg.
  if output=$($COMPOSE exec -T --user root wp2 sh -c \
    'rm -f -- /var/www/html/wp-content/mu-plugins/wprism-explain-offload-guard.php /var/www/html/wp-content/mu-plugins/wprism-explain-cron-freeze.php; printf "%s\\n" cleaned' 2>/dev/null); then
    rc=0
  else
    rc=$?
  fi
  if [ "$rc" -eq 0 ] && grep -Fqx 'cleaned' <<<"$output"; then
    return 0
  fi
  if output=$($COMPOSE run --rm -T --no-deps --user root wp2 sh -c \
    'rm -f -- /var/www/html/wp-content/mu-plugins/wprism-explain-offload-guard.php /var/www/html/wp-content/mu-plugins/wprism-explain-cron-freeze.php; printf "%s\\n" cleaned' 2>/dev/null); then
    rc=0
  else
    rc=$?
  fi
  [ "$rc" -eq 0 ] && grep -Fqx 'cleaned' <<<"$output"
}
cleanup_strict_explain() {
  local status=$?
  trap - EXIT
  # The option is the only durable database mutation in this window. Best-effort
  # cleanup is deliberately attempted from the EXIT path too: a compose
  # command can return non-zero after the write has reached MySQL, leaving
  # the normal forward delete unreachable. The MU files get the same
  # exec-then-one-shot cleanup attempt; if the Docker daemon itself is dead,
  # only pair destroy (not pair reset) removes their persistent webroot volume.
  if [ "${EXPLAIN_TOOTH_OPTION_MAY_EXIST:-0}" -eq 1 ]; then
    wp_conf2 option delete wprism_explain_mutation_tooth >/dev/null 2>&1 || true
  fi
  if [ "${EXPLAIN_MU_MAY_EXIST:-0}" -eq 1 ]; then
    remove_strict_explain_mu || true
  fi
  rm -rf -- "${EXPLAIN_REGISTRY_DIR:-}"
  exit "$status"
}
trap cleanup_strict_explain EXIT
EXPLAIN_REGISTRY="$EXPLAIN_REGISTRY_DIR/envs.json"
jq -n --arg compose "$PWD/pair.yml" '{envs:{target:{transport:"docker",compose_file:$compose,service:"cli2",repo_path:"/siterepo"}}}' \
  >"$EXPLAIN_REGISTRY"
# A real attachment is in scope. Register an offload adapter hook that would
# abort if strict explain contacted it; local media is already present, so the
# observation must bypass provider-owned code entirely. The persistent web
# volume is root-owned, so install the fixture through the pair's exact owned
# web container, then prove WordPress actually registered it before relying on
# the negative invocation assertion.
EXPLAIN_MU_MAY_EXIST=1
$COMPOSE exec -T --user root wp2 sh -c \
  'printf "%s\n" "<?php" "add_filter(\"wprism_attachment_capture_source\", static function () { throw new RuntimeException(\"WPRISM_EXPLAIN_OFFLOAD_HOOK_WAS_INVOKED\"); });" > /var/www/html/wp-content/mu-plugins/wprism-explain-offload-guard.php'
[ "$(wp_conf2 eval 'echo has_filter("wprism_attachment_capture_source") ? "registered" : "missing";')" = 'registered' ] \
  || fail "the throwing attachment-offload premise hook was not registered"
# issue #3410: this proof hashes the WHOLE target database before/after two host
# `wprism explain` invocations and asserts equality to show explain is SELECT-only.
# It false-failed intermittently ("strict explain changed the target database")
# because UNRELATED asynchronous WordPress state — not any explain write —
# entered the hash window. Two WP-core mechanisms were identified live (see the
# issue #3410 reproduction), both firing on ANY wp-cli WordPress boot, and every
# hash and every explain here IS a wp-cli boot of conf2:
#   1. WP-Cron: with due events pending (a fresh install has several), spawn_cron()
#      rewrites the `_transient_doing_cron` option with a fresh microtime() on each
#      boot — the dominant culprit (two idle exports seconds apart differed there).
#   2. Lazy transient GC: reading an expired transient DELETEs its `_transient_*`
#      rows on read, so an expiry landing mid-window mutates wp_options.
# Neither is an explain write. Quiesce both across the whole window: clear expired
# transients (site- and network-scoped) so lazy GC has nothing to collect, and
# freeze WP-Cron for every boot via a scoped DISABLE_WP_CRON mu-plugin (installed
# through the pair's own owned web container, like the offload guard above). The
# tooth is untouched: the comparison stays a byte-exact hash of the ENTIRE database
# with NO row filtering, so an explain write to ANY row still fails — a positive
# control at the end proves that live. Both MU files remain cleanup-owned until
# their answered removal; if the Docker daemon is unavailable even to the
# one-shot fallback, pair destroy is required because pair reset preserves the
# webroot volumes.
wp_conf2 transient delete --expired >/dev/null 2>&1 || true
wp_conf2 transient delete --expired --network >/dev/null 2>&1 || true
$COMPOSE exec -T --user root wp2 sh -c \
  'printf "%s\n" "<?php" "if (!defined(\"DISABLE_WP_CRON\")) { define(\"DISABLE_WP_CRON\", true); }" > /var/www/html/wp-content/mu-plugins/wprism-explain-cron-freeze.php'
[ "$(wp_conf2 eval 'echo (defined("DISABLE_WP_CRON") && DISABLE_WP_CRON) ? "frozen" : "live";')" = 'frozen' ] \
  || fail "the WP-Cron freeze premise (DISABLE_WP_CRON) was not active for the strict-explain read-only window"
# issue #3413: premise-assert the before-export carried bytes BEFORE hashing, so an
# empty-at-exit-0 compose run is named as infrastructure rather than silently
# hashing to the empty-string digest and later reading as an engine mutation.
EXPLAIN_DB_BEFORE_SQL=$(wp_conf2 db export - --skip-comments --single-transaction 2>/dev/null)
require_observed_nonempty "conf2 db export (before strict explain)" "$EXPLAIN_DB_BEFORE_SQL"
EXPLAIN_DB_BEFORE=$(printf '%s' "$EXPLAIN_DB_BEFORE_SQL" | shasum -a 256 | awk '{print $1}')
EXPLAIN_REPO_BEFORE=$(git -C "$CONF_REPO2" status --porcelain --untracked-files=all)
EXPLAIN_RC=0
# issue #3413: capture each explain invocation's stderr (into the issue #3409 per-run
# registry dir, cleaned below) and read it into a var immediately, so the
# refusal accusation can paste rc+stdout+stderr instead of dropping stderr to
# /dev/null. The human invocation's refusals land ONLY on stderr (its healthy
# framing is `EXPLAIN CONFLICT …`, not wp-cli's), so without this a refusal is
# undiagnosable from the sweep log.
EXPLAIN_JSON=$(php ../cli/wprism --envs-file="$EXPLAIN_REGISTRY" explain target "$EXPLAIN_SELECTOR" --format=json 2>"$EXPLAIN_REGISTRY_DIR/json.stderr") \
  || EXPLAIN_RC=$?
EXPLAIN_JSON_ERR=$(cat "$EXPLAIN_REGISTRY_DIR/json.stderr" 2>/dev/null || true)
EXPLAIN_HUMAN=''
EXPLAIN_HUMAN_ERR=''
if [ "$EXPLAIN_RC" -eq 0 ]; then
  EXPLAIN_HUMAN=$(php ../cli/wprism --envs-file="$EXPLAIN_REGISTRY" explain target "$EXPLAIN_SELECTOR" 2>"$EXPLAIN_REGISTRY_DIR/human.stderr") \
    || EXPLAIN_RC=$?
  EXPLAIN_HUMAN_ERR=$(cat "$EXPLAIN_REGISTRY_DIR/human.stderr" 2>/dev/null || true)
fi
$COMPOSE exec -T --user root wp2 rm -f -- /var/www/html/wp-content/mu-plugins/wprism-explain-offload-guard.php
# issue #3410: fingerprint the target while WP-Cron is STILL frozen (a db export is
# itself a wp-cli boot), so no post-window boot can churn `_transient_doing_cron`
# back into the AFTER hash. Then stage the mutation-tooth positive control under
# the SAME quiescing, and only THEN lift the freeze — so every fail-prone
# assertion below runs after the window is fully closed. (issue #3413 premise still
# applies: an empty-at-exit-0 export is named infrastructure, not a mutation.)
EXPLAIN_DB_AFTER_SQL=$(wp_conf2 db export - --skip-comments --single-transaction 2>/dev/null)
require_observed_nonempty "conf2 db export (after strict explain)" "$EXPLAIN_DB_AFTER_SQL"
EXPLAIN_DB_AFTER=$(printf '%s' "$EXPLAIN_DB_AFTER_SQL" | shasum -a 256 | awk '{print $1}')
# Positive control (acceptance item): staged under the identical quiescing the
# proof used, a real durable write to a NON-transient option must still move the
# whole-database fingerprint. If it does not, the quiescing has blinded the tooth
# and a genuine explain write could pass unseen — the deferred assertion at the
# end of this proof refuses on exactly that.
wp_conf2 transient delete --expired >/dev/null 2>&1 || true
wp_conf2 transient delete --expired --network >/dev/null 2>&1 || true
EXPLAIN_TOOTH_BEFORE_SQL=$(wp_conf2 db export - --skip-comments --single-transaction 2>/dev/null)
require_observed_nonempty "conf2 db export (mutation-tooth before)" "$EXPLAIN_TOOTH_BEFORE_SQL"
EXPLAIN_TOOTH_BEFORE=$(printf '%s' "$EXPLAIN_TOOTH_BEFORE_SQL" | shasum -a 256 | awk '{print $1}')
EXPLAIN_TOOTH_OPTION_MAY_EXIST=1
wp_conf2 option update wprism_explain_mutation_tooth conformance-check >/dev/null \
  || fail "issue #3410 mutation-tooth self-test could not stage its durable probe write"
EXPLAIN_TOOTH_AFTER_RC=0
EXPLAIN_TOOTH_AFTER_SQL=$(wp_conf2 db export - --skip-comments --single-transaction 2>/dev/null) \
  || EXPLAIN_TOOTH_AFTER_RC=$?
# Remove the durable tooth immediately after collecting its bytes, before the
# premise check below can abort on an empty-at-exit-0 observation. The EXIT
# cleanup above covers the separate non-zero/partial-write failure shape.
EXPLAIN_TOOTH_DELETE_RC=0
EXPLAIN_TOOTH_DELETE_OUT=$(wp_conf2 option delete wprism_explain_mutation_tooth 2>/dev/null) \
  || EXPLAIN_TOOTH_DELETE_RC=$?
[ "$EXPLAIN_TOOTH_DELETE_RC" -eq 0 ] && [ -n "$EXPLAIN_TOOTH_DELETE_OUT" ] \
  || fail "infrastructure failure: conf2 mutation-tooth cleanup was not answered (rc=$EXPLAIN_TOOTH_DELETE_RC)"
EXPLAIN_TOOTH_OPTION_MAY_EXIST=0
[ "$EXPLAIN_TOOTH_AFTER_RC" -eq 0 ] \
  || fail "infrastructure failure: conf2 db export (mutation-tooth after) failed (rc=$EXPLAIN_TOOTH_AFTER_RC)"
require_observed_nonempty "conf2 db export (mutation-tooth after)" "$EXPLAIN_TOOTH_AFTER_SQL"
EXPLAIN_TOOTH_AFTER=$(printf '%s' "$EXPLAIN_TOOTH_AFTER_SQL" | shasum -a 256 | awk '{print $1}')
# issue #3410/3424: lift both MU files only after the strict-explain and tooth
# observations have answered. The helper verifies that either the running
# container or a one-shot root fallback actually executed the removal.
remove_strict_explain_mu \
  || fail "infrastructure failure: strict-explain MU cleanup was not answered"
EXPLAIN_MU_MAY_EXIST=0
rm -rf -- "$EXPLAIN_REGISTRY_DIR"
trap - EXIT
# The json invocation is safe to gate: the host CLI's refusal envelope goes to
# STDOUT (cli/wprism's wants_agent_refusal_json path), so a genuine refusal still
# reaches the accusation below while a compose-layer death (empty stdout)
# names infrastructure. The HUMAN invocation above is deliberately ungated —
# its healthy framing is `EXPLAIN CONFLICT …`, not wp-cli's, and its refusals
# land on the dropped stderr (issue #3413 owns capturing that).
require_wprism_answered "host wprism explain (json envelope)" json "$EXPLAIN_JSON"
# issue #3413: paste rc + both streams of both invocations so a refusal is
# diagnosable from the sweep log (the human refusal lands on stderr).
[ "$EXPLAIN_RC" -eq 0 ] \
  || fail "public host wprism explain refused a valid current selector (rc=$EXPLAIN_RC) -- json=${EXPLAIN_JSON:-<empty>} | json-stderr=${EXPLAIN_JSON_ERR:-<empty>} | human=${EXPLAIN_HUMAN:-<empty>} | human-stderr=${EXPLAIN_HUMAN_ERR:-<empty>}"
jq -e --arg selector "$EXPLAIN_SELECTOR" --arg entity_hash "$EXPLAIN_ENTITY_HASH" '
  .format == "wprism-explain/v1"
  and .ok == true
  and .selector.bucket == "conflict"
  and .selector.entity_identity_sha256 == $entity_hash
  and .selector.copyable == $selector
  and (.basis.artifact_sha256 | test("^[a-f0-9]{64}$"))
  and (.basis.revision_sha256 | test("^[a-f0-9]{64}$"))
  and .action.bucket == "conflict"
  and .action.reason_code == "repository_and_target_changed_since_base"
  and .source.kind == "canonical_entity"
  and .source.path == "posts/page/<identity>.md"
  and .source.content_binding == "compiled_artifact"
  and (.rules | length) > 0
  and (.references.status == "none_declared" or .references.status == "declared")
  and .execution.mutation.apply_eligibility == "blocked"
  and .execution.rebuild_surfaces == []
  and .execution.actions == []
  and .execution.provider_negotiation == "not_performed"
  and .execution.action_invocation == "not_performed"
  and .verification[0].verifier == "canonical-recapture/v1"
  and .verification[0].when == "not_scheduled"
  and .redaction.canonical_values == "omitted"' <<<"$EXPLAIN_JSON" >/dev/null \
  || fail "public explain did not return the bounded source/rule/reference/action/verification contract: $EXPLAIN_JSON"
for FORBIDDEN in \
  "$UA" \
  'Branch Repository Intent For Conflict' \
  'Target Environment Intent For Conflict' \
  '/siterepo'; do
  ! grep -Fq "$FORBIDDEN" <<<"$EXPLAIN_JSON$EXPLAIN_HUMAN" \
    || fail "public explain leaked raw entity/value/path evidence"
done
for NEEDLE in \
  "EXPLAIN CONFLICT posts/page/<identity>.md" \
  "selector: $EXPLAIN_SELECTOR" \
  'intent: preserve_target_state (blocked)' \
  'structured actions: none selected by this row' \
  'values: omitted'; do
  grep -Fq "$NEEDLE" <<<"$EXPLAIN_HUMAN" \
    || fail "human explain is missing '$NEEDLE': $EXPLAIN_HUMAN"
done
# issue #3413: paste both digests into the mutation accusation so it is diagnosable
# (was neither hash nor diff). EXPLAIN_DB_AFTER was captured above under the same
# WP-Cron/transient quiescing as EXPLAIN_DB_BEFORE (issue #3410), while frozen.
[ "$EXPLAIN_DB_AFTER" = "$EXPLAIN_DB_BEFORE" ] \
  || fail "strict explain changed the target database: before=$EXPLAIN_DB_BEFORE after=$EXPLAIN_DB_AFTER"
EXPLAIN_REPO_AFTER=$(git -C "$CONF_REPO2" status --porcelain --untracked-files=all)
[ "$EXPLAIN_REPO_AFTER" = "$EXPLAIN_REPO_BEFORE" ] \
  || fail "strict explain changed the target repository: $(diff <(printf '%s\n' "$EXPLAIN_REPO_BEFORE") <(printf '%s\n' "$EXPLAIN_REPO_AFTER") | tr '\n' ' ')"
# issue #3410 mutation-tooth positive control, deferred to here so the freeze is
# already lifted before any fail: under the SAME quiescing this proof used, a real
# durable write to a non-transient option must still move the whole-database
# fingerprint — proving the quiescing removed the async WP-Cron/transient churn
# WITHOUT removing the read-only tooth.
[ "$EXPLAIN_TOOTH_AFTER" != "$EXPLAIN_TOOTH_BEFORE" ] \
  || fail "issue #3410 mutation-tooth regression: under the SAME async-churn quiescing this proof uses, a real durable write to a non-transient option no longer moves the whole-database fingerprint — the SELECT-only tooth is gone, and a genuine explain write could pass unseen"
pass "public wprism explain traces one current row through a deterministic value-free contract with zero database/repository/provider/action mutation (issue #3410: WP-Cron/expired-transient churn quiesced across the window; whole-database mutation tooth verified live under that same quiescing)"

CONFLICT_TARGET_BEFORE=$(wp_conf2 post list --post_type=page --name=branch-a --field=post_title) || true
require_observed_nonempty "conf2 branch-a post_title (unforced-conflict target baseline)" "$CONFLICT_TARGET_BEFORE"
CONFLICT_BASE_BEFORE=$(wp_conf2 db query "SELECT content_hash FROM wp_wprism_state WHERE uuid = '$UA'" --skip-column-names | tr -d '[:space:]') || true
require_observed_nonempty "conf2 wp_wprism_state content_hash (unforced-conflict base baseline)" "$CONFLICT_BASE_BEFORE"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_wprism_answered "conf2 wprism apply (unforced three-way conflict probe)" human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflicts (env and repo both changed' <<<"$CONFLICT_OUT" \
  || fail "unforced three-way conflict did not refuse before mutation: $CONFLICT_OUT"
# issue #3401: capture each target OBSERVATION into a var and name an
# empty-at-exit-0 compose death as infrastructure BEFORE comparing, so the
# issue #3381 signature (a load-starved `docker compose run` returning EMPTY at
# exit 0) is named at the read site instead of reading as an engine mutation.
# A healthy (non-empty) read reaches the exact same compare; a real mutation
# (a different non-empty value) still reaches the accusation.
CONFLICT_TARGET_AFTER=$(wp_conf2 post list --post_type=page --name=branch-a --field=post_title) || true
require_observed_nonempty "conf2 branch-a post_title (unforced-conflict target)" "$CONFLICT_TARGET_AFTER"
[ "$CONFLICT_TARGET_AFTER" = "$CONFLICT_TARGET_BEFORE" ] \
  || fail "unforced conflict mutated the target title (before=$CONFLICT_TARGET_BEFORE after=$CONFLICT_TARGET_AFTER)"
CONFLICT_BASE_AFTER=$(wp_conf2 db query "SELECT content_hash FROM wp_wprism_state WHERE uuid = '$UA'" --skip-column-names | tr -d '[:space:]') || true
require_observed_nonempty "conf2 wp_wprism_state content_hash (unforced-conflict base)" "$CONFLICT_BASE_AFTER"
[ "$CONFLICT_BASE_AFTER" = "$CONFLICT_BASE_BEFORE" ] \
  || fail "unforced conflict advanced the target's last-synced base (before=$CONFLICT_BASE_BEFORE after=$CONFLICT_BASE_AFTER)"
FORCED_CONFLICT=$(wp_conf2 wprism apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_wprism_answered "conf2 wprism apply --force-theirs conflict override" json "$FORCED_CONFLICT"
jq -e --arg uuid "$UA" '.warnings | any(contains("FORCED conflict " + $uuid))' <<<"$FORCED_CONFLICT" >/dev/null \
  || fail "--force-theirs did not report the overridden conflict in machine output: $FORCED_CONFLICT"
CONFLICT_TARGET_CONVERGED=$(wp_conf2 post list --post_type=page --name=branch-a --field=post_title) || true
require_observed_nonempty "conf2 branch-a post_title (forced-conflict convergence)" "$CONFLICT_TARGET_CONVERGED"
[ "$CONFLICT_TARGET_CONVERGED" = 'Branch Repository Intent For Conflict' ] \
  || fail "forced repository intent did not converge on target (after=$CONFLICT_TARGET_CONVERGED)"
pass "plan conflicts speak WordPress names, expose hash-only base/repository/target intent, recommend reconciliation, and report destructive override (issue #3345)"

# Direct apply cannot bind the recovery process's held external exclusion.
# These fixture checks prove the exact refusal and native/ledger nonmutation;
# signed deletion and retry belong to the separately owned core SSH extension.
core_private_refusal_evidence() { # <snapshot|verify> <profile> <directory> <frozen-context> [baseline]
  $COMPOSE run --rm -T \
    --volume "$PAIR_SOURCE_ROOT/sandbox/tests/lib/PrivateRefusalReceipt.php:/wprism-test/PrivateRefusalReceipt.php:ro" \
    --volume "$PAIR_SOURCE_ROOT/sandbox/conformance/fixtures/core-private-refusal-evidence.php:/wprism-test/core-private-refusal-evidence.php:ro" \
    --entrypoint php cli2 /wprism-test/core-private-refusal-evidence.php \
    /wprism-test/PrivateRefusalReceipt.php "$@"
}

core_deletion_native_state() {
  local retain_rows=false projection='$state'
  case "${1:-witness}" in
    witness) ;;
    private) retain_rows=true; projection='["format"=>"wprism-core-native-state-diagnostic/v1","purpose"=>"diagnostic_only","verified"=>false,"tables"=>$nativeRows,"witness"=>$state]' ;;
    *) fail 'unknown core native observation mode' ;;
  esac
  wp_conf2 eval '
global $wpdb;
if (preg_match("/^[A-Za-z0-9_]+$/D", $wpdb->prefix) !== 1) {
    throw new RuntimeException("core deletion fixture table prefix is not bounded");
}
$keys = [
    "posts" => "ID", "postmeta" => "meta_id", "comments" => "comment_ID",
    "commentmeta" => "meta_id", "term_relationships" => "object_id,term_taxonomy_id",
    "terms" => "term_id", "termmeta" => "meta_id", "term_taxonomy" => "term_taxonomy_id",
    "options" => "option_id", "wprism_map" => "uuid,id_kind",
    "wprism_state" => "uuid", "wprism_kv" => "k", "wprism_journal" => "id",
];
$state = [];
$nativeRows = [];
foreach ($keys as $suffix => $key) {
    $wpdb->last_error = "";
    $rows = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}{$suffix} ORDER BY $key LIMIT 4097", ARRAY_A);
    if ($wpdb->last_error !== "" || !is_array($rows) || count($rows) > 4096) {
        throw new RuntimeException("core deletion fixture native read failed its bounded row witness");
    }
    $bytes = json_encode($rows, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (strlen($bytes) > 1048576) {
        throw new RuntimeException("core deletion fixture native read exceeded its byte witness");
    }
    $state[$suffix] = ["count" => count($rows), "sha256" => hash("sha256", $bytes)];
    if ('"$retain_rows"') {
        $nativeRows[$suffix] = $rows;
    }
    if ($suffix === "wprism_map") {
        // CaptureIdentity restores these tuples from durable UUID metadata;
        // SidebarState cannot reconstruct ledger-only widget identity.
        $restorable = array_values(array_filter($rows, static fn(array $row): bool =>
            in_array($row["id_kind"], ["post", "term", "term_taxonomy"], true)));
        $restorableMap = ["count" => count($restorable), "sha256" => hash("sha256",
            json_encode($restorable, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR))];
    }
}
$state["restorable_map"] = $restorableMap;
echo wp_json_encode('"$projection"', JSON_UNESCAPED_SLASHES);
'
}

core_capture_plan_native_state() { # <output variable> <label> <private stage>
  local output_variable="$1" label="$2" stage="$3" stem
  case "$stage" in identity-baseline|native-before|native-after|native-repeated) ;; *) fail 'unknown core native diagnostic stage' ;; esac
  stem="$CORE_NATIVE_EVIDENCE/$stage"
  # The shared transport retains rows and both streams before any assertion
  # can destroy the disposable target. The ordinary report still carries only
  # hashes/counts; private retention grants no plan or repair authority.
  (umask 077; wprism_private_capture_stage "$CORE_NATIVE_EVIDENCE" "$stage" core_deletion_native_state private) \
    || fail 'core native diagnostic command failed; inspect its retained private streams'
  # Admit bounded, private files before copying stderr into shell memory.
  # The output variable is not used until this helper's warning gate passes.
  capture_wprism_json_success "$output_variable" "$label" \
    php "$PAIR_SOURCE_ROOT/sandbox/conformance/fixtures/core-native-state-evidence.php" "$stem"
  assert_no_php_runtime_diagnostics "$label" "$(<"$stem.stderr")"
  if grep -Eq '(^|[[:space:]])Warning:' "$stem.stderr"; then
    fail 'core native diagnostic emitted a warning; inspect its retained private streams'
  fi
}

core_assert_deletion_exclusion() { # <profile> <frozen-context> <apply-flags...>
  local profile="$1" context="$2" reason='' nodes=1 before='' after='' baseline='' output='' answer='' receipt='' rc=0
  shift 2
  case "$profile" in
    plain) reason=deletion_writer_exclusion_required ;;
    forced-comments) reason=apply_failed; nodes=2 ;;
    forced-conflicts) reason=apply_forced_override_failed; nodes=2 ;;
    *) fail 'unknown core direct-deletion refusal profile' ;;
  esac
  capture_wprism_json_success before 'core direct-deletion native and ledger baseline' core_deletion_native_state
  require_observed_nonempty "core direct-deletion native and ledger baseline" "$before"
  capture_wprism_json_success baseline 'core direct-deletion private baseline' \
    core_private_refusal_evidence snapshot "$profile" /siterepo/.wprism/refusals "$context"
  require_observed_nonempty 'core direct-deletion private baseline' "$baseline"
  output=$(wp_conf2 wprism apply --repo=/siterepo --default-author=admin --format=json "$@" 2>&1) || rc=$?
  assert_no_php_runtime_diagnostics 'core direct-deletion refusal' "$output"
  require_wprism_answered "core direct-deletion refusal" json "$output"
  [ "$rc" -ne 0 ] || fail 'direct core deletion unexpectedly succeeded without held exclusion'
  answer=$(awk 'NF { line=$0 } END { print line }' <<<"$output")
  jq -e --arg reason "$reason" --arg profile "$profile" '
    .format == "wprism-command-refusal/v1" and .ok == false and .command == "apply" and
    .error == $reason and .reason_code == $reason and
    (if $profile == "forced-comments" then .details_redacted == true else has("details_redacted") | not end)
  ' <<<"$answer" >/dev/null || fail 'direct core deletion returned an unrelated public refusal'
  ! grep -Fq 'no exact held external writer exclusion is bound' <<<"$output" \
    || fail 'direct core deletion disclosed its private operator cause'
  if [ "$profile" = forced-conflicts ]; then
    jq -e --argjson context "$context" '
      (.forced_overrides | type == "array" and length == ($context.delete_conflict | length)) and
      all(.forced_overrides[];
        .format == "wprism-forced-plan-override/v1" and .plan_bucket == "delete_conflict" and
        .effect == "delete_target_authored_state" and .status == "authorized" and
        .required_flags == ["--with-deletes","--force-theirs"] and .supplied_flags == .required_flags)
    ' <<<"$answer" >/dev/null || fail 'core refused conflict authorization was hidden or claimed to have committed'
  fi
  capture_wprism_json_success receipt 'core direct-deletion exact fresh private cause graph' \
    core_private_refusal_evidence verify "$profile" /siterepo/.wprism/refusals "$context" "$baseline"
  jq -e --argjson nodes "$nodes" '
    .command == "apply" and .format == "wprism-private-refusal-check/v1" and
    .new_records == 1 and .verified == true and
    (.node_message_sha256 | type == "array" and length == $nodes) and
    all(.node_message_sha256[]; type == "string" and test("^[a-f0-9]{64}$"))
  ' <<<"$receipt" >/dev/null || fail 'core direct-deletion private receipt is malformed'
  capture_wprism_json_success after 'core direct-deletion native and ledger readback' core_deletion_native_state
  require_observed_nonempty "core direct-deletion native and ledger readback" "$after"
  jq -en --argjson before "$before" --argjson after "$after" '$before == $after' >/dev/null \
    || fail 'direct core deletion changed target posts, revisions, comments, relationships, options or identity/base/recovery ledgers'
}

# issue #3210: absence alone is not authority; capture replaces the prior Home
# page with a versioned tombstone. A target-only comment blocks deletion;
# overriding that guard still cannot mint a held external writer exclusion.
HOME_FILE=$(find "$CONF_REPO1/state/posts/page" -name '*--home.md' -print -quit)
require_fixture_values HOME_FILE
HOME_UUID=$(basename "$HOME_FILE" | sed -E 's/--home\.md$//')
require_fixture_values HOME_UUID
HOME1=$(wp_conf1 post list --post_type=page --name=home --field=ID | tr -d '[:space:]')
HOME2=$(wp_conf2 post list --post_type=page --name=home --field=ID | tr -d '[:space:]')
require_fixture_ids HOME1 HOME2
COMMENT2=$(wp_conf2 comment create --comment_post_ID="$HOME2" --comment_content='runtime deletion guard' --comment_author='Runtime Visitor' --porcelain)
require_fixture_ids COMMENT2
wp_conf1 post delete "$HOME1" --force >/dev/null
capture_wprism_json_checked DELETE_CAPTURE 'conf1 explicit page-deletion capture' assert_wprism_json_required_environment \
  wp_conf1 wprism capture --repo=/siterepo --format=json
require_wprism_answered "conf1 wprism capture page deletion" json "$DELETE_CAPTURE"
[ "$(jq -r '.counts.deletion' <<<"$DELETE_CAPTURE")" -ge 1 ] \
  || fail "page deletion did not emit a tombstone: $DELETE_CAPTURE"
[ -f "$CONF_REPO1/state/deletions/$HOME_UUID.json" ] || fail "Home tombstone was not published"
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: explicit page deletion'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main

capture_wprism_json_checked DELETE_PLAN 'conf2 referential page-deletion plan' assert_wprism_json_required_environment \
  wp_conf2 wprism plan --repo=/siterepo --format=json
require_wprism_answered "conf2 wprism plan referential page deletion" json "$DELETE_PLAN"
jq -e --arg uuid "$HOME_UUID" '.delete | any(.uuid == $uuid and (.blocked | contains("comments reference")))' \
  <<<"$DELETE_PLAN" >/dev/null || fail "target-only comment did not block the explicit page deletion: $DELETE_PLAN"
DELETE_RC=0
DELETE_OUT=$(wp_conf2 wprism apply --repo=/siterepo --with-deletes --default-author=admin 2>&1) || DELETE_RC=$?
require_wprism_answered "conf2 wprism apply --with-deletes (referential guard probe)" human "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && grep -qi 'referential guard' <<<"$DELETE_OUT" \
  || fail "guarded page delete was not refused: $DELETE_OUT"
COMMENT_EXCLUSION_CONTEXT=$(jq -nc --arg uuid "$HOME_UUID" --argjson comment_id "$COMMENT2" \
  '{uuid:$uuid,comment_id:$comment_id}')
jq -e --arg uuid "$HOME_UUID" --arg row "comments.comment_ID=$COMMENT2" '
  (.delete_conflict | length) == 0 and
  ([.delete[] | select(.uuid == $uuid)] | length) == 1 and
  (.delete[] | select(.uuid == $uuid) | .guard_refs == [
    {table:"comments",rows:[$row],repairable:false,option_name_ref:false}
  ])
' <<<"$DELETE_PLAN" >/dev/null || fail 'forced comment refusal premise is not one exact known runtime guard'
core_assert_deletion_exclusion forced-comments "$COMMENT_EXCLUSION_CONTEXT" --with-deletes --force-delete-referenced
HOME_AFTER=$(wp_conf2 post list --post_type=page --name=home --field=ID | tr -d '[:space:]')
require_observed_nonempty "conf2 Home after missing-exclusion refusal" "$HOME_AFTER"
[ "$HOME_AFTER" = "$HOME2" ] || fail 'Home changed despite the missing-exclusion refusal'
# issue #3401: `comment get` on a genuine cascade exits NON-zero, but a
# compose-death empty arrives at exit 0 (issue #3381). Capture, name only the
# exit-0 empty as infrastructure, and let a real (non-zero) cascade still
# reach the engine accusation below.
COMMENT2_REF_RC=0
COMMENT2_REF=$(wp_conf2 comment get "$COMMENT2" --field=comment_ID 2>/dev/null) || COMMENT2_REF_RC=$?
[ "$COMMENT2_REF_RC" -ne 0 ] || require_observed_nonempty "conf2 comment get (preserved runtime comment)" "$COMMENT2_REF"
[ "$COMMENT2_REF" = "$COMMENT2" ] || fail "runtime comment was cascaded or lost (expected=$COMMENT2 got=${COMMENT2_REF:-<empty>})"
capture_wprism_json_checked RETRY_PLAN 'conf2 refused page-deletion retry plan' assert_wprism_json_required_environment \
  wp_conf2 wprism plan --repo=/siterepo --format=json
require_wprism_answered "conf2 wprism plan page deletion retry" json "$RETRY_PLAN"
jq -e --arg uuid "$HOME_UUID" '
  (.delete | any(.uuid == $uuid and (.blocked | contains("comments reference")))) and
  (.delete_conflict | length) == 0 and (.deleted | any(.uuid == $uuid) | not)
' <<<"$RETRY_PLAN" >/dev/null || fail 'a refused page tombstone was incorrectly reported as applied/deleted'
pass "explicit page tombstone guards and forced guard overrides preserve native comments and remain pending without held exclusion"
# Retire only this already-proven fixture comment to reach the independent
# writer-exclusion gate without carrying its guard into later conflict cases.
wp_conf2 comment delete "$COMMENT2" --force >/dev/null
capture_wprism_json_checked PLAIN_DELETE_PLAN 'conf2 guard-free pending page tombstone' assert_wprism_json_required_environment \
  wp_conf2 wprism plan --repo=/siterepo --format=json
require_wprism_answered 'conf2 unguarded pending page tombstone' json "$PLAIN_DELETE_PLAN"
jq -e --arg uuid "$HOME_UUID" '.delete | any(.uuid == $uuid and (has("blocked") | not))' \
  <<<"$PLAIN_DELETE_PLAN" >/dev/null || fail 'retiring the fixture comment did not establish a guard-free pending deletion'
core_assert_deletion_exclusion plain '{}' --with-deletes
pass "a guard-free direct page deletion refuses for exact missing held exclusion with zero native or ledger mutation"

# Local edit: the tombstone expected base matches wprism_state, but the live
# hash does not. Editing creates a derived revision child; force authorization
# stays visible, but the direct refusal must preserve both post and revisions.
HELLO_FILE=$(find "$CONF_REPO1/state/posts/post" -name '*--hello-conformance.md' -print -quit)
require_fixture_values HELLO_FILE
HELLO_UUID=$(basename "$HELLO_FILE" | sed -E 's/--hello-conformance\.md$//')
require_fixture_values HELLO_UUID
HELLO1=$(wp_conf1 post list --post_type=post --name=hello-conformance --field=ID | tr -d '[:space:]')
HELLO2=$(wp_conf2 post list --post_type=post --name=hello-conformance --field=ID | tr -d '[:space:]')
require_fixture_ids HELLO1 HELLO2
wp_conf2 post update "$HELLO2" --post_content='target-only deletion conflict' >/dev/null
HELLO_COMMENT=$(wp_conf2 comment create --comment_post_ID="$HELLO2" --comment_content='runtime conflict guard' --comment_author='Runtime Visitor' --porcelain)
require_fixture_ids HELLO_COMMENT
wp_conf1 post delete "$HELLO1" --force >/dev/null
capture_wprism_json_checked LOCAL_DELETE_CAPTURE 'conf1 local-conflict deletion capture' assert_wprism_json_required_environment \
  wp_conf1 wprism capture --repo=/siterepo --format=json
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: delete against target local edit'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
capture_wprism_json_checked BLOCKED_LOCAL_PLAN 'conf2 guard-blocked local-deletion plan' assert_wprism_json_required_environment \
  wp_conf2 wprism plan --repo=/siterepo --format=json
require_wprism_answered "conf2 wprism plan guard-blocked deletion conflict" json "$BLOCKED_LOCAL_PLAN"
jq -e --arg uuid "$HELLO_UUID" '.delete_conflict | any(
  .uuid == $uuid
  and (.blocked | contains("comments reference"))
  and (.conflict_view.choices | any(.id == "apply_repository") | not)
  and (.conflict_view.choices | any(.id == "reconcile_in_repository" and .destructive == false))
)' <<<"$BLOCKED_LOCAL_PLAN" >/dev/null \
  || fail "guard-blocked deletion conflict advertised a destructive repository choice: $BLOCKED_LOCAL_PLAN"
BLOCKED_LOCAL_HUMAN=$(wp_conf2 wprism plan --repo=/siterepo)
require_wprism_answered "conf2 wprism plan guard-blocked deletion human view" human "$BLOCKED_LOCAL_HUMAN"
! grep -Fq 'DESTRUCTIVE OVERRIDE apply_repository' <<<"$BLOCKED_LOCAL_HUMAN" \
  || fail "guard-blocked deletion conflict advertised a destructive override in human output: $BLOCKED_LOCAL_HUMAN"
BLOCKED_CONTENT_BEFORE=$(wp_conf2 post get "$HELLO2" --field=post_content) || true
require_observed_nonempty "conf2 post get post_content (guard-blocked deletion target baseline)" "$BLOCKED_CONTENT_BEFORE"
BLOCKED_BASE_BEFORE=$(wp_conf2 db query "SELECT content_hash FROM wp_wprism_state WHERE uuid = '$HELLO_UUID'" --skip-column-names | tr -d '[:space:]') || true
require_observed_nonempty "conf2 wp_wprism_state content_hash (guard-blocked deletion base baseline)" "$BLOCKED_BASE_BEFORE"
BLOCKED_FORCE_RC=0
BLOCKED_FORCE_OUT=$(wp_conf2 wprism apply --repo=/siterepo --with-deletes --force-theirs --default-author=admin --format=json 2>/dev/null) \
  || BLOCKED_FORCE_RC=$?
# issue #3391: json mode, and stderr is deliberately dropped — a compose-layer
# death therefore leaves $BLOCKED_FORCE_OUT EMPTY while satisfying the
# non-zero-exit assertion below vacuously, and the typed-evidence assertion
# after it then accuses the engine of losing its refusal envelope.
require_wprism_answered "conf2 wprism apply --with-deletes --force-theirs (json refusal envelope)" json "$BLOCKED_FORCE_OUT"
[ "$BLOCKED_FORCE_RC" -ne 0 ] \
  || fail "guard-blocked deletion conflict accepted incomplete force authorization: $BLOCKED_FORCE_OUT"
BLOCKED_FORCE_JSON=$(awk 'NF { line=$0 } END { print line }' <<<"$BLOCKED_FORCE_OUT")
BLOCKED_ENTITY_HASH=$(printf '%s' "$HELLO_UUID" | shasum -a 256 | awk '{print $1}')
jq -e --arg entity_hash "$BLOCKED_ENTITY_HASH" '.format == "wprism-command-refusal/v1"
  and .error == "apply_conflict_override_incomplete"
  and (.forced_overrides | length) == 1
  and .forced_overrides[0].format == "wprism-forced-plan-override/v1"
  and .forced_overrides[0].plan_bucket == "delete_conflict"
  and .forced_overrides[0].entity_identity_sha256 == $entity_hash
  and .forced_overrides[0].conflict_kind == "tombstone_conflict"
  and .forced_overrides[0].choice == "explicit_force_flags"
  and .forced_overrides[0].effect == "delete_target_authored_state"
  and .forced_overrides[0].required_flags == ["--with-deletes","--force-theirs","--force-delete-referenced"]
  and .forced_overrides[0].supplied_flags == ["--with-deletes","--force-theirs"]
  and (.forced_overrides[0] | has("guard_override") | not)
  and .forced_overrides[0].status == "incomplete"' <<<"$BLOCKED_FORCE_JSON" >/dev/null \
  || fail "guard-blocked deletion refusal did not preserve truthful bounded force evidence: $BLOCKED_FORCE_JSON"
! grep -Fq "$HELLO_UUID" <<<"$BLOCKED_FORCE_JSON" \
  || fail "guard-blocked deletion refusal leaked the raw entity identity: $BLOCKED_FORCE_JSON"
! grep -Fq 'runtime conflict guard' <<<"$BLOCKED_FORCE_JSON" \
  || fail "guard-blocked deletion refusal leaked raw guard detail: $BLOCKED_FORCE_JSON"
# issue #3401: post get exits non-zero on a genuinely deleted target, so a
# compose-death empty (exit 0) is distinguished from a real deletion.
BLOCKED_CONTENT_AFTER_RC=0
BLOCKED_CONTENT_AFTER=$(wp_conf2 post get "$HELLO2" --field=post_content 2>/dev/null) || BLOCKED_CONTENT_AFTER_RC=$?
[ "$BLOCKED_CONTENT_AFTER_RC" -ne 0 ] || require_observed_nonempty "conf2 post get post_content (guard-blocked deletion target)" "$BLOCKED_CONTENT_AFTER"
[ "$BLOCKED_CONTENT_AFTER" = "$BLOCKED_CONTENT_BEFORE" ] \
  || fail "guard-blocked forced deletion mutated the target post (before=$BLOCKED_CONTENT_BEFORE after=$BLOCKED_CONTENT_AFTER)"
BLOCKED_BASE_AFTER=$(wp_conf2 db query "SELECT content_hash FROM wp_wprism_state WHERE uuid = '$HELLO_UUID'" --skip-column-names | tr -d '[:space:]') || true
require_observed_nonempty "conf2 wp_wprism_state content_hash (guard-blocked deletion base)" "$BLOCKED_BASE_AFTER"
[ "$BLOCKED_BASE_AFTER" = "$BLOCKED_BASE_BEFORE" ] \
  || fail "guard-blocked forced deletion advanced the last-synced base (before=$BLOCKED_BASE_BEFORE after=$BLOCKED_BASE_AFTER)"
# issue #3401: was `comment get … >/dev/null || fail` — a compose-death empty
# tripped this engine accusation. Capture, name an exit-0 empty as
# infrastructure, and keep the accusation for a genuine (non-zero) removal.
HELLO_COMMENT_REF_RC=0
HELLO_COMMENT_REF=$(wp_conf2 comment get "$HELLO_COMMENT" --field=comment_ID 2>/dev/null) || HELLO_COMMENT_REF_RC=$?
[ "$HELLO_COMMENT_REF_RC" -ne 0 ] || require_observed_nonempty "conf2 comment get (guard-blocked deletion runtime reference)" "$HELLO_COMMENT_REF"
[ "$HELLO_COMMENT_REF" = "$HELLO_COMMENT" ] \
  || fail "guard-blocked forced deletion removed its runtime reference (expected=$HELLO_COMMENT got=${HELLO_COMMENT_REF:-<empty>})"
pass "guard-blocked deletion conflict refuses incomplete force authorization with truthful typed evidence and zero target/ledger mutation"
wp_conf2 comment delete "$HELLO_COMMENT" --force >/dev/null
capture_wprism_json_checked LOCAL_PLAN 'conf2 guard-free local-deletion plan' assert_wprism_json_required_environment \
  wp_conf2 wprism plan --repo=/siterepo --format=json
require_wprism_answered "conf2 wprism plan local deletion conflict" json "$LOCAL_PLAN"
jq -e --arg uuid "$HELLO_UUID" '.delete_conflict | any(
  .uuid == $uuid
  and (.reason | contains("changed locally"))
  and (has("blocked") | not)
  and .conflict_view.format == "wprism-plan-conflict/v1"
  and .conflict_view.kind == "tombstone_conflict"
  and .conflict_view.reason_code == "target_changed_since_delete_base"
  and .conflict_view.base.role == "last_synced"
  and .conflict_view.repository.intent == "delete"
  and .conflict_view.repository.expected_base_hash == .conflict_view.base.content_hash
  and (.conflict_view.repository.intent_receipt_hash | test("^[a-f0-9]{64}$"))
  and .conflict_view.target.intent == "preserve_target_change"
  and .conflict_view.recommended_choice == "reconcile_in_repository"
  and (.conflict_view.choices | any(.id == "apply_repository" and .requires == ["--with-deletes","--force-theirs"] and .effect == "delete_target_authored_state" and .destructive == true))
)' \
  <<<"$LOCAL_PLAN" >/dev/null || fail "local edit did not become a deletion conflict: $LOCAL_PLAN"
LOCAL_HUMAN=$(wp_conf2 wprism plan --repo=/siterepo)
require_wprism_answered "conf2 wprism plan local deletion conflict human view" human "$LOCAL_HUMAN"
for NEEDLE in \
  'WHY target_changed_since_delete_base' \
  'REPOSITORY intent=delete state=none expected-base=sha256:' \
  'DESTRUCTIVE OVERRIDE apply_repository (--with-deletes --force-theirs): delete target authored state'; do
  grep -Fq "$NEEDLE" <<<"$LOCAL_HUMAN" \
    || fail "human deletion-conflict view is missing '$NEEDLE': $LOCAL_HUMAN"
done
LOCAL_FORCE_ONLY_CONTENT=$(wp_conf2 post get "$HELLO2" --field=post_content) || true
require_observed_nonempty "conf2 post get post_content (force-theirs-only deletion-conflict target baseline)" "$LOCAL_FORCE_ONLY_CONTENT"
LOCAL_FORCE_ONLY_BASE=$(wp_conf2 db query "SELECT content_hash FROM wp_wprism_state WHERE uuid = '$HELLO_UUID'" --skip-column-names | tr -d '[:space:]') || true
require_observed_nonempty "conf2 wp_wprism_state content_hash (force-theirs-only deletion-conflict base baseline)" "$LOCAL_FORCE_ONLY_BASE"
LOCAL_FORCE_ONLY_RC=0
LOCAL_FORCE_ONLY_OUT=$(wp_conf2 wprism apply --repo=/siterepo --force-theirs --default-author=admin --format=json 2>/dev/null) \
  || LOCAL_FORCE_ONLY_RC=$?
require_wprism_answered "conf2 wprism apply --force-theirs (json refusal envelope)" json "$LOCAL_FORCE_ONLY_OUT"
[ "$LOCAL_FORCE_ONLY_RC" -ne 0 ] \
  || fail "entity tombstone conflict accepted --force-theirs without --with-deletes: $LOCAL_FORCE_ONLY_OUT"
LOCAL_FORCE_ONLY_JSON=$(awk 'NF { line=$0 } END { print line }' <<<"$LOCAL_FORCE_ONLY_OUT")
jq -e --arg entity_hash "$BLOCKED_ENTITY_HASH" '.format == "wprism-command-refusal/v1"
  and .error == "apply_conflict_override_incomplete"
  and (.forced_overrides | length) == 1
  and .forced_overrides[0].entity_identity_sha256 == $entity_hash
  and .forced_overrides[0].choice == "apply_repository"
  and .forced_overrides[0].effect == "delete_target_authored_state"
  and .forced_overrides[0].required_flags == ["--with-deletes","--force-theirs"]
  and .forced_overrides[0].supplied_flags == ["--force-theirs"]
  and .forced_overrides[0].status == "incomplete"' <<<"$LOCAL_FORCE_ONLY_JSON" >/dev/null \
  || fail "entity tombstone conflict did not report the missing --with-deletes authorization honestly: $LOCAL_FORCE_ONLY_JSON"
# issue #3401: same guard as the guard-blocked region above — name a
# compose-death empty as infrastructure before the mutation compare.
LOCAL_FORCE_ONLY_CONTENT_AFTER_RC=0
LOCAL_FORCE_ONLY_CONTENT_AFTER=$(wp_conf2 post get "$HELLO2" --field=post_content 2>/dev/null) || LOCAL_FORCE_ONLY_CONTENT_AFTER_RC=$?
[ "$LOCAL_FORCE_ONLY_CONTENT_AFTER_RC" -ne 0 ] || require_observed_nonempty "conf2 post get post_content (force-theirs-only deletion-conflict target)" "$LOCAL_FORCE_ONLY_CONTENT_AFTER"
[ "$LOCAL_FORCE_ONLY_CONTENT_AFTER" = "$LOCAL_FORCE_ONLY_CONTENT" ] \
  || fail "--force-theirs without --with-deletes mutated the deletion-conflict target (before=$LOCAL_FORCE_ONLY_CONTENT after=$LOCAL_FORCE_ONLY_CONTENT_AFTER)"
LOCAL_FORCE_ONLY_BASE_AFTER=$(wp_conf2 db query "SELECT content_hash FROM wp_wprism_state WHERE uuid = '$HELLO_UUID'" --skip-column-names | tr -d '[:space:]') || true
require_observed_nonempty "conf2 wp_wprism_state content_hash (force-theirs-only deletion-conflict base)" "$LOCAL_FORCE_ONLY_BASE_AFTER"
[ "$LOCAL_FORCE_ONLY_BASE_AFTER" = "$LOCAL_FORCE_ONLY_BASE" ] \
  || fail "--force-theirs without --with-deletes advanced the deletion-conflict base (before=$LOCAL_FORCE_ONLY_BASE after=$LOCAL_FORCE_ONLY_BASE_AFTER)"
pass "entity tombstone conflicts require both advertised flags and refuse incomplete authorization without mutation"
LOCAL_RC=0
LOCAL_OUT=$(wp_conf2 wprism apply --repo=/siterepo --with-deletes --default-author=admin 2>&1) || LOCAL_RC=$?
require_wprism_answered "conf2 wprism apply --with-deletes (unforced delete conflict probe)" human "$LOCAL_OUT"
[ "$LOCAL_RC" -ne 0 ] && grep -qi 'deletion conflicts' <<<"$LOCAL_OUT" \
  || fail "unforced delete conflict was not refused: $LOCAL_OUT"
LOCAL_EXCLUSION_CONTEXT=$(jq -c '{delete_conflict:[.delete_conflict[] | {uuid,reason}]}' <<<"$LOCAL_PLAN")
core_assert_deletion_exclusion forced-conflicts "$LOCAL_EXCLUSION_CONTEXT" --with-deletes --force-theirs
HELLO_AFTER=$(wp_conf2 post list --post_type=post --name=hello-conformance --field=ID | tr -d '[:space:]')
require_observed_nonempty "conf2 locally edited post after missing-exclusion refusal" "$HELLO_AFTER"
[ "$HELLO_AFTER" = "$HELLO2" ] || fail 'locally edited post changed despite missing-exclusion refusal'
pass "delete-vs-local-edit force authorization stays loud while missing held exclusion preserves the post and revision children"

# Branch edit: capture the changed entity into git without applying it to
# conf2, then delete it on conf1. The tombstone therefore expects the new
# branch hash while conf2's base is still the old hash.
ATT_FILE=$(find "$CONF_REPO1/state/posts/attachment" -name '*--conformance-logo.md' -print -quit)
require_fixture_values ATT_FILE
ATT_UUID=$(basename "$ATT_FILE" | sed -E 's/--conformance-logo\.md$//')
require_fixture_values ATT_UUID
ATT1=$(wp_conf1 post list --post_type=attachment --name=conformance-logo --field=ID | tr -d '[:space:]')
require_fixture_ids ATT1
wp_conf1 post update "$ATT1" --post_title='Conformance Logo Branch Edit' >/dev/null
capture_wprism_json_checked BRANCH_EDIT_CAPTURE 'conf1 branch attachment edit capture' assert_wprism_json_required_environment \
  wp_conf1 wprism capture --repo=/siterepo --format=json
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: branch edits attachment'
git -C "$CONF_REPO1" push -q origin main
# The authored block widget must stop referencing the attachment before its
# source deletion. WordPress does not repair widget block content when an
# attachment is removed; leaving the image would test dangling-reference
# refusal rather than the intended tombstone expected-base conflict.
capture_wprism_json_success ATTACHMENT_WIDGET_UPDATE 'core source attachment widget retirement' wp_conf1 eval '
$widgets = get_option("widget_block");
if (!is_array($widgets) || !isset($widgets[21]["content"]) || !str_contains($widgets[21]["content"], "<!-- wp:image ")) {
    throw new RuntimeException("core attachment fixture lost its exact authored image widget");
}
$widgets[21]["content"] = "<!-- wp:paragraph --><p>Attachment retired in source intent</p><!-- /wp:paragraph -->";
update_option("widget_block", $widgets);
if (get_option("widget_block") !== $widgets) {
    throw new RuntimeException("core attachment fixture widget update did not persist");
}
echo wp_json_encode(["verified" => true]);
'
jq -e '. == {verified:true}' <<<"$ATTACHMENT_WIDGET_UPDATE" >/dev/null \
  || fail 'core attachment fixture did not verify its authored widget retirement'
wp_conf1 post delete "$ATT1" --force >/dev/null
capture_wprism_json_checked BRANCH_DELETE_CAPTURE 'conf1 branch attachment deletion capture' assert_wprism_json_required_environment \
  wp_conf1 wprism capture --repo=/siterepo --format=json
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: delete branch-edited attachment'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
capture_wprism_json_checked BRANCH_PLAN 'conf2 branch expected-base deletion plan' assert_wprism_json_required_environment \
  wp_conf2 wprism plan --repo=/siterepo --format=json
require_wprism_answered "conf2 wprism plan branch deletion conflict" json "$BRANCH_PLAN"
jq -e --arg uuid "$ATT_UUID" '.delete_conflict | any(.uuid == $uuid and (.reason | contains("expected hash")))' \
  <<<"$BRANCH_PLAN" >/dev/null || fail "delete-vs-branch-edit did not conflict on its expected base: $BRANCH_PLAN"
BRANCH_EXCLUSION_CONTEXT=$(jq -c '{delete_conflict:[.delete_conflict[] | {uuid,reason}]}' <<<"$BRANCH_PLAN")
core_assert_deletion_exclusion forced-conflicts "$BRANCH_EXCLUSION_CONTEXT" --with-deletes --force-theirs
ATT_AFTER=$(wp_conf2 post list --post_type=attachment --name=conformance-logo --field=ID | tr -d '[:space:]')
require_observed_nonempty "conf2 branch-conflicted attachment after missing-exclusion refusal" "$ATT_AFTER"
[ "$ATT_AFTER" = "$TARGET_ATTACHMENT" ] || fail 'branch-conflicted attachment changed despite missing-exclusion refusal'
pass "delete-vs-branch-edit conflicts on the tombstone expected base and direct force cannot consume pending tombstones"

# Missing guard infrastructure is a refusal, never a skipped warning.
CHILD_FILE=$(find "$CONF_REPO1/state/posts/page" -name '*--shared-child.md' -print | sort | head -1)
require_fixture_values CHILD_FILE
CHILD_UUID=$(basename "$CHILD_FILE" | sed -E 's/--shared-child\.md$//')
require_fixture_values CHILD_UUID
CHILD1=$(wp_conf1 eval "echo \\WPrism\\Ledger::id_for('$CHILD_UUID', \\WPrism\\Ledger::KIND_POST);")
require_fixture_ids CHILD1
wp_conf1 post delete "$CHILD1" --force >/dev/null
capture_wprism_json_checked CHILD_DELETE_CAPTURE 'conf1 child-page deletion capture' assert_wprism_json_required_environment \
  wp_conf1 wprism capture --repo=/siterepo --format=json
git -C "$CONF_REPO1" add -A
git -C "$CONF_REPO1" -c user.name=wprism -c user.email=wprism@example.test commit -qm 'conformance: missing deletion guard table'
git -C "$CONF_REPO1" push -q origin main
git -C "$CONF_REPO2" pull -q origin main
wp_conf2 db query 'RENAME TABLE wp_comments TO wp_comments_wprism_hold' >/dev/null
capture_wprism_json_checked MISSING_PLAN 'conf2 missing guard-table deletion plan' assert_wprism_json_required_environment \
  wp_conf2 wprism plan --repo=/siterepo --format=json
require_wprism_answered "conf2 wprism plan missing guard table" json "$MISSING_PLAN"
jq -e --arg uuid "$CHILD_UUID" '.delete | any(.uuid == $uuid and (.blocked | contains("required guard table")))' \
  <<<"$MISSING_PLAN" >/dev/null || fail "missing guard table did not fail closed: $MISSING_PLAN"
wp_conf2 db query 'RENAME TABLE wp_comments_wprism_hold TO wp_comments' >/dev/null
capture_wprism_json_checked RESTORED_GUARD_PLAN 'conf2 restored guard-table plan' assert_wprism_json_required_environment \
  wp_conf2 wprism plan --repo=/siterepo --format=json
require_wprism_answered 'conf2 restored guard-table pending deletions' json "$RESTORED_GUARD_PLAN"
jq -e --arg uuid "$CHILD_UUID" '.delete | any(.uuid == $uuid and (has("blocked") | not))' \
  <<<"$RESTORED_GUARD_PLAN" >/dev/null || fail 'restoring comments did not recover the complete child guard observation'
RESTORED_EXCLUSION_CONTEXT=$(jq -c '{delete_conflict:[.delete_conflict[] | {uuid,reason}]}' <<<"$RESTORED_GUARD_PLAN")
core_assert_deletion_exclusion forced-conflicts "$RESTORED_EXCLUSION_CONTEXT" --with-deletes --force-theirs
pass "missing reverse-reference guard infrastructure fails closed"

# No direct apply above reached row mutation. The signed core SSH extension
# owns successful deletion and FK preflight; transaction suites own rollback.
# Clearing environment-bound history does not remove the durable UUIDs or
# native posts preserved by these refusals. A plan must observe those posts
# and report missing-base conflicts, not reinterpret them as already deleted.
# Ordinary Capture::snapshot repairs maps from embedded UUIDs; only strict
# observation forbids that maintenance. Freeze the exact restorable tuples
# before clearing history, retain every native-table check, and prove a fixed
# point without inventing widget UUIDs or a last-synced state baseline.
TOMBSTONES=$(find "$CONF_REPO1/state/deletions" -type f -name '*.json' | wc -l | tr -d '[:space:]')
require_observed_nonempty "repository tombstone count before fresh-target plan" "$TOMBSTONES"
. "$PAIR_SOURCE_ROOT/sandbox/tests/lib/private_command_capture.sh"
# pair_siterepo_host_one() deliberately broadens every site's mode bits at
# handback; private host evidence must not live anywhere in that bind tree.
mkdir -p "$PAIR_SOURCE_ROOT/sandbox/tmp"
CORE_NATIVE_EVIDENCE=$(umask 077; mktemp -d "$PAIR_SOURCE_ROOT/sandbox/tmp/wprism-core-native.XXXXXX")
printf 'core native diagnostics (unverified): %s\n' "$CORE_NATIVE_EVIDENCE" >&2
(
. "$PAIR_SOURCE_ROOT/sandbox/tests/lib/wordpress_cron_window.sh"
core_cron_window_transport() {
  wordpress_cron_window_compose_transport cli2 "$@"
}
trap 'wordpress_cron_window_exit "$?"' EXIT
trap 'exit 130' INT TERM
# a56cad09 retained exactly one unexpected change: doing_cron.option_value.
# Freeze spawning before the first native boot, retain every option row, and
# keep the guard through the repeat read. Cleanup never boots WordPress.
wordpress_cron_window_begin wp_conf2 core_cron_window_transport
core_capture_plan_native_state FRESH_IDENTITY_BASELINE 'core existing target native and restorable identity baseline' identity-baseline
require_observed_nonempty 'core existing target native and restorable identity baseline' "$FRESH_IDENTITY_BASELINE"
jq -e '.restorable_map.count > 0' <<<"$FRESH_IDENTITY_BASELINE" >/dev/null \
  || fail 'fresh-target fixture has no durable identity mappings to restore'
wp_conf2 db query 'TRUNCATE TABLE wp_wprism_map; TRUNCATE TABLE wp_wprism_state' >/dev/null
core_capture_plan_native_state FRESH_NATIVE_BEFORE 'core unmapped target native and ledger baseline' native-before
require_observed_nonempty "core unmapped target native and ledger baseline" "$FRESH_NATIVE_BEFORE"
jq -en --argjson baseline "$FRESH_IDENTITY_BASELINE" --argjson before "$FRESH_NATIVE_BEFORE" '
  {count:0,sha256:"4f53cda18c2baa0c0354bb5f9a3ecbe5ed12ab4d8e11ba873c2f11161202b945"} as $empty |
  $before == ($baseline | .wprism_map=$empty | .wprism_state=$empty | .restorable_map=$empty)
' >/dev/null || fail 'fresh-target fixture did not clear exactly map and state history'
capture_wprism_json_checked FRESH_PLAN 'conf2 unmapped existing-target plan' assert_wprism_json_required_environment \
  wp_conf2 wprism plan --repo=/siterepo --format=json
require_wprism_answered "conf2 wprism plan fresh target deletion interpretation" json "$FRESH_PLAN"
jq -e --argjson count "$TOMBSTONES" '
  (.deleted | length) == 0 and (.delete | length) == 0 and
  (.delete_conflict | length) == $count and
  all(.delete_conflict[]; .reason == "target entity exists but has no last-synced base")
' <<<"$FRESH_PLAN" >/dev/null || fail 'unmapped existing target entities lost their missing-base deletion conflicts'
core_capture_plan_native_state FRESH_NATIVE_AFTER 'core unmapped target native and ledger readback' native-after
require_observed_nonempty "core unmapped target native and ledger readback" "$FRESH_NATIVE_AFTER"
jq -en --argjson baseline "$FRESH_IDENTITY_BASELINE" --argjson before "$FRESH_NATIVE_BEFORE" --argjson after "$FRESH_NATIVE_AFTER" '
  $after == ($before | .wprism_map=$baseline.restorable_map | .restorable_map=$baseline.restorable_map)
' >/dev/null || fail 'fresh-target plan changed more than its exact embedded-identity map repair'
capture_wprism_json_checked FRESH_REPEAT_PLAN 'conf2 repaired-map missing-base repeat plan' assert_wprism_json_required_environment \
  wp_conf2 wprism plan --repo=/siterepo --format=json
require_wprism_answered 'conf2 repaired-map missing-base repeat plan' json "$FRESH_REPEAT_PLAN"
jq -en --argjson first "$FRESH_PLAN" --argjson repeated "$FRESH_REPEAT_PLAN" '
  [$first.deleted,$first.delete,$first.delete_conflict] == [$repeated.deleted,$repeated.delete,$repeated.delete_conflict]
' >/dev/null || fail 'repairing an identity map changed the pending missing-base deletion conflicts'
core_capture_plan_native_state FRESH_NATIVE_REPEATED 'core repaired-map native and ledger fixed point' native-repeated
require_observed_nonempty 'core repaired-map native and ledger fixed point' "$FRESH_NATIVE_REPEATED"
[ "$FRESH_NATIVE_AFTER" = "$FRESH_NATIVE_REPEATED" ] || fail 'repeated fresh-target plan changed native or ledger state'
pass "unmapped existing target entities remain deletion conflicts; exact embedded maps repair once, without minting identity or a state baseline"
)
# END core fresh native window
