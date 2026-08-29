<?php
namespace WPrism;

require_once __DIR__ . '/../Policy/Policy.php';
require_once __DIR__ . '/../Grammar/Tokens.php';
require_once __DIR__ . '/ApplyFieldMaterializer.php';
require_once __DIR__ . '/../Delete/DeleteGuardEvaluator.php';
require_once __DIR__ . '/CacheInvalidationTransaction.php';
// Deliberately NOT require_once('Db.php') or require_once('Ledger.php')
// here: four suites declare a fake WPrism\Ledger (regress_adapter_observation.php,
// regress_code_revision_enforcement.php, regress_lifecycle_phase_handoff_unit.php,
// regress_scoped_promotion_target.php); three of those four ALSO declare a
// fake WPrism\Db (all but regress_code_revision_enforcement.php). Only TWO of
// the four actually reach this file transitively through Apply.php --
// regress_code_revision_enforcement.php (via Deploy.php) and regress_scoped_
// promotion_target.php -- runtime-verified, not assumed (the other two are
// pulled in by grep on filename alone and never load Apply.php at all).
// Between those two reaching suites, Ledger's exclusion is necessary for
// regress_code_revision_enforcement.php and Db's for regress_scoped_
// promotion_target.php, so both stay excluded even though neither reaching
// suite needs both. Verified empirically, the hard way: an initial pass
// checked Db and Ledger with two DIFFERENT, non-equivalent grep patterns
// (a `final class Db {`-shaped one for Db, a bare `^class Ledger\b` one for
// Ledger) and wrongly cleared Ledger as unfaked; make regress-offline-all
// caught the resulting "Cannot redeclare class WPrism\Ledger" fatal
// immediately. Re-verified with one symmetric pattern applied to both
// classes: `grep -rnE '^\s*(final\s+)?class\s+(Db|Ledger)\s*(\{|extends|
// implements)' sandbox/tests/*.php` (note -n, not -l -- printing the
// matched line, not just the filename, is what actually distinguishes
// which class a file fakes rather than merely that it fakes something).
// A caller that needs Db or Ledger (like Apply.php itself) must require
// them explicitly.

/**
 * The term entity materializer (issue #3347 slice 6, one of the "Entity
 * materializers: posts, terms, menus, options/meta/users, relationships,
 * attachments, typed tables" target seams): reconciles one canonical term's
 * name/slug/parent/description and its own term-object relationships (a
 * term's membership in OTHER taxonomies as object_id — Polylang's
 * term_language/term_translations) against the live target.
 *
 * Constructed from exactly `(Policy, Tokens, ApplyFieldMaterializer)` — the
 * same narrow contract MenuMaterializer (slice 4) and UserMetaMaterializer
 * (slice 5) established. Unlike MenuMaterializer (which requires nothing
 * itself, issue #3444), all three constructor types are required directly
 * above, matching UserMetaMaterializer's own corrected practice.
 *
 * One dependency does NOT fit that contract: reconcile_term_relationships()
 * needs the policy-scoped taxonomy roster whose resolved object_keyspace is
 * `term` (`term_object_taxes()`), which Apply computes via the shared,
 * memoized, WordPress-registry-reading, warnings-collecting
 * taxes_by_object_type() — the SAME computation the still-Apply-resident
 * post-relationship reconciler also consumes (taxes_for_post_type()). That
 * method is not a narrow, injectable dependency (it mutates Apply's own
 * $taxesByObjectType memoization field and $warnings collection, and reads
 * live WordPress taxonomy registration) and moving it here would either
 * duplicate it or wrongly couple two still-separate entity materializers.
 * Both methods below take the resolved `$termObjectTaxes` array as an
 * explicit parameter instead — issue #3347's own guardrail ("dependency
 * injection and narrow data contracts") applied to a value Apply already
 * has to compute once per apply run regardless of which entities changed.
 *
 * encode_description() and reconcile_term_relationships() had no caller
 * anywhere in Apply.php besides finalize_term() itself (grep-verified) and
 * moved here in full rather than staying as facades — unlike MenuMaterializer's
 * assign_locations(), which Apply's own delete path also calls directly and
 * therefore still keeps as a separate facade.
 */
final class TermMaterializer {
    private const MAX_RELATIONSHIP_TAXONOMIES = 256;
    private const MAX_TERM_RELATIONSHIPS = 100000;
    private ?string $relationshipLockIndex = null;

