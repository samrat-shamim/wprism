<?php
declare(strict_types=1);

namespace Duo\Providers;

use Automattic\WooCommerce\Admin\Features\Features;
use Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler;
use Automattic\WooCommerce\Internal\StockNotifications\DataRetentionController;

/** Exact WooCommerce 11.0.0/11.0.1 scheduler repair with crash-safe transition evidence. */
final class WoocommerceSchedulerSettings {
    private const ANALYTICS_CAPABILITY = 'reconcile_analytics_import_schedule';
    private const RETENTION_CAPABILITY = 'reconcile_stock_notification_retention';
    private const ANALYTICS_OPTION = 'woocommerce_analytics_scheduled_import';
    private const RETENTION_OPTION = 'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold';
    private const MARKER_OPTION = '_duo_woocommerce_scheduler_settings_state';
    private const CURSOR_DATE_OPTION = 'woocommerce_admin_scheduler_last_processed_order_modified_date';
    private const CURSOR_ID_OPTION = 'woocommerce_admin_scheduler_last_processed_order_id';
    private const ACTION_SCHEDULER_SCHEMA_OPTION = 'schema-ActionScheduler_StoreSchema';
    private const ANALYTICS_HOOK = 'wc-admin_process_pending_orders_batch';
    private const ANALYTICS_GROUP = 'wc-admin-data';
    private const RETENTION_HOOK = 'customer_stock_notifications_daily';
    private const MARKER_FORMAT = 'duo-woocommerce-scheduler-state/v1';
    private const MAX_ACTIONS = 16;
    private const MAX_ACTION_SCHEDULE_BYTES = 4096;
    private const MAX_ACTION_ARGS_BYTES = 191;
    private const MAX_LOGS_PER_ACTION = 32;
    private const MAX_LOG_MESSAGE_BYTES = 1024;
    private const ACTION_HORIZON_SECONDS = 604800;
    private const MAX_CRON_EVENTS = 100000;
    private const MAX_CRON_BYTES = 16777216;
    private const MAX_RETENTION_DAYS = 3650000;
    private const MAX_MARKER_BYTES = 4096;
    private const MAX_SCHEMA_OPTION_BYTES = 64;
    private const RETENTION_PAST_GRACE_SECONDS = 300;
    private const RETENTION_FUTURE_GRACE_SECONDS = 300;
    private const MUTEX_PREFIX = 'duo:woocommerce:scheduler:';
    private const TABLE_IDENTIFIER_PATTERN = '/^[A-Za-z0-9_]{1,64}$/D';
    private const NATIVE_ANALYTICS_INTERVAL = 43200;
    private const NATIVE_ACTION_SCHEDULER_SCHEMA_VERSION = 8;

    /** @var ?array{name:string,connection:int} */
    private static ?array $activeMutex = null;

    public function __construct(\Duo\Policy $policy) {
    }

