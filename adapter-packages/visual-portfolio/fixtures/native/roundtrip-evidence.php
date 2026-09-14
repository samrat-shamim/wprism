<?php
declare(strict_types=1);

/** Independent native witnesses complement the harness's complete canonical diff. */
final class VisualPortfolioRoundtripEvidence
{
    public const POSTS = ['harbor', 'garden', 'harbor-light', 'paper-garden', 'quiet-shapes', 'open-horizon', 'vp-author-gallery', 'vp-alternate-archive'];
    public const TERMS = ['Field notes', 'Studio work'];

    private static function check(bool $ok, string $why): void
    {
        if (!$ok) throw new RuntimeException('Visual Portfolio roundtrip: ' . $why);
    }

    public static function rendered(string $html, array $record, string $kind): array
    {
        self::check(strlen($html) >= 1024 && !preg_match('/(?:PHP (?:Warning|Fatal error)|Warning:|Fatal error:)/', $html), 'complete diagnostic-free HTTP body');
        self::check(in_array($kind, ['gallery', 'archive'], true), 'known render kind');
        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try { self::check($dom->loadHTML('<?xml encoding="UTF-8">' . $html), 'rendered HTML parses'); }
        finally { libxml_clear_errors(); libxml_use_internal_errors($previous); }
        $xpath = new DOMXPath($dom);
        if ($kind === 'archive') {
            $nodes = $xpath->query('//*[contains(concat(" ",normalize-space(@class)," ")," vp-portfolio__item-meta-title ")]');
            self::check($nodes !== false && $nodes->length === 2, 'two legacy archive items');
            $titles = [];
            foreach ($nodes as $node) $titles[] = trim($node->textContent);
            self::check($titles === ['Open Horizon', 'Quiet Shapes'], 'native descending-date archive selection');
            return [];
        }
        $nodes = $xpath->query('//figure[contains(concat(" ",normalize-space(@class)," ")," wp-block-visual-portfolio-item-image ")]');
        self::check($nodes !== false && $nodes->length === 2, 'two modern gallery image figures');
        $assets = [];
        foreach ($nodes as $i => $node) {
            $name = ['harbor', 'garden'][$i];
            $media = $record['media'][$name];
            $images = $xpath->query('.//img', $node);
            $links = $xpath->query('.//a[@data-vp-popup]', $node);
            self::check($images !== false && $images->length === 1 && $links !== false && $links->length === 1, 'one image and native popup trigger per item');
            $img = $images->item(0); $link = $links->item(0);
            self::check(in_array('wp-image-' . $media['id'], preg_split('/\s+/', trim($img->getAttribute('class'))), true), 'rendered attachment role');
            self::check($link->getAttribute('href') === $media['url'] && $link->getAttribute('data-vp-popup') !== '', 'popup binds the physical original');
            $url = $img->hasAttribute('data-src') ? $img->getAttribute('data-src') : $img->getAttribute('src');
            $stem = substr($media['url'], 0, -4);
            self::check(preg_match('~^' . preg_quote($stem, '~') . '(?:-[1-9][0-9]*x[1-9][0-9]*)?\.png$~D', $url) === 1, 'rendered image belongs to its bound original');
            $assets[] = $url;
            $assets[] = $media['url'];
        }
        return array_values(array_unique($assets));
    }

    public static function compare(array $source, array $target): void
    {
        foreach ([$source, $target] as $record) {
            self::check(($record['format'] ?? null) === 'wprism-vp-native-roundtrip/v1'
                && ($record['plugin'] ?? null) === '3.8.1', 'exact native observation');
            self::check(is_string($record['home'] ?? null) && preg_match('~^https?://[^/]+$~D', $record['home']) === 1, 'native home');
            foreach (['posts' => self::POSTS, 'terms' => self::TERMS] as $kind => $names) {
                self::check(is_array($record[$kind] ?? null) && array_keys($record[$kind]) === $names, 'complete role inventory');
                $ids = []; $uuids = [];
                foreach ($record[$kind] as $row) {
                    self::check(is_array($row) && is_int($row['id'] ?? null) && $row['id'] > 0
                        && is_string($row['uuid'] ?? null) && preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $row['uuid']) === 1,
                        'native durable role identity');
                    $ids[] = $row['id']; $uuids[] = $row['uuid'];
                }
                self::check(count(array_unique($ids)) === count($names) && count(array_unique($uuids)) === count($names), 'distinct role identities');
            }
            self::check(($record['archive_page'] ?? null) === $record['posts']['vp-alternate-archive']['id']
                && ($record['placeholder'] ?? null) === $record['posts']['harbor']['id'], 'native settings bind correct target roles');
            self::check(is_array($record['media'] ?? null) && array_keys($record['media']) === ['harbor', 'garden'], 'two physical images');
            foreach ($record['media'] as $name => $media) {
                self::check(is_array($media) && ($media['id'] ?? null) === $record['posts'][$name]['id']
                    && is_string($media['sha256'] ?? null) && preg_match('/^[a-f0-9]{64}$/D', $media['sha256']) === 1
                    && is_int($media['bytes'] ?? null) && $media['bytes'] > 0
                    && is_string($media['url'] ?? null) && str_starts_with($media['url'], $record['home'] . '/wp-content/uploads/'), 'nonempty bound media bytes');
            }
            $expectedFeatured = [];
            foreach (['harbor-light', 'paper-garden', 'quiet-shapes', 'open-horizon'] as $i => $name) {
                $expectedFeatured[$name] = $record['posts'][$i % 2 === 0 ? 'harbor' : 'garden']['id'];
            }
            self::check(($record['featured'] ?? null) === $expectedFeatured, 'every native featured image');
            self::check(($record['gallery_ids'] ?? null) === [$record['posts']['harbor']['id'], $record['posts']['garden']['id']], 'ordered gallery attachment bindings');
        }
        self::check($source['home'] !== $target['home'], 'distinct site origins');
        foreach (['posts', 'terms'] as $kind) foreach ($source[$kind] as $name => $row) {
            self::check($target[$kind][$name]['uuid'] === $row['uuid'] && $target[$kind][$name]['id'] !== $row['id'], 'cross-site durable identity with divergent local IDs');
        }
        foreach (['harbor', 'garden'] as $name) {
            self::check($source['media'][$name]['sha256'] === $target['media'][$name]['sha256']
                && $source['media'][$name]['bytes'] === $target['media'][$name]['bytes'], 'original image bytes survive transfer');
        }
    }
}
