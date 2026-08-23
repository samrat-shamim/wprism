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

            foreach (['_EventVenueID' => 'tribe_venue', '_EventOrganizerID' => 'tribe_organizer'] as $key => $type) {
                if (!array_key_exists($key, $meta)) {
                    continue;
                }
                $token = $meta[$key];
                if (!is_string($token)
                    || preg_match('/^\{\{post:([0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12})\}\}$/D', $token, $m) !== 1) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        "The Events Calendar $key must be one canonical post UUID token"
                    );
                    continue;
                }
                if (isset($posts[$m[1]]) && $posts[$m[1]] !== $type) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        "The Events Calendar $key must resolve to post type $type, not {$posts[$m[1]]}"
                    );
                }
            }

            foreach (['_EventShowMap', '_EventShowMapLink'] as $key) {
                if (array_key_exists($key, $meta) && !in_array($meta[$key], ['0', '1'], true)) {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        "The Events Calendar $key must use the plugin's exact 0/1 wire value"
                    );
                }
            }
            foreach (['_EventAllDay', '_EventHideFromUpcoming'] as $key) {
                if (array_key_exists($key, $meta) && $meta[$key] !== 'yes') {
                    $out[] = $this->diagnostic(
                        $path,
                        "meta.$key",
                        "The Events Calendar $key must be absent or use the plugin's exact yes wire value"
                    );
                }
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
