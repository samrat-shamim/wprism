<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';
require_once dirname(__DIR__) . '/Contract/ProjectionVocabulary.php';
require_once __DIR__ . '/NextAction.php';

use WPrism\Canon;
use WPrism\CommandRefusalException;

/**
 * How one `wprism release` run ended — `wprism-release-outcome/v1` (round-3 MUP
 * §2.3; product spec *Release and verify*).
 *
 * ## The invariant this class exists to hold
 *
 * MUP §2.3 keeps two closed vocabularies strictly apart:
 *
 *  - a refusal raised **before** the authorization plan is frozen carries a
 *    §2.1 **gap action** (`classify`, `declare in contract`, `qualify in
 *    rehearsal`, `exclude`, `provision env value`, `install adapter`,
 *    `nothing — supported`) — the fix is an assessment fix;
 *  - a failure **after** the plan is frozen carries a §2.3 **next action**
 *    (`resume`, `reconcile`, `retry`, `recover`, `requalify`, `escalate`) —
 *    the fix is an operational one.
 *
 * "The two closed sets are not interchangeable" is not a style note: a
 * pre-freeze refusal answered with `retry` tells an operator to re-run a
 * command that will refuse identically, and a post-freeze failure answered
 * with `qualify in rehearsal` tells them to go and rehearse while production
 * sits half-released. `validate()` therefore refuses any outcome that carries
 * both, or that carries the wrong one for its own status — the structure
 * enforces the separation rather than the caller remembering it.
 *
 * A `refused` outcome also carries no `plan_digest`, because there is no
 * frozen plan: MUP §2.3 puts every refusal in `refusals()` strictly before
 * step 1.
 *
 * ## `conditions_rechecked`
 *
 * `{at, checked, conditions, manifests}` — `AuthorizationPlan::
 * recheckConditions()`'s record of the mutation-gate re-observation, or null
 * where none was made. `validate()` REFUSES a `released` outcome that carries
 * none, which is what turns "the gate re-checks conditions" from a property of
 * one call site into a property of the document: a release cannot be recorded
 * as released without evidencing that the conditions it was authorized against
 * were re-observed against the live target first.
 */
final class ReleaseOutcome {
    public const FORMAT = 'wprism-release-outcome/v1';

    /** Released cleanly, with verification attached. */
    public const RELEASED = 'released';

    /** Refused before the plan was frozen; nothing was written. */
    public const REFUSED = 'refused';

    /** Failed after the plan was frozen; the target may have been mutated. */
    public const FAILED = 'failed';

    /** @var list<string> */
    public const STATUSES = [self::RELEASED, self::REFUSED, self::FAILED];

    /**
     * A clean release.
     *
     * @param array<string,mixed> $verify a `wprism-verify-report/v1`. MUP §2.3
     *        step 5 always runs verification, so `[]` here renders as
     *        `verify: null` — an explicit "not verified", never an implied
     *        pass. The spec's own rule applies: absence of an error is never
     *        the proof.
     * @param ?array<string,mixed> $conditionsRechecked the
     *        `AuthorizationPlan::recheckConditions()` record. Required in
     *        practice: `validate()` refuses a released outcome without one.
     * @return array<string,mixed>
     */
    public static function released(
        string $environment,
        string $planDigest,
        array $verify = [],
        ?array $conditionsRechecked = null
    ): array {
        return self::document(
            self::RELEASED,
            $environment,
            $planDigest,
            null,
            null,
            $verify,
            $conditionsRechecked
        );
    }

    /**
     * A pre-freeze refusal, built from one of `AuthorizationPlan::refusals()`'
     * specs (or `RecoveryProfileSelection`'s).
     *
     * @param array{reason_code:string,message:string,remediation:string,gap_action:?string,diagnostics?:list<array<string,mixed>>} $refusal
     * @return array<string,mixed>
     */
    public static function refusedBeforeFreeze(string $environment, array $refusal): array {
        $gapAction = $refusal['gap_action'] ?? null;
        if ($gapAction !== null && !in_array($gapAction, ProjectionVocabulary::GAP_ACTIONS, true)) {
            throw new \InvalidArgumentException(
                'a pre-freeze refusal may only carry a gap action from the §2.1 closed set'
            );
        }

        return self::document(self::REFUSED, $environment, null, [
            'diagnostics' => array_values($refusal['diagnostics'] ?? []),
            'gap_action' => $gapAction,
            'message' => (string) $refusal['message'],
            'reason_code' => (string) $refusal['reason_code'],
            'remediation' => (string) $refusal['remediation'],
        ], null, [], null);
    }

