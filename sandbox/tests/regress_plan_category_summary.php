<?php
/**
 * Offline regression for DUO-3345's optional plan category projection.
 *
 * The detailed plan remains authoritative. This suite proves the additive
 * projection is a closed, ordered, count-only and value-free view; validates
 * it through the public host contract; and exercises Apply's pure nested
 * deletion counter without WordPress, a database, providers, or Docker.
 */
declare(strict_types=1);

if (!defined('DUO_SPEC_VERSION')) {
    define('DUO_SPEC_VERSION', 2);
}
require_once __DIR__ . '/../../agent/src/Canon.php';
require_once __DIR__ . '/../../agent/src/OptionState.php';
require_once __DIR__ . '/../../agent/src/Policy.php';
require_once __DIR__ . '/../../agent/src/SidebarState.php';
require_once __DIR__ . '/../../agent/src/Snapshot.php';
require_once __DIR__ . '/../../agent/src/Apply.php';
require_once __DIR__ . '/../../cli/src/PlanContract.php';
require_once __DIR__ . '/../../cli/src/PlanSummary.php';

use Duo\ApplyPlanner;
use Duo\PlanCategorySummary;
use Duo\Orchestrator\PlanContract;
use Duo\Orchestrator\PlanSummary;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

$emptyPlan = static function (): array {
    return array_fill_keys([
        'adapter_dispositions', 'adopt', 'code_drift', 'code_mismatch',
        'collision', 'conflict', 'create', 'delete', 'delete_conflict',
        'deleted', 'drift', 'effects_inventory', 'env_missing',
        'incomplete_apply', 'incomplete_lifecycle', 'missing_user',
        'provider_problems', 'regen_context', 'regen_pending', 'skipped_user_meta',
        'unchanged', 'update', 'uploads_inventory', 'warnings',
    ], []);
};

$plan = $emptyPlan();
$plan['create'][] = [
    'uuid' => 'post-create', 'type' => 'post', 'path' => 'posts/page/post-create.md',
    'title' => "SECRET_TITLE\nINJECTED",
];
$plan['update'][] = [
    'uuid' => 'attachment-update', 'type' => 'post',
    'path' => 'posts/attachment/SECRET_MEDIA.jpg.md',
];
$plan['update'][] = [
    'uuid' => 'table-update', 'type' => 'acme_records',
    'path' => 'tables/acme_records/SECRET_TABLE.json',
];
$plan['adopt'][] = [
    'uuid' => 'term-adopt', 'type' => 'term', 'path' => 'terms/category/SECRET_TERM.md',
];
$plan['unchanged'][] = [
    'uuid' => 'menu-unchanged', 'type' => 'menu', 'path' => 'menus/SECRET_MENU.json',
];
$plan['drift'][] = [
    'uuid' => 'sidebar-drift', 'type' => 'sidebar', 'path' => 'sidebars/SECRET_SIDEBAR.json',
];
$plan['conflict'][] = [
    'uuid' => 'options-conflict', 'type' => 'options', 'path' => 'options/SECRET_OPTION.json',
];
$plan['collision'][] = [
    'uuid' => 'user-meta-collision', 'type' => 'user-meta',
    'path' => 'user-meta/SECRET_LOGIN.json', 'env_id' => 77,
];

$plan['delete'][] = [
    'uuid' => 'attachment-delete', 'type' => 'post', 'deletion_kind' => 'post',
    'deletion_type' => 'attachment', 'path' => 'deletions/post/SECRET_ATTACHMENT.json',
    'blocked' => 'SECRET_GUARD_REASON',
];
$plan['delete'][] = [
    'uuid' => 'menu-delete', 'type' => 'menu', 'deletion_kind' => 'menu',
    'deletion_type' => 'nav_menu', 'path' => 'deletions/menu/SECRET_DELETE_MENU.json',
];
$plan['delete_conflict'][] = [
    'uuid' => 'table-delete-conflict', 'type' => 'acme_records',
    'deletion_kind' => 'table', 'deletion_type' => 'acme_records',
    'path' => 'deletions/table/SECRET_TABLE_DELETE.json',
];
$plan['deleted'][] = [
    'uuid' => 'post-deleted', 'type' => 'post', 'deletion_kind' => 'post',
    'deletion_type' => 'page', 'path' => 'deletions/post/SECRET_POST_DELETE.json',
];

