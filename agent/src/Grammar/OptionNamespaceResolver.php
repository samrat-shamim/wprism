<?php
namespace Duo;

/**
 * Pure discovery ownership lookup for manifest-declared option namespaces.
 *
 * A namespace authorizes discovery only; classification remains Policy's
 * separate rule/interpreter contract. Overlaps refuse rather than selecting
 * by manifest order because ownership is load-bearing authorization evidence.
 */
final class OptionNamespaceResolver {
    /** @param list<array<string,mixed>> $manifests */
    public function __construct(private array $manifests) {}

    /** @return ?array{owner:string, match:string} */
    public function owner_for(string $name): ?array {
        $matches = [];
        foreach ($this->manifests as $manifest) {
            foreach ($manifest['option_namespaces'] ?? [] as $declaration) {
                if (preg_match('/' . $declaration['match'] . '/', $name)) {
                    $matches[] = [
                        'owner' => (string) ($manifest['name'] ?? '?'),
                        'match' => (string) $declaration['match'],
                    ];
                }
            }
        }
        if (count($matches) > 1) {
            throw new \RuntimeException(
                "duo: option '$name' is claimed by overlapping namespaces from "
                . implode(', ', array_map(fn($match) => $match['owner'], $matches))
                . ' — discovery ownership must not depend on manifest load order'
            );
        }
        return $matches[0] ?? null;
    }
}
