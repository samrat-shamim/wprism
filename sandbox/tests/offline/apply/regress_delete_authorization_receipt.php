<?php
/**
 * A full apply with pending tombstones and no --with-deletes must refuse
 * before any authored mutation. Success therefore means the revision's
 * deletion intent converged rather than "everything except deletions".
 *
 * Offline: no docker, no WordPress, no target, no pair.
 */
declare(strict_types=1);

// From offline/apply/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';
require_once __DIR__ . '/../../lib/wp_stubs.php';
require_once __DIR__ . '/../../lib/FakeWpdb.php';
require_once __DIR__ . '/../../lib/frozen_policy.php';

require_once __DIR__ . '/../../../../agent/src/Policy/Policy.php';
require_once __DIR__ . '/../../../../agent/src/Repository/CompiledArtifact.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyPlanner.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyServices.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyPreparationCoordinator.php';
require_once __DIR__ . '/../../../../agent/src/Apply/ApplyLedgerFinalizer.php';
require_once __DIR__ . '/../../../../agent/src/Rebuild/RebuildSelection.php';
require_once __DIR__ . '/../../../../agent/src/Scope/ScopedApplyWorkflow.php';

use WPrism\ApplyLedgerFinalizer;
use WPrism\ApplyPlanner;
use WPrism\ApplyPreparationCoordinator;
use WPrism\ApplyPreparationRequest;
use WPrism\ApplyServiceCallbacks;
use WPrism\ApplyServices;
use WPrism\CommandRefusalException;
use WPrism\CompiledRepository;
use WPrism\Ledger;
use WPrism\Policy;
use WPrism\RebuildSelection;
use WPrism\ScopedApplyWorkflow;
use WPrismTest\FakeWpdb;
use WPrismTest\FrozenPolicy;
use WPrismTest\WpStore;

// --------------------------------------------------------------- fixtures

/** The tombstone row shape rebuild_work() hands on, as build_plan() emits it. */
$tombstone = [
    'uuid' => '9c8e6f21-4a35-4b0d-8a11-2f6d5c4b3a29',
    'type' => 'post',
    'path' => 'state/deletions/9c8e6f21-4a35-4b0d-8a11-2f6d5c4b3a29.json',
    'expected_hash' => str_repeat('a', 64),
    'receipt_hash' => str_repeat('b', 64),
];
/** A second tombstone, so the rendered row list is proven to be a list. */
$menuTombstone = [
    'uuid' => '1d5b7e40-88c2-4f6a-9e33-70a1c2b3d4e5',
    'type' => 'menu',
    'path' => 'state/deletions/1d5b7e40-88c2-4f6a-9e33-70a1c2b3d4e5.json',
    'receipt_hash' => str_repeat('c', 64),
];
/** The authored entity every case below applies alongside the deletion. */
$authoredUuid = '3f7a1c92-5d64-4e8b-b0c7-9a2e4f6d8b31';
$authoredRow = [
    'uuid' => $authoredUuid,
    'type' => 'post',
    'path' => 'state/posts/page/3f7a1c92--pricing.md',
];
$tree = [
    $authoredUuid => [
        'type' => 'post',
        'hash' => str_repeat('d', 64),
        'data' => ['uuid' => $authoredUuid, 'type' => 'page', 'title' => 'Pricing'],
    ],
];

/** @return array<string,list<array<string,mixed>>> every bucket prepare() dereferences */
$emptyPlan = static fn(): array => [
    'create' => [], 'update' => [], 'adopt' => [], 'unchanged' => [], 'drift' => [],
    'conflict' => [], 'collision' => [], 'delete' => [], 'delete_conflict' => [],
    'deleted' => [], 'code_mismatch' => [], 'code_drift' => [], 'missing_user' => [],
    'incomplete_apply' => [], 'regen_pending' => [], 'regen_context' => [],
    'skipped_user_meta' => [], 'uploads_inventory' => [], 'effects_inventory' => [],
];

// ------------------------------------------------- (1) the pure projection

