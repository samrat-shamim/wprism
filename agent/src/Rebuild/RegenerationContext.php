<?php
namespace WPrism;

/** Normalization rules for durable/current derived-state context receipts. */
final class RegenerationContext {
    /**
     * Merge a current receipt over durable recovery evidence without dropping
     * roots from an earlier failed reparent chain or known deleted children.
     *
     * @param array<string,mixed> $base
     * @param array<string,mixed> $overlay
     * @return array<string,mixed>
     */
    public static function merge(array $base, array $overlay): array {
        $merged = array_merge($base, $overlay);
        if (isset($base['_marker_key']) && !isset($overlay['_marker_key'])) {
            $merged['_marker_key'] = $base['_marker_key'];
        }
        $roots = self::root_ids($base);
        foreach (self::root_ids($overlay) as $rootId) {
            $roots[$rootId] = $rootId;
        }
        if ($roots) {
            $rootIds = array_values($roots);
            sort($rootIds, SORT_NUMERIC);
            $merged['root_ids'] = $rootIds;
        }
        $children = [];
        foreach ([$base, $overlay] as $context) {
            foreach ((array) ($context['child_ids'] ?? []) as $childId) {
                $childId = (int) $childId;
                if ($childId > 0) {
                    $children[$childId] = $childId;
                }
            }
        }
        if ($children || array_key_exists('child_ids', $base) || array_key_exists('child_ids', $overlay)) {
            $merged['child_ids'] = array_values($children);
            sort($merged['child_ids'], SORT_NUMERIC);
        }
        return $merged;
    }

    /** @param array<string,mixed> $context @return array<int,int> keyed by id */
    public static function root_ids(array $context): array {
        $roots = [];
        foreach ((array) ($context['root_ids'] ?? []) as $rootId) {
            $rootId = (int) $rootId;
            if ($rootId > 0) {
                $roots[$rootId] = $rootId;
            }
        }
        foreach (['old_parent_id', 'new_parent_id', 'parent_id'] as $key) {
            $rootId = (int) ($context[$key] ?? 0);
            if ($rootId > 0) {
                $roots[$rootId] = $rootId;
            }
        }
        return $roots;
    }
}
