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
$selectedIds = array_map('intval', array_column(ImporterTemplateDeletionEvidence::selected($rows), 'id'));
sort($selectedIds, SORT_NUMERIC);
$map = array_map(
    static fn(int $id): array => ['uuid' => sprintf('00000000-0000-5000-8000-%012d', $id),
        'entity_type' => 'wt_iew_mapping_template', 'id_kind' => 'iew_template', 'local_id' => (string) $id],
    $selectedIds
);
usort($map, static fn(array $left, array $right): int => $left['uuid'] <=> $right['uuid']);
$ledger = ['format' => 'wprism-importer-template-ledger/v1', 'map' => $map,
    'state' => array_map(static fn(array $row): array => ['uuid' => $row['uuid'],
        'entity_type' => 'wt_iew_mapping_template', 'content_hash' => hash('sha256', $row['uuid'])], $map)];
ImporterTemplateDeletionEvidence::ledgerPreimage($ledger, $ledger, $before);
wprism_check(true, 'exact target map and state preimage survives fixture restoration');
foreach (['missing-map', 'wrong-local', 'wrong-type', 'wrong-kind', 'duplicate-uuid', 'missing-state',
    'wrong-state-type', 'wrong-state-hash', 'changed-postimage'] as $fault) {
    $badBefore = $ledger;
    $badAfter = $ledger;
    if ($fault === 'missing-map') array_pop($badBefore['map']);
    if ($fault === 'wrong-local') $badBefore['map'][0]['local_id'] = '99';
    if ($fault === 'wrong-type') $badBefore['map'][0]['entity_type'] = 'post';
    if ($fault === 'wrong-kind') $badBefore['map'][0]['id_kind'] = 'post';
    if ($fault === 'duplicate-uuid') $badBefore['map'][1]['uuid'] = $badBefore['map'][0]['uuid'];
    if ($fault === 'missing-state') array_pop($badBefore['state']);
    if ($fault === 'wrong-state-type') $badBefore['state'][0]['entity_type'] = 'post';
    if ($fault === 'wrong-state-hash') $badBefore['state'][0]['content_hash'] = 'not-a-hash';
    if ($fault === 'changed-postimage') $badAfter['state'][0]['content_hash'] = str_repeat('f', 64);
    wprism_check_throws(static fn() => ImporterTemplateDeletionEvidence::ledgerPreimage($badBefore, $badAfter, $before),
        RuntimeException::class, 'target ledger preimage oracle rejects ' . $fault);
}
$deletePlan = ['delete' => array_map(static fn(array $row): array => ['uuid' => $row['uuid'],
    'receipt_hash' => hash('sha256', 'delete:' . $row['uuid'])], $map)];
$terminalLedger = ['format' => 'wprism-importer-template-ledger/v1', 'map' => [],
    'state' => array_map(static fn(array $row): array => ['uuid' => $row['uuid'], 'entity_type' => 'deletion',
        'content_hash' => hash('sha256', 'delete:' . $row['uuid'])], $map)];
