<?php
declare(strict_types=1);

use WPrism\ManifestProviderRuntime;
use WPrism\ProviderSdk;
use WPrism\Providers;

function native_permalink_vectors(array $ids, string $home): array {
    $statuses = ['publish', 'draft', 'pending', 'future', 'private'];
    $post = $page = [];
    foreach ($statuses as $status) {
        $post[] = $home . ($status === 'publish' ? '/2026/post-publish/' : '/?p=' . $ids['post_' . $status]);
        $page[] = $home . ($status === 'publish' ? '/grand/parent/page-publish/' : '/?page_id=' . $ids['page_' . $status]);
    }
    return [
        'post_statuses' => ['home' => $home, 'permalinks' => $post],
        'page_statuses' => ['home' => $home, 'permalinks' => $page],
        'warm_pages' => ['home' => $home, 'permalinks' => $page],
        'custom_types' => ['home' => $home, 'permalinks' => [$home . '/library/grand/parent/book/',
            $home . '/?post_type=wprism_probe_book&p=' . $ids['book_private']]],
        'builtin_template' => ['home' => $home, 'permalinks' => [$home . '/2026/native-template/']],
        'front_page' => ['home' => $home, 'permalinks' => [$home . '/']],
        'plain' => ['home' => $home, 'permalinks' => [$home . '/?p=' . $ids['post_publish'],
            $home . '/?page_id=' . $ids['page_publish'], $home . '/?wprism_probe_book=grand/parent/book']],
        'index' => ['home' => $home, 'permalinks' => [$home . '/index.php/grand/parent/page-publish/']],
        'no_slash' => ['home' => $home, 'permalinks' => [$home . '/grand/parent/page-publish']],
        'absent' => ['home' => $home, 'permalinks' => [false]],
        'constant_home' => ['home' => 'http://constant.example.test/base',
            'permalinks' => ['http://constant.example.test/base/2026/post-publish/']],
    ];
}

function native_permalink_refusal_names(): array {
    return ['stale_primary', 'stale_ancestor', 'stale_home_cache', 'stale_page_structure', 'foreign_home_hook',
        'foreign_capability_hook', 'observer_callback', 'swallowed_failure'];
}

if (($argv[1] ?? '') === '--admit') {
    require_once __DIR__ . '/../lib/PrivateCommandOutput.php';
    $data = json_decode(WPrismTest\PrivateCommandOutput::readObject($argv[2] ?? '',
        '/^ ?Container wprism-[a-z0-9]+-cli1-run-[a-z0-9]+ (Creating|Created) *$/D'), true, 64, JSON_THROW_ON_ERROR);
    $home = $argv[3] ?? '';
    $keys = ['format', 'engine', 'ids', 'initial', 'final', 'observations', 'refusals'];
    if (array_keys($data) !== $keys || $data['format'] !== 'wprism-native-permalinks/v1' || $data['engine'] !== 'MariaDB'
        || preg_match('#^http://[a-z][a-z0-9]+1\.invalid$#D', $home) !== 1 || !is_array($data['ids'])) {
        throw new RuntimeException('native permalink evidence has a different source-independent contract');
    }
    $idKeys = ['grand', 'parent'];
    foreach (['post', 'page'] as $type) {
        foreach (['publish', 'draft', 'pending', 'future', 'private'] as $status) $idKeys[] = $type . '_' . $status;
    }
    array_push($idKeys, 'book_publish', 'book_private', 'template', 'absent');
    if (array_keys($data['ids']) !== $idKeys || count(array_unique($data['ids'], SORT_REGULAR)) !== count($idKeys)) {
        throw new RuntimeException('native permalink evidence has a different identity inventory');
    }
    foreach ($data['ids'] as $id) {
        if (!is_int($id) || $id < 1) throw new RuntimeException('native permalink evidence has a malformed identity');
    }
    foreach (['initial', 'final'] as $phase) {
        if (!is_array($data[$phase]) || array_keys($data[$phase]) !== ['posts', 'options']) {
            throw new RuntimeException('native permalink preservation evidence is incomplete');
        }
        foreach ($data[$phase] as $witness) {
            if (!is_array($witness) || array_keys($witness) !== ['row_count', 'raw_bytes', 'rows_sha256']
                || !is_int($witness['row_count']) || $witness['row_count'] < 1 || !is_int($witness['raw_bytes'])
                || $witness['raw_bytes'] < 1 || !is_string($witness['rows_sha256'])
                || preg_match('/^[a-f0-9]{64}$/D', $witness['rows_sha256']) !== 1) {
                throw new RuntimeException('native permalink preservation witness is empty or malformed');
            }
        }
    }
    if ($data['initial'] !== $data['final'] || $data['observations'] !== native_permalink_vectors($data['ids'], $home)
        || $data['refusals'] !== native_permalink_refusal_names()) {
        throw new RuntimeException('native permalink evidence disagrees with the independent URL or preservation vectors');
    }
    exit(0);
}

