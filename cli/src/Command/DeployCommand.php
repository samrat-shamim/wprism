<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/../Transport/EnvironmentDriver.php';
require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/CodeResolveCommand.php';
require_once __DIR__ . '/../Transport/CodeDeploy.php';

/**
 * Host command boundary for standalone immutable-artifact deployment.
 *
 * The larger promotion family still owns the shared scope, rollback-fence,
 * owner, and exact-cleanup primitives. They are explicit collaborators here
 * so deploy becomes independently callable without duplicating recovery law.
 *
 * Deploy takes its own whole-database checkpoint under its own lease, at the
 * position promote takes one (cli/wprism:2385-2388), and retains it as
 * `deploy-<runId>.sql` beside the `deploy-<runId>.json` artifact — the naming
 * that lets `RetainedCheckpoints` list and `wprism recover` restore it with no
 * second recovery mechanism. `--no-checkpoint` opts out and reproduces the
 * pre-checkpoint stream and wp-call sequence exactly.
 */
final class DeployCommand {
    /**
     * @param list<string> $extra
     * @param callable(list<string>):?int $scopeRefusal
     * @param callable(EnvironmentDriver):bool $rollbackFence
     * @param callable():string $runIdFactory
     * @param callable(EnvironmentDriver,array<string,mixed>,string,string):void $compensateUncertainBegin
     * @param callable(EnvironmentDriver,string,string):bool $abort exact lease
     *        cleanup after either failure or a host-recorded provider terminal
     * @param callable(EnvironmentDriver,string,bool):void $printRecovery
     *        a verb-aware `print_promotion_recovery` (cli/wprism:3311-3384). It
     *        arrives as a collaborator for the same reason the other five do:
     *        this handler stays callable without loading cli/wprism's globals.
     *        It takes no owner and no artifact hash: since issue #3525 the
     *        recovery guidance names one verb — `wprism recover <env>
     *        --restore=<id> --writers-excluded --operator-directed` — whose
     *        `<id>` is the checkpoint's own basename, so the lease identity is
     *        no longer an input to what gets PRINTED. `$abort` above still
     *        takes it, because that call actually uses it.
     */
    public static function run(
        EnvironmentDriver $transport,
        array $extra,
        callable $scopeRefusal,
        callable $rollbackFence,
        callable $runIdFactory,
        callable $compensateUncertainBegin,
        callable $abort,
        callable $printRecovery
    ): int {
        $scopeExit = $scopeRefusal($extra);
        if ($scopeExit !== null) return $scopeExit;
        try {
            $deployExtra = self::forceFlags($extra, 'deploy');
        } catch (\Throwable $e) {
            fwrite(STDERR, 'wprism: ' . $e->getMessage() . "\n");
            return 1;
        }
        if (!$rollbackFence($transport)) return 1;

        $repo = rtrim($transport->repoPath(), '/');
        $runId = $runIdFactory();
        $artifact = "$repo/.wprism/artifacts/deploy-$runId.json";
        // The checkpoint is the SIBLING of the artifact, stem for stem. That is
        // what makes `RetainedCheckpoints::script()`'s existing
        // `artifacts/$b.json` identity grep (RetainedCheckpoints.php:180) find
        // this deploy's lease identity with no second mechanism; a
        // `promote-<runId>.sql` here would list with an empty artifact_hash and
        // then refuse `checkpoint_identity_unknown` at --restore time.
        $checkpoint = "$repo/.wprism/checkpoints/deploy-$runId.sql.enc";
        $wantCheckpoint = self::checkpointRequested($extra);
        // One mkdir, two directories when a checkpoint is wanted — the shape
        // promote uses (cli/wprism:2190-2192). The message below names a failed
        // precondition, not a directory count, so it stays byte-identical in
        // both arms; `--no-checkpoint` must reproduce today's stream exactly.
        $mkdir = $transport->captureRaw(
            $wantCheckpoint
                ? 'mkdir -p ' . escapeshellarg(dirname($artifact)) . ' ' . escapeshellarg(dirname($checkpoint))
                : 'mkdir -p ' . escapeshellarg(dirname($artifact))
        );
        if ($mkdir['exit'] !== 0) {
            fwrite(STDERR, "wprism: deploy: could not create target artifact directory\n");
            CommandOutput::renderTransportDetail($mkdir);
            return $mkdir['exit'] !== 0 ? $mkdir['exit'] : 1;
        }

        // A format-2 release resolves and compiles from a disposable,
        // target-visible repository snapshot. The canonical repository is
        // therefore byte-identical when compile or any later preflight
        // refuses; see CodeResolveCommand::releaseCompile().
        $compiled = CodeResolveCommand::releaseCompile(
            $transport,
            'deploy',
            static function (string $compileRepo) use ($transport, $artifact): array {
                echo "deploy phase: compile\n";
                return CodeDeploy::compile($transport, $compileRepo, $artifact);
            }
        );
        if (is_int($compiled)) {
            return $compiled;
        }
        $compile = $compiled;
        if ($compile['exit'] !== 0) {
            fwrite(STDERR, "wprism: deploy: compile failed; no lifecycle or code materialization occurred\n");
            CommandOutput::renderTransportDetail($compile);
            return $compile['exit'] !== 0 ? $compile['exit'] : 1;
        }
        if ($compile['summary'] === null) {
            fwrite(STDERR, "wprism: deploy: compile returned no valid artifact hash; no lifecycle or code materialization occurred\n");
            return 1;
        }
        $dispositionBlockers = CodeDeploy::dispositionBlockers($compile['summary']);
        if ($dispositionBlockers) {
            foreach ($dispositionBlockers as $row) {
                fwrite(STDERR, "wprism: deploy: adapter {$row['name']} is {$row['status']}: {$row['reason']}\n");
            }
            fwrite(STDERR, "wprism: deploy: refusing before promotion-begin; only certified adapters may enter deployment\n");
            return 1;
        }

        $artifactHash = (string) $compile['summary']['artifact_hash'];
        $codeEnabled = CodeDeploy::enabled($compile['summary']);
        $schemaDeclared = CodeDeploy::schemaSettlementRequired($compile['summary']);
        $lifecycleSettlementDeclared = CodeDeploy::lifecycleSettlementDeclared($compile['summary']);
        $codeChangeRequired = false;
        if ($codeEnabled) {
            $preflight = self::runtimePreflight(
                $transport,
                'deploy',
                $repo,
                $artifact,
                $artifactHash,
                $compile['summary']
            );
            if (is_int($preflight)) return $preflight;
            $codeChangeRequired = $preflight;
        }
        $lifecycleTransitionRequired = $codeChangeRequired;
        if (!$codeChangeRequired) {
            echo "deploy phase: lifecycle-status\n";
            $lifecycleResult = $transport->captureWp(CodeDeploy::lifecycleStatusArgs(
                $repo,
                $artifact,
                $artifactHash,
                $deployExtra
            ));
            if (($lifecycleResult['exit'] ?? 1) !== 0) {
                fwrite(STDERR, "wprism: deploy: lifecycle preflight failed; no target mutation occurred\n");
                CommandOutput::renderTransportDetail($lifecycleResult);
                return ($lifecycleResult['exit'] ?? 1) !== 0 ? (int) $lifecycleResult['exit'] : 1;
            }
            try {
                $lifecycleStatus = CodeDeploy::lifecycleStatusResult($lifecycleResult);
            } catch (\Throwable $failure) {
                fwrite(STDERR, "wprism: deploy: {$failure->getMessage()}; no target mutation occurred\n");
                return 1;
            }
            $lifecycleTransitionRequired = $lifecycleStatus['required'];
        }
        $schemaSettlementRequired = false;
        if ($schemaDeclared) {
            echo "deploy phase: schema-status\n";
            $schemaStatusResult = $transport->captureWp(CodeDeploy::schemaStatusArgs(
                $repo,
                $artifact,
                $artifactHash,
                $lifecycleTransitionRequired
            ));
            if (($schemaStatusResult['exit'] ?? 1) !== 0) {
                fwrite(STDERR, "wprism: deploy: schema readiness preflight failed; no target mutation occurred\n");
                CommandOutput::renderTransportDetail($schemaStatusResult);
                return ($schemaStatusResult['exit'] ?? 1) !== 0 ? (int) $schemaStatusResult['exit'] : 1;
            }
            try {
                $schemaStatus = CodeDeploy::schemaStatusResult($schemaStatusResult);
            } catch (\Throwable $failure) {
                fwrite(STDERR, "wprism: deploy: {$failure->getMessage()}; no target mutation occurred\n");
                return 1;
            }
            if (!$schemaStatus['declared']) {
                fwrite(STDERR, "wprism: deploy: compiled schema effects disagree with target policy; no target mutation occurred\n");
                return 1;
            }
            if ($schemaStatus['mode'] !== ($lifecycleTransitionRequired ? 'presence' : 'exact')) {
                fwrite(STDERR, "wprism: deploy: target returned the wrong schema readiness mode; no target mutation occurred\n");
                return 1;
            }
            // Activation can create, drop, or alter plugin tables. A declared
            // schema authority therefore always gets a checkpointed fresh
            // post-activation pass; only unchanged code may skip on exact
            // digest-bound readiness evidence.
            $schemaSettlementRequired = $lifecycleTransitionRequired || $schemaStatus['required'];
        }
        // A lifecycle-settlement provider runs only after the ordered fresh
        // retire/activate boundary. Missing schema can itself create derived
        // state debt, so establish even no-op lifecycle phases before that
        // provider when schema settlement is the only initial finding.
        $lifecyclePhasesRequired = $lifecycleTransitionRequired
            || ($schemaSettlementRequired && $lifecycleSettlementDeclared);
        $lifecycleSettlementRequired = $codeChangeRequired
            || ($lifecycleSettlementDeclared
                && ($lifecyclePhasesRequired || $schemaSettlementRequired));
        $providerSettlementPhases = [];
        if ($schemaSettlementRequired) {
            $providerSettlementPhases[] = 'schema-settle';
        }
        if ($lifecycleSettlementDeclared && $lifecycleSettlementRequired) {
            $providerSettlementPhases[] = 'lifecycle-settle';
        }
        $providerTransactionPhases = $providerSettlementPhases;
        if ($providerTransactionPhases !== [] && $lifecyclePhasesRequired) {
            $providerTransactionPhases = array_merge(
                ['lifecycle-retire', 'lifecycle-activate'],
                $providerTransactionPhases
            );
        }
        if (!$codeChangeRequired && !$lifecyclePhasesRequired && !$schemaSettlementRequired) {
            echo $codeEnabled
                ? "deploy complete: code revision already exact; lifecycle hooks not run\n"
                : "deploy complete: no code descriptor; lifecycle hooks not run\n";
            return 0;
        }
        if ($providerSettlementPhases !== [] && !$wantCheckpoint) {
            fwrite(
                STDERR,
                'wprism: deploy: --no-checkpoint cannot authorize restorable provider settlement effects; '
                . "run checkpointed host deploy before strict planning or lifecycle settlement\n"
            );
            return 1;
        }
        echo "deploy phase: promotion-begin\n";
        $begin = $transport->captureWp(CodeDeploy::beginArgs($repo, $runId, $artifactHash));
        if ($begin['exit'] !== 0) {
            fwrite(STDERR, "wprism: deploy: promotion-begin failed; lifecycle and code materialization were not started\n");
            CommandOutput::renderTransportDetail($begin);
            $compensateUncertainBegin($transport, $begin, $runId, $artifactHash);
            return $begin['exit'] !== 0 ? $begin['exit'] : 1;
        }

        if ($wantCheckpoint) {
            // Under the lease, exactly where promote takes it
            // (cli/wprism:2385-2388). The dump therefore contains the promotion
            // lease row this deploy just took, which is the whole reason
            // `wprism recover`'s four steps re-take that same (owner,
            // artifact_hash) pair before importing
            // (RecoverCommand.php:113 ORDERED_STEPS). Taken
            // before promotion-begin the dump would carry no lease row or a
            // stale one; taken after code-stage it would already describe
            // mutated code.
            echo "deploy phase: checkpoint\n";
            $export = CodeDeploy::encryptedCheckpoint($transport, $repo, $checkpoint);
            if ($export['exit'] !== 0) {
                fwrite(STDERR, "wprism: deploy: database checkpoint failed; code and lifecycle phases were not started\n");
                CommandOutput::renderTransportDetail($export);
                if ($abort($transport, $runId, $artifactHash)) {
                    fwrite(STDERR, "wprism: deploy: promotion lease cleanup confirmed; no usable checkpoint was produced\n");
                }
                return $export['exit'] !== 0 ? $export['exit'] : 1;
            }
            echo "database checkpoint: $checkpoint\n";
        }

        if ($codeChangeRequired) {
            echo "deploy phase: code-stage\n";
            $stage = $transport->streamWp(CodeDeploy::stageArgs($repo, $artifact, $runId, $artifactHash));
            if ($stage !== 0) {
                fwrite(STDERR, "wprism: deploy: code-stage failed (exit $stage); lifecycle phases and code-finalize were not run\n");
                self::cleanupAndGuide(
                    $transport, $abort, $printRecovery, $wantCheckpoint, $checkpoint, true, $runId, $artifactHash
                );
                return $stage;
            }
        }

        if ($providerTransactionPhases !== []) {
            // Code staging has its own checkpoint/session receipt. Publish the
            // external provider transaction before the first lifecycle hook or
            // provider mutation, and include both lifecycle processes in its
            // closed ordered phase list.
            echo "deploy phase: provider-settlement-begin\n";
            $providerBegin = CodeDeploy::beginProviderSettlement(
                $transport,
                $repo,
                $artifact,
                $checkpoint,
                $runId,
                $artifactHash,
                $providerTransactionPhases
            );
            if (($providerBegin['exit'] ?? 1) !== 0) {
                fwrite(
                    STDERR,
                    "wprism: deploy: provider settlement authorization failed; lifecycle/provider phases were not started\n"
                );
                CommandOutput::renderTransportDetail($providerBegin);
                self::cleanupAndGuide(
                    $transport,
                    $abort,
                    $printRecovery,
                    true,
                    $checkpoint,
                    $codeChangeRequired,
                    $runId,
                    $artifactHash
                );
                return ($providerBegin['exit'] ?? 1) !== 0 ? (int) $providerBegin['exit'] : 1;
            }
        }

        if ($lifecyclePhasesRequired) {
            echo "deploy phase: lifecycle-retire\n";
            $retire = $transport->streamWp(CodeDeploy::lifecycleArgs(
                $repo,
                $artifact,
                $runId,
                $artifactHash,
                true,
                $codeChangeRequired,
                false,
                'retire',
                $deployExtra,
                in_array('lifecycle-retire', $providerTransactionPhases, true) ? $checkpoint : ''
            ));
            if ($retire !== 0) {
                fwrite(STDERR, "wprism: deploy: lifecycle retirement failed (exit $retire); later phases were not run\n");
                self::cleanupAndGuide(
                    $transport,
                    $abort,
                    $printRecovery,
                    $wantCheckpoint,
                    $checkpoint,
                    $codeChangeRequired,
                    $runId,
                    $artifactHash
                );
                return $retire;
            }
            if (in_array('lifecycle-retire', $providerTransactionPhases, true)) {
                $retireAdvance = CodeDeploy::advanceProviderSettlement(
                    $transport,
                    $repo,
                    $artifact,
                    $checkpoint,
                    $runId,
                    $artifactHash,
                    $providerTransactionPhases,
                    'lifecycle-retire'
                );
                if (($retireAdvance['exit'] ?? 1) !== 0) {
                    fwrite(
                        STDERR,
                        "wprism: deploy: lifecycle retirement succeeded but durable phase progress was not recorded\n"
                    );
                    CommandOutput::renderTransportDetail($retireAdvance);
                    self::cleanupAndGuide(
                        $transport, $abort, $printRecovery, true, $checkpoint,
                        $codeChangeRequired, $runId, $artifactHash
                    );
                    return ($retireAdvance['exit'] ?? 1) !== 0 ? (int) $retireAdvance['exit'] : 1;
                }
            }

            echo "deploy phase: lifecycle-activate\n";
            $activate = $transport->streamWp(CodeDeploy::lifecycleArgs(
                $repo,
                $artifact,
                $runId,
                $artifactHash,
                $codeChangeRequired || $schemaSettlementRequired || $lifecycleSettlementRequired,
                $codeChangeRequired,
                false,
                'activate',
                $deployExtra,
                in_array('lifecycle-activate', $providerTransactionPhases, true) ? $checkpoint : ''
            ));
            if ($activate !== 0) {
                fwrite(STDERR, "wprism: deploy: lifecycle activation failed (exit $activate); later phases were not run\n");
                self::cleanupAndGuide(
                    $transport,
                    $abort,
                    $printRecovery,
                    $wantCheckpoint,
                    $checkpoint,
                    $codeChangeRequired,
                    $runId,
                    $artifactHash
                );
                return $activate;
            }
            if (in_array('lifecycle-activate', $providerTransactionPhases, true)) {
                $activateAdvance = CodeDeploy::advanceProviderSettlement(
                    $transport,
                    $repo,
                    $artifact,
                    $checkpoint,
                    $runId,
                    $artifactHash,
                    $providerTransactionPhases,
                    'lifecycle-activate'
                );
                if (($activateAdvance['exit'] ?? 1) !== 0) {
                    fwrite(
                        STDERR,
                        "wprism: deploy: lifecycle activation succeeded but durable phase progress was not recorded\n"
                    );
                    CommandOutput::renderTransportDetail($activateAdvance);
                    self::cleanupAndGuide(
                        $transport, $abort, $printRecovery, true, $checkpoint,
                        $codeChangeRequired, $runId, $artifactHash
                    );
                    return ($activateAdvance['exit'] ?? 1) !== 0 ? (int) $activateAdvance['exit'] : 1;
                }
            }
        }

        if ($schemaSettlementRequired) {
            echo "deploy phase: schema-settle\n";
            $schema = $transport->streamWp(CodeDeploy::schemaSettleArgs(
                $repo,
                $artifact,
                $artifactHash,
                $runId,
                $checkpoint,
                $lifecyclePhasesRequired,
                false
            ));
            if ($schema !== 0) {
                fwrite(STDERR, "wprism: deploy: schema settlement failed (exit $schema); later phases were not run\n");
                self::cleanupAndGuide(
                    $transport,
                    $abort,
                    $printRecovery,
                    $wantCheckpoint,
                    $checkpoint,
                    $codeChangeRequired,
                    $runId,
                    $artifactHash
                );
                return $schema;
            }
            $schemaAdvance = CodeDeploy::advanceProviderSettlement(
                $transport,
                $repo,
                $artifact,
                $checkpoint,
                $runId,
                $artifactHash,
                $providerTransactionPhases,
                'schema-settle'
            );
            if (($schemaAdvance['exit'] ?? 1) !== 0) {
                fwrite(STDERR, "wprism: deploy: schema succeeded but durable provider phase progress was not recorded\n");
                CommandOutput::renderTransportDetail($schemaAdvance);
                self::cleanupAndGuide(
                    $transport,
                    $abort,
                    $printRecovery,
                    true,
                    $checkpoint,
                    $codeChangeRequired,
                    $runId,
                    $artifactHash
                );
                return ($schemaAdvance['exit'] ?? 1) !== 0 ? (int) $schemaAdvance['exit'] : 1;
            }
        }

        if ($lifecycleSettlementRequired) {
            echo "deploy phase: lifecycle-settle\n";
            $settle = $transport->streamWp(CodeDeploy::lifecycleSettleArgs(
                $repo,
                $artifact,
                $artifactHash,
                $runId,
                in_array('lifecycle-settle', $providerSettlementPhases, true) ? $checkpoint : '',
                false
            ));
            if ($settle !== 0) {
                fwrite(STDERR, "wprism: deploy: asynchronous lifecycle settlement failed (exit $settle); code-finalize was not run\n");
                self::cleanupAndGuide(
                    $transport,
                    $abort,
                    $printRecovery,
                    $wantCheckpoint,
                    $checkpoint,
                    $codeChangeRequired,
                    $runId,
                    $artifactHash
                );
                return $settle;
            }
            if (in_array('lifecycle-settle', $providerSettlementPhases, true)) {
                $lifecycleAdvance = CodeDeploy::advanceProviderSettlement(
                    $transport,
                    $repo,
                    $artifact,
                    $checkpoint,
                    $runId,
                    $artifactHash,
                    $providerTransactionPhases,
                    'lifecycle-settle'
                );
                if (($lifecycleAdvance['exit'] ?? 1) !== 0) {
                    fwrite(
                        STDERR,
                        "wprism: deploy: lifecycle settlement succeeded but durable provider phase progress was not recorded\n"
                    );
                    CommandOutput::renderTransportDetail($lifecycleAdvance);
                    self::cleanupAndGuide(
                        $transport,
                        $abort,
                        $printRecovery,
                        true,
                        $checkpoint,
                        $codeChangeRequired,
                        $runId,
                        $artifactHash
                    );
                    return ($lifecycleAdvance['exit'] ?? 1) !== 0
                        ? (int) $lifecycleAdvance['exit']
                        : 1;
                }
            }
        }

        if ($providerTransactionPhases !== []) {
            echo "deploy phase: provider-settlement-complete\n";
            $providerComplete = CodeDeploy::completeProviderSettlement(
                $transport,
                $repo,
                $artifact,
                $checkpoint,
                $runId,
                $artifactHash,
                $providerTransactionPhases
            );
            if (($providerComplete['exit'] ?? 1) !== 0) {
                fwrite(
                    STDERR,
                    "wprism: deploy: provider phases succeeded but durable settlement debt could not be cleared\n"
                );
                CommandOutput::renderTransportDetail($providerComplete);
                self::cleanupAndGuide(
                    $transport,
                    $abort,
                    $printRecovery,
                    true,
                    $checkpoint,
                    $codeChangeRequired,
                    $runId,
                    $artifactHash
                );
                return ($providerComplete['exit'] ?? 1) !== 0 ? (int) $providerComplete['exit'] : 1;
            }
            if (!$codeChangeRequired && !$abort($transport, $runId, $artifactHash)) {
                fwrite(
                    STDERR,
                    "wprism: deploy: provider settlement completed but promotion lease cleanup could not be confirmed\n"
                );
                return 1;
            }
        }

        if ($codeChangeRequired) {
            echo "deploy phase: code-finalize\n";
            $finalize = $transport->streamWp(CodeDeploy::finalizeArgs($repo, $artifact, $runId, $artifactHash, false));
            if ($finalize !== 0) {
                fwrite(STDERR, "wprism: deploy: code-finalize failed (exit $finalize); later phases were not run\n");
                self::cleanupAndGuide(
                    $transport, $abort, $printRecovery, $wantCheckpoint, $checkpoint, true, $runId, $artifactHash
                );
                return $finalize;
            }
            // The completion line is unchanged; the retained line is a separate
            // fact, in the position and wording promote uses (cli/wprism:2465).
            echo $schemaSettlementRequired
                ? "deploy complete: code-stage -> lifecycle-retire -> lifecycle-activate -> schema-settle -> lifecycle-settle -> code-finalize\n"
                : "deploy complete: code-stage -> lifecycle-retire -> lifecycle-activate -> lifecycle-settle -> code-finalize\n";
            if ($wantCheckpoint) {
                echo "database checkpoint retained: $checkpoint\n";
            }
            return 0;
        }

        $phases = [];
        if ($lifecyclePhasesRequired) {
            $phases[] = 'lifecycle-retire';
            $phases[] = 'lifecycle-activate';
        }
        if ($schemaSettlementRequired) {
            $phases[] = 'schema-settle';
        }
        if ($lifecycleSettlementRequired) {
            $phases[] = 'lifecycle-settle';
        }
        echo 'deploy complete: ' . implode(' -> ', $phases) . '; '
            . ($codeEnabled ? 'code revision already exact' : 'no code descriptor') . "\n";
        if ($wantCheckpoint) {
            echo "database checkpoint retained: $checkpoint\n";
        }
        return 0;
    }

