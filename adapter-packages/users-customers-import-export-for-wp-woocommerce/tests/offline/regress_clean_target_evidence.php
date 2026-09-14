<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 2) . '/fixtures/clean-target-evidence.php';
$package = dirname(__DIR__, 2);
$settings = json_decode(file_get_contents($package . '/fixtures/native-settings.json'), true, flags: JSON_THROW_ON_ERROR)['source'];
$tables = array_fill_keys(['posts', 'postmeta', 'options', 'terms', 'term_taxonomy', 'term_relationships', 'termmeta',
    'users', 'usermeta', 'wt_iew_action_history', 'wt_iew_mapping_template'], []);
$before = ['format' => 'wprism-importer-native-settings/v1', 'tables' => $tables, 'files' => [], 'settings' => false];
$before['tables']['users'] = [['ID' => '1', 'user_login' => 'admin']];
ImporterCleanTargetEvidence::pristine($before);
wprism_check(true, 'fresh installation with empty native tables is admitted');
foreach (['history', 'template', 'user', 'file', 'missing-table'] as $fault) {
    $bad = $before;
    if ($fault === 'history') $bad['tables']['wt_iew_action_history'][] = ['id' => '1'];
    if ($fault === 'template') $bad['tables']['wt_iew_mapping_template'][] = ['id' => '1'];
    if ($fault === 'user') $bad['tables']['users'][] = ['ID' => '2', 'user_login' => 'old'];
    if ($fault === 'file') $bad['files']['webtoffee_export/old.csv'] = str_repeat('a', 64);
    if ($fault === 'missing-table') unset($bad['tables']['wt_iew_action_history']);
    wprism_check_throws(static fn() => ImporterCleanTargetEvidence::pristine($bad), RuntimeException::class, 'empty premises reject ' . $fault);
}
$prerequisites = ['users' => ['template-reader' => '2', 'template-editor' => '3', 'import-template-reader' => '4'],
    'input' => 'https://target.test/wp-content/webtoffee_import/target-input.csv', 'input_sha256' => str_repeat('b', 64)];
