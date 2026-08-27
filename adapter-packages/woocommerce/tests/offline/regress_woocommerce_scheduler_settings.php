<?php
/** Offline adversarial product-path regression for WooCommerce scheduler settings. */
declare(strict_types=1);

namespace Automattic\WooCommerce\Admin\Features {
    final class Features {
        public static bool $enabled = true;

        public static function is_enabled(string $feature): bool {
            return $feature === 'analytics-scheduled-import' && self::$enabled;
        }
    }
}

namespace Automattic\WooCommerce\Internal\Admin\Schedulers {
    final class OrdersScheduler {
        public const PROCESS_PENDING_ORDERS_BATCH_ACTION = 'process_pending_batch';
        public static int $interval = 43200;

        public static function get_import_interval(): int {
            return apply_filters('woocommerce_analytics_import_interval', self::$interval);
        }

        public static function queue(): object {
            return $GLOBALS['wooSchedulerQueue'];
        }

        public static function schedule_recurring_batch_processor(): void {
            foreach (\woo_scheduler_action_rows() as $row) {
                if ($row['status'] === 'pending'
                    && $row['_action']->get_hook() === 'wc-admin_process_pending_orders_batch') {
                    return;
                }
            }
            $pre = apply_filters(
                'pre_as_schedule_recurring_action',
                null,
                time(),
                self::$interval,
                'wc-admin_process_pending_orders_batch',
                [],
                'wc-admin-data',
                10,
                true
            );
            if ($pre !== null) {
                return;
            }
            \woo_scheduler_add_action('recurring', [], self::$interval);
            \woo_scheduler_fail('schedule');
        }

        /** @param list<mixed> $args */
        public static function schedule_action(string $action, array $args = []): void {
            if ($action !== self::PROCESS_PENDING_ORDERS_BATCH_ACTION) {
                return;
            }
            foreach (\woo_scheduler_action_rows() as $row) {
                if ($row['status'] === 'pending'
                    && $row['_action']->get_hook() === 'wc-admin_process_pending_orders_batch'
                    && $row['_action']->get_group() === 'wc-admin-data'
                    && $row['_action']->get_args() === $args) {
                    return;
                }
            }
            if (!get_option('schema-ActionScheduler_StoreSchema', false)) {
                $GLOBALS['wooSchedulerSynchronousAnalyticsRuns']++;
                return;
            }
            if (apply_filters('woocommerce_analytics_disable_action_scheduling', false) !== false) {
                throw new \RuntimeException('unexpected disabled analytics scheduling in exact native stub');
            }
            $pre = apply_filters(
                'pre_as_schedule_single_action',
                null,
                time() + 5,
                'wc-admin_process_pending_orders_batch',
                $args,
                'wc-admin-data',
                10,
                false
            );
            if ($pre !== null) {
                return;
            }
            \woo_scheduler_add_action('single', $args, null);
            \woo_scheduler_fail('schedule');
        }

        public static function handle_scheduled_import_option_added(string $name, mixed $value): void {
        }

        public static function handle_scheduled_import_option_change(mixed $oldValue, mixed $newValue): void {
        }

        public static function handle_scheduled_import_option_before_delete(string $name): void {
        }
    }
}

namespace Automattic\WooCommerce\Internal\StockNotifications {
    final class DataRetentionController {
        public function __construct() {
            add_action(
                'customer_stock_notifications_daily',
                [$this, 'do_wc_customer_stock_notifications_daily']
            );
            add_action(
                'update_option_woocommerce_customer_stock_notifications_unverified_deletions_days_threshold',
                [$this, 'schedule_or_unschedule_daily_task'],
                10,
                2
            );
            add_action(
                'add_option_woocommerce_customer_stock_notifications_unverified_deletions_days_threshold',
                [$this, 'schedule_or_unschedule_daily_task'],
                10,
                2
            );
            register_deactivation_hook('woocommerce/woocommerce.php', [$this, 'clear_daily_task']);
        }

        public function do_wc_customer_stock_notifications_daily(): void {
        }

        public function schedule_or_unschedule_daily_task(mixed $unused, mixed $newValue): void {
            $GLOBALS['wooSchedulerTransactionIsolations'][] = $GLOBALS['wpdb']->activeTransactionIsolation();
            if (is_callable($GLOBALS['wooSchedulerDuringRetentionController'] ?? null)) {
                ($GLOBALS['wooSchedulerDuringRetentionController'])('schedule');
            }
            if (!is_numeric($newValue) || empty($newValue)) {
                $this->clear_daily_task();
                return;
            }
            if (!\wp_next_scheduled('customer_stock_notifications_daily')) {
                \wp_schedule_event(time() + 10, 'daily', 'customer_stock_notifications_daily');
            }
        }

        public function clear_daily_task(): void {
            $GLOBALS['wooSchedulerTransactionIsolations'][] = $GLOBALS['wpdb']->activeTransactionIsolation();
            if (is_callable($GLOBALS['wooSchedulerDuringRetentionController'] ?? null)) {
                ($GLOBALS['wooSchedulerDuringRetentionController'])('clear');
            }
            \wp_clear_scheduled_hook('customer_stock_notifications_daily');
        }
    }
}

namespace Automattic\WooCommerce\Internal\Features {
    final class FeaturesController {
        public function process_updated_option(mixed $name, mixed $oldValue, mixed $newValue): void {
            $GLOBALS['wooSchedulerCoreOptionCallbackCalls'][] = [__METHOD__, $name];
        }

        public function process_added_option(mixed $name, mixed $value): void {
            $GLOBALS['wooSchedulerCoreOptionCallbackCalls'][] = [__METHOD__, $name];
        }
    }
}

namespace Automattic\WooCommerce\Internal\DataStores\Orders {
    final class CustomOrdersTableController {
        public function process_pre_update_option(mixed $value, string $name, mixed $oldValue): mixed {
            $GLOBALS['wooSchedulerCoreOptionCallbackCalls'][] = [__METHOD__, $name];
            return $value;
        }

        public function process_updated_option(string $name, mixed $oldValue, mixed $newValue): void {
            $GLOBALS['wooSchedulerCoreOptionCallbackCalls'][] = [__METHOD__, $name];
        }

        public function process_updated_option_fts_index(
            string $name,
            mixed $oldValue,
            mixed $newValue
        ): void {
            $GLOBALS['wooSchedulerCoreOptionCallbackCalls'][] = [__METHOD__, $name];
        }
    }

    final class DataSynchronizer {
        public function process_updated_option(string $name, mixed $oldValue, mixed $newValue): void {
            $GLOBALS['wooSchedulerCoreOptionCallbackCalls'][] = [__METHOD__, $name];
        }

        public function process_added_option(string $name, mixed $value): void {
            $GLOBALS['wooSchedulerCoreOptionCallbackCalls'][] = [__METHOD__, $name];
        }
    }
}

namespace {
    use Automattic\WooCommerce\Admin\Features\Features;
    use Automattic\WooCommerce\Internal\Admin\Schedulers\OrdersScheduler;
    use Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController;
    use Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer;
    use Automattic\WooCommerce\Internal\Features\FeaturesController;
    use Automattic\WooCommerce\Internal\StockNotifications\DataRetentionController;
    use Duo\Policy;
    use DuoTest\FakeWpdb;

    foreach (['MINUTE_IN_SECONDS' => 60, 'DAY_IN_SECONDS' => 86400] as $constant => $value) {
        if (!defined($constant)) {
            define($constant, $value);
        }
    }
    define('WOOCOMMERCE_BIS_ALPHA_ENABLED', true);
    require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/agent_version.php';
    duo_test_define_agent_versions();

    final class WP_Hook {
        /** @var array<int,array<string,array{function:callable,accepted_args:int}>> */
        public array $callbacks = [];
    }

    /** @var array<string,WP_Hook> */
    $GLOBALS['wp_filter'] = [];

    function woo_scheduler_callback_id(callable $callback): string {
        if (is_array($callback)) {
            return (is_object($callback[0]) ? spl_object_hash($callback[0]) : (string) $callback[0])
                . '::' . (string) $callback[1];
        }
        return is_string($callback) ? $callback : spl_object_hash($callback);
    }

    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool {
        $GLOBALS['wp_filter'][$hook] ??= new WP_Hook();
        $GLOBALS['wp_filter'][$hook]->callbacks[$priority][woo_scheduler_callback_id($callback)] = [
            'function' => $callback,
            'accepted_args' => $acceptedArgs,
        ];
        return true;
    }