    /**
     * The one post-checkpoint failure epilogue: exact lease cleanup, then —
     * only when a checkpoint exists — the four numbered instructions that make
     * it usable.
     *
     * This mirrors `promote_failed()` (cli/wprism:3310-3316) rather than
     * discarding the abort's result: a checkpoint an operator is never told how
     * to use is not a recovery story, and the code-first ordering
     * `RecoverCommand::assertCodeFirst()` enforces has to be announced at
     * failure time rather than discovered at `--restore` time. Under
     * `--no-checkpoint` nothing here prints, so that arm reproduces today's
     * stream byte for byte.
     *
     * @param callable(EnvironmentDriver,string,string):bool $abort
     * @param callable(EnvironmentDriver,string,bool):void $printRecovery
     */
    private static function cleanupAndGuide(
        EnvironmentDriver $transport,
        callable $abort,
        callable $printRecovery,
        bool $wantCheckpoint,
        string $checkpoint,
        bool $codeMayHaveChanged,
        string $runId,
        string $artifactHash
    ): void {
        $clean = $abort($transport, $runId, $artifactHash);
        if (!$wantCheckpoint) {
            return;
        }
        if ($clean) {
            fwrite(STDERR, "wprism: deploy: promotion lease cleanup confirmed\n");
            $printRecovery($transport, $checkpoint, $codeMayHaveChanged);
            return;
        }
        fwrite(STDERR, "wprism: deploy: do not begin checkpoint recovery until the exact lease cleanup command above succeeds. Expiry lets a different promotion owner recover the target; it does not authorize this checkpoint restore.\n");
    }

