<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once __DIR__ . '/AdapterBoundary.php';

use Duo\Canon;

/**
 * `duo adapter proposals` — the scheduled half of the bisector: re-run the
 * PLANNER over every pinned plugin's recorded ledger, propose the range bump
 * the evidence supports, and derive how stale each adapter's proof is.
 *
 * ## The cost this exists to bound
 *
 * `AdapterContractGrammar.php:73-79` forbids an unbounded `version_range`, so
 * every adapter's claim is pinned to an exact window and every upstream release
 * train walks out from under it. Today the only staleness signal is prose: the
 * elementor disposition reads "Certified for exact Elementor 4.0.0 and 4.2.3…",
 * a sentence that reads identically in 2029 whether or not Elementor 6 shipped.
 * Nothing in the tree answers "which adapter's proof is furthest behind its
 * plugin's releases", because nothing derives it.
 *
 * `duo adapter boundary` already turns "try the versions" into O(log releases)
 * probes over a recorded release list. What it does not do is run itself — it
 * answers about ONE slug, from flags a human typed, starting at an anchor a
 * human named. This verb is the job around it: it reads the whole ledger
 * directory, derives each adapter's anchor from its OWN recorded outcomes, and
 * folds N searches into one document.
 *
 * ## Why a PROPOSAL and never an edit — and why that is structural here
 *
 * A range lives in two files. `manifests/<name>.json` declares
 * `version_range`, and `manifests/dispositions.json` restates it as
 * `supported_versions.range`; `ManifestDispositions.php:632-637` refuses the
 * pair the instant they disagree —
 * "certified manifest disposition '<name>' versions disagree with its manifest
 * contract" — comparing `Canon::encode()` of the two sides, so the agreement is
 * on canonical BYTES, not on `==`.
 *
 * So this verb emits BOTH edits, and derives both from ONE `$range` value
 * (`proposedEdits()` below), which is what makes the byte-equality a property
 * of the emitter rather than of the operator's copy-paste. It still stops
 * there, and the reason is AGENTS.md rule 2: a byte under `manifests/` is
 * adapter identity — `ArtifactPolicyIdentity::manifest_rows()` folds each
 * manifest's JSON and its disposition into the digest every deployed site pins
 * against, so a job that widened a range on a cron schedule would brick every
 * site holding a compiled artifact (`compiled_artifact_manifest_mismatch`)
 * without a human ever reading the evidence. A range bump is a reviewed human
 * edit. This document is the review packet for it.
 *
 * `has_write_path` is asserted in the corpus by hashing every file under
 * `manifests/` before and after the suite, exactly as the bisector's own suite
 * does: this file opens no path for writing at all.
 *
 * ## What is refused rather than proposed
 *
 * A proposal rests on a bisection that REACHED GREEN. Three ways it does not,
 * and all three are refusals with the adapter named, never a quiet omission:
 *
 *   `no_green_probe`          the outcome record holds no `green` at all, so
 *                             there is no anchor to search outward from and no
 *                             endpoint a proposal could name. A range proposed
 *                             from an all-failing record would be a claim with
 *                             its own evidence contradicting it.
 *   `bisection_incomplete`    the search returned `probe-required` or `blocked`
 *                             (an unresolvable artifact, a non-monotone record,
 *                             an anchor that did not hold). The row carries the
 *                             search's own reason and, when the answer is "run
 *                             another probe", the exact release to probe next.
 *   `declared_pair_disagrees` the manifest and its disposition ALREADY disagree
 *                             on the range. That library does not load
 *                             (:632-637), so proposing a third state for it
 *                             would be proposing an edit onto a broken base.
 *
 * And one refusal that is about the proposal rather than the search:
 * `proposal_contains_failing_release` — a recorded non-green release falling
 * inside the range this verb was about to propose. The bisector already refuses
 * a contradiction inside the WINDOW it settled, but a proposed range keeps the
 * declared floor when the evidenced floor is higher (see below), and that span
 * is outside the settled window. Proposing a range that contains a release the
 * record says fails is the one automated claim-widening this file must never
 * perform, so it is checked against the whole record after the range is built.
 *
 * ## How the proposed endpoints are derived, and why they cannot outrun evidence
 *
 * `min` only ever moves DOWN, to the evidenced floor. It never moves up to it:
 * a floor is "green at least this far down" unless the release below it probed
 * and failed, so raising `min` to an unevidenced floor would drop a claim the
 * record does not disprove — a narrowing nobody asked for, and one that would
 * refuse deploys on sites running the versions it dropped.
 *
 * `max` is EXCLUSIVE (`CodeCompatibility.php:759-761`), and it moves to the
 * version of the next RECORDED release after the evidenced ceiling. That bound
 * admits every release through the ceiling and nothing beyond it, and it is a
 * version string somebody recorded rather than one this file invented. When the
 * ceiling IS the newest recorded release there is no next version, so `max`
 * does not move at all and `limits[]` says why: inventing a bound past the end
 * of the recorded list would claim releases nobody has heard of.
 *
 * ## The freshness fact, and where it is allowed to live
 *
 * `manifests/capabilities/platform.json` already carries per-axis
 * `last_verified` — a VERSION, not a date ("7.1", "the newest exercised core").
 * This verb derives the same shape per adapter: the newest release that probed
 * green, how many recorded releases are newer than it, and how many of those
 * nobody has probed at all.
 *
 * It lives OUTSIDE `manifests/` and it is DERIVED, and those two are the same
 * decision. A `last_verified` field stored beside a manifest would be a rule-2
 * identity input: re-verifying an adapter would move its `adapter_digest` and
 * every `site.duo.json` content pin in the fleet, turning a health signal into
 * a fleet-wide re-pin event. So the fact is a projection of the ledger, emitted
 * on stdout, read by `duo census --health=<doc>`, and stored nowhere this
 * process controls.
 *
 * Because it is a projection, it can never be hand-asserted, and that is
 * enforced rather than documented: `assertNotHandAsserted()` refuses any ledger
 * document carrying `last_verified`, `stale`, `releases_behind` or `freshness`.
 * A recorded input allowed to state its own freshness would let the one adapter
 * nobody has probed since 2026 declare itself current.
 *
 * ## Exit codes
 *
 *   0  a proposals document was produced (including one whose every adapter refused)
 *   1  the ledger or the manifest library could not be read
 *   2  usage error
 *
 * Deliberately NOT the bisector's four (`AdapterBoundary.php:121-132`). Its
 * exit 3 means "probe exactly this release next", which is a single-subject
 * question; a job over sixteen adapters has as many next-probes as it has
 * incomplete searches, and collapsing them into one process status would make a
 * driver loop probe the first one forever. They are rows in `refused[]` with
 * their own `next_probe`, and the process succeeded at what it was asked to do.
 */
