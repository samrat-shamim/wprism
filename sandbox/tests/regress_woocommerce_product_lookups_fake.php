<?php
/**
 * Offline fake-WP/DB regression for the WooCommerce lookup adapter.
 *
 * This intentionally models only the public Woo APIs the shipped adapter is
 * allowed to call.  It proves stale _price repair, variable-root dedupe,
 * deletion context (including the parent id), runtime stock preservation, and
 * exact attribute/meta lookup verification without Docker.
 */

namespace Automattic\WooCommerce\Internal\ProductAttributesLookup {
    final class LookupDataStore {
        public const ACTION_DELETE = 3;
        public static ?self $instance = null;
        public bool $failed = false;
        public int $createCalls = 0;
        public int $deleteCalls = 0;

        public function create_data_for_product($product, $optimized = false): void {
            global $fakeProducts, $fakeAttrLookup;
            $this->createCalls++;
            $rootId = (int) (is_object($product) ? $product->get_id() : $product);
            $root = \wc_get_product($rootId);
            if (!$root) {
                $this->failed = true;
                return;
            }
            $rows = [];
            $attrs = (array) $root->get_attributes();
            if ($root->get_type() !== 'variable') {
                foreach ($attrs as $taxonomy => $attribute) {
                    if (!is_object($attribute) || !(int) $attribute->get_id() || (bool) $attribute->get_variation()) {
                        continue;
                    }
                    foreach ((array) $attribute->get_options() as $termId) {
                        $termId = (int) $termId;
                        if ($termId > 0) {
                            $rows[] = [
                                'product_id' => $rootId,
                                'product_or_parent_id' => $rootId,
                                'taxonomy' => $taxonomy,
                                'term_id' => $termId,
                                'is_variation_attribute' => 0,
                                'in_stock' => $root->is_in_stock() ? 1 : 0,
                            ];
                        }
                    }
                }
            } else {
                foreach ($attrs as $taxonomy => $attribute) {
                    foreach ((array) $attribute->get_options() as $termId) {
                        if (!(bool) $attribute->get_variation()) {
                            $rows[] = [
                                'product_id' => $rootId,
                                'product_or_parent_id' => $rootId,
                                'taxonomy' => $taxonomy,
                                'term_id' => (int) $termId,
                                'is_variation_attribute' => 0,
                                'in_stock' => $root->is_in_stock() ? 1 : 0,
                            ];
                        }
                    }
                }
                foreach ((array) $root->get_children() as $childId) {
                    $child = \wc_get_product((int) $childId);
                    foreach ($attrs as $taxonomy => $attribute) {
                        if (!(bool) $attribute->get_variation() || !$child) {
                            continue;
                        }
                        $slug = (string) (($child->get_attributes()[$taxonomy] ?? ''));
                        $term = $slug === 'red' ? 101 : ($slug === 'blue' ? 102 : 0);
                        if ($term > 0) {
                            $rows[] = [
                                'product_id' => (int) $childId,
                                'product_or_parent_id' => $rootId,
                                'taxonomy' => $taxonomy,
                                'term_id' => $term,
                                'is_variation_attribute' => 1,
                                'in_stock' => $child->is_in_stock() ? 1 : 0,
                            ];
                        }
                    }
                }
            }
            foreach ($fakeAttrLookup as $key => $_row) {
                if ((int) ($_row['product_or_parent_id'] ?? 0) === $rootId) {
                    unset($fakeAttrLookup[$key]);
                }
            }
            foreach ($rows as $row) {
                $fakeAttrLookup[] = $row;
            }
        }

        public function run_update_callback(int $id, int $action): void {
            global $fakeAttrLookup;
            $this->deleteCalls++;
            if ($action !== self::ACTION_DELETE) {
                return;
            }
            foreach ($fakeAttrLookup as $key => $row) {
                if ((int) $row['product_id'] === $id || (int) $row['product_or_parent_id'] === $id) {
                    unset($fakeAttrLookup[$key]);
                }
            }
            $fakeAttrLookup = array_values($fakeAttrLookup);
        }

        public function get_last_create_operation_failed(): bool {
            return $this->failed;
        }
    }
}

namespace Automattic\WooCommerce\Internal\Caches {
    final class ProductCache {
        public static array $removed = [];
        public function remove(int $id): bool {
            global $fakeProductCache, $fakeCacheEvents;
            self::$removed[] = $id;
            $fakeCacheEvents[] = "remove:$id";
            unset($fakeProductCache[$id]);
            return true;
        }
    }
}

namespace {
    if (!defined('DUO_SPEC_VERSION')) {
        define('DUO_SPEC_VERSION', 2);
    }
    if (!defined('ARRAY_A')) {
        define('ARRAY_A', 'ARRAY_A');
    }

    final class FakeWpdb {
        public string $prefix = 'wp_';
        public string $postmeta = 'wp_postmeta';
        public string $posts = 'wp_posts';
        public string $last_error = '';
        private bool $transactionActive = false;
        private array $transactionMeta = [];
        private array $transactionMetaLookup = [];
        private array $transactionAttrLookup = [];

        public function prepare(string $query, ...$args): string {
            $index = 0;
            return (string) preg_replace_callback('/%[dsif]/', static function (array $match) use ($args, &$index): string {
                if (!array_key_exists($index, $args)) {
                    return $match[0];
                }
                $arg = $args[$index++];
                return is_int($arg) || is_float($arg)
                    ? (string) $arg
                    : "'" . addslashes((string) $arg) . "'";
            }, $query);
        }

        public function query(string $query): int|false {
            global $fakeMeta, $fakeMetaLookup, $fakeAttrLookup;
            $command = strtoupper(trim($query));
            if ($command === 'START TRANSACTION') {
                if ($this->transactionActive) {
                    return false;
                }
                $this->transactionMeta = $fakeMeta;
                $this->transactionMetaLookup = $fakeMetaLookup;
                $this->transactionAttrLookup = $fakeAttrLookup;
                $this->transactionActive = true;
                return 0;
            }
            if ($command === 'COMMIT') {
                if (!$this->transactionActive) {
                    return false;
                }
                $this->transactionActive = false;
                $this->transactionMeta = [];
                $this->transactionMetaLookup = [];
                $this->transactionAttrLookup = [];
                return 0;
            }
            if ($command === 'ROLLBACK') {
                if (!$this->transactionActive) {
                    return false;
                }
                $fakeMeta = $this->transactionMeta;
                $fakeMetaLookup = $this->transactionMetaLookup;
                $fakeAttrLookup = $this->transactionAttrLookup;
                $this->transactionActive = false;
                $this->transactionMeta = [];
                $this->transactionMetaLookup = [];
                $this->transactionAttrLookup = [];
                return 0;
            }
            if (preg_match("/DELETE FROM wp_postmeta WHERE post_id = (\\d+) AND meta_key = '_price'/", $query, $m)) {
                $id = (int) $m[1];
                $fakeMeta[$id]['_price'] = [];
                return 1;
            }
            return 0;
        }

        public function insert(string $table, array $data, array $formats): int|false {
            global $fakeMeta;
            if ($table !== $this->postmeta || ($data['meta_key'] ?? '') !== '_price') {
                return false;
            }
            $fakeMeta[(int) $data['post_id']]['_price'][] = (string) $data['meta_value'];
            return 1;
        }

        public function get_row(string $query, $output = null): ?array {
            global $fakeMetaLookup;
            if (preg_match('/FROM `wp_wc_product_meta_lookup` WHERE product_id = (\\d+)/', $query, $m)) {
                return $fakeMetaLookup[(int) $m[1]] ?? null;
            }
            return null;
        }

        /**
         * Model the adapter's targeted _children reverse lookup.  This is
         * deliberately a fixture map, not a product query: the real adapter
         * asks SQL only for grouped candidates containing the changed id and
         * validates each candidate with the public Woo product object.
         */
        public function get_col(string $query): array {
            global $fakeGroupedChildren;
            if (!str_contains($query, "meta_key = '_children'")) {
                return [];
            }
            $childId = 0;
            if (preg_match('/i:(\\d+);/', $query, $m)) {
                $childId = (int) $m[1];
            } elseif (preg_match('/s:\\d+:"(\\d+)";/', $query, $m)) {
                $childId = (int) $m[1];
            }
            if ($childId <= 0) {
                return [];
            }
            $ids = [];
            foreach (($fakeGroupedChildren ?? []) as $parentId => $children) {
                if (in_array($childId, array_map('intval', (array) $children), true)) {
                    $ids[(int) $parentId] = (int) $parentId;
                }
            }
            ksort($ids, SORT_NUMERIC);
            return array_values($ids);
        }

