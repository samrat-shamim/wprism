<?php
declare(strict_types=1);

namespace Duo;

require_once __DIR__ . '/Canon.php';

/**
 * Durable single-slot storage for a scoped apply session.
 *
 * The value passed to compare_and_swap() is the exact canonical byte string
 * returned by read(). A null replacement removes the key. Implementations
 * must make the comparison and write one atomic operation. A Ledger adapter
 * can map the keys to duo_kv while keeping that database-specific
 * transaction/CAS detail outside this protocol.
 */
interface ScopedApplySessionStorage {
    public function read(string $key): ?string;

    public function compare_and_swap(string $key, ?string $expected, ?string $replacement): bool;
}

/**
 * Bounded, engine-generic protocol for one scoped state apply.
 *
 * The protocol deliberately stores only canonical hashes and opaque bounded
 * identity tokens. It never stores action arguments, entity content, target
 * ids, provider output, credentials, or exception text. ScopeContract remains
 * read-only evidence; this class is the later, target-bound mutation witness.
 */
final class ScopedApplySession {
    public const AUTHORITY_FORMAT = 'duo-scoped-mutation-authority/v1';
    public const SESSION_FORMAT = 'duo-scoped-apply-session/v1';
    public const STORAGE_KEY = 'scoped_apply_session';
    public const TERMINAL_KEY_PREFIX = 'scoped_apply_terminal:';

    public const PHASE_PLANNED = 'planned';
    public const PHASE_AUTHORING = 'authoring';
    public const PHASE_AUTHORED_COMMITTED = 'authored_committed';
    public const PHASE_EFFECTS_PENDING = 'effects_pending';
    public const PHASE_VERIFYING = 'verifying';
    public const PHASE_COMPLETE = 'complete';
    public const PHASE_RECOVERY_REQUIRED = 'recovery_required';

    /** @var list<string> */
    public const PHASES = [
        self::PHASE_PLANNED,
        self::PHASE_AUTHORING,
        self::PHASE_AUTHORED_COMMITTED,
        self::PHASE_EFFECTS_PENDING,
        self::PHASE_VERIFYING,
        self::PHASE_COMPLETE,
        self::PHASE_RECOVERY_REQUIRED,
    ];

    private const HASH_RE = '/^[a-f0-9]{64}$/';
    private const TOKEN_RE = '/^[A-Za-z0-9._:-]{8,128}$/';
    private const TYPE_RE = '/^[A-Za-z0-9._:-]{1,64}$/';
    private const MANIFEST_RE = '/^[A-Za-z0-9._-]{1,128}$/';

    /** @var array<string,string> */
    private const NEXT_PHASE = [
        self::PHASE_PLANNED => self::PHASE_AUTHORING,
        self::PHASE_AUTHORING => self::PHASE_AUTHORED_COMMITTED,
        self::PHASE_AUTHORED_COMMITTED => self::PHASE_EFFECTS_PENDING,
        self::PHASE_EFFECTS_PENDING => self::PHASE_VERIFYING,
        self::PHASE_VERIFYING => self::PHASE_COMPLETE,
    ];

    private ScopedApplySessionStorage $storage;
    private string $storageKey = self::STORAGE_KEY;
    /** @var array<string,mixed>|null */
    private ?array $record = null;
    private ?string $version = null;

    public function __construct(ScopedApplySessionStorage $storage) {
        $this->storage = $storage;
    }

    /**
     * Build and seal the immutable target-bound authority.
     *
     * @param array<string,mixed> $source
     * @param array<string,mixed> $lease
     * @param array<string,mixed> $target
     * @param array<string,mixed> $plan
     * @param array<string,mixed> $selection
     * @return array<string,mixed>
     */
    public static function make_authority(
        string $scopeHash,
        array $source,
        array $lease,
        array $target,
        array $plan,
        array $selection,
        string $codeWitnessHash
    ): array {
        return self::seal_authority([
            'format' => self::AUTHORITY_FORMAT,
            'scope_hash' => $scopeHash,
            'source' => $source,
            'lease' => $lease,
            'target' => $target,
            'plan' => $plan,
            'selection' => $selection,
            'code_witness_hash' => $codeWitnessHash,
        ]);
    }

    /** Alias for callers that prefer a builder name. */
    public static function build_authority(
        string $scopeHash,
        array $source,
        array $lease,
        array $target,
        array $plan,
        array $selection,
        string $codeWitnessHash
    ): array {
        return self::make_authority(
            $scopeHash,
            $source,
            $lease,
            $target,
            $plan,
            $selection,
            $codeWitnessHash
        );
    }

    /**
     * Seal a caller-built authority. Re-sealing an already sealed authority
     * is allowed only when its existing digest is still exact.
     *
     * @param array<string,mixed> $authority
     * @return array<string,mixed>
     */
    public static function seal_authority(array $authority): array {
        $providedHash = $authority['authority_hash'] ?? null;
        unset($authority['authority_hash']);
        if (is_array($authority['selection'] ?? null)) {
            $authority['selection'] = self::canonical_selection($authority['selection']);
        }
        self::assert_authority_base($authority);
        $authority['authority_hash'] = self::digest($authority);
        if ($providedHash !== null) {
            self::assert_hash($providedHash, 'authority_hash');
            if (!hash_equals((string) $providedHash, (string) $authority['authority_hash'])) {
                throw new \RuntimeException('duo: scoped mutation authority hash does not verify');
            }
        }
        return self::canonical_copy($authority);
    }

    /**
     * Validate the closed authority schema and its intrinsic digest.
     *
     * @param array<string,mixed> $authority
     * @return array<string,mixed>
     */
    public static function validate_authority(array $authority): array {
        self::assert_keys($authority, [
            'authority_hash',
            'code_witness_hash',
            'format',
            'lease',
            'plan',
            'scope_hash',
            'selection',
            'source',
            'target',
        ], 'scoped mutation authority');
        if (($authority['format'] ?? null) !== self::AUTHORITY_FORMAT) {
            throw new \RuntimeException('duo: scoped mutation authority has an unsupported format');
        }
        self::assert_hash($authority['scope_hash'] ?? null, 'scope_hash');
        self::assert_hash($authority['code_witness_hash'] ?? null, 'code_witness_hash');
        self::assert_source($authority['source'] ?? null);
        self::assert_lease_shape($authority['lease'] ?? null);
        self::assert_target($authority['target'] ?? null);
        self::assert_plan($authority['plan'] ?? null);
        self::assert_selection($authority['selection'] ?? null);
        self::assert_hash($authority['authority_hash'] ?? null, 'authority_hash');
        $withoutHash = $authority;
        unset($withoutHash['authority_hash']);
        if (!hash_equals(self::digest($withoutHash), (string) $authority['authority_hash'])) {
            throw new \RuntimeException('duo: scoped mutation authority hash does not verify');
        }
        if (!hash_equals(
            (string) $authority['source']['artifact_hash'],
            (string) $authority['lease']['artifact_hash']
        )) {
            throw new \RuntimeException('duo: scoped mutation authority lease artifact does not match source artifact');
        }
        return self::canonical_copy($authority);
    }

