#!/usr/bin/env bash
# Exact-artifact TEC acceptance beyond byte recapture: native repositories,
# hostile huge identities, mixed-option sovereignty, schema refusals,
# unsupported deletion, conflict authority, rollback/retry, concurrency, and
# deactivate/uninstall/reinstall lifecycle behavior.
set -euo pipefail
CONF1_PORT="${CONF1_PORT:-8806}"
CONF2_PORT="${CONF2_PORT:-8807}"

observe_tec() { # <conf1|conf2>
  local side="$1" repo file out
  case "$side" in
    conf1) repo="${CONF_REPO1:-siterepo/conf1}" ;;
    conf2) repo="${CONF_REPO2:-siterepo/conf2}" ;;
    *) fail "invalid TEC observation side: $side" ;;
  esac
  file="$repo/.tmp-tec-observe.php"
  read -r -d '' OBSERVE_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
$one = static function (string $type, string $title): WP_Post {
    $posts = get_posts([
        'post_type' => $type,
        'post_status' => 'any',
        'posts_per_page' => -1,
        'title' => $title,
    ]);
    if (count($posts) !== 1) {
        throw new RuntimeException("expected one $type '$title', got " . count($posts));
    }
    return $posts[0];
};
$event = $one('tribe_events', 'Duo Production Readiness Event 東京');
$all_day = $one('tribe_events', 'Duo All Day Boundary Event');
$delete_probe = $one('tribe_events', 'Duo Unsupported Delete Probe');
$venue = $one('tribe_venue', 'Duo Readiness Hall 東京');
$disabled_venue = $one('tribe_venue', 'Duo Map Disabled Venue');
$absent_map_venue = $one('tribe_venue', 'Duo Map Metadata Absent Venue');
$organizer = $one('tribe_organizer', 'Duo Readiness Team 東京');
$organizer_accessibility = $one('tribe_organizer', 'Duo Accessibility Guild বাংলা');
$organizer_night = $one('tribe_organizer', 'Duo Night Crew مرحبا');
$organizers = [$organizer, $organizer_accessibility, $organizer_night];
$category = get_term_by('slug', 'duo-readiness-category', 'tribe_events_cat');
if (!$category instanceof WP_Term) {
    throw new RuntimeException('TEC event category is missing');
}
global $wpdb;
$occurrence = $wpdb->get_row($wpdb->prepare(
    "SELECT start_date,end_date,duration,event_id,post_id FROM {$wpdb->prefix}tec_occurrences WHERE post_id=%d",
    $event->ID
), ARRAY_A);
$event_row = $wpdb->get_row($wpdb->prepare(
    "SELECT start_date,end_date,timezone,duration,event_id,post_id FROM {$wpdb->prefix}tec_events WHERE post_id=%d",
    $event->ID
), ARRAY_A);
$all_day_occurrence = $wpdb->get_row($wpdb->prepare(
    "SELECT start_date,end_date,post_id FROM {$wpdb->prefix}tec_occurrences WHERE post_id=%d",
    $all_day->ID
), ARRAY_A);
$repository_event = tribe_events()->where('id', (int) $event->ID)->first();
$cache = $wpdb->get_var($wpdb->prepare(
    "SELECT value FROM {$wpdb->prefix}tec_kv_cache WHERE cache_key=%s",
    'duo-readiness-target-only'
));
$option = (array) get_option('tribe_events_calendar_options', []);
$category_meta = [];
foreach (['primary', 'secondary', 'text', 'priority', 'hidden'] as $suffix) {
    $category_meta[$suffix] = get_term_meta($category->term_id, 'tec-events-cat-colors-' . $suffix, true);
}
$dropdown_rows = tribe(
    \TEC\Events\Category_Colors\Repositories\Category_Color_Dropdown_Provider::class
)->get_dropdown_categories();
$category_dropdown = array_values(array_filter(
    $dropdown_rows,
    static fn(array $row): bool => ($row['slug'] ?? '') === $category->slug
));
if (count($category_dropdown) !== 1) {
    throw new RuntimeException('TEC native Category Colors dropdown did not return the fixture category');
}
$editor_meta = new Tribe__Events__Editor__Meta();
$editor_meta->register();
$status_editor = tribe(\Tribe\Events\Event_Status\Classic_Editor::class);
$status_editor->register_fields();
$hidden_event_ids = array_map(
    'intval',
    tribe(\Tribe\Events\Views\V2\Query\Hide_From_Upcoming_Controller::class)->get_hidden_post_ids()
);
// Editor meta is registered globally, while Classic_Editor.php:131-146 uses
// register_post_meta('tribe_events', ...); both registries are native contract.
$registered = array_replace(
    get_registered_meta_keys('post'),
    get_registered_meta_keys('post', Tribe__Events__Main::POSTTYPE)
);
$registered_contract = [];
foreach ([
    '_EventCostDescription',
    '_EventDateTimeSeparator',
    '_EventTimeRangeSeparator',
    '_EventOrganizerID',
    '_VenueLat',
    '_VenueLng',
    '_tribe_events_status',
    '_tribe_events_status_reason',
] as $key) {
    $args = $registered[$key] ?? [];
    $callback = $args['sanitize_callback'] ?? null;
    $registered_contract[$key] = [
        'callback' => is_array($callback)
            ? get_class($callback[0]) . '::' . $callback[1]
            : (is_string($callback) ? $callback : get_debug_type($callback)),
        'rest' => $args['show_in_rest'] ?? null,
        'single' => $args['single'] ?? null,
        'type' => $args['type'] ?? null,
    ];
}
$editing_fields = apply_filters('tribe_general_settings_editing_section', [
    'disable_metabox_custom_fields' => ['type' => 'checkbox_bool'],
]);
$toggle_field = $editing_fields['toggle_blocks_editor'] ?? [];
$organizer_block_type = WP_Block_Type_Registry::get_instance()->get_registered('tribe/event-organizer');
$organizer_renderer = $organizer_block_type instanceof WP_Block_Type
    ? $organizer_block_type->render_callback
    : null;
