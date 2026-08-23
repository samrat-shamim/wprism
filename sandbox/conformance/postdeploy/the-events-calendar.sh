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

$category = wp_insert_term('Duo Readiness 東京', 'tribe_events_cat', [
    'slug' => 'duo-readiness-category',
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
    'venue' => 'Duo Readiness Hall 東京',
    'address' => 'Target-only stale address',
    'city' => 'Target stale city',
    'country' => 'Target stale country',
    'website' => home_url('/target-stale-venue/'),
])->create();
$organizer = tribe_organizers()->set_args([
    'organizer' => 'Duo Readiness Team 東京',
    'email' => 'target-stale@example.test',
    'website' => home_url('/target-stale-organizer/'),
])->create();
if (!$venue || !$venue->ID || !$organizer || !$organizer->ID) {
    throw new RuntimeException('TEC did not create hostile linked target rows');
}

$dirty = tribe_events()->set_args([
    'title' => 'Duo Production Readiness Event 東京',
    'status' => 'publish',
    'description' => 'Target-only stale event body.',
    'start_date' => '2031-01-02 03:00:00',
    'end_date' => '2031-01-02 04:00:00',
    'timezone' => 'UTC',
    'venue' => (int) $venue->ID,
    'organizer' => (int) $organizer->ID,
])->create();
if (!$dirty || !$dirty->ID) {
    throw new RuntimeException('TEC did not create the dirty same-slug target event');
}
wp_set_object_terms((int) $dirty->ID, [$category_id], 'tribe_events_cat');

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
        'cache_key' => 'duo-readiness-target-only',
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
    'category-color-enable-frontend' => false,
    'tec_seo_out_of_range_behavior' => 'hard_404',
    'google_maps_js_api_key' => 'target-maps-key-preserved',
    'eb_security_key' => 'target-event-aggregator-secret-preserved',
    'duo_target_only_runtime' => 'target-option-preserved',
] as $key => $value) {
    tribe_update_option($key, $value);
}

// Duo's materializer does not fire TEC's wp-admin category-save hook. Keep a
// hostile generated option and a primed plugin cache so the provider must run
// both native regeneration and native cache invalidation after term-meta apply.
update_option(
    'tec_events_category_color_css',
    '.tribe_events_cat-duo-readiness-category{--tec-color-category-primary:#000000}',
    true
);
$dropdown = tribe(
    \TEC\Events\Category_Colors\Repositories\Category_Color_Dropdown_Provider::class
)->get_dropdown_categories();
$dirty_dropdown = array_values(array_filter(
    $dropdown,
    static fn(array $row): bool => ($row['slug'] ?? '') === 'duo-readiness-category'
));
if (count($dirty_dropdown) !== 1 || ($dirty_dropdown[0]['primary'] ?? null) !== '#000000') {
    throw new RuntimeException('TEC hostile target Category Colors cache premise did not land');
}

echo wp_json_encode([
    'category' => $category_id,
    'dirty_event' => (int) $dirty->ID,
    'organizer' => (int) $organizer->ID,
    'venue' => (int) $venue->ID,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
PHPEOF

TARGET_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-target.php"
TARGET_IDS_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-target-ids.json"
printf '%s' "$TARGET_PHP" > "$TARGET_FILE"
TARGET_OUT=$(wp_conf2 eval-file /siterepo/.tmp-tec-target.php)
require_observed_nonempty "TEC dirty target premise" "$TARGET_OUT"
TARGET_JSON=$(printf '%s\n' "$TARGET_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$TARGET_JSON" | jq -e '
  .dirty_event >= 7000000000 and .venue >= 7000000000 and .organizer >= 7000000000 and
  .category >= 7100000000
' >/dev/null || fail "TEC dirty target premise returned malformed or non-huge identities: $TARGET_JSON"
printf '%s\n' "$TARGET_JSON" > "$TARGET_IDS_FILE"
rm -f "$TARGET_FILE"
pass "TEC target has huge divergent same-slug identities, stale projections/settings, and target-owned integration/cache state"
