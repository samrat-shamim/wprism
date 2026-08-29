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

    private static function step(&$node, array $segments, int $i, callable $fn, string $locator): void {
        if (!is_array($node)) {
            return;
        }
        if (array_is_list($node)) {
            // Transparent array mapping: the CURRENT segment applies to
            // every element (never to the list's own positional keys) —
            // this is what makes "[]" syntax unnecessary in the grammar.
            foreach ($node as $idx => &$el) {
                self::step($el, $segments, $i, $fn, $locator . '[' . $idx . ']');
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
                    $fn($node, $key, $childLocator);
                } else {
                    self::step($child, $segments, $i + 1, $fn, $childLocator);
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
                $fn($node, $key, $childLocator);
            } else {
                self::step($node[$key], $segments, $i + 1, $fn, $childLocator);
            }
        }
        foreach ($node as $k => &$child) {
            if (is_array($child)) {
                self::step($child, $segments, $i, $fn, $locator . '.' . $k);
            }
        }
        unset($child);
    }
}
