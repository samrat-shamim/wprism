<?php
namespace WPrism;

require_once __DIR__ . '/../Kernel/Canon.php';
require_once __DIR__ . '/EntityMetaCapture.php';
require_once __DIR__ . '/../Repository/Ledger.php';
require_once __DIR__ . '/../Kernel/PlainData.php';
require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Grammar/Tokens.php';

/** Builds canonical term entities after the candidate identity pass. */
final class TermCapture {
    private const MAX_RELATIONSHIP_TAXONOMIES = 256;
    private const MAX_TERM_RELATIONSHIPS = 100000;
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
        if ($this->policy->taxonomy_term_group_is_authored((string) $term->taxonomy)) {
            $termGroup = self::nonnegative_integer($term->term_group ?? null);
            if ($termGroup === null) {
                throw new \RuntimeException(
                    'wprism: authored term_group capture received a malformed or out-of-range database value'
                );
            }
            $front['term_group'] = $termGroup;
        }
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
        $taxonomies = $this->relationship_taxonomies($termObjectTaxonomies);
        $placeholders = implode(',', array_fill(0, count($taxonomies), '%s'));
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT tr.term_taxonomy_id, tt.taxonomy, tt.term_id FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($placeholders)
             ORDER BY tr.term_taxonomy_id ASC LIMIT " . (self::MAX_TERM_RELATIONSHIPS + 1),
            $termId,
            ...$taxonomies
        ), ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException('wprism: bounded term-object relationship capture read failed');
        }
        if (count($rows) > self::MAX_TERM_RELATIONSHIPS) {
            throw new \RuntimeException('wprism: term-object relationships exceed the bounded row limit');
        }
        $out = [];
        $seen = [];
        foreach ($rows as $position => $row) {
            $ttId = is_array($row) ? self::positive_id($row['term_taxonomy_id'] ?? null) : null;
            $targetId = is_array($row) ? self::positive_id($row['term_id'] ?? null) : null;
            $taxonomy = is_array($row) ? ($row['taxonomy'] ?? null) : null;
            if (!is_array($row)
                || array_keys($row) !== ['term_taxonomy_id', 'taxonomy', 'term_id']
                || $ttId === null
                || $targetId === null
                || !is_string($taxonomy)
                || !in_array($taxonomy, $taxonomies, true)) {
                throw new \RuntimeException(
                    "wprism: bounded term-object relationship capture returned a malformed row at position $position"
                );
            }
            if (isset($seen[$ttId])) {
                throw new \RuntimeException('wprism: term-object relationship capture returned a duplicate identity');
            }
            $seen[$ttId] = true;
            $targetUuid = Ledger::uuid_for($targetId, Ledger::KIND_TERM);
            if ($targetUuid !== null) {
                $out[$taxonomy][] = $targetUuid;
            }
        }
        foreach ($out as &$list) {
            sort($list, SORT_STRING);
        }
        unset($list);
        return $out;
    }

    /** @return list<string> */
    private function relationship_taxonomies(array $taxonomies): array {
        if (!array_is_list($taxonomies)
            || $taxonomies === []
            || count($taxonomies) > self::MAX_RELATIONSHIP_TAXONOMIES) {
            throw new \RuntimeException('wprism: term-object taxonomy scope is malformed or over the bounded limit');
        }
        $out = [];
        foreach ($taxonomies as $taxonomy) {
            if (!is_string($taxonomy)
                || preg_match('/^[a-z0-9_-]{1,32}$/D', $taxonomy) !== 1
                || isset($out[$taxonomy])) {
                throw new \RuntimeException('wprism: term-object taxonomy scope contains an invalid/duplicate name');
            }
            $out[$taxonomy] = true;
        }
        $out = array_keys($out);
        sort($out, SORT_STRING);
        return $out;
    }

    private static function positive_id(mixed $value): ?int {
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/D', $value) !== 1) {
            return null;
        }
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return is_int($id) ? $id : null;
    }

    private static function nonnegative_integer(mixed $value): ?int {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (!is_string($value) || preg_match('/^(?:0|[1-9][0-9]*)$/D', $value) !== 1) {
            return null;
        }
        $number = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
        return is_int($number) ? $number : null;
    }

    /** @return string|object|array */
    private function description(object $term) {
        $this->policy->taxonomy_description_lint_rule($term->taxonomy, $term->description);
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
                "wprism: taxonomy '{$term->taxonomy}' declares description_refs but term {$term->slug}'s description"
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
