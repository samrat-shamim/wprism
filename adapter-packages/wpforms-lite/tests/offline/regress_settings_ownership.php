<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
// A prior source library is an explicit counterfactual, never a runtime override.
$libraryRoot = $argv[1] ?? $root;
require_once $root . '/sandbox/tests/lib/check.php';
require_once $root . '/sandbox/tests/lib/wp_stubs.php';
require_once $root . '/sandbox/tests/lib/FakeWpdb.php';
require_once $root . '/sandbox/tests/lib/agent_version.php';
require_once $root . '/agent/src/Policy/AdapterLibrary.php';
require_once $root . '/agent/src/Capture/OptionsCapture.php';
require_once $root . '/agent/src/Capture/CaptureSafetyGates.php';
require_once $root . '/agent/src/Apply/OptionsMaterializer.php';
require_once $root . '/agent/src/Kernel/Db.php';

use WPrism\AdapterLibrary;
use WPrism\ApplyFieldMaterializer;
use WPrism\CacheInvalidationTransaction;
use WPrism\Canon;
use WPrism\CaptureSafetyGates;
use WPrism\Db;
use WPrism\NativeDatabaseProfile;
use WPrism\OptionsCapture;
use WPrism\OptionsMaterializer;
use WPrism\OptionState;
use WPrism\Policy;
use WPrism\Tokens;
use WPrismTest\FakeWpdb;
use WPrismTest\WpStore;

wprism_test_define_agent_versions();
WpStore::reset()->seedOptions(['home' => 'https://settings.example.test']);
$db = FakeWpdb::install()->enableInformationSchema()->enableFullApplySqlExtensions();
$db->seedTable('wp_wprism_map', [])->setTableEngine('wp_wprism_map', 'InnoDB');
$db->setTableEngine('wp_options', 'InnoDB')->setColumns('wp_options', [
    'option_id' => 'bigint unsigned', 'option_name' => 'varchar(191)',
    'option_value' => 'longtext', 'autoload' => 'varchar(20)',
])->setUniqueKey('wp_options', ['option_name'])->setIndexes('wp_options', [[
    'Key_name' => 'option_name', 'Column_name' => 'option_name', 'Seq_in_index' => 1,
    'Sub_part' => null, 'Non_unique' => 0, 'Index_type' => 'BTREE',
]]);
$policy = Policy::load(null, ['core', 'wpforms-lite'],
    adapterLibrary: AdapterLibrary::fromSourcePackage($libraryRoot, 'wpforms-lite'));
$fixture = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/fixtures/native-authoring.json'), true, 64, JSON_THROW_ON_ERROR);
$source = $fixture['settings'];
$localKeys = ['gdpr-disable-uuid', 'gdpr-disable-details', 'modern-markup-is-set',
    'modern-markup-hide-setting', 'lite-connect-enabled'];
$expected = array_diff_key($source, array_flip($localKeys));
$tokens = new Tokens('https://settings.example.test', 'https://settings.example.test/wp-content/uploads');
$tokens->policy = $policy;
$gates = new CaptureSafetyGates('/fixture/wpforms-settings-ownership');
$capture = new OptionsCapture($policy, $tokens,
    static function (string $section, string $key, mixed $value, array $rule) use ($gates): void {
        $gates->guardSecret($section, $key, $value, $rule);
        $gates->guardPersonalData($section, $key, $value, $rule);
    }, static function (): never { throw new LogicException('settings have no entity references'); },
    static function (): never { throw new LogicException('settings have no identity lookup'); });
$sourceRow = ['option_id' => 1, 'option_name' => 'wpforms_settings',
    'option_value' => serialize($source), 'autoload' => 'yes'];
$db->seedTable('wp_options', [$sourceRow]);
$captured = $capture->capture(false)['document'];
wprism_check_same(Canon::encode(['wpforms_settings' => $expected]), Canon::encode(OptionState::values($captured)),
    'Capture excludes Pro-only disabled controls, not merely credential-shaped values');
