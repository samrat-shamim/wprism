<?php
declare(strict_types=1);

/**
 * Offline source contract for the SSH adoption/scoped-promotion live harness.
 *
 * The deliberate post-begin promote fault is the one live failure where the
 * exact pre-promote scoped refresh/plan plus failure and success promote
 * stdout/stderr are essential to distinguish an engine regression from a
 * fixture or transport problem. They used to live only in transient values
 * inside a secret-bearing scratch tree that the EXIT trap always deleted. This
 * check proves the repair keeps a separate private, bounded evidence directory
 * while still destroying SSH keys, environment config, and DB credentials on
 * every exit. It reads source only: no Docker, SSH host, WordPress, or live
 * target.
 */

$root = dirname(__DIR__, 4);
$harnessPath = $root . '/sandbox/tests/live/regress_ssh_adopt.sh';
$harness = file_get_contents($harnessPath);
if ($harness === false) {
    fwrite(STDERR, "FAIL: could not read SSH-adoption harness: $harnessPath\n");
    exit(1);
}

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "ok: $message\n";
        return;
    }
    $failures++;
    fwrite(STDERR, "FAIL: $message\n");
};

$cleanupStart = strpos($harness, "cleanup() {\n");
$cleanupEnd = strpos($harness, "for command in git docker lsof; do\n");
$check(
    is_int($cleanupStart) && is_int($cleanupEnd) && $cleanupStart < $cleanupEnd,
    'the harness defines bounded EXIT cleanup before live preflight'
);
if (!is_int($cleanupStart) || !is_int($cleanupEnd) || $cleanupStart >= $cleanupEnd) {
    exit(1);
}
$cleanup = substr($harness, $cleanupStart, $cleanupEnd - $cleanupStart);

$tmpMkdir = 'TMP="$(mktemp -d "${TMPDIR:-/tmp}/${PREFIX}-ssh-adopt.XXXXXX")"';
$trap = 'trap cleanup EXIT';
$diagMkdir = 'DIAG_DIR="$(mktemp -d "${TMPDIR:-/tmp}/${PREFIX}-ssh-adopt-diagnostics.XXXXXX")"';
$tmpAt = strpos($harness, $tmpMkdir);
$trapAt = strpos($harness, $trap);
$diagAt = strpos($harness, $diagMkdir);
$check(
    is_int($tmpAt) && is_int($trapAt) && is_int($diagAt)
        && $tmpAt < $trapAt && $trapAt < $diagAt
        && !str_contains($diagMkdir, '$TMP/'),
    'secret scratch cleanup is armed before a sibling diagnostic mktemp directory is allocated'
);
$check(
    str_contains($harness, 'chmod 0700 "$DIAG_DIR"'),
    'the retained diagnostic directory is explicitly mode 0700'
);

// The scoped-promotion leg used to STAGE its own capability evidence: a
// hermetic library was manufactured under $TMP, its capabilities/ directory
// tarred, scp'd to the target, and unpacked over the adopted one, because the
// generated attestation was expired on any tree that had not imported current
// subject records and promotion refused before it could exercise anything.
// There is no attestation to re-seal, so the staging is gone — and its absence
// is asserted, not merely unmentioned: a harness that still pushed a
// capabilities/ directory onto the target would be replacing the very library
// the gate reads, which is exactly the bypass this check has always existed to
// forbid. What remains is a read-only premise check on the library `wprism adopt`
// itself installed.
$scopedSay = 'say "the adopted target carries the reviewed embedded adapter library it will be gated on"';
$scopedAt = strpos($harness, $scopedSay);
$promoteLegAt = strpos($harness, 'say "exercise a real checkpointed SSH scoped promotion and its recovery boundary"');
$check(
    is_int($scopedAt) && is_int($promoteLegAt) && $scopedAt < $promoteLegAt
        && str_contains($harness, '($p["format"]??null)!=="wprism-platform-boundary/v1"')
        && str_contains($harness, 'count($v["evidence"]["tests"]??[])<1')
        && !str_contains($harness, 'HERMETIC_')
        && !str_contains($harness, 'certification_fixture.php')
        && !str_contains($harness, 'capability-registry.php')
        && !str_contains(substr($harness, $scopedAt), 'WPRISM_MANIFESTS_DIR='),
    'scoped SSH promotion verifies the reviewed library the target was ADOPTED with, and stages, imports, or '
    . 'redirects nothing to get there'
);
$check(
    !preg_match('/(scp|tar)[^\n]*capabilities/', $harness),
    'no capabilities/ directory is ever archived or copied onto the target — the library under the gate is the one '
    . 'adopt installed'
);

