<?php
namespace Duo\Regenerators;

use Duo\Policy;

/**
 * TEC (The Events Calendar) regenerator — DUO-3234, task #124's proving
 * fixture. Wraps the exact, verified-live two-call synthesis path
 * `TEC\Events\Custom_Tables\V1\Migration\Strategies\Single_Event_Migration_
 * Strategy::apply()` itself uses (that class is built for the SAME recovery
 * scenario this mechanism needs — a `tribe_events` post whose custom-table
 * rows are missing or stale — so this regenerator mirrors its own two calls
 * rather than inventing a new path), read from the real TEC 6.17.2 source
 * (src/Events/Custom_Tables/V1/Migration/Strategies/Single_Event_Migration_
 * Strategy.php:70-100), not guessed from the class name alone:
 *
 *   $upserted = Event::upsert(['post_id'], Event::data_from_post($post_id));
 *   // upsert() returns FALSE (not an exception) on failure — checked
 *   // explicitly, mirroring Single_Event_Migration_Strategy's own check.
 *   $event = Event::find($post_id, 'post_id');
 *   // find() can return non-Event on a miss — checked via instanceof,
 *   // again mirroring the plugin's own verified-live guard.
 *   $event->occurrences()->save_occurrences();
 *
 * `Event extends Model`, and Model::__callStatic()
 * (src/Events/Custom_Tables/V1/Models/Model.php) proxies undefined static
 * calls to an internal Builder — upsert(), find(), and last_errors() are
 * ALL dispatched this way, invisible to method_exists() even though
 * calling them works. A pre-check that used method_exists() on those
 * names was tried against a real, working TEC install and produced a
 * false-positive "not found" for upsert() — confirmed empirically, not
 * assumed (see this task's design comment for the exact error). This file
 * now pre-checks only data_from_post(), the one method confirmed (grep on
 * Event.php itself) to be a real, directly-declared static method; every
 * other call below — upsert(), last_errors(), find(), the instance-side
 * occurrences()/save_occurrences() (whose real-vs-magic status was never
 * independently confirmed either way) — is unchecked and relies on
 * Apply::regen_dependencies()'s own try/catch to wrap whatever \Error or
 * \RuntimeException surfaces. That's deliberate: guessing wrong about
 * real-vs-magic in either direction is worse than a slightly less
 * custom-tailored failure message on a genuine break.
 */
