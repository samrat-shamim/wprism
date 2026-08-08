<?php
/**
 * Focused fault-injection sub-contract for WooCommerceContract's composed
 * whole-catalog rebuilder. Wired through regress-woocommerce-contract so a
 * failed WordPress/DB read can never look like an empty catalog or projection.
 */

namespace Automattic\WooCommerce\Internal\ProductAttributesLookup {
    final class DataRegenerator {
        public function initiate_regeneration(bool $useActionScheduler): void {}
        public function do_regeneration_step($limit = null, bool $direct = false): bool { return false; }
        public function get_last_regeneration_step_failed(): bool { return false; }
        public function finalize_regeneration(bool $direct = false): void {}
    }

    final class LookupDataStore {
        public function create_data_for_product($product, bool $optimized = false): void {}
        public function get_last_create_operation_failed(): bool { return false; }
    }
}

namespace Automattic\WooCommerce\Internal\Admin {
    final class CategoryLookup {
        private static ?self $instance = null;
        public static function instance(): self { return self::$instance ??= new self(); }
        public function regenerate(): void {}
    }
}

namespace {
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }
    if (!defined('WC_VERSION')) {
        define('WC_VERSION', '11.0.0');
    }

    final class WC_Product_Variable {}
    final class WC_Product_Grouped {}

    final class WooContractReadFakeWpdb {
        public string $prefix = 'wp_';
        public string $postmeta = 'wp_postmeta';
        public string $posts = 'wp_posts';
        public string $terms = 'wp_terms';
        public string $term_taxonomy = 'wp_term_taxonomy';
        public string $term_relationships = 'wp_term_relationships';
        public string $last_error = '';
        public ?string $failReadContaining = null;
        public int $deleteCalls = 0;
        public int $insertCalls = 0;

        public function prepare(string $sql, ...$args): string {
            foreach ($args as $arg) {
                $replacement = is_int($arg)
                    ? (string) $arg
                    : "'" . str_replace("'", "''", (string) $arg) . "'";
                $sql = (string) preg_replace('/%[ds]/', $replacement, $sql, 1);
            }
            return $sql;
        }

        private function fails(string $sql): bool {
            if ($this->failReadContaining === null || !str_contains($sql, $this->failReadContaining)) {
                return false;
            }
            $this->last_error = 'simulated read failure';
            return true;
        }

        public function get_col(string $sql): array {
            if ($this->fails($sql)) {
                return [];
            }
            if (str_contains($sql, "meta_key = '_price'")) {
                if (preg_match('/post_id = (\d+)/', $sql, $match)) {
                    return match ((int) $match[1]) {
                        21 => ['8.00'],
                        30 => ['9.99'],
                        default => [],
                    };
                }
            }
            return [];
        }

        public function get_row(string $sql, $output = null): ?array {
            if ($this->fails($sql)) {
                return null;
            }
            if (str_contains($sql, 'wp_wc_product_meta_lookup')) {
                return [
                    'min_price' => '9.99',
                    'max_price' => '9.99',
                    'stock_quantity' => null,
                    'stock_status' => null,
                ];
            }
            return null;
        }

        public function get_results(string $sql, $output = null): array {
            if ($this->fails($sql)) {
                return [];
            }
            return [];
        }

        public function get_var(string $sql): mixed {
            if ($this->fails($sql)) {
                return null;
            }
            if (str_contains($sql, 'COUNT(*)')) {
                return '1';
            }
            return null;
        }

        public function delete(string $table, array $where, $whereFormat = null): int|false {
            $this->deleteCalls++;
            return 1;
        }

        public function insert(string $table, array $data, $format = null): int|false {
            $this->insertCalls++;
            return 1;
        }
    }

    final class WooContractReadFakeProduct {
        public function __construct(
            private int $id,
            private string $type,
            private array $children = [],
            private string $regularPrice = '9.99'
        ) {}

        public function is_type(string|array $type): bool {
            return is_array($type)
                ? in_array($this->type, $type, true)
                : $this->type === $type;
        }
        public function get_visible_children(): array { return $this->children; }
        public function get_children(string $context = 'view'): array { return $this->children; }
        public function is_on_sale(string $context = 'view'): bool { return false; }
        public function get_sale_price(string $context = 'view'): string { return ''; }
        public function get_regular_price(string $context = 'view'): string { return $this->regularPrice; }
        public function get_date_on_sale_from(string $context = 'view') { return null; }
        public function get_date_on_sale_to(string $context = 'view') { return null; }
    }

    final class WooContractReadFakeContainer {
        public function get(string $class): object {
            return $class === \Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore::class
                ? new \Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore()
                : new \Automattic\WooCommerce\Internal\ProductAttributesLookup\DataRegenerator();
        }
    }

    $fakeCatalogIds = [];
    $fakeCatalogReadFailure = false;
    $fakeProducts = [];

    function get_posts(array $args): array {
        global $wpdb, $fakeCatalogIds, $fakeCatalogReadFailure;
        if ($fakeCatalogReadFailure) {
            $wpdb->last_error = 'simulated catalog read failure';
            return [];
        }
        return $fakeCatalogIds;
    }
    function clean_post_cache(int $id): void {}
    function wc_get_product(int $id): object|false {
        global $fakeProducts;
        return $fakeProducts[$id] ?? false;
    }
    function wc_update_product_lookup_tables(): void {}
    function wc_maybe_schedule_product_sale_events(int $id): void {}
    function wc_get_container(): WooContractReadFakeContainer { return new WooContractReadFakeContainer(); }
    function as_next_scheduled_action(string $hook, array $args, string $group) { return false; }
    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool { return true; }
    function remove_filter(string $hook, callable $callback, int $priority = 10): bool { return true; }

    require dirname(__DIR__, 2) . '/agent/src/TransientDbException.php';
    require dirname(__DIR__, 2) . '/agent/src/Db.php';
    require dirname(__DIR__, 2) . '/agent/src/WooCommerceContract.php';

    $failures = 0;
    function woo_read_check(bool $condition, string $message): void {
        global $failures;
        echo ($condition ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$condition) {
            $failures++;
        }
    }

    $wpdb = new WooContractReadFakeWpdb();
    $fakeProducts = [
        20 => new WooContractReadFakeProduct(20, 'variable', [21]),
        30 => new WooContractReadFakeProduct(30, 'simple'),
    ];

    $contractSource = (string) file_get_contents(dirname(__DIR__, 2) . '/agent/src/WooCommerceContract.php');
    $operationalSource = substr($contractSource, (int) strpos($contractSource, 'public static function rebuild'));
    woo_read_check(
        preg_match('/\$wpdb->get_(?:col|row|results|var)\s*\(/', $operationalSource) !== 1
            && preg_match('/(?<!checked_)get_posts\s*\(/', $operationalSource) !== 1,
        'every composed rebuilder decision read routes through a checked boundary'
    );

    $fakeCatalogIds = [];
    $fakeCatalogReadFailure = true;
    $catalogFailure = null;
    try {
        \Duo\WooCommerceContract::rebuild();
    } catch (\Throwable $failure) {
        $catalogFailure = $failure->getMessage();
    }
    woo_read_check(str_contains((string) $catalogFailure, 'catalog enumeration query failed')
        && $wpdb->deleteCalls === 0 && $wpdb->insertCalls === 0,
        'failed catalog enumeration cannot become a successful empty rebuild or mutate derived prices');

    $fakeCatalogReadFailure = false;
    $fakeCatalogIds = [20];
    $wpdb->failReadContaining = 'post_id = 21';
    $parentFailure = null;
    try {
        \Duo\WooCommerceContract::rebuild();
    } catch (\Throwable $failure) {
        $parentFailure = $failure->getMessage();
    }
    woo_read_check(str_contains((string) $parentFailure, 'child price read for product 21 query failed')
        && $wpdb->deleteCalls === 0 && $wpdb->insertCalls === 0,
        'failed child-price read cannot erase a parent price projection');

    $verify = new \ReflectionMethod(\Duo\WooCommerceContract::class, 'verify');
    $verify->setAccessible(true);
    $wpdb->failReadContaining = null;
    $verify->invoke(null, [30]);
    woo_read_check(true, 'fault-injection verifier fixture has a valid exact baseline');

    $verificationFailures = [
        'wc_product_meta_lookup' => 'product lookup verification for product 30 query failed',
        "meta_key='_stock_status'" => 'stock-status verification for product 30 query failed',
        "post_type = 'product_variation'" => 'attribute source verification query failed',
        "tt.taxonomy='product_cat'" => 'category source verification query failed',
    ];
    foreach ($verificationFailures as $needle => $expectedMessage) {
        $wpdb->failReadContaining = $needle;
        $message = null;
        try {
            $verify->invoke(null, [30]);
        } catch (\Throwable $failure) {
            $message = $failure->getMessage();
        }
        woo_read_check(str_contains((string) $message, $expectedMessage),
            "verification read fails closed at $needle");
    }

    if ($failures > 0) {
        echo "FAIL: $failures check(s) failed\n";
        exit(1);
    }
    echo "ALL WOO COMPOSED READ-FAILURE CHECKS PASSED\n";
}