    /** Alias for callers that prefer an assertion-style API. */
    public static function assert_authority(array $authority): void {
        self::validate_authority($authority);
    }

    /** Hash one canonical, value-free witness for an integration caller. */
    public static function hash_value(mixed $value): string {
        return hash('sha256', Canon::encode($value));
    }

    /** @param array<string,mixed> $lease */
    public static function lease_hash(array $lease): string {
        self::assert_lease_shape($lease);
        return self::digest($lease);
    }

    /** @param array<string,mixed> $authority */
    public static function authority_hash(array $authority): string {
        return (string) self::validate_authority($authority)['authority_hash'];
    }

    /**
     * Begin or idempotently reopen the one stored session.
     *
     * A different authority can never replace an existing active slot,
     * including a completed terminal session. Call archive_terminal() after
     * completion when the slot must be released for a later authority; the
     * archived terminal remains addressable by its authority hash.
     *
     * @param array<string,mixed> $authority
     */
    public static function begin(ScopedApplySessionStorage $storage, array $authority): self {
        $authority = self::validate_authority($authority);
        $session = new self($storage);
        $raw = $storage->read(self::STORAGE_KEY);
        if ($raw !== null) {
            $record = self::decode_record($raw);
            self::assert_same_authority($record, $authority);
            $session->adopt($record, $raw);
            return $session;
        }

        // A completed session may have been explicitly archived to free the
        // active slot. Reopening by the exact authority remains a terminal,
        // byte-stable read, while a different authority may begin normally.
        $terminalKey = self::terminal_storage_key((string) $authority['authority_hash']);
        $archived = $storage->read($terminalKey);
        if ($archived !== null) {
            $record = self::decode_record($archived);
            self::assert_same_authority($record, $authority);
            if ($record['phase'] !== self::PHASE_COMPLETE) {
                throw new \RuntimeException('duo: scoped apply terminal archive is not complete');
            }
            $session->storageKey = $terminalKey;
            $session->adopt($record, $archived);
            return $session;
        }

        $record = self::new_record($authority);
        $encoded = self::encode_record($record);
        if (!$storage->compare_and_swap(self::STORAGE_KEY, null, $encoded)) {
            $after = $storage->read(self::STORAGE_KEY);
            if ($after === null) {
                throw new \RuntimeException('duo: scoped apply session storage CAS conflict');
            }
            $winner = self::decode_record($after);
            self::assert_same_authority($winner, $authority);
            $session->adopt($winner, $after);
            return $session;
        }
        $readback = $storage->read(self::STORAGE_KEY);
        if ($readback !== $encoded) {
            throw new \RuntimeException('duo: scoped apply session storage write was not durable');
        }
        $session->adopt($record, $encoded);
        return $session;
    }

    /** Return the bounded storage key for an archived terminal identity. */
    public static function terminal_storage_key(string $authorityHash): string {
        self::assert_hash($authorityHash, 'terminal authority_hash');
        return self::TERMINAL_KEY_PREFIX . $authorityHash;
    }

    /**
     * Archive a complete session and clear the active slot with CAS. The
     * complete session itself is retained under its authority hash, so a
     * lost response can still reopen the exact terminal identity later.
     */
    public function archive_terminal(?string $expectedCanonical = null): self {
        $record = $this->current();
        if ($record['phase'] !== self::PHASE_COMPLETE) {
            throw new \RuntimeException('duo: only a complete scoped apply session can be archived');
        }
        $active = $this->storage->read(self::STORAGE_KEY);
        if ($active === null) {
            $archivedKey = self::terminal_storage_key((string) $record['authority_hash']);
            $archived = $this->storage->read($archivedKey);
            if ($archived === null) {
                throw new \RuntimeException('duo: scoped apply terminal archive is missing');
            }
            if ($expectedCanonical !== null && $archived !== $expectedCanonical) {
                throw new \RuntimeException('duo: scoped apply terminal archive expected bytes do not match');
            }
            $this->storageKey = $archivedKey;
            $this->adopt(self::decode_record($archived), $archived);
            return $this;
        }
        $activeRecord = self::decode_record($active);
        $this->assert_owned($activeRecord);
        if ($expectedCanonical !== null && $active !== $expectedCanonical) {
            throw new \RuntimeException('duo: scoped apply terminal archive expected bytes do not match');
        }
        $archivedKey = self::terminal_storage_key((string) $activeRecord['authority_hash']);
        $archived = $this->storage->read($archivedKey);
        if ($archived === null) {
            if (!$this->storage->compare_and_swap($archivedKey, null, $active)) {
                throw new \RuntimeException('duo: scoped apply terminal archive storage CAS conflict');
            }
        } elseif ($archived !== $active) {
            throw new \RuntimeException('duo: scoped apply terminal archive identity is immutable');
        }
        if (!$this->storage->compare_and_swap(self::STORAGE_KEY, $active, null)) {
            throw new \RuntimeException('duo: scoped apply terminal clear storage CAS conflict');
        }
        $this->storageKey = $archivedKey;
        $this->adopt($activeRecord, $active);
        return $this;
    }

    /** Explicit name for callers that treat archive as terminal cleanup. */
    public function clear_terminal(?string $expectedCanonical = null): self {
        return $this->archive_terminal($expectedCanonical);
    }

    /** Load the one stored session, or null before begin(). */
    public static function open(ScopedApplySessionStorage $storage): ?self {
        $session = new self($storage);
        $raw = $storage->read(self::STORAGE_KEY);
        if ($raw === null) {
            return null;
        }
        $session->adopt(self::decode_record($raw), $raw);
        return $session;
    }

    /** Alias for storage-oriented callers. */
    public static function load(ScopedApplySessionStorage $storage): ?self {
        return self::open($storage);
    }

