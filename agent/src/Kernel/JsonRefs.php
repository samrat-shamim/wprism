<?php
namespace WPrism;

require_once __DIR__ . '/ReferencePath.php';

/**
 * Minimal JSON-path-style primitive shared by json_refs (rewrite a scalar/
 * array id at a declared path) and key_refs (rewrite a map's own KEYS at a
 * declared path) — task #11 wave 2. Shaped by Elementor's `_elementor_data`
 * postmeta, whose ids sit at nested JSON paths rather than at the value's
 * root, then generalized to options by Polylang's `polylang` option — one
 * flat array mixing authored config with a ref-bearing `nav_menus` sub-key
 * (design-review-v0 finding #9). Also used by Lint::scan_tree() to know which
 * locators a declaration already "owns" (declared paths clean, undeclared
 * paths in the same structure still flagged — see Lint.php).
 *
 * Grammar (deliberately minimal — no filters, no explicit array indices;
 * designed from the actual verified samples below, not speculatively):
 *
 *   path    := '$' segment*
 *   segment := '.' key    ; direct child, exact key name
 *            | '..' key   ; recursive descent — `key` at ANY depth in the
 *                           subtree (through both object values and array
 *                           elements), continuing to search deeper even
 *                           past a match (a nested same-named key is legal)
 *            | '.*'       ; every direct child, any key name (wildcard)
 *   key     := [A-Za-z0-9_-]+
 *
 * Every segment is transparently array-mapping: when the CURRENT node is a
 * PHP list (array_is_list()), a segment applies itself to EACH element
 * rather than treating the list's own positional keys as match candidates.
 * This is what lets "$..image.id" reach into Elementor's arbitrarily deep
 * `elements[].elements[]...` nesting, and the SAME "$..wp_gallery.id" (no
 * special "[]" syntax) also reach into a gallery's array-of-{id,url} —
 * confirmed empirically (see below), not assumed.
 *
 * Verified shapes driving this grammar (fx1/fx2 probes, task #11 wave 2 —
 * Elementor 4.2.1 via its own Document::save() pipeline, Yoast SEO 28.2 via
 * WPSEO_Taxonomy_Meta::set_values(), both documented public APIs):
 *   - Elementor `_elementor_data`: a JSON array of arbitrarily-nested
 *     `elements[]` (section -> column -> widget, widgets can themselves
 *     nest). Every media-reference control observed (widget "image",
 *     section/column "background_image", gallery widget "wp_gallery")
 *     uses the identical `{"id":<int>,"url":"<string>"}` shape — a single
 *     `"$..image.id"`-style path per control name covers it regardless of
 *     nesting depth; the gallery case is the SAME shape as an ARRAY
 *     (`[{"id":..,"url":..}, ...]`), covered for free by array-transparency.
 *   - Yoast `wpseo_taxonomy_meta`: PHP-serialized
 *     `{taxonomy_name: {term_id(int): {wpseo_opengraph-image-id(string
 *     digits): ..., ...}}}` — an id-KEYED map (key_refs, "$.*") one level
 *     under a wildcarded taxonomy-name level, with id-VALUED sibling
 *     fields (json_refs, "$.*.*.wpseo_opengraph-image-id") at the level
 *     below that — both reachable with the same minimal grammar, no
 *     extension needed for either capability to coexist on one value.
 */
final class JsonRefs {
    /**
     * Parse "$..image.id" into a segment list. Throws on malformed syntax —
     * this is manifest-authoring-time data, so a bad path should fail loud
     * and early (at first capture/apply/lint use), not silently match
     * nothing.
     *
     * @return array<int, array{type: 'child'|'desc'|'wild', key: ?string}>
     */
    public static function parse_path(string $path): array {
        return ReferencePath::parse($path);
    }

    /**
     * Walk $root (by reference) applying $segments in order. For every
     * position where the FINAL segment resolves, calls
     * `$fn($container, $key, $locator)` — $container[$key] IS the matched
     * leaf, and $container is a reference into $root, so the callback may
     * read OR overwrite `$container[$key]` in place (used identically by
     * json_refs' scalar rewrite and key_refs' whole-map key-rename — see
     * Tokens::struct_capture()/struct_apply()).
     *
     * $locator accumulates a human-readable pointer as segments resolve
     * (dotted keys, "[i]" indices, ".." to mark a recursive-descent hop) —
     * same style Lint.php's existing locators use, prefixed by whatever the
     * caller passes as the starting $locator (e.g. "meta._elementor_data").
     */
    public static function walk(&$root, array $segments, callable $fn, string $locator): void {
        self::step($root, $segments, 0, $fn, $locator);
    }

    /**
     * Resolve terminal matches before rewriting, treating each matched value
     * as opaque. A scalar codec may emit a container; recursive paths must not
     * reinterpret that generated payload or any nested terminal match inside
     * the same owned value. The ordinary walk contract remains unchanged.
     */
    public static function walk_atomic(&$root, array $segments, callable $fn, string $locator): void {
        $matches = [];
        $protected = [];
        self::step($root, $segments, 0,
            static function (&$container, $key, string $matchedLocator, array $keys) use (&$matches, &$protected): void {
                $matches[] = ['keys' => $keys, 'locator' => $matchedLocator];
                self::protect_position($protected, $keys);
            }, $locator, []);

        foreach ($matches as $match) {
            $branch = &$protected;
            foreach ($match['keys'] as $part) {
                if (!is_array($branch)) {
                    // A terminal ancestor owns this complete value, whether
                    // its callback already ran or appears later in path order.
                    continue 2;
                }
                $branch = &$branch[$part];
            }
            if ($branch !== true) {
                continue;
            }
            $branch = false; // Duplicate terminal matches have one owner.
            $keys = $match['keys'];
            $key = array_pop($keys);
            $container = &$root;
            foreach ($keys as $part) {
                if (!is_array($container) || !array_key_exists($part, $container)) {
                    throw new \RuntimeException('wprism: atomic reference callback invalidated a later match');
                }
                $container = &$container[$part];
            }
            if (!is_array($container) || !array_key_exists($key, $container)) {
                throw new \RuntimeException('wprism: atomic reference callback invalidated a later match');
            }
            $fn($container, $key, $match['locator']);
            unset($container, $branch);
        }
    }

