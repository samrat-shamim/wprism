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

namespace TEC\Common\lucatume\DI52\Builders {
    final class Resolver {
        /** @param mixed $bindings */
        public function __construct(protected mixed $bindings) {}

        public function resolve(string $id): object {
            ++$GLOBALS['wooMixedTecFactoryCalls'];
            throw new \RuntimeException('TEC mixed-option admission must not resolve or construct a service');
        }
    }

    final class ValueBuilder {
        public function __construct(private object $value) {}

        public function build(): object {
            ++$GLOBALS['wooMixedTecFactoryCalls'];
            throw new \RuntimeException('TEC mixed-option admission must not build a service');
        }
    }
}

namespace TEC\Common\lucatume\DI52 {
    class Container {
        public function __construct(protected mixed $resolver) {}
    }
}

namespace TEC\Common\Contracts {
    class Container extends \TEC\Common\lucatume\DI52\Container {}
}

namespace Tribe\Events\Views\V2 {
    final class Hooks {
        public function action_save_wplang(string $option, mixed $oldValue, mixed $value): void {
            ++$GLOBALS['wooMixedTecCallbackCalls'];
        }
    }
}

namespace {
    final class Tribe__Cache {}

    final class Tribe__Main {
        public const OPTIONNAME = 'tribe_events_calendar_options';
    }

    final class Tribe__Container extends \TEC\Common\Contracts\Container {
        protected static ?self $instance = null;

        public static function install(?self $instance): void {
            self::$instance = $instance;
        }

        public static function instance(): self {
            ++$GLOBALS['wooMixedTecFactoryCalls'];
            throw new \RuntimeException('TEC mixed-option admission must not call Tribe__Container::instance');
        }
    }

    final class Tribe__Settings_Manager {
        private static ?self $instance = null;

        public static function install(?self $instance): void {
            self::$instance = $instance;
        }

        public static function instance(): self {
            ++$GLOBALS['wooMixedTecFactoryCalls'];
            throw new \RuntimeException('TEC mixed-option admission must not call Tribe__Settings_Manager::instance');
        }

        public function update_options_cache(string $option, mixed $oldValue, mixed $value): void {
            ++$GLOBALS['wooMixedTecCallbackCalls'];
        }
    }

    final class Tribe__Events__Aggregator {
        private static ?self $instance = null;

        public static function install(?self $instance): void {
            self::$instance = $instance;
        }

        public static function instance(): self {
            ++$GLOBALS['wooMixedTecFactoryCalls'];
            throw new \RuntimeException('TEC mixed-option admission must not call Tribe__Events__Aggregator::instance');
        }

        public function action_purge_transients(string $option): void {
            ++$GLOBALS['wooMixedTecCallbackCalls'];
        }
    }

    final class Tribe__Cache_Listener {
        private static ?self $instance = null;
        /** @var list<string> */
        public array $writes = [];

        public function __construct(private object $cache) {}

        public static function install(?self $instance = null): ?self {
            if (func_num_args() === 0) {
                return self::$instance ??= new self(new Tribe__Cache());
            }
            self::$instance = $instance;
            return self::$instance;
        }

        public static function instance(): self {
            ++$GLOBALS['wooMixedTecFactoryCalls'];
            throw new \RuntimeException('TEC mixed-option admission must not call Tribe__Cache_Listener::instance');
        }

        public function update_last_updated_option(string $option, mixed $oldValue, mixed $value): void {
            $GLOBALS['wooMixedTecCallbackCalls'] = ($GLOBALS['wooMixedTecCallbackCalls'] ?? 0) + 1;
            if ($option === 'rewrite_rules') {
                $this->mark('tribe_last_updated_option');
            }
        }

        public function update_last_save_post(string $option, mixed $oldValue, mixed $value): void {
            $GLOBALS['wooMixedTecCallbackCalls'] = ($GLOBALS['wooMixedTecCallbackCalls'] ?? 0) + 1;
            if ($option === 'rewrite_rules') {
                $this->mark('tribe_last_save_post');
            }
        }

        public function generate_rewrite_rules(): void {
            $this->mark('tribe_last_generate_rewrite_rules');
        }

        private function mark(string $option): void {
            $this->writes[] = $option;
            $GLOBALS['wooHierarchyTecPurgeRequested'] = true;
            if (function_exists('woo_hierarchy_test_set_option')) {
                woo_hierarchy_test_set_option($option, (float) count($this->writes));
            }
        }
    }

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
