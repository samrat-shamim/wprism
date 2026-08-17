<?php
namespace Duo;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/EntityMetaCapture.php';
require_once __DIR__ . '/../Repository/Ledger.php';
require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Grammar/Tokens.php';

/** Builds canonical term entities after the candidate identity pass. */
final class TermCapture {
    public function __construct(
        private Policy $policy,
        private Tokens $tokens,
        private EntityMetaCapture $entityMetaCapture
    ) {}

    /** @param string[] $termObjectTaxonomies */
    public function capture(object $term, string $uuid, array $termObjectTaxonomies): array {
        $parentUuid = null;
        if ((int) $term->parent > 0) {
            $parentUuid = Ledger::uuid_for((int) $term->parent, Ledger::KIND_TERM);
            if ($parentUuid === null) {
                $this->tokens->warnings[] = "term {$term->slug}: unmanaged parent term {$term->parent} dropped";
            }
        }

        $byKey = $this->entityMetaCapture->termMetaByKey((int) $term->term_id);
        $flatMeta = array_map(static fn($values) => $values[0], $byKey);
        $meta = [];
        foreach ($byKey as $key => $values) {
            [$store, $value] = $this->entityMetaCapture->classifyValue(
                $key,
                $values,
                $flatMeta,
                "term {$term->taxonomy}:{$term->slug}",
                'term_meta',
                true
            );
            if ($store) {
                $meta[$key] = $value;
            }
        }

        $front = [
            'uuid' => $uuid,
            'taxonomy' => $term->taxonomy,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $this->description($term),
            'parent' => $parentUuid,
            'meta' => (object) $meta,
            'relationships' => (object) $this->relationships((int) $term->term_id, $termObjectTaxonomies),
        ];
        return [
            'uuid' => $uuid,
            'type' => 'term',
            'path' => "terms/{$term->taxonomy}/{$uuid}--{$term->slug}.json",
            'content' => Canon::encode($front),
        ];
    }

    /** @param string[] $termObjectTaxonomies */
    private function relationships(int $termId, array $termObjectTaxonomies): array {
        global $wpdb;
        if ($termObjectTaxonomies === []) {
            return [];
        }
        $in = "'" . implode("','", array_map('esc_sql', $termObjectTaxonomies)) . "'";
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tt.taxonomy, tt.term_id FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            $termId
        )) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $targetUuid = Ledger::uuid_for((int) $row->term_id, Ledger::KIND_TERM);
            if ($targetUuid !== null) {
                $out[$row->taxonomy][] = $targetUuid;
            }
        }
        foreach ($out as &$list) {
            sort($list, SORT_STRING);
        }
        unset($list);
        return $out;
    }

    /** @return string|object|array */
    private function description(object $term) {
        $rule = $this->policy->description_reference_rule($term->taxonomy);
        if ($rule === null) {
            return $this->tokens->tokenize_text((string) $term->description);
        }
        $decoded = PlainData::decode(
            (string) $term->description,
            "taxonomy '{$term->taxonomy}' term {$term->slug}'s description"
        );
        if (!is_array($decoded)) {
            throw new \RuntimeException(
                "duo: taxonomy '{$term->taxonomy}' declares description_refs but term {$term->slug}'s description"
                . ' is not an array once unserialized'
            );
        }
        $captured = $this->tokens->struct_capture(
            $decoded,
            $rule['json_refs'],
            $rule['key_refs']
        );
        return $rule['legacy_flat_map'] ? (object) $captured : $captured;
    }
}
