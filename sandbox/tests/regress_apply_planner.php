<?php
/**
 * Offline regression for ApplyPlanner (DUO-3347 slice 2: the pure
 * conflict/display-projection half of plan production extracted out of
 * Apply.php). Existing suites (regress_conflict_view.php,
 * regress_plan_title_render.php, regress_lifecycle_state_handoff.php,
 * regress_plan_category_summary.php) already exercise these methods'
 * behavior in depth, most via ReflectionMethod against Apply's own thin
 * facades — this file is deliberately narrower: it proves the extracted
 * methods are directly callable as ApplyPlanner's own public API, with no
 * Apply instance, WordPress, database, or Reflection required.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/ApplyPlanner.php';

use Duo\ApplyPlanner;

$failures = [];
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$ok) {
        $failures[] = $message;
    }
};

// --------------------------------------------------------------- conflict_view

$view = ApplyPlanner::conflict_view(
    'repository_and_target_changed_since_base',
    'update',
    'present',
    str_repeat('a', 64),
    str_repeat('b', 64),
    str_repeat('a', 64),
    null,
    str_repeat('c', 64),
    ['--force-theirs']
);
$check($view['format'] === 'duo-plan-conflict/v1', 'conflict_view: versioned format');
$check($view['kind'] === 'concurrent_change', 'conflict_view: update intent is a concurrent_change, not a tombstone');
$check($view['choices'][1]['effect'] === 'replace_target_authored_state', 'conflict_view: update intent derives replace effect');
$check($view['choices'][1]['destructive'] === true, 'conflict_view: apply_repository choice is destructive');
$check($view['choices'][0]['destructive'] === false, 'conflict_view: reconcile_in_repository choice is non-destructive');

$deleteView = ApplyPlanner::conflict_view(
    'target_without_last_synced_base', 'delete', 'missing', null, null,
    str_repeat('a', 64), str_repeat('d', 64), str_repeat('c', 64), ['--with-deletes', '--force-theirs']
);
$check($deleteView['kind'] === 'tombstone_conflict', 'conflict_view: delete intent is a tombstone_conflict');
$check($deleteView['choices'][1]['effect'] === 'delete_target_authored_state', 'conflict_view: delete intent derives delete effect');

// ---------------------------------------------------------- forced_override_evidence

$row = ['uuid' => 'e1', 'conflict_view' => $view];
$evidence = ApplyPlanner::forced_override_evidence($row, 'conflict', ['force_theirs' => true]);
$check($evidence['entity_identity_sha256'] === hash('sha256', 'e1'), 'forced_override_evidence: identity is hashed, never raw');
$check($evidence['status'] === 'authorized', 'forced_override_evidence: every required flag supplied means authorized');
$check($evidence['required_flags'] === ['--force-theirs'], 'forced_override_evidence: required flags come from the conflict_view choice');

$incompleteEvidence = ApplyPlanner::forced_override_evidence($row, 'conflict', []);
$check($incompleteEvidence['status'] === 'incomplete', 'forced_override_evidence: no supplied flags means incomplete');

$blockedRow = ['uuid' => 'e2', 'conflict_view' => $deleteView, 'blocked' => 'x references this row'];
$blockedEvidence = ApplyPlanner::forced_override_evidence($blockedRow, 'delete_conflict', [
    'with_deletes' => true, 'force_theirs' => true,
]);
$check(in_array('--force-delete-referenced', $blockedEvidence['required_flags'], true),
    'forced_override_evidence: a guard-blocked deletion adds the referential escape hatch to required_flags');
$check($blockedEvidence['status'] === 'incomplete', 'forced_override_evidence: guard override flag not yet supplied stays incomplete');

// -------------------------------------------------------- incomplete_override_refusal

$refusal = ApplyPlanner::incomplete_override_refusal([$incompleteEvidence], 'operator detail');
$check($refusal instanceof \Duo\CommandRefusalException, 'incomplete_override_refusal: returns a typed machine-readable refusal');
$check($refusal->reasonCode === 'apply_conflict_override_incomplete', 'incomplete_override_refusal: exact reason code');

// -------------------------------------------------------------- entity_display_title

$check(ApplyPlanner::entity_display_title(['title' => 'About Us']) === 'About Us',
    'entity_display_title: post title');
$check(ApplyPlanner::entity_display_title(['name' => 'Category']) === 'Category',
    'entity_display_title: term/menu name');
$check(ApplyPlanner::entity_display_title(['title' => '   ']) === null,
    'entity_display_title: whitespace-only title is treated as absent');
$check(ApplyPlanner::entity_display_title([]) === null,
    'entity_display_title: no title/name key returns null');
$check(ApplyPlanner::entity_display_title('not-an-array') === null,
    'entity_display_title: non-array data returns null rather than a TypeError');

// ---------------------------------------------------------- lifecycle_comparison_hash

$transition = ['entity' => 'options/core', 'before_hash' => 'before123', 'after_hash' => 'after456'];
$check(ApplyPlanner::lifecycle_comparison_hash('options/core', 'after456', $transition) === 'before123',
    'lifecycle_comparison_hash: matching post-hook snapshot compares against the pre-hook hash');
$check(ApplyPlanner::lifecycle_comparison_hash('options/core', 'somethingElse', $transition) === 'somethingElse',
    'lifecycle_comparison_hash: a later unrelated edit falls back to the ordinary environment hash');
$check(ApplyPlanner::lifecycle_comparison_hash('posts/x', 'envhash', $transition) === 'envhash',
    'lifecycle_comparison_hash: only options/core ever gets the lifecycle rewrite');
$check(ApplyPlanner::lifecycle_comparison_hash('options/core', null, $transition) === null,
    'lifecycle_comparison_hash: no environment hash returns null unchanged');
$check(ApplyPlanner::lifecycle_comparison_hash('options/core', 'envhash', null) === 'envhash',
    'lifecycle_comparison_hash: no recorded transition returns the environment hash unchanged');

// ------------------------------------------------------- nested_delete_candidate_counts

$check(ApplyPlanner::nested_delete_candidate_counts([], [], [], null) === null,
    'nested_delete_candidate_counts: absent observations is refused, not zero');
$check(ApplyPlanner::nested_delete_candidate_counts([], [], [], ['menus_by_term_id' => 'not-an-array']) === null,
    'nested_delete_candidate_counts: malformed menus_by_term_id fails closed');

$emptyPlan = array_fill_keys(
    ['create', 'adopt', 'update', 'conflict', 'delete', 'delete_conflict'],
    []
);
$check(ApplyPlanner::nested_delete_candidate_counts([], [], $emptyPlan, ['menus_by_term_id' => []]) === [
    'menu' => 0, 'widget' => 0, 'option' => 0,
], 'nested_delete_candidate_counts: no candidates in an empty plan/tree counts zero across all three');

$tree = [
    'sidebar-1' => ['type' => 'sidebar', 'data' => ['widgets' => [
        ['type' => 'text', 'uuid' => 'w1'],
    ]]],
];
$planWithWidgetDelete = $emptyPlan;
$planWithWidgetDelete['update'][] = [
    'uuid' => 'sidebar-1',
    'widget_deletes' => [['type' => 'text', 'uuid' => 'w-gone']],
];
$check(ApplyPlanner::nested_delete_candidate_counts(['sidebar-1' => []], $tree, $planWithWidgetDelete, ['menus_by_term_id' => []]) === [
    'menu' => 0, 'widget' => 1, 'option' => 0,
], 'nested_delete_candidate_counts: a widget delete absent from the global desired set counts as one candidate');

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall ApplyPlanner checks passed\n";
exit(0);