$diagnosticAssignments = [
    'SCOPED_PLAN_STDOUT="$DIAG_DIR/scoped-plan.stdout"',
    'SCOPED_PLAN_STDERR="$DIAG_DIR/scoped-plan.stderr"',
    'SCOPED_PLAN_EXIT="$DIAG_DIR/scoped-plan.exit"',
    'SCOPED_REFRESH_STDOUT="$DIAG_DIR/scoped-refresh.stdout"',
    'SCOPED_REFRESH_STDERR="$DIAG_DIR/scoped-refresh.stderr"',
    'SCOPED_REFRESH_EXIT="$DIAG_DIR/scoped-refresh.exit"',
    'SCOPED_PROMOTE_STDOUT="$DIAG_DIR/scoped-promote.stdout"',
    'SCOPED_PROMOTE_STDERR="$DIAG_DIR/scoped-promote.stderr"',
    'SCOPED_PROMOTE_EXIT="$DIAG_DIR/scoped-promote.exit"',
    'SCOPED_SUCCESS_PROMOTE_STDOUT="$DIAG_DIR/scoped-success-promote.stdout"',
    'SCOPED_SUCCESS_PROMOTE_STDERR="$DIAG_DIR/scoped-success-promote.stderr"',
    'SCOPED_SUCCESS_PROMOTE_EXIT="$DIAG_DIR/scoped-success-promote.exit"',
    'AUTHORITY_STATUS_STDOUT="$DIAG_DIR/authority-status.stdout"',
    'AUTHORITY_STATUS_STDERR="$DIAG_DIR/authority-status.stderr"',
    'AUTHORITY_STATUS_EXIT="$DIAG_DIR/authority-status.exit"',
];
$check(
    array_reduce(
        $diagnosticAssignments,
        static fn(bool $ok, string $assignment): bool => $ok && str_contains($harness, $assignment),
        true
    )
        && str_contains($harness, 'for diagnostic_file in "$SCOPED_PLAN_STDOUT" "$SCOPED_PLAN_STDERR" "$SCOPED_PLAN_EXIT" "$SCOPED_REFRESH_STDOUT" "$SCOPED_REFRESH_STDERR" "$SCOPED_REFRESH_EXIT" "$SCOPED_PROMOTE_STDOUT" "$SCOPED_PROMOTE_STDERR" "$SCOPED_PROMOTE_EXIT" "$SCOPED_SUCCESS_PROMOTE_STDOUT" "$SCOPED_SUCCESS_PROMOTE_STDERR" "$SCOPED_SUCCESS_PROMOTE_EXIT" "$AUTHORITY_STATUS_STDOUT" "$AUTHORITY_STATUS_STDERR" "$AUTHORITY_STATUS_EXIT"; do')
        && str_contains($harness, '( umask 077; : >"$diagnostic_file" )')
        && str_contains($harness, 'chmod 0600 "$diagnostic_file"'),
    'only the bounded plan, promote, and authority-status streams and numeric exits are precreated mode 0600'
);
preg_match_all('/\$DIAG_DIR\/([A-Za-z0-9._-]+)/', $harness, $diagnosticNames);
$actualDiagnosticNames = array_values(array_unique($diagnosticNames[1] ?? []));
sort($actualDiagnosticNames);
$expectedDiagnosticNames = [
    'authority-status.exit',
    'authority-status.stderr',
    'authority-status.stdout',
    'scoped-plan.exit',
    'scoped-plan.stderr',
    'scoped-plan.stdout',
    'scoped-promote.exit',
    'scoped-promote.stderr',
    'scoped-promote.stdout',
    'scoped-refresh.exit',
    'scoped-refresh.stderr',
    'scoped-refresh.stdout',
    'scoped-success-promote.exit',
    'scoped-success-promote.stderr',
    'scoped-success-promote.stdout',
];
$check(
    $actualDiagnosticNames === $expectedDiagnosticNames,
    'the diagnostic directory receives no key, config, credential, or unrelated evidence file'
);

