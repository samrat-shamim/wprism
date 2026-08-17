<?php
namespace Duo;

require_once __DIR__ . '/PromotionSessionJournal.php';

/** Immutable typed view of an unresolved lifecycle hook attempt. */
final class LifecycleAttemptRecord {
    /** @param array<string,mixed> $payload */
    private function __construct(
        private array $payload,
        private string $owner,
        private string $artifactHash
    ) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload, string $owner = '', string $artifactHash = ''): self {
        $keys = array_keys($payload);
        sort($keys, SORT_STRING);
        $full = $keys === ['artifact_hash', 'before_hash', 'entity', 'owner', 'phase'];
        $compact = $keys === ['before_hash', 'entity', 'phase'];
        if ((!$full && !$compact)
            || ($payload['entity'] ?? null) !== 'options/core'
            || !is_string($payload['phase'] ?? null)
            || !in_array($payload['phase'], ['all', 'retire', 'activate'], true)
            || !is_string($payload['before_hash'] ?? null)
            || !preg_match('/^[a-f0-9]{64}$/D', $payload['before_hash'])) {
            throw new \InvalidArgumentException('malformed lifecycle attempt record');
        }
        if ($full) {
            $owner = (string) $payload['owner'];
            $artifactHash = (string) $payload['artifact_hash'];
            if ($owner === '' || !preg_match('/^[a-f0-9]{64}$/D', $artifactHash)) {
                throw new \InvalidArgumentException('malformed lifecycle attempt identity');
            }
        } elseif ($owner === '' || !preg_match('/^[a-f0-9]{64}$/D', $artifactHash)) {
            throw new \InvalidArgumentException('lifecycle attempt requires its session identity');
        }
        return new self($payload, $owner, $artifactHash);
    }

    /** @return array<string,mixed> */
    public function toArray(): array { return $this->payload; }
    public function owner(): string { return $this->owner; }
    public function artifactHash(): string { return $this->artifactHash; }
    public function entity(): string { return (string) $this->payload['entity']; }
    public function phase(): string { return (string) $this->payload['phase']; }
    public function beforeHash(): string { return (string) $this->payload['before_hash']; }
}

/**
 * Durable lifecycle journal.  It owns only the typed lifecycle evidence in
 * `promotion_session`; lease SQL, TTL, and process fencing remain elsewhere.
 */
final class LifecycleJournal {
    public function __construct(
        private string $owner,
        private string $artifactHash
    ) {}

    public function begin(string $entity, string $phase, string $beforeHash): void {
        self::recordBegin($this->owner, $this->artifactHash, $entity, $phase, $beforeHash);
    }

    public function complete(string $entity, string $phase, string $beforeHash, string $afterHash): void {
        self::recordComplete($this->owner, $this->artifactHash, $entity, $phase, $beforeHash, $afterHash);
    }

    public function assertClear(string $context): void {
        self::recordAssertClear($this->owner, $this->artifactHash, $context);
    }

    public function incomplete(): ?LifecycleAttemptRecord {
        $session = self::session($this->owner, $this->artifactHash, false);
        return $session === null ? null : self::attempt($session);
    }

    /** Read-only status for CLI/reporting when no owner has been selected yet. */
    public static function incompleteAny(): ?array {
        $session = PromotionSessionJournal::readAny();
        if ($session === null) {
            return null;
        }
        $attempt = self::attempt($session);
        return $attempt === null ? null : [
            'owner' => $session->owner(),
            'artifact_hash' => $session->artifactHash(),
            ...$attempt->toArray(),
        ];
    }

    public function assertPhaseStart(string $phase): void {
        self::recordAssertPhaseStart($this->owner, $this->artifactHash, $phase);
    }

    public function completePhase(string $phase): void {
        self::recordCompletePhase($this->owner, $this->artifactHash, $phase);
    }

    public function assertComplete(): void {
        self::recordAssertComplete($this->owner, $this->artifactHash);
    }

    public static function recordBegin(
        string $owner,
        string $artifactHash,
        string $entity,
        string $phase,
        string $beforeHash
    ): void {
        self::assertIdentity($owner, $artifactHash);
        self::assertState($entity, $beforeHash, $beforeHash);
        if (!in_array($phase, ['all', 'retire', 'activate'], true)) {
            throw new \RuntimeException("duo: unsupported lifecycle attempt phase '$phase'");
        }
        $session = self::session($owner, $artifactHash, true);
        if (self::attempt($session) !== null) {
            throw new \RuntimeException(
                'duo: unresolved lifecycle attempt must be recovered before another hook window can start'
            );
        }
        $payload = $session->toArray();
        $payload['lifecycle_attempt'] = [
            'entity' => $entity,
            'phase' => $phase,
            'before_hash' => $beforeHash,
        ];
        self::replace($session, $payload);
    }

    public static function recordComplete(
        string $owner,
        string $artifactHash,
        string $entity,
        string $phase,
        string $beforeHash,
        string $afterHash
    ): void {
        self::assertIdentity($owner, $artifactHash);
        self::assertState($entity, $beforeHash, $afterHash);
        $session = self::session($owner, $artifactHash, true);
        $attempt = self::requireAttempt($session);
        self::assertAttemptMatches($attempt, $owner, $artifactHash, $entity, [$phase], $beforeHash);
        $payload = $session->toArray();
        unset($payload['lifecycle_attempt']);
        self::replace($session, $payload);
    }

