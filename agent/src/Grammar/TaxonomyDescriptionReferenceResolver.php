<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/ReferenceRules.php';

/**
 * Pure resolution of a taxonomy description's declared reference grammar.
 *
 * This intentionally reads only pinned manifest order. Policy retains the
 * public compatibility facades and every live caller continues to ask Policy;
 * no site override, WordPress query, or compiled-artifact state belongs here.
 */
final class TaxonomyDescriptionReferenceResolver {
    /** @param list<array<string,mixed>> $manifests */
    public function __construct(private array $manifests) {}

    /** @return ?array{json_refs:array,key_refs:?array,legacy_flat_map:bool} */
    public function resolve(string $tax): ?array {
        foreach ($this->manifests as $m) {
            if (isset($m['taxonomies'][$tax]['description_refs'])) {
                return ReferenceRules::description(
                    $m['taxonomies'][$tax]['description_refs'],
                    "manifest '" . ($m['name'] ?? '?') . "'.taxonomies.$tax.description_refs"
                );
            }
        }
        return null;
    }
}
