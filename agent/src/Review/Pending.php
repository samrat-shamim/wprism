<?php
namespace WPrism;

// issue #3508: the queue's mechanism-ownership test below asks SidebarState
// directly, and the drop-in has no autoloader — every engine file names the
// classes it loads (sandbox/tests/offline/guards/regress_agent_src_requires.php).
require_once __DIR__ . '/../Repository/SidebarState.php';

/**
 * The core loop's review queue (DESIGN.md 3.1.5): `wp wprism pending` is the
 * step between "unclassified write blocked capture" and "wp wprism classify
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
 * A journal-observed name is not automatically a review item (issue #3508). A
 * name a dedicated engine mechanism already owns end to end has no
 * classification to give, so demanding one is noise, not safety. Two
 * mechanisms own names today: SidebarState, over `sidebars_widgets` and the
 * whole `widget_<type>` family (SidebarState::owns_option(),
 * Repository/SidebarState.php:51-53), and the dynamic_options resolver, over
 * any declared prefix — core.json's `theme_mods_` is the one shipped case
 * (Policy::dynamic_option_rule_for_prefix(), Policy/Policy.php:885). Neither
 * is silenced by this: SidebarState still refuses capture for an undeclared
 * widget type with real instances (SidebarState.php:262-269) and the gate
 * walk still emits its own `widgets:<type>` finding
 * (Capture/CaptureGateScanner.php:100-106). See mechanism_owner() below.
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
     *   ref_hint?: array{kind:string, id:int, title:string, post_type:string, at:string},
     *   secret?: string
     * }>
     */
    public static function scan(string $repo, ?Policy $policy = null): array {
        return self::scan_with_policy($repo, $policy ?? Policy::load($repo));
    }

    /** The review queue is one read-only projection on every caller path. */
    private static function scan_with_policy(string $repo, Policy $policy): array {

        // Capture/Snapshot and the per-item helpers can each make several
        // SELECTs.  Check immediately after every observer-owned read: a
        // subsequent successful wpdb query clears last_error and must never
        // turn a failed earlier read into empty evidence.
        $observationReadCheckpoint = static function (): void { self::assert_read_only_database(); };

        // This is the collect-only gate walk, not Capture::run().  Supplying
        // the already loaded policy avoids a second policy path and preserves
        // the observer's no-repair/no-write boundary.
        $gate = Capture::gate_scan_read_only($repo, $policy, $observationReadCheckpoint);
        self::assert_read_only_database();
        // The journal-derived half of the queue exists only once the agent's
        // durable journal does. On a target WPrism has never written to (the
        // first look `wprism assess` takes on an adoption seed, before any init
        // or capture) the table is provably absent — a clean SHOW TABLES says
        // so — and that is a known state, not a failed read: the queue is
        // then exactly what the live gate walk found. Reading the journal
        // regardless made the observer refuse `adapter_observation_pending_
        // unreadable` on every fresh site (T7 grind A1), which told the
        // operator to repair a database nothing had touched.
        if (!self::journal_installed()) {
            $journalOptions = [];
            $journalPostMeta = [];
            $journalTermMeta = [];
        } else {
            $journalOptions = self::journal_unclassified($policy, 'options', $observationReadCheckpoint);
            self::assert_read_only_database();
            $journalPostMeta = self::journal_unclassified($policy, 'postmeta', $observationReadCheckpoint);
            self::assert_read_only_database();
            $journalTermMeta = self::journal_unclassified($policy, 'termmeta', $observationReadCheckpoint);
            self::assert_read_only_database();
        }

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
        // issue #3266's menu-item meta findings fold directly into
        // $gate['post_meta'] above (issue #3275 — nav_menu_item is a real
        // post_type, tagged into that finding's own post_types set, not a
        // separate discovery section) — no dedicated loop needed here.
        foreach ($gate['user_meta'] as $key => $ev) {
            $items[] = self::make_item('user_meta', $key, $ev, null, $observationReadCheckpoint);
        }
        $keyspaceGaps = Snapshot::keyspace_gaps($policy, $observationReadCheckpoint);
        self::assert_read_only_database();
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
        self::assert_read_only_database();
        return $items;
    }

    /**
     * Whether the agent's durable journal table exists on this target, proved
     * by a SHOW TABLES whose own read succeeded. A failed probe is refused as
     * unreadable (never reported as absent), exactly as
     * AdapterObservation::assert_journal_prerequisite() treats it.
     */
    public static function journal_installed(): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wprism_journal';
        $found = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
        $readError = $wpdb->last_error ?? '';
        if (!is_string($readError) || $readError !== '' || $found === false) {
            self::assert_read_only_database();
            throw new CommandRefusalException(
                'pending_evidence_unreadable',
                'the pending review could not read existing evidence',
                'inspect and repair the target database through the existing controlled workflow before reviewing pending evidence',
                [[
                    'code' => 'pending_evidence_unreadable',
                    'message' => 'the review could not prove whether the provenance journal exists',
                    'remediation' => 'restore readable target evidence before reviewing pending evidence',
                ]],
                'wprism: pending review refused because the journal existence probe failed'
            );
        }

        return is_string($found) && $found === $table;
    }

    /**
     * A review projection must fail instead of presenting a failed query as
     * an empty pending queue.
     */
    private static function assert_read_only_database(): void {
        global $wpdb;
        $error = is_object($wpdb) ? ($wpdb->last_error ?? null) : null;
        if (!is_string($error) || $error !== '') {
            throw new CommandRefusalException(
                'pending_evidence_unreadable',
                'the pending review could not read existing evidence',
                'inspect and repair the target database through the existing controlled workflow before reviewing pending evidence',
                [[
                    'code' => 'pending_evidence_unreadable',
                    'message' => 'the review will not treat a failed pending read as an empty review queue',
                    'remediation' => 'restore readable target evidence before reviewing pending evidence',
                ]],
                'wprism: pending review refused because an evidence SELECT failed'
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
     * issue #3214: current_value() reads a raw, untrusted DB value — ANY
     * option/post_meta/term_meta row in this installation (this is the
     * pending-review surface; the key may not even be classified yet), not
     * just a wprism-authored one. Plain maybe_unserialize() (WordPress core:
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
            // The KEY is evidence, not decoration: it is what separates a
            // page id from an action-scheduler watermark that happens to be
            // the same small integer. See ref_hint().
            $hint = self::ref_hint($key, $value, $observationReadCheckpoint);
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
     * Key tokens that say "this slot holds another entity's id".
     *
     * This vocabulary is the whole difference between evidence and
     * coincidence. A bare small integer resolves against a fresh WordPress
     * install no matter what it means, so the id ALONE is never a reason to
     * emit a hint — the NAME holding it has to claim a reference too. The
     * list is deliberately short and deliberately covers WordPress's own
     * reference-bearing options, which are the recall this rule must not
     * cost: `page_on_front` and `page_for_posts` (`page`), `sticky_posts`
     * (`posts`), `wp_page_for_privacy_policy` (`page`), `default_category`
     * and `default_link_category` (`category`), plus the `*_id` / `*_ids`
     * convention every plugin follows.
     *
     * Matched per TOKEN, never as a substring: `wpforms` must not match
     * `forms`, or the plugin's every bookkeeping row would look like a
     * reference again. camelCase splits too, so a JSON `formId` reads as
     * `form` + `id`.
     */
    private const REF_KEY_TOKENS = [
        'id', 'ids', 'page', 'pages', 'post', 'posts', 'parent', 'parents',
        'term', 'terms', 'category', 'categories', 'tag', 'tags',
        'attachment', 'attachments', 'thumbnail', 'thumbnails', 'media',
        'menu', 'menus', 'form', 'forms',
    ];

    /** A scalar whose name says term/category/tag must resolve in that ID
     * namespace first. WordPress allocates post and term ids independently,
     * so post-first resolution mislabeled Rank Math's
     * `rank_math_primary_category = 2` as Sample Page #2 on a fresh site even
     * though category #2 was the value's real owner. This changes hint order
     * only; the result remains advisory and never becomes a classification. */
    private const TERM_REF_KEY_TOKENS = [
        'term', 'terms', 'category', 'categories', 'tag', 'tags',
    ];

    /**
     * Key tokens that say "this slot holds a version", which is a number
     * about code and never about an entity.
     *
     * Measured: `wpforms_constant_contact_version = '3'` was hinted
     * `post:3 "Privacy Policy"` on the recon site — a provider schema
     * version pointed at a page. This veto runs BEFORE the token test above
     * so a slot named both ways (a hypothetical `form_version`) still reads
     * as the version it is.
     */
    private const VERSION_KEY_TOKENS = ['version', 'versions'];

    /**
     * A value at or above this is a unix timestamp, not an entity id.
     *
     * 1e9 is 2001-09-09; WordPress auto-increment ids reach nothing close on
     * any real site, and a site that genuinely holds a billion posts has a
     * larger problem than a review-queue hint. Measured case:
     * `wpforms_forms_first_created = '1787672947'` sits under a key whose
     * `forms` token passes REF_KEY_TOKENS, so the key test alone would let an
     * epoch through to resolve_id() and depend on luck for the right answer.
     */
    private const TIMESTAMP_FLOOR = 1000000000;

    /** Structure walk bounds: a review queue must not turn one pathological
     *  option value into an unbounded descent or an unbounded number of
     *  resolve_id() SELECTs. Both are generous next to anything measured (the
     *  deepest real case, `wpforms_form_locations`, is two levels and two
     *  candidates) and both are the reason this walk can be recursive at all. */
    private const STRUCTURE_MAX_DEPTH = 6;
    private const STRUCTURE_MAX_CANDIDATES = 32;

    /**
     * Finding #9's linter: if the value holds something that looks like a
     * post/term id, check whether it actually resolves to one. First
     * candidate (in value order) that resolves wins; a given id is checked
     * against posts before terms. This is a hint for `classify` time, not
     * proof the key IS a ref — never a classification.
     *
     * WHAT THE RECON MEASURED. On a WPForms Lite 2.0.0.5 site holding four
     * genuine cross-entity references, `wprism pending` emitted three hints and
     * all three were wrong:
     *   - `wpforms_settings` (a serialized array) -> post:1 "Hello world!"
     *   - `wpforms_constant_contact_version` = '3' -> post:3 "Privacy Policy"
     *   - `action_scheduler_hybrid_store_demarkation` = '4' -> post:4 "Recon
     *     Thank You"
     * and found none of the four real ones. The issue #3508 guard below only
     * suppressed a value that was WHOLLY 0 or 1, so `wpforms_settings`'s
     * first extractable id — the `"1"` of `s:13:"modern-markup";s:1:"1"` —
     * walked straight past it into exactly the row issue #3508 exists to stop.
     *
     * FOUR RULES, each one a measured class. A candidate is offered only when
     * a key CLAIMS a reference (REF_KEY_TOKENS — the pending key itself for a
     * scalar, the member key for anything inside a structure), and never when
     * the key names a version (VERSION_KEY_TOKENS), the value is an epoch
     * (TIMESTAMP_FLOOR), or the value is 0/1. That last one is issue #3508's
     * rule applied at every depth instead of only to a whole value: a `1`
     * inside a serialized settings array is the same boolean it would be on
     * its own, and post #1 is still WordPress's own seed row.
     *
     * numeric_candidates() is deliberately untouched and no longer the
     * extractor here: it is shared with Lint::scan_tree() at eight call sites
     * (Lint.php:197, 235, 316, 445, 575, 613, 665, 713), where a bare 1
     * sitting inside a larger structure IS a genuine candidate and where the
     * key vocabulary above has no meaning.
     *
     * WHAT THIS STILL CANNOT SEE, stated because the alternative is implying
     * otherwise: pending's surfaces are options, post/term/user meta and the
     * gate walk's own findings. It never reads a post BODY, so three of the
     * recon's four real references — `settings.confirmations.<n>.page` inside
     * a wpforms post's `post_content`, and two `wpforms/form-selector`
     * `formId` block attributes — are out of reach here by surface, not by
     * heuristic. `wp wprism lint` is the body scanner, and it found both block
     * attrs. The fourth, `wpforms_form_locations` postmeta, is a structure
     * this walk now reaches.
     */
    private static function ref_hint(string $key, $value, ?callable $observationReadCheckpoint = null): ?array {
        if (self::has_key_token($key, self::VERSION_KEY_TOKENS)) {
            return null;
        }
        $candidates = [];
        self::collect_ref_candidates($value, '', self::ref_claim($key), 0, $candidates);
        foreach ($candidates as [$id, $locator]) {
            $hit = self::resolve_id_with_preference(
                $id,
                $observationReadCheckpoint,
                self::has_key_token($key, self::TERM_REF_KEY_TOKENS)
            );
            if ($hit !== null) {
                // `at` is the hint explaining itself: '' means the key's own
                // value, anything else is the exact member inside it that
                // matched, so an operator reading `at [0].id` can go look at
                // that member rather than trusting the arrow.
                $hit['at'] = $locator;
                return $hit;
            }
        }
        return null;
    }

    /**
     * Walk a value for id candidates whose key claims a reference, appending
     * `[id, locator]` pairs in value order.
     *
     * `$refKey` is the nearest key that claimed a reference, carried DOWN
     * through integer-keyed levels only: a list under `page_ids` is a list of
     * page ids, but a named child key replaces its parent's claim outright
     * (`['page' => ['title' => '4']]` offers nothing — `title` is not a
     * reference slot, whatever its parent was called). A null $refKey means
     * nothing here claims a reference, so no scalar under it is a candidate.
     *
     * JSON strings are decoded and walked like arrays. That is the second
     * half of the recon's finding: a value's structure is invisible to a
     * shallow extractor whether the encoding is PHP-serialized (already
     * decoded by current_value()) or JSON (`wpforms_versions_lite` is a JSON
     * map on the measured site), and refusing to look inside either is how a
     * real reference like `wpforms_form_locations`'s `id => 5` — the id of
     * the PAGE embedding the form — went unseen while a boolean two levels
     * down got hinted.
     *
     * @param list<array{0:int,1:string}> $out
     */
    private static function collect_ref_candidates(
        $value,
        string $path,
        ?string $refKey,
        int $depth,
        array &$out
    ): void {
        if (count($out) >= self::STRUCTURE_MAX_CANDIDATES || $depth > self::STRUCTURE_MAX_DEPTH) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $memberKey => $memberValue) {
                if (count($out) >= self::STRUCTURE_MAX_CANDIDATES) {
                    return;
                }
                $childPath = is_int($memberKey)
                    ? $path . '[' . $memberKey . ']'
                    : $path . '.' . $memberKey;
                // An int key is a list position and carries the parent's
                // claim; a string key IS the claim, and replaces it.
                $childRefKey = is_int($memberKey) ? $refKey : self::ref_claim((string) $memberKey);
                self::collect_ref_candidates($memberValue, $childPath, $childRefKey, $depth + 1, $out);
            }
            return;
        }
        if (is_string($value)) {
            $decoded = self::decode_json_structure($value);
            if ($decoded !== null) {
                self::collect_ref_candidates($decoded, $path, $refKey, $depth + 1, $out);
                return;
            }
            // A CSV list of ids under a reference-claiming key, exactly the
            // shape numeric_candidates() has always recognised; the locator
            // spelling matches its `[csv:$i]` so the two read the same.
            if ($refKey !== null && preg_match('/^\d+(,\d+)+$/', trim($value)) === 1) {
                foreach (explode(',', trim($value)) as $i => $segment) {
                    self::offer_candidate($segment, $path . '[csv:' . $i . ']', $out);
                }
                return;
            }
        }
        if ($refKey !== null && (is_int($value) || is_string($value))) {
            self::offer_candidate($value, $path, $out);
        }
    }

    /**
     * Apply the two VALUE vetoes and record the candidate.
     *
     * Both vetoes live here rather than in the walk so every path — bare
     * scalar, list element, CSV segment, structure member — is filtered by
     * the same code; a veto that held on one shape and not another is exactly
     * the drift issue #3508's whole-value-only test turned out to be.
     *
     * @param list<array{0:int,1:string}> $out
     */
    private static function offer_candidate($value, string $locator, array &$out): void {
        if (!is_int($value) && !(is_string($value) && $value !== '' && is_numeric($value) && !str_contains($value, '.'))) {
            return;
        }
        $id = (int) $value;
        if ($id <= 0 || $id === 1) {
            // issue #3508 at every depth: 0 and 1 are flags. 0 is also not a
            // positive id, so the two reasons coincide there.
            return;
        }
        if ($id >= self::TIMESTAMP_FLOOR) {
            return;
        }
        $out[] = [$id, $locator];
    }

    /**
     * A JSON object/array this string encodes, or null if it is not one.
     *
     * Guarded on the first non-space byte before json_decode() is called at
     * all: every option value in a review queue is an untrusted string, most
     * of them are not JSON, and `json_decode` on a bare numeric string
     * succeeds and would turn `'42'` into a "structure". Only `{`/`[` open a
     * structure, and only an array result is one.
     */
    private static function decode_json_structure(string $raw): ?array {
        $trimmed = ltrim($raw);
        if ($trimmed === '' || ($trimmed[0] !== '{' && $trimmed[0] !== '[')) {
            return null;
        }
        $decoded = json_decode($trimmed, true, self::STRUCTURE_MAX_DEPTH + 2);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * The reference $key claims, or null when it claims none.
     *
     * One place, so the pending key and every member key inside its value are
     * judged by identical rules — the version veto first (a slot named for a
     * version holds a version, whatever else its name says), then the
     * reference vocabulary.
     */
    private static function ref_claim(string $key): ?string {
        if (self::has_key_token($key, self::VERSION_KEY_TOKENS)) {
            return null;
        }
        return self::has_key_token($key, self::REF_KEY_TOKENS) ? $key : null;
    }

    /**
     * Whether $key carries one of $tokens as a whole token.
     *
     * Splits on non-alphanumerics AND on camelCase boundaries, so
     * `wpforms_form_locations`, `_thumbnail_id` and a JSON `formId` all
     * tokenize the way a reader would read them, while `wpforms` stays one
     * token and never matches `forms`.
     *
     * @param list<string> $tokens
     */
    private static function has_key_token(string $key, array $tokens): bool {
        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key) ?? $key;
        foreach (preg_split('/[^a-zA-Z0-9]+/', strtolower($spaced)) ?: [] as $token) {
            if ($token !== '' && in_array($token, $tokens, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Resolve a positive id against THIS environment's live posts, then
     * terms by default — or terms first when ref_hint() has a term-shaped
     * key — the one place that turns a bare integer into "yes, that's real,
     * here's what" (or null). Shared by ref_hint() above and
     * Lint::scan_tree()'s bare_id / serialized_desc_ids detectors (task
     * #11's generalized linter) — extracted so both use one implementation
     * rather than two copies that could drift.
     *
     * Excludes post_type=revision and post_status=auto-draft: every edit
     * accumulates revision rows (WP core plumbing — never a WPrism-manageable
     * post_type, never independently addressable; "revision #10" is never
     * a meaningful reference the way "post #10" is), and auto-draft posts
     * are transient empty placeholders. Left unfiltered, either would match
     * as pure id-space noise unrelated to authored content, and that noise
     * grows with every edit a site receives. No other post_type/status is
     * excluded — attachments (status=inherit) are legitimate targets.
     */
    public static function resolve_id(int $id, ?callable $observationReadCheckpoint = null): ?array {
        return self::resolve_id_with_preference($id, $observationReadCheckpoint, false);
    }

    /** @return ?array{kind:string,id:int,title:string,post_type:string} */
    private static function resolve_id_with_preference(
        int $id,
        ?callable $observationReadCheckpoint,
        bool $preferTerm
    ): ?array {
        if ($id <= 0) {
            return null;
        }
        global $wpdb;
        if ($preferTerm) {
            $term = self::resolve_term_id($id, $observationReadCheckpoint);
            if ($term !== null) {
                return $term;
            }
        }
        $post = $wpdb->get_row($wpdb->prepare(
            "SELECT ID, post_type, post_title FROM {$wpdb->posts}
             WHERE ID = %d AND post_type != 'revision' AND post_status != 'auto-draft'",
            $id
        ), ARRAY_A);
        self::checkpoint_observation_read($observationReadCheckpoint);
        if ($post) {
            return ['kind' => 'post', 'id' => $id, 'title' => (string) $post['post_title'], 'post_type' => (string) $post['post_type']];
        }
        return $preferTerm ? null : self::resolve_term_id($id, $observationReadCheckpoint);
    }

    /** @return ?array{kind:string,id:int,title:string,post_type:string} */
    private static function resolve_term_id(int $id, ?callable $observationReadCheckpoint = null): ?array {
        global $wpdb;
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
     * The dedicated engine mechanism that already owns $item end to end, or
     * null when the review queue is the right place to ask a human.
     *
     * `Journal::ground_truth()` answers from the exact/pattern rule table
     * alone (Journal.php:331-334 -> Policy::option_rule(), Policy.php:634-636
     * -> PolicyRuleResolver), which structurally cannot see either mechanism
     * below. Before issue #3508 that made a fresh install demand a
     * classification for ~46 names whose owner is engine code rather than any
     * manifest declaration: every `widget_<type>` row, `sidebars_widgets`,
     * and both the active theme's `theme_mods_<stylesheet>` row and each
     * stale-residue one.
     *
     * dynamic_options is asked by PREFIX on purpose, never through
     * resolve_dynamic_option(): the active row is governed (class `env` plus
     * declared sub_keys) and a residue row is declared env-local residue
     * (manifests/core.json:85), so both belong out of the queue — and the
     * prefix lookup needs no `get_option('stylesheet')` read, which
     * scan()'s strictly read-only projection must not
     * acquire.
     */
    private static function mechanism_owner(Policy $policy, string $tbl, string $item): ?string {
        if ($tbl !== 'options') {
            return null;
        }
        if (SidebarState::owns_option($item)) {
            return 'widgets';
        }
        return $policy->dynamic_option_rule_for_prefix($item) !== null ? 'dynamic_options' : null;
    }

    /**
     * Aggregate wprism_journal rows for one table, keeping only items with NO
     * policy rule (Journal::ground_truth — the exact lookup Journal::report
     * uses, reused rather than re-derived) and no dedicated owning mechanism
     * (mechanism_owner above). Multiple rows per item
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
             FROM {$wpdb->prefix}wprism_journal
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
                if (Journal::ground_truth($policy, $tbl, $item) !== null
                    || self::mechanism_owner($policy, $tbl, $item) !== null) {
                    // Already classified, or owned end to end by a dedicated
                    // mechanism — either way not part of the review queue.
                    $excluded[$item] = true;
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
