<?php
namespace WPrism;

/**
 * Identity boundary for typed-snapshot rows.
 *
 * This class deliberately loads no engine or WordPress dependencies. Snapshot
 * injects the declaration, ledger, UUID, decoding, and live-query capabilities
 * it needs, while direct regressions can execute the complete identity state
 * machine without bootstrapping the rest of the agent.
 */
final class SnapshotIdentity {
    private const COMPOSITE_COMPONENT_BITS = 31;
    private const COMPOSITE_COMPONENT_MAX = (1 << self::COMPOSITE_COMPONENT_BITS) - 1;

    /** One authority for both PHP packing and Ledger's SQL-side tuple probe. */
    public static function compositeComponentBits(): int {
        return self::COMPOSITE_COMPONENT_BITS;
    }

    private \Closure $naturalKeyColumns;
    private \Closure $rowTables;
    private \Closure $ledgerUuidFor;
    private \Closure $ledgerIdFor;
    private \Closure $ledgerSet;
    private \Closure $requireReadOnlyMapping;
    private \Closure $uuidV5;
    private \Closure $uuidV7;
    private \Closure $decodeEntity;
    private \Closure $findNaturalCollision;

    public function __construct(
        \Closure $naturalKeyColumns,
        \Closure $rowTables,
        \Closure $ledgerUuidFor,
        \Closure $ledgerIdFor,
        \Closure $ledgerSet,
        \Closure $requireReadOnlyMapping,
        \Closure $uuidV5,
        \Closure $uuidV7,
        \Closure $decodeEntity,
        \Closure $findNaturalCollision
    ) {
        $this->naturalKeyColumns = $naturalKeyColumns;
        $this->rowTables = $rowTables;
        $this->ledgerUuidFor = $ledgerUuidFor;
        $this->ledgerIdFor = $ledgerIdFor;
        $this->ledgerSet = $ledgerSet;
        $this->requireReadOnlyMapping = $requireReadOnlyMapping;
        $this->uuidV5 = $uuidV5;
        $this->uuidV7 = $uuidV7;
        $this->decodeEntity = $decodeEntity;
        $this->findNaturalCollision = $findNaturalCollision;
    }

    /**
     * Stable UUIDv5 input for a natural-key row. The single-component form is
     * frozen at "<table>:<value>"; tuples preserve declaration order and use
     * "<table>:<column>=<component>:...".
     *
     * @param array<string,string> $components
     */
    public function naturalKeyName(string $table, array $decl, array $components): string {
        $columns = ($this->naturalKeyColumns)($decl);
        if (count($columns) === 1) {
            return $table . ':' . $components[$columns[0]];
        }
        $parts = [$table];
        foreach ($columns as $column) {
            $parts[] = $column . '=' . $components[$column];
        }
        return implode(':', $parts);
    }

    /**
     * Read natural-key components from a captured row. Ref components carry
     * their referenced UUID in a token; malformed or incomplete informational
     * input returns null instead of aborting plan annotation.
     *
     * @param array<string,mixed> $columns
     * @return array<string,string>|null
     */
    public function naturalKeyComponentsFromFront(array $decl, array $columns): ?array {
        $wanted = ($this->naturalKeyColumns)($decl);
        if ($wanted === []) {
            return null;
        }
        $refKinds = [];
        foreach ($decl['refs'] ?? [] as $ref) {
            $refKinds[(string) $ref['column']] = (string) $ref['kind'];
        }
        $out = [];
        foreach ($wanted as $column) {
            $raw = $columns[$column] ?? null;
            if (!is_scalar($raw) || (string) $raw === '') {
                return null;
            }
            if (!isset($refKinds[$column])) {
                $out[$column] = (string) $raw;
                continue;
            }
            $uuid = $this->uuidInToken((string) $raw);
            if ($uuid === null) {
                return null;
            }
            $out[$column] = $uuid;
        }
        return $out;
    }

