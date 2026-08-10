<?php
declare(strict_types=1);

/**
 * Offline adversarial regression for DUO-3344's target-bound apply session.
 *
 * The fake store is deliberately the only dependency: no WordPress bootstrap,
 * globals, database driver, provider, or target API is available here. It
 * exercises the real canonical protocol and its storage CAS seam.
 */

require_once dirname(__DIR__, 2) . '/agent/src/ScopedApplySession.php';

use Duo\Canon;
use Duo\ScopedApplySession;
use Duo\ScopedApplySessionStorage;

final class ScopedApplySessionMemoryStore implements ScopedApplySessionStorage {
    /** @var array<string,?string> */
    public array $values = [];
    public bool $forceConflict = false;

    public function read(string $key): ?string {
        if ($key !== ScopedApplySession::STORAGE_KEY
            && !str_starts_with($key, ScopedApplySession::TERMINAL_KEY_PREFIX)) {
            throw new RuntimeException('unexpected storage key');
        }
        return $this->values[$key] ?? null;
    }

    public function compare_and_swap(string $key, ?string $expected, ?string $replacement): bool {
        if ($key !== ScopedApplySession::STORAGE_KEY
            && !str_starts_with($key, ScopedApplySession::TERMINAL_KEY_PREFIX)) {
            throw new RuntimeException('unexpected storage key');
        }
        if ($this->forceConflict) {
            $this->forceConflict = false;
            return false;
        }
        if (($this->values[$key] ?? null) !== $expected) {
            return false;
        }
        $this->values[$key] = $replacement;
        return true;
    }
}

$checks = 0;
$failures = 0;

$check = static function (bool $condition, string $message) use (&$checks, &$failures): void {
    $checks++;
    echo ($condition ? 'ok: ' : 'FAIL: ') . $message . "\n";
    if (!$condition) {
        $failures++;
    }
};

$expectThrow = static function (callable $operation, string $needle, string $message) use ($check): void {
    try {
        $operation();
        $check(false, $message . ' (did not refuse)');
    } catch (Throwable $failure) {
        $check(str_contains($failure->getMessage(), $needle), $message . ' (' . $failure->getMessage() . ')');
    }
};

$h = static fn(string $seed): string => hash('sha256', $seed);
$artifact = $h('artifact');
$lease = [
    'owner' => 'scoped-owner',
    'artifact_hash' => $artifact,
    'session_id' => 'scoped-session',
];
$workItems = [[
    'identity_hash' => $h('work-identity'),
    'type' => 'post',
    'desired_hash' => $h('work-desired'),
]];
$deletionItems = [[
    'identity_hash' => $h('deletion-identity'),
    'receipt_hash' => $h('deletion-receipt'),
    'deletion_kind' => 'post',
    'deletion_type' => 'page',
]];
$actionItems = [[
    'manifest' => 'core',
    'index' => 0,
    'declaration_hash' => $h('action-declaration'),
]];
$effectItems = [[
    'action_hash' => $h('effect-action-declaration'),
    'effect_hash' => $h('effect-declaration'),
]];
$authority = ScopedApplySession::make_authority(
    $h('scope'),
    [
        'artifact_hash' => $artifact,
        'state_revision_hash' => $h('revision'),
        'manifest_hash' => $h('manifest'),
    ],
    $lease,
    [
        'selected_before_hash' => $h('selected-before'),
        'selected_before_ledger_map_hash' => $h('selected-before-ledger-map'),
        'protected_ledger_map_hash' => $h('protected-ledger-map'),
        'protected_out_of_scope_hash' => $h('protected-out-of-scope'),
        'ledger_roots_hash' => $h('ledger-roots'),
    ],
    [
        'precondition_hash' => $h('precondition'),
        'guard_witnesses_hash' => $h('guard-witnesses'),
    ],
    [
        'work_hash' => ScopedApplySession::hash_value($workItems),
        'work_items' => $workItems,
        'deletions_hash' => ScopedApplySession::hash_value($deletionItems),
        'deletion_items' => $deletionItems,
        'action_declarations_hash' => ScopedApplySession::hash_value($actionItems),
        'action_items' => $actionItems,
        'capabilities_hash' => $h('capabilities'),
        'effects_hash' => ScopedApplySession::hash_value($effectItems),
        'effect_items' => $effectItems,
    ],
    $h('code-witness')
);

$check(
    ScopedApplySession::validate_authority($authority) === $authority,
    'authority is closed, canonical, and self-hashing'
);
$check(
    !str_contains(Canon::encode($authority), 'raw-content') && !str_contains(Canon::encode($authority), 'secret'),
    'authority contains no raw content or secret witness'
);

