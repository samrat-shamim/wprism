#!/usr/bin/env bash
# Hostile target: huge divergent identities, same-slug event/venue/organizer
# and category rows, stale occurrence projections, conflicting portable
# settings, and target-owned credential/cache siblings.
set -euo pipefail

read -r -d '' TARGET_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
global $wpdb;

foreach ([$wpdb->posts, $wpdb->terms, $wpdb->term_taxonomy] as $table) {
    $next = $table === $wpdb->posts ? 7000000000 : 7100000000;
    if (false === $wpdb->query("ALTER TABLE `$table` AUTO_INCREMENT = $next")) {
        throw new RuntimeException("could not widen target identity in $table: {$wpdb->last_error}");
    }
}

$category = wp_insert_term('WPrism Readiness 東京', 'tribe_events_cat', [
    'slug' => 'wprism-readiness-category',
    'description' => 'Target-only stale category description.',
]);
if (is_wp_error($category)) {
    throw new RuntimeException($category->get_error_message());
}
$category_id = (int) $category['term_id'];
$color = tribe(\TEC\Events\Category_Colors\Event_Category_Meta::class)->set_term($category_id);
$color->set('tec-events-cat-colors-primary', '#000000')
    ->set('tec-events-cat-colors-secondary', '#111111')
    ->set('tec-events-cat-colors-text', '#222222')
    ->set('tec-events-cat-colors-priority', 99)
    ->set('tec-events-cat-colors-hidden', '1')
    ->save();

$venue = tribe_venues()->set_args([
    'venue' => 'WPrism Readiness Hall 東京',
    'address' => 'Target-only stale address',
    'city' => 'Target stale city',
    'country' => 'Target stale country',
    'website' => home_url('/target-stale-venue/'),
])->create();
$organizers = [];
foreach ([
    ['organizer' => 'WPrism Readiness Team 東京', 'email' => 'target-stale@example.test'],
    ['organizer' => 'WPrism Accessibility Guild বাংলা', 'email' => 'target-stale-accessibility@example.test'],
    ['organizer' => 'WPrism Night Crew مرحبا', 'email' => 'target-stale-night@example.test'],
] as $organizer_args) {
    $organizer_args['website'] = home_url('/target-stale-organizer/');
    $organizers[] = tribe_organizers()->set_args($organizer_args)->create();
}
$organizer = $organizers[0];
if (!$venue || !$venue->ID || count(array_filter(
    $organizers,
    static fn($candidate): bool => $candidate && $candidate->ID
)) !== 3) {
    throw new RuntimeException('TEC did not create hostile linked target rows');
}
$organizer_ids = array_map(static fn($candidate): int => (int) $candidate->ID, $organizers);

$widget_page_id = wp_insert_post([
    'post_type' => 'page',
    'post_status' => 'publish',
    'post_title' => 'WPrism TEC Legacy Widget Surface',
    'post_name' => 'wprism-tec-legacy-widget-surface',
    'post_content' => '<!-- wp:paragraph --><p>Target-only stale widget page.</p><!-- /wp:paragraph -->',
], true);
if (is_wp_error($widget_page_id) || (int) $widget_page_id < 7000000000) {
    throw new RuntimeException('TEC hostile target widget page did not receive a divergent identity');
}
global $wp_widget_factory;
if (!$wp_widget_factory->get_widget_object('tribe-widget-events-list')
    instanceof \Tribe\Events\Views\V2\Widgets\Widget_List
    || !$wp_widget_factory->get_widget_object('tribe-widget-events-qr-code')
    instanceof \Tribe\Events\Views\V2\Widgets\Widget_QR_Code) {
    throw new RuntimeException('TEC hostile target lost the reviewed native widget registry');
}
update_option('widget_tribe-widget-events-list', [
    1 => [
        'title' => 'Target-only stale list widget',
        'limit' => 1,
        'no_upcoming_events' => true,
        'featured_events_only' => true,
        'jsonld_enable' => false,
        'tribe_is_list_widget' => true,
    ],
    '_multiwidget' => 1,
], true);
update_option('widget_tribe-widget-events-qr-code', [
    1 => [
        'widget_title' => 'Target-only stale QR widget',
        'qr_code_size' => '4',
        'redirection' => 'current',
        'event_id' => 0,
        'series_id' => 0,
    ],
    '_multiwidget' => 1,
], true);
update_option('sidebars_widgets', [
    'wp_inactive_widgets' => [
        'tribe-widget-events-list-1',
        'tribe-widget-events-qr-code-1',
    ],
    'array_version' => 3,
], true);

