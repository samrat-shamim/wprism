<?php
/** Offline adversarial product-path regression for Woo hierarchy repair. */
declare(strict_types=1);

define('DUO_SPEC_VERSION', 2);
$root = dirname(__DIR__, 4);
putenv('DUO_MANIFESTS_DIR=' . $root . '/manifests');

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/OptionState.php';
require_once $root . '/agent/src/Kernel/PlainData.php';
require_once $root . '/agent/src/Policy/Policy.php';
require_once $root . '/agent/src/Adapter/ProviderSdk.php';
require_once $root . '/agent/src/Adapter/Providers.php';

use Duo\Policy;
use DuoTest\FakeWpdb;
use DuoTest\WpStore;

final class WooHierarchyWakeupCanary {
    public static int $wakeups = 0;

    public function __wakeup(): void {
        self::$wakeups++;
    }
}

if (!class_exists('WP_CLI')) {
    final class WP_CLI {
        public static string $mode = 'success';
        /** @var null|callable(string,array):object */
        public static $handler = null;
        /** @var list<array{command:string,options:array}> */
        public static array $calls = [];

        public static function runcommand(string $command, array $options): object {
            self::$calls[] = ['command' => $command, 'options' => $options];
            if (self::$mode === 'throw') {
                throw new RuntimeException('secret=child-launch-detail');
            }
            if (self::$mode === 'exit') {
                return (object) [
                    'return_code' => 23,
                    'stdout' => 'secret=child-stdout',
                    'stderr' => 'secret=child-stderr',
                ];
            }
            if (!is_callable(self::$handler)) {
                throw new RuntimeException('test handler missing');
            }
            $result = (self::$handler)($command, $options);
            if (self::$mode === 'stderr') {
                $result->stderr = "warning: secret=stderr-marker\n";
            } elseif (self::$mode === 'trailing') {
                $result->stdout .= "\nwarning: secret=trailing-marker";
            } elseif (self::$mode === 'mismatch') {
                $decoded = json_decode((string) $result->stdout, true, flags: JSON_THROW_ON_ERROR);
                $decoded['after']['category_lookup_sha256'] = str_repeat('0', 64);
                $result->stdout = json_encode(
                    $decoded,
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                );
            }
            return $result;
        }
    }
}

require_once $root . '/manifests/providers/woocommerce-hierarchy-lookups.php';

/** @return array<int,int> */
function woo_hierarchy_test_parent_map(string $taxonomy): array {
    global $wpdb;
    $map = [];
    foreach ($wpdb->rows($wpdb->term_taxonomy) as $row) {
        if (($row['taxonomy'] ?? null) === $taxonomy) {
            $map[(int) $row['term_id']] = (int) $row['parent'];
        }
    }
    ksort($map, SORT_NUMERIC);
    return $map;
}

/** @param array<int,int> $map @return array<int,list<int>> */
function woo_hierarchy_test_children(array $map): array {
    $children = [];
    foreach ($map as $id => $parent) {
        if ($parent > 0) {
            $children[$parent][] = $id;
        }
    }
    ksort($children, SORT_NUMERIC);
    foreach ($children as &$ids) {
        sort($ids, SORT_NUMERIC);
    }
    unset($ids);
    return $children;
}

/** @param array<int,int> $map @return list<array{category_tree_id:int,category_id:int}> */
function woo_hierarchy_test_category_rows(array $map): array {
    $rows = [];
    foreach ($map as $id => $_parent) {
        for ($cursor = $id; $cursor > 0; $cursor = $map[$cursor] ?? 0) {
            $rows[] = ['category_tree_id' => $cursor, 'category_id' => $id];
        }
    }
    usort($rows, static fn(array $left, array $right): int => [
        $left['category_tree_id'], $left['category_id'],
    ] <=> [
        $right['category_tree_id'], $right['category_id'],
    ]);
    return $rows;
}

