<?php
namespace WPrism;

// Graph validation is a pure canonical-tree pass. Normal direct loads close
// its collaborators; focused fixtures may preload narrow doubles, so retain
// the established conditional-load boundary used by sibling compiler seams.
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}
if (!class_exists(Snapshot::class, false)) {
    require_once __DIR__ . '/Snapshot.php';
}
if (!class_exists(SidebarState::class, false)) {
    require_once __DIR__ . '/SidebarState.php';
}
if (!class_exists(ReferenceGraph::class, false)) {
    require_once __DIR__ . '/ReferenceGraph.php';
}
if (!class_exists(RepositoryIdentityRegistry::class, false)) {
    require_once __DIR__ . '/RepositoryIdentityRegistry.php';
}

/**
 * Validates the already-enumerated reference graph for one canonical tree.
 *
 * This collaborator deliberately owns only the proof of graph claims: token
 * grammar and registered kinds, raw UUID existence and kinds, explicit
 * deletion witnesses, parent cycles, and menu-local parent membership. It
 * neither discovers files nor invents edges; ReferenceGraph remains the one
 * enumerator shared with scope closure. It also reports through the
 * compiler-owned callback rather than throwing, so RepositoryCompiler keeps
 * the complete cross-surface diagnostic aggregate and final canonical sort.
 */
final class RepositoryReferenceGraphValidator {
    private Policy $policy;
    private RepositoryIdentityRegistry $identities;
    /** @var \Closure(string,string,string,string,?string):void */
    private \Closure $add;

    /** @param \Closure(string,string,string,string,?string):void $add */
    public function __construct(Policy $policy, RepositoryIdentityRegistry $identities, \Closure $add) {
        $this->policy = $policy;
        $this->identities = $identities;
        $this->add = $add;
    }

    /**
     * @param array<string,array<string,mixed>> $tree
     * @param array<string,array<string,mixed>> $deletions
     */
    public function validate(array $tree, array $deletions): void {
        $kindTypes = ReferenceGraph::kind_types($this->policy);
        $parentGraph = [];
        $parentLocations = [];
        foreach (ReferenceGraph::edges($tree, $this->policy) as $edge) {
            if ($edge['check'] === ReferenceGraph::CHECK_TOKEN) {
                $this->validate_token((string) $edge['token'], $edge['path'], $edge['locator'], $kindTypes, $deletions);
            } elseif ($edge['check'] === ReferenceGraph::CHECK_RAW) {
                $this->validate_raw_ref($edge['target'], $edge['expects'], $edge['path'], $edge['locator'], $deletions);
            }
            if ($edge['relation'] === ReferenceGraph::REL_PARENT) {
                $parentGraph[$edge['from']][] = $edge['target'];
                $parentLocations[$edge['from']] = [$edge['path'], $edge['locator']];
            }
        }
        // A menu item's parent must live in the same menu. That is a
        // membership question about one file rather than an edge, so the
        // graph reports only the parents that do resolve and the missing
        // ones are diagnosed here.
        foreach ($tree as $entity) {
            if ($entity['type'] !== 'menu') {
                continue;
            }
            $items = [];
            foreach ((array) ($entity['data']['items'] ?? []) as $item) {
                $items[(string) ($item['uuid'] ?? '')] = true;
            }
            foreach ((array) ($entity['data']['items'] ?? []) as $i => $item) {
                if (!empty($item['parent']) && !isset($items[(string) $item['parent']])) {
                    $this->add('semantic_delete_reference', $entity['path'], "items[$i].parent", "menu-item parent {$item['parent']} is absent from this menu");
                }
            }
        }
        $this->validate_reference_cycles($parentGraph, $parentLocations);
    }

    private function validate_reference_cycles(array $graph, array $locations): void {
        ksort($graph, SORT_STRING);
        $state = [];
        $stack = [];
        $reported = [];
        $visit = function (string $node) use (&$visit, &$state, &$stack, &$reported, $graph, $locations): void {
            $state[$node] = 1;
            $stack[] = $node;
            foreach ($graph[$node] ?? [] as $next) {
                if (($state[$next] ?? 0) === 0) {
                    $visit($next);
                    continue;
                }
                if (($state[$next] ?? 0) !== 1) {
                    continue;
                }
                $start = array_search($next, $stack, true);
                $cycle = array_slice($stack, $start === false ? 0 : $start);
                $cycle[] = $next;
                $keyParts = $cycle;
                sort($keyParts, SORT_STRING);
                $key = implode('|', array_unique($keyParts));
                if (isset($reported[$key])) {
                    continue;
                }
                $reported[$key] = true;
                [$path, $locator] = $locations[$node] ?? ['state', ''];
                $this->add('reference_cycle', $path, $locator, 'parent graph contains cycle ' . implode(' -> ', $cycle));
            }
            array_pop($stack);
            $state[$node] = 2;
        };
        foreach (array_keys($graph) as $node) {
            if (($state[$node] ?? 0) === 0) {
                $visit((string) $node);
            }
        }
    }

    private function validate_token(string $token, string $path, string $locator, array $kindTypes, array $deletions): void {
        if (!preg_match('/^\{\{([a-z][a-z0-9_]*):([0-9a-f-]{36})\}\}$/', $token, $m)) {
            $this->add('malformed_reference', $path, $locator, "reference token '$token' is malformed");
            return;
        }
        $kind = $m[1];
        $uuid = $m[2];
        if (!isset($kindTypes[$kind])) {
            $this->add(
                'invalid_reference_kind',
                $path,
                $locator,
                "reference kind '$kind' is not registered by core, a manifest-bound codec, or a pinned table schema"
            );
            return;
        }
        $this->validate_raw_ref($uuid, $kindTypes[$kind], $path, $locator, $deletions);
    }

    private function validate_raw_ref(string $uuid, array $expectedTypes, string $path, string $locator, array $deletions): void {
        if (!RepositoryIdentityRegistry::is_uuid($uuid)) {
            $this->add('malformed_reference', $path, $locator, "reference '$uuid' is not a valid UUID");
            return;
        }
        $identity = $this->identities->find($uuid);
        if ($identity === null) {
            if (isset($deletions[$uuid])) {
                $this->add(
                    'semantic_delete_reference', $path, $locator,
                    "reference target $uuid is explicitly deleted by {$deletions[$uuid]['path']}",
                    $deletions[$uuid]['path']
                );
            } else {
                $this->add('semantic_delete_reference', $path, $locator, "reference target $uuid is absent from the compiled revision");
            }
            return;
        }
        $actual = $identity['kind'];
        if (!in_array($actual, $expectedTypes, true)) {
            $this->add('reference_kind_mismatch', $path, $locator, "reference target $uuid is $actual; expected " . implode('|', $expectedTypes));
        }
    }

    private function add(string $code, string $path, string $locator, string $message, ?string $relatedPath = null): void {
        ($this->add)($code, $path, $locator, $message, $relatedPath);
    }
}
