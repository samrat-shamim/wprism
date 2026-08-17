<?php
namespace Duo;

require_once __DIR__ . '/TableGraph.php';

/**
 * Live schema boundary for authored typed tables (DUO-3349).
 *
 * TableSchema owns only live column introspection, write-format derivation,
 * ledger-width checks, and declared/live column reconciliation. It does not
 * load Policy, Ledger, Snapshot, or WordPress bootstrap code: callers inject
 * the pure declaration-grammar assertion and the ledger's id-kind width,
 * while the WordPress database handle is read only when a live method runs.
 * Snapshot retains thin compatibility facades for every historical entry
 * point used by capture and materialization.
 */
final class TableSchema {
    /** duo_map.entity_type/duo_state.entity_type are VARCHAR(64). */
    private const MAX_ENTITY_TYPE_LEN = 64;

    /**
     * @return ?array<string,string> live column name => live MySQL type,
     *   or null if the table does not exist on this environment
     */
    public static function live_column_types(string $table): ?array {
        global $wpdb;
        $t = preg_replace('/[^A-Za-z0-9_]/', '', $table);
        $prefixed = $wpdb->prefix . $t;
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefixed))) {
            return null;
        }
        $rows = $wpdb->get_results("SHOW COLUMNS FROM `$prefixed`", ARRAY_A) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $out[$row['Field']] = $row['Type'];
        }
        return $out;
    }

    /** @return ?string[] live column names, or null when the table is absent */
    public static function live_columns(string $table): ?array {
        $types = self::live_column_types($table);
        return $types === null ? null : array_keys($types);
    }

    /** Whether a live MySQL type needs an unquoted numeric write format. */
    public static function is_bit_column(string $liveType): bool {
        return (bool) preg_match('/^bit\(/i', $liveType);
    }

    /**
     * Build data and positional wpdb formats in lockstep. BIT values must be
     * emitted as unquoted integers; every other typed-table value retains the
     * engine's established string format.
     *
     * @param array<string,mixed> $data
     * @param array<string,string> $colTypes
     * @return array{0: array, 1: string[]}
     */
    public static function write_format(array $data, array $colTypes): array {
        $format = [];
        foreach ($data as $col => $value) {
            if (self::is_bit_column($colTypes[$col] ?? '')) {
                $data[$col] = $value === null ? null : (int) $value;
                $format[] = '%d';
            } else {
                $format[] = '%s';
            }
        }
        return [$data, $format];
    }

    /** Assert that the table name fits the shared entity_type columns. */
    public static function assert_entity_type_width(string $table): void {
        if (strlen($table) > self::MAX_ENTITY_TYPE_LEN) {
            throw new \RuntimeException(
                "duo: table '$table' name is " . strlen($table) . ' chars — a table row entity_type IS the table '
                . 'name itself, which must be 1-' . self::MAX_ENTITY_TYPE_LEN
                . ' chars (duo_map.entity_type/duo_state.entity_type are VARCHAR(' . self::MAX_ENTITY_TYPE_LEN . '))'
            );
        }
    }

    /** Assert that the declaration's id_kind fits duo_map.id_kind. */
    public static function assert_id_kind_width(string $table, array $decl, int $idKindWidth): void {
        $idKind = (string) ($decl['id_kind'] ?? '');
        if ($idKind === '' || strlen($idKind) > $idKindWidth) {
            throw new \RuntimeException(
                "duo: table '$table' declares id_kind '$idKind' — must be 1-$idKindWidth"
                . " chars (duo_map.id_kind is VARCHAR($idKindWidth))"
            );
        }
    }

    /**
     * Reconcile an ordinary or composite row declaration with its live table.
     * The injected grammar callback keeps the declaration-only Policy boundary
     * independently loadable and preserves Snapshot's established check order.
     */
    public static function assert_row_schema(
        string $table,
        array $decl,
        int $idKindWidth,
        callable $assertGrammar
    ): void {
        self::assert_entity_type_width($table);
        $assertGrammar($table, $decl);
        if (TableGraph::is_composite_ref($decl)) {
            self::assert_composite_row_schema($table, $decl, $idKindWidth, $assertGrammar);
            return;
        }
        self::assert_id_kind_width($table, $decl, $idKindWidth);
        $pk = (string) ($decl['pk'] ?? '');
        $colKeys = array_keys($decl['columns'] ?? []);
        $refCols = array_column($decl['refs'] ?? [], 'column');

        $live = self::live_columns($table);
        if ($live === null) {
            throw new \RuntimeException(
                "duo: declared table '$table' does not exist on this environment (plugin inactive, or manifest stale?)"
            );
        }
        $accounted = array_merge([$pk], $colKeys, $refCols);
        $undeclared = array_diff($live, $accounted);
        if ($undeclared) {
            sort($undeclared);
            throw new \RuntimeException(
                "duo: table '$table' has undeclared column(s): " . implode(', ', $undeclared)
                . ' — every real column must be classified in the manifest (as the pk, a ref, or a columns entry'
                . ' with class authored/runtime/derived/env) before this table can be captured; an FK-shaped or'
                . ' otherwise unclassified column must never silently reach canonical state'
            );
        }
        $missing = array_diff($accounted, $live);
        if ($missing) {
            sort($missing);
            throw new \RuntimeException(
                "duo: table '$table' declares column(s) absent from this environment: " . implode(', ', $missing)
                . ' (plugin schema changed? manifest may be pinned to the wrong version range)'
            );
        }
    }

    /** Reconcile a composite-ref row declaration with its live table. */
    public static function assert_composite_row_schema(
        string $table,
        array $decl,
        int $idKindWidth,
        callable $assertGrammar
    ): void {
        self::assert_entity_type_width($table);
        $assertGrammar($table, $decl);
        self::assert_id_kind_width($table, $decl, $idKindWidth);
        $refCols = array_column($decl['refs'] ?? [], 'column');
        $colKeys = array_keys($decl['columns'] ?? []);

        $live = self::live_columns($table);
        if ($live === null) {
            throw new \RuntimeException(
                "duo: declared table '$table' does not exist on this environment (plugin inactive, or manifest stale?)"
            );
        }
        $accounted = array_merge($refCols, $colKeys);
        $undeclared = array_diff($live, $accounted);
        if ($undeclared) {
            sort($undeclared);
            throw new \RuntimeException(
                "duo: table '$table' has undeclared column(s): " . implode(', ', $undeclared)
                . ' — every real column must be classified (as a composite_ref identity/ref column, or a columns'
                . ' entry with class authored/runtime/derived/env) before this table can be captured'
            );
        }
        $missing = array_diff($accounted, $live);
        if ($missing) {
            sort($missing);
            throw new \RuntimeException(
                "duo: table '$table' declares column(s) absent from this environment: " . implode(', ', $missing)
                . ' (plugin schema changed? manifest may be pinned to the wrong version range)'
            );
        }
    }

    /** Reconcile an attached-meta declaration with its fixed live EAV shape. */
    public static function assert_meta_schema(string $table, array $decl, callable $assertGrammar): void {
        $assertGrammar($table, $decl);
        $attachCol = (string) ($decl['attached_to']['column'] ?? '');
        $idCol = (string) ($decl['id_column'] ?? 'id');
        $keyCol = (string) ($decl['key_column'] ?? 'meta_key');
        $valCol = (string) ($decl['value_column'] ?? 'meta_value');
        $expected = array_unique(array_filter([
            $idCol, $attachCol, $keyCol, $valCol,
            $decl['legacy_key_column'] ?? null, $decl['legacy_value_column'] ?? null,
        ]));
        sort($expected);

        $live = self::live_columns($table);
        if ($live === null) {
            throw new \RuntimeException("duo: declared attached-meta table '$table' does not exist on this environment");
        }
        sort($live);
        if ($expected !== $live) {
            throw new \RuntimeException(
                "duo: attached-meta table '$table' schema mismatch — expected columns [" . implode(', ', $expected)
                . '], found [' . implode(', ', $live) . '] (plugin schema changed? manifest declaration is stale)'
            );
        }
    }
}