    public function __construct(
        private readonly Policy $policy,
        private readonly Tokens $tokens,
        private readonly ApplyFieldMaterializer $fieldMaterializer
    ) {
    }

    public function begin_authored_transaction(): void {
        $this->relationshipLockIndex = null;
    }

    public function end_authored_transaction(): void {
        $this->relationshipLockIndex = null;
    }

    public function ensure_term_row(array $front, string $entityType): void {
        global $wpdb;
        if (Ledger::id_for($front['uuid'], Ledger::KIND_TERM) !== null) {
            return;
        }
        CacheInvalidationTransaction::assert_term_taxonomy_prepared(
            (string) $front['taxonomy'],
            'apply insert term'
        );
        $termGroup = $this->policy->taxonomy_term_group_is_authored((string) $front['taxonomy'])
            ? (int) $front['term_group']
            : 0;
        Db::insert(
            $wpdb->terms,
            ['name' => $front['name'], 'slug' => $front['slug'], 'term_group' => $termGroup],
            null,
            'apply insert term'
        );
        $termId = Db::insert_id('apply insert term');
        Db::insert($wpdb->term_taxonomy, [
            'term_id' => $termId,
            'taxonomy' => $front['taxonomy'],
            'description' => '',
            'parent' => 0,
            'count' => 0,
        ], null, 'apply insert term taxonomy');
        $termTaxonomyId = Db::insert_id('apply insert term taxonomy');
        Db::insert($wpdb->termmeta, [
            'term_id' => $termId,
            'meta_key' => '_wprism_uuid',
            'meta_value' => $front['uuid'],
        ], null, 'apply insert term identity');
        $identityRows = $this->fieldMaterializer->meta_owner_range_lock(
            $wpdb->termmeta,
            'term_id',
            'apply insert term identity readback'
        )->exact_key_rows($termId, '_wprism_uuid');
        if (count($identityRows) !== 1
            || !is_string($identityRows[0]['meta_value'] ?? null)
            || !hash_equals((string) $front['uuid'], $identityRows[0]['meta_value'])) {
            throw new \RuntimeException('wprism: apply insert term identity did not persist one exact requested sidecar');
        }
        Ledger::set($front['uuid'], $entityType, Ledger::KIND_TERM, $termId);
        Ledger::set($front['uuid'], $entityType, Ledger::KIND_TT, $termTaxonomyId);
        $this->queue_term_cache($termId, (string) $front['taxonomy'], 'apply insert term');
    }

    /** @param string[] $termObjectTaxes policy-scoped taxonomies whose resolved object_keyspace is `term` */
    public function finalize_term(array $front, array $termObjectTaxes): void {
        global $wpdb;
        $termId = Ledger::id_for($front['uuid'], Ledger::KIND_TERM);
        $parentId = 0;
        if (!empty($front['parent'])) {
            $parentId = Ledger::id_for($front['parent'], Ledger::KIND_TERM)
                ?? throw new \RuntimeException("wprism: term {$front['slug']}: parent {$front['parent']} not resolvable");
        }
        CacheInvalidationTransaction::assert_term_taxonomy_prepared(
            (string) $front['taxonomy'],
            'apply update term'
        );
        $termRow = ['name' => $front['name'], 'slug' => $front['slug']];
        if ($this->policy->taxonomy_term_group_is_authored((string) $front['taxonomy'])) {
            $termRow['term_group'] = (int) $front['term_group'];
        }
        Db::update(
            $wpdb->terms,
            $termRow,
            ['term_id' => $termId],
            null,
            null,
            'apply update term'
        );
        Db::update($wpdb->term_taxonomy, [
            'description' => $this->encode_description($front['taxonomy'], $front['description']),
            'parent' => $parentId,
        ], ['term_id' => $termId, 'taxonomy' => $front['taxonomy']], null, null, 'apply update term taxonomy');
        $this->queue_term_cache($termId, (string) $front['taxonomy'], 'apply update term');
        $this->fieldMaterializer->reconcile_authored_term_meta($termId, (array) ($front['meta'] ?? []));
        $this->reconcile_term_relationships($termId, $front['taxonomy'], (array) ($front['relationships'] ?? []), $termObjectTaxes);
    }

