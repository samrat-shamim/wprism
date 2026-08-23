<?php
declare(strict_types=1);

namespace Duo\Interpreters;

use Duo\Canon;
use Duo\Policy;

/**
 * Exact repository constraints for the free TEC 6.17.2/6.17.3 storage
 * contract. The plugin's custom-table writer trusts these classic meta rows;
 * rejecting incoherent rows before deploy is safer than asking regeneration
 * to manufacture an occurrence from contradictory authored inputs.
 */
final class TheEventsCalendar {
    private const REQUIRED_EVENT_META = [
        '_EventDuration',
        '_EventEndDate',
        '_EventEndDateUTC',
        '_EventStartDate',
        '_EventStartDateUTC',
        '_EventTimezone',
    ];

    public function __construct(Policy $policy) {
        // The complete decision is fixed by the pinned TEC schema. No live
        // plugin or site-policy state may influence repository compilation.
    }

    public function post_meta_rule(string $key, array $allMeta): ?array {
        return null;
    }

    /** @return list<array<string,mixed>> */
    public function repository_diagnostics(array $tree): array {
        $posts = [];
        $events = [];
        $linkedPosts = [];
        $terms = [];
        foreach ($tree as $entity) {
            if (($entity['type'] ?? '') === 'post') {
                $front = $this->post_front($entity);
                $uuid = (string) ($front['uuid'] ?? '');
                if ($uuid !== '') {
                    $posts[$uuid] = (string) ($front['type'] ?? '');
                }
                if (($front['type'] ?? '') === 'tribe_events') {
                    $events[] = [$entity, $front];
                } elseif (in_array(($front['type'] ?? ''), ['tribe_venue', 'tribe_organizer'], true)) {
                    $linkedPosts[] = [$entity, $front];
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

    /** @return array<string,mixed> */
    private function post_front(array $entity): array {
        if (is_array($entity['data'] ?? null)) {
            return $entity['data'];
        }
        return Canon::parse_post_file((string) ($entity['content'] ?? ''))[0];
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
