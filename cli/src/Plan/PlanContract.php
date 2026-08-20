<?php
declare(strict_types=1);

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
 * REQUIRED_BUCKETS is the exact detailed bucket set agent/src/Apply/Apply.php emits:
 * build_plan()'s own initializer plus the buckets it assigns later
 * (regen_pending, regen_context, env_missing), plus `warnings` and
 * `provider_problems`, which only the plan() entry point attaches — so a document missing it did not come from `wp duo
 * plan` at all. Cli.php::plan() json_encode()s that array verbatim, so the
 * detailed wire envelope and the emitter's array are the same thing. The
 * additive `category_summary` and explicitly requested `plan_view` are
 * optional for backwards compatibility. Their strict display validators are
 * intentionally separate from `violations()`: malformed optional display
 * data must never alter promotion/convergence readiness, and no-filter
 * renderers simply omit it. A scoped plan has a separate, closed set of
 * top-level projections; those
 * names are exposed through scopedProjections() for emitter-drift checks but
 * are deliberately not accepted as optional full-plan projections by this
 * trust boundary. The derivation
 * is machine-checked against Apply.php by
 * sandbox/tests/offline/cli/regress_plan_contract_trust.php: an emitter that grows a
 * bucket without teaching this list about it fails that suite loudly rather
 * than silently widening what a truth-critical caller will trust.
 *
 * Row FIELD shapes stay out of scope on purpose: buckets carry deliberately
 * different row shapes (see PlanSummary's own docblock), and `ok` is computed
 * from counts, so no field-level schema is validated here — "the bucket exists
 * and is a list" is what makes a count trustworthy. There is one cheap floor
 * BENEATH the field shapes, though (DUO-3388): every required bucket except
 * `warnings` is a list of row OBJECTS, and PlanSummary::label() and its
 * render() peers dereference those rows as arrays, so a non-array row such as
 * `"conflict": [true]` would raise an uncaught TypeError the instant a trust
 * boundary rendered the envelope. requireComplete() therefore also asserts
 * is_array() per row — not a schema, only "each row is an object" — and turns a
 * malformed row into the same house-style refusal a missing or non-list bucket
 * produces, naming the bucket and the offending index. `warnings` is the sole
 * exemption: it is a `list<string>` (agent/src/Apply/Apply.php's plan() entry point),
 * so its rows are legitimately not objects.
 */
final class PlanContract {
    private const CATEGORY_SUMMARY_FORMAT = 'duo-plan-category-summary/v1';

    /** @var list<string> */
    private const CATEGORY_SUMMARY_IDS = [
        'code', 'lifecycle', 'authored_state', 'generated_effects', 'media',
        'secrets', 'environment_state', 'capabilities', 'deletions',
    ];

    /** @var array<string,list<string>> */
    private const CATEGORY_SUMMARY_METRICS = [
        'code' => [
            'count', 'compatibility_mismatch', 'lifecycle_mismatch', 'revision_stale',
            'drift', 'other_mismatch', 'unsupported_code',
        ],
        'lifecycle' => ['count', 'code_lifecycle_mismatch', 'incomplete_lifecycle'],
        'authored_state' => ['count'],
        'generated_effects' => [
            'count', 'declared_effects', 'declared_lifecycle_effects',
            'declared_rebuild_effects', 'declared_regenerator_effects',
            'selected_native_actions', 'selected_provider_actions',
            'regen_pending', 'incomplete_apply',
        ],
        'media' => ['count', 'attachment_entities', 'upload_inventory_entries'],
        'secrets' => [],
        'environment_state' => [
            'count', 'state_drift', 'code_drift', 'required_env_missing',
            'optional_env_missing', 'missing_user', 'skipped_user_meta',
            'incomplete_lifecycle', 'incomplete_apply', 'regen_pending',
        ],
        'capabilities' => [
            'count', 'certification_source_blockers', 'selected_provider_blockers',
            'declared_unselected_provider_problems',
        ],
        'deletions' => [
            'count', 'blocked', 'nested_menu_item_delete_candidates',
            'nested_widget_delete_candidates', 'nested_option_delete_candidates',
        ],
    ];

    /** @var array<string,list<string>> */
    private const CATEGORY_SUMMARY_ACTIONS = [
        'code' => [],
        'lifecycle' => [],
        'authored_state' => [
            'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict', 'collision',
        ],
        'generated_effects' => [],
        'media' => [
            'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict', 'collision',
            'delete', 'delete_conflict', 'deleted',
        ],
        'secrets' => [],
        'environment_state' => [],
        'capabilities' => [],
        'deletions' => ['delete', 'delete_conflict', 'deleted'],
    ];

    /** @var array<string,list<string>> */
    private const CATEGORY_SUMMARY_ENTITIES = [
        'code' => ['plugin', 'theme', 'other'],
        'lifecycle' => [],
        'authored_state' => [
            'post', 'attachment', 'term', 'menu', 'sidebar', 'options',
            'user_meta', 'typed_table',
        ],
        'generated_effects' => [],
        'media' => ['attachment'],
        'secrets' => [],
        'environment_state' => [],
        'capabilities' => [],
        'deletions' => [
            'post', 'attachment', 'term', 'menu', 'sidebar', 'options',
            'user_meta', 'typed_table',
        ],
    ];

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
        'provider_problems',
        'regen_context',
        'regen_pending',
        'skipped_user_meta',
        'unchanged',
        'update',
        'uploads_inventory',
        'warnings',
    ];

    /**
     * Required buckets whose rows are legitimately NOT JSON objects, so the
     * per-row object floor in violations() skips them. `warnings` is the only
     * one: agent/src/Apply/Apply.php's plan() entry point attaches it as a plain
     * `list<string>`. Flooring it would refuse a well-formed plan that merely
     * carries a warning line; every OTHER required bucket is a list of row
     * objects, where a non-array row is always malformed.
     *
     * @var list<string>
     */
    private const NON_OBJECT_ROW_BUCKETS = ['warnings'];

    /** @return list<string> */
    public static function requiredBuckets(): array {
        return self::REQUIRED_BUCKETS;
    }

    /** @return list<string> */
    public static function optionalProjections(): array {
        return ['category_summary', 'plan_view'];
    }

    /**
     * Closed decorations on `duo-scoped-plan/v1`, never full-plan options.
     *
     * @return list<string>
     */
    public static function scopedProjections(): array {
        return [
            'artifact_hash',
            'format',
            'resolved_adapters',
            'scope',
            'scoped_recovery',
            'selected_actions',
            'selected_surfaces',
            'target',
        ];
    }

    public static function validCategorySummary(mixed $summary): bool {
        return self::categorySummaryViolations($summary) === [];
    }

    /**
     * Render only a projection that passed this host's closed validator.
     *
     * The host CLI and WordPress agent are separately deployable, so this is
     * intentionally a tiny host-side renderer rather than a cross-tree class
     * include. The regression compares its exact output with the agent twin.
     *
     * @return list<string>
     */
    public static function categorySummaryHumanLines(mixed $summary, array $selected = []): array {
        if (!self::validCategorySummary($summary)) {
            return [];
        }
        /** @var array<string,mixed> $summary */
        $labels = [
            'code' => 'code',
            'lifecycle' => 'lifecycle',
            'authored_state' => 'authored state',
            'generated_effects' => 'generated effects',
            'media' => 'media',
            'secrets' => 'secrets',
            'environment_state' => 'environment state',
            'capabilities' => 'capabilities',
            'deletions' => 'deletions',
        ];
        $lines = ['SUMMARY [' . self::CATEGORY_SUMMARY_FORMAT . ']'];
        $requested = $selected === [] ? self::CATEGORY_SUMMARY_IDS : $selected;
        foreach ($requested as $id) {
            $index = array_search($id, self::CATEGORY_SUMMARY_IDS, true);
            if (!is_int($index)) {
                return [];
            }
            if ($id === 'secrets') {
                $lines[] = '  secrets: redacted; secret values omitted; secret-state refusals use duo-command-refusal/v1';
                continue;
            }
            /** @var array<string,mixed> $category */
            $category = $summary['categories'][$index];
            $parts = [];
            foreach ($category['metrics'] as $metric => $count) {
                $parts[] = $metric . '=' . $count;
            }
            foreach (['entity_actions' => 'actions', 'contained_entities' => 'entities'] as $facet => $facetLabel) {
                $counts = [];
                foreach ($category[$facet] as $key => $count) {
                    $counts[] = $key . '=' . $count;
                }
                if ($counts !== []) {
                    $parts[] = $facetLabel . '[' . implode(',', $counts) . ']';
                }
            }
            $lines[] = '  ' . $labels[$id] . ': ' . implode(', ', $parts);
        }
        if (in_array('generated_effects', $requested, true)) {
            $lines[] = '  vocabulary: generated effects use shipped derived classification; values omitted';
        }
        return $lines;
    }

    /**
     * Name every way this document falls short of the complete envelope.
     *
     * @return list<string> empty means complete; otherwise a deterministic,
     *   bounded list naming exactly which buckets are absent, non-list, or
     *   (except warnings) carry a non-object row, with that row's index.
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
                continue;
            }
            // DUO-3388 row-shape FLOOR. Every required bucket except the
            // non-object-row ones (warnings) is a list of row OBJECTS;
            // PlanSummary::label()/render() dereference those rows as arrays, so
            // a non-array row would TypeError the instant a trust boundary
            // rendered the envelope. Naming the first offending index keeps this
            // list bounded and deterministic like the checks above; it is a
            // floor, not a schema — only "each row is an object" is asserted.
            if (in_array($bucket, self::NON_OBJECT_ROW_BUCKETS, true)) {
                continue;
            }
            foreach ($plan[$bucket] as $index => $row) {
                if (!is_array($row)) {
                    $violations[] = "$bucket row $index is not a JSON object";
                    break;
                }
            }
        }
        return $violations;
    }

    /** @return list<string> */
    public static function categorySummaryViolations(mixed $summary): array {
        if (!is_array($summary) || array_is_list($summary)) {
            return ['is not an object'];
        }
        $violations = [];
        $expected = ['format', 'redaction', 'facets', 'vocabulary', 'categories'];
        $actual = array_keys($summary);
        if ($actual !== $expected) {
            $violations[] = 'has unexpected or out-of-order top-level keys';
        }
        if (($summary['format'] ?? null) !== self::CATEGORY_SUMMARY_FORMAT) {
            $violations[] = 'format is not duo-plan-category-summary/v1';
        }
        if (($summary['redaction'] ?? null) !== 'values_omitted') {
            $violations[] = 'redaction is not values_omitted';
        }
        if (($summary['facets'] ?? null) !== 'overlapping') {
            $violations[] = 'facets is not overlapping';
        }
        $vocabulary = $summary['vocabulary'] ?? null;
        if (!is_array($vocabulary)
            || array_keys($vocabulary) !== ['generated_effects']
            || !is_array($vocabulary['generated_effects'] ?? null)
            || array_keys($vocabulary['generated_effects']) !== ['public_label', 'wire_class']
            || ($vocabulary['generated_effects']['public_label'] ?? null) !== 'generated'
            || ($vocabulary['generated_effects']['wire_class'] ?? null) !== 'derived'
        ) {
            $violations[] = 'vocabulary is malformed';
        }
        $categories = $summary['categories'] ?? null;
        if (!is_array($categories) || !array_is_list($categories)) {
            $violations[] = 'categories is not an ordered list';
            return $violations;
        }
        if (count($categories) !== count(self::CATEGORY_SUMMARY_IDS)) {
            $violations[] = 'categories has the wrong length';
        }
        $byId = [];
        foreach (self::CATEGORY_SUMMARY_IDS as $index => $id) {
            $category = $categories[$index] ?? null;
            if (!is_array($category) || ($category['id'] ?? null) !== $id) {
                $violations[] = "category $id is missing or out of order";
                continue;
            }
            $categoryKeys = ['id', 'metrics', 'entity_actions', 'contained_entities'];
            if ($id === 'secrets') {
                $categoryKeys[] = 'visibility';
            }
            if (array_keys($category) !== $categoryKeys) {
                $violations[] = "category $id has unexpected or out-of-order keys";
                continue;
            }
            foreach ([
                'metrics' => self::CATEGORY_SUMMARY_METRICS[$id],
                'entity_actions' => self::CATEGORY_SUMMARY_ACTIONS[$id],
                'contained_entities' => self::CATEGORY_SUMMARY_ENTITIES[$id],
            ] as $facet => $keys) {
                $violations = array_merge(
                    $violations,
                    self::countMapViolations($category[$facet] ?? null, $keys, "category $id.$facet")
                );
            }
            if ($id === 'secrets' && ($category['visibility'] ?? null) !== 'redacted') {
                $violations[] = 'category secrets visibility is not redacted';
            }
            $byId[$id] = $category;
        }
        if ($violations === []) {
            $code = $byId['code']['metrics'];
            if ($code['count'] !== $code['compatibility_mismatch'] + $code['lifecycle_mismatch']
                + $code['revision_stale'] + $code['drift'] + $code['other_mismatch']
                || $code['unsupported_code'] !== $code['compatibility_mismatch']
                || array_sum($byId['code']['contained_entities']) !== $code['count']) {
                $violations[] = 'category code arithmetic is inconsistent';
            }
            $lifecycle = $byId['lifecycle']['metrics'];
            if ($lifecycle['count'] !== $lifecycle['code_lifecycle_mismatch'] + $lifecycle['incomplete_lifecycle']
                || $lifecycle['code_lifecycle_mismatch'] !== $code['lifecycle_mismatch']) {
                $violations[] = 'category lifecycle arithmetic is inconsistent';
            }
            $authored = $byId['authored_state'];
            if ($authored['metrics']['count'] !== array_sum($authored['entity_actions'])
                || $authored['metrics']['count'] !== array_sum($authored['contained_entities'])) {
                $violations[] = 'category authored_state arithmetic is inconsistent';
            }
            $generated = $byId['generated_effects']['metrics'];
            if ($generated['count'] !== $generated['declared_effects']
                    + $generated['selected_native_actions'] + $generated['selected_provider_actions']
                    + $generated['regen_pending'] + $generated['incomplete_apply']
                || $generated['declared_lifecycle_effects'] + $generated['declared_rebuild_effects']
                    + $generated['declared_regenerator_effects'] > $generated['declared_effects']) {
                $violations[] = 'category generated_effects arithmetic is inconsistent';
            }
            $media = $byId['media'];
            if ($media['metrics']['attachment_entities'] !== array_sum($media['entity_actions'])
                || $media['metrics']['attachment_entities'] !== $media['contained_entities']['attachment']
                || $media['metrics']['count'] !== $media['metrics']['attachment_entities']
                    + $media['metrics']['upload_inventory_entries']) {
                $violations[] = 'category media arithmetic is inconsistent';
            }
            $environment = $byId['environment_state']['metrics'];
            if ($environment['count'] !== array_sum($environment) - $environment['count']) {
                $violations[] = 'category environment_state arithmetic is inconsistent';
            }
            $capabilities = $byId['capabilities']['metrics'];
            if ($capabilities['count'] !== $capabilities['certification_source_blockers']
                + $capabilities['selected_provider_blockers']
                + $capabilities['declared_unselected_provider_problems']) {
                $violations[] = 'category capabilities arithmetic is inconsistent';
            }
            $deletions = $byId['deletions'];
            if ($deletions['metrics']['count'] !== array_sum($deletions['entity_actions'])
                || $deletions['metrics']['count'] !== array_sum($deletions['contained_entities'])
                || $deletions['metrics']['blocked'] > $deletions['entity_actions']['delete']
                    + $deletions['entity_actions']['delete_conflict']) {
                $violations[] = 'category deletions arithmetic is inconsistent';
            }
        }
        return $violations;
    }

    /** @param list<string> $keys @return list<string> */
    private static function countMapViolations(mixed $value, array $keys, string $where): array {
        // Internally generated empty maps are stdClass so json_encode emits
        // `{}`. The host decodes JSON associatively, where both `{}` and `[]`
        // become PHP's empty array, so accept that one unavoidable decoded
        // representation too. Non-empty maps retain exact ordered keys.
        if ($keys === [] && $value instanceof \stdClass && get_object_vars($value) === []) {
            return [];
        }
        if (!is_array($value) || array_keys($value) !== $keys) {
            return ["$where is not the closed ordered count map"];
        }
        foreach ($keys as $key) {
            if (!is_int($value[$key]) || $value[$key] < 0) {
                return ["$where.$key is not a nonnegative integer"];
            }
        }
        return [];
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
