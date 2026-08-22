#!/usr/bin/env bash
# Target-side hostile premise: force post IDs away from the source, then
# author a same-slug event with stale occurrence dates. Apply must adopt and
# converge it; merely observing an existing occurrence row is insufficient.
set -euo pipefail

read -r -d '' TARGET_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

for ($i = 0; $i < 12; $i++) {
    $id = wp_insert_post([
        'post_type' => 'post',
        'post_status' => 'draft',
        'post_title' => "TEC identity spacer $i",
    ], true);
    if (is_wp_error($id) || !$id || !wp_delete_post((int) $id, true)) {
        throw new RuntimeException("could not consume target post identity $i");
    }
}

$dirty = tribe_events()->set_args([
    'title' => 'Duo Production Readiness Event',
    'status' => 'publish',
    'description' => 'Target-only stale event body.',
    'start_date' => '2031-01-02 03:00:00',
    'end_date' => '2031-01-02 04:00:00',
])->create();
if (!$dirty || !$dirty->ID) {
    throw new RuntimeException('TEC did not create the dirty same-slug target event');
}

global $wpdb;
$inserted = $wpdb->insert(
    $wpdb->prefix . 'tec_kv_cache',
    [
        'cache_key' => 'duo-readiness-target-only',
        'value' => 'target-runtime-preserved',
        'expiration' => 4102444800,
    ]
);
if ($inserted === false) {
    throw new RuntimeException('TEC target-only cache premise could not be inserted');
}

echo wp_json_encode(['dirty_event' => (int) $dirty->ID], JSON_UNESCAPED_SLASHES);
PHPEOF

TARGET_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-target.php"
TARGET_IDS_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-target-ids.json"
printf '%s' "$TARGET_PHP" > "$TARGET_FILE"
TARGET_OUT=$(wp_conf2 eval-file /siterepo/.tmp-tec-target.php)
require_observed_nonempty "TEC dirty target premise" "$TARGET_OUT"
TARGET_JSON=$(printf '%s\n' "$TARGET_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$TARGET_JSON" | jq -e '.dirty_event > 0' >/dev/null \
  || fail "TEC dirty target premise returned a malformed identity: $TARGET_JSON"
printf '%s\n' "$TARGET_JSON" > "$TARGET_IDS_FILE"
rm -f "$TARGET_FILE"
pass "TEC target has divergent IDs, a stale same-slug occurrence, and target-only runtime cache state"