        public function get_results(string $query, $output = null): array {
            global $fakeAttrLookup;
            if (str_contains($query, 'wc_product_attributes_lookup')) {
                preg_match('/product_or_parent_id = (\\d+) OR product_id = (\\d+)/', $query, $m);
                $root = (int) ($m[1] ?? 0);
                return array_values(array_filter($fakeAttrLookup, static fn(array $row): bool =>
                    (int) $row['product_or_parent_id'] === $root || (int) $row['product_id'] === $root
                ));
            }
            return [];
        }

        public function get_var(string $query): mixed {
            global $fakeMetaLookup, $fakeAttrLookup;
            if (trim($query) === 'SELECT @@in_transaction') {
                return $this->transactionActive ? '1' : '0';
            }
            if (preg_match('/COUNT\\(\\*\\) FROM `?wp_wc_product_meta_lookup`? WHERE product_id = (\\d+)/', $query, $m)) {
                return isset($fakeMetaLookup[(int) $m[1]]) ? 1 : 0;
            }
            if (preg_match('/COUNT\\(\\*\\) FROM wp_wc_product_attributes_lookup WHERE product_id = (\\d+) OR product_or_parent_id = (\\d+)/', $query, $m)) {
                $a = (int) $m[1];
                $b = (int) $m[2];
                return count(array_filter($fakeAttrLookup, static fn(array $row): bool =>
                    (int) $row['product_id'] === $a || (int) $row['product_or_parent_id'] === $b
                ));
            }
            return null;
        }
    }

    final class FakeProductAttribute {
        public function __construct(private int $id, private array $options, private bool $variation) {}
        public function get_id(): int { return $this->id; }
        public function get_options(): array { return $this->options; }
        public function get_variation(): bool { return $this->variation; }
    }

    final class FakeProduct {
        public function __construct(
            private int $id,
            private string $type,
            private int $parent,
            private array $children,
            private array $attributes,
            private bool $stock,
            ?array $visibleChildren = null,
            private string $status = 'publish',
            private string $catalogVisibility = 'visible'
        ) {
            $this->visibleChildren = $visibleChildren ?? $children;
        }
        private array $visibleChildren;
        private ?array $cachedPrices = null;
        public function get_id(): int { return $this->id; }
        public function get_type(): string { return $this->type; }
        public function get_parent_id(string $context = 'view'): int { return $this->parent; }
        public function is_type(string $type): bool { return $this->type === $type; }
        public function prime_cached_price(array $prices): void { $this->cachedPrices = $prices; }
        public function get_price(string $context = 'view'): string {
            global $fakeMeta;
            $prices = $this->cachedPrices ?? ($fakeMeta[$this->id]['_price'] ?? []);
            return (string) ($prices[0] ?? '');
        }
        public function get_children(): array { return $this->children; }
        public function get_visible_children(): array {
            if ($this->status !== 'publish' || $this->catalogVisibility === 'hidden') {
                return [];
            }
            return $this->visibleChildren;
        }
        public function get_attributes(): array { return $this->attributes; }
        public function is_in_stock(): bool { return $this->stock; }
        public function get_status(): string { return $this->status; }
        public function get_catalog_visibility(): string { return $this->catalogVisibility; }
        public function set_parent(int $parent): void { $this->parent = $parent; }
        public function set_children(array $children): void { $this->children = $children; }
        public function set_attributes(array $attributes): void { $this->attributes = $attributes; }
        public function set_visible_children(array $children): void { $this->visibleChildren = $children; }
        public function set_status(string $status): void { $this->status = $status; }
        public function set_catalog_visibility(string $visibility): void { $this->catalogVisibility = $visibility; }
    }

    final class WC_Data_Store {
        public static ?FakeProductStore $product = null;
        public static ?FakeVariableStore $variable = null;
        public static ?FakeGroupedStore $grouped = null;
        public static function load(string $name): object {
            self::$product ??= new FakeProductStore();
            self::$variable ??= new FakeVariableStore();
            self::$grouped ??= new FakeGroupedStore();
            return $name === 'product-variable'
                ? self::$variable
                : ($name === 'product-grouped' ? self::$grouped : self::$product);
        }
    }

    final class WC_Cache_Helper {
        /** @var array<int,string> */
        public static array $invalidatedGroups = [];
        /** @var list<string> */
        public static array $invalidatedAttributes = [];
        /** @var list<array{group:string,refresh:bool,value:string}> */
        public static array $transientVersionCalls = [];

        public static function invalidate_cache_group(string $group): void {
            self::$invalidatedGroups[] = $group;
        }

        /**
         * Woo queues these transient deletes until shutdown.  The fake
         * performs the queue's observable delete immediately so the test can
         * assert the concrete `wc_layered_nav_counts_<attribute>` keys rather
         * than passing through a no-op helper.
         *
         * @param list<string> $attributeKeys
         */
        public static function invalidate_attribute_count(array $attributeKeys): void {
            foreach ($attributeKeys as $attributeKey) {
                $attributeKey = (string) $attributeKey;
                self::$invalidatedAttributes[] = $attributeKey;
                \delete_transient('wc_layered_nav_counts_' . $attributeKey);
            }
        }

        public static function get_transient_version(string $group, bool $refresh = false): string {
            global $fakeTransientVersions;
            $value = (string) ($fakeTransientVersions[$group] ?? '');
            if ($refresh || $value === '') {
                // A deterministic fake value keeps repeated adapter calls
                // easy to inspect while preserving the real get/set shape.
                $value = 'fake-' . $group . '-version';
                $fakeTransientVersions[$group] = $value;
            }
            self::$transientVersionCalls[] = [
                'group' => $group,
                'refresh' => $refresh,
                'value' => $value,
            ];
            return $value;
        }
    }

    final class FakeProductStore {
        public function delete_from_lookup_table(int $id, string $table): void {
            global $fakeMetaLookup, $fakeMeta, $fakeProducts;
            unset($fakeMetaLookup[$id]);
            unset($fakeMeta[$id], $fakeProducts[$id]);
        }
        public function refresh_product_lookup_table(int $id): void {
            global $fakeMeta, $fakeMetaLookup;
            $prices = array_values($fakeMeta[$id]['_price'] ?? []);
            $first = $prices[0] ?? null;
            $last = $prices[count($prices) - 1] ?? null;
            $price = (string) ($first ?? '');
            $sale = (string) ($fakeMeta[$id]['_sale_price'][0] ?? '');
            $fakeMetaLookup[$id] = [
                'product_id' => $id,
                'sku' => (string) (($fakeMeta[$id]['_sku'][0] ?? '')),
                'virtual' => 0,
                'downloadable' => 0,
                // The real Woo lookup schema is DECIMAL(19,4) NOT NULL with
                // a zero default, so a no-price product reads back 0.0000.
                'min_price' => $first === null ? '0.0000' : $first,
                'max_price' => $last === null ? '0.0000' : $last,
                'onsale' => ((bool) $sale && $price === $sale) ? 1 : 0,
                'stock_quantity' => (($fakeMeta[$id]['_manage_stock'][0] ?? '') === 'yes')
                    ? (float) ($fakeMeta[$id]['_stock'][0] ?? 0)
                    : null,
                'stock_status' => 'instock',
                'rating_count' => 0,
                // The real Woo lookup schema is DECIMAL(3,2) with a 0.00
                // default and normalizes empty source meta to that value.
                'average_rating' => ((string) ($fakeMeta[$id]['_wc_average_rating'][0] ?? '')) === ''
                    ? '0.00'
                    : (string) $fakeMeta[$id]['_wc_average_rating'][0],
                // The real Woo lookup schema is BIGINT NOT NULL with a zero
                // default, so absent source meta is normalized to integer 0.
                'total_sales' => (string) ($fakeMeta[$id]['total_sales'][0] ?? '0'),
                'tax_status' => 'taxable',
                'tax_class' => '',
                'global_unique_id' => '',
            ];
        }
    }

    final class FakeVariableStore {
        public int $calls = 0;
        public function sync_price(&$product): void {
            global $fakeMeta, $fakeSyncFailures;
            $this->calls++;
            $prices = [];
            foreach ($product->get_visible_children() as $childId) {
                foreach ((array) ($fakeMeta[(int) $childId]['_price'] ?? []) as $price) {
                    if ($price !== '') {
                        $prices[(string) $price] = (string) $price;
                    }
                }
            }
            usort($prices, static fn(string $a, string $b): int => (float) $a <=> (float) $b);
            $fakeMeta[$product->get_id()]['_price'] = $prices;
            // Woo's public variable sync clears these authored parent rows.
            // The adapter must restore them, including when this simulated
            // call fails after the derived write has started.
            $fakeMeta[$product->get_id()]['_regular_price'] = [];
            $fakeMeta[$product->get_id()]['_sale_price'] = [];
            if (($fakeSyncFailures['variable'][$product->get_id()] ?? 0) > 0) {
                $fakeSyncFailures['variable'][$product->get_id()]--;
                throw new \RuntimeException('simulated variable sync failure');
            }
        }
    }

