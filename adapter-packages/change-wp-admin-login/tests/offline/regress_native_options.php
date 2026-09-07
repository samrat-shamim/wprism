<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/sandbox/tests/lib/frozen_policy.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/agent/src/Capture/OptionsCapture.php';
require_once $root . '/agent/src/Capture/CaptureSafetyGates.php';
require_once $root . '/agent/src/Apply/ApplyFieldMaterializer.php';
require_once $root . '/agent/src/Apply/OptionsMaterializer.php';
require_once $root . '/agent/src/Repository/RepositoryCompiler.php';
wprism_test_define_agent_versions();

use WPrism\ApplyFieldMaterializer;
use WPrism\CacheInvalidationTransaction;
use WPrism\Canon;
use WPrism\CaptureSafetyGates;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\OptionsCapture;
use WPrism\OptionsMaterializer;
use WPrism\OptionState;
use WPrism\PlainData;
use WPrism\RepositoryCompiler;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$capsule = dirname(__DIR__, 2);
$native = Canon::decode(Canon::read_file($capsule . '/fixtures/native-options.json'));
$manifest = Canon::decode(Canon::read_file($capsule . '/package/manifest.json'));
// Only the fixture's three identity anchors are synthetic. The option contract,
// native serialized rows, and digest-bound interpreter are the actual capsule.
$coreFixture = ['name' => 'aio-native-identities', 'spec_version' => 3,
    'option_autoload' => 'preserve', 'post_types' => ['page' => ['class' => 'authored']]];