$plan['code_mismatch'] = [
    ['issue' => 'missing_in_code', 'kind' => 'plugin', 'plugin' => 'SECRET_PLUGIN_A/plugin.php'],
    ['issue' => 'outside_version_range', 'kind' => 'theme', 'theme' => 'SECRET_THEME'],
    ['issue' => 'inactive_in_environment', 'kind' => 'plugin', 'plugin' => 'SECRET_PLUGIN_B/plugin.php'],
    ['issue' => 'code_revision_stale', 'kind' => 'code', 'revision' => 'SECRET_REVISION'],
    ['issue' => 'future_code_fact', 'kind' => 'future_kind', 'message' => 'SECRET_FUTURE'],
];
$plan['code_drift'] = [
    ['issue' => 'code_drift', 'kind' => 'theme', 'theme' => 'SECRET_DRIFT_THEME'],
];
$plan['incomplete_lifecycle'] = [['phase' => 'activate', 'message' => 'SECRET_LIFECYCLE']];
$plan['incomplete_apply'] = [['reason' => 'SECRET_APPLY_RETRY']];
$plan['regen_pending'] = [['uuid' => 'SECRET_REGEN_UUID', 'type' => 'post']];
$plan['env_missing'] = [
    ['name' => 'SECRET_REQUIRED_A', 'required' => true],
    ['name' => 'SECRET_REQUIRED_B', 'required' => true],
    ['name' => 'SECRET_OPTIONAL', 'required' => false],
];
$plan['missing_user'] = [
    ['login' => 'SECRET_LOGIN_A'], ['login' => 'SECRET_LOGIN_B'],
];
$plan['skipped_user_meta'] = [['login' => 'SECRET_SKIPPED_LOGIN']];
$plan['uploads_inventory'] = [
    ['original_path' => 'SECRET_UPLOAD_A.jpg'], ['original_path' => 'SECRET_UPLOAD_B.jpg'],
];
$plan['effects_inventory'] = [
    ['phase' => 'lifecycle', 'effect' => ['id' => 'SECRET_EFFECT_LIFECYCLE']],
    ['phase' => 'rebuild', 'effect' => ['id' => 'SECRET_EFFECT_REBUILD']],
    ['phase' => 'regenerator', 'effect' => ['id' => 'SECRET_EFFECT_REGENERATOR']],
    ['phase' => 'future_phase', 'effect' => ['id' => 'SECRET_EFFECT_FUTURE']],
];
$plan['adapter_dispositions'] = [
    ['name' => 'SECRET_CERT_A'], ['name' => 'SECRET_CERT_B'], ['provider' => 'SECRET_SELECTED'],
];
$plan['provider_problems'] = [
    ['provider' => 'SECRET_UNSELECTED_A'], ['provider' => 'SECRET_UNSELECTED_B'],
];
$plan['warnings'] = ["SECRET_WARNING\nINJECTED_WARNING"];