    final class FakeGroupedStore {
        public int $calls = 0;

        public function sync_price(&$product): void {
            global $fakeMeta, $fakeSyncFailures;
            $this->calls++;
            $prices = [];
            foreach ((array) $product->get_children() as $childId) {
                $child = \wc_get_product((int) $childId);
                if ($child && is_callable([$child, 'get_price'])) {
                    $price = (string) $child->get_price('edit');
                    if ($price !== '') {
                        $prices[] = $price;
                    }
                }
            }
            usort($prices, static fn(string $a, string $b): int => (float) $a <=> (float) $b);
            $fakeMeta[$product->get_id()]['_price'] = $prices
                ? [$prices[0], $prices[count($prices) - 1]]
                : [];
            // Woo's grouped data store derives only prices here. Runtime
            // stock/order metadata must remain target-local and untouched.
            $fakeMeta[$product->get_id()]['_regular_price'] = [];
            $fakeMeta[$product->get_id()]['_sale_price'] = [];
            if (($fakeSyncFailures['grouped'][$product->get_id()] ?? 0) > 0) {
                $fakeSyncFailures['grouped'][$product->get_id()]--;
                throw new \RuntimeException('simulated grouped sync failure');
            }
        }
    }

    final class FakeContainer {
        public function get(string $class): object {
            if ($class === '\\Automattic\\WooCommerce\\Internal\\Caches\\ProductCache') {
                return new \Automattic\WooCommerce\Internal\Caches\ProductCache();
            }
            return \Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore::$instance
                ??= new \Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore();
        }
    }

    $fakeMeta = [
        10 => ['_price' => ['999'], '_regular_price' => [''], '_sale_price' => [''], '_sale_price_dates_from' => [''], '_sale_price_dates_to' => [''], '_stock_status' => ['instock'], '_manage_stock' => ['yes'], '_stock' => ['5'], '_tax_status' => ['taxable'], '_tax_class' => [''], '_wc_rating_count' => [[]], '_sku' => [''], '_virtual' => ['no'], '_downloadable' => ['no'], 'total_sales' => ['0'], '_wc_average_rating' => [''], '_global_unique_id' => ['']],
        11 => ['_price' => ['999'], '_regular_price' => ['20'], '_sale_price' => ['18'], '_sale_price_dates_from' => [''], '_sale_price_dates_to' => [''], '_stock_status' => ['instock'], '_manage_stock' => ['yes'], '_stock' => ['5'], '_tax_status' => ['taxable'], '_tax_class' => [''], '_wc_rating_count' => [[]], '_sku' => [''], '_virtual' => ['no'], '_downloadable' => ['no'], 'total_sales' => ['0'], '_wc_average_rating' => [''], '_global_unique_id' => ['']],
        12 => ['_price' => ['21'], '_regular_price' => ['21'], '_sale_price' => [''], '_sale_price_dates_from' => [''], '_sale_price_dates_to' => [''], '_stock_status' => ['instock'], '_manage_stock' => ['yes'], '_stock' => ['5'], '_tax_status' => ['taxable'], '_tax_class' => [''], '_wc_rating_count' => [[]], '_sku' => [''], '_virtual' => ['no'], '_downloadable' => ['no'], 'total_sales' => ['0'], '_wc_average_rating' => [''], '_global_unique_id' => ['']],
        13 => ['_price' => ['999'], '_regular_price' => ['21'], '_sale_price' => ['0'], '_sale_price_dates_from' => [''], '_sale_price_dates_to' => [''], '_stock_status' => ['instock'], '_manage_stock' => ['yes'], '_stock' => ['5'], '_tax_status' => ['taxable'], '_tax_class' => [''], '_wc_rating_count' => [[]], '_sku' => [''], '_virtual' => ['no'], '_downloadable' => ['no'], 'total_sales' => ['0'], '_wc_average_rating' => [''], '_global_unique_id' => ['']],
    ];
    $fakeMeta[20] = $fakeMeta[10];
    $fakeMeta[20]['_price'] = ['999'];
    $fakeMeta[20]['_regular_price'] = ['32', '31'];
    $fakeMeta[20]['_sale_price'] = ['29'];
    $fakeMeta[21] = $fakeMeta[11];
    $fakeMeta[21]['_price'] = ['999'];
    $fakeMeta[22] = $fakeMeta[10];
    $fakeMeta[22]['_price'] = ['777'];
    $fakeMeta[24] = $fakeMeta[10];
    $fakeMeta[24]['_price'] = ['666'];
    $fakeMeta[30] = $fakeMeta[10];
    $fakeMeta[30]['_price'] = ['888'];
    $fakeMeta[31] = $fakeMeta[11];
    $fakeMeta[31]['_price'] = ['999'];
    $fakeMeta[31]['_stock'] = ['7'];
    unset($fakeMeta[13]['total_sales']);
    $fakeMeta[14] = $fakeMeta[10];
    $fakeMeta[14]['_price'] = [];
    $fakeMeta[14]['_regular_price'] = [''];
    $fakeMeta[14]['_sale_price'] = [''];
    $fakeMeta[15] = $fakeMeta[10];
    $fakeMeta[15]['_price'] = ['15'];
    $fakeMeta[15]['_regular_price'] = ['15'];
    $fakeMeta[15]['_sale_price'] = [''];
    $fakeProducts = [
        10 => new FakeProduct(10, 'variable', 0, [11, 12], ['pa_color' => new FakeProductAttribute(7, [101, 102], true)], true),
        11 => new FakeProduct(11, 'variation', 10, [], ['pa_color' => 'red'], true),
        12 => new FakeProduct(12, 'variation', 10, [], ['pa_color' => 'blue'], true),
        13 => new FakeProduct(13, 'simple', 0, [], [], true),
        20 => new FakeProduct(20, 'variable', 0, [21], ['pa_color' => new FakeProductAttribute(8, [101, 102], true)], true),
        21 => new FakeProduct(21, 'variation', 20, [], ['pa_color' => 'red'], true),
        22 => new FakeProduct(22, 'variable', 0, [], ['pa_color' => new FakeProductAttribute(9, [101, 102], true)], true),
        24 => new FakeProduct(24, 'variable', 0, [], ['pa_color' => new FakeProductAttribute(10, [101, 102], true)], true),
        30 => new FakeProduct(30, 'variable', 0, [31], ['pa_color' => new FakeProductAttribute(11, [101, 102], true)], true, [], 'draft', 'hidden'),
        31 => new FakeProduct(31, 'variation', 30, [], ['pa_color' => 'red'], true),
        14 => new FakeProduct(14, 'simple', 0, [], [], true),
        15 => new FakeProduct(15, 'simple', 0, [], ['pa_grind-size' => new FakeProductAttribute(7, [101], false)], true),
    ];
    // wc_get_product() below returns a cached clone.  Mutating $fakeProducts
    // therefore leaves a deliberately stale Woo object in this cache until
    // ProductCache::remove() invalidates it, just like Woo's product factory.
    $fakeProductCache = [];
    $fakeCacheEvents = [];
    $fakeTransientVersions = [];
    $fakeProductTransientCalls = [];
    $fakeMetaLookup = [
        10 => ['product_id' => 10, 'sku' => '', 'virtual' => 0, 'downloadable' => 0, 'min_price' => '999', 'max_price' => '999', 'onsale' => 0, 'stock_quantity' => 5, 'stock_status' => 'instock', 'rating_count' => 0, 'average_rating' => '', 'total_sales' => '0', 'tax_status' => 'taxable', 'tax_class' => '', 'global_unique_id' => ''],
        11 => ['product_id' => 11, 'sku' => '', 'virtual' => 0, 'downloadable' => 0, 'min_price' => '999', 'max_price' => '999', 'onsale' => 0, 'stock_quantity' => 5, 'stock_status' => 'instock', 'rating_count' => 0, 'average_rating' => '', 'total_sales' => '0', 'tax_status' => 'taxable', 'tax_class' => '', 'global_unique_id' => ''],
        12 => ['product_id' => 12, 'sku' => '', 'virtual' => 0, 'downloadable' => 0, 'min_price' => '21', 'max_price' => '21', 'onsale' => 0, 'stock_quantity' => 5, 'stock_status' => 'instock', 'rating_count' => 0, 'average_rating' => '', 'total_sales' => '0', 'tax_status' => 'taxable', 'tax_class' => '', 'global_unique_id' => ''],
        20 => ['product_id' => 20, 'sku' => '', 'virtual' => 0, 'downloadable' => 0, 'min_price' => '777', 'max_price' => '777', 'onsale' => 0, 'stock_quantity' => 5, 'stock_status' => 'instock', 'rating_count' => 0, 'average_rating' => '', 'total_sales' => '0', 'tax_status' => 'taxable', 'tax_class' => '', 'global_unique_id' => ''],
        21 => ['product_id' => 21, 'sku' => '', 'virtual' => 0, 'downloadable' => 0, 'min_price' => '777', 'max_price' => '777', 'onsale' => 0, 'stock_quantity' => 5, 'stock_status' => 'instock', 'rating_count' => 0, 'average_rating' => '', 'total_sales' => '0', 'tax_status' => 'taxable', 'tax_class' => '', 'global_unique_id' => ''],
        22 => ['product_id' => 22, 'sku' => '', 'virtual' => 0, 'downloadable' => 0, 'min_price' => '777', 'max_price' => '777', 'onsale' => 0, 'stock_quantity' => 5, 'stock_status' => 'instock', 'rating_count' => 0, 'average_rating' => '', 'total_sales' => '0', 'tax_status' => 'taxable', 'tax_class' => '', 'global_unique_id' => ''],
        24 => ['product_id' => 24, 'sku' => '', 'virtual' => 0, 'downloadable' => 0, 'min_price' => '666', 'max_price' => '666', 'onsale' => 0, 'stock_quantity' => 5, 'stock_status' => 'instock', 'rating_count' => 0, 'average_rating' => '', 'total_sales' => '0', 'tax_status' => 'taxable', 'tax_class' => '', 'global_unique_id' => ''],
        30 => ['product_id' => 30, 'sku' => '', 'virtual' => 0, 'downloadable' => 0, 'min_price' => '888', 'max_price' => '888', 'onsale' => 0, 'stock_quantity' => 5, 'stock_status' => 'instock', 'rating_count' => 0, 'average_rating' => '', 'total_sales' => '0', 'tax_status' => 'taxable', 'tax_class' => '', 'global_unique_id' => ''],
        31 => ['product_id' => 31, 'sku' => '', 'virtual' => 0, 'downloadable' => 0, 'min_price' => '999', 'max_price' => '999', 'onsale' => 0, 'stock_quantity' => 5, 'stock_status' => 'instock', 'rating_count' => 0, 'average_rating' => '', 'total_sales' => '0', 'tax_status' => 'taxable', 'tax_class' => '', 'global_unique_id' => ''],
    ];
    $fakeMetaLookup[14] = $fakeMetaLookup[10];
    $fakeMetaLookup[14]['product_id'] = 14;
    $fakeMetaLookup[15] = $fakeMetaLookup[10];
    $fakeMetaLookup[15]['product_id'] = 15;
    $fakeAttrLookup = [];
    $wpdb = new FakeWpdb();
    $fakeRegisteredTaxonomies = [];
    $fakeDeletedTransients = [];
    $fakeWpCacheDeletes = [];
    $fakeCacheHooks = [];
    $fakeGroupedChildren = [];
    $fakeSyncFailures = ['variable' => [], 'grouped' => []];
    $fakeMetaRestoreFailures = [];

