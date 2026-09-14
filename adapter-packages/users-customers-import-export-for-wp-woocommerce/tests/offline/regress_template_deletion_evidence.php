<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';
require_once dirname(__DIR__, 2) . '/fixtures/template-deletion-evidence.php';

$forms = [];
foreach (['import', 'export'] as $type) $forms[$type] = json_decode(file_get_contents(dirname(__DIR__, 2)
    . '/fixtures/native-' . $type . '-templates.json'), true, 32, JSON_THROW_ON_ERROR)['form'];
$rows = [];
foreach ([['export', 'Selected users'], ['import', 'Selected users'], ['import', 'Draft input mapping'],
    ['export', 'Selected users copy'], ['import', 'Reusable input copy'], ['Import', 'Selected users'], ['export', 'Local product']] as $index => [$type, $name]) {
    $rows[] = ['id' => (string) ($index + 1), 'template_type' => $type, 'item_type' => $index === 6 ? 'product' : 'user',
        'name' => $name, 'data' => json_encode($forms[strtolower($type)], JSON_THROW_ON_ERROR)];
}
$before = ['format' => 'wprism-importer-native-settings/v1', 'tables' => array_fill_keys(['posts', 'postmeta', 'options', 'terms',
    'term_taxonomy', 'term_relationships', 'termmeta', 'users', 'usermeta', 'wt_iew_action_history', 'wt_iew_mapping_template'], []),
    'files' => array_fill_keys(['import/input.csv', 'export/first.csv', 'export/second.csv', 'import/.htaccess', 'export/.htaccess'], str_repeat('a', 64))];
$before['tables']['wt_iew_mapping_template'] = $rows;
$before['tables']['users'] = array_map(static fn(int $id): array => ['ID' => (string) $id], range(1, 4));
$before['tables']['wt_iew_action_history'] = [['id' => '1', 'data' => json_encode($forms['export'])], ['id' => '2', 'data' => json_encode($forms['export'])]];
$after = $before;
$after['tables']['wt_iew_mapping_template'] = array_slice($rows, 3);
ImporterTemplateDeletionEvidence::removed($before, $after);
wprism_check(true, 'native oracle admits only the exact three-row removal');
foreach (['missing-table', 'missing-witness', 'remaining-original', 'copy-lost', 'case-row-lost', 'product-row-lost',
    'user-changed', 'history-changed', 'file-changed', 'option-added'] as $fault) {
    $badBefore = $before;
    $badAfter = $after;
    switch ($fault) {
        case 'missing-table': unset($badBefore['tables']['usermeta'], $badAfter['tables']['usermeta']); break;
        case 'missing-witness': $badBefore['files'] = $badAfter['files'] = []; break;
        case 'remaining-original': $badAfter['tables']['wt_iew_mapping_template'][] = $rows[0]; break;
        case 'copy-lost': unset($badAfter['tables']['wt_iew_mapping_template'][0]); break;
        case 'case-row-lost': unset($badAfter['tables']['wt_iew_mapping_template'][2]); break;
        case 'product-row-lost': unset($badAfter['tables']['wt_iew_mapping_template'][3]); break;
        case 'user-changed': $badAfter['tables']['users'][0]['ID'] = '42'; break;
        case 'history-changed': $badAfter['tables']['wt_iew_action_history'][0]['data'] = '{}'; break;
        case 'file-changed': $badAfter['files']['import/input.csv'] = str_repeat('b', 64); break;
        case 'option-added': $badAfter['tables']['options'][] = ['option_name' => 'unexpected']; break;
    }
    wprism_check_throws(static fn() => ImporterTemplateDeletionEvidence::removed($badBefore, $badAfter), RuntimeException::class,
        'native deletion oracle rejects ' . $fault);
}
$export = ['id' => 4, 'selected' => ['4'], 'form' => $forms['export']];
$import = ['id' => 5, 'selected' => ['5'], 'form' => $forms['import']];
$history = ['status' => 1, 'template_data' => $forms['export']];
ImporterTemplateDeletionEvidence::reopened($before, $export, $import, $history, 2);
wprism_check(true, 'native copies and history each return the complete saved form');
foreach (['wrong-copy-id', 'old-cursor-selected', 'copy-form-lost', 'history-form-lost', 'history-failed', 'wrong-history'] as $fault) {
    $badExport = $export;
    $badImport = $import;
    $badHistory = $history;
    $historyId = 2;
    switch ($fault) {
        case 'wrong-copy-id': $badExport['id'] = 1; break;
        case 'old-cursor-selected': $badImport['selected'] = ['2']; break;
        case 'copy-form-lost': $badExport['form'] = []; break;
        case 'history-form-lost': $badHistory['template_data'] = []; break;
        case 'history-failed': $badHistory['status'] = 0; break;
        case 'wrong-history': $historyId = 99; break;
    }
    wprism_check_throws(static fn() => ImporterTemplateDeletionEvidence::reopened($before, $badExport, $badImport, $badHistory, $historyId),
        RuntimeException::class, 'native independence oracle rejects ' . $fault);
}
wprism_check_summary('Importer template deletion evidence');
