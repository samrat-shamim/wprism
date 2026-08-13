<?php
/**
 * Offline regression for ApplyPlanner (DUO-3347: the pure
 * conflict/display-projection half of plan production extracted out of
 * Apply.php). Existing suites (regress_conflict_view.php,
 * regress_plan_title_render.php, regress_lifecycle_state_handoff.php,
 * regress_plan_category_summary.php) already exercise these methods'
 * behavior in depth, most via ReflectionMethod against Apply's own thin
 * facades — this file is deliberately narrower: it proves the extracted
 * methods are directly callable as ApplyPlanner's own public API. One small
 * Apply-integrated regression additionally proves a declared literal `tt`
 * table kind stays in raw ledger keyspace rather than being treated as a
 * Snapshot reference token.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../agent/src/Uuid.php';
require_once __DIR__ . '/../../agent/src/ApplyPlanner.php';
require_once __DIR__ . '/../../agent/src/Ledger.php';
require_once __DIR__ . '/../../agent/src/Apply.php';

use Duo\ApplyPlanner;
use Duo\Canon;
use Duo\OptionState;
use Duo\Policy;
use Duo\Snapshot;
use Duo\Uuid;

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

// ---------------------------------------------------------- deletion comparison

$deletionExpectedHash = str_repeat('a', 64);
$deletionReceiptHash = str_repeat('d', 64);
$deletionRow = [
    'uuid' => 'delete-1',
    'type' => 'post',
    'expected_hash' => $deletionExpectedHash,
    'receipt_hash' => $deletionReceiptHash,
];
$deletedComparison = ApplyPlanner::classify_deletion($deletionRow, null, null);
$check(
    $deletedComparison === ['bucket' => 'deleted', 'row' => $deletionRow],
    'deletion comparison: absent target is already deleted without inventing a conflict'
);
$baseFacts = ['entity_type' => 'entity', 'content_hash' => $deletionExpectedHash];
$deleteComparison = ApplyPlanner::classify_deletion(
    $deletionRow,
    ['hash' => $deletionExpectedHash],
    $baseFacts
);
$check(
    $deleteComparison === ['bucket' => 'delete', 'row' => $deletionRow],
    'deletion comparison: matching target and base hashes authorize the delete bucket'
);
$missingBaseComparison = ApplyPlanner::classify_deletion(
    $deletionRow,
    ['hash' => $deletionExpectedHash],
    null
);
$check(
    $missingBaseComparison['bucket'] === 'delete_conflict'
        && $missingBaseComparison['row']['reason'] === 'target entity exists but has no last-synced base'
        && $missingBaseComparison['row']['conflict_view']['reason_code'] === 'target_without_last_synced_base',
    'deletion comparison: target without a base is a typed conflict, never deletion authority'
);
$recreatedBaseComparison = ApplyPlanner::classify_deletion(
    $deletionRow,
    ['hash' => $deletionExpectedHash],
    ['entity_type' => 'deletion', 'content_hash' => $deletionExpectedHash]
);
$check(
    $recreatedBaseComparison['row']['reason'] === 'target entity was recreated after this deletion intent was applied'
        && $recreatedBaseComparison['row']['conflict_view']['reason_code'] === 'target_recreated_after_delete',
    'deletion comparison: a recreated target remains a tombstone conflict'
);
$baseMismatchComparison = ApplyPlanner::classify_deletion(
    $deletionRow,
    ['hash' => $deletionExpectedHash],
    ['entity_type' => 'entity', 'content_hash' => str_repeat('b', 64)]
);
$check(
    $baseMismatchComparison['row']['conflict_view']['reason_code'] === 'repository_expected_base_mismatch',
    'deletion comparison: repository/base mismatch refuses before target deletion'
);
$targetChangedComparison = ApplyPlanner::classify_deletion(
    $deletionRow,
    ['hash' => str_repeat('c', 64)],
    $baseFacts
);
$check(
    $targetChangedComparison['row']['conflict_view']['reason_code'] === 'target_changed_since_delete_base',
    'deletion comparison: target drift refuses the tombstone'
);

// --------------------------------------------------------- observed comparison

$comparisonRow = ['uuid' => 'observed-1', 'type' => 'post', 'path' => 'posts/observed-1.md'];
$unchangedComparison = ApplyPlanner::classify_observed(
    $comparisonRow,
    'repo-hash',
    ['hash' => 'repo-hash'],
    'base-hash',
    'target-hash'
);
$check(
    $unchangedComparison === ['bucket' => 'unchanged', 'row' => $comparisonRow],
    'observed comparison: matching repository and target hashes are unchanged'
);
$firstSyncComparison = ApplyPlanner::classify_observed(
    $comparisonRow,
    'repo-hash',
    ['hash' => 'target-hash'],
    null,
    'target-hash'
);
$check(
    $firstSyncComparison === [
        'bucket' => 'update',
        'row' => $comparisonRow + ['first_sync' => true],
    ],
    'observed comparison: a target without a base is a first-sync update'
);
$lifecycleUpdateComparison = ApplyPlanner::classify_observed(
    $comparisonRow,
    'repo-hash',
    ['hash' => 'target-hash'],
    'base-hash',
    'base-hash'
);
$check(
    $lifecycleUpdateComparison === [
        'bucket' => 'update',
        'row' => $comparisonRow + ['first_sync' => false],
    ],
    'observed comparison: lifecycle-adjusted target matching the base is an update'
);
$driftComparison = ApplyPlanner::classify_observed(
    $comparisonRow,
    'base-hash',
    ['hash' => 'target-hash'],
    'base-hash',
    'target-hash'
);
$check(
    $driftComparison === ['bucket' => 'drift', 'row' => $comparisonRow],
    'observed comparison: repository matching the base while target differs is drift'
);
$conflictComparison = ApplyPlanner::classify_observed(
    $comparisonRow,
    'repo-hash',
    ['hash' => 'target-hash'],
    'base-hash',
    'target-hash'
);
$check(
    $conflictComparison['bucket'] === 'conflict'
        && $conflictComparison['row']['conflict_view']['reason_code'] === 'repository_and_target_changed_since_base'
        && $conflictComparison['row']['conflict_view']['choices'][1]['requires'] === ['--force-theirs'],
    'observed comparison: repository and target changes become a typed conflict'
);

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

// -------------------------------------------------------- theme mismatch

$themeTree = [
    'theme-old' => [
        'type' => 'term',
        'data' => ['taxonomy' => 'wp_theme', 'slug' => 'old-theme'],
    ],
    'theme-active' => [
        'type' => 'term',
        'data' => ['taxonomy' => 'wp_theme', 'slug' => 'active-theme'],
    ],
    'template-one' => [
        'type' => 'post',
        'path' => 'posts/template-one.json',
        'data' => ['terms' => ['wp_theme' => ['theme-old']]],
    ],
    'template-two' => [
        'type' => 'post',
        'path' => 'posts/template-two.json',
        'data' => ['terms' => ['wp_theme' => ['theme-old', 'theme-active']]],
    ],
];
$themeWarning = "active-theme mismatch: this environment's active theme is 'active-theme' but "
    . "posts/template-one.json, posts/template-two.json are tagged for theme 'old-theme'"
    . " — will apply but will NOT render until 'old-theme' is active here";
$check(
    ApplyPlanner::theme_mismatch_warnings($themeTree, 'active-theme') === [$themeWarning],
    'theme mismatch: captured non-active theme groups all affected paths into one exact warning'
);
$check(
    ApplyPlanner::theme_mismatch_warnings($themeTree, 'old-theme') === [
        "active-theme mismatch: this environment's active theme is 'old-theme' but "
            . "posts/template-two.json is tagged for theme 'active-theme'"
            . " — will apply but will NOT render until 'active-theme' is active here",
    ],
    'theme mismatch: active captured theme is ignored while another captured theme remains observable'
);
$check(
    ApplyPlanner::theme_mismatch_warnings([
        'post' => ['type' => 'post', 'data' => ['terms' => ['wp_theme' => ['missing']]]],
    ], 'active-theme') === [],
    'theme mismatch: non-FSE trees and unknown theme identities produce no warning'
);

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

// --------------------------------------------------------- option projection

$optionPolicy = new Policy();
$optionPolicy->manifests = [[
    'name' => 'option-fixture',
    'options' => [
        'managed_option' => ['class' => 'managed', 'autoload' => 'yes'],
    ],
]];
$optionPlanner = new ApplyPlanner(
    $optionPolicy,
    [],
    static fn(string $uuid, string $kind): ?int => null,
    static fn(string $uuid, string $kind): ?int => null
);
$desiredOptions = [
    'format' => 'duo-options/v1',
    'records' => [
        'authored_option' => ['state' => 'present', 'autoload' => 'yes', 'value' => 'desired'],
        'managed_option' => ['state' => 'present', 'autoload' => 'yes', 'value' => 'lifecycle'],
        'absent_option' => ['state' => 'absent'],
    ],
];
$check(
    $optionPlanner->option_rebuild_names($desiredOptions, null) === ['authored_option'],
    'option projection: fresh targets select authored records but exclude managed and absent records'
);
$observedOptions = [
    'content' => Canon::encode([
        'format' => 'duo-options/v1',
        'records' => [
            'authored_option' => ['state' => 'present', 'autoload' => 'yes', 'value' => 'old'],
            'managed_option' => ['state' => 'present', 'autoload' => 'yes', 'value' => 'old-lifecycle'],
            'target_only_option' => ['state' => 'present', 'autoload' => 'yes', 'value' => 'target'],
        ],
    ]),
];
$check(
    $optionPlanner->option_rebuild_names($desiredOptions, $observedOptions) === ['authored_option'],
    'option projection: changed authored records are selected while managed and target-only records stay untouched'
);
$unchangedOptions = [
    'content' => Canon::encode([
        'format' => 'duo-options/v1',
        'records' => [
            'authored_option' => ['state' => 'present', 'autoload' => 'yes', 'value' => 'desired'],
            'managed_option' => ['state' => 'present', 'autoload' => 'yes', 'value' => 'different-lifecycle'],
        ],
    ]),
];
$check(
    $optionPlanner->option_rebuild_names($desiredOptions, $unchangedOptions) === [],
    'option projection: an authored record equal to the target produces no rebuild work'
);

// --------------------------------------------------------- sidebar projection

$sidebarRow = ['uuid' => 'sidebar/main', 'type' => 'sidebar', 'path' => 'sidebars/main.json'];
$sidebarDesired = [
    'widgets' => [
        ['uuid' => 'widget-1', 'type' => 'text', 'settings' => []],
    ],
];
$sidebarTarget = [
    'content' => Canon::encode([
        'widgets' => [
            ['uuid' => 'widget-1', 'type' => 'text', 'settings' => ['text' => 'keep']],
            ['uuid' => 'widget-target-only', 'type' => 'text', 'settings' => ['_duo_unmanaged' => true]],
        ],
    ]),
];
$sidebarPlanner = new ApplyPlanner(
    new Policy(),
    [],
    static fn(string $uuid, string $kind): ?int => $uuid === 'widget-1' && $kind === 'widget_text' ? 17 : null,
    static fn(string $uuid, string $kind): ?int => null
);
$sidebarProjection = $sidebarPlanner->project_sidebar_deletes(
    $sidebarRow,
    $sidebarDesired,
    $sidebarTarget,
    'sidebar-base-hash'
);
$check(
    $sidebarProjection === $sidebarRow + [
        'widget_deletes' => [[
            'uuid' => 'widget-target-only',
            'type' => 'text',
            'unmanaged' => true,
        ]],
    ],
    'sidebar projection: mapped desired widgets preserve exact target-only deletion evidence'
);
$sidebarMissingMapMessage = null;
try {
    $sidebarPlanner->project_sidebar_deletes(
        $sidebarRow,
        ['widgets' => [['uuid' => 'widget-missing', 'type' => 'text', 'settings' => []]]],
        $sidebarTarget,
        'sidebar-base-hash'
    );
} catch (RuntimeException $failure) {
    $sidebarMissingMapMessage = $failure->getMessage();
}
$check(
    $sidebarMissingMapMessage ===
        'duo: widget identity history is missing for sidebars/main.json; refusing to infer which live '
        . 'instance owns a canonical UUID. Restore identity-export before plan/apply.',
    'sidebar projection: unmanaged defaults plus a missing desired map refuse identity inference'
);
$sidebarAcknowledgedDefaultProjection = $sidebarPlanner->project_sidebar_deletes(
    $sidebarRow,
    ['widgets' => [['uuid' => 'widget-missing', 'type' => 'text', 'settings' => []]]],
    ['content' => Canon::encode([
        'widgets' => [[
            'uuid' => 'widget-target-only',
            'type' => 'text',
            'settings' => [],
        ]],
    ])],
    'sidebar-base-hash'
);
$check(
    $sidebarAcknowledgedDefaultProjection['widget_deletes'] === [[
        'uuid' => 'widget-target-only',
        'type' => 'text',
        'unmanaged' => false,
    ]],
    'sidebar projection: absent unmanaged marker does not invent an identity-history refusal'
);

$optionDeletionRow = ['uuid' => 'options/core', 'type' => 'options', 'path' => 'options/core.json'];
$optionBeforeDelete = OptionState::present('before-delete', 'yes');
$optionDeletionDocument = OptionState::document([
    'remove_me' => OptionState::deleted($optionBeforeDelete),
    'leave_alone' => OptionState::absent(),
]);
$optionTarget = OptionState::document(['remove_me' => $optionBeforeDelete]);
$optionEnvironment = [
    'content' => Canon::encode($optionTarget),
    'hash' => 'target-options-hash',
];
$noOptionDeletion = ApplyPlanner::classify_option_deletions(
    $optionDeletionRow,
    OptionState::document(['leave_alone' => OptionState::absent()]),
    $optionEnvironment,
    'base-options-hash',
    'repository-options-hash',
    'comparison-options-hash'
);
$check(
    $noOptionDeletion === ['bucket' => 'continue', 'row' => $optionDeletionRow],
    'option deletion projection: no deleted desired record leaves the row unchanged'
);
$safeOptionDeletion = ApplyPlanner::classify_option_deletions(
    $optionDeletionRow,
    $optionDeletionDocument,
    $optionEnvironment,
    'base-options-hash',
    'repository-options-hash',
    'comparison-options-hash'
);
$check(
    $safeOptionDeletion === [
        'bucket' => 'continue',
        'row' => $optionDeletionRow + ['option_deletes' => ['remove_me']],
    ],
    'option deletion projection: matching target records become exact pending delete names'
);
$changedOptionTarget = OptionState::document([
    'remove_me' => OptionState::present('changed-after-delete-base', 'yes'),
]);
$changedOptionDeletion = ApplyPlanner::classify_option_deletions(
    $optionDeletionRow,
    $optionDeletionDocument,
    ['content' => Canon::encode($changedOptionTarget), 'hash' => 'target-options-hash'],
    'base-options-hash',
    'repository-options-hash',
    'comparison-options-hash'
);
$check(
    $changedOptionDeletion['bucket'] === 'conflict'
        && $changedOptionDeletion['row']['option_deletes'] === ['remove_me']
        && $changedOptionDeletion['row']['reason'] === 'remove_me changed after the deletion base'
        && $changedOptionDeletion['row']['conflict_view']['reason_code'] === 'option_delete_and_target_changed_since_base',
    'option deletion projection: changed target records become typed conflicts'
);
$recreatedOptionDeletion = ApplyPlanner::classify_option_deletions(
    $optionDeletionRow,
    $optionDeletionDocument,
    $optionEnvironment,
    'same-options-hash',
    'same-options-hash',
    'comparison-options-hash'
);
$check(
    $recreatedOptionDeletion['bucket'] === 'conflict'
        && $recreatedOptionDeletion['row']['reason'] === 'remove_me was recreated after its deletion intent was applied'
        && $recreatedOptionDeletion['row']['conflict_view']['reason_code'] === 'option_delete_and_target_changed_since_base',
    'option deletion projection: repository recreation after deletion becomes a typed conflict'
);

// ----------------------------------------------- natural-key continuity notes

$naturalKeyUuid = Uuid::v5(Uuid::NAMESPACE_DUO, 'acme_rooms:fresh-room');
$naturalKeyCollisionResolverCalls = [];
$naturalKeyTableResolverCalls = [];
$naturalKeyPlanner = new ApplyPlanner(
    new Policy(),
    [
        'acme_rooms' => [
            'id_kind' => 'acme_room',
            'identity' => ['mode' => 'natural_key', 'column' => 'code'],
        ],
        'mapped_rooms' => [
            'id_kind' => 'mapped_room',
            'identity' => ['mode' => 'mapped'],
        ],
    ],
    static function (string $uuid, string $kind) use (&$naturalKeyCollisionResolverCalls): ?int {
        $naturalKeyCollisionResolverCalls[] = [$uuid, $kind];
        return null;
    },
    static function (string $uuid, string $kind) use (&$naturalKeyTableResolverCalls, $naturalKeyUuid): ?int {
        $naturalKeyTableResolverCalls[] = [$uuid, $kind];
        return $uuid === $naturalKeyUuid && $kind === 'acme_room' ? 71 : null;
    }
);
$check(
    $naturalKeyPlanner->natural_key_continuity_annotations(
        $naturalKeyUuid,
        'acme_rooms',
        ['columns' => ['code' => 'fresh-room']],
        null
    ) === [],
    'natural-key annotation: a retained UUID equal to the current key produces no rename note'
);
$renameNote = 'acme_rooms row renamed-room: renamed since first capture (uuid retained via ledger)';
$check(
    $naturalKeyPlanner->natural_key_continuity_annotations(
        $naturalKeyUuid,
        'acme_rooms',
        ['columns' => ['code' => 'renamed-room']],
        ['content' => Canon::encode(['columns' => ['code' => 'renamed-room']])]
    ) === [$renameNote],
    'natural-key annotation: desired and observed rename notes collapse to one deterministic plan annotation'
);
$check(
    $naturalKeyPlanner->natural_key_continuity_annotations(
        $naturalKeyUuid,
        'acme_rooms',
        null,
        ['content' => Canon::encode(['columns' => ['code' => 'target-renamed-room']])]
    ) === ['acme_rooms row target-renamed-room: renamed since first capture (uuid retained via ledger)'],
    'natural-key annotation: a same-snapshot observed front is projected without a target reread'
);
$check($naturalKeyCollisionResolverCalls === [], 'natural-key annotation: continuity leaves collision-token resolution untouched');
$check(
    $naturalKeyTableResolverCalls === [
        [$naturalKeyUuid, 'acme_room'],
        [$naturalKeyUuid, 'acme_room'],
        [$naturalKeyUuid, 'acme_room'],
    ],
    'natural-key annotation: the planner uses only its injected raw-table resolver'
);
$unmappedPlanner = new ApplyPlanner(
    new Policy(),
    ['acme_rooms' => ['id_kind' => 'acme_room', 'identity' => ['mode' => 'natural_key', 'column' => 'code']]],
    static fn(string $uuid, string $kind): ?int => null,
    static fn(string $uuid, string $kind): ?int => null
);
$check(
    $unmappedPlanner->natural_key_continuity_annotations(
        $naturalKeyUuid,
        'acme_rooms',
        ['columns' => ['code' => 'renamed-room']],
        null
    ) === [],
    'natural-key annotation: a fresh target with no retained mapping does not claim a rename'
);
$check(
    $naturalKeyPlanner->natural_key_continuity_annotations(
        $naturalKeyUuid,
        'mapped_rooms',
        ['columns' => ['code' => 'renamed-room']],
        null
    ) === [],
    'natural-key annotation: mapped identity tables never produce continuity notes'
);

final class ApplyPlannerRawTableLedgerWpdb {
    public string $prefix = 'wp_';
    public string $last_error = '';

    /** @var list<array{0:string,1:string}> */
    public array $ledgerLookups = [];

    public function prepare(string $query, mixed ...$args): string {
        $this->ledgerLookups[] = [(string) ($args[0] ?? ''), (string) ($args[1] ?? '')];
        return $query;
    }

    public function get_var(string $query): ?string {
        $lookup = $this->ledgerLookups[array_key_last($this->ledgerLookups)];
        return $lookup[1] === 'tt' ? '73' : null;
    }
}

