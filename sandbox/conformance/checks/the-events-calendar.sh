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
$widget_page = $one('page', 'Duo TEC Legacy Widget Surface');
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
$customizer = tribe('customizer');
if (get_class($customizer) !== 'Tribe__Customizer' || $customizer->ID !== 'tribe_customizer') {
    throw new RuntimeException('TEC native Customizer service or canonical option identity was overridden');
}
global $wp_filter;
$fallback_hook = $wp_filter['default_option_tribe_customizer'] ?? null;
if (!$fallback_hook instanceof WP_Hook) {
    throw new RuntimeException('TEC native Customizer fallback hook is absent or malformed');
}
$fallback_callbacks = [];
foreach ($fallback_hook->callbacks as $priority => $callbacks) {
    foreach ($callbacks as $callback) {
        $fallback_callbacks[] = [
            'accepted_args' => $callback['accepted_args'] ?? null,
            'function' => $callback['function'] ?? null,
            'priority' => $priority,
        ];
    }
}
if (count($fallback_callbacks) !== 1
    || $fallback_callbacks[0]['priority'] !== 10
    || $fallback_callbacks[0]['accepted_args'] !== 1
    || !is_array($fallback_callbacks[0]['function'])
    || ($fallback_callbacks[0]['function'][0] ?? null) !== $customizer
    || ($fallback_callbacks[0]['function'][1] ?? null) !== 'maybe_fallback_get_option') {
    throw new RuntimeException('TEC native Customizer fallback callback topology was extended or overridden');
}
$customizer_contract = [
    'accepted_args' => 1,
    'callback' => 'Tribe__Customizer::maybe_fallback_get_option',
    'canonical' => 'tribe_customizer',
    'class' => get_class($customizer),
    'hook' => 'default_option_tribe_customizer',
    'legacy' => 'tribe_events_pro_customizer',
    'priority' => 10,
];
$customizer_section_services = [
    'events.views.v2.customizer.global-elements' => [
        'class' => \Tribe\Events\Views\V2\Customizer\Section\Global_Elements::class,
        'id' => 'global_elements',
    ],
    'events.views.v2.customizer.month-view' => [
        'class' => \Tribe\Events\Views\V2\Customizer\Section\Month_View::class,
        'id' => 'month_view',
    ],
    'events.views.v2.customizer.events-bar' => [
        'class' => \Tribe\Events\Views\V2\Customizer\Section\Events_Bar::class,
        'id' => 'tec_events_bar',
    ],
    'events.views.v2.customizer.single-event' => [
        'class' => \Tribe\Events\Views\V2\Customizer\Section\Single_Event::class,
        'id' => 'single_event',
    ],
];
$customizer_sections = [];
foreach ($customizer_section_services as $service => $expected) {
    $section = tribe($service);
    if (get_class($section) !== $expected['class']
        || $section->ID !== $expected['id']
        || tribe($service) !== $section) {
        throw new RuntimeException('TEC native Customizer section service identity was overridden');
    }
    $defaults = $section->setup_defaults();
    $settings = $section->setup_content_settings();
    if (!is_array($defaults)
        || !is_array($settings)
        || array_keys($defaults) !== array_keys(array_intersect_key($defaults, $settings))
        || count($defaults) !== count($settings)) {
        throw new RuntimeException('TEC native Customizer defaults/settings registry is malformed');
    }
    $setting_tuples = [];
    foreach ($settings as $setting => $arguments) {
        if (!is_string($setting)
            || !is_array($arguments)
            || array_keys($arguments) !== ['sanitize_callback', 'sanitize_js_callback', 'transport']
            || count(array_filter($arguments, 'is_string')) !== 3) {
            throw new RuntimeException('TEC native Customizer setting tuple is malformed');
        }
        $setting_tuples[$setting] = array_values($arguments);
    }
    if ($expected['id'] === 'tec_events_bar'
        && (isset($settings['view_selector_background_color'])
            || isset($settings['view_selector_background_color_choice']))) {
        throw new RuntimeException('TEC JavaScript-era Customizer residue became a server-owned setting');
    }
    $registry_bytes = wp_json_encode([
        'defaults' => $defaults,
        'settings' => $setting_tuples,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    if (!is_string($registry_bytes)) {
        throw new RuntimeException('TEC native Customizer registry could not be bounded');
    }
    $customizer_sections[$service] = [
        'class' => get_class($section),
        'default_count' => count($defaults),
        'id' => $section->ID,
        'registry_sha256' => hash('sha256', $registry_bytes),
        'setting_count' => count($settings),
    ];
}
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
$widget_blocks = array_values(array_filter(
    parse_blocks($widget_page->post_content),
    static fn(array $block): bool => ($block['blockName'] ?? null) === 'core/legacy-widget'
));
if (count($widget_blocks) !== 4) {
    throw new RuntimeException('TEC legacy-widget page did not retain four exact top-level product blocks');
}
$decode_widget_instance = static function (mixed $instance, string $label): array {
    if (!is_array($instance)
        || !is_string($instance['encoded'] ?? null)
        || !is_string($instance['hash'] ?? null)) {
        throw new RuntimeException("TEC $label embedded widget instance is malformed");
    }
    $serialized = base64_decode($instance['encoded'], true);
    if (!is_string($serialized) || base64_encode($serialized) !== $instance['encoded']) {
        throw new RuntimeException("TEC $label embedded widget instance is not canonical base64");
    }
    $settings = unserialize($serialized, ['allowed_classes' => false]);
    if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
        throw new RuntimeException("TEC $label embedded widget instance is not one settings object");
    }
    return [
        'encoded_bytes' => strlen($instance['encoded']),
        'hash_valid' => hash_equals(wp_hash($serialized), $instance['hash']),
        'settings' => $settings,
    ];
};
$stored_widget_local = static function (array $attrs, string $type): int {
    $id = $attrs['id'] ?? null;
    $prefix = $type . '-';
    if (!is_string($id)
        || !str_starts_with($id, $prefix)
        || preg_match('/^[1-9][0-9]*$/D', substr($id, strlen($prefix))) !== 1) {
        throw new RuntimeException("TEC stored widget identity for $type is malformed");
    }
    return (int) substr($id, strlen($prefix));
};
$widget_family = static function (string $name, int $selectedLocal): array {
    $value = get_option($name, null);
    if (!is_array($value)) {
        throw new RuntimeException("TEC widget option $name is malformed");
    }
    $multiwidget = [
        'present' => array_key_exists('_multiwidget', $value),
        'value' => $value['_multiwidget'] ?? null,
    ];
    unset($value['_multiwidget']);
    if (!isset($value[$selectedLocal]) || !is_array($value[$selectedLocal])) {
        throw new RuntimeException("TEC widget option $name lacks its selected stored instance");
    }
    $residue = [];
    foreach ($value as $local => $settings) {
        if (!is_int($local) || $local <= 0 || !is_array($settings)) {
            throw new RuntimeException("TEC widget option $name has a malformed local identity");
        }
        if ($local !== $selectedLocal) {
            $residue[] = ['local_id' => $local, 'settings' => $settings];
        }
    }
    return [
        'local_id' => $selectedLocal,
        'multiwidget' => $multiwidget,
        'residue' => $residue,
        'settings' => $value[$selectedLocal],
    ];
};
$list_local = $stored_widget_local(
    (array) ($widget_blocks[0]['attrs'] ?? []),
    'tribe-widget-events-list'
);
$qr_local = $stored_widget_local(
    (array) ($widget_blocks[1]['attrs'] ?? []),
    'tribe-widget-events-qr-code'
);
$selected_assignments = [
    'tribe-widget-events-list-' . $list_local,
    'tribe-widget-events-qr-code-' . $qr_local,
];
$sidebars = get_option('sidebars_widgets', null);
if (!is_array($sidebars)
    || !is_array($sidebars['wp_inactive_widgets'] ?? null)) {
    throw new RuntimeException('TEC legacy-widget sidebar assignment is missing or malformed');
}
$sidebar_assignments = array_values($sidebars['wp_inactive_widgets']);
if (count($sidebar_assignments) !== count(array_unique($sidebar_assignments, SORT_STRING))) {
    throw new RuntimeException('TEC inactive widget assignments contain a duplicate identity');
}
foreach ($selected_assignments as $selected_assignment) {
    if (count(array_keys($sidebar_assignments, $selected_assignment, true)) !== 1) {
        throw new RuntimeException('TEC inactive widget assignments lack one exact selected identity');
    }
}
$sidebar_residue = array_values(array_filter(
    $sidebar_assignments,
    static fn(mixed $id): bool => !in_array($id, $selected_assignments, true)
));
$list_widget_instance = $widget_family('widget_tribe-widget-events-list', $list_local);
$qr_widget_instance = $widget_family('widget_tribe-widget-events-qr-code', $qr_local);
$expected_residue = [];
foreach ($list_widget_instance['residue'] as $row) {
    $expected_residue[] = 'tribe-widget-events-list-' . $row['local_id'];
}
foreach ($qr_widget_instance['residue'] as $row) {
    $expected_residue[] = 'tribe-widget-events-qr-code-' . $row['local_id'];
}
if ($sidebar_residue !== $expected_residue) {
    throw new RuntimeException('TEC inactive widget residue disagrees with target-owned option instances');
}
$safe_serialized = serialize(['title' => 'native-safe-probe', 'limit' => 5]);
$object_serialized = serialize(['title' => (object) ['hostile' => true]]);
$native_filter_probe = static function (string $serialized): array {
    $parsed_block = [
        'blockName' => 'core/legacy-widget',
        'attrs' => [
            'idBase' => 'tribe-widget-events-list',
            'instance' => [
                'encoded' => base64_encode($serialized),
                'hash' => str_repeat('0', 32),
            ],
        ],
        'innerBlocks' => [],
        'innerContent' => [],
        'innerHTML' => '',
    ];
    return apply_filters('render_block_data', $parsed_block, $parsed_block, null);
};
$safe_probe = $native_filter_probe($safe_serialized);
$object_probe = $native_filter_probe($object_serialized);
$safe_probe_hash = $safe_probe['attrs']['instance']['hash'] ?? null;
$object_probe_hash = $object_probe['attrs']['instance']['hash'] ?? null;
$widget_provider_callbacks = [];
foreach (($wp_filter['render_block_data']->callbacks ?? []) as $priority => $callbacks) {
    foreach ($callbacks as $callback) {
        $function = $callback['function'] ?? null;
        if (is_array($function)
            && ($function[1] ?? null) === 'enable_rendering_widget_copied'
            && is_object($function[0] ?? null)) {
            $widget_provider_callbacks[] = [
                'accepted_args' => $callback['accepted_args'] ?? null,
                'class' => get_class($function[0]),
                'priority' => $priority,
            ];
        }
    }
}
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
    'customizer_contract' => $customizer_contract,
    'customizer_sections' => $customizer_sections,
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
    'widget_surface' => [
        'blocks' => [
            'embedded_list' => $decode_widget_instance(
                $widget_blocks[2]['attrs']['instance'] ?? null,
                'list'
            ),
            'embedded_qr' => $decode_widget_instance(
                $widget_blocks[3]['attrs']['instance'] ?? null,
                'QR'
            ),
            'stored_list' => $widget_blocks[0]['attrs'] ?? null,
            'stored_qr' => $widget_blocks[1]['attrs'] ?? null,
        ],
        'list' => $list_widget_instance,
        'native_contract' => [
            'legacy_block_registered' => WP_Block_Type_Registry::get_instance()->is_registered('core/legacy-widget'),
            'object_instance_rehashed' => is_string($object_probe_hash)
                && hash_equals(wp_hash($object_serialized), $object_probe_hash),
            'provider_callbacks' => $widget_provider_callbacks,
            'safe_instance_rehashed' => is_string($safe_probe_hash)
                && hash_equals(wp_hash($safe_serialized), $safe_probe_hash),
        ],
        'page_id' => (int) $widget_page->ID,
        'permalink' => get_permalink($widget_page),
        'qr' => $qr_widget_instance,
        'sidebar' => $sidebar_assignments,
        'sidebar_residue' => $sidebar_residue,
        'sidebar_selected' => $selected_assignments,
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
        'multi_day_cutoff' => $option['multiDayCutoff'] ?? null,
        'events_slug' => $option['eventsSlug'] ?? null,
        'maps_key' => $option['google_maps_js_api_key'] ?? null,
        'seo_behavior' => $option['tec_seo_out_of_range_behavior'] ?? null,
        'single_slug' => $option['singleEventSlug'] ?? null,
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
    $selected=[]; foreach(["eventsSlug","singleEventSlug","tribeEnableViews","viewOption","eventsDefaultVenueID","eventsDefaultOrganizerID","google_maps_js_api_key","eb_security_key"] as $k){$selected[$k]=$option[$k]??null;}
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

# Lifecycle commands run with TEC inactive or absent, so the witness must use
# physical rows only. Hash the complete portable graph, its deterministic
# projections, and every settings/CSS byte uninstall must retain. Exact source
# proves deactivation alone rewrites the env-owned schema-version subkey to
# 5.16.0, so normalize only that value while retaining its presence and every
# authored/target-owned sibling in the mixed row.
tec_target_storage_fingerprint() {
  wp_conf2 eval '
    global $wpdb;
    $queries = [
      "posts" => "SELECT * FROM {$wpdb->posts} WHERE post_type IN (\"tribe_events\",\"tribe_venue\",\"tribe_organizer\") ORDER BY ID",
      "postmeta" => "SELECT pm.* FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type IN (\"tribe_events\",\"tribe_venue\",\"tribe_organizer\") ORDER BY pm.meta_id",
      "widget_page" => $wpdb->prepare(
        "SELECT * FROM {$wpdb->posts} WHERE post_type=%s AND post_name=%s ORDER BY ID",
        "page",
        "duo-tec-legacy-widget-surface"
      ),
      "widget_page_postmeta" => $wpdb->prepare(
        "SELECT pm.* FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID=pm.post_id WHERE p.post_type=%s AND p.post_name=%s ORDER BY pm.meta_id",
        "page",
        "duo-tec-legacy-widget-surface"
      ),
      "terms" => "SELECT t.* FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=t.term_id WHERE tt.taxonomy=\"tribe_events_cat\" ORDER BY t.term_id",
      "term_taxonomy" => "SELECT * FROM {$wpdb->term_taxonomy} WHERE taxonomy=\"tribe_events_cat\" ORDER BY term_taxonomy_id",
      "termmeta" => "SELECT tm.* FROM {$wpdb->termmeta} tm INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id=tm.term_id WHERE tt.taxonomy=\"tribe_events_cat\" ORDER BY tm.meta_id",
      "term_relationships" => "SELECT tr.* FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.taxonomy=\"tribe_events_cat\" ORDER BY tr.object_id,tr.term_taxonomy_id",
      "tec_events" => "SELECT * FROM {$wpdb->prefix}tec_events ORDER BY event_id",
      "tec_occurrences" => "SELECT * FROM {$wpdb->prefix}tec_occurrences ORDER BY occurrence_id",
      "options" => $wpdb->prepare(
        "SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name IN (%s,%s,%s,%s,%s,%s) ORDER BY option_id",
        "tribe_customizer",
        "tribe_events_pro_customizer",
        "tec_events_category_color_css",
        "sidebars_widgets",
        "widget_tribe-widget-events-list",
        "widget_tribe-widget-events-qr-code"
      ),
    ];
    $fingerprint = [];
    foreach ($queries as $name => $sql) {
      $wpdb->last_error = "";
      $rows = $wpdb->get_results($sql, ARRAY_A);
      if (!is_array($rows) || $wpdb->last_error !== "") {
        throw new RuntimeException("TEC lifecycle fingerprint read failed for $name");
      }
      $fingerprint[$name] = [
        "count" => count($rows),
        "sha256" => hash("sha256", serialize($rows)),
      ];
    }
    $wpdb->last_error = "";
    $mainRows = $wpdb->get_results($wpdb->prepare(
      "SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name=%s",
      "tribe_events_calendar_options"
    ), ARRAY_A);
    if (!is_array($mainRows) || count($mainRows) !== 1 || $wpdb->last_error !== "") {
      throw new RuntimeException("TEC lifecycle main-option read failed");
    }
    $main = maybe_unserialize($mainRows[0]["option_value"]);
    if (!is_array($main) || !array_key_exists("schema-version", $main)) {
      throw new RuntimeException("TEC lifecycle main-option schema marker is absent or malformed");
    }
    $main["schema-version"] = "__duo_env_schema_version__";
    $mainRows[0]["option_value"] = serialize($main);
    $fingerprint["main_option"] = [
      "count" => 1,
      "sha256" => hash("sha256", serialize($mainRows)),
    ];
    echo hash("sha256", wp_json_encode($fingerprint, JSON_UNESCAPED_SLASHES));
  '
}

tec_seed_lifecycle_runtime() {
  wp_conf2 eval '
    foreach (["administrator", "editor", "author", "contributor", "subscriber"] as $roleName) {
      $role = get_role($roleName);
      if (!$role instanceof WP_Role) {
        throw new RuntimeException("TEC lifecycle role is missing");
      }
      $role->add_cap("duo_tec_lifecycle_neighbor");
    }
    $hooks = [
      "tribe_schedule_transient_purge",
      "tribe_trash_event_cron",
      "tribe_del_event_cron",
      "tribe_aggregator_single_process_insert_records",
      "duo_tec_lifecycle_neighbor_cron",
    ];
    foreach ($hooks as $offset => $hook) {
      wp_clear_scheduled_hook($hook);
      if (!wp_schedule_single_event(time() + 7200 + $offset, $hook)) {
        throw new RuntimeException("TEC lifecycle cron seed failed");
      }
    }
    update_option("rewrite_rules", ["duo-tec-lifecycle" => "runtime"]);
    set_transient("tec_custom_tables_v1_initialized", "duo-lifecycle", 0);
  ' >/dev/null
}

tec_lifecycle_state() {
  wp_conf2 eval '
    global $wpdb;
    $main = get_option("tribe_events_calendar_options", null);
    if (!is_array($main)) {
      throw new RuntimeException("TEC lifecycle main option is malformed");
    }
    $cron = _get_cron_array();
    if (!is_array($cron)) {
      throw new RuntimeException("TEC lifecycle cron storage is malformed");
    }
    $countHook = static function (string $hook) use ($cron): int {
      $count = 0;
      foreach ($cron as $timestampHooks) {
        if (!is_array($timestampHooks) || !isset($timestampHooks[$hook])) {
          continue;
        }
        if (!is_array($timestampHooks[$hook])) {
          throw new RuntimeException("TEC lifecycle cron hook is malformed");
        }
        $count += count($timestampHooks[$hook]);
      }
      return $count;
    };
    $postTypes = ["tribe_events", "tribe_venue", "tribe_organizer", "tribe-ea-record"];
    $roles = [];
    foreach (["administrator", "editor", "author", "contributor", "subscriber"] as $roleName) {
      $role = get_role($roleName);
      if (!$role instanceof WP_Role) {
        throw new RuntimeException("TEC lifecycle role is missing");
      }
      $pluginCaps = [];
      foreach ($role->capabilities as $capability => $granted) {
        foreach ($postTypes as $postType) {
          if (str_contains((string) $capability, $postType)) {
            $pluginCaps[(string) $capability] = (bool) $granted;
            break;
          }
        }
      }
      ksort($pluginCaps, SORT_STRING);
      $roles[$roleName] = [
        "neighbor" => $role->has_cap("duo_tec_lifecycle_neighbor"),
        "plugin_caps" => $pluginCaps,
      ];
    }
    $rewriteCount = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name=%s",
      "rewrite_rules"
    ));
    echo wp_json_encode([
      "schema_version" => $main["schema-version"] ?? null,
      "legacy_ct1_transient" => get_transient("tec_custom_tables_v1_initialized"),
      "rewrite_rules_rows" => $rewriteCount,
      "cron" => [
        "transient_purge" => $countHook("tribe_schedule_transient_purge"),
        "trash" => $countHook("tribe_trash_event_cron"),
        "delete" => $countHook("tribe_del_event_cron"),
        "aggregator" => $countHook("tribe_aggregator_process_insert_records"),
        "aggregator_single" => $countHook("tribe_aggregator_single_process_insert_records"),
        "neighbor" => $countHook("duo_tec_lifecycle_neighbor_cron"),
      ],
      "roles" => $roles,
    ], JSON_UNESCAPED_SLASHES);
  '
}