    function add_action(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool {
        return add_filter($hook, $callback, $priority, $acceptedArgs);
    }

    function register_deactivation_hook(string $file, callable $callback): void {
        add_action('deactivate_' . $file, $callback, 10, 1);
    }

    function has_filter(string $hook, callable|false $callback = false): bool|int {
        $registered = $GLOBALS['wp_filter'][$hook] ?? null;
        if (!$registered instanceof WP_Hook || $registered->callbacks === []) {
            return false;
        }
        if ($callback === false) {
            return true;
        }
        $id = woo_scheduler_callback_id($callback);
        foreach ($registered->callbacks as $priority => $callbacks) {
            if (isset($callbacks[$id])) {
                return $priority;
            }
        }
        return false;
    }

    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
        $GLOBALS['wooSchedulerHookCrossings'][] = $hook;
        $registered = $GLOBALS['wp_filter'][$hook] ?? null;
        if (!$registered instanceof WP_Hook) {
            return $value;
        }
        ksort($registered->callbacks, SORT_NUMERIC);
        foreach ($registered->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                $value = ($callback['function'])(...array_slice(
                    [$value, ...$args],
                    0,
                    $callback['accepted_args']
                ));
            }
        }
        return $value;
    }

    function do_action(string $hook, mixed ...$args): void {
        $GLOBALS['wooSchedulerHookCrossings'][] = $hook;
        $registered = $GLOBALS['wp_filter'][$hook] ?? null;
        if (!$registered instanceof WP_Hook) {
            return;
        }
        ksort($registered->callbacks, SORT_NUMERIC);
        foreach ($registered->callbacks as $callbacks) {
            foreach ($callbacks as $callback) {
                ($callback['function'])(...array_slice($args, 0, $callback['accepted_args']));
            }
        }
    }

    final class ActionScheduler_ActionClaim {
        /** @param list<int> $actions */
        public function __construct(private readonly int $id, private readonly array $actions) {
        }
        public function get_id(): int {
            return $this->id;
        }
        /** @return list<int> */
        public function get_actions(): array {
            return $this->actions;
        }
    }

    final class WC_Action_Queue {
    }

    final class ActionScheduler_DBStore {
        private ?\DateTime $claim_before_date = null;
        /** @var array{group:string|list<string>,hooks:string|list<string>,exclude-groups:string|list<string>} */
        protected array $claim_filters = [
            'group' => '',
            'hooks' => '',
            'exclude-groups' => '',
        ];

        public function stake_claim(int $max, \DateTime $before, array $hooks = [], string $group = ''): ActionScheduler_ActionClaim {
            global $wpdb;
            $GLOBALS['wooSchedulerTransactionIsolations'][] = $wpdb->activeTransactionIsolation();
            $this->claim_before_date = $before;
            if ($hooks !== []) {
                $this->claim_filters['hooks'] = $hooks;
            } else {
                $hooks = is_array($this->claim_filters['hooks']) ? $this->claim_filters['hooks'] : [];
            }
            if ($group !== '') {
                $this->claim_filters['group'] = $group;
            } else {
                $group = is_string($this->claim_filters['group']) ? $this->claim_filters['group'] : '';
            }
            woo_scheduler_fail('claim_before');
            apply_filters(
                'action_scheduler_claim_actions_order_by',
                'ORDER BY priority ASC, attempts ASC, scheduled_date_gmt ASC, action_id ASC',
                1,
                $hooks
            );
            apply_filters('action_scheduler_db_supports_skip_locked', true);
            $wpdb->insert('actionscheduler_claims', ['date_created_gmt' => gmdate('Y-m-d H:i:s')]);
            $claimId = $wpdb->insert_id;
            $claimed = [];
            foreach (woo_scheduler_action_rows() as $row) {
                $action = $row['_action'];
                if (($row['status'] ?? null) !== 'pending'
                    || (int) ($row['claim_id'] ?? 0) !== 0
                    || ($hooks !== [] && !in_array($action->get_hook(), $hooks, true))
                    || ($group !== '' && $action->get_group() !== $group)
                    || $action->get_schedule()->get_date()->getTimestamp() > $before->getTimestamp()) {
                    continue;
                }
                $wpdb->update('actionscheduler_actions', [
                    'claim_id' => $claimId,
                    'last_attempt_gmt' => gmdate('Y-m-d H:i:s'),
                    'last_attempt_local' => gmdate('Y-m-d H:i:s'),
                ], [
                    'action_id' => (int) $row['action_id'],
                ]);
                $claimed[] = (int) $row['action_id'];
                if (count($claimed) >= $max) {
                    break;
                }
            }
            woo_scheduler_fail('claim_after');
            $this->claim_before_date = null;
            return new ActionScheduler_ActionClaim($claimId, $claimed);
        }

        public function release_claim(ActionScheduler_ActionClaim $claim): void {
            global $wpdb;
            woo_scheduler_fail('claim_release_before');
            $released = 0;
            foreach (woo_scheduler_action_rows() as $row) {
                if ((int) ($row['claim_id'] ?? 0) === $claim->get_id()
                    && ($row['status'] ?? null) === 'pending') {
                    $wpdb->update('actionscheduler_actions', ['claim_id' => 0], [
                        'action_id' => (int) $row['action_id'],
                    ]);
                    if (++$released === 1) {
                        woo_scheduler_fail('claim_release_partial');
                    }
                }
            }
            $wpdb->delete('actionscheduler_claims', ['claim_id' => $claim->get_id()]);
            woo_scheduler_fail('claim_release');
        }

        public function cancel_action(int $actionId): void {
            global $wpdb;
            $GLOBALS['wooSchedulerTransactionIsolations'][] = $wpdb->activeTransactionIsolation();
            if (is_callable($GLOBALS['wooSchedulerDuringCancel'] ?? null)) {
                ($GLOBALS['wooSchedulerDuringCancel'])('before', $actionId);
            }
            $updated = $wpdb->update(
                'actionscheduler_actions',
                ['status' => 'canceled'],
                ['action_id' => $actionId]
            );
            if ($updated === false) {
                throw new RuntimeException('native scheduler cancellation failed');
            }
            $GLOBALS['wooSchedulerCanceledActionIds'][] = $actionId;
            if (is_callable($GLOBALS['wooSchedulerDuringCancel'] ?? null)) {
                ($GLOBALS['wooSchedulerDuringCancel'])('middle', $actionId);
            }
            do_action('action_scheduler_canceled_action', $actionId);
            if (is_callable($GLOBALS['wooSchedulerDuringCancel'] ?? null)) {
                ($GLOBALS['wooSchedulerDuringCancel'])('after', $actionId);
            }
            woo_scheduler_fail('unschedule');
        }

        public function get_claim_id(int $actionId): int {
            foreach (woo_scheduler_action_rows() as $row) {
                if ((int) $row['action_id'] === $actionId) {
                    return (int) ($row['claim_id'] ?? 0);
                }
            }
            return 0;
        }

        public function get_status(int $actionId): string {
            foreach (woo_scheduler_action_rows() as $row) {
                if ((int) $row['action_id'] === $actionId) {
                    return (string) $row['status'];
                }
            }
            throw new RuntimeException('missing Action Scheduler action');
        }
    }

    final class ActionScheduler_DBLogger {
        public function log_stored_action(int $actionId): void {
            $this->log($actionId, 'action created');
        }
        public function log_canceled_action(int $actionId): void {
            $this->log($actionId, 'action canceled');
        }
        public function log_failed_fetch_action(int $actionId, Throwable $failure): void {
            $this->log($actionId, 'action fetch failed');
        }
        private function log(int $actionId, string $message): void {
            global $wpdb;
            $wpdb->insert('actionscheduler_logs', [
                'action_id' => $actionId,
                'message' => $message,
                'log_date_gmt' => gmdate('Y-m-d H:i:s'),
                'log_date_local' => gmdate('Y-m-d H:i:s'),
            ]);
            if ($message === 'action canceled'
                && is_callable($GLOBALS['wooSchedulerAfterCancelLog'] ?? null)) {
                ($GLOBALS['wooSchedulerAfterCancelLog'])($actionId);
            }
        }
    }

    final class ActionScheduler_ActionFactory {
    }

    final class ActionScheduler {
        public static bool $initialized = true;
        private static ?ActionScheduler_DBStore $store = null;
        private static ?ActionScheduler_DBLogger $logger = null;
        private static ?ActionScheduler_ActionFactory $factory = null;

        public static function is_initialized(string $context = ''): bool {
            return self::$initialized;
        }
        public static function store(): ActionScheduler_DBStore {
            return self::$store ??= new ActionScheduler_DBStore();
        }
        public static function logger(): ActionScheduler_DBLogger {
            return self::$logger ??= new ActionScheduler_DBLogger();
        }
        public static function factory(): ActionScheduler_ActionFactory {
            return self::$factory ??= new ActionScheduler_ActionFactory();
        }
        public static function reset(): void {
            self::$store = new ActionScheduler_DBStore();
            self::$logger = new ActionScheduler_DBLogger();
            self::$factory = new ActionScheduler_ActionFactory();
        }
    }

    final class WC_Install {
        public static function cron_schedules(array $schedules): array {
            $schedules['fifteen_minutes'] = [
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display' => 'Every 15 minutes',
            ];
            return $schedules;
        }
    }

    final class ActionScheduler_QueueRunner {
        private static ?self $instance = null;

        public static function instance(): self {
            return self::$instance ??= new self();
        }

        public static function reset(): void {
            self::$instance = new self();
        }

        public function add_wp_cron_schedule(array $schedules): array {
            $schedules['every_minute'] = [
                'interval' => MINUTE_IN_SECONDS,
                'display' => 'Every minute',
            ];
            return $schedules;
        }
    }

    class WooSchedulerSchedule {
        public function __construct(
            private readonly bool $recurring,
            private readonly ?int $interval,
            private readonly \DateTimeImmutable $date
        ) {
        }

        public function is_recurring(): bool {
            return $this->recurring;
        }

        public function get_recurrence(): ?int {
            return $this->interval;
        }

        public function get_date(): \DateTimeImmutable {
            return $this->date;
        }
    }

    final class ActionScheduler_SimpleSchedule extends WooSchedulerSchedule {
        public function __construct(\DateTimeImmutable $date) {
            parent::__construct(false, null, $date);
        }

        public function __serialize(): array {
            $timestamp = $this->get_date()->getTimestamp();
            return [
                "\0*\0scheduled_timestamp" => $timestamp,
                "\0ActionScheduler_SimpleSchedule\0timestamp" => $timestamp,
            ];
        }
    }

    final class ActionScheduler_IntervalSchedule extends WooSchedulerSchedule {
        public function __construct(\DateTimeImmutable $date, int $interval) {
            parent::__construct(true, $interval, $date);
        }

        public function __serialize(): array {
            $timestamp = $this->get_date()->getTimestamp();
            $interval = $this->get_recurrence();
            return [
                "\0*\0scheduled_timestamp" => $timestamp,
                "\0*\0first_timestamp" => $timestamp,
                "\0*\0recurrence" => $interval,
                "\0ActionScheduler_IntervalSchedule\0start_timestamp" => $timestamp,
                "\0ActionScheduler_IntervalSchedule\0interval_in_seconds" => $interval,
            ];
        }
    }

    class WooSchedulerAction {
        /** @param list<mixed> $args */
        public function __construct(
            private readonly string $hook,
            private readonly array $args,
            private readonly string $group,
            private readonly WooSchedulerSchedule $schedule
        ) {
        }

        public function get_hook(): string {
            return $this->hook;
        }

        /** @return list<mixed> */
        public function get_args(): array {
            return $this->args;
        }

        public function get_group(): string {
            return $this->group;
        }

        public function get_schedule(): WooSchedulerSchedule {
            return $this->schedule;
        }

        public function __toString(): string {
            return json_encode([
                'hook' => $this->hook,
                'args' => $this->args,
                'group' => $this->group,
                'recurring' => $this->schedule->is_recurring(),
                'interval' => $this->schedule->get_recurrence(),
                'timestamp' => $this->schedule->get_date()->getTimestamp(),
            ], JSON_THROW_ON_ERROR);
        }
    }

    final class ActionScheduler_Action extends WooSchedulerAction {
    }

    final class WooSchedulerWakeupCanary {
        public static int $wakeups = 0;

        public function __wakeup(): void {
            self::$wakeups++;
        }
    }

    final class WooSchedulerContainer {
        public function __construct(
            public readonly DataRetentionController $retention,
            public readonly FeaturesController $features,
            public readonly CustomOrdersTableController $customOrders,
            public readonly DataSynchronizer $dataSynchronizer
        ) {
        }

        public function get(string $class): object {
            return match ($class) {
                DataRetentionController::class => $this->retention,
                FeaturesController::class => $this->features,
                CustomOrdersTableController::class => $this->customOrders,
                DataSynchronizer::class => $this->dataSynchronizer,
                default => throw new RuntimeException('unregistered Woo scheduler test service'),
            };
        }
    }

    /** @return list<array<string,mixed>> */
    function woo_scheduler_option_rows(): array {
        global $wpdb;
        return $wpdb->rows('options');
    }

    function woo_scheduler_option_value(string $name, mixed $default = false): mixed {
        foreach (woo_scheduler_option_rows() as $row) {
            if (($row['option_name'] ?? null) === $name) {
                return $row['option_value'];
            }
        }
        return $default;
    }

    function woo_scheduler_option_autoload(string $name): ?string {
        foreach (woo_scheduler_option_rows() as $row) {
            if (($row['option_name'] ?? null) === $name) {
                return is_string($row['autoload'] ?? null) ? $row['autoload'] : null;
            }
        }
        return null;
    }

    function get_option(string $name, mixed $default = false): mixed {
        $pre = apply_filters('pre_option_' . $name, false, $name, $default);
        $pre = apply_filters('pre_option', $pre, $name, $default);
        if ($pre !== false) {
            return $pre;
        }
        $value = woo_scheduler_option_value($name, false);
        if ($value === false) {
            $value = apply_filters('default_option_' . $name, $default, $name, false);
            return apply_filters('default_option', $value, $name, false);
        }
        return apply_filters('option_' . $name, $value, $name);
    }

    function wp_using_ext_object_cache(?bool $using = null): bool {
        if ($using !== null) {
            $GLOBALS['wooSchedulerExternalObjectCache'] = $using;
        }
        return (bool) ($GLOBALS['wooSchedulerExternalObjectCache'] ?? false);
    }

    function wp_cache_delete(string|int $key, string $group = ''): bool {
        $GLOBALS['wooSchedulerCacheDeletes'][] = [$group, (string) $key];
        if (($GLOBALS['wooSchedulerCacheDeleteFails'] ?? null) === [$group, (string) $key]) {
            return false;
        }
        unset($GLOBALS['wooSchedulerCacheResidue'][$group][(string) $key]);
        if ($group === 'options' && in_array((string) $key, ['cron', 'alloptions', 'notoptions'], true)) {
            $raw = woo_scheduler_option_value('cron', false);
            if (is_string($raw)) {
                $decoded = @unserialize($raw, ['allowed_classes' => false]);
                if (is_array($decoded) && ($decoded['version'] ?? null) === 2) {
                    unset($decoded['version']);
                    $GLOBALS['wooSchedulerCron'] = $decoded;
                }
            }
        }
        return true;
    }

    function wp_cache_get(string|int $key, string $group = '', bool $force = false, ?bool &$found = null): mixed {
        $exists = array_key_exists((string) $key, $GLOBALS['wooSchedulerCacheResidue'][$group] ?? []);
        $found = $exists;
        return $exists ? $GLOBALS['wooSchedulerCacheResidue'][$group][(string) $key] : false;
    }

    function woo_scheduler_put_option(string $name, string|int $value, ?string $autoload = null): bool {
        global $wpdb;
        $value = (string) $value;
        $autoload ??= $name === '_duo_woocommerce_scheduler_settings_state' ? 'off' : 'no';
        foreach (woo_scheduler_option_rows() as $row) {
            if (($row['option_name'] ?? null) === $name) {
                $result = $wpdb->update('options', ['option_value' => $value, 'autoload' => $autoload], [
                    'option_id' => (int) $row['option_id'],
                ]);
                return $result !== false;
            }
        }
        return $wpdb->insert(
            'options',
            ['option_name' => $name, 'option_value' => $value, 'autoload' => $autoload]
        ) !== false;
    }

    function woo_scheduler_delete_option(string $name): void {
        global $wpdb;
        $wpdb->delete('options', ['option_name' => $name]);
    }

    function update_option(string $name, mixed $value, string|bool|null $autoload = null): bool {
        $value = apply_filters('sanitize_option_' . $name, $value, $name);
        $old = woo_scheduler_option_value($name, false);
        $value = apply_filters('pre_update_option_' . $name, $value, $old, $name);
        $value = apply_filters('pre_update_option', $value, $name, $old);
        $raw = (string) $value;
        $isMarker = $name === '_duo_woocommerce_scheduler_settings_state';
        $point = null;
        if ($isMarker && str_contains($raw, '"phase":"intent"')) {
            $point = 'marker_intent';
        } elseif ($isMarker && str_contains($raw, '"phase":"verified"')) {
            $point = 'final_marker';
        } elseif ($name === 'woocommerce_admin_scheduler_last_processed_order_modified_date') {
            $point = 'cursor_date';
        } elseif ($name === 'woocommerce_admin_scheduler_last_processed_order_id') {
            $point = 'cursor_id';
        }
        woo_scheduler_fail(($point ?? '') . '_before');
        $nativeAutoload = match (true) {
            $old !== false && $autoload === null => woo_scheduler_option_autoload($name) ?? 'no',
            $autoload === false => 'off',
            $autoload === true => 'on',
            default => 'auto',
        };
        if (!woo_scheduler_put_option($name, $raw, $nativeAutoload)) {
            return false;
        }
        $GLOBALS['wooSchedulerOptionWrites'][] = $name;
        $GLOBALS['wooSchedulerOptionHooks'][] = $old === false
            ? ['pre_add_option_' . $name, 'add_option_' . $name, 'add_option', 'added_option']
            : ['pre_update_option_' . $name, 'pre_update_option', 'update_option_' . $name, 'update_option', 'updated_option'];
        $GLOBALS['wooSchedulerOptionCacheEvents']++;
        do_action('update_option_' . $name, $old, $raw, $name);
        do_action('update_option', $name, $old, $raw);
        do_action('updated_option', $name, $old, $raw);
        if (is_callable($GLOBALS['wooSchedulerAfterOptionWrite'] ?? null)) {
            ($GLOBALS['wooSchedulerAfterOptionWrite'])($name, $raw);
        }
        woo_scheduler_fail((string) $point);
        return $old !== $raw;
    }

    function validate_plugin(string $plugin): int|WP_Error {
        return $plugin === 'woocommerce/woocommerce.php' ? 0 : new WP_Error('missing');
    }

    function get_plugins(): array {
        return ['woocommerce/woocommerce.php' => ['Version' => '11.0.1']];
    }

    function wc_get_container(): WooSchedulerContainer {
        return $GLOBALS['wooSchedulerContainer'];
    }

    /** @return list<array<string,mixed>> */
    function woo_scheduler_action_rows(): array {
        global $wpdb;
        return $wpdb->rows('actionscheduler_actions');
    }

    /** @param list<mixed> $args */
    function woo_scheduler_add_action(
        string $kind,
        array $args,
        ?int $interval,
        string $status = 'pending',
        string $group = 'wc-admin-data',
        string $hook = 'wc-admin_process_pending_orders_batch',
        ?int $timestamp = null
    ): int {
        global $wpdb;
        $groupIds = [];
        foreach ($wpdb->rows('actionscheduler_groups') as $groupRow) {
            if (($groupRow['slug'] ?? null) === $group) {
                $groupIds[] = (int) $groupRow['group_id'];
            }
        }
        if (count($groupIds) > 1) {
            throw new RuntimeException('native scheduler group lookup is ambiguous');
        }
        if ($groupIds === []) {
            if ($wpdb->insert('actionscheduler_groups', ['slug' => $group]) === false) {
                throw new RuntimeException('native scheduler group creation failed');
            }
            $groupIds[] = $wpdb->insert_id;
        }
        $groupId = $groupIds[0];
        if (is_callable($GLOBALS['wooSchedulerBeforeActionInsert'] ?? null)) {
            ($GLOBALS['wooSchedulerBeforeActionInsert'])();
        }
        $date = new \DateTimeImmutable(
            '@' . ($timestamp ?? time() + ($kind === 'recurring' ? 0 : 5))
        );
        $schedule = $kind === 'recurring'
            ? new ActionScheduler_IntervalSchedule($date, (int) $interval)
            : new ActionScheduler_SimpleSchedule($date);
        $action = new ActionScheduler_Action(
            $hook,
            $args,
            $group,
            $schedule
        );
        $wpdb->insert('actionscheduler_actions', [
            'status' => $status,
            'claim_id' => 0,
            'hook' => $hook,
            'args' => json_encode($args, JSON_THROW_ON_ERROR),
            'extended_args' => null,
            'schedule' => serialize($schedule),
            'group_id' => $groupId,
            'priority' => 10,
            'scheduled_date_gmt' => gmdate('Y-m-d H:i:s', $date->getTimestamp()),
            'scheduled_date_local' => gmdate('Y-m-d H:i:s', $date->getTimestamp()),
            'last_attempt_gmt' => '0000-00-00 00:00:00',
            'last_attempt_local' => '0000-00-00 00:00:00',
            '_action' => $action,
        ]);
        $id = $wpdb->insert_id;
        do_action('action_scheduler_stored_action', $id);
        woo_scheduler_fail('schedule_before_claim');
        return $id;
    }

    /** @return array<int,WooSchedulerAction> */
    function as_get_scheduled_actions(array $query = [], string $format = 'objects'): array {
        $GLOBALS['wooSchedulerNativeHydrations']++;
        if (is_callable($GLOBALS['wooSchedulerBeforeNativeHydration'] ?? null)) {
            ($GLOBALS['wooSchedulerBeforeNativeHydration'])();
        }
        $out = [];
        foreach (woo_scheduler_action_rows() as $row) {
            $id = (int) $row['action_id'];
            $storedAction = $row['_action'];
            $action = is_object($GLOBALS['wooSchedulerNativeActionOverride'] ?? null)
                ? $GLOBALS['wooSchedulerNativeActionOverride']
                : $storedAction;
            $class = apply_filters(
                'action_scheduler_stored_action_class',
                get_class($action),
                $row['status'],
                $action->get_hook(),
                $action->get_args(),
                $action->get_schedule(),
                $action->get_group()
            );
            $action = apply_filters(
                'action_scheduler_stored_action_instance',
                $action,
                $action->get_hook(),
                $action->get_args(),
                $action->get_schedule(),
                $action->get_group(),
                10
            );
            if (($GLOBALS['wooSchedulerNativeActionOverride'] ?? null) === null
                && ($class !== ActionScheduler_Action::class
                    || get_class($action) !== ActionScheduler_Action::class)) {
                throw new RuntimeException('exact fake Action Scheduler fetch filters changed the action');
            }
            if (($query['hook'] ?? null) !== null && $action->get_hook() !== $query['hook']) {
                continue;
            }
            if (($query['status'] ?? null) !== null && $row['status'] !== $query['status']) {
                continue;
            }
            $out[$id] = $action;
        }
        uasort($out, static fn(WooSchedulerAction $left, WooSchedulerAction $right): int =>
            $left->get_schedule()->get_date() <=> $right->get_schedule()->get_date());
        return array_slice($out, 0, (int) ($query['per_page'] ?? 5), true);
    }

    /** @param list<mixed> $args */
    function as_unschedule_all_actions(string $hook, array $args = [], string $group = ''): ?int {
        global $wpdb;
        $first = null;
        foreach (woo_scheduler_action_rows() as $row) {
            $id = (int) $row['action_id'];
            $action = $row['_action'];
            if ($row['status'] === 'pending'
                && $action->get_hook() === $hook
                && $action->get_args() === $args
                && $action->get_group() === $group) {
                $first ??= $id;
                $wpdb->update('actionscheduler_actions', ['status' => 'canceled'], ['action_id' => $id]);
                do_action('action_scheduler_canceled_action', $id);
            }
        }
        $GLOBALS['wooSchedulerUnschedules'][] = [$hook, $args, $group];
        woo_scheduler_fail('unschedule');
        return $first;
    }

    /** @return array<int,array<string,array<string,array<string,mixed>>>> */
    function _get_cron_array(): array {
        get_option('cron', []);
        return $GLOBALS['wooSchedulerCron'];
    }

    function wp_get_schedules(): array {
        return apply_filters('cron_schedules', [
            'daily' => ['interval' => DAY_IN_SECONDS, 'display' => 'Once Daily'],
        ]);
    }

    function wp_next_scheduled(string $hook, array $args = []): int|false {
        $pre = apply_filters('pre_get_scheduled_event', null, $hook, $args, null);
        if ($pre !== null) {
            return is_object($pre) && is_int($pre->timestamp ?? null) ? $pre->timestamp : false;
        }
        foreach ((array) $GLOBALS['wooSchedulerCron'] as $timestamp => $hooks) {
            foreach ((array) ($hooks[$hook] ?? []) as $event) {
                if (($event['args'] ?? null) === $args) {
                    return (int) $timestamp;
                }
            }
        }
        return apply_filters('wp_next_scheduled', false, null, $hook, $args);
    }

    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = []): bool {
        $event = (object) compact('hook', 'timestamp', 'recurrence', 'args');
        $pre = apply_filters('pre_schedule_event', null, $event, false);
        if ($pre !== null) {
            return (bool) $pre;
        }
        apply_filters('schedule_event', $event);
        if (is_callable($GLOBALS['wooSchedulerDuringCronMutation'] ?? null)) {
            ($GLOBALS['wooSchedulerDuringCronMutation'])();
        }
        $GLOBALS['wooSchedulerCron'][$timestamp][$hook][md5(serialize($args))] = [
            'schedule' => $recurrence,
            'args' => $args,
            'interval' => DAY_IN_SECONDS,
        ];
        woo_scheduler_sync_cron_option(true);
        $GLOBALS['wooSchedulerCronWrites']++;
        woo_scheduler_fail('cron_schedule');
        return true;
    }

    function wp_clear_scheduled_hook(string $hook, array $args = []): int|false {
        $pre = apply_filters('pre_clear_scheduled_hook', null, $hook, $args, false);
        if ($pre !== null) {
            return is_int($pre) ? $pre : false;
        }
        if (is_callable($GLOBALS['wooSchedulerDuringCronMutation'] ?? null)) {
            ($GLOBALS['wooSchedulerDuringCronMutation'])();
        }
        $removed = 0;
        foreach (array_keys((array) $GLOBALS['wooSchedulerCron']) as $timestamp) {
            foreach ((array) ($GLOBALS['wooSchedulerCron'][$timestamp][$hook] ?? []) as $key => $event) {
                if (($event['args'] ?? null) === $args) {
                    unset($GLOBALS['wooSchedulerCron'][$timestamp][$hook][$key]);
                    $removed++;
                }
            }
            if (($GLOBALS['wooSchedulerCron'][$timestamp][$hook] ?? []) === []) {
                unset($GLOBALS['wooSchedulerCron'][$timestamp][$hook]);
            }
            if (($GLOBALS['wooSchedulerCron'][$timestamp] ?? []) === []) {
                unset($GLOBALS['wooSchedulerCron'][$timestamp]);
            }
        }
        woo_scheduler_sync_cron_option(true);
        $GLOBALS['wooSchedulerCronWrites']++;
        woo_scheduler_fail('cron_clear');
        return $removed;
    }

    function woo_scheduler_fail(string $point): void {
        if ($point !== '' && ($GLOBALS['wooSchedulerFailPoint'] ?? null) === $point) {
            $GLOBALS['wooSchedulerFailPoint'] = null;
            throw new RuntimeException("injected Woo scheduler failure at $point");
        }
    }

    /** @return array{authority_hash:string,effect_hash:string,input_hash:string,lease_session_id:string,operation_id:string} */
    function woo_scheduler_operation(int $sequence = 1): array {
        return [
            'authority_hash' => hash('sha256', 'woo-scheduler-authority'),
            'effect_hash' => hash('sha256', 'woo-scheduler-effects'),
            'input_hash' => hash('sha256', 'woo-scheduler-input'),
            'lease_session_id' => 'woo-scheduler-lease-' . $sequence,
            'operation_id' => 'woo-scheduler-operation-' . $sequence,
        ];
    }

    /** @param array<string,array{0:int,1:list<string>}> $definitions @return list<array<string,mixed>> */
    function woo_scheduler_index_rows(array $definitions): array {
        $rows = [];
        foreach ($definitions as $key => [$nonUnique, $columns]) {
            foreach ($columns as $offset => $column) {
                $rows[] = [
                    'Key_name' => $key,
                    'Non_unique' => $nonUnique,
                    'Seq_in_index' => $offset + 1,
                    'Column_name' => $column,
                    'Sub_part' => match ($key . ':' . $column) {
                        'hook_status_scheduled_date_gmt:hook' => 163,
                        'args:args', 'slug:slug' => 191,
                        default => null,
                    },
                    'Collation' => 'A',
                    'Null' => in_array($column, [
                        'args', 'date_created_gmt', 'last_attempt_gmt', 'log_date_gmt',
                        'scheduled_date_gmt', 'scheduled_date_local',
                    ], true) ? 'YES' : '',
                    'Index_type' => 'BTREE',
                    'Visible' => 'YES',
                    'Ignored' => 'NO',
                ];
            }
        }
        return $rows;
    }

    /**
     * @param list<array{0:string,1:string,2:string,3:?string,4:string}> $definitions
     * @return list<array<string,mixed>>
     */
    function woo_scheduler_column_rows(array $definitions): array {
        return array_map(static fn(array $definition): array => [
            'Field' => $definition[0],
            'Type' => $definition[1],
            'Null' => $definition[2],
            'Key' => '',
            'Default' => $definition[3],
            'Extra' => $definition[4],
        ], $definitions);
    }

    function woo_scheduler_reset(?string $analytics = 'no', ?string $retention = ''): FakeWpdb {
        $wpdb = FakeWpdb::install();
        $rows = [[
            'option_id' => 1,
            'option_name' => 'schema-ActionScheduler_StoreSchema',
            'option_value' => '8.0.1700000000',
            'autoload' => 'yes',
        ], [
            'option_id' => 2,
            'option_name' => 'cron',
            'option_value' => serialize(['version' => 2]),
            'autoload' => 'on',
        ]];
        $next = 3;
        foreach ([
            'woocommerce_analytics_scheduled_import' => $analytics,
            'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold' => $retention,
        ] as $name => $value) {
            if ($value !== null) {
                $rows[] = [
                    'option_id' => $next++,
                    'option_name' => $name,
                    'option_value' => $value,
                    'autoload' => 'no',
                ];
            }
        }
        $wpdb->seedTable('options', $rows)->setColumnDefinitions('options', woo_scheduler_column_rows([
            ['option_id', 'bigint(20) unsigned', 'NO', null, 'auto_increment'],
            ['option_name', 'varchar(191)', 'NO', '', ''],
            ['option_value', 'longtext', 'NO', null, ''],
            ['autoload', 'varchar(20)', 'NO', 'yes', ''],
        ]))->setPrimaryKey('options', 'option_id')->setIndexes('options', woo_scheduler_index_rows([
            'PRIMARY' => [0, ['option_id']],
            'option_name' => [0, ['option_name']],
            'autoload' => [1, ['autoload']],
        ]))->setTableEngine('options', 'InnoDB');
        $wpdb->seedTable('actionscheduler_actions', [])->setColumnDefinitions(
            'actionscheduler_actions',
            woo_scheduler_column_rows([
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
            ])
        )->setPrimaryKey('actionscheduler_actions', 'action_id')->setIndexes(
            'actionscheduler_actions',
            woo_scheduler_index_rows([
                'PRIMARY' => [0, ['action_id']],
                'hook_status_scheduled_date_gmt' => [1, ['hook', 'status', 'scheduled_date_gmt']],
                'status_scheduled_date_gmt' => [1, ['status', 'scheduled_date_gmt']],
                'scheduled_date_gmt' => [1, ['scheduled_date_gmt']],
                'args' => [1, ['args']],
                'group_id' => [1, ['group_id']],
                'last_attempt_gmt' => [1, ['last_attempt_gmt']],
                'claim_id_status_priority_scheduled_date_gmt' => [1, ['claim_id', 'status', 'priority', 'scheduled_date_gmt']],
                'status_last_attempt_gmt' => [1, ['status', 'last_attempt_gmt']],
                'status_claim_id' => [1, ['status', 'claim_id']],
            ])
        )->setTableEngine('actionscheduler_actions', 'InnoDB');
        $wpdb->seedTable('actionscheduler_claims', [])->setColumnDefinitions(
            'actionscheduler_claims',
            woo_scheduler_column_rows([
                ['claim_id', 'bigint(20) unsigned', 'NO', null, 'auto_increment'],
                ['date_created_gmt', 'datetime', 'YES', '0000-00-00 00:00:00', ''],
            ])
        )->setPrimaryKey('actionscheduler_claims', 'claim_id')->setIndexes(
            'actionscheduler_claims',
            woo_scheduler_index_rows([
                'PRIMARY' => [0, ['claim_id']],
                'date_created_gmt' => [1, ['date_created_gmt']],
            ])
        )->setTableEngine('actionscheduler_claims', 'InnoDB');
        $wpdb->seedTable('actionscheduler_groups', [[
            'group_id' => 1,
            'slug' => 'wc-admin-data',
        ]])->setColumnDefinitions('actionscheduler_groups', woo_scheduler_column_rows([
            ['group_id', 'bigint(20) unsigned', 'NO', null, 'auto_increment'],
            ['slug', 'varchar(255)', 'NO', null, ''],
        ]))->setPrimaryKey('actionscheduler_groups', 'group_id')->setIndexes(
            'actionscheduler_groups',
            woo_scheduler_index_rows([
                'PRIMARY' => [0, ['group_id']],
                'slug' => [1, ['slug']],
            ])
        )->setTableEngine('actionscheduler_groups', 'InnoDB');
        $wpdb->seedTable('actionscheduler_logs', [])->setColumnDefinitions(
            'actionscheduler_logs',
            woo_scheduler_column_rows([
                ['log_id', 'bigint(20) unsigned', 'NO', null, 'auto_increment'],
                ['action_id', 'bigint(20) unsigned', 'NO', null, ''],
                ['message', 'text', 'NO', null, ''],
                ['log_date_gmt', 'datetime', 'YES', '0000-00-00 00:00:00', ''],
                ['log_date_local', 'datetime', 'YES', '0000-00-00 00:00:00', ''],
            ])
        )->setPrimaryKey('actionscheduler_logs', 'log_id')->setIndexes(
            'actionscheduler_logs',
            woo_scheduler_index_rows([
                'PRIMARY' => [0, ['log_id']],
                'action_id' => [1, ['action_id']],
                'log_date_gmt' => [1, ['log_date_gmt']],
            ])
        )->setTableEngine('actionscheduler_logs', 'InnoDB');
        $GLOBALS['wooSchedulerUnschedules'] = [];
        $GLOBALS['wooSchedulerCanceledActionIds'] = [];
        $GLOBALS['wooSchedulerDuringCancel'] = null;
        $GLOBALS['wooSchedulerAfterCancelLog'] = null;
        $GLOBALS['wooSchedulerTransactionIsolations'] = [];
        $GLOBALS['wooSchedulerCron'] = [];
        $GLOBALS['wooSchedulerCronWrites'] = 0;
        $GLOBALS['wooSchedulerOptionWrites'] = [];
        $GLOBALS['wooSchedulerOptionHooks'] = [];
        $GLOBALS['wooSchedulerOptionCacheEvents'] = 0;
        $GLOBALS['wooSchedulerCacheDeletes'] = [];
        $GLOBALS['wooSchedulerCacheResidue'] = [];
        $GLOBALS['wooSchedulerCacheDeleteFails'] = null;
        $GLOBALS['wooSchedulerExternalObjectCache'] = false;
        $GLOBALS['wooSchedulerDuringCronMutation'] = null;
        $GLOBALS['wooSchedulerDuringRetentionController'] = null;
        $GLOBALS['wooSchedulerCoreOptionCallbackCalls'] = [];
        $GLOBALS['wooSchedulerSynchronousAnalyticsRuns'] = 0;
        $GLOBALS['wooSchedulerBeforeActionInsert'] = null;
        $GLOBALS['wooSchedulerAfterOptionWrite'] = null;
        $GLOBALS['wooSchedulerFailPoint'] = null;
        $GLOBALS['wooSchedulerHookCrossings'] = [];
        $GLOBALS['wooSchedulerNativeHydrations'] = 0;
        $GLOBALS['wooSchedulerBeforeNativeHydration'] = null;
        $GLOBALS['wooSchedulerNativeActionOverride'] = null;
        WooSchedulerWakeupCanary::$wakeups = 0;
        $GLOBALS['wooSchedulerQueue'] = new WC_Action_Queue();
        Features::$enabled = true;
        OrdersScheduler::$interval = 43200;
        ActionScheduler::$initialized = true;
        ActionScheduler::reset();
        ActionScheduler_QueueRunner::reset();
        $GLOBALS['wp_filter'] = [];
        $GLOBALS['wooSchedulerContainer'] = new WooSchedulerContainer(
            new DataRetentionController(),
            new FeaturesController(),
            new CustomOrdersTableController(),
            new DataSynchronizer()
        );
        $customOrders = $GLOBALS['wooSchedulerContainer']->customOrders;
        $dataSynchronizer = $GLOBALS['wooSchedulerContainer']->dataSynchronizer;
        $featuresController = $GLOBALS['wooSchedulerContainer']->features;
        add_filter('pre_update_option', [$customOrders, 'process_pre_update_option'], 999, 3);
        add_filter('updated_option', [$dataSynchronizer, 'process_updated_option'], 999, 3);
        add_filter('updated_option', [$customOrders, 'process_updated_option'], 999, 3);
        add_filter('updated_option', [$customOrders, 'process_updated_option_fts_index'], 999, 3);
        add_filter('updated_option', [$featuresController, 'process_updated_option'], 999, 3);
        add_filter('added_option', [$dataSynchronizer, 'process_added_option'], 999, 2);
        add_filter('added_option', [$featuresController, 'process_added_option'], 999, 2);
        add_action('action_scheduler_stored_action', [ActionScheduler::logger(), 'log_stored_action'], 10, 1);
        add_action('action_scheduler_canceled_action', [ActionScheduler::logger(), 'log_canceled_action'], 10, 1);
        add_action('action_scheduler_failed_fetch_action', [ActionScheduler::logger(), 'log_failed_fetch_action'], 10, 2);
        add_filter('cron_schedules', [WC_Install::class, 'cron_schedules'], 10, 1);
        add_filter(
            'cron_schedules',
            [ActionScheduler_QueueRunner::instance(), 'add_wp_cron_schedule'],
            10,
            1
        );
        add_action(
            'add_option_woocommerce_analytics_scheduled_import',
            [OrdersScheduler::class, 'handle_scheduled_import_option_added'],
            10,
            2
        );
        add_action(
            'update_option_woocommerce_analytics_scheduled_import',
            [OrdersScheduler::class, 'handle_scheduled_import_option_change'],
            10,
            2
        );
        add_action(
            'delete_option',
            [OrdersScheduler::class, 'handle_scheduled_import_option_before_delete'],
            10,
            1
        );
        return $wpdb;
    }

    function woo_scheduler_provider(Policy $policy): \Duo\Providers\WoocommerceSchedulerSettings {
        return new \Duo\Providers\WoocommerceSchedulerSettings($policy);
    }

    function woo_scheduler_prepare_no_to_yes(Policy $policy): \Duo\Providers\WoocommerceSchedulerSettings {
        woo_scheduler_reset('no');
        $provider = woo_scheduler_provider($policy);
        $provider->invoke('reconcile_analytics_import_schedule', []);
        woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'yes');
        return $provider;
    }

    function woo_scheduler_claim_count(): int {
        return count($GLOBALS['wpdb']->rows('actionscheduler_claims'));
    }

    function woo_scheduler_log_count(): int {
        return count($GLOBALS['wpdb']->rows('actionscheduler_logs'));
    }

    /** @return array{before:mixed,filters:mixed} */
    function woo_scheduler_native_claim_state(): array {
        $store = ActionScheduler::store();
        $reflection = new ReflectionClass($store);
        $before = $reflection->getProperty('claim_before_date');
        $filters = $reflection->getProperty('claim_filters');
        return [
            'before' => $before->getValue($store),
            'filters' => $filters->getValue($store),
        ];
    }

    /** @param array<string,mixed> $filters */
    function woo_scheduler_set_native_claim_state(mixed $before, array $filters): void {
        $store = ActionScheduler::store();
        $reflection = new ReflectionClass($store);
        $reflection->getProperty('claim_before_date')->setValue($store, $before);
        $reflection->getProperty('claim_filters')->setValue($store, $filters);
    }

    function woo_scheduler_assert_storage_refusal(Policy $policy, string $label, callable $mutate): void {
        $db = woo_scheduler_reset('yes');
        $mutate($db);
        $provider = woo_scheduler_provider($policy);
        duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
            "$label removes analytics scheduling capability before mutation");
        duo_check_throws(
            static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
            RuntimeException::class,
            "$label is a loud exact-storage refusal",
            'scheduler'
        );
        duo_check(woo_scheduler_action_count('recurring') === 0
            && woo_scheduler_claim_count() === 0
            && woo_scheduler_option_value('_duo_woocommerce_scheduler_settings_state', false) === false,
            "$label refusal precedes marker, action, and claim mutation");
    }

    function woo_scheduler_action_count(string $kind): int {
        $count = 0;
        foreach (woo_scheduler_action_rows() as $row) {
            if (!in_array($row['status'], ['pending', 'in-progress'], true)) {
                continue;
            }
            $action = $row['_action'];
            $schedule = $action->get_schedule();
            if ($kind === 'recurring' && $schedule->is_recurring() && $action->get_args() === []) {
                $count++;
            } elseif ($kind === 'catchup' && !$schedule->is_recurring() && $action->get_args() === [null, null]) {
                $count++;
            } elseif ($kind === 'work' && !$schedule->is_recurring()
                && is_string($action->get_args()[0] ?? null)) {
                $count++;
            }
        }
        return $count;
    }

    function woo_scheduler_seed_cron(int $timestamp, array $args = [], string $schedule = 'daily', int $interval = DAY_IN_SECONDS): void {
        $GLOBALS['wooSchedulerCron'][$timestamp]['customer_stock_notifications_daily'][md5(serialize($args))] = [
            'schedule' => $schedule,
            'args' => $args,
            'interval' => $interval,
        ];
        woo_scheduler_sync_cron_option(false);
    }

    /** @param list<mixed> $args */
    function woo_scheduler_seed_unrelated_cron(int $timestamp, array $args = ['tenant-safe']): void {
        $GLOBALS['wooSchedulerCron'][$timestamp]['unrelated_product_job'][md5(serialize($args))] = [
            'schedule' => 'hourly',
            'args' => $args,
            'interval' => 3600,
        ];
        woo_scheduler_sync_cron_option(false);
    }

    function woo_scheduler_sync_cron_option(bool $native): void {
        $value = $GLOBALS['wooSchedulerCron'];
        $value['version'] = 2;
        $raw = serialize($value);
        if ($native) {
            // WordPress 7.0.3 `_set_cron_array()` calls update_option('cron',
            // $cron, true); retaining that exact autoload request is part of
            // the provider's whole-option transaction proof.
            update_option('cron', $raw, true);
        } else {
            woo_scheduler_put_option('cron', $raw, 'on');
        }
    }

    $root = dirname(__DIR__, 4);
    require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
    require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/FakeWpdb.php';
    require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/wp_stubs.php';
    require_once $root . '/agent/src/Kernel/Canon.php';
    require_once $root . '/agent/src/Kernel/PlainData.php';
    require_once $root . '/agent/src/Kernel/OptionState.php';
    require_once $root . '/agent/src/Policy/Policy.php';
    require_once $root . '/agent/src/Adapter/ProviderSdk.php';
    require_once $root . '/agent/src/Adapter/Providers.php';
    require_once $root . '/adapter-packages/woocommerce/package/runtime/providers/woocommerce-scheduler-settings.php';

    $policy = Policy::load(
        null,
        ['woocommerce'],
        false,
        null,
        \Duo\AdapterLibrary::fromSourcePackage($root, 'woocommerce')
    );
    $manifest = json_decode(
        (string) file_get_contents($root . '/adapter-packages/woocommerce/package/manifest.json'),
        true,
        flags: JSON_THROW_ON_ERROR
    );
    woo_scheduler_reset();
    $provider = woo_scheduler_provider($policy);

    duo_check_same([
        'id' => 'woocommerce-scheduler-settings',
        'plugin' => 'woocommerce/woocommerce.php',
        'version' => '1.0.0',
    ], $provider->identity(), 'scheduler-settings provider identity is exact and manifest-bindable');
    $providerDeclaration = null;
    foreach ((array) ($manifest['providers'] ?? []) as $declaration) {
        if (($declaration['id'] ?? null) === 'woocommerce-scheduler-settings') {
            $providerDeclaration = $declaration;
            break;
        }
    }
    duo_check_same(
        ['reconcile_analytics_import_schedule', 'reconcile_stock_notification_retention'],
        $providerDeclaration['capabilities'] ?? null,
        'the shipped manifest binds both scheduler capabilities to this exact provider file'
    );
    foreach ([
        'woocommerce_analytics_scheduled_import' => 'authored',
        'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold' => 'authored',
        '_duo_woocommerce_scheduler_settings_state' => 'runtime',
        'woocommerce_admin_scheduler_last_processed_order_id' => 'runtime',
        'woocommerce_admin_scheduler_last_processed_order_modified_date' => 'runtime',
    ] as $option => $class) {
        duo_check_same($class, $policy->option_rule($option)['class'] ?? null,
            "$option has its exact scheduler ownership classification");
    }
    $initialCapabilities = $provider->capabilities();
    duo_check_same(
        ['reconcile_analytics_import_schedule', 'reconcile_stock_notification_retention'],
        array_keys($initialCapabilities),
        'both exact native scheduler capabilities negotiate on a ready Woo target'
    );
    duo_check(in_array(
        'table:actionscheduler_claims',
        $initialCapabilities['reconcile_analytics_import_schedule']['writes'],
        true
    ), 'analytics capability declares the native claim table that every invocation mutates');

    $schedulerActions = [];
    foreach ((array) ($manifest['actions'] ?? []) as $action) {
        if (($action['provider'] ?? null) === 'woocommerce-scheduler-settings') {
            $schedulerActions[(string) $action['capability']] = $action;
        }
    }
    duo_check_same(
        ['reconcile_analytics_import_schedule', 'reconcile_stock_notification_retention'],
        array_keys($schedulerActions),
        'the manifest contains exactly the two scheduler post-commit actions'
    );
    duo_check_same(
        ['option:woocommerce_analytics_scheduled_import'],
        $schedulerActions['reconcile_analytics_import_schedule']['triggers'] ?? null,
        'analytics native scheduling is selected only by its authored setting'
    );
    duo_check_same(
        ['option:woocommerce_customer_stock_notifications_unverified_deletions_days_threshold'],
        $schedulerActions['reconcile_stock_notification_retention']['triggers'] ?? null,
        'stock-retention cron repair is selected only by its authored threshold'
    );
    foreach ($schedulerActions as $capability => $action) {
        $declaredDatabaseWrites = [];
        foreach ((array) ($action['effects'] ?? []) as $effect) {
            if (($effect['kind'] ?? null) !== 'database') {
                continue;
            }
            $selector = (array) ($effect['selector'] ?? []);
            $surface = (string) ($selector['type'] ?? '')
                . ':' . (string) ($selector['value'] ?? '');
            $declaredDatabaseWrites[] = $surface === 'option:_duo_woocommerce_scheduler_settings_state'
                ? 'entity:woocommerce-analytics-scheduler-marker'
                : $surface;
        }
        sort($declaredDatabaseWrites, SORT_STRING);
        $capabilityWrites = $initialCapabilities[$capability]['writes'];
        sort($capabilityWrites, SORT_STRING);
        duo_check_same($capabilityWrites, $declaredDatabaseWrites,
            "$capability action checkpoints every database surface its capability advertises");
    }
    duo_check_same(
        ['reconcile_analytics_import_schedule'],
        array_column($policy->actions_for(['option:woocommerce_analytics_scheduled_import']), 'capability'),
        'policy trigger projection selects only analytics repair for an analytics option apply'
    );
    duo_check_same(
        ['reconcile_stock_notification_retention'],
        array_column($policy->actions_for([
            'option:woocommerce_customer_stock_notifications_unverified_deletions_days_threshold',
        ]), 'capability'),
        'policy trigger projection selects only retention repair for a retention option apply'
    );

    $initialNo = $provider->invoke_scoped(
        'reconcile_analytics_import_schedule', [], woo_scheduler_operation(1)
    );
    duo_check_same(0, woo_scheduler_action_count('recurring'),
        'initial adoption of immediate analytics mode does not invent a recurring action');
    duo_check_same(0, woo_scheduler_action_count('catchup'),
        'initial adoption of already-immediate mode does not replay a transitional catch-up');
    duo_check(str_contains((string) get_option('_duo_woocommerce_scheduler_settings_state'), '"state":"no"'),
        'initial adoption durably records a verified immediate-mode marker');
    duo_check_same('off', woo_scheduler_option_autoload('_duo_woocommerce_scheduler_settings_state'),
        'Duo-owned analytics markers use the exact non-autoloaded platform wire');
    duo_check_same('off', $initialNo['after']['marker_autoload'] ?? null,
        'analytics recovery evidence binds the Duo marker autoload bytes');
    $writesAfterInitial = count($GLOBALS['wooSchedulerOptionWrites']);
    $reconciledNo = $provider->reconcile_scoped(
        'reconcile_analytics_import_schedule', [], woo_scheduler_operation(1)
    );
    duo_check_same($initialNo['after'], $reconciledNo['after'],
        'analytics recovery is a read-only reproduction of the stored stable projection');
    duo_check_same($writesAfterInitial, count($GLOBALS['wooSchedulerOptionWrites']),
        'analytics reconcile performs no marker, cursor, or schedule mutation');
    $againNo = $provider->invoke_scoped(
        'reconcile_analytics_import_schedule', [], woo_scheduler_operation(2)
    );
    duo_check_same($initialNo['after'], $againNo['after'],
        'repeated immediate-mode invocation is semantically idempotent');
    duo_check_same($writesAfterInitial, count($GLOBALS['wooSchedulerOptionWrites']),
        'an already-stable analytics invocation fires no option hooks again');

    woo_scheduler_reset('yes');
    woo_scheduler_put_option('woocommerce_admin_scheduler_last_processed_order_modified_date', '2025-01-02 03:04:05');
    woo_scheduler_put_option('woocommerce_admin_scheduler_last_processed_order_id', '9876543210');
    $provider = woo_scheduler_provider($policy);
    $adoptYes = $provider->invoke_scoped(
        'reconcile_analytics_import_schedule', [], woo_scheduler_operation(3)
    );
    duo_check_same(1, woo_scheduler_action_count('recurring'),
        'initial scheduled-mode adoption creates one exact native recurring action');
    duo_check_same('2025-01-02 03:04:05', get_option('woocommerce_admin_scheduler_last_processed_order_modified_date'),
        'initial scheduled-mode adoption conservatively preserves an existing date cursor');
    duo_check_same('9876543210', get_option('woocommerce_admin_scheduler_last_processed_order_id'),
        'initial scheduled-mode adoption conservatively preserves an existing ID cursor');

    woo_scheduler_reset('no');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(4));
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'yes');
    woo_scheduler_put_option('woocommerce_admin_scheduler_last_processed_order_modified_date', '2024-01-01 00:00:00');
    woo_scheduler_put_option('woocommerce_admin_scheduler_last_processed_order_id', '123');
    $transitionYes = $provider->invoke_scoped(
        'reconcile_analytics_import_schedule', [], woo_scheduler_operation(5)
    );
    duo_check_same('0', get_option('woocommerce_admin_scheduler_last_processed_order_id'),
        'proved no-to-yes transition resets the compound cursor ID exactly once');
    duo_check((string) get_option('woocommerce_admin_scheduler_last_processed_order_modified_date')
        !== '2024-01-01 00:00:00',
        'proved no-to-yes transition resets the date cursor through the reviewed native operation');
    duo_check_same(1, woo_scheduler_action_count('recurring'),
        'proved no-to-yes transition converges to exactly one recurring action');
    duo_check_same(0, woo_scheduler_action_count('catchup'),
        'proved no-to-yes transition leaves no stale catch-up action');

    woo_scheduler_reset('no');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_analytics_import_schedule', []);
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'yes');
    $provider->invoke('reconcile_analytics_import_schedule', []);
    duo_check_same('auto', woo_scheduler_option_autoload(
        'woocommerce_admin_scheduler_last_processed_order_modified_date'
    ), 'a missing date cursor uses the exact native automatic-autoload wire');
    duo_check_same('auto', woo_scheduler_option_autoload(
        'woocommerce_admin_scheduler_last_processed_order_id'
    ), 'a missing ID cursor uses the exact native automatic-autoload wire');

    woo_scheduler_reset('no');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_analytics_import_schedule', []);
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'yes');
    woo_scheduler_put_option(
        'woocommerce_admin_scheduler_last_processed_order_modified_date',
        '2024-06-07 08:09:10',
        'auto-off'
    );
    woo_scheduler_put_option('woocommerce_admin_scheduler_last_processed_order_id', '41', 'off');
    $provider->invoke('reconcile_analytics_import_schedule', []);
    duo_check_same('auto-off', woo_scheduler_option_autoload(
        'woocommerce_admin_scheduler_last_processed_order_modified_date'
    ), 'native cursor reset preserves an existing automatic-autoload preimage');
    duo_check_same('off', woo_scheduler_option_autoload(
        'woocommerce_admin_scheduler_last_processed_order_id'
    ), 'native cursor reset preserves an existing explicit-autoload preimage');

    foreach (['marker_intent', 'cursor_date', 'cursor_id'] as $index => $failurePoint) {
        woo_scheduler_reset('no');
        $provider = woo_scheduler_provider($policy);
        $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(10 + $index * 2));
        woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'yes');
        woo_scheduler_put_option('woocommerce_admin_scheduler_last_processed_order_modified_date', '2023-05-06 07:08:09');
        woo_scheduler_put_option('woocommerce_admin_scheduler_last_processed_order_id', '44');
        $GLOBALS['wooSchedulerFailPoint'] = $failurePoint;
        $crashedOperation = woo_scheduler_operation(11 + $index * 2);
        duo_check_throws(
            static fn() => $provider->invoke_scoped(
                'reconcile_analytics_import_schedule', [], $crashedOperation
            ),
            RuntimeException::class,
            "$failurePoint crash leaves a loud durable transition state",
            'injected Woo scheduler failure'
        );
        duo_check_throws(
            static fn() => $provider->invoke_scoped(
                'reconcile_analytics_import_schedule', [], woo_scheduler_operation(12 + $index * 2)
            ),
            RuntimeException::class,
            "$failurePoint residual intent cannot be adopted by a later operation",
            'belongs to another operation'
        );
        $retry = $provider->invoke_scoped(
            'reconcile_analytics_import_schedule', [], $crashedOperation
        );
        duo_check(($retry['verified'] ?? false) === true
            && woo_scheduler_action_count('recurring') === 1
            && get_option('woocommerce_admin_scheduler_last_processed_order_id') === '0',
            "$failurePoint residual state is resumable only by its exact originating operation");
    }

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(20));
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'no');
    $GLOBALS['wooSchedulerFailPoint'] = 'unschedule';
    duo_check_throws(
        static fn() => $provider->invoke_scoped(
            'reconcile_analytics_import_schedule', [], woo_scheduler_operation(21)
        ),
        RuntimeException::class,
        'crash after exact recurring unschedule leaves a loud transition intent',
        'injected Woo scheduler failure'
    );
    duo_check_same(1, woo_scheduler_action_count('recurring'),
        'unschedule callback failure rolls exact cancellation and its log back atomically');
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(21));
    duo_check_same(1, woo_scheduler_action_count('catchup'),
        'a new operation resumes the post-unschedule phase with one catch-up action');
    $canceledClaimRows = array_values(array_filter(
        $GLOBALS['wpdb']->rows('actionscheduler_actions'),
        static fn(array $row): bool => ($row['status'] ?? null) === 'canceled'
            && (int) ($row['claim_id'] ?? 0) > 0
    ));
    duo_check(count($canceledClaimRows) === 1 && woo_scheduler_claim_count() === 0,
        'native canceled rows retain their historical claim ID while the claim row is exactly released');

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(23));
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'no');
    $GLOBALS['wooSchedulerFailPoint'] = 'schedule';
    duo_check_throws(
        static fn() => $provider->invoke_scoped(
            'reconcile_analytics_import_schedule', [], woo_scheduler_operation(24)
        ),
        RuntimeException::class,
        'crash after catch-up scheduling leaves a loud transition intent',
        'injected Woo scheduler failure'
    );
    duo_check_same(0, woo_scheduler_action_count('catchup'),
        'schedule callback failure rolls the native catch-up action and log back atomically');
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(24));
    duo_check_same(1, woo_scheduler_action_count('catchup'),
        'retry after a schedule crash finalizes without duplicating catch-up work');

    $provider = woo_scheduler_prepare_no_to_yes($policy);
    $commitApplied = false;
    $GLOBALS['wpdb']->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$commitApplied): null|string {
        if (!$commitApplied && $method === 'query' && trim($sql) === 'COMMIT') {
            $commitApplied = true;
            $db->query('COMMIT');
            return 'simulated lost COMMIT acknowledgement';
        }
        return null;
    });
    $provider->invoke('reconcile_analytics_import_schedule', []);
    duo_check($commitApplied && woo_scheduler_action_count('recurring') === 1,
        'an applied COMMIT with a lost acknowledgement is accepted only by exact native readback');
    duo_check_same(0, woo_scheduler_claim_count(),
        'applied-but-unacknowledged COMMIT releases its promoted native claim');
    duo_check_same(1, woo_scheduler_log_count(),
        'applied-but-unacknowledged COMMIT retains exactly one native action log');

    $provider = woo_scheduler_prepare_no_to_yes($policy);
    $GLOBALS['wpdb']->failNextQuery('simulated COMMIT not applied', 'COMMIT');
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'a failed COMMIT that did not apply rolls the transaction back loudly',
        'provider checked mutation failed'
    );
    duo_check(woo_scheduler_action_count('recurring') === 0
        && woo_scheduler_claim_count() === 0
        && woo_scheduler_log_count() === 0,
        'COMMIT-not-applied leaves no action, claim, or log residue');
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'ordinary invocation never trusts an attacker-computable residual intent',
        'unscoped invocation cannot adopt'
    );
    woo_scheduler_delete_option('_duo_woocommerce_scheduler_settings_state');
    $provider->invoke('reconcile_analytics_import_schedule', []);
    duo_check_same(1, woo_scheduler_action_count('recurring'),
        'manual intent clearance permits conservative retry to one native action');

    $provider = woo_scheduler_prepare_no_to_yes($policy);
    $commitRolledBack = false;
    $GLOBALS['wpdb']->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$commitRolledBack): null|string {
        if (!$commitRolledBack && $method === 'query' && trim($sql) === 'COMMIT') {
            $commitRolledBack = true;
            $db->query('ROLLBACK');
            return 'simulated server rollback with lost COMMIT acknowledgement';
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'inactive COMMIT failure recognizes the exact rolled-back scheduler preimage',
        'provider checked mutation failed'
    );
    duo_check($commitRolledBack
        && woo_scheduler_action_count('recurring') === 0
        && woo_scheduler_claim_count() === 0
        && woo_scheduler_log_count() === 0,
        'server-rolled-back COMMIT is safely retryable without action, claim, or log residue');

    $provider = woo_scheduler_prepare_no_to_yes($policy);
    $startApplied = false;
    $GLOBALS['wpdb']->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$startApplied): null|string {
        if (!$startApplied && $method === 'query' && trim($sql) === 'START TRANSACTION') {
            $startApplied = true;
            $db->query('START TRANSACTION');
            return 'simulated lost START acknowledgement';
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'an applied START with a lost acknowledgement is rolled back before refusal',
        'provider checked mutation failed'
    );
    duo_check_same('0', $GLOBALS['wpdb']->get_var('SELECT @@in_transaction'),
        'lost START acknowledgement cleanup leaves no live transaction');
    duo_check(woo_scheduler_action_count('recurring') === 0 && woo_scheduler_claim_count() === 0,
        'lost START acknowledgement leaves no native action or claim');

    $provider = woo_scheduler_prepare_no_to_yes($policy);
    $GLOBALS['wpdb']->failNextQuery('simulated START not applied', 'START TRANSACTION');
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'a START failure that did not apply is refused after a safe blind rollback',
        'provider checked mutation failed'
    );
    duo_check_same('0', $GLOBALS['wpdb']->get_var('SELECT @@in_transaction'),
        'START-not-applied cleanup also proves transaction inactivity');

    foreach (['foreign-next-read-committed', 'session-read-committed'] as $isolationCase) {
        woo_scheduler_reset('yes');
        woo_scheduler_add_action('recurring', [], 43200);
        if ($isolationCase === 'foreign-next-read-committed') {
            $GLOBALS['wpdb']->setTransactionIsolation('REPEATABLE-READ')
                ->setNextTransactionIsolation('READ-COMMITTED');
        } else {
            $GLOBALS['wpdb']->setTransactionIsolation('READ-COMMITTED');
        }
        $provider = woo_scheduler_provider($policy);
        $provider->invoke('reconcile_analytics_import_schedule', []);
        duo_check($GLOBALS['wooSchedulerTransactionIsolations'] !== []
            && array_unique($GLOBALS['wooSchedulerTransactionIsolations']) === ['REPEATABLE-READ'],
            "$isolationCase is replaced by the provider's own one-shot REPEATABLE READ");
    }

    woo_scheduler_reset('yes');
    woo_scheduler_add_action('recurring', [], 43200);
    $provider = woo_scheduler_provider($policy);
    $GLOBALS['wpdb']->failNextQuery('simulated isolation SET failure', 'SET TRANSACTION');
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'a failed isolation SET refuses before native claim mutation',
        'provider checked mutation failed'
    );
    duo_check(woo_scheduler_claim_count() === 0
        && $GLOBALS['wpdb']->get_var('SELECT @@in_transaction') === '0',
        'failed isolation SET cleanup leaves no claim or live transaction');

    woo_scheduler_reset('yes');
    woo_scheduler_add_action('recurring', [], 43200);
    $provider = woo_scheduler_provider($policy);
    $lostIsolationAck = false;
    $GLOBALS['wpdb']->onQuery(static function (
        string $sql,
        string $method,
        FakeWpdb $db
    ) use (&$lostIsolationAck): null|string {
        if (!$lostIsolationAck && $method === 'query'
            && trim($sql) === 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ') {
            $lostIsolationAck = true;
            $db->query($sql);
            return 'simulated lost isolation acknowledgement';
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'an applied isolation SET with lost acknowledgement is consumed before refusal',
        'provider checked mutation failed'
    );
    duo_check($lostIsolationAck
        && woo_scheduler_claim_count() === 0
        && $GLOBALS['wpdb']->get_var('SELECT @@in_transaction') === '0',
        'lost isolation acknowledgement leaves neither pending work nor an active transaction');

    woo_scheduler_reset('yes');
    woo_scheduler_add_action('recurring', [], 43200);
    $GLOBALS['wpdb']->setServerVersion('11.4.2-MariaDB');
    $primaryIsolationReads = 0;
    $fallbackIsolationReads = 0;
    $GLOBALS['wpdb']->onQuery(static function (string $sql) use (
        &$primaryIsolationReads,
        &$fallbackIsolationReads
    ): null|string {
        if (trim($sql) === 'SELECT @@transaction_isolation') {
            $primaryIsolationReads++;
            return 'simulated MariaDB primary isolation variable absence';
        }
        if (trim($sql) === 'SELECT @@tx_isolation') {
            $fallbackIsolationReads++;
        }
        return null;
    });
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_analytics_import_schedule', []);
    duo_check($primaryIsolationReads > 0 && $fallbackIsolationReads === $primaryIsolationReads,
        'MariaDB transaction isolation falls back exactly to @@tx_isolation');

    woo_scheduler_reset('yes');
    woo_scheduler_add_action('recurring', [], 43200);
    $provider = woo_scheduler_provider($policy);
    $GLOBALS['wpdb']->onQuery(static function (string $sql): null|string {
        if (in_array(trim($sql), ['SELECT @@transaction_isolation', 'SELECT @@tx_isolation'], true)) {
            return 'simulated isolation probe failure';
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'unreadable isolation variables roll back before native claim mutation',
        'could not read the native transaction-isolation variable'
    );
    duo_check(woo_scheduler_claim_count() === 0
        && $GLOBALS['wpdb']->get_var('SELECT @@in_transaction') === '0',
        'isolation probe failure leaves no transaction or claim residue');

    woo_scheduler_reset('yes');
    woo_scheduler_add_action('recurring', [], 43200);
    $provider = woo_scheduler_provider($policy);
    $reconnectedDuringStart = false;
    $GLOBALS['wpdb']->onQuery(static function (
        string $sql,
        string $method,
        FakeWpdb $db
    ) use (&$reconnectedDuringStart): null {
        if (!$reconnectedDuringStart && $method === 'query' && trim($sql) === 'START TRANSACTION') {
            $reconnectedDuringStart = true;
            $db->setConnectionId(99);
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'reconnect during START refuses before any locked scheduler read or mutation',
        'mutex'
    );
    duo_check($reconnectedDuringStart
        && woo_scheduler_claim_count() === 0
        && $GLOBALS['wpdb']->get_var('SELECT @@in_transaction') === '0',
        'reconnect-during-START cleanup leaves no transaction or claim residue');

    woo_scheduler_reset('yes');
    woo_scheduler_add_action('recurring', [], 43200);
    $provider = woo_scheduler_provider($policy);
    $reconnectedAtomicWitness = false;
    $GLOBALS['wpdb']->onQuery(static function (
        string $sql,
        string $method,
        FakeWpdb $db
    ) use (&$reconnectedAtomicWitness): null {
        if (!$reconnectedAtomicWitness
            && $method === 'get_results'
            && str_contains($sql, 'CONNECTION_ID() AS connection_id')
            && $db->activeTransactionIsolation() !== null) {
            $reconnectedAtomicWitness = true;
            $db->setConnectionId(101);
            $db->query('START TRANSACTION');
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'the atomic session witness rejects a replacement session that reports an active transaction',
        'mutex'
    );
    duo_check($reconnectedAtomicWitness
        && woo_scheduler_claim_count() === 0
        && $GLOBALS['wpdb']->get_var('SELECT @@in_transaction') === '0',
        'replacement-session cleanup rolls back the crafted active transaction without a native claim');

    $provider = woo_scheduler_prepare_no_to_yes($policy);
    $GLOBALS['wooSchedulerFailPoint'] = 'schedule';
    $rollbackApplied = false;
    $GLOBALS['wpdb']->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$rollbackApplied): null|string {
        if (!$rollbackApplied && $method === 'query' && trim($sql) === 'ROLLBACK') {
            $rollbackApplied = true;
            $db->query('ROLLBACK');
            return 'simulated lost ROLLBACK acknowledgement';
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'an applied ROLLBACK with a lost acknowledgement preserves the work failure',
        'injected Woo scheduler failure at schedule'
    );
    duo_check($rollbackApplied && woo_scheduler_action_count('recurring') === 0
        && woo_scheduler_claim_count() === 0
        && woo_scheduler_log_count() === 0,
        'applied-but-unacknowledged ROLLBACK removes every transactional row');

    $provider = woo_scheduler_prepare_no_to_yes($policy);
    $GLOBALS['wooSchedulerFailPoint'] = 'schedule';
    $rollbackAttempts = 0;
    $GLOBALS['wpdb']->onQuery(static function (string $sql, string $method) use (&$rollbackAttempts): null|string {
        if ($method === 'query' && trim($sql) === 'ROLLBACK' && ++$rollbackAttempts === 1) {
            return 'simulated ROLLBACK not applied';
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'a non-applied ROLLBACK retries without masking the work failure',
        'injected Woo scheduler failure at schedule'
    );
    duo_check($rollbackAttempts === 2
        && woo_scheduler_action_count('recurring') === 0
        && woo_scheduler_claim_count() === 0,
        'ROLLBACK retry converges without transactional claim residue');

    $provider = woo_scheduler_prepare_no_to_yes($policy);
    $GLOBALS['wpdb']->failNextQuery('simulated committed log read failure', 'LENGTH(message) AS message_bytes');
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'a post-COMMIT action-log read failure remains loud',
        'provider checked read failed'
    );
    duo_check(woo_scheduler_action_count('recurring') === 1 && woo_scheduler_claim_count() === 0,
        'post-COMMIT log proof failure releases the promoted claim without deleting the action');
    woo_scheduler_delete_option('_duo_woocommerce_scheduler_settings_state');
    $provider->invoke('reconcile_analytics_import_schedule', []);
    duo_check_same(1, woo_scheduler_action_count('recurring'),
        'manual recovery after committed log-proof failure never duplicates native work');

    $provider = woo_scheduler_prepare_no_to_yes($policy);
    $afterCommit = false;
    $failedFenceRead = false;
    $GLOBALS['wpdb']->onQuery(static function (string $sql, string $method) use (&$afterCommit, &$failedFenceRead): null|string {
        if ($method === 'query' && trim($sql) === 'COMMIT') {
            $afterCommit = true;
        } elseif ($afterCommit && !$failedFenceRead && str_contains($sql, 'FROM `wp_actionscheduler_claims`')) {
            $failedFenceRead = true;
            return 'simulated committed fence read failure';
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'a committed native-fence read failure remains loud',
        'provider checked read failed'
    );
    duo_check($failedFenceRead && woo_scheduler_claim_count() === 0,
        'committed fence-read failure still exhaustively releases the promoted claim');

    $provider = woo_scheduler_prepare_no_to_yes($policy);
    $lostCommit = false;
    $failedLostCommitProof = false;
    $GLOBALS['wpdb']->onQuery(static function (
        string $sql,
        string $method,
        FakeWpdb $db
    ) use (&$lostCommit, &$failedLostCommitProof): null|string {
        if (!$lostCommit && $method === 'query' && trim($sql) === 'COMMIT') {
            $lostCommit = true;
            $db->query('COMMIT');
            return 'simulated applied COMMIT with lost acknowledgement';
        }
        if ($lostCommit && !$failedLostCommitProof && str_contains($sql, 'FROM `wp_actionscheduler_claims`')) {
            $failedLostCommitProof = true;
            return 'simulated ambiguous COMMIT proof failure';
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'applied-COMMIT proof failure remains loud after claim promotion',
        'provider checked read failed'
    );
    duo_check($lostCommit && $failedLostCommitProof && woo_scheduler_claim_count() === 0,
        'applied-COMMIT proof failure cannot strand the newly committed native claim');

    foreach (['truthy-no-apply', 'probe-throw', 'reconnect'] as $commitMode) {
        woo_scheduler_reset('yes');
        woo_scheduler_add_action('recurring', [], 43200);
        $provider = woo_scheduler_provider($policy);
        if ($commitMode === 'truthy-no-apply') {
            $GLOBALS['wpdb']->acknowledgeNextQueryWithoutExecution('COMMIT');
        } else {
            $afterCommit = false;
            $injected = false;
            $GLOBALS['wpdb']->onQuery(static function (
                string $sql,
                string $method,
                FakeWpdb $db
            ) use ($commitMode, &$afterCommit, &$injected): null|string {
                if (!$injected && $method === 'query' && trim($sql) === 'COMMIT') {
                    if ($commitMode === 'reconnect') {
                        $injected = true;
                        $db->setConnectionId(99);
                    } else {
                        $afterCommit = true;
                    }
                } elseif ($commitMode === 'probe-throw'
                    && $afterCommit
                    && !$injected
                    && str_contains($sql, '@@in_transaction AS in_transaction')) {
                    $injected = true;
                    return 'simulated initial-claim post-COMMIT probe failure';
                }
                return null;
            });
        }
        duo_check_throws(
            static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
            RuntimeException::class,
            "$commitMode initial-claim COMMIT frontier remains loud",
            $commitMode === 'probe-throw' ? 'provider checked read failed' : 'duo:'
        );
        duo_check(woo_scheduler_action_count('recurring') === 1
            && woo_scheduler_claim_count() === 0
            && (int) ($GLOBALS['wpdb']->rows('actionscheduler_actions')[0]['claim_id'] ?? -1) === 0
            && $GLOBALS['wpdb']->get_var('SELECT @@in_transaction') === '0',
            "$commitMode initial-claim frontier strands no transaction or claim");
    }

    foreach (['truthy-no-apply', 'probe-throw', 'reconnect'] as $commitMode) {
        $provider = woo_scheduler_prepare_no_to_yes($policy);
        if ($commitMode === 'truthy-no-apply') {
            $GLOBALS['wpdb']->acknowledgeNextQueryWithoutExecution('COMMIT');
        } else {
            $afterCommit = false;
            $injected = false;
            $GLOBALS['wpdb']->onQuery(static function (
                string $sql,
                string $method,
                FakeWpdb $db
            ) use ($commitMode, &$afterCommit, &$injected): null|string {
                if (!$injected && $method === 'query' && trim($sql) === 'COMMIT') {
                    if ($commitMode === 'reconnect') {
                        $injected = true;
                        $db->setConnectionId(99);
                    } else {
                        $afterCommit = true;
                    }
                } elseif ($commitMode === 'probe-throw'
                    && $afterCommit
                    && !$injected
                    && str_contains($sql, '@@in_transaction AS in_transaction')) {
                    $injected = true;
                    return 'simulated schedule post-COMMIT probe failure';
                }
                return null;
            });
        }
        duo_check_throws(
            static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
            RuntimeException::class,
            "$commitMode new-schedule COMMIT frontier remains loud",
            $commitMode === 'probe-throw' ? 'provider checked read failed' : 'duo:'
        );
        $expectedActions = $commitMode === 'probe-throw' ? 1 : 0;
        duo_check(woo_scheduler_action_count('recurring') === $expectedActions
            && woo_scheduler_claim_count() === 0
            && $GLOBALS['wpdb']->get_var('SELECT @@in_transaction') === '0',
            "$commitMode new-schedule frontier has an exact inactive, unclaimed outcome");
    }

    foreach (['claim_before', 'claim_after'] as $claimFailurePoint) {
        woo_scheduler_reset('yes');
        woo_scheduler_add_action('recurring', [], 43200);
        $provider = woo_scheduler_provider($policy);
        $GLOBALS['wooSchedulerFailPoint'] = $claimFailurePoint;
        duo_check_throws(
            static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
            RuntimeException::class,
            "$claimFailurePoint failure rolls back the initial native claim transaction",
            'injected Woo scheduler failure'
        );
        duo_check(woo_scheduler_claim_count() === 0
            && woo_scheduler_action_count('recurring') === 1
            && (int) ($GLOBALS['wpdb']->rows('actionscheduler_actions')[0]['claim_id'] ?? -1) === 0,
            "$claimFailurePoint leaves the pre-existing action pending and unclaimed");
    }

    $provider = woo_scheduler_prepare_no_to_yes($policy);
    $GLOBALS['wooSchedulerFailPoint'] = 'schedule_before_claim';
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'failure after a new action row but before its claim rolls the whole transaction back',
        'injected Woo scheduler failure'
    );
    duo_check(woo_scheduler_action_count('recurring') === 0
        && woo_scheduler_claim_count() === 0
        && woo_scheduler_log_count() === 0,
        'new-row-before-claim failure cannot leave an unowned runnable action');

    foreach (['claim_release_before', 'claim_release_partial', 'claim_release'] as $releaseFailurePoint) {
        $provider = woo_scheduler_prepare_no_to_yes($policy);
        $GLOBALS['wooSchedulerFailPoint'] = $releaseFailurePoint;
        $provider->invoke('reconcile_analytics_import_schedule', []);
        duo_check(woo_scheduler_action_count('recurring') === 1
            && woo_scheduler_claim_count() === 0
            && (int) ($GLOBALS['wpdb']->rows('actionscheduler_actions')[0]['claim_id'] ?? -1) === 0,
            "$releaseFailurePoint is retried through exact native claim release readback");
    }

    woo_scheduler_reset('yes');
    $existingAction = woo_scheduler_add_action('recurring', [], 43200);
    $GLOBALS['wpdb']->update('actionscheduler_actions', [
        'last_attempt_gmt' => '2025-07-08 09:10:11',
        'last_attempt_local' => '2025-07-08 09:10:12',
    ], ['action_id' => $existingAction]);
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_analytics_import_schedule', []);
    $attemptProjection = array_values(array_filter(
        $GLOBALS['wpdb']->rows('actionscheduler_actions'),
        static fn(array $row): bool => (int) ($row['action_id'] ?? 0) === $existingAction
    ))[0] ?? [];
    duo_check_same(
        ['2025-07-08 09:10:11', '2025-07-08 09:10:12'],
        [$attemptProjection['last_attempt_gmt'] ?? null, $attemptProjection['last_attempt_local'] ?? null],
        'native claim fencing restores exact pre-existing last-attempt runtime bytes'
    );
    $provider->invoke('reconcile_analytics_import_schedule', []);
    $attemptProjection = array_values(array_filter(
        $GLOBALS['wpdb']->rows('actionscheduler_actions'),
        static fn(array $row): bool => (int) ($row['action_id'] ?? 0) === $existingAction
    ))[0] ?? [];
    duo_check_same(
        ['2025-07-08 09:10:11', '2025-07-08 09:10:12'],
        [$attemptProjection['last_attempt_gmt'] ?? null, $attemptProjection['last_attempt_local'] ?? null],
        'repeated stable reconciliation does not churn Action Scheduler attempt state'
    );

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_analytics_import_schedule', []);
    $createdRow = array_values(array_filter(
        $GLOBALS['wpdb']->rows('actionscheduler_actions'),
        static fn(array $row): bool => ($row['status'] ?? null) === 'pending'
    ))[0] ?? [];
    duo_check_same(
        ['0000-00-00 00:00:00', '0000-00-00 00:00:00'],
        [$createdRow['last_attempt_gmt'] ?? null, $createdRow['last_attempt_local'] ?? null],
        'transaction-fencing a newly scheduled action restores its untouched attempt preimage'
    );

    woo_scheduler_reset('yes');
    woo_scheduler_add_action('recurring', [], 43200);
    $preservedClaimState = [
        'before' => null,
        'filters' => ['group' => '', 'hooks' => '', 'exclude-groups' => ['external-runtime-group']],
    ];
    woo_scheduler_set_native_claim_state(
        $preservedClaimState['before'],
        $preservedClaimState['filters']
    );
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_analytics_import_schedule', []);
    duo_check_same($preservedClaimState, woo_scheduler_native_claim_state(),
        'provider claims restore the exact pre-existing DBStore singleton filters');
    $unrelatedId = woo_scheduler_add_action(
        'single',
        [],
        null,
        'pending',
        'unrelated-runtime-group',
        'unrelated-runtime-hook'
    );
    $nativeClaim = ActionScheduler::store()->stake_claim(
        16,
        new DateTime('@' . (time() + 604800)),
        [],
        ''
    );
    duo_check(in_array($unrelatedId, $nativeClaim->get_actions(), true),
        'a same-process native runner remains able to claim unrelated work after Duo');
    ActionScheduler::store()->release_claim($nativeClaim);

    woo_scheduler_reset('yes');
    woo_scheduler_add_action('recurring', [], 43200);
    $provider = woo_scheduler_provider($policy);
    $GLOBALS['wooSchedulerFailPoint'] = 'claim_after';
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'a throwing native claim still restores its same-process singleton state',
        'injected Woo scheduler failure'
    );
    duo_check_same([
        'before' => null,
        'filters' => ['group' => '', 'hooks' => '', 'exclude-groups' => ''],
    ], woo_scheduler_native_claim_state(),
        'throwing claim cleanup restores claim_before_date and every native filter');

    foreach ([
        'active claim-before date' => [new DateTime('@' . time()), [
            'group' => '', 'hooks' => '', 'exclude-groups' => '',
        ]],
        'unknown claim filter' => [null, [
            'group' => '', 'hooks' => '', 'exclude-groups' => '', 'extension' => '',
        ]],
    ] as $label => [$beforeDate, $filters]) {
        woo_scheduler_reset('yes');
        woo_scheduler_add_action('recurring', [], 43200);
        woo_scheduler_set_native_claim_state($beforeDate, $filters);
        $provider = woo_scheduler_provider($policy);
        duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
            "$label removes analytics capability before native claim mutation");
    }

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(26));
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'no');
    $GLOBALS['wooSchedulerFailPoint'] = 'final_marker_before';
    duo_check_throws(
        static fn() => $provider->invoke_scoped(
            'reconcile_analytics_import_schedule', [], woo_scheduler_operation(27)
        ),
        RuntimeException::class,
        'failure before final-marker persistence leaves completed external state under intent',
        'injected Woo scheduler failure'
    );
    duo_check(str_contains((string) get_option('_duo_woocommerce_scheduler_settings_state'), '"phase":"intent"'),
        'the pre-final-marker crash retains the durable intent rather than forging verification');
    duo_check_same(0, woo_scheduler_claim_count(),
        'failure before terminal marker persistence still releases every native claim');
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(27));
    duo_check(str_contains((string) get_option('_duo_woocommerce_scheduler_settings_state'), '"phase":"verified"'),
        'a new operation recognizes exact completed state and finalizes the marker');

    woo_scheduler_reset('no');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(30));
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'yes');
    woo_scheduler_put_option('woocommerce_admin_scheduler_last_processed_order_modified_date', '2022-01-01 00:00:00');
    woo_scheduler_put_option('woocommerce_admin_scheduler_last_processed_order_id', '9');
    $GLOBALS['wooSchedulerFailPoint'] = 'cursor_date';
    try {
        $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(31));
    } catch (RuntimeException $ignored) {
    }
    woo_scheduler_put_option('woocommerce_admin_scheduler_last_processed_order_id', '777');
    duo_check_throws(
        static fn() => $provider->invoke_scoped(
            'reconcile_analytics_import_schedule', [], woo_scheduler_operation(31)
        ),
        RuntimeException::class,
        'ambiguous cursor mutation during a partial transition is never replayed or blessed',
        'ambiguous partial cursor reset'
    );

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(33));
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'no');
    $immediate = $provider->invoke_scoped(
        'reconcile_analytics_import_schedule', [], woo_scheduler_operation(34)
    );
    foreach (woo_scheduler_action_rows() as $row) {
        if ($row['_action']->get_args() === [null, null]) {
            $GLOBALS['wpdb']->update('actionscheduler_actions', ['status' => 'complete'], [
                'action_id' => (int) $row['action_id'],
            ]);
        }
    }
    $writesBeforeNaturalReconcile = count($GLOBALS['wooSchedulerOptionWrites']);
    $consumed = $provider->reconcile_scoped(
        'reconcile_analytics_import_schedule', [], woo_scheduler_operation(34)
    );
    duo_check_same($immediate['after'], $consumed['after'],
        'natural one-time catch-up consumption preserves the durable immediate projection');
    duo_check_same($writesBeforeNaturalReconcile, count($GLOBALS['wooSchedulerOptionWrites']),
        'reconcile after natural action consumption remains strictly observational');

    woo_scheduler_reset('yes');
    woo_scheduler_put_option('woocommerce_admin_scheduler_last_processed_order_modified_date', '2021-02-03 04:05:06');
    woo_scheduler_put_option('woocommerce_admin_scheduler_last_processed_order_id', '88');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(35));
    foreach (woo_scheduler_action_rows() as $row) {
        if ($row['status'] === 'pending') {
            $GLOBALS['wpdb']->update('actionscheduler_actions', ['status' => 'canceled'], [
                'action_id' => (int) $row['action_id'],
            ]);
        }
    }
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(36));
    duo_check_same('2021-02-03 04:05:06', get_option('woocommerce_admin_scheduler_last_processed_order_modified_date'),
        'steady scheduled-mode repair never resets the merchant runtime date cursor');
    duo_check_same('88', get_option('woocommerce_admin_scheduler_last_processed_order_id'),
        'steady scheduled-mode repair never resets the merchant runtime ID cursor');

    woo_scheduler_add_action('single', ['foreign'], null, 'pending', 'foreign-group');
    duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'foreign same-hook group/arguments remove analytics capability before mutation');
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'foreign same-hook topology is loud at invoke',
        'foreign'
    );
    duo_check(!str_contains((string) get_option('_duo_woocommerce_scheduler_settings_state', ''), '"phase":"intent"'),
        'foreign topology refusal occurs before an intent or external mutation');

    woo_scheduler_reset('yes');
    woo_scheduler_add_action('recurring', [], 43200, 'in-progress');
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'an in-progress analytics action blocks capability negotiation before mutation');

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    $stableWithWork = $provider->invoke_scoped(
        'reconcile_analytics_import_schedule', [], woo_scheduler_operation(40)
    );
    $workId = woo_scheduler_add_action('single', ['2026-08-24 00:00:00', 9000000001], null);
    $workProjection = $provider->invoke_scoped(
        'reconcile_analytics_import_schedule', [], woo_scheduler_operation(41)
    );
    duo_check_same(1, woo_scheduler_action_count('work'),
        'a bounded exact native continuation is preserved rather than mistaken for foreign work');
    $GLOBALS['wpdb']->update('actionscheduler_actions', ['status' => 'complete'], ['action_id' => $workId]);
    $workConsumed = $provider->reconcile_scoped(
        'reconcile_analytics_import_schedule', [], woo_scheduler_operation(41)
    );
    duo_check_same($workProjection['after'], $workConsumed['after'],
        'natural continuation consumption preserves normalized recovery evidence');

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(42));
    woo_scheduler_add_action('recurring', [], 43200);
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(43));
    duo_check_same(1, woo_scheduler_action_count('recurring'),
        'duplicate recurring actions converge to one exact scoped action');
    duo_check(count($GLOBALS['wooSchedulerCanceledActionIds']) === 2
        && count(array_unique($GLOBALS['wooSchedulerCanceledActionIds'])) === 2,
        'duplicate repair cancels only the two exact natively claimed action identities');

    foreach (['before', 'middle', 'after'] as $position) {
        woo_scheduler_reset('yes');
        $provider = woo_scheduler_provider($policy);
        $provider->invoke_scoped(
            'reconcile_analytics_import_schedule', [], woo_scheduler_operation(440)
        );
        woo_scheduler_add_action('recurring', [], 43200);
        $candidate = $GLOBALS['wpdb']->rows('actionscheduler_actions')[0];
        unset($candidate['action_id']);
        $candidate['status'] = 'pending';
        $candidate['claim_id'] = 0;
        $primary = $GLOBALS['wpdb'];
        $second = (new FakeWpdb('wp_'))->setConnectionId(2);
        $second->shareDatabaseStateWith($primary)->shareAdvisoryLocksWith($primary);
        $insertResult = null;
        $insertError = '';
        $attempted = false;
        $GLOBALS['wooSchedulerDuringCancel'] = static function (string $phase) use (
            $position,
            $candidate,
            $primary,
            $second,
            &$insertResult,
            &$insertError,
            &$attempted
        ): void {
            if ($attempted || $phase !== $position) {
                return;
            }
            $attempted = true;
            $GLOBALS['wpdb'] = $second;
            $insertResult = $second->insert('actionscheduler_actions', $candidate);
            $insertError = $second->last_error;
            $GLOBALS['wpdb'] = $primary;
        };
        $provider->invoke_scoped(
            'reconcile_analytics_import_schedule', [], woo_scheduler_operation(441)
        );
        $GLOBALS['wooSchedulerDuringCancel'] = null;
        duo_check($attempted
            && $insertResult === false
            && str_contains($insertError, 'row lock wait timeout'),
            "$position cancellation range-lock blocks a newly inserted matching native action");
        $GLOBALS['wpdb'] = $second;
        $retryInsert = $second->insert('actionscheduler_actions', $candidate);
        $GLOBALS['wpdb'] = $primary;
        duo_check($retryInsert === 1 && woo_scheduler_action_count('recurring') === 2,
            "$position competing action survives by retrying after exact cancellation commit");
    }

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(450));
    $targetId = (int) $GLOBALS['wpdb']->rows('actionscheduler_actions')[0]['action_id'];
    $logsBefore = count(array_filter(
        $GLOBALS['wpdb']->rows('actionscheduler_logs'),
        static fn(array $row): bool => (int) ($row['action_id'] ?? 0) === $targetId
    ));
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'no');
    $GLOBALS['wpdb']->failNextQuery(
        'simulated ignored native cancellation-log insert failure',
        'INSERT INTO `wp_actionscheduler_logs`'
    );
    duo_check_throws(
        static fn() => $provider->invoke_scoped(
            'reconcile_analytics_import_schedule', [], woo_scheduler_operation(451)
        ),
        RuntimeException::class,
        'ignored native cancellation-log insert failure rolls cancellation back loudly',
        'did not append exactly one owner-bound log'
    );
    duo_check(woo_scheduler_action_count('recurring') === 1
        && count(array_filter(
            $GLOBALS['wpdb']->rows('actionscheduler_logs'),
            static fn(array $row): bool => (int) ($row['action_id'] ?? 0) === $targetId
        )) === $logsBefore,
        'failed cancellation-log insert leaves exact action and log preimages');
    $provider->invoke_scoped(
        'reconcile_analytics_import_schedule', [], woo_scheduler_operation(451)
    );
    duo_check(woo_scheduler_action_count('recurring') === 0,
        'same-operation retry converges after cancellation-log insert recovery');

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(452));
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'no');
    $GLOBALS['wooSchedulerAfterCancelLog'] = static function (int $actionId): void {
        $GLOBALS['wpdb']->insert('actionscheduler_logs', [
            'action_id' => $actionId,
            'message' => 'duplicate hostile cancellation log',
            'log_date_gmt' => gmdate('Y-m-d H:i:s'),
            'log_date_local' => gmdate('Y-m-d H:i:s'),
        ]);
    };
    duo_check_throws(
        static fn() => $provider->invoke_scoped(
            'reconcile_analytics_import_schedule', [], woo_scheduler_operation(453)
        ),
        RuntimeException::class,
        'multiple same-owner cancellation logs roll back as a non-native append',
        'did not append exactly one owner-bound log'
    );
    duo_check_same(1, woo_scheduler_action_count('recurring'),
        'multiple-log refusal rolls the canceled action back to pending');

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(454));
    woo_scheduler_add_action('recurring', [], 43200);
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'no');
    $cancelLogInserts = 0;
    $GLOBALS['wpdb']->onQuery(static function (string $sql, string $method) use (&$cancelLogInserts): null|string {
        if ($method === 'insert'
            && str_contains($sql, 'INSERT INTO `wp_actionscheduler_logs`')
            && ++$cancelLogInserts === 2) {
            return 'simulated second-owner cancellation-log failure';
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke_scoped(
            'reconcile_analytics_import_schedule', [], woo_scheduler_operation(455)
        ),
        RuntimeException::class,
        'partial two-owner cancellation-log append rolls the whole transaction back',
        'did not append exactly one owner-bound log'
    );
    duo_check($cancelLogInserts === 2 && woo_scheduler_action_count('recurring') === 2,
        'partial cancellation-log append preserves both pending action preimages');

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(456));
    $targetId = (int) $GLOBALS['wpdb']->rows('actionscheduler_actions')[0]['action_id'];
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'no');
    $primary = $GLOBALS['wpdb'];
    $second = (new FakeWpdb('wp_'))->setConnectionId(2);
    $second->shareDatabaseStateWith($primary)->shareAdvisoryLocksWith($primary);
    $sameOwnerInsert = null;
    $sameOwnerError = '';
    $GLOBALS['wooSchedulerDuringCancel'] = static function (string $phase) use (
        $targetId,
        $primary,
        $second,
        &$sameOwnerInsert,
        &$sameOwnerError
    ): void {
        if ($phase !== 'before' || $sameOwnerInsert !== null) {
            return;
        }
        $GLOBALS['wpdb'] = $second;
        $sameOwnerInsert = $second->insert('actionscheduler_logs', [
            'action_id' => $targetId,
            'message' => 'competing same-owner log',
            'log_date_gmt' => gmdate('Y-m-d H:i:s'),
            'log_date_local' => gmdate('Y-m-d H:i:s'),
        ]);
        $sameOwnerError = $second->last_error;
        $GLOBALS['wpdb'] = $primary;
    };
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(457));
    duo_check($sameOwnerInsert === false && str_contains($sameOwnerError, 'row lock wait timeout'),
        'cancellation-log owner range blocks a concurrent same-action append');

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(458));
    $foreignId = woo_scheduler_add_action(
        'single', [], null, 'pending', 'foreign-runtime-group', 'foreign-runtime-hook'
    );
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'no');
    $primary = $GLOBALS['wpdb'];
    $second = (new FakeWpdb('wp_'))->setConnectionId(2);
    $second->shareDatabaseStateWith($primary)->shareAdvisoryLocksWith($primary);
    $foreignLogInsert = null;
    $GLOBALS['wooSchedulerDuringCancel'] = static function (string $phase) use (
        $foreignId,
        $primary,
        $second,
        &$foreignLogInsert
    ): void {
        if ($phase !== 'before' || $foreignLogInsert !== null) {
            return;
        }
        $GLOBALS['wpdb'] = $second;
        $foreignLogInsert = $second->insert('actionscheduler_logs', [
            'action_id' => $foreignId,
            'message' => 'legitimate unrelated runner log',
            'log_date_gmt' => gmdate('Y-m-d H:i:s'),
            'log_date_local' => gmdate('Y-m-d H:i:s'),
        ]);
        $GLOBALS['wpdb'] = $primary;
    };
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], woo_scheduler_operation(459));
    duo_check($foreignLogInsert === 1 && count(array_filter(
        $GLOBALS['wpdb']->rows('actionscheduler_logs'),
        static fn(array $row): bool => (int) ($row['action_id'] ?? 0) === $foreignId
    )) === 2, 'unrelated Action Scheduler logs remain concurrent and byte-preserved');

    foreach (['truthy-no-apply', 'probe-throw', 'reconnect'] as $commitMode) {
        woo_scheduler_reset('yes');
        $provider = woo_scheduler_provider($policy);
        $provider->invoke_scoped(
            'reconcile_analytics_import_schedule', [], woo_scheduler_operation(460)
        );
        woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'no');
        $afterCommit = false;
        $injected = false;
        $commitCount = 0;
        $GLOBALS['wpdb']->onQuery(static function (
            string $sql,
            string $method,
            FakeWpdb $db
        ) use ($commitMode, &$afterCommit, &$commitCount, &$injected): null|string {
            if ($method === 'query' && trim($sql) === 'COMMIT') {
                $commitCount++;
                if ($commitCount === 1 && $commitMode === 'truthy-no-apply') {
                    // The first COMMIT belongs to the native claim fence. Arm
                    // the fake only after that statement is intercepted so
                    // the lost-apply frontier lands on exact cancellation.
                    $db->acknowledgeNextQueryWithoutExecution('COMMIT');
                } elseif ($commitCount === 2 && $commitMode === 'reconnect') {
                    $injected = true;
                    $db->setConnectionId(99);
                } elseif ($commitCount === 2 && $commitMode === 'probe-throw') {
                    $afterCommit = true;
                }
            } elseif ($commitMode === 'probe-throw'
                && $afterCommit
                && !$injected
                && str_contains($sql, '@@in_transaction AS in_transaction')) {
                $injected = true;
                return 'simulated cancellation post-COMMIT probe failure';
            }
            return null;
        });
        duo_check_throws(
            static fn() => $provider->invoke_scoped(
                'reconcile_analytics_import_schedule', [], woo_scheduler_operation(461)
            ),
            RuntimeException::class,
            "$commitMode cancellation COMMIT frontier remains loud",
            $commitMode === 'probe-throw' ? 'provider checked read failed' : 'duo:'
        );
        $expectedRecurring = $commitMode === 'probe-throw' ? 0 : 1;
        duo_check(woo_scheduler_action_count('recurring') === $expectedRecurring,
            "$commitMode cancellation frontier has its exact action outcome");
        duo_check(woo_scheduler_claim_count() === 0,
            "$commitMode cancellation frontier strands no native claim");
        duo_check($GLOBALS['wpdb']->get_var('SELECT @@in_transaction') === '0',
            "$commitMode cancellation frontier leaves no live transaction");
    }

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    for ($i = 0; $i < 17; $i++) {
        woo_scheduler_add_action('recurring', [], 43200);
    }
    duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'seventeen pending actions exceed the bounded Action Scheduler transfer');

    woo_scheduler_reset('yes');
    $actionId = woo_scheduler_add_action('recurring', [], 43200);
    $validSchedule = (string) ($GLOBALS['wpdb']->rows('actionscheduler_actions')[0]['schedule'] ?? '');
    $timestamp = time();
    $referenceSchedule = preg_replace('/i:[0-9]+;\}$/D', 'R:2;}', $validSchedule);
    $nestedSchedule = preg_replace('/i:[0-9]+;\}$/D', 'a:1:{i:0;a:1:{i:0;i:1;}}}', $validSchedule);
    $hostileSchedules = [
        'oversized schedule' => [str_repeat('x', 4097), 'byte grammar'],
        'trailing schedule bytes' => [$validSchedule . 'x', 'serialized schedule is malformed'],
        'reference-shaped schedule' => [$referenceSchedule, 'class grammar'],
        'nested schedule state' => [$nestedSchedule, 'non-scalar state'],
        'object wakeup schedule' => [serialize(new WooSchedulerWakeupCanary()), 'class grammar'],
    ];
    foreach ($hostileSchedules as $label => [$rawSchedule, $expectedMessage]) {
        woo_scheduler_reset('yes');
        $actionId = woo_scheduler_add_action('recurring', [], 43200);
        $GLOBALS['wpdb']->update('actionscheduler_actions', ['schedule' => $rawSchedule], [
            'action_id' => $actionId,
        ]);
        $provider = woo_scheduler_provider($policy);
        duo_check_throws(
            static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
            RuntimeException::class,
            "$label refuses before native Action Scheduler hydration",
            $expectedMessage
        );
        duo_check_same(0, $GLOBALS['wooSchedulerNativeHydrations'],
            "$label is rejected by the bounded raw roster before hookable getters");
        duo_check_same(0, WooSchedulerWakeupCanary::$wakeups,
            "$label executes no serialized object wakeup hook");
    }

    foreach ([
        'malformed JSON args' => '{bad',
        'noncanonical JSON args' => '[ ]',
        'oversized JSON args' => str_repeat('x', 192),
    ] as $label => $rawArgs) {
        woo_scheduler_reset('yes');
        $actionId = woo_scheduler_add_action('recurring', [], 43200);
        $GLOBALS['wpdb']->update('actionscheduler_actions', ['args' => $rawArgs], ['action_id' => $actionId]);
        $provider = woo_scheduler_provider($policy);
        duo_check_throws(
            static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
            RuntimeException::class,
            "$label is a loud raw action boundary",
            'raw action'
        );
        duo_check_same(0, $GLOBALS['wooSchedulerNativeHydrations'],
            "$label refuses before native action construction");
    }

    woo_scheduler_reset('yes');
    $actionId = woo_scheduler_add_action('recurring', [], 43200);
    $stored = $GLOBALS['wpdb']->rows('actionscheduler_actions')[0]['_action'];
    $GLOBALS['wooSchedulerNativeActionOverride'] = new WooSchedulerAction(
        $stored->get_hook(),
        $stored->get_args(),
        $stored->get_group(),
        $stored->get_schedule()
    );
    $provider = woo_scheduler_provider($policy);
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'a method-compatible non-native action class is never trusted after raw preflight',
        'malformed native action'
    );

    woo_scheduler_reset('yes');
    $actionId = woo_scheduler_add_action('recurring', [], 43200);
    $rawRaceInjected = false;
    $GLOBALS['wooSchedulerBeforeNativeHydration'] = static function () use ($actionId, &$rawRaceInjected): void {
        if ($rawRaceInjected) {
            return;
        }
        $rawRaceInjected = true;
        $GLOBALS['wpdb']->update('actionscheduler_actions', [
            'last_attempt_gmt' => '2026-08-24 01:02:03',
            'last_attempt_local' => '2026-08-24 01:02:03',
        ], ['action_id' => $actionId]);
    };
    $provider = woo_scheduler_provider($policy);
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'same-length raw roster drift across native hydration is refused',
        'raw action roster changed'
    );
    duo_check($rawRaceInjected && $GLOBALS['wooSchedulerNativeHydrations'] > 0,
        'raw roster drift is injected strictly after bounded preflight and before native readback');

    $hostileSchemaAuthorities = [
        'missing' => null,
        'empty' => '',
        'falsey zero' => '0',
        'legacy scalar' => '1',
        'bare version' => '8',
        'missing timestamp' => '8.0',
        'zero timestamp' => '8.0.0',
        'old schema' => '7.0.1700000000',
        'future schema' => '9.0.1700000000',
        'leading schema zero' => '08.0.1700000000',
        'leading minor zero' => '8.00.1700000000',
        'leading timestamp zero' => '8.0.01700000000',
        'overflow timestamp' => '8.0.9223372036854775808',
        'secret-shaped malformed' => '8.0.secret=SCHEMA_MARKER',
    ];
    foreach ($hostileSchemaAuthorities as $label => $schemaAuthority) {
        woo_scheduler_reset('yes');
        if ($schemaAuthority === null) {
            woo_scheduler_delete_option('schema-ActionScheduler_StoreSchema');
        } else {
            woo_scheduler_put_option('schema-ActionScheduler_StoreSchema', $schemaAuthority);
        }
        $provider = woo_scheduler_provider($policy);
        duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
            "$label Action Scheduler schema authority closes negotiation");
        duo_check_throws(
            static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
            RuntimeException::class,
            "$label schema authority refuses before synchronous analytics fallback",
            'schema'
        );
        duo_check($GLOBALS['wooSchedulerSynchronousAnalyticsRuns'] === 0
            && woo_scheduler_action_count('recurring') === 0
            && woo_scheduler_claim_count() === 0,
            "$label schema refusal executes no synchronous job or scheduler mutation");
    }

    woo_scheduler_reset('no');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke_scoped(
        'reconcile_analytics_import_schedule',
        [],
        woo_scheduler_operation(470)
    );
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'yes');
    $primary = $GLOBALS['wpdb'];
    $second = (new FakeWpdb('wp_'))->setConnectionId(2);
    $second->shareDatabaseStateWith($primary)->shareAdvisoryLocksWith($primary);
    $schemaRaceWrite = null;
    $schemaRaceInjected = false;
    $primary->onQuery(static function (string $sql) use (
        $primary,
        $second,
        &$schemaRaceInjected,
        &$schemaRaceWrite
    ): null {
        if (!$schemaRaceInjected
            && str_contains($sql, 'FOR UPDATE')
            && str_contains($sql, 'schema-ActionScheduler_StoreSchema')) {
            $schemaRaceInjected = true;
            $GLOBALS['wpdb'] = $second;
            $schemaRaceWrite = $second->update('options', ['option_value' => '7.0.1700000000'], [
                'option_name' => 'schema-ActionScheduler_StoreSchema',
            ]);
            $GLOBALS['wpdb'] = $primary;
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke_scoped(
            'reconcile_analytics_import_schedule',
            [],
            woo_scheduler_operation(471)
        ),
        RuntimeException::class,
        'same-length schema drift before its transaction lock refuses native scheduling',
        'schema authority'
    );
    duo_check($schemaRaceInjected
        && $schemaRaceWrite === 1
        && $GLOBALS['wooSchedulerSynchronousAnalyticsRuns'] === 0
        && woo_scheduler_action_count('recurring') === 0
        && woo_scheduler_claim_count() === 0,
        'schema reproof race rolls back without a synchronous job, action, or claim');
    $primary->onQuery(null);
    woo_scheduler_put_option('schema-ActionScheduler_StoreSchema', '8.0.1700000000');
    $provider->invoke_scoped(
        'reconcile_analytics_import_schedule',
        [],
        woo_scheduler_operation(471)
    );
    duo_check(woo_scheduler_action_count('recurring') === 1,
        'the exact originating operation retries after schema authority recovery');

    woo_scheduler_reset('no');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_analytics_import_schedule', []);
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'yes');
    $primary = $GLOBALS['wpdb'];
    $second = (new FakeWpdb('wp_'))->setConnectionId(2);
    $second->shareDatabaseStateWith($primary)->shareAdvisoryLocksWith($primary);
    $lockedSchemaWrite = null;
    $lockedSchemaError = '';
    $GLOBALS['wooSchedulerBeforeActionInsert'] = static function () use (
        $primary,
        $second,
        &$lockedSchemaError,
        &$lockedSchemaWrite
    ): void {
        if ($lockedSchemaWrite !== null) {
            return;
        }
        $GLOBALS['wpdb'] = $second;
        $lockedSchemaWrite = $second->update('options', ['option_value' => '7.0.1700000000'], [
            'option_name' => 'schema-ActionScheduler_StoreSchema',
        ]);
        $lockedSchemaError = $second->last_error;
        $GLOBALS['wpdb'] = $primary;
    };
    $provider->invoke('reconcile_analytics_import_schedule', []);
    duo_check($lockedSchemaWrite === false
        && str_contains($lockedSchemaError, 'row lock wait timeout')
        && $GLOBALS['wooSchedulerSynchronousAnalyticsRuns'] === 0,
        'the exact schema row remains locked across the native scheduling callback');
    duo_check(woo_scheduler_action_count('recurring') === 1,
        'blocked schema drift cannot divert native scheduling into synchronous fallback');
    $GLOBALS['wooSchedulerBeforeActionInsert'] = null;

    woo_scheduler_reset('no');
    $GLOBALS['wpdb']->delete('actionscheduler_groups', ['slug' => 'wc-admin-data']);
    $GLOBALS['wpdb']->insert('actionscheduler_groups', [
        'group_id' => 9000000001,
        'slug' => 'unrelated-native-group',
    ]);
    $provider = woo_scheduler_provider($policy);
    duo_check(isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'an absent wc-admin-data group with an empty action roster is clean derived state');
    $provider->invoke_scoped(
        'reconcile_analytics_import_schedule',
        [],
        woo_scheduler_operation(472)
    );
    duo_check(count(array_filter(
        $GLOBALS['wpdb']->rows('actionscheduler_groups'),
        static fn(array $row): bool => ($row['slug'] ?? null) === 'wc-admin-data'
    )) === 0, 'immediate-mode adoption does not invent an unused native scheduler group');
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'yes');
    $GLOBALS['wooSchedulerFailPoint'] = 'schedule';
    duo_check_throws(
        static fn() => $provider->invoke_scoped(
            'reconcile_analytics_import_schedule',
            [],
            woo_scheduler_operation(473)
        ),
        RuntimeException::class,
        'failure after native group creation rolls the derived group and action back together',
        'injected Woo scheduler failure at schedule'
    );
    duo_check(count(array_filter(
        $GLOBALS['wpdb']->rows('actionscheduler_groups'),
        static fn(array $row): bool => ($row['slug'] ?? null) === 'wc-admin-data'
    )) === 0
        && woo_scheduler_action_count('recurring') === 0
        && woo_scheduler_claim_count() === 0
        && woo_scheduler_log_count() === 0,
        'native group/action/log rollback preserves the exact absent-group preimage');
    $provider->invoke_scoped(
        'reconcile_analytics_import_schedule',
        [],
        woo_scheduler_operation(473)
    );
    $nativeGroups = array_values(array_filter(
        $GLOBALS['wpdb']->rows('actionscheduler_groups'),
        static fn(array $row): bool => ($row['slug'] ?? null) === 'wc-admin-data'
    ));
    $nativeActions = array_values(array_filter(
        $GLOBALS['wpdb']->rows('actionscheduler_actions'),
        static fn(array $row): bool => ($row['status'] ?? null) === 'pending'
    ));
    duo_check(count($nativeGroups) === 1
        && (int) $nativeGroups[0]['group_id'] > 9000000001
        && count($nativeActions) === 1
        && $nativeActions[0]['group_id'] === $nativeGroups[0]['group_id'],
        'retry natively creates one divergent-ID group and binds the recurring action to it');

    woo_scheduler_reset('yes');
    woo_scheduler_add_action('recurring', [], 43200);
    $GLOBALS['wpdb']->delete('actionscheduler_groups', ['slug' => 'wc-admin-data']);
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'an active analytics action referencing a missing group remains loud');

    woo_scheduler_reset('yes');
    $GLOBALS['wpdb']->insert('actionscheduler_groups', [
        'group_id' => 2,
        'slug' => 'wc-admin-data',
    ]);
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'duplicate native analytics groups remain a loud dirty-target boundary');

    woo_scheduler_reset('no');
    $GLOBALS['wpdb']->delete('actionscheduler_groups', ['slug' => 'wc-admin-data']);
    $provider = woo_scheduler_provider($policy);
    $provider->invoke_scoped(
        'reconcile_analytics_import_schedule',
        [],
        woo_scheduler_operation(474)
    );
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'yes');
    $GLOBALS['wpdb']->failNextQuery(
        'simulated native group insert failure',
        'INSERT INTO `wp_actionscheduler_groups`'
    );
    duo_check_throws(
        static fn() => $provider->invoke_scoped(
            'reconcile_analytics_import_schedule',
            [],
            woo_scheduler_operation(475)
        ),
        RuntimeException::class,
        'native group insert failure remains atomic and retryable',
        'native scheduler group creation failed'
    );
    duo_check($GLOBALS['wpdb']->rows('actionscheduler_groups') === []
        && woo_scheduler_action_count('recurring') === 0,
        'failed native group creation leaves no partial group or action');
    $provider->invoke_scoped(
        'reconcile_analytics_import_schedule',
        [],
        woo_scheduler_operation(475)
    );
    duo_check(woo_scheduler_action_count('recurring') === 1,
        'same-operation retry converges after native group insertion recovers');

    woo_scheduler_reset('no');
    $GLOBALS['wpdb']->delete('actionscheduler_groups', ['slug' => 'wc-admin-data']);
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_analytics_import_schedule', []);
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'yes');
    $primary = $GLOBALS['wpdb'];
    $second = (new FakeWpdb('wp_'))->setConnectionId(2);
    $second->shareDatabaseStateWith($primary)->shareAdvisoryLocksWith($primary);
    $concurrentGroupInsert = null;
    $concurrentGroupError = '';
    $GLOBALS['wooSchedulerBeforeActionInsert'] = static function () use (
        $primary,
        $second,
        &$concurrentGroupError,
        &$concurrentGroupInsert
    ): void {
        $GLOBALS['wpdb'] = $second;
        $concurrentGroupInsert = $second->insert('actionscheduler_groups', ['slug' => 'wc-admin-data']);
        $concurrentGroupError = $second->last_error;
        $GLOBALS['wpdb'] = $primary;
    };
    $provider->invoke('reconcile_analytics_import_schedule', []);
    duo_check($concurrentGroupInsert === false
        && str_contains($concurrentGroupError, 'row lock wait timeout'),
        'the exact group-slug range lock blocks a concurrent native duplicate insertion');
    duo_check(count(array_filter(
        $GLOBALS['wpdb']->rows('actionscheduler_groups'),
        static fn(array $row): bool => ($row['slug'] ?? null) === 'wc-admin-data'
    )) === 1, 'concurrent group creation cannot produce a duplicate after commit');
    $GLOBALS['wooSchedulerBeforeActionInsert'] = null;

    foreach ([
        'missing native column' => static function (FakeWpdb $db): void {
            $rows = $db->get_results('SHOW FULL COLUMNS FROM `wp_actionscheduler_actions`', ARRAY_A);
            array_pop($rows);
            $db->setColumnDefinitions('actionscheduler_actions', $rows);
        },
        'extra native column' => static function (FakeWpdb $db): void {
            $rows = $db->get_results('SHOW FULL COLUMNS FROM `wp_actionscheduler_actions`', ARRAY_A);
            $rows[] = ['Field' => 'extension_bytes', 'Type' => 'longtext', 'Null' => 'YES', 'Key' => '', 'Default' => null, 'Extra' => ''];
            $db->setColumnDefinitions('actionscheduler_actions', $rows);
        },
        'retyped native column' => static function (FakeWpdb $db): void {
            $rows = $db->get_results('SHOW FULL COLUMNS FROM `wp_actionscheduler_actions`', ARRAY_A);
            $rows[1]['Type'] = 'varchar(255)';
            $db->setColumnDefinitions('actionscheduler_actions', $rows);
        },
        'reordered native columns' => static function (FakeWpdb $db): void {
            $rows = $db->get_results('SHOW FULL COLUMNS FROM `wp_actionscheduler_actions`', ARRAY_A);
            [$rows[1], $rows[2]] = [$rows[2], $rows[1]];
            $db->setColumnDefinitions('actionscheduler_actions', $rows);
        },
    ] as $label => $mutate) {
        woo_scheduler_assert_storage_refusal($policy, $label, $mutate);
    }

    $indexMutations = [
        'shortened hook index prefix' => static function (array &$rows): void {
            foreach ($rows as &$row) {
                if ($row['Key_name'] === 'hook_status_scheduled_date_gmt' && $row['Column_name'] === 'hook') {
                    $row['Sub_part'] = 162;
                }
            }
        },
        'extended hook index prefix' => static function (array &$rows): void {
            foreach ($rows as &$row) {
                if ($row['Key_name'] === 'hook_status_scheduled_date_gmt' && $row['Column_name'] === 'hook') {
                    $row['Sub_part'] = 164;
                }
            }
        },
        'shortened args index prefix' => static function (array &$rows): void {
            foreach ($rows as &$row) {
                if ($row['Key_name'] === 'args') {
                    $row['Sub_part'] = 190;
                }
            }
        },
        'numeric index prefix' => static function (array &$rows): void {
            $rows[0]['Sub_part'] = 1;
        },
        'reordered composite index' => static function (array &$rows): void {
            foreach ($rows as &$row) {
                if ($row['Key_name'] === 'status_claim_id') {
                    $row['Seq_in_index'] = $row['Seq_in_index'] === '1' ? 2 : 1;
                }
            }
        },
        'hidden native index' => static function (array &$rows): void {
            $rows[0]['Visible'] = 'NO';
        },
        'ignored native index' => static function (array &$rows): void {
            $rows[0]['Ignored'] = 'YES';
        },
        'descending native index' => static function (array &$rows): void {
            $rows[0]['Collation'] = 'D';
        },
        'wrong index nullability' => static function (array &$rows): void {
            $rows[0]['Null'] = 'YES';
        },
        'renamed native index' => static function (array &$rows): void {
            $rows[0]['Key_name'] = 'renamed_primary';
        },
        'extra native index' => static function (array &$rows): void {
            $extra = $rows[0];
            $extra['Key_name'] = 'extension_index';
            $extra['Non_unique'] = '1';
            $rows[] = $extra;
        },
        'non-BTREE native index' => static function (array &$rows): void {
            $rows[0]['Index_type'] = 'HASH';
        },
    ];
    foreach ($indexMutations as $label => $mutate) {
        woo_scheduler_assert_storage_refusal($policy, $label, static function (FakeWpdb $db) use ($mutate): void {
            $rows = $db->get_results('SHOW INDEX FROM `wp_actionscheduler_actions`', ARRAY_A);
            $mutate($rows);
            $db->setIndexes('actionscheduler_actions', $rows);
        });
    }

    woo_scheduler_assert_storage_refusal(
        $policy,
        'shortened native group-slug index prefix',
        static function (FakeWpdb $db): void {
            $rows = $db->get_results('SHOW INDEX FROM `wp_actionscheduler_groups`', ARRAY_A);
            foreach ($rows as &$row) {
                if ($row['Key_name'] === 'slug') {
                    $row['Sub_part'] = 190;
                }
            }
            unset($row);
            $db->setIndexes('actionscheduler_groups', $rows);
        }
    );

    $provider = woo_scheduler_prepare_no_to_yes($policy);
    $schemaChangedBeforeLock = false;
    $GLOBALS['wpdb']->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$schemaChangedBeforeLock): null {
        if (!$schemaChangedBeforeLock && $method === 'get_results'
            && str_contains($sql, 'LIMIT 1 FOR UPDATE')) {
            $schemaChangedBeforeLock = true;
            $rows = $db->get_results('SHOW INDEX FROM `wp_actionscheduler_actions`', ARRAY_A);
            $rows[0]['Visible'] = 'NO';
            $db->setIndexes('actionscheduler_actions', $rows);
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'DDL-shaped schema drift between preflight and transaction lock is re-proved and refused',
        'index'
    );
    duo_check($schemaChangedBeforeLock
        && woo_scheduler_action_count('recurring') === 0
        && woo_scheduler_claim_count() === 0,
        'pre-lock schema race rolls back before native scheduling');

    $provider = woo_scheduler_prepare_no_to_yes($policy);
    $primary = $GLOBALS['wpdb'];
    $ddlConnection = (new FakeWpdb('wp_'))->setConnectionId(22);
    $ddlConnection->shareDatabaseStateWith($primary)->shareAdvisoryLocksWith($primary);
    $lastLockedTable = null;
    $blockedDdl = [];
    $GLOBALS['wpdb']->onQuery(static function (string $sql, string $method) use (
        $primary,
        $ddlConnection,
        &$lastLockedTable,
        &$blockedDdl
    ): null {
        if ($lastLockedTable !== null) {
            $GLOBALS['wpdb'] = $ddlConnection;
            try {
                $ddlConnection->query("ALTER TABLE `$lastLockedTable` ADD COLUMN hostile int");
            } catch (RuntimeException $failure) {
                if (str_contains($failure->getMessage(), 'metadata lock wait timeout')) {
                    $blockedDdl[$lastLockedTable] = true;
                }
            } finally {
                $GLOBALS['wpdb'] = $primary;
            }
            $lastLockedTable = null;
        }
        if ($method === 'get_results'
            && preg_match('/FROM `([^`]+)`.*LIMIT 1 FOR UPDATE/D', $sql, $match) === 1) {
            $lastLockedTable = $match[1];
        }
        return null;
    });
    $provider->invoke('reconcile_analytics_import_schedule', []);
    duo_check_same([
        'wp_options',
        'wp_actionscheduler_actions',
        'wp_actionscheduler_claims',
        'wp_actionscheduler_groups',
        'wp_actionscheduler_logs',
    ], array_keys(array_filter($blockedDdl)),
        'transactional metadata locks block concurrent DDL on every native scheduler table');
    duo_check_same(1, woo_scheduler_action_count('recurring'),
        'schema-lock proof still permits one exact native scheduled action');

    foreach ([
        ['pre_as_schedule_recurring_action', 1, 'first'],
        ['action_scheduler_claim_actions_order_by', 10, 'middle'],
        ['woocommerce_analytics_import_interval', 999, 'last'],
        ['action_scheduler_stored_action', 1, 'stored-first'],
        ['action_scheduler_canceled_action', 10, 'cancel-middle'],
        ['action_scheduler_failed_fetch_action', 999, 'fetch-last'],
    ] as [$hook, $priority, $position]) {
        woo_scheduler_reset('yes');
        add_filter($hook, static fn(mixed $value = null): mixed => $value, $priority, 1);
        $provider = woo_scheduler_provider($policy);
        duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
            "$position callback injection on $hook closes negotiation before START TRANSACTION");
        duo_check_same('0', $GLOBALS['wpdb']->get_var('SELECT @@in_transaction'),
            "$position callback injection on $hook leaves no native transaction or mutation frontier");
    }

    woo_scheduler_reset('no');
    $provider = woo_scheduler_provider($policy);
    $acquiredLock = '';
    $verificationFailed = false;
    $GLOBALS['wpdb']->onQuery(static function (string $sql) use (
        &$acquiredLock,
        &$verificationFailed
    ): null|string {
        if ($acquiredLock === ''
            && preg_match("/GET_LOCK\\('([^']+)'/", $sql, $match) === 1) {
            $acquiredLock = $match[1];
        } elseif ($acquiredLock !== ''
            && !$verificationFailed
            && str_contains($sql, 'IS_USED_LOCK')) {
            $verificationFailed = true;
            return 'simulated post-acquisition continuity read failure';
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'a verified GET_LOCK followed by a driver failure runs exhaustive acquisition cleanup',
        'provider checked read failed'
    );
    $GLOBALS['wpdb']->onQuery(null);
    duo_check($verificationFailed && $acquiredLock !== ''
        && $GLOBALS['wpdb']->get_var("SELECT IS_USED_LOCK('$acquiredLock')") === null,
        'post-acquisition verification failure strands no named lock');

    foreach (['not-applied', 'applied', 'readback', 'reconnect'] as $releaseFailure) {
        woo_scheduler_reset('no');
        $provider = woo_scheduler_provider($policy);
        $lockName = '';
        $releaseInjected = false;
        $GLOBALS['wpdb']->onQuery(static function (
            string $sql,
            string $method,
            FakeWpdb $db
        ) use (&$lockName, &$releaseInjected, $releaseFailure): null|string {
            if ($lockName === '' && preg_match("/GET_LOCK\\('([^']+)'/", $sql, $match) === 1) {
                $lockName = $match[1];
            }
            if (!$releaseInjected && str_contains($sql, 'RELEASE_LOCK')) {
                $releaseInjected = true;
                if ($releaseFailure === 'applied') {
                    $db->get_var($sql);
                } elseif ($releaseFailure === 'reconnect') {
                    $db->setConnectionId(99);
                }
                return "simulated $releaseFailure RELEASE_LOCK failure";
            }
            if ($releaseFailure === 'readback'
                && $releaseInjected
                && str_contains($sql, 'IS_USED_LOCK')) {
                $releaseFailure = 'readback-consumed';
                return 'simulated release readback failure';
            }
            return null;
        });
        duo_check_throws(
            static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
            RuntimeException::class,
            "$releaseFailure mutex frontier remains loud after bounded cleanup",
            'mutex'
        );
        $GLOBALS['wpdb']->onQuery(null);
        duo_check($releaseInjected && $lockName !== ''
            && $GLOBALS['wpdb']->get_var("SELECT IS_USED_LOCK('$lockName')") === null,
            "$releaseFailure mutex frontier leaves no named-lock residue");
    }

    woo_scheduler_reset('no');
    $provider = woo_scheduler_provider($policy);
    $GLOBALS['wooSchedulerFailPoint'] = 'marker_intent';
    $releaseFailed = false;
    $GLOBALS['wpdb']->onQuery(static function (string $sql) use (&$releaseFailed): null|string {
        if (!$releaseFailed && str_contains($sql, 'RELEASE_LOCK')) {
            $releaseFailed = true;
            return 'simulated cleanup error after work failure';
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'provider mutex cleanup never masks the original scheduler work failure',
        'injected Woo scheduler failure at marker_intent'
    );

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    $witnesses = 0;
    $GLOBALS['wpdb']->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$witnesses): null {
        if ($method === 'get_results'
            && str_contains($sql, 'SHA2(option_value, 256) AS option_sha256')
            && str_contains($sql, "BINARY option_name = BINARY 'woocommerce_analytics_scheduled_import'")
            && ++$witnesses === 2) {
            woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'no');
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'same-length setting replacement between compact witnesses is refused',
        'bounded digest'
    );
    duo_check_same(2, $witnesses,
        'same-length race is injected before any LONGTEXT payload can be trusted');

    woo_scheduler_reset('yes');
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'yesx');
    $provider = woo_scheduler_provider($policy);
    $oversizedHashes = 0;
    $GLOBALS['wpdb']->onQuery(static function (string $sql) use (&$oversizedHashes): null {
        if (str_contains($sql, 'SHA2(option_value, 256)')
            && str_contains($sql, 'woocommerce_analytics_scheduled_import')) {
            $oversizedHashes++;
            throw new RuntimeException('oversized option reached SHA2');
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'oversized scheduler settings refuse on their length-only witness',
        'bounded byte grammar'
    );
    duo_check_same(0, $oversizedHashes,
        'an oversized scheduler LONGTEXT is never hashed or returned by the bounded observer');

    woo_scheduler_reset('yes');
    $spoofReads = 0;
    add_filter('option_woocommerce_analytics_scheduled_import', static function (mixed $value) use (&$spoofReads): mixed {
        $spoofReads++;
        return $value;
    });
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'even a same-value analytics option filter closes capability negotiation');
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'same-value option filters refuse before raw/native option comparison',
        'extension callback'
    );
    duo_check_same(0, $spoofReads,
        'unreviewed analytics option filters are never executed by the provider');

    woo_scheduler_reset('yes');
    add_action(
        'update_option_woocommerce_analytics_scheduled_import',
        [OrdersScheduler::class, 'handle_scheduled_import_option_change'],
        20,
        2
    );
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'an alternate-priority duplicate of the native analytics callback is refused');

    woo_scheduler_reset('yes');
    $hostileGenericWrites = 0;
    add_filter('pre_update_option', static function (mixed $value) use (&$hostileGenericWrites): mixed {
        $hostileGenericWrites++;
        return $value;
    });
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'an unknown generic option callback closes analytics capability negotiation');
    duo_check_same(0, $hostileGenericWrites,
        'generic option extensions are refused before their callback can run');

    woo_scheduler_reset('yes');
    $nativeFeatures = $GLOBALS['wooSchedulerContainer']->features;
    add_action('updated_option', [$nativeFeatures, 'process_updated_option'], 999, 3);
    $provider = woo_scheduler_provider($policy);
    duo_check(isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'the exact container-owned generic option callback remains admissible');
    $GLOBALS['wp_filter']['updated_option']->callbacks[999]['duplicate-native-service'] = [
        'function' => [$nativeFeatures, 'process_updated_option'],
        'accepted_args' => 3,
    ];
    duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'duplicate copies of an otherwise exact native service callback are refused');

    woo_scheduler_reset('yes');
    add_action('updated_option', [new FeaturesController(), 'process_updated_option'], 999, 3);
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'a same-class callback from a substituted service instance is refused');

    woo_scheduler_reset('no');
    $throwingMarkerCallback = 0;
    add_action('update_option__duo_woocommerce_scheduler_settings_state', static function () use (
        &$throwingMarkerCallback
    ): void {
        $throwingMarkerCallback++;
        throw new RuntimeException('hostile marker callback executed');
    }, 10, 3);
    $provider = woo_scheduler_provider($policy);
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'a throwing post-write marker callback is refused before marker persistence',
        'extension callback'
    );
    duo_check($throwingMarkerCallback === 0
        && woo_scheduler_option_value('_duo_woocommerce_scheduler_settings_state', false) === false,
        'post-write option callbacks cannot run or leave undeclared marker effects');

    woo_scheduler_reset('yes', '30');
    $preEventCalls = 0;
    add_filter('pre_get_scheduled_event', static function (mixed $value) use (&$preEventCalls): mixed {
        $preEventCalls++;
        return $value;
    });
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_stock_notification_retention']),
        'pre_get_scheduled_event closes retention capability before cron observation');
    duo_check_same(0, $preEventCalls,
        'the retention provider refuses rather than executing pre_get_scheduled_event');

    woo_scheduler_reset('yes', '30');
    $provider = woo_scheduler_provider($policy);
    duo_check(isset($provider->capabilities()['reconcile_stock_notification_retention']),
        'stock Woo controller, cron-schedule, and global option callbacks negotiate exactly');
    $provider->invoke('reconcile_stock_notification_retention', []);
    $cronCallbackMethods = array_values(array_unique(array_map(
        static fn(array $call): string => $call[0],
        array_filter(
            $GLOBALS['wooSchedulerCoreOptionCallbackCalls'],
            static fn(array $call): bool => ($call[1] ?? null) === 'cron'
        )
    )));
    sort($cronCallbackMethods, SORT_STRING);
    $expectedCronCallbackMethods = [
        CustomOrdersTableController::class . '::process_pre_update_option',
        CustomOrdersTableController::class . '::process_updated_option',
        CustomOrdersTableController::class . '::process_updated_option_fts_index',
        DataSynchronizer::class . '::process_updated_option',
        FeaturesController::class . '::process_updated_option',
    ];
    sort($expectedCronCallbackMethods, SORT_STRING);
    duo_check_same($expectedCronCallbackMethods, $cronCallbackMethods,
        'every stock global option callback is a proved no-op around the exact cron write');
    $foreignDailyCalls = 0;
    add_action('customer_stock_notifications_daily', static function () use (&$foreignDailyCalls): void {
        $foreignDailyCalls++;
    });
    duo_check(!isset($provider->capabilities()['reconcile_stock_notification_retention'])
        && $foreignDailyCalls === 0,
        'an extra daily-retention callback closes negotiation without being executed');

    woo_scheduler_reset('yes', '30');
    $nativeRetention = $GLOBALS['wooSchedulerContainer']->retention;
    $nativeRetentionCallback = woo_scheduler_callback_id([
        $nativeRetention,
        'schedule_or_unschedule_daily_task',
    ]);
    unset($GLOBALS['wp_filter'][
        'update_option_woocommerce_customer_stock_notifications_unverified_deletions_days_threshold'
    ]->callbacks[10][$nativeRetentionCallback]);
    $foreignRetention = (new ReflectionClass(DataRetentionController::class))->newInstanceWithoutConstructor();
    add_action(
        'update_option_woocommerce_customer_stock_notifications_unverified_deletions_days_threshold',
        [$foreignRetention, 'schedule_or_unschedule_daily_task'],
        10,
        2
    );
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_stock_notification_retention']),
        'a same-class substituted retention controller instance is refused');

    woo_scheduler_reset('yes', '30');
    $nativeRunner = ActionScheduler_QueueRunner::instance();
    $nativeRunnerCallback = woo_scheduler_callback_id([$nativeRunner, 'add_wp_cron_schedule']);
    unset($GLOBALS['wp_filter']['cron_schedules']->callbacks[10][$nativeRunnerCallback]);
    $foreignRunner = (new ReflectionClass(ActionScheduler_QueueRunner::class))->newInstanceWithoutConstructor();
    add_filter('cron_schedules', [$foreignRunner, 'add_wp_cron_schedule'], 10, 1);
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_stock_notification_retention']),
        'a same-class substituted Action Scheduler cron owner is refused');

    woo_scheduler_reset('yes', '30');
    $foreignScheduleCalls = 0;
    add_filter('cron_schedules', static function (array $schedules) use (&$foreignScheduleCalls): array {
        $foreignScheduleCalls++;
        return $schedules;
    });
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_stock_notification_retention'])
        && $foreignScheduleCalls === 0,
        'an extra cron-schedule callback is refused before its code can execute');

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    $driverMarker = 'secret=SCHEDULER_DRIVER_MARKER' . str_repeat('x', 1000);
    $GLOBALS['wpdb']->failNextQuery($driverMarker, 'SHA2(option_value');
    $message = '';
    try {
        $provider->invoke('reconcile_analytics_import_schedule', []);
    } catch (Throwable $failure) {
        $message = $failure->getMessage();
    }
    duo_check(str_contains($message, 'provider checked read failed')
        && !str_contains($message, 'SCHEDULER_DRIVER_MARKER')
        && strlen($message) < 256,
        'failed compact option witness is loud, bounded, and redacted');

    woo_scheduler_reset('yes');
    woo_scheduler_put_option('_duo_woocommerce_scheduler_settings_state', '{"format":"duo-woocommerce-scheduler-state/v1","phase":"verified","state":"yes","unknown":1}');
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'unknown marker fields remain loud instead of widening provider state');
    woo_scheduler_put_option('_duo_woocommerce_scheduler_settings_state', 'not-json secret=MARKER_SECRET');
    $markerMessage = '';
    try {
        $provider->invoke('reconcile_analytics_import_schedule', []);
    } catch (Throwable $failure) {
        $markerMessage = $failure->getMessage();
    }
    duo_check(!str_contains($markerMessage, 'MARKER_SECRET') && strlen($markerMessage) < 256,
        'corrupt marker diagnostics remain bounded and never echo marker bytes');

    foreach (['no', 'on', 'auto-on'] as $hostileAutoload) {
        woo_scheduler_reset('no');
        $provider = woo_scheduler_provider($policy);
        $provider->invoke('reconcile_analytics_import_schedule', []);
        $markerRaw = (string) woo_scheduler_option_value('_duo_woocommerce_scheduler_settings_state');
        woo_scheduler_put_option(
            '_duo_woocommerce_scheduler_settings_state',
            $markerRaw,
            $hostileAutoload
        );
        $provider = woo_scheduler_provider($policy);
        duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
            "Duo marker autoload $hostileAutoload is a loud dirty-target boundary");
        duo_check_throws(
            static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
            RuntimeException::class,
            "Duo marker autoload $hostileAutoload cannot be silently converted",
            'non-autoloaded platform wire'
        );
    }

    woo_scheduler_reset('no');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_analytics_import_schedule', []);
    $markerRow = array_values(array_filter(
        woo_scheduler_option_rows(),
        static fn(array $row): bool => ($row['option_name'] ?? null)
            === '_duo_woocommerce_scheduler_settings_state'
    ))[0];
    $markerRow['option_id'] = 9001;
    $GLOBALS['wpdb']->insert('options', $markerRow);
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'duplicate Duo marker aliases are refused before transition recovery');

    woo_scheduler_reset('no');
    $provider = woo_scheduler_provider($policy);
    $GLOBALS['wooSchedulerAfterOptionWrite'] = static function (string $name, string $value): void {
        if ($name === '_duo_woocommerce_scheduler_settings_state'
            && str_contains($value, '"phase":"intent"')) {
            woo_scheduler_put_option($name, $value, 'on');
        }
    };
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'same-value marker autoload drift is refused by durable readback',
        'marker write was not durable'
    );

    woo_scheduler_reset('no');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_analytics_import_schedule', []);
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'yes');
    $GLOBALS['wooSchedulerAfterOptionWrite'] = static function (string $name, string $value): void {
        if ($name === 'woocommerce_admin_scheduler_last_processed_order_modified_date') {
            woo_scheduler_put_option($name, $value, 'on');
        }
    };
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'missing-cursor autoload drift cannot masquerade as the native automatic wire',
        'cursor write did not persist'
    );

    woo_scheduler_reset('yes');
    $provider = woo_scheduler_provider($policy);
    $forgedOrigin = woo_scheduler_operation(900);
    $GLOBALS['wooSchedulerFailPoint'] = 'marker_intent';
    duo_check_throws(
        static fn() => $provider->invoke_scoped(
            'reconcile_analytics_import_schedule', [], $forgedOrigin
        ),
        RuntimeException::class,
        'first-adoption crash leaves an origin-bound intent',
        'injected Woo scheduler failure'
    );
    duo_check_throws(
        static fn() => $provider->invoke_scoped(
            'reconcile_analytics_import_schedule', [], woo_scheduler_operation(901)
        ),
        RuntimeException::class,
        'a later operation cannot trust a first-adoption intent from dirty target bytes',
        'belongs to another operation'
    );
    $provider->invoke_scoped('reconcile_analytics_import_schedule', [], $forgedOrigin);
    duo_check_same(1, woo_scheduler_action_count('recurring'),
        'only the exact originating operation can complete first-adoption provider state');

    woo_scheduler_reset('no');
    $provider = woo_scheduler_provider($policy);
    $GLOBALS['wooSchedulerAfterOptionWrite'] = static function (string $name, string $value): void {
        if ($name === '_duo_woocommerce_scheduler_settings_state'
            && str_contains($value, '"phase":"intent"')) {
            woo_scheduler_put_option($name, '{"format":"duo-woocommerce-scheduler-state/v1","phase":"verified","state":"yes"}');
        }
    };
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'concurrent marker replacement cannot cross the durable intent readback',
        'marker write was not durable'
    );

    woo_scheduler_reset('no');
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_analytics_import_schedule', []);
    $flattenedHooks = array_merge(...$GLOBALS['wooSchedulerOptionHooks']);
    duo_check(in_array('add_option__duo_woocommerce_scheduler_settings_state', $flattenedHooks, true)
        && in_array('update_option__duo_woocommerce_scheduler_settings_state', $flattenedHooks, true)
        && in_array('updated_option', $flattenedHooks, true),
        'marker lifecycle fires the exact native add/update option hook families');
    duo_check($GLOBALS['wooSchedulerOptionCacheEvents'] >= 2,
        'marker intent and terminal writes exercise the external WordPress option-cache boundary');
    woo_scheduler_delete_option('_duo_woocommerce_scheduler_settings_state');
    woo_scheduler_put_option('woocommerce_analytics_scheduled_import', 'yes');
    woo_scheduler_put_option('woocommerce_admin_scheduler_last_processed_order_modified_date', '2020-02-03 04:05:06');
    woo_scheduler_put_option('woocommerce_admin_scheduler_last_processed_order_id', '55');
    $provider->invoke('reconcile_analytics_import_schedule', []);
    duo_check_same('2020-02-03 04:05:06', get_option('woocommerce_admin_scheduler_last_processed_order_modified_date'),
        'marker cleanup/reinstall falls back to conservative adoption without replaying cursor reset');
    Features::$enabled = false;
    duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'a retained marker never widens behavior while the target feature is disabled');

    woo_scheduler_reset('yes', '30');
    wp_using_ext_object_cache(true);
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_analytics_import_schedule']),
        'external object-cache publication independently removes analytics repair capability');
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_analytics_import_schedule', []),
        RuntimeException::class,
        'analytics refuses external-cache option publication before scheduler mutation',
        'external object-cache publication'
    );
    duo_check(woo_scheduler_option_value('_duo_woocommerce_scheduler_settings_state', false) === false
        && woo_scheduler_action_count('recurring') === 0
        && woo_scheduler_claim_count() === 0
        && $GLOBALS['wooSchedulerSynchronousAnalyticsRuns'] === 0,
        'analytics external-cache refusal leaves no marker, action, claim, or synchronous work');

    woo_scheduler_reset('yes', '30');
    wp_using_ext_object_cache(true);
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_stock_notification_retention']),
        'external object-cache publication removes retention repair capability before mutation');
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_stock_notification_retention', []),
        RuntimeException::class,
        'external object-cache targets refuse before native cron publication',
        'external object-cache publication'
    );
    duo_check_same(0, $GLOBALS['wooSchedulerCronWrites'],
        'external-cache refusal occurs before any native cron option write');

    foreach (['invalid', str_repeat('x', 21)] as $index => $malformedAutoload) {
        woo_scheduler_reset('yes', '30');
        woo_scheduler_put_option(
            'cron',
            (string) woo_scheduler_option_value('cron'),
            $malformedAutoload
        );
        $provider = woo_scheduler_provider($policy);
        duo_check(!isset($provider->capabilities()['reconcile_stock_notification_retention']),
            "malformed cron autoload wire $index removes retention capability");
        duo_check_throws(
            static fn() => $provider->invoke('reconcile_stock_notification_retention', []),
            RuntimeException::class,
            "malformed cron autoload wire $index is a loud pre-mutation boundary",
            'malformed witness'
        );
        duo_check_same(0, $GLOBALS['wooSchedulerCronWrites'],
            "malformed cron autoload wire $index reaches no native cron mutation");
    }

    woo_scheduler_reset('yes', '30');
    $GLOBALS['wooSchedulerCacheResidue']['options']['cron'] = 'stale';
    $GLOBALS['wooSchedulerCacheDeleteFails'] = ['options', 'cron'];
    $provider = woo_scheduler_provider($policy);
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_stock_notification_retention', []),
        RuntimeException::class,
        'retention repair refuses when local option-cache deletion cannot be read back',
        'cache deletion did not persist'
    );
    duo_check_same(0, $GLOBALS['wooSchedulerCronWrites'],
        'cache readback failure rolls back before native retention scheduling');

    foreach ([time() - DAY_IN_SECONDS - 301, time() + (2 * DAY_IN_SECONDS)] as $hostileTimestamp) {
        woo_scheduler_reset('yes', '30');
        woo_scheduler_seed_cron($hostileTimestamp);
        $provider = woo_scheduler_provider($policy);
        $provider->invoke('reconcile_stock_notification_retention', []);
        $timestamps = [];
        foreach ($GLOBALS['wooSchedulerCron'] as $timestamp => $hooks) {
            if (isset($hooks['customer_stock_notifications_daily'])) {
                $timestamps[] = (int) $timestamp;
            }
        }
        duo_check(count($timestamps) === 1
            && $timestamps[0] >= time() - 300
            && $timestamps[0] <= time() + DAY_IN_SECONDS + 300,
            'stale or far-future daily retention work is cleared and natively rescheduled in horizon');
    }

    woo_scheduler_reset('yes', '30');
    $provider = woo_scheduler_provider($policy);
    $retentionCommitRolledBack = false;
    $GLOBALS['wpdb']->onQuery(static function (
        string $sql,
        string $method,
        FakeWpdb $db
    ) use (&$retentionCommitRolledBack): null|string {
        if (!$retentionCommitRolledBack && $method === 'query' && trim($sql) === 'COMMIT') {
            $retentionCommitRolledBack = true;
            $db->query('ROLLBACK');
            return 'simulated retention server rollback';
        }
        return null;
    });
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_stock_notification_retention', []),
        RuntimeException::class,
        'inactive retention COMMIT failure recognizes the exact cron preimage',
        'provider checked mutation failed'
    );
    duo_check($retentionCommitRolledBack && $GLOBALS['wooSchedulerCron'] === [],
        'server-rolled-back retention COMMIT refreshes cache to the empty preimage');
    $provider->invoke('reconcile_stock_notification_retention', []);
    duo_check_same(1, count($GLOBALS['wooSchedulerCron']),
        'retention retries safely after a server-side COMMIT rollback');

    foreach (['truthy-no-apply', 'probe-throw', 'reconnect'] as $commitMode) {
        woo_scheduler_reset('yes', '30');
        $provider = woo_scheduler_provider($policy);
        if ($commitMode === 'truthy-no-apply') {
            $GLOBALS['wpdb']->acknowledgeNextQueryWithoutExecution('COMMIT');
        } else {
            $afterCommit = false;
            $injected = false;
            $GLOBALS['wpdb']->onQuery(static function (
                string $sql,
                string $method,
                FakeWpdb $db
            ) use ($commitMode, &$afterCommit, &$injected): null|string {
                if (!$injected && $method === 'query' && trim($sql) === 'COMMIT') {
                    if ($commitMode === 'reconnect') {
                        $injected = true;
                        $db->setConnectionId(99);
                    } else {
                        $afterCommit = true;
                    }
                } elseif ($commitMode === 'probe-throw'
                    && $afterCommit
                    && !$injected
                    && str_contains($sql, '@@in_transaction AS in_transaction')) {
                    $injected = true;
                    return 'simulated retention post-COMMIT probe failure';
                }
                return null;
            });
        }
        duo_check_throws(
            static fn() => $provider->invoke('reconcile_stock_notification_retention', []),
            RuntimeException::class,
            "$commitMode retention COMMIT frontier remains loud",
            $commitMode === 'probe-throw' ? 'provider checked read failed' : 'duo:'
        );
        $expectedEvents = $commitMode === 'probe-throw' ? 1 : 0;
        duo_check(count($GLOBALS['wooSchedulerCron']) === $expectedEvents
            && $GLOBALS['wpdb']->get_var('SELECT @@in_transaction') === '0',
            "$commitMode retention COMMIT frontier has an exact inactive cron outcome");
    }

    woo_scheduler_reset('yes', '30');
    $provider = woo_scheduler_provider($policy);
    $retention = $provider->invoke_scoped(
        'reconcile_stock_notification_retention', [], woo_scheduler_operation(50)
    );
    duo_check_same(1, count($GLOBALS['wooSchedulerCron']),
        'positive whole-day retention schedules exactly one native daily cron event');
    duo_check(($retention['before']['cron_autoload'] ?? null) === 'on'
        && ($retention['after']['cron_autoload'] ?? null) === 'on',
        'retention receipts bind the exact cron autoload preimage and committed wire');
    $cronWrites = $GLOBALS['wooSchedulerCronWrites'];
    $provider->invoke_scoped('reconcile_stock_notification_retention', [], woo_scheduler_operation(51));
    duo_check_same($cronWrites, $GLOBALS['wooSchedulerCronWrites'],
        'repeated retention invoke is idempotent and does not churn stable cron');

    $cronTimestamp = (int) array_key_first($GLOBALS['wooSchedulerCron']);
    $event = $GLOBALS['wooSchedulerCron'][$cronTimestamp];
    unset($GLOBALS['wooSchedulerCron'][$cronTimestamp]);
    $GLOBALS['wooSchedulerCron'][$cronTimestamp + 86400] = $event;
    woo_scheduler_sync_cron_option(false);
    $retentionReconciled = $provider->reconcile_scoped(
        'reconcile_stock_notification_retention', [], woo_scheduler_operation(50)
    );
    duo_check_same($retention['after'], $retentionReconciled['after'],
        'natural daily cron timestamp movement preserves semantic recovery evidence');

    woo_scheduler_put_option(
        'cron',
        (string) woo_scheduler_option_value('cron'),
        'off'
    );
    $autoloadDrift = $provider->reconcile_scoped(
        'reconcile_stock_notification_retention', [], woo_scheduler_operation(50)
    );
    duo_check(($autoloadDrift['after']['cron_autoload'] ?? null) === 'off'
        && $autoloadDrift['after'] !== $retention['after'],
        'same-value cron autoload-only drift cannot reproduce the stored scoped receipt');

    woo_scheduler_reset('yes', '30');
    $provider = woo_scheduler_provider($policy);
    $GLOBALS['wooSchedulerAfterOptionWrite'] = static function (string $name, string $raw): void {
        if ($name === 'cron') {
            woo_scheduler_put_option($name, $raw, 'off');
        }
    };
    duo_check_throws(
        static fn() => $provider->invoke('reconcile_stock_notification_retention', []),
        RuntimeException::class,
        'same-value cron autoload drift during native scheduling rolls back loudly',
        'cron autoload'
    );
    duo_check(woo_scheduler_option_autoload('cron') === 'on'
        && $GLOBALS['wooSchedulerCron'] === [],
        'cron autoload drift rollback restores the exact option and topology preimage');
    $GLOBALS['wooSchedulerAfterOptionWrite'] = null;
    $provider->invoke('reconcile_stock_notification_retention', []);
    duo_check(woo_scheduler_option_autoload('cron') === 'on'
        && count($GLOBALS['wooSchedulerCron']) === 1,
        'retention retry converges after cron autoload interference is removed');

    woo_scheduler_reset('yes', '30');
    woo_scheduler_seed_unrelated_cron(time() + 600, ['preserve-me', 9000000001]);
    $unrelatedBefore = $GLOBALS['wooSchedulerCron'];
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_stock_notification_retention', []);
    $unrelatedAfter = $GLOBALS['wooSchedulerCron'];
    foreach ($unrelatedAfter as &$hooks) {
        unset($hooks['customer_stock_notifications_daily']);
    }
    unset($hooks);
    $unrelatedAfter = array_filter($unrelatedAfter);
    duo_check_same($unrelatedBefore, $unrelatedAfter,
        'locked native retention scheduling preserves every unrelated cron event byte');
    duo_check(count(array_filter(
        $GLOBALS['wooSchedulerCacheDeletes'],
        static fn(array $event): bool => $event[0] === 'options'
            && in_array($event[1], ['cron', 'alloptions', 'notoptions'], true)
    )) >= 6, 'retention transaction explicitly refreshes individual and alloptions cache views');

    woo_scheduler_reset('yes', '30');
    $primary = $GLOBALS['wpdb'];
    $second = (new FakeWpdb('wp_'))->setConnectionId(2);
    $second->shareDatabaseStateWith($primary)->shareAdvisoryLocksWith($primary);
    $staleCron = $GLOBALS['wooSchedulerCron'];
    $staleCron[time() + 900]['concurrent_product_job'][md5(serialize(['new']))] = [
        'schedule' => 'hourly',
        'args' => ['new'],
        'interval' => 3600,
    ];
    $staleCron['version'] = 2;
    $concurrentWrite = null;
    $concurrentError = '';
    $GLOBALS['wooSchedulerDuringCronMutation'] = static function () use (
        $primary,
        $second,
        $staleCron,
        &$concurrentWrite,
        &$concurrentError
    ): void {
        $GLOBALS['wpdb'] = $second;
        $concurrentWrite = $second->update('options', ['option_value' => serialize($staleCron)], [
            'option_name' => 'cron',
        ]);
        $concurrentError = $second->last_error;
        $GLOBALS['wpdb'] = $primary;
    };
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_stock_notification_retention', []);
    duo_check_same(false, $concurrentWrite,
        'a second database connection cannot overwrite the locked whole cron option');
    duo_check(str_contains($concurrentError, 'row lock wait timeout'),
        'the concurrent whole-option writer is refused by the exact cron-row lock');
    $GLOBALS['wpdb'] = $second;
    $latest = unserialize((string) woo_scheduler_option_value('cron'), ['allowed_classes' => false]);
    $latest[time() + 900]['concurrent_product_job'][md5(serialize(['new']))] = [
        'schedule' => 'hourly',
        'args' => ['new'],
        'interval' => 3600,
    ];
    $secondWrite = $second->update('options', ['option_value' => serialize($latest)], [
        'option_name' => 'cron',
    ]);
    $GLOBALS['wpdb'] = $primary;
    wp_cache_delete('cron', 'options');
    duo_check_same(1, $secondWrite,
        'the competing writer can retry from the committed bytes after the row lock releases');
    duo_check_same(1, count(array_filter(
        $GLOBALS['wooSchedulerCron'],
        static fn(array $hooks): bool => isset($hooks['customer_stock_notifications_daily'])
    )), 'the post-lock merged cron state retains the native retention event');
    duo_check(count(array_filter(
        $GLOBALS['wooSchedulerCron'],
        static fn(array $hooks): bool => isset($hooks['concurrent_product_job'])
    )) === 1, 'the post-lock merged cron state retains the concurrently added unrelated event');
    $GLOBALS['wooSchedulerDuringCronMutation'] = null;

    woo_scheduler_reset('yes', '30');
    $primary = $GLOBALS['wpdb'];
    $second = (new FakeWpdb('wp_'))->setConnectionId(2);
    $second->shareDatabaseStateWith($primary)->shareAdvisoryLocksWith($primary);
    $beforeReadUpdate = null;
    $beforeReadInjected = false;
    $primary->onQuery(static function (string $sql) use (
        $primary,
        $second,
        &$beforeReadInjected,
        &$beforeReadUpdate
    ): null {
        if (!$beforeReadInjected
            && str_contains($sql, 'FOR UPDATE')
            && str_contains(
                $sql,
                'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold'
            )) {
            $beforeReadInjected = true;
            $GLOBALS['wpdb'] = $second;
            $beforeReadUpdate = update_option(
                'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold',
                '60'
            );
            $GLOBALS['wpdb'] = $primary;
        }
        return null;
    });
    $provider = woo_scheduler_provider($policy);
    $beforeReadReceipt = $provider->invoke('reconcile_stock_notification_retention', []);
    duo_check($beforeReadUpdate === true
        && woo_scheduler_option_value(
            'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold'
        ) === '60',
        'a native retention update immediately before the source lock serializes first');
    duo_check(($beforeReadReceipt['after']['option_sha256'] ?? null) === hash('sha256', '60')
        && count($GLOBALS['wooSchedulerCron']) === 1,
        'the locked repair derives its projection from the update that serialized first');

    woo_scheduler_reset('yes', '30');
    $primary = $GLOBALS['wpdb'];
    $second = (new FakeWpdb('wp_'))->setConnectionId(2);
    $second->shareDatabaseStateWith($primary)->shareAdvisoryLocksWith($primary);
    $duringControllerUpdate = null;
    $duringControllerError = '';
    $GLOBALS['wooSchedulerDuringRetentionController'] = static function (string $phase) use (
        $primary,
        $second,
        &$duringControllerError,
        &$duringControllerUpdate
    ): void {
        if ($phase !== 'schedule' || $duringControllerUpdate !== null) {
            return;
        }
        $GLOBALS['wpdb'] = $second;
        $duringControllerUpdate = update_option(
            'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold',
            '0'
        );
        $duringControllerError = $second->last_error;
        $GLOBALS['wpdb'] = $primary;
    };
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_stock_notification_retention', []);
    duo_check($duringControllerUpdate === false
        && str_contains($duringControllerError, 'row lock wait timeout'),
        'the exact retention source-row lock blocks a native update during the controller');
    duo_check(woo_scheduler_option_value(
        'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold'
    ) === '30' && count($GLOBALS['wooSchedulerCron']) === 1,
        'blocked controller-time source drift cannot commit a stale cron projection');
    $GLOBALS['wooSchedulerDuringRetentionController'] = null;
    $GLOBALS['wpdb'] = $second;
    $controllerRetry = update_option(
        'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold',
        '0'
    );
    $GLOBALS['wpdb'] = $primary;
    duo_check($controllerRetry === true && $GLOBALS['wooSchedulerCron'] === [],
        'the blocked native update retries after commit and clears cron in serial order');

    woo_scheduler_reset('yes', '30');
    $primary = $GLOBALS['wpdb'];
    $second = (new FakeWpdb('wp_'))->setConnectionId(2);
    $second->shareDatabaseStateWith($primary)->shareAdvisoryLocksWith($primary);
    $beforeCommitUpdate = null;
    $beforeCommitError = '';
    $primary->onQuery(static function (string $sql, string $method) use (
        $primary,
        $second,
        &$beforeCommitError,
        &$beforeCommitUpdate
    ): null {
        if ($beforeCommitUpdate === null && $method === 'query' && trim($sql) === 'COMMIT') {
            $GLOBALS['wpdb'] = $second;
            $beforeCommitUpdate = update_option(
                'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold',
                '0'
            );
            $beforeCommitError = $second->last_error;
            $GLOBALS['wpdb'] = $primary;
        }
        return null;
    });
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_stock_notification_retention', []);
    duo_check($beforeCommitUpdate === false
        && str_contains($beforeCommitError, 'row lock wait timeout'),
        'the retention source-row lock remains held through the COMMIT frontier');
    duo_check(woo_scheduler_option_value(
        'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold'
    ) === '30' && count($GLOBALS['wooSchedulerCron']) === 1,
        'a pre-COMMIT native update cannot be blessed through a stale RR snapshot');

    woo_scheduler_reset('yes', null);
    woo_scheduler_seed_cron(time() + 30);
    $primary = $GLOBALS['wpdb'];
    $second = (new FakeWpdb('wp_'))->setConnectionId(2);
    $second->shareDatabaseStateWith($primary)->shareAdvisoryLocksWith($primary);
    $gapInsert = null;
    $gapInsertError = '';
    $GLOBALS['wooSchedulerDuringRetentionController'] = static function (string $phase) use (
        $primary,
        $second,
        &$gapInsert,
        &$gapInsertError
    ): void {
        if ($phase !== 'clear' || $gapInsert !== null) {
            return;
        }
        $GLOBALS['wpdb'] = $second;
        $gapInsert = update_option(
            'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold',
            '30'
        );
        $gapInsertError = $second->last_error;
        $GLOBALS['wpdb'] = $primary;
    };
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_stock_notification_retention', []);
    duo_check($gapInsert === false && str_contains($gapInsertError, 'row lock wait timeout'),
        'an absent retention source holds its exact unique-index gap against native insertion');
    duo_check(woo_scheduler_option_value(
        'woocommerce_customer_stock_notifications_unverified_deletions_days_threshold',
        false
    ) === false && $GLOBALS['wooSchedulerCron'] === [],
        'blocked gap insertion cannot change the disabled retention projection');
    $GLOBALS['wooSchedulerDuringRetentionController'] = null;

    woo_scheduler_reset('yes', '30');
    woo_scheduler_put_option('unrelated_scheduler_neighbor', 'before');
    $primary = $GLOBALS['wpdb'];
    $second = (new FakeWpdb('wp_'))->setConnectionId(2);
    $second->shareDatabaseStateWith($primary)->shareAdvisoryLocksWith($primary);
    $neighborUpdate = null;
    $GLOBALS['wooSchedulerDuringRetentionController'] = static function (string $phase) use (
        $primary,
        $second,
        &$neighborUpdate
    ): void {
        if ($phase !== 'schedule' || $neighborUpdate !== null) {
            return;
        }
        $GLOBALS['wpdb'] = $second;
        $neighborUpdate = update_option('unrelated_scheduler_neighbor', 'after');
        $GLOBALS['wpdb'] = $primary;
    };
    $provider = woo_scheduler_provider($policy);
    $provider->invoke('reconcile_stock_notification_retention', []);
    duo_check($neighborUpdate === true
        && woo_scheduler_option_value('unrelated_scheduler_neighbor') === 'after',
        'exact source and cron index locks do not broaden into a hostile neighboring option lock');
    $GLOBALS['wooSchedulerDuringRetentionController'] = null;

    woo_scheduler_seed_cron(time() + 20);
    $provider->invoke('reconcile_stock_notification_retention', []);
    $retentionEvents = 0;
    foreach ($GLOBALS['wooSchedulerCron'] as $hooks) {
        $retentionEvents += count($hooks['customer_stock_notifications_daily'] ?? []);
    }
    duo_check_same(1, $retentionEvents,
        'duplicate exact retention events converge to one native daily event');

    foreach ([null, '', '0'] as $index => $disabledValue) {
        woo_scheduler_reset('yes', $disabledValue);
        woo_scheduler_seed_cron(time() + 30);
        $provider = woo_scheduler_provider($policy);
        $provider->invoke('reconcile_stock_notification_retention', []);
        duo_check_same([], $GLOBALS['wooSchedulerCron'],
            'absent, empty, and zero retention values each clear native daily work (' . $index . ')');
    }

    foreach (['-1', '1.5', '1e3', ' 1', '01', 'nan', '3650001'] as $hostile) {
        woo_scheduler_reset('yes', $hostile);
        $provider = woo_scheduler_provider($policy);
        duo_check(!isset($provider->capabilities()['reconcile_stock_notification_retention']),
            "retention value $hostile is an explicit atomic refusal outside reviewed whole days");
    }

    woo_scheduler_reset('yes', '30');
    woo_scheduler_seed_cron(time() + 40, ['foreign']);
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_stock_notification_retention']),
        'foreign same-hook cron arguments refuse before native clear can delete them');
    duo_check_same(0, $GLOBALS['wooSchedulerCronWrites'],
        'foreign retention topology refusal performs no cron mutation');

    foreach (['cron_schedule', 'cron_clear'] as $index => $failurePoint) {
        woo_scheduler_reset('yes', $failurePoint === 'cron_schedule' ? '30' : '0');
        if ($failurePoint === 'cron_clear') {
            woo_scheduler_seed_cron(time() + 50);
        }
        $provider = woo_scheduler_provider($policy);
        $GLOBALS['wooSchedulerFailPoint'] = $failurePoint;
        duo_check_throws(
            static fn() => $provider->invoke('reconcile_stock_notification_retention', []),
            RuntimeException::class,
            "$failurePoint is loud after the injected native cron mutation",
            'injected Woo scheduler failure'
        );
        $retry = $provider->invoke('reconcile_stock_notification_retention', []);
        duo_check(($retry['verified'] ?? false) === true,
            "$failurePoint residual cron state retries idempotently to convergence");
    }

    woo_scheduler_reset('yes', '30');
    $GLOBALS['wooSchedulerCron'][time() + 60]['unrelated'][0] = array_fill(0, 100001, []);
    $provider = woo_scheduler_provider($policy);
    duo_check(!isset($provider->capabilities()['reconcile_stock_notification_retention']),
        'hostile cron inventory is bounded before provider-side traversal can grow without limit');

    $artifactLock = json_decode((string) file_get_contents(
        $root . '/sandbox/conformance/artifacts.lock.json'
    ), true, 32, JSON_THROW_ON_ERROR);
    duo_check_same(
        'ba08c7fc58c98a11f22866269c5832d85c52b664806ec206036f09737ba21666',
        $artifactLock['plugins']['woocommerce']['11.0.0']['sha256'] ?? null,
        'official WooCommerce 11.0.0 scheduler artifact is exact-digest pinned'
    );
    duo_check_same(
        'da189b6616c610d15a2106f93151dab81b78f83e075bcefce221ac0d00b4fa21',
        $artifactLock['plugins']['woocommerce']['11.0.1']['sha256'] ?? null,
        'official WooCommerce 11.0.1 scheduler artifact is exact-digest pinned'
    );

    duo_check_summary('WooCommerce scheduler settings provider');
}
