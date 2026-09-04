<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/DatabaseExceptions.php';

if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
require_once __DIR__ . '/../Kernel/NativeTableDefinition.php';
require_once __DIR__ . '/../Kernel/DatabaseTablePresence.php';
require_once __DIR__ . '/../Kernel/TransactionAuthority.php';

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
     * issue #3246: entity_type shipped as VARCHAR(32). A table-row entity's own
     * 'type' IS its declared table name (Snapshot.php's own docblock/
     * row_tables()) — not a short, freely-chosen abbreviation the way
     * id_kind is — so it must fit whatever WooCommerce/PMPro/etc. actually
     * named their table, not the other way around. Two shipped tables
     * already exceed 32 (woocommerce_shipping_zone_locations, 35;
     * woocommerce_shipping_zone_methods, 33), silently truncated by MySQL
     * on insert — harmless-latent until issue #3209's identity-contradiction
     * guard started strictly comparing stored-vs-computed entity_type on
     * every Ledger::set(), refusing the truncated-vs-full mismatch on the
     * very next recapture. See Snapshot::MAX_ENTITY_TYPE_LEN (the mirrored,
     * manually-synced budget assert this same width backs — the existing
     * MAX_ID_KIND_LEN/id_kind precedent in Snapshot.php, now backed by the
     * shared ID_KIND_WIDTH constant below) and
     * Snapshot::repair_truncated_entity_types() (the migration-time repair
     * for rows already corrupted under the old width).
     */
    private const ENTITY_TYPE_WIDTH = 64;
    /** Long enough for the closed widget_<type> family (including plugin id
     * bases such as widget_tribe-widget-events-qr-code, 34) and the database's
     * own 64-byte identifier ceiling for declared custom-table kinds. */
    public const ID_KIND_WIDTH = 64;
    /**
     * MySQL and MariaDB cap physical table identifiers at 64 characters.
     * Journal's SQL recognizer accepts only single-byte identifier characters,
     * so this is an exact database ceiling rather than a guessed plugin budget.
     */
    public const TABLE_IDENTIFIER_WIDTH = 64;

    private static function checked_get_var(mixed $sql, string $context): mixed {
        global $wpdb;
        $wpdb->last_error = '';
        $value = $wpdb->get_var($sql);
        if ($value === false || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("wprism: ledger read failed: $context");
        }
        return $value;
    }

    private static function checked_get_row(mixed $sql, string $context): ?array {
        global $wpdb;
        $wpdb->last_error = '';
        $row = $wpdb->get_row($sql, ARRAY_A);
        if (($row !== null && !is_array($row)) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("wprism: ledger read failed: $context");
        }
        return $row;
    }

    /** @return array<int,array<string,mixed>> */
    private static function checked_get_results(mixed $sql, string $context): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException("wprism: ledger read failed: $context");
        }
        return $rows;
    }

    public static function ensure(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $charset = $wpdb->get_charset_collate();
        $w = self::ENTITY_TYPE_WIDTH;
        $kw = self::ID_KIND_WIDTH;
        $tw = self::TABLE_IDENTIFIER_WIDTH;
        Db::ensure_tables([
            $p . 'wprism_map' => new NativeTableDefinition([
                'uuid' => ['type' => 'char', 'length' => 36, 'nullable' => false],
                'entity_type' => ['type' => 'varchar', 'length' => $w, 'nullable' => false],
                'id_kind' => ['type' => 'varchar', 'length' => $kw, 'nullable' => false],
                'local_id' => ['type' => 'bigint', 'unsigned' => true, 'nullable' => false],
            ], ['uuid', 'id_kind'], [
                'kind_local' => ['id_kind', 'local_id'],
            ]),
            $p . 'wprism_state' => new NativeTableDefinition([
                'uuid' => ['type' => 'varchar', 'length' => 64, 'nullable' => false],
                'entity_type' => ['type' => 'varchar', 'length' => $w, 'nullable' => false],
                'content_hash' => ['type' => 'char', 'length' => 64, 'nullable' => false],
            ], ['uuid']),
            $p . 'wprism_kv' => new NativeTableDefinition([
                'k' => ['type' => 'varchar', 'length' => 191, 'nullable' => false],
                'v' => ['type' => 'longtext', 'nullable' => true],
            ], ['k']),
            $p . 'wprism_journal' => new NativeTableDefinition([
                'id' => [
                    'type' => 'bigint',
                    'unsigned' => true,
                    'auto_increment' => true,
                    'nullable' => false,
                ],
                't' => ['type' => 'datetime', 'nullable' => false],
                'op' => ['type' => 'varchar', 'length' => 8, 'nullable' => false],
                'tbl' => ['type' => 'varchar', 'length' => $tw, 'nullable' => false],
                'item' => [
                    'type' => 'varchar',
                    'length' => 191,
                    'nullable' => false,
                    'default' => '',
                ],
                'surface' => ['type' => 'varchar', 'length' => 32, 'nullable' => false],
                'actor' => [
                    'type' => 'bigint',
                    'unsigned' => true,
                    'nullable' => false,
                    'default' => 0,
                ],
                'caps' => [
                    'type' => 'varchar',
                    'length' => 64,
                    'nullable' => false,
                    'default' => '',
                ],
                'hook' => [
                    'type' => 'varchar',
                    'length' => 191,
                    'nullable' => false,
                    'default' => '',
                ],
                'proposal' => ['type' => 'varchar', 'length' => 16, 'nullable' => false],
            ], ['id'], [], [
                'tbl_item' => ['tbl', 'item'],
            ]),
        ], $charset, 'ledger schema');
        self::migrate_widen_entity_type();
        self::migrate_widen_id_kind();
    }

    /**
     * Read-only boundary for an already-captured production environment.
     *
     * `ensure()` is deliberately NOT an acceptable prelude to a production
     * export: CREATE/ALTER would turn an observation request into a repair.
     * This helper therefore proves that the three ledger tables the export
     * consumes already exist with the minimum schema that makes their
     * uniqueness promises meaningful, using information_schema SELECTs only.
     * A stale deployment must be repaired through the ordinary capture gate;
     * an export has no authority to make it look current.
     */
    /**
     * The agent's own tables, unprefixed. Every schema statement in this file
     * creates exactly these; anything that enumerates "tables no adapter
     * declares" (Coverage) must skip them, because they are WPrism's ledger, not
     * site state — the T6 adapter walk read `table:wprism_journal … unclassified`
     * in its own assessment before this list existed.
     *
     * @var list<string>
     */
    public const OWN_TABLES = ['wprism_journal', 'wprism_kv', 'wprism_map', 'wprism_state'];

    public static function assert_read_only_schema(): void {
        global $wpdb;
        $tables = [
            $wpdb->prefix . 'wprism_map',
            $wpdb->prefix . 'wprism_state',
            $wpdb->prefix . 'wprism_kv',
        ];
        $placeholders = implode(',', array_fill(0, count($tables), '%s'));
        $columns = self::checked_get_results($wpdb->prepare(
            "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE, COLUMN_TYPE, CHARACTER_MAXIMUM_LENGTH\n"
            . 'FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() '
            . "AND TABLE_NAME IN ($placeholders)",
            ...$tables
        ), 'read-only ledger schema inventory');

        $byTable = [];
        foreach ($columns as $column) {
            $byTable[(string) $column['TABLE_NAME']][(string) $column['COLUMN_NAME']] = $column;
        }
        $need = [
            $wpdb->prefix . 'wprism_map' => ['uuid' => 36, 'entity_type' => self::ENTITY_TYPE_WIDTH, 'id_kind' => self::ID_KIND_WIDTH, 'local_id' => 0],
            $wpdb->prefix . 'wprism_state' => ['uuid' => 64, 'entity_type' => self::ENTITY_TYPE_WIDTH, 'content_hash' => 64],
            $wpdb->prefix . 'wprism_kv' => ['k' => 191, 'v' => 1],
        ];
        foreach ($need as $table => $fields) {
            foreach ($fields as $field => $minimum) {
                $row = $byTable[$table][$field] ?? null;
                if (!is_array($row)) {
                    throw new \RuntimeException("wprism: refresh export refused — required ledger table/column '$table.$field' is missing; run the existing capture gate to provision or repair it");
                }
                if ($field === 'local_id') {
                    $type = strtolower((string) ($row['COLUMN_TYPE'] ?? ''));
                    if (!str_contains($type, 'bigint') || !str_contains($type, 'unsigned')) {
                        throw new \RuntimeException("wprism: refresh export refused — ledger column '$table.$field' is not an unsigned BIGINT identity");
                    }
                    continue;
                }
                $length = (int) ($row['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
                if ($length < $minimum) {
                    throw new \RuntimeException("wprism: refresh export refused — ledger column '$table.$field' is narrower than the supported durable identity schema");
                }
            }
        }

        $indexes = self::checked_get_results($wpdb->prepare(
            "SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME\n"
            . 'FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() '
            . "AND TABLE_NAME IN ($placeholders) ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX",
            ...$tables
        ), 'read-only ledger index inventory');
        $byIndex = [];
        foreach ($indexes as $index) {
            $byIndex[(string) $index['TABLE_NAME']][(string) $index['INDEX_NAME']][] = $index;
        }
        foreach ([
            [$wpdb->prefix . 'wprism_map', ['uuid', 'id_kind']],
            [$wpdb->prefix . 'wprism_map', ['id_kind', 'local_id']],
            [$wpdb->prefix . 'wprism_state', ['uuid']],
            [$wpdb->prefix . 'wprism_kv', ['k']],
        ] as [$table, $fields]) {
            $found = false;
            foreach ($byIndex[$table] ?? [] as $rows) {
                if ((int) ($rows[0]['NON_UNIQUE'] ?? 1) !== 0) {
                    continue;
                }
                if (array_column($rows, 'COLUMN_NAME') === $fields) {
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                throw new \RuntimeException(
                    "wprism: refresh export refused — ledger table '$table' lacks the required unique identity index ("
                    . implode(', ', $fields) . ')'
                );
            }
        }

        // This SELECT-only structural pass catches accidental/manual table
        // edits before individual content rows are trusted below.
        self::assert_read_only_map_inventory();
    }

    /**
     * Prove one identity mapping already exists and says exactly what the
     * live row + embedded identity say. Export must never use Ledger::set()
     * as an implicit repair; a missing or contradicting tuple is recovery
     * work, not a value the observer is allowed to synthesize.
     */
    public static function require_read_only_mapping(
        string $uuid,
        string $entityType,
        string $kind,
        int $localId,
        string $where
    ): void {
        if (!Uuid::is($uuid) || $entityType === '' || $kind === '' || $localId <= 0) {
            throw new \RuntimeException("wprism: refresh export refused — invalid durable identity at $where");
        }
        global $wpdb;
        $byUuid = self::checked_get_row($wpdb->prepare(
            "SELECT entity_type, local_id FROM {$wpdb->prefix}wprism_map WHERE uuid = %s AND id_kind = %s",
            $uuid, $kind
        ), 'read-only identity lookup by UUID');
        $byLocal = self::checked_get_row($wpdb->prepare(
            "SELECT uuid, entity_type FROM {$wpdb->prefix}wprism_map WHERE id_kind = %s AND local_id = %d",
            $kind, $localId
        ), 'read-only identity lookup by local id');
        if ($byUuid === null || $byLocal === null) {
            throw new \RuntimeException(
                "wprism: refresh export refused — durable identity is missing for $where ($kind:$localId); "
                . 'run the existing capture/identity recovery gate before exporting production'
            );
        }
        if ((int) $byUuid['local_id'] !== $localId
            || (string) $byUuid['entity_type'] !== $entityType
            || (string) $byLocal['uuid'] !== $uuid
            || (string) $byLocal['entity_type'] !== $entityType) {
            throw new \RuntimeException(
                "wprism: refresh export refused — durable identity contradicts live $where ($uuid, $kind:$localId)"
            );
        }
    }

    /** `assert_read_only_schema()`'s map half: no DML, no pruning. */
    private static function assert_read_only_map_inventory(): void {
        $uuidKinds = [];
        $locals = [];
        foreach (self::all_map() as $row) {
            $uuid = (string) $row['uuid'];
            $kind = (string) $row['id_kind'];
            $type = (string) $row['entity_type'];
            $local = (int) $row['local_id'];
            if (!Uuid::is($uuid) || $kind === '' || strlen($kind) > self::ID_KIND_WIDTH
                || $type === '' || strlen($type) > self::ENTITY_TYPE_WIDTH || $local <= 0) {
                throw new \RuntimeException('wprism: refresh export refused — ledger map contains an invalid durable identity tuple');
            }
            $uuidKey = "$uuid|$kind";
            $localKey = "$kind|$local";
            if (isset($uuidKinds[$uuidKey]) || isset($locals[$localKey])) {
                throw new \RuntimeException('wprism: refresh export refused — ledger map contains duplicate durable identities');
            }
            $uuidKinds[$uuidKey] = true;
            $locals[$localKey] = true;
        }
    }

    private static function migrate_widen_id_kind(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wprism_map';
        $width = self::ID_KIND_WIDTH;
        $len = self::checked_get_var($wpdb->prepare(
            'SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS '
            . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'id_kind'",
            $table
        ), 'schema width lookup for wprism_map.id_kind');
        if ($len !== null && (int) $len < $width) {
            Db::ensure_varchar_column_width(
                $table,
                'id_kind',
                $width,
                false,
                'ledger migrate widen wprism_map.id_kind'
            );
        }
    }

    /**
     * issue #3246: CREATE TABLE IF NOT EXISTS above never widens a table that
     * already exists — any environment that created wprism_map/wprism_state
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
        foreach (['wprism_map', 'wprism_state'] as $table) {
            $len = self::checked_get_var($wpdb->prepare(
                'SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS '
                . "WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = 'entity_type'",
                $p . $table
            ), "schema width lookup for $table.entity_type");
            if ($len !== null && (int) $len < $w) {
                Db::ensure_varchar_column_width(
                    $p . $table,
                    'entity_type',
                    $w,
                    false,
                    "ledger migrate widen $table.entity_type"
                );
            }
        }
    }

    public static function id_for(string $uuid, string $kind): ?int {
        global $wpdb;
        $id = self::checked_get_var($wpdb->prepare(
            "SELECT local_id FROM {$wpdb->prefix}wprism_map WHERE uuid = %s AND id_kind = %s",
            $uuid, $kind
        ), 'identity lookup by UUID');
        return $id === null ? null : (int) $id;
    }

    public static function uuid_for(int $localId, string $kind): ?string {
        global $wpdb;
        $uuid = self::checked_get_var($wpdb->prepare(
            "SELECT uuid FROM {$wpdb->prefix}wprism_map WHERE id_kind = %s AND local_id = %d",
            $kind, $localId
        ), 'identity lookup by local id');
        return $uuid ?: null;
    }

    public static function set(string $uuid, string $entityType, string $kind, int $localId): void {
        global $wpdb;
        if (!Uuid::is($uuid) || $localId <= 0) {
            throw new \RuntimeException("wprism: invalid ledger identity '$uuid' ($kind:$localId)");
        }
        $byUuid = self::checked_get_row($wpdb->prepare(
            "SELECT entity_type, local_id FROM {$wpdb->prefix}wprism_map WHERE uuid = %s AND id_kind = %s",
            $uuid, $kind
        ), 'identity contradiction check by UUID');
        if ($byUuid !== null && (int) $byUuid['local_id'] !== $localId) {
            throw new \RuntimeException(
                "wprism: identity contradiction: $uuid ($kind) is already bound to local id {$byUuid['local_id']}; "
                . "refusing to rebind it to $localId"
            );
        }
        if ($byUuid !== null && (string) $byUuid['entity_type'] !== $entityType) {
            throw new \RuntimeException(
                "wprism: identity contradiction: $uuid ($kind:$localId) is already typed {$byUuid['entity_type']}; "
                . "refusing to retype it as $entityType"
            );
        }
        $byLocal = self::checked_get_row($wpdb->prepare(
            "SELECT uuid, entity_type FROM {$wpdb->prefix}wprism_map WHERE id_kind = %s AND local_id = %d",
            $kind, $localId
        ), 'identity contradiction check by local id');
        if ($byLocal !== null && $byLocal['uuid'] !== $uuid) {
            throw new \RuntimeException(
                "wprism: identity contradiction: local $kind id $localId is already bound to {$byLocal['uuid']}; "
                . "refusing to replace it with $uuid"
            );
        }
        if ($byLocal !== null && (string) $byLocal['entity_type'] !== $entityType) {
            throw new \RuntimeException(
                "wprism: identity contradiction: local $kind id $localId is already typed {$byLocal['entity_type']}; "
                . "refusing to retype it as $entityType"
            );
        }
        Db::mutation($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}wprism_map (uuid, entity_type, id_kind, local_id)
             SELECT %s, %s, %s, %d",
            $uuid, $entityType, $kind, $localId
        ), '', 'ON DUPLICATE KEY UPDATE entity_type = VALUES(entity_type)', 'ledger upsert identity');
    }

    public static function forget(string $uuid): void {
        global $wpdb;
        Db::mutation(
            "DELETE FROM {$wpdb->prefix}wprism_map",
            $wpdb->prepare('uuid = %s', $uuid),
            '',
            'ledger forget identity'
        );
        Db::mutation(
            "DELETE FROM {$wpdb->prefix}wprism_state",
            $wpdb->prepare('uuid = %s', $uuid),
            '',
            'ledger forget state hash'
        );
    }

    public static function state_hash(string $uuid): ?string {
        global $wpdb;
        return self::checked_get_var($wpdb->prepare(
            "SELECT content_hash FROM {$wpdb->prefix}wprism_state WHERE uuid = %s", $uuid
        ), 'state hash lookup') ?: null;
    }

    public static function set_state_hash(string $uuid, string $entityType, string $hash): void {
        global $wpdb;
        Db::mutation($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}wprism_state (uuid, entity_type, content_hash)
             SELECT %s, %s, %s",
            $uuid, $entityType, $hash
        ), '', 'ON DUPLICATE KEY UPDATE entity_type = VALUES(entity_type), content_hash = VALUES(content_hash)', 'ledger upsert state hash');
    }

    /** @return array<string, array{entity_type: string, content_hash: string}> keyed by uuid */
    public static function all_state(): array {
        global $wpdb;
        $rows = self::checked_get_results(
            "SELECT uuid, entity_type, content_hash FROM {$wpdb->prefix}wprism_state",
            'state hash inventory'
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
        $rows = self::checked_get_results(
            "SELECT uuid, entity_type, id_kind, local_id FROM {$wpdb->prefix}wprism_map "
            . 'ORDER BY id_kind ASC, local_id ASC, uuid ASC',
            'identity inventory'
        );
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
                Db::mutation(
                    "DELETE FROM {$wpdb->prefix}wprism_state",
                    $wpdb->prepare('uuid = %s', $uuid),
                    '',
                    'ledger prune state hash'
                );
            }
        }
    }

    /**
     * Drop identity rows whose local row no longer exists. Deletes made
     * outside wprism (wp-admin, wp-cli) never touch the ledger, and a stale
     * uuid↔id row makes dangling references resolve asymmetrically between
     * environments (one captures a token, the other the raw id) and turns
     * re-apply-after-local-delete into a silent no-op (the create path sees
     * the dead id and skips the insert). Run wherever canonical state is
     * built — capture and snapshot.
     */
    public static function prune_dead_map(): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $map = "`{$p}wprism_map`";
        Db::mutation(
            "DELETE FROM $map",
            "id_kind = '" . self::KIND_POST . "' AND NOT EXISTS ("
                . "SELECT 1 FROM `{$wpdb->posts}` po WHERE po.`ID` = $map.`local_id`)",
            '',
            'ledger prune dead post identities',
            [$wpdb->posts]
        );
        Db::mutation(
            "DELETE FROM $map",
            "id_kind = '" . self::KIND_TERM . "' AND NOT EXISTS ("
                . "SELECT 1 FROM `{$wpdb->terms}` t WHERE t.`term_id` = $map.`local_id`)",
            '',
            'ledger prune dead term identities',
            [$wpdb->terms]
        );
        Db::mutation(
            "DELETE FROM $map",
            "id_kind = '" . self::KIND_TT . "' AND NOT EXISTS ("
                . "SELECT 1 FROM `{$wpdb->term_taxonomy}` tt "
                . "WHERE tt.`term_taxonomy_id` = $map.`local_id`)",
            '',
            'ledger prune dead term-taxonomy identities',
            [$wpdb->term_taxonomy]
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
     * ledger (no _wprism_uuid-equivalent column — see Snapshot.php's docblock),
     * so this is the ONLY reconciliation mechanism dead map rows for these
     * id_kinds ever get; skipping it would let a deleted row's uuid linger
     * forever, silently colliding with a future row that reuses the same
     * auto-increment local_id (exactly the hazard prune_dead_map() exists to
     * close for posts/terms, just with no meta-column fallback here).
     *
     * @param array<string, array{table: string, pk: string}> $tables
     *   id_kind => {table: UNPREFIXED table name, pk: primary key column}
     * @param array<string, int[]> $preserveLocalIds
     *   ids which are still referenced by an authored option-name namespace
     *   (or by a frozen canonical option document). Those references remain
     *   meaningful even when the owning typed row has already disappeared;
     *   pruning their map entry would make capture drop a live settings row
     *   before the deletion planner can pair it with its option tombstone.
     */
    public static function prune_dead_table_map(array $tables, array $preserveLocalIds = []): void {
        global $wpdb;
        $p = $wpdb->prefix;
        $map = "`{$p}wprism_map`";
        foreach ($tables as $idKind => $decl) {
            $table = preg_replace('/[^A-Za-z0-9_]/', '', $decl['table']);
            $pk = preg_replace('/[^A-Za-z0-9_]/', '', $decl['pk']);
            if (!self::checked_get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $p . $table),
                'typed identity table lookup'
            )) {
                continue; // plugin's table not present on this environment — nothing to reconcile
            }
            $keep = [];
            foreach ((array) ($preserveLocalIds[$idKind] ?? []) as $id) {
                // Callers derive these from strict numeric option-name
                // captures or Ledger itself; re-check here because this is
                // the final SQL boundary and an invalid value must never
                // widen the DELETE predicate or become executable SQL.
                if (is_int($id) && $id > 0) {
                    $keep[$id] = true;
                }
            }
            $keepClause = $keep
                ? ' AND local_id NOT IN (' . implode(',', array_keys($keep)) . ')'
                : '';
            $source = "`{$p}{$table}`";
            Db::mutation(
                "DELETE FROM $map",
                $wpdb->prepare(
                    "id_kind = %s$keepClause AND NOT EXISTS ("
                        . "SELECT 1 FROM $source src WHERE src.`{$pk}` = $map.`local_id`)",
                    $idKind
                ),
                '',
                "ledger prune dead $table identities",
                [$p . $table]
            );
        }
    }

    /**
     * Dead-map reconciliation for composite_ref tables. Their ledger local_id
     * packs two target-local reference ids rather than naming a scalar PK, so
     * the ordinary single-column join above cannot prove whether the physical
     * row survived. Decode the packed tuple in SQL and delete only a mapping
     * whose exact pair is absent. An absent plugin table remains a lifecycle
     * skip, matching prune_dead_table_map(): activation may recreate it later.
     *
     * @param array<string,array{table:string,columns:string[]}> $tables
     *   id_kind => unprefixed table plus the two identity columns in packing order
     */
    public static function prune_dead_composite_table_map(array $tables, int $componentBits): void {
        global $wpdb;
        if ($componentBits <= 0 || $componentBits > 31) {
            throw new \RuntimeException('wprism: invalid composite identity component width for ledger pruning');
        }
        $componentModulus = 1 << $componentBits;
        $p = $wpdb->prefix;
        $map = "`{$p}wprism_map`";
        foreach ($tables as $idKind => $decl) {
            $rawTable = $decl['table'] ?? null;
            $rawColumns = $decl['columns'] ?? null;
            if (!is_string($idKind) || $idKind === '' || !is_string($rawTable)
                || !is_array($rawColumns) || count($rawColumns) !== 2
                || !is_string($rawColumns[0] ?? null) || !is_string($rawColumns[1] ?? null)) {
                throw new \RuntimeException(
                    "wprism: invalid composite identity table declaration for ledger pruning ($idKind)"
                );
            }
            $table = preg_replace('/[^A-Za-z0-9_]/', '', $rawTable);
            $rawColumns = array_values($rawColumns);
            $columns = array_map(
                static fn($column): string => preg_replace('/[^A-Za-z0-9_]/', '', (string) $column),
                $rawColumns
            );
            if ($table === '' || $table !== $rawTable || $columns !== $rawColumns
                || $columns[0] === '' || $columns[1] === '') {
                throw new \RuntimeException(
                    "wprism: invalid composite identity table declaration for ledger pruning ($idKind)"
                );
            }
            if (!self::checked_get_var(
                $wpdb->prepare('SHOW TABLES LIKE %s', $p . $table),
                'composite typed identity table lookup'
            )) {
                continue;
            }
            $source = "`{$p}{$table}`";
            Db::mutation(
                "DELETE FROM $map",
                $wpdb->prepare(
                    "id_kind = %s AND NOT EXISTS (SELECT 1 FROM $source src "
                        . "WHERE src.`{$columns[0]}` = ($map.`local_id` >> $componentBits) "
                        . "AND src.`{$columns[1]}` = ($map.`local_id` % $componentModulus))",
                    $idKind
                ),
                '',
                "ledger prune dead $table composite identities",
                [$p . $table]
            );
        }
    }

    public static function kv_get(string $k): ?string {
        global $wpdb;
        $row = self::checked_get_row($wpdb->prepare(
            "SELECT k, v FROM {$wpdb->prefix}wprism_kv WHERE k = %s", $k
        ), 'key/value lookup');
        return self::checked_kv_value($row, $k, 'key/value lookup');
    }

    /**
     * Read-only existence fact for first-install observers of the KV store.
     *
     * A missing table is a legitimate virgin-site state; a privilege-hidden,
     * shadowed, failed, or malformed probe is not. Callers still choose
     * explicitly whether absence is meaningful before using kv_get().
     */
    public static function kv_table_installed(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wprism_kv';
        try {
            return DatabaseTablePresence::base_table_exists($table);
        } catch (DatabaseTablePresenceException $failure) {
            throw new \RuntimeException(
                'wprism: ledger read failed: key/value table presence lookup',
                0,
                $failure
            );
        }
    }

    /**
     * Lock one exact key/gap on the caller's original transaction session.
     *
     * WordPress may reconnect and replay a failed SELECT. The SQL predicate
     * makes that replay return no authority on the replacement autocommit
     * session; the post-query continuity proof then refuses the operation.
     */
    public static function kv_get_for_update(
        string $k,
        string $lockIndex,
        TransactionAuthority $authority
    ): ?string {
        global $wpdb;
        if (preg_match('/^[A-Za-z0-9_$]{1,64}$/D', $lockIndex) !== 1) {
            throw new \InvalidArgumentException('key/value locking lookup carries a malformed index');
        }
        self::assert_transaction_authority($authority, 'key/value locking lookup preflight');
        $row = self::checked_get_row($wpdb->prepare(
            "SELECT k, v FROM {$wpdb->prefix}wprism_kv FORCE INDEX (`$lockIndex`)
             WHERE k = %s AND CONNECTION_ID() = %s
             AND BINARY @wprism_tx_session = BINARY %s
             FOR UPDATE",
            $k,
            $authority->connection_id(),
            $authority->session_nonce()
        ), 'key/value locking lookup');
        self::assert_transaction_authority($authority, 'key/value locking lookup postflight');
        return self::checked_kv_value($row, $k, 'key/value locking lookup');
    }

    /**
     * Atomically publish a bounded KV row set only on the locked transaction
     * session. The session predicates live in the mutation itself: a wpdb
     * reconnect may replay it, but can never turn it into an autocommit write.
     *
     * @param array<string,string> $rows
     */
    public static function kv_set_transactional(
        array $rows,
        TransactionAuthority $authority
    ): void {
        global $wpdb;
        if ($rows === [] || count($rows) > 64) {
            throw new \InvalidArgumentException(
                'transactional key/value rows must contain between 1 and 64 entries'
            );
        }
        foreach ($rows as $key => $value) {
            if (!is_string($key) || $key === '' || strlen($key) > 191 || !is_string($value)) {
                throw new \InvalidArgumentException('transactional key/value rows are malformed');
            }
        }
        if ($rows === []) {
            throw new \InvalidArgumentException('transactional key/value rows cannot be empty');
        }
        self::assert_transaction_authority($authority, 'transactional key/value publication preflight');
        $ordered = [];
        foreach ($rows as $key => $value) {
            $ordered[] = ['k' => $key, 'v' => $value];
        }
        Db::transactional_upsert_rows(
            $wpdb->prefix . 'wprism_kv',
            $ordered,
            ['v'],
            $authority,
            'transactional ledger key/value publication'
        );
        self::assert_transaction_authority($authority, 'transactional key/value publication postflight');
    }

    /** Delete one durable key only inside the exact authored transaction. */
    public static function kv_delete_transactional(
        string $k,
        TransactionAuthority $authority
    ): void {
        global $wpdb;
        if ($k === '' || strlen($k) > 191) {
            throw new \InvalidArgumentException('transactional key/value deletion carries a malformed key');
        }
        self::assert_transaction_authority($authority, 'transactional key/value deletion preflight');
        Db::transactional_mutation(
            "DELETE FROM {$wpdb->prefix}wprism_kv",
            $wpdb->prepare('k = %s', $k),
            '',
            $authority,
            'transactional ledger key/value deletion'
        );
        self::assert_transaction_authority($authority, 'transactional key/value deletion postflight');
    }

    public static function kv_set(string $k, string $v): void {
        global $wpdb;
        Db::mutation($wpdb->prepare(
            "INSERT INTO {$wpdb->prefix}wprism_kv (k, v) SELECT %s, %s",
            $k, $v
        ), '', 'ON DUPLICATE KEY UPDATE v = VALUES(v)', 'ledger upsert key/value');
    }

    public static function kv_delete(string $k): void {
        global $wpdb;
        Db::mutation(
            "DELETE FROM {$wpdb->prefix}wprism_kv",
            $wpdb->prepare('k = %s', $k),
            '',
            'ledger delete key/value'
        );
    }

    private static function assert_transaction_authority(
        TransactionAuthority $expected,
        string $context
    ): void {
        if (!$expected->equals(Db::transaction_authority($context))) {
            throw new DatabaseTransactionOutcomeException($context . ' changed database session authority');
        }
    }

    /** A present SQL NULL is corrupt durable evidence, never key absence. */
    private static function checked_kv_value(?array $row, string $key, string $context): ?string {
        if ($row === null) {
            return null;
        }
        $keys = array_keys($row);
        sort($keys, SORT_STRING);
        if ($keys !== ['k', 'v']
            || !is_string($row['k'])
            || !hash_equals($key, $row['k'])
            || !is_string($row['v'])) {
            throw new \RuntimeException("wprism: ledger read failed: $context returned malformed key/value evidence");
        }
        return $row['v'];
    }

    /**
     * All wprism_kv rows whose key starts with $prefix (issue #3234's
     * regen_pending: markers — see Apply::regen_dependencies()). A plain
     * SELECT + PHP-side str_starts_with(), not a SQL LIKE, deliberately:
     * wprism_kv is tiny (applied_revision plus however many regen_pending:
     * rows are currently outstanding — never a real "many rows" table), so
     * there is no performance case for a LIKE query, and this sidesteps
     * needing $wpdb->esc_like() correctness at every call site for what is,
     * today, exactly one caller.
     *
     * @return array<string,string> k => v, for matching keys only
     */
    public static function kv_prefix(string $prefix): array {
        global $wpdb;
        $rows = self::checked_get_results(
            "SELECT k, v FROM {$wpdb->prefix}wprism_kv",
            'key/value prefix inventory'
        );
        $out = [];
        foreach ($rows as $r) {
            if (str_starts_with((string) $r['k'], $prefix)) {
                $out[$r['k']] = (string) $r['v'];
            }
        }
        return $out;
    }
}
