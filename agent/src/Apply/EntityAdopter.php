<?php
namespace WPrism;

if (!class_exists(Db::class, false)) {
    require_once __DIR__ . '/../Kernel/Db.php';
}
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Snapshot::class, false)) {
    require_once __DIR__ . '/../Repository/Snapshot.php';
}
require_once __DIR__ . '/CacheInvalidationTransaction.php';
require_once __DIR__ . '/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../Kernel/MetaRows.php';
require_once __DIR__ . '/../Kernel/Uuid.php';
require_once __DIR__ . '/../Kernel/TableGraph.php';
require_once __DIR__ . '/../Kernel/TableRowScope.php';
require_once __DIR__ . '/../Grammar/Tokens.php';

/** Claims a plan-approved unmanaged row by installing canonical identity. */
final class EntityAdopter {
    public function __construct(
        private readonly Policy $policy,
        private readonly array $snapshotRowTables,
        private readonly ApplyFieldMaterializer $fieldMaterializer
    ) {
    }

    /** A plan's natural-key match cannot authorize a different row at mutation time. */
    private function lock_table_identity(array $entity, int $environmentId): void {
        global $wpdb;
        $table = $entity['type'];
        $decl = $this->snapshotRowTables[$table];
        $front = $entity['data'];
        $columns = (array) ($front['columns'] ?? []);
        TableRowScope::assert_matches($table, $decl, $columns);
        $composite = TableGraph::is_composite_ref($decl);
        $keys = $composite ? $decl['identity']['columns'] : Policy::natural_key_columns($decl);
        if ($keys === []) {
            throw new \RuntimeException('wprism: table adoption requires a declared natural or composite identity');
        }
        $references = array_column($decl['refs'] ?? [], 'kind', 'column');
        $predicates = [];
        $values = [];
        $localComponents = [];
        foreach (array_unique(array_merge($keys, array_keys($decl['row_scope'] ?? []))) as $column) {
            $value = $columns[$column] ?? null;
            if (!is_string($value) || $value === '') {
                throw new \RuntimeException('wprism: table adoption has incomplete canonical identity components');
            }
            if (isset($references[$column])) {
                $kind = $references[$column];
                if (!preg_match('/^\{\{' . preg_quote($kind, '/') . ':([0-9a-f-]{36})\}\}$/D', $value, $match)
                    || !Uuid::is($match[1])) {
                    throw new \RuntimeException('wprism: table adoption has an invalid identity reference');
                }
                $value = Ledger::id_for($match[1], Tokens::ledger_kind($kind));
                if ($value === null) {
                    throw new \RuntimeException('wprism: table adoption requires its referenced identity to be bound first');
                }
                $localComponents[$column] = $value;
                $predicates[] = "`$column` = %d";
            } else {
                $predicates[] = "BINARY `$column` = BINARY %s";
            }
            $values[] = $value;
        }
        $physical = $wpdb->prefix . $table;
        $this->fieldMaterializer->prove_lock_tables([$physical], 'adopt table identity locking');
        $authority = Db::transaction_authority('adopt table identity locking');
        $rows = Db::transactional_rows($wpdb->prepare(
            "SELECT * FROM `$physical` WHERE " . implode(' AND ', $predicates) . ' LIMIT 2 FOR UPDATE',
            ...$values
        ), $authority, 'adopt table identity locking');
        if (count($rows) !== 1) {
            throw new \RuntimeException('wprism: table adoption requires exactly one current native identity match');
        }
        $actualId = $composite ? Snapshot::pack_composite_id($table, $localComponents)
            : MetaRows::positive_id($rows[0][$decl['pk']] ?? null);
        if ($actualId !== $environmentId) {
            throw new \RuntimeException('wprism: table adoption native identity changed after planning');
        }
    }

