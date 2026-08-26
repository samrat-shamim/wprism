<?php
declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\Features {
    final class FeaturesController {
        public function process_updated_option(string $name, mixed $old, mixed $new): void {
            $GLOBALS['tec_readiness_woo_calls'][] = [__METHOD__, $name];
        }

        public function process_added_option(string $name, mixed $value): void {
            $GLOBALS['tec_readiness_woo_calls'][] = [__METHOD__, $name];
        }
    }
}

namespace Automattic\WooCommerce\Internal\DataStores\Orders {
    final class DataSynchronizer {
        public function process_updated_option(string $name, mixed $old, mixed $new): void {
            $GLOBALS['tec_readiness_woo_calls'][] = [__METHOD__, $name];
        }

        public function process_added_option(string $name, mixed $value): void {
            $GLOBALS['tec_readiness_woo_calls'][] = [__METHOD__, $name];
        }
    }

    final class CustomOrdersTableController {
        public function process_pre_update_option(mixed $value, string $name, mixed $old): mixed {
            $GLOBALS['tec_readiness_woo_calls'][] = [__METHOD__, $name];
            return $value;
        }

        public function process_updated_option(string $name, mixed $old, mixed $new): void {
            $GLOBALS['tec_readiness_woo_calls'][] = [__METHOD__, $name];
        }

        public function process_updated_option_fts_index(string $name, mixed $old, mixed $new): void {
            $GLOBALS['tec_readiness_woo_calls'][] = [__METHOD__, $name];
        }
    }
}

namespace Automattic\WooCommerce {
    final class Container {
        /** @param array<class-string,object> $services */
        public function __construct(private readonly array $services) {}

        public function get(string $class): object {
            $service = $this->services[$class] ?? null;
            if (!is_object($service)) {
                throw new \RuntimeException('offline WooCommerce service is unavailable');
            }
            return $service;
        }
    }
}

namespace {
    trait TecReadinessYoastOptionSingleton {
        private static ?self $instance = null;

        private function __construct() {
            $GLOBALS['tec_readiness_yoast_constructs'] =
                1 + (int) ($GLOBALS['tec_readiness_yoast_constructs'] ?? 0);
        }

        public static function get_instance(): self {
            return self::$instance ??= new self();
        }

        public function add_default_filters_if_not_changed(mixed $value, string $name, mixed $old): mixed {
            $GLOBALS['tec_readiness_yoast_calls'][] = [__METHOD__, $name, $old, $value];
            return $value;
        }

        public function add_default_filters_if_same_option(string $name): void {
            $GLOBALS['tec_readiness_yoast_calls'][] = [__METHOD__, $name];
        }
    }

    final class WPSEO_Option_Wpseo { use TecReadinessYoastOptionSingleton; }
    final class WPSEO_Option_Titles { use TecReadinessYoastOptionSingleton; }
    final class WPSEO_Option_Social { use TecReadinessYoastOptionSingleton; }
    final class WPSEO_Taxonomy_Meta { use TecReadinessYoastOptionSingleton; }
    final class WPSEO_Option_Llmstxt { use TecReadinessYoastOptionSingleton; }
    final class WPSEO_Option_Tracking_Only { use TecReadinessYoastOptionSingleton; }

    final class WPSEO_Sitemaps_Cache {
        /** @var array<string,string|list<string>> */
        protected static $cache_clear = [
            'wpseo' => '',
            'home' => '',
            'wpseo_titles' => '',
        ];
        protected static bool $clear_all = false;
        /** @var list<string> */
        protected static array $clear_types = [];

        public function __construct() {
            add_action('update_option', [self::class, 'clear_on_option_update'], 10, 1);
        }

        public static function register_clear_on_option_update(string $name, string $type = ''): void {
            self::$cache_clear[$name] = $type;
        }