ImporterTemplateDeletionEvidence::ledgerTerminal($ledger, $terminalLedger, $deletePlan);
wprism_check(true, 'terminal ledger removes selected maps and binds exact deletion receipts');
foreach (['retained-map', 'missing-state', 'wrong-state-type', 'wrong-state-hash', 'wrong-plan-receipt', 'extra-plan-row'] as $fault) {
    $badTerminal = $terminalLedger;
    $badPlan = $deletePlan;
    if ($fault === 'retained-map') $badTerminal['map'][] = $map[0];
    if ($fault === 'missing-state') array_pop($badTerminal['state']);
    if ($fault === 'wrong-state-type') $badTerminal['state'][0]['entity_type'] = 'wt_iew_mapping_template';
    if ($fault === 'wrong-state-hash') $badTerminal['state'][0]['content_hash'] = str_repeat('f', 64);
    if ($fault === 'wrong-plan-receipt') $badPlan['delete'][0]['receipt_hash'] = str_repeat('e', 64);
    if ($fault === 'extra-plan-row') $badPlan['delete'][] = $badPlan['delete'][0];
    wprism_check_throws(static fn() => ImporterTemplateDeletionEvidence::ledgerTerminal($ledger, $badTerminal, $badPlan),
        RuntimeException::class, 'terminal ledger oracle rejects ' . $fault);
}
$warning = <<<'WARNING'
users-customers-import-export-for-wp-woocommerce/users-customers-import-export-for-wp-woocommerce.php 2.7.5 is active on this environment but absent from the existing WPrism code-version baseline. Its activation or first version change therefore cannot be distinguished from an out-of-band update. Run 'wprism deploy' to reconcile and record the installed bytes, or remove the undeclared activation before capture/apply. — 'wprism capture' observed this and did NOT accept it as the new baseline: capture reports what it sees, it does not reconcile code. The recorded versions are unchanged, so this finding is still there on the next 'wprism status'.
WARNING;
$agent = ['warnings' => [$warning], 'notes' => [], 'counts' => ['wt_iew_mapping_template' => 5], 'revision_hash' => str_repeat('a', 64)];
$host = ['format' => 'wprism-capture-result/v1', 'environment' => 'target', 'branch' => 'wprism-live-evidence',
    'capture' => ['warnings_count' => 1, 'notes_count' => 0, 'counts' => $agent['counts'], 'state_revision' => $agent['revision_hash']]];
ImporterTemplateDeletionEvidence::initialCapture($agent, $host);
wprism_check(true, 'initial native activation has exactly the independently observed drift warning');
foreach (['other-warning', 'extra-warning', 'warning-hidden', 'wrong-revision', 'wrong-counts'] as $fault) {
    $badAgent = $agent;
    $badHost = $host;
    if ($fault === 'other-warning') $badAgent['warnings'] = ['unrelated lint warning'];
    if ($fault === 'extra-warning') $badAgent['warnings'][] = 'extra warning';
    if ($fault === 'warning-hidden') $badHost['capture']['warnings_count'] = 0;
    if ($fault === 'wrong-revision') $badHost['capture']['state_revision'] = str_repeat('b', 64);
    if ($fault === 'wrong-counts') $badHost['capture']['counts']['wt_iew_mapping_template'] = 4;
    wprism_check_throws(static fn() => ImporterTemplateDeletionEvidence::initialCapture($badAgent, $badHost), RuntimeException::class,
        'initial capture evidence rejects ' . $fault);
}
$deploy = ['lifecycle_phase' => 'all', 'code_mismatch' => [], 'code_drift' => [[
    'issue' => 'code_baseline_missing', 'plugin' => 'users-customers-import-export-for-wp-woocommerce/users-customers-import-export-for-wp-woocommerce.php',
    'installed_version' => '2.7.5', 'recorded_version' => '']],
    'warnings' => ['FORCED past code_drift: ' . strstr($warning, " — 'wprism capture' observed this", true)]];
ImporterTemplateDeletionEvidence::reconcile($agent, $deploy);
wprism_check(true, 'fixture reconciliation admits only its explicit one-plugin baseline acceptance');
foreach (['extra-drift', 'other-owner', 'old-version', 'mismatch', 'extra-warning', 'wrong-phase'] as $fault) {
    $bad = $deploy;
    if ($fault === 'extra-drift') $bad['code_drift'][] = $bad['code_drift'][0];
    if ($fault === 'other-owner') $bad['code_drift'][0]['plugin'] = 'other/plugin.php';
    if ($fault === 'old-version') $bad['code_drift'][0]['installed_version'] = '2.7.4';
    if ($fault === 'mismatch') $bad['code_mismatch'] = [['issue' => 'missing_in_code']];
    if ($fault === 'extra-warning') $bad['warnings'][] = 'unrelated';
    if ($fault === 'wrong-phase') $bad['lifecycle_phase'] = 'retire';
    wprism_check_throws(static fn() => ImporterTemplateDeletionEvidence::reconcile($agent, $bad), RuntimeException::class,
        'fixture reconciliation rejects ' . $fault);
}
$answer = ['format' => 'wprism-command-refusal/v1', 'ok' => false, 'command' => 'apply',
    'error' => 'deletion_writer_exclusion_required', 'reason_code' => 'deletion_writer_exclusion_required',
    'message' => 'deletion requires a signed recovery promotion whose external exclusion blocks all target writers'];
