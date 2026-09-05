<?php
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/sandbox/tests/lib/check.php';

// The live fixture previously expected force-theirs to resurrect destructively
// lost mapped rows. The engine's pre-prune guard already refuses that operation
// (regress_scoped_apply_recovery.php); this capsule pin prevents its evidence
// from crediting a derived-state provider with database/identity recovery.
$source = (string) file_get_contents(dirname(__DIR__) . '/conformance/check.sh');
$start = strpos($source, 'COMPLETE_BASE_PLAN');
$lifecycle = $start === false ? '' : substr($source, $start);
wprism_check($lifecycle !== '', 'destructive lifecycle has an independently checked clean baseline');

$previous = -1;
foreach ([
    'COMPLETE_PREIMAGE=$(observe_code_snippets conf2)',
    'db export /siterepo/.tmp-code-snippets-complete-uninstall.sql --add-drop-table',
    'update_setting("general", "enable_flat_files", true)',
    'plugin uninstall code-snippets --deactivate',
    'capture_wprism_json_refusal COMPLETE_LOST_PLAN ',
    'capture_wprism_json_refusal COMPLETE_LOST_APPLY ',
    'db import /siterepo/.tmp-code-snippets-complete-uninstall.sql',
    '"$(code_snippets_recovery_hash)" = "$COMPLETE_PREIMAGE_HASH"',
    '"$(observe_code_snippets conf2)" = "$COMPLETE_PREIMAGE"',
    'capture_wprism_json_success COMPLETE_RESTORED_APPLY ',
    'save_runtime_profile conf1 complete-uninstall-recovery',
    'capture_wprism_json_success COMPLETE_APPLY ',
    'capture_wprism_json_success COMPLETE_RETRY ',
] as $step) {
    $position = strpos($lifecycle, $step);
    wprism_check(
        $position !== false && $position > $previous,
        'destructive lifecycle orders ' . $step . ' after its prerequisite'
    );
    if ($position !== false) {
        $previous = $position;
    }
}
wprism_check(
    str_contains($lifecycle, '.raw_count == 3 and .flat_enabled == false and .flat_tree == {}'),
    'database backup starts from a disabled empty flat-tree preimage it can actually recover'
);
wprism_check(
    substr_count($lifecycle, '.reason_code == "canonical_identity_recovery_required"') === 2
        && str_contains($lifecycle, '.command == "plan"')
        && str_contains($lifecycle, '.command == "apply"'),
    'both commands require the exact canonical identity refusal after captured nonzero exits'
);
wprism_check(
    substr_count($lifecycle, '"$(code_snippets_recovery_hash)" = "$COMPLETE_REFUSAL_HASH"') === 2,
    'each destructive-loss refusal proves retained identities and native storage unchanged'
);
wprism_check(
    preg_match(
        '/capture_wprism_json_refusal COMPLETE_LOST_APPLY[^\n]*\n\s*wp_conf2 wprism apply[^\n]*--force-theirs/',
        $lifecycle
    ) === 1,
    'destructive-loss apply explicitly proves force-theirs grants no identity replacement authority'
);
$restored = strpos($lifecycle, 'save_runtime_profile conf1 complete-uninstall-recovery');
$newIntent = $restored === false ? '' : substr($lifecycle, $restored);
wprism_check(
    $newIntent !== '' && !str_contains($newIntent, '--force-theirs')
        && str_contains($newIntent, '.actions[0].verified == true and .actions[0].after.row_count == 3'),
    'post-restore intent uses ordinary apply and verifies the existing-row provider receipt'
);
foreach ([
    'SELECT uuid,entity_type,id_kind,local_id',
    'SELECT uuid,entity_type,content_hash',
    'SELECT k,v',
    'SELECT * FROM {$wpdb->prefix}snippets ORDER BY id',
    'SELECT option_name,option_value,autoload',
] as $observation) {
    wprism_check(
        str_contains($source, $observation),
        'recovery evidence includes ' . $observation
    );
}
wprism_check_summary('Code Snippets destructive lifecycle evidence');
