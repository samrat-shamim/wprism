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

namespace Automattic\WooCommerce\Internal\DependencyManagement {
    final class RuntimeContainer {
        /** @param array<class-string,object> $resolved_cache */
        public function __construct(private array $resolved_cache) {}

        public function resolve_or_construct(string $class): object {
            ++$GLOBALS['wooMixedContainerGetCalls'];
            throw new \RuntimeException('Woo mixed-option admission must not construct a service');
        }
    }
}

namespace Automattic\WooCommerce {
    final class Container {
        public function __construct(
            private readonly \Automattic\WooCommerce\Internal\DependencyManagement\RuntimeContainer $container
        ) {}

        public function get(string $class): object {
            ++$GLOBALS['wooMixedContainerGetCalls'];
            throw new \RuntimeException('Woo mixed-option admission must not call Container::get');
        }
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
    final class WC_Settings_Tracking {
        /** @var list<string> */
        protected $allowed_options = [];
        /** @var list<string> */
        public array $calls = [];
        /** @var list<string> */
        public array $events = [];

        /** @param list<string> $options */
        public function set_allowed_options(array $options): void {
            $this->allowed_options = $options;
        }

        public function track_settings_page_view(): void { $this->calls[] = __FUNCTION__; }
        public function add_option_to_list(): void { $this->calls[] = __FUNCTION__; }
        public function add_option_to_list_and_track_setting_change(): void { $this->calls[] = __FUNCTION__; }
        public function send_settings_change_event(): void { $this->calls[] = __FUNCTION__; $this->events[] = 'tracks'; }
        public function possibly_add_settings_tracking_scripts(): void { $this->calls[] = __FUNCTION__; }

        public function track_setting_change(string $option, mixed $oldValue, mixed $value): void {
            $this->calls[] = __FUNCTION__;
            $this->events[] = $option;
        }
    }

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

    if (!function_exists('wc_get_container')) {
        function wc_get_container(): object {
            $container = $GLOBALS['wc_container'] ?? null;
            if (!is_object($container)) {
                throw new \RuntimeException('Woo mixed-option test container is unavailable');
            }
            return $container;
        }
    }

    trait WooMixedYoastOption {
        public function add_default_filters_if_not_changed(mixed $value, string $name, mixed $old): mixed {
            $GLOBALS['wooMixedYoastCalls'][] = [__METHOD__, $name];
            return $value;
        }

        public function add_default_filters_if_same_option(string $name): void {
            $GLOBALS['wooMixedYoastCalls'][] = [__METHOD__, $name];
        }
    }

    final class WPSEO_Option_Wpseo { use WooMixedYoastOption; }
    final class WPSEO_Option_Titles { use WooMixedYoastOption; }
    final class WPSEO_Option_Social { use WooMixedYoastOption; }
    final class WPSEO_Taxonomy_Meta { use WooMixedYoastOption; }
    final class WPSEO_Option_Llmstxt { use WooMixedYoastOption; }
    final class WPSEO_Option_Tracking_Only { use WooMixedYoastOption; }

    final class WPSEO_Options {
        /** @var array<string,object> */
        private static array $instances = [];

        public static function register_option(string $name, object $service): void {
            self::$instances[$name] = $service;
        }

        public static function unregister_option(string $name): void {
            unset(self::$instances[$name]);
        }

        public static function get_option_instance(string $name): object|false {
            $GLOBALS['wooMixedYoastReads'][] = $name;
            return self::$instances[$name] ?? false;
        }
    }

    final class WPSEO_Sitemaps_Cache {
        /** @var array<string,string|list<string>> */
        protected static $cache_clear = ['wpseo' => '', 'wpseo_titles' => ''];

        public static function clear_on_option_update(string $name): void {
            $GLOBALS['wooMixedYoastCalls'][] = [__METHOD__, $name];
        }
    }

    final class WPSEO_Sitemaps {
        public WPSEO_Sitemaps_Cache $cache;

        public function __construct() {
            $this->cache = new WPSEO_Sitemaps_Cache();
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