tec_target_main_option_raw_hash() {
  wp_conf2 eval '
    global $wpdb;
    $wpdb->last_error = "";
    $rows = $wpdb->get_results($wpdb->prepare(
      "SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name=%s",
      "tribe_events_calendar_options"
    ), ARRAY_A);
    if (!is_array($rows) || count($rows) !== 1 || $wpdb->last_error !== "") {
      throw new RuntimeException("TEC lifecycle raw main-option read failed");
    }
    echo hash("sha256", serialize($rows));
  '
}

tec_deactivate_reactivate_cycle() { # <exact-version>
  local expected_version="$1" before before_caps before_fingerprint before_raw
  local inactive inactive_fingerprint inactive_raw reactivated reactivated_caps
  local reactivate
  tec_seed_lifecycle_runtime
  before=$(tec_lifecycle_state)
  require_observed_nonempty "TEC $expected_version active lifecycle state" "$before"
  printf '%s\n' "$before" | jq -e --arg version "$expected_version" '
    .schema_version == $version and .legacy_ct1_transient == "duo-lifecycle" and
    .rewrite_rules_rows == 1 and
    .cron == {
      transient_purge:1, trash:1, delete:1, aggregator:0,
      aggregator_single:1, neighbor:1
    } and
    ([.roles[].neighbor] | all) and
    ([.roles[].plugin_caps | length] | add) > 0
  ' >/dev/null || fail "TEC $expected_version active lifecycle premise is malformed: $before"
  before_caps=$(printf '%s\n' "$before" | jq -c '.roles | with_entries(.value = .value.plugin_caps)')
  before_fingerprint=$(tec_target_storage_fingerprint)
  before_raw=$(tec_target_main_option_raw_hash)
  require_observed_nonempty "TEC $expected_version lifecycle storage baseline" "$before_fingerprint"
  require_observed_nonempty "TEC $expected_version lifecycle raw-option baseline" "$before_raw"

  wp_conf2 plugin deactivate the-events-calendar >/dev/null
  wp_conf2 plugin is-active the-events-calendar >/dev/null 2>&1 \
    && fail "TEC $expected_version deactivation premise did not land"
  inactive=$(tec_lifecycle_state)
  require_observed_nonempty "TEC $expected_version inactive lifecycle state" "$inactive"
  printf '%s\n' "$inactive" | jq -e '
    .schema_version == "5.16.0" and .legacy_ct1_transient == false and
    .rewrite_rules_rows == 0 and
    .cron == {
      transient_purge:0, trash:0, delete:0, aggregator:0,
      aggregator_single:1, neighbor:1
    } and
    ([.roles[].neighbor] | all) and
    ([.roles[].plugin_caps | length] | add) == 0
  ' >/dev/null || fail "TEC $expected_version native deactivation effects drifted: $inactive"
  inactive_fingerprint=$(tec_target_storage_fingerprint)
  inactive_raw=$(tec_target_main_option_raw_hash)
  [ "$inactive_fingerprint" = "$before_fingerprint" ] \
    || fail "TEC $expected_version deactivation mutated persistent authored/derived state"
  [ "$inactive_raw" != "$before_raw" ] \
    || fail "TEC $expected_version deactivation did not expose its exact env schema-version transition"

  reactivate=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
  require_duo_answered "TEC $expected_version deploy after deactivation" json "$reactivate"
  wp_conf2 plugin is-active the-events-calendar >/dev/null \
    || fail "Duo deploy did not reactivate exact TEC $expected_version code"
  reactivated=$(tec_lifecycle_state)
  require_observed_nonempty "TEC $expected_version reactivated lifecycle state" "$reactivated"
  reactivated_caps=$(printf '%s\n' "$reactivated" | jq -c '.roles | with_entries(.value = .value.plugin_caps)')
  printf '%s\n' "$reactivated" | jq -e --arg version "$expected_version" '
    .schema_version == $version and ([.roles[].neighbor] | all)
  ' >/dev/null || fail "TEC $expected_version reactivation did not restore native env state: $reactivated"
  [ "$reactivated_caps" = "$before_caps" ] \
    || fail "TEC $expected_version reactivation did not restore the exact native capability set"
  [ "$(tec_target_storage_fingerprint)" = "$before_fingerprint" ] \
    || fail "TEC $expected_version exact-code reactivation mutated authored or derived state"
  [ "$(tec_target_main_option_raw_hash)" = "$before_raw" ] \
    || fail "TEC $expected_version reactivation did not converge the exact mixed settings row"
  pass "TEC $expected_version deactivation clears only reviewed runtime and exact-code deploy restores native state"
}