    /**
     * A post-freeze failure. The next action is looked up, never supplied:
     * the caller names WHAT failed and `NextAction` decides what to do about
     * it, so one failure class cannot acquire two answers in two call sites.
     *
     * `reason_code`, `remediation` and `diagnostics` carry the raising
     * refusal's own words when there was one. Without them a machine reading
     * `--format=json` saw the failure CLASS and nothing else — and two
     * distinct refusals share the class `capability_expired`
     * (`release_condition_changed` and `release_condition_uncheckable`), so
     * the envelope could not say which condition drifted, on which subject, or
     * what to do about it. They are `null`/`[]` for a failure classified from
     * the target rather than raised as a refusal, which is the honest answer
     * there: there is no refusal to quote.
     *
     * @param ?array{reason_code:string,message:string,remediation:string,diagnostics:list<array<string,mixed>>} $refusal
     * @param array<string,mixed> $verify a non-passing verification report
     *        when verification completed but proved that the release did not
     *        converge. An unavailable report remains the explicit `null`.
     * @return array<string,mixed>
     */
    public static function failedAfterFreeze(
        string $environment,
        string $planDigest,
        string $failureClass,
        ?array $conditionsRechecked = null,
        ?array $refusal = null,
        array $verify = []
    ): array {
        $action = NextAction::forFailure($failureClass);

        return self::document(self::FAILED, $environment, $planDigest, null, [
            'class' => $failureClass,
            'diagnostics' => array_values($refusal['diagnostics'] ?? []),
            'next_action' => $action,
            'precondition' => NextAction::preconditionFor($action),
            'reason' => NextAction::reasonFor($failureClass),
            'reason_code' => isset($refusal['reason_code']) ? (string) $refusal['reason_code'] : null,
            'remediation' => isset($refusal['remediation']) ? (string) $refusal['remediation'] : null,
        ], $verify, $conditionsRechecked);
    }

    /** Canonical bytes for `--format=json`. */
    public static function encode(array $outcome): string {
        return Canon::encode($outcome);
    }

    /**
     * Refuse an outcome that mixes the two closed vocabularies, or that
     * claims a state its own fields contradict.
     *
     * @param array<string,mixed> $outcome
     */
    public static function validate(array $outcome): void {
        if (($outcome['format'] ?? null) !== self::FORMAT) {
            throw self::refuse('release_outcome_format_invalid', 'the document is not a ' . self::FORMAT);
        }
        $status = $outcome['status'] ?? null;
        if (!is_string($status) || !in_array($status, self::STATUSES, true)) {
            throw self::refuse('release_outcome_shape_invalid', 'the release outcome names no known status');
        }
        $refusal = $outcome['refusal'] ?? null;
        $failure = $outcome['failure'] ?? null;
        if ($refusal !== null && $failure !== null) {
            throw self::refuse(
                'release_outcome_ambiguous',
                'a release outcome carries both a pre-freeze gap action and a post-freeze next action'
            );
        }
        if ($status === self::REFUSED) {
            if (!is_array($refusal)) {
                throw self::refuse('release_outcome_shape_invalid', 'a refused release carries no refusal');
            }
            if (($outcome['plan_digest'] ?? null) !== null) {
                throw self::refuse(
                    'release_outcome_ambiguous',
                    'a refused release names a frozen plan, but every release refusal happens before the freeze'
                );
            }
            $gapAction = $refusal['gap_action'] ?? null;
            if ($gapAction !== null && !in_array($gapAction, ProjectionVocabulary::GAP_ACTIONS, true)) {
                throw self::refuse(
                    'release_outcome_ambiguous',
                    'a release refusal carries an action that is not in the assessment gap-action set'
                );
            }
        }
        if ($status === self::FAILED) {
            if (!is_array($failure) || !is_string($failure['next_action'] ?? null)) {
                throw self::refuse('release_outcome_shape_invalid', 'a failed release carries no next action');
            }
            if (!NextAction::isAction((string) $failure['next_action'])) {
                throw self::refuse(
                    'release_outcome_ambiguous',
                    'a release failure carries an action that is not in the release next-action set'
                );
            }
            if (!is_string($outcome['plan_digest'] ?? null)) {
                throw self::refuse(
                    'release_outcome_ambiguous',
                    'a failed release names no frozen plan, but a next action only applies after the freeze'
                );
            }
        }
        if ($status === self::RELEASED && !is_string($outcome['plan_digest'] ?? null)) {
            throw self::refuse('release_outcome_shape_invalid', 'a released outcome names no frozen plan');
        }
        if ($status === self::RELEASED && !is_array($outcome['conditions_rechecked'] ?? null)) {
            // What makes the mutation-gate recheck unskippable rather than a
            // habit. The product spec permits execution "only when every
            // named, machine-checkable condition is satisfied and rechecked at
            // the mutation gate" (docs/product-spec.md:302-303); a released
            // outcome that cannot say WHEN the recheck happened is a release
            // that cannot evidence its own authorization, and this build
            // refuses to record one.
            throw self::refuse(
                'release_outcome_shape_invalid',
                'a released outcome records no mutation-gate condition recheck'
            );
        }
    }

