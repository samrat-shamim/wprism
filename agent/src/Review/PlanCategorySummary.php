<?php
namespace WPrism;

/**
 * Deterministic, value-free category projection for the public plan envelope.
 *
 * Detailed plan buckets remain the source of truth. This class projects only
 * closed identifiers and non-negative counts; it never copies paths, UUIDs,
 * names, messages, values, provider ids, or effect ids. Facets deliberately
 * overlap (an attachment deletion is media and deletion evidence), so
 * consumers must not sum category totals.
 *
 * `generated` is the product-facing doctrine term. `derived` remains the
 * shipped manifest/wire classifier; this projection does not add an alias.
 */
final class PlanCategorySummary {
    public const FORMAT = 'wprism-plan-category-summary/v1';

    /** @var list<string> */
    private const CATEGORY_IDS = [
        'code', 'lifecycle', 'authored_state', 'generated_effects', 'media',
        'secrets', 'environment_state', 'capabilities', 'deletions',
    ];

    /** @var list<string> */
    private const AUTHORED_ACTIONS = [
        'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict', 'collision',
    ];

    /** @var list<string> */
    private const DELETE_ACTIONS = ['delete', 'delete_conflict', 'deleted'];

    /** @var list<string> */
    private const MEDIA_ACTIONS = [
        'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict', 'collision',
        'delete', 'delete_conflict', 'deleted',
    ];

    /** @var list<string> */
    private const ENTITY_KINDS = [
        'post', 'attachment', 'term', 'menu', 'sidebar', 'options',
        'user_meta', 'typed_table',
    ];

    /**
     * The public display vocabulary is deliberately exposed through methods,
     * not duplicated by newer projections.  A plan view must use the same
     * compiled-tree/tombstone classification as the category summary; a path
     * or row-type guess would turn an attachment into an ordinary post (and
     * eventually grow plugin-specific branches in a renderer).
     *
     * @return list<string>
     */
    public static function categoryIds(): array {
        return self::CATEGORY_IDS;
    }

    /** @return list<string> */
    public static function entityKinds(): array {
        return self::ENTITY_KINDS;
    }

    /** @return list<string> */
    public static function actionBuckets(): array {
        return array_merge(self::AUTHORED_ACTIONS, self::DELETE_ACTIONS);
    }

    /**
     * Classify one detailed entity-action row with the exact compiled context
     * that produced the plan.  This is intentionally unavailable for global
     * diagnostic rows: they have no entity identity and belong in count-only
     * safety evidence, never a fabricated entity filter result.
     *
     * @param array<string,mixed> $row
     * @param array<string,array<string,mixed>> $tree
     * @param array<string,array<string,mixed>> $deletions
     */
    public static function entityKindForPlanRow(
        array $row,
        array $tree,
        array $deletions,
        string $bucket
    ): ?string {
        if (in_array($bucket, self::AUTHORED_ACTIONS, true)) {
            return self::entityKind($row, $tree, false);
        }
        if (in_array($bucket, self::DELETE_ACTIONS, true)) {
            return self::entityKind($row, $deletions, true);
        }
        return null;
    }

    /**
     * Closed, overlapping category facets for an entity-action row.  These
     * are display predicates only: the detailed bucket remains the mutation
     * authority.  Keep this beside entityKindForPlanRow() so all projections
     * use one classification rather than independently inferring categories.
     *
     * @return list<string>|null
     */
    public static function categoriesForPlanRow(string $bucket, string $entityKind): ?array {
        if (!in_array($entityKind, self::ENTITY_KINDS, true)) {
            return null;
        }
        if (in_array($bucket, self::AUTHORED_ACTIONS, true)) {
            $categories = ['authored_state'];
            if ($entityKind === 'attachment') {
                $categories[] = 'media';
            }
            if ($bucket === 'drift') {
                $categories[] = 'environment_state';
            }
            return self::orderedCategories($categories);
        }
        if (in_array($bucket, self::DELETE_ACTIONS, true)) {
            $categories = ['deletions'];
            if ($entityKind === 'attachment') {
                $categories[] = 'media';
            }
            return self::orderedCategories($categories);
        }
        return null;
    }

    /** @var list<string> */
    private const CODE_KINDS = ['plugin', 'theme', 'other'];