foreach ($prerequisites['users'] as $login => $id) $before['tables']['users'][] = ['ID' => $id, 'user_login' => $login];
$before['files']['webtoffee_import/target-input.csv'] = $prerequisites['input_sha256'];
$before['tables']['terms'] = [['term_id' => '1', 'slug' => 'uncategorized']];
$blocks = ['_multiwidget' => 1];
foreach (range(2, 6) as $id) $blocks[$id] = ['content' => 'Block ' . $id];
foreach (['blogname' => 'Source title', 'widget_block' => serialize($blocks), 'widget_text' => serialize([]),
    'sidebars_widgets' => serialize(['wp_inactive_widgets' => [], 'sidebar-1' => ['block-2', 'block-3', 'block-4', 'block-5', 'block-6'], 'array_version' => 3])] as $name => $value) {
    $before['tables']['options'][] = ['option_id' => (string) (count($before['tables']['options']) + 1),
        'option_name' => $name, 'option_value' => $value, 'autoload' => 'auto'];
}
$source = $before;
$source['settings'] = $settings;
$source['tables']['termmeta'] = [['meta_id' => '1', 'term_id' => '1', 'meta_key' => '_wprism_uuid', 'meta_value' => '01a09e6e-3ca7-7939-916e-f317e90b543c']];
$source['tables']['options'][] = ['option_id' => '5', 'option_name' => 'wt_iew_advanced_settings', 'option_value' => serialize($settings), 'autoload' => 'auto'];
foreach (['export' => ['Selected users', 'Selected users copy'],
    'import' => ['Reusable input mapping', 'Reusable input copy', 'Draft input mapping']] as $type => $names) {
    foreach ($names as $name) {
        $form = json_decode(file_get_contents($package . '/fixtures/native-' . $type . '-templates.json'), true, flags: JSON_THROW_ON_ERROR)['form'];
        if ($name === 'Draft input mapping') $form['method_import_form_data']['wt_iew_local_file'] = '';
        $source['tables']['wt_iew_mapping_template'][] = ['id' => (string) (count($source['tables']['wt_iew_mapping_template']) + 1),
            'template_type' => $type, 'item_type' => 'user', 'name' => $name, 'data' => json_encode($form, JSON_THROW_ON_ERROR)];
    }
}
$after = $source;
$after['tables']['options'][2]['option_value'] = serialize(['_multiwidget' => 1]);
foreach ($after['tables']['wt_iew_mapping_template'] as &$row) {
    $form = json_decode($row['data'], true, flags: JSON_THROW_ON_ERROR);
    unset($form['method_' . $row['template_type'] . '_form_data']['selected_template']);
    if ($row['template_type'] === 'import' && $row['name'] !== 'Draft input mapping') $form['method_import_form_data']['wt_iew_local_file'] = $prerequisites['input'];
    $row['data'] = json_encode($form, JSON_THROW_ON_ERROR);
}
unset($row);
ImporterCleanTargetEvidence::created($source, $before, $after, $prerequisites);
wprism_check(true, 'five creations and nine settings preserve a clean native target');
foreach (['lost-template', 'duplicate-id', 'lost-setting', 'setting-id', 'setting-autoload', 'wrong-input', 'cursor', 'user-mutation', 'history-mutation', 'file-mutation', 'lost-core-identity'] as $fault) {
    $bad = $after;
    if ($fault === 'lost-template') array_pop($bad['tables']['wt_iew_mapping_template']);
    if ($fault === 'duplicate-id') $bad['tables']['wt_iew_mapping_template'][1]['id'] = '1';
    if ($fault === 'lost-setting') $bad['tables']['options'][4]['option_value'] = serialize([]);
    if ($fault === 'setting-id') $bad['tables']['options'][4]['option_id'] = '1';
    if ($fault === 'setting-autoload') $bad['tables']['options'][4]['autoload'] = 'off';
    if ($fault === 'wrong-input' || $fault === 'cursor') {
        $form = json_decode($bad['tables']['wt_iew_mapping_template'][2]['data'], true, flags: JSON_THROW_ON_ERROR);
        if ($fault === 'wrong-input') $form['method_import_form_data']['wt_iew_local_file'] = 'https://source.test/source.csv';
        else $form['method_import_form_data']['selected_template'] = '2';
        $bad['tables']['wt_iew_mapping_template'][2]['data'] = json_encode($form, JSON_THROW_ON_ERROR);
    }
    if ($fault === 'user-mutation') $bad['tables']['users'][1]['user_login'] = 'changed';
    if ($fault === 'history-mutation') $bad['tables']['wt_iew_action_history'][] = ['id' => '1'];
    if ($fault === 'file-mutation') $bad['files']['webtoffee_import/target-input.csv'] = str_repeat('c', 64);
    if ($fault === 'lost-core-identity') $bad['tables']['termmeta'] = [];
    wprism_check_throws(static fn() => ImporterCleanTargetEvidence::created($source, $before, $bad, $prerequisites), RuntimeException::class, 'creation evidence rejects ' . $fault);
}
$updated = $after;
$updated['tables']['wt_iew_mapping_template'][0]['name'] = 'Renamed selection';
$updatedSource = $source;
$updatedSource['tables']['wt_iew_mapping_template'][0]['name'] = 'Renamed selection';
ImporterCleanTargetEvidence::updated($updatedSource, $after, $updated, $prerequisites['input']);
wprism_check(true, 'native rename after creation preserves every other observed value');
foreach (['id', 'unrelated-template', 'history', 'file'] as $fault) {
    $bad = $updated;
    if ($fault === 'id') $bad['tables']['wt_iew_mapping_template'][0]['id'] = '99';
    if ($fault === 'unrelated-template') $bad['tables']['wt_iew_mapping_template'][1]['name'] = 'unexpected';
    if ($fault === 'history') $bad['tables']['wt_iew_action_history'][] = ['id' => '1'];
    if ($fault === 'file') $bad['files']['webtoffee_import/target-input.csv'] = str_repeat('c', 64);
    wprism_check_throws(static fn() => ImporterCleanTargetEvidence::updated($updatedSource, $after, $bad, $prerequisites['input']), RuntimeException::class, 'update evidence rejects ' . $fault);
}
wprism_check_summary('Importer clean target evidence');
