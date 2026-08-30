<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';
require_once __DIR__ . '/../Kernel/MetaRows.php';
require_once __DIR__ . '/../Kernel/Uuid.php';
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}

/** Exact live-identity proof for a canonical password-protected post. */
final class ProtectedPostIdentity {
    private const IDENTITY_KEY = '_wprism_uuid';
    private const OBSERVATION_LIMIT = 3;
    private const MAX_IDENTITY_ROWS = 100000;
    private const PURPOSE = 'protected post identity locking';

    /**
     * Observe map identity, physical type/password, the owner's sidecar, and
     * global post/term UUID uniqueness in one statement snapshot. Returning
     * the password inside this witness prevents plan from combining a valid
     * identity at one instant with a reused row's password at another.
     *
     * @return ?array{post_id:int,post_password:string}
     */
    public static function observe(string $uuid, string $expectedPostType): ?array {
        self::assert_inputs($uuid, $expectedPostType);
        global $wpdb;
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT m.uuid AS mapped_uuid, m.id_kind AS mapped_kind, m.local_id, m.entity_type, '
            . 'p.ID, p.post_type, p.post_password, '
            . 'opm.meta_id AS owner_meta_id, opm.meta_key AS owner_meta_key, '
            . 'LEFT(opm.meta_value, 37) AS owner_meta_value_prefix, '
            . 'OCTET_LENGTH(opm.meta_value) AS owner_meta_value_bytes, '
            . 'gpm.meta_id AS global_post_meta_id, gpm.post_id AS global_post_id, '
            . 'gtm.meta_id AS global_term_meta_id, gtm.term_id AS global_term_id '
            . "FROM {$wpdb->prefix}wprism_map m "
            . "LEFT JOIN {$wpdb->posts} p ON p.ID = m.local_id "
            . "LEFT JOIN {$wpdb->postmeta} opm ON opm.post_id = p.ID AND opm.meta_key = %s "
            . "LEFT JOIN {$wpdb->postmeta} gpm ON gpm.meta_key = %s "
            . 'AND BINARY gpm.meta_key = BINARY %s AND BINARY gpm.meta_value = BINARY m.uuid '
            . "LEFT JOIN {$wpdb->termmeta} gtm ON gtm.meta_key = %s "
            . 'AND BINARY gtm.meta_key = BINARY %s AND BINARY gtm.meta_value = BINARY m.uuid '
            . 'WHERE m.uuid = %s AND m.id_kind = %s '
            . 'ORDER BY opm.meta_id ASC, gpm.meta_id ASC, gtm.meta_id ASC LIMIT '
            . self::OBSERVATION_LIMIT,
            self::IDENTITY_KEY,
            self::IDENTITY_KEY,
            self::IDENTITY_KEY,
            self::IDENTITY_KEY,
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
        return self::verified_observation($rows[0], $uuid, $expectedPostType);
    }