$failureScopeMint = '"$WPRISM" --envs-file="$TMP/envs.json" scope target --roots=options --contract >"$TMP/scoped-apply-failure-scope.json"';
$refreshStart = strpos($harness, 'if ssh_fixture \'cd /var/www/html && wp wprism refresh-export --repo=/home/wprism/site --scope-contract=/home/wprism/site/.scoped-apply-scope-chain.json --format=json\'');
$priorFailure = "ssh_fixture 'cd /var/www/html && wp option update scoped-apply_scoped_option prior-failure --autoload=no >/dev/null'";
$faultArm = "ssh_fixture 'touch /home/wprism/recovery-fixture/scoped-apply-fault-active'";
$planStart = strpos($harness, 'if "$WPRISM" --envs-file="$TMP/envs.json" plan target --scope-contract="$TMP/scoped-apply-failure-scope.json"');
$promoteStart = strpos($harness, 'if "$WPRISM" --envs-file="$TMP/envs.json" promote target --scope-contract="$TMP/scoped-apply-failure-scope.json"');
$scopeMintAt = strpos($harness, $failureScopeMint);
$priorFailureAt = strpos($harness, $priorFailure);
$faultArmAt = strpos($harness, $faultArm);
$check(
    is_int($scopeMintAt) && is_int($refreshStart) && is_int($priorFailureAt) && is_int($faultArmAt)
        && is_int($planStart) && is_int($promoteStart)
        && $scopeMintAt < $refreshStart && $refreshStart < $priorFailureAt && $priorFailureAt < $faultArmAt
        && $faultArmAt < $planStart && $planStart < $promoteStart,
    'the read-only scoped refresh and diagnostic plan run after scope minting and before promote'
);
$refreshEnd = $priorFailureAt;
if (is_int($refreshStart) && is_int($refreshEnd) && $refreshStart < $refreshEnd) {
    $refreshCapture = substr($harness, $refreshStart, $refreshEnd - $refreshStart);
    $scopeHashDerivation = <<<'SH'
FAILURE_SCOPE_HASH="$(jq -r '.scope_hash' "$TMP/scoped-apply-failure-scope.json")"
SH;
    $sourceIdentityProjection = <<<'SH'
jq -r '[(.live.roots // [])[], (.live.closure // [])[] | .entity] + [(.tombstones // [])[] | .uuid] | sort[]' \
  "$TMP/scoped-apply-failure-scope.json" >"$TMP/scoped-apply-failure-scope-identities"
SH;
    $targetIdentityProjection = <<<'SH'
jq -r '(.scope.selected_identities // [])[]' "$SCOPED_REFRESH_STDOUT" | LC_ALL=C sort >"$TMP/scoped-apply-refresh-identities"
SH;
    $identityEqualityGate = 'diff -u "$TMP/scoped-apply-failure-scope-identities" "$TMP/scoped-apply-refresh-identities" >/dev/null';
    $check(
        str_contains($refreshCapture, 'wp wprism refresh-export --repo=/home/wprism/site --scope-contract=/home/wprism/site/.scoped-apply-scope-chain.json --format=json')
            && str_contains($refreshCapture, '$SCOPED_REFRESH_STDOUT')
            && str_contains($refreshCapture, '$SCOPED_REFRESH_STDERR')
            && str_contains($refreshCapture, '$SCOPED_REFRESH_EXIT')
            && str_contains($refreshCapture, '.scope.scope_hash == $h')
            && str_contains($refreshCapture, 'scoped-apply-failure-scope-identities')
            && str_contains($refreshCapture, 'scoped-apply-refresh-identities')
            && str_contains($harness, $scopeHashDerivation)
            && str_contains($harness, $sourceIdentityProjection)
            && str_contains($refreshCapture, $targetIdentityProjection)
            && str_contains($refreshCapture, $identityEqualityGate),
        'scoped refresh-export records private streams and proves exact scope hash/selected-identity continuity before target mutation'
    );
}
if (!is_int($planStart) || !is_int($promoteStart) || $planStart >= $promoteStart) {
    exit(1);
}
$planCapture = substr($harness, $planStart, $promoteStart - $planStart);
$planRedirect = '"$WPRISM" --envs-file="$TMP/envs.json" plan target --scope-contract="$TMP/scoped-apply-failure-scope.json" --format=json >"$SCOPED_PLAN_STDOUT" 2>"$SCOPED_PLAN_STDERR"';
$planExit = 'printf \'%s\\n\' "$SCOPED_PLAN_CODE" >"$SCOPED_PLAN_EXIT"';
$check(
    str_contains($planCapture, $planRedirect)
        && str_contains($planCapture, $planExit)
        && str_contains($planCapture, '[ "$SCOPED_PLAN_CODE" -eq 0 ]')
        && !str_contains($planCapture, 'promote target')
        && !str_contains($planCapture, 'capture target')
        && !str_contains($planCapture, 'scope target'),
    'the retained pre-promote plan uses only the public read-only scoped plan command and records its streams/exit'
);

