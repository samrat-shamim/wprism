#!/usr/bin/env bash
# Exact ACF 6.8.7 source fixture. Every value is authored through ACF's public
# APIs so the proof covers the plugin's real storage shapes rather than a
# hand-built approximation of its post/meta/options records.
set -euo pipefail

SEED_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-seed-acf.php"
cat > "$SEED_FILE" <<'PHPEOF'
<?php
if (!function_exists('acf_update_field_group')) {
    throw new RuntimeException('ACF functions not available');
}

function wprism_acf_group(array $group): int {
    acf_update_field_group($group);
    $posts = get_posts([
        'post_type' => 'acf-field-group',
        'name' => $group['key'],
        'posts_per_page' => 1,
        'fields' => 'ids',
        'post_status' => 'any',
    ]);
    if (!$posts) {
        throw new RuntimeException("ACF field group {$group['key']} was not created");
    }
    return (int) $posts[0];
}

function wprism_acf_field(int $parent, array $field): void {
    $field['parent'] = $parent;
    acf_update_field($field);
    if (!acf_get_field($field['key'])) {
        throw new RuntimeException("ACF field {$field['key']} was not created");
    }
}

function wprism_acf_attachment(string $basename, string $title, array $rgb): int {
    require_once ABSPATH . 'wp-admin/includes/image.php';
    require_once ABSPATH . 'wp-admin/includes/file.php';
    require_once ABSPATH . 'wp-admin/includes/media.php';
    $upload = wp_upload_dir();
    $filename = trailingslashit($upload['path']) . $basename;
    $image = imagecreatetruecolor(64, 48);
    imagefilledrectangle($image, 0, 0, 63, 47, imagecolorallocate($image, ...$rgb));
    imagepng($image, $filename);
    imagedestroy($image);
    $filetype = wp_check_filetype(basename($filename), null);
    $id = wp_insert_attachment([
        'post_mime_type' => $filetype['type'],
        'post_title' => $title,
        'post_content' => '',
        'post_status' => 'inherit',
    ], $filename, 0, true);
    if (is_wp_error($id) || !$id) {
        throw new RuntimeException("ACF attachment $title was not created");
    }
    update_post_meta($id, '_wp_attachment_image_alt', "$title 東京 🚀");
    wp_update_attachment_metadata($id, wp_generate_attachment_metadata($id, $filename));
    return (int) $id;
}

$sourceHome = home_url('/');
$longInstruction = str_repeat("ACF schema 東京 🚀 | delimiter :: $sourceHome\n", 1200);
$postGroup = wprism_acf_group([
    'key' => 'group_wprism_post',
    'title' => 'WPrism Post Fields 東京 🚀',
    'fields' => [],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
    'menu_order' => 0,
    'position' => 'normal',
    'style' => 'default',
    'label_placement' => 'top',
    'instruction_placement' => 'label',
    'active' => true,
]);

wprism_acf_field($postGroup, [
    'key' => 'field_wprism_hero', 'label' => 'Hero Image', 'name' => 'wprism_hero',
    'type' => 'image', 'return_format' => 'id', 'instructions' => $longInstruction,
]);
wprism_acf_field($postGroup, [
    'key' => 'field_wprism_related', 'label' => 'Related', 'name' => 'wprism_related',
    'type' => 'relationship', 'post_type' => ['post'], 'return_format' => 'id',
]);
wprism_acf_field($postGroup, [
    'key' => 'field_wprism_feature', 'label' => 'Feature', 'name' => 'wprism_feature',
    'type' => 'post_object', 'post_type' => ['post'], 'multiple' => 0, 'return_format' => 'id',
]);
wprism_acf_field($postGroup, [
    'key' => 'field_wprism_features', 'label' => 'Features', 'name' => 'wprism_features',
    'type' => 'post_object', 'post_type' => ['post'], 'multiple' => 1, 'return_format' => 'id',
]);
wprism_acf_field($postGroup, [
    'key' => 'field_wprism_page', 'label' => 'Page Link', 'name' => 'wprism_page',
    'type' => 'page_link', 'post_type' => ['page'], 'multiple' => 0, 'allow_archives' => 0,
]);
wprism_acf_field($postGroup, [
    'key' => 'field_wprism_pages', 'label' => 'Page Links', 'name' => 'wprism_pages',
    'type' => 'page_link', 'post_type' => ['page'], 'multiple' => 1, 'allow_archives' => 0,
]);
wprism_acf_field($postGroup, [
    'key' => 'field_wprism_cats', 'label' => 'Categories', 'name' => 'wprism_cats',
    'type' => 'taxonomy', 'taxonomy' => 'category', 'field_type' => 'checkbox',
    'add_term' => 0, 'save_terms' => 0, 'load_terms' => 0, 'return_format' => 'id',
]);
wprism_acf_field($postGroup, [
    'key' => 'field_wprism_cat', 'label' => 'Primary Category', 'name' => 'wprism_cat',
    'type' => 'taxonomy', 'taxonomy' => 'category', 'field_type' => 'radio',
    'add_term' => 0, 'save_terms' => 0, 'load_terms' => 0, 'return_format' => 'id',
]);
wprism_acf_field($postGroup, [
    'key' => 'field_wprism_owner', 'label' => 'Owner', 'name' => 'wprism_owner',
    'type' => 'user', 'role' => '', 'multiple' => 0, 'return_format' => 'id',
]);
wprism_acf_field($postGroup, [
    'key' => 'field_wprism_owners', 'label' => 'Owners', 'name' => 'wprism_owners',
    'type' => 'user', 'role' => '', 'multiple' => 1, 'return_format' => 'id',
]);
wprism_acf_field($postGroup, [
    'key' => 'field_wprism_link', 'label' => 'Portable Link', 'name' => 'wprism_link',
    'type' => 'link', 'return_format' => 'array',
]);
wprism_acf_field($postGroup, [
    'key' => 'field_wprism_icon', 'label' => 'Media Icon', 'name' => 'wprism_icon',
    'type' => 'icon_picker', 'tabs' => ['media_library'], 'return_format' => 'array',
]);
// This unused field is removed later to prove the unsupported delete boundary.
wprism_acf_field($postGroup, [
    'key' => 'field_wprism_delete_probe', 'label' => 'Delete Probe', 'name' => 'wprism_delete_probe',
    'type' => 'text',
]);

