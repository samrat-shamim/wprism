<?php
namespace WPrism;

require_once __DIR__ . '/../Repository/ReferenceGraph.php';
require_once __DIR__ . '/../Kernel/OptionState.php';

/**
 * Resolve a bounded set of canonical entities from explicit roots, closing
 * over every declared edge that the roots cannot survive without.
 *
 * issue #3344's premise is that a developer moving one feature should never
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
 * This class still mutates nothing and grants no authority. Scoped capture
 * and refresh use its result only after an immutable ScopeContract is
 * associated with the exact source revision; their separate overlay layer
 * owns mutation bounds, deletion checks, and durable receipts. Scoped apply,
 * promote, verification, and rollback remain outside v1.
 *
 * issue #3344: an `option:<name>` root names one authored option instead of
 * the whole `options/core` surface (`options` remains the coarse selector).
 * Every other root is a real compiled tree key; an option is not -- it is
 * one record inside one file -- so it resolves to a synthetic key
 * (option_key()) carrying its own report row and its own attributed
 * outbound edges (attribute_option_edges(), matched against the option's
 * OWN declared references only, never another option's). This is
 * contractable as immutable evidence, but it is not yet mutation-capable:
 * each mutation consumer rejects the contract explicitly before target work.
 * Keeping the proof format available lets a later option-aware consumer use
 * the same exact root, record hash, and closure facts without smuggling an
 * authority grant through this read-only resolver.
 */
final class ScopeClosure {
    public const FORMAT = 'wprism-scope/v1';

    /**
     * The whole-site scope. Named rather than special-cased so that a
     * full-site operation is the same model with a wider root set, instead
     * of a second code path that can drift from the scoped one.
     */
    public const SELECTOR_ALL = 'all';

    /**
     * Prefix for the synthetic per-option identity option_key() mints.
     * Provably disjoint from every real tree key: a post/term uuid is
     * 36 hex-and-dash characters, `options/core` is this exact literal with
     * nothing appended, SidebarState::key() starts with 'sidebar/', and
     * UserMetaState::key() is a bare 64-char sha256 hex digest -- none can
     * ever equal 'options/core#' followed by anything, for any option name.
     */
    private const OPTION_KEY_PREFIX = 'options/core#';

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

        // A requested option root has no tree entry of its own to carry
        // $outbound[...] -- attribute options/core's edges to their owning
        // option name (computed once, only for options actually requested)
        // and wire that in as if it were the option's own adjacency list.
        $optionNames = [];
        foreach ($roots as $root) {
            if (self::is_option_key($root['entity'])) {
                $optionNames[] = self::option_name_from_key($root['entity']);
            }
        }
        if ($optionNames) {
            $optionEdges = self::attribute_option_edges($tree, $edges, $optionNames);
            foreach ($optionNames as $name) {
                $outbound[self::option_key($name)] = $optionEdges[$name] ?? [];
            }
        }

