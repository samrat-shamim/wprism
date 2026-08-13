<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/EnvironmentDriver.php';
require_once __DIR__ . '/CommandOutput.php';
require_once __DIR__ . '/CodeDeploy.php';

/**
 * Host command boundary for standalone immutable-artifact deployment.
 *
 * The larger promotion family still owns the shared scope, rollback-fence,
 * owner, and exact-cleanup primitives. They are explicit collaborators here
 * so deploy becomes independently callable without duplicating recovery law.
 */
final class DeployCommand {
    /**
     * @param list<string> $extra
     * @param callable(list<string>):?int $scopeRefusal
     * @param callable(EnvironmentDriver):bool $rollbackFence
     * @param callable():string $runIdFactory
     * @param callable(EnvironmentDriver,array<string,mixed>,string,string):void $compensateUncertainBegin
     * @param callable(EnvironmentDriver,string,string):bool $abort
     */
    public static function run(
        EnvironmentDriver $transport,
        array $extra,
        callable $scopeRefusal,
        callable $rollbackFence,
        callable $runIdFactory,
        callable $compensateUncertainBegin,
        callable $abort
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
        $mkdir = $transport->captureRaw('mkdir -p ' . escapeshellarg(dirname($artifact)));
        if ($mkdir['exit'] !== 0) {
            fwrite(STDERR, "duo: deploy: could not create target artifact directory\n");
            CommandOutput::renderTransportDetail($mkdir);
            return $mkdir['exit'] !== 0 ? $mkdir['exit'] : 1;
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

        if ($codeEnabled) {
            echo "deploy phase: code-stage\n";
            $stage = $transport->streamWp(CodeDeploy::stageArgs($repo, $artifact, $runId, $artifactHash));
            if ($stage !== 0) {
                fwrite(STDERR, "duo: deploy: code-stage failed (exit $stage); lifecycle phases and code-finalize were not run\n");
                $abort($transport, $runId, $artifactHash);
                return $stage;
            }
        }

        echo "deploy phase: lifecycle-retire\n";
        $retire = $transport->streamWp(CodeDeploy::lifecycleArgs(
            $repo, $artifact, $runId, $artifactHash, true, $codeEnabled, false, 'retire', $deployExtra
        ));
        if ($retire !== 0) {
            fwrite(STDERR, "duo: deploy: lifecycle retirement failed (exit $retire); later phases were not run\n");
            $abort($transport, $runId, $artifactHash);
            return $retire;
        }

        echo "deploy phase: lifecycle-activate\n";
        $activate = $transport->streamWp(CodeDeploy::lifecycleArgs(
            $repo, $artifact, $runId, $artifactHash, $codeEnabled, $codeEnabled, false, 'activate', $deployExtra
        ));
        if ($activate !== 0) {
            fwrite(STDERR, "duo: deploy: lifecycle activation failed (exit $activate); later phases were not run\n");
            $abort($transport, $runId, $artifactHash);
            return $activate;
        }

        if ($codeEnabled) {
            echo "deploy phase: code-finalize\n";
            $finalize = $transport->streamWp(CodeDeploy::finalizeArgs($repo, $artifact, $runId, $artifactHash, false));
            if ($finalize !== 0) {
                fwrite(STDERR, "duo: deploy: code-finalize failed (exit $finalize); later phases were not run\n");
                $abort($transport, $runId, $artifactHash);
                return $finalize;
            }
            echo "deploy complete: code-stage -> lifecycle-retire -> lifecycle-activate -> code-finalize\n";
            return 0;
        }

        echo "deploy complete: lifecycle-retire -> lifecycle-activate (no code descriptor)\n";
        return 0;
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
            throw new \RuntimeException(
                "duo $verb: unsupported deploy flag '$arg' (only --force-code-mismatch and --force-code-drift are accepted)"
            );
        }
        return $allowed;
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