$termGroup = wprism_acf_group([
    'key' => 'group_wprism_term', 'title' => 'WPrism Term Fields', 'fields' => [],
    'location' => [[['param' => 'taxonomy', 'operator' => '==', 'value' => 'category']]],
    'active' => true,
]);
wprism_acf_field($termGroup, [
    'key' => 'field_wprism_term_note', 'label' => 'Term Note', 'name' => 'wprism_term_note', 'type' => 'textarea',
]);
wprism_acf_field($termGroup, [
    'key' => 'field_wprism_term_image', 'label' => 'Term Image', 'name' => 'wprism_term_image',
    'type' => 'image', 'return_format' => 'id',
]);

$userGroup = wprism_acf_group([
    'key' => 'group_wprism_user', 'title' => 'WPrism User Fields', 'fields' => [],
    'location' => [[['param' => 'user_form', 'operator' => '==', 'value' => 'all']]],
    'active' => true,
]);
wprism_acf_field($userGroup, [
    'key' => 'field_wprism_user_note', 'label' => 'User Note', 'name' => 'wprism_user_note', 'type' => 'text',
]);
wprism_acf_field($userGroup, [
    'key' => 'field_wprism_user_image', 'label' => 'User Image', 'name' => 'wprism_user_image',
    'type' => 'image', 'return_format' => 'id',
]);

$optionsGroup = wprism_acf_group([
    'key' => 'group_wprism_options', 'title' => 'WPrism Options Fields', 'fields' => [],
    'location' => [[['param' => 'post_type', 'operator' => '==', 'value' => 'post']]],
    'active' => true,
]);
wprism_acf_field($optionsGroup, [
    'key' => 'field_wprism_option_note', 'label' => 'Option Note', 'name' => 'wprism_option_note', 'type' => 'text',
]);
wprism_acf_field($optionsGroup, [
    'key' => 'field_wprism_option_image', 'label' => 'Option Image', 'name' => 'wprism_option_image',
    'type' => 'image', 'return_format' => 'id',
]);

$menuGroup = wprism_acf_group([
    'key' => 'group_wprism_menu', 'title' => 'WPrism Menu Item Fields', 'fields' => [],
    'location' => [[['param' => 'nav_menu_item', 'operator' => '==', 'value' => 'all']]],
    'active' => true,
]);
wprism_acf_field($menuGroup, [
    'key' => 'field_wprism_menu_image', 'label' => 'Menu Image', 'name' => 'wprism_menu_image',
    'type' => 'image', 'return_format' => 'id',
]);

$hero = wprism_acf_attachment('conf-acf-hero.png', 'Conformance ACF Hero', [90, 60, 200]);
$secondary = wprism_acf_attachment('conf-acf-secondary.png', 'Conformance ACF Secondary', [20, 150, 90]);

