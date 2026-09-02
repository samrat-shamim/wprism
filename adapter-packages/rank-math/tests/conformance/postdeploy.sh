#!/usr/bin/env bash
# Hostile Rank Math target after real deploy/lifecycle settlement and before
# apply: divergent slugs/ids, same-natural-key redirection, stale projections,
# target credentials, runtime counters, and independently created schema.
set -euo pipefail

TARGET_REPO="${CONF_REPO2:-siterepo/conf2}"

cat > "$TARGET_REPO/.tmp-rank-math-target.php" <<'PHPEOF'
<?php
global $wpdb;
if (!is_plugin_active('seo-by-rank-math/rank-math.php')) {
    throw new RuntimeException('Rank Math is not active after deploy');
}
$required = [
    'rank_math_internal_links' => ['id', 'url', 'post_id', 'target_post_id', 'type'],
    'rank_math_internal_meta' => ['object_id', 'internal_link_count', 'external_link_count', 'incoming_link_count'],
    'rank_math_redirections' => ['id', 'sources', 'url_to', 'header_code', 'hits', 'status', 'created', 'updated', 'last_accessed'],
    'rank_math_redirections_cache' => ['id', 'from_url', 'redirection_id', 'object_id', 'object_type', 'is_redirected'],
];
$schema = [];
foreach ($required as $suffix => $columns) {
    $table = $wpdb->prefix . $suffix;
    if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) !== $table) {
        throw new RuntimeException("Rank Math lifecycle settlement omitted $suffix");
    }
    $found = array_map('strval', (array) $wpdb->get_col("SHOW COLUMNS FROM `$table`"));
    $missing = array_values(array_diff($columns, $found));
    if ($missing) {
        throw new RuntimeException("Rank Math lifecycle settlement left $suffix incomplete: " . implode(',', $missing));
    }
    $schema[$suffix] = hash('sha256', implode("\0", $found));
}

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
$category = $term('category', 'Target Rank Math Primary Stale', 'rank-math-primary');
$secondary = $term('category', 'Target Rank Math Secondary Stale', 'rank-math-secondary');
$tag = $term('post_tag', 'Target Rank Math Portable Stale', 'rank-math-portable');

$hub = wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_title' => 'Target Rank Math Hub Stale',
    'post_name' => 'rank-math-hub',
    'post_content' => '<p>target hub stale</p>',
], true);
$post = wp_insert_post([
    'post_type' => 'post',
    'post_status' => 'publish',
    'post_title' => 'Target Rank Math Article Stale',
    'post_name' => 'rank-math-article',
    'post_content' => '<p>target article stale</p>',
], true);
if (is_wp_error($hub) || is_wp_error($post) || (int) $hub <= 0 || (int) $post <= 0) {
    throw new RuntimeException('Rank Math hostile target post creation failed');
}
wp_set_object_terms((int) $post, [$category, $secondary], 'category');
wp_set_object_terms((int) $post, [$tag], 'post_tag');
update_post_meta((int) $post, 'rank_math_title', 'Target SEO title must converge');
update_post_meta((int) $post, 'rank_math_description', 'Target SEO description must converge');
update_post_meta((int) $post, 'rank_math_primary_category', (string) $secondary);
update_post_meta((int) $post, 'rank_math_internal_links_processed', 'target-stale-marker');

update_option('rank-math-options-instant-indexing', [
    'indexnow_api_key' => 'target-indexnow-credential-must-survive',
    'submit_posts' => ['page'],
]);
update_option('rank-math-options-sitemap', [
    'exclude_posts' => (string) $post,
    'exclude_terms' => (string) $secondary,
]);
update_option('rank_math_notifications', ['target-runtime-notification-must-survive']);
update_option('wprism_rank_math_target_neighbor', 'target-neighbor-must-survive', false);

$redirections = $wpdb->prefix . 'rank_math_redirections';
$cache = $wpdb->prefix . 'rank_math_redirections_cache';
$sources = maybe_serialize([[
    'ignore' => '',
    'pattern' => 'rank-math-old',
    'comparison' => 'exact',
]]);
$inserted = $wpdb->insert($redirections, [
    'sources' => $sources,
    'url_to' => home_url('/target-stale-destination/'),
    'header_code' => 301,
    'hits' => 37,
    'status' => 'inactive',
    'created' => '2020-01-01 00:00:00',
    'updated' => '2020-01-02 00:00:00',
    'last_accessed' => '2020-01-03 00:00:00',
]);
if ($inserted !== 1) {
    throw new RuntimeException('Rank Math hostile natural-key redirection insert failed');
}
$redirection = (int) $wpdb->insert_id;
$wpdb->insert($cache, [
    'from_url' => 'rank-math-old',
    'redirection_id' => $redirection,
    'object_id' => 0,
    'object_type' => 'post',
    'is_redirected' => 0,
]);

$links = $wpdb->prefix . 'rank_math_internal_links';
$meta = $wpdb->prefix . 'rank_math_internal_meta';
$wpdb->query("DELETE FROM `$links`");
$wpdb->query("DELETE FROM `$meta`");
$wpdb->insert($links, [
    'url' => '/target-stale-link',
    'post_id' => (int) $post,
    'target_post_id' => 999999999,
    'type' => 'internal',
]);
$wpdb->insert($meta, [
    'object_id' => (int) $post,
    'internal_link_count' => 31337,
    'external_link_count' => 31337,
    'incoming_link_count' => 31337,
]);

echo wp_json_encode([
    'category' => $category,
    'hub' => (int) $hub,
    'modules' => array_values((array) get_option('rank_math_modules', [])),
    'post' => (int) $post,
    'redirection' => $redirection,
    'schema' => $schema,
    'secondary' => $secondary,
    'tag' => $tag,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF

TARGET_OUT=$(wp_conf2 eval-file /siterepo/.tmp-rank-math-target.php)
rm -f "$TARGET_REPO/.tmp-rank-math-target.php"
require_observed_nonempty 'Rank Math hostile target' "$TARGET_OUT"
TARGET_JSON=$(printf '%s\n' "$TARGET_OUT" | awk 'NF { line=$0 } END { print line }')
jq -e '
  .category >= 9200001 and .hub >= 9100001 and .post > .hub and .redirection > 0 and
  (.schema | length) == 4 and (.schema[] | test("^[0-9a-f]{64}$"))
' <<<"$TARGET_JSON" >/dev/null || fail "Rank Math target identity/schema premise failed: $TARGET_JSON"
printf '%s\n' "$TARGET_JSON" > "$TARGET_REPO/.tmp-rank-math-target.json"

SOURCE_JSON=$(cat "${CONF_REPO1:-siterepo/conf1}/.tmp-rank-math-source.json")
for key in category hub post secondary tag; do
  SOURCE_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$SOURCE_JSON")
  TARGET_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$TARGET_JSON")
  require_fixture_ids SOURCE_ID TARGET_ID
  [ "$SOURCE_ID" != "$TARGET_ID" ] || fail "Rank Math divergent identity premise failed for $key ($SOURCE_ID)"
done

pass 'Rank Math deploy prepared all schemas; target begins with divergent identities, same-key authored drift, stale derived state, and target-owned credentials/runtime data'
