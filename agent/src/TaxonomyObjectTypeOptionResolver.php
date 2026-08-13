<?php
namespace Duo;

/**
 * Pure resolution of a taxonomy's option-derived object-type declaration.
 *
 * This reads only pinned manifest order. Policy retains the public facade,
 * while Apply owns reading the current compiled option state at apply time.
 */
final class TaxonomyObjectTypeOptionResolver {
    /** @param list<array<string,mixed>> $manifests */
    public function __construct(private array $manifests) {}

    /** @return ?array{option:string, sub_key:string} */
    public function resolve(string $tax): ?array {
        foreach ($this->manifests as $manifest) {
            $declaration = $manifest['taxonomies'][$tax]['object_type_from_option'] ?? null;
            if ($declaration !== null) {
                return [
                    'option' => (string) $declaration['option'],
                    'sub_key' => (string) $declaration['sub_key'],
                ];
            }
        }
        return null;
    }
}
