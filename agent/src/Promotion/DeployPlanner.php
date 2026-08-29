<?php
namespace WPrism;

/**
 * Plans lifecycle order for one already-scoped plugin transition.
 *
 * Deploy owns the WordPress reads (Requires Plugins headers) and the
 * lifecycle side effects. This collaborator owns only the deterministic
 * graph projection: activation is provider-first, retirement is
 * dependent-first, and independent nodes retain the historical stable tie
 * break. Keeping the graph walk here gives Deploy a narrow orchestration
 * facade without changing plugin discovery or hook ordering semantics.
 */
final class DeployPlanner {
    /**
     * Stable topological sort with dependent -> provider edges. When two
     * nodes are independent, use reverse active order as the deterministic
     * lifecycle-compatible tie break.
     *
     * @param list<string> $plugins
     * @param array<string,list<string>> $requirements plugin => providers
     * @return list<string>
     */
    public static function order_deactivations(array $plugins, array $requirements): array {
        $nodes = array_fill_keys($plugins, true);
        $indegree = array_fill_keys($plugins, 0);
        $edges = array_fill_keys($plugins, []);
        foreach ($plugins as $plugin) {
            foreach ($requirements[$plugin] ?? [] as $provider) {
                if (!isset($nodes[$provider]) || isset($edges[$plugin][$provider])) {
                    continue;
                }
                $edges[$plugin][$provider] = true;
                $indegree[$provider]++;
            }
        }

        $priority = array_reverse($plugins);
        $ordered = [];
        while (count($ordered) < count($plugins)) {
            $next = null;
            foreach ($priority as $candidate) {
                if (isset($nodes[$candidate]) && $indegree[$candidate] === 0) {
                    $next = $candidate;
                    break;
                }
            }
            if ($next === null) {
                $cycle = array_keys($nodes);
                sort($cycle, SORT_STRING);
                throw new \RuntimeException(
                    'wprism: plugin dependency cycle prevents safe teardown: ' . implode(', ', $cycle)
                );
            }
            $ordered[] = $next;
            unset($nodes[$next]);
            foreach (array_keys($edges[$next]) as $provider) {
                $indegree[$provider]--;
            }
        }
        return $ordered;
    }

    /**
     * Stable topological sort with provider -> dependent edges. Independent
     * nodes retain their desired-list order; callers may write that desired
     * list back after activation to preserve authored/native state exactly.
     *
     * @param list<string> $plugins
     * @param array<string,list<string>> $requirements plugin => providers
     * @return list<string>
     */
    public static function order_activations(array $plugins, array $requirements): array {
        $nodes = array_fill_keys($plugins, true);
        $indegree = array_fill_keys($plugins, 0);
        $edges = array_fill_keys($plugins, []);
        foreach ($plugins as $plugin) {
            foreach ($requirements[$plugin] ?? [] as $provider) {
                if (!isset($nodes[$provider]) || isset($edges[$provider][$plugin])) {
                    continue;
                }
                $edges[$provider][$plugin] = true;
                $indegree[$plugin]++;
            }
        }

        $ordered = [];
        while (count($ordered) < count($plugins)) {
            $next = null;
            foreach ($plugins as $candidate) {
                if (isset($nodes[$candidate]) && $indegree[$candidate] === 0) {
                    $next = $candidate;
                    break;
                }
            }
            if ($next === null) {
                $cycle = array_keys($nodes);
                sort($cycle, SORT_STRING);
                throw new \RuntimeException(
                    'wprism: plugin dependency cycle prevents safe activation: ' . implode(', ', $cycle)
                );
            }
            $ordered[] = $next;
            unset($nodes[$next]);
            foreach (array_keys($edges[$next]) as $dependent) {
                $indegree[$dependent]--;
            }
        }
        return $ordered;
    }
}
