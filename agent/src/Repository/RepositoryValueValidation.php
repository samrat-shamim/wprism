<?php
declare(strict_types=1);

namespace WPrism;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/../Kernel/NativeValueValidation.php';
require_once __DIR__ . '/RepositoryMediaDerivatives.php';
if (!class_exists(Policy::class, false)) {
    require_once __DIR__ . '/../Policy/Policy.php';
}

/**
 * Native predicates over a complete immutable metadata tree. The compiler
 * checks portable shapes; Capture and target planning explicitly invoke this
 * separate native boundary before publication or mutation. The two public
 * entry points prevent a runtime predicate from hiding in compiler dispatch.
 */
final class RepositoryValueValidation {
    public static function assert_portable_tree(array $tree, Policy $policy): void {
        self::assert_tree($tree, $policy, false);
    }

    public static function assert_native_tree(array $tree, Policy $policy): void {
        self::assert_tree($tree, $policy, true);
    }

    private static function assert_tree(array $tree, Policy $policy, bool $native): void {
        RepositoryMediaDerivatives::derive($tree, $policy);
        foreach ($tree as $entity) {
            $type = $entity['type'] ?? null;
            $hook = match ($type) {
                'post' => 'meta_rule_for_post', 'term' => 'meta_rule_for_term',
                'user-meta' => 'meta_rule_for_user', default => null,
            };
            if ($hook === null) continue;
            $front = $entity['data'] ?? null;
            if (!is_array($front)) {
                $front = $type === 'post' ? Canon::parse_post_file($entity['content'])[0] : Canon::decode($entity['content']);
            }
            $meta = (array)($front['meta'] ?? []);
            foreach ($meta as $key => $value) {
                $rule = $policy->$hook((string)$key, $meta) ?? [];
                if (!array_key_exists(NativeValueValidation::FIELD, $rule)) continue;
                $values = array_key_exists('repeated_rows', $rule) ? $value : [$value];
                if (!is_array($values) || !array_is_list($values) || $values === []) throw new \RuntimeException('wprism: native value predicate has a malformed metadata roster');
                foreach ($values as $one) {
                    $where = 'metadata ' . ($entity['path'] ?? $type) . ':' . $key;
                    if ($native) NativeValueValidation::assert_native($one, $rule, $where);
                    else NativeValueValidation::assert_portable($one, $rule, $where);
                }
            }
        }
    }
}
