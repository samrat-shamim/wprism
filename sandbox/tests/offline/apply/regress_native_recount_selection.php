<?php
declare(strict_types=1);

// NativeRebuildExecutor must not reach plugin recount callbacks for unrelated
// writes. The callback below models WooCommerce's persistent cache deletion;
// invoking the real executor distinguishes selection from a pure predicate pin.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyPlanBuilder.php';

use WPrism\NativeRebuildExecutor;
use WPrism\Policy;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

function taxonomy_exists(string $taxonomy): bool { return true; }
function wp_clear_scheduled_hook(string $hook, array $args): int { return 0; }
function wp_update_term_count_now(array $ids, string $taxonomy): bool {
    $GLOBALS['recount_calls'][] = [$ids, $taxonomy];
    unset(WpStore::instance()->options['plugin_term_counts']);
    return !$GLOBALS['recount_refuses'];
}

$wpdb = new FakeWpdb();
$wpdb->seedTable('wprism_map', [['uuid' => 'selected', 'id_kind' => 'post', 'local_id' => 7]]);
$wpdb->seedTable('term_taxonomy', [['term_taxonomy_id' => 12, 'taxonomy' => 'product_cat']]);
$policy = new Policy();
$policy->site = ['policy' => ['taxonomies' => ['product_cat']]];
$executor = new NativeRebuildExecutor($policy, static function (): void {});
$effects = new ReflectionMethod(WPrism\ApplyPlanBuilder::class, 'engine_effect_sources');
$reset = static function (): void {
    $GLOBALS['recount_calls'] = [];
    $GLOBALS['recount_refuses'] = false;
    WpStore::instance()->options['plugin_term_counts'] = ['product_cat' => 17];
};
foreach (['table', 'options', 'sidebar', 'user_meta', 'unchanged'] as $type) {
    $reset();
    $tree = ['selected' => ['type' => $type], 'existing-term' => ['type' => 'term']];
    $work = $type === 'unchanged' ? [] : [['uuid' => 'selected']];
    $executor->run([], $work, $tree, [], false);
    wprism_check_same([], $GLOBALS['recount_calls'], "$type-only ordinary Apply never invokes a recount callback");
    wprism_check_same(['product_cat' => 17], WpStore::instance()->options['plugin_term_counts'], "$type-only Apply preserves unrelated persistent plugin cache");
    wprism_check_same(['object-cache-flush'], $effects->invoke(null, $work, $tree, [], false), "$type-only Plan omits unselected taxonomy effects");
}
foreach (['post', 'term', 'menu'] as $type) {
    $reset();
    $work = [['uuid' => 'selected']];
    $tree = ['selected' => ['type' => $type, 'data' => ['status' => 'publish']]];
    $executor->run([], $work, $tree, [], false);
    wprism_check_same([[[12], 'product_cat']], $GLOBALS['recount_calls'], "$type mutation still invokes the registered native callback");
    wprism_check(in_array('taxonomy-counts', $effects->invoke(null, $work, $tree, [], false), true), "$type mutation declares taxonomy effects");
}
foreach (['post', 'term', 'menu', 'option', 'table'] as $kind) {
    $reset();
    $deletions = [['deletion_kind' => $kind]];
    $expected = in_array($kind, ['post', 'term', 'menu'], true);
    $executor->run([], [], [], $deletions, false);
    wprism_check_same($expected, $GLOBALS['recount_calls'] !== [], "$kind deletion selects only relevant native recounts");
    wprism_check_same($expected, in_array('taxonomy-counts', $effects->invoke(null, [], [], $deletions, false), true), "$kind deletion Plan agrees with execution");
}
$reset();
$executor->run([], [], [], [], true);
wprism_check_same([[[12], 'product_cat']], $GLOBALS['recount_calls'], 'interrupted Apply retains native recount recovery even with no surviving authored work');
wprism_check(in_array('taxonomy-counts', $effects->invoke(null, [], [], [], true), true), 'interrupted Plan discloses conservative recovery recount');
wprism_check(NativeRebuildExecutor::needs_taxonomy_recount([['uuid' => 'post']], ['post' => ['type' => 'post']], []), 'post status/relationship changes remain taxonomy-sensitive');
$reset();
$GLOBALS['recount_refuses'] = true;
wprism_check_throws(static fn() => $executor->run([], [], [], [], true), RuntimeException::class, 'a required callback failure still refuses recovery', 'registered recount callback failed');
wprism_check_summary('regress-native-recount-selection');