ImporterTemplateDeletionEvidence::refusal($answer, 'direct');
wprism_check(true, 'exact public direct deletion refusal admitted');
foreach (['command' => 'plan', 'reason_code' => 'unrelated_failure', 'message' => 'unknown failure', 'ok' => true,
    'details_redacted' => true] as $field => $value) {
    $bad = array_replace($answer, [$field => $value]);
    wprism_check_throws(static fn() => ImporterTemplateDeletionEvidence::refusal($bad, 'direct'), RuntimeException::class,
        'direct deletion refusal rejects unrelated ' . $field);
}
require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/ShellProbe.php';
$root = dirname(__DIR__, 4);
$capsule = dirname(__DIR__, 2);
$source = file_get_contents($capsule . '/tests/live/regress_template_deletion_ssh.sh');
$start = strpos($source, 'importer_delete_record()');
$end = strpos($source, 'importer_delete_native()', $start);
if ($start === false || $end === false) throw new RuntimeException('native deletion capture function boundaries changed');
$probe = <<<'SH'
set -euo pipefail
ROOT="$1" PACKAGE_ROOT="$2"
fail() { printf '%s\n' "$*" >&2; exit 1; }
pass() { printf 'ok: %s\n' "$*"; }
. "$ROOT/sandbox/tests/lib/private_command_capture.sh"
DIAG_DIR=$(umask 077; mktemp -d)
trap 'rm -rf -- "$DIAG_DIR"' EXIT
command_stdout="$3" command_stderr="$4" command_exit="$5" command_kind="$6"
fixture_command() { printf '%s' "$command_stdout"; printf '%s' "$command_stderr" >&2; return "$command_exit"; }
SH;
$probe .= "\n" . substr($source, $start, $end - $start) . "\nimporter_delete_capture probe \"$" . "command_kind\" fixture_command\n";
foreach (['json', 'direct'] as $kind) foreach (['valid', 'wrong-exit', 'stderr', 'malformed'] as $fault) {
    $stdout = $kind === 'direct' ? json_encode($answer, JSON_THROW_ON_ERROR) : '{"status":true}';
    $stderr = $fault === 'stderr' ? 'PHP Warning: fixture diagnostic' : '';
    $exit = $kind === 'direct' ? 1 : 0;
    if ($fault === 'wrong-exit') $exit = 2;
    if ($fault === 'malformed') $stdout = 'PHP Warning: fixture diagnostic' . $stdout;
    [$status, $out] = WPrismTest\ShellProbe::run($probe, [$root, $capsule, $stdout, $stderr, (string) $exit, $kind], $root);
    wprism_check_same($fault === 'valid', $status === 0, 'actual deletion capture admits exact ' . $kind . ' streams and exit: ' . $fault);
    if ($fault === 'valid') wprism_check(str_contains($out, 'ok: importer-delete-probe'), 'actual deletion capture reaches positive admission');
}
$planCapture = strpos($source, 'importer_delete_capture plan json');
$planStart = strpos($source, "  jq -e --slurpfile repo ", $planCapture);
$planEnd = strpos($source, "  importer_delete_capture direct-baseline", $planStart);
if ($planCapture === false || $planStart === false || $planEnd === false) throw new RuntimeException('native deletion plan assertion boundaries changed');
$planProbe = <<<'SH'
set -euo pipefail
fail() { exit 1; }
DIAG_DIR=$(umask 077; mktemp -d)
trap 'rm -rf -- "$DIAG_DIR"' EXIT
printf '%s' "$1" > "$DIAG_DIR/importer-delete-plan.stdout"
printf '%s' "$2" > "$DIAG_DIR/importer-delete-deletion-repository.stdout"
SH;
$planProbe .= "\n" . substr($source, $planStart, $planEnd - $planStart);
$plan = array_fill_keys(['create', 'update', 'adopt', 'drift', 'conflict', 'delete_conflict', 'code_mismatch', 'code_drift',
    'provider_problems', 'warnings', 'collision', 'incomplete_apply', 'incomplete_lifecycle', 'missing_user'], []);
