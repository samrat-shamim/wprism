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
            $root = is_object($product) ? $product : \wc_get_product($rootId);
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
                $termsBySlug = array_flip(\get_terms([
                    'taxonomy' => 'pa_color',
                    'hide_empty' => false,
                    'fields' => 'id=>slug',
                ]));
                foreach ((array) $root->get_children() as $childId) {
                    $child = \wc_get_product((int) $childId);
                    foreach ($attrs as $taxonomy => $attribute) {
                        if (!(bool) $attribute->get_variation() || !$child) {
                            continue;
                        }
                        $slug = (string) (($child->get_attributes()[$taxonomy] ?? ''));
                        $term = (int) ($termsBySlug[$slug] ?? 0);
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
        /**
         * wc_product_meta_lookup's real column types, verbatim from
         * WooCommerce 11.0.0 (class-wc-install.php:1977): every column but
         * product_id is NULLable, prices are decimal(19,4) default NULL,
         * stock_quantity is double default NULL, total_sales is bigint(20)
         * default 0, average_rating is decimal(3,2) default 0.00, the flags
         * are tinyint(1), and the rest are varchar(100).
         *
         * This map is what MySQL would do, in the two places the real database
         * would do it: coercing a supplied value on write, and evaluating a
         * `<=>` predicate on read. So '18' and '18.0000' are one value here,
         * and so are '' and 0 in a numeric column — while a genuine SQL NULL
         * stays distinct from both.
         */
        public const LOOKUP_COLUMN_TYPES = [
            'product_id' => 'int',
            'sku' => 'string',
            'virtual' => 'int',
            'downloadable' => 'int',
            'min_price' => 'decimal',
            'max_price' => 'decimal',
            'onsale' => 'int',
            'stock_quantity' => 'decimal',
            'stock_status' => 'string',
            'rating_count' => 'int',
            'average_rating' => 'decimal',
            'total_sales' => 'int',
            'tax_status' => 'string',
            'tax_class' => 'string',
            'global_unique_id' => 'string',
        ];

        public static function coerce_lookup_column(string $column, mixed $value): mixed {
            if ($value === null) {
                return null;
            }
            return match (self::LOOKUP_COLUMN_TYPES[$column] ?? 'string') {
                'int' => (int) $value,
                'decimal' => (float) $value,
                default => (string) $value,
            };
        }

        public string $prefix = 'wp_';
        public string $postmeta = 'wp_postmeta';
        public string $posts = 'wp_posts';
        public string $last_error = '';
        public ?string $failReadContaining = null;
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
            if ($this->failReadContaining !== null && str_contains($query, $this->failReadContaining)) {
                $this->last_error = 'simulated read failure';
                return null;
            }
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
            if ($this->failReadContaining !== null && str_contains($query, $this->failReadContaining)) {
                $this->last_error = 'simulated read failure';
                return [];
            }
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
            if ($this->failReadContaining !== null && str_contains($query, $this->failReadContaining)) {
                $this->last_error = 'simulated read failure';
                return [];
            }
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
            global $fakeMetaLookup, $fakeAttrLookup, $fakeProducts;
            if ($this->failReadContaining !== null && str_contains($query, $this->failReadContaining)) {
                $this->last_error = 'simulated read failure';
                return null;
            }
            if (trim($query) === 'SELECT @@in_transaction') {
                return $this->transactionActive ? '1' : '0';
            }
            if (preg_match('/SELECT post_type FROM wp_posts WHERE ID = (\d+) LIMIT 1/', $query, $m)) {
                $product = $fakeProducts[(int) $m[1]] ?? null;
                return $product && $product->get_type() === 'variation'
                    ? 'product_variation'
                    : ($product ? 'product' : null);
            }
            if (preg_match("/SELECT ID FROM wp_posts WHERE post_parent = (\\d+) AND post_type = 'product_variation'/", $query, $m)) {
                $parentId = (int) $m[1];
                foreach ($fakeProducts as $id => $product) {
                    if ($product->get_type() === 'variation'
                        && (int) $product->get_parent_id('edit') === $parentId) {
                        return (int) $id;
                    }
                }
                return null;
            }
            // DUO-3342 value verification. The adapter asks the DATABASE
            // whether the stored row equals WooCommerce's own published
            // derivation — one NULL-safe predicate per column — so that the
            // column's type does the normalizing instead of a Duo-authored
            // tolerance table. Model that with the explicit column-type map
            // below: the same coercion $wpdb->replace() applied on the way in.
            // Must be tested before the bare cardinality branch, which this
            // query's prefix would otherwise match.
            if (preg_match('/COUNT\\(\\*\\) FROM `wp_wc_product_meta_lookup` WHERE product_id = (\\d+) AND (.+)$/s', $query, $m)) {
                $row = $fakeMetaLookup[(int) $m[1]] ?? null;
                if (!is_array($row)) {
                    return 0;
                }
                $found = preg_match_all(
                    "/`([a-z0-9_]+)` (?:<=> '((?:[^'\\\\\\\\]|\\\\\\\\.)*)'|IS (NULL))/",
                    $m[2],
                    $predicates,
                    PREG_SET_ORDER
                );
                if ($found !== substr_count($m[2], ' AND ') + 1) {
                    // Never let an unparsed predicate read as "everything
                    // matched" — that would make this suite pass vacuously.
                    throw new \RuntimeException('fake wpdb could not parse lookup verification predicate: ' . $m[2]);
                }
                foreach ($predicates as $predicate) {
                    $column = (string) $predicate[1];
                    $stored = array_key_exists($column, $row) ? $row[$column] : null;
                    if (($predicate[3] ?? '') === 'NULL') {
                        if ($stored !== null) {
                            return 0;
                        }
                        continue;
                    }
                    if ($stored === null) {
                        return 0;
                    }
                    if (self::coerce_lookup_column($column, stripslashes($predicate[2]))
                        !== self::coerce_lookup_column($column, $stored)) {
                        return 0;
                    }
                }
                return 1;
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

    final class FakeSaleDate {
        public function __construct(private int $timestamp) {}
        public function getTimestamp(): int { return $this->timestamp; }
    }

    class FakeProduct {
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
        public function get_date_on_sale_from(string $context = 'view'): ?FakeSaleDate {
            return fake_sale_date($this->id, '_sale_price_dates_from');
        }
        public function get_date_on_sale_to(string $context = 'view'): ?FakeSaleDate {
            return fake_sale_date($this->id, '_sale_price_dates_to');
        }
        public function get_status(): string { return $this->status; }
        public function get_catalog_visibility(): string { return $this->catalogVisibility; }
        public function set_parent(int $parent): void { $this->parent = $parent; }
        public function set_children(array $children): void { $this->children = $children; }
        public function set_attributes(array $attributes): void { $this->attributes = $attributes; }
        public function set_visible_children(array $children): void { $this->visibleChildren = $children; }
        public function set_status(string $status): void { $this->status = $status; }
        public function set_catalog_visibility(string $visibility): void { $this->catalogVisibility = $visibility; }
    }

    final class WC_Product_Variable extends FakeProduct {
        public function __construct(int $id) {
            global $fakeProducts;
            $source = $fakeProducts[$id];
            parent::__construct(
                $id,
                'variable',
                (int) $source->get_parent_id('edit'),
                (array) $source->get_children(),
                (array) $source->get_attributes(),
                (bool) $source->is_in_stock(),
                (array) $source->get_visible_children(),
                (string) $source->get_status(),
                (string) $source->get_catalog_visibility()
            );
        }
    }

    final class WC_Product_Grouped extends FakeProduct {
        public function __construct(int $id) {
            global $fakeProducts;
            $source = $fakeProducts[$id];
            parent::__construct(
                $id,
                'grouped',
                (int) $source->get_parent_id('edit'),
                (array) $source->get_children(),
                (array) $source->get_attributes(),
                (bool) $source->is_in_stock(),
                (array) $source->get_visible_children(),
                (string) $source->get_status(),
                (string) $source->get_catalog_visibility()
            );
        }
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
        /**
         * WC_Data_Store proxies every call through __call(), so is_callable()
         * on a real store is always true; has_callable() (class-wc-data-store
         * .php:235) is the only honest version guard. Model it so the adapter
         * exercises that path here too.
         */
        public function has_callable(string $method): bool {
            return method_exists($this, $method);
        }
        public function delete_from_lookup_table(int $id, string $table): void {
            global $fakeMetaLookup, $fakeMeta, $fakeProducts;
            unset($fakeMetaLookup[$id]);
            unset($fakeMeta[$id], $fakeProducts[$id]);
        }
        /**
         * Model WC_Data_Store_WP::update_lookup_table() faithfully, because
         * the adapter's verification now depends on two of its properties.
         *
         * First, Woo compares its fresh derivation against the
         * `lookup_table`/`object_<id>` object-cache entry — never against the
         * stored row — and skips BOTH the write and the cache set when they
         * match, so a warm cache turns a refresh into a silent no-op. Second,
         * the derivation Woo publishes to that cache is the only channel
         * through which the protected get_data_for_lookup_table() result is
         * observable at all.
         *
         * Woo caches the PHP-typed derivation while the table holds what MySQL
         * coerced on write, so those two are modelled as genuinely different
         * shapes here — that difference is the whole reason the adapter
         * compares in SQL rather than in PHP.
         *
         * $fakeLookupWriteFaults models Woo's write and its cache set
         * diverging, which real Woo permits: update_lookup_table() never checks
         * $wpdb->replace()'s return value and sets the cache unconditionally
         * (class-wc-data-store-wp.php:617-620), so a failed REPLACE leaves Woo
         * advertising a row that never landed. $fakeLookupCacheSuppressed
         * models a cache that drops the entry, which must fail closed rather
         * than silently skip the check.
         */
        public function refresh_product_lookup_table(int $id): void {
            global $fakeMeta, $fakeMetaLookup, $fakeLookupWriteFaults, $fakeLookupCacheSuppressed;
            $prices = array_values($fakeMeta[$id]['_price'] ?? []);
            $first = $prices[0] ?? null;
            $last = $prices[count($prices) - 1] ?? null;
            $price = (string) ($first ?? '');
            $sale = (string) ($fakeMeta[$id]['_sale_price'][0] ?? '');
            $derived = [
                'product_id' => $id,
                'sku' => (string) (($fakeMeta[$id]['_sku'][0] ?? '')),
                'virtual' => 0,
                'downloadable' => 0,
                // reset()/end() over an empty price array is false in Woo, not
                // null: decimal(19,4) is `default NULL`, but a supplied false
                // binds as '' and lands as 0.0000, never as SQL NULL.
                'min_price' => $first === null ? false : $first,
                'max_price' => $last === null ? false : $last,
                'onsale' => ((bool) $sale && $price === $sale) ? 1 : 0,
                // The one column Woo genuinely derives as PHP null: unmanaged
                // stock is null rather than zero (class-wc-product-data-store
                // -cpt.php:2496), and `double NULL` keeps it as SQL NULL.
                'stock_quantity' => (($fakeMeta[$id]['_manage_stock'][0] ?? '') === 'yes')
                    ? (float) ($fakeMeta[$id]['_stock'][0] ?? 0)
                    : null,
                'stock_status' => 'instock',
                'rating_count' => 0,
                'average_rating' => (string) ($fakeMeta[$id]['_wc_average_rating'][0] ?? ''),
                // get_post_meta($id, 'total_sales', true) returns '' for absent
                // meta, never null (:2513), and the column is always in the
                // derived set, so bigint(20) stores 0. Woo has no path that
                // writes SQL NULL here.
                'total_sales' => (string) ($fakeMeta[$id]['total_sales'][0] ?? ''),
                'tax_status' => 'taxable',
                'tax_class' => '',
                'global_unique_id' => '',
            ];
            if ($derived === wp_cache_get('lookup_table', 'object_' . $id)) {
                return;
            }
            // What the table holds once $wpdb->replace() has bound each derived
            // value as %s: '' and false reach the numeric columns as their zero,
            // while a PHP null stays SQL NULL in the columns Woo passes it for.
            // Woo caches the derivation it computed, NOT this coerced form, so
            // the two deliberately differ — that gap is why the adapter compares
            // in SQL instead of PHP.
            // $wpdb->replace() is a DELETE plus INSERT, so a column outside the
            // derived set does not keep whatever it held — it comes back at its
            // schema default. Model that for any residual column the row still
            // carries (the nullable default the lookup table uses throughout).
            $stored = $derived;
            foreach ($fakeMetaLookup[$id] ?? [] as $column => $_value) {
                if (!array_key_exists($column, $derived)) {
                    $stored[$column] = null;
                }
            }
            $stored['min_price'] = $first === null ? '0.0000' : $first;
            $stored['max_price'] = $last === null ? '0.0000' : $last;
            $stored['average_rating'] = $derived['average_rating'] === '' ? '0.00' : $derived['average_rating'];
            $stored['total_sales'] = $derived['total_sales'] === '' ? '0' : $derived['total_sales'];
            $fakeMetaLookup[$id] = array_merge(
                $stored,
                (array) (($fakeLookupWriteFaults ?? [])[$id] ?? [])
            );
            if (!in_array($id, (array) ($fakeLookupCacheSuppressed ?? []), true)) {
                wp_cache_set('lookup_table', $derived, 'object_' . $id);
            }
        }
    }

    final class FakeVariableStore {
        public function has_callable(string $method): bool {
            return method_exists($this, $method);
        }
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
        public function has_callable(string $method): bool {
            return method_exists($this, $method);
        }
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
    $fakeWpCache = [];
    $fakeLookupWriteFaults = [];
    $fakeLookupCacheSuppressed = [];
    $fakeWpCacheDeletes = [];
    $fakeCacheHooks = [];
    $fakeGroupedChildren = [];
    $fakeSyncFailures = ['variable' => [], 'grouped' => []];
    $fakeMetaRestoreFailures = [];
    $fakeSaleSchedules = [
        'wc_product_start_scheduled_sale' => [],
        'wc_product_end_scheduled_sale' => [],
    ];
    $fakeSaleScheduleCalls = [];
    $fakeSaleUnscheduleCalls = [];
    $fakeSaleVerificationFailure = false;
    $fakeFilters = [];
    $fakeTermQueries = [];

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
    function fake_sale_date(int $id, string $key): ?FakeSaleDate {
        global $fakeMeta;
        $value = (string) ($fakeMeta[$id][$key][0] ?? '');
        return $value === '' || (int) $value <= 0 ? null : new FakeSaleDate((int) $value);
    }
    function wc_maybe_schedule_product_sale_events($id, $product = null): void {
        global $fakeSaleSchedules, $fakeSaleScheduleCalls;
        $id = (int) $id;
        $fakeSaleScheduleCalls[] = $id;
        foreach (array_keys($fakeSaleSchedules) as $hook) {
            unset($fakeSaleSchedules[$hook][$id]);
        }
        if (!is_object($product)) {
            $product = wc_get_product($id);
        }
        if (!is_object($product)) {
            return;
        }
        $dates = [
            'wc_product_start_scheduled_sale' => $product->get_date_on_sale_from('edit'),
            'wc_product_end_scheduled_sale' => $product->get_date_on_sale_to('edit'),
        ];
        foreach ($dates as $hook => $date) {
            if ($date instanceof FakeSaleDate && $date->getTimestamp() > time()) {
                $fakeSaleSchedules[$hook][$id] = $date->getTimestamp();
            }
        }
    }
    function as_unschedule_all_actions(string $hook, array $args = [], string $group = ''): int {
        global $fakeSaleSchedules, $fakeSaleUnscheduleCalls;
        $id = (int) ($args['product_id'] ?? 0);
        $fakeSaleUnscheduleCalls[] = $id;
        $had = isset($fakeSaleSchedules[$hook][$id]);
        unset($fakeSaleSchedules[$hook][$id]);
        return $had ? 1 : 0;
    }
    function as_next_scheduled_action(string $hook, array $args = [], string $group = ''): int|false {
        global $fakeSaleSchedules, $fakeSaleVerificationFailure;
        if ($fakeSaleVerificationFailure) {
            return false;
        }
        return $fakeSaleSchedules[$hook][(int) ($args['product_id'] ?? 0)] ?? false;
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
        global $fakeWpCacheDeletes, $fakeWpCache;
        $fakeWpCacheDeletes[] = ['key' => $key, 'group' => $group];
        unset($fakeWpCache[$group][$key]);
    }
    function wp_cache_get(string $key, string $group = '') {
        global $fakeWpCache;
        return $fakeWpCache[$group][$key] ?? false;
    }
    function wp_cache_set(string $key, $value, string $group = ''): bool {
        global $fakeWpCache;
        $fakeWpCache[$group][$key] = $value;
        return true;
    }
    function wc_stock_amount($value) { return (float) $value; }
    function add_filter(string $hook, callable $callback, int $priority = 10, int $acceptedArgs = 1): bool {
        global $fakeFilters;
        $fakeFilters[$hook][$priority][] = [$callback, $acceptedArgs];
        return true;
    }
    function remove_filter(string $hook, callable $callback, int $priority = 10): bool {
        global $fakeFilters;
        foreach (($fakeFilters[$hook][$priority] ?? []) as $index => [$candidate]) {
            if ($candidate === $callback) {
                unset($fakeFilters[$hook][$priority][$index]);
                return true;
            }
        }
        return false;
    }
    function get_terms(array $args): array {
        global $fakeFilters, $fakeTermQueries;
        $taxonomies = (array) ($args['taxonomy'] ?? []);
        $filters = $fakeFilters['get_terms_args'] ?? [];
        ksort($filters);
        foreach ($filters as $callbacks) {
            foreach ($callbacks as [$callback, $acceptedArgs]) {
                $args = $acceptedArgs >= 2
                    ? $callback($args, $taxonomies)
                    : $callback($args);
            }
        }
        $fakeTermQueries[] = $args;
        // Model Polylang's current-language term clauses: absent `lang`
        // exposes only red, while explicit empty `lang` means all languages.
        return array_key_exists('lang', $args) && $args['lang'] === ''
            ? [101 => 'red', 102 => 'blue']
            : [101 => 'red'];
    }
    function wc_sanitize_taxonomy_name(string $name): string { return $name; }
    function is_wp_error($value): bool { return false; }
    function get_option(string $key, $default = false) { return $key === 'woocommerce_schema_version' ? 1000 : $default; }

    require dirname(__DIR__, 2) . '/agent/src/Canon.php';
    require dirname(__DIR__, 2) . '/agent/src/OptionState.php';
    require dirname(__DIR__, 2) . '/agent/src/Policy.php';
    // invoke() reads the engine's reserved batch-argument name from the
    // contract itself rather than restating the literal.
    require dirname(__DIR__, 2) . '/agent/src/Providers.php';
    require dirname(__DIR__, 2) . '/manifests/providers/woocommerce-product-lookups.php';

    $reflection = new \ReflectionClass(\Duo\Policy::class);
    $policy = $reflection->newInstanceWithoutConstructor();
    $adapter = new \Duo\Providers\WoocommerceProductLookups($policy);
    // Prime a product read before the registry refresh. The adapter must evict
    // this stale parsed object after registering the newly-applied taxonomy.
    wc_get_product(15);
    $adapter->regenerate_batch([10, 11, 12], []);

    $failures = 0;
    $check = static function (bool $condition, string $message) use (&$failures): void {
        echo ($condition ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$condition) { $failures++; }
    };

    // Sale scheduling is a bounded product batch, not a catalog-wide scan.
    // Exercise a future end date, an unrelated no-date product, heartbeat
    // calls, exact Action Scheduler readback, and a verifier fault that must
    // fail closed before the retry is allowed to pass.
    $futureSale = time() + 3600;
    $fakeMeta[13]['_sale_price_dates_to'] = [(string) $futureSale];
    $saleCallStart = count($fakeSaleScheduleCalls);
    $heartbeats = 0;
    $adapter->regenerate_batch([13], [], static function () use (&$heartbeats): void {
        $heartbeats++;
    });
    $saleCalls = array_slice($fakeSaleScheduleCalls, $saleCallStart);
    $check(in_array(13, $saleCalls, true), 'product batch invokes Woo sale scheduling for the affected product only');
    $check(($fakeSaleSchedules['wc_product_end_scheduled_sale'][13] ?? null) === $futureSale,
        'future sale end action is present at the exact authored timestamp');
    $check($heartbeats > 0, 'product sale scheduling batch renews its heartbeat around bounded work');
    $unrelatedCallStart = count($fakeSaleScheduleCalls);
    $adapter->regenerate_batch([14], []);
    $unrelatedCalls = array_slice($fakeSaleScheduleCalls, $unrelatedCallStart);
    $check($unrelatedCalls === [14], 'an unrelated no-date product is the only sale-scheduling candidate in its batch');
    $check(as_next_scheduled_action('wc_product_end_scheduled_sale', ['product_id' => 14], 'woocommerce-sales') === false,
        'no-date product has no unexpected sale action');
    $fakeSaleVerificationFailure = true;
    $saleVerificationFailedClosed = false;
    try {
        $adapter->regenerate_batch([13], []);
    } catch (\Throwable $failure) {
        $saleVerificationFailedClosed = str_contains($failure->getMessage(), 'sale schedule verification mismatch');
    }
    $fakeSaleVerificationFailure = false;
    $check($saleVerificationFailedClosed, 'sale schedule verification refuses a failed Action Scheduler readback');
    $adapter->regenerate_batch([13], []);
    $check(($fakeSaleSchedules['wc_product_end_scheduled_sale'][13] ?? null) === $futureSale,
        'sale scheduling retry restores the exact future action after a verification failure');

    $groupedDiscovery = new \ReflectionMethod($adapter, 'find_grouped_parent_ids');
    $groupedDiscovery->setAccessible(true);
    $wpdb->failReadContaining = "meta_key = '_children'";
    $groupedReadFailedClosed = false;
    try {
        $groupedDiscovery->invoke($adapter, 11);
    } catch (\Throwable $failure) {
        $groupedReadFailedClosed = str_contains($failure->getMessage(), 'grouped parent discovery query failed');
    }
    $wpdb->failReadContaining = null;
    $check($groupedReadFailedClosed,
        'grouped-parent discovery fails closed when its database read fails');

    $verifyExactState = new \ReflectionMethod($adapter, 'verify_exact_state');
    $verifyExactState->setAccessible(true);
    // Bind by the method's own arity rather than a fixed argument list. The
    // deletion-only path this exercises is identical either way, and the
    // tolerance is what lets this suite be run verbatim against an older
    // adapter revision to see which checks its behavior actually fails —
    // without a harness TypeError standing in for a real finding.
    $verifyExactStateArgs = $verifyExactState->getNumberOfParameters() >= 6
        ? [WC_Data_Store::load('product'), [], [], [], [999 => 999], null]
        : [[], [], [], [999 => 999], null];
    $wpdb->failReadContaining = 'wc_product_meta_lookup';
    $deletionReadFailedClosed = false;
    try {
        $verifyExactState->invokeArgs($adapter, $verifyExactStateArgs);
    } catch (\Throwable $failure) {
        $deletionReadFailedClosed = str_contains(
            $failure->getMessage(),
            'product lookup deletion verification for product 999 query failed'
        );
    }
    $wpdb->failReadContaining = null;
    $check($deletionReadFailedClosed,
        'lookup deletion verification cannot clear a receipt after a failed count query');

    $actualAttributeRows = new \ReflectionMethod($adapter, 'actual_attribute_rows');
    $actualAttributeRows->setAccessible(true);
    $wpdb->failReadContaining = 'wc_product_attributes_lookup';
    $attributeReadFailedClosed = false;
    try {
        $actualAttributeRows->invoke($adapter, 10);
    } catch (\Throwable $failure) {
        $attributeReadFailedClosed = str_contains(
            $failure->getMessage(),
            'product attribute lookup verification for product 10 query failed'
        );
    }
    $wpdb->failReadContaining = null;
    $check($attributeReadFailedClosed,
        'attribute lookup verification cannot treat a failed query as an empty table');

    $check($fakeMeta[11]['_price'] === ['18'], 'stale child _price is recomputed from regular/sale inputs');
    $check($fakeMeta[10]['_price'] === ['18', '21'], 'variable parent _price is synchronized from distinct child prices');
    $check($fakeMeta[10]['_regular_price'] === [''] && $fakeMeta[10]['_sale_price'] === [''],
        'variable sync preserves explicit empty authored parent price rows');
    $check((WC_Data_Store::$variable?->calls ?? 0) === 1, 'variable root synchronization is deduplicated');
    $check($fakeMetaLookup[11]['min_price'] === '18', 'existing stale wc_product_meta_lookup row is refreshed');
    $check($fakeMetaLookup[11]['onsale'] === 1, 'onsale follows Woo sale-price/effective-price equality');
    $check($fakeMeta[11]['_stock'] === ['5'], 'target-local runtime stock meta is preserved');
    $check(count($fakeAttrLookup) === 2, 'attribute lookup rows are regenerated synchronously and exactly');
    $check($fakeTermQueries !== [] && count(array_filter(
        $fakeTermQueries,
        static fn(array $args): bool => !array_key_exists('lang', $args) || $args['lang'] !== ''
    )) === 0, 'public and verifier attribute term reads explicitly span all Polylang languages');
    $remainingTermFilters = array_filter(
        $fakeFilters['get_terms_args'][1] ?? [],
        static fn(array $entry): bool => isset($entry[0])
    );
    $check($remainingTermFilters === [], 'all-language term filter is removed after synchronous generation');
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
    $fakeSaleSchedules['wc_product_end_scheduled_sale'][41] = time() + 3600;
    $deleteSaleCallStart = count($fakeSaleUnscheduleCalls);
    $adapter->regenerate_batch([], $groupedDeleteContext);
    $deleteSaleCalls = array_slice($fakeSaleUnscheduleCalls, $deleteSaleCallStart);
    $check(in_array(41, $deleteSaleCalls, true), 'deleted product sale actions are cleared through the bounded id context');
    $check(as_next_scheduled_action('wc_product_end_scheduled_sale', ['product_id' => 41], 'woocommerce-sales') === false,
        'deleted product has no residual end-sale action after bounded cleanup');
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
    // get_post_meta($id, 'total_sales', true) returns '' for absent meta, not
    // null (class-wc-product-data-store-cpt.php:2513), and total_sales is
    // always in the derived set, so bigint(20) stores 0. The retired "Woo
    // stores SQL NULL here" reading was wrong about WooCommerce.
    $check($fakeMetaLookup[13]['total_sales'] === '0',
        'absent total_sales source meta reaches the bigint lookup column as zero, never SQL NULL');

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

    // On a fresh target the derived product_type relationship is absent, so
    // Woo's ordinary factory reports the root as simple. Put lower-id
    // variations before their higher-id parent to reproduce the live R3-A
    // ordering that originally erased/omitted lookup rows. The adapter must
    // infer a WC_Product_Variable object without persisting product_type.
    $fakeProducts[68] = new FakeProduct(68, 'variation', 70, [], ['pa_color' => 'red'], true);
    $fakeProducts[69] = new FakeProduct(69, 'variation', 70, [], ['pa_color' => 'blue'], true);
    $fakeProducts[70] = new FakeProduct(
        70,
        'simple',
        0,
        [68, 69],
        ['pa_color' => new FakeProductAttribute(12, [101, 102], true)],
        true
    );
    $fakeMeta[68] = $fakeMeta[12];
    $fakeMeta[68]['_regular_price'] = ['18'];
    $fakeMeta[68]['_sale_price'] = [''];
    $fakeMeta[69] = $fakeMeta[12];
    $fakeMeta[70] = $fakeMeta[10];
    foreach ([68, 69, 70] as $id) {
        $fakeMetaLookup[$id] = $fakeMetaLookup[10];
        $fakeMetaLookup[$id]['product_id'] = $id;
        $fakeMetaLookup[$id]['min_price'] = '999';
        $fakeMetaLookup[$id]['max_price'] = '999';
    }
    $adapter->regenerate_batch([68, 69, 70], []);
    $freshRows = array_values(array_filter(
        $fakeAttrLookup,
        static fn(array $row): bool => (int) $row['product_or_parent_id'] === 70
    ));
    $check($fakeMeta[70]['_price'] === ['18', '21'],
        'fresh target infers variable-root price synthesis from authored child posts');
    $check(count($freshRows) === 2
        && array_column($freshRows, 'product_id') === [68, 69],
        'child-before-parent fresh target generates the exact cross-language variation lookup graph');
    $check($fakeProducts[70]->get_type() === 'simple',
        'fresh-target classification stays in memory and never persists a derived product_type');

    // DUO-3342: wc_product_meta_lookup verification no longer rebuilds Woo's
    // column rules. It brackets one forced re-derivation with two reads — the
    // row the apply left (before) and the row Woo rewrote (after) — and
    // compares both, plus Woo's published derivation, in the database.
    //
    // Honest framing, because it matters for what these checks claim: the
    // retired per-column table was ALREADY strict for an expected null (it
    // required null or '', rejecting 0), so none of this catches a defect that
    // rule let through. What changes is that the rules are WooCommerce's
    // rather than a hand-copy of a protected method, and that the before-read
    // restores a check the naive shape would have lost.
    $check(!method_exists($adapter, 'lookup_values_equal')
        && !method_exists($adapter, 'is_on_sale_from_meta')
        && !method_exists($adapter, 'cogs_lookup_enabled')
        && !method_exists($adapter, 'stock_quantity_from_meta'),
        'no Duo-authored copy of WooCommerce lookup column rules remains in the adapter');

    // THE RESTORED TOOTH. Reaching a variable root through one of its
    // variations puts the root in $variableRoots and only that variation in
    // $priceIds, so a SIBLING variation is verified but is never in the
    // batch's own refresh set. Its stale row must be refused. A verification
    // that read the row only after its own refresh would silently rewrite this
    // and pass.
    $fakeProducts[91] = new FakeProduct(91, 'variation', 90, [], ['pa_color' => 'red'], true);
    $fakeProducts[92] = new FakeProduct(92, 'variation', 90, [], ['pa_color' => 'blue'], true);
    $fakeProducts[90] = new FakeProduct(
        90,
        'variable',
        0,
        [91, 92],
        ['pa_color' => new FakeProductAttribute(13, [101, 102], true)],
        true
    );
    foreach ([90, 91, 92] as $id) {
        $fakeMeta[$id] = $fakeMeta[12];
        $fakeMetaLookup[$id] = $fakeMetaLookup[12];
        $fakeMetaLookup[$id]['product_id'] = $id;
    }
    $adapter->regenerate_batch([90], []);              // converge the whole family
    $fakeMetaLookup[92]['min_price'] = 'left-behind';  // sibling row goes stale
    $staleSiblingMessage = '';
    try {
        $adapter->regenerate_batch([91], []);          // reached via the variation path
    } catch (\Throwable $failure) {
        $staleSiblingMessage = $failure->getMessage();
    }
    $check(str_contains($staleSiblingMessage, 'product lookup verification mismatch for product 92')
        && str_contains($staleSiblingMessage, 'the apply left this WooCommerce product lookup row divergent'),
        'a sibling variation row the apply never refreshed is refused, not silently rewritten');
    $adapter->regenerate_batch([90], []);
    $check(($fakeMetaLookup[92]['min_price'] ?? null) === '21',
        'the refused sibling row is repaired by the refresh, so the retry converges');

    // A column OUTSIDE Woo's derived set that still holds data — cogs_total_
    // value with the COGS feature off, or anything a third party maintains —
    // is reset by Woo's own DELETE+INSERT whatever the apply did, so blaming
    // the apply for it would be a false attribution. Only reachable on a row
    // the batch's own refresh pass never touches, because that pass would
    // otherwise have reset the column before verification snapshots it — so
    // the sibling path is the case, again.
    $fakeMetaLookup[92]['cogs_total_value'] = '42.0000';
    $residualColumnMessage = '';
    try {
        $adapter->regenerate_batch([91], []);
    } catch (\Throwable $failure) {
        $residualColumnMessage = $failure->getMessage();
    }
    $check($residualColumnMessage === '',
        'a residual non-derived lookup column, reset by Woo own write, is not blamed on the apply');

    // The other axis: Woo's write not landing what Woo derived. Real Woo
    // permits this — update_lookup_table() ignores $wpdb->replace()'s return
    // value and caches its derivation unconditionally.
    $fakeLookupWriteFaults[13] = ['sku' => 'NOT-WHAT-WOO-DERIVED'];
    $skuFaultMessage = '';
    try {
        $adapter->regenerate_batch([13], []);
    } catch (\Throwable $failure) {
        $skuFaultMessage = $failure->getMessage();
    }
    $fakeLookupWriteFaults = [];
    $check(str_contains($skuFaultMessage, 'product lookup verification mismatch for product 13')
        && str_contains($skuFaultMessage, 'did not land the values WooCommerce derived')
        && str_contains($skuFaultMessage, 'NOT-WHAT-WOO-DERIVED'),
        'a lookup write that did not land WooCommerce derived values fails closed and names both sides');

    // PARITY, not a caught defect: stock_quantity is the one column Woo really
    // derives as PHP null (unmanaged stock), and SQL NULL is a different row
    // from 0 to every consumer. The retired comparison rejected this too; what
    // is new is that the null comes from Woo's own derivation rather than from
    // a Duo reimplementation of when Woo produces one.
    $fakeMeta[13]['_manage_stock'] = ['no'];
    $fakeLookupWriteFaults[13] = ['stock_quantity' => '0'];
    $nullStockMessage = '';
    try {
        $adapter->regenerate_batch([13], []);
    } catch (\Throwable $failure) {
        $nullStockMessage = $failure->getMessage();
    }
    $fakeLookupWriteFaults = [];
    $fakeMeta[13]['_manage_stock'] = ['yes'];
    $check(str_contains($nullStockMessage, 'product lookup verification mismatch for product 13')
        && str_contains($nullStockMessage, 'did not land the values WooCommerce derived'),
        'a column WooCommerce derived as SQL NULL is refused when the table stored zero (parity)');

    // A standalone product for the cache cases: ids 10-12 have been through
    // deletion and reparent fixtures by this point in the suite.
    $fakeProducts[80] = new FakeProduct(80, 'simple', 0, [], [], true);
    $fakeMeta[80] = [
        '_price' => ['21'], '_regular_price' => ['21'], '_sale_price' => [''],
        '_sale_price_dates_from' => [''], '_sale_price_dates_to' => [''],
        '_stock_status' => ['instock'], '_manage_stock' => ['yes'], '_stock' => ['5'],
        '_tax_status' => ['taxable'], '_tax_class' => [''], '_wc_rating_count' => [[]],
        '_sku' => [''], '_virtual' => ['no'], '_downloadable' => ['no'],
        'total_sales' => ['0'], '_wc_average_rating' => [''], '_global_unique_id' => [''],
    ];

    // Woo's update_lookup_table() short-circuits on the `lookup_table` object
    // cache rather than on the stored row, so a cache warmed earlier in the
    // same request turns its refresh into a silent no-op. Warm it by
    // converging once, then corrupt the row behind Woo's back: the adapter's
    // eviction before each refresh is what keeps both the repair and the
    // readback real rather than a comparison of one cached value with itself.
    $adapter->regenerate_batch([80], []);
    $fakeMetaLookup[80]['min_price'] = '4242';
    $adapter->regenerate_batch([80], []);
    $check(($fakeMetaLookup[80]['min_price'] ?? null) === '21',
        'a warm WooCommerce lookup-derivation cache cannot short-circuit the repair or its verification');

    // Woo publishing no derivation at all must refuse, not silently skip the
    // value check: an unverifiable readback is exactly the state a receipt is
    // not allowed to clear on.
    $fakeLookupCacheSuppressed = [80];
    $noDerivationCaught = false;
    try {
        $adapter->regenerate_batch([80], []);
    } catch (\Throwable $failure) {
        $noDerivationCaught = str_contains(
            $failure->getMessage(),
            'published no product lookup derivation for product 80'
        );
    }
    $fakeLookupCacheSuppressed = [];
    $check($noDerivationCaught,
        'verification fails closed when WooCommerce publishes no lookup derivation to read back');
    $adapter->regenerate_batch([80], []);
    $check(($fakeMetaLookup[80]['min_price'] ?? null) === '21',
        'the batch converges again once WooCommerce publishes its derivation');

    // ------------------------------------------------------------------
    // DUO-3342: invoke() is the only NEW adapter code — the mapping from the
    // engine batch envelope onto the two arguments everything above drives
    // directly. Everything it must not lose is asserted here against the same
    // fixture: the entity ids, the captured deletion inventory, and (the
    // subtle one) the accumulated reparent roots the engine delivers as one
    // row per root.
    // ------------------------------------------------------------------
    // No setAccessible(): reflection reaches a private directly from PHP 8.1,
    // and calling it only adds a deprecation notice on 8.5+.
    $mapChannels = new \ReflectionMethod($adapter, 'deletion_context_from_channels');
    $mapped = $mapChannels->invoke(
        $adapter,
        [[
            'kind' => 'post:product_variation', 'uuid' => 'variation-delete', 'id' => 11,
            'post_type' => 'product_variation', 'parent_id' => 10, 'child_ids' => [12, 11],
        ]],
        [
            ['kind' => 'post:product_variation', 'uuid' => 'moved', 'id' => 21, 'root_id' => 24,
             'old_parent_id' => 22, 'new_parent_id' => 20],
            ['kind' => 'post:product_variation', 'uuid' => 'moved', 'id' => 21, 'root_id' => 20,
             'old_parent_id' => 22, 'new_parent_id' => 20],
            ['kind' => 'post:product_variation', 'uuid' => 'moved', 'id' => 21, 'root_id' => 22,
             'old_parent_id' => 22, 'new_parent_id' => 20],
        ]
    );
    $check($mapped[0] === [
        'kind' => 'delete', 'uuid' => 'variation-delete', 'id' => 11,
        'post_type' => 'product_variation', 'parent_id' => 10, 'child_ids' => [11, 12],
    ], 'a deletions channel row maps onto the regenerator-shaped delete context with its captured '
        . 'parent_id and child_ids intact');
    $check(count($mapped) === 2
        && ($mapped[1]['kind'] ?? null) === 'reparent'
        && ($mapped[1]['root_ids'] ?? null) === [20, 22, 24]
        && ($mapped[1]['id'] ?? null) === 21
        && ($mapped[1]['post_type'] ?? null) === 'product_variation',
        'and THREE reparent rows for one entity regroup into ONE context carrying all three roots — '
        . 'collapsing to the old/new pair would silently strand the first root of a chained move');
    $check(($mapped[1]['old_parent_id'] ?? null) === 22 && ($mapped[1]['new_parent_id'] ?? null) === 20
        && ($mapped[1]['parent_id'] ?? null) === 22,
        'the old/new pair rides along for the pre-root_ids fallback the batch entry point still honors');

    // The whole envelope, through the real invoke(), against the same fixture
    // the deletion scenarios above used: a receipt whose before/after are
    // observed row cardinalities and whose verified is true only because the
    // exact-state pass inside regenerate_batch() already ran.
    $fakeMetaLookup[11] = $fakeMetaLookup[12];
    $fakeMetaLookup[11]['product_id'] = 11;
    $receipt = $adapter->invoke('rebuild_product_lookups', [
        'entities' => [
            'entities' => [['kind' => 'post:product', 'id' => 10]],
            'always_on_write' => true,
            'deletions' => [[
                'kind' => 'post:product_variation', 'uuid' => 'variation-delete', 'id' => 11,
                'post_type' => 'product_variation', 'parent_id' => 10, 'child_ids' => [],
            ]],
            'reparents' => [],
            'retry' => false,
        ],
    ]);
    $check(array_keys($receipt) === ['before', 'after', 'verified'] && $receipt['verified'] === true,
        'invoke() returns exactly the before/after/verified receipt the provider contract requires');
    $check(($receipt['before']['scoped_products'] ?? null) === 2
        && ($receipt['before']['meta_lookup_rows'] ?? null) === 2
        && ($receipt['after']['meta_lookup_rows'] ?? null) === 1,
        'the receipt observes the ids it was handed on both sides, and records the deleted row disappearing');
    $check(!isset($fakeMetaLookup[11]),
        'and the envelope really reached the deletion path — the deleted lookup row is gone');
    $unknownCapabilityCaught = false;
    try {
        $adapter->invoke('regenerate_everything', ['entities' => []]);
    } catch (\Throwable $failure) {
        $unknownCapabilityCaught = str_contains($failure->getMessage(), "does not implement capability");
    }
    $check($unknownCapabilityCaught, 'an unadvertised capability name is refused rather than silently run');
    $missingEnvelopeCaught = false;
    try {
        $adapter->invoke('rebuild_product_lookups', ['entities' => [['kind' => 'post:product', 'id' => 10]]]);
    } catch (\Throwable $failure) {
        $missingEnvelopeCaught = str_contains($failure->getMessage(), 'no engine batch envelope');
    }
    $check($missingEnvelopeCaught,
        'a bare batch where the declared channels belong fails closed — a missing channel would mean this '
        . 'adapter silently stopped seeing tombstones');

    if ($failures > 0) {
        echo "FAIL: $failures check(s) failed\n";
        exit(1);
    }
    echo "ALL PASSED\n";
}