    /** Native keys make a terminal-owner trie independent of locator spelling. */
    private static function protect_position(array &$protected, array $keys): void {
        $branch = &$protected;
        foreach ($keys as $part) {
            if ($branch === true) {
                return;
            }
            $branch[$part] ??= [];
            $branch = &$branch[$part];
        }
        $branch = true;
    }

    /**
     * Select scalar values by the existing path dialect, retaining native key
     * coordinates. A matched container grants no authority to its children;
     * a dotted diagnostic locator is never an authorization key.
     *
     * @param array<mixed> $value
     * @param list<string> $paths
     * @return array<mixed> Native-key trie whose true terminals are scalar/null positions.
     */
    public static function scalar_position_trie(array $value, array $paths): array {
        $positions = [];
        foreach ($paths as $path) {
            self::step($value, self::parse_path($path), 0,
                static function (&$container, $key, string $locator, array $keys) use (&$positions): void {
                    if (is_scalar($container[$key]) || $container[$key] === null) {
                        self::protect_position($positions, $keys);
                    }
                }, '', []);
        }
        return $positions;
    }

    /**
     * Apply a text codec outside positions owned by the existing reference
     * dialect. A declared literal sentinel is not URL prose, and typed token
     * envelopes are not a second text surface. Selection precedes rewriting;
     * the protected trie uses native keys, never ambiguous dotted locators.
     *
     * @param array<mixed> $value
     * @param list<string> $paths
     * @param callable(string):string $rewrite
     * @return array<mixed>
     */
    public static function rewrite_unreferenced_strings(array $value, array $paths, callable $rewrite): array {
        $protected = [];
        foreach ($paths as $path) {
            self::step($value, self::parse_path($path), 0,
                static function (&$container, $key, string $locator, array $keys) use (&$protected): void {
                    self::protect_position($protected, $keys);
                }, '', []);
        }

        return self::rewrite_string_leaves($value, $protected, $rewrite);
    }

    /** @param array<mixed> $protected
     *  @param callable(string):string $rewrite */
    private static function rewrite_string_leaves(mixed $value, array $protected, callable $rewrite): mixed {
        if (is_string($value)) {
            $rewritten = $rewrite($value);
            if (!is_string($rewritten)) {
                throw new \RuntimeException('wprism: string leaf rewrite returned a non-string value');
            }
            return $rewritten;
        }
        if (is_array($value)) {
            foreach ($value as $key => $child) {
                $branch = $protected[$key] ?? [];
                if ($branch !== true) {
                    $value[$key] = self::rewrite_string_leaves($child, $branch, $rewrite);
                }
            }
        }
        return $value;
    }

    /** Coordinates are internal and opt-in: existing walk callbacks still
     *  receive exactly three arguments, including when they have optional ones. */
    private static function step(&$node, array $segments, int $i, callable $fn, string $locator, ?array $keys = null): void {
        if (!is_array($node)) {
            return;
        }
        if (array_is_list($node)) {
            // Transparent array mapping: the CURRENT segment applies to
            // every element (never to the list's own positional keys) —
            // this is what makes "[]" syntax unnecessary in the grammar.
            foreach ($node as $idx => &$el) {
                self::step($el, $segments, $i, $fn, $locator . '[' . $idx . ']', $keys === null ? null : [...$keys, $idx]);
            }
            unset($el);
            return;
        }

        $seg = $segments[$i];
        $isLast = ($i === count($segments) - 1);

        if ($seg['type'] === 'child' || $seg['type'] === 'wild') {
            foreach ($node as $key => &$child) {
                if ($seg['type'] === 'child' && (string) $key !== $seg['key']) {
                    continue;
                }
                $childLocator = $locator . '.' . $key;
                if ($isLast) {
                    if ($keys === null) {
                        $fn($node, $key, $childLocator);
                    } else {
                        $fn($node, $key, $childLocator, [...$keys, $key]);
                    }
                } else {
                    self::step($child, $segments, $i + 1, $fn, $childLocator, $keys === null ? null : [...$keys, $key]);
                }
            }
            unset($child);
            return;
        }

        // 'desc': match `key` at THIS level, any depth, AND keep descending
        // into every child regardless (a deeper/nested same-named key is a
        // separate, equally valid match — true recursive-descent semantics).
        $key = $seg['key'];
        if (array_key_exists($key, $node)) {
            $childLocator = $locator . '..' . $key;
            if ($isLast) {
                if ($keys === null) {
                    $fn($node, $key, $childLocator);
                } else {
                    $fn($node, $key, $childLocator, [...$keys, $key]);
                }
            } else {
                self::step($node[$key], $segments, $i + 1, $fn, $childLocator, $keys === null ? null : [...$keys, $key]);
            }
        }
        foreach ($node as $k => &$child) {
            if (is_array($child)) {
                self::step($child, $segments, $i, $fn, $locator . '.' . $k, $keys === null ? null : [...$keys, $k]);
            }
        }
        unset($child);
    }
}