function woo_hierarchy_test_set_option(string $name, mixed $value): void {
    global $wpdb;
    $store = WpStore::instance();
    $store->options[$name] = $value;
    $store->autoload[$name] = 'yes';
    $raw = serialize($value);
    $found = array_values(array_filter(
        $wpdb->rows($wpdb->options),
        static fn(array $row): bool => ($row['option_name'] ?? null) === $name
    ));
    if ($found === []) {
        $wpdb->insert($wpdb->options, [
            'option_name' => $name,
            'option_value' => $raw,
            'autoload' => 'yes',
        ]);
        return;
    }
    $wpdb->update($wpdb->options, ['option_value' => $raw], ['option_name' => $name]);
}

function woo_hierarchy_test_set_raw_option(string $name, string $raw, mixed $effective): void {
    global $wpdb;
    $store = WpStore::instance();
    $store->options[$name] = $effective;
    $store->autoload[$name] = 'yes';
    $wpdb->update($wpdb->options, ['option_value' => $raw], ['option_name' => $name]);
}

function woo_hierarchy_test_remove_option(string $name): void {
    global $wpdb;
    unset(WpStore::instance()->options[$name], WpStore::instance()->autoload[$name]);
    $wpdb->delete($wpdb->options, ['option_name' => $name]);
}

/** Install the exact state the real child must create, then return its bounded receipt. */
function woo_hierarchy_test_native_child(bool $flushRewrite): object {
    global $wpdb;
    $catMap = woo_hierarchy_test_parent_map('product_cat');
    $brandMap = woo_hierarchy_test_parent_map('product_brand');
    $wpdb->query('TRUNCATE TABLE wp_wc_category_lookup');
    foreach (woo_hierarchy_test_category_rows($catMap) as $row) {
        $wpdb->insert('wp_wc_category_lookup', $row);
    }
    woo_hierarchy_test_set_option('product_cat_children', woo_hierarchy_test_children($catMap));
    woo_hierarchy_test_set_option('product_brand_children', woo_hierarchy_test_children($brandMap));

    if ($flushRewrite) {
        $brandBase = (string) (WpStore::instance()->options['woocommerce_brand_permalink'] ?? 'brand');
        if ($brandBase === '') {
            $brandBase = 'brand';
        }
        woo_hierarchy_test_set_option('rewrite_rules', [
            '^' . $brandBase . '/(.+?)/?$' => 'index.php?product_brand=$matches[1]',
            '^shop/?$' => 'index.php?post_type=product',
        ]);
    }

    $snapshot = new ReflectionMethod(
        \Duo\Providers\WoocommerceHierarchyLookups::class,
        'projection_snapshot'
    );
    $after = $snapshot->invoke(null, true, $flushRewrite, false);
    return (object) [
        'return_code' => 0,
        'stdout' => json_encode(
            ['format' => 'duo-woocommerce-hierarchy-child/v1', 'after' => $after],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ),
        'stderr' => '',
    ];
}