    /**
     * WP-2.8's `version_range_graduated` is deliberately NOT in this list, and
     * the reason is in the category projection itself: `unsupported_code` is
     * this same counter under a second name (:358). A graduated verdict is the
     * precise opposite claim — the installed release has recorded per-release
     * probe evidence behind it — so counting it here would report evidenced
     * code as unsupported. It lands in `other_mismatch` instead, which is what
     * that bucket is for: a code_mismatch row that is none of the three named
     * kinds. Give it its own counter only alongside a decision to move the
     * summary's metric keys, which is a wire change (rule 8).
     *
     * @var list<string>
     */
    private const CODE_COMPATIBILITY_ISSUES = ['missing_in_code', 'outside_version_range'];

    /** @var list<string> */
    private const CODE_LIFECYCLE_ISSUES = [
        'inactive_in_environment', 'unexpected_active_plugin',
        'active_plugin_order_mismatch', 'template_mismatch',
    ];

    /** @var list<string> */
    private const CONTEXT_KEYS = [
        'selected_native_actions',
        'selected_provider_actions',
        'certification_source_blockers',
        'selected_provider_blockers',
        'nested_menu_item_delete_candidates',
        'nested_widget_delete_candidates',
        'nested_option_delete_candidates',
    ];

    /** @var list<string> */
    private const CONSUMED_ROW_KEYS = [
        'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict',
        'collision', 'delete', 'delete_conflict', 'deleted', 'code_mismatch',
        'code_drift', 'effects_inventory', 'regen_pending', 'incomplete_apply',
        'incomplete_lifecycle', 'uploads_inventory', 'env_missing',
        'missing_user', 'skipped_user_meta', 'provider_problems',
    ];