tec_derived_hash() {
  wp_conf2 eval '
    global $wpdb;
    $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0]??null;
    if(!$p) throw new RuntimeException("TEC derived hash event missing");
    echo hash("sha256",wp_json_encode([
      "event"=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tec_events WHERE post_id=%d OR event_id IN (SELECT event_id FROM {$wpdb->prefix}tec_events WHERE post_id=%d) ORDER BY event_id",$p->ID,$p->ID),ARRAY_A),
      "occurrence"=>$wpdb->get_results($wpdb->prepare("SELECT * FROM {$wpdb->prefix}tec_occurrences WHERE post_id=%d OR event_id IN (SELECT event_id FROM {$wpdb->prefix}tec_events WHERE post_id=%d) ORDER BY occurrence_id",$p->ID,$p->ID),ARRAY_A),
    ],JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
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
  require $argv[1];
  require $argv[2];
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
' "$DUO_SOURCE_ROOT/agent/src/Kernel/Canon.php" "$DUO_SOURCE_ROOT/sandbox/tests/support/wp-block-parser-stub.php")
printf '%s\n' "$TEC_CANON_ORGANIZER_BLOCKS" | jq -e --argjson front "$TEC_SOURCE_EVENT_JSON" '
  . == $front.meta._EventOrganizerID and
  length == 3 and all(.[]; test("^\\{\\{post:[0-9a-f-]{36}\\}\\}$"))
' >/dev/null || fail "TEC canonical organizer blocks did not retain exact ordered post tokens: $TEC_CANON_ORGANIZER_BLOCKS"
TEC_SOURCE_WIDGET_STATE=$(grep -RlF '"title": "Duo TEC Legacy Widget Surface"' \
  "${CONF_REPO1:-siterepo/conf1}/state/posts/page" || true)
[ -n "$TEC_SOURCE_WIDGET_STATE" ] \
  && [ "$(printf '%s\n' "$TEC_SOURCE_WIDGET_STATE" | wc -l | tr -d ' ')" = 1 ] \
  || fail "TEC canonical legacy-widget page was not unique: $TEC_SOURCE_WIDGET_STATE"
TEC_CANON_WIDGET_BLOCKS=$(TEC_STATE_PATH="$TEC_SOURCE_WIDGET_STATE" php -r '
  require $argv[1];
  require $argv[2];
  [, $body] = Duo\Canon::parse_post_file((string) file_get_contents((string) getenv("TEC_STATE_PATH")));
  $attrs = [];
  foreach (parse_blocks($body) as $block) {
    if (($block["blockName"] ?? null) === "core/legacy-widget") $attrs[] = $block["attrs"] ?? null;
  }
  echo json_encode($attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
' "$DUO_SOURCE_ROOT/agent/src/Kernel/Canon.php" "$DUO_SOURCE_ROOT/sandbox/tests/support/wp-block-parser-stub.php")
TEC_SOURCE_SIDEBAR="${CONF_REPO1:-siterepo/conf1}/state/sidebars/wp_inactive_widgets.json"
[ -f "$TEC_SOURCE_SIDEBAR" ] || fail "TEC canonical SidebarState fixture is missing: $TEC_SOURCE_SIDEBAR"
jq -e --argjson blocks "$TEC_CANON_WIDGET_BLOCKS" --argjson event "$TEC_SOURCE_EVENT_JSON" '
  .widgets as $widgets |
  ($widgets | length) == 2 and ($blocks | length) == 4 and
  ($widgets | map(.type)) == ["tribe-widget-events-list","tribe-widget-events-qr-code"] and
  $blocks[0] == {id:("{{widget:" + $widgets[0].uuid + "}}"),idBase:"tribe-widget-events-list"} and
  $blocks[1] == {id:("{{widget:" + $widgets[1].uuid + "}}"),idBase:"tribe-widget-events-qr-code"} and
  $blocks[2].idBase == "tribe-widget-events-list" and
  $blocks[2].instance.duo == "the-events-calendar/v1" and
  ($blocks[2].instance.settings.title | contains("{{home}}/calendar-readiness/")) and
  $blocks[3].idBase == "tribe-widget-events-qr-code" and
  $blocks[3].instance.duo == "the-events-calendar/v1" and
  $blocks[3].instance.settings.event_id == ("{{post:" + $event.uuid + "}}") and
  $widgets[1].settings.event_id == ("{{post:" + $event.uuid + "}}")
' "$TEC_SOURCE_SIDEBAR" >/dev/null \
  || fail "TEC canonical widget ledger/embedded codecs did not bind exact sidebar/post identities: $TEC_CANON_WIDGET_BLOCKS"

# Both admitted artifacts must refuse the object graph before publishing any
# repository bytes. Native 6.17.2 re-hashes the same payload while 6.17.3
# rejects it on render; the adapter deliberately enforces the safer boundary
# on both and reports no serialized value.
TEC_WIDGET_OBJECT_BACKUP="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-widget-object-backup.txt"
TEC_WIDGET_OBJECT_OUT="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-widget-object-capture"
rm -rf "$TEC_WIDGET_OBJECT_OUT"
wp_conf1 eval '
  $pages=get_posts(["post_type"=>"page","post_status"=>"any","posts_per_page"=>2,"title"=>"Duo TEC Legacy Widget Surface"]);
  if(count($pages)!==1) throw new RuntimeException("TEC widget object probe page is not unique");
  $backup=[
    "post_content"=>$pages[0]->post_content,
    "post_modified"=>$pages[0]->post_modified,
    "post_modified_gmt"=>$pages[0]->post_modified_gmt,
  ];
  file_put_contents(
    "/siterepo/.tmp-tec-widget-object-backup.txt",
    wp_json_encode($backup,JSON_UNESCAPED_SLASHES)
  );
  $serialized=serialize(["title"=>(object)["hostile"=>true]]);
  $attrs=["idBase"=>"tribe-widget-events-list","instance"=>["encoded"=>base64_encode($serialized),"hash"=>wp_hash($serialized)]];
  $body="<!-- wp:legacy-widget ".wp_json_encode($attrs,JSON_UNESCAPED_SLASHES)." /-->";
  if(is_wp_error(wp_update_post(["ID"=>$pages[0]->ID,"post_content"=>$body],true))){
    throw new RuntimeException("TEC widget object probe could not persist");
  }
' >/dev/null
TEC_WIDGET_OBJECT_RC=0
TEC_WIDGET_OBJECT_CAPTURE=$(wp_conf1 duo capture --repo=/siterepo --out=/siterepo/.tmp-tec-widget-object-capture 2>&1) \
  || TEC_WIDGET_OBJECT_RC=$?
require_duo_answered "TEC embedded widget object refusal" human "$TEC_WIDGET_OBJECT_CAPTURE"
[ "$TEC_WIDGET_OBJECT_RC" -ne 0 ] \
  && grep -Fq 'non-plain serialized data (PHP object)' <<<"$TEC_WIDGET_OBJECT_CAPTURE" \
  && [ ! -e "$TEC_WIDGET_OBJECT_OUT" ] \
  || fail "TEC embedded widget object graph did not refuse atomically: $TEC_WIDGET_OBJECT_CAPTURE"
wp_conf1 eval '
  $pages=get_posts(["post_type"=>"page","post_status"=>"any","posts_per_page"=>2,"title"=>"Duo TEC Legacy Widget Surface"]);
  if(count($pages)!==1) throw new RuntimeException("TEC widget object restore page is not unique");
  $raw=file_get_contents("/siterepo/.tmp-tec-widget-object-backup.txt");
  $backup=is_string($raw)?json_decode($raw,true):null;
  if(!is_array($backup)||array_keys($backup)!==["post_content","post_modified","post_modified_gmt"]){
    throw new RuntimeException("TEC widget object probe backup is malformed");
  }
  global $wpdb;
  $restored=$wpdb->update(
    $wpdb->posts,
    $backup,
    ["ID"=>$pages[0]->ID],
    ["%s","%s","%s"],
    ["%d"]
  );
  if($restored===false||$wpdb->last_error!==""){
    throw new RuntimeException("TEC widget object probe did not restore exact page bytes");
  }
  clean_post_cache((int)$pages[0]->ID);
  $restoredPost=get_post((int)$pages[0]->ID);
  if(!$restoredPost instanceof WP_Post
      ||$restoredPost->post_content!==$backup["post_content"]
      ||$restoredPost->post_modified!==$backup["post_modified"]
      ||$restoredPost->post_modified_gmt!==$backup["post_modified_gmt"]){
    throw new RuntimeException("TEC widget object probe restore readback diverged");
  }
' >/dev/null
rm -rf "$TEC_WIDGET_OBJECT_OUT"
rm -f "$TEC_WIDGET_OBJECT_BACKUP"
pass "TEC stored and embedded widget identities are canonical; hostile object graphs refuse without publication"
TEC_SOURCE_OPTIONS="${CONF_REPO1:-siterepo/conf1}/state/options/core.json"
jq -e '
  .records.tribe_events_calendar_options.value as $o |
  ($o | has("debugEvents") | not) and
  ($o | has("enable_month_view_cache") | not) and
  ($o | has("trash-past-events") | not) and
  ($o | has("delete-past-events") | not) and
  ($o | has("multiDayCutoff") | not) and
  ($o | has("eventsDefaultVenueID") | not) and
  ($o | has("eventsDefaultOrganizerID") | not) and
  ($o | has("google_maps_js_api_key") | not)
' "$TEC_SOURCE_OPTIONS" >/dev/null \
  || fail "TEC canonical mixed option captured an operational/secret target-owned sibling"
pass "canonical TEC state preserves ordered organizer rows and status while excluding preview, operational, and secret state"
SOURCE=$(observe_tec conf1)
TARGET=$(observe_tec conf2)
TEC_EXPECTED_VERSION="${TEC_EXPECTED_VERSION:-6.17.3}"
TEC_CUSTOMIZER_SECTION_CONTRACT='{
  "events.views.v2.customizer.global-elements":{
    "class":"Tribe\\Events\\Views\\V2\\Customizer\\Section\\Global_Elements",
    "default_count":9,
    "id":"global_elements",
    "registry_sha256":"246ad3241459d2208681900b6a39d032dc9daec306ed60bcd9ec404e4a4db493",
    "setting_count":9
  },
  "events.views.v2.customizer.month-view":{
    "class":"Tribe\\Events\\Views\\V2\\Customizer\\Section\\Month_View",
    "default_count":9,
    "id":"month_view",
    "registry_sha256":"17bae2fe211585b18a24ca2c7088550f34e111be2291778ce55fab10826d5bf1",
    "setting_count":9
  },
  "events.views.v2.customizer.events-bar":{
    "class":"Tribe\\Events\\Views\\V2\\Customizer\\Section\\Events_Bar",
    "default_count":10,
    "id":"tec_events_bar",
    "registry_sha256":"91736c6fb3dab5b87b1dc0d354469d0d4cf8c31ca9780cadf527a781f7652f83",
    "setting_count":10
  },
  "events.views.v2.customizer.single-event":{
    "class":"Tribe\\Events\\Views\\V2\\Customizer\\Section\\Single_Event",
    "default_count":3,
    "id":"single_event",
    "registry_sha256":"b70fc0e4b29587f972ef2df1cc133278041b4e2a40c923352da16bd8c8c76216",
    "setting_count":3
  }
}'

printf '%s\n' "$SOURCE" | jq -e \
  --arg version "$TEC_EXPECTED_VERSION" \
  --argjson source "$SOURCE_IDS" \
  --argjson customizer_sections "$TEC_CUSTOMIZER_SECTION_CONTRACT" '
  .event.all_day == null and .event.all_day_native == false and
  .event.hide_from_upcoming == null and .event.hidden_native == false and
  .event.organizer_blocks == (.organizers | map(.id)) and
  .all_day.organizer_blocks == [null] and .delete_probe.organizer_blocks == [] and
  .options.blocks_editor == true and
  .options.multi_day_cutoff == "03:00" and
  .customizer_contract == {
    accepted_args:1,
    callback:"Tribe__Customizer::maybe_fallback_get_option",
    canonical:"tribe_customizer",
    class:"Tribe__Customizer",
    hook:"default_option_tribe_customizer",
    legacy:"tribe_events_pro_customizer",
    priority:10
  } and .customizer_sections == $customizer_sections and
  .editor_native_contract == {
    block:{registered:true,renderer:"Tribe__Events__Editor__Blocks__Event_Organizer::render"},
    setting:{default:false,key:"toggle_blocks_editor",runtime:true,type:"checkbox_bool",validation:"boolean"}
  } and
  .all_day.all_day == "1" and .all_day.all_day_native == true and
  .all_day.hide_from_upcoming == "yes" and .all_day.hidden_native == true and
  .delete_probe.all_day == "" and .delete_probe.all_day_native == false and
  .delete_probe.hide_from_upcoming == null and .delete_probe.hidden_native == false and
  .widget_surface.page_id == $source.widget_page and
  .widget_surface.list.local_id == $source.widget_list and
  .widget_surface.qr.local_id == $source.widget_qr and
  .widget_surface.sidebar == [
    ("tribe-widget-events-list-" + ($source.widget_list|tostring)),
    ("tribe-widget-events-qr-code-" + ($source.widget_qr|tostring))
  ] and
  .widget_surface.sidebar_selected == .widget_surface.sidebar and
  .widget_surface.sidebar_residue == [] and
  .widget_surface.list.multiwidget == {present:true,value:1} and
  .widget_surface.qr.multiwidget == {present:true,value:1} and
  .widget_surface.list.residue == [] and .widget_surface.qr.residue == [] and
  .widget_surface.blocks.stored_list == {id:("tribe-widget-events-list-" + ($source.widget_list|tostring))} and
  .widget_surface.blocks.stored_qr == {id:("tribe-widget-events-qr-code-" + ($source.widget_qr|tostring))} and
  .widget_surface.blocks.embedded_list.hash_valid == true and
  .widget_surface.blocks.embedded_qr.hash_valid == true and
  .widget_surface.blocks.embedded_qr.settings.event_id == $source.event and
  .widget_surface.native_contract == {
    legacy_block_registered:true,
    object_instance_rehashed:($version == "6.17.2"),
    provider_callbacks:[{
      accepted_args:1,
      class:"Tribe\\Events\\Views\\V2\\Widgets\\Service_Provider",
      priority:10
    }],
    safe_instance_rehashed:true
  }
' >/dev/null || fail "TEC source did not expose exact repository/Gutenberg all-day and visibility wires: $SOURCE"

printf '%s\n' "$TARGET" | jq -e \
  --arg version "$TEC_EXPECTED_VERSION" \
  --argjson source "$SOURCE_IDS" \
  --argjson dirty "$TARGET_IDS" \
  --argjson customizer_sections "$TEC_CUSTOMIZER_SECTION_CONTRACT" '
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
  .customizer_contract == {
    accepted_args:1,
    callback:"Tribe__Customizer::maybe_fallback_get_option",
    canonical:"tribe_customizer",
    class:"Tribe__Customizer",
    hook:"default_option_tribe_customizer",
    legacy:"tribe_events_pro_customizer",
    priority:10
  } and .customizer_sections == $customizer_sections and
  .options.events_slug == "calendar-readiness" and .options.single_slug == "readiness-event" and
  .options.views == ["list","month"] and .options.currency_code == "NPR" and
  .options.default_venue == .venue.id and .options.default_organizer == .organizer.id and
  .options.category_frontend == true and .options.seo_behavior == "soft_noindex" and
  .options.category_show_hidden == false and .options.blocks_editor == true and
  .options.timezone_mode == "event" and
  .options.debug == false and .options.month_cache == true and
  .options.trash_past == 12 and .options.delete_past == 24 and
  .options.multi_day_cutoff == "07:00" and
  .options.maps_key == "target-maps-key-preserved" and
  .options.eb_secret == "target-event-aggregator-secret-preserved" and
  .cache == "target-runtime-preserved" and
  (.event.permalink | contains("/readiness-event/")) and
  .widget_surface.page_id == $dirty.widget_page and
  .widget_surface.page_id != $source.widget_page and .widget_surface.page_id >= 7000000000 and
  .widget_surface.list.local_id > 0 and .widget_surface.list.local_id != $source.widget_list and
  .widget_surface.qr.local_id > 0 and .widget_surface.qr.local_id != $source.widget_qr and
  .widget_surface.sidebar_selected == [
    ("tribe-widget-events-list-" + (.widget_surface.list.local_id|tostring)),
    ("tribe-widget-events-qr-code-" + (.widget_surface.qr.local_id|tostring))
  ] and
  .widget_surface.sidebar_residue == [
    "tribe-widget-events-list-1",
    "tribe-widget-events-qr-code-1"
  ] and
  .widget_surface.sidebar == (
    .widget_surface.sidebar_residue + .widget_surface.sidebar_selected
  ) and
  .widget_surface.list.multiwidget == {present:true,value:1} and
  .widget_surface.qr.multiwidget == {present:true,value:1} and
  .widget_surface.list.residue == [{
    local_id:1,
    settings:{
      featured_events_only:true,
      jsonld_enable:false,
      limit:1,
      no_upcoming_events:true,
      title:"Target-only stale list widget",
      tribe_is_list_widget:true
    }
  }] and
  .widget_surface.qr.residue == [{
    local_id:1,
    settings:{
      event_id:0,
      qr_code_size:"4",
      redirection:"current",
      series_id:0,
      widget_title:"Target-only stale QR widget"
    }
  }] and
  .widget_surface.blocks.stored_list == {id:("tribe-widget-events-list-" + (.widget_surface.list.local_id|tostring))} and
  .widget_surface.blocks.stored_qr == {id:("tribe-widget-events-qr-code-" + (.widget_surface.qr.local_id|tostring))} and
  .widget_surface.list.settings.title == ("Duo Sidebar Calendar 東京 " + $home + "calendar-readiness/") and
  .widget_surface.list.settings.limit == 7 and
  .widget_surface.qr.settings.event_id == .event.id and
  .widget_surface.qr.settings.widget_title == "Duo Sidebar Event QR বাংলা" and
  .widget_surface.blocks.embedded_list.hash_valid == true and
  .widget_surface.blocks.embedded_list.settings.title == ("Duo Embedded Calendar مرحبا " + $home + "calendar-readiness/") and
  .widget_surface.blocks.embedded_list.settings.limit == "10" and
  .widget_surface.blocks.embedded_qr.hash_valid == true and
  .widget_surface.blocks.embedded_qr.settings.event_id == .event.id and
  .widget_surface.blocks.embedded_qr.settings.series_id == 0 and
  .widget_surface.native_contract == {
    legacy_block_registered:true,
    object_instance_rehashed:($version == "6.17.2"),
    provider_callbacks:[{
      accepted_args:1,
      class:"Tribe\\Events\\Views\\V2\\Widgets\\Service_Provider",
      priority:10
    }],
    safe_instance_rehashed:true
  }
' >/dev/null || fail "TEC native graph/settings/derived state did not converge: $TARGET"

pass "TEC adopted huge native identities, rewrote refs/URLs, repaired projections, and preserved target-owned extension state"

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
WIDGET_PERMALINK=$(jq -er '.widget_surface.permalink' <<<"$TARGET")
WIDGET_FRONT=$(curl -fsSL "$WIDGET_PERMALINK") \
  || fail "TEC target legacy-widget page did not return 200: $WIDGET_PERMALINK"
require_observed_nonempty "TEC target legacy-widget response" "$WIDGET_FRONT"
grep -qF 'Duo Sidebar Calendar 東京' <<<"$WIDGET_FRONT" \
  || fail "TEC stored-id list widget did not render through the target-native block path"
grep -qF 'Duo Embedded Calendar مرحبا' <<<"$WIDGET_FRONT" \
  || fail "TEC embedded list widget did not render after target-salt re-signing"
grep -qF 'Duo Production Readiness Event 東京' <<<"$WIDGET_FRONT" \
  || fail "TEC rendered list widgets did not resolve the applied event graph"
! grep -qF 'Target-only stale list widget' <<<"$WIDGET_FRONT" \
  || fail "TEC legacy-widget page rendered the displaced target widget instance"
pass "native Gutenberg meta, ordered organizers/statuses, legacy widgets, and Category Colors render exactly"

# Exercise the exact native Category Colors services on every artifact in the
# boundary matrix. Generator::fetch_category_meta() has no ORDER BY, uses an
# OFFSET page size of 500, and usort() compares priority only. The provider
# therefore binds equal-priority output semantically and admits exactly one
# complete native page, while reconciliation must never repopulate the cache
# that the native second service deliberately deletes.
TEC_COLOR_BOUNDARY_FILE="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-category-colors-boundary.php"
read -r -d '' TEC_COLOR_BOUNDARY_PHP <<'PHPEOF' || true
<?php
wp_set_current_user(1);
global $wpdb;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};
$pluginRoot = WP_PLUGIN_DIR . '/the-events-calendar';
foreach ([
    'common/src/Tribe/Cache.php' => '13122d8dd94a4a0b8f43cefcea7b5f61d3c00bc11b98fb282ac73076ed3eb457',
    'src/Events/Category_Colors/CSS/Controller.php' => '16f8bbacefaf7ebe41292f49a6e5a22a21752b4be96ef46316d306d7ba03f949',
    'src/Events/Category_Colors/CSS/Generator.php' => 'e4b400e98736faeed5f2df6f054ba63012e7159962a6ddc53021a94cfbf038d0',
    'src/Events/Category_Colors/Repositories/Category_Color_Dropdown_Provider.php' => 'db1c758c197c08c0c7f70754408fa077a62abf335110a309f540f6644edc3372',
] as $relative => $expectedSha256) {
    $path = $pluginRoot . '/' . $relative;
    $assert(is_file($path) && hash_file('sha256', $path) === $expectedSha256,
        "exact Category Colors service source disagrees: $relative");
}
$manifestDir = getenv('DUO_MANIFESTS_DIR');
$providerPath = is_string($manifestDir)
    ? rtrim($manifestDir, '/') . '/providers/the-events-calendar-category-colors.php'
    : '';
$assert($providerPath !== '' && is_file($providerPath),
    'exact Category Colors provider source is absent from the active manifest mount');
require_once $providerPath;
$assert(class_exists(\Duo\Providers\TheEventsCalendarCategoryColors::class, false),
    'exact Category Colors provider class did not load from the active manifest mount');
$provider = new \Duo\Providers\TheEventsCalendarCategoryColors(
    \Duo\Policy::load('/siterepo')
);
$operation = [
    'format' => 'duo-provider-operation/v1',
    'id' => 'tec-exact-category-colors-boundary',
];
$cacheKey = \TEC\Events\Category_Colors\Repositories\Category_Color_Dropdown_Provider::CACHE_KEY;
$created = [];
$deleteCreated = static function () use (&$created): void {
    foreach (array_reverse($created) as $termId) {
        wp_delete_term($termId, Tribe__Events__Main::TAXONOMY);
    }
    $created = [];
};
$createCategory = static function (string $slug, array $meta) use (&$created): int {
    $inserted = wp_insert_term($slug, Tribe__Events__Main::TAXONOMY, [
        'slug' => $slug,
        'description' => 'Duo exact Category Colors boundary fixture.',
    ]);
    if (is_wp_error($inserted)) {
        throw new RuntimeException('could not create exact Category Colors fixture: ' . $inserted->get_error_message());
    }
    $termId = (int) $inserted['term_id'];
    $created[] = $termId;
    $nativeMeta = tribe(\TEC\Events\Category_Colors\Event_Category_Meta::class)->set_term($termId);
    foreach ($meta as $key => $value) {
        $nativeMeta->set($key, $value);
    }
    $nativeMeta->save();
    return $termId;
};
$rawCss = static function () use ($wpdb): string {
    $rows = $wpdb->get_col($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 2",
        'tec_events_category_color_css'
    ));
    if (!is_array($rows) || count($rows) !== 1 || !is_string($rows[0])) {
        throw new RuntimeException('exact Category Colors CSS option row is absent, duplicated, or malformed');
    }
    return $rows[0];
};
$relevantCount = static function () use ($wpdb): int {
    $keys = [
        'tec-events-cat-colors-primary',
        'tec-events-cat-colors-secondary',
        'tec-events-cat-colors-text',
        'tec-events-cat-colors-priority',
        'tec-events-cat-colors-hidden',
    ];
    $placeholders = implode(',', array_fill(0, count($keys), '%s'));
    $sql = $wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->termmeta} tm "
            . "INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = tm.term_id "
            . "WHERE tt.taxonomy = %s AND tm.meta_key IN ($placeholders)",
        ...array_merge([Tribe__Events__Main::TAXONOMY], $keys)
    );
    $wpdb->last_error = '';
    $value = $wpdb->get_var($sql);
    if ($wpdb->last_error !== '' || !is_string($value) || preg_match('/^[0-9]+$/D', $value) !== 1) {
        throw new RuntimeException('could not count exact Category Colors native metadata rows');
    }
    return (int) $value;
};

