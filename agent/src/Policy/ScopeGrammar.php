<?php
namespace WPrism;

/**
 * Pure whole-entity scope declaration grammar.
 *
 * Site policy carries explicit scope dispositions under
 * `policy.scope.{post_type,taxonomy}`; manifests reuse their existing
 * `post_types` and `taxonomies` declarations. This collaborator owns the
 * shape and closed class vocabulary only. Policy remains responsible for
 * invoking it at both live and frozen load boundaries, and for publishing the
 * same vocabulary to the validation/reporting surfaces.
 */
final class ScopeGrammar {
    private const SCOPE_CLASSES = ['authored', 'runtime', 'derived', 'env'];

    /** @return list<string> */
    public static function scopeClasses(): array {
        return self::SCOPE_CLASSES;
    }

    /**
     * Validate whole-entity scope dispositions at policy load time. Site rules
     * live under policy.scope.{post_type,taxonomy}; manifests reuse their
     * existing post_types/taxonomies declarations. Invalid scope input must
     * fail every consumer, never turn into an implicit include or exclusion.
     */
    public static function validate_scope_classes(array $source, string $label, bool $site): void {
        $groups = $site
            ? ($source['policy']['scope'] ?? [])
            : ['post_type' => $source['post_types'] ?? [], 'taxonomy' => $source['taxonomies'] ?? []];
        foreach (['post_type', 'taxonomy'] as $kind) {
            foreach ($groups[$kind] ?? [] as $name => $rule) {
                if (!is_string($name) || $name === '' || !is_array($rule)) {
                    throw new \RuntimeException("wprism: $label has an invalid scope.$kind declaration");
                }
                $class = $rule['class'] ?? ($site ? null : 'authored');
                if (!in_array($class, self::SCOPE_CLASSES, true)) {
                    throw new \RuntimeException(
                        "wprism: $label scope.$kind.$name.class=" . var_export($class, true)
                        . ' (expected ' . implode('|', self::SCOPE_CLASSES) . ')'
                    );
                }
            }
        }
    }
}