$oneRow = ApplyPlanner::unauthorized_deletes_refusal([$tombstone]);
wprism_check_same(
    'wprism: planned deletions require --with-deletes (1); no target mutation attempted. '
        . 'Review and rerun with --with-deletes to authorize:'
        . "\n  - state/deletions/9c8e6f21-4a35-4b0d-8a11-2f6d5c4b3a29.json",
    $oneRow,
    'the operator refusal names the count, flag, no-mutation result, and row'
);

wprism_check_same(
    'wprism: planned deletions require --with-deletes (2); no target mutation attempted. '
        . 'Review and rerun with --with-deletes to authorize:'
        . "\n  - state/deletions/9c8e6f21-4a35-4b0d-8a11-2f6d5c4b3a29.json"
        . "\n  - state/deletions/1d5b7e40-88c2-4f6a-9e33-70a1c2b3d4e5.json",
    ApplyPlanner::unauthorized_deletes_refusal([$tombstone, $menuTombstone]),
    'every planned deletion gets its own row, in the deletion order it was handed'
);

wprism_check_same(
    'wprism: planned deletions require --with-deletes (1); no target mutation attempted. '
        . 'Review and rerun with --with-deletes to authorize:'
        . "\n  - options core_managed_flag",
    ApplyPlanner::unauthorized_deletes_refusal([
        ['uuid' => 'core_managed_flag', 'type' => 'options'],
    ]),
    'a row projected without a repository path falls back to its type and uuid, never to nothing'
);

// -------------------------------------- (2) the shipped preparation branch

$store = WpStore::reset();
$wpdb = FakeWpdb::install()->enableInformationSchema();
$wpdb
    ->setColumns('wprism_map', [
        'uuid' => 'char(36)',
        'entity_type' => 'varchar(64)',
        'id_kind' => 'varchar(32)',
        'local_id' => 'bigint unsigned',
    ])
    ->setUniqueKey('wprism_map', ['uuid', 'id_kind'])
    ->setUniqueKey('wprism_map', ['id_kind', 'local_id'])
    ->setTableEngine('wprism_map', 'InnoDB')
    ->setColumns('wprism_state', [
        'uuid' => 'char(36)',
        'entity_type' => 'varchar(64)',
        'content_hash' => 'char(64)',
    ])
    ->setUniqueKey('wprism_state', ['uuid'])
    ->setTableEngine('wprism_state', 'InnoDB')
    ->setColumns('wprism_kv', ['k' => 'varchar(191)', 'v' => 'longtext'])
    ->setIndexes('wprism_kv', [[
        'Key_name' => 'PRIMARY', 'Non_unique' => 0, 'Seq_in_index' => 1,
        'Column_name' => 'k', 'Sub_part' => null, 'Index_type' => 'BTREE', 'Visible' => 'YES', 'Ignored' => 'NO',
    ]])
    ->setUniqueKey('wprism_kv', ['k'])
    ->setTableEngine('wprism_kv', 'InnoDB');
$policy = FrozenPolicy::policy([], FrozenPolicy::site([]));
$compiled = CompiledRepository::create(['tree' => []]);

/**
 * Every runtime boundary prepare() can reach on the non-scoped path is a
 * no-op here. That is deliberate rather than convenient: the branch under
 * test sits before the promotion-lock renewal, before the plan recheck, and
 * before any target mutation, so a boundary that DID something would only be
 * asserting the harness. `resolve_login('')` returns null without touching
 * $wpdb (PostMaterializer.php:233-237), which is why no user fixture exists.
 */
$noop = static function (): void {};
$services = new ApplyServices(
    $policy,
    $compiled,
    new ApplyServiceCallbacks(
        taxonomyOwnership: static fn(): array => [],
        renewPromotionLock: static function (string $phase): void {},
        renewRegenerationLease: $noop,
        renewProviderLease: $noop,
        lockDeleteGuards: static function (array $a, array $b, array $c, array $d, array $e): void {},
        deletionDatabaseProfile: static fn(array $work): array => [
            'read_tables' => [], 'table_presence_reads' => [],
        ],
        recheckDeleteGuard: static function (
            array $row,
            array $uuids,
            array $deletions,
            bool $forced,
            array $tree,
            array $repairs,
            bool $mutating
        ): void {},
        selectionDeclaresChannelFor: static fn(string $channel, string $surface): bool => false,
        selectionDeclaresEntityBatchFor: static fn(string $surface): bool => false,
        selectionTriggersProviderActionFor: static fn(string $surface): bool => false,
        pinnedProviderActionOwns: static fn(string $surface): bool => false,
        upsertMeta: static function (
            string $table,
            string $column,
            int $id,
            string $key,
            ?string $value,
            ?string $previous,
            string $context
        ): void {},
    ),
    '/fixture/repo'
);

