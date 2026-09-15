<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/StoragePrerequisiteGrammar.php';

/** Derives host settlement authority from existing prerequisite/action declarations. */
final class StoragePrerequisiteSettlement {
    public const LIFECYCLE = 'lifecycle-settle';
    public const MANUAL = 'manual';

    /**
     * The relationship is derived rather than declared twice: a prerequisite
     * is automatically settleable only when the same manifest's lifecycle
     * action discloses exclusively restorable checkpoint effects and one
     * covers that option. A broad options-table effect covers it too because
     * that is the recovery boundary the provider already published in
     * effects_inventory(). Mixed effects remain available to complete code
     * transitions, but storage debt alone cannot select them.
     *
     * @return list<array{equals:string,manifest:string,option:string,settlement:string}>
     */
    public static function inventory(array $manifests): array {
        $actions = self::lifecycle_actions($manifests);

        $rows = [];
        foreach (StoragePrerequisiteGrammar::project($manifests) as $prerequisite) {
            $settlement = self::MANUAL;
            foreach ($actions[$prerequisite['manifest']] ?? [] as $action) {
                if (self::covers_option($action, $prerequisite['option'])) {
                    $settlement = self::LIFECYCLE;
                    break;
                }
            }
            $rows[] = $prerequisite + ['settlement' => $settlement];
        }
        return $rows;
    }

    /**
     * Select only the action declarations that cover currently unmet rows.
     * This is the combination boundary: storage debt in one manifest cannot
     * enlist an unrelated manifest's lifecycle provider or external effects.
     *
     * @param list<array{manifest:string,option:string,ready:bool}> $readiness
     * @return list<array<string,mixed>>
     */
    public static function actions_for_readiness(array $manifests, array $readiness): array {
        $prerequisites = StoragePrerequisiteGrammar::project($manifests);
        if (count($prerequisites) !== count($readiness)) {
            throw new \RuntimeException('wprism: storage prerequisite projections disagree');
        }
        $actions = self::lifecycle_actions($manifests);
        $selected = [];
        foreach ($prerequisites as $index => $prerequisite) {
            $observed = $readiness[$index] ?? null;
            $keys = is_array($observed) ? array_keys($observed) : [];
            sort($keys, SORT_STRING);
            if ($keys !== ['manifest', 'option', 'ready']
                || ($observed['manifest'] ?? null) !== $prerequisite['manifest']
                || ($observed['option'] ?? null) !== $prerequisite['option']
                || !is_bool($observed['ready'] ?? null)) {
                throw new \RuntimeException('wprism: storage prerequisite projections disagree');
            }
            if ($observed['ready']) {
                continue;
            }
            $covered = false;
            foreach ($actions[$prerequisite['manifest']] ?? [] as $action) {
                if (!self::covers_option($action, $prerequisite['option'])) {
                    continue;
                }
                $selected[$prerequisite['manifest'] . "\0" . (string) $action['index']] = true;
                $covered = true;
            }
            if (!$covered) {
                throw new \RuntimeException(
                    "wprism: adapter {$prerequisite['manifest']} option {$prerequisite['option']} "
                    . 'has no effect-covered lifecycle settlement action'
                );
            }
        }

        $result = [];
        foreach ($actions as $manifestActions) {
            foreach ($manifestActions as $action) {
                if (isset($selected[$action['manifest'] . "\0" . (string) $action['index']])) {
                    $result[] = $action;
                }
            }
        }
        return $result;
    }

    /** @return array<string,list<array<string,mixed>>> */
    private static function lifecycle_actions(array $manifests): array {
        $actions = [];
        foreach ($manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            foreach ((array) ($manifest['actions'] ?? []) as $index => $action) {
                if (is_array($action) && ($action['phase'] ?? null) === 'lifecycle_settle') {
                    $actions[$name][] = $action + ['manifest' => $name, 'index' => (int) $index];
                }
            }
        }
        return $actions;
    }

    private static function covers_option(array $action, string $option): bool {
        $effects = $action['effects'] ?? null;
        if (!is_array($effects) || $effects === []) {
            return false;
        }
        $covers = false;
        foreach ($effects as $effect) {
            if (!self::is_restorable_database_effect($effect)) {
                return false;
            }
            $type = $effect['selector']['type'] ?? null;
            $value = $effect['selector']['value'] ?? null;
            if (($type === 'option' && $value === $option)
                || ($type === 'table' && $value === 'options')) {
                $covers = true;
            }
        }
        return $covers;
    }

    private static function is_restorable_database_effect(mixed $effect): bool {
        return is_array($effect)
            && ($effect['kind'] ?? null) === 'database'
            && ($effect['mode'] ?? null) === 'restorable'
            && ($effect['selector']['scope'] ?? null) === 'database_checkpoint';
    }
}
