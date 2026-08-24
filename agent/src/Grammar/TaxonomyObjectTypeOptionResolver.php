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
        $declarations = $this->resolve_all($tax);
        if ($declarations === null) {
            return null;
        }
        return [
            'option' => $declarations[0]['option'],
            'sub_key' => $declarations[0]['sub_key'],
        ];
    }

    /**
     * Resolve every compiled-option contribution for one taxonomy.
     *
     * The original object shape remains a one-row shorthand. A list adds
     * independent contributions, including a boolean setting whose true/1
     * state enables fixed object types. Policy grammar validates the raw
     * shape before this pure projection is reachable.
     *
     * @return ?list<array{option:string, sub_key:string, object_types_when_truthy?:list<string>}>
     */
    public function resolve_all(string $tax): ?array {
        foreach ($this->manifests as $manifest) {
            $declaration = $manifest['taxonomies'][$tax]['object_type_from_option'] ?? null;
            if ($declaration !== null) {
                $rows = array_is_list($declaration) ? $declaration : [$declaration];
                return array_map(static function (array $row): array {
                    $resolved = [
                        'option' => (string) $row['option'],
                        'sub_key' => (string) $row['sub_key'],
                    ];
                    if (array_key_exists('object_types_when_truthy', $row)) {
                        $resolved['object_types_when_truthy'] = array_values(array_map(
                            'strval',
                            $row['object_types_when_truthy']
                        ));
                    }
                    return $resolved;
                }, $rows);
            }
        }
        return null;
    }
}
