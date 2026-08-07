<?php
/**
 * Offline safety contract for deploy -> apply's options/core hash handoff.
 * The live grind proves the happy path with real hooks and a tombstone; this
 * pins the critical negative rule: any post-deploy target edit invalidates
 * the handoff and restores ordinary three-way comparison.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/agent/src/Canon.php';
require_once $root . '/agent/src/OptionState.php';
require_once $root . '/agent/src/Deploy.php';
require_once $root . '/agent/src/Apply.php';

use Duo\Apply;
use Duo\Deploy;
use Duo\OptionState;

$check = static function (bool $ok, string $message): void {
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
};

$method = new ReflectionMethod(Apply::class, 'lifecycle_comparison_hash');
$method->setAccessible(true);
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
$recordGate->setAccessible(true);
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