$store = new ScopedApplySessionMemoryStore();
$session = ScopedApplySession::begin($store, $authority);
$initialBytes = $session->canonical();
$check($session->phase() === ScopedApplySession::PHASE_PLANNED, 'begin stores exactly one planned session');
$check($session->session_id() === $lease['session_id'], 'session identity is the exact lease session id');

$expectThrow(
    static fn() => $session->transition(ScopedApplySession::PHASE_EFFECTS_PENDING),
    'skipped',
    'a skipped phase is refused'
);
$session->transition(ScopedApplySession::PHASE_AUTHORING);

$intent = [
    'ordinal' => 1,
    'authority_hash' => $authority['authority_hash'],
    'lease_hash' => ScopedApplySession::lease_hash($lease),
    'action_hash' => $h('action'),
    'operation_hash' => $h('operation'),
    'input_hash' => $h('input'),
    'effect_hash' => $h('effect'),
    'before_hash' => $h('before'),
];
$session->append_intent($intent);
$beforeIntentBytes = $session->canonical();
$session->append_intent($intent);
$check($session->canonical() === $beforeIntentBytes, 'duplicate identical intent is byte-stable and append-only');

$badIntent = $intent;
$badIntent['operation_hash'] = $h('different-operation');
$expectThrow(
    static fn() => $session->append_intent($badIntent),
    'duplicate intent',
    'duplicate intent with mismatched hashes is refused'
);

$badLeaseIntent = $intent;
$badLeaseIntent['ordinal'] = 2;
$badLeaseIntent['lease_hash'] = $h('foreign-lease');
$expectThrow(
    static fn() => $session->append_intent($badLeaseIntent),
    'lease mismatch',
    'intent bound to a different lease is refused'
);

$session->transition(ScopedApplySession::PHASE_AUTHORED_COMMITTED);
$receipt = $intent + ['after_hash' => $h('after')];
$session->append_receipt($receipt);
$beforeReceiptBytes = $session->canonical();
$session->append_receipt($receipt);
$check($session->canonical() === $beforeReceiptBytes, 'duplicate identical receipt is byte-stable and append-only');

$badReceipt = $receipt;
$badReceipt['effect_hash'] = $h('different-effect');
$expectThrow(
    static fn() => $session->append_receipt($badReceipt),
    'duplicate receipt',
    'duplicate receipt with mismatched hashes is refused'
);

$effectIntent = $intent;
$effectIntent['ordinal'] = 2;
$effectIntent['action_hash'] = $h('effect-action');
$effectIntent['operation_hash'] = $h('effect-operation');
$effectIntent['input_hash'] = $h('effect-input');
$effectIntent['effect_hash'] = $h('effect-effect');
$effectIntent['before_hash'] = $h('effect-before');
$session->append_intent($effectIntent);
$check(
    count($session->intents()) === 2,
    'effect intents may append after the authored transaction is committed'
);
$session->transition(ScopedApplySession::PHASE_EFFECTS_PENDING);
$session->append_receipt($effectIntent + ['after_hash' => $h('effect-after')]);

$convergenceHash = $h('convergence-root');
$terminalTarget = [
    'protected_ledger_map_hash' => $h('protected-map-terminal'),
    'selected_ledger_map_hash' => $h('selected-map-terminal'),
];
$session
    ->transition(ScopedApplySession::PHASE_VERIFYING)
    ->complete($convergenceHash, $terminalTarget);
$terminalBytes = $session->terminal_receipt_bytes();
$terminalIdentity = $session->terminal_identity();
$check($terminalBytes !== null && $terminalIdentity !== null, 'complete publishes a terminal receipt identity');
$check(
    $session->transition(ScopedApplySession::PHASE_COMPLETE)->terminal_receipt_bytes() === $terminalBytes,
    're-running a terminal phase preserves exact terminal receipt bytes'
);
$check(
    ($session->terminal_receipt()['convergence_hash'] ?? null) === $convergenceHash,
    'terminal receipt binds the caller-supplied convergence witness'
);
$check(
    ($session->terminal_receipt()['selected_ledger_map_hash'] ?? null)
        === $terminalTarget['selected_ledger_map_hash']
        && ($session->terminal_receipt()['protected_ledger_map_hash'] ?? null)
        === $terminalTarget['protected_ledger_map_hash'],
    'terminal receipt binds the post-finalization selected/protected identity-map roots'
);
$changedTerminalTarget = $terminalTarget;
$changedTerminalTarget['selected_ledger_map_hash'] = $h('selected-map-drifted');
$expectThrow(
    static fn() => $session->transition(
        ScopedApplySession::PHASE_COMPLETE,
        $convergenceHash,
        $changedTerminalTarget
    ),
    'terminal target identity mismatch',
    'terminal replay refuses a selected identity-map witness that changed after commit'
);

