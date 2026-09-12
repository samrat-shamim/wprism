<?php
declare(strict_types=1);

namespace WPrism\Interpreters;

use WPrism\Canon;
use WPrism\Policy;
use WPrism\Tokens;

/**
 * 1.35's buildMapIframe() saves credentials outside the attribute object.
 * Blocks::walk() invokes a whole-block codec even for absent/default attrs,
 * but accepts only attributes back, so it cannot rebind that saved HTML.
 */
final class MapBlockGutenberg {
    private const MESSAGE = "wprism: block 'webfactory/map' is unsupported: environment-local API keys in api_key and saved iframe HTML require a target environment binding";

    public function __construct(Policy $policy) {}

    public function post_meta_rule(string $key, array $allMeta): ?array {
        return null;
    }

    public function capture_block_attributes(
        array $block,
        Tokens $tokens,
        bool $forceUnresolvedRefs = false,
        string $postLabel = ''
    ): array {
        throw new \RuntimeException(self::MESSAGE);
    }

    public function apply_block_attributes(array $block, Tokens $tokens): array {
        throw new \RuntimeException(self::MESSAGE);
    }

    /** Repository compilation has no WordPress parser and must enforce the same boundary. */
    public function repository_diagnostics(array $tree): array {
        $out = [];
        foreach ($tree as $entity) {
            $bodies = [];
            if (($entity['type'] ?? '') === 'post') {
                $bodies['body'] = is_string($entity['body'] ?? null)
                    ? $entity['body'] : Canon::parse_post_file((string) ($entity['content'] ?? ''))[1];
            } elseif (($entity['type'] ?? '') === 'sidebar') {
                foreach (($entity['data']['widgets'] ?? []) as $index => $widget) {
                    if (($widget['type'] ?? '') === 'block') {
                        $bodies["widgets[$index].settings.content"] = $widget['settings']['content'] ?? '';
                    }
                }
            }
            foreach ($bodies as $locator => $body) {
                // Detect the reserved opening delimiter, not credential values.
                // Refusing incomplete delimiters too keeps malformed JSON from
                // becoming a bypass; errors never echo keys, addresses or HTML.
                if (is_string($body) && preg_match('~<!--\s+wp:webfactory/map(?:\s|$)~', $body) === 1) {
                    $out[] = [
                        'code' => 'adapter_schema_content_mismatch',
                        'path' => (string) ($entity['path'] ?? ''),
                        'locator' => $locator . '.webfactory/map',
                        'message' => self::MESSAGE,
                    ];
                }
            }
        }
        return $out;
    }
}
