<?php
declare(strict_types=1);

namespace Duo\Providers;

use Duo\Policy;

/**
 * PMPro 3.8.2/3.8.3 membership-level metadata cache reconciliation.
 *
 * Duo writes pmpro_membership_levelmeta through SQL, bypassing WordPress's
 * metadata API and therefore its `pmpro_membership_level_meta` invalidation.
 * That is observable for up to the external object-cache lifetime: PMPro's
 * get_pmpro_membership_level_meta() delegates to get_metadata(), which reads
 * this exact group. Clear only the current level ids, then compare a fresh
 * native API view with the committed table bytes before issuing a receipt.
 */
final class PaidMembershipsProCache {
    private const CACHE_GROUP = 'pmpro_membership_level_meta';
    private const META_KEYS = [
        'confirmation_in_email',
        'enable_avatars',
        'membership_account_message',
    ];

    public function __construct(private readonly Policy $policy) {
    }

    /** @return array{id:string,plugin:string,version:string} */
    public function identity(): array {
        return [
            'id' => 'paid-memberships-pro-cache',
            'plugin' => 'paid-memberships-pro/paid-memberships-pro.php',
            'version' => '1.0.0',
        ];
    }

    /** @return array<string,array<string,mixed>> */
    public function capabilities(): array {
        return [
            'clear_level_meta_caches' => [
                'args' => [],
                'reads' => ['table:pmpro_membership_levels', 'table:pmpro_membership_levelmeta'],
                'writes' => ['entity:pmpro-membership-level-meta-cache'],
                'scope' => 'site',
                'idempotent' => true,
                'timeout_seconds' => 30,
                'scoped' => [
                    'operation_envelope' => \Duo\Providers::SCOPED_OPERATION_FORMAT,
                    'reconcile' => true,
                ],
            ],
        ];
    }

    /** @param array<string,mixed> $args */
    public function invoke(string $capability, array $args): array {
        if ($capability !== 'clear_level_meta_caches') {
            throw new \RuntimeException(
                "duo: Paid Memberships Pro cache provider does not implement capability '$capability'"
            );
        }
        return $this->repair();
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function invoke_scoped(string $capability, array $args, array $operation): array {
        $receipt = $this->invoke($capability, $args);
        return [
            'operation' => $operation,
            'before' => $receipt['before'],
            'after' => $receipt['after'],
            'verified' => true,
        ];
    }

    /** @param array<string,mixed> $args @param array<string,mixed> $operation */
    public function reconcile_scoped(string $capability, array $args, array $operation): array {
        return $this->invoke_scoped($capability, $args, $operation);
    }

    /** @return array{before:array<string,mixed>,after:array<string,mixed>,verified:true} */
    private function repair(): array {
        $this->assertRuntimeContract();
        $ids = $this->levelIds();
        $before = [
            'level_ids' => $ids,
            'cached_level_ids' => $this->cachedLevelIds($ids),
        ];

        foreach ($ids as $id) {
            wp_cache_delete($id, self::CACHE_GROUP);
        }
        $remaining = $this->cachedLevelIds($ids);
        if ($remaining !== []) {
            throw new \RuntimeException(
                'duo: Paid Memberships Pro cache invalidation left cached membership level id(s): '
                . implode(', ', $remaining)
            );
        }

        [$databaseHash, $apiHash, $rowCount] = $this->verifiedProjection($ids);
        if (!hash_equals($databaseHash, $apiHash)) {
            throw new \RuntimeException(
                'duo: Paid Memberships Pro metadata cache did not converge on the committed levelmeta table'
            );
        }

        return [
            'before' => $before,
            'after' => [
                'level_ids' => $ids,
                'cleared_level_ids' => $ids,
                'refreshed_cached_level_ids' => $this->cachedLevelIds($ids),
                'meta_row_count' => $rowCount,
                'database_hash' => $databaseHash,
                'api_hash' => $apiHash,
            ],
            'verified' => true,
        ];
    }

    private function assertRuntimeContract(): void {
        if (is_multisite()) {
            throw new \RuntimeException(
                'duo: Paid Memberships Pro cache provider is certified for single-site tables only'
            );
        }
        if (!function_exists('get_pmpro_membership_level_meta')) {
            throw new \RuntimeException(
                'duo: Paid Memberships Pro membership-level metadata API is unavailable'
            );
        }
    }

    /** @return list<int> */
    private function levelIds(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pmpro_membership_levels';
        $wpdb->last_error = '';
        $rows = $wpdb->get_col("SELECT id FROM `$table` ORDER BY id");
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException(
                "duo: Paid Memberships Pro level inventory query failed against $table"
            );
        }
        $ids = [];
        foreach ($rows as $row) {
            $raw = (string) $row;
            if (preg_match('/^[1-9][0-9]*$/D', $raw) !== 1) {
                throw new \RuntimeException(
                    "duo: Paid Memberships Pro level inventory returned invalid id '$raw'"
                );
            }
            $id = (int) $raw;
            if ($id <= 0 || (string) $id !== $raw) {
                throw new \RuntimeException(
                    "duo: Paid Memberships Pro level inventory returned unrepresentable id '$raw'"
                );
            }
            $ids[$id] = $id;
        }
        ksort($ids, SORT_NUMERIC);
        return array_values($ids);
    }

    /** @param list<int> $ids @return list<int> */
    private function cachedLevelIds(array $ids): array {
        $cached = [];
        foreach ($ids as $id) {
            $found = false;
            wp_cache_get($id, self::CACHE_GROUP, false, $found);
            if ($found) {
                $cached[] = $id;
            }
        }
        return $cached;
    }

    /** @param list<int> $ids @return array{string,string,int} */
    private function verifiedProjection(array $ids): array {
        global $wpdb;
        $table = $wpdb->prefix . 'pmpro_membership_levelmeta';
        $quotedKeys = implode(',', array_fill(0, count(self::META_KEYS), '%s'));
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT pmpro_membership_level_id, meta_key, meta_value FROM `$table` "
            . "WHERE meta_key IN ($quotedKeys) ORDER BY pmpro_membership_level_id, meta_key, meta_id",
            ...self::META_KEYS
        ), ARRAY_A);
        if (!is_array($rows) || (string) ($wpdb->last_error ?? '') !== '') {
            throw new \RuntimeException(
                "duo: Paid Memberships Pro levelmeta verification query failed against $table"
            );
        }

        $database = [];
        foreach ($rows as $row) {
            $id = (int) ($row['pmpro_membership_level_id'] ?? 0);
            $key = (string) ($row['meta_key'] ?? '');
            if ($id <= 0 || !in_array($key, self::META_KEYS, true)) {
                throw new \RuntimeException(
                    'duo: Paid Memberships Pro levelmeta verification returned an invalid row'
                );
            }
            $slot = $id . ':' . $key;
            if (isset($database[$slot])) {
                throw new \RuntimeException(
                    "duo: Paid Memberships Pro levelmeta key '$key' has multiple rows for level $id"
                );
            }
            $database[$slot] = (string) ($row['meta_value'] ?? '');
        }

        $api = [];
        foreach ($ids as $id) {
            foreach (self::META_KEYS as $key) {
                $slot = $id . ':' . $key;
                if (!array_key_exists($slot, $database)) {
                    continue;
                }
                $api[$slot] = maybe_serialize(get_pmpro_membership_level_meta($id, $key, true));
            }
        }
        ksort($database, SORT_STRING);
        ksort($api, SORT_STRING);
        return [
            hash('sha256', serialize($database)),
            hash('sha256', serialize($api)),
            count($database),
        ];
    }
}