$store = WpStore::reset()->seedOptions([
    'product_cat_children' => [9000000000 => [9000000002], 9000000001 => [9000000002]],
    'product_brand_children' => [8000000000 => [8000000002]],
    'rewrite_rules' => ['^stale/(.+)$' => 'index.php?stale=$matches[1]'],
    'woocommerce_brand_permalink' => 'maker-houses',
]);
$wpdb = FakeWpdb::install();
$wpdb->seedTable('wp_term_taxonomy', [
    ['term_taxonomy_id' => 1, 'term_id' => 9000000000, 'taxonomy' => 'product_cat', 'parent' => 0],
    ['term_taxonomy_id' => 2, 'term_id' => 9000000001, 'taxonomy' => 'product_cat', 'parent' => 9000000000],
    ['term_taxonomy_id' => 3, 'term_id' => 9000000002, 'taxonomy' => 'product_cat', 'parent' => 9000000001],
    ['term_taxonomy_id' => 4, 'term_id' => 8000000000, 'taxonomy' => 'product_brand', 'parent' => 0],
    ['term_taxonomy_id' => 5, 'term_id' => 8000000002, 'taxonomy' => 'product_brand', 'parent' => 8000000000],
])->setColumns('wp_term_taxonomy', [
    'term_taxonomy_id' => 'bigint(20) unsigned',
    'term_id' => 'bigint(20) unsigned',
    'taxonomy' => 'varchar(32)',
    'parent' => 'bigint(20) unsigned',
]);
$wpdb->seedTable('wp_wc_category_lookup', [
    ['category_tree_id' => 9000000000, 'category_id' => 9000000000],
    ['category_tree_id' => 9000000000, 'category_id' => 9000000001],
    ['category_tree_id' => 9000000000, 'category_id' => 9000000002],
    ['category_tree_id' => 9000000001, 'category_id' => 9000000001],
    ['category_tree_id' => 9000000002, 'category_id' => 9000000001],
    ['category_tree_id' => 9000000002, 'category_id' => 9000000002],
])->setColumns('wp_wc_category_lookup', [
    'category_tree_id' => 'bigint(20) unsigned',
    'category_id' => 'bigint(20) unsigned',
])->setUniqueKey('wp_wc_category_lookup', ['category_tree_id', 'category_id']);
$wpdb->seedTable('wp_options', [
    ['option_id' => 1, 'option_name' => 'product_cat_children', 'option_value' => serialize($store->options['product_cat_children']), 'autoload' => 'yes'],
    ['option_id' => 2, 'option_name' => 'product_brand_children', 'option_value' => serialize($store->options['product_brand_children']), 'autoload' => 'yes'],
    ['option_id' => 3, 'option_name' => 'rewrite_rules', 'option_value' => serialize($store->options['rewrite_rules']), 'autoload' => 'yes'],
    ['option_id' => 4, 'option_name' => 'woocommerce_brand_permalink', 'option_value' => 'maker-houses', 'autoload' => 'yes'],
])->setColumns('wp_options', [
    'option_id' => 'bigint(20) unsigned',
    'option_name' => 'varchar(191)',
    'option_value' => 'longtext',
    'autoload' => 'varchar(20)',
])->setUniqueKey('wp_options', ['option_name']);

WP_CLI::$handler = static function (string $command, array $options): object {
    duo_check(str_starts_with($command, 'eval ')
        && str_contains($command, 'WoocommerceHierarchyLookups::run_child('),
        'provider launches only its fixed shipped fresh-process entrypoint');
    duo_check_same(
        ['launch' => true, 'return' => 'all', 'exit_error' => false],
        $options,
        'WP-CLI child invocation is isolated and returns the complete process result'
    );
    $flushRewrite = str_contains($command, 'run_child(true)');
    if (WP_CLI::$mode === 'parent-race') {
        global $wpdb;
        $wpdb->update('wp_term_taxonomy', ['parent' => 9000000000], ['term_id' => 9000000002]);
    } elseif (WP_CLI::$mode === 'brand-route-race') {
        woo_hierarchy_test_set_option('woocommerce_brand_permalink', 'raced-maker-route');
    }
    return woo_hierarchy_test_native_child($flushRewrite);
};

$policy = Policy::load(null, ['woocommerce']);
$provider = new \Duo\Providers\WoocommerceHierarchyLookups($policy);
$capabilities = $provider->capabilities();
duo_check_same([
    'id' => 'woocommerce-hierarchy-lookups',
    'plugin' => 'woocommerce/woocommerce.php',
    'version' => '1.0.0',
], $provider->identity(), 'provider identity is exact and manifest-bindable');
duo_check_same(
    ['flush_rewrite' => ['type' => 'bool', 'required' => true]],
    $capabilities['rebuild_hierarchy_lookups']['args'] ?? null,
    'brand rewrite authority is an exact required boolean, never inferred from a dirty target'
);

$operation = [
    'format' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
    'authority_sha256' => str_repeat('a', 64),
    'session_sha256' => str_repeat('b', 64),
    'input_sha256' => str_repeat('c', 64),
    'effects_sha256' => str_repeat('d', 64),
];
$hierarchyArgs = ['flush_rewrite' => false];
$receipt = $provider->invoke_scoped('rebuild_hierarchy_lookups', $hierarchyArgs, $operation);
duo_check_same(false, $receipt['before']['category_lookup_valid'] ?? null,
    'same-count hostile category rows are observed as dirty before native regeneration');
duo_check_same(false, $receipt['before']['product_cat_children_valid'] ?? null,
    'same-count hostile hierarchy-option drift is observed instead of blessed');