    /**
     * Mirror of Capture::term_description(): a taxonomy declaring
     * `taxonomies.<tax>.description_refs` gets its token-bearing map
     * resolved back through the ledger and re-serialized with PHP's OWN
     * serialize() — so int-typed ids come back as `i:N;`, matching
     * Polylang's own writes byte-for-byte in TYPE, not just in decoded
     * value (verified empirically: this column's ids are genuinely int-typed
     * `i:N;`, not the digit-string convention ACF/Yoast use elsewhere).
     * Every other taxonomy keeps the plain detokenize_text() treatment.
     */
    public function encode_description(string $taxonomy, $description): string {
        $this->policy->taxonomy_description_lint_rule($taxonomy, $description);
        $rule = $this->policy->description_reference_rule($taxonomy);
        if ($rule === null) {
            return $this->tokens->detokenize_text((string) $description);
        }
        $decoded = $this->tokens->struct_apply(
            $description,
            $rule['json_refs'],
            $rule['key_refs']
        );
        return serialize($decoded);
    }

    /**
     * Term-keyspace symmetry of Apply's own reconcile_relationships(): a
     * term's own membership in OTHER taxonomies as object_id — a relationship
     * type the data model had no room for until Polylang's
     * term_language/term_translations forced it, since those rows store a
     * TERM id in the column every other taxonomy fills with a POST id.
     * Scoped to $termObjectTaxes — the same manifest-keyspace collision guard
     * reconcile_relationships() applies
     * for posts — so this never touches a colliding POST's own
     * relationship rows just because the numeric id matches. Two-phase-
     * safe for free: this only ever runs in phase 2 (finalize_term()),
     * after phase 1 has already inserted every term row (source AND
     * target) and its ledger entries for this whole apply run.
     *
     * @param string[] $termObjectTaxes policy-scoped taxonomies whose resolved object_keyspace is `term`
     */
    public function reconcile_term_relationships(int $termId, string $taxonomy, array $relField, array $termObjectTaxes): void {
        global $wpdb;
        foreach (array_keys($relField) as $tax) {
            $keyspace = $this->policy->taxonomy_object_keyspace((string) $tax);
            if ($keyspace !== 'term') {
                throw new \RuntimeException(
                    "wprism: term $termId ($taxonomy) declares relationships.$tax, but manifest object_keyspace "
                    . "is '$keyspace' — term relationships require object_keyspace=term"
                );
            }
        }
        $taxes = $this->relationship_taxonomies($termObjectTaxes);
        if (!$taxes) {
            return;
        }
        $desiredTt = [];
        foreach ($relField as $tax => $uuids) {
            if (!in_array($tax, $taxes, true)) {
                // Not a taxonomy this environment currently owns as term-
                // object (stale file from before this capability existed,
                // or a hand edit) — never let it reach the ledger lookup /
                // INSERT below, mirroring reconcile_relationships()'s
                // identical guard on the post side.
                continue;
            }
            foreach ((array) $uuids as $u) {
                $tt = Ledger::id_for($u, Ledger::KIND_TT)
                    ?? throw new \RuntimeException("wprism: term {$termId} ($taxonomy) references unresolvable term $u ($tax)");
                $desiredTt[$tt] = true;
            }
        }
        $currentRows = $this->locked_relationship_rows($termId, $taxes, 'term-object relationship reconciliation');
        $current = [];
        foreach ($currentRows as $row) {
            $current[$row['term_taxonomy_id']] = true;
        }
        foreach (array_keys($current) as $tt) {
            if (!isset($desiredTt[$tt])) {
                Db::delete($wpdb->term_relationships, ['object_id' => $termId, 'term_taxonomy_id' => $tt], null, 'apply delete term-object relationship');
            }
        }
        foreach (array_keys($desiredTt) as $tt) {
            if (!isset($current[$tt])) {
                Db::insert($wpdb->term_relationships, [
                    'object_id' => $termId, 'term_taxonomy_id' => $tt, 'term_order' => 0,
                ], null, 'apply insert term-object relationship');
            }
        }
        foreach ($taxes as $tax) {
            CacheInvalidationTransaction::queue_relationship(
                $termId,
                $tax,
                'term-object relationship reconciliation'
            );
        }
        $after = [];
        foreach ($this->locked_relationship_rows(
            $termId,
            $taxes,
            'term-object relationship postcondition'
        ) as $row) {
            $after[$row['term_taxonomy_id']] = true;
        }
        ksort($after, SORT_NUMERIC);
        ksort($desiredTt, SORT_NUMERIC);
        if ($after !== $desiredTt) {
            throw new \RuntimeException(
                "wprism: term $termId ($taxonomy) relationship postcondition disagrees with exact locked storage; "
                . 'recovery_required'
            );
        }
    }

