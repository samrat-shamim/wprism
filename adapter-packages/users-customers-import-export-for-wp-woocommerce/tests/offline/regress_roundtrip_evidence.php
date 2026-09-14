<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 2) . '/fixtures/roundtrip-evidence.php';
$capsule = dirname(__DIR__, 2);
$settings = json_decode(file_get_contents($capsule . '/fixtures/native-settings.json'), true, 32, JSON_THROW_ON_ERROR);
$forms = [];
foreach (['import', 'export'] as $type) $forms[$type] = json_decode(file_get_contents($capsule
    . '/fixtures/native-' . $type . '-templates.json'), true, 32, JSON_THROW_ON_ERROR)['form'];
$names = [['export', 'Selected users'], ['export', 'Selected users copy'], ['import', 'Reusable input mapping'],
    ['import', 'Reusable input copy'], ['import', 'Draft input mapping']];
$tables = ['posts', 'postmeta', 'options', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta',
    'users', 'usermeta', 'wt_iew_action_history', 'wt_iew_mapping_template'];
$source = ['format' => 'wprism-importer-native-settings/v1', 'tables' => array_fill_keys($tables, []),
    'settings' => $settings['source'], 'files' => []];
$source['tables']['users'] = [['ID' => '2', 'user_login' => 'template-reader'], ['ID' => '3', 'user_login' => 'template-editor']];
$source['tables']['options'] = [
    ['option_id' => '1', 'option_name' => 'blogname', 'option_value' => 'Source title', 'autoload' => 'on'],
    ['option_id' => '2', 'option_name' => 'wt_iew_advanced_settings', 'option_value' => serialize($settings['source']), 'autoload' => 'auto'],
    ['option_id' => '3', 'option_name' => 'local', 'option_value' => 'keep-local', 'autoload' => 'off'],
];
foreach ($names as $index => [$type, $name]) {
    $form = $forms[$type];
    if ($index === 4) $form['method_import_form_data']['wt_iew_local_file'] = '';
    $source['tables']['wt_iew_mapping_template'][] = ['id' => (string) ($index + 1), 'template_type' => $type,
        'item_type' => 'user', 'name' => $name, 'data' => json_encode($form, JSON_THROW_ON_ERROR)];
}
$before = $source;
$before['settings'] = $settings['target'];
$before['tables']['options'][0]['option_value'] = 'Target title';
$before['tables']['options'][1]['option_value'] = serialize($settings['target'] + ['other_module_key' => ['nested' => 'keep-target-local']]);
$before['tables']['users'] = [['ID' => '82', 'user_login' => 'template-reader'], ['ID' => '93', 'user_login' => 'template-editor']];
foreach (range(101, 106) as $id) $before['tables']['users'][] = ['ID' => (string) $id, 'user_login' => 'local-' . $id];
foreach (['posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'usermeta'] as $table) {
    $before['tables'][$table] = [['local_witness' => $table]];
}
$before['tables']['wt_iew_action_history'] = array_map(static fn(int $id): array => ['id' => (string) $id, 'data' => 'completed'], range(1, 4));
$before['files'] = array_fill_keys(['import/input.csv', 'import/target-input.csv', 'import/rotated-input.csv', 'export/a.csv', 'export/b.csv', 'export/c.csv'], str_repeat('a', 64));
$after = $before;
$after['settings'] = $settings['source'];
$after['tables']['options'][0]['option_value'] = 'Source title';
$after['tables']['options'][1]['option_value'] = serialize($settings['source'] + ['other_module_key' => ['nested' => 'keep-target-local']]);
$after['tables']['wt_iew_mapping_template'] = $source['tables']['wt_iew_mapping_template'];
foreach ($after['tables']['wt_iew_mapping_template'] as $index => &$row) {
    $row['id'] = (string) (103 + $index);
    $form = json_decode($row['data'], true, 32, JSON_THROW_ON_ERROR);
    unset($form['method_' . $row['template_type'] . '_form_data']['selected_template']);
    if ($row['template_type'] === 'export') $form['filter_form_data']['wt_iew_email'] = ['82', '93'];
    elseif ($index !== 4) $form['method_import_form_data']['wt_iew_local_file'] = 'https://target.test/wp-content/webtoffee_import/target-input.csv';
    $row['data'] = json_encode($form, JSON_THROW_ON_ERROR);
}
unset($row);
$before['tables']['wt_iew_mapping_template'] = [$after['tables']['wt_iew_mapping_template'][0], $after['tables']['wt_iew_mapping_template'][2]];
foreach ($before['tables']['wt_iew_mapping_template'] as &$row) {
    $form = json_decode($row['data'], true, 32, JSON_THROW_ON_ERROR);
    $form['mapping_form_data']['mapping_selected_fields']['display_name'] = 'Target local header';
    $row['data'] = json_encode($form, JSON_THROW_ON_ERROR);
}
unset($row);
foreach ([['Import', 'user'], ['export', 'product']] as $index => [$type, $item]) {
    $foreign = ['id' => (string) (101 + $index), 'template_type' => $type, 'item_type' => $item,
        'name' => 'Excluded', 'data' => '{"local":"keep"}'];
    $before['tables']['wt_iew_mapping_template'][] = $foreign;
    $after['tables']['wt_iew_mapping_template'][] = $foreign;
}
ImporterRoundtripEvidence::applied($source, $before, $after);
wprism_check(true, 'full native census admits exact authored forms with adopted/created IDs and local inputs');
$mutateForm = static function (array &$record, int $index, callable $mutate): void {
    $form = json_decode($record['tables']['wt_iew_mapping_template'][$index]['data'], true, 32, JSON_THROW_ON_ERROR);
    $mutate($form);
    $record['tables']['wt_iew_mapping_template'][$index]['data'] = json_encode($form, JSON_THROW_ON_ERROR);
};
foreach (['missing-table', 'missing-witness', 'missing-template', 'extra-template', 'wrong-name', 'lost-adoption', 'source-id',
    'lost-foreign', 'changed-foreign', 'lost-setting', 'lost-local-setting', 'wrong-getter', 'wrong-autoload', 'wrong-title',
    'changed-option', 'changed-user', 'changed-history', 'changed-file', 'wrong-user-binding', 'wrong-local-input',
    'nonempty-draft', 'leaked-cursor', 'changed-mapping', 'lost-form-section'] as $fault) {
    $bad = $after;
    $badBefore = $before;
    switch ($fault) {
        case 'missing-table': unset($bad['tables']['usermeta'], $badBefore['tables']['usermeta']); break;
        case 'missing-witness': $bad['files'] = $badBefore['files'] = []; break;
        case 'missing-template': array_pop($bad['tables']['wt_iew_mapping_template']); break;
        case 'extra-template': $bad['tables']['wt_iew_mapping_template'][] = $bad['tables']['wt_iew_mapping_template'][0]; break;
        case 'wrong-name': $bad['tables']['wt_iew_mapping_template'][1]['name'] = 'Renamed'; break;
        case 'lost-adoption': $bad['tables']['wt_iew_mapping_template'][0]['id'] = '999'; break;
        case 'source-id': $bad['tables']['wt_iew_mapping_template'][1]['id'] = '2'; break;
        case 'lost-foreign': $bad['tables']['wt_iew_mapping_template'][5]['item_type'] = 'user'; $bad['tables']['wt_iew_mapping_template'][5]['template_type'] = 'import'; break;
        case 'changed-foreign': $bad['tables']['wt_iew_mapping_template'][5]['data'] = '{}'; break;
        case 'lost-setting': $bad['tables']['options'][1]['option_value'] = serialize($settings['target'] + ['other_module_key' => ['nested' => 'keep-target-local']]); break;
        case 'lost-local-setting': $bad['tables']['options'][1]['option_value'] = serialize($settings['source']); break;
        case 'wrong-getter': $bad['settings']['wt_iew_default_import_batch'] = 999; break;
        case 'wrong-autoload': $bad['tables']['options'][1]['autoload'] = 'off'; break;
        case 'wrong-title': $bad['tables']['options'][0]['option_value'] = 'Target title'; break;
        case 'changed-option': $bad['tables']['options'][2]['option_value'] = 'changed'; break;
        case 'changed-user': $bad['tables']['users'][7]['user_login'] = 'changed'; break;
        case 'changed-history': $bad['tables']['wt_iew_action_history'][0]['data'] = 'changed'; break;
        case 'changed-file': $bad['files']['export/a.csv'] = str_repeat('b', 64); break;
        case 'wrong-user-binding': $mutateForm($bad, 0, static function (array &$f): void { $f['filter_form_data']['wt_iew_email'] = ['101']; }); break;
        case 'wrong-local-input': $mutateForm($bad, 2, static function (array &$f): void { $f['method_import_form_data']['wt_iew_local_file'] = 'https://source.test/source.csv'; }); break;
        case 'nonempty-draft': $mutateForm($bad, 4, static function (array &$f): void { $f['method_import_form_data']['wt_iew_local_file'] = 'https://target.test/target-input.csv'; }); break;
        case 'leaked-cursor': $mutateForm($bad, 0, static function (array &$f): void { $f['method_export_form_data']['selected_template'] = '1'; }); break;
        case 'changed-mapping': $mutateForm($bad, 0, static function (array &$f): void { $f['mapping_form_data']['mapping_selected_fields']['display_name'] = 'Local_Display'; }); break;
        case 'lost-form-section': $mutateForm($bad, 2, static function (array &$f): void { unset($f['advanced_form_data']); }); break;
    }
    wprism_check_throws(static fn() => ImporterRoundtripEvidence::applied($source, $badBefore, $bad), RuntimeException::class,
        'native roundtrip evidence rejects ' . $fault);
}
foreach (['posts', 'postmeta', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta', 'usermeta'] as $table) {
    $bad = $after;
    $bad['tables'][$table] = [];
    wprism_check_throws(static fn() => ImporterRoundtripEvidence::applied($source, $before, $bad), RuntimeException::class,
        'native roundtrip evidence rejects lost local ' . $table);
}
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/ShellProbe.php';
$probe = <<<'SH'
set -euo pipefail
ROOT="$1" PACKAGE="$2"
fail() { printf 'FAIL: %s\n' "$*" >&2; exit 1; }
pass() { printf 'ok: %s\n' "$*"; }
CONF_PAIR="importerprobe$(php -r 'echo bin2hex(random_bytes(8));')"
trap 'rm -rf -- "$ROOT/sandbox/tmp/importer-roundtrip-$CONF_PAIR"' EXIT
cd "$ROOT/sandbox"
. "$PACKAGE/fixtures/roundtrip.sh"
importer_roundtrip_begin
stdout="$3" stderr="$4" result="$5"
fixture_command() { printf '%s' "$stdout"; printf '%s' "$stderr" >&2; return "$result"; }
importer_roundtrip_capture probe fixture_command
SH;
foreach (['valid', 'stdout-warning', 'stderr-warning', 'unknown-stderr', 'malformed-json', 'extra-document', 'nonzero'] as $fault) {
    $stdout = '{"status":true}';
    $stderr = '';
    $status = '0';
    if ($fault === 'stdout-warning') $stdout = 'PHP Warning: fixture' . $stdout;
    if ($fault === 'stderr-warning') $stderr = 'PHP Warning: fixture';
    if ($fault === 'unknown-stderr') $stderr = 'unexpected transport';
    if ($fault === 'malformed-json') $stdout = '{"status":';
    if ($fault === 'extra-document') $stdout .= '{"status":true}';
    if ($fault === 'nonzero') $status = '2';
    [$exit, $out] = WPrismTest\ShellProbe::run($probe, [dirname(__DIR__, 4), $capsule, $stdout, $stderr, $status], dirname(__DIR__, 4));
    wprism_check_same($fault === 'valid', $exit === 0, 'actual roundtrip capture checks all streams and exit: ' . $fault);
    if ($fault === 'valid') wprism_check(str_contains($out, 'ok: Importer roundtrip probe'), 'actual roundtrip positive admission is reached');
}
wprism_check_summary('Importer roundtrip evidence');
