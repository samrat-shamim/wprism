<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/ProcessFence.php';
require_once __DIR__ . '/PromotionSessionJournal.php';
require_once __DIR__ . '/LifecycleJournal.php';
require_once __DIR__ . '/StateTransitionJournal.php';

/** Immutable typed view of the target-authoritative lease row. */
final class PromotionLeaseRecord {
    /** @param array<string,mixed> $payload */
    private function __construct(private array $payload) {}

    /** @param array<string,mixed> $payload */
    public static function fromArray(array $payload): self {
        foreach (['owner', 'artifact_hash', 'phase'] as $key) {
            if (!is_string($payload[$key] ?? null) || $payload[$key] === '') {
                throw new \InvalidArgumentException('malformed promotion lease record');
            }
        }
        if (!preg_match('/^[a-f0-9]{64}$/D', (string) $payload['artifact_hash'])) {
            throw new \InvalidArgumentException('malformed promotion lease artifact hash');
        }
        foreach (['acquired_at', 'expires_at'] as $key) {
            if (!is_int($payload[$key] ?? null) && !ctype_digit((string) ($payload[$key] ?? ''))) {
                throw new \InvalidArgumentException('malformed promotion lease timestamps');
            }
        }
        if (array_key_exists('recovered', $payload) && !is_bool($payload['recovered'])) {
            throw new \InvalidArgumentException('malformed promotion lease recovery flag');
        }
        if (array_key_exists('session_id', $payload)
            && (!is_string($payload['session_id'])
                || preg_match('/^ps-[a-f0-9]{32}$/D', $payload['session_id']) !== 1)) {
            throw new \InvalidArgumentException('malformed promotion lease generation');
        }
        return new self($payload);
    }

    /** @return array<string,mixed> */
    public function toArray(): array { return $this->payload; }
    public function owner(): string { return (string) $this->payload['owner']; }
    public function artifactHash(): string { return (string) $this->payload['artifact_hash']; }
    public function phase(): string { return (string) $this->payload['phase']; }
    public function acquiredAt(): int { return (int) $this->payload['acquired_at']; }
    public function expiresAt(): int { return (int) $this->payload['expires_at']; }
    public function recovered(): bool { return (bool) ($this->payload['recovered'] ?? false); }
    public function sessionId(): ?string {
        $value = $this->payload['session_id'] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }
}

/**
 * Target-authoritative promotion lease.
 *
 * The host orchestrator runs deploy and apply in separate wp-cli processes,
 * so a process-local mutex or MySQL connection advisory lock cannot span the
 * lifecycle boundary. The lease therefore lives in the target database's
 * duo_kv table. Acquisition is one INSERT ... ON DUPLICATE KEY UPDATE whose
 * conditional assignment admits a still-live current owner or a different
 * owner recovering an expired lease; readback proves who won the race. The
 * host's promotion-begin records its session at checkpoint time. A standalone
 * direct apply instead fences its read-only preflight first, then records the
 * session only after every pre-mutation gate passes. Every explicit
 * orchestrator phase is a continuation of both its host-begun session and its
 * exact still-live row, so an obsolete checkpoint cannot restart—or advertise
 * recovery—after another run.
 *
 * The database row spans separate WP-CLI processes. Each live mutation
 * process additionally holds a connection-scoped MariaDB/MySQL advisory
 * fence. That fence prevents a second process from recovering an expired row
 * while the first is inside an opaque plugin hook or filesystem walk; the
 * continuously fenced process may renew when control returns. A crashed
 * process drops its advisory fence automatically and leaves only the bounded
 * row for a new owner to recover after expiry.
 *
 * These gates serialize Duo writers only. Public reads and unrelated runtime
 * writes remain available; Apply's optimistic recheck is the second half of
 * the safety boundary for authored state changed by live traffic.
 */
/**
 * Target-authoritative promotion lease service.
 *
 * This file owns lease acquisition, renewal, advisory-fence continuity, and
 * release. The historical PromotionLock facade lives in its own loader, so
 * session/lifecycle journals depend on typed session transitions rather than
 * the lease coordinator.
 */
class PromotionLease {
    private const KEY = 'promotion_lock';
    private const DEFAULT_TTL = 300;
    private static ?string $leaseSessionOwner = null;
    private static ?string $leaseSessionArtifact = null;

    /** Begin a new multi-process host session; later host phases only continue it. */
    public static function begin(string $owner, string $artifactHash, ?int $ttl = null): array {
        return self::acquire($owner, $artifactHash, 'checkpoint', $ttl, false);
    }

