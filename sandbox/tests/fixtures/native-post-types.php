<?php
declare(strict_types=1);

use WPrism\ManifestProviderRuntime;
use WPrism\ProviderSdk;
use WPrism\Providers;

function native_post_expected(string $engine): array {
    return ['format' => 'wprism-native-post-types/v1', 'engine' => $engine,
        'cold_warm_and_absent' => true, 'exact_stdclass_and_wp_post' => true,
        'bounded_unconsumed_cache_fields' => true,
        'refusals' => ['zero_id', 'duplicate_id', 'stale_type', 'cached_ghost', 'unsafe_class',
            'cached_object_field', 'cached_oversized_field', 'cached_extra_field', 'cold_oversized_field', 'observer_callback'],
        'object_clones' => 0, 'caught_failure_rollback' => true, 'complete_post_rows_restored' => true];
}

if (($argv[1] ?? '') === '--admit') {
    require_once __DIR__ . '/../lib/PrivateCommandOutput.php';
    $engine = $argv[3] ?? '';
    $record = json_decode(WPrismTest\PrivateCommandOutput::readObject($argv[2] ?? '',
        '/^ ?Container wprism-[a-z0-9]+-cli1-run-[a-z0-9]+ (Creating|Created) *$/D'), true, 32, JSON_THROW_ON_ERROR);
    if (!in_array($engine, ['MariaDB', 'MySQL'], true) || $record !== native_post_expected($engine)) {
        throw new RuntimeException('native post-type record does not prove the complete declared contract');
    }
    exit(0);
}

if (!defined('ABSPATH') || !class_exists(ProviderSdk::class)) {
    throw new RuntimeException('native post-type fixture requires loaded WordPress and the product SDK');
}

/** Fixture-only private loader join; the actual SDK owns all database work. */
final class NativePostTypeProbe extends ManifestProviderRuntime {
    private static ?Closure $operation = null;
    private static mixed $result = null;

    public static function run(bool $write, callable $operation): mixed {
        $runtime = new self(['source' => 'manifest', 'id' => 'native-post-type-probe', 'plugin' => 'fixture/fixture.php',
            'version' => '1.0.0', 'capabilities' => ['native_posts'], 'contracts' => ['native_posts' => [
                'args' => [], 'idempotent' => true, 'scope' => 'site', 'timeout_seconds' => 60,
                'reads' => $write ? [] : ['table:posts'], 'writes' => $write ? ['table:posts'] : [],
            ]]]);
        (new ReflectionMethod(Providers::class, 'bind_manifest_runtime_contracts'))->invoke(null, $runtime, $runtime->capabilities());
        self::$operation = $write
            ? static fn(): mixed => ProviderSdk::database_write_contract_transaction('native post fixture mutation', $operation,
                static fn() => ProviderSdk::DATABASE_POSTIMAGE_UNKNOWN)
            : static fn(): mixed => ProviderSdk::database_read_contract_snapshot('native post fixture observation', $operation);
        try { $runtime->invoke('native_posts', []); return self::$result; }
        finally { self::$operation = null; self::$result = null; }
    }

    protected function invoke_native_posts(array $args): array {
        self::$result = (self::$operation)();
        return ['before' => [], 'after' => [], 'verified' => true];
    }
}

final class NativePostTypeObjectProbe {
    public static int $clones = 0;
    public function __clone(): void { self::$clones++; }
}

function native_post_require(bool $condition, string $label): void {
    if (!$condition) throw new RuntimeException('native post-type proof failed: ' . $label);
}

function native_post_reject(callable $operation): void {
    $caught = null;
    try { $operation(); } catch (Throwable $failure) { $caught = $failure; }
    native_post_require($caught instanceof RuntimeException, 'expected typed refusal');
}

function native_post_observe(array $ids): array {
    return NativePostTypeProbe::run(true, static fn(): array => ProviderSdk::checked_native_post_types($ids, 'native post fixture input'));
}

function native_post_rows(): array {
    global $wpdb;
    return NativePostTypeProbe::run(false, static fn(): array => ProviderSdk::physical_table_rows([
        'table' => $wpdb->posts, 'columns' => ['ID', 'post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title',
            'post_excerpt', 'post_status', 'comment_status', 'ping_status', 'post_password', 'post_name', 'to_ping', 'pinged',
            'post_modified', 'post_modified_gmt', 'post_content_filtered', 'post_parent', 'guid', 'menu_order', 'post_type',
            'post_mime_type', 'comment_count'], 'identity' => ['ID'], 'max_rows' => 128, 'max_raw_bytes' => 8388608, 'mode' => 'digest',
    ], 'native post complete physical table'));
}