final class AdapterProposals {
    /** The one sub-verb this owner claims off `duo adapter`. */
    public const VERBS = ['proposals'];

    public const FORMAT = 'duo-adapter-boundary-proposals/v1';

    /**
     * The ledger's two file shapes, as `sandbox/conformance/boundary/README.md`
     * already documents them: `<slug>.releases.json` is the reviewed candidate
     * set, `<slug>.outcomes.json` is what `sandbox/bin/adapter-boundary.sh`
     * accumulates as probes complete. The slug is the FILENAME stem, and it is
     * cross-checked against the document's own `slug` by the reader, so a file
     * renamed to another plugin's stem is refused rather than folded.
     */
    public const RELEASES_SUFFIX = '.releases.json';
    public const OUTCOMES_SUFFIX = '.outcomes.json';

    /**
     * Keys a ledger document may never carry. Freshness is derived from probe
     * outcomes; a recorded input that asserted it would let a stale adapter
     * declare itself current, which is the one thing this signal exists to
     * catch.
     */
    public const DERIVED_ONLY_KEYS = ['last_verified', 'stale', 'releases_behind', 'freshness'];

    /** The closed freshness vocabulary; `stale` is everything that is not `current`. */
    public const FRESHNESS_CLASSES = ['unrecorded', 'unverified', 'behind', 'current'];

    public const EXIT_OK = 0;
    public const EXIT_REFUSED = 1;
    public const EXIT_USAGE = 2;

    private const FLAGS = ['--ledger', '--manifests', '--format'];

    private const USAGE = 'run `duo adapter proposals [--ledger=<dir>] [--manifests=<dir>] [--format=json]`';

    /**
     * @param list<string> $args everything after `adapter proposals`
     */
    public static function run(array $args): int {
        // Read off raw argv before parsing, the posture every host verb in this
        // family takes (AdapterBoundary.php:175-181): a caller that asked for
        // machine output must get one parseable envelope even for the refusal
        // that fires while its own flags are still being read.
        $json = in_array('--format=json', $args, true);
        try {
            $options = self::options($args);
        } catch (\RuntimeException $e) {
            return self::refuse($json, self::EXIT_USAGE, 'invalid_arguments', $e->getMessage());
        }
        $json = $options['format'] === 'json';

        try {
            $library = self::readLibrary($options['manifests']);
            $ledger = self::readLedger($options['ledger']);
        } catch (\RuntimeException $e) {
            return self::refuse($json, self::EXIT_REFUSED, 'proposals_input_refused', $e->getMessage());
        }

        $document = self::project($library, $ledger);
        if ($json) {
            // Canon::encode() already terminates with LF (Canon.php:102).
            echo Canon::encode($document);
        } else {
            self::render($document);
        }
        return self::EXIT_OK;
    }