    function wc_get_product($id = false) {
        global $fakeProducts, $fakeProductCache, $fakeCacheEvents, $fakeMeta;
        $id = (int) $id;
        if (isset($fakeProductCache[$id])) {
            $fakeCacheEvents[] = "read:$id:cached";
            return $fakeProductCache[$id];
        }
        if (!isset($fakeProducts[$id])) {
            $fakeCacheEvents[] = "read:$id:missing";
            return false;
        }
        $fakeCacheEvents[] = "read:$id:fresh";
        $product = clone $fakeProducts[$id];
        $product->prime_cached_price((array) ($fakeMeta[$id]['_price'] ?? []));
        // Before the adapter refreshes Woo's registry, a same-request
        // wc_get_product() read sees a global attribute as unregistered and
        // drops it from the parsed product object. This models the live
        // post-init typed-table bootstrap gap.
        if ($id === 15 && !taxonomy_exists('pa_grind-size')) {
            $product->set_attributes([]);
        }
        return $fakeProductCache[$id] = $product;
    }
    function delete_transient(string $key): bool {
        global $fakeDeletedTransients;
        $fakeDeletedTransients[] = $key;
        return true;
    }
    function wc_get_attribute_taxonomies(): array {
        return [
            (object) ['attribute_id' => 7, 'attribute_name' => 'grind-size', 'attribute_label' => 'Grind Size'],
            (object) ['attribute_id' => 8, 'attribute_name' => 'color', 'attribute_label' => 'Color'],
        ];
    }
    function wc_attribute_taxonomy_name(string $name): string {
        return 'pa_' . $name;
    }
    function taxonomy_exists(string $taxonomy): bool {
        global $fakeRegisteredTaxonomies;
        return in_array($taxonomy, $fakeRegisteredTaxonomies, true);
    }
    function register_taxonomy(string $taxonomy, array $objectTypes, array $args = []): object {
        global $fakeRegisteredTaxonomies;
        $fakeRegisteredTaxonomies[] = $taxonomy;
        return (object) ['name' => $taxonomy, 'object_type' => $objectTypes, 'args' => $args];
    }
    function wc_get_container(): FakeContainer { return new FakeContainer(); }
    function get_post_meta(int $id, string $key, bool $single = true) {
        global $fakeMeta;
        $values = $fakeMeta[$id][$key] ?? [];
        return $single ? ($values[0] ?? '') : $values;
    }
    function delete_post_meta(int $id, string $key): bool {
        global $fakeMeta;
        $hadRows = !empty($fakeMeta[$id][$key]);
        unset($fakeMeta[$id][$key]);
        return $hadRows;
    }
    function add_post_meta(int $id, string $key, $value, bool $unique = false): int|false {
        global $fakeMeta, $fakeMetaRestoreFailures;
        if ($unique && !empty($fakeMeta[$id][$key])) {
            return false;
        }
        if (($fakeMetaRestoreFailures[$id][$key] ?? 0) > 0) {
            $fakeMetaRestoreFailures[$id][$key]--;
            return false;
        }
        $fakeMeta[$id][$key] ??= [];
        $fakeMeta[$id][$key][] = $value;
        return count($fakeMeta[$id][$key]);
    }
    function clean_post_cache(int $id): void {
        global $fakeCacheHooks, $fakeProducts;
        $fakeCacheHooks[] = ['hook' => 'clean_object_term_cache', 'id' => $id];
        $fakeCacheHooks[] = ['hook' => 'clean_post_cache', 'id' => $id];
        // WP 6.8 keeps post_parent:<id> and last_changed in the posts
        // group; post_ancestors/post_parent are not cache groups here.
        wp_cache_delete((string) $id, 'posts');
        wp_cache_delete('post_parent:' . $id, 'posts');
        wp_cache_delete('last_changed', 'posts');
        wp_cache_delete((string) $id, 'post_meta');
        wp_cache_delete('last_changed', 'terms');
        wp_cache_delete('wp_get_archives', 'general');
        $product = $fakeProducts[$id] ?? null;
        if (is_object($product) && is_callable([$product, 'get_attributes'])) {
            foreach (array_keys((array) $product->get_attributes()) as $taxonomy) {
                $taxonomy = (string) $taxonomy;
                if ($taxonomy !== '') {
                    wp_cache_delete((string) $id, $taxonomy . '_relationships');
                }
            }
        }
    }
    function wc_delete_product_transients(int $id = 0): void {
        global $fakeProducts, $fakeProductTransientCalls;
        $fixed = [
            'wc_products_onsale',
            'wc_featured_products',
            'wc_outofstock_count',
            'wc_low_stock_count',
        ];
        foreach ($fixed as $transient) {
            delete_transient($transient);
        }
        $ids = $id > 0 ? [$id] : [];
        $product = $id > 0 ? ($fakeProducts[$id] ?? null) : null;
        if (is_object($product) && is_callable([$product, 'get_parent_id'])) {
            $parentId = (int) $product->get_parent_id('edit');
            if ($parentId > 0) {
                $ids[] = $parentId;
            }
        }
        $specific = [
            'wc_product_children_',
            'wc_var_prices_',
            'wc_related_',
            'wc_child_has_weight_',
            'wc_child_has_dimensions_',
        ];
        foreach (array_values(array_unique(array_map('intval', $ids))) as $productId) {
            foreach ($specific as $prefix) {
                delete_transient($prefix . $productId);
            }
        }
        WC_Cache_Helper::get_transient_version('product', true);
        $fakeProductTransientCalls[] = $id;
    }
    function wp_cache_delete(string $key, string $group): void {
        global $fakeWpCacheDeletes;
        $fakeWpCacheDeletes[] = ['key' => $key, 'group' => $group];
    }
    function wc_stock_amount($value) { return (float) $value; }
    function get_terms(array $args): array { return [101 => 'red', 102 => 'blue']; }
    function wc_sanitize_taxonomy_name(string $name): string { return $name; }
    function is_wp_error($value): bool { return false; }
    function get_option(string $key, $default = false) { return $key === 'woocommerce_schema_version' ? 1000 : $default; }

