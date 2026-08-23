<?php
namespace Duo;

require_once __DIR__ . '/CompiledArtifact.php';
require_once __DIR__ . '/../Policy/ArtifactPolicyIdentity.php';
// Keep these explicit for the direct source-require contract. They are no-ops
// after ArtifactPolicyIdentity's normal load, but must remain *after* it so
// its fake-Canon refusal boundary can distinguish a complete normal Policy
// stack from a caller-provided stub.
if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
// The typed refusal artifact_exception() now mints. Unguarded and direct: the
// direct-require contract (sandbox/tests/offline/guards/regress_agent_src_requires.php)
// is that a file loads every engine class it names, and this one has no
// ordering relationship with the Canon/Policy stack above.
require_once __DIR__ . '/../Kernel/CommandRefusal.php';

/**
 * Validates and loads an immutable persisted compiled-repository artifact.
 *
 * This boundary has no compiler-builder state. It returns the artifact only
 * after its active Policy identity, effect contract, and optional code/state
 * bridge agree. CodeStateContract intentionally remains an optional runtime
 * dependency: narrow/refusal-safe callers must receive its stable diagnostic
 * rather than a bootstrap failure when that bridge is unavailable.
 */
final class CompiledArtifactReader {
    public static function read_artifact(string $path, Policy $policy): CompiledRepository {
        try {
            $artifact = CompiledRepository::from_array(Canon::decode(Canon::read_file($path)));
        } catch (\Throwable $t) {
            throw self::artifact_exception('compiled_artifact_invalid', $path, $t->getMessage());
        }
        if (!hash_equals(ArtifactPolicyIdentity::site_hash($policy), $artifact->site_hash())) {
            throw self::artifact_exception(
                'compiled_artifact_policy_mismatch', $path,
                'compiled site policy does not match the active site.duo.json'
            );
        }
        if (!hash_equals(ArtifactPolicyIdentity::manifest_hash($policy), $artifact->manifest_hash())) {
            throw self::artifact_exception(
                'compiled_artifact_manifest_mismatch', $path,
                'compiled manifest/interpreter set does not match active pins'
            );
        }
        if (!hash_equals(
            Canon::encode($artifact->effects_inventory()),
            Canon::encode($policy->effects_inventory())
        )) {
            throw self::artifact_exception(
                'compiled_artifact_invalid', $path,
                'compiled effect inventory does not match the active manifest contracts'
            );
        }
        $policyHasCode = $policy->code_config() !== null;
        $artifactHasCode = $artifact->code_descriptor() !== null;
        if ($policyHasCode !== $artifactHasCode) {
            throw self::artifact_exception(
                'compiled_artifact_code_mismatch', $path,
                $policyHasCode
                    ? 'active site policy enables code materialization but this artifact has no code descriptor'
                    : 'active site policy is legacy/state-only but this artifact unexpectedly contains a code descriptor'
            );
        }
        if ($artifactHasCode) {
            if (!class_exists(CodeStateContract::class)) {
                throw self::artifact_exception(
                    'compiled_artifact_code_state_contract_unavailable', $path,
                    'code/state bridge support is not loaded'
                );
            }
            try {
                CodeStateContract::validate($artifact, (array) $artifact->code_descriptor());
            } catch (\Throwable $t) {
                throw self::artifact_exception(
                    'compiled_artifact_code_state_mismatch', $path, $t->getMessage()
                );
            }
        }
        // Artifact consumers need the identical repository-derived schema
        // facts compilation used; never let a loaded artifact make ACF (or
        // a future interpreter) fall back to target-only rows during apply.
        $policy->prime_interpreters_from_repository($artifact->tree());
        return $artifact;
    }

