<?php
/**
 * Build the offline rehearsal fixture: one complete `wp duo plan
 * --format=json` envelope with a valid `duo-plan-category-summary/v1`
 * projection, and one set of `duo-assess-report/v1` surface rows.
 *
 * Written as a generator rather than two committed JSON blobs for one
 * reason: the surface rows carry `ProjectionVocabulary`'s annotation
 * constants, and a literal copy of those sentences in a fixture would be a
 * second vocabulary that drifts silently the day the real one is reworded.
 * The category summary is likewise built through the arithmetic
 * `PlanContract::categorySummaryViolations()` enforces, so a fixture that
 * stopped satisfying the agent's own invariants fails here rather than
 * producing a preview nobody can trust.
 *
 * usage: php make-fixture.php <out-dir>
 */
declare(strict_types=1);

require_once dirname(__DIR__, 4) . '/cli/src/Contract/ProjectionVocabulary.php';

use Duo\Orchestrator\ProjectionVocabulary;

$out = $argv[1] ?? '';
if ($out === '') {
    fwrite(STDERR, "usage: make-fixture.php <out-dir>\n");
    exit(2);
}
if (!is_dir($out) && !mkdir($out, 0700, true)) {
    fwrite(STDERR, "could not create '$out'\n");
    exit(1);
}

/**
 * One category row in the agent's exact key and metric order.
 *
 * @param array<string,int> $metrics
 * @param array<string,int> $entityActions
 * @param array<string,int> $containedEntities
 * @return array<string,mixed>
 */
function rf_category(string $id, array $metrics, array $entityActions, array $containedEntities): array {
    $row = [
        'id' => $id,
        'metrics' => $metrics === [] ? new stdClass() : $metrics,
        'entity_actions' => $entityActions === [] ? new stdClass() : $entityActions,
        'contained_entities' => $containedEntities === [] ? new stdClass() : $containedEntities,
    ];
    if ($id === 'secrets') {
        $row['visibility'] = 'redacted';
    }
    return $row;
}

$authoredActions = [
    'create' => 2, 'update' => 1, 'adopt' => 0, 'unchanged' => 0,
    'drift' => 0, 'conflict' => 0, 'collision' => 0,
];
$mediaActions = [
    'create' => 1, 'update' => 0, 'adopt' => 0, 'unchanged' => 0, 'drift' => 0,
    'conflict' => 0, 'collision' => 0, 'delete' => 0, 'delete_conflict' => 0, 'deleted' => 0,
];
$deleteActions = ['delete' => 1, 'delete_conflict' => 0, 'deleted' => 0];

// The scope this fixture publishes, and therefore the whole point of it:
// post + attachment + term are touched; options, user_meta, menu, sidebar and
// typed_table are not. Code and lifecycle are non-empty (one plugin whose
// lifecycle disagrees) and environment_state carries one missing env value.
$categorySummary = [
    'format' => 'duo-plan-category-summary/v1',
    'redaction' => 'values_omitted',
    'facets' => 'overlapping',
    'vocabulary' => ['generated_effects' => ['public_label' => 'generated', 'wire_class' => 'derived']],
    'categories' => [
        rf_category(
            'code',
            [
                'count' => 1, 'compatibility_mismatch' => 0, 'lifecycle_mismatch' => 1,
                'revision_stale' => 0, 'drift' => 0, 'other_mismatch' => 0, 'unsupported_code' => 0,
            ],
            [],
            ['plugin' => 1, 'theme' => 0, 'other' => 0]
        ),
        rf_category('lifecycle', ['count' => 1, 'code_lifecycle_mismatch' => 1, 'incomplete_lifecycle' => 0], [], []),
        rf_category(
            'authored_state',
            ['count' => 3],
            $authoredActions,
            [
                'post' => 2, 'attachment' => 1, 'term' => 0, 'menu' => 0,
                'sidebar' => 0, 'options' => 0, 'user_meta' => 0, 'typed_table' => 0,
            ]
        ),
        rf_category(
            'generated_effects',
            [
                'count' => 2, 'declared_effects' => 1, 'declared_lifecycle_effects' => 1,
                'declared_rebuild_effects' => 0, 'declared_regenerator_effects' => 0,
                'selected_native_actions' => 1, 'selected_provider_actions' => 0,
                'regen_pending' => 0, 'incomplete_apply' => 0,
            ],
            [],
            []
        ),
        rf_category(
            'media',
            ['count' => 1, 'attachment_entities' => 1, 'upload_inventory_entries' => 0],
            $mediaActions,
            ['attachment' => 1]
        ),
        rf_category('secrets', [], [], []),
        rf_category(
            'environment_state',
            [
                'count' => 1, 'state_drift' => 0, 'code_drift' => 0, 'required_env_missing' => 1,
                'optional_env_missing' => 0, 'missing_user' => 0, 'skipped_user_meta' => 0,
                'incomplete_lifecycle' => 0, 'incomplete_apply' => 0, 'regen_pending' => 0,
            ],
            [],
            []
        ),
        rf_category(
            'capabilities',
            [
                'count' => 0, 'certification_source_blockers' => 0,
                'selected_provider_blockers' => 0, 'declared_unselected_provider_problems' => 0,
            ],
            [],
            []
        ),
        rf_category(
            'deletions',
            [
                'count' => 1, 'blocked' => 0, 'nested_menu_item_delete_candidates' => 0,
                'nested_widget_delete_candidates' => 0, 'nested_option_delete_candidates' => 0,
            ],
            $deleteActions,
            [
                'post' => 0, 'attachment' => 0, 'term' => 1, 'menu' => 0,
                'sidebar' => 0, 'options' => 0, 'user_meta' => 0, 'typed_table' => 0,
            ]
        ),
    ],
];

