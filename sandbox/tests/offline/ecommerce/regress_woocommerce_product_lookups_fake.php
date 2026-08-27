<?php
/**
 * Offline fake-WP/DB regression for the WooCommerce lookup adapter.
 *
 * This intentionally models only the public Woo APIs the shipped adapter is
 * allowed to call.  It proves stale _price repair, variable-root dedupe,
 * deletion context (including the parent id), runtime stock preservation, and
 * exact product-meta and product-attribute lookup verification without
 * Docker. Attribute rows are synthesized through Woo's public scoped writer,
 * read back from SQL, and receipt-bound; the separate post-init taxonomy
 * refresh is checked for both private and public attributes.
 */

namespace Automattic\WooCommerce\Internal\CostOfGoodsSold {
    final class CostOfGoodsSoldController {
        public static ?self $instance = null;
        public bool $enabled = false;
        public bool $lookupColumnPresent = false;

        public function feature_is_enabled(): bool {
            return $this->enabled;
        }

        public function product_meta_lookup_table_cogs_value_columns_exist(): bool {
            return $this->lookupColumnPresent;
        }
    }
}

namespace Automattic\WooCommerce\Utilities {
    final class NumberUtil {
        public static function round($value, int $precision = 0, int $mode = PHP_ROUND_HALF_UP): float {
            return round((float) $value, $precision, $mode);
        }
    }
}

namespace Automattic\WooCommerce\Internal\ProductAttributesLookup {
    final class LookupDataStore {
        public const ACTION_DELETE = 3;
        public static ?self $instance = null;
        public bool $failed = false;
        public int $failNextCreates = 0;
        public int $createCalls = 0;
        public int $deleteCalls = 0;

