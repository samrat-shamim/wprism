<?php
declare(strict_types=1);

// WPForms native recon 2026-09-07: a ready init failed only during baseline
// Capture on its stored, unassigned widget. Exercise the real proposal and
// SidebarState with shared SQL rows; no plugin-specific init branch is valid.
require_once __DIR__ . '/../../lib/check.php';

use WPrism\Canon;
use WPrism\InitPlanner;
use WPrism\Policy;
use WPrism\SidebarState;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

$root = dirname(__DIR__, 4);
$scratch = realpath(sys_get_temp_dir()) . '/wprism-init-widgets-' . bin2hex(random_bytes(8));
mkdir($scratch, 0700);
foreach (['site', 'wp-content', 'wp-content/plugins', 'wp-content/themes', 'wp-content/mu-plugins'] as $directory) {
    mkdir($scratch . '/' . $directory, 0700);
}
register_shutdown_function(static function () use ($scratch): void {
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($scratch, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir() && !$item->isLink()) rmdir($item->getPathname());
        else unlink($item->getPathname());
    }
    rmdir($scratch);
});
define('ABSPATH', $scratch . '/');
define('WP_CONTENT_DIR', $scratch . '/wp-content');
define('WP_PLUGIN_DIR', WP_CONTENT_DIR . '/plugins');
define('WPMU_PLUGIN_DIR', WP_CONTENT_DIR . '/mu-plugins');
foreach (['WPRISM_AGENT_VERSION', 'WPRISM_SPEC_VERSION'] as $constant) {
    preg_match("/define\\('" . $constant . "',\\s*('?)([^')]+)\\1\\)/", (string) file_get_contents($root . '/agent/wprism.php'), $match);
    define($constant, $constant === 'WPRISM_SPEC_VERSION' ? (int) $match[2] : $match[2]);
}
function get_theme_root(): string { return WP_CONTENT_DIR . '/themes'; }
function get_taxonomies(array $arguments = [], string $output = 'names'): array { return []; }
function get_mu_plugins(): array { return []; }
function get_dropins(): array { return []; }
function get_posts(array $arguments): array {
    if (($arguments['post_type'] ?? null) !== 'attachment' || ($arguments['fields'] ?? null) !== 'ids') {
        throw new LogicException('init widget fixture admits only the empty attachment observation');
    }
    return [];
}

require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once $root . '/agent/src/Init/InitPlanner.php';
require_once $root . '/agent/src/Repository/Snapshot.php';
require_once $root . '/agent/src/Repository/SidebarState.php';
require_once $root . '/agent/src/Grammar/Tokens.php';
require_once $root . '/cli/src/Onboarding/Init.php';

$store = WpStore::reset()->seedOptions(['home' => 'https://init.example.test', 'active_plugins' => []]);
$wpdb = FakeWpdb::install();
$family = [7 => ['title' => 'Private native widget', 'form_id' => 37], '_multiwidget' => 1];
$seed = static function (array $sidebars = [], ?array $nativeFamily = null) use ($wpdb, $family): array {
    $rows = [
        ['option_id' => 1, 'option_name' => 'widget_unmanaged-form', 'option_value' => serialize($nativeFamily ?? $family), 'autoload' => 'yes'],
        ['option_id' => 2, 'option_name' => 'widget_empty', 'option_value' => serialize(['_multiwidget' => 1]), 'autoload' => 'yes'],
        ['option_id' => 3, 'option_name' => 'widget_text', 'option_value' => serialize(['_multiwidget' => 1]), 'autoload' => 'yes'],
        ['option_id' => 4, 'option_name' => 'sidebars_widgets', 'option_value' => serialize($sidebars + ['array_version' => 3]), 'autoload' => 'yes'],
    ];
    $wpdb->seedTable('wp_options', $rows);
    foreach (['wp_posts', 'wp_postmeta', 'wp_terms', 'wp_term_taxonomy', 'wp_termmeta', 'wp_usermeta'] as $table) {
        $wpdb->seedTable($table, [])->setTableEngine($table, 'InnoDB');
    }
    return $rows;
};
$before = $seed();
$proposal = InitPlanner::proposal($scratch . '/site', true);
wprism_check_same([], $proposal['unsupported'], 'empty-code fixture has no unrelated init blocker');
wprism_check_same(true, $proposal['ready'], 'unassigned undeclared widget can stay explicitly local at initialization');
$proposedOptions = (array) $proposal['state']['config']['policy']['options'];
wprism_check_same(['class' => 'runtime'], $proposedOptions['widget_unmanaged-form'] ?? null,
    'the actual init proposal records the populated undeclared family as a reviewed local exclusion');
