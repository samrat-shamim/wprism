<?php
namespace Duo;

/** Durable identity validation shared by capture, plan, apply, and recovery. */
final class Identity {
    private const MAX_EMBEDDED_IDENTITY_ROWS = 100000;
    private const OWNER_VALIDATION_CHUNK = 500;

    public static function assert_embedded_unique(): void {
        global $wpdb;
        $rows = array_merge(
            self::meta_rows($wpdb->postmeta, 'post_id', 'post', $wpdb->posts, 'ID'),
            self::meta_rows($wpdb->termmeta, 'term_id', 'term', $wpdb->terms, 'term_id')
        );
        $byOwner = [];
        $byUuid = [];
        foreach ($rows as $row) {
            $owner = $row['kind'] . ':' . $row['local_id'];
            $uuid = $row['uuid'];
            $byOwner[$owner][] = $uuid;
            if (!Uuid::is($uuid)) {
                throw new \RuntimeException(
                    "duo: invalid _duo_uuid '$uuid' on $owner; capture requires a lowercase RFC UUID"
                );
            }
            $byUuid[$uuid][$owner] = true;
        }
        foreach ($byOwner as $owner => $values) {
            if (count($values) !== 1) {
                throw new \RuntimeException(
                    "duo: duplicate _duo_uuid metadata rows on $owner (" . count($values)
                    . ' rows); refusing to choose one'
                );
            }
        }
        foreach ($byUuid as $uuid => $owners) {
            if (count($owners) > 1) {
                throw new \RuntimeException(
                    "duo: duplicate _duo_uuid $uuid is attached to " . implode(', ', array_keys($owners))
                    . '; copied metadata must be replaced with a fresh identity before capture'
                );
            }
        }
    }

    private static function meta_rows(
        string $table,
        string $ownerColumn,
        string $kind,
        string $ownerTable,
        string $ownerPrimaryKey
    ): array {
        global $wpdb;
        foreach ([$table, $ownerColumn, $ownerTable, $ownerPrimaryKey] as $identifier) {
            if (!is_string($identifier) || preg_match('/^[A-Za-z0-9_]{1,64}$/D', $identifier) !== 1) {
                throw new \RuntimeException("duo: could not validate live $kind identity owners: unsafe SQL identifier");
            }
        }
        if (property_exists($wpdb, 'last_error')) {
            $wpdb->last_error = '';
        }
        $rows = $wpdb->get_results(
            "SELECT `$ownerColumn` AS local_id, LEFT(meta_value, 37) AS uuid, "
            . "OCTET_LENGTH(meta_value) AS uuid_bytes FROM `$table` "
            . "WHERE meta_key = '_duo_uuid' AND BINARY meta_key = BINARY '_duo_uuid' "
            . "ORDER BY `$ownerColumn` ASC, meta_id ASC LIMIT "
            . (self::MAX_EMBEDDED_IDENTITY_ROWS + 1),
            ARRAY_A
        );
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            $detail = trim((string) ($wpdb->last_error ?? ''));
            throw new \RuntimeException(
                "duo: could not validate live $kind identity owners: "
                . ($detail !== '' ? $detail : 'checked identity read returned no result')
            );
        }
        if (count($rows) > self::MAX_EMBEDDED_IDENTITY_ROWS) {
            throw new \RuntimeException("duo: could not validate live $kind identity owners: bounded row limit exceeded");
        }
        $validated = [];
        $previousOwner = 0;
        foreach ($rows as $position => $row) {
            $localId = is_array($row) ? self::positive_id($row['local_id'] ?? null) : null;
            $uuidBytes = is_array($row) ? self::nonnegative_size($row['uuid_bytes'] ?? null) : null;
            if (!is_array($row)
                || array_keys($row) !== ['local_id', 'uuid', 'uuid_bytes']
                || $localId === null
                || !is_string($row['uuid'] ?? null)
                || $uuidBytes === null
                || $uuidBytes > 36
                || strlen($row['uuid']) !== $uuidBytes
                || $localId < $previousOwner) {
                throw new \RuntimeException(
                    "duo: could not validate live $kind identity owners: malformed row at bounded position $position"
                );
            }
            $validated[] = ['kind' => $kind, 'local_id' => $localId, 'uuid' => $row['uuid']];
            $previousOwner = $localId;
        }
        if ($validated === []) {
            return [];
        }