$tree = [
    'post-create' => ['type' => 'post', 'data' => ['type' => 'page']],
    'attachment-update' => ['type' => 'post', 'data' => ['type' => 'attachment']],
    'table-update' => ['type' => 'acme_records', 'data' => []],
    'term-adopt' => ['type' => 'term', 'data' => ['taxonomy' => 'category']],
    'menu-unchanged' => ['type' => 'menu', 'data' => []],
    'sidebar-drift' => ['type' => 'sidebar', 'data' => []],
    'options-conflict' => ['type' => 'options', 'data' => []],
    'user-meta-collision' => ['type' => 'user-meta', 'data' => []],
];
$deletions = [
    'attachment-delete' => ['data' => ['kind' => 'post', 'type' => 'attachment']],
    'menu-delete' => ['data' => ['kind' => 'menu', 'type' => 'nav_menu']],
    'table-delete-conflict' => ['data' => ['kind' => 'table', 'type' => 'acme_records']],
    'post-deleted' => ['data' => ['kind' => 'post', 'type' => 'page']],
];
$context = [
    'selected_native_actions' => 2,
    'selected_provider_actions' => 1,
    'certification_source_blockers' => 2,
    'selected_provider_blockers' => 1,
    'nested_menu_item_delete_candidates' => 6,
    'nested_widget_delete_candidates' => 1,
    'nested_option_delete_candidates' => 4,
];

$summary = PlanCategorySummary::build($plan, $tree, $deletions, $context);
$check(is_array($summary), 'complete compiled/count context emits the optional projection');
if (!is_array($summary)) {
    fwrite(STDERR, "FAIL: category projection unexpectedly absent\n");
    exit(1);
}
$byId = [];
foreach ($summary['categories'] as $category) {
    $byId[$category['id']] = $category;
}

$check(array_keys($summary) === ['format', 'redaction', 'facets', 'vocabulary', 'categories'], 'top-level key order is closed');
$check($summary['format'] === PlanCategorySummary::FORMAT, 'summary uses the versioned format');
$check($summary['redaction'] === 'values_omitted' && $summary['facets'] === 'overlapping', 'redaction and overlapping-facet doctrine are explicit');
$check(array_keys($byId) === [
    'code', 'lifecycle', 'authored_state', 'generated_effects', 'media',
    'secrets', 'environment_state', 'capabilities', 'deletions',
], 'category order and vocabulary are fixed');
$check($summary['vocabulary'] === [
    'generated_effects' => ['public_label' => 'generated', 'wire_class' => 'derived'],
], 'generated is public vocabulary while derived remains the wire classifier');

$check($byId['code']['metrics'] === [
    'count' => 6,
    'compatibility_mismatch' => 2,
    'lifecycle_mismatch' => 1,
    'revision_stale' => 1,
    'drift' => 1,
    'other_mismatch' => 1,
    'unsupported_code' => 2,
], 'code facts use closed compatibility/lifecycle/revision/drift/other buckets');
$check($byId['code']['contained_entities'] === ['plugin' => 2, 'theme' => 2, 'other' => 2], 'unknown code kinds remain bounded as other');
$check($byId['lifecycle']['metrics'] === [
    'count' => 2, 'code_lifecycle_mismatch' => 1, 'incomplete_lifecycle' => 1,
], 'lifecycle overlaps only its exact code facts and incomplete marker');
$check($byId['authored_state']['metrics'] === ['count' => 8], 'authored state counts only non-deletion entity actions');
$check($byId['authored_state']['entity_actions'] === [
    'create' => 1, 'update' => 2, 'adopt' => 1, 'unchanged' => 1,
    'drift' => 1, 'conflict' => 1, 'collision' => 1,
], 'authored action map is exact and zero-free only because every action is exercised');
$check($byId['authored_state']['contained_entities'] === [
    'post' => 1, 'attachment' => 1, 'term' => 1, 'menu' => 1,
    'sidebar' => 1, 'options' => 1, 'user_meta' => 1, 'typed_table' => 1,
], 'compiled identity context covers every engine and adapter entity family');
$check($byId['generated_effects']['metrics'] === [
    'count' => 9,
    'declared_effects' => 4,
    'declared_lifecycle_effects' => 1,
    'declared_rebuild_effects' => 1,
    'declared_regenerator_effects' => 1,
    'selected_native_actions' => 2,
    'selected_provider_actions' => 1,
    'regen_pending' => 1,
    'incomplete_apply' => 1,
], 'generated counts distinguish declarations, eligible phases, selected actions, and retry facts');
$check($byId['media']['metrics'] === [
    'count' => 4, 'attachment_entities' => 2, 'upload_inventory_entries' => 2,
], 'media combines attachment entities and upload inventory without copying paths');
$check($byId['media']['entity_actions'] === [
    'create' => 0, 'update' => 1, 'adopt' => 0, 'unchanged' => 0,
    'drift' => 0, 'conflict' => 0, 'collision' => 0,
    'delete' => 1, 'delete_conflict' => 0, 'deleted' => 0,
], 'media retains exact attachment action provenance');
$check($byId['secrets']['visibility'] === 'redacted', 'secret visibility is an explicit closed value');
$check($byId['secrets']['metrics'] instanceof stdClass
    && $byId['secrets']['entity_actions'] instanceof stdClass
    && $byId['secrets']['contained_entities'] instanceof stdClass,
    'empty facets remain JSON objects, not lists');