    /**
     * The operator projection: three lines at most, because this is the last
     * thing printed after a page of plan.
     *
     * @param array<string,mixed> $outcome
     * @return list<string>
     */
    public static function humanLines(array $outcome): array {
        self::validate($outcome);
        $environment = (string) $outcome['environment'];
        $status = (string) $outcome['status'];
        if ($status === self::RELEASED) {
            /** @var array<string,mixed> $rechecked */
            $rechecked = $outcome['conditions_rechecked'];

            // Exactly one extra line, and it is a COUNT. MUP §4.6 forbids a
            // listing bounded by the site's adapter set, and this is the last
            // thing printed after a page of plan: the enumeration lives in
            // --format=json, where a machine reads it.
            return [
                'released to ' . $environment,
                '  conditions rechecked at ' . (string) $rechecked['at'] . ': '
                    . (int) $rechecked['conditions'] . ' across '
                    . (int) $rechecked['checked'] . ' adapter claim(s)',
            ];
        }
        if ($status === self::REFUSED) {
            /** @var array<string,mixed> $refusal */
            $refusal = $outcome['refusal'];
            $lines = ['refused before anything was written: ' . (string) $refusal['message']];
            $gap = $refusal['gap_action'] ?? null;
            if (is_string($gap)) {
                $lines[] = '  next action: ' . $gap;
            }
            $lines[] = '  ' . (string) $refusal['remediation'];

            return $lines;
        }
        /** @var array<string,mixed> $failure */
        $failure = $outcome['failure'];
        $lines = [
            'release failed after the plan was frozen: ' . (string) $failure['reason'],
            '  next action: ' . (string) $failure['next_action'],
        ];
        if (is_string($failure['precondition'] ?? null)) {
            $lines[] = '  ' . (string) $failure['precondition'];
        }

        return $lines;
    }

    /**
     * @param ?array<string,mixed> $refusal
     * @param ?array<string,mixed> $failure
     * @param array<string,mixed> $verify
     * @return array<string,mixed>
     */
    private static function document(
        string $status,
        string $environment,
        ?string $planDigest,
        ?array $refusal,
        ?array $failure,
        array $verify,
        ?array $conditionsRechecked
    ): array {
        if ($environment === '') {
            throw new \InvalidArgumentException('a release outcome must name its environment');
        }
        $outcome = [
            'conditions_rechecked' => $conditionsRechecked,
            'environment' => $environment,
            'failure' => $failure,
            'format' => self::FORMAT,
            'plan_digest' => $planDigest,
            'refusal' => $refusal,
            'status' => $status,
            'verify' => $verify === [] ? null : $verify,
        ];
        self::validate($outcome);

        return $outcome;
    }

    private static function refuse(string $code, string $message): CommandRefusalException {
        return new CommandRefusalException(
            $code,
            $message,
            'do not act on this outcome; inspect private operator evidence and the frozen plan under .wprism/releases'
        );
    }
}
