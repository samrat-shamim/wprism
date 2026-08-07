<?php
namespace Duo;

/**
 * Target-authoritative promotion lease.
 *
 * The host orchestrator runs deploy and apply in separate wp-cli processes,
 * so a process-local mutex or MySQL connection advisory lock cannot span the
 * lifecycle boundary. The lease therefore lives in the target database's
 * duo_kv table. Acquisition is one INSERT ... ON DUPLICATE KEY UPDATE whose
 * conditional assignment admits only the current owner or an expired owner;
 * readback proves who won the race. A successful deploy may hand the same
 * owner token to apply, while a crashed owner becomes recoverable after the
 * bounded lease expires.
 *
 * This gate serializes Duo writers only. Public reads and unrelated runtime
 * writes remain available; Apply's optimistic recheck is the second half of
 * the safety boundary for authored state changed by live traffic.
 */
final class PromotionLock {
    private const KEY = 'promotion_lock';
    private const DEFAULT_TTL = 300;

    /** @return array{owner:string,artifact_hash:string,phase:string,acquired_at:int,expires_at:int,recovered:bool} */
    public static function acquire(string $owner, string $artifactHash, string $phase, ?int $ttl = null): array {
        global $wpdb;
        self::assert_identity($owner, $artifactHash);
        $ttl = self::ttl($ttl);
        $now = time();
        $before = self::current();
        $payload = self::payload($owner, $artifactHash, $phase, $now, $now + $ttl);
        $table = $wpdb->prefix . 'duo_kv';
        $sql = $wpdb->prepare(
            "INSERT INTO `$table` (k, v) VALUES (%s, %s)
             ON DUPLICATE KEY UPDATE v = IF(
                CAST(COALESCE(JSON_UNQUOTE(JSON_EXTRACT(v, '$.expires_at')), '0') AS UNSIGNED) <= %d
                OR (
                    JSON_UNQUOTE(JSON_EXTRACT(v, '$.owner')) = %s
                    AND JSON_UNQUOTE(JSON_EXTRACT(v, '$.artifact_hash')) = %s
                ),
                VALUES(v), v
             )",
            self::KEY,
            wp_json_encode($payload),
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
        if (!hash_equals($artifactHash, (string) ($current['artifact_hash'] ?? ''))) {
            throw new \RuntimeException('duo: promotion lock owner attempted to change its compiled artifact');
        }
        $current['recovered'] = $before !== null
            && (int) ($before['expires_at'] ?? 0) <= $now
            && (string) ($before['owner'] ?? '') !== $owner;
        return $current;
    }

    public static function heartbeat(string $owner, string $artifactHash, string $phase, ?int $ttl = null): void {
        global $wpdb;
        self::assert_identity($owner, $artifactHash);
        $ttl = self::ttl($ttl);
        $now = time();
        $current = self::current();
        if ($current === null
            || !hash_equals($owner, (string) ($current['owner'] ?? ''))
            || !hash_equals($artifactHash, (string) ($current['artifact_hash'] ?? ''))
            || (int) ($current['expires_at'] ?? 0) <= $now) {
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

    public static function release(string $owner, string $artifactHash): void {
        global $wpdb;
        self::assert_identity($owner, $artifactHash);
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