$check($byId['environment_state']['metrics'] === [
    'count' => 11,
    'state_drift' => 1,
    'code_drift' => 1,
    'required_env_missing' => 2,
    'optional_env_missing' => 1,
    'missing_user' => 2,
    'skipped_user_meta' => 1,
    'incomplete_lifecycle' => 1,
    'incomplete_apply' => 1,
    'regen_pending' => 1,
], 'environment state arithmetic is exact and value-free');
$check($byId['capabilities']['metrics'] === [
    'count' => 5,
    'certification_source_blockers' => 2,
    'selected_provider_blockers' => 1,
    'declared_unselected_provider_problems' => 2,
], 'capability origins remain separate rather than inferred after merging');
$check($byId['deletions']['metrics'] === [
    'count' => 4,
    'blocked' => 1,
    'nested_menu_item_delete_candidates' => 6,
    'nested_widget_delete_candidates' => 1,
    'nested_option_delete_candidates' => 4,
], 'top-level and nested deletion candidates remain distinct');
$check($byId['deletions']['entity_actions'] === [
    'delete' => 2, 'delete_conflict' => 1, 'deleted' => 1,
], 'deletion action map is exact');
$check($byId['deletions']['contained_entities'] === [
    'post' => 1, 'attachment' => 1, 'term' => 0, 'menu' => 1,
    'sidebar' => 0, 'options' => 0, 'user_meta' => 0, 'typed_table' => 1,
], 'deletion kinds use tombstone context rather than path guesses');

$encoded = json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
$check(str_contains($encoded, '"metrics":{}'), 'empty metric maps serialize as JSON objects');
$check(str_contains($encoded, '"entity_actions":{}'), 'empty action maps serialize as JSON objects');
foreach ([
    'SECRET_TITLE', 'SECRET_MEDIA', 'SECRET_TABLE', 'SECRET_TERM', 'SECRET_MENU',
    'SECRET_SIDEBAR', 'SECRET_OPTION', 'SECRET_LOGIN', 'SECRET_GUARD',
    'SECRET_PLUGIN', 'SECRET_THEME', 'SECRET_REVISION', 'SECRET_EFFECT',
    'SECRET_PROVIDER', 'SECRET_WARNING', 'INJECTED',
] as $raw) {
    $check(!str_contains($encoded, $raw), "summary omits raw detailed bytes matching $raw");
}

$reordered = $plan;
foreach ($reordered as $key => $rows) {
    $reordered[$key] = array_reverse($rows);
}
$reorderedTree = array_reverse($tree, true);
$reorderedDeletions = array_reverse($deletions, true);
$reorderedSummary = PlanCategorySummary::build($reordered, $reorderedTree, $reorderedDeletions, $context);
$check(json_encode($reorderedSummary, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) === $encoded, 'row and compiler-map order cannot change summary bytes');