        public static function clear_on_option_update(string $name): void {
            if (!array_key_exists($name, self::$cache_clear)) {
                return;
            }
            $GLOBALS['tec_readiness_yoast_calls'][] = [__METHOD__, $name];
            $type = self::$cache_clear[$name];
            if ($type === '') {
                self::$clear_all = true;
                return;
            }
            self::$clear_types = array_values(array_unique(array_merge(
                self::$clear_types,
                (array) $type
            )));
        }
    }

    final class WPSEO_Sitemaps {
        public WPSEO_Sitemaps_Cache $cache;

        public function __construct() {
            $this->cache = new WPSEO_Sitemaps_Cache();
        }
    }

    final class WPSEO_Options {
        /** @var mixed */
        protected static $option_values = ['stripcategorybase' => false];
        /** @var array<string,object> */
        private static array $optionInstances = [];

        public static function get(string $name): mixed {
            $GLOBALS['tec_readiness_yoast_policy_reads'][] = $name;
            return is_array(self::$option_values) ? (self::$option_values[$name] ?? null) : null;
        }

        public static function set_option_values(mixed $values): void {
            self::$option_values = $values;
        }

        public static function register_option(string $name, object $instance): void {
            self::$optionInstances[$name] = $instance;
        }

        public static function unregister_option(string $name): void {
            unset(self::$optionInstances[$name]);
        }

        public static function get_option_instance(string $name): object|false {
            $GLOBALS['tec_readiness_yoast_option_reads'][] = $name;
            return self::$optionInstances[$name] ?? false;
        }
    }

    /** The Yoast 28.3 category wrapper is a stateless normal callback. */
    final class WPSEO_Rewrite {
        /** @param array<string,string> $rules @return array<string,string> */
        public function category_rewrite_rules_wrapper(array $rules): array {
            $GLOBALS['tec_readiness_rewrite_calls'][] = [__METHOD__, 'category'];
            // The current fixture models stripcategorybase=false. Enabled
            // mode is intentionally not emulated: production preflight
            // refuses its open get_categories()/get_terms() filter graph.
            if (WPSEO_Options::get('stripcategorybase') === false) {
                if (($GLOBALS['tec_readiness_yoast_rewrite_drift'] ?? null) instanceof self) {
                    $GLOBALS['wpseo_rewrite'] = $GLOBALS['tec_readiness_yoast_rewrite_drift'];
                    unset($GLOBALS['tec_readiness_yoast_rewrite_drift']);
                }
                return $rules;
            }
            get_terms(['taxonomy' => 'category']);
            return $rules;
        }
    }

    final class Yoast_Dynamic_Rewrites {
        private static ?self $instance = null;
        /** @var array<string,string> */
        protected array $extra_rules_top = ['^yoast-top/?$' => 'index.php?yoast=top'];
        /** @var array<string,string> */
        protected array $extra_rules_bottom = ['^yoast-bottom/?$' => 'index.php?yoast=bottom'];
        public object $wp_rewrite;

        private function __construct() {
            $this->wp_rewrite = $GLOBALS['wp_rewrite'];
            add_filter('option_rewrite_rules', [$this, 'filter_rewrite_rules_option'], 10, 1);
            add_filter('sanitize_option_rewrite_rules', [$this, 'sanitize_rewrite_rules_option'], 10, 1);
        }

        public static function instance(): self {
            return self::$instance ??= new self();
        }

        public function filter_rewrite_rules_option(mixed $rules): mixed {
            $GLOBALS['tec_readiness_rewrite_calls'][] = [__METHOD__, 'option'];
            return is_array($rules)
                ? array_merge($this->extra_rules_top, $rules, $this->extra_rules_bottom)
                : $rules;
        }

        public function sanitize_rewrite_rules_option(mixed $rules): mixed {
            $GLOBALS['tec_readiness_rewrite_calls'][] = [__METHOD__, 'sanitize'];
            return is_array($rules)
                ? array_diff_key($rules, $this->extra_rules_top, $this->extra_rules_bottom)
                : $rules;
        }
    }