    /** @return array{id:string,plugin:string,version:string} */
    public function identity(): array {
        return [
            'id' => 'woocommerce-scheduler-settings',
            'plugin' => 'woocommerce/woocommerce.php',
            'version' => '1.0.0',
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public function capabilities(): array {
        $capabilities = [
            self::ANALYTICS_CAPABILITY => [
                'args' => [],
                'reads' => [
                    'option:' . self::ANALYTICS_OPTION,
                    'entity:woocommerce-analytics-scheduler-marker',
                    'option:' . self::CURSOR_DATE_OPTION,
                    'option:' . self::CURSOR_ID_OPTION,
                    'entity:woocommerce-action-scheduler-store-schema',
                    'entity:woocommerce-analytics-import-schedule',
                ],
                'writes' => [
                    'entity:woocommerce-analytics-scheduler-marker',
                    'option:' . self::CURSOR_DATE_OPTION,
                    'option:' . self::CURSOR_ID_OPTION,
                    'table:actionscheduler_actions',
                    'table:actionscheduler_claims',
                    'table:actionscheduler_groups',
                    'table:actionscheduler_logs',
                ],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 60,
                'scoped' => [
                    'operation_envelope' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
                    'reconcile' => true,
                ],
            ],
            self::RETENTION_CAPABILITY => [
                'args' => [],
                'reads' => [
                    'option:' . self::RETENTION_OPTION,
                    'entity:wordpress-cron-' . self::RETENTION_HOOK,
                ],
                'writes' => ['option:cron'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 30,
                'scoped' => [
                    'operation_envelope' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
                    'reconcile' => true,
                ],
            ],
        ];

        if (!\Duo\Providers::runtime_negotiation_available()) {
            return $capabilities;
        }
        try {
            self::assert_analytics_prerequisites();
            self::analytics_option_state();
            self::marker_state();
            self::cursor_state();
            self::analytics_topology();
        } catch (\Throwable $failure) {
            unset($capabilities[self::ANALYTICS_CAPABILITY]);
        }
        try {
            self::assert_retention_prerequisites();
            self::retention_option_state();
            self::retention_topology();
        } catch (\Throwable $failure) {
            unset($capabilities[self::RETENTION_CAPABILITY]);
        }
        return $capabilities;
    }

    /** @param array<string,mixed> $args */
    public function invoke(string $capability, array $args): array {
        self::assert_call($capability, $args);
        return match ($capability) {
            self::ANALYTICS_CAPABILITY => self::repair_analytics(hash('sha256', 'unscoped'), false),
            self::RETENTION_CAPABILITY => self::repair_retention(),
            default => throw new \RuntimeException(
                "duo: WooCommerce scheduler-settings provider does not implement capability '$capability'"
            ),
        };
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function invoke_scoped(string $capability, array $args, array $operation): array {
        self::assert_call($capability, $args);
        $operation = \Duo\Providers::validate_scoped_operation($operation);
        $receipt = match ($capability) {
            self::ANALYTICS_CAPABILITY => self::repair_analytics(self::digest($operation), true),
            self::RETENTION_CAPABILITY => self::repair_retention(),
            default => throw new \RuntimeException(
                "duo: WooCommerce scheduler-settings provider does not implement capability '$capability'"
            ),
        };
        return [
            'operation' => $operation,
            'before' => $receipt['before'],
            'after' => $receipt['after'],
            'verified' => true,
        ];
    }

    /** Read-only recovery; it must never replay a transition or repair. */
    public function reconcile_scoped(string $capability, array $args, array $operation): array {
        self::assert_call($capability, $args);
        $operation = \Duo\Providers::validate_scoped_operation($operation);
        $after = match ($capability) {
            self::ANALYTICS_CAPABILITY => self::analytics_stable_projection(),
            self::RETENTION_CAPABILITY => self::retention_stable_projection(),
            default => throw new \RuntimeException(
                "duo: WooCommerce scheduler-settings provider does not implement capability '$capability'"
            ),
        };
        return [
            'operation' => $operation,
            'after' => $after,
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args */
    private static function assert_call(string $capability, array $args): void {
        if (!in_array($capability, [self::ANALYTICS_CAPABILITY, self::RETENTION_CAPABILITY], true)) {
            throw new \RuntimeException(
                "duo: WooCommerce scheduler-settings provider does not implement capability '$capability'"
            );
        }
        if ($args !== []) {
            throw new \RuntimeException('duo: WooCommerce scheduler-settings capabilities accept no arguments');
        }
    }

    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    private static function repair_analytics(string $operationHash, bool $allowIntentResume): array {
        return self::with_provider_mutex(
            static fn(array $mutex): array => self::repair_analytics_locked(
                $operationHash,
                $allowIntentResume,
                $mutex
            )
        );
    }

    /** @param array{name:string,connection:int} $mutex */
    private static function repair_analytics_locked(
        string $operationHash,
        bool $allowIntentResume,
        array $mutex
    ): array {
        self::assert_analytics_prerequisites();
        $fence = self::acquire_analytics_claim_fence($mutex);
        $workFailure = null;
        $before = null;
        $after = null;
        try {
            self::assert_provider_mutex($mutex);
            $source = self::analytics_option_state();
            $interval = self::analytics_interval();
            $topology = self::analytics_topology($fence);
            $cursors = self::cursor_state();
            $marker = self::marker_state();
            $before = self::analytics_exact_receipt($source, $marker, $topology, $cursors, $interval);

            if (($marker['phase'] ?? null) === 'verified'
                && ($marker['data']['state'] ?? null) === $source['desired']
                && self::analytics_topology_is_stable($source['desired'], $topology, $interval)) {
                $after = self::analytics_stable_projection($fence);
            } else {
                if (($marker['phase'] ?? null) === 'intent') {
                    $intent = $marker['data'];
                    if (!$allowIntentResume) {
                        throw new \RuntimeException(
                            'duo: WooCommerce analytics unscoped invocation cannot adopt a pre-existing '
                            . 'transition intent; manual recovery is required'
                        );
                    }
                    if (!hash_equals($intent['origin_operation_sha256'], $operationHash)) {
                        throw new \RuntimeException(
                            'duo: WooCommerce analytics transition intent belongs to another operation; '
                            . 'manual recovery is required before a new scoped operation can proceed'
                        );
                    }
                } else {
                    $intent = self::new_intent($source, $marker, $topology, $cursors, $interval, $operationHash);
                    self::assert_plan_preconditions($intent, $topology);
                    self::write_marker($intent);
                    self::assert_provider_mutex($mutex);
                    self::assert_analytics_claim_fence($fence);
                    self::assert_intent_source($intent);
                    $currentTopology = self::analytics_topology($fence);
                    $currentCursors = self::cursor_state();
                    if (!hash_equals($intent['before_topology_sha256'], $currentTopology['sha256'])
                        || !hash_equals($intent['before_cursor_date_sha256'], $currentCursors['date_sha256'])
                        || $intent['before_cursor_date_present'] !== $currentCursors['date_present']
                        || $intent['before_cursor_date_autoload'] !== $currentCursors['date_autoload']
                        || !hash_equals($intent['before_cursor_id_sha256'], $currentCursors['id_sha256'])
                        || $intent['before_cursor_id_present'] !== $currentCursors['id_present']
                        || $intent['before_cursor_id_autoload'] !== $currentCursors['id_autoload']) {
                        throw new \RuntimeException(
                            'duo: WooCommerce analytics transition witnesses changed while its durable intent was written; recovery_required'
                        );
                    }
                }

                self::resume_analytics_intent($intent, $fence, $mutex);
                self::write_marker(self::terminal_marker($intent['to'], $operationHash));
                self::assert_provider_mutex($mutex);
                self::assert_analytics_claim_fence($fence);
                $after = self::analytics_stable_projection($fence);
            }
        } catch (\Throwable $failure) {
            $workFailure = $failure;
        }

        $releaseFailure = null;
        try {
            self::release_analytics_claim_fence($fence, $mutex);
        } catch (\Throwable $failure) {
            $releaseFailure = $failure;
        }
        if ($workFailure !== null) {
            if ($releaseFailure !== null) {
                throw new \RuntimeException(
                    $workFailure->getMessage()
                    . '; additionally the native claim cleanup failed and recovery_required',
                    0,
                    $workFailure
                );
            }
            throw $workFailure;
        }
        if ($releaseFailure !== null) {
            throw $releaseFailure;
        }
        if (!is_array($before) || !is_array($after)) {
            throw new \RuntimeException('duo: WooCommerce analytics repair produced no exact receipt');
        }

        // A process death after the terminal marker but before the framework's
        // scoped receipt is intentionally not replayable by reconcile_scoped:
        // Providers owns that outer intent and will require manual recovery.
        self::assert_provider_mutex($mutex);
        if (self::analytics_stable_projection() !== $after) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics projection changed while its native claim fence was released; recovery_required'
            );
        }
        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /** @return array<string,mixed> */
    private static function new_intent(
        array $source,
        array $marker,
        array $topology,
        array $cursors,
        int $interval,
        string $operationHash
    ): array {
        $from = ($marker['phase'] ?? null) === 'verified' ? $marker['data']['state'] : 'unknown';
        $to = $source['desired'];
        $plan = match (true) {
            $from === 'no' && $to === 'yes' => 'no_to_yes',
            $from === 'yes' && $to === 'no' => 'yes_to_no',
            $from === 'yes' && $to === 'yes' => 'steady_yes',
            $from === 'no' && $to === 'no' => 'steady_no',
            $to === 'yes' => 'adopt_yes',
            $topology['recurring'] > 0 => 'adopt_to_no',
            default => 'adopt_no',
        };
        $catchupRequired = in_array($plan, ['yes_to_no', 'adopt_to_no'], true)
            || (in_array($plan, ['steady_no', 'adopt_no'], true) && $topology['catchup'] > 0);

        return [
            'before_cursor_date_autoload' => $cursors['date_autoload'],
            'before_cursor_date_present' => $cursors['date_present'],
            'before_cursor_date_sha256' => $cursors['date_sha256'],
            'before_cursor_id_autoload' => $cursors['id_autoload'],
            'before_cursor_id_present' => $cursors['id_present'],
            'before_cursor_id_sha256' => $cursors['id_sha256'],
            'before_source_sha256' => self::digest($source),
            'before_topology_sha256' => $topology['sha256'],
            'before_work_sha256' => $topology['work_sha256'],
            'catchup_required' => $catchupRequired ? 1 : 0,
            'expected_cursor_date' => $plan === 'no_to_yes'
                ? gmdate('Y-m-d H:i:s', time() - MINUTE_IN_SECONDS)
                : '',
            'format' => self::MARKER_FORMAT,
            'from' => $from,
            'interval' => $interval,
            'origin_operation_sha256' => $operationHash,
            'phase' => 'intent',
            'plan' => $plan,
            'to' => $to,
        ];
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $topology */
    private static function assert_plan_preconditions(array $intent, array $topology): void {
        if (in_array($intent['plan'], ['no_to_yes', 'steady_yes', 'adopt_yes'], true)
            && ($topology['recurring'] !== 1
                || $topology['recurring_interval'] !== $intent['interval'])
            && $topology['work'] > 0) {
            // Woo's public recurring scheduler checks the whole hook rather
            // than exact args/group. Waiting for the native continuation to
            // finish is the only way to avoid deleting or blessing it.
            throw new \RuntimeException(
                'duo: WooCommerce analytics recurring repair is blocked by a pending native continuation; retry after it completes'
            );
        }
    }

    /** @param array<string,mixed> $intent */
    private static function resume_analytics_intent(array $intent, array &$fence, array $mutex): void {
        self::assert_intent_schema($intent);
        self::assert_provider_mutex($mutex);
        self::assert_analytics_claim_fence($fence);
        self::assert_intent_source($intent);
        $state = self::intent_runtime_state($intent, $fence, $mutex);
        self::assert_safe_intent_phase($intent, $state['topology'], $state['cursors']);

        if ($intent['plan'] === 'no_to_yes') {
            if (!self::cursor_date_is_expected($intent, $state['cursors'])) {
                self::write_cursor(self::CURSOR_DATE_OPTION, $intent['expected_cursor_date']);
                $state = self::intent_runtime_state($intent, $fence, $mutex);
                self::assert_safe_intent_phase($intent, $state['topology'], $state['cursors']);
            }
            if (!self::cursor_id_is_reset($state['cursors'], $intent)) {
                self::write_cursor(self::CURSOR_ID_OPTION, 0);
                $state = self::intent_runtime_state($intent, $fence, $mutex);
                self::assert_safe_intent_phase($intent, $state['topology'], $state['cursors']);
            }
            if (!self::cursor_date_is_expected($intent, $state['cursors'])
                || !self::cursor_id_is_reset($state['cursors'], $intent)) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics cursor transition did not converge; recovery_required'
                );
            }
        }

        if ($intent['to'] === 'yes') {
            self::converge_scheduled_topology($intent, $fence, $mutex);
        } else {
            self::converge_immediate_topology($intent, $fence, $mutex);
        }
        self::assert_intent_source($intent);

        $finalTopology = self::analytics_topology($fence);
        $finalCursors = self::cursor_state();
        self::assert_work_unchanged($intent, $finalTopology);
        if ($intent['to'] === 'yes') {
            if ($finalTopology['recurring'] !== 1
                || $finalTopology['recurring_interval'] !== $intent['interval']
                || $finalTopology['catchup'] !== 0) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics recurring schedule did not converge; recovery_required'
                );
            }
            if ($intent['plan'] === 'no_to_yes'
                && (!self::cursor_date_is_expected($intent, $finalCursors)
                    || !self::cursor_id_is_reset($finalCursors, $intent))) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics cursor reset drifted before transition verification; recovery_required'
                );
            }
        } elseif ($finalTopology['recurring'] !== 0
            || $finalTopology['catchup'] > 1
            || ($intent['catchup_required'] === 1 && $finalTopology['catchup'] !== 1)) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics immediate schedule did not converge; recovery_required'
            );
        }
    }

    /** @param array<string,mixed> $intent */
    private static function converge_scheduled_topology(array $intent, array &$fence, array $mutex): void {
        $state = self::intent_runtime_state($intent, $fence, $mutex);
        $topology = $state['topology'];
        if ($topology['catchup'] > 0) {
            self::unschedule_analytics([null, null], $fence, $mutex);
            $state = self::intent_runtime_state($intent, $fence, $mutex);
            $topology = $state['topology'];
        }
        if ($topology['recurring'] !== 1 || $topology['recurring_interval'] !== $intent['interval']) {
            if ($topology['recurring'] > 0) {
                self::unschedule_analytics([], $fence, $mutex);
                $state = self::intent_runtime_state($intent, $fence, $mutex);
                $topology = $state['topology'];
            }
            if ($topology['work'] > 0) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics recurring repair is blocked by a pending native continuation; retry after it completes'
                );
            }
            self::schedule_and_claim('recurring', $fence, $mutex);
            self::intent_runtime_state($intent, $fence, $mutex);
        }
    }

    /** @param array<string,mixed> $intent */
    private static function converge_immediate_topology(array $intent, array &$fence, array $mutex): void {
        $state = self::intent_runtime_state($intent, $fence, $mutex);
        $topology = $state['topology'];
        if ($topology['recurring'] > 0) {
            self::unschedule_analytics([], $fence, $mutex);
            $state = self::intent_runtime_state($intent, $fence, $mutex);
            $topology = $state['topology'];
        }
        if ($topology['catchup'] > 1) {
            self::unschedule_analytics([null, null], $fence, $mutex);
            $state = self::intent_runtime_state($intent, $fence, $mutex);
            $topology = $state['topology'];
        }
        if ($intent['catchup_required'] === 1 && $topology['catchup'] === 0) {
            self::schedule_and_claim('catchup', $fence, $mutex);
            self::intent_runtime_state($intent, $fence, $mutex);
        }
    }

    /** @param array<string,mixed> $intent @return array{topology:array<string,mixed>,cursors:array<string,mixed>} */
    private static function intent_runtime_state(array $intent, array $fence, array $mutex): array {
        self::assert_provider_mutex($mutex);
        self::assert_analytics_claim_fence($fence);
        self::assert_intent_source($intent);
        $marker = self::marker_state();
        if (($marker['phase'] ?? null) !== 'intent' || $marker['data'] !== $intent) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics transition marker changed concurrently; recovery_required'
            );
        }
        $topology = self::analytics_topology($fence);
        self::assert_work_unchanged($intent, $topology);
        $cursors = self::cursor_state();
        self::assert_safe_intent_phase($intent, $topology, $cursors);
        return ['topology' => $topology, 'cursors' => $cursors];
    }

    /** @param array<string,mixed> $intent */
    private static function assert_intent_source(array $intent): void {
        $source = self::analytics_option_state();
        if (!hash_equals($intent['before_source_sha256'], self::digest($source))
            || $source['desired'] !== $intent['to']
            || self::analytics_interval() !== $intent['interval']) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics setting or native interval changed during transition; recovery_required'
            );
        }
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $topology */
    private static function assert_work_unchanged(array $intent, array $topology): void {
        if (!hash_equals($intent['before_work_sha256'], $topology['work_sha256'])) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics continuation changed during an in-progress transition; recovery_required'
            );
        }
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $topology @param array<string,mixed> $cursors */
    private static function assert_safe_intent_phase(array $intent, array $topology, array $cursors): void {
        self::assert_work_unchanged($intent, $topology);
        $topologyIsBefore = hash_equals($intent['before_topology_sha256'], $topology['sha256']);
        if ($intent['to'] === 'yes') {
            $topologyIsPartial = $topology['catchup'] === 0
                && $topology['recurring'] <= 1
                && ($topology['recurring'] === 0
                    || $topology['recurring_interval'] === $intent['interval']);
        } else {
            $topologyIsPartial = $topology['recurring'] === 0 && $topology['catchup'] <= 1;
        }
        if (!$topologyIsBefore && !$topologyIsPartial) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics transition has an ambiguous partial schedule; recovery_required'
            );
        }

        $dateBefore = self::cursor_component_is_before(
            $cursors['date_present'],
            $cursors['date_sha256'],
            $cursors['date_autoload'],
            $intent['before_cursor_date_present'],
            $intent['before_cursor_date_sha256'],
            $intent['before_cursor_date_autoload']
        );
        $idBefore = self::cursor_component_is_before(
            $cursors['id_present'],
            $cursors['id_sha256'],
            $cursors['id_autoload'],
            $intent['before_cursor_id_present'],
            $intent['before_cursor_id_sha256'],
            $intent['before_cursor_id_autoload']
        );
        if ($intent['plan'] !== 'no_to_yes') {
            if (!$dateBefore || !$idBefore) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics cursors changed during a non-reset transition; recovery_required'
                );
            }
            return;
        }
        $dateExpected = self::cursor_date_is_expected($intent, $cursors);
        $idReset = self::cursor_id_is_reset($cursors, $intent);
        if (!(($dateBefore && $idBefore) || ($dateExpected && $idBefore) || ($dateExpected && $idReset))) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics transition has an ambiguous partial cursor reset; recovery_required'
            );
        }
    }

    private static function cursor_component_is_before(
        int $present,
        string $sha256,
        ?string $autoload,
        int $beforePresent,
        string $beforeSha256,
        ?string $beforeAutoload
    ): bool {
        return $present === $beforePresent
            && $autoload === $beforeAutoload
            && hash_equals($beforeSha256, $sha256);
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $cursors */
    private static function cursor_date_is_expected(array $intent, array $cursors): bool {
        return $cursors['date_present'] === 1
            && is_string($cursors['date_raw'])
            && self::cursor_autoload_is_expected(
                $cursors['date_autoload'],
                $intent['before_cursor_date_present'],
                $intent['before_cursor_date_autoload']
            )
            && hash_equals($intent['expected_cursor_date'], $cursors['date_raw']);
    }

    /** @param array<string,mixed> $cursors */
    private static function cursor_id_is_reset(array $cursors, array $intent): bool {
        return $cursors['id_present'] === 1
            && $cursors['id_raw'] === '0'
            && self::cursor_autoload_is_expected(
                $cursors['id_autoload'],
                $intent['before_cursor_id_present'],
                $intent['before_cursor_id_autoload']
            );
    }

    private static function cursor_autoload_is_expected(
        mixed $current,
        int $beforePresent,
        mixed $before
    ): bool {
        return $beforePresent === 1 ? $current === $before : $current === 'auto';
    }

    /** @param list<mixed> $args */
    private static function unschedule_analytics(array $args, array &$fence, array $mutex): void {
        $kind = $args === [] ? 'recurring' : ($args === [null, null] ? 'catchup' : '');
        if ($kind === '') {
            throw new \RuntimeException('duo: WooCommerce analytics cancellation args are outside the closed grammar');
        }
        $targetIds = [];
        foreach ($fence['active'] as $actionId => $activeKind) {
            if ($activeKind === $kind) {
                $targetIds[] = (int) $actionId;
            }
        }
        sort($targetIds, SORT_NUMERIC);
        if ($targetIds === []) {
            throw new \RuntimeException('duo: WooCommerce analytics cancellation has no exact claimed target');
        }

        self::assert_provider_mutex($mutex);
        self::assert_analytics_claim_fence($fence);
        self::assert_native_scheduler_hook_topology();
        self::assert_scheduler_storage();
        self::assert_transaction_state(false);
        self::begin_checked_transaction('WooCommerce scheduler native cancellation transaction', $mutex);
        $temporary = $fence;
        $committed = false;
        try {
            self::assert_transaction_state(true);
            self::lock_scheduler_storage();
            self::assert_native_scheduler_hook_topology();
            self::assert_provider_mutex($mutex);
            self::lock_analytics_pending_range($temporary);
            $logBefore = self::cancellation_log_roster($targetIds);
            foreach ($targetIds as $actionId) {
                $state = self::action_db_state($actionId);
                if ($state === null
                    || $state['status'] !== 'pending'
                    || $state['claim_id'] !== ($temporary['owners'][$actionId] ?? null)) {
                    throw new \RuntimeException(
                        'duo: WooCommerce analytics exact cancellation target changed before native mutation; recovery_required'
                    );
                }
                $temporary['store']->cancel_action($actionId);
                self::assert_provider_mutex($mutex);
                self::assert_transaction_state(true);
                unset($temporary['active'][$actionId]);
                $temporary['canceled'][$actionId] = $kind;
            }
            self::lock_analytics_pending_range($temporary);
            $logAfter = self::cancellation_log_roster($targetIds);
            self::assert_cancellation_log_append($targetIds, $logBefore, $logAfter);
            self::assert_analytics_claim_fence($temporary);
            $fence = $temporary;
            $commitFailure = self::commit_checked_transaction(
                'WooCommerce scheduler native cancellation transaction',
                $mutex
            );
            $pending = 0;
            $canceled = 0;
            foreach ($targetIds as $actionId) {
                $state = self::action_db_state($actionId);
                if ($state === null
                    || $state['claim_id'] !== ($temporary['owners'][$actionId] ?? null)) {
                    $committed = true;
                    throw new \RuntimeException(
                        'duo: WooCommerce analytics cancellation COMMIT outcome is partial; recovery_required',
                        0,
                        $commitFailure
                    );
                }
                $state['status'] === 'canceled' ? $canceled++ : $pending++;
            }
            if ($pending === count($targetIds)) {
                throw $commitFailure
                    ?? new \RuntimeException(
                        'duo: WooCommerce analytics cancellation COMMIT did not persist; recovery_required'
                    );
            }
            $committed = true;
            if ($canceled !== count($targetIds)) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics cancellation COMMIT outcome is partial; recovery_required',
                    0,
                    $commitFailure
                );
            }
            self::assert_provider_mutex($mutex);
            self::assert_analytics_claim_fence($fence);
        } catch (\Throwable $failure) {
            if (!$committed) {
                try {
                    self::rollback_checked_transaction(
                        'WooCommerce scheduler native cancellation transaction',
                        true
                    );
                } catch (\Throwable $rollbackFailure) {
                    throw new \RuntimeException(
                        $failure->getMessage()
                        . '; additionally the native cancellation could not roll back and recovery_required',
                        0,
                        $failure
                    );
                }
            }
            throw $failure;
        }
    }

    private static function write_cursor(string $name, string|int $value): void {
        $before = self::raw_option_record($name, 32);
        update_option($name, $value);
        $after = self::raw_option_record($name, 32);
        if ($after === null
            || !hash_equals((string) $value, $after['value'])
            || ($before !== null && $after['autoload'] !== $before['autoload'])
            || ($before === null && $after['autoload'] !== 'auto')) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics cursor write did not persist exact native bytes/autoload; recovery_required'
            );
        }
    }

    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    private static function repair_retention(): array {
        return self::with_provider_mutex(
            static fn(array $mutex): array => self::repair_retention_locked($mutex)
        );
    }

    /** @param array{name:string,connection:int} $mutex */
    private static function repair_retention_locked(array $mutex): array {
        self::assert_retention_prerequisites();
        self::assert_provider_mutex($mutex);
        self::assert_local_option_cache();
        $source = self::retention_option_state();
        $topology = self::retention_topology();
        $before = self::retention_exact_receipt($source, $topology);
        $enabled = $source['days'] > 0;
        if ((!$enabled && $topology['matching'] > 0)
            || ($enabled && ($topology['matching'] !== 1 || $topology['healthy'] !== 1))) {
            return self::repair_retention_transaction($mutex);
        }
        if (self::retention_option_state() !== $source) {
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention setting changed during cron repair; recovery_required'
            );
        }
        $after = self::retention_stable_projection();
        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /** @param array{name:string,connection:int} $mutex */
    private static function repair_retention_transaction(array $mutex): array {
        self::assert_provider_mutex($mutex);
        self::assert_retention_hook_topology();
        self::assert_options_storage();
        self::assert_transaction_state(false);
        self::begin_checked_transaction('WooCommerce stock-retention cron transaction', $mutex);
        $committed = false;
        $before = null;
        $after = null;
        $failure = null;
        try {
            self::assert_transaction_state(true);
            $source = self::locked_retention_option_state();
            $lockedBefore = self::locked_cron_state();
            self::assert_options_storage();
            self::assert_transaction_state(true);
            $before = self::retention_exact_receipt($source, $lockedBefore['topology']);
            $expected = $source['days'] > 0 ? 1 : 0;
            $controller = wc_get_container()->get(DataRetentionController::class);

            if (($expected === 0 && $lockedBefore['topology']['matching'] > 0)
                || ($expected === 1
                    && ($lockedBefore['topology']['matching'] > 1
                        || ($lockedBefore['topology']['matching'] === 1
                            && $lockedBefore['topology']['healthy'] !== 1)))) {
                self::assert_locked_retention_source($source);
                $controller->clear_daily_task();
                self::assert_transaction_state(true);
                self::assert_provider_mutex($mutex);
            }
            $current = self::locked_cron_state();
            if ($expected === 1 && $current['topology']['matching'] === 0) {
                self::assert_locked_retention_source($source);
                $controller->schedule_or_unschedule_daily_task(null, $source['raw']);
                self::assert_transaction_state(true);
                self::assert_provider_mutex($mutex);
            }

            self::assert_locked_retention_source($source);
            $lockedAfter = self::locked_cron_state();
            if ($lockedAfter['topology']['matching'] !== $expected
                || ($expected === 1 && $lockedAfter['topology']['healthy'] !== 1)
                || $lockedBefore['witness']['autoload'] !== $lockedAfter['witness']['autoload']
                || !hash_equals(
                    $lockedBefore['topology']['unrelated_sha256'],
                    $lockedAfter['topology']['unrelated_sha256']
                )
                || $lockedBefore['topology']['unrelated_count']
                    !== $lockedAfter['topology']['unrelated_count']) {
                throw new \RuntimeException(
                    'duo: WooCommerce stock-retention native cron mutation changed unrelated state '
                    . 'or cron autoload; recovery_required'
                );
            }
            $after = self::retention_projection_from($source, $lockedAfter['topology']);
            self::assert_transaction_state(true);
            $commitFailure = self::commit_checked_transaction(
                'WooCommerce stock-retention cron transaction',
                $mutex
            );
            self::refresh_cron_option_cache(false);
            $committedRecord = self::raw_option_record('cron', self::MAX_CRON_BYTES);
            if ($committedRecord === null
                || !hash_equals($lockedAfter['raw'], $committedRecord['value'])
                || $committedRecord['autoload'] !== $lockedAfter['witness']['autoload']) {
                if ($committedRecord !== null
                    && hash_equals($lockedBefore['raw'], $committedRecord['value'])
                    && $committedRecord['autoload'] === $lockedBefore['witness']['autoload']) {
                    throw $commitFailure
                        ?? new \RuntimeException(
                            'duo: WooCommerce stock-retention COMMIT did not persist; recovery_required'
                        );
                }
                throw new \RuntimeException(
                    'duo: WooCommerce stock-retention COMMIT bytes are neither preimage nor result; recovery_required',
                    0,
                    $commitFailure
                );
            }
            $committedTopology = self::retention_topology();
            self::assert_retention_source($source);
            if ($committedTopology['matching'] !== $expected
                || ($expected === 1 && $committedTopology['healthy'] !== 1)
                || $committedTopology['cron_autoload'] !== $lockedAfter['witness']['autoload']
                || !hash_equals(
                    $lockedBefore['topology']['unrelated_sha256'],
                    $committedTopology['unrelated_sha256']
                )) {
                throw new \RuntimeException(
                    'duo: WooCommerce stock-retention COMMIT outcome is ambiguous; recovery_required',
                    0,
                    $commitFailure
                );
            }
            $committed = true;
        } catch (\Throwable $caught) {
            $failure = $caught;
        }

        if (!$committed) {
            $cleanupFailure = null;
            try {
                self::cleanup_retention_transaction($mutex);
            } catch (\Throwable $caught) {
                $cleanupFailure = $caught;
            }
            if ($failure !== null) {
                if ($cleanupFailure !== null) {
                    throw new \RuntimeException(
                        $failure->getMessage()
                        . '; additionally the cron rollback/cache cleanup failed and recovery_required',
                        0,
                        $failure
                    );
                }
                throw $failure;
            }
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention cron transaction ended without an outcome; recovery_required'
            );
        }
        if ($failure !== null) {
            throw $failure;
        }
        self::assert_transaction_state(false);
        self::refresh_cron_option_cache(false);
        $final = self::retention_stable_projection();
        if (!is_array($before) || !is_array($after) || $final !== $after) {
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention committed projection changed during cache readback; recovery_required'
            );
        }
        return ['before' => $before, 'after' => $after, 'verified' => true];
    }

    /** @param array{name:string,connection:int} $mutex */
    private static function cleanup_retention_transaction(array $mutex): void {
        try {
            self::rollback_checked_transaction(
                'WooCommerce stock-retention cron transaction',
                true
            );
            self::assert_transaction_state(false);
            self::refresh_cron_option_cache(false);
            self::retention_topology();
            return;
        } catch (\Throwable $continuityFailure) {
            $replacement = self::recover_provider_mutex_for_cleanup($mutex, $continuityFailure);
        }

        $priorAuthority = self::$activeMutex;
        self::$activeMutex = $replacement;
        $workFailure = null;
        try {
            self::rollback_checked_transaction(
                'WooCommerce stock-retention replacement-session cleanup',
                true
            );
            self::assert_transaction_state(false);
            self::refresh_cron_option_cache(false);
            self::retention_topology();
        } catch (\Throwable $failure) {
            $workFailure = $failure;
        }
        $releaseFailure = null;
        try {
            self::release_provider_mutex($replacement);
        } catch (\Throwable $failure) {
            $releaseFailure = $failure;
        } finally {
            self::$activeMutex = $priorAuthority;
        }
        if ($workFailure !== null || $releaseFailure !== null) {
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention replacement-session cleanup did not converge; recovery_required',
                0,
                $workFailure ?? $releaseFailure
            );
        }
    }

    /** @param array<string,mixed> $source */
    private static function assert_retention_source(array $source): void {
        if (self::retention_option_state() !== $source) {
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention setting changed during locked cron repair; recovery_required'
            );
        }
    }

    /** @param array<string,mixed> $source */
    private static function assert_locked_retention_source(array $source): void {
        if (self::locked_retention_option_state() !== $source) {
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention setting changed inside its locked cron repair; recovery_required'
            );
        }
    }

    /** @return array<string,mixed> */
    private static function analytics_stable_projection(?array $fence = null): array {
        self::assert_analytics_prerequisites();
        $schema = self::action_scheduler_schema_state();
        $source = self::analytics_option_state();
        $marker = self::marker_state();
        if (($marker['phase'] ?? null) !== 'verified'
            || ($marker['data']['state'] ?? null) !== $source['desired']) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics transition marker is incomplete or disagrees with the authored setting; recovery_required'
            );
        }
        $interval = self::analytics_interval();
        $topology = self::analytics_topology($fence);
        self::cursor_state();
        if (!self::analytics_topology_is_stable($source['desired'], $topology, $interval)) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics scheduler projection is stale; recovery_required'
            );
        }
        return [
            'catchup_projection' => $source['desired'] === 'yes' ? 'none' : 'pending-or-consumed',
            'continuation_projection' => 'bounded-native',
            'cursor_projection' => 'bounded-native',
            'desired_scheduled' => $source['desired'] === 'yes' ? 1 : 0,
            'marker_sha256' => $marker['sha256'],
            'marker_autoload' => $marker['autoload'],
            'native_interval' => $interval,
            'option_present' => $source['present'],
            'option_sha256' => $source['sha256'],
            'recurring_projection' => $source['desired'] === 'yes' ? 1 : 0,
            'scheduler_schema_autoload' => $schema['autoload'],
            'scheduler_schema_sha256' => $schema['sha256'],
        ];
    }

    /** @param array<string,mixed> $topology */
    private static function analytics_topology_is_stable(string $desired, array $topology, int $interval): bool {
        return $desired === 'yes'
            ? $topology['recurring'] === 1
                && $topology['recurring_interval'] === $interval
                && $topology['catchup'] === 0
            : $topology['recurring'] === 0 && $topology['catchup'] <= 1;
    }

    /** @return array<string,mixed> */
    private static function retention_stable_projection(): array {
        self::assert_retention_prerequisites();
        $source = self::retention_option_state();
        $topology = self::retention_topology();
        $expected = $source['days'] > 0 ? 1 : 0;
        if ($topology['matching'] !== $expected
            || ($expected === 1 && $topology['healthy'] !== 1)) {
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention cron projection is stale; recovery_required'
            );
        }
        return self::retention_projection_from($source, $topology);
    }

    /** @return array<string,mixed> */
    private static function retention_projection_from(array $source, array $topology): array {
        $expected = $source['days'] > 0 ? 1 : 0;
        return [
            'cron_autoload' => $topology['cron_autoload'],
            'matching_events' => $expected,
            'option_autoload' => $source['autoload'],
            'option_present' => $source['present'],
            'option_sha256' => $source['sha256'],
            'retention_enabled' => $expected,
            'topology_sha256' => self::digest($topology['semantic']),
            'unrelated_count' => $topology['unrelated_count'],
            'unrelated_sha256' => $topology['unrelated_sha256'],
        ];
    }

    /** @return array<string,mixed> */
    private static function with_provider_mutex(callable $operation): array {
        if (self::$activeMutex !== null) {
            throw new \RuntimeException('duo: WooCommerce scheduler provider mutex scope is already active');
        }
        $mutex = self::acquire_provider_mutex();
        self::$activeMutex = $mutex;
        $result = null;
        $failure = null;
        try {
            $result = $operation($mutex);
        } catch (\Throwable $caught) {
            $failure = $caught;
        }

        $releaseFailure = null;
        try {
            self::release_provider_mutex($mutex);
        } catch (\Throwable $caught) {
            $releaseFailure = $caught;
        } finally {
            self::$activeMutex = null;
        }
        if ($releaseFailure !== null) {
            if ($failure !== null) {
                throw new \RuntimeException(
                    $failure->getMessage()
                    . '; additionally the provider mutex cleanup failed and recovery_required',
                    0,
                    $failure
                );
            }
            throw new \RuntimeException(
                'duo: WooCommerce scheduler provider could not verify release of its database mutex; recovery_required',
                0,
                $releaseFailure
            );
        }
        if ($failure !== null) {
            throw $failure;
        }
        if (!is_array($result)) {
            throw new \RuntimeException('duo: WooCommerce scheduler provider returned malformed mutex work');
        }
        return $result;
    }

    /** @return array{name:string,connection:int} */
    private static function acquire_provider_mutex(): array {
        global $wpdb;
        self::assert_options_table_identity();
        $connection = self::db_positive_uint(\Duo\ProviderSdk::checked_get_var(
            'SELECT CONNECTION_ID()',
            'WooCommerce scheduler mutex connection'
        ), 'connection');
        $database = is_string($wpdb->dbname ?? null) ? $wpdb->dbname : '';
        $name = self::MUTEX_PREFIX . substr(hash('sha256', $database . '|' . $wpdb->options), 0, 32);
        $acquired = \Duo\ProviderSdk::checked_get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name),
            'WooCommerce scheduler mutex acquisition'
        );
        if ((string) $acquired !== '1') {
            throw new \RuntimeException(
                'duo: another WooCommerce scheduler reconciliation holds the provider mutex; retry after it finishes'
            );
        }
        $mutex = ['name' => $name, 'connection' => $connection];
        try {
            self::assert_provider_mutex($mutex);
        } catch (\Throwable $failure) {
            try {
                self::release_provider_mutex_best_effort($mutex);
            } catch (\Throwable $cleanupFailure) {
                throw new \RuntimeException(
                    $failure->getMessage()
                    . '; additionally the acquired provider mutex could not be cleaned up and recovery_required',
                    0,
                    $failure
                );
            }
            throw $failure;
        }
        return $mutex;
    }

    /** @param array{name:string,connection:int} $mutex */
    private static function assert_provider_mutex(array $mutex): void {
        self::session_witness($mutex);
    }

    /**
     * @param array{name:string,connection:int} $mutex
     * @return array{connection:int,in_transaction:bool}
     */
    private static function session_witness(array $mutex): array {
        global $wpdb;
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            'SELECT CONNECTION_ID() AS connection_id, '
            . '@@in_transaction AS in_transaction, '
            . 'IS_USED_LOCK(%s) AS lock_holder',
            $mutex['name']
        ), 'WooCommerce scheduler atomic session continuity');
        if (count($rows) !== 1
            || !self::canonical_positive_uint($rows[0]['connection_id'] ?? null)
            || !in_array($rows[0]['in_transaction'] ?? null, ['0', '1'], true)
            || !self::canonical_positive_uint($rows[0]['lock_holder'] ?? null)) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler returned a malformed atomic session witness'
            );
        }
        $connection = (int) $rows[0]['connection_id'];
        if ($connection !== $mutex['connection']
            || (int) $rows[0]['lock_holder'] !== $connection) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler provider lost its database mutex continuity; recovery_required'
            );
        }
        return [
            'connection' => $connection,
            'in_transaction' => $rows[0]['in_transaction'] === '1',
        ];
    }

    /** @param array{name:string,connection:int} $mutex */
    private static function release_provider_mutex(array $mutex): void {
        global $wpdb;
        try {
            self::assert_provider_mutex($mutex);
        } catch (\Throwable $failure) {
            try {
                self::release_provider_mutex_best_effort($mutex);
            } catch (\Throwable $cleanupFailure) {
                throw new \RuntimeException(
                    $failure->getMessage()
                    . '; additionally the provider mutex could not be cleaned up and recovery_required',
                    0,
                    $failure
                );
            }
            throw $failure;
        }
        try {
            $released = \Duo\ProviderSdk::checked_get_var(
                $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $mutex['name']),
                'WooCommerce scheduler mutex release'
            );
            $holder = \Duo\ProviderSdk::checked_get_var(
                $wpdb->prepare('SELECT IS_USED_LOCK(%s)', $mutex['name']),
                'WooCommerce scheduler mutex release readback'
            );
            if ((string) $released !== '1' || $holder !== null) {
                throw new \RuntimeException(
                    'duo: WooCommerce scheduler provider database mutex release did not persist'
                );
            }
        } catch (\Throwable $failure) {
            try {
                self::release_provider_mutex_best_effort($mutex);
            } catch (\Throwable $cleanupFailure) {
                throw new \RuntimeException(
                    $failure->getMessage()
                    . '; additionally the provider mutex could not be cleaned up and recovery_required',
                    0,
                    $failure
                );
            }
            throw $failure;
        }
    }

    /** @param array{name:string,connection:int} $mutex */
    private static function release_provider_mutex_best_effort(array $mutex): void {
        global $wpdb;
        $failure = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $released = \Duo\ProviderSdk::checked_get_var(
                    $wpdb->prepare('SELECT RELEASE_LOCK(%s)', $mutex['name']),
                    'WooCommerce scheduler mutex cleanup release'
                );
                $holder = \Duo\ProviderSdk::checked_get_var(
                    $wpdb->prepare('SELECT IS_USED_LOCK(%s)', $mutex['name']),
                    'WooCommerce scheduler mutex cleanup readback'
                );
                if (in_array((string) $released, ['0', '1'], true) && $holder === null) {
                    return;
                }
                $failure ??= new \RuntimeException('native mutex cleanup readback disagrees');
            } catch (\Throwable $caught) {
                $failure ??= $caught;
            }
        }
        throw new \RuntimeException(
            'duo: WooCommerce scheduler provider mutex best-effort cleanup did not converge',
            0,
            $failure
        );
    }

    /** @return object */
    private static function analytics_store(): object {
        if (!class_exists('ActionScheduler') || !is_callable(['ActionScheduler', 'store'])) {
            throw new \RuntimeException('duo: WooCommerce exact Action Scheduler store is unavailable');
        }
        $store = \ActionScheduler::store();
        if (!is_object($store)
            || get_class($store) !== 'ActionScheduler_DBStore'
            || !is_callable([$store, 'stake_claim'])
            || !is_callable([$store, 'release_claim'])
            || !is_callable([$store, 'cancel_action'])
            || !is_callable([$store, 'get_claim_id'])
            || !is_callable([$store, 'get_status'])) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler repair requires the exact ActionScheduler_DBStore boundary'
            );
        }
        return $store;
    }

    /**
     * @param array{name:string,connection:int} $mutex
     * @return array{store:object,claims:list<object>,owners:array<int,int>,active:array<int,string>,canceled:array<int,string>,created:array<int,true>}
     */
    private static function acquire_analytics_claim_fence(array $mutex): array {
        self::assert_provider_mutex($mutex);
        $topology = self::analytics_topology();
        $fence = [
            'store' => self::analytics_store(),
            'claims' => [],
            'owners' => [],
            'active' => [],
            'canceled' => [],
            'created' => [],
        ];
        if ($topology['rows'] === []) {
            return $fence;
        }
        $expected = [];
        foreach ($topology['rows'] as $row) {
            $expected[(int) $row['action_id']] = (string) $row['kind'];
        }
        try {
            self::stake_visible_actions($fence, $expected, $mutex);
            self::assert_analytics_claim_fence($fence);
        } catch (\Throwable $failure) {
            try {
                self::release_analytics_claim_fence($fence, $mutex);
            } catch (\Throwable $releaseFailure) {
                throw new \RuntimeException(
                    $failure->getMessage()
                    . '; additionally the initial native claim cleanup failed and recovery_required',
                    0,
                    $failure
                );
            }
            throw $failure;
        }
        return $fence;
    }

    /** @param array<int,string> $expected */
    private static function stake_visible_actions(array &$fence, array $expected, array $mutex): void {
        self::assert_provider_mutex($mutex);
        self::assert_native_scheduler_hook_topology();
        self::assert_scheduler_storage();
        self::assert_transaction_state(false);
        self::begin_checked_transaction('WooCommerce scheduler initial native claim', $mutex);
        $temporary = $fence;
        $committed = false;
        try {
            self::assert_transaction_state(true);
            self::lock_scheduler_storage();
            self::assert_native_scheduler_hook_topology();
            self::assert_provider_mutex($mutex);
            $claimPreimages = self::claim_runtime_preimages(array_keys($expected));
            $claim = self::stake_claim_preserving_store_state(
                $temporary['store'],
                self::MAX_ACTIONS + 1,
                new \DateTime('@' . (time() + self::ACTION_HORIZON_SECONDS)),
                [self::ANALYTICS_HOOK],
                self::ANALYTICS_GROUP
            );
            self::assert_provider_mutex($mutex);
            self::assert_transaction_state(true);
            $claimed = self::claim_action_ids($claim);
            $expectedIds = array_keys($expected);
            sort($expectedIds, SORT_NUMERIC);
            if ($claimed !== $expectedIds) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics worker won the native claim race; '
                    . 'wait for it or stale-claim cleanup before retrying'
                );
            }
            self::register_claim($temporary, $claim, $expected);
            self::restore_claim_runtime_preimages($claimPreimages);
            self::assert_analytics_claim_fence($temporary);
            // Promote before the first fallible post-COMMIT probe. If the
            // acknowledgement is lost after server apply, the outer finally
            // must still own and release this exact native claim.
            $fence = $temporary;
            $commitFailure = self::commit_checked_transaction(
                'WooCommerce scheduler initial native claim',
                $mutex
            );
            [$commitState, $proofFailure] = self::claim_state_after_commit((int) $claim->get_id());
            $claimed = self::claim_action_ids($claim);
            if (!$commitState['claim_present'] && $commitState['action_ids'] === []) {
                throw $proofFailure
                    ?? $commitFailure
                    ?? new \RuntimeException(
                        'duo: WooCommerce initial native claim COMMIT did not persist; recovery_required'
                    );
            }
            $committed = true;
            if (!$commitState['claim_present'] || $commitState['action_ids'] !== $claimed) {
                throw new \RuntimeException(
                    'duo: WooCommerce initial native claim COMMIT outcome is partial; recovery_required',
                    0,
                    $proofFailure ?? $commitFailure
                );
            }
            if ($proofFailure !== null) {
                throw $proofFailure;
            }
            self::assert_provider_mutex($mutex);
            self::assert_analytics_claim_fence($fence);
        } catch (\Throwable $failure) {
            if (!$committed) {
                try {
                    self::rollback_checked_transaction(
                        'WooCommerce scheduler initial native claim',
                        true
                    );
                } catch (\Throwable $rollbackFailure) {
                    throw new \RuntimeException(
                        $failure->getMessage()
                        . '; additionally the initial native claim rollback failed and recovery_required',
                        0,
                        $failure
                    );
                }
            }
            throw $failure;
        }
    }

    /** @return list<int> */
    private static function claim_action_ids(object $claim): array {
        if (get_class($claim) !== 'ActionScheduler_ActionClaim'
            || !is_callable([$claim, 'get_id'])
            || !is_callable([$claim, 'get_actions'])
            || !self::canonical_positive_uint($claim->get_id())
            || !is_array($claim->get_actions())
            || count($claim->get_actions()) > self::MAX_ACTIONS) {
            throw new \RuntimeException('duo: WooCommerce Action Scheduler returned a malformed native claim');
        }
        $ids = [];
        foreach ($claim->get_actions() as $actionId) {
            if (!self::canonical_positive_uint($actionId) || isset($ids[(int) $actionId])) {
                throw new \RuntimeException(
                    'duo: WooCommerce Action Scheduler returned duplicate or malformed claimed actions'
                );
            }
            $ids[(int) $actionId] = true;
        }
        $out = array_keys($ids);
        sort($out, SORT_NUMERIC);
        return $out;
    }

    /** @param array<int,string> $kinds */
    private static function register_claim(
        array &$fence,
        object $claim,
        array $kinds,
        bool $created = false
    ): void {
        $claimId = (int) $claim->get_id();
        foreach (self::claim_action_ids($claim) as $actionId) {
            if (isset($fence['owners'][$actionId]) || !isset($kinds[$actionId])) {
                throw new \RuntimeException('duo: WooCommerce scheduler claim overlaps an existing provider fence');
            }
            $fence['owners'][$actionId] = $claimId;
            $fence['active'][$actionId] = $kinds[$actionId];
            if ($created) {
                $fence['created'][$actionId] = true;
            }
        }
        $fence['claims'][] = $claim;
    }

    private static function assert_analytics_claim_fence(array $fence): void {
        $claimSets = [];
        foreach ($fence['claims'] as $claim) {
            $claimId = (int) $claim->get_id();
            if (isset($claimSets[$claimId])) {
                throw new \RuntimeException('duo: WooCommerce scheduler provider claim identity is duplicated');
            }
            $claimSets[$claimId] = self::claim_action_ids($claim);
            $state = self::claim_db_state($claimId);
            if (!$state['claim_present'] || $state['action_ids'] !== $claimSets[$claimId]) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics native claim ownership changed during repair; recovery_required'
                );
            }
        }
        foreach ($fence['active'] as $actionId => $_kind) {
            $claimId = $fence['store']->get_claim_id($actionId);
            $status = $fence['store']->get_status($actionId);
            if (!is_int($claimId)
                || $claimId !== ($fence['owners'][$actionId] ?? null)
                || $status !== 'pending') {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics worker progressed inside the native claim fence; recovery_required'
                );
            }
        }
        foreach ($fence['canceled'] as $actionId => $_kind) {
            if ($fence['store']->get_status($actionId) !== 'canceled') {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics scoped cancellation did not persist; recovery_required'
                );
            }
        }
        self::analytics_topology($fence);
    }

    private static function release_analytics_claim_fence(array $fence, array $mutex): void {
        $cleanupMutex = $mutex;
        $recoveredMutex = false;
        $priorAuthority = self::$activeMutex;
        try {
            self::assert_provider_mutex($cleanupMutex);
        } catch (\Throwable $continuityFailure) {
            $cleanupMutex = self::recover_provider_mutex_for_cleanup(
                $mutex,
                $continuityFailure
            );
            $recoveredMutex = true;
            self::$activeMutex = $cleanupMutex;
        }
        $failure = null;
        if ($recoveredMutex) {
            try {
                self::rollback_checked_transaction(
                    'WooCommerce scheduler replacement-session claim cleanup',
                    true
                );
                self::assert_transaction_state(false);
            } catch (\Throwable $rollbackFailure) {
                $failure = $rollbackFailure;
            }
        }
        foreach (array_reverse($fence['claims']) as $claim) {
            $claimFailure = null;
            for ($attempt = 1; $attempt <= 2; $attempt++) {
                try {
                    self::assert_provider_mutex($cleanupMutex);
                    $claimId = (int) $claim->get_id();
                    $claimedIds = self::claim_action_ids($claim);
                    $before = self::claim_db_state($claimId);
                    if ($before['claim_present']) {
                        if (array_diff($before['action_ids'], $claimedIds) !== []) {
                            throw new \RuntimeException('native claim ownership changed before release');
                        }
                        foreach ($claimedIds as $actionId) {
                            $status = $fence['store']->get_status($actionId);
                            $currentClaim = $fence['store']->get_claim_id($actionId);
                            if (in_array($actionId, $before['action_ids'], true)) {
                                if (!in_array($status, ['pending', 'canceled'], true)
                                    || $currentClaim !== $claimId) {
                                    throw new \RuntimeException('native partial claim release state is malformed');
                                }
                            } elseif ($status !== 'pending' || $currentClaim !== 0) {
                                throw new \RuntimeException('native partial claim release lost action ownership');
                            }
                        }
                        $fence['store']->release_claim($claim);
                    }
                    self::assert_provider_mutex($cleanupMutex);
                    $after = self::claim_db_state($claimId);
                    if ($after['claim_present']
                        || array_diff($after['action_ids'], $claimedIds) !== []) {
                        throw new \RuntimeException('native claim release readback disagrees');
                    }
                    foreach ($claimedIds as $actionId) {
                        $actionState = self::action_db_state($actionId);
                        if ($actionState === null
                            && isset($fence['created'][$actionId])
                            && !in_array($actionId, $after['action_ids'], true)) {
                            // A newly-created action disappears with an exact
                            // rollback. A promoted temporary fence must treat
                            // that preimage as clean, not as a stranded claim.
                            continue;
                        }
                        if ($actionState === null) {
                            throw new \RuntimeException('native claimed action disappeared during release');
                        }
                        $status = $actionState['status'];
                        $currentClaim = $actionState['claim_id'];
                        if (in_array($actionId, $after['action_ids'], true)) {
                            if ($status !== 'canceled' || $currentClaim !== $claimId) {
                                throw new \RuntimeException('native canceled action claim residue is malformed');
                            }
                        } elseif ($status !== 'pending' || $currentClaim !== 0) {
                            throw new \RuntimeException('native pending action remained claimed after release');
                        }
                    }
                    $claimFailure = null;
                    break;
                } catch (\Throwable $caught) {
                    $claimFailure ??= $caught;
                }
            }
            if ($claimFailure !== null) {
                $failure ??= $claimFailure;
            }
        }
        if ($recoveredMutex) {
            try {
                self::release_provider_mutex($cleanupMutex);
            } catch (\Throwable $releaseFailure) {
                $failure ??= $releaseFailure;
            } finally {
                self::$activeMutex = $priorAuthority;
            }
        }
        if ($failure !== null) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics native claim release was incomplete; recovery_required',
                0,
                $failure
            );
        }
    }

    /**
     * A database reconnect releases MySQL named locks but not an already
     * committed Action Scheduler claim. Reacquire only the now-unowned exact
     * lock name on the replacement connection so exhaustive claim cleanup can
     * run before control returns; a surviving/foreign holder remains loud.
     *
     * @param array{name:string,connection:int} $mutex
     * @return array{name:string,connection:int}
     */
    private static function recover_provider_mutex_for_cleanup(
        array $mutex,
        \Throwable $continuityFailure
    ): array {
        global $wpdb;
        $connection = self::db_positive_uint(\Duo\ProviderSdk::checked_get_var(
            'SELECT CONNECTION_ID()',
            'WooCommerce scheduler claim-cleanup replacement connection'
        ), 'connection');
        $holder = \Duo\ProviderSdk::checked_get_var(
            $wpdb->prepare('SELECT IS_USED_LOCK(%s)', $mutex['name']),
            'WooCommerce scheduler claim-cleanup lock holder'
        );
        if ($connection === $mutex['connection'] || $holder !== null) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler cannot recover its mutex for exhaustive claim cleanup',
                0,
                $continuityFailure
            );
        }
        $acquired = \Duo\ProviderSdk::checked_get_var(
            $wpdb->prepare('SELECT GET_LOCK(%s, 0)', $mutex['name']),
            'WooCommerce scheduler claim-cleanup mutex reacquisition'
        );
        if ((string) $acquired !== '1') {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler cannot recover its mutex for exhaustive claim cleanup',
                0,
                $continuityFailure
            );
        }
        $replacement = ['name' => $mutex['name'], 'connection' => $connection];
        try {
            self::assert_provider_mutex($replacement);
        } catch (\Throwable $recoveryFailure) {
            try {
                self::release_provider_mutex_best_effort($replacement);
            } catch (\Throwable) {
                // The outer failure remains the recovery authority; the
                // best-effort helper already performed exact holder readback.
            }
            throw new \RuntimeException(
                'duo: WooCommerce scheduler replacement mutex verification failed during claim cleanup',
                0,
                $recoveryFailure
            );
        }
        return $replacement;
    }

    /** @return null|array{status:string,claim_id:int} */
    private static function action_db_state(int $actionId): ?array {
        global $wpdb;
        $table = self::scheduler_table('actionscheduler_actions');
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT action_id, status, claim_id FROM `$table` WHERE action_id = %d "
            . 'ORDER BY action_id ASC LIMIT 2',
            $actionId
        ), 'WooCommerce scheduler exact action-row readback');
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1
            || ($rows[0]['action_id'] ?? null) !== (string) $actionId
            || !in_array($rows[0]['status'] ?? null, ['pending', 'canceled'], true)
            || !self::canonical_uint($rows[0]['claim_id'] ?? null)) {
            throw new \RuntimeException('duo: WooCommerce scheduler exact action row is malformed or duplicated');
        }
        return ['status' => $rows[0]['status'], 'claim_id' => (int) $rows[0]['claim_id']];
    }

    /** @return array{claim_present:bool,action_ids:list<int>} */
    private static function claim_db_state(int $claimId): array {
        global $wpdb;
        $claimsTable = self::scheduler_table('actionscheduler_claims');
        $actionsTable = self::scheduler_table('actionscheduler_actions');
        $claimRows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT claim_id FROM `$claimsTable` WHERE claim_id = %d ORDER BY claim_id ASC LIMIT 2",
            $claimId
        ), 'WooCommerce scheduler native claim-row readback');
        if (count($claimRows) > 1
            || ($claimRows !== [] && ($claimRows[0]['claim_id'] ?? null) !== (string) $claimId)) {
            throw new \RuntimeException('duo: WooCommerce scheduler native claim row is malformed or duplicated');
        }
        $actionRows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT action_id FROM `$actionsTable` WHERE claim_id = %d ORDER BY action_id ASC LIMIT %d",
            $claimId,
            self::MAX_ACTIONS + 1
        ), 'WooCommerce scheduler claimed-action readback');
        if (count($actionRows) > self::MAX_ACTIONS) {
            throw new \RuntimeException('duo: WooCommerce scheduler native claim exceeds its bounded action set');
        }
        $ids = [];
        foreach ($actionRows as $row) {
            if (!self::canonical_positive_uint($row['action_id'] ?? null)
                || isset($ids[(int) $row['action_id']])) {
                throw new \RuntimeException('duo: WooCommerce scheduler native claim action set is malformed');
            }
            $ids[(int) $row['action_id']] = true;
        }
        $actionIds = array_keys($ids);
        sort($actionIds, SORT_NUMERIC);
        return ['claim_present' => $claimRows !== [], 'action_ids' => $actionIds];
    }

    private static function schedule_and_claim(string $kind, array &$fence, array $mutex): void {
        if (!in_array($kind, ['recurring', 'catchup'], true)) {
            throw new \RuntimeException('duo: WooCommerce scheduler native schedule kind is outside the closed grammar');
        }
        self::assert_provider_mutex($mutex);
        self::assert_analytics_claim_fence($fence);
        self::assert_native_scheduler_hook_topology();
        self::assert_scheduler_storage();
        self::assert_transaction_state(false);

        self::begin_checked_transaction('WooCommerce scheduler native schedule transaction', $mutex);
        $temporary = $fence;
        $committed = false;
        try {
            self::assert_transaction_state(true);
            self::lock_scheduler_storage();
            self::assert_native_scheduler_hook_topology();
            self::assert_provider_mutex($mutex);
            if ($kind === 'recurring') {
                OrdersScheduler::schedule_recurring_batch_processor();
            } else {
                OrdersScheduler::schedule_action(
                    OrdersScheduler::PROCESS_PENDING_ORDERS_BATCH_ACTION,
                    [null, null]
                );
            }
            self::assert_provider_mutex($mutex);
            self::assert_transaction_state(true);

            $unclaimedIds = self::unclaimed_analytics_action_ids();
            if (count($unclaimedIds) !== 1) {
                throw new \RuntimeException(
                    'duo: WooCommerce native scheduler created a missing or ambiguous unclaimed action'
                );
            }
            $claimPreimages = self::claim_runtime_preimages($unclaimedIds);

            $claim = self::stake_claim_preserving_store_state(
                $temporary['store'],
                self::MAX_ACTIONS + 1,
                new \DateTime('@' . (time() + self::ACTION_HORIZON_SECONDS)),
                [self::ANALYTICS_HOOK],
                self::ANALYTICS_GROUP
            );
            self::assert_provider_mutex($mutex);
            self::assert_transaction_state(true);
            $claimed = self::claim_action_ids($claim);
            if ($claimed !== $unclaimedIds) {
                throw new \RuntimeException(
                    'duo: WooCommerce native scheduler did not create exactly one transaction-fenced action'
                );
            }
            self::register_claim($temporary, $claim, [$claimed[0] => $kind], true);
            self::restore_claim_runtime_preimages($claimPreimages);
            $topology = self::analytics_topology($temporary);
            $created = array_values(array_filter(
                $topology['rows'],
                static fn(array $row): bool => $row['action_id'] === $claimed[0]
            ));
            if (count($created) !== 1 || $created[0]['kind'] !== $kind) {
                throw new \RuntimeException(
                    'duo: WooCommerce transaction-fenced action disagrees with the native requested schedule'
                );
            }
            self::assert_transaction_state(true);
            $fence = $temporary;
            $commitFailure = self::commit_checked_transaction(
                'WooCommerce scheduler native schedule transaction',
                $mutex
            );
            [$commitState, $proofFailure] = self::claim_state_after_commit((int) $claim->get_id());
            if (!$commitState['claim_present'] && $commitState['action_ids'] === []) {
                throw $proofFailure
                    ?? $commitFailure
                    ?? new \RuntimeException(
                        'duo: WooCommerce native scheduler COMMIT did not persist; recovery_required'
                    );
            }
            // A lost COMMIT acknowledgement is not evidence of rollback. Accept it only
            // when the exact new action, log-backed topology, native claim ownership,
            // connection, and provider mutex all prove the committed outcome.
            $committed = true;
            if (!$commitState['claim_present'] || $commitState['action_ids'] !== $claimed) {
                throw new \RuntimeException(
                    'duo: WooCommerce native scheduler COMMIT outcome is partial; recovery_required',
                    0,
                    $proofFailure ?? $commitFailure
                );
            }
            if ($proofFailure !== null) {
                throw $proofFailure;
            }
            self::assert_provider_mutex($mutex);
            self::assert_analytics_claim_fence($fence);
            $committedTopology = self::analytics_topology($fence);
            $committedRows = array_values(array_filter(
                $committedTopology['rows'],
                static fn(array $row): bool => $row['action_id'] === $claimed[0]
            ));
            if (count($committedRows) !== 1 || $committedRows[0]['kind'] !== $kind) {
                throw new \RuntimeException(
                    'duo: WooCommerce native scheduler COMMIT outcome is ambiguous; recovery_required',
                    0,
                    $commitFailure
                );
            }
            self::assert_stored_action_log($claimed[0]);
            self::assert_transaction_state(false);
            self::assert_provider_mutex($mutex);
            self::assert_analytics_claim_fence($fence);
        } catch (\Throwable $failure) {
            if (!$committed) {
                try {
                    self::rollback_checked_transaction(
                        'WooCommerce scheduler native schedule transaction',
                        true
                    );
                } catch (\Throwable $rollbackFailure) {
                    throw new \RuntimeException(
                        $failure->getMessage()
                        . '; additionally the native scheduler transaction could not roll back and recovery_required',
                        0,
                        $failure
                    );
                }
            }
            throw $failure;
        }
    }

    private static function checked_db_mutation(string $sql, string $context): void {
        global $wpdb;
        $wpdb->last_error = '';
        $result = $wpdb->query($sql);
        if ($result === false || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("duo: provider checked mutation failed: $context");
        }
    }

    /** @return list<int> */
    private static function unclaimed_analytics_action_ids(): array {
        global $wpdb;
        $table = self::scheduler_table('actionscheduler_actions');
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT action_id FROM `$table` WHERE hook = %s AND status = %s AND claim_id = 0 "
            . 'ORDER BY action_id ASC LIMIT %d',
            self::ANALYTICS_HOOK,
            'pending',
            self::MAX_ACTIONS + 1
        ), 'WooCommerce scheduler unclaimed native action roster');
        if (count($rows) > self::MAX_ACTIONS) {
            throw new \RuntimeException('duo: WooCommerce scheduler unclaimed action roster exceeds its bound');
        }
        $ids = [];
        foreach ($rows as $row) {
            if (!self::canonical_positive_uint($row['action_id'] ?? null)
                || isset($ids[(int) $row['action_id']])) {
                throw new \RuntimeException('duo: WooCommerce scheduler unclaimed action roster is malformed');
            }
            $ids[(int) $row['action_id']] = true;
        }
        return array_keys($ids);
    }

    /** @return list<int> */
    private static function lock_analytics_pending_range(array $fence): array {
        global $wpdb;
        self::assert_transaction_state(true);
        $table = self::scheduler_table('actionscheduler_actions');
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT action_id, BINARY hook AS hook, status, claim_id FROM `$table` "
            . 'FORCE INDEX (`hook_status_scheduled_date_gmt`) '
            . 'WHERE hook = %s AND status = %s '
            . 'ORDER BY scheduled_date_gmt ASC, action_id ASC LIMIT %d FOR UPDATE',
            self::ANALYTICS_HOOK,
            'pending',
            self::MAX_ACTIONS + 1
        ), 'WooCommerce scheduler exact pending-hook range lock');
        if (count($rows) > self::MAX_ACTIONS) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics pending range exceeds its bounded action inventory'
            );
        }
        $ids = [];
        foreach ($rows as $row) {
            $actionId = self::db_positive_uint($row['action_id'] ?? null, 'pending range action');
            if (isset($ids[$actionId])
                || ($row['hook'] ?? null) !== self::ANALYTICS_HOOK
                || ($row['status'] ?? null) !== 'pending'
                || !self::canonical_positive_uint($row['claim_id'] ?? null)
                || (int) $row['claim_id'] !== ($fence['owners'][$actionId] ?? null)) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics pending range changed outside its exact native claim fence; recovery_required'
                );
            }
            $ids[$actionId] = true;
        }
        $actual = array_keys($ids);
        $expected = array_map('intval', array_keys($fence['active']));
        sort($actual, SORT_NUMERIC);
        sort($expected, SORT_NUMERIC);
        if ($actual !== $expected) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics pending range changed outside its exact native claim fence; recovery_required'
            );
        }
        self::assert_transaction_state(true);
        return $actual;
    }

    /**
     * @param list<int> $actionIds
     * @return array<int,list<array{log_id:int,message_bytes:int,message_sha256:string,gmt:string,local:string}>>
     */
    private static function cancellation_log_roster(array $actionIds): array {
        global $wpdb;
        self::assert_transaction_state(true);
        if ($actionIds === [] || count($actionIds) > self::MAX_ACTIONS) {
            throw new \RuntimeException('duo: WooCommerce cancellation log scope is malformed');
        }
        $table = self::scheduler_table('actionscheduler_logs');
        $out = [];
        foreach ($actionIds as $actionId) {
            if (!is_int($actionId) || $actionId < 1 || isset($out[$actionId])) {
                throw new \RuntimeException('duo: WooCommerce cancellation log identity is malformed');
            }
            $witnesses = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
                "SELECT log_id, action_id, LENGTH(message) AS message_bytes, "
                . "log_date_gmt, log_date_local FROM `$table` FORCE INDEX (`action_id`) "
                . 'WHERE action_id = %d ORDER BY log_id ASC LIMIT %d FOR UPDATE',
                $actionId,
                self::MAX_LOGS_PER_ACTION + 2
            ), 'WooCommerce scheduler bounded cancellation-log range');
            if (count($witnesses) > self::MAX_LOGS_PER_ACTION + 1) {
                throw new \RuntimeException(
                    'duo: WooCommerce cancellation-log owner range exceeds its bound'
                );
            }
            $rows = [];
            foreach ($witnesses as $witness) {
                $logId = self::db_positive_uint($witness['log_id'] ?? null, 'cancellation log');
                $messageBytes = self::db_positive_uint(
                    $witness['message_bytes'] ?? null,
                    'cancellation log message bytes'
                );
                if (($witness['action_id'] ?? null) !== (string) $actionId
                    || isset($rows[$logId])
                    || $messageBytes > self::MAX_LOG_MESSAGE_BYTES
                    || !is_string($witness['log_date_gmt'] ?? null)
                    || !self::valid_mysql_datetime($witness['log_date_gmt'])
                    || !is_string($witness['log_date_local'] ?? null)
                    || !self::valid_mysql_datetime($witness['log_date_local'])) {
                    throw new \RuntimeException(
                        'duo: WooCommerce cancellation-log owner range is malformed or oversized'
                    );
                }
                $digestRows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
                    "SELECT log_id, action_id, LENGTH(message) AS message_bytes, "
                    . "SHA2(message, 256) AS message_sha256 FROM `$table` "
                    . 'WHERE log_id = %d AND action_id = %d AND LENGTH(message) = %d '
                    . 'AND LENGTH(message) <= %d ORDER BY log_id ASC LIMIT 2',
                    $logId,
                    $actionId,
                    $messageBytes,
                    self::MAX_LOG_MESSAGE_BYTES
                ), 'WooCommerce scheduler exact cancellation-log digest');
                if (count($digestRows) !== 1
                    || ($digestRows[0]['log_id'] ?? null) !== (string) $logId
                    || ($digestRows[0]['action_id'] ?? null) !== (string) $actionId
                    || ($digestRows[0]['message_bytes'] ?? null) !== (string) $messageBytes
                    || !is_string($digestRows[0]['message_sha256'] ?? null)
                    || preg_match('/^[a-f0-9]{64}$/D', $digestRows[0]['message_sha256']) !== 1) {
                    throw new \RuntimeException(
                        'duo: WooCommerce cancellation log changed during bounded readback; recovery_required'
                    );
                }
                $rows[$logId] = [
                    'log_id' => $logId,
                    'message_bytes' => $messageBytes,
                    'message_sha256' => $digestRows[0]['message_sha256'],
                    'gmt' => $witness['log_date_gmt'],
                    'local' => $witness['log_date_local'],
                ];
            }
            ksort($rows, SORT_NUMERIC);
            $out[$actionId] = array_values($rows);
        }
        ksort($out, SORT_NUMERIC);
        self::assert_transaction_state(true);
        return $out;
    }

    /** @param list<int> $actionIds @param array<int,list<array<string,mixed>>> $before @param array<int,list<array<string,mixed>>> $after */
    private static function assert_cancellation_log_append(
        array $actionIds,
        array $before,
        array $after
    ): void {
        foreach ($actionIds as $actionId) {
            $prior = $before[$actionId] ?? null;
            $current = $after[$actionId] ?? null;
            if (!is_array($prior)
                || !is_array($current)
                || count($current) !== count($prior) + 1
                || array_slice($current, 0, count($prior)) !== $prior) {
                throw new \RuntimeException(
                    'duo: WooCommerce native cancellation did not append exactly one owner-bound log; recovery_required'
                );
            }
            $new = $current[array_key_last($current)];
            $priorLast = $prior === [] ? 0 : (int) $prior[array_key_last($prior)]['log_id'];
            if (!is_array($new) || ($new['log_id'] ?? 0) <= $priorLast) {
                throw new \RuntimeException(
                    'duo: WooCommerce native cancellation log identity is not an exact append; recovery_required'
                );
            }
        }
    }

    /** @param list<int> $actionIds @return array<int,array{gmt:?string,local:?string}> */
    private static function claim_runtime_preimages(array $actionIds): array {
        global $wpdb;
        if ($actionIds === [] || count($actionIds) > self::MAX_ACTIONS) {
            throw new \RuntimeException('duo: WooCommerce scheduler claim preimage scope is malformed');
        }
        sort($actionIds, SORT_NUMERIC);
        foreach ($actionIds as $actionId) {
            if (!is_int($actionId) || $actionId < 1) {
                throw new \RuntimeException('duo: WooCommerce scheduler claim preimage identity is malformed');
            }
        }
        $table = self::scheduler_table('actionscheduler_actions');
        $placeholders = implode(',', array_fill(0, count($actionIds), '%d'));
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT action_id, status, claim_id, last_attempt_gmt, last_attempt_local FROM `$table` "
            . "WHERE action_id IN ($placeholders) ORDER BY action_id ASC LIMIT %d",
            ...[...$actionIds, count($actionIds) + 1]
        ), 'WooCommerce scheduler claim runtime preimages');
        if (count($rows) !== count($actionIds)) {
            throw new \RuntimeException('duo: WooCommerce scheduler claim preimage roster changed');
        }
        $preimages = [];
        foreach ($rows as $row) {
            $actionId = self::db_positive_uint($row['action_id'] ?? null, 'claim preimage action');
            $gmt = $row['last_attempt_gmt'] ?? null;
            $local = $row['last_attempt_local'] ?? null;
            if (!in_array($actionId, $actionIds, true)
                || isset($preimages[$actionId])
                || ($row['status'] ?? null) !== 'pending'
                || ($row['claim_id'] ?? null) !== '0'
                || !self::valid_native_attempt_date($gmt)
                || !self::valid_native_attempt_date($local)) {
                throw new \RuntimeException('duo: WooCommerce scheduler claim runtime preimage is malformed');
            }
            $preimages[$actionId] = ['gmt' => $gmt, 'local' => $local];
        }
        ksort($preimages, SORT_NUMERIC);
        return $preimages;
    }

    /** @param array<int,array{gmt:?string,local:?string}> $preimages */
    private static function restore_claim_runtime_preimages(array $preimages): void {
        global $wpdb;
        $table = self::scheduler_table('actionscheduler_actions');
        foreach ($preimages as $actionId => $preimage) {
            $wpdb->last_error = '';
            $result = $wpdb->update($table, [
                'last_attempt_gmt' => $preimage['gmt'],
                'last_attempt_local' => $preimage['local'],
            ], ['action_id' => $actionId]);
            if ($result === false || !in_array($result, [0, 1], true) || (string) $wpdb->last_error !== '') {
                throw new \RuntimeException(
                    'duo: WooCommerce scheduler could not restore native claim runtime preimages'
                );
            }
        }
        if (self::claim_runtime_dates(array_keys($preimages)) !== $preimages) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler native claim runtime preimage restoration changed; recovery_required'
            );
        }
    }

    /** @param list<int> $actionIds @return array<int,array{gmt:?string,local:?string}> */
    private static function claim_runtime_dates(array $actionIds): array {
        global $wpdb;
        $table = self::scheduler_table('actionscheduler_actions');
        $placeholders = implode(',', array_fill(0, count($actionIds), '%d'));
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT action_id, last_attempt_gmt, last_attempt_local FROM `$table` "
            . "WHERE action_id IN ($placeholders) ORDER BY action_id ASC LIMIT %d",
            ...[...$actionIds, count($actionIds) + 1]
        ), 'WooCommerce scheduler restored claim runtime dates');
        if (count($rows) !== count($actionIds)) {
            throw new \RuntimeException('duo: WooCommerce scheduler restored claim runtime roster changed');
        }
        $dates = [];
        foreach ($rows as $row) {
            $actionId = self::db_positive_uint($row['action_id'] ?? null, 'restored claim action');
            $gmt = $row['last_attempt_gmt'] ?? null;
            $local = $row['last_attempt_local'] ?? null;
            if (!in_array($actionId, $actionIds, true)
                || isset($dates[$actionId])
                || !self::valid_native_attempt_date($gmt)
                || !self::valid_native_attempt_date($local)) {
                throw new \RuntimeException('duo: WooCommerce scheduler restored claim runtime date is malformed');
            }
            $dates[$actionId] = ['gmt' => $gmt, 'local' => $local];
        }
        ksort($dates, SORT_NUMERIC);
        return $dates;
    }

    private static function valid_native_attempt_date(mixed $value): bool {
        return $value === null
            || $value === '0000-00-00 00:00:00'
            || (is_string($value) && self::valid_mysql_datetime($value));
    }

    /**
     * Action Scheduler 3.9.3 retains claim filters on its singleton DBStore and
     * clears claim_before_date only on normal return. The exact 11.0.0/11.0.1
     * bytes therefore require an exhaustive in-memory preimage restore around
     * every scoped native claim, including a throw after the DB mutation.
     *
     * @param list<string> $hooks
     */
    private static function stake_claim_preserving_store_state(
        object $store,
        int $maxActions,
        \DateTime $beforeDate,
        array $hooks,
        string $group
    ): object {
        $state = self::claim_store_state($store);
        $claim = null;
        $workFailure = null;
        try {
            $claim = $store->stake_claim($maxActions, $beforeDate, $hooks, $group);
        } catch (\Throwable $failure) {
            $workFailure = $failure;
        }

        $restoreFailure = null;
        try {
            self::restore_claim_store_state($store, $state);
        } catch (\Throwable $failure) {
            $restoreFailure = $failure;
        }
        if ($workFailure !== null) {
            if ($restoreFailure !== null) {
                throw new \RuntimeException(
                    $workFailure->getMessage()
                    . '; additionally the native claim singleton state could not be restored and recovery_required',
                    0,
                    $workFailure
                );
            }
            throw $workFailure;
        }
        if ($restoreFailure !== null) {
            throw $restoreFailure;
        }
        if (!is_object($claim) || get_class($claim) !== 'ActionScheduler_ActionClaim') {
            throw new \RuntimeException('duo: WooCommerce Action Scheduler returned a malformed native claim');
        }
        return $claim;
    }

    /** @return array{before:null,filters:array<string,mixed>} */
    private static function claim_store_state(object $store): array {
        if (get_class($store) !== 'ActionScheduler_DBStore') {
            throw new \RuntimeException('duo: WooCommerce scheduler claim state requires the exact DBStore');
        }
        try {
            $reflection = new \ReflectionClass($store);
            $before = $reflection->getProperty('claim_before_date');
            $filters = $reflection->getProperty('claim_filters');
        } catch (\ReflectionException $failure) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler native claim singleton layout disagrees',
                0,
                $failure
            );
        }
        if ($before->getDeclaringClass()->getName() !== 'ActionScheduler_DBStore'
            || !$before->isPrivate()
            || $before->isStatic()
            || $filters->getDeclaringClass()->getName() !== 'ActionScheduler_DBStore'
            || !$filters->isProtected()
            || $filters->isStatic()) {
            throw new \RuntimeException('duo: WooCommerce scheduler native claim singleton layout disagrees');
        }
        $beforeValue = $before->getValue($store);
        $filterValue = $filters->getValue($store);
        if ($beforeValue !== null || !is_array($filterValue)) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler native claim singleton is already active or malformed'
            );
        }
        self::assert_claim_filters($filterValue);
        return ['before' => null, 'filters' => $filterValue];
    }

    /** @param array{before:null,filters:array<string,mixed>} $state */
    private static function restore_claim_store_state(object $store, array $state): void {
        try {
            $reflection = new \ReflectionClass($store);
            $before = $reflection->getProperty('claim_before_date');
            $filters = $reflection->getProperty('claim_filters');
            $before->setValue($store, $state['before']);
            $filters->setValue($store, $state['filters']);
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler native claim singleton restore failed; recovery_required',
                0,
                $failure
            );
        }
        if (self::claim_store_state($store) !== $state) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler native claim singleton restore changed; recovery_required'
            );
        }
    }

    /** @param array<string,mixed> $filters */
    private static function assert_claim_filters(array $filters): void {
        if (array_keys($filters) !== ['group', 'hooks', 'exclude-groups']) {
            throw new \RuntimeException('duo: WooCommerce scheduler native claim filters are malformed');
        }
        foreach ($filters as $name => $value) {
            if ($value === '') {
                continue;
            }
            $values = is_string($value) ? [$value] : $value;
            if (!is_array($values) || $values === [] || count($values) > self::MAX_ACTIONS) {
                throw new \RuntimeException('duo: WooCommerce scheduler native claim filters are malformed');
            }
            $seen = [];
            foreach ($values as $key => $entry) {
                if (!is_int($key)
                    || !is_string($entry)
                    || $entry === ''
                    || strlen($entry) > 191
                    || str_contains($entry, "\0")
                    || isset($seen[$entry])) {
                    throw new \RuntimeException('duo: WooCommerce scheduler native claim filters are malformed');
                }
                $seen[$entry] = true;
            }
            if ($name === 'hooks' && is_string($value)) {
                throw new \RuntimeException('duo: WooCommerce scheduler native claim hook filter is malformed');
            }
        }
    }

    /** @return array{0:array{claim_present:bool,action_ids:list<int>},1:?\Throwable} */
    private static function claim_state_after_commit(int $claimId): array {
        $failure = null;
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                return [self::claim_db_state($claimId), $failure];
            } catch (\Throwable $caught) {
                $failure ??= $caught;
            }
        }
        throw new \RuntimeException(
            'duo: WooCommerce scheduler could not classify its native COMMIT claim outcome; recovery_required',
            0,
            $failure
        );
    }

    /** @param array{name:string,connection:int} $mutex */
    private static function begin_checked_transaction(string $context, array $mutex): void {
        self::assert_provider_mutex($mutex);
        try {
            // `@@transaction_isolation` is the session default, not proof of
            // a foreign one-shot SET TRANSACTION. Replace any pending weaker
            // characteristic immediately before START on the mutex-owning
            // connection; every failure path below consumes it with an empty
            // transaction before returning control to WordPress.
            self::checked_db_mutation(
                'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ',
                "$context isolation"
            );
            self::assert_provider_mutex($mutex);
            self::checked_db_mutation('START TRANSACTION', "$context start");
            self::assert_provider_mutex($mutex);
            self::assert_transaction_state(true);
        } catch (\Throwable $failure) {
            try {
                // START can apply server-side even when its acknowledgement is lost.
                // A blind ROLLBACK is safe outside a transaction and is the only
                // bounded cleanup when the continuity probe itself also fails.
                self::rollback_checked_transaction($context, true);
                self::consume_pending_transaction_isolation($context, $mutex);
            } catch (\Throwable $cleanupFailure) {
                throw new \RuntimeException(
                    $failure->getMessage()
                    . '; additionally the ambiguous transaction start could not be rolled back and recovery_required',
                    0,
                    $failure
                );
            }
            throw $failure;
        }
    }

    /** @param array{name:string,connection:int} $mutex */
    private static function consume_pending_transaction_isolation(string $context, array $mutex): void {
        self::assert_provider_mutex($mutex);
        self::checked_db_mutation('START TRANSACTION', "$context isolation cleanup start");
        self::assert_provider_mutex($mutex);
        if (!self::transaction_is_active()) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler isolation cleanup did not start its empty transaction'
            );
        }
        self::rollback_checked_transaction("$context isolation cleanup", true);
        self::assert_provider_mutex($mutex);
        if (self::transaction_is_active()) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler isolation cleanup left an active transaction; recovery_required'
            );
        }
    }

    /**
     * @param array{name:string,connection:int} $mutex
     * @return ?\Throwable A lost/false acknowledgement when exact server state is inactive.
     */
    private static function commit_checked_transaction(string $context, array $mutex): ?\Throwable {
        $acknowledgementFailure = null;
        try {
            self::checked_db_mutation('COMMIT', "$context commit");
        } catch (\Throwable $failure) {
            $acknowledgementFailure = $failure;
        }

        $active = null;
        $continuityFailure = null;
        try {
            self::assert_provider_mutex($mutex);
            $active = self::transaction_is_active();
            self::assert_provider_mutex($mutex);
        } catch (\Throwable $failure) {
            $continuityFailure = $failure;
        }
        if ($continuityFailure === null && $active === false) {
            return $acknowledgementFailure;
        }

        $primary = $acknowledgementFailure
            ?? $continuityFailure
            ?? new \RuntimeException(
                'duo: WooCommerce scheduler COMMIT acknowledgement did not end its transaction; recovery_required'
            );
        try {
            // A truthy driver acknowledgement is not a commit certificate.
            // Until the same mutex-owning connection proves inactivity, an
            // exact rollback is still required and the preimage remains the
            // only admissible outcome.
            self::rollback_checked_transaction($context, true);
        } catch (\Throwable $cleanupFailure) {
            throw new \RuntimeException(
                $primary->getMessage()
                . '; additionally the ambiguous COMMIT could not be rolled back and recovery_required',
                0,
                $primary
            );
        }
        throw $primary;
    }

    private static function rollback_checked_transaction(string $context, bool $force = false): void {
        $primaryFailure = null;
        try {
            if (!self::transaction_is_active() && !$force) {
                return;
            }
        } catch (\Throwable $failure) {
            $primaryFailure = $failure;
        }
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                self::checked_db_mutation(
                    'ROLLBACK',
                    $context . ($attempt === 1 ? ' rollback' : ' rollback retry')
                );
            } catch (\Throwable $failure) {
                $primaryFailure ??= $failure;
            }
            try {
                if (!self::transaction_is_active()) {
                    return;
                }
            } catch (\Throwable $failure) {
                $primaryFailure ??= $failure;
            }
        }
        throw new \RuntimeException(
            'duo: WooCommerce scheduler transaction rollback outcome is ambiguous; recovery_required',
            0,
            $primaryFailure
        );
    }

    private static function transaction_is_active(): bool {
        if (self::$activeMutex === null) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler transaction continuity has no active mutex authority'
            );
        }
        return self::session_witness(self::$activeMutex)['in_transaction'];
    }

    private static function assert_transaction_state(bool $expected): void {
        if (self::transaction_is_active() !== $expected) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler transaction continuity changed inside a native callback; recovery_required'
            );
        }
        if ($expected) {
            self::assert_isolation_variable_readable();
        }
    }

    private static function assert_isolation_variable_readable(): void {
        global $wpdb;
        $isolation = null;
        foreach (['SELECT @@transaction_isolation', 'SELECT @@tx_isolation'] as $sql) {
            $wpdb->last_error = '';
            $candidate = $wpdb->get_var($sql);
            if ((string) ($wpdb->last_error ?? '') === '' && is_string($candidate)) {
                $isolation = strtoupper(str_replace(' ', '-', $candidate));
                break;
            }
        }
        if (!in_array($isolation, [
            'READ-UNCOMMITTED', 'READ-COMMITTED', 'REPEATABLE-READ', 'SERIALIZABLE',
        ], true)) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler could not read the native transaction-isolation variable'
            );
        }
    }

    private static function assert_native_scheduler_hook_topology(): void {
        if (!function_exists('has_filter')) {
            throw new \RuntimeException('duo: WooCommerce scheduler cannot inspect native hook topology');
        }
        self::assert_analytics_option_hook_topology();
        foreach ([
            'action_scheduler_claim_actions_order_by',
            'action_scheduler_completed_action',
            'action_scheduler_db_supports_skip_locked',
            'action_scheduler_stored_action_class',
            'action_scheduler_stored_action_instance',
            'pre_as_schedule_recurring_action',
            'pre_as_schedule_single_action',
            'woocommerce_analytics_disable_action_scheduling',
            'woocommerce_analytics_import_interval',
            'woocommerce_queue_class',
        ] as $hook) {
            if (has_filter($hook) !== false) {
                throw new \RuntimeException(
                    'duo: WooCommerce scheduler native transaction hook topology has an extension callback'
                );
            }
        }
        self::assert_exact_native_hook(
            'action_scheduler_store_class',
            ['ActionScheduler_DataController', 'set_store_class'],
            100,
            1
        );
        self::assert_exact_native_hook(
            'action_scheduler_logger_class',
            ['ActionScheduler_DataController', 'set_logger_class'],
            100,
            1
        );

        $logger = \ActionScheduler::logger();
        if (!is_object($logger) || get_class($logger) !== 'ActionScheduler_DBLogger') {
            throw new \RuntimeException('duo: WooCommerce scheduler requires the exact native database logger');
        }
        self::assert_exact_native_hook(
            'action_scheduler_stored_action',
            [$logger, 'log_stored_action'],
            10,
            1
        );
        self::assert_exact_native_hook(
            'action_scheduler_canceled_action',
            [$logger, 'log_canceled_action'],
            10,
            1
        );
        self::assert_exact_native_hook(
            'action_scheduler_failed_fetch_action',
            [$logger, 'log_failed_fetch_action'],
            10,
            2
        );
    }

    private static function assert_analytics_option_hook_topology(): void {
        foreach ([
            self::ANALYTICS_OPTION,
            self::MARKER_OPTION,
            self::CURSOR_DATE_OPTION,
            self::CURSOR_ID_OPTION,
            self::ACTION_SCHEDULER_SCHEMA_OPTION,
        ] as $name) {
            foreach (['pre_option_', 'default_option_', 'option_'] as $prefix) {
                self::assert_closed_option_hook($prefix . $name, []);
            }
        }
        foreach ([self::MARKER_OPTION, self::CURSOR_DATE_OPTION, self::CURSOR_ID_OPTION] as $name) {
            foreach ([
                'sanitize_option_', 'pre_update_option_', 'update_option_',
                'pre_add_option_', 'add_option_',
            ] as $prefix) {
                self::assert_closed_option_hook($prefix . $name, []);
            }
        }

        self::assert_exact_native_hook(
            'add_option_' . self::ANALYTICS_OPTION,
            [OrdersScheduler::class, 'handle_scheduled_import_option_added'],
            10,
            2
        );
        self::assert_exact_native_hook(
            'update_option_' . self::ANALYTICS_OPTION,
            [OrdersScheduler::class, 'handle_scheduled_import_option_change'],
            10,
            2
        );
        self::assert_exact_native_hook(
            'delete_option',
            [OrdersScheduler::class, 'handle_scheduled_import_option_before_delete'],
            10,
            1
        );

        self::assert_core_option_hook_topology();
    }

    private static function assert_core_option_hook_topology(): void {
        foreach (['pre_option', 'default_option', 'pre_wp_load_alloptions', 'alloptions'] as $hook) {
            self::assert_closed_option_hook($hook, []);
        }
        foreach (['sanitize_option', 'pre_add_option', 'add_option'] as $hook) {
            self::assert_closed_option_hook($hook, []);
        }
        self::assert_closed_option_hook('wp_default_autoload_value', [
            ['wp_filter_default_autoload_value_via_option_size', '', 5, 4, 'function'],
        ]);
        self::assert_closed_option_hook('pre_update_option', [
            ['Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController', 'process_pre_update_option', 999, 3, 'container'],
        ]);
        self::assert_closed_option_hook('update_option', []);
        self::assert_closed_option_hook('updated_option', [
            ['Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer', 'process_updated_option', 999, 3, 'container'],
            ['Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController', 'process_updated_option', 999, 3, 'container'],
            ['Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController', 'process_updated_option_fts_index', 999, 3, 'container'],
            ['Automattic\\WooCommerce\\Internal\\Features\\FeaturesController', 'process_updated_option', 999, 3, 'container'],
        ]);
        self::assert_closed_option_hook('added_option', [
            ['Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer', 'process_added_option', 999, 2, 'container'],
            ['Automattic\\WooCommerce\\Internal\\Features\\FeaturesController', 'process_added_option', 999, 3, 'container'],
        ]);
    }

    /** @param list<array{0:string,1:string,2:int,3:int,4:'container'|'function'}> $allowed */
    private static function assert_closed_option_hook(string $hook, array $allowed): void {
        global $wp_filter;
        $seen = [];
        $registered = $wp_filter[$hook] ?? null;
        if ($registered === null) {
            return;
        }
        if (!$registered instanceof \WP_Hook || !is_array($registered->callbacks ?? null)) {
            throw new \RuntimeException('duo: WooCommerce scheduler option hook topology is unreadable');
        }
        foreach ($registered->callbacks as $priority => $callbacks) {
            if (!is_int($priority) || !is_array($callbacks)) {
                throw new \RuntimeException('duo: WooCommerce scheduler option hook topology is malformed');
            }
            foreach ($callbacks as $callback) {
                $function = $callback['function'] ?? null;
                $acceptedArgs = $callback['accepted_args'] ?? null;
                if (is_string($function) && is_int($acceptedArgs)) {
                    $matched = false;
                    foreach ($allowed as [$allowedFunction, $allowedMethod, $allowedPriority, $allowedArgs, $owner]) {
                        if ($owner === 'function'
                            && $function === $allowedFunction
                            && $allowedMethod === ''
                            && $priority === $allowedPriority
                            && $acceptedArgs === $allowedArgs) {
                            $signature = $allowedFunction . '@' . $allowedPriority . '/' . $allowedArgs;
                            if (isset($seen[$signature])) {
                                throw new \RuntimeException(
                                    'duo: WooCommerce scheduler option hook topology has duplicate native callbacks'
                                );
                            }
                            $seen[$signature] = true;
                            $matched = true;
                            break;
                        }
                    }
                    if (!$matched) {
                        throw new \RuntimeException(
                            'duo: WooCommerce scheduler option hook topology has an extension callback'
                        );
                    }
                    continue;
                }
                if (!is_array($function)
                    || count($function) !== 2
                    || (!is_object($function[0]) && !is_string($function[0]))
                    || !is_string($function[1])
                    || !is_int($acceptedArgs)) {
                    throw new \RuntimeException(
                        'duo: WooCommerce scheduler option hook topology has an extension callback'
                    );
                }
                $class = is_object($function[0]) ? get_class($function[0]) : ltrim($function[0], '\\');
                $matched = false;
                foreach ($allowed as [$allowedClass, $allowedMethod, $allowedPriority, $allowedArgs, $owner]) {
                    if ($class === $allowedClass
                        && $function[1] === $allowedMethod
                        && $priority === $allowedPriority
                        && $acceptedArgs === $allowedArgs) {
                        if ($owner !== 'container'
                            || !is_object($function[0])
                            || $function[0] !== self::native_container_service($allowedClass)) {
                            throw new \RuntimeException(
                                'duo: WooCommerce scheduler option hook callback is not the exact native service'
                            );
                        }
                        $signature = $allowedClass . '::' . $allowedMethod . '@'
                            . $allowedPriority . '/' . $allowedArgs;
                        if (isset($seen[$signature])) {
                            throw new \RuntimeException(
                                'duo: WooCommerce scheduler option hook topology has duplicate native callbacks'
                            );
                        }
                        $seen[$signature] = true;
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    throw new \RuntimeException(
                        'duo: WooCommerce scheduler option hook topology has an extension callback'
                    );
                }
            }
        }
    }

    private static function native_container_service(string $class): object {
        if (!function_exists('wc_get_container')) {
            throw new \RuntimeException('duo: WooCommerce scheduler native service container is unavailable');
        }
        $container = wc_get_container();
        if (!is_object($container) || !is_callable([$container, 'get'])) {
            throw new \RuntimeException('duo: WooCommerce scheduler native service container is malformed');
        }
        $service = $container->get($class);
        if (!is_object($service) || get_class($service) !== $class) {
            throw new \RuntimeException('duo: WooCommerce scheduler native option-hook service is unavailable');
        }
        return $service;
    }

    private static function assert_stored_action_log(int $actionId): void {
        global $wpdb;
        $logsTable = self::scheduler_table('actionscheduler_logs');
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT log_id, action_id, LENGTH(message) AS message_bytes, log_date_gmt, log_date_local "
            . "FROM `$logsTable` WHERE action_id = %d ORDER BY log_id ASC LIMIT 2",
            $actionId
        ), 'WooCommerce scheduler committed action log readback');
        if (count($rows) !== 1
            || !self::canonical_positive_uint($rows[0]['log_id'] ?? null)
            || ($rows[0]['action_id'] ?? null) !== (string) $actionId
            || !self::canonical_positive_uint($rows[0]['message_bytes'] ?? null)
            || (int) $rows[0]['message_bytes'] > 1024
            || !is_string($rows[0]['log_date_gmt'] ?? null)
            || !self::valid_mysql_datetime($rows[0]['log_date_gmt'])
            || !is_string($rows[0]['log_date_local'] ?? null)
            || !self::valid_mysql_datetime($rows[0]['log_date_local'])) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler committed action lacks its exact bounded native log'
            );
        }
    }

    /** @param array{0:object|string,1:string} $expected */
    private static function assert_exact_native_hook(
        string $hook,
        array $expected,
        int $priority,
        int $acceptedArgs
    ): void {
        self::assert_exact_native_hook_set($hook, [[$expected, $priority, $acceptedArgs]]);
    }

    /**
     * @param list<array{0:array{0:object|string,1:string},1:int,2:int}> $expected
     */
    private static function assert_exact_native_hook_set(string $hook, array $expected): void {
        global $wp_filter;
        $registered = $wp_filter[$hook] ?? null;
        if (!$registered instanceof \WP_Hook || !is_array($registered->callbacks ?? null)) {
            throw new \RuntimeException('duo: WooCommerce scheduler native hook is absent or unreadable');
        }
        $rows = [];
        foreach ($registered->callbacks as $registeredPriority => $callbacks) {
            if (!is_int($registeredPriority) || !is_array($callbacks)) {
                throw new \RuntimeException('duo: WooCommerce scheduler native hook is malformed');
            }
            foreach ($callbacks as $callback) {
                $rows[] = [
                    'priority' => $registeredPriority,
                    'function' => $callback['function'] ?? null,
                    'accepted_args' => $callback['accepted_args'] ?? null,
                ];
            }
        }
        if (count($rows) !== count($expected)) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler native hook has missing or extension callbacks'
            );
        }
        $matched = [];
        foreach ($rows as $row) {
            $found = null;
            foreach ($expected as $index => [$function, $priority, $acceptedArgs]) {
                if (!isset($matched[$index])
                    && $row['priority'] === $priority
                    && $row['accepted_args'] === $acceptedArgs
                    && $row['function'] === $function) {
                    $found = $index;
                    break;
                }
            }
            if ($found === null) {
                throw new \RuntimeException(
                    'duo: WooCommerce scheduler native hook has missing or extension callbacks'
                );
            }
            $matched[$found] = true;
        }
    }

    private static function assert_scheduler_storage(): void {
        $requirements = [
            'options' => [
                'indexes' => [
                    'PRIMARY' => [0, [['option_id', null, '']]],
                    'option_name' => [0, [['option_name', null, '']]],
                    'autoload' => [1, [['autoload', null, '']]],
                ],
                'columns' => [
                    ['option_id', 'bigint(20) unsigned', 'NO', null, 'auto_increment'],
                    ['option_name', 'varchar(191)', 'NO', '', ''],
                    ['option_value', 'longtext', 'NO', null, ''],
                    ['autoload', 'varchar(20)', 'NO', 'yes', ''],
                ],
            ],
            'actionscheduler_actions' => [
                'indexes' => [
                    'PRIMARY' => [0, [['action_id', null, '']]],
                    'hook_status_scheduled_date_gmt' => [1, [
                        ['hook', 163, ''], ['status', null, ''], ['scheduled_date_gmt', null, 'YES'],
                    ]],
                    'status_scheduled_date_gmt' => [1, [
                        ['status', null, ''], ['scheduled_date_gmt', null, 'YES'],
                    ]],
                    'scheduled_date_gmt' => [1, [['scheduled_date_gmt', null, 'YES']]],
                    'args' => [1, [['args', 191, 'YES']]],
                    'group_id' => [1, [['group_id', null, '']]],
                    'last_attempt_gmt' => [1, [['last_attempt_gmt', null, 'YES']]],
                    'claim_id_status_priority_scheduled_date_gmt' => [1, [
                        ['claim_id', null, ''], ['status', null, ''], ['priority', null, ''],
                        ['scheduled_date_gmt', null, 'YES'],
                    ]],
                    'status_last_attempt_gmt' => [1, [
                        ['status', null, ''], ['last_attempt_gmt', null, 'YES'],
                    ]],
                    'status_claim_id' => [1, [['status', null, ''], ['claim_id', null, '']]],
                ],
                'columns' => [
                    ['action_id', 'bigint(20) unsigned', 'NO', null, 'auto_increment'],
                    ['hook', 'varchar(191)', 'NO', null, ''],
                    ['status', 'varchar(20)', 'NO', null, ''],
                    ['scheduled_date_gmt', 'datetime', 'YES', '0000-00-00 00:00:00', ''],
                    ['scheduled_date_local', 'datetime', 'YES', '0000-00-00 00:00:00', ''],
                    ['priority', 'tinyint unsigned', 'NO', '10', ''],
                    ['args', 'varchar(191)', 'YES', null, ''],
                    ['schedule', 'longtext', 'YES', null, ''],
                    ['group_id', 'bigint(20) unsigned', 'NO', '0', ''],
                    ['attempts', 'int(11)', 'NO', '0', ''],
                    ['last_attempt_gmt', 'datetime', 'YES', '0000-00-00 00:00:00', ''],
                    ['last_attempt_local', 'datetime', 'YES', '0000-00-00 00:00:00', ''],
                    ['claim_id', 'bigint(20) unsigned', 'NO', '0', ''],
                    ['extended_args', 'varchar(8000)', 'YES', null, ''],
                ],
            ],
            'actionscheduler_claims' => [
                'indexes' => [
                    'PRIMARY' => [0, [['claim_id', null, '']]],
                    'date_created_gmt' => [1, [['date_created_gmt', null, 'YES']]],
                ],
                'columns' => [
                    ['claim_id', 'bigint(20) unsigned', 'NO', null, 'auto_increment'],
                    ['date_created_gmt', 'datetime', 'YES', '0000-00-00 00:00:00', ''],
                ],
            ],
            'actionscheduler_groups' => [
                'indexes' => [
                    'PRIMARY' => [0, [['group_id', null, '']]],
                    'slug' => [1, [['slug', 191, '']]],
                ],
                'columns' => [
                    ['group_id', 'bigint(20) unsigned', 'NO', null, 'auto_increment'],
                    ['slug', 'varchar(255)', 'NO', null, ''],
                ],
            ],
            'actionscheduler_logs' => [
                'indexes' => [
                    'PRIMARY' => [0, [['log_id', null, '']]],
                    'action_id' => [1, [['action_id', null, '']]],
                    'log_date_gmt' => [1, [['log_date_gmt', null, 'YES']]],
                ],
                'columns' => [
                    ['log_id', 'bigint(20) unsigned', 'NO', null, 'auto_increment'],
                    ['action_id', 'bigint(20) unsigned', 'NO', null, ''],
                    ['message', 'text', 'NO', null, ''],
                    ['log_date_gmt', 'datetime', 'YES', '0000-00-00 00:00:00', ''],
                    ['log_date_local', 'datetime', 'YES', '0000-00-00 00:00:00', ''],
                ],
            ],
        ];
        foreach ($requirements as $suffix => $requirement) {
            $table = self::scheduler_table($suffix);
            self::assert_innodb_table($table);
            self::assert_exact_columns($table, $requirement['columns']);
            self::assert_required_indexes($table, $requirement['indexes']);
        }
    }

    /** Hold InnoDB next-key plus metadata locks while exact native schema is re-proved. */
    private static function lock_scheduler_storage(): void {
        global $wpdb;
        self::assert_transaction_state(true);
        foreach ([
            'options' => 'option_id',
            'actionscheduler_actions' => 'action_id',
            'actionscheduler_claims' => 'claim_id',
            'actionscheduler_groups' => 'group_id',
            'actionscheduler_logs' => 'log_id',
        ] as $suffix => $primaryKey) {
            $table = self::scheduler_table($suffix);
            $rows = \Duo\ProviderSdk::checked_get_results(
                "SELECT `$primaryKey` FROM `$table` WHERE `$primaryKey` = 0 "
                . "ORDER BY `$primaryKey` ASC LIMIT 1 FOR UPDATE",
                'WooCommerce scheduler transaction schema-lock acquisition'
            );
            if ($rows !== []) {
                throw new \RuntimeException(
                    'duo: WooCommerce scheduler native storage contains an impossible zero identity'
                );
            }
            self::assert_transaction_state(true);
        }
        self::assert_scheduler_storage();
        self::locked_action_scheduler_schema_state();
        self::lock_analytics_group_range();
        self::assert_transaction_state(true);
    }

    private static function lock_analytics_group_range(): void {
        global $wpdb;
        $table = self::scheduler_table('actionscheduler_groups');
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT group_id, BINARY slug AS slug, LENGTH(slug) AS slug_bytes FROM `$table` "
            . 'FORCE INDEX (`slug`) WHERE slug = %s '
            . 'ORDER BY group_id ASC LIMIT 2 FOR UPDATE',
            self::ANALYTICS_GROUP
        ), 'WooCommerce scheduler locked analytics group range');
        self::assert_transaction_state(true);
        if (count($rows) > 1) {
            throw new \RuntimeException('duo: WooCommerce analytics native action group is duplicated under lock');
        }
        if ($rows !== []
            && (!self::canonical_positive_uint($rows[0]['group_id'] ?? null)
                || ($rows[0]['slug'] ?? null) !== self::ANALYTICS_GROUP
                || ($rows[0]['slug_bytes'] ?? null) !== (string) strlen(self::ANALYTICS_GROUP))) {
            throw new \RuntimeException('duo: WooCommerce analytics native action group is aliased under lock');
        }
    }

    private static function assert_options_storage(): void {
        $table = self::scheduler_table('options');
        self::assert_innodb_table($table);
        self::assert_exact_columns($table, [
            ['option_id', 'bigint(20) unsigned', 'NO', null, 'auto_increment'],
            ['option_name', 'varchar(191)', 'NO', '', ''],
            ['option_value', 'longtext', 'NO', null, ''],
            ['autoload', 'varchar(20)', 'NO', 'yes', ''],
        ]);
        self::assert_required_indexes($table, [
            'PRIMARY' => [0, [['option_id', null, '']]],
            'option_name' => [0, [['option_name', null, '']]],
            'autoload' => [1, [['autoload', null, '']]],
        ]);
    }

    /** @param list<array{0:string,1:string,2:string,3:?string,4:string}> $expected */
    private static function assert_exact_columns(string $table, array $expected): void {
        global $wpdb;
        $count = self::db_positive_or_zero_uint(\Duo\ProviderSdk::checked_get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.COLUMNS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME = BINARY %s',
            $table
        ), 'WooCommerce scheduler column cardinality witness'), 'column count');
        if ($count !== count($expected) || $count > 32) {
            throw new \RuntimeException('duo: WooCommerce scheduler native column inventory disagrees with its exact schema');
        }
        $rows = \Duo\ProviderSdk::checked_get_results(
            "SHOW FULL COLUMNS FROM `$table`",
            'WooCommerce scheduler exact ordered columns'
        );
        if (count($rows) !== $count) {
            throw new \RuntimeException('duo: WooCommerce scheduler column inventory changed during bounded readback');
        }
        $actual = [];
        foreach ($rows as $row) {
            $actual[] = [
                $row['Field'] ?? null,
                strtolower((string) ($row['Type'] ?? '')),
                strtoupper((string) ($row['Null'] ?? '')),
                $row['Default'] ?? null,
                strtolower((string) ($row['Extra'] ?? '')),
            ];
        }
        if ($actual !== $expected) {
            throw new \RuntimeException('duo: WooCommerce scheduler native ordered column schema disagrees');
        }
    }

    private static function assert_innodb_table(string $table): void {
        global $wpdb;
        $rows = \Duo\ProviderSdk::checked_get_results(
            $wpdb->prepare('SHOW TABLE STATUS LIKE %s', $wpdb->esc_like($table)),
            'WooCommerce scheduler transaction table engine'
        );
        if (count($rows) !== 1
            || ($rows[0]['Name'] ?? null) !== $table
            || strtoupper((string) ($rows[0]['Engine'] ?? '')) !== 'INNODB') {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler transaction requires exact InnoDB native tables'
            );
        }
    }

    /** @param array<string,array{0:int,1:list<array{0:string,1:?int,2:string}>}> $required */
    private static function assert_required_indexes(string $table, array $required): void {
        global $wpdb;
        $count = self::db_positive_or_zero_uint(\Duo\ProviderSdk::checked_get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM information_schema.STATISTICS '
            . 'WHERE TABLE_SCHEMA = DATABASE() AND BINARY TABLE_NAME = BINARY %s',
            $table
        ), 'WooCommerce scheduler index cardinality witness'), 'index count');
        if ($count > 64) {
            throw new \RuntimeException('duo: WooCommerce scheduler table index inventory exceeds its bound');
        }
        $rows = \Duo\ProviderSdk::checked_get_results(
            "SHOW INDEX FROM `$table`",
            'WooCommerce scheduler required indexes'
        );
        if (count($rows) !== $count) {
            throw new \RuntimeException('duo: WooCommerce scheduler index inventory changed during readback');
        }
        $actual = [];
        foreach ($rows as $row) {
            $key = $row['Key_name'] ?? null;
            $column = $row['Column_name'] ?? null;
            $sequence = self::db_positive_uint($row['Seq_in_index'] ?? null, 'index sequence');
            $nonUnique = self::db_positive_or_zero_uint($row['Non_unique'] ?? null, 'index uniqueness');
            $subPart = $row['Sub_part'] ?? null;
            if ($subPart !== null) {
                $subPart = self::db_positive_uint($subPart, 'index prefix length');
            }
            $nullable = $row['Null'] ?? '';
            if (!is_string($key) || $key === ''
                || !is_string($column) || $column === ''
                || $sequence > 16
                || !in_array($nonUnique, [0, 1], true)
                || !is_string($nullable)
                || !in_array($nullable, ['', 'YES'], true)
                || ($row['Collation'] ?? null) !== 'A'
                || strtoupper((string) ($row['Index_type'] ?? '')) !== 'BTREE'
                || (array_key_exists('Visible', $row) && strtoupper((string) $row['Visible']) !== 'YES')
                || (array_key_exists('Ignored', $row) && strtoupper((string) $row['Ignored']) !== 'NO')) {
                throw new \RuntimeException('duo: WooCommerce scheduler table returned an unusable native index');
            }
            if (isset($actual[$key][$sequence])) {
                throw new \RuntimeException('duo: WooCommerce scheduler table returned a duplicate index column');
            }
            $actual[$key]['non_unique'] ??= $nonUnique;
            if ($actual[$key]['non_unique'] !== $nonUnique) {
                throw new \RuntimeException('duo: WooCommerce scheduler index uniqueness is inconsistent');
            }
            $actual[$key][$sequence] = [$column, $subPart, $nullable];
        }
        $actualNames = array_keys($actual);
        $requiredNames = array_keys($required);
        sort($actualNames, SORT_STRING);
        sort($requiredNames, SORT_STRING);
        if ($actualNames !== $requiredNames) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler native storage has a missing, renamed, or extra index'
            );
        }
        foreach ($required as $key => [$nonUnique, $columns]) {
            if (!isset($actual[$key]) || ($actual[$key]['non_unique'] ?? null) !== $nonUnique) {
                throw new \RuntimeException('duo: WooCommerce scheduler native storage lacks a required index');
            }
            $observed = $actual[$key];
            unset($observed['non_unique']);
            ksort($observed, SORT_NUMERIC);
            if (array_values($observed) !== $columns) {
                throw new \RuntimeException('duo: WooCommerce scheduler required index columns disagree');
            }
        }
    }

    private static function scheduler_table(string $suffix): string {
        global $wpdb;
        self::assert_options_table_identity();
        $table = $suffix === 'options' ? $wpdb->options : $wpdb->prefix . $suffix;
        $property = $suffix;
        if ($suffix !== 'options'
            && (!isset($wpdb->$property) || $wpdb->$property !== $table)) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler native table registration disagrees with the exact site prefix'
            );
        }
        if (preg_match(self::TABLE_IDENTIFIER_PATTERN, $table) !== 1) {
            throw new \RuntimeException('duo: WooCommerce scheduler table identity is outside its bounded grammar');
        }
        return $table;
    }

    private static function assert_options_table_identity(): void {
        global $wpdb;
        if (!is_object($wpdb)
            || !isset($wpdb->prefix, $wpdb->options)
            || !is_string($wpdb->prefix)
            || !is_string($wpdb->options)
            || $wpdb->options !== $wpdb->prefix . 'options'
            || preg_match(self::TABLE_IDENTIFIER_PATTERN, $wpdb->options) !== 1
            || !is_callable([$wpdb, 'prepare'])
            || !is_callable([$wpdb, 'esc_like'])) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler verification requires the exact options-table identity'
            );
        }
    }

    private static function db_positive_uint(mixed $value, string $field): int {
        if (!self::canonical_positive_uint($value)) {
            throw new \RuntimeException("duo: WooCommerce scheduler $field is not a canonical positive integer");
        }
        return (int) $value;
    }

    private static function db_positive_or_zero_uint(mixed $value, string $field): int {
        if (!self::canonical_uint($value)) {
            throw new \RuntimeException("duo: WooCommerce scheduler $field is not a canonical unsigned integer");
        }
        return (int) $value;
    }

    private static function assert_analytics_prerequisites(): void {
        foreach (['as_get_scheduled_actions', 'get_option', 'update_option', 'wc_get_container'] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics schedule requires the native Action Scheduler and option APIs'
                );
            }
        }
        if (!class_exists(Features::class)
            || !is_callable([Features::class, 'is_enabled'])
            || Features::is_enabled('analytics-scheduled-import') !== true) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics scheduled-import feature must be enabled on the target'
            );
        }
        if (!class_exists(OrdersScheduler::class)
            || !is_callable([OrdersScheduler::class, 'schedule_recurring_batch_processor'])
            || !is_callable([OrdersScheduler::class, 'schedule_action'])
            || !is_callable([OrdersScheduler::class, 'get_import_interval'])
            || !is_callable([OrdersScheduler::class, 'queue'])) {
            throw new \RuntimeException('duo: WooCommerce exact analytics scheduler API is unavailable');
        }
        $queue = OrdersScheduler::queue();
        if (!is_object($queue) || get_class($queue) !== 'WC_Action_Queue') {
            throw new \RuntimeException('duo: WooCommerce analytics scheduler requires the exact native action queue');
        }
        if (!class_exists('ActionScheduler')
            || !is_callable(['ActionScheduler', 'is_initialized'])
            || !is_callable(['ActionScheduler', 'store'])
            || !is_callable(['ActionScheduler', 'logger'])
            || !is_callable(['ActionScheduler', 'factory'])
            || \ActionScheduler::is_initialized(__METHOD__) !== true) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics schedule requires initialized Action Scheduler storage'
            );
        }
        self::assert_local_option_cache();
        self::action_scheduler_schema_state();
        $factory = \ActionScheduler::factory();
        if (!is_object($factory) || get_class($factory) !== 'ActionScheduler_ActionFactory') {
            throw new \RuntimeException('duo: WooCommerce exact Action Scheduler factory is unavailable');
        }
        self::claim_store_state(self::analytics_store());
        self::assert_native_scheduler_hook_topology();
        self::assert_scheduler_storage();
    }

    private static function assert_retention_prerequisites(): void {
        foreach ([
            '_get_cron_array', 'get_option', 'has_filter', 'wp_cache_delete',
            'wp_cache_get',
            'wp_clear_scheduled_hook', 'wp_get_schedules', 'wp_next_scheduled',
            'wp_schedule_event', 'wp_using_ext_object_cache',
        ] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException(
                    'duo: WooCommerce stock retention requires the native WordPress cron API'
                );
            }
        }
        if (!defined('WOOCOMMERCE_BIS_ALPHA_ENABLED') || WOOCOMMERCE_BIS_ALPHA_ENABLED !== true) {
            throw new \RuntimeException(
                'duo: WooCommerce customer stock-notification feature must be enabled on the target'
            );
        }
        if (!function_exists('wc_get_container')) {
            throw new \RuntimeException('duo: WooCommerce stock retention requires the native service container');
        }
        $controller = wc_get_container()->get(DataRetentionController::class);
        if (!is_object($controller)
            || get_class($controller) !== DataRetentionController::class
            || !is_callable([$controller, 'do_wc_customer_stock_notifications_daily'])
            || !is_callable([$controller, 'schedule_or_unschedule_daily_task'])
            || !is_callable([$controller, 'clear_daily_task'])) {
            throw new \RuntimeException('duo: WooCommerce exact stock-retention controller is unavailable');
        }
        self::assert_retention_hook_topology();
        self::assert_options_storage();
        self::assert_local_option_cache();
        $schedules = wp_get_schedules();
        if (!is_array($schedules)
            || !is_array($schedules['daily'] ?? null)
            || ($schedules['daily']['interval'] ?? null) !== DAY_IN_SECONDS) {
            throw new \RuntimeException(
                'duo: WordPress daily cron recurrence disagrees with WooCommerce stock retention'
            );
        }
    }

    private static function assert_retention_hook_topology(): void {
        foreach ([
            'alloptions',
            'default_option',
            'default_option_' . self::RETENTION_OPTION,
            'default_option_cron',
            'option_' . self::RETENTION_OPTION,
            'option_cron',
            'pre_clear_scheduled_hook',
            'pre_get_scheduled_event',
            'pre_option',
            'pre_option_' . self::RETENTION_OPTION,
            'pre_option_cron',
            'pre_schedule_event',
            'pre_update_option_cron',
            'pre_wp_load_alloptions',
            'sanitize_option_cron',
            'schedule_event',
            'update_option_cron',
            'wp_next_scheduled',
        ] as $hook) {
            if (has_filter($hook) !== false) {
                throw new \RuntimeException(
                    'duo: WooCommerce stock-retention cron or option hook topology has an extension callback'
                );
            }
        }
        self::assert_core_option_hook_topology();
        $controller = self::native_container_service(DataRetentionController::class);
        self::assert_exact_native_hook(
            self::RETENTION_HOOK,
            [$controller, 'do_wc_customer_stock_notifications_daily'],
            10,
            1
        );
        self::assert_exact_native_hook(
            'update_option_' . self::RETENTION_OPTION,
            [$controller, 'schedule_or_unschedule_daily_task'],
            10,
            2
        );
        self::assert_exact_native_hook(
            'add_option_' . self::RETENTION_OPTION,
            [$controller, 'schedule_or_unschedule_daily_task'],
            10,
            2
        );
        self::assert_exact_native_hook(
            'deactivate_woocommerce/woocommerce.php',
            [$controller, 'clear_daily_task'],
            10,
            1
        );
        if (!class_exists('WC_Install', false)
            || !is_callable(['WC_Install', 'cron_schedules'])
            || !class_exists('ActionScheduler_QueueRunner', false)
            || !is_callable(['ActionScheduler_QueueRunner', 'instance'])) {
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention native cron-schedule owners are unavailable'
            );
        }
        $runner = \ActionScheduler_QueueRunner::instance();
        if (!is_object($runner)
            || get_class($runner) !== 'ActionScheduler_QueueRunner'
            || !is_callable([$runner, 'add_wp_cron_schedule'])) {
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention Action Scheduler cron owner is malformed'
            );
        }
        self::assert_exact_native_hook_set('cron_schedules', [
            [['WC_Install', 'cron_schedules'], 10, 1],
            [[$runner, 'add_wp_cron_schedule'], 10, 1],
        ]);
    }

    /** @return array{sha256:string,autoload:string} */
    private static function action_scheduler_schema_state(): array {
        $record = self::raw_option_record(
            self::ACTION_SCHEDULER_SCHEMA_OPTION,
            self::MAX_SCHEMA_OPTION_BYTES
        );
        $state = self::normalize_action_scheduler_schema_record($record);
        if (get_option(self::ACTION_SCHEDULER_SCHEMA_OPTION, false) !== $record['value']) {
            throw new \RuntimeException(
                'duo: WooCommerce Action Scheduler schema raw and native option views disagree'
            );
        }
        return $state;
    }

    /** @return array{sha256:string,autoload:string} */
    private static function locked_action_scheduler_schema_state(): array {
        global $wpdb;
        self::assert_transaction_state(true);
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_id, BINARY option_name AS option_name, autoload, "
            . "LENGTH(option_value) AS option_bytes FROM `{$wpdb->options}` "
            . 'FORCE INDEX (`option_name`) WHERE option_name = %s '
            . 'ORDER BY option_name ASC, option_id ASC LIMIT 2 FOR UPDATE',
            self::ACTION_SCHEDULER_SCHEMA_OPTION
        ), 'WooCommerce locked Action Scheduler schema witness');
        self::assert_transaction_state(true);
        if (count($rows) !== 1
            || ($rows[0]['option_name'] ?? null) !== self::ACTION_SCHEDULER_SCHEMA_OPTION
            || !self::canonical_positive_uint($rows[0]['option_id'] ?? null)
            || !self::canonical_uint($rows[0]['option_bytes'] ?? null)
            || (int) $rows[0]['option_bytes'] > self::MAX_SCHEMA_OPTION_BYTES
            || !self::valid_autoload($rows[0]['autoload'] ?? null)) {
            throw new \RuntimeException(
                'duo: WooCommerce Action Scheduler schema option is absent, aliased, oversized, or malformed under lock'
            );
        }
        $optionId = (int) $rows[0]['option_id'];
        $bytes = (int) $rows[0]['option_bytes'];
        $payload = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_id, BINARY option_name AS option_name, option_value, autoload, "
            . "LENGTH(option_value) AS option_bytes FROM `{$wpdb->options}` "
            . 'WHERE option_id = %d AND BINARY option_name = BINARY %s '
            . 'AND LENGTH(option_value) = %d AND LENGTH(option_value) <= %d '
            . 'ORDER BY option_id ASC LIMIT 2 FOR UPDATE',
            $optionId,
            self::ACTION_SCHEDULER_SCHEMA_OPTION,
            $bytes,
            self::MAX_SCHEMA_OPTION_BYTES
        ), 'WooCommerce locked Action Scheduler schema payload');
        self::assert_transaction_state(true);
        if (count($payload) !== 1
            || ($payload[0]['option_id'] ?? null) !== (string) $optionId
            || ($payload[0]['option_name'] ?? null) !== self::ACTION_SCHEDULER_SCHEMA_OPTION
            || ($payload[0]['autoload'] ?? null) !== $rows[0]['autoload']
            || ($payload[0]['option_bytes'] ?? null) !== (string) $bytes
            || !is_string($payload[0]['option_value'] ?? null)
            || strlen($payload[0]['option_value']) !== $bytes) {
            throw new \RuntimeException(
                'duo: WooCommerce Action Scheduler schema option changed during its locked bounded read; recovery_required'
            );
        }
        $record = ['value' => $payload[0]['option_value'], 'autoload' => $payload[0]['autoload']];
        $state = self::normalize_action_scheduler_schema_record($record);
        self::refresh_option_cache(self::ACTION_SCHEDULER_SCHEMA_OPTION, true);
        if (get_option(self::ACTION_SCHEDULER_SCHEMA_OPTION, false) !== $record['value']) {
            throw new \RuntimeException(
                'duo: WooCommerce locked Action Scheduler schema raw and native option views disagree'
            );
        }
        return $state;
    }

    /**
     * @param ?array{value:string,autoload:string} $record
     * @return array{sha256:string,autoload:string}
     */
    private static function normalize_action_scheduler_schema_record(?array $record): array {
        if ($record === null) {
            throw new \RuntimeException('duo: WooCommerce Action Scheduler schema authority is absent');
        }
        $parts = explode('.', $record['value']);
        if (count($parts) !== 3
            || $parts[0] !== (string) self::NATIVE_ACTION_SCHEDULER_SCHEMA_VERSION
            || $parts[1] !== '0'
            || !self::canonical_positive_uint($parts[2])) {
            throw new \RuntimeException(
                'duo: WooCommerce Action Scheduler schema authority is outside the exact 8.0.timestamp wire'
            );
        }
        return [
            'sha256' => hash('sha256', $record['value']),
            'autoload' => $record['autoload'],
        ];
    }

    /** @return array{desired:string,present:int,raw:?string,sha256:string} */
    private static function analytics_option_state(): array {
        $raw = self::raw_option(self::ANALYTICS_OPTION, 3);
        if ($raw !== null && !in_array($raw, ['yes', 'no'], true)) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics scheduled-import option is outside its exact yes/no grammar'
            );
        }
        $effective = get_option(self::ANALYTICS_OPTION, false);
        if (($raw === null && $effective !== false) || ($raw !== null && $effective !== $raw)) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics scheduled-import raw and native option views disagree'
            );
        }
        return [
            'desired' => $raw ?? 'no',
            'present' => $raw === null ? 0 : 1,
            'raw' => $raw,
            'sha256' => hash('sha256', $raw ?? 'absent'),
        ];
    }

    /** @return array{days:int,present:int,raw:string,sha256:string,autoload:?string} */
    private static function retention_option_state(): array {
        $record = self::raw_option_record(self::RETENTION_OPTION, 16);
        $raw = $record['value'] ?? null;
        if ($raw !== null
            && $raw !== ''
            && (preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $raw) !== 1
                || (int) $raw > self::MAX_RETENTION_DAYS)) {
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention threshold is outside its reviewed nonnegative whole-day boundary'
            );
        }
        $effective = get_option(self::RETENTION_OPTION, false);
        if (($raw === null && $effective !== false) || ($raw !== null && $effective !== $raw)) {
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention raw and native option views disagree'
            );
        }
        return [
            'days' => $raw === null || $raw === '' ? 0 : (int) $raw,
            'present' => $raw === null ? 0 : 1,
            'raw' => $raw ?? '',
            'sha256' => hash('sha256', $raw ?? 'absent'),
            'autoload' => $record['autoload'] ?? null,
        ];
    }

    private static function analytics_interval(): int {
        $interval = OrdersScheduler::get_import_interval();
        if ($interval !== self::NATIVE_ANALYTICS_INTERVAL) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics import interval disagrees with the exact 11.0.x native default'
            );
        }
        return $interval;
    }

    /** @return array<string,mixed> */
    private static function analytics_topology(?array $fence = null): array {
        $store = self::analytics_store();
        $rawRoster = self::analytics_raw_roster();
        $rows = [];
        $seenIds = [];
        foreach (['pending', 'in-progress'] as $status) {
            $actions = as_get_scheduled_actions([
                'hook' => self::ANALYTICS_HOOK,
                'status' => $status,
                'per_page' => self::MAX_ACTIONS + 1,
                'orderby' => 'date',
                'order' => 'ASC',
            ], 'objects');
            if (!is_array($actions) || count($actions) > self::MAX_ACTIONS) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics schedule exceeds its bounded action inventory'
                );
            }
            foreach ($actions as $actionId => $action) {
                if (!self::canonical_positive_uint($actionId) || isset($seenIds[(string) $actionId])) {
                    throw new \RuntimeException(
                        'duo: WooCommerce analytics schedule returned a malformed or duplicate action identity'
                    );
                }
                $seenIds[(string) $actionId] = true;
                $raw = $rawRoster['rows'][(int) $actionId] ?? null;
                if (!is_array($raw) || $raw['status'] !== $status) {
                    throw new \RuntimeException(
                        'duo: WooCommerce raw and native analytics action rosters disagree; recovery_required'
                    );
                }
                if ($status === 'in-progress') {
                    throw new \RuntimeException(
                        'duo: WooCommerce analytics schedule has an active action; retry after it completes'
                    );
                }
                $claimId = $store->get_claim_id((int) $actionId);
                if (!is_int($claimId) || $claimId < 0) {
                    throw new \RuntimeException(
                        'duo: WooCommerce analytics schedule returned a malformed native claim identity'
                    );
                }
                if ($fence === null && $claimId !== 0) {
                    throw new \RuntimeException(
                        'duo: WooCommerce analytics schedule is held by an active native claim; '
                        . 'wait for the Action Scheduler runner or its bounded stale-claim cleanup before retrying'
                    );
                }
                if ($fence !== null
                    && (!isset($fence['owners'][(int) $actionId])
                        || $fence['owners'][(int) $actionId] !== $claimId)) {
                    throw new \RuntimeException(
                        'duo: WooCommerce analytics worker claim changed during the provider fence; recovery_required'
                    );
                }
                if (!is_object($action)
                    || get_class($action) !== 'ActionScheduler_Action'
                    || $action->get_hook() !== self::ANALYTICS_HOOK
                    || !is_array($action->get_args())
                    || !is_string($action->get_group())) {
                    throw new \RuntimeException(
                        'duo: WooCommerce analytics schedule returned a malformed native action'
                    );
                }
                $schedule = $action->get_schedule();
                if (!is_object($schedule)
                    || get_class($schedule) !== $raw['schedule_class']) {
                    throw new \RuntimeException(
                        'duo: WooCommerce analytics action has a malformed native schedule'
                    );
                }
                $recurring = $schedule->is_recurring();
                $date = $schedule->get_date();
                if (!is_bool($recurring)
                    || !$date instanceof \DateTimeInterface
                    || $date->getTimestamp() < 1
                    || $date->getTimestamp() > time() + self::ACTION_HORIZON_SECONDS
                    || gmdate('Y-m-d H:i:s', $date->getTimestamp()) !== $raw['scheduled_date_gmt']) {
                    throw new \RuntimeException(
                        'duo: WooCommerce analytics action schedule is outside its bounded native time grammar'
                    );
                }
                $args = $action->get_args();
                $group = $action->get_group();
                if (!hash_equals(self::ANALYTICS_GROUP, $group)
                    || self::digest($args) !== $raw['args_sha256']) {
                    throw new \RuntimeException(
                        'duo: WooCommerce analytics hook has a foreign Action Scheduler group'
                    );
                }
                $recurrence = null;
                $kind = '';
                if ($recurring && $args === []) {
                    if (!is_callable([$schedule, 'get_recurrence'])
                        || !is_int($schedule->get_recurrence())
                        || $schedule->get_recurrence() < 1
                        || $schedule->get_recurrence() > 604800) {
                        throw new \RuntimeException(
                            'duo: WooCommerce analytics recurring interval is malformed'
                        );
                    }
                    $recurrence = $schedule->get_recurrence();
                    $kind = 'recurring';
                } elseif (!$recurring && $args === [null, null]) {
                    $kind = 'catchup';
                } elseif (!$recurring && self::native_work_args($args)) {
                    $kind = 'work';
                } else {
                    throw new \RuntimeException(
                        'duo: WooCommerce analytics hook has foreign or malformed action arguments'
                    );
                }
                $rows[] = [
                    'action_id' => (int) $actionId,
                    'args_sha256' => self::digest($args),
                    'date' => $date->getTimestamp(),
                    'kind' => $kind,
                    'recurrence' => $recurrence,
                ];
            }
        }
        usort($rows, static fn(array $left, array $right): int => $left['action_id'] <=> $right['action_id']);
        $workRows = array_values(array_filter($rows, static fn(array $row): bool => $row['kind'] === 'work'));
        $recurringRows = array_values(array_filter($rows, static fn(array $row): bool => $row['kind'] === 'recurring'));
        $catchupRows = array_values(array_filter($rows, static fn(array $row): bool => $row['kind'] === 'catchup'));
        if ($fence !== null) {
            foreach ($fence['active'] as $actionId => $_kind) {
                if (!isset($seenIds[(string) $actionId])) {
                    throw new \RuntimeException(
                        'duo: WooCommerce analytics claimed action completed or disappeared during repair; recovery_required'
                    );
                }
            }
        }
        $rawIds = array_keys($rawRoster['rows']);
        $nativeIds = array_map('intval', array_keys($seenIds));
        sort($rawIds, SORT_NUMERIC);
        sort($nativeIds, SORT_NUMERIC);
        if ($rawIds !== $nativeIds || self::analytics_raw_roster()['sha256'] !== $rawRoster['sha256']) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics raw action roster changed across native hydration; recovery_required'
            );
        }
        return [
            'catchup' => count($catchupRows),
            'recurring' => count($recurringRows),
            'recurring_interval' => count($recurringRows) === 1 ? $recurringRows[0]['recurrence'] : null,
            'rows' => $rows,
            'sha256' => self::digest($rows),
            'work' => count($workRows),
            'work_sha256' => self::digest($workRows),
        ];
    }

    /** @return array{rows:array<int,array<string,mixed>>,sha256:string} */
    private static function analytics_raw_roster(): array {
        global $wpdb;
        $actionsTable = self::scheduler_table('actionscheduler_actions');
        $groupsTable = self::scheduler_table('actionscheduler_groups');
        $groups = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT group_id, BINARY slug AS slug, LENGTH(slug) AS slug_bytes FROM `$groupsTable` "
            . 'WHERE slug = %s ORDER BY group_id ASC LIMIT 2',
            self::ANALYTICS_GROUP
        ), 'WooCommerce analytics raw group roster');
        if (count($groups) > 1) {
            throw new \RuntimeException('duo: WooCommerce analytics native action group is duplicated');
        }
        $groupId = null;
        if ($groups !== []) {
            if (!self::canonical_positive_uint($groups[0]['group_id'] ?? null)
                || ($groups[0]['slug'] ?? null) !== self::ANALYTICS_GROUP
                || ($groups[0]['slug_bytes'] ?? null) !== (string) strlen(self::ANALYTICS_GROUP)) {
                throw new \RuntimeException('duo: WooCommerce analytics native action group is aliased');
            }
            $groupId = (int) $groups[0]['group_id'];
        }
        $witnesses = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT action_id, BINARY hook AS hook, status, group_id, claim_id, priority, "
            . "scheduled_date_gmt, scheduled_date_local, last_attempt_gmt, last_attempt_local, "
            . "LENGTH(args) AS args_bytes, LENGTH(extended_args) AS extended_args_bytes, "
            . "LENGTH(schedule) AS schedule_bytes FROM `$actionsTable` "
            . 'WHERE hook = %s AND status IN (%s,%s) ORDER BY action_id ASC LIMIT %d',
            self::ANALYTICS_HOOK,
            'pending',
            'in-progress',
            self::MAX_ACTIONS + 1
        ), 'WooCommerce analytics bounded raw action witnesses');
        if (count($witnesses) > self::MAX_ACTIONS) {
            throw new \RuntimeException('duo: WooCommerce analytics schedule exceeds its bounded action inventory');
        }
        if ($groupId === null) {
            if ($witnesses !== []) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics active action references a missing native group'
                );
            }
            return ['rows' => [], 'sha256' => self::digest([])];
        }
        $rows = [];
        foreach ($witnesses as $witness) {
            $actionId = self::db_positive_uint($witness['action_id'] ?? null, 'raw action');
            $argsBytes = self::db_positive_or_zero_uint($witness['args_bytes'] ?? null, 'raw args bytes');
            $scheduleBytes = self::db_positive_uint($witness['schedule_bytes'] ?? null, 'raw schedule bytes');
            if (isset($rows[$actionId])
                || ($witness['hook'] ?? null) !== self::ANALYTICS_HOOK
                || !in_array($witness['status'] ?? null, ['pending', 'in-progress'], true)
                || ($witness['group_id'] ?? null) !== (string) $groupId
                || !self::canonical_uint($witness['claim_id'] ?? null)
                || ($witness['priority'] ?? null) !== '10'
                || !self::valid_mysql_datetime((string) ($witness['scheduled_date_gmt'] ?? ''))
                || !self::valid_mysql_datetime((string) ($witness['scheduled_date_local'] ?? ''))
                || !self::valid_native_attempt_date($witness['last_attempt_gmt'] ?? null)
                || !self::valid_native_attempt_date($witness['last_attempt_local'] ?? null)
                || $argsBytes > self::MAX_ACTION_ARGS_BYTES
                || ($witness['extended_args_bytes'] ?? null) !== null
                || $scheduleBytes > self::MAX_ACTION_SCHEDULE_BYTES) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics raw action witness is malformed or exceeds its byte grammar'
                );
            }
            $payload = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
                "SELECT action_id, args, extended_args, schedule, LENGTH(args) AS args_bytes, "
                . "LENGTH(schedule) AS schedule_bytes FROM `$actionsTable` WHERE action_id = %d "
                . 'AND LENGTH(args) = %d AND LENGTH(args) <= %d AND extended_args IS NULL '
                . 'AND LENGTH(schedule) = %d AND LENGTH(schedule) <= %d '
                . 'ORDER BY action_id ASC LIMIT 2',
                $actionId,
                $argsBytes,
                self::MAX_ACTION_ARGS_BYTES,
                $scheduleBytes,
                self::MAX_ACTION_SCHEDULE_BYTES
            ), 'WooCommerce analytics bounded raw action payload');
            if (count($payload) !== 1
                || ($payload[0]['action_id'] ?? null) !== (string) $actionId
                || ($payload[0]['args_bytes'] ?? null) !== (string) $argsBytes
                || ($payload[0]['schedule_bytes'] ?? null) !== (string) $scheduleBytes
                || ($payload[0]['extended_args'] ?? null) !== null
                || !is_string($payload[0]['args'] ?? null)
                || !is_string($payload[0]['schedule'] ?? null)) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics raw action changed during bounded payload read; recovery_required'
                );
            }
            try {
                $args = json_decode($payload[0]['args'], true, 8, JSON_THROW_ON_ERROR);
            } catch (\Throwable $failure) {
                throw new \RuntimeException('duo: WooCommerce analytics raw action arguments are malformed');
            }
            if (!is_array($args) || wp_json_encode($args) !== $payload[0]['args']) {
                throw new \RuntimeException('duo: WooCommerce analytics raw action arguments are noncanonical');
            }
            $scheduleClass = self::safe_schedule_class($payload[0]['schedule']);
            $rows[$actionId] = [
                'args_sha256' => self::digest($args),
                'claim_id' => (int) $witness['claim_id'],
                'group_id' => $groupId,
                'hook' => self::ANALYTICS_HOOK,
                'last_attempt_gmt' => $witness['last_attempt_gmt'],
                'last_attempt_local' => $witness['last_attempt_local'],
                'payload_sha256' => hash('sha256', $payload[0]['args'] . "\0" . $payload[0]['schedule']),
                'priority' => 10,
                'schedule_class' => $scheduleClass,
                'scheduled_date_gmt' => $witness['scheduled_date_gmt'],
                'scheduled_date_local' => $witness['scheduled_date_local'],
                'status' => $witness['status'],
            ];
        }
        ksort($rows, SORT_NUMERIC);
        return ['rows' => $rows, 'sha256' => self::digest($rows)];
    }

    private static function safe_schedule_class(string $raw): string {
        if (preg_match('/^O:([0-9]+):"([A-Za-z0-9_]+)":/D', $raw, $header) !== 1
            || (int) $header[1] !== strlen($header[2])
            || !in_array($header[2], ['ActionScheduler_IntervalSchedule', 'ActionScheduler_SimpleSchedule'], true)
            || preg_match('/(?:^|[;{}])(?:[rRC]):[0-9]+[;:]/', $raw) === 1) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics serialized schedule is outside its exact native class grammar'
            );
        }
        set_error_handler(static function (int $severity, string $message): never {
            throw new \RuntimeException('safe schedule decoding failed');
        });
        try {
            $decoded = unserialize($raw, ['allowed_classes' => false]);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: WooCommerce analytics serialized schedule is malformed');
        } finally {
            restore_error_handler();
        }
        if (!is_object($decoded) || get_class($decoded) !== '__PHP_Incomplete_Class') {
            throw new \RuntimeException('duo: WooCommerce analytics serialized schedule is malformed');
        }
        if (serialize($decoded) !== $raw) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics serialized schedule is noncanonical or has trailing bytes'
            );
        }
        $properties = (array) $decoded;
        $class = $properties['__PHP_Incomplete_Class_Name'] ?? null;
        unset($properties['__PHP_Incomplete_Class_Name']);
        if ($class !== $header[2]) {
            throw new \RuntimeException('duo: WooCommerce analytics serialized schedule class changed');
        }
        $expectedWireKeys = $class === 'ActionScheduler_SimpleSchedule'
            ? ["\0*\0scheduled_timestamp", "\0ActionScheduler_SimpleSchedule\0timestamp"]
            : [
                "\0*\0scheduled_timestamp",
                "\0*\0first_timestamp",
                "\0*\0recurrence",
                "\0ActionScheduler_IntervalSchedule\0start_timestamp",
                "\0ActionScheduler_IntervalSchedule\0interval_in_seconds",
            ];
        if (array_keys($properties) !== $expectedWireKeys) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics serialized schedule fields are outside the exact native wire'
            );
        }
        $normalized = [];
        foreach ($properties as $key => $value) {
            if (!is_string($key) || (!is_int($value) && $value !== null)) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics serialized schedule contains non-scalar state'
                );
            }
            $parts = explode("\0", $key);
            $name = end($parts);
            if (!is_string($name) || $name === '' || array_key_exists($name, $normalized)) {
                throw new \RuntimeException('duo: WooCommerce analytics serialized schedule fields are malformed');
            }
            $normalized[$name] = $value;
        }
        ksort($normalized, SORT_STRING);
        $expected = $class === 'ActionScheduler_SimpleSchedule'
            ? ['scheduled_timestamp', 'timestamp']
            : ['first_timestamp', 'interval_in_seconds', 'recurrence', 'scheduled_timestamp', 'start_timestamp'];
        sort($expected, SORT_STRING);
        if (array_keys($normalized) !== $expected
            || !is_int($normalized['scheduled_timestamp'] ?? null)
            || $normalized['scheduled_timestamp'] < 1
            || $normalized['scheduled_timestamp'] > time() + self::ACTION_HORIZON_SECONDS
            || ($class === 'ActionScheduler_SimpleSchedule'
                && ($normalized['timestamp'] ?? null) !== $normalized['scheduled_timestamp'])
            || ($class === 'ActionScheduler_IntervalSchedule'
                && ((!is_int($normalized['recurrence'] ?? null))
                    || $normalized['recurrence'] < 1
                    || $normalized['recurrence'] > self::ACTION_HORIZON_SECONDS
                    || !is_int($normalized['first_timestamp'] ?? null)
                    || $normalized['first_timestamp'] < 1
                    || $normalized['first_timestamp'] > $normalized['scheduled_timestamp']
                    || ($normalized['interval_in_seconds'] ?? null) !== $normalized['recurrence']
                    || ($normalized['start_timestamp'] ?? null) !== $normalized['scheduled_timestamp']))) {
            throw new \RuntimeException('duo: WooCommerce analytics serialized schedule fields disagree');
        }
        return $class;
    }

    /** @param list<mixed> $args */
    private static function native_work_args(array $args): bool {
        return count($args) === 2
            && is_string($args[0])
            && self::valid_mysql_datetime($args[0])
            && self::canonical_uint($args[1]);
    }

    /** @return array<string,mixed> */
    private static function cursor_state(): array {
        $dateRecord = self::raw_option_record(self::CURSOR_DATE_OPTION, 19);
        $date = $dateRecord['value'] ?? null;
        if ($date !== null && !self::valid_mysql_datetime($date)) {
            throw new \RuntimeException('duo: WooCommerce analytics date cursor is malformed');
        }
        $idRecord = self::raw_option_record(self::CURSOR_ID_OPTION, 20);
        $id = $idRecord['value'] ?? null;
        if ($id !== null && !self::canonical_uint($id)) {
            throw new \RuntimeException('duo: WooCommerce analytics ID cursor is malformed');
        }
        return [
            'date_present' => $date === null ? 0 : 1,
            'date_raw' => $date,
            'date_sha256' => hash('sha256', $date ?? 'absent'),
            'date_autoload' => $dateRecord['autoload'] ?? null,
            'id_present' => $id === null ? 0 : 1,
            'id_raw' => $id,
            'id_sha256' => hash('sha256', $id ?? 'absent'),
            'id_autoload' => $idRecord['autoload'] ?? null,
        ];
    }

    /** @return array<string,mixed> */
    private static function retention_topology(): array {
        $record = self::raw_option_record('cron', self::MAX_CRON_BYTES);
        $raw = $record['value'] ?? null;
        $topology = self::retention_topology_from_raw($raw, true);
        $topology['cron_autoload'] = $record['autoload'] ?? null;
        return $topology;
    }

    /** @return array{raw:string,topology:array<string,mixed>,witness:array<string,mixed>} */
    private static function locked_cron_state(): array {
        global $wpdb;
        self::assert_transaction_state(true);
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_id, BINARY option_name AS option_name, autoload, "
            . "LENGTH(option_value) AS option_bytes "
            . "FROM `{$wpdb->options}` FORCE INDEX (`option_name`) WHERE option_name = %s "
            . 'ORDER BY option_id ASC LIMIT 2 FOR UPDATE',
            'cron'
        ), 'WooCommerce stock-retention locked cron witness');
        self::assert_transaction_state(true);
        if (count($rows) !== 1
            || ($rows[0]['option_name'] ?? null) !== 'cron'
            || !self::canonical_positive_uint($rows[0]['option_id'] ?? null)
            || !self::canonical_uint($rows[0]['option_bytes'] ?? null)
            || (int) $rows[0]['option_bytes'] > self::MAX_CRON_BYTES
            || !self::valid_autoload($rows[0]['autoload'] ?? null)) {
            throw new \RuntimeException(
                'duo: WordPress cron option is absent, aliased, duplicated, oversized, or malformed under lock'
            );
        }
        $digestRows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_id, BINARY option_name AS option_name, autoload, "
            . "LENGTH(option_value) AS option_bytes, SHA2(option_value, 256) AS option_sha256 "
            . "FROM `{$wpdb->options}` WHERE option_id = %d AND BINARY option_name = BINARY %s "
            . 'AND LENGTH(option_value) = %d AND LENGTH(option_value) <= %d '
            . 'ORDER BY option_id ASC LIMIT 2',
            (int) $rows[0]['option_id'], 'cron', (int) $rows[0]['option_bytes'], self::MAX_CRON_BYTES
        ), 'WooCommerce stock-retention locked cron digest witness');
        self::assert_transaction_state(true);
        if (count($digestRows) !== 1
            || ($digestRows[0]['option_id'] ?? null) !== (string) (int) $rows[0]['option_id']
            || ($digestRows[0]['option_name'] ?? null) !== 'cron'
            || ($digestRows[0]['autoload'] ?? null) !== $rows[0]['autoload']
            || ($digestRows[0]['option_bytes'] ?? null) !== (string) (int) $rows[0]['option_bytes']
            || !is_string($digestRows[0]['option_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $digestRows[0]['option_sha256']) !== 1) {
            throw new \RuntimeException(
                'duo: WordPress cron option changed before its bounded locked digest; recovery_required'
            );
        }
        $witness = [
            'id' => (int) $rows[0]['option_id'],
            'bytes' => (int) $rows[0]['option_bytes'],
            'sha256' => $digestRows[0]['option_sha256'],
            'autoload' => $rows[0]['autoload'],
        ];
        $payload = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_id, BINARY option_name AS option_name, option_value, autoload, "
            . "LENGTH(option_value) AS option_bytes "
            . "FROM `{$wpdb->options}` WHERE option_id = %d AND BINARY option_name = BINARY %s "
            . 'AND LENGTH(option_value) = %d AND LENGTH(option_value) <= %d '
            . 'ORDER BY option_id ASC LIMIT 2',
            $witness['id'], 'cron', $witness['bytes'], self::MAX_CRON_BYTES
        ), 'WooCommerce stock-retention locked cron payload');
        self::assert_transaction_state(true);
        if (count($payload) !== 1
            || ($payload[0]['option_id'] ?? null) !== (string) $witness['id']
            || ($payload[0]['option_name'] ?? null) !== 'cron'
            || ($payload[0]['autoload'] ?? null) !== $witness['autoload']
            || ($payload[0]['option_bytes'] ?? null) !== (string) $witness['bytes']
            || !is_string($payload[0]['option_value'] ?? null)
            || strlen($payload[0]['option_value']) !== $witness['bytes']
            || !hash_equals($witness['sha256'], hash('sha256', $payload[0]['option_value']))) {
            throw new \RuntimeException(
                'duo: WordPress cron option changed during its locked bounded payload read; recovery_required'
            );
        }
        self::refresh_cron_option_cache(true);
        $topology = self::retention_topology_from_raw($payload[0]['option_value'], false);
        $topology['cron_autoload'] = $witness['autoload'];
        return ['raw' => $payload[0]['option_value'], 'topology' => $topology, 'witness' => $witness];
    }

    /** @return array{days:int,present:int,raw:string,sha256:string,autoload:?string} */
    private static function locked_retention_option_state(): array {
        global $wpdb;
        self::assert_transaction_state(true);
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_id, BINARY option_name AS option_name, autoload, "
            . "LENGTH(option_value) AS option_bytes FROM `{$wpdb->options}` "
            . 'FORCE INDEX (`option_name`) WHERE option_name = %s '
            . 'ORDER BY option_name ASC, option_id ASC LIMIT 2 FOR UPDATE',
            self::RETENTION_OPTION
        ), 'WooCommerce stock-retention locked source witness');
        self::assert_transaction_state(true);
        if (count($rows) > 1) {
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention setting is aliased or duplicated under lock'
            );
        }
        if ($rows === []) {
            self::refresh_option_cache(self::RETENTION_OPTION, true);
            if (get_option(self::RETENTION_OPTION, false) !== false) {
                throw new \RuntimeException(
                    'duo: WooCommerce stock-retention locked raw and native absent views disagree'
                );
            }
            return [
                'days' => 0,
                'present' => 0,
                'raw' => '',
                'sha256' => hash('sha256', 'absent'),
                'autoload' => null,
            ];
        }
        $row = $rows[0];
        if (($row['option_name'] ?? null) !== self::RETENTION_OPTION
            || !self::canonical_positive_uint($row['option_id'] ?? null)
            || !self::canonical_uint($row['option_bytes'] ?? null)
            || (int) $row['option_bytes'] > 16
            || !self::valid_autoload($row['autoload'] ?? null)) {
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention locked source witness is aliased, oversized, or malformed'
            );
        }
        $optionId = (int) $row['option_id'];
        $bytes = (int) $row['option_bytes'];
        $payload = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_id, BINARY option_name AS option_name, option_value, autoload, "
            . "LENGTH(option_value) AS option_bytes, SHA2(option_value, 256) AS option_sha256 "
            . "FROM `{$wpdb->options}` WHERE option_id = %d "
            . 'AND BINARY option_name = BINARY %s AND LENGTH(option_value) = %d '
            . 'AND LENGTH(option_value) <= 16 ORDER BY option_id ASC LIMIT 2 FOR UPDATE',
            $optionId,
            self::RETENTION_OPTION,
            $bytes
        ), 'WooCommerce stock-retention locked source payload');
        self::assert_transaction_state(true);
        if (count($payload) !== 1
            || ($payload[0]['option_id'] ?? null) !== (string) $optionId
            || ($payload[0]['option_name'] ?? null) !== self::RETENTION_OPTION
            || ($payload[0]['autoload'] ?? null) !== $row['autoload']
            || ($payload[0]['option_bytes'] ?? null) !== (string) $bytes
            || !is_string($payload[0]['option_value'] ?? null)
            || strlen($payload[0]['option_value']) !== $bytes
            || !is_string($payload[0]['option_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $payload[0]['option_sha256']) !== 1
            || !hash_equals($payload[0]['option_sha256'], hash('sha256', $payload[0]['option_value']))) {
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention setting changed during its locked bounded read; recovery_required'
            );
        }
        $raw = $payload[0]['option_value'];
        if ($raw !== ''
            && (preg_match('/^(?:0|[1-9][0-9]{0,9})$/D', $raw) !== 1
                || (int) $raw > self::MAX_RETENTION_DAYS)) {
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention threshold is outside its reviewed nonnegative whole-day boundary'
            );
        }
        self::refresh_option_cache(self::RETENTION_OPTION, true);
        if (get_option(self::RETENTION_OPTION, false) !== $raw) {
            throw new \RuntimeException(
                'duo: WooCommerce stock-retention locked raw and native option views disagree'
            );
        }
        return [
            'days' => $raw === '' ? 0 : (int) $raw,
            'present' => 1,
            'raw' => $raw,
            'sha256' => $payload[0]['option_sha256'],
            'autoload' => $payload[0]['autoload'],
        ];
    }

    private static function refresh_cron_option_cache(bool $transactionExpected): void {
        self::refresh_option_cache('cron', $transactionExpected);
    }

    private static function refresh_option_cache(string $name, bool $transactionExpected): void {
        self::assert_local_option_cache();
        foreach ([$name, 'alloptions', 'notoptions'] as $key) {
            wp_cache_delete($key, 'options');
            self::assert_transaction_state($transactionExpected);
            $found = null;
            wp_cache_get($key, 'options', false, $found);
            if (!is_bool($found) || $found) {
                throw new \RuntimeException(
                    'duo: WordPress option-cache deletion did not persist before cron observation; recovery_required'
                );
            }
        }
    }

    private static function assert_local_option_cache(): void {
        if (!function_exists('wp_using_ext_object_cache')
            || !function_exists('wp_cache_delete')
            || !function_exists('wp_cache_get')) {
            throw new \RuntimeException('duo: WordPress exact option-cache boundary is unavailable');
        }
        $external = wp_using_ext_object_cache();
        // Core leaves $_wp_using_ext_object_cache unset when no drop-in was
        // loaded, so stock WordPress returns null here; only true denotes the
        // persistent publication boundary this provider cannot fence.
        if ($external !== null && !is_bool($external)) {
            throw new \RuntimeException('duo: WordPress returned a malformed external object-cache state');
        }
        if ($external === true) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler option repair refuses external object-cache publication'
            );
        }
    }

    /** @return array<string,mixed> */
    private static function retention_topology_from_raw(?string $raw, bool $verifyRawReadback): array {
        $decoded = [];
        if ($raw !== null) {
            $plain = \Duo\PlainData::decode_serialized($raw, 'WordPress cron option');
            if (!is_array($plain)
                || ($plain['version'] ?? null) !== 2) {
                throw new \RuntimeException('duo: WordPress cron option is outside its exact plain-data version');
            }
            unset($plain['version']);
            $decoded = $plain;
        }
        $crons = _get_cron_array();
        if (!is_array($crons)) {
            throw new \RuntimeException('duo: WordPress returned a malformed cron inventory');
        }
        if ($crons !== $decoded
            || ($verifyRawReadback && self::raw_option('cron', self::MAX_CRON_BYTES) !== $raw)) {
            throw new \RuntimeException(
                'duo: WordPress raw and native cron inventories disagree or changed during bounded observation; recovery_required'
            );
        }
        $events = [];
        $unrelated = [];
        $total = 0;
        foreach ($crons as $timestamp => $hooks) {
            if (!self::canonical_positive_uint($timestamp) || !is_array($hooks)) {
                throw new \RuntimeException('duo: WordPress cron inventory contains a malformed timestamp bucket');
            }
            foreach ($hooks as $hook => $instances) {
                if (!is_string($hook) || !is_array($instances)) {
                    throw new \RuntimeException('duo: WordPress cron inventory contains a malformed hook bucket');
                }
                $total += count($instances);
                if ($total > self::MAX_CRON_EVENTS) {
                    throw new \RuntimeException('duo: WordPress cron inventory exceeds the bounded event limit');
                }
                if ($hook !== self::RETENTION_HOOK) {
                    foreach ($instances as $instanceKey => $event) {
                        if (!is_string($instanceKey)
                            || !is_array($event)
                            || !is_array($event['args'] ?? null)
                            || !hash_equals(md5(serialize($event['args'])), $instanceKey)) {
                            throw new \RuntimeException(
                                'duo: WordPress cron inventory contains a malformed unrelated event'
                            );
                        }
                        $bytes = serialize($event);
                        $unrelated[] = [
                            'event_bytes' => strlen($bytes),
                            'event_sha256' => hash('sha256', $bytes),
                            'hook_sha256' => hash('sha256', $hook),
                            'instance' => $instanceKey,
                            'timestamp' => (int) $timestamp,
                        ];
                    }
                    continue;
                }
                foreach ($instances as $instanceKey => $event) {
                    if (!is_array($event)
                        || ($event['args'] ?? null) !== []
                        || ($event['schedule'] ?? null) !== 'daily'
                        || ($event['interval'] ?? null) !== DAY_IN_SECONDS
                        || !is_string($instanceKey)
                        || !hash_equals(md5(serialize($event['args'] ?? null)), $instanceKey)) {
                        throw new \RuntimeException(
                            'duo: WooCommerce stock-retention hook has a foreign or malformed cron event'
                        );
                    }
                    $events[] = ['timestamp' => (int) $timestamp];
                }
            }
        }
        usort($events, static fn(array $left, array $right): int => $left['timestamp'] <=> $right['timestamp']);
        usort($unrelated, static fn(array $left, array $right): int => [
            $left['timestamp'], $left['hook_sha256'], $left['instance'],
        ] <=> [
            $right['timestamp'], $right['hook_sha256'], $right['instance'],
        ]);
        $semantic = array_map(static fn(array $event): array => [
            'args_sha256' => hash('sha256', '[]'),
            'interval' => DAY_IN_SECONDS,
            'schedule' => 'daily',
        ], $events);
        $now = time();
        $healthy = array_values(array_filter($events, static fn(array $event): bool =>
            $event['timestamp'] >= $now - self::RETENTION_PAST_GRACE_SECONDS
            && $event['timestamp'] <= $now + DAY_IN_SECONDS + self::RETENTION_FUTURE_GRACE_SECONDS
        ));
        return [
            'events' => $events,
            'healthy' => count($healthy),
            'matching' => count($events),
            'semantic' => $semantic,
            'unrelated_count' => count($unrelated),
            'unrelated_sha256' => self::digest($unrelated),
        ];
    }

    /** @return array<string,mixed> */
    private static function marker_state(): array {
        $record = self::raw_option_record(self::MARKER_OPTION, self::MAX_MARKER_BYTES);
        $raw = $record['value'] ?? null;
        if ($record === null) {
            if (get_option(self::MARKER_OPTION, false) !== false) {
                throw new \RuntimeException(
                    'duo: WooCommerce analytics marker raw and native option views disagree'
                );
            }
            return ['phase' => 'absent', 'autoload' => null, 'sha256' => hash('sha256', 'absent')];
        }
        if ($record['autoload'] !== 'off') {
            throw new \RuntimeException(
                'duo: WooCommerce analytics Duo-owned marker is not on the exact non-autoloaded platform wire'
            );
        }
        if (get_option(self::MARKER_OPTION, false) !== $raw) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics marker raw and native option views disagree'
            );
        }
        try {
            $data = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: WooCommerce analytics transition marker is malformed');
        }
        if (!is_array($data) || self::canonical_json($data) !== $raw) {
            throw new \RuntimeException('duo: WooCommerce analytics transition marker is noncanonical');
        }
        if (($data['phase'] ?? null) === 'verified') {
            if (self::sorted_keys($data) !== ['format', 'operation_sha256', 'phase', 'state']
                || ($data['format'] ?? null) !== self::MARKER_FORMAT
                || !in_array($data['state'] ?? null, ['yes', 'no'], true)
                || !is_string($data['operation_sha256'] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $data['operation_sha256']) !== 1) {
                throw new \RuntimeException('duo: WooCommerce analytics verified marker is malformed');
            }
            return [
                'phase' => 'verified',
                'autoload' => $record['autoload'],
                'data' => $data,
                'sha256' => hash('sha256', $raw),
            ];
        }
        self::assert_intent_schema($data);
        return [
            'phase' => 'intent',
            'autoload' => $record['autoload'],
            'data' => $data,
            'sha256' => hash('sha256', $raw),
        ];
    }

    /** @param array<string,mixed> $intent */
    private static function assert_intent_schema(array $intent): void {
        $expected = [
            'before_cursor_date_autoload', 'before_cursor_date_present', 'before_cursor_date_sha256',
            'before_cursor_id_autoload', 'before_cursor_id_present', 'before_cursor_id_sha256',
            'before_source_sha256', 'before_topology_sha256', 'before_work_sha256',
            'catchup_required', 'expected_cursor_date', 'format', 'from', 'interval',
            'origin_operation_sha256', 'phase', 'plan', 'to',
        ];
        if (self::sorted_keys($intent) !== $expected
            || ($intent['format'] ?? null) !== self::MARKER_FORMAT
            || ($intent['phase'] ?? null) !== 'intent'
            || !in_array($intent['from'] ?? null, ['unknown', 'yes', 'no'], true)
            || !in_array($intent['to'] ?? null, ['yes', 'no'], true)
            || !in_array($intent['plan'] ?? null, [
                'adopt_no', 'adopt_to_no', 'adopt_yes', 'no_to_yes', 'steady_no', 'steady_yes', 'yes_to_no',
            ], true)
            || !in_array($intent['before_cursor_date_present'] ?? null, [0, 1], true)
            || !in_array($intent['before_cursor_id_present'] ?? null, [0, 1], true)
            || !in_array($intent['catchup_required'] ?? null, [0, 1], true)
            || !is_int($intent['interval'] ?? null)
            || $intent['interval'] < 60
            || $intent['interval'] > 604800) {
            throw new \RuntimeException('duo: WooCommerce analytics transition intent is malformed');
        }
        foreach ([
            ['before_cursor_date_present', 'before_cursor_date_autoload'],
            ['before_cursor_id_present', 'before_cursor_id_autoload'],
        ] as [$presentKey, $autoloadKey]) {
            if (($intent[$presentKey] === 0 && $intent[$autoloadKey] !== null)
                || ($intent[$presentKey] === 1 && !self::valid_autoload($intent[$autoloadKey] ?? null))) {
                throw new \RuntimeException('duo: WooCommerce analytics transition cursor autoload is malformed');
            }
        }
        foreach ([
            'before_cursor_date_sha256', 'before_cursor_id_sha256', 'before_source_sha256',
            'before_topology_sha256', 'before_work_sha256', 'origin_operation_sha256',
        ] as $hash) {
            if (!is_string($intent[$hash] ?? null)
                || preg_match('/^[a-f0-9]{64}$/D', $intent[$hash]) !== 1) {
                throw new \RuntimeException('duo: WooCommerce analytics transition intent hash is malformed');
            }
        }
        if (!is_string($intent['expected_cursor_date'] ?? null)
            || (($intent['plan'] === 'no_to_yes') !== self::valid_mysql_datetime($intent['expected_cursor_date']))) {
            throw new \RuntimeException('duo: WooCommerce analytics transition intent cursor is malformed');
        }
    }

    /** @return array{format:string,operation_sha256:string,phase:string,state:string} */
    private static function terminal_marker(string $state, string $operationHash): array {
        return [
            'format' => self::MARKER_FORMAT,
            'operation_sha256' => $operationHash,
            'phase' => 'verified',
            'state' => $state,
        ];
    }

    /** @param array<string,mixed> $marker */
    private static function write_marker(array $marker): void {
        $raw = self::canonical_json($marker);
        if (strlen($raw) > self::MAX_MARKER_BYTES) {
            throw new \RuntimeException('duo: WooCommerce analytics transition marker exceeds its byte bound');
        }
        update_option(self::MARKER_OPTION, $raw, false);
        try {
            $readback = self::marker_state();
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics transition marker write was not durable; recovery_required',
                0,
                $failure
            );
        }
        if (($readback['data'] ?? null) !== $marker) {
            throw new \RuntimeException(
                'duo: WooCommerce analytics transition marker write was not durable; recovery_required'
            );
        }
    }

    /** @return array<string,mixed> */
    private static function analytics_exact_receipt(
        array $source,
        array $marker,
        array $topology,
        array $cursors,
        int $interval
    ): array {
        $schema = self::action_scheduler_schema_state();
        return [
            'cursor_sha256' => self::digest([
                $cursors['date_present'], $cursors['date_sha256'], $cursors['date_autoload'],
                $cursors['id_present'], $cursors['id_sha256'], $cursors['id_autoload'],
            ]),
            'marker_phase' => $marker['phase'],
            'marker_autoload' => $marker['autoload'],
            'marker_sha256' => $marker['sha256'],
            'native_interval' => $interval,
            'option_present' => $source['present'],
            'option_sha256' => $source['sha256'],
            'scheduler_schema_autoload' => $schema['autoload'],
            'scheduler_schema_sha256' => $schema['sha256'],
            'topology_sha256' => $topology['sha256'],
        ];
    }

    /** @return array<string,mixed> */
    private static function retention_exact_receipt(array $source, array $topology): array {
        return [
            'cron_autoload' => $topology['cron_autoload'],
            'matching_events' => $topology['matching'],
            'option_autoload' => $source['autoload'],
            'option_present' => $source['present'],
            'option_sha256' => $source['sha256'],
            'retention_enabled' => $source['days'] > 0 ? 1 : 0,
            'topology_sha256' => self::digest($topology['semantic']),
            'unrelated_count' => $topology['unrelated_count'],
            'unrelated_sha256' => $topology['unrelated_sha256'],
        ];
    }

    private static function raw_option(string $name, int $maxBytes): ?string {
        $record = self::raw_option_record($name, $maxBytes);
        return $record['value'] ?? null;
    }

    /** @return ?array{value:string,autoload:string} */
    private static function raw_option_record(string $name, int $maxBytes): ?array {
        global $wpdb;
        self::assert_options_table_identity();
        $firstWitness = self::option_witness($name, $maxBytes);
        $secondWitness = self::option_witness($name, $maxBytes);
        if ($firstWitness !== $secondWitness) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler setting changed during compact option observation; recovery_required'
            );
        }
        if ($firstWitness === null) {
            return null;
        }
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_id, BINARY option_name AS option_name, option_value, autoload, "
            . "LENGTH(option_value) AS option_bytes "
            . "FROM `{$wpdb->options}` WHERE option_id = %d AND BINARY option_name = BINARY %s "
            . 'AND LENGTH(option_value) = %d AND LENGTH(option_value) <= %d '
            . 'ORDER BY option_id ASC LIMIT 2',
            $firstWitness['id'], $name, $firstWitness['bytes'], $maxBytes
        ), 'WooCommerce scheduler setting bounded payload read');
        if (count($rows) !== 1
            || ($rows[0]['option_id'] ?? null) !== (string) $firstWitness['id']
            || ($rows[0]['option_name'] ?? null) !== $name
            || ($rows[0]['autoload'] ?? null) !== $firstWitness['autoload']
            || ($rows[0]['option_bytes'] ?? null) !== (string) $firstWitness['bytes']
            || !is_string($rows[0]['option_value'] ?? null)
            || strlen($rows[0]['option_value']) !== $firstWitness['bytes']
            || !hash_equals($firstWitness['sha256'], hash('sha256', $rows[0]['option_value']))) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler setting changed during bounded option read; recovery_required'
            );
        }
        if (self::option_witness($name, $maxBytes) !== $firstWitness) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler setting changed after bounded option read; recovery_required'
            );
        }
        return ['value' => $rows[0]['option_value'], 'autoload' => $rows[0]['autoload']];
    }

    /** @return ?array{id:int,bytes:int,sha256:string,autoload:string} */
    private static function option_witness(string $name, int $maxBytes): ?array {
        global $wpdb;
        $rows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_id, BINARY option_name AS option_name, autoload, "
            . "LENGTH(option_value) AS option_bytes "
            . "FROM `{$wpdb->options}` "
            . 'WHERE option_name = %s ORDER BY option_id ASC LIMIT 2',
            $name
        ), 'WooCommerce scheduler setting compact length witness');
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1
            || ($rows[0]['option_name'] ?? null) !== $name
            || !self::canonical_positive_uint($rows[0]['option_id'] ?? null)
            || !self::canonical_uint($rows[0]['option_bytes'] ?? null)
            || !self::valid_autoload($rows[0]['autoload'] ?? null)) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler setting is absent, aliased, duplicated, or has a malformed witness'
            );
        }
        $bytes = (int) $rows[0]['option_bytes'];
        if ($bytes > $maxBytes) {
            throw new \RuntimeException('duo: WooCommerce scheduler setting exceeds its bounded byte grammar');
        }
        $digestRows = \Duo\ProviderSdk::checked_get_results($wpdb->prepare(
            "SELECT option_id, BINARY option_name AS option_name, autoload, "
            . "LENGTH(option_value) AS option_bytes, "
            . "SHA2(option_value, 256) AS option_sha256 FROM `{$wpdb->options}` "
            . 'WHERE option_id = %d AND BINARY option_name = BINARY %s '
            . 'AND LENGTH(option_value) = %d AND LENGTH(option_value) <= %d '
            . 'ORDER BY option_id ASC LIMIT 2',
            (int) $rows[0]['option_id'], $name, $bytes, $maxBytes
        ), 'WooCommerce scheduler setting bounded digest witness');
        if (count($digestRows) !== 1
            || ($digestRows[0]['option_id'] ?? null) !== (string) (int) $rows[0]['option_id']
            || ($digestRows[0]['option_name'] ?? null) !== $name
            || ($digestRows[0]['autoload'] ?? null) !== $rows[0]['autoload']
            || ($digestRows[0]['option_bytes'] ?? null) !== (string) $bytes
            || !is_string($digestRows[0]['option_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $digestRows[0]['option_sha256']) !== 1) {
            throw new \RuntimeException(
                'duo: WooCommerce scheduler setting changed before its bounded digest; recovery_required'
            );
        }
        return [
            'id' => (int) $rows[0]['option_id'],
            'bytes' => $bytes,
            'sha256' => $digestRows[0]['option_sha256'],
            'autoload' => $rows[0]['autoload'],
        ];
    }

    private static function valid_autoload(mixed $value): bool {
        return is_string($value)
            && in_array($value, ['yes', 'no', 'on', 'off', 'auto', 'auto-on', 'auto-off'], true);
    }

    private static function valid_mysql_datetime(string $value): bool {
        if (preg_match('/^[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2}$/D', $value) !== 1) {
            return false;
        }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value, new \DateTimeZone('UTC'));
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d H:i:s') === $value;
    }

    private static function canonical_positive_uint(mixed $value): bool {
        return self::canonical_uint($value) && (int) $value > 0;
    }

    private static function canonical_uint(mixed $value): bool {
        if (is_int($value)) {
            return $value >= 0;
        }
        return is_string($value)
            && preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) === 1
            && strlen($value) <= strlen((string) PHP_INT_MAX)
            && (string) (int) $value === $value;
    }

    /** @param array<string,mixed> $value @return list<string> */
    private static function sorted_keys(array $value): array {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        return $keys;
    }

    private static function canonical_json(array $value): string {
        self::sort_recursive($value);
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new \RuntimeException('duo: WooCommerce scheduler state could not be encoded');
        }
        return $encoded;
    }

    private static function digest(mixed $value): string {
        if (is_array($value)) {
            self::sort_recursive($value);
        }
        $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (!is_string($encoded)) {
            throw new \RuntimeException('duo: WooCommerce scheduler receipt could not be encoded');
        }
        return hash('sha256', $encoded);
    }

    /** @param array<mixed> $value */
    private static function sort_recursive(array &$value): void {
        foreach ($value as &$child) {
            if (is_array($child)) {
                self::sort_recursive($child);
            }
        }
        unset($child);
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
    }
}
