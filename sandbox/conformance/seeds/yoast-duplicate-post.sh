#!/usr/bin/env bash
# Yoast Duplicate Post 4.7 production fixture: the complete settings registry,
# a native role-settings projection, hostile UTF-8/LONGTEXT values, tax/meta
# copy semantics, and a durable original-post reference created by the plugin.
set -euo pipefail

read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
require_once DUPLICATE_POST_PATH . 'admin-functions.php';
if (!function_exists('duplicate_post_create_duplicate')) {
    throw new RuntimeException('Yoast Duplicate Post admin cloning API is unavailable');
}
duplicate_post_admin_init();

if (!get_role('duo_reviewer')) {
    add_role('duo_reviewer', 'Duo Reviewer', ['read' => true, 'edit_posts' => true]);
}

$prefix = str_repeat('複製✨', 14) . ' |';
$suffix = '| ' . str_repeat('鏡像🚀', 12);
$settings = [
    'duplicate_post_blacklist' => '_duo_skip*, _edit_lock',
    'duplicate_post_copyattachments' => '0',
    'duplicate_post_copyauthor' => '1',
    'duplicate_post_copychildren' => '0',
    'duplicate_post_copycomments' => '0',
    'duplicate_post_copycontent' => '1',
    'duplicate_post_copydate' => '0',
    'duplicate_post_copyexcerpt' => '1',
    'duplicate_post_copyformat' => '1',
    'duplicate_post_copymenuorder' => '1',
    'duplicate_post_copypassword' => '0',
    'duplicate_post_copyslug' => '0',
    'duplicate_post_copystatus' => '0',
    'duplicate_post_copytemplate' => '1',
    'duplicate_post_copythumbnail' => '0',
    'duplicate_post_copytitle' => '1',
    'duplicate_post_increase_menu_order_by' => '17',
    'duplicate_post_roles' => ['administrator', 'duo_reviewer'],
    'duplicate_post_show_link' => ['new_draft' => '1', 'clone' => '1', 'rewrite_republish' => '1'],
    'duplicate_post_show_link_in' => ['row' => '1', 'adminbar' => '1', 'submitbox' => '1', 'bulkactions' => '1'],
    'duplicate_post_show_notice' => '0',
    'duplicate_post_show_original_column' => '1',
    'duplicate_post_show_original_in_post_states' => '1',
    'duplicate_post_show_original_meta_box' => '1',
    'duplicate_post_taxonomies_blacklist' => [],
    'duplicate_post_title_prefix' => $prefix,
    'duplicate_post_title_suffix' => $suffix,
    'duplicate_post_types_enabled' => ['post', 'page'],
];
foreach ($settings as $name => $value) {
    update_option($name, $value);
}

// Exercise the plugin's own settings-screen projection rather than granting
// copy_posts directly in the fixture.
$_GET['settings-updated'] = 'true';
$optionsPage = new Yoast\WP\Duplicate_Post\Admin\Options_Page(
    new Yoast\WP\Duplicate_Post\Admin\Options(),
    new Yoast\WP\Duplicate_Post\Admin\Options_Form_Generator(
        new Yoast\WP\Duplicate_Post\Admin\Options_Inputs()
    ),
    new Yoast\WP\Duplicate_Post\UI\Asset_Manager()
);
$optionsPage->register_capabilities();
unset($_GET['settings-updated']);

for ($i = 0; $i < 5; $i++) {
    $spacer = wp_insert_post([
        'post_type' => 'post', 'post_status' => 'draft',
        'post_title' => "Source identity spacer $i",
    ], true);
    if (is_wp_error($spacer) || !$spacer || !wp_delete_post((int) $spacer, true)) {
        throw new RuntimeException('could not advance the source post identity sequence');
    }
}
global $wpdb;
if (false === $wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 3100001")) {
    throw new RuntimeException('could not establish the large source post-id boundary');
}

