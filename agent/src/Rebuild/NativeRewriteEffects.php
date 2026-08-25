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
 *
 * The same fresh process also executes exact rewrite interpreters from TEC,
 * Yoast 28.3 and Polylang 3.8.6. Their reviewed sources are respectively
 * 2f447a4120a349d5f596c834192b17a5b911c6c94e8a62cfaee58af89cc86aab,
 * 0e198faca151aeca66680e916a038eab5c264f7d0ee6472d8f07d1845d0a7b9a,
 * 3b07ec0af1f94269b2a5a98bba078edbee73e1697aeeed119ae12ff4a3ca7553,
 * 7ed2774c6c73c514c64fc1a4b6533e41bacc8278a54785e8246492ce597bfdc5,
 * 364cf0f52c51aeba8702e5108e2ddc66c35dc7b93bb4f694a3c35f862ed25856,
 * 5cadce6a89e87278bdd021d8f049d9c4e511acecc6c6366808740f04027d2dc0,
 * and cc15a8ffa92ffb045cd5c5ef350688c7b2e36c6b43ceb9c68bdf6f8c5ed68f98.
 * Bind the exact runtime objects and dynamic Polylang type roster before the
 * first native call; arbitrary pll_* callbacks can execute undeclared code
 * while still returning byte-valid rules, so they are a pre-mutation refusal.
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
        private readonly ?object $listener,
        private readonly ?object $listenerCache,
        private readonly ?object $globalCache,
        private readonly ?object $wooContainer,
        private readonly ?object $wooFeatures,
        private readonly ?object $wooSynchronizer,
        private readonly ?object $wooCustomOrders,
        /** @var array<string,mixed> */
        private readonly array $rewriteTopology,
        private readonly bool $purgePresent,
        private readonly mixed $purgeValue
    ) {}

    /** Bind every admitted rewrite interpreter; partial plugin runtimes refuse. */
    public static function prepare(): self {
        $updated = self::hook_records('updated_option');
        $generate = self::hook_records('generate_rewrite_rules');
        $preUpdated = self::hook_records('pre_update_option');
        $added = self::hook_records('added_option');
        $tec = self::resolve_tec_services($updated, $generate);
        $listener = $tec['listener'] ?? null;
        $woo = self::resolve_woo_services($updated, $preUpdated, $added);
        self::assert_updated_option_callbacks($updated, $listener, $woo);
        if ($listener !== null) {
            self::assert_generate_callback($generate, $listener);
            self::assert_trigger_filters();
            foreach (self::MARKER_OPTIONS as $name) {
                self::assert_marker_option_hooks($name, $listener, $woo);
            }
        }
        $rewriteTopology = self::rewrite_topology($listener, $woo);
        return new self(
            $listener,
            $tec['listener_cache'] ?? null,
            $tec['global_cache'] ?? null,
            $woo['container'] ?? null,
            $woo['features'] ?? null,
            $woo['synchronizer'] ?? null,
            $woo['custom_orders'] ?? null,
            $rewriteTopology,
            $tec['purge_present'] ?? false,
            $tec['purge_value'] ?? null
        );
    }

    /** Re-prove callback/service identity, then restore the exact local flag. */
    public function restore(): void {
        $topologyFailure = null;
        try {
            $updated = self::hook_records('updated_option');
            $generate = self::hook_records('generate_rewrite_rules');
            $preUpdated = self::hook_records('pre_update_option');
            $added = self::hook_records('added_option');
            $tec = self::resolve_tec_services($updated, $generate);
            $listener = $tec['listener'] ?? null;
            $woo = self::resolve_woo_services($updated, $preUpdated, $added);
            self::assert_updated_option_callbacks($updated, $listener, $woo);
            if ($listener !== null) {
                self::assert_generate_callback($generate, $listener);
                self::assert_trigger_filters();
                foreach (self::MARKER_OPTIONS as $name) {
                    self::assert_marker_option_hooks($name, $listener, $woo);
                }
            }
            $rewriteTopology = self::rewrite_topology($listener, $woo);
            if ($listener !== $this->listener
                || ($tec['listener_cache'] ?? null) !== $this->listenerCache
                || ($tec['global_cache'] ?? null) !== $this->globalCache
                || ($woo['container'] ?? null) !== $this->wooContainer
                || ($woo['features'] ?? null) !== $this->wooFeatures
                || ($woo['synchronizer'] ?? null) !== $this->wooSynchronizer
                || ($woo['custom_orders'] ?? null) !== $this->wooCustomOrders
                || $rewriteTopology !== $this->rewriteTopology) {
                throw new \RuntimeException(
                    'duo: native rewrite found The Events Calendar cache-listener service drift'
                );
            }
        } catch (\Throwable $failure) {
            $topologyFailure = $failure;
        }

        $flagFailure = null;
        if ($this->listener !== null) {
            try {
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
            } catch (\Throwable $failure) {
                $flagFailure = $failure;
            }
        }

        if ($topologyFailure !== null && $flagFailure !== null) {
            throw new \RuntimeException(
                'duo: native rewrite cleanup found topology drift and purge-flag restoration failure; topology='
                . get_class($topologyFailure) . ':' . substr(hash('sha256', $topologyFailure->getMessage()), 0, 16)
                . '; flag=' . get_class($flagFailure) . ':'
                . substr(hash('sha256', $flagFailure->getMessage()), 0, 16),
                0,
                $topologyFailure
            );
        }
        if ($topologyFailure !== null) {
            throw $topologyFailure;
        }
        if ($flagFailure !== null) {
            throw $flagFailure;
        }
    }

    /**
     * Project Yoast 28.3's exact option_rewrite_rules callback without calling
     * plugin code a second time. Durable rewrite_rules deliberately excludes
     * the singleton's bounded dynamic maps; the effective read prepends and
     * appends them (inc/class-yoast-dynamic-rewrites.php, SHA above).
     */
    public function expected_effective_rules(mixed $stored): mixed {
        $yoast = $this->rewriteTopology['yoast'] ?? null;
        if ($yoast === null || !is_array($stored)) {
            return $stored;
        }
        if (!is_array($yoast)
            || !is_array($yoast['top'] ?? null)
            || !is_array($yoast['bottom'] ?? null)) {
            throw new \LogicException('duo: native rewrite lost its proven Yoast projection');
        }
        return array_merge($yoast['top'], $stored, $yoast['bottom']);
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
     * @return ?array{listener:object,listener_cache:object,global_cache:object,purge_present:bool,purge_value:mixed}
     */
    private static function resolve_tec_services(array $updated, array $generate): ?array {
        $tecVisible = class_exists('Tribe__Events__Rewrite', false)
            || self::contains_class_callback($updated, 'Tribe__Cache_Listener')
            || self::contains_class_callback($generate, 'Tribe__Cache_Listener')
            || self::hook_records('tribe_pre_rewrite') !== []
            || self::hook_records('tribe_events_pre_rewrite') !== []
            || self::hook_records('tribe_events_rewrite_rules_custom') !== [];
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
        $purgePresent = self::call_function('tribe_isset_var', self::PURGE_FLAG);
        if (!is_bool($purgePresent)) {
            throw new \RuntimeException(
                'duo: native rewrite found malformed The Events Calendar purge-flag presence'
            );
        }
        return [
            'listener' => $listener,
            'listener_cache' => $listenerCache,
            'global_cache' => $globalCache,
            'purge_present' => $purgePresent,
            'purge_value' => $purgePresent ? self::call_function('tribe_get_var', self::PURGE_FLAG) : null,
        ];
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
     * @param ?array{container:object,features:object,synchronizer:object,custom_orders:object} $woo
     * @return array<string,mixed>
     */
    private static function rewrite_topology(?object $listener, ?array $woo): array {
        global $wp_rewrite;
        if (!is_object($wp_rewrite)) {
            throw new \RuntimeException('duo: native rewrite found a malformed WordPress rewrite runtime');
        }

        $tecRewrite = null;
        $aggregator = null;
        $views = null;
        $viewsRewrite = null;
        $kitchenSink = null;
        $manager = null;
        $viewRegistrations = [];
        $qrRoutes = null;
        if ($listener !== null) {
            $tecRewrite = self::exact_static_service(
                'Tribe__Events__Rewrite',
                'instance',
                'generate_rewrite_rules',
                'filter_generate'
            );
            $aggregator = self::exact_static_service(
                'Tribe__Events__Aggregator',
                'instance',
                'tribe_events_pre_rewrite',
                'action_endpoint_configuration'
            );
            $views = self::exact_container_service(
                'Tribe\\Events\\Views\\V2\\Hooks',
                'tribe_events_pre_rewrite',
                'on_tribe_events_pre_rewrite'
            );
            // Hooks::filter_rewrite_i18n_slugs_raw() resolves this exact,
            // stateless service at call time. Its reviewed 6.17.2/6.17.3
            // implementation only transforms the rule bases later covered by
            // rewrite.flush's durable/runtime hashes; it owns no companion
            // storage or request-local state.
            $viewsRewrite = self::exact_lazy_container_value_service(
                'Tribe\\Events\\Views\\V2\\Rewrite',
                'filter_raw_i18n_slugs'
            );
            // Hooks::on_tribe_events_pre_rewrite() resolves this second
            // singleton at call time. Binding only the outer Hooks callback
            // would let a container override execute foreign code and return
            // byte-valid rules (identical 6.17.2/6.17.3 sources).
            $kitchenSink = self::exact_container_value_service(
                'Tribe\\Events\\Views\\V2\\Kitchen_Sink',
                'generate_rules'
            );
            $manager = self::resolve_view_manager();
            $viewRegistrations = self::view_registrations($manager);
            $qrRoutes = self::exact_container_service(
                'TEC\\Events\\QR\\Routes',
                'tribe_events_pre_rewrite',
                'add_qr_rules'
            );
            self::assert_tec_inner_topology($qrRoutes, $views);
        }
        $yoast = self::resolve_yoast($wp_rewrite);
        $polylang = self::resolve_polylang();
        $corePermastructs = self::assert_core_generation_topology($wp_rewrite, $polylang);
        self::assert_rewrite_rules_option_hooks($listener, $woo, $yoast);

        $generateExpected = [];
        if ($listener !== null) {
            $generateExpected = [
                [$listener, 'generate_rewrite_rules', 10, 1],
                [$tecRewrite, 'filter_generate', 10, 1],
            ];
        }
        self::assert_exact_hook('generate_rewrite_rules', $generateExpected);

        $rewriteArrayExpected = [];
        if ($listener !== null) {
            $rewriteArrayExpected[] = [$tecRewrite, 'filter_rewrite_rules_array', 25, 1];
        }
        if ($woo !== null) {
            if (!function_exists('wc_fix_rewrite_rules')) {
                throw new \RuntimeException(
                    'duo: native rewrite found an incomplete WooCommerce rewrite runtime'
                );
            }
            $rewriteArrayExpected[] = ['wc_fix_rewrite_rules', null, 10, 1];
        }
        if ($polylang !== null) {
            $rewriteArrayExpected[] = [$polylang['sitemaps'], 'rewrite_rules', 10, 1];
            $rewriteArrayExpected[] = [$polylang['links'], 'rewrite_rules', 10, 1];
        }
        self::assert_exact_hook('rewrite_rules_array', $rewriteArrayExpected);

        $eventsExpected = [];
        if ($listener !== null) {
            $eventsExpected = [
                [$tecRewrite, 'generate_core_rules', 10, 1],
                [$aggregator, 'action_endpoint_configuration', 10, 1],
                [$views, 'on_tribe_events_pre_rewrite', 10, 1],
                [$qrRoutes, 'add_qr_rules', 10, 1],
            ];
            foreach ($viewRegistrations as $registration) {
                $eventsExpected[] = [$registration, 'filter_add_routes', 5, 1];
            }
        }
        // Common 6.17.2/6.17.3 defines Tribe__Deprecation::instance(), but no
        // normal free-plugin boot path resolves it. The exact live WP-CLI
        // roster is therefore empty. Resolving that opt-in singleton here
        // would itself mutate both old/new rewrite hooks and would turn an
        // extension-only diagnostic service into an admitted native effect.
        self::assert_exact_hook('tribe_pre_rewrite', []);
        self::assert_exact_hook('tribe_events_pre_rewrite', $eventsExpected);
        self::assert_exact_hook('tribe_events_rewrite_rules_custom', []);

        if ($polylang !== null) {
            foreach ($polylang['types'] as $type) {
                self::assert_exact_hook($type . '_rewrite_rules', [
                    [$polylang['links'], 'rewrite_rules', 10, 1],
                ]);
            }
        }

        return [
            'tec_rewrite' => $tecRewrite,
            'aggregator' => $aggregator,
            'views' => $views,
            'views_rewrite' => $viewsRewrite === null ? null : [
                'class' => get_class($viewsRewrite),
                'method' => 'filter_raw_i18n_slugs',
                'stateless' => true,
            ],
            'kitchen_sink' => $kitchenSink,
            'view_manager' => $manager,
            'view_registrations' => $viewRegistrations,
            'qr_routes' => $qrRoutes,
            'yoast' => $yoast,
            'polylang' => $polylang,
            'core_permastructs' => $corePermastructs,
        ];
    }

    /**
     * @param ?array{container:object,features:object,synchronizer:object,custom_orders:object} $woo
     * @param ?array{service:object,state:string,top:array<string,string>,bottom:array<string,string>} $yoast
     */
    private static function assert_rewrite_rules_option_hooks(
        ?object $listener,
        ?array $woo,
        ?array $yoast
    ): void {
        foreach ([
            'sanitize_option_rewrite_rules',
            'pre_option_rewrite_rules',
            'pre_option',
            'pre_wp_load_alloptions',
            'pre_cache_alloptions',
            'alloptions',
            'default_option_rewrite_rules',
            'option_rewrite_rules',
            'pre_update_option_rewrite_rules',
            'pre_update_option',
            'update_option',
            'wp_autoload_values_to_autoload',
            'wp_default_autoload_value',
            'wp_max_autoloaded_option_size',
            'update_option_rewrite_rules',
            'updated_option',
            'add_option',
            'add_option_rewrite_rules',
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
            if ($hookName === 'sanitize_option_rewrite_rules' && $yoast !== null) {
                self::assert_exact_hook($hookName, [
                    [$yoast['service'], 'sanitize_rewrite_rules_option', 10, 1],
                ]);
                continue;
            }
            if ($hookName === 'option_rewrite_rules' && $yoast !== null) {
                self::assert_exact_hook($hookName, [
                    [$yoast['service'], 'filter_rewrite_rules_option', 10, 1],
                ]);
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
                'duo: native rewrite found extended rewrite_rules option topology'
            );
        }
    }

    private static function exact_static_service(
        string $class,
        string $factory,
        string $hookName,
        string $method
    ): object {
        $service = self::one_exact_class_callback($hookName, $class, $method);
        if (!class_exists($class, false) || !is_callable([$class, $factory])) {
            throw new \RuntimeException('duo: native rewrite found an incomplete plugin rewrite service');
        }
        try {
            $resolved = self::call_static($class, $factory);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: native rewrite could not resolve a plugin rewrite service', 0, $failure);
        }
        if (!is_object($resolved) || get_class($resolved) !== $class || $resolved !== $service) {
            throw new \RuntimeException('duo: native rewrite found a substituted plugin rewrite service');
        }
        return $service;
    }

    private static function exact_container_service(string $class, string $hookName, string $method): object {
        $service = self::one_exact_class_callback($hookName, $class, $method);
        if (!class_exists($class, false)) {
            throw new \RuntimeException('duo: native rewrite found an incomplete TEC rewrite service');
        }
        try {
            $resolved = self::call_function('tribe', $class);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: native rewrite could not resolve a TEC rewrite service', 0, $failure);
        }
        if (!is_object($resolved) || get_class($resolved) !== $class || $resolved !== $service) {
            throw new \RuntimeException('duo: native rewrite found a substituted TEC rewrite service');
        }
        return $service;
    }

    private static function exact_container_value_service(string $class, string $method): object {
        if (!class_exists($class, false)) {
            throw new \RuntimeException('duo: native rewrite found an incomplete TEC rewrite service');
        }
        try {
            $resolved = self::call_function('tribe', $class);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: native rewrite could not resolve a TEC rewrite service', 0, $failure);
        }
        if (!is_object($resolved)
            || get_class($resolved) !== $class
            || !is_callable([$resolved, $method])) {
            throw new \RuntimeException('duo: native rewrite found a substituted TEC rewrite service');
        }
        return $resolved;
    }

    /**
     * Views V2 Hooks resolves Rewrite only when its raw-slug filter runs; the
     * exact 6.17.2/6.17.3 class is therefore absent from the normal WP-CLI
     * boot class table. Resolve through TEC's already-bound container first,
     * then prove that the ordinary lazy autoload produced one stable exact
     * service before native generation can execute it.
     */
    private static function exact_lazy_container_value_service(string $class, string $method): object {
        try {
            $resolved = self::call_function('tribe', $class);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: native rewrite could not resolve a lazy TEC rewrite service', 0, $failure);
        }
        self::assert_stateless_lazy_container_value($resolved, $class, $method, false);
        try {
            $reresolved = self::call_function('tribe', $class);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: native rewrite could not re-resolve a lazy TEC rewrite service', 0, $failure);
        }
        self::assert_stateless_lazy_container_value($reresolved, $class, $method, true);
        return $resolved;
    }

    private static function assert_stateless_lazy_container_value(
        mixed $resolved,
        string $class,
        string $method,
        bool $reresolution
    ): void {
        $phase = $reresolution ? ' during re-resolution' : '';
        if (!class_exists($class, false)
            || !is_object($resolved)
            || get_class($resolved) !== $class
            || !is_callable([$resolved, $method])) {
            throw new \RuntimeException('duo: native rewrite found a substituted lazy TEC rewrite service' . $phase);
        }
        try {
            $properties = (new \ReflectionClass($resolved))->getProperties();
            $dynamicState = get_object_vars($resolved);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: native rewrite could not inspect a lazy TEC rewrite service' . $phase, 0, $failure);
        }
        if ($properties !== [] || $dynamicState !== []) {
            throw new \RuntimeException('duo: native rewrite found a stateful lazy TEC rewrite service' . $phase);
        }
    }

    private static function assert_tec_inner_topology(object $qrRoutes, object $views): void {
        foreach ([
            'tribe_cache_expiration',
            'tribe_events_category_slug',
            'tribe_events_tag_slug',
            'tribe_events_rewrite_i18n_domains',
            'tribe_events_rewrite_base_slugs',
            'tribe_events_rewrite_i18n_languages',
            'tribe_events_rewrite_i18n_slugs',
            'tec_events_qr_route_base',
            'tec_events_qr_route_prefix',
            'deprecated_function_run',
            'deprecated_function_trigger_error',
        ] as $hookName) {
            self::assert_exact_hook($hookName, []);
        }
        self::assert_exact_hook('tribe_events_rewrite_i18n_slugs_raw', [
            [$views, 'filter_rewrite_i18n_slugs_raw', 50, 2],
        ]);

        try {
            $base = (new \ReflectionProperty('TEC\\Events\\QR\\Routes', 'route_base'))->getValue($qrRoutes);
            $prefix = (new \ReflectionProperty('TEC\\Events\\QR\\Routes', 'route_prefix'))->getValue($qrRoutes);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: native rewrite could not inspect the TEC QR route state', 0, $failure);
        }
        if (($base !== null && $base !== 'events') || ($prefix !== null && $prefix !== 'qr')) {
            throw new \RuntimeException('duo: native rewrite found substituted TEC QR route state');
        }
    }

    /**
     * WP_Rewrite 6.9.2/7.0.3/7.1 calls the seven fixed filters and one
     * `{permastruct}_rewrite_rules` filter for every exact runtime key. The
     * runtime roster is the only finite same-attempt authority; accepting a
     * callback on an unproved dynamic name would execute extension code before
     * the durable rewrite receipt can distinguish its side effects.
     *
     * @param ?array{runtime:object,links:object,sitemaps:object,types:list<string>,types_hash:string} $polylang
     */
    private static function assert_core_generation_topology(object $wpRewrite, ?array $polylang): string {
        if (!property_exists($wpRewrite, 'extra_permastructs')
            || !is_array($wpRewrite->extra_permastructs)
            || count($wpRewrite->extra_permastructs) > 256) {
            throw new \RuntimeException('duo: native rewrite found a malformed permastruct roster');
        }
        $reachable = array_fill_keys([
            'post', 'date', 'root', 'comments', 'search', 'author', 'page',
        ], true);
        foreach ($wpRewrite->extra_permastructs as $name => $_value) {
            if (!is_string($name) || preg_match('/\A[a-z0-9_-]{1,64}\z/D', $name) !== 1) {
                throw new \RuntimeException('duo: native rewrite found a malformed permastruct roster');
            }
            $reachable[$name] = true;
        }

        // Polylang registers the same exact callback for types WordPress does
        // not dispatch here (3.8.6 includes attachment_rewrite_rules). The
        // complete roster is still closed and callback-checked above; only its
        // intersection with WP_Rewrite's actual generation graph is reachable
        // during this mutation.
        $polylangTypes = $polylang === null ? [] : array_fill_keys($polylang['types'], true);
        foreach (array_keys($reachable) as $name) {
            $expected = isset($polylangTypes[$name])
                ? [[$polylang['links'], 'rewrite_rules', 10, 1]]
                : [];
            self::assert_exact_hook($name . '_rewrite_rules', $expected);
        }
        if (isset($reachable['post_tag'])) {
            self::assert_exact_hook('tag_rewrite_rules', []);
        }
        return self::permastruct_state($wpRewrite->extra_permastructs);
    }

    /** @param array<string,mixed> $permastructs */
    private static function permastruct_state(array $permastructs): string {
        $bytes = 0;
        foreach ($permastructs as $name => $definition) {
            if (!is_array($definition) || count($definition) > 16) {
                throw new \RuntimeException('duo: native rewrite found a malformed permastruct roster');
            }
            foreach ($definition as $key => $value) {
                if ((!is_int($key) && !is_string($key))
                    || (!is_string($value) && !is_int($value) && !is_bool($value))) {
                    throw new \RuntimeException('duo: native rewrite found a malformed permastruct roster');
                }
                $bytes += (is_string($key) ? strlen($key) : 8) + (is_string($value) ? strlen($value) : 8);
                if ($bytes > 1048576 || (is_string($value) && strlen($value) > 65536)) {
                    throw new \RuntimeException('duo: native rewrite found an oversized permastruct roster');
                }
            }
            $bytes += strlen($name);
        }
        try {
            return hash('sha256', json_encode($permastructs, JSON_THROW_ON_ERROR));
        } catch (\JsonException $failure) {
            throw new \RuntimeException('duo: native rewrite found a malformed permastruct roster', 0, $failure);
        }
    }

    private static function one_exact_class_callback(string $hookName, string $class, string $method): object {
        $services = [];
        foreach (self::hook_records($hookName) as [, $record]) {
            $callback = $record['function'];
            if (is_array($callback)
                && is_object($callback[0] ?? null)
                && get_class($callback[0]) === $class
                && ($callback[1] ?? null) === $method) {
                $services[spl_object_id($callback[0])] = $callback[0];
            }
        }
        if (count($services) !== 1) {
            throw new \RuntimeException('duo: native rewrite found incomplete or substituted plugin callbacks');
        }
        return reset($services);
    }

    private static function resolve_view_manager(): object {
        $class = 'Tribe\\Events\\Views\\V2\\Manager';
        if (!class_exists($class, false)) {
            throw new \RuntimeException('duo: native rewrite found an incomplete TEC view registry');
        }
        try {
            $manager = self::call_function('tribe', $class);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: native rewrite could not resolve the TEC view registry', 0, $failure);
        }
        if (!is_object($manager)
            || get_class($manager) !== $class
            || !is_callable([$manager, 'get_view_registration_objects'])) {
            throw new \RuntimeException('duo: native rewrite found a substituted TEC view registry');
        }
        return $manager;
    }

    /** @return list<object> */
    private static function view_registrations(object $manager): array {
        try {
            $raw = self::call_object($manager, 'get_view_registration_objects');
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: native rewrite could not read the TEC view registry', 0, $failure);
        }
        if (!is_array($raw) || $raw !== []) {
            throw new \RuntimeException('duo: native rewrite found an extended TEC view registry');
        }
        return [];
    }

    /** @return ?array{service:object,state:string,top:array<string,string>,bottom:array<string,string>} */
    private static function resolve_yoast(object $wpRewrite): ?array {
        $option = self::hook_records('option_rewrite_rules');
        $sanitize = self::hook_records('sanitize_option_rewrite_rules');
        $visible = class_exists('Yoast_Dynamic_Rewrites', false)
            || self::contains_class_callback($option, 'Yoast_Dynamic_Rewrites')
            || self::contains_class_callback($sanitize, 'Yoast_Dynamic_Rewrites');
        if (!$visible) {
            return null;
        }
        $service = self::one_exact_class_callback(
            'option_rewrite_rules',
            'Yoast_Dynamic_Rewrites',
            'filter_rewrite_rules_option'
        );
        $sanitizeService = self::one_exact_class_callback(
            'sanitize_option_rewrite_rules',
            'Yoast_Dynamic_Rewrites',
            'sanitize_rewrite_rules_option'
        );
        if ($sanitizeService !== $service || !is_callable(['Yoast_Dynamic_Rewrites', 'instance'])) {
            throw new \RuntimeException('duo: native rewrite found substituted Yoast rewrite services');
        }
        try {
            $resolved = self::call_static('Yoast_Dynamic_Rewrites', 'instance');
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: native rewrite could not resolve the Yoast rewrite service', 0, $failure);
        }
        if ($resolved !== $service
            || get_class($service) !== 'Yoast_Dynamic_Rewrites'
            || !property_exists($service, 'wp_rewrite')
            || $service->wp_rewrite !== $wpRewrite) {
            throw new \RuntimeException('duo: native rewrite found substituted Yoast rewrite services');
        }
        $state = self::yoast_state($service);
        return [
            'service' => $service,
            'state' => $state['hash'],
            'top' => $state['top'],
            'bottom' => $state['bottom'],
        ];
    }

    /** @return array{hash:string,top:array<string,string>,bottom:array<string,string>} */
    private static function yoast_state(object $service): array {
        $maps = [];
        $bytes = 0;
        foreach (['extra_rules_top', 'extra_rules_bottom'] as $propertyName) {
            try {
                $property = new \ReflectionProperty('Yoast_Dynamic_Rewrites', $propertyName);
                $value = $property->getValue($service);
            } catch (\Throwable $failure) {
                throw new \RuntimeException('duo: native rewrite could not inspect Yoast rewrite state', 0, $failure);
            }
            if (!is_array($value) || count($value) > 2048) {
                throw new \RuntimeException('duo: native rewrite found malformed Yoast rewrite state');
            }
            foreach ($value as $pattern => $query) {
                if (!is_string($pattern)
                    || !is_string($query)
                    || strlen($pattern) > 16384
                    || strlen($query) > 16384) {
                    throw new \RuntimeException('duo: native rewrite found malformed Yoast rewrite state');
                }
                $bytes += strlen($pattern) + strlen($query);
                if ($bytes > 4194304) {
                    throw new \RuntimeException('duo: native rewrite found oversized Yoast rewrite state');
                }
            }
            $maps[$propertyName] = $value;
        }
        return [
            'hash' => hash('sha256', serialize($maps)),
            'top' => $maps['extra_rules_top'],
            'bottom' => $maps['extra_rules_bottom'],
        ];
    }

    /** @return ?array{runtime:object,links:object,sitemaps:object,types:list<string>,types_hash:string} */
    private static function resolve_polylang(): ?array {
        $rewriteArray = self::hook_records('rewrite_rules_array');
        $callbackVisible = self::contains_class_callback($rewriteArray, 'PLL_Links_Directory');
        $runtimeVisible = function_exists('PLL') && array_key_exists('polylang', $GLOBALS);
        if (!$callbackVisible && !$runtimeVisible) {
            return null;
        }
        if (!$runtimeVisible) {
            throw new \RuntimeException('duo: native rewrite found an incomplete Polylang rewrite runtime');
        }
        try {
            $runtime = $GLOBALS['polylang'];
            $resolved = self::call_function('PLL');
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: native rewrite could not resolve the Polylang runtime', 0, $failure);
        }
        // rewrite.flush is deliberately executed by a fresh WP-CLI process;
        // Polylang 3.8.6 boots that exact runtime as PLL_Admin (the pinned
        // artifact's src/admin/admin.php SHA-256 is
        // 7ed2774c6c73c514c64fc1a4b6533e41bacc8278a54785e8246492ce597bfdc5),
        // while an HTTP frontend would expose a different service graph.
        if (!is_object($runtime)
            || get_class($runtime) !== 'PLL_Admin'
            || $resolved !== $runtime
            || !property_exists($runtime, 'links_model')
            || !is_object($runtime->links_model)
            || !property_exists($runtime, 'sitemaps')
            || !is_object($runtime->sitemaps)) {
            throw new \RuntimeException('duo: native rewrite found a substituted Polylang runtime');
        }
        $links = $runtime->links_model;
        if (get_class($links) !== 'PLL_Links_Directory') {
            if ($callbackVisible) {
                throw new \RuntimeException('duo: native rewrite found a substituted Polylang links model');
            }
            return null;
        }
        $sitemaps = $runtime->sitemaps;
        if (get_class($sitemaps) !== 'PLL_Sitemaps') {
            throw new \RuntimeException('duo: native rewrite found a substituted Polylang sitemap service');
        }
        if (!$callbackVisible || !is_callable([$links, 'get_rewrite_rules_filters'])) {
            throw new \RuntimeException('duo: native rewrite found an incomplete Polylang rewrite runtime');
        }
        foreach (['pll_rewrite_rules', 'pll_modify_rewrite_rule'] as $openHook) {
            if (self::hook_records($openHook) !== []) {
                throw new \RuntimeException(
                    'duo: native rewrite found an unsupported open Polylang rewrite filter'
                );
            }
        }
        try {
            $rawTypes = self::call_object($links, 'get_rewrite_rules_filters');
        } catch (\Throwable $failure) {
            throw new \RuntimeException('duo: native rewrite could not read Polylang rewrite types', 0, $failure);
        }
        if (!is_array($rawTypes) || count($rawTypes) > 128) {
            throw new \RuntimeException('duo: native rewrite found a malformed Polylang rewrite type roster');
        }
        $types = [];
        foreach ($rawTypes as $type) {
            if (!is_string($type) || preg_match('/\A[a-z0-9_-]{1,64}\z/D', $type) !== 1) {
                throw new \RuntimeException('duo: native rewrite found a malformed Polylang rewrite type roster');
            }
            if (isset($types[$type])) {
                throw new \RuntimeException('duo: native rewrite found a duplicate Polylang rewrite type');
            }
            $types[$type] = $type;
        }
        return [
            'runtime' => $runtime,
            'links' => $links,
            'sitemaps' => $sitemaps,
            'types' => array_values($types),
            'types_hash' => hash('sha256', serialize($rawTypes)),
        ];
    }

    /**
     * @param list<array{0:mixed,1:?string,2:int,3:int}> $expected
     */
    private static function assert_exact_hook(string $hookName, array $expected): void {
        $records = self::hook_records($hookName);
        foreach ($records as [$priority, $record]) {
            $matched = null;
            foreach ($expected as $index => [$owner, $method, $expectedPriority, $accepted]) {
                $callback = $method === null ? $owner : [$owner, $method];
                if ($priority === $expectedPriority
                    && $record['accepted_args'] === $accepted
                    && $record['function'] === $callback) {
                    $matched = $index;
                    break;
                }
            }
            if ($matched === null) {
                throw new \RuntimeException(
                    "duo: native rewrite found extended or substituted '$hookName' callbacks"
                );
            }
            unset($expected[$matched]);
        }
        if ($expected !== []) {
            throw new \RuntimeException("duo: native rewrite found incomplete '$hookName' callbacks");
        }
    }

    /**
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $records
     * @param ?array{container:object,features:object,synchronizer:object,custom_orders:object} $woo
     */
    private static function assert_updated_option_callbacks(
        array $records,
        ?object $listener,
        ?array $woo
    ): void {
        $expected = [];
        if ($listener !== null) {
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
        }
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