    /**
     * The reviewed public half of every artifact-identity refusal.
     *
     * Keyed by the same code the diagnostic carries, so a new reader gate
     * cannot ship without a reviewed operator answer: artifact_exception()
     * below reads this map by key and PHP's match() throws
     * \UnhandledMatchError on a code that is not here.
     *
     * Constant and VALUE-FREE by construction — no artifact path, no policy
     * hash, no nested exception text. Those live in the operator sentence and
     * the previous chain, because `Cli::halt_json_failure()` publishes this
     * half verbatim (agent/src/Command/Cli.php:60-64) and an absolute artifact
     * path under /Users or /home would trip
     * `CommandRefusalException::containsSensitivePublicDetail()` (:199) and
     * redact the entire payload.
     *
     * @return array{0:string,1:string}
     */
    private static function artifact_guidance(string $code): array {
        return match ($code) {
            'compiled_artifact_invalid' => [
                'the compiled artifact is unreadable or disagrees with its own recorded content',
                'recompile the repository and rerun this command against the newly compiled artifact',
            ],
            'compiled_artifact_policy_mismatch' => [
                'the compiled artifact was built from a different site policy than the one active on this repository',
                'recompile the repository against its current site.duo.json, then rerun this command with that artifact',
            ],
            'compiled_artifact_manifest_mismatch' => [
                'the compiled manifest and interpreter set does not match this repository\'s active manifest pins',
                'recompile the repository and re-pin it with the object wp duo manifest-pin emits, then rerun this command with the new artifact',
            ],
            'compiled_artifact_code_mismatch' => [
                'the compiled artifact and the active site policy disagree about whether this repository materializes code',
                'recompile the repository under its current site.duo.json so artifact and policy agree about code, then rerun this command',
            ],
            'compiled_artifact_code_state_contract_unavailable' => [
                'this agent build cannot validate the code/state bridge the compiled artifact declares',
                'run this command against an agent build that ships the code/state contract, or against a state-only artifact',
            ],
            'compiled_artifact_code_state_mismatch' => [
                'the compiled artifact\'s code descriptor does not satisfy the code/state contract',
                'recompile the repository so its code descriptor and captured state agree, then rerun this command with that artifact',
            ],
        };
    }

    /**
     * Artifact identity refusals are TYPED, not compiler batches.
     *
     * Every one of these gates fires on a DEPLOYED site whose pins moved, not
     * on a repository that failed to compile — `compiled_artifact_manifest_mismatch`
     * is exactly what a one-byte edit under `manifests/` produces fleet-wide.
     * They arrived as `RepositoryCompilationException`, so `Cli` published
     * top-level `error: repository_compilation_failed`
     * (agent/src/Command/Cli.php:38-46) with the real code buried in
     * `diagnostics[0].code` AND the absolute artifact `path` copied into public
     * JSON. An orchestrator therefore could not branch on "recompile and re-pin"
     * without walking diagnostics, and the path leaked.
     *
     * `$operatorMessage` is harvested from a RepositoryCompilationException
     * built from this same diagnostic row, so `getMessage()` — including the
     * "duo: repository compilation failed (1 blocking diagnostic(s)); no target
     * contact or mutation attempted:" framing and the `[code] path — message`
     * line (agent/src/Repository/CompiledArtifact.php:227-236) — is provably
     * byte-identical to what `WP_CLI::error()` printed before. That object is
     * also passed as `$previous` so the full operator evidence stays reachable
     * in the chain.
     *
     * The published diagnostic keeps `code` (several suites read it with
     * array_column) and a reviewed constant message, and drops `path` and the
     * nested `$message`: the latter is a caught `\Throwable`'s text on the
     * invalid/code-state gates and can itself contain the artifact path.
     */
    private static function artifact_exception(string $code, string $path, string $message): CommandRefusalException {
        [$publicMessage, $remediation] = self::artifact_guidance($code);
        $operator = new RepositoryCompilationException([[
            'severity' => 'blocking', 'code' => $code, 'path' => $path,
            'locator' => '', 'message' => $message,
        ]]);
        return new CommandRefusalException(
            $code,
            $publicMessage,
            $remediation,
            [['code' => $code, 'message' => $publicMessage, 'remediation' => $remediation]],
            $operator->getMessage(),
            $operator
        );
    }
}
