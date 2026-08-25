#!/usr/bin/env bash
# Hostile Polylang target after deploy: source-natural-key posts, terms and
# media already exist at disjoint ids; the target owns a stale default-language
# projection and environment-only option siblings. Apply must adopt only the
# declared authored graph, preserve target runtime state, and let the provider
# prove the three derived projections from fresh target-local ids.
set -euo pipefail

TARGET_REPO="${CONF_REPO2:-siterepo/conf2}"
HOSTILE_FILE="$TARGET_REPO/.tmp-polylang-hostile.php"

cat > "$HOSTILE_FILE" <<'PHPEOF'
<?php
global $wpdb;

$wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 5100001");
$wpdb->query("ALTER TABLE {$wpdb->terms} AUTO_INCREMENT = 5200001");
$wpdb->query("ALTER TABLE {$wpdb->term_taxonomy} AUTO_INCREMENT = 5300001");
if ($wpdb->last_error !== '') {
    throw new RuntimeException('Polylang target high-identity premise failed');
}

$terms = [];
foreach ([
    'en' => ['Target stale News', 'conformance-polylang-news-en'],
    'fr' => ['Target stale Actualites', 'conformance-polylang-news-fr'],
    'ar' => ['Target stale Arabic', 'conformance-polylang-news-ar'],
] as $language => [$name, $slug]) {
    $created = wp_insert_term($name, 'category', ['slug' => $slug]);
    if (is_wp_error($created)) {
        throw new RuntimeException('Polylang target category creation failed: ' . $created->get_error_message());
    }
    $terms[$language] = (int) $created['term_id'];
}

$posts = [];
foreach ([
    'en' => 'portable-polylang-story-en',
    'fr' => 'portable-polylang-story-fr',
    'ar' => 'portable-polylang-story-ar',
] as $language => $slug) {
    $created = wp_insert_post([
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_title' => "Hostile target $language story",
        'post_name' => $slug,
        'post_content' => "Hostile target $language content that must not survive adoption.",
    ], true);
    if (is_wp_error($created)) {
        throw new RuntimeException('Polylang target post creation failed: ' . $created->get_error_message());
    }
    $storedPost = get_post((int) $created);
    if (!$storedPost instanceof WP_Post || $storedPost->post_name !== $slug) {
        throw new RuntimeException("Polylang target post natural-key premise failed: $slug");
    }
    $posts[$language] = (int) $created;
    wp_set_object_terms((int) $created, [$terms[$language]], 'category', false);
}

// Same source-natural keys, deliberately different persistent statuses and
// bytes: Apply must adopt these target rows while restoring source-authored
// records and the target-local Polylang translation ids.
$publisher = get_user_by('login', 'admin');
if (!$publisher instanceof WP_User) {
    throw new RuntimeException('Polylang target publisher premise is missing');
}
$previousUserId = get_current_user_id();
wp_set_current_user((int) $publisher->ID);
if (!current_user_can('publish_posts')) {
    throw new RuntimeException('Polylang target publisher lacks publish_posts');
}
$pages = [];
foreach ([
    'en' => ['portable-polylang-page-en', 'draft'],
    'fr' => ['portable-polylang-page-fr', 'publish'],
    'ar' => ['portable-polylang-page-ar', 'pending'],
] as $language => [$slug, $status]) {
    $created = wp_insert_post([
        'post_type' => 'page',
        'post_status' => $status,
        'post_title' => "Hostile target $language page",
        'post_name' => $slug,
        'post_content' => "Hostile target $language page content that must not survive adoption.",
        'post_author' => 0,
    ], true);
    if (is_wp_error($created)) {
        throw new RuntimeException('Polylang target page creation failed: ' . $created->get_error_message());
    }
    $storedPage = get_post((int) $created);
    if (!$storedPage instanceof WP_Post
        || $storedPage->post_name !== $slug
        || $storedPage->post_status !== $status) {
        throw new RuntimeException("Polylang target page natural-key/status premise failed: $slug/$status");
    }
    $pages[$language] = (int) $created;
}