wprism_check_same([$sourceRow], $db->rows('wp_options'), 'Capture leaves every source option byte intact');
wprism_check_same(20, count($expected), 'the remaining roster includes 19 unconditional controls and conditional modern markup');

// Lite's General UI persists absent disabled Pro controls as false. Those
// observed defaults do not authorize replacing a target's downgraded residue.
// Hostile local values are synthetic mechanism fixtures, not native Pro proof.
$target = array_replace($source, [
    'disable-css' => '3', 'global-assets' => false, 'gdpr' => false,
    'gdpr-disable-uuid' => true, 'gdpr-disable-details' => true,
    'modern-markup-is-set' => 'target marker', 'modern-markup-hide-setting' => false,
    'lite-connect-enabled' => true, 'validation-required' => 'Target copy',
    'license-key' => 'sk_live_LOCALSETTINGS1234567890',
    'integrations-providers' => ['customer_email' => 'private@example.test'],
    'future-setting' => ['nested' => [null, false, 0, '0', '日本語 Ω']],
]);
$targetRows = [array_replace($sourceRow, ['option_value' => serialize($target)]),
    ['option_id' => 2, 'option_name' => 'wpforms_crypto_secret_key',
        'option_value' => 'sk_live_LOCALCRYPTO1234567890', 'autoload' => 'no']];
$db->seedTable('wp_options', $targetRows);
$field = new ApplyFieldMaterializer($policy, $tokens);
$writer = new OptionsMaterializer($policy, $tokens, $field);
$apply = static function (array $document) use ($field, $writer): array {
    $warnings = [];
    Db::start_repeatable_read('WPForms settings ownership fixture',
        new NativeDatabaseProfile(['wp_options'], ['wp_options']));
    $field->begin_authored_transaction();
    $writer->begin_authored_transaction();
    CacheInvalidationTransaction::begin();
    try {
        $writer->apply_options($document, false, $warnings);
        Db::commit('WPForms settings ownership fixture commit');
        $writer->commit_authored_transaction();
        CacheInvalidationTransaction::finish();
    } catch (Throwable $failure) {
        $writer->rollback_authored_transaction();
        Db::rollback('WPForms settings ownership fixture rollback');
        throw $failure;
    } finally {
        $writer->end_authored_transaction();
        $field->end_authored_transaction();
        CacheInvalidationTransaction::end();
    }
    return $warnings;
};
wprism_check_same([], $apply($captured), 'the shared mixed-option writer needs no WPForms executable or warning fallback');
$after = $db->rows('wp_options');
wprism_check_same(serialize(array_replace($target, $expected)), $after[0]['option_value'],
    'Apply merges only authored settings and preserves every local member, type and nested value');
wprism_check_same($targetRows[1], $after[1], 'the complete separate crypto row remains byte-identical');
wprism_check_same(array_diff_key($targetRows[0], ['option_value' => true]), array_diff_key($after[0], ['option_value' => true]),
    'the existing option identity and autoload are preserved');
wprism_check_same(Canon::encode($captured), Canon::encode($capture->capture(false)['document']),
    'full mixed-option recapture converges to the actual source capture');
wprism_check_same([], $apply($captured), 'repeat materialization has no warnings');
wprism_check_same($after, $db->rows('wp_options'), 'repeat materialization is an exact physical fixed point');
foreach (['gdpr-disable-uuid', 'gdpr-disable-details'] as $key) {
    $bad = OptionState::values($captured)['wpforms_settings'];
    $bad[$key] = false;
    wprism_check_throws(static fn() => $apply(OptionState::document([
        'wpforms_settings' => OptionState::present($bad, 'yes'),
    ])), RuntimeException::class, 'a forged Pro-only authored subkey refuses at the shared materializer');
    wprism_check_same($after, $db->rows('wp_options'), 'forged Pro subkey refusal preserves all target option rows');
}
wprism_check_summary('regress_wpforms_lite_settings_ownership');
