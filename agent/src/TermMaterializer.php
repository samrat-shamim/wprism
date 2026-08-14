<?php
namespace Duo;

require_once __DIR__ . '/Policy.php';
require_once __DIR__ . '/Tokens.php';
require_once __DIR__ . '/ApplyFieldMaterializer.php';
// Deliberately NOT require_once('Db.php') or require_once('Ledger.php')
// here: four suites declare a fake Duo\Ledger (regress_adapter_observation.php,
// regress_code_revision_enforcement.php, regress_lifecycle_phase_handoff_unit.php,
// regress_scoped_promotion_target.php); three of those four ALSO declare a
// fake Duo\Db (all but regress_code_revision_enforcement.php). Only TWO of
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
// caught the resulting "Cannot redeclare class Duo\Ledger" fatal
// immediately. Re-verified with one symmetric pattern applied to both
// classes: `grep -rnE '^\s*(final\s+)?class\s+(Db|Ledger)\s*(\{|extends|
// implements)' sandbox/tests/*.php` (note -n, not -l -- printing the
// matched line, not just the filename, is what actually distinguishes
// which class a file fakes rather than merely that it fakes something).
// A caller that needs Db or Ledger (like Apply.php itself) must require
// them explicitly.

/**
 * The term entity materializer (DUO-3347 slice 6, one of the "Entity
 * materializers: posts, terms, menus, options/meta/users, relationships,
 * attachments, typed tables" target seams): reconciles one canonical term's
 * name/slug/parent/description and its own term-object relationships (a
 * term's membership in OTHER taxonomies as object_id — Polylang's
 * term_language/term_translations) against the live target.
 *
 * Constructed from exactly `(Policy, Tokens, ApplyFieldMaterializer)` — the
 * same narrow contract MenuMaterializer (slice 4) and UserMetaMaterializer
 * (slice 5) established. Unlike MenuMaterializer (which requires nothing
 * itself, DUO-3444), all three constructor types are required directly
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
 * explicit parameter instead — DUO-3347's own guardrail ("dependency
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
    public function __construct(
        private readonly Policy $policy,
        private readonly Tokens $tokens,
        private readonly ApplyFieldMaterializer $fieldMaterializer
    ) {
    }

    public function ensure_term_row(array $front, string $entityType): void {
        global $wpdb;
        if (Ledger::id_for($front['uuid'], Ledger::KIND_TERM) !== null) {
            return;
        }
        Db::insert(
            $wpdb->terms,
            ['name' => $front['name'], 'slug' => $front['slug'], 'term_group' => 0],
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
            'meta_key' => '_duo_uuid',
            'meta_value' => $front['uuid'],
        ], null, 'apply insert term identity');
        Ledger::set($front['uuid'], $entityType, Ledger::KIND_TERM, $termId);
        Ledger::set($front['uuid'], $entityType, Ledger::KIND_TT, $termTaxonomyId);
    }

    /** @param string[] $termObjectTaxes policy-scoped taxonomies whose resolved object_keyspace is `term` */
    public function finalize_term(array $front, array $termObjectTaxes): void {
        global $wpdb;
        $termId = Ledger::id_for($front['uuid'], Ledger::KIND_TERM);
        $parentId = 0;
        if (!empty($front['parent'])) {
            $parentId = Ledger::id_for($front['parent'], Ledger::KIND_TERM)
                ?? throw new \RuntimeException("duo: term {$front['slug']}: parent {$front['parent']} not resolvable");
        }
        Db::update($wpdb->terms, ['name' => $front['name'], 'slug' => $front['slug']], ['term_id' => $termId], null, null, 'apply update term');
        Db::update($wpdb->term_taxonomy, [
            'description' => $this->encode_description($front['taxonomy'], $front['description']),
            'parent' => $parentId,
        ], ['term_id' => $termId, 'taxonomy' => $front['taxonomy']], null, null, 'apply update term taxonomy');
        $this->fieldMaterializer->reconcile_authored_term_meta($termId, (array) ($front['meta'] ?? []));
        $this->reconcile_term_relationships($termId, $front['taxonomy'], (array) ($front['relationships'] ?? []), $termObjectTaxes);
    }

    /**
     * Mirror of Capture::term_description(): a taxonomy declaring
     * `taxonomies.<tax>.description_refs` gets its token-bearing map
     * resolved back through the ledger and re-serialized with PHP's OWN
     * serialize() — so int-typed ids come back as `i:N;`, matching
     * Polylang's own writes byte-for-byte in TYPE, not just in decoded
     * value (docs/frontier/polylang.md verified this column is genuinely
     * int-typed, not the digit-string convention ACF/Yoast use elsewhere).
     * Every other taxonomy keeps the plain detokenize_text() treatment.
     */
    public function encode_description(string $taxonomy, $description): string {
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
     * term's own membership in OTHER taxonomies as object_id (docs/frontier/
     * polylang.md's "term-object relationship capture/apply" — Polylang's
     * term_language/term_translations). Scoped to $termObjectTaxes — the
     * same manifest-keyspace collision guard reconcile_relationships() applies
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
                    "duo: term $termId ($taxonomy) declares relationships.$tax, but manifest object_keyspace "
                    . "is '$keyspace' — term relationships require object_keyspace=term"
                );
            }
        }
        $taxes = $termObjectTaxes;
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
                    ?? throw new \RuntimeException("duo: term {$termId} ($taxonomy) references unresolvable term $u ($tax)");
                $desiredTt[$tt] = true;
            }
        }
        $in = "'" . implode("','", array_map('esc_sql', $taxes)) . "'";
        $current = $wpdb->get_col($wpdb->prepare(
            "SELECT tr.term_taxonomy_id FROM {$wpdb->term_relationships} tr
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id
             WHERE tr.object_id = %d AND tt.taxonomy IN ($in)",
            $termId
        )) ?: [];
        foreach ($current as $tt) {
            if (!isset($desiredTt[(int) $tt])) {
                Db::delete($wpdb->term_relationships, ['object_id' => $termId, 'term_taxonomy_id' => (int) $tt], null, 'apply delete term-object relationship');
            }
        }
        foreach (array_keys($desiredTt) as $tt) {
            if (!in_array((string) $tt, array_map('strval', $current), true)) {
                Db::insert($wpdb->term_relationships, [
                    'object_id' => $termId, 'term_taxonomy_id' => $tt, 'term_order' => 0,
                ], null, 'apply insert term-object relationship');
            }
        }
    }
}