/**
 * Drive the shipped coordinator. `$warnings` and `$forcedOverrideEvidence`
 * are the same by-reference channels ApplyRequestCoordinator.php:1092-1093
 * passes in, and the fresh-plan closure returns the same plan so the
 * precondition recheck at ApplyPreparationCoordinator.php:275-282 compares
 * equal — this suite is about the deletion branch, not about that gate.
 *
 * @param array<string,mixed> $plan
 * @param array<string,mixed> $opts
 * @return array{prepared:?\WPrism\PreparedApply,warnings:list<string>,refusal:?\Throwable}
 */
$prepare = static function (array $plan, array $opts, bool $scoped) use ($policy, $compiled, $services, $tree): array {
    $warnings = [];
    $evidence = [];
    $coordinator = new ApplyPreparationCoordinator(
        '/fixture/repo',
        $policy,
        $services,
        new RebuildSelection($policy),
        new ScopedApplyWorkflow(),
        static fn(array $o, CompiledRepository $c, bool $s, bool $full): array => $plan,
        static function (string $phase): void {}
    );
    $request = new ApplyPreparationRequest(
        options: $opts,
        compiled: $compiled,
        plan: $plan,
        tree: $tree,
        scoped: $scoped,
        scopedPromotion: false,
        recoveringScoped: false,
        retryingIncompleteApply: false,
        promotionOwner: 'wprism-test-owner',
        promotionArtifact: str_repeat('e', 64)
    );
    try {
        return ['prepared' => $coordinator->prepare($request, $warnings, $evidence), 'warnings' => $warnings, 'refusal' => null];
    } catch (\Throwable $refusal) {
        return ['prepared' => null, 'warnings' => $warnings, 'refusal' => $refusal];
    }
};

$plannedDelete = $emptyPlan();
$plannedDelete['update'] = [$authoredRow];
$plannedDelete['delete'] = [$tombstone];

$unauthorized = $prepare($plannedDelete, [], false);
wprism_check($unauthorized['refusal'] instanceof CommandRefusalException,
    'a full apply with an unauthorized deletion refuses before returning prepared work');
wprism_check_same('apply_refused', $unauthorized['refusal']?->reasonCode,
    'the full deletion refusal uses the typed apply refusal envelope');
wprism_check(str_contains((string) $unauthorized['refusal']?->getMessage(), 'no target mutation attempted')
    && str_contains((string) $unauthorized['refusal']?->getMessage(), $tombstone['path']),
    'the operator refusal identifies the pending tombstone and the no-mutation outcome');
wprism_check_same([], $unauthorized['warnings'], 'the full path refuses instead of reporting a successful partial warning');

$authorized = $prepare($plannedDelete, ['with_deletes' => true], false);
wprism_check_same(null, $authorized['refusal'], '--with-deletes prepares the same plan without refusing');
wprism_check_same([], $authorized['warnings'], '--with-deletes emits no unauthorized-deletion refusal warning');
wprism_check_same(
    true,
    $authorized['prepared']?->executeDeletes,
    '--with-deletes authorizes the executor delete block (AuthoredTransactionExecutor.php:222)'
);

$noDeletes = $emptyPlan();
$noDeletes['update'] = [$authoredRow];
$clean = $prepare($noDeletes, [], false);
wprism_check_same(null, $clean['refusal'], 'a plan with no tombstone prepares unchanged');
wprism_check_same(
    [],
    $clean['warnings'],
    'an apply with nothing to delete gains no warning — every prior warning count is unmoved'
);

