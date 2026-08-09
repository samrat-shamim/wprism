<?php
namespace Duo;

/**
 * Canonical-surface projection shared by Apply and the read-only scope
 * contract. A surface is a small, exact manifest-matching vocabulary entry;
 * it is evidence about a canonical entity kind, never a target id or write
 * authorization.
 *
 * Keeping this pure and in one place is deliberate. Apply uses the result to
 * find structured action declarations, while ScopeContract publishes the
 * same static vocabulary for a future scoped workflow. Two locally-maintained
 * spellings would eventually let a contract promise a trigger that Apply
 * could not see (or vice versa).
 */
final class CanonicalSurfaces {
    /**
     * Apply-compatible projection over changed work and tombstone plan rows.
     *
     * @param array<int,array<string,mixed>> $work
     * @param array<string,array<string,mixed>> $tree
     * @param array<int,array<string,mixed>> $deleteWork
     * @return list<string>
     */
    public static function for_apply(array $work, array $tree, array $deleteWork = [], ?Policy $policy = null): array {
        $surfaces = [];
        foreach ($work as $entry) {
            $uuid = (string) ($entry['uuid'] ?? '');
            $entity = $uuid !== '' ? ($tree[$uuid] ?? null) : null;
            if (!is_array($entity)) {
                continue;
            }
            foreach (self::for_entity($entity, $entry, $policy) as $surface) {
                $surfaces[$surface] = true;
            }
        }
        foreach ($deleteWork as $row) {
            foreach (self::for_deletion($row) as $surface) {
                $surfaces[$surface] = true;
            }
        }
        $out = array_keys($surfaces);
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * Static projection over a contract's whole resolved closure. Unlike an
     * Apply plan, the contract has no target comparison from which to learn a
     * changed option subset, so every non-absent, non-managed option record
     * it carries is a potential canonical surface. This is deliberately
     * conservative evidence, not a claim that an action will run.
     *
     * @param list<array<string,mixed>> $entities
     * @param list<array<string,mixed>> $tombstones parsed deletion data rows
     * @return list<string>
     */
    public static function for_scope(array $entities, array $tombstones, Policy $policy): array {
        $surfaces = [];
        foreach ($entities as $entity) {
            if (!is_array($entity)) {
                continue;
            }
            $entry = [];
            if (($entity['type'] ?? '') === 'options') {
                $entry['rebuild_option_names'] = array_keys(OptionState::records((array) ($entity['data'] ?? [])));
            }
            foreach (self::for_entity($entity, $entry, $policy) as $surface) {
                $surfaces[$surface] = true;
            }
        }
        foreach ($tombstones as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (self::for_deletion($row) as $surface) {
                $surfaces[$surface] = true;
            }
        }
        $out = array_keys($surfaces);
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * @param array<string,mixed> $entity
     * @param array<string,mixed> $entry
     * @return list<string>
     */
    public static function for_entity(array $entity, array $entry = [], ?Policy $policy = null): array {
        $entityType = (string) ($entity['type'] ?? '');
        $data = (array) ($entity['data'] ?? []);
        if ($entityType === 'post') {
            $postType = (string) ($data['type'] ?? '');
            return [$postType !== '' ? 'post:' . $postType : 'entity:post'];
        }
        if ($entityType === 'term') {
            $taxonomy = (string) ($data['taxonomy'] ?? '');
            return [$taxonomy !== '' ? 'term:' . $taxonomy : 'entity:term'];
        }
        if ($entityType === 'menu') {
            return ['term:nav_menu'];
        }
        if ($entityType === 'options') {
            $records = OptionState::records($data);
            $names = !empty($entry['retry'])
                ? array_keys($records)
                : (array) ($entry['rebuild_option_names'] ?? []);
            $out = [];
            foreach ($names as $option) {
                $option = (string) $option;
                if ($option === '' || !isset($records[$option])) {
                    continue;
                }
                $record = $records[$option];
                if (($record['state'] ?? null) === 'absent') {
                    continue;
                }
                if (($record['state'] ?? null) === 'present'
                    && $policy !== null
                    && (($policy->option_rule($option)['class'] ?? null) === 'managed')) {
                    continue;
                }
                $out[] = 'option:' . $option;
            }
            return $out;
        }
        if ($entityType === 'user-meta' || $entityType === SidebarState::ENTITY_TYPE) {
            return ['entity:' . ($entityType !== '' ? $entityType : 'unknown')];
        }
        if ($entityType !== '') {
            // Snapshot row entities retain their concrete table name. This
            // matches manifest trigger literals without granting a caller
            // authority to query or mutate that table.
            return ['table:' . $entityType];
        }
        return ['entity:unknown'];
    }

    /** @param array<string,mixed> $row @return list<string> */
    public static function for_deletion(array $row): array {
        $kind = (string) ($row['deletion_kind'] ?? $row['kind'] ?? '');
        $type = (string) ($row['deletion_type'] ?? $row['type'] ?? '');
        return match ($kind) {
            'post' => [$type !== '' ? 'post:' . $type : 'entity:post'],
            'term' => [$type !== '' ? 'term:' . $type : 'entity:term'],
            'menu' => ['term:nav_menu'],
            'table' => [$type !== '' ? 'table:' . $type : 'entity:table'],
            'option', 'options' => [$type !== '' ? 'option:' . $type : 'entity:options'],
            default => ['entity:' . ($kind !== '' ? $kind : ($type !== '' ? $type : 'unknown'))],
        };
    }
}
