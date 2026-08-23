#!/usr/bin/env bash
# Exact supported-release free-plugin fixture through TEC repositories and native
# Category Colors/settings APIs. It deliberately carries long Unicode,
# cross-midnight/non-hour timezone dates, linked defaults, and target-owned
# integration siblings so the round trip covers more than a minimal event.
set -euo pipefail

read -r -d '' SEED_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);

$category = wp_insert_term('Duo Readiness 東京', 'tribe_events_cat', [
    'slug' => 'duo-readiness-category',
    'description' => 'Portable category description — বাংলা — مرحبا',
]);
if (is_wp_error($category)) {
    throw new RuntimeException($category->get_error_message());
}
$category_id = (int) $category['term_id'];
$color = tribe(\TEC\Events\Category_Colors\Event_Category_Meta::class)->set_term($category_id);
$color->set('tec-events-cat-colors-primary', '#123abc')
    ->set('tec-events-cat-colors-secondary', '#fedcba')
    ->set('tec-events-cat-colors-text', '#ffffff')
    ->set('tec-events-cat-colors-priority', 17)
    ->set('tec-events-cat-colors-hidden', '0')
    ->save();

$venue = tribe_venues()->set_args([
    'venue' => 'Duo Readiness Hall 東京',
    'address' => '100 Portable Street — ভবন ৭',
    'city' => 'Kathmandu',
    'state' => 'Bagmati',
    'province' => 'Bagmati Province',
    'state_province' => 'Bagmati Province',
    'zip' => '44600',
    'country' => 'Nepal',
    'phone' => '+977-555-0100',
    'website' => home_url('/readiness-venue/?source=duo'),
    'show_map' => true,
    'show_map_link' => true,
])->create();
$organizer = tribe_organizers()->set_args([
    'organizer' => 'Duo Readiness Team 東京',
    'email' => 'events@example.test',
    'phone' => '+977-555-0101',
    'website' => home_url('/readiness-organizer/'),
])->create();
if (!$venue || !$venue->ID || !$organizer || !$organizer->ID) {
    throw new RuntimeException('TEC venue/organizer repositories did not create the source graph');
}

$body = str_repeat('Portable long event body — বাংলা — 日本語 — مرحبا. ', 700)
    . "\nNative source URL: " . home_url('/events/portable-source/');
$event = tribe_events()->set_args([
    'title' => 'Duo Production Readiness Event 東京',
    'status' => 'publish',
    'description' => $body,
    'start_date' => '2026-09-05 22:30:00',
    'end_date' => '2026-09-06 01:45:00',
    'timezone' => 'Asia/Kathmandu',
    'venue' => (int) $venue->ID,
    'organizer' => (int) $organizer->ID,
    'cost' => '125.50',
    'currency_symbol' => 'रु',
    'currency_position' => 'postfix',
    'show_map' => true,
    'show_map_link' => true,
    'url' => home_url('/tickets/readiness/?edition=東京'),
    'featured' => true,
])->create();
if (!$event || !$event->ID) {
    throw new RuntimeException('TEC event repository did not create the source event');
}
update_post_meta((int) $event->ID, '_EventCurrencyCode', 'NPR');
update_post_meta((int) $event->ID, '_EventPhone', '+977-555-0199');
$terms = wp_set_object_terms((int) $event->ID, [$category_id], 'tribe_events_cat');
$tags = wp_set_object_terms((int) $event->ID, ['readiness', '東京'], 'post_tag');
if (is_wp_error($terms) || is_wp_error($tags)) {
    throw new RuntimeException('TEC source event taxonomy assignment failed');
}

$all_day = tribe_events()->set_args([
    'title' => 'Duo All Day Boundary Event',
    'status' => 'publish',
    'description' => 'All-day portable event with no venue or organizer.',
    'start_date' => '2026-10-11 00:00:00',
    'end_date' => '2026-10-11 23:59:59',
    'timezone' => 'Asia/Kathmandu',
    'all_day' => true,
    'cost' => '0',
])->create();
$delete_probe = tribe_events()->set_args([
    'title' => 'Duo Unsupported Delete Probe',
    'status' => 'publish',
    'description' => 'This event exists only to prove unsupported deletion refuses atomically.',
    'start_date' => '2026-11-01 08:00:00',
    'end_date' => '2026-11-01 09:00:00',
    'timezone' => 'UTC',
])->create();
if (!$all_day || !$all_day->ID || !$delete_probe || !$delete_probe->ID) {
    throw new RuntimeException('TEC boundary events were not created');
}