$faultStart = strpos($harness, 'if "$WPRISM" --envs-file="$TMP/envs.json" promote target --scope-contract="$TMP/scoped-apply-failure-scope.json"');
$faultEnd = strpos($harness, "ssh_fixture 'rm -f /home/wprism/recovery-fixture/scoped-apply-fault-active", is_int($faultStart) ? $faultStart : 0);
$check(
    is_int($faultStart) && is_int($faultEnd) && $faultStart < $faultEnd,
    'the controlled scoped-promote fault has a bounded capture/status segment'
);
if (!is_int($faultStart) || !is_int($faultEnd) || $faultStart >= $faultEnd) {
    exit(1);
}
$faultCapture = substr($harness, $faultStart, $faultEnd - $faultStart);
$promoteRedirect = '"$WPRISM" --envs-file="$TMP/envs.json" promote target --scope-contract="$TMP/scoped-apply-failure-scope.json" >"$SCOPED_PROMOTE_STDOUT" 2>"$SCOPED_PROMOTE_STDERR"';
$promoteExit = 'printf \'%s\\n\' "$FAILURE_CODE" >"$SCOPED_PROMOTE_EXIT"';
$statusCapture = <<<'SH'
ssh_fixture 'php /home/wprism/site/.wprism/control/recovery-runtime/rollback-control.php authority-status --root=/home/wprism/site/.wprism/control' >"$AUTHORITY_STATUS_STDOUT" 2>"$AUTHORITY_STATUS_STDERR"
SH;
$statusExit = 'printf \'%s\\n\' "$AUTHORITY_STATUS_CODE" >"$AUTHORITY_STATUS_EXIT"';
$promoteAt = strpos($faultCapture, $promoteRedirect);
$promoteExitAt = strpos($faultCapture, $promoteExit);
$statusAt = strpos($faultCapture, $statusCapture);
$statusExitAt = strpos($faultCapture, $statusExit);
$check(
    is_int($promoteAt) && is_int($promoteExitAt) && is_int($statusAt) && is_int($statusExitAt)
        && $promoteAt < $promoteExitAt && $promoteExitAt < $statusAt && $statusAt < $statusExitAt
        && !str_contains($faultCapture, 'rollback-control.php status --root=/home/wprism/site/.wprism/control'),
    'controlled promote streams and immediate raw authority-status streams/exits are captured in order without decorated recovery probes'
);
$check(
    !str_contains($harness, 'FAILURE_OUT')
        && str_contains($harness, 'grep -q \'scoped promote phase: promotion-begin-scoped\' "$SCOPED_PROMOTE_STDOUT" "$SCOPED_PROMOTE_STDERR"')
        && str_contains($harness, 'grep -q \'scoped promote phase: apply\' "$SCOPED_PROMOTE_STDOUT" "$SCOPED_PROMOTE_STDERR"')
        && str_contains($harness, 'grep -q \'prior database verified; generation .* rolled_back and exclusion released\' "$SCOPED_PROMOTE_STDOUT" "$SCOPED_PROMOTE_STDERR"'),
    'fault assertions grep the retained stream files directly instead of a transient combined variable'
);
$check(
    str_contains($harness, "' \"\$AUTHORITY_STATUS_STDOUT\" >/dev/null")
        && str_contains($harness, 'FAIL_RECEIPT="$(jq -r \'.receipt_id\' "$AUTHORITY_STATUS_STDOUT")"')
        && str_contains($harness, 'jq -e --argjson failed_generation "$(jq -r \'.generation\' "$AUTHORITY_STATUS_STDOUT")"'),
    'the captured authority status is consumed privately without copying it back into TMP'
);
$rawStatusStart = strpos($harness, '[ "$AUTHORITY_STATUS_CODE" -eq 0 ]', $faultEnd);
$rawStatusEnd = strpos($harness, 'FAIL_EVIDENCE=', is_int($rawStatusStart) ? $rawStatusStart : 0);
$check(
    is_int($rawStatusStart) && is_int($rawStatusEnd) && $rawStatusStart < $rawStatusEnd
        && str_contains(substr($harness, $rawStatusStart, $rawStatusEnd - $rawStatusStart), '.receipt_format == "wprism-scoped-promotion-receipt/v1"')
        && str_contains(substr($harness, $rawStatusStart, $rawStatusEnd - $rawStatusStart), '.state == "rolled_back" and .terminal == true')
        && str_contains(substr($harness, $rawStatusStart, $rawStatusEnd - $rawStatusStart), '.scope_hash == $h')
        && !str_contains(substr($harness, $rawStatusStart, $rawStatusEnd - $rawStatusStart), 'exclusion_state')
        && str_contains($harness, "jq -e '.state == \"released\"' <<<\"$(ssh_fixture 'cat /home/wprism/recovery-fixture/provider-state.json')\""),
    'raw authority-status proves the signed terminal receipt while the existing provider-state assertion independently proves exclusion release'
);

