#!/usr/bin/env bash
# The capsule's real claim is a boundary, so this hook proves the boundary
# rather than only the happy path: what capture admits, what it refuses, that
# each refusal names its own cause, and that capture answers again once the
# refused state is removed.
set -euo pipefail

STATE="${CONF_REPO1:-siterepo/conf1}/state"

# ---- 1. what the capsule DOES own reached canonical state -------------------
OPTION_DOC=$(find "$STATE" -name '*.json' -path '*option*' | head -1)
[ -n "$OPTION_DOC" ] || fail "Block Visibility capture produced no options document under $STATE"
jq -e '
  (.records["block_visibility_settings"].value.plugin_settings.block_opacity == 45) and
  (.records["block_visibility_settings"].value.disabled_blocks == ["core/separator","core/spacer"]) and
  (.records["block_visibility_settings"].value.visibility_controls.cookie.enable == false)
' "$OPTION_DOC" >/dev/null \
  || fail "Block Visibility settings did not reach canonical state as three authored sub-keys: $(jq -c '.records["block_visibility_settings"]' "$OPTION_DOC")"
pass "Block Visibility settings captured as three authored sub-keys, block names and all"

PRESET_COUNT=$(grep -rl '"type": *"post"' "$STATE" 2>/dev/null | xargs -r grep -l 'visibility_preset' | wc -l | tr -d ' ')
[ "$PRESET_COUNT" -ge 1 ] \
  || fail "Block Visibility captured no visibility_preset entity into canonical state"
grep -rq 'Logged In Only' "$STATE" \
  || fail "the authored preset title is missing from canonical state"
grep -rq 'restrictedRoles' "$STATE" \
  || fail "the preset's entity-free control set did not reach canonical state"
pass "the visibility_preset entity and its entity-free control set captured, proving the interpreter admits a portable control set"

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
diff -r "$STATE" "${CONF_REPO1:-siterepo/conf1}/.tmp-bv-clean" \
  || fail "capture after removing the annotation is not byte-identical to the admitted capture"
rm -rf "${CONF_REPO1:-siterepo/conf1}/.tmp-bv-clean"
pass "removing the visibility rule restores a byte-identical capture, so the boundary is the rule and not the content"

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
grep -q 'postID' <<<"$REF_CAP" \
  || fail "the control_sets refusal did not name the offending location rule field: $REF_CAP"
pass "a preset control set carrying an entity reference aborts capture, naming the meta key and the exact location rule field"