$policy = new Policy();
$policy->site = Canon::decode(Canon::encode($proposal['state']['config']));
$policy->manifests = [Canon::decode(Canon::read_file($root . '/platform/adapter-library/core/manifest.json'))];
try {
    $captured = SidebarState::capture($policy, new Tokens(), false);
    wprism_check_same([], $captured['entities'], 'real sidebar capture accepts the proposed exclusion without claiming inactive settings');
} catch (Throwable $failure) {
    wprism_check(false, 'real sidebar capture must accept the ready proposal: ' . $failure->getMessage());
}
wprism_check_same($before, $wpdb->rows('wp_options'), 'proposal and sidebar capture preserve every native option byte');
wprism_check_same([], array_values(array_diff(scandir($scratch . '/site'), ['.', '..'])), 'proposal publishes no repository state');
wprism_check_same(['widget_unmanaged-form'], array_keys($proposedOptions), 'init writes an exact exclusion, never a blanket widget pattern or empty/declared family rule');
wprism_check(!str_contains(Canon::encode($proposal), 'Private native widget'), 'proposal contains no excluded native settings');
$rendered = implode("\n", WPrism\Orchestrator\Init::render($proposal));
wprism_check(str_contains($rendered, 'UNMANAGED WIDGET widget_unmanaged-form [unmanaged_widget_left_local]'),
    'the real host renderer discloses the proposed exclusion before confirmation');
$core = $policy->manifests[0];
$barePolicy = static function (array $options = []) use ($core): Policy {
    $result = new Policy();
    $result->site = ['policy' => ['options' => $options]];
    $result->manifests = [$core];
    return $result;
};
wprism_check_throws(static fn() => SidebarState::capture($barePolicy(), new Tokens(), false), RuntimeException::class,
    'ordinary capture still refuses an unacknowledged stored widget', "contains instances but type 'unmanaged-form' is undeclared");

$before = $seed(['wp_inactive_widgets' => ['unmanaged-form-7']]);
$parked = InitPlanner::proposal($scratch . '/site', true);
wprism_check_same(true, $parked['ready'], 'a parked inactive assignment is local, not an authored sidebar boundary');
wprism_check_same(['class' => 'runtime'], ((array) $parked['state']['config']['policy']['options'])['widget_unmanaged-form'],
    'parked and unassigned native instances receive the same exact exclusion');
wprism_check_same($before, $wpdb->rows('wp_options'), 'the parked assignment and its settings are not removed');

$before = $seed(['sidebar-1' => ['unmanaged-form-7']]);
$active = InitPlanner::proposal($scratch . '/site', true);
wprism_check_same(false, $active['ready'], 'actual init blocks an undeclared active widget before any baseline work');
wprism_check_same(['undeclared_active_widget'], array_column($active['unsupported'], 'code'),
    'active ownership gets its own actionable blocker rather than a false ready proposal');
wprism_check_same('sidebar-1:unmanaged-form', $active['unsupported'][0]['extension'], 'the blocker names the full layout and missing widget type');
wprism_check_same([], (array) $active['state']['config']['policy']['options'], 'a blocked active layout is not relabelled runtime');
wprism_check_throws(static fn() => InitPlanner::assert_confirmed_proposal($active, $proposal['digest']), RuntimeException::class,
    'a new active assignment invalidates the earlier confirmed proposal');
foreach (['runtime', 'env'] as $class) {
    $localPolicy = $barePolicy(['widget_unmanaged-form' => ['class' => $class]]);
    wprism_check_same(['undeclared_active_widget'], array_column(InitPlanner::unmanaged_widgets($localPolicy)['blockers'], 'code'),
        "$class option classification cannot authorize an incomplete active sidebar");
    wprism_check_throws(static fn() => SidebarState::capture($localPolicy, new Tokens(), false), RuntimeException::class,
        "the existing active-sidebar capture guard survives $class exclusion", "contains undeclared widget type 'unmanaged-form'");
}
wprism_check_same($before, $wpdb->rows('wp_options'), 'active blockers and refused capture preserve the complete native layout');

$seed();
foreach (['runtime', 'env'] as $class) {
    $localPolicy = $barePolicy(['widget_unmanaged-form' => ['class' => $class]]);
    wprism_check_same(['options' => [], 'advisories' => [], 'blockers' => []], InitPlanner::unmanaged_widgets($localPolicy),
        "an existing $class exclusion is retained without a replacement decision");
}
foreach (['authored', 'derived'] as $class) {
    $ownedPolicy = $barePolicy(['widget_unmanaged-form' => ['class' => $class]]);
    $decision = InitPlanner::unmanaged_widgets($ownedPolicy);
    wprism_check_same([], $decision['options'], "init never downgrades a selected $class declaration");
    wprism_check_same(['widget_classification_without_grammar'], array_column($decision['blockers'], 'code'),
        "a $class family without structural grammar remains a named blocker");
}
$declaredPolicy = $barePolicy();
$declaredPolicy->manifests[] = ['name' => 'native-form-widget', 'widgets' => [
    'unmanaged-form' => ['settings' => ['title' => new stdClass(), 'form_id' => ['ref' => 'post']]],
]];
wprism_check_same(['options' => [], 'advisories' => [], 'blockers' => []], InitPlanner::unmanaged_widgets($declaredPolicy),
    'a selected widget grammar owns the family; init invents no competing option classification');

