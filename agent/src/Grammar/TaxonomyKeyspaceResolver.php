<?php
namespace WPrism;

require_once __DIR__ . '/TaxonomyPatternResolver.php';

/**
 * Pure resolution of a taxonomy relationship row's post or term keyspace.
 *
 * Exact taxonomy declarations compose with one concrete taxonomy-pattern
 * contract. Live taxonomy discovery and option-derived object-type expansion
 * stay in Policy/its callers; this resolver accepts only those runtime types
 * as supplied facts and refuses a declaration that cannot describe them.
 */
final class TaxonomyKeyspaceResolver {
    /** @param list<array<string,mixed>> $manifests */
    public function __construct(
        private array $manifests,
        private TaxonomyPatternResolver $patterns
    ) {}

    /** @return "post"|"term" */
    public function resolve(string $tax, ?array $runtimeObjectTypes = null): string {
        /** @var array<string,string[]> $declared value => declaration locations */
        $declared = [];
        foreach ($this->manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '?');
            if (array_key_exists($tax, (array) ($manifest['taxonomies'] ?? []))) {
                $rule = $manifest['taxonomies'][$tax];
                if (is_array($rule)) {
                    $value = array_key_exists('object_keyspace', $rule)
                        ? (string) $rule['object_keyspace']
                        : 'post';
                    $source = "manifest '$name' taxonomies.$tax";
                    $declared[$value][] = array_key_exists('object_keyspace', $rule)
                        ? $source . '.object_keyspace'
                        : $source . ' (legacy post default)';
                }
            }
        }
        $pattern = $this->patterns->match($tax);
        if ($pattern !== null) {
            $declared[$pattern['object_keyspace']][] = $pattern['source'] . '.object_keyspace';
        }
        if ($declared === []) {
            if ($runtimeObjectTypes !== null && in_array('term', $runtimeObjectTypes, true)) {
                throw new \RuntimeException(
                    "wprism: taxonomy '$tax' has runtime object_type containing 'term' but no manifest "
                    . 'object_keyspace declaration — term or mixed relationship ownership must declare '
                    . 'object_keyspace="term" or object_keyspace="post" explicitly'
                );
            }
            return 'post'; // explicit compatibility default for pre-issue #3316 manifests
        }
        if (count($declared) !== 1) {
            $claims = [];
            foreach ($declared as $value => $sources) {
                $claims[] = "$value from " . implode(', ', $sources);
            }
            throw new \RuntimeException(
                "wprism: taxonomy '$tax' has ambiguous object_keyspace declarations ("
                . implode('; ', $claims) . ') — every exact or matching pattern declaration must agree'
            );
        }
        $resolved = (string) array_key_first($declared);
        if ($runtimeObjectTypes !== null) {
            $runtimeObjectTypes = array_values(array_unique(array_map('strval', $runtimeObjectTypes)));
            $hasTermSentinel = in_array('term', $runtimeObjectTypes, true);
            if ($hasTermSentinel && count($runtimeObjectTypes) > 1) {
                throw new \RuntimeException(
                    "wprism: taxonomy '$tax' is registered with mixed runtime object_type values ("
                    . implode(', ', $runtimeObjectTypes) . '); one object_keyspace declaration cannot safely '
                    . 'describe both post- and term-owned relationship rows'
                );
            }
            if ($hasTermSentinel && $resolved !== 'term') {
                throw new \RuntimeException(
                    "wprism: taxonomy '$tax' declares object_keyspace='$resolved' but its runtime object_type "
                    . "contains 'term' — declaration/runtime relationship ownership contradicts"
                );
            }
        }
        return $resolved;
    }
}