    /**
     * Validate a decoded session record, including append-only rows and
     * phase/recovery topology.
     *
     * @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    public static function validate_session(array $record): array {
        self::assert_keys($record, [
            'authority',
            'authority_hash',
            'format',
            'intents',
            'lease',
            'phase',
            'phase_history',
            'receipts',
            'recovery',
            'session_hash',
            'session_id',
            'terminal_receipt',
        ], 'scoped apply session');
        if (($record['format'] ?? null) !== self::SESSION_FORMAT) {
            throw new \RuntimeException('duo: scoped apply session has an unsupported format');
        }
        self::assert_token($record['session_id'] ?? null, 'session_id');
        $authority = self::validate_authority($record['authority'] ?? []);
        self::assert_hash($record['authority_hash'] ?? null, 'session authority_hash');
        if (!hash_equals((string) $authority['authority_hash'], (string) $record['authority_hash'])) {
            throw new \RuntimeException('duo: scoped apply session authority mismatch');
        }
        self::assert_lease_shape($record['lease'] ?? null);
        if (self::canonical_encode($record['lease']) !== self::canonical_encode($authority['lease'])) {
            throw new \RuntimeException('duo: scoped apply session lease mismatch');
        }
        if (!hash_equals((string) $record['session_id'], (string) $authority['lease']['session_id'])) {
            throw new \RuntimeException('duo: scoped apply session id does not match its lease');
        }
        $phase = $record['phase'] ?? null;
        if (!is_string($phase) || !in_array($phase, self::PHASES, true)) {
            throw new \RuntimeException('duo: scoped apply session has an invalid phase');
        }
        self::assert_phase_history($record['phase_history'] ?? null, $phase, $record['recovery'] ?? null);
        self::assert_intents($record['intents'] ?? null, (string) $record['authority_hash'], $record['lease']);
        self::assert_receipts(
            $record['receipts'] ?? null,
            (string) $record['authority_hash'],
            $record['lease'],
            $record['intents']
        );
        self::assert_terminal($record['terminal_receipt'] ?? null, $phase, $record);
        self::assert_hash($record['session_hash'] ?? null, 'session_hash');
        $withoutHash = $record;
        unset($withoutHash['session_hash']);
        if (!hash_equals(self::digest($withoutHash), (string) $record['session_hash'])) {
            throw new \RuntimeException('duo: scoped apply session hash does not verify');
        }
        return self::canonical_copy($record);
    }

    /** @param array<string,mixed> $record */
    public static function session_hash(array $record): string {
        $record = self::validate_session($record);
        return (string) $record['session_hash'];
    }

    /**
     * Advance exactly one normal phase. Completion requires the caller's
     * canonical post-verification convergence witness plus post-finalization
     * selected/protected map roots; repeating the current terminal phase is
     * safe without supplying them again.
     */
    public function transition(
        string $nextPhase,
        ?string $convergenceHash = null,
        ?array $terminalTarget = null
    ): self {
        if ($nextPhase === self::PHASE_COMPLETE && $convergenceHash !== null) {
            self::assert_hash($convergenceHash, 'convergence_hash');
        }
        if ($terminalTarget !== null) {
            self::assert_terminal_target($terminalTarget);
        }
        $this->mutate(function (array $record) use ($nextPhase, $convergenceHash, $terminalTarget): array {
            $current = (string) $record['phase'];
            if ($nextPhase === $current) {
                if ($current === self::PHASE_COMPLETE && $convergenceHash !== null
                    && !hash_equals(
                        (string) $record['terminal_receipt']['convergence_hash'],
                        $convergenceHash
                    )) {
                    throw new \RuntimeException('duo: scoped apply terminal convergence identity mismatch');
                }
                if ($current === self::PHASE_COMPLETE && $terminalTarget !== null
                    && (!hash_equals(
                        (string) $record['terminal_receipt']['selected_ledger_map_hash'],
                        (string) $terminalTarget['selected_ledger_map_hash']
                    ) || !hash_equals(
                        (string) $record['terminal_receipt']['protected_ledger_map_hash'],
                        (string) $terminalTarget['protected_ledger_map_hash']
                    ))) {
                    throw new \RuntimeException('duo: scoped apply terminal target identity mismatch');
                }
                return $record;
            }
            if ($current === self::PHASE_COMPLETE) {
                throw new \RuntimeException('duo: scoped apply session is terminal; phase transition refused');
            }
            if ($current === self::PHASE_RECOVERY_REQUIRED) {
                throw new \RuntimeException('duo: scoped apply session requires its exact recovery resume phase');
            }
            if ($nextPhase === self::PHASE_RECOVERY_REQUIRED) {
                throw new \RuntimeException('duo: recovery requires the recover() API and a cause hash');
            }
            if ($nextPhase === self::PHASE_COMPLETE
                && ($convergenceHash === null || $terminalTarget === null)) {
                throw new \RuntimeException(
                    'duo: complete requires convergence and post-finalization target witness hashes'
                );
            }
            $expected = self::NEXT_PHASE[$current] ?? null;
            if ($expected !== $nextPhase) {
                throw new \RuntimeException('duo: scoped apply session phase transition skipped or moved backward');
            }
            if ($nextPhase === self::PHASE_VERIFYING) {
                self::assert_receipts_complete($record['intents'], $record['receipts']);
            }
            $record['phase'] = $nextPhase;
            $record['phase_history'][] = $nextPhase;
            if ($nextPhase === self::PHASE_COMPLETE) {
                self::assert_receipts_complete($record['intents'], $record['receipts']);
                $record['terminal_receipt'] = self::build_terminal_receipt(
                    $record,
                    (string) $convergenceHash,
                    $terminalTarget
                );
            }
            return $record;
        });
        return $this;
    }

    /** Alias for a phase-oriented integration caller. */
    public function advance(
        string $nextPhase,
        ?string $convergenceHash = null,
        ?array $terminalTarget = null
    ): self {
        return $this->transition($nextPhase, $convergenceHash, $terminalTarget);
    }

    /** Complete after the fresh-process verifier has produced its witness. */
    public function complete(string $convergenceHash, array $terminalTarget): self {
        return $this->transition(self::PHASE_COMPLETE, $convergenceHash, $terminalTarget);
    }

    /** Alias for integrations that name the verifier result explicitly. */
    public function finalize(string $convergenceHash, array $terminalTarget): self {
        return $this->complete($convergenceHash, $terminalTarget);
    }

    /**
     * Record a value-free recovery gate. The cause is a hash, never an error
     * message or exception text. Repeating the exact gate is idempotent.
     */
    public function recover(string $causeHash): self {
        self::assert_hash($causeHash, 'recovery cause hash');
        $this->mutate(function (array $record) use ($causeHash): array {
            $current = (string) $record['phase'];
            if ($current === self::PHASE_COMPLETE) {
                throw new \RuntimeException('duo: scoped apply session is terminal; recovery is refused');
            }
            if ($current === self::PHASE_RECOVERY_REQUIRED) {
                $recovery = $record['recovery'];
                if (is_array($recovery)
                    && hash_equals((string) $recovery['cause_hash'], $causeHash)) {
                    return $record;
                }
                throw new \RuntimeException('duo: scoped apply session already has a different recovery witness');
            }
            $record['phase'] = self::PHASE_RECOVERY_REQUIRED;
            $record['phase_history'][] = self::PHASE_RECOVERY_REQUIRED;
            $record['recovery'] = [
                'cause_hash' => $causeHash,
                'from_phase' => $current,
            ];
            return $record;
        });
        return $this;
    }

