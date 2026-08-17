<?php
namespace Duo;

/**
 * Pure declaration graph for authored typed tables (DUO-3349).
 *
 * This class owns only facts derivable from the explicit declared-table map:
 * the row and attached-meta rosters, attached-meta ownership, and stable
 * parent-first ordering. It never loads Policy or reads WordPress, the
 * database, the ledger, or canonical state. Snapshot adapts its Policy at
 * thin compatibility facades while capture/materialization and live schema
 * validation remain separate seams.
 */
final class TableGraph {
    public const CLASS_ROW = 'authored_snapshot';
    public const CLASS_META = 'authored_snapshot_meta';

    /** Entity types already owned by Capture/Apply's ordinary dispatch. */
    private const RESERVED_TYPES = ['post', 'term', 'menu', 'options'];

    /**
     * Single declaration-level identity-mode check shared by graph consumers
     * and Snapshot's capture/materialization compatibility facade.
     */
    public static function is_composite_ref(array $decl): bool {
        return ($decl['identity']['mode'] ?? 'mapped') === 'composite_ref';
    }

    /**
     * Declared row tables keyed by table name.
     *
     * A row entity's canonical type is its table name, so ordinary entity
     * types are reserved. Ledger identity is keyed by (id_kind, local_id), so
     * two row tables may not share an id_kind. Both contradictions refuse at
     * this pure graph boundary before any target read.
     *
     * @return array<string,array>
     */
    public static function row_tables(array $declaredTables): array {
        $out = [];
        $seenKind = [];
        foreach ($declaredTables as $name => $decl) {
            if (($decl['class'] ?? '') !== self::CLASS_ROW) {
                continue;
            }
            if (in_array($name, self::RESERVED_TYPES, true)) {
                throw new \RuntimeException(
                    "duo: table '$name' collides with a reserved entity type name (" . implode('/', self::RESERVED_TYPES) . ')'
                );
            }
            $kind = (string) ($decl['id_kind'] ?? '');
            if (isset($seenKind[$kind])) {
                throw new \RuntimeException(
                    "duo: id_kind '$kind' is declared by both '{$seenKind[$kind]}' and '$name' — "
                    . 'each authored_snapshot table needs its own unique id_kind'
                );
            }
            $seenKind[$kind] = $name;
            $out[$name] = $decl;
        }
        return $out;
    }

    /** @return array<string,array> declared attached-meta tables keyed by name */
    public static function meta_tables(array $declaredTables): array {
        $out = [];
        foreach ($declaredTables as $name => $decl) {
            if (($decl['class'] ?? '') === self::CLASS_META) {
                $out[$name] = $decl;
            }
        }
        return $out;
    }

    /**
     * Group attached-meta declarations by their owning row table.
     *
     * Composite-ref rows intentionally have no scalar local identity for an
     * EAV sidecar to reference, so that ownership shape remains an explicit
     * refusal rather than a partially implemented materialization path.
     *
     * @param array<string,array> $rowTables
     * @param array<string,array> $metaTables
     * @return array<string,array<string,array>>
     */
    public static function meta_tables_by_owner(array $rowTables, array $metaTables): array {
        $out = [];
        foreach ($metaTables as $metaName => $metaDecl) {
            $owner = $metaDecl['attached_to']['table'] ?? null;
            if ($owner === null || !isset($rowTables[$owner])) {
                throw new \RuntimeException(
                    "duo: table '$metaName' declares class " . self::CLASS_META . ' with attached_to.table='
                    . var_export($owner, true) . ', which is not itself a declared ' . self::CLASS_ROW . ' table'
                );
            }
            if (self::is_composite_ref($rowTables[$owner])) {
                throw new \RuntimeException(
                    "duo: table '$metaName' declares attached_to.table='$owner', which is identity.mode=composite_ref — "
                    . 'a pure join table has no scalar row identity for an attached-meta sidecar to key on'
                );
            }
            $out[$owner][$metaName] = $metaDecl;
        }
        return $out;
    }

    /**
     * Stable parent-first topological order derived from refs[].kind edges.
     * Unconstrained tables retain declaration order; cycles refuse loudly.
     *
     * @param array<string,array> $rowTables
     * @return list<string>
     */
    public static function topo_order(array $rowTables): array {
        $idKindToTable = [];
        foreach ($rowTables as $name => $decl) {
            $idKindToTable[$decl['id_kind']] = $name;
        }
        $deps = [];
        foreach ($rowTables as $name => $decl) {
            $deps[$name] = [];
            foreach ($decl['refs'] ?? [] as $ref) {
                $target = $idKindToTable[$ref['kind']] ?? null;
                if ($target !== null && $target !== $name) {
                    $deps[$name][$target] = true;
                }
            }
        }
        $order = [];
        $placed = [];
        $remaining = array_keys($rowTables);
        while ($remaining) {
            $progressed = false;
            foreach ($remaining as $i => $name) {
                $ready = true;
                foreach (array_keys($deps[$name]) as $dep) {
                    if (!isset($placed[$dep])) {
                        $ready = false;
                        break;
                    }
                }
                if ($ready) {
                    $order[] = $name;
                    $placed[$name] = true;
                    unset($remaining[$i]);
                    $progressed = true;
                }
            }
            if (!$progressed) {
                throw new \RuntimeException(
                    'duo: cyclic ref dependency among declared tables: ' . implode(', ', $remaining)
                );
            }
        }
        return $order;
    }

    /** Rank in parent-first phase-2 order; unknown tables retain rank zero. */
    public static function phase2_rank(array $rowTables, string $table): int {
        $order = self::topo_order($rowTables);
        $idx = array_search($table, $order, true);
        return $idx === false ? 0 : $idx;
    }
}
