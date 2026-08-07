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
 * owner recovering an expired lease; readback proves who won the race. Only
 * promotion-begin may create/recover that row and it also records the latest
 * begun owner/artifact session durably. Every explicit orchestrator phase is
 * a continuation of both that session and its exact still-live row, so an
 * obsolete checkpoint cannot restart—or advertise recovery—after another run.
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

    /** Begin a new host session. Only this entry point may create/recover. */
    public static function begin(string $owner, string $artifactHash, ?int $ttl = null): array {
        return self::acquire($owner, $artifactHash, 'checkpoint', $ttl, false);
    }

    /** @return array{owner:string,artifact_hash:string,phase:string,acquired_at:int,expires_at:int,recovered:bool} */
    public static function acquire(
        string $owner,
        string $artifactHash,
        string $phase,
        ?int $ttl = null,
        bool $requireExisting = false
    ): array {
        global $wpdb;
        self::assert_identity($owner, $artifactHash);
        self::claim_process_fence();
        try {
            $ttl = self::ttl($ttl);
            $now = time();
            $before = self::current();
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
            if (!$requireExisting) {
                Ledger::kv_set(self::SESSION_KEY, wp_json_encode([
                    'owner' => $owner,
                    'artifact_hash' => $artifactHash,
                    'begun_at' => $now,
                ]));
            }
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
        $session['state_transition'] = [
            'entity' => $entity,
            'before_hash' => $beforeHash,
            'after_hash' => $afterHash,
        ];
        Ledger::kv_set(self::SESSION_KEY, wp_json_encode($session));
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
        // rebuilders, or probes). Keep their normal WordPress connection
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