    /**
     * Resume only the exact phase recorded before recovery_required. A caller
     * cannot use recovery as a back door to skip or reorder a normal phase.
     */
    public function resume(string $phase): self {
        $this->mutate(function (array $record) use ($phase): array {
            if ((string) $record['phase'] !== self::PHASE_RECOVERY_REQUIRED
                || !is_array($record['recovery'])) {
                throw new \RuntimeException('duo: scoped apply session has no recovery gate to resume');
            }
            $from = (string) ($record['recovery']['from_phase'] ?? '');
            if ($phase !== $from) {
                throw new \RuntimeException('duo: scoped apply recovery resume phase is not exact');
            }
            $record['phase'] = $from;
            $record['phase_history'][] = $from;
            $record['recovery'] = null;
            return $record;
        });
        return $this;
    }

    /** Alias for callers that spell the operation explicitly. */
    public function resume_recovery(string $phase): self {
        return $this->resume($phase);
    }

    /**
     * Append one mutation intent. The row's ordinal is one-based and must be
     * contiguous. Replaying an identical row is a byte-stable no-op.
     *
     * @param array<string,mixed> $intent
     */
    public function append_intent(array $intent): self {
        $this->mutate(function (array $record) use ($intent): array {
            $row = self::normalize_intent($intent, $record);
            $existing = self::row_at($record['intents'], (int) $row['ordinal']);
            if ($existing !== null) {
                if (self::canonical_encode($existing) === self::canonical_encode($row)) {
                    return $record;
                }
                throw new \RuntimeException('duo: duplicate intent ordinal has mismatched hashes');
            }
            if (!in_array((string) $record['phase'], [
                self::PHASE_AUTHORING,
                self::PHASE_AUTHORED_COMMITTED,
                self::PHASE_EFFECTS_PENDING,
            ], true)) {
                throw new \RuntimeException('duo: scoped apply intent is out of phase');
            }
            $expected = count($record['intents']) + 1;
            if ((int) $row['ordinal'] !== $expected) {
                throw new \RuntimeException('duo: scoped apply intent ordinal is not append-only');
            }
            $record['intents'][] = $row;
            return $record;
        });
        return $this;
    }

    /** Alias for receipt-oriented integration code. */
    public function record_intent(array $intent): self {
        return $this->append_intent($intent);
    }

    /**
     * Append one post-operation receipt. It must match the exact intent at
     * its ordinal, including authority, lease, operation and before hashes.
     * Replaying an identical receipt is a byte-stable no-op.
     *
     * @param array<string,mixed> $receipt
     */
    public function append_receipt(array $receipt): self {
        $this->mutate(function (array $record) use ($receipt): array {
            $row = self::normalize_receipt($receipt, $record);
            $existing = self::row_at($record['receipts'], (int) $row['ordinal']);
            if ($existing !== null) {
                if (self::canonical_encode($existing) === self::canonical_encode($row)) {
                    return $record;
                }
                throw new \RuntimeException('duo: duplicate receipt ordinal has mismatched hashes');
            }
            if (!in_array((string) $record['phase'], [
                self::PHASE_AUTHORED_COMMITTED,
                self::PHASE_EFFECTS_PENDING,
                self::PHASE_VERIFYING,
            ], true)) {
                throw new \RuntimeException('duo: scoped apply receipt is out of phase');
            }
            $intent = self::row_at($record['intents'], (int) $row['ordinal']);
            if ($intent === null) {
                throw new \RuntimeException('duo: scoped apply receipt has no matching intent');
            }
            foreach (['authority_hash', 'lease_hash', 'action_hash', 'operation_hash', 'input_hash', 'effect_hash', 'before_hash'] as $key) {
                if (!hash_equals((string) $intent[$key], (string) $row[$key])) {
                    throw new \RuntimeException('duo: receipt does not match its intent hashes');
                }
            }
            $expected = count($record['receipts']) + 1;
            if ((int) $row['ordinal'] !== $expected) {
                throw new \RuntimeException('duo: scoped apply receipt ordinal is not append-only');
            }
            $record['receipts'][] = $row;
            return $record;
        });
        return $this;
    }

    /** Alias for callers that use the shorter receipt verb. */
    public function record_receipt(array $receipt): self {
        return $this->append_receipt($receipt);
    }

    /** Prove the exact lease tuple currently bound to this session. */
    public function assert_lease(string $owner, string $artifactHash, string $sessionId): void {
        self::assert_token($owner, 'lease owner');
        self::assert_hash($artifactHash, 'lease artifact_hash');
        self::assert_token($sessionId, 'lease session_id');
        $expected = $this->lease();
        if (!hash_equals($owner, (string) $expected['owner'])
            || !hash_equals($artifactHash, (string) $expected['artifact_hash'])
            || !hash_equals($sessionId, (string) $expected['session_id'])) {
            throw new \RuntimeException('duo: scoped apply session lease mismatch');
        }
    }

    public function phase(): string {
        return (string) $this->current()['phase'];
    }

    public function session_id(): string {
        return (string) $this->current()['session_id'];
    }

    public function authority_hash_value(): string {
        return (string) $this->current()['authority_hash'];
    }

    /** @return array<string,mixed> */
    public function authority(): array {
        return self::canonical_copy((array) $this->current()['authority']);
    }

    /** @return array<string,mixed> */
    public function lease(): array {
        return self::canonical_copy((array) $this->current()['lease']);
    }

    /** @return list<array<string,mixed>> */
    public function intents(): array {
        return self::canonical_copy((array) $this->current()['intents']);
    }

    /** @return list<array<string,mixed>> */
    public function receipts(): array {
        return self::canonical_copy((array) $this->current()['receipts']);
    }

    /** @return array<string,mixed> */
    public function to_array(): array {
        return self::canonical_copy($this->current());
    }

    /** Exact canonical bytes currently persisted by the storage seam. */
    public function canonical(): string {
        $this->reload();
        return (string) $this->version;
    }

    /** Refresh this object from storage and revalidate the exact record. */
    public function reload(): self {
        $raw = $this->storage->read($this->storageKey);
        if ($raw === null) {
            throw new \RuntimeException('duo: scoped apply session disappeared from storage');
        }
        $record = self::decode_record($raw);
        $this->assert_owned($record);
        $this->adopt($record, $raw);
        return $this;
    }