$check(PlanContract::validCategorySummary($summary), 'public host validator accepts the internal projection');
$decodedSummary = json_decode($encoded, true, 512, JSON_THROW_ON_ERROR);
$check(PlanContract::validCategorySummary($decodedSummary), 'public host validator accepts the associative JSON decode');
$check(PlanContract::categorySummaryViolations($decodedSummary) === [], 'valid projection has no display-contract violations');

$mutations = [];
$mutated = $decodedSummary;
$mutated['categories'][0]['metrics']['count']++;
$mutations['inconsistent arithmetic'] = $mutated;
$mutated = $decodedSummary;
$mutated['categories'][0]['metrics']['raw_SECRET_key'] = 1;
$mutations['unknown metric key'] = $mutated;
$mutated = $decodedSummary;
$mutated['vocabulary']['SECRET_vocabulary'] = ['value' => 1];
$mutations['unknown vocabulary key'] = $mutated;
$mutated = $decodedSummary;
$first = array_shift($mutated);
$mutated['format'] = $first;
$mutations['reordered top-level keys'] = $mutated;
$mutated = $decodedSummary;
$mutated['categories'][0]['metrics']['count'] = '6';
$mutations['string count'] = $mutated;
foreach ($mutations as $label => $mutated) {
    $check(!PlanContract::validCategorySummary($mutated), "$label is rejected by the closed display contract");
}

$complete = $plan;
$complete['category_summary'] = $decodedSummary;
$check(PlanContract::violations($complete) === [], 'valid optional projection does not widen the authoritative bucket contract');
$invalid = $complete;
$invalid['category_summary'] = $mutations['unknown metric key'];
$without = $plan;
$validRendered = PlanSummary::render($complete);
$invalidRendered = PlanSummary::render($invalid);
$withoutRendered = PlanSummary::render($without);
$check($validRendered['ok'] === $invalidRendered['ok'] && $validRendered['ok'] === $withoutRendered['ok'], 'optional projection never changes plan readiness');
$check(PlanContract::violations($invalid) === [] && PlanContract::violations($without) === [], 'malformed or absent optional display data never changes completeness');
$check(str_contains(implode("\n", $validRendered['lines']), 'SUMMARY [duo-plan-category-summary/v1]'), 'host status renders a valid projection');
$check(!str_contains(implode("\n", $invalidRendered['lines']), 'SUMMARY [duo-plan-category-summary/v1]'), 'host status omits a malformed projection');
$check(!str_contains(implode("\n", $withoutRendered['lines']), 'SUMMARY [duo-plan-category-summary/v1]'), 'host status does not synthesize an older agent projection');

$human = PlanCategorySummary::humanLines($summary);
$humanText = implode("\n", $human);
$check(PlanContract::categorySummaryHumanLines($decodedSummary) === $human, 'separately deployable host and agent renderers stay byte-identical');
$check($human[0] === 'SUMMARY [duo-plan-category-summary/v1]', 'human projection has a stable versioned header');
$check(str_contains($humanText, 'secrets: redacted; secret values omitted'), 'human projection makes secret redaction visible');
$check(str_contains($humanText, 'vocabulary: generated effects use shipped derived classification'), 'human projection teaches public/wire vocabulary');
foreach (['SECRET_', 'INJECTED'] as $raw) {
    $check(!str_contains($humanText, $raw), "human projection omits $raw bytes");
}

