<?php
declare(strict_types=1);

namespace Duo;

/**
 * Binds The Events Calendar's optional rewrite side effects in the fresh
 * native-action child.
 *
 * Free TEC 6.17.2/6.17.3 registers one Cache_Listener on
 * generate_rewrite_rules and two callbacks on updated_option
 * (common/src/Tribe/Cache_Listener.php, identical SHA-256
 * 14a63e60db2f047b7dd62fa708d464b170d87485227cc63583c989a45fcb248b).
 * Those callbacks persist three tribe_last_* options and set a request-local
 * shutdown flag. The rows are covered by the action's database checkpoint;
 * the flag is not. Binding the exact service/hook topology and restoring the
 * flag before child shutdown prevents a failed or successful Duo flush from
 * turning that local flag into an unreceipted site-wide transient purge.
 *
 * WooCommerce 11.0.1 normally adds four updated_option callbacks plus one
 * pre_update_option and two added_option callbacks while its container boots.
 * They return before mutation for each tribe_last_* name, but refusing them
 * would make the reviewed TEC rewrite recovery path unusable on an ordinary
 * co-install. The exact bootstrap/service sources are bound by the offline
 * artifact fixture (SHA-256 2f3a95ae78217be16fa1f272c1fad4d3faecfd02939041a861d65826bb3f4cb7,
 * c39f44ebd0928be1c3f3a5066422defa5623705dc44f440f4572595def5866b2,
 * a10ff8e2e5820deeb5a032cccfc2ffca09a5134e3e87e388e0262a89a8805234,
 * b4d1a6772b064de9be6a80750074b0a9e371514f58131a1701cad6cd52ccb8bf);
 * runtime admission additionally requires the canonical container and the
 * exact service objects installed in every callback. Request-conditional
 * settings tracking remains outside this closed topology.
 */
final class NativeRewriteEffects {
    private const PURGE_FLAG = 'should_delete_expired_transients';
    private const MARKER_OPTIONS = [
        'tribe_last_generate_rewrite_rules',
        'tribe_last_updated_option',
        'tribe_last_save_post',
    ];
    private const WOO_CONTAINER = 'Automattic\\WooCommerce\\Container';
    private const WOO_FEATURES = 'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController';
    private const WOO_SYNCHRONIZER =
        'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer';
    private const WOO_CUSTOM_ORDERS =
        'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController';

    private function __construct(
        private readonly object $listener,
        private readonly object $listenerCache,
        private readonly object $globalCache,
        private readonly ?object $wooContainer,
        private readonly ?object $wooFeatures,
        private readonly ?object $wooSynchronizer,
        private readonly ?object $wooCustomOrders,
        private readonly bool $purgePresent,
        private readonly mixed $purgeValue
    ) {}

    /** Return null when TEC is not loaded; partial/substituted TEC refuses. */
    public static function prepare(): ?self {
        $updated = self::hook_records('updated_option');
        $generate = self::hook_records('generate_rewrite_rules');
        $preUpdated = self::hook_records('pre_update_option');
        $added = self::hook_records('added_option');
        $tecVisible = class_exists('Tribe__Cache_Listener', false)
            || function_exists('tribe_cache')
            || self::contains_class_callback($updated, 'Tribe__Cache_Listener')
            || self::contains_class_callback($generate, 'Tribe__Cache_Listener');
        if (!$tecVisible) {
            return null;
        }
        foreach (['tribe', 'tribe_cache', 'tribe_isset_var', 'tribe_get_var', 'tribe_set_var', 'tribe_unset_var'] as $function) {
            if (!function_exists($function)) {
                throw new \RuntimeException(
                    'duo: native rewrite found an incomplete The Events Calendar cache-listener runtime'
                );
            }
        }
        if (!class_exists('Tribe__Cache_Listener', false)
            || !is_callable(['Tribe__Cache_Listener', 'instance'])) {
            throw new \RuntimeException(
                'duo: native rewrite found an incomplete The Events Calendar cache-listener runtime'
            );
        }

        $listener = self::listener_from_hooks($updated, $generate);
        try {
            $resolvedListener = self::call_static('Tribe__Cache_Listener', 'instance');
            $globalCache = self::call_function('tribe_cache');
            $containerCache = self::call_function('tribe', 'cache');
            $cacheProperty = new \ReflectionProperty('Tribe__Cache_Listener', 'cache');
            $listenerCache = $cacheProperty->getValue($listener);
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: native rewrite could not resolve The Events Calendar cache-listener services',
                0,
                $failure
            );
        }
        if ($resolvedListener !== $listener
            || !is_object($globalCache)
            || get_class($globalCache) !== 'Tribe__Cache'
            || !is_object($containerCache)
            || get_class($containerCache) !== 'Tribe__Cache'
            || $containerCache !== $globalCache
            || !is_object($listenerCache)
            || get_class($listenerCache) !== 'Tribe__Cache'
            || $listenerCache === $globalCache) {
            throw new \RuntimeException(
                'duo: native rewrite found substituted The Events Calendar cache-listener services'
            );
        }

