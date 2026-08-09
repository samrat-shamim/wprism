<?php
namespace Duo\Orchestrator;

/**
 * The complete `wp duo plan --format=json` envelope.
 *
 * PlanSummary::render() is deliberately fixture-tolerant: every bucket it
 * reads is defaulted (`$plan['conflict'] ?? []`) so unit fixtures may render
 * one bucket in isolation. That tolerance means a valid JSON object such as
 * `{}` renders as a CLEAN plan — harmless for a fixture, fatal for a caller
 * that turns `render(...)['ok']` into a promotion receipt or a converged
 * branch environment. Validation therefore belongs here, at the trust-
 * critical callers, never inside the renderer.
 *
 * REQUIRED_BUCKETS is the exact key set agent/src/Apply.php emits:
 * build_plan()'s own initializer plus the buckets it assigns later
 * (regen_pending, env_missing), plus `warnings`, which only the plan()
 * entry point attaches — so a document missing it did not come from `wp duo
 * plan` at all. Cli.php::plan() json_encode()s that array verbatim, so the
 * wire envelope and the emitter's array are the same thing. The derivation
 * is machine-checked against Apply.php by
 * sandbox/tests/regress_plan_contract_trust.php: an emitter that grows a
 * bucket without teaching this list about it fails that suite loudly rather
 * than silently widening what a truth-critical caller will trust.
 *
 * Row SHAPES stay out of scope on purpose. Buckets carry deliberately
 * different row shapes (see PlanSummary's own docblock), and `ok` is
 * computed from counts, so "the bucket exists and is a list" is exactly the
 * property that makes a count trustworthy.
 */
final class PlanContract {
    /** @var list<string> */
    private const REQUIRED_BUCKETS = [
        'adapter_dispositions',
        'adopt',
        'code_drift',
        'code_mismatch',
        'collision',
        'conflict',
        'create',
        'delete',
        'delete_conflict',
        'deleted',
        'drift',
        'effects_inventory',
        'env_missing',
        'incomplete_apply',
        'incomplete_lifecycle',
        'missing_user',
        'regen_pending',
        'skipped_user_meta',
        'unchanged',
        'update',
        'uploads_inventory',
        'warnings',
    ];

    /** @return list<string> */
    public static function requiredBuckets(): array {
        return self::REQUIRED_BUCKETS;
    }

    /**
     * Name every way this document falls short of the complete envelope.
     *
     * @return list<string> empty means complete; otherwise a deterministic,
     *   bounded list naming exactly which buckets are absent or non-list.
     */
    public static function violations(mixed $plan): array {
        // json_decode() renders both `{}` and `[]` as PHP's empty array, so
        // an empty document cannot be rejected as "not an object" here — it
        // falls through and reports every missing bucket by name instead.
        if (!is_array($plan) || ($plan !== [] && array_is_list($plan))) {
            return ['plan is not a JSON object'];
        }
        $violations = [];
        foreach (self::REQUIRED_BUCKETS as $bucket) {
            if (!array_key_exists($bucket, $plan)) {
                $violations[] = "missing $bucket";
                continue;
            }
            if (!is_array($plan[$bucket]) || !array_is_list($plan[$bucket])) {
                $violations[] = "$bucket is not a list";
            }
        }
        return $violations;
    }

    /**
     * Fail closed unless this is a complete agent plan envelope.
     *
     * @param string $surface the trust boundary refusing, named in the
     *   diagnostic its caller prints or journals.
     * @return array<string,mixed> the same plan, once it is trustworthy.
     */
    public static function requireComplete(mixed $plan, string $surface): array {
        $violations = self::violations($plan);
        if ($violations !== []) {
            throw new \RuntimeException(
                "$surface: incomplete agent plan envelope (" . implode(', ', $violations) . ')'
            );
        }
        /** @var array<string,mixed> $plan */
        return $plan;
    }
}
