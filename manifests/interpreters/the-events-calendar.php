<?php
declare(strict_types=1);

namespace Duo\Interpreters;

use Duo\Canon;
use Duo\IdentityTokenCodec;
use Duo\Ledger;
use Duo\PlainData;
use Duo\Policy;
use Duo\Secrets;
use Duo\SidebarState;
use Duo\Tokens;

/**
 * Exact repository constraints for the free TEC 6.17.2/6.17.3 storage
 * contract. The plugin's custom-table writer trusts these classic meta rows;
 * rejecting incoherent rows before deploy is safer than asking regeneration
 * to manufacture an occurrence from contradictory authored inputs.
 */
final class TheEventsCalendar {
    private const CALENDAR_OPTIONS = 'tribe_events_calendar_options';
    private const CUSTOMIZER_CANONICAL_OPTION = 'tribe_customizer';
    private const CUSTOMIZER_LEGACY_OPTION = 'tribe_events_pro_customizer';
    private const SETTINGS_CACHE_KEY = 'Tribe__Settings_Manager:option_cache';
    private const CUSTOMIZER_MAX_NODES = 128;
    private const CUSTOMIZER_MAX_OPTION_BYTES = 65536;
    private const CUSTOMIZER_MAX_SETTING_BYTES = 4096;
    private const CUSTOMIZER_SETTINGS = [
        'global_elements' => [
            'font_family' => 'key',
            'font_size' => 'key',
            'font_size_base' => 'key',
            'event_title_color' => 'color',
            'event_date_time_color' => 'color',
            'link_color' => 'color',
            'background_color_choice' => 'key',
            'background_color' => 'color',
            'accent_color' => 'color',
        ],
        'month_view' => [
            'grid_lines_color' => 'color',
            'grid_hover_color' => 'color',
            'grid_background_color_choice' => 'key',
            'grid_background_color' => 'color',
            'tooltip_background_color' => 'key',
            'days_of_week_color' => 'color',
            'date_marker_color' => 'color',
            'multiday_event_bar_color_choice' => 'key',
            'multiday_event_bar_color' => 'color',
        ],
        'tec_events_bar' => [
            'events_bar_background_color_choice' => 'key',
            'events_bar_background_color' => 'color',
            'events_bar_border_color_choice' => 'key',
            'events_bar_border_color' => 'color',
            'events_bar_icon_color_choice' => 'key',
            'events_bar_icon_color' => 'color',
            'events_bar_text_color' => 'color',
            'find_events_button_color_choice' => 'key',
            'find_events_button_color' => 'color',
            'find_events_button_text_color' => 'color',
        ],
        'single_event' => [
            'post_title_color_choice' => 'key',
            'post_title_color' => 'color',
            'details_bg_color' => 'color',
        ],
    ];
    private const CUSTOMIZER_TARGET_OWNED_SETTINGS = [
        'tec_events_bar' => [
            'view_selector_background_color',
            'view_selector_background_color_choice',
        ],
    ];
    private const LEGACY_WIDGET_BLOCK = 'core/legacy-widget';
    private const LEGACY_WIDGET_CODEC = 'the-events-calendar/v1';
    private const LIST_WIDGET = 'tribe-widget-events-list';
    private const QR_WIDGET = 'tribe-widget-events-qr-code';
    private const WIDGET_HASH_BYTES = 32;
    private const WIDGET_MAX_DEPTH = 6;
    private const WIDGET_MAX_ENCODED_BYTES = 21848;
    private const WIDGET_MAX_KEY_BYTES = 64;
    private const WIDGET_MAX_NODES = 64;
    private const WIDGET_MAX_SERIALIZED_BYTES = 16384;
    private const WIDGET_MAX_STRING_BYTES = 4096;

    private const IMPORT_COLUMN_OPTIONS = [
        'tribe_events_import_column_mapping',
        'tribe_events_import_column_mapping_events',
        'tribe_events_import_column_mapping_organizers',
        'tribe_events_import_column_mapping_venues',
    ];

    private const POWER_AUTOMATE_ENDPOINTS = [
        'attendees',
        'canceled_events',
        'checkin',
        'create_events',
        'new_events',
        'orders',
        'refunded_orders',
        'updated_attendees',
        'updated_events',
    ];

    private const REQUIRED_EVENT_META = [
        '_EventDuration',
        '_EventEndDate',
        '_EventEndDateUTC',
        '_EventStartDate',
        '_EventStartDateUTC',
        '_EventTimezone',
    ];

    private const ZAPIER_ENDPOINTS = [
        'attendees',
        'authorize',
        'canceled_events',
        'checkin',
        'create_events',
        'find_attendees',
        'find_events',
        'find_tickets',
        'new_events',
        'orders',
        'refunded_orders',
        'update_events',
        'updated_attendees',
        'updated_events',
    ];

    public function __construct(Policy $policy) {
        // The complete decision is fixed by the pinned TEC schema. No live
        // plugin or site-policy state may influence repository compilation.
    }

    public function post_meta_rule(string $key, array $allMeta): ?array {
        return null;
    }

    /**
     * Exact computed option-name families bundled in free TEC/Common 6.17.x.
     * Prefix ownership exists to make an extension-added sibling visible;
     * only native endpoint IDs and SHA-256 connection-key shapes classify.
     */
    public function option_rule(string $name, array $allOptions): ?array {
        if (str_starts_with($name, 'tribe_events_import_column_mapping')) {
            if (in_array($name, self::IMPORT_COLUMN_OPTIONS, true)) {
                return ['class' => 'runtime'];
            }
            $this->refuse_computed_option_name('CSV column-mapping', $name);
        }

        $computedFamilies = [
            '_tec_power_automate_endpoint_details_' => ['runtime', self::POWER_AUTOMATE_ENDPOINTS],
            '_tec_zapier_endpoint_details_' => ['runtime', self::ZAPIER_ENDPOINTS],
        ];
        foreach ($computedFamilies as $prefix => [$class, $suffixes]) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            $suffix = substr($name, strlen($prefix));
            if (in_array($suffix, $suffixes, true)) {
                return ['class' => $class];
            }
            $this->refuse_computed_option_name('Event Automator endpoint', $name);
        }

        foreach (['tec_power_automate_connection_', 'tec_zapier_api_key_'] as $prefix) {
            if (!str_starts_with($name, $prefix)) {
                continue;
            }
            $suffix = substr($name, strlen($prefix));
            if (preg_match('/^[a-f0-9]{64}$/D', $suffix) === 1) {
                return ['class' => 'env'];
            }
            $this->refuse_computed_option_name('Event Automator connection', $name);
        }

