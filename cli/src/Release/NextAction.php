<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/CommandRefusal.php';

use Duo\CommandRefusalException;

/**
 * The closed post-freeze next-action set, and the deterministic mapping from
 * failure class to action (round-3 MUP §2.3; product spec *Release and
 * verify*).
 *
 * The spec's sentence is short and total: *"Failure returns a documented
 * public next action: resume, reconcile, retry, recover, requalify, or
 * escalate."* Two properties make that a gate rather than a slogan, and both
 * are enforced here:
 *
 *  1. **The set is closed and total.** `ACTIONS` is the whole vocabulary; a
 *     failure class this table does not know refuses loudly instead of
 *     defaulting. A silent default is how "escalate" quietly becomes the
 *     answer to everything, or worse, how "retry" becomes the answer to
 *     something that must never be retried.
 *  2. **A next action is only ever a POST-freeze answer.** MUP §2.3: *"A
 *     pre-authorization refusal carries a gap action from §2.1's closed set…
 *     Only a failure after the plan is frozen carries a release next
 *     action."* The two closed sets are not interchangeable, and
 *     `ReleaseOutcome::validate()` refuses a document that mixes them.
 *
 * ## The mapping
 *
 * | failure class | next action | why |
 * |---|---|---|
 * | `incomplete_lifecycle` | `recover` | an interrupted code lifecycle window left the target between two worlds; only the recovery profile can converge it. MUP §2.3 states this one literally. |
 * | `incomplete_apply` | `recover` | same shape one layer down: the authored-state transaction did not reach its terminal receipt. |
 * | `ambiguous_commitment` | `reconcile` | the controller lost the response and cannot prove whether the target committed. **Never `retry`**: replaying an unprovable commitment is how a release is applied twice. |
 * | `receipt_uncertain` | `reconcile` | the durable receipt is unreadable or disagrees with the target; the same quarantine. |
 * | `drift_detected` | `reconcile` | the target moved outside Duo since the plan was frozen; merge it, do not overwrite it. |
 * | `ref_mismatch` | `reconcile` | MUP §2.3 step 2: `--from <ref>` is a binding assertion, and a target `HEAD` mismatch is reconciled, not forced. |
 * | `transport_transient` | `retry` | the only class that earns a retry — and only after receipt reconciliation proves replay safe (`RETRY_PRECONDITION`). |
 * | `plan_changed` | `retry` | a clean pre-mutation refusal (MUP §2.3 step 3): nothing was written, so rebuilding the plan and re-authorizing is safe. |
 * | `capability_expired` | `requalify` | a condition re-checked at the mutation gate no longer holds; the fix is evidence, not repetition. |
 * | `evidence_not_current` | `requalify` | the pinned certification evidence went stale between freeze and gate. |
 * | `frozen_promotion` | `resume` | a durable frozen promotion exists and the existing state machine can carry it forward from its own receipt. |
 * | `lease_held` | `resume` | another generation holds the promotion lease; resuming that generation is the safe move, not starting a new one. |
 * | `checkpoint_unavailable` | `escalate` | the selected recovery profile's checkpoint is absent or unverifiable, so no automated action is safe. |
 * | `authority_required` | `escalate` | the plan named authority that has not been given; only a human can supply it. |
 * | `nothing_safe` | `escalate` | the explicit terminal case the spec names: refuse and identify the required operator authority. |
 *
 * Every row is a real receipt or refusal shape the shipped promotion state
 * machine already produces; this class adds the product word, not a new
 * failure taxonomy.
 */
final class NextAction {
    public const RESUME = 'resume';
    public const RECONCILE = 'reconcile';
    public const RETRY = 'retry';
    public const RECOVER = 'recover';
    public const REQUALIFY = 'requalify';
    public const ESCALATE = 'escalate';

    /** @var list<string> */
    public const ACTIONS = [
        self::RESUME, self::RECONCILE, self::RETRY, self::RECOVER, self::REQUALIFY, self::ESCALATE,
    ];