duo_check(($receipt['after']['category_lookup_valid'] ?? false) === true
    && ($receipt['after']['category_lookup_rows'] ?? null) === 6
    && ($receipt['after']['product_cat_children'] ?? null) === 2
    && ($receipt['after']['product_brand_children'] ?? null) === 1,
    'native child repairs exact category closure and both hierarchy caches for divergent large term ids');
$receiptBytes = json_encode($receipt, JSON_THROW_ON_ERROR);
duo_check(!str_contains($receiptBytes, '9000000000')
    && !str_contains($receiptBytes, '8000000000')
    && preg_match('/^[a-f0-9]{64}$/D', (string) ($receipt['after']['category_lookup_sha256'] ?? '')) === 1,
    'bounded receipts retain counts and exact hashes without leaking term identities');

$idempotent = $provider->invoke_scoped('rebuild_hierarchy_lookups', $hierarchyArgs, $operation);
duo_check_same($receipt['after'], $idempotent['before'],
    'a second apply begins from the exact converged projection');
duo_check_same($receipt['after'], $idempotent['after'],
    'concurrent/retried idempotent repair produces the same exact receipt');

$exactAfter = $receipt['after'];
woo_hierarchy_test_set_option('product_cat_children', [9000000000 => [9000000002], 9000000001 => [9000000002]]);
duo_check_throws(
    static fn() => $provider->reconcile_scoped(
        'rebuild_hierarchy_lookups',
        $hierarchyArgs,
        $operation
    ),
    RuntimeException::class,
    'same-count hierarchy drift cannot retire the saved receipt',
    'hierarchy option disagrees'
);
$recovered = $provider->invoke_scoped('rebuild_hierarchy_lookups', $hierarchyArgs, $operation);
duo_check_same($exactAfter, $recovered['after'], 'retry restores exact hierarchy state after same-count drift');

$wpdb->delete('wp_wc_category_lookup', ['category_tree_id' => 9000000001, 'category_id' => 9000000002]);
duo_check_throws(
    static fn() => $provider->reconcile_scoped(
        'rebuild_hierarchy_lookups',
        $hierarchyArgs,
        $operation
    ),
    RuntimeException::class,
    'a missing category closure row is a loud recovery failure',
    'exact row projection mismatch'
);
$provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);

$wpdb->update('wp_term_taxonomy', ['parent' => 9000000000], ['term_id' => 9000000002]);
$moved = $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);
duo_check(($moved['after']['category_lookup_rows'] ?? null) === 5
    && ($moved['after']['product_cat_children'] ?? null) === 2
    && ($moved['after']['product_cat_parent_sha256'] ?? null)
        !== ($exactAfter['product_cat_parent_sha256'] ?? null),
    'moving a deep category parent regenerates exact closure and hierarchy bytes');

$wpdb->delete('wp_term_taxonomy', ['term_id' => 9000000001]);
$wpdb->update('wp_term_taxonomy', ['parent' => 0], ['term_id' => 9000000002]);
$removedParent = $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);
duo_check(($removedParent['after']['product_cat_terms'] ?? null) === 2
    && ($removedParent['after']['category_lookup_rows'] ?? null) === 2
    && ($removedParent['after']['product_cat_children'] ?? null) === 0,
    'parent removal and child reparenting remove stale closure and hierarchy rows');

// Restore the three-level category graph for malformed/failure cases.
$wpdb->insert('wp_term_taxonomy', [
    'term_taxonomy_id' => 2,
    'term_id' => 9000000001,
    'taxonomy' => 'product_cat',
    'parent' => 9000000000,
]);
$wpdb->update('wp_term_taxonomy', ['parent' => 9000000001], ['term_id' => 9000000002]);
$provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);

$callsBeforeMalformed = count(WP_CLI::$calls);
$wpdb->update('wp_term_taxonomy', ['parent' => 7777777777], ['term_id' => 9000000002]);
duo_check_throws(
    static fn() => $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs),
    RuntimeException::class,
    'dangling authored parent refuses before the native child can mutate derived state',
    'dangling parent'
);
duo_check_same($callsBeforeMalformed, count(WP_CLI::$calls),
    'pre-mutation hierarchy refusal launches no child process');
$wpdb->update('wp_term_taxonomy', ['parent' => 9000000001], ['term_id' => 9000000002]);

