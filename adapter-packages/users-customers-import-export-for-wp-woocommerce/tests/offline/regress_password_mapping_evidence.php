<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
require_once "$root/sandbox/tests/lib/check.php";
require_once "$root/agent/src/Kernel/PrivateRefusalEvidence.php";
require_once "$capsule/fixtures/password-mapping-evidence.php";

use WPrism\PrivateRefusalEvidence;

$id = 47;
$form = json_decode(file_get_contents($capsule . '/fixtures/native-import-templates.json'), true, 32, JSON_THROW_ON_ERROR)['form'];
$form['method_import_form_data']['wt_iew_local_file'] = 'https://source.test/wp-content/webtoffee_import/source-input.csv';
$form['mapping_form_data']['mapping_fields']['user_pass'] = ['', 0];
unset($form['mapping_form_data']['mapping_selected_fields']['user_pass']);
$row = ['id' => (string) $id, 'template_type' => 'import', 'item_type' => 'user',
    'name' => 'Generated password mapping', 'data' => json_encode($form, JSON_THROW_ON_ERROR)];
$seed = ['phase' => 'seed-passwordless', 'id' => $id, 'row' => $row];
$tables = array_fill_keys(['posts', 'postmeta', 'options', 'terms', 'term_taxonomy', 'term_relationships',
    'termmeta', 'users', 'usermeta', 'wt_iew_action_history', 'wt_iew_mapping_template'], []);
$tables['users'] = [['ID' => '1', 'user_login' => 'admin']];
$before = ['format' => 'wprism-importer-native-settings/v1', 'tables' => $tables,
    'settings' => ['wt_iew_default_import_batch' => 10], 'files' => ['import/source-input.csv' => str_repeat('a', 64)]];
$seeded = $before;
$seeded['tables']['wt_iew_mapping_template'][] = $row;
$public = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'capture',
    'error' => 'capture_failed', 'reason_code' => 'capture_failed',
    'message' => 'capture refused at an unclassified safety gate', 'details_redacted' => true,
    'diagnostics' => [['code' => 'capture_failed', 'message' => 'capture refused at an unclassified safety gate']]];
$baseline = ['command' => 'capture', 'baseline' => '[]'];
$message = "wprism: table 'wt_iew_mapping_template' column 'data' (row $id).mapping_form_data.mapping_fields requires nonempty field template 'user_pass'";
$record = ['format' => 'wprism-private-refusal-evidence/v2', 'command' => 'capture', 'reason_code' => 'capture_failed',
    ...PrivateRefusalEvidence::graph(new RuntimeException($message))];
$recordBytes = json_encode($record, JSON_THROW_ON_ERROR);
$name = '20260916-030000-capture-' . str_repeat('a', 24) . '.json';
$diagnostic = ['command' => 'capture', 'format' => 'wprism-private-refusal-diagnostic/v1', 'new_records' => 1,
    'purpose' => 'diagnostic_only', 'records' => [['bytes' => strlen($recordBytes), 'contents_base64' => base64_encode($recordBytes),
        'name' => $name, 'sha256' => hash('sha256', $recordBytes)]], 'verified' => false];
ImporterPasswordMappingEvidence::verify($before, $seed, $seeded, $public, $baseline, $diagnostic, $seeded, $before);
wprism_check(true, 'native unsupported mapping, exact refusal and restoration are admitted');

foreach (['seed-phase', 'seed-id', 'seed-type', 'seed-item', 'seed-name', 'seed-enabled', 'seed-selected',
    'missing-table', 'seeded-extra', 'refusal-mutation', 'cleanup-drift', 'public-reason', 'public-redaction',
    'stale-private', 'private-cause'] as $fault) {
    $badBefore = $before;
    $badSeed = $seed;
    $badSeeded = $seeded;
    $badPublic = $public;
    $badBaseline = $baseline;
    $badDiagnostic = $diagnostic;
    $badAfter = $seeded;
    $badCleaned = $before;
    switch ($fault) {
        case 'seed-phase': $badSeed['phase'] = 'consume'; break;
        case 'seed-id': $badSeed['id'] = 0; break;
        case 'seed-type': $badSeed['row']['template_type'] = 'export'; break;
        case 'seed-item': $badSeed['row']['item_type'] = 'product'; break;
        case 'seed-name': $badSeed['row']['name'] = 'Other'; break;
        case 'seed-enabled':
            $changed = $form;
            $changed['mapping_form_data']['mapping_fields']['user_pass'] = ['{Password}', 1];
            $badSeed['row']['data'] = json_encode($changed, JSON_THROW_ON_ERROR);
            break;
        case 'seed-selected':
            $changed = $form;
            $changed['mapping_form_data']['mapping_selected_fields']['user_pass'] = '{Password}';
            $badSeed['row']['data'] = json_encode($changed, JSON_THROW_ON_ERROR);
            break;
        case 'missing-table': unset($badBefore['tables']['usermeta']); break;
        case 'seeded-extra': $badSeeded['tables']['options'][] = ['option_name' => 'changed']; break;
        case 'refusal-mutation': $badAfter['files'] = []; break;
        case 'cleanup-drift': $badCleaned['tables']['users'] = []; break;
        case 'public-reason': $badPublic['reason_code'] = 'unrelated'; break;
        case 'public-redaction': unset($badPublic['details_redacted']); break;
        case 'stale-private': $badBaseline['baseline'] = json_encode([$name], JSON_THROW_ON_ERROR); break;
        case 'private-cause':
            $changedRecord = ['format' => 'wprism-private-refusal-evidence/v2', 'command' => 'capture',
                'reason_code' => 'capture_failed', ...PrivateRefusalEvidence::graph(new RuntimeException('unrelated'))];
            $changedBytes = json_encode($changedRecord, JSON_THROW_ON_ERROR);
            $badDiagnostic['records'][0]['bytes'] = strlen($changedBytes);
            $badDiagnostic['records'][0]['contents_base64'] = base64_encode($changedBytes);
            $badDiagnostic['records'][0]['sha256'] = hash('sha256', $changedBytes);
            break;
    }
    wprism_check_throws(static fn() => ImporterPasswordMappingEvidence::verify(
        $badBefore, $badSeed, $badSeeded, $badPublic, $badBaseline, $badDiagnostic, $badAfter, $badCleaned
    ), RuntimeException::class, 'password-mapping evidence rejects ' . $fault);
}
wprism_check_summary('importer required password mapping evidence');