    final class PLL_Links_Directory {
        /** @param list<string> $types */
        public function __construct(private array $types) {}

        public function prepare_rewrite_rules(): void {}

        /** @return list<string> */
        public function get_rewrite_rules_filters(): array {
            $GLOBALS['tec_readiness_polylang_roster_reads'] =
                1 + (int) ($GLOBALS['tec_readiness_polylang_roster_reads'] ?? 0);
            return apply_filters('pll_rewrite_rules', $this->types);
        }

        /** @param array<string,string> $rules @return array<string,string> */
        public function rewrite_rules(array $rules): array {
            $GLOBALS['tec_readiness_rewrite_calls'][] = [__METHOD__, 'rewrite_rules_array'];
            foreach ($rules as $pattern => $query) {
                apply_filters('pll_modify_rewrite_rule', true, [$pattern => $query], 'rewrite_rules_array', false);
            }
            return $rules;
        }
    }

    final class PLL_Sitemaps {
        /** @param array<string,string> $rules @return array<string,string> */
        public function rewrite_rules(array $rules): array {
            $GLOBALS['tec_readiness_rewrite_calls'][] = [__METHOD__, 'rewrite_rules_array'];
            return $rules;
        }
    }

    #[AllowDynamicProperties]
    final class PLL_Admin {
        public function __construct(public object $links_model) {}
    }

    final class PLL_Frontend {
        public function __construct(public object $links_model) {}
    }

    final class WC_Settings_Tracking {
        public function track_setting_change(string $name, mixed $old, mixed $new): void {
            $GLOBALS['tec_readiness_woo_calls'][] = [__METHOD__, $name];
        }
    }

    /** The exact Woo 11.0.1 helper returns the global canonical container. */
    function wc_get_container(): object {
        return $GLOBALS['wc_container'];
    }

    /** @param array<string,string> $rules @return array<string,string> */
    function wc_fix_rewrite_rules(array $rules): array {
        $GLOBALS['tec_readiness_rewrite_calls'][] = [__FUNCTION__, 'array'];
        if (isset($GLOBALS['tec_readiness_rewrite_drift_permastructs'])) {
            $GLOBALS['wp_rewrite']->extra_permastructs =
                $GLOBALS['tec_readiness_rewrite_drift_permastructs'];
            unset($GLOBALS['tec_readiness_rewrite_drift_permastructs']);
        }
        if (isset($GLOBALS['tec_readiness_rewrite_drift_woo_container'])) {
            $GLOBALS['wc_container'] = $GLOBALS['tec_readiness_rewrite_drift_woo_container'];
            unset($GLOBALS['tec_readiness_rewrite_drift_woo_container']);
        }
        return $rules;
    }

    function PLL(): object {
        return $GLOBALS['polylang'];
    }

