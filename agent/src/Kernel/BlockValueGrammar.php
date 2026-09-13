<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/ReferenceRules.php';
require_once __DIR__ . '/RecordFields.php';
require_once __DIR__ . '/EncodedText.php';
require_once __DIR__ . '/ValueContractGrammar.php';

/** Exact block attributes reuse the option/meta value grammar without changing legacy path semantics. */
final class BlockValueGrammar {
    public const FEATURE = 'block-attribute-values/v1';
    public const SECTION = 'block_values';
    public const GROUP_FEATURE = 'block-attribute-groups/v1';
    public const GROUP_FIELD = 'groups';
    public const MAX_GROUPS = 256;
    public const MAX_GROUP_MEMBERS = 4096;
    public const MAX_GROUP_VALUES = 65536;
    public const CONTRACT_FEATURE = 'block-value-contracts/v1';
    public const MAX_OBJECT_FIELDS = ValueContractGrammar::MAX_OBJECT_FIELDS;
    public const MAX_OBJECT_DEPTH = ValueContractGrammar::MAX_OBJECT_DEPTH;
    public const MAX_CONTRACT_RULES = ValueContractGrammar::MAX_CONTRACT_RULES;
    public const MAX_ENUM_VALUES = ValueContractGrammar::MAX_ENUM_VALUES;

    public static function section_grammar(): array {
        return [
            'keyed_by' => 'block name, then exact top-level attribute name',
            'authored' => ['class' => 'authored', 'optional' => ['ref', 'cast', 'json_refs', 'key_refs', 'plain_data', RecordFields::FIELD, EncodedText::FIELD, ...ReferenceRules::BLOCK_CONTRACT_FIELDS]],
            'derived' => ['class' => 'derived'],
            'ownership' => 'one manifest per block when block_values is present; no overlapping block_attrs or whole-block codec',
            'values' => 'exactly one of ref, json_refs/key_refs, plain_data:true, negotiated text_encoding, object_fields, or enum; CSV refs require an array ref',
            'validated_by' => 'WPrism\\BlockValueGrammar::validate()',
        ];
    }

