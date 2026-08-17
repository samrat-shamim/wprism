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

    private static function artifact_exception(string $code, string $path, string $message): RepositoryCompilationException {
        return new RepositoryCompilationException([[
            'severity' => 'blocking', 'code' => $code, 'path' => $path,
            'locator' => '', 'message' => $message,
        ]]);
    }
}
