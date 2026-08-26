<?php
namespace Duo;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/Ledger.php';
require_once __DIR__ . '/SidebarState.php';
require_once __DIR__ . '/Snapshot.php';

/** Read-only proof that one retained ledger tuple still owns its live row. */
final class CanonicalMapWitness {
    /**
     * @param array{uuid:string,entity_type:string,id_kind:string,local_id:int} $row
     * @param array<string,mixed> $evidence
     * @param array<string,array<string,array<string,mixed>>> $mapByIdentityKind
     */
    public static function assert_exact(
        Policy $policy,
        string $uuid,
        string $kind,
        array $row,
        array $evidence,
        array $mapByIdentityKind
    ): void {
        global $wpdb;
        $localId = (int) ($row['local_id'] ?? 0);
        $entityType = (string) ($evidence['entity_type'] ?? '');
        if ($localId <= 0) {
            throw new \RuntimeException('duo: canonical map witness has an invalid local identity');
        }
        if ($entityType === 'post' || $entityType === 'menu_item') {
            if ($entityType === 'menu_item') {
                $owner = (string) ($evidence['owner'] ?? '');
                $ownerTt = (int) ($mapByIdentityKind[$owner][Ledger::KIND_TT]['local_id'] ?? 0);
                if ($ownerTt <= 0) {
                    throw new \RuntimeException('duo: canonical menu-item map witness has no owning menu mapping');
                }
                $query = $wpdb->prepare(
                    "SELECT p.ID, p.post_type, pm.meta_value AS duo_uuid FROM {$wpdb->posts} p"
                    . " LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s"
                    . " JOIN {$wpdb->term_relationships} tr"
                    . ' ON tr.object_id = p.ID AND tr.term_taxonomy_id = %d'
                    . ' WHERE p.ID = %d ORDER BY pm.meta_id ASC LIMIT 1',
                    '_duo_uuid',
                    $ownerTt,
                    $localId
                );
            } else {
                $query = $wpdb->prepare(
                    "SELECT p.ID, p.post_type, pm.meta_value AS duo_uuid FROM {$wpdb->posts} p"
                    . " LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = %s"
                    . ' WHERE p.ID = %d ORDER BY pm.meta_id ASC LIMIT 1',
                    '_duo_uuid',
                    $localId
                );
            }
            $physical = self::checked_target_row($query);
            if ($physical === null
                || (int) ($physical['ID'] ?? 0) !== $localId
                || (string) ($physical['duo_uuid'] ?? '') !== $uuid
                || (string) ($physical['post_type'] ?? '') !== (string) ($evidence['post_type'] ?? '')) {
                throw new \RuntimeException('duo: canonical post map witness does not match its live backing row');
            }
            Ledger::require_read_only_mapping($uuid, $entityType, $kind, $localId, 'canonical post identity');
            return;
        }
        if ($entityType === 'term' || $entityType === 'menu') {
            $termMap = $mapByIdentityKind[$uuid][Ledger::KIND_TERM] ?? null;
            $ttMap = $mapByIdentityKind[$uuid][Ledger::KIND_TT] ?? null;
            $termId = (int) ($termMap['local_id'] ?? 0);
            $ttId = (int) ($ttMap['local_id'] ?? 0);
            if ($termId <= 0 || $ttId <= 0) {
                throw new \RuntimeException('duo: canonical term map witness is incomplete');
            }
            $physical = self::checked_target_row($wpdb->prepare(
                'SELECT t.term_id, tt.term_taxonomy_id, tt.taxonomy, tm.meta_value AS duo_uuid'
                . " FROM {$wpdb->terms} t"
                . " JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id"
                . " LEFT JOIN {$wpdb->termmeta} tm ON tm.term_id = t.term_id AND tm.meta_key = %s"
                . ' WHERE t.term_id = %d AND tt.term_taxonomy_id = %d'
                . ' ORDER BY tm.meta_id ASC LIMIT 1',
                '_duo_uuid',
                $termId,
                $ttId
            ));
            if ($physical === null
                || (int) ($physical['term_id'] ?? 0) !== $termId
                || (int) ($physical['term_taxonomy_id'] ?? 0) !== $ttId
                || (string) ($physical['taxonomy'] ?? '') !== (string) ($evidence['taxonomy'] ?? '')
                || (string) ($physical['duo_uuid'] ?? '') !== $uuid) {
                throw new \RuntimeException('duo: canonical term map witness does not match its live backing row');
            }
            Ledger::require_read_only_mapping($uuid, $entityType, Ledger::KIND_TERM, $termId, 'canonical term identity');
            Ledger::require_read_only_mapping($uuid, $entityType, Ledger::KIND_TT, $ttId, 'canonical taxonomy identity');
            return;
        }
        if ($entityType === 'widget') {
            SidebarState::assert_read_only_selected_mapping(
                $policy,
                $uuid,
                (string) ($evidence['widget_type'] ?? ''),
                $localId,
                (string) ($evidence['owner'] ?? '')
            );
            return;
        }
        if (($evidence['table'] ?? null) === $entityType) {
            Snapshot::assert_read_only_selected_mapping($policy, $entityType, $uuid, $kind, $localId);
            return;
        }
        throw new \RuntimeException('duo: canonical map witness has an unsupported entity type');
    }

    /** @return array<string,mixed>|null */
    private static function checked_target_row(string $sql): ?array {
        global $wpdb;
        $wpdb->last_error = '';
        $row = $wpdb->get_row($sql, ARRAY_A);
        if ($row === false || !empty($wpdb->last_error)) {
            throw new \RuntimeException('duo: canonical map witness target read failed');
        }
        return is_array($row) ? $row : null;
    }
}
