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
            && !str_starts_with($key, ScopedApplySession::TERMINAL_KEY_PREFIX)
            && !str_starts_with($key, ScopedApplySession::TERMINAL_REQUEST_KEY_PREFIX)) {
            throw new RuntimeException('unexpected storage key');
        }
        return $this->values[$key] ?? null;
    }

    public function compare_and_swap(string $key, ?string $expected, ?string $replacement): bool {
        if ($key !== ScopedApplySession::STORAGE_KEY
            && !str_starts_with($key, ScopedApplySession::TERMINAL_KEY_PREFIX)
            && !str_starts_with($key, ScopedApplySession::TERMINAL_REQUEST_KEY_PREFIX)) {
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
$privateMapUuid = '00000000-0000-4000-8000-000000000099';
$privateMapUuid2 = '00000000-0000-4000-8000-000000000098';
$ledgerMapIdentityHashes = [hash('sha256', $privateMapUuid), hash('sha256', $privateMapUuid2)];
$canonicalLedgerMapIdentityHashes = $ledgerMapIdentityHashes;
sort($canonicalLedgerMapIdentityHashes, SORT_STRING);
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
        'ledger_map_identity_hashes' => $ledgerMapIdentityHashes,
        'ledger_map_identity_set_hash' => ScopedApplySession::hash_value($canonicalLedgerMapIdentityHashes),
    ],
    $h('code-witness')
);

$check(
    ScopedApplySession::validate_authority($authority) === $authority,
    'authority is closed, canonical, and self-hashing'
);
$check(
    !str_contains(Canon::encode($authority), 'raw-content')
        && !str_contains(Canon::encode($authority), 'secret')
        && !str_contains(Canon::encode($authority), $privateMapUuid)
        && !str_contains(Canon::encode($authority), $privateMapUuid2)
        && ($authority['selection']['ledger_map_identity_hashes'] ?? null) === $canonicalLedgerMapIdentityHashes,
    'authority retains only opaque map-identity hashes, never raw content, secret, or UUID'
);

