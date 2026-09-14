<?php
declare(strict_types=1);

/** Synthetic native shapes shared by capsule oracle mutation tests, never a live postimage. */
function importer_test_native_record(string $package, array $fixtureSettings): array {
    $native = ['format' => 'wprism-importer-native-settings/v1', 'tables' => array_fill_keys(['posts', 'postmeta', 'options', 'terms',
        'term_taxonomy', 'term_relationships', 'termmeta', 'users', 'usermeta', 'wt_iew_action_history', 'wt_iew_mapping_template'], []),
        'settings' => $fixtureSettings + ['other_module_key' => ['nested' => 'keep-target-local']], 'files' => array_fill_keys(['input.csv', 'old.csv', 'index.php'], str_repeat('a', 64))];
    $native['tables']['users'] = [['ID' => '1', 'user_login' => 'admin'], ['ID' => '82', 'user_login' => 'template-reader'],
        ['ID' => '93', 'user_login' => 'template-editor'], ['ID' => '104', 'user_login' => 'import-template-reader']];
    $native['tables']['wt_iew_action_history'] = [['id' => '1', 'data' => 'local-history']];
    $native['tables']['options'] = [['option_id' => '13', 'option_name' => 'wt_iew_advanced_settings',
        'option_value' => serialize($fixtureSettings + ['other_module_key' => ['nested' => 'keep-target-local']]), 'autoload' => 'auto'],
        ['option_id' => '14', 'option_name' => 'local', 'option_value' => 'preserve', 'autoload' => 'off']];
    foreach (['export' => ['Selected users', 'Selected users copy'], 'import' => ['Reusable input mapping', 'Reusable input copy', 'Draft input mapping']] as $type => $names) {
        foreach ($names as $name) {
            $form = json_decode(file_get_contents($package . '/fixtures/native-' . $type . '-templates.json'), true, flags: JSON_THROW_ON_ERROR)['form'];
            unset($form['method_' . $type . '_form_data']['selected_template']);
            if ($type === 'export') $form['filter_form_data']['wt_iew_email'] = ['82', '93'];
            else $form['method_import_form_data']['wt_iew_local_file'] = $name === 'Draft input mapping' ? '' : 'https://target.test/webtoffee_import/target-input.csv';
            $native['tables']['wt_iew_mapping_template'][] = ['id' => (string) (201 + count($native['tables']['wt_iew_mapping_template'])),
                'template_type' => $type, 'item_type' => 'user', 'name' => $name, 'data' => json_encode($form, JSON_THROW_ON_ERROR)];
        }
    }
    foreach ([['Import', 'user'], ['export', 'product']] as $index => [$type, $item]) $native['tables']['wt_iew_mapping_template'][] = [
        'id' => (string) (301 + $index), 'template_type' => $type, 'item_type' => $item, 'name' => 'local', 'data' => '{broken'];
    return $native;
}
