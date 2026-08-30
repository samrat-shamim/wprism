<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/MetaOwnerRangeLock.php';
require_once __DIR__ . '/../Kernel/MetaRows.php';
require_once __DIR__ . '/../Kernel/Uuid.php';
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}

/** Exact live-identity proof for a canonical password-protected post. */
final class ProtectedPostIdentity {
    private const IDENTITY_KEY = '_wprism_uuid';
    private const OBSERVATION_LIMIT = 3;

    /**
     * Observe the ledger, post, and collation-equality sidecar set in one
     * database statement. A statement snapshot cannot combine a stale map
     * from one instant with a replacement post from another.
     */
    public static function observe(string $uuid, string $expectedPostType): ?int {
        self::assert_inputs($uuid, $expectedPostType);
        global $wpdb;
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT m.local_id, m.entity_type, p.ID, p.post_type, pm.meta_id, pm.meta_key, '
            . 'LEFT(pm.meta_value, 37) AS meta_value_prefix, '
            . "OCTET_LENGTH(pm.meta_value) AS meta_value_bytes\n"
            . "FROM {$wpdb->prefix}wprism_map m\n"
            . "LEFT JOIN {$wpdb->posts} p ON p.ID = m.local_id\n"
            . "LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s\n"
            . "WHERE m.uuid = %s AND m.id_kind = %s\n"
            . 'ORDER BY pm.meta_id ASC LIMIT ' . self::OBSERVATION_LIMIT,
            self::IDENTITY_KEY,
            $uuid,
            Ledger::KIND_POST
        ), ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('wprism: protected post identity observation failed');
        }
        if ($rows === []) {
            return null;
        }
        if (count($rows) !== 1) {
            throw self::mismatch();
        }
        return self::verified_row_id($rows[0], $uuid, $expectedPostType);
    }

    /**
     * Lock the map tuple, physical post, and complete metadata owner range.
     * The caller must retain the surrounding repeatable-read transaction
     * until its post_password write and readback have completed.
     */
    public static function lock(string $uuid, string $expectedPostType): ?int {
        self::assert_inputs($uuid, $expectedPostType);
        global $wpdb;
        $mapping = self::checked_row($wpdb->prepare(
            "SELECT local_id, entity_type FROM {$wpdb->prefix}wprism_map "
            . 'WHERE uuid = %s AND id_kind = %s LIMIT 1 FOR UPDATE',
            $uuid,
            Ledger::KIND_POST
        ), 'locked ledger lookup');
        if ($mapping === null) {
            return null;
        }
        $localId = self::positive_id($mapping['local_id'] ?? null);
        if (array_keys($mapping) !== ['local_id', 'entity_type']
            || $localId === null
            || ($mapping['entity_type'] ?? null) !== 'post') {
            throw self::mismatch();
        }

        $post = self::checked_row($wpdb->prepare(
            "SELECT ID, post_type FROM {$wpdb->posts} WHERE ID = %d LIMIT 1 FOR UPDATE",
            $localId
        ), 'locked live-post lookup');
        if (!is_array($post)
            || array_keys($post) !== ['ID', 'post_type']
            || self::positive_id($post['ID'] ?? null) !== $localId
            || !is_string($post['post_type'] ?? null)
            || !hash_equals($expectedPostType, $post['post_type'])) {
            throw self::mismatch();
        }

        $identityRows = MetaOwnerRangeLock::prepare(
            $wpdb->postmeta,
            'post_id',
            'protected post identity owner-range locking'
        )->exact_key_rows($localId, self::IDENTITY_KEY);
        if (count($identityRows) !== 1
            || !is_string($identityRows[0]['meta_value'] ?? null)
            || !hash_equals($uuid, $identityRows[0]['meta_value'])) {
            throw self::mismatch();
        }
        return $localId;
    }

    /** @param array<string,mixed> $row */
    private static function verified_row_id(array $row, string $uuid, string $expectedPostType): int {
        $keys = [
            'local_id', 'entity_type', 'ID', 'post_type', 'meta_id', 'meta_key',
            'meta_value_prefix', 'meta_value_bytes',
        ];
        $localId = self::positive_id($row['local_id'] ?? null);
        $postId = self::positive_id($row['ID'] ?? null);
        $metaId = self::positive_id($row['meta_id'] ?? null);
        if (array_keys($row) !== $keys
            || $localId === null
            || $postId !== $localId
            || $metaId === null
            || ($row['entity_type'] ?? null) !== 'post'
            || !is_string($row['post_type'] ?? null)
            || !hash_equals($expectedPostType, $row['post_type'])
            || ($row['meta_key'] ?? null) !== self::IDENTITY_KEY
            || ($row['meta_value_bytes'] ?? null) !== '36'
            || !is_string($row['meta_value_prefix'] ?? null)
            || !hash_equals($uuid, $row['meta_value_prefix'])) {
            throw self::mismatch();
        }
        return $localId;
    }

    private static function checked_row(mixed $sql, string $context): ?array {
        global $wpdb;
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (($row !== null && !is_array($row)) || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: protected post identity $context failed");
        }
        return $row;
    }

    private static function assert_inputs(string $uuid, string $expectedPostType): void {
        if (!Uuid::is($uuid)
            || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $expectedPostType) !== 1) {
            throw new \RuntimeException('wprism: protected post binding carries malformed identity inputs');
        }
    }

    private static function positive_id(mixed $value): ?int {
        return MetaRows::positive_id($value);
    }

    private static function mismatch(): \RuntimeException {
        return new \RuntimeException(
            'wprism: protected post binding identity does not match its exact live backing row'
        );
    }
}
