#!/usr/bin/env bash
# Turn activation defaults into target-owned mapped residue, diverge future ids,
# plant a target-only redirect/log/404, and prime a negative cache generation.
# Apply must preserve all of it while adding the repository graph under new ids.
set -euo pipefail

read -r -d '' HOSTILE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

foreach (Red_Group::get_all() as $row) {
    $group = Red_Group::get((int) $row['id'], true);
    if (!$group instanceof Red_Group
        || !$group->update(['name' => 'Target activation residue ' . (int) $row['id'], 'moduleId' => 1])) {
        throw new RuntimeException('could not rename a target activation group');
    }
}

global $wpdb;
$wpdb->query("ALTER TABLE {$wpdb->prefix}redirection_groups AUTO_INCREMENT=101");
$wpdb->query("ALTER TABLE {$wpdb->prefix}redirection_items AUTO_INCREMENT=201");
$group = Red_Group::create('Target-only operations', WordPress_Module::MODULE_ID, true);
if (!$group instanceof Red_Group) {
    throw new RuntimeException('could not create target-only group');
}
$item = Red_Item::create([
    'url' => '/target-only-redirect',
    'regex' => false,
    'match_type' => 'url',
    'action_type' => 'url',
    'action_code' => 302,
    'action_data' => ['url' => home_url('/target-only-destination/')],
    'title' => 'Target-only redirect',
    'group_id' => $group->get_id(),
]);
if (is_wp_error($item) || !$item instanceof Red_Item) {
    throw new RuntimeException('could not create target-only item');
}

Red_Options::save(['cache_key' => true]);
update_option('duo_redirection_target_neighbor', 'target-only-neighbor');
file_put_contents('/siterepo/.redirection-target-fixture.json', wp_json_encode([
    'cache_key' => (int) Red_Options::get()['cache_key'],
    'group_id' => $group->get_id(),
    'item_id' => $item->get_id(),
], JSON_UNESCAPED_SLASHES));

echo wp_json_encode([
    'cache_key' => (int) Red_Options::get()['cache_key'],
    'group_id' => $group->get_id(),
    'item_id' => $item->get_id(),
    'neighbor' => get_option('duo_redirection_target_neighbor'),
], JSON_UNESCAPED_SLASHES);
PHPEOF

HOSTILE_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-redirection-hostile.php"
printf '%s' "$HOSTILE_PHP" > "$HOSTILE_FILE"
HOSTILE_OUT=$(wp_conf2 eval-file /siterepo/.tmp-redirection-hostile.php)
require_observed_nonempty "Redirection hostile target seed" "$HOSTILE_OUT"
HOSTILE_JSON=$(printf '%s\n' "$HOSTILE_OUT" | awk 'NF { line=$0 } END { print line }')
jq -e '.cache_key > 0 and .group_id >= 101 and .item_id >= 201 and .neighbor == "target-only-neighbor"' \
  <<<"$HOSTILE_JSON" >/dev/null || fail "Redirection hostile target ids/state did not land: $HOSTILE_JSON"
rm -f "$HOSTILE_FILE"

TARGET_REDIRECT_CODE=$(curl --max-time 20 -sS -o /dev/null -w '%{http_code}' "http://localhost:${CONF2_PORT}/target-only-redirect")
TARGET_MISSING_CODE=$(curl --max-time 20 -sS -o /dev/null -w '%{http_code}' "http://localhost:${CONF2_PORT}/target-only-missing")
NEGATIVE_CODE=$(curl --max-time 20 -sS -o /dev/null -w '%{http_code}' "http://localhost:${CONF2_PORT}/summer")
[ "$TARGET_REDIRECT_CODE" = 302 ] && [ "$TARGET_MISSING_CODE" = 404 ] && [ "$NEGATIVE_CODE" = 404 ] \
  || fail "Redirection target cache/log premises failed (redirect=$TARGET_REDIRECT_CODE missing=$TARGET_MISSING_CODE negative=$NEGATIVE_CODE)"

# A populated mapped row needs its own target identity before the repository's
# unrelated mapped graph is applied. The disposable output is never published;
# only the database-matched duo_map evidence survives.
TARGET_IDENTITY_CAPTURE=$(wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-redirection-target-identity 2>&1)
require_duo_answered "Redirection target-only identity capture" human "$TARGET_IDENTITY_CAPTURE"
rm -rf "${CONF_REPO2:-siterepo/conf2}/.tmp-redirection-target-identity"
RUNTIME=$(wp_conf2 eval '
  global $wpdb;
  echo wp_json_encode([
    "logs" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}redirection_logs"),
    "not_found" => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}redirection_404"),
  ]);
')
require_observed_nonempty "Redirection target runtime premises" "$RUNTIME"
jq -e '.logs >= 1 and .not_found >= 1' <<<"$RUNTIME" >/dev/null \
  || fail "Redirection target requests did not create logs and 404 rows: $RUNTIME"
pass "Redirection target starts with divergent mapped residue, a target-only rule, runtime logs, and a primed negative cache"
