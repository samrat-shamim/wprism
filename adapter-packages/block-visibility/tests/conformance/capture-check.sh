#!/usr/bin/env bash
# The capsule's real claim is a boundary, so this hook proves the boundary
# rather than only the happy path: what capture admits, what it refuses, that
# each refusal names its own cause, and that capture answers again once the
# refused state is removed.
set -euo pipefail

STATE="${CONF_REPO1:-siterepo/conf1}/state"

# ---- 1. what the capsule DOES own reached canonical state -------------------
OPTION_DOC="$STATE/options/core.json"
[ -f "$OPTION_DOC" ] || fail "Block Visibility capture produced no options document at $OPTION_DOC"
jq -e '
  (.records["block_visibility_settings"].value.plugin_settings.block_opacity == 45) and
  (.records["block_visibility_settings"].value.disabled_blocks == ["core/separator","core/spacer"]) and
  (.records["block_visibility_settings"].value.visibility_controls.cookie.enable == false)
' "$OPTION_DOC" >/dev/null \
  || fail "Block Visibility settings did not reach canonical state as three authored sub-keys: $(jq -c '.records["block_visibility_settings"]' "$OPTION_DOC")"
pass "Block Visibility settings captured as three authored sub-keys, block names and all"

PRESET_DIR="$STATE/posts/visibility_preset"
[ -d "$PRESET_DIR" ] || fail "Block Visibility captured no visibility_preset entity directory at $PRESET_DIR"
PRESET_DOC=$(find "$PRESET_DIR" -name '*.md' | head -1)
[ -n "$PRESET_DOC" ] || fail "Block Visibility captured no visibility_preset document under $PRESET_DIR"
grep -q 'Logged In Only' "$PRESET_DOC" \
  || fail "the authored preset title is missing from $PRESET_DOC"
grep -q 'restrictedRoles' "$PRESET_DOC" \
  || fail "the preset's entity-free control set did not reach canonical state: $PRESET_DOC"
grep -q '"layout": "columns"' "$PRESET_DOC" \
  || fail "the preset's scalar meta did not reach canonical state: $PRESET_DOC"
pass "the visibility_preset entity, its scalars and its entity-free control set captured, proving the interpreter admits a portable control set"

# ---- 2. an annotated block REFUSES, naming the block and the reason ---------
# update_post_meta/wp_update_post cannot stage this: the attribute lives in
# post_content, which is exactly where the engine would otherwise carry a
# source-local id straight into canonical state.
ANNOTATE_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-block-visibility-annotate.php"
cat > "$ANNOTATE_FILE" <<'PHPEOF'
<?php
wp_set_current_user(1);
$post = get_page_by_path('unannotated-fixture', OBJECT, 'post');
if (!$post) { throw new RuntimeException('unannotated fixture is missing'); }
$preset = get_posts(['post_type' => 'visibility_preset', 'numberposts' => 1, 'fields' => 'ids']);
if (!$preset) { throw new RuntimeException('preset is missing'); }
$id = (int) $preset[0];
wp_update_post([
    'ID' => $post->ID,
    'post_content' => "<!-- wp:paragraph {\"blockVisibility\":{\"visibilityPresets\":{\"presets\":[$id],\"operator\":\"atLeastOne\"}}} -->\n<p>Preset gated.</p>\n<!-- /wp:paragraph -->",
], true);
echo wp_json_encode(['post' => (int) $post->ID, 'preset' => $id]);
PHPEOF
ANNOTATE_OUT=$(wp_conf1 eval-file /siterepo/.tmp-block-visibility-annotate.php)
rm -f "$ANNOTATE_FILE"
require_observed_nonempty "Block Visibility annotated-block premise" "$ANNOTATE_OUT"

BLOCK_RC=0
BLOCK_OUT=$(wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-bv-block 2>&1) || BLOCK_RC=$?
rm -rf "${CONF_REPO1:-siterepo/conf1}/.tmp-bv-block"
[ "$BLOCK_RC" -ne 0 ] \
  || fail "a block carrying blockVisibility was captured without refusal: $BLOCK_OUT"
grep -q 'blockVisibility' <<<"$BLOCK_OUT" \
  || fail "the blockVisibility refusal did not name the attribute: $BLOCK_OUT"
grep -q 'core/paragraph' <<<"$BLOCK_OUT" \
  || fail "the blockVisibility refusal did not name the block: $BLOCK_OUT"
grep -q 'explicitly unsupported' <<<"$BLOCK_OUT" \
  || fail "the blockVisibility refusal is not the reviewed unsupported boundary: $BLOCK_OUT"
pass "a block carrying blockVisibility aborts capture, naming the block, the attribute and the reviewed boundary"

# Removing the annotation must make capture answer again: the refusal is about
# the visibility rule, not about block content or this post.
wp_conf1 eval '
$p = get_page_by_path("unannotated-fixture", OBJECT, "post");
wp_update_post(["ID" => $p->ID, "post_content" => "<!-- wp:paragraph -->\n<p>No visibility rule on this block.</p>\n<!-- /wp:paragraph -->"], true);
' >/dev/null
wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-bv-clean >/dev/null \
  || fail "capture did not recover after the blockVisibility annotation was removed"