if (!defined('ABSPATH') || !class_exists(ProviderSdk::class)) throw new RuntimeException('native permalink fixture requires the product SDK');

/** Fixture-only loader join; this proves the actual SDK, not Policy-loaded adapter conformance. */
final class NativePermalinkProbe extends ManifestProviderRuntime {
    private static ?Closure $operation = null;
    private static mixed $result = null;

    public static function run(bool $write, callable $operation): mixed {
        $runtime = new self(['source' => 'manifest', 'id' => 'native-permalink-probe', 'plugin' => 'fixture/fixture.php',
            'version' => '1.0.0', 'capabilities' => ['native_links'], 'contracts' => ['native_links' => [
                'args' => [], 'idempotent' => true, 'scope' => 'site', 'timeout_seconds' => 120,
                'reads' => $write ? [] : ['table:posts', 'table:options'],
                'writes' => $write ? ['table:posts', 'table:options'] : [],
            ]]]);
        (new ReflectionMethod(Providers::class, 'bind_manifest_runtime_contracts'))->invoke(null, $runtime, $runtime->capabilities());
        self::$operation = $write
            ? static fn(): mixed => ProviderSdk::database_write_contract_transaction('native permalink fixture mutation', $operation,
                static fn() => ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN)
            : static fn(): mixed => ProviderSdk::database_read_contract_snapshot('native permalink fixture observation', $operation);
        try { $runtime->invoke('native_links', []); return self::$result; }
        finally { self::$operation = null; self::$result = null; }
    }

    protected function invoke_native_links(array $args): array {
        self::$result = (self::$operation)();
        return ['before' => [], 'after' => [], 'verified' => true];
    }
}

function native_permalink_require(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('native permalink fixture failed: ' . $label);
}

function native_permalink_rows(bool $payload = false): array {
    global $wpdb;
    return NativePermalinkProbe::run(false, static function () use ($wpdb, $payload): array {
        $tables = ['posts' => ['ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title',
            'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged',
            'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type',
            'post_mime_type', 'comment_count'], 'options' => ['option_id', 'option_name', 'option_value', 'autoload']];
        $result = [];
        foreach ($tables as $property => $columns) {
            $result[$property] = ProviderSdk::physical_table_rows(['table' => $wpdb->$property, 'columns' => $columns,
                'identity' => [$columns[0]], 'max_rows' => 8192, 'max_raw_bytes' => 16777216,
                'mode' => $payload ? 'rows' : 'digest'], 'native permalink complete preservation inputs');
        }
        return $result;
    });
}

function native_permalink_read(array $ids): array {
    return NativePermalinkProbe::run(true, static fn(): array => ProviderSdk::checked_native_permalinks($ids, 'native permalink fixture input'));
}

function native_permalink_setup(array $options): void {
    global $wpdb;
    NativePermalinkProbe::run(true, static function () use ($wpdb, $options): void {
        foreach ($options as $name => $value) ProviderSdk::database_update($wpdb->options,
            ['option_value' => $value], ['option_name' => $name], 'native permalink owned fixture option');
    });
    // Explicit fixture setup, outside the reader: each premise starts from
    // cold cache and normally initialized core state. Hostile cases below
    // deliberately change that premise and must be refused without repair.
    wp_cache_flush();
    $GLOBALS['wp_rewrite'] = new WP_Rewrite();
    register_post_type('wprism_probe_book', ['public' => true, 'hierarchical' => true,
        'rewrite' => ['slug' => 'library', 'with_front' => true, 'feeds' => false], 'query_var' => 'wprism_probe_book']);
}

