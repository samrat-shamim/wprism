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
$organizers = [];
foreach ([
    [
        'organizer' => 'Duo Readiness Team 東京',
        'email' => 'events@example.test',
        'phone' => '+977-555-0101',
        'website' => home_url('/readiness-organizer/'),
    ],
    [
        'organizer' => 'Duo Accessibility Guild বাংলা',
        'email' => 'accessibility@example.test',
        'phone' => '+977-555-0102',
        'website' => home_url('/readiness-accessibility/'),
    ],
    [
        'organizer' => 'Duo Night Crew مرحبا',
        'email' => 'night@example.test',
        'phone' => '+977-555-0103',
        'website' => home_url('/readiness-night-crew/'),
    ],
] as $organizer_args) {
    $organizers[] = tribe_organizers()->set_args($organizer_args)->create();
}
$organizer = $organizers[0];
if (!$venue || !$venue->ID || count(array_filter(
    $organizers,
    static fn($candidate): bool => $candidate && $candidate->ID
)) !== 3) {
    throw new RuntimeException('TEC venue/organizer repositories did not create the source graph');
}
$organizer_ids = array_map(static fn($candidate): int => (int) $candidate->ID, $organizers);

$disabled_venue_id = tribe_create_venue([
    'Venue' => 'Duo Map Disabled Venue',
    'Address' => '200 Native False Street',
    'City' => 'Kathmandu',
    'Country' => 'Nepal',
    'ShowMap' => false,
    'ShowMapLink' => false,
]);
$absent_map_venue = tribe_venues()->set_args([
    'venue' => 'Duo Map Metadata Absent Venue',
    'address' => '300 Legacy Boundary Street',
    'city' => 'Kathmandu',
    'country' => 'Nepal',
])->create();
if (!$disabled_venue_id || !$absent_map_venue || !$absent_map_venue->ID) {
    throw new RuntimeException('TEC map-boundary venues did not create through native APIs');
}

$cost_description = 'Admission details 東京 — bring ID';
$date_separator = ' · at · ';
$time_separator = ' · until · ';
$body = str_repeat('Portable long event body — বাংলা — 日本語 — مرحبا. ', 700)
    . "\nNative source URL: " . home_url('/events/portable-source/')
    . "\n<!-- wp:tribe/event-datetime /-->"
    . "\n<!-- wp:tribe/event-price {\"costDescription\":\"$cost_description\"} /-->";
