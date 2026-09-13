<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
foreach (['check.php', 'wp_stubs.php', 'FakeWpdb.php', 'agent_version.php', 'frozen_policy.php'] as $file) {
    require_once "$root/sandbox/tests/lib/$file";
}
wprism_test_define_agent_versions();
require_once "$root/agent/src/Grammar/Tokens.php";
require_once "$root/agent/src/Capture/OptionsCapture.php";
require_once "$root/agent/src/Capture/CaptureSafetyGates.php";
require_once "$root/agent/src/Repository/RepositoryCompiler.php";
require_once "$root/agent/src/Kernel/Db.php";
require_once "$root/agent/src/Apply/OptionsMaterializer.php";

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
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

$manifest = Canon::decode(Canon::read_file($capsule . '/package/manifest.json'));
$native = Canon::decode(Canon::read_file($capsule . '/fixtures/native-settings.json'));
$core = Canon::decode(Canon::read_file($root . '/platform/adapter-library/core/manifest.json'));
$site = FrozenPolicy::site([$core, $manifest], WPRISM_SPEC_VERSION);
$policy = FrozenPolicy::policy([$core, $manifest], $site);
$scratch = sys_get_temp_dir() . '/wprism-importer-settings-' . bin2hex(random_bytes(8));
mkdir($scratch . '/state/options', 0700, true);
$remove = static function (string $path) use (&$remove): void {
    if (!is_dir($path) || is_link($path)) { if (file_exists($path) || is_link($path)) unlink($path); return; }
    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $entry) $remove($entry->getPathname());
    rmdir($path);
};
register_shutdown_function(static fn() => $remove($scratch));
Canon::write_file($scratch . '/site.wprism.json', Canon::encode($site));
WpStore::reset()->seedOptions(['home' => 'https://source.test']);
$tokens = new Tokens('https://source.test', 'https://source.test/wp-content/uploads');
$gates = new CaptureSafetyGates($scratch);
$guard = static function (string $section, string $key, mixed $value, array $rule) use ($gates): void {
    $gates->guardSecret($section, $key, $value, $rule);
    $gates->guardPersonalData($section, $key, $value, $rule);
};
$database = static function (mixed $allSettings): FakeWpdb {
    return FakeWpdb::install()->enableInformationSchema()->seedTable('wp_options', [
        ['option_id' => 1, 'option_name' => 'wt_iew_advanced_settings', 'option_value' => serialize($allSettings), 'autoload' => 'auto'],
        ['option_id' => 2, 'option_name' => 'wt_iew_admin_modules', 'option_value' => serialize(['import' => 1, 'export' => 1, 'history' => 1]), 'autoload' => 'auto'],
        ['option_id' => 3, 'option_name' => 'wt_u_iew_is_active', 'option_value' => '1', 'autoload' => 'auto'],
        ['option_id' => 4, 'option_name' => 'wt_u_iew_basic_json_migration_complete', 'option_value' => 'yes', 'autoload' => 'auto'],
    ])->setColumns('wp_options', ['option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)',
        'option_value' => 'longtext', 'autoload' => 'varchar(20)'])
        ->setAutoIncrement('wp_options', 5, 'option_id')->setUniqueKey('wp_options', ['option_name'])
        ->setIndexes('wp_options', [['Key_name' => 'option_name', 'Column_name' => 'option_name', 'Seq_in_index' => 1,
            'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE']])->setTableEngine('wp_options', 'InnoDB')
        ->seedTable('wp_users', [['ID' => 43, 'user_login' => 'target-reader', 'user_email' => 'reader@example.test']])
        ->seedTable('wp_usermeta', [['umeta_id' => 9, 'user_id' => 43, 'meta_key' => 'session_tokens', 'meta_value' => 'local-session']])
        ->seedTable('wp_wt_iew_action_history', [['id' => 13, 'data' => '{"file":"local-export.csv"}']])
        ->seedTable('wp_wt_iew_mapping_template', [['id' => 21, 'name' => 'Local user mapping', 'item_type' => 'user']]);
};
$capture = static function () use ($policy, $tokens, $guard, $gates): array {
    $capture = new OptionsCapture($policy, $tokens, $guard,
        static function (): never { throw new LogicException('plain settings have no identity reference'); },
        static function (): never { throw new LogicException('plain settings have no table reference'); });
    $result = $capture->capture(false);
    $gates->assertOptions($result['unclassified'], $result['unscoped_refs'], $result['unscoped_option_name_refs'], $tokens);
    return $result['document'];
};
$compile = static function (array $document) use ($scratch, $policy) {
    Canon::write_file($scratch . '/state/options/core.json', Canon::encode($document));
    return RepositoryCompiler::compile($scratch, $policy);
};
$documents = [];
foreach ($native as $side => $settings) {
    $db = $database($settings + ['other_module_key' => 'keep-local']);
    $before = $db->rows('wp_options');
    $document = $documents[$side] = $capture();
    $values = OptionState::values($document);
    wprism_check_same(['wt_iew_advanced_settings'], array_keys($values), "$side runtime markers never enter authored settings");
    wprism_check_same(Canon::encode($settings), Canon::encode($values['wt_iew_advanced_settings']),
        "$side nine native settings retain exact scalar types while unknown module keys remain local");
    wprism_check_same('auto', OptionState::records($document)['wt_iew_advanced_settings']['autoload'], "$side native autoload spelling is preserved");
    wprism_check_same($before, $db->rows('wp_options'), "$side Capture preserves every native option byte");
    wprism_check_same(1, count($compile($document)->tree()), "$side captured settings compile through the immutable product path");
}

