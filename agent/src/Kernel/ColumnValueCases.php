<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/TableRowScope.php';
require_once __DIR__ . '/ValueContractGrammar.php';

/** A row discriminator selects meaning, while the column owner retains framing. */
final class ColumnValueCases {
    public const FEATURE = 'column-value-cases/v1';
    public const FIELD = 'value_cases';
    public const CODEC_KEYS = ['container', self::FIELD];
    public const SELECTOR_KEYS = ['cases', 'column'];
    public const CASE_KEYS = ['equals', 'value'];

    public static function declaration_grammar(): array {
        return ['codec' => self::CODEC_KEYS, 'selector' => self::SELECTOR_KEYS, 'case' => self::CASE_KEYS,
            'max_cases' => TableRowScope::MAX_ALTERNATIVES,
            'refines' => 'strict column container; cases exactly cover one row_scope set in byte order; authored value contracts share the manifest budget; no default'];
    }

    public static function validate(mixed $selector, array $table, ValueContractGrammar $values, string $where): void {
        self::assert_keys($selector, self::SELECTOR_KEYS, $where);
        TableRowScope::validate($where, $table);
        $column = $selector['column'];
        if (!is_string($column) || !is_array($table['row_scope'][$column] ?? null)) {
            throw new \RuntimeException("wprism: $where.column must name one declared row_scope set");
        }
        $cases = $selector['cases'];
        $scope = $table['row_scope'][$column];
        if (!is_array($cases) || !array_is_list($cases) || count($cases) !== count($scope)) {
            throw new \RuntimeException("wprism: $where.cases must exactly cover the declared row_scope set");
        }
        foreach ($cases as $i => $case) {
            self::assert_keys($case, self::CASE_KEYS, "$where.cases[$i]");
            if ($case['equals'] !== $scope[$i]) {
                throw new \RuntimeException("wprism: $where.cases must exactly cover the declared row_scope set in byte order");
            }
            if (!is_array($case['value']) || ($case['value']['class'] ?? null) !== 'authored') {
                throw new \RuntimeException("wprism: $where.cases[$i].value requires an authored value contract");
            }
            // One grammar instance spans every case and column. An unused arm
            // cannot hide an invalid contract or reset the manifest rule budget.
            $values->validate($case['value'], "$where.cases[$i].value");
        }
    }

    /** The list representation preserves numeric string discriminators in PHP JSON. */
    public static function resolve(array $codec, array $row, string $where): array {
        if (!array_key_exists(self::FIELD, $codec)) return $codec;
        self::assert_keys($codec, self::CODEC_KEYS, $where);
        $selector = $codec[self::FIELD];
        self::assert_keys($selector, self::SELECTOR_KEYS, $where);
        $column = $selector['column'];
        if (is_string($column) && is_string($row[$column] ?? null)) {
            foreach ($selector['cases'] as $case) {
                if ($case['equals'] === $row[$column]) {
                    return ['container' => $codec['container'], 'value' => $case['value']];
                }
            }
        }
        // Never echo a private or foreign discriminator into a diagnostic.
        throw new \RuntimeException("wprism: $where has no declared column value case for this row");
    }

    /** Keyspace validation inspects all declared arms, not only captured rows. */
    public static function contracts(array $codec): array {
        if (isset($codec['value'])) return ['value' => $codec['value']];
        $out = [];
        foreach (($codec[self::FIELD]['cases'] ?? []) as $i => $case) {
            $out[self::FIELD . ".cases[$i].value"] = $case['value'];
        }
        return $out;
    }

    private static function assert_keys(mixed $value, array $expected, string $where): void {
        $keys = is_array($value) ? array_keys($value) : [];
        sort($keys, SORT_STRING);
        if (!is_array($value) || array_is_list($value) || $keys !== $expected) {
            throw new \RuntimeException("wprism: $where requires exactly {" . implode(', ', $expected) . '}');
        }
    }
}
