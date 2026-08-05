<?php
namespace Duo;

/**
 * The core loop's review queue (DESIGN.md 3.1.5): `wp duo pending` is the
 * step between "unclassified write blocked capture" and "wp duo classify
 * writes the rule" — it surfaces everything currently unclassified, with
 * whatever evidence exists to help a human decide.
 *
 * Two disjoint sources, per finding #5/#9:
 *   - Gate items (post_meta, term_meta): Capture::gate_scan()'s in-scope
 *     walk — the SAME walk Capture's abort gate runs, just collecting
 *     instead of aborting. Journal evidence, when the same key was also
 *     observed there, is joined on.
 *   - Options: options are whitelist-only at capture (an unlisted option is
 *     invisible — exactly finding #5's silent-loss shape), so they can ONLY
 *     be surfaced by the provenance journal ever having seen a write to
 *     them. No journal evidence -> no way to know the option exists at all;
 *     this source is journal-only by necessity, not by choice.
 *
 * A proposal is NEVER guessed here — it is always exactly the journal's own
 * capability x surface signal (Journal::propose, aggregated), or null.
 */
final class Pending {
    /**
     * @return array<int, array{
     *   section: string, key: string, proposal: ?string,
     *   evidence: array{
     *     entities?: int, post_types?: string[],
     *     journal?: array{n:int, surfaces: array<string,int>, caps: array<string,int>, proposal: ?string}
     *   },
     *   ref_hint?: array{kind:string, id:int, title:string, post_type:string},
     *   secret?: string
     * }>
     */
    public static function scan(string $repo): array {
        Ledger::ensure();
        $policy = Policy::load($repo);

        $gate = Capture::gate_scan($repo);
        $journalOptions = self::journal_unclassified($policy, 'options');
        $journalPostMeta = self::journal_unclassified($policy, 'postmeta');
        $journalTermMeta = self::journal_unclassified($policy, 'termmeta');

        $items = [];
        foreach ($gate['post_meta'] as $key => $ev) {
            $items[] = self::make_item('post_meta', $key, $ev, $journalPostMeta[$key] ?? null);
        }
        foreach ($gate['term_meta'] as $key => $ev) {
            $items[] = self::make_item('term_meta', $key, $ev, $journalTermMeta[$key] ?? null);
        }
        foreach ($journalOptions as $key => $j) {
            $items[] = self::make_item('options', $key, null, $j);
        }

        usort($items, fn($a, $b) => [$a['section'], $a['key']] <=> [$b['section'], $b['key']]);
        return $items;
    }