    /** @return array<string,mixed>|null */
    public function terminal_receipt(): ?array {
        $terminal = $this->current()['terminal_receipt'];
        return $terminal === null ? null : self::canonical_copy((array) $terminal);
    }

    /** The exact stable terminal receipt bytes, or null before complete. */
    public function terminal_receipt_bytes(): ?string {
        $terminal = $this->terminal_receipt();
        return $terminal === null ? null : Canon::encode($terminal);
    }

    public function terminal_identity(): ?string {
        $terminal = $this->terminal_receipt();
        return $terminal === null ? null : (string) ($terminal['terminal_hash'] ?? null);
    }

    public function is_terminal(): bool {
        return $this->phase() === self::PHASE_COMPLETE;
    }

    public function is_recovery_required(): bool {
        return $this->phase() === self::PHASE_RECOVERY_REQUIRED;
    }

    /** @return array<string,mixed> */
    private static function new_record(array $authority): array {
        $record = [
            'format' => self::SESSION_FORMAT,
            'session_id' => (string) $authority['lease']['session_id'],
            'authority' => $authority,
            'authority_hash' => (string) $authority['authority_hash'],
            'lease' => $authority['lease'],
            'phase' => self::PHASE_PLANNED,
            'phase_history' => [self::PHASE_PLANNED],
            'recovery' => null,
            'intents' => [],
            'receipts' => [],
            'terminal_receipt' => null,
        ];
        $record['session_hash'] = self::digest($record);
        return self::validate_session($record);
    }

    /** @param array<string,mixed> $record */
    private static function encode_record(array $record): string {
        return Canon::encode(self::validate_session($record));
    }

    /** @return array<string,mixed> */
    private static function decode_record(string $raw): array {
        try {
            $decoded = Canon::decode($raw);
        } catch (\Throwable $e) {
            throw new \RuntimeException('duo: scoped apply session storage record is not valid canonical JSON');
        }
        if (!is_array($decoded)) {
            throw new \RuntimeException('duo: scoped apply session storage record is not an object');
        }
        $record = self::validate_session($decoded);
        if (Canon::encode($record) !== $raw) {
            throw new \RuntimeException('duo: scoped apply session storage record is not canonical');
        }
        return $record;
    }

    /**
     * Read, validate, mutate, seal, and CAS one record. No caller-visible
     * object state changes until the exact replacement is read back.
     *
     * @param callable(array<string,mixed>):array<string,mixed> $operation
     */
    private function mutate(callable $operation): void {
        $raw = $this->storage->read($this->storageKey);
        if ($raw === null) {
            throw new \RuntimeException('duo: scoped apply session is missing from storage');
        }
        $record = self::decode_record($raw);
        $this->assert_owned($record);
        $next = $operation($record);
        $next = self::seal_session($next);
        $replacement = Canon::encode($next);
        if ($replacement === $raw) {
            $this->adopt($next, $raw);
            return;
        }
        if (!$this->storage->compare_and_swap($this->storageKey, $raw, $replacement)) {
            throw new \RuntimeException('duo: scoped apply session storage CAS conflict');
        }
        $readback = $this->storage->read($this->storageKey);
        if ($readback !== $replacement) {
            throw new \RuntimeException('duo: scoped apply session storage write was not durable');
        }
        $this->adopt($next, $replacement);
    }

    /** @param array<string,mixed> $record */
    private function adopt(array $record, string $raw): void {
        $this->record = self::validate_session($record);
        $this->version = $raw;
    }

    /** @return array<string,mixed> */
    private function current(): array {
        if ($this->record === null) {
            $this->reload();
        }
        return $this->record ?? [];
    }

    /** @param array<string,mixed> $record */
    private function assert_owned(array $record): void {
        if ($this->record === null) {
            return;
        }
        if (!hash_equals((string) $this->record['session_id'], (string) $record['session_id'])
            || !hash_equals((string) $this->record['authority_hash'], (string) $record['authority_hash'])) {
            throw new \RuntimeException('duo: scoped apply session identity changed in storage');
        }
    }

    /** @param array<string,mixed> $record @param array<string,mixed> $authority */
    private static function assert_same_authority(array $record, array $authority): void {
        if (!hash_equals((string) $record['authority_hash'], (string) $authority['authority_hash'])) {
            throw new \RuntimeException('duo: a different scoped apply session is already active');
        }
        if (self::canonical_encode($record['lease']) !== self::canonical_encode($authority['lease'])) {
            throw new \RuntimeException('duo: active scoped apply session has a lease mismatch');
        }
    }