$dirty = tribe_events()->set_args([
    'title' => 'WPrism Production Readiness Event 東京',
    'status' => 'publish',
    'description' => 'Target-only stale event body.',
    'start_date' => '2031-01-02 03:00:00',
    'end_date' => '2031-01-02 04:00:00',
    'timezone' => 'UTC',
    'all_day' => true,
    'hide_from_upcoming' => true,
    'venue' => (int) $venue->ID,
    'organizers' => [$organizer_ids[2], $organizer_ids[0], $organizer_ids[1]],
])->create();
if (!$dirty || !$dirty->ID) {
    throw new RuntimeException('TEC did not create the dirty same-slug target event');
}
wp_set_object_terms((int) $dirty->ID, [$category_id], 'tribe_events_cat');

$dirty_all_day = tribe_events()->set_args([
    'title' => 'WPrism All Day Boundary Event',
    'status' => 'publish',
    'description' => 'Target-only stale all-day body.',
    'start_date' => '2031-02-03 05:00:00',
    'end_date' => '2031-02-03 06:00:00',
    'timezone' => 'UTC',
    'all_day' => true,
    'organizers' => [$organizer_ids[1]],
    'venue' => (int) $venue->ID,
])->create();
$dirty_delete_probe = tribe_events()->set_args([
    'title' => 'WPrism Unsupported Delete Probe',
    'status' => 'publish',
    'description' => 'Target-only stale scheduled-state boundary.',
    'start_date' => '2031-03-04 07:00:00',
    'end_date' => '2031-03-04 08:00:00',
    'timezone' => 'UTC',
    'all_day' => true,
    'hide_from_upcoming' => true,
])->create();
if (!$dirty_all_day || !$dirty_all_day->ID || !$dirty_delete_probe || !$dirty_delete_probe->ID) {
    throw new RuntimeException('TEC did not create hostile status/deletion target rows');
}
foreach ([$dirty, $dirty_all_day, $dirty_delete_probe] as $dirty_event) {
    if (get_post_meta((int) $dirty_event->ID, '_EventAllDay', true) !== 'yes') {
        throw new RuntimeException('TEC hostile target did not retain the classic repository all-day wire');
    }
}

$status_editor = tribe(\Tribe\Events\Event_Status\Classic_Editor::class);
$status_editor->register_fields();
$status_editor->update_fields((int) $dirty->ID, [
    'status' => 'postponed',
    'status-reason' => 'Target stale main reason.',
]);
$status_editor->update_fields((int) $dirty_all_day->ID, [
    'status' => 'canceled',
    'status-reason' => 'Target stale all-day reason.',
]);
$status_editor->update_fields((int) $dirty_delete_probe->ID, [
    'status' => 'canceled',
    'status-reason' => 'Target stale reason must be deleted.',
]);

$tec_main = Tribe__Events__Main::instance();
$tec_main->link_preview_venue_to_event((int) $venue->ID, (int) $dirty->ID);
$tec_main->link_preview_organizer_to_event(
    [$organizer_ids[2], $organizer_ids[0], $organizer_ids[1]],
    (int) $dirty->ID
);

$wpdb->update(
    $wpdb->prefix . 'tec_occurrences',
    ['start_date' => '2040-01-01 01:00:00', 'end_date' => '2040-01-01 02:00:00'],
    ['post_id' => (int) $dirty->ID]
);
$wpdb->update(
    $wpdb->prefix . 'tec_events',
    ['start_date' => '2040-01-01 01:00:00', 'end_date' => '2040-01-01 02:00:00'],
    ['post_id' => (int) $dirty->ID]
);

$inserted = $wpdb->insert(
    $wpdb->prefix . 'tec_kv_cache',
    [
        'cache_key' => 'wprism-readiness-target-only',
        'value' => 'target-runtime-preserved',
        'expiration' => 4102444800,
    ]
);
if ($inserted === false) {
    throw new RuntimeException('TEC target-only cache premise could not be inserted');
}

foreach ([
    'eventsSlug' => 'target-stale-events',
    'singleEventSlug' => 'target-stale-event',
    'tribeEnableViews' => ['day'],
    'viewOption' => 'day',
    'dateWithYearFormat' => 'Y/m/d',
    'tribeEventsBeforeHTML' => '<p>target stale before</p>',
    'tribeEventsAfterHTML' => '<p>target stale after</p>',
    'defaultCurrencyCode' => 'USD',
    'eventsDefaultVenueID' => (int) $venue->ID,
    'eventsDefaultOrganizerID' => (int) $organizer->ID,
    'toggle_blocks_editor' => false,
    'debugEvents' => false,
    'enable_month_view_cache' => true,
    'trash-past-events' => 12,
    'delete-past-events' => 24,
    'multiDayCutoff' => '07:00',
    'category-color-enable-frontend' => false,
    'category-color-show-hidden-categories' => true,
    'tec_seo_out_of_range_behavior' => 'hard_404',
    'google_maps_js_api_key' => 'target-maps-key-preserved',
    'eb_security_key' => 'target-event-aggregator-secret-preserved',
] as $key => $value) {
    tribe_update_option($key, $value);
}