$plan['adapter_dispositions'] = [];
$plan['delete'] = array_map(static fn(string $uuid): array => ['uuid' => $uuid, 'type' => 'wt_iew_mapping_template',
    'deletion_kind' => 'table', 'deletion_type' => 'wt_iew_mapping_template'], ['original-export', 'original-import', 'draft-import']);
$repository = ['deletions' => array_fill_keys(array_column($plan['delete'], 'uuid'), [])];
foreach (['valid', 'wrong-type', 'wrong-kind', 'extra-row', 'blocked-row', 'unexpected-disposition', 'warning', 'unrelated-update'] as $fault) {
    $bad = $plan;
    if ($fault === 'wrong-type') $bad['delete'][0]['type'] = 'table';
    if ($fault === 'wrong-kind') $bad['delete'][0]['deletion_kind'] = 'post';
    if ($fault === 'extra-row') $bad['delete'][] = array_replace($bad['delete'][0], ['uuid' => 'foreign']);
    if ($fault === 'blocked-row') $bad['delete'][0]['blocked'] = 'unreviewed owner';
    if ($fault === 'unexpected-disposition') $bad['adapter_dispositions'][] = ['code' => 'missing_dependency'];
    if ($fault === 'warning') $bad['warnings'][] = 'unexpected';
    if ($fault === 'unrelated-update') $bad['update'][] = ['uuid' => 'options/core'];
    [$status] = WPrismTest\ShellProbe::run($planProbe, [json_encode($bad, JSON_THROW_ON_ERROR), json_encode($repository, JSON_THROW_ON_ERROR)], $root);
    wprism_check_same($fault === 'valid', $status === 0, 'actual native plan assertion accepts only exact unblocked typed deletions: ' . $fault);
}
$fixedProbe = <<<'SH'
set -euo pipefail
fail() { exit 1; }
assert_wprism_required_environment() { :; }
DIAG_DIR=$(umask 077; mktemp -d)
trap 'rm -rf -- "$DIAG_DIR"' EXIT
. "$2/tests/live/regress_template_deletion_ssh.sh"
printf '%s' "$3" > "$DIAG_DIR/importer-delete-converged-plan.stdout"
printf '%s' "$4" > "$DIAG_DIR/importer-delete-deletion-repository.stdout"
importer_delete_assert_converged_plan converged-plan
printf 'ACCEPTED\n'
SH;
$fixedRepository = ['deletions' => []];
foreach (array_column($plan['delete'], 'uuid') as $uuid) {
    $fixedRepository['deletions'][$uuid] = ['hash' => hash('sha256', 'delete:' . $uuid)];
}
$fixed = array_fill_keys(['create', 'update', 'adopt', 'drift', 'conflict', 'collision', 'delete', 'delete_conflict',
    'code_mismatch', 'code_drift', 'provider_problems', 'warnings', 'incomplete_apply', 'incomplete_lifecycle',
    'missing_user', 'skipped_user_meta', 'adapter_dispositions', 'selected_actions', 'regen_pending', 'regen_context'], []);
$fixed['env_missing'] = [];
$fixed['deleted'] = array_map(static fn(array $row): array => ['uuid' => $row['uuid'],
    'type' => 'wt_iew_mapping_template', 'deletion_kind' => 'table', 'deletion_type' => 'wt_iew_mapping_template',
    'receipt_hash' => $fixedRepository['deletions'][$row['uuid']]['hash']], $plan['delete']);
$fixedFaults = ['valid', 'create', 'collision', 'code-mismatch', 'code-drift', 'provider-problem', 'warning',
    'incomplete-apply', 'incomplete-lifecycle', 'missing-user', 'skipped-user-meta', 'adapter-disposition',
    'selected-action', 'regen-pending', 'regen-context', 'required-env', 'missing-deleted', 'wrong-deleted-type',
    'wrong-deleted-receipt'];