$badContext = $context;
$badContext['nested_option_delete_candidates'] = -1;
$check(PlanCategorySummary::build($plan, $tree, $deletions, $badContext) === null, 'negative count context omits rather than fabricates a projection');
$badContext = array_reverse($context, true);
$check(PlanCategorySummary::build($plan, $tree, $deletions, $badContext) === null, 'out-of-order context is refused');
$missingTree = $tree;
unset($missingTree['table-update']);
$check(PlanCategorySummary::build($plan, $missingTree, $deletions, $context) === null, 'missing compiled identity context omits the projection');
$malformedPlan = $plan;
$malformedPlan['create'] = 'SECRET_NOT_A_LIST';
$check(PlanCategorySummary::build($malformedPlan, $tree, $deletions, $context) === null, 'non-list consumed bucket omits rather than fabricates a projection');
$malformedPlan = $plan;
unset($malformedPlan['create']);
$check(PlanCategorySummary::build($malformedPlan, $tree, $deletions, $context) === null, 'missing consumed bucket omits rather than fabricates an empty count');
$malformedPlan = $plan;
$malformedPlan['create'] = ['named' => $plan['create'][0]];
$check(PlanCategorySummary::build($malformedPlan, $tree, $deletions, $context) === null, 'associative consumed bucket omits rather than reorders a projection');
$malformedPlan = $plan;
$malformedPlan['create'][] = 'SECRET_NOT_A_ROW';
$check(PlanCategorySummary::build($malformedPlan, $tree, $deletions, $context) === null, 'non-row evidence omits rather than silently dropping bytes');
$malformedPlan = $plan;
$malformedPlan['create'][0]['uuid'] = new stdClass();
$check(PlanCategorySummary::build($malformedPlan, $tree, $deletions, $context) === null, 'non-string row identity omits without invoking plugin-controlled string conversion');