$equalPriorityAfter = null;
$onePageCount = null;
try {
    $equal = [
        'tec-events-cat-colors-priority' => '41',
        'tec-events-cat-colors-hidden' => '0',
    ];
    $createCategory('duo-equal-priority-alpha', $equal + [
        'tec-events-cat-colors-primary' => '#102030',
    ]);
    $createCategory('duo-equal-priority-beta', $equal + [
        'tec-events-cat-colors-primary' => '#405060',
    ]);

    $first = $provider->invoke_scoped('regenerate_css', [], $operation);
    $firstCss = $rawCss();
    $second = $provider->invoke_scoped('regenerate_css', [], $operation);
    $secondCss = $rawCss();
    $assert(($first['verified'] ?? null) === true && ($second['verified'] ?? null) === true,
        'exact equal-priority Category Colors generation was not verified');
    $assert(($first['after'] ?? null) === ($second['after'] ?? null),
        'equal-priority native generations changed the semantic scoped receipt');
    $assert(!array_key_exists('css_sha256', $first['after'] ?? []),
        'equal-priority scoped evidence incorrectly bound unstable raw CSS order');
    foreach (['duo-equal-priority-alpha', 'duo-equal-priority-beta'] as $slug) {
        $selector = '.tribe_events_cat-' . $slug . '{';
        $assert(substr_count($firstCss, $selector) === 1 && substr_count($secondCss, $selector) === 1,
            "exact equal-priority CSS lost or duplicated selector $slug");
    }
    $equalPriorityAfter = $first['after'];

    $cache = tribe_cache();
    $assert($cache->get($cacheKey) === false,
        'native Category Colors invocation did not finish with the dropdown cache absent');
    $staleCache = [[
        'slug' => 'duo-equal-priority-alpha',
        'name' => 'duo-equal-priority-alpha',
        'priority' => 41,
        'primary' => '#000000',
        'hidden' => false,
    ]];
    $assert($cache->set($cacheKey, $staleCache, 3600),
        'could not seed the exact stale dropdown cache crash fixture');
    tribe(\TEC\Events\Category_Colors\CSS\Generator::class)->generate_and_save_css();
    $staleCacheBytes = serialize($cache->get($cacheKey));
    $staleRecoveryRefused = false;
    try {
        $provider->reconcile_scoped('regenerate_css', [], $operation);
    } catch (RuntimeException $failure) {
        $staleRecoveryRefused = str_contains(
            $failure->getMessage(),
            'dropdown cache readback is stale or malformed'
        );
    }
    $assert($staleRecoveryRefused
        && serialize($cache->get($cacheKey)) === $staleCacheBytes,
        'recovery certified or mutated a stale cache after the exact first native service');
    $recoveredCrash = $provider->invoke_scoped('regenerate_css', [], $operation);
    $assert(($recoveredCrash['after'] ?? null) === $equalPriorityAfter
        && $cache->get($cacheKey) === false,
        'retry after the exact between-services crash did not converge CSS and cache');

    $dropdown = tribe(
        \TEC\Events\Category_Colors\Repositories\Category_Color_Dropdown_Provider::class
    );
    $rows = $dropdown->get_dropdown_categories();
    $bySlug = [];
    foreach ($rows as $row) {
        if (is_array($row) && isset($row['slug'])) {
            $bySlug[(string) $row['slug']] = $row;
        }
    }
    $assert(($bySlug['duo-equal-priority-alpha']['primary'] ?? null) === '#102030'
        && ($bySlug['duo-equal-priority-alpha']['priority'] ?? null) === 41
        && ($bySlug['duo-equal-priority-beta']['primary'] ?? null) === '#405060'
        && ($bySlug['duo-equal-priority-beta']['priority'] ?? null) === 41,
        'native dropdown repopulation lost equal-priority semantic rows');
    $populatedCache = $cache->get($cacheKey);
    $assert(is_array($populatedCache), 'native dropdown did not repopulate its exact cache entry');
    $populatedBytes = serialize($populatedCache);
    $reconcileOne = $provider->reconcile_scoped('regenerate_css', [], $operation);
    $reconcileTwo = $provider->reconcile_scoped('regenerate_css', [], $operation);
    $assert(($reconcileOne['after'] ?? null) === $equalPriorityAfter
        && ($reconcileTwo['after'] ?? null) === $equalPriorityAfter,
        'read-only reconciliation did not recognize equal-priority semantic output');
    $assert(serialize($cache->get($cacheKey)) === $populatedBytes,
        'reconciliation mutated or repopulated an already populated dropdown cache');

    $cache->delete($cacheKey);
    $assert($cache->get($cacheKey) === false, 'exact cache-expiry premise did not land');
    $expiryOne = $provider->reconcile_scoped('regenerate_css', [], $operation);
    $expiryTwo = $provider->reconcile_scoped('regenerate_css', [], $operation);
    $assert(($expiryOne['after'] ?? null) === $equalPriorityAfter
        && ($expiryTwo['after'] ?? null) === $equalPriorityAfter
        && $cache->get($cacheKey) === false,
        'repeated reconciliation repopulated a naturally absent dropdown cache');

    $deleteCreated();
    $provider->invoke('regenerate_css', []);

    $baseCount = $relevantCount();
    $assert($baseCount >= 0 && $baseCount <= 500,
        'canonical target already exceeds the native one-page Category Colors frontier');
    $remaining = 500 - $baseCount;
    $values = [
        'tec-events-cat-colors-primary' => '#112233',
        'tec-events-cat-colors-secondary' => '#445566',
        'tec-events-cat-colors-text' => '#ffffff',
        'tec-events-cat-colors-priority' => '73',
        'tec-events-cat-colors-hidden' => '0',
    ];
    $fixtureNumber = 0;
    $lastColoredSlug = null;
    while ($remaining > 0) {
        $slug = sprintf('duo-one-page-%03d', $fixtureNumber++);
        $termMeta = array_slice($values, 0, min(5, $remaining), true);
        $createCategory($slug, $termMeta);
        if (isset($termMeta['tec-events-cat-colors-primary'])) {
            $lastColoredSlug = $slug;
        }
        $remaining -= count($termMeta);
    }
    $onePageCount = $relevantCount();
    $assert($onePageCount === 500 && is_string($lastColoredSlug),
        'exact native one-page Category Colors fixture did not reach 500 rows');
    $onePage = $provider->invoke('regenerate_css', []);
    $onePageCss = $rawCss();
    $assert(($onePage['verified'] ?? null) === true
        && substr_count($onePageCss, '.tribe_events_cat-' . $lastColoredSlug . '{') === 1,
        'the exact native Generator did not consume its complete 500-row query page');

    // wp_insert_term() crosses TEC's registered created_tribe_events_cat
    // generator and cache-bust hooks. Establish the refusal preimage only
    // after that native fixture mutation, then repopulate the exact cache so
    // the provider must preserve both durable CSS and populated cache bytes.
    $createCategory('duo-second-page-refusal', [
        'tec-events-cat-colors-primary' => '#abcdef',
    ]);
    $assert($relevantCount() === 501, 'exact native second-page fixture did not reach 501 rows');
    $dropdown->get_dropdown_categories();
    $cssBeforeOverflow = $rawCss();
    $cacheBeforeOverflow = tribe_cache()->get($cacheKey);
    $assert(is_array($cacheBeforeOverflow),
        'exact native 501-row refusal preimage did not contain a populated dropdown cache');
    $refused = false;
    try {
        $provider->invoke('regenerate_css', []);
    } catch (RuntimeException $failure) {
        $refused = str_contains($failure->getMessage(), 'safe one-page metadata frontier');
    }
    $assert($refused, 'the exact native 501-row Category Colors query did not refuse before mutation');
    $assert($rawCss() === $cssBeforeOverflow
        && serialize(tribe_cache()->get($cacheKey)) === serialize($cacheBeforeOverflow),
        'the exact 501-row refusal mutated CSS or the populated dropdown cache');
} finally {
    $deleteCreated();
    $provider->invoke('regenerate_css', []);
}

echo wp_json_encode([
    'cache_postcondition' => 'absent_after_invoke_absent_or_current_on_reconcile',
    'stale_between_services_refused' => true,
    'equal_priority_after_sha256' => hash('sha256', wp_json_encode($equalPriorityAfter)),
    'native_one_page_rows' => $onePageCount,
    'native_second_page_refused' => true,
], JSON_UNESCAPED_SLASHES) . "\n";
PHPEOF
printf '%s' "$TEC_COLOR_BOUNDARY_PHP" > "$TEC_COLOR_BOUNDARY_FILE"
TEC_COLOR_BOUNDARY_RC=0
TEC_COLOR_BOUNDARY_OUT=$(wp_conf2 eval-file /siterepo/.tmp-tec-category-colors-boundary.php 2>&1) \
  || TEC_COLOR_BOUNDARY_RC=$?
rm -f "$TEC_COLOR_BOUNDARY_FILE"
[ "$TEC_COLOR_BOUNDARY_RC" -eq 0 ] \
  || fail "TEC exact Category Colors equal-priority/cache/pagination boundary failed: $TEC_COLOR_BOUNDARY_OUT"
TEC_COLOR_BOUNDARY_JSON=$(printf '%s\n' "$TEC_COLOR_BOUNDARY_OUT" | awk 'NF { line=$0 } END { print line }')
printf '%s\n' "$TEC_COLOR_BOUNDARY_JSON" | jq -e '
  .cache_postcondition == "absent_after_invoke_absent_or_current_on_reconcile" and
  .stale_between_services_refused == true and
  (.equal_priority_after_sha256 | test("^[0-9a-f]{64}$")) and
  .native_one_page_rows == 500 and .native_second_page_refused == true
' >/dev/null || fail "TEC exact Category Colors boundary returned malformed evidence: $TEC_COLOR_BOUNDARY_OUT"
pass "exact Category Colors services bind equal-priority semantics, read-only cache recovery, and the native 500/501 query frontier"

# Both official patch boundaries execute the real deactivate/deploy-reactivate
# path before the matrix returns. Source-proved env transitions are observed
# separately from the normalized authored/derived fingerprint.
tec_deactivate_reactivate_cycle "${TEC_EXPECTED_VERSION:-6.17.3}"

if [ "${TEC_BOUNDARY_ONLY:-0}" = 1 ]; then
  pass "TEC exact-boundary native round trip is clean"
  return 0 2>/dev/null || exit 0
fi

# The inactive widget carrier is selected only by stored-id legacy blocks.
# Exercise real scoped publication against a disposable clone so dropping
# those references cannot sweep target-local assignments, options, or maps.
# This is after TEC_BOUNDARY_ONLY: standalone covers 6.17.3 and the full
# matrix leg covers 6.17.2.
TEC_WIDGET_SCOPE_BASE="${CONF_REPO1:-siterepo/conf1}"
TEC_WIDGET_SCOPE_HOST="$TEC_WIDGET_SCOPE_BASE/.tmp-tec-widget-scoped-capture"
TEC_WIDGET_SCOPE_REPO='/siterepo/.tmp-tec-widget-scoped-capture'
TEC_SCOPE_ENVS="$TEC_WIDGET_SCOPE_BASE/.tmp-tec-scope-envs.json"
TEC_WIDGET_SCOPE_MUTATED=0
TEC_WIDGET_PHYSICAL_ORIGINAL=''
TEC_WIDGET_LEDGER_ORIGINAL=''
TEC_WIDGET_PAGE_ID=$(jq -er '.widget_page' <<<"$SOURCE_IDS")
[[ "$TEC_WIDGET_PAGE_ID" =~ ^[1-9][0-9]*$ ]] \
  || fail "TEC scoped inactive-widget fixture has a malformed page id"
TEC_WIDGET_PAGE_UUID=$(awk '
  NR == 1 && $0 == "---" { front = 1; next }
  front && $0 == "---" { exit }
  front { print }
' "$TEC_SOURCE_WIDGET_STATE" | jq -er '.uuid')
[[ "$TEC_WIDGET_PAGE_UUID" =~ ^[a-f0-9-]{36}$ ]] \
  || fail "TEC scoped inactive-widget fixture has a malformed page UUID"

tec_widget_scope_physical_hash() {
  wp_conf1 eval "\$id=$TEC_WIDGET_PAGE_ID;"'
    global $wpdb;
    $wpdb->last_error = "";
    $rows = [
      "post" => $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->posts} WHERE ID=%d ORDER BY ID", $id
      ), ARRAY_A),
      "postmeta" => $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->postmeta} WHERE post_id=%d ORDER BY meta_id", $id
      ), ARRAY_A),
      "options" => $wpdb->get_results(
        "SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} " .
        "WHERE option_name IN (\"sidebars_widgets\",\"widget_tribe-widget-events-list\",\"widget_tribe-widget-events-qr-code\") " .
        "ORDER BY option_name,option_id",
        ARRAY_A
      ),
      "map" => $wpdb->get_results(
        "SELECT uuid,entity_type,id_kind,local_id FROM {$wpdb->prefix}duo_map " .
        "ORDER BY uuid,entity_type,id_kind,local_id",
        ARRAY_A
      ),
    ];
    if ($wpdb->last_error !== "" || count($rows["post"]) !== 1 || count($rows["options"]) !== 3) {
      throw new RuntimeException("TEC scoped inactive-widget physical witness failed");
    }
    echo hash("sha256", serialize($rows));
  ' | tr -d '[:space:]'
}

tec_widget_scope_ledger_witness() {
  wp_conf1 eval "\$uuid='$TEC_WIDGET_PAGE_UUID';"'
    global $wpdb;
    $wpdb->last_error = "";
    $selected = $wpdb->get_results($wpdb->prepare(
      "SELECT uuid,entity_type,content_hash FROM {$wpdb->prefix}duo_state WHERE uuid=%s ORDER BY uuid",
      $uuid
    ), ARRAY_A);
    $other = $wpdb->get_results($wpdb->prepare(
      "SELECT uuid,entity_type,content_hash FROM {$wpdb->prefix}duo_state WHERE uuid<>%s " .
      "ORDER BY uuid,entity_type,content_hash",
      $uuid
    ), ARRAY_A);
    $kv = $wpdb->get_results(
      "SELECT k,v FROM {$wpdb->prefix}duo_kv ORDER BY k,v",
      ARRAY_A
    );
    $journal = $wpdb->get_results(
      "SELECT id,t,op,tbl,item,surface,actor,caps,hook,proposal FROM {$wpdb->prefix}duo_journal " .
      "ORDER BY id,t,op,tbl,item,surface,actor,caps,hook,proposal",
      ARRAY_A
    );
    if ($wpdb->last_error !== "" || count($selected) !== 1
        || ($selected[0]["uuid"] ?? null) !== $uuid
        || ($selected[0]["entity_type"] ?? null) !== "post"
        || !is_string($selected[0]["content_hash"] ?? null)
        || !preg_match("/^[a-f0-9]{64}$/D", $selected[0]["content_hash"])) {
      throw new RuntimeException("TEC scoped inactive-widget ledger witness failed");
    }
    echo wp_json_encode([
      "selected_state" => $selected,
      "other_state_sha256" => hash("sha256", serialize($other)),
      "kv_sha256" => hash("sha256", serialize($kv)),
      "journal_sha256" => hash("sha256", serialize($journal)),
    ], JSON_UNESCAPED_SLASHES);
  ' | jq -ce '.'
}

tec_widget_scope_canonical_widgets() {
  TEC_STATE_PATH=$1 php -r '
    require $argv[1];
    require $argv[2];
    [, $body] = Duo\Canon::parse_post_file((string) file_get_contents((string) getenv("TEC_STATE_PATH")));
    $attrs = [];
    foreach (parse_blocks($body) as $block) {
      if (($block["blockName"] ?? null) === "core/legacy-widget") {
        $attrs[] = $block["attrs"] ?? null;
      }
    }
    echo Duo\Canon::encode($attrs);
  ' "$DUO_SOURCE_ROOT/agent/src/Kernel/Canon.php" "$DUO_SOURCE_ROOT/sandbox/tests/support/wp-block-parser-stub.php"
}

tec_widget_scope_expected_page() {
  TEC_STATE_PATH=$1 php -r '
    require $argv[1];
    require $argv[2];
    $source = (string) file_get_contents((string) getenv("TEC_STATE_PATH"));
    [, $body] = Duo\Canon::parse_post_file($source);
    $kept = [];
    $stored = 0;
    $embedded = 0;
    foreach (parse_blocks($body) as $block) {
      if (($block["blockName"] ?? null) === "core/legacy-widget"
          && is_array($block["attrs"] ?? null)
          && isset($block["attrs"]["id"])) {
        $stored++;
        continue;
      }
      if (($block["blockName"] ?? null) === "core/legacy-widget"
          && is_array($block["attrs"]["instance"] ?? null)) {
        $embedded++;
      }
      $kept[] = $block;
    }
    if ($stored !== 2 || $embedded !== 2) {
      throw new RuntimeException("TEC scoped inactive-widget expected page has an unexpected block projection");
    }
    $frontEnd = strpos($source, "\n---\n", 3);
    if ($frontEnd === false) {
      throw new RuntimeException("TEC scoped inactive-widget expected page lost its canonical front matter");
    }
    echo substr($source, 0, $frontEnd + 5) . serialize_blocks($kept) . "\n";
  ' "$DUO_SOURCE_ROOT/agent/src/Kernel/Canon.php" "$DUO_SOURCE_ROOT/sandbox/tests/support/wp-block-parser-stub.php"
}

tec_widget_scope_assert_repo_absent() { # <repo> <needle> <role>
  local repo=$1 needle=$2 role=$3 matches
  matches=$(find "$repo" \
    -path "$repo/.git" -prune -o \
    \( -name '.original-post-content' -o -name '.original-selected-state.json' \
       -o -name '.expected-widget-page.md' -o -name '.first.scope.json' \
       -o -name '.second.scope.json' \) -prune -o \
    -type f -exec grep -Fl -- "$needle" {} + 2>/dev/null || true)
  [ -z "$matches" ] || fail "TEC scoped inactive-widget $role escaped into the disposable repository: $matches"
}

tec_widget_scope_restore_physical_preimage() {
  wp_conf1 eval "\$id=$TEC_WIDGET_PAGE_ID;\$uuid='$TEC_WIDGET_PAGE_UUID';"'
    global $wpdb;
    $wpdb->last_error = "";
    $content = file_get_contents("/siterepo/.tmp-tec-widget-scoped-capture/.original-post-content");
    $stateRaw = file_get_contents("/siterepo/.tmp-tec-widget-scoped-capture/.original-selected-state.json");
    $state = is_string($stateRaw) ? json_decode($stateRaw, true) : null;
    if (!is_string($content) || !is_array($state) || count($state) !== 1
        || ($state[0]["uuid"] ?? null) !== $uuid
        || ($state[0]["entity_type"] ?? null) !== "post"
        || !is_string($state[0]["content_hash"] ?? null)
        || !preg_match("/^[a-f0-9]{64}$/D", $state[0]["content_hash"])) {
      throw new RuntimeException("TEC scoped inactive-widget physical preimage is malformed");
    }
    $postResult = $wpdb->update(
      $wpdb->posts,
      ["post_content" => $content],
      ["ID" => $id],
      ["%s"],
      ["%d"]
    );
    $stateResult = $wpdb->query($wpdb->prepare(
      "INSERT INTO {$wpdb->prefix}duo_state (uuid,entity_type,content_hash) VALUES (%s,%s,%s) " .
      "ON DUPLICATE KEY UPDATE entity_type=VALUES(entity_type),content_hash=VALUES(content_hash)",
      $state[0]["uuid"],
      $state[0]["entity_type"],
      $state[0]["content_hash"]
    ));
    if ($postResult === false || $stateResult === false || $wpdb->last_error !== "") {
      throw new RuntimeException("TEC scoped inactive-widget cleanup could not restore its physical preimage");
    }
    clean_post_cache($id);
  ' >/dev/null
}