    public static function validate(array $manifest): void {
        if (!array_key_exists(self::SECTION, $manifest)) return;
        $registry = self::attribute_maps($manifest);
        if (!is_array($registry) || $registry === [] || array_is_list($registry)) {
            throw new \RuntimeException('wprism: block_values must be a non-empty object keyed by block name');
        }
        $base = ($manifest['spec_version'] ?? 0) >= 3
            && in_array(self::FEATURE, $manifest['engine_features'] ?? [], true);
        $grammar = new ValueContractGrammar(
            $base && in_array(self::CONTRACT_FEATURE, $manifest['engine_features'] ?? [], true),
            $base && in_array(RecordFields::FEATURE, $manifest['engine_features'] ?? [], true),
            $base && in_array(EncodedText::FEATURE, $manifest['engine_features'] ?? [], true),
            'block'
        );
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
                $grammar->validate($rule, $where);
            }
        }
        self::project([$manifest]);
    }

    public static function contract_grammar(): array {
        return [
            'field' => 'block_values.<block>.<attribute>',
            'object_fields' => 'nonempty exact-name map of authored value rules; present fields only; unknown fields and empty/non-object containers refuse',
            'enum' => 'one to 64 distinct strict literals: integers, booleans, null, or ASCII codes [A-Za-z0-9_-] of zero to 128 bytes; preserved without text rewriting',
            'on_unmapped' => 'refuse; reference codecs only; missing capture identities and target user bindings refuse instead of dropping references or using a default author',
            'reference_keyspaces' => 'json_refs/key_refs resolve durable identities; user login identities require ref:user or ref:user[] leaves, including within object_fields',
            'composition' => 'object_fields and enum each own their complete value and cannot combine with another codec; nested members cannot be derived',
            'max_fields_per_object' => self::MAX_OBJECT_FIELDS, 'max_object_depth' => self::MAX_OBJECT_DEPTH,
            'max_expanded_contract_rules' => self::MAX_CONTRACT_RULES,
            'authority' => 'v3 manifest declaring block-attribute-values/v1 and block-value-contracts/v1; no option, metadata or site-policy transport',
        ];
    }

    public static function group_grammar(): array {
        return [
            'field' => 'block_values.groups', 'shape' => 'list of closed {blocks, attributes, value} groups',
            'blocks' => 'nonempty list of distinct exact block names',
            'attributes' => 'nonempty list of distinct exact top-level attribute names',
            'value' => self::section_grammar(),
            'expansion' => 'one value rule for each exact block/attribute pair; duplicate pairs refuse, including equal rules',
            'max_groups' => self::MAX_GROUPS, 'max_members_per_list' => self::MAX_GROUP_MEMBERS,
            'max_expanded_group_values' => self::MAX_GROUP_VALUES,
            'authority' => 'v3 manifest declaring both block-attribute-values/v1 and block-attribute-groups/v1',
        ];
    }

    /**
     * Normalize declaration syntax at the grammar owner. The manifest itself
     * stays unchanged for identity hashing. Every reader, including derivative
     * ownership and keyspace validation, consumes this same exact field map.
     * No glob, inheritance order or runtime schema discovery grants new fields.
     */
    public static function attribute_maps(array $manifest): array {
        $values = $manifest[self::SECTION] ?? [];
        if (!is_array($values)) throw new \RuntimeException('wprism: block_values must be a non-empty object keyed by block name');
        if (!array_key_exists(self::GROUP_FIELD, $values)) return $values;
        if (($manifest['spec_version'] ?? 0) < 3
            || !in_array(self::FEATURE, (array) ($manifest['engine_features'] ?? []), true)
            || !in_array(self::GROUP_FEATURE, (array) ($manifest['engine_features'] ?? []), true)) {
            throw new \RuntimeException('wprism: block value groups require a v3 manifest declaring both block value features');
        }
        $groups = $values[self::GROUP_FIELD];
        unset($values[self::GROUP_FIELD]);
        if (!is_array($groups) || !array_is_list($groups) || $groups === [] || count($groups) > self::MAX_GROUPS) {
            throw new \RuntimeException('wprism: block_values.groups requires a bounded nonempty list');
        }
        if ($values !== [] && array_is_list($values)) throw new \RuntimeException('wprism: block_values requires an exact block map');
        foreach ($values as $attributes) {
            if (!is_array($attributes) || $attributes === [] || array_is_list($attributes)) {
                throw new \RuntimeException('wprism: block_values requires nonempty exact attribute maps beside groups');
            }
        }
        $count = 0;
        foreach ($groups as $group) {
            if (!is_array($group) || count($group) !== 3 || array_diff_key($group, ['blocks' => true, 'attributes' => true, 'value' => true])
                || !is_array($group['value'] ?? null) || array_is_list($group['value'])) {
                throw new \RuntimeException('wprism: block value groups require closed {blocks, attributes, value} objects');
            }
            foreach (['blocks', 'attributes'] as $field) {
                $members = $group[$field] ?? null;
                if (!is_array($members) || !array_is_list($members) || $members === [] || count($members) > self::MAX_GROUP_MEMBERS) {
                    throw new \RuntimeException("wprism: block value group $field requires a bounded nonempty exact-name list");
                }
                $seen = [];
                foreach ($members as $member) {
                    $pattern = $field === 'blocks' ? '/^[a-z][a-z0-9_-]*\/[a-z][a-z0-9_-]*$/D' : '/^[A-Za-z_][A-Za-z0-9_-]*$/D';
                    if (!is_string($member) || preg_match($pattern, $member) !== 1 || isset($seen[$member])) {
                        throw new \RuntimeException("wprism: block value group $field requires distinct exact names");
                    }
                    $seen[$member] = true;
                }
            }
            $count += count($group['blocks']) * count($group['attributes']);
            if ($count > self::MAX_GROUP_VALUES) throw new \RuntimeException('wprism: block value groups exceed their expanded field bound');
            foreach ($group['blocks'] as $block) {
                $values[$block] ??= [];
                if (!is_array($values[$block]) || ($values[$block] !== [] && array_is_list($values[$block]))) {
                    throw new \RuntimeException('wprism: block_values requires exact attribute maps');
                }
                foreach ($group['attributes'] as $attribute) {
                    if (array_key_exists($attribute, $values[$block])) {
                        throw new \RuntimeException("wprism: block value groups duplicate '$block.$attribute'; every field requires one declaration");
                    }
                    $values[$block][$attribute] = $group['value'];
                }
            }
        }
        ksort($values, SORT_STRING);
        foreach ($values as &$attributes) {
            if (!is_array($attributes)) throw new \RuntimeException('wprism: block_values requires exact attribute maps');
            ksort($attributes, SORT_STRING);
        }
        unset($attributes);
        return $values;
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
            foreach (self::attribute_maps($manifest) as $block => $attributes) {
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
