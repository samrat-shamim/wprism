<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
require_once "$root/agent/src/Capture/OptionsCapture.php";
require_once "$root/agent/src/Capture/CaptureSafetyGates.php";
require_once "$root/agent/src/Apply/ApplyFieldMaterializer.php";
require_once "$root/agent/src/Apply/OptionsMaterializer.php";
require_once "$root/agent/src/Repository/RepositoryCompiler.php";
require_once "$root/agent/src/Kernel/SerializedDataPreflight.php";
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
use WPrism\RepositoryCompiler;
use WPrism\SerializedDataPreflight;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$capsule = dirname(__DIR__, 2);
$manifest = Canon::decode(Canon::read_file($capsule . '/package/manifest.json'));
$nativeRows = Canon::decode(Canon::read_file($capsule . '/fixtures/native-options.json'));
$identityManifest = ['name' => 'qi-option-identities', 'spec_version' => 3, 'option_autoload' => 'preserve',
    'post_types' => ['page' => ['class' => 'authored']]];
$site = FrozenPolicy::site([$identityManifest, $manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['page'];
$site['policy']['taxonomies'] = [];
$policy = FrozenPolicy::policy([$identityManifest, $manifest], $site);
$uuid = '11111111-1111-4111-8111-000000000013';
$scratch = sys_get_temp_dir() . '/wprism-qi-options-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/posts/page', 0700, true);
mkdir($scratch . '/state/options', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
$front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-08 00:00:00',
    'date_gmt' => '2026-09-08 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
    'modified' => '2026-09-08 00:00:00', 'modified_gmt' => '2026-09-08 00:00:00', 'parent' => null,
    'ping_status' => 'closed', 'slug' => 'styled-page', 'status' => 'publish', 'terms' => (object) [],
    'title' => 'Styled page', 'type' => 'page', 'uuid' => $uuid];
Canon::write_file($scratch . '/state/posts/page/' . $uuid . '--styled-page.md', Canon::post_file($front, 'Styled page'));
$database = static function (array $rows, int $offset) use ($uuid): FakeWpdb {
    foreach ($rows as $i => &$row) $row['option_id'] = $i + 1;
    unset($row);
    return FakeWpdb::install()->enableInformationSchema()->seedTable('wp_options', $rows)
        ->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
        ->setAutoIncrement('wp_options', count($rows) + 1, 'option_id')->setUniqueKey('wp_options', ['option_name'])
        ->setIndexes('wp_options', [['Key_name' => 'option_name', 'Column_name' => 'option_name', 'Seq_in_index' => 1,
            'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE']])->setTableEngine('wp_options', 'InnoDB')
        ->seedTable('wp_wprism_map', [['uuid' => $uuid, 'entity_type' => 'post:page', 'id_kind' => 'post', 'local_id' => 13 + $offset]])
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
        }, static function (): never { throw new LogicException('Qi style keys use structured references'); },
        static function (): never { throw new LogicException('Qi fixture declares no scalar option reference'); });
    $result = $reader->capture(false);
    $gates->assertOptions($result['unclassified'], $result['unscoped_refs'], $result['unscoped_option_name_refs'], $tokens);
    return $result['document'];
};
$sourceDb = $database($nativeRows, 0);
$sourceBefore = $sourceDb->rows('wp_options');
$document = $capture('http://localhost:9164');
$values = OptionState::values($document);
wprism_check_same($sourceBefore, $sourceDb->rows('wp_options'), 'actual native Qi option capture never changes source rows');
wprism_check_same(['{{post:' . $uuid . '}}'], array_keys($values['qi_blocks_global_styles']['root']['items']['posts']['items']),
    'native Qi style ownership uses the durable page identity');
wprism_check(!isset($values['qi_blocks_cropped_images']) && !isset($values['qi_blocks_setup_wizard']),
    'crop cache and completed onboarding remain target-local runtime state');
Canon::write_file($scratch . '/state/options/core.json', Canon::encode($document));
$beforeCompile = $sourceDb->queries();
$compiled = RepositoryCompiler::compile($scratch, $policy);
wprism_check_same($beforeCompile, $sourceDb->queries(), 'immutable Qi option compilation makes no database queries');
$runtime = [['option_name' => 'qi_blocks_cropped_images', 'option_value' => 'a:1:{s:5:"local";s:4:"kept";}', 'autoload' => 'off'],
    ['option_name' => 'qi_blocks_setup_wizard', 'option_value' => 'target-local-step', 'autoload' => 'auto-off']];