$duplicateMapSelection = $authority;
unset($duplicateMapSelection['authority_hash']);
$duplicateMapSelection['selection']['ledger_map_identity_hashes'] = [
    $canonicalLedgerMapIdentityHashes[0], $canonicalLedgerMapIdentityHashes[0],
];
$duplicateMapSelection['selection']['ledger_map_identity_set_hash'] = ScopedApplySession::hash_value(
    $duplicateMapSelection['selection']['ledger_map_identity_hashes']
);
$expectThrow(
    static fn() => ScopedApplySession::seal_authority($duplicateMapSelection),
    'sorted and unique',
    'authority refuses duplicate opaque map identities instead of silently widening its partition'
);
$mismatchedMapSelection = $authority;
unset($mismatchedMapSelection['authority_hash']);
$mismatchedMapSelection['selection']['ledger_map_identity_set_hash'] = $h('wrong-map-set');
$expectThrow(
    static fn() => ScopedApplySession::seal_authority($mismatchedMapSelection),
    'does not match',
    'authority refuses a map identity set whose sealed digest does not match its canonical members'
);
$rawMapSelection = $authority;
unset($rawMapSelection['authority_hash']);
$rawMapSelection['selection']['ledger_map_identity_hashes'] = [$privateMapUuid];
$rawMapSelection['selection']['ledger_map_identity_set_hash'] = ScopedApplySession::hash_value([$privateMapUuid]);
$expectThrow(
    static fn() => ScopedApplySession::seal_authority($rawMapSelection),
    'invalid opaque identity hash',
    'authority rejects raw target UUIDs at the hash-only durable privacy boundary'
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
$requestReopen = ScopedApplySession::open_terminal_for_request(
    $store,
    (string) $authority['scope_hash'],
    (string) $authority['source']['artifact_hash']
);
$check(
    $requestReopen !== null
        && $requestReopen->terminal_receipt_bytes() === $terminalBytes
        && $requestReopen->authority_hash_value() === $session->authority_hash_value(),
    'archived terminal receipt reopens by stable public scope/source request identity'
);
$check(
    ScopedApplySession::open_terminal_for_request($store, $h('other-scope'), $artifact) === null,
    'a different public scope/source request cannot discover another terminal archive'
);
$requestIndexKey = ScopedApplySession::terminal_request_storage_key(
    (string) $authority['scope_hash'],
    (string) $authority['source']['artifact_hash']
);
$requestIndexBytes = (string) $store->values[$requestIndexKey];
$tamperedRequestIndex = Canon::decode($requestIndexBytes);
$tamperedRequestIndex['terminal_hash'] = $h('tampered-terminal-index');
$store->values[$requestIndexKey] = Canon::encode($tamperedRequestIndex);
$expectThrow(
    static fn() => ScopedApplySession::open_terminal_for_request(
        $store,
        (string) $authority['scope_hash'],
        (string) $authority['source']['artifact_hash']
    ),
    'does not bind',
    'a tampered public terminal index is refused before archived receipt replay'
);
$store->values[$requestIndexKey] = $requestIndexBytes;

$externalWitness = [
    'allow_deletes' => true,
    'generation' => 9,
    'receipt_id' => str_repeat('r', 32),
    'receipt_payload_sha256' => $h('external-receipt'),
    'signing_key_id' => 'offline-key-1',
    'target_id' => str_repeat('t', 32),
];
$externalAuthorityInput = $authority;
unset($externalAuthorityInput['authority_hash']);
$externalAuthorityInput['format'] = ScopedApplySession::EXTERNAL_AUTHORITY_FORMAT;
$externalAuthorityInput['promotion'] = ScopedApplySession::external_promotion_binding($externalWitness, true);
$externalAuthority = ScopedApplySession::seal_authority($externalAuthorityInput);
$expectThrow(
    static fn() => ScopedApplySession::assert_external_promotion($authority, $externalWitness, true),
    'does not match the current external checkpoint generation',
    'legacy v1 ordinary authority is never wildcard replay authority for scoped promotion'
);
$check(
    !str_contains(Canon::encode($externalAuthority), (string) $externalWitness['receipt_id'])
        && !str_contains(Canon::encode($externalAuthority), (string) $externalWitness['target_id'])
        && ($externalAuthority['promotion']['allow_deletes'] ?? null) === true,
    'external authority seals exact generation/delete capability without raw receipt or target identities'
);
ScopedApplySession::assert_external_promotion($externalAuthority, $externalWitness, true);
$expectThrow(
    static fn() => ScopedApplySession::assert_external_promotion($externalAuthority, $externalWitness, false),
    'deletion authority',
    'external authority cannot be replayed with a different delete capability'
);
$externalStore = new ScopedApplySessionMemoryStore();
$externalSession = ScopedApplySession::begin($externalStore, $externalAuthority);
foreach ([
    ScopedApplySession::PHASE_AUTHORING,
    ScopedApplySession::PHASE_AUTHORED_COMMITTED,
    ScopedApplySession::PHASE_EFFECTS_PENDING,
    ScopedApplySession::PHASE_VERIFYING,
] as $phase) {
    $externalSession->transition($phase);
}
$externalSession->complete($h('external-convergence'), $terminalTarget);
$externalSession->archive_terminal();
$externalBindingHash = ScopedApplySession::external_promotion_binding_hash($externalWitness, true);
$externalReopen = ScopedApplySession::open_terminal_for_request(
    $externalStore,
    (string) $externalAuthority['scope_hash'],
    (string) $externalAuthority['source']['artifact_hash'],
    $externalBindingHash
);
$check(
    $externalReopen !== null
        && $externalReopen->authority_hash_value() === $externalAuthority['authority_hash']
        && ScopedApplySession::open_terminal_for_request(
            $externalStore,
            (string) $externalAuthority['scope_hash'],
            (string) $externalAuthority['source']['artifact_hash']
        ) === null,
    'external terminal archive is discoverable only through its exact generation binding'
);
$changedWitness = $externalWitness;
$changedWitness['generation'] = 10;
$changedWitness['receipt_payload_sha256'] = $h('next-external-receipt');
$check(
    ScopedApplySession::open_terminal_for_request(
        $externalStore,
        (string) $externalAuthority['scope_hash'],
        (string) $externalAuthority['source']['artifact_hash'],
        ScopedApplySession::external_promotion_binding_hash($changedWitness, true)
    ) === null,
    'a fresh external generation cannot discover a prior scoped terminal archive'
);

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

$tamperedMapSelection = json_decode((string) $store->values[ScopedApplySession::STORAGE_KEY], true, 512, JSON_THROW_ON_ERROR);
$tamperedMapSelection['authority']['selection']['ledger_map_identity_hashes'][0] = $h('tampered-map-identity');
$store->values[ScopedApplySession::STORAGE_KEY] = Canon::encode($tamperedMapSelection);
$expectThrow(
    static fn() => ScopedApplySession::open($store),
    'sorted and unique',
    'tampering with sealed opaque map selection is detected before session recovery'
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
