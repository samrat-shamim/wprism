<?php
namespace Duo;

/**
 * Structure-aware content rewriting via the official block parser:
 * - block attributes per the manifest block_attrs registry (typed paths),
 * - wp-image-<id> classes inside media blocks' inner HTML,
 * - URL tokenization of inner content strings.
 * Classic (non-block) content parses as a single freeform block and gets URL
 * tokenization only. serialize_blocks() re-emission is the canonical form; it
 * is a fixed point after the first normalization, which the capture-twice
 * determinism test asserts.
 *
 * block_attrs rules come in three shapes, freely mixed per block name:
 * - a static ref: {"kind": "post"|"term"|"tt", "path": ..., "type": "int"|"int[]"}
 * - a polymorphic ref, kind dispatched from a sibling attribute:
 *   {"kind_from": {"attr": ..., "map": {sibling-value: kind}, "default"?: kind},
 *    "path": ..., "type": ...} — e.g. core/navigation-link's "id" is a post
 *   or term ref depending on its own "kind" attribute ("post-type"/"taxonomy"/
 *   "custom"/"post-type-archive"); dispatch resolving to no kind (no map hit,
 *   no default) leaves that attribute untouched rather than guessing.
 * - a string tokenizer: {"path": ..., "tokenize": "text"} — routes a plain
 *   string attribute value through the same {{home}}/{{uploads}} substitution
 *   as body text. Attribute values are otherwise invisible to the innerHTML/
 *   innerContent pass below (self-closing blocks like core/navigation-link
 *   carry no inner content at all), so this is the only way a URL-shaped
 *   attribute gets rebound across environments.
 *
 * A "kind"/"kind_from" ref's id_to_token() failing (unmapped or dangling —
 * no ledger row for that id) drops the value, matching options'/meta's
 * dangling-reference semantics (spec/repo-format.md): a scalar ref drops
 * the whole attribute key, an int[] ref drops just that element, both with
 * a warning naming the block/attribute/id. A raw env-local id must never
 * survive into canonical state — Lint::scan_blocks()'s unrewritten_
 * registered_ref finding is what catches it if it ever does. This is the
 * uniform dangling-style treatment only; block refs don't yet get the
 * unscoped-vs-dangling loud-abort triage Capture.php gives options/post_meta
 * (task #73) — that upgrade is wave-2 Capture.php work.
 */
final class Blocks {
    /** Blocks whose inner HTML may carry wp-image-<id> classes. */
    private const IMAGE_CLASS_BLOCKS = ['core/image', 'core/gallery', 'core/media-text', 'core/cover'];

    public static function capture_rewrite(string $content, Policy $policy, Tokens $tokens): string {
        if ($content === '') {
            return '';
        }
        $blocks = parse_blocks($content);
        $rules = $policy->block_attr_rules();
        $blocks = array_map(fn($b) => self::walk($b, $rules, $tokens, true), $blocks);
        return serialize_blocks($blocks);
    }

    public static function apply_rewrite(string $content, Policy $policy, Tokens $tokens): string {
        if ($content === '') {
            return '';
        }
        $blocks = parse_blocks($content);
        $rules = $policy->block_attr_rules();
        $blocks = array_map(fn($b) => self::walk($b, $rules, $tokens, false), $blocks);
        return serialize_blocks($blocks);
    }

