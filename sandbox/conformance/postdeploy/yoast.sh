#!/usr/bin/env bash
# Hostile exact Yoast target after deploy and before apply: divergent same-slug
# identities, stale authored fields, target-owned runtime siblings, and damaged
# derived rows. Apply must adopt the native identities, preserve target-local
# state, then drive the plugin's own four-table reindex to convergence.
set -euo pipefail

TARGET_REPO="${CONF_REPO2:-siterepo/conf2}"
HOSTILE_FILE="$TARGET_REPO/.tmp-yoast-hostile.php"
cat > "$HOSTILE_FILE" <<'PHPEOF'
<?php
global $wpdb;
$wpdb->query("ALTER TABLE {$wpdb->posts} AUTO_INCREMENT = 9100001");
$wpdb->query("ALTER TABLE {$wpdb->terms} AUTO_INCREMENT = 9200001");
$wpdb->query("ALTER TABLE {$wpdb->term_taxonomy} AUTO_INCREMENT = 9300001");

$term = static function (string $taxonomy, string $name, string $slug): int {
    $created = wp_insert_term($name, $taxonomy, ['slug' => $slug]);
    if (is_wp_error($created)) {
        throw new RuntimeException($created->get_error_message());
    }
    return (int) $created['term_id'];
};
$primary = $term('category', 'Target Primary Stale', 'conformance-primary');
$secondary = $term('category', 'Target Secondary Stale', 'conformance-secondary');
$tag = $term('post_tag', 'Target Search Tag Stale', 'conformance-search-tag');

