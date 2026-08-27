<?php
namespace Duo;

require_once __DIR__ . '/Journal.php';
require_once __DIR__ . '/../Kernel/CommandRefusal.php';
require_once __DIR__ . '/../Policy/Policy.php';

/**
 * Score each adapter's declared `effects[]` against the writes the provenance
 * journal actually observed. Report-only, by construction.
 *
 * WHY THIS EXISTS
 * ---------------
 * `recovery/EffectBundle.php:9-13` states the authority verbatim — "The
 * compiled inventory is the entire authority… it may never add an effect after
 * preparation" — and `:121` asserts each actual effect matches exactly
 * `{effect_id, kind, manifest, phase, selector}`. So a manifest's declared
 * effects ARE recovery's rollback authority. Nothing compared them against
 * what a site's database actually receives, which means an adapter that
 * declares FEWER effects has always looked cleaner than one that declares
 * more. This is the missing comparison, and it inverts that incentive.
 *
 * `Journal::report_with_policy()` already scores observed writes against
 * manifest ground truth into a closed verdict set (Journal.php:279-360); this
 * is that identical shape retargeted from a write's provenance CLASS to its
 * membership in an effect SELECTOR.
 *
 * REPORT-ONLY, AND WHY THAT IS A DESIGN PROPERTY RATHER THAN A PHASE
 * ------------------------------------------------------------------
 * Made blocking too early this breaks working sites, so the noise floor has to
 * be measured before anyone can argue about a refusal. Nothing on the
 * capture/plan/apply/deploy path references this class — the same posture
 * `Coverage` takes (Coverage.php:14-20) and for the same reason. The document
 * carries `authority => false` and `blocking => false` as the machine-readable
 * statement of that, and `sandbox/tests/offline/recovery/
 * regress_effect_declaration_coverage.php` asserts the structural half: no
 * mutation path names this class, and a report full of findings still RETURNS.
 *
 * WHAT THE JOURNAL CAN AND CANNOT SEE — READ THIS BEFORE TRUSTING A NUMBER
 * -----------------------------------------------------------------------
 * The journal observes `(table, item)` for database writes only
 * (Journal.php:66-100). Of the 247 effect rows `Policy::effects_inventory()`
 * projects for the 16 shipped adapters, 64 carry a `database_checkpoint`
 * selector and are therefore scorable; the remaining 183 select `external`
 * hooks, cache namespaces and provider resources, which no journal row can
 * confirm or refute. Eight adapters (core, elementor, ninja-forms, polylang,
 * the-events-calendar, woocommerce, yoast, yoast-duplicate-post) declare at
 * least one scorable effect; the other eight declare none, so this scorer is
 * SILENT about them and that silence is reported as `scorable => false` rather
 * than as a clean score. #561 added the seventh scorable adapter; the reviewed
 * Polylang production-readiness port added five effects, two observable, and
 * the eighth. PMPro's generic row-cache invalidation migration retired one
 * provider action effect without changing journal observability. Measured on
 * the shipped tree at the commit that introduced this file and re-pinned at
 * each reviewed manifest edit.
 *
 * THE THREE VERDICTS ON AN OBSERVED WRITE
 * ---------------------------------------
 * A write is scored once per adapter whose territory it falls in, and a write
 * can be in more than one (a `table:options` selector claims every options
 * row, which is a real property of that declaration, not an artifact here):
 *
 *   `declared`  — it matches at least one journal-observable declared effect
 *                 selector of this adapter. The rollback authority covers it.
 *   `classified` — no effect selector matches, but this adapter classifies the
 *                 surface (`Journal::ground_truth_details()` names it as the
 *                 declaring manifest) or declares it as an action trigger. The
 *                 apply ledger and the database checkpoint hold authority
 *                 there, so `effects[]` need not; not a finding.
 *   `outside_declaration` — the write is inside this adapter's declared option
 *                 namespace, and it is neither classified by it nor inside any
 *                 of its declared effect selectors. THE finding: nothing —
 *                 not the ledger, not the checkpoint inventory — has declared
 *                 authority over that write.
 *
 * A write no adapter's territory claims is not silently dropped; it is
 * reported at the top level as `unattributed`.
 *
 * THE THREE VERDICTS ON A DECLARED EFFECT
 * ---------------------------------------
 *   `exercised`   — at least one observed write matched its selector.
 *   `unexercised` — none did. EXPLICITLY NOT AN ERROR. An adapter whose action
 *                 never fired during the observed window is the ordinary case,
 *                 and treating a declaration nothing exercised as a defect
 *                 would punish exactly the over-declaration this report exists
 *                 to reward.
 *   `unobservable` — the selector's scope is not `database_checkpoint`, so the
 *                 journal cannot see it at all and silence about it is not
 *                 evidence in either direction.
 *
 * WHY TRIGGERS ARE MATCHED EVEN THOUGH THEY ARE REDUNDANT TODAY
 * -------------------------------------------------------------
 * Every `option:`/`table:` trigger the 16 shipped manifests declare is also
 * classified by the SAME manifest (verified by walking `Policy::actions()`
 * against `option_rule_details()`/`declared_table_details()`: zero
 * disagreements). So trigger matching changes no shipped verdict. It stays
 * because the day a manifest declares a trigger on a surface it does not
 * classify, the trigger write would otherwise be reported as a finding — a
 * false positive on the exact axis this report publishes a rate for.
 * `post:`/`term:` triggers name entity kinds, not `(table, item)` journal
 * coordinates, so they are deliberately not mapped.
 */