$rawTableLedgerWpdb = new ApplyPlannerRawTableLedgerWpdb();
$GLOBALS['wpdb'] = $rawTableLedgerWpdb;
$applyReflection = new ReflectionClass(\Duo\Apply::class);
$applyForRawTt = $applyReflection->newInstanceWithoutConstructor();
$applyReflection->getProperty('policy')->setValue($applyForRawTt, new Policy());
$applyReflection->getProperty('snapshotRowTablesCache')->setValue($applyForRawTt, [
    'tt_rooms' => [
        'id_kind' => 'tt',
        'identity' => ['mode' => 'natural_key', 'column' => 'code'],
    ],
]);
$rawTtUuid = 'f2e8bd3d-8c9e-5f4a-9b79-c872f4e8414c';
$rawTtRow = [];
$rawTtArgs = [&$rawTtRow, $rawTtUuid, 'tt_rooms', ['columns' => ['code' => 'renamed-room']], null];
$applyReflection->getMethod('annotate_natural_key_continuity')->invokeArgs($applyForRawTt, $rawTtArgs);
$check($rawTtRow['annotations'] === [
    'tt_rooms row renamed-room: renamed since first capture (uuid retained via ledger)',
], 'Apply preserves a declared raw tt ledger kind for natural-key continuity');
$check($rawTableLedgerWpdb->ledgerLookups === [[$rawTtUuid, 'tt']], 'Apply sends literal declared tt to Ledger unchanged');

