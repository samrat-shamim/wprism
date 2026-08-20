<?php
declare(strict_types=1);

/**
 * Offline contract regression for DUO-3344's scoped-apply live harness
 * teardown.
 *
 * The original live body passed, then its EXIT trap discarded the only
 * pair.sh destroy diagnostic, removed the bind roots anyway, and printed a
 * pre-cleanup PASS. A later manual destroy could therefore take a different
 * missing-root path and hide the actual failure. This source contract pins
 * the teardown ordering without allocating Docker, a pair, or WordPress:
 * retain transcripts and roots unless exact pair absence is proved first and
 * the incoming body completed cleanly, and let only verified cleanup publish
 * the final PASS.
 */

$root = dirname(__DIR__, 2);
$harnessPath = $root . '/sandbox/tests/live/regress_scoped_apply_live.sh';
$harness = file_get_contents($harnessPath);
if ($harness === false) {
    fwrite(STDERR, "FAIL: could not read scoped live harness: $harnessPath\n");
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
$cleanupEnd = strpos($harness, "trap cleanup EXIT\n");
$check(
    is_int($cleanupStart) && is_int($cleanupEnd) && $cleanupStart < $cleanupEnd,
    'the scoped live harness defines a bounded EXIT cleanup function before arming it'
);
if (!is_int($cleanupStart) || !is_int($cleanupEnd) || $cleanupStart >= $cleanupEnd) {
    exit(1);
}
$cleanup = substr($harness, $cleanupStart, $cleanupEnd - $cleanupStart);

$destroyLog = 'destroy_log="$TMP/pair-destroy.log"';
$listLog = 'list_log="$TMP/pair-list-after-destroy.log"';
$destroyCall = 'bash "$ROOT/sandbox/bin/pair.sh" destroy "$PAIR" >"$destroy_log" 2>&1';
$listCall = 'bash "$ROOT/sandbox/bin/pair.sh" list >"$list_log" 2>&1';
$check(
    str_contains($cleanup, $destroyLog)
        && str_contains($cleanup, $destroyCall)
        && !str_contains($cleanup, 'destroy "$PAIR" >/dev/null 2>&1'),
    'pair destroy captures its complete transcript in the owned scratch allocation instead of suppressing it'
);
$check(
    str_contains($cleanup, $listLog) && str_contains($cleanup, $listCall),
    'post-destroy pair.sh list is captured as a retained cleanup witness'
);

$destroyAt = strpos($cleanup, $destroyCall);
$listAt = strpos($cleanup, $listCall);
$absenceAt = strrpos($cleanup, 'pair_absent=1');
$bodyIncompleteGate = 'if [ "$incoming_status" -ne 0 ] || [ "$BODY_COMPLETE" -ne 1 ]; then';
$bodyIncompleteAt = strpos($cleanup, $bodyIncompleteGate);
$rootGate = 'if [ "$cleanup_failed" -eq 0 ] && [ "$pair_absent" -eq 1 ] && [ "$incoming_status" -eq 0 ] && [ "$BODY_COMPLETE" -eq 1 ]; then';
$rootGateAt = strpos($cleanup, $rootGate);
$rootRemoval = 'rm -rf -- "$SITE1" "$SITE2" "$ORIGIN"';
$rootRemovalAt = strpos($cleanup, $rootRemoval);
$tmpRemoval = 'rm -rf -- "$TMP"';
$tmpRemovalAt = strpos($cleanup, $tmpRemoval);
$preserveAt = strpos($cleanup, 'preserving owned roots and cleanup artifacts');
$check(
    is_int($destroyAt) && is_int($listAt) && is_int($absenceAt) && is_int($bodyIncompleteAt) && is_int($rootGateAt)
        && is_int($rootRemovalAt) && is_int($tmpRemovalAt) && is_int($preserveAt)
        && $destroyAt < $listAt
        && $listAt < $absenceAt
        && $bodyIncompleteAt < $rootGateAt
        && $absenceAt < $rootGateAt
        && $rootGateAt < $rootRemovalAt
        && $rootRemovalAt < $tmpRemovalAt,
    'destroy, exact list absence, and the clean incoming-body predicate precede every owned-root or scratch deletion'
);
$check(
    !str_contains($cleanup, 'if [ "$cleanup_failed" -eq 0 ] && [ "$pair_absent" -eq 1 ]; then')
        && str_contains($cleanup, 'body_incomplete=1')
        && str_contains($cleanup, 'if [ "$cleanup_failed" -ne 0 ] || [ "$body_incomplete" -eq 1 ]; then')
        && str_contains($cleanup, 'FAIL: scoped live body did not complete cleanly; preserving owned roots and cleanup artifacts:')
        && str_contains($cleanup, 'status=1'),
    'exact pair absence alone is insufficient: failed or incomplete bodies retain roots and scratch evidence with a nonzero exit'
);
$check(
    str_contains($cleanup, 'if [ "$destroy_failed" -eq 1 ]; then')
        && str_contains($cleanup, 'print_cleanup_excerpt "pair destroy failed for ${PAIR}" "$destroy_log"')
        && str_contains($cleanup, 'elif [ "$pair_still_present" -eq 1 ]; then')
        && str_contains($cleanup, 'print_cleanup_excerpt "exact pair ${PAIR} remains in post-destroy list" "$list_log"'),
    'destroy and exact-absence failures print bounded retained transcripts with their artifact paths'
);

$finalMarker = '✔ REGRESS_SCOPED_APPLY_LIVE PASSED';
$finalAt = strpos($cleanup, $finalMarker);
$finalGate = 'if [ "$cleanup_failed" -eq 0 ] && [ "$body_incomplete" -eq 0 ] && [ "$pair_absent" -eq 1 ] && [ "$incoming_status" -eq 0 ] && [ "$BODY_COMPLETE" -eq 1 ]; then';
$finalGateAt = strpos($cleanup, $finalGate);
$bodyCompleteAt = strrpos($harness, 'BODY_COMPLETE=1');
$check(
    substr_count($harness, $finalMarker) === 1
        && is_int($finalAt) && is_int($finalGateAt) && is_int($tmpRemovalAt) && is_int($bodyCompleteAt)
        && $finalGateAt < $finalAt
        && $tmpRemovalAt < $finalAt
        && $bodyCompleteAt > $cleanupEnd,
    'only the verified EXIT cleanup may emit the final PASS; the body merely marks completion'
);

echo $failures === 0
    ? "REGRESS_SCOPED_APPLY_LIVE_CLEANUP PASSED\n"
    : "REGRESS_SCOPED_APPLY_LIVE_CLEANUP FAILED: $failures assertion(s)\n";
exit($failures === 0 ? 0 : 1);
