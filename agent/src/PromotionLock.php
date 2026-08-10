<?php
namespace Duo;

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
final class PromotionLock {
    private const KEY = 'promotion_lock';
    private const SESSION_KEY = 'promotion_session';
    private const DEFAULT_TTL = 300;
    private static ?string $processFenceName = null;
    private static ?int $processFenceConnection = null;
    private static ?string $leaseSessionOwner = null;
    private static ?string $leaseSessionArtifact = null;

    /** Begin a new multi-process host session; later host phases only continue it. */
    public static function begin(string $owner, string $artifactHash, ?int $ttl = null): array {
        return self::acquire($owner, $artifactHash, 'checkpoint', $ttl, false);
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
        $existingSession = self::current_session();
        if ($existingSession !== null
            && hash_equals($owner, (string) ($existingSession['owner'] ?? ''))
            && hash_equals($artifactHash, (string) ($existingSession['artifact_hash'] ?? ''))) {
            throw new \RuntimeException('duo: direct apply promotion session was already begun');
        }
        if (self::session_lifecycle_attempt($existingSession) !== null) {
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
        Ledger::kv_set(self::SESSION_KEY, wp_json_encode([
            'owner' => $owner,
            'artifact_hash' => $artifactHash,
            'begun_at' => $now,
        ]));
    }

    /** @return array{owner:string,artifact_hash:string,phase:string,acquired_at:int,expires_at:int,recovered:bool} */
    private static function acquire_internal(
        string $owner,
        string $artifactHash,
        string $phase,
        ?int $ttl,
        bool $requireExisting,
        bool $publishSession
    ): array {
        global $wpdb;
        self::assert_identity($owner, $artifactHash);
        self::claim_process_fence();
        try {
            $ttl = self::ttl($ttl);
            $now = time();
            $before = self::current();
            $preserveRecoverySession = false;
            if (!$requireExisting) {
                $existingSession = self::current_session();
                $attempt = self::session_lifecycle_attempt($existingSession);
                if ($attempt !== null) {
                    if (!is_array($existingSession)
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
            if ($publishSession && !$requireExisting && !$preserveRecoverySession) {
                $session = [
                    'owner' => $owner,
                    'artifact_hash' => $artifactHash,
                    'begun_at' => $now,
                    // Owner tokens are operator/run identities and may be
                    // deliberately reused. This random generation is the
                    // durable discriminator a scoped mutation authority binds
                    // to so an expired/replaced lease cannot append evidence
                    // to an older session with the same owner/artifact pair.
                    'session_id' => 'ps-' . bin2hex(random_bytes(16)),
                ];
                Ledger::kv_set(self::SESSION_KEY, wp_json_encode($session));
            } elseif ($session === null) {
                $session = self::current_session();
            }
            $current['session_id'] = self::normalized_session_id($session);
            return $current;
        } catch (\Throwable $t) {
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
        $current = self::current();
        $session = self::current_session();
        if ($current === null || $session === null
            || !hash_equals($owner, (string) ($current['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($current['artifact_hash'] ?? ''))
            || !hash_equals($owner, (string) ($session['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($session['artifact_hash'] ?? ''))) {
            throw new \RuntimeException('duo: lifecycle state transition lost its promotion session');
        }
        $session = self::consume_lifecycle_attempt(
            $session,
            $entity,
            ['all', 'activate'],
            $beforeHash,
            !hash_equals($beforeHash, $afterHash)
        );
        $session['state_transition'] = [
            'entity' => $entity,
            'before_hash' => $beforeHash,
            'after_hash' => $afterHash,
        ];
        Ledger::kv_set(self::SESSION_KEY, wp_json_encode($session));
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
        $session = self::transition_session($owner, $artifactHash);
        $pending = $session['pending_state_transition'] ?? null;
        if ($pending !== null) {
            if (!is_array($pending)) {
                throw new \RuntimeException('duo: malformed pending lifecycle state transition');
            }
            self::assert_state_transition(
                (string) ($pending['entity'] ?? ''),
                (string) ($pending['before_hash'] ?? ''),
                (string) ($pending['after_hash'] ?? '')
            );
            if (hash_equals($entity, (string) $pending['entity'])
                && hash_equals($beforeHash, (string) $pending['before_hash'])
                && hash_equals($afterHash, (string) $pending['after_hash'])) {
                // A process may die after retirement published its receipt but
                // before the host launches activation. Replaying the exact
                // retire leg is a successful no-op; never rewrite H0->H1 as
                // the retry process's already-retired H1->H1 snapshot.
                return;
            }
            throw new \RuntimeException(
                'duo: lifecycle retirement already has a different pending state transition; refusing replacement'
            );
        }
        $session = self::consume_lifecycle_attempt(
            $session,
            $entity,
            ['retire'],
            $beforeHash,
            !hash_equals($beforeHash, $afterHash)
        );
        $session['pending_state_transition'] = [
            'entity' => $entity,
            'before_hash' => $beforeHash,
            'after_hash' => $afterHash,
        ];
        unset($session['state_transition']);
        Ledger::kv_set(self::SESSION_KEY, wp_json_encode($session));
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
        $session = self::transition_session($owner, $artifactHash);
        $pending = $session['pending_state_transition'] ?? null;
        if (!is_array($pending)) {
            throw new \RuntimeException(
                'duo: lifecycle activation has no pending retirement state transition'
            );
        }
        self::assert_state_transition(
            (string) ($pending['entity'] ?? ''),
            (string) ($pending['before_hash'] ?? ''),
            (string) ($pending['after_hash'] ?? '')
        );
        if (!hash_equals($entity, (string) $pending['entity'])
            || !hash_equals($beforeHash, (string) $pending['after_hash'])) {
            throw new \RuntimeException(
                'duo: lifecycle state changed between retirement and activation; refusing three-way bypass'
            );
        }
        $session = self::consume_lifecycle_attempt(
            $session,
            $entity,
            ['activate'],
            $beforeHash,
            !hash_equals($beforeHash, $afterHash)
        );
        $session['state_transition'] = [
            'entity' => $entity,
            'before_hash' => (string) $pending['before_hash'],
            'after_hash' => $afterHash,
        ];
        unset($session['pending_state_transition']);
        Ledger::kv_set(self::SESSION_KEY, wp_json_encode($session));
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
        if (!in_array($phase, ['all', 'retire', 'activate'], true)) {
            throw new \RuntimeException("duo: unsupported lifecycle attempt phase '$phase'");
        }
        $session = self::transition_session($owner, $artifactHash);
        if (self::session_lifecycle_attempt($session) !== null) {
            throw new \RuntimeException(
                'duo: unresolved lifecycle attempt must be recovered before another hook window can start'
            );
        }
        $session['lifecycle_attempt'] = [
            'entity' => $entity,
            'phase' => $phase,
            'before_hash' => $beforeHash,
        ];
        Ledger::kv_set(self::SESSION_KEY, wp_json_encode($session));
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
        $session = self::transition_session($owner, $artifactHash);
        $session = self::consume_lifecycle_attempt(
            $session,
            $entity,
            [$phase],
            $beforeHash,
            true
        );
        Ledger::kv_set(self::SESSION_KEY, wp_json_encode($session));
    }

    /** Apply and lifecycle continuations may never cross an ambiguous hook. */
    public static function assert_no_lifecycle_attempt(
        string $owner,
        string $artifactHash,
        string $context
    ): void {
        self::assert_identity($owner, $artifactHash);
        $session = self::transition_session($owner, $artifactHash);
        $attempt = self::session_lifecycle_attempt($session);
        if ($attempt === null) {
            return;
        }
        throw new \RuntimeException(
            "duo: $context refused — unresolved lifecycle attempt {$attempt['phase']} for {$attempt['entity']} "
            . "started at {$attempt['before_hash']}; restore the exact pre-lifecycle database checkpoint before retrying"
        );
    }

    /**
     * Read-only status surface for an ambiguous hook window. Unlike a live
     * lease, this receipt deliberately survives abort and expiry; operators
     * must therefore see it even when no continuation owner is available.
     *
     * @return array{owner:string,artifact_hash:string,entity:string,phase:string,before_hash:string}|null
     */
    public static function incomplete_lifecycle(): ?array {
        $session = self::current_session();
        $attempt = self::session_lifecycle_attempt($session);
        if ($attempt === null || $session === null) {
            return null;
        }
        $owner = (string) $session['owner'];
        $artifact = (string) $session['artifact_hash'];
        self::assert_identity($owner, $artifact);
        return [
            'owner' => $owner,
            'artifact_hash' => $artifact,
            'entity' => $attempt['entity'],
            'phase' => $attempt['phase'],
            'before_hash' => $attempt['before_hash'],
        ];
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
        if (!in_array($phase, ['retire', 'activate'], true)) {
            throw new \RuntimeException("duo: unsupported ordered lifecycle phase '$phase'");
        }
        $session = self::transition_session($owner, $artifactHash);
        $completed = self::session_lifecycle_phases($session);
        if ($phase === 'retire' && ($completed === [] || $completed === ['retire'])) {
            return;
        }
        if ($phase === 'activate'
            && ($completed === ['retire'] || $completed === ['retire', 'activate'])) {
            return;
        }
        throw new \RuntimeException(
            "duo: lifecycle phase '$phase' is out of order for this promotion session; "
            . 'run host retirement then fresh-process activation'
        );
    }

    /** Record one successful phase, including a verified no-op phase. */
    public static function complete_lifecycle_phase(
        string $owner,
        string $artifactHash,
        string $phase
    ): void {
        self::assert_lifecycle_phase_start($owner, $artifactHash, $phase);
        $session = self::transition_session($owner, $artifactHash);
        if (self::session_lifecycle_attempt($session) !== null) {
            throw new \RuntimeException(
                "duo: lifecycle phase '$phase' cannot complete with an unresolved hook attempt"
            );
        }
        $completed = self::session_lifecycle_phases($session);
        if (($phase === 'retire' && $completed === ['retire'])
            || ($phase === 'activate' && $completed === ['retire', 'activate'])) {
            return;
        }
        $session['lifecycle_phases'] = $phase === 'retire'
            ? ['retire']
            : ['retire', 'activate'];
        Ledger::kv_set(self::SESSION_KEY, wp_json_encode($session));
    }

    /** Code completion requires both successful fresh-process lifecycle legs. */
    public static function assert_lifecycle_complete(string $owner, string $artifactHash): void {
        self::assert_identity($owner, $artifactHash);
        $session = self::transition_session($owner, $artifactHash);
        if (self::session_lifecycle_phases($session) !== ['retire', 'activate']) {
            throw new \RuntimeException(
                'duo: code-finalize refused — this promotion session has not completed lifecycle retirement '
                . 'and fresh-process activation in order'
            );
        }
    }

    public static function has_pending_state_transition(
        string $owner,
        string $artifactHash,
        string $entity
    ): bool {
        self::assert_identity($owner, $artifactHash);
        $session = self::current_session();
        if ($session === null
            || !hash_equals($owner, (string) ($session['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($session['artifact_hash'] ?? ''))) {
            return false;
        }
        $pending = $session['pending_state_transition'] ?? null;
        if ($pending === null) {
            return false;
        }
        if (!is_array($pending)) {
            throw new \RuntimeException('duo: malformed pending lifecycle state transition');
        }
        self::assert_state_transition(
            (string) ($pending['entity'] ?? ''),
            (string) ($pending['before_hash'] ?? ''),
            (string) ($pending['after_hash'] ?? '')
        );
        return hash_equals($entity, (string) $pending['entity']);
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
        self::assert_state_transition($entity, $currentHash, $currentHash);
        if (!self::has_pending_state_transition($owner, $artifactHash, $entity)) {
            return false;
        }
        $session = self::current_session();
        $pending = is_array($session) ? ($session['pending_state_transition'] ?? null) : null;
        if (!is_array($pending)
            || !hash_equals($currentHash, (string) ($pending['after_hash'] ?? ''))) {
            throw new \RuntimeException(
                'duo: lifecycle state changed between retirement and activation; activation was not attempted'
            );
        }
        return true;
    }

    /** @return array{entity:string,before_hash:string,after_hash:string}|null */
    public static function state_transition(string $owner, string $artifactHash, string $entity): ?array {
        self::assert_identity($owner, $artifactHash);
        $session = self::current_session();
        if ($session === null
            || !hash_equals($owner, (string) ($session['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($session['artifact_hash'] ?? ''))) {
            return null;
        }
        $row = $session['state_transition'] ?? null;
        if ($row === null) {
            return null;
        }
        if (!is_array($row)) {
            throw new \RuntimeException('duo: malformed lifecycle state transition; refusing three-way bypass');
        }
        self::assert_state_transition(
            (string) ($row['entity'] ?? ''),
            (string) ($row['before_hash'] ?? ''),
            (string) ($row['after_hash'] ?? '')
        );
        if (!hash_equals($entity, (string) $row['entity'])) {
            return null;
        }
        return $row;
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
    private static function current_session(): ?array {
        $raw = Ledger::kv_get(self::SESSION_KEY);
        if ($raw === null || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)
            || !is_string($decoded['owner'] ?? null)
            || !is_string($decoded['artifact_hash'] ?? null)
            || !is_int($decoded['begun_at'] ?? null)) {
            throw new \RuntimeException('duo: malformed promotion session record; refusing to guess checkpoint ownership');
        }
        return $decoded;
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

    /** @param array<string,mixed>|null $session */
    private static function normalized_session_id(?array $session): string {
        if ($session === null) {
            throw new \RuntimeException('duo: promotion session identity is missing');
        }
        $explicit = (string) ($session['session_id'] ?? '');
        if ($explicit !== '') {
            if (preg_match('/^ps-[a-f0-9]{32}$/D', $explicit) !== 1) {
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

    /** @return array<string,mixed> */
    private static function transition_session(string $owner, string $artifactHash): array {
        $current = self::current();
        $session = self::current_session();
        if ($current === null || $session === null
            || !hash_equals($owner, (string) ($current['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($current['artifact_hash'] ?? ''))
            || !hash_equals($owner, (string) ($session['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($session['artifact_hash'] ?? ''))) {
            throw new \RuntimeException('duo: lifecycle state transition lost its promotion session');
        }
        return $session;
    }

    /**
     * @param array<string,mixed>|null $session
     * @return array{entity:string,phase:string,before_hash:string}|null
     */
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
            || !preg_match('/^[a-f0-9]{64}$/', $attempt['before_hash'])) {
            throw new \RuntimeException('duo: malformed unresolved lifecycle attempt');
        }
        return [
            'entity' => $attempt['entity'],
            'phase' => $attempt['phase'],
            'before_hash' => $attempt['before_hash'],
        ];
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

    /**
     * Remove a matching attempt from the same atomic promotion_session write
     * which publishes its successful pending/final transition.
     *
     * @param array<string,mixed> $session
     * @param list<string> $phases
     * @return array<string,mixed>
     */
    private static function consume_lifecycle_attempt(
        array $session,
        string $entity,
        array $phases,
        string $beforeHash,
        bool $required = false
    ): array {
        $attempt = self::session_lifecycle_attempt($session);
        if ($attempt === null) {
            if ($required) {
                throw new \RuntimeException('duo: successful lifecycle phase has no matching pre-hook attempt receipt');
            }
            return $session;
        }
        if (!hash_equals($entity, $attempt['entity'])
            || !in_array($attempt['phase'], $phases, true)
            || !hash_equals($beforeHash, $attempt['before_hash'])) {
            throw new \RuntimeException(
                'duo: lifecycle attempt boundary changed before its successful receipt could be published'
            );
        }
        unset($session['lifecycle_attempt']);
        return $session;
    }

    /** One live PHP mutation process fences long hooks/filesystem walks. */
    private static function claim_process_fence(): void {
        global $wpdb;
        $name = self::process_fence_name();
        $connection = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
        if (self::$processFenceName === $name
            && self::$processFenceConnection === $connection
            && self::process_fence_is_continuous()) {
            return;
        }
        self::$processFenceName = null;
        self::$processFenceConnection = null;
        self::$leaseSessionOwner = null;
        self::$leaseSessionArtifact = null;
        $acquired = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $name));
        if ((string) $acquired !== '1') {
            throw new \RuntimeException(
                'duo: promotion lock held by another live target process; concurrent target mutation refused'
            );
        }
        self::$processFenceName = $name;
        self::$processFenceConnection = $connection;
    }

    private static function process_fence_is_continuous(): bool {
        global $wpdb;
        if (self::$processFenceName === null || self::$processFenceConnection === null) {
            return false;
        }
        $connection = (int) $wpdb->get_var('SELECT CONNECTION_ID()');
        if ($connection !== self::$processFenceConnection) {
            return false;
        }
        $holder = $wpdb->get_var($wpdb->prepare('SELECT IS_USED_LOCK(%s)', self::$processFenceName));
        return $holder !== null && (int) $holder === $connection;
    }

    private static function release_process_fence(): void {
        global $wpdb;
        $name = self::$processFenceName;
        self::$processFenceName = null;
        self::$processFenceConnection = null;
        self::$leaseSessionOwner = null;
        self::$leaseSessionArtifact = null;
        if ($name !== null) {
            $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    private static function process_fence_name(): string {
        global $wpdb;
        $database = is_string($wpdb->dbname ?? null) ? $wpdb->dbname : '';
        return 'duo:' . substr(hash('sha256', $database . '|' . $wpdb->prefix), 0, 59);
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
