<?php
declare(strict_types=1);

namespace WPrism;

/** Shape predicates refine reviewed codecs; selection never tries or executes a fallback codec. */
final class ValueShapeContract {
    public const FEATURE = 'block-value-shapes/v1';
    public const ALTERNATIVES_FIELD = 'one_of';
    public const SCALAR_FIELD = 'scalar_type';

    public static function declaration_grammar(): array {
        return [
            'scalar_type' => 'string, number or boolean; exactly class:authored and plain_data:true; strict native/canonical type without coercion',
            'one_of' => 'exactly two authored rules: one enum and one object_fields; recursive existing codecs and budgets; no other parent codec',
            'selection' => 'JSON arrays select the object rule, whose existing nonempty exact-object check still applies; other JSON values select the literal enum',
            'clearance' => 'selected nested references retain their ordinary role; literal siblings retain privacy and secret clearance',
            'authority' => 'v3 block_values declaring block-attribute-values/v1, block-value-contracts/v1 and block-value-shapes/v1; no site, option or column override',
        ];
    }

    public static function assert_negotiated(array $rule, string $where, bool $allowed): void {
        if ((array_key_exists(self::SCALAR_FIELD, $rule) || array_key_exists(self::ALTERNATIVES_FIELD, $rule)) && !$allowed) {
            throw new \RuntimeException("wprism: $where requires negotiated " . self::FEATURE);
        }
    }

    public static function uses(array $rule): bool {
        if (array_key_exists(self::SCALAR_FIELD, $rule) || array_key_exists(self::ALTERNATIVES_FIELD, $rule)) return true;
        foreach ($rule['object_fields'] ?? [] as $child) if (self::uses($child)) return true;
        return false;
    }

    public static function assert_scalar_rule(array $rule, string $where): void {
        if (count($rule) !== 3 || ($rule['class'] ?? null) !== 'authored' || ($rule['plain_data'] ?? null) !== true
            || !in_array($rule[self::SCALAR_FIELD] ?? null, ['string', 'number', 'boolean'], true)) {
            throw new \RuntimeException("wprism: $where.scalar_type requires one strict authored plain-data scalar codec");
        }
    }

    /** @return array{literal:array,object:array} */
    public static function alternatives(array $rule, string $where): array {
        $cases = $rule[self::ALTERNATIVES_FIELD] ?? null;
        if (count($rule) !== 2 || ($rule['class'] ?? null) !== 'authored' || !is_array($cases)
            || !array_is_list($cases) || count($cases) !== 2) {
            throw new \RuntimeException("wprism: $where.one_of requires exactly one literal and one object value rule");
        }
        $out = [];
        foreach ($cases as $case) {
            if (!is_array($case) || ($case['class'] ?? null) !== 'authored'
                || array_key_exists('enum', $case) === array_key_exists('object_fields', $case)) {
                throw new \RuntimeException("wprism: $where.one_of requires exactly one literal and one object value rule");
            }
            $kind = array_key_exists('enum', $case) ? 'literal' : 'object';
            if (isset($out[$kind])) throw new \RuntimeException("wprism: $where.one_of requires disjoint literal and object value rules");
            $out[$kind] = $case;
        }
        return $out;
    }

    public static function select(mixed $value, array $rule, string $where): array {
        return self::alternatives($rule, $where)[is_array($value) ? 'object' : 'literal'];
    }

    public static function assert_scalar_value(mixed $value, array $rule, string $where): void {
        if (!array_key_exists(self::SCALAR_FIELD, $rule)) return;
        self::assert_scalar_rule($rule, $where);
        $valid = match ($rule[self::SCALAR_FIELD]) {
            'string' => is_string($value),
            'number' => is_int($value) || (is_float($value) && is_finite($value)),
            'boolean' => is_bool($value),
        };
        if (!$valid) throw new \RuntimeException("wprism: $where requires its declared strict scalar type");
    }
}