$drifted = $emptyPlan();
$drifted['update'] = [$authoredRow];
$drifted['drift'] = [[
    'uuid' => '8f14e45f-ceea-467a-9a3e-1b2c3d4e5f60',
    'type' => 'post',
    'path' => 'state/posts/page/8f14e45f--team.md',
]];
$stale = $prepare($drifted, [], false);
wprism_check($stale['refusal'] instanceof CommandRefusalException
    && $stale['refusal']->reasonCode === 'apply_refused'
    && str_contains($stale['refusal']->getMessage(), 'no target mutation attempted')
    && str_contains($stale['refusal']->getMessage(), 'state/posts/page/8f14e45f--team.md'),
    'ordinary drift refuses in preparation before the unrelated update can create a partial apply');
wprism_check_same(null, $stale['prepared'], 'stale-plan refusal returns no executable workset');

// Native Qi editing reached an ordinary conflict here, but JSON callers got
// apply_failed plus recovery guidance. Exercise the real precondition owner;
// its private row details must not become public merely to classify the gate.
$privatePath = 'state/posts/page/operator@example.test.md';
foreach ([
    'conflict' => [
        [['path' => $privatePath]], '--force-theirs',
        "wprism: conflicts (env and repo both changed since last sync) — capture first or --force-theirs:\n  - $privatePath",
    ],
    'collision' => [
        [['type' => 'post', 'path' => $privatePath, 'env_id' => 44]], '--adopt-by-slug',
        "wprism: slug collisions need explicit resolution (--adopt-by-slug=posts,terms,menus,tables adopts unmanaged rows):\n  - post $privatePath collides with env id 44 (same slug, different/no uuid)",
    ],
    'delete_conflict' => [
        [['path' => $privatePath, 'reason' => 'changed native state']], '--with-deletes',
        "wprism: deletion conflicts (target differs from the tombstone's expected base) — capture/reconcile first or --force-theirs:\n  - $privatePath: changed native state",
    ],
] as $bucket => [$rows, $flag, $operatorMessage]) {
    $plan = $emptyPlan(); $plan[$bucket] = $rows;
    $queries = $wpdb->queries();
    $result = $prepare($plan, [], false);
    $refusal = $result['refusal'];
    $payload = $refusal instanceof CommandRefusalException ? $refusal->payload() : [];
    wprism_check_same('apply_refused', $payload['error'] ?? null, "$bucket has reviewed public precondition authority instead of an unclassified failure");
    wprism_check(str_contains($payload['remediation'] ?? '', $flag) && !str_contains($payload['remediation'] ?? '', 'recovery'),
        "$bucket names the existing explicit resolution route");
    wprism_check($payload !== [] && !str_contains(json_encode($payload), 'operator@example.test')
        && !isset($payload['details_redacted']), "$bucket publishes value-free guidance without leaking the private row or redacting its useful message");
    wprism_check_same($operatorMessage, $refusal?->getMessage(), "$bucket retains exact private operator wording and row details");
    wprism_check_same([null, []], [$result['prepared'], $result['warnings']], "$bucket returns neither executable work nor a successful warning");
    wprism_check_same($queries, $wpdb->queries(), "$bucket refuses before database access");
}

// The scoped counterpart is the branch that already refused. It must stay a
// refusal, with its reason code and its operator sentence byte-identical, and
// it must not also collect the new warning.
$scoped = $prepare($plannedDelete, [], true);
wprism_check(
    $scoped['refusal'] instanceof CommandRefusalException,
    'scoped apply still refuses an unauthorized tombstone as a typed command refusal'
);
wprism_check_same(
    'apply_refused',
    $scoped['refusal'] instanceof CommandRefusalException ? $scoped['refusal']->reasonCode : null,
    'the scoped refusal keeps its reason code'
);
wprism_check_same(
    'wprism: scoped apply selected live tombstones but --with-deletes was not supplied; '
        . 'no scoped session or authored target mutation was created',
    $scoped['refusal']?->getMessage(),
    'the scoped operator sentence is byte-identical (AGENTS.md rule 8)'
);
wprism_check_same([], $scoped['warnings'], 'the scoped path refuses instead of warning — the two branches are exclusive');

// -------------------- finalizer invariant (the refused path cannot reach it)

