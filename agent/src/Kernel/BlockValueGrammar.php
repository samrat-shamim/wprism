<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/ReferenceRules.php';

/** Exact block attributes reuse the option/meta value grammar without changing legacy path semantics. */
final class BlockValueGrammar {
    public const FEATURE = 'block-attribute-values/v1';
    public const SECTION = 'block_values';

    public static function section_grammar(): array {
        return [
            'keyed_by' => 'block name, then exact top-level attribute name',
            'authored' => ['class' => 'authored', 'optional' => ['ref', 'cast', 'json_refs', 'key_refs', 'plain_data']],
            'derived' => ['class' => 'derived'],
            'ownership' => 'one manifest per block when block_values is present; no overlapping block_attrs or whole-block codec',
            'values' => 'exactly one of ref, json_refs/key_refs, or plain_data:true; CSV refs require an array ref',
            'validated_by' => 'WPrism\\BlockValueGrammar::validate()',
        ];
    }

    public static function validate(array $manifest): void {
        if (!array_key_exists(self::SECTION, $manifest)) return;
        $registry = $manifest[self::SECTION];
        if (!is_array($registry) || $registry === [] || array_is_list($registry)) {
            throw new \RuntimeException('wprism: block_values must be a non-empty object keyed by block name');
        }
        foreach ($registry as $block => $attributes) {
            if (!is_string($block) || preg_match('/^[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*$/D', $block) !== 1
                || !is_array($attributes) || $attributes === [] || array_is_list($attributes)) {
                throw new \RuntimeException('wprism: block_values requires block names and non-empty exact attribute maps');
            }
            foreach ($attributes as $attribute => $rule) {
                $where = "block_values.$block.$attribute";
                if (!is_string($attribute) || preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $attribute) !== 1
                    || !is_array($rule) || array_is_list($rule)) {
                    throw new \RuntimeException("wprism: $where requires an exact attribute name and value rule");
                }
                if (($rule['class'] ?? null) === 'derived') {
                    if (array_keys($rule) !== ['class']) {
                        throw new \RuntimeException("wprism: $where derived attributes admit only class");
                    }
                    continue;
                }
                if (($rule['class'] ?? null) !== 'authored'
                    || array_diff(array_keys($rule), ['class', 'ref', 'cast', 'json_refs', 'key_refs', 'plain_data'])) {
                    throw new \RuntimeException("wprism: $where has an unsupported value disposition or field");
                }
                ReferenceRules::value_rule($rule, $where);
                $choices = (int) isset($rule['ref']) + (int) (isset($rule['json_refs']) || isset($rule['key_refs']))
                    + (int) (($rule['plain_data'] ?? null) === true);
                if ($choices !== 1 || (isset($rule['cast']) && !isset($rule['ref']))
                    || (($rule['cast'] ?? null) === 'csv' && !str_ends_with($rule['ref'], '[]'))) {
                    throw new \RuntimeException("wprism: $where requires one reference or plain-data codec; CSV requires a list ref");
                }
            }
        }
        self::project([$manifest]);
    }

    /** @param list<array> $manifests @return array<string,list<array>> */
    public static function project(array $manifests): array {
        $out = [];
        $owners = [];
        $values = [];
        foreach ($manifests as $i => $manifest) {
            foreach ($manifest['block_attrs'] ?? [] as $block => $rules) {
                $out[$block] = $rules;
                $owners[$block][$i] = true;
            }
            foreach ($manifest[self::SECTION] ?? [] as $block => $attributes) {
                $owners[$block][$i] = true;
                $values[$block] = $attributes;
            }
        }
        foreach ($values as $block => $attributes) {
            if (count($owners[$block]) !== 1) {
                throw new \RuntimeException("wprism: block_values '$block' requires one manifest owner across block declarations");
            }
            foreach ($out[$block] ?? [] as $rule) {
                if (array_key_exists('codec', $rule) || array_key_exists($rule['path'], $attributes)) {
                    throw new \RuntimeException("wprism: block_values '$block' overlaps a legacy attribute or whole-block codec");
                }
            }
            foreach ($attributes as $attribute => $rule) {
                $out[$block][] = ['path' => $attribute, 'value' => $rule];
            }
        }
        return $out;
    }
}