foreach ($organizer_ids as $organizer_id) {
    $body .= "\n<!-- wp:tribe/event-organizer "
        . wp_json_encode(['organizer' => $organizer_id], JSON_UNESCAPED_SLASHES)
        . ' /-->';
}
$event = tribe_events()->set_args([
    'title' => 'Duo Production Readiness Event 東京',
    'status' => 'publish',
    'description' => $body,
    'start_date' => '2026-09-05 22:30:00',
    'end_date' => '2026-09-06 01:45:00',
    'timezone' => 'Asia/Kathmandu',
    'venue' => (int) $venue->ID,
    'organizers' => $organizer_ids,
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
$editor_meta = new Tribe__Events__Editor__Meta();
$editor_meta->register();
update_post_meta((int) $event->ID, '_EventCostDescription', '<b>Admission details</b> 東京 — bring ID');
update_post_meta((int) $event->ID, '_EventDateTimeSeparator', ' <em>· at ·</em> ');
update_post_meta((int) $event->ID, '_EventTimeRangeSeparator', ' <em>· until ·</em> ');
foreach ([
    '_EventCostDescription' => $cost_description,
    '_EventDateTimeSeparator' => $date_separator,
    '_EventTimeRangeSeparator' => $time_separator,
] as $key => $expected) {
    if (get_post_meta((int) $event->ID, $key, true) !== $expected) {
        throw new RuntimeException("TEC registered-meta sanitizer did not persist exact $key state");
    }
}
$terms = wp_set_object_terms((int) $event->ID, [$category_id], 'tribe_events_cat');
$tags = wp_set_object_terms((int) $event->ID, ['readiness', '東京'], 'post_tag');
if (is_wp_error($terms) || is_wp_error($tags)) {
    throw new RuntimeException('TEC source event taxonomy assignment failed');
}

$all_day = tribe_events()->set_args([
    'title' => 'Duo All Day Boundary Event',
    'status' => 'publish',
    'description' => "All-day portable event with no venue or organizer.\n<!-- wp:tribe/event-organizer /-->",
    'start_date' => '2026-10-11 00:00:00',
    'end_date' => '2026-10-11 23:59:59',
    'timezone' => 'Asia/Kathmandu',
    'all_day' => true,
    'hide_from_upcoming' => true,
    'cost' => '0',
    'show_map' => false,
    'show_map_link' => false,
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
update_post_meta((int) $delete_probe->ID, '_EventAllDay', false);
if (get_post_meta((int) $all_day->ID, '_EventAllDay', true) !== '1'
    || get_post_meta((int) $all_day->ID, '_EventHideFromUpcoming', true) !== 'yes'
    || !metadata_exists('post', (int) $delete_probe->ID, '_EventAllDay')
    || get_post_meta((int) $delete_probe->ID, '_EventAllDay', true) !== '') {
    throw new RuntimeException('TEC registered/repository boolean paths did not persist their exact native wires');
}
delete_post_meta((int) $delete_probe->ID, '_EventShowMap');
delete_post_meta((int) $delete_probe->ID, '_EventShowMapLink');

$status_editor = tribe(\Tribe\Events\Event_Status\Classic_Editor::class);
$status_editor->register_fields();
$status_editor->update_fields((int) $event->ID, [
    'status' => 'canceled',
    'status-reason' => 'Weather <strong>closure</strong> 東京 — doors remain shut.',
]);
$status_editor->update_fields((int) $all_day->ID, [
    'status' => 'postponed',
    'status-reason' => '',
]);
$status_editor->delete_fields((int) $delete_probe->ID, ['status' => 'scheduled']);

if (get_post_meta((int) $event->ID, '_EventOrganizerID', false) !== array_map('strval', $organizer_ids)
    || tribe_get_organizer_ids((int) $event->ID) !== array_map('strval', $organizer_ids)) {
    throw new RuntimeException('TEC native repository did not preserve unique organizer row order');
}
$status_expectations = [
    [(int) $event->ID, 'canceled', 'Weather <strong>closure</strong> 東京 — doors remain shut.'],
    [(int) $all_day->ID, 'postponed', ''],
];
foreach ($status_expectations as [$event_id, $status, $reason]) {
    $model = tribe_get_event($event_id);
    if (get_post_meta($event_id, '_tribe_events_status', true) !== $status
        || get_post_meta($event_id, '_tribe_events_status_reason', true) !== $reason
        || !$model instanceof WP_Post
        || $model->event_status !== $status
        || $model->event_status_reason !== $reason) {
        throw new RuntimeException("TEC native event-status readback mismatch on $event_id");
    }
}
foreach (['_tribe_events_status', '_tribe_events_status_reason'] as $key) {
    if (metadata_exists('post', (int) $delete_probe->ID, $key)) {
        throw new RuntimeException("TEC scheduled-as-absence boundary retained $key");
    }
}

// These are native auto-draft preview cleanup lists, not authored graph refs.
// Persist them through the owning core methods so capture must exclude the
// exact serialized wire while a dirty target keeps its own local cleanup list.
$tec_main = Tribe__Events__Main::instance();
$tec_main->link_preview_venue_to_event((int) $venue->ID, (int) $event->ID);
$tec_main->link_preview_organizer_to_event($organizer_ids, (int) $event->ID);
if (get_post_meta((int) $event->ID, '_preview_venues', true) !== [(int) $venue->ID]
    || get_post_meta((int) $event->ID, '_preview_organizers', true) !== $organizer_ids) {
    throw new RuntimeException('TEC native preview cleanup lists did not persist exact source state');
}

$map_expectations = [
    [(int) $event->ID, '_EventShowMap', '1', true],
    [(int) $event->ID, '_EventShowMapLink', '1', true],
    [(int) $all_day->ID, '_EventShowMap', '', false],
    [(int) $all_day->ID, '_EventShowMapLink', '', false],
    [(int) $venue->ID, '_VenueShowMap', '1', true],
    [(int) $venue->ID, '_VenueShowMapLink', '1', true],
    [(int) $disabled_venue_id, '_EventShowMap', 'false', false],
    [(int) $disabled_venue_id, '_EventShowMapLink', 'false', false],
    [(int) $disabled_venue_id, '_VenueShowMap', 'false', false],
    [(int) $disabled_venue_id, '_VenueShowMapLink', 'false', false],
];
foreach ($map_expectations as [$post_id, $key, $expected, $truthy]) {
    if (get_post_meta($post_id, $key, true) !== $expected) {
        throw new RuntimeException("TEC native map wire mismatch for $key on $post_id");
    }
    $rendered = str_ends_with($key, 'ShowMapLink')
        ? tribe_show_google_map_link($post_id)
        : tribe_embed_google_map($post_id);
    if ($rendered !== $truthy) {
        throw new RuntimeException("TEC native map readback mismatch for $key on $post_id");
    }
}
foreach (['_EventShowMap', '_EventShowMapLink'] as $key) {
    if (metadata_exists('post', (int) $delete_probe->ID, $key)) {
        throw new RuntimeException("TEC event absence boundary unexpectedly retained $key");
    }
}
foreach (['_EventShowMap', '_EventShowMapLink', '_VenueShowMap', '_VenueShowMapLink'] as $key) {
    if (metadata_exists('post', (int) $absent_map_venue->ID, $key)) {
        throw new RuntimeException("TEC venue absence boundary unexpectedly retained $key");
    }
}
if (tribe_embed_google_map((int) $delete_probe->ID)
    || tribe_show_google_map_link((int) $delete_probe->ID)
    || tribe_embed_google_map((int) $absent_map_venue->ID)
    || tribe_show_google_map_link((int) $absent_map_venue->ID)) {
    throw new RuntimeException('TEC absent map metadata did not render as disabled');
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
    'toggle_blocks_editor' => true,
    'debugEvents' => true,
    'enable_month_view_cache' => false,
    'trash-past-events' => 3,
    'delete-past-events' => 6,
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

$editing_fields = apply_filters('tribe_general_settings_editing_section', [
    'disable_metabox_custom_fields' => ['type' => 'checkbox_bool'],
]);
$toggle_field = $editing_fields['toggle_blocks_editor'] ?? null;
if (!is_array($toggle_field)
    || ($toggle_field['type'] ?? null) !== 'checkbox_bool'
    || ($toggle_field['default'] ?? null) !== false
    || ($toggle_field['validation_type'] ?? null) !== 'boolean'
    || Tribe__Events__Editor__Compatibility::$blocks_editor_key !== 'toggle_blocks_editor') {
    throw new RuntimeException('TEC native settings registry lost the portable Block Editor toggle contract');
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
    'absent_map_venue' => (int) $absent_map_venue->ID,
    'category' => $category_id,
    'delete_probe' => (int) $delete_probe->ID,
    'disabled_venue' => (int) $disabled_venue_id,
    'event' => (int) $event->ID,
    'organizer' => (int) $organizer->ID,
    'organizers' => $organizer_ids,
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
  .event > 0 and .venue > 0 and .organizer > 0 and (.organizers | length) == 3 and
  .organizer == .organizers[0] and (.organizers | unique | length) == 3 and .category > 0 and
  .all_day > 0 and .delete_probe > 0 and .disabled_venue > 0 and .absent_map_venue > 0
' >/dev/null || fail "TEC source repository fixture returned malformed identities: $SEED_JSON"
printf '%s\n' "$SEED_JSON" > "$SOURCE_IDS_FILE"
rm -f "$SEED_FILE"
pass "TEC source authored registered editor meta, ordered organizers, event statuses, map boundaries, runtime previews, settings, and occurrences"
