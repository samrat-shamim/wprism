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
            $isArray = ($rule['type'] ?? 'int') === 'int[]';
            $kind = $rule['kind'];
            $v = $block['attrs'][$path];
            if ($capture) {
                if ($isArray) {
                    $block['attrs'][$path] = array_map(
                        fn($id) => $tokens->id_to_token((int) $id, $kind) ?? (int) $id,
                        (array) $v
                    );
                } else {
                    $block['attrs'][$path] = $tokens->id_to_token((int) $v, $kind) ?? (int) $v;
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
}