    /** @var array<string,list<string>> */
    private const METRIC_KEYS = [
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
    private const ACTION_KEYS = [
        'code' => [],
        'lifecycle' => [],
        'authored_state' => self::AUTHORED_ACTIONS,
        'generated_effects' => [],
        'media' => self::MEDIA_ACTIONS,
        'secrets' => [],
        'environment_state' => [],
        'capabilities' => [],
        'deletions' => self::DELETE_ACTIONS,
    ];

    /** @var array<string,list<string>> */
    private const ENTITY_KEYS = [
        'code' => self::CODE_KINDS,
        'lifecycle' => [],
        'authored_state' => self::ENTITY_KINDS,
        'generated_effects' => [],
        'media' => ['attachment'],
        'secrets' => [],
        'environment_state' => [],
        'capabilities' => [],
        'deletions' => self::ENTITY_KINDS,
    ];

    /**
     * Build the optional display projection from already-observed evidence.
     *
     * The caller supplies exact compiled identity context and seven counts
     * derived while the target snapshot is still coherent. Missing/ambiguous
     * context returns null: an absent optional projection is honest; a made-up
     * `unknown` bucket would not be.
     *
     * @param array<string,mixed> $plan
     * @param array<string,array<string,mixed>> $tree
     * @param array<string,array<string,mixed>> $deletions
     * @param array<string,int> $context
     * @return array<string,mixed>|null
     */
    public static function build(
        array $plan,
        array $tree,
        array $deletions,
        array $context
    ): ?array {
        if (!self::validContext($context)) {
            return null;
        }
        if (!self::validPlanRows($plan)) {
            return null;
        }

        $stateActions = self::zeroes(self::AUTHORED_ACTIONS);
        $stateKinds = self::zeroes(self::ENTITY_KINDS);
        $deleteActions = self::zeroes(self::DELETE_ACTIONS);
        $deleteKinds = self::zeroes(self::ENTITY_KINDS);
        $attachmentActions = self::zeroes(self::MEDIA_ACTIONS);
        $stateRows = 0;
        $deleteRows = 0;
        $attachmentRows = 0;

        foreach (self::AUTHORED_ACTIONS as $action) {
            foreach (self::rows($plan, $action) as $row) {
                $kind = self::entityKind($row, $tree, false);
                if ($kind === null) {
                    return null;
                }
                $stateRows++;
                $stateActions[$action]++;
                $stateKinds[$kind]++;
                if ($kind === 'attachment') {
                    $attachmentRows++;
                    $attachmentActions[$action]++;
                }
            }
        }
        foreach (self::DELETE_ACTIONS as $action) {
            foreach (self::rows($plan, $action) as $row) {
                $kind = self::entityKind($row, $deletions, true);
                if ($kind === null) {
                    return null;
                }
                $deleteRows++;
                $deleteActions[$action]++;
                $deleteKinds[$kind]++;
                if ($kind === 'attachment') {
                    $attachmentRows++;
                    $attachmentActions[$action]++;
                }
            }
        }

        $compatibilityMismatch = 0;
        $lifecycleMismatch = 0;
        $revisionStale = 0;
        $otherMismatch = 0;
        $codeMismatch = self::rows($plan, 'code_mismatch');
        $codeDrift = self::rows($plan, 'code_drift');
        foreach ($codeMismatch as $row) {
            $issue = is_string($row['issue'] ?? null) ? $row['issue'] : '';
            if (in_array($issue, self::CODE_COMPATIBILITY_ISSUES, true)) {
                $compatibilityMismatch++;
            } elseif (in_array($issue, self::CODE_LIFECYCLE_ISSUES, true)) {
                $lifecycleMismatch++;
            } elseif ($issue === 'code_revision_stale') {
                $revisionStale++;
            } else {
                $otherMismatch++;
            }
        }
        $codeCount = $compatibilityMismatch + $lifecycleMismatch + $revisionStale
            + count($codeDrift) + $otherMismatch;
        $codeKinds = self::zeroes(self::CODE_KINDS);
        foreach (array_merge($codeMismatch, $codeDrift) as $row) {
            $kind = is_string($row['kind'] ?? null) ? $row['kind'] : '';
            $kind = in_array($kind, ['plugin', 'theme'], true) ? $kind : 'other';
            $codeKinds[$kind]++;
        }

        $effects = self::rows($plan, 'effects_inventory');
        $effectPhases = ['lifecycle' => 0, 'rebuild' => 0, 'regenerator' => 0];
        foreach ($effects as $row) {
            $phase = is_string($row['phase'] ?? null) ? $row['phase'] : '';
            if (array_key_exists($phase, $effectPhases)) {
                $effectPhases[$phase]++;
            }
        }
        $regenPending = self::rows($plan, 'regen_pending');
        $incompleteApply = self::rows($plan, 'incomplete_apply');
        $incompleteLifecycle = self::rows($plan, 'incomplete_lifecycle');
        $generatedCount = count($effects)
            + $context['selected_native_actions']
            + $context['selected_provider_actions']
            + count($regenPending)
            + count($incompleteApply);

        $uploads = self::rows($plan, 'uploads_inventory');
        $envMissing = self::rows($plan, 'env_missing');
        $missingUser = self::rows($plan, 'missing_user');
        $skippedUserMeta = self::rows($plan, 'skipped_user_meta');
        $stateDrift = self::rows($plan, 'drift');
        $requiredEnv = self::requiredCount($envMissing);
        $environmentCount = count($stateDrift) + count($codeDrift) + count($envMissing)
            + count($missingUser) + count($skippedUserMeta)
            + count($incompleteLifecycle) + count($incompleteApply) + count($regenPending);
        $providerProblems = self::rows($plan, 'provider_problems');
        $capabilityCount = $context['certification_source_blockers']
            + $context['selected_provider_blockers'] + count($providerProblems);

        $categories = [
            self::category('code', [
                'count' => $codeCount,
                'compatibility_mismatch' => $compatibilityMismatch,
                'lifecycle_mismatch' => $lifecycleMismatch,
                'revision_stale' => $revisionStale,
                'drift' => count($codeDrift),
                'other_mismatch' => $otherMismatch,
                'unsupported_code' => $compatibilityMismatch,
            ], [], $codeKinds),
            self::category('lifecycle', [
                'count' => $lifecycleMismatch + count($incompleteLifecycle),
                'code_lifecycle_mismatch' => $lifecycleMismatch,
                'incomplete_lifecycle' => count($incompleteLifecycle),
            ]),
            self::category('authored_state', ['count' => $stateRows], $stateActions, $stateKinds),
            self::category('generated_effects', [
                'count' => $generatedCount,
                'declared_effects' => count($effects),
                'declared_lifecycle_effects' => $effectPhases['lifecycle'],
                'declared_rebuild_effects' => $effectPhases['rebuild'],
                'declared_regenerator_effects' => $effectPhases['regenerator'],
                'selected_native_actions' => $context['selected_native_actions'],
                'selected_provider_actions' => $context['selected_provider_actions'],
                'regen_pending' => count($regenPending),
                'incomplete_apply' => count($incompleteApply),
            ]),
            self::category('media', [
                'count' => $attachmentRows + count($uploads),
                'attachment_entities' => $attachmentRows,
                'upload_inventory_entries' => count($uploads),
            ], $attachmentActions, ['attachment' => $attachmentRows]),
            self::category('secrets', [], [], [], 'redacted'),
            self::category('environment_state', [
                'count' => $environmentCount,
                'state_drift' => count($stateDrift),
                'code_drift' => count($codeDrift),
                'required_env_missing' => $requiredEnv,
                'optional_env_missing' => count($envMissing) - $requiredEnv,
                'missing_user' => count($missingUser),
                'skipped_user_meta' => count($skippedUserMeta),
                'incomplete_lifecycle' => count($incompleteLifecycle),
                'incomplete_apply' => count($incompleteApply),
                'regen_pending' => count($regenPending),
            ]),
            self::category('capabilities', [
                'count' => $capabilityCount,
                'certification_source_blockers' => $context['certification_source_blockers'],
                'selected_provider_blockers' => $context['selected_provider_blockers'],
                'declared_unselected_provider_problems' => count($providerProblems),
            ]),
            self::category('deletions', [
                'count' => $deleteRows,
                'blocked' => self::blockedDeleteCount($plan),
                'nested_menu_item_delete_candidates' => $context['nested_menu_item_delete_candidates'],
                'nested_widget_delete_candidates' => $context['nested_widget_delete_candidates'],
                'nested_option_delete_candidates' => $context['nested_option_delete_candidates'],
            ], $deleteActions, $deleteKinds),
        ];

        return [
            'format' => self::FORMAT,
            'redaction' => 'values_omitted',
            'facets' => 'overlapping',
            'vocabulary' => [
                'generated_effects' => [
                    'public_label' => 'generated',
                    'wire_class' => 'derived',
                ],
            ],
            'categories' => $categories,
        ];
    }

    /** @param array<string,mixed> $summary @return list<string> */
    public static function humanLines(array $summary): array {
        return self::humanLinesForCategories($summary, self::CATEGORY_IDS);
    }

    /**
     * Render a canonical subset for an explicit plan view.  Category counts
     * remain full-plan evidence; this only controls which fixed summary lines
     * are observed. An empty selection means all categories, matching the
     * plan-view filter grammar.
     *
     * @param array<string,mixed> $summary
     * @param list<string> $selected
     * @return list<string>
     */
    public static function humanLinesForCategories(array $summary, array $selected): array {
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
        $categories = is_array($summary['categories'] ?? null) ? $summary['categories'] : [];
        $lines = ['SUMMARY [' . self::FORMAT . ']'];
        $requested = $selected === [] ? self::CATEGORY_IDS : self::orderedCategories($selected);
        foreach ($requested as $id) {
            $label = $labels[$id] ?? $id;
            $category = self::categoryById($categories, $id);
            if ($id === 'secrets') {
                $lines[] = '  secrets: redacted; secret values omitted; secret-state refusals use wprism-command-refusal/v1';
                continue;
            }
            $parts = [];
            foreach ((array) ($category['metrics'] ?? []) as $metric => $count) {
                $parts[] = $metric . '=' . (int) $count;
            }
            foreach (['entity_actions' => 'actions', 'contained_entities' => 'entities'] as $facet => $facetLabel) {
                $counts = [];
                foreach ((array) ($category[$facet] ?? []) as $key => $count) {
                    $counts[] = $key . '=' . (int) $count;
                }
                if ($counts !== []) {
                    $parts[] = $facetLabel . '[' . implode(',', $counts) . ']';
                }
            }
            $lines[] = '  ' . $label . ': ' . implode(', ', $parts);
        }
        if (in_array('generated_effects', $requested, true)) {
            $lines[] = '  vocabulary: generated effects use shipped derived classification; values omitted';
        }
        return $lines;
    }

    /** @param array<string,mixed> $context */
    private static function validContext(array $context): bool {
        if (array_keys($context) !== self::CONTEXT_KEYS) {
            return false;
        }
        foreach (self::CONTEXT_KEYS as $key) {
            if (!is_int($context[$key]) || $context[$key] < 0) {
                return false;
            }
        }
        return true;
    }

    /** @param array<string,mixed> $plan */
    private static function validPlanRows(array $plan): bool {
        foreach (self::CONSUMED_ROW_KEYS as $key) {
            if (!array_key_exists($key, $plan)
                || !is_array($plan[$key])
                || !array_is_list($plan[$key])) {
                return false;
            }
            $rows = $plan[$key];
            foreach ($rows as $row) {
                if (!is_array($row)) {
                    return false;
                }
            }
        }
        return true;
    }

    /** @return list<array<string,mixed>> */
    private static function rows(array $plan, string $key): array {
        /** @var list<array<string,mixed>> $rows */
        $rows = $plan[$key];
        return $rows;
    }

    /** @param list<string> $keys @return array<string,int> */
    private static function zeroes(array $keys): array {
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = 0;
        }
        return $out;
    }

