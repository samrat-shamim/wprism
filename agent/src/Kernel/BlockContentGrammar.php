<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/BlockValueGrammar.php';
require_once __DIR__ . '/Secrets.php';

/** Negotiated leaf-block ownership; legacy attribute codecs retain their original return contract. */
final class BlockContentGrammar {
    public const FEATURE = 'block-content-codecs/v1';
    public const SECTION = 'block_content';

    public static function section_grammar(): array {
        return [
            'keyed_by' => 'exact block name',
            'rule' => 'closed {codec, env_options, public_text}; codec matches every block_attrs rule and the same manifest interpreter',
            'public_text' => 'distinct declared scalar string attributes reviewed as public authored text; values still undergo secret and PII scanning, only key-role heuristics are removed',
            'env_options' => 'distinct exact required scalar env option names declared by the same manifest',
            'ownership' => 'one manifest across block_attrs, block_values and block_content; no site override',
            'hooks' => 'capture_block_content(block, Tokens, force, label) and apply_block_content(block, Tokens, environment): closed {attrs, html}',
            'result' => 'declared attributes and bounded leaf HTML only; no child blocks or generic inner-content rewriting',
        ];
    }

    public static function validate(array $manifest): void {
        if (!array_key_exists(self::SECTION, $manifest)) return;
        $registry = $manifest[self::SECTION];
        if (($manifest['spec_version'] ?? 0) < 3 || !in_array(self::FEATURE, (array) ($manifest['engine_features'] ?? []), true)
            || !is_array($registry) || $registry === [] || array_is_list($registry)) {
            throw new \RuntimeException('wprism: block_content requires a negotiated v3 nonempty block map');
        }
        foreach ($registry as $block => $rule) {
            if (!is_string($block) || preg_match('~^[a-z][a-z0-9_-]*/[a-z][a-z0-9_-]*$~D', $block) !== 1
                || !is_array($rule) || count($rule) !== 3 || array_diff(array_keys($rule), ['codec', 'env_options', 'public_text'])
                || !is_string($rule['codec'] ?? null) || $rule['codec'] === '' || $rule['codec'] !== ($manifest['interpreter'] ?? null)
                || !is_array($rule['env_options'] ?? null) || !array_is_list($rule['env_options'])) {
                throw new \RuntimeException('wprism: block_content requires closed manifest-bound codec and env_options rules');
            }
            $attrs = $manifest['block_attrs'][$block] ?? [];
            if ($attrs === []) throw new \RuntimeException('wprism: block_content requires a closed block_attrs codec declaration');
            foreach ($attrs as $attr) {
                if (($attr['codec'] ?? null) !== $rule['codec']) {
                    throw new \RuntimeException('wprism: block_content must own every declared block attribute');
                }
            }
            if (!is_array($rule['public_text'] ?? null) || !array_is_list($rule['public_text'])) {
                throw new \RuntimeException('wprism: block_content public_text requires an exact attribute list');
            }
            $seenText = [];
            foreach ($rule['public_text'] as $attribute) {
                if (!is_string($attribute) || !in_array($attribute, array_column($attrs, 'path'), true) || isset($seenText[$attribute])
                    || Secrets::suspicious($attribute, 'Abcdef1234567890-')) {
                    throw new \RuntimeException('wprism: block_content public_text requires distinct declared attributes');
                }
                $seenText[$attribute] = true;
            }
            $seen = [];
            foreach ($rule['env_options'] as $name) {
                if (!is_string($name) || preg_match('/^[a-zA-Z_][a-zA-Z0-9_.-]{0,190}$/D', $name) !== 1 || isset($seen[$name])) {
                    throw new \RuntimeException('wprism: block_content env_options requires distinct exact option names');
                }
                $seen[$name] = true;
                $option = $manifest['options'][$name] ?? [];
                if (($option['class'] ?? null) !== 'env' || ($option['required'] ?? null) !== true || !empty($option['sub_keys'])) {
                    throw new \RuntimeException('wprism: block_content requires same-manifest required scalar env options');
                }
            }
        }
    }

    public static function project(array $manifests, array $site = []): array {
        if (array_key_exists(self::SECTION, $site)) throw new \RuntimeException('wprism: block_content is manifest-owned, not a site policy override');
        $out = [];
        foreach ($manifests as $index => $manifest) {
            self::validate($manifest);
            foreach ($manifest[self::SECTION] ?? [] as $block => $rule) {
                foreach ($manifests as $otherIndex => $other) {
                    if ($otherIndex !== $index && (isset($other['block_attrs'][$block]) || isset($other[self::SECTION][$block])
                        || isset(BlockValueGrammar::attribute_maps($other)[$block]))) {
                        throw new \RuntimeException('wprism: block_content requires one manifest owner');
                    }
                }
                if (isset($site['block_attrs'][$block]) || isset($site['block_values'][$block]) || isset(BlockValueGrammar::attribute_maps($manifest)[$block])) {
                    throw new \RuntimeException('wprism: block_content cannot overlap another block grammar');
                }
                foreach ($rule['env_options'] as $name) {
                    if (array_key_exists($name, $site['options'] ?? [])) {
                        throw new \RuntimeException('wprism: block_content environment options cannot be overridden by site policy');
                    }
                }
                $out[$block] = $rule;
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