    /**
     * Lock the map tuple, physical post, and the complete post/term identity
     * key ranges. The caller must retain the surrounding repeatable-read
     * transaction and DeleteGuardEvaluator continuity witness through write,
     * readback, and terminal transaction control.
     */
    public static function lock(string $uuid, string $expectedPostType): ?int {
        self::assert_inputs($uuid, $expectedPostType);
        global $wpdb;
        $mapTable = $wpdb->prefix . 'wprism_map';
        DeleteGuardEvaluator::assert_innodb_tables([
            $mapTable,
            $wpdb->posts,
            $wpdb->postmeta,
            $wpdb->termmeta,
        ], self::PURPOSE);
        $mapIndex = DeleteGuardEvaluator::full_width_composite_unique_lock_index(
            $mapTable,
            ['uuid', 'id_kind'],
            self::PURPOSE
        );
        $postIndex = DeleteGuardEvaluator::full_width_lock_index(
            $wpdb->posts,
            'ID',
            self::PURPOSE,
            true
        );
        $postmetaIndex = DeleteGuardEvaluator::bounded_prefix_lock_index(
            $wpdb->postmeta,
            'meta_key',
            strlen(self::IDENTITY_KEY),
            self::PURPOSE
        );
        $termmetaIndex = DeleteGuardEvaluator::bounded_prefix_lock_index(
            $wpdb->termmeta,
            'meta_key',
            strlen(self::IDENTITY_KEY),
            self::PURPOSE
        );
        DeleteGuardEvaluator::assert_transaction_isolation(self::PURPOSE);

        $mappings = self::checked_rows($wpdb->prepare(
            "SELECT uuid, id_kind, local_id, entity_type FROM `$mapTable` FORCE INDEX (`$mapIndex`) "
            . 'WHERE uuid = %s AND id_kind = %s ORDER BY local_id ASC LIMIT 2 FOR UPDATE',
            $uuid,
            Ledger::KIND_POST
        ), 'locked ledger lookup');
        if ($mappings === []) {
            return null;
        }
        if (count($mappings) !== 1) {
            throw self::mismatch();
        }
        $mapping = $mappings[0];
        $localId = self::positive_id($mapping['local_id'] ?? null);
        if (array_keys($mapping) !== ['uuid', 'id_kind', 'local_id', 'entity_type']
            || !is_string($mapping['uuid'] ?? null)
            || !hash_equals($uuid, $mapping['uuid'])
            || ($mapping['id_kind'] ?? null) !== Ledger::KIND_POST
            || $localId === null
            || ($mapping['entity_type'] ?? null) !== 'post') {
            throw self::mismatch();
        }

        $posts = self::checked_rows($wpdb->prepare(
            "SELECT ID, post_type FROM {$wpdb->posts} FORCE INDEX (`$postIndex`) "
            . 'WHERE ID = %d ORDER BY ID ASC LIMIT 2 FOR UPDATE',
            $localId
        ), 'locked live-post lookup');
        if (count($posts) !== 1) {
            throw self::mismatch();
        }
        $post = $posts[0];
        if (array_keys($post) !== ['ID', 'post_type']
            || self::positive_id($post['ID'] ?? null) !== $localId
            || !is_string($post['post_type'] ?? null)
            || !hash_equals($expectedPostType, $post['post_type'])) {
            throw self::mismatch();
        }

        self::assert_locked_global_identity(
            $uuid,
            $localId,
            self::locked_meta_rows($wpdb->postmeta, 'post_id', $postmetaIndex),
            self::locked_meta_rows($wpdb->termmeta, 'term_id', $termmetaIndex)
        );
        return $localId;
    }

    /** @param array<string,mixed> $row @return array{post_id:int,post_password:string} */
    private static function verified_observation(array $row, string $uuid, string $expectedPostType): array {
        $keys = [
            'mapped_uuid', 'mapped_kind', 'local_id', 'entity_type', 'ID', 'post_type', 'post_password',
            'owner_meta_id', 'owner_meta_key', 'owner_meta_value_prefix', 'owner_meta_value_bytes',
            'global_post_meta_id', 'global_post_id', 'global_term_meta_id', 'global_term_id',
        ];
        $localId = self::positive_id($row['local_id'] ?? null);
        $postId = self::positive_id($row['ID'] ?? null);
        $ownerMetaId = self::positive_id($row['owner_meta_id'] ?? null);
        $globalPostMetaId = self::positive_id($row['global_post_meta_id'] ?? null);
        $globalPostId = self::positive_id($row['global_post_id'] ?? null);
        if (array_keys($row) !== $keys
            || !is_string($row['mapped_uuid'] ?? null)
            || !hash_equals($uuid, $row['mapped_uuid'])
            || ($row['mapped_kind'] ?? null) !== Ledger::KIND_POST
            || $localId === null
            || $postId !== $localId
            || ($row['entity_type'] ?? null) !== 'post'
            || !is_string($row['post_type'] ?? null)
            || !hash_equals($expectedPostType, $row['post_type'])
            || !is_string($row['post_password'] ?? null)
            || $ownerMetaId === null
            || ($row['owner_meta_key'] ?? null) !== self::IDENTITY_KEY
            || ($row['owner_meta_value_bytes'] ?? null) !== '36'
            || !is_string($row['owner_meta_value_prefix'] ?? null)
            || !hash_equals($uuid, $row['owner_meta_value_prefix'])
            || $globalPostMetaId !== $ownerMetaId
            || $globalPostId !== $localId
            || ($row['global_term_meta_id'] ?? null) !== null
            || ($row['global_term_id'] ?? null) !== null) {
            throw self::mismatch();
        }
        return ['post_id' => $localId, 'post_password' => $row['post_password']];
    }