        public function create_data_for_product($product, $optimized = false): void {
            global $fakeProducts, $fakeAttrLookup;
            $this->createCalls++;
            $this->failed = false;
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
            if ($this->failNextCreates > 0) {
                $this->failNextCreates--;
                $this->failed = true;
                return;
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

namespace Automattic\WooCommerce\Internal\Utilities {
    final class URL {
        public function __construct(private string $url) {}

        public function get_parent_url(): string|false {
            $withoutQuery = explode('?', $this->url, 2)[0];
            $slash = strrpos($withoutQuery, '/');
            return $slash === false ? false : substr($withoutQuery, 0, $slash + 1);
        }
    }
}

namespace Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories {
    final class StoredUrl {
        public function __construct(private int $id, private string $url, private bool $enabled) {}
        public function get_id(): int { return $this->id; }
        public function get_url(): string { return $this->url; }
        public function is_enabled(): bool { return $this->enabled; }
    }

    final class Register {
        public const MODE_DISABLED = 'disabled';
        public const MODE_ENABLED = 'enabled';
        public static ?self $instance = null;
        /** @var array<string,array{id:int,enabled:bool}> */
        public array $rules = [];
        public string $mode = self::MODE_ENABLED;
        public int $adds = 0;
        public int $enables = 0;
        public bool $failAdd = false;
        public bool $failEnable = false;
        public bool $failValidation = false;
        private int $nextId = 1;

        public function get_mode(): string { return $this->mode; }

        public function get_by_url(string $url): StoredUrl|false {
            $url = rtrim($url, '/') . '/';
            $row = $this->rules[$url] ?? null;
            return is_array($row) ? new StoredUrl($row['id'], $url, $row['enabled']) : false;
        }

        public function add_approved_directory(string $url, bool $enabled = true): int {
            if ($this->failAdd) {
                throw new \RuntimeException('simulated add failure containing api_key=do-not-leak');
            }
            $url = rtrim($url, '/') . '/';
            if (isset($this->rules[$url])) {
                return $this->rules[$url]['id'];
            }
            $id = $this->nextId++;
            $this->rules[$url] = ['id' => $id, 'enabled' => $enabled];
            $this->adds++;
            return $id;
        }

        public function enable_by_id(int $id): bool {
            if ($this->failEnable) {
                return false;
            }
            foreach ($this->rules as $url => $row) {
                if ($row['id'] === $id) {
                    $this->rules[$url]['enabled'] = true;
                    $this->enables++;
                    return true;
                }
            }
            return false;
        }

        public function is_valid_path(string $file): bool {
            if ($this->failValidation) {
                throw new \RuntimeException('simulated validation failure containing token=do-not-leak');
            }
            foreach ($this->rules as $url => $row) {
                if ($row['enabled'] && str_starts_with($file, $url)) {
                    return true;
                }
            }
            return false;
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

    final class FakeDownloadWakeupCanary {
        public function __wakeup(): void {
            $GLOBALS['fakeDownloadWakeups']++;
        }
    }

    final class FakeWpdb {
        /**
         * wc_product_meta_lookup's real column types, verbatim from
         * WooCommerce 11.0.x (class-wc-install.php:1977): every column but
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
            'cogs_total_value' => 'decimal',
        ];

        public const LOOKUP_COLUMN_DECLARATIONS = [
            'product_id' => 'bigint(20)',
            'sku' => 'varchar(100)',
            'global_unique_id' => 'varchar(100)',
            'virtual' => 'tinyint(1)',
            'downloadable' => 'tinyint(1)',
            'min_price' => 'decimal(19,4)',
            'max_price' => 'decimal(19,4)',
            'onsale' => 'tinyint(1)',
            'stock_quantity' => 'double',
            'stock_status' => 'varchar(100)',
            'rating_count' => 'bigint(20)',
            'average_rating' => 'decimal(3,2)',
            'total_sales' => 'bigint(20)',
            'tax_status' => 'varchar(100)',
            'tax_class' => 'varchar(100)',
            'cogs_total_value' => 'decimal(19,4)',
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

        public static function decimal_assignment(mixed $value, int $scale): string {
            return number_format((float) $value, $scale, '.', '');
        }

        public string $prefix = 'wp_';
        public string $postmeta = 'wp_postmeta';
        public string $posts = 'wp_posts';
        public string $terms = 'wp_terms';
        public string $term_taxonomy = 'wp_term_taxonomy';
        public string $term_relationships = 'wp_term_relationships';
        public string $last_error = '';
        public ?string $failReadContaining = null;
        public bool $failAttributeDelete = false;
        public bool $nullTransactionStateRead = false;
        public array $lookupColumnDeclarations = self::LOOKUP_COLUMN_DECLARATIONS;
        public array $lookupReceiptExtraRows = [];
        public mixed $attributeCountOverride = null;
        public bool $attributePayloadDropLast = false;
        public mixed $afterAttributePayload = null;
        public ?array $groupedParentOverride = null;
        /** @var callable|null invoked at each bounded child inventory read */
        public mixed $childScopeReadHook = null;
        /** @var callable|null invoked at each bounded grouped reverse-owner read */
        public mixed $groupedParentReadHook = null;
        public mixed $afterDownloadWitness = null;
        public ?string $downloadMetaKeyOverride = null;
        public ?array $lookupSchemaRowsOverride = null;
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
            if (preg_match(
                '/DELETE FROM `wp_wc_product_attributes_lookup` WHERE product_id IN \\(([0-9, ]+)\\) OR product_or_parent_id IN \\(([0-9, ]+)\\)/',
                $query,
                $match
            )) {
                if ($this->failAttributeDelete) {
                    $this->last_error = 'simulated secret=do-not-leak';
                    return false;
                }
                $ids = array_map('intval', preg_split('/\\s*,\\s*/', trim($match[1])) ?: []);
                $roots = array_map('intval', preg_split('/\\s*,\\s*/', trim($match[2])) ?: []);
                $before = count($fakeAttrLookup);
                $fakeAttrLookup = array_values(array_filter($fakeAttrLookup, static fn(array $row): bool =>
                    !in_array((int) $row['product_id'], $ids, true)
                    && !in_array((int) $row['product_or_parent_id'], $roots, true)
                ));
                return $before - count($fakeAttrLookup);
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
            global $fakeGroupedChildren, $fakeProducts;
            if ($this->failReadContaining !== null && str_contains($query, $this->failReadContaining)) {
                $this->last_error = 'simulated read failure';
                return [];
            }
            if (preg_match(
                '/SELECT ID FROM wp_posts\s+WHERE post_parent = (\d+)\s+AND post_type = \'product_variation\'.*?LIMIT ([0-9]+)/s',
                $query,
                $match
            )) {
                if (is_callable($this->childScopeReadHook)) {
                    ($this->childScopeReadHook)();
                }
                $parentId = (int) $match[1];
                $rows = [];
                $parent = $fakeProducts[$parentId] ?? null;
                foreach ((array) ($parent?->get_children() ?? []) as $id) {
                    $id = (int) $id;
                    $product = $fakeProducts[$id] ?? null;
                    if ($product !== null
                        && ($product->get_type() !== 'variation'
                            || (int) $product->get_parent_id('edit') !== $parentId
                            || !in_array($product->get_status(), ['publish', 'private'], true))) {
                        continue;
                    }
                    if ($id > 0) {
                        $rows[$id] = $id;
                    }
                }
                ksort($rows, SORT_NUMERIC);
                return array_slice(array_values($rows), 0, (int) $match[2]);
            }
            if (!str_contains($query, "meta_key = '_children'")) {
                return [];
            }
            if (is_array($this->groupedParentOverride)) {
                $rows = $this->groupedParentOverride;
                if (preg_match('/LIMIT ([0-9]+)$/', trim($query), $limit)) {
                    $rows = array_slice($rows, 0, (int) $limit[1]);
                }
                return $rows;
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
            $rows = array_values($ids);
            if (preg_match('/LIMIT ([0-9]+)$/', trim($query), $limit)) {
                $rows = array_slice($rows, 0, (int) $limit[1]);
            }
            return $rows;
        }

        public function get_results(string $query, $output = null): array {
            global $fakeAttrLookup, $fakeMeta, $fakeMetaLookup, $fakeDownloadMetaRows, $fakeCogsMetaRows,
                $fakeProducts, $fakePostTypeOverrides, $fakeVisibilityRelationships, $fakeVisibilityTerms,
                $fakeVisibilityQueries, $fakeVisibilityChildFlood, $fakeGroupedChildren;
            if (!str_contains($query, 'SELECT DISTINCT pm.post_id')) {
                $fakeVisibilityQueries[] = $query;
            }
            if ($this->failReadContaining !== null && str_contains($query, $this->failReadContaining)) {
                $this->last_error = 'simulated read failure';
                return [];
            }
            if (str_contains($query, 'SELECT DISTINCT pm.post_id')
                && str_contains($query, 'AS child_id')) {
                if (is_callable($this->groupedParentReadHook)) {
                    ($this->groupedParentReadHook)();
                }
                preg_match_all('/(\d+) AS child_id/', $query, $matches);
                $candidateIds = array_values(array_unique(array_map('intval', $matches[1] ?? [])));
                $candidateSet = array_fill_keys($candidateIds, true);
                $seenEdges = [];
                $rows = [];
                foreach (($fakeGroupedChildren ?? []) as $parentId => $children) {
                    foreach ((array) $children as $childValue) {
                        $childId = (int) $childValue;
                        $edgeKey = (int) $parentId . ':' . $childId;
                        if (isset($candidateSet[$childId]) && !isset($seenEdges[$edgeKey])) {
                            $seenEdges[$edgeKey] = true;
                            $rows[] = [
                                'post_id' => (string) (int) $parentId,
                                'child_id' => (string) $childId,
                            ];
                        }
                    }
                }
                usort($rows, static fn(array $left, array $right): int => [
                    (int) $left['child_id'], (int) $left['post_id'],
                ] <=> [
                    (int) $right['child_id'], (int) $right['post_id'],
                ]);
                if (preg_match('/LIMIT (\d+)$/', trim($query), $limit)) {
                    $rows = array_slice($rows, 0, (int) $limit[1]);
                }
                return $rows;
            }
            if (preg_match(
                "/SELECT meta_id, LENGTH\\(meta_value\\) AS value_bytes FROM wp_postmeta\\s+WHERE post_id = (\\d+) AND meta_key = '_children'.*?LIMIT 2/s",
                $query,
                $match
            )) {
                if (is_callable($this->childScopeReadHook)) {
                    ($this->childScopeReadHook)();
                }
                $id = (int) $match[1];
                $children = $fakeGroupedChildren[$id]
                    ?? (($fakeProducts[$id] ?? null)?->get_children() ?? []);
                $serialized = serialize(array_values($children));
                return [['meta_id' => '1', 'value_bytes' => (string) strlen($serialized)]];
            }
            if (preg_match(
                "/SELECT meta_id, meta_value FROM wp_postmeta\\s+WHERE post_id = (\\d+) AND meta_key = '_children'.*?LIMIT 2/s",
                $query,
                $match
            )) {
                if (is_callable($this->childScopeReadHook)) {
                    ($this->childScopeReadHook)();
                }
                $id = (int) $match[1];
                $children = $fakeGroupedChildren[$id]
                    ?? (($fakeProducts[$id] ?? null)?->get_children() ?? []);
                return [['meta_id' => '1', 'meta_value' => serialize(array_values($children))]];
            }
            if (preg_match(
                '/SELECT ID, post_parent, post_type FROM wp_posts WHERE ID IN \(([0-9, ]+)\) ORDER BY ID ASC/',
                $query,
                $match
            )) {
                $ids = array_map('intval', preg_split('/\s*,\s*/', trim($match[1])) ?: []);
                $rows = [];
                foreach ($ids as $id) {
                    $product = $fakeProducts[$id] ?? null;
                    if (!is_object($product)) {
                        continue;
                    }
                    $postType = (string) ($fakePostTypeOverrides[$id]
                        ?? ($product->get_type() === 'variation' ? 'product_variation' : 'product'));
                    $rows[] = [
                        'ID' => (string) $id,
                        'post_parent' => (string) (int) $product->get_parent_id('edit'),
                        'post_type' => $postType,
                    ];
                }
                return $rows;
            }
            if (preg_match(
                "/SELECT ID, post_parent, post_type FROM wp_posts WHERE post_parent IN \\(([0-9, ]+)\\) AND post_type = 'product_variation' ORDER BY ID ASC LIMIT ([0-9]+)/",
                $query,
                $match
            )) {
                $parents = array_map('intval', preg_split('/\s*,\s*/', trim($match[1])) ?: []);
                if (is_array($fakeVisibilityChildFlood)
                    && in_array((int) ($fakeVisibilityChildFlood['parent'] ?? 0), $parents, true)) {
                    $rows = [];
                    $excludedCount = (int) ($fakeVisibilityChildFlood['excluded_count'] ?? 0);
                    $total = $excludedCount + 1;
                    $returned = min((int) $match[2], $total);
                    for ($offset = 0; $offset < $returned; $offset++) {
                        $rows[] = [
                            'ID' => (string) ($offset < $excludedCount
                                ? (int) $fakeVisibilityChildFlood['first'] + $offset
                                : (int) $fakeVisibilityChildFlood['survivor']),
                            'post_parent' => (string) (int) $fakeVisibilityChildFlood['parent'],
                            'post_type' => 'product_variation',
                        ];
                    }
                    return $rows;
                }
                $rows = [];
                foreach ($fakeProducts as $id => $product) {
                    if ($product->get_type() !== 'variation'
                        || !in_array((int) $product->get_parent_id('edit'), $parents, true)) {
                        continue;
                    }
                    $rows[] = [
                        'ID' => (string) $id,
                        'post_parent' => (string) (int) $product->get_parent_id('edit'),
                        'post_type' => 'product_variation',
                    ];
                }
                usort($rows, static fn(array $left, array $right): int => (int) $left['ID'] <=> (int) $right['ID']);
                return array_slice($rows, 0, (int) $match[2]);
            }
            if (str_contains($query, 'FROM wp_term_relationships tr')
                && str_contains(
                    $query,
                    "tt.taxonomy IN ('product_type', 'product_visibility', 'pos_product_visibility')"
                )) {
                if (!preg_match('/tr.object_id IN \(([0-9, ]+)\)/', $query, $match)) {
                    throw new \RuntimeException('fake wpdb could not parse product visibility relationship scope');
                }
                $ids = array_map('intval', preg_split('/\s*,\s*/', trim($match[1])) ?: []);
                $rows = [];
                foreach ($ids as $id) {
                    foreach ((array) ($fakeVisibilityRelationships[$id] ?? []) as $taxonomy => $relationships) {
                        foreach ((array) $relationships as $relationship) {
                            $rows[] = [
                                'object_id' => (string) $id,
                                'term_taxonomy_id' => (string) ($relationship['term_taxonomy_id'] ?? ''),
                                'term_order' => (string) ($relationship['term_order'] ?? ''),
                                'term_id' => (string) ($relationship['term_id'] ?? ''),
                                'taxonomy' => (string) ($relationship['taxonomy'] ?? $taxonomy),
                                'slug' => (string) ($relationship['slug'] ?? ''),
                                'name' => (string) ($relationship['name'] ?? ''),
                            ];
                        }
                    }
                }
                usort($rows, static fn(array $left, array $right): int => [
                    (int) $left['object_id'], $left['taxonomy'], $left['slug'], (int) $left['term_taxonomy_id'],
                ] <=> [
                    (int) $right['object_id'], $right['taxonomy'], $right['slug'],
                    (int) $right['term_taxonomy_id'],
                ]);
                preg_match('/LIMIT ([0-9]+)$/', $query, $limitMatch);
                return array_slice($rows, 0, (int) ($limitMatch[1] ?? count($rows)));
            }
            if (str_contains($query, 'FROM wp_terms t INNER JOIN wp_term_taxonomy tt')) {
                $rows = [];
                foreach ($fakeVisibilityTerms as $taxonomy => $terms) {
                    foreach ($terms as $term) {
                        $rows[] = [
                            'term_id' => (string) ($term['term_id'] ?? ''),
                            'name' => (string) ($term['name'] ?? ''),
                            'slug' => (string) ($term['slug'] ?? ''),
                            'term_taxonomy_id' => (string) ($term['term_taxonomy_id'] ?? ''),
                            'taxonomy' => (string) ($term['taxonomy'] ?? $taxonomy),
                        ];
                    }
                }
                usort($rows, static fn(array $left, array $right): int => [
                    $left['taxonomy'], $left['slug'], (int) $left['term_taxonomy_id'],
                ] <=> [
                    $right['taxonomy'], $right['slug'], (int) $right['term_taxonomy_id'],
                ]);
                return array_slice($rows, 0, 15);
            }
            if (str_contains($query, "meta_key IN ('_cogs_total_value', '_cogs_value_is_additive')")) {
                if (!preg_match('/post_id IN \(([0-9, ]+)\)/', $query, $match)) {
                    throw new \RuntimeException('fake wpdb could not parse Cost of Goods metadata scope');
                }
                $ids = array_map('intval', preg_split('/\s*,\s*/', trim($match[1])) ?: []);
                $rows = [];
                foreach ($ids as $id) {
                    foreach (['_cogs_total_value', '_cogs_value_is_additive'] as $key) {
                        $values = isset($fakeCogsMetaRows[$id]) && array_key_exists($key, (array) $fakeCogsMetaRows[$id])
                            ? (array) $fakeCogsMetaRows[$id][$key]
                            : (array) ($fakeMeta[$id][$key] ?? []);
                        foreach ($values as $index => $value) {
                            $rows[] = [
                                'post_id' => (string) $id,
                                'meta_id' => (string) ($id * 100 + ($key === '_cogs_total_value' ? 10 : 20) + $index),
                                'meta_key' => $key,
                                'meta_value' => substr((string) $value, 0, 129),
                                'meta_value_bytes' => (string) strlen((string) $value),
                            ];
                        }
                    }
                }
                usort($rows, static fn(array $left, array $right): int => [
                    (int) $left['post_id'], (string) $left['meta_key'], (int) $left['meta_id'],
                ] <=> [
                    (int) $right['post_id'], (string) $right['meta_key'], (int) $right['meta_id'],
                ]);
                preg_match('/LIMIT ([0-9]+)$/', trim($query), $limit);
                return array_slice($rows, 0, (int) ($limit[1] ?? count($rows)));
            }
            if (preg_match('/SELECT ID, post_type FROM wp_posts WHERE ID IN \(([0-9, ]+)\) ORDER BY ID ASC/', $query, $match)) {
                $ids = array_map('intval', preg_split('/\s*,\s*/', trim($match[1])) ?: []);
                $rows = [];
                foreach ($ids as $id) {
                    $product = $fakeProducts[$id] ?? null;
                    if (!is_object($product)) {
                        continue;
                    }
                    $rows[] = [
                        'ID' => (string) $id,
                        'post_type' => (string) ($fakePostTypeOverrides[$id]
                            ?? ($product->get_type() === 'variation' ? 'product_variation' : 'product')),
                    ];
                }
                return $rows;
            }
            if (str_contains($query, "meta_key = '_downloadable_files'")) {
                if (!preg_match('/post_id IN \(([0-9, ]+)\)/', $query, $match)) {
                    throw new \RuntimeException('fake wpdb could not parse downloadable metadata scope');
                }
                $ids = array_map('intval', preg_split('/\s*,\s*/', trim($match[1])) ?: []);
                $payload = str_contains($query, ' AS meta_key, meta_value,');
                $rows = [];
                foreach ($ids as $id) {
                    $values = array_key_exists($id, (array) $fakeDownloadMetaRows)
                        ? (array) $fakeDownloadMetaRows[$id]
                        : (array) ($fakeMeta[$id]['_downloadable_files'] ?? []);
                    foreach ($values as $index => $value) {
                        $row = [
                            'post_id' => (string) $id,
                            'meta_id' => (string) ($id * 10 + $index + 1),
                            'meta_key' => $this->downloadMetaKeyOverride ?? '_downloadable_files',
                            'meta_value_bytes' => (string) strlen((string) $value),
                            'meta_sha256' => hash('sha256', (string) $value),
                        ];
                        if ($payload) {
                            $row['meta_value'] = (string) $value;
                            // Preserve the provider SELECT order.
                            $row = [
                                'post_id' => $row['post_id'],
                                'meta_id' => $row['meta_id'],
                                'meta_key' => $row['meta_key'],
                                'meta_value' => $row['meta_value'],
                                'meta_value_bytes' => $row['meta_value_bytes'],
                                'meta_sha256' => $row['meta_sha256'],
                            ];
                        }
                        $rows[] = $row;
                    }
                }
                usort($rows, static fn(array $left, array $right): int => [
                    (int) $left['post_id'], (int) $left['meta_id'],
                ] <=> [
                    (int) $right['post_id'], (int) $right['meta_id'],
                ]);
                preg_match('/LIMIT ([0-9]+)$/', trim($query), $limit);
                $rows = array_slice($rows, 0, (int) ($limit[1] ?? count($rows)));
                if (!$payload && is_callable($this->afterDownloadWitness)) {
                    $callback = $this->afterDownloadWitness;
                    $this->afterDownloadWitness = null;
                    $callback();
                }
                return $rows;
            }
            if (preg_match('/SELECT ID, post_parent FROM wp_posts WHERE ID IN \(([0-9, ]+)\) ORDER BY ID ASC/', $query, $match)) {
                $ids = array_map('intval', preg_split('/\s*,\s*/', trim($match[1])) ?: []);
                $rows = [];
                foreach ($ids as $id) {
                    $product = $fakeProducts[$id] ?? null;
                    if (!$product) {
                        continue;
                    }
                    $rows[] = [
                        'ID' => (string) $id,
                        'post_parent' => (string) (int) $product->get_parent_id('edit'),
                    ];
                }
                return $rows;
            }
            if (preg_match(
                '/FROM `wp_wc_product_meta_lookup` WHERE product_id IN \\(([0-9, ]+)\\) ORDER BY product_id ASC LIMIT ([0-9]+)/',
                $query,
                $match
            )) {
                if (!preg_match('/^SELECT (.+?) FROM /', $query, $projectionMatch)
                    || preg_match_all('/`([a-z0-9_]+)`/', $projectionMatch[1], $columnMatch) < 1) {
                    throw new \RuntimeException('fake wpdb could not parse bounded lookup projection');
                }
                $columns = $columnMatch[1];
                $ids = array_map('intval', preg_split('/\\s*,\\s*/', trim($match[1])) ?: []);
                $rows = [];
                foreach ($ids as $id) {
                    if (!isset($fakeMetaLookup[$id])) {
                        continue;
                    }
                    $rows[] = array_map(
                        static fn(string $column) => array_key_exists($column, $fakeMetaLookup[$id])
                            ? ($fakeMetaLookup[$id][$column] === null
                                ? null
                                : (string) $fakeMetaLookup[$id][$column])
                            : null,
                        $columns
                    );
                    $rows[array_key_last($rows)] = array_combine(
                        $columns,
                        $rows[array_key_last($rows)]
                    );
                }
                array_push($rows, ...$this->lookupReceiptExtraRows);
                return array_slice($rows, 0, (int) $match[2]);
            }
            if (str_contains($query, 'FROM information_schema.COLUMNS')
                && str_contains($query, "BINARY TABLE_NAME = BINARY 'wp_wc_product_meta_lookup'")) {
                $rows = $this->lookupSchemaRowsOverride ?? array_map(
                    static fn(string $column, string $type): array => ['Field' => $column, 'Type' => $type],
                    array_keys($this->lookupColumnDeclarations),
                    array_values($this->lookupColumnDeclarations)
                );
                preg_match('/LIMIT ([0-9]+)$/', trim($query), $limit);
                return array_slice($rows, 0, (int) ($limit[1] ?? count($rows)));
            }
            if (preg_match(
                '/FROM `wp_wc_product_attributes_lookup` WHERE product_or_parent_id IN \\(([0-9, ]+)\\)/',
                $query,
                $match
            )) {
                $roots = array_map('intval', preg_split('/\\s*,\\s*/', trim($match[1])) ?: []);
                $rows = array_values(array_filter($fakeAttrLookup, static fn(array $row): bool =>
                    in_array((int) $row['product_or_parent_id'], $roots, true)
                ));
                usort($rows, static fn(array $a, array $b): int => [
                    (int) $a['product_or_parent_id'], (int) $a['product_id'], (string) $a['taxonomy'],
                    (int) $a['term_id'], (int) $a['is_variation_attribute'], (int) $a['in_stock'],
                ] <=> [
                    (int) $b['product_or_parent_id'], (int) $b['product_id'], (string) $b['taxonomy'],
                    (int) $b['term_id'], (int) $b['is_variation_attribute'], (int) $b['in_stock'],
                ]);
                if ($this->attributePayloadDropLast && $rows !== []) {
                    array_pop($rows);
                }
                $result = array_map(
                    static fn(array $row): array => array_map('strval', $row),
                    array_slice($rows, 0, preg_match('/LIMIT ([0-9]+)$/', trim($query), $limit)
                        ? (int) $limit[1]
                        : count($rows))
                );
                if (is_callable($this->afterAttributePayload)) {
                    $callback = $this->afterAttributePayload;
                    $this->afterAttributePayload = null;
                    $callback();
                }
                return $result;
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
                if ($this->nullTransactionStateRead) {
                    return null;
                }
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
            $query = (string) preg_replace_callback(
                "/CAST\\('((?:[^'\\\\]|\\\\.)*)' AS DECIMAL\\(([1-9][0-9]?),([0-9]{1,2})\\)\\)/",
                static fn(array $matches): string => "'"
                    . self::decimal_assignment(stripslashes($matches[1]), (int) $matches[3])
                    . "'",
                $query
            );
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
            // DUO-3342 receipt observation, batched (independent review F7):
            // one COUNT per chunk per table instead of two per id. Modeled as
            // DISTINCT rows touching any id in the set, which is what the SQL
            // does — deliberately not a per-id sum, since that is the
            // double-count the batching removes.
            if (preg_match('/COUNT\\(\\*\\) FROM `?wp_wc_product_meta_lookup`? WHERE product_id IN \\(([0-9, ]+)\\)/', $query, $m)) {
                $wanted = array_map('intval', preg_split('/\\s*,\\s*/', trim($m[1])) ?: []);
                return count(array_filter(
                    array_keys($fakeMetaLookup),
                    static fn($id): bool => in_array((int) $id, $wanted, true)
                ));
            }
            if (preg_match(
                '/COUNT\\(\\*\\) FROM `wp_wc_product_attributes_lookup` WHERE product_or_parent_id IN \\(([0-9, ]+)\\)/',
                $query,
                $m
            )) {
                if ($this->attributeCountOverride !== null) {
                    return $this->attributeCountOverride;
                }
                $wantedParents = array_map('intval', preg_split('/\\s*,\\s*/', trim($m[1])) ?: []);
                return count(array_filter($fakeAttrLookup, static fn(array $row): bool =>
                    in_array((int) $row['product_or_parent_id'], $wantedParents, true)));
            }
            if (preg_match(
                '/COUNT\\(\\*\\) FROM wp_wc_product_attributes_lookup WHERE product_id IN \\(([0-9, ]+)\\) OR product_or_parent_id IN \\(([0-9, ]+)\\)/',
                $query,
                $m
            )) {
                $wanted = array_map('intval', preg_split('/\\s*,\\s*/', trim($m[1])) ?: []);
                $wantedParents = array_map('intval', preg_split('/\\s*,\\s*/', trim($m[2])) ?: []);
                return count(array_filter($fakeAttrLookup, static fn(array $row): bool =>
                    in_array((int) $row['product_id'], $wanted, true)
                    || in_array((int) $row['product_or_parent_id'], $wantedParents, true)));
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

    final class FakeProductDownload {
        public function __construct(
            private string $id,
            private string $name,
            private string $file,
            private bool $enabled
        ) {}
        public function get_id(): string { return $this->id; }
        public function get_name(): string { return $this->name; }
        public function get_file(): string { return $this->file; }
        public function get_enabled(): bool { return $this->enabled; }
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
        public function get_regular_price(string $context = 'view'): string {
            global $fakeMeta;
            return (string) ($fakeMeta[$this->id]['_regular_price'][0] ?? '');
        }
        public function get_sale_price(string $context = 'view'): string {
            global $fakeMeta;
            return (string) ($fakeMeta[$this->id]['_sale_price'][0] ?? '');
        }
        public function is_on_sale(string $context = 'view'): bool {
            global $fakePublicOnSaleOverrides;
            if (array_key_exists($this->id, $fakePublicOnSaleOverrides)) {
                return (bool) $fakePublicOnSaleOverrides[$this->id];
            }
            $regular = $this->get_regular_price('edit');
            $sale = $this->get_sale_price('edit');
            $from = $this->get_date_on_sale_from('edit');
            $to = $this->get_date_on_sale_to('edit');
            $now = time();
            return $sale !== '' && $regular !== '' && (float) $regular > (float) $sale
                && ($from === null || $from->getTimestamp() <= $now)
                && ($to === null || $to->getTimestamp() >= $now);
        }
        public function get_children(): array { return $this->children; }
        public function get_visible_children(): array {
            if ($this->status !== 'publish' || $this->catalogVisibility === 'hidden') {
                return [];
            }
            return $this->visibleChildren;
        }
        public function get_attributes(): array { return $this->attributes; }
        public function get_downloads(): array {
            global $fakeMeta, $fakeNativeDownloadOverrides;
            if (array_key_exists($this->id, $fakeNativeDownloadOverrides)) {
                return $fakeNativeDownloadOverrides[$this->id];
            }
            $raw = maybe_unserialize((string) ($fakeMeta[$this->id]['_downloadable_files'][0] ?? 'a:0:{}'));
            if (!is_array($raw)) {
                return [];
            }
            $register = \Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register::$instance
                ??= new \Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register();
            $out = [];
            foreach ($raw as $id => $row) {
                if (!is_array($row) || !is_string($row['file'] ?? null)) {
                    continue;
                }
                $enabled = $register->mode === $register::MODE_DISABLED
                    || $register->is_valid_path($row['file']);
                $name = (string) ($row['name'] ?? '');
                if ($name === '') {
                    $name = wc_get_filename_from_url($row['file']);
                }
                $out[(string) $id] = new FakeProductDownload((string) $id, $name, $row['file'], $enabled);
            }
            return $out;
        }
        public function is_in_stock(): bool { return $this->stock; }
        public function get_stock_status(string $context = 'view'): string {
            return $this->stock ? 'instock' : 'outofstock';
        }
        public function get_downloadable(string $context = 'view'): bool {
            global $fakeMeta;
            return (string) ($fakeMeta[$this->id]['_downloadable'][0] ?? 'no') === 'yes';
        }
        public function get_featured(string $context = 'view'): bool {
            return in_array('featured', fake_visibility_slugs($this->id, 'product_visibility'), true);
        }
        public function get_average_rating(string $context = 'view'): string {
            global $fakeMeta;
            $value = (string) ($fakeMeta[$this->id]['_wc_average_rating'][0] ?? '');
            return $value === '' ? '0' : $value;
        }
        public function get_date_on_sale_from(string $context = 'view'): ?FakeSaleDate {
            return fake_sale_date($this->id, '_sale_price_dates_from');
        }
        public function get_date_on_sale_to(string $context = 'view'): ?FakeSaleDate {
            return fake_sale_date($this->id, '_sale_price_dates_to');
        }
        public function get_status(): string { return $this->status; }
        public function get_catalog_visibility(string $context = 'view'): string {
            global $fakeVisibilityRelationships;
            if (!array_key_exists($this->id, (array) $fakeVisibilityRelationships)) {
                return $this->catalogVisibility;
            }
            $slugs = fake_visibility_slugs($this->id, 'product_visibility');
            $excludeSearch = in_array('exclude-from-search', $slugs, true);
            $excludeCatalog = in_array('exclude-from-catalog', $slugs, true);
            return $excludeSearch && $excludeCatalog
                ? 'hidden'
                : ($excludeSearch ? 'catalog' : ($excludeCatalog ? 'search' : 'visible'));
        }
        public function set_parent(int $parent): void { $this->parent = $parent; }
        public function set_children(array $children): void { $this->children = $children; }
        public function set_attributes(array $attributes): void { $this->attributes = $attributes; }
        public function set_visible_children(array $children): void { $this->visibleChildren = $children; }
        public function set_status(string $status): void { $this->status = $status; }
        public function set_type(string $type): void { $this->type = $type; }
        public function set_catalog_visibility(string $visibility): void {
            $this->catalogVisibility = $visibility;
            global $fakeVisibilityRelationships;
            if (!array_key_exists($this->id, (array) $fakeVisibilityRelationships)) {
                return;
            }
            $slugs = array_values(array_diff(
                fake_visibility_slugs($this->id, 'product_visibility'),
                ['exclude-from-search', 'exclude-from-catalog']
            ));
            if ($visibility === 'hidden' || $visibility === 'catalog') {
                $slugs[] = 'exclude-from-search';
            }
            if ($visibility === 'hidden' || $visibility === 'search') {
                $slugs[] = 'exclude-from-catalog';
            }
            fake_set_visibility_relationships($this->id, 'product_visibility', $slugs);
        }
        public function set_stock(bool $stock): void { $this->stock = $stock; }
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
            global $fakeMeta, $fakeMetaLookup, $fakeLookupWriteFaults, $fakeLookupCacheSuppressed,
                $fakeVisibilityRaceOnLookupRefresh;
            if (isset($fakeVisibilityRaceOnLookupRefresh[$id])
                && is_callable($fakeVisibilityRaceOnLookupRefresh[$id])) {
                $race = $fakeVisibilityRaceOnLookupRefresh[$id];
                unset($fakeVisibilityRaceOnLookupRefresh[$id]);
                $race();
            }
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
            $cogsController = \Automattic\WooCommerce\Internal\CostOfGoodsSold\CostOfGoodsSoldController::$instance;
            if ($cogsController !== null && $cogsController->enabled && $cogsController->lookupColumnPresent) {
                $rawCogs = (string) ($fakeMeta[$id]['_cogs_total_value'][0] ?? '');
                $derived['cogs_total_value'] = $rawCogs === '' ? null : (float) $rawCogs;
            }
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
            $stored['min_price'] = $first === null
                ? '0.0000'
                : (strlen((string) strrchr((string) $first, '.')) > 5
                    ? FakeWpdb::decimal_assignment($first, 4)
                    : $first);
            $stored['max_price'] = $last === null
                ? '0.0000'
                : (strlen((string) strrchr((string) $last, '.')) > 5
                    ? FakeWpdb::decimal_assignment($last, 4)
                    : $last);
            $stored['average_rating'] = $derived['average_rating'] === '' ? '0.00' : $derived['average_rating'];
            $stored['total_sales'] = $derived['total_sales'] === '' ? '0' : $derived['total_sales'];
            if (array_key_exists('cogs_total_value', $derived) && $derived['cogs_total_value'] !== null) {
                $stored['cogs_total_value'] = FakeWpdb::decimal_assignment($derived['cogs_total_value'], 4);
            }
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
            \delete_post_meta((int) $product->get_id(), '_regular_price');
            \delete_post_meta((int) $product->get_id(), '_sale_price');
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
            \delete_post_meta((int) $product->get_id(), '_regular_price');
            \delete_post_meta((int) $product->get_id(), '_sale_price');
            if (($fakeSyncFailures['grouped'][$product->get_id()] ?? 0) > 0) {
                $fakeSyncFailures['grouped'][$product->get_id()]--;
                throw new \RuntimeException('simulated grouped sync failure');
            }
        }
    }

    final class FakeContainer {
        public function get(string $class): object {
            $class = '\\' . ltrim($class, '\\');
            if ($class === '\\Automattic\\WooCommerce\\Internal\\CostOfGoodsSold\\CostOfGoodsSoldController') {
                return \Automattic\WooCommerce\Internal\CostOfGoodsSold\CostOfGoodsSoldController::$instance
                    ??= new \Automattic\WooCommerce\Internal\CostOfGoodsSold\CostOfGoodsSoldController();
            }
            if ($class === '\\Automattic\\WooCommerce\\Internal\\Caches\\ProductCache') {
                return new \Automattic\WooCommerce\Internal\Caches\ProductCache();
            }
            if ($class === '\\Automattic\\WooCommerce\\Internal\\ProductDownloads\\ApprovedDirectories\\Register') {
                return \Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register::$instance
                    ??= new \Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register();
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
    $fakeVisibilityTerms = ['product_type' => [], 'product_visibility' => [], 'pos_product_visibility' => []];
    foreach (['simple', 'grouped', 'variable', 'external'] as $offset => $slug) {
        $fakeVisibilityTerms['product_type'][$slug] = [
            'term_id' => 181 + $offset,
            'term_taxonomy_id' => 281 + $offset,
            'taxonomy' => 'product_type',
            'slug' => $slug,
            'name' => $slug,
        ];
    }
    foreach ([
        'exclude-from-search', 'exclude-from-catalog', 'featured', 'outofstock',
        'rated-1', 'rated-2', 'rated-3', 'rated-4', 'rated-5',
    ] as $offset => $slug) {
        $fakeVisibilityTerms['product_visibility'][$slug] = [
            'term_id' => 201 + $offset,
            'term_taxonomy_id' => 301 + $offset,
            'taxonomy' => 'product_visibility',
            'slug' => $slug,
            'name' => $slug,
        ];
    }
    $fakeVisibilityTerms['pos_product_visibility']['pos-hidden'] = [
        'term_id' => 220,
        'term_taxonomy_id' => 320,
        'taxonomy' => 'pos_product_visibility',
        'slug' => 'pos-hidden',
        'name' => 'pos-hidden',
    ];
    $fakeVisibilityRelationships = [];
    foreach ($fakeProducts as $id => $product) {
        $fakeVisibilityRelationships[$id] = [
            'product_type' => [],
            'product_visibility' => [],
            'pos_product_visibility' => [],
        ];
        if ($product->get_type() !== 'variation') {
            fake_set_visibility_relationships((int) $id, 'product_type', [$product->get_type()]);
        }
    }
    fake_set_visibility_relationships(30, 'product_visibility', [
        'exclude-from-search',
        'exclude-from-catalog',
    ]);
    $fakeVisibilityWrites = [];
    $fakeVisibilityWriteFailures = [];
    $fakeVisibilityPartialWrites = [];
    $fakeVisibilityPostWriteMutations = [];
    $fakeVisibilityPreWriteMutations = [];
    $fakeVisibilityNativeEvents = [];
    $fakeVisibilityQueries = [];
    $fakeVisibilityChildFlood = null;
    $fakeVisibilityRaceOnLookupRefresh = [];
    // wc_get_product() below returns a cached clone.  Mutating $fakeProducts
    // therefore leaves a deliberately stale Woo object in this cache until
    // ProductCache::remove() invalidates it, just like Woo's product factory.
    $fakeProductCache = [];
    $fakePostTypeOverrides = [];
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
    $fakeAttrLookup = [[
        'product_id' => 999,
        'product_or_parent_id' => 999,
        'taxonomy' => 'pa_external',
        'term_id' => 777,
        'is_variation_attribute' => 0,
        'in_stock' => 1,
    ]];
    $unrelatedAttributeRow = $fakeAttrLookup[0];
    $wpdb = new FakeWpdb();
    $fakeRegisteredTaxonomies = [];
    /** @var list<array{taxonomy:string,object_types:list<string>,args:array}> */
    $fakeTaxonomyRegistrations = [];
    $fakeDeletedTransients = [];
    $fakeWpCache = [];
    $fakeLookupWriteFaults = [];
    $fakeLookupCacheSuppressed = [];
    $fakePublicOnSaleOverrides = [];
    $fakeWpCacheDeletes = [];
    $fakeCacheHooks = [];
    $fakeGroupedChildren = [];
    $fakeSyncFailures = ['variable' => [], 'grouped' => []];
    $fakeMetaRestoreFailures = [];
    $fakeDownloadMetaRows = [];
    $fakeCogsMetaRows = [];
    $fakeNativeDownloadOverrides = [];
    $fakeSaleSchedules = [
        'wc_product_start_scheduled_sale' => [],
        'wc_product_end_scheduled_sale' => [],
    ];
    $fakeSaleScheduleDuplicates = [
        'wc_product_start_scheduled_sale' => [],
        'wc_product_end_scheduled_sale' => [],
    ];
    $fakeSaleScheduleCalls = [];
    $fakeSaleUnscheduleCalls = [];
    $fakeSaleVerificationFailure = false;
    $fakeFilters = [];
    /** @var list<array{hook:string,value:mixed,args:list<mixed>}> */
    $fakeApplyFilterCalls = [];
    $fakeOptions = [
        'woocommerce_permalinks' => ['attribute_base' => 'attribute'],
        'woocommerce_schema_version' => 1000,
    ];
    $fakeOptionReads = [];
    $fakeTermQueries = [];

    function wc_get_product($id = false) {
        global $fakeProducts, $fakeProductCache, $fakeCacheEvents, $fakeMeta;
        $id = (int) $id;
        if (isset($fakeProductCache[$id])) {
            $fakeCacheEvents[] = "read:$id:cached";
            return $fakeProductCache[$id];
        }
        if (!isset($fakeProducts[$id])) {
            // High-cardinality bound fixtures intentionally enumerate 50,000
            // absent descendants; retaining one diagnostic string per miss
            // would make the fake's log, rather than the provider witness,
            // exceed the 128 MiB test budget.
            if ($id < 70000 || $id > 122000) {
                $fakeCacheEvents[] = "read:$id:missing";
            }
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
    /** @return list<string> */
    function fake_visibility_slugs(int $id, string $taxonomy): array {
        global $fakeVisibilityRelationships;
        $slugs = array_map(
            static fn(array $row): string => (string) ($row['slug'] ?? ''),
            (array) ($fakeVisibilityRelationships[$id][$taxonomy] ?? [])
        );
        sort($slugs, SORT_STRING);
        return $slugs;
    }
    /** @param list<string> $slugs */
    function fake_set_visibility_relationships(int $id, string $taxonomy, array $slugs): array {
        global $fakeVisibilityRelationships, $fakeVisibilityTerms;
        $rows = [];
        foreach (array_values(array_unique($slugs)) as $slug) {
            $term = $fakeVisibilityTerms[$taxonomy][$slug] ?? null;
            if (!is_array($term)) {
                throw new \RuntimeException("fake visibility taxonomy $taxonomy has no term $slug");
            }
            $rows[] = [
                'term_taxonomy_id' => $term['term_taxonomy_id'],
                'term_order' => 0,
                'term_id' => $term['term_id'],
                'taxonomy' => $taxonomy,
                'slug' => $slug,
                'name' => $term['name'],
            ];
        }
        usort($rows, static fn(array $left, array $right): int => [
            $left['slug'], $left['term_taxonomy_id'],
        ] <=> [
            $right['slug'], $right['term_taxonomy_id'],
        ]);
        $fakeVisibilityRelationships[$id] ??= [
            'product_type' => [],
            'product_visibility' => [],
            'pos_product_visibility' => [],
        ];
        $fakeVisibilityRelationships[$id][$taxonomy] = $rows;
        return array_map(static fn(array $row): int => (int) $row['term_taxonomy_id'], $rows);
    }
    /** @param list<int> $children */
    function fake_add_visibility_product(
        int $id,
        string $type,
        int $parent = 0,
        array $children = [],
        bool $inStock = true,
        bool $downloadable = false,
        string $averageRating = '0'
    ): void {
        global $fakeProducts, $fakeMeta, $fakeMetaLookup, $fakeVisibilityRelationships;
        $fakeProducts[$id] = new FakeProduct($id, $type, $parent, $children, [], $inStock);
        $fakeMeta[$id] = [
            '_price' => ['21'],
            '_regular_price' => ['21'],
            '_sale_price' => [''],
            '_sale_price_dates_from' => [''],
            '_sale_price_dates_to' => [''],
            '_stock_status' => [$inStock ? 'instock' : 'outofstock'],
            '_manage_stock' => ['yes'],
            '_stock' => [$inStock ? '5' : '0'],
            '_tax_status' => ['taxable'],
            '_tax_class' => [''],
            '_wc_rating_count' => [[]],
            '_sku' => [''],
            '_virtual' => ['no'],
            '_downloadable' => [$downloadable ? 'yes' : 'no'],
            'total_sales' => ['0'],
            '_wc_average_rating' => [$averageRating],
            '_global_unique_id' => [''],
        ];
        $fakeMetaLookup[$id] = [
            'product_id' => $id,
            'sku' => '',
            'virtual' => 0,
            'downloadable' => $downloadable ? 1 : 0,
            'min_price' => '999',
            'max_price' => '999',
            'onsale' => 0,
            'stock_quantity' => $inStock ? 5 : 0,
            'stock_status' => $inStock ? 'instock' : 'outofstock',
            'rating_count' => 0,
            'average_rating' => $averageRating,
            'total_sales' => '0',
            'tax_status' => 'taxable',
            'tax_class' => '',
            'global_unique_id' => '',
        ];
        $fakeVisibilityRelationships[$id] = [
            'product_type' => [],
            'product_visibility' => [],
            'pos_product_visibility' => [],
        ];
        if ($type !== 'variation') {
            fake_set_visibility_relationships($id, 'product_type', [$type]);
        }
    }
    function fake_visibility_pre_write(int $id, string $taxonomy, string $operation): void {
        global $fakeVisibilityPreWriteMutations;
        $key = "$id:$taxonomy:$operation";
        if (isset($fakeVisibilityPreWriteMutations[$key])
            && is_callable($fakeVisibilityPreWriteMutations[$key])) {
            $mutation = $fakeVisibilityPreWriteMutations[$key];
            unset($fakeVisibilityPreWriteMutations[$key]);
            $mutation();
        }
    }
    function wp_remove_object_terms(int $id, array $termIds, string $taxonomy): bool {
        global $fakeVisibilityTerms, $fakeVisibilityWrites, $fakeVisibilityWriteFailures,
            $fakeVisibilityNativeEvents, $fakeVisibilityRelationships;
        $key = "$id:$taxonomy";
        fake_visibility_pre_write($id, $taxonomy, 'remove');
        $fakeVisibilityWrites[] = [
            'id' => $id,
            'taxonomy' => $taxonomy,
            'operation' => 'remove',
            'term_ids' => $termIds,
        ];
        if (($fakeVisibilityWriteFailures[$key] ?? 0) > 0) {
            $fakeVisibilityWriteFailures[$key]--;
            return false;
        }
        $remove = array_map('intval', $termIds);
        $remaining = [];
        foreach ((array) ($fakeVisibilityRelationships[$id][$taxonomy] ?? []) as $row) {
            if (!in_array((int) ($row['term_id'] ?? 0), $remove, true)) {
                $remaining[] = (string) ($row['slug'] ?? '');
            }
        }
        fake_set_visibility_relationships($id, $taxonomy, $remaining);
        $fakeVisibilityNativeEvents[] = "remove:$key";
        return true;
    }
    function wp_add_object_terms(int $id, array $termIds, string $taxonomy): array|false {
        global $fakeVisibilityTerms, $fakeVisibilityWrites, $fakeVisibilityWriteFailures,
            $fakeVisibilityPartialWrites, $fakeVisibilityPostWriteMutations, $fakeVisibilityNativeEvents;
        $key = "$id:$taxonomy";
        fake_visibility_pre_write($id, $taxonomy, 'add');
        $fakeVisibilityWrites[] = [
            'id' => $id,
            'taxonomy' => $taxonomy,
            'operation' => 'add',
            'term_ids' => $termIds,
        ];
        if (($fakeVisibilityWriteFailures[$key] ?? 0) > 0) {
            $fakeVisibilityWriteFailures[$key]--;
            return false;
        }
        $slugsById = [];
        $ttById = [];
        foreach ((array) ($fakeVisibilityTerms[$taxonomy] ?? []) as $slug => $term) {
            $termId = (int) $term['term_id'];
            $slugsById[$termId] = (string) $slug;
            $ttById[$termId] = (int) $term['term_taxonomy_id'];
        }
        $selected = [];
        foreach ($termIds as $termId) {
            $termId = (int) $termId;
            if (!isset($slugsById[$termId])) {
                return false;
            }
            $selected[$termId] = $slugsById[$termId];
        }
        if (($fakeVisibilityPartialWrites[$key] ?? 0) > 0) {
            $fakeVisibilityPartialWrites[$key]--;
            $selected = array_slice($selected, 0, max(0, count($selected) - 1), true);
        }
        $slugs = array_values(array_unique(array_merge(
            fake_visibility_slugs($id, $taxonomy),
            array_values($selected)
        )));
        fake_set_visibility_relationships($id, $taxonomy, $slugs);
        $fakeVisibilityNativeEvents[] = "add:$key";
        if (isset($fakeVisibilityPostWriteMutations[$key])
            && is_callable($fakeVisibilityPostWriteMutations[$key])) {
            $mutation = $fakeVisibilityPostWriteMutations[$key];
            unset($fakeVisibilityPostWriteMutations[$key]);
            $mutation();
        }
        return array_values(array_map(static fn(int $termId): int => $ttById[$termId], array_keys($selected)));
    }
    function wc_get_filename_from_url(string $file): string {
        $path = (string) (parse_url($file, PHP_URL_PATH) ?? '');
        return basename($path);
    }
    function fake_sale_date(int $id, string $key): ?FakeSaleDate {
        global $fakeMeta;
        $value = (string) ($fakeMeta[$id][$key][0] ?? '');
        return $value === '' || (int) $value <= 0 ? null : new FakeSaleDate((int) $value);
    }
    function wc_maybe_schedule_product_sale_events($id, $product = null): void {
        global $fakeSaleSchedules, $fakeSaleScheduleCalls, $fakeSaleScheduleDuplicates;
        $id = (int) $id;
        $fakeSaleScheduleCalls[] = $id;
        foreach (array_keys($fakeSaleSchedules) as $hook) {
            unset($fakeSaleSchedules[$hook][$id]);
            unset($fakeSaleScheduleDuplicates[$hook][$id]);
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
        global $fakeSaleSchedules, $fakeSaleUnscheduleCalls, $fakeSaleScheduleDuplicates;
        $id = (int) ($args['product_id'] ?? 0);
        $fakeSaleUnscheduleCalls[] = $id;
        $removed = isset($fakeSaleSchedules[$hook][$id]) ? 1 : 0;
        $removed += (int) ($fakeSaleScheduleDuplicates[$hook][$id] ?? 0);
        unset($fakeSaleSchedules[$hook][$id]);
        unset($fakeSaleScheduleDuplicates[$hook][$id]);
        return $removed;
    }
    function as_get_scheduled_actions(array $query = [], string $returnFormat = 'OBJECT'): array {
        global $fakeSaleSchedules, $fakeSaleScheduleDuplicates;
        if ($returnFormat !== 'ids'
            || ($query['group'] ?? '') !== 'woocommerce-sales'
            || ($query['status'] ?? '') !== 'pending') {
            return [];
        }
        $hook = (string) ($query['hook'] ?? '');
        $id = (int) (($query['args']['product_id'] ?? 0));
        if (!isset($fakeSaleSchedules[$hook][$id])) {
            return [];
        }
        $base = $id * 1000 + ($hook === 'wc_product_start_scheduled_sale' ? 100 : 200);
        $ids = [$base];
        $duplicates = (int) ($fakeSaleScheduleDuplicates[$hook][$id] ?? 0);
        for ($index = 1; $index <= $duplicates; $index++) {
            $ids[] = $base + $index;
        }
        return array_slice($ids, 0, (int) ($query['per_page'] ?? 5));
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
            // The two values deliberately exercise both sides of Woo's
            // attribute_public branch. The first is a private attribute: a
            // same-request repair must not silently widen it to a public,
            // queryable, rewrite-enabled taxonomy.
            (object) [
                'attribute_id' => 7,
                'attribute_name' => 'grind-size',
                'attribute_label' => 'Grind Size',
                'attribute_public' => 0,
            ],
            (object) [
                'attribute_id' => 8,
                'attribute_name' => 'color',
                'attribute_label' => 'Color',
                'attribute_public' => 1,
            ],
            // Woo treats legacy rows that predate attribute_public as public.
            // The late-registration mirror must retain that exact default.
            (object) [
                'attribute_id' => 9,
                'attribute_name' => 'legacy',
                'attribute_label' => 'Legacy',
            ],
            // Woo 11 measures the taxonomy-name limit in bytes and permits
            // multibyte slugs. Keep this private so the fake's deliberately
            // small sanitize_title() does not stand in for WordPress's UTF-8
            // rewrite implementation; the dynamic filter names are the seam.
            (object) [
                'attribute_id' => 10,
                'attribute_name' => '尺寸',
                'attribute_label' => '尺寸',
                'attribute_public' => 0,
            ],
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
        global $fakeRegisteredTaxonomies, $fakeTaxonomyRegistrations;
        $fakeRegisteredTaxonomies[] = $taxonomy;
        $fakeTaxonomyRegistrations[] = [
            'taxonomy' => $taxonomy,
            'object_types' => array_values($objectTypes),
            'args' => $args,
        ];
        return (object) ['name' => $taxonomy, 'object_type' => $objectTypes, 'args' => $args];
    }
    function wc_get_container(): FakeContainer { return new FakeContainer(); }
    function get_post_meta(int $id, string $key, bool $single = true) {
        global $fakeMeta;
        $values = $fakeMeta[$id][$key] ?? [];
        return $single ? ($values[0] ?? '') : $values;
    }
    function maybe_unserialize($value) {
        if (!is_string($value)) {
            return $value;
        }
        $decoded = @unserialize(trim($value));
        return $decoded === false && trim($value) !== 'b:0;' ? $value : $decoded;
    }
    function delete_post_meta(int $id, string $key): bool {
        global $fakeMeta;
        $check = apply_filters('delete_post_metadata', null, $id, $key, null, false);
        if ($check !== null) {
            return (bool) $check;
        }
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
        foreach (['product_type', 'product_visibility', 'pos_product_visibility'] as $taxonomy) {
            wp_cache_delete((string) $id, $taxonomy . '_relationships');
        }
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
    function apply_filters(string $hook, mixed $value, mixed ...$args): mixed {
        global $fakeFilters, $fakeApplyFilterCalls;
        $fakeApplyFilterCalls[] = ['hook' => $hook, 'value' => $value, 'args' => $args];
        $filters = $fakeFilters[$hook] ?? [];
        ksort($filters, SORT_NUMERIC);
        foreach ($filters as $callbacks) {
            foreach ($callbacks as [$callback, $acceptedArgs]) {
                $callArgs = array_slice(array_merge([$value], $args), 0, (int) $acceptedArgs);
                $value = $callback(...$callArgs);
            }
        }
        return $value;
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
    function absint($value): int { return abs((int) $value); }
    function __(string $text, string $domain = ''): string { return $text; }
    function _x(string $text, string $context, string $domain = ''): string { return $text; }
    function sanitize_title(string $title): string {
        $title = strtolower(trim($title));
        $title = (string) preg_replace('/[^a-z0-9]+/', '-', $title);
        return trim($title, '-');
    }
    function trailingslashit(string $value): string { return rtrim($value, '/') . '/'; }
    function untrailingslashit(string $value): string { return rtrim($value, '/\\'); }
    function wp_parse_args(array $args, array $defaults = []): array {
        return array_merge($defaults, $args);
    }
    function is_wp_error($value): bool { return false; }
    function get_option(string $key, $default = false) {
        global $fakeOptions, $fakeOptionReads;
        $fakeOptionReads[] = $key;
        $pre = apply_filters("pre_option_{$key}", false, $key, $default);
        $pre = apply_filters('pre_option', $pre, $key, $default);
        if ($pre !== false) {
            return $pre;
        }
        if (!array_key_exists($key, $fakeOptions)) {
            return apply_filters("default_option_{$key}", $default, $key, true);
        }
        return apply_filters("option_{$key}", $fakeOptions[$key], $key);
    }

    require dirname(__DIR__, 4) . '/agent/src/Kernel/Canon.php';
    require dirname(__DIR__, 4) . '/agent/src/Kernel/PlainData.php';
    require dirname(__DIR__, 4) . '/agent/src/Kernel/OptionState.php';
    require dirname(__DIR__, 4) . '/agent/src/Policy/Policy.php';
    require dirname(__DIR__, 4) . '/agent/src/Adapter/ProviderSdk.php';
    // invoke() reads the engine's reserved batch-argument name from the
    // contract itself rather than restating the literal.
    require dirname(__DIR__, 4) . '/agent/src/Adapter/Providers.php';
    require dirname(__DIR__, 4) . '/manifests/providers/woocommerce-product-lookups.php';

    $manifest = json_decode(
        (string) file_get_contents(dirname(__DIR__, 4) . '/manifests/woocommerce.json'),
        true,
        512,
        JSON_THROW_ON_ERROR
    );
    $productProviderDeclaration = null;
    foreach ((array) ($manifest['providers'] ?? []) as $providerDeclaration) {
        if (is_array($providerDeclaration)
            && ($providerDeclaration['id'] ?? null) === 'woocommerce-product-lookups') {
            $productProviderDeclaration = $providerDeclaration;
            break;
        }
    }
    if (!is_array($productProviderDeclaration)) {
        throw new \RuntimeException('WooCommerce product lookup provider declaration is absent');
    }
    $adapter = new \Duo\Providers\WoocommerceProductLookups($productProviderDeclaration);
    // These are the real WordPress/WooCommerce filter seams. Keep the args
    // filters identity-preserving so the registration assertions below pin
    // Woo's exact defaults; make the public object filter visibly change its
    // result so the fake proves the provider passes filtered object types to
    // register_taxonomy(), rather than merely mentioning the hook in source.
    add_filter('woocommerce_taxonomy_objects_pa_grind-size',
        static fn(array $objectTypes): array => $objectTypes, 10, 1);
    add_filter('woocommerce_taxonomy_objects_pa_color',
        static fn(array $objectTypes): array => ['product', 'product_variation'], 10, 1);
    add_filter('woocommerce_taxonomy_args_pa_grind-size',
        static fn(array $args): array => $args, 10, 1);
    add_filter('woocommerce_taxonomy_args_pa_color',
        static fn(array $args): array => $args, 10, 1);
    add_filter('woocommerce_taxonomy_objects_pa_尺寸',
        static fn(array $objectTypes): array => $objectTypes, 10, 1);
    add_filter('woocommerce_taxonomy_args_pa_尺寸',
        static fn(array $args): array => $args, 10, 1);
    add_filter('woocommerce_attribute_show_in_nav_menus',
        static fn(bool $default, string $taxonomy): bool => true, 10, 2);
    // Prime a product read before the registry refresh. The adapter must evict
    // this stale parsed object after registering the newly-applied taxonomy.
    wc_get_product(15);
    $adapter->regenerate_batch([10, 11, 12], []);

    $failures = 0;
    $check = static function (bool $condition, string $message) use (&$failures): void {
        echo ($condition ? 'ok: ' : 'FAIL: ') . $message . "\n";
        if (!$condition) { $failures++; }
    };

    // Woo's product_visibility taxonomy is a mixed projection: featured and
    // catalog exclusions are merchant intent, while stock/rating terms must
    // be regenerated from the target's freshly loaded product. POS visibility
    // is merchant intent on supported roots and a strict inherited projection
    // on every variation. Exercise the full native writer/readback boundary
    // before the older lookup scenarios mutate their shared fixtures.
    $variableCallsBeforeVisibility = WC_Data_Store::$variable?->calls ?? 0;
    fake_add_visibility_product(90, 'variable', 0, [91, 92], false, false, '4.6');
    fake_add_visibility_product(91, 'variation', 90, [], false);
    fake_add_visibility_product(92, 'variation', 90, [], true);
    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'rated-1',
    ]);
    fake_set_visibility_relationships(90, 'pos_product_visibility', ['pos-hidden']);
    fake_set_visibility_relationships(91, 'product_visibility', ['featured', 'rated-3']);
    fake_set_visibility_relationships(92, 'product_visibility', ['outofstock']);
    $visibilityWriteStart = count($fakeVisibilityWrites);
    $adapter->regenerate_batch([90], []);
    $check(fake_visibility_slugs(90, 'product_visibility') === [
        'exclude-from-search', 'featured', 'outofstock', 'rated-5',
    ], 'mixed product visibility preserves featured/catalog intent and repairs exact stock/rating terms');
    $check(fake_visibility_slugs(91, 'product_visibility') === ['outofstock']
        && fake_visibility_slugs(92, 'product_visibility') === [],
        'variation visibility removes forbidden root terms and projects only each target stock state');
    $check(fake_visibility_slugs(90, 'pos_product_visibility') === ['pos-hidden']
        && fake_visibility_slugs(91, 'pos_product_visibility') === ['pos-hidden']
        && fake_visibility_slugs(92, 'pos_product_visibility') === ['pos-hidden'],
        'POS hidden intent is written through the native taxonomy API and inherited by every variation');
    $visibleRoot = wc_get_product(90);
    $check($visibleRoot instanceof FakeProduct
        && $visibleRoot->get_featured('edit') === true
        && $visibleRoot->get_catalog_visibility('edit') === 'catalog'
        && $visibleRoot->get_stock_status('edit') === 'outofstock',
        'fresh WC CRUD readback agrees with the exact raw mixed visibility projection');
    $visibilityWritesAfterRepair = count($fakeVisibilityWrites);
    $adapter->regenerate_batch([90], []);
    $check(count($fakeVisibilityWrites) === $visibilityWritesAfterRepair
        && $visibilityWritesAfterRepair > $visibilityWriteStart,
        'replaying an exact mixed visibility projection is idempotent and performs no taxonomy write');

    $visibilityScopeArgs = [
        'entities' => [
            'entities' => [['kind' => 'post:product', 'id' => 90]],
            'always_on_write' => true,
            'deletions' => [],
            'reparents' => [],
            'retry' => true,
        ],
    ];
    $visibilityOperation = ['fixture' => 'woocommerce-visibility-recovery'];
    $visibilityScoped = $adapter->invoke_scoped(
        'rebuild_product_lookups',
        $visibilityScopeArgs,
        $visibilityOperation
    );
    $visibilityReceiptBytes = serialize($visibilityScoped['after']);
    $check(($visibilityScoped['after']['visibility_products'] ?? null) === 3
        && ($visibilityScoped['after']['visibility_relationships'] ?? null) === 9
        && preg_match(
            '/^[a-f0-9]{64}$/D',
            (string) ($visibilityScoped['after']['visibility_intent_sha256'] ?? '')
        ) === 1
        && preg_match(
            '/^[a-f0-9]{64}$/D',
            (string) ($visibilityScoped['after']['visibility_scope_sha256'] ?? '')
        ) === 1
        && !str_contains($visibilityReceiptBytes, 'featured')
        && !str_contains($visibilityReceiptBytes, 'pos-hidden'),
        'scoped receipts bind exact visibility intent/raw/native state with bounded non-disclosing digests');
    $exactRootVisibility = $fakeVisibilityRelationships[90]['product_visibility'];
    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'outofstock', 'rated-4',
    ]);
    $sameCountVisibilityDrift = false;
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $visibilityScopeArgs, $visibilityOperation);
    } catch (\Throwable $failure) {
        $sameCountVisibilityDrift = str_contains($failure->getMessage(), 'product_visibility projection disagrees');
    }
    $check($sameCountVisibilityDrift,
        'same-count rated-term drift cannot verify or retire the scoped visibility receipt');
    $fakeVisibilityRelationships[90]['product_visibility'] = $exactRootVisibility;
    $visibilityRecovered = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $visibilityScopeArgs,
        $visibilityOperation
    );
    $check(($visibilityRecovered['after'] ?? null) === ($visibilityScoped['after'] ?? null),
        'restoring exact raw/native visibility state deterministically recovers the scoped receipt');

    $wrongIdentityRow = $fakeVisibilityRelationships[90]['product_visibility'][0];
    $fakeVisibilityRelationships[90]['product_visibility'][0]['term_id'] = 999999;
    $wrongIdentityFailure = false;
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $visibilityScopeArgs, $visibilityOperation);
    } catch (\Throwable $failure) {
        $wrongIdentityFailure = str_contains($failure->getMessage(), 'product_visibility projection disagrees');
    }
    $fakeVisibilityRelationships[90]['product_visibility'][0] = $wrongIdentityRow;
    $check($wrongIdentityFailure,
        'a no-op-path relationship with the right slug but wrong native term identity cannot verify');

    $overflowIdentityRow = $fakeVisibilityRelationships[90]['product_visibility'][0];
    $fakeVisibilityRelationships[90]['product_visibility'][0]['term_id'] = (string) PHP_INT_MAX . '0';
    $overflowIdentityFailure = false;
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $visibilityScopeArgs, $visibilityOperation);
    } catch (\Throwable $failure) {
        $overflowIdentityFailure = str_contains($failure->getMessage(), 'noncanonical term ID');
    }
    $fakeVisibilityRelationships[90]['product_visibility'][0] = $overflowIdentityRow;
    $check($overflowIdentityFailure,
        'overflowing database identities refuse before PHP integer saturation can alias another term');

    $fakeVisibilityTerms['product_visibility']['duplicate-featured'] = [
        'term_id' => 999998,
        'term_taxonomy_id' => 999997,
        'taxonomy' => 'product_visibility',
        'slug' => 'featured',
        'name' => 'featured',
    ];
    $duplicateCoreTermFailure = false;
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $visibilityScopeArgs, $visibilityOperation);
    } catch (\Throwable $failure) {
        $duplicateCoreTermFailure = str_contains($failure->getMessage(), 'inventory is malformed or duplicated')
            || str_contains($failure->getMessage(), 'exact core cardinality');
    }
    unset($fakeVisibilityTerms['product_visibility']['duplicate-featured']);
    $check($duplicateCoreTermFailure,
        'a duplicate native core term is rejected even when every relationship already looks exact');

    $fakeVisibilityTerms['product_visibility']['extension-private'] = [
        'term_id' => 999996,
        'term_taxonomy_id' => 999995,
        'taxonomy' => 'product_visibility',
        'slug' => 'extension-private',
        'name' => 'extension-private',
    ];
    $extraProductVisibilityFailure = false;
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $visibilityScopeArgs, $visibilityOperation);
    } catch (\Throwable $failure) {
        $extraProductVisibilityFailure = str_contains($failure->getMessage(), 'exact core cardinality')
            || str_contains($failure->getMessage(), 'malformed or duplicated');
    }
    unset($fakeVisibilityTerms['product_visibility']['extension-private']);
    $check($extraProductVisibilityFailure,
        'a tenth target-wide product_visibility term cannot hide outside the nine-slug core filter');

    $fakeVisibilityTerms['pos_product_visibility']['extension-private'] = [
        'term_id' => 999994,
        'term_taxonomy_id' => 999993,
        'taxonomy' => 'pos_product_visibility',
        'slug' => 'extension-private',
        'name' => 'extension-private',
    ];
    $extraPosVisibilityFailure = false;
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $visibilityScopeArgs, $visibilityOperation);
    } catch (\Throwable $failure) {
        $extraPosVisibilityFailure = str_contains($failure->getMessage(), 'exact core cardinality')
            || str_contains($failure->getMessage(), 'malformed or duplicated');
    }
    unset($fakeVisibilityTerms['pos_product_visibility']['extension-private']);
    $check($extraPosVisibilityFailure,
        'POS visibility inventory admits only absence or the one exact core pos-hidden identity');