// ----------------------------------------------------------- work projection

$optionPolicy->manifests[0]['post_types'] = [
    'definition' => ['phase' => 'early'],
];
$workTree = [
    'regular-create' => ['type' => 'post', 'post_type' => 'post'],
    'early-create' => ['type' => 'post', 'post_type' => 'definition'],
    'adopt-term' => ['type' => 'term'],
    'update-options' => ['type' => 'options'],
    'conflict-menu' => ['type' => 'menu'],
    'drift-post' => ['type' => 'post', 'post_type' => 'post'],
];
$workPlan = [
    'create' => [['uuid' => 'regular-create'], ['uuid' => 'early-create']],
    'adopt' => [['uuid' => 'adopt-term']],
    'update' => [['uuid' => 'update-options']],
    'conflict' => [['uuid' => 'conflict-menu']],
    'drift' => [['uuid' => 'drift-post']],
    'delete' => [
        ['uuid' => 'post-delete', 'deletion_kind' => 'post'],
        ['uuid' => 'term-delete', 'deletion_kind' => 'term'],
    ],
    'delete_conflict' => [['uuid' => 'menu-delete', 'deletion_kind' => 'menu']],
    'deleted' => [['uuid' => 'retry-term', 'deletion_kind' => 'term']],
];
$workProjection = $optionPlanner->rebuild_work($workPlan, $workTree, [], false);
$check(
    array_column($workProjection['work'], 'uuid') === [
        'early-create', 'regular-create', 'adopt-term', 'update-options', 'conflict-menu',
    ],
    'work projection: early posts lead and the remaining authored buckets retain their stable order'
);
$check(
    array_column($workProjection['delete_work'], 'uuid') === ['post-delete', 'term-delete']
        && array_column($workProjection['rebuild_delete_work'], 'uuid') === ['post-delete', 'term-delete'],
    'work projection: ordinary apply selects only authorized deletes and shares that order with rebuild work'
);
$forcedRetryProjection = $optionPlanner->rebuild_work(
    $workPlan,
    $workTree,
    ['force_theirs' => true],
    true,
    true
);
$check(
    array_column($forcedRetryProjection['work'], 'uuid') === [
        'early-create', 'regular-create', 'adopt-term', 'update-options', 'conflict-menu', 'drift-post',
    ],
    'work projection: scoped-promotion drift is opt-in while conflicts remain in the negotiated work set'
);
$check(
    array_column($forcedRetryProjection['delete_work'], 'uuid') === ['post-delete', 'menu-delete', 'term-delete']
        && array_column($forcedRetryProjection['rebuild_delete_work'], 'uuid') === [
            'post-delete', 'menu-delete', 'retry-term', 'term-delete',
        ],
    'work projection: forced conflicts and incomplete-apply tombstones widen only their declared projections'
);

