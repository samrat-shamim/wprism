#!/usr/bin/env bash
# Hostile Rank Math target after real deploy/schema settlement and before
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
        throw new RuntimeException("Rank Math schema settlement omitted $suffix");
    }
    $found = array_map('strval', (array) $wpdb->get_col("SHOW COLUMNS FROM `$table`"));
    $missing = array_values(array_diff($columns, $found));
    if ($missing) {
        throw new RuntimeException("Rank Math schema settlement left $suffix incomplete: " . implode(',', $missing));
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
update_option('rank_math_indexnow_log', [[
    'url' => home_url('/target-indexnow-history-must-survive/'),
    'status' => 202,
    'manual_submission' => true,
    'message' => 'target runtime submission history',
    'time' => 1800000002,
]]);
update_option('wprism_rank_math_target_neighbor', 'target-neighbor-must-survive', false);

$redirections = $wpdb->prefix . 'rank_math_redirections';
$cache = $wpdb->prefix . 'rank_math_redirections_cache';
if (false === $wpdb->query("ALTER TABLE `$redirections` AUTO_INCREMENT = 9400001")) {
    throw new RuntimeException('Rank Math hostile redirection identity range could not be established');
}
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
  .category >= 9200001 and .hub >= 9100001 and .post > .hub and .redirection >= 9400001 and
  (.schema | length) == 4 and (.schema[] | test("^[0-9a-f]{64}$"))
' <<<"$TARGET_JSON" >/dev/null || fail "Rank Math target identity/schema premise failed: $TARGET_JSON"
printf '%s\n' "$TARGET_JSON" > "$TARGET_REPO/.tmp-rank-math-target.json"

# WP-CLI 2.12.0's skip list contains directory slugs. Reset with the exact Rank
# Math slug, seed through its native API, and prove the stored grammar from a
# separate process after the native shutdown writer has completed.
TARGET_NOTIFICATION_CLEAR=$(wp_conf2 --skip-plugins=seo-by-rank-math eval '
delete_option("rank_math_notifications");
echo "rank-math-target-notification-cleared";
')
require_observed_nonempty 'Rank Math target notification reset' "$TARGET_NOTIFICATION_CLEAR"
[ "$(printf '%s\n' "$TARGET_NOTIFICATION_CLEAR" | awk 'NF { line=$0 } END { print line }')" = \
    'rank-math-target-notification-cleared' ] \
  || fail "Rank Math target notification reset did not complete: $TARGET_NOTIFICATION_CLEAR"
TARGET_NOTIFICATION_SEED=$(wp_conf2 eval '
\RankMath\Helper::add_notification(
    "Target runtime notification must survive",
    ["id" => "wprism-target-runtime", "type" => "success", "screen" => "any", "capability" => ""]
);
echo "rank-math-target-notification-seeded";
')
require_observed_nonempty 'Rank Math target notification seed' "$TARGET_NOTIFICATION_SEED"
[ "$(printf '%s\n' "$TARGET_NOTIFICATION_SEED" | awk 'NF { line=$0 } END { print line }')" = \
    'rank-math-target-notification-seeded' ] \
  || fail "Rank Math native target notification seed did not complete: $TARGET_NOTIFICATION_SEED"
TARGET_NOTIFICATION=$(wp_conf2 --skip-plugins=seo-by-rank-math eval '
echo wp_json_encode(get_option("rank_math_notifications", null));
')
require_observed_nonempty 'Rank Math target notification premise' "$TARGET_NOTIFICATION"
jq -e '
  . == [{
    message:"Target runtime notification must survive",
    options:{id:"wprism-target-runtime",classes:"rank-math-notice",type:"success",screen:"any",capability:""}
  }]
' <<<"$TARGET_NOTIFICATION" >/dev/null \
  || fail "Rank Math target notification premise was not durable before apply: $TARGET_NOTIFICATION"

# Instrument one ordinary boot without exposing queue values. Exact priorities
# prove that the storage reader ran at plugins_loaded:5 and the shutdown writer
# remains registered; the following skipped process proves the persistent row
# survived that full native lifecycle before WPrism apply starts.
CONTROL_BOOT=$(wp_conf2 eval '
$center = rank_math()->notification;
$stored = get_option("rank_math_notifications", []);
echo wp_json_encode([
    "did_plugins_loaded" => did_action("plugins_loaded"),
    "plugin_loaded" => defined("RANK_MATH_FILE"),
    "reader_priority" => has_action("plugins_loaded", [$center, "get_from_storage"]),
    "writer_priority" => has_action("shutdown", [$center, "update_storage"]),
    "center_count" => count($center->get_notifications()),
    "stored_count" => is_array($stored) ? count($stored) : -1,
    "stored_sha256" => hash("sha256", maybe_serialize($stored)),
]);
')
require_observed_nonempty 'Rank Math notification control boot' "$CONTROL_BOOT"
CONTROL_JSON=$(printf '%s\n' "$CONTROL_BOOT" | awk 'NF { line=$0 } END { print line }')
jq -e '
  .did_plugins_loaded >= 1 and .plugin_loaded == true and
  .reader_priority == 5 and .writer_priority == 10 and
  .center_count == 1 and .stored_count == 1 and
  (.stored_sha256 | test("^[a-f0-9]{64}$"))
' <<<"$CONTROL_JSON" >/dev/null \
  || fail "Rank Math ordinary notification control did not load the persistent queue: $CONTROL_JSON"
CONTROL_NOTIFICATION=$(wp_conf2 --skip-plugins=seo-by-rank-math eval '
echo wp_json_encode(get_option("rank_math_notifications", null));
')
require_observed_nonempty 'Rank Math notification control readback' "$CONTROL_NOTIFICATION"
jq -e '
  . == [{
    message:"Target runtime notification must survive",
    options:{id:"wprism-target-runtime",classes:"rank-math-notice",type:"success",screen:"any",capability:""}
  }]
' <<<"$CONTROL_NOTIFICATION" >/dev/null \
  || fail "Rank Math ordinary control boot changed its valid persistent notification: $CONTROL_NOTIFICATION"

SOURCE_JSON=$(cat "${CONF_REPO1:-siterepo/conf1}/.tmp-rank-math-source.json")
for key in category hub post secondary tag; do
  SOURCE_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$SOURCE_JSON")
  TARGET_ID=$(jq -r --arg key "$key" '.[$key]' <<<"$TARGET_JSON")
  require_fixture_ids SOURCE_ID TARGET_ID
  [ "$SOURCE_ID" != "$TARGET_ID" ] || fail "Rank Math divergent identity premise failed for $key ($SOURCE_ID)"
done

pass 'Rank Math deploy settled all declared schemas; target begins with divergent identities, same-key authored drift, stale derived state, and target-owned credentials/runtime data'