    /** Begin or resume one receipt-bound, externally checkpointed promotion. */
    public static function begin_scoped(
        string $owner,
        string $artifactHash,
        string $receiptHash,
        string $scopeHash,
        array $authorityWitness,
        ?int $ttl = null
    ): array {
        self::assert_identity($owner, $artifactHash);
        if (preg_match('/^[a-f0-9]{64}$/D', $receiptHash) !== 1) {
            throw new \RuntimeException('duo: scoped promotion requires the signed receipt payload hash');
        }
        self::assert_scoped_authority_witness($authorityWitness, $owner, $artifactHash, $receiptHash, $scopeHash);
        $metadata = self::scoped_session_metadata($authorityWitness, $receiptHash, $scopeHash);
        $session = self::current_session(true);
        if ($session === null) {
            $begun = self::acquire_internal(
                $owner, $artifactHash, 'checkpoint', $ttl, false, true, $metadata, false, true
            );
            $begun['scoped_receipt_sha256'] = $receiptHash;
            $begun['scope_hash'] = $scopeHash;
            return $begun;
        }
        if (($session['profile'] ?? null) !== 'scoped-checkpoint-v1') {
            self::assert_reclaimable_ordinary_session($session);
            if (self::current() !== null) {
                throw new \RuntimeException('duo: scoped promotion begin found a live ordinary target promotion lock');
            }
            $begun = self::acquire_internal(
                $owner, $artifactHash, 'checkpoint', $ttl, false, true, $metadata, true, false
            );
            $begun['scoped_receipt_sha256'] = $receiptHash;
            $begun['scope_hash'] = $scopeHash;
            return $begun;
        }
        if (!hash_equals($owner, (string) ($session['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($session['artifact_hash'] ?? ''))) {
            throw new \RuntimeException('duo: scoped promotion begin found a different target promotion session');
        }
        $sessionId = self::normalized_session_id($session);
        self::assert_scoped_session_id($sessionId);
        self::assert_scoped_profile_session($session, $receiptHash, $scopeHash, $authorityWitness);
        $recovered = self::recover_session($owner, $artifactHash, $sessionId, $ttl);
        $recovered['scoped_receipt_sha256'] = $receiptHash;
        $recovered['scope_hash'] = $scopeHash;
        return $recovered;
    }

    /** Prove that the durable target session matches the exact external witness. */
    public static function assert_scoped_promotion_session(
        string $owner,
        string $artifactHash,
        string $receiptHash,
        string $scopeHash,
        array $authorityWitness
    ): void {
        self::assert_identity($owner, $artifactHash);
        $session = self::current_session();
        if ($session === null
            || !hash_equals($owner, (string) ($session['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($session['artifact_hash'] ?? ''))) {
            throw new \RuntimeException('duo: scoped promotion target session is absent or superseded');
        }
        self::assert_scoped_session_id(self::normalized_session_id($session));
        self::assert_scoped_profile_session($session, $receiptHash, $scopeHash, $authorityWitness);
    }

    /** Refuse unbound ordinary continuation through an external scoped session. */
    public static function assert_no_unbound_scoped_continuation(): void {
        $session = self::current_session();
        if (($session['profile'] ?? null) === 'scoped-checkpoint-v1') {
            throw new \RuntimeException('duo: externally checkpointed scoped promotion requires its exact signed continuation');
        }
    }

    /** @return array{owner:string,artifact_hash:string,phase:string,acquired_at:int,expires_at:int,recovered:bool,session_id:string} */
    public static function acquire(
        string $owner,
        string $artifactHash,
        string $phase,
        ?int $ttl = null,
        bool $requireExisting = false
    ): array {
        return self::acquire_internal(
            $owner,
            $artifactHash,
            $phase,
            $ttl,
            $requireExisting,
            true
        );
    }

    /**
     * Fence a direct apply while its exact selected provider capabilities and
     * final optimistic plan are checked, without yet claiming that a durable
     * promotion session began.  A refusal releases this transient lease and
     * leaves the previous promotion_session byte-for-byte intact.  Apply must
     * call begin_apply_session() before its first durable mutation.
     *
     * @return array{owner:string,artifact_hash:string,phase:string,acquired_at:int,expires_at:int,recovered:bool}
     */
    public static function acquire_apply_preflight(
        string $owner,
        string $artifactHash,
        ?int $ttl = null
    ): array {
        return self::acquire_internal(
            $owner,
            $artifactHash,
            'apply-preflight',
            $ttl,
            false,
            false
        );
    }

    /**
     * Publish the direct-apply session only after every locked pre-mutation
     * gate has passed.  The continuously held lease/process fence proves no
     * other Duo writer can replace the boundary between preflight and this
     * write.  Continuation applies already carry a host-begun session and may
     * not call this entry point.
     */
    public static function begin_apply_session(string $owner, string $artifactHash): void {
        self::assert_identity($owner, $artifactHash);
        $current = self::current();
        if (!self::process_fence_is_continuous()
            || self::$leaseSessionOwner === null
            || !hash_equals(self::$leaseSessionOwner, $owner)
            || self::$leaseSessionArtifact === null
            || !hash_equals(self::$leaseSessionArtifact, $artifactHash)
            || $current === null
            || !hash_equals($owner, (string) ($current['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($current['artifact_hash'] ?? ''))) {
            throw new \RuntimeException(
                'duo: direct apply lost its preflight lease before the promotion session began'
            );
        }
        $existingSession = PromotionSessionJournal::readAny();
        if ($existingSession !== null
            && hash_equals($owner, $existingSession->owner())
            && hash_equals($artifactHash, $existingSession->artifactHash())) {
            throw new \RuntimeException('duo: direct apply promotion session was already begun');
        }
        if (LifecycleJournal::incompleteAny() !== null) {
            throw new \RuntimeException(
                'duo: unresolved lifecycle attempt blocks a new promotion session; restore the exact pre-lifecycle database checkpoint using its original owner/artifact recovery commands'
            );
        }
        // Planning/provider code may legitimately run longer than the row
        // TTL while this process continuously owns the advisory fence. Match
        // heartbeat()'s established rule: that holder may renew when control
        // returns, whereas a disconnected/changed holder may not revive it.
        self::heartbeat($owner, $artifactHash, 'apply-session-begin');
        $now = time();
        $current = self::current();
        if (!self::process_fence_is_continuous()
            || self::$leaseSessionOwner === null
            || !hash_equals(self::$leaseSessionOwner, $owner)
            || self::$leaseSessionArtifact === null
            || !hash_equals(self::$leaseSessionArtifact, $artifactHash)
            || $current === null
            || !hash_equals($owner, (string) ($current['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($current['artifact_hash'] ?? ''))
            || (int) ($current['expires_at'] ?? 0) <= $now) {
            throw new \RuntimeException(
                'duo: direct apply lost its preflight lease before the promotion session began'
            );
        }
        // Direct apply reaches this boundary only after its locked gates.
        // Give scoped authority the same unforgeable crash-recovery
        // generation as host-begun/new acquire() sessions.
        PromotionSessionJournal::start($owner, $artifactHash, $now, 'ps-' . bin2hex(random_bytes(16)));
    }

    /** @return array{owner:string,artifact_hash:string,phase:string,acquired_at:int,expires_at:int,recovered:bool} */
    private static function acquire_internal(
        string $owner,
        string $artifactHash,
        string $phase,
        ?int $ttl,
        bool $requireExisting,
        bool $publishSession,
        ?array $sessionMetadata = null,
        bool $replaceProfilelessOrdinarySession = false,
        bool $requireSessionAbsentAfterFence = false
    ): array {
        global $wpdb;
        self::assert_identity($owner, $artifactHash);
        self::claim_process_fence();
        $replacementTransactionOpen = false;
        try {
            if ($replaceProfilelessOrdinarySession) {
                Db::start('scoped ordinary session replacement transaction start');
                $replacementTransactionOpen = true;
            }
            $ttl = self::ttl($ttl);
            $now = time();
            $before = self::current();
            $preserveRecoverySession = false;
            $existingSession = self::current_session($replaceProfilelessOrdinarySession || $requireSessionAbsentAfterFence);
            if ($requireSessionAbsentAfterFence && $existingSession !== null) {
                throw new \RuntimeException(
                    'duo: scoped promotion initial begin found a target promotion session after fencing; retry so its recovery contract can be classified'
                );
            }
            if ($replaceProfilelessOrdinarySession) {
                self::assert_transactional_replacement_storage();
                if ($before !== null) {
                    throw new \RuntimeException('duo: scoped promotion begin found a live ordinary target promotion lock');
                }
                if ($existingSession === null) {
                    throw new \RuntimeException('duo: scoped promotion ordinary session changed before fenced replacement');
                }
                self::assert_reclaimable_ordinary_session($existingSession);
            }
            if (!$requireExisting) {
                if (($existingSession['profile'] ?? null) === 'scoped-checkpoint-v1') {
                    throw new \RuntimeException(
                        'duo: an externally checkpointed scoped promotion session must reach exact completion or rollback before a new ordinary promotion begins'
                    );
                }
                $attempt = self::session_lifecycle_attempt($existingSession);
                if ($attempt !== null) {
                    if (!is_array($attempt)
                        || !is_array($existingSession)
                        || !hash_equals($owner, (string) ($existingSession['owner'] ?? ''))
                        || !hash_equals($artifactHash, (string) ($existingSession['artifact_hash'] ?? ''))) {
                        throw new \RuntimeException(
                            'duo: unresolved lifecycle attempt blocks a new promotion session; restore the exact pre-lifecycle database checkpoint using its original owner/artifact recovery commands'
                        );
                    }
                    // The exact original owner/artifact may reacquire only so
                    // the documented checkpoint-import sequence can run. Do
                    // not erase or rewrite the ambiguity receipt here; every
                    // lifecycle/apply continuation remains blocked until the
                    // database import restores the pre-hook session row.
                    $preserveRecoverySession = true;
                }
            }
            $session = null;
            if ($requireExisting) {
                $session = self::current_session();
                if ($session === null
                    || !hash_equals($owner, (string) ($session['owner'] ?? ''))
                    || !hash_equals($artifactHash, (string) ($session['artifact_hash'] ?? ''))) {
                    throw new \RuntimeException(
                        'duo: promotion continuation refused — the begun owner/artifact session was superseded'
                    );
                }
                if ($before === null) {
                    throw new \RuntimeException(
                        'duo: promotion continuation refused — no live promotion-begin session exists'
                    );
                }
                if (!hash_equals($owner, (string) ($before['owner'] ?? ''))
                    || !hash_equals($artifactHash, (string) ($before['artifact_hash'] ?? ''))) {
                    throw new \RuntimeException(
                        'duo: promotion continuation refused — the begun owner/artifact session was replaced'
                    );
                }
                if ((int) ($before['expires_at'] ?? 0) <= $now) {
                    throw new \RuntimeException(
                        'duo: promotion continuation refused — the begun session expired before handoff'
                    );
                }
            }
            $payload = self::payload($owner, $artifactHash, $phase, $now, $now + $ttl);
            $table = $wpdb->prefix . 'duo_kv';
            $sql = $wpdb->prepare(
                "INSERT INTO `$table` (k, v) VALUES (%s, %s)
                 ON DUPLICATE KEY UPDATE v = IF(
                    (
                        CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v, '$.expires_at')), '0') AS UNSIGNED) <= %d
                        AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.owner')) <> %s
                    )
                    OR (
                        CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v, '$.expires_at')), '0') AS UNSIGNED) > %d
                        AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.owner')) = %s
                        AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.artifact_hash')) = %s
                    ),
                    VALUES(v), v
                 )",
                self::KEY,
                wp_json_encode($payload),
                $now,
                $owner,
                $now,
                $owner,
                $artifactHash
            );
            Db::query($sql, 'promotion lock acquire');
            $current = self::current();
            if ($current === null || !hash_equals($owner, (string) ($current['owner'] ?? ''))) {
                $heldBy = (string) ($current['owner'] ?? 'unknown');
                $heldPhase = (string) ($current['phase'] ?? 'unknown');
                $expires = (int) ($current['expires_at'] ?? 0);
                throw new \RuntimeException(
                    "duo: promotion lock held by '$heldBy' in phase '$heldPhase' until epoch $expires; "
                    . 'concurrent target mutation refused'
                );
            }
            if ((int) ($current['expires_at'] ?? 0) <= $now) {
                throw new \RuntimeException(
                    'duo: promotion lock expired before handoff; start a new promotion rather than reviving this owner'
                );
            }
            if (!hash_equals($artifactHash, (string) ($current['artifact_hash'] ?? ''))) {
                throw new \RuntimeException('duo: promotion lock owner attempted to change its compiled artifact');
            }
            $current['recovered'] = $before !== null
                && (int) ($before['expires_at'] ?? 0) <= $now
                && (string) ($before['owner'] ?? '') !== $owner;
            self::$leaseSessionOwner = $owner;
            self::$leaseSessionArtifact = $artifactHash;
            if ($publishSession) {
                if (!$requireExisting && !$preserveRecoverySession) {
                    // Owner tokens are operator/run identities and may be
                    // deliberately reused. This random generation is the
                    // durable discriminator a scoped mutation authority binds
                    // to so an expired/replaced lease cannot append evidence
                    // to an older session with the same owner/artifact pair.
                    $session = PromotionSessionJournal::start(
                        $owner,
                        $artifactHash,
                        $now,
                        'ps-' . bin2hex(random_bytes(16)),
                        $sessionMetadata
                    )->toArray();
                } elseif ($session === null) {
                    $session = self::current_session();
                }
                $current['session_id'] = self::normalized_session_id($session);
            }
            if ($replacementTransactionOpen) {
                Db::commit('scoped ordinary session replacement transaction commit');
                $replacementTransactionOpen = false;
            }
            return $current;
        } catch (\Throwable $t) {
            if ($replacementTransactionOpen) {
                try {
                    Db::rollback('scoped ordinary session replacement transaction rollback');
                } catch (\Throwable $rollback) {
                    self::release_process_fence();
                    throw new \RuntimeException(
                        'duo: scoped ordinary session replacement failed and rollback could not be confirmed: '
                        . $rollback->getMessage(),
                        0,
                        $t
                    );
                }
            }
            self::release_process_fence();
            throw $t;
        }
    }

    public static function heartbeat(string $owner, string $artifactHash, string $phase, ?int $ttl = null): void {
        global $wpdb;
        self::assert_identity($owner, $artifactHash);
        $continuous = self::process_fence_is_continuous()
            && self::$leaseSessionOwner !== null
            && hash_equals(self::$leaseSessionOwner, $owner)
            && self::$leaseSessionArtifact !== null
            && hash_equals(self::$leaseSessionArtifact, $artifactHash);
        self::claim_process_fence();
        $ttl = self::ttl($ttl);
        $now = time();
        $current = self::current();
        if ($current === null
            || !hash_equals($owner, (string) ($current['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($current['artifact_hash'] ?? ''))
            || ((int) ($current['expires_at'] ?? 0) <= $now && !$continuous)) {
            throw new \RuntimeException('duo: promotion lock lost or expired; mutation refused');
        }
        $payload = self::payload(
            $owner,
            $artifactHash,
            $phase,
            (int) ($current['acquired_at'] ?? $now),
            $now + $ttl
        );
        $table = $wpdb->prefix . 'duo_kv';
        Db::query($wpdb->prepare(
            "UPDATE `$table` SET v = %s WHERE k = %s
             AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.owner')) = %s
             AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.artifact_hash')) = %s",
            wp_json_encode($payload),
            self::KEY,
            $owner,
            $artifactHash
        ), 'promotion lock heartbeat');
        $after = self::current();
        if ($after === null
            || !hash_equals($owner, (string) ($after['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($after['artifact_hash'] ?? ''))
            || (int) ($after['expires_at'] ?? 0) <= $now) {
            throw new \RuntimeException('duo: promotion lock lost during renewal; mutation refused');
        }
    }

    /**
     * Re-acquire the exact generation recorded by a nonterminal scoped apply.
     *
     * This is deliberately narrower than begin(): it cannot mint a generation,
     * change owner/artifact, or recover an unrelated expired row. The target
     * session record supplies the random id, the process advisory fence proves
     * the old mutation process is gone, and exact readback precedes any caller
     * append to the scoped journal.
     *
     * @return array<string,mixed>
     */
    public static function recover_session(
        string $owner,
        string $artifactHash,
        string $sessionId,
        ?int $ttl = null
    ): array {
        global $wpdb;
        self::assert_identity($owner, $artifactHash);
        self::assert_scoped_session_id($sessionId);
        self::claim_process_fence();
        try {
            $session = self::current_session();
            if ($session === null
                || !hash_equals($owner, (string) ($session['owner'] ?? ''))
                || !hash_equals($artifactHash, (string) ($session['artifact_hash'] ?? ''))
                || !hash_equals($sessionId, self::normalized_session_id($session))) {
                throw new \RuntimeException('duo: scoped recovery promotion session was superseded');
            }
            $now = time();
            $current = self::current();
            if ($current !== null && (int) ($current['expires_at'] ?? 0) > $now) {
                if (!hash_equals($owner, (string) ($current['owner'] ?? ''))
                    || !hash_equals($artifactHash, (string) ($current['artifact_hash'] ?? ''))) {
                    throw new \RuntimeException('duo: scoped recovery is blocked by another live promotion lease');
                }
                $continued = self::acquire($owner, $artifactHash, 'scoped-recovery', $ttl, true);
                if (!hash_equals($sessionId, (string) $continued['session_id'])) {
                    throw new \RuntimeException('duo: scoped recovery generation changed during continuation');
                }
                return $continued;
            }
            if ($current !== null
                && (!hash_equals($owner, (string) ($current['owner'] ?? ''))
                    || !hash_equals($artifactHash, (string) ($current['artifact_hash'] ?? '')))) {
                throw new \RuntimeException(
                    'duo: scoped recovery found an expired lease with a different owner/artifact; refusing implicit takeover'
                );
            }
            $table = $wpdb->prefix . 'duo_kv';
            if ($current !== null) {
                Db::query($wpdb->prepare(
                    "DELETE FROM `$table` WHERE k = %s
                     AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.owner')) = %s
                     AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.artifact_hash')) = %s
                     AND CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v, '$.expires_at')), '0') AS UNSIGNED) <= %d",
                    self::KEY,
                    $owner,
                    $artifactHash,
                    $now
                ), 'scoped promotion lease expired-generation recovery');
            }
            $ttl = self::ttl($ttl);
            $payload = self::payload($owner, $artifactHash, 'scoped-recovery', $now, $now + $ttl);
            $inserted = Db::query($wpdb->prepare(
                "INSERT IGNORE INTO `$table` (k, v) VALUES (%s, %s)",
                self::KEY,
                wp_json_encode($payload)
            ), 'scoped promotion lease recovery acquire');
            if ((int) $inserted !== 1) {
                throw new \RuntimeException('duo: scoped recovery lost the promotion lease race');
            }
            $after = self::current();
            if ($after === null
                || !hash_equals($owner, (string) ($after['owner'] ?? ''))
                || !hash_equals($artifactHash, (string) ($after['artifact_hash'] ?? ''))
                || (int) ($after['expires_at'] ?? 0) <= $now) {
                throw new \RuntimeException('duo: scoped recovery promotion lease readback failed');
            }
            self::$leaseSessionOwner = $owner;
            self::$leaseSessionArtifact = $artifactHash;
            $after['recovered'] = true;
            $after['session_id'] = $sessionId;
            return $after;
        } catch (\Throwable $failure) {
            self::release_process_fence();
            throw $failure;
        }
    }

    /**
     * Persist one exact cross-process lifecycle handoff inside the durable
     * promotion session. Deploy records the canonical entity hash on both
     * sides of its hook-firing window; apply may use the pre-hook hash for
     * three-way comparison only while the live entity still equals the
     * recorded post-hook hash. The owner/artifact session prevents a stale
     * handoff from authorizing another promotion.
     */
    public static function record_state_transition(
        string $owner,
        string $artifactHash,
        string $entity,
        string $beforeHash,
        string $afterHash
    ): void {
        self::assert_identity($owner, $artifactHash);
        self::assert_state_transition($entity, $beforeHash, $afterHash);
        self::assert_live_session($owner, $artifactHash);
        (new StateTransitionJournal($owner, $artifactHash))->record($entity, $beforeHash, $afterHash);
    }

    /**
     * Begin a state handoff which spans more than one fresh WordPress
     * process.  Retirement hooks run with the outgoing plugins loaded;
     * replacement activation must run in a later process where those PHP
     * symbols are gone.  Apply must not see an authorization witness between
     * those phases, so the first leg is persisted under a distinct pending
     * key inside the exact promotion session.
     */
    public static function begin_state_transition(
        string $owner,
        string $artifactHash,
        string $entity,
        string $beforeHash,
        string $afterHash
    ): void {
        self::assert_identity($owner, $artifactHash);
        self::assert_state_transition($entity, $beforeHash, $afterHash);
        self::assert_live_session($owner, $artifactHash);
        (new StateTransitionJournal($owner, $artifactHash))->begin($entity, $beforeHash, $afterHash);
    }

    /**
     * Complete a cross-process state handoff.  The activation process must
     * start from the exact hash retirement published; otherwise a bootstrap
     * hook or external edit occurred between phases and the entity-wide
     * comparison witness is refused.
     */
    public static function complete_state_transition(
        string $owner,
        string $artifactHash,
        string $entity,
        string $beforeHash,
        string $afterHash
    ): void {
        self::assert_identity($owner, $artifactHash);
        self::assert_state_transition($entity, $beforeHash, $afterHash);
        self::assert_live_session($owner, $artifactHash);
        (new StateTransitionJournal($owner, $artifactHash))->complete($entity, $beforeHash, $afterHash);
    }

    /**
     * Persist the pre-hook boundary before any lifecycle API can mutate the
     * shared options/core entity. An exception, fatal, timeout, or process
     * loss deliberately leaves this row inside promotion_session. A different
     * promotion owner may not replace it; only the exact original checkpoint
     * (which predates this write) clears the ambiguity safely.
     */
    public static function begin_lifecycle_attempt(
        string $owner,
        string $artifactHash,
        string $entity,
        string $phase,
        string $beforeHash
    ): void {
        self::assert_identity($owner, $artifactHash);
        self::assert_state_transition($entity, $beforeHash, $beforeHash);
        self::assert_live_session($owner, $artifactHash);
        (new LifecycleJournal($owner, $artifactHash))->begin($entity, $phase, $beforeHash);
    }

    /** Clear a successful non-handoff deploy attempt in the same session row. */
    public static function complete_lifecycle_attempt(
        string $owner,
        string $artifactHash,
        string $entity,
        string $phase,
        string $beforeHash,
        string $afterHash
    ): void {
        self::assert_identity($owner, $artifactHash);
        self::assert_state_transition($entity, $beforeHash, $afterHash);
        self::assert_live_session($owner, $artifactHash);
        (new LifecycleJournal($owner, $artifactHash))->complete($entity, $phase, $beforeHash, $afterHash);
    }

    /** Apply and lifecycle continuations may never cross an ambiguous hook. */
    public static function assert_no_lifecycle_attempt(
        string $owner,
        string $artifactHash,
        string $context
    ): void {
        self::assert_identity($owner, $artifactHash);
        self::assert_live_session($owner, $artifactHash);
        (new LifecycleJournal($owner, $artifactHash))->assertClear($context);
    }

    /**
     * Read-only status surface for an ambiguous hook window. Unlike a live
     * lease, this receipt deliberately survives abort and expiry; operators
     * must therefore see it even when no continuation owner is available.
     *
     * @return array{owner:string,artifact_hash:string,entity:string,phase:string,before_hash:string}|null
     */
    public static function incomplete_lifecycle(): ?array {
        return LifecycleJournal::incompleteAny();
    }

    /**
     * Prove the host is entering the ordered fresh-process lifecycle sequence.
     * A completed phase may be replayed idempotently after transport loss, but
     * activation may never run before this exact session completed retirement.
     */
    public static function assert_lifecycle_phase_start(
        string $owner,
        string $artifactHash,
        string $phase
    ): void {
        self::assert_identity($owner, $artifactHash);
        self::assert_live_session($owner, $artifactHash);
        (new LifecycleJournal($owner, $artifactHash))->assertPhaseStart($phase);
    }

    /** Record one successful phase, including a verified no-op phase. */
    public static function complete_lifecycle_phase(
        string $owner,
        string $artifactHash,
        string $phase
    ): void {
        self::assert_identity($owner, $artifactHash);
        self::assert_live_session($owner, $artifactHash);
        (new LifecycleJournal($owner, $artifactHash))->completePhase($phase);
    }

    /** Code completion requires both successful fresh-process lifecycle legs. */
    public static function assert_lifecycle_complete(string $owner, string $artifactHash): void {
        self::assert_identity($owner, $artifactHash);
        self::assert_live_session($owner, $artifactHash);
        (new LifecycleJournal($owner, $artifactHash))->assertComplete();
    }

    public static function has_pending_state_transition(
        string $owner,
        string $artifactHash,
        string $entity
    ): bool {
        self::assert_identity($owner, $artifactHash);
        if (!self::session_matches_identity($owner, $artifactHash)) {
            return false;
        }
        return (new StateTransitionJournal($owner, $artifactHash))->hasPending($entity);
    }

    /**
     * Check the inter-process boundary before activation fires any hooks.
     * Returns false when retirement did not open a handoff (for example an
     * activation-only change discovered in this process).
     */
    public static function assert_pending_state_transition_start(
        string $owner,
        string $artifactHash,
        string $entity,
        string $currentHash
    ): bool {
        self::assert_identity($owner, $artifactHash);
        self::assert_state_transition($entity, $currentHash, $currentHash);
        if (!self::session_matches_identity($owner, $artifactHash)) {
            return false;
        }
        return (new StateTransitionJournal($owner, $artifactHash))->assertPendingStart($entity, $currentHash);
    }

    /** @return array{entity:string,before_hash:string,after_hash:string}|null */
    public static function state_transition(string $owner, string $artifactHash, string $entity): ?array {
        self::assert_identity($owner, $artifactHash);
        if (!self::session_matches_identity($owner, $artifactHash)) {
            return null;
        }
        $record = (new StateTransitionJournal($owner, $artifactHash))->current($entity);
        return $record?->toArray();
    }

    /** Retire the exact scoped target handoff after external terminal commit. */
    public static function complete_scoped(
        string $owner,
        string $artifactHash,
        string $receiptHash,
        string $scopeHash,
        array $authorityWitness
    ): array {
        global $wpdb;
        self::assert_identity($owner, $artifactHash);
        if (preg_match('/^[a-f0-9]{64}$/D', $receiptHash) !== 1) {
            throw new \RuntimeException('duo: scoped promotion requires the signed receipt payload hash');
        }
        self::assert_scoped_authority_witness($authorityWitness, $owner, $artifactHash, $receiptHash, $scopeHash);
        self::claim_process_fence();
        try {
            if (self::current() !== null) {
                throw new \RuntimeException('duo: scoped promotion session completion requires its target lease to be absent');
            }
            $session = self::current_session();
            if ($session === null) {
                return ['owner' => $owner, 'artifact_hash' => $artifactHash, 'released' => false, 'already_absent' => true];
            }
            if (!hash_equals($owner, (string) ($session['owner'] ?? ''))
                || !hash_equals($artifactHash, (string) ($session['artifact_hash'] ?? ''))) {
                throw new \RuntimeException('duo: scoped promotion session completion found a different owner/artifact');
            }
            self::assert_scoped_profile_session($session, $receiptHash, $scopeHash, $authorityWitness);
            $table = $wpdb->prefix . 'duo_kv';
            $deleted = Db::query($wpdb->prepare(
                "DELETE FROM `$table` WHERE k = %s
                 AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.owner')) = %s
                 AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.artifact_hash')) = %s
                 AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.profile')) = %s
                 AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.scoped_receipt_sha256')) = %s
                 AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.scoped_scope_hash')) = %s
                 AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.scoped_receipt_id')) = %s
                 AND CAST(JSON_UNQUOTE(JSON_EXTRACT(v, '$.scoped_generation')) AS UNSIGNED) = %d
                 AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.scoped_target_id')) = %s",
                'promotion_session', $owner, $artifactHash, 'scoped-checkpoint-v1', $receiptHash,
                $scopeHash, (string) $authorityWitness['receipt_id'], (int) $authorityWitness['generation'],
                (string) $authorityWitness['target_id']
            ), 'scoped promotion session complete');
            if ((int) $deleted !== 1) {
                throw new \RuntimeException('duo: scoped promotion session completion lost its exact target row');
            }
            return ['owner' => $owner, 'artifact_hash' => $artifactHash, 'released' => true, 'already_absent' => false];
        } finally {
            self::release_process_fence();
        }
    }

    public static function release(string $owner, string $artifactHash): void {
        global $wpdb;
        self::assert_identity($owner, $artifactHash);
        self::claim_process_fence();
        try {
            $table = $wpdb->prefix . 'duo_kv';
            $deleted = Db::query($wpdb->prepare(
                "DELETE FROM `$table` WHERE k = %s
                 AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.owner')) = %s
                 AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.artifact_hash')) = %s",
                self::KEY,
                $owner,
                $artifactHash
            ), 'promotion lock release');
            if ((int) $deleted !== 1) {
                throw new \RuntimeException('duo: promotion lock lost before release; completion refused');
            }
        } finally {
            self::release_process_fence();
        }
    }

    /**
     * Idempotent failure/recovery cleanup. Unlike release(), which proves a
     * successful mutation still owns its lease, abort() treats an already
     * absent matching lease as success. It never deletes another promotion's
     * row, even if that row is expired.
     *
     * @return array{owner:string,artifact_hash:string,released:bool,already_absent:bool}
     */
    public static function abort(string $owner, string $artifactHash): array {
        global $wpdb;
        self::assert_identity($owner, $artifactHash);
        self::claim_process_fence();
        try {
            $current = self::current();
            if ($current === null) {
                self::assert_abort_session($owner, $artifactHash);
                return [
                    'owner' => $owner,
                    'artifact_hash' => $artifactHash,
                    'released' => false,
                    'already_absent' => true,
                ];
            }
            self::assert_abort_ownership($current, $owner, $artifactHash);

            $table = $wpdb->prefix . 'duo_kv';
            $deleted = Db::query($wpdb->prepare(
                "DELETE FROM `$table` WHERE k = %s
                 AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.owner')) = %s
                 AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.artifact_hash')) = %s",
                self::KEY,
                $owner,
                $artifactHash
            ), 'promotion lock abort');
            if ((int) $deleted === 1) {
                return [
                    'owner' => $owner,
                    'artifact_hash' => $artifactHash,
                    'released' => true,
                    'already_absent' => false,
                ];
            }

            // A stage/deploy/apply failure may have released the lease just before
            // the host's compensating abort reached us. That is a successful,
            // idempotent cleanup. Any other replacement remains fail-closed.
            $after = self::current();
            if ($after === null) {
                self::assert_abort_session($owner, $artifactHash);
                return [
                    'owner' => $owner,
                    'artifact_hash' => $artifactHash,
                    'released' => false,
                    'already_absent' => true,
                ];
            }
            self::assert_abort_ownership($after, $owner, $artifactHash);
            throw new \RuntimeException('duo: promotion lock abort did not remove the matching lease');
        } finally {
            self::release_process_fence();
        }
    }

    /**
     * Release after an apply failure without inheriting its transaction.
     *
     * A failed ROLLBACK can leave the authored transaction open on WordPress's
     * global connection. Releasing through that connection would place the
     * DELETE inside the doomed transaction, so PHP shutdown rolls the release
     * back and strands a truthful retry behind the full lease TTL. Closing the
     * connection first makes MariaDB roll back that transaction and its row
     * locks; a fresh connection can then remove only this proven owner/artifact
     * lease. The caller deliberately preserves the original apply exception if
     * this best-effort cleanup also fails.
     */
    public static function release_after_failure(string $owner, string $artifactHash): void {
        global $wpdb;
        self::assert_identity($owner, $artifactHash);

        // Most failures happen outside a transaction (preconditions,
        // rebuild actions, or probes). Keep their normal WordPress connection
        // alive for shutdown hooks; only cross the independent cleanup
        // boundary when MariaDB proves this process still owns a transaction.
        $inTransaction = $wpdb->get_var('SELECT @@in_transaction');
        if ($inTransaction !== null && (int) $inTransaction === 0) {
            self::release($owner, $artifactHash);
            return;
        }

        // Nothing after a failed apply may depend on this connection. Closing
        // it is the only database-authoritative way to end an open transaction
        // when the attempted ROLLBACK itself reported failure.
        $wpdb->close();

        // Reuse WordPress's host/socket parsing and error policy, but never
        // let connection recovery bail out of PHP: the outer catch must retain
        // the original apply failure as the primary operator-facing error.
        if (!$wpdb->check_connection(false)) {
            throw new \RuntimeException('duo: could not reconnect to release promotion lock after failure');
        }
        self::release($owner, $artifactHash);
    }

    /**
     * Process-scoped advisory-fence witness for responsibility-focused
     * collaborators. Lease acquisition/release remains the only code allowed
     * to change the fence; callers may only assert continuity.
     */
    public static function assert_process_fence(): void {
        ProcessFence::assertHeld();
    }

    /** @return array<string,mixed>|null */
    public static function current(): ?array {
        $raw = Ledger::kv_get(self::KEY);
        if ($raw === null) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('duo: malformed promotion lock record; refusing to guess ownership');
        }
        return $decoded;
    }

    /** @return array<string,mixed>|null */
    private static function current_session(bool $allowMalformedOrdinaryFallback = false): ?array {
        $typedFailure = null;
        try {
            $record = PromotionSessionJournal::readAny();
            if ($record !== null) {
                return $record->toArray();
            }
            return null;
        } catch (\InvalidArgumentException $failure) {
            if (!$allowMalformedOrdinaryFallback) {
                throw $failure;
            }
            // Scoped replacement must classify malformed ordinary recovery
            // receipts itself so it can refuse without erasing their bytes.
            // Preserve the journal as the normal reader; this narrow fallback
            // only decodes the identity envelope for the fail-closed refusal.
            $typedFailure = $failure;
        }
        $raw = Ledger::kv_get('promotion_session');
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)
            || !is_string($decoded['owner'] ?? null)
            || $decoded['owner'] === ''
            || !is_string($decoded['artifact_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $decoded['artifact_hash']) !== 1
            || !is_int($decoded['begun_at'] ?? null)) {
            throw new \RuntimeException('duo: malformed promotion session record; refusing to guess checkpoint ownership');
        }
        $scopedMarkers = [
            'profile', 'scoped_allow_deletes', 'scoped_generation', 'scoped_receipt_id',
            'scoped_receipt_sha256', 'scoped_scope_hash', 'scoped_signing_key_id', 'scoped_target_id',
        ];
        if (array_intersect($scopedMarkers, array_keys($decoded)) !== []) {
            throw $typedFailure ?? new \RuntimeException('duo: malformed scoped promotion session record');
        }
        return $decoded;
    }

    private static function session_matches_identity(string $owner, string $artifactHash): bool {
        $session = PromotionSessionJournal::readAny();
        return $session === null
            || (hash_equals($owner, $session->owner())
                && hash_equals($artifactHash, $session->artifactHash()));
    }

    public static function owner(array $opts): string {
        $owner = (string) ($opts['promotion_owner'] ?? '');
        return $owner !== '' ? $owner : 'direct-' . bin2hex(random_bytes(16));
    }

    /**
     * Exact generation of the currently begun owner/artifact session.
     *
     * Pre-session-id records can survive an agent upgrade while a full
     * promotion is being recovered. Preserve that existing recovery route by
     * deriving a stable legacy generation from its immutable begun tuple. New
     * sessions always carry the random `ps-*` form, which scoped mutation
     * authority can distinguish even when an orchestrator reuses an owner.
     */
    public static function session_id(string $owner, string $artifactHash): string {
        self::assert_identity($owner, $artifactHash);
        $current = self::current();
        $session = self::current_session();
        if ($current === null || $session === null
            || !hash_equals($owner, (string) ($current['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($current['artifact_hash'] ?? ''))
            || !hash_equals($owner, (string) ($session['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($session['artifact_hash'] ?? ''))
            || (int) ($current['expires_at'] ?? 0) <= time()) {
            throw new \RuntimeException('duo: promotion session identity is not live for this owner/artifact');
        }
        return self::normalized_session_id($session);
    }

    /**
     * Exact random generation required by scoped mutation authority.
     *
     * Ordinary full-promotion recovery may still observe a stable `legacy-*`
     * generation for a session begun before random session ids shipped. A
     * scoped apply must refuse that continuation before renewing its lease or
     * recording authority, because its crash-recovery protocol deliberately
     * accepts only an unforgeable `ps-*` generation.
     */
    public static function scoped_session_id(string $owner, string $artifactHash): string {
        $sessionId = self::session_id($owner, $artifactHash);
        self::assert_scoped_session_id($sessionId);
        return $sessionId;
    }

    private static function assert_scoped_session_id(string $sessionId): void {
        if (preg_match('/^ps-[a-f0-9]{32}$/D', $sessionId) !== 1) {
            throw new \RuntimeException('duo: scoped recovery requires the exact random promotion session generation');
        }
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $authorityWitness */
    private static function assert_scoped_profile_session(
        array $session,
        string $receiptHash,
        string $scopeHash,
        array $authorityWitness
    ): void {
        if (($session['profile'] ?? null) !== 'scoped-checkpoint-v1'
            || !is_string($session['scoped_receipt_sha256'] ?? null)
            || !hash_equals((string) $session['scoped_receipt_sha256'], $receiptHash)
            || !hash_equals((string) ($session['scoped_scope_hash'] ?? ''), $scopeHash)
            || !hash_equals((string) ($session['scoped_receipt_id'] ?? ''), (string) ($authorityWitness['receipt_id'] ?? ''))
            || (int) ($session['scoped_generation'] ?? 0) !== (int) ($authorityWitness['generation'] ?? 0)
            || !hash_equals((string) ($session['scoped_target_id'] ?? ''), (string) ($authorityWitness['target_id'] ?? ''))
            || !hash_equals((string) ($session['scoped_signing_key_id'] ?? ''), (string) ($authorityWitness['signing_key_id'] ?? ''))
            || !is_bool($session['scoped_allow_deletes'] ?? null)
            || $session['scoped_allow_deletes'] !== ($authorityWitness['allow_deletes'] ?? null)) {
            throw new \RuntimeException('duo: scoped promotion target session does not match its external signed receipt');
        }
    }

    /** @param array<string,mixed> $authorityWitness */
    private static function assert_scoped_authority_witness(
        array $authorityWitness,
        string $owner,
        string $artifactHash,
        string $receiptHash,
        string $scopeHash
    ): void {
        if (($authorityWitness['format'] ?? null) !== 'duo-scoped-promotion-witness/v1'
            || ($authorityWitness['exclusion_state'] ?? null) !== 'held'
            || ($authorityWitness['recovery_ready'] ?? null) !== true
            || !is_bool($authorityWitness['allow_deletes'] ?? null)
            || !hash_equals($owner, (string) ($authorityWitness['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($authorityWitness['artifact_hash'] ?? ''))
            || !hash_equals($receiptHash, (string) ($authorityWitness['receipt_payload_sha256'] ?? ''))
            || !hash_equals($scopeHash, (string) ($authorityWitness['scope_hash'] ?? ''))) {
            throw new \RuntimeException('duo: scoped promotion target received no exact external authority witness');
        }
    }

    /** @param array<string,mixed> $authorityWitness @return array<string,mixed> */
    private static function scoped_session_metadata(array $authorityWitness, string $receiptHash, string $scopeHash): array {
        return [
            'profile' => 'scoped-checkpoint-v1',
            'scoped_allow_deletes' => (bool) $authorityWitness['allow_deletes'],
            'scoped_generation' => (int) $authorityWitness['generation'],
            'scoped_receipt_id' => (string) $authorityWitness['receipt_id'],
            'scoped_receipt_sha256' => $receiptHash,
            'scoped_scope_hash' => $scopeHash,
            'scoped_signing_key_id' => (string) $authorityWitness['signing_key_id'],
            'scoped_target_id' => (string) $authorityWitness['target_id'],
        ];
    }

    /** @param array<string,mixed>|null $session */
    private static function normalized_session_id(?array $session): string {
        if ($session === null) {
            throw new \RuntimeException('duo: promotion session identity is missing');
        }
        if (array_key_exists('session_id', $session)) {
            $explicit = $session['session_id'];
            if (!is_string($explicit) || preg_match('/^ps-[a-f0-9]{32}$/D', $explicit) !== 1) {
                throw new \RuntimeException('duo: malformed promotion session generation');
            }
            return $explicit;
        }
        return 'legacy-' . substr(hash(
            'sha256',
            (string) ($session['owner'] ?? '') . "\0"
                . (string) ($session['artifact_hash'] ?? '') . "\0"
                . (string) ($session['begun_at'] ?? '')
        ), 0, 32);
    }

    /** @param array<string,mixed> $session */
    private static function assert_reclaimable_ordinary_session(array $session): void {
        $allowed = [
            'owner', 'artifact_hash', 'begun_at', 'session_id', 'lifecycle_attempt',
            'pending_state_transition', 'state_transition', 'lifecycle_phases',
        ];
        foreach (array_keys($session) as $key) {
            if (!is_string($key) || !in_array($key, $allowed, true)) {
                throw new \RuntimeException('duo: unknown ordinary promotion session recovery field blocks scoped promotion session replacement');
            }
        }
        if (array_key_exists('profile', $session)) {
            throw new \RuntimeException('duo: scoped promotion begin found a profiled non-scoped target promotion session');
        }
        if (self::session_lifecycle_attempt($session) !== null) {
            throw new \RuntimeException('duo: unresolved lifecycle attempt blocks scoped promotion session replacement; restore the exact pre-lifecycle database checkpoint before retrying');
        }
        if (array_key_exists('pending_state_transition', $session)) {
            throw new \RuntimeException('duo: pending lifecycle state transition blocks scoped promotion session replacement; restore the exact pre-lifecycle database checkpoint before retrying');
        }
        self::normalized_session_id($session);
        $phases = self::session_lifecycle_phases($session);
        if ($phases === ['retire']) {
            throw new \RuntimeException('duo: incomplete lifecycle phase receipt blocks scoped promotion session replacement; restore the exact pre-lifecycle database checkpoint before retrying');
        }
        if (!array_key_exists('state_transition', $session)) {
            return;
        }
        if ($phases !== ['retire', 'activate']) {
            throw new \RuntimeException('duo: lifecycle state transition without complete lifecycle phases blocks scoped promotion session replacement');
        }
        $transition = $session['state_transition'];
        if (!is_array($transition)) {
            throw new \RuntimeException('duo: malformed completed lifecycle state transition blocks scoped promotion session replacement');
        }
        $keys = array_keys($transition);
        sort($keys, SORT_STRING);
        if ($keys !== ['after_hash', 'before_hash', 'entity']
            || !is_string($transition['entity'] ?? null)
            || !is_string($transition['before_hash'] ?? null)
            || !is_string($transition['after_hash'] ?? null)) {
            throw new \RuntimeException('duo: malformed completed lifecycle state transition blocks scoped promotion session replacement');
        }
        self::assert_state_transition($transition['entity'], $transition['before_hash'], $transition['after_hash']);
    }

    private static function assert_transactional_replacement_storage(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'duo_kv';
        $engine = $wpdb->get_var($wpdb->prepare(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
            $table
        ));
        if (!is_string($engine) || strcasecmp($engine, 'InnoDB') !== 0) {
            throw new \RuntimeException('duo: scoped ordinary session replacement requires an InnoDB duo_kv table; refusing a nontransactional promotion handoff');
        }
    }

    /** @param array<string,mixed>|null $session @return array<string,mixed>|null */
    private static function session_lifecycle_attempt(?array $session): ?array {
        if ($session === null || !array_key_exists('lifecycle_attempt', $session)) {
            return null;
        }
        $attempt = $session['lifecycle_attempt'];
        if (!is_array($attempt)) {
            throw new \RuntimeException('duo: malformed unresolved lifecycle attempt');
        }
        $keys = array_keys($attempt);
        sort($keys, SORT_STRING);
        if ($keys !== ['before_hash', 'entity', 'phase']
            || ($attempt['entity'] ?? null) !== 'options/core'
            || !is_string($attempt['phase'] ?? null)
            || !in_array($attempt['phase'], ['all', 'retire', 'activate'], true)
            || !is_string($attempt['before_hash'] ?? null)
            || preg_match('/^[a-f0-9]{64}$/D', $attempt['before_hash']) !== 1) {
            throw new \RuntimeException('duo: malformed unresolved lifecycle attempt');
        }
        return $attempt;
    }

    /** @param array<string,mixed>|null $session @return list<string> */
    private static function session_lifecycle_phases(?array $session): array {
        if ($session === null || !array_key_exists('lifecycle_phases', $session)) {
            return [];
        }
        $phases = $session['lifecycle_phases'];
        if (!is_array($phases) || !array_is_list($phases)
            || ($phases !== ['retire'] && $phases !== ['retire', 'activate'])) {
            throw new \RuntimeException('duo: malformed completed lifecycle phase receipt');
        }
        return $phases;
    }

    private static function ttl(?int $ttl): int {
        if ($ttl === null && getenv('DUO_TEST_MODE') === '1' && getenv('DUO_TEST_PROMOTION_TTL') !== false) {
            $ttl = (int) getenv('DUO_TEST_PROMOTION_TTL');
        }
        $ttl ??= self::DEFAULT_TTL;
        if ($ttl < 1 || $ttl > 3600) {
            throw new \RuntimeException('duo: promotion lock TTL must be between 1 and 3600 seconds');
        }
        return $ttl;
    }

    private static function assert_identity(string $owner, string $artifactHash): void {
        if (!preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $owner)) {
            throw new \RuntimeException('duo: invalid promotion lock owner token');
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $artifactHash)) {
            throw new \RuntimeException('duo: promotion lock requires the compiled artifact sha256');
        }
    }

    private static function assert_state_transition(string $entity, string $beforeHash, string $afterHash): void {
        if ($entity !== 'options/core'
            || !preg_match('/^[a-f0-9]{64}$/', $beforeHash)
            || !preg_match('/^[a-f0-9]{64}$/', $afterHash)) {
            throw new \RuntimeException('duo: malformed lifecycle state transition; refusing three-way bypass');
        }
    }

    /** Prove both durable ownership rows before a typed journal mutates. */
    private static function assert_live_session(string $owner, string $artifactHash): void {
        $lease = self::current();
        if ($lease === null
            || !hash_equals($owner, (string) ($lease['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($lease['artifact_hash'] ?? ''))) {
            throw new \RuntimeException('duo: lifecycle state transition lost its promotion session');
        }
        PromotionSessionJournal::readFor($owner, $artifactHash)
            ?? throw new \RuntimeException('duo: lifecycle state transition lost its promotion session');
    }

    /** One live PHP mutation process fences long hooks/filesystem walks. */
    private static function claim_process_fence(): void {
        ProcessFence::acquire(static function (): void {
            self::invalidate_process_fence_witnesses();
        });
    }

    /**
     * A replacement advisory fence cannot inherit the old lease witness.
     * Without this reset, a reconnect could renew an expired durable row as
     * though the original database connection had remained continuously held.
     */
    private static function invalidate_process_fence_witnesses(): void {
        self::$leaseSessionOwner = null;
        self::$leaseSessionArtifact = null;
    }

    private static function process_fence_is_continuous(): bool {
        return ProcessFence::isContinuous();
    }

    private static function release_process_fence(): void {
        ProcessFence::release();
        self::$leaseSessionOwner = null;
        self::$leaseSessionArtifact = null;
    }

    private static function process_fence_name(): string {
        return ProcessFence::name();
    }

    /** @param array<string,mixed> $current */
    private static function assert_abort_ownership(array $current, string $owner, string $artifactHash): void {
        $currentOwner = (string) ($current['owner'] ?? 'unknown');
        $currentArtifact = (string) ($current['artifact_hash'] ?? 'unknown');
        if (!hash_equals($owner, $currentOwner) || !hash_equals($artifactHash, $currentArtifact)) {
            throw new \RuntimeException(
                "duo: promotion abort refused; lock belongs to '$currentOwner' for artifact '$currentArtifact'"
            );
        }
    }

    private static function assert_abort_session(string $owner, string $artifactHash): void {
        $session = self::current_session();
        if ($session === null) {
            return;
        }
        $sessionOwner = (string) $session['owner'];
        $sessionArtifact = (string) $session['artifact_hash'];
        if (!hash_equals($owner, $sessionOwner) || !hash_equals($artifactHash, $sessionArtifact)) {
            throw new \RuntimeException(
                "duo: promotion abort refused; the latest begun session belongs to '$sessionOwner' "
                . "for artifact '$sessionArtifact'"
            );
        }
    }

    /** @return array{owner:string,artifact_hash:string,phase:string,acquired_at:int,expires_at:int} */
    private static function payload(
        string $owner,
        string $artifactHash,
        string $phase,
        int $acquiredAt,
        int $expiresAt
    ): array {
        return [
            'owner' => $owner,
            'artifact_hash' => $artifactHash,
            'phase' => $phase,
            'acquired_at' => $acquiredAt,
            'expires_at' => $expiresAt,
        ];
    }
}