$successPromoteStart = strpos($harness, 'if "$WPRISM" --envs-file="$TMP/envs.json" promote target --scope-contract="$TMP/scoped-apply-success-scope.json"');
$successStatusStart = strpos($harness, 'SUCCESS_STATUS=', is_int($successPromoteStart) ? $successPromoteStart : 0);
$check(
    is_int($successPromoteStart) && is_int($successStatusStart) && $successPromoteStart < $successStatusStart,
    'the committed scoped-promote retry has a bounded private capture segment'
);
if (!is_int($successPromoteStart) || !is_int($successStatusStart) || $successPromoteStart >= $successStatusStart) {
    exit(1);
}
$successCapture = substr($harness, $successPromoteStart, $successStatusStart - $successPromoteStart);
$successPromoteRedirect = '"$WPRISM" --envs-file="$TMP/envs.json" promote target --scope-contract="$TMP/scoped-apply-success-scope.json" --format=json >"$SCOPED_SUCCESS_PROMOTE_STDOUT" 2>"$SCOPED_SUCCESS_PROMOTE_STDERR"';
$successPromoteExit = 'printf \'%s\n\' "$SUCCESS_CODE" >"$SCOPED_SUCCESS_PROMOTE_EXIT"';
$successReceiptInput = '\' "$SCOPED_SUCCESS_PROMOTE_STDOUT" >/dev/null';
$check(
    str_contains($successCapture, $successPromoteRedirect)
        && str_contains($successCapture, $successPromoteExit)
        && str_contains($successCapture, '[ "$SUCCESS_CODE" -eq 0 ]')
        && str_contains($successCapture, $successReceiptInput)
        && !str_contains($successCapture, 'SUCCESS_JSON=')
        && !str_contains($successCapture, '$TMP/scoped-apply-success.err'),
    'the committed scoped-promote retry retains private stdout, stderr, and exit before parsing its receipt directly from stdout'
);
foreach (['SCOPED_PLAN_STDOUT', 'SCOPED_PLAN_STDERR', 'SCOPED_REFRESH_STDOUT', 'SCOPED_REFRESH_STDERR', 'SCOPED_PROMOTE_STDOUT', 'SCOPED_PROMOTE_STDERR', 'SCOPED_SUCCESS_PROMOTE_STDOUT', 'SCOPED_SUCCESS_PROMOTE_STDERR', 'AUTHORITY_STATUS_STDOUT', 'AUTHORITY_STATUS_STDERR'] as $diagnosticVariable) {
    $check(
        preg_match('/(?:\\bcat\\b|\\becho\\b|\\bprintf\\b)[^\\n]*\\$' . $diagnosticVariable . '\\b/', $harness) !== 1,
        "$diagnosticVariable is never printed or catted into terminal/CI output"
    );
}
$check(
    !str_contains($harness, 'cat "$TMP/driver-adopt.err"')
        && !str_contains($harness, 'cat "$TMP/scoped-apply-success.err"')
        && !str_contains($harness, '$TMP/scoped-apply-success.err')
        && !str_contains($harness, 'SUCCESS_JSON='),
    'other local command diagnostics are likewise not catted into terminal/CI output or retained in secret scratch'
);

