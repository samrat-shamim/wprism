<?php
declare(strict_types=1);

/**
 * issue #3353: responsibility-focused control-plane seams.
 *
 * This is deliberately offline: it loads the new collaborators without
 * WordPress or a target database, exercises the filesystem/publication value
 * boundaries, and proves malformed sealed records cannot cross the typed
 * journal API.  The existing capture/promotion suites remain the behavioral
 * characterization for the legacy facades.
 */

$root = dirname(__DIR__, 4);
foreach ([
    '/agent/src/Kernel/Canon.php',
    '/agent/src/Kernel/DurableFilesystem.php',
    '/agent/src/Kernel/ProcessFence.php',
    '/agent/src/Promotion/PromotionLock.php',
    '/agent/src/Promotion/PromotionLease.php',
    '/agent/src/Promotion/PromotionSessionJournal.php',
    '/agent/src/Promotion/LifecycleJournal.php',
    '/agent/src/Promotion/StateTransitionJournal.php',
    '/agent/src/Publication/Publish.php',
    '/agent/src/Publication/AtomicTreePublisher.php',
    '/agent/src/Publication/PublicationJournal.php',
] as $relative) {
    require_once $root . $relative;
}

$passed = 0;
$failed = 0;

$check = static function (bool $condition, string $message) use (&$passed, &$failed): void {
    if ($condition) {
        ++$passed;
        echo "ok: $message\n";
        return;
    }
    ++$failed;
    echo "FAIL: $message\n";
};

$throws = static function (callable $callback): bool {
    try {
        $callback();
    } catch (Throwable) {
        return true;
    }
    return false;
};

$classes = [
    'WPrism\\AtomicTreePublisher',
    'WPrism\\DurableFilesystem',
    'WPrism\\PublicationJournal',
    'WPrism\\PublicationRecord',
    'WPrism\\PromotionLease',
    'WPrism\\PromotionLock',
    'WPrism\\PromotionSessionJournal',
    'WPrism\\PromotionLeaseRecord',
    'WPrism\\PromotionSessionRecord',
    'WPrism\\LifecycleJournal',
    'WPrism\\StateTransitionJournal',
    'WPrism\\ProcessFence',
];
foreach ($classes as $class) {
    $check(class_exists($class), "control-plane class $class loads without WordPress");
}

$base = sys_get_temp_dir() . '/control-plane-seams-' . bin2hex(random_bytes(6));
$state = $base . '/state';
$candidate = $base . '/candidate';
mkdir($state, 0775, true);
mkdir($candidate . '/nested', 0775, true);
file_put_contents($candidate . '/nested/value.json', '{"v":1}');
file_put_contents($candidate . '/root.txt', "root\n");

$cleanup = static function () use ($base): void {
    if (!is_dir($base)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $entry) {
        $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
    }
    @rmdir($base);
};
register_shutdown_function($cleanup);

$manifest = \WPrism\DurableFilesystem::ownershipManifest($candidate);
$check(
    \WPrism\Canon::encode($manifest) === \WPrism\Canon::encode(\WPrism\AtomicTreePublisher::tree_ownership_manifest($candidate)),
    'AtomicTreePublisher delegates ownership manifests to DurableFilesystem without changing bytes/inodes'
);
$check(
    \WPrism\DurableFilesystem::treeDigest($candidate) === \WPrism\AtomicTreePublisher::tree_digest($candidate),
    'filesystem digest remains identical across the extracted service and legacy publication facade'
);
$check(
    \WPrism\DurableFilesystem::directoryIdentity($candidate) === \WPrism\AtomicTreePublisher::directory_ownership_identity($candidate),
    'directory identity is shared by the extracted filesystem service and publisher'
);
\WPrism\DurableFilesystem::syncFile($candidate . '/root.txt');
\WPrism\DurableFilesystem::syncDirectory($candidate);
$check(true, 'durable filesystem syncs the witnessed file and directory inodes as hard boundaries');
$disabledSync = [];
$disabledSyncStatus = 0;
exec(
    escapeshellarg(PHP_BINARY) . ' -d disable_functions=fsync -r '
        . escapeshellarg(
            'require ' . var_export($root . '/agent/src/Kernel/DurableFilesystem.php', true) . '; '
            . 'try { \\WPrism\\DurableFilesystem::syncFile(' . var_export($candidate . '/root.txt', true) . '); } '
            . 'catch (Throwable $failure) { exit(str_contains($failure->getMessage(), "sync is unavailable") ? 0 : 2); } '
            . 'exit(3);'
        ),
    $disabledSync,
    $disabledSyncStatus
);
$check($disabledSyncStatus === 0, 'a process without fsync refuses the durability boundary instead of degrading silently');