$ninjaManifest = json_decode(
    (string) file_get_contents(__DIR__ . '/../../manifests/ninja-forms.json'),
    true,
    512,
    JSON_THROW_ON_ERROR
);
$tablePolicy = new Policy();
$tablePolicy->manifests = [$ninjaManifest];
$tablePlanner = new ApplyPlanner(
    $tablePolicy,
    Snapshot::row_tables($tablePolicy),
    static fn(string $uuid, string $kind): ?int => null,
    static fn(string $uuid, string $kind): ?int => null
);
$tableTree = [
    'table-parent' => ['type' => 'nf3_forms'],
    'table-child' => ['type' => 'nf3_fields'],
    'table-post' => ['type' => 'post', 'post_type' => 'post'],
];
$tablePlan = [
    'create' => [
        ['uuid' => 'table-child'],
        ['uuid' => 'table-parent'],
        ['uuid' => 'table-post'],
    ],
    'delete' => [
        ['uuid' => 'table-parent-delete', 'deletion_kind' => 'table', 'deletion_type' => 'nf3_forms'],
        ['uuid' => 'table-child-delete', 'deletion_kind' => 'table', 'deletion_type' => 'nf3_fields'],
    ],
];
$tableProjection = $tablePlanner->rebuild_work($tablePlan, $tableTree, [], false);
$check(
    array_column($tableProjection['work'], 'uuid') === ['table-post', 'table-parent', 'table-child'],
    'work projection: declared table parents follow ordinary entities and precede dependent table children'
);
$check(
    array_column($tableProjection['delete_work'], 'uuid') === ['table-child-delete', 'table-parent-delete'],
    'work projection: declared table deletion order is child-before-parent from the manifest graph'
);

