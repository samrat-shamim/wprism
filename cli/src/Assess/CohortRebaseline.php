<?php
declare(strict_types=1);

namespace WPrism\Orchestrator;

require_once __DIR__ . '/FleetCensus.php';

/**
 * `wprism census --baseline=` — the cohort re-baseline: what moved between two
 * censuses, and whether an adapter cohort is the thing that moved it.
 *
 * ## The question this exists to make answerable
 *
 * `FleetCensus` publishes a coverage ratio, an adoption funnel and a demand
 * rank for one moment. The coverage push (WP-6.3) is not graded on a moment:
 * its exit criterion is MOVEMENT — "a re-measured fleet coverage ratio and
 * adoption funnel against the Phase-0 baseline, published with the residual
 * named. A cohort that ships every adapter but does not move the ratio is a
 * FINDING that re-opens G0, not a success."
 *
 * Two census documents side by side is the whole instrument, and until this
 * file existed the comparison was a human reading two JSON blobs — which is
 * exactly the shape of measurement that reports success. So the delta is
 * computed here, and the one outcome the program is most likely to hit and
 * least likely to admit gets its own word in a closed vocabulary:
 * `shipped_without_movement` (`VERDICT_SHIPPED_NO_MOVEMENT`), published with a
 * `finding` object beside it. A cohort cannot ship adapters and read as
 * anything else.
 *
 * ## Attribution is per adapter, and it is per SURFACE
 *
 * "Coverage went up" is not evidence that a cohort did it. `attribution.moved`
 * carries one row per plugin slug that moved, naming the adapter that covered
 * it before, the adapter that covers it now, and the exact surface ids that
 * crossed between `uncovered_surfaces` and `claimed_surfaces` — the two lists
 * `FleetCensus::demand_rows()` already publishes per slug. A surface credited
 * to a new adapter is `surfaces_claimed`; one that lost its credit is
 * `surfaces_regressed`, and it is reported with the same weight, because a
 * disposition narrowed in the same release that shipped a cohort is precisely
 * the movement a net ratio hides.
 *
 * WHAT THE FOUR LISTS PARTITION, said exactly, because the boundary matters.
 * `uncovered_surfaces ∪ claimed_surfaces` is the set of surface ids the estate
 * was OBSERVED to hold for that slug, split by whether a reviewed claim credits
 * them (FleetCensus.php:918-941). The four lists are the movements OF THE
 * RESIDUAL — every crossing into, out of, or within `uncovered_surfaces`. Two
 * movements are therefore deliberately not here: a surface that arrives already
 * credited, and one that leaves while credited. Both are the estate's DATA
 * changing under a claim that never moved, so no adapter may be credited or
 * blamed for either; they show up in `coverage.delta.total` and
 * `coverage.delta.covered`, which is the block that is about the denominator.
 * `attribution.unchanged` counts rows with no residual, adapter, stage or pin
 * movement, and means exactly that — not "this slug is identical".
 *
 * Rows that did not move are a COUNT, not a listing (`GapActions.php:152-156`,
 * "the count is the signal"): an empty `moved` list beside `unchanged: 5` says
 * "nothing moved, and here is how much nothing" rather than leaving a reader
 * to infer it from an absent key.
 *
 * ## Comparability is published, never assumed
 *
 * A delta between two censuses over DIFFERENT estates is not a delta of
 * anything. `comparability.class` is one of `same-estate`,
 * `population-changed` or `disjoint-estate`, computed from the opaque site
 * labels alone, and `cohort.attributable` is false for the latter two — the
 * numbers are still published, because refusing to print them would hide the
 * movement that did happen, but nothing in the document calls them a cohort's
 * work.
 *
 * Two incomparabilities are REFUSALS instead, because they change what the
 * ratio means rather than what it measures:
 *
 *   - a different `library.site_mode`: the platform boundary decides which
 *     submissions are eligible at all (`FleetCensus::project()`'s excluded
 *     population), so two censuses taken under different boundaries divide by
 *     denominators that were built by different rules;
 *   - a document that is not `wprism-fleet-census/v1`, or one missing a block
 *     every delta below reads.
 *
 * An engine move is NOT a refusal and is deliberately not one: the program
 * this verb was built for crosses a spec flag day between its baseline and its
 * re-measurement, and a re-baseline that refused to look across the flip would
 * be unable to answer the only question anyone asked. `comparability.engine`
 * names both engines and whether they differ.
 *
 * ## Still 0/1/2
 *
 * A finding is an ANSWER, not a refusal, and it does not get its own exit
 * code — the same ruling `FleetCensus` records for a narrow sample
 * (FleetCensus.php:152-156: "no 'answer with a caveat' code … a fact inside
 * the document, not a different outcome for a CI job to branch on"). A caller
 * that wants to gate on the finding reads `cohort.verdict`, which is a closed
 * vocabulary precisely so that it can.
 */
