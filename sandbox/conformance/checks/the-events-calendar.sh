#!/usr/bin/env bash
# Plugin-visible target proof: adopted IDs diverge, refs resolve, stale
# occurrences converge, runtime cache survives, and TEC/WP/frontend queries see
# the event. The generic harness already enforces byte-identical recapture.
set -euo pipefail

SOURCE_IDS_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-source-ids.json"
TARGET_IDS_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-target-ids.json"
[ -f "$SOURCE_IDS_FILE" ] || fail "TEC source identity premise is missing: $SOURCE_IDS_FILE"
[ -f "$TARGET_IDS_FILE" ] || fail "TEC target identity premise is missing: $TARGET_IDS_FILE"
SOURCE_IDS=$(cat "$SOURCE_IDS_FILE")
DIRTY_TARGET_ID=$(jq -er '.dirty_event' "$TARGET_IDS_FILE")

read -r -d '' CHECK_PHP <<'PHPEOF' || true
<?php
$events = get_posts([
    'post_type' => 'tribe_events',
    'post_status' => 'any',
    'posts_per_page' => -1,
    'name' => 'duo-production-readiness-event',
]);
if (count($events) !== 1) {
    throw new RuntimeException('expected exactly one adopted target event, got ' . count($events));
}
$event = $events[0];
$venue = get_post((int) get_post_meta($event->ID, '_EventVenueID', true));
$organizer = get_post((int) get_post_meta($event->ID, '_EventOrganizerID', true));
global $wpdb;
$occurrence = $wpdb->get_row($wpdb->prepare(
    "SELECT start_date, end_date FROM {$wpdb->prefix}tec_occurrences WHERE post_id = %d",
    (int) $event->ID
), ARRAY_A);
$repository_event = tribe_events()->where('id', (int) $event->ID)->first();
$cache = $wpdb->get_var($wpdb->prepare(
    "SELECT value FROM {$wpdb->prefix}tec_kv_cache WHERE cache_key = %s",
    'duo-readiness-target-only'
));

echo wp_json_encode([
    'cache' => $cache,
    'content' => $event->post_content,
    'end_meta' => get_post_meta($event->ID, '_EventEndDate', true),
    'event' => (int) $event->ID,
    'occurrence' => $occurrence,
    'organizer' => $organizer ? (int) $organizer->ID : 0,
    'organizer_email' => $organizer ? get_post_meta($organizer->ID, '_OrganizerEmail', true) : '',
    'permalink' => get_permalink($event),
    'repository_event' => $repository_event ? (int) $repository_event->ID : 0,
    'start_meta' => get_post_meta($event->ID, '_EventStartDate', true),
    'venue' => $venue ? (int) $venue->ID : 0,
    'venue_city' => $venue ? get_post_meta($venue->ID, '_VenueCity', true) : '',
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHPEOF

CHECK_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-check.php"
printf '%s' "$CHECK_PHP" > "$CHECK_FILE"
CHECK_OUT=$(wp_conf2 eval-file /siterepo/.tmp-tec-check.php)
require_observed_nonempty "TEC target API readback" "$CHECK_OUT"
CHECK_JSON=$(printf '%s\n' "$CHECK_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$CHECK_JSON" | jq -e \
  --argjson source "$SOURCE_IDS" \
  --argjson dirty "$DIRTY_TARGET_ID" '
    .event == $dirty and
    .event != $source.event and .venue != $source.venue and .organizer != $source.organizer and
    .repository_event == .event and
    .start_meta == "2026-09-05 17:00:00" and .end_meta == "2026-09-05 20:00:00" and
    .occurrence.start_date == .start_meta and .occurrence.end_date == .end_meta and
    .venue_city == "Dhaka" and .organizer_email == "events@example.test" and
    (.content | contains("বাংলা café")) and
    .cache == "target-runtime-preserved" and
    (.permalink | type == "string" and length > 0)
  ' >/dev/null || fail "TEC target did not converge through its native query/occurrence/reference boundary: $CHECK_JSON"

PERMALINK=$(printf '%s\n' "$CHECK_JSON" | jq -er '.permalink')
FRONT=$(curl -fsSL "$PERMALINK") || fail "TEC target event permalink did not return 200: $PERMALINK"
require_observed_nonempty "TEC target event response" "$FRONT"
[ "${#FRONT}" -ge 1000 ] || fail "TEC target event response was suspiciously short (${#FRONT} bytes)"
grep -qF 'Duo Production Readiness Event' <<<"$FRONT" \
  || fail "TEC target event response did not contain the canonical title"
grep -qF 'Portable event body with UTF-8' <<<"$FRONT" \
  || fail "TEC target event response did not contain the canonical body"

rm -f "$CHECK_FILE" "$SOURCE_IDS_FILE" "$TARGET_IDS_FILE"
pass "TEC adopted divergent identities, repaired stale occurrences, preserved runtime cache, and rendered canonical target behavior"
