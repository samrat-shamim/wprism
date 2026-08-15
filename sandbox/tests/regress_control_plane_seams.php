<?php
declare(strict_types=1);

/**
 * DUO-3353: responsibility-focused control-plane seams.
 *
 * This is deliberately offline: it loads the new collaborators without
 * WordPress or a target database, exercises the filesystem/publication value
 * boundaries, and proves malformed sealed records cannot cross the typed
 * journal API.  The existing capture/promotion suites remain the behavioral
 * characterization for the legacy facades.
 */

$root = dirname(__DIR__, 2);
foreach ([
    '/agent/src/Canon.php',
    '/agent/src/DurableFilesystem.php',
    '/agent/src/ProcessFence.php',
    '/agent/src/PromotionLock.php',
    '/agent/src/PromotionLease.php',
    '/agent/src/PromotionSessionJournal.php',
    '/agent/src/LifecycleJournal.php',
    '/agent/src/StateTransitionJournal.php',
    '/recovery/TransitionFaultMatrix.php',
    '/agent/src/Publish.php',
    '/agent/src/AtomicTreePublisher.php',
    '/agent/src/PublicationJournal.php',
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
    'Duo\\AtomicTreePublisher',
    'Duo\\DurableFilesystem',
    'Duo\\PublicationJournal',
    'Duo\\PublicationRecord',
    'Duo\\PromotionLease',
    'Duo\\PromotionLock',
    'Duo\\PromotionSessionJournal',
    'Duo\\PromotionLeaseRecord',
    'Duo\\PromotionSessionRecord',
    'Duo\\LifecycleJournal',
    'Duo\\StateTransitionJournal',
    'Duo\\ProcessFence',
];
foreach ($classes as $class) {
    $check(class_exists($class), "control-plane class $class loads without WordPress");
}

$base = sys_get_temp_dir() . '/duo3353-seams-' . bin2hex(random_bytes(6));
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

$manifest = \Duo\DurableFilesystem::ownershipManifest($candidate);
$check(
    \Duo\Canon::encode($manifest) === \Duo\Canon::encode(\Duo\AtomicTreePublisher::tree_ownership_manifest($candidate)),
    'AtomicTreePublisher delegates ownership manifests to DurableFilesystem without changing bytes/inodes'
);
$check(
    \Duo\DurableFilesystem::treeDigest($candidate) === \Duo\AtomicTreePublisher::tree_digest($candidate),
    'filesystem digest remains identical across the extracted service and legacy publication facade'
);
$check(
    \Duo\DurableFilesystem::directoryIdentity($candidate) === \Duo\AtomicTreePublisher::directory_ownership_identity($candidate),
    'directory identity is shared by the extracted filesystem service and publisher'
);

$journal = new \Duo\PublicationJournal($state);
$intent = $journal->begin($candidate, true);
$check($intent instanceof \Duo\PublicationRecord, 'PublicationJournal returns a typed sealed intent');
$check($intent->format() === 'duo-capture-intent/v1', 'typed intent preserves its wire format');
$check($journal->intent()?->id() === $intent->id(), 'typed readback preserves the exact intent id');
$recoverState = $base . '/recover-state';
mkdir($recoverState, 0775, true);
$recoveryJournal = new \Duo\PublicationJournal($recoverState);
$check($recoveryJournal->recover() === [], 'PublicationJournal instance recovery owns its bound state directory');
$check(\Duo\AtomicTreePublisher::recover($recoverState) === [], 'AtomicTreePublisher facade preserves static recovery calls');
$tampered = $intent->toArray();
$tampered['record_sha256'] = str_repeat('0', 64);
$check($throws(static fn() => \Duo\PublicationRecord::fromArray($tampered)), 'tampered publication seal is refused');
$extra = $intent->toArray();
$extra['unreviewed'] = true;
$check($throws(static fn() => \Duo\PublicationRecord::fromArray($extra)), 'publication records reject untyped extra fields');

$artifact = str_repeat('a', 64);
$lease = \Duo\PromotionLeaseRecord::fromArray([
    'owner' => 'duo3353-owner',
    'artifact_hash' => $artifact,
    'phase' => 'checkpoint',
    'acquired_at' => 1,
    'expires_at' => 2,
    'recovered' => false,
    'session_id' => 'ps-' . str_repeat('b', 32),
]);
$check($lease->artifactHash() === $artifact && $lease->sessionId() !== null, 'lease value object validates owner/artifact/generation');
$session = \Duo\PromotionSessionRecord::fromArray([
    'owner' => 'duo3353-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
    'session_id' => 'ps-' . str_repeat('b', 32),
    'lifecycle_phases' => ['retire'],
]);
$check($session->sessionId() === 'ps-' . str_repeat('b', 32), 'session value object reads the durable session generation, not lease expiry');
$decision = \Duo\PromotionRecoveryDecision::select(
    'verified-rollback-provider',
    'verified_rollback',
    hash('sha256', 'redacted-config-fixture')
);
$check(
    \Duo\PromotionRecoveryDecision::fromArray($decision->toArray())->same($decision),
    'promotion recovery decision round-trips its canonical digest'
);
$decisionTampered = $decision->toArray();
$decisionTampered['profile'] = 'checkpoint-only';
$check(
    $throws(static fn() => \Duo\PromotionRecoveryDecision::fromArray($decisionTampered)),
    'promotion recovery decision refuses provider/profile drift without a new digest'
);
$check($throws(static fn() => \Duo\PromotionSessionRecord::fromArray([
    'owner' => 'duo3353-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
    'session_id' => 'ps-not-a-valid-generation',
])), 'malformed session generations are refused');
$check($throws(static fn() => \Duo\PromotionLeaseRecord::fromArray([
    'owner' => 'duo3353-owner',
    'artifact_hash' => str_repeat('c', 64),
    'phase' => 'checkpoint',
    'acquired_at' => 1,
])), 'incomplete lease records are refused');
$check($throws(static fn() => \Duo\PromotionLeaseRecord::fromArray([
    'owner' => 'duo3353-owner',
    'artifact_hash' => $artifact,
    'phase' => 'checkpoint',
    'acquired_at' => 1,
    'expires_at' => 2,
    'session_id' => 'not-a-generation',
])), 'lease records refuse non-generation session ids');
$check($throws(static fn() => \Duo\PromotionSessionRecord::fromArray([
    'owner' => 'duo3353-owner',
    'artifact_hash' => $artifact,
    'begun_at' => '1',
])), 'session records refuse string begun_at values');
$check($throws(static fn() => \Duo\PromotionSessionRecord::fromArray([
    'owner' => 'duo3353-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
    'lifecycle_attempt' => ['entity' => 'options/core', 'phase' => 'retire', 'before_hash' => 'bad'],
])), 'session records refuse malformed nested lifecycle attempts');
$check($throws(static fn() => \Duo\PromotionSessionRecord::fromArray([
    'owner' => 'duo3353-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
    'pending_state_transition' => null,
])), 'session records refuse null transition witnesses');
$check($throws(static fn() => \Duo\PromotionSessionRecord::fromArray([
    'owner' => 'duo3353-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
    'lifecycle_phases' => [],
])), 'session records refuse an explicit empty lifecycle receipt');
$emptyTimestamp = $intent->toArray();
$emptyTimestamp['created_at'] = '';
$check($throws(static fn() => \Duo\PublicationRecord::fromArray($emptyTimestamp)), 'publication records refuse empty timestamps');
$emptyTimestamp['created_at'] = " \t";
$check($throws(static fn() => \Duo\PublicationRecord::fromArray($emptyTimestamp)), 'publication records refuse whitespace timestamps');

if (!class_exists('Duo\\Ledger', false)) {
    eval('namespace Duo; final class Ledger { public static array $rows = []; public static function kv_get(string $key): ?string { return self::$rows[$key] ?? null; } public static function kv_set(string $key, string $value): void { self::$rows[$key] = $value; } }');
}
$legacyDecision = \Duo\PromotionSessionRecord::fromArray([
    'owner' => 'legacy-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
]);
\Duo\Ledger::$rows['promotion_session'] = json_encode($legacyDecision->toArray(), JSON_THROW_ON_ERROR);
$check(
    !$legacyDecision->isForwardAuthorized()
        && $throws(static fn() => \Duo\PromotionSessionJournal::bindRecoveryDecision(
            'legacy-owner', $artifact, $decision->toArray()
        )),
    'legacy promotion sessions remain recovery-only and cannot be upgraded by a generic binding'
);
$legacyUpgrade = \Duo\PromotionSessionRecord::fromArray(
    $legacyDecision->toArray() + ['recovery_decision' => $decision->toArray()]
);
$check(
    $throws(static fn() => \Duo\PromotionSessionJournal::replaceExact($legacyDecision, $legacyUpgrade)),
    'typed promotion session replacement cannot upgrade a legacy row with a recovery decision'
);
$boundDecisionSession = \Duo\PromotionSessionRecord::fromArray([
    'owner' => 'bound-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
    'session_id' => 'ps-' . str_repeat('d', 32),
    'recovery_decision' => $decision->toArray(),
]);
\Duo\Ledger::$rows['promotion_session'] = json_encode(
    $boundDecisionSession->toArray(),
    JSON_THROW_ON_ERROR
);
$check(
    $boundDecisionSession->isForwardAuthorized()
        && \Duo\PromotionSessionJournal::assertRecoveryDecision(
            'bound-owner', $artifact, $decision->toArray()
        )->toArray() === $boundDecisionSession->toArray(),
    'bound promotion sessions reselect and compare the exact recovery decision'
);
$check(
    $throws(static fn() => \Duo\PromotionSessionJournal::start(
        'untrusted-owner',
        $artifact,
        1,
        'ps-' . str_repeat('e', 32),
        ['recovery_decision' => $decision->toArray()]
    )),
    'generic promotion session starts cannot publish a decision outside the lease-bound transaction'
);
$check(
    $throws(static fn() => \Duo\PromotionSessionJournal::assertRecoveryDecision(
        'bound-owner', $artifact,
        \Duo\PromotionRecoveryDecision::select('other-provider', 'verified_rollback', hash('sha256', 'redacted-config-fixture'))->toArray()
    )),
    'bound promotion sessions refuse recovery provider/profile drift before continuation'
);
$matrix = \Duo\Recovery\TransitionFaultMatrix::load(
    $root . '/recovery/transition-fault-matrix.json'
);
$check(
    ($matrix['format'] ?? null) === 'duo-recovery-transition-fault-matrix/v1'
        && ($matrix['deferrals'] ?? null) === [],
    'recovery transition matrix is versioned and has no deferred authority obligations'
);
$matrixWithUnknownField = $matrix;
$matrixWithUnknownField['transitions'][0]['unexpected'] = true;
$check(
    $throws(static fn() => \Duo\Recovery\TransitionFaultMatrix::validate($matrixWithUnknownField)),
    'recovery transition matrix refuses open row fields'
);
$matrixWithMissingTransition = $matrix;
array_pop($matrixWithMissingTransition['transitions']);
$check(
    $throws(static fn() => \Duo\Recovery\TransitionFaultMatrix::validate($matrixWithMissingTransition)),
    'recovery transition matrix refuses incomplete transition coverage'
);
$check(
    \Duo\Recovery\RecoveryTransitionPolicy::allows('promoting', 'verifying_new', 'ordinary')
        && !\Duo\Recovery\RecoveryTransitionPolicy::allows('verifying_new', 'rollback_pending', 'scoped-checkpoint-v1'),
    'recovery transition policy preserves ordinary rollback and scoped forward-only verification boundaries'
);
\Duo\Ledger::$rows['promotion_session'] = json_encode([
    'owner' => 'journal-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
    'state_transition' => [
        'entity' => 'options/core',
        'before_hash' => str_repeat('b', 64),
        'after_hash' => str_repeat('c', 64),
    ],
], JSON_THROW_ON_ERROR);
$expectedSession = \Duo\PromotionSessionJournal::read();
$foreignSession = \Duo\PromotionSessionRecord::fromArray([
    'owner' => 'other-owner',
    'artifact_hash' => $artifact,
    'begun_at' => 1,
]);
$sessionBytesBeforeForeignReplace = \Duo\Ledger::$rows['promotion_session'];
$check(
    $expectedSession instanceof \Duo\PromotionSessionRecord
        && $throws(static fn() => \Duo\PromotionSessionJournal::replaceExact($expectedSession, $foreignSession))
        && \Duo\Ledger::$rows['promotion_session'] === $sessionBytesBeforeForeignReplace,
    'session journal transitions preserve owner/artifact identity and refuse foreign replacement'
);
$check(
    $throws(static fn() => (new \Duo\StateTransitionJournal('other-owner', $artifact))->current('options/core')),
    'state-transition reads refuse cross-owner session inspection'
);
$foreignBytes = \Duo\Ledger::$rows['promotion_session'];
$check(
    \Duo\PromotionLease::has_pending_state_transition('other-owner', $artifact, 'options/core') === false
        && \Duo\PromotionLease::assert_pending_state_transition_start(
            'other-owner', $artifact, 'options/core', str_repeat('c', 64)
        ) === false
        && \Duo\PromotionLease::state_transition('other-owner', $artifact, 'options/core') === null
        && \Duo\Ledger::$rows['promotion_session'] === $foreignBytes,
    'legacy promotion-lock reads soft-return for a foreign session without inspecting its witnesses'
);
$begunAtChanged = \Duo\PromotionSessionRecord::fromArray([
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
    $throws(static fn() => \Duo\PromotionSessionJournal::replaceExact($expectedSession, $begunAtChanged))
        && \Duo\Ledger::$rows['promotion_session'] === $foreignBytes,
    'legacy session transitions preserve the immutable begun-at identity'
);

$lockSource = file_get_contents($root . '/agent/src/PromotionLease.php');
$lifecycleSource = file_get_contents($root . '/agent/src/LifecycleJournal.php');
$publicationSource = file_get_contents($root . '/agent/src/PublicationJournal.php');
$atomicFacadeSource = file_get_contents($root . '/agent/src/AtomicTreePublisher.php');
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
