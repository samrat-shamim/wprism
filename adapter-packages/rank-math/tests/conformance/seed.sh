#!/usr/bin/env bash
# Exact Rank Math 1.0.277.2 source: disconnected setup, native modules and
# redirection, divergent references, frontend SEO, long UTF-8, and link state.
set -euo pipefail

SOURCE_REPO="${CONF_REPO1:-siterepo/conf1}"

# Registration is checked before Rank Math constructs its module manager. The
# wizard's skip choice therefore lands in one request and every module API is
# deliberately exercised from a subsequent fresh process.
wp_conf1 option update rank_math_registration_skip 1 >/dev/null
wp_conf1 option update rank_math_is_configured 1 >/dev/null
wp_conf1 eval '
if (!class_exists("RankMath\\Helper")) {
    throw new RuntimeException("Rank Math helper did not boot after the disconnected setup choice");
}
RankMath\Helper::update_modules([
    "link-counter" => "on",
    "redirections" => "on",
    "schema" => "on",
]);
$modules = array_values(array_unique(array_map("strval", (array) get_option("rank_math_modules", []))));
sort($modules, SORT_STRING);
update_option("rank_math_modules", $modules);
' >/dev/null

cat > "$SOURCE_REPO/.tmp-rank-math-seed.php" <<'PHPEOF'
<?php
global $wpdb;
$wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 3100001");
$wpdb->query("ALTER TABLE {$wpdb->terms} AUTO_INCREMENT = 3200001");
$wpdb->query("ALTER TABLE {$wpdb->term_taxonomy} AUTO_INCREMENT = 3300001");

$term = static function (string $taxonomy, string $name, string $slug): int {
    $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
    if (is_wp_error($created)) {
        throw new RuntimeException($created->get_error_message());
    }
    return (int) $created['term_id'];
};
$category = $term('category', 'Rank Math Primary 東京 🚀', 'rank-math-primary');
$secondary = $term('category', 'Rank Math Secondary', 'rank-math-secondary');
$tag = $term('post_tag', 'Rank Math Portable', 'rank-math-portable');

$hub = wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_title' => 'Rank Math Hub 東京 🚀',
    'post_name' => 'rank-math-hub',
    'post_content' => '<!-- wp:paragraph --><p id="rank-math-hub-marker">Portable Rank Math destination 東京 🚀.</p><!-- /wp:paragraph -->',
], true);
if (is_wp_error($hub) || (int) $hub <= 0) {
    throw new RuntimeException('Rank Math hub creation failed');
}

$upload = wp_upload_dir();
$imagePath = trailingslashit($upload['path']) . 'wprism-rank-math-social.png';
$image = imagecreatetruecolor(96, 64);
imagefilledrectangle($image, 0, 0, 95, 63, imagecolorallocate($image, 42, 96, 170));
imagepng($image, $imagePath);
imagedestroy($image);
$attachment = wp_insert_attachment([
    'post_mime_type' => 'image/png',
    'post_status' => 'inherit',
    'post_title' => 'Rank Math Social 東京 🚀',
    'post_name' => 'wprism-rank-math-social',
], $imagePath, 0, true);
if (is_wp_error($attachment) || (int) $attachment <= 0) {
    throw new RuntimeException('Rank Math social image creation failed');
}
require_once ABSPATH . 'wp-admin/includes/image.php';
wp_update_attachment_metadata((int) $attachment, wp_generate_attachment_metadata((int) $attachment, $imagePath));

$long = str_repeat('Long portable SEO 東京 🚀 — مرحبا — こんにちは — ', 2500);
$post = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_title' => 'Rank Math Article 東京 🚀',
    'post_name' => 'rank-math-article',
    'post_excerpt' => 'Portable Rank Math excerpt 東京 🚀.',
    'post_content' => '<!-- wp:paragraph --><p id="rank-math-article-marker">' . esc_html($long)
        . ' <a href="' . esc_url(home_url('/rank-math-hub/')) . '">internal hub</a>'
        . ' <a href="https://external.example.test/rank-math?x=1&amp;y=2">external proof</a></p><!-- /wp:paragraph -->',
], true);
if (is_wp_error($post) || (int) $post <= 0) {
    throw new RuntimeException('Rank Math article creation failed');
}
wp_set_object_terms((int) $post, [$category, $secondary], 'category');
wp_set_object_terms((int) $post, [$tag], 'post_tag');