    /**
     * The whole fold, callable in-process so the offline corpus drives THIS
     * code rather than a re-implementation of it.
     *
     * @param list<array{name:string, plugin:string, slug:string, range:array{min:string,max:string}, supported_versions:?array<string,mixed>}> $library
     * @param array<string, array{releases:array<string,mixed>, outcomes:array<string, array{version:string,outcome:string,signature:string}>, sources:array<string,string>}> $ledger keyed by slug
     * @return array<string,mixed> a duo-adapter-boundary-proposals/v1 document
     */
    public static function project(array $library, array $ledger): array {
        $proposals = [];
        $unchanged = [];
        $refused = [];
        $freshness = [];
        $withProbes = 0;

        foreach ($library as $adapter) {
            $name = $adapter['name'];
            $slug = $adapter['slug'];
            $entry = $ledger[$slug] ?? null;
            $open = false;

            if ($entry === null) {
                // Not a refusal: an adapter with no recorded release list is
                // not a failed proposal, it is an unmeasured one. It surfaces
                // as `unrecorded` freshness, which is the loudest staleness
                // class there is, and the census ranks it beside the rest.
                $freshness[] = self::freshnessRow($adapter, null, null, false);
                continue;
            }
            $withProbes++;

            $declared = self::declaredPair($adapter);
            if ($declared !== null) {
                $refused[] = $declared;
                $freshness[] = self::freshnessRow($adapter, $entry, null, false);
                continue;
            }

            $anchor = self::anchor($entry['releases']['releases'], $entry['outcomes']);
            if ($anchor === null) {
                $refused[] = [
                    'adapter' => $name,
                    'reason' => 'no_green_probe',
                    'detail' => "the recorded outcomes for '$slug' hold no green probe, so the bisection never "
                        . 'reached a release that installs, seeds and round-trips byte-identically. There is no '
                        . 'anchor to search outward from and no endpoint a range could name: a proposal built on '
                        . 'this record would be a claim its own evidence contradicts',
                    'next_probe' => null,
                ];
                $freshness[] = self::freshnessRow($adapter, $entry, null, false);
                continue;
            }

            $search = AdapterBoundary::search($entry['releases'], $entry['outcomes'], [
                'anchor' => $anchor,
                'manifest' => $name,
                'declared_range' => $adapter['range'],
            ]);
            if ($search['status'] !== 'complete') {
                $blocked = is_array($search['blocked'] ?? null) ? $search['blocked'] : null;
                $refused[] = [
                    'adapter' => $name,
                    'reason' => 'bisection_incomplete',
                    'detail' => 'the bisection for ' . $slug . ' returned ' . (string) $search['status']
                        . ($blocked === null ? '' : ': ' . (string) $blocked['reason'] . ' — ' . (string) $blocked['detail'])
                        . '. A range is proposed only from a search that ran to completion',
                    'next_probe' => $search['next_probe'] ?? null,
                ];
                $freshness[] = self::freshnessRow($adapter, $entry, $anchor, false);
                continue;
            }

            $boundary = (array) $search['boundary'];
            $range = self::proposedRange($adapter['range'], $boundary, $entry['releases']['releases']);
            $failing = self::failingInside($range, $entry['outcomes']);
            if ($failing !== []) {
                $refused[] = [
                    'adapter' => $name,
                    'reason' => 'proposal_contains_failing_release',
                    'detail' => 'the range this search would propose (' . $range['min'] . ' <= v < ' . $range['max']
                        . ') contains recorded non-green release(s) ' . implode(', ', $failing)
                        . '. The bisector refuses a contradiction inside the window it SETTLED; this span is '
                        . 'outside it, because the proposal keeps the declared floor rather than raising it to an '
                        . 'unevidenced one. Proposing a range over a release the record says fails is the one '
                        . 'widening this verb must never perform',
                    'next_probe' => null,
                ];
                $freshness[] = self::freshnessRow($adapter, $entry, $anchor, false);
                continue;
            }

            $row = [
                'adapter' => $name,
                'plugin' => $adapter['plugin'],
                'current_range' => $adapter['range'],
                'proposed_range' => $range,
                'evidence' => [
                    'floor' => (string) $boundary['floor'],
                    'ceiling' => (string) $boundary['ceiling'],
                    'floor_transition_evidenced' => $boundary['floor_transition_evidenced'],
                    'ceiling_transition_evidenced' => $boundary['ceiling_transition_evidenced'],
                    'anchor' => $anchor,
                    'probe_count' => (int) $search['probe_count'],
                    'probe_bound' => (int) $search['probe_bound'],
                    'probes' => $search['probes'],
                ],
                'limits' => self::limits($adapter['range'], $range, $boundary, $entry['releases']['releases'], $search),
                'sources' => $entry['sources'],
            ];
            if (Canon::encode($range) === Canon::encode($adapter['range'])) {
                // A proposal that changes nothing is not a proposal. It is the
                // answer an operator most wants from a scheduled job — the
                // declared claim still matches the evidence — and reporting it
                // as a proposal would put a no-op edit in a review queue.
                $row['status'] = 'no_change';
                $unchanged[] = $row;
            } else {
                $row['status'] = 'proposed';
                $row['edits'] = self::proposedEdits($adapter, $range);
                $row['canon'] = [
                    'range_sha256' => hash('sha256', Canon::encode($range)),
                    'why' => 'ManifestDispositions.php:632-637 compares Canon::encode() of the manifest\'s '
                        . 'version_range against Canon::encode() of the disposition\'s supported_versions.range and '
                        . 'refuses the pair the moment those BYTES differ. Both edits above are encodings of one '
                        . 'value, so they agree by construction rather than by the operator\'s care',
                ];
                $proposals[] = $row;
                $open = true;
            }
            $freshness[] = self::freshnessRow($adapter, $entry, $anchor, $open);
        }

        // Total order and a function of the SET, never of directory-read order:
        // the adapter name is unique in a library, so the comparison never falls
        // through to input order.
        usort($proposals, static fn(array $a, array $b): int => strcmp($a['adapter'], $b['adapter']));
        usort($unchanged, static fn(array $a, array $b): int => strcmp($a['adapter'], $b['adapter']));
        usort($refused, static fn(array $a, array $b): int => strcmp($a['adapter'], $b['adapter']));
        // Freshness ranks by how far behind the proof is, because that is the
        // question the row exists to answer; the name breaks every tie so the
        // rank is total.
        usort($freshness, static function (array $a, array $b): int {
            return $b['releases_behind'] <=> $a['releases_behind']
                ?: strcmp($a['freshness_class'], $b['freshness_class'])
                ?: strcmp($a['adapter'], $b['adapter']);
        });

        $stale = 0;
        foreach ($freshness as $row) {
            if ($row['stale'] === true) {
                $stale++;
            }
        }

        return [
            'format' => self::FORMAT,
            'ledger' => [
                'adapters' => count($library),
                'with_recorded_probes' => $withProbes,
                'without_recorded_probes' => count($library) - $withProbes,
                'stale_adapters' => $stale,
                'open_proposals' => count($proposals),
            ],
            'proposals' => $proposals,
            'unchanged' => $unchanged,
            'refused' => $refused,
            'freshness' => $freshness,
            'review_required' => self::REVIEW_REQUIRED,
            'evidence_limits' => self::EVIDENCE_LIMITS,
        ];
    }