/**
 * `finalize()` is the atomic convergence boundary. Driving the shipped method
 * over a real Ledger and the FakeWpdb interpreter is the only way to state,
 * as a measured fact, the second half of the warning's own sentence: the
 * Directly driving executeDeletes=false documents why preparation must block:
 * finalization would otherwise advance the revision without a delete receipt.
 */
$revision = str_repeat('f', 64);
$finalize = static function (bool $executeDeletes) use (
    $wpdb,
    $compiled,
    $emptyPlan,
    $tree,
    $authoredRow,
    $tombstone,
    $revision
): FakeWpdb {
    $wpdb->seedTable('wp_wprism_kv', []);
    $wpdb->setUniqueKey('wp_wprism_kv', ['k']);
    $wpdb->seedTable('wp_wprism_state', []);
    $wpdb->setUniqueKey('wp_wprism_state', ['uuid']);
    $wpdb->seedTable('wp_wprism_map', []);
    $plan = $emptyPlan();
    $plan['update'] = [$authoredRow];
    $plan['delete'] = [$tombstone];
    (new ApplyLedgerFinalizer(static function (): void {}))->finalize(
        $compiled,
        $plan,
        $tree,
        [$authoredRow],
        [$tombstone],
        $executeDeletes,
        false,
        null,
        null,
        [],
        $revision
    );
    return $wpdb;
};

$finalize(false);
$kv = [];
foreach ($wpdb->rows('wp_wprism_kv') as $row) {
    $kv[(string) $row['k']] = (string) $row['v'];
}
wprism_check_same(
    $revision,
    $kv['applied_revision'] ?? null,
    'the low-level finalizer would record the revision without a deletion receipt, so preparation must refuse it'
);
$stateUuids = array_column($wpdb->rows('wp_wprism_state'), 'uuid');
wprism_check_same(
    [$authoredRow['uuid']],
    $stateUuids,
    'the un-executed tombstone leaves no deletion receipt, so it stays pending on every later plan'
);

$finalize(true);
$authorizedState = [];
foreach ($wpdb->rows('wp_wprism_state') as $row) {
    $authorizedState[(string) $row['uuid']] = (string) $row['entity_type'];
}
wprism_check_same(
    'deletion',
    $authorizedState[$tombstone['uuid']] ?? null,
    '--with-deletes records the deletion receipt that clears the tombstone from later plans'
);

// A scoped terminal receipt is stronger than the ordinary revision row: the
// selected identity map was already sealed with authored state at ordinal 1.
// Finalization must lock that exact generation, reject any later callback/
// concurrent substitution, and leave both map and session retryable.
$mapUuid = '72c934b8-0bad-4da7-b298-196f8936d021';
$mapRows = [[
    'uuid' => $mapUuid,
    'entity_type' => 'post',
    'id_kind' => 'post',
    'local_id' => 71,
]];
$wpdb->seedTable('wp_wprism_kv', [])->setUniqueKey('wp_wprism_kv', ['k']);
$wpdb->seedTable('wp_wprism_state', [])->setUniqueKey('wp_wprism_state', ['uuid']);
$wpdb->seedTable('wp_wprism_map', $mapRows)
    ->setUniqueKey('wp_wprism_map', ['uuid', 'id_kind'])
    ->setUniqueKey('wp_wprism_map', ['id_kind', 'local_id']);
