<?php
declare(strict_types=1);

/**
 * The closed next-action set, checked as a set (round-3 MUP §2.3).
 *
 * `regress_release_next_action.sh` proves that a REAL `duo release` run
 * reaches the right action; this proves the table it reaches it through is
 * total, closed and single-valued, so a new failure class cannot arrive with
 * two answers or none.
 */

$root = $argv[1] ?? dirname(__DIR__, 4);
require_once $root . '/cli/src/Release/NextAction.php';
require_once $root . '/cli/src/Release/ReleaseOutcome.php';
require_once $root . '/cli/src/Command/ReleaseCommand.php';

use Duo\CommandRefusalException;
use Duo\Orchestrator\NextAction;
use Duo\Orchestrator\ProjectionVocabulary;
use Duo\Orchestrator\ReleaseCommand;
use Duo\Orchestrator\ReleaseOutcome;

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    if ($condition) {
        echo "ok: $message\n";

        return;
    }
    fwrite(STDERR, "FAIL: $message\n");
    $failures++;
};

// The set itself is the spec's, in the spec's order.
$check(
    NextAction::ACTIONS === ['resume', 'reconcile', 'retry', 'recover', 'requalify', 'escalate'],
    'the next-action set is exactly resume|reconcile|retry|recover|requalify|escalate'
);

// Total and single-valued: every documented failure class resolves, and it
// resolves inside the closed set.
$classes = NextAction::failureClasses();
$check(count($classes) >= 15, 'every documented failure class is mapped (' . count($classes) . ')');
foreach ($classes as $class) {
    $action = NextAction::forFailure($class);
    $check(
        in_array($action, NextAction::ACTIONS, true),
        "failure class '$class' resolves to the closed set (got '$action')"
    );
    $check(NextAction::reasonFor($class) !== '', "failure class '$class' states WHY, not only WHAT");
}

// An unmapped class is a refusal, not a guess.
try {
    NextAction::forFailure('a_class_nobody_documented');
    $check(false, 'an unmapped failure class refuses instead of defaulting');
} catch (CommandRefusalException $refusal) {
    $check(
        $refusal->reasonCode === 'release_failure_class_unknown',
        'an unmapped failure class refuses with its own reason code'
    );
}

// The two rows MUP §2.3 states as absolutes.
$check(NextAction::forFailure('incomplete_lifecycle') === NextAction::RECOVER,
    'an incomplete_lifecycle receipt is ALWAYS recover');
$check(NextAction::forFailure('incomplete_apply') === NextAction::RECOVER,
    'an interrupted authored-state transaction is recover');
$check(NextAction::forFailure('ambiguous_commitment') === NextAction::RECONCILE,
    'an ambiguous commitment is ALWAYS reconcile');
$check(NextAction::forFailure('ambiguous_commitment') !== NextAction::RETRY,
    'an ambiguous commitment is NEVER retry');
$check(NextAction::forFailure('ref_mismatch') === NextAction::RECONCILE,
    'a --from ref mismatch is reconcile');
$check(NextAction::forFailure('plan_changed') === NextAction::RETRY,
    'a plan that changed before any write is retry — nothing needs reconciling');

// `retry` never ships without the spec's own precondition on it.
$check(
    NextAction::preconditionFor(NextAction::RETRY) === NextAction::RETRY_PRECONDITION,
    'every retry outcome carries the durable-receipt-reconciliation precondition'
);

// Every class `ReleaseCommand` can OBSERVE is a class the table knows, and
// each one is a distinct answer rather than a synonym for escalate.
$observed = ReleaseCommand::OBSERVED_FAILURE_CLASSES;
$check($observed !== [], 'ReleaseCommand documents the failure classes it observes');
$resolved = [];
foreach ($observed as $class) {
    $check(NextAction::isFailureClass($class), "the observed class '$class' is in the closed mapping");
    $resolved[NextAction::forFailure($class)] = true;
}
$check(count($resolved) >= 4, 'the observed classes reach at least four distinct next actions');
$check(
    NextAction::forFailure('nothing_safe') === NextAction::ESCALATE,
    'the classifier fallback escalates rather than offering an automated action'
);

// The two closed sets never overlap: a release next action is not a gap
// action and a gap action is not a release next action.
$overlap = array_intersect(NextAction::ACTIONS, ProjectionVocabulary::GAP_ACTIONS);
$check($overlap === [], 'the release next-action set and the §2.1 gap-action set are disjoint');

// A pre-freeze refusal may only carry a gap action, enforced structurally.
try {
    ReleaseOutcome::refusedBeforeFreeze('fixture', [
        'diagnostics' => [],
        'gap_action' => NextAction::RETRY,
        'message' => 'a refusal',
        'reason_code' => 'release_surface_not_releasable',
        'remediation' => 'do something',
    ]);
    $check(false, 'a pre-freeze refusal cannot carry a release next action as its gap action');
} catch (\InvalidArgumentException) {
    $check(true, 'a pre-freeze refusal carrying a release next action is refused structurally');
}

// A post-freeze failure may only carry a next action, and must name a plan.
$failed = ReleaseOutcome::failedAfterFreeze('fixture', 'sha256:' . str_repeat('ab', 32), 'incomplete_lifecycle');
ReleaseOutcome::validate($failed);
$check(
    ($failed['failure']['next_action'] ?? null) === NextAction::RECOVER,
    'a post-freeze failure document carries exactly one next action'
);
$check(
    ($failed['plan_digest'] ?? null) !== null,
    'a post-freeze failure names the authorization it was operating under'
);

echo $failures === 0
    ? "PASS: the closed next-action set\n"
    : "FAIL: $failures next-action assertions\n";
exit($failures === 0 ? 0 : 1);