    /**
     * Failure class -> next action. See the class docblock's table for the
     * reasoning behind each row.
     *
     * @var array<string,string>
     */
    public const MAPPING = [
        'ambiguous_commitment' => self::RECONCILE,
        'authority_required' => self::ESCALATE,
        'capability_expired' => self::REQUALIFY,
        'checkpoint_unavailable' => self::ESCALATE,
        'drift_detected' => self::RECONCILE,
        'evidence_not_current' => self::REQUALIFY,
        'frozen_promotion' => self::RESUME,
        'incomplete_apply' => self::RECOVER,
        'incomplete_lifecycle' => self::RECOVER,
        'lease_held' => self::RESUME,
        'nothing_safe' => self::ESCALATE,
        'plan_changed' => self::RETRY,
        'receipt_uncertain' => self::RECONCILE,
        'ref_mismatch' => self::RECONCILE,
        'transport_transient' => self::RETRY,
    ];

    /**
     * One sentence per failure class, printed beside the action so the
     * operator reads WHY, not only WHAT.
     *
     * @var array<string,string>
     */
    public const REASONS = [
        'ambiguous_commitment' => 'the controller cannot prove whether the target committed this release',
        'authority_required' => 'the frozen plan named authority that has not been supplied',
        'capability_expired' => 'a condition re-checked at the mutation gate no longer holds',
        'checkpoint_unavailable' => "the selected recovery profile's checkpoint is absent or did not verify",
        'drift_detected' => 'the target changed outside Duo after the plan was frozen',
        'evidence_not_current' => 'the certification evidence this release depends on went stale',
        'frozen_promotion' => 'a durable frozen promotion for this artifact already exists',
        'incomplete_apply' => 'the authored-state transaction did not reach its terminal receipt',
        'incomplete_lifecycle' => 'the code lifecycle window was interrupted between phases',
        'lease_held' => 'another promotion generation holds the target lease',
        'nothing_safe' => 'no automated action is safe for this failure',
        'plan_changed' => 'the target moved between freezing the plan and confirming it; nothing was written',
        'receipt_uncertain' => 'the durable receipt is unreadable or disagrees with the target',
        'ref_mismatch' => 'the target HEAD does not match the ref this release asserted',
        'transport_transient' => 'the transport failed in a way that carries no commitment evidence',
    ];

    /**
     * The spec's own precondition on `retry`: *"Retry uses the same operation
     * identity and is offered only after durable receipt reconciliation
     * proves replay safe."* Printed on every `retry` outcome so the action is
     * never read as "just run it again".
     */
    public const RETRY_PRECONDITION =
        'retry reuses the same operation identity and is safe only after durable receipt reconciliation '
        . 'proves the prior attempt wrote nothing';

    /** @return list<string> */
    public static function failureClasses(): array {
        return array_keys(self::MAPPING);
    }

    public static function isAction(string $action): bool {
        return in_array($action, self::ACTIONS, true);
    }

    public static function isFailureClass(string $failureClass): bool {
        return array_key_exists($failureClass, self::MAPPING);
    }

    /**
     * The one documented action for a failure class.
     *
     * Deliberately throws rather than defaulting: an unmapped class is a
     * caller bug, and inventing an action for it would put an undocumented
     * word in front of an operator at the worst possible moment.
     */
    public static function forFailure(string $failureClass): string {
        if (!array_key_exists($failureClass, self::MAPPING)) {
            throw new CommandRefusalException(
                'release_failure_class_unknown',
                'this release failure has no documented next action in this build',
                'treat it as escalate: preserve the frozen plan and the target receipts, and inspect private '
                    . 'operator evidence before any further attempt',
                [['failure_class' => $failureClass]]
            );
        }

        return self::MAPPING[$failureClass];
    }

    public static function reasonFor(string $failureClass): string {
        self::forFailure($failureClass);

        return self::REASONS[$failureClass];
    }

    /**
     * The precondition string for an action, or null when it carries none.
     * Only `retry` has one, and it is the spec's own sentence.
     */
    public static function preconditionFor(string $action): ?string {
        return $action === self::RETRY ? self::RETRY_PRECONDITION : null;
    }
}