$objectPayload = serialize(new WooHierarchyWakeupCanary());
woo_hierarchy_test_set_raw_option(
    'product_cat_children',
    $objectPayload,
    new WooHierarchyWakeupCanary()
);
$objectRepair = $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);
duo_check_same(0, WooHierarchyWakeupCanary::$wakeups,
    'hostile serialized hierarchy bytes cross PlainData with zero object execution');
duo_check_same(true, $objectRepair['after']['product_cat_children_valid'] ?? null,
    'hostile derived option bytes are replaced through the native repair path');

WP_CLI::$mode = 'parent-race';
duo_check_throws(
    static fn() => $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs),
    RuntimeException::class,
    'a concurrent authored parent move cannot be blessed even when child and parent projections agree',
    'authored hierarchy source changed'
);
WP_CLI::$mode = 'success';
$wpdb->update('wp_term_taxonomy', ['parent' => 9000000001], ['term_id' => 9000000002]);
$provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);

WP_CLI::$mode = 'exit';
$failureMessage = '';
try {
    $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);
} catch (Throwable $failure) {
    $failureMessage = $failure->getMessage();
}
duo_check(str_contains($failureMessage, 'exited 23')
    && str_contains($failureMessage, 'recovery_required')
    && !str_contains($failureMessage, 'secret='),
    'child failure is loud/retryable and redacts stdout plus stderr');
WP_CLI::$mode = 'success';
duo_check(($provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs)['after']['category_lookup_valid'] ?? false) === true,
    'failed child invocation retries to exact convergence');

foreach (['stderr', 'trailing', 'mismatch'] as $mode) {
    WP_CLI::$mode = $mode;
    $message = '';
    try {
        $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);
    } catch (Throwable $failure) {
        $message = $failure->getMessage();
    }
    duo_check($message !== '' && str_contains($message, 'recovery_required')
        && !str_contains($message, 'secret='),
        "$mode child output cannot verify or leak through the repair boundary");
}
WP_CLI::$mode = 'success';

$rewriteArgs = ['flush_rewrite' => true];
WP_CLI::$mode = 'brand-route-race';
duo_check_throws(
    static fn() => $provider->invoke('rebuild_hierarchy_lookups', $rewriteArgs),
    RuntimeException::class,
    'a concurrent authored brand-route edit cannot be blessed with its matching regenerated rules',
    'authored hierarchy source changed'
);
WP_CLI::$mode = 'success';
woo_hierarchy_test_set_option('woocommerce_brand_permalink', 'maker-houses');
$routeReceipt = $provider->invoke_scoped('rebuild_hierarchy_lookups', $rewriteArgs, $operation);
duo_check(($routeReceipt['after']['rewrite_rules_valid'] ?? false) === true
    && ($routeReceipt['after']['rewrite_rules'] ?? null) === 2
    && preg_match('/^[a-f0-9]{64}$/D', (string) ($routeReceipt['after']['brand_permalink_sha256'] ?? '')) === 1,
    'brand permalink path flushes and binds exact fresh-process rewrite state');
$savedRoute = $routeReceipt['after'];
woo_hierarchy_test_set_option('rewrite_rules', [
    '^maker-houses/(.+?)/?$' => 'index.php?wrong=$matches[1]',
    '^shop/?$' => 'index.php?post_type=product',
]);
$routeDrift = $provider->reconcile_scoped(
    'rebuild_hierarchy_lookups',
    $rewriteArgs,
    $operation
);
duo_check(($routeDrift['after']['rewrite_rules'] ?? null) === ($savedRoute['rewrite_rules'] ?? null)
    && ($routeDrift['after']['rewrite_rules_effective_sha256'] ?? null)
        !== ($savedRoute['rewrite_rules_effective_sha256'] ?? null),
    'same-count rewrite drift changes the scoped recovery fingerprint');
$routeRecovered = $provider->invoke('rebuild_hierarchy_lookups', $rewriteArgs);
duo_check_same($savedRoute, $routeRecovered['after'], 'brand-route retry restores exact native rewrite state');