    require dirname(__DIR__, 2) . '/agent/src/Canon.php';
    require dirname(__DIR__, 2) . '/agent/src/OptionState.php';
    require dirname(__DIR__, 2) . '/agent/src/Policy.php';
    require dirname(__DIR__, 2) . '/manifests/regenerators/woocommerce-product-lookups.php';

    $reflection = new \ReflectionClass(\Duo\Policy::class);
    $policy = $reflection->newInstanceWithoutConstructor();
    $adapter = new \Duo\Regenerators\WoocommerceProductLookups($policy);
    // Prime a product read before the registry refresh. The adapter must evict
    // this stale parsed object after registering the newly-applied taxonomy.
    wc_get_product(15);
    $adapter->regenerate_batch([10, 11, 12], []);

    $failures = 0;
    $check = static function (bool $condition, string $message) use (&$failures): void {
        echo ($condition ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$condition) { $failures++; }
    };
    $ratingEqual = new \ReflectionMethod(\Duo\Regenerators\WoocommerceProductLookups::class, 'lookup_values_equal');
    $ratingEqual->setAccessible(true);
    $check((bool) $ratingEqual->invoke($adapter, '', '0.00', 'average_rating'),
        'empty authored average rating matches Woo decimal zero normalization');
    $check((bool) $ratingEqual->invoke($adapter, '4.50', '4.5000005', 'average_rating'),
        'average rating decimal formatting uses a tiny numeric tolerance');
    $check(!(bool) $ratingEqual->invoke($adapter, '4.50', '4.49', 'average_rating'),
        'average rating verifier rejects a real nonzero mismatch');
    $check((bool) $ratingEqual->invoke($adapter, '', '0', 'total_sales'),
        'empty authored total sales matches Woo integer zero normalization');
    $check((bool) $ratingEqual->invoke($adapter, '12', '12.0', 'total_sales'),
        'numeric total sales values compare by their numeric meaning');
    $check(!(bool) $ratingEqual->invoke($adapter, 'not-a-number', '0', 'total_sales'),
        'malformed total sales does not silently compare equal to zero');
    $check((bool) $ratingEqual->invoke($adapter, null, '0.0000', 'min_price'),
        'no effective price matches Woo decimal zero normalization');
    $check((bool) $ratingEqual->invoke($adapter, '12.34', '12.3400', 'max_price'),
        'priced lookup values retain decimal numeric equivalence');
    $check(!(bool) $ratingEqual->invoke($adapter, '12.34', '12.35', 'min_price'),
        'priced lookup verifier rejects a real nonzero mismatch');
    $check(!(bool) $ratingEqual->invoke($adapter, '0', 'not-a-number', 'max_price'),
        'malformed price lookup values cannot pass through numeric coercion');
    $check($fakeMeta[11]['_price'] === ['18'], 'stale child _price is recomputed from regular/sale inputs');
    $check($fakeMeta[10]['_price'] === ['18', '21'], 'variable parent _price is synchronized from distinct child prices');
    $check($fakeMeta[10]['_regular_price'] === [''] && $fakeMeta[10]['_sale_price'] === [''],
        'variable sync preserves explicit empty authored parent price rows');
    $check((WC_Data_Store::$variable?->calls ?? 0) === 1, 'variable root synchronization is deduplicated');
    $check($fakeMetaLookup[11]['min_price'] === '18', 'existing stale wc_product_meta_lookup row is refreshed');
    $check($fakeMetaLookup[11]['onsale'] === 1, 'onsale follows Woo sale-price/effective-price equality');
    $check($fakeMeta[11]['_stock'] === ['5'], 'target-local runtime stock meta is preserved');
    $check(count($fakeAttrLookup) === 2, 'attribute lookup rows are regenerated synchronously and exactly');
    $check(in_array('product_10', WC_Cache_Helper::$invalidatedGroups, true)
        && in_array('product_11', WC_Cache_Helper::$invalidatedGroups, true),
        'Woo cache-helper invalidates the affected product groups');
    $check(in_array('pa_grind-size', $fakeRegisteredTaxonomies, true)
        && in_array('pa_color', $fakeRegisteredTaxonomies, true),
        'newly-applied Woo attribute taxonomies are registered before product reads');
    $check(in_array('wc_attribute_taxonomies', $fakeDeletedTransients, true)
        && in_array('woocommerce-attributes', WC_Cache_Helper::$invalidatedGroups, true),
        'Woo attribute transient and object cache are refreshed before registration');

    // The old fake made wc_delete_product_transients() and
    // invalidate_attribute_count() no-ops, so a green lookup test could not
    // prove the callback-visible cache contract.  Assert the actual finite
    // fixed keys, concrete product/parent-specific families, product
    // transient-version refreshes, and concrete layered-nav attribute keys.
    $fixedProductTransients = [
        'wc_products_onsale',
        'wc_featured_products',
        'wc_outofstock_count',
        'wc_low_stock_count',
    ];
    foreach ($fixedProductTransients as $transient) {
        $check(in_array($transient, $fakeDeletedTransients, true),
            "Woo product transient invalidation deletes fixed key $transient");
    }
    foreach ([10, 11, 12] as $productId) {
        foreach ([
            'wc_product_children_',
            'wc_var_prices_',
            'wc_related_',
            'wc_child_has_weight_',
            'wc_child_has_dimensions_',
        ] as $prefix) {
            $key = $prefix . $productId;
            $check(in_array($key, $fakeDeletedTransients, true),
                "Woo product transient invalidation deletes concrete key $key");
        }
    }
    $check(count($fakeProductTransientCalls) > 0
        && count(array_filter(
            WC_Cache_Helper::$transientVersionCalls,
            static fn(array $call): bool => $call['group'] === 'product' && $call['refresh'] === true
        )) > 0,
        'Woo product transient invalidation refreshes product-transient-version through get/set');
    $check(in_array('pa_color', WC_Cache_Helper::$invalidatedAttributes, true)
        && in_array('wc_layered_nav_counts_pa_color', $fakeDeletedTransients, true),
        'Woo attribute invalidation deletes the concrete layered-nav count key');
    $cacheHookNames = array_values(array_unique(array_map(
        static fn(array $event): string => (string) ($event['hook'] ?? ''),
        $fakeCacheHooks
    )));
    sort($cacheHookNames, SORT_STRING);
    $check($cacheHookNames === ['clean_object_term_cache', 'clean_post_cache'],
        'WordPress post-cache cleanup exercises both declared callback boundaries');
    $cacheGroups = array_values(array_unique(array_map(
        static fn(array $event): string => (string) ($event['group'] ?? ''),
        $fakeWpCacheDeletes
    )));
    sort($cacheGroups, SORT_STRING);
    $check(array_diff(['posts', 'post_meta', 'terms', 'general', 'pa_color_relationships'], $cacheGroups) === [],
        'WordPress generic post-cache groups and dynamic taxonomy group are observed as a finite bounded set');
    $check(!in_array('post_ancestors', $cacheGroups, true)
        && !in_array('post_parent', $cacheGroups, true)
        && !in_array('term_relationships', $cacheGroups, true),
        'WordPress cleanup does not misclassify post_parent, post_ancestors, or the table name as cache groups');

