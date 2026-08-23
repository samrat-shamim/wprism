#!/usr/bin/env bash
# Author both portable settings through Classic Editor's registered
# sanitizers and create one plain plus one block post so the plugin's own
# per-post editor-selection filter has both branches to consume.
set -euo pipefail

read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

$replace = Classic_Editor::validate_option_editor('classic');
$allow = Classic_Editor::validate_option_allow_users('allow');
if ($replace !== 'classic' || $allow !== 'allow') {
    throw new RuntimeException('Classic Editor rejected its supported settings');
}
if (Classic_Editor::validate_option_editor("legacy-未知-\u{1F680}") !== 'classic'
    || Classic_Editor::validate_option_allow_users("allow-未知-\u{1F680}") !== 'disallow') {
    throw new RuntimeException('Classic Editor invalid-value normalization changed');
}
update_option('classic-editor-replace', $replace);
update_option('classic-editor-allow-users', $allow);

$plain = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_title' => 'Classic Editor Plain Fixture',
    'post_name' => 'classic-editor-plain-fixture',
    'post_content' => 'A plain post should open in the configured classic editor.',
], true);
$blocks = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_title' => 'Classic Editor Block Fixture',
    'post_name' => 'classic-editor-block-fixture',
    'post_content' => "<!-- wp:paragraph -->\n<p>A block post keeps the block editor available.</p>\n<!-- /wp:paragraph -->",
], true);
if (is_wp_error($plain) || is_wp_error($blocks) || !$plain || !$blocks) {
    throw new RuntimeException('Classic Editor post fixtures were not persisted');
}

echo wp_json_encode(['blocks' => (int) $blocks, 'plain' => (int) $plain]);
PHPEOF

SEED_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-classic-editor-seed.php"
printf '%s' "$SEED_PHP" > "$SEED_FILE"
SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-classic-editor-seed.php)
require_observed_nonempty "Classic Editor source seed" "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$SEED_JSON" | jq -e '.plain > 0 and .blocks > 0 and .plain != .blocks' >/dev/null \
  || fail "Classic Editor did not create two distinct plugin-visible post fixtures: $SEED_JSON"
rm -f "$SEED_FILE"
pass "Classic Editor settings and both editor-selection branches were authored through live plugin APIs"
