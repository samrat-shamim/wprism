<?php
declare(strict_types=1);

$planSummaryPath = getenv('DUO_PLAN_SUMMARY_PATH');
require_once is_string($planSummaryPath) && $planSummaryPath !== ''
    ? $planSummaryPath
    : __DIR__ . '/../../cli/src/PlanSummary.php';
require_once __DIR__ . '/../../agent/src/Apply.php';

use Duo\Orchestrator\PlanSummary;

function fail_conflict_view(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_conflict_view(bool $condition, string $message): void {
    if (!$condition) {
        fail_conflict_view($message);
    }
}

function empty_plan(): array {
    $plan = [];
    foreach ([
        'create', 'update', 'adopt', 'unchanged', 'drift', 'conflict',
        'collision', 'delete', 'delete_conflict', 'deleted',
    ] as $bucket) {
        $plan[$bucket] = [];
    }
    return $plan;
}

function conflict_view(
    string $reason,
    string $repositoryIntent,
    string $baseState,
    ?string $baseHash,
    ?string $repositoryHash,
    ?string $expectedBaseHash,
    ?string $receiptHash,
    string $targetHash,
    array $requires,
    string $effect
): array {
    return [
        'format' => 'duo-plan-conflict/v1',
        'kind' => $repositoryIntent === 'delete' ? 'tombstone_conflict' : 'concurrent_change',
        'reason_code' => $reason,
        'base' => [
            'role' => 'last_synced',
            'source' => 'duo_state',
            'state' => $baseState,
            'content_hash' => $baseHash,
        ],
        'repository' => [
            'role' => 'repository_intent',
            'source' => 'compiled_repository',
            'intent' => $repositoryIntent,
            'content_hash' => $repositoryHash,
            'expected_base_hash' => $expectedBaseHash,
            'intent_receipt_hash' => $receiptHash,
        ],
        'target' => [
            'role' => 'target_observation',
            'source' => 'live_target_snapshot',
            'intent' => 'preserve_target_change',
            'state' => 'present',
            'content_hash' => $targetHash,
        ],
        'recommended_choice' => 'reconcile_in_repository',
        'choices' => [
            [
                'id' => 'reconcile_in_repository',
                'effect' => 'preserve_and_reconcile_both_intents',
                'requires' => [],
                'destructive' => false,
            ],
            [
                'id' => 'apply_repository',
                'effect' => $effect,
                'requires' => $requires,
                'destructive' => true,
            ],
        ],
    ];
}

$base = str_repeat('a', 64);
$repository = str_repeat('b', 64);
$target = str_repeat('c', 64);
$receipt = str_repeat('d', 64);
$plan = empty_plan();
$plan['conflict'][] = [
    'uuid' => '11111111-1111-7111-8111-111111111111',
    'type' => 'post',
    'path' => 'state/posts/page/11111111-1111-7111-8111-111111111111--about.md',
    'title' => "About\nUs",
    'conflict_view' => conflict_view(
        'repository_and_target_changed_since_base',
        'update',
        'present',
        $base,
        $repository,
        $base,
        null,
        $target,
        ['--force-theirs'],
        'replace_target_authored_state'
    ),
];
$plan['delete_conflict'][] = [
    'uuid' => '22222222-2222-7222-8222-222222222222',
    'type' => 'post',
    'path' => 'state/deletions/22222222-2222-7222-8222-222222222222.json',
    'reason' => 'target entity exists but has no last-synced base',
    'conflict_view' => conflict_view(
        'target_without_last_synced_base',
        'delete',
        'missing',
        null,
        null,
        $base,
        $receipt,
        $target,
        ['--with-deletes', '--force-theirs'],
        'delete_target_authored_state'
    ),
];

$rendered = PlanSummary::render($plan);
$human = implode("\n", $rendered['lines']);

assert_conflict_view($rendered['ok'] === false, 'conflicts must keep status fail-closed');
assert_conflict_view(
    str_contains($human, "state/posts/page/11111111-1111-7111-8111-111111111111--about.md 'About Us'"),
    'the conflict row must retain its WordPress name'
);
foreach ([
    'WHY repository_and_target_changed_since_base',
    'BASE last-synced: present sha256:aaaaaaaaaaaa',
    'REPOSITORY intent=update state=sha256:bbbbbbbbbbbb expected-base=sha256:aaaaaaaaaaaa',
    'TARGET observation: intent=preserve_target_change state=present sha256:cccccccccccc',
    'SAFE CHOICE reconcile_in_repository: preserve both intents; capture the target change, resolve it in the repository, then re-plan',
    'DESTRUCTIVE OVERRIDE apply_repository (--force-theirs): replace target authored state',
    'WHY target_without_last_synced_base',
    'BASE last-synced: missing none',
    'REPOSITORY intent=delete state=none expected-base=sha256:aaaaaaaaaaaa',
    'DESTRUCTIVE OVERRIDE apply_repository (--with-deletes --force-theirs): delete target authored state',
] as $needle) {
    assert_conflict_view(str_contains($human, $needle), "human conflict view is missing: $needle");
}

$json = json_encode($plan, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
assert_conflict_view(str_contains($json, $base), 'JSON must retain the exact last-synced hash');
assert_conflict_view(str_contains($json, $repository), 'JSON must retain the exact repository content hash');
assert_conflict_view(str_contains($json, $target), 'JSON must retain the exact target semantic hash');
assert_conflict_view(str_contains($json, $receipt), 'JSON must retain the exact deletion-intent receipt');
assert_conflict_view(
    !str_contains($json, 'sk_live_') && !str_contains($json, 'private-value'),
    'the conflict wire must remain evidence-only and contain no fixture value material'
);

$blockedPlan = empty_plan();
$blockedView = conflict_view(
    'target_changed_since_delete_base',
    'delete',
    'present',
    $base,
    null,
    $base,
    $receipt,
    $target,
    ['--with-deletes', '--force-theirs'],
    'delete_target_authored_state'
);
$blockedView['choices'] = array_values(array_filter(
    $blockedView['choices'],
    static fn(array $choice): bool => $choice['id'] !== 'apply_repository'
));
$blockedPlan['delete_conflict'][] = [
    'uuid' => '33333333-3333-7333-8333-333333333333',
    'type' => 'post',
    'path' => 'state/deletions/33333333-3333-7333-8333-333333333333.json',
    'reason' => 'target entity changed locally since the tombstone base',
    'blocked' => 'comments reference this post — 1 row(s)',
    'conflict_view' => $blockedView,
];
$blockedHuman = implode("\n", PlanSummary::render($blockedPlan)['lines']);
assert_conflict_view(
    str_contains($blockedHuman, 'blocked deletes (referential guard):'),
    'a guard-blocked conflict must retain the separate referential refusal'
);
assert_conflict_view(
    !str_contains($blockedHuman, 'DESTRUCTIVE OVERRIDE apply_repository'),
    'a guard-blocked deletion conflict must not advertise a destructive repository choice'
);

$applyReflection = new ReflectionClass(\Duo\Apply::class);
$apply = $applyReflection->newInstanceWithoutConstructor();
$warnings = $applyReflection->getProperty('warnings');
$warnings->setValue($apply, [
    'ordinary context that must not be promoted into failure output',
    'FORCED conflict 44444444-4444-7444-8444-444444444444 (repository intent authorized to replace target authored state)',
]);
$forcedEvidence = $applyReflection->getProperty('forcedOverrideEvidence');
$evidenceMethod = $applyReflection->getMethod('forced_override_evidence');
$ordinaryEvidence = $evidenceMethod->invoke(null, [
    'uuid' => '44444444-4444-7444-8444-444444444444',
    'conflict_view' => $plan['conflict'][0]['conflict_view'],
], 'conflict', ['force_theirs' => true]);
$forcedEvidence->setValue($apply, [$ordinaryEvidence]);
$preserve = $applyReflection->getMethod('failure_with_forced_warnings');
$laterFailure = new RuntimeException('later convergence failed');
$reported = $preserve->invoke(null, $laterFailure, $apply);
assert_conflict_view($reported instanceof \Duo\CommandRefusalException, 'forced failure evidence must remain a typed machine-readable refusal');
assert_conflict_view($reported->getPrevious() === $laterFailure, 'forced failure evidence must retain the exact original cause');
assert_conflict_view(
    str_contains($reported->getMessage(), 'Warning: FORCED conflict 44444444-4444-7444-8444-444444444444')
        && str_contains($reported->getMessage(), 'later convergence failed'),
    'a later failure must not hide the ordinary conflict override'
);
assert_conflict_view(
    !str_contains($reported->getMessage(), 'ordinary context'),
    'only explicit force evidence may be promoted into failure output'
);
$forcedPayload = $reported->payload();
assert_conflict_view(
    ($forcedPayload['error'] ?? null) === 'apply_forced_override_failed'
        && ($forcedPayload['forced_overrides'][0]['entity_identity_sha256'] ?? null)
            === hash('sha256', '44444444-4444-7444-8444-444444444444')
        && ($forcedPayload['forced_overrides'][0]['choice'] ?? null) === 'apply_repository'
        && ($forcedPayload['forced_overrides'][0]['required_flags'] ?? null) === ['--force-theirs']
        && ($forcedPayload['forced_overrides'][0]['supplied_flags'] ?? null) === ['--force-theirs']
        && ($forcedPayload['forced_overrides'][0]['status'] ?? null) === 'authorized',
    'forced failure JSON evidence must contain only the reviewed hashed identity and engine-owned override enums'
);
assert_conflict_view(
    !str_contains((string) json_encode($forcedPayload), '44444444-4444-7444-8444-444444444444'),
    'forced failure JSON evidence must not expose even the successful plan row identity verbatim'
);

$blockedEvidence = $evidenceMethod->invoke(null, $blockedPlan['delete_conflict'][0], 'delete_conflict', [
    'with_deletes' => true,
    'force_theirs' => true,
]);
assert_conflict_view(
    ($blockedEvidence['choice'] ?? null) === 'explicit_force_flags'
        && ($blockedEvidence['effect'] ?? null) === 'delete_target_authored_state'
        && ($blockedEvidence['required_flags'] ?? null)
            === ['--with-deletes', '--force-theirs', '--force-delete-referenced']
        && ($blockedEvidence['supplied_flags'] ?? null) === ['--with-deletes', '--force-theirs']
        && ($blockedEvidence['status'] ?? null) === 'incomplete'
        && !array_key_exists('guard_override', $blockedEvidence),
    'a guard-blocked deletion refusal must distinguish required and supplied flags without fabricating authorization or the suppressed apply choice'
);
$incompleteMethod = $applyReflection->getMethod('incomplete_override_refusal');
$incomplete = $incompleteMethod->invoke(null, [$blockedEvidence], 'operator-only guard detail');
$warnings->setValue($apply, ['FORCED past code_drift: reviewed operator-only context']);
$forcedEvidence->setValue($apply, []);
$incompleteReported = $preserve->invoke(null, $incomplete, $apply);
assert_conflict_view(
    $incompleteReported instanceof \Duo\CommandRefusalException
        && $incompleteReported->reasonCode === 'apply_conflict_override_incomplete'
        && ($incompleteReported->payload()['forced_overrides'][0]['status'] ?? null) === 'incomplete',
    'an unrelated authorized force warning must not relabel an incomplete conflict authorization as authorized or generic'
);
$blockedAuthorizedEvidence = $evidenceMethod->invoke(null, $blockedPlan['delete_conflict'][0], 'delete_conflict', [
    'with_deletes' => true,
    'force_theirs' => true,
    'force_delete_referenced' => true,
]);
assert_conflict_view(
    ($blockedAuthorizedEvidence['required_flags'] ?? null)
        === ['--with-deletes', '--force-theirs', '--force-delete-referenced']
        && ($blockedAuthorizedEvidence['supplied_flags'] ?? null)
            === ['--with-deletes', '--force-theirs', '--force-delete-referenced']
        && ($blockedAuthorizedEvidence['status'] ?? null) === 'authorized'
        && ($blockedAuthorizedEvidence['guard_override'] ?? null) === 'force_delete_referenced',
    'a guarded deletion becomes authorized evidence only after every explicit escape hatch is supplied'
);
$warnings->setValue($apply, [
    'FORCED deletion conflict 33333333-3333-7333-8333-333333333333 (target entity changed locally since the tombstone base)',
    'FORCED delete of guarded post 33333333-3333-7333-8333-333333333333',
]);
$forcedEvidence->setValue($apply, [$blockedAuthorizedEvidence]);
$blockedReported = $preserve->invoke(null, $laterFailure, $apply);
assert_conflict_view(
    $blockedReported instanceof \Duo\CommandRefusalException
        && ($blockedReported->payload()['forced_overrides'][0] ?? null) === $blockedAuthorizedEvidence,
    'a later guard-blocked deletion failure must preserve the truthful typed force evidence in JSON'
);
$blockedPayloadJson = json_encode($blockedReported->payload(), JSON_THROW_ON_ERROR);
assert_conflict_view(
    !str_contains($blockedPayloadJson, '33333333-3333-7333-8333-333333333333')
        && !str_contains($blockedPayloadJson, 'comments reference this post'),
    'guard-blocked deletion evidence must not expose raw entity or guard detail'
);

$warnings->setValue($apply, ['ordinary context']);
$forcedEvidence->setValue($apply, []);
assert_conflict_view(
    $preserve->invoke(null, $laterFailure, $apply) === $laterFailure,
    'without a force override the original failure object and type must pass through unchanged'
);

echo "ok: semantic plan conflicts expose base/repository/target intent and bounded safe choices\n";