    private const REVIEW_REQUIRED = [
        'manifest_edit' => 'not-performed',
        'files' => ['manifests/<name>.json', 'manifests/dispositions.json'],
        'why' => 'Both edits above are PROPOSED. Applying them is a reviewed human act because AGENTS.md rule 2 '
            . 'makes a byte under manifests/ adapter identity: ArtifactPolicyIdentity::manifest_rows() folds each '
            . 'manifest and its disposition into the digest every deployed site pins against, so a job that '
            . 'committed a widening would refuse every site holding a compiled artifact with '
            . 'compiled_artifact_manifest_mismatch until it was recompiled and re-pinned.',
    ];

    /**
     * Printed on every run for the same reason the bisector prints its own
     * (`AdapterBoundary.php:577-586`): a tool that discloses its limits only
     * when something goes wrong lets silence read as "everything was checked".
     */
    private const EVIDENCE_LIMITS = [
        'Freshness is derived from the RECORDED ledger, so "current" means "no recorded release is newer than '
            . 'the newest green probe". A release that upstream shipped and nobody recorded is invisible here, '
            . 'and recording one is a deliberate human act (sandbox/conformance/boundary/README.md).',
        'A proposed range is bounded by probes, not exhausted by them: releases between the floor and the '
            . 'ceiling that no probe touched are unevidenced, not proven green.',
        'Each probe outcome is one pair round-trip against one seed hook. It is evidence about that plugin '
            . 'content shape, not about every shape a site can hold.',
    ];

    /**
     * The two edits, both derived from ONE `$range`.
     *
     * That is the whole point: `ManifestDispositions.php:632-637` compares
     * `Canon::encode()` of the manifest's `version_range` against
     * `Canon::encode()` of the disposition's `supported_versions.range`, and
     * refuses the pair the moment the bytes differ. Building the second edit
     * from the first value rather than re-deriving it means no code path here
     * can emit a pair the validator would reject.
     *
     * The disposition edit carries `plugin` too, and it carries the MANIFEST's
     * `plugin` string: the same check compares that field against
     * `$manifest['plugin']` before it looks at the range at all (`:632`).
     *
     * @param array{name:string, plugin:string, range:array{min:string,max:string}, supported_versions:?array<string,mixed>} $adapter
     * @param array{min:string,max:string} $range
     * @return list<array<string,mixed>>
     */
    private static function proposedEdits(array $adapter, array $range): array {
        $supported = $adapter['supported_versions'] ?? [];
        $proposedSupported = is_array($supported) ? $supported : [];
        $proposedSupported['plugin'] = $adapter['plugin'];
        $proposedSupported['range'] = $range;

        $edits = [
            [
                'file' => 'manifests/' . $adapter['name'] . '.json',
                'pointer' => '/version_range',
                'current' => $adapter['range'],
                'proposed' => $range,
            ],
            [
                'file' => 'manifests/dispositions.json',
                'pointer' => '/manifests/' . $adapter['name'] . '/supported_versions',
                'current' => $adapter['supported_versions'],
                'proposed' => $proposedSupported,
            ],
        ];
        // A defensive equality on the bytes the validator actually compares.
        // It cannot fail while both edits are built from `$range` above — which
        // is why it is here: the day someone re-derives the second one, this
        // throws in the emitter instead of shipping a review packet that
        // bricks the library it is applied to.
        if (Canon::encode($edits[0]['proposed']) !== Canon::encode($edits[1]['proposed']['range'])) {
            throw new \LogicException(
                'duo: adapter proposals: the two proposed edits do not agree on canonical bytes, which is exactly '
                . 'what ManifestDispositions.php:632-637 refuses'
            );
        }
        return $edits;
    }