final class CohortRebaseline {
    public const FORMAT = 'wprism-cohort-rebaseline/v1';

    /**
     * The closed verdict vocabulary. A reader may switch on this; a value
     * outside the list is a bug in this file rather than a new outcome, which
     * is why `verdict()` below ends in an unconditional branch and not a
     * default that invents a word.
     */
    public const VERDICT_NO_COHORT = 'no_cohort';
    public const VERDICT_COVERAGE_MOVED = 'coverage_moved';
    public const VERDICT_ADOPTION_ONLY = 'adoption_only';
    public const VERDICT_SHIPPED_NO_MOVEMENT = 'shipped_without_movement';
    public const VERDICT_COVERAGE_REGRESSED = 'coverage_regressed';
    public const VERDICT_UNMEASURED = 'unmeasured';

    public const VERDICTS = [
        self::VERDICT_NO_COHORT,
        self::VERDICT_COVERAGE_MOVED,
        self::VERDICT_ADOPTION_ONLY,
        self::VERDICT_SHIPPED_NO_MOVEMENT,
        self::VERDICT_COVERAGE_REGRESSED,
        self::VERDICT_UNMEASURED,
    ];

    /** The closed comparability vocabulary, computed from site labels alone. */
    public const COMPARABILITY_CLASSES = ['same-estate', 'population-changed', 'disjoint-estate'];

    /**
     * The four counters `FleetCensus::funnel()` publishes per stage. Restated
     * here so this projector is closed over them: a counter a newer census
     * gains is not silently folded into a delta nobody defined.
     */
    public const FUNNEL_COUNTERS = ['plugins', 'sites_installed', 'sites_pinning', 'uncovered_surfaces'];

    /** The five `fleet` totals every ratio in the census is built from. */
    private const FLEET_COUNTERS = ['total', 'covered', 'pending', 'uncovered'];

    /**
     * One re-baseline over two already-read census documents.
     *
     * No filesystem, exactly as `FleetCensus::project()` takes none: the delta,
     * the attribution and the verdict are the subject, and a suite that had to
     * write files to reach them would be testing `file_get_contents`.
     *
     * @param array<string,mixed> $baseline a `wprism-fleet-census/v1` document
     * @param array<string,mixed> $current  a `wprism-fleet-census/v1` document
     * @return array<string,mixed> a `wprism-cohort-rebaseline/v1` document
     */
    public static function project(array $baseline, array $current): array {
        $before = self::census('baseline', $baseline);
        $after = self::census('current', $current);

        if ($before['site_mode'] !== $after['site_mode']) {
            throw new FleetCensusRefusal(
                'rebaseline_boundary_mismatch',
                'the two censuses were taken under different platform boundaries, so their denominators were built by different rules',
                're-measure the current estate against the same platform boundary the baseline used, then re-run the re-baseline',
                'baseline site_mode ' . $before['site_mode'] . ' vs current site_mode ' . $after['site_mode']
            );
        }

        $comparability = self::comparability($before, $after);
        $attribution = self::attribution($before['demand'], $after['demand']);
        $cohort = self::cohort($before, $after, $attribution, $comparability);

        return [
            'format' => self::FORMAT,
            'spec_version' => defined('WPRISM_SPEC_VERSION') ? (int) WPRISM_SPEC_VERSION : 0,
            'agent_version' => defined('WPRISM_AGENT_VERSION') ? (string) WPRISM_AGENT_VERSION : 'unknown',
            'comparability' => $comparability,
            'coverage' => self::coverage($before, $after),
            'funnel' => self::funnel($before['funnel'], $after['funnel']),
            'attribution' => $attribution,
            'cohort' => $cohort,
        ];
    }