$blocks = [];
foreach ([
    'en' => ['portable-polylang-block-en', 'private'],
    'fr' => ['portable-polylang-block-fr', 'draft'],
    'ar' => ['portable-polylang-block-ar', 'pending'],
] as $language => [$slug, $status]) {
    $created = wp_insert_post([
        'post_type' => 'wp_block',
        'post_status' => $status,
        'post_title' => "Hostile target $language pattern",
        'post_name' => $slug,
        'post_content' => "Hostile target $language pattern content that must not survive adoption.",
        'post_author' => 0,
    ], true);
    if (is_wp_error($created)) {
        throw new RuntimeException('Polylang target synced-pattern creation failed: ' . $created->get_error_message());
    }
    $storedBlock = get_post((int) $created);
    if (!$storedBlock instanceof WP_Post
        || $storedBlock->post_name !== $slug
        || $storedBlock->post_status !== $status) {
        throw new RuntimeException("Polylang target wp_block natural-key/status premise failed: $slug/$status");
    }
    $blocks[$language] = (int) $created;
}
wp_set_current_user($previousUserId);

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
$attachments = [];
foreach (['en', 'fr', 'ar'] as $index => $language) {
    $path = "/tmp/duo-polylang-media-$language.png";
    $image = imagecreatetruecolor(72, 48);
    if (!$image) {
        throw new RuntimeException('Polylang target media image allocation failed');
    }
    imagefilledrectangle(
        $image,
        0,
        0,
        71,
        47,
        imagecolorallocate($image, 180 - ($index * 20), 30 + ($index * 30), 60)
    );
    imagepng($image, $path);
    imagedestroy($image);
    $attachmentId = media_handle_sideload(
        ['name' => "duo-polylang-media-$language.png", 'tmp_name' => $path],
        $posts[$language],
        "Polylang Media $language 東京 🚀"
    );
    if (is_wp_error($attachmentId)) {
        throw new RuntimeException('Polylang target media import failed: ' . $attachmentId->get_error_message());
    }
    $attachments[$language] = (int) $attachmentId;
    $renamed = wp_update_post([
        'ID' => (int) $attachmentId,
        'post_name' => "duo-polylang-media-$language",
    ], true);
    if (is_wp_error($renamed)) {
        throw new RuntimeException('Polylang target media identity normalization failed');
    }
    update_post_meta((int) $attachmentId, '_wp_attachment_image_alt', "target-stale-$language");
}

$staleMenu = wp_create_nav_menu('Polylang Primary English');
if (is_wp_error($staleMenu)) {
    throw new RuntimeException('Polylang target stale menu creation failed');
}
wp_update_nav_menu_item((int) $staleMenu, 0, [
    'menu-item-title' => 'target-stale-menu-marker',
    'menu-item-url' => home_url('/target-stale-menu/'),
    'menu-item-status' => 'publish',
    'menu-item-type' => 'custom',
]);

$stylesheet = (string) get_option('stylesheet');
$options = get_option('polylang');
if (!is_array($options)) {
    throw new RuntimeException('Polylang target option is not an array');
}
$options['browser'] = true;
$options['default_lang'] = 'ar';
$options['force_lang'] = 1;
$options['hide_default'] = true;
$options['media_support'] = 0;
$options['post_types'] = ['target_runtime_type'];
$options['redirect_lang'] = true;
$options['rewrite'] = false;
$options['taxonomies'] = ['target_runtime_taxonomy'];
$options['sync'] = ['comment_status'];
$options['nav_menus'] = [];
// Keep the stale target inside the portable topology contract; the runtime
// marker below is deliberately target-owned sentinel data, not the exact
// 'yes' capability required by force_lang mode 0.
$options['force_lang'] = 1;
$options['hide_default'] = 1;
$options['browser'] = 1;
update_option('polylang', $options);
update_option('pll_language_from_content_available', 'target-runtime-sentinel');
update_option('pll_language_taxonomies', ['target-runtime-taxonomy-cache']);
update_option('duo_polylang_undeclared_neighbor', 'target-only-preserved');
set_theme_mod('nav_menu_locations', ['primary' => (int) $staleMenu]);
update_option('rewrite_rules', ['^target-stale/?$' => 'index.php?target-stale=1']);