$tmpRemoval = 'rm -rf -- "$TMP" || cleanup_failed=1';
$tmpAbsence = '[ ! -e "$TMP" ] && [ ! -L "$TMP" ] || cleanup_failed=1';
$successGate = 'if [ "$BODY_COMPLETE" -eq 1 ] && [ "$incoming" -eq 0 ] && [ "$cleanup_failed" -eq 0 ]; then';
$diagRemoval = 'rm -rf -- "$DIAG_DIR" || cleanup_failed=1';
$diagAbsence = '[ ! -e "$DIAG_DIR" ] && [ ! -L "$DIAG_DIR" ] || cleanup_failed=1';
$retainedPath = 'FAIL: SSH-adoption diagnostic evidence retained privately at %s';
$finalMarker = '✔ REGRESS_SSH_ADOPT PASSED';
$tmpRemovalAt = strpos($cleanup, $tmpRemoval);
$tmpAbsenceAt = strpos($cleanup, $tmpAbsence);
$successGateAt = strpos($cleanup, $successGate);
$diagRemovalAt = strpos($cleanup, $diagRemoval);
$diagAbsenceAt = strpos($cleanup, $diagAbsence);
$retainedPathAt = strpos($cleanup, $retainedPath);
$finalAt = strpos($cleanup, $finalMarker);
$check(
    str_contains($harness, 'BODY_COMPLETE=0')
        && is_int($tmpRemovalAt) && is_int($tmpAbsenceAt) && is_int($successGateAt)
        && is_int($diagRemovalAt) && is_int($diagAbsenceAt) && is_int($retainedPathAt) && is_int($finalAt)
        && $tmpRemovalAt < $tmpAbsenceAt && $tmpAbsenceAt < $successGateAt
        && $successGateAt < $diagRemovalAt && $diagRemovalAt < $diagAbsenceAt && $diagAbsenceAt < $finalAt
        && $retainedPathAt > $finalAt
        && !str_contains($cleanup, 'exit "$incoming"'),
    'TMP is always erased first; only a completed clean body with verified cleanup deletes diagnostics and emits PASS'
);
$bodyCompleteAt = strrpos($harness, 'BODY_COMPLETE=1');
$check(
    substr_count($harness, $finalMarker) === 1
        && is_int($bodyCompleteAt) && $bodyCompleteAt > $cleanupEnd
        && str_contains($cleanup, 'exit 1'),
    'the body merely marks completion while every incomplete, failed, or cleanup-failed exit retains diagnostics and stays nonzero'
);

$containerStart = strpos($harness, "cleanup_container() {\n");
$resourceStart = strpos($harness, "cleanup_resource() {\n");
$check(
    is_int($containerStart) && is_int($resourceStart) && $containerStart < $resourceStart,
    'the original ownership-guard helpers remain separately defined'
);
if (is_int($containerStart) && is_int($resourceStart) && $containerStart < $resourceStart) {
    $containerCleanup = substr($harness, $containerStart, $resourceStart - $containerStart);
    $containerOwnedAt = strpos($containerCleanup, '[ "$owned" -eq 1 ] || return 0');
    $containerLabelsAt = strpos($containerCleanup, 'resource_has_our_labels container "$name"');
    $containerRemoveAt = strpos($containerCleanup, 'docker rm -f "$name"');
    $check(
        is_int($containerOwnedAt) && is_int($containerLabelsAt) && is_int($containerRemoveAt)
            && $containerOwnedAt < $containerLabelsAt && $containerLabelsAt < $containerRemoveAt,
        'container cleanup still requires this run\'s exact ownership labels before removal'
    );
}
$resourceEnd = strpos($harness, "cleanup() {\n");
if (is_int($resourceStart) && is_int($resourceEnd) && $resourceStart < $resourceEnd) {
    $resourceCleanup = substr($harness, $resourceStart, $resourceEnd - $resourceStart);
    $resourceOwnedAt = strpos($resourceCleanup, '[ "$owned" -eq 1 ] || return 0');
    $resourceLabelsAt = strpos($resourceCleanup, 'resource_has_our_labels "$kind" "$name"');
    $resourceRemoveAt = strpos($resourceCleanup, '"${remove[@]}" >/dev/null');
    $check(
        is_int($resourceOwnedAt) && is_int($resourceLabelsAt) && is_int($resourceRemoveAt)
            && $resourceOwnedAt < $resourceLabelsAt && $resourceLabelsAt < $resourceRemoveAt
            && str_contains($harness, '[ "$labels" = "$SUITE_LABEL|$RUN_ID|$SOURCE_SHA" ]'),
        'network, volume, and image cleanup still refuses any resource whose full run labels do not match'
    );
}

echo $failures === 0
    ? "REGRESS_SSH_ADOPT_EVIDENCE_RETENTION PASSED\n"
    : "REGRESS_SSH_ADOPT_EVIDENCE_RETENTION FAILED: $failures assertion(s)\n";
exit($failures === 0 ? 0 : 1);