final class EffectDeclarationCoverage {
    public const FORMAT = 'duo-effect-declaration-coverage/v1';

    /** Closed verdict set for one observed `(table, item)` surface. */
    public const WRITE_VERDICTS = ['classified', 'declared', 'outside_declaration'];

    /** Closed verdict set for one declared effect. */
    public const EFFECT_VERDICTS = ['exercised', 'unexercised', 'unobservable'];

    /** The one selector scope a database write can ever be inside. */
    private const OBSERVABLE_SCOPE = 'database_checkpoint';

    /**
     * Read the target's existing journal and score it. Never repairs: no
     * `Ledger::ensure()` anywhere on this path, so a missing journal refuses
     * (see `assert_journal_prerequisite()`) instead of being created and then
     * read back as "this site performed no writes".
     *
     * @param list<string> $manifestNames the same `--manifests` selection
     *        `wp duo journal-report` takes, resolved the same way.
     * @return array<string,mixed>
     */
    public static function report(array $manifestNames): array {
        self::assert_journal_prerequisite();
        $policy = Policy::load(null, $manifestNames);
        return self::from_facts($policy, Journal::report_read_only($policy));
    }

    /**
     * Pure projection seam. The offline regression supplies existing-path
     * shaped journal facts here, so the scoring contract can be proven without
     * a database — the same split `AdapterObservation::from_facts()` uses
     * (AdapterObservation.php:100-117).
     *
     * @param array<string,mixed> $journal a `Journal::report_read_only()` document
     * @return array<string,mixed>
     */
    public static function from_facts(Policy $policy, array $journal): array {
        $observed = self::observed_surfaces($journal);
        $adapters = self::declared($policy);
        $names = array_keys($adapters);

        $unattributed = [];
        foreach ($observed as $surface) {
            $claimed = false;
            foreach ($names as $name) {
                $verdict = self::score_surface($policy, $adapters[$name], $name, $surface);
                if ($verdict === null) {
                    continue;
                }
                $claimed = true;
                $adapters[$name]['surfaces']++;
                $adapters[$name]['observations'] += $surface['observations'];
                if ($verdict === 'declared') {
                    $adapters[$name]['declared_writes'] += $surface['observations'];
                } elseif ($verdict === 'classified') {
                    $adapters[$name]['classified_writes'] += $surface['observations'];
                } else {
                    $adapters[$name]['findings'][] = [
                        'item' => $surface['item'],
                        'observations' => $surface['observations'],
                        'table' => $surface['table'],
                    ];
                }
            }
            if (!$claimed) {
                $unattributed[] = [
                    'item' => $surface['item'],
                    'observations' => $surface['observations'],
                    'table' => $surface['table'],
                ];
            }
        }

        return self::project($adapters, $unattributed);
    }

