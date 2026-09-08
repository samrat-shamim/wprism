<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
require_once "$root/sandbox/tests/support/wp-block-parser-stub.php";
require_once "$root/sandbox/tests/support/wp-shortcode-stub.php";
require_once "$root/agent/src/Grammar/Blocks.php";
require_once "$root/agent/src/Capture/OptionsCapture.php";
require_once "$root/agent/src/Capture/CaptureSafetyGates.php";
require_once "$root/agent/src/Apply/ApplyFieldMaterializer.php";
require_once "$root/agent/src/Apply/OptionsMaterializer.php";
require_once "$root/agent/src/Repository/RepositoryCompiler.php";
require_once "$root/agent/src/Repository/SidebarState.php";
wprism_test_define_agent_versions();

use WPrism\ApplyFieldMaterializer;
use WPrism\Blocks;
use WPrism\CacheInvalidationTransaction;
use WPrism\Canon;
use WPrism\CaptureSafetyGates;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\OptionsCapture;
use WPrism\OptionsMaterializer;
use WPrism\OptionState;
use WPrism\PhpContainerValue;
use WPrism\RepositoryCompiler;
use WPrism\SidebarState;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$capsule = dirname(__DIR__, 2);
$fixture = Canon::decode(Canon::read_file($capsule . '/fixtures/native-contexts/observations.json'));
wprism_check_same('787c5d08549e1d8b74548fa29ef61cec3e0f7db82fb9c4ea7f28a343fd70a756',
    hash_file('sha256', $capsule . '/fixtures/native-contexts/observations.json'), 'historical native context observations retain their reviewed bytes');
$core = Canon::decode(Canon::read_file("$root/platform/adapter-library/core/manifest.json"));
$manifest = Canon::decode(Canon::read_file($capsule . '/package/manifest.json'));
$site = FrozenPolicy::site([$core, $manifest], WPRISM_SPEC_VERSION);
$site['policy']['post_types'] = ['page', 'wp_template'];
$site['policy']['taxonomies'] = [];
$site['policy']['scope']['post_type'] = ['page' => ['class' => 'authored'], 'wp_template' => ['class' => 'authored']];
$policy = FrozenPolicy::policy([$core, $manifest], $site);
$uuid = static fn(int $id): string => '11111111-1111-4111-8111-' . sprintf('%012d', $id);
$scratch = sys_get_temp_dir() . '/wprism-qi-contexts-' . bin2hex(random_bytes(8));
foreach (['options', 'sidebars', 'posts/page', 'posts/wp_template'] as $directory) mkdir("$scratch/state/$directory", 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
$database = static function (array $rows, bool $source) use ($uuid): FakeWpdb {
    foreach ($rows as $index => &$row) $row['option_id'] = $index + 1;
    unset($row);
    $map = [['uuid' => $uuid(13), 'entity_type' => 'post:page', 'id_kind' => 'post', 'local_id' => $source ? 13 : 813]];
    if ($source) foreach (range(2, 7) as $id) {
        $map[] = ['uuid' => $uuid(100 + $id), 'entity_type' => 'widget', 'id_kind' => 'widget_block', 'local_id' => $id];
    }
    return FakeWpdb::install()->enableInformationSchema()->seedTable('wp_options', $rows)
        ->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)', 'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
        ->setAutoIncrement('wp_options', count($rows) + 1, 'option_id')->setUniqueKey('wp_options', ['option_name'])
        ->setIndexes('wp_options', [['Key_name' => 'option_name', 'Column_name' => 'option_name', 'Seq_in_index' => 1,
            'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE']])->setTableEngine('wp_options', 'InnoDB')
        ->seedTable('wp_wprism_map', $map)
        ->setColumns('wp_wprism_map', ['uuid' => 'varchar(36)', 'entity_type' => 'varchar(64)', 'id_kind' => 'varchar(64)', 'local_id' => 'bigint unsigned'])
        ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id'])
        ->setTableEngine('wp_wprism_map', 'InnoDB');
};
$row = static fn(string $name, string $value): array => ['option_name' => $name, 'option_value' => $value, 'autoload' => 'auto-off'];
$capture = static function (string $home) use ($policy, $scratch): array {
    WpStore::reset()->seedOptions(['home' => $home]);
    $tokens = new Tokens($home, $home . '/wp-content/uploads');
    $gates = new CaptureSafetyGates($scratch);
    $reader = new OptionsCapture($policy, $tokens,
        static function (string $section, string $key, mixed $value, array $rule) use ($gates): void {
            $gates->guardSecret($section, $key, $value, $rule);
            $gates->guardPersonalData($section, $key, $value, $rule);
        }, static function (): never { throw new LogicException('context fixture has no option-name references'); },
        static function (): never { throw new LogicException('context fixture has no scalar option references'); });
    $options = $reader->capture(false);
    $gates->assertOptions($options['unclassified'], $options['unscoped_refs'], $options['unscoped_option_name_refs'], $tokens);
    $sidebars = SidebarState::capture($policy, $tokens, false, false, true);
    wprism_check_same([], $sidebars['warnings'], 'native active widgets capture without local exclusions');
    wprism_check_same([], $tokens->warnings, 'native context capture has no unresolved references');
    return ['options' => $options['document'], 'sidebars' => $sidebars['entities']];
};
$front = ['author' => 'user:admin', 'comment_status' => 'closed', 'date' => '2026-09-08 00:00:00',
    'date_gmt' => '2026-09-08 00:00:00', 'excerpt' => '', 'menu_order' => 0, 'meta' => (object) [],
    'modified' => '2026-09-08 00:00:00', 'modified_gmt' => '2026-09-08 00:00:00', 'parent' => null,
    'ping_status' => 'closed', 'slug' => 'context-page', 'status' => 'publish', 'terms' => (object) [],
    'title' => 'Context page', 'type' => 'page', 'uuid' => $uuid(13)];