        $included = [];
        $queue = [];
        foreach ($roots as $root) {
            if (isset($included[$root['entity']])) {
                continue;
            }
            if (self::is_option_key($root['entity'])) {
                $included[$root['entity']] = [
                    'entity' => $root['entity'],
                    'path' => (string) $tree['options/core']['path'],
                    'type' => 'option',
                    'option' => self::option_name_from_key($root['entity']),
                    'reason' => 'root',
                    'selector' => $root['selector'],
                    'from' => null, 'from_path' => null, 'locator' => null,
                ];
                $queue[] = $root['entity'];
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

            // Declared parent -> child descent (issue #3315). A parent row is
            // incomplete without the child types its adapter declares — a
            // product without its variations is not a smaller product. The
            // engine learns which types those are from the manifest, never
            // from a branch naming a plugin. An option root has no post
            // children to descend into and no tree entry to read a type
            // from -- is_option_key() short-circuits before $tree[$key] is
            // ever touched for one.
            if (self::is_option_key($key) || ($tree[$key]['type'] ?? '') !== 'post') {
                continue;
            }
            $entity = $tree[$key];
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
            throw new \RuntimeException('wprism: a scope needs at least one root selector (or "all")');
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
            throw new \RuntimeException('wprism: a scope needs at least one root selector (or "all")');
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
                "wprism: root selector '$selector' is not understood — " . self::GRAMMAR
            );
        }
        switch ($prefix) {
            case 'path':
                foreach ($tree as $key => $entity) {
                    if ((string) $entity['path'] === $rest) {
                        return (string) $key;
                    }
                }
                throw new \RuntimeException("wprism: root selector '$selector' names no entity in this revision");
            case 'menu':
                return self::require_typed_slug($tree, 'menu', 'slug', $rest, $selector);
            case 'sidebar':
                return self::require_key($tree, SidebarState::key($rest), $selector);
            case 'user-meta':
                return self::require_key($tree, UserMetaState::key($rest), $selector);
            case 'option':
                return self::require_option($tree, $rest, $selector);
            case 'table':
                [$table, $uuid] = array_pad(explode(':', $rest, 2), 2, null);
                if ($uuid === null || $uuid === '') {
                    throw new \RuntimeException("wprism: root selector '$selector' needs the form table:<table>:<uuid>");
                }
                return self::require_uuid($tree, $owners, $uuid, [(string) $table], $selector);
            case 'post':
            case 'term':
                return self::require_uuid($tree, $owners, $rest, [$prefix], $selector);
        }
        throw new \RuntimeException("wprism: root selector '$selector' is not understood — " . self::GRAMMAR);
    }

    private const GRAMMAR = 'roots are post:<uuid>, term:<uuid>, table:<table>:<uuid>, menu:<slug>, sidebar:<id>, user-meta:<login>, options, option:<name>, path:<state-relative-path>, or all';

    private static function require_key(array $tree, string $key, string $selector): string {
        if (!isset($tree[$key])) {
            throw new \RuntimeException("wprism: root selector '$selector' names no entity in this revision");
        }
        return $key;
    }

    /**
     * Resolve `option:<name>` against the compiled document's own authored
     * records -- the same "a scope root is refused rather than silently
     * retyped" discipline require_uuid() already applies to post/term, just
     * checked against record presence instead of tree membership, since an
     * option is not its own tree entry.
     */
    private static function require_option(array $tree, string $name, string $selector): string {
        if (!isset($tree['options/core'])) {
            throw new \RuntimeException("wprism: root selector '$selector' names no entity in this revision");
        }
        $records = OptionState::records($tree['options/core']['data']);
        if (!array_key_exists($name, $records)) {
            throw new \RuntimeException("wprism: root selector '$selector' names no authored option in this revision");
        }
        return self::option_key($name);
    }

    private static function option_key(string $name): string {
        return self::OPTION_KEY_PREFIX . $name;
    }

    private static function is_option_key(string $key): bool {
        return str_starts_with($key, self::OPTION_KEY_PREFIX);
    }

    private static function option_name_from_key(string $key): string {
        return substr($key, strlen(self::OPTION_KEY_PREFIX));
    }

    /** True for the synthetic per-option key that is not a tree entry. */
    public static function is_option_root(string $key): bool {
        return self::is_option_key($key);
    }

    /** Recover the exact option name from a validated synthetic root key. */
    public static function option_name_from_root(string $key): string {
        if (!self::is_option_key($key)) {
            throw new \InvalidArgumentException('scope root is not a per-option synthetic identity');
        }
        $name = self::option_name_from_key($key);
        if ($name === '') {
            throw new \InvalidArgumentException('scope option root has no option name');
        }
        return $name;
    }

    /**
     * Attribute options/core's own outbound reference edges to the specific
     * option record each one actually came from, so that selecting one
     * option's closure never silently pulls in (or silently omits) another
     * option's dependencies.
     *
     * A locator is matched by exact-prefix-plus-boundary against every
     * authored record name's own locator prefix (mirroring exactly the one
     * more segment ReferenceGraph::walk_tokens() would append one level
     * below "$.records", including its int-key bracket form for a
     * numeric-string option name PHP's own array-key coercion turns into an
     * int) -- never by splitting the locator string on ".", which an option
     * name may itself legally contain. Two authored names can make a
     * locator match more than one prefix at once (one name a literal
     * structural prefix of another once dot-segmented); rather than guess
     * via longest-match, which is not always correct once a value can be
     * arbitrarily nested, an ambiguous locator refuses loudly -- but ONLY
     * when the ambiguity actually touches a requested name: an unrelated
     * collision between two options nobody selected must not block this
     * call's own, unaffected selection (matching every root against the
     * FULL document is still required to detect that collision correctly
     * in the first place -- checking only the requested names' own
     * prefixes could misattribute an edge that truly belongs to an
     * unrequested name that happens to also match).
     *
     * @param array<string,array<string,mixed>> $tree
     * @param list<array<string,mixed>> $edges
     * @param list<string> $requestedNames
     * @return array<string,list<array<string,mixed>>> option name => its own outbound edges
     */
    private static function attribute_option_edges(array $tree, array $edges, array $requestedNames): array {
        if (!isset($tree['options/core'])) {
            return [];
        }
        $requested = array_flip($requestedNames);
        $names = array_keys(OptionState::records($tree['options/core']['data']));
        $prefixes = [];
        foreach ($names as $name) {
            $prefixes[(string) $name] = '$.records' . (is_int($name) ? "[$name]" : '.' . $name);
        }
        $byName = [];
        foreach ($edges as $edge) {
            if ($edge['from'] !== 'options/core') {
                continue;
            }
            $locator = (string) $edge['locator'];
            $matches = [];
            foreach ($prefixes as $name => $prefix) {
                if (self::locator_belongs_to_prefix($locator, $prefix)) {
                    $matches[] = $name;
                }
            }
            if (count($matches) === 1) {
                $byName[$matches[0]][] = $edge;
                continue;
            }
            // Zero matches is a structural impossibility for a well-formed
            // document (every edge's locator was itself derived by walking
            // this exact records map, so its root segment must correspond
            // to some key in it) -- if it ever fires, it means this
            // function's own prefix logic has drifted from
            // ReferenceGraph::walk_tokens()'s, a bug worth surfacing
            // regardless of what was requested, not data this call can
            // safely ignore.
            if (count($matches) === 0 || array_intersect($matches, array_keys($requested))) {
                $matchedNames = implode("', '", $matches);
                throw new \RuntimeException(
                    count($matches) === 0
                        ? "wprism: option reference edge '$locator' does not resolve to any authored option name"
                        : "wprism: option reference edge '$locator' matches more than one option name ('$matchedNames') -- "
                            . 'refusing rather than guessing which option owns this dependency'
                );
            }
        }
        return $byName;
    }

    private static function locator_belongs_to_prefix(string $locator, string $prefix): bool {
        if ($locator === $prefix) {
            return true;
        }
        if (!str_starts_with($locator, $prefix)) {
            return false;
        }
        $boundary = $locator[strlen($prefix)];
        return $boundary === '.' || $boundary === '[' || $boundary === ' ';
    }

    private static function require_typed_slug(array $tree, string $type, string $field, string $value, string $selector): string {
        foreach ($tree as $key => $entity) {
            if ((string) $entity['type'] === $type && (string) ($entity['data'][$field] ?? '') === $value) {
                return (string) $key;
            }
        }
        throw new \RuntimeException("wprism: root selector '$selector' names no $type in this revision");
    }

    /** @param list<string> $expectTypes */
    private static function require_uuid(array $tree, array $owners, string $uuid, array $expectTypes, string $selector): string {
        if (isset($tree[$uuid])) {
            $actual = (string) $tree[$uuid]['type'];
            if (!in_array($actual, $expectTypes, true)) {
                throw new \RuntimeException(
                    "wprism: root selector '$selector' resolves to a '$actual' entity ({$tree[$uuid]['path']}), not "
                    . implode('/', $expectTypes) . ' — a scope root is refused rather than silently retyped'
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
                "wprism: root selector '$selector' names a {$owner['kind']} carried by {$tree[$owner['entity']]['path']} — "
                . 'select that entity instead; a menu item or widget cannot be scoped away from its owner'
            );
        }
        throw new \RuntimeException("wprism: root selector '$selector' names no entity in this revision");
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

        // A partially-included options/core (one or more of its options
        // selected, but not the whole surface) must not ALSO count as
        // wholly excluded -- that would report the same file as both
        // included and excluded at once, which is not honest.
        $anyOptionIncluded = false;
        foreach ($included as $includedKey => $_) {
            if (self::is_option_key((string) $includedKey)) {
                $anyOptionIncluded = true;
                break;
            }
        }

        $excludedByType = [];
        $excludedTotal = 0;
        foreach ($tree as $key => $entity) {
            $key = (string) $key;
            if (isset($included[$key]) || ($key === 'options/core' && $anyOptionIncluded)) {
                continue;
            }
            $excludedTotal++;
            $type = (string) $entity['type'];
            $excludedByType[$type] = ($excludedByType[$type] ?? 0) + 1;
        }
        ksort($excludedByType, SORT_STRING);

        // Media blobs an included attachment owns. They are artifacts rather
        // than tree entities, so they are named separately instead of being
        // counted as entities that were "included". An option root is never
        // an attachment.
        $media = [];
        foreach ($rows as $row) {
            if (self::is_option_key((string) $row['entity'])) {
                continue;
            }
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

        // One entity is one root however many selectors named it — `all`
        // beside an explicit root, or the same entity by uuid and by path.
        // Counting the request instead of the result made roots exceed
        // included, which reads as nonsense. First selector wins, matching
        // how the closure itself keeps the first reason for an inclusion.
        $rootRows = [];
        foreach ($roots as $root) {
            if (isset($rootRows[$root['entity']])) {
                continue;
            }
            if (self::is_option_key($root['entity'])) {
                $rootRows[$root['entity']] = [
                    'selector' => $root['selector'],
                    'entity' => $root['entity'],
                    'path' => (string) $tree['options/core']['path'],
                    'type' => 'option',
                    'option' => self::option_name_from_key($root['entity']),
                ];
                continue;
            }
            $rootRows[$root['entity']] = [
                'selector' => $root['selector'],
                'entity' => $root['entity'],
                'path' => (string) $tree[$root['entity']]['path'],
                'type' => (string) $tree[$root['entity']]['type'],
            ];
        }
        $rootRows = array_values($rootRows);

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
                'closure' => count($rows) - count($rootRows),
                'excluded' => $excludedTotal,
                'media' => count($media),
                'inbound' => count($inbound),
            ],
        ];
    }
}