        return null;
    }

    /**
     * Canonicalize the exact free Views V2 Customizer storage surface. The
     * raw snapshot and primary row are one CaptureTransaction MVCC view;
     * array_key_exists therefore distinguishes a persisted empty canonical
     * map from absence before the legacy compatibility input is considered.
     *
     * @return array<string,mixed>
     */
    public function normalize_captured_option_sub_keys(
        string $name,
        array $rawAuthored,
        array $declaredSubKeys,
        array $rawOptionSnapshot
    ): array {
        if ($name !== self::CUSTOMIZER_CANONICAL_OPTION) {
            return $rawAuthored;
        }
        $this->assert_customizer_sub_key_declaration($declaredSubKeys);

        if (array_key_exists(self::CUSTOMIZER_CANONICAL_OPTION, $rawOptionSnapshot)) {
            $source = $this->decode_customizer_storage(
                $rawOptionSnapshot[self::CUSTOMIZER_CANONICAL_OPTION],
                'canonical'
            );
            // The exact primary row is read separately for its autoload. The
            // product path supplies both reads from one repeatable snapshot;
            // this equality additionally rejects any non-empty disagreement.
            if ($source !== $rawAuthored) {
                throw new \RuntimeException(
                    'duo: The Events Calendar canonical Customizer snapshot disagrees with its exact authored row'
                );
            }
            return $this->normalize_customizer_sparse_map($source);
        }

        if ($rawAuthored !== []) {
            throw new \RuntimeException(
                'duo: The Events Calendar absent canonical Customizer snapshot disagrees with its exact authored row'
            );
        }
        if (!array_key_exists(self::CUSTOMIZER_LEGACY_OPTION, $rawOptionSnapshot)) {
            return [];
        }
        return $this->normalize_customizer_sparse_map($this->decode_customizer_storage(
            $rawOptionSnapshot[self::CUSTOMIZER_LEGACY_OPTION],
            'legacy compatibility'
        ));
    }

    /**
     * Materialize both closed TEC mixed options through one digest-bound
     * owner. Free TEC exposes no whole-Customizer setter: WP_Customize_Setting
     * ultimately replaces the canonical option, so the engine-owned checked
     * row writer is the only hook-free transactional primitive. The nested
     * Events Bar residue is target-owned and therefore merged explicitly.
     */
    public function materialize_option_sub_keys(
        string $name,
        array $captured,
        array $declaredSubKeys,
        string $autoload,
        ?array $targetValue,
        \Closure $lockTargetOption,
        \Closure $finalizeStorage,
        \Closure $restoreStorage,
        ?\Closure $registerRuntimeRestore = null,
        ?\Closure $writeStorage = null
    ): bool {
        if (!in_array($name, [self::CALENDAR_OPTIONS, self::CUSTOMIZER_CANONICAL_OPTION], true)) {
            return false;
        }
        if ($registerRuntimeRestore === null || $writeStorage === null) {
            throw new \RuntimeException(
                'duo: The Events Calendar mixed-option writer lacks engine-owned storage/recovery authority'
            );
        }

        if ($name === self::CUSTOMIZER_CANONICAL_OPTION) {
            $this->assert_customizer_sub_key_declaration($declaredSubKeys);
            $this->assert_customizer_runtime();
            $storage = $this->customizer_materialized_storage($captured, $targetValue);
            $registerRuntimeRestore(static function (): void {});
        } else {
            $storage = $this->ordinary_closed_mixed_storage($captured, $declaredSubKeys, $targetValue);
            $this->invalidate_settings_cache_with_rollback($registerRuntimeRestore);
        }

        $writeStorage($storage);
        $finalizeStorage();
        return true;
    }

    /**
     * Remove only a target-owned nested carrier from the verification view.
     * An explicitly desired empty section remains meaningful; an absent
     * section may disappear even when the same physical residue keeps its
     * container present on the target.
     *
     * @return array<string,mixed>
     */
    public function project_materialized_option_sub_keys(
        string $name,
        array $rawAuthored,
        array $declaredSubKeys,
        array $desiredAuthoredKeys
    ): array {
        if ($name !== self::CUSTOMIZER_CANONICAL_OPTION) {
            return $rawAuthored;
        }
        $this->assert_customizer_sub_key_declaration($declaredSubKeys);
        $desired = array_fill_keys($desiredAuthoredKeys, true);
        $projected = $this->normalize_customizer_sparse_map($rawAuthored);
        foreach ($projected as $section => $settings) {
            if ($settings === [] && !isset($desired[$section])) {
                unset($projected[$section]);
            }
        }
        return $projected;
    }

    /**
     * Capture the two exact core/legacy-widget storage forms used by free TEC.
     * Stored instances bind to SidebarState's durable widget identity; copied
     * instances are decoded as bounded plain data and lose their source salt.
     *
     * @return array<string,mixed>
     */
    public function capture_block_attributes(
        array $block,
        Tokens $tokens,
        bool $forceUnresolvedRefs = false,
        string $postLabel = ''
    ): array {
        $this->assert_widget_block_shell($block);
        $attrs = $this->widget_attrs($block);
        if ($attrs === []) {
            return [];
        }

        if (array_key_exists('id', $attrs)) {
            $this->assert_exact_keys($attrs, ['id'], 'stored legacy widget attributes');
            [$idBase, $localId] = $this->physical_widget_id($attrs['id']);
            $uuid = Ledger::uuid_for($localId, SidebarState::kind($idBase));
            if ($uuid === null) {
                throw new \RuntimeException(
                    'duo: The Events Calendar stored legacy widget has no durable SidebarState identity'
                );
            }
            return [
                'id' => '{{widget:' . $uuid . '}}',
                'idBase' => $idBase,
            ];
        }

        if (!array_key_exists('idBase', $attrs)) {
            throw new \RuntimeException(
                'duo: The Events Calendar legacy widget must use the exact stored-id or embedded-instance form'
            );
        }
        $idBase = $this->widget_id_base($attrs['idBase']);
        if (!array_key_exists('instance', $attrs) || $attrs['instance'] === null) {
            $this->assert_exact_keys(
                $attrs,
                array_key_exists('instance', $attrs) ? ['idBase', 'instance'] : ['idBase'],
                'empty embedded legacy widget attributes'
            );
            return ['idBase' => $idBase];
        }

        $this->assert_exact_keys($attrs, ['idBase', 'instance'], 'embedded legacy widget attributes');
        if (!is_array($attrs['instance']) || array_is_list($attrs['instance'])) {
            throw new \RuntimeException(
                'duo: The Events Calendar embedded legacy widget instance must be one closed attribute object'
            );
        }
        $instance = $attrs['instance'];
        $this->assert_exact_keys($instance, ['encoded', 'hash'], 'embedded legacy widget instance');
        if (!is_string($instance['encoded'])
            || strlen($instance['encoded']) > self::WIDGET_MAX_ENCODED_BYTES
            || $instance['encoded'] === ''
            || preg_match('/^(?:[A-Za-z0-9+\/]{4})*(?:[A-Za-z0-9+\/]{2}==|[A-Za-z0-9+\/]{3}=)?$/D', $instance['encoded']) !== 1) {
            throw new \RuntimeException(
                'duo: The Events Calendar embedded legacy widget payload is not bounded canonical base64'
            );
        }
        $serialized = base64_decode($instance['encoded'], true);
        if (!is_string($serialized)
            || base64_encode($serialized) !== $instance['encoded']
            || strlen($serialized) > self::WIDGET_MAX_SERIALIZED_BYTES) {
            throw new \RuntimeException(
                'duo: The Events Calendar embedded legacy widget payload exceeds or violates its storage grammar'
            );
        }
        $this->assert_widget_hash($serialized, $instance['hash']);
        $settings = PlainData::decode_serialized($serialized, 'The Events Calendar embedded legacy widget');
        if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            throw new \RuntimeException(
                'duo: The Events Calendar embedded legacy widget must decode to one plain settings object'
            );
        }
        $this->assert_bounded_widget_value($settings, 'embedded legacy widget settings');
        $portable = $this->capture_widget_settings($idBase, $settings, $tokens);

        return [
            'idBase' => $idBase,
            'instance' => [
                'duo' => self::LEGACY_WIDGET_CODEC,
                'settings' => $portable,
            ],
        ];
    }

    /**
     * Materialize canonical TEC legacy-widget attributes for the target.
     * The only generated payload is the bounded native instance plus a fresh
     * target wp_hash; source encoded bytes and hashes are never retained.
     *
     * @return array<string,mixed>
     */
    public function apply_block_attributes(array $block, Tokens $tokens): array {
        $this->assert_widget_block_shell($block);
        $attrs = $this->widget_attrs($block);
        if ($attrs === []) {
            return [];
        }

        if (array_key_exists('id', $attrs)) {
            $this->assert_exact_keys($attrs, ['id', 'idBase'], 'canonical stored legacy widget attributes');
            $idBase = $this->widget_id_base($attrs['idBase']);
            if (!is_string($attrs['id'])
                || preg_match('/^\{\{widget:([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\}\}$/D', $attrs['id'], $match) !== 1) {
                throw new \RuntimeException(
                    'duo: The Events Calendar stored legacy widget identity must be one canonical widget token'
                );
            }
            $localId = Ledger::id_for($match[1], SidebarState::kind($idBase));
            if ($localId === null || $localId <= 0) {
                throw new \RuntimeException(
                    'duo: The Events Calendar stored legacy widget identity is not bound on the target'
                );
            }
            return ['id' => $idBase . '-' . $localId];
        }

        if (!array_key_exists('idBase', $attrs)) {
            throw new \RuntimeException(
                'duo: The Events Calendar canonical legacy widget has no reviewed identity form'
            );
        }
        $idBase = $this->widget_id_base($attrs['idBase']);
        if (!array_key_exists('instance', $attrs)) {
            $this->assert_exact_keys($attrs, ['idBase'], 'canonical empty legacy widget attributes');
            return ['idBase' => $idBase];
        }

        $this->assert_exact_keys($attrs, ['idBase', 'instance'], 'canonical embedded legacy widget attributes');
        if (!is_array($attrs['instance']) || array_is_list($attrs['instance'])) {
            throw new \RuntimeException(
                'duo: The Events Calendar canonical embedded widget instance is malformed'
            );
        }
        $instance = $attrs['instance'];
        $this->assert_exact_keys($instance, ['duo', 'settings'], 'canonical embedded legacy widget instance');
        if (($instance['duo'] ?? null) !== self::LEGACY_WIDGET_CODEC
            || !is_array($instance['settings'])
            || ($instance['settings'] !== [] && array_is_list($instance['settings']))) {
            throw new \RuntimeException(
                'duo: The Events Calendar canonical embedded widget codec marker or settings object is invalid'
            );
        }
        $this->assert_bounded_widget_value($instance['settings'], 'canonical embedded widget settings');
        $settings = $this->apply_widget_settings($idBase, $instance['settings'], $tokens);
        $serialized = serialize($settings);
        if (strlen($serialized) > self::WIDGET_MAX_SERIALIZED_BYTES) {
            throw new \RuntimeException(
                'duo: The Events Calendar target widget instance exceeds the reviewed storage budget'
            );
        }
        $hash = $this->widget_hash($serialized);

        return [
            'idBase' => $idBase,
            'instance' => [
                'encoded' => base64_encode($serialized),
                'hash' => $hash,
            ],
        ];
    }

    /** @return list<array<string,mixed>> */
    public function repository_diagnostics(array $tree): array {
        $posts = [];
        $postEntities = [];
        $events = [];
        $linkedPosts = [];
        $terms = [];
        $widgets = [];
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') === 'post') {
                $front = $this->post_front($entity);
                $uuid = (string) ($front['uuid'] ?? '');
                if ($uuid !== '') {
                    $posts[$uuid] = (string) ($front['type'] ?? '');
                }
                $postEntities[] = [$entity, $front];
                if (($front['type'] ?? '') === 'tribe_events') {
                    $events[] = [$entity, $front];
                } elseif (in_array(($front['type'] ?? ''), ['tribe_venue', 'tribe_organizer'], true)) {
                    $linkedPosts[] = [$entity, $front];
                }
                continue;
            }
            if (($entity['type'] ?? '') === SidebarState::ENTITY_TYPE) {
                foreach ((array) (($entity['data']['widgets'] ?? [])) as $widget) {
                    $uuid = (string) ($widget['uuid'] ?? '');
                    $type = (string) ($widget['type'] ?? '');
                    if ($uuid !== '') {
                        $widgets[$uuid] = $type;
                    }
                }
                continue;
            }
            if (($entity['type'] ?? '') === 'term') {
                $data = (array) ($entity['data'] ?? []);
                if (($data['taxonomy'] ?? '') === 'tribe_events_cat') {
                    $terms[] = [$entity, $data];
                }
            }
        }

        $out = [];
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') !== SidebarState::ENTITY_TYPE) {
                continue;
            }
            $path = (string) ($entity['path'] ?? '');
            foreach ((array) (($entity['data']['widgets'] ?? [])) as $index => $widget) {
                $type = (string) ($widget['type'] ?? '');
                if (!in_array($type, [self::LIST_WIDGET, self::QR_WIDGET], true)) {
                    continue;
                }
                $settings = $widget['settings'] ?? null;
                if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
                    $out[] = $this->diagnostic(
                        $path,
                        "widgets[$index].settings",
                        'The Events Calendar widget settings must be one closed canonical object'
                    );
                    continue;
                }
                foreach ($this->repository_widget_settings_diagnostics(
                    $type,
                    $settings,
                    $posts,
                    $path,
                    "widgets[$index].settings"
                ) as $diagnostic) {
                    $out[] = $diagnostic;
                }
            }
        }
        foreach ($postEntities as [$entity]) {
            foreach ($this->legacy_widget_block_diagnostics(
                $entity,
                $posts,
                $widgets,
                (string) ($entity['path'] ?? '')
            ) as $diagnostic) {
                $out[] = $diagnostic;
            }
        }
        foreach ($events as [$entity, $front]) {
            $path = (string) ($entity['path'] ?? '');
            $meta = (array) ($front['meta'] ?? []);
            foreach (self::REQUIRED_EVENT_META as $key) {
                if (!array_key_exists($key, $meta) || !is_string($meta[$key]) || $meta[$key] === '') {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        "The Events Calendar event requires a non-empty string $key value"
                    );
                }
            }

            $timezone = $this->timezone($meta['_EventTimezone'] ?? null);
            if ($timezone === null && array_key_exists('_EventTimezone', $meta)) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._EventTimezone',
                    'The Events Calendar event timezone must be one exact PHP timezone identifier'
                );
            }
            $start = $this->date($meta['_EventStartDate'] ?? null, $timezone);
            $end = $this->date($meta['_EventEndDate'] ?? null, $timezone);
            $startUtc = $this->date($meta['_EventStartDateUTC'] ?? null, new \DateTimeZone('UTC'));
            $endUtc = $this->date($meta['_EventEndDateUTC'] ?? null, new \DateTimeZone('UTC'));
            foreach ([
                '_EventStartDate' => $start,
                '_EventEndDate' => $end,
                '_EventStartDateUTC' => $startUtc,
                '_EventEndDateUTC' => $endUtc,
            ] as $key => $parsed) {
                if (array_key_exists($key, $meta) && $parsed === null) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        "The Events Calendar $key must use an exact real Y-m-d H:i:s date"
                    );
                }
            }

            if ($start !== null && $end !== null && $end < $start) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._EventEndDate',
                    'The Events Calendar event end must not precede its start'
                );
            }
            if ($start !== null && $startUtc !== null && $start->getTimestamp() !== $startUtc->getTimestamp()) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._EventStartDateUTC',
                    'The Events Calendar local and UTC start instants disagree'
                );
            }
            if ($end !== null && $endUtc !== null && $end->getTimestamp() !== $endUtc->getTimestamp()) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._EventEndDateUTC',
                    'The Events Calendar local and UTC end instants disagree'
                );
            }

            $duration = $meta['_EventDuration'] ?? null;
            if (!is_string($duration) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $duration) !== 1) {
                if (array_key_exists('_EventDuration', $meta)) {
                    $out[] = $this->diagnostic(
                        $path,
                        'meta._EventDuration',
                        'The Events Calendar duration must be canonical non-negative decimal seconds'
                    );
                }
            } elseif ($startUtc !== null && $endUtc !== null
                && (int) $duration !== $endUtc->getTimestamp() - $startUtc->getTimestamp()) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._EventDuration',
                    'The Events Calendar duration disagrees with the authored UTC interval'
                );
            }

            if (array_key_exists('_EventVenueID', $meta)) {
                $token = $meta['_EventVenueID'];
                if (!is_string($token)
                    || preg_match('/^\{\{post:([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\}\}$/D', $token, $m) !== 1) {
                    $out[] = $this->diagnostic(
                        $path,
                        'meta._EventVenueID',
                        'The Events Calendar _EventVenueID must be one canonical post UUID token'
                    );
                } elseif (isset($posts[$m[1]]) && $posts[$m[1]] !== 'tribe_venue') {
                    $out[] = $this->diagnostic(
                        $path,
                        'meta._EventVenueID',
                        "The Events Calendar _EventVenueID must resolve to post type tribe_venue, not {$posts[$m[1]]}"
                    );
                }
            }
            if (array_key_exists('_EventOrganizerID', $meta)) {
                $organizers = $meta['_EventOrganizerID'];
                if (!is_array($organizers) || !array_is_list($organizers) || $organizers === []) {
                    $out[] = $this->diagnostic(
                        $path,
                        'meta._EventOrganizerID',
                        'The Events Calendar _EventOrganizerID must be a non-empty ordered list of canonical post UUID tokens'
                    );
                } else {
                    $seenOrganizers = [];
                    foreach ($organizers as $i => $organizerToken) {
                        if (!is_string($organizerToken)
                            || preg_match('/^\{\{post:([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\}\}$/D', $organizerToken, $m) !== 1) {
                            $out[] = $this->diagnostic(
                                $path,
                                "meta._EventOrganizerID[$i]",
                                'The Events Calendar each organizer row must be one canonical post UUID token'
                            );
                            continue;
                        }
                        if (isset($seenOrganizers[$organizerToken])) {
                            $out[] = $this->diagnostic(
                                $path,
                                "meta._EventOrganizerID[$i]",
                                'The Events Calendar organizer rows must be unique in native physical order'
                            );
                        }
                        $seenOrganizers[$organizerToken] = true;
                        if (isset($posts[$m[1]]) && $posts[$m[1]] !== 'tribe_organizer') {
                            $out[] = $this->diagnostic(
                                $path,
                                "meta._EventOrganizerID[$i]",
                                "The Events Calendar organizer row must resolve to post type tribe_organizer, not {$posts[$m[1]]}"
                            );
                        }
                    }
                }
            }
            foreach ($this->organizer_block_diagnostics($entity, $meta, $posts, $path) as $diagnostic) {
                $out[] = $diagnostic;
            }

            $hasStatus = array_key_exists('_tribe_events_status', $meta);
            $hasStatusReason = array_key_exists('_tribe_events_status_reason', $meta);
            if ($hasStatus && (!is_string($meta['_tribe_events_status'])
                || !in_array($meta['_tribe_events_status'], ['canceled', 'postponed'], true))) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._tribe_events_status',
                    'The Events Calendar stored event status must be canceled or postponed; scheduled is represented by absence'
                );
            }
            if ($hasStatusReason && !is_string($meta['_tribe_events_status_reason'])) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._tribe_events_status_reason',
                    'The Events Calendar event status reason must remain one scalar string'
                );
            }
            if ($hasStatus !== $hasStatusReason) {
                $out[] = $this->diagnostic(
                    $path,
                    $hasStatus ? 'meta._tribe_events_status_reason' : 'meta._tribe_events_status',
                    'The Events Calendar status and reason rows must be present or absent together'
                );
            }

            foreach (['_EventShowMap', '_EventShowMapLink'] as $key) {
                if (array_key_exists($key, $meta) && !in_array($meta[$key], ['', '1'], true)) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        "The Events Calendar event $key must use the repository's exact empty/1 wire value"
                    );
                }
            }
            // Editor/Meta.php:27 + common Editor/Meta.php:178 persist
            // Gutenberg booleans as 1/empty, while API.php:369-373 persists
            // the public classic API's same states as yes/no. Both exact
            // 6.17.2/6.17.3 paths are current authored storage, not aliases.
            if (array_key_exists('_EventAllDay', $meta)
                && !in_array($meta['_EventAllDay'], ['', '1', 'no', 'yes'], true)) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._EventAllDay',
                    'The Events Calendar _EventAllDay must be absent or use an exact current empty/1/no/yes wire value'
                );
            }
            // API.php:180 plus its bool-typed public helper persist 1/empty;
            // Repository/Event.php:1480-1485 and the classic checkbox persist
            // yes/absence. Unlike _EventAllDay, no native path normalizes no.
            if (array_key_exists('_EventHideFromUpcoming', $meta)
                && !in_array($meta['_EventHideFromUpcoming'], ['', '1', 'yes'], true)) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._EventHideFromUpcoming',
                    'The Events Calendar _EventHideFromUpcoming must be absent or use an exact current empty/1/yes wire value'
                );
            }
            if (array_key_exists('_tribe_featured', $meta) && $meta['_tribe_featured'] !== '1') {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._tribe_featured',
                    "The Events Calendar featured flag must be absent or use the plugin's exact 1 wire value"
                );
            }
            if (array_key_exists('_EventCurrencyPosition', $meta)
                && !in_array($meta['_EventCurrencyPosition'], ['prefix', 'postfix'], true)) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._EventCurrencyPosition',
                    'The Events Calendar currency position must be prefix or postfix'
                );
            }
            foreach (array_keys($meta) as $key) {
                if (str_starts_with((string) $key, '_EventRecurrence')) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        'The Events Calendar Pro recurrence state is outside the free-plugin adapter contract'
                    );
                }
            }
            foreach (['_tribe_aggregator_global_id', '_tribe_legacy_ignored_event'] as $key) {
                if (array_key_exists($key, $meta)) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        'The Events Calendar Event Aggregator/import state is outside the free-plugin authored contract'
                    );
                }
            }
            foreach (['_VenueLat', '_VenueLng', '_VenueOverwriteCoords'] as $key) {
                if (array_key_exists($key, $meta)) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        'The Events Calendar Pro/Event Aggregator coordinate state is outside the free-plugin adapter contract'
                    );
                }
            }
            foreach (['_VenueShowMap', '_VenueShowMapLink'] as $key) {
                if (array_key_exists($key, $meta)) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        "The Events Calendar $key belongs only to a tribe_venue post"
                    );
                }
            }
            foreach ([
                '_EventCost', '_EventCostDescription', '_EventCostMax', '_EventCostMin',
                '_EventCurrencyCode', '_EventCurrencyPosition', '_EventCurrencySymbol',
                '_EventDateTimeSeparator', '_EventOrigin', '_EventPhone', '_EventTimeRangeSeparator',
                '_EventTimezoneAbbr', '_EventURL',
            ] as $key) {
                if (array_key_exists($key, $meta) && !is_string($meta[$key])) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        "The Events Calendar $key must remain one scalar string, not structured or serialized data"
                    );
                }
            }
            if (isset($meta['_EventCostDescription'])
                && is_string($meta['_EventCostDescription'])
                && !$this->is_sanitized_text_field($meta['_EventCostDescription'])) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta._EventCostDescription',
                    'The Events Calendar _EventCostDescription must already match its native sanitize_text_field shape'
                );
            }
            foreach (['_EventDateTimeSeparator', '_EventTimeRangeSeparator'] as $key) {
                if (isset($meta[$key]) && is_string($meta[$key]) && !$this->is_sanitized_separator($meta[$key])) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        "The Events Calendar $key must already match its native separator sanitizer shape"
                    );
                }
            }
        }

        $linkedStringMeta = [
            'tribe_venue' => [
                '_VenueAddress', '_VenueCity', '_VenueCountry', '_VenueOrigin', '_VenuePhone',
                '_VenueProvince', '_VenueState', '_VenueStateProvince', '_VenueURL', '_VenueZip',
            ],
            'tribe_organizer' => ['_OrganizerEmail', '_OrganizerOrigin', '_OrganizerPhone', '_OrganizerWebsite'],
        ];
        foreach ($linkedPosts as [$entity, $front]) {
            $path = (string) ($entity['path'] ?? '');
            $meta = (array) ($front['meta'] ?? []);
            $postType = (string) $front['type'];
            foreach ($linkedStringMeta[$postType] as $key) {
                if (array_key_exists($key, $meta) && !is_string($meta[$key])) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        "The Events Calendar $key must remain one scalar string, not structured or serialized data"
                    );
                }
            }
            foreach (['_VenueLat', '_VenueLng', '_VenueOverwriteCoords'] as $key) {
                if (array_key_exists($key, $meta)) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        'The Events Calendar Pro/Event Aggregator coordinate state is outside the free-plugin adapter contract'
                    );
                }
            }
            $mapKeys = ['_EventShowMap', '_EventShowMapLink', '_VenueShowMap', '_VenueShowMapLink'];
            if ($postType === 'tribe_venue') {
                foreach ($mapKeys as $key) {
                    if (array_key_exists($key, $meta) && !in_array($meta[$key], ['', '1', 'false'], true)) {
                        $out[] = $this->diagnostic(
                            $path,
                            "meta.$key",
                            "The Events Calendar venue $key must use the native empty/1/false wire value"
                        );
                    }
                }
            } else {
                foreach ($mapKeys as $key) {
                    if (array_key_exists($key, $meta)) {
                        $out[] = $this->diagnostic(
                            $path,
                            "meta.$key",
                            "The Events Calendar $key does not belong to a tribe_organizer post"
                        );
                    }
                }
            }
            foreach (['_tribe_events_status', '_tribe_events_status_reason'] as $key) {
                if (array_key_exists($key, $meta)) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        "The Events Calendar $key belongs only to a tribe_events post"
                    );
                }
            }
        }

        foreach ($terms as [$entity, $data]) {
            $path = (string) ($entity['path'] ?? '');
            $meta = (array) ($data['meta'] ?? []);
            foreach (['primary', 'secondary', 'text'] as $suffix) {
                $key = 'tec-events-cat-colors-' . $suffix;
                if (array_key_exists($key, $meta)
                    && (!is_string($meta[$key]) || preg_match('/^#[0-9a-fA-F]{6}$/D', $meta[$key]) !== 1)) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        "The Events Calendar category color $suffix must be one six-digit hex color"
                    );
                }
            }
            $priority = $meta['tec-events-cat-colors-priority'] ?? null;
            if ($priority !== null
                && (!is_string($priority) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $priority) !== 1)) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta.tec-events-cat-colors-priority',
                    'The Events Calendar category color priority must be canonical non-negative decimal'
                );
            }
            $hidden = $meta['tec-events-cat-colors-hidden'] ?? null;
            if ($hidden !== null && !in_array($hidden, ['', '0', '1'], true)) {
                $out[] = $this->diagnostic(
                    $path,
                    'meta.tec-events-cat-colors-hidden',
                    'The Events Calendar category hidden flag must use its exact empty/0/1 wire value'
                );
            }
        }
        return $out;
    }

    /** @return list<array{code:string,path:string,locator:string,message:string}> */
    private function legacy_widget_block_diagnostics(
        array $entity,
        array $posts,
        array $widgets,
        string $path
    ): array {
        $body = $this->post_body($entity);
        $markerCount = $this->legacy_widget_marker_count($body);
        if ($markerCount === 0) {
            return [];
        }
        if (!function_exists('parse_blocks')) {
            return [$this->diagnostic(
                $path,
                'body.core/legacy-widget',
                'The Events Calendar legacy-widget contract requires the native WordPress block parser'
            )];
        }
        $blocks = [];
        $walk = static function (array $nodes) use (&$walk, &$blocks): void {
            foreach ($nodes as $node) {
                if (($node['blockName'] ?? null) === self::LEGACY_WIDGET_BLOCK) {
                    $blocks[] = $node;
                }
                if (is_array($node['innerBlocks'] ?? null) && $node['innerBlocks'] !== []) {
                    $walk($node['innerBlocks']);
                }
            }
        };
        $walk(parse_blocks($body));
        if (count($blocks) !== $markerCount) {
            return [$this->diagnostic(
                $path,
                'body.core/legacy-widget',
                'The Events Calendar legacy-widget markup must parse as exact registered blocks'
            )];
        }

        $out = [];
        foreach ($blocks as $index => $block) {
            $locator = "body.core/legacy-widget[$index]";
            try {
                $this->assert_widget_block_shell($block);
                $attrs = $this->widget_attrs($block);
            } catch (\Throwable) {
                $out[] = $this->diagnostic(
                    $path,
                    $locator,
                    'The Events Calendar legacy widget must remain one exact self-closing canonical block'
                );
                continue;
            }
            if ($attrs === []) {
                continue;
            }
            if (array_key_exists('id', $attrs)) {
                try {
                    $this->assert_exact_keys($attrs, ['id', 'idBase'], 'canonical stored legacy widget attributes');
                    $idBase = $this->widget_id_base($attrs['idBase']);
                } catch (\Throwable) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.attrs",
                        'The Events Calendar stored legacy widget canonical shape is invalid'
                    );
                    continue;
                }
                if (!is_string($attrs['id'])
                    || preg_match('/^\{\{widget:([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\}\}$/D', $attrs['id'], $match) !== 1) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.attrs.id",
                        'The Events Calendar stored legacy widget identity must be one canonical widget token'
                    );
                } elseif (!isset($widgets[$match[1]])) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.attrs.id",
                        'The Events Calendar stored legacy widget identity must resolve to one captured sidebar widget'
                    );
                } elseif ($widgets[$match[1]] !== $idBase) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.attrs.idBase",
                        'The Events Calendar stored legacy widget identity and idBase disagree'
                    );
                }
                continue;
            }

            if (!array_key_exists('idBase', $attrs)) {
                $out[] = $this->diagnostic(
                    $path,
                    "$locator.attrs",
                    'The Events Calendar legacy widget has no reviewed canonical identity form'
                );
                continue;
            }
            try {
                $idBase = $this->widget_id_base($attrs['idBase']);
            } catch (\Throwable) {
                $out[] = $this->diagnostic(
                    $path,
                    "$locator.attrs.idBase",
                    'The Events Calendar legacy widget idBase is outside the exact free-plugin registry'
                );
                continue;
            }
            if (!array_key_exists('instance', $attrs)) {
                try {
                    $this->assert_exact_keys($attrs, ['idBase'], 'canonical empty legacy widget attributes');
                } catch (\Throwable) {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.attrs",
                        'The Events Calendar empty embedded widget carries an undeclared attribute'
                    );
                }
                continue;
            }
            try {
                $this->assert_exact_keys($attrs, ['idBase', 'instance'], 'canonical embedded legacy widget attributes');
                if (!is_array($attrs['instance']) || array_is_list($attrs['instance'])) {
                    throw new \RuntimeException('invalid instance');
                }
                $instance = $attrs['instance'];
                $this->assert_exact_keys($instance, ['duo', 'settings'], 'canonical embedded legacy widget instance');
                if (($instance['duo'] ?? null) !== self::LEGACY_WIDGET_CODEC
                    || !is_array($instance['settings'])
                    || ($instance['settings'] !== [] && array_is_list($instance['settings']))) {
                    throw new \RuntimeException('invalid codec settings');
                }
                $this->assert_bounded_widget_value($instance['settings'], 'canonical embedded widget settings');
            } catch (\Throwable) {
                $out[] = $this->diagnostic(
                    $path,
                    "$locator.attrs.instance",
                    'The Events Calendar embedded legacy widget canonical envelope is invalid'
                );
                continue;
            }
            foreach ($this->repository_widget_settings_diagnostics(
                $idBase,
                $instance['settings'],
                $posts,
                $path,
                "$locator.attrs.instance.settings"
            ) as $diagnostic) {
                $out[] = $diagnostic;
            }
        }
        return $out;
    }

    /** @return list<array{code:string,path:string,locator:string,message:string}> */
    private function repository_widget_settings_diagnostics(
        string $idBase,
        array $settings,
        array $posts,
        string $path,
        string $locator
    ): array {
        $out = [];
        $allowed = $idBase === self::LIST_WIDGET
            ? ['title', 'limit', 'no_upcoming_events', 'featured_events_only', 'jsonld_enable', 'tribe_is_list_widget']
            : ['widget_title', 'qr_code_size', 'redirection', 'event_id', 'series_id'];
        try {
            $this->assert_known_setting_keys($settings, $allowed, 'canonical');
            $this->assert_bounded_widget_value($settings, 'canonical widget settings');
        } catch (\Throwable) {
            return [$this->diagnostic(
                $path,
                $locator,
                'The Events Calendar widget settings exceed or escape the closed native schema'
            )];
        }
        foreach ($settings as $key => $value) {
            $valid = true;
            if (in_array($key, ['title', 'widget_title'], true)) {
                try {
                    $this->assert_widget_title($value, $key);
                } catch (\Throwable) {
                    $valid = false;
                }
            } elseif ($key === 'limit') {
                try {
                    $this->widget_limit($value);
                } catch (\Throwable) {
                    $valid = false;
                }
            } elseif (in_array($key, ['no_upcoming_events', 'featured_events_only', 'jsonld_enable', 'tribe_is_list_widget'], true)) {
                $valid = is_bool($value);
            } elseif ($key === 'qr_code_size') {
                $valid = is_string($value) && in_array($value, ['4', '8', '12', '16', '20', '24', '28'], true);
            } elseif ($key === 'redirection') {
                $valid = is_string($value) && in_array($value, ['current', 'upcoming', 'specific'], true);
            } elseif ($key === 'series_id') {
                $valid = $value === null;
            } elseif ($key === 'event_id') {
                if ($value === null) {
                    $valid = true;
                } elseif (!is_string($value)
                    || preg_match('/^\{\{post:([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\}\}$/D', $value, $match) !== 1) {
                    $valid = false;
                } elseif (!isset($posts[$match[1]]) || $posts[$match[1]] !== 'tribe_events') {
                    $out[] = $this->diagnostic(
                        $path,
                        "$locator.event_id",
                        'The Events Calendar QR widget event token must resolve to one captured tribe_events post'
                    );
                    continue;
                }
            }
            if (!$valid) {
                $out[] = $this->diagnostic(
                    $path,
                    "$locator.$key",
                    'The Events Calendar widget setting violates its exact native scalar contract'
                );
            }
        }
        if (($settings['redirection'] ?? null) === 'specific' && ($settings['event_id'] ?? null) === null) {
            $out[] = $this->diagnostic(
                $path,
                "$locator.event_id",
                'The Events Calendar QR widget specific redirection requires one event token'
            );
        }
        return $out;
    }

    private function legacy_widget_marker_count(string $body): int {
        $opening = '<!--';
        // Core block comments omit the `core/` namespace even though the
        // native parser restores blockName=core/legacy-widget.
        $name = 'wp:legacy-widget';
        $whitespace = " \t\r\n\f\v";
        $offset = 0;
        $count = 0;
        $length = strlen($body);
        while ($offset < $length && ($start = strpos($body, $opening, $offset)) !== false) {
            $end = strpos($body, '-->', $start + strlen($opening));
            if ($end === false) {
                break;
            }
            $cursor = $start + strlen($opening);
            if ($cursor < $end && str_contains($whitespace, $body[$cursor])) {
                $cursor += strspn($body, $whitespace, $cursor, $end - $cursor);
                if ($cursor + strlen($name) <= $end
                    && substr_compare($body, $name, $cursor, strlen($name)) === 0) {
                    $cursor += strlen($name);
                    if ($cursor < $end
                        && ($body[$cursor] === '/' || str_contains($whitespace, $body[$cursor]))) {
                        ++$count;
                    }
                }
            }
            $offset = $end + 3;
        }
        return $count;
    }

    private function assert_widget_block_shell(array $block): void {
        if (($block['blockName'] ?? null) !== self::LEGACY_WIDGET_BLOCK
            || !is_array($block['innerBlocks'] ?? null)
            || ($block['innerBlocks'] ?? []) !== []
            || !is_string($block['innerHTML'] ?? null)
            || trim((string) $block['innerHTML']) !== ''
            || !is_array($block['innerContent'] ?? null)
            || ($block['innerContent'] ?? []) !== []) {
            throw new \RuntimeException(
                'duo: The Events Calendar legacy widget must be one exact self-closing core block'
            );
        }
    }

    /** @return array<string,mixed> */
    private function widget_attrs(array $block): array {
        $attrs = $block['attrs'] ?? null;
        if (!is_array($attrs) || ($attrs !== [] && array_is_list($attrs))) {
            throw new \RuntimeException(
                'duo: The Events Calendar legacy widget attributes must be one closed object'
            );
        }
        foreach (array_keys($attrs) as $key) {
            if (!is_string($key)) {
                throw new \RuntimeException(
                    'duo: The Events Calendar legacy widget attributes contain a non-string key'
                );
            }
        }
        return $attrs;
    }

    private function assert_exact_keys(array $value, array $expected, string $context): void {
        $actual = array_keys($value);
        foreach ($actual as $key) {
            if (!is_string($key)) {
                throw new \RuntimeException("duo: The Events Calendar $context has a non-string key");
            }
        }
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("duo: The Events Calendar $context has an unknown or missing field");
        }
    }

    private function widget_id_base(mixed $value): string {
        if (!is_string($value) || !in_array($value, [self::LIST_WIDGET, self::QR_WIDGET], true)) {
            throw new \RuntimeException(
                'duo: The Events Calendar legacy widget idBase is not one exact free-plugin widget type'
            );
        }
        return $value;
    }

    /** @return array{string,int} */
    private function physical_widget_id(mixed $value): array {
        if (!is_string($value)
            || preg_match(
                '/^(' . preg_quote(self::LIST_WIDGET, '/') . '|' . preg_quote(self::QR_WIDGET, '/')
                . ')-([1-9][0-9]*)$/D',
                $value,
                $match
            ) !== 1
            || (string) (int) $match[2] !== $match[2]
            || (int) $match[2] <= 0) {
            throw new \RuntimeException(
                'duo: The Events Calendar stored legacy widget id must use one supported idBase and canonical positive instance'
            );
        }
        return [$match[1], (int) $match[2]];
    }

    private function assert_widget_hash(string $serialized, mixed $hash): void {
        if (!is_string($hash)
            || strlen($hash) !== self::WIDGET_HASH_BYTES
            || preg_match('/^[a-f0-9]{32}$/D', $hash) !== 1
            || !hash_equals($this->widget_hash($serialized), $hash)) {
            throw new \RuntimeException(
                'duo: The Events Calendar embedded legacy widget source hash is missing or invalid'
            );
        }
    }

    private function widget_hash(string $serialized): string {
        if (!function_exists('wp_hash')) {
            throw new \RuntimeException(
                'duo: The Events Calendar legacy widget codec requires the native WordPress hash service'
            );
        }
        $hash = wp_hash($serialized);
        if (!is_string($hash)
            || strlen($hash) !== self::WIDGET_HASH_BYTES
            || preg_match('/^[a-f0-9]{32}$/D', $hash) !== 1) {
            throw new \RuntimeException(
                'duo: The Events Calendar legacy widget hash service returned an unsupported receipt'
            );
        }
        return $hash;
    }

    private function assert_bounded_widget_value(mixed $value, string $context): void {
        $nodes = 0;
        $walk = function (mixed $current, int $depth) use (&$walk, &$nodes, $context): void {
            ++$nodes;
            if ($nodes > self::WIDGET_MAX_NODES || $depth > self::WIDGET_MAX_DEPTH) {
                throw new \RuntimeException(
                    "duo: The Events Calendar $context exceeds its closed depth or node budget"
                );
            }
            if (is_object($current) || is_resource($current) || is_float($current)) {
                throw new \RuntimeException(
                    "duo: The Events Calendar $context contains an unsupported value type"
                );
            }
            if (is_string($current)) {
                $this->assert_safe_widget_string($current, $context);
                return;
            }
            if (!is_array($current)) {
                return;
            }
            foreach ($current as $key => $child) {
                if (!is_string($key) || $key === '' || strlen($key) > self::WIDGET_MAX_KEY_BYTES) {
                    throw new \RuntimeException(
                        "duo: The Events Calendar $context contains an invalid settings key"
                    );
                }
                if (class_exists('ReflectionReference')
                    && \ReflectionReference::fromArrayElement($current, $key) !== null) {
                    throw new \RuntimeException(
                        "duo: The Events Calendar $context contains a PHP reference"
                    );
                }
                $walk($child, $depth + 1);
            }
        };
        $walk($value, 0);
    }

    private function assert_safe_widget_string(string $value, string $context): void {
        if (strlen($value) > self::WIDGET_MAX_STRING_BYTES
            || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/D', $value) === 1) {
            throw new \RuntimeException(
                "duo: The Events Calendar $context contains an over-budget or invalid text value"
            );
        }
        if (Secrets::hard_match($value) !== null) {
            throw new \RuntimeException(
                "duo: The Events Calendar $context contains a hard credential; refusing capture"
            );
        }
        if (preg_match('/(?:javascript|data)\s*:/iD', $value) === 1
            || preg_match('#https?://[^/\s:@]+:[^/\s@]+@#iD', $value) === 1) {
            throw new \RuntimeException(
                "duo: The Events Calendar $context contains an unsafe URL shape"
            );
        }
    }

    /** @return array<string,mixed> */
    private function capture_widget_settings(string $idBase, array $settings, Tokens $tokens): array {
        $allowed = $idBase === self::LIST_WIDGET
            ? ['title', 'limit', 'no_upcoming_events', 'featured_events_only', 'jsonld_enable', 'tribe_is_list_widget']
            : ['widget_title', 'qr_code_size', 'redirection', 'event_id', 'series_id'];
        $this->assert_known_setting_keys($settings, $allowed, 'physical');
        $out = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            $value = $settings[$key];
            if (($idBase === self::LIST_WIDGET && $key === 'title')
                || ($idBase === self::QR_WIDGET && $key === 'widget_title')) {
                $this->assert_widget_title($value, $key);
                $out[$key] = $tokens->tokenize_text($value, "TEC legacy widget $key");
                continue;
            }
            if ($key === 'limit') {
                $out[$key] = $this->widget_limit($value);
                continue;
            }
            if (in_array($key, ['no_upcoming_events', 'featured_events_only', 'jsonld_enable', 'tribe_is_list_widget'], true)) {
                if (!is_bool($value)) {
                    throw new \RuntimeException(
                        "duo: The Events Calendar list widget setting '$key' must be a native boolean"
                    );
                }
                $out[$key] = $value;
                continue;
            }
            if ($key === 'qr_code_size') {
                if (!is_string($value) || !in_array($value, ['4', '8', '12', '16', '20', '24', '28'], true)) {
                    throw new \RuntimeException(
                        'duo: The Events Calendar QR widget size is outside its exact native menu'
                    );
                }
                $out[$key] = $value;
                continue;
            }
            if ($key === 'redirection') {
                if (!is_string($value) || !in_array($value, ['current', 'upcoming', 'specific'], true)) {
                    throw new \RuntimeException(
                        'duo: The Events Calendar QR widget redirection is outside its exact native menu'
                    );
                }
                $out[$key] = $value;
                continue;
            }
            if ($key === 'event_id') {
                $id = $this->native_widget_reference_id($value, 'event_id');
                $out[$key] = $id === null ? null : ($tokens->id_to_token($id, 'post')
                    ?? throw new \RuntimeException(
                        'duo: The Events Calendar QR widget event reference is not managed by this repository'
                    ));
                continue;
            }
            if ($key === 'series_id') {
                if ($this->native_widget_reference_id($value, 'series_id') !== null) {
                    throw new \RuntimeException(
                        'duo: The Events Calendar QR widget series reference requires the licensed recurrence surface'
                    );
                }
                $out[$key] = null;
            }
        }
        if (($out['redirection'] ?? null) === 'specific' && ($out['event_id'] ?? null) === null) {
            throw new \RuntimeException(
                'duo: The Events Calendar QR widget specific redirection requires one managed event'
            );
        }
        return $out;
    }

    /** @return array<string,mixed> */
    private function apply_widget_settings(string $idBase, array $settings, Tokens $tokens): array {
        $allowed = $idBase === self::LIST_WIDGET
            ? ['title', 'limit', 'no_upcoming_events', 'featured_events_only', 'jsonld_enable', 'tribe_is_list_widget']
            : ['widget_title', 'qr_code_size', 'redirection', 'event_id', 'series_id'];
        $this->assert_known_setting_keys($settings, $allowed, 'canonical');
        $out = [];
        foreach ($allowed as $key) {
            if (!array_key_exists($key, $settings)) {
                continue;
            }
            $value = $settings[$key];
            if (($idBase === self::LIST_WIDGET && $key === 'title')
                || ($idBase === self::QR_WIDGET && $key === 'widget_title')) {
                if (!is_string($value)) {
                    throw new \RuntimeException(
                        "duo: The Events Calendar canonical widget setting '$key' must be a string"
                    );
                }
                $value = $tokens->detokenize_text($value);
                $this->assert_widget_title($value, $key);
                $out[$key] = $value;
                continue;
            }
            if ($key === 'limit') {
                $out[$key] = $this->widget_limit($value);
                continue;
            }
            if (in_array($key, ['no_upcoming_events', 'featured_events_only', 'jsonld_enable', 'tribe_is_list_widget'], true)) {
                if (!is_bool($value)) {
                    throw new \RuntimeException(
                        "duo: The Events Calendar canonical list widget setting '$key' must be boolean"
                    );
                }
                $out[$key] = $value;
                continue;
            }
            if ($key === 'qr_code_size') {
                if (!is_string($value) || !in_array($value, ['4', '8', '12', '16', '20', '24', '28'], true)) {
                    throw new \RuntimeException('duo: The Events Calendar canonical QR widget size is invalid');
                }
                $out[$key] = $value;
                continue;
            }
            if ($key === 'redirection') {
                if (!is_string($value) || !in_array($value, ['current', 'upcoming', 'specific'], true)) {
                    throw new \RuntimeException('duo: The Events Calendar canonical QR widget redirection is invalid');
                }
                $out[$key] = $value;
                continue;
            }
            if ($key === 'event_id') {
                if ($value === null) {
                    $out[$key] = 0;
                    continue;
                }
                if (!is_string($value)) {
                    throw new \RuntimeException(
                        'duo: The Events Calendar canonical QR widget event must be null or one post token'
                    );
                }
                $decoded = IdentityTokenCodec::decode($value);
                if ($decoded['kind'] !== 'post') {
                    throw new \RuntimeException(
                        'duo: The Events Calendar canonical QR widget event must use the post keyspace'
                    );
                }
                $out[$key] = $tokens->token_to_id($value);
                continue;
            }
            if ($key === 'series_id') {
                if ($value !== null) {
                    throw new \RuntimeException(
                        'duo: The Events Calendar canonical QR widget series state is outside the free adapter'
                    );
                }
                $out[$key] = 0;
            }
        }
        if (($settings['redirection'] ?? null) === 'specific' && ($settings['event_id'] ?? null) === null) {
            throw new \RuntimeException(
                'duo: The Events Calendar canonical QR widget specific redirection requires one event token'
            );
        }
        $this->assert_bounded_widget_value($out, 'target embedded widget settings');
        return $out;
    }

    private function assert_known_setting_keys(array $settings, array $allowed, string $form): void {
        foreach (array_keys($settings) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \RuntimeException(
                    "duo: The Events Calendar $form legacy widget contains an undeclared setting"
                );
            }
        }
    }

    private function assert_widget_title(mixed $value, string $key): void {
        if (!is_string($value) || !function_exists('wp_strip_all_tags')) {
            throw new \RuntimeException(
                "duo: The Events Calendar widget setting '$key' requires one native sanitized string"
            );
        }
        $this->assert_safe_widget_string($value, "widget setting '$key'");
        if (wp_strip_all_tags($value) !== $value
            || preg_match('/^(?:[aOsidbCE]:|N;)/D', trim($value)) === 1) {
            throw new \RuntimeException(
                "duo: The Events Calendar widget setting '$key' is not one native plain-text value"
            );
        }
    }

    private function widget_limit(mixed $value): int|string {
        if (is_int($value) && $value >= 1 && $value <= 10) {
            return $value;
        }
        if (is_string($value)
            && preg_match('/^(?:[1-9]|10)$/D', $value) === 1) {
            return $value;
        }
        throw new \RuntimeException(
            'duo: The Events Calendar list widget limit must be the exact native integer range 1..10'
        );
    }

    private function native_widget_reference_id(mixed $value, string $key): ?int {
        if (in_array($value, [null, '', 0, '0'], true)) {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value)
            && preg_match('/^[1-9][0-9]*$/D', $value) === 1
            && (string) (int) $value === $value
            && (int) $value > 0) {
            return (int) $value;
        }
        throw new \RuntimeException(
            "duo: The Events Calendar QR widget '$key' must be an exact positive id or native unset value"
        );
    }

    /** @return array<string,mixed> */
    private function post_front(array $entity): array {
        if (is_array($entity['data'] ?? null)) {
            return $entity['data'];
        }
        return Canon::parse_post_file((string) ($entity['content'] ?? ''))[0];
    }

    private function post_body(array $entity): string {
        if (is_string($entity['body'] ?? null)) {
            return $entity['body'];
        }
        return Canon::parse_post_file((string) ($entity['content'] ?? ''))[1];
    }

    /**
     * @param array<string,mixed> $meta
     * @param array<string,string> $posts
     * @return list<array{code:string,path:string,locator:string,message:string}>
     */
    private function organizer_block_diagnostics(array $entity, array $meta, array $posts, string $path): array {
        $body = $this->post_body($entity);
        $markerCount = $this->organizer_block_marker_count($body);
        if ($markerCount === 0) {
            // Classic-editor events own organizer rows without block markup.
            return [];
        }
        if (!function_exists('parse_blocks')) {
            return [$this->diagnostic(
                $path,
                'body.tribe/event-organizer',
                'The Events Calendar organizer block contract requires the native WordPress block parser'
            )];
        }

        $organizerBlocks = [];
        $walk = static function (
            array $blocks,
            int $depth = 0,
            ?string $parent = null
        ) use (&$walk, &$organizerBlocks): void {
            foreach ($blocks as $block) {
                if (($block['blockName'] ?? null) === 'tribe/event-organizer') {
                    $organizerBlocks[] = [
                        'block' => $block,
                        'depth' => $depth,
                        'parent' => $parent,
                    ];
                }
                if (is_array($block['innerBlocks'] ?? null) && $block['innerBlocks'] !== []) {
                    $name = is_string($block['blockName'] ?? null) ? $block['blockName'] : null;
                    $walk($block['innerBlocks'], $depth + 1, $name);
                }
            }
        };
        $walk(parse_blocks($body));

        $out = [];
        if (count($organizerBlocks) !== $markerCount) {
            $out[] = $this->diagnostic(
                $path,
                'body.tribe/event-organizer',
                'The Events Calendar organizer block markup must parse as exact registered blocks'
            );
            return $out;
        }

        $populated = [];
        $seen = [];
        $shapeClean = true;
        foreach ($organizerBlocks as $i => $record) {
            $block = $record['block'];
            // Linked_Posts.php:1008-1024 only supplements ordering from
            // top-level blocks. Nested registered blocks remain portable when
            // their canonical attributes and authoritative repeated rows agree;
            // retaining depth here makes that reviewed limitation explicit.
            $blockLocator = "body.tribe/event-organizer[$i]"
                . ($record['depth'] > 0 ? '.nested' : '');
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
            if (array_key_exists('organizers', $attrs)) {
                $shapeClean = false;
                $out[] = $this->diagnostic(
                    $path,
                    "$blockLocator.organizers",
                    'The Events Calendar organizers list is meta-sourced and must not be serialized into block content'
                );
            }
            if (($block['innerBlocks'] ?? []) !== [] || trim((string) ($block['innerHTML'] ?? '')) !== '') {
                $shapeClean = false;
                $out[] = $this->diagnostic(
                    $path,
                    $blockLocator,
                    'The Events Calendar dynamic organizer block must not carry nested blocks or authored inner HTML'
                );
            }
            if (!array_key_exists('organizer', $attrs)) {
                // The registered default is null; an empty editor placeholder
                // serializes without the attribute and renders no organizer.
                continue;
            }

            $token = $attrs['organizer'];
            if (!is_string($token)
                || preg_match('/^\{\{post:([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\}\}$/D', $token, $m) !== 1) {
                $shapeClean = false;
                $out[] = $this->diagnostic(
                    $path,
                    "$blockLocator.organizer",
                    'The Events Calendar populated organizer block must contain one canonical post UUID token'
                );
                continue;
            }
            if (isset($seen[$token])) {
                $shapeClean = false;
                $out[] = $this->diagnostic(
                    $path,
                    "$blockLocator.organizer",
                    'The Events Calendar populated organizer blocks must be unique in editor order'
                );
            }
            $seen[$token] = true;
            $populated[] = $token;
            if (!isset($posts[$m[1]])) {
                $shapeClean = false;
                $out[] = $this->diagnostic(
                    $path,
                    "$blockLocator.organizer",
                    'The Events Calendar organizer block UUID must resolve to one captured tribe_organizer post'
                );
            } elseif ($posts[$m[1]] !== 'tribe_organizer') {
                $shapeClean = false;
                $out[] = $this->diagnostic(
                    $path,
                    "$blockLocator.organizer",
                    "The Events Calendar organizer block must resolve to post type tribe_organizer, not {$posts[$m[1]]}"
                );
            }
        }

        $expected = $meta['_EventOrganizerID'] ?? [];
        $expectedClean = is_array($expected) && array_is_list($expected);
        foreach ($expectedClean ? $expected : [] as $token) {
            if (!is_string($token)
                || preg_match('/^\{\{post:[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\}\}$/D', $token) !== 1) {
                $expectedClean = false;
                break;
            }
        }
        if ($shapeClean && $expectedClean && $populated !== $expected) {
            $out[] = $this->diagnostic(
                $path,
                'body.tribe/event-organizer',
                'The Events Calendar populated organizer block order must exactly match _EventOrganizerID row order'
            );
        }
        return $out;
    }

    /**
     * Count complete exact organizer opening comments without a regex resource
     * failure becoming the indistinguishable classic-editor zero-block case.
     */
    private function organizer_block_marker_count(string $body): int {
        $opening = '<!--';
        $name = 'wp:tribe/event-organizer';
        $whitespace = " \t\r\n\f\v";
        $offset = 0;
        $count = 0;
        $length = strlen($body);
        while ($offset < $length && ($start = strpos($body, $opening, $offset)) !== false) {
            $end = strpos($body, '-->', $start + strlen($opening));
            if ($end === false) {
                break;
            }
            $cursor = $start + strlen($opening);
            if ($cursor < $end && str_contains($whitespace, $body[$cursor])) {
                $cursor += strspn($body, $whitespace, $cursor, $end - $cursor);
                if ($cursor + strlen($name) <= $end
                    && substr_compare($body, $name, $cursor, strlen($name)) === 0) {
                    $cursor += strlen($name);
                    if ($cursor < $end
                        && ($body[$cursor] === '/' || str_contains($whitespace, $body[$cursor]))) {
                        ++$count;
                    }
                }
            }
            $offset = $end + 3;
        }
        return $count;
    }

    private function timezone(mixed $value): ?\DateTimeZone {
        if (!is_string($value) || $value === '') {
            return null;
        }
        try {
            return new \DateTimeZone($value);
        } catch (\Throwable) {
            return null;
        }
    }

    private function date(mixed $value, ?\DateTimeZone $timezone): ?\DateTimeImmutable {
        if (!is_string($value) || $timezone === null) {
            return null;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $timezone);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d H:i:s') !== $value) {
            return null;
        }
        return $date;
    }

    private function is_sanitized_text_field(string $value): bool {
        if (preg_match('//u', $value) !== 1
            || str_contains($value, '<')
            || preg_match('/%[a-f0-9]{2}/iD', $value) === 1
            || preg_match('/[\x00-\x1f\x7f]/D', $value) === 1
            || preg_match('/ {2,}/D', $value) === 1) {
            return false;
        }
        return trim($value) === $value;
    }

    private function is_sanitized_separator(string $value): bool {
        return preg_match('//u', $value) === 1
            && strip_tags(htmlspecialchars_decode($value, ENT_QUOTES)) === $value;
    }

    /** @return array<string,mixed> */
    private function ordinary_closed_mixed_storage(
        array $captured,
        array $declaredSubKeys,
        ?array $targetValue
    ): array {
        $storage = $targetValue ?? [];
        PlainData::assert($storage, 'The Events Calendar target mixed option');
        if ($storage !== [] && array_is_list($storage)) {
            throw new \RuntimeException(
                'duo: The Events Calendar target mixed option is not an object-shaped sibling map'
            );
        }
        foreach ($declaredSubKeys as $subKey => $subRule) {
            if (($subRule['class'] ?? null) === 'authored') {
                unset($storage[(string) $subKey]);
            }
        }
        foreach ($captured as $subKey => $value) {
            if (!is_string($subKey)
                || (($declaredSubKeys[$subKey]['class'] ?? null) !== 'authored')) {
                throw new \RuntimeException(
                    'duo: The Events Calendar mixed option contains an undeclared/non-authored desired sibling'
                );
            }
            $storage[$subKey] = $value;
        }
        return $storage;
    }

    /** @return array<string,mixed> */
    private function customizer_materialized_storage(array $captured, ?array $targetValue): array {
        if ($this->normalize_customizer_sparse_map($captured) !== $captured) {
            throw new \RuntimeException(
                'duo: The Events Calendar desired Customizer map is not in exact native-normalized form'
            );
        }

        $residue = [];
        if ($targetValue !== null) {
            // The validation result deliberately is not the merge base: all
            // server-owned settings are authored and must be replaced or
            // deleted. Only the two reviewed JS-era values survive.
            $this->normalize_customizer_sparse_map($targetValue);
            foreach (self::CUSTOMIZER_TARGET_OWNED_SETTINGS as $section => $settings) {
                $targetSection = $targetValue[$section] ?? null;
                if (!is_array($targetSection)) {
                    continue;
                }
                foreach ($settings as $setting) {
                    if (array_key_exists($setting, $targetSection)) {
                        $residue[$section][$setting] = $targetSection[$setting];
                    }
                }
            }
        }

        $storage = $captured;
        foreach ($residue as $section => $settings) {
            if (!array_key_exists($section, $storage)) {
                $storage[$section] = [];
            }
            foreach ($settings as $setting => $value) {
                $storage[$section][$setting] = $value;
            }
        }
        return $storage;
    }

    /**
     * The adapter reads raw rows, but site behavior still depends on TEC's
     * exact canonical-option singleton and its sole legacy fallback callback.
     * Extensions on any value/identity hook can make those bytes mean
     * something else, so refuse that topology before the checked row write.
     */
    private function assert_customizer_runtime(): void {
        if (!function_exists('tribe')) {
            throw new \RuntimeException('duo: The Events Calendar Customizer service is unavailable');
        }
        try {
            $customizer = tribe('customizer');
            $sameCustomizer = tribe('customizer');
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: The Events Calendar Customizer service lookup failed',
                0,
                $failure
            );
        }
        if (!is_object($customizer)
            || get_class($customizer) !== 'Tribe__Customizer'
            || $sameCustomizer !== $customizer
            || !isset($customizer->ID)
            || $customizer->ID !== self::CUSTOMIZER_CANONICAL_OPTION) {
            throw new \RuntimeException(
                'duo: The Events Calendar Customizer service identity was extended or overridden'
            );
        }

        global $wp_filter;
        $fallbackHook = is_array($wp_filter ?? null)
            ? ($wp_filter['default_option_' . self::CUSTOMIZER_CANONICAL_OPTION] ?? null)
            : null;
        if (!is_object($fallbackHook)
            || get_class($fallbackHook) !== 'WP_Hook'
            || !is_array($fallbackHook->callbacks ?? null)) {
            throw new \RuntimeException(
                'duo: The Events Calendar Customizer fallback hook is absent or malformed'
            );
        }
        $callbacks = [];
        foreach ($fallbackHook->callbacks as $priority => $records) {
            if (!is_array($records)) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Customizer fallback callback topology is malformed'
                );
            }
            foreach ($records as $record) {
                $callbacks[] = [$priority, $record];
            }
        }
        $record = $callbacks[0][1] ?? null;
        $callback = is_array($record) ? ($record['function'] ?? null) : null;
        if (count($callbacks) !== 1
            || ($callbacks[0][0] ?? null) !== 10
            || !is_array($record)
            || array_keys($record) !== ['function', 'accepted_args']
            || ($record['accepted_args'] ?? null) !== 1
            || !is_array($callback)
            || count($callback) !== 2
            || ($callback[0] ?? null) !== $customizer
            || ($callback[1] ?? null) !== 'maybe_fallback_get_option') {
            throw new \RuntimeException(
                'duo: The Events Calendar Customizer fallback callback topology was extended or overridden'
            );
        }

        foreach ([
            'tribe_events_pro_customizer_is_active',
            'tribe_customizer_is_active',
            'tribe_customizer_panel_id',
            'tribe_events_pro_customizer_pre_get_option',
            'tribe_customizer_pre_get_option',
            'tribe_customizer_get_option',
            'pre_option_' . self::CUSTOMIZER_CANONICAL_OPTION,
            'option_' . self::CUSTOMIZER_CANONICAL_OPTION,
            'pre_option_' . self::CUSTOMIZER_LEGACY_OPTION,
            'default_option_' . self::CUSTOMIZER_LEGACY_OPTION,
            'option_' . self::CUSTOMIZER_LEGACY_OPTION,
        ] as $hookName) {
            $hook = is_array($wp_filter ?? null) ? ($wp_filter[$hookName] ?? null) : null;
            if (is_object($hook)
                && is_array($hook->callbacks ?? null)
                && $hook->callbacks !== []) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Customizer value/identity hook topology is extended'
                );
            }
        }
    }

    /**
     * Raw writes intentionally bypass updated_option. Forget TEC's request-
     * local Settings Manager cache so post-commit reads load durable bytes;
     * rollback restores its exact prior presence/value before DB rollback.
     */
    private function invalidate_settings_cache_with_rollback(\Closure $registerRuntimeRestore): void {
        global $wp_filter;
        $updatedHook = is_array($wp_filter ?? null) ? ($wp_filter['updated_option'] ?? null) : null;
        $matches = [];
        if (is_object($updatedHook)
            && get_class($updatedHook) === 'WP_Hook'
            && is_array($updatedHook->callbacks ?? null)) {
            foreach ($updatedHook->callbacks as $priority => $records) {
                if (!is_array($records)) {
                    continue;
                }
                foreach ($records as $record) {
                    $callback = is_array($record) ? ($record['function'] ?? null) : null;
                    if (is_array($callback)
                        && is_object($callback[0] ?? null)
                        && get_class($callback[0]) === 'Tribe__Settings_Manager'
                        && ($callback[1] ?? null) === 'update_options_cache') {
                        $matches[] = [$priority, $record, $callback[0]];
                    }
                }
            }
        }
        if (count($matches) !== 1
            || ($matches[0][0] ?? null) !== 10
            || (($matches[0][1]['accepted_args'] ?? null) !== 3)
            || !class_exists('Tribe__Settings_Manager', false)
            || !method_exists('Tribe__Settings_Manager', 'instance')) {
            throw new \RuntimeException(
                'duo: The Events Calendar settings-cache callback topology is absent or extended'
            );
        }
        $manager = \Tribe__Settings_Manager::instance();
        if (!is_object($manager)
            || get_class($manager) !== 'Tribe__Settings_Manager'
            || ($matches[0][2] ?? null) !== $manager) {
            throw new \RuntimeException(
                'duo: The Events Calendar settings-cache singleton identity was substituted'
            );
        }
        foreach (['tribe_isset_var', 'tribe_get_var', 'tribe_set_var', 'tribe_unset_var'] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException(
                    'duo: The Events Calendar settings-cache primitive is unavailable'
                );
            }
        }

        $wasPresent = tribe_isset_var(self::SETTINGS_CACHE_KEY);
        $before = $wasPresent ? tribe_get_var(self::SETTINGS_CACHE_KEY) : null;
        $registerRuntimeRestore(static function () use ($wasPresent, $before): void {
            if ($wasPresent) {
                tribe_set_var(self::SETTINGS_CACHE_KEY, $before);
                if (!tribe_isset_var(self::SETTINGS_CACHE_KEY)
                    || tribe_get_var(self::SETTINGS_CACHE_KEY) !== $before) {
                    throw new \RuntimeException(
                        'duo: The Events Calendar settings cache could not restore its exact prior value'
                    );
                }
                return;
            }
            tribe_unset_var(self::SETTINGS_CACHE_KEY);
            if (tribe_isset_var(self::SETTINGS_CACHE_KEY)) {
                throw new \RuntimeException(
                    'duo: The Events Calendar settings cache could not restore exact absence'
                );
            }
        });
        tribe_unset_var(self::SETTINGS_CACHE_KEY);
        if (tribe_isset_var(self::SETTINGS_CACHE_KEY)) {
            throw new \RuntimeException(
                'duo: The Events Calendar settings cache could not be invalidated before storage materialization'
            );
        }
    }

    /** @param array<string,mixed> $declaredSubKeys */
    private function assert_customizer_sub_key_declaration(array $declaredSubKeys): void {
        $actual = array_keys($declaredSubKeys);
        $expected = array_keys(self::CUSTOMIZER_SETTINGS);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException(
                'duo: The Events Calendar Customizer declaration is not the exact four-section free-plugin registry'
            );
        }
        foreach ($expected as $section) {
            $rule = $declaredSubKeys[$section] ?? null;
            $keys = is_array($rule) ? array_keys($rule) : [];
            sort($keys, SORT_STRING);
            if (!is_array($rule)
                || $keys !== ['class', 'plain_data']
                || ($rule['class'] ?? null) !== 'authored'
                || ($rule['plain_data'] ?? null) !== true) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Customizer section declaration must use the exact authored plain-data grammar'
                );
            }
        }
    }

    /** @return array<string,mixed> */
    private function decode_customizer_storage(mixed $raw, string $source): array {
        if (!is_string($raw)
            || $raw !== trim($raw)
            || strlen($raw) > self::CUSTOMIZER_MAX_OPTION_BYTES) {
            throw new \RuntimeException(
                "duo: The Events Calendar $source Customizer storage is not bounded canonical serialized data"
            );
        }
        $decoded = PlainData::decode_serialized(
            $raw,
            "The Events Calendar $source Customizer storage"
        );
        if (!is_array($decoded) || ($decoded !== [] && array_is_list($decoded))) {
            throw new \RuntimeException(
                "duo: The Events Calendar $source Customizer storage is not a sparse section map"
            );
        }
        return $decoded;
    }

    /** @return array<string,array<string,string>> */
    private function normalize_customizer_sparse_map(array $raw): array {
        PlainData::assert($raw, 'The Events Calendar Customizer storage');
        $nodes = 0;
        $bytes = 0;
        $this->measure_customizer_value($raw, 0, $nodes, $bytes);
        if (Secrets::hard_match_deep($raw) !== null) {
            throw new \RuntimeException(
                'duo: The Events Calendar Customizer storage contains a credential-shaped value'
            );
        }
        if ($raw !== [] && array_is_list($raw)) {
            throw new \RuntimeException(
                'duo: The Events Calendar Customizer storage is not a sparse section map'
            );
        }

        $normalized = [];
        foreach ($raw as $section => $settings) {
            if (!is_string($section) || !array_key_exists($section, self::CUSTOMIZER_SETTINGS)) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Customizer storage contains an undeclared section'
                );
            }
            if (!is_array($settings) || ($settings !== [] && array_is_list($settings))) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Customizer section is not a sparse setting map'
                );
            }

            $normalized[$section] = [];
            foreach ($settings as $setting => $value) {
                if (!is_string($setting) || !is_string($value)) {
                    throw new \RuntimeException(
                        'duo: The Events Calendar Customizer setting is not scalar text'
                    );
                }
                if (in_array(
                    $setting,
                    self::CUSTOMIZER_TARGET_OWNED_SETTINGS[$section] ?? [],
                    true
                )) {
                    continue;
                }
                $sanitizer = self::CUSTOMIZER_SETTINGS[$section][$setting] ?? null;
                if ($sanitizer === null) {
                    throw new \RuntimeException(
                        'duo: The Events Calendar Customizer section contains an undeclared setting'
                    );
                }
                if ($sanitizer === 'key') {
                    $sanitized = preg_replace('/[^a-z0-9_\-]/', '', strtolower($value));
                    if (!is_string($sanitized)) {
                        throw new \RuntimeException(
                            'duo: The Events Calendar Customizer key sanitizer failed'
                        );
                    }
                } elseif ($sanitizer === 'color') {
                    if ($value !== '' && preg_match('/^#(?:[A-Fa-f0-9]{3}){1,2}$/D', $value) !== 1) {
                        throw new \RuntimeException(
                            'duo: The Events Calendar Customizer color is outside the native sanitizer grammar'
                        );
                    }
                    $sanitized = $value;
                } else {
                    throw new \RuntimeException(
                        'duo: The Events Calendar Customizer setting uses an unreviewed sanitizer'
                    );
                }
                $normalized[$section][$setting] = $sanitized;
            }
        }
        return $normalized;
    }

    private function measure_customizer_value(
        mixed $value,
        int $depth,
        int &$nodes,
        int &$bytes
    ): void {
        ++$nodes;
        if ($nodes > self::CUSTOMIZER_MAX_NODES || $depth > 2) {
            throw new \RuntimeException(
                'duo: The Events Calendar Customizer map exceeds the bounded shape frontier'
            );
        }
        if (is_string($value)) {
            if (strlen($value) > self::CUSTOMIZER_MAX_SETTING_BYTES) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Customizer setting exceeds the bounded byte frontier'
                );
            }
            if (preg_match('//u', $value) !== 1) {
                throw new \RuntimeException(
                    'duo: The Events Calendar Customizer setting is not valid UTF-8'
                );
            }
            $bytes += strlen($value);
        } elseif (is_array($value)) {
            foreach ($value as $key => $child) {
                if (!is_string($key) || preg_match('//u', $key) !== 1) {
                    throw new \RuntimeException(
                        'duo: The Events Calendar Customizer map contains a malformed key'
                    );
                }
                $bytes += strlen($key);
                $this->measure_customizer_value($child, $depth + 1, $nodes, $bytes);
            }
        } else {
            throw new \RuntimeException(
                'duo: The Events Calendar Customizer map contains a non-string leaf'
            );
        }
        if ($bytes > self::CUSTOMIZER_MAX_OPTION_BYTES) {
            throw new \RuntimeException(
                'duo: The Events Calendar Customizer map exceeds the bounded byte frontier'
            );
        }
    }

    private function refuse_computed_option_name(string $family, string $name): never {
        $fingerprint = 'string:' . strlen($name) . ':' . substr(hash('sha256', $name), 0, 16);
        throw new \RuntimeException(
            "duo: The Events Calendar encountered an unsupported $family option name ($fingerprint); "
            . 'the exact free 6.17.2/6.17.3 computed-name registry is closed'
        );
    }

    /** @return array{code:string,path:string,locator:string,message:string} */
    private function diagnostic(string $path, string $locator, string $message): array {
        return [
            'code' => 'adapter_schema_content_mismatch',
            'path' => $path,
            'locator' => $locator,
            'message' => $message,
        ];
    }
}