foreach ($fixedFaults as $fault) {
    $bad = $fixed;
    if ($fault === 'create') $bad['create'][] = ['uuid' => 'unresolved'];
    if ($fault === 'collision') $bad['collision'][] = ['uuid' => 'unresolved'];
    if ($fault === 'code-mismatch') $bad['code_mismatch'][] = ['issue' => 'missing_in_code'];
    if ($fault === 'code-drift') $bad['code_drift'][] = ['issue' => 'version_drift'];
    if ($fault === 'provider-problem') $bad['provider_problems'][] = ['reason' => 'unavailable'];
    if ($fault === 'warning') $bad['warnings'][] = 'unresolved warning';
    if ($fault === 'incomplete-apply') $bad['incomplete_apply'][] = ['uuid' => 'unresolved'];
    if ($fault === 'incomplete-lifecycle') $bad['incomplete_lifecycle'][] = ['plugin' => 'unresolved'];
    if ($fault === 'missing-user') $bad['missing_user'][] = ['login' => 'missing'];
    if ($fault === 'skipped-user-meta') $bad['skipped_user_meta'][] = ['login' => 'missing'];
    if ($fault === 'adapter-disposition') $bad['adapter_dispositions'][] = ['adapter' => 'unreviewed'];
    if ($fault === 'selected-action') $bad['selected_actions'][] = ['id' => 'unresolved'];
    if ($fault === 'regen-pending') $bad['regen_pending'][] = ['uuid' => 'unresolved'];
    if ($fault === 'regen-context') $bad['regen_context'][] = ['uuid' => 'unresolved'];
    if ($fault === 'required-env') $bad['env_missing'][] = ['name' => 'required', 'required' => true];
    if ($fault === 'missing-deleted') array_pop($bad['deleted']);
    if ($fault === 'wrong-deleted-type') $bad['deleted'][0]['type'] = 'post';
    if ($fault === 'wrong-deleted-receipt') $bad['deleted'][0]['receipt_hash'] = str_repeat('f', 64);
    [$status, $out] = WPrismTest\ShellProbe::run($fixedProbe,
        [$root, $capsule, json_encode($bad, JSON_THROW_ON_ERROR), json_encode($fixedRepository, JSON_THROW_ON_ERROR)], $root);
    wprism_check_same($fault === 'valid', $status === 0 && str_contains($out, 'ACCEPTED'),
        'actual fixed-point assertion closes every action and diagnostic bucket: ' . $fault);
}
$milestones = [
    'wprism_ssh_enroll_full_recovery importer-deletion',
    'wprism_ssh_install_locked_plugin "$slug" 2.7.5 certified-boundary inactive',
    'wprism_ssh_stage_code_inventory "$slug"',
    'wprism_ssh_stage_generation_releases 3',
    'importer-delete-baseline-ledger',
    'importer_delete_assert ledger-preimage baseline-ledger restored-ledger baseline',
    'provider-state.json.fail-verify-after',
    'and .status.state == "rolled_back" and .status.terminal == true',
    'importer_delete_assert same baseline rollback-preserved',
    'importer_delete_assert same baseline-ledger rollback-ledger',
    'importer_delete_assert removed baseline signed-removed',
    'importer_delete_assert ledger-terminal baseline-ledger signed-ledger plan',
    'Importer signed deletion fixed point',
    'importer_delete_assert same signed-ledger repeat-ledger',
];
$cursor = -1;
foreach ($milestones as $milestone) {
    $position = strpos($source, $milestone);
    wprism_check(is_int($position) && $position > $cursor,
        'signed deletion retains ordered recovery, rollback, retry and fixed-point milestone: ' . $milestone);
    $cursor = $position;
}
wprism_check_same(3, substr_count($source, 'promote target --with-deletes'),
    'signed deletion exercises one failed promotion, one retry and one fixed-point repeat');
wprism_check_summary('Importer template deletion evidence');