woo_hierarchy_test_remove_option('woocommerce_brand_permalink');
$defaultRoute = $provider->invoke('rebuild_hierarchy_lookups', $rewriteArgs);
duo_check(($defaultRoute['after']['rewrite_rules_valid'] ?? false) === true
    && ($defaultRoute['after']['brand_permalink_sha256'] ?? null) === hash('sha256', ''),
    'absent brand permalink uses Woo core default while remaining distinctly receipt-bound');

$wpdb->failNextQuery('secret=database-marker', 'term_taxonomy');
$dbFailure = '';
try {
    $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs);
} catch (Throwable $failure) {
    $dbFailure = $failure->getMessage();
}
duo_check(str_contains($dbFailure, 'provider checked read failed')
    && !str_contains($dbFailure, 'database-marker'),
    'checked parent-map read failure stays loud and redacts driver detail');
duo_check(($provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs)['after']['category_lookup_valid'] ?? false) === true,
    'checked-read failure retries cleanly');

$source = (string) file_get_contents($root . '/manifests/providers/woocommerce-hierarchy-lookups.php');
duo_check(str_contains($source, 'CategoryLookup')
    && str_contains($source, '->regenerate()')
    && str_contains($source, '_get_term_hierarchy($taxonomy)')
    && str_contains($source, 'clean_taxonomy_cache($taxonomy)')
    && str_contains($source, "wp_cache_delete('get', 'term-queries')"),
    'shipped child uses Woo/WordPress native writers after explicit persistent-cache invalidation');
duo_check(str_contains($source, 'flush_rewrite_rules(false)')
    && str_contains($source, '$wp_rewrite->rewrite_rules()')
    && str_contains($source, 'fresh native generation'),
    'brand route verification compares stored rules with a fresh native generation in the child');
duo_check(str_contains($source, 'PlainData::decode_serialized(')
    && !str_contains($source, 'maybe_unserialize('),
    'all raw serialized hierarchy/rewrite bytes use the shared class-disabled plain-data boundary');

$acyclic = new ReflectionMethod(
    \Duo\Providers\WoocommerceHierarchyLookups::class,
    'assert_acyclic_parent_map'
);
$longChain = [];
for ($termId = 1; $termId <= 50000; ++$termId) {
    $longChain[$termId] = $termId - 1;
}
$acyclic->invoke(null, $longChain, 'product_cat');
duo_check(true,
    'a 50k-deep acyclic hierarchy completes through the linear iterative graph validator');
duo_check_throws(
    static fn() => $acyclic->invoke(null, [1 => 2, 2 => 1], 'product_cat'),
    RuntimeException::class,
    'the linear graph validator still refuses an authored parent cycle',
    'contains a cycle'
);

$closure = new ReflectionMethod(
    \Duo\Providers\WoocommerceHierarchyLookups::class,
    'category_closure'
);
$overBoundChain = [];
for ($termId = 1; $termId <= 632; ++$termId) {
    $overBoundChain[$termId] = $termId - 1;
}
duo_check_throws(
    static fn() => $closure->invoke(null, $overBoundChain),
    RuntimeException::class,
    'a valid deep hierarchy refuses before allocating a category closure beyond the exact row cap',
    'bounded row contract'
);

$queries = $wpdb->queries();
duo_check(
    count(array_filter($queries, static fn(string $sql): bool =>
        str_contains($sql, 'SELECT term_id, parent FROM wp_term_taxonomy')
        && str_contains($sql, 'LIMIT 200001'))) > 0
    && count(array_filter($queries, static fn(string $sql): bool =>
        str_contains($sql, 'SELECT category_tree_id, category_id FROM `wp_wc_category_lookup`')
        && str_contains($sql, 'LIMIT 200001'))) > 0,
    'authored parent and derived lookup reads carry exact MAX+1 SQL limits'
);

$wpdb->setColumns('wp_wc_category_lookup', [
    'category_tree_id' => 'bigint(20) unsigned',
    'category_id' => 'bigint(20) unsigned',
    'addon_payload' => 'longtext',
]);
duo_check_throws(
    static fn() => $provider->invoke('rebuild_hierarchy_lookups', $hierarchyArgs),
    RuntimeException::class,
    'unknown category lookup schema refuses before native truncation',
    'unknown or missing columns'
);

duo_check_summary('WooCommerce hierarchy lookup provider');