$cutoff_sentinel = tribe_events()->set_args([
    'title' => 'WPrism Target Local All Day Cutoff Sentinel',
    'status' => 'publish',
    'description' => 'Target-owned event must survive the hook-bypassing settings write byte-exact.',
    'start_date' => '2020-04-05 02:03:04',
    'end_date' => '2020-04-05 04:03:04',
    'timezone' => 'UTC',
    'all_day' => true,
])->create();
if (!$cutoff_sentinel || !$cutoff_sentinel->ID) {
    throw new RuntimeException('TEC target-local cutoff sentinel could not be created');
}
$cutoff_sentinel_id = (int) $cutoff_sentinel->ID;
$cutoff_sentinel_post = get_post($cutoff_sentinel_id);
if (!$cutoff_sentinel_post instanceof WP_Post) {
    throw new RuntimeException('TEC target-local cutoff sentinel post could not be read back');
}
$cutoff_sentinel_meta = [];
foreach (['_EventAllDay', '_EventStartDate', '_EventEndDate', '_EventDuration'] as $key) {
    $cutoff_sentinel_meta[$key] = get_post_meta($cutoff_sentinel_id, $key, true);
}

// WPrism's materializer does not fire TEC's wp-admin category-save hook. Keep a
// hostile generated option and a primed plugin cache so the provider must run
// both native regeneration and native cache invalidation after term-meta apply.
update_option(
    'tec_events_category_color_css',
    '.tribe_events_cat-wprism-readiness-category{--tec-color-category-primary:#000000}',
    true
);
$dropdown = tribe(
    \TEC\Events\Category_Colors\Repositories\Category_Color_Dropdown_Provider::class
)->get_dropdown_categories();
$dirty_dropdown = array_values(array_filter(
    $dropdown,
    static fn(array $row): bool => ($row['slug'] ?? '') === 'wprism-readiness-category'
));
if (count($dirty_dropdown) !== 1 || ($dirty_dropdown[0]['primary'] ?? null) !== '#000000') {
    throw new RuntimeException('TEC hostile target Category Colors cache premise did not land');
}

echo wp_json_encode([
    'category' => $category_id,
    'cutoff_sentinel' => [
        'id' => $cutoff_sentinel_id,
        'post' => [
            'post_status' => $cutoff_sentinel_post->post_status,
            'post_title' => $cutoff_sentinel_post->post_title,
            'post_type' => $cutoff_sentinel_post->post_type,
        ],
        'meta' => $cutoff_sentinel_meta,
    ],
    'all_day' => (int) $dirty_all_day->ID,
    'delete_probe' => (int) $dirty_delete_probe->ID,
    'dirty_event' => (int) $dirty->ID,
    'organizer' => (int) $organizer->ID,
    'organizers' => $organizer_ids,
    'venue' => (int) $venue->ID,
    'widget_page' => (int) $widget_page_id,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHPEOF

TARGET_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-target.php"
TARGET_IDS_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-target-ids.json"
printf '%s' "$TARGET_PHP" > "$TARGET_FILE"
TARGET_OUT=$(wp_conf2 eval-file /siterepo/.tmp-tec-target.php)
require_observed_nonempty "TEC dirty target premise" "$TARGET_OUT"
TARGET_JSON=$(printf '%s\n' "$TARGET_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$TARGET_JSON" | jq -e '
  .dirty_event >= 7000000000 and .all_day >= 7000000000 and .delete_probe >= 7000000000 and
  .cutoff_sentinel.id >= 7000000000 and
  .cutoff_sentinel.post == {
    post_status:"publish",
    post_title:"WPrism Target Local All Day Cutoff Sentinel",
    post_type:"tribe_events"
  } and .cutoff_sentinel.meta._EventAllDay == "yes" and
  .venue >= 7000000000 and .organizer >= 7000000000 and
  (.organizers | length) == 3 and (.organizers | unique | length) == 3 and
  all(.organizers[]; . >= 7000000000) and
  .category >= 7100000000 and .widget_page >= 7000000000
' >/dev/null || fail "TEC dirty target premise returned malformed or non-huge identities: $TARGET_JSON"
printf '%s\n' "$TARGET_JSON" > "$TARGET_IDS_FILE"
rm -f "$TARGET_FILE"
pass "TEC target has huge divergent identities, conflicting widget counters, reversed organizers, stale statuses/projections/settings, and target-owned runtime state"