WpStore::reset()->seedOptions(['home' => 'https://target.test']);
$targetTokens = new Tokens('https://target.test', 'https://target.test/wp-content/uploads');
$fields = new ApplyFieldMaterializer($policy, $targetTokens);
$materializer = new OptionsMaterializer($policy, $targetTokens, $fields);
$write = static function (array $document, bool $abort = false) use ($fields, $materializer): array {
    Db::start_repeatable_read('importer settings transaction', new NativeDatabaseProfile(['wp_options'], ['wp_options']));
    $fields->begin_authored_transaction();
    $materializer->begin_authored_transaction();
    CacheInvalidationTransaction::begin();
    try {
        $warnings = [];
        $materializer->apply_options($document, false, $warnings);
        if ($abort) throw new RuntimeException('fixture failure after settings materialization');
        Db::commit('importer settings commit');
        $materializer->commit_authored_transaction();
        CacheInvalidationTransaction::finish();
        return $warnings;
    } catch (Throwable $failure) {
        $materializer->rollback_authored_transaction();
        Db::rollback_after_failure($failure, 'importer settings rollback');
        throw $failure;
    } finally {
        $materializer->end_authored_transaction();
        $fields->end_authored_transaction();
        CacheInvalidationTransaction::end();
    }
};
$snapshot = static function (FakeWpdb $db): array {
    $rows = [];
    foreach (['wp_options', 'wp_users', 'wp_usermeta', 'wp_wt_iew_action_history', 'wp_wt_iew_mapping_template'] as $table) {
        $rows[$table] = $db->rows($table);
    }
    return $rows;
};
$local = ['other_module_key' => ['nested' => 'keep-target-local']];
$db = $database($native['target'] + $local);
$before = $snapshot($db);
$source = $compile($documents['source'])->tree()['options/core']['data'];
wprism_check_same([], $write($source), 'checked settings Apply has no warnings');
$after = $snapshot($db);
wprism_check_same(Canon::encode($native['source'] + $local),
    Canon::encode(unserialize($after['wp_options'][0]['option_value'], ['allowed_classes' => false])),
    'Apply replaces all nine native values while preserving nested foreign-module settings');
wprism_check_same('auto', $after['wp_options'][0]['autoload'], 'Apply preserves canonical native autoload');
$unchanged = $after;
$unchanged['wp_options'][0] = $before['wp_options'][0];
wprism_check_same($before, $unchanged, 'settings Apply preserves runtime markers, users, sessions, history and templates');
wprism_check_same($documents['source'], $capture(), 'target recapture returns the complete source settings document');
wprism_check_same([], $write($source), 'repeated settings Apply has no warnings');
wprism_check_same($after, $snapshot($db), 'repeat preserves every native row byte');
wprism_check_throws(static fn() => $write($documents['target'], true), RuntimeException::class,
    'failure after the real settings write rolls back', 'fixture failure after settings materialization');
wprism_check_same($after, $snapshot($db), 'rollback restores exact settings bytes and all operational data');
wprism_check_same([], $write($documents['target']), 'a fresh transaction succeeds after rollback');
wprism_check_same($before, $snapshot($db), 'reverse settings Apply restores the complete target native state');

// Missing authored subkeys mean source absence, not an instruction to remove
// the shared row. OptionsMaterializer reports each removed declared key.
$partial = $native['source'];
unset($partial['wt_iew_include_bom']);
$withSettings = static function (array $settings) use ($documents): array {
    $records = OptionState::records($documents['source']);
    $records['wt_iew_advanced_settings'] = OptionState::present($settings, 'auto');
    return OptionState::document($records);
};
$partialDocument = $withSettings($partial);
$partialArtifact = $compile($partialDocument);
$warnings = $write($partialArtifact->tree()['options/core']['data']);
wprism_check_same(1, count($warnings), 'source absence reports one authored-subkey removal');
wprism_check(str_contains($warnings[0] ?? '', 'wt_iew_include_bom: removed'), 'removal names the exact declared subkey');
wprism_check_same(Canon::encode($partial + $local), Canon::encode(unserialize($db->rows('wp_options')[0]['option_value'], ['allowed_classes' => false])),
    'subkey removal preserves the shared option and every local sibling');
wprism_check_same($partialDocument, $capture(), 'partial settings recapture retains authored absence');
$beforeAbsent = $snapshot($db);
wprism_check_same([], $write(OptionState::document(['wt_iew_advanced_settings' => OptionState::absent()])), 'an absent option record makes no write');
wprism_check_same($beforeAbsent, $snapshot($db), 'whole shared option absence preserves target settings');

foreach (['foreign-key', 'secret', 'personal-data'] as $fault) {
    $bad = $native['source'];
    if ($fault === 'foreign-key') $bad['other_module_key'] = 'not-authorized';
    if ($fault === 'secret') $bad['wt_iew_default_import_method'] = 'sk_live_' . str_repeat('a', 32);
    if ($fault === 'personal-data') $bad['wt_iew_default_import_method'] = 'person@example.test';
    $badDocument = $withSettings($bad);
    wprism_check_throws(static fn() => $compile($badDocument), RuntimeException::class, "compiler refuses $fault before target contact");
    wprism_check_same($beforeAbsent, $snapshot($db), "refused $fault preserves the target");
}
$db = $database('malformed-native-container');
$beforeMalformed = $snapshot($db);
wprism_check_throws(static fn() => $write($source), RuntimeException::class, 'malformed target container refuses before replacement', 'not array-shaped');
wprism_check_same($beforeMalformed, $snapshot($db), 'container refusal preserves the complete target state');
wprism_check_summary('importer native settings');
