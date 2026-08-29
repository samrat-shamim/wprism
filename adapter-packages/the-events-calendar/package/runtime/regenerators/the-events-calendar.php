<?php
namespace WPrism\Regenerators;

use WPrism\Policy;

/**
 * TEC (The Events Calendar) regenerator — issue #3234, task #124's proving
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
    private const LAST_SAVE_OPTION = 'tribe_last_save_post';
    private const SESSION_STATE_QUERY =
        'SELECT CONNECTION_ID() AS connection_id, @@in_transaction AS in_transaction';
    private const TRANSACTION_ISOLATION_COMMAND =
        'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ';
    private const CONFIGURATION_CLASS = 'TEC\\Common\\Configuration\\Configuration';
    private const OCCURRENCES_GENERATOR_CLASS =
        'TEC\\Events\\Custom_Tables\\V1\\Events\\Occurrences\\Occurrences_Generator';

    /** @var list<string> */
    private const EMPTY_NATIVE_HOOKS = [
        'get_post_metadata',
        'tec_events_custom_tables_v1_event_data_from_post',
        'tec_custom_tables_tec_events_model_v1_extensions',
        'tec_custom_tables_tec_occurrences_model_v1_extensions',
        'tec_events_custom_tables_v1_occurrences_generator',
        'tec_custom_tables_v1_get_occurrence_match',
        'tec_events_custom_tables_v1_after_update_occurrences',
        'tec_events_custom_tables_v1_after_insert_occurrences',
        'tec_events_custom_tables_v1_after_save_occurrences',
        'tec_cache_listener_save_post_types',
        'tribe_cache_expiration',
        'tribe_cache_last_occurrence_option_triggers',
        'tribe_cache_last_occurrence_option_triggers:save_post',
        'tribe_cache_last_occurrence_option_triggers:updated_option',
    ];

    /** @var list<string> */
    private const EMPTY_OPTION_HOOKS = [
        'pre_option_tribe_last_save_post',
        'pre_wp_load_alloptions',
        'pre_cache_alloptions',
        'alloptions',
        'default_option_tribe_last_save_post',
        'option_tribe_last_save_post',
        'sanitize_option_tribe_last_save_post',
        'pre_update_option_tribe_last_save_post',
        'pre_update_option',
        'update_option',
        'wp_autoload_values_to_autoload',
        'update_option_tribe_last_save_post',
        'add_option',
        'add_option_tribe_last_save_post',
        'added_option',
        'wp_max_autoloaded_option_size',
    ];

    /** @var array<string,list<array{class:string,method:string,priority:int,accepted_args:int}>> */
    private const ALLOWED_OPTION_HOOKS = [
        'pre_option' => [
            [
                'class' => 'TEC\\Common\\Integrations\\Harbor\\PUE',
                'method' => 'filter_pre_get_option',
                'priority' => 10,
                'accepted_args' => 3,
            ],
        ],
        'updated_option' => [
            [
                'class' => 'Tribe__Cache_Listener',
                'method' => 'update_last_updated_option',
                'priority' => 10,
                'accepted_args' => 3,
            ],
            [
                'class' => 'Tribe__Cache_Listener',
                'method' => 'update_last_save_post',
                'priority' => 10,
                'accepted_args' => 3,
            ],
            [
                'class' => 'Tribe__Settings_Manager',
                'method' => 'update_options_cache',
                'priority' => 10,
                'accepted_args' => 3,
            ],
            [
                'class' => 'Tribe__Events__Aggregator',
                'method' => 'action_purge_transients',
                'priority' => 10,
                'accepted_args' => 1,
            ],
            [
                'class' => 'Tribe\\Events\\Views\\V2\\Hooks',
                'method' => 'action_save_wplang',
                'priority' => 10,
                'accepted_args' => 3,
            ],
        ],
    ];

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
    private const MAX_SOURCE_META_ROWS = 10000;
    private const MAX_SOURCE_META_KEY_BYTES = 1020;
    private const MAX_SOURCE_META_VALUE_BYTES = 16777216;
    private const MAX_SOURCE_META_OWNER_BYTES = 67108864;
    private const REQUIRED_EVENT_META_KEYS = [
        '_EventStartDate',
        '_EventEndDate',
        '_EventStartDateUTC',
        '_EventEndDateUTC',
        '_EventTimezone',
        '_EventDuration',
    ];

    /** @var list<array{key:string,group:string}> */
    private const SOURCE_CACHE_KEYS = [
        ['key' => 'post-id', 'group' => 'posts'],
        ['key' => 'post-id', 'group' => 'post_meta'],
        ['key' => 'post-id', 'group' => 'tec_occurrence_matches'],
        ['key' => 'last_changed', 'group' => 'posts'],
        ['key' => 'tribe_last_save_post', 'group' => 'options'],
        ['key' => 'alloptions', 'group' => 'options'],
        ['key' => 'notoptions', 'group' => 'options'],
    ];
    private const NATIVE_CACHE_GROUPS = [
        'tribe-events',
        'tribe-events-non-persistent',
    ];

    public function __construct(Policy $policy) {
        $this->policy = $policy;
    }

    public function regenerate(int $localId): void {
        $eventClass = '\\TEC\\Events\\Custom_Tables\\V1\\Models\\Event';
        if (!class_exists($eventClass)) {
            throw new \RuntimeException(
                "wprism: TEC regenerator: $eventClass not found — The Events Calendar's Custom Tables v1 "
                . 'architecture may have been restructured in a version outside this manifest\'s pinned range'
            );
        }
        if (!method_exists($eventClass, 'data_from_post')) {
            throw new \RuntimeException(
                "wprism: TEC regenerator: $eventClass::data_from_post() not found — cannot regenerate tec_occurrences"
            );
        }

        $this->assertDatabaseRuntime();
        $preflightSession = $this->transactionSession('before regeneration');
        if ($preflightSession['in_transaction']) {
            throw new \RuntimeException(
                'wprism: TEC derived-state transaction continuity was lost before regeneration; recovery_required'
            );
        }
        $preflightConnectionId = $preflightSession['connection_id'];
        $nativeRuntime = $this->prepareNativeRuntime();
        // The preflight above proves there is no caller-owned transaction to
        // damage. From this point an apparently failed START is ambiguous, so
        // the failure path issues ROLLBACK too rather than leaving a possible
        // row-lock owner attached to the long-running apply process.
        $transactionMayBeOpen = true;
        $commitCommandSucceeded = false;
        $optionsTable = null;
        $optionNameIndex = null;
        $lastSaveOptionWitness = null;
        $initialLastSaveAutoload = null;
        $initialDerivedWitness = null;
        $connectionId = $preflightConnectionId;
        try {
            $this->beginRepeatableReadTransaction($preflightConnectionId, 'start');
            $connectionId = $preflightConnectionId;
            [$eventTable, $occurrenceTable] = $this->assertNativeTableSchemas();
            [
                $postsTable,
                $postMetaTable,
                $postMetaIndex,
                $optionsTable,
                $optionNameIndex,
            ] = $this->assertSourceTableSchemas();
            $sourceWitness = $this->sourceWitness(
                $localId,
                $postsTable,
                $postMetaTable,
                $postMetaIndex
            );
            $lastSaveOptionWitness = $this->lastSaveOptionWitness(
                $optionsTable,
                $optionNameIndex,
                false,
                $initialLastSaveAutoload
            );
            $existingEventId = $this->lockDerivedOwnerRanges(
                $localId,
                null,
                $eventTable,
                $occurrenceTable
            );
            $initialDerivedWitness = $this->derivedOwnerWitness(
                $localId,
                $existingEventId,
                $eventTable,
                $occurrenceTable
            );
            // The first SELECT against each table acquires transaction-lifetime
            // metadata locks. Re-read the exact schemas only now so a DDL race
            // between the initial preflight and owner-range acquisition cannot
            // make plugin code run against an unreviewed physical contract.
            if ($this->assertNativeTableSchemas() !== [$eventTable, $occurrenceTable]
                || $this->assertSourceTableSchemas() !== [
                    $postsTable,
                    $postMetaTable,
                    $postMetaIndex,
                    $optionsTable,
                    $optionNameIndex,
                ]) {
                throw new \RuntimeException(
                    'wprism: TEC derived-state table identities changed while owner ranges were acquired'
                );
            }
            $this->assertPluginCallBoundary(
                'the native-call preflight',
                $nativeRuntime,
                $connectionId,
                $sourceWitness,
                $localId,
                $postsTable,
                $postMetaTable,
                $postMetaIndex,
                $optionsTable,
                $optionNameIndex,
                $lastSaveOptionWitness
            );
            $this->purgeSourceCaches($localId);

            $eventData = $eventClass::data_from_post($localId);
            $this->assertPluginCallBoundary(
                'Event::data_from_post()',
                $nativeRuntime,
                $connectionId,
                $sourceWitness,
                $localId,
                $postsTable,
                $postMetaTable,
                $postMetaIndex,
                $optionsTable,
                $optionNameIndex,
                $lastSaveOptionWitness
            );
            if (!is_array($eventData)) {
                throw new \RuntimeException(
                    'wprism: TEC Event::data_from_post() returned a non-array value'
                );
            }
            $expectedEvent = $this->expectedEventData($eventData, $localId);
            $upserted = $eventClass::upsert(['post_id'], $eventData);
            $this->assertPluginCallBoundary(
                'Event::upsert()',
                $nativeRuntime,
                $connectionId,
                $sourceWitness,
                $localId,
                $postsTable,
                $postMetaTable,
                $postMetaIndex,
                $optionsTable,
                $optionNameIndex,
                null,
                $upserted !== false
            );
            if ($upserted !== false) {
                $upsertAutoload = null;
                $upsertOptionWitness = $this->lastSaveOptionWitness(
                    $optionsTable,
                    $optionNameIndex,
                    true,
                    $upsertAutoload
                );
                if (hash_equals($lastSaveOptionWitness, $upsertOptionWitness)) {
                    throw new \RuntimeException(
                        'wprism: TEC Event::upsert() did not advance the exact native save-post cache marker'
                    );
                }
                $this->assertLastSaveAutoloadTransition(
                    $initialLastSaveAutoload,
                    $upsertAutoload
                );
            }
            if ($upserted === false) {
                $errors = (array) $eventClass::last_errors();
                $this->assertPluginCallBoundary(
                    'Event::last_errors()',
                    $nativeRuntime,
                    $connectionId,
                    $sourceWitness,
                    $localId,
                    $postsTable,
                    $postMetaTable,
                    $postMetaIndex,
                    $optionsTable,
                    $optionNameIndex,
                    null,
                    false
                );
                throw new \RuntimeException(
                    'wprism: TEC Event::upsert() failed with ' . count($errors) . ' model error(s)'
                );
            }

            $event = $eventClass::find($localId, 'post_id');
            $this->assertPluginCallBoundary(
                'Event::find()',
                $nativeRuntime,
                $connectionId,
                $sourceWitness,
                $localId,
                $postsTable,
                $postMetaTable,
                $postMetaIndex,
                $optionsTable,
                $optionNameIndex,
                null,
                true
            );
            if (!($event instanceof $eventClass)) {
                throw new \RuntimeException(
                    "wprism: TEC Event::find(\$localId, 'post_id') could not locate the just-upserted event"
                );
            }
            $eventId = $event->event_id;
            if (!is_int($eventId) || $eventId <= 0) {
                throw new \RuntimeException(
                    'wprism: TEC Event::find() returned an event without one positive integer event_id'
                );
            }
            $this->lockDerivedOwnerRanges(
                $localId,
                $eventId,
                $eventTable,
                $occurrenceTable,
                $existingEventId
            );
            $this->assertPluginCallBoundary(
                'the occurrence owner-range lock',
                $nativeRuntime,
                $connectionId,
                $sourceWitness,
                $localId,
                $postsTable,
                $postMetaTable,
                $postMetaIndex,
                $optionsTable,
                $optionNameIndex,
                null,
                true
            );
            $occurrences = $event->occurrences();
            $this->assertPluginCallBoundary(
                'Event::occurrences()',
                $nativeRuntime,
                $connectionId,
                $sourceWitness,
                $localId,
                $postsTable,
                $postMetaTable,
                $postMetaIndex,
                $optionsTable,
                $optionNameIndex,
                null,
                true
            );
            $occurrences->save_occurrences();
            $this->assertPluginCallBoundary(
                'Occurrence::save_occurrences()',
                $nativeRuntime,
                $connectionId,
                $sourceWitness,
                $localId,
                $postsTable,
                $postMetaTable,
                $postMetaIndex,
                $optionsTable,
                $optionNameIndex,
                null,
                true
            );
            $this->verifyDerivedRows(
                $localId,
                $eventId,
                $eventTable,
                $occurrenceTable,
                $expectedEvent
            );
            $this->assertPluginCallBoundary(
                'derived-state readback',
                $nativeRuntime,
                $connectionId,
                $sourceWitness,
                $localId,
                $postsTable,
                $postMetaTable,
                $postMetaIndex,
                $optionsTable,
                $optionNameIndex,
                null,
                true
            );
            $finalLastSaveAutoload = null;
            $this->lastSaveOptionWitness(
                $optionsTable,
                $optionNameIndex,
                true,
                $finalLastSaveAutoload
            );
            $this->assertLastSaveAutoloadTransition(
                $initialLastSaveAutoload,
                $finalLastSaveAutoload
            );
            $commitFailure = null;
            try {
                $this->transactionCommand('COMMIT', 'commit');
                $this->assertTransactionSession($connectionId, false, 'after commit response');
                $commitCommandSucceeded = true;
            } catch (\Throwable $failure) {
                $commitFailure = $failure;
            }
            if ($commitFailure !== null) {
                if (!is_string($initialDerivedWitness)) {
                    throw $commitFailure;
                }
                $commitOutcome = $this->classifyAmbiguousCommit(
                    $commitFailure,
                    $connectionId,
                    $sourceWitness,
                    $lastSaveOptionWitness,
                    $initialDerivedWitness,
                    $localId,
                    $eventId,
                    $expectedEvent,
                    $eventTable,
                    $occurrenceTable,
                    $postsTable,
                    $postMetaTable,
                    $postMetaIndex,
                    $optionsTable,
                    $optionNameIndex
                );
                $transactionMayBeOpen = false;
                if ($commitOutcome === 'preimage') {
                    throw new \RuntimeException(
                        'wprism: TEC derived-state transaction commit failed without server apply',
                        0,
                        $commitFailure
                    );
                }
                $commitCommandSucceeded = true;
            }
            // A server-applied COMMIT has no transaction to roll back even if
            // the following connection/cache proof fails. Mark that boundary
            // before post-commit checks so recovery never issues a misleading
            // ROLLBACK on a new or reconnected session.
            $transactionMayBeOpen = false;
            $this->assertTransactionSession($connectionId, false, 'after classified commit');
            $this->purgeSourceCaches($localId);
        } catch (\Throwable $failure) {
            $cleanupFailures = [];
            if ($transactionMayBeOpen) {
                if (!$this->settleRollback($connectionId)) {
                    $cleanupFailures[] = 'rollback';
                }
            }
            if (!$commitCommandSucceeded
                && is_string($optionsTable)
                && is_string($optionNameIndex)
                && is_string($lastSaveOptionWitness)) {
                try {
                    if (!hash_equals(
                        $lastSaveOptionWitness,
                        $this->lastSaveOptionWitness($optionsTable, $optionNameIndex)
                    )) {
                        $cleanupFailures[] = 'option_row';
                    }
                } catch (\Throwable) {
                    $cleanupFailures[] = 'option_row';
                }
            }
            if (!$commitCommandSucceeded
                && is_string($eventTable ?? null)
                && is_string($occurrenceTable ?? null)
                && is_string($initialDerivedWitness)) {
                try {
                    $currentEventId = $this->lockDerivedOwnerRanges(
                        $localId,
                        null,
                        $eventTable,
                        $occurrenceTable
                    );
                    if (!hash_equals(
                        $initialDerivedWitness,
                        $this->derivedOwnerWitness(
                            $localId,
                            $currentEventId,
                            $eventTable,
                            $occurrenceTable
                        )
                    )) {
                        $cleanupFailures[] = 'derived_rows';
                    }
                } catch (\Throwable) {
                    $cleanupFailures[] = 'derived_rows';
                }
            }
            try {
                $this->purgeSourceCaches($localId);
            } catch (\Throwable $cacheFailure) {
                $cleanupFailures[] = 'cache';
            }
            $runtimeCleanupFailures = $this->restoreNativeRuntime($nativeRuntime);
            $cleanupFailures = array_values(array_unique(array_merge(
                $cleanupFailures,
                $runtimeCleanupFailures
            )));
            if ($commitCommandSucceeded || $cleanupFailures !== []) {
                throw new \RuntimeException(
                    'wprism: TEC derived-state '
                        . ($commitCommandSucceeded ? 'committed outcome' : 'rollback')
                        . '/runtime cleanup requires recovery ('
                        . implode(',', $cleanupFailures === [] ? ['post_commit'] : $cleanupFailures)
                        . '); recovery_required',
                    0,
                    $failure
                );
            }
            throw $failure;
        }
        $runtimeCleanupFailures = $this->restoreNativeRuntime($nativeRuntime);
        if ($runtimeCleanupFailures !== []) {
            throw new \RuntimeException(
                'wprism: TEC derived-state committed but runtime cleanup failed ('
                    . implode(',', $runtimeCleanupFailures)
                    . '); recovery_required'
            );
        }
    }

    private function assertDatabaseRuntime(): void {
        foreach ([
            'add_action',
            'has_filter',
            'is_file',
            'remove_action',
            'tribe_cache',
            'tribe_get_var',
            'tribe_isset_var',
            'tribe_set_var',
            'tribe_unset_var',
            'wp_cache_delete',
            'wp_cache_flush_group',
            'wp_cache_get',
            'wp_cache_supports',
            'wp_using_ext_object_cache',
        ] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException(
                    "wprism: TEC derived-state regeneration requires $function()"
                );
            }
        }
        $this->assertLocalObjectCacheTopology();
        $supportsGroupFlush = wp_cache_supports('flush_group');
        if (!is_bool($supportsGroupFlush) || !$supportsGroupFlush) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration requires exact local object-cache group flushing'
            );
        }
        global $wpdb;
        foreach (['get_results', 'get_row', 'get_var', 'prepare', 'query', 'esc_like'] as $method) {
            if (!is_object($wpdb) || !is_callable([$wpdb, $method])) {
                throw new \RuntimeException(
                    'wprism: TEC derived-state regeneration requires the exact WordPress database connection'
                );
            }
        }
    }

    /**
     * Resolve every exact native singleton before START. In particular,
     * Cache_Listener::instance() adds hooks on first use; allowing that lazy
     * bootstrap inside the derived transaction would make plugin code mutate
     * an unproved global topology after locks have been acquired.
     *
     * @return array{
     *   container:object,
     *   cache:object,
     *   listener:object,
     *   listener_cache:object,
     *   log_callback:array{0:object,1:string},
     *   cache_keys:array<string,string>,
     *   listener_cache_keys:array<string,string>,
     *   flag_present:bool,
     *   flag_value:mixed
     * }
     */
    private function prepareNativeRuntime(): array {
        if (!class_exists('Tribe__Cache')
            || !class_exists('Tribe__Cache_Listener')
            || !class_exists('Tribe\\Log\\Service_Provider')) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration requires the exact free-plugin cache and logger classes'
            );
        }
        $container = $this->assertNativeContainerServices();
        $cache = tribe_cache();
        if (!is_object($cache) || get_class($cache) !== 'Tribe__Cache') {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration rejected an overridden Tribe cache singleton'
            );
        }
        $listener = \Tribe__Cache_Listener::instance();
        if (!is_object($listener) || get_class($listener) !== 'Tribe__Cache_Listener') {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration rejected an overridden cache listener singleton'
            );
        }
        $listenerCache = $this->listenerCache($listener);
        // Exact free TEC 6.17.2/6.17.3 Cache_Listener.php:42-44 constructs a
        // dedicated `new Tribe__Cache()` (identical source SHA-256
        // 14a63e60...248b), so equality with tribe_cache() would refuse the
        // supported artifact. Both distinct mutable registries are tracked.
        if ($listenerCache === $cache) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration rejected the cache listener service identity'
            );
        }

        $this->assertRequiredHookCallback(
            'tribe_schedule_transient_purge',
            [$cache, 'delete_expired_transients'],
            10,
            1
        );
        $this->assertRequiredHookCallback(
            'shutdown',
            [$cache, 'maybe_delete_expired_transients'],
            10,
            1
        );
        foreach ([
            ['save_post', 'save_post', 0, 2],
            ['updated_option', 'update_last_updated_option', 10, 3],
            ['updated_option', 'update_last_save_post', 10, 3],
            ['generate_rewrite_rules', 'generate_rewrite_rules', 10, 1],
            ['clean_post_cache', 'save_post', 0, 2],
        ] as [$hook, $method, $priority, $acceptedArgs]) {
            $this->assertRequiredHookCallback(
                $hook,
                [$listener, $method],
                $priority,
                $acceptedArgs
            );
        }

        $cacheKeys = $this->cacheNonPersistentKeys($cache);
        $listenerCacheKeys = $this->cacheNonPersistentKeys($listenerCache);
        $flagPresent = tribe_isset_var('should_delete_expired_transients');
        $flagValue = $flagPresent
            ? tribe_get_var('should_delete_expired_transients')
            : null;
        $logCallback = $this->assertNativeHookTopology(false, $listener);
        if (!remove_action('tribe_log', $logCallback, 10)
            || has_filter('tribe_log') !== false) {
            // A failed remove may leave the exact callback installed. Calling
            // add_action again is idempotent for the same object/method key.
            add_action('tribe_log', $logCallback, 10, 3);
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration could not suppress failure logging safely'
            );
        }

        return [
            'container' => $container,
            'cache' => $cache,
            'listener' => $listener,
            'listener_cache' => $listenerCache,
            'log_callback' => $logCallback,
            'cache_keys' => $cacheKeys,
            'listener_cache_keys' => $listenerCacheKeys,
            'flag_present' => $flagPresent,
            'flag_value' => $flagValue,
        ];
    }

    /**
     * @param array{
     *   container:object,
     *   cache:object,
     *   listener:object,
     *   listener_cache:object,
     *   log_callback:array{0:object,1:string},
     *   cache_keys:array<string,string>,
     *   listener_cache_keys:array<string,string>,
     *   flag_present:bool,
     *   flag_value:mixed
     * } $runtime
     * @return list<string>
     */
    private function restoreNativeRuntime(array $runtime): array {
        $failures = [];
        try {
            if ($this->assertNativeContainerServices($runtime['container']) !== $runtime['container']) {
                $failures[] = 'container_service';
            }
        } catch (\Throwable) {
            $failures[] = 'container_service';
        }
        try {
            if (tribe_cache() !== $runtime['cache']
                || \Tribe__Cache_Listener::instance() !== $runtime['listener']
                || $this->listenerCache($runtime['listener']) !== $runtime['listener_cache']
                || $runtime['listener_cache'] === $runtime['cache']) {
                $failures[] = 'cache_service';
            }
        } catch (\Throwable) {
            $failures[] = 'cache_service';
        }
        try {
            if ($runtime['flag_present']) {
                tribe_set_var('should_delete_expired_transients', $runtime['flag_value']);
            } else {
                tribe_unset_var('should_delete_expired_transients');
            }
        } catch (\Throwable) {
            $failures[] = 'transient_flag';
        }
        try {
            $this->restoreCacheNonPersistentKeys($runtime['cache'], $runtime['cache_keys']);
            $this->restoreCacheNonPersistentKeys(
                $runtime['listener_cache'],
                $runtime['listener_cache_keys']
            );
        } catch (\Throwable) {
            $failures[] = 'cache_registry';
        }
        try {
            if (!add_action('tribe_log', $runtime['log_callback'], 10, 3)) {
                $failures[] = 'logger_hook';
            } else {
                $this->assertNativeHookTopology(false, $runtime['listener']);
            }
        } catch (\Throwable) {
            $failures[] = 'logger_hook';
        }
        try {
            if ($this->assertNativeContainerServices($runtime['container']) !== $runtime['container']) {
                $failures[] = 'container_service';
            }
        } catch (\Throwable) {
            $failures[] = 'container_service';
        }
        try {
            if (tribe_cache() !== $runtime['cache']
                || \Tribe__Cache_Listener::instance() !== $runtime['listener']
                || $this->listenerCache($runtime['listener']) !== $runtime['listener_cache']
                || $runtime['listener_cache'] === $runtime['cache']) {
                $failures[] = 'cache_service';
            }
        } catch (\Throwable) {
            $failures[] = 'cache_service';
        }
        return array_values(array_unique($failures));
    }

    private function assertNativeContainerServices(?object $expectedContainer = null): object {
        try {
            $container = tribe();
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration could not resolve the native service container',
                0,
                $failure
            );
        }
        if (!is_object($container)
            || get_class($container) !== 'Tribe__Container'
            || $expectedContainer !== null && $container !== $expectedContainer
            || !is_callable([$container, 'isBound'])
            || !is_callable([$container, 'make'])) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration rejected the native service container identity'
            );
        }
        foreach ([self::CONFIGURATION_CLASS, self::OCCURRENCES_GENERATOR_CLASS] as $service) {
            try {
                $bound = $container->isBound($service);
            } catch (\Throwable $failure) {
                throw new \RuntimeException(
                    'wprism: TEC derived-state regeneration could not prove the native service binding state',
                    0,
                    $failure
                );
            }
            if (!is_bool($bound) || $bound) {
                throw new \RuntimeException(
                    'wprism: TEC derived-state regeneration rejected an overridden native service binding'
                );
            }
        }
        try {
            $configuration = $container->make(self::CONFIGURATION_CLASS);
            $generator = $container->make(self::OCCURRENCES_GENERATOR_CLASS);
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration could not resolve the exact free-plugin services',
                0,
                $failure
            );
        }
        if (!is_object($configuration)
            || get_class($configuration) !== self::CONFIGURATION_CLASS
            || !is_callable([$configuration, 'has'])
            || !is_callable([$configuration, 'get'])
            || !is_object($generator)
            || get_class($generator) !== self::OCCURRENCES_GENERATOR_CLASS) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration rejected an overridden free-plugin service'
            );
        }
        try {
            $memoizeFlagPresent = $configuration->has('TEC_NO_MEMOIZE_CT1_MODELS');
            $memoizeFlag = $configuration->get('TEC_NO_MEMOIZE_CT1_MODELS');
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration could not prove the model memoization configuration',
                0,
                $failure
            );
        }
        if (!is_bool($memoizeFlagPresent) || $memoizeFlagPresent || $memoizeFlag !== null) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration rejected a non-default model memoization configuration'
            );
        }
        return $container;
    }

    private function listenerCache(object $listener): object {
        try {
            $property = new \ReflectionProperty('Tribe__Cache_Listener', 'cache');
            $cache = $property->getValue($listener);
        } catch (\Throwable) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration could not prove the cache listener service identity'
            );
        }
        if (!is_object($cache) || get_class($cache) !== 'Tribe__Cache') {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration rejected the cache listener service identity'
            );
        }
        return $cache;
    }

    /** @return array<string,string> */
    private function cacheNonPersistentKeys(object $cache): array {
        try {
            $property = new \ReflectionProperty('Tribe__Cache', 'non_persistent_keys');
            $value = $property->getValue($cache);
        } catch (\Throwable) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration could not inspect the native cache registry'
            );
        }
        if (!is_array($value) || count($value) > 10000) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration rejected the native cache registry frontier'
            );
        }
        foreach ($value as $key => $entry) {
            if (!is_string($key)
                || !is_string($entry)
                || !hash_equals($key, $entry)
                || strlen($key) > 1024) {
                throw new \RuntimeException(
                    'wprism: TEC derived-state regeneration rejected a malformed native cache registry entry'
                );
            }
        }
        return $value;
    }

    /** @param array<string,string> $keys */
    private function restoreCacheNonPersistentKeys(object $cache, array $keys): void {
        $property = new \ReflectionProperty('Tribe__Cache', 'non_persistent_keys');
        $property->setValue($cache, $keys);
        if ($this->cacheNonPersistentKeys($cache) !== $keys) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration could not restore the native cache registry'
            );
        }
    }

    /**
     * @return array{0:object,1:string}
     */
    private function assertNativeHookTopology(bool $logSuppressed, object $listener): array {
        foreach (array_merge(self::EMPTY_NATIVE_HOOKS, self::EMPTY_OPTION_HOOKS) as $hook) {
            if (has_filter($hook) !== false) {
                if ($hook === self::EVENT_DATA_FILTER) {
                    throw new \RuntimeException(
                        'wprism: TEC free-plugin derived-state contract does not admit the event-data filter'
                    );
                }
                throw new \RuntimeException(
                    "wprism: TEC free-plugin derived-state contract does not admit callback hook $hook"
                );
            }
        }
        $defaultAutoload = $this->hookCallbacks('wp_default_autoload_value');
        if (count($defaultAutoload) !== 1
            || ($defaultAutoload[0]['function'] ?? null)
                !== 'wp_filter_default_autoload_value_via_option_size'
            || ($defaultAutoload[0]['priority'] ?? null) !== 5
            || ($defaultAutoload[0]['accepted_args'] ?? null) !== 4
            || !function_exists('wp_filter_default_autoload_value_via_option_size')) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration requires the exact WordPress default-autoload callback'
            );
        }
        foreach (self::ALLOWED_OPTION_HOOKS as $hook => $allowed) {
            $seen = [];
            foreach ($this->hookCallbacks($hook) as $callback) {
                $matched = false;
                foreach ($allowed as $rule) {
                    $function = $callback['function'];
                    if (is_array($function)
                        && count($function) === 2
                        && is_object($function[0])
                        && is_string($function[1])
                        && get_class($function[0]) === $rule['class']
                        && $function[0] === $this->nativeOptionHookService(
                            $rule['class'],
                            $listener
                        )
                        && hash_equals($rule['method'], $function[1])
                        && $callback['priority'] === $rule['priority']
                        && $callback['accepted_args'] === $rule['accepted_args']) {
                        $identity = $rule['class'] . '::' . $rule['method'];
                        if (isset($seen[$identity])) {
                            throw new \RuntimeException(
                                "wprism: TEC derived-state option hook $hook has a duplicated native callback"
                            );
                        }
                        $seen[$identity] = true;
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    throw new \RuntimeException(
                        "wprism: TEC derived-state option hook $hook contains an unreviewed callback"
                    );
                }
            }
        }
        $this->assertRequiredHookCallback(
            'updated_option',
            [$listener, 'update_last_updated_option'],
            10,
            3
        );
        $this->assertRequiredHookCallback(
            'updated_option',
            [$listener, 'update_last_save_post'],
            10,
            3
        );

        $logs = $this->hookCallbacks('tribe_log');
        if ($logSuppressed) {
            if ($logs !== []) {
                throw new \RuntimeException(
                    'wprism: TEC derived-state failure logger changed while native calls were in progress'
                );
            }
            return [new \stdClass(), 'suppressed'];
        }
        if (count($logs) !== 1) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration requires one exact free-plugin failure logger'
            );
        }
        $log = $logs[0];
        $function = $log['function'];
        if (!is_array($function)
            || count($function) !== 2
            || !is_object($function[0])
            || get_class($function[0]) !== 'Tribe\\Log\\Service_Provider'
            || ($function[1] ?? null) !== 'dispatch_log'
            || $log['priority'] !== 10
            || $log['accepted_args'] !== 3) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration rejected the free-plugin failure logger topology'
            );
        }
        return [$function[0], 'dispatch_log'];
    }

    private function nativeOptionHookService(string $class, object $listener): object {
        if ($class === 'Tribe__Cache_Listener') {
            return $listener;
        }
        try {
            if (in_array($class, [
                'Tribe__Settings_Manager',
                'Tribe__Events__Aggregator',
            ], true)) {
                if (!is_callable([$class, 'instance'])) {
                    throw new \RuntimeException('singleton factory is unavailable');
                }
                $service = $class::instance();
            } else {
                $service = tribe($class);
            }
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration could not resolve one native option-hook service',
                0,
                $failure
            );
        }
        if (!is_object($service) || get_class($service) !== $class) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration rejected one native option-hook service identity'
            );
        }
        return $service;
    }

    /** @param array{0:object,1:string} $function */
    private function assertRequiredHookCallback(
        string $hook,
        array $function,
        int $priority,
        int $acceptedArgs
    ): void {
        $matches = 0;
        foreach ($this->hookCallbacks($hook) as $callback) {
            if ($callback['function'] === $function
                && $callback['priority'] === $priority
                && $callback['accepted_args'] === $acceptedArgs) {
                ++$matches;
            }
        }
        if ($matches !== 1) {
            throw new \RuntimeException(
                "wprism: TEC derived-state regeneration rejected required native hook $hook"
            );
        }
    }

    /** @return list<array{function:mixed,priority:int,accepted_args:int}> */
    private function hookCallbacks(string $hook): array {
        $node = $GLOBALS['wp_filter'][$hook] ?? null;
        if ($node === null) {
            return [];
        }
        if (!is_object($node)
            || get_class($node) !== 'WP_Hook'
            || !isset($node->callbacks)
            || !is_array($node->callbacks)) {
            throw new \RuntimeException(
                "wprism: TEC derived-state regeneration rejected malformed hook registry $hook"
            );
        }
        $result = [];
        foreach ($node->callbacks as $priority => $records) {
            if (!is_int($priority) || !is_array($records) || count($records) > 1000) {
                throw new \RuntimeException(
                    "wprism: TEC derived-state regeneration rejected hook registry frontier $hook"
                );
            }
            foreach ($records as $record) {
                if (!is_array($record)
                    || !array_key_exists('function', $record)
                    || !isset($record['accepted_args'])
                    || !is_int($record['accepted_args'])
                    || $record['accepted_args'] < 0
                    || $record['accepted_args'] > 100) {
                    throw new \RuntimeException(
                        "wprism: TEC derived-state regeneration rejected malformed hook callback $hook"
                    );
                }
                $result[] = [
                    'function' => $record['function'],
                    'priority' => $priority,
                    'accepted_args' => $record['accepted_args'],
                ];
            }
        }
        return $result;
    }

    private function transactionCommand(string $sql, string $phase): void {
        global $wpdb;
        $wpdb->last_error = '';
        try {
            $result = $wpdb->query($sql);
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                "wprism: TEC derived-state transaction $phase raised a driver exception; recovery_required",
                0,
                $failure
            );
        }
        if ($result === false || $wpdb->last_error !== '') {
            throw new \RuntimeException(
                "wprism: TEC derived-state transaction $phase failed; recovery_required"
            );
        }
    }

    /**
     * A session may carry a one-shot READ COMMITTED characteristic even when
     * @@transaction_isolation still reports its REPEATABLE READ default.
     * Replace that pending characteristic immediately before START, then
     * prove the exact session stayed active. If START is ambiguous, consume
     * any still-pending characteristic on the same session before returning.
     */
    private function beginRepeatableReadTransaction(string $connectionId, string $phase): void {
        $this->assertSessionIsolation(
            $connectionId,
            false,
            "before $phase isolation override"
        );
        try {
            $this->transactionCommand(
                self::TRANSACTION_ISOLATION_COMMAND,
                "$phase isolation override"
            );
            $this->transactionCommand('START TRANSACTION', $phase);
            $this->assertTransactionSession($connectionId, true, "after $phase");
            $this->assertSessionIsolation(
                $connectionId,
                true,
                "after $phase isolation override"
            );
        } catch (\Throwable $failure) {
            $settled = $this->settleRollback($connectionId);
            if (!$settled || !$this->consumePendingIsolation($connectionId)) {
                throw new \RuntimeException(
                    "wprism: TEC derived-state $phase isolation cleanup requires recovery",
                    0,
                    $failure
                );
            }
            throw $failure;
        }
    }

    /**
     * START then ROLLBACK is deliberately data-free: it consumes either the
     * pending one-shot override or the admitted session default, so a failed
     * regenerator cannot alter the next caller's transaction characteristics.
     */
    private function consumePendingIsolation(string $connectionId): bool {
        try {
            $this->assertTransactionSession(
                $connectionId,
                false,
                'before isolation cleanup transaction'
            );
            $this->transactionCommand('START TRANSACTION', 'isolation cleanup start');
            $this->assertTransactionSession(
                $connectionId,
                true,
                'during isolation cleanup transaction'
            );
            return $this->settleRollback($connectionId);
        } catch (\Throwable) {
            $this->settleRollback($connectionId);
            return false;
        }
    }

    /** @return array{connection_id:string,in_transaction:bool} */
    private function transactionSession(string $phase): array {
        global $wpdb;
        $wpdb->last_error = '';
        try {
            $session = $wpdb->get_row(self::SESSION_STATE_QUERY, ARRAY_A);
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                "wprism: TEC derived-state transaction continuity probe raised during $phase; recovery_required",
                0,
                $failure
            );
        }
        if ($wpdb->last_error !== ''
            || !is_array($session)
            || array_keys($session) !== ['connection_id', 'in_transaction']
            || !is_string($session['connection_id'])
            || preg_match('/^[1-9][0-9]*$/D', $session['connection_id']) !== 1
            || strlen($session['connection_id']) > 20
            || !is_string($session['in_transaction'])
            || !in_array($session['in_transaction'], ['0', '1'], true)) {
            throw new \RuntimeException(
                "wprism: TEC derived-state transaction continuity was unavailable $phase; recovery_required"
            );
        }
        return [
            'connection_id' => $session['connection_id'],
            'in_transaction' => $session['in_transaction'] === '1',
        ];
    }

    private function assertTransactionSession(
        string $expectedConnectionId,
        bool $expectedTransaction,
        string $phase
    ): void {
        $session = $this->transactionSession($phase);
        if (!hash_equals($expectedConnectionId, $session['connection_id'])) {
            throw new \RuntimeException(
                "wprism: TEC derived-state database connection changed $phase; recovery_required"
            );
        }
        if ($session['in_transaction'] !== $expectedTransaction) {
            throw new \RuntimeException(
                "wprism: TEC derived-state transaction continuity was lost $phase; recovery_required"
            );
        }
    }

    /**
     * A failed ROLLBACK response is not evidence that rollback failed. Probe
     * the exact session state and retry once when the first response left the
     * transaction active. A reconnect is never accepted as cleanup proof.
     */
    private function settleRollback(string $connectionId): bool {
        global $wpdb;
        for ($attempt = 0; $attempt < 2; ++$attempt) {
            $wpdb->last_error = '';
            try {
                $wpdb->query('ROLLBACK');
            } catch (\Throwable) {
                // The state probe below distinguishes a thrown response after
                // server apply from a failure that left the transaction open.
            }
            try {
                $session = $this->transactionSession('during rollback recovery');
                if (!hash_equals($connectionId, $session['connection_id'])) {
                    return false;
                }
                if (!$session['in_transaction']) {
                    return true;
                }
            } catch (\Throwable) {
                return false;
            }
        }
        return false;
    }

    /**
     * @param array<string,string> $expectedEvent
     * @return 'applied'|'preimage'
     */
    private function classifyAmbiguousCommit(
        \Throwable $commitFailure,
        string $connectionId,
        string $sourceWitness,
        string $initialOptionWitness,
        string $initialDerivedWitness,
        int $localId,
        int $eventId,
        array $expectedEvent,
        string $eventTable,
        string $occurrenceTable,
        string $postsTable,
        string $postMetaTable,
        string $postMetaIndex,
        string $optionsTable,
        string $optionNameIndex
    ): string {
        try {
            $session = $this->transactionSession('after ambiguous commit');
            if (!hash_equals($connectionId, $session['connection_id'])) {
                throw new \RuntimeException(
                    'wprism: TEC derived-state database connection changed after ambiguous commit; recovery_required'
                );
            }
            $wasActive = $session['in_transaction'];
            if ($wasActive && !$this->settleRollback($connectionId)) {
                throw new \RuntimeException(
                    'wprism: TEC derived-state ambiguous commit left an uncloseable transaction'
                );
            }
            $outcome = $this->classifyInactiveCommitOutcome(
                $connectionId,
                $sourceWitness,
                $initialOptionWitness,
                $initialDerivedWitness,
                $localId,
                $eventId,
                $expectedEvent,
                $eventTable,
                $occurrenceTable,
                $postsTable,
                $postMetaTable,
                $postMetaIndex,
                $optionsTable,
                $optionNameIndex
            );
            if ($wasActive && $outcome !== 'preimage') {
                throw new \RuntimeException(
                    'wprism: TEC derived-state rollback after ambiguous commit did not restore its preimage'
                );
            }
            return $outcome;
        } catch (\Throwable $classificationFailure) {
            throw new \RuntimeException(
                'wprism: TEC derived-state commit outcome is ambiguous; recovery_required',
                0,
                $classificationFailure === $commitFailure ? null : $commitFailure
            );
        }
    }

    /**
     * @param array<string,string> $expectedEvent
     * @return 'applied'|'preimage'
     */
    private function classifyInactiveCommitOutcome(
        string $connectionId,
        string $sourceWitness,
        string $initialOptionWitness,
        string $initialDerivedWitness,
        int $localId,
        int $eventId,
        array $expectedEvent,
        string $eventTable,
        string $occurrenceTable,
        string $postsTable,
        string $postMetaTable,
        string $postMetaIndex,
        string $optionsTable,
        string $optionNameIndex
    ): string {
        $verificationOpen = true;
        try {
            $this->beginRepeatableReadTransaction(
                $connectionId,
                'commit-outcome verification start'
            );
            if ($this->assertNativeTableSchemas() !== [$eventTable, $occurrenceTable]
                || $this->assertSourceTableSchemas() !== [
                    $postsTable,
                    $postMetaTable,
                    $postMetaIndex,
                    $optionsTable,
                    $optionNameIndex,
                ]) {
                throw new \RuntimeException(
                    'wprism: TEC derived-state commit-outcome table identities changed'
                );
            }
            $currentSourceWitness = $this->sourceWitness(
                $localId,
                $postsTable,
                $postMetaTable,
                $postMetaIndex
            );
            $currentOptionWitness = $this->lastSaveOptionWitness(
                $optionsTable,
                $optionNameIndex
            );
            $currentEventId = $this->lockDerivedOwnerRanges(
                $localId,
                null,
                $eventTable,
                $occurrenceTable
            );
            $currentDerivedWitness = $this->derivedOwnerWitness(
                $localId,
                $currentEventId,
                $eventTable,
                $occurrenceTable
            );
            if (!hash_equals($sourceWitness, $currentSourceWitness)) {
                throw new \RuntimeException(
                    'wprism: TEC derived-state source changed across ambiguous commit'
                );
            }

            if (hash_equals($initialOptionWitness, $currentOptionWitness)
                && hash_equals($initialDerivedWitness, $currentDerivedWitness)) {
                $outcome = 'preimage';
            } else {
                if ($currentEventId !== $eventId
                    || hash_equals($initialOptionWitness, $currentOptionWitness)) {
                    throw new \RuntimeException(
                        'wprism: TEC derived-state ambiguous commit exposed a mixed physical outcome'
                    );
                }
                $this->lastSaveOptionWitness($optionsTable, $optionNameIndex, true);
                $this->verifyDerivedRows(
                    $localId,
                    $eventId,
                    $eventTable,
                    $occurrenceTable,
                    $expectedEvent
                );
                $outcome = 'applied';
            }
            if (!$this->settleRollback($connectionId)) {
                throw new \RuntimeException(
                    'wprism: TEC derived-state commit-outcome verification could not release its read locks'
                );
            }
            $verificationOpen = false;
            return $outcome;
        } catch (\Throwable $failure) {
            if ($verificationOpen) {
                $this->settleRollback($connectionId);
            }
            throw $failure;
        }
    }

    private function assertSessionIsolation(
        string $connectionId,
        bool $expectedTransaction,
        string $phase
    ): void {
        global $wpdb;
        $wpdb->last_error = '';
        $isolation = $wpdb->get_var('SELECT @@transaction_isolation');
        if ($isolation === null || $wpdb->last_error !== '') {
            $wpdb->last_error = '';
            $isolation = $wpdb->get_var('SELECT @@tx_isolation');
        }
        if (!is_string($isolation)
            || !in_array(strtoupper($isolation), ['REPEATABLE-READ', 'SERIALIZABLE'], true)
            || $wpdb->last_error !== '') {
            throw new \RuntimeException(
                'wprism: TEC derived-state locking requires REPEATABLE-READ or SERIALIZABLE isolation'
            );
        }
        $this->assertTransactionSession(
            $connectionId,
            $expectedTransaction,
            $phase
        );
    }

    /** @return array{0:string,1:string,2:string,3:string,4:string} */
    private function assertSourceTableSchemas(): array {
        global $wpdb;
        if (!isset($wpdb->posts, $wpdb->postmeta, $wpdb->options)
            || !is_string($wpdb->posts)
            || !is_string($wpdb->postmeta)
            || !is_string($wpdb->options)
            || !hash_equals($wpdb->prefix . 'posts', $wpdb->posts)
            || !hash_equals($wpdb->prefix . 'postmeta', $wpdb->postmeta)
            || !hash_equals($wpdb->prefix . 'options', $wpdb->options)) {
            throw new \RuntimeException(
                'wprism: TEC derived-state source locking rejected the WordPress table identities'
            );
        }
        foreach ([
            $wpdb->posts => 'posts',
            $wpdb->postmeta => 'postmeta',
            $wpdb->options => 'options',
        ] as $table => $label) {
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
                throw new \RuntimeException(
                    "wprism: TEC derived-state source locking rejected the $label table identifier"
                );
            }
            $status = $this->schemaRows(
                $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)),
                "$label source table identity"
            );
            if (count($status) !== 1
                || !is_array($status[0])
                || !is_string($status[0]['Name'] ?? null)
                || !hash_equals($table, $status[0]['Name'])
                || !is_string($status[0]['Engine'] ?? null)
                || strcasecmp($status[0]['Engine'], 'InnoDB') !== 0) {
                throw new \RuntimeException(
                    "wprism: TEC derived-state source locking rejected the $label table identity or engine"
                );
            }
        }
        $this->assertFullWidthIndex($wpdb->posts, 'PRIMARY', 'ID', true, 'posts');
        $this->assertFullWidthIndex($wpdb->postmeta, 'post_id', 'post_id', false, 'postmeta');
        $this->assertFullWidthIndex($wpdb->options, 'option_name', 'option_name', true, 'options');
        return [$wpdb->posts, $wpdb->postmeta, 'post_id', $wpdb->options, 'option_name'];
    }

    private function assertFullWidthIndex(
        string $table,
        string $index,
        string $column,
        bool $unique,
        string $context
    ): void {
        $rows = $this->schemaRows("SHOW INDEX FROM `$table`", "$context source indexes");
        if (count($rows) > self::MAX_INDEX_ROWS) {
            throw new \RuntimeException(
                "wprism: TEC derived-state source locking rejected the $context index frontier"
            );
        }
        $matched = [];
        foreach ($rows as $row) {
            if (!is_array($row)
                || !is_string($row['Key_name'] ?? null)
                || !is_string($row['Non_unique'] ?? null)
                || !is_string($row['Seq_in_index'] ?? null)
                || !is_string($row['Column_name'] ?? null)
                || !array_key_exists('Sub_part', $row)
                || !is_string($row['Index_type'] ?? null)) {
                throw new \RuntimeException(
                    "wprism: TEC derived-state source locking rejected one malformed $context index row"
                );
            }
            if ($row['Key_name'] === $index) {
                $matched[] = $row;
            }
        }
        if (count($matched) !== 1
            || $matched[0]['Non_unique'] !== ($unique ? '0' : '1')
            || $matched[0]['Seq_in_index'] !== '1'
            || $matched[0]['Column_name'] !== $column
            || $matched[0]['Sub_part'] !== null
            || strcasecmp($matched[0]['Index_type'], 'BTREE') !== 0) {
            throw new \RuntimeException(
                "wprism: TEC derived-state source locking requires full-width $context index $index"
            );
        }
    }

    private function lastSaveOptionWitness(
        string $optionsTable,
        string $optionNameIndex,
        bool $required = false,
        ?string &$autoload = null
    ): string {
        global $wpdb;
        $rows = $this->lockedRows(
            $wpdb->prepare(
                "SELECT option_id, option_name, LEFT(option_value, 257) AS option_value_prefix, "
                    . "OCTET_LENGTH(option_value) AS option_value_bytes, autoload "
                    . "FROM `$optionsTable` FORCE INDEX (`$optionNameIndex`) WHERE option_name = %s "
                    . 'ORDER BY option_id ASC LIMIT 2 FOR UPDATE',
                self::LAST_SAVE_OPTION
            ),
            'native save-post cache marker'
        );
        if ($rows === []) {
            $autoload = null;
            if ($required) {
                throw new \RuntimeException(
                    'wprism: TEC native save-post cache marker is absent after native mutation'
                );
            }
            return hash('sha256', 'absent');
        }
        $row = $rows[0] ?? null;
        if (count($rows) !== 1
            || !is_array($row)
            || array_keys($row) !== [
                'option_id',
                'option_name',
                'option_value_prefix',
                'option_value_bytes',
                'autoload',
            ]
            || $row['option_name'] !== self::LAST_SAVE_OPTION
            || !is_string($row['option_value_prefix'])
            || !is_string($row['autoload'])
            || !in_array($row['autoload'], [
                'yes',
                'no',
                'on',
                'off',
                'auto',
                'auto-on',
                'auto-off',
            ], true)) {
            throw new \RuntimeException(
                'wprism: TEC derived-state native save-post cache marker returned a malformed row'
            );
        }
        $this->positiveDriverInt($row['option_id'], 'native save-post cache marker identity');
        $autoload = $row['autoload'];
        $bytes = $this->nullableDriverSize(
            $row['option_value_bytes'],
            'native save-post cache marker length'
        );
        $value = $row['option_value_prefix'];
        if ($bytes === null
            || $bytes > 256
            || strlen($value) !== $bytes
            || preg_match('/^(?:0|[1-9][0-9]*)(?:\.[0-9]+)?(?:E[+-]?[0-9]+)?$/Di', $value) !== 1
            || !is_finite((float) $value)
            || (float) $value <= 0) {
            throw new \RuntimeException(
                'wprism: TEC derived-state native save-post cache marker is malformed or oversized'
            );
        }
        return hash(
            'sha256',
            (string) json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
        );
    }

    private function assertLastSaveAutoloadTransition(
        ?string $initialAutoload,
        ?string $currentAutoload
    ): void {
        if ($currentAutoload === null) {
            throw new \RuntimeException(
                'wprism: TEC native save-post cache marker lost its physical autoload state'
            );
        }
        if ($initialAutoload === null) {
            if (!hash_equals('auto', $currentAutoload)) {
                throw new \RuntimeException(
                    'wprism: TEC native save-post cache marker used a non-native default autoload state'
                );
            }
            return;
        }
        if (in_array($initialAutoload, ['yes', 'no', 'on', 'off'], true)) {
            if (!hash_equals($initialAutoload, $currentAutoload)) {
                throw new \RuntimeException(
                    'wprism: TEC native save-post cache marker changed a fixed physical autoload state'
                );
            }
            return;
        }
        if (!in_array($initialAutoload, ['auto', 'auto-on', 'auto-off'], true)
            || !hash_equals('auto', $currentAutoload)) {
            throw new \RuntimeException(
                'wprism: TEC native save-post cache marker returned an invalid computed autoload state'
            );
        }
    }

    private function sourceWitness(
        int $localId,
        string $postsTable,
        string $postMetaTable,
        string $postMetaIndex
    ): string {
        global $wpdb;
        $postRows = $this->lockedRows(
            $wpdb->prepare(
                "SELECT ID, post_type FROM `$postsTable` FORCE INDEX (`PRIMARY`) "
                    . 'WHERE ID = %d ORDER BY ID ASC LIMIT 2 FOR UPDATE',
                $localId
            ),
            'source post'
        );
        if (count($postRows) !== 1
            || !is_array($postRows[0])
            || array_keys($postRows[0]) !== ['ID', 'post_type']
            || $postRows[0]['ID'] !== (string) $localId
            || $postRows[0]['post_type'] !== 'tribe_events') {
            throw new \RuntimeException(
                'wprism: TEC derived-state source locking requires one exact tribe_events post row'
            );
        }

        // The first pass acquires the complete owner range and proves the
        // resource frontier without asking MySQL to hash hostile values. A
        // single SELECT that computes SHA2 before PHP sees its length can do
        // terabytes of server work before rejecting a 10k-row owner.
        $rows = $this->lockedRows(
            $wpdb->prepare(
                "SELECT meta_id, OCTET_LENGTH(meta_key) AS meta_key_bytes, "
                    . "OCTET_LENGTH(meta_value) AS meta_value_bytes "
                    . "FROM `$postMetaTable` FORCE INDEX (`$postMetaIndex`) WHERE post_id = %d "
                    . 'ORDER BY meta_id ASC LIMIT ' . (self::MAX_SOURCE_META_ROWS + 1) . ' FOR UPDATE',
                $localId
            ),
            'source post metadata'
        );
        if (count($rows) > self::MAX_SOURCE_META_ROWS) {
            throw new \RuntimeException(
                'wprism: TEC derived-state source metadata exceeds the bounded owner-row frontier'
            );
        }
        $previousId = 0;
        $ownerBytes = 0;
        $witnessRows = [];
        foreach ($rows as $position => $row) {
            if (!is_array($row)
                || array_keys($row) !== [
                    'meta_id',
                    'meta_key_bytes',
                    'meta_value_bytes',
                ]) {
                throw new \RuntimeException(
                    "wprism: TEC derived-state source metadata returned a malformed row at position $position"
                );
            }
            $metaId = $this->positiveDriverInt($row['meta_id'], 'source metadata identity');
            $keyBytes = $this->nullableDriverSize($row['meta_key_bytes'], 'source metadata key length');
            $valueBytes = $this->nullableDriverSize($row['meta_value_bytes'], 'source metadata value length');
            if ($metaId <= $previousId
                || $keyBytes !== null && $keyBytes > self::MAX_SOURCE_META_KEY_BYTES
                || $valueBytes !== null && $valueBytes > self::MAX_SOURCE_META_VALUE_BYTES) {
                throw new \RuntimeException(
                    "wprism: TEC derived-state source metadata row $position is unordered, malformed, or oversized"
                );
            }
            $rowBytes = strlen($row['meta_id']) + ($keyBytes ?? 0) + ($valueBytes ?? 0);
            if ($rowBytes > self::MAX_SOURCE_META_OWNER_BYTES - $ownerBytes) {
                throw new \RuntimeException(
                    'wprism: TEC derived-state source metadata exceeds the bounded owner-byte frontier'
                );
            }
            $ownerBytes += $rowBytes;
            $previousId = $metaId;
            $witnessRows[] = $row;
        }

        $hashRows = $this->lockedRows(
            $wpdb->prepare(
                "SELECT meta_id, SHA2(BINARY meta_key, 256) AS meta_key_sha256, "
                    . "SHA2(BINARY meta_value, 256) AS meta_value_sha256 "
                    . "FROM `$postMetaTable` FORCE INDEX (`$postMetaIndex`) WHERE post_id = %d "
                    . 'ORDER BY meta_id ASC LIMIT ' . (self::MAX_SOURCE_META_ROWS + 1) . ' FOR UPDATE',
                $localId
            ),
            'source post metadata hashes'
        );
        if (count($hashRows) !== count($witnessRows)) {
            throw new \RuntimeException(
                'wprism: TEC derived-state source metadata changed between its locked shape and hash passes'
            );
        }
        foreach ($hashRows as $position => $hashRow) {
            $shapeRow = $witnessRows[$position];
            if (!is_array($hashRow)
                || array_keys($hashRow) !== ['meta_id', 'meta_key_sha256', 'meta_value_sha256']
                || $hashRow['meta_id'] !== $shapeRow['meta_id']
                || !$this->validNullableSha256(
                    $hashRow['meta_key_sha256'],
                    $this->nullableDriverSize($shapeRow['meta_key_bytes'], 'source metadata key length')
                )
                || !$this->validNullableSha256(
                    $hashRow['meta_value_sha256'],
                    $this->nullableDriverSize($shapeRow['meta_value_bytes'], 'source metadata value length')
                )) {
                throw new \RuntimeException(
                    "wprism: TEC derived-state source metadata hash row $position is malformed or mismatched"
                );
            }
            $witnessRows[$position] += $hashRow;
        }
        $requiredHashes = array_fill_keys(array_map(
            static fn(string $key): string => hash('sha256', $key),
            self::REQUIRED_EVENT_META_KEYS
        ), 0);
        foreach ($hashRows as $hashRow) {
            $hash = $hashRow['meta_key_sha256'] ?? null;
            if (is_string($hash) && array_key_exists($hash, $requiredHashes)) {
                ++$requiredHashes[$hash];
            }
        }
        if (array_values($requiredHashes) !== array_fill(0, count($requiredHashes), 1)) {
            throw new \RuntimeException(
                'wprism: TEC derived-state source locking requires one physical row for every required event key'
            );
        }
        return hash('sha256', (string) json_encode($witnessRows, JSON_UNESCAPED_SLASHES));
    }

    private function assertPluginCallBoundary(
        string $call,
        array $nativeRuntime,
        string $connectionId,
        string $sourceWitness,
        int $localId,
        string $postsTable,
        string $postMetaTable,
        string $postMetaIndex,
        string $optionsTable,
        string $optionNameIndex,
        ?string $expectedOptionWitness,
        bool $optionRequired = false
    ): void {
        if ($this->assertNativeContainerServices($nativeRuntime['container']) !== $nativeRuntime['container']
            || tribe_cache() !== $nativeRuntime['cache']
            || \Tribe__Cache_Listener::instance() !== $nativeRuntime['listener']
            || $this->listenerCache($nativeRuntime['listener']) !== $nativeRuntime['listener_cache']
            || $nativeRuntime['listener_cache'] === $nativeRuntime['cache']) {
            throw new \RuntimeException(
                "wprism: TEC derived-state native service identity changed during $call; recovery_required"
            );
        }
        $this->assertNativeHookTopology(true, $nativeRuntime['listener']);
        $this->assertTransactionSession($connectionId, true, "after $call");
        $this->assertLocalObjectCacheTopology($call);
        if (!hash_equals(
            $sourceWitness,
            $this->sourceWitness($localId, $postsTable, $postMetaTable, $postMetaIndex)
        )) {
            throw new \RuntimeException(
                "wprism: TEC derived-state source rows changed during $call; recovery_required"
            );
        }
        $optionWitness = $this->lastSaveOptionWitness(
            $optionsTable,
            $optionNameIndex,
            $optionRequired
        );
        if ($expectedOptionWitness !== null
            && !hash_equals($expectedOptionWitness, $optionWitness)) {
            throw new \RuntimeException(
                "wprism: TEC native save-post cache marker changed during $call; recovery_required"
            );
        }
        // The source and option witnesses are separate statements. Re-prove
        // the exact server session after them so an automatic reconnect
        // cannot turn the next native write into an unlocked operation.
        $this->assertTransactionSession($connectionId, true, "after $call witness");
    }

    /**
     * Stock WordPress leaves `_wp_using_ext_object_cache` null after starting
     * its built-in cache: wp_start_object_cache() treats null as false and
     * calls wp_cache_init(), but never writes false back (pinned load.php).
     * The signal alone is therefore insufficient. Bind it to the exact core
     * cache class and absence of the object-cache drop-in before admitting
     * either normal null or an explicit false, and repeat the composite proof
     * after every plugin call while the authored transaction is active.
     */
    private function assertLocalObjectCacheTopology(?string $call = null): void {
        $externalCache = wp_using_ext_object_cache();
        global $wp_object_cache;
        $local = ($externalCache === null || $externalCache === false)
            && defined('WP_CONTENT_DIR')
            && is_object($wp_object_cache)
            && get_class($wp_object_cache) === 'WP_Object_Cache'
            && !is_file(WP_CONTENT_DIR . '/object-cache.php');
        if ($local) {
            return;
        }
        if ($call !== null) {
            throw new \RuntimeException(
                "wprism: TEC derived-state local object-cache topology changed during $call; recovery_required"
            );
        }
        if ($externalCache === true) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration does not admit an external object-cache topology'
            );
        }
        if ($externalCache !== null && $externalCache !== false) {
            throw new \RuntimeException(
                'wprism: TEC derived-state regeneration rejected a malformed external object-cache signal'
            );
        }
        throw new \RuntimeException(
            'wprism: TEC derived-state regeneration requires the exact local WordPress object-cache topology'
        );
    }

    /** @param array<string,mixed> $eventData @return array<string,string> */
    private function expectedEventData(array $eventData, int $localId): array {
        $keys = array_keys($eventData);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'duration',
            'end_date',
            'end_date_utc',
            'hash',
            'post_id',
            'start_date',
            'start_date_utc',
            'timezone',
        ]) {
            throw new \RuntimeException(
                'wprism: TEC Event::data_from_post() returned an unexpected field set'
            );
        }
        if (($eventData['post_id'] ?? null) !== $localId) {
            throw new \RuntimeException(
                'wprism: TEC Event::data_from_post() returned a mismatched post_id'
            );
        }
        $expected = ['post_id' => (string) $localId];
        foreach ([
            'start_date',
            'end_date',
            'start_date_utc',
            'end_date_utc',
            'timezone',
        ] as $field) {
            if (!is_string($eventData[$field] ?? null) || $eventData[$field] === '') {
                throw new \RuntimeException(
                    "wprism: TEC Event::data_from_post() returned a malformed $field"
                );
            }
            $expected[$field] = $eventData[$field];
        }
        foreach (['start_date', 'end_date', 'start_date_utc', 'end_date_utc'] as $field) {
            if (!$this->validDatabaseTimestamp($expected[$field])) {
                throw new \RuntimeException(
                    "wprism: TEC Event::data_from_post() returned a malformed $field"
                );
            }
        }
        try {
            new \DateTimeZone($expected['timezone']);
        } catch (\Throwable) {
            throw new \RuntimeException(
                'wprism: TEC Event::data_from_post() returned a malformed timezone'
            );
        }
        $duration = $eventData['duration'] ?? null;
        if (!(is_int($duration) || is_string($duration))
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', (string) $duration) !== 1
            || strlen((string) $duration) > 7
            || (int) $duration > 8388607) {
            throw new \RuntimeException(
                'wprism: TEC Event::data_from_post() returned a malformed duration'
            );
        }
        if (($eventData['hash'] ?? null) !== '') {
            throw new \RuntimeException(
                'wprism: TEC Event::data_from_post() returned a non-native hash'
            );
        }
        $expected['duration'] = (string) $duration;
        $expected['hash'] = '';
        return $expected;
    }

    private function lockDerivedOwnerRanges(
        int $localId,
        ?int $eventId,
        string $eventTable,
        string $occurrenceTable,
        ?int $expectedExistingEventId = null
    ): ?int {
        global $wpdb;
        $eventRows = $this->lockedRows(
            $wpdb->prepare(
                "SELECT event_id, post_id FROM `$eventTable` FORCE INDEX (`post_id`) "
                    . 'WHERE post_id = %d ORDER BY event_id ASC LIMIT 3 FOR UPDATE',
                $localId
            ),
            'tec_events owner range'
        );
        if (count($eventRows) > 1) {
            throw new \RuntimeException(
                'wprism: TEC derived-state locking rejected duplicate tec_events owner rows'
            );
        }
        $currentEventId = null;
        if ($eventRows !== []) {
            $row = $eventRows[0];
            if (!is_array($row)
                || array_keys($row) !== ['event_id', 'post_id']
                || $row['post_id'] !== (string) $localId) {
                throw new \RuntimeException(
                    'wprism: TEC derived-state locking rejected a malformed tec_events owner row'
                );
            }
            $currentEventId = $this->positiveDriverInt($row['event_id'], 'tec_events identity');
        }
        if ($eventId !== null && $currentEventId !== $eventId) {
            throw new \RuntimeException(
                'wprism: TEC derived-state locking found event identity drift after native upsert'
            );
        }
        if ($expectedExistingEventId !== null && $currentEventId !== $expectedExistingEventId) {
            throw new \RuntimeException(
                'wprism: TEC derived-state locking found an unexpected event identity replacement'
            );
        }

        $byPost = $this->lockedRows(
            $wpdb->prepare(
                "SELECT occurrence_id, event_id, post_id FROM `$occurrenceTable` "
                    . "FORCE INDEX (`idx_wp_tec_occurrences_post_id_dates`) WHERE post_id = %d "
                    . 'ORDER BY occurrence_id ASC LIMIT 3 FOR UPDATE',
                $localId
            ),
            'tec_occurrences post owner range'
        );
        $byEvent = [];
        if ($currentEventId !== null) {
            $byEvent = $this->lockedRows(
                $wpdb->prepare(
                    "SELECT occurrence_id, event_id, post_id FROM `$occurrenceTable` FORCE INDEX (`event_id`) "
                        . 'WHERE event_id = %d ORDER BY occurrence_id ASC LIMIT 3 FOR UPDATE',
                    $currentEventId
                ),
                'tec_occurrences event owner range'
            );
        }
        $occurrences = [];
        foreach (array_merge($byPost, $byEvent) as $row) {
            if (!is_array($row)
                || array_keys($row) !== ['occurrence_id', 'event_id', 'post_id']
                || $row['post_id'] !== (string) $localId
                || $currentEventId === null
                || $row['event_id'] !== (string) $currentEventId) {
                throw new \RuntimeException(
                    'wprism: TEC derived-state locking rejected a cross-linked occurrence row'
                );
            }
            $occurrenceId = $this->positiveDriverInt($row['occurrence_id'], 'occurrence identity');
            $occurrences[$occurrenceId] = $row;
        }
        if (count($occurrences) > 1) {
            throw new \RuntimeException(
                'wprism: TEC derived-state locking rejected duplicate free-event occurrence rows'
            );
        }
        return $currentEventId;
    }

    private function derivedOwnerWitness(
        int $localId,
        ?int $eventId,
        string $eventTable,
        string $occurrenceTable
    ): string {
        global $wpdb;
        $eventLookupId = $eventId ?? 0;
        $eventFields = array_reverse(self::EVENT_ROW_FIELDS);
        $eventRows = $this->checkedDriverRows(
            $this->lockedRows(
                $wpdb->prepare(
                    "SELECT hash, updated_at, duration, timezone, end_date_utc, start_date_utc, "
                        . "end_date, start_date, post_id, event_id FROM `$eventTable` "
                        . 'WHERE event_id = %d OR post_id = %d ORDER BY event_id LIMIT 3 FOR UPDATE',
                    $eventLookupId,
                    $localId
                ),
                'exact tec_events owner witness'
            ),
            $eventFields,
            'tec_events owner witness'
        );
        $occurrenceFields = array_reverse(self::OCCURRENCE_ROW_FIELDS);
        $occurrenceRows = $this->checkedDriverRows(
            $this->lockedRows(
                $wpdb->prepare(
                    "SELECT hash, updated_at, duration, end_date_utc, start_date_utc, end_date, "
                        . "start_date, post_id, event_id, occurrence_id FROM `$occurrenceTable` "
                        . 'WHERE event_id = %d OR post_id = %d ORDER BY occurrence_id LIMIT 3 FOR UPDATE',
                    $eventLookupId,
                    $localId
                ),
                'exact tec_occurrences owner witness'
            ),
            $occurrenceFields,
            'tec_occurrences owner witness'
        );
        return hash('sha256', (string) json_encode(
            [$eventRows, $occurrenceRows],
            JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        ));
    }

    /** @return list<array<string,mixed>> */
    private function lockedRows(string $sql, string $context): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || !array_is_list($rows) || $wpdb->last_error !== '') {
            throw new \RuntimeException(
                "wprism: TEC derived-state locking could not read $context"
            );
        }
        return $rows;
    }

    private function positiveDriverInt(mixed $value, string $context): int {
        if (!is_string($value)
            || preg_match('/^[1-9][0-9]*$/D', $value) !== 1
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || strlen($value) === strlen((string) PHP_INT_MAX)
                && strcmp($value, (string) PHP_INT_MAX) > 0) {
            throw new \RuntimeException(
                "wprism: TEC derived-state $context is not a bounded positive driver integer"
            );
        }
        return (int) $value;
    }

    private function nullableDriverSize(mixed $value, string $context): ?int {
        if ($value === null) {
            return null;
        }
        if (!is_string($value)
            || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1
            || strlen($value) > strlen((string) PHP_INT_MAX)
            || strlen($value) === strlen((string) PHP_INT_MAX)
                && strcmp($value, (string) PHP_INT_MAX) > 0) {
            throw new \RuntimeException(
                "wprism: TEC derived-state $context is not a bounded driver size"
            );
        }
        return (int) $value;
    }

    private function validNullableSha256(mixed $hash, ?int $bytes): bool {
        if ($bytes === null) {
            return $hash === null;
        }
        return is_string($hash) && preg_match('/^[a-f0-9]{64}$/D', $hash) === 1;
    }

    private function purgeSourceCaches(int $localId): void {
        $failed = false;
        foreach (self::SOURCE_CACHE_KEYS as $cache) {
            $key = $cache['key'] === 'post-id' ? (string) $localId : $cache['key'];
            $group = $cache['group'];
            try {
                $deleted = wp_cache_delete($key, $group);
                $found = null;
                wp_cache_get($key, $group, false, $found);
                if (!is_bool($deleted) || !is_bool($found) || $found) {
                    $failed = true;
                }
            } catch (\Throwable) {
                // Continue through the closed key set. A failure on the first
                // key must not leave later source/cache-generation entries
                // unvisited in the long-running apply process.
                $failed = true;
            }
        }
        foreach (self::NATIVE_CACHE_GROUPS as $group) {
            try {
                $flushed = wp_cache_flush_group($group);
                if (!is_bool($flushed) || !$flushed) {
                    $failed = true;
                }
            } catch (\Throwable) {
                $failed = true;
            }
        }
        if ($failed) {
            throw new \RuntimeException(
                'wprism: TEC derived-state native cache effects could not be purged; recovery_required'
            );
        }
    }

    /** @param array<string,string> $expectedEvent */
    private function verifyDerivedRows(
        int $localId,
        int $eventId,
        string $eventTable,
        string $occurrenceTable,
        array $expectedEvent
    ): void {
        global $wpdb;
        // Native model calls can handle a driver error and leave the public
        // wpdb field populated. Only each exact read may decide its outcome.
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
                'wprism: TEC derived-state verification query failed for tec_events'
            );
        }
        if (!is_array($eventRows)) {
            throw new \RuntimeException(
                'wprism: TEC derived-state verification query returned a non-array for tec_events'
            );
        }
        $eventRows = $this->checkedDriverRows($eventRows, self::EVENT_ROW_FIELDS, 'tec_events');
        $expectedEvent['event_id'] = (string) $eventId;
        $eventMismatches = $this->rowMismatches($eventRows, $expectedEvent);
        if (count($eventRows) === 1 && !$this->validDatabaseTimestamp($eventRows[0]['updated_at'] ?? null)) {
            $eventMismatches[] = 'updated_at';
        }
        if ($eventMismatches !== []) {
            throw new \RuntimeException(
                'wprism: TEC derived-state verification failed for tec_events fields: '
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
                'wprism: TEC derived-state verification query failed for tec_occurrences'
            );
        }
        if (!is_array($occurrenceRows)) {
            throw new \RuntimeException(
                'wprism: TEC derived-state verification query returned a non-array for tec_occurrences'
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
                'wprism: TEC derived-state verification failed for tec_occurrences fields: '
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
                'wprism: TEC derived-state schema preflight requires one safe WordPress table prefix and database reader'
            );
        }
        $tables = [];
        foreach (self::TABLE_SCHEMAS as $suffix => $expected) {
            $table = $wpdb->prefix . $suffix;
            if (strlen($table) > 64 || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $table) !== 1) {
                throw new \RuntimeException(
                    "wprism: TEC derived-state schema preflight rejected the $suffix table identifier"
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
                "wprism: TEC derived-state schema preflight rejected the $suffix table identity or engine"
            );
        }

        $columns = $this->schemaRows("SHOW FULL COLUMNS FROM `$table`", "$suffix columns");
        if (count($columns) !== count($expected['columns'])) {
            throw new \RuntimeException(
                "wprism: TEC derived-state schema preflight rejected the $suffix column count"
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
                    "wprism: TEC derived-state schema preflight rejected $suffix column $columnName"
                );
            }
        }

        $indexRows = $this->schemaRows("SHOW INDEX FROM `$table`", "$suffix indexes");
        if (count($indexRows) > self::MAX_INDEX_ROWS) {
            throw new \RuntimeException(
                "wprism: TEC derived-state schema preflight rejected the $suffix index row frontier"
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
                    "wprism: TEC derived-state schema preflight rejected one $suffix index row"
                );
            }
            $name = $row['Key_name'];
            $sequence = (int) $row['Seq_in_index'];
            $unique = $row['Non_unique'] === '0';
            if (isset($indexes[$name]['columns'][$sequence])
                || isset($indexes[$name]) && $indexes[$name]['unique'] !== $unique) {
                throw new \RuntimeException(
                    "wprism: TEC derived-state schema preflight rejected duplicated $suffix index metadata"
                );
            }
            $indexes[$name]['unique'] = $unique;
            $indexes[$name]['columns'][$sequence] = $row['Column_name'];
        }
        foreach ($indexes as $name => &$index) {
            ksort($index['columns'], SORT_NUMERIC);
            if (array_keys($index['columns']) !== range(1, count($index['columns']))) {
                throw new \RuntimeException(
                    "wprism: TEC derived-state schema preflight rejected non-contiguous $suffix index metadata"
                );
            }
            $index['columns'] = array_values($index['columns']);
            if ($index['unique'] && !isset($expected['indexes'][$name])) {
                throw new \RuntimeException(
                    "wprism: TEC derived-state schema preflight rejected an unknown unique $suffix index"
                );
            }
        }
        unset($index);
        foreach ($expected['indexes'] as $name => $indexExpected) {
            if (($indexes[$name] ?? null) !== $indexExpected) {
                throw new \RuntimeException(
                    "wprism: TEC derived-state schema preflight rejected required $suffix index $name"
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
                "wprism: TEC derived-state schema preflight could not read $context"
            );
        }
        return $rows;
    }

    private function postMetaString(int $localId, string $key): string {
        $value = get_post_meta($localId, $key, true);
        if (!is_string($value)) {
            throw new \RuntimeException(
                "wprism: TEC derived-state verification requires $key to be one scalar string"
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
                "wprism: TEC derived-state verification requires $key to be one exact UTC database timestamp"
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
                "wprism: TEC derived-state verification query returned a non-list for $table"
            );
        }
        foreach ($rows as $row) {
            if (!is_array($row) || array_keys($row) !== $fields) {
                throw new \RuntimeException(
                    "wprism: TEC derived-state verification query returned a malformed driver row for $table"
                );
            }
            foreach ($row as $value) {
                if (!is_string($value)) {
                    throw new \RuntimeException(
                        "wprism: TEC derived-state verification query returned a non-string driver value for $table"
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
                'wprism: TEC deletion regeneration is unsupported; event/venue/organizer cascade semantics '
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
