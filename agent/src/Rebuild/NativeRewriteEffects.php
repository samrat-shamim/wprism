<?php
declare(strict_types=1);

namespace WPrism;

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
 * flag before child shutdown prevents a failed or successful WPrism flush from
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
 * Yoast SEO 28.3 normally adds six non-multisite option services: each binds
 * add_default_filters_if_not_changed to pre_update_option at PHP_INT_MAX/3,
 * and add_default_filters_if_same_option to update_option and add_option at
 * 10/1. Its sitemap cache adds one static update_option callback at 10/1; the
 * exact source (inc/sitemaps/class-sitemaps-cache.php, SHA-256
 * dc99816988fef1554775757fb8ab18b65ec2d46a08f03c475bf6da4dfdc72cc8)
 * returns without mutation for every TEC marker name unless an extension
 * registered that marker in its protected cache-clear map. Active sitemaps
 * bind the exact WPSEO_Sitemaps global/cache object and inspect only the fixed
 * marker keys in that registration map; inactive sitemaps bind the absence of
 * both global and callback. Mixed state
 * or a registered marker refuses before mutation. WPSEO_Options::
 * get_option_instance() reads the already-constructed option services;
 * get_instance() is deliberately not called because it constructs missing
 * services and would let a partial runtime manufacture admission.
 *
 * The same fresh process also executes exact rewrite interpreters from TEC,
 * Yoast 28.3 and Polylang 3.8.6. Yoast's normal WPSEO_Rewrite global adds a
 * category_rewrite_rules callback at 10/1; its reviewed stateless wrapper
 * (inc/class-rewrite.php, SHA-256
 * d8e168e467b06e6c49f1f1c60b2c5437d7eb9081ef96aa472ed1de880639dbda)
 * returns the input unchanged under the exact cached stripcategorybase=false
 * policy. Enabled mode enters the open get_categories/get_terms filter graph,
 * so it refuses before native mutation instead of executing unbound callbacks.
 * The remaining reviewed sources are respectively
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
    private const WOO_RUNTIME_CONTAINER =
        'Automattic\\WooCommerce\\Internal\\DependencyManagement\\RuntimeContainer';
    private const WOO_FEATURES = 'Automattic\\WooCommerce\\Internal\\Features\\FeaturesController';
    private const WOO_SYNCHRONIZER =
        'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\DataSynchronizer';
    private const WOO_CUSTOM_ORDERS =
        'Automattic\\WooCommerce\\Internal\\DataStores\\Orders\\CustomOrdersTableController';
    private const WPSEO_SITEMAPS = 'WPSEO_Sitemaps';
    private const WPSEO_SITEMAPS_CACHE = 'WPSEO_Sitemaps_Cache';
    private const WPSEO_REWRITE = 'WPSEO_Rewrite';
    private const HARBOR_PUE = 'TEC\\Common\\Integrations\\Harbor\\PUE';
    private const TEC_CONTAINER = 'Tribe__Container';
    private const TEC_DI_CONTAINER = 'TEC\\Common\\lucatume\\DI52\\Container';
    private const TEC_DI_RESOLVER = 'TEC\\Common\\lucatume\\DI52\\Builders\\Resolver';
    private const TEC_DI_VALUE_BUILDER = 'TEC\\Common\\lucatume\\DI52\\Builders\\ValueBuilder';
    private const TEC_DI_SERVICE_PROVIDER = 'TEC\\Common\\lucatume\\DI52\\ServiceProvider';
    /** @var array<string,string> */
    private const WPSEO_OPTIONS = [
        'wpseo' => 'WPSEO_Option_Wpseo',
        'wpseo_titles' => 'WPSEO_Option_Titles',
        'wpseo_social' => 'WPSEO_Option_Social',
        'wpseo_taxonomy_meta' => 'WPSEO_Taxonomy_Meta',
        'wpseo_llmstxt' => 'WPSEO_Option_Llmstxt',
        'wpseo_tracking_only' => 'WPSEO_Option_Tracking_Only',
    ];

    private function __construct(
        private readonly ?object $listener,
        private readonly ?object $listenerCache,
        private readonly ?object $globalCache,
        private readonly ?object $wooContainer,
        private readonly ?object $wooFeatures,
        private readonly ?object $wooSynchronizer,
        private readonly ?object $wooCustomOrders,
        /** @var ?array<string,object> */
        private readonly ?array $wpseoOptions,
        private readonly ?object $wpseoSitemaps,
        private readonly ?object $wpseoSitemapsCache,
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
        $update = self::hook_records('update_option');
        $add = self::hook_records('add_option');
        $added = self::hook_records('added_option');
        $tec = self::resolve_tec_services($updated, $generate);
        $listener = $tec['listener'] ?? null;
        $woo = self::resolve_woo_services($updated, $preUpdated, $added);
        $wpseo = self::resolve_wpseo_services($preUpdated, $update, $add);
        self::assert_updated_option_callbacks($updated, $listener, $woo);
        if ($listener !== null) {
            self::assert_generate_callback($generate, $listener);
            self::assert_trigger_filters();
            foreach (self::MARKER_OPTIONS as $name) {
                self::assert_marker_option_hooks($name, $listener, $woo, $wpseo);
            }
        }
        $rewriteTopology = self::rewrite_topology($listener, $woo, $wpseo);
        return new self(
            $listener,
            $tec['listener_cache'] ?? null,
            $tec['global_cache'] ?? null,
            $woo['container'] ?? null,
            $woo['features'] ?? null,
            $woo['synchronizer'] ?? null,
            $woo['custom_orders'] ?? null,
            $wpseo['options'] ?? null,
            $wpseo['sitemaps'] ?? null,
            $wpseo['sitemaps_cache'] ?? null,
            $rewriteTopology,
            $tec['purge_present'] ?? false,
            $tec['purge_value'] ?? null
        );
    }

    /**
     * Polylang 3.8.x reads and saves its mixed option through WordPress core.
     * The exact Woo 11.0.1 and Yoast 28.3 pre-update callbacks return the
     * value unchanged for option name `polylang`; TEC 6.17.2 Harbor likewise
     * returns the pre-read value unchanged outside `pue_install_key_*`.
     * Resolve their canonical services without constructing missing
     * singletons, then close the callback union before Polylang mutates its
     * request-local registry.
     */
    public static function assert_inert_polylang_option_filter_topology(): void {
        $preOption = self::hook_records('pre_option');
        self::resolve_polylang_harbor_service($preOption);
        $preUpdated = self::hook_records('pre_update_option');
        $woo = self::resolve_polylang_woo_service($preUpdated);
        $wpseo = self::resolve_wpseo_option_services($preUpdated);
        self::assert_polylang_pre_update_callbacks($preUpdated, $woo, $wpseo);
    }

    /** Re-prove callback/service identity, then restore the exact local flag. */
    public function restore(): void {
        $topologyFailure = null;
        try {
            $updated = self::hook_records('updated_option');
            $generate = self::hook_records('generate_rewrite_rules');
            $preUpdated = self::hook_records('pre_update_option');
            $update = self::hook_records('update_option');
            $add = self::hook_records('add_option');
            $added = self::hook_records('added_option');
            $tec = self::resolve_tec_services($updated, $generate);
            $listener = $tec['listener'] ?? null;
            $woo = self::resolve_woo_services($updated, $preUpdated, $added);
            $wpseo = self::resolve_wpseo_services($preUpdated, $update, $add);
            self::assert_updated_option_callbacks($updated, $listener, $woo);
            if ($listener !== null) {
                self::assert_generate_callback($generate, $listener);
                self::assert_trigger_filters();
                foreach (self::MARKER_OPTIONS as $name) {
                    self::assert_marker_option_hooks($name, $listener, $woo, $wpseo);
                }
            }
            $rewriteTopology = self::rewrite_topology($listener, $woo, $wpseo);
            if ($listener !== $this->listener
                || ($tec['listener_cache'] ?? null) !== $this->listenerCache
                || ($tec['global_cache'] ?? null) !== $this->globalCache
                || ($woo['container'] ?? null) !== $this->wooContainer
                || ($woo['features'] ?? null) !== $this->wooFeatures
                || ($woo['synchronizer'] ?? null) !== $this->wooSynchronizer
                || ($woo['custom_orders'] ?? null) !== $this->wooCustomOrders
                || ($wpseo['options'] ?? null) !== $this->wpseoOptions
                || ($wpseo['sitemaps'] ?? null) !== $this->wpseoSitemaps
                || ($wpseo['sitemaps_cache'] ?? null) !== $this->wpseoSitemapsCache
                || $rewriteTopology !== $this->rewriteTopology) {
                throw new \RuntimeException(
                    'wprism: native rewrite found The Events Calendar cache-listener service drift'
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
                        'wprism: native rewrite could not restore The Events Calendar purge-flag preimage'
                    );
                }
            } catch (\Throwable $failure) {
                $flagFailure = $failure;
            }
        }

        if ($topologyFailure !== null && $flagFailure !== null) {
            throw new \RuntimeException(
                'wprism: native rewrite cleanup found topology drift and purge-flag restoration failure; topology='
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
            throw new \LogicException('wprism: native rewrite lost its proven Yoast projection');
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
            throw new \RuntimeException('wprism: native rewrite found malformed WordPress hook topology');
        }
        $records = [];
        foreach ($hook->callbacks as $priority => $atPriority) {
            if (!is_int($priority) || !is_array($atPriority)) {
                throw new \RuntimeException('wprism: native rewrite found malformed WordPress hook topology');
            }
            foreach ($atPriority as $record) {
                if (!is_array($record)
                    || array_keys($record) !== ['function', 'accepted_args']
                    || !is_int($record['accepted_args'] ?? null)) {
                    throw new \RuntimeException('wprism: native rewrite found malformed WordPress hook topology');
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

    /** @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $records */
    private static function contains_class_method_callback(array $records, string $class, string $method): bool {
        foreach ($records as [, $record]) {
            $callback = $record['function'];
            if (is_array($callback)
                && is_object($callback[0] ?? null)
                && get_class($callback[0]) === $class
                && ($callback[1] ?? null) === $method) {
                return true;
            }
        }
        return false;
    }

    /** @return list<string> */
    private static function dynamic_rewrite_hooks_for_class(string $class): array {
        global $wp_filter;
        if (!is_array($wp_filter ?? null)) {
            return [];
        }
        if (count($wp_filter) > 16384) {
            throw new \RuntimeException('wprism: native rewrite found an oversized WordPress hook topology');
        }
        $hooks = [];
        foreach (array_keys($wp_filter) as $hookName) {
            if (!is_string($hookName)
                || preg_match('/\A[a-z0-9_-]{1,64}_rewrite_rules\z/D', $hookName) !== 1
                || !self::contains_class_method_callback(
                    self::hook_records($hookName),
                    $class,
                    'rewrite_rules'
                )) {
                continue;
            }
            $hooks[] = $hookName;
        }
        sort($hooks, SORT_STRING);
        return $hooks;
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
                    'wprism: native rewrite found an incomplete The Events Calendar cache-listener runtime'
                );
            }
        }
        if (!class_exists('Tribe__Cache_Listener', false)
            || !is_callable(['Tribe__Cache_Listener', 'instance'])) {
            throw new \RuntimeException(
                'wprism: native rewrite found an incomplete The Events Calendar cache-listener runtime'
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
                'wprism: native rewrite could not resolve The Events Calendar cache-listener services',
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
                'wprism: native rewrite found substituted The Events Calendar cache-listener services'
            );
        }
        $purgePresent = self::call_function('tribe_isset_var', self::PURGE_FLAG);
        if (!is_bool($purgePresent)) {
            throw new \RuntimeException(
                'wprism: native rewrite found malformed The Events Calendar purge-flag presence'
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
                'wprism: native rewrite found incomplete or substituted The Events Calendar listener callbacks'
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
                        'wprism: native rewrite found a substituted The Events Calendar generation callback'
                    );
                }
                $matches++;
            }
        }
        if ($matches !== 1) {
            throw new \RuntimeException(
                'wprism: native rewrite found incomplete The Events Calendar generation callbacks'
            );
        }
    }

    /**
     * @param ?array{container:object,features:object,synchronizer:object,custom_orders:object} $woo
     * @param ?array{options:array<string,object>,sitemaps:?object,sitemaps_cache:?object} $wpseo
     * @return array<string,mixed>
     */
    private static function rewrite_topology(?object $listener, ?array $woo, ?array $wpseo): array {
        global $wp_rewrite;
        if (!is_object($wp_rewrite)) {
            throw new \RuntimeException('wprism: native rewrite found a malformed WordPress rewrite runtime');
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
        $corePermastructs = self::assert_core_generation_topology($wp_rewrite, $polylang, $yoast);
        self::assert_rewrite_rules_option_hooks($listener, $woo, $wpseo, $yoast);

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
                    'wprism: native rewrite found an incomplete WooCommerce rewrite runtime'
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
                $expected = [[$polylang['links'], 'rewrite_rules', 10, 1]];
                if ($type === 'category' && $yoast !== null) {
                    $expected[] = [$yoast['category_service'], 'category_rewrite_rules_wrapper', 10, 1];
                }
                self::assert_exact_hook($type . '_rewrite_rules', $expected);
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
     * @param ?array{options:array<string,object>,sitemaps:?object,sitemaps_cache:?object} $wpseo
     * @param ?array{service:object,category_service:object,state:string,top:array<string,string>,bottom:array<string,string>} $yoast
     */
    private static function assert_rewrite_rules_option_hooks(
        ?object $listener,
        ?array $woo,
        ?array $wpseo,
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
            if (in_array($hookName, ['pre_update_option', 'update_option', 'add_option', 'added_option'], true)) {
                try {
                    self::assert_normal_option_callbacks($hookName, $records, $woo, $wpseo);
                } catch (\RuntimeException $failure) {
                    if (str_contains($failure->getMessage(), 'extended or substituted normal ')) {
                        throw new \RuntimeException(
                            'wprism: native rewrite found extended rewrite_rules option topology',
                            0,
                            $failure
                        );
                    }
                    throw $failure;
                }
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
                'wprism: native rewrite found extended rewrite_rules option topology'
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
            throw new \RuntimeException('wprism: native rewrite found an incomplete plugin rewrite service');
        }
        try {
            $resolved = self::call_static($class, $factory);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: native rewrite could not resolve a plugin rewrite service', 0, $failure);
        }
        if (!is_object($resolved) || get_class($resolved) !== $class || $resolved !== $service) {
            throw new \RuntimeException('wprism: native rewrite found a substituted plugin rewrite service');
        }
        return $service;
    }

    private static function exact_container_service(string $class, string $hookName, string $method): object {
        $service = self::one_exact_class_callback($hookName, $class, $method);
        if (!class_exists($class, false)) {
            throw new \RuntimeException('wprism: native rewrite found an incomplete TEC rewrite service');
        }
        try {
            $resolved = self::call_function('tribe', $class);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: native rewrite could not resolve a TEC rewrite service', 0, $failure);
        }
        if (!is_object($resolved) || get_class($resolved) !== $class || $resolved !== $service) {
            throw new \RuntimeException('wprism: native rewrite found a substituted TEC rewrite service');
        }
        return $service;
    }

    private static function exact_container_value_service(string $class, string $method): object {
        if (!class_exists($class, false)) {
            throw new \RuntimeException('wprism: native rewrite found an incomplete TEC rewrite service');
        }
        try {
            $resolved = self::call_function('tribe', $class);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: native rewrite could not resolve a TEC rewrite service', 0, $failure);
        }
        if (!is_object($resolved)
            || get_class($resolved) !== $class
            || !is_callable([$resolved, $method])) {
            throw new \RuntimeException('wprism: native rewrite found a substituted TEC rewrite service');
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
            throw new \RuntimeException('wprism: native rewrite could not resolve a lazy TEC rewrite service', 0, $failure);
        }
        self::assert_stateless_lazy_container_value($resolved, $class, $method, false);
        try {
            $reresolved = self::call_function('tribe', $class);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: native rewrite could not re-resolve a lazy TEC rewrite service', 0, $failure);
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
            throw new \RuntimeException('wprism: native rewrite found a substituted lazy TEC rewrite service' . $phase);
        }
        try {
            $properties = (new \ReflectionClass($resolved))->getProperties();
            $dynamicState = get_object_vars($resolved);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: native rewrite could not inspect a lazy TEC rewrite service' . $phase, 0, $failure);
        }
        if ($properties !== [] || $dynamicState !== []) {
            throw new \RuntimeException('wprism: native rewrite found a stateful lazy TEC rewrite service' . $phase);
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
            throw new \RuntimeException('wprism: native rewrite could not inspect the TEC QR route state', 0, $failure);
        }
        if (($base !== null && $base !== 'events') || ($prefix !== null && $prefix !== 'qr')) {
            throw new \RuntimeException('wprism: native rewrite found substituted TEC QR route state');
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
     * @param ?array{service:object,category_service:object,state:string,top:array<string,string>,bottom:array<string,string>} $yoast
     */
    private static function assert_core_generation_topology(
        object $wpRewrite,
        ?array $polylang,
        ?array $yoast
    ): string {
        if (!property_exists($wpRewrite, 'extra_permastructs')
            || !is_array($wpRewrite->extra_permastructs)
            || count($wpRewrite->extra_permastructs) > 256) {
            throw new \RuntimeException('wprism: native rewrite found a malformed permastruct roster');
        }
        $reachable = array_fill_keys([
            'post', 'date', 'root', 'comments', 'search', 'author', 'page',
        ], true);
        foreach ($wpRewrite->extra_permastructs as $name => $_value) {
            if (!is_string($name) || preg_match('/\A[a-z0-9_-]{1,64}\z/D', $name) !== 1) {
                throw new \RuntimeException('wprism: native rewrite found a malformed permastruct roster');
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
            $expected = [];
            if (isset($polylangTypes[$name])) {
                $expected[] = [$polylang['links'], 'rewrite_rules', 10, 1];
            }
            if ($name === 'category' && $yoast !== null) {
                $expected[] = [$yoast['category_service'], 'category_rewrite_rules_wrapper', 10, 1];
            }
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
                throw new \RuntimeException('wprism: native rewrite found a malformed permastruct roster');
            }
            foreach ($definition as $key => $value) {
                if ((!is_int($key) && !is_string($key))
                    || (!is_string($value) && !is_int($value) && !is_bool($value))) {
                    throw new \RuntimeException('wprism: native rewrite found a malformed permastruct roster');
                }
                $bytes += (is_string($key) ? strlen($key) : 8) + (is_string($value) ? strlen($value) : 8);
                if ($bytes > 1048576 || (is_string($value) && strlen($value) > 65536)) {
                    throw new \RuntimeException('wprism: native rewrite found an oversized permastruct roster');
                }
            }
            $bytes += strlen($name);
        }
        try {
            return hash('sha256', json_encode($permastructs, JSON_THROW_ON_ERROR));
        } catch (\JsonException $failure) {
            throw new \RuntimeException('wprism: native rewrite found a malformed permastruct roster', 0, $failure);
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
            throw new \RuntimeException('wprism: native rewrite found incomplete or substituted plugin callbacks');
        }
        return reset($services);
    }

    private static function resolve_view_manager(): object {
        $class = 'Tribe\\Events\\Views\\V2\\Manager';
        if (!class_exists($class, false)) {
            throw new \RuntimeException('wprism: native rewrite found an incomplete TEC view registry');
        }
        try {
            $manager = self::call_function('tribe', $class);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: native rewrite could not resolve the TEC view registry', 0, $failure);
        }
        if (!is_object($manager)
            || get_class($manager) !== $class
            || !is_callable([$manager, 'get_view_registration_objects'])) {
            throw new \RuntimeException('wprism: native rewrite found a substituted TEC view registry');
        }
        return $manager;
    }

    /** @return list<object> */
    private static function view_registrations(object $manager): array {
        try {
            $raw = self::call_object($manager, 'get_view_registration_objects');
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: native rewrite could not read the TEC view registry', 0, $failure);
        }
        if (!is_array($raw) || $raw !== []) {
            throw new \RuntimeException('wprism: native rewrite found an extended TEC view registry');
        }
        return [];
    }

    /** @return ?array{service:object,category_service:object,state:string,top:array<string,string>,bottom:array<string,string>} */
    private static function resolve_yoast(object $wpRewrite): ?array {
        $option = self::hook_records('option_rewrite_rules');
        $sanitize = self::hook_records('sanitize_option_rewrite_rules');
        $category = self::hook_records('category_rewrite_rules');
        $dynamicVisible = class_exists('Yoast_Dynamic_Rewrites', false)
            || self::contains_class_callback($option, 'Yoast_Dynamic_Rewrites')
            || self::contains_class_callback($sanitize, 'Yoast_Dynamic_Rewrites');
        $categoryVisible = class_exists(self::WPSEO_REWRITE, false)
            || array_key_exists('wpseo_rewrite', $GLOBALS)
            || self::contains_class_callback($category, self::WPSEO_REWRITE);
        if (!$dynamicVisible && !$categoryVisible) {
            return null;
        }
        if (!$dynamicVisible || !$categoryVisible) {
            throw new \RuntimeException('wprism: native rewrite found incomplete Yoast rewrite services');
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
            throw new \RuntimeException('wprism: native rewrite found substituted Yoast rewrite services');
        }
        $categoryService = self::one_exact_class_callback(
            'category_rewrite_rules',
            self::WPSEO_REWRITE,
            'category_rewrite_rules_wrapper'
        );
        try {
            $resolved = self::call_static('Yoast_Dynamic_Rewrites', 'instance');
            $categoryGlobal = $GLOBALS['wpseo_rewrite'] ?? null;
            $categoryProperties = (new \ReflectionClass($categoryService))->getProperties();
            $categoryDynamicState = get_object_vars($categoryService);
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: native rewrite could not resolve the Yoast rewrite service', 0, $failure);
        }
        if ($resolved !== $service
            || get_class($service) !== 'Yoast_Dynamic_Rewrites'
            || !property_exists($service, 'wp_rewrite')
            || $service->wp_rewrite !== $wpRewrite) {
            throw new \RuntimeException('wprism: native rewrite found substituted Yoast rewrite services');
        }
        if (!is_object($categoryGlobal)
            || get_class($categoryGlobal) !== self::WPSEO_REWRITE
            || $categoryGlobal !== $categoryService
            || !is_callable([$categoryService, 'category_rewrite_rules_wrapper'])
            || $categoryProperties !== []
            || $categoryDynamicState !== []) {
            throw new \RuntimeException('wprism: native rewrite found substituted Yoast category rewrite service');
        }
        self::assert_inert_yoast_category_policy();
        $state = self::yoast_state($service);
        return [
            'service' => $service,
            'category_service' => $categoryService,
            'state' => $state['hash'],
            'top' => $state['top'],
            'bottom' => $state['bottom'],
        ];
    }

    /**
     * The disabled wrapper returns before get_categories(). Enabled mode
     * traverses third-party term-query filters whose effects are not part of
     * this closed native action, so only the already-primed false value is
     * admissible without executing plugin code during preflight.
     */
    private static function assert_inert_yoast_category_policy(): void {
        try {
            $values = (new \ReflectionProperty('WPSEO_Options', 'option_values'))->getValue();
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'wprism: native rewrite could not inspect the Yoast category rewrite policy',
                0,
                $failure
            );
        }
        if (!is_array($values)
            || !array_key_exists('stripcategorybase', $values)
            || !is_bool($values['stripcategorybase'])) {
            throw new \RuntimeException('wprism: native rewrite found an unprimed Yoast category rewrite policy');
        }
        if ($values['stripcategorybase']) {
            throw new \RuntimeException('wprism: native rewrite does not support enabled Yoast category-base removal');
        }
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
                throw new \RuntimeException('wprism: native rewrite could not inspect Yoast rewrite state', 0, $failure);
            }
            if (!is_array($value) || count($value) > 2048) {
                throw new \RuntimeException('wprism: native rewrite found malformed Yoast rewrite state');
            }
            foreach ($value as $pattern => $query) {
                if (!is_string($pattern)
                    || !is_string($query)
                    || strlen($pattern) > 16384
                    || strlen($query) > 16384) {
                    throw new \RuntimeException('wprism: native rewrite found malformed Yoast rewrite state');
                }
                $bytes += strlen($pattern) + strlen($query);
                if ($bytes > 4194304) {
                    throw new \RuntimeException('wprism: native rewrite found oversized Yoast rewrite state');
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
        $linksCallbackVisible = self::contains_class_callback($rewriteArray, 'PLL_Links_Directory');
        $sitemapsCallbackVisible = self::contains_class_callback($rewriteArray, 'PLL_Sitemaps');
        $callbackVisible = $linksCallbackVisible || $sitemapsCallbackVisible;
        $runtimeVisible = function_exists('PLL') && array_key_exists('polylang', $GLOBALS);
        if (!$callbackVisible && !$runtimeVisible) {
            return null;
        }
        if (!$runtimeVisible) {
            throw new \RuntimeException('wprism: native rewrite found an incomplete Polylang rewrite runtime');
        }
        try {
            $runtime = $GLOBALS['polylang'];
            $resolved = self::call_function('PLL');
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: native rewrite could not resolve the Polylang runtime', 0, $failure);
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
            || !is_object($runtime->links_model)) {
            throw new \RuntimeException('wprism: native rewrite found a substituted Polylang runtime');
        }
        $links = $runtime->links_model;
        if (get_class($links) !== 'PLL_Links_Directory') {
            if ($callbackVisible) {
                throw new \RuntimeException('wprism: native rewrite found a substituted Polylang links model');
            }
            return null;
        }
        $sitemaps = null;
        if (property_exists($runtime, 'sitemaps')) {
            if (!is_object($runtime->sitemaps) || get_class($runtime->sitemaps) !== 'PLL_Sitemaps') {
                throw new \RuntimeException('wprism: native rewrite found a substituted Polylang sitemap service');
            }
            $sitemaps = $runtime->sitemaps;
        }
        if (!is_callable([$links, 'get_rewrite_rules_filters'])) {
            throw new \RuntimeException('wprism: native rewrite found an incomplete Polylang rewrite runtime');
        }
        foreach (['pll_rewrite_rules', 'pll_modify_rewrite_rule'] as $openHook) {
            if (self::hook_records($openHook) !== []) {
                throw new \RuntimeException(
                    'wprism: native rewrite found an unsupported open Polylang rewrite filter'
                );
            }
        }
        $dynamicHooks = self::dynamic_rewrite_hooks_for_class('PLL_Links_Directory');
        if (!$linksCallbackVisible) {
            // links-directory.php::prepare_rewrite_rules() registers the
            // array callback and every dynamic type callback as one batch,
            // but only after languages exist. The sitemap loader has the same
            // language gate. Exact whole-batch absence is therefore an inert
            // pre-provider phase; any fragment is partial boot or substitution.
            if ($sitemaps !== null || $sitemapsCallbackVisible || $dynamicHooks !== []) {
                throw new \RuntimeException('wprism: native rewrite found an incomplete Polylang rewrite runtime');
            }
            return null;
        }
        if ($sitemaps === null || !$sitemapsCallbackVisible) {
            throw new \RuntimeException('wprism: native rewrite found an incomplete Polylang sitemap runtime');
        }
        try {
            $rawTypes = self::call_object($links, 'get_rewrite_rules_filters');
        } catch (\Throwable $failure) {
            throw new \RuntimeException('wprism: native rewrite could not read Polylang rewrite types', 0, $failure);
        }
        if (!is_array($rawTypes) || count($rawTypes) > 128) {
            throw new \RuntimeException('wprism: native rewrite found a malformed Polylang rewrite type roster');
        }
        $types = [];
        foreach ($rawTypes as $type) {
            if (!is_string($type) || preg_match('/\A[a-z0-9_-]{1,64}\z/D', $type) !== 1) {
                throw new \RuntimeException('wprism: native rewrite found a malformed Polylang rewrite type roster');
            }
            if (isset($types[$type])) {
                throw new \RuntimeException('wprism: native rewrite found a duplicate Polylang rewrite type');
            }
            $types[$type] = $type;
        }
        $expectedDynamicHooks = array_map(
            static fn(string $type): string => $type . '_rewrite_rules',
            array_values($types)
        );
        sort($expectedDynamicHooks, SORT_STRING);
        if (array_diff($dynamicHooks, $expectedDynamicHooks) !== []) {
            throw new \RuntimeException('wprism: native rewrite found extended Polylang rewrite callbacks');
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
                    "wprism: native rewrite found extended or substituted '$hookName' callbacks"
                );
            }
            unset($expected[$matched]);
        }
        if ($expected !== []) {
            throw new \RuntimeException("wprism: native rewrite found incomplete '$hookName' callbacks");
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
                    'wprism: native rewrite found incomplete The Events Calendar updated-option services'
                );
            }
            try {
                $manager = \Tribe__Settings_Manager::instance();
                $aggregator = \Tribe__Events__Aggregator::instance();
                $views = self::call_function('tribe', 'Tribe\\Events\\Views\\V2\\Hooks');
            } catch (\Throwable $failure) {
                throw new \RuntimeException(
                    'wprism: native rewrite could not resolve The Events Calendar updated-option services',
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
                        'wprism: native rewrite found substituted The Events Calendar updated-option services'
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
                    'wprism: native rewrite found extended or substituted updated-option callbacks'
                );
            }
            unset($expected[$matched]);
        }
        if ($expected !== []) {
            throw new \RuntimeException(
                'wprism: native rewrite found incomplete The Events Calendar updated-option callbacks'
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
                    'wprism: native rewrite found extended The Events Calendar cache-listener trigger filters'
                );
            }
        }
    }

    /**
     * @param ?array{container:object,features:object,synchronizer:object,custom_orders:object} $woo
     * @param ?array{options:array<string,object>,sitemaps:?object,sitemaps_cache:?object} $wpseo
     */
    private static function assert_marker_option_hooks(
        string $name,
        object $listener,
        ?array $woo,
        ?array $wpseo
    ): void {
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
            if (in_array($hookName, ['pre_update_option', 'update_option', 'add_option', 'added_option'], true)) {
                try {
                    self::assert_normal_option_callbacks($hookName, $records, $woo, $wpseo);
                } catch (\RuntimeException $failure) {
                    if (str_contains($failure->getMessage(), 'extended or substituted normal ')) {
                        throw new \RuntimeException(
                            "wprism: native rewrite found extended marker option topology for '$name'",
                            0,
                            $failure
                        );
                    }
                    throw $failure;
                }
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
                "wprism: native rewrite found extended marker option topology for '$name'"
            );
        }
    }

    /**
     * Bind Harbor's already-resolved controller without calling tribe(),
     * whose DI52 make() path constructs an unresolved service. The reviewed
     * 6.17.2 container stores a registered controller as a ValueBuilder; the
     * callback owner, provider container and stored value must be one object.
     *
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $records
     */
    private static function resolve_polylang_harbor_service(array $records): ?object {
        $visible = class_exists(self::HARBOR_PUE, false)
            || self::contains_class_callback($records, self::HARBOR_PUE);
        if (!$visible) {
            if ($records !== []) {
                throw new \RuntimeException(
                    'wprism: native option topology found extended or substituted pre_option callbacks'
                );
            }
            return null;
        }
        if (count($records) !== 1) {
            throw new \RuntimeException(
                'wprism: native option topology found extended or substituted pre_option callbacks'
            );
        }
        [$priority, $record] = $records[0];
        $callback = $record['function'];
        $owner = is_array($callback) ? ($callback[0] ?? null) : null;
        if ($priority !== 10
            || $record['accepted_args'] !== 3
            || !is_object($owner)
            || get_class($owner) !== self::HARBOR_PUE
            || ($callback[1] ?? null) !== 'filter_pre_get_option'
            || !class_exists(self::TEC_CONTAINER, false)
            || !class_exists(self::TEC_DI_CONTAINER, false)
            || !class_exists(self::TEC_DI_RESOLVER, false)
            || !class_exists(self::TEC_DI_VALUE_BUILDER, false)
            || !class_exists(self::TEC_DI_SERVICE_PROVIDER, false)) {
            throw new \RuntimeException(
                'wprism: native option topology found extended or substituted pre_option callbacks'
            );
        }
        try {
            $container = (new \ReflectionProperty(self::TEC_CONTAINER, 'instance'))->getValue();
            $providerContainer = (new \ReflectionProperty(
                self::TEC_DI_SERVICE_PROVIDER,
                'container'
            ))->getValue($owner);
            $resolver = (new \ReflectionProperty(self::TEC_DI_CONTAINER, 'resolver'))->getValue($container);
            $bindings = (new \ReflectionProperty(self::TEC_DI_RESOLVER, 'bindings'))->getValue($resolver);
            $builder = is_array($bindings) ? ($bindings[self::HARBOR_PUE] ?? null) : null;
            $bound = is_object($builder) && get_class($builder) === self::TEC_DI_VALUE_BUILDER
                ? (new \ReflectionProperty(self::TEC_DI_VALUE_BUILDER, 'value'))->getValue($builder)
                : null;
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'wprism: native option topology could not inspect the resolved TEC Harbor service',
                0,
                $failure
            );
        }
        if (!is_object($container)
            || get_class($container) !== self::TEC_CONTAINER
            || $providerContainer !== $container
            || !is_object($resolver)
            || get_class($resolver) !== self::TEC_DI_RESOLVER
            || $bound !== $owner) {
            throw new \RuntimeException(
                'wprism: native option topology found a substituted TEC Harbor pre_option service'
            );
        }
        return $owner;
    }

    /**
     * Read Woo's exact RuntimeContainer cache instead of Container::get(),
     * which constructs a cache miss. A partial boot therefore refuses without
     * manufacturing the CustomOrdersTableController used as admission proof.
     *
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $records
     */
    private static function resolve_polylang_woo_service(array $records): ?object {
        $visible = function_exists('wc_get_container')
            || array_key_exists('wc_container', $GLOBALS)
            || class_exists(self::WOO_CONTAINER, false)
            || class_exists(self::WOO_RUNTIME_CONTAINER, false)
            || class_exists(self::WOO_CUSTOM_ORDERS, false)
            || self::contains_class_callback($records, self::WOO_CUSTOM_ORDERS);
        if (!$visible) {
            return null;
        }
        if (!function_exists('wc_get_container')
            || !array_key_exists('wc_container', $GLOBALS)
            || !class_exists(self::WOO_CONTAINER, false)
            || !class_exists(self::WOO_RUNTIME_CONTAINER, false)
            || !class_exists(self::WOO_CUSTOM_ORDERS, false)) {
            throw new \RuntimeException(
                'wprism: native option topology found an incomplete WooCommerce pre_update_option runtime'
            );
        }
        try {
            $container = $GLOBALS['wc_container'];
            $runtime = is_object($container) && get_class($container) === self::WOO_CONTAINER
                ? (new \ReflectionProperty(self::WOO_CONTAINER, 'container'))->getValue($container)
                : null;
            $cache = is_object($runtime) && get_class($runtime) === self::WOO_RUNTIME_CONTAINER
                ? (new \ReflectionProperty(self::WOO_RUNTIME_CONTAINER, 'resolved_cache'))->getValue($runtime)
                : null;
            $customOrders = is_array($cache) ? ($cache[self::WOO_CUSTOM_ORDERS] ?? null) : null;
            $publicContainer = self::call_function('wc_get_container');
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'wprism: native option topology could not inspect WooCommerce pre_update_option services',
                0,
                $failure
            );
        }
        if (!is_object($container)
            || get_class($container) !== self::WOO_CONTAINER
            || $publicContainer !== $container
            || !is_object($runtime)
            || get_class($runtime) !== self::WOO_RUNTIME_CONTAINER
            || !is_object($customOrders)
            || get_class($customOrders) !== self::WOO_CUSTOM_ORDERS) {
            throw new \RuntimeException(
                'wprism: native option topology found incomplete or substituted WooCommerce pre_update_option services'
            );
        }
        return $customOrders;
    }

    /**
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $records
     * @param ?array<string,object> $wpseo
     */
    private static function assert_polylang_pre_update_callbacks(
        array $records,
        ?object $woo,
        ?array $wpseo
    ): void {
        $expected = [];
        if ($woo !== null) {
            $expected[] = [$woo, 'process_pre_update_option', 999, 3];
        }
        if ($wpseo !== null) {
            foreach ($wpseo as $service) {
                $expected[] = [$service, 'add_default_filters_if_not_changed', PHP_INT_MAX, 3];
            }
        }
        foreach ($records as [$priority, $record]) {
            $matched = null;
            foreach ($expected as $index => [$owner, $method, $expectedPriority, $accepted]) {
                if ($priority === $expectedPriority
                    && $record['accepted_args'] === $accepted
                    && $record['function'] === [$owner, $method]) {
                    $matched = $index;
                    break;
                }
            }
            if ($matched === null) {
                $family = 'normal';
                $callback = $record['function'];
                if (is_array($callback)) {
                    $owner = $callback[0] ?? null;
                    $class = is_object($owner) ? get_class($owner) : $owner;
                    if (is_string($class) && in_array($class, self::WPSEO_OPTIONS, true)) {
                        $family = 'Yoast SEO';
                    } elseif ($class === self::WOO_CUSTOM_ORDERS) {
                        $family = 'WooCommerce';
                    }
                }
                throw new \RuntimeException(
                    "wprism: native option topology found extended or substituted $family pre_update_option callbacks"
                );
            }
            unset($expected[$matched]);
        }
        if ($expected !== []) {
            $yoast = false;
            $wooMissing = false;
            foreach ($expected as [$owner]) {
                $yoast = $yoast || ($wpseo !== null && in_array($owner, $wpseo, true));
                $wooMissing = $wooMissing || $owner === $woo;
            }
            $family = $yoast ? 'Yoast SEO' : ($wooMissing ? 'WooCommerce' : 'normal');
            throw new \RuntimeException(
                "wprism: native option topology found incomplete $family pre_update_option callbacks"
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
                'wprism: native rewrite found an incomplete WooCommerce option-callback runtime'
            );
        }
        try {
            $container = $GLOBALS['wc_container'];
            if (!is_object($container) || get_class($container) !== self::WOO_CONTAINER) {
                throw new \RuntimeException(
                    'wprism: native rewrite found substituted WooCommerce option-callback services'
                );
            }
            if (self::call_function('wc_get_container') !== $container) {
                throw new \RuntimeException(
                    'wprism: native rewrite found substituted WooCommerce option-callback services'
                );
            }
            $features = self::call_object($container, 'get', self::WOO_FEATURES);
            $synchronizer = self::call_object($container, 'get', self::WOO_SYNCHRONIZER);
            $customOrders = self::call_object($container, 'get', self::WOO_CUSTOM_ORDERS);
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'wprism: native rewrite could not resolve WooCommerce option-callback services',
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
                    'wprism: native rewrite found substituted WooCommerce option-callback services'
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
     * Resolve Yoast's six non-multisite option services without constructing
     * any missing singleton. A loaded manager, option class, or callback makes
     * the runtime visible; all six existing instances are then required so a
     * partial boot cannot be mistaken for an inactive plugin.
     *
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $preUpdated
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $update
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $add
     * @return ?array{options:array<string,object>,sitemaps:?object,sitemaps_cache:?object}
     */
    private static function resolve_wpseo_services(array $preUpdated, array $update, array $add): ?array {
        $records = array_merge($preUpdated, $update, $add);
        $options = self::resolve_wpseo_option_services($records);
        if ($options === null) {
            return null;
        }
        $sitemapCallbacks = [];
        foreach ($update as $tuple) {
            $callback = $tuple[1]['function'];
            if (is_array($callback) && ($callback[0] ?? null) === self::WPSEO_SITEMAPS_CACHE) {
                $sitemapCallbacks[] = $tuple;
            }
        }
        $sitemapsPresent = array_key_exists('wpseo_sitemaps', $GLOBALS);
        if (class_exists(self::WPSEO_SITEMAPS_CACHE, false)) {
            self::assert_wpseo_sitemap_cache_map();
        }
        if (!$sitemapsPresent && $sitemapCallbacks === []) {
            return [
                'options' => $options,
                'sitemaps' => null,
                'sitemaps_cache' => null,
            ];
        }
        if (!$sitemapsPresent
            || count($sitemapCallbacks) !== 1
            || $sitemapCallbacks[0][0] !== 10
            || $sitemapCallbacks[0][1]['accepted_args'] !== 1
            || $sitemapCallbacks[0][1]['function']
                !== [self::WPSEO_SITEMAPS_CACHE, 'clear_on_option_update']) {
            throw new \RuntimeException(
                'wprism: native rewrite found incomplete or substituted Yoast SEO sitemap cache topology'
            );
        }
        $sitemaps = $GLOBALS['wpseo_sitemaps'];
        $sitemapsCache = is_object($sitemaps) ? ($sitemaps->cache ?? null) : null;
        if (!is_object($sitemaps)
            || get_class($sitemaps) !== self::WPSEO_SITEMAPS
            || !is_object($sitemapsCache)
            || get_class($sitemapsCache) !== self::WPSEO_SITEMAPS_CACHE
            || !class_exists(self::WPSEO_SITEMAPS_CACHE, false)
            || !is_callable([self::WPSEO_SITEMAPS_CACHE, 'clear_on_option_update'])) {
            throw new \RuntimeException(
                'wprism: native rewrite found incomplete or substituted Yoast SEO sitemap cache service'
            );
        }
        return [
            'options' => $options,
            'sitemaps' => $sitemaps,
            'sitemaps_cache' => $sitemapsCache,
        ];
    }

    /**
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $records
     * @return ?array<string,object>
     */
    private static function resolve_wpseo_option_services(array $records): ?array {
        $visible = class_exists('WPSEO_Options', false);
        foreach (self::WPSEO_OPTIONS as $class) {
            $visible = $visible
                || class_exists($class, false)
                || self::contains_class_callback($records, $class);
        }
        if (!$visible) {
            return null;
        }
        if (!class_exists('WPSEO_Options', false)
            || !is_callable(['WPSEO_Options', 'get_option_instance'])) {
            throw new \RuntimeException(
                'wprism: native rewrite found incomplete or substituted Yoast SEO option singletons'
            );
        }
        $options = [];
        foreach (self::WPSEO_OPTIONS as $optionName => $class) {
            try {
                // This getter only reads WPSEO_Options::$option_instances;
                // get_instance() would construct an unproved option service.
                $service = self::call_static('WPSEO_Options', 'get_option_instance', $optionName);
            } catch (\Throwable $failure) {
                throw new \RuntimeException(
                    'wprism: native rewrite could not resolve Yoast option-callback services',
                    0,
                    $failure
                );
            }
            if (!is_object($service) || get_class($service) !== $class) {
                throw new \RuntimeException(
                    'wprism: native rewrite found incomplete or substituted Yoast SEO option singletons'
                );
            }
            $options[$optionName] = $service;
        }
        return $options;
    }

    private static function assert_wpseo_sitemap_cache_map(): void {
        try {
            $cacheClear = (new \ReflectionProperty(
                self::WPSEO_SITEMAPS_CACHE,
                'cache_clear'
            ))->getValue();
        } catch (\Throwable $failure) {
            throw new \RuntimeException(
                'wprism: native rewrite could not inspect the Yoast SEO sitemap cache registration map',
                0,
                $failure
            );
        }
        if (!is_array($cacheClear)) {
            throw new \RuntimeException(
                'wprism: native rewrite found a malformed Yoast SEO sitemap cache registration map'
            );
        }
        foreach (self::MARKER_OPTIONS as $marker) {
            if (array_key_exists($marker, $cacheClear)) {
                throw new \RuntimeException(
                    'wprism: native rewrite found a TEC marker registered for Yoast SEO sitemap cache invalidation'
                );
            }
        }
    }

    /**
     * @param list<array{0:int,1:array{function:mixed,accepted_args:int}}> $records
     * @param ?array{container:object,features:object,synchronizer:object,custom_orders:object} $woo
     * @param ?array{options:array<string,object>,sitemaps:?object,sitemaps_cache:?object} $wpseo
     */
    private static function assert_normal_option_callbacks(
        string $hookName,
        array $records,
        ?array $woo,
        ?array $wpseo
    ): void {
        $expected = [];
        if ($woo !== null) {
            $expected = match ($hookName) {
                'pre_update_option' => [
                    [$woo['custom_orders'], 'process_pre_update_option', 999, 3],
                ],
                'added_option' => [
                    [$woo['features'], 'process_added_option', 999, 3],
                    [$woo['synchronizer'], 'process_added_option', 999, 2],
                ],
                'update_option', 'add_option' => [],
                default => throw new \LogicException('wprism: unknown normal option callback family'),
            };
        }
        if ($wpseo !== null) {
            $yoastExpected = [];
            foreach ($wpseo['options'] as $service) {
                if ($hookName === 'pre_update_option') {
                    $yoastExpected[] = [$service, 'add_default_filters_if_not_changed', PHP_INT_MAX, 3];
                } elseif ($hookName === 'update_option' || $hookName === 'add_option') {
                    $yoastExpected[] = [$service, 'add_default_filters_if_same_option', 10, 1];
                } elseif ($hookName !== 'added_option') {
                    throw new \LogicException('wprism: unknown normal option callback family');
                }
            }
            if ($hookName === 'update_option' && $wpseo['sitemaps_cache'] !== null) {
                $yoastExpected[] = [
                    self::WPSEO_SITEMAPS_CACHE,
                    'clear_on_option_update',
                    10,
                    1,
                ];
            }
            $expected = array_merge($expected, $yoastExpected);
        }
        $yoastServices = $wpseo === null ? [] : array_values($wpseo['options']);
        $wooServices = $woo === null ? [] : [
            $woo['features'],
            $woo['synchronizer'],
            $woo['custom_orders'],
        ];
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
                $family = 'normal';
                $callback = $record['function'];
                if (is_array($callback)) {
                    $owner = $callback[0] ?? null;
                    $class = is_object($owner) ? get_class($owner) : $owner;
                    if (is_string($class)
                        && (in_array($class, self::WPSEO_OPTIONS, true)
                            || $class === self::WPSEO_SITEMAPS_CACHE)) {
                        $family = 'Yoast SEO';
                    } elseif (is_string($class)
                        && in_array($class, [self::WOO_FEATURES, self::WOO_SYNCHRONIZER, self::WOO_CUSTOM_ORDERS], true)) {
                        $family = 'WooCommerce';
                    }
                }
                throw new \RuntimeException(
                    "wprism: native rewrite found extended or substituted $family $hookName callbacks"
                );
            }
            unset($expected[$matched]);
        }
        if ($expected !== []) {
            $yoastMissing = false;
            $wooMissing = false;
            foreach ($expected as [$object]) {
                if ($wpseo !== null
                    && ($object === self::WPSEO_SITEMAPS_CACHE
                        || in_array($object, $yoastServices, true))) {
                    $yoastMissing = true;
                }
                if ($woo !== null && in_array($object, $wooServices, true)) {
                    $wooMissing = true;
                }
            }
            $family = $yoastMissing ? 'Yoast SEO' : ($wooMissing ? 'WooCommerce' : 'normal');
            throw new \RuntimeException(
                "wprism: native rewrite found incomplete $family $hookName callbacks"
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
            throw new \RuntimeException('wprism: native rewrite lost a proven runtime function');
        }
        return \Closure::fromCallable($name)(...$args);
    }

    private static function call_static(string $class, string $method, mixed ...$args): mixed {
        if (!is_callable([$class, $method])) {
            throw new \RuntimeException('wprism: native rewrite lost a proven runtime service');
        }
        return \Closure::fromCallable([$class, $method])(...$args);
    }

    private static function call_object(mixed $object, string $method, mixed ...$args): mixed {
        if (!is_object($object) || !is_callable([$object, $method])) {
            throw new \RuntimeException('wprism: native rewrite lost a proven runtime service');
        }
        return \Closure::fromCallable([$object, $method])(...$args);
    }
}