    /**
     * The WHITELIST projection of one census document. Every field a
     * re-baseline can ever read is named here, for the reason
     * `FleetCensus::submission()` gives one level down: the producing verb's
     * future fields were never scoped for this comparison, and a delta over a
     * key nobody defined a delta for is a number with no meaning.
     *
     * @param array<string,mixed> $document
     * @return array<string,mixed>
     */
    private static function census(string $side, array $document): array {
        if (($document['format'] ?? null) !== FleetCensus::FORMAT) {
            throw new FleetCensusRefusal(
                'rebaseline_document_unsupported',
                "the $side document is not a " . FleetCensus::FORMAT . ' document',
                'produce both sides with `wprism census --format=json` and pass those documents unmodified'
            );
        }
        foreach (['library', 'fleet', 'funnel', 'demand', 'sites', 'basis'] as $block) {
            if (!is_array($document[$block] ?? null)) {
                throw new FleetCensusRefusal(
                    'rebaseline_document_incomplete',
                    "the $side document carries no '$block' block, so no delta can be computed from it",
                    're-measure that side with a build that emits the whole ' . FleetCensus::FORMAT . ' document',
                    "missing block: $block"
                );
            }
        }

        $library = (array) $document['library'];
        $siteMode = is_string($library['site_mode'] ?? null) ? (string) $library['site_mode'] : '';
        if ($siteMode === '') {
            throw new FleetCensusRefusal(
                'rebaseline_document_incomplete',
                "the $side document names no library.site_mode, so its denominator cannot be compared with the other side's",
                're-measure that side with a build that publishes library.site_mode',
                "$side: library.site_mode"
            );
        }

        $labels = [];
        foreach ((array) $document['sites'] as $row) {
            if (is_array($row) && is_string($row['label'] ?? null) && $row['label'] !== '') {
                $labels[(string) $row['label']] = true;
            }
        }
        ksort($labels, SORT_STRING);

        $fleet = (array) $document['fleet'];
        $totals = [];
        foreach (self::FLEET_COUNTERS as $counter) {
            $totals[$counter] = is_int($fleet[$counter] ?? null) ? (int) $fleet[$counter] : 0;
        }
        // Null survives rather than becoming 0: `FleetCensus::ppm()` returns
        // null where nothing was measured, and a re-baseline that read that as
        // "0%" would publish a collapse that never happened.
        $totals['covered_ppm'] = is_int($fleet['covered_ppm'] ?? null) ? (int) $fleet['covered_ppm'] : null;

        $funnel = [];
        foreach (FleetCensus::FUNNEL_STAGES as $stage) {
            $row = is_array(((array) $document['funnel'])[$stage] ?? null)
                ? (array) ((array) $document['funnel'])[$stage]
                : [];
            $counters = [];
            foreach (self::FUNNEL_COUNTERS as $counter) {
                $counters[$counter] = is_int($row[$counter] ?? null) ? (int) $row[$counter] : 0;
            }
            $funnel[$stage] = $counters;
        }

        $demand = [];
        foreach ((array) $document['demand'] as $row) {
            if (!is_array($row) || !is_string($row['slug'] ?? null) || $row['slug'] === '') {
                continue;
            }
            $adapter = $row['covering_adapter'] ?? null;
            $demand[(string) $row['slug']] = [
                'slug' => (string) $row['slug'],
                'covering_adapter' => is_string($adapter) && $adapter !== '' ? $adapter : null,
                'funnel_stage' => in_array($row['funnel_stage'] ?? null, FleetCensus::FUNNEL_STAGES, true)
                    ? (string) $row['funnel_stage']
                    : 'no_adapter',
                'sites_installed' => is_int($row['sites_installed'] ?? null) ? (int) $row['sites_installed'] : 0,
                'sites_pinning' => is_int($row['sites_pinning'] ?? null) ? (int) $row['sites_pinning'] : 0,
                'demand_score' => is_int($row['demand_score'] ?? null) ? (int) $row['demand_score'] : 0,
                'uncovered_surfaces' => self::surface_ids($row['uncovered_surfaces'] ?? null),
                'claimed_surfaces' => self::surface_ids($row['claimed_surfaces'] ?? null),
            ];
        }
        ksort($demand, SORT_STRING);

        $basis = (array) $document['basis'];

        return [
            'side' => $side,
            'site_mode' => $siteMode,
            'labels' => array_keys($labels),
            'fleet' => $totals,
            'funnel' => $funnel,
            'demand' => $demand,
            'library' => [
                'adapters' => is_int($library['adapters'] ?? null) ? (int) $library['adapters'] : 0,
                'reviewed' => is_int($library['reviewed'] ?? null) ? (int) $library['reviewed'] : 0,
                'surfaces_sha256' => is_string($library['surfaces_sha256'] ?? null)
                    ? (string) $library['surfaces_sha256']
                    : '',
            ],
            'engine' => [
                'spec_version' => is_int($document['spec_version'] ?? null) ? (int) $document['spec_version'] : 0,
                'agent_version' => is_string($document['agent_version'] ?? null)
                    ? (string) $document['agent_version']
                    : 'unknown',
            ],
            'basis' => [
                'sites' => is_int($basis['sites'] ?? null) ? (int) $basis['sites'] : count($labels),
                'sample_class' => is_string($basis['sample_class'] ?? null) ? (string) $basis['sample_class'] : 'unknown',
            ],
        ];
    }