    private function queue_term_cache(int $termId, string $taxonomy, string $purpose): void {
        // clean_term_cache() is not a cache primitive: it regenerates the
        // hierarchy option, queries the database and fires plugin hooks. Raw
        // authored mutation may not run those undeclared effects inside the
        // transaction. The `terms` last_changed token is the hook-free query
        // generation consumed by WP_Term_Query and is repeated after outcome.
        CacheInvalidationTransaction::queue_term($termId, $taxonomy, $purpose);
    }

    /** @return list<string> */
    private function relationship_taxonomies(array $taxonomies): array {
        if ($taxonomies === []) {
            return [];
        }
        if (!array_is_list($taxonomies) || count($taxonomies) > self::MAX_RELATIONSHIP_TAXONOMIES) {
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

    /** @return list<array{term_taxonomy_id:int,taxonomy:string,term_id:int}> */
    private function locked_relationship_rows(int $termId, array $taxonomies, string $purpose): array {
        global $wpdb;
        if ($termId <= 0) {
            throw new \RuntimeException("wprism: $purpose received an invalid term identity");
        }
        DeleteGuardEvaluator::assert_table_identifiers(
            [$wpdb->term_relationships, $wpdb->term_taxonomy],
            $purpose
        );
        DeleteGuardEvaluator::assert_transaction_isolation($purpose);
        if ($this->relationshipLockIndex === null) {
            DeleteGuardEvaluator::assert_innodb_tables(
                [$wpdb->term_relationships, $wpdb->term_taxonomy],
                $purpose
            );
            $this->relationshipLockIndex = DeleteGuardEvaluator::full_width_lock_index(
                $wpdb->term_relationships,
                'object_id',
                $purpose
            );
        }
        $placeholders = implode(',', array_fill(0, count($taxonomies), '%s'));
        $wpdb->last_error = '';
        $rows = $wpdb->get_results($wpdb->prepare(
            'SELECT tr.term_taxonomy_id, tt.taxonomy, tt.term_id '
            . "FROM {$wpdb->term_relationships} tr FORCE INDEX (`{$this->relationshipLockIndex}`) "
            . "JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id "
            . "WHERE tr.object_id = %d AND tt.taxonomy IN ($placeholders) "
            . 'ORDER BY tr.term_taxonomy_id ASC LIMIT ' . (self::MAX_TERM_RELATIONSHIPS + 1)
            . ' FOR UPDATE',
            $termId,
            ...$taxonomies
        ), ARRAY_A);
        if (!is_array($rows)
            || !array_is_list($rows)
            || trim((string) ($wpdb->last_error ?? '')) !== '') {
            throw new \RuntimeException("wprism: $purpose bounded locked read failed");
        }
        if (count($rows) > self::MAX_TERM_RELATIONSHIPS) {
            throw new \RuntimeException("wprism: $purpose exceeds the bounded row limit");
        }
        $out = [];
        $seen = [];
        foreach ($rows as $position => $row) {
            $ttId = is_array($row) ? self::positive_id($row['term_taxonomy_id'] ?? null) : null;
            $targetId = is_array($row) ? self::positive_id($row['term_id'] ?? null) : null;
            $tax = is_array($row) ? ($row['taxonomy'] ?? null) : null;
            if (!is_array($row)
                || array_keys($row) !== ['term_taxonomy_id', 'taxonomy', 'term_id']
                || $ttId === null
                || $targetId === null
                || !is_string($tax)
                || !in_array($tax, $taxonomies, true)) {
                throw new \RuntimeException("wprism: $purpose returned a malformed row at position $position");
            }
            if (isset($seen[$ttId])) {
                throw new \RuntimeException("wprism: $purpose returned a duplicate relationship identity");
            }
            $seen[$ttId] = true;
            $out[] = ['term_taxonomy_id' => $ttId, 'taxonomy' => $tax, 'term_id' => $targetId];
        }
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
}