// --------------------------------------------- mutation-authority projections

$repairPlan = [
    'create' => [['uuid' => 'created']],
    'update' => [['uuid' => 'updated']],
    'adopt' => [['uuid' => 'adopted']],
    'drift' => [['uuid' => 'drifted']],
    'conflict' => [['uuid' => 'conflicted']],
    'delete' => [['uuid' => 'deleted']],
];
$check(
    ApplyPlanner::guard_repair_uuids($repairPlan) === [
        'created' => true,
        'updated' => true,
        'adopted' => true,
    ],
    'guard repair projection: ordinary plans include only authored create/update/adopt UUIDs'
);
$check(
    ApplyPlanner::guard_repair_uuids($repairPlan, true) === [
        'created' => true,
        'updated' => true,
        'adopted' => true,
        'drifted' => true,
    ],
    'guard repair projection: scoped promotion explicitly widens the witness with drift'
);
$check(
    ApplyPlanner::guard_repair_uuids([
        'update' => [['uuid' => '']],
        'adopt' => [['type' => 'post']],
        'delete' => [['uuid' => 'tombstone']],
    ]) === [],
    'guard repair projection: malformed or non-authored rows cannot become repair authority'
);

$authorityPlan = [
    'delete' => [['uuid' => 'delete-1', 'guard_witnesses' => ['0' => str_repeat('a', 64)]]],
    'regen_context' => [['uuid' => 'post-1', 'receipt_hash' => str_repeat('b', 64)]],
    'uploads_inventory' => ['uploads' => ['one.jpg']],
    'effects_inventory' => ['effects' => ['one']],
    'warnings' => ['display-only'],
];
$authorityHash = ApplyPlanner::plan_precondition_hash($authorityPlan);
$warningOnlyPlan = $authorityPlan;
$warningOnlyPlan['warnings'] = ['a different display warning'];
$check(
    $authorityHash === ApplyPlanner::plan_precondition_hash($warningOnlyPlan),
    'precondition hash: report-only buckets do not change mutation authority'
);
$changedWitnessPlan = $authorityPlan;
$changedWitnessPlan['delete'][0]['guard_witnesses']['0'] = str_repeat('c', 64);
$check(
    $authorityHash !== ApplyPlanner::plan_precondition_hash($changedWitnessPlan),
    'precondition hash: exact deletion guard witnesses invalidate stale authority'
);
$changedReceiptPlan = $authorityPlan;
$changedReceiptPlan['regen_context'][0]['receipt_hash'] = str_repeat('d', 64);
$check(
    $authorityHash !== ApplyPlanner::plan_precondition_hash($changedReceiptPlan),
    'precondition hash: durable regeneration receipts invalidate stale authority'
);

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