    /** @param list<string> $categories @return list<string> */
    private static function orderedCategories(array $categories): array {
        $selected = array_fill_keys($categories, true);
        $out = [];
        foreach (self::CATEGORY_IDS as $category) {
            if (isset($selected[$category])) {
                $out[] = $category;
            }
        }
        return $out;
    }

    /**
     * @param list<string> $keys
     * @param array<string,int> $counts
     * @return array<string,int>|\stdClass
     */
    private static function fixedCounts(array $keys, array $counts): array|\stdClass {
        // The wire contract calls every facet a map. PHP otherwise encodes
        // its one empty-array value as `[]`, so preserve the JSON object
        // shape explicitly for closed maps whose allowed key set is empty.
        if ($keys === []) {
            return new \stdClass();
        }
        $out = [];
        foreach ($keys as $key) {
            $out[$key] = (int) ($counts[$key] ?? 0);
        }
        return $out;
    }

    /** @param array<string,int> $metrics @param array<string,int> $actions @param array<string,int> $entities */
    private static function category(
        string $id,
        array $metrics,
        array $actions = [],
        array $entities = [],
        ?string $visibility = null
    ): array {
        $category = [
            'id' => $id,
            'metrics' => self::fixedCounts(self::METRIC_KEYS[$id], $metrics),
            'entity_actions' => self::fixedCounts(self::ACTION_KEYS[$id], $actions),
            'contained_entities' => self::fixedCounts(self::ENTITY_KEYS[$id], $entities),
        ];
        if ($visibility !== null) {
            $category['visibility'] = $visibility;
        }
        return $category;
    }

