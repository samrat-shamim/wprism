<?php
declare(strict_types=1);

namespace WPrism;

/** Exact derived-cache repair inside an already selected authored post write. */
final class PostMetaInvalidation {
    public const FEATURE = 'post-meta-invalidation/v1';
    public const FIELD = 'on_post_write';

    public static function declaration_grammar(): array {
        return [
            'path' => 'post_meta.<exact-name>.' . self::FIELD,
            'class' => 'derived',
            'value' => 'delete',
            'optional_fields' => ['note'],
            'authority' => 'one v3 static manifest declaring the feature; no competing core, adapter, site or interpreter rule',
            'effect' => 'delete every exact-key row on the post or menu item being materialized, under its existing metadata owner lock and authored SQL transaction',
            'boundaries' => 'no owner discovery, patterns, other metadata surfaces, native callbacks or cache population; ordinary derived rules preserve target rows',
        ];
    }

    public static function assert_rule(array $rule, string $where, bool $allowed = false): void {
        if (!array_key_exists(self::FIELD, $rule)) return;
        if (!$allowed) {
            throw new \RuntimeException("wprism: $where.on_post_write belongs only to exact post_meta in a v3 adapter declaring " . self::FEATURE);
        }
        if (($rule['class'] ?? null) !== 'derived' || $rule[self::FIELD] !== 'delete'
            || array_diff_key($rule, ['class' => true, self::FIELD => true, 'note' => true])
            || (array_key_exists('note', $rule) && !is_string($rule['note']))) {
            throw new \RuntimeException("wprism: $where.on_post_write requires exactly class=derived, on_post_write=delete and an optional note string");
        }
    }

    /** Interpreter option sub-rules cannot hide a post-only write grant. */
    public static function uses(array $rule): bool {
        if (array_key_exists(self::FIELD, $rule)) return true;
        foreach ((array) ($rule['sub_keys'] ?? []) as $subRule) {
            if (is_array($subRule) && self::uses($subRule)) return true;
        }
        return false;
    }

    public static function deletes(array $rule, string $where): bool {
        self::assert_rule($rule, $where, true);
        return array_key_exists(self::FIELD, $rule);
    }

    /** @return list<string> Exact static grants, including currently absent caches. */
    public static function keys(array $manifests): array {
        $keys = [];
        foreach ($manifests as $manifest) {
            foreach ($manifest['post_meta'] ?? [] as $key => $rule) {
                if (self::uses($rule)) $keys[(string) $key] = true;
            }
        }
        $names = array_map('strval', array_keys($keys));
        sort($names, SORT_STRING);
        return $names;
    }

    public static function assert_site_override(array $declared, string $where): void {
        if (self::uses($declared)) {
            throw new \RuntimeException("wprism: $where cannot replace a manifest post-meta invalidation contract");
        }
    }
}
