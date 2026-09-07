#!/usr/bin/env bash
# Exact-artifact Polylang authoring fixture. It uses plugin APIs for a
# three-language LTR/RTL post, term and media graph; real per-language menus;
# both optional switcher stores; high identities; and long UTF-8 content.
set -euo pipefail

SOURCE_REPO="${CONF_REPO1:-siterepo/conf1}"
SEED_FILE="$SOURCE_REPO/.tmp-polylang-seed.php"

cat > "$SEED_FILE" <<'PHPEOF'
<?php
global $wpdb;

$wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 4100001");
$wpdb->query("ALTER TABLE {$wpdb->terms} AUTO_INCREMENT = 4200001");
$wpdb->query("ALTER TABLE {$wpdb->term_taxonomy} AUTO_INCREMENT = 4300001");
if ($wpdb->last_error !== '') {
    throw new RuntimeException('Polylang source high-identity premise failed');
}

$languages = [
    ['locale' => 'en_US', 'slug' => 'en', 'name' => 'English', 'rtl' => 0, 'term_group' => 0, 'flag' => 'us'],
    ['locale' => 'fr_FR', 'slug' => 'fr', 'name' => 'Français 東京', 'rtl' => 0, 'term_group' => 1, 'flag' => 'fr'],
    ['locale' => 'ar', 'slug' => 'ar', 'name' => 'العربية 🚀', 'rtl' => 1, 'term_group' => 2, 'flag' => 'sa'],
];
if (isset(PLL()->model->languages)) {
    foreach ($languages as $args) {
        $result = PLL()->model->languages->add($args);
        if (is_wp_error($result) && $result->has_errors()) {
            throw new RuntimeException('Polylang language creation failed: ' . $result->get_error_message());
        }
    }
} else {
    $options =& PLL()->options;
    $model = new PLL_Admin_Model($options);
    foreach ($languages as $args) {
        $result = $model->add_language($args);
        if (is_wp_error($result) && $result->has_errors()) {
            throw new RuntimeException('Polylang language creation failed: ' . $result->get_error_message());
        }
    }
}

$options = get_option('polylang');
if (!is_array($options)) {
    throw new RuntimeException('Polylang source option is not an array');
}
$options['default_lang'] = 'en';
$options['browser'] = false;
$options['force_lang'] = 1;
$options['hide_default'] = false;
$options['media_support'] = 1;
$options['post_types'] = [];
$options['redirect_lang'] = false;
$options['rewrite'] = true;
$options['taxonomies'] = [];
$options['sync'] = ['taxonomies', 'post_meta', 'post_date'];
update_option('polylang', $options);

$insertTerm = static function (string $name, string $slug): int {
    $result = wp_insert_term($name, 'category', ['slug' => $slug]);
    if (is_wp_error($result)) {
        throw new RuntimeException('Polylang category creation failed: ' . $result->get_error_message());
    }
    return (int) $result['term_id'];
};
$terms = [
    'en' => $insertTerm('Conformance News 東京', 'conformance-polylang-news-en'),
    'fr' => $insertTerm('Actualités Conformité', 'conformance-polylang-news-fr'),
    'ar' => $insertTerm('أخبار المطابقة', 'conformance-polylang-news-ar'),
];
foreach ($terms as $language => $termId) {
    pll_set_term_language($termId, $language);
}
pll_save_term_translations($terms);

$long = str_repeat('محتوى طويل 東京 🚀 delimiter |%| literal {{not-a-token}} — ', 1400);
$postContent = [
    'en' => '<!-- wp:paragraph --><p>English portable body 東京 🚀.</p><!-- /wp:paragraph -->' . $long,
    'fr' => '<!-- wp:paragraph --><p>Contenu français portable 東京 🚀.</p><!-- /wp:paragraph -->' . $long,
    'ar' => '<!-- wp:paragraph --><p dir="rtl">محتوى عربي قابل للنقل 東京 🚀.</p><!-- /wp:paragraph -->' . $long,
];
$titles = [
    'en' => 'Portable English Story 東京 🚀',
    'fr' => 'Histoire française portable',
    'ar' => 'قصة عربية محمولة',
];
$slugs = [
    'en' => 'portable-polylang-story-en',
    'fr' => 'portable-polylang-story-fr',
    'ar' => 'portable-polylang-story-ar',
];
$publisher = get_user_by('login', 'admin');
if (!$publisher instanceof WP_User) {
    throw new RuntimeException('Polylang source publisher premise is missing');
}
$posts = [];
foreach (['en', 'fr', 'ar'] as $language) {
    $postId = wp_insert_post([
        'post_type' => 'post',
        'post_status' => 'publish',
        'post_author' => (int) $publisher->ID,
        'post_title' => $titles[$language],
        'post_name' => $slugs[$language],
        'post_content' => $postContent[$language],
        'post_excerpt' => "excerpt-$language 東京 🚀 |%|",
    ], true);
    if (is_wp_error($postId)) {
        throw new RuntimeException('Polylang post creation failed: ' . $postId->get_error_message());
    }
    $posts[$language] = (int) $postId;
    pll_set_post_language((int) $postId, $language);
    wp_set_object_terms((int) $postId, [$terms[$language]], 'category', false);
}
pll_save_post_translations($posts);