// Exercise the pure planner projection directly at its production owner.
$nested = new ReflectionMethod(ApplyPlanner::class, 'nested_delete_candidate_counts');
$nestedTree = [
    'menu-update' => ['type' => 'menu', 'data' => ['items' => [
        ['uuid' => 'item-keep'], ['uuid' => 'item-new'],
    ]]],
    'menu-adopt' => ['type' => 'menu', 'data' => ['items' => [
        ['uuid' => 'adopt-keep'],
    ]]],
    'sidebar-a' => ['type' => 'sidebar', 'data' => ['widgets' => []]],
    'sidebar-b' => ['type' => 'sidebar', 'data' => ['widgets' => [
        ['type' => 'text', 'uuid' => 'widget-moved'],
    ]]],
];
$nestedEnv = [
    'menu-update' => ['type' => 'menu'],
    'menu-delete' => ['type' => 'menu'],
    'sidebar-a' => ['type' => 'sidebar'],
    'sidebar-b' => ['type' => 'sidebar'],
];
$nestedPlan = $emptyPlan();
$nestedPlan['update'] = [
    ['uuid' => 'menu-update', 'type' => 'menu'],
    ['uuid' => 'sidebar-a', 'type' => 'sidebar', 'widget_deletes' => [
        ['type' => 'text', 'uuid' => 'widget-moved'],
        ['type' => 'text', 'uuid' => 'widget-stale'],
    ]],
    ['uuid' => 'sidebar-b', 'type' => 'sidebar', 'widget_deletes' => [
        ['type' => 'text', 'uuid' => 'widget-stale'],
    ]],
    ['uuid' => 'options/core', 'type' => 'options', 'option_deletes' => ['b', 'c']],
];
$nestedTree['options/core'] = ['type' => 'options', 'data' => []];
$nestedEnv['options/core'] = ['type' => 'options'];
$nestedPlan['create'][] = ['uuid' => 'options/core', 'type' => 'options', 'option_deletes' => ['a', 'b']];
$nestedPlan['conflict'][] = ['uuid' => 'options/core', 'type' => 'options', 'option_deletes' => ['c', 'd']];
$nestedPlan['adopt'][] = ['uuid' => 'options/core', 'type' => 'options', 'option_deletes' => ['ignored-adopt']];
$nestedPlan['adopt'][] = ['uuid' => 'menu-adopt', 'type' => 'menu', 'env_id' => 42];
$nestedPlan['unchanged'][] = ['uuid' => 'options/core', 'type' => 'options', 'option_deletes' => ['ignored-unchanged']];
$nestedPlan['delete'][] = [
    'uuid' => 'menu-delete', 'type' => 'menu', 'deletion_kind' => 'menu',
];
$observations = ['menus_by_term_id' => [
    '11' => [
        'uuid' => 'menu-update',
        'managed_menu_item_uuids' => ['item-keep', 'item-stale-a', 'item-stale-b'],
        'all_menu_item_count' => 3,
    ],
    '12' => [
        'uuid' => 'menu-delete',
        'managed_menu_item_uuids' => ['delete-item'],
        'all_menu_item_count' => 4,
    ],
    '42' => [
        'uuid' => null,
        'managed_menu_item_uuids' => ['adopt-keep', 'adopt-stale'],
        'all_menu_item_count' => 2,
    ],
]];
$nestedCounts = $nested->invoke(null, $nestedEnv, $nestedTree, $nestedPlan, $observations);
$check($nestedCounts === ['menu' => 7, 'widget' => 1, 'option' => 4], 'nested candidates mirror menu reconciliation/adoption/tombstone, global widget move, and exact option buckets');
$check($nested->invoke(null, $nestedEnv, $nestedTree, $nestedPlan, null) === null, 'missing coherent menu observation omits the optional projection');
$badObservations = $observations;
$badObservations['menus_by_term_id']['11']['managed_menu_item_uuids'][] = new stdClass();
$check($nested->invoke(null, $nestedEnv, $nestedTree, $nestedPlan, $badObservations) === null, 'malformed observation fails closed');
$badObservations = $observations;
$badObservations['menus_by_term_id']['11']['managed_menu_item_uuids'][] = 'item-keep';
$check($nested->invoke(null, $nestedEnv, $nestedTree, $nestedPlan, $badObservations) === null, 'duplicate managed menu identity fails closed');
$badObservations = $observations;
$badObservations['menus_by_term_id']['11']['all_menu_item_count'] = 1;
$check($nested->invoke(null, $nestedEnv, $nestedTree, $nestedPlan, $badObservations) === null, 'managed menu count cannot exceed the observed target total');
$badNestedTree = $nestedTree;
$badNestedTree['sidebar-a']['data']['widgets'][] = 'SECRET_NOT_A_WIDGET';
$check($nested->invoke(null, $nestedEnv, $badNestedTree, $nestedPlan, $observations) === null, 'malformed desired widget row fails closed');
$badNestedTree = $nestedTree;
unset($badNestedTree['sidebar-a']['data']['widgets']);
$check($nested->invoke(null, $nestedEnv, $badNestedTree, $nestedPlan, $observations) === null, 'missing desired widget list fails closed');
$badNestedTree = $nestedTree;
unset($badNestedTree['menu-update']['data']['items']);
$check($nested->invoke(null, $nestedEnv, $badNestedTree, $nestedPlan, $observations) === null, 'missing desired menu-item list fails closed');
$badNestedPlan = $nestedPlan;
$badNestedPlan['update'][] = 'SECRET_NOT_A_PLAN_ROW';
$check($nested->invoke(null, $nestedEnv, $nestedTree, $badNestedPlan, $observations) === null, 'malformed nested-plan row fails closed');
$badNestedPlan = $nestedPlan;
unset($badNestedPlan['delete']);
$check($nested->invoke(null, $nestedEnv, $nestedTree, $badNestedPlan, $observations) === null, 'missing consumed nested-plan bucket fails closed');
$badNestedPlan = $nestedPlan;
$badNestedPlan['update'][1]['widget_deletes'][] = 'SECRET_NOT_A_WIDGET_DELETE';
$check($nested->invoke(null, $nestedEnv, $nestedTree, $badNestedPlan, $observations) === null, 'malformed widget deletion row fails closed');

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . count($failures) . " category-summary check(s) failed\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "PASS: plan category summaries are closed, deterministic, value-free, and non-authorizing\n";
