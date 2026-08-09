<?php
namespace Duo;

require_once __DIR__ . '/ReferenceGraph.php';

/**
 * Resolve a bounded set of canonical entities from explicit roots, closing
 * over every declared edge that the roots cannot survive without.
 *
 * DUO-3344's premise is that a developer moving one feature should never
 * have to remember which term, attachment, menu, or child row a page
 * silently depends on. So the closure is computed, never asked for: the
 * caller names roots, and everything reachable from them through a DECLARED
 * edge joins the scope with a record of which edge pulled it in.
 *
 * Direction matters, and only one direction is closure. If the scope holds
 * X and X references Y, then Y is a dependency — X is incoherent without it,
 * so Y is included. The reverse is not true and is not treated as true: an
 * option pointing AT a page does not become part of that page's scope just
 * by pointing. Those inbound referrers are reported separately, unincluded,
 * because they are what a later scoped DELETE would strand — the report is
 * the evidence, not the remedy.
 *
 * This is a read-only computation over an already-compiled revision, and
 * that ordering carries a guarantee worth stating plainly: compilation has
 * already refused every dangling reference as a blocking diagnostic, so a
 * closure over a tree that compiled cannot discover a missing dependency.
 * The refusal this class still owes is a root that does not resolve.
 *
 * Offline and target-free, like the compiler it reads from. Scope is a
 * property of a repository revision, not of any environment, which is what
 * lets the same resolved scope be quoted by capture, plan, promote, and
 * rollback later without being recomputed against a moving target.
 *
 * NOT in this slice: scoped capture, promote, delete, refresh, or rollback.
 * Nothing here mutates anything or grants any authority; it resolves and
 * reports. Mutation semantics are deliberately a separate change.
 */
final class ScopeClosure {
    public const FORMAT = 'duo-scope/v1';

    /**
     * The whole-site scope. Named rather than special-cased so that a
     * full-site operation is the same model with a wider root set, instead
     * of a second code path that can drift from the scoped one.
     */
    public const SELECTOR_ALL = 'all';

    /**
     * @param list<string> $selectors typed root selectors
     * @return array<string,mixed> the report (see FORMAT)
     * @throws \RuntimeException when a root does not resolve
     */
    public static function resolve(CompiledRepository $compiled, Policy $policy, array $selectors): array {
        $tree = $compiled->tree();
        $owners = ReferenceGraph::owners($tree);
        $edges = ReferenceGraph::edges($tree, $policy);

        $roots = self::resolve_roots($tree, $owners, $selectors);

        // Outbound adjacency, plus the child index that inverts declared
        // hierarchy edges. Both are built once; closure is a plain
        // breadth-first walk over them.
        $outbound = [];
        $childrenOf = [];
        foreach ($edges as $edge) {
            if (!isset($tree[$edge['from']])) {
                // Intra-entry edges (a menu item's parent) are addressed by
                // an id that owns no file of its own; they move no scope.
                continue;
            }
            $outbound[$edge['from']][] = $edge;
            if ($edge['relation'] === ReferenceGraph::REL_PARENT
                && ($tree[$edge['from']]['type'] ?? '') === 'post') {
                $childrenOf[$edge['target']][] = $edge['from'];
            }
        }

        $included = [];
        $queue = [];
        foreach ($roots as $root) {
            if (isset($included[$root['entity']])) {
                continue;
            }
            $included[$root['entity']] = [
                'entity' => $root['entity'],
                'path' => (string) $tree[$root['entity']]['path'],
                'type' => (string) $tree[$root['entity']]['type'],
                'reason' => 'root',
                'selector' => $root['selector'],
                'from' => null, 'from_path' => null, 'locator' => null,
            ];
            $queue[] = $root['entity'];
        }

        while ($queue) {
            $key = array_shift($queue);
            $entity = $tree[$key];

            foreach ($outbound[$key] ?? [] as $edge) {
                $owner = $owners[$edge['target']] ?? null;
                if ($owner === null) {
                    // An unresolvable target cannot happen in a tree that
                    // compiled; skipping rather than throwing keeps this
                    // read-only path from inventing a second, weaker copy of
                    // the compiler's own dangling-reference refusal.
                    continue;
                }
                $target = $owner['entity'];
                // A reference into the entity's own file (a menu item citing
                // its own menu) is already in scope by definition.
                if ($target === $key || isset($included[$target])) {
                    continue;
                }
                $included[$target] = [
                    'entity' => $target,
                    'path' => (string) $tree[$target]['path'],
                    'type' => (string) $tree[$target]['type'],
                    'reason' => $edge['relation'],
                    'selector' => null,
                    'from' => $key,
                    'from_path' => $edge['path'],
                    'locator' => $edge['locator'],
                ];
                $queue[] = $target;
            }

            // Declared parent -> child descent (DUO-3315). A parent row is
            // incomplete without the child types its adapter declares — a
            // product without its variations is not a smaller product. The
            // engine learns which types those are from the manifest, never
            // from a branch naming a plugin.
            if (($entity['type'] ?? '') !== 'post') {
                continue;
            }
            $childTypes = $policy->child_post_types((string) ($entity['data']['type'] ?? ''));
            if (!$childTypes) {
                continue;
            }
            foreach ($childrenOf[$key] ?? [] as $childKey) {
                if (isset($included[$childKey])
                    || !in_array((string) ($tree[$childKey]['data']['type'] ?? ''), $childTypes, true)) {
                    continue;
                }
                $included[$childKey] = [
                    'entity' => $childKey,
                    'path' => (string) $tree[$childKey]['path'],
                    'type' => (string) $tree[$childKey]['type'],
                    'reason' => 'declared_child',
                    'selector' => null,
                    'from' => $key,
                    'from_path' => (string) $entity['path'],
                    'locator' => 'post_types.' . (string) ($entity['data']['type'] ?? '') . '.children',
                ];
                $queue[] = $childKey;
            }
        }

        return self::report($tree, $owners, $edges, $roots, $included);
    }