// ScopeDiscovery reads each persistent non-deletion post status (publish,
// draft, pending, private and future) for every opted-in type. Keep pages and
// synced patterns in that exact frontier: their Polylang term descriptions
// carry post ids, so capture/apply must rebind two more translated groups
// rather than merely copy public posts. Trash is deliberately absent: it is a
// deletion/tombstone boundary, not an authored post state.
$previousUserId = get_current_user_id();
wp_set_current_user((int) $publisher->ID);
if (!current_user_can('publish_posts')) {
    throw new RuntimeException('Polylang source publisher lacks publish_posts');
}
$pages = [];
$pageFixtures = [
    'en' => ['publish', 'Portable English Page 東京 🚀', 'portable-polylang-page-en', '<!-- wp:paragraph --><p>English portable page reference.</p><!-- /wp:paragraph -->'],
    'fr' => ['private', 'Page française portable', 'portable-polylang-page-fr', '<!-- wp:paragraph --><p>Page française portable.</p><!-- /wp:paragraph -->'],
    'ar' => ['draft', 'صفحة عربية محمولة', 'portable-polylang-page-ar', '<!-- wp:paragraph --><p dir="rtl">صفحة عربية قابلة للنقل.</p><!-- /wp:paragraph -->'],
];
foreach ($pageFixtures as $language => [$status, $title, $slug, $content]) {
    $pageId = wp_insert_post([
        'post_type' => 'page',
        'post_status' => $status,
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => $content,
        'post_author' => 0,
    ], true);
    if (is_wp_error($pageId)) {
        throw new RuntimeException('Polylang page creation failed: ' . $pageId->get_error_message());
    }
    $storedPage = get_post((int) $pageId);
    if (!$storedPage instanceof WP_Post
        || $storedPage->post_name !== $slug
        || $storedPage->post_status !== $status) {
        throw new RuntimeException("Polylang source page natural-key/status premise failed: $slug/$status");
    }
    $pages[$language] = (int) $pageId;
    pll_set_post_language((int) $pageId, $language);
}
pll_save_post_translations($pages);

$futureGmt = gmdate('Y-m-d H:i:s', time() + (2 * DAY_IN_SECONDS));
$blocks = [];
$blockFixtures = [
    'en' => ['pending', 'Portable English Pattern', 'portable-polylang-block-en', '<!-- wp:paragraph --><p>English portable synced pattern.</p><!-- /wp:paragraph -->'],
    'fr' => ['future', 'Composition française portable', 'portable-polylang-block-fr', '<!-- wp:paragraph --><p>Composition française portable.</p><!-- /wp:paragraph -->'],
    'ar' => ['private', 'نمط عربي محمول', 'portable-polylang-block-ar', '<!-- wp:paragraph --><p dir="rtl">نمط عربي قابل للنقل.</p><!-- /wp:paragraph -->'],
];
foreach ($blockFixtures as $language => [$status, $title, $slug, $content]) {
    $record = [
        'post_type' => 'wp_block',
        'post_status' => $status,
        'post_title' => $title,
        'post_name' => $slug,
        'post_content' => $content,
        'post_author' => 0,
    ];
    if ($status === 'future') {
        $record['post_date_gmt'] = $futureGmt;
        $record['post_date'] = get_date_from_gmt($futureGmt);
    }
    $blockId = wp_insert_post($record, true);
    if (is_wp_error($blockId)) {
        throw new RuntimeException('Polylang synced-pattern creation failed: ' . $blockId->get_error_message());
    }
    $storedBlock = get_post((int) $blockId);
    if (!$storedBlock instanceof WP_Post
        || $storedBlock->post_name !== $slug
        || $storedBlock->post_status !== $status) {
        throw new RuntimeException("Polylang source wp_block natural-key/status premise failed: $slug/$status");
    }
    $blocks[$language] = (int) $blockId;
    pll_set_post_language((int) $blockId, $language);
}
pll_save_post_translations($blocks);
wp_set_current_user($previousUserId);

