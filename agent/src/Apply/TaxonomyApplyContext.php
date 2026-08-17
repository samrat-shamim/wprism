<?php
namespace Duo;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Kernel/OptionState.php';
if (!class_exists(CompiledRepository::class, false)) {
    require_once __DIR__ . '/../Repository/CompiledArtifact.php';
}

/** Resolves and memoizes the taxonomy relationship ownership for one apply. */
final class TaxonomyApplyContext {
    /** @var array{by_post_type:array<string,string[]>,term_object:string[]}|null */
    private ?array $ownership = null;

    public function __construct(
        private readonly Policy $policy,
        private readonly CompiledRepository $compiled
    ) {}

    /**
     * @param string[] $warnings
     * @return array{by_post_type:array<string,string[]>,term_object:string[]}
     */
    public function ownership(array &$warnings): array {
        if ($this->ownership !== null) {
            return $this->ownership;
        }

        $byPostType = [];
        $termObject = [];
        foreach ($this->policy->taxonomies() as $taxonomy) {
            $taxonomyObject = get_taxonomy($taxonomy);
            $objectTypes = $taxonomyObject !== false
                ? (array) $taxonomyObject->object_type
                : $this->policy->pattern_object_type($taxonomy);
            if ($objectTypes === null) {
                $warnings[] = "taxonomy '$taxonomy' is in policy scope but not registered on this environment"
                    . ' (plugin inactive?) — cannot determine which object type its relationships'
                    . ' belong to, so its relationships are skipped for every post and term on apply';
                continue;
            }
            $objectTypes = array_values(array_unique(array_merge(
                $objectTypes,
                $this->option_driven_object_types($taxonomy)
            )));
            $keyspace = $this->policy->taxonomy_object_keyspace($taxonomy, $objectTypes);
            if ($keyspace === 'term') {
                $termObject[] = $taxonomy;
                continue;
            }
            foreach ($objectTypes as $objectType) {
                $byPostType[$objectType][] = $taxonomy;
            }
        }

        return $this->ownership = [
            'by_post_type' => $byPostType,
            'term_object' => $termObject,
        ];
    }

    /** @return string[] */
    private function option_driven_object_types(string $taxonomy): array {
        $ref = $this->policy->object_type_option_ref($taxonomy);
        if ($ref === null) {
            return [];
        }
        $optionsEntity = $this->compiled->tree()['options/core'] ?? null;
        if (!is_array($optionsEntity) || !is_array($optionsEntity['data'] ?? null)) {
            return [];
        }
        try {
            $records = OptionState::records($optionsEntity['data']);
        } catch (\Throwable $failure) {
            return [];
        }
        $record = $records[$ref['option']] ?? null;
        if (($record['state'] ?? '') !== 'present' || !is_array($record['value'] ?? null)) {
            return [];
        }
        $value = $record['value'][$ref['sub_key']] ?? null;
        if (!is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map('strval', $value)));
    }
}
