<?php
namespace Duo;

/**
 * The core loop's review queue (DESIGN.md 3.1.5): `wp duo pending` is the
 * step between "unclassified write blocked capture" and "wp duo classify
 * writes the rule" — it surfaces everything currently unclassified, with
 * whatever evidence exists to help a human decide.
 *
 * Two disjoint sources, per finding #5/#9:
 *   - Gate items (scope, post_meta, term_meta, user_meta): Capture::gate_scan()'s
 *     exact scope/classification walk — the SAME walk Capture's abort gates
 *     run, collecting instead of aborting. Journal evidence, when the same
 *     meta key was also observed there, is joined on.
 *   - Options: every manifest-declared option namespace is enumerated from
 *     wp_options directly. The journal enriches those rows but is never a
 *     completeness dependency. Options outside a claimed namespace remain
 *     journal-only because no adapter has asserted ownership of them.
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
        return self::scan_with_policy($repo, Policy::load($repo), false);
    }

    /**
     * Strictly read-only pending projection for adapter observation.
     *
     * Pending's normal operator command keeps its historical Ledger::ensure()
     * behavior above: an interactive review queue can initialize the agent's
     * durable journal state.  An observation request cannot.  Its caller has
     * already proved the journal prerequisite exists, so this twin takes the
     * same gate/journal/keyspace walk without DDL, repair, classification, or
     * any ledger write.
     */
    public static function scan_read_only(string $repo, Policy $policy): array {
        self::assert_read_only_database();
        return self::scan_with_policy($repo, $policy, true);
    }

    private static function scan_with_policy(string $repo, Policy $policy, bool $strictRead): array {

        // Capture/Snapshot and the per-item helpers can each make several
        // SELECTs.  Check immediately after every observer-owned read: a
        // subsequent successful wpdb query clears last_error and must never
        // turn a failed earlier read into empty evidence.
        $observationReadCheckpoint = $strictRead
            ? static function (): void { self::assert_read_only_database(); }
            : null;

        // This is the collect-only gate walk, not Capture::run().  Supplying
        // the already loaded policy avoids a second policy path and preserves
        // the observer's no-repair/no-write boundary.
        $gate = Capture::gate_scan_read_only($repo, $policy, $observationReadCheckpoint);
        self::assert_read_only_database($strictRead);
        $journalOptions = self::journal_unclassified($policy, 'options', $observationReadCheckpoint);
        self::assert_read_only_database($strictRead);
        $journalPostMeta = self::journal_unclassified($policy, 'postmeta', $observationReadCheckpoint);
        self::assert_read_only_database($strictRead);
        $journalTermMeta = self::journal_unclassified($policy, 'termmeta', $observationReadCheckpoint);
        self::assert_read_only_database($strictRead);

        $items = [];
        foreach ($gate['scope'] as $key => $ev) {
            $items[] = self::make_item('scope', $key, $ev, null, $observationReadCheckpoint);
        }
        foreach ($gate['options'] as $key => $ev) {
            $items[] = self::make_item('options', $key, $ev, $journalOptions[$key] ?? null, $observationReadCheckpoint);
            unset($journalOptions[$key]);
        }
        foreach ($gate['widgets'] ?? [] as $key => $ev) {
            $items[] = self::make_item('widgets', $key, $ev, null, $observationReadCheckpoint);
        }
        foreach ($gate['post_meta'] as $key => $ev) {
            $items[] = self::make_item('post_meta', $key, $ev, $journalPostMeta[$key] ?? null, $observationReadCheckpoint);
        }
        foreach ($gate['term_meta'] as $key => $ev) {
            $items[] = self::make_item('term_meta', $key, $ev, $journalTermMeta[$key] ?? null, $observationReadCheckpoint);
        }
        // DUO-3266's menu-item meta findings fold directly into
        // $gate['post_meta'] above (DUO-3275 — nav_menu_item is a real
        // post_type, tagged into that finding's own post_types set, not a
        // separate discovery section) — no dedicated loop needed here.
        foreach ($gate['user_meta'] as $key => $ev) {
            $items[] = self::make_item('user_meta', $key, $ev, null, $observationReadCheckpoint);
        }
        $keyspaceGaps = Snapshot::keyspace_gaps($policy, $observationReadCheckpoint);
        self::assert_read_only_database($strictRead);
        foreach ($keyspaceGaps as $gap) {
            $items[] = self::make_item('table_meta', $gap['table'] . ':' . $gap['key'], [
                'entities' => $gap['count'],
                'owner_candidates' => [$gap['owner']],
                'value_shapes' => $gap['value_shapes'],
                'reason' => $gap['reason'],
            ], null, $observationReadCheckpoint);
        }
        foreach ($journalOptions as $key => $j) {
            $items[] = self::make_item('options', $key, null, $j, $observationReadCheckpoint);
        }

        usort($items, fn($a, $b) => [$a['section'], $a['key']] <=> [$b['section'], $b['key']]);
        self::assert_read_only_database($strictRead);
        return $items;
    }

    /**
     * A read-only observer must fail instead of presenting a failed query as
     * an empty pending queue. Normal interactive pending keeps its legacy
     * behavior; this check is deliberately confined to the new twin.
     */
    private static function assert_read_only_database(bool $required = true): void {
        if (!$required) {
            return;
        }
        global $wpdb;
        $error = is_object($wpdb) ? ($wpdb->last_error ?? null) : null;
        if (!is_string($error) || $error !== '') {
            throw new CommandRefusalException(
                'adapter_observation_pending_unreadable',
                'adapter observation could not read the existing pending-review evidence',
                'inspect and repair the target database through the existing controlled workflow before collecting proposal evidence',
                [[
                    'code' => 'adapter_observation_pending_unreadable',
                    'message' => 'the observer will not treat a failed pending read as an empty review queue',
                    'remediation' => 'restore readable target evidence before collecting adapter observation evidence',
                ]],
                'duo: adapter observation refused because a pending evidence SELECT failed'
            );
        }
    }

    /** Live current value for one section/key — first row found (a
     *  representative sample, not per-entity). Used for the ref-hint linter,
     *  the secret flag, and `classify`'s pre-write secret check. */
    public static function current_value(
        string $section,
        string $key,
        ?callable $observationReadCheckpoint = null
    ) {
        if (!in_array($section, ['options', 'post_meta', 'term_meta', 'user_meta'], true)) {
            return null;
        }
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
            'user_meta' => $wpdb->get_var($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->usermeta} WHERE meta_key = %s LIMIT 1", $key
            )),
        };
        self::checkpoint_observation_read($observationReadCheckpoint);
        return $raw === null ? null : self::safe_maybe_unserialize($raw);
    }

    /**
     * DUO-3214: current_value() reads a raw, untrusted DB value — ANY
     * option/post_meta/term_meta row in this installation (this is the
     * pending-review surface; the key may not even be classified yet), not
     * just a duo-authored one. Plain maybe_unserialize() (WordPress core:
     * `is_serialized($data) ? @unserialize(trim($data)) : $data`) calls
     * unserialize() with no 'allowed_classes' restriction — a PHP-serialized
     * OBJECT instantiates (running its __wakeup(), and later __destruct())
     * before this function, or its caller, ever inspects the result. This
     * mirrors core's own contract exactly (same is_serialized() gate, same
     * trim() before unserialize, same passthrough for a non-serialized
     * string) with the one change that matters: 'allowed_classes' => false,
     * the identical safe pattern Capture::term_description() already uses
     * (Capture.php:583) — a serialized OBJECT decodes to false (PHP's
     * documented behavior for a disallowed class) instead of ever
     * instantiating.
     */
    private static function safe_maybe_unserialize(string $raw) {
        return is_serialized($raw) ? @unserialize(trim($raw), ['allowed_classes' => false]) : $raw;
    }

    // ------------------------------------------------------------ assembly

    /**
     * @param ?array{entities:int, post_types?:string[]} $gateEv
     * @param ?array{n:int, surfaces:array<string,int>, caps:array<string,int>, proposal:?string} $journalEv
     */
    private static function make_item(
        string $section,
        string $key,
        ?array $gateEv,
        ?array $journalEv,
        ?callable $observationReadCheckpoint = null
    ): array {
        $evidence = [];
        if ($gateEv !== null) {
            $evidence = $gateEv;
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

        $value = self::current_value($section, $key, $observationReadCheckpoint);
        if ($value !== null) {
            $hint = self::ref_hint($value, $observationReadCheckpoint);
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
    private static function ref_hint($value, ?callable $observationReadCheckpoint = null): ?array {
        foreach (self::numeric_candidates($value) as [$id, ]) {
            if ($id <= 0) {
                continue;
            }
            $hit = self::resolve_id($id, $observationReadCheckpoint);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
    }

    /**
     * Resolve a positive id against THIS environment's live posts, then
     * terms — the one place that turns a bare integer into "yes, that's
     * real, here's what" (or null). Shared by ref_hint() above and
     * Lint::scan_tree()'s bare_id / serialized_desc_ids detectors (task
     * #11's generalized linter) — extracted so both use one implementation
     * rather than two copies that could drift.
     *
     * Excludes post_type=revision and post_status=auto-draft: every edit
     * accumulates revision rows (WP core plumbing — never a Duo-manageable
     * post_type, never independently addressable; "revision #10" is never
     * a meaningful reference the way "post #10" is), and auto-draft posts
     * are transient empty placeholders. Left unfiltered, either would match
     * as pure id-space noise unrelated to authored content, and that noise
     * grows with every edit a site receives. No other post_type/status is
     * excluded — attachments (status=inherit) are legitimate targets.
     */
    public static function resolve_id(int $id, ?callable $observationReadCheckpoint = null): ?array {
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        $post = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_type, post_title FROM {$wpdb->posts}
             WHERE ID = %d AND post_type != 'revision' AND post_status != 'auto-draft'",
            $id
        ), ARRAY_A);
        self::checkpoint_observation_read($observationReadCheckpoint);
        if ($post) {
            return ['kind' => 'post', 'id' => $id, 'title' => (string) $post['post_title'], 'post_type' => (string) $post['post_type']];
        }
        $term = $wpdb->get_row($wpdb->prepare(
            "SELECT t.term_id, t.name, tt.taxonomy FROM {$wpdb->terms} t
             JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
             WHERE t.term_id = %d LIMIT 1",
            $id
        ), ARRAY_A);
        self::checkpoint_observation_read($observationReadCheckpoint);
        if ($term) {
            // Reuses the same 4-key shape as the post case ("post_type"
            // holds the taxonomy name here) so callers render both
            // uniformly without a kind-specific branch.
            return ['kind' => 'term', 'id' => $id, 'title' => (string) $term['name'], 'post_type' => (string) $term['taxonomy']];
        }
        return null;
    }

    /**
     * Candidate positive-looking ids from a scalar/array/CSV value, each
     * paired with a locator suffix describing where it was found ("" for a
     * bare scalar, "[$i]" for an array element, "[csv:$i]" for a CSV
     * segment). Shallow only — no nested-object recursion; sub-key/
     * id-keyed-array refs are backlog (task #11's wave 2). Shared by
     * ref_hint() above (which only wants the ids) and Lint::scan_tree()'s
     * bare_id detector (which wants the locators too, to point at exactly
     * which element matched).
     *
     * @return array<int, array{0:int, 1:string}>
     */
    public static function numeric_candidates($value): array {
        if (is_int($value) || (is_string($value) && $value !== '' && is_numeric($value) && !str_contains($value, '.'))) {
            return [[(int) $value, '']];
        }
        if (is_array($value)) {
            $out = [];
            foreach ($value as $i => $v) {
                if (is_numeric($v) && !str_contains((string) $v, '.')) {
                    $out[] = [(int) $v, '[' . $i . ']'];
                }
            }
            return $out;
        }
        if (is_string($value) && preg_match('/^\d+(,\d+)+$/', trim($value))) {
            $out = [];
            foreach (explode(',', trim($value)) as $i => $seg) {
                $out[] = [(int) $seg, '[csv:' . $i . ']'];
            }
            return $out;
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
    private static function journal_unclassified(
        Policy $policy,
        string $tbl,
        ?callable $observationReadCheckpoint = null
    ): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT item, surface, caps, proposal, COUNT(*) AS n
             FROM {$wpdb->prefix}duo_journal
             WHERE tbl = %s AND item != ''
             GROUP BY item, surface, caps, proposal",
            $tbl
        ), ARRAY_A) ?: [];
        self::checkpoint_observation_read($observationReadCheckpoint);

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

    /** Invoke adapter observation's strict read check without changing normal pending behavior. */
    private static function checkpoint_observation_read(?callable $observationReadCheckpoint): void {
        if ($observationReadCheckpoint !== null) {
            $observationReadCheckpoint();
        }
    }
}