    /**
     * Collapse the journal's `(tbl, item, surface, caps, proposal)` grouping
     * onto the `(table, item)` coordinate an effect selector actually speaks.
     * Surface and capability are provenance questions — Journal's own report
     * answers those — and carrying them here would report one write five times
     * because five contexts produced it.
     *
     * @param array<string,mixed> $journal
     * @return list<array{item:string, observations:int, table:string}>
     */
    private static function observed_surfaces(array $journal): array {
        $rows = $journal['rows'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            self::refuse_invalid_facts();
        }
        $merged = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['table'] ?? null) || !is_string($row['item'] ?? null)) {
                self::refuse_invalid_facts();
            }
            $count = $row['n'] ?? null;
            if (!is_int($count) || $count < 0) {
                self::refuse_invalid_facts();
            }
            $key = $row['table'] . "\0" . $row['item'];
            if (!isset($merged[$key])) {
                $merged[$key] = ['item' => $row['item'], 'observations' => 0, 'table' => $row['table']];
            }
            $merged[$key]['observations'] += $count;
        }
        ksort($merged, SORT_STRING);
        return array_values($merged);
    }

    /**
     * The declared half: every pinned adapter, with its effect rows split into
     * the scorable and the unobservable.
     *
     * `Policy::effects_inventory()` is the input rather than the raw manifest
     * because it is the exact projection the recovery controller consumes
     * (Policy.php:2954-2960), synthesized irreversible rows included — scoring
     * anything else would score a document recovery never sees.
     *
     * @return array<string,array<string,mixed>>
     */
    private static function declared(Policy $policy): array {
        $adapters = [];
        foreach ($policy->manifests as $manifest) {
            $name = (string) ($manifest['name'] ?? '');
            if ($name === '') {
                continue;
            }
            $adapters[$name] = [
                'classified_writes' => 0,
                'declared_writes' => 0,
                'effects' => [],
                'exercised' => [],
                'findings' => [],
                'observations' => 0,
                'surfaces' => 0,
                'triggers' => [],
                'unobservable' => 0,
            ];
        }
        foreach ($policy->effects_inventory() as $row) {
            $name = (string) ($row['manifest'] ?? '');
            // effects_inventory() prepends engine-owned `core` rebuild rows
            // unconditionally (Policy.php:2963-3016). Attributing them to an
            // adapter that is not pinned would invent a sixteenth-plus-one
            // adapter nobody selected.
            if (!isset($adapters[$name])) {
                continue;
            }
            $effect = is_array($row['effect'] ?? null) ? $row['effect'] : [];
            $selector = is_array($effect['selector'] ?? null) ? $effect['selector'] : [];
            if ((string) ($selector['scope'] ?? '') !== self::OBSERVABLE_SCOPE) {
                $adapters[$name]['unobservable']++;
                continue;
            }
            $adapters[$name]['effects'][] = [
                'id' => (string) ($effect['id'] ?? ''),
                'phase' => (string) ($row['phase'] ?? ''),
                'selector' => $selector,
            ];
        }
        foreach ($policy->actions() as $action) {
            $name = (string) ($action['manifest'] ?? '');
            if (!isset($adapters[$name])) {
                continue;
            }
            foreach ((array) ($action['triggers'] ?? []) as $trigger) {
                if (is_string($trigger)) {
                    $adapters[$name]['triggers'][$trigger] = true;
                }
            }
        }
        ksort($adapters, SORT_STRING);
        return $adapters;
    }

    /**
     * One adapter's verdict on one observed surface, or null when the surface
     * is not in that adapter's territory at all.
     *
     * `option_namespace()` refuses outright when two manifests claim the same
     * option name (OptionNamespaceResolver.php:27-33). That refusal is not
     * caught here: overlapping discovery ownership is a policy defect the same
     * `Pending::scan()` path already refuses on, and swallowing it would let
     * this report print a score computed from an ownership map nobody agreed
     * on. It still cannot block an apply — nothing on that path calls here.
     *
     * @param array<string,mixed> $adapter
     * @param array{item:string, observations:int, table:string} $surface
     * @return ?string one of self::WRITE_VERDICTS, or null when out of territory
     */
    private static function score_surface(
        Policy $policy,
        array &$adapter,
        string $name,
        array $surface
    ): ?string {
        $matched = [];
        foreach ($adapter['effects'] as $effect) {
            if (self::selector_matches($effect['selector'], $surface['table'], $surface['item'])) {
                $matched[] = $effect['id'];
            }
        }
        if ($matched !== []) {
            foreach ($matched as $id) {
                $adapter['exercised'][$id] = true;
            }
            return 'declared';
        }
        if (Journal::ground_truth_details($policy, $surface['table'], $surface['item'])['source'] === $name) {
            return 'classified';
        }
        foreach (array_keys($adapter['triggers']) as $trigger) {
            if (self::trigger_matches((string) $trigger, $surface['table'], $surface['item'])) {
                return 'classified';
            }
        }
        if ($surface['table'] === 'options' && $surface['item'] !== ''
            && ($policy->option_namespace($surface['item'])['owner'] ?? null) === $name) {
            return 'outside_declaration';
        }
        return null;
    }

    /** @param array<string,mixed> $selector */
    private static function selector_matches(array $selector, string $table, string $item): bool {
        $value = (string) ($selector['value'] ?? '');
        return match ((string) ($selector['type'] ?? '')) {
            'table' => $value === $table,
            'option' => $table === 'options' && $value === $item,
            default => false,
        };
    }

    /**
     * `post:`/`term:` triggers name an entity kind, not a `(table, item)`
     * coordinate: a `post:product` write lands in `posts` and `postmeta` under
     * keys this scorer cannot tie back to the post type without the ledger.
     * Mapping them by guess would manufacture `classified` verdicts, which is
     * the one direction a report-only scorer must never err in — it would hide
     * findings rather than produce them.
     */
    private static function trigger_matches(string $trigger, string $table, string $item): bool {
        if (str_starts_with($trigger, 'option:')) {
            return $table === 'options' && substr($trigger, 7) === $item;
        }
        if (str_starts_with($trigger, 'table:')) {
            return substr($trigger, 6) === $table;
        }
        return false;
    }

    /**
     * @param array<string,array<string,mixed>> $adapters
     * @param list<array<string,mixed>> $unattributed
     * @return array<string,mixed>
     */
    private static function project(array $adapters, array $unattributed): array {
        $rows = [];
        $totals = [
            'adapters' => count($adapters),
            'declared_effects' => 0,
            'observable_effects' => 0,
            'observations' => 0,
            'outside_declaration' => 0,
            'scorable_adapters' => 0,
            'unexercised' => 0,
        ];
        $scoredSurfaces = 0;
        $findingSurfaces = 0;
        foreach ($adapters as $name => $adapter) {
            $observable = count($adapter['effects']);
            $unexercised = [];
            foreach ($adapter['effects'] as $effect) {
                if (!isset($adapter['exercised'][$effect['id']])) {
                    $unexercised[$effect['id']] = true;
                }
            }
            $unexercised = array_keys($unexercised);
            sort($unexercised, SORT_STRING);
            // Already in `table\0item` order: observed_surfaces() ksorts on
            // that exact key and findings are appended in that walk's order.
            $findings = $adapter['findings'];
            $rows[] = [
                'adapter' => $name,
                'classified_writes' => $adapter['classified_writes'],
                'declared_effects' => $observable + $adapter['unobservable'],
                'declared_writes' => $adapter['declared_writes'],
                'exercised_effects' => count($adapter['exercised']),
                'observable_effects' => $observable,
                'observations' => $adapter['observations'],
                'observed_surfaces' => $adapter['surfaces'],
                'outside_declaration' => $findings,
                // Silence about an adapter that declares no journal-observable
                // effect is not a clean score, and this flag is what keeps a
                // reader from summing it as one.
                'scorable' => $observable > 0,
                'unexercised_effects' => $unexercised,
                'unobservable_effects' => $adapter['unobservable'],
            ];
            $totals['declared_effects'] += $observable + $adapter['unobservable'];
            $totals['observable_effects'] += $observable;
            $totals['observations'] += $adapter['observations'];
            $totals['outside_declaration'] += count($findings);
            $totals['scorable_adapters'] += $observable > 0 ? 1 : 0;
            $totals['unexercised'] += count($unexercised);
            $scoredSurfaces += $adapter['surfaces'];
            $findingSurfaces += count($findings);
        }
        return [
            'adapters' => $rows,
            // The number the risk field asks to be PUBLISHED. It is a rate over
            // scored (adapter, surface) pairs, not over journal rows, because a
            // surface claimed by three adapters is three independent judgements
            // and a rate that counted it once would understate the exposure.
            'baseline' => [
                'outside_declaration_rate' => $scoredSurfaces > 0
                    ? round($findingSurfaces / $scoredSurfaces, 4)
                    : null,
                'outside_declaration_surfaces' => $findingSurfaces,
                'scored_surfaces' => $scoredSurfaces,
            ],
            // Not an authority and never a gate: stated in the document so a
            // consumer cannot promote this report into one by accident.
            'authority' => false,
            'blocking' => false,
            'format' => self::FORMAT,
            'totals' => $totals,
            'unattributed' => $unattributed,
        ];
    }

    /**
     * A missing journal is a typed refusal, never an empty result.
     *
     * The failure this closes is specific: read a site with no journal table,
     * get zero rows, and every adapter scores a perfect clean sheet — the
     * report would publish "no write fell outside a declaration" from a site
     * that recorded no writes at all. `Journal::table_state()` separates
     * "absent" from "unreadable" so the two get different reason codes; both
     * refuse.
     */
    private static function assert_journal_prerequisite(): void {
        global $wpdb;
        $state = Journal::table_state($wpdb);
        if ($state === 'unreadable') {
            throw new CommandRefusalException(
                'effect_coverage_journal_unreadable',
                'effect declaration coverage could not read the existing provenance journal',
                'restore readable provenance state, then score effect declarations again',
                [[
                    'code' => 'effect_coverage_journal_unreadable',
                    'message' => 'a failed journal read is not evidence that no write was observed',
                    'remediation' => 'repair the journal through the existing controlled workflow before scoring effect declarations',
                ]],
                'duo: effect declaration coverage refused because the provenance journal probe failed'
            );
        }
        if ($state !== 'present') {
            throw new CommandRefusalException(
                'effect_coverage_journal_absent',
                'effect declaration coverage requires an existing provenance journal table',
                'enable the provenance journal and let it observe a representative window, then score effect declarations again',
                [[
                    'code' => 'effect_coverage_journal_absent',
                    'message' => 'an absent journal cannot be scored as an adapter that wrote nothing outside its declarations',
                    'remediation' => 'install or repair the agent through the existing controlled workflow before scoring effect declarations',
                ]],
                'duo: effect declaration coverage refused because its provenance journal prerequisite is absent'
            );
        }
    }

    /** The projection seam is fed existing-path facts; a malformed one is a bug, not a site condition. */
    private static function refuse_invalid_facts(): never {
        throw new CommandRefusalException(
            'effect_coverage_invalid_facts',
            'effect declaration coverage received journal facts it cannot score',
            'collect the journal aggregate through the existing read-only report before scoring effect declarations',
            [[
                'code' => 'effect_coverage_invalid_facts',
                'message' => 'the scorer will not guess at a journal row it cannot read',
                'remediation' => 'supply the exact read-only journal aggregate this report consumes',
            ]],
            'duo: effect declaration coverage refused a malformed journal aggregate'
        );
    }
}
