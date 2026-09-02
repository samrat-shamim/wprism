<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Transport/RecoveryTransport.php';
require_once __DIR__ . '/../Transport/CodeDeploy.php';
require_once __DIR__ . '/../Authority/OperationAuthorization.php';
require_once __DIR__ . '/../Authority/TargetOperationStore.php';
require_once __DIR__ . '/../Recovery/CheckpointCatalog.php';
require_once __DIR__ . '/../Recovery/CheckpointPrune.php';
require_once __DIR__ . '/../Recovery/RecoveryClaim.php';
require_once __DIR__ . '/../Recovery/RecoveryOutcome.php';
require_once __DIR__ . '/../Recovery/RecoveryPlan.php';
require_once __DIR__ . '/../Recovery/RetainedCheckpoints.php';
require_once __DIR__ . '/../Recovery/RollbackAuthority.php';
require_once __DIR__ . '/../Recovery/ScopedRollbackProfile.php';
require_once __DIR__ . '/../Recovery/VerifiedRollbackProfile.php';
require_once __DIR__ . '/../Release/AuthorizationPlan.php';
require_once __DIR__ . '/AssessCommand.php';
require_once __DIR__ . '/CommandOutput.php';

use WPrism\CommandRefusalException;
use WPrism\Recovery\CanonicalJson;
use WPrism\Recovery\RollbackControl;

/**
 * `wprism recover <env>` — the operator verb over the recovery runtime
 * (round-3 MUP §2.5).
 *
 * This replaces raw invocation of `recovery/rollback-control.php` (§5.3).
 * The runtime is unchanged; only who types it changes. It is deliberately a
 * THIN, LITERAL front end: `RollbackAuthority`, `VerifiedRollbackProfile` and
 * `ScopedRollbackProfile` own every transition, and the operator-directed
 * path drives exactly the four commands `cli/wprism`'s
 * `print_promotion_recovery()` currently asks a human to type, in the same
 * order, built from the same `CodeDeploy` argument builders. Nothing here
 * invents a fifth step, and nothing here reorders the four.
 *
 * ## The three rules §2.5 makes non-negotiable
 *
 *  1. **External writer exclusion is asserted before anything starts.** The
 *     checkpoint contains the promotion lease row (`cli/wprism`'s
 *     `print_promotion_recovery()` says so), so a lock inside the database
 *     being imported cannot protect the window. `--writers-excluded` is the
 *     operator's assertion that a real maintenance window exists; without it
 *     this command refuses before the first transition.
 *  2. **Code first.** If the failure happened after a code phase, a database
 *     import is refused until code is reconciled to the pre-release revision,
 *     and the refusal names that exact revision. Restoring a database that
 *     describes one code revision underneath a different one is the state
 *     that cannot be reasoned about afterwards.
 *  3. **The final abort is mandatory, including when the import fails.**
 *     Step 4 releases the temporary lease row the imported dump reinstated.
 *     Skipping it on a failed import leaves the target holding a lease no
 *     process owns, which is the one outcome worse than a failed recovery.
 *     It runs in a `finally`, so no branch of this code can skip it.
 *
 * ## The claim is printed twice
 *
 * `RecoveryClaim`'s `restores` / `does_not_restore` lists are printed
 * verbatim BEFORE acting and again in the outcome. When the checkpoint's own
 * artifact hash matches a frozen authorization plan in this site repository,
 * the claim printed is that plan's claim, byte for byte — which is what makes
 * "the claim the operator saw at authorization is the claim they see at
 * recovery" a checkable property rather than a coincidence.
 *
 * The checkpoint instant is printed on the line after the claim, at both
 * printings, and never inside it: the claim is embedded in the DIGESTED part
 * of the frozen plan, so a clock value in it would give one unchanged
 * authorization a new `plan_digest` every second (`RecoveryClaim`'s docblock,
 * "Why no field here holds a clock value"). `checkpointLine()` names where
 * the instant came from, because the receipt's own creation time and the
 * plan's release-start instant answer slightly different questions.
 *
 * ## Which targets
 *
 * Every transport. The signed catalog and the signed rollback need the
 * adopted rollback authority runtime, which an adopted target carries only
 * when its rollback authority is configured (`RecoveryTransport`);
 * the retained release checkpoints (`RetainedCheckpoints`) exist on every
 * target that ever ran an operator-directed promotion, and they are restored
 * through the operator-directed four steps, which need nothing but `wp`.
 * A local or docker target therefore lists and restores its retained
 * checkpoints, and the frozen plan's `operator-directed` claim on that
 * transport is a claim this verb honours — `grind_mup.sh` step 11 is that
 * property as a gate. The `recovery_authority_unavailable` refusal is kept,
 * byte for byte, for the one thing a target with no configured rollback
 * authority genuinely cannot do: a signed rollback.
 *
 * ## Pruning is a verb, never a policy
 *
 * `--prune-retained=<keep-n>` is the only thing in the product that removes a
 * retained release checkpoint, and nothing removes one automatically. It lives
 * on THIS verb because `flags()` below is already the closed grammar over this
 * catalog and `CheckpointCatalog::list()` is already the single read that
 * names every retained row; a separate `wprism checkpoints` verb would duplicate
 * the catalog read, the `--limit` grammar and a help block in order to own an
 * `rm`. `CheckpointPrune`'s docblock carries the five safety rules and why
 * `retention_until` stays null; two of them matter here:
 *
 *  - **The default is a plan.** `--prune-retained=<n>` alone prints what it
 *    would remove and issues no mutating call at all. Deletion needs
 *    `--confirm-prune`. `--dry-run` was rejected as the spelling because it
 *    would make deletion the default, which is the wrong fail direction for
 *    the only before-image a target holds.
 *  - **No writer exclusion.** `--writers-excluded` asserts one exact fact
 *    about a whole-database import (rule 1 above); a prune imports nothing and
 *    takes no lease, so combining the two is `invalid_arguments` rather than
 *    an accepted no-op that would re-teach the flag as generic danger.
 *
 * Exit status: `0` a completed listing, prune or recovery, `1` any refusal, a
 * prune the target could not complete, or a recovery that did not reach its
 * terminal state.
 */
final class RecoverCommand {
    /** The four ordered steps of the operator-directed path (§2.5). */
    public const ORDERED_STEPS = ['abort', 'begin', 'import', 'final-abort'];

    /** The flag that asserts a real external maintenance window. */
    public const WRITERS_EXCLUDED_FLAG = '--writers-excluded';

    /** The only verb in the product that removes a retained checkpoint. */
    public const PRUNE_RETAINED_FLAG = '--prune-retained';

    /** The read-only half of the asynchronous recovery contract. */
    public const PREPARE_VERB = 'prepare';

    /** The actor-authorized, target-consumed half of that contract. */
    public const EXECUTE_VERB = 'execute';

    /** Without it a prune prints a plan and issues no mutating call. */
    public const CONFIRM_PRUNE_FLAG = '--confirm-prune';

    /**
     * What a failed step reports when the target sent no classified refusal —
     * a transport error, `wp db import`'s own non-zero exit, an agent build
     * that predates the reason codes. It stays byte-identical to what every
     * failed step said before issue #3506, so the only thing that changed for an
     * unclassified failure is that it is now distinguishable from a
     * classified one.
     */
    public const STEP_FAILED_DETAIL =
        'the target refused or failed this step; inspect private operator evidence';

    /** Where the checkpoint instant printed beside the claim came from. */
    public const SOURCE_RECEIPT = 'receipt';
    public const SOURCE_PLAN = 'authorization-plan';
    public const SOURCE_NONE = 'unpublished';

    /**
     * Checkpoint coverage that means code moved during the failed release.
     *
     * A receipt covering a code release is a receipt taken around a code
     * phase, so a database import under it is a code-first decision.
     */
    public const CODE_COVERAGE = RecoveryClaim::RESOURCE_CODE_RELEASE;

    /**
     * @param list<string> $extra everything after `<env>`
     * @param ?callable():string $clock null reads the wall clock
     */
    public static function run(EnvironmentDriver $driver, array $extra, ?callable $clock = null): int {
        $json = AssessCommand::wantsJson($extra);
        $readClock = $clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z');
        try {
            $flags = self::flags($extra);
            $now = $readClock();
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, $json, 'recover');
        }

        if ($flags['execute'] === true) {
            try {
                $outcome = self::executeAuthorized($driver, $flags, $readClock);
            } catch (CommandRefusalException $refusal) {
                return AssessCommand::renderRefusal($refusal, true, 'recover');
            }
            echo RecoveryOutcome::encode($outcome);

            return $outcome['recovered'] === true ? 0 : 1;
        }

