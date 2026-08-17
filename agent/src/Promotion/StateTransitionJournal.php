<?php
namespace Duo;

require_once __DIR__ . '/PromotionSessionJournal.php';
require_once __DIR__ . '/LifecycleJournal.php';

/** Immutable typed state-handoff witness. */
final class StateTransitionRecord {
    /** @param array<string,mixed> $payload */
    private function __construct(private array $payload) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        if ($keys !== ['after_hash', 'before_hash', 'entity']
            || ($payload['entity'] ?? null) !== 'options/core'
            || !is_string($payload['before_hash'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $payload['before_hash'])
            || !is_string($payload['after_hash'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $payload['after_hash'])) {
            throw new \InvalidArgumentException('malformed state transition record');
        }
        return new self($payload);
    }

    /** @return array<string,mixed> */
    public function toArray(): array { return $this->payload; }
    public function entity(): string { return (string) $this->payload['entity']; }
    public function beforeHash(): string { return (string) $this->payload['before_hash']; }
    public function afterHash(): string { return (string) $this->payload['after_hash']; }
}

/** Cross-process retirement/activation handoff journal. */
final class StateTransitionJournal {
    public function __construct(
        private string $owner,
        private string $artifactHash
    ) {}

    public function record(string $entity, string $beforeHash, string $afterHash): void {
        self::recordTransition($this->owner, $this->artifactHash, $entity, $beforeHash, $afterHash);
    }

    public function begin(string $entity, string $beforeHash, string $afterHash): void {
        self::beginTransition($this->owner, $this->artifactHash, $entity, $beforeHash, $afterHash);
    }

    public function complete(string $entity, string $beforeHash, string $afterHash): void {
        self::completeTransition($this->owner, $this->artifactHash, $entity, $beforeHash, $afterHash);
    }

    public function hasPending(string $entity): bool {
        $session = self::session($this->owner, $this->artifactHash, false);
        if ($session === null) {
            return false;
        }
        $pending = $session->toArray()['pending_state_transition'] ?? null;
        if ($pending === null) {
            return false;
        }
        return StateTransitionRecord::fromArray($pending)->entity() === $entity;
    }

    public function assertPendingStart(string $entity, string $currentHash): bool {
        self::assertHash($entity, $currentHash, $currentHash);
        if (!$this->hasPending($entity)) {
            return false;
        }
        $session = self::session($this->owner, $this->artifactHash, true);
        $pending = StateTransitionRecord::fromArray($session->toArray()['pending_state_transition'] ?? []);
        if (!hash_equals($currentHash, $pending->afterHash())) {
            throw new \RuntimeException('duo: lifecycle state changed between retirement and activation; activation was not attempted');
        }
        return true;
    }

    public function current(string $entity): ?StateTransitionRecord {
        $session = self::session($this->owner, $this->artifactHash, false);
        if ($session === null) {
            return null;
        }
        $row = $session->toArray()['state_transition'] ?? null;
        if ($row === null) {
            return null;
        }
        $record = StateTransitionRecord::fromArray($row);
        return $record->entity() === $entity ? $record : null;
    }

    public static function recordTransition(string $owner, string $artifactHash, string $entity, string $beforeHash, string $afterHash): void {
        self::assertIdentity($owner, $artifactHash);
        self::assertHash($entity, $beforeHash, $afterHash);
        $session = self::session($owner, $artifactHash, true);
        $payload = self::consumeAttempt($session->toArray(), $owner, $artifactHash, $entity, ['all', 'activate'], $beforeHash, $beforeHash !== $afterHash);
        $payload['state_transition'] = ['entity' => $entity, 'before_hash' => $beforeHash, 'after_hash' => $afterHash];
        self::replace($session, $payload);
    }

