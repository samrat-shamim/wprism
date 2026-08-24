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
    final class WC_Settings_Tracking {
        public function track_setting_change(string $name, mixed $old, mixed $new): void {
            $GLOBALS['tec_readiness_woo_calls'][] = [__METHOD__, $name];
        }
    }

    /** The exact Woo 11.0.1 helper returns the global canonical container. */
    function wc_get_container(): object {
        return $GLOBALS['wc_container'];
    }

    /** @return array{container:object,features:object,synchronizer:object,custom_orders:object} */
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
        return [
            'container' => $container,
            'features' => $features,
            'synchronizer' => $synchronizer,
            'custom_orders' => $customOrders,
        ];
    }

    /** @param array{container:object,features:object,synchronizer:object,custom_orders:object} $services */
    function tec_readiness_remove_woo_option_callbacks(array $services): void {
        remove_action('updated_option', [$services['features'], 'process_updated_option'], 999);
        remove_action('updated_option', [$services['synchronizer'], 'process_updated_option'], 999);
        remove_action('updated_option', [$services['custom_orders'], 'process_updated_option'], 999);
        remove_action('updated_option', [$services['custom_orders'], 'process_updated_option_fts_index'], 999);
        remove_filter('pre_update_option', [$services['custom_orders'], 'process_pre_update_option'], 999);
        remove_action('added_option', [$services['features'], 'process_added_option'], 999);
        remove_action('added_option', [$services['synchronizer'], 'process_added_option'], 999);
        unset($GLOBALS['wc_container']);
    }
}