$post = static function (string $type, string $title, string $slug, int $parent = 0): int {
    $id = wp_insert_post([
        'post_type' => $type,
        'post_status' => $type === 'attachment' ? 'inherit' : 'publish',
        'post_title' => $title,
        'post_name' => $slug,
        'post_parent' => $parent,
        'post_content' => '<p>Hostile target content that must converge.</p>',
        'post_mime_type' => $type === 'attachment' ? 'image/png' : '',
    ], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    return (int) $id;
};

$hub = $post('page', 'Target Yoast Hub Stale', 'conformance-yoast-hub');
$ids = [
    'hub' => $hub,
    'child' => $post('page', 'Target Yoast Child Stale', 'conformance-yoast-child', $hub),
    'about' => $post('page', 'Target About Stale', 'conformance-about'),
    'contact' => $post('page', 'Target Contact Stale', 'conformance-contact'),
    'terms' => $post('page', 'Target Terms Stale', 'conformance-terms'),
    'privacy' => $post('page', 'Target Privacy Stale', 'conformance-privacy'),
    'shop' => $post('page', 'Target Shop Stale', 'conformance-shop'),
    'included_a' => $post('page', 'Target Included A Stale', 'conformance-included-a'),
    'included_b' => $post('page', 'Target Included B Stale', 'conformance-included-b'),
    'post' => $post('post', 'Target Yoast Post Stale', 'conformance-yoast-post'),
];
wp_set_object_terms($ids['post'], [$primary, $secondary], 'category');
wp_set_object_terms($ids['post'], [$tag], 'post_tag');
update_post_meta($ids['post'], '_yoast_wpseo_title', 'Hostile target title');
update_post_meta($ids['post'], '_yoast_wpseo_metadesc', 'Hostile target description');
update_post_meta($ids['post'], '_yoast_wpseo_primary_category', (string) $secondary);
update_post_meta($ids['post'], '_yoast_wpseo_content_score', 'target-derived-17');
update_post_meta($ids['post'], '_yoast_wpseo_estimated-reading-time-minutes', '99');
update_post_meta($ids['post'], '_yoast_wpseo_linkdex', 'target-derived-19');

WPSEO_Taxonomy_Meta::set_values($primary, 'category', [
    'wpseo_desc' => 'Hostile target taxonomy description',
    'wpseo_opengraph-image-id' => (string) $ids['child'],
    'wpseo_twitter-image-id' => (string) $ids['child'],
]);
WPSEO_Options::set('company_logo_id', $ids['child']);
WPSEO_Options::set('person_logo_id', $ids['child']);
WPSEO_Options::set('og_default_image_id', $ids['child']);
WPSEO_Options::set('disableadvanced_meta', false);
WPSEO_Options::set('llms_txt_selection_mode', 'auto');
WPSEO_Options::set('about_us_page', $ids['child']);
WPSEO_Options::set('contact_page', $ids['child']);
WPSEO_Options::set('terms_page', $ids['child']);
WPSEO_Options::set('privacy_policy_page', $ids['child']);
WPSEO_Options::set('shop_page', $ids['child']);
WPSEO_Options::set('other_included_pages', [$ids['child']]);

$main = (array) get_option('wpseo', []);
$main['duo_target_neighbor'] = 'target-main-option-preserved';
update_option('wpseo', $main);
update_option('wpseo_tracking_only', [
    'task_list_first_opened_on' => 1999999001,
    'task_first_actioned_on' => 1999999002,
    'frontend_inspector_first_actioned_on' => 1999999003,
]);
update_option('yoast_migrations_free', [
    'version' => 777,
    'lock' => 1999999004,
    'error' => ['time' => 1999999005, 'message' => 'target-runtime-marker'],
]);
update_option('yoast_target_undeclared_neighbor', 'target-neighbor-preserved');

$ids['cat_a'] = $primary;
$ids['cat_b'] = $secondary;
$ids['tag'] = $tag;
echo wp_json_encode($ids, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF

$COMPOSE exec -T --user root wp2 sh -c \
  'printf "%s\n" "<?php" "add_filter(\"Yoast\\\\WP\\\\SEO\\\\should_index_indexables\", \"__return_true\", 999);" > /var/www/html/wp-content/mu-plugins/duo-yoast-index-fixture.php'

HOSTILE_OUT=$(wp_conf2 eval-file /siterepo/.tmp-yoast-hostile.php)
rm -f "$HOSTILE_FILE"
require_observed_nonempty 'conf2 Yoast hostile target output' "$HOSTILE_OUT"
TARGET_JSON=$(printf '%s\n' "$HOSTILE_OUT" | awk 'NF { line=$0 } END { print line }')
jq -e '.post >= 9100001 and .cat_a >= 9200001 and .child > .hub' <<<"$TARGET_JSON" >/dev/null \
  || fail "Yoast hostile target premise was incomplete: $TARGET_JSON"
printf '%s\n' "$TARGET_JSON" > "$TARGET_REPO/.tmp-yoast-target.json"

SOURCE_JSON=$(cat "${CONF_REPO1:-siterepo/conf1}/.tmp-yoast-source.json")
for key in post hub child about contact terms privacy shop included_a included_b cat_a cat_b tag; do
  SOURCE_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$SOURCE_JSON")
  TARGET_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$TARGET_JSON")
  require_fixture_ids SOURCE_ID TARGET_ID
  [ "$SOURCE_ID" != "$TARGET_ID" ] || fail "Yoast divergent identity premise failed for $key ($SOURCE_ID)"
done

# Produce real derived rows, then damage three distinct projections. The
# post-apply provider must repair these through Yoast's own command; authored
# materialization alone cannot make these readbacks pass.
wp_conf2 yoast index --reindex --skip-confirmation >/dev/null
wp_conf2 eval '
global $wpdb;
$post = get_page_by_path("conformance-yoast-post", OBJECT, "post");
if (!$post) { throw new RuntimeException("hostile target post missing before derived damage"); }
$wpdb->delete($wpdb->prefix . "yoast_primary_term", ["post_id" => $post->ID]);
$wpdb->query($wpdb->prepare(
    "UPDATE {$wpdb->prefix}yoast_indexable SET link_count = NULL WHERE object_id = %d AND object_type = %s",
    $post->ID,
    "post"
));
$wpdb->delete($wpdb->prefix . "yoast_indexable", ["object_id" => $post->ID, "object_type" => "post"]);
' >/dev/null

pass 'Yoast target begins with divergent native identities, stale authored state, damaged projections, and target-owned runtime siblings'