    public function adopt(array $row, array $entity, array &$warnings): void {
        global $wpdb;
        $envId = (int) $row['env_id'];
        $uuid = (string) ($row['uuid'] ?? '');
        if ($envId <= 0 || !Uuid::is($uuid)) {
            throw new \RuntimeException('wprism: adoption request has a malformed physical/canonical identity');
        }
        if (isset($this->snapshotRowTables[$entity['type']])) {
            $this->lock_table_identity($entity, $envId);
            Snapshot::adopt($this->policy, $row['uuid'], $entity['type'], $envId);
            $warnings[] = "adopted env table row {$entity['type']}:$envId as {$row['uuid']} ({$row['path']})";
            return;
        }
        if ($entity['type'] === 'post') {
            $exact = $this->fieldMaterializer->meta_owner_range_lock(
                $wpdb->postmeta,
                'post_id',
                'adopt post identity owner-range locking'
            )->exact_key_rows($envId, '_wprism_uuid');
            if (count($exact) > 1) {
                throw new \RuntimeException('wprism: adopt post identity found duplicate exact identity rows');
            }
            if ($exact !== []
                && (!is_string($exact[0]['meta_value']) || !hash_equals($uuid, $exact[0]['meta_value']))) {
                throw new \RuntimeException('wprism: adopt post identity contradicts the exact physical identity row');
            }
            if ($exact === []) {
                Db::insert($wpdb->postmeta, [
                    'post_id' => $envId,
                    'meta_key' => '_wprism_uuid',
                    'meta_value' => $uuid,
                ], null, 'adopt post identity');
                CacheInvalidationTransaction::queue($envId, 'post_meta', 'adopt post identity');
                $this->assert_identity_readback(
                    $wpdb->postmeta,
                    'post_id',
                    $envId,
                    $uuid,
                    'adopt post identity'
                );
            }
            Ledger::set($uuid, 'post', Ledger::KIND_POST, $envId);
            $warnings[] = "adopted env post $envId as $uuid ({$row['path']})";
            return;
        }

        $taxonomy = $entity['type'] === 'menu'
            ? 'nav_menu'
            : (string) ($entity['data']['taxonomy'] ?? '');
        if (preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $taxonomy) !== 1) {
            throw new \RuntimeException('wprism: adopt term identity has a malformed taxonomy');
        }
        $index = $this->fieldMaterializer->proven_lock_index(
            $wpdb->term_taxonomy,
            'term_id',
            'adopt term taxonomy owner-range locking'
        );
        $wpdb->last_error = '';
        $taxonomyRows = $wpdb->get_results($wpdb->prepare(
            "SELECT term_taxonomy_id, taxonomy FROM {$wpdb->term_taxonomy} FORCE INDEX (`$index`) "
            . 'WHERE term_id = %d ORDER BY term_taxonomy_id ASC LIMIT 1025 FOR UPDATE',
            $envId
        ), ARRAY_A);
        if (!is_array($taxonomyRows)
            || !array_is_list($taxonomyRows)
            || trim((string) ($wpdb->last_error ?? '')) !== ''
            || count($taxonomyRows) > 1024) {
            throw new \RuntimeException('wprism: adopt term taxonomy owner-range read failed or exceeded its bound');
        }
        $termTaxonomyIds = [];
        foreach ($taxonomyRows as $position => $taxonomyRow) {
            $id = is_array($taxonomyRow)
                ? MetaRows::positive_id($taxonomyRow['term_taxonomy_id'] ?? null)
                : null;
            $rowTaxonomy = is_array($taxonomyRow) ? ($taxonomyRow['taxonomy'] ?? null) : null;
            if (!is_array($taxonomyRow)
                || array_keys($taxonomyRow) !== ['term_taxonomy_id', 'taxonomy']
                || $id === null
                || !is_string($rowTaxonomy)
                || preg_match('/^[A-Za-z0-9_-]{1,32}$/D', $rowTaxonomy) !== 1) {
                throw new \RuntimeException("wprism: adopt term taxonomy owner-range returned a malformed row at position $position");
            }
            if (hash_equals($taxonomy, $rowTaxonomy)) {
                $termTaxonomyIds[] = $id;
            }
        }
        if (count($termTaxonomyIds) !== 1) {
            throw new \RuntimeException('wprism: adopt term identity requires exactly one byte-exact taxonomy row');
        }
        $termTaxonomyId = $termTaxonomyIds[0];
        $exact = $this->fieldMaterializer->meta_owner_range_lock(
            $wpdb->termmeta,
            'term_id',
            'adopt term identity owner-range locking'
        )->exact_key_rows($envId, '_wprism_uuid');
        if (count($exact) > 1) {
            throw new \RuntimeException('wprism: adopt term identity found duplicate exact identity rows');
        }
        if ($exact !== []
            && (!is_string($exact[0]['meta_value']) || !hash_equals($uuid, $exact[0]['meta_value']))) {
            throw new \RuntimeException('wprism: adopt term identity contradicts the exact physical identity row');
        }
        if ($exact === []) {
            Db::insert($wpdb->termmeta, [
                'term_id' => $envId,
                'meta_key' => '_wprism_uuid',
                'meta_value' => $uuid,
            ], null, 'adopt term identity');
            CacheInvalidationTransaction::queue($envId, 'term_meta', 'adopt term identity');
            $this->assert_identity_readback(
                $wpdb->termmeta,
                'term_id',
                $envId,
                $uuid,
                'adopt term identity'
            );
        }
        Ledger::set($uuid, $entity['type'], Ledger::KIND_TERM, $envId);
        Ledger::set($uuid, $entity['type'], Ledger::KIND_TT, $termTaxonomyId);
        $warnings[] = "adopted env term $envId as $uuid ({$row['path']})";
    }

    private function assert_identity_readback(
        string $table,
        string $ownerColumn,
        int $ownerId,
        string $uuid,
        string $purpose
    ): void {
        $exact = $this->fieldMaterializer->meta_owner_range_lock(
            $table,
            $ownerColumn,
            "$purpose readback locking"
        )->exact_key_rows($ownerId, '_wprism_uuid');
        if (count($exact) !== 1
            || !is_string($exact[0]['meta_value'] ?? null)
            || !hash_equals($uuid, $exact[0]['meta_value'])) {
            throw new \RuntimeException("wprism: $purpose exact locked readback disagrees with the requested identity");
        }
    }
}
