<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

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
 * position promote takes one (cli/duo:2385-2388), and retains it as
 * `deploy-<runId>.sql` beside the `deploy-<runId>.json` artifact — the naming
 * that lets `RetainedCheckpoints` list and `duo recover` restore it with no
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
     * @param callable(EnvironmentDriver,string,string):bool $abort
     * @param callable(EnvironmentDriver,string,bool,string,string):void $printRecovery
     *        a verb-aware `print_promotion_recovery` (cli/duo:3272). It arrives
     *        as a collaborator for the same reason the other five do: this
     *        handler stays callable without loading cli/duo's globals.
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
            fwrite(STDERR, 'duo: ' . $e->getMessage() . "\n");
            return 1;
        }
        if (!$rollbackFence($transport)) return 1;

        $repo = rtrim($transport->repoPath(), '/');
        $runId = $runIdFactory();
        $artifact = "$repo/.duo/artifacts/deploy-$runId.json";
        // The checkpoint is the SIBLING of the artifact, stem for stem. That is
        // what makes `RetainedCheckpoints::script()`'s existing
        // `artifacts/$b.json` identity grep (RetainedCheckpoints.php:180) find
        // this deploy's lease identity with no second mechanism; a
        // `promote-<runId>.sql` here would list with an empty artifact_hash and
        // then refuse `checkpoint_identity_unknown` at --restore time.
        $checkpoint = "$repo/.duo/checkpoints/deploy-$runId.sql";
        $wantCheckpoint = self::checkpointRequested($extra);
        // One mkdir, two directories when a checkpoint is wanted — the shape
        // promote uses (cli/duo:2190-2192). The message below names a failed
        // precondition, not a directory count, so it stays byte-identical in
        // both arms; `--no-checkpoint` must reproduce today's stream exactly.
        $mkdir = $transport->captureRaw(
            $wantCheckpoint
                ? 'mkdir -p ' . escapeshellarg(dirname($artifact)) . ' ' . escapeshellarg(dirname($checkpoint))
                : 'mkdir -p ' . escapeshellarg(dirname($artifact))
        );
        if ($mkdir['exit'] !== 0) {
            fwrite(STDERR, "duo: deploy: could not create target artifact directory\n");
            CommandOutput::renderTransportDetail($mkdir);
            return $mkdir['exit'] !== 0 ? $mkdir['exit'] : 1;
        }

        // DUO-3500: host-side, before compile, and outside every lease. It
        // prints nothing at all unless this repository declares a code lock,
        // so an ordinary format-1 deploy emits exactly the phase lines it
        // always did. When a lock IS declared, resolving here is what makes
        // the compile below able to hash the declared bytes instead of
        // refusing `code_component_unresolved`
        // (agent/src/Code/CodeDescriptorCompiler.php:144-153).
        $resolveExit = CodeResolveCommand::deployPhase($transport, 'deploy');
        if ($resolveExit !== null) {
            return $resolveExit;
        }
        echo "deploy phase: compile\n";
        $compile = CodeDeploy::compile($transport, $repo, $artifact);
        if ($compile['exit'] !== 0) {
            fwrite(STDERR, "duo: deploy: compile failed; no lifecycle or code materialization occurred\n");
            CommandOutput::renderTransportDetail($compile);
            return $compile['exit'] !== 0 ? $compile['exit'] : 1;
        }
        if ($compile['summary'] === null) {
            fwrite(STDERR, "duo: deploy: compile returned no valid artifact hash; no lifecycle or code materialization occurred\n");
            return 1;
        }
        $dispositionBlockers = CodeDeploy::dispositionBlockers($compile['summary']);
        if ($dispositionBlockers) {
            foreach ($dispositionBlockers as $row) {
                fwrite(STDERR, "duo: deploy: adapter {$row['name']} is {$row['status']}: {$row['reason']}\n");
            }
            fwrite(STDERR, "duo: deploy: refusing before promotion-begin; only certified adapters may enter deployment\n");
            return 1;
        }

        $artifactHash = (string) $compile['summary']['artifact_hash'];
        $codeEnabled = CodeDeploy::enabled($compile['summary']);
        if ($codeEnabled) {
            $preflightExit = self::runtimePreflight($transport, 'deploy', $repo, $artifact, $artifactHash, $compile['summary']);
            if ($preflightExit !== null) return $preflightExit;
        }
        echo "deploy phase: promotion-begin\n";
        $begin = $transport->captureWp(CodeDeploy::beginArgs($runId, $artifactHash));
        if ($begin['exit'] !== 0) {
            fwrite(STDERR, "duo: deploy: promotion-begin failed; lifecycle and code materialization were not started\n");
            CommandOutput::renderTransportDetail($begin);
            $compensateUncertainBegin($transport, $begin, $runId, $artifactHash);
            return $begin['exit'] !== 0 ? $begin['exit'] : 1;
        }

        if ($wantCheckpoint) {
            // Under the lease, exactly where promote takes it
            // (cli/duo:2385-2388). The dump therefore contains the promotion
            // lease row this deploy just took, which is the whole reason
            // `duo recover`'s four steps re-take that same (owner,
            // artifact_hash) pair before importing (cli/duo:3289-3297). Taken
            // before promotion-begin the dump would carry no lease row or a
            // stale one; taken after code-stage it would already describe
            // mutated code.
            echo "deploy phase: checkpoint\n";
            $export = $transport->captureWp(['db', 'export', $checkpoint, '--porcelain']);
            if ($export['exit'] !== 0) {
                fwrite(STDERR, "duo: deploy: database checkpoint failed; code and lifecycle phases were not started\n");
                CommandOutput::renderTransportDetail($export);
                if ($abort($transport, $runId, $artifactHash)) {
                    fwrite(STDERR, "duo: deploy: promotion lease cleanup confirmed; no usable checkpoint was produced\n");
                }
                return $export['exit'] !== 0 ? $export['exit'] : 1;
            }
            echo "database checkpoint: $checkpoint\n";
        }

        if ($codeEnabled) {
            echo "deploy phase: code-stage\n";
            $stage = $transport->streamWp(CodeDeploy::stageArgs($repo, $artifact, $runId, $artifactHash));
            if ($stage !== 0) {
                fwrite(STDERR, "duo: deploy: code-stage failed (exit $stage); lifecycle phases and code-finalize were not run\n");
                self::cleanupAndGuide(
                    $transport, $abort, $printRecovery, $wantCheckpoint, $checkpoint, true, $runId, $artifactHash
                );
                return $stage;
            }
        }

        echo "deploy phase: lifecycle-retire\n";
        $retire = $transport->streamWp(CodeDeploy::lifecycleArgs(
            $repo, $artifact, $runId, $artifactHash, true, $codeEnabled, false, 'retire', $deployExtra
        ));
        if ($retire !== 0) {
            fwrite(STDERR, "duo: deploy: lifecycle retirement failed (exit $retire); later phases were not run\n");
            // $codeEnabled, not true: with no code descriptor this phase staged
            // nothing, which is the same boolean promote passes (cli/duo:2430).
            self::cleanupAndGuide(
                $transport, $abort, $printRecovery, $wantCheckpoint, $checkpoint, $codeEnabled, $runId, $artifactHash
            );
            return $retire;
        }

        echo "deploy phase: lifecycle-activate\n";
        $activate = $transport->streamWp(CodeDeploy::lifecycleArgs(
            $repo, $artifact, $runId, $artifactHash, $codeEnabled, $codeEnabled, false, 'activate', $deployExtra
        ));
        if ($activate !== 0) {
            fwrite(STDERR, "duo: deploy: lifecycle activation failed (exit $activate); later phases were not run\n");
            self::cleanupAndGuide(
                $transport, $abort, $printRecovery, $wantCheckpoint, $checkpoint, $codeEnabled, $runId, $artifactHash
            );
            return $activate;
        }

        if ($codeEnabled) {
            echo "deploy phase: code-finalize\n";
            $finalize = $transport->streamWp(CodeDeploy::finalizeArgs($repo, $artifact, $runId, $artifactHash, false));
            if ($finalize !== 0) {
                fwrite(STDERR, "duo: deploy: code-finalize failed (exit $finalize); later phases were not run\n");
                self::cleanupAndGuide(
                    $transport, $abort, $printRecovery, $wantCheckpoint, $checkpoint, true, $runId, $artifactHash
                );
                return $finalize;
            }
            // The completion line is unchanged; the retained line is a separate
            // fact, in the position and wording promote uses (cli/duo:2465).
            echo "deploy complete: code-stage -> lifecycle-retire -> lifecycle-activate -> code-finalize\n";
            if ($wantCheckpoint) {
                echo "database checkpoint retained: $checkpoint\n";
            }
            return 0;
        }

        echo "deploy complete: lifecycle-retire -> lifecycle-activate (no code descriptor)\n";
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
     * This mirrors `promote_failed()` (cli/duo:3310-3316) rather than
     * discarding the abort's result: a checkpoint an operator is never told how
     * to use is not a recovery story, and the code-first ordering
     * `RecoverCommand::assertCodeFirst()` enforces has to be announced at
     * failure time rather than discovered at `--restore` time. Under
     * `--no-checkpoint` nothing here prints, so that arm reproduces today's
     * stream byte for byte.
     *
     * @param callable(EnvironmentDriver,string,string):bool $abort
     * @param callable(EnvironmentDriver,string,bool,string,string):void $printRecovery
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
            fwrite(STDERR, "duo: deploy: promotion lease cleanup confirmed\n");
            $printRecovery($transport, $checkpoint, $codeMayHaveChanged, $runId, $artifactHash);
            return;
        }
        fwrite(STDERR, "duo: deploy: do not begin checkpoint recovery until the exact lease cleanup command above succeeds. Expiry lets a different promotion owner recover the target; it does not authorize this checkpoint restore.\n");
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
                "duo $verb: unsupported deploy flag '$arg' (only --force-code-mismatch, --force-code-drift and --no-checkpoint are accepted)"
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
    ): ?int {
        $codeRevision = $compiledSummary['code']['code_revision'] ?? null;
        if (!is_string($codeRevision) || preg_match('/^[0-9a-f]{64}$/', $codeRevision) !== 1) {
            fwrite(STDERR, "duo: $verb: compiled code descriptor has no valid revision; refusing before promotion-begin\n");
            return 1;
        }
        echo "$verb phase: code-preflight\n";
        $preflight = CodeDeploy::preflight($transport, $repo, $artifact, $artifactHash, $codeRevision);
        if ($preflight['exit'] !== 0) {
            $boundary = $verb === 'promote' ? 'promotion-begin/checkpoint' : 'promotion-begin';
            fwrite(STDERR, "duo: $verb: code runtime preflight failed; refusing before $boundary\n");
            CommandOutput::renderTransportDetail($preflight);
            return $preflight['exit'] !== 0 ? $preflight['exit'] : 1;
        }
        if ($preflight['summary'] === null) {
            $boundary = $verb === 'promote' ? 'promotion-begin/checkpoint' : 'promotion-begin';
            fwrite(STDERR, "duo: $verb: code runtime preflight returned no valid target evidence; refusing before $boundary\n");
            CommandOutput::renderTransportDetail($preflight);
            return 1;
        }
        return null;
    }

    private static function isOrchestratorInternalFlag(string $arg): bool {
        foreach (['--repo', '--compiled', '--artifact-hash', '--promotion-owner', '--promotion-hold', '--materializing-code', '--state-handoff', '--lifecycle-phase'] as $flag) {
            if ($arg === $flag || str_starts_with($arg, $flag . '=')) return true;
        }
        return false;
    }
}