    /** @return list<string> force flags that may also be passed to both lifecycle phases. */
    public static function forceFlags(array $extra, string $verb): array {
        $allowed = [];
        foreach ($extra as $arg) {
            if (self::isOrchestratorInternalFlag($arg)) {
                throw new \RuntimeException(
                    "$verb owns its repository, compiled artifact/hash, code-materialization, and target lease flags; remove '$arg'"
                );
            }
            if ($arg === '--force-code-mismatch' || $arg === '--force-code-drift'
                || str_starts_with($arg, '--force-code-mismatch=')
                || str_starts_with($arg, '--force-code-drift=')) {
                $allowed[] = $arg;
                continue;
            }
            // Accepted here and DROPPED: the two lifecycle phases own no
            // checkpoint, so forwarding --no-checkpoint to them would offer the
            // agent a flag it must refuse.
            if ($arg === '--no-checkpoint') {
                continue;
            }
            throw new \RuntimeException(
                "wprism $verb: unsupported deploy flag '$arg' (only --force-code-mismatch, --force-code-drift and --no-checkpoint are accepted)"
            );
        }
        return $allowed;
    }

    /**
     * Whether this invocation takes its database checkpoint. Default is yes.
     *
     * Exact match only, with no `=value` form: a `--no-checkpoint=false` that
     * silently checkpointed anyway would be the quiet reinterpretation
     * AGENTS.md rule 9 forbids, and `forceFlags()` refuses any spelling this
     * does not recognise before the first target call.
     *
     * @param list<string> $extra
     */
    public static function checkpointRequested(array $extra): bool {
        return !in_array('--no-checkpoint', $extra, true);
    }

