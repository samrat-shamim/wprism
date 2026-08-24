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
 */
final class NativeRewriteEffects {
    private const PURGE_FLAG = 'should_delete_expired_transients';
    private const MARKER_OPTIONS = [
        'tribe_last_generate_rewrite_rules',
        'tribe_last_updated_option',
        'tribe_last_save_post',
    ];

    private function __construct(
        private readonly object $listener,
        private readonly object $listenerCache,
        private readonly object $globalCache,
        private readonly bool $purgePresent,
        private readonly mixed $purgeValue
    ) {}

    /** Return null when TEC is not loaded; partial/substituted TEC refuses. */
    public static function prepare(): ?self {
        $updated = self::hook_records('updated_option');
        $generate = self::hook_records('generate_rewrite_rules');
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

        self::assert_updated_option_callbacks($updated, $listener);
        self::assert_generate_callback($generate, $listener);
        self::assert_trigger_filters();
        foreach (self::MARKER_OPTIONS as $name) {
            self::assert_marker_option_hooks($name, $listener);
        }

        $purgePresent = self::call_function('tribe_isset_var', self::PURGE_FLAG);
        if (!is_bool($purgePresent)) {
            throw new \RuntimeException(
                'duo: native rewrite found malformed The Events Calendar purge-flag presence'
            );
        }
        $purgeValue = $purgePresent ? self::call_function('tribe_get_var', self::PURGE_FLAG) : null;
        return new self($listener, $listenerCache, $globalCache, $purgePresent, $purgeValue);
    }

    /** Re-prove callback/service identity, then restore the exact local flag. */
    public function restore(): void {
        $updated = self::hook_records('updated_option');
        $generate = self::hook_records('generate_rewrite_rules');
        $listener = self::listener_from_hooks($updated, $generate);
        self::assert_updated_option_callbacks($updated, $listener);
        self::assert_generate_callback($generate, $listener);
        self::assert_trigger_filters();
        foreach (self::MARKER_OPTIONS as $name) {
            self::assert_marker_option_hooks($name, $listener);
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
            || $listenerCache !== $this->listenerCache) {
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

    /** @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $records */
    private static function assert_updated_option_callbacks(array $records, object $listener): void {
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
            [$manager, 'update_options_cache', 3],
            [$listener, 'update_last_updated_option', 3],
            [$listener, 'update_last_save_post', 3],
            [$aggregator, 'action_purge_transients', 1],
            [$views, 'action_save_wplang', 3],
        ];
        foreach ($records as [$priority, $record]) {
            $matched = null;
            foreach ($expected as $index => [$object, $method, $accepted]) {
                if ($priority === 10
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

    private static function assert_marker_option_hooks(string $name, object $listener): void {
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
            if ($records === []) {
                continue;
            }
            if ($hookName === 'updated_option') {
                self::assert_updated_option_callbacks($records, $listener);
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
}