$postMeta = [
    'rank_math_advanced_robots' => ['max-snippet' => '-1', 'max-video-preview' => '-1'],
    'rank_math_breadcrumb_title' => 'Portable breadcrumb 東京 🚀',
    'rank_math_canonical_url' => home_url('/rank-math-canonical/東京/?a=1&b=two'),
    'rank_math_description' => 'Portable Rank Math description 東京 🚀 with | = : delimiters.',
    'rank_math_facebook_description' => 'Portable Facebook description 東京 🚀.',
    'rank_math_facebook_image' => wp_get_attachment_url((int) $attachment),
    'rank_math_facebook_image_id' => (string) $attachment,
    'rank_math_facebook_title' => 'Portable Facebook title 東京 🚀',
    'rank_math_focus_keyword' => 'portable rank math,東京 seo',
    'rank_math_pillar_content' => 'on',
    'rank_math_primary_category' => (string) $category,
    'rank_math_rich_snippet' => 'article',
    'rank_math_robots' => ['index', 'follow'],
    'rank_math_title' => 'Portable Rank Math Title 東京 🚀 %sep% %sitename%',
    'rank_math_twitter_description' => 'Portable Twitter description 東京 🚀.',
    'rank_math_twitter_image' => wp_get_attachment_url((int) $attachment),
    'rank_math_twitter_image_id' => (string) $attachment,
    'rank_math_twitter_title' => 'Portable Twitter title 東京 🚀',
    'rank_math_twitter_use_facebook' => 'off',
];
foreach ($postMeta as $key => $value) {
    update_post_meta((int) $post, $key, $value);
}

$termMeta = [
    'rank_math_description' => 'Portable category SEO description 東京 🚀.',
    'rank_math_facebook_image' => wp_get_attachment_url((int) $attachment),
    'rank_math_facebook_image_id' => (string) $attachment,
    'rank_math_title' => 'Portable Category Title 東京 🚀 %sep% %sitename%',
    'rank_math_twitter_image_id' => (string) $attachment,
];
foreach ($termMeta as $key => $value) {
    update_term_meta($category, $key, $value);
}

$general = (array) get_option('rank-math-options-general', []);
$general['breadcrumbs'] = 'on';
$general['breadcrumbs_home_label'] = 'Origin 東京 🚀';
$general['nofollow_external_links'] = 'off';
$general['new_window_external_links'] = 'on';
$general['strip_category_base'] = 'off';
$general['wprism_plain_data'] = [
    'empty' => [],
    'large' => str_repeat('settings 東京 🚀 — ', 8000),
    'nested' => ['enabled' => true, 'threshold' => 0, 'nullable' => null],
];
update_option('rank-math-options-general', $general);

$titles = (array) get_option('rank-math-options-titles', []);
$titles['homepage_facebook_image'] = wp_get_attachment_url((int) $attachment);
$titles['homepage_facebook_image_id'] = (int) $attachment;
$titles['knowledgegraph_logo'] = wp_get_attachment_url((int) $attachment);
$titles['knowledgegraph_logo_id'] = (int) $attachment;
$titles['local_seo_about_page'] = (int) $hub;
$titles['local_seo_contact_page'] = (int) $post;
$titles['open_graph_image_id'] = (int) $attachment;
$titles['knowledgegraph_name'] = 'WPrism Rank Math 東京 🚀';
$titles['pt_post_primary_taxonomy'] = 'category';
$titles['pt_post_title'] = '%title% %sep% %sitename%';
$titles['tax_category_title'] = '%term% %sep% %sitename%';
update_option('rank-math-options-titles', $titles);

// Both objects stay target-owned: one embeds a credential, the other local ids.
update_option('rank-math-options-instant-indexing', [
    'indexnow_api_key' => 'source-indexnow-credential-must-not-transfer',
    'submit_posts' => ['post'],
]);
update_option('rank-math-options-sitemap', [
    'exclude_posts' => (string) $hub,
    'exclude_terms' => (string) $category,
]);
update_option('rank_math_indexnow_log', [[
    'url' => home_url('/source-indexnow-history-must-not-transfer/'),
    'status' => 429,
    'manual_submission' => false,
    'message' => 'source runtime submission history',
    'time' => 1700000001,
]]);

$redirection = RankMath\Redirections\Redirection::from([
    'sources' => [[
        'pattern' => 'rank-math-old',
        'comparison' => 'exact',
        'ignore' => '',
    ]],
    'url_to' => home_url('/rank-math-hub/?from=redirect'),
    'header_code' => '302',
    'status' => 'active',
]);
$redirectionId = $redirection->save();
if (!is_int($redirectionId) || $redirectionId <= 0) {
    throw new RuntimeException('Rank Math native redirection writer failed');
}

