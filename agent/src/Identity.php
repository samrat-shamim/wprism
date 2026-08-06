<?php
namespace Duo;

/** Durable identity validation shared by capture, plan, apply, and recovery. */
final class Identity {
    public static function assert_embedded_unique(): void {
        global $wpdb;
        $rows = array_merge(
            self::meta_rows($wpdb->postmeta, 'post_id', 'post'),
            self::meta_rows($wpdb->termmeta, 'term_id', 'term')
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

    private static function meta_rows(string $table, string $ownerColumn, string $kind): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT `$ownerColumn` AS local_id, meta_value AS uuid FROM `$table` "
            . "WHERE meta_key = '_duo_uuid' ORDER BY `$ownerColumn` ASC, meta_id ASC",
            ARRAY_A
        ) ?: [];
        return array_map(static fn(array $r): array => [
            'kind' => $kind,
            'local_id' => (int) $r['local_id'],
            'uuid' => (string) $r['uuid'],
        ], $rows);
    }

    /** Validate the completed capture graph, including nested menu-item identities. */
    public static function assert_entities_unique(array $entities): void {
        $seen = [];
        foreach ($entities as $entity) {
            if (($entity['type'] ?? '') === 'options') {
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
