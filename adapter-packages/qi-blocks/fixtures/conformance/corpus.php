<?php
declare(strict_types=1);

/** Native saved corpus inputs; deliberately independent of adapter reference declarations. */
final class QiConformanceCorpus {
    public const EXTERNAL_BLOCKS = ['qi-blocks/contact-form-7', 'qi-blocks/product-list'];
    public const SOURCE_HOME = 'http://localhost:9164';

    /** A spatial signal makes wrong crop origins observable; the historical uniform PNG could not. */
    public static function image_png(): string {
        $image = imagecreatetruecolor(1200, 800);
        if ($image === false) throw new RuntimeException('Native Qi fixture cannot allocate its asymmetric raster');
        try {
            for ($y = 0; $y < 800; $y++) for ($x = 0; $x < 1200; $x++) {
                $red = intdiv($x * 255, 1199); $green = intdiv($y * 255, 799);
                $blue = ((intdiv($x, 97) + 3 * intdiv($y, 61)) % 2) * 173;
                if (!imagesetpixel($image, $x, $y, ($red << 16) | ($green << 8) | $blue)) throw new RuntimeException('Native Qi fixture cannot draw its asymmetric raster');
            }
            ob_start();
            try {
                if (!imagepng($image)) throw new RuntimeException('Native Qi fixture cannot encode its asymmetric raster');
                return ob_get_contents();
            } finally { ob_end_clean(); }
        } finally { imagedestroy($image); }
    }

    /** Independently fixed corner/center values establish the live input premise, before crop comparison. */
    public static function image_samples(string $bytes): array {
        $size = getimagesizefromstring($bytes);
        if (!is_array($size) || [$size[0], $size[1], $size['mime']] !== [1200, 800, 'image/png']) throw new RuntimeException('Native Qi asymmetric original has wrong dimensions or MIME');
        $image = imagecreatefromstring($bytes);
        if ($image === false) throw new RuntimeException('Native Qi asymmetric original cannot decode');
        try {
            $samples = [];
            foreach ([[0, 0], [1199, 0], [0, 799], [1199, 799], [600, 400]] as [$x, $y]) {
                $color = imagecolorsforindex($image, imagecolorat($image, $x, $y));
                $samples[] = [$color['red'], $color['green'], $color['blue'], $color['alpha']];
            }
            if ($samples !== [[0, 0, 0, 0], [255, 0, 0, 0], [0, 255, 173, 0], [255, 255, 173, 0], [127, 127, 0, 0]]) throw new RuntimeException('Native Qi original lost its independent asymmetric sample signal');
            return $samples;
        } finally { imagedestroy($image); }
    }

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
            if ($canonical) self::exclude_gallery_response($block);
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

    public static function applied_body(string $saved, array $ids, string $home, string $imageUrl): string {
        $blocks = parse_blocks(self::body($saved, $ids, $home, $imageUrl));
        $walk = static function (array &$blocks) use (&$walk): void {
            foreach ($blocks as &$block) {
                // Native query previews are derived; the saved CSV selection
                // remains a string after Apply, unlike its canonical token list.
                if ($block['blockName'] === 'qi-blocks/blog-list') unset($block['attrs']['maxNumPages'], $block['attrs']['queriedPostsData']);
                self::exclude_gallery_response($block);
                $walk($block['innerBlocks']);
            }
        };
        $walk($blocks);
        return serialize_blocks($blocks);
    }

    /** Locked native savers consume four gallery fields; this expectation never reads the adapter. */
    private static function exclude_gallery_response(array &$block): void {
        if (!in_array($block['blockName'], ['qi-blocks/image-gallery', 'qi-blocks/image-gallery-pinterest', 'qi-blocks/image-slider'], true)) return;
        foreach ($block['attrs']['gallery'] ?? [] as $i => $image) {
            $block['attrs']['gallery'][$i] = array_intersect_key($image, ['id' => true, 'url' => true, 'alt' => true, 'caption' => true]);
        }
    }

    public static function global_styles(object $styles, int $page): array {
        // Qi 1.5.2 add_options() initializes these four ordered roots before its
        // REST writer adds the page entry (global styles class:54–64,143–157).
        return ['posts' => [$page => $styles], 'widgets' => [], 'templates' => [], 'undefined' => []];
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