$seed([], ['_multiwidget' => 1]);
$empty = InitPlanner::proposal($scratch . '/site', true);
wprism_check_same("{}\n", Canon::encode($empty['state']['config']['policy']['options'], false),
    'marker-only widget families retain the legacy empty options object');
wprism_check_same([], array_values(array_filter($empty['advisories'], static fn(array $row): bool => $row['code'] === 'unmanaged_widget_left_local')),
    'an empty family has no invented ownership decision');
$seed([], [7 => ['title' => 'Only native settings changed', 'form_id' => 91], '_multiwidget' => 1]);
$changedSettings = InitPlanner::proposal($scratch . '/site', true);
wprism_check_same($proposal['digest'], $changedSettings['digest'], 'excluded native settings do not become part of proposal identity');
$seed([], [7 => ['title' => 'one'], 11 => ['title' => 'two'], '_multiwidget' => 1]);
$changedCount = InitPlanner::proposal($scratch . '/site', true);
wprism_check($proposal['digest'] !== $changedCount['digest'], 'the disclosed exclusion count participates in the reviewed digest');

foreach ([serialize(['_multiwidget' => 2]), serialize([0 => ['title' => 'invalid']]), 'not a multi-instance array', 'O:8:"stdClass":0:{}'] as $raw) {
    $rows = $seed();
    $rows[0]['option_value'] = $raw;
    $wpdb->seedTable('wp_options', $rows);
    wprism_check_throws(static fn() => InitPlanner::proposal($scratch . '/site', true), RuntimeException::class,
        'actual proposal refuses malformed native widget bytes instead of acknowledging them as runtime');
    wprism_check_same($rows, $wpdb->rows('wp_options'), 'malformed-family refusal preserves all physical option rows');
}
$rows = $seed();
$rows[] = ['option_id' => 5, 'option_name' => 'widget_UNMANAGED-FORM', 'option_value' => serialize($family), 'autoload' => 'yes'];
$wpdb->seedTable('wp_options', $rows);
wprism_check_throws(static fn() => InitPlanner::proposal($scratch . '/site', true), RuntimeException::class,
    'proposal reuses the bounded reader collation-alias refusal', 'duplicate/collation-alias rows');
$seed();
$wpdb->failNextQuery('injected preflight failure', 'OCTET_LENGTH(option_value) AS option_value_bytes');
wprism_check_throws(static fn() => InitPlanner::proposal($scratch . '/site', true), RuntimeException::class,
    'a failed widget preflight is never an empty unmanaged inventory', 'bounded widget option size preflight failed');
$wpdb->failNextQuery('injected value failure', 'SELECT option_name, option_value FROM');
wprism_check_throws(static fn() => InitPlanner::proposal($scratch . '/site', true), RuntimeException::class,
    'a failed widget value read is never an empty unmanaged inventory', 'bounded widget option read failed');
$seed();
$descriptor = ['option_name' => 'widget_unmanaged-form', 'option_value_bytes' => '16777217', 'option_value_sha256' => str_repeat('a', 64)];
$queryStart = count($wpdb->queries());
$wpdb->returnNextGetResultsAs([$descriptor], 'OCTET_LENGTH(option_value) AS option_value_bytes');
wprism_check_throws(static fn() => InitPlanner::proposal($scratch . '/site', true), RuntimeException::class,
    'oversized widget values refuse at the existing preflight frontier', 'bounded value frontier');
wprism_check_same([], array_values(array_filter(array_slice($wpdb->queries(), $queryStart), static fn(string $sql): bool => str_contains($sql, 'SELECT option_name, option_value FROM'))),
    'oversized widget settings never cross the native value reader');
$queryStart = count($wpdb->queries());
$wpdb->returnNextGetResultsAs(array_fill(0, 2049, $descriptor), 'OCTET_LENGTH(option_value) AS option_value_bytes');
wprism_check_throws(static fn() => InitPlanner::proposal($scratch . '/site', true), RuntimeException::class,
    'an oversized family roster refuses before any settings are fetched', 'bounded row limit');
wprism_check_same([], array_values(array_filter(array_slice($wpdb->queries(), $queryStart), static fn(string $sql): bool => str_contains($sql, 'SELECT option_name, option_value FROM'))),
    'a rejected family roster cannot turn into an unbounded value fetch');
wprism_check_same([], $wpdb->ddlLog(), 'all readiness paths remain free of database schema writes');
wprism_check_same([], array_values(array_filter($wpdb->queries(), static fn(string $sql): bool => preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE)\b/i', $sql) === 1)),
    'all readiness paths and their sidebar controls remain free of database data writes');
wprism_check_same([], array_values(array_diff(scandir($scratch . '/site'), ['.', '..'])), 'every refusal leaves the target repository byte-empty');
wprism_check_summary('init widgets');
