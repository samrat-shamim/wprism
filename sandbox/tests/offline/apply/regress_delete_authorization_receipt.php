<?php
/**
 * DUO-3502: what a FULL apply tells the operator when the plan holds a
 * tombstone and `--with-deletes` was not supplied.
 *
 * Measured on a pair: `duo promote prod` over a plan with one deletion applied
 * every create/update, advanced `applied_revision`, exited 0, and said nothing
 * about the deletion; the target still held the entity, and `duo status prod`
 * — whose exit code docs/guides/daily-workflow.md:260 calls the "safe to
 * promote?" answer — then exited 0 with that deletion pending forever. The
 * only trace was numeric, `"delete":1` beside `"deleted":0` inside the receipt
 * JSON (ApplyRequestCoordinator.php:1533-1534).
 *
 * The mechanism is a missing branch, not a broken one. Only the SCOPED path
 * refuses this state (ApplyPreparationCoordinator.php:171-179); a full apply
 * returns `executeDeletes=false` with a fully populated `$deleteWork`
 * (ApplyPlanner::rebuild_work(), ApplyPlanner.php:729-736),
 * AuthoredTransactionExecutor.php:222-253 skips its entire delete block, and
 * ApplyLedgerFinalizer.php:94-99 records `applied_revision` while its
 * deletion-receipt block at :64-69 never runs at all. Every one of those steps
 * is correct on its own; nothing appended to `$warnings`, which is the defect.
 *
 * Three product-path pins, each of which fails against the prior defect:
 *
 *   (1) the pure projection — `ApplyPlanner::unauthorized_deletes_warning()`
 *       did not exist, so the exact operator sentence is asserted byte for
 *       byte here rather than paraphrased at the call site;
 *   (2) the shipped `ApplyPreparationCoordinator::prepare()` driven over the
 *       real ApplyServices/ApplyPlanner/RebuildActionNegotiator collaborators
 *       — the branch that used to append nothing now appends exactly one
 *       warning, only for a non-scoped apply that planned deletions it was
 *       not authorized to run, and the scoped refusal beside it is unmoved;
 *   (3) the shipped `ApplyLedgerFinalizer::finalize()` over a real Ledger and
 *       the FakeWpdb interpreter — which is what makes "the revision is
 *       recorded as applied without them" in that sentence a measured fact
 *       and not a claim.
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

use Duo\ApplyLedgerFinalizer;
use Duo\ApplyPlanner;
use Duo\ApplyPreparationCoordinator;
use Duo\ApplyPreparationRequest;
use Duo\ApplyServiceCallbacks;
use Duo\ApplyServices;
use Duo\CommandRefusalException;
use Duo\CompiledRepository;
use Duo\Ledger;
use Duo\Policy;
use Duo\RebuildSelection;
use Duo\ScopedApplyWorkflow;
use DuoTest\FakeWpdb;
use DuoTest\FrozenPolicy;
use DuoTest\WpStore;

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

$oneRow = ApplyPlanner::unauthorized_deletes_warning([$tombstone]);
duo_check_same(
    'planned deletions NOT applied (1) — --with-deletes was not supplied; the target still holds them '
        . 'and this revision is recorded as applied without them. Rerun with --with-deletes to authorize:'
        . "\n  - state/deletions/9c8e6f21-4a35-4b0d-8a11-2f6d5c4b3a29.json",
    $oneRow,
    'the operator sentence names the count, the flag, the recorded revision, and the row'
);

duo_check_same(
    'planned deletions NOT applied (2) — --with-deletes was not supplied; the target still holds them '
        . 'and this revision is recorded as applied without them. Rerun with --with-deletes to authorize:'
        . "\n  - state/deletions/9c8e6f21-4a35-4b0d-8a11-2f6d5c4b3a29.json"
        . "\n  - state/deletions/1d5b7e40-88c2-4f6a-9e33-70a1c2b3d4e5.json",
    ApplyPlanner::unauthorized_deletes_warning([$tombstone, $menuTombstone]),
    'every planned deletion gets its own row, in the deletion order it was handed'
);

duo_check_same(
    'planned deletions NOT applied (1) — --with-deletes was not supplied; the target still holds them '
        . 'and this revision is recorded as applied without them. Rerun with --with-deletes to authorize:'
        . "\n  - options core_managed_flag",
    ApplyPlanner::unauthorized_deletes_warning([
        ['uuid' => 'core_managed_flag', 'type' => 'options'],
    ]),
    'a row projected without a repository path falls back to its type and uuid, never to nothing'
);

// -------------------------------------- (2) the shipped preparation branch

$store = WpStore::reset();
$wpdb = FakeWpdb::install();
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
 * @return array{prepared:?\Duo\PreparedApply,warnings:list<string>,refusal:?\Throwable}
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
        promotionOwner: 'duo-test-owner',
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
duo_check_same(null, $unauthorized['refusal'], 'a full apply with an unauthorized deletion still prepares — no new refusal');
duo_check_same(
    [ApplyPlanner::unauthorized_deletes_warning([$tombstone])],
    $unauthorized['warnings'],
    'the skipped deletion produces exactly one warning on the channel apply already renders'
);
duo_check_same(
    false,
    $unauthorized['prepared']?->executeDeletes,
    'the run is still not authorized to delete — the warning replaces silence, not the flag'
);
duo_check_same(
    [$tombstone],
    $unauthorized['prepared']?->deleteWork,
    'the tombstone the executor will skip is exactly the row the warning named'
);
duo_check_same(
    [$authoredRow],
    $unauthorized['prepared']?->work,
    'every authored entity is still applied — this is a warning about the half that is not'
);

$authorized = $prepare($plannedDelete, ['with_deletes' => true], false);
duo_check_same(null, $authorized['refusal'], '--with-deletes prepares the same plan without refusing');
duo_check_same([], $authorized['warnings'], '--with-deletes emits no unauthorized-deletion warning');
duo_check_same(
    true,
    $authorized['prepared']?->executeDeletes,
    '--with-deletes authorizes the executor delete block (AuthoredTransactionExecutor.php:222)'
);

$noDeletes = $emptyPlan();
$noDeletes['update'] = [$authoredRow];
$clean = $prepare($noDeletes, [], false);
duo_check_same(null, $clean['refusal'], 'a plan with no tombstone prepares unchanged');
duo_check_same(
    [],
    $clean['warnings'],
    'an apply with nothing to delete gains no warning — every prior warning count is unmoved'
);

// The scoped counterpart is the branch that already refused. It must stay a
// refusal, with its reason code and its operator sentence byte-identical, and
// it must not also collect the new warning.
$scoped = $prepare($plannedDelete, [], true);
duo_check(
    $scoped['refusal'] instanceof CommandRefusalException,
    'scoped apply still refuses an unauthorized tombstone as a typed command refusal'
);
duo_check_same(
    'apply_refused',
    $scoped['refusal'] instanceof CommandRefusalException ? $scoped['refusal']->reasonCode : null,
    'the scoped refusal keeps its reason code'
);
duo_check_same(
    'duo: scoped apply selected live tombstones but --with-deletes was not supplied; '
        . 'no scoped session or authored target mutation was created',
    $scoped['refusal']?->getMessage(),
    'the scoped operator sentence is byte-identical (AGENTS.md rule 8)'
);
duo_check_same([], $scoped['warnings'], 'the scoped path refuses instead of warning — the two branches are exclusive');

// -------------------------------------------- (3) what the ledger records

/**
 * `finalize()` is the atomic convergence boundary. Driving the shipped method
 * over a real Ledger and the FakeWpdb interpreter is the only way to state,
 * as a measured fact, the second half of the warning's own sentence: the
 * revision IS recorded, and the tombstone leaves no deletion receipt behind,
 * so nothing about a later run knows the deletion is still owed.
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
    $wpdb->seedTable('wp_duo_kv', []);
    $wpdb->setUniqueKey('wp_duo_kv', ['k']);
    $wpdb->seedTable('wp_duo_state', []);
    $wpdb->setUniqueKey('wp_duo_state', ['uuid']);
    $wpdb->seedTable('wp_duo_map', []);
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
foreach ($wpdb->rows('wp_duo_kv') as $row) {
    $kv[(string) $row['k']] = (string) $row['v'];
}
duo_check_same(
    $revision,
    $kv['applied_revision'] ?? null,
    'the revision is recorded as applied even though the planned deletion never ran — the fact the warning states'
);
$stateUuids = array_column($wpdb->rows('wp_duo_state'), 'uuid');
duo_check_same(
    [$authoredRow['uuid']],
    $stateUuids,
    'the un-executed tombstone leaves no deletion receipt, so it stays pending on every later plan'
);

$finalize(true);
$authorizedState = [];
foreach ($wpdb->rows('wp_duo_state') as $row) {
    $authorizedState[(string) $row['uuid']] = (string) $row['entity_type'];
}
duo_check_same(
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
$wpdb->seedTable('wp_duo_kv', [])->setUniqueKey('wp_duo_kv', ['k']);
$wpdb->seedTable('wp_duo_state', [])->setUniqueKey('wp_duo_state', ['uuid']);
$wpdb->seedTable('wp_duo_map', $mapRows)
    ->setUniqueKey('wp_duo_map', ['uuid', 'id_kind'])
    ->setUniqueKey('wp_duo_map', ['id_kind', 'local_id']);
foreach (['wp_duo_kv', 'wp_duo_state', 'wp_duo_map'] as $table) {
    $wpdb->setTableEngine($table, 'InnoDB');
}
$mapIdentityHashes = [hash('sha256', $mapUuid)];
$mapRoots = \Duo\ScopedApply::ledger_map_roots($mapIdentityHashes, $mapRows);
$scopeArtifactHash = hash('sha256', 'finalizer-artifact');
$scopedAuthority = \Duo\ScopedApplySession::make_authority(
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
        'ledger_roots_hash' => hash('sha256', \Duo\Canon::encode($mapRoots)),
    ],
    [
        'precondition_hash' => hash('sha256', 'finalizer-plan'),
        'guard_witnesses_hash' => hash('sha256', 'finalizer-guards'),
    ],
    [
        'work_hash' => \Duo\ScopedApplySession::hash_value([]),
        'work_items' => [],
        'deletions_hash' => \Duo\ScopedApplySession::hash_value([]),
        'deletion_items' => [],
        'action_declarations_hash' => \Duo\ScopedApplySession::hash_value([]),
        'action_items' => [],
        'capabilities_hash' => \Duo\ScopedApplySession::hash_value([]),
        'effects_hash' => \Duo\ScopedApplySession::hash_value([]),
        'effect_items' => [],
        'ledger_map_identity_hashes' => $mapIdentityHashes,
        'ledger_map_identity_set_hash' => \Duo\ScopedApplySession::hash_value($mapIdentityHashes),
    ],
    hash('sha256', 'finalizer-code')
);
$scopedSession = \Duo\ScopedApplySession::begin(
    new \Duo\LedgerScopedApplySessionStorage(),
    $scopedAuthority
);
$scopedSession->transition(\Duo\ScopedApplySession::PHASE_AUTHORING);
$authorIntent = \Duo\ScopedApplyCoordinator::intent(
    $scopedSession,
    1,
    'duo-scoped-authored-transaction/v2',
    'finalizer-author',
    hash('sha256', 'finalizer-author-input'),
    hash('sha256', 'finalizer-author-effect'),
    (string) $scopedAuthority['target']['selected_before_hash']
);
$scopedSession->append_intent($authorIntent);
$authorMapHash = \Duo\ScopedApplyCoordinator::authored_ledger_map_hash($mapRoots);
$scopedSession->commit_authored_receipt(
    \Duo\ScopedApplyCoordinator::receipt($authorIntent, $authorMapHash)
);
$scopedSession->transition(\Duo\ScopedApplySession::PHASE_EFFECTS_PENDING);
$scopedSession->transition(\Duo\ScopedApplySession::PHASE_VERIFYING);
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
    if (!$mapSubstituted && str_contains($sql, 'INSERT INTO wp_duo_state')) {
        $mapSubstituted = true;
        $changed = $mapRows;
        $changed[0]['local_id'] = 72;
        $db->seedTable('wp_duo_map', $changed);
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
duo_check(
    $mapSubstituted
        && $mapSubstitutionRefused
        && $wpdb->rows('wp_duo_map') === $mapRows
        && $wpdb->rows('wp_duo_state') === []
        && $scopedSession->phase() === \Duo\ScopedApplySession::PHASE_VERIFYING,
    'post-author selected-map substitution is refused inside finalization and map/state/session all roll back'
);

$wpdb->setTableEngine('wp_duo_map', 'MyISAM');
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
duo_check(
    $engineDriftRefused
        && $wpdb->rows('wp_duo_map') === $mapRows
        && $wpdb->rows('wp_duo_state') === []
        && $scopedSession->phase() === \Duo\ScopedApplySession::PHASE_VERIFYING,
    'scoped finalization refuses a storage-engine substitution after metadata-lock acquisition without mutation'
);
$wpdb->setTableEngine('wp_duo_map', 'InnoDB');
putenv('DUO_TEST_MODE=1');
putenv('DUO_TEST_FAIL_DB_CONTEXT=ledger transaction commit');
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
    $scopedCommitRefused = $failure instanceof \Duo\DatabaseMutationException
        && str_contains($failure->getMessage(), 'ledger transaction commit');
} finally {
    putenv('DUO_TEST_FAIL_DB_CONTEXT');
    putenv('DUO_TEST_MODE');
}
$durableVerifyingSession = \Duo\ScopedApplySession::open(
    new \Duo\LedgerScopedApplySessionStorage()
);
duo_check(
    $scopedCommitRefused
        && $durableVerifyingSession instanceof \Duo\ScopedApplySession
        && $durableVerifyingSession->phase() === \Duo\ScopedApplySession::PHASE_VERIFYING
        && $scopedSession->phase() === \Duo\ScopedApplySession::PHASE_VERIFYING
        && $wpdb->rows('wp_duo_map') === $mapRows
        && $wpdb->rows('wp_duo_state') === [],
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
duo_check(
    $scopedSession->phase() === \Duo\ScopedApplySession::PHASE_COMPLETE
        && $wpdb->rows('wp_duo_map') === $mapRows,
    'same-process retry after engine/map/commit faults terminalizes the exact verifying session once'
);

$wpdb->resetLog();
$terminalCommitApplied = false;
$terminalCommitHook = null;
$terminalCommitHook = static function (
    string $sql,
    string $method,
    FakeWpdb $db
) use (&$terminalCommitApplied): ?string {
    if ($method !== 'query' || $sql !== 'COMMIT' || $terminalCommitApplied) {
        return null;
    }
    $terminalCommitApplied = true;
    $db->onQuery(null);
    $db->query('COMMIT');
    return 'simulated COMMIT client failure after server application';
};
$wpdb->onQuery($terminalCommitHook);
try {
    $finalize(false);
    $terminalCommitRefused = false;
} catch (\Throwable $failure) {
    $terminalCommitRefused = $failure instanceof \Duo\DatabaseTransactionOutcomeException;
}
$terminalKv = [];
foreach ($wpdb->rows('wp_duo_kv') as $row) {
    $terminalKv[(string) $row['k']] = (string) $row['v'];
}
duo_check(
    $terminalCommitRefused
        && $terminalCommitApplied
        && ($terminalKv['applied_revision'] ?? null) === $revision
        && !in_array('ROLLBACK', $wpdb->queries(), true),
    'an inactive ambiguous ledger COMMIT preserves its physical postimage and never compensates through autocommit'
);
// Inspecting the exact product postimage above is the explicit recovery
// boundary after which this same-process fixture may begin another transaction.
\Duo\Db::forget_transaction_tracking();

duo_check_summary('delete authorization receipt');