    /**
     * The `options:<prefix>` / `tables:<logical>` ids the census publishes,
     * deduplicated and sorted so a set comparison is a set comparison.
     *
     * @return list<string>
     */
    private static function surface_ids(mixed $rows): array {
        $out = [];
        foreach ((array) $rows as $id) {
            if (is_string($id) && $id !== '') {
                $out[$id] = true;
            }
        }
        ksort($out, SORT_STRING);
        return array_keys($out);
    }

    /**
     * Whether these two documents describe the same estate, said out loud.
     *
     * The comparison is on the opaque site LABELS, which is the only site
     * identity a census carries (`FleetCensus::LABEL_PATTERN`) — so this
     * answer costs nothing in redaction and is exact rather than heuristic.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,mixed>
     */
    private static function comparability(array $before, array $after): array {
        $baselineLabels = (array) $before['labels'];
        $currentLabels = (array) $after['labels'];
        $added = array_values(array_diff($currentLabels, $baselineLabels));
        $removed = array_values(array_diff($baselineLabels, $currentLabels));
        $common = array_values(array_intersect($baselineLabels, $currentLabels));
        sort($added, SORT_STRING);
        sort($removed, SORT_STRING);

        if ($added === [] && $removed === []) {
            $class = 'same-estate';
            $disclosure = 'both censuses fold the same ' . count($common)
                . ' labelled site(s), so every delta below is a delta of one estate over time';
        } elseif ($common === []) {
            $class = 'disjoint-estate';
            $disclosure = 'the two censuses share NO site label, so the deltas below compare two different estates: '
                . 'nothing in this document attributes movement to an adapter cohort';
        } else {
            $class = 'population-changed';
            $disclosure = 'the estate changed between the two censuses (' . count($added) . ' added, '
                . count($removed) . ' removed, ' . count($common)
                . ' in common), so the ratio delta mixes cohort effect with population change';
        }

        $libraryMoved = $before['library']['surfaces_sha256'] !== $after['library']['surfaces_sha256'];
        $engineMoved = $before['engine'] !== $after['engine'];

        return [
            'class' => $class,
            'sites' => [
                'baseline' => count($baselineLabels),
                'current' => count($currentLabels),
                'common' => count($common),
                'added' => $added,
                'removed' => $removed,
            ],
            'library' => [
                'baseline_surfaces_sha256' => (string) $before['library']['surfaces_sha256'],
                'current_surfaces_sha256' => (string) $after['library']['surfaces_sha256'],
                // The content address of the coverage ORACLE, not of the
                // manifest tree: equal shas mean no reviewed claim derives a
                // different surface set on either side, which is the exact
                // fact a cohort has to move and a refactor must not.
                'moved' => $libraryMoved,
                'baseline_adapters' => (int) $before['library']['adapters'],
                'current_adapters' => (int) $after['library']['adapters'],
                'baseline_reviewed' => (int) $before['library']['reviewed'],
                'current_reviewed' => (int) $after['library']['reviewed'],
            ],
            'engine' => [
                'baseline' => $before['engine'],
                'current' => $after['engine'],
                // Not a refusal: a re-baseline whose whole purpose is to
                // measure a program that crossed a spec flag day must be able
                // to look across it.
                'moved' => $engineMoved,
            ],
            'basis' => [
                'baseline' => $before['basis'],
                'current' => $after['basis'],
            ],
            'disclosure' => $disclosure,
        ];
    }