RankMath\Links\Links::process_post_links((int) $hub, get_post((int) $hub));
RankMath\Links\Links::process_post_links((int) $post, get_post((int) $post));
$sourceRows = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}rank_math_internal_links WHERE post_id=%d",
    (int) $post
));
if ($sourceRows < 2 || !get_post_meta((int) $post, 'rank_math_internal_links_processed', true)) {
    throw new RuntimeException('Rank Math native link processor did not establish the source premise');
}

remove_theme_mod('custom_css_post_id');
echo wp_json_encode([
    'attachment' => (int) $attachment,
    'category' => $category,
    'hub' => (int) $hub,
    'post' => (int) $post,
    'redirection' => $redirectionId,
    'secondary' => $secondary,
    'tag' => $tag,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF

SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-rank-math-seed.php)
rm -f "$SOURCE_REPO/.tmp-rank-math-seed.php"
require_observed_nonempty 'Rank Math source seed' "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
jq -e '
  .attachment >= 3100001 and .category >= 3200001 and .hub >= 3100001 and
  .post > .hub and .redirection > 0 and .secondary > .category and .tag > .secondary
' <<<"$SEED_JSON" >/dev/null || fail "Rank Math source identity or native-writer premise failed: $SEED_JSON"
printf '%s\n' "$SEED_JSON" > "$SOURCE_REPO/.tmp-rank-math-source.json"

SOURCE_FRONT=$(curl -fsSL "http://localhost:${CONF1_PORT:-8806}/rank-math-article/") \
  || fail 'Rank Math source frontend did not render'
require_observed_nonempty 'Rank Math source frontend' "$SOURCE_FRONT"
grep -Fq 'Portable Rank Math Title' <<<"$SOURCE_FRONT" \
  && grep -Fq 'Portable Rank Math description' <<<"$SOURCE_FRONT" \
  || fail 'Rank Math source frontend did not consume its authored SEO metadata'

# WP-CLI 2.12.0 matches --skip-plugins against the directory slug, not the
# plugin basename. Clear only the fixture queue with that exact slug, seed one
# persistent entry through Rank Math's native API, then observe it after the
# seeding request has shut down in an independent plugin-skipped process.
SOURCE_NOTIFICATION_CLEAR=$(wp_conf1 --skip-plugins=seo-by-rank-math eval '
delete_option("rank_math_notifications");
echo "rank-math-source-notification-cleared";
')
require_observed_nonempty 'Rank Math source notification reset' "$SOURCE_NOTIFICATION_CLEAR"
[ "$(printf '%s\n' "$SOURCE_NOTIFICATION_CLEAR" | awk 'NF { line=$0 } END { print line }')" = \
    'rank-math-source-notification-cleared' ] \
  || fail "Rank Math source notification reset did not complete: $SOURCE_NOTIFICATION_CLEAR"
SOURCE_NOTIFICATION_SEED=$(wp_conf1 eval '
\RankMath\Helper::add_notification(
    "Source runtime notification must not transfer",
    ["id" => "wprism-source-runtime", "type" => "success", "screen" => "any", "capability" => ""]
);
echo "rank-math-source-notification-seeded";
')
require_observed_nonempty 'Rank Math source notification seed' "$SOURCE_NOTIFICATION_SEED"
[ "$(printf '%s\n' "$SOURCE_NOTIFICATION_SEED" | awk 'NF { line=$0 } END { print line }')" = \
    'rank-math-source-notification-seeded' ] \
  || fail "Rank Math native source notification seed did not complete: $SOURCE_NOTIFICATION_SEED"
SOURCE_NOTIFICATION=$(wp_conf1 --skip-plugins=seo-by-rank-math eval '
echo wp_json_encode(get_option("rank_math_notifications", null));
')
require_observed_nonempty 'Rank Math source notification premise' "$SOURCE_NOTIFICATION"
jq -e '
  . == [{
    message:"Source runtime notification must not transfer",
    options:{id:"wprism-source-runtime",classes:"rank-math-notice",type:"success",screen:"any",capability:""}
  }]
' <<<"$SOURCE_NOTIFICATION" >/dev/null \
  || fail "Rank Math source notification premise was not durable before capture: $SOURCE_NOTIFICATION"

pass 'Rank Math source covers disconnected setup, native modules/redirection/link APIs, reference families, large UTF-8, env/runtime boundaries, and frontend SEO'