require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
$attachments = [];
$colors = ['en' => [30, 120, 210], 'fr' => [210, 50, 80], 'ar' => [40, 170, 90]];
foreach (['en', 'fr', 'ar'] as $language) {
    $path = "/tmp/wprism-polylang-media-$language.png";
    $image = imagecreatetruecolor(72, 48);
    if (!$image) {
        throw new RuntimeException('Polylang media image allocation failed');
    }
    [$red, $green, $blue] = $colors[$language];
    imagefilledrectangle($image, 0, 0, 71, 47, imagecolorallocate($image, $red, $green, $blue));
    imagepng($image, $path);
    imagedestroy($image);
    $attachmentId = media_handle_sideload(
        ['name' => "wprism-polylang-media-$language.png", 'tmp_name' => $path],
        $posts[$language],
        "Polylang Media $language 東京 🚀"
    );
    if (is_wp_error($attachmentId)) {
        throw new RuntimeException('Polylang media import failed: ' . $attachmentId->get_error_message());
    }
    $attachments[$language] = (int) $attachmentId;
    $renamed = wp_update_post([
        'ID' => (int) $attachmentId,
        'post_name' => "wprism-polylang-media-$language",
    ], true);
    if (is_wp_error($renamed)) {
        throw new RuntimeException('Polylang media identity normalization failed: ' . $renamed->get_error_message());
    }
    update_post_meta((int) $attachmentId, '_wp_attachment_image_alt', "Polylang $language alt 東京 🚀");
    pll_set_post_language((int) $attachmentId, $language);
}
pll_save_post_translations($attachments);

$menus = [];
$menuNames = [
    'en' => 'Polylang Primary English',
    'fr' => 'Polylang Principal Français',
    'ar' => 'قائمة بوليلانج الرئيسية',
];
foreach (['en', 'fr', 'ar'] as $language) {
    $menuId = wp_create_nav_menu($menuNames[$language]);
    if (is_wp_error($menuId)) {
        throw new RuntimeException('Polylang menu creation failed: ' . $menuId->get_error_message());
    }
    $menus[$language] = (int) $menuId;
    wp_update_nav_menu_item((int) $menuId, 0, [
        'menu-item-title' => "marker-$language-東京-🚀",
        'menu-item-url' => home_url("/$language-marker/?q=a%2Fb"),
        'menu-item-status' => 'publish',
        'menu-item-type' => 'custom',
    ]);
    wp_update_nav_menu_item((int) $menuId, 0, [
        'menu-item-title' => $titles[$language],
        'menu-item-object-id' => $posts[$language],
        'menu-item-object' => 'post',
        'menu-item-status' => 'publish',
        'menu-item-type' => 'post_type',
    ]);
}
$switcherItem = wp_update_nav_menu_item($menus['en'], 0, [
    'menu-item-title' => 'Language switcher 東京 🚀',
    'menu-item-url' => '#pll_switcher',
    'menu-item-status' => 'publish',
    'menu-item-type' => 'custom',
]);
if (is_wp_error($switcherItem)) {
    throw new RuntimeException('Polylang menu switcher creation failed: ' . $switcherItem->get_error_message());
}
update_post_meta((int) $switcherItem, '_pll_menu_item', [
    'hide_if_no_translation' => 0,
    'hide_current' => 0,
    'force_home' => 0,
    'show_flags' => 0,
    'show_names' => 1,
    'dropdown' => 1,
]);

$stylesheet = get_option('stylesheet');
$options = get_option('polylang');
$options['default_lang'] = 'en';
$options['media_support'] = 1;
$options['nav_menus'] = [
    $stylesheet => [
        'primary' => $menus,
    ],
];
$options['sync'] = ['taxonomies', 'post_meta', 'post_date'];
update_option('polylang', $options);
set_theme_mod('nav_menu_locations', ['primary' => $menus['en']]);

update_option('widget_polylang', [
    2 => [
        'title' => 'All Languages 東京 🚀',
        'dropdown' => 0,
        'show_names' => 1,
        'show_flags' => 0,
        'force_home' => 0,
        'hide_current' => 0,
        'hide_if_no_translation' => 0,
    ],
    3 => [
        'title' => 'العربية فقط',
        'dropdown' => 1,
        'show_names' => 1,
        'show_flags' => 0,
        'force_home' => 1,
        'hide_current' => 0,
        'hide_if_no_translation' => 0,
        'pll_lang' => 'ar',
    ],
    '_multiwidget' => 1,
]);
$sidebars = wp_get_sidebars_widgets();
$sidebars['sidebar-1'] = ['polylang-2', 'polylang-3'];
wp_set_sidebars_widgets($sidebars);