$category = wp_insert_term('Duo Duplicate Category 東京', 'category', ['slug' => 'duo-duplicate-category']);
$tag = wp_insert_term('Duo Duplicate Tag 🚀', 'post_tag', ['slug' => 'duo-duplicate-tag']);
if (is_wp_error($category) || is_wp_error($tag)) {
    throw new RuntimeException('could not create Duplicate Post taxonomy fixtures');
}
$long = str_repeat("東京🚀|comma,quote\"apostrophe'backslash\\\n", 700);
$original = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_title' => 'Duo Duplicate Original 東京 🚀',
    'post_name' => 'duo-duplicate-original',
    'post_excerpt' => 'Portable excerpt 東京 🚀',
    'post_content' => "<!-- wp:paragraph --><p>Duo original marker 東京 🚀</p><!-- /wp:paragraph -->\n$long",
    'menu_order' => 7,
], true);
if (is_wp_error($original) || !$original) {
    throw new RuntimeException('could not create the Duplicate Post original');
}
wp_set_object_terms((int) $original, [(int) $category['term_id']], 'category');
wp_set_object_terms((int) $original, [(int) $tag['term_id']], 'post_tag');
update_post_meta((int) $original, '_duo_copy_me', "portable-meta 東京 🚀 | quote\" apostrophe' slash\\");
update_post_meta((int) $original, '_duo_skip_runtime', 'must-not-copy');

$copy = duplicate_post_create_duplicate(get_post((int) $original));
if (is_wp_error($copy) || !$copy) {
    throw new RuntimeException('Yoast Duplicate Post native clone failed');
}
wp_update_post(['ID' => (int) $copy, 'post_name' => 'duo-duplicate-copy']);

$originalPost = duplicate_post_get_original((int) $copy);
$roles = [];
foreach (['administrator', 'duo_reviewer', 'editor', 'subscriber'] as $roleName) {
    $role = get_role($roleName);
    $roles[$roleName] = $role ? $role->has_cap('copy_posts') : null;
}
$copyPost = get_post((int) $copy);
echo wp_json_encode([
    'copy_id' => (int) $copy,
    'copy_menu_order' => (int) $copyPost->menu_order,
    'copy_status' => $copyPost->post_status,
    'copy_title' => $copyPost->post_title,
    'copied_category' => wp_get_post_terms((int) $copy, 'category', ['fields' => 'slugs']),
    'copied_meta' => get_post_meta((int) $copy, '_duo_copy_me', true),
    'copied_tag' => wp_get_post_terms((int) $copy, 'post_tag', ['fields' => 'slugs']),
    'excluded_meta' => get_post_meta((int) $copy, '_duo_skip_runtime', true),
    'original_id' => (int) $original,
    'original_via_api' => $originalPost ? (int) $originalPost->ID : 0,
    'prefix' => $prefix,
    'roles' => $roles,
    'settings_count' => count($settings),
    'suffix' => $suffix,
    'version' => get_option('duplicate_post_version'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHPEOF

SEED_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-yoast-duplicate-post-seed.php"
printf '%s' "$SEED_PHP" > "$SEED_FILE"
SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-yoast-duplicate-post-seed.php)
rm -f "$SEED_FILE"
require_observed_nonempty "Yoast Duplicate Post source seed" "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$SEED_JSON" | jq -e '
  .settings_count == 28 and .original_id >= 3100001 and .copy_id > .original_id and
  .original_via_api == .original_id and .copy_status == "draft" and
  .copy_menu_order == 24 and (.copy_title | contains("Duo Duplicate Original")) and
  .copied_category == ["duo-duplicate-category"] and .copied_tag == ["duo-duplicate-tag"] and
  (.copied_meta | contains("portable-meta 東京 🚀")) and .excluded_meta == "" and
  .roles.administrator == true and .roles.duo_reviewer == true and
  .roles.editor == false and .roles.subscriber == false and .version == "4.7"
' >/dev/null || fail "Yoast Duplicate Post source fixture did not establish the native/settings/reference premises: $SEED_JSON"
pass "Yoast Duplicate Post authored all settings, native role policy, hostile bytes, native clone semantics, and _dp_original through exact 4.7 APIs"
