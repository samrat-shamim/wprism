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
     * @return array<int, array{match:string, object_type:string[], update_count_callback:?string, object_keyspace:string, source:string}>
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
     * @return ?array{match:string,object_type:string[],update_count_callback:?string,object_keyspace:string,source:string}
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
            foreach (['object_type', 'update_count_callback', 'object_keyspace'] as $field) {
                if ($effective[$field] != $pattern[$field]) {
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

    /** taxonomy_patterns stores an undelimited PCRE fragment by contract. */
    public static function matches(string $match, string $tax): bool {
        return @preg_match('/' . $match . '/', $tax) === 1;
    }
}
