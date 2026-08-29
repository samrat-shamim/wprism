#!/usr/bin/env bash
# Author a marketplace campaign only through Redirection's public group/item
# and options APIs, then drive traffic so runtime counters/logs are non-empty
# before capture and can be proved absent from canonical state.
set -euo pipefail

# Redirection deliberately separates plugin activation from its onboarding
# database install. A fresh WP-CLI activation leaves all four tables absent;
# use the plugin's public command and prove its postcondition before any native
# writer is exercised (the first live clean-room run failed here, before WPrism).
INSTALL_OUT=$(wp_conf1 redirection database install 2>&1)
require_observed_nonempty "Redirection source database install" "$INSTALL_OUT"
SOURCE_DATABASE=$(wp_conf1 eval '
  global $wpdb;
  $tables=[];
  foreach (["redirection_items","redirection_groups","redirection_logs","redirection_404"] as $suffix) {
    $name=$wpdb->prefix.$suffix;
    $tables[$suffix]=$wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s",$name))===$name;
  }
  echo wp_json_encode([
    "database"=>(string)(Red_Options::get()["database"] ?? ""),
    "groups"=>(int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}redirection_groups"),
    "tables"=>$tables,
  ]);
')
require_observed_nonempty "Redirection source database readiness" "$SOURCE_DATABASE"
jq -e '.database != "" and .groups >= 2 and (.tables | all(. == true))' <<<"$SOURCE_DATABASE" >/dev/null \
  || fail "Redirection native source database install did not converge: $SOURCE_DATABASE"

read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

$page = wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_title' => 'Summer Marketplace 東京 🚀',
    'post_name' => 'summer-marketplace',
    'post_content' => '<h1 class="wprism-summer-marketplace">Summer offers from local providers</h1>',
], true);
if (is_wp_error($page) || !$page) {
    throw new RuntimeException('could not create the marketplace destination page');
}

$group = Red_Group::create('Summer campaign 東京 🚀', WordPress_Module::MODULE_ID, true);
if (!$group instanceof Red_Group) {
    throw new RuntimeException('Redirection rejected the campaign group');
}

Red_Options::save([
    'support' => false,
    'monitor_types' => ['post'],
    'monitor_post' => $group->get_id(),
    'associated_redirect' => '/moved-$dec$',
    'auto_target' => '/automatic-$dec$',
    'expire_redirect' => 14,
    'expire_404' => 5,
    'log_external' => true,
    'log_header' => false,
    'track_hits' => true,
    'redirect_cache' => 1,
    'ip_logging' => 0,
    'https' => false,
    'headers' => [],
    'plugin_update' => 'prompt',
    'permalinks' => ['/marketplace/%postname%/'],
    'flag_query' => 'ignore',
    'flag_case' => false,
    'flag_trailing' => false,
    'flag_regex' => false,
    'cache_key' => true,
]);

$definitions = [
    [
        'url' => '/summer',
        'regex' => false,
        'match_type' => 'url',
        'action_type' => 'url',
        'action_code' => 302,
        'action_data' => ['url' => home_url('/summer-marketplace/')],
        'title' => 'Summer marketplace 東京 🚀',
        'group_id' => $group->get_id(),
    ],
    [
        'url' => '^/marketplace/vendor/(.*)$',
        'regex' => true,
        'match_type' => 'url',
        'action_type' => 'url',
        'action_code' => 307,
        'action_data' => ['url' => home_url('/providers/$1')],
        'title' => 'Vendor route',
        'group_id' => $group->get_id(),
    ],
    [
        'url' => '/offers',
        'regex' => false,
        'match_type' => 'language',
        'action_type' => 'url',
        'action_code' => 302,
        'action_data' => [
            'language' => 'fr',
            'url_from' => home_url('/fr/offres/'),
            'url_notfrom' => home_url('/offers/'),
        ],
        'title' => 'Localized offers',
        'group_id' => $group->get_id(),
    ],
    [
        'url' => '/retired-service',
        'regex' => false,
        'match_type' => 'url',
        'action_type' => 'error',
        'action_code' => 410,
        'action_data' => [],
        'title' => 'Retired service',
        'group_id' => $group->get_id(),
    ],
];

$ids = [];
foreach ($definitions as $definition) {
    $item = Red_Item::create($definition);
    if (is_wp_error($item) || !$item instanceof Red_Item) {
        throw new RuntimeException('Redirection rejected a campaign rule');
    }
    $ids[$definition['title']] = $item->get_id();
}

global $wpdb;
$shapes = $wpdb->get_results(
    "SELECT title, action_data FROM {$wpdb->prefix}redirection_items WHERE group_id=" . (int) $group->get_id(),
    ARRAY_A
);
$shapeCounts = ['null' => 0, 'plain' => 0, 'serialized' => 0];
foreach ((array) $shapes as $row) {
    if ($row['action_data'] === null) {
        $shapeCounts['null']++;
    } elseif (is_serialized($row['action_data'])) {
        $shapeCounts['serialized']++;
    } else {
        $shapeCounts['plain']++;
    }
}
if ($shapeCounts !== ['null' => 1, 'plain' => 2, 'serialized' => 1]) {
    throw new RuntimeException('native Redirection action_data framing changed');
}

echo wp_json_encode([
    'cache_key' => (int) Red_Options::get()['cache_key'],
    'group_id' => $group->get_id(),
    'item_ids' => $ids,
    'page_id' => (int) $page,
    'shapes' => $shapeCounts,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHPEOF

SEED_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-redirection-seed.php"
printf '%s' "$SEED_PHP" > "$SEED_FILE"
SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-redirection-seed.php)
require_observed_nonempty "Redirection source seed" "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$SEED_JSON" | jq -e '
  .cache_key > 0 and .group_id > 0 and .page_id > 0 and
  (.item_ids | length) == 4 and
  .shapes == {null:1, plain:2, serialized:1}
' >/dev/null || fail "Redirection native source seed did not land: $SEED_JSON"
rm -f "$SEED_FILE"

SUMMER_CODE=$(curl --max-time 20 -sS -o /dev/null -w '%{http_code}' "http://localhost:${CONF1_PORT}/summer")
RETIRED_CODE=$(curl --max-time 20 -sS -o /dev/null -w '%{http_code}' "http://localhost:${CONF1_PORT}/retired-service")
[ "$SUMMER_CODE" = 302 ] && [ "$RETIRED_CODE" = 410 ] \
  || fail "Redirection source traffic premise failed (summer=$SUMMER_CODE retired=$RETIRED_CODE)"
RUNTIME=$(wp_conf1 eval '
  global $wpdb;
  echo wp_json_encode([
    "hits" => (int) $wpdb->get_var("SELECT SUM(last_count) FROM {$wpdb->prefix}redirection_items"),
    "logs" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}redirection_logs"),
  ]);
')
require_observed_nonempty "Redirection source runtime rows" "$RUNTIME"
jq -e '.hits >= 1 and .logs >= 1' <<<"$RUNTIME" >/dev/null \
  || fail "Redirection source requests did not produce runtime state: $RUNTIME"
pass "Redirection source uses native APIs, all three action_data frames, portable settings, mapped references, and real runtime traffic"
