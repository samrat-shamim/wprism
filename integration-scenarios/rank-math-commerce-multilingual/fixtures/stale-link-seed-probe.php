<?php
declare(strict_types=1);

/** Runs the caller's actual wp-eval seed against shared SQL and native API doubles. */
require_once dirname(__DIR__, 3) . '/sandbox/tests/lib/FakeWpdb.php';

use WPrismTest\FakeWpdb;

[$script, $fault, $program, $witness] = $argv;
$wpdb = FakeWpdb::install();
foreach (['rank_math_internal_links', 'rank_math_internal_meta'] as $table) {
    $wpdb->seedTable($table, [['id' => 17, 'fixture' => 'prior-derived-state']]);
}
$wpdb->seedTable('rank_math_redirections', [['id' => 1, 'hits' => 41]])
    ->seedTable('actionscheduler_actions', [['action_id' => 6, 'hook' => 'target-runtime']]);
$meta = [101 => ['authored' => 'preserved']];

class WP_Post {
    public function __construct(public int $ID) {}
}

function get_page_by_path(string $slug, string $output, string $type): ?WP_Post {
    global $fault;
    $rows = ['rmcombo-product-en' => [101, 'product'], 'rmcombo-product-de' => [102, 'product'],
        'rmcombo-book' => [103, 'rmcombo_book'], 'rmcombo-target-neighbor' => [104, 'product']];
    if ($output !== OBJECT || !isset($rows[$slug]) || $rows[$slug][1] !== $type) {
        throw new RuntimeException('native post observation used the wrong selector');
    }
    if ($fault === 'missing-post' && $slug === 'rmcombo-book') return null;
    return new WP_Post($fault === 'overlap' ? 101 : $rows[$slug][0]);
}

function update_post_meta(int $id, string $key, string $value): bool {
    global $fault, $meta;
    if ($fault === 'marker') return false;
    $meta[$id][$key] = $value;
    return true;
}

function get_post_meta(int $id, string $key, bool $single): string {
    global $meta;
    return $meta[$id][$key] ?? '';
}

function wp_json_encode(mixed $value): string {
    return json_encode($value, JSON_THROW_ON_ERROR);
}

$matches = [
    'delete-links' => 'DELETE FROM wp_rank_math_internal_links',
    'delete-counts' => 'DELETE FROM wp_rank_math_internal_meta',
    'insert-links' => 'INSERT INTO `wp_rank_math_internal_links`',
    'insert-counts' => 'INSERT INTO `wp_rank_math_internal_meta`',
    'read-links' => 'SELECT COUNT(*) FROM wp_rank_math_internal_links',
    'read-counts' => 'SELECT COUNT(*) FROM wp_rank_math_internal_meta',
];
if (isset($matches[$fault])) $wpdb->failNextQuery('private-seed-sql-canary', $matches[$fault]);
if ($fault === 'short-count') {
    $wpdb->onQuery(static function (string $sql, string $method, FakeWpdb $db): null {
        if ($sql === 'SELECT COUNT(*) FROM wp_rank_math_internal_links') {
            $db->seedTable('rank_math_internal_links', []);
        }
        return null;
    });
}
if ($fault === 'extra') echo "{}\n";
if ($fault === 'warning-stdout') echo "PHP Warning: native-seed-transport-diagnostic in Unknown on line 0\n";
if ($fault === 'warning-stderr') fwrite(STDERR, "PHP Warning: native-seed-transport-diagnostic in Unknown on line 0\n");
if ($fault === 'owned-compose') fwrite(STDERR, " Container wprism-rmcomboseed-cli2-run-aabbcc Created \n");
if ($fault === 'foreign-compose') fwrite(STDERR, " Container wprism-foreignseed-cli2-run-aabbcc Created \n");

try {
    eval($program);
} finally {
    file_put_contents($witness, json_encode([
        'queries' => $wpdb->queries(), 'links' => $wpdb->rows('rank_math_internal_links'),
        'counts' => $wpdb->rows('rank_math_internal_meta'), 'meta' => $meta,
        'redirections' => $wpdb->rows('rank_math_redirections'),
        'scheduler' => $wpdb->rows('actionscheduler_actions'),
    ], JSON_THROW_ON_ERROR));
}
if ($fault === 'nonzero') exit(7);