    // Grouped products have no post_parent reverse relation.  A changed
    // simple child must therefore discover its grouped roots through the
    // targeted _children query and reconcile each root through Woo's public
    // product-grouped data store.  Keep a stale grouped price and distinct
    // target-local stock values so this proves both synthesis and runtime
    // isolation.
    $fakeProducts[40] = new FakeProduct(40, 'grouped', 0, [41, 12], [], true);
    $fakeProducts[41] = new FakeProduct(41, 'simple', 0, [], [], true);
    $fakeMeta[40] = $fakeMeta[10];
    $fakeMeta[40]['_price'] = ['777'];
    $fakeMeta[40]['_regular_price'] = ['74'];
    unset($fakeMeta[40]['_sale_price']);
    $fakeMeta[41] = $fakeMeta[11];
    $fakeMeta[41]['_price'] = ['999'];
    $fakeMeta[41]['_regular_price'] = ['19'];
    $fakeMeta[41]['_sale_price'] = ['17'];
    $fakeMeta[41]['_stock'] = ['9'];
    $fakeMetaLookup[40] = $fakeMetaLookup[10];
    $fakeMetaLookup[40]['product_id'] = 40;
    $fakeMetaLookup[40]['min_price'] = '777';
    $fakeMetaLookup[40]['max_price'] = '777';
    $fakeMetaLookup[41] = $fakeMetaLookup[11];
    $fakeMetaLookup[41]['product_id'] = 41;
    $fakeMetaLookup[41]['min_price'] = '999';
    $fakeMetaLookup[41]['max_price'] = '999';
    $fakeMetaLookup[41]['stock_quantity'] = 9;
    $fakeGroupedChildren = [40 => [41, 12]];
    $groupedEventStart = count($fakeCacheEvents);
    $groupedAttrCount = count($fakeAttrLookup);
    $adapter->regenerate_batch([41], []);
    $check($fakeMeta[41]['_price'] === ['17'], 'grouped child _price is recomputed before grouped synchronization');
    $check($fakeMeta[40]['_price'] === ['17', '21'], 'grouped root _price is synthesized from all current child prices');
    $check($fakeMeta[40]['_regular_price'] === ['74'] && !array_key_exists('_sale_price', $fakeMeta[40]),
        'grouped sync preserves one authored parent row and an absent sale-price key');
    $check((WC_Data_Store::$grouped?->calls ?? 0) === 1, 'grouped root uses the public product-grouped sync_price store');
    $check($fakeMetaLookup[40]['min_price'] === '17' && $fakeMetaLookup[40]['max_price'] === '21',
        'grouped root meta lookup reflects the synthesized price range');
    $check($fakeMetaLookup[41]['min_price'] === '17' && $fakeMetaLookup[41]['max_price'] === '17',
        'grouped child meta lookup is refreshed after effective-price repair');
    $check($fakeMeta[40]['_stock'] === ['5'] && $fakeMeta[41]['_stock'] === ['9'],
        'grouped synthesis preserves target-local runtime stock on root and child');
    $check($fakeMeta[40]['total_sales'] === ['0'] && $fakeMeta[41]['total_sales'] === ['0'],
        'grouped synthesis preserves target-local order counters on root and child');
    $check(count($fakeAttrLookup) === $groupedAttrCount,
        'grouped price reconciliation does not create unrelated attribute rows');
    $groupedEvents = array_slice($fakeCacheEvents, $groupedEventStart);
    $groupedParentRemove = array_search('remove:40', $groupedEvents, true);
    $groupedParentRead = null;
    foreach ($groupedEvents as $eventIndex => $event) {
        if (str_starts_with($event, 'read:40:')) {
            $groupedParentRead = $eventIndex;
            break;
        }
    }
    $check($groupedParentRemove !== false && $groupedParentRead !== null
        && $groupedParentRemove < $groupedParentRead,
        'grouped parent discovery evicts the root before its first Woo read');

    $adapter->regenerate_batch([41], []);
    $check($fakeMeta[40]['_price'] === ['17', '21'] && (WC_Data_Store::$grouped?->calls ?? 0) === 2,
        'grouped reconciliation is retry-safe and repeats the public synthesis boundary');
    $check(count($fakeAttrLookup) === $groupedAttrCount,
        'grouped retry leaves attribute lookup cardinality unchanged');

    // Exercise the same finally/retry guarantee through the grouped public
    // store after it has already cleared its authored parent rows.
    $groupedFailureCaught = false;
    $fakeSyncFailures['grouped'][40] = 1;
    try {
        $adapter->regenerate_batch([41], []);
    } catch (\Throwable $t) {
        $groupedFailureCaught = true;
    }
    $check($groupedFailureCaught, 'grouped public sync failure is surfaced to the caller');
    $check($fakeMeta[40]['_regular_price'] === ['74'] && !array_key_exists('_sale_price', $fakeMeta[40]),
        'failed grouped sync restores one authored row and absent sale-price semantics');
    $adapter->regenerate_batch([41], []);
    $check($fakeMeta[40]['_regular_price'] === ['74'] && !array_key_exists('_sale_price', $fakeMeta[40]),
        'grouped sync retry preserves the restored authored parent rows');

    // A grouped product may contain a variable product. Preload the grouped
    // root and variable child while the child still has its stale raw price;
    // variable sync_price() then writes a new root price. The grouped store
    // fake reads child prices through wc_get_product(), so this fails if the
    // variable child cache is not evicted and reloaded after variable sync.
    $fakeProducts[50] = new FakeProduct(50, 'grouped', 0, [51], [], true);
    $fakeProducts[51] = new FakeProduct(51, 'variable', 0, [52], [], true);
    $fakeProducts[52] = new FakeProduct(52, 'variation', 51, [], [], true);
    $fakeMeta[50] = $fakeMeta[40];
    $fakeMeta[50]['_price'] = ['777'];
    $fakeMeta[50]['_stock'] = ['6'];
    $fakeMeta[50]['total_sales'] = ['4'];
    $fakeMeta[51] = $fakeMeta[10];
    $fakeMeta[51]['_price'] = ['777'];
    $fakeMeta[51]['_stock'] = ['7'];
    $fakeMeta[51]['total_sales'] = ['5'];
    $fakeMeta[52] = $fakeMeta[11];
    $fakeMeta[52]['_price'] = ['999'];
    $fakeMeta[52]['_regular_price'] = ['23'];
    $fakeMeta[52]['_sale_price'] = [''];
    $fakeMeta[52]['_stock'] = ['11'];
    $fakeMeta[52]['total_sales'] = ['2'];
    foreach ([50 => '777', 51 => '777', 52 => '999'] as $id => $price) {
        $fakeMetaLookup[$id] = $fakeMetaLookup[10];
        $fakeMetaLookup[$id]['product_id'] = $id;
        $fakeMetaLookup[$id]['min_price'] = $price;
        $fakeMetaLookup[$id]['max_price'] = $price;
    }
    $fakeMetaLookup[50]['stock_quantity'] = 6;
    $fakeMetaLookup[51]['stock_quantity'] = 7;
    $fakeMetaLookup[52]['stock_quantity'] = 11;
    $fakeGroupedChildren[50] = [51];
    wc_get_product(50);
    wc_get_product(51);
    wc_get_product(52);
    $adapter->regenerate_batch([52], []);
    $check($fakeMeta[52]['_price'] === ['23'], 'grouped variable-chain variation recomputes its effective price');
    $check($fakeMeta[51]['_price'] === ['23'], 'grouped variable child synchronizes from its variation');
    $check($fakeMeta[50]['_price'] === ['23', '23'],
        'grouped root reads the reloaded variable child price after variable synchronization');
    $check($fakeMetaLookup[50]['min_price'] === '23' && $fakeMetaLookup[50]['max_price'] === '23'
        && $fakeMetaLookup[51]['min_price'] === '23' && $fakeMetaLookup[52]['min_price'] === '23',
        'grouped variable-chain lookup rows are refreshed with exact prices');
    $check($fakeMeta[50]['_stock'] === ['6'] && $fakeMeta[51]['_stock'] === ['7'] && $fakeMeta[52]['_stock'] === ['11']
        && $fakeMeta[50]['total_sales'] === ['4'] && $fakeMeta[51]['total_sales'] === ['5']
        && $fakeMeta[52]['total_sales'] === ['2'],
        'grouped variable-chain synthesis preserves stock and order counters');
    $check((WC_Data_Store::$variable?->calls ?? 0) === 2
        && (WC_Data_Store::$grouped?->calls ?? 0) === 5,
        'grouped variable-chain sync ordering uses the public variable store before grouped retries');

    // Deleting a grouped simple child leaves the serialized grouped relation
    // in this fake until the next ordinary source edit. The deletion receipt
    // must still discover the root, skip the missing child, and converge the
    // root from its remaining child. Replaying the same receipt is harmless.
    $groupedDeleteContext = [[
        'uuid' => 'grouped-child-delete',
        'id' => 41,
        'post_type' => 'product',
        'child_ids' => [],
    ]];
    $adapter->regenerate_batch([], $groupedDeleteContext);
    $check(!isset($fakeMetaLookup[41]), 'grouped deletion removes the deleted child meta lookup row');
    $check($fakeMeta[40]['_price'] === ['21', '21'] && $fakeMetaLookup[40]['min_price'] === '21',
        'grouped deletion resynthesizes the root from remaining children');
    $check($fakeMeta[40]['_stock'] === ['5'] && $fakeMeta[40]['total_sales'] === ['0'],
        'grouped deletion preserves root-local stock and order counters');
    $check((WC_Data_Store::$grouped?->calls ?? 0) === 6,
        'grouped deletion reaches the public grouped synchronization boundary');
    $adapter->regenerate_batch([], $groupedDeleteContext);
    $check(!isset($fakeMetaLookup[41]) && $fakeMeta[40]['_price'] === ['21', '21']
        && (WC_Data_Store::$grouped?->calls ?? 0) === 7,
        'grouped deletion receipt retry is idempotent and leaves exact root state');

