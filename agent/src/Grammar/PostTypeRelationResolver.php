<?php
namespace WPrism;

/**
 * Resolves manifest-declared post-type parent/child relationships.
 *
 * This is deliberately a pure view over already-validated manifest arrays:
 * relationship expansion is generic scope evidence, not a WordPress query,
 * a deletion capability, or permission to infer an undeclared hierarchy.
 * Policy retains its public query facades while ScopeClosure and Apply keep
 * their existing callers and authority boundaries.
 */
final class PostTypeRelationResolver {
    /** @var list<array<string,mixed>> */
    private array $manifests;

    /** @param list<array<string,mixed>> $manifests */
    public function __construct(array $manifests) {
        $this->manifests = $manifests;
    }

    /**
     * Declared direct child post types for a parent CPT. Pinned manifests
     * compose additively, then sort independently of manifest pin order.
     *
     * @return string[]
     */
    public function children(string $postType): array {
        $out = [];
        foreach ($this->manifests as $manifest) {
            $decl = $manifest['post_types'][$postType] ?? null;
            if (!is_array($decl)) {
                continue;
            }
            foreach ((array) ($decl['children'] ?? []) as $childPostType) {
                if (is_string($childPostType) && $childPostType !== '') {
                    $out[$childPostType] = true;
                }
            }
        }
        $types = array_keys($out);
        sort($types, SORT_STRING);
        return $types;
    }

    /**
     * Declared direct parent post types for a child CPT. A concrete row still
     * has one post_parent at a time; this type-level inverse is intentionally
     * plural and never manufactures a relationship.
     *
     * @return string[]
     */
    public function parents(string $postType): array {
        $out = [];
        foreach ($this->manifests as $manifest) {
            foreach ((array) ($manifest['post_types'] ?? []) as $parentPostType => $decl) {
                if (!is_array($decl)
                    || !in_array($postType, (array) ($decl['children'] ?? []), true)) {
                    continue;
                }
                $out[(string) $parentPostType] = true;
            }
        }
        $types = array_keys($out);
        sort($types, SORT_STRING);
        return $types;
    }

    /**
     * Returns the lexical, duplicate-free transitive closure over declared
     * parent/child edges. Unknown input types survive as isolated members;
     * undeclared edges are never inferred.
     *
     * @param array<int,mixed> $postTypes
     * @return string[]
     */
    public function closure(array $postTypes): array {
        $pending = [];
        foreach ($postTypes as $postType) {
            if (is_string($postType) && $postType !== '') {
                $pending[$postType] = true;
            }
        }

        $seen = [];
        while ($pending) {
            ksort($pending, SORT_STRING);
            $postType = (string) array_key_first($pending);
            unset($pending[$postType]);
            if (isset($seen[$postType])) {
                continue;
            }
            $seen[$postType] = true;
            foreach (array_merge($this->children($postType), $this->parents($postType)) as $relatedPostType) {
                if (!isset($seen[$relatedPostType])) {
                    $pending[$relatedPostType] = true;
                }
            }
        }

        $types = array_keys($seen);
        sort($types, SORT_STRING);
        return $types;
    }
}
