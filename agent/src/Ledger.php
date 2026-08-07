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

    /**
     * DUO-3246: entity_type shipped as VARCHAR(32). A table-row entity's own
     * 'type' IS its declared table name (Snapshot.php's own docblock/
     * row_tables()) — not a short, freely-chosen abbreviation the way
     * id_kind is — so it must fit whatever WooCommerce/PMPro/etc. actually
     * named their table, not the other way around. Two shipped tables
     * already exceed 32 (woocommerce_shipping_zone_locations, 35;
     * woocommerce_shipping_zone_methods, 33), silently truncated by MySQL
     * on insert — harmless-latent until DUO-3209's identity-contradiction
     * guard started strictly comparing stored-vs-computed entity_type on
     * every Ledger::set(), refusing the truncated-vs-full mismatch on the
     * very next recapture. See Snapshot::MAX_ENTITY_TYPE_LEN (the mirrored,
     * manually-synced budget assert this same width backs — the existing
     * MAX_ID_KIND_LEN/id_kind precedent in Snapshot.php, applied here
     * instead of copied there, since id_kind's own budget is deliberately
     * enforced against the EXISTING width rather than widened) and
     * Snapshot::repair_truncated_entity_types() (the migration-time repair
     * for rows already corrupted under the old width).
     */
    private const ENTITY_TYPE_WIDTH = 64;

    public static function ensure(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();
        $w = self::ENTITY_TYPE_WIDTH;
        Db::query("CREATE TABLE IF NOT EXISTS {$p}duo_map (
            uuid CHAR(36) NOT NULL,
            entity_type VARCHAR($w) NOT NULL,
            id_kind VARCHAR(16) NOT NULL,
            local_id BIGINT UNSIGNED NOT NULL,
            PRIMARY KEY (uuid, id_kind),
            UNIQUE KEY kind_local (id_kind, local_id)
        ) $charset", 'ledger schema create duo_map');
        Db::query("CREATE TABLE IF NOT EXISTS {$p}duo_state (
            uuid VARCHAR(64) NOT NULL,
            entity_type VARCHAR($w) NOT NULL,
            content_hash CHAR(64) NOT NULL,
            PRIMARY KEY (uuid)
        ) $charset", 'ledger schema create duo_state');
        Db::query("CREATE TABLE IF NOT EXISTS {$p}duo_kv (
            k VARCHAR(191) NOT NULL,
            v LONGTEXT NULL,
            PRIMARY KEY (k)
        ) $charset", 'ledger schema create duo_kv');
        Db::query("CREATE TABLE IF NOT EXISTS {$p}duo_journal (
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
        ) $charset", 'ledger schema create duo_journal');
        self::migrate_widen_entity_type();
    }

    /**
     * DUO-3246: CREATE TABLE IF NOT EXISTS above never widens a table that
     * already exists — any environment that created duo_map/duo_state
     * before this fix shipped stays at the old VARCHAR(32) forever without
     * this. Idempotent by construction: checked via information_schema
     * before ever issuing an ALTER, so an already-migrated environment
     * (the normal case, after the first run post-fix) pays one cheap
     * SELECT per table on every ensure() call, never a repeated ALTER.
     */
    private static function migrate_widen_entity_type(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $w = self::ENTITY_TYPE_WIDTH;
        foreach (['duo_map', 'duo_state'] as $table) {
            $len = $wpdb->get_var($wpdb->prepare(
                'SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS '
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'entity_type'",
                $p . $table
            ));
            if ($len !== null && (int) $len < $w) {
                Db::query(
                    "ALTER TABLE `{$p}{$table}` MODIFY COLUMN entity_type VARCHAR($w) NOT NULL",
                    "ledger migrate widen $table.entity_type"
                );
            }
        }
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
        if (!Uuid::is($uuid) || $localId <= 0) {
            throw new \RuntimeException("duo: invalid ledger identity '$uuid' ($kind:$localId)");
        }
        $byUuid = $wpdb->get_row($wpdb->prepare(
            "SELECT entity_type, local_id FROM {$wpdb->prefix}duo_map WHERE uuid = %s AND id_kind = %s",
            $uuid, $kind
        ), ARRAY_A);
        if ($byUuid !== null && (int) $byUuid['local_id'] !== $localId) {
            throw new \RuntimeException(
                "duo: identity contradiction: $uuid ($kind) is already bound to local id {$byUuid['local_id']}; "
                . "refusing to rebind it to $localId"
            );
        }
        if ($byUuid !== null && (string) $byUuid['entity_type'] !== $entityType) {
            throw new \RuntimeException(
                "duo: identity contradiction: $uuid ($kind:$localId) is already typed {$byUuid['entity_type']}; "
                . "refusing to retype it as $entityType"
            );
        }
        $byLocal = $wpdb->get_row($wpdb->prepare(
            "SELECT uuid, entity_type FROM {$wpdb->prefix}duo_map WHERE id_kind = %s AND local_id = %d",
            $kind, $localId
        ), ARRAY_A);
        if ($byLocal !== null && $byLocal['uuid'] !== $uuid) {
            throw new \RuntimeException(
                "duo: identity contradiction: local $kind id $localId is already bound to {$byLocal['uuid']}; "
                . "refusing to replace it with $uuid"
            );
        }
        if ($byLocal !== null && (string) $byLocal['entity_type'] !== $entityType) {
            throw new \RuntimeException(
                "duo: identity contradiction: local $kind id $localId is already typed {$byLocal['entity_type']}; "
                . "refusing to retype it as $entityType"
            );
        }
        Db::query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}duo_map (uuid, entity_type, id_kind, local_id)
             VALUES (%s, %s, %s, %d)
             ON DUPLICATE KEY UPDATE entity_type = VALUES(entity_type)",
            $uuid, $entityType, $kind, $localId
        ), 'ledger upsert identity');
    }

    public static function forget(string $uuid): void {
        global $wpdb;
        Db::query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}duo_map WHERE uuid = %s", $uuid
        ), 'ledger forget identity');
        Db::query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}duo_state WHERE uuid = %s", $uuid
        ), 'ledger forget state hash');
    }

    public static function state_hash(string $uuid): ?string {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT content_hash FROM {$wpdb->prefix}duo_state WHERE uuid = %s", $uuid
        )) ?: null;
    }

    public static function set_state_hash(string $uuid, string $entityType, string $hash): void {
        global $wpdb;
        Db::query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}duo_state (uuid, entity_type, content_hash)
             VALUES (%s, %s, %s)
             ON DUPLICATE KEY UPDATE entity_type = VALUES(entity_type), content_hash = VALUES(content_hash)",
            $uuid, $entityType, $hash
        ), 'ledger upsert state hash');
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

    /** @return array<int,array{uuid:string,entity_type:string,id_kind:string,local_id:int}> */
    public static function all_map(): array {
        global $wpdb;
        $rows = $wpdb->get_results(
            "SELECT uuid, entity_type, id_kind, local_id FROM {$wpdb->prefix}duo_map "
            . 'ORDER BY id_kind ASC, local_id ASC, uuid ASC', ARRAY_A
        ) ?: [];
        return array_map(static fn(array $r): array => [
            'uuid' => (string) $r['uuid'],
            'entity_type' => (string) $r['entity_type'],
            'id_kind' => (string) $r['id_kind'],
            'local_id' => (int) $r['local_id'],
        ], $rows);
    }

    public static function prune_state(array $keepUuids): void {
        global $wpdb;
        $keep = array_fill_keys($keepUuids, true);
        foreach (self::all_state() as $uuid => $_) {
            if (!isset($keep[$uuid])) {
                Db::query($wpdb->prepare(
                    "DELETE FROM {$wpdb->prefix}duo_state WHERE uuid = %s", $uuid
                ), 'ledger prune state hash');
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
        Db::query(
            "DELETE m FROM {$p}duo_map m LEFT JOIN {$wpdb->posts} po ON po.ID = m.local_id
             WHERE m.id_kind = '" . self::KIND_POST . "' AND po.ID IS NULL",
            'ledger prune dead post identities'
        );
        Db::query(
            "DELETE m FROM {$p}duo_map m LEFT JOIN {$wpdb->terms} t ON t.term_id = m.local_id
             WHERE m.id_kind = '" . self::KIND_TERM . "' AND t.term_id IS NULL",
            'ledger prune dead term identities'
        );
        Db::query(
            "DELETE m FROM {$p}duo_map m LEFT JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = m.local_id
             WHERE m.id_kind = '" . self::KIND_TT . "' AND tt.term_taxonomy_id IS NULL",
            'ledger prune dead term-taxonomy identities'
        );
    }

    /**
     * Generalization of prune_dead_map() above for Snapshot.php's declared
     * custom-table id_kinds — same rationale, same "run wherever canonical
     * state is built" placement, but POLICY-AWARE: unlike post/term/
     * term_taxonomy (core WP concepts, always present, hardcoded above),
     * which (table, pk column) a given id_kind maps to is only known from
     * the currently-loaded manifests, so the caller (Snapshot::capture())
     * supplies it instead of this method assuming a fixed roster.
     *
     * A declared table's rows carry NO identity of their own outside this
     * ledger (no _duo_uuid-equivalent column — see Snapshot.php's docblock),
     * so this is the ONLY reconciliation mechanism dead map rows for these
     * id_kinds ever get; skipping it would let a deleted row's uuid linger
     * forever, silently colliding with a future row that reuses the same
     * auto-increment local_id (exactly the hazard prune_dead_map() exists to
     * close for posts/terms, just with no meta-column fallback here).
     *
     * @param array<string, array{table: string, pk: string}> $tables
     *   id_kind => {table: UNPREFIXED table name, pk: primary key column}
     */
    public static function prune_dead_table_map(array $tables): void {
        global $wpdb;
        $p = $wpdb->prefix;
        foreach ($tables as $idKind => $decl) {
            $table = preg_replace('/[^A-Za-z0-9_]/', '', $decl['table']);
            $pk = preg_replace('/[^A-Za-z0-9_]/', '', $decl['pk']);
            if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $p . $table))) {
                continue; // plugin's table not present on this environment — nothing to reconcile
            }
            Db::query($wpdb->prepare(
                "DELETE m FROM {$p}duo_map m LEFT JOIN `{$p}{$table}` src ON src.`{$pk}` = m.local_id
                 WHERE m.id_kind = %s AND src.`{$pk}` IS NULL",
                $idKind
            ), "ledger prune dead $table identities");
        }
    }

    public static function kv_get(string $k): ?string {
        global $wpdb;
        return $wpdb->get_var($wpdb->prepare(
            "SELECT v FROM {$wpdb->prefix}duo_kv WHERE k = %s", $k
        ));
    }

    public static function kv_set(string $k, string $v): void {
        global $wpdb;
        Db::query($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}duo_kv (k, v) VALUES (%s, %s)
             ON DUPLICATE KEY UPDATE v = VALUES(v)",
            $k, $v
        ), 'ledger upsert key/value');
    }

    public static function kv_delete(string $k): void {
        global $wpdb;
        Db::query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}duo_kv WHERE k = %s",
            $k
        ), 'ledger delete key/value');
    }

    /**
     * All duo_kv rows whose key starts with $prefix (DUO-3234's
     * regen_pending: markers — see Apply::regen_dependencies()). A plain
     * SELECT + PHP-side str_starts_with(), not a SQL LIKE, deliberately:
     * duo_kv is tiny (applied_revision plus however many regen_pending:
     * rows are currently outstanding — never a real "many rows" table), so
     * there is no performance case for a LIKE query, and this sidesteps
     * needing $wpdb->esc_like() correctness at every call site for what is,
     * today, exactly one caller.
     *
     * @return array<string,string> k => v, for matching keys only
     */
    public static function kv_prefix(string $prefix): array {
        global $wpdb;
        $rows = $wpdb->get_results("SELECT k, v FROM {$wpdb->prefix}duo_kv", ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $r) {
            if (str_starts_with((string) $r['k'], $prefix)) {
                $out[$r['k']] = (string) $r['v'];
            }
        }
        return $out;
    }
}