$result = [
    'attachments' => $attachments,
    'blocks' => $blocks,
    'default_category' => (int) get_option('default_category'),
    'language_terms' => [],
    'menus' => $menus,
    'pages' => $pages,
    'posts' => $posts,
    'switcher_item' => (int) $switcherItem,
    'terms' => $terms,
];
foreach (['en', 'fr', 'ar'] as $language) {
    $term = get_term_by('slug', $language, 'language');
    if (!$term instanceof WP_Term) {
        throw new RuntimeException("Polylang language term missing for $language");
    }
    $result['language_terms'][$language] = (int) $term->term_id;
}
foreach (array_merge($posts, $pages, $blocks, $attachments) as $id) {
    if ($id < 4100001) {
        throw new RuntimeException('Polylang source post/page/pattern/media high-identity premise failed');
    }
}
foreach (array_merge($terms, $result['language_terms'], $menus) as $id) {
    if ($id < 4200001) {
        throw new RuntimeException('Polylang source term/menu high-identity premise failed');
    }
}
echo wp_json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF

SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-polylang-seed.php)
rm -f "$SEED_FILE"
require_observed_nonempty 'conf1 Polylang production seed output' "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
jq -e '
  (.posts | to_entries | all(.value >= 4100001)) and
  (.pages | to_entries | all(.value >= 4100001)) and
  (.blocks | to_entries | all(.value >= 4100001)) and
  (.attachments | to_entries | all(.value >= 4100001)) and
  (.terms | to_entries | all(.value >= 4200001)) and
  (.language_terms | to_entries | all(.value >= 4200001)) and
  (.menus | to_entries | all(.value >= 4200001)) and
  .switcher_item >= 4100001
' <<<"$SEED_JSON" >/dev/null || fail "Polylang source fixture premise was incomplete: $SEED_JSON"
printf '%s\n' "$SEED_JSON" > "$SOURCE_REPO/.tmp-polylang-source.json"

# Polylang snapshots its option into the plugin singleton before the authoring
# calls above. Media/menu callbacks in that same request can persist the stale
# singleton at shutdown, overwriting a direct option update. A fresh native
# request is the real settings boundary and makes all eleven portable values the
# input to every subsequent Polylang request (the same process rule its admin
# settings page relies on after redirect).
wp_conf1 eval '
  $fixture=json_decode(file_get_contents("/siterepo/.tmp-polylang-source.json"),true,512,JSON_THROW_ON_ERROR);
  $option=get_option("polylang");
  if (!is_array($option)) throw new RuntimeException("Polylang option missing after authoring request");
  $option["browser"]=false;
  $option["default_lang"]="en";
  $option["force_lang"]=1;
  $option["hide_default"]=false;
  $option["media_support"]=1;
  $option["post_types"]=[];
  $option["redirect_lang"]=false;
  $option["rewrite"]=true;
  $option["taxonomies"]=[];
  $option["sync"]=["taxonomies","post_meta","post_date"];
  $option["nav_menus"]=[get_option("stylesheet")=>["primary"=>$fixture["menus"]]];
  update_option("polylang",$option);
  set_theme_mod("nav_menu_locations",["primary"=>(int)$fixture["menus"]["en"]]);
' >/dev/null

OPTION_FILE="$SOURCE_REPO/.tmp-polylang-source-option.json"
wp_conf1 option get polylang --format=json | awk 'NF { line=$0 } END { print line }' > "$OPTION_FILE"
jq -e '
  .browser == false and .default_lang == "en" and .force_lang == 1 and
  .hide_default == false and .media_support == 1 and .redirect_lang == false and .rewrite == true and
  .sync == ["taxonomies","post_meta","post_date"] and
  (.nav_menus | type == "object")
' "$OPTION_FILE" >/dev/null || fail 'Polylang portable option premise did not persist through the plugin storage path'

. "$(dirname "${BASH_SOURCE[0]}")/../../fixtures/polylang-biography.sh"
polylang_biography_seed source

pass 'Polylang source owns divergent high post/page/pattern/term/media/menu graphs, every persistent non-deletion status, RTL and long UTF-8 data, portable settings, and both optional switcher stores'