    $exactRootType = $fakeVisibilityRelationships[90]['product_type'];
    fake_set_visibility_relationships(90, 'product_type', []);
    $missingProductTypeFailure = false;
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $missingProductTypeFailure = str_contains($failure->getMessage(), 'exactly one native product_type');
    }
    $fakeVisibilityRelationships[90]['product_type'] = $exactRootType;
    $check($missingProductTypeFailure,
        'a product missing its authored native product_type refuses before derived mutation');

    fake_set_visibility_relationships(90, 'product_type', ['variable', 'simple']);
    $multipleProductTypeFailure = false;
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $multipleProductTypeFailure = str_contains($failure->getMessage(), 'exactly one native product_type');
    }
    $fakeVisibilityRelationships[90]['product_type'] = $exactRootType;
    $check($multipleProductTypeFailure,
        'multiple product_type relationships cannot select a plausible native subtype');

    $wrongProductTypeIdentity = $fakeVisibilityRelationships[90]['product_type'][0];
    $fakeVisibilityRelationships[90]['product_type'][0]['term_id'] = 999992;
    $wrongProductTypeIdentityFailure = false;
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $wrongProductTypeIdentityFailure = str_contains($failure->getMessage(), 'product_type identity');
    }
    $fakeVisibilityRelationships[90]['product_type'][0] = $wrongProductTypeIdentity;
    $check($wrongProductTypeIdentityFailure,
        'the right product_type slug with a wrong native term identity cannot verify');

    $savedExternalType = $fakeVisibilityTerms['product_type']['external'];
    unset($fakeVisibilityTerms['product_type']['external']);
    $missingTypeInventoryFailure = false;
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $missingTypeInventoryFailure = str_contains($failure->getMessage(), 'product_type term inventory is incomplete');
    }
    $fakeVisibilityTerms['product_type']['external'] = $savedExternalType;
    $check($missingTypeInventoryFailure,
        'the exact four-term core product_type inventory is required even on a no-op root');

    fake_set_visibility_relationships(91, 'product_type', ['simple']);
    $variationTypeFailure = false;
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $variationTypeFailure = str_contains($failure->getMessage(), 'impossible product_type relationship');
    }
    fake_set_visibility_relationships(91, 'product_type', []);
    $check($variationTypeFailure,
        'a variation cannot carry a product_type relationship of its own');

    fake_add_visibility_product(199, 'variation', 13);
    $nonVariableParentFailure = false;
    try {
        $adapter->regenerate_batch([199], []);
    } catch (\Throwable $failure) {
        $nonVariableParentFailure = str_contains($failure->getMessage(), 'exact variable product_type parent');
    }
    unset($fakeProducts[199], $fakeMeta[199], $fakeMetaLookup[199], $fakeVisibilityRelationships[199]);
    $check($nonVariableParentFailure,
        'a variation parent must resolve to the exact authored variable product_type');

    // Removing root POS intent must remove every inherited child term. The
    // supported simple case remains portable; downloadable and external roots
    // are normal native exclusion cases and cannot retain a stale pos-hidden.
    fake_set_visibility_relationships(90, 'pos_product_visibility', []);
    $adapter->regenerate_batch([90], []);
    $check(fake_visibility_slugs(90, 'pos_product_visibility') === []
        && fake_visibility_slugs(91, 'pos_product_visibility') === []
        && fake_visibility_slugs(92, 'pos_product_visibility') === [],
        'POS visible intent removes the root term and every inherited variation term');
    $savedPosHidden = $fakeVisibilityTerms['pos_product_visibility']['pos-hidden'];
    unset($fakeVisibilityTerms['pos_product_visibility']['pos-hidden']);
    $adapter->regenerate_batch([90], []);
    $check(true, 'an unused POS taxonomy may have no term before the first native pos-hidden write');
    $fakeVisibilityTerms['pos_product_visibility']['pos-hidden'] = $savedPosHidden;
    fake_set_visibility_relationships(90, 'pos_product_visibility', ['pos-hidden']);
    unset($fakeVisibilityTerms['pos_product_visibility']['pos-hidden']);
    $missingReferencedPosTerm = false;
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $missingReferencedPosTerm = str_contains($failure->getMessage(), 'native pos-hidden term is absent');
    }
    $fakeVisibilityTerms['pos_product_visibility']['pos-hidden'] = $savedPosHidden;
    $check($missingReferencedPosTerm,
        'a referenced pos-hidden relationship requires the one exact native POS term identity');
    fake_set_visibility_relationships(90, 'pos_product_visibility', []);
    fake_add_visibility_product(93, 'simple');
    fake_set_visibility_relationships(93, 'pos_product_visibility', ['pos-hidden']);
    $adapter->regenerate_batch([93], []);
    $check(fake_visibility_slugs(93, 'pos_product_visibility') === ['pos-hidden'],
        'non-downloadable simple products preserve authored POS hidden intent');
    fake_add_visibility_product(94, 'simple', 0, [], true, true);
    fake_set_visibility_relationships(94, 'pos_product_visibility', ['pos-hidden']);
    $adapter->regenerate_batch([94], []);
    $check(fake_visibility_slugs(94, 'pos_product_visibility') === [],
        'downloadable products cannot retain a stale POS-hidden relationship');
    fake_add_visibility_product(95, 'external');
    fake_set_visibility_relationships(95, 'pos_product_visibility', ['pos-hidden']);
    $adapter->regenerate_batch([95], []);
    $check(fake_visibility_slugs(95, 'pos_product_visibility') === [],
        'unsupported external products cannot retain a stale POS-hidden relationship');

    // Missing core identities, native writer faults, partial writes, hostile
    // post-write drift, and concurrent merchant edits all remain loud and
    // retryable. None of the failures includes merchant data.
    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'rated-1',
    ]);
    $fakeVisibilityWriteFailures['90:product_visibility'] = 1;
    $visibilityWriteFailure = '';
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $visibilityWriteFailure = $failure->getMessage();
    }
    $check(str_contains($visibilityWriteFailure, 'native relationship write failed')
        && !str_contains($visibilityWriteFailure, 'featured'),
        'native visibility write failure is loud, redacted, and leaves deterministic retry authority');
    $adapter->regenerate_batch([90], []);
    $check(fake_visibility_slugs(90, 'product_visibility') === [
        'exclude-from-search', 'featured', 'outofstock', 'rated-5',
    ], 'visibility retry converges after the injected native writer failure');

    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'rated-1',
    ]);
    $fakeVisibilityPartialWrites['90:product_visibility'] = 1;
    $partialVisibilityFailure = false;
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $partialVisibilityFailure = str_contains($failure->getMessage(), 'returned incomplete state');
    }
    $check($partialVisibilityFailure,
        'partial native visibility writes refuse before a verified receipt can be returned');
    $adapter->regenerate_batch([90], []);

    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'rated-1',
    ]);
    $fakeVisibilityPostWriteMutations['90:product_visibility'] = static function (): void {
        fake_set_visibility_relationships(90, 'product_visibility', [
            'exclude-from-search', 'featured', 'outofstock', 'rated-4',
        ]);
    };
    $postWriteVisibilityFailure = false;
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $postWriteVisibilityFailure = str_contains($failure->getMessage(), 'projection disagrees');
    }
    $check($postWriteVisibilityFailure,
        'same-count competing drift after the native writer cannot be blessed by its return value');
    $adapter->regenerate_batch([90], []);

    $savedRatedFive = $fakeVisibilityTerms['product_visibility']['rated-5'];
    unset($fakeVisibilityTerms['product_visibility']['rated-5']);
    $missingVisibilityTerm = false;
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $missingVisibilityTerm = str_contains($failure->getMessage(), 'term inventory is incomplete');
    }
    $fakeVisibilityTerms['product_visibility']['rated-5'] = $savedRatedFive;
    $check($missingVisibilityTerm,
        'a target missing one exact core visibility identity refuses before repair instead of creating an impostor');
    $adapter->regenerate_batch([90], []);

    $fakeVisibilityRelationships[90]['product_visibility'][] =
        $fakeVisibilityRelationships[90]['product_visibility'][0];
    $duplicateVisibilityFailure = false;
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $duplicateVisibilityFailure = str_contains($failure->getMessage(), 'duplicate state');
    }
    array_pop($fakeVisibilityRelationships[90]['product_visibility']);
    $check($duplicateVisibilityFailure,
        'duplicate raw visibility relationships refuse instead of collapsing into a plausible term set');

    $fakeVisibilityRaceOnLookupRefresh[90] = static function (): void {
        fake_set_visibility_relationships(90, 'product_visibility', [
            'exclude-from-search', 'exclude-from-catalog', 'featured', 'outofstock', 'rated-5',
        ]);
    };
    $concurrentVisibilityMessage = '';
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $concurrentVisibilityMessage = $failure->getMessage();
    }
    $check(str_contains($concurrentVisibilityMessage, 'visibility changed')
        && str_contains($concurrentVisibilityMessage, 'recovery_required'),
        'a concurrent merchant catalog-visibility edit during lookup work cannot be overwritten or blessed');
    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'outofstock', 'rated-5',
    ]);
    $adapter->regenerate_batch([90], []);
    $check(fake_visibility_slugs(90, 'product_visibility') === [
        'exclude-from-search', 'featured', 'outofstock', 'rated-5',
    ], 'visibility converges after the concurrent merchant edit is explicitly resolved and retried');

    // Pin the narrower lost-update window: both merchant catalog and POS
    // intent change after reconcile_visibility() takes its current snapshot
    // but immediately before the first native derived-term mutation. The
    // provider may fail, but it must never replace either authored edit with
    // the stale snapshot it captured.
    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'rated-1',
    ]);
    fake_set_visibility_relationships(90, 'pos_product_visibility', ['pos-hidden']);
    $fakeVisibilityPreWriteMutations['90:product_visibility:remove'] = static function (): void {
        fake_set_visibility_relationships(90, 'product_visibility', [
            'exclude-from-search', 'exclude-from-catalog', 'featured', 'rated-1',
        ]);
        fake_set_visibility_relationships(90, 'pos_product_visibility', []);
    };
    $preWriteRaceMessage = '';
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $preWriteRaceMessage = $failure->getMessage();
    }
    $check(str_contains($preWriteRaceMessage, 'visibility')
        && str_contains($preWriteRaceMessage, 'recovery_required')
        && in_array('exclude-from-catalog', fake_visibility_slugs(90, 'product_visibility'), true)
        && fake_visibility_slugs(90, 'pos_product_visibility') === [],
        'after-snapshot-before-write catalog/POS edits survive selective derived repair and force retry');
    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'outofstock', 'rated-5',
    ]);
    fake_set_visibility_relationships(90, 'pos_product_visibility', ['pos-hidden']);
    $adapter->regenerate_batch([90], []);

    // Final verification must derive from its own fresh native snapshot. Each
    // mutation lands after reconcile_visibility() observed current state but
    // before the first exact derived-term removal, the window in which reusing
    // the old expected array used to bless a stale projection.
    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'rated-1',
    ]);
    $fakeProducts[90]->set_stock(false);
    $fakeVisibilityPreWriteMutations['90:product_visibility:remove'] = static function (): void {
        global $fakeProducts;
        $fakeProducts[90]->set_stock(true);
    };
    $stockRaceFailure = false;
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $stockRaceFailure = str_contains($failure->getMessage(), 'product_visibility projection disagrees');
    }
    $check($stockRaceFailure,
        'after-snapshot stock drift cannot bless the stale outofstock visibility projection');
    $fakeProducts[90]->set_stock(false);
    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'outofstock', 'rated-5',
    ]);

    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'rated-1',
    ]);
    $fakeMeta[90]['_wc_average_rating'] = ['4.6'];
    $fakeVisibilityPreWriteMutations['90:product_visibility:remove'] = static function (): void {
        global $fakeMeta;
        $fakeMeta[90]['_wc_average_rating'] = ['3.6'];
    };
    $ratingRaceFailure = false;
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $ratingRaceFailure = str_contains($failure->getMessage(), 'product_visibility projection disagrees');
    }
    $check($ratingRaceFailure,
        'after-snapshot rating drift cannot bless the stale rated-* visibility projection');
    $fakeMeta[90]['_wc_average_rating'] = ['4.6'];
    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'outofstock', 'rated-5',
    ]);

    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'rated-1',
    ]);
    $fakeVisibilityPreWriteMutations['90:product_visibility:remove'] = static function (): void {
        global $fakeProducts;
        $fakeProducts[90]->set_type('external');
        fake_set_visibility_relationships(90, 'product_type', ['external']);
    };
    $typeRaceFailure = false;
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $typeRaceFailure = str_contains($failure->getMessage(), 'intent changed');
    }
    $check($typeRaceFailure,
        'after-snapshot product subtype drift cannot retain the prior visibility/POS projection');
    $fakeProducts[90]->set_type('variable');
    fake_set_visibility_relationships(90, 'product_type', ['variable']);
    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'outofstock', 'rated-5',
    ]);

    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'rated-1',
    ]);
    $fakeMeta[90]['_downloadable'] = ['no'];
    $fakeVisibilityPreWriteMutations['90:product_visibility:remove'] = static function (): void {
        global $fakeMeta;
        $fakeMeta[90]['_downloadable'] = ['yes'];
    };
    $downloadableRaceFailure = false;
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $downloadableRaceFailure = str_contains($failure->getMessage(), 'intent changed');
    }
    $check($downloadableRaceFailure,
        'after-snapshot downloadable drift cannot retain stale POS applicability');
    $fakeMeta[90]['_downloadable'] = ['no'];
    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'outofstock', 'rated-5',
    ]);

    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'rated-1',
    ]);
    $fakeVisibilityPreWriteMutations['90:product_visibility:remove'] = static function (): void {
        global $fakeProducts;
        $fakeProducts[91]->set_parent(93);
    };
    $parentRaceFailure = false;
    try {
        $adapter->regenerate_batch([90], []);
    } catch (\Throwable $failure) {
        $parentRaceFailure = str_contains($failure->getMessage(), 'intent changed')
            || str_contains($failure->getMessage(), 'scope changed');
    }
    $check($parentRaceFailure,
        'after-snapshot variation-parent drift cannot bless the old variable/POS inheritance scope');
    $fakeProducts[91]->set_parent(90);
    fake_set_visibility_relationships(90, 'product_visibility', [
        'exclude-from-search', 'featured', 'outofstock', 'rated-5',
    ]);
    $adapter->regenerate_batch([90], []);

    fake_add_visibility_product(96, 'variation', 999, [], true);
    $orphanVisibilityFailure = false;
    try {
        $adapter->regenerate_batch([96], []);
    } catch (\Throwable $failure) {
        $orphanVisibilityFailure = str_contains($failure->getMessage(), 'orphaned variation owner');
    }
    $check($orphanVisibilityFailure,
        'an orphaned variation cannot acquire an invented POS inheritance projection');
    unset($fakeProducts[96], $fakeMeta[96], $fakeMetaLookup[96], $fakeVisibilityRelationships[96]);

    fake_add_visibility_product(97, 'variable', 0, [98]);
    fake_add_visibility_product(98, 'variation', 97);
    fake_set_visibility_relationships(97, 'pos_product_visibility', ['pos-hidden']);
    fake_set_visibility_relationships(98, 'pos_product_visibility', ['pos-hidden']);
    $floodFirst = 100000;
    $floodCount = 50001;
    $fakeVisibilityChildFlood = [
        'parent' => 97,
        'first' => $floodFirst,
        'excluded_count' => $floodCount,
        'survivor' => 900000,
    ];
    $saturatedChildReadFailure = false;
    try {
        $adapter->regenerate_batch([97], [[
            'kind' => 'delete',
            'uuid' => 'visibility-excluded-child-flood',
            'id' => 999999,
            'post_type' => 'product_variation',
            'parent_id' => 97,
            'child_ids' => range($floodFirst, $floodFirst + $floodCount - 1),
        ]]);
    } catch (\Throwable $failure) {
        $saturatedChildReadFailure = str_contains($failure->getMessage(), 'saturated its bounded read');
    }
    $check($saturatedChildReadFailure,
        'excluded variation tombstones cannot consume a bounded child window and hide a later live child');
    $fakeVisibilityChildFlood = null;
    $deletedChildWriteStart = count($fakeVisibilityWrites);
    $adapter->regenerate_batch([97], [[
        'kind' => 'delete',
        'uuid' => 'visibility-deleted-child',
        'id' => 98,
        'post_type' => 'product_variation',
        'parent_id' => 97,
        'child_ids' => [],
    ]]);
    $deletedChildWrites = array_slice($fakeVisibilityWrites, $deletedChildWriteStart);
    $check(!array_filter(
        $deletedChildWrites,
        static fn(array $write): bool => (int) $write['id'] === 98
    ), 'the engine tombstone scope excludes a lingering deleted child from native visibility writes');
    $check((bool) array_filter(
        $fakeVisibilityQueries,
        static fn(string $query): bool => str_contains($query, 'LIMIT 50001')
    ), 'variation expansion is explicitly bounded in the product-path SQL before hostile allocation');
    if (WC_Data_Store::$variable !== null) {
        WC_Data_Store::$variable->calls = $variableCallsBeforeVisibility;
    }

    // The provider's graph bound is one deduplicated aggregate, not one
    // counter per array. Exercise duplicate grouped membership, a nested
    // grouped root, and a variable child whose variations are also referenced
    // by the nested group; every id must count once across products, roots,
    // prices, and attributes.
    $preflightScope = new \ReflectionMethod($adapter, 'preflight_product_scope');
    $nestedRoot = 70000;
    fake_add_visibility_product($nestedRoot, 'grouped', 0, [70001, 70001, 70002]);
    fake_add_visibility_product(70001, 'variable', 0, [70003, 70004]);
    fake_add_visibility_product(70002, 'grouped', 0, [70003, 70005]);
    fake_add_visibility_product(70003, 'variation', 70001);
    fake_add_visibility_product(70004, 'variation', 70001);
    fake_add_visibility_product(70005, 'simple');
    $fakeGroupedChildren[70000] = [70001, 70001, 70002];
    $fakeGroupedChildren[70002] = [70003, 70005];
    $nestedScope = $preflightScope->invoke($adapter, [$nestedRoot], []);
    $nestedScopeIds = array_map('intval', array_keys($nestedScope['ids']));
    sort($nestedScopeIds, SORT_NUMERIC);
    $check($nestedScopeIds === [70000, 70001, 70002, 70003, 70004, 70005],
        'nested and duplicate grouped/variation expansion contributes one exact deduplicated scope id per product');
    // Keep this on the production batch path too: a read failure after the
    // preflight proves the provider reached its normal visibility boundary,
    // rather than passing only through a test-only graph helper.
    $wpdb->failReadContaining = 'SELECT ID, post_parent, post_type';
    $nestedPathFailure = '';
    try {
        $adapter->regenerate_batch([$nestedRoot], []);
    } catch (\Throwable $failure) {
        $nestedPathFailure = $failure->getMessage();
    }
    $wpdb->failReadContaining = null;
    $check(str_contains($nestedPathFailure, 'checked read failed')
        && !str_contains($nestedPathFailure, 'aggregate product count'),
        'the production batch consumes the same nested deduplicated scope before its first cache mutation');
    foreach ([$nestedRoot, 70001, 70002, 70003, 70004, 70005] as $id) {
        unset($fakeProducts[$id], $fakeMeta[$id], $fakeMetaLookup[$id], $fakeVisibilityRelationships[$id], $fakeProductCache[$id]);
    }
    unset($fakeGroupedChildren[70000], $fakeGroupedChildren[70002]);

    // Exactly MAX_SCOPED_PRODUCTS (the root plus 49,999 unique grouped
    // children) is admitted and reaches the next read-only boundary. This
    // keeps the boundary regression fast while proving the guard is <=, not <.
    $boundaryRoot = 71000;
    $boundaryChildren = range($boundaryRoot + 1, $boundaryRoot + 49999);
    fake_add_visibility_product($boundaryRoot, 'grouped', 0, $boundaryChildren);
    $boundaryReverseBatches = 0;
    $wpdb->groupedParentReadHook = static function () use (&$boundaryReverseBatches): void {
        $boundaryReverseBatches++;
    };
    $wpdb->failReadContaining = 'SELECT ID, post_parent, post_type';
    $boundaryCacheStart = count($fakeCacheEvents);
    $boundaryFailure = '';
    try {
        $adapter->regenerate_batch([$boundaryRoot], []);
    } catch (\Throwable $failure) {
        $boundaryFailure = $failure->getMessage();
    }
    $wpdb->failReadContaining = null;
    $wpdb->groupedParentReadHook = null;
    $boundaryCacheEvents = array_slice($fakeCacheEvents, $boundaryCacheStart);
    $check(str_contains($boundaryFailure, 'checked read failed')
        && !str_contains($boundaryFailure, 'aggregate product count')
        && !in_array('remove:' . $boundaryRoot, $boundaryCacheEvents, true),
        'a 50,000-id aggregate is accepted at the boundary before the first cache mutation');
    $check($boundaryReverseBatches === 3127,
        'the 50,000-id reverse witness uses one initial plus two 1,563-batch bounded scans, never one query per child');
    unset($fakeProducts[$boundaryRoot], $fakeMeta[$boundaryRoot], $fakeMetaLookup[$boundaryRoot],
        $fakeVisibilityRelationships[$boundaryRoot], $fakeProductCache[$boundaryRoot]);
    unset($boundaryChildren);

    // The reverse-owner result itself is bounded independently of the
    // product graph. More than MAX_GROUPED_REVERSE_RESULT_ROWS owners for
    // one child must refuse during preflight rather than truncate the witness.
    $reverseOverflowChild = 71900;
    $reverseOverflowParents = range(71901, 121901);
    foreach ($reverseOverflowParents as $parentId) {
        $fakeGroupedChildren[$parentId] = [$reverseOverflowChild];
    }
    fake_add_visibility_product($reverseOverflowChild, 'simple');
    $reverseOverflowCacheStart = count($fakeCacheEvents);
    $reverseOverflowFailure = '';
    try {
        $adapter->regenerate_batch([$reverseOverflowChild], []);
    } catch (\Throwable $failure) {
        $reverseOverflowFailure = $failure->getMessage();
    }
    $reverseOverflowEffects = array_values(array_filter(
        array_slice($fakeCacheEvents, $reverseOverflowCacheStart),
        static fn(string $event): bool => str_starts_with($event, 'remove:')
    ));
    $check(str_contains($reverseOverflowFailure, 'grouped reverse ownership exceeds its bounded result scope')
        && $reverseOverflowEffects === [],
        'more than 50,000 grouped owners refuses as an explicit bounded reverse-witness overflow');
    foreach ($reverseOverflowParents as $parentId) {
        unset($fakeGroupedChildren[$parentId]);
    }
    unset($reverseOverflowParents);
    unset($fakeProducts[$reverseOverflowChild], $fakeMeta[$reverseOverflowChild],
        $fakeMetaLookup[$reverseOverflowChild], $fakeVisibilityRelationships[$reverseOverflowChild],
        $fakeProductCache[$reverseOverflowChild]);

    // A bounded preflight is only useful if its witness is checked again
    // before the first effect. Widen the same 50,000-id grouped root between
    // those two reads and prove the provider refuses with no cache, lookup,
    // price, or scheduler work having happened.
    $groupedRaceRoot = 71500;
    $groupedRaceChildren = range($groupedRaceRoot + 1, $groupedRaceRoot + 49999);
    $groupedRaceWidened = $groupedRaceChildren;
    $groupedRaceWidened[] = $groupedRaceRoot + 50000;
    fake_add_visibility_product($groupedRaceRoot, 'grouped', 0, $groupedRaceChildren);
    $fakeGroupedChildren[$groupedRaceRoot] = $groupedRaceChildren;
    $groupedRaceReads = 0;
    $wpdb->childScopeReadHook = static function () use (
        &$groupedRaceReads,
        &$fakeGroupedChildren,
        $groupedRaceRoot,
        $groupedRaceWidened
    ): void {
        $groupedRaceReads++;
        if ($groupedRaceReads === 4) {
            $fakeGroupedChildren[$groupedRaceRoot] = $groupedRaceWidened;
        }
    };
    $groupedRaceCacheStart = count($fakeCacheEvents);
    $groupedRaceLookupStart =
        (\Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore::$instance?->createCalls ?? 0);
    $groupedRaceVariableStart = WC_Data_Store::$variable?->calls ?? 0;
    $groupedRaceGroupedStart = WC_Data_Store::$grouped?->calls ?? 0;
    $groupedRaceScheduleStart = array_sum(array_map('count', $fakeSaleSchedules));
    $groupedRaceFailure = '';
    try {
        $adapter->regenerate_batch([$groupedRaceRoot], []);
    } catch (\Throwable $failure) {
        $groupedRaceFailure = $failure->getMessage();
    }
    $wpdb->childScopeReadHook = null;
    $groupedRaceEffects = array_values(array_filter(
        array_slice($fakeCacheEvents, $groupedRaceCacheStart),
        static fn(string $event): bool => str_starts_with($event, 'remove:')
    ));
    $groupedRaceLookupCalls =
        (\Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore::$instance?->createCalls ?? 0)
        - $groupedRaceLookupStart;
    $groupedRaceScheduleCalls = array_sum(array_map('count', $fakeSaleSchedules)) - $groupedRaceScheduleStart;
    $check(str_contains($groupedRaceFailure, 'child scope changed')
        && $groupedRaceEffects === []
        && $groupedRaceLookupCalls === 0
        && (WC_Data_Store::$variable?->calls ?? 0) === $groupedRaceVariableStart
        && (WC_Data_Store::$grouped?->calls ?? 0) === $groupedRaceGroupedStart
        && $groupedRaceScheduleCalls === 0,
        'a grouped child list widening from 50,000 to 50,001 after preflight refuses before every derived effect');
    unset($fakeProducts[$groupedRaceRoot], $fakeMeta[$groupedRaceRoot], $fakeMetaLookup[$groupedRaceRoot],
        $fakeVisibilityRelationships[$groupedRaceRoot], $fakeProductCache[$groupedRaceRoot],
        $fakeGroupedChildren[$groupedRaceRoot]);
    unset($groupedRaceChildren, $groupedRaceWidened);

    // Reverse grouped ownership is a separate bounded witness from a root's
    // own _children payload. Insert a new grouped owner for an already
    // selected simple child during the post-preflight reverse probe; the
    // provider must refuse before it can discover, invalidate, or sync that
    // newly authorized root.
    $reverseRaceChild = 71700;
    $reverseRaceParent = 71701;
    fake_add_visibility_product($reverseRaceChild, 'simple');
    $reverseParentReads = 0;
    $wpdb->groupedParentReadHook = static function () use (
        &$reverseParentReads,
        &$fakeGroupedChildren,
        $reverseRaceChild,
        $reverseRaceParent
    ): void {
        $reverseParentReads++;
        if ($reverseParentReads === 3) {
            fake_add_visibility_product($reverseRaceParent, 'grouped', 0, [$reverseRaceChild]);
            $fakeGroupedChildren[$reverseRaceParent] = [$reverseRaceChild];
        }
    };
    $reverseRaceCacheStart = count($fakeCacheEvents);
    $reverseRaceFailure = '';
    try {
        $adapter->regenerate_batch([$reverseRaceChild], []);
    } catch (\Throwable $failure) {
        $reverseRaceFailure = $failure->getMessage();
    }
    $wpdb->groupedParentReadHook = null;
    $reverseRaceEffects = array_values(array_filter(
        array_slice($fakeCacheEvents, $reverseRaceCacheStart),
        static fn(string $event): bool => str_starts_with($event, 'remove:')
    ));
    $check(str_contains($reverseRaceFailure, 'grouped-parent scope changed')
        && $reverseRaceEffects === []
        && $reverseParentReads === 3,
        'a grouped parent inserted after reverse preflight refuses before root discovery or derived effects');
    unset($fakeProducts[$reverseRaceChild], $fakeMeta[$reverseRaceChild], $fakeMetaLookup[$reverseRaceChild],
        $fakeVisibilityRelationships[$reverseRaceChild], $fakeProductCache[$reverseRaceChild],
        $fakeProducts[$reverseRaceParent], $fakeMeta[$reverseRaceParent], $fakeMetaLookup[$reverseRaceParent],
        $fakeVisibilityRelationships[$reverseRaceParent], $fakeProductCache[$reverseRaceParent],
        $fakeGroupedChildren[$reverseRaceParent]);

    // The reverse witness must include ordinary members discovered below a
    // grouped root, not only the initially selected child. Insert a new
    // grouped owner for a nested simple member during its post-preflight
    // reverse probe; before the child-ID witness this owner was discovered
    // only after effects began and the unsnapshotted root was skipped.
    $nestedReverseRoot = 71800;
    $nestedReverseInner = 71801;
    $nestedReverseChild = 71802;
    $nestedReverseParent = 71803;
    fake_add_visibility_product($nestedReverseRoot, 'grouped', 0, [$nestedReverseInner]);
    fake_add_visibility_product($nestedReverseInner, 'grouped', 0, [$nestedReverseChild]);
    fake_add_visibility_product($nestedReverseChild, 'simple');
    $fakeGroupedChildren[$nestedReverseRoot] = [$nestedReverseInner];
    $fakeGroupedChildren[$nestedReverseInner] = [$nestedReverseChild];
    $nestedReverseReads = 0;
    $wpdb->groupedParentReadHook = static function () use (
        &$nestedReverseReads,
        &$fakeGroupedChildren,
        $nestedReverseChild,
        $nestedReverseParent
    ): void {
        $nestedReverseReads++;
        // One bounded batch reads the selected root, one reads the complete
        // discovered-child witness, and one revalidates that same batch;
        // mutate immediately before the nested-child assertion returns.
        if ($nestedReverseReads === 3) {
            fake_add_visibility_product($nestedReverseParent, 'grouped', 0, [$nestedReverseChild]);
            $fakeGroupedChildren[$nestedReverseParent] = [$nestedReverseChild];
        }
    };
    $nestedReverseCacheStart = count($fakeCacheEvents);
    $nestedReverseFailure = '';
    try {
        $adapter->regenerate_batch([$nestedReverseRoot], []);
    } catch (\Throwable $failure) {
        $nestedReverseFailure = $failure->getMessage();
    }
    $wpdb->groupedParentReadHook = null;
    $nestedReverseEffects = array_values(array_filter(
        array_slice($fakeCacheEvents, $nestedReverseCacheStart),
        static fn(string $event): bool => str_starts_with($event, 'remove:')
    ));
    $check(str_contains($nestedReverseFailure, 'grouped-parent scope changed')
        && $nestedReverseEffects === []
        && $nestedReverseReads === 3,
        'a grouped parent inserted for a nested ordinary child refuses before root discovery or derived effects');
    foreach ([$nestedReverseRoot, $nestedReverseInner, $nestedReverseChild, $nestedReverseParent] as $id) {
        unset($fakeProducts[$id], $fakeMeta[$id], $fakeMetaLookup[$id],
            $fakeVisibilityRelationships[$id], $fakeProductCache[$id]);
    }
    unset($fakeGroupedChildren[$nestedReverseRoot], $fakeGroupedChildren[$nestedReverseInner],
        $fakeGroupedChildren[$nestedReverseParent]);

    // A sealed owner can disappear or change subtype after the final scope
    // witness but before reverse-edge projection. The provider must consume
    // that edge once and refuse before invalidating the stale owner, rather
    // than retrying the same generator row forever or silently skipping it.
    $staleReverseChild = 71710;
    $staleReverseParent = 71711;
    fake_add_visibility_product($staleReverseChild, 'simple');
    fake_add_visibility_product($staleReverseParent, 'grouped', 0, [$staleReverseChild]);
    $fakeGroupedChildren[$staleReverseParent] = [$staleReverseChild];
    $staleReverseReads = 0;
    $wpdb->childScopeReadHook = static function () use (
        &$staleReverseReads,
        &$fakeProducts,
        &$fakeProductCache,
        $staleReverseParent
    ): void {
        $staleReverseReads++;
        // Preflight and assertion each read the grouped payload twice. Make
        // the owner non-grouped after those reads, immediately before the
        // reverse-edge consumer loads it.
        if ($staleReverseReads === 4) {
            $fakeProducts[$staleReverseParent]->set_type('simple');
            unset($fakeProductCache[$staleReverseParent]);
        }
    };
    $staleReverseCacheStart = count($fakeCacheEvents);
    $staleReverseFailure = '';
    try {
        $adapter->regenerate_batch([$staleReverseChild], []);
    } catch (\Throwable $failure) {
        $staleReverseFailure = $failure->getMessage();
    }
    $wpdb->childScopeReadHook = null;
    $staleReverseEffects = array_values(array_filter(
        array_slice($fakeCacheEvents, $staleReverseCacheStart),
        static fn(string $event): bool => $event === 'remove:' . $staleReverseParent
    ));
    $check(str_contains($staleReverseFailure, 'is no longer grouped')
        && $staleReverseEffects === []
        && $staleReverseReads === 4,
        'a reverse owner changing subtype refuses once, without invalidating the stale owner');
    unset($fakeProducts[$staleReverseChild], $fakeMeta[$staleReverseChild],
        $fakeMetaLookup[$staleReverseChild], $fakeVisibilityRelationships[$staleReverseChild],
        $fakeProductCache[$staleReverseChild], $fakeProducts[$staleReverseParent],
        $fakeMeta[$staleReverseParent], $fakeMetaLookup[$staleReverseParent],
        $fakeVisibilityRelationships[$staleReverseParent], $fakeProductCache[$staleReverseParent],
        $fakeGroupedChildren[$staleReverseParent]);

    // The owner can retain its grouped subtype while losing the specific
    // witness edge. Re-read the bounded serialized child payload before any
    // owner cache invalidation and refuse the exact membership drift.
    $lostMembershipChild = 71720;
    $lostMembershipParent = 71721;
    fake_add_visibility_product($lostMembershipChild, 'simple');
    fake_add_visibility_product($lostMembershipParent, 'grouped', 0, [$lostMembershipChild]);
    $fakeGroupedChildren[$lostMembershipParent] = [$lostMembershipChild];
    $lostMembershipReads = 0;
    $wpdb->childScopeReadHook = static function () use (
        &$lostMembershipReads,
        &$fakeGroupedChildren,
        $lostMembershipParent
    ): void {
        $lostMembershipReads++;
        // The fifth bounded payload read is the reverse-edge consumer's
        // fresh owner check: two preflight reads plus two assertion reads
        // have already established the sealed scope.
        if ($lostMembershipReads === 5) {
            $fakeGroupedChildren[$lostMembershipParent] = [];
        }
    };
    $lostMembershipCacheStart = count($fakeCacheEvents);
    $lostMembershipFailure = '';
    try {
        $adapter->regenerate_batch([$lostMembershipChild], []);
    } catch (\Throwable $failure) {
        $lostMembershipFailure = $failure->getMessage();
    }
    $wpdb->childScopeReadHook = null;
    $lostMembershipEffects = array_values(array_filter(
        array_slice($fakeCacheEvents, $lostMembershipCacheStart),
        static fn(string $event): bool => $event === 'remove:' . $lostMembershipParent
    ));
    $check(str_contains($lostMembershipFailure, 'child membership changed')
        && $lostMembershipEffects === []
        && $lostMembershipReads === 6,
        'a reverse owner losing its sealed child membership refuses before owner invalidation');
    unset($fakeProducts[$lostMembershipChild], $fakeMeta[$lostMembershipChild],
        $fakeMetaLookup[$lostMembershipChild], $fakeVisibilityRelationships[$lostMembershipChild],
        $fakeProductCache[$lostMembershipChild], $fakeProducts[$lostMembershipParent],
        $fakeMeta[$lostMembershipParent], $fakeMetaLookup[$lostMembershipParent],
        $fakeVisibilityRelationships[$lostMembershipParent], $fakeProductCache[$lostMembershipParent],
        $fakeGroupedChildren[$lostMembershipParent]);

    // A reverse owner can disappear after the final bounded child read. The
    // sealed SQL edge must not be treated as permission to invalidate a post
    // that no longer has a public Woo product object.
    $disappearedOwnerChild = 71730;
    $disappearedOwnerParent = 71731;
    fake_add_visibility_product($disappearedOwnerChild, 'simple');
    fake_add_visibility_product($disappearedOwnerParent, 'grouped', 0, [$disappearedOwnerChild]);
    $fakeGroupedChildren[$disappearedOwnerParent] = [$disappearedOwnerChild];
    $disappearedOwnerReads = 0;
    $wpdb->childScopeReadHook = static function () use (
        &$disappearedOwnerReads,
        &$fakeProducts,
        &$fakeProductCache,
        &$fakeGroupedChildren,
        $disappearedOwnerParent
    ): void {
        $disappearedOwnerReads++;
        if ($disappearedOwnerReads === 4) {
            unset($fakeProducts[$disappearedOwnerParent], $fakeProductCache[$disappearedOwnerParent]);
            // Keep the fixture's SQL witness row so the production edge
            // consumer, rather than preflight, handles the stale owner.
            $fakeGroupedChildren[$disappearedOwnerParent] = [71730];
        }
    };
    $disappearedOwnerCacheStart = count($fakeCacheEvents);
    $disappearedOwnerFailure = '';
    try {
        $adapter->regenerate_batch([$disappearedOwnerChild], []);
    } catch (\Throwable $failure) {
        $disappearedOwnerFailure = $failure->getMessage();
    }
    $wpdb->childScopeReadHook = null;
    $disappearedOwnerEffects = array_values(array_filter(
        array_slice($fakeCacheEvents, $disappearedOwnerCacheStart),
        static fn(string $event): bool => $event === 'remove:' . $disappearedOwnerParent
    ));
    $check(str_contains($disappearedOwnerFailure, 'disappeared before native projection')
        && $disappearedOwnerEffects === []
        && $disappearedOwnerReads === 4,
        'a reverse owner disappearing after preflight refuses before stale-owner invalidation');
    unset($fakeProducts[$disappearedOwnerChild], $fakeMeta[$disappearedOwnerChild],
        $fakeMetaLookup[$disappearedOwnerChild], $fakeVisibilityRelationships[$disappearedOwnerChild],
        $fakeProductCache[$disappearedOwnerChild], $fakeProducts[$disappearedOwnerParent],
        $fakeMeta[$disappearedOwnerParent], $fakeMetaLookup[$disappearedOwnerParent],
        $fakeVisibilityRelationships[$disappearedOwnerParent], $fakeProductCache[$disappearedOwnerParent],
        $fakeGroupedChildren[$disappearedOwnerParent]);

    // If an owner was absent while preflight walked the reverse edge, its
    // returned scope has no child witness. Restoring the owner before the
    // projection edge is consumed must still refuse rather than treating the
    // missing snapshot entry as an empty child list.
    $missingWitnessChild = 71740;
    $missingWitnessParent = 71741;
    fake_add_visibility_product($missingWitnessChild, 'simple');
    fake_add_visibility_product($missingWitnessParent, 'grouped', 0, [$missingWitnessChild]);
    $fakeGroupedChildren[$missingWitnessParent] = [$missingWitnessChild];
    $missingWitnessProduct = $fakeProducts[$missingWitnessParent];
    $missingWitnessReads = 0;
    $wpdb->groupedParentReadHook = static function () use (
        &$missingWitnessReads,
        &$fakeProducts,
        &$fakeProductCache,
        $missingWitnessParent,
        $missingWitnessProduct
    ): void {
        $missingWitnessReads++;
        if ($missingWitnessReads === 1) {
            unset($fakeProducts[$missingWitnessParent], $fakeProductCache[$missingWitnessParent]);
        } elseif ($missingWitnessReads === 2) {
            $fakeProducts[$missingWitnessParent] = $missingWitnessProduct;
        }
    };
    $missingWitnessCacheStart = count($fakeCacheEvents);
    $missingWitnessFailure = '';
    try {
        $adapter->regenerate_batch([$missingWitnessChild], []);
    } catch (\Throwable $failure) {
        $missingWitnessFailure = $failure->getMessage();
    }
    $wpdb->groupedParentReadHook = null;
    $missingWitnessEffects = array_values(array_filter(
        array_slice($fakeCacheEvents, $missingWitnessCacheStart),
        static fn(string $event): bool => $event === 'remove:' . $missingWitnessParent
    ));
    $check(str_contains($missingWitnessFailure, 'lacks its bounded child witness')
        && $missingWitnessEffects === []
        && $missingWitnessReads === 3,
        'a restored reverse owner without a sealed child witness refuses before owner invalidation');
    unset($fakeProducts[$missingWitnessChild], $fakeMeta[$missingWitnessChild],
        $fakeMetaLookup[$missingWitnessChild], $fakeVisibilityRelationships[$missingWitnessChild],
        $fakeProductCache[$missingWitnessChild], $fakeProducts[$missingWitnessParent],
        $fakeMeta[$missingWitnessParent], $fakeMetaLookup[$missingWitnessParent],
        $fakeVisibilityRelationships[$missingWitnessParent], $fakeProductCache[$missingWitnessParent],
        $fakeGroupedChildren[$missingWitnessParent]);

    // Exercise the same mutable sequence through Woo's variable-product
    // post_parent reader. The all-child witness is fixed at 50,000 ids, then
    // the native membership widens before the assertion read; no later
    // consumer is allowed to observe or project that widened set.
    $variableRaceRoot = 71600;
    $variableRaceChildren = range($variableRaceRoot + 1, $variableRaceRoot + 49999);
    $variableRaceWidened = $variableRaceChildren;
    $variableRaceWidened[] = $variableRaceRoot + 50000;
    fake_add_visibility_product($variableRaceRoot, 'variable', 0, $variableRaceChildren);
    $variableRaceReads = 0;
    $wpdb->childScopeReadHook = static function () use (
        &$variableRaceReads,
        &$fakeProducts,
        $variableRaceRoot,
        $variableRaceWidened
    ): void {
        $variableRaceReads++;
        if ($variableRaceReads === 3) {
            $fakeProducts[$variableRaceRoot]->set_children($variableRaceWidened);
        }
    };
    $variableRaceCacheStart = count($fakeCacheEvents);
    $variableRaceLookupStart =
        (\Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore::$instance?->createCalls ?? 0);
    $variableRaceVariableStart = WC_Data_Store::$variable?->calls ?? 0;
    $variableRaceGroupedStart = WC_Data_Store::$grouped?->calls ?? 0;
    $variableRaceScheduleStart = array_sum(array_map('count', $fakeSaleSchedules));
    $variableRaceFailure = '';
    try {
        $adapter->regenerate_batch([$variableRaceRoot], []);
    } catch (\Throwable $failure) {
        $variableRaceFailure = $failure->getMessage();
    }
    $wpdb->childScopeReadHook = null;
    $variableRaceEffects = array_values(array_filter(
        array_slice($fakeCacheEvents, $variableRaceCacheStart),
        static fn(string $event): bool => str_starts_with($event, 'remove:')
    ));
    $variableRaceLookupCalls =
        (\Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore::$instance?->createCalls ?? 0)
        - $variableRaceLookupStart;
    $variableRaceScheduleCalls = array_sum(array_map('count', $fakeSaleSchedules)) - $variableRaceScheduleStart;
    $check(str_contains($variableRaceFailure, 'child scope changed')
        && $variableRaceEffects === []
        && $variableRaceLookupCalls === 0
        && (WC_Data_Store::$variable?->calls ?? 0) === $variableRaceVariableStart
        && (WC_Data_Store::$grouped?->calls ?? 0) === $variableRaceGroupedStart
        && $variableRaceScheduleCalls === 0,
        'a variable child list widening from 50,000 to 50,001 after preflight refuses before every derived effect');
    unset($fakeProducts[$variableRaceRoot], $fakeMeta[$variableRaceRoot], $fakeMetaLookup[$variableRaceRoot],
        $fakeVisibilityRelationships[$variableRaceRoot], $fakeProductCache[$variableRaceRoot]);
    unset($variableRaceChildren, $variableRaceWidened);

    // MAX+1 must fail before visibility/cache work and must not partially
    // mutate the root lookup witness.
    $overflowRoot = 72000;
    $overflowChildren = range($overflowRoot + 1, $overflowRoot + 50000);
    fake_add_visibility_product($overflowRoot, 'grouped', 0, $overflowChildren);
    $overflowLookupBefore = $fakeMetaLookup[$overflowRoot];
    $overflowCacheStart = count($fakeCacheEvents);
    $overflowFailure = '';
    try {
        $adapter->regenerate_batch([$overflowRoot], []);
    } catch (\Throwable $failure) {
        $overflowFailure = $failure->getMessage();
    }
    $overflowCacheEvents = array_slice($fakeCacheEvents, $overflowCacheStart);
    $check(str_contains($overflowFailure, 'aggregate product count')
        && !in_array('remove:' . $overflowRoot, $overflowCacheEvents, true)
        && $fakeMetaLookup[$overflowRoot] === $overflowLookupBefore,
        'a 50,001-id aggregate refuses loudly before cache/lookup effects with no partial root mutation');
    unset($fakeProducts[$overflowRoot], $fakeMeta[$overflowRoot], $fakeMetaLookup[$overflowRoot],
        $fakeVisibilityRelationships[$overflowRoot], $fakeProductCache[$overflowRoot]);

    // The same single aggregate must reject a variable root's variation
    // expansion; a separate per-root/child counter would otherwise permit
    // this path while grouped roots are bounded.
    $variationOverflowRoot = 73000;
    $variationOverflowChildren = range($variationOverflowRoot + 1, $variationOverflowRoot + 50000);
    fake_add_visibility_product($variationOverflowRoot, 'variable', 0, $variationOverflowChildren);
    $variationOverflowCacheStart = count($fakeCacheEvents);
    $variationOverflowFailure = '';
    try {
        $adapter->regenerate_batch([$variationOverflowRoot], []);
    } catch (\Throwable $failure) {
        $variationOverflowFailure = $failure->getMessage();
    }
    $variationOverflowCacheEvents = array_slice($fakeCacheEvents, $variationOverflowCacheStart);
    $check(str_contains($variationOverflowFailure, 'aggregate product count')
        && !in_array('remove:' . $variationOverflowRoot, $variationOverflowCacheEvents, true),
        'a 50,001-id variable/variation aggregate refuses before cache effects');
    unset($fakeProducts[$variationOverflowRoot], $fakeMeta[$variationOverflowRoot],
        $fakeMetaLookup[$variationOverflowRoot], $fakeVisibilityRelationships[$variationOverflowRoot],
        $fakeProductCache[$variationOverflowRoot]);

    // A target-local approved-directory register is a real WooCommerce 11
    // projection: raw postmeta can contain an enabled, correctly rebased
    // file while WC_Product::get_downloads() disables it until the target
    // parent is approved. Exercise that boundary through regenerate_batch(),
    // the same provider entry point a real apply invokes.
    $downloadId = '0123456789abcdef0123456789abcdef';
    $downloadFile = 'https://target.example/uploads/2030/01/catalog.pdf?download=1&label=tokyo';
    $downloadRow = static function (
        string $file,
        bool $enabled = true,
        string $name = 'Portable catalog 東京.pdf',
        array $extra = []
    ) use ($downloadId): string {
        return serialize([
            $downloadId => array_merge([
            'id' => $downloadId,
            'name' => $name,
            'file' => $file,
            'enabled' => $enabled,
            ], $extra),
        ]);
    };
    $fakeMeta[17] = $fakeMeta[13];
    $fakeMeta[17]['_downloadable'] = ['yes'];
    $fakeMeta[17]['_downloadable_files'] = [$downloadRow($downloadFile)];
    $fakeProducts[17] = new FakeProduct(17, 'simple', 0, [], [], true);
    fake_set_visibility_relationships(17, 'product_type', ['simple']);
    $fakeMetaLookup[17] = $fakeMetaLookup[10];
    $fakeMetaLookup[17]['product_id'] = 17;
    $register = \Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register::$instance
        ??= new \Automattic\WooCommerce\Internal\ProductDownloads\ApprovedDirectories\Register();
    $register->rules['https://unrelated.example/private/'] = ['id' => 900, 'enabled' => true];
    $adapter->regenerate_batch([17], []);
    $expectedParent = 'https://target.example/uploads/2030/01/';
    $nativeDownload = wc_get_product(17)->get_downloads()[$downloadId] ?? null;
    $check(isset($register->rules[$expectedParent]) && $register->rules[$expectedParent]['enabled'] === true,
        'download repair adds the exact target parent through WooCommerce\'s approved-directory API');
    $check(isset($register->rules['https://unrelated.example/private/']),
        'download repair preserves unrelated target-local approved-directory rules');
    $check($nativeDownload instanceof FakeProductDownload
        && $nativeDownload->get_id() === $downloadId
        && $nativeDownload->get_name() === 'Portable catalog 東京.pdf'
        && $nativeDownload->get_enabled() === true
        && $nativeDownload->get_file() === $downloadFile,
        'the real provider path verifies exact native download identity, name, file, and enabled state');
    $addCount = $register->adds;
    $adapter->regenerate_batch([17], []);
    $check($register->adds === $addCount,
        'replaying the same product batch is idempotent and creates no duplicate directory rule');

    $register->rules[$expectedParent]['enabled'] = false;
    $enableCount = $register->enables;
    $adapter->regenerate_batch([17], []);
    $check($register->rules[$expectedParent]['enabled'] === true && $register->enables === $enableCount + 1,
        'an exact disabled target rule is re-enabled because the authored product requires that directory');

    $register->rules = [
        'https://target.example/uploads/' => ['id' => 901, 'enabled' => true],
        'https://unrelated.example/private/' => ['id' => 900, 'enabled' => true],
    ];
    $addCount = $register->adds;
    $adapter->regenerate_batch([17], []);
    $check($register->adds === $addCount && !isset($register->rules[$expectedParent]),
        'an existing broader approved parent satisfies the file without adding a redundant narrower rule');

    $register->mode = $register::MODE_DISABLED;
    $register->rules = ['https://unrelated.example/private/' => ['id' => 900, 'enabled' => true]];
    $addCount = $register->adds;
    $adapter->regenerate_batch([17], []);
    $nativeDownload = wc_get_product(17)->get_downloads()[$downloadId] ?? null;
    $check($register->adds === $addCount && $nativeDownload instanceof FakeProductDownload
        && $nativeDownload->get_enabled() === true,
        'disabled directory enforcement performs no registry mutation and native verification still succeeds');
    $register->mode = $register::MODE_ENABLED;

    $secretFile = 'https://target.example/private/catalog.pdf?api_key=do-not-leak';
    $fakeMeta[17]['_downloadable_files'] = [$downloadRow($secretFile)];
    $register->rules = [];
    $register->failAdd = true;
    $failureMessage = '';
    try {
        $adapter->regenerate_batch([17], []);
    } catch (\Throwable $failure) {
        $failureMessage = $failure->getMessage();
    }
    $register->failAdd = false;
    $check(str_contains($failureMessage, 'could not approve the target directory')
        && !str_contains($failureMessage, 'api_key') && !str_contains($failureMessage, 'do-not-leak'),
        'directory write failures are loud but redact credential-shaped URLs and plugin error detail');
    $adapter->regenerate_batch([17], []);
    $check(wc_get_product(17)->get_downloads()[$downloadId]->get_enabled() === true,
        'the same batch converges on retry after a directory-provider write failure');

    $fakeMeta[17]['_downloadable_files'] = [$downloadRow('[private_download id="17"]')];
    $shortcodeFailure = false;
    try {
        $adapter->regenerate_batch([17], []);
    } catch (\Throwable $failure) {
        $shortcodeFailure = str_contains($failure->getMessage(), 'shortcode download locator');
    }
    $check($shortcodeFailure,
        'extension-executed shortcode download locators fail closed before any approval API call');

    $fakeMeta[17]['_downloadable_files'] = [$downloadRow($downloadFile, false)];
    $disabledFailure = false;
    try {
        $adapter->regenerate_batch([17], []);
    } catch (\Throwable $failure) {
        $disabledFailure = str_contains($failure->getMessage(), 'unsupported downloadable-file row');
    }
    $check($disabledFailure,
        'site-local disabled download rows cannot be silently promoted as portable authored state');

    $fakeMeta[17]['_downloadable_files'] = [
        $downloadRow($downloadFile, true, 'Portable catalog 東京.pdf', ['addon_checksum' => 'unsupported']),
    ];
    $unknownDownloadFieldFailure = false;
    try {
        $adapter->regenerate_batch([17], []);
    } catch (\Throwable $failure) {
        $unknownDownloadFieldFailure = str_contains($failure->getMessage(), 'unsupported downloadable-file row');
    }
    $check($unknownDownloadFieldFailure,
        'addon-owned downloadable-file fields refuse at the executable provider boundary');

    $fakeMeta[17]['_downloadable_files'] = [$downloadRow($downloadFile)];
    $fakeDownloadMetaRows[17] = [$downloadRow($downloadFile), $downloadRow($downloadFile)];
    $duplicateFailure = false;
    try {
        $adapter->regenerate_batch([17], []);
    } catch (\Throwable $failure) {
        $duplicateFailure = str_contains($failure->getMessage(), 'multiple _downloadable_files rows');
    }
    unset($fakeDownloadMetaRows[17]);
    $check($duplicateFailure,
        'duplicate raw download metadata rows refuse instead of selecting an arbitrary value');

    $observeDownloads = new \ReflectionMethod($adapter, 'authored_downloads');
    $oversizedDownloadMarker = 'download_secret_marker_DO_NOT_ECHO';
    $fakeDownloadMetaRows[17] = [
        $oversizedDownloadMarker
            . str_repeat('x', 1048577 - strlen($oversizedDownloadMarker)),
    ];
    $oversizedDownloadMessage = '';
    try {
        $observeDownloads->invoke($adapter, [17]);
    } catch (\Throwable $failure) {
        $oversizedDownloadMessage = $failure->getMessage();
    }
    unset($fakeDownloadMetaRows[17]);
    $check(str_contains($oversizedDownloadMessage, 'oversized downloadable-file metadata')
        && !str_contains($oversizedDownloadMessage, $oversizedDownloadMarker),
        'a single LONGTEXT download value is refused from its compact byte witness without transfer or disclosure');

    $aggregateDownloadIds = range(1000, 1016);
    $aggregateDownloadValue = str_repeat('x', 1048576);
    foreach ($aggregateDownloadIds as $aggregateDownloadId) {
        $fakeDownloadMetaRows[$aggregateDownloadId] = [$aggregateDownloadValue];
    }
    $fakeVisibilityQueries = [];
    $aggregateDownloadMessage = '';
    try {
        $observeDownloads->invoke($adapter, $aggregateDownloadIds);
    } catch (\Throwable $failure) {
        $aggregateDownloadMessage = $failure->getMessage();
    }
    foreach ($aggregateDownloadIds as $aggregateDownloadId) {
        unset($fakeDownloadMetaRows[$aggregateDownloadId]);
    }
    unset($aggregateDownloadValue);
    $check(str_contains($aggregateDownloadMessage, 'aggregate byte bound')
        && !array_filter(
            $fakeVisibilityQueries,
            static fn(string $query): bool => str_contains($query, ' AS meta_key, meta_value,')
        ), 'aggregate authored download bytes refuse from compact witnesses before any LONGTEXT payload read');

    $tooManyDownloads = [];
    for ($index = 0; $index < 1001; $index++) {
        $rowId = 'download-' . $index;
        $tooManyDownloads[$rowId] = [
            'id' => $rowId,
            'name' => 'n',
            'file' => 'https://target.example/d/' . $index,
            'enabled' => true,
        ];
    }
    $fakeDownloadMetaRows[17] = [serialize($tooManyDownloads)];
    $tooManyDownloadsMessage = '';
    try {
        $observeDownloads->invoke($adapter, [17]);
    } catch (\Throwable $failure) {
        $tooManyDownloadsMessage = $failure->getMessage();
    }
    unset($fakeDownloadMetaRows[17], $tooManyDownloads);
    $check(str_contains($tooManyDownloadsMessage, 'malformed downloadable-file metadata'),
        'decoded download rows are bounded per product even when the serialized payload is within its byte cap');

    $aggregateDecodedRows = [];
    for ($index = 0; $index < 910; $index++) {
        $rowId = 'd-' . $index;
        $aggregateDecodedRows[$rowId] = [
            'id' => $rowId,
            'name' => 'n',
            'file' => 'https://target.example/d/' . $index,
            'enabled' => true,
        ];
    }
    $aggregateDecodedValue = serialize($aggregateDecodedRows);
    $aggregateDecodedIds = range(1100, 1110);
    foreach ($aggregateDecodedIds as $aggregateDecodedId) {
        $fakeDownloadMetaRows[$aggregateDecodedId] = [$aggregateDecodedValue];
    }
    $aggregateDecodedMessage = '';
    try {
        $observeDownloads->invoke($adapter, $aggregateDecodedIds);
    } catch (\Throwable $failure) {
        $aggregateDecodedMessage = $failure->getMessage();
    }
    foreach ($aggregateDecodedIds as $aggregateDecodedId) {
        unset($fakeDownloadMetaRows[$aggregateDecodedId]);
    }
    unset($aggregateDecodedRows, $aggregateDecodedValue);
    $check(str_contains($aggregateDecodedMessage, 'aggregate decoded bound'),
        'decoded download cardinality is bounded across owners and chunks, not merely per product');

    $downloadBoundCases = [
        'identity' => [str_repeat('i', 257), 'name', 'https://target.example/file'],
        'name' => ['bounded-id', str_repeat('n', 4097), 'https://target.example/file'],
        'file' => ['bounded-id', 'name', 'https://target.example/' . str_repeat('f', 8193)],
    ];
    foreach ($downloadBoundCases as $part => [$rowId, $name, $file]) {
        $fakeDownloadMetaRows[17] = [serialize([
            $rowId => ['id' => $rowId, 'name' => $name, 'file' => $file, 'enabled' => true],
        ])];
        $downloadBoundMessage = '';
        try {
            $observeDownloads->invoke($adapter, [17]);
        } catch (\Throwable $failure) {
            $downloadBoundMessage = $failure->getMessage();
        }
        $check(str_contains($downloadBoundMessage, 'unsupported downloadable-file row'),
            "decoded download $part bytes have an explicit per-row bound");
    }
    unset($fakeDownloadMetaRows[17]);

    $fakeDownloadMetaRows[17] = [$downloadRow($downloadFile)];
    $wpdb->downloadMetaKeyOverride = '_DOWNLOADABLE_FILES';
    $downloadAliasMessage = '';
    try {
        $observeDownloads->invoke($adapter, [17]);
    } catch (\Throwable $failure) {
        $downloadAliasMessage = $failure->getMessage();
    }
    $wpdb->downloadMetaKeyOverride = null;
    $check(str_contains($downloadAliasMessage, 'malformed or aliased state'),
        'case-insensitive metadata lookup cannot bless a non-binary _downloadable_files alias');

    $sameLengthRacedDownload = $downloadRow(str_replace('catalog.pdf', 'catxlog.pdf', $downloadFile));
    $wpdb->afterDownloadWitness = static function () use (&$fakeDownloadMetaRows, $sameLengthRacedDownload): void {
        $fakeDownloadMetaRows[17] = [$sameLengthRacedDownload];
    };
    $downloadRaceMessage = '';
    try {
        $observeDownloads->invoke($adapter, [17]);
    } catch (\Throwable $failure) {
        $downloadRaceMessage = $failure->getMessage();
    }
    $fakeDownloadMetaRows[17] = [$downloadRow($downloadFile)];
    $check(str_contains($downloadRaceMessage, 'changed after its byte witness')
        || str_contains($downloadRaceMessage, 'identity changed after its byte witness'),
        'a same-length download metadata write between compact witness and payload read cannot be blessed');

    $wpdb->failReadContaining = ' AS meta_key, meta_value,';
    $downloadPayloadReadMessage = '';
    try {
        $observeDownloads->invoke($adapter, [17]);
    } catch (\Throwable $failure) {
        $downloadPayloadReadMessage = $failure->getMessage();
    }
    $wpdb->failReadContaining = null;
    $wpdb->last_error = '';
    unset($fakeDownloadMetaRows[17]);
    $check(str_contains($downloadPayloadReadMessage, 'checked read failed')
        && !str_contains($downloadPayloadReadMessage, $downloadFile),
        'download payload DB failure is loud and redacted after a valid compact witness');

    $downloadScopeArgs = [
        'entities' => [
            'entities' => [['kind' => 'post:product', 'id' => 17]],
            'always_on_write' => true,
            'deletions' => [],
            'reparents' => [],
            'retry' => true,
        ],
    ];
    $scopeOperation = ['fixture' => 'woocommerce-scoped-recovery'];
    $fakeDownloadWakeups = 0;
    $fakeMeta[17]['_downloadable_files'] = [serialize(new FakeDownloadWakeupCanary())];
    $approvalAddsBeforeObject = $register->adds;
    $objectFailure = '';
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $downloadScopeArgs, $scopeOperation);
    } catch (\Throwable $failure) {
        $objectFailure = $failure->getMessage();
    }
    $check($fakeDownloadWakeups === 0
        && $register->adds === $approvalAddsBeforeObject
        && str_contains($objectFailure, 'non-plain serialized data')
        && str_contains($objectFailure, 'downloadable-file metadata'),
        'scoped recovery rejects serialized download objects before __wakeup or approved-directory hooks execute');
    $fakeMeta[17]['_downloadable_files'] = [$downloadRow($downloadFile)];

    $lookupBeforeReadFailure = $fakeMetaLookup[17];
    $wpdb->failReadContaining = "meta_key = '_downloadable_files'";
    $readFailure = false;
    try {
        $adapter->regenerate_batch([17], []);
    } catch (\Throwable $failure) {
        $readFailure = str_contains($failure->getMessage(), 'checked read failed');
    }
    $wpdb->failReadContaining = null;
    $wpdb->last_error = '';
    $check($readFailure && $fakeMetaLookup[17] === $lookupBeforeReadFailure,
        'a failed fresh metadata read refuses before product lookup mutation and is safe to retry');

    $receipt = $adapter->invoke('rebuild_product_lookups', [
        'entities' => [
            'entities' => [['kind' => 'post:product', 'id' => 17]],
            'always_on_write' => true,
            'deletions' => [],
            'reparents' => [],
            'retry' => true,
        ],
    ]);
    $receiptBytes = serialize($receipt);
    $check(($receipt['after']['download_files'] ?? null) === 1
        && ($receipt['after']['usable_download_files'] ?? null) === 1
        && preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['after']['lookup_scope_sha256'] ?? '')) === 1
        && preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['after']['attribute_lookup_scope_sha256'] ?? '')) === 1
        && preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['after']['sale_schedule_scope_sha256'] ?? '')) === 1
        && preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['after']['download_scope_sha256'] ?? '')) === 1
        && !str_contains($receiptBytes, 'target.example') && !str_contains($receiptBytes, 'catalog.pdf'),
        'verified receipts stay bounded and bind exact meta/attribute lookup, sale, and download state without exposing authored values');

    $downloadScoped = $adapter->invoke_scoped(
        'rebuild_product_lookups',
        $downloadScopeArgs,
        $scopeOperation
    );
    $nativeBaseline = wc_get_product(17)->get_downloads();
    $nativeMismatchCases = [
        'extra identity' => $nativeBaseline + [
            'extra-download' => new FakeProductDownload(
                'extra-download',
                'Extra',
                $downloadFile,
                true
            ),
        ],
        'missing identity' => [],
        'same-count wrong name' => [
            $downloadId => new FakeProductDownload($downloadId, 'Wrong name', $downloadFile, true),
        ],
        'same-count wrong file' => [
            $downloadId => new FakeProductDownload(
                $downloadId,
                'Portable catalog 東京.pdf',
                $downloadFile . '&native-drift=1',
                true
            ),
        ],
        'same-count wrong enabled state' => [
            $downloadId => new FakeProductDownload(
                $downloadId,
                'Portable catalog 東京.pdf',
                $downloadFile,
                false
            ),
        ],
        'same-count wrong object id' => [
            $downloadId => new FakeProductDownload(
                'different-object-id',
                'Portable catalog 東京.pdf',
                $downloadFile,
                true
            ),
        ],
    ];
    foreach ($nativeMismatchCases as $case => $nativeOverride) {
        $fakeNativeDownloadOverrides[17] = $nativeOverride;
        $nativeMismatch = false;
        try {
            $adapter->reconcile_scoped('rebuild_product_lookups', $downloadScopeArgs, $scopeOperation);
        } catch (\Throwable $failure) {
            $nativeMismatch = str_contains($failure->getMessage(), 'native download');
        }
        unset($fakeNativeDownloadOverrides[17]);
        $check($nativeMismatch, "native download verification refuses $case");
    }

    $fakeMeta[17]['_downloadable_files'] = [$downloadRow($downloadFile . '&revision=2')];
    $downloadDrift = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $downloadScopeArgs,
        $scopeOperation
    );
    $check(($downloadDrift['after']['download_files'] ?? null)
            === ($downloadScoped['after']['download_files'] ?? null)
        && ($downloadDrift['after']['download_scope_sha256'] ?? null)
            !== ($downloadScoped['after']['download_scope_sha256'] ?? null),
        'same-count downloadable-file drift cannot match the saved scoped postcondition');
    $fakeMeta[17]['_downloadable_files'] = [$downloadRow($downloadFile, true, 'Changed download name')];
    $downloadNameDrift = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $downloadScopeArgs,
        $scopeOperation
    );
    $check(($downloadNameDrift['after']['download_files'] ?? null)
            === ($downloadScoped['after']['download_files'] ?? null)
        && ($downloadNameDrift['after']['download_scope_sha256'] ?? null)
            !== ($downloadScoped['after']['download_scope_sha256'] ?? null),
        'same-count downloadable-file name drift cannot match the saved scoped postcondition');
    $fakeMeta[17]['_downloadable_files'] = [$downloadRow($downloadFile)];
    $downloadRecovered = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $downloadScopeArgs,
        $scopeOperation
    );
    $check(($downloadRecovered['after'] ?? null) === ($downloadScoped['after'] ?? null),
        'restoring the exact downloadable-file state makes scoped recovery deterministic and retryable');

    // Woo publishes the uncoerced PHP price in its derivation cache while
    // MySQL assigns that value into DECIMAL(19,4). The verifier must accept
    // the installed schema's exact rounding and must not weaken comparison of
    // any non-DECIMAL column.
    $fakeMeta[16] = $fakeMeta[13];
    $fakeMeta[16]['_price'] = ['0'];
    $fakeMeta[16]['_regular_price'] = ['123456789.123456'];
    $fakeMeta[16]['_sale_price'] = [''];
    $fakeProducts[16] = new FakeProduct(16, 'simple', 0, [], [], true);
    fake_set_visibility_relationships(16, 'product_type', ['simple']);
    $fakeMetaLookup[16] = $fakeMetaLookup[10];
    $fakeMetaLookup[16]['product_id'] = 16;
    $adapter->regenerate_batch([16], []);
    $check($fakeMeta[16]['_price'] === ['123456789.123456'],
        'six-decimal authored price remains exact in WooCommerce postmeta');
    $check(($fakeMetaLookup[16]['min_price'] ?? null) === '123456789.1235'
        && ($fakeMetaLookup[16]['max_price'] ?? null) === '123456789.1235',
        'lookup verification accepts only the installed DECIMAL scale assignment');

    // Sale scheduling is a bounded product batch, not a catalog-wide scan.
    // Exercise a future end date, an unrelated no-date product, heartbeat
    // calls, exact Action Scheduler readback, and a verifier fault that must
    // fail closed before the retry is allowed to pass.
    $futureSaleTimestamp = new \ReflectionMethod($adapter, 'future_sale_timestamp');
    $clockNow = 1700000000;
    $check($futureSaleTimestamp->invoke($adapter, new FakeSaleDate($clockNow), $clockNow) === null
        && $futureSaleTimestamp->invoke($adapter, new FakeSaleDate($clockNow + 1), $clockNow) === $clockNow + 1,
        'sale timestamp projection uses an explicit clock seam at the exact now boundary');
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
        $saleVerificationFailedClosed = str_contains($failure->getMessage(), 'sale schedule APIs disagreed');
    }
    $fakeSaleVerificationFailure = false;
    $check($saleVerificationFailedClosed, 'sale schedule verification refuses a failed Action Scheduler readback');
    $adapter->regenerate_batch([13], []);
    $check(($fakeSaleSchedules['wc_product_end_scheduled_sale'][13] ?? null) === $futureSale,
        'sale scheduling retry restores the exact future action after a verification failure');

    $productScopeArgs = [
        'entities' => [
            'entities' => [['kind' => 'post:product', 'id' => 13]],
            'always_on_write' => true,
            'deletions' => [],
            'reparents' => [],
            'retry' => true,
        ],
    ];
    $fakeProducts[13]->set_attributes([
        'pa_color' => new FakeProductAttribute(13, [101], false),
    ]);
    unset($fakeProductCache[13]);
    $attributeStoreForRetry = wc_get_container()->get(
        \Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore::class
    );
    $attributeStoreForRetry->failNextCreates = 1;
    $attributeFailure = false;
    try {
        $adapter->invoke_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
    } catch (\Throwable $failure) {
        $attributeFailure = str_contains($failure->getMessage(), 'attribute lookup regeneration failed')
            && str_contains($failure->getMessage(), 'recovery_required');
    }
    $check($attributeFailure && !array_filter(
        $fakeAttrLookup,
        static fn(array $row): bool => (int) $row['product_or_parent_id'] === 13
    ), 'a partial native attribute lookup failure is loud and leaves retry authority active');
    $productScoped = $adapter->invoke_scoped(
        'rebuild_product_lookups',
        $productScopeArgs,
        $scopeOperation
    );
    $check(($productScoped['after']['attribute_lookup_rows'] ?? null) === 1
        && preg_match('/^[a-f0-9]{64}$/D', (string) ($productScoped['after']['attribute_lookup_scope_sha256'] ?? '')) === 1,
        'attribute lookup retry writes and receipt-binds the exact native scoped row');
    $exactLookupRow = $fakeMetaLookup[13];
    $fakeMetaLookup[13]['min_price'] = 'same-count-drift';
    $lookupDrift = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $productScopeArgs,
        $scopeOperation
    );
    $check(($lookupDrift['after']['meta_lookup_rows'] ?? null)
            === ($productScoped['after']['meta_lookup_rows'] ?? null)
        && ($lookupDrift['after']['lookup_scope_sha256'] ?? null)
            !== ($productScoped['after']['lookup_scope_sha256'] ?? null),
        'same-count lookup-value drift cannot match the saved scoped postcondition');
    $fakeMetaLookup[13] = $exactLookupRow;
    $lookupRecovered = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $productScopeArgs,
        $scopeOperation
    );
    $check(($lookupRecovered['after'] ?? null) === ($productScoped['after'] ?? null),
        'restoring every lookup value recovers the exact scoped postcondition');

    $attributeRowKey = array_key_first(array_filter(
        $fakeAttrLookup,
        static fn(array $row): bool => (int) $row['product_or_parent_id'] === 13
    ));
    $exactAttributeRow = $attributeRowKey === null ? null : $fakeAttrLookup[$attributeRowKey];
    if ($attributeRowKey !== null) {
        $fakeAttrLookup[$attributeRowKey]['term_id'] = 102;
    }
    $attributeDrift = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $productScopeArgs,
        $scopeOperation
    );
    $check($exactAttributeRow !== null
        && ($attributeDrift['after']['attribute_lookup_rows'] ?? null)
            === ($productScoped['after']['attribute_lookup_rows'] ?? null)
        && ($attributeDrift['after']['attribute_lookup_scope_sha256'] ?? null)
            !== ($productScoped['after']['attribute_lookup_scope_sha256'] ?? null),
        'same-count product attribute lookup drift cannot match the saved scoped postcondition');
    if ($attributeRowKey !== null && is_array($exactAttributeRow)) {
        $fakeAttrLookup[$attributeRowKey] = $exactAttributeRow;
    }
    $attributeRecovered = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $productScopeArgs,
        $scopeOperation
    );
    $check(($attributeRecovered['after'] ?? null) === ($productScoped['after'] ?? null),
        'restoring exact attribute lookup rows recovers the scoped postcondition');

    $cogsController = wc_get_container()->get(
        \Automattic\WooCommerce\Internal\CostOfGoodsSold\CostOfGoodsSoldController::class
    );
    $fakeMeta[13]['_cogs_total_value'] = ['7.12555'];
    $cogsLookupBeforeRefusal = $fakeMetaLookup[13];
    $cogsDisabledMessage = '';
    try {
        $adapter->invoke_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
    } catch (\Throwable $failure) {
        $cogsDisabledMessage = $failure->getMessage();
    }
    $check(str_contains($cogsDisabledMessage, 'Cost of Goods is disabled')
        && str_contains($cogsDisabledMessage, '1 scoped authored row')
        && !str_contains($cogsDisabledMessage, '7.12555')
        && $fakeMetaLookup[13] === $cogsLookupBeforeRefusal,
        'authored COGS refuses before provider mutation when the target native feature is disabled');

    $cogsController->enabled = true;
    $cogsMissingColumnMessage = '';
    try {
        $adapter->invoke_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
    } catch (\Throwable $failure) {
        $cogsMissingColumnMessage = $failure->getMessage();
    }
    $check(str_contains($cogsMissingColumnMessage, 'lookup column is absent')
        && str_contains($cogsMissingColumnMessage, 'native WooCommerce COGS column tool')
        && !str_contains($cogsMissingColumnMessage, '7.12555')
        && $fakeMetaLookup[13] === $cogsLookupBeforeRefusal,
        'authored COGS refuses without an exact native lookup-column boundary and names the recovery path');

    $cogsController->lookupColumnPresent = true;
    $cogsScoped = $adapter->invoke_scoped(
        'rebuild_product_lookups',
        $productScopeArgs,
        $scopeOperation
    );
    $check(($fakeMetaLookup[13]['cogs_total_value'] ?? null) === '7.1256'
        && ($cogsScoped['after']['cogs_authored_rows'] ?? null) === 1
        && ($cogsScoped['after']['cogs_typed_products'] ?? null) === 1
        && ($cogsScoped['after']['cogs_feature_enabled'] ?? null) === 1
        && ($cogsScoped['after']['cogs_lookup_column_present'] ?? null) === 1
        && preg_match('/^[a-f0-9]{64}$/D', (string) ($cogsScoped['after']['cogs_scope_sha256'] ?? '')) === 1
        && !str_contains(serialize($cogsScoped), '7.12555'),
        'enabled COGS delegates five-decimal rounding to Woo/MySQL and binds redacted exact scoped evidence');

    $simpleProduct = $fakeProducts[13];
    $fakeProducts[13] = new FakeProduct(13, 'external', 0, [], [], true);
    fake_set_visibility_relationships(13, 'product_type', ['external']);
    unset($fakeProductCache[13]);
    $cogsTypeDrift = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $productScopeArgs,
        $scopeOperation
    );
    $check(($cogsTypeDrift['after']['cogs_authored_rows'] ?? null)
            === ($cogsScoped['after']['cogs_authored_rows'] ?? null)
        && ($cogsTypeDrift['after']['cogs_scope_sha256'] ?? null)
            !== ($cogsScoped['after']['cogs_scope_sha256'] ?? null),
        'the exact native WC product subtype is part of the scoped COGS receipt even when row count and value are unchanged');
    $fakeProducts[13] = $simpleProduct;
    fake_set_visibility_relationships(13, 'product_type', ['simple']);
    unset($fakeProductCache[13]);
    $cogsTypeRecovered = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $productScopeArgs,
        $scopeOperation
    );
    $check(($cogsTypeRecovered['after'] ?? null) === ($cogsScoped['after'] ?? null),
        'restoring the native WC product subtype recovers the exact scoped COGS postcondition');

    $fakeMeta[13]['_cogs_value_is_additive'] = ['yes'];
    $simpleAdditiveMessage = '';
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
    } catch (\Throwable $failure) {
        $simpleAdditiveMessage = $failure->getMessage();
    }
    unset($fakeMeta[13]['_cogs_value_is_additive']);
    $check(str_contains($simpleAdditiveMessage, 'variation-only additive Cost of Goods metadata')
        && !str_contains($simpleAdditiveMessage, '7.12555'),
        'a hostile post-materialization additive row on a simple product cannot verify or retire recovery');

    $fakeMeta[13]['_cogs_total_value'] = ['0'];
    $simpleZeroMessage = '';
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
    } catch (\Throwable $failure) {
        $simpleZeroMessage = $failure->getMessage();
    }
    $fakeMeta[13]['_cogs_total_value'] = ['7.12555'];
    $check(str_contains($simpleZeroMessage, 'base product 13 has a Cost of Goods zero that native storage deletes')
        && !str_contains($simpleZeroMessage, '7.12555'),
        'a hostile post-materialization base-product zero cannot verify or retire recovery');

    $fakePostTypeOverrides[13] = 'product_variation';
    $inconsistentSubtypeMessage = '';
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
    } catch (\Throwable $failure) {
        $inconsistentSubtypeMessage = $failure->getMessage();
    }
    unset($fakePostTypeOverrides[13]);
    $check(str_contains($inconsistentSubtypeMessage, 'inconsistent native product subtype')
        && !str_contains($inconsistentSubtypeMessage, '7.12555'),
        'posts-table and native WC subtype disagreement refuses with redacted diagnostics');

    $fakeMeta[13]['_cogs_total_value'] = ['8.12555'];
    $cogsDrift = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $productScopeArgs,
        $scopeOperation
    );
    $check(($cogsDrift['after']['cogs_authored_rows'] ?? null)
            === ($cogsScoped['after']['cogs_authored_rows'] ?? null)
        && ($cogsDrift['after']['cogs_scope_sha256'] ?? null)
            !== ($cogsScoped['after']['cogs_scope_sha256'] ?? null),
        'same-count COGS drift cannot match the saved scoped postcondition');
    $fakeMeta[13]['_cogs_total_value'] = ['7.12555'];
    $cogsRecovered = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $productScopeArgs,
        $scopeOperation
    );
    $check(($cogsRecovered['after'] ?? null) === ($cogsScoped['after'] ?? null),
        'restoring exact authored COGS recovers the complete scoped postcondition');

    foreach ([
        '1.23454' => '1.2345',
        '1.23455' => '1.2346',
        '-1.23455' => '-1.2346',
        '1.234565' => '1.2346',
        '1.0E-7' => '0.0000',
        '9.999999999999E+14' => '999999999999900.0000',
    ] as $rawCogs => $lookupCogs) {
        $fakeMeta[13]['_cogs_total_value'] = [$rawCogs];
        $adapter->invoke_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
        $check(($fakeMetaLookup[13]['cogs_total_value'] ?? null) === $lookupCogs,
            "native COGS postmeta $rawCogs reaches exact DECIMAL(19,4) lookup value $lookupCogs");
    }
    $fakeMeta[13]['_cogs_total_value'] = ['7.12555'];
    $adapter->invoke_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);

    $variationScopeArgs = [
        'entities' => [
            'entities' => [['kind' => 'post:product_variation', 'id' => 11]],
            'always_on_write' => true,
            'deletions' => [],
            'reparents' => [],
            'retry' => true,
        ],
    ];
    $fakeMeta[11]['_cogs_total_value'] = ['0'];
    $fakeMeta[11]['_cogs_value_is_additive'] = ['yes'];
    $fakeMetaLookup[11]['cogs_total_value'] = '0.0000';
    unset($fakeProductCache[11]);
    $variationCogs = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $variationScopeArgs,
        $scopeOperation
    );
    $check(($variationCogs['after']['cogs_authored_rows'] ?? null) === 2
        && ($variationCogs['after']['cogs_typed_products'] ?? null) === 1
        && ($fakeMetaLookup[11]['cogs_total_value'] ?? null) === '0.0000',
        'native variation subtype preserves explicit zero and the additive marker through scoped recovery verification');
    unset(
        $fakeMeta[11]['_cogs_total_value'],
        $fakeMeta[11]['_cogs_value_is_additive'],
        $fakeMetaLookup[11]['cogs_total_value']
    );

    $fakeLookupWriteFaults[13] = ['cogs_total_value' => '9.9999'];
    $cogsWriteFailure = '';
    try {
        $adapter->invoke_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
    } catch (\Throwable $failure) {
        $cogsWriteFailure = $failure->getMessage();
    }
    unset($fakeLookupWriteFaults[13]);
    $check(str_contains($cogsWriteFailure, 'product lookup verification mismatch')
        && !str_contains($cogsWriteFailure, '7.12555'),
        'a failed rounded COGS lookup write refuses with bounded digests and leaves retry authority');
    $adapter->invoke_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
    $check(($fakeMetaLookup[13]['cogs_total_value'] ?? null) === '7.1256',
        'rounded COGS lookup retry converges after the injected write failure');

    $cogsController->enabled = false;
    $cogsDisabledAgain = '';
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
    } catch (\Throwable $failure) {
        $cogsDisabledAgain = $failure->getMessage();
    }
    $cogsController->enabled = true;
    $cogsReenabled = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $productScopeArgs,
        $scopeOperation
    );
    $check(str_contains($cogsDisabledAgain, 'Cost of Goods is disabled')
        && ($cogsReenabled['after']['cogs_scope_sha256'] ?? null)
            === ($cogsScoped['after']['cogs_scope_sha256'] ?? null),
        'target feature disable is loud and re-enable recovers the exact authored COGS receipt');

    $fakeCogsMetaRows[13]['_cogs_total_value'] = ['7.12555', '7.12555'];
    $duplicateCogsMessage = '';
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
    } catch (\Throwable $failure) {
        $duplicateCogsMessage = $failure->getMessage();
    }
    unset($fakeCogsMetaRows[13]);
    $check(str_contains($duplicateCogsMessage, 'multiple _cogs_total_value rows')
        && !str_contains($duplicateCogsMessage, '7.12555'),
        'duplicate COGS rows refuse without choosing or disclosing a value');

    $cogsOversizeMarker = 'cogs_oversize_secret_DO_NOT_ECHO';
    $fakeCogsMetaRows[13]['_cogs_total_value'] = [
        $cogsOversizeMarker . str_repeat('9', 129 - strlen($cogsOversizeMarker)),
    ];
    $cogsOversizeMessage = '';
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
    } catch (\Throwable $failure) {
        $cogsOversizeMessage = $failure->getMessage();
    }
    unset($fakeCogsMetaRows[13]);
    $check(str_contains($cogsOversizeMessage, 'oversized authored Cost of Goods metadata')
        && !str_contains($cogsOversizeMessage, $cogsOversizeMarker),
        'oversized COGS is refused from a prefix/length witness without transferring or disclosing the full value');

    $fakeCogsMetaRows[13]['_cogs_total_value'] = ['7', '8', '9'];
    $cogsSaturationMessage = '';
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
    } catch (\Throwable $failure) {
        $cogsSaturationMessage = $failure->getMessage();
    }
    unset($fakeCogsMetaRows[13]);
    $check(str_contains($cogsSaturationMessage, 'saturated its bounded row read'),
        'hostile duplicate COGS rows cannot exceed the two-keys-per-owner transfer bound');

    $fakeCogsMetaRows[13]['_cogs_total_value'] = ['1.0E+15'];
    $overflowCogsMessage = '';
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
    } catch (\Throwable $failure) {
        $overflowCogsMessage = $failure->getMessage();
    }
    unset($fakeCogsMetaRows[13]);
    $check(str_contains($overflowCogsMessage, 'malformed authored Cost of Goods metadata')
        && !str_contains($overflowCogsMessage, '1.0E+15'),
        'a target-float value that would overflow DECIMAL(19,4) refuses without disclosure');

    $fakeCogsMetaRows[13]['_cogs_total_value'] = ['cogs_secret_marker_DO_NOT_ECHO'];
    $malformedCogsMessage = '';
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
    } catch (\Throwable $failure) {
        $malformedCogsMessage = $failure->getMessage();
    }
    unset($fakeCogsMetaRows[13]);
    $check(str_contains($malformedCogsMessage, 'malformed authored Cost of Goods metadata')
        && !str_contains($malformedCogsMessage, 'cogs_secret_marker_DO_NOT_ECHO'),
        'malformed COGS refuses through the executable boundary with redacted diagnostics');

    foreach (['1.2300', '1.0E+3', '0E+9'] as $nonWriterCogs) {
        $fakeCogsMetaRows[13]['_cogs_total_value'] = [$nonWriterCogs];
        $nonWriterMessage = '';
        try {
            $adapter->reconcile_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
        } catch (\Throwable $failure) {
            $nonWriterMessage = $failure->getMessage();
        }
        $check(str_contains($nonWriterMessage, 'malformed authored Cost of Goods metadata')
            && !str_contains($nonWriterMessage, $nonWriterCogs),
            "non-writer-equivalent COGS spelling $nonWriterCogs is refused and redacted at reconciliation");
    }
    unset($fakeCogsMetaRows[13]);

    unset($fakeMeta[13]['_cogs_total_value'], $fakeMetaLookup[13]['cogs_total_value']);
    $cogsController->enabled = false;
    $cogsController->lookupColumnPresent = false;

    $observeLookup = new \ReflectionMethod($adapter, 'observe_lookup_state');
    $lookupExtra = [];
    foreach (array_keys(FakeWpdb::LOOKUP_COLUMN_DECLARATIONS) as $column) {
        $lookupExtra[$column] = array_key_exists($column, $fakeMetaLookup[13])
            ? ($fakeMetaLookup[13][$column] === null ? null : (string) $fakeMetaLookup[13][$column])
            : null;
    }
    $wpdb->lookupReceiptExtraRows = [$lookupExtra];
    $lookupSaturationMessage = '';
    try {
        $observeLookup->invoke($adapter, [13]);
    } catch (\Throwable $failure) {
        $lookupSaturationMessage = $failure->getMessage();
    }
    $wpdb->lookupReceiptExtraRows = [];
    $check(str_contains($lookupSaturationMessage, 'saturated its bounded owner scope'),
        'duplicate product lookup rows cannot exceed the one-row-per-owner receipt transfer bound');

    $lookupSecret = 'lookup_secret_marker_DO_NOT_ECHO';
    $originalSku = $fakeMetaLookup[13]['sku'];
    $fakeMetaLookup[13]['sku'] = $lookupSecret . str_repeat('x', 1025 - strlen($lookupSecret));
    $lookupOversizeMessage = '';
    try {
        $observeLookup->invoke($adapter, [13]);
    } catch (\Throwable $failure) {
        $lookupOversizeMessage = $failure->getMessage();
    }
    $fakeMetaLookup[13]['sku'] = $originalSku;
    $check(str_contains($lookupOversizeMessage, 'oversized scalar')
        && !str_contains($lookupOversizeMessage, $lookupSecret),
        'oversized lookup scalars refuse without entering receipts or diagnostics');

    $schemaAdapter = new \Duo\Providers\WoocommerceProductLookups($productProviderDeclaration);
    $schemaObserver = new \ReflectionMethod($schemaAdapter, 'observe_lookup_state');
    $wpdb->lookupColumnDeclarations = FakeWpdb::LOOKUP_COLUMN_DECLARATIONS + [
        'extension_secret' => 'longtext',
    ];
    $fakeMetaLookup[13]['extension_secret'] = 'extension_secret_marker_DO_NOT_TRANSFER';
    $fakeVisibilityQueries = [];
    $extensionState = $schemaObserver->invoke($schemaAdapter, [13]);
    $extensionReceiptQueries = array_filter(
        $fakeVisibilityQueries,
        static fn(string $query): bool => str_contains($query, 'FROM `wp_wc_product_meta_lookup`')
    );
    unset($fakeMetaLookup[13]['extension_secret']);
    $check(($extensionState['meta_lookup_rows'] ?? null) === 1
        && !array_filter(
            $extensionReceiptQueries,
            static fn(string $query): bool => str_contains($query, 'extension_secret')
        ), 'unknown extension lookup columns remain target-owned and are never selected into Duo receipt memory');

    $wpdb->lookupColumnDeclarations = FakeWpdb::LOOKUP_COLUMN_DECLARATIONS;
    $badTypeDeclarations = FakeWpdb::LOOKUP_COLUMN_DECLARATIONS;
    $badTypeDeclarations['sku'] = 'longtext';
    $wpdb->lookupColumnDeclarations = $badTypeDeclarations;
    $badTypeMessage = '';
    try {
        $badTypeAdapter = new \Duo\Providers\WoocommerceProductLookups($productProviderDeclaration);
        (new \ReflectionMethod($badTypeAdapter, 'observe_lookup_state'))->invoke($badTypeAdapter, [13]);
    } catch (\Throwable $failure) {
        $badTypeMessage = $failure->getMessage();
    }
    $check(str_contains($badTypeMessage, "core column 'sku' has an incompatible type"),
        'lookup receipt projection refuses an incompatible exact core column type');

    $wpdb->lookupColumnDeclarations = FakeWpdb::LOOKUP_COLUMN_DECLARATIONS;
    unset($wpdb->lookupColumnDeclarations['tax_class']);
    $missingCoreMessage = '';
    try {
        $missingCoreAdapter = new \Duo\Providers\WoocommerceProductLookups($productProviderDeclaration);
        (new \ReflectionMethod($missingCoreAdapter, 'observe_lookup_state'))->invoke($missingCoreAdapter, [13]);
    } catch (\Throwable $failure) {
        $missingCoreMessage = $failure->getMessage();
    }
    $check(str_contains($missingCoreMessage, 'missing one or more exact core columns'),
        'lookup receipt projection refuses a partial core schema');

    $wpdb->lookupColumnDeclarations = FakeWpdb::LOOKUP_COLUMN_DECLARATIONS;
    $wpdb->lookupSchemaRowsOverride = array_map(
        static fn(string $column, string $type): array => ['Field' => $column, 'Type' => $type],
        array_keys(FakeWpdb::LOOKUP_COLUMN_DECLARATIONS),
        array_values(FakeWpdb::LOOKUP_COLUMN_DECLARATIONS)
    );
    $wpdb->lookupSchemaRowsOverride[] = ['Field' => 'product_id', 'Type' => 'bigint(20)'];
    $duplicateSchemaMessage = '';
    try {
        $duplicateSchemaAdapter = new \Duo\Providers\WoocommerceProductLookups($productProviderDeclaration);
        (new \ReflectionMethod($duplicateSchemaAdapter, 'observe_lookup_state'))->invoke($duplicateSchemaAdapter, [13]);
    } catch (\Throwable $failure) {
        $duplicateSchemaMessage = $failure->getMessage();
    }
    $wpdb->lookupSchemaRowsOverride = null;
    $check(str_contains($duplicateSchemaMessage, 'unusable column name'),
        'duplicate schema rows cannot overwrite a prior core-column declaration');

    $oversizedSchema = FakeWpdb::LOOKUP_COLUMN_DECLARATIONS;
    for ($columnIndex = count($oversizedSchema); $columnIndex < 4097; $columnIndex++) {
        $oversizedSchema['extension_' . $columnIndex] = 'longtext';
    }
    $wpdb->lookupColumnDeclarations = $oversizedSchema;
    $fakeVisibilityQueries = [];
    $oversizedSchemaMessage = '';
    try {
        $oversizedSchemaAdapter = new \Duo\Providers\WoocommerceProductLookups($productProviderDeclaration);
        (new \ReflectionMethod($oversizedSchemaAdapter, 'observe_lookup_state'))->invoke($oversizedSchemaAdapter, [13]);
    } catch (\Throwable $failure) {
        $oversizedSchemaMessage = $failure->getMessage();
    }
    unset($oversizedSchema);
    $check(str_contains($oversizedSchemaMessage, 'oversized column inventory')
        && (bool) array_filter(
            $fakeVisibilityQueries,
            static fn(string $query): bool => str_contains($query, 'information_schema.COLUMNS')
                && str_contains($query, 'LIMIT 4097')
        ), 'hostile lookup schemas are cut off by an exact bounded information-schema projection');

    $wpdb->lookupColumnDeclarations = FakeWpdb::LOOKUP_COLUMN_DECLARATIONS;
    $wpdb->failReadContaining = 'information_schema.COLUMNS';
    $schemaReadMessage = '';
    try {
        $schemaReadAdapter = new \Duo\Providers\WoocommerceProductLookups($productProviderDeclaration);
        (new \ReflectionMethod($schemaReadAdapter, 'observe_lookup_state'))->invoke($schemaReadAdapter, [13]);
    } catch (\Throwable $failure) {
        $schemaReadMessage = $failure->getMessage();
    }
    $wpdb->failReadContaining = null;
    $wpdb->last_error = '';
    $check(str_contains($schemaReadMessage, 'checked read failed')
        && !str_contains($schemaReadMessage, 'information_schema'),
        'lookup schema DB failure is loud while its SQL and driver detail remain redacted');

    $wpdb->prefix = 'wp_bad`identifier_';
    $badTableMessage = '';
    try {
        $badTableAdapter = new \Duo\Providers\WoocommerceProductLookups($productProviderDeclaration);
        (new \ReflectionMethod($badTableAdapter, 'observe_lookup_state'))->invoke($badTableAdapter, [13]);
    } catch (\Throwable $failure) {
        $badTableMessage = $failure->getMessage();
    }
    $wpdb->prefix = 'wp_';
    $check(str_contains($badTableMessage, 'unusable WooCommerce product lookup table name'),
        'a backtick-bearing database prefix is refused before lookup-schema interpolation');

    $attributeRowKey = array_key_first(array_filter(
        $fakeAttrLookup,
        static fn(array $row): bool => (int) $row['product_or_parent_id'] === 13
    ));
    $duplicateAttributeRow = $fakeAttrLookup[$attributeRowKey] ?? null;
    if (is_array($duplicateAttributeRow)) {
        $fakeAttrLookup[] = $duplicateAttributeRow;
    }
    $duplicateAttributeRefused = false;
    try {
        $adapter->reconcile_scoped('rebuild_product_lookups', $productScopeArgs, $scopeOperation);
    } catch (\Throwable $failure) {
        $duplicateAttributeRefused = str_contains($failure->getMessage(), 'duplicate scoped rows');
    }
    if (is_array($duplicateAttributeRow)) {
        array_pop($fakeAttrLookup);
    }
    $check($duplicateAttributeRefused,
        'impossible duplicate native attribute rows refuse instead of collapsing into one receipt row');

    // Chunk by root ownership, not by a product/root OR query. With 205 roots
    // followed by 205 high-id children, the old query returned the first 195
    // variation rows once by root in chunk one and again by product in chunk
    // two, falsely diagnosing duplicate database rows.
    $largeAttributeScope = [];
    for ($i = 0; $i < 205; $i++) {
        $rootId = 10000 + $i;
        $childId = 20000 + $i;
        $fakeProducts[$rootId] = new FakeProduct($rootId, 'variable', 0, [$childId], [], true);
        $fakeProducts[$childId] = new FakeProduct($childId, 'variation', $rootId, [], [], true);
        $largeAttributeScope[] = $rootId;
        $largeAttributeScope[] = $childId;
        $fakeAttrLookup[] = [
            'product_id' => $childId,
            'product_or_parent_id' => $rootId,
            'taxonomy' => 'pa_color',
            'term_id' => 101,
            'is_variation_attribute' => 1,
            'in_stock' => 1,
        ];
    }
    $observeAttributes = new \ReflectionMethod($adapter, 'observe_attribute_lookup_state');
    $largeAttributeState = $observeAttributes->invoke($adapter, $largeAttributeScope);
    $check(($largeAttributeState['attribute_lookup_products'] ?? null) === 410
        && ($largeAttributeState['attribute_lookup_rows'] ?? null) === 205,
        'attribute receipt observation crosses the 200-id boundary without query-overlap duplicates');
    $fakeAttrLookup = array_values(array_filter(
        $fakeAttrLookup,
        static fn(array $row): bool => (int) $row['product_or_parent_id'] < 10000
    ));
    foreach ($largeAttributeScope as $id) {
        unset($fakeProducts[$id], $fakeProductCache[$id]);
    }

    $wpdb->attributeCountOverride = 200001;
    $attributeBoundMessage = '';
    try {
        $observeAttributes->invoke($adapter, [13]);
    } catch (\Throwable $failure) {
        $attributeBoundMessage = $failure->getMessage();
    }
    $wpdb->attributeCountOverride = null;
    $check(str_contains($attributeBoundMessage, 'cardinality exceeds its bounded count'),
        'attribute lookup cardinality is refused from a compact COUNT witness before row transfer');

    $wpdb->attributeCountOverride = '0001';
    $attributeNoncanonicalCountMessage = '';
    try {
        $observeAttributes->invoke($adapter, [13]);
    } catch (\Throwable $failure) {
        $attributeNoncanonicalCountMessage = $failure->getMessage();
    }
    $wpdb->attributeCountOverride = null;
    $check(str_contains($attributeNoncanonicalCountMessage, 'not a canonical database integer'),
        'malformed attribute COUNT bytes cannot be loosely cast into a trusted transfer limit');

    $wpdb->attributePayloadDropLast = true;
    $attributeRaceMessage = '';
    try {
        $observeAttributes->invoke($adapter, [13]);
    } catch (\Throwable $failure) {
        $attributeRaceMessage = $failure->getMessage();
    }
    $wpdb->attributePayloadDropLast = false;
    $check(str_contains($attributeRaceMessage, 'changed after its cardinality witness'),
        'attribute lookup rows disappearing after the COUNT witness cannot produce a false receipt');

    $attributeRaceKey = array_key_first(array_filter(
        $fakeAttrLookup,
        static fn(array $row): bool => (int) $row['product_or_parent_id'] === 13
    ));
    $originalAttributeRaceRow = $fakeAttrLookup[$attributeRaceKey];
    $wpdb->afterAttributePayload = static function () use (&$fakeAttrLookup, $attributeRaceKey): void {
        $fakeAttrLookup[$attributeRaceKey]['term_id'] = 102;
    };
    $attributeSameCountRaceMessage = '';
    try {
        $observeAttributes->invoke($adapter, [13]);
    } catch (\Throwable $failure) {
        $attributeSameCountRaceMessage = $failure->getMessage();
    }
    $fakeAttrLookup[$attributeRaceKey] = $originalAttributeRaceRow;
    $check(str_contains($attributeSameCountRaceMessage, 'changed during bounded readback'),
        'same-count attribute row replacement between cardinality/payload reads cannot be blessed');

    $wpdb->failReadContaining = 'SELECT COUNT(*) FROM `wp_wc_product_attributes_lookup`';
    $attributeCountReadMessage = '';
    try {
        $observeAttributes->invoke($adapter, [13]);
    } catch (\Throwable $failure) {
        $attributeCountReadMessage = $failure->getMessage();
    }
    $wpdb->failReadContaining = null;
    $wpdb->last_error = '';
    $check(str_contains($attributeCountReadMessage, 'checked read failed')
        && !str_contains($attributeCountReadMessage, 'wc_product_attributes_lookup'),
        'attribute cardinality DB failure is loud with bounded redacted diagnostics');

    $wpdb->failReadContaining = 'SELECT product_id, product_or_parent_id, taxonomy';
    $attributePayloadReadMessage = '';
    try {
        $observeAttributes->invoke($adapter, [13]);
    } catch (\Throwable $failure) {
        $attributePayloadReadMessage = $failure->getMessage();
    }
    $wpdb->failReadContaining = null;
    $wpdb->last_error = '';
    $check(str_contains($attributePayloadReadMessage, 'checked read failed')
        && !str_contains($attributePayloadReadMessage, 'wc_product_attributes_lookup'),
        'attribute payload DB failure cannot turn its prior cardinality witness into evidence');

    $attributeOwnerBoundMessage = '';
    try {
        $observeAttributes->invoke($adapter, range(1, 50001));
    } catch (\Throwable $failure) {
        $attributeOwnerBoundMessage = $failure->getMessage();
    }
    $check(str_contains($attributeOwnerBoundMessage, 'exceeds its bounded product scope'),
        'attribute lookup owner expansion refuses an over-bound repository scope before SQL');

    $fakeSaleSchedules['wc_product_end_scheduled_sale'][13] = $futureSale + 1;
    $saleDrift = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $productScopeArgs,
        $scopeOperation
    );
    $check(($saleDrift['after']['sale_schedule_actions'] ?? null)
            === ($productScoped['after']['sale_schedule_actions'] ?? null)
        && ($saleDrift['after']['sale_schedule_scope_sha256'] ?? null)
            !== ($productScoped['after']['sale_schedule_scope_sha256'] ?? null),
        'same-count scheduled-sale timestamp drift cannot match the saved scoped postcondition');
    $fakeSaleSchedules['wc_product_end_scheduled_sale'][13] = $futureSale;
    $saleRecovered = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $productScopeArgs,
        $scopeOperation
    );
    $check(($saleRecovered['after'] ?? null) === ($productScoped['after'] ?? null),
        'restoring exact scheduled-sale timestamps recovers the saved scoped postcondition');

    $fakeSaleScheduleDuplicates['wc_product_end_scheduled_sale'][13] = 1;
    $verifySaleSchedules = new \ReflectionMethod($adapter, 'verify_sale_schedules');
    $duplicateSaleRefused = false;
    try {
        $verifySaleSchedules->invoke($adapter, [13], [], [13 => wc_get_product(13)], null);
    } catch (\Throwable $failure) {
        $duplicateSaleRefused = str_contains($failure->getMessage(), 'cardinality or timestamp mismatch');
    }
    $duplicateSaleDrift = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $productScopeArgs,
        $scopeOperation
    );
    $check($duplicateSaleRefused,
        'exact sale verification refuses a duplicate action even when the earliest timestamp is correct');
    $check(($duplicateSaleDrift['after']['sale_schedule_actions'] ?? null)
            !== ($productScoped['after']['sale_schedule_actions'] ?? null)
        && ($duplicateSaleDrift['after']['sale_schedule_scope_sha256'] ?? null)
            !== ($productScoped['after']['sale_schedule_scope_sha256'] ?? null),
        'duplicate active sale actions cannot match or retire the saved scoped postcondition');
    unset($fakeSaleScheduleDuplicates['wc_product_end_scheduled_sale'][13]);
    $duplicateSaleRecovered = $adapter->reconcile_scoped(
        'rebuild_product_lookups',
        $productScopeArgs,
        $scopeOperation
    );
    $check(($duplicateSaleRecovered['after'] ?? null) === ($productScoped['after'] ?? null),
        'removing the duplicate action restores the exact scoped sale postcondition');

    $groupedDiscovery = new \ReflectionMethod($adapter, 'find_grouped_parent_ids');
    $wpdb->failReadContaining = "meta_key = '_children'";
    $groupedReadFailedClosed = false;
    try {
        $groupedDiscovery->invoke($adapter, 11);
    } catch (\Throwable $failure) {
        $message = $failure->getMessage();
        $groupedReadFailedClosed = str_contains($message, 'duo: provider checked read failed: grouped parent discovery')
            && !str_contains($message, 'meta_key =')
            && !str_contains($message, 'simulated read failure')
            && !str_contains($message, 'WooCommerce');
    }
    $wpdb->failReadContaining = null;
    $check($groupedReadFailedClosed,
        'grouped-parent discovery fails closed when its database read fails');

    $wpdb->groupedParentOverride = range(1, 50001);
    $groupedBoundMessage = '';
    try {
        $groupedDiscovery->invoke($adapter, 11);
    } catch (\Throwable $failure) {
        $groupedBoundMessage = $failure->getMessage();
    }
    $wpdb->groupedParentOverride = null;
    $check(str_contains($groupedBoundMessage, 'exceeds its bounded owner scope'),
        'grouped-parent reverse discovery stops at MAX+1 instead of transferring a catalog-wide result');

    $wpdb->groupedParentOverride = ['0007'];
    $groupedMalformedMessage = '';
    try {
        $groupedDiscovery->invoke($adapter, 11);
    } catch (\Throwable $failure) {
        $groupedMalformedMessage = $failure->getMessage();
    }
    $wpdb->groupedParentOverride = null;
    $check(str_contains($groupedMalformedMessage, 'not a canonical database integer'),
        'grouped-parent IDs use canonical bounded integer parsing rather than loose casts');

    $verifyExactState = new \ReflectionMethod($adapter, 'verify_exact_state');
    $verifyExactStateArgs = [
        WC_Data_Store::load('product'),
        [],
        [],
        [999 => 999],
        ['ids' => [], 'children' => [], 'visible_children' => []],
    ];
    $wpdb->failReadContaining = 'wc_product_meta_lookup';
    $deletionReadFailedClosed = false;
    try {
        $verifyExactState->invokeArgs($adapter, $verifyExactStateArgs);
    } catch (\Throwable $failure) {
        $message = $failure->getMessage();
        $deletionReadFailedClosed = str_contains(
            $message,
            'duo: provider checked read failed: product lookup deletion verification for product 999'
        )
            && !str_contains($message, 'wc_product_meta_lookup')
            && !str_contains($message, 'simulated read failure')
            && !str_contains($message, 'WooCommerce');
    }
    $wpdb->failReadContaining = null;
    $check($deletionReadFailedClosed,
        'lookup deletion verification cannot clear a receipt after a failed count query');

    $attributeStoreProbe = wc_get_container()->get(
        \Automattic\WooCommerce\Internal\ProductAttributesLookup\LookupDataStore::class
    );
    $check($attributeStoreProbe->createCalls > 0 && $attributeStoreProbe->deleteCalls === 0,
        'verified product repair invokes Woo public scoped attribute synthesis, never its internal delete callback');

    $check($fakeMeta[11]['_price'] === ['18'], 'stale child _price is recomputed from regular/sale inputs');
    $check($fakeMeta[10]['_price'] === ['18', '21'], 'variable parent _price is synchronized from distinct child prices');
    $check($fakeMeta[10]['_regular_price'] === [''] && $fakeMeta[10]['_sale_price'] === [''],
        'variable sync preserves explicit empty authored parent price rows');
    $check((WC_Data_Store::$variable?->calls ?? 0) === 1, 'variable root synchronization is deduplicated');
    $check($fakeMetaLookup[11]['min_price'] === '18', 'existing stale wc_product_meta_lookup row is refreshed');
    $check($fakeMetaLookup[11]['onsale'] === 1, 'onsale follows Woo sale-price/effective-price equality');
    $check($fakeMeta[11]['_stock'] === ['5'], 'target-local runtime stock meta is preserved');
    $check(in_array($unrelatedAttributeRow, $fakeAttrLookup, true),
        'scoped attribute lookup repair preserves an unrelated target-only row byte-for-byte');
    $check($fakeTermQueries !== [], 'verified provider delegates attribute term derivation to Woo native lookup synthesis');
    $remainingTermFilters = array_filter(
        $fakeFilters['get_terms_args'][1] ?? [],
        static fn(array $entry): bool => isset($entry[0])
    );
    $check($remainingTermFilters === [], 'all-language term filter is removed after synchronous generation');
    $check(in_array('product_10', WC_Cache_Helper::$invalidatedGroups, true)
        && in_array('product_11', WC_Cache_Helper::$invalidatedGroups, true),
        'Woo cache-helper invalidates the affected product groups');
    $check(in_array('pa_grind-size', $fakeRegisteredTaxonomies, true)
        && in_array('pa_color', $fakeRegisteredTaxonomies, true)
        && in_array('pa_尺寸', $fakeRegisteredTaxonomies, true),
        'newly-applied ASCII and multibyte Woo attribute taxonomies are registered before product reads');
    $check(in_array('wc_attribute_taxonomies', $fakeDeletedTransients, true)
        && in_array('woocommerce-attributes', WC_Cache_Helper::$invalidatedGroups, true),
        'Woo attribute transient and object cache are refreshed before registration');
    $check(in_array('woocommerce_permalinks', $fakeOptionReads, true),
        'attribute rewrite derivation reads the Woo permalink setting without the mutating Woo helper');

    // DUO-3368: refresh_attribute_taxonomy_registry() is a post-init repair,
    // not permission to invent a new taxonomy contract. Its registration
    // args must remain byte-for-byte equivalent to WooCommerce's own
    // class-wc-post-types.php branch for both attribute_public values, and
    // both Woo filter seams must be honored before registration.
    $registrationByTaxonomy = [];
    foreach ($fakeTaxonomyRegistrations as $registration) {
        $registrationByTaxonomy[$registration['taxonomy']] = $registration;
    }
    $capabilities = [
        'manage_terms' => 'manage_product_terms',
        'edit_terms' => 'edit_product_terms',
        'delete_terms' => 'delete_product_terms',
        'assign_terms' => 'assign_product_terms',
    ];
    $hiddenLabels = [
        'name' => 'Product Grind Size',
        'singular_name' => 'Grind Size',
        'search_items' => 'Search Grind Size',
        'all_items' => 'All Grind Size',
        'parent_item' => 'Parent Grind Size',
        'parent_item_colon' => 'Parent Grind Size:',
        'edit_item' => 'Edit Grind Size',
        'update_item' => 'Update Grind Size',
        'add_new_item' => 'Add new Grind Size',
        'new_item_name' => 'New Grind Size',
        'not_found' => 'No &quot;Grind Size&quot; found',
        'back_to_items' => '&larr; Back to "Grind Size" attributes',
    ];
    $publicLabels = [
        'name' => 'Product Color',
        'singular_name' => 'Color',
        'search_items' => 'Search Color',
        'all_items' => 'All Color',
        'parent_item' => 'Parent Color',
        'parent_item_colon' => 'Parent Color:',
        'edit_item' => 'Edit Color',
        'update_item' => 'Update Color',
        'add_new_item' => 'Add new Color',
        'new_item_name' => 'New Color',
        'not_found' => 'No &quot;Color&quot; found',
        'back_to_items' => '&larr; Back to "Color" attributes',
    ];
    $legacyLabels = [
        'name' => 'Product Legacy',
        'singular_name' => 'Legacy',
        'search_items' => 'Search Legacy',
        'all_items' => 'All Legacy',
        'parent_item' => 'Parent Legacy',
        'parent_item_colon' => 'Parent Legacy:',
        'edit_item' => 'Edit Legacy',
        'update_item' => 'Update Legacy',
        'add_new_item' => 'Add new Legacy',
        'new_item_name' => 'New Legacy',
        'not_found' => 'No &quot;Legacy&quot; found',
        'back_to_items' => '&larr; Back to "Legacy" attributes',
    ];
    $expectedHiddenArgs = [
        'hierarchical' => false,
        'update_count_callback' => '_update_post_term_count',
        'labels' => $hiddenLabels,
        'show_ui' => true,
        'show_in_quick_edit' => false,
        'show_in_menu' => false,
        'meta_box_cb' => false,
        'query_var' => false,
        'rewrite' => false,
        'sort' => false,
        'public' => false,
        'show_in_nav_menus' => false,
        'capabilities' => $capabilities,
    ];
    $expectedPublicArgs = [
        'hierarchical' => false,
        'update_count_callback' => '_update_post_term_count',
        'labels' => $publicLabels,
        'show_ui' => true,
        'show_in_quick_edit' => false,
        'show_in_menu' => false,
        'meta_box_cb' => false,
        'query_var' => true,
        'rewrite' => [
            'slug' => 'attribute/color',
            'with_front' => false,
            'hierarchical' => true,
        ],
        'sort' => false,
        'public' => true,
        'show_in_nav_menus' => true,
        'capabilities' => $capabilities,
    ];
    $expectedLegacyArgs = $expectedPublicArgs;
    $expectedLegacyArgs['labels'] = $legacyLabels;
    $expectedLegacyArgs['rewrite']['slug'] = 'attribute/legacy';
    $check(($registrationByTaxonomy['pa_grind-size']['object_types'] ?? null) === ['product'],
        'the non-public attribute keeps the default product object type after the objects filter');
    $check(($registrationByTaxonomy['pa_color']['object_types'] ?? null) === ['product', 'product_variation'],
        'the public attribute receives the object types returned by its Woo taxonomy-objects filter');
    $check(($registrationByTaxonomy['pa_legacy']['object_types'] ?? null) === ['product'],
        'an attribute row without attribute_public retains Woo\'s public default and product object type');
    $check(($registrationByTaxonomy['pa_尺寸']['object_types'] ?? null) === ['product']
        && ($registrationByTaxonomy['pa_尺寸']['args']['public'] ?? null) === false
        && ($registrationByTaxonomy['pa_尺寸']['args']['query_var'] ?? null) === false
        && ($registrationByTaxonomy['pa_尺寸']['args']['rewrite'] ?? null) === false,
        'a valid multibyte Woo attribute crosses both dynamic filters without widening its private contract');
    $check(($registrationByTaxonomy['pa_grind-size']['args'] ?? null) === $expectedHiddenArgs,
        'attribute_public=0 registers the complete Woo contract without public/query/rewrite visibility');
    $check(($registrationByTaxonomy['pa_color']['args'] ?? null) === $expectedPublicArgs,
        'attribute_public=1 registers the complete Woo contract with its public rewrite and nav visibility');
    $check(($registrationByTaxonomy['pa_legacy']['args'] ?? null) === $expectedLegacyArgs,
        'an absent legacy attribute_public value defaults to Woo\'s complete public registration contract');
    $filterCallsFor = static function (string $hook) use (&$fakeApplyFilterCalls): array {
        return array_values(array_filter(
            $fakeApplyFilterCalls,
            static fn(array $call): bool => $call['hook'] === $hook
        ));
    };
    $registryRefresh = new \ReflectionMethod($adapter, 'refresh_attribute_taxonomy_registry');
    unset($fakeOptions['woocommerce_permalinks']);
    $registryRefresh->invoke($adapter);
    $fakeOptions['woocommerce_permalinks'] = ['attribute_base' => 'attribute'];
    $hiddenObjectsCalls = $filterCallsFor('woocommerce_taxonomy_objects_pa_grind-size');
    $publicObjectsCalls = $filterCallsFor('woocommerce_taxonomy_objects_pa_color');
    $hiddenArgsCalls = $filterCallsFor('woocommerce_taxonomy_args_pa_grind-size');
    $publicArgsCalls = $filterCallsFor('woocommerce_taxonomy_args_pa_color');
    $legacyObjectsCalls = $filterCallsFor('woocommerce_taxonomy_objects_pa_legacy');
    $legacyArgsCalls = $filterCallsFor('woocommerce_taxonomy_args_pa_legacy');
    $multibyteObjectsCalls = $filterCallsFor('woocommerce_taxonomy_objects_pa_尺寸');
    $multibyteArgsCalls = $filterCallsFor('woocommerce_taxonomy_args_pa_尺寸');
    $navCalls = $filterCallsFor('woocommerce_attribute_show_in_nav_menus');
    $check(count($hiddenObjectsCalls) === 1 && $hiddenObjectsCalls[0]['value'] === ['product']
        && $hiddenObjectsCalls[0]['args'] === [],
        'the non-public taxonomy objects filter receives exactly WooCommerce\'s product default');
    $check(count($publicObjectsCalls) === 1 && $publicObjectsCalls[0]['value'] === ['product']
        && $publicObjectsCalls[0]['args'] === [],
        'the public taxonomy objects filter receives exactly WooCommerce\'s product default');
    $check(count($hiddenArgsCalls) === 1 && $hiddenArgsCalls[0]['value'] === $expectedHiddenArgs
        && $hiddenArgsCalls[0]['args'] === [],
        'the non-public taxonomy args filter sees the full unmodified Woo contract');
    $check(count($publicArgsCalls) === 1 && $publicArgsCalls[0]['value'] === $expectedPublicArgs
        && $publicArgsCalls[0]['args'] === [],
        'the public taxonomy args filter sees the full rewrite-enabled Woo contract');
    $check(count($legacyObjectsCalls) === 1 && $legacyObjectsCalls[0]['value'] === ['product']
        && $legacyObjectsCalls[0]['args'] === []
        && count($legacyArgsCalls) === 1 && $legacyArgsCalls[0]['value'] === $expectedLegacyArgs
        && $legacyArgsCalls[0]['args'] === [],
        'legacy public-default registration preserves both Woo taxonomy filter seams');
    $check(count($multibyteObjectsCalls) === 1 && $multibyteObjectsCalls[0]['value'] === ['product']
        && $multibyteObjectsCalls[0]['args'] === []
        && count($multibyteArgsCalls) === 1
        && ($multibyteArgsCalls[0]['value']['public'] ?? null) === false
        && $multibyteArgsCalls[0]['args'] === [],
        'multibyte attribute registration invokes both provider-owned dynamic filter families');
    $check(count($filterCallsFor('pre_option_woocommerce_permalinks')) >= 1
        && count($filterCallsFor('pre_option')) >= 1
        && count($filterCallsFor('option_woocommerce_permalinks')) >= 1
        && count($filterCallsFor('default_option_woocommerce_permalinks')) >= 1,
        'permalink projection crosses every declared present/missing specific and generic option-read filter');
    $publicNavCalls = array_values(array_filter(
        $navCalls,
        static fn(array $call): bool => in_array(($call['args'][0] ?? null), ['pa_color', 'pa_legacy'], true)
    ));
    $check(count($navCalls) === 2 && count($publicNavCalls) === 2
        && array_reduce($publicNavCalls, static fn(bool $ok, array $call): bool =>
            $ok && $call['value'] === false, true),
        'the nav-menu filter runs only for explicit/default-public attributes with Woo\'s false default and taxonomy name');
    $check(count(array_filter(
        $navCalls,
        static fn(array $call): bool => ($call['args'][0] ?? null) === 'pa_grind-size'
    )) === 0,
        'the non-public attribute cannot invoke the public-only nav-menu filter');

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
    fake_set_visibility_relationships(40, 'product_type', ['grouped']);
    fake_set_visibility_relationships(41, 'product_type', ['simple']);
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
    if ($groupedParentRemove !== false) {
        foreach (array_slice($groupedEvents, $groupedParentRemove + 1, null, true) as $eventIndex => $event) {
            if (str_starts_with($event, 'read:40:')) {
                $groupedParentRead = $eventIndex;
                break;
            }
        }
    }
    $check($groupedParentRemove !== false && $groupedParentRead !== null
        && $groupedParentRemove < $groupedParentRead,
        'grouped parent discovery evicts the root before its first post-invalidation Woo read');

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
    fake_set_visibility_relationships(50, 'product_type', ['grouped']);
    fake_set_visibility_relationships(51, 'product_type', ['variable']);
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
    $fakePublicOnSaleOverrides[13] = false;
    $adapter->regenerate_batch([13], [], static function () use (&$heartbeatCalls): void {
        $heartbeatCalls++;
    });
    $check($heartbeatCalls > 0, 'batch adapter invokes the promotion heartbeat callback');
    $check($fakeMeta[13]['_price'] === ['21'],
        'simple active price follows Woo public is_on_sale semantics rather than a copied sale/date rule');
    $check($fakeMetaLookup[13]['onsale'] === 0, 'zero sale price is not marked onsale');
    unset($fakePublicOnSaleOverrides[13]);
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
    $sameParentEvents = array_slice($fakeCacheEvents, $sameParentEventStart);
    $sameParentRemove = array_search('remove:30', $sameParentEvents, true);
    $sameParentRead = null;
    if ($sameParentRemove !== false) {
        foreach (array_slice($sameParentEvents, $sameParentRemove + 1, null, true) as $eventIndex => $event) {
            if (str_starts_with($event, 'read:30:')) {
                $sameParentRead = $eventIndex;
                break;
            }
        }
    }
    $check($sameParentRemove !== false && $sameParentRead !== null && $sameParentRemove < $sameParentRead,
        'same-parent write evicts the current parent cache before the first post-invalidation parent read');

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

    $transactionStateFailureCaught = false;
    $priceBeforeTransactionStateFailure = $fakeMeta[20]['_price'];
    $wpdb->nullTransactionStateRead = true;
    try {
        $adapter->regenerate_batch([], [[
            'kind' => 'reparent',
            'uuid' => 'variable-transaction-state-failure',
            'id' => 20,
            'post_type' => 'product',
            'root_ids' => [20],
        ]]);
    } catch (\Throwable $t) {
        $transactionStateFailureCaught = str_contains($t->getMessage(), 'transaction-state inspection returned no value');
    } finally {
        $wpdb->nullTransactionStateRead = false;
    }
    $check($transactionStateFailureCaught,
        'parent synchronization fails closed when the database cannot report transaction state');
    $check($fakeMeta[20]['_price'] === $priceBeforeTransactionStateFailure,
        'an unknown transaction state cannot mutate the derived parent price');

    // The public variable store can fail after mutating derived _price. The
    // scoped WordPress metadata guard must preserve authored parent rows and
    // be removed in finally; the provider-local transaction owns derived
    // rollback because provider dispatch occurs after Apply's main commit.
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
        'failed variable sync never deletes authored parent rows');
    $check($fakeMeta[20]['_price'] === $priceBeforeVariableFailure,
        'a throwing public sync rolls back its partial derived-price mutation inside the provider boundary');
    $remainingPriceGuards = array_filter(
        $fakeFilters['delete_post_metadata'][PHP_INT_MAX] ?? [],
        static fn(array $entry): bool => isset($entry[0])
    );
    $check($remainingPriceGuards === [], 'parent-price metadata guard is removed after a throwing Woo sync');
    $adapter->regenerate_batch([], [[
        'kind' => 'reparent',
        'uuid' => 'variable-sync-failure',
        'id' => 20,
        'post_type' => 'product',
        'root_ids' => [20],
    ]]);
    $check($fakeMeta[20]['_regular_price'] === ['32', '31'] && $fakeMeta[20]['_sale_price'] === ['29'],
        'variable sync retry preserves the exact authored parent rows');
    $fakeMeta[999]['_regular_price'] = ['ordinary-delete'];
    $check(delete_post_meta(999, '_regular_price') && !isset($fakeMeta[999]['_regular_price']),
        'metadata guard is scoped to the active Woo parent sync and cannot suppress later ordinary deletes');
    $check(in_array(20, \Automattic\WooCommerce\Internal\Caches\ProductCache::$removed, true)
        && in_array(22, \Automattic\WooCommerce\Internal\Caches\ProductCache::$removed, true),
        'reparent invalidates Woo product-instance caches for both roots');
    $reparentEvents = array_slice($fakeCacheEvents, $reparentEventStart);
    foreach ([20, 21, 22] as $cacheId) {
        $firstRemove = array_search("remove:$cacheId", $reparentEvents, true);
        $firstRead = null;
        if ($firstRemove !== false) {
            foreach (array_slice($reparentEvents, $firstRemove + 1, null, true) as $eventIndex => $event) {
                if (str_starts_with($event, "read:$cacheId:")) {
                    $firstRead = $eventIndex;
                    break;
                }
            }
        }
        $check($firstRemove !== false && $firstRead !== null && $firstRemove < $firstRead,
            "reparent evicts product-cache id $cacheId before the first post-invalidation read");
    }

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
        if ($firstRemove !== false) {
            foreach (array_slice($chainedReparentEvents, $firstRemove + 1, null, true) as $eventIndex => $event) {
                if (str_starts_with($event, "read:$cacheId:")) {
                    $firstRead = $eventIndex;
                    break;
                }
            }
        }
        $check($firstRemove !== false && $firstRead !== null && $firstRemove < $firstRead,
            "chained reparent evicts accumulated root/cache id $cacheId before the first post-invalidation read");
    }

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
    $check($fakeMeta[24]['_stock'] === ['5'], 'deleted reparent retry preserves root-local runtime stock');
    $deletedReparentEvents = array_slice($fakeCacheEvents, $deletedReparentEventStart);
    foreach ([20, 21, 22, 24] as $cacheId) {
        $firstRemove = array_search("remove:$cacheId", $deletedReparentEvents, true);
        $firstRead = null;
        if ($firstRemove !== false) {
            foreach (array_slice($deletedReparentEvents, $firstRemove + 1, null, true) as $eventIndex => $event) {
                if (str_starts_with($event, "read:$cacheId:")) {
                    $firstRead = $eventIndex;
                    break;
                }
            }
        }
        $check($firstRemove !== false && ($firstRead === null || $firstRemove < $firstRead),
            "deleted reparent retry evicts cache id $cacheId before any post-invalidation read");
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
    $check((WC_Data_Store::$variable?->calls ?? 0) === 14, 'parent resync remains deduplicated across same-parent, chained roots, deletion, and cleanup');

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
    $check(!array_filter($fakeAttrLookup, static fn(array $row): bool =>
        (int) $row['product_id'] === 15 || (int) $row['product_or_parent_id'] === 15),
        'deleted simple product removes its exact native attribute lookup rows synchronously');

    $fakeAttrLookup[] = [
        'product_id' => 501, 'product_or_parent_id' => 500, 'taxonomy' => 'pa_color',
        'term_id' => 101, 'is_variation_attribute' => 1, 'in_stock' => 1,
    ];
    $adapter->regenerate_batch([], [[
        'uuid' => 'variable-root-attribute-delete', 'id' => 500, 'post_type' => 'product',
        'parent_id' => 0, 'child_ids' => [501],
    ]]);
    $check(!array_filter($fakeAttrLookup, static fn(array $row): bool =>
        (int) $row['product_id'] === 500 || (int) $row['product_id'] === 501
            || (int) $row['product_or_parent_id'] === 500),
        'deleted variable root removes its complete bounded child attribute projection');

    $fakeAttrLookup[] = [
        'product_id' => 600, 'product_or_parent_id' => 600, 'taxonomy' => 'pa_color',
        'term_id' => 101, 'is_variation_attribute' => 0, 'in_stock' => 1,
    ];
    $wpdb->failAttributeDelete = true;
    $attributeDeleteFailure = '';
    try {
        $adapter->regenerate_batch([], [[
            'uuid' => 'simple-attribute-delete-failure', 'id' => 600, 'post_type' => 'product',
            'parent_id' => 0, 'child_ids' => [],
        ]]);
    } catch (\Throwable $failure) {
        $attributeDeleteFailure = $failure->getMessage();
    }
    $wpdb->failAttributeDelete = false;
    $wpdb->last_error = '';
    $check(str_contains($attributeDeleteFailure, 'attribute lookup deletion failed')
        && str_contains($attributeDeleteFailure, 'recovery_required')
        && !str_contains($attributeDeleteFailure, 'do-not-leak')
        && (bool) array_filter($fakeAttrLookup, static fn(array $row): bool =>
            (int) $row['product_or_parent_id'] === 600),
        'bounded attribute deletion failure is redacted, loud, and leaves exact retry work');
    $adapter->regenerate_batch([], [[
        'uuid' => 'simple-attribute-delete-failure', 'id' => 600, 'post_type' => 'product',
        'parent_id' => 0, 'child_ids' => [],
    ]]);
    $check(!array_filter($fakeAttrLookup, static fn(array $row): bool =>
        (int) $row['product_or_parent_id'] === 600),
        'retry deterministically clears the failed bounded attribute tombstone');

    // Apply materializes authored product_type before this provider runs. Put
    // lower-id variations before their higher-id exact variable parent to
    // reproduce the live R3-A ordering that originally erased/omitted lookup
    // rows without asking this derived-state provider to invent type identity.
    $fakeProducts[68] = new FakeProduct(68, 'variation', 70, [], ['pa_color' => 'red'], true);
    $fakeProducts[69] = new FakeProduct(69, 'variation', 70, [], ['pa_color' => 'blue'], true);
    $fakeProducts[70] = new FakeProduct(
        70,
        'variable',
        0,
        [68, 69],
        ['pa_color' => new FakeProductAttribute(12, [101, 102], true)],
        true
    );
    fake_set_visibility_relationships(70, 'product_type', ['variable']);
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
    $check($fakeMeta[70]['_price'] === ['18', '21'],
        'fresh target synthesizes variable-root price from the exact authored type and child posts');
    $check($fakeProducts[70]->get_type() === 'variable',
        'fresh-target classification is bound to the authored variable product_type identity');

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

    // DUO-3373 decision: a variation-path write expands only the finite child
    // list of its loaded variable root. That is bounded by the same public
    // child set the root sync and verification already consume, and avoids a
    // deterministic refuse-then-retry when a sibling's derived inputs or
    // lookup row is stale. It is not a catalog-wide scan.
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
    fake_set_visibility_relationships(90, 'product_type', ['variable']);
    foreach ([90, 91, 92] as $id) {
        $fakeMeta[$id] = $fakeMeta[12];
        $fakeMetaLookup[$id] = $fakeMetaLookup[12];
        $fakeMetaLookup[$id]['product_id'] = $id;
    }
    $adapter->regenerate_batch([90], []);              // converge the whole family
    $fakeMeta[92]['_price'] = ['999'];
    $fakeMetaLookup[92]['min_price'] = 'left-behind';  // sibling row and input go stale
    $adapter->regenerate_batch([91], []);              // reached via the variation path
    $check($fakeMeta[92]['_price'] === ['21'],
        'variation-path refresh repairs the stale sibling effective price in one pass');
    $check(($fakeMetaLookup[92]['min_price'] ?? null) === '21',
        'variation-path refresh repairs the stale sibling lookup row in one pass');
    $check($fakeMeta[90]['_price'] === ['21'],
        'the variable root is synthesized from the refreshed sibling set in the same pass');

    // A column OUTSIDE Woo's derived set that still holds data — cogs_total_
    // value with the COGS feature off, or anything a third party maintains —
    // is reset by Woo's own DELETE+INSERT whenever this bounded refresh pass
    // touches the row. Blaming the apply for that reset would be a false
    // attribution; this assertion keeps that non-derived column outside the
    // provider's verification authority.
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
    $skuLeakMarker = 'sku_secret_marker_DO_NOT_ECHO';
    $globalIdLeakMarker = 'global_unique_id_secret_marker_DO_NOT_ECHO';
    $fakeLookupWriteFaults[13] = [
        'sku' => $skuLeakMarker,
        'global_unique_id' => $globalIdLeakMarker,
    ];
    $skuFaultMessage = '';
    try {
        $adapter->regenerate_batch([13], []);
    } catch (\Throwable $failure) {
        $skuFaultMessage = $failure->getMessage();
    }
    $fakeLookupWriteFaults = [];
    $check(str_contains($skuFaultMessage, 'product lookup verification mismatch for product 13')
        && str_contains($skuFaultMessage, 'did not land the values WooCommerce derived')
        && str_contains($skuFaultMessage, 'columns=')
        && substr_count($skuFaultMessage, '_sha256=') === 2
        && !str_contains($skuFaultMessage, $skuLeakMarker)
        && !str_contains($skuFaultMessage, $globalIdLeakMarker),
        'lookup mismatch diagnostics are bounded digests and never echo secret-shaped SKU/global ids');

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
    fake_set_visibility_relationships(80, 'product_type', ['simple']);
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
    $check(in_array($unrelatedAttributeRow, $fakeAttrLookup, true)
        && $attributeStoreProbe->createCalls > 0
        && $attributeStoreProbe->deleteCalls === 0,
        'product, reparent, retry, and invoke paths repair scoped attributes while preserving unrelated rows');
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
