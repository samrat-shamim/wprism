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
     *   yoast:object,polylang:object,polylang_links:object,polylang_sitemaps:object,
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
        $yoast = Yoast_Dynamic_Rewrites::instance();
        $polylangTypes = [
            'date', 'root', 'comments', 'search', 'author', 'attachment', 'product', 'product_cat',
        ];
        $polylangLinks = new PLL_Links_Directory($polylangTypes);
        $polylang = new PLL_Admin($polylangLinks);
        $polylang->sitemaps = new PLL_Sitemaps();
        $GLOBALS['polylang'] = $polylang;
        add_filter('rewrite_rules_array', [$polylang->sitemaps, 'rewrite_rules'], 10, 1);
        add_filter('rewrite_rules_array', [$polylangLinks, 'rewrite_rules'], 10, 1);
        foreach ($polylangTypes as $type) {
            add_filter($type . '_rewrite_rules', [$polylangLinks, 'rewrite_rules'], 10, 1);
        }
        return [
            'container' => $container,
            'features' => $features,
            'synchronizer' => $synchronizer,
            'custom_orders' => $customOrders,
            'yoast' => $yoast,
            'polylang' => $polylang,
            'polylang_links' => $polylangLinks,
            'polylang_sitemaps' => $polylang->sitemaps,
            'polylang_types' => $polylangTypes,
        ];
    }

    /**
     * @param array{
     *   container:object,features:object,synchronizer:object,custom_orders:object,
     *   yoast:object,polylang:object,polylang_links:object,polylang_sitemaps:object,
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
        remove_filter('rewrite_rules_array', 'wc_fix_rewrite_rules', 10);
        remove_filter('option_rewrite_rules', [$services['yoast'], 'filter_rewrite_rules_option'], 10);
        remove_filter('sanitize_option_rewrite_rules', [$services['yoast'], 'sanitize_rewrite_rules_option'], 10);
        remove_filter('rewrite_rules_array', [$services['polylang_sitemaps'], 'rewrite_rules'], 10);
        remove_filter('rewrite_rules_array', [$services['polylang_links'], 'rewrite_rules'], 10);
        foreach ($services['polylang_types'] as $type) {
            remove_filter($type . '_rewrite_rules', [$services['polylang_links'], 'rewrite_rules'], 10);
        }
        unset($GLOBALS['wc_container'], $GLOBALS['polylang']);
    }
}
