<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/../Transport/Transport.php';
require_once __DIR__ . '/../Transport/CodeDeploy.php';
require_once __DIR__ . '/../Plan/PlanContract.php';
require_once __DIR__ . '/../Plan/PlanSummary.php';
require_once __DIR__ . '/../Contract/ApplicationContract.php';
require_once __DIR__ . '/../Contract/ContractStore.php';
require_once __DIR__ . '/../Contract/ProjectionVocabulary.php';
require_once __DIR__ . '/../Recovery/RecoveryClaim.php';
require_once __DIR__ . '/../Recovery/RollbackAuthority.php';
require_once __DIR__ . '/../Recovery/RecoveryProfileSelection.php';
require_once __DIR__ . '/../Release/AuthorizationPlan.php';
require_once __DIR__ . '/../Release/AuthorizationPlanRenderer.php';
require_once __DIR__ . '/../Release/JourneyOracle.php';
require_once __DIR__ . '/../Release/NextAction.php';
require_once __DIR__ . '/../Release/ReleaseOutcome.php';
require_once __DIR__ . '/AssessCommand.php';
require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/VerifyCommand.php';

use Duo\CommandRefusalException;

/**
 * `duo release <env>` — the composed release (round-3 MUP §2.3).
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
 * Step 6 calls one injected callable, and `cli/duo`'s `cmd_release()` binds
 * that callable to `cmd_promote()` — the same function `duo promote` itself
 * calls, routing through `PromoteCommand` to `cmd_promote_scoped()` /
 * `cmd_promote_internal()`. Deploy-before-apply ordering, the promotion
 * lease, the target fence, the checkpoint, the DUO-3310
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
 *     the journeys `duo verify` reads all live in it. The refusal is a
 *     PRE-authorization refusal and therefore carries a §2.1 gap action
 *     (`declare in contract`), never a release next action.
 *  2. **Target facts.** One `wp duo plan --format=json` read, its complete
 *     envelope proved through `PlanContract::requireComplete()`; the same
 *     plan rendered through `PlanSummary::render()` for the drift/readiness
 *     check; the target repository `HEAD` read through the driver; and the
 *     target's own content-addressed artifact hash. `--from <ref>` is a
 *     BINDING ASSERTION, not a git transport: the ref is resolved in the
 *     local site repository, compared with the target `HEAD`, and a mismatch
 *     refuses with the next action `reconcile` and the literal command. This
 *     command runs `git rev-parse` and nothing else — no fetch, no push, no
 *     checkout. MUP invents no code-shipping path that `promote` and the
 *     code-release provider do not already own.
 *  3. **Projection + pre-freeze refusals.** The projection is REGENERATED
 *     from current facts (MUP §3.4's refresh row: "any assess, status, or
 *     release regenerates projection.json"), never read from disk — a
 *     contract cannot certify itself, and a committed projection is a review
 *     artifact, not evidence about the target as it is now. Regeneration is
 *     unconditional — the plan rendered at step 5 always reflects current
 *     facts, `--plan-only` included — but persisting the regenerated
 *     document to `.duo/contract/projection.json` is a site-repository
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
 *     `.duo/releases/<plan_digest>.json` in the LOCAL site repository BEFORE
 *     any target mutation — the spec's "durably bind and present".
 *  6. **Execute**, with `AuthorizationPlan::reverify()` immediately before the
 *     mutating call: any difference between the frozen plan and the target as
 *     it is at that instant invalidates the authorization (`plan_changed`).
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
     * The lifecycle phases `promote` runs, in its own order.
     *
     * `retire` → `activate` happen on EVERY promotion, code or not
     * (`cli/duo`'s `cmd_promote_internal()` runs `lifecycle-retire` and
     * `lifecycle-activate` for a repository with no code descriptor too), and
     * they fire WordPress hooks. That is why the §1.6 containment gate can
     * fire on a state-only release: the honest answer is that the window is
     * entered, not that it is empty. `deploy` and `finalize` are added when
     * the plan reports code work, mirroring promote's `code-stage` and
     * `code-finalize` phases.
     *
     * @var list<string>
     */
    public const BASE_LIFECYCLE_PHASES = ['retire', 'activate', 'verify'];

    /** @var list<string> */
    public const CODE_LIFECYCLE_PHASES = ['deploy', 'finalize'];

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
     * @param callable(EnvironmentDriver,list<string>):int $promote the EXISTING
     *        promote entry point, injected by `cli/duo`'s `cmd_release()`
     * @param ?callable():?string $confirm reads one line of operator intent;
     *        null reads STDIN
     * @param ?callable():string $clock null reads the wall clock
     * @param ?callable(array):array $hostCatalog the assess injection seam
     * @param ?callable(EnvironmentDriver,array):array $verify null uses
     *        `VerifyCommand::report()`
     */
    public static function run(
        EnvironmentDriver $driver,
        array $extra,
        string $sourceRoot,
        callable $promote,
        ?callable $confirm = null,
        ?callable $clock = null,
        ?callable $hostCatalog = null,
        ?callable $verify = null
    ): int {
        $json = AssessCommand::wantsJson($extra);
        try {
            $flags = self::flags($extra);
            $limit = AuthorizationPlanRenderer::limitFromArgs($extra);
        } catch (CommandRefusalException $refusal) {
            return AssessCommand::renderRefusal($refusal, $json, 'release');
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
        ?callable $hostCatalog = null
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
                'run duo assess ' . self::token($driver->name()) . ', review '
                    . $store->proposalRelativePath($driver->name()) . ', then '
                    . 'duo contract ' . self::token($driver->name()) . ' accept',
                'declare in contract'
            );
        }

        // 2. Target facts.
        $plan = self::targetPlan($driver);
        // Pending deletions are this command's own decision, not an
        // assessment gap: `AuthorizationPlan::refusals()` refuses them by name
        // with `release_deletes_not_authorized` and no gap action
        // (cli/src/Release/AuthorizationPlan.php:613-625). DUO-3502 made a
        // pending deletion non-ready for `duo status`, which is right for an
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
        $head = self::targetHead($driver);
        $target = [
            'artifact_hash' => self::targetArtifactHash($driver),
            'code_revision_from' => $head,
            'head_revision' => $head,
            'repo_path' => $driver->repoPath(),
        ];
        self::bindRef($flags['from'], $head, $siteRepo, $driver);
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
            // in capture/merge, which is exactly what `duo status` prints.
            throw self::gapRefusal(
                'release_target_not_clean',
                'the target plan is not safe to promote, so there is nothing to authorize',
                'run duo status ' . self::token($driver->name())
                    . ', resolve every reported condition through capture/refresh/rebase, then re-run duo release',
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
        ]);
        $projection = AssessCommand::projection($assessment, $contract);
        // Regeneration above is unconditional (step 3's docblock), but
        // persisting it is a site-repository mutation, so it is gated on a
        // release that can proceed past this step. Without this gate every
        // `--plan-only` run rewrote `.duo/contract/projection.json` before
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
        // not a fact about this release, and `duo assess` already reports it.
        $inScope = array_values(array_filter(
            $all,
            static fn (array $row): bool => in_array((string) ($row['id'] ?? ''), $scope['surfaces'], true)
        ));

        $inputs = [
            'authority' => self::authority($plan, $scope),
            'capabilities' => self::capabilities($inScope),
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
        try {
            $current = $prepared['inputs'];
            $current['plan'] = self::targetPlan($driver);
            $current['target']['code_revision_from'] = self::targetHead($driver);
            $current['target']['head_revision'] = $current['target']['code_revision_from'];
            $current['target']['artifact_hash'] = self::targetArtifactHash($driver);
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

        // 7. Verify. `duo release` always verifies (MUP §2.3 step 5); an
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

        $outcome = ReleaseOutcome::released($driver->name(), $digest, $report);
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
     */
    private static function fail(
        EnvironmentDriver $driver,
        string $planDigest,
        string $failureClass,
        bool $json,
        ?CommandRefusalException $refusal
    ): int {
        $outcome = ReleaseOutcome::failedAfterFreeze($driver->name(), $planDigest, $failureClass);
        ReleaseOutcome::validate($outcome);
        if ($json) {
            echo ReleaseOutcome::encode($outcome);

            return 1;
        }
        if ($refusal !== null) {
            fwrite(STDERR, '[' . $refusal->reasonCode . '] ' . $refusal->publicMessage . "\n");
        }
        foreach (ReleaseOutcome::humanLines($outcome) as $line) {
            fwrite(STDERR, $line . "\n");
        }

        return 1;
    }

    /**
     * Report a pre-freeze refusal in whichever channel was asked for, and
     * record it as a `duo-release-outcome/v1` when JSON was asked for.
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

    /**
     * `--from <ref>` — a binding assertion over the target's own `HEAD`.
     *
     * The ref is resolved with `git rev-parse` in the LOCAL site repository
     * and compared with the revision the target repository is actually on.
     * Nothing is fetched, pushed or checked out: shipping code is
     * `promote`'s and the code-release provider's job, and a release that
     * quietly moved a target's git state would be exactly the invented
     * transport MUP §2.3 forbids.
     */
    private static function bindRef(?string $ref, string $head, string $siteRepo, EnvironmentDriver $driver): void {
        if ($ref === null) {
            return;
        }
        $resolved = self::localRevision($siteRepo, $ref);
        if (hash_equals($resolved, $head)) {
            return;
        }
        throw new CommandRefusalException(
            'release_ref_mismatch',
            'the target repository is not on the revision --from asserts, so this release would authorize one '
                . 'revision and mutate from another',
            'reconcile the two before releasing: bring the target repository to ' . substr($resolved, 0, 12)
                . ' through the deployment path that owns it, confirm with duo status '
                . self::token($driver->name()) . ', then re-run duo release --from with the same ref. Do not '
                . 'retry this release: it asserted a revision the target is not on, so a retry asserts it again',
            [[
                'code' => 'ref_mismatch',
                'failure_class' => 'ref_mismatch',
                'next_action' => NextAction::forFailure('ref_mismatch'),
                'reason' => NextAction::reasonFor('ref_mismatch'),
                'asserted_ref' => preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,127}$/D', $ref) === 1 ? $ref : '<ref>',
            ]]
        );
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
                'run duo release from inside the site repository whose environments this release targets'
            );
        }
        $stdout = (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        $revision = trim($stdout);
        if ($exit !== 0 || preg_match('/^[a-f0-9]{40,64}$/D', $revision) !== 1) {
            throw new CommandRefusalException(
                'release_ref_unresolvable',
                'the asserted ref does not resolve to a commit in the local site repository',
                'fetch or create the ref locally, then re-run duo release with the same --from value'
            );
        }

        return $revision;
    }

    /** The target repository's own `HEAD`, read through the driver. */
    private static function targetHead(EnvironmentDriver $driver): string {
        $result = $driver->captureRaw(
            'git -C ' . escapeshellarg($driver->repoPath()) . ' rev-parse HEAD'
        );
        $revision = trim((string) ($result['stdout'] ?? ''));
        if (($result['exit'] ?? 1) !== 0 || preg_match('/^[a-f0-9]{40,64}$/D', $revision) !== 1) {
            throw new CommandRefusalException(
                'release_target_revision_unknown',
                'the target repository did not report a revision, so no ref assertion about it can be checked',
                'confirm the target repo_path is a Git worktree and this transport can read it, then re-run duo release'
            );
        }

        return $revision;
    }

    /**
     * The target's own content address for this revision.
     *
     * `wp duo compile` is the sole source of truth for the artifact hash and
     * is explicitly target-read-free: it "compiles a canonical revision into
     * Duo's immutable, content-addressed apply artifact without reading or
     * mutating the target environment" (`agent/src/Command/Cli.php`). It is
     * invoked here WITHOUT `--out`, so nothing is written anywhere — which is
     * what lets `--plan-only` name the artifact it would release while still
     * mutating nothing at all.
     */
    private static function targetArtifactHash(EnvironmentDriver $driver): string {
        $result = $driver->captureWp(CodeDeploy::controlArgs([
            'duo', 'compile', '--repo=' . $driver->repoPath(), '--format=json',
        ]));
        $summary = ($result['exit'] ?? 1) === 0
            ? json_decode(trim((string) ($result['stdout'] ?? '')), true)
            : null;
        if (!is_array($summary) || !CodeDeploy::validArtifactHash($summary)) {
            throw new CommandRefusalException(
                'release_artifact_unavailable',
                'the target could not compile this revision into a content-addressed artifact, so there is no '
                    . 'identity to bind the authorization to',
                'run duo status ' . self::token($driver->name())
                    . ' and repair every repository, policy or code diagnostic it reports, then re-run duo release'
            );
        }

        return (string) $summary['artifact_hash'];
    }

    /**
     * One complete `wp duo plan --format=json` read.
     *
     * @return array<string,mixed>
     */
    private static function targetPlan(EnvironmentDriver $driver): array {
        $result = $driver->captureWp(['duo', 'plan', '--repo=' . $driver->repoPath(), '--format=json']);
        if (($result['exit'] ?? 1) !== 0) {
            $refusal = json_decode(trim((string) ($result['stdout'] ?? '')), true);
            if (is_array($refusal) && ($refusal['format'] ?? null) === 'duo-command-refusal/v1') {
                throw new CommandRefusalException(
                    is_string($refusal['reason_code'] ?? null) ? $refusal['reason_code'] : 'release_plan_unavailable',
                    (string) ($refusal['message'] ?? 'the target refused to produce a plan'),
                    (string) ($refusal['remediation'] ?? 'repair the target, then re-run duo release'),
                    array_values(array_filter((array) ($refusal['diagnostics'] ?? []), 'is_array'))
                );
            }
            throw new CommandRefusalException(
                'release_plan_unavailable',
                'the target could not produce a plan, so there is nothing to authorize',
                'run duo status ' . self::token($driver->name()) . ', repair the target, then re-run duo release'
            );
        }
        $decoded = json_decode(trim((string) ($result['stdout'] ?? '')), true);
        if (!is_array($decoded)) {
            throw new CommandRefusalException(
                'release_plan_unavailable',
                'the target returned plan output this build could not parse as JSON',
                'upgrade the target agent, then re-run duo release'
            );
        }

        try {
            return PlanContract::requireComplete($decoded, 'duo release');
        } catch (\Throwable $incomplete) {
            throw new CommandRefusalException(
                'release_plan_incomplete',
                'the target plan envelope is incomplete, so its counts cannot be trusted to describe this release',
                'upgrade the target agent to a build that emits the complete plan envelope, then re-run duo release',
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
     * publishes (`\Duo\PlanCategorySummary`). The host never classifies a
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
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private static function capabilities(array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            $operation = $row['operations'][self::OPERATION] ?? null;
            if (!is_array($operation)) {
                continue;
            }
            $out[] = [
                'certification_provenance' => (string) ($operation['certification_provenance'] ?? ''),
                'conditions' => array_values(
                    is_array($operation['conditions'] ?? null) ? $operation['conditions'] : []
                ),
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
     * (`cli/duo`'s `promote phase: checkpoint`), which is exactly the one
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
        // `duo promote` selects must be one answer: promote's dispatch tests
        // exactly this pair (cli/duo), so testing SshTransport here would let
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
            're-run duo release and answer yes to the authorization question, or pass --yes to confirm the '
                . 'displayed plan non-interactively'
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
            'duo release <env> accepts ' . implode(', ', self::FLAGS)
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
