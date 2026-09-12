<?php
declare(strict_types=1);

namespace WPrism\Interpreters;

use WPrism\ProviderSdk;
use WPrism\Canon;
use WPrism\Policy;
use WPrism\Tokens;

/** Exact 1.35 static saver: attributes and iframe credentials are one environment binding. */
final class MapBlockGutenberg {
    public const OPTION = 'gmw-map-block-key';
    public const BINDING = '@env';
    private const MESSAGE = "wprism: block 'webfactory/map' does not match the supported 1.35 environment-bound static map schema";

    public function __construct(Policy $policy) {}
    public function post_meta_rule(string $key, array $allMeta): ?array { return null; }

    public function capture_block_content(array $block, Tokens $tokens, bool $force = false, string $label = ''): array {
        $attrs = self::attributes($block['attrs'] ?? null, false);
        $html = $block['innerHTML'] ?? null;
        if (!is_string($html) || preg_match('~&amp;key=([A-Za-z0-9_-]{1,64})"~', $html, $match) !== 1) self::refuse();
        $key = $match[1];
        if (isset($attrs['api_key']) && !hash_equals($attrs['api_key'], $key)) self::refuse();
        if (!hash_equals(self::html($attrs, $key), trim($html, " \t\r\n"))) self::refuse();
        // Explicit historical and omitted editor-default keys share the same
        // declared target binding. Neither source value is portable.
        $attrs['api_key'] = self::BINDING;
        return ['attrs' => $attrs, 'html' => "\n" . self::html($attrs, self::BINDING) . "\n"];
    }

    public function apply_block_content(array $block, Tokens $tokens, array $environment): array {
        $attrs = self::canonical($block);
        $key = $environment[self::OPTION] ?? null;
        if (!is_string($key) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $key) !== 1) self::refuse();
        $attrs['api_key'] = $key;
        return ['attrs' => $attrs, 'html' => "\n" . self::html($attrs, $key) . "\n"];
    }

    private static function attributes(mixed $attrs, bool $canonical): array {
        if (!is_array($attrs) || ($attrs !== [] && array_is_list($attrs))
            || array_diff(array_keys($attrs), ['zoom', 'height', 'address', 'api_key'])) self::refuse();
        if ($canonical && count($attrs) !== 4) self::refuse();
        $out = ['zoom' => $attrs['zoom'] ?? 10, 'height' => $attrs['height'] ?? 300,
            'address' => $attrs['address'] ?? 'Theater District, New York, USA'];
        foreach (['zoom', 'height', 'address'] as $name) {
            if (array_key_exists($name, $attrs) && $attrs[$name] === null) self::refuse();
        }
        // Inspector bounds: zoom 1..21, height 50..1000. Persisted values
        // must be integers; parseInt truncation is not an authored codec.
        if (!is_int($out['zoom']) || $out['zoom'] < 1 || $out['zoom'] > 21
            || !is_int($out['height']) || $out['height'] < 50 || $out['height'] > 1000
            || !is_string($out['address']) || strlen($out['address']) > 8192
            || preg_match('//u', $out['address']) !== 1 || preg_match('/[\x00-\x1f\x7f]/', $out['address']) !== 0) self::refuse();
        if (array_key_exists('api_key', $attrs)) {
            if (!is_string($attrs['api_key']) || ($canonical ? $attrs['api_key'] !== self::BINDING
                : preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $attrs['api_key']) !== 1)) self::refuse();
            $out['api_key'] = $attrs['api_key'];
        }
        return $out;
    }

    private static function canonical(array $block): array {
        $attrs = self::attributes($block['attrs'] ?? null, true);
        if (($block['innerBlocks'] ?? []) !== [] || !is_string($block['innerHTML'] ?? null)
            || !hash_equals("\n" . self::html($attrs, self::BINDING) . "\n", $block['innerHTML'])) self::refuse();
        return $attrs;
    }

    private static function html(array $attrs, string $key): string {
        // encodeURIComponent leaves these five characters unescaped.
        $query = strtr(rawurlencode($attrs['address']), ['%21' => '!', '%27' => "'", '%28' => '(', '%29' => ')', '%2A' => '*']);
        $src = 'https://www.google.com/maps/embed/v1/place?q=' . $query
            . '&maptype=roadmap&zoom=' . $attrs['zoom'] . '&key=' . $key;
        $escaped = htmlspecialchars($src, ENT_COMPAT | ENT_SUBSTITUTE, 'UTF-8');
        // Core injects the default block class on save()'s outer element;
        // the plugin itself supplies the identical class on its inner div.
        return '<div class="wp-block-webfactory-map"><div class="wp-block-webfactory-map"><iframe title="Map" width="100%" height="'
            . $attrs['height'] . 'px" src="' . $escaped . '" frameborder="0"></iframe></div></div>';
    }

    private static function refuse(): never { throw new \RuntimeException(self::MESSAGE); }

    /** Pure compilation rejects injected credentials and saver drift without WordPress. */
    public function repository_diagnostics(array $tree): array {
        $out = [];
        foreach ($tree as $entity) {
            $bodies = [];
            if (($entity['type'] ?? '') === 'post') {
                $bodies['body'] = is_string($entity['body'] ?? null)
                    ? $entity['body'] : Canon::parse_post_file((string) ($entity['content'] ?? ''))[1];
            } elseif (($entity['type'] ?? '') === 'sidebar') {
                foreach (($entity['data']['widgets'] ?? []) as $index => $widget) {
                    if (($widget['type'] ?? '') === 'block') $bodies["widgets[$index].settings.content"] = $widget['settings']['content'] ?? '';
                }
            }
            foreach ($bodies as $locator => $body) {
                if (!is_string($body)) continue;
                try {
                    $blocks = ProviderSdk::block_attributes_read($body, ['webfactory/map']);
                    // Incomplete reserved openings cannot become freeform just
                    // because the generic delimiter reader skips them.
                    $count = preg_match_all('~<!--\s+wp:webfactory/map(?:\s|$)~', $body);
                    if ($count !== count($blocks)) self::refuse();
                    foreach ($blocks as $block) {
                        $start = strpos($body, '-->', $block['offset']);
                        $end = $start === false ? false : strpos($body, '<!-- /wp:webfactory/map -->', $start + 3);
                        if ($start === false || $end === false || substr($body, $start - 1, 1) === '/') self::refuse();
                        self::canonical(['attrs' => $block['attrs'], 'innerHTML' => substr($body, $start + 3, $end - $start - 3)]);
                    }
                } catch (\RuntimeException) {
                    $out[] = ['code' => 'adapter_schema_content_mismatch', 'path' => (string) ($entity['path'] ?? ''),
                        'locator' => $locator . '.webfactory/map', 'message' => self::MESSAGE];
                }
            }
        }
        return $out;
    }
}