    /**
     * The proposed endpoints.
     *
     * `min` moves DOWN to the evidenced floor and never up: a floor is "green
     * at least this far down" unless the release below it probed and failed, so
     * raising `min` to it would retract a claim the record does not disprove.
     *
     * `max` is exclusive (`CodeCompatibility.php:759-761`) and moves to the
     * next RECORDED release after the ceiling — a bound that admits every
     * release through the ceiling and nothing past it, written by whoever
     * recorded the list rather than invented here. A ceiling that IS the newest
     * recorded release leaves `max` alone.
     *
     * @param array{min:string,max:string} $declared
     * @param array<string,mixed> $boundary
     * @param list<array<string,mixed>> $releases
     * @return array{min:string,max:string}
     */
    private static function proposedRange(array $declared, array $boundary, array $releases): array {
        $min = version_compare((string) $boundary['floor'], $declared['min'], '<')
            ? (string) $boundary['floor']
            : $declared['min'];

        $max = $declared['max'];
        $next = self::nextRelease($releases, (string) $boundary['ceiling']);
        if ($next !== null && version_compare($next, $max, '>')) {
            $max = $next;
        }
        return ['min' => $min, 'max' => $max];
    }

    /**
     * @param list<array<string,mixed>> $releases
     */
    private static function nextRelease(array $releases, string $version): ?string {
        foreach ($releases as $position => $entry) {
            if ((string) $entry['version'] === $version) {
                return isset($releases[$position + 1]) ? (string) $releases[$position + 1]['version'] : null;
            }
        }
        return null;
    }

    /**
     * Every recorded non-green release inside the half-open range.
     *
     * @param array{min:string,max:string} $range
     * @param array<string, array{version:string,outcome:string,signature:string}> $outcomes
     * @return list<string>
     */
    private static function failingInside(array $range, array $outcomes): array {
        $inside = [];
        foreach ($outcomes as $row) {
            if ($row['outcome'] === AdapterBoundary::OUTCOME_GREEN) {
                continue;
            }
            if (version_compare($row['version'], $range['min'], '>=')
                && version_compare($row['version'], $range['max'], '<')) {
                $inside[] = $row['version'];
            }
        }
        usort($inside, static fn(string $a, string $b): int => version_compare($a, $b));
        return $inside;
    }

    /**
     * What this proposal does not prove, said in the row.
     *
     * @param array{min:string,max:string} $declared
     * @param array{min:string,max:string} $range
     * @param array<string,mixed> $boundary
     * @param list<array<string,mixed>> $releases
     * @param array<string,mixed> $search
     * @return list<string>
     */
    private static function limits(array $declared, array $range, array $boundary, array $releases, array $search): array {
        $limits = [];
        if (self::nextRelease($releases, (string) $boundary['ceiling']) === null) {
            $limits[] = 'The ceiling ' . (string) $boundary['ceiling'] . ' is the newest RECORDED release, so max '
                . 'stays at ' . $range['max'] . ': a bound past the end of the recorded list would claim releases '
                . 'nobody has recorded.';
        }
        if (version_compare((string) $boundary['floor'], $declared['min'], '>')) {
            $limits[] = 'The evidenced floor ' . (string) $boundary['floor'] . ' is ABOVE the declared min '
                . $declared['min'] . ', and min is left where it is: a floor is "green at least this far down" '
                . 'unless the release below it probed and failed, so raising it would retract a claim this record '
                . 'does not disprove.';
        }
        foreach ((array) $search['evidence_limits'] as $limit) {
            $limits[] = (string) $limit;
        }
        return $limits;
    }

    /**
     * The anchor, DERIVED: the newest release with a recorded green outcome.
     *
     * A scheduled job has no human to name one, and picking the declared `min`
     * would fail the moment a floor moved. The newest green is the release this
     * library most recently proved, which is exactly what "search outward from
     * a release believed green" means when the record is doing the believing.
     *
     * Null when no green was ever recorded — the `no_green_probe` refusal.
     *
     * @param list<array<string,mixed>> $releases in recorded (release) order
     * @param array<string, array{version:string,outcome:string,signature:string}> $outcomes
     */
    private static function anchor(array $releases, array $outcomes): ?string {
        $anchor = null;
        foreach ($releases as $entry) {
            $version = (string) $entry['version'];
            if (($outcomes[$version]['outcome'] ?? null) === AdapterBoundary::OUTCOME_GREEN) {
                $anchor = $version;
            }
        }
        return $anchor;
    }

    /**
     * The library's own precondition. A manifest and a disposition that already
     * disagree on the range describe a library that does not load
     * (`ManifestDispositions.php:632-637`), so there is no base to propose an
     * edit onto.
     *
     * @param array{name:string, plugin:string, range:array{min:string,max:string}, supported_versions:?array<string,mixed>} $adapter
     * @return array<string,mixed>|null a refusal row, or null when the pair agrees
     */
    private static function declaredPair(array $adapter): ?array {
        $supported = $adapter['supported_versions'];
        if ($supported === null) {
            // No reviewed entry at all is not a disagreement: an unreviewed
            // manifest claims nothing, and `duo manifest-validate` is where
            // that is the subject.
            return null;
        }
        $sameRange = Canon::encode($supported['range'] ?? null) === Canon::encode($adapter['range']);
        $samePlugin = ($supported['plugin'] ?? null) === $adapter['plugin'];
        if ($sameRange && $samePlugin) {
            return null;
        }
        return [
            'adapter' => $adapter['name'],
            'reason' => 'declared_pair_disagrees',
            'detail' => "manifests/{$adapter['name']}.json and its dispositions entry already disagree on the "
                . 'supported versions, which is the pair ManifestDispositions.php:632-637 refuses ("versions '
                . 'disagree with its manifest contract"). That library does not load, so proposing a third state '
                . 'for it would be an edit onto a broken base',
            'next_probe' => null,
        ];
    }