    /**
     * The coverage ratio, both sides and the difference.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @return array<string,mixed>
     */
    private static function coverage(array $before, array $after): array {
        $baseline = (array) $before['fleet'];
        $current = (array) $after['fleet'];
        $delta = [];
        foreach (self::FLEET_COUNTERS as $counter) {
            $delta[$counter] = (int) $current[$counter] - (int) $baseline[$counter];
        }
        // Null propagates: a side that measured no surfaces has no ratio, and
        // subtracting from nothing is not zero.
        $delta['covered_ppm'] = is_int($baseline['covered_ppm']) && is_int($current['covered_ppm'])
            ? $current['covered_ppm'] - $baseline['covered_ppm']
            : null;

        return [
            'baseline' => $baseline,
            'current' => $current,
            'delta' => $delta,
        ];
    }

    /**
     * Funnel movement, one row per stage including the stages that did not
     * move — `FleetCensus::funnel()`'s own posture, for the same reason.
     *
     * @param array<string,array<string,int>> $baseline
     * @param array<string,array<string,int>> $current
     * @return array<string,mixed>
     */
    private static function funnel(array $baseline, array $current): array {
        $out = [];
        foreach (FleetCensus::FUNNEL_STAGES as $stage) {
            $delta = [];
            foreach (self::FUNNEL_COUNTERS as $counter) {
                $delta[$counter] = $current[$stage][$counter] - $baseline[$stage][$counter];
            }
            $out[$stage] = [
                'baseline' => $baseline[$stage],
                'current' => $current[$stage],
                'delta' => $delta,
            ];
        }
        return $out;
    }

    /**
     * Per-slug attribution: which adapter's claim moved which surfaces.
     *
     * A row is emitted only when something about it moved. That is not
     * brevity — an operator reading a cohort's result needs the movers and
     * nothing else, and a listing of every unchanged plugin is where the two
     * rows that matter go to hide. The unchanged population is a count beside
     * the list so its absence is a number rather than an inference.
     *
     * @param array<string,array<string,mixed>> $baseline slug => row
     * @param array<string,array<string,mixed>> $current  slug => row
     * @return array<string,mixed>
     */
    private static function attribution(array $baseline, array $current): array {
        $slugs = array_keys($baseline + $current);
        sort($slugs, SORT_STRING);

        $moved = [];
        $unchanged = 0;
        foreach ($slugs as $slug) {
            $was = $baseline[$slug] ?? null;
            $now = $current[$slug] ?? null;

            $wasUncovered = $was === null ? [] : (array) $was['uncovered_surfaces'];
            $wasClaimed = $was === null ? [] : (array) $was['claimed_surfaces'];
            $nowUncovered = $now === null ? [] : (array) $now['uncovered_surfaces'];
            $nowClaimed = $now === null ? [] : (array) $now['claimed_surfaces'];

            // The four movements OF THE RESIDUAL (the header's partition, and
            // its stated boundary). `surfaces_claimed` is the only one a cohort
            // is allowed to take credit for; the other three are published with
            // equal weight because each of them can move a net ratio without an
            // adapter having done anything.
            $claimed = array_values(array_intersect($wasUncovered, $nowClaimed));
            $regressed = array_values(array_intersect($wasClaimed, $nowUncovered));
            $appeared = array_values(array_diff($nowUncovered, $wasUncovered, $wasClaimed));
            $resolved = array_values(array_diff($wasUncovered, $nowUncovered, $nowClaimed));
            sort($claimed, SORT_STRING);
            sort($regressed, SORT_STRING);
            sort($appeared, SORT_STRING);
            sort($resolved, SORT_STRING);

            $adapterBefore = $was === null ? null : $was['covering_adapter'];
            $adapterAfter = $now === null ? null : $now['covering_adapter'];
            $change = self::adapter_change($adapterBefore, $adapterAfter);

            $pinningDelta = ($now === null ? 0 : (int) $now['sites_pinning'])
                - ($was === null ? 0 : (int) $was['sites_pinning']);
            $installedDelta = ($now === null ? 0 : (int) $now['sites_installed'])
                - ($was === null ? 0 : (int) $was['sites_installed']);
            $scoreDelta = ($now === null ? 0 : (int) $now['demand_score'])
                - ($was === null ? 0 : (int) $was['demand_score']);
            $stageBefore = $was === null ? null : (string) $was['funnel_stage'];
            $stageAfter = $now === null ? null : (string) $now['funnel_stage'];

            $didMove = $claimed !== [] || $regressed !== [] || $appeared !== [] || $resolved !== []
                || $change !== 'unchanged' || $pinningDelta !== 0 || $installedDelta !== 0
                || $stageBefore !== $stageAfter;
            if (!$didMove) {
                $unchanged++;
                continue;
            }

            $moved[] = [
                'slug' => $slug,
                'present' => self::presence($was !== null, $now !== null),
                'adapter_baseline' => $adapterBefore,
                'adapter_current' => $adapterAfter,
                'adapter_change' => $change,
                'stage_baseline' => $stageBefore,
                'stage_current' => $stageAfter,
                'surfaces_claimed' => $claimed,
                'surfaces_regressed' => $regressed,
                'surfaces_appeared' => $appeared,
                'surfaces_resolved' => $resolved,
                'sites_installed_delta' => $installedDelta,
                'sites_pinning_delta' => $pinningDelta,
                'demand_score_delta' => $scoreDelta,
            ];
        }

        // Most surface movement first, then the biggest adoption move, then
        // the slug: a total order that is a function of the two documents and
        // never of the order either one listed its rank in.
        usort($moved, static function (array $a, array $b): int {
            return count($b['surfaces_claimed']) <=> count($a['surfaces_claimed'])
                ?: count($b['surfaces_regressed']) <=> count($a['surfaces_regressed'])
                ?: $b['sites_pinning_delta'] <=> $a['sites_pinning_delta']
                ?: strcmp($a['slug'], $b['slug']);
        });

        return [
            'moved' => $moved,
            'unchanged' => $unchanged,
            'disclosure' => 'a row appears here only when its adapter, its funnel stage, its residual surfaces or '
                . 'its pin count moved; the ' . $unchanged . ' unchanged slug row(s) are counted rather than listed. '
                . 'A surface that arrived already credited, or left while credited, is the estate\'s data moving '
                . 'under a claim that did not: read coverage.delta.total and coverage.delta.covered for those',
        ];
    }