foreach ([
    'eventsSlug' => 'calendar-readiness',
    'singleEventSlug' => 'readiness-event',
    'tribeEnableViews' => ['list', 'month'],
    'viewOption' => 'list',
    'dateWithYearFormat' => 'j M Y',
    'tribeEventsBeforeHTML' => '<p class="duo-before">Readiness before 東京</p>',
    'tribeEventsAfterHTML' => '<p class="duo-after">Readiness after বাংলা</p>',
    'defaultCurrencySymbol' => 'रु',
    'defaultCurrencyCode' => 'NPR',
    'reverseCurrencyPosition' => true,
    'postsPerPage' => 17,
    'showEventsInMainLoop' => true,
    'tribeDisableTribeBar' => true,
    'tribe_events_timezone_mode' => 'event',
    'tribe_events_timezones_show_zone' => true,
    'category-color-enable-frontend' => true,
    'category-color-legend-show' => ['list', 'month'],
    'category-color-legend-superpowers' => true,
    'category-color-show-hidden-categories' => false,
    'tec_seo_out_of_range_behavior' => 'soft_noindex',
    'tec_seo_noindex_dated_list_urls' => true,
    'tec_seo_disabled_view_404' => true,
    'eventsDefaultVenueID' => (int) $venue->ID,
    'eventsDefaultOrganizerID' => (int) $organizer->ID,
    'duo_source_only_secret' => 'source-integration-value-must-not-copy',
    'google_maps_js_api_key' => 'source-maps-key-must-not-copy',
] as $key => $value) {
    if (!tribe_update_option($key, $value)) {
        $actual = tribe_get_option($key, '__missing__');
        if ($actual !== $value) {
            throw new RuntimeException("TEC source option $key did not persist");
        }
    }
}

// Event_Category_Meta::save() deliberately does not run the wp-admin hook
// that owns Category Colors CSS. Generate through TEC's native controller so
// the source fixture proves the real derived path while capture excludes it.
tribe(\TEC\Events\Category_Colors\CSS\Controller::class)->generate_css();
$category_css = get_option('tec_events_category_color_css', '');
if (!is_string($category_css)
    || !str_contains($category_css, '.tribe_events_cat-duo-readiness-category{')
    || !str_contains($category_css, '#123abc')) {
    throw new RuntimeException('TEC native source Category Colors CSS did not generate');
}

global $wpdb;
foreach ([(int) $event->ID, (int) $all_day->ID, (int) $delete_probe->ID] as $event_id) {
    $occurrences = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->prefix}tec_occurrences WHERE post_id = %d",
        $event_id
    ));
    if ($occurrences !== 1) {
        throw new RuntimeException("TEC source event $event_id has $occurrences occurrence rows; expected one");
    }
}

echo wp_json_encode([
    'all_day' => (int) $all_day->ID,
    'category' => $category_id,
    'delete_probe' => (int) $delete_probe->ID,
    'event' => (int) $event->ID,
    'organizer' => (int) $organizer->ID,
    'venue' => (int) $venue->ID,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHPEOF

SEED_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-seed.php"
SOURCE_IDS_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-source-ids.json"
printf '%s' "$SEED_PHP" > "$SEED_FILE"
SEED_OUT=$(wp_conf1 eval-file /siterepo/.tmp-tec-seed.php)
require_observed_nonempty "TEC source repository fixture" "$SEED_OUT"
SEED_JSON=$(printf '%s\n' "$SEED_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$SEED_JSON" | jq -e '
  .event > 0 and .venue > 0 and .organizer > 0 and .category > 0 and
  .all_day > 0 and .delete_probe > 0
' >/dev/null || fail "TEC source repository fixture returned malformed identities: $SEED_JSON"
printf '%s\n' "$SEED_JSON" > "$SOURCE_IDS_FILE"
rm -f "$SEED_FILE"
pass "TEC source authored a long linked event, all-day/no-ref event, delete probe, category colors, settings, and occurrences"