    /**
     * One adapter's derived freshness.
     *
     * Shaped after `manifests/capabilities/platform.json`'s per-axis
     * `last_verified`, which is a VERSION and not a date ("7.1", the newest
     * exercised core). A date would answer "when did someone run this", which
     * nothing here can know from a probe record; a version answers "how far
     * behind its plugin is this adapter's proof", which is the question.
     *
     * @param array{name:string, plugin:string, slug:string, range:array{min:string,max:string}} $adapter
     * @param array{releases:array<string,mixed>, outcomes:array<string,mixed>}|null $entry
     * @return array<string,mixed>
     */
    private static function freshnessRow(array $adapter, ?array $entry, ?string $anchor, bool $openProposal): array {
        $row = [
            'adapter' => $adapter['name'],
            'freshness_class' => 'unrecorded',
            'last_verified' => null,
            'newest_recorded_release' => null,
            'recorded_releases' => 0,
            'probes_recorded' => 0,
            'releases_behind' => 0,
            'unprobed_newer' => 0,
            'failing_newer' => 0,
            'declared_max' => $adapter['range']['max'],
            'declared_covers_newest' => null,
            'open_proposal' => $openProposal,
            'stale' => true,
            'why' => 'no recorded release list for this adapter\'s plugin, so nothing has ever been bisected for '
                . 'it — the loudest staleness there is, and invisible until it is counted',
        ];
        if ($entry === null) {
            return $row;
        }

        $releases = $entry['releases']['releases'];
        $outcomes = $entry['outcomes'];
        $newest = (string) $releases[count($releases) - 1]['version'];
        $row['newest_recorded_release'] = $newest;
        $row['recorded_releases'] = count($releases);
        $row['probes_recorded'] = count($outcomes);
        $row['declared_covers_newest'] = version_compare($newest, $adapter['range']['min'], '>=')
            && version_compare($newest, $adapter['range']['max'], '<');

        // `last_verified` is the anchor by construction when there is one: both
        // are "the newest release that probed green", derived from one record
        // rather than asserted twice.
        $lastVerified = $anchor ?? self::anchor($releases, $outcomes);
        if ($lastVerified === null) {
            $row['freshness_class'] = 'unverified';
            $row['why'] = 'a release list is recorded and ' . count($outcomes) . ' probe(s) with it, but none '
                . 'reached green: this adapter has a candidate set and no proof';
            return $row;
        }

        $row['last_verified'] = $lastVerified;
        $behind = 0;
        $unprobed = 0;
        $failing = 0;
        $seen = false;
        foreach ($releases as $entryRow) {
            $version = (string) $entryRow['version'];
            if ($version === $lastVerified) {
                $seen = true;
                continue;
            }
            if (!$seen) {
                continue;
            }
            $behind++;
            $outcome = $outcomes[$version]['outcome'] ?? null;
            if ($outcome === null) {
                $unprobed++;
            } elseif ($outcome !== AdapterBoundary::OUTCOME_GREEN) {
                $failing++;
            }
        }
        $row['releases_behind'] = $behind;
        $row['unprobed_newer'] = $unprobed;
        $row['failing_newer'] = $failing;
        $row['freshness_class'] = $behind === 0 ? 'current' : 'behind';
        $row['stale'] = $behind !== 0;
        $row['why'] = $behind === 0
            ? 'the newest green probe IS the newest recorded release, so nothing recorded is unproven'
            : $behind . ' recorded release(s) are newer than the newest green probe (' . $unprobed
                . ' never probed, ' . $failing . ' probed non-green)';
        return $row;
    }

    /**
     * The manifest library, read-only and shallow: the declared range, the
     * plugin it claims, and the reviewed restatement. Nothing here loads the
     * engine — the proposal is about two authored fields, and `duo
     * manifest-validate` is the verb that runs the real `Policy::load()` over
     * the result.
     *
     * @return list<array{name:string, plugin:string, slug:string, range:array{min:string,max:string}, supported_versions:?array<string,mixed>}>
     */
    public static function readLibrary(string $dir): array {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            throw new \RuntimeException("the manifest library directory '$dir' does not exist");
        }
        $dispositions = [];
        $file = $dir . '/dispositions.json';
        if (is_file($file)) {
            $data = Canon::decode(self::readFile($file));
            if (!is_array($data) || !is_array($data['manifests'] ?? null)) {
                throw new \RuntimeException("dispositions document '$file' carries no 'manifests' object");
            }
            $dispositions = $data['manifests'];
        }

