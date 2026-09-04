<?php
/** Database-only WooCommerce product-deletion effect regression. */

if (!defined('ARRAY_A')) {
    define('ARRAY_A', 'ARRAY_A');
}

$root = dirname(__DIR__, 4);
require $root . '/agent/src/Adapter/ManifestProviderRuntime.php';
require $root . '/agent/src/Adapter/ProviderSdk.php';
require_once $root . '/agent/src/Adapter/Providers.php';
require $root . '/adapter-packages/woocommerce/package/runtime/providers/woocommerce-product-lookups.php';

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    echo "FAIL: $message\n";
    $failures++;
};

final class WooDeletionCleanupWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';
    public bool $inTransaction = false;
    public string $failContext = '';
    /** @var array<int,array{product_id:int}> */
    public array $meta = [];
    /** @var list<array<string,int|string>> */
    public array $attributes = [];
    /** @var array<int,array{action_id:int,status:string,hook:string,args:string,group_id:int}> */
    public array $actions = [];
    /** @var array<int,string> */
    public array $groups = [];
    /** @var array<string,string> */
    public array $options = [];
    private ?array $snapshot = null;

    public function prepare(string $sql, mixed ...$values): string {
        foreach ($values as $value) {
            $replacement = is_int($value) ? (string) $value : "'" . addslashes((string) $value) . "'";
            $sql = preg_replace('/%[ds]/', $replacement, $sql, 1) ?? $sql;
        }
        return $sql;
    }

    public function get_var(string $sql): mixed {
        if ($sql === 'SELECT @@in_transaction') {
            return $this->inTransaction ? '1' : '0';
        }
        $this->last_error = 'unexpected get_var';
        return false;
    }

    /** @return list<array<string,mixed>> */
    public function get_results(string $sql, mixed $format): array|false {
        if (str_contains($sql, 'FROM `wp_wc_product_meta_lookup`')) {
            $ids = $this->numericIn($sql);
            $rows = [];
            foreach ($this->meta as $row) {
                if (in_array((int) $row['product_id'], $ids, true)) {
                    $rows[] = ['product_id' => (string) $row['product_id']];
                }
            }
            usort($rows, static fn(array $a, array $b): int => ((int) $a['product_id']) <=> ((int) $b['product_id']));
            return $rows;
        }
        if (str_contains($sql, 'FROM `wp_wc_product_attributes_lookup`')) {
            $ids = $this->numericIn($sql);
            $rows = array_values(array_filter($this->attributes, static fn(array $row): bool =>
                in_array((int) $row['product_id'], $ids, true)
                    || in_array((int) $row['product_or_parent_id'], $ids, true)));
            usort($rows, static fn(array $a, array $b): int =>
                ((int) $a['product_or_parent_id'] <=> (int) $b['product_or_parent_id'])
                ?: ((int) $a['product_id'] <=> (int) $b['product_id'])
                ?: strcmp((string) $a['taxonomy'], (string) $b['taxonomy'])
                ?: ((int) $a['term_id'] <=> (int) $b['term_id']));
            return array_map(static fn(array $row): array => array_map('strval', $row), $rows);
        }
        if (str_contains($sql, 'FROM `wp_options`')) {
            $names = $this->stringIn($sql);
            $rows = [];
            foreach (array_keys($this->options) as $name) {
                if (in_array($name, $names, true)) {
                    $rows[] = ['option_name' => $name];
                }
            }
            usort($rows, static fn(array $a, array $b): int => strcmp($a['option_name'], $b['option_name']));
            return $rows;
        }
        if (str_contains($sql, 'FROM `wp_actionscheduler_actions`')) {
            $rows = [];
            foreach ($this->actions as $action) {
                if ($action['status'] !== 'pending'
                    || !in_array($action['hook'], [
                        'wc_product_start_scheduled_sale',
                        'wc_product_end_scheduled_sale',
                    ], true)
                    || ($this->groups[$action['group_id']] ?? '') !== 'woocommerce-sales'
                    || !str_contains($sql, "'" . addslashes($action['args']) . "'")) {
                    continue;
                }
                $rows[] = ['action_id' => (string) $action['action_id']];
            }
            usort($rows, static fn(array $a, array $b): int => ((int) $a['action_id']) <=> ((int) $b['action_id']));
            return $rows;
        }
        $this->last_error = 'unexpected get_results';
        return false;
    }

    public function query(string $sql): int|false {
        if ($sql === 'START TRANSACTION') {
            if ($this->inTransaction) {
                $this->last_error = 'nested transaction';
                return false;
            }
            $this->snapshot = [$this->meta, $this->attributes, $this->actions, $this->options];
            $this->inTransaction = true;
            return 0;
        }
        if ($sql === 'COMMIT') {
            $this->snapshot = null;
            $this->inTransaction = false;
            return 0;
        }
        if ($sql === 'ROLLBACK') {
            if ($this->snapshot !== null) {
                [$this->meta, $this->attributes, $this->actions, $this->options] = $this->snapshot;
            }
            $this->snapshot = null;
            $this->inTransaction = false;
            return 0;
        }
        if ($this->failContext !== '' && str_contains($sql, $this->failContext)) {
            $this->last_error = 'do-not-leak';
            return false;
        }
        if (str_starts_with($sql, 'DELETE FROM `wp_wc_product_meta_lookup`')) {
            $ids = $this->numericIn($sql);
            $before = count($this->meta);
            $this->meta = array_values(array_filter($this->meta, static fn(array $row): bool =>
                !in_array((int) $row['product_id'], $ids, true)));
            return $before - count($this->meta);
        }
        if (str_starts_with($sql, 'DELETE FROM `wp_wc_product_attributes_lookup`')) {
            $ids = $this->numericIn($sql);
            $before = count($this->attributes);
            $this->attributes = array_values(array_filter($this->attributes, static fn(array $row): bool =>
                !in_array((int) $row['product_id'], $ids, true)
                    && !in_array((int) $row['product_or_parent_id'], $ids, true)));
            return $before - count($this->attributes);
        }
        if (str_starts_with($sql, 'UPDATE `wp_actionscheduler_actions`')) {
            $ids = $this->numericIn($sql);
            $updated = 0;
            foreach ($this->actions as &$action) {
                if ($action['status'] === 'pending' && in_array($action['action_id'], $ids, true)) {
                    $action['status'] = 'canceled';
                    $updated++;
                }
            }
            unset($action);
            return $updated;
        }
        if (str_starts_with($sql, 'DELETE FROM `wp_options`')) {
            $names = $this->stringIn($sql);
            $deleted = 0;
            foreach ($names as $name) {
                if (array_key_exists($name, $this->options)) {
                    unset($this->options[$name]);
                    $deleted++;
                }
            }
            return $deleted;
        }
        $this->last_error = 'unexpected query';
        return false;
    }

    /** @return list<int> */
    private function numericIn(string $sql): array {
        preg_match('/\bIN \(([^)]*)\)/', $sql, $match);
        preg_match_all('/\b[0-9]+\b/', $match[1] ?? '', $numbers);
        return array_values(array_unique(array_map('intval', $numbers[0] ?? [])));
    }

    /** @return list<string> */
    private function stringIn(string $sql): array {
        preg_match('/\bIN \(([^)]*)\)/', $sql, $match);
        preg_match_all("/'((?:[^'\\\\]|\\\\.)*)'/", $match[1] ?? '', $strings);
        return array_map('stripslashes', $strings[1] ?? []);
    }
}