    /** Resolve or mint identity for one ordinary mapped/natural-key row. */
    public function identifyRow(
        string $table,
        array $decl,
        array $row,
        int $localId,
        object $tokens,
        bool $mint,
        bool $strictReadOnly = false
    ): string {
        $idKind = $decl['id_kind'];
        $uuid = ($this->ledgerUuidFor)($localId, $idKind);
        if ($strictReadOnly) {
            if ($uuid === null) {
                throw new \RuntimeException(
                    "wprism: refresh export refused — mapped identity missing for populated table '$table' row $localId ($idKind); "
                    . 'run the existing capture/identity recovery gate before exporting production'
                );
            }
            ($this->requireReadOnlyMapping)($uuid, $table, $idKind, $localId, "table '$table' row $localId");
            return $uuid;
        }
        if ($uuid === null) {
            $mode = $decl['identity']['mode'] ?? 'mapped';
            if ($mode === 'natural_key') {
                $uuid = ($this->uuidV5)($this->naturalKeyName(
                    $table,
                    $decl,
                    $this->liveNaturalKeyComponents($table, $decl, $row, $localId, $tokens)
                ));
            } else {
                if (!$mint) {
                    throw new \RuntimeException(
                        "wprism: mapped identity missing for populated table '$table' row $localId ($idKind); "
                        . 'refusing to create or rebind it. Restore a verified identity sidecar with '
                        . '`wp wprism identity-import --repo=<repo> --in=<file>` before plan/apply'
                    );
                }
                $uuid = ($this->uuidV7)();
            }
        }
        ($this->ledgerSet)($uuid, $table, $idKind, $localId);
        return $uuid;
    }

    /** @return array<string,string> */
    private function liveNaturalKeyComponents(
        string $table,
        array $decl,
        array $row,
        int $localId,
        object $tokens
    ): array {
        $refKinds = [];
        foreach ($decl['refs'] ?? [] as $ref) {
            $refKinds[(string) $ref['column']] = (string) $ref['kind'];
        }
        $out = [];
        foreach (($this->naturalKeyColumns)($decl) as $column) {
            if (isset($refKinds[$column])) {
                $raw = (int) ($row[$column] ?? 0);
                $token = $raw > 0 ? $tokens->id_to_token($raw, $refKinds[$column]) : null;
                if ($token === null) {
                    throw new \RuntimeException(
                        "wprism: table '$table' row $localId: identity.mode=natural_key column '$column' holds "
                        . ($raw > 0 ? "unmanaged {$refKinds[$column]} ref $raw" : 'no reference')
                        . ' — a parent-scoped natural key derives from the REFERENCED row\'s own uuid, so capture '
                        . 'scope must include that row'
                    );
                }
                $out[$column] = $this->uuidFromToken($token);
                continue;
            }
            $value = (string) ($row[$column] ?? '');
            if ($value === '') {
                throw new \RuntimeException(
                    "wprism: table '$table' row $localId: identity.mode=natural_key column '$column' is empty — "
                    . 'cannot mint a stable identity for this row'
                );
            }
            $out[$column] = $value;
        }
        return $out;
    }

    /**
     * Derive a composite-ref row from the referenced rows' UUIDs, never their
     * environment-local IDs.
     *
     * @return array{0:string,1:array<string,string>,2:array<string,int>}
     */
    public function identifyCompositeRow(string $table, array $decl, array $row, object $tokens): array {
        $cols = $decl['identity']['columns'];
        $kindByCol = [];
        foreach ($decl['refs'] as $ref) {
            $kindByCol[$ref['column']] = $ref['kind'];
        }
        $uuidParts = [$table];
        $tokensByCol = [];
        $localByCol = [];
        foreach ($cols as $col) {
            $raw = (int) ($row[$col] ?? 0);
            if ($raw <= 0) {
                throw new \RuntimeException(
                    "wprism: $table row has empty identity column '$col' — every composite_ref identity "
                    . 'column is structural, never optional (there is no partial version of a join fact)'
                );
            }
            $token = $tokens->id_to_token($raw, $kindByCol[$col]);
            if ($token === null) {
                throw new \RuntimeException(
                    "wprism: $table row has unmanaged {$kindByCol[$col]} ref $raw in identity column '$col' — "
                    . 'capture scope must include the referenced row'
                );
            }
            $uuidParts[] = "$col=" . $this->uuidFromToken($token);
            $tokensByCol[$col] = $token;
            $localByCol[$col] = $raw;
        }
        return [($this->uuidV5)(implode(':', $uuidParts)), $tokensByCol, $localByCol];
    }

    private function uuidInToken(string $token): ?string {
        return preg_match('/^\{\{[a-z][a-z0-9_]*:([0-9a-f-]{36})\}\}$/', $token, $matches)
            ? $matches[1]
            : null;
    }

    public function uuidFromToken(string $token): string {
        $uuid = $this->uuidInToken($token);
        if ($uuid === null) {
            throw new \RuntimeException(
                "wprism: malformed ref token '$token' (composite_ref/natural_key identity derivation)"
            );
        }
        return $uuid;
    }