Canon::write_file($scratch . '/state/posts/page/' . $uuid(13) . '--context-page.md', Canon::post_file($front, 'Context page'));
$targetHome = 'https://context-target.example.test/longer-prefix';

foreach ($fixture['styles'] as $context => $nativeStyles) {
    $sourceDb = $database([
        $row('widget_block', serialize($fixture['widgets'])), $row('sidebars_widgets', serialize($fixture['sidebars'])),
        $row('qi_blocks_global_styles', $nativeStyles),
    ], true);
    $before = $sourceDb->rows('wp_options');
    $captured = $capture($fixture['source_home']);
    wprism_check_same($before, $sourceDb->rows('wp_options'), "$context capture preserves every native option byte");
    wprism_check_same(6, count(Canon::decode($captured['sidebars'][0]['content'])['widgets']), 'all native core widgets and the Qi block share one complete sidebar');
    $styles = PhpContainerValue::restore(OptionState::values($captured['options'])['qi_blocks_global_styles'], 'Qi context fixture');
    wprism_check($styles['widgets'] instanceof stdClass, 'native widget styles retain object storage rather than bypassing the Qi frontend guard');
    wprism_check_same($context === 'widget-page-template', $styles['templates'] instanceof stdClass, 'template root preserves its actual empty-array or populated-object storage');
    wprism_check_same(['posts', 'widgets', 'templates', 'undefined'], array_keys($styles), 'all four native style roots preserve their order');
    Canon::write_file($scratch . '/state/options/core.json', Canon::encode($captured['options']));
    foreach ($captured['sidebars'] as $entity) Canon::write_file($scratch . '/state/' . $entity['path'], $entity['content']);
    // This is the exact native template body inside an explicit content-only
    // compiler fixture. Theme taxonomy, template discovery and native render
    // are separate live obligations; invented term rows would not prove them.
    $template = $front;
    $template['type'] = 'wp_template';
    $template['uuid'] = $uuid(18);
    $template['slug'] = 'wp-custom-template-qi-blocks-full-width';
    $templateBody = Blocks::capture_rewrite($fixture['template_body'], $policy, new Tokens($fixture['source_home']));
    Canon::write_file($scratch . '/state/posts/wp_template/' . $uuid(18) . '--' . $template['slug'] . '.md', Canon::post_file($template, $templateBody));
    $beforeCompile = $sourceDb->queries();
    $compiled = RepositoryCompiler::compile($scratch, $policy);
    wprism_check_same($beforeCompile, $sourceDb->queries(), 'complete context compilation is independent of a database');
    $protectedBlock = ['content' => '<!-- wp:paragraph --><p>Local unassigned block</p><!-- /wp:paragraph -->'];
    $protectedText = serialize([9 => ['title' => 'Local unassigned title', 'text' => 'Local text'], '_multiwidget' => 1]);
    $runtime = serialize(['target-theme' => true]);
    $targetDb = $database([
        $row('widget_block', serialize([50 => $protectedBlock, 99 => ['content' => 'Displaced default'], '_multiwidget' => 1])),
        $row('widget_text', $protectedText),
        $row('sidebars_widgets', serialize(['wp_inactive_widgets' => [], 'sidebar-1' => ['block-99'], 'array_version' => 3])),
        $row('qi_blocks_custom_templates_flag', $runtime),
    ], false);
    WpStore::reset()->seedOptions(['home' => $targetHome]);
    $tokens = new Tokens($targetHome, $targetHome . '/wp-content/uploads');
    $fields = new ApplyFieldMaterializer($policy, $tokens);
    $materializer = new OptionsMaterializer($policy, $tokens, $fields);
    $write = static function () use ($policy, $tokens, $fields, $materializer, $compiled): void {
        Db::start_repeatable_read('Qi context transaction', new NativeDatabaseProfile(['wp_options', 'wp_wprism_map'], ['wp_options', 'wp_wprism_map']));
        $fields->begin_authored_transaction();
        $materializer->begin_authored_transaction();
        CacheInvalidationTransaction::begin();
        SidebarState::begin_authored_transaction(
            static fn(string $name, string $purpose): ?array => CacheInvalidationTransaction::lock_option_row($name, $purpose),
            static function (string $name, string $purpose): void { CacheInvalidationTransaction::queue_option($name, $purpose); },
            static function (string $name, string $value, string $autoload, string $purpose): void {
                CacheInvalidationTransaction::assert_option_row($name, $value, $autoload, $purpose);
            }
        );
        try {
            SidebarState::ensure_widgets($policy, $compiled->tree());
            SidebarState::finalize_sidebar($policy, $tokens, $compiled->tree()['sidebar/sidebar-1']['data'], 'sidebar-1', $compiled->tree(), true);
            $warnings = [];
            $materializer->apply_options($compiled->tree()['options/core']['data'], false, $warnings);
            Db::commit('Qi context commit');
            $materializer->commit_authored_transaction();
            CacheInvalidationTransaction::finish();
            wprism_check_same([], $warnings, 'checked widget and style transaction has no warnings');
        } catch (Throwable $failure) {
            Db::rollback('Qi context rollback');
            throw $failure;
        } finally {
            SidebarState::end_authored_transaction();
            $materializer->end_authored_transaction();
            $fields->end_authored_transaction();
            CacheInvalidationTransaction::end();
        }
    };
    $beforeFailure = [$targetDb->rows('wp_options'), $targetDb->rows('wp_wprism_map')];
    $earlierWrite = false;
    $targetDb->onQuery(static function (string $sql, string $_method, FakeWpdb $db) use (&$earlierWrite): ?string {
        if (str_starts_with($sql, 'INSERT INTO') && str_contains($sql, 'qi_blocks_global_styles')) {
            $earlierWrite = count($db->rows('wp_wprism_map')) === 7;
            return 'injected late Qi context style failure';
        }
        return null;
    });
    wprism_check_throws($write, RuntimeException::class, 'a failed style write aborts the complete widget transaction', 'database mutation failed: apply insert authored option');
    wprism_check($earlierWrite, 'style failure follows six allocated widget identities and sidebar materialization');
    wprism_check_same($beforeFailure, [$targetDb->rows('wp_options'), $targetDb->rows('wp_wprism_map')], 'late failure rolls back both native widgets and their identity map');
    $targetDb->onQuery(null);
    $write();
    $actual = array_column($targetDb->rows('wp_options'), 'option_value', 'option_name');
    $native = unserialize($nativeStyles, ['allowed_classes' => ['stdClass']]);
    $expected = $native;
    if ($expected['posts'] !== []) {
        $expected['posts'] = [813 => $expected['posts'][13]];
        foreach ($expected['posts'][813] as $style) foreach ($style->values as $value) {
            $value->selector = str_replace('body[class*="-13"]', 'body[class*="-813"]', $value->selector);
        }
    }
    wprism_check_same(serialize($expected), $actual['qi_blocks_global_styles'], "$context applies complete native style bytes with only declared page identities rebound");
    $widgets = unserialize($actual['widget_block'], ['allowed_classes' => false]);
    $assignments = unserialize($actual['sidebars_widgets'], ['allowed_classes' => false]);
    wprism_check_same(array_map(static fn(int $id): string => 'block-' . $id, range(1, 6)), $assignments['sidebar-1'], 'target allocates six divergent native widget instance IDs in authored order');
    foreach (range(2, 7) as $id) wprism_check_same($fixture['widgets'][$id], $widgets[$id - 1], 'every target widget preserves its complete native settings');
    wprism_check_same($protectedBlock, $widgets[50], 'an unassigned block in the touched family remains intact');
    wprism_check(!isset($widgets[99]), 'the selected sidebar replaces its displaced default');
    wprism_check_same($protectedText, $actual['widget_text'], 'the unrelated native widget family remains byte-identical');
    wprism_check_same($runtime, $actual['qi_blocks_custom_templates_flag'], 'native template registration remains target-local runtime state');
    wprism_check_same($fixture['template_body'], Blocks::apply_rewrite($templateBody, $policy, $tokens), 'native template classes and core template-part attributes survive the shared block codec');
    wprism_check_same(Canon::encode($captured), Canon::encode($capture($targetHome)), 'complete canonical sidebar and style state recaptures exactly at the target');
    $beforeRepeat = [$targetDb->rows('wp_options'), $targetDb->rows('wp_wprism_map')];
    $write();
    wprism_check_same($beforeRepeat, [$targetDb->rows('wp_options'), $targetDb->rows('wp_wprism_map')], 'repeat checked materialization preserves all option rows and widget identities');
}
if (wprism_check_failed() > 0) exit(1);
