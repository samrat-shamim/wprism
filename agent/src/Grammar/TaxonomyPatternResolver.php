<?php
namespace Duo;

/**
 * Pure manifest-declared taxonomy-pattern normalization and matching.
 *
 * The resolver deliberately owns no WordPress taxonomy discovery: Policy's
 * taxonomies() remains the one live wp_term_taxonomy expansion boundary.
 * This class only makes the already-validated manifest contract available to
 * Policy's public compatibility facades and to its exact-keyspace resolver.
 */
final class TaxonomyPatternResolver {
    /** @param list<array<string,mixed>> $manifests */
    public function __construct(private array $manifests) {}

    /**
     * @return array<int, array{match:string, object_type:string[], update_count_callback:?string, hierarchical:?bool, object_keyspace:string, source:string}>
     */
    public function rules(): array {
        $out = [];
        foreach ($this->manifests as $m) {
            foreach ($m['taxonomy_patterns'] ?? [] as $i => $pat) {
                $objectTypes = array_values(array_unique(array_map(
                    'strval',
                    (array) ($pat['object_type'] ?? [])
                )));
                sort($objectTypes, SORT_STRING);
                $out[] = [
                    'match' => (string) $pat['match'],
                    'object_type' => $objectTypes,
                    'update_count_callback' => isset($pat['update_count_callback'])
                        ? (string) $pat['update_count_callback']
                        : null,
                    'hierarchical' => array_key_exists('hierarchical', $pat)
                        ? (bool) $pat['hierarchical']
                        : null,
                    'object_keyspace' => array_key_exists('object_keyspace', $pat)
                        ? (string) $pat['object_keyspace']
                        : 'post',
                    'source' => "manifest '" . (string) ($m['name'] ?? '?') . "' taxonomy_patterns[$i]",
                ];
            }
        }
        return $out;
    }

    /**
     * Resolve every pattern matching one concrete taxonomy as a single
     * structural contract. Arbitrary PCRE intersection is not decidable at
     * load time, so differently-spelled overlapping patterns are checked at
     * the first concrete name; identical regex conflicts are also rejected
     * eagerly by validate_no_conflicting_taxonomy_object_keyspaces().
     *
     * @return ?array{match:string,object_type:string[],update_count_callback:?string,hierarchical:?bool,object_keyspace:string,source:string}
     */
    public function match(string $tax): ?array {
        $effective = null;
        foreach ($this->rules() as $pattern) {
            if (!self::matches($pattern['match'], $tax)) {
                continue;
            }
            if ($effective === null) {
                $effective = $pattern;
                continue;
            }
            foreach (['object_type', 'update_count_callback', 'hierarchical', 'object_keyspace'] as $field) {
                if ($effective[$field] !== $pattern[$field]) {
                    $ambiguity = $field === 'object_keyspace'
                        ? 'ambiguous object_keyspace declarations'
                        : 'ambiguous taxonomy_patterns contracts';
                    throw new \RuntimeException(
                        "duo: taxonomy '$tax' matches $ambiguity: "
                        . "{$effective['source']} and {$pattern['source']} disagree on $field; "
                        . 'pin order may not choose runtime relationship behavior'
                    );
                }
            }
        }
        return $effective;
    }

    /**
     * The manifest-declared REGISTRATION facts for one exact taxonomy name:
     * `taxonomies.<tax>.object_type` (and optionally `.update_count_callback`),
     * a statement about the plugin's own `register_taxonomy()` call, exactly
     * as a `taxonomy_patterns` entry states them for a dynamic name.
     *
     * Why it exists: `wp duo refresh-export` runs under the isolated control
     * bootstrap (no plugins), where `get_taxonomy('product_cat')` is false
     * and `ScopeDiscovery::taxonomyOwnership()` has no other way to learn
     * which object keyspace a policy-scoped exact taxonomy's relationships
     * belong to — its strict read-only mode refuses and names "a
     * plugin-owned object_type declaration" as the remedy. Patterns cannot
     * carry that for an exact name without also widening scope (a matching
     * pattern pulls live names INTO Policy::taxonomies()), so the exact
     * declaration lives on the `taxonomies.<tax>` rule the manifest already
     * classifies, and changes nothing about scope.
     *
     * Two manifests that both declare registration facts for one taxonomy
     * must agree, for the same reason two matching patterns must.
     *
     * @return ?array{object_type:string[],update_count_callback:?string,hierarchical:?bool,source:string}
     */
    public function declaredRegistration(string $tax): ?array {
        $effective = null;
        foreach ($this->manifests as $m) {
            $rule = $m['taxonomies'][$tax] ?? null;
            if (!is_array($rule) || !array_key_exists('object_type', $rule)) {
                continue;
            }
            $objectTypes = array_values(array_unique(array_map('strval', (array) $rule['object_type'])));
            sort($objectTypes, SORT_STRING);
            $candidate = [
                'object_type' => $objectTypes,
                'update_count_callback' => isset($rule['update_count_callback'])
                    ? (string) $rule['update_count_callback']
                    : null,
                'hierarchical' => array_key_exists('hierarchical', $rule)
                    ? (bool) $rule['hierarchical']
                    : null,
                'source' => "manifest '" . (string) ($m['name'] ?? '?') . "' taxonomies.$tax",
            ];
            if ($effective === null) {
                $effective = $candidate;
                continue;
            }
            foreach (['object_type', 'update_count_callback', 'hierarchical'] as $field) {
                if ($effective[$field] !== $candidate[$field]) {
                    throw new \RuntimeException(
                        "duo: taxonomy '$tax' has ambiguous registration declarations: "
                        . "{$effective['source']} and {$candidate['source']} disagree on $field; "
                        . 'pin order may not choose runtime relationship behavior'
                    );
                }
            }
        }
        return $effective;
    }

    /** taxonomy_patterns stores an undelimited PCRE fragment by contract. */
    public static function matches(string $match, string $tax): bool {
        return @preg_match('/' . $match . '/', $tax) === 1;
    }
}