tec_widget_scope_repo_hash() {
  local repo=$1
  (
    cd "$repo"
    {
      find state -type f -print
      [ ! -d media ] || find media -type f -print
    } | LC_ALL=C sort | while IFS= read -r file; do
      printf '%s  %s\n' "$(shasum -a 256 "$file" | awk '{print $1}')" "$file"
    done | shasum -a 256 | awk '{print $1}'
  )
}

cleanup_tec_widget_scope() {
  local remove_repo=1
  if [ "$TEC_WIDGET_SCOPE_MUTATED" -eq 1 ]; then
    remove_repo=0
    if [ -f "$TEC_WIDGET_SCOPE_HOST/.original-post-content" ] \
      && [ -f "$TEC_WIDGET_SCOPE_HOST/.original-selected-state.json" ] \
      && [ -n "$TEC_WIDGET_PHYSICAL_ORIGINAL" ] \
      && [ -n "$TEC_WIDGET_LEDGER_ORIGINAL" ] \
      && tec_widget_scope_restore_physical_preimage >/dev/null 2>&1 \
      && [ "$(tec_widget_scope_physical_hash 2>/dev/null)" = "$TEC_WIDGET_PHYSICAL_ORIGINAL" ] \
      && [ "$(tec_widget_scope_ledger_witness 2>/dev/null)" = "$TEC_WIDGET_LEDGER_ORIGINAL" ]; then
      TEC_WIDGET_SCOPE_MUTATED=0
      remove_repo=1
    fi
    if [ "$TEC_WIDGET_SCOPE_MUTATED" -eq 1 ]; then
      printf 'TEC scoped inactive-widget cleanup retained its exact backup at %s\n' \
        "$TEC_WIDGET_SCOPE_HOST" >&2
    fi
  fi
  [ "$remove_repo" -ne 1 ] || rm -rf -- "$TEC_WIDGET_SCOPE_HOST"
  rm -f -- "$TEC_SCOPE_ENVS"
}
trap cleanup_tec_widget_scope EXIT
rm -rf -- "$TEC_WIDGET_SCOPE_HOST"
git clone -q --no-hardlinks "$TEC_WIDGET_SCOPE_BASE" "$TEC_WIDGET_SCOPE_HOST"
TEC_WIDGET_SCOPE_BASE_HEAD=$(git -C "$TEC_WIDGET_SCOPE_BASE" rev-parse --verify HEAD)
TEC_WIDGET_SCOPE_CLONE_HEAD=$(git -C "$TEC_WIDGET_SCOPE_HOST" rev-parse --verify HEAD)
[ "$TEC_WIDGET_SCOPE_CLONE_HEAD" = "$TEC_WIDGET_SCOPE_BASE_HEAD" ] \
  && cmp -s "$TEC_WIDGET_SCOPE_BASE/site.duo.json" "$TEC_WIDGET_SCOPE_HOST/site.duo.json" \
  && [ -z "$(git -C "$TEC_WIDGET_SCOPE_HOST" status --porcelain=v1 --untracked-files=all)" ] \
  || fail "TEC scoped inactive-widget clone did not bind the exact source HEAD/site identity"
# pair.yml's CLI runs as uid/gid 33 while this disposable clone is created by
# the host. Match run.sh's cooperative umask contract before scoped capture
# writes its ignored publication/backup files, without changing Git modes.
chmod -R a+rwX "$TEC_WIDGET_SCOPE_HOST" \
  || fail "TEC scoped inactive-widget clone could not establish cooperative bind permissions"
[ -z "$(git -C "$TEC_WIDGET_SCOPE_HOST" status --porcelain=v1 --untracked-files=all)" ] \
  || fail "TEC scoped inactive-widget permission preparation changed repository identity"
TEC_SCOPE_COMPOSE="$(pwd -P)/pair.yml"
[ -f "$TEC_SCOPE_COMPOSE" ] \
  || fail "TEC scoped evidence cannot resolve the exact pair compose file"
jq -n --arg compose "$TEC_SCOPE_COMPOSE" --arg widgetRepo "$TEC_WIDGET_SCOPE_REPO" '
  {envs: {
    "tec-widget-source": {
      transport: "docker", compose_file: $compose, service: "cli1", repo_path: $widgetRepo
    },
    "tec-source": {
      transport: "docker", compose_file: $compose, service: "cli1", repo_path: "/siterepo"
    }
  }}
' >"$TEC_SCOPE_ENVS" \
  || fail "TEC scoped evidence could not write its isolated control-plane registry"

tec_scope_contract_json() {
  # The host transport preserves the agent's pretty canonical contract while
  # Compose writes progress on stderr. Compact exactly one stdout document so
  # the shared answer classifier still sees one complete final envelope.
  php ../cli/duo --envs-file="$TEC_SCOPE_ENVS" scope "$@" --contract --format=json \
    | jq -ce -s 'if length == 1 then .[0] else error("TEC scope expected exactly one JSON document") end'
}

TEC_WIDGET_SCOPE_ONE="$TEC_WIDGET_SCOPE_HOST/.first.scope.json"
capture_duo_json_success TEC_WIDGET_SCOPE_ONE_OUT \
  "TEC scoped inactive-widget first contract" \
  tec_scope_contract_json tec-widget-source \
  --roots="post:$TEC_WIDGET_PAGE_UUID"
printf '%s\n' "$TEC_WIDGET_SCOPE_ONE_OUT" >"$TEC_WIDGET_SCOPE_ONE"
jq -e --arg uuid "$TEC_WIDGET_PAGE_UUID" '
  .format == "duo-scope-contract/v1" and
  .selectors == ["post:" + $uuid] and
  any(.live.closure[]; .entity == "sidebar/wp_inactive_widgets")
' "$TEC_WIDGET_SCOPE_ONE" >/dev/null \
  || fail "TEC scoped inactive-widget contract lacks its exact outbound carrier closure"

TEC_WIDGET_PHYSICAL_ORIGINAL=$(tec_widget_scope_physical_hash)
require_observed_nonempty "TEC scoped inactive-widget original physical witness" "$TEC_WIDGET_PHYSICAL_ORIGINAL"
TEC_WIDGET_LEDGER_ORIGINAL=$(tec_widget_scope_ledger_witness)
printf '%s\n' "$TEC_WIDGET_LEDGER_ORIGINAL" | jq -e '
  (.selected_state | length) == 1 and
  .selected_state[0].entity_type == "post" and
  (.selected_state[0].content_hash | test("^[a-f0-9]{64}$")) and
  (.other_state_sha256 | test("^[a-f0-9]{64}$")) and
  (.kv_sha256 | test("^[a-f0-9]{64}$")) and
  (.journal_sha256 | test("^[a-f0-9]{64}$"))
' >/dev/null || fail "TEC scoped inactive-widget original ledger witness is malformed"
wp_conf1 eval "\$id=$TEC_WIDGET_PAGE_ID;"'
  global $wpdb;
  $post = get_post($id);
  if (!$post instanceof WP_Post) {
    throw new RuntimeException("TEC scoped inactive-widget page disappeared");
  }
  $backup = "/siterepo/.tmp-tec-widget-scoped-capture/.original-post-content";
  if (file_put_contents($backup, $post->post_content) !== strlen($post->post_content)) {
    throw new RuntimeException("TEC scoped inactive-widget backup failed");
  }
' >/dev/null
printf '%s\n' "$TEC_WIDGET_LEDGER_ORIGINAL" | jq -c '.selected_state' \
  >"$TEC_WIDGET_SCOPE_HOST/.original-selected-state.json"
TEC_WIDGET_EXPECTED_PAGE="$TEC_WIDGET_SCOPE_HOST/.expected-widget-page.md"
tec_widget_scope_expected_page "$TEC_SOURCE_WIDGET_STATE" >"$TEC_WIDGET_EXPECTED_PAGE"
TEC_WIDGET_SCOPE_MUTATED=1
wp_conf1 eval "\$id=$TEC_WIDGET_PAGE_ID;"'
  global $wpdb;
  $post = get_post($id);
  if (!$post instanceof WP_Post) {
    throw new RuntimeException("TEC scoped inactive-widget page disappeared before mutation");
  }
  $kept = [];
  $stored = 0;
  $embedded = 0;
  foreach (parse_blocks($post->post_content) as $block) {
    if (($block["blockName"] ?? null) === "core/legacy-widget"
        && is_array($block["attrs"] ?? null)
        && isset($block["attrs"]["id"])) {
      $stored++;
      continue;
    }
    if (($block["blockName"] ?? null) === "core/legacy-widget"
        && is_array($block["attrs"]["instance"] ?? null)) {
      $embedded++;
    }
    $kept[] = $block;
  }
  if ($stored !== 2 || $embedded !== 2) {
    throw new RuntimeException("TEC scoped inactive-widget mutation did not find the exact block forms");
  }
  $content = serialize_blocks($kept);
  if ($content === $post->post_content
      || $wpdb->update($wpdb->posts, ["post_content" => $content], ["ID" => $id], ["%s"], ["%d"]) !== 1) {
    throw new RuntimeException("TEC scoped inactive-widget mutation did not persist");
  }
  clean_post_cache($id);
' >/dev/null
TEC_WIDGET_PHYSICAL_MUTATED=$(tec_widget_scope_physical_hash)
require_observed_nonempty "TEC scoped inactive-widget mutated physical witness" "$TEC_WIDGET_PHYSICAL_MUTATED"
[ "$TEC_WIDGET_PHYSICAL_MUTATED" != "$TEC_WIDGET_PHYSICAL_ORIGINAL" ] \
  || fail "TEC scoped inactive-widget mutation did not change its exact post witness"
TEC_WIDGET_LEDGER_MUTATED=$(tec_widget_scope_ledger_witness)
[ "$TEC_WIDGET_LEDGER_MUTATED" = "$TEC_WIDGET_LEDGER_ORIGINAL" ] \
  || fail "TEC scoped inactive-widget fixture mutation changed Duo ledger bytes before capture"