        $library = [];
        foreach (glob($dir . '/*.json') ?: [] as $path) {
            $name = basename($path, '.json');
            if ($name === 'dispositions') {
                continue;
            }
            $manifest = Canon::decode(self::readFile($path));
            if (!is_array($manifest)) {
                continue;
            }
            $plugin = $manifest['plugin'] ?? null;
            $range = $manifest['version_range'] ?? null;
            // Core declares no plugin and no range, and an excluded regression
            // fixture may declare neither: an adapter with nothing to bump is
            // not this job's subject.
            if (!is_string($plugin) || $plugin === ''
                || !is_array($range) || !is_string($range['min'] ?? null) || !is_string($range['max'] ?? null)) {
                continue;
            }
            $entry = $dispositions[$name] ?? null;
            $library[] = [
                'name' => $name,
                'plugin' => $plugin,
                'slug' => self::slugOf($plugin),
                'range' => ['min' => (string) $range['min'], 'max' => (string) $range['max']],
                'supported_versions' => is_array($entry) && is_array($entry['supported_versions'] ?? null)
                    ? $entry['supported_versions']
                    : null,
            ];
        }
        usort($library, static fn(array $a, array $b): int => strcmp($a['name'], $b['name']));
        return $library;
    }

    /**
     * The ledger directory: `<slug>.releases.json` and its optional
     * `<slug>.outcomes.json`, read through the BISECTOR's own readers.
     *
     * Reusing `AdapterBoundary::readReleaseList()` / `readOutcomeTable()`
     * rather than parsing here is the point: a second parser for the same two
     * documents would be a second grammar, and the day they disagreed by one
     * rule the job would fold a list the single-subject command refuses.
     *
     * @return array<string, array{releases:array<string,mixed>, outcomes:array<string,mixed>, sources:array<string,string>}>
     */
    public static function readLedger(string $dir): array {
        $dir = rtrim($dir, '/');
        if (!is_dir($dir)) {
            throw new \RuntimeException(
                "the ledger directory '$dir' does not exist. It holds the recorded <slug>.releases.json and "
                . '<slug>.outcomes.json documents sandbox/bin/adapter-boundary.sh accumulates; this verb never '
                . 'reaches the network, so an absent ledger is an absent answer, not a reason to scrape one'
            );
        }
        $ledger = [];
        foreach (glob($dir . '/*' . self::RELEASES_SUFFIX) ?: [] as $path) {
            $slug = basename($path, self::RELEASES_SUFFIX);
            self::assertNotHandAsserted($path);
            $releases = AdapterBoundary::readReleaseList($path);
            if ($releases['slug'] !== $slug) {
                throw new \RuntimeException(
                    "ledger file '$path' is named for slug '$slug' but the document inside declares "
                    . "'{$releases['slug']}'. The filename is how this job joins a ledger entry to a manifest, so a "
                    . 'renamed file would fold one plugin\'s releases into another plugin\'s claim'
                );
            }
            $outcomes = [];
            $sources = ['releases' => basename($path)];
            $outcomePath = $dir . '/' . $slug . self::OUTCOMES_SUFFIX;
            if (is_file($outcomePath)) {
                self::assertNotHandAsserted($outcomePath);
                $outcomes = AdapterBoundary::readOutcomeTable($outcomePath, $slug);
                $sources['outcomes'] = basename($outcomePath);
            }
            $ledger[$slug] = ['releases' => $releases, 'outcomes' => $outcomes, 'sources' => $sources];
        }
        ksort($ledger, SORT_STRING);
        return $ledger;
    }

    /**
     * Freshness is DERIVED or it is nothing.
     *
     * The readers above normalize and drop unknown keys, so this guard reads
     * the raw document: a ledger allowed to carry its own `last_verified` would
     * let the one adapter nobody has probed since the flag day declare itself
     * current, which is the single failure this signal exists to make
     * impossible.
     */
    private static function assertNotHandAsserted(string $path): void {
        $data = Canon::decode(self::readFile($path));
        if (!is_array($data)) {
            return;
        }
        foreach (self::DERIVED_ONLY_KEYS as $key) {
            if (array_key_exists($key, $data)) {
                throw new \RuntimeException(
                    "ledger document '$path' carries '$key', which is DERIVED from probe outcomes and may never be "
                    . 'recorded. A recorded input allowed to state its own freshness would let a stale adapter '
                    . 'declare itself current — remove the key and let the projection answer'
                );
            }
        }
    }

    /**
     * WordPress's `<dir>/<file>.php` plugin identity reduced to the directory,
     * the same derivation `AssessInventory::plugins_without_adapter()` uses
     * (agent/src/Assess/AssessInventory.php:477-485) and the same one the
     * artifact lock keys its plugin blocks on — which is what lets a ledger
     * file named for a lock slug join a manifest that names a basename.
     */
    private static function slugOf(string $basename): string {
        $directory = strpos($basename, '/') === false ? '' : dirname($basename);
        $file = basename($basename);
        return $directory !== '' && $directory !== '.'
            ? $directory
            : (string) preg_replace('/\.php$/D', '', $file);
    }

    private static function readFile(string $path): string {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("could not read '$path'");
        }
        return $raw;
    }

    /**
     * @param list<string> $args
     * @return array{ledger:string, manifests:string, format:string}
     */
    private static function options(array $args): array {
        $repo = dirname(__DIR__, 3);
        $values = [
            // The recorded-input directory this checkout already ships
            // (sandbox/conformance/boundary/README.md). It is outside
            // manifests/ by construction, which is the rule-2 property the
            // freshness fact depends on.
            '--ledger' => $repo . '/sandbox/conformance/boundary',
            '--manifests' => $repo . '/manifests',
            '--format' => 'human',
        ];
        $seen = [];
        foreach ($args as $arg) {
            if ($arg === 'proposals' && !isset($seen['proposals'])) {
                // The sub-verb itself, kept in argv by the router.
                $seen['proposals'] = true;
                continue;
            }
            if (!str_starts_with($arg, '--')) {
                throw new \RuntimeException("unexpected argument '$arg'");
            }
            $flag = explode('=', $arg, 2)[0];
            if (!in_array($flag, self::FLAGS, true)) {
                throw new \RuntimeException("unsupported flag '$flag'");
            }
            // Refused rather than last-wins, the posture every host verb here
            // takes (AdapterCatalog.php:230-241): a second --ledger silently
            // replacing the first would fold a record the operator did not name.
            if (isset($seen[$flag])) {
                throw new \RuntimeException("duplicate flag '$flag'");
            }
            $seen[$flag] = true;
            $value = str_contains($arg, '=') ? substr($arg, strlen($flag) + 1) : '';
            if (trim($value) === '') {
                throw new \RuntimeException("$flag needs a value");
            }
            $values[$flag] = $value;
        }
        if (!in_array($values['--format'], ['human', 'json'], true)) {
            throw new \RuntimeException("--format must be 'human' or 'json'");
        }
        return [
            'ledger' => $values['--ledger'],
            'manifests' => $values['--manifests'],
            'format' => $values['--format'],
        ];
    }

    /** @param array<string,mixed> $document */
    private static function render(array $document): void {
        $ledger = (array) $document['ledger'];
        echo 'proposals: ' . (int) $ledger['open_proposals'] . ' open over ' . (int) $ledger['adapters']
            . ' pinned adapter(s), ' . (int) $ledger['with_recorded_probes'] . " with a recorded ledger\n";
        foreach ((array) $document['proposals'] as $row) {
            $row = (array) $row;
            $current = (array) $row['current_range'];
            $proposed = (array) $row['proposed_range'];
            $evidence = (array) $row['evidence'];
            echo 'propose ' . (string) $row['adapter'] . ': '
                . (string) $current['min'] . ' <= v < ' . (string) $current['max'] . '  ->  '
                . (string) $proposed['min'] . ' <= v < ' . (string) $proposed['max']
                . ' (floor ' . (string) $evidence['floor'] . ', ceiling ' . (string) $evidence['ceiling']
                . ', ' . (int) $evidence['probe_count'] . " probe(s))\n";
            foreach ((array) $row['edits'] as $edit) {
                $edit = (array) $edit;
                echo '  edit ' . (string) $edit['file'] . (string) $edit['pointer'] . ': '
                    . rtrim(Canon::encode($edit['proposed']), "\n") . "\n";
            }
        }
        foreach ((array) $document['unchanged'] as $row) {
            $row = (array) $row;
            echo 'unchanged ' . (string) $row['adapter'] . ": the evidence already agrees with the declared range\n";
        }
        foreach ((array) $document['refused'] as $row) {
            $row = (array) $row;
            fwrite(STDERR, 'duo: adapter proposals: ' . (string) $row['adapter'] . ': '
                . (string) $row['reason'] . ': ' . (string) $row['detail'] . "\n");
            $next = $row['next_probe'] ?? null;
            if (is_array($next)) {
                fwrite(STDERR, 'duo: adapter proposals: ' . (string) $row['adapter'] . ': next: probe '
                    . (string) $next['version'] . ' (' . (string) $next['arm'] . " arm)\n");
            }
        }
        foreach ((array) $document['freshness'] as $row) {
            $row = (array) $row;
            echo 'freshness ' . (string) $row['adapter'] . ': ' . (string) $row['freshness_class']
                . ' last_verified=' . ($row['last_verified'] === null ? 'none' : (string) $row['last_verified'])
                . ' behind=' . (int) $row['releases_behind']
                . ' unprobed=' . (int) $row['unprobed_newer']
                . ' — ' . (string) $row['why'] . "\n";
        }
        foreach (self::EVIDENCE_LIMITS as $limit) {
            echo 'limit: ' . $limit . "\n";
        }
        $review = self::REVIEW_REQUIRED;
        echo 'review: manifest edit ' . $review['manifest_edit'] . ' — '
            . implode(' + ', $review['files']) . " remain a reviewed human edit\n";
    }

    private static function refuse(bool $json, int $code, string $reason, string $message): int {
        if ($json) {
            CommandOutput::renderRefusalJson('adapter proposals', $reason, $message, self::USAGE);
            return $code;
        }
        fwrite(STDERR, 'duo: adapter proposals: ' . $message . "\n");
        fwrite(STDERR, 'duo: adapter proposals: remedy: ' . self::USAGE . "\n");
        return $code;
    }
}
