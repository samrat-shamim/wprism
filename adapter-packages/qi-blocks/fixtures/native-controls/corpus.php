<?php
declare(strict_types=1);

require_once __DIR__ . '/../conformance/corpus.php';

/** Replay the three real full-response gallery fragments; simple-media controls have separate evidence. */
final class QiNativeControlsCorpus {
    public const GALLERIES = ['qi-blocks/image-gallery', 'qi-blocks/image-gallery-pinterest', 'qi-blocks/image-slider'];

    public static function saved(string $saved, string $controls): string {
        $replacements = [];
        foreach (parse_blocks($controls) as $block) {
            if (!in_array($block['blockName'], self::GALLERIES, true)) continue;
            if (isset($replacements[$block['blockName']]) || $block['innerBlocks'] !== []) throw new RuntimeException('Native gallery fixture owner is ambiguous');
            $text = static fn(string $value): string => preg_replace('/(?<![A-Za-z0-9_-])wp-image-1(?![0-9])/', 'wp-image-8',
                str_replace('http://localhost:9176', QiConformanceCorpus::SOURCE_HOME, $value));
            $attrs = static function (mixed $value, string|int $key = '') use (&$attrs, $text): mixed {
                if ($key === 'id' && $value === 1) return 8;
                if (is_array($value)) foreach ($value as $name => &$child) $child = $attrs($child, $name);
                return is_string($value) ? $text($value) : $value;
            };
            $block['attrs'] = $attrs($block['attrs']);
            foreach ($block['innerContent'] as &$fragment) if (is_string($fragment)) $fragment = $text($fragment);
            unset($fragment);
            $replacements[$block['blockName']] = $block;
        }
        if (count($replacements) !== 3) throw new RuntimeException('Native gallery fixture requires all three full-response writers');
        $blocks = parse_blocks($saved); $seen = [];
        foreach ($blocks as &$block) if (isset($replacements[$block['blockName']])) {
            if (isset($seen[$block['blockName']])) throw new RuntimeException('Native corpus gallery owner is duplicated');
            $seen[$block['blockName']] = true;
            $block = $replacements[$block['blockName']];
        }
        unset($block);
        if (count($seen) !== 3) throw new RuntimeException('Native corpus lost a gallery owner');
        return serialize_blocks($blocks);
    }
}