$targetDb = $database($runtime, 800);
$targetHome = 'https://target.example.test/longer-prefix';
$targetTokens = new Tokens($targetHome, $targetHome . '/wp-content/uploads');
$fields = new ApplyFieldMaterializer($policy, $targetTokens);
$materializer = new OptionsMaterializer($policy, $targetTokens, $fields);
$write = static function () use ($fields, $materializer, $compiled): void {
    Db::start_repeatable_read('Qi option transaction', new NativeDatabaseProfile(['wp_options', 'wp_wprism_map'], ['wp_options']));
    $fields->begin_authored_transaction();
    $materializer->begin_authored_transaction();
    CacheInvalidationTransaction::begin();
    try {
        $warnings = [];
        $materializer->apply_options($compiled->tree()['options/core']['data'], false, $warnings);
        Db::commit('Qi option commit');
        $materializer->commit_authored_transaction();
        CacheInvalidationTransaction::finish();
        wprism_check_same([], $warnings, 'checked Qi option apply emits no warning');
    } catch (Throwable $failure) {
        Db::rollback('Qi option rollback');
        throw $failure;
    } finally {
        $materializer->end_authored_transaction();
        $fields->end_authored_transaction();
        CacheInvalidationTransaction::end();
    }
};
$beforeFailure = $targetDb->rows('wp_options');
$earlierWrite = false;
$targetDb->onQuery(static function (string $sql, string $_method, FakeWpdb $db) use (&$earlierWrite): ?string {
    if (str_starts_with($sql, 'INSERT INTO') && str_contains($sql, 'qi_blocks_global_styles')) {
        $earlierWrite = in_array('qi_blocks_disabled_blocks', array_column($db->rows('wp_options'), 'option_name'), true);
        return 'injected late Qi style write failure';
    }
    return null;
});
wprism_check_throws($write, RuntimeException::class, 'checked SQL refuses a failed native Qi style insert');
wprism_check($earlierWrite, 'the failure follows an earlier successful authored Qi option write');
wprism_check_same($beforeFailure, $targetDb->rows('wp_options'), 'late Qi style failure rolls back every earlier authored write');
$targetDb->onQuery(null);
$write();
$actual = array_column($targetDb->rows('wp_options'), 'option_value', 'option_name');
$original = array_column($nativeRows, 'option_value', 'option_name');
$expectedStyles = SerializedDataPreflight::decode($original['qi_blocks_global_styles'], 'native Qi fixture', true);
wprism_check_same([13], array_keys($expectedStyles['posts']), 'native fixture style ownership is exactly page 13');
$expectedStyles['posts'] = [813 => $expectedStyles['posts'][13]];
$nativeSelectors = 0;
foreach ($expectedStyles['posts'][813] as $blockStyle) {
    foreach ($blockStyle->values as $value) {
        // The native frontend emits the saved selector verbatim. Rebinding
        // only the posts map key would select the correct record but render
        // CSS that still targets the source page's body class.
        $value->selector = str_replace('body[class*="-13"]', 'body[class*="-813"]', $value->selector, $replacements);
        $nativeSelectors += $replacements;
    }
}
wprism_check($nativeSelectors > 48, 'native Save stores page identity inside every block style selector as well as its map key');
$replaceUrls = static function (&$value) use (&$replaceUrls, $targetHome): void {
    if (is_string($value)) $value = str_replace('http://localhost:9164', $targetHome, $value);
    elseif (is_array($value) || $value instanceof stdClass) foreach ($value as &$child) $replaceUrls($child);
};
$replaceUrls($expectedStyles);
wprism_check_same(hash('sha256', serialize($expectedStyles)), hash('sha256', $actual['qi_blocks_global_styles']),
    'checked apply preserves native types and order while rebinding page keys, embedded selector identities and CSS URLs');
foreach ($runtime as $row) wprism_check_same($row['option_value'], $actual[$row['option_name']], 'target runtime option remains byte-identical');
$beforeRepeat = $targetDb->rows('wp_options');
$write();
wprism_check_same($beforeRepeat, $targetDb->rows('wp_options'), 'identical Qi option apply preserves exact rows and autoload values');
wprism_check_same(Canon::encode($document), Canon::encode($capture($targetHome)), 'full native Qi option recapture is canonically identical');
if (wprism_check_failed() > 0) exit(1);
