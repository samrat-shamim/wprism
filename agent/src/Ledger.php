<?php
namespace Duo;

/**
 * Per-environment ledger: typed identity map (uuid, entity_type, id_kind) -> local id,
 * canonical-content hashes at last sync (the 3-way base), and a small kv store.
 * Lives in the environment's DB, never in the repo.
 */
final class Ledger {
    public const KIND_POST = 'post';
    public const KIND_TERM = 'term';
    public const KIND_TT   = 'term_taxonomy';

    public static function ensure(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}duo_map (
            uuid CHAR(36) NOT NULL,
            entity_type VARCHAR(32) NOT NULL,
            id_kind VARCHAR(16) NOT NULL,
            local_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (uuid, id_kind),
            UNIQUE KEY kind_local (id_kind, local_id)
        ) $charset");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}duo_state (
            uuid VARCHAR(64) NOT NULL,
            entity_type VARCHAR(32) NOT NULL,
            content_hash CHAR(64) NOT NULL,
            PRIMARY KEY (uuid)
        ) $charset");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}duo_kv (
            k VARCHAR(191) NOT NULL,
            v LONGTEXT NULL,
            PRIMARY KEY (k)
        ) $charset");
        $wpdb->query("CREATE TABLE IF NOT EXISTS {$p}duo_journal (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            t DATETIME NOT NULL,
            op VARCHAR(8) NOT NULL,
            tbl VARCHAR(64) NOT NULL,
            item VARCHAR(191) NOT NULL DEFAULT '',
            surface VARCHAR(32) NOT NULL,
            actor BIGINT UNSIGNED NOT NULL DEFAULT 0,
            caps VARCHAR(64) NOT NULL DEFAULT '',
            hook VARCHAR(191) NOT NULL DEFAULT '',
            proposal VARCHAR(16) NOT NULL,
            PRIMARY KEY (id),
            KEY tbl_item (tbl, item)
        ) $charset");
    }

    public static function id_for(string $uuid, string $kind): ?int {
        global $wpdb;
        $id = $wpdb->get_var($wpdb->prepare(
            "SELECT local_id FROM {$wpdb->prefix}duo_map WHERE uuid = %s AND id_kind = %s",
            $uuid, $kind
        ));
        return $id === null ? null : (int) $id;
    }

    public static function uuid_for(int $localId, string $kind): ?string {
        global $wpdb;
        $uuid = $wpdb->get_var($wpdb->prepare(
            "SELECT uuid FROM {$wpdb->prefix}duo_map WHERE id_kind = %s AND local_id = %d",
            $kind, $localId
        ));
        return $uuid ?: null;
    }

    public static function set(string $uuid, string $entityType, string $kind, int $localId): void {
        global $wpdb;
        // Auto-increment ids get reused (site empty resets counters; deletes
        // outside duo leave stale rows). A stale row holding this (kind,
        // local_id) under a DIFFERENT uuid would win the kind_local unique-key
        // conflict below and silently keep the OLD uuid mapped to the new row
        // — the entity's own _duo_uuid meta is the identity truth, so the
        // contradicting map row must go first.
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}duo_map WHERE id_kind = %s AND local_id = %d AND uuid <> %s",
            $kind, $localId, $uuid
        ));
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}duo_map (uuid, entity_type, id_kind, local_id)
             VALUES (%s, %s, %s, %d)
             ON DUPLICATE KEY UPDATE entity_type = VALUES(entity_type), local_id = VALUES(local_id)",
            $uuid, $entityType, $kind, $localId
        ));
    }

    public static function forget(string $uuid): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}duo_map WHERE uuid = %s", $uuid
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}duo_state WHERE uuid = %s", $uuid
        ));
    }

    public static function state_hash(string $uuid): ?string {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT content_hash FROM {$wpdb->prefix}duo_state WHERE uuid = %s", $uuid
        )) ?: null;
    }

    public static function set_state_hash(string $uuid, string $entityType, string $hash): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}duo_state (uuid, entity_type, content_hash)
             VALUES (%s, %s, %s)
             ON DUPLICATE KEY UPDATE entity_type = VALUES(entity_type), content_hash = VALUES(content_hash)",
            $uuid, $entityType, $hash
        ));
    }

    /** @return array<string, array{entity_type: string, content_hash: string}> keyed by uuid */
    public static function all_state(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT uuid, entity_type, content_hash FROM {$wpdb->prefix}duo_state", ARRAY_A
        );
        $out = [];
        foreach ($rows ?: [] as $r) {
            $out[$r['uuid']] = ['entity_type' => $r['entity_type'], 'content_hash' => $r['content_hash']];
        }
        return $out;
    }

    public static function prune_state(array $keepUuids): void {
        global $wpdb;
        $keep = array_fill_keys($keepUuids, true);
        foreach (self::all_state() as $uuid => $_) {
            if (!isset($keep[$uuid])) {
                $wpdb->query($wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}duo_state WHERE uuid = %s", $uuid
                ));
            }
        }
    }

    /**
     * Drop identity rows whose local row no longer exists. Deletes made
     * outside duo (wp-admin, wp-cli) never touch the ledger, and a stale
     * uuid↔id row makes dangling references resolve asymmetrically between
     * environments (one captures a token, the other the raw id) and turns
     * re-apply-after-local-delete into a silent no-op (the create path sees
     * the dead id and skips the insert). Run wherever canonical state is
     * built — capture and snapshot.
     */
    public static function prune_dead_map(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $wpdb->query(
            "DELETE m FROM {$p}duo_map m LEFT JOIN {$wpdb->posts} po ON po.ID = m.local_id
             WHERE m.id_kind = '" . self::KIND_POST . "' AND po.ID IS NULL"
        );
        $wpdb->query(
            "DELETE m FROM {$p}duo_map m LEFT JOIN {$wpdb->terms} t ON t.term_id = m.local_id
             WHERE m.id_kind = '" . self::KIND_TERM . "' AND t.term_id IS NULL"
        );
        $wpdb->query(
            "DELETE m FROM {$p}duo_map m LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = m.local_id
             WHERE m.id_kind = '" . self::KIND_TT . "' AND tt.term_taxonomy_id IS NULL"
        );
    }

    public static function kv_get(string $k): ?string {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT v FROM {$wpdb->prefix}duo_kv WHERE k = %s", $k
        ));
    }

    public static function kv_set(string $k, string $v): void {
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}duo_kv (k, v) VALUES (%s, %s)
             ON DUPLICATE KEY UPDATE v = VALUES(v)",
            $k, $v
        ));
    }
}