    /** @param array<string,mixed> $record */
    private static function seal_session(array $record): array {
        unset($record['session_hash']);
        $record['session_hash'] = self::digest($record);
        return self::validate_session($record);
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private static function build_terminal_receipt(
        array $record,
        string $convergenceHash,
        array $terminalTarget
    ): array {
        self::assert_terminal_target($terminalTarget);
        $base = [
            'authority_hash' => (string) $record['authority_hash'],
            'convergence_hash' => $convergenceHash,
            'intents_hash' => self::hash_value($record['intents']),
            'lease_hash' => self::lease_hash($record['lease']),
            'phase' => self::PHASE_COMPLETE,
            'protected_ledger_map_hash' => (string) $terminalTarget['protected_ledger_map_hash'],
            'receipts_hash' => self::hash_value($record['receipts']),
            'selected_ledger_map_hash' => (string) $terminalTarget['selected_ledger_map_hash'],
            'session_id' => (string) $record['session_id'],
        ];
        $base['terminal_hash'] = self::digest($base);
        return $base;
    }

    /** @param array<string,mixed>|null $recovery */
    private static function assert_phase_history(mixed $history, string $phase, mixed $recovery): void {
        if (!is_array($history) || !array_is_list($history) || $history === []
            || $history[0] !== self::PHASE_PLANNED || $history[count($history) - 1] !== $phase) {
            throw new \RuntimeException('duo: scoped apply session phase history is malformed');
        }
        foreach ($history as $item) {
            if (!is_string($item) || !in_array($item, self::PHASES, true)) {
                throw new \RuntimeException('duo: scoped apply session phase history contains an invalid phase');
            }
        }
        for ($i = 1, $n = count($history); $i < $n; $i++) {
            $previous = $history[$i - 1];
            $next = $history[$i];
            if ($next === self::PHASE_RECOVERY_REQUIRED) {
                if ($previous === self::PHASE_COMPLETE || $previous === self::PHASE_RECOVERY_REQUIRED) {
                    throw new \RuntimeException('duo: scoped apply session recovery transition is not monotonic');
                }
                continue;
            }
            if ($previous === self::PHASE_RECOVERY_REQUIRED) {
                $from = $i >= 2 ? $history[$i - 2] : null;
                if (!is_string($from) || $next !== $from) {
                    throw new \RuntimeException('duo: scoped apply recovery transition is not phase-exact');
                }
                continue;
            }
            if ((self::NEXT_PHASE[$previous] ?? null) !== $next) {
                throw new \RuntimeException('duo: scoped apply session phase history skipped a phase');
            }
        }
        if ($phase === self::PHASE_RECOVERY_REQUIRED) {
            self::assert_keys($recovery, ['cause_hash', 'from_phase'], 'scoped apply recovery');
            self::assert_hash($recovery['cause_hash'] ?? null, 'recovery cause hash');
            $from = count($history) >= 2 ? $history[count($history) - 2] : null;
            if ($recovery['from_phase'] !== $from || $from === self::PHASE_COMPLETE) {
                throw new \RuntimeException('duo: scoped apply recovery phase witness is not exact');
            }
        } elseif ($recovery !== null) {
            throw new \RuntimeException('duo: scoped apply session has recovery metadata outside recovery_required');
        }
    }

    /** @param list<array<string,mixed>> $intents @param list<array<string,mixed>> $receipts */
    private static function assert_receipts_complete(array $intents, array $receipts): void {
        if (count($intents) !== count($receipts)) {
            throw new \RuntimeException('duo: scoped apply session has unreceipted mutation intents');
        }
        foreach ($intents as $intent) {
            $receipt = self::row_at($receipts, (int) $intent['ordinal']);
            if ($receipt === null) {
                throw new \RuntimeException('duo: scoped apply session has a missing receipt ordinal');
            }
            foreach (['authority_hash', 'lease_hash', 'action_hash', 'operation_hash', 'input_hash', 'effect_hash', 'before_hash'] as $key) {
                if (!hash_equals((string) $intent[$key], (string) $receipt[$key])) {
                    throw new \RuntimeException('duo: scoped apply receipt no longer matches its intent');
                }
            }
        }
    }

    /** @param mixed $terminal @param array<string,mixed> $record */
    private static function assert_terminal(mixed $terminal, string $phase, array $record): void {
        if ($phase !== self::PHASE_COMPLETE) {
            if ($terminal !== null) {
                throw new \RuntimeException('duo: scoped apply terminal receipt exists before complete');
            }
            return;
        }
        if (!is_array($terminal)) {
            throw new \RuntimeException('duo: scoped apply complete session has no terminal receipt');
        }
        self::assert_keys($terminal, [
            'authority_hash', 'convergence_hash', 'intents_hash', 'lease_hash', 'phase',
            'protected_ledger_map_hash', 'receipts_hash', 'selected_ledger_map_hash',
            'session_id', 'terminal_hash',
        ], 'scoped apply terminal receipt');
        if (($terminal['phase'] ?? null) !== self::PHASE_COMPLETE
            || ($terminal['session_id'] ?? null) !== ($record['session_id'] ?? null)
            || ($terminal['authority_hash'] ?? null) !== ($record['authority_hash'] ?? null)) {
            throw new \RuntimeException('duo: scoped apply terminal receipt identity mismatch');
        }
        self::assert_hash($terminal['convergence_hash'] ?? null, 'terminal convergence_hash');
        self::assert_hash($terminal['intents_hash'] ?? null, 'terminal intents_hash');
        self::assert_hash($terminal['receipts_hash'] ?? null, 'terminal receipts_hash');
        self::assert_hash($terminal['lease_hash'] ?? null, 'terminal lease_hash');
        self::assert_hash($terminal['protected_ledger_map_hash'] ?? null, 'terminal protected_ledger_map_hash');
        self::assert_hash($terminal['selected_ledger_map_hash'] ?? null, 'terminal selected_ledger_map_hash');
        self::assert_hash($terminal['terminal_hash'] ?? null, 'terminal_hash');
        if (!hash_equals((string) $terminal['intents_hash'], self::hash_value($record['intents']))
            || !hash_equals((string) $terminal['receipts_hash'], self::hash_value($record['receipts']))
            || !hash_equals((string) $terminal['lease_hash'], self::lease_hash($record['lease']))) {
            throw new \RuntimeException('duo: scoped apply terminal receipt evidence mismatch');
        }
        $withoutHash = $terminal;
        unset($withoutHash['terminal_hash']);
        if (!hash_equals(self::digest($withoutHash), (string) $terminal['terminal_hash'])) {
            throw new \RuntimeException('duo: scoped apply terminal receipt hash does not verify');
        }
    }

    /** @param mixed $target */
    private static function assert_terminal_target(mixed $target): void {
        self::assert_keys($target, [
            'protected_ledger_map_hash',
            'selected_ledger_map_hash',
        ], 'scoped apply terminal target');
        self::assert_hash($target['protected_ledger_map_hash'] ?? null, 'terminal target protected_ledger_map_hash');
        self::assert_hash($target['selected_ledger_map_hash'] ?? null, 'terminal target selected_ledger_map_hash');
    }

    /** @param mixed $intents @param array<string,mixed> $lease */
    private static function assert_intents(mixed $intents, string $authorityHash, array $lease): void {
        if (!is_array($intents) || !array_is_list($intents)) {
            throw new \RuntimeException('duo: scoped apply intents must be an append-only list');
        }
        $expected = 1;
        $leaseHash = self::lease_hash($lease);
        foreach ($intents as $row) {
            self::assert_intent_row($row);
            if ($row['ordinal'] !== $expected) {
                throw new \RuntimeException('duo: scoped apply intents have a skipped or duplicate ordinal');
            }
            self::assert_row_binding($row, $authorityHash, $leaseHash, 'intent');
            $expected++;
        }
    }

    /** @param mixed $receipts @param array<string,mixed> $lease @param mixed $intents */
    private static function assert_receipts(mixed $receipts, string $authorityHash, array $lease, mixed $intents): void {
        if (!is_array($receipts) || !array_is_list($receipts)) {
            throw new \RuntimeException('duo: scoped apply receipts must be an append-only list');
        }
        $expected = 1;
        $leaseHash = self::lease_hash($lease);
        foreach ($receipts as $row) {
            self::assert_receipt_row($row);
            if ($row['ordinal'] !== $expected) {
                throw new \RuntimeException('duo: scoped apply receipts have a skipped or duplicate ordinal');
            }
            self::assert_row_binding($row, $authorityHash, $leaseHash, 'receipt');
            $intent = self::row_at((array) $intents, (int) $row['ordinal']);
            if ($intent === null) {
                throw new \RuntimeException('duo: scoped apply receipt has no intent ordinal');
            }
            foreach (['authority_hash', 'lease_hash', 'action_hash', 'operation_hash', 'input_hash', 'effect_hash', 'before_hash'] as $key) {
                if (!hash_equals((string) $intent[$key], (string) $row[$key])) {
                    throw new \RuntimeException('duo: scoped apply receipt does not match its intent');
                }
            }
            $expected++;
        }
    }

    /** @param mixed $row */
    private static function assert_intent_row(mixed $row): void {
        self::assert_keys($row, [
            'action_hash', 'authority_hash', 'before_hash', 'effect_hash', 'input_hash', 'lease_hash', 'operation_hash', 'ordinal',
        ], 'scoped apply intent');
        if (!is_int($row['ordinal']) || $row['ordinal'] < 1) {
            throw new \RuntimeException('duo: scoped apply intent ordinal is invalid');
        }
        foreach (['authority_hash', 'lease_hash', 'action_hash', 'operation_hash', 'input_hash', 'effect_hash', 'before_hash'] as $key) {
            self::assert_hash($row[$key], "intent $key");
        }
    }

    /** @param mixed $row */
    private static function assert_receipt_row(mixed $row): void {
        self::assert_keys($row, [
            'action_hash', 'after_hash', 'authority_hash', 'before_hash', 'effect_hash', 'input_hash', 'lease_hash', 'operation_hash', 'ordinal',
        ], 'scoped apply receipt');
        if (!is_int($row['ordinal']) || $row['ordinal'] < 1) {
            throw new \RuntimeException('duo: scoped apply receipt ordinal is invalid');
        }
        foreach (['authority_hash', 'lease_hash', 'action_hash', 'operation_hash', 'input_hash', 'effect_hash', 'before_hash', 'after_hash'] as $key) {
            self::assert_hash($row[$key], "receipt $key");
        }
    }

    /** @param array<string,mixed> $row */
    private static function assert_row_binding(array $row, string $authorityHash, string $leaseHash, string $kind): void {
        if (!hash_equals($authorityHash, (string) $row['authority_hash'])) {
            throw new \RuntimeException("duo: scoped apply $kind authority mismatch");
        }
        if (!hash_equals($leaseHash, (string) $row['lease_hash'])) {
            throw new \RuntimeException("duo: scoped apply $kind lease mismatch");
        }
    }

    /** @param array<string,mixed> $intent @param array<string,mixed> $record @return array<string,mixed> */
    private static function normalize_intent(array $intent, array $record): array {
        self::assert_intent_row($intent);
        self::assert_row_binding($intent, (string) $record['authority_hash'], self::lease_hash($record['lease']), 'intent');
        return [
            'ordinal' => $intent['ordinal'],
            'authority_hash' => $intent['authority_hash'],
            'lease_hash' => $intent['lease_hash'],
            'action_hash' => $intent['action_hash'],
            'operation_hash' => $intent['operation_hash'],
            'input_hash' => $intent['input_hash'],
            'effect_hash' => $intent['effect_hash'],
            'before_hash' => $intent['before_hash'],
        ];
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $record @return array<string,mixed> */
    private static function normalize_receipt(array $receipt, array $record): array {
        self::assert_receipt_row($receipt);
        self::assert_row_binding($receipt, (string) $record['authority_hash'], self::lease_hash($record['lease']), 'receipt');
        return [
            'ordinal' => $receipt['ordinal'],
            'authority_hash' => $receipt['authority_hash'],
            'lease_hash' => $receipt['lease_hash'],
            'action_hash' => $receipt['action_hash'],
            'operation_hash' => $receipt['operation_hash'],
            'input_hash' => $receipt['input_hash'],
            'effect_hash' => $receipt['effect_hash'],
            'before_hash' => $receipt['before_hash'],
            'after_hash' => $receipt['after_hash'],
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private static function row_at(array $rows, int $ordinal): ?array {
        foreach ($rows as $row) {
            if ((int) ($row['ordinal'] ?? 0) === $ordinal) {
                return $row;
            }
        }
        return null;
    }

    /** @param mixed $source */
    private static function assert_source(mixed $source): void {
        self::assert_keys($source, ['artifact_hash', 'manifest_hash', 'state_revision_hash'], 'scoped mutation authority source');
        foreach (['artifact_hash', 'state_revision_hash', 'manifest_hash'] as $key) {
            self::assert_hash($source[$key], "source $key");
        }
    }

    /** @param mixed $lease */
    private static function assert_lease_shape(mixed $lease): void {
        self::assert_keys($lease, ['artifact_hash', 'owner', 'session_id'], 'scoped mutation authority lease');
        self::assert_token($lease['owner'] ?? null, 'lease owner');
        self::assert_hash($lease['artifact_hash'] ?? null, 'lease artifact_hash');
        self::assert_token($lease['session_id'] ?? null, 'lease session_id');
    }

    /** @param mixed $target */
    private static function assert_target(mixed $target): void {
        self::assert_keys($target, [
            'ledger_roots_hash',
            'protected_ledger_map_hash',
            'protected_out_of_scope_hash',
            'selected_before_hash',
            'selected_before_ledger_map_hash',
        ], 'scoped mutation authority target');
        foreach ([
            'selected_before_hash',
            'selected_before_ledger_map_hash',
            'protected_ledger_map_hash',
            'protected_out_of_scope_hash',
            'ledger_roots_hash',
        ] as $key) {
            self::assert_hash($target[$key], "target $key");
        }
    }

    /** @param mixed $plan */
    private static function assert_plan(mixed $plan): void {
        self::assert_keys($plan, ['guard_witnesses_hash', 'precondition_hash'], 'scoped mutation authority plan');
        self::assert_hash($plan['precondition_hash'], 'plan precondition_hash');
        self::assert_hash($plan['guard_witnesses_hash'], 'plan guard_witnesses_hash');
    }

    /** @param mixed $selection */
    private static function assert_selection(mixed $selection): void {
        self::assert_keys($selection, [
            'action_declarations_hash', 'action_items', 'capabilities_hash', 'deletion_items', 'deletions_hash',
            'effect_items', 'effects_hash', 'work_hash', 'work_items',
        ], 'scoped mutation authority selection');
        foreach (['work_hash', 'deletions_hash', 'action_declarations_hash', 'capabilities_hash', 'effects_hash'] as $key) {
            self::assert_hash($selection[$key], "selection $key");
        }
        self::assert_item_list($selection['work_items'], 'work_items', static function (mixed $row): void {
            self::assert_keys($row, ['desired_hash', 'identity_hash', 'type'], 'scoped selection work item');
            self::assert_hash($row['identity_hash'], 'work item identity_hash');
            self::assert_hash($row['desired_hash'], 'work item desired_hash');
            self::assert_type_token($row['type'], 'work item type');
        });
        self::assert_item_list($selection['deletion_items'], 'deletion_items', static function (mixed $row): void {
            self::assert_keys($row, ['deletion_kind', 'deletion_type', 'identity_hash', 'receipt_hash'], 'scoped selection deletion item');
            self::assert_hash($row['identity_hash'], 'deletion item identity_hash');
            self::assert_hash($row['receipt_hash'], 'deletion item receipt_hash');
            self::assert_type_token($row['deletion_kind'], 'deletion item kind');
            self::assert_type_token($row['deletion_type'], 'deletion item type');
        });
        self::assert_item_list($selection['action_items'], 'action_items', static function (mixed $row): void {
            self::assert_keys($row, ['declaration_hash', 'index', 'manifest'], 'scoped selection action item');
            self::assert_hash($row['declaration_hash'], 'action item declaration_hash');
            if (!is_int($row['index']) || $row['index'] < 0) {
                throw new \RuntimeException('duo: scoped selection action item index is invalid');
            }
            self::assert_manifest_token($row['manifest'], 'action item manifest');
        });
        self::assert_item_list($selection['effect_items'], 'effect_items', static function (mixed $row): void {
            self::assert_keys($row, ['action_hash', 'effect_hash'], 'scoped selection effect item');
            self::assert_hash($row['action_hash'], 'effect item action_hash');
            self::assert_hash($row['effect_hash'], 'effect item effect_hash');
        });
        foreach (['work_items', 'deletion_items', 'action_items', 'effect_items'] as $key) {
            $previous = null;
            foreach ($selection[$key] as $row) {
                $encoded = self::canonical_encode($row);
                if ($previous !== null && strcmp($previous, $encoded) >= 0) {
                    throw new \RuntimeException("duo: scoped selection $key must be canonically sorted and unique");
                }
                $previous = $encoded;
            }
        }
        foreach ([
            'work_hash' => 'work_items',
            'deletions_hash' => 'deletion_items',
            'action_declarations_hash' => 'action_items',
            'effects_hash' => 'effect_items',
        ] as $hashKey => $itemsKey) {
            if (!hash_equals((string) $selection[$hashKey], self::hash_value($selection[$itemsKey]))) {
                throw new \RuntimeException("duo: scoped selection $hashKey does not match its retained items");
            }
        }
    }

    /** @param array<string,mixed> $selection @return array<string,mixed> */
    private static function canonical_selection(array $selection): array {
        self::assert_keys($selection, [
            'action_declarations_hash', 'action_items', 'capabilities_hash', 'deletion_items', 'deletions_hash',
            'effect_items', 'effects_hash', 'work_hash', 'work_items',
        ], 'scoped mutation authority selection');
        foreach (['work_items', 'deletion_items', 'action_items', 'effect_items'] as $key) {
            if (!is_array($selection[$key]) || !array_is_list($selection[$key])) {
                throw new \RuntimeException("duo: scoped selection $key must be a list");
            }
            usort(
                $selection[$key],
                static fn(mixed $a, mixed $b): int => strcmp(self::canonical_encode($a), self::canonical_encode($b))
            );
        }
        self::assert_selection($selection);
        return self::canonical_copy($selection);
    }

    /** @param mixed $items @param callable(mixed):void $validator */
    private static function assert_item_list(mixed $items, string $field, callable $validator): void {
        if (!is_array($items) || !array_is_list($items)) {
            throw new \RuntimeException("duo: scoped selection $field must be a list");
        }
        foreach ($items as $row) {
            $validator($row);
        }
    }

    /** @param mixed $value */
    private static function assert_hash(mixed $value, string $field): void {
        if (!is_string($value) || preg_match(self::HASH_RE, $value) !== 1) {
            throw new \RuntimeException("duo: scoped apply $field must be a lowercase SHA-256 hash");
        }
    }

    /** @param mixed $value */
    private static function assert_token(mixed $value, string $field): void {
        if (!is_string($value) || preg_match(self::TOKEN_RE, $value) !== 1) {
            throw new \RuntimeException("duo: scoped apply $field is not a bounded identity token");
        }
    }

    /** @param mixed $value */
    private static function assert_type_token(mixed $value, string $field): void {
        if (!is_string($value) || preg_match(self::TYPE_RE, $value) !== 1) {
            throw new \RuntimeException("duo: scoped apply $field is not a bounded type token");
        }
    }

    /** @param mixed $value */
    private static function assert_manifest_token(mixed $value, string $field): void {
        if (!is_string($value) || preg_match(self::MANIFEST_RE, $value) !== 1) {
            throw new \RuntimeException("duo: scoped apply $field is not a bounded manifest token");
        }
    }

    /** @param mixed $authority */
    private static function assert_authority_base(mixed $authority): void {
        self::assert_keys($authority, [
            'code_witness_hash', 'format', 'lease', 'plan', 'scope_hash', 'selection', 'source', 'target',
        ], 'scoped mutation authority');
        if (($authority['format'] ?? null) !== self::AUTHORITY_FORMAT) {
            throw new \RuntimeException('duo: scoped mutation authority has an unsupported format');
        }
        self::assert_hash($authority['scope_hash'], 'scope_hash');
        self::assert_hash($authority['code_witness_hash'], 'code_witness_hash');
        self::assert_source($authority['source']);
        self::assert_lease_shape($authority['lease']);
        self::assert_target($authority['target']);
        self::assert_plan($authority['plan']);
        self::assert_selection($authority['selection']);
        if (!hash_equals((string) $authority['source']['artifact_hash'], (string) $authority['lease']['artifact_hash'])) {
            throw new \RuntimeException('duo: scoped mutation authority lease artifact does not match source artifact');
        }
    }

    /** @param mixed $value @param list<string> $expected */
    private static function assert_keys(mixed $value, array $expected, string $where): void {
        if (!is_array($value) || array_is_list($value)) {
            throw new \RuntimeException("duo: $where must be a closed object");
        }
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("duo: $where has an unexpected schema");
        }
    }

    /** @param array<string,mixed> $record */
    private static function digest(array $record): string {
        return hash('sha256', Canon::encode($record));
    }

    private static function canonical_encode(mixed $value): string {
        return Canon::encode($value);
    }

    private static function canonical_copy(mixed $value): mixed {
        return Canon::decode(Canon::encode($value));
    }
}
