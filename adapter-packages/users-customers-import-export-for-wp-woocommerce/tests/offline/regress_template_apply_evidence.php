<?php
declare(strict_types=1);

$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
require_once "$root/sandbox/tests/lib/check.php";
require_once "$capsule/fixtures/template-apply-evidence.php";

$uuid = '14f8a3a9-6805-5848-a337-054c7019e229';
$receipt = [
    'applied' => 2,
    'canary' => 'clean',
    'drift' => [],
    'actions' => [],
    'warnings' => ["adopted env table row wt_iew_mapping_template:103 as $uuid (tables/wt_iew_mapping_template/$uuid--selected-users.json)"],
];
ImporterTemplateApplyEvidence::receipt($receipt, '103', 'selected-users');
wprism_check(true, 'full Apply admits its one exact table-adoption disclosure');
$clean = array_replace($receipt, ['warnings' => []]);
ImporterTemplateApplyEvidence::receipt($clean);
wprism_check(true, 'scoped non-adopting Apply requires an empty warning list');

$hash = str_repeat('a', 64);
$scopedReceipt = [
    'authority_hash' => $hash,
    'convergence_hash' => $hash,
    'intents_hash' => $hash,
    'lease_hash' => $hash,
    'phase' => 'complete',
    'protected_ledger_map_hash' => $hash,
    'receipts_hash' => $hash,
    'selected_ledger_map_hash' => $hash,
    'session_id' => 'ps-' . str_repeat('b', 32),
    'terminal_hash' => $hash,
];
$scopedApply = array_replace($clean, [
    'format' => 'wprism-scoped-apply-result/v1',
    'scoped_receipt' => $scopedReceipt,
]);
$replay = [
    'format' => 'wprism-scoped-apply-result/v1',
    'replayed' => true,
    'applied' => 0,
    'plan' => [],
    'drift' => [],
    'warnings' => [],
    'actions' => [],
    'canary' => 'clean',
    'verification' => null,
    'scoped_receipt' => $scopedReceipt,
];
ImporterTemplateApplyEvidence::replay($scopedApply, $replay);
wprism_check(true, 'same-request replay returns the exact terminal receipt without work');

$faults = [
    'missing-warning' => static fn(array &$bad) => $bad['warnings'] = [],
    'wrong-target' => static fn(array &$bad) => $bad['warnings'][0] = str_replace(':103 ', ':104 ', $bad['warnings'][0]),
    'wrong-slug' => static fn(array &$bad) => $bad['warnings'][0] = str_replace('selected-users', 'other', $bad['warnings'][0]),
    'wrong-path-uuid' => static fn(array &$bad) => $bad['warnings'][0] = str_replace('/14f8a3a9-', '/24f8a3a9-', $bad['warnings'][0]),
    'extra-warning' => static fn(array &$bad) => $bad['warnings'][] = $bad['warnings'][0],
    'runtime-warning' => static fn(array &$bad) => $bad['warnings'][0] = 'PHP Warning: hidden native failure',
    'action' => static fn(array &$bad) => $bad['actions'] = [['id' => 'unexpected']],
    'drift' => static fn(array &$bad) => $bad['drift'] = [['uuid' => 'unexpected']],
    'canary' => static fn(array &$bad) => $bad['canary'] = 'dirty',
    'zero-write' => static fn(array &$bad) => $bad['applied'] = 0,
    'string-write-count' => static fn(array &$bad) => $bad['applied'] = '1',
    'fractional-write-count' => static fn(array &$bad) => $bad['applied'] = 1.5,
    'boolean-write-count' => static fn(array &$bad) => $bad['applied'] = true,
];
foreach ($faults as $name => $mutate) {
    $bad = $receipt;
    $mutate($bad);
    wprism_check_throws(static fn() => ImporterTemplateApplyEvidence::receipt($bad, '103', 'selected-users'), RuntimeException::class,
        'full Apply receipt rejects ' . $name);
}
$unexpected = $clean;
$unexpected['warnings'] = $receipt['warnings'];
wprism_check_throws(static fn() => ImporterTemplateApplyEvidence::receipt($unexpected), RuntimeException::class,
    'non-adopting Apply refuses an adoption disclosure');
$replayFaults = [
    'write' => static fn(array &$bad) => $bad['applied'] = 1,
    'plan' => static fn(array &$bad) => $bad['plan'] = ['update' => 1],
    'drift' => static fn(array &$bad) => $bad['drift'] = [['uuid' => 'unexpected']],
    'warning' => static fn(array &$bad) => $bad['warnings'] = ['unexpected'],
    'action' => static fn(array &$bad) => $bad['actions'] = [['id' => 'unexpected']],
    'canary' => static fn(array &$bad) => $bad['canary'] = 'dirty',
    'verification' => static fn(array &$bad) => $bad['verification'] = [],
    'not-replayed' => static fn(array &$bad) => $bad['replayed'] = false,
    'wrong-format' => static fn(array &$bad) => $bad['format'] = 'other',
    'new-session' => static fn(array &$bad) => $bad['scoped_receipt']['session_id'] = 'ps-' . str_repeat('c', 32),
    'nonterminal' => static fn(array &$bad) => $bad['scoped_receipt']['phase'] = 'applying',
];
foreach ($replayFaults as $name => $mutate) {
    $bad = $replay;
    $mutate($bad);
    wprism_check_throws(static fn() => ImporterTemplateApplyEvidence::replay($scopedApply, $bad), RuntimeException::class,
        'same-request replay rejects ' . $name);
}
wprism_check_summary('importer template Apply evidence');
