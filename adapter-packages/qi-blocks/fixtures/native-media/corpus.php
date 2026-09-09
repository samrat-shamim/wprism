<?php
declare(strict_types=1);

require_once __DIR__ . '/../conformance/corpus.php';

/** Retained editor output supplies selections; adapter rules are never an expected-value oracle. */
final class QiNativeMediaCorpus {
    public const DIMENSIONS = [[251, 157], [293, 181], [317, 193], [347, 219]];

    public static function saved(string $saved, string $media): string {
        $replacements = [];
        foreach (parse_blocks($media) as $block) {
            if ($block['blockName'] === null) continue;
            $name = $block['blockName'];
            if (!in_array($name, ['qi-blocks/single-image', 'qi-blocks/author-info', 'qi-blocks/parallax-images'], true)
                || isset($replacements[$name]) || $block['innerBlocks'] !== []) throw new RuntimeException('Unexpected native media fixture block');
            // These three fragments were saved with attachment 1 / home 9176;
            // the existing full corpus binder consumes attachment 8 / home 9164.
            $text = static function (string $value): string {
                return preg_replace('/(?<![A-Za-z0-9_-])wp-image-1(?![0-9])/', 'wp-image-8',
                    str_replace('http://localhost:9176', QiConformanceCorpus::SOURCE_HOME, $value));
            };
            $attrs = static function (mixed $value, string|int $key = '') use (&$attrs, $text): mixed {
                if ($key === 'id' && $value === 1) return 8;
                if (is_array($value)) foreach ($value as $name => &$child) $child = $attrs($child, $name);
                return is_string($value) ? $text($value) : $value;
            };
            $block['attrs'] = $attrs($block['attrs']);
            foreach ($block['innerContent'] as &$fragment) if (is_string($fragment)) $fragment = $text($fragment);
            unset($fragment);
            $replacements[$name] = $block;
        }
        if (count($replacements) !== 3) throw new RuntimeException('Native media fixture must contain three distinct blocks');
        $blocks = parse_blocks($saved); $seen = [];
        foreach ($blocks as &$block) if (isset($replacements[$block['blockName']])) {
            if (isset($seen[$block['blockName']])) throw new RuntimeException('Native corpus media owner is duplicated');
            $seen[$block['blockName']] = true;
            $block = $replacements[$block['blockName']];
        }
        unset($block);
        if (count($seen) !== 3) throw new RuntimeException('Native corpus lost a media owner');
        return serialize_blocks($blocks);
    }
}