function native_permalink_reject(callable $operation, string $label, array &$refusals): void {
    $before = native_permalink_rows();
    $refused = false;
    try { $operation(); } catch (RuntimeException) { $refused = true; }
    native_permalink_require($refused && native_permalink_rows() === $before, $label . ' complete physical refusal preservation');
    $refusals[] = $label;
}

global $wpdb;
native_permalink_require(wp_get_current_user()->ID === 0 && !defined('WP_HOME'), 'ordinary anonymous unconstrained-home fixture boot');
$initialRows = native_permalink_rows(true);
$initial = $initialRows;
foreach ($initial as &$witness) unset($witness['rows']);
unset($witness);
$ids = $observations = $refusals = [];
$ownedOptions = ['permalink_structure', 'show_on_front', 'page_on_front'];
$savedOptions = [];
foreach ($initialRows['options']['rows'] as $row) {
    if (in_array($row['option_name'], $ownedOptions, true)) $savedOptions[$row['option_name']] = $row;
}
native_permalink_require(count($savedOptions) === count($ownedOptions), 'all setup option rows already exist');
$home = (string) get_option('home');
try {
    $seeds = ['grand' => ['page', 'publish', 'grand', 0], 'parent' => ['page', 'publish', 'parent', 'grand']];
    foreach (['post', 'page'] as $type) {
        foreach (['publish', 'draft', 'pending', 'future', 'private'] as $status) {
            $seeds[$type . '_' . $status] = [$type, $status, $type . '-' . $status, $type === 'page' && $status === 'publish' ? 'parent' : 0];
        }
    }
    $seeds['book_publish'] = ['wprism_probe_book', 'publish', 'book', 'parent'];
    $seeds['book_private'] = ['wprism_probe_book', 'private', 'private-book', 0];
    $seeds['template'] = ['wp_template', 'publish', 'native-template', 0];
    foreach ($seeds as $key => [$type, $status, $slug, $parent]) {
        NativePermalinkProbe::run(true, static function () use ($wpdb, $type, $status, $slug, $parent, $key, &$ids): void {
            ProviderSdk::database_insert($wpdb->posts, ['post_author' => 0, 'post_date' => '2026-09-08 01:02:03',
                'post_date_gmt' => '2026-09-08 01:02:03', 'post_content' => 'native permalink owned fixture', 'post_title' => $slug,
                'post_excerpt' => '', 'post_status' => $status, 'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => '',
                'post_name' => $slug, 'to_ping' => '', 'pinged' => '', 'post_modified' => '2026-09-08 01:02:03',
                'post_modified_gmt' => '2026-09-08 01:02:03', 'post_content_filtered' => '',
                'post_parent' => is_string($parent) ? $ids[$parent] : 0, 'guid' => '', 'menu_order' => 0,
                'post_type' => $type, 'post_mime_type' => '', 'comment_count' => 0], 'native permalink fixture row');
            native_permalink_require(is_int($wpdb->insert_id) && $wpdb->insert_id > 0, 'owned physical ID');
            $ids[$key] = $wpdb->insert_id;
        });
    }
    $ids['absent'] = max($ids) + 1;
    native_permalink_require($wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE ID = %d", $ids['absent'])) === null,
        'absence control is independently missing');
    native_permalink_setup(['permalink_structure' => '/%year%/%postname%/', 'show_on_front' => 'posts', 'page_on_front' => '0']);
    $postIds = $pageIds = [];
    foreach (['publish', 'draft', 'pending', 'future', 'private'] as $status) {
        $postIds[] = $ids['post_' . $status];
        $pageIds[] = $ids['page_' . $status];
    }
    $observations['post_statuses'] = native_permalink_read($postIds);
    $observations['page_statuses'] = native_permalink_read($pageIds);
    $observations['warm_pages'] = native_permalink_read($pageIds);
    $observations['custom_types'] = native_permalink_read([$ids['book_publish'], $ids['book_private']]);
    $observations['builtin_template'] = native_permalink_read([$ids['template']]);
    foreach (['stale_primary' => $ids['post_publish'], 'stale_ancestor' => $ids['parent']] as $label => $id) {
        $row = wp_cache_get($id, 'posts');
        native_permalink_require(is_object($row), 'native raw cache was warmed');
        $row->post_name = 'stale-slug';
        wp_cache_set($id, $row, 'posts');
        native_permalink_reject(static fn() => native_permalink_read([$label === 'stale_primary' ? $ids['post_publish'] : $ids['page_publish']]), $label, $refusals);
        native_permalink_require(wp_cache_get($id, 'posts')->post_name === 'stale-slug', 'reader did not repair stale cache');
        wp_cache_delete($id, 'posts');
    }
    $all = wp_cache_get('alloptions', 'options');
    native_permalink_require(is_array($all), 'native alloptions cache exists');
    $stale = $all;
    $stale['home'] = 'http://stale.invalid';
    wp_cache_set('alloptions', $stale, 'options');
    native_permalink_reject(static fn() => native_permalink_read([$ids['post_publish']]), 'stale_home_cache', $refusals);
    wp_cache_set('alloptions', $all, 'options');
    $GLOBALS['wp_rewrite']->page_structure = '/stale/%pagename%';
    native_permalink_reject(static fn() => native_permalink_read([$ids['page_publish']]), 'stale_page_structure', $refusals);
    unset($GLOBALS['wp_rewrite']->page_structure);
    $foreign = static fn($value) => $value;
    foreach (['home_url' => ['foreign_home_hook', $ids['post_publish']], 'user_has_cap' => ['foreign_capability_hook', $ids['post_private']]] as $hook => [$label, $id]) {
        add_filter($hook, $foreign);
        try { native_permalink_reject(static fn() => native_permalink_read([$id]), $label, $refusals); }
        finally { remove_filter($hook, $foreign); }
    }
    native_permalink_reject(static fn() => NativePermalinkProbe::run(false,
        static fn() => ProviderSdk::checked_native_permalinks([$ids['post_publish']], 'forbidden native observer')), 'observer_callback', $refusals);
    native_permalink_reject(static fn() => NativePermalinkProbe::run(true, static function () use ($wpdb, $ids): void {
        ProviderSdk::database_update($wpdb->posts, ['post_title' => 'must roll back'], ['ID' => $ids['post_publish']], 'caught native refusal setup');
        try { ProviderSdk::checked_native_permalinks([0], 'caught native refusal'); } catch (Throwable) {}
    }), 'swallowed_failure', $refusals);
    native_permalink_setup(['show_on_front' => 'page', 'page_on_front' => (string) $ids['page_publish']]);
    $observations['front_page'] = native_permalink_read([$ids['page_publish']]);
    native_permalink_setup(['permalink_structure' => '', 'show_on_front' => 'posts', 'page_on_front' => '0']);
    $_SERVER['REQUEST_URI'] = '/unrelated?do-not-copy=1';
    $observations['plain'] = native_permalink_read([$ids['post_publish'], $ids['page_publish'], $ids['book_publish']]);
    native_permalink_setup(['permalink_structure' => '/index.php/%postname%/']);
    $observations['index'] = native_permalink_read([$ids['page_publish']]);
    native_permalink_setup(['permalink_structure' => '/%postname%']);
    $observations['no_slash'] = native_permalink_read([$ids['page_publish']]);
    $observations['absent'] = native_permalink_read([$ids['absent']]);
    native_permalink_setup(['permalink_structure' => '/%year%/%postname%/']);
    define('WP_HOME', 'http://constant.example.test/base');
    $observations['constant_home'] = native_permalink_read([$ids['post_publish']]);
    native_permalink_require($observations === native_permalink_vectors($ids, $home), 'all concrete native URL vectors');
} finally {
    foreach ($ids as $name => $id) {
        if ($name === 'absent') continue;
        NativePermalinkProbe::run(true, static fn() => ProviderSdk::database_delete($wpdb->posts, ['ID' => $id], 'native permalink owned row cleanup'));
    }
    NativePermalinkProbe::run(true, static function () use ($wpdb, $savedOptions): void {
        foreach ($savedOptions as $row) ProviderSdk::database_update($wpdb->options,
            ['option_value' => $row['option_value'], 'autoload' => $row['autoload']], ['option_id' => (int) $row['option_id']], 'native permalink exact option restoration');
    });
}
$final = native_permalink_rows();
native_permalink_require($final === $initial, 'all original physical post and option rows restored');
echo json_encode(['format' => 'wprism-native-permalinks/v1', 'engine' => WPrism\PlatformCompatibility::current_facts()['database']['engine'],
    'ids' => $ids, 'initial' => $initial, 'final' => $final, 'observations' => $observations, 'refusals' => $refusals],
    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
