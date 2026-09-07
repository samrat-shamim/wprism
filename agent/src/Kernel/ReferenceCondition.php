<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/ReferencePath.php';
require_once __DIR__ . '/IdentityTokenCodec.php';

/** A closed sibling discriminator for an otherwise ordinary structural ref. */
final class ReferenceCondition {
    public const FEATURE = 'conditional-json-refs/v1';

    public static function declaration_grammar(): array {
        return [
            'path' => 'structured option/metadata json_refs[].when',
            'required' => ['key', 'equals', 'otherwise'],
            'key' => 'one exact sibling key; cannot itself be a declared reference',
            'equals' => 'one nonempty string discriminator, at most 128 bytes',
            'otherwise' => 'one to sixteen distinct nonempty string discriminators, at most 128 bytes each',
            'unknown' => 'missing, non-string and unlisted discriminators refuse',
            'authority' => 'v3 adapter declaring conditional-json-refs/v1; body refs are not admitted',
        ];
    }

    public static function uses(array $rule): bool {
        foreach ((array) ($rule['json_refs'] ?? []) as $ref) {
            if (is_array($ref) && array_key_exists('when', $ref)) return true;
        }
        foreach ((array) ($rule['sub_keys'] ?? []) as $subRule) {
            if (is_array($subRule) && self::uses($subRule)) return true;
        }
        return false;
    }

    public static function assert_rule(array $ref, string $where): void {
        if (!array_key_exists('when', $ref)) return;
        $when = $ref['when'];
        if (!is_array($when) || array_is_list($when) || count($when) !== 3
            || array_diff_key($when, ['key' => true, 'equals' => true, 'otherwise' => true])
            || !is_string($when['key'] ?? null) || preg_match('/^[A-Za-z0-9_-]+$/D', $when['key']) !== 1
            || strlen($when['key']) > 128 || !self::discriminator($when['equals'] ?? null)
            || !is_array($when['otherwise'] ?? null) || !array_is_list($when['otherwise'])
            || count($when['otherwise']) < 1 || count($when['otherwise']) > 16) {
            throw new \RuntimeException("wprism: $where.when requires a bounded {key, equals, otherwise} sibling discriminator");
        }
        $seen = [$when['equals']];
        foreach ($when['otherwise'] as $value) {
            if (!self::discriminator($value) || in_array($value, $seen, true)) {
                throw new \RuntimeException("wprism: $where.when discriminators must be distinct bounded strings");
            }
            $seen[] = $value;
        }
        $segments = ReferencePath::parse($ref['path']);
        $last = $segments === [] ? null : $segments[count($segments) - 1];
        if ($last === null || !in_array($last['type'], ['child', 'desc'], true) || $last['key'] === $when['key']) {
            throw new \RuntimeException("wprism: $where.when requires an exact terminal reference key distinct from its discriminator");
        }
    }

    private static function discriminator(mixed $value): bool {
        return is_string($value) && $value !== '' && strlen($value) <= 128
            && preg_match('//u', $value) === 1 && preg_match('/[\x00-\x1f\x7f]/', $value) !== 1;
    }

    /** Unknown variants must not fall through to untyped portable content. */
    public static function matches(array $container, array $ref, string $where): bool {
        if (!array_key_exists('when', $ref)) return true;
        self::assert_rule($ref, $where);
        $when = $ref['when'];
        $value = $container[$when['key']] ?? null;
        if ($value === $when['equals']) return true;
        if (is_string($value) && in_array($value, $when['otherwise'], true)) return false;
        throw new \RuntimeException("wprism: $where has a missing or unreviewed structural reference discriminator");
    }

    /** New conditional declarations cannot coerce malformed selected values. */
    public static function assert_native(mixed $value, array $ref, string $where): void {
        if (!array_key_exists('when', $ref) || self::unset_value($value)) return;
        if (is_int($value) && $value > 0) return;
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1
            && (string) (int) $value === $value) return;
        throw new \RuntimeException("wprism: $where conditional reference requires a canonical positive integer or unset value");
    }

    public static function assert_canonical(mixed $value, array $ref, string $where): void {
        if (!array_key_exists('when', $ref) || self::unset_value($value)) return;
        if (is_string($value)) {
            try {
                $decoded = IdentityTokenCodec::decode($value);
                if ($decoded['kind'] === $ref['kind']) return;
            } catch (\RuntimeException) {
                // The public diagnostic never repeats supplied token bytes.
            }
        }
        throw new \RuntimeException("wprism: $where conditional reference requires a token in its declared keyspace");
    }

    private static function unset_value(mixed $value): bool {
        return in_array($value, [null, '', 0, '0', false], true);
    }
}