foreach (['wp_wprism_kv', 'wp_wprism_state', 'wp_wprism_map'] as $table) {
    $wpdb->setTableEngine($table, 'InnoDB');
}
$mapIdentityHashes = [hash('sha256', $mapUuid)];
$mapRoots = \WPrism\ScopedApply::ledger_map_roots($mapIdentityHashes, $mapRows);
$scopeArtifactHash = hash('sha256', 'finalizer-artifact');
$scopedAuthority = \WPrism\ScopedApplySession::make_authority(
    hash('sha256', 'finalizer-scope'),
    [
        'artifact_hash' => $scopeArtifactHash,
        'state_revision_hash' => hash('sha256', 'finalizer-revision'),
        'manifest_hash' => hash('sha256', 'finalizer-manifest'),
    ],
    [
        'owner' => 'finalizer-owner',
        'artifact_hash' => $scopeArtifactHash,
        'session_id' => 'finalizer-session',
    ],
    [
        'selected_before_hash' => hash('sha256', 'finalizer-selected-before'),
        'selected_before_ledger_map_hash' => (string) $mapRoots['selected_ledger_map_root'],
        'protected_ledger_map_hash' => (string) $mapRoots['protected_ledger_map_root'],
        'protected_out_of_scope_hash' => hash('sha256', 'finalizer-protected'),
        'ledger_roots_hash' => hash('sha256', \WPrism\Canon::encode($mapRoots)),
    ],
    [
        'precondition_hash' => hash('sha256', 'finalizer-plan'),
        'guard_witnesses_hash' => hash('sha256', 'finalizer-guards'),
    ],
    [
        'work_hash' => \WPrism\ScopedApplySession::hash_value([]),
        'work_items' => [],
        'deletions_hash' => \WPrism\ScopedApplySession::hash_value([]),
        'deletion_items' => [],
        'action_declarations_hash' => \WPrism\ScopedApplySession::hash_value([]),
        'action_items' => [],
        'capabilities_hash' => \WPrism\ScopedApplySession::hash_value([]),
        'effects_hash' => \WPrism\ScopedApplySession::hash_value([]),
        'effect_items' => [],
        'ledger_map_identity_hashes' => $mapIdentityHashes,
        'ledger_map_identity_set_hash' => \WPrism\ScopedApplySession::hash_value($mapIdentityHashes),
    ],
    hash('sha256', 'finalizer-code')
);
$scopedSession = \WPrism\ScopedApplySession::begin(
    new \WPrism\LedgerScopedApplySessionStorage(),
    $scopedAuthority
);
$scopedSession->transition(\WPrism\ScopedApplySession::PHASE_AUTHORING);
$authorIntent = \WPrism\ScopedApplyCoordinator::intent(
    $scopedSession,
    1,
    'wprism-scoped-authored-transaction/v2',
    'finalizer-author',
    hash('sha256', 'finalizer-author-input'),
    hash('sha256', 'finalizer-author-effect'),
    (string) $scopedAuthority['target']['selected_before_hash']
);
$scopedSession->append_intent($authorIntent);
$authorMapHash = \WPrism\ScopedApplyCoordinator::authored_ledger_map_hash($mapRoots);
$scopedSession->commit_authored_receipt(
    \WPrism\ScopedApplyCoordinator::receipt($authorIntent, $authorMapHash)
);
$scopedSession->transition(\WPrism\ScopedApplySession::PHASE_EFFECTS_PENDING);
$scopedSession->transition(\WPrism\ScopedApplySession::PHASE_VERIFYING);
$scopedVerification = [
    'authored_ledger_map_hash' => $authorMapHash,
    'receipt_hash' => hash('sha256', 'finalizer-convergence'),
];
$scopedPlan = $emptyPlan();
$scopedPlan['update'] = [$authoredRow];

$mapSubstituted = false;
$wpdb->onQuery(static function (string $sql, string $method, FakeWpdb $db) use (
    &$mapSubstituted,
    $mapRows
): ?string {
    if (!$mapSubstituted && str_contains($sql, 'INSERT INTO wp_wprism_state')) {
        $mapSubstituted = true;
        $changed = $mapRows;
        $changed[0]['local_id'] = 72;
        $db->seedTable('wp_wprism_map', $changed);
    }
    return null;
});
try {
    (new ApplyLedgerFinalizer(static function (): void {}))->finalize(
        $compiled,
        $scopedPlan,
        $tree,
        [$authoredRow],
        [],
        false,
        true,
        [],
        $scopedSession,
        $scopedVerification,
        $revision
    );
    $mapSubstitutionRefused = false;
} catch (\Throwable $failure) {
    $mapSubstitutionRefused = str_contains(
        $failure->getMessage(),
        'outside authorized tombstone cleanup'
    );
}
$wpdb->onQuery(null);
wprism_check(
    $mapSubstituted
        && $mapSubstitutionRefused
        && $wpdb->rows('wp_wprism_map') === $mapRows
        && $wpdb->rows('wp_wprism_state') === []
        && $scopedSession->phase() === \WPrism\ScopedApplySession::PHASE_VERIFYING,
    'post-author selected-map substitution is refused inside finalization and map/state/session all roll back'
);

