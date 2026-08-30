<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Transport/Transport.php';
require_once __DIR__ . '/../Transport/CodeDeploy.php';
require_once __DIR__ . '/../Authority/OperationAuthorization.php';
require_once __DIR__ . '/../Authority/TargetOperationStore.php';
require_once __DIR__ . '/../Plan/PlanContract.php';
require_once __DIR__ . '/../Plan/PlanSummary.php';
require_once __DIR__ . '/../Contract/ApplicationContract.php';
require_once __DIR__ . '/../Contract/ContractStore.php';
require_once __DIR__ . '/../Contract/ProjectionVocabulary.php';
require_once __DIR__ . '/../Assess/SurfaceCatalog.php';
require_once __DIR__ . '/../Recovery/RecoveryClaim.php';
require_once __DIR__ . '/../Recovery/RollbackAuthority.php';
require_once __DIR__ . '/../Recovery/RecoveryProfileSelection.php';
require_once __DIR__ . '/../Release/AuthorizationPlan.php';
require_once __DIR__ . '/../Release/AuthorizationPlanRenderer.php';
require_once __DIR__ . '/../Release/JourneyOracle.php';
require_once __DIR__ . '/../Release/NextAction.php';
require_once __DIR__ . '/../Release/ReleaseOperationStatus.php';
require_once __DIR__ . '/../Release/ReleaseOutcome.php';
require_once __DIR__ . '/../Release/ReleasePrepare.php';
require_once __DIR__ . '/../Release/SourceStageReceipt.php';
require_once __DIR__ . '/AssessCommand.php';
require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/StageSourceCommand.php';
require_once __DIR__ . '/VerifyCommand.php';

use WPrism\CommandRefusalException;

/**
 * `wprism release <env>` — the composed release (round-3 MUP §2.3).
 *
 * The verb boundary, per the module map's rule 9: every engine module this
 * command needs is called from here and its result handed to the next as
 * data. In particular `RecoveryProfileSelection` (cli:Recovery) is called
 * here and the claim it produces reaches `AuthorizationPlan` (cli:Release) as
 * a canonical array, which is the whole reason `cli/src/Release/` references
 * no Recovery class (MUP §4.3, module map rule 9).
 *
 * ## Release COMPOSES promote; it does not fork it
 *
 * Step 6 calls one injected callable, and `cli/wprism`'s `cmd_release()` binds
 * that callable to `cmd_promote_authorized()`. That boundary carries only the
 * consumed release tuple into the same `PromoteCommand` /
 * `cmd_promote_internal()` state machine that `wprism promote` calls.
 * Deploy-before-apply ordering, the promotion
 * lease, the target fence, the checkpoint, the issue #3310
 * `VerifiedRollbackProfile` / `ScopedRollbackProfile` selection and every
 * `promote phase:` output byte therefore come from exactly one implementation.
 * This class adds authorization in front of it and verification behind it; it
 * re-implements none of it.
 *
 * ## The fixed order (MUP §2.3)
 *
 *  1. **Contract.** A site with no accepted application contract cannot be
 *     released: the projection that decides whether a surface is releasable,
 *     the reviewed live-effect declaration §1.6's consequence demands, and
 *     the journeys `wprism verify` reads all live in it. The refusal is a
 *     PRE-authorization refusal and therefore carries a §2.1 gap action
 *     (`declare in contract`), never a release next action.
 *  2. **Repository delivery + target facts.** One `wp wprism plan --format=json` read, its complete
 *     envelope proved through `PlanContract::requireComplete()`; the same
 *     plan rendered through `PlanSummary::render()` for the drift/readiness
 *     check; the target repository `HEAD` read through the driver; and the
 *     target's own content-addressed artifact hash. `--from <ref>` resolves
 *     the exact local commit and, for an executing release, fast-forwards a
 *     clean named target worktree to the same advertised origin ref before
 *     the plan is built. Hash equality prevents a same-name remote ref from
 *     selecting different bytes; divergent history refuses. `--plan-only`
 *     remains read-only and reports a mismatch instead of delivering it.
 *  3. **Projection + pre-freeze refusals.** The projection is REGENERATED
 *     from current facts (MUP §3.4's refresh row: "any assess, status, or
 *     release regenerates projection.json"), never read from disk — a
 *     contract cannot certify itself, and a committed projection is a review
 *     artifact, not evidence about the target as it is now. Regeneration is
 *     unconditional — the plan rendered at step 5 always reflects current
 *     facts, `--plan-only` included — but persisting the regenerated
 *     document to `.wprism/contract/projection.json` is a site-repository
 *     mutation, so it is gated on a release that can actually proceed past
 *     this step: `--plan-only` (step 5) skips the write entirely, matching
 *     the "mutated nothing" promise docs/guides/release.md:122-124,
 *     docs/guides/daily-workflow.md:330-331 and cli/README.md:502-503 all
 *     make, and which the write used to break by running unconditionally
 *     inside `prepare()` before the plan-only return.
 *     `AuthorizationPlan::refusals()` then enumerates the whole gate.
 *  4. **Recovery profile.** `RecoveryProfileSelection` proves what the target
 *     can actually do and `decide()` applies `--profile` / the
 *     `--accept-weaker-recovery` authority to it.
 *  5. **Freeze and confirm.** The plan is rendered, `--plan-only` stops here
 *     having mutated nothing, and otherwise the confirmed plan is written to
 *     `.wprism/releases/<plan_digest>.json` in the LOCAL site repository BEFORE
 *     any target mutation — the spec's "durably bind and present".
 *  6. **Execute**, with the mutation gate immediately before the mutating
 *     call and after the operator's confirmation. FOUR facts are re-observed
 *     against the target as it is at that instant, not three: the agent plan
 *     envelope, the target `HEAD`, the target artifact hash, and — through one
 *     `AssessCommand::capabilityReport()` read — the reviewed capability
 *     claims. The fourth is what closes the spec's condition sentence
 *     (docs/product-spec.md:302-303): `capabilities` was copied into the
 *     frozen plan, so before this build every condition it named re-hashed to
 *     its own frozen value and a plugin deactivated or downgraded inside the
 *     confirmation window released anyway. The refusals, in the order they are
 *     raised: `evidence_not_current` (the reviewed library moved),
 *     `capability_expired` (a named condition drifted, was withdrawn, appeared
 *     or cannot be re-observed), then the generic `plan_changed`. The
 *     condition refusals come FIRST deliberately — `plan_changed` answers
 *     `retry`, and a retry after a plugin was deactivated walks into an
 *     identical refusal, so the failure that has a real remedy must be the one
 *     that names itself.
 *  7. **Verify** through `VerifyCommand`'s own mechanism and record the
 *     outcome.
 *
 * ## Exit status
 *
 * `0` success (including `--plan-only`), `1` a refusal or a failure that
 * carries a documented next action, `2` usage. Only a failure AFTER the plan
 * is frozen carries a release next action from the closed set
 * `resume|reconcile|retry|recover|requalify|escalate`; a pre-authorization
 * refusal carries a §2.1 gap action instead, and the two closed sets are
 * never interchangeable (MUP §2.3).
 */
final class ReleaseCommand {
    /** The projection operation a release is about. */
    public const OPERATION = 'release';

    /**
     * The complete flag grammar, as `flags()` enforces it. Anything else is a
     * typed refusal: a mistyped option that is silently ignored turns a
     * `--plan-only` into a real release.
     *
     * @var list<string>
     */
    public const FLAGS = [
        '--from', '--plan-only', '--profile', RecoveryProfileSelection::WEAKER_FLAG,
        '--with-deletes', '--yes', '--format', '--limit',
    ];

    /**
     * The phase every promotion performs: read-back verification after apply.
     *
     * `cmd_promote_internal()` guards code-stage, lifecycle-retire,
     * lifecycle-activate, lifecycle-settle and code-finalize behind
     * `$codeChangeRequired` (cli/wprism:3202-3240), while its content-only
     * terminal says "code lifecycle hooks not run" (:3253-3255). A state-only
     * authorization plan must therefore name only verify; claiming it enters
     * the hook-firing window would demand an external-effect declaration for
     * execution that does not occur.
     *
     * @var list<string>
     */
    public const BASE_LIFECYCLE_PHASES = ['verify'];

    /** @var list<string> */
    public const CODE_LIFECYCLE_PHASES = ['deploy', 'retire', 'activate', 'finalize'];

    /**
     * Post-failure classification, in evaluation order.
     *
     * `promote` reports failure as an exit code — its human output names the
     * phase, but a caller composing it gets an integer — so the failure CLASS
     * is not asked of promote, it is OBSERVED from the target afterwards
     * through the same read-only plan the release already knows how to read.
     * Every entry maps to exactly one `NextAction`, and the fallback is
     * `nothing_safe` → `escalate`: a failure this command cannot positively
     * classify must never be answered with an automated action.
     *
     * `plan_changed` is first because it is the one class raised WITHOUT
     * observing the target afterwards: `reverify()` names it before the
     * mutating call, which is exactly why it is the only post-freeze class
     * whose answer is `retry`.
     *
     * @var list<string>
     */
    public const OBSERVED_FAILURE_CLASSES = [
        'plan_changed',
        'incomplete_lifecycle', 'incomplete_apply', 'ambiguous_commitment',
        'checkpoint_unavailable', 'drift_detected', 'receipt_uncertain', 'nothing_safe',
    ];

    /**
     * @param list<string> $extra everything after `<env>`
     * @param string $sourceRoot this checkout's root (the adoption probe the
     *        composed assessment runs needs it, exactly as `cmd_assess()` does)
     * @param callable(EnvironmentDriver,list<string>,array<string,string>):int $promote
     *        the existing promote state machine, injected by `cli/wprism` with
     *        the externally-authorized repository/artifact binding
     * @param ?callable():?string $confirm reads one line of operator intent;
     *        null reads STDIN
     * @param ?callable():string $clock null reads the wall clock
     * @param ?callable(array):array $hostCatalog the assess injection seam
     * @param ?callable(EnvironmentDriver,array):array $verify null uses
     *        `VerifyCommand::report()`
     * @param ?callable():void $beforeConsumption a deterministic race seam;
     *        production leaves it null
     * @param ?callable():void $afterBoundaryVerification deterministic seam
     *        after controller verification but before target election
     * @param ?callable():void $beforeMaterialization deterministic target
     *        repository race seam; production leaves it null
     * @param ?callable():void $afterMaterialization deterministic handoff race
     *        seam; production leaves it null
     */
    public static function run(
        EnvironmentDriver $driver,
        array $extra,
        string $sourceRoot,
        callable $promote,
        ?callable $confirm = null,
        ?callable $clock = null,
        ?callable $hostCatalog = null,
        ?callable $verify = null,
        ?callable $beforeConsumption = null,
        ?callable $afterBoundaryVerification = null,
        ?callable $beforeMaterialization = null,
        ?callable $afterMaterialization = null
    ): int {
        if (($extra[0] ?? null) === 'prepare') {
            return self::runPreparedStage(
                $driver,
                array_slice($extra, 1),
                $sourceRoot,
                $clock,
                $hostCatalog
            );
        }
        if (($extra[0] ?? null) === 'execute') {
            return self::runAuthorizedStage(
                $driver,
                array_slice($extra, 1),
                $sourceRoot,
                $promote,
                $clock,
                $hostCatalog,
                $verify,
                $beforeConsumption,
                $afterBoundaryVerification,
                $beforeMaterialization,
                $afterMaterialization
            );
        }
        if (($extra[0] ?? null) === 'status') {
            return self::runOperationStatus($driver, array_slice($extra, 1));
        }
        $json = AssessCommand::wantsJson($extra);
        try {
            $flags = self::flags($extra);
            $limit = AuthorizationPlanRenderer::limitFromArgs($extra);
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, $json, 'release');
        }

        if (!$flags['plan_only']) {
            return self::refuse($driver, new CommandRefusalException(
                'release_external_authorization_required',
                'direct interactive or --yes release mutation is disabled',
                'run stage-source, save its exact receipt, run release prepare, obtain a signed external '
                    . 'authorization, then run release execute with every expected digest'
            ), $json);
        }

        try {
            $prepared = self::prepare($driver, $flags, $sourceRoot, $clock, $hostCatalog);
        } catch (CommandRefusalException $refusal) {
            return self::refuse($driver, $refusal, $json);
        }

        $document = $prepared['plan_document'];
        foreach (AuthorizationPlanRenderer::render($document, $limit) as $line) {
            echo $line . "\n";
        }
        $warning = $prepared['recovery']['warning'] ?? null;
        if (is_string($warning) && $warning !== '') {
            // One line, on stderr, beside the plan the operator is reading:
            // a weaker-than-provable recovery profile is a named human
            // authority and must be visible in the same breath as the claim
            // it weakens (MUP §2.3).
            fwrite(STDERR, $warning . "\n");
        }

        if ($flags['plan_only']) {
            if ($json) {
                echo AuthorizationPlan::encode($document);
            }

            return 0;
        }

        try {
            self::confirm($document, $flags, $confirm);
            $frozenPath = AuthorizationPlan::freeze($document, $prepared['site_repo']);
        } catch (CommandRefusalException $refusal) {
            return self::refuse($driver, $refusal, $json);
        }
        echo 'authorization frozen: ' . $frozenPath . "\n";