/** Every bucket `PlanContract::requiredBuckets()` closes over. */
$plan = [
    'adapter_dispositions' => [], 'adopt' => [], 'code_drift' => [], 'code_mismatch' => [],
    'collision' => [], 'conflict' => [], 'create' => [], 'delete' => [],
    'delete_conflict' => [], 'deleted' => [], 'drift' => [], 'effects_inventory' => [],
    'env_missing' => [], 'incomplete_apply' => [], 'incomplete_lifecycle' => [],
    'missing_user' => [], 'provider_problems' => [], 'regen_context' => [], 'regen_pending' => [],
    'skipped_user_meta' => [], 'unchanged' => [], 'update' => [], 'uploads_inventory' => [],
    'warnings' => [],
    'category_summary' => $categorySummary,
];

/**
 * @param array<string,mixed> $overrides
 * @return array<string,mixed>
 */
function rf_projection(array $overrides): array {
    return $overrides + [
        'state_class' => 'authored',
        'handling' => 'manage',
        'readiness' => 'Ready',
        'certification_provenance' => 'Platform-certified',
        'effect_containment' => 'prevented',
        'effect_containment_basis' => ProjectionVocabulary::CONTAINMENT_BASIS_PREVENTED,
        'effect_recovery_semantics' => 'provider-state restorable',
        'conditions' => [],
        'remediation' => null,
        'meaning' => 'proven on the exact stack and target',
        'annotations' => [],
    ];
}

/**
 * @param array<string,mixed> $projection
 * @return array<string,mixed>
 */
function rf_surface(string $id, string $kind, string $label, array $projection, string $nextAction = 'nothing — supported'): array {
    return [
        'id' => $id,
        'label' => $label,
        'kind' => $kind,
        'state_class' => (string) $projection['state_class'],
        'handling' => (string) $projection['handling'],
        'operations' => ['release' => $projection],
        'next_action' => $nextAction,
        'decided_by' => 'operator',
        'meaning' => (string) $projection['meaning'],
    ];
}

$surfaces = [
    // in scope: the plan touches `post`
    rf_surface('post_type:product', 'post_type', 'Products', rf_projection([])),
    // in scope: the plan touches `attachment`
    rf_surface('media:attachment', 'media', 'Media', rf_projection([])),
    // in scope: the plan touches `term` through its deletions facet
    rf_surface('taxonomy:product_cat', 'taxonomy', 'Product categories', rf_projection([])),
    // OUT of scope: the plan touches no `typed_table`
    rf_surface('table:acme_catalog', 'table', 'Custom catalog table', rf_projection([
        'state_class' => 'unclassified',
        'handling' => 'block',
        'readiness' => 'Not qualified',
        'certification_provenance' => 'Uncertified',
        'effect_containment' => 'unknown',
        'effect_containment_basis' => ProjectionVocabulary::CONTAINMENT_BASIS_UNKNOWN,
        'effect_recovery_semantics' => 'unknown',
        'meaning' => 'nothing classified this state, so nothing may write it',
        'annotations' => [ProjectionVocabulary::ANNOTATION_UNCLASSIFIED_NOT_QUALIFIED],
    ]), 'qualify in rehearsal'),
    // in scope through the lifecycle window, NOT through an entity kind
    rf_surface('option_group:core:managed', 'option_group', 'Plugins and themes', rf_projection([
        'effect_containment' => 'unknown',
        'effect_containment_basis' => ProjectionVocabulary::CONTAINMENT_BASIS_UNKNOWN,
        'annotations' => [ProjectionVocabulary::ANNOTATION_MANAGED],
        'meaning' => 'the code half owns this state',
    ])),
    // in scope through environment_state, NOT through an entity kind
    rf_surface('option_group:woocommerce:env', 'option_group', 'Payment keys', rf_projection([
        'state_class' => 'environment-bound',
        'handling' => 'rebind',
        'readiness' => 'Ready with conditions',
        'effect_containment' => 'unknown',
        'effect_containment_basis' => ProjectionVocabulary::CONTAINMENT_BASIS_UNKNOWN,
        'effect_recovery_semantics' => 'not applicable',
        'conditions' => ['env_missing woocommerce_stripe_secret'],
        'meaning' => 'this value belongs to the environment, not to the repository',
    ]), 'provision env value'),
    // in scope through `post`, and Unsupported: a rehearsal shows it anyway
    rf_surface('post_type:shop_order', 'post_type', 'Orders', rf_projection([
        'state_class' => 'runtime',
        'handling' => 'preserve local',
        'readiness' => 'Unsupported',
        'effect_recovery_semantics' => 'not applicable',
        'meaning' => 'live operational state is never copied',
    ]), 'exclude'),
    // OUT of scope: the plan touches no `user_meta`
    rf_surface('user_meta:core:authored', 'user_meta', 'User profile fields', rf_projection([])),
    // in scope, and Experimental: the disclosure blocks authorizing it here
    rf_surface('post_type:tribe_events', 'post_type', 'Events', rf_projection([
        'readiness' => 'Experimental',
        'meaning' => 'proven only against a candidate evidence profile',
    ]), 'qualify in rehearsal'),
    // in scope, and Uncertified: the disclosure blocks authorizing it here
    rf_surface('post_type:acme_thing', 'post_type', 'Acme things', rf_projection([
        'certification_provenance' => 'Uncertified',
        'annotations' => [ProjectionVocabulary::ANNOTATION_SITE_CERTIFICATION_DEFERRED],
    ]), 'install adapter'),
];

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
file_put_contents($out . '/plan.json', json_encode($plan, $flags) . "\n");
file_put_contents($out . '/surfaces.json', json_encode($surfaces, $flags) . "\n");
echo "rehearse fixture written to $out\n";