global $wpdb;
$initial = native_post_rows();
$ids = [];
try {
    foreach (['fixture_form', 'page'] as $type) {
        NativePostTypeProbe::run(true, static function () use ($wpdb, $type, &$ids): void {
            ProviderSdk::database_insert($wpdb->posts, [
                'post_author' => 0, 'post_date' => '2026-09-08 00:00:00', 'post_date_gmt' => '2026-09-08 00:00:00',
                'post_content' => 'owned native input', 'post_title' => '東京', 'post_excerpt' => '', 'post_status' => 'publish',
                'comment_status' => 'closed', 'ping_status' => 'closed', 'post_password' => '', 'post_name' => 'wprism-native-post-type-fixture',
                'to_ping' => '', 'pinged' => '', 'post_modified' => '2026-09-08 00:00:00', 'post_modified_gmt' => '2026-09-08 00:00:00',
                'post_content_filtered' => '', 'post_parent' => 0, 'guid' => '', 'menu_order' => 0, 'post_type' => $type,
                'post_mime_type' => '', 'comment_count' => 0,
            ], 'native post fixture creation');
            native_post_require(is_int($wpdb->insert_id) && $wpdb->insert_id > 0, 'positive native inserted ID');
            $ids[] = $wpdb->insert_id;
        });
    }
    [$id, $page] = $ids;
    $missing = max($ids) + 1;
    native_post_require($wpdb->get_var($wpdb->prepare("SELECT ID FROM $wpdb->posts WHERE ID = %d", $missing)) === null,
        'absence control is independently empty');
    $before = native_post_rows();
    foreach ($ids as $selected) wp_cache_delete($selected, 'posts'); // Test-only cold-cache setup, not product repair.
    native_post_require(native_post_observe([$page, $missing, $id]) === ['page', false, 'fixture_form'], 'cold core getter and absence');
    native_post_require(native_post_observe([$page, $missing, $id]) === ['page', false, 'fixture_form'], 'warm core getter and absence');
    $raw = wp_cache_get($id, 'posts');
    native_post_require(is_object($raw) && get_class($raw) === 'stdClass' && $raw->filter === 'raw', 'core cached raw stdClass');
    wp_cache_set($id, new WP_Post($raw), 'posts');
    native_post_require(native_post_observe([$id]) === ['fixture_form'], 'exact final WP_Post cache');
    $staleTitle = clone $raw;
    $staleTitle->post_title = 'unconsumed bounded field';
    wp_cache_set($id, $staleTitle, 'posts');
    native_post_require(native_post_observe([$id]) === ['fixture_form'], 'type-only contract does not claim unconsumed field equality');
    native_post_reject(static fn() => native_post_observe([0]));
    native_post_reject(static fn() => native_post_observe([$id, $id]));
    foreach ([['post_type' => 'post'], ['post_content' => new NativePostTypeObjectProbe()],
        ['post_content' => str_repeat('x', 1048577)], ['extension_payload' => 'not admitted']] as $changes) {
        $bad = clone $raw;
        foreach ($changes as $key => $value) $bad->$key = $value;
        wp_cache_set($id, $bad, 'posts');
        native_post_reject(static fn() => native_post_observe([$id]));
        native_post_require(native_post_rows() === $before, 'cache refusal preserves every physical post row');
    }
    // The raw cache compatibility view is test setup only. wp_cache_set would
    // itself clone the hostile object before the product can admit it.
    $cache = $GLOBALS['wp_object_cache']->__get('cache');
    $cache['posts'][$id] = new NativePostTypeObjectProbe();
    $GLOBALS['wp_object_cache']->__set('cache', $cache);
    native_post_reject(static fn() => native_post_observe([$id]));
    native_post_require(NativePostTypeObjectProbe::$clones === 0, 'no hostile clone crossed admission');
    wp_cache_set($missing, (object) ['ID' => $missing, 'post_type' => 'page', 'filter' => 'raw'], 'posts');
    native_post_reject(static fn() => native_post_observe([$missing]));
    wp_cache_delete($missing, 'posts');
    wp_cache_delete($id, 'posts');
    // The oversized fixture is deliberately authored outside the SDK, whose
    // own DML statement bound is smaller than this hostile native row.
    native_post_require($wpdb->update($wpdb->posts, ['post_content' => str_repeat('x', 1048577)], ['ID' => $id]) === 1,
        'cold oversized row exists physically');
    native_post_reject(static fn() => native_post_observe([$id]));
    native_post_require(wp_cache_get($id, 'posts') === false, 'oversized row never reaches native cache population');
    native_post_require($wpdb->update($wpdb->posts, ['post_content' => 'owned native input'], ['ID' => $id]) === 1,
        'cold oversized fixture is restored');
    native_post_reject(static fn() => NativePostTypeProbe::run(false,
        static fn() => ProviderSdk::checked_native_post_types([$id], 'forbidden native observer')));
    native_post_reject(static fn() => NativePostTypeProbe::run(true, static function () use ($wpdb, $id): void {
        ProviderSdk::database_update($wpdb->posts, ['post_title' => 'must roll back'], ['ID' => $id], 'native input rollback control');
        try { ProviderSdk::checked_native_post_types([0], 'swallowed native refusal'); } catch (Throwable) {}
    }));
    native_post_require(native_post_rows() === $before, 'swallowed refusal rolls back complete physical post rows');
} finally {
    foreach ($ids as $id) {
        NativePostTypeProbe::run(true, static fn() => ProviderSdk::database_delete($wpdb->posts, ['ID' => $id], 'native post fixture cleanup'));
        wp_cache_delete($id, 'posts');
    }
}
native_post_require(native_post_rows() === $initial, 'every original post row survives owned fixture cleanup');
$engine = WPrism\PlatformCompatibility::current_facts()['database']['engine'] ?? '';
native_post_require(in_array($engine, ['MariaDB', 'MySQL'], true), 'known native driver');
echo json_encode(native_post_expected($engine), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
