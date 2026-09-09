#!/usr/bin/env bash
# Author the surface this capsule DOES own, through the plugin's own registered
# storage: the settings option, and a visibility_preset whose control set
# references no entity. The refused surfaces are exercised in capture-check.sh,
# after this capture has proven the admitted ones round-trip.
set -euo pipefail

read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

// register_settings() runs on rest_api_init/admin_init, so fire the plugin's
// own hook rather than hand-rolling a settings array it would not have written.
do_action('rest_api_init');
$registered = get_registered_settings();
$defaults = $registered['block_visibility_settings']['default'] ?? null;
if (!is_array($defaults) || array_keys($defaults) !== ['visibility_controls', 'disabled_blocks', 'plugin_settings']) {
    throw new RuntimeException(
        'Block Visibility settings schema is not the measured three-key object: ' . wp_json_encode(array_keys((array) $defaults))
    );
}

// A deliberately non-default setting on each of the three sub-keys, so target
// convergence would be provable on every one of them.
$settings = $defaults;
$settings['plugin_settings']['enable_contextual_indicators'] = false;
$settings['plugin_settings']['block_opacity'] = 45;
$settings['visibility_controls']['cookie']['enable'] = false;
$settings['disabled_blocks'] = ['core/separator', 'core/spacer'];
update_option('block_visibility_settings', $settings);

$stored = get_option('block_visibility_settings');
if ($stored['plugin_settings']['block_opacity'] !== 45
    || $stored['disabled_blocks'] !== ['core/separator', 'core/spacer']) {
    throw new RuntimeException('Block Visibility settings did not persist: ' . wp_json_encode($stored));
}

// A preset whose control set is entirely portable — role slugs and a
// screen-size rule carry no entity id, so this capsule captures it.
$preset = wp_insert_post([
    'post_type' => 'visibility_preset',
    'post_status' => 'publish',
    'post_title' => 'Logged In Only',
], true);
if (is_wp_error($preset) || !$preset) {
    throw new RuntimeException('Block Visibility preset was not created');
}
update_post_meta($preset, 'enable', true);
update_post_meta($preset, 'layout', 'columns');
update_post_meta($preset, 'hide_block', false);
update_post_meta($preset, 'control_sets', [[
    'id' => 1,
    'title' => 'Control Set 1',
    'enable' => true,
    'controls' => [
        'userRole' => [
            'visibilityByRole' => 'user-role',
            'restrictedRoles' => ['administrator', 'editor'],
        ],
        'screenSize' => ['hideOnScreenSize' => ['extraLarge' => false, 'large' => true]],
    ],
]]);

// Ordinary block content with NO blockVisibility attribute: the capsule leaves
// unannotated content completely alone, which is what makes the refusal below
// a statement about visibility rules rather than about block content at all.
$post = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_title' => 'Unannotated Fixture',
    'post_name' => 'unannotated-fixture',
    'post_content' => "<!-- wp:paragraph -->\n<p>No visibility rule on this block.</p>\n<!-- /wp:paragraph -->",
], true);
if (is_wp_error($post) || !$post) {
    throw new RuntimeException('Block Visibility unannotated fixture was not created');
}

echo wp_json_encode([
    'disabled_blocks' => $stored['disabled_blocks'],
    'opacity' => $stored['plugin_settings']['block_opacity'],
    'post' => (int) $post,
    'preset' => (int) $preset,
    'preset_meta' => array_keys(get_post_meta($preset)),
]);
PHPEOF

SEED_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-block-visibility-seed.php"
printf '%s' "$SEED_PHP" > "$SEED_FILE"
SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-block-visibility-seed.php)
require_observed_nonempty "Block Visibility source seed" "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$SEED_JSON" | jq -e '
  .preset > 0 and .post > 0 and .opacity == 45 and
  .disabled_blocks == ["core/separator", "core/spacer"] and
  (.preset_meta | sort) == ["control_sets", "enable", "hide_block", "layout"]
' >/dev/null || fail "Block Visibility source seed did not land: $SEED_JSON"
rm -f "$SEED_FILE"
pass "Block Visibility settings and an entity-free visibility preset authored through the plugin's own registered storage"