$journal = new \WPrism\PublicationJournal($state);
$intent = $journal->begin($candidate, true);
$check($intent instanceof \WPrism\PublicationRecord, 'PublicationJournal returns a typed sealed intent');
$check($intent->format() === 'wprism-capture-intent/v1', 'typed intent preserves its wire format');
$check($journal->intent()?->id() === $intent->id(), 'typed readback preserves the exact intent id');
$recoverState = $base . '/recover-state';
mkdir($recoverState, 0775, true);
$recoveryJournal = new \WPrism\PublicationJournal($recoverState);
$check($recoveryJournal->recover() === [], 'PublicationJournal instance recovery owns its bound state directory');
$check(\WPrism\AtomicTreePublisher::recover($recoverState) === [], 'AtomicTreePublisher facade preserves static recovery calls');
$tampered = $intent->toArray();
$tampered['record_sha256'] = str_repeat('0', 64);
$check($throws(static fn() => \WPrism\PublicationRecord::fromArray($tampered)), 'tampered publication seal is refused');
$extra = $intent->toArray();
$extra['unreviewed'] = true;
$check($throws(static fn() => \WPrism\PublicationRecord::fromArray($extra)), 'publication records reject untyped extra fields');

$artifact = str_repeat('a', 64);
$lease = \WPrism\PromotionLeaseRecord::fromArray([
    'owner' => 'control-plane-owner',
    'artifact_hash' => $artifact,
    'phase' => 'checkpoint',
    'acquired_at' => 1,
    'expires_at' => 2,
    'recovered' => false,
    'session_id' => 'ps-' . str_repeat('b', 32),
]);
$check($lease->artifactHash() === $artifact && $lease->sessionId() !== null, 'lease value object validates owner/artifact/generation');
$session = \WPrism\PromotionSessionRecord::fromArray([
    'owner' => 'control-plane-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
    'session_id' => 'ps-' . str_repeat('b', 32),
    'lifecycle_phases' => ['retire'],
]);
$check($session->sessionId() === 'ps-' . str_repeat('b', 32), 'session value object reads the durable session generation, not lease expiry');
$check($throws(static fn() => \WPrism\PromotionSessionRecord::fromArray([
    'owner' => 'control-plane-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
    'session_id' => 'ps-not-a-valid-generation',
])), 'malformed session generations are refused');
$check($throws(static fn() => \WPrism\PromotionLeaseRecord::fromArray([
    'owner' => 'control-plane-owner',
    'artifact_hash' => str_repeat('c', 64),
    'phase' => 'checkpoint',
    'acquired_at' => 1,
])), 'incomplete lease records are refused');
$check($throws(static fn() => \WPrism\PromotionLeaseRecord::fromArray([
    'owner' => 'control-plane-owner',
    'artifact_hash' => $artifact,
    'phase' => 'checkpoint',
    'acquired_at' => 1,
    'expires_at' => 2,
    'session_id' => 'not-a-generation',
])), 'lease records refuse non-generation session ids');
$check($throws(static fn() => \WPrism\PromotionSessionRecord::fromArray([
    'owner' => 'control-plane-owner',
    'artifact_hash' => $artifact,
    'begun_at' => '1',
])), 'session records refuse string begun_at values');
$check($throws(static fn() => \WPrism\PromotionSessionRecord::fromArray([
    'owner' => 'control-plane-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
    'lifecycle_attempt' => ['entity' => 'options/core', 'phase' => 'retire', 'before_hash' => 'bad'],
])), 'session records refuse malformed nested lifecycle attempts');
$check($throws(static fn() => \WPrism\PromotionSessionRecord::fromArray([
    'owner' => 'control-plane-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
    'pending_state_transition' => null,
])), 'session records refuse null transition witnesses');
$check($throws(static fn() => \WPrism\PromotionSessionRecord::fromArray([
    'owner' => 'control-plane-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
    'lifecycle_phases' => [],
])), 'session records refuse an explicit empty lifecycle receipt');
$emptyTimestamp = $intent->toArray();
$emptyTimestamp['created_at'] = '';
$check($throws(static fn() => \WPrism\PublicationRecord::fromArray($emptyTimestamp)), 'publication records refuse empty timestamps');
$emptyTimestamp['created_at'] = " \t";
$check($throws(static fn() => \WPrism\PublicationRecord::fromArray($emptyTimestamp)), 'publication records refuse whitespace timestamps');