// ----------------------------------------------------------- collision planner

final class ApplyPlannerCollisionWpdb {
    public string $prefix = 'wp_';
    public string $posts = 'wp_posts';
    public string $terms = 'wp_terms';
    public string $term_taxonomy = 'wp_term_taxonomy';
    /** @var list<int|string> */
    public array $collisionIds = [];

    public function prepare(string $query, mixed ...$args): string {
        return $query;
    }

    /** @return list<int|string> */
    public function get_col(string $query): array {
        return $this->collisionIds;
    }

    public function get_var(string $query): mixed {
        return $this->collisionIds[0] ?? null;
    }
}

$plannerConstructor = (new ReflectionClass(ApplyPlanner::class))->getConstructor();
$check(
    array_map(static fn(ReflectionParameter $p): string => (string) $p->getType(), $plannerConstructor->getParameters()) === [
        'Duo\\Policy', 'array', 'Closure', 'Closure',
    ],
    'collision planner: constructor separates collision-token and raw-table ledger resolvers'
);

$plannerPolicy = (new ReflectionClass(Policy::class))->newInstanceWithoutConstructor();
$resolverCalls = [];
$collisionPlanner = new ApplyPlanner($plannerPolicy, [], static function (string $uuid, string $kind) use (&$resolverCalls): ?int {
    $resolverCalls[] = [$uuid, $kind];
    return $uuid === 'parent-1' && $kind === 'post' ? 7 : null;
}, static fn(string $uuid, string $kind): ?int => null);
$wpdb = new ApplyPlannerCollisionWpdb();
$collisionEntity = [
    'type' => 'post',
    'data' => ['uuid' => 'post-1', 'slug' => 'about', 'type' => 'page'],
];
$collisionCache = [];
$wpdb->collisionIds = [42];
$check(
    $collisionPlanner->find_collision($collisionEntity, [], $collisionCache) === 42,
    'collision planner: a same-slug post resolves the one local natural-key match'
);
$check(
    $collisionCache === ['post-1' => 42],
    'collision planner: the resolved UUID is memoized in the caller-owned cache'
);
$check(
    $resolverCalls === [],
    'collision planner: an unparented natural key does not consult the injected ledger resolver'
);