TEC_WIDGET_CAPTURE_ONE=$(wp_conf1 duo capture \
  --repo="$TEC_WIDGET_SCOPE_REPO" \
  --scope-contract="$TEC_WIDGET_SCOPE_REPO/.first.scope.json" \
  --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC scoped inactive-widget first capture" json "$TEC_WIDGET_CAPTURE_ONE"
printf '%s\n' "$TEC_WIDGET_CAPTURE_ONE" | jq -e --arg hash "$(jq -r '.scope_hash' "$TEC_WIDGET_SCOPE_ONE")" '
  .scope.scope_hash == $hash and .counts.deletion == 0
' >/dev/null || fail "TEC scoped inactive-widget capture returned malformed scope/deletion evidence"
[ ! -e "$TEC_WIDGET_SCOPE_HOST/state/sidebars/wp_inactive_widgets.json" ] \
  || fail "TEC scoped inactive-widget capture published an empty/shared pseudo row"
tec_widget_scope_assert_repo_absent "$TEC_WIDGET_SCOPE_HOST" \
  'duo-inactive-overlay-deauthorization/v1' 'capture-local receipt'
tec_widget_scope_assert_repo_absent "$TEC_WIDGET_SCOPE_HOST" \
  'sidebar/wp_inactive_widgets' 'pseudo-row tombstone/carrier'
[ "$(tec_widget_scope_physical_hash)" = "$TEC_WIDGET_PHYSICAL_MUTATED" ] \
  || fail "TEC scoped inactive-widget capture mutated post/options/sidebar/map target bytes"
TEC_WIDGET_SCOPE_TWO="$TEC_WIDGET_SCOPE_HOST/.second.scope.json"
capture_duo_json_success TEC_WIDGET_SCOPE_TWO_OUT \
  "TEC scoped inactive-widget second contract" \
  tec_scope_contract_json tec-widget-source \
  --roots="post:$TEC_WIDGET_PAGE_UUID"
printf '%s\n' "$TEC_WIDGET_SCOPE_TWO_OUT" >"$TEC_WIDGET_SCOPE_TWO"
jq -e --arg uuid "$TEC_WIDGET_PAGE_UUID" '
  .format == "duo-scope-contract/v1" and
  ([.live.roots[], .live.closure[]] | any(.entity == "sidebar/wp_inactive_widgets") | not) and
  ([.live.roots[] | select(.entity == $uuid and .type == "post" and
    (.entity_hash | test("^[a-f0-9]{64}$")))] | length) == 1
' "$TEC_WIDGET_SCOPE_TWO" >/dev/null \
  || fail "TEC scoped inactive-widget second contract retained pseudo ownership or lost its selected post hash"
TEC_WIDGET_EXPECTED_STATE_HASH=$(jq -er --arg uuid "$TEC_WIDGET_PAGE_UUID" '
  first(.live.roots[] | select(.entity == $uuid and .type == "post") | .entity_hash)
' "$TEC_WIDGET_SCOPE_TWO")
TEC_WIDGET_LEDGER_FIRST=$(tec_widget_scope_ledger_witness)
jq -en --argjson before "$TEC_WIDGET_LEDGER_ORIGINAL" \
  --argjson after "$TEC_WIDGET_LEDGER_FIRST" \
  --arg expected "$TEC_WIDGET_EXPECTED_STATE_HASH" '
  ($before | del(.selected_state)) == ($after | del(.selected_state)) and
  ($before.selected_state | length) == 1 and ($after.selected_state | length) == 1 and
  $after.selected_state[0].uuid == $before.selected_state[0].uuid and
  $after.selected_state[0].entity_type == $before.selected_state[0].entity_type and
  $after.selected_state[0].content_hash != $before.selected_state[0].content_hash and
  $after.selected_state[0].content_hash == $expected
' >/dev/null || fail "TEC scoped inactive-widget capture exceeded its exact selected duo_state bookkeeping row"
TEC_WIDGET_SCOPE_STATE_REL=${TEC_SOURCE_WIDGET_STATE#"$TEC_WIDGET_SCOPE_BASE/"}
[ "$TEC_WIDGET_SCOPE_STATE_REL" != "$TEC_SOURCE_WIDGET_STATE" ] \
  || fail "TEC scoped inactive-widget canonical page is outside its bound source repository"
TEC_WIDGET_SCOPE_STATE="$TEC_WIDGET_SCOPE_HOST/$TEC_WIDGET_SCOPE_STATE_REL"
[ -f "$TEC_WIDGET_SCOPE_STATE" ] \
  || fail "TEC scoped inactive-widget capture lost its exact selected canonical page"
if ! cmp -s "$TEC_WIDGET_EXPECTED_PAGE" "$TEC_WIDGET_SCOPE_STATE"; then
  diff -u "$TEC_WIDGET_EXPECTED_PAGE" "$TEC_WIDGET_SCOPE_STATE" >&2 || true
  fail "TEC scoped inactive-widget capture changed non-widget canonical page bytes"
fi
TEC_WIDGET_EXPECTED_EMBEDDED=$(printf '%s\n' "$TEC_CANON_WIDGET_BLOCKS" | jq -cS '[.[2],.[3]]')
TEC_WIDGET_CANONICAL_FIRST=$(tec_widget_scope_canonical_widgets "$TEC_WIDGET_SCOPE_STATE" | jq -cS '.')
jq -en --argjson actual "$TEC_WIDGET_CANONICAL_FIRST" --argjson expected "$TEC_WIDGET_EXPECTED_EMBEDDED" '
  $actual == $expected and ($actual | length) == 2 and
  all($actual[]; (has("id") | not) and has("instance")) and
  ($actual | map(.idBase)) == ["tribe-widget-events-list","tribe-widget-events-qr-code"]
' >/dev/null || fail "TEC scoped inactive-widget capture did not preserve the exact two embedded widget blocks"

TEC_WIDGET_REPO_FIRST=$(tec_widget_scope_repo_hash "$TEC_WIDGET_SCOPE_HOST")
TEC_WIDGET_CAPTURE_TWO=$(wp_conf1 duo capture \
  --repo="$TEC_WIDGET_SCOPE_REPO" \
  --scope-contract="$TEC_WIDGET_SCOPE_REPO/.second.scope.json" \
  --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC scoped inactive-widget fixed-point capture" json "$TEC_WIDGET_CAPTURE_TWO"
printf '%s\n' "$TEC_WIDGET_CAPTURE_TWO" | jq -e --arg hash "$(jq -r '.scope_hash' "$TEC_WIDGET_SCOPE_TWO")" '
  .scope.scope_hash == $hash and .counts.deletion == 0
' >/dev/null || fail "TEC scoped inactive-widget retry returned malformed scope/deletion evidence"
[ "$(tec_widget_scope_repo_hash "$TEC_WIDGET_SCOPE_HOST")" = "$TEC_WIDGET_REPO_FIRST" ] \
  || fail "TEC scoped inactive-widget second capture was not a canonical fixed point"
[ "$(tec_widget_scope_physical_hash)" = "$TEC_WIDGET_PHYSICAL_MUTATED" ] \
  || fail "TEC scoped inactive-widget retry changed target assignment/option/map bytes"
TEC_WIDGET_LEDGER_SECOND=$(tec_widget_scope_ledger_witness)
[ "$TEC_WIDGET_LEDGER_SECOND" = "$TEC_WIDGET_LEDGER_FIRST" ] \
  || fail "TEC scoped inactive-widget fixed-point capture changed Duo ledger rows"
TEC_WIDGET_CANONICAL_SECOND=$(tec_widget_scope_canonical_widgets "$TEC_WIDGET_SCOPE_STATE" | jq -cS '.')
[ "$TEC_WIDGET_CANONICAL_SECOND" = "$TEC_WIDGET_CANONICAL_FIRST" ] \
  && [ "$TEC_WIDGET_CANONICAL_SECOND" = "$TEC_WIDGET_EXPECTED_EMBEDDED" ] \
  && cmp -s "$TEC_WIDGET_EXPECTED_PAGE" "$TEC_WIDGET_SCOPE_STATE" \
  || fail "TEC scoped inactive-widget retry changed the exact embedded widget projection"
tec_widget_scope_assert_repo_absent "$TEC_WIDGET_SCOPE_HOST" \
  'duo-inactive-overlay-deauthorization/v1' 'retry capture-local receipt'
tec_widget_scope_assert_repo_absent "$TEC_WIDGET_SCOPE_HOST" \
  'sidebar/wp_inactive_widgets' 'retry pseudo-row tombstone/carrier'

tec_widget_scope_restore_physical_preimage
[ "$(tec_widget_scope_physical_hash)" = "$TEC_WIDGET_PHYSICAL_ORIGINAL" ] \
  && [ "$(tec_widget_scope_ledger_witness)" = "$TEC_WIDGET_LEDGER_ORIGINAL" ] \
  || fail "TEC scoped inactive-widget cleanup did not restore exact target and Duo ledger bytes"
TEC_WIDGET_SCOPE_MUTATED=0
rm -rf -- "$TEC_WIDGET_SCOPE_HOST"
pass "real scoped capture deauthorizes only stored inactive ownership, preserves target/ledger bytes, and reaches a receipt-free fixed point"

# Category metadata commits before required actions (the same recovery boundary
# core rewrite, Elementor, and Yoast conformance exercise). Drive that boundary
# through a real term-scoped authority so ordinal-one's transaction-atomic map
# receipt is exercised by both a failure before COMMIT and a provider failure
# after COMMIT. The physical term-id re-key below is a portable-state-preserving
# ABA: every term/taxonomy/meta/relationship byte keeps its meaning while the
# selected duo_map generation alone changes.
tec_category_uuid() {
  wp_conf1 eval '
    $term=get_term_by("slug","duo-readiness-category","tribe_events_cat");
    if(!$term instanceof WP_Term) throw new RuntimeException("TEC source category disappeared");
    $uuid=\Duo\Ledger::uuid_for((int)$term->term_id,\Duo\Ledger::KIND_TERM);
    if(!is_string($uuid)) throw new RuntimeException("TEC source category has no term UUID");
    echo $uuid;
  ' | tr -d '[:space:]'
}

tec_category_scope() { # <target-host-path> <category-uuid>
  local output=$1 uuid=$2 scoped
  [[ "$uuid" =~ ^[a-f0-9-]{36}$ ]] || fail "TEC Category Colors scope received a malformed category UUID"
  capture_duo_json_success scoped \
    "TEC Category Colors scope contract" \
    tec_scope_contract_json tec-source --roots="term:${uuid}"
  printf '%s\n' "$scoped" >"$output"
  jq -e --arg uuid "$uuid" '
    .format == "duo-scope-contract/v1" and .selectors == ["term:" + $uuid] and
    any(.potential_actions[];
      .manifest == "the-events-calendar" and
      .declaration.kind == "provider" and
      .declaration.provider == "the-events-calendar-category-colors" and
      .declaration.capability == "regenerate_css") and
    any(.potential_providers[]; .id == "the-events-calendar-category-colors")
  ' "$output" >/dev/null || fail "TEC Category Colors scope did not bind its exact provider action"
}

tec_scoped_session_evidence() {
  wp_conf2 eval '
    $session=\Duo\ScopedApplySession::open(new \Duo\LedgerScopedApplySessionStorage());
    if(!$session instanceof \Duo\ScopedApplySession) throw new RuntimeException("TEC scoped session is absent");
    $canonical=$session->canonical();
    $record=$session->to_array();
    $authorActionHash=$record["intents"][0]["action_hash"]??null;
    $receipt=$record["receipts"][0]??null;
    $roots=\Duo\ScopedApply::ledger_map_roots(
      (array)($record["authority"]["selection"]["ledger_map_identity_hashes"]??[])
    );
    $current=\Duo\ScopedApplyCoordinator::authored_ledger_map_hash($roots);
    $receiptAfter=is_array($receipt)?($receipt["after_hash"]??null):null;
    echo wp_json_encode([
      "canonical_sha256"=>hash("sha256",$canonical),
      "phase"=>$session->phase(),
      "recovery_from"=>$session->recorded_recovery_phase(),
      "intent_count"=>count($record["intents"]??[]),
      "receipt_count"=>count($record["receipts"]??[]),
      "author_action_hash"=>$authorActionHash,
      "author_action_matches"=>is_string($authorActionHash)
        &&hash_equals(hash("sha256","duo-scoped-authored-transaction/v2"),$authorActionHash),
      "author_receipt_after"=>$receiptAfter,
      "current_author_after"=>$current,
      "author_matches"=>is_string($receiptAfter)&&hash_equals($receiptAfter,$current),
    ],JSON_UNESCAPED_SLASHES)."\n";
  ' | awk 'NF { line=$0 } END { print line }'
}

tec_scoped_color_storage_hash() { # <category-uuid>; excludes the scoped session itself
  local uuid=$1
  [[ "$uuid" =~ ^[a-f0-9-]{36}$ ]] || fail "TEC Category Colors storage hash received a malformed UUID"
  wp_conf2 eval "
    global \$wpdb;
    \$uuid='$uuid';
    \$term=get_term_by('slug','duo-readiness-category','tribe_events_cat');
    if(!\$term instanceof WP_Term) throw new RuntimeException('TEC target category disappeared');
    \$id=(int)\$term->term_id;
    \$queries=[
      'term'=>\$wpdb->prepare(\"SELECT * FROM {\$wpdb->terms} WHERE term_id=%d\",\$id),
      'tt'=>\$wpdb->prepare(\"SELECT * FROM {\$wpdb->term_taxonomy} WHERE term_id=%d ORDER BY term_taxonomy_id\",\$id),
      'meta'=>\$wpdb->prepare(\"SELECT * FROM {\$wpdb->termmeta} WHERE term_id=%d ORDER BY meta_id\",\$id),
      'rel'=>\$wpdb->prepare(\"SELECT tr.* FROM {\$wpdb->term_relationships} tr INNER JOIN {\$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id=tr.term_taxonomy_id WHERE tt.term_id=%d ORDER BY tr.object_id,tr.term_taxonomy_id\",\$id),
      'map'=>\$wpdb->prepare(\"SELECT * FROM {\$wpdb->prefix}duo_map WHERE uuid=%s ORDER BY id_kind,local_id\",\$uuid),
      'state'=>\$wpdb->prepare(\"SELECT * FROM {\$wpdb->prefix}duo_state WHERE uuid=%s\",\$uuid),
      'css'=>\$wpdb->prepare(\"SELECT option_id,option_name,option_value,autoload FROM {\$wpdb->options} WHERE option_name=%s\",'tec_events_category_color_css'),
      'revision'=>\"SELECT k,v FROM {\$wpdb->prefix}duo_kv WHERE k IN ('applied_revision','apply_in_progress') ORDER BY k\",
    ];
    \$rows=[];
    foreach(\$queries as \$name=>\$sql){
      \$wpdb->last_error='';
      \$result=\$wpdb->get_results(\$sql,ARRAY_A);
      if(!is_array(\$result)||\$wpdb->last_error!=='') throw new RuntimeException('TEC scoped storage witness read failed');
      \$rows[\$name]=\$result;
    }
    echo hash('sha256',serialize(\$rows));
  " | tr -d '[:space:]'
}

tec_set_source_category_primary() { # <#rrggbb>
  local color=$1
  [[ "$color" =~ ^#[0-9a-f]{6}$ ]] || fail "TEC source Category Colors fixture received an invalid color"
  wp_conf1 eval "
    \$term=get_term_by('slug','duo-readiness-category','tribe_events_cat');
    if(!\$term instanceof WP_Term) throw new RuntimeException('TEC source category disappeared');
    tribe(\\TEC\\Events\\Category_Colors\\Event_Category_Meta::class)
      ->set_term((int)\$term->term_id)
      ->set('tec-events-cat-colors-primary','$color')
      ->save();
    tribe(\\TEC\\Events\\Category_Colors\\CSS\\Controller::class)->generate_css();
    \$css=get_option('tec_events_category_color_css','');
    if(!is_string(\$css)||!str_contains(\$css,'$color')) throw new RuntimeException('TEC source CSS update failed');
  " >/dev/null
}

TEC_COLOR_PRECOMMIT_SCOPE=''
TEC_COLOR_SCOPE=''
TEC_COLOR_KV_CONSTRAINT_MAY_EXIST=0
TEC_COLOR_SESSION_RECEIPT_CONSTRAINT_MAY_EXIST=0
TEC_COLOR_ABA_MAY_BE_REKEYED=0
restore_tec_scoped_color_faults() {
  if [ "${TEC_COLOR_KV_CONSTRAINT_MAY_EXIST:-0}" -eq 1 ]; then
    wp_conf2 db query \
      'ALTER TABLE wp_duo_kv DROP CONSTRAINT IF EXISTS duo_tec_fail_scoped_receipt' \
      >/dev/null 2>&1 || true
    TEC_COLOR_KV_CONSTRAINT_MAY_EXIST=0
  fi
  if [ "${TEC_COLOR_SESSION_RECEIPT_CONSTRAINT_MAY_EXIST:-0}" -eq 1 ]; then
    wp_conf2 db query \
      'ALTER TABLE wp_duo_kv DROP CONSTRAINT IF EXISTS duo_tec_fail_scoped_effect_receipt' \
      >/dev/null 2>&1 || true
    TEC_COLOR_SESSION_RECEIPT_CONSTRAINT_MAY_EXIST=0
  fi
  if [ "${TEC_COLOR_ABA_MAY_BE_REKEYED:-0}" -eq 1 ] \
    && [[ "${COLOR_ABA_OLD_ID:-}" =~ ^[1-9][0-9]*$ ]] \
    && [[ "${COLOR_ABA_NEW_ID:-}" =~ ^[1-9][0-9]*$ ]] \
    && [[ "${TEC_COLOR_UUID:-}" =~ ^[a-f0-9-]{36}$ ]]; then
    wp_conf2 db query "
      START TRANSACTION;
      UPDATE wp_duo_map SET local_id=${COLOR_ABA_OLD_ID}
        WHERE uuid='${TEC_COLOR_UUID}' AND id_kind='term' AND local_id=${COLOR_ABA_NEW_ID};
      UPDATE wp_termmeta SET term_id=${COLOR_ABA_OLD_ID} WHERE term_id=${COLOR_ABA_NEW_ID};
      UPDATE wp_term_taxonomy SET term_id=${COLOR_ABA_OLD_ID}
        WHERE term_id=${COLOR_ABA_NEW_ID} AND taxonomy='tribe_events_cat';
      UPDATE wp_terms SET term_id=${COLOR_ABA_OLD_ID} WHERE term_id=${COLOR_ABA_NEW_ID};
      COMMIT;
    " >/dev/null 2>&1 || true
    TEC_COLOR_ABA_MAY_BE_REKEYED=0
  fi
  if [[ "${COLOR_ABA_AUTOINCREMENT:-}" =~ ^[1-9][0-9]*$ ]]; then
    wp_conf2 db query \
      "ALTER TABLE wp_terms AUTO_INCREMENT=${COLOR_ABA_AUTOINCREMENT}" \
      >/dev/null 2>&1 || true
  fi
  [ -z "${TEC_COLOR_PRECOMMIT_SCOPE:-}" ] || rm -f -- "$TEC_COLOR_PRECOMMIT_SCOPE"
  [ -z "${TEC_COLOR_SCOPE:-}" ] || rm -f -- "$TEC_COLOR_SCOPE"
  [ -z "${TEC_SCOPE_ENVS:-}" ] || rm -f -- "$TEC_SCOPE_ENVS"
}
cleanup_tec_scoped_color_faults() {
  local status=$?
  trap - EXIT
  restore_tec_scoped_color_faults
  exit "$status"
}
trap cleanup_tec_scoped_color_faults EXIT

TEC_COLOR_UUID=$(tec_category_uuid)
TEC_COLOR_PRECOMMIT_SCOPE="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-category-colors-precommit.scope.json"
tec_set_source_category_primary '#456789'
commit_tec_source 'conformance: scoped TEC Category Colors atomic author intent'
tec_category_scope "$TEC_COLOR_PRECOMMIT_SCOPE" "$TEC_COLOR_UUID"
COLOR_ATOMIC_BEFORE=$(tec_scoped_color_storage_hash "$TEC_COLOR_UUID")
wp_conf2 db query 'ALTER TABLE wp_duo_kv DROP CONSTRAINT IF EXISTS duo_tec_fail_scoped_receipt' >/dev/null
TEC_COLOR_KV_CONSTRAINT_MAY_EXIST=1
wp_conf2 db query '
  ALTER TABLE wp_duo_kv ADD CONSTRAINT duo_tec_fail_scoped_receipt
  CHECK (k <> "scoped_apply_session" OR v NOT LIKE "%\"phase\": \"authored_committed\"%")
' >/dev/null
COLOR_ATOMIC_RC=0
COLOR_ATOMIC_OUT=$(wp_conf2 duo apply --repo=/siterepo \
  --scope-contract=/siterepo/.tmp-tec-category-colors-precommit.scope.json \
  --default-author=admin 2>&1) || COLOR_ATOMIC_RC=$?
require_duo_answered "TEC injected atomic scoped author-receipt failure" human "$COLOR_ATOMIC_OUT"
[ "$COLOR_ATOMIC_RC" -ne 0 ] && grep -Fq 'duo_tec_fail_scoped_receipt' <<<"$COLOR_ATOMIC_OUT" \
  || fail "TEC atomic scoped author-receipt constraint did not fail at the product boundary: $COLOR_ATOMIC_OUT"
[ "$(tec_scoped_color_storage_hash "$TEC_COLOR_UUID")" = "$COLOR_ATOMIC_BEFORE" ] \
  || fail "TEC atomic author-receipt failure did not roll target, map, state, and CSS bytes back"
COLOR_ATOMIC_SESSION=$(tec_scoped_session_evidence)
printf '%s\n' "$COLOR_ATOMIC_SESSION" | jq -e '
  .phase == "authoring" and .recovery_from == null and
  .intent_count == 1 and .receipt_count == 0 and
  .author_action_hash == "a0b8cb4c1ee6649aa089e3f21cc64471337f0b4d389837ba1219c77479b573c0" and
  .author_action_matches == true and
  .author_receipt_after == null and .author_matches == false
' >/dev/null || fail "TEC failed atomic author receipt did not retain only retryable authoring intent: $COLOR_ATOMIC_SESSION"
wp_conf2 db query 'ALTER TABLE wp_duo_kv DROP CONSTRAINT duo_tec_fail_scoped_receipt' >/dev/null
TEC_COLOR_KV_CONSTRAINT_MAY_EXIST=0
COLOR_ATOMIC_RETRY=$(wp_conf2 duo apply --repo=/siterepo \
  --scope-contract=/siterepo/.tmp-tec-category-colors-precommit.scope.json \
  --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC atomic scoped author-receipt retry" json "$COLOR_ATOMIC_RETRY"
jq -e '
  .format == "duo-scoped-apply-result/v1" and .canary == "clean" and
  .verification.result == "pass" and .scoped_receipt.phase == "complete"
' <<<"$COLOR_ATOMIC_RETRY" >/dev/null \
  || fail "TEC atomic scoped author-receipt retry did not converge: $COLOR_ATOMIC_RETRY"
COLOR_ATOMIC_RECOVERED=$(observe_tec conf2)
printf '%s\n' "$COLOR_ATOMIC_RECOVERED" | jq -e '
  .category.meta.primary == "#456789" and .category.dropdown.primary == "#456789" and
  (.category_css | contains("--tec-color-category-primary:#456789"))
' >/dev/null || fail "TEC atomic scoped author-receipt retry did not repair Category Colors"
rm -f "$TEC_COLOR_PRECOMMIT_SCOPE"
pass "atomic scoped author receipt failure rolls target/map/session publication back and retries exactly"

tec_set_source_category_primary '#654321'
commit_tec_source 'conformance: scoped native TEC Category Colors provider intent'
TEC_COLOR_SCOPE="${CONF_REPO2:-siterepo/conf2}/.tmp-tec-category-colors-provider.scope.json"
tec_category_scope "$TEC_COLOR_SCOPE" "$TEC_COLOR_UUID"
COLOR_FAULT_BEFORE=$(observe_tec conf2)
printf '%s\n' "$COLOR_FAULT_BEFORE" | jq -e '
  .category.meta.primary == "#456789" and .category.dropdown.primary == "#456789" and
  (.category_css | contains("--tec-color-category-primary:#456789"))
' >/dev/null || fail "TEC Category Colors failure premise is not at the prior projection: $COLOR_FAULT_BEFORE"
COLOR_FAULT_REV_BEFORE=$(wp_conf2 db query "SELECT v FROM wp_duo_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty "TEC applied revision before Category Colors fault" "$COLOR_FAULT_REV_BEFORE"
wp_conf2 db query 'ALTER TABLE wp_duo_kv DROP CONSTRAINT IF EXISTS duo_tec_fail_scoped_effect_receipt' >/dev/null
TEC_COLOR_SESSION_RECEIPT_CONSTRAINT_MAY_EXIST=1
wp_conf2 db query '
  ALTER TABLE wp_duo_kv ADD CONSTRAINT duo_tec_fail_scoped_effect_receipt
  CHECK (
    k <> "scoped_apply_session"
    OR JSON_UNQUOTE(JSON_EXTRACT(v, "$.phase")) = "complete"
    OR COALESCE(JSON_LENGTH(JSON_EXTRACT(v, "$.receipts")), 0) < 3
  )
' >/dev/null
COLOR_FAULT_RC=0
COLOR_FAULT_OUT=$(wp_conf2 duo apply --repo=/siterepo \
  --scope-contract=/siterepo/.tmp-tec-category-colors-provider.scope.json \
  --default-author=admin 2>&1) || COLOR_FAULT_RC=$?
require_duo_answered "TEC injected Category Colors provider failure" human "$COLOR_FAULT_OUT"
[ "$COLOR_FAULT_RC" -ne 0 ] \
  && grep -Fq "required manifest action 'provider:the-events-calendar-category-colors/regenerate_css' failed" <<<"$COLOR_FAULT_OUT" \
  && grep -Fq 'scoped apply session update CAS' <<<"$COLOR_FAULT_OUT" \
  || fail "TEC injected Category Colors outer-receipt failure did not surface through the provider: $COLOR_FAULT_OUT"
COLOR_FAULT_AFTER=$(observe_tec conf2)
COLOR_FAULT_EXPECTED=$(printf '%s\n' "$COLOR_FAULT_BEFORE" | jq -Sc '
  .category.meta.primary = "#654321" |
  .category.dropdown.primary = "#654321" |
  .category_css |= gsub("#456789"; "#654321")
')
[ "$(printf '%s\n' "$COLOR_FAULT_AFTER" | jq -Sc .)" = "$COLOR_FAULT_EXPECTED" ] \
  || fail "TEC lost outer receipt did not retain the verified native Category Colors effect: $COLOR_FAULT_AFTER"
[ "$(wp_conf2 db query "SELECT v FROM wp_duo_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')" = "$COLOR_FAULT_REV_BEFORE" ] \
  || fail "TEC failed Category Colors provider action advanced applied_revision"
COLOR_FAULT_SESSION=$(tec_scoped_session_evidence)
printf '%s\n' "$COLOR_FAULT_SESSION" | jq -e '
  .phase == "recovery_required" and .recovery_from == "effects_pending" and
  .intent_count >= 3 and .receipt_count >= 2 and
  .author_action_hash == "a0b8cb4c1ee6649aa089e3f21cc64471337f0b4d389837ba1219c77479b573c0" and
  .author_action_matches == true and .author_matches == true
' >/dev/null || fail "TEC failed Category Colors provider action did not retain exact scoped recovery authority: $COLOR_FAULT_SESSION"
wp_conf2 db query 'ALTER TABLE wp_duo_kv DROP CONSTRAINT duo_tec_fail_scoped_effect_receipt' >/dev/null
TEC_COLOR_SESSION_RECEIPT_CONSTRAINT_MAY_EXIST=0

COLOR_ABA_OLD_ID=$(wp_conf2 db query "
  SELECT local_id FROM wp_duo_map
  WHERE uuid='${TEC_COLOR_UUID}' AND id_kind='term'
" --skip-column-names | tr -d '[:space:]')
[[ "$COLOR_ABA_OLD_ID" =~ ^[1-9][0-9]*$ ]] || fail "TEC Category Colors ABA premise lacks one selected term map"
[ "$(wp_conf2 db query "SELECT COUNT(*) FROM wp_duo_map WHERE uuid='${TEC_COLOR_UUID}' AND id_kind='term'" --skip-column-names | tr -d '[:space:]')" = 1 ] \
  || fail "TEC Category Colors ABA premise has a duplicate selected term map"
COLOR_ABA_NEW_ID=$(wp_conf2 db query 'SELECT COALESCE(MAX(term_id),0)+1000 FROM wp_terms' --skip-column-names | tr -d '[:space:]')
COLOR_ABA_AUTOINCREMENT=$(wp_conf2 db query "
  SELECT AUTO_INCREMENT FROM information_schema.TABLES
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='wp_terms'
" --skip-column-names | tr -d '[:space:]')
[[ "$COLOR_ABA_NEW_ID" =~ ^[1-9][0-9]*$ && "$COLOR_ABA_AUTOINCREMENT" =~ ^[1-9][0-9]*$ ]] \
  || fail "TEC Category Colors ABA could not bind its reversible term-id frontier"
COLOR_ABA_CSS_BEFORE=$(wp_conf2 db query "
  SELECT SHA2(CONCAT(option_id,0x00,option_value,0x00,autoload),256)
  FROM wp_options WHERE option_name='tec_events_category_color_css'
" --skip-column-names | tr -d '[:space:]')
TEC_COLOR_ABA_MAY_BE_REKEYED=1
wp_conf2 db query "
  START TRANSACTION;
  UPDATE wp_terms SET term_id=${COLOR_ABA_NEW_ID} WHERE term_id=${COLOR_ABA_OLD_ID};
  UPDATE wp_term_taxonomy SET term_id=${COLOR_ABA_NEW_ID} WHERE term_id=${COLOR_ABA_OLD_ID} AND taxonomy='tribe_events_cat';
  UPDATE wp_termmeta SET term_id=${COLOR_ABA_NEW_ID} WHERE term_id=${COLOR_ABA_OLD_ID};
  UPDATE wp_duo_map SET local_id=${COLOR_ABA_NEW_ID}
    WHERE uuid='${TEC_COLOR_UUID}' AND id_kind='term' AND local_id=${COLOR_ABA_OLD_ID};
  COMMIT;
" >/dev/null
COLOR_ABA_SESSION_DRIFT=$(tec_scoped_session_evidence)
printf '%s\n' "$COLOR_ABA_SESSION_DRIFT" | jq -e '
  .phase == "recovery_required" and .recovery_from == "effects_pending" and
  .author_matches == false and
  (.author_receipt_after | test("^[a-f0-9]{64}$")) and
  (.current_author_after | test("^[a-f0-9]{64}$")) and
  .author_receipt_after != .current_author_after
' >/dev/null || fail "TEC selected term-id ABA did not change only the atomic author map witness: $COLOR_ABA_SESSION_DRIFT"
COLOR_ABA_RC=0
COLOR_ABA_OUT=$(wp_conf2 duo apply --repo=/siterepo \
  --scope-contract=/siterepo/.tmp-tec-category-colors-provider.scope.json \
  --default-author=admin 2>&1) || COLOR_ABA_RC=$?
require_duo_answered "TEC selected-map ABA retry refusal" human "$COLOR_ABA_OUT"
[ "$COLOR_ABA_RC" -ne 0 ] \
  && grep -Fq 'scoped apply recovery author receipt does not match selected state and identity map' <<<"$COLOR_ABA_OUT" \
  || fail "TEC selected-map ABA did not refuse before Category Colors effect replay: $COLOR_ABA_OUT"
[ "$(tec_scoped_session_evidence | jq -Sc .)" = "$(printf '%s\n' "$COLOR_ABA_SESSION_DRIFT" | jq -Sc .)" ] \
  || fail "TEC selected-map ABA refusal changed its already-active recovery session"
[ "$(wp_conf2 db query "SELECT SHA2(CONCAT(option_id,0x00,option_value,0x00,autoload),256) FROM wp_options WHERE option_name='tec_events_category_color_css'" --skip-column-names | tr -d '[:space:]')" = "$COLOR_ABA_CSS_BEFORE" ] \
  || fail "TEC selected-map ABA refusal replayed the Category Colors CSS effect"
[ "$(wp_conf2 db query "SELECT v FROM wp_duo_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')" = "$COLOR_FAULT_REV_BEFORE" ] \
  || fail "TEC selected-map ABA refusal advanced applied_revision"

wp_conf2 db query "
  START TRANSACTION;
  UPDATE wp_duo_map SET local_id=${COLOR_ABA_OLD_ID}
    WHERE uuid='${TEC_COLOR_UUID}' AND id_kind='term' AND local_id=${COLOR_ABA_NEW_ID};
  UPDATE wp_termmeta SET term_id=${COLOR_ABA_OLD_ID} WHERE term_id=${COLOR_ABA_NEW_ID};
  UPDATE wp_term_taxonomy SET term_id=${COLOR_ABA_OLD_ID} WHERE term_id=${COLOR_ABA_NEW_ID} AND taxonomy='tribe_events_cat';
  UPDATE wp_terms SET term_id=${COLOR_ABA_OLD_ID} WHERE term_id=${COLOR_ABA_NEW_ID};
  COMMIT;
" >/dev/null
wp_conf2 db query "ALTER TABLE wp_terms AUTO_INCREMENT=${COLOR_ABA_AUTOINCREMENT}" >/dev/null
TEC_COLOR_ABA_MAY_BE_REKEYED=0
COLOR_ABA_SESSION_RESTORED=$(tec_scoped_session_evidence)
printf '%s\n' "$COLOR_ABA_SESSION_RESTORED" | jq -e '.author_matches == true' >/dev/null \
  || fail "TEC selected-map ABA inverse did not restore the exact atomic author map witness: $COLOR_ABA_SESSION_RESTORED"
COLOR_RETRY=$(wp_conf2 duo apply --repo=/siterepo \
  --scope-contract=/siterepo/.tmp-tec-category-colors-provider.scope.json \
  --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC Category Colors retry" json "$COLOR_RETRY"
jq -e '
  .format == "duo-scoped-apply-result/v1" and .canary == "clean" and
  .verification.result == "pass" and .scoped_receipt.phase == "complete" and .applied >= 1
' <<<"$COLOR_RETRY" >/dev/null || fail "TEC Category Colors retry did not converge: $COLOR_RETRY"
COLOR_RECOVERED=$(observe_tec conf2)
printf '%s\n' "$COLOR_RECOVERED" | jq -e '
  .category.meta.primary == "#654321" and .category.dropdown.primary == "#654321" and
  (.category_css | contains("--tec-color-category-primary:#654321")) and
  (.category_css | contains("--tec-color-category-secondary:#fedcba"))
' >/dev/null || fail "TEC Category Colors retry did not repair native CSS/dropdown projections: $COLOR_RECOVERED"
rm -f "$TEC_COLOR_SCOPE"
restore_tec_scoped_color_faults
trap - EXIT
pass "scoped Category Colors recovery refuses a selected-map ABA before reconciling a lost outer receipt, then inverse/retry converges"

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
  global $wpdb;
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  if($wpdb->update($wpdb->posts,["post_content"=>"AKIAABCDEFGHIJKLMNOP"],["ID"=>$p->ID],["%s"],["%d"])!==1){
    throw new RuntimeException("TEC credential body probe could not persist its exact physical mutation");
  }
  clean_post_cache($p->ID);
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
  global $wpdb;
  $b=json_decode(file_get_contents("/siterepo/.tmp-tec-schema-backup.json"),true,512,JSON_THROW_ON_ERROR);
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  if($wpdb->update($wpdb->posts,["post_content"=>$b["content"]],["ID"=>$p->ID],["%s"],["%d"])!==1){
    throw new RuntimeException("TEC credential body probe could not restore its exact physical preimage");
  }
  clean_post_cache($p->ID);
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
mkdir -p "$ORGANIZER_BLOCK_DIR"
cp "$CONF_REPO1/site.duo.json" "$ORGANIZER_BLOCK_DIR/site.duo.json"
chmod -R a+rwX "$ORGANIZER_BLOCK_DIR"
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
ORGANIZER_BLOCK_CAPTURE_RC=0
ORGANIZER_BLOCK_CAPTURE_OUT=$(wp_conf1 duo capture --repo=/siterepo \
  --out=/siterepo/.tmp-tec-organizer-block-owner/state 2>&1) \
  || ORGANIZER_BLOCK_CAPTURE_RC=$?
require_duo_answered "TEC wrong-owner organizer block capture" human "$ORGANIZER_BLOCK_CAPTURE_OUT"
[ "$ORGANIZER_BLOCK_CAPTURE_RC" -eq 0 ] \
  && grep -Fq 'Success: captured' <<<"$ORGANIZER_BLOCK_CAPTURE_OUT" \
  || fail "TEC wrong-owner organizer block did not traverse capture/tokenization: $ORGANIZER_BLOCK_CAPTURE_OUT"
ORGANIZER_BLOCK_RC=0
ORGANIZER_BLOCK_OUT=$(wp_conf1 duo compile --repo=/siterepo/.tmp-tec-organizer-block-owner 2>&1) \
  || ORGANIZER_BLOCK_RC=$?
require_duo_answered "TEC wrong-owner organizer block compile" human "$ORGANIZER_BLOCK_OUT"
[ "$ORGANIZER_BLOCK_RC" -ne 0 ] \
  && grep -Fq 'organizer block must resolve to post type tribe_organizer, not tribe_venue' <<<"$ORGANIZER_BLOCK_OUT" \
  || fail "TEC wrong-owner organizer block did not refuse through capture/token/interpreter paths: $ORGANIZER_BLOCK_OUT"
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

# TEC entities participate in plugin-owned occurrence, linked-post, taxonomy,
# Category Colors, and optional-add-on effects. Remove only each identity row,
# retain every dependent row as a witness, and prove capture publishes neither
# a tombstone nor any database/provider side effect before restoring the row.
tec_deletion_fingerprint() {
  wp_conf1 eval '
    global $wpdb;
    $queries=[
      "posts"=>"SELECT * FROM {$wpdb->posts} ORDER BY ID",
      "postmeta"=>"SELECT * FROM {$wpdb->postmeta} ORDER BY meta_id",
      "terms"=>"SELECT * FROM {$wpdb->terms} ORDER BY term_id",
      "term_taxonomy"=>"SELECT * FROM {$wpdb->term_taxonomy} ORDER BY term_taxonomy_id",
      "termmeta"=>"SELECT * FROM {$wpdb->termmeta} ORDER BY meta_id",
      "term_relationships"=>"SELECT * FROM {$wpdb->term_relationships} ORDER BY object_id,term_taxonomy_id",
      "tec_events"=>"SELECT * FROM {$wpdb->prefix}tec_events ORDER BY event_id",
      "tec_occurrences"=>"SELECT * FROM {$wpdb->prefix}tec_occurrences ORDER BY occurrence_id",
      "category_css"=>$wpdb->prepare(
        "SELECT option_id,option_name,option_value,autoload FROM {$wpdb->options} WHERE option_name IN (%s,%s) ORDER BY option_id",
        "tec_events_category_color_css","tribe_events_calendar_options"
      ),
    ];
    $fingerprint=[];
    foreach($queries as $name=>$sql){
      $wpdb->last_error="";
      $rows=$wpdb->get_results($sql,ARRAY_A);
      if(!is_array($rows)||$wpdb->last_error!==""){
        throw new RuntimeException("TEC deletion fingerprint read failed for $name");
      }
      $fingerprint[$name]=["count"=>count($rows),"sha256"=>hash("sha256",serialize($rows))];
    }
    echo wp_json_encode($fingerprint,JSON_UNESCAPED_SLASHES);
  '
}

tec_refuse_post_deletion() { # <post-type> <title> <surface>
  local post_type="$1" title="$2" surface="$3" backup id status before after rc out
  backup="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-delete-${post_type}-row.json"
  id=$(wp_conf1 eval '
    global $wpdb;
    $posts=get_posts(["post_type"=>"'"$post_type"'","post_status"=>"any","posts_per_page"=>2,"title"=>"'"$title"'"]);
    if(count($posts)!==1) throw new RuntimeException("TEC deletion probe identity is not unique");
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->posts} WHERE ID=%d",$posts[0]->ID),ARRAY_A);
    if(!is_array($row)) throw new RuntimeException("TEC deletion probe row is unreadable");
    file_put_contents("/siterepo/.tmp-tec-delete-'"$post_type"'-row.json",wp_json_encode($row));
    if(1!==$wpdb->delete($wpdb->posts,["ID"=>$posts[0]->ID])) throw new RuntimeException($wpdb->last_error);
    clean_post_cache($posts[0]->ID); echo $posts[0]->ID;
  ')
  require_fixture_ids id
  status=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
  before=$(tec_deletion_fingerprint)
  rc=0
  out=$(wp_conf1 duo capture --repo=/siterepo --format=json) || rc=$?
  require_duo_answered "TEC unsupported $surface deletion capture" json "$out"
  [ "$rc" -ne 0 ] && jq -e --arg surface "$surface" '
    .format == "duo-command-refusal/v1" and .reason_code == "unsupported_deletion" and
    any(.diagnostics[]?; .code == "unsupported_deletion" and .surface == $surface)
  ' <<<"$out" >/dev/null \
    || fail "TEC deletion did not refuse at exact selector $surface: $out"
  after=$(tec_deletion_fingerprint)
  [ "$after" = "$before" ] \
    || fail "TEC $surface deletion refusal mutated posts/meta/terms/relationships/occurrences/Category Colors state"
  [ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$status" ] \
    || fail "TEC $surface deletion refusal partially published a tombstone"
  wp_conf1 eval '
    global $wpdb;
    $row=json_decode(file_get_contents("/siterepo/.tmp-tec-delete-'"$post_type"'-row.json"),true,512,JSON_THROW_ON_ERROR);
    if(false===$wpdb->insert($wpdb->posts,$row)) throw new RuntimeException($wpdb->last_error);
    clean_post_cache((int)$row["ID"]);
  ' >/dev/null
  rm -f "$backup"
  pass "unsupported TEC deletion refuses atomically at $surface"
}

tec_refuse_term_deletion() { # <taxonomy> <slug> <surface>
  local taxonomy="$1" slug="$2" surface="$3" backup id status before after rc out
  backup="${CONF_REPO1:-siterepo/conf1}/.tmp-tec-delete-${taxonomy}-row.json"
  id=$(wp_conf1 eval '
    global $wpdb;
    $term=get_term_by("slug","'"$slug"'","'"$taxonomy"'");
    if(!$term instanceof WP_Term) throw new RuntimeException("TEC term deletion probe is missing");
    $row=$wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->terms} WHERE term_id=%d",$term->term_id),ARRAY_A);
    if(!is_array($row)) throw new RuntimeException("TEC term deletion probe row is unreadable");
    file_put_contents("/siterepo/.tmp-tec-delete-'"$taxonomy"'-row.json",wp_json_encode($row));
    if(1!==$wpdb->delete($wpdb->terms,["term_id"=>$term->term_id])) throw new RuntimeException($wpdb->last_error);
    clean_term_cache((int)$term->term_id,"'"$taxonomy"'"); echo $term->term_id;
  ')
  require_fixture_ids id
  status=$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)
  before=$(tec_deletion_fingerprint)
  rc=0
  out=$(wp_conf1 duo capture --repo=/siterepo --format=json) || rc=$?
  require_duo_answered "TEC unsupported $surface deletion capture" json "$out"
  [ "$rc" -ne 0 ] && jq -e --arg surface "$surface" '
    .format == "duo-command-refusal/v1" and .reason_code == "unsupported_deletion" and
    any(.diagnostics[]?; .code == "unsupported_deletion" and .surface == $surface)
  ' <<<"$out" >/dev/null \
    || fail "TEC deletion did not refuse at exact selector $surface: $out"
  after=$(tec_deletion_fingerprint)
  [ "$after" = "$before" ] \
    || fail "TEC $surface deletion refusal mutated posts/meta/terms/relationships/occurrences/Category Colors state"
  [ "$(git -C "$CONF_REPO1" status --porcelain --untracked-files=all -- state)" = "$status" ] \
    || fail "TEC $surface deletion refusal partially published a tombstone"
  wp_conf1 eval '
    global $wpdb;
    $row=json_decode(file_get_contents("/siterepo/.tmp-tec-delete-'"$taxonomy"'-row.json"),true,512,JSON_THROW_ON_ERROR);
    if(false===$wpdb->insert($wpdb->terms,$row)) throw new RuntimeException($wpdb->last_error);
    clean_term_cache((int)$row["term_id"],"'"$taxonomy"'");
  ' >/dev/null
  rm -f "$backup"
  pass "unsupported TEC deletion refuses atomically at $surface"
}

tec_refuse_post_deletion tribe_events 'Duo Unsupported Delete Probe' post:tribe_events
tec_refuse_post_deletion tribe_venue 'Duo Unsupported Delete Venue' post:tribe_venue
tec_refuse_post_deletion tribe_organizer 'Duo Unsupported Delete Organizer' post:tribe_organizer
tec_refuse_term_deletion tribe_events_cat duo-unsupported-delete-category term:tribe_events_cat
unset -f tec_deletion_fingerprint tec_refuse_post_deletion tec_refuse_term_deletion
pass "all unsupported TEC entity deletions refuse with no tombstone, cascade, reverse-reference, occurrence, or Category Colors mutation"

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

# The exact free custom-table model exposes one filter over the complete
# derived row. A same-shape extension callback is still a different authority:
# refuse it before either custom-table write, retain the batch marker, and let
# the next process retry the identical canonical intent after the hook leaves.
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  $result=tribe_events()->where("id",$p->ID)->set_args([
    "description"=>"TEC filtered-row refusal body 東京 🚀 " . home_url("/filter-refusal/"),
    "start_date"=>"2026-09-08 13:15:00","end_date"=>"2026-09-08 16:45:00","timezone"=>"Asia/Kathmandu"
  ])->save();
  if(empty($result[$p->ID])) throw new RuntimeException("TEC filter-refusal source update failed");
' >/dev/null
commit_tec_source 'conformance: TEC filtered derived-row refusal intent'
FILTER_DERIVED_BEFORE=$(tec_derived_hash)
FILTER_REV_BEFORE=$(wp_conf2 db query "SELECT v FROM wp_duo_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')
require_observed_nonempty "TEC applied revision before event-data filter refusal" "$FILTER_REV_BEFORE"
TEC_FILTER_MU_MAY_EXIST=1
remove_tec_filter_fault() {
  $COMPOSE exec -T --user root wp2 rm -f -- /var/www/html/wp-content/mu-plugins/duo-tec-event-data-filter-fault.php >/dev/null 2>&1
}
cleanup_tec_filter_fault() {
  local status=$?
  trap - EXIT
  if [ "${TEC_FILTER_MU_MAY_EXIST:-0}" -eq 1 ]; then
    remove_tec_filter_fault || true
  fi
  exit "$status"
}
trap cleanup_tec_filter_fault EXIT
$COMPOSE exec -T --user root wp2 sh -c \
  'printf "%s\n" "<?php" "add_filter(\"tec_events_custom_tables_v1_event_data_from_post\", static function (array \$data): array { \$data[\"timezone\"] = \"UTC\"; return \$data; }, PHP_INT_MAX, 1);" > /var/www/html/wp-content/mu-plugins/duo-tec-event-data-filter-fault.php'
[ "$(wp_conf2 eval 'echo has_filter("tec_events_custom_tables_v1_event_data_from_post") ? "registered" : "missing";')" = registered ] \
  || fail "TEC event-data filter fault was not registered"
FILTER_RC=0
FILTER_OUT=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin 2>&1) || FILTER_RC=$?
require_duo_answered "TEC native event-data filter refusal" human "$FILTER_OUT"
[ "$FILTER_RC" -ne 0 ] \
  && grep -Fq "batch regenerator 'the-events-calendar' failed" <<<"$FILTER_OUT" \
  && grep -Fq 'free-plugin derived-state contract does not admit the event-data filter' <<<"$FILTER_OUT" \
  || fail "TEC event-data filter did not refuse through the exact regenerator: $FILTER_OUT"
[ "$(wp_conf2 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  echo get_post_meta($p->ID,"_EventStartDate",true);
')" = '2026-09-08 13:15:00' ] || fail "TEC event-data filter refusal lost the committed authored intent"
[ "$(tec_derived_hash)" = "$FILTER_DERIVED_BEFORE" ] \
  || fail "TEC event-data filter refusal mutated a derived row before topology validation"
[ "$(wp_conf2 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  $uuid=\Duo\Ledger::uuid_for((int)$p->ID,\Duo\Ledger::KIND_POST);
  echo $uuid === null ? "missing-uuid" : (string)\Duo\Ledger::kv_get("regen_pending:".$uuid);
')" = tribe_events ] || fail "TEC event-data filter refusal did not arm the exact batch retry marker"
[ "$(wp_conf2 db query "SELECT v FROM wp_duo_kv WHERE k='applied_revision'" --skip-column-names | tr -d '[:space:]')" = "$FILTER_REV_BEFORE" ] \
  || fail "TEC event-data filter refusal advanced applied_revision"
[ "$(wp_conf2 eval 'echo null === \Duo\Ledger::kv_get("apply_in_progress") ? "clear" : "retained";')" = retained ] \
  || fail "TEC event-data filter refusal did not retain apply_in_progress"
remove_tec_filter_fault
TEC_FILTER_MU_MAY_EXIST=0
trap - EXIT
FILTER_RETRY=$(wp_conf2 duo apply --repo=/siterepo --default-author=admin --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC retry after event-data filter refusal" json "$FILTER_RETRY"
jq -e '.canary == "clean" and .verification.result == "pass" and .applied >= 1' <<<"$FILTER_RETRY" >/dev/null \
  || fail "TEC retry after event-data filter refusal did not converge: $FILTER_RETRY"
[ "$(wp_conf2 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  $uuid=\Duo\Ledger::uuid_for((int)$p->ID,\Duo\Ledger::KIND_POST);
  echo $uuid !== null && \Duo\Ledger::kv_get("regen_pending:".$uuid) === null ? "clear" : "retained";
')" = clear ] || fail "TEC successful event-data filter retry retained its batch marker"
FILTER_RETRIED=$(observe_tec conf2)
printf '%s\n' "$FILTER_RETRIED" | jq -e '
  .event.start == "2026-09-08 13:15:00" and .event.end == "2026-09-08 16:45:00" and
  .event.timezone == "Asia/Kathmandu" and
  .event.occurrence.start_date == .event.start and .event.occurrence.end_date == .event.end and
  (.event.content | contains("TEC filtered-row refusal body 東京 🚀"))
' >/dev/null || fail "TEC event-data filter retry did not converge both exact native rows: $FILTER_RETRIED"
pass "native event-data filter topology refuses before derived writes, arms retry, and converges after removal"

# A late postmeta constraint failure lands after the post body write. The whole
# transaction, derived rows, and retry marker must survive as one unit.
wp_conf1 eval '
  $p=get_posts(["post_type"=>"tribe_events","post_status"=>"any","posts_per_page"=>1,"title"=>"Duo Production Readiness Event 東京"])[0];
  $result=tribe_events()->where("id",$p->ID)->set_args([
    "description"=>"TEC transaction body 東京 🚀 " . home_url("/transaction/"),
    "start_date"=>"2026-09-09 13:15:00","end_date"=>"2026-09-09 16:45:00","timezone"=>"Asia/Kathmandu"
  ])->save();
  if(empty($result[$p->ID])) throw new RuntimeException("TEC transaction source update failed");
' >/dev/null
commit_tec_source 'conformance: TEC transactional recovery intent'
FAULT_BEFORE=$(tec_target_hash)
wp_conf2 db query 'ALTER TABLE wp_postmeta DROP CONSTRAINT IF EXISTS duo_tec_fail_end' >/dev/null
wp_conf2 db query '
  ALTER TABLE wp_postmeta ADD CONSTRAINT duo_tec_fail_end
  CHECK (meta_key <> "_EventEndDate" OR meta_value <> "2026-09-09 16:45:00")
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
  .event.start == "2026-09-09 13:15:00" and .event.end == "2026-09-09 16:45:00" and
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
tec_seed_lifecycle_runtime
LIFECYCLE_BEFORE=$(tec_target_storage_fingerprint)
LIFECYCLE_RAW_BEFORE=$(tec_target_main_option_raw_hash)
LIFECYCLE_ACTIVE=$(tec_lifecycle_state)
LIFECYCLE_CAPS_BEFORE=$(printf '%s\n' "$LIFECYCLE_ACTIVE" | jq -c '.roles | with_entries(.value = .value.plugin_caps)')
require_observed_nonempty "TEC lifecycle physical baseline" "$LIFECYCLE_BEFORE"
require_observed_nonempty "TEC lifecycle raw mixed-option baseline" "$LIFECYCLE_RAW_BEFORE"
wp_conf2 plugin deactivate the-events-calendar >/dev/null
wp_conf2 plugin is-active the-events-calendar >/dev/null 2>&1 && fail "TEC deactivation premise did not land"
LIFECYCLE_INACTIVE=$(tec_lifecycle_state)
printf '%s\n' "$LIFECYCLE_INACTIVE" | jq -e '
  .schema_version == "5.16.0" and .legacy_ct1_transient == false and
  .rewrite_rules_rows == 0 and
  .cron == {
    transient_purge:0, trash:0, delete:0, aggregator:0,
    aggregator_single:1, neighbor:1
  } and
  ([.roles[].neighbor] | all) and
  ([.roles[].plugin_caps | length] | add) == 0
' >/dev/null || fail "TEC pre-uninstall deactivation effects drifted: $LIFECYCLE_INACTIVE"
[ "$(tec_target_storage_fingerprint)" = "$LIFECYCLE_BEFORE" ] \
  || fail "TEC deactivation mutated authored, derived, Customizer, settings, or Category Colors rows"
[ "$(tec_target_main_option_raw_hash)" != "$LIFECYCLE_RAW_BEFORE" ] \
  || fail "TEC deactivation did not write the exact env-owned schema-version transition"
ROWS_BEFORE_UNINSTALL=$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_posts WHERE post_type="tribe_events"' --skip-column-names)
wp_conf2 plugin uninstall the-events-calendar >/dev/null
wp_conf2 plugin is-installed the-events-calendar >/dev/null 2>&1 && fail "TEC uninstall left plugin code installed"
[ "$(wp_conf2 db query 'SELECT COUNT(*) FROM wp_posts WHERE post_type="tribe_events"' --skip-column-names)" = "$ROWS_BEFORE_UNINSTALL" ] \
  || fail "TEC empty native uninstall unexpectedly deleted authored event rows"
[ "$(wp_conf2 option get duo_tec_neighbor)" = 'target-neighbor-preserved' ] \
  || fail "TEC uninstall mutated an unrelated target option"
[ "$(tec_target_storage_fingerprint)" = "$LIFECYCLE_BEFORE" ] \
  || fail "TEC empty native uninstall mutated authored, derived, Customizer, settings, or Category Colors rows"
UNINSTALLED_STATE=$(tec_lifecycle_state)
printf '%s\n' "$UNINSTALLED_STATE" | jq -e '
  .schema_version == "5.16.0" and
  .cron.aggregator_single == 1 and .cron.neighbor == 1 and
  ([.roles[].neighbor] | all) and
  ([.roles[].plugin_caps | length] | add) == 0
' >/dev/null || fail "TEC guard-only uninstall mutated exact inactive runtime residue: $UNINSTALLED_STATE"
MISSING_STORAGE_BEFORE=$(tec_target_storage_fingerprint)
MISSING_REPO_BEFORE=$(git -C "$CONF_REPO2" status --porcelain=v1 --untracked-files=all -- state)
MISSING_RC=0
MISSING_OUT=$(wp_conf2 duo deploy --repo=/siterepo 2>&1) || MISSING_RC=$?
require_duo_answered "TEC deploy with code absent" human "$MISSING_OUT"
[ "$MISSING_RC" -ne 0 ] && grep -Eq 'code_mismatch|missing_in_code|is not installed' <<<"$MISSING_OUT" \
  || fail "missing TEC code did not refuse at compatibility: $MISSING_OUT"
[ "$(tec_target_storage_fingerprint)" = "$MISSING_STORAGE_BEFORE" ] \
  || fail "missing-code compatibility refusal mutated retained TEC rows"
[ "$(git -C "$CONF_REPO2" status --porcelain=v1 --untracked-files=all -- state)" = "$MISSING_REPO_BEFORE" ] \
  || fail "missing-code compatibility refusal mutated canonical target state"
TEC_SHA=2db436c929797bfc5311be942158c474716e61c2f289f7d05c3a08d29b2ad687
TEC_ARTIFACT="/artifacts-cache/plugin-the-events-calendar-6.17.3-${TEC_SHA}.zip"
[ "$(wp_conf2 eval "echo hash_file('sha256', '$TEC_ARTIFACT');")" = "$TEC_SHA" ] \
  || fail "cached TEC reinstall artifact digest moved"
wp_conf2 plugin install "$TEC_ARTIFACT" --force >/dev/null
[ "$(wp_conf2 plugin get the-events-calendar --field=version)" = '6.17.3' ] \
  || fail "TEC exact reinstall reported wrong version"
REINSTALL_DEPLOY=$(wp_conf2 duo deploy --repo=/siterepo --format=json | awk 'NF { line=$0 } END { print line }')
require_duo_answered "TEC deploy after exact reinstall" json "$REINSTALL_DEPLOY"
REINSTALLED_STATE=$(tec_lifecycle_state)
REINSTALLED_CAPS=$(printf '%s\n' "$REINSTALLED_STATE" | jq -c '.roles | with_entries(.value = .value.plugin_caps)')
printf '%s\n' "$REINSTALLED_STATE" | jq -e '
  .schema_version == "6.17.3" and ([.roles[].neighbor] | all)
' >/dev/null || fail "TEC exact reinstall did not restore native env state: $REINSTALLED_STATE"
[ "$REINSTALLED_CAPS" = "$LIFECYCLE_CAPS_BEFORE" ] \
  || fail "TEC exact reinstall did not restore the native capability set"
[ "$(tec_target_storage_fingerprint)" = "$LIFECYCLE_BEFORE" ] \
  || fail "TEC exact reinstall mutated persistent authored or derived state"
[ "$(tec_target_main_option_raw_hash)" = "$LIFECYCLE_RAW_BEFORE" ] \
  || fail "TEC exact reinstall did not converge the exact mixed settings row"
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
unset -f tec_target_storage_fingerprint
pass "deactivate/reactivate, residue-preserving uninstall, absent-code refusal, exact reinstall, native readback, and final recapture are clean"
