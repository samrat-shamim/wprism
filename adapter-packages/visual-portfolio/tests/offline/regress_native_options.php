<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
require_once "$root/agent/src/Capture/OptionsCapture.php";
require_once "$root/agent/src/Capture/EntityMetaCapture.php";
require_once "$root/agent/src/Capture/CaptureSafetyGates.php";
require_once "$root/agent/src/Repository/RepositoryCompiler.php";
require_once "$root/agent/src/Policy/ScopeContract.php";
require_once "$root/agent/src/Scope/ScopedStateOverlay.php";
wprism_test_define_agent_versions();

use WPrism\Canon;
use WPrism\CaptureSafetyGates;
use WPrism\CommandRefusalException;
use WPrism\EntityMetaCapture;
use WPrism\OptionsCapture;
use WPrism\OptionState;
use WPrism\RepositoryAuthorizationException;
use WPrism\RepositoryCompiler;
use WPrism\ScopeContract;
use WPrism\ScopedStateOverlay;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$capsule = dirname(__DIR__, 2);
$manifest = Canon::decode(Canon::read_file($capsule . '/package/manifest.json'));
$native = Canon::decode(Canon::read_file($capsule . '/fixtures/native/options.json'));
$core = Canon::decode(Canon::read_file("$root/platform/adapter-library/core/manifest.json"));
$site = FrozenPolicy::site([$core, $manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['page', 'portfolio'];
$site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy([$core, $manifest], $site);
$uuid = static fn(int $id): string => '11111111-1111-4111-8111-' . sprintf('%012d', $id);
$scratch = sys_get_temp_dir() . '/wprism-vp-options-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/posts/page', 0700, true);
mkdir($scratch . '/state/options', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
foreach ([1, 3, 10] as $id) {
    $front = ['uuid' => $uuid($id), 'type' => 'page', 'slug' => 'fixture-' . $id, 'title' => 'Fixture ' . $id,
        'author' => 'user:admin', 'parent' => null, 'menu_order' => 0, 'status' => 'publish', 'comment_status' => 'closed',
        'ping_status' => 'closed', 'date' => '2026-09-09 00:00:00', 'date_gmt' => '2026-09-09 00:00:00',
        'modified' => '2026-09-09 00:00:00', 'modified_gmt' => '2026-09-09 00:00:00', 'excerpt' => '', 'meta' => (object) [], 'terms' => (object) []];
    Canon::write_file($scratch . '/state/posts/page/' . $uuid($id) . '--fixture-' . $id . '.md', Canon::post_file($front, 'Fixture'));
}
$database = static function (array $rows) use ($uuid): FakeWpdb {
    foreach ($rows as $i => &$row) $row['option_id'] = $i + 1;
    unset($row);
    return FakeWpdb::install()->seedTable('wp_options', $rows)->seedTable('wp_wprism_map', array_map(
        static fn(int $id): array => ['uuid' => $uuid($id), 'id_kind' => 'post', 'entity_type' => 'post:page', 'local_id' => $id], [1, 3, 10]));
};
$home = 'http://localhost:9186';
WpStore::reset()->seedOptions(['home' => $home]);
$tokens = new Tokens($home, $home . '/wp-content/uploads');
$gates = new CaptureSafetyGates($scratch);
$guard = static function (string $section, string $key, mixed $value, array $rule) use ($gates): void {
    $gates->guardSecret($section, $key, $value, $rule);
    $gates->guardPersonalData($section, $key, $value, $rule);
};
$capture = static function () use ($policy, $tokens, $guard, $gates): array {
    $reader = new OptionsCapture($policy, $tokens, $guard,
        static function (): never { throw new LogicException('fixture references must already be mapped'); },
        static function (): never { throw new LogicException('fixture contains no custom table reference'); });
    $result = $reader->capture(false);
    $gates->assertOptions($result['unclassified'], $result['unscoped_refs'], $result['unscoped_option_name_refs'], $tokens);
    return $result['document'];
};
$db = $database($native);
$before = $db->rows('wp_options');
$document = $capture();
$values = OptionState::values($document);
wprism_check_same(['portfolio_permalinks', 'vp_general', 'vp_images', 'vp_popup_gallery'], array_keys($values),
    'complete observed namespace projects only the four authored settings maps');
wprism_check_same('{{post:' . $uuid(10) . '}}', $values['vp_general']['portfolio_archive_page'], 'archive selection is a durable post reference');
wprism_check_same('{{post:' . $uuid(3) . '}}', $values['vp_general']['no_image'], 'placeholder uses the same durable attachment identity kind');
wprism_check_same('on', $values['vp_general']['register_portfolio_post_type'], 'native CPT setting retains its checkbox spelling');
wprism_check_same('3', $values['vp_general']['archive_page_items_per_page'], 'native settings number remains a string');
wprism_check_same("{{uploads}}/2026/09/garden.png\r\nnative-gallery", $values['vp_images']['lazy_loading_excludes'], 'textarea capture retains CRLF and rebases its URL');
foreach (['thumbs_position', 'popup_quick_view_internal_links_target', 'popup_quick_view_external_links_target'] as $key) {
    wprism_check(!array_key_exists($key, $values['vp_popup_gallery']), 'hidden premium field remains target-local: ' . $key);
}
foreach (array_keys($values) as $name) {
    $record = OptionState::records($document)[$name];
    wprism_check_same('auto', $record['autoload'], "$name retains the exact native autoload spelling");
}
wprism_check_same($before, $db->rows('wp_options'), 'capture preserves all physical native option rows');
wprism_check_same($document, $capture(), 'unchanged native settings recapture exactly');
Canon::write_file($scratch . '/state/options/core.json', Canon::encode($document));
$queries = $db->queries();
$compiled = RepositoryCompiler::compile($scratch, $policy);
wprism_check_same($queries, $db->queries(), 'settings compilation performs no database lookup');
$resolution = ScopeContract::resolve($compiled, $policy, ['option:vp_general']);
$selected = ScopedStateOverlay::selected_identities($resolution);
wprism_check(in_array($uuid(10), $selected, true) && in_array($uuid(3), $selected, true), 'option scope closes over its archive and placeholder references');
wprism_check(!in_array($uuid(1), $selected, true), 'old target archive is not an outbound authored dependency');
$unclassified = [];
$meta = new EntityMetaCapture($policy, $tokens, $guard, static function (): void {},
    static function (string $key) use (&$unclassified): void { $unclassified[] = $key; });
foreach (['_vp_post_type_mapped' => 'portfolio', '_vp_words_count' => '795', '_vp_views_count' => '42'] as $key => $raw) {
    wprism_check_same([false, null], $meta->classifyValue($key, [$raw], [$key => $raw], 'page', 'post_meta'), "$key never becomes independently authored state");
}
wprism_check_same([true, '{{uploads}}/native.mp4'], $meta->classifyValue('_vp_format_video_url', [$home . '/wp-content/uploads/native.mp4'], [], 'page', 'post_meta'), 'video meta transports a source URL');
wprism_check_same([true, ['x' => 0.25, 'y' => 0.7]], $meta->classifyValue('_vp_image_focal_point', [serialize(['x' => 0.25, 'y' => 0.7])], [], 'page', 'post_meta'), 'focal point metadata retains its exact numeric map');
wprism_check_same([], $unclassified, 'all exercised metadata has an exact declared owner');

$change = static function (array $rows, string $name, callable $edit): array {
    foreach ($rows as &$row) if ($row['option_name'] === $name) {
        $value = unserialize($row['option_value'], ['allowed_classes' => false]);
        $edit($value);
        $row['option_value'] = serialize($value);
    }
    unset($row);
    return $rows;
};
foreach (['vp_general', 'vp_images', 'vp_popup_gallery', 'portfolio_permalinks'] as $name) {
    $bad = $change($native, $name, static function (&$value): void { $value['unknown_authored_setting'] = 'nonempty'; });
    $badDb = $database($bad);
    $badBefore = $badDb->rows('wp_options');
    wprism_check_throws($capture, RuntimeException::class, "$name refuses a populated unknown sibling", 'undeclared sibling');
    wprism_check_same($badBefore, $badDb->rows('wp_options'), 'closed-key refusal preserves every source row');
}
foreach (['api_key=MixedCredential-2026-Value' => 'secret_state_refused', 'private@example.test' => 'personal_data_refused'] as $private => $reason) {
    $bad = $change($native, 'vp_images', static function (&$value) use ($private): void { $value['lazy_loading_excludes'] = $private; });
    $badDb = $database($bad);
    $badBefore = $badDb->rows('wp_options');
    $failure = null;
    try { $capture(); } catch (CommandRefusalException $e) { $failure = $e; }
    wprism_check_same($reason, $failure?->reasonCode, 'native authored options keep the capture privacy guards');
    wprism_check_same($badBefore, $badDb->rows('wp_options'), 'private-value refusal preserves native rows');
    $records = OptionState::records($document);
    $records['vp_images']['value']['lazy_loading_excludes'] = $private;
    $bytes = Canon::encode(OptionState::document($records));
    Canon::write_file($scratch . '/state/options/core.json', $bytes);
    $codes = [];
    try { RepositoryCompiler::compile($scratch, $policy); }
    catch (RepositoryAuthorizationException $e) { $codes = array_column($e->diagnostics, 'code'); }
    wprism_check_same([$reason === 'secret_state_refused' ? 'repository_secret_not_allowed' : 'repository_pii_not_allowed'], $codes, 'compiler independently enforces option privacy');
    wprism_check_same($bytes, file_get_contents($scratch . '/state/options/core.json'), 'compiler refusal preserves its complete input');
}
$unknown = $native;
$unknown[] = ['option_name' => 'vp_unreviewed_native_writer', 'option_value' => '1', 'autoload' => 'off'];
$database($unknown);
$failure = null;
try { $capture(); } catch (CommandRefusalException $e) { $failure = $e; }
wprism_check_same('incomplete_state_discovery', $failure?->reasonCode, 'unknown plugin namespace rows cannot silently disappear');
if (wprism_check_failed() > 0) exit(1);