# Two wp_update_post() calls moved this post's modified stamps, so the ONLY
# admissible difference is those two lines. Asserting that rather than plain
# byte-identity is the stronger statement: every other captured byte, the block
# content included, came back unchanged.
RECOVERED_DIFF=$(diff -r "$STATE" "${CONF_REPO1:-siterepo/conf1}/.tmp-bv-clean" || true)
# `diff -r` reports a whole file present on only one side as "Only in <dir>",
# and an unreadable/binary pair as "Files ... differ" -- neither starts with
# < or >, so filtering on those alone would wave structural loss through: a
# recovery that dropped state/options/core.json entirely would read as "no
# difference but the modified stamps". Both shapes stay in UNEXPECTED.
UNEXPECTED=$(printf '%s\n' "$RECOVERED_DIFF" \
  | grep -E '^([<>]|Only in |Files .* differ)' \
  | grep -vE '^[<>][[:space:]]+"modified(_gmt)?": ' || true)
[ -z "$UNEXPECTED" ] \
  || fail "capture after removing the annotation differs by more than the modified stamps: $UNEXPECTED"
grep -rq 'blockVisibility' "${CONF_REPO1:-siterepo/conf1}/.tmp-bv-clean" \
  && fail "the recovered capture still carries a blockVisibility attribute" || true
rm -rf "${CONF_REPO1:-siterepo/conf1}/.tmp-bv-clean"
pass "removing the visibility rule restores capture with no difference but the modified stamps and no blockVisibility left behind, so the boundary is the rule and not the content"

# ---- 3. an entity-referencing preset control set REFUSES by field name ------
REF_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-block-visibility-ref.php"
cat > "$REF_FILE" <<'PHPEOF'
<?php
wp_set_current_user(1);
$page = wp_insert_post(['post_type'=>'page','post_status'=>'publish','post_title'=>'Gated Page'], true);
$preset = get_posts(['post_type' => 'visibility_preset', 'numberposts' => 1, 'fields' => 'ids'])[0];
$sets = get_post_meta($preset, 'control_sets', true);
$sets[0]['controls']['location'] = ['ruleSets' => [[
    'enable' => true,
    'rules' => [['field' => 'postID', 'operator' => 'any', 'value' => (string) $page]],
]]];
update_post_meta($preset, 'control_sets', $sets);
echo wp_json_encode(['page' => (int) $page, 'preset' => (int) $preset]);
PHPEOF
REF_OUT=$(wp_conf1 eval-file /siterepo/.tmp-block-visibility-ref.php)
rm -f "$REF_FILE"
require_observed_nonempty "Block Visibility referencing-preset premise" "$REF_OUT"

REF_RC=0
REF_CAP=$(wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-bv-ref 2>&1) || REF_RC=$?
rm -rf "${CONF_REPO1:-siterepo/conf1}/.tmp-bv-ref"
[ "$REF_RC" -ne 0 ] \
  || fail "a preset control set referencing a post id was captured without refusal: $REF_CAP"
grep -q 'control_sets' <<<"$REF_CAP" \
  || fail "the control_sets refusal did not name the meta key: $REF_CAP"
grep -q 'location' <<<"$REF_CAP" \
  || fail "the control_sets refusal did not name the offending control: $REF_CAP"
# Cause, not just exit status: without this the block passes on ANY non-zero
# capture that happens to echo the meta key -- a generic bare_id finding on the
# numeric string, or a serialization failure dumping the blob. This sentence
# exists only in the capsule interpreter's reviewed refusal.
grep -q 'has not reviewed as reference-free' <<<"$REF_CAP" \
  || fail "the control_sets refusal is not the interpreter's reviewed allowlist boundary: $REF_CAP"
pass "a preset control set using a control outside the reviewed reference-free allowlist aborts capture, naming the meta key and the exact control"

# Restore the portable control set: the refusal above is the assertion, and
# leaving the source refusing would make every later harness step fail for a
# reason this hook already proved. Capture must answer again afterwards, which
# is what makes the refusal a boundary rather than a latent broken state.
wp_conf1 eval '
$preset = get_posts(["post_type" => "visibility_preset", "numberposts" => 1, "fields" => "ids"])[0];
$sets = get_post_meta($preset, "control_sets", true);
unset($sets[0]["controls"]["location"]);
update_post_meta($preset, "control_sets", $sets);
' >/dev/null
wp_conf1 wprism capture --repo=/siterepo --out=/siterepo/.tmp-bv-restored >/dev/null \
  || fail "capture did not recover after the entity-referencing location rule was removed"
grep -rq 'restrictedRoles' "${CONF_REPO1:-siterepo/conf1}/.tmp-bv-restored" \
  || fail "the restored capture lost the preset's remaining entity-free control set"
grep -rq '"field": "postID"' "${CONF_REPO1:-siterepo/conf1}/.tmp-bv-restored" \
  && fail "the restored capture still carries the entity-referencing location rule" || true
rm -rf "${CONF_REPO1:-siterepo/conf1}/.tmp-bv-restored"
pass "removing the entity-referencing rule restores capture with the portable control set intact, so the interpreter refuses the reference and not the preset"