    /**
     * @return array{
     *   container:object,features:object,synchronizer:object,custom_orders:object,
     *   yoast:object,yoast_rewrite:object,yoast_options:array<string,object>,yoast_sitemaps:object,yoast_sitemaps_cache:object,
     *   polylang:object,polylang_links:object,polylang_sitemaps:object,
     *   polylang_types:list<string>
     * }
     */
    function tec_readiness_install_woo_option_callbacks(): array {
        $features = new \Automattic\WooCommerce\Internal\Features\FeaturesController();
        $synchronizer = new \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer();
        $customOrders =
            new \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController();
        $container = new \Automattic\WooCommerce\Container([
            get_class($features) => $features,
            get_class($synchronizer) => $synchronizer,
            get_class($customOrders) => $customOrders,
        ]);
        $GLOBALS['wc_container'] = $container;
        add_action('updated_option', [$features, 'process_updated_option'], 999, 3);
        add_action('updated_option', [$synchronizer, 'process_updated_option'], 999, 3);
        add_action('updated_option', [$customOrders, 'process_updated_option'], 999, 3);
        add_action('updated_option', [$customOrders, 'process_updated_option_fts_index'], 999, 3);
        add_filter('pre_update_option', [$customOrders, 'process_pre_update_option'], 999, 3);
        add_action('added_option', [$features, 'process_added_option'], 999, 3);
        add_action('added_option', [$synchronizer, 'process_added_option'], 999, 2);
        add_filter('rewrite_rules_array', 'wc_fix_rewrite_rules', 10, 1);
        $yoastOptions = [];
        foreach ([
            'wpseo' => WPSEO_Option_Wpseo::class,
            'wpseo_titles' => WPSEO_Option_Titles::class,
            'wpseo_social' => WPSEO_Option_Social::class,
            'wpseo_taxonomy_meta' => WPSEO_Taxonomy_Meta::class,
            'wpseo_llmstxt' => WPSEO_Option_Llmstxt::class,
            'wpseo_tracking_only' => WPSEO_Option_Tracking_Only::class,
        ] as $name => $class) {
            $yoastOptions[$name] = $class::get_instance();
            WPSEO_Options::register_option($name, $yoastOptions[$name]);
            add_filter(
                'pre_update_option',
                [$yoastOptions[$name], 'add_default_filters_if_not_changed'],
                PHP_INT_MAX,
                3
            );
            add_action(
                'update_option',
                [$yoastOptions[$name], 'add_default_filters_if_same_option'],
                10,
                1
            );
            add_action(
                'add_option',
                [$yoastOptions[$name], 'add_default_filters_if_same_option'],
                10,
                1
            );
        }
        WPSEO_Options::set_option_values(['stripcategorybase' => false]);
        $sitemaps = new WPSEO_Sitemaps();
        $GLOBALS['wpseo_sitemaps'] = $sitemaps;
        $yoast = Yoast_Dynamic_Rewrites::instance();
        $polylangTypes = [
            'date', 'root', 'comments', 'search', 'author', 'attachment', 'category', 'product', 'product_cat',
        ];
        $polylangLinks = new PLL_Links_Directory($polylangTypes);
        $polylang = new PLL_Admin($polylangLinks);
        $polylang->sitemaps = new PLL_Sitemaps();
        $GLOBALS['polylang'] = $polylang;
        add_action('pll_prepare_rewrite_rules', [$polylangLinks, 'prepare_rewrite_rules'], 10, 1);
        add_filter('rewrite_rules_array', [$polylang->sitemaps, 'rewrite_rules'], 10, 1);
        add_filter('rewrite_rules_array', [$polylangLinks, 'rewrite_rules'], 10, 1);
        foreach ($polylangTypes as $type) {
            add_filter($type . '_rewrite_rules', [$polylangLinks, 'rewrite_rules'], 10, 1);
        }
        $yoastRewrite = new WPSEO_Rewrite();
        $GLOBALS['wpseo_rewrite'] = $yoastRewrite;
        add_filter(
            'category_rewrite_rules',
            [$yoastRewrite, 'category_rewrite_rules_wrapper'],
            10,
            1
        );
        $services = [
            'container' => $container,
            'features' => $features,
            'synchronizer' => $synchronizer,
            'custom_orders' => $customOrders,
            'yoast_options' => $yoastOptions,
            'yoast_rewrite' => $yoastRewrite,
            'yoast_sitemaps' => $sitemaps,
            'yoast_sitemaps_cache' => $sitemaps->cache,
            'yoast' => $yoast,
            'polylang' => $polylang,
            'polylang_links' => $polylangLinks,
            'polylang_sitemaps' => $polylang->sitemaps,
            'polylang_types' => $polylangTypes,
        ];
        $GLOBALS['tec_readiness_woo_services'] = $services;
        return $services;
    }