    /** Live current value for one section/key — first row found (a
     *  representative sample, not per-entity). Used for the ref-hint linter,
     *  the secret flag, and `classify`'s pre-write secret check. */
    public static function current_value(string $section, string $key) {
        global $wpdb;
        $raw = match ($section) {
            'options' => $wpdb->get_var($wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s LIMIT 1", $key
            )),
            'post_meta' => $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1", $key
            )),
            'term_meta' => $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->termmeta} WHERE meta_key = %s LIMIT 1", $key
            )),
            default => null,
        };
        return $raw === null ? null : maybe_unserialize($raw);
    }

    // ------------------------------------------------------------ assembly

    /**
     * @param ?array{entities:int, post_types?:string[]} $gateEv
     * @param ?array{n:int, surfaces:array<string,int>, caps:array<string,int>, proposal:?string} $journalEv
     */
    private static function make_item(string $section, string $key, ?array $gateEv, ?array $journalEv): array {
        $evidence = [];
        if ($gateEv !== null) {
            $evidence['entities'] = $gateEv['entities'];
            if (!empty($gateEv['post_types'])) {
                $evidence['post_types'] = $gateEv['post_types'];
            }
        }
        if ($journalEv !== null) {
            $evidence['journal'] = $journalEv;
        }

        $item = [
            'section' => $section,
            'key' => $key,
            'proposal' => $journalEv['proposal'] ?? null,
            'evidence' => $evidence,
        ];

        $value = self::current_value($section, $key);
        if ($value !== null) {
            $hint = self::ref_hint($value);
            if ($hint !== null) {
                $item['ref_hint'] = $hint;
            }
            if (is_string($value)) {
                $label = Secrets::hard_match($value);
                if ($label !== null) {
                    $item['secret'] = "hard:$label";
                } elseif (Secrets::suspicious($key, $value)) {
                    $item['secret'] = 'suspicious';
                }
            }
        }
        return $item;
    }

    // --------------------------------------------------------------- ref linter (finding #9)

    /**
     * Finding #9's linter: if the current value looks like a post/term id —
     * or a list of them, array or CSV — check whether it actually resolves
     * to an existing post or term. First id (in value order) that resolves
     * wins; a given id is checked against posts before terms. This is a
     * hint for `classify` time, not proof the key IS a ref: small ids can
     * coincide with unrelated numbers.
     */
    private static function ref_hint($value): ?array {
        $ids = self::numeric_candidates($value);
        if (!$ids) {
            return null;
        }
        global $wpdb;
        foreach ($ids as $id) {
            if ($id <= 0) {
                continue;
            }
            $post = $wpdb->get_row($wpdb->prepare(
                "SELECT ID, post_type, post_title FROM {$wpdb->posts} WHERE ID = %d", $id
            ), ARRAY_A);
            if ($post) {
                return ['kind' => 'post', 'id' => $id, 'title' => (string) $post['post_title'], 'post_type' => (string) $post['post_type']];
            }
            $term = $wpdb->get_row($wpdb->prepare(
                "SELECT t.term_id, t.name, tt.taxonomy FROM {$wpdb->terms} t
                 JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
                 WHERE t.term_id = %d LIMIT 1",
                $id
            ), ARRAY_A);
            if ($term) {
                // Reuses the same 4-key shape as the post case ("post_type"
                // holds the taxonomy name here) so the CLI renders both
                // uniformly without a kind-specific branch.
                return ['kind' => 'term', 'id' => $id, 'title' => (string) $term['name'], 'post_type' => (string) $term['taxonomy']];
            }
        }
        return null;
    }

    /** @return int[] candidate positive-looking ids from a scalar/array/CSV value. */
    private static function numeric_candidates($value): array {
        if (is_int($value) || (is_string($value) && $value !== '' && is_numeric($value) && !str_contains($value, '.'))) {
            return [(int) $value];
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $v) {
                if (is_numeric($v) && !str_contains((string) $v, '.')) {
                    $out[] = (int) $v;
                }
            }
            return $out;
        }
        if (is_string($value) && preg_match('/^\d+(,\d+)+$/', trim($value))) {
            return array_map('intval', explode(',', trim($value)));
        }
        return [];
    }

    // ------------------------------------------------------ journal evidence

    /**
     * Aggregate duo_journal rows for one table, keeping only items with NO
     * policy rule (Journal::ground_truth — the exact lookup Journal::report
     * uses, reused rather than re-derived). Multiple rows per item
     * (different surface/caps/proposal combinations) collapse to one
     * evidence bundle; proposal takes the strongest signal seen —
     * authored > runtime > abstain-only ('review' rows alone => null,
     * never guessed).
     *
     * @return array<string, array{n:int, surfaces: array<string,int>, caps: array<string,int>, proposal: ?string}>
     */
    private static function journal_unclassified(Policy $policy, string $tbl): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT item, surface, caps, proposal, COUNT(*) AS n
             FROM {$wpdb->prefix}duo_journal
             WHERE tbl = %s AND item != ''
             GROUP BY item, surface, caps, proposal",
            $tbl
        ), ARRAY_A) ?: [];

        $rank = ['review' => 0, 'runtime' => 1, 'authored' => 2];
        $out = [];
        $excluded = [];
        foreach ($rows as $r) {
            $item = $r['item'];
            if (isset($excluded[$item])) {
                continue;
            }
            if (!isset($out[$item])) {
                if (Journal::ground_truth($policy, $tbl, $item) !== null) {
                    $excluded[$item] = true; // already classified — not part of the review queue
                    continue;
                }
                $out[$item] = ['n' => 0, 'surfaces' => [], 'caps' => [], 'proposal' => null];
            }
            $n = (int) $r['n'];
            $out[$item]['n'] += $n;
            $out[$item]['surfaces'][$r['surface']] = ($out[$item]['surfaces'][$r['surface']] ?? 0) + $n;
            $capsKey = $r['caps'] !== '' ? $r['caps'] : 'anon';
            $out[$item]['caps'][$capsKey] = ($out[$item]['caps'][$capsKey] ?? 0) + $n;

            $curRank = $rank[$out[$item]['proposal'] ?? ''] ?? -1;
            $newRank = $rank[$r['proposal']] ?? -1;
            if ($newRank > $curRank) {
                $out[$item]['proposal'] = $r['proposal'] === 'review' ? null : $r['proposal'];
            }
        }
        ksort($out, SORT_STRING);
        return $out;
    }
}
