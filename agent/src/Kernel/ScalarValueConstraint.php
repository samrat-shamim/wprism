<?php
declare(strict_types=1);

namespace WPrism;

/** Portable predicates preserve native scalar types; they never sanitize or execute plugin code. */
final class ScalarValueConstraint {
    public const FEATURE = 'scalar-option-constraints/v1';
    public const FIELD = 'value_constraint';
    public const MAX_ENUM_VALUES = 64;

    public static function declaration_grammar(): array {
        return [
            'field' => self::FIELD,
            'path' => 'options.<name>.sub_keys.<key>.' . self::FIELD,
            'class' => 'authored',
            'alternatives' => ['{type: integer, minimum?: integer, maximum?: integer}', '{enum: nonempty literal list}'],
            'enum' => 'distinct integers, booleans, null or ASCII codes of at most 128 bytes',
            'max_enum_values' => self::MAX_ENUM_VALUES,
            'semantics' => 'strict type and inclusive bounds; no coercion, default or reference interpretation',
            'boundaries' => ['Capture', 'immutable compiler and target Plan', 'Apply incoming values and locked preimages'],
            'authority' => 'static v3 manifest option subkeys; no whole option, pattern, dynamic option, interpreter or authored site override',
        ];
    }

    public static function uses(array $rule): bool {
        if (array_key_exists(self::FIELD, $rule)) return true;
        foreach ((array) ($rule['sub_keys'] ?? []) as $child) {
            if (is_array($child) && self::uses($child)) return true;
        }
        return false;
    }

    public static function assert_rule(array $rule, string $where, bool $allowed): void {
        if (!array_key_exists(self::FIELD, $rule)) return;
        if (!$allowed) self::refuse($where, 'requires a static option subkey in a v3 manifest declaring ' . self::FEATURE);
        if (($rule['class'] ?? null) !== 'authored') self::refuse($where, 'requires class authored');
        foreach (['ref', 'cast', 'json_refs', 'key_refs', 'json_encoded', 'plain_data', 'php_containers', 'record_fields',
            'sub_keys', 'repeated_rows', 'order_preserving', 'native_value_validation', 'text_encoding',
            'object_fields', 'enum', 'on_unmapped', 'one_of', 'scalar_type', 'ref_same_local_id_as', 'ref_taxonomy'] as $field) {
            if (array_key_exists($field, $rule)) self::refuse($where, "cannot combine with $field");
        }
        $constraint = $rule[self::FIELD];
        if (!is_array($constraint) || array_is_list($constraint)) self::refuse($where, 'requires a closed predicate object');
        if (array_key_exists('enum', $constraint)) {
            if (array_keys($constraint) !== ['enum']) self::refuse($where, 'enum cannot combine with another predicate');
            self::assert_literal_values($constraint['enum'], "$where." . self::FIELD);
            return;
        }
        if (($constraint['type'] ?? null) !== 'integer' || array_diff_key($constraint, ['type' => true, 'minimum' => true, 'maximum' => true])) {
            self::refuse($where, 'requires type integer with optional inclusive minimum and maximum');
        }
        foreach (['minimum', 'maximum'] as $bound) {
            if (array_key_exists($bound, $constraint) && !is_int($constraint[$bound])) self::refuse($where, "$bound must be an integer");
        }
        if (isset($constraint['minimum'], $constraint['maximum']) && $constraint['minimum'] > $constraint['maximum']) {
            self::refuse($where, 'minimum exceeds maximum');
        }
    }

    /** Shared with block/column value contracts so literal codes have one grammar. */
    public static function assert_literal_values(mixed $values, string $where): void {
        if (!is_array($values) || !array_is_list($values) || $values === [] || count($values) > self::MAX_ENUM_VALUES) {
            throw new \RuntimeException("wprism: $where.enum requires a bounded nonempty literal list and no other codec");
        }
        $seen = [];
        foreach ($values as $value) {
            if (!(is_int($value) || is_bool($value) || $value === null
                || (is_string($value) && preg_match('/^[A-Za-z0-9_-]{0,128}$/D', $value) === 1))
                || in_array($value, $seen, true)) {
                throw new \RuntimeException("wprism: $where.enum requires distinct integers, booleans, null or bounded ASCII codes");
            }
            $seen[] = $value;
        }
    }

    /** Refusals name the declaration, never the potentially private value. */
    public static function assert_value(mixed $value, array $rule, string $where): void {
        if (!array_key_exists(self::FIELD, $rule)) return;
        self::assert_rule($rule, $where, true);
        $constraint = $rule[self::FIELD];
        if (isset($constraint['enum'])) {
            if (!in_array($value, $constraint['enum'], true)) self::refuse($where, 'requires a declared literal value in its exact scalar type');
            return;
        }
        if (!is_int($value) || (isset($constraint['minimum']) && $value < $constraint['minimum'])
            || (isset($constraint['maximum']) && $value > $constraint['maximum'])) {
            self::refuse($where, 'requires an integer within its declared inclusive bounds');
        }
    }

    public static function assert_site_override(array $declared, array $override, string $where): void {
        if (!self::uses($declared)) return;
        if (in_array($override['class'] ?? null, ['runtime', 'derived', 'env'], true)
            && !self::uses($override) && !array_key_exists('sub_keys', $override)) return;
        self::refuse($where, 'cannot replace a manifest predicate with authored site policy; exclude the whole option or retain its manifest contract');
    }

    private static function refuse(string $where, string $why): never {
        throw new \RuntimeException("wprism: $where scalar value constraint $why");
    }
}
