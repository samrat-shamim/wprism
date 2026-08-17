<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/DeleteGuardEvaluator.php';
require_once __DIR__ . '/DeleteGuardValueCodec.php';
require_once __DIR__ . '/../Kernel/OptionState.php';
if (!class_exists(Ledger::class, false)) {
    require_once __DIR__ . '/../Repository/Ledger.php';
}
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}

/**
 * Reads target-owned reference storage for manifest-declared deletion guards.
 *
 * This is the stateful counterpart to DeleteGuardEvaluator: it resolves the
 * target identity, constructs the bounded SQL reads, decodes declared option
 * and metadata reference shapes, and returns stable row labels plus a witness.
 * It does not decide whether a delete is authorized and never mutates target
 * data. Apply retains transaction placement and passes these findings to the
 * evaluator unchanged.
 */
final class DeleteGuardReferenceScanner {
    public function __construct(private readonly Policy $policy) {}

    /** @return array{count:int,error:?string,rows:string[],witness?:string} */
    public function count(
        array $guard,
        string $targetUuid,
        array $deleteUuids,
        array $deletions,
        array $tree = [],
        array $guardRepairUuids = [],
        bool $forUpdate = false
    ): array {
        global $wpdb;
        $table = $wpdb->prefix . preg_replace('/[^A-Za-z0-9_]/', '', $guard['table']);
        $column = preg_replace('/[^A-Za-z0-9_]/', '', $guard['column']);
        if (!$wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table))) {
            return ['count' => 0, 'error' => "required guard table '{$guard['table']}' is absent", 'rows' => []];
        }
        $localId = Ledger::id_for($targetUuid, (string) $guard['id_kind']);
        if ($localId === null) {
            return [
                'count' => 0,
                'error' => "required {$guard['id_kind']} identity mapping is absent for guard {$guard['table']}.{$guard['column']}",
                'rows' => [],
            ];
        }

        // Authored option names may embed the guarded row's local id. The
        // matching canonical option tombstone is the only portable repair.
        if (!empty($guard['option_name_ref'])) {
            return $this->count_option_name_refs(
                $guard,
                $targetUuid,
                $localId,
                $tree,
                $guardRepairUuids,
                $table,
                $forUpdate
            );
        }

        // Typed PHP metadata values are decoded only through their declared
        // reference shape. Unsupported values fail closed.
        if (array_key_exists('meta_key', $guard) || array_key_exists('ref', $guard)) {
            return $this->count_meta_refs(
                $guard,
                $targetUuid,
                $deleteUuids,
                $tree,
                $guardRepairUuids,
                $table,
                $forUpdate
            );
        }

        $where = ["`$column` = %d"];
        $args = [$localId];
        foreach ((array) ($guard['where'] ?? []) as $name => $value) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
            if (is_int($value)) {
                $where[] = "`$name` = %d";
                $args[] = $value;
            } else {
                $where[] = "`$name` = %s";
                $args[] = (string) $value;
            }
        }
        foreach ((array) ($guard['exclude_where'] ?? []) as $name => $value) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
            if (is_int($value)) {
                $where[] = "`$name` <> %d";
                $args[] = $value;
            } else {
                $where[] = "`$name` <> %s";
                $args[] = (string) $value;
            }
        }

        $sourceKind = (string) ($guard['source_id_kind'] ?? '');
        $sourcePk = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($guard['source_pk'] ?? ''));
        if ($sourceKind !== '' && $sourcePk !== '') {
            $allowed = [];
            foreach ($deleteUuids as $uuid => $_) {
                if (!isset($deletions[$uuid])) {
                    continue;
                }
                $id = Ledger::id_for((string) $uuid, $sourceKind);
                if ($id !== null) {
                    $allowed[] = $id;
                }
            }
            if ($allowed) {
                $where[] = "`$sourcePk` NOT IN (" . implode(',', array_fill(0, count($allowed), '%d')) . ')';
                array_push($args, ...$allowed);
            }
        }
        $identityCols = $sourcePk !== '' ? [$sourcePk] : [];
        if (!$identityCols) {
            $primary = $wpdb->get_results("SHOW KEYS FROM `$table` WHERE Key_name = 'PRIMARY'", ARRAY_A) ?: [];
            usort($primary, fn(array $a, array $b): int =>
                ((int) ($a['Seq_in_index'] ?? 0)) <=> ((int) ($b['Seq_in_index'] ?? 0))
            );
            $identityCols = array_values(array_filter(array_map(
                fn(array $r): string => preg_replace('/[^A-Za-z0-9_]/', '', (string) ($r['Column_name'] ?? '')),
                $primary
            )));
        }
        $lockIndex = null;
        if ($forUpdate) {
            $lockIndex = DeleteGuardEvaluator::lock_index($guard, $table);
            if ($lockIndex === null) {
                return [
                    'count' => 0,
                    'error' => "guard table '{$guard['table']}' has no complete indexed lock boundary for {$guard['column']}",
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode([])),
                ];
            }
        }
        $forceIndex = $lockIndex !== null ? " FORCE INDEX (`$lockIndex`)" : '';
        $order = $identityCols
            ? implode(', ', array_map(fn(string $c): string => "`$c` ASC", $identityCols))
            : "`$column` ASC";
        $sql = "SELECT * FROM `$table`$forceIndex WHERE " . implode(' AND ', $where) . " ORDER BY $order";
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
            $found = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
            if ($wpdb->last_error) {
                return ['count' => 0, 'error' => "guard query failed for {$guard['table']}.{$guard['column']}: {$wpdb->last_error}", 'rows' => [], 'witness' => hash('sha256', Canon::encode([]))];
            }
        } else {
            $countSql = "SELECT COUNT(*) FROM `$table` WHERE " . implode(' AND ', $where);
            $count = $wpdb->get_var($wpdb->prepare($countSql, ...$args));
            if ($wpdb->last_error) {
                return ['count' => 0, 'error' => "guard query failed for {$guard['table']}.{$guard['column']}", 'rows' => [], 'witness' => hash('sha256', Canon::encode([]))];
            }
            if ((int) $count === 0) {
                return ['count' => 0, 'error' => null, 'rows' => [], 'witness' => hash('sha256', Canon::encode([]))];
            }
            $found = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
            if ($wpdb->last_error) {
                return ['count' => 0, 'error' => "guard query failed for {$guard['table']}.{$guard['column']}", 'rows' => [], 'witness' => hash('sha256', Canon::encode([]))];
            }
        }
        if (!$found) {
            return ['count' => 0, 'error' => null, 'rows' => [], 'witness' => hash('sha256', Canon::encode([]))];
        }
        if (!$identityCols) {
            return [
                'count' => 0,
                'error' => "guard table '{$guard['table']}' has no stable row identity; refusing an unenumerated force-delete warning",
                'rows' => [],
                'witness' => hash('sha256', Canon::encode($found)),
            ];
        }
        $rows = array_map(function (array $foundRow) use ($guard, $identityCols): string {
            $identity = implode(',', array_map(
                fn(string $c): string => "$c=" . (string) ($foundRow[$c] ?? ''),
                $identityCols
            ));
            return (string) $guard['table'] . '.' . $identity;
        }, $found ?: []);
        return [
            'count' => count($rows),
            'error' => null,
            'rows' => $rows,
            'witness' => hash('sha256', Canon::encode(array_values($found))),
        ];
    }

    /** @return array{count:int,error:?string,rows:string[],witness?:string} */
    private function count_option_name_refs(
        array $guard,
        string $targetUuid,
        int $targetId,
        array $tree,
        array $guardRepairUuids,
        string $table,
        bool $forUpdate = false
    ): array {
        global $wpdb;
        $identityColumn = preg_replace(
            '/[^A-Za-z0-9_]/',
            '',
            (string) ($guard['identity_column'] ?? 'option_id')
        );
        $lockIndex = null;
        if ($forUpdate) {
            $lockIndex = DeleteGuardEvaluator::lock_index($guard, $table);
            if ($lockIndex === null) {
                return [
                    'count' => 0,
                    'error' => "guard table '{$guard['table']}' has no complete indexed lock boundary for option_name",
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode([])),
                ];
            }
        }
        $forceIndex = $lockIndex !== null ? " FORCE INDEX (`$lockIndex`)" : '';
        $sql = "SELECT `$identityColumn` AS guard_id, `option_name`, `option_value`, `autoload` FROM `$table`$forceIndex";
        if ($forUpdate) {
            $sql .= " WHERE `option_name` >= ''";
        }
        $sql .= " ORDER BY `$identityColumn` ASC" . ($forUpdate ? ' FOR UPDATE' : '');
        $rows = $wpdb->get_results($sql, ARRAY_A);
        if ($wpdb->last_error) {
            return [
                'count' => 0,
                'error' => "guard query failed for {$guard['table']} option-name refs: {$wpdb->last_error}",
                'rows' => [],
                'witness' => hash('sha256', Canon::encode([])),
            ];
        }

        $found = [];
        $witnessRows = [];
        $idKind = (string) ($guard['id_kind'] ?? '');
        $token = '{{' . $idKind . ':' . $targetUuid . '}}';
        foreach ($rows ?: [] as $row) {
            $name = (string) ($row['option_name'] ?? '');
            try {
                $details = $this->policy->option_name_ref_match_details($name);
            } catch (\Throwable $e) {
                return [
                    'count' => 0,
                    'error' => $e->getMessage(),
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode([])),
                ];
            }
            if ($details === null || ($details['rule']['class'] ?? '') !== 'authored'
                || (string) ($details['rule']['id_kind'] ?? '') !== $idKind) {
                continue;
            }
            $matches = $details['matches'] ?? [];
            $rawId = $matches['id'][0] ?? null;
            $matchedId = Policy::strict_positive_local_id($rawId);
            if ($matchedId === null) {
                return [
                    'count' => 0,
                    'error' => "guard {$guard['table']} option-name rule captured an unsafe local id",
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode([])),
                ];
            }
            if ($matchedId !== $targetId) {
                continue;
            }

            $offset = (int) ($matches['id'][1] ?? -1);
            if ($offset < 0) {
                return [
                    'count' => 0,
                    'error' => "guard {$guard['table']} option-name rule did not expose its named id capture",
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode([])),
                ];
            }
            $canonicalName = substr($name, 0, $offset)
                . $token
                . substr($name, $offset + strlen((string) ($matches['id'][0] ?? '')));
            $optionsUuid = 'options/core';
            $record = null;
            $witnessRows[] = $row;
            $optionsDocument = $tree[$optionsUuid]['data'] ?? null;
            if (is_array($optionsDocument)
                && array_key_exists('format', $optionsDocument)
                && array_key_exists('records', $optionsDocument)) {
                $record = OptionState::records($optionsDocument)[$canonicalName] ?? null;
            }
            if (isset($guardRepairUuids[$optionsUuid])
                && is_array($record)
                && ($record['state'] ?? '') === 'deleted') {
                continue;
            }

            $found[] = (string) $guard['table'] . '.' . $identityColumn . '='
                . (string) ($row['guard_id'] ?? '') . " (option_name=$name)";
        }
        return [
            'count' => count($found),
            'error' => null,
            'rows' => $found,
            'witness' => hash('sha256', Canon::encode(array_values($witnessRows))),
        ];
    }

    /** @return array{count:int,error:?string,rows:string[],witness?:string} */
    private function count_meta_refs(
        array $guard,
        string $targetUuid,
        array $deleteUuids,
        array $tree,
        array $guardRepairUuids,
        string $table,
        bool $forUpdate = false
    ): array {
        global $wpdb;
        $column = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($guard['column'] ?? ''));
        $metaKey = (string) ($guard['meta_key'] ?? '');
        $sourceKind = (string) ($guard['source_id_kind'] ?? '');
        $sourcePk = preg_replace('/[^A-Za-z0-9_]/', '', (string) ($guard['source_pk'] ?? $column));
        $identityColumn = preg_replace(
            '/[^A-Za-z0-9_]/',
            '',
            (string) ($guard['identity_column'] ?? 'meta_id')
        );
        $where = ["`meta_key` = %s"];
        $args = [$metaKey];
        foreach ((array) ($guard['where'] ?? []) as $name => $value) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
            if (is_int($value)) {
                $where[] = "`$name` = %d";
                $args[] = $value;
            } else {
                $where[] = "`$name` = %s";
                $args[] = (string) $value;
            }
        }
        foreach ((array) ($guard['exclude_where'] ?? []) as $name => $value) {
            $name = preg_replace('/[^A-Za-z0-9_]/', '', (string) $name);
            if (is_int($value)) {
                $where[] = "`$name` <> %d";
                $args[] = $value;
            } else {
                $where[] = "`$name` <> %s";
                $args[] = (string) $value;
            }
        }
        $lockIndex = null;
        if ($forUpdate) {
            $lockIndex = DeleteGuardEvaluator::lock_index($guard, $table);
            if ($lockIndex === null) {
                return [
                    'count' => 0,
                    'error' => "guard table '{$guard['table']}' has no complete indexed lock boundary for metadata key '$metaKey'",
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode([])),
                ];
            }
        }
        $forceIndex = $lockIndex !== null ? " FORCE INDEX (`$lockIndex`)" : '';
        $sql = "SELECT `$identityColumn` AS guard_id, `$sourcePk` AS source_id, `meta_value` "
            . "FROM `$table`$forceIndex WHERE " . implode(' AND ', $where)
            . " ORDER BY `$identityColumn` ASC" . ($forUpdate ? ' FOR UPDATE' : '');
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$args), ARRAY_A);
        if ($wpdb->last_error) {
            return [
                'count' => 0,
                'error' => "guard query failed for {$guard['table']} metadata key '$metaKey': {$wpdb->last_error}",
                'rows' => [],
                'witness' => hash('sha256', Canon::encode([])),
            ];
        }
        $targetId = Ledger::id_for($targetUuid, (string) ($guard['id_kind'] ?? ''));
        if ($targetId === null) {
            return [
                'count' => 0,
                'error' => "required {$guard['id_kind']} identity mapping is absent for guard {$guard['table']} metadata key '$metaKey'",
                'rows' => [],
                'witness' => hash('sha256', Canon::encode($rows ?: [])),
            ];
        }
        $found = [];
        $foundSources = [];
        foreach ($rows ?: [] as $row) {
            $sourceId = (int) ($row['source_id'] ?? 0);
            $sourceUuid = $sourceKind !== '' && $sourceId > 0
                ? Ledger::uuid_for($sourceId, $sourceKind)
                : null;
            if ($sourceUuid !== null && isset($deleteUuids[$sourceUuid])) {
                continue;
            }
            $valueIds = DeleteGuardValueCodec::meta_value_ids($row['meta_value'] ?? null, $guard);
            if ($valueIds === null) {
                return [
                    'count' => 0,
                    'error' => "guard {$guard['table']} metadata key '$metaKey' has an unsupported {$guard['ref']} value shape",
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode($rows ?: [])),
                ];
            }
            if (!in_array($targetId, $valueIds, true)) {
                continue;
            }
            if ($sourceUuid !== null && isset($guardRepairUuids[$sourceUuid])) {
                $desired = (array) (($tree[$sourceUuid]['data']['meta'] ?? null));
                $desiredPresent = array_key_exists($metaKey, $desired);
                $desiredContains = DeleteGuardValueCodec::canonical_meta_ref_contains_uuid(
                    $desiredPresent ? $desired[$metaKey] : null,
                    (string) ($guard['ref'] ?? ''),
                    $targetUuid,
                    $desiredPresent
                );
                if ($desiredContains === null) {
                    return [
                        'count' => 0,
                        'error' => "guard {$guard['table']} metadata key '$metaKey' has an unsupported desired {$guard['ref']} value shape",
                        'rows' => [],
                        'witness' => hash('sha256', Canon::encode($rows ?: [])),
                    ];
                }
                if (!$desiredContains) {
                    continue;
                }
                $foundSources[$sourceUuid] = true;
            }
            $label = (string) ($guard['table'] ?? 'metadata') . '.' . $identityColumn . '='
                . (string) ($row['guard_id'] ?? '');
            if ($sourcePk !== '' && array_key_exists('source_id', $row)) {
                $label .= " ($sourcePk=" . $sourceId . ')';
            }
            $found[] = $label;
        }

        foreach ($guardRepairUuids as $sourceUuid => $_) {
            if (isset($deleteUuids[$sourceUuid]) || isset($foundSources[$sourceUuid])) {
                continue;
            }
            $desired = (array) (($tree[$sourceUuid]['data']['meta'] ?? null));
            $desiredPresent = array_key_exists($metaKey, $desired);
            $desiredContains = DeleteGuardValueCodec::canonical_meta_ref_contains_uuid(
                $desiredPresent ? $desired[$metaKey] : null,
                (string) ($guard['ref'] ?? ''),
                $targetUuid,
                $desiredPresent
            );
            if ($desiredContains === null) {
                return [
                    'count' => 0,
                    'error' => "guard {$guard['table']} metadata key '$metaKey' has an unsupported desired {$guard['ref']} value shape",
                    'rows' => [],
                    'witness' => hash('sha256', Canon::encode($rows ?: [])),
                ];
            }
            if (!$desiredContains) {
                continue;
            }
            $found[] = (string) ($guard['table'] ?? 'metadata') . '.' . $identityColumn
                . "=desired:$sourceUuid";
        }
        return [
            'count' => count($found),
            'error' => null,
            'rows' => $found,
            'witness' => hash('sha256', Canon::encode($rows ?: [])),
        ];
    }
}