$wpdb->collisionIds = [77];
$parentedEntity = [
    'type' => 'post',
    'data' => [
        'uuid' => 'child-1',
        'slug' => 'child',
        'type' => 'page',
        'parent' => '{{post:parent-1}}',
    ],
];
$parentedCache = [];
$check(
    $collisionPlanner->find_collision($parentedEntity, [], $parentedCache) === 77,
    'collision planner: a typed post parent uses the injected resolver before querying the child key'
);
$check(
    $resolverCalls === [['parent-1', 'post']],
    'collision planner: the resolver receives the canonical post id-kind, not a Ledger class dependency'
);

$resolverCalls = [];
$wpdb->collisionIds = [42, 43];
$conflictingCache = [];
$conflictingEntity = [
    'type' => 'term',
    'data' => ['uuid' => 'term-1', 'slug' => 'news', 'taxonomy' => 'category'],
];
$conflictingMessage = null;
try {
    $collisionPlanner->find_collision($conflictingEntity, [], $conflictingCache);
} catch (RuntimeException $failure) {
    $conflictingMessage = $failure->getMessage();
}
$check(
    is_string($conflictingMessage)
        && str_contains($conflictingMessage, 'conflicting adoption key')
        && str_contains($conflictingMessage, '42, 43'),
    'collision planner: duplicate local natural identity remains a loud refusal'
);
$check(
    $resolverCalls === [],
    'collision planner: an unparented term natural key does not consult the injected ledger resolver'
);

$tablePolicy = new Policy();
$tablePolicy->manifests = [[
    'name' => 'collision-fixture',
    'tables' => [
        'acme_rooms' => [
            'class' => 'authored_snapshot',
            'id_kind' => 'acme_room',
            'pk' => 'id',
            'columns' => ['code' => ['class' => 'authored']],
            'identity' => ['mode' => 'natural_key', 'column' => 'code'],
        ],
        'acme_slots' => [
            'class' => 'authored_snapshot',
            'id_kind' => 'acme_slot',
            'pk' => 'id',
            'columns' => ['room_id' => ['class' => 'authored'], 'code' => ['class' => 'authored']],
            'refs' => [['column' => 'room_id', 'kind' => 'acme_room']],
            'identity' => ['mode' => 'natural_key', 'columns' => ['room_id', 'code']],
        ],
    ],
]];
$roomUuid = '00000000-0000-4000-8000-000000000101';
$slotUuid = '00000000-0000-4000-8000-000000000102';
$tableResolverCalls = [];
$tablePlanner = new ApplyPlanner(
    $tablePolicy,
    $tablePolicy->declared_tables(),
    static function (string $uuid, string $kind) use (&$tableResolverCalls, $roomUuid): ?int {
        $tableResolverCalls[] = [$uuid, $kind];
        return $uuid === $roomUuid && $kind === 'acme_room' ? 13 : null;
    },
    static fn(string $uuid, string $kind): ?int => null
);
$wpdb->collisionIds = [91];
$tableCache = [];
$tableEntity = [
    'type' => 'acme_slots',
    'data' => [
        'uuid' => $slotUuid,
        'columns' => ['room_id' => "{{acme_room:$roomUuid}}", 'code' => 'morning'],
    ],
];
$check(
    $tablePlanner->find_collision($tableEntity, [], $tableCache) === 91,
    'collision planner: a declared typed-table natural key resolves through the injected parent resolver'
);
$check(
    $tableResolverCalls === [[$roomUuid, 'acme_room']],
    'collision planner: typed-table refs use the declared token kind without loading Ledger directly'
);