$wpdb->setTableEngine('wp_wprism_map', 'MyISAM');
try {
    (new ApplyLedgerFinalizer(static function (): void {}))->finalize(
        $compiled,
        $scopedPlan,
        $tree,
        [$authoredRow],
        [],
        false,
        true,
        [],
        $scopedSession,
        $scopedVerification,
        $revision
    );
    $engineDriftRefused = false;
} catch (\Throwable $failure) {
    $engineDriftRefused = str_contains($failure->getMessage(), 'InnoDB required');
}
wprism_check(
    $engineDriftRefused
        && $wpdb->rows('wp_wprism_map') === $mapRows
        && $wpdb->rows('wp_wprism_state') === []
        && $scopedSession->phase() === \WPrism\ScopedApplySession::PHASE_VERIFYING,
    'scoped finalization refuses a storage-engine substitution after metadata-lock acquisition without mutation'
);
$wpdb->setTableEngine('wp_wprism_map', 'InnoDB');
putenv('WPRISM_TEST_MODE=1');
putenv('WPRISM_TEST_FAIL_DB_CONTEXT=ledger transaction commit');
try {
    (new ApplyLedgerFinalizer(static function (): void {}))->finalize(
        $compiled,
        $scopedPlan,
        $tree,
        [$authoredRow],
        [],
        false,
        true,
        [],
        $scopedSession,
        $scopedVerification,
        $revision
    );
    $scopedCommitRefused = false;
} catch (\Throwable $failure) {
    $scopedCommitRefused = $failure instanceof \WPrism\DatabaseMutationException
        && str_contains($failure->getMessage(), 'ledger transaction commit');
} finally {
    putenv('WPRISM_TEST_FAIL_DB_CONTEXT');
    putenv('WPRISM_TEST_MODE');
}
$durableVerifyingSession = \WPrism\ScopedApplySession::open(
    new \WPrism\LedgerScopedApplySessionStorage()
);
wprism_check(
    $scopedCommitRefused
        && $durableVerifyingSession instanceof \WPrism\ScopedApplySession
        && $durableVerifyingSession->phase() === \WPrism\ScopedApplySession::PHASE_VERIFYING
        && $scopedSession->phase() === \WPrism\ScopedApplySession::PHASE_VERIFYING
        && $wpdb->rows('wp_wprism_map') === $mapRows
        && $wpdb->rows('wp_wprism_state') === [],
    'a confirmed scoped finalizer COMMIT rollback reloads durable and in-memory sessions to verifying'
);
(new ApplyLedgerFinalizer(static function (): void {}))->finalize(
    $compiled,
    $scopedPlan,
    $tree,
    [$authoredRow],
    [],
    false,
    true,
    [],
    $scopedSession,
    $scopedVerification,
    $revision
);
wprism_check(
    $scopedSession->phase() === \WPrism\ScopedApplySession::PHASE_COMPLETE
        && $wpdb->rows('wp_wprism_map') === $mapRows,
    'same-process retry after engine/map/commit faults terminalizes the exact verifying session once'
);

$wpdb->resetLog()->injectTransactionOutcome('COMMIT', 'after_false');
try {
    $finalize(false);
    $terminalCommitRefused = false;
} catch (\Throwable $failure) {
    $terminalCommitRefused = $failure instanceof \WPrism\DatabaseTransactionOutcomeException;
}
$terminalKv = [];
foreach ($wpdb->rows('wp_wprism_kv') as $row) {
    $terminalKv[(string) $row['k']] = (string) $row['v'];
}
wprism_check(
    $terminalCommitRefused
        && ($terminalKv['applied_revision'] ?? null) === $revision
        && !in_array('ROLLBACK', $wpdb->queries(), true),
    'an inactive ambiguous ledger COMMIT preserves its physical postimage and never compensates through autocommit'
);
// Inspecting the exact product postimage above is the explicit recovery
// boundary after which this same-process fixture may begin another transaction.
\WPrism\Db::forget_transaction_tracking();

wprism_check_summary('delete authorization receipt');