    /**
     * @param array<string,array<string,mixed>> $tree
     * @param array<string,array{entity:string,kind:string}> $owners
     * @param list<string> $selectors
     * @return list<array{selector:string,entity:string}>
     */
    private static function resolve_roots(array $tree, array $owners, array $selectors): array {
        if (!$selectors) {
            throw new \RuntimeException('duo: a scope needs at least one root selector (or "all")');
        }
        $roots = [];
        foreach ($selectors as $selector) {
            $selector = trim((string) $selector);
            if ($selector === '') {
                continue;
            }
            if ($selector === self::SELECTOR_ALL) {
                foreach (array_keys($tree) as $key) {
                    $roots[] = ['selector' => $selector, 'entity' => (string) $key];
                }
                continue;
            }
            $roots[] = ['selector' => $selector, 'entity' => self::resolve_one($tree, $owners, $selector)];
        }
        if (!$roots) {
            throw new \RuntimeException('duo: a scope needs at least one root selector (or "all")');
        }
        return $roots;
    }

    /** @return string the resolved tree key */
    private static function resolve_one(array $tree, array $owners, string $selector): string {
        if ($selector === 'options') {
            return self::require_key($tree, 'options/core', $selector);
        }
        [$prefix, $rest] = array_pad(explode(':', $selector, 2), 2, null);
        if ($rest === null || $rest === '') {
            throw new \RuntimeException(
                "duo: root selector '$selector' is not understood — " . self::GRAMMAR
            );
        }
        switch ($prefix) {
            case 'path':
                foreach ($tree as $key => $entity) {
                    if ((string) $entity['path'] === $rest) {
                        return (string) $key;
                    }
                }
                throw new \RuntimeException("duo: root selector '$selector' names no entity in this revision");
            case 'menu':
                return self::require_typed_slug($tree, 'menu', 'slug', $rest, $selector);
            case 'sidebar':
                return self::require_key($tree, SidebarState::key($rest), $selector);
            case 'user-meta':
                return self::require_key($tree, UserMetaState::key($rest), $selector);
            case 'table':
                [$table, $uuid] = array_pad(explode(':', $rest, 2), 2, null);
                if ($uuid === null || $uuid === '') {
                    throw new \RuntimeException("duo: root selector '$selector' needs the form table:<table>:<uuid>");
                }
                return self::require_uuid($tree, $owners, $uuid, [(string) $table], $selector);
            case 'post':
            case 'term':
                return self::require_uuid($tree, $owners, $rest, [$prefix], $selector);
        }
        throw new \RuntimeException("duo: root selector '$selector' is not understood — " . self::GRAMMAR);
    }

    private const GRAMMAR = 'roots are post:<uuid>, term:<uuid>, table:<table>:<uuid>, menu:<slug>, sidebar:<id>, user-meta:<login>, options, path:<state-relative-path>, or all';

    private static function require_key(array $tree, string $key, string $selector): string {
        if (!isset($tree[$key])) {
            throw new \RuntimeException("duo: root selector '$selector' names no entity in this revision");
        }
        return $key;
    }