    $heartbeatCalls = 0;
    $adapter->regenerate_batch([13], [], static function () use (&$heartbeatCalls): void {
        $heartbeatCalls++;
    });
    $check($heartbeatCalls > 0, 'batch adapter invokes the promotion heartbeat callback');
    $check($fakeMeta[13]['_price'] === ['0'], 'authored zero sale price recomputes stale _price');
    $check($fakeMetaLookup[13]['onsale'] === 0, 'zero sale price is not marked onsale');

    $adapter->regenerate_batch([14], []);
    $check(($fakeMetaLookup[14]['min_price'] ?? null) === '0.0000'
        && ($fakeMetaLookup[14]['max_price'] ?? null) === '0.0000',
        'no-price product lookup stores Woo decimal zero defaults');

    $adapter->regenerate_batch([15], []);
    $check(count(array_filter($fakeAttrLookup, static fn(array $row): bool =>
        (int) $row['product_id'] === 15 && $row['taxonomy'] === 'pa_grind-size')) === 1,
        'simple global attribute lookup rows are required after bootstrap registration');
    $check(in_array('pa_grind-size', WC_Cache_Helper::$invalidatedAttributes, true)
        && in_array('wc_layered_nav_counts_pa_grind-size', $fakeDeletedTransients, true),
        'simple product attribute invalidation reconciles its concrete layered-nav key');
    $cacheGroupsAfterSimple = array_values(array_unique(array_map(
        static fn(array $event): string => (string) ($event['group'] ?? ''),
        $fakeWpCacheDeletes
    )));
    $check(in_array('pa_grind-size_relationships', $cacheGroupsAfterSimple, true),
        'WordPress cleanup observes the concrete simple-product taxonomy relationship group');

    // A same-parent variation write must evict the current variable root
    // before wc_get_product(parent).  The cached parent below is deliberately
    // stale: it says the root is draft/hidden and has no visible children,
    // while the raw-SQL-side state is publish/visible with child 31 visible.
    // Without parent invalidation sync_price() would erase the root price
    // range, even though the variation's authored price and stock are live.
    wc_get_product(30);
    $fakeProducts[30]->set_status('publish');
    $fakeProducts[30]->set_catalog_visibility('visible');
    $fakeProducts[30]->set_visible_children([31]);
    $sameParentEventStart = count($fakeCacheEvents);
    $adapter->regenerate_batch([31], []);
    $check($fakeMeta[31]['_price'] === ['18'], 'same-parent variation recomputes its authored effective price');
    $check($fakeMeta[31]['_stock'] === ['7'], 'same-parent variation preserves target-local runtime stock');
    $check($fakeMeta[30]['_price'] === ['18'], 'same-parent write refreshes the variable root price range');
    $check($fakeMetaLookup[30]['min_price'] === '18', 'same-parent write refreshes the root meta lookup row');
    $check(count(array_filter($fakeAttrLookup, static fn(array $row): bool => (int) $row['product_or_parent_id'] === 30)) === 1,
        'same-parent write rebuilds the exact current attribute root');
    $sameParentEvents = array_slice($fakeCacheEvents, $sameParentEventStart);
    $sameParentRemove = array_search('remove:30', $sameParentEvents, true);
    $sameParentRead = null;
    foreach ($sameParentEvents as $eventIndex => $event) {
        if (str_starts_with($event, 'read:30:')) {
            $sameParentRead = $eventIndex;
            break;
        }
    }
    $check($sameParentRemove !== false && $sameParentRead !== null && $sameParentRemove < $sameParentRead,
        'same-parent write evicts the current parent cache before the first parent read');

    // Reparent a live variation from variable root 20 to root 22.  The old
    // root must be refreshed as well as the new root, while the variation's
    // lookup row and target-local stock remain live.
    // Preload stale parent/child product objects before the raw-SQL-side
    // mutation.  The adapter must evict all three ids before it asks Woo for
    // any of them, or it will rebuild the old/new roots from this stale graph.
    wc_get_product(20);
    wc_get_product(21);
    wc_get_product(22);
    $reparentEventStart = count($fakeCacheEvents);
    $fakeProducts[21]->set_parent(22);
    $fakeProducts[20]->set_children([]);
    $fakeProducts[20]->set_visible_children([]);
    $fakeProducts[22]->set_children([21]);
    $fakeProducts[22]->set_visible_children([21]);
    $fakeMeta[21]['_price'] = ['999'];
    $adapter->regenerate_batch([21], [[
        'kind' => 'reparent',
        'uuid' => 'variation-reparent',
        'id' => 21,
        'post_type' => 'product_variation',
        'old_parent_id' => 20,
        'new_parent_id' => 22,
        'parent_id' => 20,
        'child_ids' => [],
    ]]);
    $check(($fakeMetaLookup[20]['min_price'] ?? null) === '0.0000',
        'reparent refresh writes Woo zero defaults for the old no-price root');
    $check($fakeMetaLookup[22]['min_price'] === '18', 'reparent refresh writes the new variable root price lookup');
    $check($fakeMetaLookup[21]['min_price'] === '18', 'reparent keeps the live variation lookup row');
    $check($fakeMeta[21]['_stock'] === ['5'], 'reparent preserves target-local variation stock');
    $check($fakeMeta[20]['_regular_price'] === ['32', '31'] && $fakeMeta[20]['_sale_price'] === ['29'],
        'variable sync preserves multiple authored parent price rows and their order');

    // The public variable store can fail after clearing the parent authored
    // rows. The adapter must restore them before surfacing the error, and a
    // replay must still be able to complete with the exact same authored
    // row shape.
    $fakeMeta[20]['_price'] = ['123'];
    $priceBeforeVariableFailure = $fakeMeta[20]['_price'];
    $variableFailureCaught = false;
    $fakeSyncFailures['variable'][20] = 1;
    try {
        $adapter->regenerate_batch([], [[
            'kind' => 'reparent',
            'uuid' => 'variable-sync-failure',
            'id' => 20,
            'post_type' => 'product',
            'root_ids' => [20],
        ]]);
    } catch (\Throwable $t) {
        $variableFailureCaught = true;
    }
    $check($variableFailureCaught, 'variable public sync failure is surfaced to the caller');
    $check($fakeMeta[20]['_regular_price'] === ['32', '31'] && $fakeMeta[20]['_sale_price'] === ['29'],
        'failed variable sync restores authored parent rows before retry');
    $check($fakeMeta[20]['_price'] === $priceBeforeVariableFailure,
        'failed variable sync rolls back its derived price mutation before retry');
    $adapter->regenerate_batch([], [[
        'kind' => 'reparent',
        'uuid' => 'variable-sync-failure',
        'id' => 20,
        'post_type' => 'product',
        'root_ids' => [20],
    ]]);
    $check($fakeMeta[20]['_regular_price'] === ['32', '31'] && $fakeMeta[20]['_sale_price'] === ['29'],
        'variable sync retry preserves the restored authored parent rows');