if (!class_exists('WPrism\\Ledger', false)) {
    eval('namespace WPrism; final class Ledger { public static array $rows = []; public static function kv_get(string $key): ?string { return self::$rows[$key] ?? null; } public static function kv_set(string $key, string $value): void { self::$rows[$key] = $value; } }');
}
\WPrism\Ledger::$rows['promotion_session'] = json_encode([
    'owner' => 'journal-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
    'state_transition' => [
        'entity' => 'options/core',
        'before_hash' => str_repeat('b', 64),
        'after_hash' => str_repeat('c', 64),
    ],
], JSON_THROW_ON_ERROR);
$expectedSession = \WPrism\PromotionSessionJournal::read();
$foreignSession = \WPrism\PromotionSessionRecord::fromArray([
    'owner' => 'other-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
]);
$sessionBytesBeforeForeignReplace = \WPrism\Ledger::$rows['promotion_session'];
$check(
    $expectedSession instanceof \WPrism\PromotionSessionRecord
        && $throws(static fn() => \WPrism\PromotionSessionJournal::replaceExact($expectedSession, $foreignSession))
        && \WPrism\Ledger::$rows['promotion_session'] === $sessionBytesBeforeForeignReplace,
    'session journal transitions preserve owner/artifact identity and refuse foreign replacement'
);
$check(
    $throws(static fn() => (new \WPrism\StateTransitionJournal('other-owner', $artifact))->current('options/core')),
    'state-transition reads refuse cross-owner session inspection'
);
$foreignBytes = \WPrism\Ledger::$rows['promotion_session'];
$check(
    \WPrism\PromotionLease::has_pending_state_transition('other-owner', $artifact, 'options/core') === false
        && \WPrism\PromotionLease::assert_pending_state_transition_start(
            'other-owner', $artifact, 'options/core', str_repeat('c', 64)
        ) === false
        && \WPrism\PromotionLease::state_transition('other-owner', $artifact, 'options/core') === null
        && \WPrism\Ledger::$rows['promotion_session'] === $foreignBytes,
    'legacy promotion-lock reads soft-return for a foreign session without inspecting its witnesses'
);
$begunAtChanged = \WPrism\PromotionSessionRecord::fromArray([
    'owner' => 'journal-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 2,
    'state_transition' => [
        'entity' => 'options/core',
        'before_hash' => str_repeat('b', 64),
        'after_hash' => str_repeat('c', 64),
    ],
]);
$check(
    $throws(static fn() => \WPrism\PromotionSessionJournal::replaceExact($expectedSession, $begunAtChanged))
        && \WPrism\Ledger::$rows['promotion_session'] === $foreignBytes,
    'legacy session transitions preserve the immutable begun-at identity'
);

$lockSource = file_get_contents($root . '/agent/src/Promotion/PromotionLease.php');
$lifecycleSource = file_get_contents($root . '/agent/src/Promotion/LifecycleJournal.php');
$publicationSource = file_get_contents($root . '/agent/src/Publication/PublicationJournal.php');
$atomicFacadeSource = file_get_contents($root . '/agent/src/Publication/AtomicTreePublisher.php');
$check(is_string($lockSource) && str_contains($lockSource, 'PromotionSessionJournal::readAny()'), 'PromotionLease reads promotion_session through PromotionSessionJournal');
$check(is_string($lockSource) && str_contains($lockSource, 'PromotionSessionJournal::start('), 'PromotionLease starts promotion_session through PromotionSessionJournal');
$check(is_string($lockSource) && str_contains($lockSource, 'ProcessFence::acquire(') && str_contains($lockSource, 'ProcessFence::release()') && str_contains($lockSource, 'invalidate_process_fence_witnesses'), 'PromotionLease delegates advisory-fence ownership to ProcessFence and clears witnesses on discontinuity');
$check(
    is_string($lifecycleSource)
        && str_contains($lifecycleSource, 'PromotionSessionJournal::readFor(')
        && str_contains($lifecycleSource, 'PromotionSessionJournal::replaceExact('),
    'LifecycleJournal binds every status read and transition to the exact session identity'
);
$check(
    is_string($publicationSource)
        && str_contains($publicationSource, 'class PublicationJournal')
        && !str_contains($publicationSource, 'AtomicTreePublisher::')
        && is_string($atomicFacadeSource)
        && str_contains($atomicFacadeSource, "require_once __DIR__ . '/PublicationJournal.php';"),
    'PublicationJournal owns the publication protocol and AtomicTreePublisher is only its compatibility facade'
);

$cleanup();
echo "control-plane seams: $passed passed, $failed failed\n";
exit($failed === 0 ? 0 : 1);