        $ownerIds = array_values(array_unique(array_column($validated, 'local_id')));
        $live = [];
        foreach (array_chunk($ownerIds, self::OWNER_VALIDATION_CHUNK) as $chunk) {
            $placeholders = implode(',', array_fill(0, count($chunk), '%d'));
            if (property_exists($wpdb, 'last_error')) {
                $wpdb->last_error = '';
            }
            $liveIds = $wpdb->get_col($wpdb->prepare(
                "SELECT `$ownerPrimaryKey` FROM `$ownerTable` WHERE `$ownerPrimaryKey` IN ($placeholders) "
                . "ORDER BY `$ownerPrimaryKey` ASC LIMIT " . (self::OWNER_VALIDATION_CHUNK + 1),
                ...$chunk
            ));
            if (!is_array($liveIds)
                || !array_is_list($liveIds)
                || trim((string) ($wpdb->last_error ?? '')) !== ''
                || count($liveIds) > count($chunk)) {
                throw new \RuntimeException("duo: could not validate live $kind identity owners: checked owner read failed");
            }
            $previousLive = 0;
            $requested = array_fill_keys($chunk, true);
            foreach ($liveIds as $position => $liveId) {
                $id = self::positive_id($liveId);
                if ($id === null || !isset($requested[$id]) || $id <= $previousLive) {
                    throw new \RuntimeException(
                        "duo: could not validate live $kind identity owners: malformed owner row at bounded position $position"
                    );
                }
                $live[$id] = true;
                $previousLive = $id;
            }
        }
        // Raw uninstallers can leave exact identity metadata behind after the
        // owner disappears. The bounded checked owner reads exclude that
        // residue without constructing one site-wide placeholder list.
        return array_values(array_filter(
            $validated,
            static fn(array $row): bool => isset($live[$row['local_id']])
        ));
    }

    private static function positive_id(mixed $value): ?int {
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($id) && $id > 0 ? $id : null;
    }

    private static function nonnegative_size(mixed $value): ?int {
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $size = filter_var($value, FILTER_VALIDATE_INT);
        return is_int($size) && $size >= 0 ? $size : null;
    }

    /** Validate the completed capture graph, including nested menu-item/widget identities. */
    public static function assert_entities_unique(array $entities): void {
        $seen = [];
        foreach ($entities as $entity) {
            if (($entity['type'] ?? '') === SidebarState::ENTITY_TYPE) {
                $front = Canon::decode($entity['content']);
                foreach ((array) ($front['widgets'] ?? []) as $i => $widget) {
                    self::claim($seen, (string) ($widget['uuid'] ?? ''), $entity['path'] . "#widgets[$i]");
                }
                continue;
            }
            if (in_array(($entity['type'] ?? ''), ['options', 'user-meta'], true)) {
                continue;
            }
            self::claim($seen, (string) ($entity['uuid'] ?? ''), (string) ($entity['path'] ?? $entity['type']));
            if (($entity['type'] ?? '') === 'menu') {
                $front = Canon::decode($entity['content']);
                foreach ((array) ($front['items'] ?? []) as $i => $item) {
                    self::claim($seen, (string) ($item['uuid'] ?? ''), $entity['path'] . "#items[$i]");
                }
            }
        }
    }

    private static function claim(array &$seen, string $uuid, string $where): void {
        if (!Uuid::is($uuid)) {
            throw new \RuntimeException("duo: invalid entity uuid '$uuid' at $where");
        }
        if (isset($seen[$uuid])) {
            throw new \RuntimeException("duo: duplicate entity uuid $uuid at {$seen[$uuid]} and $where");
        }
        $seen[$uuid] = $where;
    }
}
