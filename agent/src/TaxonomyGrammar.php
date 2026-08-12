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
 * duplicated validator remains on Policy.
 */
final class TaxonomyGrammar {
    /** Object keyspaces supported by the canonical taxonomy relationship contract. */
    private const TAXONOMY_RELATIONSHIP_OBJECTS = ['post', 'term'];

    /** Validate object_keyspace declarations on exact and pattern taxonomies. */
    public static function validate_taxonomy_object_keyspace_declarations(array $manifest): void {
        $name = (string) ($manifest['name'] ?? '?');
        foreach ((array) ($manifest['taxonomies'] ?? []) as $tax => $rule) {
            if (!is_array($rule) || !array_key_exists('object_keyspace', $rule)) {
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

    private static function validate_taxonomy_object_keyspace_value(mixed $value, string $where): void {
        if (!is_string($value) || !in_array($value, self::TAXONOMY_RELATIONSHIP_OBJECTS, true)) {
            throw new \RuntimeException(
                "duo: $where must be one of " . implode('|', self::TAXONOMY_RELATIONSHIP_OBJECTS)
                . '; no other relationship object keyspace is supported'
            );
        }
    }
}