    /**
     * Acquire ephemeral target PHP/WordPress evidence for the exact frozen code
     * revision. This is read-only and completes before promotion-begin, so it
     * has no lease or checkpoint cleanup to perform on failure.
     */
    public static function runtimePreflight(
        EnvironmentDriver $transport,
        string $verb,
        string $repo,
        string $artifact,
        string $artifactHash,
        array $compiledSummary
    ): bool|int {
        $codeRevision = $compiledSummary['code']['code_revision'] ?? null;
        if (!is_string($codeRevision) || preg_match('/^[0-9a-f]{64}$/', $codeRevision) !== 1) {
            fwrite(STDERR, "wprism: $verb: compiled code descriptor has no valid revision; refusing before promotion-begin\n");
            return 1;
        }
        echo "$verb phase: code-preflight\n";
        $preflight = CodeDeploy::preflight($transport, $repo, $artifact, $artifactHash, $codeRevision);
        if ($preflight['exit'] !== 0) {
            $boundary = $verb === 'promote' ? 'promotion-begin/checkpoint' : 'promotion-begin';
            fwrite(STDERR, "wprism: $verb: code runtime preflight failed; refusing before $boundary\n");
            CommandOutput::renderTransportDetail($preflight);
            return $preflight['exit'] !== 0 ? $preflight['exit'] : 1;
        }
        if ($preflight['summary'] === null) {
            $boundary = $verb === 'promote' ? 'promotion-begin/checkpoint' : 'promotion-begin';
            fwrite(STDERR, "wprism: $verb: code runtime preflight returned no valid target evidence; refusing before $boundary\n");
            CommandOutput::renderTransportDetail($preflight);
            return 1;
        }
        return (bool) $preflight['summary']['change_required'];
    }

    private static function isOrchestratorInternalFlag(string $arg): bool {
        foreach (['--repo', '--compiled', '--artifact-hash', '--promotion-owner', '--promotion-hold', '--materializing-code', '--state-handoff', '--lifecycle-phase'] as $flag) {
            if ($arg === $flag || str_starts_with($arg, $flag . '=')) return true;
        }
        return false;
    }
}
