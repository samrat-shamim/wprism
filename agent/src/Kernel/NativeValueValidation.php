<?php
declare(strict_types=1);

namespace WPrism;

/** Closed native value predicates, explicitly separate from portable compilation. */
final class NativeValueValidation {
    public const FEATURE = 'native-value-validation/v1';
    public const FIELD = 'native_value_validation';
    public const MAX_BYTES = 1048576;
    public const KSES = ['profile' => 'wordpress-kses/v1', 'context' => 'pre_user_description'];

    public static function declaration_grammar(): array {
        return [
            'path' => '{post_meta,term_meta,user_meta}.<name>.' . self::FIELD,
            'class' => 'authored',
            'value' => self::KSES,
            'portable' => ['type' => 'string', 'max_bytes' => self::MAX_BYTES, 'encoding' => 'UTF-8 without unsafe control bytes'],
            'native' => 'exact identity under WordPress KSES in the declared context; unavailable or changed output refuses',
            'boundaries' => ['Capture raw and canonical', 'target Plan canonical preflight', 'Apply resolved values and locked preimages'],
            'authority' => 'v3 manifest metadata and metadata patterns; interpreter answers need one feature-enrolled owner; no site declaration',
        ];
    }

    public static function assert_rule(array $rule, string $where): void {
        if (!array_key_exists(self::FIELD, $rule)) return;
        $profile = $rule[self::FIELD];
        if (!is_array($profile)) throw new \RuntimeException("wprism: $where native value validation must be a profile object");
        ksort($profile, SORT_STRING);
        $expected = self::KSES;
        ksort($expected, SORT_STRING);
        if (($rule['class'] ?? null) !== 'authored' || $profile !== $expected) {
            throw new \RuntimeException("wprism: $where native value validation requires class=authored and the exact wordpress-kses/v1 pre_user_description profile");
        }
    }

    /** Pure shape/size checks remain available to host compilation without WordPress. */
    public static function assert_portable(mixed $value, array $rule, string $where): void {
        self::assert_rule($rule, $where);
        if (!array_key_exists(self::FIELD, $rule)) return;
        if (!is_string($value) || strlen($value) > self::MAX_BYTES || preg_match('//u', $value) !== 1
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) === 1) {
            throw new \RuntimeException("wprism: $where native value profile requires bounded UTF-8 text without unsafe control bytes");
        }
    }

    /** Capture/target preflight/materialization only; absence is never a validation waiver. */
    public static function assert_native(mixed $value, array $rule, string $where): void {
        self::assert_portable($value, $rule, $where);
        if (!array_key_exists(self::FIELD, $rule)) return;
        if (function_exists('wp_kses')) {
            $sanitized = wp_kses($value, self::KSES['context']);
        } else {
            throw new \RuntimeException("wprism: $where cannot prove the native pre_user_description KSES boundary");
        }
        if (!is_string($sanitized) || !hash_equals($value, $sanitized)) {
            throw new \RuntimeException("wprism: $where is not already canonical under the native pre_user_description KSES boundary");
        }
    }

    /** Site policy may exclude an owned value, never silently remove its predicate. */
    public static function assert_site_override(array $declared, array $override, string $where): void {
        if (!array_key_exists(self::FIELD, $declared)) return;
        if (!in_array($override['class'] ?? null, ['runtime', 'derived', 'env'], true)
            || array_key_exists(self::FIELD, $override)) {
            throw new \RuntimeException("wprism: $where cannot replace a manifest native value predicate; retain its declaration or exclude the value");
        }
    }

    public static function assert_same_predicate(array $before, array $after, string $where): void {
        self::assert_rule($before, $where);
        self::assert_rule($after, $where);
        if (array_key_exists(self::FIELD, $before) !== array_key_exists(self::FIELD, $after)) {
            throw new \RuntimeException("wprism: $where cannot remove or add a native value predicate in the locked target context");
        }
    }
}
