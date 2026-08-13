<?php
namespace Duo;

/**
 * Lock-boundary proof for manifest-declared deletion guards.
 *
 * A deletion guard's final `SELECT ... FOR UPDATE` is only authoritative
 * when its first equality/range column is covered by an index. This small
 * evaluator owns that schema proof; Apply keeps the transaction lifecycle,
 * query construction, witness capture, and forced-warning orchestration.
 *
 * The class deliberately has no constructor and no dependency on Apply or
 * Policy. It evaluates only the manifest guard plus the target's inspected
 * index metadata, making the race-boundary rule directly characterizable.
 */
final class DeleteGuardEvaluator {
    /**
     * Resolve an index which covers the first equality/range column of a
     * manifest guard. A prefix index is accepted only when the declared
     * metadata key fits entirely inside that prefix; otherwise inserts with
     * the same visible prefix could still evade the gap lock.
     */
    public static function lock_index(array $guard, string $table): ?string {
        global $wpdb;
        $lockColumn = array_key_exists('meta_key', $guard) || array_key_exists('ref', $guard)
            ? 'meta_key'
            : (!empty($guard['option_name_ref']) ? 'option_name' : (string) ($guard['column'] ?? ''));
        $rows = $wpdb->get_results("SHOW INDEX FROM `$table`", ARRAY_A) ?: [];
        $indexes = [];
        foreach ($rows as $row) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($row['Key_name'] ?? ''));
            $seq = (int) ($row['Seq_in_index'] ?? 0);
            if ($name === '' || $seq <= 0) {
                continue;
            }
            $indexes[$name][$seq] = [
                'column' => preg_replace('/[^A-Za-z0-9_]/', '', (string) ($row['Column_name'] ?? '')),
                'prefix' => isset($row['Sub_part']) && $row['Sub_part'] !== null
                    ? (int) $row['Sub_part']
                    : null,
            ];
        }
        foreach ($indexes as $name => $parts) {
            ksort($parts, SORT_NUMERIC);
            $first = reset($parts);
            if (($first['column'] ?? '') !== $lockColumn) {
                continue;
            }
            $prefix = $first['prefix'] ?? null;
            if ($prefix !== null && array_key_exists('meta_key', $guard)
                && strlen((string) $guard['meta_key']) > $prefix) {
                continue;
            }
            return $name;
        }
        return null;
    }
}