    /** @param array<string,int> $columnValues */
    public function packCompositeId(string $table, array $columnValues): int {
        $pairs = [];
        $overflow = [];
        foreach ($columnValues as $column => $value) {
            $pairs[] = "$column=$value";
            if ($value < 0 || $value > self::COMPOSITE_COMPONENT_MAX) {
                $overflow[] = "$column=$value";
            }
        }
        if ($overflow) {
            throw new \RuntimeException(
                "wprism: table '$table' composite identity (" . implode(', ', $pairs) . ') has out-of-budget component(s) ('
                . implode(', ', $overflow) . ') — each component must be 0..' . self::COMPOSITE_COMPONENT_MAX
                . " for this engine's packed local_id (see pack_composite_id()'s docblock)"
            );
        }
        $values = array_values($columnValues);
        return ($values[0] << self::COMPOSITE_COMPONENT_BITS) | $values[1];
    }

    /** @return array{0:int,1:int} */
    public function unpackCompositeId(int $packed): array {
        return [$packed >> self::COMPOSITE_COMPONENT_BITS, $packed & self::COMPOSITE_COMPONENT_MAX];
    }

    /**
     * Find an unmanaged natural-key row on this target, recursively resolving
     * an adoptable parent-scoped key when its parent is not ledger-mapped yet.
     *
     * @param array<string,array> $tree
     * @param array<string,?int> $cache
     * @param array<string,bool> $seen
     */
    public function findCollision(
        array $entity,
        array $tree = [],
        array &$cache = [],
        array $seen = [],
        ?\Closure $ledgerIdFor = null
    ): ?int {
        $rowTables = ($this->rowTables)();
        $decl = $rowTables[$entity['type']] ?? null;
        if ($decl === null) {
            return null;
        }
        $columns = ($this->naturalKeyColumns)($decl);
        if ($columns === []) {
            return null;
        }
        $front = $entity['data'] ?? ($this->decodeEntity)($entity['content']);
        $captured = is_array($front['columns'] ?? null) ? $front['columns'] : [];
        $refKinds = [];
        foreach ($decl['refs'] ?? [] as $ref) {
            $refKinds[(string) $ref['column']] = (string) $ref['kind'];
        }
        $predicates = [];
        $args = [];
        foreach ($columns as $column) {
            $value = $captured[$column] ?? null;
            if (!is_string($value) || $value === '') {
                return null;
            }
            if (isset($refKinds[$column])) {
                $parentId = $this->collisionRefId(
                    $this->uuidFromToken($value),
                    $refKinds[$column],
                    $tree,
                    $cache,
                    $seen + [(string) ($front['uuid'] ?? '') => true],
                    $ledgerIdFor,
                    $rowTables
                );
                if ($parentId === null) {
                    return null;
                }
                $predicates[] = "`$column` = %d";
                $args[] = $parentId;
                continue;
            }
            $predicates[] = isset($decl['row_scope'][$column])
                ? "BINARY `$column` = BINARY %s"
                : "`$column` = %s";
            $args[] = $value;
        }
        return ($this->findNaturalCollision)($entity['type'], $decl['pk'], $predicates, $args);
    }

    /** @param array<string,array> $rowTables */
    private function collisionRefId(
        string $uuid,
        string $kind,
        array $tree,
        array &$cache,
        array $seen,
        ?\Closure $ledgerIdFor,
        array $rowTables
    ): ?int {
        $mapped = $ledgerIdFor !== null
            ? $ledgerIdFor($uuid, $kind)
            : ($this->ledgerIdFor)($uuid, $kind);
        if ($mapped !== null) {
            return $mapped;
        }
        if (array_key_exists($uuid, $cache)) {
            return $cache[$uuid];
        }
        $parent = $tree[$uuid] ?? null;
        if ($parent === null || isset($seen[$uuid])) {
            return null;
        }
        $decl = $rowTables[$parent['type'] ?? ''] ?? null;
        if ($decl === null || (string) ($decl['id_kind'] ?? '') !== $kind) {
            return null;
        }
        return $cache[$uuid] = $this->findCollision($parent, $tree, $cache, $seen, $ledgerIdFor);
    }

    /** Claim an unmanaged environment row by writing identity only. */
    public function adopt(string $uuid, string $table, int $environmentId): void {
        $decl = ($this->rowTables)()[$table];
        ($this->ledgerSet)($uuid, $table, $decl['id_kind'], $environmentId);
    }
}
