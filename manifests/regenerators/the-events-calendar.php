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

    private const EVENT_DATA_FILTER = 'tec_events_custom_tables_v1_event_data_from_post';

    private const EVENT_ROW_FIELDS = [
        'event_id',
        'post_id',
        'start_date',
        'end_date',
        'start_date_utc',
        'end_date_utc',
        'timezone',
        'duration',
        'updated_at',
        'hash',
    ];
    private const OCCURRENCE_ROW_FIELDS = [
        'occurrence_id',
        'event_id',
        'post_id',
        'start_date',
        'end_date',
        'start_date_utc',
        'end_date_utc',
        'duration',
        'updated_at',
        'hash',
    ];
    private const TABLE_SCHEMAS = [
        'tec_events' => [
            'columns' => [
                'event_id' => ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
                'post_id' => ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                'start_date' => ['Type' => 'varchar(19)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                'end_date' => ['Type' => 'varchar(19)', 'Null' => 'YES', 'Default' => null, 'Extra' => ''],
                'timezone' => ['Type' => 'varchar(30)', 'Null' => 'NO', 'Default' => 'UTC', 'Extra' => ''],
                'start_date_utc' => ['Type' => 'varchar(19)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                'end_date_utc' => ['Type' => 'varchar(19)', 'Null' => 'YES', 'Default' => null, 'Extra' => ''],
                'duration' => ['Type' => 'mediumint(30)', 'Null' => 'YES', 'Default' => '7200', 'Extra' => ''],
                'updated_at' => ['Type' => 'timestamp', 'Null' => 'YES', 'Default' => 'current_timestamp()', 'Extra' => 'on update current_timestamp()'],
                'hash' => ['Type' => 'varchar(40)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
            ],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['event_id']],
                'post_id' => ['unique' => true, 'columns' => ['post_id']],
            ],
        ],
        'tec_occurrences' => [
            'columns' => [
                'occurrence_id' => ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => 'auto_increment'],
                'event_id' => ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                'post_id' => ['Type' => 'bigint(20) unsigned', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                'start_date' => ['Type' => 'datetime', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                'start_date_utc' => ['Type' => 'datetime', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                'end_date' => ['Type' => 'datetime', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                'end_date_utc' => ['Type' => 'datetime', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                'duration' => ['Type' => 'mediumint(30)', 'Null' => 'YES', 'Default' => '7200', 'Extra' => ''],
                'hash' => ['Type' => 'varchar(40)', 'Null' => 'NO', 'Default' => null, 'Extra' => ''],
                'updated_at' => ['Type' => 'timestamp', 'Null' => 'YES', 'Default' => 'current_timestamp()', 'Extra' => 'on update current_timestamp()'],
            ],
            'indexes' => [
                'PRIMARY' => ['unique' => true, 'columns' => ['occurrence_id']],
                'event_id' => ['unique' => false, 'columns' => ['event_id']],
                'hash' => ['unique' => true, 'columns' => ['hash']],
                'idx_wp_tec_occurrences_post_id_dates' => [
                    'unique' => false,
                    'columns' => ['post_id', 'end_date', 'start_date'],
                ],
                'idx_wp_tec_occurrences_post_id_dates_utc' => [
                    'unique' => false,
                    'columns' => ['post_id', 'end_date_utc', 'start_date_utc'],
                ],
            ],
        ],
    ];
    private const MAX_INDEX_ROWS = 64;

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    public function regenerate(int $localId): void {
        if (!function_exists('has_filter')) {
            throw new \RuntimeException(
                'duo: TEC derived-state regeneration requires has_filter()'
            );
        }
        if (has_filter(self::EVENT_DATA_FILTER) !== false) {
            throw new \RuntimeException(
                'duo: TEC free-plugin derived-state contract does not admit the event-data filter'
            );
        }
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

        [$eventTable, $occurrenceTable] = $this->assertNativeTableSchemas();

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
        // Native model calls can handle a driver error and leave the public
        // wpdb field populated. Only this exact read may decide its outcome.
        $wpdb->last_error = '';
        $eventRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT event_id, post_id, start_date, end_date, start_date_utc, end_date_utc, "
                . "timezone, duration, updated_at, hash FROM `$eventTable` "
                . 'WHERE post_id = %d OR event_id = %d ORDER BY event_id LIMIT 3',
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
        $eventRows = $this->checkedDriverRows($eventRows, self::EVENT_ROW_FIELDS, 'tec_events');
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

        $wpdb->last_error = '';
        $occurrenceRows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT occurrence_id, event_id, post_id, start_date, end_date, start_date_utc, "
                . "end_date_utc, duration, updated_at, hash FROM `$occurrenceTable` "
                . 'WHERE post_id = %d OR event_id = %d ORDER BY occurrence_id LIMIT 3',
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
        $occurrenceRows = $this->checkedDriverRows(
            $occurrenceRows,
            self::OCCURRENCE_ROW_FIELDS,
            'tec_occurrences'
        );
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

    /** @return array{0:string,1:string} */
    private function assertNativeTableSchemas(): array {
        global $wpdb;
        if (!is_object($wpdb)
            || !is_callable([$wpdb, 'get_results'])
            || !is_callable([$wpdb, 'prepare'])
            || !is_callable([$wpdb, 'esc_like'])
            || !isset($wpdb->prefix)
            || !is_string($wpdb->prefix)
            || preg_match('/^[A-Za-z0-9_]{0,44}$/D', $wpdb->prefix) !== 1) {
            throw new \RuntimeException(
                'duo: TEC derived-state schema preflight requires one safe WordPress table prefix and database reader'
            );
        }
        $tables = [];
        foreach (self::TABLE_SCHEMAS as $suffix => $expected) {
            $table = $wpdb->prefix . $suffix;
            if (strlen($table) > 64 || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
                throw new \RuntimeException(
                    "duo: TEC derived-state schema preflight rejected the $suffix table identifier"
                );
            }
            $tables[$suffix] = $table;
            $this->assertNativeTableSchema($table, $suffix, $expected);
        }
        return [$tables['tec_events'], $tables['tec_occurrences']];
    }

    /**
     * @param array{
     *   columns:array<string,array{Type:string,Null:string,Default:?string,Extra:string}>,
     *   indexes:array<string,array{unique:bool,columns:list<string>}>
     * } $expected
     */
    private function assertNativeTableSchema(string $table, string $suffix, array $expected): void {
        global $wpdb;
        $status = $this->schemaRows(
            $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)),
            "$suffix table identity"
        );
        if (count($status) !== 1
            || !is_array($status[0])
            || !is_string($status[0]['Name'] ?? null)
            || !hash_equals($table, $status[0]['Name'])
            || !is_string($status[0]['Engine'] ?? null)
            || strcasecmp($status[0]['Engine'], 'InnoDB') !== 0) {
            throw new \RuntimeException(
                "duo: TEC derived-state schema preflight rejected the $suffix table identity or engine"
            );
        }

        $columns = $this->schemaRows("SHOW FULL COLUMNS FROM `$table`", "$suffix columns");
        if (count($columns) !== count($expected['columns'])) {
            throw new \RuntimeException(
                "duo: TEC derived-state schema preflight rejected the $suffix column count"
            );
        }
        foreach (array_values($expected['columns']) as $position => $columnExpected) {
            $columnName = array_keys($expected['columns'])[$position];
            $column = $columns[$position] ?? null;
            if (!is_array($column)
                || !is_string($column['Field'] ?? null)
                || !hash_equals($columnName, $column['Field'])
                || !is_string($column['Type'] ?? null)
                || !hash_equals($columnExpected['Type'], strtolower($column['Type']))
                || !is_string($column['Null'] ?? null)
                || !hash_equals($columnExpected['Null'], strtoupper($column['Null']))
                || !array_key_exists('Default', $column)
                || $column['Default'] !== $columnExpected['Default']
                || !is_string($column['Extra'] ?? null)
                || !hash_equals($columnExpected['Extra'], strtolower($column['Extra']))) {
                throw new \RuntimeException(
                    "duo: TEC derived-state schema preflight rejected $suffix column $columnName"
                );
            }
        }

        $indexRows = $this->schemaRows("SHOW INDEX FROM `$table`", "$suffix indexes");
        if (count($indexRows) > self::MAX_INDEX_ROWS) {
            throw new \RuntimeException(
                "duo: TEC derived-state schema preflight rejected the $suffix index row frontier"
            );
        }
        $indexes = [];
        foreach ($indexRows as $row) {
            if (!is_array($row)
                || !is_string($row['Key_name'] ?? null)
                || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $row['Key_name']) !== 1
                || !is_string($row['Non_unique'] ?? null)
                || !in_array($row['Non_unique'], ['0', '1'], true)
                || !is_string($row['Seq_in_index'] ?? null)
                || preg_match('/^[1-9][0-9]*$/D', $row['Seq_in_index']) !== 1
                || (int) $row['Seq_in_index'] > self::MAX_INDEX_ROWS
                || !is_string($row['Column_name'] ?? null)
                || !isset($expected['columns'][$row['Column_name']])
                || !array_key_exists('Sub_part', $row)
                || $row['Sub_part'] !== null
                || !is_string($row['Index_type'] ?? null)
                || strcasecmp($row['Index_type'], 'BTREE') !== 0) {
                throw new \RuntimeException(
                    "duo: TEC derived-state schema preflight rejected one $suffix index row"
                );
            }
            $name = $row['Key_name'];
            $sequence = (int) $row['Seq_in_index'];
            $unique = $row['Non_unique'] === '0';
            if (isset($indexes[$name]['columns'][$sequence])
                || isset($indexes[$name]) && $indexes[$name]['unique'] !== $unique) {
                throw new \RuntimeException(
                    "duo: TEC derived-state schema preflight rejected duplicated $suffix index metadata"
                );
            }
            $indexes[$name]['unique'] = $unique;
            $indexes[$name]['columns'][$sequence] = $row['Column_name'];
        }
        foreach ($indexes as $name => &$index) {
            ksort($index['columns'], SORT_NUMERIC);
            if (array_keys($index['columns']) !== range(1, count($index['columns']))) {
                throw new \RuntimeException(
                    "duo: TEC derived-state schema preflight rejected non-contiguous $suffix index metadata"
                );
            }
            $index['columns'] = array_values($index['columns']);
            if ($index['unique'] && !isset($expected['indexes'][$name])) {
                throw new \RuntimeException(
                    "duo: TEC derived-state schema preflight rejected an unknown unique $suffix index"
                );
            }
        }
        unset($index);
        foreach ($expected['indexes'] as $name => $indexExpected) {
            if (($indexes[$name] ?? null) !== $indexExpected) {
                throw new \RuntimeException(
                    "duo: TEC derived-state schema preflight rejected required $suffix index $name"
                );
            }
        }
    }

    /** @return list<array<string,mixed>> */
    private function schemaRows(string $sql, string $context): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || !array_is_list($rows) || $wpdb->last_error !== '') {
            throw new \RuntimeException(
                "duo: TEC derived-state schema preflight could not read $context"
            );
        }
        return $rows;
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
        return is_string($value)
            && preg_match('/^[1-9][0-9]*$/D', $value) === 1
            && (strlen($value) < 20
                || strlen($value) === 20 && strcmp($value, '18446744073709551615') <= 0);
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
            if (!is_string($actual) || !hash_equals($expectedValue, $actual)) {
                $mismatches[] = $field;
            }
        }
        return $mismatches;
    }

    /**
     * mysqli's text protocol returns a zero-based list of ARRAY_A rows whose
     * selected non-NULL values are strings. Prove that exact bounded driver
     * surface before value-level verification so a compatible-driver shape
     * drift cannot be confused with a missing derived row.
     *
     * @param array<array-key,mixed> $rows
     * @param list<string> $fields
     * @return list<array<string,string>>
     */
    private function checkedDriverRows(array $rows, array $fields, string $table): array {
        if (!array_is_list($rows)) {
            throw new \RuntimeException(
                "duo: TEC derived-state verification query returned a non-list for $table"
            );
        }
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== $fields) {
                throw new \RuntimeException(
                    "duo: TEC derived-state verification query returned a malformed driver row for $table"
                );
            }
            foreach ($row as $value) {
                if (!is_string($value)) {
                    throw new \RuntimeException(
                        "duo: TEC derived-state verification query returned a non-string driver value for $table"
                    );
                }
            }
        }
        return $rows;
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
