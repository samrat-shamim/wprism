<?php
namespace WPrism;

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
     * Exact primary engine channels present in one apply selection. `retry`
     * supplements, but never substitutes for, the mutation that is being
     * retried; otherwise a deletion retry could select an ordinary writer
     * merely because both capabilities support retry receipts.
     *
     * @param array<int,array<string,mixed>> $work
     * @param array<int,array<string,mixed>> $deleteWork
     * @return list<string>
     */
    public static function mutation_channels_for_apply(array $work, array $deleteWork = []): array {
        $channels = [];
        if ($work !== []) {
            $channels[] = 'always_on_write';
        }
        if ($deleteWork !== []) {
            $channels[] = 'deletions';
        }
        return $channels;
    }

    /**
     * Translate exact, value-free mutation triggers into the surface ids the
     * assess/contract/release layer already uses. `null` means an entity kind
     * has no exact projection yet; callers must retain their conservative
     * kind-level fallback instead of silently narrowing around it.
     *
     * @param list<string> $surfaces from for_apply()
     * @return ?list<string>
     */
    public static function projection_ids(array $surfaces, Policy $policy): ?array {
        $projected = [];
        foreach ($surfaces as $surface) {
            if (!is_string($surface) || !str_contains($surface, ':')) {
                return null;
            }
            [$kind, $name] = explode(':', $surface, 2);
            if ($name === '') {
                return null;
            }
            if ($kind === 'post') {
                $projected['post_type:' . $name] = true;
                if ($name === 'attachment') {
                    $projected['media:attachment'] = true;
                }
                continue;
            }
            if ($kind === 'term') {
                $projected['taxonomy:' . $name] = true;
                continue;
            }
            if ($kind === 'table') {
                $projected['table:' . $name] = true;
                continue;
            }
            if ($kind === 'option') {
                $details = $policy->option_rule_details($name);
                $rule = is_array($details['rule'] ?? null) ? $details['rule'] : null;
                if ($rule === null) {
                    return null;
                }
                $class = (string) ($rule['class'] ?? 'authored');
                if (!in_array($class, Policy::CLASSES, true)) {
                    $class = 'authored';
                }
                $projected['option_group:' . self::declarant(
                    $policy,
                    'options',
                    $name,
                    $details['source'] ?? null
                ) . ':' . $class] = true;
                continue;
            }

            return null;
        }
        $out = array_keys($projected);
        sort($out, SORT_STRING);

        return $out;
    }

    /** The same effective declarant identity AssessInventory groups by. */
    private static function declarant(Policy $policy, string $section, string $name, mixed $source): string {
        if (is_string($source) && $source !== '') {
            foreach ($policy->manifests as $manifest) {
                if ((string) ($manifest['name'] ?? '') === $source) {
                    return $source;
                }
            }
        }

        return $policy->declaring_manifest($section, $name) ?? 'core';
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
     * Static trigger projection for one synthetic option root.  The whole
     * options document is deliberately not passed through for_entity(): that
     * would publish actions for unrelated option names and turn read-only
     * evidence into a broader promise than the root selected.
     *
     * @return list<string>
     */
    public static function for_option_scope(string $name, array $record, Policy $policy): array {
        if ($name === '' || ($record['state'] ?? null) === 'absent') {
            return [];
        }
        if (($record['state'] ?? null) === 'present'
            && (($policy->option_rule($name)['class'] ?? null) === 'managed')) {
            return [];
        }
        return ['option:' . $name];
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
