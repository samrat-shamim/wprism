<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/MetaOwnerRangeLock.php';
require_once __DIR__ . '/CacheInvalidationTransaction.php';
require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';
require_once __DIR__ . '/../Kernel/DatabaseQueryIsolation.php';
require_once __DIR__ . '/../Kernel/DatabaseWorkAuthority.php';
require_once __DIR__ . '/../Kernel/NativeDatabaseProfile.php';
require_once __DIR__ . '/../Kernel/MetaRows.php';
require_once __DIR__ . '/../Policy/Policy.php';

/** Native locks stay with Apply; the composition root supplies the existing canonical reader. */
final class MediaDerivativeObservation {
    private const PAGE = 512;
    private const MAX_ROWS = 1000000;

    public function __construct(
        private readonly Policy $policy,
        private readonly \Closure $snapshot,
        private readonly \Closure $databaseProfile
    ) {}

    public function profile(): NativeDatabaseProfile {
        $profile = ($this->databaseProfile)();
        if (!$profile instanceof NativeDatabaseProfile || !$profile->is_read_only()) {
            throw new \RuntimeException('wprism: media consumer observation requires a read-only database profile');
        }
        return $profile;
    }

    public function read_locked(DatabaseWorkAuthority $authority): array {
        global $wpdb;
        CacheInvalidationTransaction::assert_local_cache('media consumer observation');
        DeleteGuardEvaluator::assert_transaction_isolation('media consumer observation');
        $types = $this->policy->post_types();
        sort($types, SORT_STRING);
        $index = DeleteGuardEvaluator::full_width_lock_index($wpdb->posts, 'post_type', 'media consumer post ranges');
        $meta = MetaOwnerRangeLock::prepare($wpdb->postmeta, 'post_id', 'media consumer metadata ranges');
        $total = 0;
        foreach ($types as $type) {
            $after = 0;
            do {
                $rows = $this->rows($wpdb->prepare(
                    "SELECT ID, post_type FROM {$wpdb->posts} FORCE INDEX (`$index`) WHERE post_type = %s AND ID > %d "
                        . 'ORDER BY ID ASC LIMIT ' . self::PAGE . ' FOR UPDATE', $type, $after
                ));
                foreach ($rows as $row) {
                    $id = MetaRows::positive_id($row['ID'] ?? null);
                    if (array_keys($row) !== ['ID', 'post_type'] || $id === null || $id <= $after
                        || ($row['post_type'] ?? null) !== $type || ++$total > self::MAX_ROWS) {
                        throw new \RuntimeException('wprism: media consumer post range is malformed, aliased or oversized');
                    }
                    $after = $id;
                    DatabaseQueryIsolation::work_unit($authority, static function () use ($meta, $id): void {
                        $meta->read($id);
                    });
                    wp_cache_delete($id, 'posts');
                    wp_cache_delete($id, 'post_meta');
                }
            } while (count($rows) === self::PAGE);
        }

        // The range includes gaps: a concurrent new consumer or changed UUID
        // cannot appear after the canonical reader while this transaction owns
        // publication/cleanup. Runtime metadata is read-locked, never rewritten.
        $map = $wpdb->prefix . 'wprism_map';
        $mapIndex = DeleteGuardEvaluator::full_width_lock_index($map, 'uuid', 'media consumer identity ranges');
        $afterUuid = '';
        $afterKind = '';
        $total = 0;
        do {
            $rows = $this->rows($wpdb->prepare(
                "SELECT uuid, id_kind FROM `$map` FORCE INDEX (`$mapIndex`) WHERE uuid > %s OR (uuid = %s AND id_kind > %s) "
                    . 'ORDER BY uuid ASC, id_kind ASC LIMIT ' . self::PAGE . ' FOR UPDATE', $afterUuid, $afterUuid, $afterKind
            ));
            foreach ($rows as $row) {
                if (array_keys($row) !== ['uuid', 'id_kind'] || !is_string($row['uuid']) || strlen($row['uuid']) > 191
                    || !is_string($row['id_kind']) || $row['id_kind'] === '' || strlen($row['id_kind']) > 191
                    || strcmp($row['uuid'], $afterUuid) < 0
                    || ($row['uuid'] === $afterUuid && strcmp($row['id_kind'], $afterKind) <= 0) || ++$total > self::MAX_ROWS) {
                    throw new \RuntimeException('wprism: media consumer identity range is malformed or oversized');
                }
                $afterUuid = $row['uuid'];
                $afterKind = $row['id_kind'];
            }
        } while (count($rows) === self::PAGE);

        $options = ['sidebars_widgets'];
        foreach ($this->policy->widget_types() as $type => $declaration) {
            foreach ($declaration['settings'] ?? [] as $rule) {
                if (($rule['codec'] ?? null) === 'blocks') $options[] = 'widget_' . $type;
            }
        }
        $options = array_values(array_unique($options));
        sort($options, SORT_STRING);
        $optionIndex = DeleteGuardEvaluator::full_width_lock_index($wpdb->options, 'option_name', 'media consumer widget ranges', true);
        foreach ($options as $name) {
            $rows = $this->rows($wpdb->prepare(
                "SELECT option_id, option_name FROM {$wpdb->options} FORCE INDEX (`$optionIndex`) WHERE option_name = %s "
                    . 'ORDER BY option_id ASC LIMIT 2 FOR UPDATE', $name
            ));
            if (count($rows) > 1 || ($rows !== [] && (array_keys($rows[0]) !== ['option_id', 'option_name']
                || MetaRows::positive_id($rows[0]['option_id']) === null || $rows[0]['option_name'] !== $name))) {
                throw new \RuntimeException('wprism: media consumer widget range is duplicated, malformed or aliased');
            }
            wp_cache_delete($name, 'options');
        }
        wp_cache_delete('alloptions', 'options');
        wp_cache_delete('notoptions', 'options');
        return ($this->snapshot)($authority);
    }

    private function rows(string $sql): array {
        global $wpdb;
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if (!is_array($rows) || !array_is_list($rows) || count($rows) > self::PAGE
            || trim((string) $wpdb->last_error) !== '') {
            throw new \RuntimeException('wprism: media consumer range lock failed or exceeded its bound');
        }
        foreach ($rows as $row) {
            if (!is_array($row)) throw new \RuntimeException('wprism: media consumer range returned a malformed row');
        }
        return $rows;
    }
}