$manifests = [$coreFixture, $manifest];
$site = FrozenPolicy::site($manifests, WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['page'];
$site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy($manifests, $site);
$scratch = sys_get_temp_dir() . '/wprism-aio-native-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/posts/page', 0700, true);
mkdir($scratch . '/state/options', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
$identities = [];
foreach ([$native['pages']['login'], $native['pages']['logout'], $native['media']] as $index => $id) {
    $uuid = '11111111-1111-4111-8111-' . str_pad((string) ($index + 1), 12, '0', STR_PAD_LEFT);
    $identities[$id] = $uuid;
    $front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-08 00:00:00',
        'date_gmt' => '2026-09-08 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
        'modified' => '2026-09-08 00:00:00', 'modified_gmt' => '2026-09-08 00:00:00', 'parent' => null,
        'ping_status' => 'closed', 'slug' => 'destination-' . $index, 'status' => 'publish', 'terms' => (object) [],
        'title' => 'Destination', 'type' => 'page', 'uuid' => $uuid];
    Canon::write_file($scratch . '/state/posts/page/' . $uuid . '--destination-' . $index . '.md', Canon::post_file($front, 'Destination'));
}
$database = static function (array $rows, int $offset = 0) use ($identities): FakeWpdb {
    foreach ($rows as $index => &$row) $row['option_id'] = $index + 1;
    unset($row);
    $map = [];
    foreach ($identities as $id => $uuid) $map[] = ['uuid' => $uuid, 'entity_type' => 'post:page', 'id_kind' => 'post', 'local_id' => $id + $offset];
    return FakeWpdb::install()->enableInformationSchema()
        ->seedTable('wp_options', $rows)
        ->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
        ->setAutoIncrement('wp_options', count($rows) + 1, 'option_id')->setUniqueKey('wp_options', ['option_name'])
        ->setIndexes('wp_options', [['Key_name' => 'option_name', 'Column_name' => 'option_name', 'Seq_in_index' => 1,
            'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE']])->setTableEngine('wp_options', 'InnoDB')
        ->seedTable('wp_wprism_map', $map)
        ->setColumns('wp_wprism_map', ['uuid' => 'varchar(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned'])
        ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id'])
        ->setTableEngine('wp_wprism_map', 'InnoDB');
};
$capture = static function (string $home) use ($policy, $scratch): array {
    WpStore::reset()->seedOptions(['home' => $home]);
    $tokens = new Tokens($home, $home . '/wp-content/uploads');
    $gates = new CaptureSafetyGates($scratch);
    $reader = new OptionsCapture($policy, $tokens,
        static function (string $section, string $key, mixed $value, array $rule) use ($gates): void {
            $gates->guardSecret($section, $key, $value, $rule);
            $gates->guardPersonalData($section, $key, $value, $rule);
        }, static fn(): null => null, static fn(): bool => false);
    $result = $reader->capture(false);
    $gates->assertOptions($result['unclassified'], $result['unscoped_refs'], $result['unscoped_option_name_refs'], $tokens);
    return $result['document'];
};
$compile = static function (array $document) use ($scratch, $policy) {
    Canon::write_file($scratch . '/state/options/core.json', Canon::encode($document));
    return RepositoryCompiler::compile($scratch, $policy);
};
$rulesName = 'aio_login_pro_login_redirection_rules';
foreach ($native['native_cases'] as $case => $rules) {
    $rows = $native['raw_authored_options'];
    foreach ($rows as &$row) if ($row['option_name'] === $rulesName) $row['option_value'] = serialize($rules);
    unset($row);
    $rows[] = ['option_name' => 'aio_login_google_recaptcha_v2_secret_key', 'option_value' => 'source-private-captcha-key', 'autoload' => 'off'];
    $rows[] = ['option_name' => 'aio_login_configured_providers_list', 'option_value' => 'source-runtime-snapshot', 'autoload' => 'off'];
    $source = $database($rows);
    $before = $source->rows('wp_options');
    $document = $capture($native['source_home']);
    $values = OptionState::values($document);
    wprism_check_same(74, count($values), "$case captures all 74 native authored rows");
    wprism_check(!isset($values['aio_login_google_recaptcha_v2_secret_key']) && !isset($values['aio_login_configured_providers_list']),
        "$case excludes environment credentials and runtime snapshot");
    wprism_check_same($before, $source->rows('wp_options'), "$case capture preserves native serialized rows");
    $artifact = $compile($document);
    wprism_check_same(Canon::encode($document), Canon::encode($artifact->tree()['options/core']['data']), "$case complete compiler retains captured option contract");
    $target = $database([
        ['option_name' => 'aio_login_google_recaptcha_v2_secret_key', 'option_value' => 'target-private-captcha-key', 'autoload' => 'off'],
        ['option_name' => 'aio_login_configured_providers_list', 'option_value' => 'target-runtime-snapshot', 'autoload' => 'off'],
    ], 800);
    WpStore::reset()->seedOptions(['home' => 'https://aio-target.test']);
    $tokens = new Tokens('https://aio-target.test', 'https://aio-target.test/wp-content/uploads');
    $fields = new ApplyFieldMaterializer($policy, $tokens);
    $writer = new OptionsMaterializer($policy, $tokens, $fields);
    $apply = static function (array $desired) use ($writer, $fields): void {
        Db::start_repeatable_read('AIO native fixture', new NativeDatabaseProfile(['wp_options', 'wp_wprism_map'], ['wp_options']));
        $fields->begin_authored_transaction();
        $writer->begin_authored_transaction();
        CacheInvalidationTransaction::begin();
        try {
            $warnings = [];
            $writer->apply_options($desired, false, $warnings);
            Db::commit('AIO native fixture');
            $writer->commit_authored_transaction();
            CacheInvalidationTransaction::finish();
            wprism_check_same([], $warnings, 'checked native option materialization emits no warnings');
        } catch (Throwable $failure) {
            Db::rollback('AIO native fixture');
            throw $failure;
        } finally {
            $writer->end_authored_transaction();
            $fields->end_authored_transaction();
            CacheInvalidationTransaction::end();
        }
    };
    $apply($document);
    $targetRows = array_column($target->rows('wp_options'), null, 'option_name');
    $actual = PlainData::decode($targetRows[$rulesName]['option_value'], 'target rule');
    foreach (['login', 'logout'] as $event) {
        $expected = $rules[0][$event . '_target_type'] === 'page'
            ? (string) ((int) $rules[0][$event . '_target_value'] + 800)
            : str_replace($native['source_home'], 'https://aio-target.test', $rules[0][$event . '_target_value']);
        wprism_check_same($expected, $actual[0][$event . '_target_value'], "$case $event target uses divergent identities or rebound URL");
    }
    foreach (['aio_login_logo', 'aio_login_background_image', 'aio_login_background_image_mobile', 'aio_login_favicon', 'aio_login_forgot_background_image'] as $key) {
        wprism_check_same((string) ($native['media'] + 800), $targetRows[$key]['option_value'], "$case $key rebinds the native media reference");
    }
    wprism_check_same('target-private-captcha-key', $targetRows['aio_login_google_recaptcha_v2_secret_key']['option_value'], "$case preserves target secret");
    wprism_check_same('target-runtime-snapshot', $targetRows['aio_login_configured_providers_list']['option_value'], "$case preserves target runtime");
    $stable = $target->rows('wp_options');
    $apply($document);
    wprism_check_same($stable, $target->rows('wp_options'), "$case repeat apply is byte-stable");
    wprism_check_same($document, $capture('https://aio-target.test'), "$case full native option recapture is byte-identical");
    foreach (['user', 'user_role', 'priority', 'duplicate', 'unknown-field', 'unknown-target', 'missing-target', 'identity-shape'] as $fault) {
        $bad = $values[$rulesName];
        if (in_array($fault, ['user', 'user_role'], true)) { $bad[0]['condition_type'] = $fault; $bad[0]['condition_value'] = '1'; }
        if ($fault === 'priority') $bad[0]['order'] = 1;
        if ($fault === 'duplicate') $bad[] = $bad[0];
        if ($fault === 'unknown-field') $bad[0]['unknown'] = '1';
        if ($fault === 'unknown-target') $bad[0]['login_target_type'] = 'future';
        if ($fault === 'missing-target') unset($bad[0]['login_target_value']);
        if ($fault === 'identity-shape') $bad[0]['id'] = [];
        $badDocument = $document;
        $badDocument['records'][$rulesName]['value'] = $bad;
        wprism_check_throws(static fn() => $compile($badDocument), RuntimeException::class, "$case compiler refuses $fault");
        wprism_check_same($stable, $target->rows('wp_options'), "$case $fault refusal cannot mutate target rows");
    }
}
wprism_check_summary('AIO native options');
