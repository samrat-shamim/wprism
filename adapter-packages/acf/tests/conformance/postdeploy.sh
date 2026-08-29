#!/usr/bin/env bash
# Hostile target authored through WordPress/ACF APIs after deploy and before
# apply: divergent local identities, same-slug schemas/content/terms, stale
# ACF values, and environment-owned activation/health state.
set -euo pipefail

HOSTILE_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-acf-hostile.php"
cat > "$HOSTILE_FILE" <<'PHPEOF'
<?php
if (!function_exists('acf_update_field_group')) {
    throw new RuntimeException('ACF functions not available on target');
}

for ($i = 0; $i < 12; $i++) {
    $id = wp_insert_post([
        'post_type' => 'post', 'post_status' => 'draft',
        'post_title' => "Target identity spacer $i",
    ], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    wp_delete_post((int) $id, true);
}

require_once ABSPATH . 'wp-admin/includes/user.php';
for ($i = 0; $i < 4; $i++) {
    $id = wp_insert_user([
        'user_login' => "acf-target-spacer-$i",
        'user_pass' => wp_generate_password(32, true, true),
        'user_email' => "acf-target-spacer-$i@example.test",
        'role' => 'subscriber',
    ]);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    wp_delete_user((int) $id);
}
$editor = wp_insert_user([
    'user_login' => 'acf-editor',
    'user_pass' => wp_generate_password(32, true, true),
    'user_email' => 'acf-editor-target@example.test',
    'role' => 'editor',
]);
if (is_wp_error($editor)) {
    throw new RuntimeException($editor->get_error_message());
}

acf_update_field_group([
    'key' => 'group_wprism_post',
    'title' => 'Hostile target schema',
    'fields' => [],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'page']]],
    'active' => false,
]);
$groupPosts = get_posts([
    'post_type' => 'acf-field-group', 'name' => 'group_wprism_post',
    'posts_per_page' => 1, 'fields' => 'ids', 'post_status' => 'any',
]);
$group = $groupPosts ? (int) $groupPosts[0] : 0;
if (!$group) {
    throw new RuntimeException('hostile ACF group was not created');
}
acf_update_field([
    'key' => 'field_wprism_hero', 'label' => 'Hostile Boolean', 'name' => 'wprism_hero',
    'type' => 'true_false', 'parent' => $group,
]);

$postIds = [];
foreach ([
    ['post', 'conf-related-target-one', 'Hostile Related One'],
    ['post', 'conf-related-target-two', 'Hostile Related Two'],
    ['page', 'conf-linked-page-one', 'Hostile Page One'],
    ['page', 'conf-linked-page-two', 'Hostile Page Two'],
    ['post', 'conf-acf-content', 'Hostile ACF Content'],
] as [$type, $slug, $title]) {
    $id = wp_insert_post([
        'post_type' => $type, 'post_status' => 'publish',
        'post_title' => $title, 'post_name' => $slug,
        'post_content' => '<p>hostile target content</p>',
    ], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $postIds[$slug] = (int) $id;
}

$termIds = [];
foreach (['one', 'two', 'three'] as $suffix) {
    $term = wp_insert_term("Hostile Category $suffix", 'category', ['slug' => "conf-cat-$suffix"]);
    if (is_wp_error($term)) {
        throw new RuntimeException($term->get_error_message());
    }
    $termIds[$suffix] = (int) $term['term_id'];
}

update_field('field_wprism_hero', 1, $postIds['conf-acf-content']);
update_field('field_wprism_user_note', 'target-only stale user value', 'user_' . (int) $editor);
update_option('options_wprism_option_note', 'target-only stale option value');
update_option('_options_wprism_option_note', 'field_wprism_option_note');
update_option('acf_first_activated_version', 'target-runtime-first-activation');
update_option('acf_site_health', [
    'target' => true,
    'nonce_like_neighbor' => 'target-runtime-preserved',
]);

echo wp_json_encode([
    'content' => $postIds['conf-acf-content'],
    'editor' => (int) $editor,
    'group' => $group,
    'hero_field' => (int) acf_get_field('field_wprism_hero')['ID'],
    'posts' => $postIds,
    'terms' => $termIds,
    'runtime_first' => get_option('acf_first_activated_version'),
    'runtime_health' => get_option('acf_site_health'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF

HOSTILE_OUT=$(wp_conf2 eval-file /siterepo/.tmp-acf-hostile.php)
rm -f "$HOSTILE_FILE"
require_observed_nonempty "conf2 ACF hostile target output" "$HOSTILE_OUT"
HOSTILE_JSON=$(printf '%s\n' "$HOSTILE_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$HOSTILE_JSON" | jq -e '
  .content > 12 and .editor > 4 and .group > 12 and .hero_field > 12 and
  (.posts | length) == 5 and (.terms | length) == 3 and
  .runtime_first == "target-runtime-first-activation" and
  .runtime_health.nonce_like_neighbor == "target-runtime-preserved"
' >/dev/null || fail "ACF hostile target premise was incomplete: $HOSTILE_JSON"
printf '%s\n' "$HOSTILE_JSON" > "${CONF_REPO2:-siterepo/conf2}/.tmp-acf-target.json"
pass "ACF target starts with divergent users/posts/schemas, same-slug collisions, stale values, and runtime-owned neighbor state"