    private static function require_typed_slug(array $tree, string $type, string $field, string $value, string $selector): string {
        foreach ($tree as $key => $entity) {
            if ((string) $entity['type'] === $type && (string) ($entity['data'][$field] ?? '') === $value) {
                return (string) $key;
            }
        }
        throw new \RuntimeException("duo: root selector '$selector' names no $type in this revision");
    }

    /** @param list<string> $expectTypes */
    private static function require_uuid(array $tree, array $owners, string $uuid, array $expectTypes, string $selector): string {
        if (isset($tree[$uuid])) {
            $actual = (string) $tree[$uuid]['type'];
            if (!in_array($actual, $expectTypes, true)) {
                throw new \RuntimeException(
                    "duo: root selector '$selector' resolves to a '$actual' entity ({$tree[$uuid]['path']}), not "
                    . implode('/', $expectTypes) . " — a scope root is refused rather than silently retyped"
                );
            }
            return $uuid;
        }
        // Menu items and widgets are addressable but are not files. Naming
        // the owner is more useful than "not found", because the owner is
        // the unit a scope can actually carry.
        $owner = $owners[$uuid] ?? null;
        if ($owner !== null) {
            throw new \RuntimeException(
                "duo: root selector '$selector' names a {$owner['kind']} carried by {$tree[$owner['entity']]['path']} — "
                . 'select that entity instead; a menu item or widget cannot be scoped away from its owner'
            );
        }
        throw new \RuntimeException("duo: root selector '$selector' names no entity in this revision");
    }

    /**
     * @param array<string,array<string,mixed>> $tree
     * @param array<string,array{entity:string,kind:string}> $owners
     * @param list<array<string,mixed>> $edges
     * @param list<array{selector:string,entity:string}> $roots
     * @param array<string,array<string,mixed>> $included
     * @return array<string,mixed>
     */
    private static function report(array $tree, array $owners, array $edges, array $roots, array $included): array {
        ksort($included, SORT_STRING);
        $rows = array_values($included);
        usort($rows, static fn(array $a, array $b): int => strcmp($a['path'], $b['path']));

        $excludedByType = [];
        $excludedTotal = 0;
        foreach ($tree as $key => $entity) {
            if (isset($included[(string) $key])) {
                continue;
            }
            $excludedTotal++;
            $type = (string) $entity['type'];
            $excludedByType[$type] = ($excludedByType[$type] ?? 0) + 1;
        }
        ksort($excludedByType, SORT_STRING);

        // Media blobs an included attachment owns. They are artifacts rather
        // than tree entities, so they are named separately instead of being
        // counted as entities that were "included".
        $media = [];
        foreach ($rows as $row) {
            $entity = $tree[$row['entity']];
            if ((string) $entity['type'] === 'post' && (string) ($entity['data']['type'] ?? '') === 'attachment') {
                $blob = (string) ($entity['data']['media'] ?? '');
                if ($blob !== '') {
                    $media[$blob] = true;
                }
            }
        }
        $media = array_keys($media);
        sort($media, SORT_STRING);

        // Inbound referrers: outside the scope, pointing into it. Not
        // dependencies of the scope and deliberately not included — recorded
        // because they are exactly what a later scoped delete would strand.
        $inbound = [];
        foreach ($edges as $edge) {
            if (!isset($tree[$edge['from']]) || isset($included[$edge['from']])) {
                continue;
            }
            $owner = $owners[$edge['target']] ?? null;
            if ($owner === null || !isset($included[$owner['entity']])) {
                continue;
            }
            $inbound[] = [
                'entity' => $edge['from'],
                'path' => $edge['path'],
                'locator' => $edge['locator'],
                'relation' => $edge['relation'],
                'target' => $owner['entity'],
                'target_path' => (string) $tree[$owner['entity']]['path'],
            ];
        }
        usort($inbound, static fn(array $a, array $b): int => [$a['path'], $a['locator']] <=> [$b['path'], $b['locator']]);

        $rootRows = [];
        foreach ($roots as $root) {
            $rootRows[] = [
                'selector' => $root['selector'],
                'entity' => $root['entity'],
                'path' => (string) $tree[$root['entity']]['path'],
                'type' => (string) $tree[$root['entity']]['type'],
            ];
        }

        return [
            'format' => self::FORMAT,
            'roots' => $rootRows,
            'included' => $rows,
            'media' => $media,
            'inbound' => $inbound,
            'excluded' => ['total' => $excludedTotal, 'by_type' => $excludedByType],
            'totals' => [
                'roots' => count($rootRows),
                'included' => count($rows),
                'closure' => count($rows) - count(array_unique(array_column($rootRows, 'entity'))),
                'excluded' => $excludedTotal,
                'media' => count($media),
                'inbound' => count($inbound),
            ],
        ];
    }
}