    private static function walk(array $block, array $rules, Tokens $tokens, bool $capture): array {
        $name = $block['blockName'];
        foreach ($rules[$name] ?? [] as $rule) {
            $path = $rule['path'];
            if (!isset($block['attrs'][$path])) {
                continue;
            }
            $v = $block['attrs'][$path];

            if (!empty($rule['lint_ok'])) {
                // declared non-ref attribute (e.g. queryId — a query instance
                // index, not an entity id): exempts it from `wp duo lint`'s
                // *Id-name heuristic, and there is nothing to rewrite here
                continue;
            }

            if (($rule['tokenize'] ?? null) === 'text') {
                if (is_string($v)) {
                    $block['attrs'][$path] = $capture ? $tokens->tokenize_text($v) : $tokens->detokenize_text($v);
                }
                continue;
            }

            $kind = self::resolve_kind($rule, $block['attrs']);
            if ($kind === null) {
                continue;
            }
            $isArray = ($rule['type'] ?? 'int') === 'int[]';
            if ($capture) {
                // Dangling-reference semantics matching options/meta refs
                // (spec/repo-format.md "Dangling references"): an unmapped
                // id must never reach canonical state as a raw env-local
                // int — on another environment it may silently resolve to
                // an unrelated live row after auto-increment reuse. The
                // previous `?? (int) $id` here kept the raw id instead of
                // dropping it — the exact gap Lint::scan_blocks()'s new
                // unrewritten_registered_ref finding now catches when it
                // already happened. This does NOT attempt the unscoped-vs-
                // dangling loud-abort triage Capture.php does for options/
                // post_meta (task #73) — every unmapped block ref gets the
                // uniform dangling-style drop; giving block refs the same
                // unscoped upgrade is wave-2 Capture.php work.
                if ($isArray) {
                    $kept = [];
                    foreach ((array) $v as $i => $id) {
                        $tok = $tokens->id_to_token((int) $id, $kind);
                        if ($tok === null) {
                            $tokens->warnings[] = "block '$name' attribute '$path" . "[$i]': unmapped $kind id "
                                . (int) $id . ' dropped (dangling reference)';
                            continue;
                        }
                        $kept[] = $tok;
                    }
                    $block['attrs'][$path] = $kept;
                } else {
                    $tok = $tokens->id_to_token((int) $v, $kind);
                    if ($tok === null) {
                        $tokens->warnings[] = "block '$name' attribute '$path': unmapped $kind id " . (int) $v
                            . ' dropped (dangling reference)';
                        unset($block['attrs'][$path]);
                    } else {
                        $block['attrs'][$path] = $tok;
                    }
                }
            } else {
                if ($isArray) {
                    $block['attrs'][$path] = array_map(
                        fn($t) => is_string($t) && str_starts_with($t, '{{') ? $tokens->token_to_id($t) : (int) $t,
                        (array) $v
                    );
                } else {
                    $block['attrs'][$path] = is_string($v) && str_starts_with($v, '{{')
                        ? $tokens->token_to_id($v)
                        : (int) $v;
                }
            }
        }

        $rewriteImageClass = in_array($name, self::IMAGE_CLASS_BLOCKS, true);
        $rewriteString = function (?string $s) use ($tokens, $capture, $rewriteImageClass): ?string {
            if ($s === null || $s === '') {
                return $s;
            }
            if ($rewriteImageClass) {
                if ($capture) {
                    $s = preg_replace_callback('/wp-image-(\d+)/', function ($m) use ($tokens) {
                        $tok = $tokens->id_to_token((int) $m[1], 'post');
                        return $tok !== null ? 'wp-image-' . $tok : $m[0];
                    }, $s);
                } else {
                    $s = preg_replace_callback('/wp-image-(\{\{post:[0-9a-f-]{36}\}\})/', function ($m) use ($tokens) {
                        return 'wp-image-' . $tokens->token_to_id($m[1]);
                    }, $s);
                }
            }
            return $capture ? $tokens->tokenize_text($s) : $tokens->detokenize_text($s);
        };

        if (!empty($block['innerContent'])) {
            $block['innerContent'] = array_map(
                fn($chunk) => is_string($chunk) ? $rewriteString($chunk) : $chunk,
                $block['innerContent']
            );
        }
        if (!empty($block['innerHTML'])) {
            $block['innerHTML'] = $rewriteString($block['innerHTML']);
        }
        if (!empty($block['innerBlocks'])) {
            $block['innerBlocks'] = array_map(
                fn($b) => self::walk($b, $rules, $tokens, $capture),
                $block['innerBlocks']
            );
        }
        return $block;
    }

    /**
     * A rule's ref kind is either static ("kind") or dispatched from a
     * sibling attribute's current value ("kind_from": {attr, map, default?}).
     * Null means "no applicable kind" (e.g. a custom-kind navigation link,
     * where the sibling value has no map entry and no default is declared) —
     * the caller leaves that attribute untouched rather than guessing a kind.
     */
    private static function resolve_kind(array $rule, array $attrs): ?string {
        if (isset($rule['kind_from'])) {
            $kf = $rule['kind_from'];
            $sibling = $attrs[$kf['attr']] ?? null;
            return $kf['map'][$sibling] ?? $kf['default'] ?? null;
        }
        if (isset($rule['kind'])) {
            return $rule['kind'];
        }
        throw new \RuntimeException("duo: block_attrs rule for path '{$rule['path']}' needs 'kind' or 'kind_from'");
    }
}
