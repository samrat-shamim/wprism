<?php
declare(strict_types=1);

/**
 * Offline source contract for the SSH adoption/scoped-promotion live harness.
 *
 * The deliberate post-begin promote fault is the one live failure where the
 * exact stdout/stderr is essential to distinguish an engine regression from a
 * fixture or transport problem. It used to live only in FAILURE_OUT, inside a
 * secret-bearing scratch tree that the EXIT trap always deleted. This check
 * proves the repair keeps a separate private, bounded evidence directory while
 * still destroying SSH keys, environment config, and DB credentials on every
 * exit. It reads source only: no Docker, SSH host, WordPress, or live target.
 */

$root = dirname(__DIR__, 2);
$harnessPath = $root . '/sandbox/tests/regress_ssh_adopt.sh';
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

$diagnosticAssignments = [
    'SCOPED_PROMOTE_STDOUT="$DIAG_DIR/scoped-promote.stdout"',
    'SCOPED_PROMOTE_STDERR="$DIAG_DIR/scoped-promote.stderr"',
    'SCOPED_PROMOTE_EXIT="$DIAG_DIR/scoped-promote.exit"',
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
        && str_contains($harness, 'for diagnostic_file in "$SCOPED_PROMOTE_STDOUT" "$SCOPED_PROMOTE_STDERR" "$SCOPED_PROMOTE_EXIT" "$AUTHORITY_STATUS_STDOUT" "$AUTHORITY_STATUS_STDERR" "$AUTHORITY_STATUS_EXIT"; do')
        && str_contains($harness, '( umask 077; : >"$diagnostic_file" )')
        && str_contains($harness, 'chmod 0600 "$diagnostic_file"'),
    'only the bounded promote/status streams and numeric exits are precreated mode 0600'
);
preg_match_all('/\$DIAG_DIR\/([A-Za-z0-9._-]+)/', $harness, $diagnosticNames);
$actualDiagnosticNames = array_values(array_unique($diagnosticNames[1] ?? []));
sort($actualDiagnosticNames);
$expectedDiagnosticNames = [
    'authority-status.exit',
    'authority-status.stderr',
    'authority-status.stdout',
    'scoped-promote.exit',
    'scoped-promote.stderr',
    'scoped-promote.stdout',
];
$check(
    $actualDiagnosticNames === $expectedDiagnosticNames,
    'the diagnostic directory receives no key, config, credential, or unrelated evidence file'
);

$faultStart = strpos($harness, 'if "$DUO" --envs-file="$TMP/envs.json" promote target --scope-contract="$TMP/duo3344-failure-scope.json"');
$faultEnd = strpos($harness, "ssh_fixture 'rm -f /home/duo/recovery-fixture/duo3344-scoped-fault-active", is_int($faultStart) ? $faultStart : 0);
$check(
    is_int($faultStart) && is_int($faultEnd) && $faultStart < $faultEnd,
    'the controlled scoped-promote fault has a bounded capture/status segment'
);
if (!is_int($faultStart) || !is_int($faultEnd) || $faultStart >= $faultEnd) {
    exit(1);
}
$faultCapture = substr($harness, $faultStart, $faultEnd - $faultStart);
$promoteRedirect = '"$DUO" --envs-file="$TMP/envs.json" promote target --scope-contract="$TMP/duo3344-failure-scope.json" >"$SCOPED_PROMOTE_STDOUT" 2>"$SCOPED_PROMOTE_STDERR"';
$promoteExit = 'printf \'%s\\n\' "$FAILURE_CODE" >"$SCOPED_PROMOTE_EXIT"';
$statusCapture = <<<'SH'
ssh_fixture 'php /home/duo/site/.duo/control/recovery-runtime/rollback-control.php authority-status --root=/home/duo/site/.duo/control' >"$AUTHORITY_STATUS_STDOUT" 2>"$AUTHORITY_STATUS_STDERR"
SH;
$statusExit = 'printf \'%s\\n\' "$AUTHORITY_STATUS_CODE" >"$AUTHORITY_STATUS_EXIT"';
$promoteAt = strpos($faultCapture, $promoteRedirect);
$promoteExitAt = strpos($faultCapture, $promoteExit);
$statusAt = strpos($faultCapture, $statusCapture);
$statusExitAt = strpos($faultCapture, $statusExit);
$check(
    is_int($promoteAt) && is_int($promoteExitAt) && is_int($statusAt) && is_int($statusExitAt)
        && $promoteAt < $promoteExitAt && $promoteExitAt < $statusAt && $statusAt < $statusExitAt
        && !str_contains($faultCapture, 'rollback-control.php status --root=/home/duo/site/.duo/control'),
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
        && str_contains(substr($harness, $rawStatusStart, $rawStatusEnd - $rawStatusStart), '.receipt_format == "duo-scoped-promotion-receipt/v1"')
        && str_contains(substr($harness, $rawStatusStart, $rawStatusEnd - $rawStatusStart), '.state == "rolled_back" and .terminal == true')
        && !str_contains(substr($harness, $rawStatusStart, $rawStatusEnd - $rawStatusStart), 'exclusion_state')
        && str_contains($harness, "jq -e '.state == \"released\"' <<<\"$(ssh_fixture 'cat /home/duo/recovery-fixture/provider-state.json')\""),
    'raw authority-status proves the signed terminal receipt while the existing provider-state assertion independently proves exclusion release'
);
foreach (['SCOPED_PROMOTE_STDOUT', 'SCOPED_PROMOTE_STDERR', 'AUTHORITY_STATUS_STDOUT', 'AUTHORITY_STATUS_STDERR'] as $diagnosticVariable) {
    $check(
        preg_match('/(?:\\bcat\\b|\\becho\\b|\\bprintf\\b)[^\\n]*\\$' . $diagnosticVariable . '\\b/', $harness) !== 1,
        "$diagnosticVariable is never printed or catted into terminal/CI output"
    );
}
$check(
    !str_contains($harness, 'cat "$TMP/driver-adopt.err"')
        && !str_contains($harness, 'cat "$TMP/duo3344-success.err"'),
    'other local command diagnostics are likewise not catted into terminal/CI output'
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
