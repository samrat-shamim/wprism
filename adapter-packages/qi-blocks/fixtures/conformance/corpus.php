<?php
declare(strict_types=1);

/** Native saved corpus inputs; deliberately independent of adapter reference declarations. */
final class QiConformanceCorpus {
    public const EXTERNAL_BLOCKS = ['qi-blocks/contact-form-7', 'qi-blocks/product-list'];
    public const SOURCE_HOME = 'http://localhost:9164';

    public static function text(string $text, array $ids, string $home, string $imageUrl): string {
        $text = str_replace(self::SOURCE_HOME . '/wp-content/uploads/2026/09/tmp-qi-image', substr($imageUrl, 0, -4), $text);
        $text = str_replace(self::SOURCE_HOME, $home, $text);
        $text = preg_replace_callback('/([?&]p=)(9|10)(?![0-9])/',
            static fn(array $m): string => $m[1] . $ids[$m[2] === '9' ? 'first' : 'second'], $text);
        return preg_replace('/(?<![A-Za-z0-9_-])wp-image-8(?![0-9])/', 'wp-image-' . $ids['image'], $text);
    }

    public static function body(string $saved, array $ids, string $home, string $imageUrl, bool $canonical = false): string {
        $blocks = array_values(array_filter(parse_blocks($saved),
            static fn(array $b): bool => !in_array($b['blockName'], self::EXTERNAL_BLOCKS, true)));
        $walk = static function (array &$block) use (&$walk, $ids, $home, $imageUrl, $canonical): void {
            $attrs = static function (mixed $value, string|int $key = '') use (&$attrs, $ids, $home, $imageUrl, $canonical): mixed {
                if ($key === 'id' && $value === 8) return $ids['image'];
                if ($key === 'postIds' && is_string($value) && $value !== '') {
                    $selected = array_map(static function (string $id) use ($ids): int|string {
                        return match ($id) { '9' => $ids['first'], '10' => $ids['second'],
                            default => throw new RuntimeException('Unexpected native query fixture identity: ' . $id) };
                    }, explode(',', $value));
                    return $canonical ? $selected : implode(',', $selected);
                }
                if (is_array($value)) foreach ($value as $name => &$child) $child = $attrs($child, $name);
                return is_string($value) ? self::text($value, $ids, $home, $imageUrl) : $value;
            };
            $block['attrs'] = $attrs($block['attrs']);
            if ($canonical && $block['blockName'] === 'qi-blocks/blog-list') {
                unset($block['attrs']['maxNumPages'], $block['attrs']['queriedPostsData']);
            }
            foreach ($block['innerContent'] as &$fragment) if (is_string($fragment)) $fragment = self::text($fragment, $ids, $home, $imageUrl);
            unset($fragment);
            foreach ($block['innerBlocks'] as &$child) $walk($child);
        };
        foreach ($blocks as &$block) $walk($block);
        return serialize_blocks($blocks);
    }

    public static function block_names(string $body): array {
        $names = [];
        $walk = static function (array $blocks) use (&$walk, &$names): void {
            foreach ($blocks as $block) {
                if (is_string($block['blockName']) && str_starts_with($block['blockName'], 'qi-blocks/')) $names[] = $block['blockName'];
                $walk($block['innerBlocks']);
            }
        };
        $walk(parse_blocks($body));
        sort($names, SORT_STRING);
        return $names;
    }

    public static function styles(string $fixture, string $body, array $ids, string $home, string $imageUrl): object {
        $rows = json_decode($fixture, true, 512, JSON_THROW_ON_ERROR);
        $styles = null;
        foreach ($rows as $row) if ($row['option_name'] === 'qi_blocks_global_styles') {
            $styles = unserialize($row['option_value'], ['allowed_classes' => ['stdClass']]);
        }
        if (!is_array($styles) || !($styles['posts'][13] ?? null) instanceof stdClass) {
            throw new RuntimeException('Native style fixture lost its PHP container premise');
        }
        $out = new stdClass();
        $walk = static function (mixed &$value) use (&$walk, $ids, $home, $imageUrl): void {
            if (is_array($value) || $value instanceof stdClass) foreach ($value as &$child) $walk($child);
            elseif (is_string($value)) $value = str_replace('body[class*="-13"]', 'body[class*="-' . $ids['page'] . '"]', self::text($value, $ids, $home, $imageUrl));
        };
        foreach ($styles['posts'][13] as $key => $style) {
            // The two integration blocks are top-level native fixture entries.
            // Their CSS keys must leave with them; no other style is invented.
            if (!str_contains($body, $key)) continue;
            $walk($style);
            $out->{$key} = $style;
        }
        if (count((array) $out) < 40) throw new RuntimeException('Native style corpus unexpectedly lost its block owners');
        return $out;
    }
}
