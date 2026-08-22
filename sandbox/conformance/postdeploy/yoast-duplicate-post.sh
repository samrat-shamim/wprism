#!/usr/bin/env bash
# Build a valid but hostile target after exact-code deploy: same-slug posts and
# terms at different local IDs, competing settings/meta, inverted role caps,
# and target-owned Rewrite & Republish runtime residue.
set -euo pipefail

read -r -d '' HOSTILE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
require_once DUPLICATE_POST_PATH . 'admin-functions.php';
duplicate_post_admin_init();

if (!get_role('duo_reviewer')) {
    add_role('duo_reviewer', 'Duo Reviewer', ['read' => true, 'edit_posts' => true]);
}
for ($i = 0; $i < 12; $i++) {
    $spacer = wp_insert_post([
        'post_type' => 'post', 'post_status' => 'draft',
        'post_title' => "Target identity spacer $i",
    ], true);
    if (is_wp_error($spacer) || !$spacer || !wp_delete_post((int) $spacer, true)) {
        throw new RuntimeException('could not advance the target post identity sequence');
    }
}
global $wpdb;
if (false === $wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 9100001")) {
    throw new RuntimeException('could not establish the large target post-id boundary');
}

$category = wp_insert_term('Target Duplicate Category', 'category', ['slug' => 'duo-duplicate-category']);
$tag = wp_insert_term('Target Duplicate Tag', 'post_tag', ['slug' => 'duo-duplicate-tag']);
if (is_wp_error($category) || is_wp_error($tag)) {
    throw new RuntimeException('could not create target taxonomy identities');
}
$original = wp_insert_post([
    'post_type' => 'post', 'post_status' => 'private',
    'post_title' => 'Target hostile original', 'post_name' => 'duo-duplicate-original',
    'post_excerpt' => 'target-original-excerpt', 'post_content' => 'target-original-body',
    'menu_order' => 99,
], true);
$copy = wp_insert_post([
    'post_type' => 'post', 'post_status' => 'publish',
    'post_title' => 'Target hostile copy', 'post_name' => 'duo-duplicate-copy',
    'post_excerpt' => 'target-copy-excerpt', 'post_content' => 'target-copy-body',
    'menu_order' => 88,
], true);
if (is_wp_error($original) || !$original || is_wp_error($copy) || !$copy) {
    throw new RuntimeException('could not create hostile target post identities');
}
wp_set_object_terms((int) $original, [(int) $category['term_id']], 'category');
wp_set_object_terms((int) $copy, [(int) $tag['term_id']], 'post_tag');
update_post_meta((int) $copy, '_dp_original', (int) $copy);
update_post_meta((int) $copy, '_duo_copy_me', 'target-hostile-meta');

// Runtime state is deliberately malformed/hostile but target-sovereign. The
// authored apply must neither capture nor rewrite these bytes.
update_post_meta((int) $copy, '_dp_is_rewrite_republish_copy', 1);
update_post_meta((int) $copy, '_dp_creation_date_gmt', 'not-a-date-東京-🚀');
update_post_meta((int) $original, '_dp_has_rewrite_republish_copy', (string) $copy);
update_post_meta((int) $original, '_dp_has_been_republished', '999999999999999999999999');

$settings = [
    'duplicate_post_blacklist' => 'target_only*',
    'duplicate_post_copyattachments' => '1',
    'duplicate_post_copyauthor' => '0',
    'duplicate_post_copychildren' => '1',
    'duplicate_post_copycomments' => '1',
    'duplicate_post_copycontent' => '0',
    'duplicate_post_copydate' => '1',
    'duplicate_post_copyexcerpt' => '0',
    'duplicate_post_copyformat' => '0',
    'duplicate_post_copymenuorder' => '0',
    'duplicate_post_copypassword' => '1',
    'duplicate_post_copyslug' => '1',
    'duplicate_post_copystatus' => '1',
    'duplicate_post_copytemplate' => '0',
    'duplicate_post_copythumbnail' => '1',
    'duplicate_post_copytitle' => '0',
    'duplicate_post_increase_menu_order_by' => '-9',
    'duplicate_post_roles' => ['editor', 'subscriber'],
    'duplicate_post_show_link' => ['new_draft' => '0', 'clone' => '1', 'rewrite_republish' => '0'],
    'duplicate_post_show_link_in' => ['row' => '0', 'adminbar' => '0', 'submitbox' => '1', 'bulkactions' => '0'],
    'duplicate_post_show_notice' => '1',
    'duplicate_post_show_original_column' => '0',
    'duplicate_post_show_original_in_post_states' => '0',
    'duplicate_post_show_original_meta_box' => '0',
    'duplicate_post_taxonomies_blacklist' => ['category', 'post_tag'],
    'duplicate_post_title_prefix' => 'TARGET PREFIX',
    'duplicate_post_title_suffix' => 'TARGET SUFFIX',
    'duplicate_post_types_enabled' => ['page'],
];
foreach ($settings as $name => $value) {
    update_option($name, $value);
}
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
update_option('yoast_duplicate_post_target_neighbor', 'target-only-neighbor');

$roles = [];
foreach (['administrator', 'duo_reviewer', 'editor', 'subscriber'] as $roleName) {
    $role = get_role($roleName);
    $roles[$roleName] = $role ? $role->has_cap('copy_posts') : null;
}
echo wp_json_encode([
    'copy_id' => (int) $copy,
    'original_id' => (int) $original,
    'original_ref' => (int) get_post_meta((int) $copy, '_dp_original', true),
    'roles' => $roles,
    'runtime_copy' => get_post_meta((int) $original, '_dp_has_rewrite_republish_copy', true),
    'settings_count' => count($settings),
    'version' => get_option('duplicate_post_version'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHPEOF

HOSTILE_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-yoast-duplicate-post-hostile.php"
printf '%s' "$HOSTILE_PHP" > "$HOSTILE_FILE"
HOSTILE_OUT=$(wp_conf2 eval-file /siterepo/.tmp-yoast-duplicate-post-hostile.php)
rm -f "$HOSTILE_FILE"
require_observed_nonempty "Yoast Duplicate Post hostile target seed" "$HOSTILE_OUT"
HOSTILE_JSON=$(printf '%s\n' "$HOSTILE_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$HOSTILE_JSON" | jq -e '
  .settings_count == 28 and .original_id >= 9100001 and .copy_id > .original_id and
  .original_ref == .copy_id and (.runtime_copy | tonumber) == .copy_id and
  .roles.administrator == false and .roles.duo_reviewer == false and
  .roles.editor == true and .roles.subscriber == true and .version == "4.7"
' >/dev/null || fail "Yoast Duplicate Post hostile target premises did not land: $HOSTILE_JSON"
pass "Yoast Duplicate Post target starts with divergent identities, hostile settings/refs, inverted role caps, runtime workflow residue, and an undeclared neighbor"
