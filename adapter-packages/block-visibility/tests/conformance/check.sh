#!/usr/bin/env bash
# The portable claim excludes blockVisibility itself, but the plugin must still
# consume the migrated settings and entity-free preset on the target. The
# frontend probe remains target-local so it proves native behavior without
# carrying a source-local preset id through canonical block content.
set -euo pipefail

PROBE_ID=""
cleanup_probe() {
  [ -z "$PROBE_ID" ] || wp_conf2 post delete "$PROBE_ID" --force >/dev/null 2>&1 || true
}
trap cleanup_probe EXIT

SETTINGS=$(wp_conf2 option get block_visibility_settings --format=json)
printf '%s\n' "$SETTINGS" | jq -e '
  .plugin_settings.block_opacity == 45 and
  .plugin_settings.enable_contextual_indicators == false and
  .visibility_controls.cookie.enable == false and
  .disabled_blocks == ["core/separator", "core/spacer"]
' >/dev/null \
  || fail "Block Visibility target settings did not converge through native storage: $SETTINGS"
pass "Block Visibility settings converge on the target with their native stored values"

pass "Apply converges divergent declared settings keys on the target"

PRESET_ID=$(wp_conf2 post list --post_type=visibility_preset --name=logged-in-only --field=ID)
require_observed_nonempty "Block Visibility target preset identity" "$PRESET_ID"
PRESET_META=$(wp_conf2 eval "\$meta = get_post_meta((int) $PRESET_ID); \$meta['control_sets'] = maybe_unserialize(\$meta['control_sets'][0] ?? ''); echo wp_json_encode(\$meta);")
printf '%s\n' "$PRESET_META" | jq -e '
  .enable == ["1"] and .hide_block == [""] and .layout == ["columns"] and
  .control_sets[0].controls.userRole.restrictedRoles == ["administrator", "editor"]
' >/dev/null \
  || fail "Block Visibility entity-free preset did not converge through native meta: $PRESET_META"
pass "the entity-free visibility preset converges with native scalar and control-set values"

PROBE_ID=$(wp_conf2 eval '
  $preset = (int) get_posts(["post_type" => "visibility_preset", "name" => "logged-in-only", "numberposts" => 1, "fields" => "ids"])[0];
  $attrs = wp_json_encode(["blockVisibility" => ["visibilityPresets" => ["presets" => [$preset], "operator" => "atLeastOne"]]]);
  $content = "<!-- wp:paragraph " . $attrs . " -->\n<p>Block Visibility native probe</p>\n<!-- /wp:paragraph -->";
  $post = wp_insert_post([
      "post_type" => "post",
      "post_status" => "publish",
      "post_title" => "Block Visibility Native Probe",
      "post_name" => "block-visibility-native-probe",
      "post_content" => $content,
  ], true);
  if (is_wp_error($post) || !$post) { throw new RuntimeException("could not create the target-local visibility probe"); }
  echo (int) $post;
')
require_observed_nonempty "Block Visibility target-local probe identity" "$PROBE_ID"

BODY=$(curl --max-time 20 -L --max-redirs 3 -sS "http://localhost:${CONF2_PORT}/?p=${PROBE_ID}") \
  || fail "Block Visibility native frontend probe failed before an HTTP response"
if printf '%s\n' "$BODY" | grep -Fq 'Block Visibility native probe'; then
  fail "Block Visibility did not hide the target-local anonymous block probe"
fi
pass "Block Visibility hides an anonymous block through the migrated native preset"

VISIBLE_ID=$(wp_conf2 post list --post_type=post --name=unannotated-fixture --field=ID)
require_observed_nonempty "Block Visibility unannotated target post identity" "$VISIBLE_ID"
BODY=$(curl --max-time 20 -L --max-redirs 3 -sS "http://localhost:${CONF2_PORT}/?p=${VISIBLE_ID}") \
  || fail "Block Visibility unannotated frontend probe failed before an HTTP response"
printf '%s\n' "$BODY" | grep -Fq 'No visibility rule on this block.' \
  || fail "Block Visibility unexpectedly hid the migrated unannotated block"
pass "an unannotated block remains visible while the target-local preset-gated block is hidden"
