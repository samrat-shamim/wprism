<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * Host-side half of code deployment.
 *
 * The agent owns all filesystem mutation and verification.  The host only
 * creates one immutable compiler artifact, decides whether that artifact
 * declares code materialization, and carries its expected outer hash, exact
 * artifact path, and one promotion owner through stage -> lifecycle deploy -> finalize. Keeping
 * this boundary here is intentional: a Docker/SSH/local target can decide
 * how to materialize its code without the orchestration CLI learning its
 * filesystem layout or reimplementing any agent validation.
 */
final class CodeDeploy {
    /**
     * Compile into a target-visible artifact and decode the agent's JSON
     * response.  `wp duo compile --format=json` is the sole source of truth
     * for whether code exists in this revision; do not inspect a mutable
     * checkout on the host after compilation.
     *
     * @return array{exit:int, stdout:string, stderr:string, summary:?array}
     */
    public static function compile(Transport $transport, string $repo, string $artifact): array {
        $result = $transport->captureWp([
            'duo', 'compile', '--repo=' . $repo, '--out=' . $artifact, '--format=json',
        ]);
        if ($result['exit'] !== 0) {
            return $result + ['summary' => null];
        }

        $summary = json_decode(trim($result['stdout']), true);
        if (!is_array($summary) || !self::validArtifactHash($summary)) {
            return $result + ['summary' => null];
        }
        return $result + ['summary' => $summary];
    }

    /** The content-addressed artifact invariant the host can validate itself. */
    public static function validArtifactHash(array $summary): bool {
        return is_string($summary['artifact_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/', (string) $summary['artifact_hash']) === 1;
    }

    /**
     * Whether this compiled revision declares a code half.
     *
     * The compiled repository's top-level `code` value is an opaque
     * descriptor which includes a separate code revision. The host
     * intentionally knows no descriptor internals: code-stage/finalize
     * receive the frozen artifact and let the agent validate paths, hashes,
     * ownership, and transport-local facts. An old artifact with no `code`
     * descriptor takes the pre-code lifecycle path exactly.
     */
    public static function enabled(array $summary): bool {
        return isset($summary['code']) && is_array($summary['code']) && $summary['code'] !== [];
    }

    /**
     * Acquire the target lease before promotion's DB checkpoint. The outer
     * artifact hash is deliberately the only code/state input: it binds both
     * halves without making the host inspect the opaque code descriptor.
     *
     * @return array<int,string>
     */
    public static function beginArgs(string $owner, string $artifactHash): array {
        return [
            'duo', 'promotion-begin', '--promotion-owner=' . $owner,
            '--artifact-hash=' . $artifactHash,
        ];
    }

    /**
     * Exact, idempotent cleanup. Hash rather than --compiled keeps recovery
     * available when a failed run's checkout/policy changes before cleanup.
     *
     * @return array<int,string>
     */
    public static function abortArgs(string $owner, string $artifactHash): array {
        return [
            'duo', 'promotion-abort', '--promotion-owner=' . $owner,
            '--artifact-hash=' . $artifactHash,
        ];
    }

    /** @return array<int,string> */
    public static function stageArgs(string $repo, string $artifact, string $owner, string $artifactHash): array {
        return [
            'duo', 'code-stage', '--repo=' . $repo, '--compiled=' . $artifact,
            '--promotion-owner=' . $owner, '--artifact-hash=' . $artifactHash,
        ];
    }

    /** @return array<int,string> */
    public static function finalizeArgs(
        string $repo,
        string $artifact,
        string $owner,
        string $artifactHash,
        bool $hold
    ): array {
        $args = [
            'duo', 'code-finalize', '--repo=' . $repo, '--compiled=' . $artifact,
            '--promotion-owner=' . $owner, '--artifact-hash=' . $artifactHash,
        ];
        if ($hold) {
            $args[] = '--promotion-hold';
        }
        return $args;
    }

    /** @return array<int,string> */
    public static function lifecycleArgs(
        string $repo,
        string $artifact,
        string $owner,
        string $artifactHash,
        bool $hold,
        bool $materializingCode,
        bool $stateHandoff,
        array $extra = []
    ): array {
        $args = [
            'duo', 'deploy', '--repo=' . $repo, '--compiled=' . $artifact,
            '--promotion-owner=' . $owner, '--artifact-hash=' . $artifactHash,
        ];
        if ($hold) {
            $args[] = '--promotion-hold';
        }
        if ($materializingCode) {
            $args[] = '--materializing-code';
        }
        if ($stateHandoff) {
            $args[] = '--state-handoff';
        }
        return array_merge($args, $extra);
    }
}