        try {
            $catalog = CheckpointCatalog::list($driver, $now);
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, $json, 'recover');
        }

        if ($flags['prepare'] === true) {
            try {
                $plan = self::prepare($driver, $catalog, $flags, $now);
            } catch (CommandRefusalException $refusal) {
                return AssessCommand::renderRefusal($refusal, $json, 'recover');
            }
            echo RecoveryPlan::encode($plan);

            return 0;
        }

        // Before the listing branch, because a prune is a different request
        // over the same catalog and `flags()` has already refused the two
        // being asked for together.
        if ($flags['prune_retained'] !== null) {
            try {
                $document = self::prune($driver, $catalog, $flags);
            } catch (CommandRefusalException $refusal) {
                return AssessCommand::renderRefusal($refusal, $json, 'recover');
            }
            if ($json) {
                echo self::encode($document);
            } else {
                foreach (self::pruneLines($document, $flags['limit']) as $line) {
                    echo $line . "\n";
                }
            }

            return $document['ok'] === true ? 0 : 1;
        }

        if ($flags['restore'] === null) {
            if ($json) {
                echo self::encode($catalog);

                return 0;
            }
            foreach (CheckpointCatalog::humanLines($catalog, $flags['limit']) as $line) {
                echo $line . "\n";
            }

            return 0;
        }

        try {
            $outcome = self::restore($driver, $catalog, $flags, $json);
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, $json, 'recover');
        }

        if ($json) {
            echo self::encode($outcome);

            return $outcome['recovered'] === true ? 0 : 1;
        }
        foreach (self::outcomeLines($outcome, $flags['limit']) as $line) {
            echo $line . "\n";
        }

        return $outcome['recovered'] === true ? 0 : 1;
    }

    /**
     * Reacquire the exact read-only fact vector a future execute gate needs.
     *
     * Authorized execute calls this before signature verification and again at
     * its target-consumption boundary. Keeping the target read and comparison
     * public gives every executor one mandatory API instead of asking it to
     * reconstruct which checkpoint, generation, head, claim, and scope matter.
     *
     * @param array<string,mixed> $plan a validated `RecoveryPlan::FORMAT`
     * @param ?callable():string $clock
     * @return array{at:string,facts_sha256:string,plan_digest:string,subject_digest:string}
     */
    public static function reverifyPreparation(
        EnvironmentDriver $driver,
        array $plan,
        ?callable $clock = null
    ): array {
        RecoveryPlan::validate($plan);
        $now = ($clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z'))();
        $catalog = CheckpointCatalog::list($driver, $now);
        $row = CheckpointCatalog::find($catalog, (string) $plan['checkpoint']['id']);
        if ($row === null) {
            throw new CommandRefusalException(
                'recovery_plan_changed',
                'the selected recovery receipt is no longer the active target generation',
                'prepare a fresh recovery plan against the checkpoint catalog that is active now',
                [['changed_fields' => ['target.receipt_id']]]
            );
        }
        // Re-derive product semantics from current receipt/repository evidence.
        // Feeding the frozen claim back here would prove only that the plan
        // still contains its own bytes, and would let an edited-but-redigested
        // claim become the current observation it is meant to be checked against.
        $resolved = self::claim($row);
        $observation = self::observePreparation($driver, $row, $resolved['claim']);

        return RecoveryPlan::reverify($plan, RecoveryPlan::currentFacts($observation), $now);
    }

    /**
     * Return an exact completed replay, or refuse a consumed operation whose
     * completion is absent. A future execute verb must call this BEFORE it
     * verifies authorization expiry: completion is durable target fact, while
     * expiry only decides whether a still-unconsumed operation may start.
     *
     * Null means no consumption exists and therefore no mutation has crossed
     * the shared authority boundary. This method itself is read-only.
     *
     * @param array<string,mixed> $plan
     * @return ?array<string,mixed> validated `RecoveryOutcome::FORMAT`
     */
    public static function priorExecutionOutcome(
        EnvironmentDriver $driver,
        array $plan,
        string $authorizationDigest
    ): ?array {
        RecoveryPlan::validate($plan);
        $stored = TargetOperationStore::status($driver, $authorizationDigest);
        if ($stored === null) {
            return null;
        }
        $consumption = is_array($stored['consumption'] ?? null) ? $stored['consumption'] : [];
        $expected = [
            'authorization_digest' => $authorizationDigest,
            'operation' => 'recovery',
            'operation_id' => (string) $plan['operation_id'],
            'presentation_digest' => (string) $plan['presentation_digest'],
            'subject_digest' => (string) $plan['subject_digest'],
            'target_id' => (string) $plan['target']['operation_target_id'],
        ];
        foreach ($expected as $field => $value) {
            if (!is_string($consumption[$field] ?? null)
                || !hash_equals($value, (string) $consumption[$field])) {
                throw new CommandRefusalException(
                    'recovery_authorization_consumption_conflict',
                    'the target consumption record does not apply to this exact frozen recovery plan',
                    'do not start recovery; reconcile the consumed operation and its target-private evidence'
                );
            }
        }
        if (!is_array($stored['completion'] ?? null)) {
            throw new CommandRefusalException(
                'recovery_reconciliation_required',
                'the recovery authorization was consumed but no exact terminal outcome is durably published',
                'do not retry recovery or consume new authority; reconcile this operation from target control evidence'
            );
        }
        $outcome = $stored['completion']['outcome'] ?? null;
        if (!is_array($outcome)) {
            throw new CommandRefusalException(
                'recovery_outcome_evidence_invalid',
                'the completed authorized operation carries no validated recovery outcome',
                'do not retry mutation; reconcile the target completion evidence'
            );
        }
        RecoveryOutcome::validate($outcome);
        $outcomeExpected = [
            'authorization_digest' => $authorizationDigest,
            'environment' => (string) $plan['environment'],
            'operation_id' => (string) $plan['operation_id'],
            'plan_digest' => (string) $plan['plan_digest'],
            'subject_digest' => (string) $plan['subject_digest'],
        ];
        foreach ($outcomeExpected as $field => $value) {
            if (!is_string($outcome[$field] ?? null)
                || !hash_equals($value, (string) $outcome[$field])) {
                throw new CommandRefusalException(
                    'recovery_outcome_evidence_invalid',
                    'the completed authorized operation changed its frozen recovery identity',
                    'do not retry mutation; reconcile the target completion evidence'
                );
            }
        }
        if (($outcome['recovered'] ?? null) === true) {
            $targetAfter = is_array($outcome['target_after'] ?? null) ? $outcome['target_after'] : [];
            $targetExpected = [
                'artifact_hash' => (string) $plan['target']['artifact_hash'],
                'generation' => (int) $plan['target']['generation'],
                'receipt_id' => (string) $plan['target']['receipt_id'],
                'rollback_target_id' => (string) $plan['target']['rollback_target_id'],
            ];
            foreach ($targetExpected as $field => $value) {
                if (!array_key_exists($field, $targetAfter) || $targetAfter[$field] !== $value) {
                    throw new CommandRefusalException(
                        'recovery_outcome_evidence_invalid',
                        'the completed recovery outcome names a different target generation or receipt',
                        'do not retry mutation; reconcile the target completion evidence'
                    );
                }
            }
        }

        return $outcome;
    }

    /**
     * Verify, consume, execute and durably complete one frozen recovery.
     * Status is read before authorization expiry so an exact completed replay
     * remains observable. An absent consumption alone may proceed; the plan is
     * re-observed both before signature verification and immediately before
     * the one-time target consumption that precedes the first rollback event.
     *
     * @param array<string,mixed> $flags
     * @param callable():string $clock
     * @return array<string,mixed>
     */
    private static function executeAuthorized(
        EnvironmentDriver $driver,
        array $flags,
        callable $clock
    ): array {
        if (!$driver instanceof RecoveryTransport || !$driver->carriesRollbackAuthority()) {
            throw new CommandRefusalException(
                'recovery_authority_unavailable',
                'this target carries no verified rollback authority for authorized recovery execution',
                'execute the frozen recovery only on its adopted authority-bearing target'
            );
        }
        $plan = RecoveryPlan::read((string) $flags['plan_path']);
        if (!hash_equals((string) $plan['environment'], $driver->name())) {
            throw new CommandRefusalException(
                'recovery_plan_environment_mismatch',
                'the frozen recovery plan names a different environment',
                'execute the plan against the exact environment it names'
            );
        }
        $envelope = OperationAuthorization::readEnvelope((string) $flags['authorization_path']);
        $authorizationDigest = OperationAuthorization::envelopeDigest($envelope);

        $prior = self::priorExecutionOutcome($driver, $plan, $authorizationDigest);
        if ($prior !== null) {
            return $prior;
        }
        $elected = TargetOperationStore::statusForSubject(
            $driver,
            RecoveryPlan::authorizationSubject($plan)
        );
        if ($elected !== null) {
            $sameAuthorization = hash_equals(
                $authorizationDigest,
                (string) ($elected['authorization_digest'] ?? '')
            );
            if ($sameAuthorization) {
                throw new CommandRefusalException(
                    'recovery_reconciliation_required',
                    'the elected recovery authorization has incomplete target control evidence',
                    'do not retry recovery; reconcile this exact operation from target control evidence'
                );
            }
            if (is_array($elected['completion'] ?? null)) {
                throw new CommandRefusalException(
                    'recovery_authorization_already_completed',
                    'a different authorization already completed this exact frozen recovery operation',
                    'query or replay the elected authorization; this envelope did not execute and remains unconsumed'
                );
            }
            throw new CommandRefusalException(
                'recovery_reconciliation_required',
                'a different authorization already won this exact frozen recovery operation without completion',
                'do not consume new authority or retry recovery; reconcile the elected target operation'
            );
        }

        // This first observation avoids asking an actor verifier to bless a
        // target already known to have drifted. The second observation below
        // closes policy/target drift between signature verification and
        // target-side one-time consumption.
        self::reverifyPreparation($driver, $plan, $clock);
        $siteRepo = AssessCommand::siteRepo(getcwd() ?: '.');
        $localTrust = OperationAuthorization::trust($siteRepo);
        $trust = TargetOperationStore::readAuthorityPolicy($driver);
        if (!hash_equals(OperationAuthorization::trustDigest($localTrust), OperationAuthorization::trustDigest($trust))) {
            throw new CommandRefusalException(
                'recovery_target_authority_policy_mismatch',
                'the target authority policy does not match the policy this recovery would bind',
                'explicitly review and sync the intended target authority policy, then prepare recovery again'
            );
        }
        $verified = OperationAuthorization::verify(
            $envelope,
            RecoveryPlan::authorizationSubject($plan),
            $trust,
            $clock()
        );

        try {
            $profile = new VerifiedRollbackProfile($driver);
            $profile->assertAuthorizedRecoveryResumable((array) $plan['target']);
        } catch (\Throwable $error) {
            throw new CommandRefusalException(
                'recovery_resume_ineligible',
                'the exact active rollback state cannot resume the frozen ordered recovery path',
                'do not consume actor authority; reconcile the target operation/event evidence, then prepare again',
                [['evidence_sha256' => hash('sha256', $error::class . "\0" . $error->getMessage())]]
            );
        }

        $reverified = self::reverifyPreparation($driver, $plan, $clock);
        $finalAuthorizationAt = $clock();
        $verified = OperationAuthorization::verify(
            $envelope,
            RecoveryPlan::authorizationSubject($plan),
            OperationAuthorization::trust($siteRepo),
            $finalAuthorizationAt
        );

        // No controller-side target observation or policy read belongs after
        // this final trust/signature/grant/expiry check. `consume()` holds the
        // target's rollback lock while it rechecks frozen facts, identity and
        // target clock and elects the operation tuple immediately here.
        $consumed = TargetOperationStore::consume(
            $driver,
            $verified,
            $envelope,
            RecoveryPlan::authorizationSubject($plan),
            self::recoveryConsumptionPrecondition($plan)
        );
        if ($consumed['replayed'] === true) {
            // Another controller won after our initial status read. Never join
            // its mutation window: completion is returned, absence reconciles.
            $replay = self::priorExecutionOutcome($driver, $plan, $authorizationDigest);
            if ($replay !== null) return $replay;
            throw new CommandRefusalException(
                'recovery_reconciliation_required',
                'the recovery authorization was consumed concurrently without a terminal outcome',
                'do not execute recovery; reconcile the target-private operation record'
            );
        }

        try {
            $execution = $profile->recoverAuthorized((array) $plan['target']);
            $targetAfter = self::authorizedTargetAfter($execution, $plan);
            $outcome = RecoveryOutcome::build([
                'authorization_digest' => $authorizationDigest,
                'environment' => (string) $plan['environment'],
                'failure' => null,
                'operation_id' => (string) $plan['operation_id'],
                'plan_digest' => (string) $plan['plan_digest'],
                'reverified' => $reverified,
                'status' => RecoveryOutcome::RECOVERED,
                'steps' => $execution['steps'],
                'subject_digest' => (string) $plan['subject_digest'],
                'target_after' => $targetAfter,
                'target_after_sha256' => hash('sha256', \WPrism\Canon::encode($targetAfter)),
                'verification_sha256' => (string) $execution['verification_sha256'],
            ]);
        } catch (\Throwable $error) {
            $outcome = self::reconciliationOutcome(
                $plan,
                $authorizationDigest,
                $reverified,
                $error
            );
        }

        TargetOperationStore::complete($driver, (array) $consumed['consumption'], $outcome);

        return $outcome;
    }

    /**
     * Freeze the target-owned facts whose final read must linearize with the
     * one-time operation election. Git writers serialize on the target-private
     * repository lock and rollback-control writers serialize on target.lock,
     * so the target command can hold both while it rechecks these bytes, target
     * HEAD and both target/actor clocks, then publishes the tuple election. A
     * changed byte therefore leaves authority unconsumed.
     *
     * @param array<string,mixed> $plan
     * @return array{files:list<array{bytes:?int,path:string,sha256:string}>,format:string,locks:list<string>,not_after:string,ordered_file_hashes:list<array{path:string,sha256:string}>,repository_head:string}
     */
    private static function recoveryConsumptionPrecondition(array $plan): array {
        RecoveryPlan::validate($plan);
        $receiptId = (string) $plan['target']['receipt_id'];
        $policyDigest = (string) $plan['authority_policy_digest'];
        $files = [
            [
                'bytes' => null,
                'path' => '.wprism/authority/authorities.json',
                'sha256' => substr($policyDigest, 7),
            ],
            [
                'bytes' => null,
                'path' => '.wprism/control/target.json',
                'sha256' => (string) $plan['target']['target_record_sha256'],
            ],
            [
                'bytes' => (int) $plan['checkpoint']['bytes'],
                'path' => '.wprism/rollback/' . $receiptId . '/artifacts/checkpoint.enc',
                'sha256' => (string) $plan['checkpoint']['sha256'],
            ],
            [
                'bytes' => null,
                'path' => '.wprism/rollback/' . $receiptId . '/receipt.json',
                'sha256' => (string) $plan['target']['receipt_envelope_sha256'],
            ],
        ];
        usort($files, static fn (array $left, array $right): int => $left['path'] <=> $right['path']);

        return [
            'files' => $files,
            'format' => TargetOperationStore::PRECONDITION_FORMAT,
            'locks' => ['.wprism/control/target.lock'],
            'not_after' => (string) $plan['target']['claim_expires_at'],
            'ordered_file_hashes' => [[
                'path' => '.wprism/rollback/' . $receiptId . '/events',
                'sha256' => (string) $plan['target']['event_chain_sha256'],
            ]],
            'repository_head' => (string) $plan['target_head'],
        ];
    }

    /**
     * @param array<string,mixed> $execution
     * @param array<string,mixed> $plan
     * @return array<string,mixed>
     */
    private static function authorizedTargetAfter(array $execution, array $plan): array {
        $status = (array) ($execution['status'] ?? []);
        $audit = (array) ($execution['audit'] ?? []);
        $target = [
            'artifact_hash' => (string) ($status['artifact_hash'] ?? ''),
            'event_chain_sha256' => (string) ($audit['event_chain_sha256'] ?? ''),
            'exclusion_state' => (string) ($status['exclusion_state'] ?? ''),
            'generation' => (int) ($status['generation'] ?? 0),
            'head_event_sha256' => (string) ($status['head_event_sha256'] ?? ''),
            'receipt_id' => (string) ($status['receipt_id'] ?? ''),
            'rollback_target_id' => (string) ($status['target_id'] ?? ''),
            'sequence' => (int) ($status['sequence'] ?? 0),
            'state' => (string) ($status['state'] ?? ''),
            'target_record_sha256' => (string) ($audit['target_record_sha256'] ?? ''),
            'terminal' => $status['terminal'] ?? null,
        ];
        $expected = [
            'artifact_hash' => (string) $plan['target']['artifact_hash'],
            'generation' => (int) $plan['target']['generation'],
            'receipt_id' => (string) $plan['target']['receipt_id'],
            'rollback_target_id' => (string) $plan['target']['rollback_target_id'],
            'state' => 'rolled_back',
            'terminal' => true,
            'exclusion_state' => 'released',
        ];
        foreach ($expected as $field => $value) {
            if (($target[$field] ?? null) !== $value) {
                throw new \RuntimeException("authorized recovery terminal evidence changed $field");
            }
        }

        return $target;
    }

    /**
     * A post-consumption failure is ambiguous by default. Publishing this
     * validated record prevents an automatic retry from turning uncertainty
     * into a second mutation; target evidence must be reconciled first.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $reverified
     * @return array<string,mixed>
     */
    private static function reconciliationOutcome(
        array $plan,
        string $authorizationDigest,
        array $reverified,
        \Throwable $error
    ): array {
        $steps = [];
        foreach (RecoveryOutcome::RECOVERY_STEPS as $index => $step) {
            $steps[] = [
                'input_sha256' => hash(
                    'sha256',
                    (string) $plan['plan_digest'] . "\0" . $step . "\0" . $error::class . "\0" . $error->getMessage()
                ),
                'result_sha256' => null,
                'status' => $index === 0 ? 'ambiguous' : 'not_started',
                'step' => $step,
            ];
        }

        return RecoveryOutcome::build([
            'authorization_digest' => $authorizationDigest,
            'environment' => (string) $plan['environment'],
            'failure' => [
                'next_action' => 'reconcile',
                'reason_code' => 'recovery_execution_ambiguous',
                'remediation' => 'inspect target rollback and operation-control evidence; do not retry mutation',
            ],
            'operation_id' => (string) $plan['operation_id'],
            'plan_digest' => (string) $plan['plan_digest'],
            'reverified' => $reverified,
            'status' => RecoveryOutcome::RECONCILE_REQUIRED,
            'steps' => $steps,
            'subject_digest' => (string) $plan['subject_digest'],
            'target_after' => null,
            'target_after_sha256' => null,
            'verification_sha256' => null,
        ]);
    }

    /**
     * Build a read-only frozen recovery plan. No writer-exclusion assertion is
     * accepted here because no recovery mutation starts in this invocation.
     *
     * @param array<string,mixed> $catalog
     * @param array<string,mixed> $flags
     * @return array<string,mixed>
     */
    private static function prepare(
        EnvironmentDriver $transport,
        array $catalog,
        array $flags,
        string $now
    ): array {
        $row = CheckpointCatalog::find($catalog, (string) $flags['restore']);
        if ($row === null) {
            throw new CommandRefusalException(
                'checkpoint_unknown',
                'no checkpoint with that receipt id is active on this target',
                'run wprism recover <env> --list and prepare one of the receipt ids it prints'
            );
        }
        if (RetainedCheckpoints::isRetained($row)) {
            throw new CommandRefusalException(
                'recovery_checkpoint_identity_incomplete',
                'this retained checkpoint has file and artifact identity but no signed target generation or receipt',
                'use the legacy operator-directed restore while it remains available, or prepare a checkpoint '
                    . 'whose receipt binds target, generation, scope, and encrypted bytes',
                [['missing_fields' => ['generation', 'receipt_payload_sha256', 'target_id']]]
            );
        }
        if (($row['kind'] ?? null) !== CheckpointCatalog::KIND_VERIFIED) {
            throw new CommandRefusalException(
                'recovery_checkpoint_identity_incomplete',
                'this checkpoint kind has no executable full-recovery identity in the prepare v1 contract',
                'select a nonterminal verified-promotion receipt; scoped and terminal receipts remain fail-closed'
            );
        }

        $resolved = self::claim($row);
        $observation = self::observePreparation($transport, $row, $resolved['claim']);

        return RecoveryPlan::build($observation + [
            'operation_id' => (string) $flags['operation_id'],
            'prepared_at' => $now,
        ]);
    }

    /**
     * Observe every execution-bearing recovery fact without changing target or
     * controller state.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $claim
     * @return array<string,mixed>
     */
    private static function observePreparation(
        EnvironmentDriver $transport,
        array $row,
        array $claim
    ): array {
        if (!$transport instanceof RecoveryTransport || !$transport->carriesRollbackAuthority()) {
            throw new CommandRefusalException(
                'recovery_authority_unavailable',
                'this transport carries no rollback authority evidence from which to freeze a recovery subject',
                'prepare recovery on an adopted target whose signed rollback authority is available'
            );
        }
        try {
            $evidence = RollbackAuthority::activeEvidence($transport);
            $audit = RollbackAuthority::audit($transport);
        } catch (CommandRefusalException $refusal) {
            throw $refusal;
        } catch (\Throwable) {
            throw new CommandRefusalException(
                'recovery_subject_evidence_unavailable',
                'the target could not publish one complete verified recovery subject',
                'repair the rollback authority evidence, then prepare recovery again'
            );
        }
        $receipt = (array) $evidence['receipt'];
        $status = (array) $evidence['status'];
        self::assertSelectedEvidence($row, $receipt, $status, $audit);
        $receiptFormat = $receipt['format'] ?? null;
        if (!in_array(
                $receiptFormat,
                [RollbackControl::RECEIPT_FORMAT, RollbackControl::VERIFIED_PROMOTION_RECEIPT_FORMAT],
                true
            )
            || ($receiptFormat === RollbackControl::VERIFIED_PROMOTION_RECEIPT_FORMAT
                && ($receipt['allow_deletes'] ?? null) !== true)
            || ($status['terminal'] ?? null) !== false
            || !in_array((string) ($status['state'] ?? ''), RecoveryPlan::ELIGIBLE_STATES, true)) {
            throw new CommandRefusalException(
                'recovery_checkpoint_state_ineligible',
                'the selected receipt has no nonterminal full-recovery transition in this build',
                'select an eligible nonterminal verified-promotion generation; terminal and scoped receipts '
                    . 'require a separate recovery-operation state machine'
            );
        }

        $checkpoint = self::signedCheckpointIdentity($transport, (string) $receipt['receipt_id']);
        $topology = self::preparationTopology($transport);
        $head = self::targetHead($transport);
        if ($head === null) {
            throw new CommandRefusalException(
                'recovery_target_head_unknown',
                'the target could not publish the exact code head recovery would run against',
                'repair the target Git checkout, then prepare recovery again'
            );
        }

        $siteRepo = AssessCommand::siteRepo(getcwd() ?: '.');
        $trust = OperationAuthorization::trust($siteRepo);
        $targetTrust = TargetOperationStore::readAuthorityPolicy($transport);
        if (!hash_equals(OperationAuthorization::trustDigest($trust), OperationAuthorization::trustDigest($targetTrust))) {
            throw new CommandRefusalException(
                'recovery_target_authority_policy_mismatch',
                'the target authority policy does not match the policy this recovery would bind',
                'explicitly review and sync the intended target authority policy, then prepare recovery again'
            );
        }
        $operationTargetId = TargetOperationStore::readIdentity($transport);

        return [
            'authority_policy_digest' => OperationAuthorization::trustDigest($targetTrust),
            'checkpoint' => [
                'bytes' => $checkpoint['bytes'],
                'created_at' => (string) $receipt['created_at'],
                'encryption_key_id' => (string) $receipt['encryption_key_id'],
                'id' => (string) $receipt['receipt_id'],
                'kind' => CheckpointCatalog::KIND_VERIFIED,
                'metadata_sha256' => (string) $receipt['checkpoint_sha256'],
                'retention_until' => (string) $receipt['retention_until'],
                'sha256' => $checkpoint['sha256'],
            ],
            'claim' => $claim,
            'environment' => $transport->name(),
            'required_grants' => ['business_owner', 'operator_confirmation'],
            'scope' => [
                'adapter_versions_sha256' => (string) ($receipt['adapter_versions_sha256'] ?? ''),
                'allow_deletes' => $receiptFormat === RollbackControl::VERIFIED_PROMOTION_RECEIPT_FORMAT
                    ? true
                    : null,
                'code_release_metadata_sha256' => $receipt['code_release_metadata_sha256'] ?? null,
                'effects_metadata_sha256' => $receipt['lifecycle_receipts_sha256'] ?? null,
                'ledger_session_sha256' => (string) ($receipt['ledger_session_sha256'] ?? ''),
                'prior_code_descriptor_sha256' => $receipt['prior_code_descriptor_sha256'] ?? null,
                'prior_verifier_inputs_sha256' => (string) ($receipt['prior_verifier_inputs_sha256'] ?? ''),
                'resources' => RecoveryClaim::RESOURCES,
                'resources_inventory_sha256' => (string) ($receipt['resources_inventory_sha256'] ?? ''),
                'runtime_fingerprints_sha256' => (string) ($receipt['runtime_fingerprints_sha256'] ?? ''),
                'scope_hash' => null,
                'uploads_inventory_sha256' => $receipt['uploads_inventory_sha256'] ?? null,
            ],
            'target' => [
                'artifact_hash' => (string) $receipt['artifact_hash'],
                'claim_epoch' => (int) $status['claim_epoch'],
                'claim_expires_at' => (string) $status['claim_expires_at'],
                'claimant' => (string) $status['claimant'],
                'event_chain_sha256' => (string) $audit['event_chain_sha256'],
                'generation' => (int) $receipt['generation'],
                'head_event_sha256' => (string) $status['head_event_sha256'],
                'operation_target_id' => $operationTargetId,
                'owner' => (string) $receipt['owner'],
                'receipt_envelope_sha256' => (string) $audit['receipt_sha256'],
                'receipt_id' => (string) $receipt['receipt_id'],
                'receipt_payload_sha256' => hash('sha256', CanonicalJson::encode($receipt)),
                'rollback_target_id' => (string) $receipt['target_id'],
                'sequence' => (int) $status['sequence'],
                'state' => (string) $status['state'],
                'target_record_sha256' => (string) $audit['target_record_sha256'],
                'terminal' => false,
            ],
            'target_head' => $head,
            'topology' => $topology,
        ];
    }

    /**
     * The catalog selection and both target-locked reads must still name one
     * generation. A race here emits no plan and drives no transition.
     *
     * @param array<string,mixed> $row
     * @param array<string,mixed> $receipt
     * @param array<string,mixed> $status
     * @param array<string,mixed> $audit
     */
    private static function assertSelectedEvidence(
        array $row,
        array $receipt,
        array $status,
        array $audit
    ): void {
        $same = (string) ($row['id'] ?? '') === (string) ($receipt['receipt_id'] ?? '')
            && (int) ($row['generation'] ?? 0) === (int) ($receipt['generation'] ?? 0)
            && (string) ($row['artifact_hash'] ?? '') === (string) ($receipt['artifact_hash'] ?? '')
            && (string) ($row['owner'] ?? '') === (string) ($receipt['owner'] ?? '')
            && (string) ($status['receipt_id'] ?? '') === (string) ($receipt['receipt_id'] ?? '')
            && (int) ($status['generation'] ?? 0) === (int) ($receipt['generation'] ?? 0)
            && (string) ($status['target_id'] ?? '') === (string) ($receipt['target_id'] ?? '')
            && (string) ($audit['receipt_id'] ?? '') === (string) ($receipt['receipt_id'] ?? '')
            && (int) ($audit['generation'] ?? 0) === (int) ($receipt['generation'] ?? 0)
            && (string) ($audit['target_id'] ?? '') === (string) ($receipt['target_id'] ?? '')
            && (string) ($audit['state'] ?? '') === (string) ($status['state'] ?? '')
            && count((array) ($audit['events'] ?? [])) === (int) ($status['sequence'] ?? 0);
        if (!$same) {
            throw new CommandRefusalException(
                'recovery_subject_unstable',
                'the selected checkpoint changed while its recovery subject was being observed',
                'run wprism recover <env> --list, then prepare the generation that is stable now'
            );
        }
    }

    /** @return array{bytes:int,sha256:string} */
    private static function signedCheckpointIdentity(
        RecoveryTransport $transport,
        string $receiptId
    ): array {
        if (preg_match('/^[a-f0-9]{32,64}$/D', $receiptId) !== 1) {
            throw new CommandRefusalException(
                'recovery_checkpoint_identity_incomplete',
                'the signed receipt id cannot safely identify checkpoint bytes',
                'repair the signed rollback receipt before preparing recovery'
            );
        }
        $path = rtrim($transport->repoPath(), '/') . '/.wprism/rollback/'
            . $receiptId . '/artifacts/checkpoint.enc';
        $program = <<<'PHP'
$path = $argv[1] ?? '';
if ($path === '' || $path[0] !== '/' || is_link($path) || !is_file($path)) {
    fwrite(STDERR, "checkpoint\n"); exit(20);
}
$hash = hash_file('sha256', $path);
$bytes = filesize($path);
if (!is_string($hash) || preg_match('/^[a-f0-9]{64}$/D', $hash) !== 1
    || !is_int($bytes) || $bytes < 1) {
    fwrite(STDERR, "identity\n"); exit(21);
}
echo $hash . "\t" . $bytes . "\n";
PHP;
        $script = implode(' ', array_map('escapeshellarg', ['php', '-r', $program, '--', $path]));
        $result = $transport->captureRaw($script);
        $stdout = rtrim((string) ($result['stdout'] ?? ''), "\n");
        $parts = explode("\t", $stdout);
        if (($result['exit'] ?? 1) !== 0 || count($parts) !== 2
            || preg_match('/^[a-f0-9]{64}$/D', $parts[0]) !== 1
            || preg_match('/^[1-9][0-9]*$/D', $parts[1]) !== 1) {
            throw new CommandRefusalException(
                'recovery_checkpoint_identity_incomplete',
                'the encrypted checkpoint bytes are absent, empty, unsafe, or unreadable',
                'repair or recover the exact receipt-bound checkpoint before preparing recovery'
            );
        }

        return ['bytes' => (int) $parts[1], 'sha256' => $parts[0]];
    }

    private static function preparationTopology(EnvironmentDriver $transport): string {
        $topology = $transport->captureWp(CodeDeploy::controlArgs(
            ['eval', 'echo is_multisite() ? "multisite" : "single-site";']
        ));
        $observed = ($topology['exit'] ?? 1) === 0 ? trim((string) ($topology['stdout'] ?? '')) : '';
        if ($observed !== 'single-site') {
            throw new CommandRefusalException(
                $observed === '' ? 'recover_topology_unknown' : 'recover_topology_unsupported',
                $observed === ''
                    ? 'the target could not answer whether it is a single-site installation'
                    : 'this recovery path restores single-site installations only',
                'prepare recovery only for a proven single-site installation'
            );
        }

        return $observed;
    }

    /**
     * Plan, or perform, one prune of the retained release checkpoints.
     *
     * The decision is `CheckpointPrune::plan()`, on the catalog this
     * invocation already read, so the rows a prune names are literally the
     * rows `--list` would have printed. Without `--confirm-prune` this issues
     * ZERO mutating calls — `CheckpointPrune::prune()` is not reached at all —
     * which is what makes "the default is a plan" a property of the code path
     * rather than of a message.
     *
     * @param array<string,mixed> $catalog
     * @param array<string,mixed> $flags
     * @return array<string,mixed> a `CheckpointPrune::FORMAT` document
     */
    private static function prune(EnvironmentDriver $transport, array $catalog, array $flags): array {
        $keep = (int) $flags['prune_retained'];
        $plan = CheckpointPrune::plan($catalog, $keep);
        $confirmed = $flags['confirm_prune'] === true;

        $outcomes = [];
        if ($confirmed) {
            foreach (CheckpointPrune::prune($transport, $plan['delete']) as $outcome) {
                $outcomes[$outcome['id']] = $outcome['status'];
            }
        }

        $ok = true;
        $pruned = [];
        foreach ($plan['delete'] as $row) {
            $id = (string) $row['id'];
            $status = $confirmed
                ? ($outcomes[$id] ?? CheckpointPrune::STATUS_FAILED)
                : CheckpointPrune::STATUS_WOULD_PRUNE;
            $ok = $ok && $status !== CheckpointPrune::STATUS_FAILED;
            $pruned[] = ['created_at' => $row['created_at'], 'id' => $id, 'status' => $status];
        }
        $kept = [];
        foreach ($plan['keep'] as $row) {
            $kept[] = ['created_at' => $row['created_at'], 'id' => (string) $row['id']];
        }

        return [
            'confirmed' => $confirmed,
            'disclosures' => $plan['disclosures'],
            'environment' => $transport->name(),
            'format' => CheckpointPrune::FORMAT,
            'keep' => $keep,
            'kept' => $kept,
            'ok' => $ok,
            'pruned' => $pruned,
        ];
    }

    /**
     * The human view of a prune.
     *
     * The row vocabulary is the outcome, uppercased, in the same shape
     * `CheckpointCatalog::humanLines()` uses for a catalog row: two spaces of
     * indent, the id, then the fact. Disclosures print as `note:` lines for
     * the same reason they do there (:322-324) — the absence a prune leaves
     * behind is printed, not implied.
     *
     * @param array<string,mixed> $document
     * @return list<string>
     */
    private static function pruneLines(array $document, int $limit): array {
        $pruned = (array) $document['pruned'];
        $kept = (array) $document['kept'];
        $confirmed = $document['confirmed'] === true;
        $lines = ['prune retained checkpoints on ' . (string) $document['environment']
            . ': keep ' . (string) $document['keep'] . ' per verb'];
        foreach (array_slice($pruned, 0, $limit) as $row) {
            $lines[] = '  ' . strtoupper((string) $row['status']) . ' ' . (string) $row['id']
                . '  ' . (is_string($row['created_at'] ?? null) ? (string) $row['created_at'] : 'unknown');
        }
        if (count($pruned) > $limit) {
            $lines[] = '  ' . (count($pruned) - $limit) . ' more (use --format=json)';
        }
        $lines[] = $confirmed
            ? 'pruned ' . count($pruned) . ', kept ' . count($kept)
            : 'would prune ' . count($pruned) . ', keep ' . count($kept);
        if (!$confirmed) {
            // The one line that turns a plan into a deletion, printed where
            // the operator just read what it would remove.
            $lines[] = 'nothing was removed: re-run with --confirm-prune to remove exactly the rows above';
        }
        foreach ((array) $document['disclosures'] as $disclosure) {
            $lines[] = 'note: ' . (string) $disclosure;
        }

        return $lines;
    }

    /**
     * Drive one restore.
     *
     * @param array<string,mixed> $catalog
     * @param array<string,mixed> $flags
     * @return array<string,mixed>
     */
    private static function restore(
        EnvironmentDriver $transport,
        array $catalog,
        array $flags,
        bool $json
    ): array {
        $row = CheckpointCatalog::find($catalog, (string) $flags['restore']);
        if ($row === null) {
            throw new CommandRefusalException(
                'checkpoint_unknown',
                'no checkpoint with that receipt id is active on this target',
                'run wprism recover <env> --list and restore one of the receipt ids it prints'
            );
        }
        if ($flags['writers_excluded'] !== true) {
            // §2.5, and `cli/wprism`'s own recovery guidance: the checkpoint
            // contains its temporary promotion lease row, so the exclusion
            // has to be external to the database being imported.
            throw new CommandRefusalException(
                'writer_exclusion_required',
                'recovery imports a database that contains its own promotion lease row, so it cannot start '
                    . 'until every external writer is excluded for the whole recovery window',
                'establish external maintenance/exclusion that prevents every WPrism writer for the full recovery '
                    . 'window, then re-run wprism recover <env> --restore=<id> ' . self::WRITERS_EXCLUDED_FLAG
            );
        }

        // Database-external debt outranks controller capability. A recovery
        // can start operator-directed on one controller and retry from another
        // that has a signing key; profile selection must not route around the
        // exact checkpoint identity already published before reset.
        $resuming = self::checkpointRecoveryFenceActive($transport);
        $providerStatus = CodeDeploy::providerSettlementRecoveryStatus(
            $transport,
            $transport->repoPath()
        );
        if (($providerStatus['exit'] ?? 1) !== 0 || !is_array($providerStatus['summary'] ?? null)) {
            throw new CommandRefusalException(
                'provider_settlement_recovery_identity_unreadable',
                'the database-external provider settlement identity could not be read safely',
                'repair the adopted recovery runtime and durable control directory, then retry the same recovery'
            );
        }
        $providerDebt = $providerStatus['summary']['active'] === true;
        if ($providerDebt) {
            $providerIdentity = $providerStatus['summary'];
            $selectedCheckpoint = self::checkpointPath($transport, $row);
            if (!RetainedCheckpoints::isRetained($row)
                || !hash_equals((string) $providerIdentity['owner'], (string) $row['owner'])
                || !hash_equals((string) $providerIdentity['artifact_hash'], (string) $row['artifact_hash'])
                || !hash_equals(
                    (string) $providerIdentity['checkpoint']['path'],
                    $selectedCheckpoint
                )) {
                throw new CommandRefusalException(
                    'provider_settlement_checkpoint_mismatch',
                    'the selected checkpoint does not own the incomplete adapter provider settlement',
                    'restore the exact retained checkpoint named by the active provider settlement; '
                        . 'a signed receipt or a different retained release cannot clear this debt'
                );
            }
        }
        $signed = !$resuming
            && !$providerDebt
            && $row['kind'] === CheckpointCatalog::KIND_VERIFIED
            && $flags['operator_directed'] !== true
            && $transport instanceof RecoveryTransport
            && $transport->rollbackConfigured();
        if (!$resuming) {
            self::assertSingleSiteTopology($transport);
        }

        $resolved = self::claim($row);
        $claim = $resolved['claim'];
        // Printed BEFORE acting, always, in both channels: the operator has
        // to read what recovery does not restore while they can still stop.
        // The checkpoint instant follows the claim rather than sitting inside
        // it — the claim is the byte-identical document §2.5 requires, and a
        // clock value in it would change the frozen plan's identity every
        // second (RecoveryClaim's docblock).
        if (!$json) {
            foreach (RecoveryClaim::humanLines($claim, $flags['limit']) as $line) {
                echo $line . "\n";
            }
            echo '  ' . self::checkpointLine($resolved) . "\n";
        }

        // The signed profile needs the Ed25519 secret that stays
        // on the controller (`RollbackAuthority::__construct()` refuses
        // without it), so a receipt this machine cannot sign against is
        // recovered the operator-directed way — the same conclusion
        // `cli/wprism`'s promotion path reaches when `rollbackConfigured()` is
        // false. `--operator-directed` forces that path explicitly.
        self::assertCodeFirst($transport, $row, $signed);

        $steps = $signed
            ? self::signedRollback(self::authorityTransport($transport))
            : self::operatorDirected($transport, $row, $resuming);

        return [
            'checkpoint' => $row,
            'checkpoint_at' => $resolved['checkpoint_at'],
            'checkpoint_source' => $resolved['checkpoint_source'],
            'claim' => $claim,
            'environment' => $transport->name(),
            'format' => 'wprism-recovery-outcome/v1',
            'recovered' => $steps['recovered'],
            'steps' => $steps['steps'],
        ];
    }

    /**
     * The signed, provider-backed path: the profile's own rollback.
     *
     * Every ordering decision — effects, uploads, code, database, prior
     * verification, exclusion release — belongs to
     * `VerifiedRollbackProfile::rollback()`, which is the same call
     * `cli/wprism`'s `promote_verified_failed()` makes. Recomputing which
     * boundaries were crossed here would be a second, divergent answer to a
     * question the signed receipt already answers.
     *
     * @return array{recovered:bool,steps:list<array<string,mixed>>}
     */
    private static function signedRollback(RecoveryTransport $transport): array {
        $profile = new VerifiedRollbackProfile($transport);
        try {
            $status = $profile->rollback(true, true, true);
        } catch (\Throwable $failure) {
            unset($failure);

            return ['recovered' => false, 'steps' => [[
                'detail' => 'the signed generation remains nonterminal with traffic excluded; resume it, '
                    . 'do not start operator-directed recovery',
                'ok' => false,
                'step' => 'signed-rollback',
            ]]];
        }

        return ['recovered' => ($status['state'] ?? '') === 'rolled_back', 'steps' => [[
            'detail' => 'prior world verified; exclusion released',
            'ok' => true,
            'step' => 'signed-rollback',
        ]]];
    }

    /** Read database-external debt before choosing any recovery profile. */
    private static function checkpointRecoveryFenceActive(EnvironmentDriver $transport): bool {
        $recoveryFence = CodeDeploy::checkpointRecoveryFence(
            $transport,
            $transport->repoPath()
        );
        if ((int) ($recoveryFence['exit'] ?? 1) === 75) {
            return true;
        }
        if ((int) ($recoveryFence['exit'] ?? 1) !== 0
            || trim((string) ($recoveryFence['stdout'] ?? '')) !== 'clear') {
            throw new CommandRefusalException(
                'checkpoint_recovery_state_unknown',
                'the durable checkpoint recovery state could not be read safely',
                'repair the target .wprism/control boundary, then retry this exact retained-checkpoint restore'
            );
        }
        return false;
    }

    private static function assertSingleSiteTopology(EnvironmentDriver $transport): void {
        $topology = $transport->captureWp(CodeDeploy::controlArgs(
            ['eval', 'echo is_multisite() ? "multisite" : "single-site";']
        ));
        $observed = ($topology['exit'] ?? 1) === 0 ? trim((string) ($topology['stdout'] ?? '')) : '';
        if ($observed === 'single-site') {
            return;
        }
        throw new CommandRefusalException(
            $observed === '' ? 'recover_topology_unknown' : 'recover_topology_unsupported',
            $observed === ''
                ? 'the target could not answer whether it is a single-site installation, and recovery imports a whole database'
                : 'this recovery path restores single-site installations only',
            'recover a single-site installation; a whole-database import on a network restores every blog and '
                . 'the network tables, which is outside the certified v1 contract'
        );
    }

    /**
     * The operator-directed path: abort → begin → isolated import → final
     * abort, driven rather than printed.
     *
     * @param array<string,mixed> $row a catalog row
     * @return array{recovered:bool,steps:list<array<string,mixed>>}
     */
    private static function operatorDirected(
        EnvironmentDriver $transport,
        array $row,
        bool $resuming
    ): array {
        $owner = (string) $row['owner'];
        $artifactHash = (string) $row['artifact_hash'];
        if (preg_match('/^[a-f0-9]{64}$/D', $artifactHash) !== 1) {
            // A retained checkpoint whose compiled artifact is gone. The lease
            // `promotion-begin` takes is bound to (owner, artifact hash); a
            // lease this command cannot name is a lease it must not take.
            throw new CommandRefusalException(
                'checkpoint_identity_unknown',
                'this checkpoint has no artifact identity, so the promotion lease its recovery needs cannot be named',
                'recover this environment through the provider that owns its backups; a retained checkpoint is '
                    . 'restorable only while its compiled artifact under .wprism/artifacts is present'
            );
        }
        // Proved BEFORE step 1. An absent or truncated checkpoint means there
        // is nothing to import, and finding that out after the lease has been
        // aborted and re-begun would leave the target opened up for a
        // recovery that was never possible.
        $checkpoint = self::checkpointPath($transport, $row);
        $preflight = CodeDeploy::checkpointRecoveryPreflight(
            $transport,
            $transport->repoPath(),
            $checkpoint
        );
        if (($preflight['exit'] ?? 1) !== 0 || !is_array($preflight['summary'] ?? null)) {
            throw new CommandRefusalException(
                'checkpoint_database_target_unverified',
                'this retained checkpoint does not authenticate the currently configured database target',
                'restore DB_HOST, DB_NAME and the WordPress table prefix to the checkpointed target, '
                    . 'then retry the exact retained checkpoint; no recovery lease command was run'
            );
        }
        $databaseTargetSha256 = (string) $preflight['summary']['database_target_sha256'];
        $steps = [];
        $recovered = false;

        // Step 1 stands outside the mandatory-final-abort guarantee on
        // purpose. `cli/wprism`: "do not begin checkpoint recovery until the
        // exact lease cleanup command above succeeds" — expiry lets a
        // DIFFERENT promotion owner recover the target, it does not authorize
        // this restore. Nothing has been reinstated yet either, so there is no
        // lease row for a fourth step to release.
        if (!$resuming) {
            $steps[] = self::step(
                $transport,
                'abort',
                CodeDeploy::recoveryAbortArgs($owner, $artifactHash, $databaseTargetSha256)
            );
            if (!$steps[0]['ok']) {
                return ['recovered' => false, 'steps' => $steps];
            }
        }

        try {
            if (!$resuming) {
                $begin = self::step(
                    $transport,
                    'begin',
                    CodeDeploy::recoveryBeginArgs($owner, $artifactHash, $databaseTargetSha256)
                );
                $steps[] = $begin;
                if (!$begin['ok']) {
                    return ['recovered' => false, 'steps' => $steps];
                }
            }
            $import = self::resultStep(
                'import',
                CodeDeploy::encryptedCheckpointImport(
                    $transport,
                    $transport->repoPath(),
                    $checkpoint,
                    $owner,
                    $artifactHash
                )
            );
            $steps[] = $import;
            $recovered = $import['ok'];
        } finally {
            // Step 4 runs even when the import failed, and even when begin
            // failed after opening the window. That is the whole point of the
            // rule, so it lives in a `finally` where no future early return
            // can route around it.
            $finalAbort = self::step(
                $transport,
                'final-abort',
                CodeDeploy::recoveryAbortArgs($owner, $artifactHash, $databaseTargetSha256)
            );
            // Recovery debt is cleared only after BOTH the import and the
            // restored lease cleanup succeeded. A failed reset/open/import
            // retains the external checkpoint identity, so an exact retry is
            // admitted and a substitute checkpoint is refused before reset.
            if ($recovered && $finalAbort['ok']) {
                $completed = CodeDeploy::completeCheckpointRecovery(
                    $transport,
                    $transport->repoPath(),
                    $checkpoint,
                    $owner,
                    $artifactHash
                );
                if (($completed['exit'] ?? 1) !== 0) {
                    $finalAbort = [
                        'detail' => 'the restored lease was aborted, but durable checkpoint recovery debt '
                            . 'could not be cleared; retry this exact checkpoint recovery',
                        'ok' => false,
                        'step' => 'final-abort',
                    ];
                } else {
                    $finalAbort['detail'] = 'completed; durable checkpoint recovery debt cleared';
                }
            }
            $steps[] = $finalAbort;
        }
        $final = $steps[count($steps) - 1];

        return ['recovered' => $recovered && $final['ok'], 'steps' => $steps];
    }

    /**
     * Code-first ordering, enforced rather than advised (§2.5).
     *
     * @param array<string,mixed> $row
     */
    private static function assertCodeFirst(EnvironmentDriver $transport, array $row, bool $signed): void {
        $covers = array_values(array_map('strval', (array) ($row['covers'] ?? [])));
        // A retained checkpoint's own row carries no code evidence — it is a
        // database file — so the code question is asked of the frozen plan
        // for the release that took it: a plan that entered a code lifecycle
        // phase makes this checkpoint one taken around a code phase.
        $aroundCode = in_array(self::CODE_COVERAGE, $covers, true)
            || (RetainedCheckpoints::isRetained($row) && self::planHadCodePhase($row));
        if (!$aroundCode) {
            return;
        }
        if ($signed) {
            // The signed profile restores code itself, in its own order,
            // before the database restore (`VerifiedRollbackProfile::rollback()`).
            return;
        }
        $revision = self::priorCodeRevision($transport, $row);
        if ($revision !== null && self::targetHead($transport) === $revision) {
            return;
        }
        throw new CommandRefusalException(
            'recover_code_not_reconciled',
            'this checkpoint was taken around a code phase, so importing its database before code is reconciled '
                . 'would leave a database describing one code revision underneath another',
            'reconcile or restore the target code to '
                . ($revision === null
                    ? 'the pre-release revision recorded in the frozen authorization plan for this release'
                    : substr($revision, 0, 12))
                . ' first, confirm with wprism status, then re-run wprism recover with the same --restore id',
            [['code_revision_expected' => $revision === null ? 'unknown' : substr($revision, 0, 12)]]
        );
    }

    /**
     * The pre-release code revision this checkpoint belongs to.
     *
     * Read out of the frozen authorization plan whose `artifact_hash` matches
     * the receipt's — the plan is the durable record of what the release was
     * authorized to move FROM, and `.wprism/releases/` is committed for exactly
     * this reason (MUP §3.1).
     *
     * @param array<string,mixed> $row
     */
    private static function priorCodeRevision(EnvironmentDriver $transport, array $row): ?string {
        unset($transport);
        foreach (self::frozenPlans() as $plan) {
            if ((string) ($plan['artifact_hash'] ?? '') !== (string) ($row['artifact_hash'] ?? '')) {
                continue;
            }
            $revision = (string) ($plan['code_revision_from'] ?? '');
            if (RecoveryPlan::isGitObjectId($revision)) {
                return $revision;
            }
        }

        return null;
    }

    /**
     * Whether the frozen authorization plan for this checkpoint's release
     * entered a code lifecycle phase (`AuthorizationPlan`'s
     * `scope.code.lifecycle_phases`), matched by artifact hash. Unknown
     * (no matching plan) is treated as no code phase: the plan is the only
     * durable record, and a checkpoint with no plan at all is one an
     * operator-directed release never froze, which the ordinary lease
     * machinery already refuses to import under a different code revision.
     *
     * @param array<string,mixed> $row
     */
    private static function planHadCodePhase(array $row): bool {
        foreach (self::frozenPlans() as $plan) {
            if ((string) ($plan['artifact_hash'] ?? '') !== (string) ($row['artifact_hash'] ?? '')) {
                continue;
            }
            $phases = $plan['scope']['code']['lifecycle_phases'] ?? [];
            if (is_array($phases) && array_intersect($phases, AuthorizationPlan::CODE_LIFECYCLE_PHASES) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * The claim to print and the instant that goes beside it.
     *
     * The claim is the frozen plan's own when one matches this checkpoint, so
     * the two printings are byte-identical (§2.5), and a freshly built one
     * otherwise. It carries no clock value at all — the boundary sentence
     * names the checkpoint, `RecoveryClaim`'s docblock says why the instant
     * cannot live inside a document the plan digests — so the instant is
     * resolved separately here.
     *
     * Both sources are real and they are ordered by which one is evidence
     * about THIS checkpoint: the receipt's own `created_at` first, because it
     * is when the checkpoint was actually pinned; the frozen plan's
     * `recovery_profile.checkpoint_at` second, because it is the release-start
     * instant the operator was shown at authorization. A full promotion
     * receipt publishes no creation time at all (`CheckpointCatalog`'s
     * `DISCLOSURE_NO_CREATION_TIME`), which is exactly the case the plan
     * covers; when neither exists the caller prints the absence rather than a
     * derived time.
     *
     * Split into an impure reader and a pure resolver for the same reason
     * `RecoveryProfileSelection` splits prove/decide: the fallback ORDER is
     * the part that has to be provable offline, and the case that exercises
     * it — a full promotion receipt, which publishes no creation time at all
     * — cannot be reached through a fixture that has one.
     *
     * @param array<string,mixed> $row
     * @return array{claim:array<string,mixed>,checkpoint_at:?string,checkpoint_source:string}
     */
    private static function claim(array $row): array {
        return self::resolveClaim($row, self::frozenPlans());
    }

    /**
     * The pure half: a catalog row and the frozen plans this site repository
     * holds go in; one claim and one dated instant come out.
     *
     * @param array<string,mixed> $row
     * @param list<array<string,mixed>> $frozenPlans
     * @return array{claim:array<string,mixed>,checkpoint_at:?string,checkpoint_source:string}
     */
    public static function resolveClaim(array $row, array $frozenPlans): array {
        $receiptAt = is_string($row['created_at'] ?? null) && $row['created_at'] !== ''
            ? (string) $row['created_at']
            : null;
        foreach ($frozenPlans as $plan) {
            if ((string) ($plan['artifact_hash'] ?? '') !== (string) ($row['artifact_hash'] ?? '')) {
                continue;
            }
            $claim = $plan['recovery_profile']['claim'] ?? null;
            if (is_array($claim)) {
                RecoveryClaim::validate($claim);
                $planAt = $plan['recovery_profile']['checkpoint_at'] ?? null;

                return self::withCheckpoint(
                    $claim,
                    $receiptAt,
                    is_string($planAt) && $planAt !== '' ? $planAt : null
                );
            }
        }

        return self::withCheckpoint(RecoveryClaim::build([
            'covered_resources' => array_values(array_map('strval', (array) ($row['covers'] ?? []))),
            'profile' => $row['kind'] === CheckpointCatalog::KIND_VERIFIED
                ? RecoveryClaim::VERIFIED_AUTOMATIC
                : RecoveryClaim::OPERATOR_DIRECTED,
        ]), $receiptAt, null);
    }

    /**
     * @param array<string,mixed> $claim
     * @return array{claim:array<string,mixed>,checkpoint_at:?string,checkpoint_source:string}
     */
    private static function withCheckpoint(array $claim, ?string $receiptAt, ?string $planAt): array {
        if ($receiptAt !== null) {
            return ['checkpoint_at' => $receiptAt, 'checkpoint_source' => self::SOURCE_RECEIPT, 'claim' => $claim];
        }
        if ($planAt !== null) {
            return ['checkpoint_at' => $planAt, 'checkpoint_source' => self::SOURCE_PLAN, 'claim' => $claim];
        }

        return ['checkpoint_at' => null, 'checkpoint_source' => self::SOURCE_NONE, 'claim' => $claim];
    }

    /**
     * The one line printed beside the claim, at both printings.
     *
     * It names its source as well as its value. The two sources answer
     * slightly different questions — when the checkpoint was pinned, versus
     * when the release that took it started — and an operator deciding what
     * they are about to lose is owed the difference rather than a bare
     * timestamp that looks equally authoritative either way.
     *
     * @param array<string,mixed> $outcome a restore() result
     */
    public static function checkpointLine(array $outcome): string {
        $at = $outcome['checkpoint_at'] ?? null;
        if (!is_string($at) || $at === '') {
            return 'checkpoint at: not published by this receipt, and no frozen authorization plan in this '
                . 'site repository records it';
        }

        return 'checkpoint at: ' . $at . (($outcome['checkpoint_source'] ?? '') === self::SOURCE_PLAN
            ? ' (release start, from the frozen authorization plan)'
            : ' (from the checkpoint receipt)');
    }

    /**
     * Every frozen authorization plan in this site repository.
     *
     * @return list<array<string,mixed>>
     */
    private static function frozenPlans(): array {
        try {
            $siteRepo = AssessCommand::siteRepo(getcwd() ?: '.');
        } catch (CommandRefusalException) {
            // Recovery must work from anywhere: an operator recovering a
            // production target may not be standing in the site repository.
            // Without it the claim is rebuilt from the receipt instead.
            return [];
        }
        $paths = glob(rtrim($siteRepo, '/') . '/' . AuthorizationPlan::DIRECTORY . '/*.json');
        $out = [];
        foreach ($paths === false ? [] : $paths as $path) {
            $raw = @file_get_contents($path);
            $decoded = is_string($raw) ? json_decode($raw, true) : null;
            if (!is_array($decoded)) {
                continue;
            }
            try {
                AuthorizationPlan::validate($decoded);
            } catch (\Throwable) {
                continue;
            }
            $out[] = $decoded;
        }

        return $out;
    }

    /**
     * Where the operator-directed checkpoint lives on the target.
     *
     * `cli/wprism`'s `cmd_promote_internal()` writes it at
     * `<repo>/.wprism/checkpoints/promote-<owner>.sql.enc`, and the receipt names the
     * owner, so the path is derived rather than guessed. `DeployCommand::run()`
     * is the second writer, at `<repo>/.wprism/checkpoints/deploy-<owner>.sql.enc`;
     * `RetainedCheckpoints::prefixForRow()` reads which of the two a catalog
     * row came from off the row's own id and returns promote's prefix for a
     * signed receipt, so the verified path resolves to exactly the string it
     * always did. Its presence and
     * non-emptiness are proved before the import: an empty checkpoint is
     * evidence of an interrupted export, not a checkpoint.
     */
    private static function checkpointPath(EnvironmentDriver $transport, array $row): string {
        $owner = (string) ($row['owner'] ?? '');
        if (preg_match(RetainedCheckpoints::OWNER_PATTERN, $owner) !== 1) {
            throw new CommandRefusalException(
                'checkpoint_owner_invalid',
                'the active receipt names an owner this build will not turn into a filesystem path',
                'inspect private operator evidence for this target before recovering'
            );
        }
        // Both sources agree on the path: the release verb wrote it, and the
        // signed receipt's owner names the same file.
        $path = RetainedCheckpoints::checkpointPath(
            $transport->repoPath(),
            $row,
            RetainedCheckpoints::prefixForRow($row)
        );
        $probe = $transport->captureRaw('test -s ' . escapeshellarg($path));
        if (($probe['exit'] ?? 1) !== 0) {
            throw new CommandRefusalException(
                'checkpoint_unavailable',
                'the database checkpoint this receipt refers to is absent or empty on the target, so there is '
                    . 'nothing to import',
                'do not retry: escalate. An absent or truncated checkpoint means the export was interrupted, '
                    . 'and the prior database has to be recovered from provider backups instead'
            );
        }

        return $path;
    }

    /** The target repository's current revision, for the code-first compare. */
    private static function targetHead(EnvironmentDriver $transport): ?string {
        $result = $transport->captureRaw(
            'git -C ' . escapeshellarg($transport->repoPath()) . ' rev-parse HEAD'
        );
        $revision = trim((string) ($result['stdout'] ?? ''));

        return ($result['exit'] ?? 1) === 0
            && RecoveryPlan::isGitObjectId($revision)
            ? $revision
            : null;
    }

    /**
     * One ordered step, run through the agent's own argument builders.
     *
     * A failure the target CLASSIFIED is reported with its reason code and
     * its reviewed remediation; a failure it did not keeps the constant
     * sentence below. Before issue #3506 every failure of all four steps
     * collapsed onto that one sentence, in the human view and in
     * `wprism-recovery-outcome/v1` alike, because `$result['stdout']` was never
     * read — so a deliberate, documented refusal (restoring a checkpoint a
     * later promotion session superseded) reached the operator as an
     * unexplained failure with no next action.
     *
     * @param list<string> $args
     * @return array<string,mixed>
     */
    private static function step(EnvironmentDriver $transport, string $name, array $args): array {
        return self::resultStep($name, $transport->captureWp($args));
    }

    /** @param array<string,mixed> $result @return array<string,mixed> */
    private static function resultStep(string $name, array $result): array {
        $ok = ($result['exit'] ?? 1) === 0;
        $step = [
            'detail' => $ok ? 'completed' : self::STEP_FAILED_DETAIL,
            'ok' => $ok,
            'step' => $name,
        ];
        if ($ok) {
            return $step;
        }
        $refusal = self::targetRefusal($result);
        if ($refusal === null) {
            return $step;
        }

        return [
            'detail' => $refusal['message'],
            'ok' => false,
            'reason_code' => $refusal['reason_code'],
            'remediation' => $refusal['remediation'],
            'step' => $name,
        ];
    }

    /**
     * The target's own refusal for a failed step, when it sent one.
     *
     * The two lease steps are asked in machine mode
     * (`CodeDeploy::recoveryAbortArgs()`/`recoveryBeginArgs()`), so a refusal
     * arrives as a `wprism-command-refusal/v1` object on stdout. Exactly three
     * reviewed fields are lifted out of it — reason code, public message,
     * remediation — because those are the only ones the agent screens as
     * public (agent/src/Kernel/CommandRefusal.php:38-49); its diagnostics are
     * a per-command shape this renderer has no line for.
     *
     * The raw stdout/stderr are deliberately NOT echoed the way
     * `CommandOutput::renderTransportDetail()` echoes promote's cleanup. The
     * operator sentence behind `promotion_abort_session_superseded` names the
     * superseding lease owner token and its 64-hex artifact hash, and MUP
     * §5.2 admits an internal identifier into a human view only when a
     * documented command consumes it — no `wprism` verb takes either. The screen
     * below is the host's own last line before printing: the agent already
     * applied it, and a target this build did not compile is not a reason to
     * take its word.
     *
     * @param array<string,mixed> $result a `captureWp()` result
     * @return ?array{reason_code:string,message:string,remediation:string}
     */
    private static function targetRefusal(array $result): ?array {
        $decoded = json_decode(trim((string) ($result['stdout'] ?? '')), true);
        if (!is_array($decoded) || ($decoded['format'] ?? null) !== 'wprism-command-refusal/v1') {
            return null;
        }
        $code = $decoded['reason_code'] ?? $decoded['error'] ?? null;
        $message = $decoded['message'] ?? null;
        $remediation = $decoded['remediation'] ?? null;
        if (!is_string($code) || preg_match('/^[a-z][a-z0-9_]{2,63}$/D', $code) !== 1
            || !is_string($message) || $message === ''
            || !is_string($remediation) || $remediation === ''
            || CommandRefusalException::containsSensitivePublicDetail([$message, $remediation])) {
            return null;
        }

        return ['message' => $message, 'reason_code' => $code, 'remediation' => $remediation];
    }

    /**
     * Only a transport that carries the rollback authority runtime can be
     * driven through `recovery/rollback-control.php`, which is what every
     * signed action is reached through. It is needed for the signed rollback
     * alone; the listing and the operator-directed restore work on every
     * transport.
     *
     * The refusal below stays byte-identical: widening the type widens WHICH
     * transports can answer, never what a transport that cannot answer says.
     */
    private static function authorityTransport(EnvironmentDriver $driver): RecoveryTransport {
        if ($driver instanceof RecoveryTransport) {
            return $driver;
        }
        throw new CommandRefusalException(
            'recovery_authority_unavailable',
            'this transport carries no rollback authority runtime, so a signed rollback cannot be driven here',
            'restore a retained release checkpoint with --restore=<id> instead, or recover this environment '
                . 'through the provider that owns its backups; the signed rollback needs an SSH-adopted target'
        );
    }

    /**
     * @param array<string,mixed> $outcome
     * @return list<string>
     */
    private static function outcomeLines(array $outcome, int $limit): array {
        $lines = ['recover ' . (string) $outcome['environment'] . ': '
            . ($outcome['recovered'] === true ? 'recovered' : 'not recovered')];
        foreach (array_slice((array) $outcome['steps'], 0, $limit) as $step) {
            if (!is_array($step)) {
                continue;
            }
            $lines[] = '  ' . (string) $step['step'] . ': '
                . ($step['ok'] === true ? 'ok' : 'FAILED') . ' — ' . (string) $step['detail'];
            // A classified refusal is the only failure with a next action, so
            // print the code the operator can grep the guides for and the
            // remedy the target itself reviewed. Both are constant, value-free
            // text (MUP §5.2); the sentence naming the superseding lease stays
            // on the target's private operator evidence.
            if (is_string($step['reason_code'] ?? null)) {
                $lines[] = '    reason: ' . $step['reason_code'];
                $lines[] = '    remedy: ' . (string) $step['remediation'];
            }
        }
        $lines[] = 'the claim this recovery was performed under, unchanged:';
        foreach (RecoveryClaim::humanLines($outcome['claim'], $limit) as $line) {
            $lines[] = '  ' . $line;
        }
        $lines[] = '  ' . self::checkpointLine($outcome);

        return $lines;
    }

    /** @param array<string,mixed> $document */
    private static function encode(array $document): string {
        return \WPrism\Canon::encode($document);
    }

    /**
     * The closed flag grammar.
     *
     * @param list<string> $extra
     * @return array<string,mixed>
     */
    private static function flags(array $extra): array {
        $out = [
            'authorization_path' => null,
            'confirm_prune' => false,
            'execute' => false,
            'format_json' => false,
            'limit' => 50,
            'list' => false,
            'operation_id' => null,
            'operator_directed' => false,
            'prepare' => false,
            'plan_path' => null,
            'prune_retained' => null,
            'restore' => null,
            'writers_excluded' => false,
        ];
        $limitSeen = false;
        foreach ($extra as $arg) {
            if (!is_string($arg)) {
                throw self::invalidArguments('recover received a non-string argument');
            }
            $name = str_contains($arg, '=') ? explode('=', $arg, 2)[0] : $arg;
            $value = str_contains($arg, '=') ? substr($arg, strlen($name) + 1) : null;
            switch ($name) {
                case self::PREPARE_VERB:
                    if ($out['prepare'] || $value !== null) {
                        throw self::invalidArguments('prepare is one positional verb and takes no value');
                    }
                    $out['prepare'] = true;
                    break;
                case self::EXECUTE_VERB:
                    if ($out['execute'] || $value !== null) {
                        throw self::invalidArguments('execute is one positional verb and takes no value');
                    }
                    $out['execute'] = true;
                    break;
                case '--list':
                    $out['list'] = true;
                    break;
                case '--restore':
                    if ($out['restore'] !== null || $value === null || $value === '') {
                        throw self::invalidArguments('--restore takes exactly one --restore=<checkpoint> value');
                    }
                    $out['restore'] = $value;
                    break;
                case '--operation-id':
                    if ($out['operation_id'] !== null || $value === null
                        || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:@+\/-]{0,255}$/D', $value) !== 1) {
                        throw self::invalidArguments(
                            '--operation-id takes exactly one safe stable --operation-id=<id> value'
                        );
                    }
                    $out['operation_id'] = $value;
                    break;
                case '--plan':
                    if ($out['plan_path'] !== null || $value === null || $value === '') {
                        throw self::invalidArguments('--plan takes exactly one --plan=<canonical-plan.json> value');
                    }
                    $out['plan_path'] = $value;
                    break;
                case '--authorization':
                    if ($out['authorization_path'] !== null || $value === null || $value === '') {
                        throw self::invalidArguments(
                            '--authorization takes exactly one --authorization=<signed-envelope.json> value'
                        );
                    }
                    $out['authorization_path'] = $value;
                    break;
                case self::WRITERS_EXCLUDED_FLAG:
                    $out['writers_excluded'] = true;
                    break;
                case '--operator-directed':
                    $out['operator_directed'] = true;
                    break;
                case self::PRUNE_RETAINED_FLAG:
                    // The bound is in the GRAMMAR, not in a later check: 0 is
                    // not expressible, so "delete the only before-image" has
                    // no spelling (CheckpointPrune's rule 1).
                    if ($out['prune_retained'] !== null
                        || $value === null
                        || preg_match('/^(?:[1-9]|[1-4][0-9]|50)$/D', $value) !== 1) {
                        throw self::invalidArguments(
                            self::PRUNE_RETAINED_FLAG . ' must be a single value between '
                                . CheckpointPrune::KEEP_MIN . ' and ' . CheckpointPrune::KEEP_MAX
                        );
                    }
                    $out['prune_retained'] = (int) $value;
                    break;
                case self::CONFIRM_PRUNE_FLAG:
                    $out['confirm_prune'] = true;
                    break;
                case '--limit':
                    if ($limitSeen
                        || $value === null
                        || preg_match('/^(?:[1-9]|[1-9][0-9]|1[0-9]{2}|200)$/D', $value) !== 1) {
                        throw self::invalidArguments('--limit must be a single value between 1 and 200');
                    }
                    $limitSeen = true;
                    $out['limit'] = (int) $value;
                    break;
                case '--format':
                    $out['format_json'] = $out['format_json'] || $value === 'json';
                    break;
                case '--json':
                    $out['format_json'] = $out['format_json'] || $arg === '--json';
                    break;
                default:
                    throw self::invalidArguments("recover received an option it does not define: '$name'");
            }
        }
        if ($out['list'] && $out['restore'] !== null) {
            throw self::invalidArguments('--list and --restore are separate requests');
        }
        if ($out['prune_retained'] !== null && ($out['list'] || $out['restore'] !== null)) {
            throw self::invalidArguments(
                self::PRUNE_RETAINED_FLAG . ', --list and --restore are separate requests'
            );
        }
        if ($out['prune_retained'] !== null && $out['writers_excluded']) {
            // Refused rather than ignored. That flag asserts one exact fact —
            // the checkpoint carries its own promotion lease row, so the
            // exclusion has to be external to the database being imported
            // (restore()'s writer_exclusion_required refusal). A prune imports
            // nothing and takes no lease; accepting the assertion here would
            // re-teach it as a generic danger acknowledgement.
            throw self::invalidArguments(
                self::WRITERS_EXCLUDED_FLAG . ' asserts a maintenance window for a whole-database import and '
                    . 'means nothing for a file removal; ' . self::PRUNE_RETAINED_FLAG . ' does not accept it'
            );
        }
        if ($out['confirm_prune'] && $out['prune_retained'] === null) {
            throw self::invalidArguments(
                self::CONFIRM_PRUNE_FLAG . ' confirms a prune, so it requires ' . self::PRUNE_RETAINED_FLAG . '=<keep-n>'
            );
        }
        if ($out['prepare'] && $out['execute']) {
            throw self::invalidArguments('prepare and execute are separate requests');
        }
        if ($out['prepare']) {
            if ($out['restore'] === null || $out['operation_id'] === null || !$out['format_json']) {
                throw self::invalidArguments(
                    'prepare requires --restore=<checkpoint>, --operation-id=<id> and --format=json'
                );
            }
            if ($out['list'] || $out['prune_retained'] !== null || $out['confirm_prune']
                || $out['writers_excluded'] || $out['operator_directed'] || $limitSeen) {
                throw self::invalidArguments(
                    'prepare is a read-only request and does not accept list, prune, restore-execution or limit flags'
                );
            }
            if ($out['plan_path'] !== null || $out['authorization_path'] !== null) {
                throw self::invalidArguments('prepare does not read --plan or --authorization files');
            }
        } elseif ($out['execute']) {
            if ($out['plan_path'] === null || $out['authorization_path'] === null || !$out['format_json']) {
                throw self::invalidArguments(
                    'execute requires --plan=<canonical-plan.json>, --authorization=<signed-envelope.json> '
                        . 'and --format=json'
                );
            }
            if ($out['restore'] !== null || $out['operation_id'] !== null || $out['list']
                || $out['prune_retained'] !== null || $out['confirm_prune'] || $out['writers_excluded']
                || $out['operator_directed'] || $limitSeen) {
                throw self::invalidArguments(
                    'execute consumes only its frozen plan and signed authorization and accepts no legacy recovery flags'
                );
            }
        } elseif ($out['operation_id'] !== null || $out['plan_path'] !== null
            || $out['authorization_path'] !== null) {
            throw self::invalidArguments(
                '--operation-id belongs only to prepare; --plan and --authorization belong only to execute'
            );
        }

        return $out;
    }

    private static function invalidArguments(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            $message,
            'wprism recover <env> accepts prepare --restore=<checkpoint> --operation-id=<id> --format=json '
                . 'or execute --plan=<plan.json> --authorization=<envelope.json> --format=json; '
                . 'legacy recovery accepts --list, --restore=<checkpoint>, ' . self::WRITERS_EXCLUDED_FLAG
                . ', --operator-directed, ' . self::PRUNE_RETAINED_FLAG . '=<'
                . CheckpointPrune::KEEP_MIN . '..' . CheckpointPrune::KEEP_MAX . '>, '
                . self::CONFIRM_PRUNE_FLAG . ', --limit=<1..200> and --format=json'
        );
    }
}
