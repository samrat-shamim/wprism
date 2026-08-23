#!/usr/bin/env bash
# Exact 6.17.2 source fixture: real venue, organizer, and event repository
# APIs populate both authored post/meta state and TEC's occurrence dependency.
set -euo pipefail

read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

$venue = tribe_venues()->set_args([
    'venue' => 'Duo Readiness Hall',
    'address' => '100 Portable Street',
    'city' => 'Dhaka',
    'state' => 'Dhaka',
    'zip' => '1205',
    'country' => 'Bangladesh',
    'phone' => '+880-555-0100',
])->create();
$organizer = tribe_organizers()->set_args([
    'organizer' => 'Duo Readiness Team',
    'email' => 'events@example.test',
    'phone' => '+880-555-0101',
])->create();
if (!$venue || !$venue->ID || !$organizer || !$organizer->ID) {
    throw new RuntimeException('TEC venue/organizer repositories did not create the source graph');
}

$event = tribe_events()->set_args([
    'title' => 'Duo Production Readiness Event',
    'status' => 'publish',
    'description' => 'Portable event body with UTF-8: বাংলা café.',
    'start_date' => '2026-09-05 17:00:00',
    'end_date' => '2026-09-05 20:00:00',
    'venue' => (int) $venue->ID,
    'organizer' => (int) $organizer->ID,
])->create();
if (!$event || !$event->ID) {
    throw new RuntimeException('TEC event repository did not create the source event');
}

global $wpdb;
$occurrences = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$wpdb->prefix}tec_occurrences WHERE post_id = %d",
    (int) $event->ID
));
if ($occurrences !== 1) {
    throw new RuntimeException("TEC source event has $occurrences occurrence rows; expected one");
}

echo wp_json_encode([
    'event' => (int) $event->ID,
    'organizer' => (int) $organizer->ID,
    'venue' => (int) $venue->ID,
], JSON_UNESCAPED_SLASHES);
PHPEOF

SEED_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-seed.php"
SOURCE_IDS_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-source-ids.json"
printf '%s' "$SEED_PHP" > "$SEED_FILE"
SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-tec-seed.php)
require_observed_nonempty "TEC source repository fixture" "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$SEED_JSON" | jq -e '.event > 0 and .venue > 0 and .organizer > 0' >/dev/null \
  || fail "TEC source repository fixture returned malformed identities: $SEED_JSON"
printf '%s\n' "$SEED_JSON" > "$SOURCE_IDS_FILE"
rm -f "$SEED_FILE"
pass "TEC source repository authored a venue/organizer/event graph and one occurrence row"