    public static function beginTransition(string $owner, string $artifactHash, string $entity, string $beforeHash, string $afterHash): void {
        self::assertIdentity($owner, $artifactHash);
        self::assertHash($entity, $beforeHash, $afterHash);
        $session = self::session($owner, $artifactHash, true);
        $payload = $session->toArray();
        $pending = $payload['pending_state_transition'] ?? null;
        if ($pending !== null) {
            $existing = StateTransitionRecord::fromArray(is_array($pending) ? $pending : []);
            if (hash_equals($entity, $existing->entity())
                && hash_equals($beforeHash, $existing->beforeHash())
                && hash_equals($afterHash, $existing->afterHash())) {
                return;
            }
            throw new \RuntimeException('duo: lifecycle retirement already has a different pending state transition; refusing replacement');
        }
        $payload = self::consumeAttempt($payload, $owner, $artifactHash, $entity, ['retire'], $beforeHash, $beforeHash !== $afterHash);
        $payload['pending_state_transition'] = ['entity' => $entity, 'before_hash' => $beforeHash, 'after_hash' => $afterHash];
        unset($payload['state_transition']);
        self::replace($session, $payload);
    }

    public static function completeTransition(string $owner, string $artifactHash, string $entity, string $beforeHash, string $afterHash): void {
        self::assertIdentity($owner, $artifactHash);
        self::assertHash($entity, $beforeHash, $afterHash);
        $session = self::session($owner, $artifactHash, true);
        $payload = $session->toArray();
        $pending = $payload['pending_state_transition'] ?? null;
        if (!is_array($pending)) {
            throw new \RuntimeException('duo: lifecycle activation has no pending retirement state transition');
        }
        $existing = StateTransitionRecord::fromArray($pending);
        if (!hash_equals($entity, $existing->entity()) || !hash_equals($beforeHash, $existing->afterHash())) {
            throw new \RuntimeException('duo: lifecycle state changed between retirement and activation; refusing three-way bypass');
        }
        $payload = self::consumeAttempt($payload, $owner, $artifactHash, $entity, ['activate'], $beforeHash, $beforeHash !== $afterHash);
        $payload['state_transition'] = ['entity' => $entity, 'before_hash' => $existing->beforeHash(), 'after_hash' => $afterHash];
        unset($payload['pending_state_transition']);
        self::replace($session, $payload);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private static function consumeAttempt(array $payload, string $owner, string $artifactHash, string $entity, array $phases, string $beforeHash, bool $required): array {
        $attempt = $payload['lifecycle_attempt'] ?? null;
        if (!is_array($attempt)) {
            if (!$required) {
                return $payload;
            }
            throw new \RuntimeException('duo: successful lifecycle phase has no matching pre-hook attempt receipt');
        }
        $typed = LifecycleAttemptRecord::fromArray($attempt, $owner, $artifactHash);
        if (!hash_equals($owner, $typed->owner())
            || !hash_equals($artifactHash, $typed->artifactHash())
            || !hash_equals($entity, $typed->entity())
            || !in_array($typed->phase(), $phases, true)
            || !hash_equals($beforeHash, $typed->beforeHash())) {
            throw new \RuntimeException('duo: lifecycle attempt boundary changed before its successful receipt could be published');
        }
        unset($payload['lifecycle_attempt']);
        return $payload;
    }

    private static function session(string $owner, string $artifactHash, bool $required): ?PromotionSessionRecord {
        $session = PromotionSessionJournal::readFor($owner, $artifactHash);
        if ($session === null && $required) {
            throw new \RuntimeException('duo: lifecycle state transition lost its promotion session');
        }
        return $session;
    }

    /** @param array<string,mixed> $payload */
    private static function replace(PromotionSessionRecord $old, array $payload): void {
        PromotionSessionJournal::replaceExact($old, PromotionSessionRecord::fromArray($payload));
    }

    private static function assertIdentity(string $owner, string $artifactHash): void {
        if ($owner === '' || !preg_match('/^[a-f0-9]{64}$/D', $artifactHash)) {
            throw new \RuntimeException('duo: malformed lifecycle journal identity');
        }
    }

    private static function assertHash(string $entity, string $beforeHash, string $afterHash): void {
        if ($entity !== 'options/core'
            || !preg_match('/^[a-f0-9]{64}$/D', $beforeHash)
            || !preg_match('/^[a-f0-9]{64}$/D', $afterHash)) {
            throw new \RuntimeException('duo: malformed lifecycle state transition; refusing three-way bypass');
        }
    }
}