    /** `null` on one side is a plugin that entered or left the measured estate. */
    private static function presence(bool $inBaseline, bool $inCurrent): string {
        if ($inBaseline && $inCurrent) {
            return 'both';
        }
        return $inCurrent ? 'current-only' : 'baseline-only';
    }

    private static function adapter_change(?string $before, ?string $after): string {
        if ($before === $after) {
            return 'unchanged';
        }
        if ($before === null) {
            return 'adapter_added';
        }
        if ($after === null) {
            return 'adapter_removed';
        }
        return 'adapter_replaced';
    }

    /**
     * The cohort verdict — the one number WP-6.3's exit criterion reads, and
     * the one place in this program where the target itself is an acceptance
     * condition.
     *
     * @param array<string,mixed> $before
     * @param array<string,mixed> $after
     * @param array<string,mixed> $attribution
     * @param array<string,mixed> $comparability
     * @return array<string,mixed>
     */
    private static function cohort(array $before, array $after, array $attribution, array $comparability): array {
        $adaptersAdded = [];
        $slugsNewlyCovered = [];
        $slugsNewlyPinned = [];
        $slugsNewlyReviewed = [];
        $surfacesClaimed = 0;
        $surfacesRegressed = 0;

        foreach ((array) $attribution['moved'] as $row) {
            $row = (array) $row;
            $surfacesClaimed += count((array) $row['surfaces_claimed']);
            $surfacesRegressed += count((array) $row['surfaces_regressed']);
            if ($row['adapter_change'] === 'adapter_added' || $row['adapter_change'] === 'adapter_replaced') {
                $slugsNewlyCovered[] = (string) $row['slug'];
                if (is_string($row['adapter_current'])) {
                    $adaptersAdded[(string) $row['adapter_current']] = true;
                }
            }
            // An adapter that existed but claimed nothing until its review
            // landed is a cohort result too: `adapter_unreviewed` is the stage
            // `FleetCensus::stage()` mints for "the code shipped, the review
            // did not", and leaving it is the same win as shipping one.
            if ($row['stage_baseline'] === 'adapter_unreviewed' && $row['stage_current'] !== 'adapter_unreviewed'
                && $row['stage_current'] !== null) {
                $slugsNewlyReviewed[] = (string) $row['slug'];
            }
            if ((int) $row['sites_pinning_delta'] > 0) {
                $slugsNewlyPinned[] = (string) $row['slug'];
            }
        }
        $adaptersAdded = array_keys($adaptersAdded);
        sort($adaptersAdded, SORT_STRING);
        sort($slugsNewlyCovered, SORT_STRING);
        sort($slugsNewlyPinned, SORT_STRING);
        sort($slugsNewlyReviewed, SORT_STRING);

        $baselinePpm = $before['fleet']['covered_ppm'];
        $currentPpm = $after['fleet']['covered_ppm'];
        $deltaPpm = is_int($baselinePpm) && is_int($currentPpm) ? $currentPpm - $baselinePpm : null;
        $shipped = $adaptersAdded !== [] || $slugsNewlyCovered !== [] || $slugsNewlyReviewed !== [];

        $verdict = self::verdict($shipped, $deltaPpm);
        $attributable = $comparability['class'] === 'same-estate';

        return [
            'adapters_added' => $adaptersAdded,
            'slugs_newly_covered' => $slugsNewlyCovered,
            'slugs_newly_reviewed' => $slugsNewlyReviewed,
            'slugs_newly_pinned' => $slugsNewlyPinned,
            'surfaces_claimed' => $surfacesClaimed,
            'surfaces_regressed' => $surfacesRegressed,
            'coverage_delta_ppm' => $deltaPpm,
            'shipped' => $shipped,
            'verdict' => $verdict,
            'attributable' => $attributable,
            'finding' => self::finding($verdict, $adaptersAdded, $slugsNewlyCovered, $deltaPpm, $surfacesRegressed),
            'disclosure' => $attributable
                ? 'the estate is unchanged, so this ratio delta is attributable to the library and the pins alone'
                : 'the estate is NOT unchanged between the two censuses, so this ratio delta is not attributable to a '
                    . 'cohort: read comparability.class before quoting it',
        ];
    }