    /** @param list<array<string,mixed>> $categories */
    private static function categoryById(array $categories, string $id): array {
        foreach ($categories as $category) {
            if (is_array($category) && ($category['id'] ?? null) === $id) {
                return $category;
            }
        }
        return [];
    }

    /**
     * @param array<string,mixed> $row
     * @param array<string,array<string,mixed>> $context
     */
    private static function entityKind(array $row, array $context, bool $deletion): ?string {
        $uuid = $row['uuid'] ?? null;
        if (!is_string($uuid) || $uuid === ''
            || !isset($context[$uuid]) || !is_array($context[$uuid])) {
            return null;
        }
        $entry = $context[$uuid];
        if (!is_array($entry['data'] ?? null)) {
            return null;
        }
        $data = $entry['data'];
        if ($deletion) {
            $kind = is_string($data['kind'] ?? null) ? $data['kind'] : '';
            $type = is_string($data['type'] ?? null) ? $data['type'] : '';
            if ($kind === 'post') {
                return $type === 'attachment' ? 'attachment' : 'post';
            }
            if (in_array($kind, ['term', 'menu'], true)) {
                return $kind;
            }
            return $kind === 'table' ? 'typed_table' : null;
        }

        $type = is_string($entry['type'] ?? null) ? $entry['type'] : '';
        if ($type === 'post') {
            return ($data['type'] ?? null) === 'attachment' ? 'attachment' : 'post';
        }
        if ($type === 'user-meta') {
            return 'user_meta';
        }
        if (in_array($type, ['term', 'menu', 'sidebar', 'options'], true)) {
            return $type;
        }
        return $type !== '' ? 'typed_table' : null;
    }

    /** @param array<string,mixed> $plan */
    private static function blockedDeleteCount(array $plan): int {
        $count = 0;
        foreach (['delete', 'delete_conflict'] as $action) {
            foreach (self::rows($plan, $action) as $row) {
                if (array_key_exists('blocked', $row) || !empty($row['guard_refs'])) {
                    $count++;
                }
            }
        }
        return $count;
    }

    /** @param list<array<string,mixed>> $rows */
    private static function requiredCount(array $rows): int {
        return count(array_filter($rows, static fn(array $row): bool => !empty($row['required'])));
    }
}