$organizer_blocks = static function (WP_Post $post): array {
    $ids = [];
    $walk = static function (array $blocks) use (&$walk, &$ids): void {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? null) === 'tribe/event-organizer') {
                $ids[] = $block['attrs']['organizer'] ?? null;
            }
            if (!empty($block['innerBlocks'])) {
                $walk($block['innerBlocks']);
            }
        }
    };
    $walk(parse_blocks($post->post_content));
    return $ids;
};
$map_meta = static function (WP_Post $post): array {
    $values = [];
    foreach (['_EventShowMap', '_EventShowMapLink', '_VenueShowMap', '_VenueShowMapLink'] as $key) {
        $values[$key] = metadata_exists('post', $post->ID, $key)
            ? get_post_meta($post->ID, $key, true)
            : null;
    }
    return [
        'embed' => tribe_embed_google_map($post->ID),
        'link' => tribe_show_google_map_link($post->ID),
        'meta' => $values,
    ];
};
$optional_editor_meta = static function (WP_Post $post): array {
    $values = [];
    foreach (['_EventCostDescription', '_EventDateTimeSeparator', '_EventTimeRangeSeparator'] as $key) {
        $values[$key] = metadata_exists('post', $post->ID, $key)
            ? get_post_meta($post->ID, $key, true)
            : null;
    }
    return $values;
};
$rest_meta = static function (WP_Post $post): array {
    $request = new WP_REST_Request('GET', '/wp/v2/tribe_events/' . $post->ID);
    $request->set_param('context', 'edit');
    $response = rest_do_request($request);
    if ($response->get_status() !== 200) {
        throw new RuntimeException(
            "TEC core REST readback failed for {$post->ID}: " . wp_json_encode($response->get_data())
        );
    }
    $meta = (array) ($response->get_data()['meta'] ?? []);
    return [
        'cost_description' => $meta['_EventCostDescription'] ?? null,
        'date_time_separator' => $meta['_EventDateTimeSeparator'] ?? null,
        'organizers' => $meta['_EventOrganizerID'] ?? null,
        'status' => $meta['_tribe_events_status'] ?? null,
        'status_reason' => $meta['_tribe_events_status_reason'] ?? null,
        'time_range_separator' => $meta['_EventTimeRangeSeparator'] ?? null,
    ];
};
$status_meta = static function (WP_Post $post) use ($rest_meta): array {
    $model = tribe_get_event($post->ID);
    return [
        'model_reason' => $model instanceof WP_Post ? $model->event_status_reason : null,
        'model_status' => $model instanceof WP_Post ? $model->event_status : null,
        'raw_reason' => metadata_exists('post', $post->ID, '_tribe_events_status_reason')
            ? get_post_meta($post->ID, '_tribe_events_status_reason', true)
            : null,
        'raw_status' => metadata_exists('post', $post->ID, '_tribe_events_status')
            ? get_post_meta($post->ID, '_tribe_events_status', true)
            : null,
        'rest' => $rest_meta($post),
    ];
};
echo wp_json_encode([
    'all_day' => [
        'all_day' => get_post_meta($all_day->ID, '_EventAllDay', true),
        'all_day_native' => tribe_event_is_all_day($all_day->ID),
        'end' => get_post_meta($all_day->ID, '_EventEndDate', true),
        'id' => (int) $all_day->ID,
        'editor_meta' => $optional_editor_meta($all_day),
        'occurrence' => $all_day_occurrence,
        'organizer' => get_post_meta($all_day->ID, '_EventOrganizerID', true),
        'organizer_blocks' => $organizer_blocks($all_day),
        'map' => $map_meta($all_day),
        'permalink' => get_permalink($all_day),
        'start' => get_post_meta($all_day->ID, '_EventStartDate', true),
        'status' => $status_meta($all_day),
        'hide_from_upcoming' => metadata_exists('post', $all_day->ID, '_EventHideFromUpcoming')
            ? get_post_meta($all_day->ID, '_EventHideFromUpcoming', true)
            : null,
        'hidden_native' => in_array(
            (int) $all_day->ID,
            $hidden_event_ids,
            true
        ),
        'venue' => get_post_meta($all_day->ID, '_EventVenueID', true),
    ],
    'cache' => $cache,
    'category' => [
        'description' => $category->description,
        'dropdown' => $category_dropdown[0],
        'id' => (int) $category->term_id,
        'meta' => $category_meta,
    ],
    'category_css' => get_option('tec_events_category_color_css', null),
    'delete_probe' => [
        'all_day' => metadata_exists('post', $delete_probe->ID, '_EventAllDay')
            ? get_post_meta($delete_probe->ID, '_EventAllDay', true)
            : null,
        'all_day_native' => tribe_event_is_all_day($delete_probe->ID),
        'hide_from_upcoming' => metadata_exists('post', $delete_probe->ID, '_EventHideFromUpcoming')
            ? get_post_meta($delete_probe->ID, '_EventHideFromUpcoming', true)
            : null,
        'hidden_native' => in_array((int) $delete_probe->ID, $hidden_event_ids, true),
        'id' => (int) $delete_probe->ID,
        'organizer_blocks' => $organizer_blocks($delete_probe),
        'permalink' => get_permalink($delete_probe),
        'status' => $status_meta($delete_probe),
    ],
    'delete_probe_map' => $map_meta($delete_probe),
    'editor_meta_contract' => $registered_contract,
    'editor_native_contract' => [
        'block' => [
            'registered' => $organizer_block_type instanceof WP_Block_Type,
            'renderer' => is_array($organizer_renderer)
                ? get_class($organizer_renderer[0]) . '::' . $organizer_renderer[1]
                : get_debug_type($organizer_renderer),
        ],
        'setting' => [
            'default' => $toggle_field['default'] ?? null,
            'key' => Tribe__Events__Editor__Compatibility::$blocks_editor_key,
            'runtime' => tribe('events.editor.compatibility')->is_blocks_editor_toggled_on(),
            'type' => $toggle_field['type'] ?? null,
            'validation' => $toggle_field['validation_type'] ?? null,
        ],
    ],
    'event' => [
        'category_ids' => array_map('intval', wp_get_post_terms($event->ID, 'tribe_events_cat', ['fields' => 'ids'])),
        'content' => $event->post_content,
        'cost' => get_post_meta($event->ID, '_EventCost', true),
        'cost_description' => get_post_meta($event->ID, '_EventCostDescription', true),
        'currency_code' => get_post_meta($event->ID, '_EventCurrencyCode', true),
        'currency_position' => get_post_meta($event->ID, '_EventCurrencyPosition', true),
        'currency_symbol' => get_post_meta($event->ID, '_EventCurrencySymbol', true),
        'end' => get_post_meta($event->ID, '_EventEndDate', true),
        'featured' => get_post_meta($event->ID, '_tribe_featured', true),
        'id' => (int) $event->ID,
        'all_day' => metadata_exists('post', $event->ID, '_EventAllDay')
            ? get_post_meta($event->ID, '_EventAllDay', true)
            : null,
        'all_day_native' => tribe_event_is_all_day($event->ID),
        'hide_from_upcoming' => metadata_exists('post', $event->ID, '_EventHideFromUpcoming')
            ? get_post_meta($event->ID, '_EventHideFromUpcoming', true)
            : null,
        'hidden_native' => in_array((int) $event->ID, $hidden_event_ids, true),
        'occurrence' => $occurrence,
        'map' => $map_meta($event),
        'organizer' => (int) get_post_meta($event->ID, '_EventOrganizerID', true),
        'organizer_helper' => array_map('intval', tribe_get_organizer_ids($event->ID)),
        'organizer_names' => array_map(
            static fn($id): string => get_the_title((int) $id),
            tribe_get_organizer_ids($event->ID)
        ),
        'organizer_blocks' => $organizer_blocks($event),
        'organizer_rows' => array_map('intval', get_post_meta($event->ID, '_EventOrganizerID', false)),
        'permalink' => get_permalink($event),
        'phone' => get_post_meta($event->ID, '_EventPhone', true),
        'repository_id' => $repository_event ? (int) $repository_event->ID : 0,
        'row' => $event_row,
        'start' => get_post_meta($event->ID, '_EventStartDate', true),
        'tags' => wp_get_post_terms($event->ID, 'post_tag', ['fields' => 'names']),
        'timezone' => get_post_meta($event->ID, '_EventTimezone', true),
        'preview_organizers' => get_post_meta($event->ID, '_preview_organizers', true),
        'preview_venues' => get_post_meta($event->ID, '_preview_venues', true),
        'rest' => $rest_meta($event),
        'status' => $status_meta($event),
        'date_time_separator' => get_post_meta($event->ID, '_EventDateTimeSeparator', true),
        'time_range_separator' => get_post_meta($event->ID, '_EventTimeRangeSeparator', true),
        'url' => get_post_meta($event->ID, '_EventURL', true),
        'venue' => (int) get_post_meta($event->ID, '_EventVenueID', true),
    ],
    'home' => home_url('/'),
    'map_boundary_venues' => [
        'absent' => [
            'id' => (int) $absent_map_venue->ID,
            'map' => $map_meta($absent_map_venue),
        ],
        'disabled' => [
            'id' => (int) $disabled_venue->ID,
            'map' => $map_meta($disabled_venue),
        ],
    ],
    'organizer' => [
        'email' => get_post_meta($organizer->ID, '_OrganizerEmail', true),
        'id' => (int) $organizer->ID,
        'phone' => get_post_meta($organizer->ID, '_OrganizerPhone', true),
        'website' => get_post_meta($organizer->ID, '_OrganizerWebsite', true),
    ],
    'organizers' => array_map(static function (WP_Post $post): array {
        return [
            'email' => get_post_meta($post->ID, '_OrganizerEmail', true),
            'id' => (int) $post->ID,
            'name' => $post->post_title,
            'website' => get_post_meta($post->ID, '_OrganizerWebsite', true),
        ];
    }, $organizers),
    'options' => [
        'after' => $option['tribeEventsAfterHTML'] ?? null,
        'before' => $option['tribeEventsBeforeHTML'] ?? null,
        'blocks_editor' => $option['toggle_blocks_editor'] ?? null,
        'category_frontend' => $option['category-color-enable-frontend'] ?? null,
        'category_show_hidden' => $option['category-color-show-hidden-categories'] ?? null,
        'currency_code' => $option['defaultCurrencyCode'] ?? null,
        'default_organizer' => (int) ($option['eventsDefaultOrganizerID'] ?? 0),
        'default_venue' => (int) ($option['eventsDefaultVenueID'] ?? 0),
        'debug' => $option['debugEvents'] ?? null,
        'delete_past' => $option['delete-past-events'] ?? null,
        'eb_secret' => $option['eb_security_key'] ?? null,
        'month_cache' => $option['enable_month_view_cache'] ?? null,
        'events_slug' => $option['eventsSlug'] ?? null,
        'maps_key' => $option['google_maps_js_api_key'] ?? null,
        'seo_behavior' => $option['tec_seo_out_of_range_behavior'] ?? null,
        'single_slug' => $option['singleEventSlug'] ?? null,
        'source_only' => $option['duo_source_only_secret'] ?? null,
        'target_only' => $option['duo_target_only_runtime'] ?? null,
        'timezone_mode' => $option['tribe_events_timezone_mode'] ?? null,
        'trash_past' => $option['trash-past-events'] ?? null,
        'views' => $option['tribeEnableViews'] ?? null,
    ],
    'venue' => [
        'address' => get_post_meta($venue->ID, '_VenueAddress', true),
        'city' => get_post_meta($venue->ID, '_VenueCity', true),
        'country' => get_post_meta($venue->ID, '_VenueCountry', true),
        'id' => (int) $venue->ID,
        'coordinates' => tribe_get_coordinates($venue->ID),
        'map' => $map_meta($venue),
        'phone' => get_post_meta($venue->ID, '_VenuePhone', true),
        'province' => get_post_meta($venue->ID, '_VenueProvince', true),
        'state_province' => get_post_meta($venue->ID, '_VenueStateProvince', true),
        'website' => get_post_meta($venue->ID, '_VenueURL', true),
    ],
    'version' => defined('Tribe__Events__Main::VERSION') ? Tribe__Events__Main::VERSION : null,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
PHPEOF
  printf '%s' "$OBSERVE_PHP" > "$file"
  if [ "$side" = conf1 ]; then
    out=$(wp_conf1 eval-file /siterepo/.tmp-tec-observe.php)
  else
    out=$(wp_conf2 eval-file /siterepo/.tmp-tec-observe.php)
  fi
  rm -f "$file"
  require_observed_nonempty "$side The Events Calendar native observation" "$out"
  printf '%s\n' "$out" | awk 'NF { line=$0 } END { print line }'
}

commit_tec_source() { # <message>
  wp_conf1 duo capture --repo=/siterepo >/dev/null
  git -C "$CONF_REPO1" add -A
  git -C "$CONF_REPO1" -c user.name=duo -c user.email=duo@example.test commit -qm "$1"
  git -C "$CONF_REPO1" push -q origin main
  git -C "$CONF_REPO2" pull -q origin main
}

tec_target_hash() {
  wp_conf2 eval '
    global $wpdb;
    $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0]??null;
    if(!$p) throw new RuntimeException("TEC hash event missing");
    $term=get_term_by("slug","duo-readiness-category","tribe_events_cat");
    $option=(array)get_option("tribe_events_calendar_options",[]);
    $selected=[]; foreach(["eventsSlug","singleEventSlug","tribeEnableViews","viewOption","eventsDefaultVenueID","eventsDefaultOrganizerID","google_maps_js_api_key","eb_security_key","duo_target_only_runtime"] as $k){$selected[$k]=$option[$k]??null;}
    $rows=[
      "post"=>$wpdb->get_row($wpdb->prepare("SELECT ID,post_title,post_name,post_status,post_content FROM {$wpdb->posts} WHERE ID=%d",$p->ID),ARRAY_A),
      "meta"=>$wpdb->get_results($wpdb->prepare("SELECT meta_key,meta_value FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_key,meta_id",$p->ID),ARRAY_A),
      "event"=>$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tec_events WHERE post_id=%d",$p->ID),ARRAY_A),
      "occurrence"=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tec_occurrences WHERE post_id=%d ORDER BY occurrence_id",$p->ID),ARRAY_A),
      "term"=>$term ? [$term->term_id,$term->name,$term->slug,$term->description,get_term_meta($term->term_id)] : null,
      "category_css"=>get_option("tec_events_category_color_css",null),
      "option"=>$selected,
    ];
    echo hash("sha256",wp_json_encode($rows,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
  '
}

SOURCE_IDS_FILE="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-source-ids.json"
TARGET_IDS_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-target-ids.json"
[ -f "$SOURCE_IDS_FILE" ] || fail "TEC source identity premise is missing: $SOURCE_IDS_FILE"
[ -f "$TARGET_IDS_FILE" ] || fail "TEC target identity premise is missing: $TARGET_IDS_FILE"
SOURCE_IDS=$(cat "$SOURCE_IDS_FILE")
TARGET_IDS=$(cat "$TARGET_IDS_FILE")
TEC_SOURCE_EVENT_STATE=$(grep -RlF '"title": "Duo Production Readiness Event 東京"' \
  "${CONF_REPO1:-siterepo/conf1}/state/posts/tribe_events" || true)
[ -n "$TEC_SOURCE_EVENT_STATE" ] && [ "$(printf '%s\n' "$TEC_SOURCE_EVENT_STATE" | wc -l | tr -d ' ')" = 1 ] \
  || fail "TEC canonical source event was not unique: $TEC_SOURCE_EVENT_STATE"
TEC_SOURCE_EVENT_JSON=$(awk '
  NR == 1 && $0 == "---" { front = 1; next }
  front && $0 == "---" { exit }
  front { print }
' "$TEC_SOURCE_EVENT_STATE")
printf '%s\n' "$TEC_SOURCE_EVENT_JSON" | jq -e '
  (.meta._EventOrganizerID | type) == "array" and
  (.meta._EventOrganizerID | length) == 3 and
  (.meta._EventOrganizerID | unique | length) == 3 and
  all(.meta._EventOrganizerID[]; test("^\\{\\{post:[0-9a-f-]{36}\\}\\}$")) and
  .meta._tribe_events_status == "canceled" and
  .meta._tribe_events_status_reason == "Weather <strong>closure</strong> 東京 — doors remain shut." and
  (.meta | has("_preview_organizers") | not) and
  (.meta | has("_preview_venues") | not)
' >/dev/null || fail "TEC canonical source did not carry exact ordered organizers/status or retained runtime previews"
TEC_CANON_ORGANIZER_BLOCKS=$(TEC_STATE_PATH="$TEC_SOURCE_EVENT_STATE" php -r '
  require "agent/src/Kernel/Canon.php";
  require "sandbox/tests/support/wp-block-parser-stub.php";
  [, $body] = Duo\Canon::parse_post_file((string) file_get_contents((string) getenv("TEC_STATE_PATH")));
  $ids = [];
  $walk = static function (array $blocks) use (&$walk, &$ids): void {
      foreach ($blocks as $block) {
          if (($block["blockName"] ?? null) === "tribe/event-organizer") {
              $ids[] = $block["attrs"]["organizer"] ?? null;
          }
          if (!empty($block["innerBlocks"])) {
              $walk($block["innerBlocks"]);
          }
      }
  };
  $walk(parse_blocks($body));
  echo json_encode($ids, JSON_UNESCAPED_SLASHES);
')
printf '%s\n' "$TEC_CANON_ORGANIZER_BLOCKS" | jq -e --argjson front "$TEC_SOURCE_EVENT_JSON" '
  . == $front.meta._EventOrganizerID and
  length == 3 and all(.[]; test("^\\{\\{post:[0-9a-f-]{36}\\}\\}$"))
' >/dev/null || fail "TEC canonical organizer blocks did not retain exact ordered post tokens: $TEC_CANON_ORGANIZER_BLOCKS"
TEC_SOURCE_OPTIONS="${CONF_REPO1:-siterepo/conf1}/state/options/core.json"
jq -e '
  .records.tribe_events_calendar_options.value as $o |
  ($o | has("debugEvents") | not) and
  ($o | has("enable_month_view_cache") | not) and
  ($o | has("trash-past-events") | not) and
  ($o | has("delete-past-events") | not) and
  ($o | has("google_maps_js_api_key") | not) and
  ($o | has("duo_source_only_secret") | not)
' "$TEC_SOURCE_OPTIONS" >/dev/null \
  || fail "TEC canonical mixed option captured an operational/secret target-owned sibling"
pass "canonical TEC state preserves ordered organizer rows and status while excluding preview, operational, and secret state"
SOURCE=$(observe_tec conf1)
TARGET=$(observe_tec conf2)
TEC_EXPECTED_VERSION="${TEC_EXPECTED_VERSION:-6.17.3}"

printf '%s\n' "$SOURCE" | jq -e '
  .event.all_day == null and .event.all_day_native == false and
  .event.hide_from_upcoming == null and .event.hidden_native == false and
  .event.organizer_blocks == (.organizers | map(.id)) and
  .all_day.organizer_blocks == [null] and .delete_probe.organizer_blocks == [] and
  .options.blocks_editor == true and
  .editor_native_contract == {
    block:{registered:true,renderer:"Tribe__Events__Editor__Blocks__Event_Organizer::render"},
    setting:{default:false,key:"toggle_blocks_editor",runtime:true,type:"checkbox_bool",validation:"boolean"}
  } and
  .all_day.all_day == "1" and .all_day.all_day_native == true and
  .all_day.hide_from_upcoming == "yes" and .all_day.hidden_native == true and
  .delete_probe.all_day == "" and .delete_probe.all_day_native == false and
  .delete_probe.hide_from_upcoming == null and .delete_probe.hidden_native == false
' >/dev/null || fail "TEC source did not expose exact repository/Gutenberg all-day and visibility wires: $SOURCE"

printf '%s\n' "$TARGET" | jq -e \
  --arg version "$TEC_EXPECTED_VERSION" \
  --argjson source "$SOURCE_IDS" \
  --argjson dirty "$TARGET_IDS" '
  .home as $home |
  .version == $version and
  .event.id == $dirty.dirty_event and .all_day.id == $dirty.all_day and
  .delete_probe.id == $dirty.delete_probe and .venue.id == $dirty.venue and
  .organizer.id == $dirty.organizer and .category.id == $dirty.category and
  .map_boundary_venues.disabled.id != $source.disabled_venue and
  .map_boundary_venues.absent.id != $source.absent_map_venue and
  .event.id != $source.event and .venue.id != $source.venue and
  .organizer.id != $source.organizer and .category.id != $source.category and
  .event.id >= 7000000000 and .all_day.id >= 7000000000 and .delete_probe.id >= 7000000000 and
  .category.id >= 7100000000 and
  .map_boundary_venues.disabled.id >= 7000000000 and .map_boundary_venues.absent.id >= 7000000000 and
  .event.repository_id == .event.id and .event.venue == .venue.id and
  (.organizers | map(.id)) == $dirty.organizers and
  .event.organizer == .organizer.id and .event.organizer_rows == ($dirty.organizers) and
  .event.organizer_blocks == ($dirty.organizers) and
  .event.organizer_helper == ($dirty.organizers) and .event.rest.organizers == ($dirty.organizers) and
  .event.organizer_names == ["Duo Readiness Team 東京","Duo Accessibility Guild বাংলা","Duo Night Crew مرحبا"] and
  .event.preview_organizers == [$dirty.organizers[2],$dirty.organizers[0],$dirty.organizers[1]] and
  .event.preview_venues == [$dirty.venue] and .event.category_ids == [.category.id] and
  .event.start == "2026-09-05 22:30:00" and .event.end == "2026-09-06 01:45:00" and
  .event.timezone == "Asia/Kathmandu" and .event.cost == "125.50" and
  .event.cost_description == "Admission details 東京 — bring ID" and
  .event.date_time_separator == " · at · " and .event.time_range_separator == " · until · " and
  .event.rest.cost_description == .event.cost_description and
  .event.rest.date_time_separator == .event.date_time_separator and
  .event.rest.time_range_separator == .event.time_range_separator and
  .event.status == {
    model_reason:"Weather <strong>closure</strong> 東京 — doors remain shut.",
    model_status:"canceled",
    raw_reason:"Weather <strong>closure</strong> 東京 — doors remain shut.",
    raw_status:"canceled",
    rest:{
      cost_description:"Admission details 東京 — bring ID",
      date_time_separator:" · at · ",
      organizers:$dirty.organizers,
      status:"canceled",
      status_reason:"Weather <strong>closure</strong> 東京 — doors remain shut.",
      time_range_separator:" · until · "
    }
  } and
  .event.currency_code == "NPR" and .event.currency_position == "postfix" and
  .event.currency_symbol == "रु" and .event.featured == "1" and
  .event.phone == "+977-555-0199" and
  .event.map == {embed:true,link:true,meta:{_EventShowMap:"1",_EventShowMapLink:"1",_VenueShowMap:null,_VenueShowMapLink:null}} and
  .event.occurrence.start_date == .event.start and .event.occurrence.end_date == .event.end and
  .event.row.start_date == .event.start and .event.row.end_date == .event.end and
  .event.row.timezone == .event.timezone and
  (.event.content | length > 25000) and (.event.content | contains("বাংলা")) and
  (.event.content | contains($home)) and (.event.url | startswith($home)) and
  .venue.city == "Kathmandu" and .venue.country == "Nepal" and .venue.coordinates == {lat:0,lng:0} and
  .venue.map == {embed:true,link:true,meta:{_EventShowMap:null,_EventShowMapLink:null,_VenueShowMap:"1",_VenueShowMapLink:"1"}} and
  .map_boundary_venues.disabled.map == {embed:false,link:false,meta:{_EventShowMap:"false",_EventShowMapLink:"false",_VenueShowMap:"false",_VenueShowMapLink:"false"}} and
  .map_boundary_venues.absent.map == {embed:false,link:false,meta:{_EventShowMap:null,_EventShowMapLink:null,_VenueShowMap:null,_VenueShowMapLink:null}} and
  (.venue.address | contains("ভবন ৭")) and (.venue.website | startswith($home)) and
  .organizer.email == "events@example.test" and (.organizer.website | startswith($home)) and
  (.organizers | map(.email)) == ["events@example.test","accessibility@example.test","night@example.test"] and
  all(.organizers[]; (.website | startswith($home))) and
  .category.description == "Portable category description — বাংলা — مرحبا" and
  .category.meta == {primary:"#123abc",secondary:"#fedcba",text:"#ffffff",priority:"17",hidden:"0"} and
  .category.dropdown.primary == "#123abc" and .category.dropdown.slug == "duo-readiness-category" and
  (.category_css | type == "string") and
  (.category_css | contains(".tribe_events_cat-duo-readiness-category{")) and
  (.category_css | contains("--tec-color-category-primary:#123abc")) and
  (.category_css | contains("--tec-color-category-secondary:#fedcba")) and
  (.category_css | contains("--tec-color-category-text:#ffffff")) and
  .event.all_day == null and .event.all_day_native == false and
  .event.hide_from_upcoming == null and .event.hidden_native == false and
  .all_day.all_day == "1" and .all_day.all_day_native == true and
  .all_day.hide_from_upcoming == "yes" and .all_day.hidden_native == true and
  .all_day.venue == "" and .all_day.organizer == "" and
  .all_day.organizer_blocks == [null] and .delete_probe.organizer_blocks == [] and
  .all_day.status.raw_status == "postponed" and .all_day.status.model_status == "postponed" and
  .all_day.status.raw_reason == "" and .all_day.status.model_reason == "" and
  .all_day.status.rest.status == "postponed" and .all_day.status.rest.status_reason == "" and
  .all_day.editor_meta == {_EventCostDescription:null,_EventDateTimeSeparator:null,_EventTimeRangeSeparator:null} and
  .all_day.map == {embed:false,link:false,meta:{_EventShowMap:"",_EventShowMapLink:"",_VenueShowMap:null,_VenueShowMapLink:null}} and
  .delete_probe_map == {embed:false,link:false,meta:{_EventShowMap:null,_EventShowMapLink:null,_VenueShowMap:null,_VenueShowMapLink:null}} and
  .delete_probe.all_day == "" and .delete_probe.all_day_native == false and
  .delete_probe.hide_from_upcoming == null and .delete_probe.hidden_native == false and
  .delete_probe.status.raw_status == null and .delete_probe.status.raw_reason == null and
  .delete_probe.status.model_status == "" and .delete_probe.status.model_reason == "" and
  .delete_probe.status.rest.status == "" and .delete_probe.status.rest.status_reason == "" and
  .all_day.occurrence.start_date == .all_day.start and .all_day.occurrence.end_date == .all_day.end and
  .editor_meta_contract == {
    _EventCostDescription:{callback:"sanitize_text_field",rest:true,single:true,type:"string"},
    _EventDateTimeSeparator:{callback:"Tribe__Events__Editor__Meta::sanitize_separator",rest:true,single:true,type:"string"},
    _EventTimeRangeSeparator:{callback:"Tribe__Events__Editor__Meta::sanitize_separator",rest:true,single:true,type:"string"},
    _EventOrganizerID:{callback:"Tribe__Events__Editor__Meta::sanitize_numeric_array",rest:true,single:false,type:"number"},
    _VenueLat:{callback:"sanitize_text_field",rest:true,single:true,type:"string"},
    _VenueLng:{callback:"sanitize_text_field",rest:true,single:true,type:"string"},
    _tribe_events_status:{callback:"null",rest:true,single:true,type:"string"},
    _tribe_events_status_reason:{callback:"null",rest:true,single:true,type:"string"}
  } and
  .editor_native_contract == {
    block:{registered:true,renderer:"Tribe__Events__Editor__Blocks__Event_Organizer::render"},
    setting:{default:false,key:"toggle_blocks_editor",runtime:true,type:"checkbox_bool",validation:"boolean"}
  } and
  .options.events_slug == "calendar-readiness" and .options.single_slug == "readiness-event" and
  .options.views == ["list","month"] and .options.currency_code == "NPR" and
  .options.default_venue == .venue.id and .options.default_organizer == .organizer.id and
  .options.category_frontend == true and .options.seo_behavior == "soft_noindex" and
  .options.category_show_hidden == false and .options.blocks_editor == true and
  .options.timezone_mode == "event" and
  .options.debug == false and .options.month_cache == true and
  .options.trash_past == 12 and .options.delete_past == 24 and
  .options.maps_key == "target-maps-key-preserved" and
  .options.eb_secret == "target-event-aggregator-secret-preserved" and
  .options.target_only == "target-option-preserved" and .options.source_only == null and
  .cache == "target-runtime-preserved" and
  (.event.permalink | contains("/readiness-event/"))
' >/dev/null || fail "TEC native graph/settings/derived state did not converge: $TARGET"
pass "TEC adopted huge native identities, rewrote refs/URLs/defaults, repaired projections, and preserved target-owned state"

PERMALINK=$(jq -er '.event.permalink' <<<"$TARGET")
FRONT=$(curl -fsSL "$PERMALINK") || fail "TEC target event permalink did not return 200: $PERMALINK"
require_observed_nonempty "TEC target event response" "$FRONT"
[ "${#FRONT}" -ge 20000 ] || fail "TEC target event response was suspiciously short (${#FRONT} bytes)"
grep -qF 'Duo Production Readiness Event 東京' <<<"$FRONT" || fail "TEC target event response lost the title"
grep -qF 'Portable long event body' <<<"$FRONT" || fail "TEC target event response lost the long body"
grep -qF 'Admission details 東京 — bring ID' <<<"$FRONT" \
  || fail "TEC target event response lost the Gutenberg price description"
grep -qF '· at ·' <<<"$FRONT" || fail "TEC target event response lost the date/time separator"
grep -qF '· until ·' <<<"$FRONT" || fail "TEC target event response lost the time-range separator"
for organizer_name in 'Duo Readiness Team 東京' 'Duo Accessibility Guild বাংলা' 'Duo Night Crew مرحبا'; do
  grep -qF "$organizer_name" <<<"$FRONT" \
    || fail "TEC target event response lost organizer: $organizer_name"
done
grep -qF 'tribe-events-status-single--canceled' <<<"$FRONT" \
  || fail "TEC target event response did not render the native canceled status"
grep -qF 'Weather <strong>closure</strong> 東京 — doors remain shut.' <<<"$FRONT" \
  || fail "TEC target event response did not render the kses-preserved canceled reason"
! grep -qF '.tribe_events_cat-duo-readiness-category{' <<<"$FRONT" \
  || fail "TEC singular event unexpectedly enqueued archive-only Category Colors CSS"

ALL_DAY_PERMALINK=$(jq -er '.all_day.permalink' <<<"$TARGET")
ALL_DAY_FRONT=$(curl -fsSL "$ALL_DAY_PERMALINK") \
  || fail "TEC target postponed event permalink did not return 200: $ALL_DAY_PERMALINK"
grep -qF 'tribe-events-status-single--postponed' <<<"$ALL_DAY_FRONT" \
  || fail "TEC target all-day event did not render the native postponed status"
! grep -qF 'Target stale all-day reason.' <<<"$ALL_DAY_FRONT" \
  || fail "TEC target postponed event retained its stale status reason"

DELETE_PROBE_PERMALINK=$(jq -er '.delete_probe.permalink' <<<"$TARGET")
DELETE_PROBE_FRONT=$(curl -fsSL "$DELETE_PROBE_PERMALINK") \
  || fail "TEC scheduled-as-absence event permalink did not return 200: $DELETE_PROBE_PERMALINK"
! grep -qF 'tribe-events-status-single-notice' <<<"$DELETE_PROBE_FRONT" \
  || fail "TEC scheduled-as-absence event rendered a stale status notice"
! grep -qF 'Target stale reason must be deleted.' <<<"$DELETE_PROBE_FRONT" \
  || fail "TEC scheduled-as-absence event retained its stale status reason"
ARCHIVE=$(curl -fsSL "http://localhost:${CONF2_PORT}/calendar-readiness/") \
  || fail "TEC authored archive slug did not resolve after rewrite repair"
grep -qF 'Readiness before 東京' <<<"$ARCHIVE" || fail "TEC archive lost authored before HTML"
grep -qF 'Readiness after বাংলা' <<<"$ARCHIVE" || fail "TEC archive lost authored after HTML"
grep -qF '.tribe_events_cat-duo-readiness-category{' <<<"$ARCHIVE" \
  || fail "TEC archive did not enqueue the native Category Colors selector"
grep -qF '#123abc' <<<"$ARCHIVE" || fail "TEC archive did not carry the authored primary category color"
pass "native Gutenberg editor meta, ordered organizers, canceled/postponed/scheduled statuses, and Category Colors render exactly"

if [ "${TEC_BOUNDARY_ONLY:-0}" = 1 ]; then
  pass "TEC exact-boundary native round trip is clean"
  return 0 2>/dev/null || exit 0
fi

# Category metadata commits before required actions (the same recovery boundary
# core rewrite, Elementor, and Yoast conformance exercise). Reject the provider
# option write after that commit, then prove the revision stays unapplied and a
# retry consumes the retained intent while repairing CSS + plugin cache.
wp_conf1 eval '
  $term=get_term_by("slug","duo-readiness-category","tribe_events_cat");
  if(!$term instanceof WP_Term) throw new RuntimeException("TEC source category disappeared");
  tribe(\TEC\Events\Category_Colors\Event_Category_Meta::class)
    ->set_term((int)$term->term_id)
    ->set("tec-events-cat-colors-primary","#654321")
    ->save();
  tribe(\TEC\Events\Category_Colors\CSS\Controller::class)->generate_css();
  $css=get_option("tec_events_category_color_css","");
  if(!is_string($css)||!str_contains($css,"#654321")) throw new RuntimeException("TEC source CSS update failed");
' >/dev/null
commit_tec_source 'conformance: native TEC Category Colors intent'
COLOR_FAULT_BEFORE=$(observe_tec conf2)
printf '%s\n' "$COLOR_FAULT_BEFORE" | jq -e '
  .category.meta.primary == "#123abc" and .category.dropdown.primary == "#123abc" and
  (.category_css | contains("--tec-color-category-primary:#123abc"))
' >/dev/null || fail "TEC Category Colors failure premise is not at the prior projection: $COLOR_FAULT_BEFORE"
COLOR_FAULT_REV_BEFORE=$(wp_conf2 db query "SELECT v FROM wp_duo_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty "TEC applied revision before Category Colors fault" "$COLOR_FAULT_REV_BEFORE"
wp_conf2 db query 'ALTER TABLE wp_options DROP CONSTRAINT IF EXISTS duo_tec_fail_category_css' >/dev/null
wp_conf2 db query '
  ALTER TABLE wp_options ADD CONSTRAINT duo_tec_fail_category_css
  CHECK (option_name <> "tec_events_category_color_css" OR option_value NOT LIKE "%#654321%")
' >/dev/null
COLOR_FAULT_RC=0
COLOR_FAULT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || COLOR_FAULT_RC=$?
require_duo_answered "TEC injected Category Colors provider failure" human "$COLOR_FAULT_OUT"
[ "$COLOR_FAULT_RC" -ne 0 ] \
  && grep -Fq "required manifest action 'provider:the-events-calendar-category-colors/regenerate_css' failed" <<<"$COLOR_FAULT_OUT" \
  && grep -Fq "provider 'the-events-calendar-category-colors' capability 'regenerate_css' failed" <<<"$COLOR_FAULT_OUT" \
  || fail "TEC injected Category Colors option failure did not surface through the provider: $COLOR_FAULT_OUT"
COLOR_FAULT_AFTER=$(observe_tec conf2)
COLOR_FAULT_EXPECTED=$(printf '%s\n' "$COLOR_FAULT_BEFORE" | jq -Sc '
  .category.meta.primary = "#654321" | .category.dropdown.primary = "#654321"
')
[ "$(printf '%s\n' "$COLOR_FAULT_AFTER" | jq -Sc .)" = "$COLOR_FAULT_EXPECTED" ] \
  || fail "TEC failed Category Colors provider action crossed its post-commit intent/CSS boundary: $COLOR_FAULT_AFTER"
[ "$(wp_conf2 db query "SELECT v FROM wp_duo_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')" = "$COLOR_FAULT_REV_BEFORE" ] \
  || fail "TEC failed Category Colors provider action advanced applied_revision"
[ "$(wp_conf2 eval 'echo null === \Duo\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = retained ] \
  || fail "TEC failed Category Colors provider action did not retain retry authority"
wp_conf2 db query 'ALTER TABLE wp_options DROP CONSTRAINT duo_tec_fail_category_css' >/dev/null
COLOR_RETRY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC Category Colors retry" json "$COLOR_RETRY"
jq -e '.canary == "clean" and .verification.result == "pass" and .applied >= 1' <<<"$COLOR_RETRY" >/dev/null \
  || fail "TEC Category Colors retry did not converge: $COLOR_RETRY"
[ "$(wp_conf2 eval 'echo null === \Duo\Ledger::kv_get("apply_in_progress") ? "cleared" : "retained";')" = cleared ] \
  || fail "TEC successful Category Colors retry retained apply_in_progress"
COLOR_RECOVERED=$(observe_tec conf2)
printf '%s\n' "$COLOR_RECOVERED" | jq -e '
  .category.meta.primary == "#654321" and .category.dropdown.primary == "#654321" and
  (.category_css | contains("--tec-color-category-primary:#654321")) and
  (.category_css | contains("--tec-color-category-secondary:#fedcba"))
' >/dev/null || fail "TEC Category Colors retry did not repair native CSS/dropdown projections: $COLOR_RECOVERED"
pass "native Category Colors option failure retains post-commit intent and retries CSS/cache repair cleanly"

# Capture-time schema/secret probes restore exact live bytes. Post bodies may
# legitimately discuss credentials, while the same token in authored TEC meta
# is a blocking leak; both paths must redact the public diagnostic.
SCHEMA_BACKUP="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-schema-backup.json"
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  file_put_contents("/siterepo/.tmp-tec-schema-backup.json",wp_json_encode([
    "content"=>$p->post_content,
    "start"=>get_post_meta($p->ID,"_EventStartDate",true),
    "cost"=>get_post_meta($p->ID,"_EventCost",true),
    "organizers"=>get_post_meta($p->ID,"_EventOrganizerID",false),
    "status"=>get_post_meta($p->ID,"_tribe_events_status",true),
    "status_reason"=>get_post_meta($p->ID,"_tribe_events_status_reason",true),
  ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
' >/dev/null

FAKE_SECRET='AKIAABCDEFGHIJKLMNOP'
BODY_WARNING_DIR="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-body-warning"
rm -rf "$BODY_WARNING_DIR"
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  wp_update_post(["ID"=>$p->ID,"post_content"=>"AKIAABCDEFGHIJKLMNOP"]);
' >/dev/null
BEFORE_STATUS=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
SECRET_RC=0
SECRET_OUT=$(wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-tec-body-warning 2>&1) || SECRET_RC=$?
[ "$SECRET_RC" -eq 0 ] \
  && grep -Fq 'looks like it contains a aws key' <<<"$SECRET_OUT" \
  && grep -Fq 'not blocked: bodies may legitimately discuss credentials' <<<"$SECRET_OUT" \
  && ! grep -Fq "$FAKE_SECRET" <<<"$SECRET_OUT" \
  && grep -RFl "$FAKE_SECRET" "$BODY_WARNING_DIR/posts/tribe_events" >/dev/null \
  || fail "TEC credential-shaped body did not capture with a redacted warning: $SECRET_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$BEFORE_STATUS" ] \
  || fail "TEC body-warning probe changed the committed repository"
rm -rf "$BODY_WARNING_DIR"
wp_conf1 eval '
  $b=json_decode(file_get_contents("/siterepo/.tmp-tec-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  wp_update_post(["ID"=>$p->ID,"post_content"=>$b["content"]]);
' >/dev/null

META_SECRET_DIR="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-meta-secret"
rm -rf "$META_SECRET_DIR"
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_EventCost","AKIAABCDEFGHIJKLMNOP");
' >/dev/null
SECRET_RC=0
SECRET_OUT=$(wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-tec-meta-secret 2>&1) || SECRET_RC=$?
require_duo_answered "TEC credential-shaped authored meta capture" human "$SECRET_OUT"
[ "$SECRET_RC" -ne 0 ] \
  && grep -Fq 'secret guard tripped' <<<"$SECRET_OUT" \
  && grep -Fq "post_meta '_EventCost'" <<<"$SECRET_OUT" \
  && ! grep -Fq "$FAKE_SECRET" <<<"$SECRET_OUT" \
  || fail "TEC credential-shaped authored meta did not refuse with redaction: $SECRET_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$BEFORE_STATUS" ] \
  || fail "TEC authored-meta secret refusal partially published state"
[ ! -e "$META_SECRET_DIR" ] \
  || fail "TEC authored-meta secret refusal partially published its isolated output"
rm -rf "$META_SECRET_DIR"
wp_conf1 eval '
  $b=json_decode(file_get_contents("/siterepo/.tmp-tec-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_EventCost",$b["cost"]);
' >/dev/null

ORGANIZER_DUP_DIR="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-organizer-duplicate"
rm -rf "$ORGANIZER_DUP_DIR"
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  $ids=get_post_meta($p->ID,"_EventOrganizerID",false);
  if(count($ids)!==3||!add_post_meta($p->ID,"_EventOrganizerID",$ids[0])) throw new RuntimeException("duplicate organizer probe failed");
' >/dev/null
ORGANIZER_DUP_RC=0
ORGANIZER_DUP_OUT=$(wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-tec-organizer-duplicate 2>&1) \
  || ORGANIZER_DUP_RC=$?
require_duo_answered "TEC duplicate organizer row capture" human "$ORGANIZER_DUP_OUT"
[ "$ORGANIZER_DUP_RC" -ne 0 ] \
  && grep -Fq "repeated-row authored meta '_EventOrganizerID'" <<<"$ORGANIZER_DUP_OUT" \
  && grep -Fq 'contains a duplicate value' <<<"$ORGANIZER_DUP_OUT" \
  || fail "TEC duplicate physical organizer row did not refuse exactly: $ORGANIZER_DUP_OUT"
[ ! -e "$ORGANIZER_DUP_DIR" ] || fail "TEC duplicate organizer refusal published isolated output"
wp_conf1 eval '
  $b=json_decode(file_get_contents("/siterepo/.tmp-tec-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  delete_post_meta($p->ID,"_EventOrganizerID");
  foreach($b["organizers"] as $id){if(!add_post_meta($p->ID,"_EventOrganizerID",$id))throw new RuntimeException("organizer restore failed");}
  if(get_post_meta($p->ID,"_EventOrganizerID",false)!==$b["organizers"])throw new RuntimeException("organizer order restore failed");
' >/dev/null
rm -rf "$ORGANIZER_DUP_DIR"

ORGANIZER_BLOCK_DIR="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-organizer-block-owner"
rm -rf "$ORGANIZER_BLOCK_DIR"
wp_conf1 eval '
  global $wpdb;
  $b=json_decode(file_get_contents("/siterepo/.tmp-tec-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  $v=get_posts(["post_type"=>"tribe_venue","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Readiness Hall 東京"])[0];
  $needle="\"organizer\":".(int)$b["organizers"][0];
  $replacement="\"organizer\":".(int)$v->ID;
  $content=preg_replace("/".preg_quote($needle,"/")."/",$replacement,$b["content"],1,$count);
  if($count!==1||$wpdb->update($wpdb->posts,["post_content"=>$content],["ID"=>$p->ID])===false){
    throw new RuntimeException("organizer block wrong-owner premise failed");
  }
' >/dev/null
ORGANIZER_BLOCK_RC=0
ORGANIZER_BLOCK_OUT=$(wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-tec-organizer-block-owner 2>&1) \
  || ORGANIZER_BLOCK_RC=$?
require_duo_answered "TEC wrong-owner organizer block capture" human "$ORGANIZER_BLOCK_OUT"
[ "$ORGANIZER_BLOCK_RC" -ne 0 ] \
  && grep -Fq 'organizer block must resolve to post type tribe_organizer, not tribe_venue' <<<"$ORGANIZER_BLOCK_OUT" \
  || fail "TEC wrong-owner organizer block did not refuse through capture/token/interpreter paths: $ORGANIZER_BLOCK_OUT"
[ ! -e "$ORGANIZER_BLOCK_DIR" ] || fail "TEC wrong-owner organizer block refusal published isolated output"
wp_conf1 eval '
  global $wpdb;
  $b=json_decode(file_get_contents("/siterepo/.tmp-tec-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  if($wpdb->update($wpdb->posts,["post_content"=>$b["content"]],["ID"=>$p->ID])===false){
    throw new RuntimeException("organizer block content restore failed");
  }
' >/dev/null
rm -rf "$ORGANIZER_BLOCK_DIR"

STATUS_BAD_DIR="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-status-malformed"
rm -rf "$STATUS_BAD_DIR"
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_tribe_events_status","rescheduled");
' >/dev/null
STATUS_BAD_RC=0
STATUS_BAD_OUT=$(wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-tec-status-malformed 2>&1) \
  || STATUS_BAD_RC=$?
require_duo_answered "TEC unknown event status capture" human "$STATUS_BAD_OUT"
[ "$STATUS_BAD_RC" -ne 0 ] \
  && grep -Fq 'stored event status must be canceled or postponed' <<<"$STATUS_BAD_OUT" \
  || fail "TEC unknown event status did not refuse through the shipped interpreter: $STATUS_BAD_OUT"
[ ! -e "$STATUS_BAD_DIR" ] || fail "TEC unknown status refusal published isolated output"
wp_conf1 eval '
  $b=json_decode(file_get_contents("/siterepo/.tmp-tec-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_tribe_events_status",$b["status"]);
  update_post_meta($p->ID,"_tribe_events_status_reason",$b["status_reason"]);
' >/dev/null
rm -rf "$STATUS_BAD_DIR"

STATUS_REASON_DIR="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-status-reason-malformed"
rm -rf "$STATUS_REASON_DIR"
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_tribe_events_status_reason",["not"=>"a string"]);
' >/dev/null
STATUS_REASON_RC=0
STATUS_REASON_OUT=$(wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-tec-status-reason-malformed 2>&1) \
  || STATUS_REASON_RC=$?
require_duo_answered "TEC non-string event status reason capture" human "$STATUS_REASON_OUT"
[ "$STATUS_REASON_RC" -ne 0 ] \
  && grep -Fq 'event status reason must remain one scalar string' <<<"$STATUS_REASON_OUT" \
  || fail "TEC structured event status reason did not refuse through the shipped interpreter: $STATUS_REASON_OUT"
[ ! -e "$STATUS_REASON_DIR" ] || fail "TEC malformed status reason refusal published isolated output"
wp_conf1 eval '
  $b=json_decode(file_get_contents("/siterepo/.tmp-tec-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_tribe_events_status_reason",$b["status_reason"]);
' >/dev/null
rm -rf "$STATUS_REASON_DIR"

wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_EventStartDate","2026-02-30 01:02:03");
' >/dev/null
DATE_RC=0
DATE_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || DATE_RC=$?
[ "$DATE_RC" -ne 0 ] && grep -q 'exact real Y-m-d H:i:s date' <<<"$DATE_OUT" \
  || fail "TEC impossible date did not refuse through the shipped interpreter: $DATE_OUT"
wp_conf1 eval '
  $b=json_decode(file_get_contents("/siterepo/.tmp-tec-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_EventStartDate",$b["start"]);
' >/dev/null

wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_EventRecurrence",["rules"=>[["type"=>"Every Week"]]]);
' >/dev/null
RECURRENCE_RC=0
RECURRENCE_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || RECURRENCE_RC=$?
[ "$RECURRENCE_RC" -ne 0 ] && grep -Eqi 'recurrence|unclassified' <<<"$RECURRENCE_OUT" \
  || fail "TEC Pro recurrence state did not refuse in the free adapter: $RECURRENCE_OUT"
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  delete_post_meta($p->ID,"_EventRecurrence");
' >/dev/null

wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_EventRecurrenceRRULE","FREQ=WEEKLY;COUNT=3");
' >/dev/null
RRULE_RC=0
RRULE_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || RRULE_RC=$?
[ "$RRULE_RC" -ne 0 ] && grep -Eqi '_EventRecurrenceRRULE|recurrence|unclassified' <<<"$RRULE_OUT" \
  || fail "TEC Pro RRULE state did not refuse in the free adapter: $RRULE_OUT"
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  delete_post_meta($p->ID,"_EventRecurrenceRRULE");
' >/dev/null

wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_tribe_aggregator_global_id","outside-free-contract");
' >/dev/null
IMPORT_RC=0
IMPORT_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || IMPORT_RC=$?
[ "$IMPORT_RC" -ne 0 ] && grep -Eqi '_tribe_aggregator_global_id|aggregator|unclassified' <<<"$IMPORT_OUT" \
  || fail "TEC Event Aggregator state did not refuse in the free adapter: $IMPORT_OUT"
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  delete_post_meta($p->ID,"_tribe_aggregator_global_id");
' >/dev/null

wp_conf1 eval '
  $v=get_posts(["post_type"=>"tribe_venue","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Readiness Hall 東京"])[0];
  update_post_meta($v->ID,"_VenueLat","27.7172");
' >/dev/null
COORDINATE_RC=0
COORDINATE_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || COORDINATE_RC=$?
[ "$COORDINATE_RC" -ne 0 ] && grep -Eqi '_VenueLat|coordinate|unclassified' <<<"$COORDINATE_OUT" \
  || fail "TEC Pro/Event Aggregator coordinate state did not refuse in the free adapter: $COORDINATE_OUT"
wp_conf1 eval '
  $v=get_posts(["post_type"=>"tribe_venue","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Readiness Hall 東京"])[0];
  delete_post_meta($v->ID,"_VenueLat");
' >/dev/null

wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_EventCost",["future"=>"schema"]);
' >/dev/null
COST_RC=0
COST_OUT=$(wp_conf1 duo capture --repo=/siterepo 2>&1) || COST_RC=$?
[ "$COST_RC" -ne 0 ] && grep -q 'one scalar string' <<<"$COST_OUT" \
  || fail "TEC structured cost did not refuse before native consumption: $COST_OUT"
wp_conf1 eval '
  $b=json_decode(file_get_contents("/siterepo/.tmp-tec-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  update_post_meta($p->ID,"_EventCost",$b["cost"]);
' >/dev/null
rm -f "$SCHEMA_BACKUP"
pass "body warnings redact; authored secrets, malformed status/organizers, dates/scalars, and paid/import/coordinate surfaces refuse atomically"

# The adapter grants no semantic event deletion. Remove only wp_posts so the
# exact row can be restored after capture proves no tombstone was published.
DELETE_BACKUP="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-delete-row.json"
DELETE_ID=$(wp_conf1 eval '
  global $wpdb;
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Unsupported Delete Probe"])[0];
  $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID=%d",$p->ID),ARRAY_A);
  file_put_contents("/siterepo/.tmp-tec-delete-row.json",wp_json_encode($row));
  if(1!==$wpdb->delete($wpdb->posts,["ID"=>$p->ID])) throw new RuntimeException($wpdb->last_error);
  clean_post_cache($p->ID); echo $p->ID;
')
require_fixture_ids DELETE_ID
DELETE_STATUS=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
DELETE_RC=0
DELETE_OUT=$(wp_conf1 duo capture --repo=/siterepo --format=json) || DELETE_RC=$?
require_duo_answered "TEC unsupported event deletion capture" json "$DELETE_OUT"
[ "$DELETE_RC" -ne 0 ] && jq -e '
  .format == "duo-command-refusal/v1" and .reason_code == "unsupported_deletion" and
  any(.diagnostics[]?; .code == "unsupported_deletion" and .surface == "post:tribe_events")
' <<<"$DELETE_OUT" >/dev/null \
  || fail "TEC event deletion did not refuse at exact selector: $DELETE_OUT"
[ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$DELETE_STATUS" ] \
  || fail "TEC deletion refusal partially published a tombstone"
wp_conf1 eval '
  global $wpdb; $row=json_decode(file_get_contents("/siterepo/.tmp-tec-delete-row.json"),true,512,JSON_THROW_ON_ERROR);
  if(false===$wpdb->insert($wpdb->posts,$row)) throw new RuntimeException($wpdb->last_error);
  clean_post_cache((int)$row["ID"]);
' >/dev/null
rm -f "$DELETE_BACKUP"
pass "unsupported TEC event deletion refuses at post:tribe_events with no partial tombstone"

# Competing source/target native repository edits must surface a conflict,
# remain atomic unforced, and converge only under explicit repository authority.
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  $result=tribe_events()->where("id",$p->ID)->set_args([
    "description"=>"Repository competing body 東京 🚀 " . home_url("/repository-authority/"),
    "start_date"=>"2026-09-07 10:00:00","end_date"=>"2026-09-07 12:30:00","timezone"=>"Asia/Kathmandu"
  ])->save();
  if(empty($result[$p->ID])) throw new RuntimeException("TEC source repository update failed");
' >/dev/null
commit_tec_source 'conformance: competing TEC event intent'
wp_conf2 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  $result=tribe_events()->where("id",$p->ID)->set_args([
    "description"=>"Target competing body","start_date"=>"2032-01-01 05:00:00","end_date"=>"2032-01-01 06:00:00","timezone"=>"UTC"
  ])->save();
  if(empty($result[$p->ID])) throw new RuntimeException("TEC target repository update failed");
' >/dev/null
CONFLICT_BEFORE=$(tec_target_hash)
CONFLICT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC competing event plan" json "$CONFLICT_PLAN"
jq -e '(.conflict | length) > 0' <<<"$CONFLICT_PLAN" >/dev/null \
  || fail "TEC competing event did not produce a typed conflict: $CONFLICT_PLAN"
CONFLICT_RC=0
CONFLICT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || CONFLICT_RC=$?
require_duo_answered "TEC unforced competing event apply" human "$CONFLICT_OUT"
[ "$CONFLICT_RC" -ne 0 ] && grep -qi 'conflict' <<<"$CONFLICT_OUT" \
  || fail "TEC competing event did not refuse: $CONFLICT_OUT"
[ "$(tec_target_hash)" = "$CONFLICT_BEFORE" ] || fail "TEC unforced conflict partially mutated target state"
FORCED=$(wp_conf2 duo apply --repo=/siterepo --force-theirs --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC forced competing event apply" json "$FORCED"
jq -e '.canary == "clean" and .verification.result == "pass" and .plan.conflict >= 1' <<<"$FORCED" >/dev/null \
  || fail "TEC forced repository intent did not converge: $FORCED"
CONVERGED=$(observe_tec conf2)
printf '%s\n' "$CONVERGED" | jq -e '
  .home as $home |
  .event.start == "2026-09-07 10:00:00" and .event.end == "2026-09-07 12:30:00" and
  .event.timezone == "Asia/Kathmandu" and (.event.content | contains($home)) and
  .event.occurrence.start_date == .event.start and .event.occurrence.end_date == .event.end and
  .options.maps_key == "target-maps-key-preserved" and .cache == "target-runtime-preserved"
' >/dev/null || fail "TEC forced conflict did not repair native derived state or preserve runtime state: $CONVERGED"
pass "native event conflicts refuse atomically; explicit authority converges and regenerates occurrences"

# A late postmeta constraint failure lands after the post body write. The whole
# transaction, derived rows, and retry marker must survive as one unit.
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  $result=tribe_events()->where("id",$p->ID)->set_args([
    "description"=>"TEC transaction body 東京 🚀 " . home_url("/transaction/"),
    "start_date"=>"2026-09-08 13:15:00","end_date"=>"2026-09-08 16:45:00","timezone"=>"Asia/Kathmandu"
  ])->save();
  if(empty($result[$p->ID])) throw new RuntimeException("TEC transaction source update failed");
' >/dev/null
commit_tec_source 'conformance: TEC transactional recovery intent'
FAULT_BEFORE=$(tec_target_hash)
wp_conf2 db query 'ALTER TABLE wp_postmeta DROP CONSTRAINT IF EXISTS duo_tec_fail_end' >/dev/null
wp_conf2 db query '
  ALTER TABLE wp_postmeta ADD CONSTRAINT duo_tec_fail_end
  CHECK (meta_key <> "_EventEndDate" OR meta_value <> "2026-09-08 16:45:00")
' >/dev/null
FAULT_RC=0
FAULT_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || FAULT_RC=$?
require_duo_answered "TEC injected transaction failure" human "$FAULT_OUT"
[ "$FAULT_RC" -ne 0 ] && grep -q 'duo_tec_fail_end' <<<"$FAULT_OUT" \
  || fail "TEC injected late database failure did not surface exactly: $FAULT_OUT"
[ "$(tec_target_hash)" = "$FAULT_BEFORE" ] || fail "TEC failed transaction left partial post/meta/derived writes"
[ "$(wp_conf2 eval 'echo null === \Duo\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = retained ] \
  || fail "TEC failed transaction did not retain retry authority"
wp_conf2 db query 'ALTER TABLE wp_postmeta DROP CONSTRAINT duo_tec_fail_end' >/dev/null
RETRY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC retry after injected failure" json "$RETRY"
jq -e '.canary == "clean" and .verification.result == "pass" and .applied >= 1' <<<"$RETRY" >/dev/null \
  || fail "TEC retry did not consume durable intent: $RETRY"
RETRIED=$(observe_tec conf2)
printf '%s\n' "$RETRIED" | jq -e '
  .event.start == "2026-09-08 13:15:00" and .event.end == "2026-09-08 16:45:00" and
  .event.occurrence.start_date == .event.start and .event.occurrence.end_date == .event.end and
  (.event.content | contains("TEC transaction body 東京 🚀"))
' >/dev/null || fail "TEC retry did not converge native event/occurrence state: $RETRIED"
pass "late TEC metadata failure rolls back post/meta/projections, retains authority, and retries cleanly"

# Two real apply processes race one new event intent. At least one must win;
# any loser must name serialization rather than an unrelated failure.
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  $result=tribe_events()->where("id",$p->ID)->set("description","Concurrent TEC intent 東京 🚀")->save();
  if(empty($result[$p->ID])) throw new RuntimeException("TEC concurrent source update failed");
' >/dev/null
commit_tec_source 'conformance: concurrent TEC apply intent'
CONCURRENT_A="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-concurrent-a.log"
CONCURRENT_B="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-concurrent-b.log"
set +e
wp_conf2 duo apply --repo=/siterepo --default-author=admin >"$CONCURRENT_A" 2>&1 & PID_A=$!
wp_conf2 duo apply --repo=/siterepo --default-author=admin >"$CONCURRENT_B" 2>&1 & PID_B=$!
wait "$PID_A"; RC_A=$?
wait "$PID_B"; RC_B=$?
set -e
if [ "$RC_A" -ne 0 ] && [ "$RC_B" -ne 0 ]; then
  fail "both competing TEC applies failed: A=$(cat "$CONCURRENT_A") B=$(cat "$CONCURRENT_B")"
fi
for result in A B; do
  eval "rc=\$RC_$result"; eval "log=\$CONCURRENT_$result"
  if [ "$rc" -eq 0 ]; then
    grep -q 'canary clean' "$log" || fail "successful competing TEC apply lacked a clean canary: $(cat "$log")"
  else
    grep -Eqi 'lock|another apply|in progress|promotion' "$log" \
      || fail "competing TEC apply failed outside the named lock: $(cat "$log")"
  fi
done
rm -f "$CONCURRENT_A" "$CONCURRENT_B"
CONCURRENT=$(observe_tec conf2)
printf '%s\n' "$CONCURRENT" | jq -e '
  .event.content == "Concurrent TEC intent 東京 🚀" and
  .event.occurrence.start_date == .event.start and .event.occurrence.end_date == .event.end
' >/dev/null || fail "competing TEC applies lost intent or occurrence repair: $CONCURRENT"
CONCURRENT_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC plan after competing applies" json "$CONCURRENT_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$CONCURRENT_PLAN" >/dev/null \
  || fail "TEC competing applies left retained work: $CONCURRENT_PLAN"
pass "competing TEC applies serialize and leave one exact idempotent native result"

# TEC's uninstall.php is intentionally empty: code removal retains authored
# rows/options/tables. Missing code must refuse, then the exact cached artifact
# reinstalls and deploy reactivates without overwriting target-owned siblings.
wp_conf2 option update duo_tec_neighbor 'target-neighbor-preserved' >/dev/null
wp_conf2 plugin deactivate the-events-calendar >/dev/null
wp_conf2 plugin is-active the-events-calendar >/dev/null 2>&1 && fail "TEC deactivation premise did not land"
REACTIVATE=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC deploy after deactivation" json "$REACTIVATE"
wp_conf2 plugin is-active the-events-calendar >/dev/null || fail "Duo deploy did not reactivate exact TEC code"
ROWS_BEFORE_UNINSTALL=$(wp_conf2 post list --post_type=tribe_events --format=count)
wp_conf2 plugin deactivate the-events-calendar >/dev/null
wp_conf2 plugin uninstall the-events-calendar >/dev/null
wp_conf2 plugin is-installed the-events-calendar >/dev/null 2>&1 && fail "TEC uninstall left plugin code installed"
[ "$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_posts WHERE post_type="tribe_events"' --skip-column-names)" = "$ROWS_BEFORE_UNINSTALL" ] \
  || fail "TEC empty native uninstall unexpectedly deleted authored event rows"
[ "$(wp_conf2 option get duo_tec_neighbor)" = 'target-neighbor-preserved' ] \
  || fail "TEC uninstall mutated an unrelated target option"
MISSING_RC=0
MISSING_OUT=$(wp_conf2 duo deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_duo_answered "TEC deploy with code absent" human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing TEC code did not refuse at compatibility: $MISSING_OUT"
TEC_SHA=2db436c929797bfc5311be942158c474716e61c2f289f7d05c3a08d29b2ad687
TEC_ARTIFACT="/artifacts-cache/plugin-the-events-calendar-6.17.3-${TEC_SHA}.zip"
[ "$(wp_conf2 eval "echo hash_file('sha256', '$TEC_ARTIFACT');")" = "$TEC_SHA" ] \
  || fail "cached TEC reinstall artifact digest moved"
wp_conf2 plugin install "$TEC_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get the-events-calendar --field=version)" = '6.17.3' ] \
  || fail "TEC exact reinstall reported wrong version"
REINSTALL_DEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC deploy after exact reinstall" json "$REINSTALL_DEPLOY"
REINSTALL_APPLY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC apply after residue-preserving reinstall" json "$REINSTALL_APPLY"
jq -e '.canary == "clean" and .verification.result == "pass"' <<<"$REINSTALL_APPLY" >/dev/null \
  || fail "TEC exact reinstall did not retain/converge canonical state: $REINSTALL_APPLY"
RECOVERED=$(observe_tec conf2)
printf '%s\n' "$RECOVERED" | jq -e '
  .version == "6.17.3" and .event.content == "Concurrent TEC intent 東京 🚀" and
  .event.repository_id == .event.id and
  .event.occurrence.start_date == .event.start and .event.occurrence.end_date == .event.end and
  .category.meta.primary == "#654321" and .category.dropdown.primary == "#654321" and
  (.category_css | contains("--tec-color-category-primary:#654321")) and
  .options.maps_key == "target-maps-key-preserved" and .cache == "target-runtime-preserved"
' >/dev/null || fail "TEC native state did not survive exact reinstall: $RECOVERED"
[ "$(wp_conf2 option get duo_tec_neighbor)" = 'target-neighbor-preserved' ] \
  || fail "TEC recovery mutated the unrelated target option"
FINAL_PLAN=$(wp_conf2 duo plan --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC final recovery plan" json "$FINAL_PLAN"
jq -e '([.create,.update,.drift,.conflict,.collision,.delete,.delete_conflict] | map(length) | add) == 0' <<<"$FINAL_PLAN" >/dev/null \
  || fail "TEC recovery was not idempotent: $FINAL_PLAN"
wp_conf2 duo capture --repo=/siterepo --out=/siterepo/.tmp-tec-final >/dev/null
diff -r "$CONF_REPO1/state" "$CONF_REPO2/.tmp-tec-final" || fail "TEC final recovered state was not byte-identical"
rm -rf "$CONF_REPO2/.tmp-tec-final"
rm -f "$SOURCE_IDS_FILE" "$TARGET_IDS_FILE"
pass "deactivate/reactivate, residue-preserving uninstall, absent-code refusal, exact reinstall, native readback, and final recapture are clean"