        $woo = self::resolve_woo_services($updated, $preUpdated, $added);
        self::assert_updated_option_callbacks($updated, $listener, $woo);
        self::assert_generate_callback($generate, $listener);
        self::assert_trigger_filters();
        foreach (self::MARKER_OPTIONS as $name) {
            self::assert_marker_option_hooks($name, $listener, $woo);
        }

        $purgePresent = self::call_function('tribe_isset_var', self::PURGE_FLAG);
        if (!is_bool($purgePresent)) {
            throw new \RuntimeException(
                'duo: native rewrite found malformed The Events Calendar purge-flag presence'
            );
        }
        $purgeValue = $purgePresent ? self::call_function('tribe_get_var', self::PURGE_FLAG) : null;
        return new self(
            $listener,
            $listenerCache,
            $globalCache,
            $woo['container'] ?? null,
            $woo['features'] ?? null,
            $woo['synchronizer'] ?? null,
            $woo['custom_orders'] ?? null,
            $purgePresent,
            $purgeValue
        );
    }

    /** Re-prove callback/service identity, then restore the exact local flag. */
    public function restore(): void {
        $updated = self::hook_records('updated_option');
        $generate = self::hook_records('generate_rewrite_rules');
        $preUpdated = self::hook_records('pre_update_option');
        $added = self::hook_records('added_option');
        $listener = self::listener_from_hooks($updated, $generate);
        $woo = self::resolve_woo_services($updated, $preUpdated, $added);
        self::assert_updated_option_callbacks($updated, $listener, $woo);
        self::assert_generate_callback($generate, $listener);
        self::assert_trigger_filters();
        foreach (self::MARKER_OPTIONS as $name) {
            self::assert_marker_option_hooks($name, $listener, $woo);
        }
        try {
            $resolvedListener = self::call_static('Tribe__Cache_Listener', 'instance');
            $globalCache = self::call_function('tribe_cache');
            $containerCache = self::call_function('tribe', 'cache');
            $cacheProperty = new \ReflectionProperty('Tribe__Cache_Listener', 'cache');
            $listenerCache = $cacheProperty->getValue($listener);
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: native rewrite could not re-prove The Events Calendar cache-listener services',
                0,
                $failure
            );
        }
        if ($listener !== $this->listener
            || $resolvedListener !== $this->listener
            || $globalCache !== $this->globalCache
            || $containerCache !== $this->globalCache
            || $listenerCache !== $this->listenerCache
            || ($woo['container'] ?? null) !== $this->wooContainer
            || ($woo['features'] ?? null) !== $this->wooFeatures
            || ($woo['synchronizer'] ?? null) !== $this->wooSynchronizer
            || ($woo['custom_orders'] ?? null) !== $this->wooCustomOrders) {
            throw new \RuntimeException(
                'duo: native rewrite found The Events Calendar cache-listener service drift'
            );
        }

        if ($this->purgePresent) {
            self::call_function('tribe_set_var', self::PURGE_FLAG, $this->purgeValue);
        } else {
            self::call_function('tribe_unset_var', self::PURGE_FLAG);
        }
        $present = self::call_function('tribe_isset_var', self::PURGE_FLAG);
        if (!is_bool($present)
            || $present !== $this->purgePresent
            || ($present && self::call_function('tribe_get_var', self::PURGE_FLAG) !== $this->purgeValue)) {
            throw new \RuntimeException(
                'duo: native rewrite could not restore The Events Calendar purge-flag preimage'
            );
        }
    }

    /** @return list<array{0:int,1:array{function:mixed,accepted_args:int}}> */
    private static function hook_records(string $name): array {
        global $wp_filter;
        $hook = is_array($wp_filter ?? null) ? ($wp_filter[$name] ?? null) : null;
        if ($hook === null) {
            return [];
        }
        if (!is_object($hook)
            || get_class($hook) !== 'WP_Hook'
            || !is_array($hook->callbacks ?? null)) {
            throw new \RuntimeException('duo: native rewrite found malformed WordPress hook topology');
        }
        $records = [];
        foreach ($hook->callbacks as $priority => $atPriority) {
            if (!is_int($priority) || !is_array($atPriority)) {
                throw new \RuntimeException('duo: native rewrite found malformed WordPress hook topology');
            }
            foreach ($atPriority as $record) {
                if (!is_array($record)
                    || array_keys($record) !== ['function', 'accepted_args']
                    || !is_int($record['accepted_args'] ?? null)) {
                    throw new \RuntimeException('duo: native rewrite found malformed WordPress hook topology');
                }
                $records[] = [$priority, $record];
            }
        }
        return $records;
    }

    /** @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $records */
    private static function contains_class_callback(array $records, string $class): bool {
        foreach ($records as [, $record]) {
            $callback = $record['function'];
            if (is_array($callback)
                && is_object($callback[0] ?? null)
                && get_class($callback[0]) === $class) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $updated
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $generate
     */
    private static function listener_from_hooks(array $updated, array $generate): object {
        $listeners = [];
        foreach (array_merge($updated, $generate) as [, $record]) {
            $callback = $record['function'];
            if (is_array($callback)
                && is_object($callback[0] ?? null)
                && get_class($callback[0]) === 'Tribe__Cache_Listener') {
                $listeners[spl_object_id($callback[0])] = $callback[0];
            }
        }
        if (count($listeners) !== 1) {
            throw new \RuntimeException(
                'duo: native rewrite found incomplete or substituted The Events Calendar listener callbacks'
            );
        }
        return reset($listeners);
    }

    /** @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $records */
    private static function assert_generate_callback(array $records, object $listener): void {
        $matches = 0;
        foreach ($records as [$priority, $record]) {
            $callback = $record['function'];
            if (is_array($callback)
                && is_object($callback[0] ?? null)
                && get_class($callback[0]) === 'Tribe__Cache_Listener') {
                if ($priority !== 10
                    || $record['accepted_args'] !== 1
                    || $callback !== [$listener, 'generate_rewrite_rules']) {
                    throw new \RuntimeException(
                        'duo: native rewrite found a substituted The Events Calendar generation callback'
                    );
                }
                $matches++;
            }
        }
        if ($matches !== 1) {
            throw new \RuntimeException(
                'duo: native rewrite found incomplete The Events Calendar generation callbacks'
            );
        }
    }

    /**
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $records
     * @param ?array{container:object,features:object,synchronizer:object,custom_orders:object} $woo
     */
    private static function assert_updated_option_callbacks(
        array $records,
        object $listener,
        ?array $woo
    ): void {
        if (!class_exists('Tribe__Settings_Manager', false)
            || !is_callable(['Tribe__Settings_Manager', 'instance'])
            || !class_exists('Tribe__Events__Aggregator', false)
            || !is_callable(['Tribe__Events__Aggregator', 'instance'])
            || !class_exists('Tribe\\Events\\Views\\V2\\Hooks', false)) {
            throw new \RuntimeException(
                'duo: native rewrite found incomplete The Events Calendar updated-option services'
            );
        }
        try {
            $manager = \Tribe__Settings_Manager::instance();
            $aggregator = \Tribe__Events__Aggregator::instance();
            $views = self::call_function('tribe', 'Tribe\\Events\\Views\\V2\\Hooks');
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: native rewrite could not resolve The Events Calendar updated-option services',
                0,
                $failure
            );
        }
        foreach ([
            [$manager, 'Tribe__Settings_Manager'],
            [$aggregator, 'Tribe__Events__Aggregator'],
            [$views, 'Tribe\\Events\\Views\\V2\\Hooks'],
        ] as [$service, $class]) {
            if (!is_object($service) || get_class($service) !== $class) {
                throw new \RuntimeException(
                    'duo: native rewrite found substituted The Events Calendar updated-option services'
                );
            }
        }
        $expected = [
            [$manager, 'update_options_cache', 10, 3],
            [$listener, 'update_last_updated_option', 10, 3],
            [$listener, 'update_last_save_post', 10, 3],
            [$aggregator, 'action_purge_transients', 10, 1],
            [$views, 'action_save_wplang', 10, 3],
        ];
        if ($woo !== null) {
            $expected = array_merge($expected, [
                [$woo['features'], 'process_updated_option', 999, 3],
                [$woo['synchronizer'], 'process_updated_option', 999, 3],
                [$woo['custom_orders'], 'process_updated_option', 999, 3],
                [$woo['custom_orders'], 'process_updated_option_fts_index', 999, 3],
            ]);
        }
        foreach ($records as [$priority, $record]) {
            $matched = null;
            foreach ($expected as $index => [$object, $method, $expectedPriority, $accepted]) {
                if ($priority === $expectedPriority
                    && $record['accepted_args'] === $accepted
                    && $record['function'] === [$object, $method]) {
                    $matched = $index;
                    break;
                }
            }
            if ($matched === null) {
                throw new \RuntimeException(
                    'duo: native rewrite found extended or substituted updated-option callbacks'
                );
            }
            unset($expected[$matched]);
        }
        if ($expected !== []) {
            throw new \RuntimeException(
                'duo: native rewrite found incomplete The Events Calendar updated-option callbacks'
            );
        }
    }

    private static function assert_trigger_filters(): void {
        foreach ([
            'tribe_cache_last_occurrence_option_triggers',
            'tribe_cache_last_occurrence_option_triggers:updated_option',
            'tribe_cache_last_occurrence_option_triggers:save_post',
        ] as $name) {
            if (self::hook_records($name) !== []) {
                throw new \RuntimeException(
                    'duo: native rewrite found extended The Events Calendar cache-listener trigger filters'
                );
            }
        }
    }

    /** @param ?array{container:object,features:object,synchronizer:object,custom_orders:object} $woo */
    private static function assert_marker_option_hooks(string $name, object $listener, ?array $woo): void {
        foreach ([
            'sanitize_option_' . $name,
            'pre_option_' . $name,
            'pre_option',
            'pre_wp_load_alloptions',
            'pre_cache_alloptions',
            'alloptions',
            'default_option_' . $name,
            'option_' . $name,
            'pre_update_option_' . $name,
            'pre_update_option',
            'update_option',
            'wp_autoload_values_to_autoload',
            'wp_default_autoload_value',
            'wp_max_autoloaded_option_size',
            'update_option_' . $name,
            'updated_option',
            'add_option',
            'add_option_' . $name,
            'added_option',
        ] as $hookName) {
            $records = self::hook_records($hookName);
            if ($hookName === 'updated_option') {
                self::assert_updated_option_callbacks($records, $listener, $woo);
                continue;
            }
            if ($hookName === 'pre_update_option' && $woo !== null) {
                self::assert_woo_option_callbacks($hookName, $records, $woo);
                continue;
            }
            if ($hookName === 'added_option' && $woo !== null) {
                self::assert_woo_option_callbacks($hookName, $records, $woo);
                continue;
            }
            if ($records === []) {
                continue;
            }
            if ($hookName === 'pre_option'
                && count($records) === 1
                && self::is_harbor_pre_option_callback($records[0])) {
                continue;
            }
            if ($hookName === 'wp_default_autoload_value'
                && count($records) === 1
                && self::is_wordpress_default_autoload_callback($records[0])) {
                continue;
            }
            throw new \RuntimeException(
                "duo: native rewrite found extended marker option topology for '$name'"
            );
        }
    }

    /**
     * Resolve the exact normal Woo 11.0.1 service graph whenever any part of
     * its option topology is visible. Partial boot, a replaced global
     * container, or a same-class foreign callback is never treated as absence.
     *
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $updated
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $preUpdated
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $added
     * @return ?array{container:object,features:object,synchronizer:object,custom_orders:object}
     */
    private static function resolve_woo_services(array $updated, array $preUpdated, array $added): ?array {
        $records = array_merge($updated, $preUpdated, $added);
        $visible = function_exists('wc_get_container')
            || array_key_exists('wc_container', $GLOBALS)
            || class_exists(self::WOO_CONTAINER, false)
            || class_exists(self::WOO_FEATURES, false)
            || class_exists(self::WOO_SYNCHRONIZER, false)
            || class_exists(self::WOO_CUSTOM_ORDERS, false);
        foreach ([self::WOO_FEATURES, self::WOO_SYNCHRONIZER, self::WOO_CUSTOM_ORDERS] as $class) {
            $visible = $visible || self::contains_class_callback($records, $class);
        }
        if (!$visible) {
            return null;
        }
        if (!function_exists('wc_get_container')
            || !array_key_exists('wc_container', $GLOBALS)
            || !class_exists(self::WOO_CONTAINER, false)
            || !class_exists(self::WOO_FEATURES, false)
            || !class_exists(self::WOO_SYNCHRONIZER, false)
            || !class_exists(self::WOO_CUSTOM_ORDERS, false)) {
            throw new \RuntimeException(
                'duo: native rewrite found an incomplete WooCommerce option-callback runtime'
            );
        }
        try {
            $container = $GLOBALS['wc_container'];
            if (!is_object($container) || get_class($container) !== self::WOO_CONTAINER) {
                throw new \RuntimeException(
                    'duo: native rewrite found substituted WooCommerce option-callback services'
                );
            }
            if (self::call_function('wc_get_container') !== $container) {
                throw new \RuntimeException(
                    'duo: native rewrite found substituted WooCommerce option-callback services'
                );
            }
            $features = self::call_object($container, 'get', self::WOO_FEATURES);
            $synchronizer = self::call_object($container, 'get', self::WOO_SYNCHRONIZER);
            $customOrders = self::call_object($container, 'get', self::WOO_CUSTOM_ORDERS);
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'duo: native rewrite could not resolve WooCommerce option-callback services',
                0,
                $failure
            );
        }
        foreach ([
            [$container, self::WOO_CONTAINER],
            [$features, self::WOO_FEATURES],
            [$synchronizer, self::WOO_SYNCHRONIZER],
            [$customOrders, self::WOO_CUSTOM_ORDERS],
        ] as [$service, $class]) {
            if (!is_object($service) || get_class($service) !== $class) {
                throw new \RuntimeException(
                    'duo: native rewrite found substituted WooCommerce option-callback services'
                );
            }
        }
        return [
            'container' => $container,
            'features' => $features,
            'synchronizer' => $synchronizer,
            'custom_orders' => $customOrders,
        ];
    }

    /**
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $records
     * @param array{container:object,features:object,synchronizer:object,custom_orders:object} $woo
     */
    private static function assert_woo_option_callbacks(string $hookName, array $records, array $woo): void {
        $expected = match ($hookName) {
            'pre_update_option' => [
                [$woo['custom_orders'], 'process_pre_update_option', 999, 3],
            ],
            'added_option' => [
                [$woo['features'], 'process_added_option', 999, 3],
                [$woo['synchronizer'], 'process_added_option', 999, 2],
            ],
            default => throw new \LogicException('duo: unknown WooCommerce option callback family'),
        };
        foreach ($records as [$priority, $record]) {
            $matched = null;
            foreach ($expected as $index => [$object, $method, $expectedPriority, $accepted]) {
                if ($priority === $expectedPriority
                    && $record['accepted_args'] === $accepted
                    && $record['function'] === [$object, $method]) {
                    $matched = $index;
                    break;
                }
            }
            if ($matched === null) {
                throw new \RuntimeException(
                    "duo: native rewrite found extended or substituted WooCommerce $hookName callbacks"
                );
            }
            unset($expected[$matched]);
        }
        if ($expected !== []) {
            throw new \RuntimeException(
                "duo: native rewrite found incomplete WooCommerce $hookName callbacks"
            );
        }
    }

    /** @param array{0:int,1:array{function:mixed,accepted_args:int}} $tuple */
    private static function is_wordpress_default_autoload_callback(array $tuple): bool {
        [$priority, $record] = $tuple;
        return $priority === 5
            && $record['accepted_args'] === 4
            && $record['function'] === 'wp_filter_default_autoload_value_via_option_size'
            && function_exists('wp_filter_default_autoload_value_via_option_size');
    }

    /** @param array{0:int,1:array{function:mixed,accepted_args:int}} $tuple */
    private static function is_harbor_pre_option_callback(array $tuple): bool {
        [$priority, $record] = $tuple;
        $callback = $record['function'];
        if ($priority !== 10
            || $record['accepted_args'] !== 3
            || !is_array($callback)
            || !is_object($callback[0] ?? null)
            || get_class($callback[0]) !== 'TEC\\Common\\Integrations\\Harbor\\PUE'
            || ($callback[1] ?? null) !== 'filter_pre_get_option') {
            return false;
        }
        try {
            return self::call_function('tribe', 'TEC\\Common\\Integrations\\Harbor\\PUE') === $callback[0];
        } catch (\Throwable) {
            return false;
        }
    }

    private static function call_function(string $name, mixed ...$args): mixed {
        if (!function_exists($name)) {
            throw new \RuntimeException('duo: native rewrite lost a proven runtime function');
        }
        return \Closure::fromCallable($name)(...$args);
    }

    private static function call_static(string $class, string $method): mixed {
        if (!is_callable([$class, $method])) {
            throw new \RuntimeException('duo: native rewrite lost a proven runtime service');
        }
        return \Closure::fromCallable([$class, $method])();
    }

    private static function call_object(mixed $object, string $method, mixed ...$args): mixed {
        if (!is_object($object) || !is_callable([$object, $method])) {
            throw new \RuntimeException('duo: native rewrite lost a proven runtime service');
        }
        return \Closure::fromCallable([$object, $method])(...$args);
    }
}