$reopened = ScopedApplySession::begin($store, $authority);
$check($reopened->canonical() === $session->canonical(), 're-opening the terminal authority preserves session identity and bytes');
$check($reopened->terminal_identity() === $terminalIdentity, 'terminal identity is stable across a new process object');
$session->archive_terminal($session->canonical());
$check(($store->values[ScopedApplySession::STORAGE_KEY] ?? null) === null, 'explicit terminal archive clears the active single-session slot');
$archivedReopen = ScopedApplySession::begin($store, $authority);
$check(
    $archivedReopen->terminal_receipt_bytes() === $terminalBytes,
    'archived terminal receipt reopens by exact authority identity'
);

$differentLeaseAuthority = $authority;
$differentLeaseAuthority['lease']['session_id'] = 'other-session';
unset($differentLeaseAuthority['authority_hash']);
$differentLeaseAuthority = ScopedApplySession::seal_authority($differentLeaseAuthority);
$nextSession = ScopedApplySession::begin($store, $differentLeaseAuthority);
$check(
    $nextSession->phase() === ScopedApplySession::PHASE_PLANNED,
    'archiving the terminal session frees the single active slot for a new authority'
);

$store->values[ScopedApplySession::STORAGE_KEY] = $session->canonical();
$tampered = json_decode((string) $store->values[ScopedApplySession::STORAGE_KEY], true, 512, JSON_THROW_ON_ERROR);
$tampered['phase'] = ScopedApplySession::PHASE_VERIFYING;
$store->values[ScopedApplySession::STORAGE_KEY] = Canon::encode($tampered);
$expectThrow(
    static fn() => ScopedApplySession::open($store),
    'phase history',
    'tampering with a persisted phase is detected by the phase history and hash'
);
$store->values[ScopedApplySession::STORAGE_KEY] = $session->canonical();

$tamperedAuthority = json_decode((string) $store->values[ScopedApplySession::STORAGE_KEY], true, 512, JSON_THROW_ON_ERROR);
$tamperedAuthority['authority']['scope_hash'] = $h('edited-scope');
$store->values[ScopedApplySession::STORAGE_KEY] = Canon::encode($tamperedAuthority);
$expectThrow(
    static fn() => ScopedApplySession::open($store),
    'authority hash',
    'tampering with immutable authority evidence is detected'
);
$store->values[ScopedApplySession::STORAGE_KEY] = $session->canonical();

$casStore = new ScopedApplySessionMemoryStore();
$casSession = ScopedApplySession::begin($casStore, $authority);
$casStore->forceConflict = true;
$expectThrow(
    static fn() => $casSession->transition(ScopedApplySession::PHASE_AUTHORING),
    'CAS conflict',
    'a storage compare-and-swap conflict is fail-closed'
);
$casSession->transition(ScopedApplySession::PHASE_AUTHORING);
$check($casSession->phase() === ScopedApplySession::PHASE_AUTHORING, 'the exact operation can retry after a CAS conflict');

$recoveryStore = new ScopedApplySessionMemoryStore();
$recovery = ScopedApplySession::begin($recoveryStore, $authority);
$recovery->transition(ScopedApplySession::PHASE_AUTHORING);
$cause = $h('recovery-cause');
$recovery->recover($cause);
$expectThrow(
    static fn() => $recovery->transition(ScopedApplySession::PHASE_AUTHORED_COMMITTED),
    'exact recovery resume phase',
    'recovery_required blocks an arbitrary phase transition'
);
$expectThrow(
    static fn() => $recovery->resume(ScopedApplySession::PHASE_PLANNED),
    'not exact',
    'recovery cannot resume an earlier or skipped phase'
);
$recovery->resume(ScopedApplySession::PHASE_AUTHORING);
$recovery->transition(ScopedApplySession::PHASE_AUTHORED_COMMITTED);
$check($recovery->phase() === ScopedApplySession::PHASE_AUTHORED_COMMITTED, 'recovery resumes only the exact recorded phase');

echo "scoped apply session checks: $checks\n";
if ($failures !== 0) {
    fwrite(STDERR, "FAIL: $failures scoped apply session checks failed\n");
    exit(1);
}
echo "ok: scoped apply session regression passed\n";