    /**
     * @param list<array<string,mixed>> $postRows
     * @param list<array<string,mixed>> $termRows
     */
    private static function assert_locked_global_identity(
        string $uuid,
        int $localId,
        array $postRows,
        array $termRows
    ): void {
        $localExact = [];
        $globalPost = [];
        foreach ($postRows as $row) {
            $ownerId = self::positive_id($row['owner_id'] ?? null);
            if ($ownerId === $localId && ($row['meta_key'] ?? null) !== self::IDENTITY_KEY) {
                throw self::mismatch();
            }
            if ($ownerId === $localId && ($row['meta_key'] ?? null) === self::IDENTITY_KEY) {
                $localExact[] = $row;
            }
            if (self::row_has_uuid($row, $uuid)) {
                $globalPost[] = $row;
            }
        }
        $globalTerm = array_values(array_filter(
            $termRows,
            static fn(array $row): bool => self::row_has_uuid($row, $uuid)
        ));
        if (count($localExact) !== 1
            || !self::row_has_uuid($localExact[0], $uuid)
            || count($globalPost) !== 1
            || self::positive_id($globalPost[0]['owner_id'] ?? null) !== $localId
            || $globalTerm !== []) {
            throw self::mismatch();
        }
    }

    /** @return list<array<string,mixed>> */
    private static function locked_meta_rows(string $table, string $ownerColumn, string $index): array {
        global $wpdb;
        $rows = self::checked_rows($wpdb->prepare(
            "SELECT meta_id, `$ownerColumn` AS owner_id, meta_key, "
            . 'LEFT(meta_value, 37) AS meta_value_prefix, OCTET_LENGTH(meta_value) AS meta_value_bytes '
            . "FROM `$table` FORCE INDEX (`$index`) WHERE meta_key = %s "
            . 'ORDER BY meta_id ASC LIMIT ' . (self::MAX_IDENTITY_ROWS + 1) . ' FOR UPDATE',
            self::IDENTITY_KEY
        ), "locked $table identity range");
        if (count($rows) > self::MAX_IDENTITY_ROWS) {
            throw new \RuntimeException('wprism: protected post identity range exceeded its bounded row limit');
        }
        $previousMetaId = 0;
        foreach ($rows as $position => $row) {
            $metaId = is_array($row) ? self::positive_id($row['meta_id'] ?? null) : null;
            $ownerId = is_array($row) ? self::positive_id($row['owner_id'] ?? null) : null;
            $valueBytes = is_array($row) ? ($row['meta_value_bytes'] ?? null) : null;
            if (!is_array($row)
                || array_keys($row) !== ['meta_id', 'owner_id', 'meta_key', 'meta_value_prefix', 'meta_value_bytes']
                || $metaId === null
                || $ownerId === null
                || $metaId <= $previousMetaId
                || !is_string($row['meta_key'] ?? null)
                || strlen($row['meta_key']) > 1020
                || !(is_string($row['meta_value_prefix'] ?? null) || ($row['meta_value_prefix'] ?? null) === null)
                || !(is_string($valueBytes) || $valueBytes === null)
                || (is_string($valueBytes) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $valueBytes) !== 1)) {
                throw new \RuntimeException(
                    "wprism: protected post identity range returned a malformed row at bounded position $position"
                );
            }
            $previousMetaId = $metaId;
        }
        return $rows;
    }

    /** @param array<string,mixed> $row */
    private static function row_has_uuid(array $row, string $uuid): bool {
        return ($row['meta_key'] ?? null) === self::IDENTITY_KEY
            && ($row['meta_value_bytes'] ?? null) === '36'
            && is_string($row['meta_value_prefix'] ?? null)
            && hash_equals($uuid, $row['meta_value_prefix']);
    }

    /** @return list<array<string,mixed>> */
    private static function checked_rows(mixed $sql, string $context): array {
        global $wpdb;
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: protected post identity $context failed");
        }
        return $rows;
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
            'wprism: protected post binding identity does not match its unique exact live backing row'
        );
    }
}
