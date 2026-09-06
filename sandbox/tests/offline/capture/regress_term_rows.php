<?php
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/TermRows.php';
require_once dirname(__DIR__, 4) . '/agent/src/Kernel/Db.php';
require_once dirname(__DIR__, 4) . '/agent/src/Adapter/ProviderSdk.php';

use WPrism\TermRows;
use WPrismTest\FakeWpdb;

$seed = static function (int $count = 1): FakeWpdb {
    $db = FakeWpdb::install();
    $terms = $taxonomies = [];
    for ($i = 1; $i <= $count; $i++) {
        $terms[] = ['term_id' => 100 + $i, 'name' => 'Name 東京 ' . $i, 'slug' => 'term-' . $i, 'term_group' => 0];
        $taxonomies[] = ['term_taxonomy_id' => 200 + $i, 'term_id' => 100 + $i, 'taxonomy' => 'fixture',
            'description' => "raw\0bytes", 'parent' => 0, 'count' => 0];
    }
    return $db->seedTable('wp_terms', $terms)->seedTable('wp_term_taxonomy', $taxonomies)
        ->setColumns('wp_terms', ['term_id' => 'bigint', 'name' => 'varchar(200)', 'slug' => 'varchar(200)', 'term_group' => 'bigint'])
        ->setColumns('wp_term_taxonomy', ['term_taxonomy_id' => 'bigint', 'term_id' => 'bigint', 'taxonomy' => 'varchar(32)',
            'description' => 'longtext', 'parent' => 'bigint', 'count' => 'bigint']);
};
$observe = static fn(int $rows = 128, int $bytes = 4194304): array => TermRows::taxonomy('fixture', $rows, $bytes, 'term fixture');
$db = $seed();
$rows = $observe();
wprism_check_same([['term_id' => '101', 'name' => 'Name 東京 1', 'slug' => 'term-1', 'term_group' => '0',
    'term_taxonomy_id' => '201', 'taxonomy' => 'fixture', 'description' => "raw\0bytes", 'parent' => '0', 'count' => '0']],
    $rows, 'term observation preserves every exact driver-shaped field, including binary description bytes');
$exactBytes = array_sum(array_map('strlen', $rows[0]));
wprism_check_same($rows, $observe(1, $exactBytes), 'exact row and aggregate byte frontiers are inclusive');
wprism_check_throws(static fn() => $observe(1, $exactBytes - 1), RuntimeException::class, 'aggregate frontier minus one refuses before values');
$db = $seed(65);
wprism_check_same(65, count($observe()), 'a second bounded batch preserves the complete taxonomy');
wprism_check_throws(static fn() => $observe(64), RuntimeException::class, 'one row beyond the caller budget refuses');
$db = $seed(0);
wprism_check_same([], $observe(), 'a genuinely empty taxonomy is an exact empty observation');
foreach ([['', 1, 1], ['fixture', 0, 1], ['fixture', 4097, 1], ['fixture', 1, 0], ['fixture', 1, 67108865]] as $arguments) {
    wprism_check_throws(static fn() => TermRows::taxonomy(...[...$arguments, 'fixture']), InvalidArgumentException::class,
        'invalid taxonomy or unbounded caller budget refuses');
}
$db = $seed();
wprism_check_same([], TermRows::taxonomy("x' OR '1'='1", 128, 4194304, 'fixture'), 'taxonomy values remain bound data, never SQL predicates');
$db->terms = 'wp_terms; DROP TABLE wp_terms';
wprism_check_throws($observe, RuntimeException::class, 'unsafe physical table binding refuses');
foreach (['orphan', 'duplicate-coordinate', 'duplicate-identity', 'oversized-name', 'oversized-description', 'null-description', 'noncanonical-id'] as $fault) {
    $db = $seed();
    $terms = $db->rows('wp_terms');
    $taxonomies = $db->rows('wp_term_taxonomy');
    if ($fault === 'orphan') $terms = [];
    if ($fault === 'duplicate-coordinate') $taxonomies[] = array_replace($taxonomies[0], ['term_taxonomy_id' => 202]);
    if ($fault === 'duplicate-identity') $taxonomies[] = $taxonomies[0];
    if ($fault === 'oversized-name') $terms[0]['name'] = str_repeat('x', 801);
    if ($fault === 'oversized-description') $taxonomies[0]['description'] = str_repeat('x', 16777217);
    if ($fault === 'null-description') $taxonomies[0]['description'] = null;
    if ($fault === 'noncanonical-id') $terms[0]['term_id'] = $taxonomies[0]['term_id'] = '0101';
    $db->seedTable('wp_terms', $terms)->seedTable('wp_term_taxonomy', $taxonomies);
    $queries = [];
    $db->onQuery(static function (string $sql) use (&$queries): void { $queries[] = $sql; });
    wprism_check_throws($observe, RuntimeException::class, "$fault cannot enter a physical term snapshot");
    if (in_array($fault, ['orphan', 'duplicate-identity', 'oversized-name', 'oversized-description', 'null-description'], true)) {
        wprism_check_same(1, count($queries), "$fault stops before hashing or transporting payloads");
    }
}
foreach ([1, 2, 3, 4] as $failAt) {
    $db = $seed();
    $query = 0;
    $db->onQuery(static function (string $sql) use (&$query, $failAt): ?string {
        return ++$query === $failAt ? 'private driver failure' : null;
    });
    wprism_check_throws($observe, RuntimeException::class, "failed physical read $failAt cannot masquerade as absence", 'checked database read failed');
}
foreach (['grow-before-hash', 'grow-before-values', 'same-size-before-values', 'append-before-final-roster'] as $fault) {
    $db = $seed();
    $query = 0;
    $db->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (&$query, $fault): void {
        $query++;
        $at = match ($fault) { 'grow-before-hash' => 2, 'append-before-final-roster' => 4, default => 3 };
        if ($query !== $at) return;
        $rows = $db->rows('wp_term_taxonomy');
        if ($fault === 'append-before-final-roster') $rows[] = array_replace($rows[0], ['term_taxonomy_id' => 202]);
        else $rows[0]['description'] = $fault === 'same-size-before-values' ? "new\0bytes" : str_repeat('x', 4194305);
        $db->seedTable('wp_term_taxonomy', $rows);
    });
    wprism_check_throws($observe, RuntimeException::class, "$fault cannot use a stale size/hash roster");
}
$db = $seed()->enableInformationSchema()->setTableEngine('wp_terms', 'InnoDB')->setTableEngine('wp_term_taxonomy', 'InnoDB');
$sdkObserve = static fn(): array => \WPrism\ProviderSdk::term_rows('fixture', 128, 4194304, 'term fixture');
wprism_check_throws($sdkObserve, RuntimeException::class, 'SDK term observation cannot invent a missing transaction profile');
\WPrism\Db::start_read_only_consistent_snapshot('term fixture missing authority', \WPrism\NativeDatabaseProfile::read_only(['wp_terms']));
try {
    wprism_check_throws($sdkObserve, \WPrism\DatabaseQueryIsolationViolationException::class,
        'SDK term observation cannot enlarge the caller physical profile');
} finally {
    \WPrism\Db::rollback('term fixture missing authority cleanup');
}
\WPrism\Db::start_read_only_consistent_snapshot('term fixture admitted authority',
    \WPrism\NativeDatabaseProfile::read_only(['wp_terms', 'wp_term_taxonomy']));
try {
    wprism_check_same($observe(), $sdkObserve(), 'SDK preserves the bounded physical witness inside the caller snapshot');
} finally {
    \WPrism\Db::rollback('term fixture admitted authority cleanup');
}
wprism_check_summary('regress_term_rows');
