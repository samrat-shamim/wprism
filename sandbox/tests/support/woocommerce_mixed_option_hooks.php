<?php
/** Exact-shape native callback doubles for the Woo mixed-option boundary. */
declare(strict_types=1);

namespace Automattic\WooCommerce\Internal\DataStores\Orders {
    final class CustomOrdersTableController {
        public function process_pre_update_option(mixed $value, string $option, mixed $oldValue): mixed {
            return $value;
        }

        public function process_updated_option(string $option, mixed $oldValue, mixed $value): void {}

        public function process_updated_option_fts_index(string $option, mixed $oldValue, mixed $value): void {}
    }

    final class DataSynchronizer {
        public function process_updated_option(string $option, mixed $oldValue, mixed $value): void {}

        public function process_added_option(string $option, mixed $value): void {}
    }
}

namespace Automattic\WooCommerce\Internal\Features {
    final class FeaturesController {
        public function process_updated_option(string $option, mixed $oldValue, mixed $value): void {}

        public function process_added_option(string $option, mixed $value): void {}
    }
}

namespace Automattic\WooCommerce\Internal\Utilities {
    final class HtmlSanitizer {
        public function sanitize(string $value, array $rules = []): string {
            return strip_tags(stripslashes($value), '<br><img><p><span>');
        }
    }
}

namespace {
    final class WC_Brands_Admin {
        public function validate_product_base(array $value): array {
            if (rtrim((string) ($value['product_base'] ?? ''), "/\\") . '/' === '/%product_brand%/') {
                $value['product_base'] = '/product' . (string) $value['product_base'];
            }
            return $value;
        }
    }

    final class WP_Hook {
        /** @var array<int,array<string,array{function:callable,accepted_args:int}>> */
        public array $callbacks = [];
    }

    final class WooMixedOptionContainer {
        /** @var array<class-string,object> */
        private array $services;

        public function __construct() {
            $this->services = [
                \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController::class
                    => new \Automattic\WooCommerce\Internal\DataStores\Orders\CustomOrdersTableController(),
                \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer::class
                    => new \Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer(),
                \Automattic\WooCommerce\Internal\Features\FeaturesController::class
                    => new \Automattic\WooCommerce\Internal\Features\FeaturesController(),
                \Automattic\WooCommerce\Internal\Utilities\HtmlSanitizer::class
                    => new \Automattic\WooCommerce\Internal\Utilities\HtmlSanitizer(),
            ];
        }

        public function get(string $class): object {
            if (!isset($this->services[$class])) {
                throw new \RuntimeException('unknown Woo mixed-option test service');
            }
            return $this->services[$class];
        }
    }

    if (!function_exists('wc_get_container')) {
        function wc_get_container(): object {
            $container = $GLOBALS['wooMixedOptionContainer'] ?? null;
            if (!is_object($container)) {
                throw new \RuntimeException('Woo mixed-option test container is unavailable');
            }
            return $container;
        }
    }

    if (!function_exists('wp_filter_default_autoload_value_via_option_size')) {
        function wp_filter_default_autoload_value_via_option_size(
            mixed $autoload,
            string $option,
            mixed $value,
            string $serializedValue
        ): mixed {
            return $autoload;
        }
    }
}