$result = [
    'attachments' => $attachments,
    'blocks' => $blocks,
    'menus' => ['en' => (int) $staleMenu],
    'pages' => $pages,
    'posts' => $posts,
    'terms' => $terms,
];
foreach (array_merge($posts, $pages, $blocks, $attachments) as $id) {
    if ($id < 5100001) {
        throw new RuntimeException('Polylang target post/page/pattern/media high-identity premise failed');
    }
}
foreach (array_merge($terms, [(int) $staleMenu]) as $id) {
    if ($id < 5200001) {
        throw new RuntimeException('Polylang target term/menu high-identity premise failed');
    }
}
echo wp_json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF

HOSTILE_OUT=$(wp_conf2 eval-file /siterepo/.tmp-polylang-hostile.php)
rm -f "$HOSTILE_FILE"
require_observed_nonempty 'conf2 Polylang hostile target output' "$HOSTILE_OUT"
TARGET_JSON=$(printf '%s\n' "$HOSTILE_OUT" | awk 'NF { line=$0 } END { print line }')
jq -e '
  (.posts | to_entries | all(.value >= 5100001)) and
  (.pages | to_entries | all(.value >= 5100001)) and
  (.blocks | to_entries | all(.value >= 5100001)) and
  (.attachments | to_entries | all(.value >= 5100001)) and
  (.terms | to_entries | all(.value >= 5200001)) and .menus.en >= 5200001
' <<<"$TARGET_JSON" >/dev/null || fail "Polylang hostile target premise was incomplete: $TARGET_JSON"
printf '%s\n' "$TARGET_JSON" > "$TARGET_REPO/.tmp-polylang-target.json"

SOURCE_JSON=$(cat "${CONF_REPO1:-siterepo/conf1}/.tmp-polylang-source.json")
SOURCE_POST=$(jq -r '.posts.en' <<<"$SOURCE_JSON")
TARGET_POST=$(jq -r '.posts.en' <<<"$TARGET_JSON")
SOURCE_PAGE=$(jq -r '.pages.en' <<<"$SOURCE_JSON")
TARGET_PAGE=$(jq -r '.pages.en' <<<"$TARGET_JSON")
SOURCE_BLOCK=$(jq -r '.blocks.en' <<<"$SOURCE_JSON")
TARGET_BLOCK=$(jq -r '.blocks.en' <<<"$TARGET_JSON")
SOURCE_TERM=$(jq -r '.terms.en' <<<"$SOURCE_JSON")
TARGET_TERM=$(jq -r '.terms.en' <<<"$TARGET_JSON")
require_fixture_ids SOURCE_POST TARGET_POST SOURCE_PAGE TARGET_PAGE SOURCE_BLOCK TARGET_BLOCK SOURCE_TERM TARGET_TERM
[ "$SOURCE_POST" != "$TARGET_POST" ] && [ "$SOURCE_PAGE" != "$TARGET_PAGE" ] && [ "$SOURCE_BLOCK" != "$TARGET_BLOCK" ] && [ "$SOURCE_TERM" != "$TARGET_TERM" ] \
  || fail "Polylang source/target hostile identities did not diverge: source=$SOURCE_POST/$SOURCE_PAGE/$SOURCE_BLOCK/$SOURCE_TERM target=$TARGET_POST/$TARGET_PAGE/$TARGET_BLOCK/$TARGET_TERM"
pass 'Polylang target begins with divergent same-key post/page/pattern content and statuses, stale derived state, and target-owned runtime siblings'