    public static function recordAssertClear(string $owner, string $artifactHash, string $context): void {
        self::assertIdentity($owner, $artifactHash);
        $session = self::session($owner, $artifactHash, true);
        $attempt = self::attempt($session);
        if ($attempt !== null) {
            throw new \RuntimeException(
                "duo: $context refused — unresolved lifecycle attempt {$attempt->phase()} for {$attempt->entity()} "
                . "started at {$attempt->beforeHash()}; restore the exact pre-lifecycle database checkpoint before retrying"
            );
        }
    }

    public static function recordAssertPhaseStart(string $owner, string $artifactHash, string $phase): void {
        self::assertIdentity($owner, $artifactHash);
        if (!in_array($phase, ['retire', 'activate'], true)) {
            throw new \RuntimeException("duo: unsupported ordered lifecycle phase '$phase'");
        }
        $session = self::session($owner, $artifactHash, true);
        $completed = self::phases($session);
        if (($phase === 'retire' && ($completed === [] || $completed === ['retire']))
            || ($phase === 'activate' && ($completed === ['retire'] || $completed === ['retire', 'activate']))) {
            return;
        }
        throw new \RuntimeException(
            "duo: lifecycle phase '$phase' is out of order for this promotion session; run host retirement then fresh-process activation"
        );
    }

    public static function recordCompletePhase(string $owner, string $artifactHash, string $phase): void {
        self::recordAssertPhaseStart($owner, $artifactHash, $phase);
        $session = self::session($owner, $artifactHash, true);
        if (self::attempt($session) !== null) {
            throw new \RuntimeException("duo: lifecycle phase '$phase' cannot complete with an unresolved hook attempt");
        }
        $completed = self::phases($session);
        if (($phase === 'retire' && $completed === ['retire'])
            || ($phase === 'activate' && $completed === ['retire', 'activate'])) {
            return;
        }
        $payload = $session->toArray();
        $payload['lifecycle_phases'] = $phase === 'retire' ? ['retire'] : ['retire', 'activate'];
        self::replace($session, $payload);
    }

    public static function recordAssertComplete(string $owner, string $artifactHash): void {
        self::assertIdentity($owner, $artifactHash);
        $session = self::session($owner, $artifactHash, true);
        if (self::phases($session) !== ['retire', 'activate']) {
            throw new \RuntimeException(
                'duo: code-finalize refused — this promotion session has not completed lifecycle retirement and fresh-process activation in order'
            );
        }
    }

    private static function session(string $owner, string $artifactHash, bool $required): ?PromotionSessionRecord {
        $session = PromotionSessionJournal::readFor($owner, $artifactHash);
        if ($session === null && $required) {
            throw new \RuntimeException('duo: lifecycle state transition lost its promotion session');
        }
        return $session;
    }

    private static function replace(PromotionSessionRecord $old, array $payload): void {
        PromotionSessionJournal::replaceExact($old, PromotionSessionRecord::fromArray($payload));
    }

    private static function attempt(PromotionSessionRecord $session): ?LifecycleAttemptRecord {
        $attempt = $session->toArray()['lifecycle_attempt'] ?? null;
        return $attempt === null
            ? null
            : LifecycleAttemptRecord::fromArray($attempt, $session->owner(), $session->artifactHash());
    }

    private static function requireAttempt(PromotionSessionRecord $session): LifecycleAttemptRecord {
        $attempt = self::attempt($session);
        if ($attempt === null) {
            throw new \RuntimeException('duo: lifecycle attempt is missing at its completion boundary');
        }
        return $attempt;
    }

    private static function assertAttemptMatches(
        LifecycleAttemptRecord $attempt,
        string $owner,
        string $artifactHash,
        string $entity,
        array $phases,
        string $beforeHash
    ): void {
        if (!hash_equals($owner, $attempt->owner())
            || !hash_equals($artifactHash, $attempt->artifactHash())
            || !hash_equals($entity, $attempt->entity())
            || !in_array($attempt->phase(), $phases, true)
            || !hash_equals($beforeHash, $attempt->beforeHash())) {
            throw new \RuntimeException('duo: lifecycle attempt boundary changed before its successful receipt could be published');
        }
    }

    /** @return list<string> */
    private static function phases(PromotionSessionRecord $session): array {
        $phases = $session->toArray()['lifecycle_phases'] ?? [];
        if (!is_array($phases)
            || array_values($phases) !== $phases
            || !in_array($phases, [[], ['retire'], ['retire', 'activate']], true)) {
            throw new \RuntimeException('duo: malformed lifecycle phase journal');
        }
        return $phases;
    }

    private static function assertIdentity(string $owner, string $artifactHash): void {
        if ($owner === '' || !preg_match('/^[a-f0-9]{64}$/D', $artifactHash)) {
            throw new \RuntimeException('duo: malformed lifecycle journal identity');
        }
    }

    private static function assertState(string $entity, string $beforeHash, string $afterHash): void {
        if ($entity !== 'options/core'
            || !preg_match('/^[a-f0-9]{64}$/D', $beforeHash)
            || !preg_match('/^[a-f0-9]{64}$/D', $afterHash)) {
            throw new \RuntimeException('duo: malformed lifecycle state transition; refusing three-way bypass');
        }
    }
}