        return self::execute($driver, $flags, $prepared, $promote, $verify, $json, $limit);
    }

    /**
     * Read one exact release lineage without re-verifying authority or
     * crossing any mutation boundary. Sequences 1 and 2 are deliberately
     * nonterminal: status names the durable ambiguity; it never guesses that
     * an elected or consumed operation is safe to execute again.
     *
     * @param list<string> $extra
     */
    private static function runOperationStatus(EnvironmentDriver $driver, array $extra): int {
        $json = AssessCommand::wantsJson($extra);
        try {
            $request = self::statusRequestFlags($extra);
            $document = ReleasePrepare::read($request['prepare']);
            if (!hash_equals((string) $document['subject_sha256'], $request['expected_subject_sha256'])) {
                throw new CommandRefusalException(
                    'release_status_digest_mismatch',
                    'the prepared release does not match the subject digest explicitly requested for status',
                    'select the intended canonical prepare document and repeat its exact subject digest'
                );
            }
            if (($document['environment'] ?? null) !== $driver->name()) {
                throw new CommandRefusalException(
                    'release_status_target_mismatch',
                    'the prepared release subject names another environment',
                    'query the subject only against the exact environment it names'
                );
            }
            $stored = TargetOperationStore::statusForSubject(
                $driver,
                ReleasePrepare::authorizationSubject($document)
            );
            $status = ReleaseOperationStatus::build($document, $stored);
            echo ReleaseOperationStatus::encode($status);
            if (($status['state'] ?? null) !== 'completed') {
                return 1;
            }
            $outcome = $status['outcome'] ?? null;
            if (!is_array($outcome)) {
                throw new CommandRefusalException(
                    'release_operation_status_invalid',
                    'the terminal release status has no complete stored outcome',
                    'preserve target control evidence and reconcile the exact operation before any mutation'
                );
            }

            return self::outcomeExit($outcome);
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, $json, 'release status');
        } catch (\Throwable $error) {
            return AssessCommand::renderRefusal(new CommandRefusalException(
                'release_status_invalid',
                'the requested release operation status could not be validated',
                'repair the named prepare or target control evidence before querying this operation again',
                [],
                $error->getMessage(),
                $error
            ), $json, 'release status');
        }
    }

    /**
     * Prepare one externally authorizable subject from an inert source stage.
     * Success emits exactly one canonical document and writes no target or
     * site-repository byte; the two stage validations bracket every planning
     * read so drift during preparation is refused rather than authorized.
     *
     * @param list<string> $extra
     */
    private static function runPreparedStage(
        EnvironmentDriver $driver,
        array $extra,
        string $sourceRoot,
        ?callable $clock,
        ?callable $hostCatalog
    ): int {
        $json = AssessCommand::wantsJson($extra);
        try {
            $request = self::prepareRequestFlags($extra);
            $receipt = SourceStageReceipt::read($request['stage_receipt']);
            if (!hash_equals($request['expected_stage_receipt_sha256'], (string) $receipt['receipt_sha256'])) {
                throw new CommandRefusalException(
                    'release_stage_receipt_unexpected',
                    'the source stage receipt does not match the digest explicitly requested for preparation',
                    'select the intended canonical receipt and repeat its exact receipt_sha256 in the prepare request'
                );
            }
            StageSourceCommand::verify($driver, $receipt);
            $siteRepo = AssessCommand::siteRepo(getcwd() ?: '.');
            self::assertLocalStageSource($siteRepo, $receipt);
            $trust = OperationAuthorization::trust($siteRepo);
            $targetTrust = TargetOperationStore::readAuthorityPolicy($driver);
            if (!hash_equals(
                OperationAuthorization::trustDigest($trust),
                OperationAuthorization::trustDigest($targetTrust)
            )) {
                throw new CommandRefusalException(
                    'release_target_authority_policy_mismatch',
                    'the target authority policy does not match the policy this release would bind',
                    'explicitly review and sync the intended target authority policy, then prepare again'
                );
            }
            $flags = [
                'accept_weaker_recovery' => $request['accept_weaker_recovery'],
                'from' => null,
                'plan_only' => true,
                'profile' => $request['profile'],
                'with_deletes' => $request['with_deletes'],
                'yes' => false,
            ];
            $prepared = self::prepare(
                $driver,
                $flags,
                $sourceRoot,
                $clock,
                $hostCatalog,
                $receipt
            );
            StageSourceCommand::verify($driver, $receipt);
            $registryOperation = SurfaceCatalog::REGISTRY_OPERATION[self::OPERATION];
            $registryDigest = $prepared['assessment']['registry_reports'][$registryOperation]['registry_sha256']
                ?? null;
            if (!is_string($registryDigest)) {
                throw new CommandRefusalException(
                    'release_prepare_capability_identity_missing',
                    'the staged assessment did not return the reviewed capability-library identity',
                    'repair the target capability report before preparing an authorization subject'
                );
            }
            $document = ReleasePrepare::build($receipt, $prepared['plan_document'], [
                'accept_weaker_recovery' => $request['accept_weaker_recovery'],
                'authority_policy_digest' => OperationAuthorization::trustDigest($targetTrust),
                'capability_registry_sha256' => $registryDigest,
                'profile' => $request['profile'],
                'with_deletes' => $request['with_deletes'],
            ]);
            echo ReleasePrepare::encode($document);

            return 0;
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, $json, 'release prepare');
        } catch (\Throwable $error) {
            $refusal = new CommandRefusalException(
                'release_prepare_invalid',
                'the staged release could not be represented as one immutable authorization subject',
                'repair the named receipt, contract or authority-policy input and run release prepare again',
                [],
                $error->getMessage(),
                $error
            );
            return AssessCommand::renderRefusal($refusal, $json, 'release prepare');
        }
    }

    /**
     * Execute exactly one externally authorized staged subject.
     *
     * Target-side completion is consulted before signature lifetime checks:
     * an exact lost-response replay must remain observable after expiry, while
     * a durable consumption without completion is reconciliation state and
     * can never cross the mutation boundary again.
     *
     * @param list<string> $extra
     * @param callable(EnvironmentDriver,list<string>,array<string,string>):int $promote
     * @param ?callable(EnvironmentDriver,array):array $verify
     * @param ?callable():void $beforeConsumption
     * @param ?callable():void $afterBoundaryVerification
     * @param ?callable():void $beforeMaterialization
     * @param ?callable():void $afterMaterialization
     */
    private static function runAuthorizedStage(
        EnvironmentDriver $driver,
        array $extra,
        string $sourceRoot,
        callable $promote,
        ?callable $clock,
        ?callable $hostCatalog,
        ?callable $verify,
        ?callable $beforeConsumption,
        ?callable $afterBoundaryVerification,
        ?callable $beforeMaterialization,
        ?callable $afterMaterialization
    ): int {
        $json = AssessCommand::wantsJson($extra);
        $consumed = false;
        try {
            $request = self::executeRequestFlags($extra);
            $document = ReleasePrepare::read($request['prepare']);
            self::assertExecuteDigests($document, $request);
            if (($document['environment'] ?? null) !== $driver->name()) {
                throw new CommandRefusalException(
                    'release_execute_target_mismatch',
                    'the prepared release subject names another environment',
                    'execute the subject only against the exact environment it names'
                );
            }

            $envelope = OperationAuthorization::readEnvelope($request['authorization']);
            $authorizationDigest = OperationAuthorization::envelopeDigest($envelope);
            if (!hash_equals($request['expected_authorization_sha256'], $authorizationDigest)) {
                throw new CommandRefusalException(
                    'release_execute_digest_mismatch',
                    'the authorization envelope does not match the digest explicitly requested for execution',
                    'select the intended canonical authorization and repeat its exact digest'
                );
            }

            // Deliberately before trust/signature/expiry verification. A
            // completed exact replay is a status read, not a second authority
            // consumption, and must remain readable after its short TTL.
            $status = TargetOperationStore::status($driver, $authorizationDigest);
            if ($status !== null) {
                return self::authorizedStageReplay($driver, $document, $authorizationDigest, $status);
            }
            $elected = TargetOperationStore::statusForSubject(
                $driver,
                ReleasePrepare::authorizationSubject($document)
            );
            if ($elected !== null) {
                if (hash_equals($authorizationDigest, (string) ($elected['authorization_digest'] ?? ''))) {
                    throw self::reconciliationRequired();
                }
                if (is_array($elected['completion'] ?? null)) {
                    throw new CommandRefusalException(
                        'release_authorization_already_completed',
                        'a different authorization already completed this exact frozen release operation',
                        'query or replay the elected authorization; this envelope did not execute and remains unconsumed'
                    );
                }
                throw new CommandRefusalException(
                    'release_operation_reconciliation_required',
                    'a different authorization already won this exact frozen release operation without completion',
                    'do not consume new authority or retry release; reconcile the elected target operation'
                );
            }

            /** @var array<string,mixed> $receipt */
            $receipt = $document['stage_receipt'];
            StageSourceCommand::verify($driver, $receipt);
            $siteRepo = AssessCommand::siteRepo(getcwd() ?: '.');
            self::assertLocalStageSource($siteRepo, $receipt);

            $flags = self::preparedFlags($document);
            $now = ($clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z'))();
            $current = self::prepare(
                $driver,
                $flags,
                $sourceRoot,
                static fn (): string => $now,
                $hostCatalog,
                $receipt
            );
            StageSourceCommand::verify($driver, $receipt);
            self::assertPreparedFactsCurrent($document, $current);

            $trust = OperationAuthorization::trust($siteRepo);
            $verified = OperationAuthorization::verify(
                $envelope,
                ReleasePrepare::authorizationSubject($document),
                $trust,
                $now
            );

            // The last policy observation before consumption asks the same
            // staged-repository capability question preparation asked. The
            // target's canonical checkout is still the receipt base here.
            $registryOperation = SurfaceCatalog::REGISTRY_OPERATION[self::OPERATION];
            $observed = AssessCommand::capabilityReport(
                $driver,
                $registryOperation,
                (string) $receipt['stage']['repository_path']
            );
            $observedRegistry = (string) ($observed['registry_sha256'] ?? '');
            if ($observedRegistry === ''
                || !hash_equals(
                    (string) $document['request']['expected_capability_registry_sha256'],
                    $observedRegistry
                )) {
                throw new CommandRefusalException(
                    'release_evidence_not_current',
                    'the reviewed capability library changed after this release subject was prepared',
                    'prepare and authorize a fresh subject against the capability policy in force now',
                    [['changed_fields' => ['registry_sha256']]]
                );
            }
            $observedConditions = SurfaceCatalog::conditionsByManifest($observed);
            $rechecked = AuthorizationPlan::recheckConditions(
                $document['authorization_plan'],
                $observedConditions,
                $now
            );
            $currentInputs = $current['inputs'];
            $currentInputs['capabilities'] = AuthorizationPlan::withObservedConditions(
                $currentInputs['capabilities'],
                $observedConditions
            );
            AuthorizationPlan::reverify(
                $document['authorization_plan'],
                AuthorizationPlan::currentFacts($currentInputs)
            );
            StageSourceCommand::verify($driver, $receipt);
            self::assertLocalStageSource($siteRepo, $receipt);

            // These are local evidence writes only and occur after signature
            // verification. The canonical target remains untouched until its
            // authorization consumption is durable.
            (new ContractStore($siteRepo))->writeProjection($current['projection']);
            AuthorizationPlan::freeze($document['authorization_plan'], $siteRepo);
            StageSourceCommand::verify($driver, $receipt);

            // Trust is mutable local policy, so the earlier verification is
            // only an admission check. Re-read and re-verify after every
            // planning read and local evidence write, immediately before the
            // target-side winner election. A revocation in that window must
            // leave no consumption record and no target mutation.
            if ($beforeConsumption !== null) {
                $beforeConsumption();
            }
            $boundaryTrust = OperationAuthorization::trust($siteRepo);
            $verified = OperationAuthorization::verify(
                $envelope,
                ReleasePrepare::authorizationSubject($document),
                $boundaryTrust,
                $now
            );

            if ($afterBoundaryVerification !== null) {
                $afterBoundaryVerification();
            }

            $subject = ReleasePrepare::authorizationSubject($document);
            $consumptionResult = TargetOperationStore::consume($driver, $verified, $envelope, $subject);
            if (($consumptionResult['replayed'] ?? false) === true) {
                $raced = TargetOperationStore::status($driver, $authorizationDigest);
                if (is_array($raced)) {
                    return self::authorizedStageReplay($driver, $document, $authorizationDigest, $raced);
                }
                throw self::reconciliationRequired();
            }
            $consumed = true;
            $consumption = $consumptionResult['consumption'];

            $promotionBinding = self::promotionBinding($document, $receipt);
            self::materializeStagedSource($driver, $receipt, $beforeMaterialization);
            if ($afterMaterialization !== null) {
                $afterMaterialization();
            }
            // This target-side locked read closes the materialization/handoff
            // seam even for an injected promoter. Production's promotion
            // state machine repeats it at entry and immediately before its
            // target promotion lease, and binds the compiled artifact there.
            self::assertPromotionBinding($driver, $promotionBinding);
            $promoteArgs = $flags['with_deletes'] ? ['--with-deletes'] : [];
            ob_start();
            try {
                $promoteExit = $promote($driver, $promoteArgs, $promotionBinding);
            } finally {
                $promotionOutput = (string) ob_get_clean();
                if ($promotionOutput !== '') {
                    fwrite(STDERR, $promotionOutput);
                }
            }
            if ($promoteExit !== 0) {
                $outcome = ReleaseOutcome::failedAfterFreeze(
                    $driver->name(),
                    (string) $document['plan_digest'],
                    self::classifyFailure($driver),
                    $rechecked
                );
                TargetOperationStore::complete($driver, $consumption, $outcome);
                echo ReleaseOutcome::encode($outcome);

                return 1;
            }

            $outcome = self::authorizedVerificationOutcome(
                $driver->name(),
                (string) $document['plan_digest'],
                $rechecked,
                static fn (): array => ($verify
                    ?? static fn (EnvironmentDriver $d, array $o): array => VerifyCommand::report($d, $o))(
                        $driver,
                        [
                            'contract' => $current['contract'],
                            'plan_digest' => (string) $document['plan_digest'],
                            'scope_surfaces' => $current['scope']['surfaces'],
                        ]
                    )
            );
            TargetOperationStore::complete($driver, $consumption, $outcome);
            echo ReleaseOutcome::encode($outcome);

            return self::outcomeExit($outcome);
        } catch (CommandRefusalException $refusal) {
            if ($consumed && $refusal->reasonCode !== 'release_operation_reconciliation_required') {
                $refusal = self::reconciliationRequired($refusal);
            }
            return AssessCommand::renderRefusal($refusal, $json, 'release execute');
        } catch (\Throwable $error) {
            $refusal = $consumed
                ? self::reconciliationRequired($error)
                : new CommandRefusalException(
                    'release_execute_invalid',
                    'the authorized staged release could not be validated for execution',
                    'repair the named prepare, authorization, stage or target input before retrying execution',
                    [],
                    $error->getMessage(),
                    $error
                );

            return AssessCommand::renderRefusal($refusal, $json, 'release execute');
        }
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $request */
    private static function assertExecuteDigests(array $document, array $request): void {
        $expected = [
            'expected_plan_digest' => $document['plan_digest'],
            'expected_presentation_sha256' => $document['presented_plan_sha256'],
            'expected_stage_receipt_sha256' => $document['stage_receipt']['receipt_sha256'],
            'expected_subject_sha256' => $document['subject_sha256'],
        ];
        $changed = [];
        foreach ($expected as $field => $value) {
            if (!hash_equals((string) $value, (string) $request[$field])) {
                $changed[] = $field;
            }
        }
        if ($changed !== []) {
            throw new CommandRefusalException(
                'release_execute_digest_mismatch',
                'the prepared release does not match every digest explicitly requested for execution',
                'select the intended canonical prepare document and repeat its exact digests',
                [['changed_fields' => $changed]]
            );
        }
    }

    /** @param array<string,mixed> $document @param array<string,mixed> $current */
    private static function assertPreparedFactsCurrent(array $document, array $current): void {
        $plan = $document['authorization_plan'];
        AuthorizationPlan::reverify($plan, AuthorizationPlan::currentFacts($current['inputs']));
        if (!hash_equals((string) $document['plan_digest'], (string) $current['plan_document']['plan_digest'])) {
            throw new CommandRefusalException(
                'plan_changed',
                'the semantic release plan changed after the external authorization subject was prepared',
                'prepare and authorize a fresh subject against the target and staged source as they are now',
                [['changed_fields' => ['plan_digest']]]
            );
        }
        $registryOperation = SurfaceCatalog::REGISTRY_OPERATION[self::OPERATION];
        $registry = (string) ($current['assessment']['registry_reports'][$registryOperation]['registry_sha256'] ?? '');
        if ($registry === ''
            || !hash_equals((string) $document['request']['expected_capability_registry_sha256'], $registry)) {
            throw new CommandRefusalException(
                'release_evidence_not_current',
                'the reviewed capability library changed after this release subject was prepared',
                'prepare and authorize a fresh subject against the capability policy in force now',
                [['changed_fields' => ['registry_sha256']]]
            );
        }
    }

    /** @param array<string,mixed> $document @return array<string,mixed> */
    private static function preparedFlags(array $document): array {
        $request = $document['request'];

        return [
            'accept_weaker_recovery' => (bool) $request['accept_weaker_recovery'],
            'from' => null,
            'plan_only' => true,
            'profile' => $request['profile'],
            'with_deletes' => (bool) $request['with_deletes'],
            'yes' => false,
        ];
    }

    /** @param array<string,mixed> $status */
    private static function authorizedStageReplay(
        EnvironmentDriver $driver,
        array $document,
        string $authorizationDigest,
        array $status
    ): int {
        $consumption = $status['consumption'] ?? null;
        if (!is_array($consumption)) {
            throw self::reconciliationRequired();
        }
        $expected = [
            'authorization_digest' => $authorizationDigest,
            'operation' => self::OPERATION,
            'operation_id' => (string) $document['operation_id'],
            'presentation_digest' => (string) $document['presented_plan_sha256'],
            'subject_digest' => (string) $document['subject_sha256'],
            'target_id' => (string) $document['target_id'],
        ];
        foreach ($expected as $field => $value) {
            if (!is_string($consumption[$field] ?? null)
                || !hash_equals($value, (string) $consumption[$field])) {
                throw new CommandRefusalException(
                    'authorization_consumption_conflict',
                    'the target authorization record does not belong to this exact release subject',
                    'do not retry mutation; reconcile the target control record and operation lineage'
                );
            }
        }
        $completion = $status['completion'] ?? null;
        if (!is_array($completion) || !is_array($completion['outcome'] ?? null)) {
            throw self::reconciliationRequired();
        }
        $outcome = $completion['outcome'];
        ReleaseOutcome::validate($outcome);
        if (($outcome['environment'] ?? null) !== $driver->name()
            || ($outcome['plan_digest'] ?? null) !== $document['plan_digest']) {
            throw new CommandRefusalException(
                'authorized_operation_outcome_conflict',
                'the target completion does not describe this exact environment and release plan',
                'do not retry mutation; reconcile the target control record and operation lineage'
            );
        }
        echo ReleaseOutcome::encode($outcome);

        return self::outcomeExit($outcome);
    }

    /** @param array<string,mixed> $outcome */
    private static function outcomeExit(array $outcome): int {
        if (($outcome['status'] ?? null) !== ReleaseOutcome::RELEASED) {
            return 1;
        }
        $verify = $outcome['verify'] ?? null;

        return is_array($verify) && ($verify['verdict'] ?? null) === JourneyOracle::PASS ? 0 : 1;
    }

    /**
     * Turn verification into the terminal target-side outcome. Once authority
     * has been consumed and promotion has run, a missing or negative proof is
     * a post-freeze failure, never a successful release with `verify: null`.
     * `nothing_safe` is deliberate: convergence, journey and transport
     * failures share no universally safe automated recovery action.
     *
     * @param array<string,mixed> $rechecked
     * @param callable():array<string,mixed> $reporter
     * @return array<string,mixed>
     */
    private static function authorizedVerificationOutcome(
        string $environment,
        string $planDigest,
        array $rechecked,
        callable $reporter
    ): array {
        try {
            $report = $reporter();
        } catch (CommandRefusalException $refusal) {
            return ReleaseOutcome::failedAfterFreeze(
                $environment,
                $planDigest,
                'nothing_safe',
                $rechecked,
                self::refusalSpec($refusal)
            );
        } catch (\Throwable $error) {
            $refusal = new CommandRefusalException(
                'release_verification_unavailable',
                'post-release verification could not produce a trustworthy report',
                'preserve the target completion evidence and escalate for private inspection before another action',
                [],
                $error->getMessage(),
                $error
            );

            return ReleaseOutcome::failedAfterFreeze(
                $environment,
                $planDigest,
                'nothing_safe',
                $rechecked,
                self::refusalSpec($refusal)
            );
        }

        if (($report['verdict'] ?? null) !== JourneyOracle::PASS) {
            $refusal = new CommandRefusalException(
                'release_verification_failed',
                'post-release verification did not pass',
                'preserve the target completion and verification report, inspect the failed checks, and escalate '
                    . 'before another action'
            );

            return ReleaseOutcome::failedAfterFreeze(
                $environment,
                $planDigest,
                'nothing_safe',
                $rechecked,
                self::refusalSpec($refusal),
                $report
            );
        }

        return ReleaseOutcome::released($environment, $planDigest, $report, $rechecked);
    }

    /**
     * Exact repository and artifact tuple handed to the existing promotion
     * state machine. The artifact hash is recompiled and then becomes the
     * target promotion-lease identity; the Git tuple is checked under the
     * same target-local repository lock both here and inside promotion.
     *
     * @param array<string,mixed> $document
     * @param array<string,mixed> $receipt
     * @return array<string,string>
     */
    private static function promotionBinding(array $document, array $receipt): array {
        $artifactHash = $document['authorization_plan']['artifact_hash'] ?? null;
        if (!is_string($artifactHash) || preg_match('/^[a-f0-9]{64}$/D', $artifactHash) !== 1) {
            throw new CommandRefusalException(
                'release_artifact_unavailable',
                'the authorized release does not carry one exact artifact identity for promotion',
                'prepare and authorize a fresh subject with a complete target artifact identity'
            );
        }

        return [
            'artifact_hash' => $artifactHash,
            'operation_id' => (string) $document['operation_id'],
            'repo_path' => (string) $document['stage_receipt']['target']['repo_path'],
            'source_commit' => (string) $receipt['source']['commit'],
            'source_tree' => (string) $receipt['source']['tree'],
            'stage_path' => (string) $receipt['stage']['repository_path'],
            'stage_ref' => (string) $receipt['stage']['ref'],
        ];
    }

    /**
     * Recheck the materialized source through target Git while holding the
     * target-private repository lock. This is public only so cli/wprism's
     * existing promotion state machine can repeat the exact same question at
     * its own entry and target-lease boundary.
     *
     * @param array<string,string> $binding
     */
    public static function assertPromotionBinding(EnvironmentDriver $driver, array $binding): void {
        $keys = array_keys($binding);
        sort($keys, SORT_STRING);
        if ($keys !== [
            'artifact_hash', 'operation_id', 'repo_path', 'source_commit', 'source_tree', 'stage_path', 'stage_ref',
        ]
            || preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', (string) ($binding['source_commit'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', (string) ($binding['source_tree'] ?? '')) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', (string) ($binding['artifact_hash'] ?? '')) !== 1
            || !is_string($binding['repo_path'] ?? null) || !hash_equals($driver->repoPath(), $binding['repo_path'])
            || !is_string($binding['stage_path'] ?? null) || !str_starts_with($binding['stage_path'], '/')
            || !is_string($binding['stage_ref'] ?? null) || $binding['stage_ref'] === ''
            || !is_string($binding['operation_id'] ?? null) || $binding['operation_id'] === '') {
            throw new CommandRefusalException(
                'release_promotion_binding_invalid',
                'the authorized release promotion binding is malformed',
                'do not promote; reconcile the consumed operation and its exact staged-source receipt'
            );
        }
        $repo = $binding['repo_path'];
        $critical = 'repo=' . escapeshellarg($repo)
            . '; expected_source=' . escapeshellarg($binding['source_commit'])
            . '; expected_tree=' . escapeshellarg($binding['source_tree'])
            . '; expected_stage=' . escapeshellarg($binding['stage_path'])
            . '; stage_ref=' . escapeshellarg($binding['stage_ref']) . '; '
            . 'unexpected=$(git -C "$repo" status --porcelain --untracked-files=all '
            . '| grep -Ev "^\\?\\? \\.wprism/(artifacts|checkpoints|code-release-prepare|code-push)/" || true); '
            . 'test -z "$unexpected" || exit 90; '
            . 'actual=$(git -C "$repo" rev-parse --verify HEAD) || exit 90; '
            . 'actual_tree=$(git -C "$repo" rev-parse --verify HEAD^{tree}) || exit 90; '
            . 'test "$actual" = "$expected_source" && test "$actual_tree" = "$expected_tree" || exit 90; '
            . 'ref_commit=$(git -C "$repo" rev-parse --verify "${stage_ref}^{commit}") || exit 91; '
            . 'stage_commit=$(git -C "$expected_stage" rev-parse --verify HEAD) || exit 91; '
            . 'stage_tree=$(git -C "$expected_stage" rev-parse --verify HEAD^{tree}) || exit 91; '
            . 'test "$ref_commit" = "$expected_source" && test "$stage_commit" = "$expected_source" '
            . '&& test "$stage_tree" = "$expected_tree" || exit 91; '
            . 'test -z "$(git -C "$expected_stage" status --porcelain --untracked-files=all)" || exit 91; '
            . 'printf __BOUND__';
        $result = self::runRepositoryLocked($driver, $repo, $critical, false);
        if (($result['exit'] ?? 1) !== 0 || trim((string) ($result['stdout'] ?? '')) !== '__BOUND__') {
            throw new CommandRefusalException(
                'release_materialized_source_changed',
                'the canonical or staged repository changed before the authorized promotion lease',
                'do not promote or retry mutation; reconcile the consumed operation and preserve current target bytes'
            );
        }
    }

    /** @return array{diagnostics:list<array<string,mixed>>,message:string,reason_code:string,remediation:string} */
    private static function refusalSpec(CommandRefusalException $refusal): array {
        return [
            'diagnostics' => $refusal->diagnostics,
            'message' => $refusal->publicMessage,
            'reason_code' => $refusal->reasonCode,
            'remediation' => $refusal->remediation,
        ];
    }

    /** @param array<string,mixed> $receipt @param ?callable():void $beforeAtomic */
    private static function materializeStagedSource(
        EnvironmentDriver $driver,
        array $receipt,
        ?callable $beforeAtomic = null
    ): void {
        // All earlier reads are advisory. This seam lets the regression move
        // HEAD and tracked bytes after them; the target-side critical section
        // below must recheck under the shared repository lock and refuse
        // without invoking reset.
        if ($beforeAtomic !== null) {
            $beforeAtomic();
        }
        $repo = $driver->repoPath();
        $critical = 'repo=' . escapeshellarg($repo)
            . '; expected_base=' . escapeshellarg((string) $receipt['base']['commit'])
            . '; expected_base_tree=' . escapeshellarg((string) $receipt['base']['tree'])
            . '; expected_source=' . escapeshellarg((string) $receipt['source']['commit'])
            . '; expected_tree=' . escapeshellarg((string) $receipt['source']['tree'])
            . '; expected_stage=' . escapeshellarg((string) $receipt['stage']['repository_path'])
            . '; stage_ref=' . escapeshellarg((string) $receipt['stage']['ref']) . '; '
            . 'test -z "$(git -C "$repo" status --porcelain --untracked-files=all)" || exit 90; '
            . 'git -C "$repo" symbolic-ref --quiet --short HEAD >/dev/null 2>&1 || exit 90; '
            . 'base=$(git -C "$repo" rev-parse --verify HEAD) || exit 90; '
            . 'base_tree=$(git -C "$repo" rev-parse --verify HEAD^{tree}) || exit 90; '
            . 'test "$base" = "$expected_base" && test "$base_tree" = "$expected_base_tree" || exit 90; '
            . 'ref_commit=$(git -C "$repo" rev-parse --verify "${stage_ref}^{commit}") || exit 91; '
            . 'stage_commit=$(git -C "$expected_stage" rev-parse --verify HEAD) || exit 91; '
            . 'stage_tree=$(git -C "$expected_stage" rev-parse --verify HEAD^{tree}) || exit 91; '
            . 'test "$ref_commit" = "$expected_source" && test "$stage_commit" = "$expected_source" '
            . '&& test "$stage_tree" = "$expected_tree" || exit 91; '
            . 'test -z "$(git -C "$expected_stage" status --porcelain --untracked-files=all)" || exit 91; '
            . 'git -C "$repo" merge-base --is-ancestor "$expected_base" "$expected_source" || exit 91; '
            // A checked fast-forward is intentionally non-destructive: Git
            // refuses tracked or colliding untracked bytes instead of the
            // old reset --hard path discarding them. Its ref/index locks are
            // the CAS beneath WPrism's cross-command repository ordering.
            . 'git -c core.hooksPath=/dev/null -C "$repo" merge --ff-only --no-edit '
            . '"$expected_source" >/dev/null 2>&1 || exit 92; '
            . 'actual=$(git -C "$repo" rev-parse --verify HEAD) || exit 92; '
            . 'actual_tree=$(git -C "$repo" rev-parse --verify HEAD^{tree}) || exit 92; '
            . 'test "$actual" = "$expected_source" && test "$actual_tree" = "$expected_tree" || exit 92; '
            . 'test -z "$(git -C "$repo" status --porcelain --untracked-files=all)" || exit 92; '
            . 'ref_commit=$(git -C "$repo" rev-parse --verify "${stage_ref}^{commit}") || exit 91; '
            . 'stage_commit=$(git -C "$expected_stage" rev-parse --verify HEAD) || exit 91; '
            . 'stage_tree=$(git -C "$expected_stage" rev-parse --verify HEAD^{tree}) || exit 91; '
            . 'test "$ref_commit" = "$expected_source" && test "$stage_commit" = "$expected_source" '
            . '&& test "$stage_tree" = "$expected_tree" || exit 91; '
            . 'test -z "$(git -C "$expected_stage" status --porcelain --untracked-files=all)" || exit 91; '
            . 'printf __MATERIALIZED__';
        $result = self::runRepositoryLocked($driver, $repo, $critical, true);
        if (($result['exit'] ?? 1) !== 0
            || trim((string) ($result['stdout'] ?? '')) !== '__MATERIALIZED__') {
            throw self::reconciliationRequired();
        }
    }

    /**
     * Run one target Git critical section under WPrism's private repository
     * lock. Read-only promotion checks require the lock materialization has
     * already created; only the consumed materialization boundary may create
     * it.
     *
     * @return array{exit:int,stdout:string,stderr:string}
     */
    private static function runRepositoryLocked(
        EnvironmentDriver $driver,
        string $repo,
        string $critical,
        bool $allowLockCreate
    ): array {
        $runner = <<<'PHP'
$repo = $argv[1] ?? '';
$critical = base64_decode($argv[2] ?? '', true);
$allowCreate = ($argv[3] ?? '') === 'create';
if ($repo === '' || !is_string($critical)) { fwrite(STDERR, "repository-lock-input\n"); exit(94); }
$git = @proc_open(
    ['git', '-C', $repo, 'rev-parse', '--absolute-git-dir'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $gitPipes,
    null,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($git)) { fwrite(STDERR, "repository-lock-git\n"); exit(94); }
$gitDir = trim((string) stream_get_contents($gitPipes[1]));
stream_get_contents($gitPipes[2]);
fclose($gitPipes[1]); fclose($gitPipes[2]);
if (proc_close($git) !== 0 || $gitDir === '' || $gitDir[0] !== '/') {
    fwrite(STDERR, "repository-lock-git\n"); exit(94);
}
$root = $gitDir . '/wprism-control';
if (!is_dir($root) || is_link($root)) { fwrite(STDERR, "repository-lock-root\n"); exit(94); }
$lockPath = $root . '/repository.lock';
if (is_link($lockPath) || (file_exists($lockPath) && !is_file($lockPath))) {
    fwrite(STDERR, "repository-lock-type\n"); exit(94);
}
if (!$allowCreate && !is_file($lockPath)) { fwrite(STDERR, "repository-lock-missing\n"); exit(94); }
$lockBefore = @lstat($lockPath);
$lock = @fopen($lockPath, $allowCreate ? 'c' : 'rb');
$lockAfter = is_resource($lock) ? @fstat($lock) : false;
if (!is_array($lockAfter) || (($lockAfter['mode'] ?? 0) & 0170000) !== 0100000
    || (is_array($lockBefore)
        && (($lockBefore['mode'] ?? 0) & 0170000) === 0100000
        && (($lockBefore['dev'] ?? null) !== ($lockAfter['dev'] ?? null)
            || ($lockBefore['ino'] ?? null) !== ($lockAfter['ino'] ?? null)))) {
    if (is_resource($lock)) @fclose($lock);
    fwrite(STDERR, "repository-lock-type\n"); exit(94);
}
$lockBound = static function (mixed $handle, string $path): bool {
    $held = is_resource($handle) ? @fstat($handle) : false;
    $named = @lstat($path);
    return is_array($held) && is_array($named)
        && (($named['mode'] ?? 0) & 0170000) === 0100000
        && ($held['dev'] ?? null) === ($named['dev'] ?? null)
        && ($held['ino'] ?? null) === ($named['ino'] ?? null);
};
if (!is_resource($lock) || !@flock($lock, LOCK_EX | LOCK_NB) || !$lockBound($lock, $lockPath)) {
    fwrite(STDERR, "repository-lock-busy\n"); exit(95);
}
$process = @proc_open(
    ['/bin/sh', '-c', $critical],
    [0 => STDIN, 1 => STDOUT, 2 => STDERR, 3 => $lock],
    $pipes,
    null,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($process)) { fwrite(STDERR, "repository-lock-child\n"); exit(94); }
$exit = (int) proc_close($process);
if (!$lockBound($lock, $lockPath)) { fwrite(STDERR, "repository-lock-replaced\n"); exit(94); }
exit($exit);
PHP;
        $script = 'php -r ' . escapeshellarg($runner) . ' -- '
            . escapeshellarg($repo) . ' ' . escapeshellarg(base64_encode($critical)) . ' '
            . escapeshellarg($allowLockCreate ? 'create' : 'read');

        return $driver->captureRaw($script);
    }

    private static function reconciliationRequired(?\Throwable $previous = null): CommandRefusalException {
        return new CommandRefusalException(
            'release_operation_reconciliation_required',
            'the authorization was consumed but no complete terminal release outcome is available',
            'do not retry mutation; reconcile this exact operation from the target authorization and release evidence',
            [],
            $previous?->getMessage(),
            $previous
        );
    }

    /**
     * Steps 1-5: everything that happens before a single target byte moves.
     *
     * Split out because it is the whole of `--plan-only`, and because an
     * offline suite must be able to drive the gate without owning a promote
     * callable.
     *
     * @param array<string,mixed> $flags
     * @return array<string,mixed> keys: plan_document, site_repo, plan,
     *         projection, recovery, target, scope, contract, assessment
     */
    public static function prepare(
        EnvironmentDriver $driver,
        array $flags,
        string $sourceRoot,
        ?callable $clock = null,
        ?callable $hostCatalog = null,
        ?array $stageReceipt = null
    ): array {
        $now = ($clock ?? static fn (): string => gmdate('Y-m-d\TH:i:s\Z'))();

        // 1. Contract.
        $siteRepo = AssessCommand::siteRepo(getcwd() ?: '.');
        $store = new ContractStore($siteRepo);
        $contract = $store->readContract();
        if ($contract === null) {
            throw self::gapRefusal(
                'contract_missing',
                'this site repository has no accepted application contract, so no reviewed declaration says '
                    . 'what this release is allowed to reach',
                'run wprism assess ' . self::token($driver->name()) . ', review '
                    . $store->proposalRelativePath($driver->name()) . ', then '
                    . 'wprism contract ' . self::token($driver->name()) . ' accept',
                'declare in contract'
            );
        }

        // 2. Repository delivery, then target facts. Executing --from is the
        // authority to fast-forward a clean named target worktree. Plan-only
        // remains byte-read-only and can only check the existing binding.
        $head = self::targetHead($driver);
        $repositoryPath = $driver->repoPath();
        $sourceRevision = $head;
        if ($stageReceipt !== null) {
            SourceStageReceipt::validate($stageReceipt);
            if (($stageReceipt['environment'] ?? null) !== $driver->name()
                || ($stageReceipt['target']['repo_path'] ?? null) !== $driver->repoPath()
                || !hash_equals((string) $stageReceipt['base']['commit'], $head)) {
                throw new CommandRefusalException(
                    'release_stage_base_changed',
                    'the canonical target base no longer matches the source stage being prepared',
                    'do not authorize this subject; reconcile the target and create a new source stage operation'
                );
            }
            $repositoryPath = (string) $stageReceipt['stage']['repository_path'];
            $sourceRevision = (string) $stageReceipt['source']['commit'];
        } elseif ($flags['from'] !== null) {
            $resolved = self::localRevision($siteRepo, $flags['from']);
            if (!hash_equals($resolved, $head)) {
                if ($flags['plan_only']) {
                    self::bindRef($flags['from'], $head, $siteRepo, $driver, $resolved);
                }
                $head = self::deliverRef($flags['from'], $resolved, $siteRepo, $driver);
                $sourceRevision = $head;
            }
        }
        $plan = self::targetPlan($driver, $repositoryPath);
        // Pending deletions are this command's own decision, not an
        // assessment gap: `AuthorizationPlan::refusals()` refuses them by name
        // with `release_deletes_not_authorized` and no gap action
        // (cli/src/Release/AuthorizationPlan.php:613-625). issue #3502 made a
        // pending deletion non-ready for `wprism status`, which is right for an
        // ordinary promote and wrong here — it would shadow that reviewed
        // refusal behind the generic one below and send the operator to
        // capture/refresh/rebase a plan that needs a flag. Every other
        // readiness term still applies, including a guard-blocked delete and
        // `delete_conflict`, and the rows stay itemized in the lines printed
        // above the refusal.
        $readiness = PlanSummary::render(
            $plan,
            [],
            $driver->name(),
            null,
            self::surfaceLabels($contract),
            deletionAuthorityOwnedByCaller: true
        );
        $target = [
            'artifact_hash' => self::targetArtifactHash($driver, $repositoryPath),
            'code_revision_from' => $sourceRevision,
            'head_revision' => $head,
            'repo_path' => $driver->repoPath(),
        ];
        if ($stageReceipt !== null) {
            $target += [
                'operation_id' => (string) $stageReceipt['operation_id'],
                'source_tree' => (string) $stageReceipt['source']['tree'],
                'stage_receipt_sha256' => (string) $stageReceipt['receipt_sha256'],
                'target_identity_sha256' => (string) $stageReceipt['target']['identity_sha256'],
            ];
        } else {
            self::bindRef($flags['from'], $head, $siteRepo, $driver);
        }
        if (!$readiness['ok']) {
            // Print WHAT is unclean before refusing. These are
            // `PlanSummary`'s own lines, rendered with the reviewed
            // contract's `surface_labels` (MUP §2.7), so a conflict reads as
            // the WordPress surface it is on rather than as a bucket name —
            // and the operator does not have to run a second command to find
            // out which rows the refusal is about.
            foreach ($readiness['lines'] as $line) {
                fwrite(STDERR, $line . "\n");
            }
            // A plan carrying a conflict, a collision, a blocked delete, a
            // code mismatch or ordinary drift is not a release candidate.
            // The gap action is `classify` rather than a release next action
            // because nothing has been authorized yet: the fix is upstream,
            // in capture/merge, which is exactly what `wprism status` prints.
            throw self::gapRefusal(
                'release_target_not_clean',
                'the target plan is not safe to promote, so there is nothing to authorize',
                'run wprism status ' . self::token($driver->name())
                    . ', resolve every reported condition through capture/refresh/rebase, then re-run wprism release',
                'classify',
                [['plan' => 'not safe to promote']]
            );
        }

        // 3. Projection, regenerated from current facts (MUP §3.4).
        $assessment = AssessCommand::assess($driver, [
            'operations' => [self::OPERATION],
            'source_root' => $sourceRoot,
            'host_catalog' => $hostCatalog,
            'generated_at' => $now,
            'repository_path' => $repositoryPath,
        ]);
        $projection = AssessCommand::projection($assessment, $contract);
        // Regeneration above is unconditional (step 3's docblock), but
        // persisting it is a site-repository mutation, so it is gated on a
        // release that can proceed past this step. Without this gate every
        // `--plan-only` run rewrote `.wprism/contract/projection.json` before
        // ever reaching the plan-only return at :215 — contradicting the
        // "mutated nothing… not the target, not the site repository" promise
        // documented three times (docs/guides/release.md:122-124,
        // docs/guides/daily-workflow.md:330-331, cli/README.md:502-503).
        if (!$flags['plan_only']) {
            $store->writeProjection($projection);
        }
        $all = is_array($projection['surfaces'] ?? null) ? array_values($projection['surfaces']) : [];
        $scope = self::scope($plan, $all);
        // `AuthorizationPlan::refusals()` and `build()` are documented to
        // take the rows for the surfaces IN SCOPE. Handing them the whole
        // projection would refuse a release because some surface the plan
        // never touches is Not qualified — which is an assessment finding,
        // not a fact about this release, and `wprism assess` already reports it.
        $inScope = array_values(array_filter(
            $all,
            static fn (array $row): bool => in_array((string) ($row['id'] ?? ''), $scope['surfaces'], true)
        ));

        $inputs = [
            'authority' => self::authority($plan, $scope),
            'capabilities' => self::capabilities(
                $inScope,
                is_array($assessment['catalog'] ?? null) ? $assessment['catalog'] : []
            ),
            'contract' => $contract,
            'deletion_semantics' => self::deletionSemantics($assessment),
            'environment' => $driver->name(),
            'flags' => ['plan_only' => $flags['plan_only'], 'with_deletes' => $flags['with_deletes']],
            'frozen_at' => $now,
            'plan' => $plan,
            'projection' => $inScope,
            // A placeholder only for the refusal pass: `refusals()` never
            // reads `recovery`, and building the claim before the gate has
            // run would ask the target to prove a profile for a release that
            // is about to be refused.
            'recovery' => ['checkpoint_at' => null, 'claim' => [], 'selected' => '', 'selected_because' => ''],
            'scope' => $scope,
            'target' => $target,
        ];
        $refusals = AuthorizationPlan::refusals($inputs);
        if ($refusals !== []) {
            throw self::fromSpec($refusals[0]);
        }

        // 4. Recovery profile.
        $recovery = self::recovery($driver, $flags, $plan, $contract, $now);
        if (is_array($recovery['refusal'] ?? null)) {
            throw self::fromSpec($recovery['refusal']);
        }
        $inputs['recovery'] = [
            // The instant is carried beside the claim, never inside it: the
            // claim is digested into `plan_digest`, and a clock value there
            // would give one unchanged decision a new identity every second
            // (AuthorizationPlan::digest()).
            'checkpoint_at' => $recovery['checkpoint_at'],
            'claim' => $recovery['claim'],
            'selected' => $recovery['selected'],
            'selected_because' => $recovery['selected_because'],
        ];

        // 5. Build.
        $document = AuthorizationPlan::build($inputs);

        return [
            'assessment' => $assessment,
            'contract' => $contract,
            'inputs' => $inputs,
            'plan' => $plan,
            'plan_document' => $document,
            'projection' => $inScope,
            'recovery' => $recovery,
            'scope' => $scope,
            'site_repo' => $siteRepo,
            'target' => $target,
        ];
    }

    /**
     * Steps 6-7: re-verify, mutate through promote, verify, report.
     *
     * @param array<string,mixed> $flags
     * @param array<string,mixed> $prepared a `prepare()` result
     * @param callable(EnvironmentDriver,list<string>):int $promote
     * @param ?callable(EnvironmentDriver,array):array $verify
     */
    private static function execute(
        EnvironmentDriver $driver,
        array $flags,
        array $prepared,
        callable $promote,
        ?callable $verify,
        bool $json,
        int $limit
    ): int {
        $document = $prepared['plan_document'];
        $digest = (string) $document['plan_digest'];

        // The frozen plan is re-verified against the target AS IT IS NOW,
        // immediately before the mutating call and after the operator's
        // confirmation. Any difference invalidates that authorization; the
        // window this closes is exactly the time a human spent reading the
        // page (product spec, *Authorization*).
        //
        // Four facts are re-observed, and until this build only the first
        // three were: the agent plan envelope, the target `HEAD`, the target
        // artifact hash — and now the reviewed capability claims. The fourth
        // was the hole. `capabilities` was COPIED from `prepare()` into
        // `$current`, so every condition the plan named re-hashed to its own
        // frozen value by construction and a plugin deactivated or downgraded
        // during the confirmation window authorized a production mutation
        // anyway (product spec: "execution is permitted only when every named,
        // machine-checkable condition is satisfied and rechecked at the
        // mutation gate", docs/product-spec.md:302-303).
        $registryOperation = SurfaceCatalog::REGISTRY_OPERATION[self::OPERATION];
        $recheckedAt = gmdate('Y-m-d\TH:i:s\Z');
        try {
            $current = $prepared['inputs'];
            $current['plan'] = self::targetPlan($driver);
            $current['target']['code_revision_from'] = self::targetHead($driver);
            $current['target']['head_revision'] = $current['target']['code_revision_from'];
            $current['target']['artifact_hash'] = self::targetArtifactHash($driver);
            // ONE `wp wprism capabilities` read, through the SAME helper the
            // freeze-time assessment used, so freeze and gate observe through
            // identical argv (`AssessCommand::capabilityReport()` states why
            // that is structural). A targeted re-probe, not a second assess:
            // no doctor, no inventory, no bootstrap.
            $observed = AssessCommand::capabilityReport($driver, $registryOperation);
        } catch (CommandRefusalException $refusal) {
            // The target cannot answer what its reviewed claims are right now.
            // Every condition the plan names is therefore UNCHECKABLE, and the
            // spec's rule for an uncheckable condition is that it blocks — so
            // this is not a transport retry, it is a requalification.
            return self::fail($driver, $digest, 'capability_expired', $json, $refusal);
        }

        $frozenRegistry = (string) ($prepared['assessment']['registry_reports'][$registryOperation]['registry_sha256']
            ?? '');
        $currentRegistry = (string) ($observed['registry_sha256'] ?? '');
        if ($frozenRegistry !== '' && !hash_equals($frozenRegistry, $currentRegistry)) {
            // The reviewed disposition library the target answers from moved
            // inside the confirmation window, so the claims this release was
            // authorized against are not the claims in force. `requalify`, and
            // the diagnostics name the fact that moved, never its value.
            return self::fail($driver, $digest, 'evidence_not_current', $json, new CommandRefusalException(
                'release_evidence_not_current',
                'the reviewed capability library the target answers from moved after the authorization plan was '
                    . 'frozen, so this release was authorized against claims that are no longer in force',
                'nothing was written. Re-run wprism assess to re-read the reviewed claims, review the plan again, '
                    . 'then wprism release.',
                [['changed_fields' => ['registry_sha256']]]
            ));
        }

        $observedConditions = SurfaceCatalog::conditionsByManifest($observed);
        $rechecked = null;
        try {
            // Raised BEFORE reverify()'s generic `plan_changed` on purpose:
            // `plan_changed` is documented as "a post-freeze failure that
            // mutated nothing" and therefore answers `retry` (its branch is
            // the last one in this method),
            // and telling an operator to retry after a plugin was deactivated
            // sends them into an identical refusal. `capability_expired` is
            // the class that already carries the right sentence and the right
            // action (NextAction.php:43,77,101) and, until this call site, had
            // nothing in the tree that could reach it.
            $rechecked = AuthorizationPlan::recheckConditions($document, $observedConditions, $recheckedAt);
        } catch (CommandRefusalException $refusal) {
            return self::fail($driver, $digest, 'capability_expired', $json, $refusal);
        }

        try {
            // The fail-closed backstop. `currentFacts()` is fed the FRESHLY
            // observed condition rows rather than the copy the plan carried,
            // so `inputs_digest.conditions_sha256` is a real comparison even
            // if the named refusal above missed a shape.
            $current['capabilities'] = AuthorizationPlan::withObservedConditions(
                $current['capabilities'],
                $observedConditions
            );
            AuthorizationPlan::reverify($document, AuthorizationPlan::currentFacts($current));
        } catch (CommandRefusalException $refusal) {
            // `plan_changed` is a post-freeze failure that mutated nothing,
            // which is precisely why its next action is `retry` and not
            // `recover`: there is no partial state to reconcile.
            return self::fail($driver, $digest, 'plan_changed', $json, $refusal);
        }

        $promoteArgs = $flags['with_deletes'] ? ['--with-deletes'] : [];
        $exit = $promote($driver, $promoteArgs);
        if ($exit !== 0) {
            return self::fail($driver, $digest, self::classifyFailure($driver), $json, null);
        }

        // 7. Verify. `wprism release` always verifies (MUP §2.3 step 5); an
        // unverified success is recorded as `verify: null`, never as an
        // implied pass, because absence of an error is never the proof.
        $report = [];
        try {
            $report = ($verify ?? static fn (EnvironmentDriver $d, array $o): array => VerifyCommand::report($d, $o))(
                $driver,
                [
                    'contract' => $prepared['contract'],
                    'plan_digest' => $digest,
                    'scope_surfaces' => $prepared['scope']['surfaces'],
                ]
            );
        } catch (CommandRefusalException $refusal) {
            fwrite(STDERR, '[' . $refusal->reasonCode . '] ' . $refusal->publicMessage . "\n");
            fwrite(STDERR, 'remedy: ' . $refusal->remediation . "\n");
        }
        foreach ($report === [] ? [] : JourneyOracle::humanLines($report, $limit) as $line) {
            echo $line . "\n";
        }

        $outcome = ReleaseOutcome::released($driver->name(), $digest, $report, $rechecked);
        ReleaseOutcome::validate($outcome);
        if ($json) {
            echo ReleaseOutcome::encode($outcome);

            return 0;
        }
        foreach (ReleaseOutcome::humanLines($outcome) as $line) {
            echo $line . "\n";
        }

        // The verdict is part of the release's own exit status: a release
        // whose declared journeys failed is not a released release, it is a
        // released release that must be looked at.
        return ($report['verdict'] ?? JourneyOracle::PASS) === JourneyOracle::PASS ? 0 : 1;
    }

    /**
     * Observe the failure class from the target, never from the exit code.
     *
     * Order matters and is the order of consequence: an interrupted code
     * lifecycle window is the most dangerous state and is checked first, an
     * unreadable target is checked last. Every branch returns a class
     * `NextAction::MAPPING` knows, so the closed set can never be widened by
     * accident here.
     */
    private static function classifyFailure(EnvironmentDriver $driver): string {
        try {
            $plan = self::targetPlan($driver);
        } catch (CommandRefusalException) {
            // The target cannot answer what state it is in. That is not a
            // clean failure and must never be retried blind.
            return 'receipt_uncertain';
        }
        if (($plan['incomplete_lifecycle'] ?? []) !== []) {
            return 'incomplete_lifecycle';
        }
        if (($plan['incomplete_apply'] ?? []) !== []) {
            return 'incomplete_apply';
        }
        if ($driver instanceof RecoveryTransport && $driver->carriesRollbackAuthority()) {
            $status = RollbackAuthority::status($driver);
            if (($status['available'] ?? false) === true && ($status['ok'] ?? false) !== true) {
                return 'checkpoint_unavailable';
            }
            if (($status['active'] ?? false) === true && ($status['terminal'] ?? false) !== true) {
                // A live, non-terminal signed generation is the definition of
                // an ambiguous commitment: the controller cannot prove whether
                // the target committed. `reconcile`, never `retry`.
                return 'ambiguous_commitment';
            }
        }
        if (($plan['drift'] ?? []) !== [] || ($plan['conflict'] ?? []) !== []) {
            return 'drift_detected';
        }

        return 'nothing_safe';
    }

    /**
     * Report a post-freeze failure with its one documented next action.
     *
     * The refusal's own reason code reaches STDERR in BOTH formats, and that
     * ordering is the fix, not a detail: `--format=json` used to return before
     * this write, so a machine-readable run printed the failure CLASS and
     * nothing that named the refusal. Two of this command's refusals now share
     * one class (`release_condition_changed` and
     * `release_condition_uncheckable` are both `capability_expired`), so
     * without the code neither channel could say which one happened. STDOUT
     * stays the machine channel and is untouched by this line.
     */
    private static function fail(
        EnvironmentDriver $driver,
        string $planDigest,
        string $failureClass,
        bool $json,
        ?CommandRefusalException $refusal
    ): int {
        $outcome = ReleaseOutcome::failedAfterFreeze(
            $driver->name(),
            $planDigest,
            $failureClass,
            null,
            $refusal === null ? null : [
                'diagnostics' => $refusal->diagnostics,
                'message' => $refusal->publicMessage,
                'reason_code' => $refusal->reasonCode,
                'remediation' => $refusal->remediation,
            ]
        );
        ReleaseOutcome::validate($outcome);
        if ($refusal !== null) {
            fwrite(STDERR, '[' . $refusal->reasonCode . '] ' . $refusal->publicMessage . "\n");
        }
        if ($json) {
            echo ReleaseOutcome::encode($outcome);

            return 1;
        }
        foreach (ReleaseOutcome::humanLines($outcome) as $line) {
            fwrite(STDERR, $line . "\n");
        }

        return 1;
    }

    /**
     * Report a pre-freeze refusal in whichever channel was asked for, and
     * record it as a `wprism-release-outcome/v1` when JSON was asked for.
     */
    private static function refuse(EnvironmentDriver $driver, CommandRefusalException $refusal, bool $json): int {
        if (!$json) {
            return AssessCommand::renderRefusal($refusal, false, 'release');
        }
        $gapAction = null;
        foreach ($refusal->diagnostics as $diagnostic) {
            if (is_array($diagnostic) && is_string($diagnostic['gap_action'] ?? null)) {
                $gapAction = $diagnostic['gap_action'];
            }
        }
        try {
            $outcome = ReleaseOutcome::refusedBeforeFreeze($driver->name(), [
                'diagnostics' => $refusal->diagnostics,
                'gap_action' => $gapAction,
                'message' => $refusal->publicMessage,
                'reason_code' => $refusal->reasonCode,
                'remediation' => $refusal->remediation,
            ]);
        } catch (\InvalidArgumentException) {
            // A refusal that cannot be expressed as an outcome still has to
            // reach the caller as one machine-readable envelope.
            return AssessCommand::renderRefusal($refusal, true, 'release');
        }
        ReleaseOutcome::validate($outcome);
        echo ReleaseOutcome::encode($outcome);

        return 1;
    }

    /** `--plan-only` can check a ref binding but may not deliver it. */
    private static function bindRef(
        ?string $ref,
        string $head,
        string $siteRepo,
        EnvironmentDriver $driver,
        ?string $resolved = null
    ): void {
        if ($ref === null) {
            return;
        }
        $resolved ??= self::localRevision($siteRepo, $ref);
        if (hash_equals($resolved, $head)) {
            return;
        }
        throw new CommandRefusalException(
            'release_ref_mismatch',
            'plan-only cannot describe the asserted revision because the target repository is on another commit',
            'reconcile the target to the selected ref before planning again; to deliver '
                . substr($resolved, 0, 12) . ', run stage-source, save its receipt, prepare and externally authorize '
                . 'the release, then run release execute with every expected digest',
            [[
                'code' => 'ref_mismatch',
                'failure_class' => 'ref_mismatch',
                'next_action' => NextAction::forFailure('ref_mismatch'),
                'reason' => NextAction::reasonFor('ref_mismatch'),
                'asserted_ref' => preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,127}$/D', $ref) === 1 ? $ref : '<ref>',
            ]]
        );
    }

    /**
     * Deliver one advertised local branch/tag to a clean target by exact hash.
     * Fetch can add object-cache bytes; the worktree/ref itself moves only by
     * a hook-free fast-forward and is re-read before planning.
     */
    private static function deliverRef(
        string $ref,
        string $resolved,
        string $siteRepo,
        EnvironmentDriver $driver
    ): string {
        $remoteRef = self::localDeliveryRef($siteRepo, $ref);
        $repo = escapeshellarg($driver->repoPath());
        $expected = escapeshellarg($resolved);
        $source = escapeshellarg($remoteRef);
        $script = 'repo=' . $repo . '; expected=' . $expected . '; source_ref=' . $source . '; '
            . 'test -z "$(git -C "$repo" status --porcelain --untracked-files=all)" || exit 67; '
            . 'branch=$(git -C "$repo" symbolic-ref --quiet --short HEAD) || exit 68; '
            . 'test -n "$branch" || exit 68; '
            . 'git -c core.hooksPath=/dev/null -C "$repo" fetch --no-tags origin "$source_ref" >/dev/null 2>&1 || exit 69; '
            . 'actual=$(git -C "$repo" rev-parse --verify FETCH_HEAD^{commit}) || exit 69; '
            . 'test "$actual" = "$expected" || exit 70; '
            . 'git -C "$repo" merge-base --is-ancestor HEAD "$expected" || exit 71; '
            . 'git -c core.hooksPath=/dev/null -C "$repo" reset --hard "$expected" >/dev/null 2>&1 || exit 72; '
            . 'actual=$(git -C "$repo" rev-parse --verify HEAD) || exit 72; '
            . 'test "$actual" = "$expected" || exit 72; printf "%s" "$actual"';
        $result = $driver->captureRaw($script);
        $exit = (int) ($result['exit'] ?? 1);
        $actual = trim((string) ($result['stdout'] ?? ''));
        if ($exit === 0 && hash_equals($resolved, $actual)) {
            return $actual;
        }
        [$message, $remediation] = match ($exit) {
            67 => [
                'the target repository has tracked or untracked work, so release delivery cannot move it safely',
                'review and commit or remove the target worktree changes, then rerun the same release',
            ],
            68 => [
                'the target repository is detached, so release delivery has no named branch to fast-forward',
                'switch the target to the intended named branch, then rerun the same release',
            ],
            69 => [
                'the target could not fetch the asserted branch or tag from its configured origin',
                'publish the local ref to the target origin and repair its fetch credentials, then rerun the same release',
            ],
            70 => [
                'the target origin ref does not resolve to the exact local commit selected by --from',
                'push the reviewed local ref without rewriting it, verify both sides name the same commit, then rerun',
            ],
            71 => [
                'the selected release is not a fast-forward of the target repository',
                'reconcile target history explicitly; WPrism release will not reset or overwrite divergent history',
            ],
            default => [
                'the target repository did not complete and verify the requested fast-forward delivery',
                'inspect target Git health and permissions, restore a clean named worktree, then rerun the release',
            ],
        };
        throw new CommandRefusalException('release_delivery_failed', $message, $remediation, [[
            'code' => 'repository_delivery_failed',
            'phase' => match ($exit) {
                67 => 'cleanliness', 68 => 'branch', 69 => 'fetch', 70 => 'identity', 71 => 'fast_forward',
                default => 'materialize',
            },
        ]]);
    }

    /** Resolve only an advertised local branch/tag; expressions are not transport identities. */
    private static function localDeliveryRef(string $siteRepo, string $ref): string {
        $result = self::localGit($siteRepo, [
            'rev-parse', '--symbolic-full-name', '--verify', '--end-of-options', $ref,
        ]);
        $resolved = trim($result['stdout']);
        if ($result['exit'] !== 0
            || preg_match('#^refs/(?:heads|tags)/[A-Za-z0-9][A-Za-z0-9._/-]{0,240}$#D', $resolved) !== 1
            || str_contains($resolved, '..') || str_contains($resolved, '//')) {
            throw new CommandRefusalException(
                'release_ref_not_deliverable',
                'the asserted revision is not an advertised local branch or tag that a target origin can fetch',
                'name a local branch or tag, publish it to the target origin, then rerun release --from with that name'
            );
        }
        return $resolved;
    }

    /** @return array{exit:int,stdout:string} */
    private static function localGit(string $siteRepo, array $args): array {
        $process = @proc_open(
            array_merge(['git', '-C', $siteRepo], $args),
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) return ['exit' => 127, 'stdout' => ''];
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => is_string($stdout) ? $stdout : ''];
    }

    /** The local site repository's own resolution of one ref. */
    private static function localRevision(string $siteRepo, string $ref): string {
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open(
            ['git', '-C', $siteRepo, 'rev-parse', '--verify', '--end-of-options', $ref . '^{commit}'],
            $descriptors,
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new CommandRefusalException(
                'release_ref_unresolvable',
                'the local site repository could not be asked to resolve the asserted ref',
                'run wprism release from inside the site repository whose environments this release targets'
            );
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $revision = trim($stdout);
        if ($exit !== 0 || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $revision) !== 1) {
            throw new CommandRefusalException(
                'release_ref_unresolvable',
                'the asserted ref does not resolve to a commit in the local site repository',
                'fetch or create the ref locally, then re-run wprism release with the same --from value'
            );
        }

        return $revision;
    }

    /** @param array<string,mixed> $receipt */
    private static function assertLocalStageSource(string $siteRepo, array $receipt): void {
        $head = self::localGit($siteRepo, ['rev-parse', '--verify', 'HEAD']);
        $tree = self::localGit($siteRepo, ['rev-parse', '--verify', 'HEAD^{tree}']);
        $status = self::localGit($siteRepo, ['status', '--porcelain', '--untracked-files=all']);
        if ($head['exit'] !== 0 || $tree['exit'] !== 0 || $status['exit'] !== 0
            || !hash_equals((string) $receipt['source']['commit'], trim($head['stdout']))
            || !hash_equals((string) $receipt['source']['tree'], trim($tree['stdout']))
            || trim($status['stdout']) !== '') {
            throw new CommandRefusalException(
                'release_prepare_source_checkout_changed',
                'the local site checkout no longer exactly matches the source commit and tree in the stage receipt',
                'check out the exact staged source revision with no tracked or untracked changes, then prepare again'
            );
        }
    }

    /** The target repository's own `HEAD`, read through the driver. */
    private static function targetHead(EnvironmentDriver $driver): string {
        $result = $driver->captureRaw(
            'git -C ' . escapeshellarg($driver->repoPath()) . ' rev-parse HEAD'
        );
        $revision = trim((string) ($result['stdout'] ?? ''));
        if (($result['exit'] ?? 1) !== 0
            || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $revision) !== 1) {
            throw new CommandRefusalException(
                'release_target_revision_unknown',
                'the target repository did not report a revision, so no ref assertion about it can be checked',
                'confirm the target repo_path is a Git worktree and this transport can read it, then re-run wprism release'
            );
        }

        return $revision;
    }

    /**
     * The target's own content address for this revision.
     *
     * `wp wprism compile` is the sole source of truth for the artifact hash and
     * is explicitly target-read-free: it "compiles a canonical revision into
     * WPrism's immutable, content-addressed apply artifact without reading or
     * mutating the target environment" (`agent/src/Command/Cli.php`). It is
     * invoked here WITHOUT `--out`, so nothing is written anywhere — which is
     * what lets `--plan-only` name the artifact it would release while still
     * mutating nothing at all.
     */
    private static function targetArtifactHash(EnvironmentDriver $driver, ?string $repositoryPath = null): string {
        $repositoryPath ??= $driver->repoPath();
        $result = $driver->captureWp(CodeDeploy::controlArgs([
            'wprism', 'compile', '--repo=' . $repositoryPath, '--format=json',
        ]));
        $summary = ($result['exit'] ?? 1) === 0
            ? json_decode(trim((string) ($result['stdout'] ?? '')), true)
            : null;
        if (!is_array($summary) || !CodeDeploy::validArtifactHash($summary)) {
            throw new CommandRefusalException(
                'release_artifact_unavailable',
                'the target could not compile this revision into a content-addressed artifact, so there is no '
                    . 'identity to bind the authorization to',
                'run wprism status ' . self::token($driver->name())
                    . ' and repair every repository, policy or code diagnostic it reports, then re-run wprism release'
            );
        }

        return (string) $summary['artifact_hash'];
    }

    /**
     * One complete `wp wprism plan --format=json` read.
     *
     * @return array<string,mixed>
     */
    private static function targetPlan(EnvironmentDriver $driver, ?string $repositoryPath = null): array {
        $repositoryPath ??= $driver->repoPath();
        $result = $driver->captureWp(['wprism', 'plan', '--repo=' . $repositoryPath, '--format=json']);
        if (($result['exit'] ?? 1) !== 0) {
            $refusal = json_decode(trim((string) ($result['stdout'] ?? '')), true);
            if (is_array($refusal) && ($refusal['format'] ?? null) === 'wprism-command-refusal/v1') {
                throw new CommandRefusalException(
                    is_string($refusal['reason_code'] ?? null) ? $refusal['reason_code'] : 'release_plan_unavailable',
                    (string) ($refusal['message'] ?? 'the target refused to produce a plan'),
                    (string) ($refusal['remediation'] ?? 'repair the target, then re-run wprism release'),
                    array_values(array_filter((array) ($refusal['diagnostics'] ?? []), 'is_array'))
                );
            }
            throw new CommandRefusalException(
                'release_plan_unavailable',
                'the target could not produce a plan, so there is nothing to authorize',
                'run wprism status ' . self::token($driver->name()) . ', repair the target, then re-run wprism release'
            );
        }
        $decoded = json_decode(trim((string) ($result['stdout'] ?? '')), true);
        if (!is_array($decoded)) {
            throw new CommandRefusalException(
                'release_plan_unavailable',
                'the target returned plan output this build could not parse as JSON',
                'upgrade the target agent, then re-run wprism release'
            );
        }

        try {
            return PlanContract::requireComplete($decoded, 'wprism release');
        } catch (\Throwable $incomplete) {
            throw new CommandRefusalException(
                'release_plan_incomplete',
                'the target plan envelope is incomplete, so its counts cannot be trusted to describe this release',
                'upgrade the target agent to a build that emits the complete plan envelope, then re-run wprism release',
                [['detail' => 'plan envelope incomplete']],
                $incomplete->getMessage()
            );
        }
    }

    /**
     * The requested scope: the surfaces the plan actually touches, plus the
     * code half.
     *
     * Surface membership is decided at the ENTITY-KIND level, from the plan's
     * own value-free `category_summary` — the only scope evidence the agent
     * publishes (`\WPrism\PlanCategorySummary`). The host never classifies a
     * detailed plan row; that rule is `PlanView`'s and it is not relaxed
     * here just because the caller is a release.
     *
     * @param array<string,mixed> $plan
     * @param list<array<string,mixed>> $rows projection surface rows
     * @return array<string,mixed>
     */
    private static function scope(array $plan, array $rows): array {
        $kinds = self::touchedEntityKinds($plan);
        $surfaces = [];
        foreach ($rows as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '') {
                continue;
            }
            if ($kinds === [] || self::rowTouched($id, $kinds)) {
                $surfaces[$id] = true;
            }
        }
        $code = self::codeCounts($plan);
        $withCode = $code['plugins_changed'] + $code['themes_changed'] + $code['other'] > 0;
        // Printed in promote's own execution order — code-stage, retire,
        // activate, code-finalize, apply's verification — because the reader
        // is being asked to authorize a sequence, not a set.
        $phases = [];
        foreach (AuthorizationPlan::LIFECYCLE_PHASES as $phase) {
            if (in_array($phase, self::BASE_LIFECYCLE_PHASES, true)
                || ($withCode && in_array($phase, self::CODE_LIFECYCLE_PHASES, true))) {
                $phases[] = $phase;
            }
        }
        ksort($surfaces, SORT_STRING);

        return [
            'code' => [
                'lifecycle_phases' => $phases,
                'plugins_changed' => $code['plugins_changed'],
                'themes_changed' => $code['themes_changed'],
            ],
            'surfaces' => array_keys($surfaces),
        ];
    }

    /**
     * The entity kinds the plan's own category summary reports as contained.
     *
     * @param array<string,mixed> $plan
     * @return array<string,true>
     */
    private static function touchedEntityKinds(array $plan): array {
        $summary = $plan['category_summary'] ?? null;
        if (!PlanContract::validCategorySummary($summary)) {
            return [];
        }
        $kinds = [];
        $categories = is_array($summary) && is_array($summary['categories'] ?? null)
            ? $summary['categories']
            : [];
        foreach ($categories as $category) {
            if (!is_array($category) || !is_array($category['contained_entities'] ?? null)) {
                continue;
            }
            foreach ($category['contained_entities'] as $kind => $count) {
                if (is_int($count) && $count > 0) {
                    $kinds[(string) $kind] = true;
                }
            }
        }

        return $kinds;
    }

    /**
     * Whether one projection surface id is inside the plan's entity kinds.
     *
     * The mapping is the surface-id grammar's own: `post_type:*` rows are
     * `post` (or `attachment` for the media surface), `taxonomy:*` rows are
     * `term`, `table:*` rows are `typed_table`. A surface whose kind this
     * grammar does not cover stays in scope rather than being silently
     * dropped — over-naming a surface in the authorization plan is a page an
     * operator reads; under-naming one is a surface that changes without
     * being declared.
     *
     * @param array<string,true> $kinds
     */
    private static function rowTouched(string $id, array $kinds): bool {
        $prefix = strpos($id, ':') === false ? '' : substr($id, 0, (int) strpos($id, ':'));

        return match ($prefix) {
            'post_type' => isset($kinds['post']) || isset($kinds['attachment']),
            'taxonomy' => isset($kinds['term']) || isset($kinds['menu']),
            'table' => isset($kinds['typed_table']),
            'media' => isset($kinds['attachment']),
            'option_group' => isset($kinds['options']),
            default => true,
        };
    }

    /**
     * Code counts from the plan's category summary.
     *
     * @param array<string,mixed> $plan
     * @return array{plugins_changed:int,themes_changed:int,other:int}
     */
    private static function codeCounts(array $plan): array {
        $out = ['other' => 0, 'plugins_changed' => 0, 'themes_changed' => 0];
        $summary = $plan['category_summary'] ?? null;
        if (!PlanContract::validCategorySummary($summary)) {
            return $out;
        }
        $categories = is_array($summary) && is_array($summary['categories'] ?? null)
            ? $summary['categories']
            : [];
        foreach ($categories as $category) {
            if (!is_array($category) || ($category['id'] ?? null) !== 'code') {
                continue;
            }
            $entities = is_array($category['contained_entities'] ?? null) ? $category['contained_entities'] : [];
            $out['plugins_changed'] = (int) ($entities['plugin'] ?? 0);
            $out['themes_changed'] = (int) ($entities['theme'] ?? 0);
            $out['other'] = (int) ($entities['other'] ?? 0);
        }

        return $out;
    }

    /**
     * §2.3.1's `capabilities` rows.
     *
     * DELIBERATE DEVIATION from §2.3.1's illustration, which names manifests
     * ("woocommerce", "core"): the rows below are per-SURFACE. The projection
     * is the only per-target readiness evidence this host has, and it is
     * computed per surface; a per-manifest row would have to invent a
     * strictest-of aggregation across surfaces that nothing in round 3
     * computes, and printing an invented aggregate beside a real condition
     * list is worse than printing the real rows. Every field §2.3.1 shows is
     * present and carries the projection's own word.
     *
     * @param list<array<string,mixed>> $rows in-scope projection surface rows
     * @param array<string,mixed> $catalog the assessment's `SurfaceCatalog::catalog()`
     *        result, read through `factVectors()` — the fact record's shape is
     *        stated there and nowhere else, because indexing it by hand here
     *        is precisely how this plan once froze with no conditions at all
     * @return list<array<string,mixed>>
     */
    private static function capabilities(array $rows, array $catalog): array {
        $out = [];
        foreach ($rows as $row) {
            $operation = $row['operations'][self::OPERATION] ?? null;
            if (!is_array($operation)) {
                continue;
            }
            $vector = SurfaceCatalog::factVectors($catalog, (string) ($row['id'] ?? ''))[self::OPERATION] ?? [];
            $out[] = [
                'certification_provenance' => (string) ($operation['certification_provenance'] ?? ''),
                // STRUCTURED rows, not the projection's prose. The projection
                // keeps the prose (SurfaceCatalog::registryFacts()), which is
                // what `wprism assess`, `projection.json` and MUP §1.3's
                // readiness word read and what makes those bytes unmoved by
                // this. The frozen plan needs the machine facts instead: a
                // prose sentence re-hashes to its own frozen value at the
                // mutation gate, which is precisely how a plugin deactivated
                // during the confirmation window used to pass.
                'conditions' => array_values(
                    is_array($vector['conditions'] ?? null) ? $vector['conditions'] : []
                ),
                // The claim the row's readiness rests on, carried even where
                // it raises no condition today: the gate must be able to see a
                // condition that APPEARS during the confirmation window, and
                // an appearing condition has no frozen row to appear against
                // unless the manifest was named at freeze.
                'manifest' => (string) ($vector['manifest'] ?? ''),
                'name' => (string) ($row['label'] ?? $row['id'] ?? ''),
                'operation' => self::OPERATION,
                'readiness' => (string) ($operation['readiness'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * The authority rows this command knows about beyond the ones
     * `AuthorizationPlan` derives from the contract's declarations.
     *
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $scope
     * @return list<array<string,mixed>>
     */
    private static function authority(array $plan, array $scope): array {
        // `AuthorizationPlan::authority()` already emits the
        // `operator_confirmation` row for every release and the
        // `declared_live_effect` row for a declared lifecycle window;
        // restating either here would print the same requirement twice and
        // teach an operator to skim the section that must not be skimmed.
        $rows = [];
        if ($scope['surfaces'] !== [] && (count($plan['create']) + count($plan['update'])) > 0) {
            $rows[] = [
                'kind' => 'business_owner',
                'reason' => 'authored state customers can see changes on this release',
            ];
        }

        return $rows;
    }

    /**
     * The registry's own declared-unsupported deletion surfaces.
     *
     * @param array<string,mixed> $assessment an `AssessCommand::assess()` result
     * @return array<string,mixed>
     */
    private static function deletionSemantics(array $assessment): array {
        $unsupported = [];
        foreach ((array) ($assessment['registry_reports'] ?? []) as $report) {
            if (!is_array($report)) {
                continue;
            }
            foreach ((array) ($report['deletion_semantics']['unsupported'] ?? []) as $surface) {
                if (is_string($surface) && $surface !== '') {
                    $unsupported[$surface] = true;
                }
            }
        }
        ksort($unsupported, SORT_STRING);

        return ['unsupported' => array_keys($unsupported)];
    }

    /**
     * Prove the recovery profile, then apply the operator's request to it.
     *
     * Only an SSH target has a rollback authority runtime, so only an SSH
     * target can prove `verified-automatic`. Every other transport proves
     * `operator-directed`: `promote` takes a real database checkpoint on it
     * (`cli/wprism`'s `promote phase: checkpoint`), which is exactly the one
     * resource `RecoveryClaim::RESTORES[operator-directed]` names. Claiming
     * more would put a code, upload and effect restore in the frozen plan
     * that no provider on that transport can honour.
     *
     * @param array<string,mixed> $flags
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $contract
     * @return array<string,mixed>
     */
    private static function recovery(
        EnvironmentDriver $driver,
        array $flags,
        array $plan,
        array $contract,
        string $now
    ): array {
        $request = [
            'accept_weaker_recovery' => $flags['accept_weaker_recovery'],
            // Release start, which is the instant the checkpoint `promote`
            // takes immediately before the first mutation is dated from.
            // `RecoveryProfileSelection::decide()` publishes it beside the
            // claim rather than in it, and nulls it for the `none` profile,
            // which takes no checkpoint at all.
            'checkpoint_at' => $now,
            'covered_resources' => [],
            'declared_external_effects' => self::declaredExternalEffects($contract),
            'requested_profile' => $flags['profile'],
        ];
        // The claim in the frozen authorization plan and the profile
        // `wprism promote` selects must be one answer: promote's dispatch tests
        // exactly this pair (cli/wprism), so testing SshTransport here would let
        // a configured local target read `operator-directed` in the document
        // it authorizes and then run the verified path.
        if ($driver instanceof RecoveryTransport && $driver->carriesRollbackAuthority()) {
            try {
                $proof = RecoveryProfileSelection::proveVerified($driver, $plan);
            } catch (\Throwable $unprovable) {
                unset($unprovable);
                $proof = self::operatorDirectedProof(
                    'the target rollback authority could not be read, so no automatic verified profile is provable'
                );
            }
        } else {
            $proof = self::operatorDirectedProof(
                'this transport carries no rollback authority runtime, so promote uses the operator-directed '
                    . 'artifact-bound lease and database checkpoint'
            );
        }

        return RecoveryProfileSelection::decide($proof, $request);
    }

    /**
     * @return array{profile:string,reason:string,automatic:bool,scoped:bool,status:array<string,mixed>}
     */
    private static function operatorDirectedProof(string $reason): array {
        return [
            'automatic' => false,
            'profile' => RecoveryClaim::OPERATOR_DIRECTED,
            'reason' => $reason,
            'scoped' => false,
            'status' => [],
        ];
    }

    /**
     * The contract's reviewed live external effects, as the claim's own
     * `declared_external_effects` facts.
     *
     * @param array<string,mixed> $contract
     * @return list<array<string,mixed>>
     */
    private static function declaredExternalEffects(array $contract): array {
        $out = [];
        foreach ((array) ($contract['declarations']['external_effects'] ?? []) as $effect) {
            if (!is_array($effect) || ($effect['decided_by'] ?? null) === ApplicationContract::UNREVIEWED_DECIDED_BY) {
                continue;
            }
            $out[] = [
                'containment' => (string) ($effect['containment'] ?? ''),
                'effect_recovery_semantics' => (string) ($effect['effect_recovery_semantics'] ?? ''),
                'id' => (string) ($effect['id'] ?? ''),
                'restored_by' => is_string($effect['restored_by'] ?? null) ? $effect['restored_by'] : null,
            ];
        }

        return $out;
    }

    /**
     * @param array<string,mixed> $contract
     * @return array<string,string>
     */
    private static function surfaceLabels(array $contract): array {
        $out = [];
        foreach ((array) ($contract['declarations']['surface_labels'] ?? []) as $id => $label) {
            if (is_string($label) && $label !== '') {
                $out[(string) $id] = $label;
            }
        }

        return $out;
    }

    /**
     * The single confirmation the rendered page ends in.
     *
     * @param array<string,mixed> $document
     * @param array<string,mixed> $flags
     */
    private static function confirm(array $document, array $flags, ?callable $confirm): void {
        if ($flags['yes']) {
            return;
        }
        echo AuthorizationPlanRenderer::question($document) . ' [yes/no] ';
        $answer = ($confirm ?? static fn (): ?string => (($line = fgets(STDIN)) === false ? null : $line))();
        if (is_string($answer) && in_array(strtolower(trim($answer)), ['y', 'yes'], true)) {
            return;
        }
        throw new CommandRefusalException(
            'release_not_authorized',
            'the authorization plan was not confirmed, so nothing was written and nothing was frozen',
            're-run wprism release and answer yes to the authorization question, or pass --yes to confirm the '
                . 'displayed plan non-interactively'
        );
    }

    /**
     * @param list<string> $extra
     * @return array{accept_weaker_recovery:bool,expected_stage_receipt_sha256:string,profile:?string,stage_receipt:string,with_deletes:bool}
     */
    private static function prepareRequestFlags(array $extra): array {
        $out = [
            'accept_weaker_recovery' => false,
            'expected_stage_receipt_sha256' => null,
            'profile' => null,
            'stage_receipt' => null,
            'with_deletes' => false,
        ];
        foreach ($extra as $arg) {
            if (!is_string($arg)) {
                throw self::invalidPrepareArguments('release prepare received a non-string argument');
            }
            $name = str_contains($arg, '=') ? explode('=', $arg, 2)[0] : $arg;
            $value = str_contains($arg, '=') ? substr($arg, strlen($name) + 1) : null;
            switch ($name) {
                case '--stage-receipt':
                    if ($out['stage_receipt'] !== null || $value === null || $value === ''
                        || preg_match('/[\x00-\x1f\x7f]/D', $value) === 1) {
                        throw self::invalidPrepareArguments('--stage-receipt takes exactly one readable file path');
                    }
                    $out['stage_receipt'] = $value;
                    break;
                case '--expected-stage-receipt-sha256':
                    if ($out['expected_stage_receipt_sha256'] !== null || $value === null
                        || preg_match('/^sha256:[a-f0-9]{64}$/D', $value) !== 1) {
                        throw self::invalidPrepareArguments(
                            '--expected-stage-receipt-sha256 takes exactly one sha256:<64-lowercase-hex> value'
                        );
                    }
                    $out['expected_stage_receipt_sha256'] = $value;
                    break;
                case '--profile':
                    if ($out['profile'] !== null || $value === null
                        || !in_array($value, RecoveryProfileSelection::PROFILES, true)) {
                        throw self::invalidPrepareArguments(
                            '--profile must be one of ' . implode(', ', RecoveryProfileSelection::PROFILES)
                        );
                    }
                    $out['profile'] = $value;
                    break;
                case RecoveryProfileSelection::WEAKER_FLAG:
                    $out['accept_weaker_recovery'] = true;
                    break;
                case '--with-deletes':
                    $out['with_deletes'] = true;
                    break;
                case '--format':
                    if ($value !== 'json') {
                        throw self::invalidPrepareArguments('release prepare supports only --format=json');
                    }
                    break;
                case '--json':
                    break;
                default:
                    throw self::invalidPrepareArguments("release prepare received an option it does not define: '$name'");
            }
        }
        if (!is_string($out['stage_receipt']) || !is_string($out['expected_stage_receipt_sha256'])) {
            throw self::invalidPrepareArguments(
                'release prepare requires --stage-receipt=<file> and --expected-stage-receipt-sha256=<digest>'
            );
        }

        return $out;
    }

    private static function invalidPrepareArguments(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            $message,
            'use wprism release <env> prepare --stage-receipt=<file> '
                . '--expected-stage-receipt-sha256=sha256:<hex> [--profile=<p>] '
                . '[--accept-weaker-recovery] [--with-deletes] [--format=json]'
        );
    }

    /**
     * @param list<string> $extra
     * @return array{expected_subject_sha256:string,prepare:string}
     */
    private static function statusRequestFlags(array $extra): array {
        $out = [
            'expected_subject_sha256' => null,
            'format_json' => false,
            'prepare' => null,
        ];
        foreach ($extra as $arg) {
            if (!is_string($arg)) {
                throw self::invalidStatusArguments('release status received a non-string argument');
            }
            $name = str_contains($arg, '=') ? explode('=', $arg, 2)[0] : $arg;
            $value = str_contains($arg, '=') ? substr($arg, strlen($name) + 1) : null;
            if ($name === '--prepare') {
                if ($out['prepare'] !== null || $value === null || $value === ''
                    || preg_match('/[\x00-\x1f\x7f]/D', $value) === 1) {
                    throw self::invalidStatusArguments('--prepare takes exactly one readable file path');
                }
                $out['prepare'] = $value;
                continue;
            }
            if ($name === '--expected-subject-sha256') {
                if ($out['expected_subject_sha256'] !== null || $value === null
                    || preg_match('/^sha256:[a-f0-9]{64}$/D', $value) !== 1) {
                    throw self::invalidStatusArguments(
                        '--expected-subject-sha256 takes exactly one sha256:<64-lowercase-hex> value'
                    );
                }
                $out['expected_subject_sha256'] = $value;
                continue;
            }
            if ($name === '--format' && $value === 'json') {
                $out['format_json'] = true;
                continue;
            }
            if ($name === '--json' && $value === null) {
                $out['format_json'] = true;
                continue;
            }
            throw self::invalidStatusArguments("release status received an option it does not define: '$name'");
        }
        if (!is_string($out['prepare']) || !is_string($out['expected_subject_sha256'])) {
            throw self::invalidStatusArguments(
                'release status requires --prepare=<file> and --expected-subject-sha256=<digest>'
            );
        }
        if ($out['format_json'] !== true) {
            throw self::invalidStatusArguments('release status requires --format=json for its single-document result');
        }

        return [
            'expected_subject_sha256' => $out['expected_subject_sha256'],
            'prepare' => $out['prepare'],
        ];
    }

    private static function invalidStatusArguments(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            $message,
            'use wprism release <env> status --prepare=<file> '
                . '--expected-subject-sha256=sha256:<hex> --format=json'
        );
    }

    /**
     * @param list<string> $extra
     * @return array{authorization:string,expected_authorization_sha256:string,expected_plan_digest:string,expected_presentation_sha256:string,expected_stage_receipt_sha256:string,expected_subject_sha256:string,prepare:string}
     */
    private static function executeRequestFlags(array $extra): array {
        $out = [
            'authorization' => null,
            'expected_authorization_sha256' => null,
            'expected_plan_digest' => null,
            'expected_presentation_sha256' => null,
            'expected_stage_receipt_sha256' => null,
            'expected_subject_sha256' => null,
            'format_json' => false,
            'prepare' => null,
        ];
        $paths = ['--authorization' => 'authorization', '--prepare' => 'prepare'];
        $digests = [
            '--expected-authorization-sha256' => 'expected_authorization_sha256',
            '--expected-plan-digest' => 'expected_plan_digest',
            '--expected-presentation-sha256' => 'expected_presentation_sha256',
            '--expected-stage-receipt-sha256' => 'expected_stage_receipt_sha256',
            '--expected-subject-sha256' => 'expected_subject_sha256',
        ];
        foreach ($extra as $arg) {
            if (!is_string($arg)) {
                throw self::invalidExecuteArguments('release execute received a non-string argument');
            }
            $name = str_contains($arg, '=') ? explode('=', $arg, 2)[0] : $arg;
            $value = str_contains($arg, '=') ? substr($arg, strlen($name) + 1) : null;
            if (isset($paths[$name])) {
                $field = $paths[$name];
                if ($out[$field] !== null || $value === null || $value === ''
                    || preg_match('/[\x00-\x1f\x7f]/D', $value) === 1) {
                    throw self::invalidExecuteArguments("$name takes exactly one readable file path");
                }
                $out[$field] = $value;
                continue;
            }
            if (isset($digests[$name])) {
                $field = $digests[$name];
                if ($out[$field] !== null || $value === null
                    || preg_match('/^sha256:[a-f0-9]{64}$/D', $value) !== 1) {
                    throw self::invalidExecuteArguments(
                        "$name takes exactly one sha256:<64-lowercase-hex> value"
                    );
                }
                $out[$field] = $value;
                continue;
            }
            if ($name === '--format' && $value === 'json') {
                $out['format_json'] = true;
                continue;
            }
            if ($name === '--json' && $value === null) {
                $out['format_json'] = true;
                continue;
            }
            throw self::invalidExecuteArguments("release execute received an option it does not define: '$name'");
        }
        if (!is_string($out['authorization'])
            || !is_string($out['expected_authorization_sha256'])
            || !is_string($out['expected_plan_digest'])
            || !is_string($out['expected_presentation_sha256'])
            || !is_string($out['expected_stage_receipt_sha256'])
            || !is_string($out['expected_subject_sha256'])
            || !is_string($out['prepare'])) {
            throw self::invalidExecuteArguments('release execute requires every prepare, authorization and digest bind');
        }
        if ($out['format_json'] !== true) {
            throw self::invalidExecuteArguments('release execute requires --format=json for its single-document result');
        }

        return [
            'authorization' => $out['authorization'],
            'expected_authorization_sha256' => $out['expected_authorization_sha256'],
            'expected_plan_digest' => $out['expected_plan_digest'],
            'expected_presentation_sha256' => $out['expected_presentation_sha256'],
            'expected_stage_receipt_sha256' => $out['expected_stage_receipt_sha256'],
            'expected_subject_sha256' => $out['expected_subject_sha256'],
            'prepare' => $out['prepare'],
        ];
    }

    private static function invalidExecuteArguments(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            $message,
            'use wprism release <env> execute --prepare=<file> --authorization=<file> '
                . '--expected-authorization-sha256=sha256:<hex> --expected-subject-sha256=sha256:<hex> '
                . '--expected-presentation-sha256=sha256:<hex> --expected-plan-digest=sha256:<hex> '
                . '--expected-stage-receipt-sha256=sha256:<hex> --format=json'
        );
    }

    /**
     * The closed flag grammar.
     *
     * @param list<string> $extra
     * @return array<string,mixed>
     */
    private static function flags(array $extra): array {
        $out = [
            'accept_weaker_recovery' => false,
            'from' => null,
            'plan_only' => false,
            'profile' => null,
            'with_deletes' => false,
            'yes' => false,
        ];
        foreach ($extra as $arg) {
            if (!is_string($arg)) {
                throw self::invalidArguments('release received a non-string argument');
            }
            $name = str_contains($arg, '=') ? explode('=', $arg, 2)[0] : $arg;
            $value = str_contains($arg, '=') ? substr($arg, strlen($name) + 1) : null;
            switch ($name) {
                case '--from':
                    if ($out['from'] !== null || $value === null || $value === '' || str_starts_with($value, '-')) {
                        throw self::invalidArguments('--from takes exactly one --from=<ref> value');
                    }
                    $out['from'] = $value;
                    break;
                case '--profile':
                    if ($out['profile'] !== null
                        || $value === null
                        || !in_array($value, RecoveryProfileSelection::PROFILES, true)) {
                        throw self::invalidArguments(
                            '--profile must be one of ' . implode(', ', RecoveryProfileSelection::PROFILES)
                        );
                    }
                    $out['profile'] = $value;
                    break;
                case '--plan-only':
                    $out['plan_only'] = true;
                    break;
                case RecoveryProfileSelection::WEAKER_FLAG:
                    $out['accept_weaker_recovery'] = true;
                    break;
                case '--with-deletes':
                    $out['with_deletes'] = true;
                    break;
                case '--yes':
                    $out['yes'] = true;
                    break;
                case '--limit':
                case '--format':
                case '--json':
                    break;
                default:
                    throw self::invalidArguments("release received an option it does not define: '$name'");
            }
        }
        if ($out['plan_only'] && $out['yes']) {
            // Confirming a plan that will not be executed is a request with
            // two meanings; refusing is cheaper than guessing which one.
            throw self::invalidArguments('--plan-only and --yes contradict each other');
        }

        return $out;
    }

    private static function invalidArguments(string $message): CommandRefusalException {
        return new CommandRefusalException(
            'invalid_arguments',
            $message,
            'wprism release <env> accepts ' . implode(', ', self::FLAGS)
                . ' (--from=<ref>, --profile=<p>, --limit=<1..200>, --format=json)'
        );
    }

    /**
     * A pre-authorization refusal, which carries a §2.1 gap action and never
     * a release next action.
     *
     * @param list<array<string,mixed>> $diagnostics
     */
    private static function gapRefusal(
        string $reasonCode,
        string $message,
        string $remediation,
        string $gapAction,
        array $diagnostics = []
    ): CommandRefusalException {
        if (!in_array($gapAction, ProjectionVocabulary::GAP_ACTIONS, true)) {
            throw new \InvalidArgumentException('a pre-authorization refusal needs a §2.1 gap action');
        }
        $diagnostics[] = ['code' => $reasonCode, 'gap_action' => $gapAction];

        return new CommandRefusalException($reasonCode, $message, $remediation, $diagnostics);
    }

    /**
     * @param array{reason_code:string,message:string,remediation:string,gap_action:?string,diagnostics?:list<array<string,mixed>>} $spec
     */
    private static function fromSpec(array $spec): CommandRefusalException {
        $diagnostics = array_values($spec['diagnostics'] ?? []);
        if (is_string($spec['gap_action'] ?? null)) {
            $diagnostics[] = ['code' => (string) $spec['reason_code'], 'gap_action' => (string) $spec['gap_action']];
        }

        return new CommandRefusalException(
            (string) $spec['reason_code'],
            (string) $spec['message'],
            (string) $spec['remediation'],
            $diagnostics
        );
    }

    /** An environment name safe to print inside a remediation sentence. */
    private static function token(string $value): string {
        return preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $value) === 1 ? $value : '<env>';
    }
}
