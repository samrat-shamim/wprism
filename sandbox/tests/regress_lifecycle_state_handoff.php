<?php
/**
 * Offline safety contract for lifecycle retire/activate -> apply's
 * options/core hash handoff.
 * The live grind proves the happy path with real hooks and a tombstone; this
 * pins the critical negative rule: any post-deploy target edit invalidates
 * the handoff and restores ordinary three-way comparison.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/agent/src/Kernel/Canon.php';
require_once $root . '/agent/src/Kernel/OptionState.php';
require_once $root . '/agent/src/Promotion/Deploy.php';
require_once $root . '/agent/src/Apply/ApplyPlanner.php';

use Duo\ApplyPlanner;
use Duo\Canon;
use Duo\Deploy;
use Duo\OptionState;

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$method = new ReflectionMethod(ApplyPlanner::class, 'lifecycle_comparison_hash');
$before = str_repeat('a', 64);
$after = str_repeat('b', 64);
$changedAfter = str_repeat('c', 64);
$transition = [
    'entity' => 'options/core',
    'before_hash' => $before,
    'after_hash' => $after,
];

$check(
    $method->invoke(null, 'options/core', $after, $transition) === $before,
    'an exact post-hook options hash must compare against the recorded pre-hook base'
);
$check(
    $method->invoke(null, 'options/core', $changedAfter, $transition) === $changedAfter,
    'a target edit after deploy must invalidate the handoff'
);
$check(
    $method->invoke(null, 'post/example', $after, $transition) === $after,
    'the lifecycle handoff must never affect another canonical entity'
);
$check(
    $method->invoke(null, 'options/core', $after, null) === $after,
    'an ordinary plan/apply without a promotion handoff must keep the live hash'
);
$check(
    $method->invoke(null, 'options/core', null, $transition) === null,
    'a missing target entity must not be manufactured by the handoff'
);

$recordGate = new ReflectionMethod(Deploy::class, 'unexpected_lifecycle_state_changes');
$missingBinder = new ReflectionMethod(Deploy::class, 'bind_lifecycle_missing_options');
$present = static fn($value): array => OptionState::present($value, 'no');
$beforeDocument = OptionState::document([
    'active_plugins' => $present(['old/old.php']),
    'authored_setting' => $present('old'),
    'unowned_setting' => $present('old'),
]);
$desiredDocument = OptionState::document([
    'active_plugins' => $present(['new/new.php']),
    'authored_setting' => $present('desired'),
    'unowned_setting' => OptionState::absent(),
]);
$safeAfter = OptionState::document([
    'active_plugins' => $present(['new/new.php']),
    'authored_setting' => $present('desired'),
    'unowned_setting' => $present('old'),
]);
$check(
    $recordGate->invoke(null, $beforeDocument, $safeAfter, $desiredDocument) === [],
    'managed lifecycle changes and an authored record already advanced to exact desired state must be composable'
);

$desiredPresent = $present('desired');
$wooLikeBefore = OptionState::document([
    'authored_setting' => OptionState::deleted($desiredPresent, true),
]);
$wooLikeAfter = OptionState::document([
    'authored_setting' => $present('hook-default'),
]);
$wooLikeDesired = OptionState::document([
    'authored_setting' => $desiredPresent,
]);
$check(
    $recordGate->invoke(null, $wooLikeBefore, $wooLikeAfter, $wooLikeDesired) === [],
    'a hook-created authored option may move from a tombstone bound to frozen desired state'
);

$activeKitDesired = OptionState::document([
    'elementor_active_kit' => $present('{{post:kit-uuid}}'),
]);
$activeKitBefore = OptionState::document([
    'elementor_active_kit' => OptionState::deleted(
        OptionState::records($activeKitDesired)['elementor_active_kit']
    ),
    'unrelated_missing' => OptionState::absent(),
]);
$activeKitAfterObserved = OptionState::document([
    // The hook created a target-local kit, but its post identity cannot be
    // tokenized by a non-minting pre-apply snapshot yet.
    'elementor_active_kit' => OptionState::absent(),
    'unrelated_missing' => OptionState::absent(),
]);
$activeKitAfterBound = $missingBinder->invoke(null, $activeKitAfterObserved, $activeKitDesired);
$activeKitAfterRecords = OptionState::records($activeKitAfterBound);
$check(
    ($activeKitAfterRecords['elementor_active_kit']['state'] ?? null) === 'deleted'
        && !array_key_exists('classification_witness', $activeKitAfterRecords['elementor_active_kit'])
        && hash_equals(
            $activeKitAfterRecords['elementor_active_kit']['expected_hash'] ?? '',
            OptionState::record_hash(OptionState::records($activeKitDesired)['elementor_active_kit'])
        ),
    'a post-hook unresolved authored ref is hash-bound without witness-only byte drift'
);
$check(
    ($activeKitAfterRecords['unrelated_missing']['state'] ?? null) === 'absent',
    'a missing option outside frozen desired-present state remains ordinary non-authoritative absence'
);
$check(
    Canon::encode($activeKitBefore) === Canon::encode($activeKitAfterBound),
    'the pre-hook bound tombstone and post-hook unresolved projection are byte-identical'
);
$check(
    $recordGate->invoke(
        null,
        $activeKitBefore,
        $activeKitAfterBound,
        $activeKitDesired
    ) === [],
    'a lifecycle hook may create an unresolved ref-bearing option before apply mints and reconciles its target'
);
$check(
    $recordGate->invoke(
        null,
        $wooLikeBefore,
        OptionState::document(['authored_setting' => OptionState::absent()]),
        $wooLikeDesired
    ) === [],
    'a ref-bearing hook-created option may remain absent when its identity is not yet resolvable'
);

$mismatchedBefore = OptionState::document([
    'authored_setting' => OptionState::deleted($present('other'), true),
]);
$check(
    $recordGate->invoke(null, $mismatchedBefore, $wooLikeAfter, $wooLikeDesired) === ['authored_setting'],
    'a deleted pre-hook record with a mismatched desired hash remains blocked'
);
$check(
    $recordGate->invoke(null, $mismatchedBefore, $wooLikeDesired, $wooLikeDesired) === [],
    'an exact post-hook desired value remains independently safe even when the missing-row proof does not bind'
);
$check(
    $recordGate->invoke(
        null,
        OptionState::document(['authored_setting' => OptionState::absent()]),
        $wooLikeAfter,
        $wooLikeDesired
    ) === ['authored_setting'],
    'ordinary absent-to-present authored changes remain blocked without a bound tombstone'
);

$presentBefore = OptionState::document([
    'authored_setting' => $present('old'),
]);
$staleDesired = OptionState::document([
    'authored_setting' => $present('desired'),
]);
$staleMigratedAfter = OptionState::document([
    'authored_setting' => $present('hook-migrated'),
]);
$check(
    $recordGate->invoke(null, $presentBefore, $staleMigratedAfter, $staleDesired) === ['authored_setting'],
    'a hook migration from a pre-existing authored value remains blocked when it differs from frozen desired state'
);
$absentDesired = OptionState::document([
    'authored_setting' => OptionState::absent(),
]);
$check(
    $recordGate->invoke(null, $wooLikeBefore, $wooLikeAfter, $absentDesired) === ['authored_setting'],
    'a hook-created option cannot override frozen state=absent intent'
);
$deletedDesired = OptionState::document([
    'authored_setting' => OptionState::deleted($present('previous')),
]);
$check(
    $recordGate->invoke(null, $wooLikeBefore, $wooLikeAfter, $deletedDesired) === ['authored_setting'],
    'a hook-created option cannot override frozen deletion intent'
);
$unsafeAfter = OptionState::document([
    'active_plugins' => $present(['new/new.php']),
    'authored_setting' => $present('hook-migrated-but-not-desired'),
    'unowned_setting' => $present('hook-mutated'),
]);
$check(
    $recordGate->invoke(null, $beforeDocument, $unsafeAfter, $desiredDocument)
        === ['authored_setting', 'unowned_setting'],
    'an unrelated or non-desired authored hook migration must block the entity-level handoff'
);
$absentAfter = OptionState::document([
    'active_plugins' => $present(['new/new.php']),
    'authored_setting' => $present('old'),
    'unowned_setting' => OptionState::absent(),
]);
$check(
    $recordGate->invoke(null, $beforeDocument, $absentAfter, $desiredDocument) === ['unowned_setting'],
    'state=absent is not deletion authority and must not authorize a hook-side removal'
);

echo "ok: lifecycle handoff is exact-session/hash, record-scoped, and fails closed on unrelated hook or later target edits\n";