$applySource = file_get_contents(__DIR__ . '/../../agent/src/Apply.php');
$plannerSource = file_get_contents(__DIR__ . '/../../agent/src/ApplyPlanner.php');
$check(
    !preg_match('/private function find_collision\(/', $applySource),
    'collision planner: Apply no longer owns the collision implementation'
);
$check(
    str_contains($applySource, '$this->apply_planner()->find_collision($e, $tree, $collisionCache);'),
    'collision planner: build_plan delegates through the planner collaborator'
);
$check(
    str_contains($applySource, '$this->apply_planner()->option_rebuild_names($e[\'data\'], $envE);'),
    'option projection: build_plan delegates rebuild-name selection through the planner collaborator'
);
$check(
    preg_match('/public function find_collision\(/', $plannerSource) === 1,
    'collision planner: the moved product-path method is public on ApplyPlanner'
);
$check(
    preg_match('/public function option_rebuild_names\(/', $plannerSource) === 1
        && preg_match('/private function option_rebuild_names\([^}]*?return \$this->apply_planner\(\)->option_rebuild_names\(/s', $applySource) === 1,
    'option projection: implementation lives on ApplyPlanner while Apply keeps only its facade'
);
$check(
    preg_match('/public function rebuild_work\(/', $plannerSource) === 1
        && preg_match('/private function rebuild_work\([^}]*?return \$this->apply_planner\(\)->rebuild_work\(/s', $applySource) === 1
        && preg_match('/private function phase2_rank\([^}]*?return \$this->apply_planner\(\)->phase2_rank\(/s', $applySource) === 1
        && preg_match('/private function deletion_rank\([^}]*?return \$this->apply_planner\(\)->deletion_rank\(/s', $applySource) === 1,
    'work projection: Apply keeps only compatibility facades while planner owns work and ordering projections'
);
$check(
    preg_match('/public static function guard_repair_uuids\(/', $plannerSource) === 1
        && preg_match('/private function guard_repair_uuids\([^}]*?return ApplyPlanner::guard_repair_uuids\(/s', $applySource) === 1
        && preg_match('/public static function plan_precondition_hash\(/', $plannerSource) === 1
        && preg_match('/private function plan_precondition_hash\([^}]*?return ApplyPlanner::plan_precondition_hash\(/s', $applySource) === 1,
    'mutation authority: Apply keeps thin facades while planner owns repair UUID and precondition projections'
);
$check(
    preg_match('/public function natural_key_continuity_annotations\(/', $plannerSource) === 1
        && preg_match('/private function annotate_natural_key_continuity\(.*?apply_planner\(\)->natural_key_continuity_annotations\(/s', $applySource) === 1
        && str_contains($plannerSource, "require_once __DIR__ . '/IdentityNotes.php';"),
    'natural-key annotation: planner owns the projection while Apply keeps the plan-row compatibility facade'
);
$themeSectionStart = strpos($applySource, '    private function check_theme_mismatch(');
$themeSectionEnd = strpos($applySource, "\n    // ----------------------------------------------------------------- apply", $themeSectionStart);
$themeSection = substr($applySource, $themeSectionStart, $themeSectionEnd - $themeSectionStart);
$check(
    str_contains($themeSection, 'ApplyPlanner::theme_mismatch_warnings(')
        && !str_contains($themeSection, '$themeSlugByUuid')
        && !str_contains($themeSection, '$affected')
        && preg_match('/public static function theme_mismatch_warnings\(/', $plannerSource) === 1,
    'theme mismatch: Apply keeps the WordPress input/facade while planner owns warning projection'
);
$deletionSectionStart = strpos($applySource, '        // Absence is not deletion authority.');
$deletionSectionEnd = strpos($applySource, '        // Runtime reverse references are target facts');
$deletionSection = substr($applySource, $deletionSectionStart, $deletionSectionEnd - $deletionSectionStart);
$check(
    str_contains($deletionSection, '$this->apply_planner()->classify_deletion(')
        && !str_contains($deletionSection, 'hash_equals(')
        && !str_contains($deletionSection, 'target_without_last_synced_base')
        && !str_contains($deletionSection, 'target_changed_since_delete_base')
        && preg_match('/public static function classify_deletion\(/', $plannerSource) === 1,
    'deletion comparison: Apply delegates tombstone classification while planner owns all three-way branches'
);
$comparisonSectionStart = strpos($applySource, "            if (\$envE !== null) {\n                \$comparison =");
$comparisonSectionEnd = strpos($applySource, '            $coll = $this->apply_planner()->find_collision(', $comparisonSectionStart);
$comparisonSection = substr($applySource, $comparisonSectionStart, $comparisonSectionEnd - $comparisonSectionStart);
$check(
    str_contains($comparisonSection, '$this->apply_planner()->classify_observed(')
        && !str_contains($comparisonSection, 'repository_and_target_changed_since_base')
        && !str_contains($comparisonSection, "['first_sync']")
        && !str_contains($comparisonSection, 'conflict_view(')
        && preg_match('/public static function classify_observed\(/', $plannerSource) === 1,
    'observed comparison: Apply delegates four-way hash classification while planner owns its conflict evidence'
);
$optionSectionStart = strpos($applySource, "            if (\$uuid === 'options/core' && \$envE !== null) {");
$optionSectionEnd = strpos($applySource, "            if (\$envE !== null) {", $optionSectionStart);
$optionSection = substr($applySource, $optionSectionStart, $optionSectionEnd - $optionSectionStart);
$check(
    str_contains($optionSection, 'ApplyPlanner::classify_option_deletions(')
        && !str_contains($optionSection, 'option_delete_and_target_changed_since_base')
        && !str_contains($optionSection, 'changed after the deletion base')
        && !str_contains($optionSection, 'was recreated after its deletion intent was applied')
        && preg_match('/public static function classify_option_deletions\(/', $plannerSource) === 1,
    'option deletion projection: Apply delegates deletion-intent comparison while the planner owns its conflict evidence'
);
$sidebarSectionStart = strpos($applySource, "            if (\$e['type'] === SidebarState::ENTITY_TYPE && \$envE !== null) {");
$sidebarSectionEnd = strpos($applySource, "            if (\$e['type'] === 'user-meta' && \$envE === null) {", $sidebarSectionStart);
$sidebarSection = substr($applySource, $sidebarSectionStart, $sidebarSectionEnd - $sidebarSectionStart);
$check(
    str_contains($sidebarSection, '$this->apply_planner()->project_sidebar_deletes(')
        && !str_contains($sidebarSection, 'Ledger::id_for')
        && !str_contains($sidebarSection, '_duo_unmanaged')
        && preg_match('/public function project_sidebar_deletes\(/', $plannerSource) === 1,
    'sidebar projection: Apply delegates widget-delete planning while the planner owns identity evidence and target-only classification'
);

if ($failures) {
    echo "\n" . count($failures) . " failure(s):\n";
    foreach ($failures as $f) {
        echo "  - $f\n";
    }
    exit(1);
}
echo "\nall ApplyPlanner checks passed\n";
exit(0);
