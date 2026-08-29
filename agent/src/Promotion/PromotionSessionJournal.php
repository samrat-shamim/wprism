<?php
namespace WPrism;

/**
 * Immutable typed view of the durable `promotion_session` checkpoint.
 *
 * Lifecycle and state-transition evidence intentionally remains in the
 * payload so older checkpoints round-trip byte-for-byte. This class validates
 * only the identity-bearing envelope; named lifecycle/state journals own the
 * transition policy and use this service's typed compare-and-replace seam.
 */
final class PromotionSessionRecord {
    /** @param array<string,mixed> $payload */
    private function __construct(private array $payload) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self {
        if (!is_string($payload['owner'] ?? null) || $payload['owner'] === ''
            || !is_string($payload['artifact_hash'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', (string) $payload['artifact_hash'])
            || !is_int($payload['begun_at'] ?? null)) {
            throw new \InvalidArgumentException('malformed promotion session record');
        }
        if (array_key_exists('session_id', $payload)) {
            if (!is_string($payload['session_id'])
                || preg_match('/^ps-[a-f0-9]{32}$/D', $payload['session_id']) !== 1) {
                throw new \InvalidArgumentException('malformed promotion session generation');
            }
        }
        if (array_key_exists('lifecycle_attempt', $payload)
            && (!is_array($payload['lifecycle_attempt'])
                || !self::validAttempt($payload['lifecycle_attempt']))) {
            throw new \InvalidArgumentException('malformed promotion lifecycle attempt');
        }
        if (array_key_exists('lifecycle_phases', $payload)
            && (!is_array($payload['lifecycle_phases'])
                || !array_is_list($payload['lifecycle_phases'])
                || !in_array($payload['lifecycle_phases'], [['retire'], ['retire', 'activate']], true))) {
            throw new \InvalidArgumentException('malformed promotion lifecycle phases');
        }
        foreach (['pending_state_transition', 'state_transition'] as $key) {
            if (array_key_exists($key, $payload)
                && (!is_array($payload[$key]) || !self::validTransition($payload[$key]))) {
                throw new \InvalidArgumentException("malformed promotion $key");
            }
        }
        if (array_key_exists('profile', $payload)) {
            self::assertScoped($payload);
        } else {
            foreach (['scoped_allow_deletes', 'scoped_generation', 'scoped_receipt_id', 'scoped_receipt_sha256', 'scoped_scope_hash', 'scoped_signing_key_id', 'scoped_target_id'] as $key) {
                if (array_key_exists($key, $payload)) {
                    throw new \InvalidArgumentException('malformed promotion scoped session metadata');
                }
            }
        }
        return new self($payload);
    }

    /** @return array<string,mixed> */
    public function toArray(): array { return $this->payload; }
    public function owner(): string { return (string) $this->payload['owner']; }
    public function artifactHash(): string { return (string) $this->payload['artifact_hash']; }
    public function begunAt(): int { return (int) $this->payload['begun_at']; }
    public function sessionId(): ?string {
        $value = $this->payload['session_id'] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @param array<string,mixed> $attempt */
    private static function validAttempt(array $attempt): bool {
        $keys = array_keys($attempt);
        sort($keys, SORT_STRING);
        return $keys === ['before_hash', 'entity', 'phase']
            && ($attempt['entity'] ?? null) === 'options/core'
            && is_string($attempt['phase'] ?? null)
            && in_array($attempt['phase'], ['all', 'retire', 'activate'], true)
            && is_string($attempt['before_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $attempt['before_hash']) === 1;
    }

    /** @param array<string,mixed> $transition */
    private static function validTransition(array $transition): bool {
        $keys = array_keys($transition);
        sort($keys, SORT_STRING);
        return $keys === ['after_hash', 'before_hash', 'entity']
            && ($transition['entity'] ?? null) === 'options/core'
            && is_string($transition['before_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $transition['before_hash']) === 1
            && is_string($transition['after_hash'] ?? null)
            && preg_match('/^[a-f0-9]{64}$/D', $transition['after_hash']) === 1;
    }

    /** @param array<string,mixed> $payload */
    private static function assertScoped(array $payload): void {
        $allowed = [
            'owner', 'artifact_hash', 'begun_at', 'session_id', 'profile',
            'scoped_allow_deletes', 'scoped_generation', 'scoped_receipt_id',
            'scoped_receipt_sha256', 'scoped_scope_hash', 'scoped_signing_key_id',
            'scoped_target_id', 'lifecycle_attempt', 'pending_state_transition',
            'state_transition', 'lifecycle_phases',
        ];
        foreach (array_keys($payload) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \InvalidArgumentException('malformed promotion scoped session metadata');
            }
        }
        if ($payload['profile'] !== 'scoped-checkpoint-v1'
            || !is_bool($payload['scoped_allow_deletes'] ?? null)
            || !is_int($payload['scoped_generation'] ?? null)
            || $payload['scoped_generation'] < 1
            || !is_string($payload['scoped_receipt_id'] ?? null)
            || $payload['scoped_receipt_id'] === ''
            || !is_string($payload['scoped_receipt_sha256'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $payload['scoped_receipt_sha256']) !== 1
            || !is_string($payload['scoped_scope_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $payload['scoped_scope_hash']) !== 1
            || !is_string($payload['scoped_signing_key_id'] ?? null)
            || $payload['scoped_signing_key_id'] === ''
            || !is_string($payload['scoped_target_id'] ?? null)
            || $payload['scoped_target_id'] === '') {
            throw new \InvalidArgumentException('malformed promotion scoped session metadata');
        }
    }
}

/**
 * Session-facing journal service.
 *
 * This class owns only the durable `promotion_session` codec and typed
 * compare-and-replace operations. Lease acquisition, heartbeats, process
 * fencing, and release are deliberately not reachable from this journal.
 */
final class PromotionSessionJournal {
    private const KEY = 'promotion_session';

    public function __construct(
        private string $owner,
        private string $artifactHash
    ) {}

    public static function read(): ?PromotionSessionRecord {
        $raw = Ledger::kv_get(self::KEY);
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('wprism: malformed promotion session record; refusing to guess checkpoint ownership');
        }
        return self::assertSession($decoded);
    }

    public static function readAny(): ?PromotionSessionRecord {
        return self::read();
    }

    public static function readFor(string $owner, string $artifactHash): ?PromotionSessionRecord {
        $record = self::read();
        if ($record === null) {
            return null;
        }
        if (!hash_equals($owner, $record->owner())
            || !hash_equals($artifactHash, $record->artifactHash())) {
            throw new \RuntimeException(
                'wprism: promotion session belongs to a different owner/artifact; refusing cross-session inspection'
            );
        }
        return $record;
    }

    public static function write(PromotionSessionRecord $session): void {
        $payload = $session->toArray();
        $encoded = function_exists('wp_json_encode')
            ? wp_json_encode($payload)
            : json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded)) {
            throw new \RuntimeException('wprism: promotion session could not be encoded');
        }
        Ledger::kv_set(self::KEY, $encoded);
    }

    public static function start(
        string $owner,
        string $artifactHash,
        int $begunAt,
        ?string $sessionId = null,
        ?array $metadata = null
    ): PromotionSessionRecord {
        if ($owner === '' || !preg_match('/^[a-f0-9]{64}$/D', $artifactHash) || $begunAt < 1) {
            throw new \InvalidArgumentException('malformed promotion session identity');
        }
        $payload = [
            'owner' => $owner,
            'artifact_hash' => $artifactHash,
            'begun_at' => $begunAt,
        ];
        if ($sessionId !== null) {
            $payload['session_id'] = $sessionId;
        }
        if ($metadata !== null) {
            foreach ($metadata as $key => $value) {
                if (!is_string($key) || array_key_exists($key, $payload)) {
                    throw new \InvalidArgumentException('malformed promotion session scoped metadata');
                }
                $payload[$key] = $value;
            }
        }
        $record = PromotionSessionRecord::fromArray($payload);
        self::write($record);
        return $record;
    }

    public static function replaceExact(
        PromotionSessionRecord $expected,
        PromotionSessionRecord $next
    ): void {
        if (!hash_equals($expected->owner(), $next->owner())
            || !hash_equals($expected->artifactHash(), $next->artifactHash())
            || $expected->begunAt() !== $next->begunAt()
            || ($expected->sessionId() ?? '') !== ($next->sessionId() ?? '')) {
            throw new \RuntimeException(
                'wprism: promotion session transition cannot change its owner, artifact, or generation'
            );
        }
        $expectedPayload = $expected->toArray();
        $nextPayload = $next->toArray();
        if (($expectedPayload['profile'] ?? null) === 'scoped-checkpoint-v1') {
            $scopedKeys = [
                'profile', 'scoped_allow_deletes', 'scoped_generation', 'scoped_receipt_id',
                'scoped_receipt_sha256', 'scoped_scope_hash', 'scoped_signing_key_id', 'scoped_target_id',
            ];
            foreach ($scopedKeys as $key) {
                if (($expectedPayload[$key] ?? null) !== ($nextPayload[$key] ?? null)) {
                    throw new \RuntimeException('wprism: scoped promotion session authority metadata is immutable');
                }
            }
        }
        $current = self::read();
        if ($current === null || !self::same($current, $expected)) {
            throw new \RuntimeException('wprism: promotion session changed before its typed transition');
        }
        self::write($next);
    }

    private static function same(PromotionSessionRecord $left, PromotionSessionRecord $right): bool {
        return self::canonical($left->toArray()) === self::canonical($right->toArray());
    }

    /** @param mixed $value */
    private static function canonical($value): string {
        if (is_array($value)) {
            if (array_is_list($value)) {
                return '[' . implode(',', array_map([self::class, 'canonical'], $value)) . ']';
            }
            ksort($value, SORT_STRING);
            $parts = [];
            foreach ($value as $key => $child) {
                $parts[] = json_encode((string) $key) . ':' . self::canonical($child);
            }
            return '{' . implode(',', $parts) . '}';
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES);
    }

    /** @param array<string,mixed> $session */
    private static function assertSession(array $session): PromotionSessionRecord {
        return PromotionSessionRecord::fromArray($session);
    }

    public function current(): ?PromotionSessionRecord {
        $current = self::read();
        if ($current === null) {
            return null;
        }
        if (!hash_equals($this->owner, $current->owner())
            || !hash_equals($this->artifactHash, $current->artifactHash())) {
            throw new \RuntimeException(
                'wprism: promotion session belongs to a different owner/artifact; refusing cross-session inspection'
            );
        }
        return $current;
    }

}