    /** The closed decision. Six outcomes, no default. */
    private static function verdict(bool $shipped, ?int $deltaPpm): string {
        if ($deltaPpm === null) {
            return self::VERDICT_UNMEASURED;
        }
        if ($deltaPpm < 0) {
            return self::VERDICT_COVERAGE_REGRESSED;
        }
        if ($shipped) {
            // The whole reason this file exists. WP-6.3: "A cohort that ships
            // every adapter but does not move the ratio is a FINDING that
            // re-opens G0, not a success."
            return $deltaPpm > 0 ? self::VERDICT_COVERAGE_MOVED : self::VERDICT_SHIPPED_NO_MOVEMENT;
        }
        return $deltaPpm > 0 ? self::VERDICT_ADOPTION_ONLY : self::VERDICT_NO_COHORT;
    }

    /**
     * The finding object, or null. Only two verdicts carry one, and both are
     * the same kind of fact: the ratio did not do what shipping an adapter is
     * supposed to make it do.
     *
     * @param list<string> $adaptersAdded
     * @param list<string> $slugsNewlyCovered
     * @return array<string,mixed>|null
     */
    private static function finding(
        string $verdict,
        array $adaptersAdded,
        array $slugsNewlyCovered,
        ?int $deltaPpm,
        int $surfacesRegressed
    ): ?array {
        if ($verdict === self::VERDICT_SHIPPED_NO_MOVEMENT) {
            return [
                'code' => 'cohort_shipped_without_movement',
                'statement' => count($adaptersAdded) . ' adapter(s) newly cover ' . count($slugsNewlyCovered)
                    . ' plugin slug(s) and the fleet coverage ratio did not move: this is a FINDING that re-opens G0, '
                    . 'not a cohort success',
                'remedy' => 'read funnel.adapter_unpinned — an adapter that exists but is not pinned contributes '
                    . 'nothing to any site\'s coverage; if the pins are already in place, the cohort was ranked '
                    . 'against demand the estate does not actually carry and the demand rank is what has to be re-taken',
            ];
        }
        if ($verdict === self::VERDICT_COVERAGE_REGRESSED) {
            return [
                'code' => 'cohort_coverage_regressed',
                'statement' => 'the fleet coverage ratio fell by ' . abs((int) $deltaPpm)
                    . ' ppm between the two censuses, with ' . $surfacesRegressed
                    . ' surface(s) losing an adapter claim they previously had',
                'remedy' => 'read attribution.moved[].surfaces_regressed: a narrowed disposition, a dropped pin and a '
                    . 'newly-installed plugin are three different causes with three different remedies',
            ];
        }
        return null;
    }
}
