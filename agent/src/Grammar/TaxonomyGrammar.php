<?php
namespace Duo;

/**
 * The pure taxonomy declaration grammar extracted from `Policy.php`
 * (DUO-3348 slice 9). `object_keyspace` is a structural taxonomy claim, not
 * a runtime hint: malformed values must be refused while the manifest loads,
 * before any capture/lint/apply path can reinterpret a relationship row's
 * shared numeric object_id. Dynamic taxonomy patterns use the same declaration
 * and their regex must be usable before a future concrete taxonomy name reaches
 * the resolver.
 *
 * The implementation is deliberately independent of Policy and WordPress.
 * Policy::taxonomy_object_keyspace() remains on Policy because it is the live
 * runtime resolver used by Capture, Apply, Lint, and RepositoryCompiler; only
 * the manifest-byte declaration grammar is moved here. Policy's load() and
 * from_snapshot() call this class directly, so no compatibility facade or
 * duplicated validator remains on Policy. Taxonomy declarations that derive
 * their object type from an option sub-key belong to this same collaborator:
 * the validator checks only the manifest's cross-declaration shape and never
 * reaches into a live option row or plugin code.
 */
final class TaxonomyGrammar {
    /** Object keyspaces supported by the canonical taxonomy relationship contract. */
    private const TAXONOMY_RELATIONSHIP_OBJECTS = ['post', 'term'];

    /** Validate object_keyspace declarations on exact and pattern taxonomies. */
    public static function validate_taxonomy_object_keyspace_declarations(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ((array) ($manifest['taxonomies'] ?? []) as $tax => $rule) {
            if (!is_array($rule)) {
                continue;
            }
            self::validate_taxonomy_registration_declaration($rule, "manifest '$name' taxonomies.$tax");
            if (!array_key_exists('object_keyspace', $rule)) {
                continue;
            }
            self::validate_taxonomy_object_keyspace_value(
                $rule['object_keyspace'],
                "manifest '$name' taxonomies.$tax.object_keyspace"
            );
        }

        if (!array_key_exists('taxonomy_patterns', $manifest)) {
            return;
        }
        $patterns = $manifest['taxonomy_patterns'];
        if (!is_array($patterns) || !array_is_list($patterns)) {
            throw new \RuntimeException("duo: manifest '$name' declares taxonomy_patterns that is not a list");
        }
        foreach ($patterns as $i => $pattern) {
            if (!is_array($pattern) || array_is_list($pattern)) {
                throw new \RuntimeException("duo: manifest '$name' declares taxonomy_patterns[$i] that is not an object");
            }
            $match = $pattern['match'] ?? null;
            if (!is_string($match) || $match === '' || @preg_match('/' . $match . '/', '') === false) {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares taxonomy_patterns[$i].match with an invalid or empty regex"
                );
            }
            if (array_key_exists('object_keyspace', $pattern)) {
                self::validate_taxonomy_object_keyspace_value(
                    $pattern['object_keyspace'],
                    "manifest '$name' taxonomy_patterns[$i].object_keyspace"
                );
            }
        }
    }

    /**
     * Validate a taxonomy's option-backed object-type declaration.
     *
     * The option and sub-key are intentionally checked only when the same
     * manifest declares the option's sub_keys map. A cross-manifest owner is a
     * valid declaration boundary, so this grammar must not invent a global
     * ownership rule from one manifest's local view.
     */
    public static function validate_object_type_option_refs(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ($manifest['taxonomies'] ?? [] as $tax => $rule) {
            $decl = $rule['object_type_from_option'] ?? null;
            if ($decl === null) {
                continue;
            }
            if (!is_array($decl)
                || !is_string($decl['option'] ?? null) || $decl['option'] === ''
                || !is_string($decl['sub_key'] ?? null) || $decl['sub_key'] === '') {
                throw new \RuntimeException(
                    "duo: manifest '$name' declares taxonomies.$tax.object_type_from_option without both a "
                    . 'non-empty string `option` and `sub_key`'
                );
            }
            $ownSubKeys = $manifest['options'][$decl['option']]['sub_keys'] ?? null;
            if ($ownSubKeys !== null) {
                $subRule = $ownSubKeys[$decl['sub_key']] ?? null;
                if ($subRule === null) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' declares taxonomies.$tax.object_type_from_option.sub_key="
                        . var_export($decl['sub_key'], true) . " but options.{$decl['option']}.sub_keys never "
                        . 'declares that key'
                    );
                }
                if (!empty($subRule['json_refs']) || !empty($subRule['key_refs'])) {
                    throw new \RuntimeException(
                        "duo: manifest '$name' declares taxonomies.$tax.object_type_from_option pointing at "
                        . "options.{$decl['option']}.sub_keys.{$decl['sub_key']}, but that sub-key declares "
                        . 'json_refs/key_refs — object_type_from_option only supports plain, non-ref-typed '
                        . 'sub-key values (post-type/taxonomy slugs, never ids)'
                    );
                }
            }
        }
    }

    /**
     * `taxonomies.<tax>.object_type` / `.update_count_callback` — the exact
     * registration facts `TaxonomyPatternResolver::declaredRegistration()`
     * serves (the plugin's own `register_taxonomy()` arguments, needed where
     * the plugin is not loaded). Same shape a `taxonomy_patterns` entry uses:
     * a non-empty list of non-empty object-type strings, and a non-empty
     * callback name when present. `update_count_callback` without
     * `object_type` is refused: a count contract for a taxonomy whose
     * relationships cannot be attributed is a contract about nothing.
     */
    private static function validate_taxonomy_registration_declaration(array $rule, string $where): void {
        $hasObjectType = array_key_exists('object_type', $rule);
        if ($hasObjectType) {
            $types = $rule['object_type'];
            if (!is_array($types) || !array_is_list($types) || $types === []) {
                throw new \RuntimeException("duo: $where.object_type must be a non-empty list of object type names");
            }
            foreach ($types as $type) {
                if (!is_string($type) || $type === '') {
                    throw new \RuntimeException("duo: $where.object_type must contain only non-empty strings");
                }
            }
        }
        if (array_key_exists('update_count_callback', $rule)) {
            if (!$hasObjectType) {
                throw new \RuntimeException(
                    "duo: $where.update_count_callback requires an object_type declaration beside it"
                );
            }
            $callback = $rule['update_count_callback'];
            if (!is_string($callback) || $callback === '') {
                throw new \RuntimeException("duo: $where.update_count_callback must be a non-empty callback name");
            }
        }
    }

    private static function validate_taxonomy_object_keyspace_value(mixed $value, string $where): void {
        if (!is_string($value) || !in_array($value, self::TAXONOMY_RELATIONSHIP_OBJECTS, true)) {
            throw new \RuntimeException(
                "duo: $where must be one of " . implode('|', self::TAXONOMY_RELATIONSHIP_OBJECTS)
                . '; no other relationship object keyspace is supported'
            );
        }
    }
}