    // A restore can fail after delete_post_meta() has already removed the
    // authored rows. The transaction must roll back both that partial restore
    // and the derived sync, leaving an exact retry starting point.
    $priceBeforeRestoreFailure = $fakeMeta[20]['_price'];
    $fakeMetaRestoreFailures[20]['_regular_price'] = 1;
    $restoreFailureCacheStart = count(\Automattic\WooCommerce\Internal\Caches\ProductCache::$removed);
    $restoreFailureCaught = false;
    try {
        $adapter->regenerate_batch([], [[
            'kind' => 'reparent',
            'uuid' => 'variable-restore-failure',
            'id' => 20,
            'post_type' => 'product',
            'root_ids' => [20],
        ]]);
    } catch (\Throwable $t) {
        $restoreFailureCaught = true;
    }
    $check($restoreFailureCaught, 'authored metadata restore failure is surfaced to the caller');
    $check($fakeMeta[20]['_regular_price'] === ['32', '31'] && $fakeMeta[20]['_sale_price'] === ['29']
        && $fakeMeta[20]['_price'] === $priceBeforeRestoreFailure,
        'restore failure rolls back partial deletion and derived price changes atomically');
    $check(in_array(20, array_slice(\Automattic\WooCommerce\Internal\Caches\ProductCache::$removed, $restoreFailureCacheStart), true),
        'restore failure invalidates Woo product caches after rollback');
    $adapter->regenerate_batch([], [[
        'kind' => 'reparent',
        'uuid' => 'variable-restore-failure',
        'id' => 20,
        'post_type' => 'product',
        'root_ids' => [20],
    ]]);
    $check($fakeMeta[20]['_regular_price'] === ['32', '31'] && $fakeMeta[20]['_sale_price'] === ['29'],
        'exact authored rows survive the retry after restore failure');
    $check(in_array(20, \Automattic\WooCommerce\Internal\Caches\ProductCache::$removed, true)
        && in_array(22, \Automattic\WooCommerce\Internal\Caches\ProductCache::$removed, true),
        'reparent invalidates Woo product-instance caches for both roots');
    $reparentEvents = array_slice($fakeCacheEvents, $reparentEventStart);
    foreach ([20, 21, 22] as $cacheId) {
        $firstRemove = array_search("remove:$cacheId", $reparentEvents, true);
        $firstRead = null;
        foreach ($reparentEvents as $eventIndex => $event) {
            if (str_starts_with($event, "read:$cacheId:")) {
                $firstRead = $eventIndex;
                break;
            }
        }
        $check($firstRemove !== false && $firstRead !== null && $firstRemove < $firstRead,
            "reparent evicts product-cache id $cacheId before the first read");
    }
    $check(count(array_filter($fakeAttrLookup, static fn(array $row): bool => (int) $row['product_or_parent_id'] === 20)) === 0,
        'reparent removes old-root attribute lookup rows');
    $check(count(array_filter($fakeAttrLookup, static fn(array $row): bool => (int) $row['product_or_parent_id'] === 22)) === 1,
        'reparent writes new-root attribute lookup rows');

    // Exercise an accumulated A->B->C receipt.  Preload stale A/B/C and the
    // live variation, then move the variation again before the prior failed
    // receipt is replayed.  The merged root_ids must refresh A, B, and C;
    // dropping A here would leave its lookup/attributes stale indefinitely.
    wc_get_product(20);
    wc_get_product(21);
    wc_get_product(22);
    wc_get_product(24);
    $chainedReparentEventStart = count($fakeCacheEvents);
    $fakeProducts[21]->set_parent(24);
    $fakeProducts[22]->set_children([]);
    $fakeProducts[22]->set_visible_children([]);
    $fakeProducts[24]->set_children([21]);
    $fakeProducts[24]->set_visible_children([21]);
    $fakeMeta[21]['_price'] = ['999'];
    $adapter->regenerate_batch([21], [[
        'kind' => 'reparent',
        'uuid' => 'variation-reparent',
        'id' => 21,
        'post_type' => 'product_variation',
        'old_parent_id' => 22,
        'new_parent_id' => 24,
        'parent_id' => 22,
        'root_ids' => [20, 22, 24],
        'child_ids' => [],
    ]]);
    $check(($fakeMetaLookup[20]['min_price'] ?? null) === '0.0000',
        'chained reparent preserves and refreshes the original no-price root A');
    $check(($fakeMetaLookup[22]['min_price'] ?? null) === '0.0000',
        'chained reparent refreshes the intermediate no-price root B');
    $check($fakeMetaLookup[24]['min_price'] === '18', 'chained reparent refreshes the final root C');
    $check($fakeMetaLookup[21]['min_price'] === '18', 'chained reparent keeps the live variation lookup exact');
    $check($fakeMeta[21]['_stock'] === ['5'], 'chained reparent preserves target-local variation stock');
    $chainedReparentEvents = array_slice($fakeCacheEvents, $chainedReparentEventStart);
    foreach ([20, 21, 22, 24] as $cacheId) {
        $firstRemove = array_search("remove:$cacheId", $chainedReparentEvents, true);
        $firstRead = null;
        foreach ($chainedReparentEvents as $eventIndex => $event) {
            if (str_starts_with($event, "read:$cacheId:")) {
                $firstRead = $eventIndex;
                break;
            }
        }
        $check($firstRemove !== false && $firstRead !== null && $firstRemove < $firstRead,
            "chained reparent evicts accumulated root/cache id $cacheId before the first read");
    }
    $check(count(array_filter($fakeAttrLookup, static fn(array $row): bool => (int) $row['product_or_parent_id'] === 20)) === 0,
        'chained reparent leaves no stale attribute rows on original root A');
    $check(count(array_filter($fakeAttrLookup, static fn(array $row): bool => (int) $row['product_or_parent_id'] === 22)) === 0,
        'chained reparent leaves no stale attribute rows on intermediate root B');
    $check(count(array_filter($fakeAttrLookup, static fn(array $row): bool => (int) $row['product_or_parent_id'] === 24)) === 1,
        'chained reparent writes exact attribute rows on final root C');

    // A failed A->B receipt can meet a deletion before retry. Leave the
    // parent's stale child list and cached deleted variation in place; the
    // adapter must combine accumulated roots with ACTION_DELETE, invalidate
    // the deleted ProductCache entry, and never read it as live.
    unset($fakeProducts[21], $fakeMeta[21]);
    $deletedReparentEventStart = count($fakeCacheEvents);
    $adapter->regenerate_batch([], [[
        'kind' => 'reparent',
        'uuid' => 'variation-delete-before-retry',
        'id' => 21,
        'post_type' => 'product_variation',
        'old_parent_id' => 20,
        'new_parent_id' => 24,
        'parent_id' => 20,
        'root_ids' => [20, 22, 24],
        'child_ids' => [],
    ], [
        'kind' => 'delete',
        'uuid' => 'variation-delete-before-retry',
        'id' => 21,
        'post_type' => 'product_variation',
        'parent_id' => 24,
        'child_ids' => [],
    ]]);
    $check(!isset($fakeMetaLookup[21]), 'deleted reparent retry removes the variation lookup row');
    $check(($fakeMetaLookup[20]['min_price'] ?? null) === '0.0000'
        && ($fakeMetaLookup[22]['min_price'] ?? null) === '0.0000'
        && ($fakeMetaLookup[24]['min_price'] ?? null) === '0.0000',
        'deleted reparent retry refreshes every accumulated no-price root');
    $check(count(array_filter($fakeAttrLookup, static fn(array $row): bool => (int) $row['product_or_parent_id'] === 24)) === 0,
        'deleted reparent retry leaves no attribute rows for the deleted child/root');
    $check($fakeMeta[24]['_stock'] === ['5'], 'deleted reparent retry preserves root-local runtime stock');
    $deletedReparentEvents = array_slice($fakeCacheEvents, $deletedReparentEventStart);
    foreach ([20, 21, 22, 24] as $cacheId) {
        $firstRemove = array_search("remove:$cacheId", $deletedReparentEvents, true);
        $firstRead = null;
        foreach ($deletedReparentEvents as $eventIndex => $event) {
            if (str_starts_with($event, "read:$cacheId:")) {
                $firstRead = $eventIndex;
                break;
            }
        }
        $check($firstRemove !== false && $firstRead !== null && $firstRemove < $firstRead,
            "deleted reparent retry evicts cache id $cacheId before the first read");
    }

    $adapter->regenerate_batch([10, 12], [[
        'uuid' => 'variation-delete',
        'id' => 11,
        'post_type' => 'product_variation',
        'parent_id' => 10,
        'child_ids' => [],
    ]]);
    $check(!isset($fakeMetaLookup[11]), 'deletion context removes the deleted variation meta lookup row');
    $check($fakeMeta[10]['_price'] === ['21'], 'deletion context parent id triggers parent price resync');
    $check((WC_Data_Store::$variable?->calls ?? 0) === 16, 'parent resync remains deduplicated across same-parent, chained roots, deletion, and cleanup');

    // A deleted simple product cannot be loaded after ACTION_DELETE.  The
    // adapter must use the finite registered taxonomy set as its conservative
    // fallback so a former attribute's layered-nav count is not left stale.
    $adapter->regenerate_batch([], [[
        'uuid' => 'simple-attribute-delete',
        'id' => 15,
        'post_type' => 'product',
        'parent_id' => 0,
        'child_ids' => [],
    ]]);
    $check(in_array('wc_layered_nav_counts_pa_grind-size', $fakeDeletedTransients, true),
        'deleted simple product invalidates the concrete registered layered-nav fallback key');

    if ($failures > 0) {
        echo "FAIL: $failures check(s) failed\n";
        exit(1);
    }
    echo "ALL PASSED\n";
}