$manifest = json_decode(
    (string) file_get_contents($root . '/adapter-packages/woocommerce/package/manifest.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$declaration = null;
foreach ($manifest['providers'] as $provider) {
    if (($provider['id'] ?? null) === 'woocommerce-product-lookups') {
        $declaration = $provider;
        break;
    }
}
if (!is_array($declaration)) {
    throw new RuntimeException('product lookup provider declaration missing');
}
$provider = new \WPrism\Providers\WoocommerceProductLookups($declaration);
$wpdb = new WooDeletionCleanupWpdb();
$GLOBALS['wpdb'] = $wpdb;
$wpdb->meta = [['product_id' => 41], ['product_id' => 99]];
$wpdb->attributes = [
    ['product_id' => 41, 'product_or_parent_id' => 41, 'taxonomy' => 'pa_color', 'term_id' => 7, 'is_variation_attribute' => 0, 'in_stock' => 1],
    ['product_id' => 42, 'product_or_parent_id' => 41, 'taxonomy' => 'pa_size', 'term_id' => 8, 'is_variation_attribute' => 1, 'in_stock' => 1],
    ['product_id' => 99, 'product_or_parent_id' => 99, 'taxonomy' => 'pa_color', 'term_id' => 9, 'is_variation_attribute' => 0, 'in_stock' => 1],
];
$wpdb->groups = [3 => 'woocommerce-sales', 4 => 'somebody-else'];
$wpdb->actions = [
    10 => ['action_id' => 10, 'status' => 'pending', 'hook' => 'wc_product_start_scheduled_sale', 'args' => '{"product_id":41}', 'group_id' => 3],
    11 => ['action_id' => 11, 'status' => 'complete', 'hook' => 'wc_product_end_scheduled_sale', 'args' => '{"product_id":41}', 'group_id' => 3],
    12 => ['action_id' => 12, 'status' => 'pending', 'hook' => 'wc_product_end_scheduled_sale', 'args' => '{"product_id":99}', 'group_id' => 3],
    13 => ['action_id' => 13, 'status' => 'pending', 'hook' => 'wc_product_end_scheduled_sale', 'args' => '{"product_id":41}', 'group_id' => 4],
];
$wpdb->options = [
    '_transient_wc_products_onsale' => 'merchant-data',
    '_transient_timeout_wc_products_onsale' => '123',
    '_transient_wc_related_41' => 'merchant-data',
    '_transient_unrelated' => 'keep',
];
$row = [
    'kind' => 'post:product',
    'uuid' => '11111111-1111-4111-8111-111111111111',
    'id' => 41,
    'post_type' => 'product',
    'parent_id' => 0,
    'child_ids' => [],
];
$args = [\WPrism\Providers::ENTITIES_ARG => ['deletions' => [$row]]];
$receipt = $provider->invoke('cleanup_product_deletions', $args);
$check(($receipt['verified'] ?? null) === true
    && ($receipt['before']['meta_lookup_rows'] ?? null) === 1
    && ($receipt['before']['attribute_lookup_rows'] ?? null) === 2
    && ($receipt['before']['pending_sale_actions'] ?? null) === 1
    && ($receipt['before']['transient_rows'] ?? null) === 3,
    'cleanup receipt binds every exact database-contained Woo deletion residue before mutation');
$check(($receipt['after']['meta_lookup_rows'] ?? null) === 0
    && ($receipt['after']['attribute_lookup_rows'] ?? null) === 0
    && ($receipt['after']['pending_sale_actions'] ?? null) === 0
    && ($receipt['after']['transient_rows'] ?? null) === 0,
    'cleanup independently verifies the exact empty post-delete projection after commit');
$check(array_column($wpdb->meta, 'product_id') === [99]
    && count($wpdb->attributes) === 1
    && ($wpdb->attributes[0]['product_id'] ?? null) === 99,
    'lookup deletion is restricted to the selected product and its parent-owned attribute rows');
$check(($wpdb->actions[10]['status'] ?? null) === 'canceled'
    && ($wpdb->actions[11]['status'] ?? null) === 'complete'
    && ($wpdb->actions[12]['status'] ?? null) === 'pending'
    && ($wpdb->actions[13]['status'] ?? null) === 'pending',
    'only pending exact-args Woo sale actions in the Woo sales group are canceled');
$check($wpdb->options === ['_transient_unrelated' => 'keep'],
    'fixed and product-specific Woo transients are removed without touching unrelated options');
$replay = $provider->invoke('cleanup_product_deletions', $args);
$check(($replay['before']['meta_lookup_rows'] ?? null) === 0
    && ($replay['after']['scope_sha256'] ?? null) === ($replay['before']['scope_sha256'] ?? null),
    'replaying the durable tombstone without a second retry-channel claim is idempotent and verifies the already-empty projection');

$variationRejected = false;
try {
    $provider->invoke('cleanup_product_deletions', [
        \WPrism\Providers::ENTITIES_ARG => ['deletions' => [array_merge($row, [
            'kind' => 'post:product_variation',
            'post_type' => 'product_variation',
            'parent_id' => 7,
        ])]],
    ]);
} catch (Throwable $failure) {
    $variationRejected = str_contains($failure->getMessage(), 'standalone product tombstones');
}
$check($variationRejected, 'variation deletion stays fail-closed instead of skipping required parent regeneration');

$wpdb->meta = [['product_id' => 41]];
$wpdb->attributes = [];
$wpdb->actions = [];
$wpdb->options = [];
$wpdb->failContext = 'DELETE FROM `wp_wc_product_attributes_lookup`';
$failureMessage = '';
try {
    $provider->invoke('cleanup_product_deletions', $args);
} catch (Throwable $failure) {
    $failureMessage = $failure->getMessage();
}
$check(str_contains($failureMessage, 'attribute lookup removal failed')
    && !str_contains($failureMessage, 'do-not-leak')
    && $wpdb->meta === [['product_id' => 41]]
    && $wpdb->inTransaction === false,
    'a mid-cleanup database failure is redacted and rolls the provider-local transaction back exactly');

if ($failures > 0) {
    exit(1);
}
echo "PASS: WooCommerce product deletion cleanup\n";