final class TheEventsCalendar {
    private Policy $policy;

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    public function regenerate(int $localId): void {
        $eventClass = '\\TEC\\Events\\Custom_Tables\\V1\\Models\\Event';
        if (!class_exists($eventClass)) {
            throw new \RuntimeException(
                "duo: TEC regenerator: $eventClass not found — The Events Calendar's Custom Tables v1 "
                . 'architecture may have been restructured in a version outside this manifest\'s pinned range'
            );
        }
        if (!method_exists($eventClass, 'data_from_post')) {
            throw new \RuntimeException(
                "duo: TEC regenerator: $eventClass::data_from_post() not found — cannot regenerate tec_occurrences"
            );
        }

        $eventData = $eventClass::data_from_post($localId);
        if (!is_array($eventData)) {
            throw new \RuntimeException(
                'duo: TEC Event::data_from_post() returned a non-array value'
            );
        }
        $upserted = $eventClass::upsert(['post_id'], $eventData);
        if ($upserted === false) {
            $errors = (array) $eventClass::last_errors();
            throw new \RuntimeException(
                'duo: TEC Event::upsert() failed with ' . count($errors) . ' model error(s)'
            );
        }

        $event = $eventClass::find($localId, 'post_id');
        if (!($event instanceof $eventClass)) {
            throw new \RuntimeException(
                "duo: TEC Event::find(\$localId, 'post_id') could not locate the just-upserted event"
            );
        }
        $eventId = $event->event_id;
        if (!is_int($eventId) || $eventId <= 0) {
            throw new \RuntimeException(
                'duo: TEC Event::find() returned an event without one positive integer event_id'
            );
        }
        $event->occurrences()->save_occurrences();

        global $wpdb;
        $eventTable = $wpdb->prefix . 'tec_events';
        // Native model calls can handle a driver error and leave the public
        // wpdb field populated. Only this exact read may decide its outcome.
        $wpdb->last_error = '';
        $eventRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_id, post_id, start_date, end_date, start_date_utc, end_date_utc, "
                . "timezone, duration, updated_at, hash FROM `$eventTable` "
                . 'WHERE post_id = %d OR event_id = %d ORDER BY event_id',
                $localId,
                $eventId
            ),
            ARRAY_A
        );
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException(
                'duo: TEC derived-state verification query failed for tec_events'
            );
        }
        if (!is_array($eventRows)) {
            throw new \RuntimeException(
                'duo: TEC derived-state verification query returned a non-array for tec_events'
            );
        }
        $expectedDuration = $this->expectedDuration($localId);
        $expectedEvent = [
            'event_id' => (string) $eventId,
            'post_id' => (string) $localId,
            'start_date' => $this->postMetaString($localId, '_EventStartDate'),
            'end_date' => $this->postMetaString($localId, '_EventEndDate'),
            'start_date_utc' => $this->postMetaString($localId, '_EventStartDateUTC'),
            'end_date_utc' => $this->postMetaString($localId, '_EventEndDateUTC'),
            'timezone' => $this->postMetaString($localId, '_EventTimezone'),
            'duration' => $expectedDuration,
            // Event::data_from_post() sets this exact free-plugin wire. Any
            // filter changing it is outside the reviewed 6.17.2/6.17.3 path.
            'hash' => '',
        ];
        $eventMismatches = $this->rowMismatches($eventRows, $expectedEvent);
        if (count($eventRows) === 1 && !$this->validDatabaseTimestamp($eventRows[0]['updated_at'] ?? null)) {
            $eventMismatches[] = 'updated_at';
        }
        if ($eventMismatches !== []) {
            throw new \RuntimeException(
                'duo: TEC derived-state verification failed for tec_events fields: '
                . implode(',', $eventMismatches)
            );
        }

        $occurrenceTable = $wpdb->prefix . 'tec_occurrences';
        $wpdb->last_error = '';
        $occurrenceRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT occurrence_id, event_id, post_id, start_date, end_date, start_date_utc, "
                . "end_date_utc, duration, updated_at, hash FROM `$occurrenceTable` "
                . 'WHERE post_id = %d OR event_id = %d ORDER BY occurrence_id',
                $localId,
                $eventId
            ),
            ARRAY_A
        );
        if ($wpdb->last_error !== '') {
            throw new \RuntimeException(
                'duo: TEC derived-state verification query failed for tec_occurrences'
            );
        }
        if (!is_array($occurrenceRows)) {
            throw new \RuntimeException(
                'duo: TEC derived-state verification query returned a non-array for tec_occurrences'
            );
        }
        $expectedOccurrence = $expectedEvent;
        unset($expectedOccurrence['timezone']);
        $expectedOccurrence['hash'] = sha1(implode(':', [
            $expectedOccurrence['post_id'],
            $expectedOccurrence['start_date'],
            $expectedOccurrence['end_date'],
            $expectedOccurrence['start_date_utc'],
            $expectedOccurrence['end_date_utc'],
            $expectedOccurrence['duration'],
        ]));
        $occurrenceMismatches = $this->rowMismatches($occurrenceRows, $expectedOccurrence);
        if (count($occurrenceRows) === 1) {
            if (!$this->validPositiveDatabaseId($occurrenceRows[0]['occurrence_id'] ?? null)) {
                $occurrenceMismatches[] = 'occurrence_id';
            }
            if (!$this->validDatabaseTimestamp($occurrenceRows[0]['updated_at'] ?? null)) {
                $occurrenceMismatches[] = 'updated_at';
            }
        }
        if ($occurrenceMismatches !== []) {
            throw new \RuntimeException(
                'duo: TEC derived-state verification failed for tec_occurrences fields: '
                . implode(',', $occurrenceMismatches)
            );
        }
    }

    private function postMetaString(int $localId, string $key): string {
        $value = get_post_meta($localId, $key, true);
        if (!is_string($value)) {
            throw new \RuntimeException(
                "duo: TEC derived-state verification requires $key to be one scalar string"
            );
        }
        return $value;
    }

    private function expectedDuration(int $localId): string {
        $duration = $this->postMetaString($localId, '_EventDuration');
        // Event::data_from_post() uses empty(), so the exact '0' string also
        // takes this path. For a real zero-length event the derived interval
        // remains zero; for an inconsistent one the repository interpreter
        // already refuses before apply.
        if ($duration !== '' && $duration !== '0') {
            return $duration;
        }
        $start = $this->utcMetaDate($localId, '_EventStartDateUTC');
        $end = $this->utcMetaDate($localId, '_EventEndDateUTC');
        return (string) ($end->getTimestamp() - $start->getTimestamp());
    }

    private function utcMetaDate(int $localId, string $key): \DateTimeImmutable {
        $wire = $this->postMetaString($localId, $key);
        $utc = new \DateTimeZone('UTC');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $wire, $utc);
        if (!$date instanceof \DateTimeImmutable || $date->format('Y-m-d H:i:s') !== $wire) {
            throw new \RuntimeException(
                "duo: TEC derived-state verification requires $key to be one exact UTC database timestamp"
            );
        }
        return $date;
    }

    private function validPositiveDatabaseId(mixed $value): bool {
        return is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1;
    }

    private function validDatabaseTimestamp(mixed $value): bool {
        if (!is_string($value)) {
            return false;
        }
        $utc = new \DateTimeZone('UTC');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, $utc);
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d H:i:s') === $value;
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @param array<string,string> $expected
     * @return list<string>
     */
    private function rowMismatches(array $rows, array $expected): array {
        if (count($rows) !== 1) {
            return ['row_count'];
        }
        $mismatches = [];
        foreach ($expected as $field => $expectedValue) {
            $actual = $rows[0][$field] ?? null;
            if (!is_scalar($actual) || (string) $actual !== $expectedValue) {
                $mismatches[] = $field;
            }
        }
        return $mismatches;
    }

    /**
     * @param list<int> $liveIds
     * @param list<array<string,mixed>> $deletionContext
     */
    public function regenerate_batch(
        array $liveIds,
        array $deletionContext,
        ?callable $heartbeat = null
    ): void {
        if ($deletionContext !== []) {
            throw new \RuntimeException(
                'duo: TEC deletion regeneration is unsupported; event/venue/organizer cascade semantics '
                . 'must be certified before deletion context can be consumed'
            );
        }
        $ids = array_values(array_unique(array_filter(
            array_map('intval', $liveIds),
            static fn(int $id): bool => $id > 0
        )));
        sort($ids, SORT_NUMERIC);
        foreach ($ids as $localId) {
            if ($heartbeat !== null) {
                $heartbeat();
            }
            $this->regenerate($localId);
        }
    }
}