    /**
     * @param array{
     *   container:object,features:object,synchronizer:object,custom_orders:object,
     *   yoast:object,yoast_rewrite:object,yoast_options:array<string,object>,yoast_sitemaps:object,yoast_sitemaps_cache:object,
     *   polylang:object,polylang_links:object,polylang_sitemaps:object,
     *   polylang_types:list<string>
     * } $services
     */
    function tec_readiness_remove_woo_option_callbacks(array $services): void {
        remove_action('updated_option', [$services['features'], 'process_updated_option'], 999);
        remove_action('updated_option', [$services['synchronizer'], 'process_updated_option'], 999);
        remove_action('updated_option', [$services['custom_orders'], 'process_updated_option'], 999);
        remove_action('updated_option', [$services['custom_orders'], 'process_updated_option_fts_index'], 999);
        remove_filter('pre_update_option', [$services['custom_orders'], 'process_pre_update_option'], 999);
        remove_action('added_option', [$services['features'], 'process_added_option'], 999);
        remove_action('added_option', [$services['synchronizer'], 'process_added_option'], 999);
        foreach ($services['yoast_options'] as $name => $service) {
            remove_filter('pre_update_option', [$service, 'add_default_filters_if_not_changed'], PHP_INT_MAX);
            remove_action('update_option', [$service, 'add_default_filters_if_same_option'], 10);
            remove_action('add_option', [$service, 'add_default_filters_if_same_option'], 10);
            WPSEO_Options::unregister_option($name);
        }
        remove_filter(
            'category_rewrite_rules',
            [$services['yoast_rewrite'], 'category_rewrite_rules_wrapper'],
            10
        );
        remove_action('update_option', [WPSEO_Sitemaps_Cache::class, 'clear_on_option_update'], 10);
        remove_filter('rewrite_rules_array', 'wc_fix_rewrite_rules', 10);
        remove_filter('option_rewrite_rules', [$services['yoast'], 'filter_rewrite_rules_option'], 10);
        remove_filter('sanitize_option_rewrite_rules', [$services['yoast'], 'sanitize_rewrite_rules_option'], 10);
        remove_filter('rewrite_rules_array', [$services['polylang_sitemaps'], 'rewrite_rules'], 10);
        remove_filter('rewrite_rules_array', [$services['polylang_links'], 'rewrite_rules'], 10);
        foreach ($services['polylang_types'] as $type) {
            remove_filter($type . '_rewrite_rules', [$services['polylang_links'], 'rewrite_rules'], 10);
        }
        unset($GLOBALS['wc_container'], $GLOBALS['polylang'], $GLOBALS['wpseo_rewrite'], $GLOBALS['wpseo_sitemaps']);
    }

    /** Temporarily isolate the TEC option writer from the Yoast option family. */
    function tec_readiness_suspend_yoast_option_callbacks(array $services): void {
        foreach ($services['yoast_options'] as $service) {
            remove_filter('pre_update_option', [$service, 'add_default_filters_if_not_changed'], PHP_INT_MAX);
            remove_action('update_option', [$service, 'add_default_filters_if_same_option'], 10);
            remove_action('add_option', [$service, 'add_default_filters_if_same_option'], 10);
        }
        remove_filter(
            'category_rewrite_rules',
            [$services['yoast_rewrite'], 'category_rewrite_rules_wrapper'],
            10
        );
        remove_action('update_option', [WPSEO_Sitemaps_Cache::class, 'clear_on_option_update'], 10);
    }

    /** Restore the exact Yoast option callback family after a TEC-only write. */
    function tec_readiness_resume_yoast_option_callbacks(array $services): void {
        foreach ($services['yoast_options'] as $service) {
            add_filter('pre_update_option', [$service, 'add_default_filters_if_not_changed'], PHP_INT_MAX, 3);
            add_action('update_option', [$service, 'add_default_filters_if_same_option'], 10, 1);
            add_action('add_option', [$service, 'add_default_filters_if_same_option'], 10, 1);
        }
        add_filter(
            'category_rewrite_rules',
            [$services['yoast_rewrite'], 'category_rewrite_rules_wrapper'],
            10,
            1
        );
        add_action('update_option', [WPSEO_Sitemaps_Cache::class, 'clear_on_option_update'], 10, 1);
    }
}