$targets = [];
foreach (['One', 'Two'] as $suffix) {
    $id = wp_insert_post([
        'post_type' => 'post', 'post_status' => 'publish',
        'post_title' => "Conformance Related Target $suffix",
        'post_name' => 'conf-related-target-' . strtolower($suffix),
        'post_content' => "<!-- wp:paragraph -->\n<p>Relationship target $suffix.</p>\n<!-- /wp:paragraph -->",
    ], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $targets[] = (int) $id;
}
$pages = [];
foreach (['One', 'Two'] as $suffix) {
    $id = wp_insert_post([
        'post_type' => 'page', 'post_status' => 'publish',
        'post_title' => "Conformance Linked Page $suffix",
        'post_name' => 'conf-linked-page-' . strtolower($suffix),
        'post_content' => "<!-- wp:paragraph -->\n<p>Page link target $suffix.</p>\n<!-- /wp:paragraph -->",
    ], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    $pages[] = (int) $id;
}
$content = wp_insert_post([
    'post_type' => 'post', 'post_status' => 'publish',
    'post_title' => 'Conformance ACF Content', 'post_name' => 'conf-acf-content',
    'post_content' => "<!-- wp:paragraph -->\n<p>Carries ACF fields.</p>\n<!-- /wp:paragraph -->",
], true);
if (is_wp_error($content)) {
    throw new RuntimeException($content->get_error_message());
}
$content = (int) $content;

$terms = [];
foreach (['One', 'Two', 'Three'] as $suffix) {
    $term = wp_insert_term("Conformance Category $suffix", 'category', [
        'slug' => 'conf-cat-' . strtolower($suffix),
    ]);
    if (is_wp_error($term)) {
        throw new RuntimeException($term->get_error_message());
    }
    $terms[] = (int) $term['term_id'];
}

$editorId = wp_insert_user([
    'user_login' => 'acf-editor',
    'user_pass' => wp_generate_password(32, true, true),
    'user_email' => 'acf-editor-source@example.test',
    'role' => 'editor',
]);
if (is_wp_error($editorId)) {
    throw new RuntimeException($editorId->get_error_message());
}
$admin = get_user_by('login', 'admin');
if (!$admin) {
    throw new RuntimeException('admin user not found');
}

$menuId = wp_create_nav_menu('Conformance ACF Menu');
if (is_wp_error($menuId)) {
    throw new RuntimeException($menuId->get_error_message());
}
$menuItem = wp_update_nav_menu_item((int) $menuId, 0, [
    'menu-item-title' => 'Conformance ACF Menu Item',
    'menu-item-url' => home_url('/conf-acf-content/'),
    'menu-item-status' => 'publish',
]);
if (is_wp_error($menuItem)) {
    throw new RuntimeException($menuItem->get_error_message());
}

update_field('field_wprism_hero', $hero, $content);
update_field('field_wprism_related', $targets, $content);
update_field('field_wprism_feature', $targets[0], $content);
update_field('field_wprism_features', $targets, $content);
update_field('field_wprism_page', $pages[0], $content);
update_field('field_wprism_pages', $pages, $content);
update_field('field_wprism_cats', [$terms[0], $terms[1]], $content);
update_field('field_wprism_cat', $terms[2], $content);
update_field('field_wprism_owner', (int) $admin->ID, $content);
update_field('field_wprism_owners', [(int) $admin->ID, (int) $editorId], $content);
update_field('field_wprism_link', [
    'title' => 'Portable source link 東京 🚀',
    'url' => home_url('/conf-linked-page-one/?from=acf&mode=real-world'),
    'target' => '_blank',
], $content);
update_field('field_wprism_icon', ['type' => 'media_library', 'value' => $secondary], $content);
update_field('field_wprism_term_note', "Term 東京 🚀 $sourceHome", 'category_' . $terms[0]);
update_field('field_wprism_term_image', $secondary, 'category_' . $terms[0]);
update_field('field_wprism_user_note', "User 東京 🚀 $sourceHome", 'user_' . (int) $editorId);
update_field('field_wprism_user_image', $hero, 'user_' . (int) $editorId);
update_field('field_wprism_option_note', "Option 東京 🚀 $sourceHome", 'option');
update_field('field_wprism_option_image', $secondary, 'option');
update_field('field_wprism_menu_image', $hero, (int) $menuItem);

echo wp_json_encode([
    'admin' => (int) $admin->ID,
    'content' => $content,
    'editor' => (int) $editorId,
    'group' => $postGroup,
    'hero' => $hero,
    'menu_item' => (int) $menuItem,
    'pages' => $pages,
    'secondary' => $secondary,
    'targets' => $targets,
    'terms' => $terms,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF

SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-seed-acf.php)
rm -f "$SEED_FILE"
require_observed_nonempty "conf1 ACF seed output" "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$SEED_JSON" | jq -e '
  .admin > 0 and .content > 0 and .editor > 0 and .group > 0 and
  .hero > 0 and .menu_item > 0 and .secondary > 0 and
  (.pages | length) == 2 and (.targets | length) == 2 and (.terms | length) == 3
' >/dev/null || fail "ACF source fixture was incomplete: $SEED_JSON"
printf '%s\n' "$SEED_JSON" > "${CONF_REPO1:-siterepo/conf1}/.tmp-acf-source.json"
echo "acf seed: $SEED_JSON"
