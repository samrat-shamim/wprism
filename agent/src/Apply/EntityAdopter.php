<?php
namespace Duo;

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

/** Claims a plan-approved unmanaged row by installing canonical identity. */
final class EntityAdopter {
    public function __construct(
        private readonly Policy $policy,
        private readonly array $snapshotRowTables
    ) {
    }

    public function adopt(array $row, array $entity, array &$warnings): void {
        global $wpdb;
        $envId = (int) $row['env_id'];
        if (isset($this->snapshotRowTables[$entity['type']])) {
            Snapshot::adopt($this->policy, $row['uuid'], $entity['type'], $envId);
            $warnings[] = "adopted env table row {$entity['type']}:$envId as {$row['uuid']} ({$row['path']})";
            return;
        }
        if ($entity['type'] === 'post') {
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT meta_id FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = '_duo_uuid' LIMIT 1",
                $envId
            ));
            if (!$existing) {
                Db::insert($wpdb->postmeta, [
                    'post_id' => $envId,
                    'meta_key' => '_duo_uuid',
                    'meta_value' => $row['uuid'],
                ], null, 'adopt post identity');
            }
            Ledger::set($row['uuid'], 'post', Ledger::KIND_POST, $envId);
            $warnings[] = "adopted env post $envId as {$row['uuid']} ({$row['path']})";
            return;
        }

        $termTaxonomyId = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt WHERE tt.term_id = %d LIMIT 1",
            $envId
        ));
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT meta_id FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = '_duo_uuid' LIMIT 1",
            $envId
        ));
        if (!$existing) {
            Db::insert($wpdb->termmeta, [
                'term_id' => $envId,
                'meta_key' => '_duo_uuid',
                'meta_value' => $row['uuid'],
            ], null, 'adopt term identity');
        }
        Ledger::set($row['uuid'], $entity['type'], Ledger::KIND_TERM, $envId);
        Ledger::set($row['uuid'], $entity['type'], Ledger::KIND_TT, $termTaxonomyId);
        $warnings[] = "adopted env term $envId as {$row['uuid']} ({$row['path']})";
    }
}
