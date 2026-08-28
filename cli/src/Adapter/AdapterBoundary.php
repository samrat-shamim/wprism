<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once dirname(__DIR__, 3) . '/agent/src/Kernel/Canon.php';
require_once dirname(__DIR__, 3) . '/agent/src/Policy/AdapterLibrary.php';

use Duo\AdapterLibrary;
use Duo\Canon;

/**
 * `duo adapter boundary` — version-range bisection as EVIDENCE, never as an edit.
 *
 * ## The cost this exists to bound
 *
 * `AdapterContractGrammar.php:73-79` refuses a plugin claim with no exact
 * `version_range`: "unbounded support, which this project's contract forbids".
 * That refusal is correct and permanent, which is why the cost it creates
 * recurs forever — every adapter, every upstream release train, needs someone
 * to find the two versions the range may name. Today that is done by hand:
 * `sandbox/tests/certify/certify_version_matrix.sh` runs ONE subject's
 * hard-coded boundary versions per invocation (`VMATRIX_MANIFEST`), and a
 * human picked those versions by trying them.
 *
 * Bisection turns "try them" into O(log releases) probes. This command owns
 * the search; the pair harness owns the probe.
 *
 * ## What it produces, and what it deliberately does not
 *
 * It emits a `duo-adapter-boundary-search/v1` document: which releases were
 * probed, in what order, what each one did, and — when the evidence supports
 * one — a proposed package-owned `evidence/artifacts.lock.json` fragment in
 * the library's OWN three-role vocabulary, shaped so `ArtifactLibrary`
 * accepts it unmodified.
 *
 * It never writes a manifest. The range and Canon-byte-equal restatement in
 * `adapter-packages/<name>/package/{manifest,disposition}.json` are ONE
 * reviewed human edit, because `ManifestDispositions.php:632-637` refuses the
 * pair the instant they disagree ("versions disagree with its manifest
 * contract"), and because the package payload is fleet-visible identity:
 * every deployed site holding a compiled artifact starts
 * refusing `compiled_artifact_manifest_mismatch`. A search that could move
 * that byte would be an automated claim-widener. This one hands a reviewer the
 * sentence they need — "6.0.0 boots and round-trips; 5.12.6 fatals with this
 * signature" — and stops.
 *
 * ## Why the release list is a RECORDED input
 *
 * The candidate set is a `duo-adapter-release-list/v1` document naming, for
 * every candidate release, its exact download URL and sha256. Three
 * consequences, all deliberate:
 *
 *   1. No process here ever reaches the network. A suite that scraped wp.org
 *      would fail on a plugin's release history changing under it, which is
 *      the one thing a version-boundary claim must be stable against.
 *   2. Resolution stays digest-pinned end to end. `fetch_artifact()` resolves
 *      against the package-owned artifact library and "a miss never falls through to a bare
 *      WP-CLI catalog install" (`fetch-artifact.sh:44-47`) — but a bisector
 *      probes versions that are BY DEFINITION not in the lock yet, so the
 *      recorded release list is its pin source. Same discipline (https URL,
 *      64-hex digest, refuse-don't-fetch on a miss), a different reviewed
 *      document. Every entry must carry a digest: a half-recorded list would
 *      make the tool choose between fabricating a lock row and silently
 *      dropping one, and it is allowed to do neither.
 *   3. The proposed lock rows are complete on arrival. Their url/sha256 are
 *      the recorded ones, so a reviewer copies the fragment rather than
 *      re-deriving it.
 *
 * The list's ORDER is the release order. That is asserted, not assumed:
 * a list whose recorded order disagrees with `version_compare()` is refused
 * by name, because a mis-ordered list does not fail — it silently bisects to
 * the wrong boundary, and a wrong boundary is the entire risk here.
 *
 * ## Why probing is a separate process
 *
 * One probe is a full pair round-trip: fetch the digest-pinned artifact, reset
 * the environment, install and activate the exact version, run that plugin's
 * package-owned seed hook (`adapter-packages/<slug>/tests/certify/version-matrix.sh`), capture, deploy,
 * apply, recapture, require byte-identity. Minutes, docker, two WordPress
 * installs. So this command is a PLANNER over an accumulating outcome record:
 * given the releases and the outcomes observed so far it either names the one
 * release to probe next (exit 3) or, once the record is sufficient, emits the
 * finished document (exit 0). `sandbox/bin/adapter-boundary.sh` is the loop
 * that runs the probes between calls; the offline corpus replays a recorded
 * outcome table through the SAME `search()` and therefore drives the real
 * search logic, not a model of it.
 *
 * ## The four outcomes, and why one of them is not a failure
 *
 *   `green`                 installed, seeded, round-tripped byte-identically
 *   `boot-fatal`            the exact bytes would not install/activate/load
 *   `round-trip-diverges`   it ran, and recapture was not byte-identical
 *   `artifact-unresolved`   the pinned artifact could not be fetched/verified
 *
 * The first three bound a range. The fourth does not, and conflating it with
 * `boot-fatal` is the specific silent-narrowing bug this vocabulary exists to
 * prevent: a 404 or a digest mismatch is a fact about the DOWNLOAD, not about
 * the plugin, and treating it as absent would let a mirror outage rewrite a
 * boundary. An unresolved probe therefore BLOCKS the search and is reported by
 * version, never skipped and never counted as a failure.
 *
 * ## Why a proposal can never be wider than its evidence
 *
 * Two properties, both structural rather than checked-after-the-fact:
 *
 *   - Both proposed endpoints are releases that PROBED GREEN. The leftmost-true
 *     and rightmost-true binary searches below only ever settle on an index
 *     whose probe returned `green` (the anchor, or a mid that did). A failing
 *     release is unreachable as a boundary — there is no branch that assigns
 *     one.
 *   - Every recorded outcome inside the settled window must be green. Bisection
 *     assumes the green releases are contiguous; when the record CONTRADICTS
 *     that assumption the honest answer is not a narrower guess, it is
 *     `non_monotone_outcomes` naming the release that disproves it. So the
 *     emitted window contains no known-failing release, and an assumption
 *     violation surfaces as a refusal instead of as a widened claim.
 *
 * The window's interior is still probed, not exhausted — `evidence_limits`
 * says so on every run, in the document, because a reviewer reading a floor
 * and a ceiling will otherwise read them as a proof about everything between.
 *
 * ## Exit codes
 *
 *   0  a boundary was searched to completion
 *   1  blocked or refused — the evidence does not support a boundary
 *   2  usage error
 *   3  more probes required; `next_probe` names exactly one release
 *
 * The fourth code is a deliberate departure from the 0/1/2 family
 * (`CensusCommand.php:19-24`). The driver loop's control flow IS "is another
 * probe needed", and expressing that as a JSON field only would mean a shell
 * loop that forgot to parse it treats an unfinished search as a finished one —
 * the failure mode being a boundary claimed from a partial record.
 */
final class AdapterBoundary {
    /** The one sub-verb this owner claims off `duo adapter`. */
    public const VERBS = ['boundary'];

    public const FORMAT = 'duo-adapter-boundary-search/v1';
    public const RELEASES_FORMAT = 'duo-adapter-release-list/v1';
    public const OUTCOMES_FORMAT = 'duo-adapter-boundary-outcomes/v1';

    public const OUTCOME_GREEN = 'green';
    public const OUTCOME_BOOT_FATAL = 'boot-fatal';
    public const OUTCOME_DIVERGES = 'round-trip-diverges';
    public const OUTCOME_UNRESOLVED = 'artifact-unresolved';

    /** @var list<string> */
    public const OUTCOMES = [
        self::OUTCOME_GREEN,
        self::OUTCOME_BOOT_FATAL,
        self::OUTCOME_DIVERGES,
        self::OUTCOME_UNRESOLVED,
    ];

    /**
     * The artifact roles, used exactly as package fragments already use
     * them. `exercise-fixture` is never PROPOSED: it means "installed to
     * exercise something", which is a statement about a test's intent rather
     * than about a version boundary, and no probe outcome implies it.
     */
    public const ROLE_CERTIFIED = 'certified-boundary';
    public const ROLE_REFUSAL = 'refusal-fixture';

    public const EXIT_OK = 0;
    public const EXIT_BLOCKED = 1;
    public const EXIT_USAGE = 2;
    public const EXIT_PROBE_REQUIRED = 3;

    private const FLAGS = ['--releases', '--outcomes', '--anchor', '--from', '--to', '--manifest', '--manifests', '--format'];

    /**
     * @param list<string> $args everything after `adapter boundary`
     */
    public static function run(array $args): int {
        // Read off raw argv before parsing, the posture CensusCommand.php:50
        // takes: a caller that asked for machine output must get one parseable
        // envelope even for the refusal that fires while its own flags are
        // still being read. `CommandOutput::wantsAgentRefusalJson()` cannot
        // answer here — its verb allow-list is the set of AGENT passthrough
        // verbs (CommandOutput.php:101-108), and this one has no agent half.
        $json = in_array('--format=json', $args, true);
        try {
            $options = self::options($args);
        } catch (\RuntimeException $e) {
            return self::refuse($json, self::EXIT_USAGE, 'invalid_arguments', $e->getMessage(), self::USAGE);
        }
        $json = $options['format'] === 'json';

        try {
            $releases = self::readReleaseList($options['releases']);
            $outcomes = $options['outcomes'] === null
                ? []
                : self::readOutcomeTable($options['outcomes'], $releases['slug']);
            $declared = $options['manifest'] === null
                ? null
                : self::readDeclaredRange($options['manifests'], $options['manifest']);
        } catch (\RuntimeException $e) {
            return self::refuse($json, self::EXIT_BLOCKED, 'boundary_input_refused', $e->getMessage(), self::USAGE);
        }

        try {
            $document = self::search($releases, $outcomes, [
                'anchor' => $options['anchor'],
                'from' => $options['from'],
                'to' => $options['to'],
                'manifest' => $options['manifest'],
                'declared_range' => $declared,
            ]);
        } catch (\RuntimeException $e) {
            return self::refuse($json, self::EXIT_BLOCKED, 'boundary_search_refused', $e->getMessage(), self::USAGE);
        }

        if ($json) {
            // Canon::encode() already terminates with LF (Canon.php:102).
            echo Canon::encode($document);
        } else {
            self::render($document);
        }
        return match ($document['status']) {
            'complete' => self::EXIT_OK,
            'probe-required' => self::EXIT_PROBE_REQUIRED,
            default => self::EXIT_BLOCKED,
        };
    }

    private const USAGE = 'run `duo adapter boundary --releases=<release-list.json> --anchor=<version> '
        . '[--outcomes=<outcomes.json>] [--from=<version>] [--to=<version>] [--manifest=<name>] [--format=json]`';

    /**
     * The search itself, callable in-process so the offline corpus drives THIS
     * code rather than a re-implementation of it.
     *
     * @param array{slug:string, releases:list<array<string,mixed>>, source:string, recorded_at:string} $releases
     * @param array<string, array{version:string, outcome:string, signature:string}> $outcomes keyed by version
     * @param array{anchor:string, from:?string, to:?string, manifest:?string, declared_range:?array<string,string>} $options
     * @return array<string,mixed> a duo-adapter-boundary-search/v1 document
     */
    public static function search(array $releases, array $outcomes, array $options): array {
        $slug = $releases['slug'];
        $all = $releases['releases'];
        $options += ['from' => null, 'to' => null, 'manifest' => null, 'declared_range' => null];
        if (!is_string($options['anchor'] ?? null) || $options['anchor'] === '') {
            throw new \RuntimeException('a boundary search needs an anchor release believed green to search outward from');
        }
        $index = [];
        foreach ($all as $position => $entry) {
            $index[$entry['version']] = $position;
        }

        $fromVersion = $options['from'] ?? $all[0]['version'];
        $toVersion = $options['to'] ?? $all[count($all) - 1]['version'];
        foreach (['from' => $fromVersion, 'to' => $toVersion, 'anchor' => $options['anchor']] as $label => $version) {
            if (!isset($index[$version])) {
                throw new \RuntimeException(
                    "--$label names '$version', which the recorded release list for '$slug' does not contain"
                );
            }
        }
        // An outcome for a release the list does not carry means the two
        // documents describe different candidate sets — most often a list
        // re-recorded after the probes ran. Silently ignoring the row would
        // drop real evidence, and the monotonicity check below would then
        // never see the contradiction it exists to catch.
        foreach ($outcomes as $row) {
            if (!isset($index[$row['version']])) {
                throw new \RuntimeException(
                    "the outcome record carries release '{$row['version']}', which the recorded release list for "
                    . "'$slug' does not contain; the two documents describe different candidate sets"
                );
            }
        }

        $lowBound = $index[$fromVersion];
        $highBound = $index[$toVersion];
        if ($lowBound > $highBound) {
            throw new \RuntimeException("--from '$fromVersion' is newer than --to '$toVersion' in the recorded release order");
        }
        $anchor = $index[$options['anchor']];
        if ($anchor < $lowBound || $anchor > $highBound) {
            throw new \RuntimeException(
                "--anchor '{$options['anchor']}' lies outside the candidate window "
                . "$fromVersion..$toVersion — bisection needs a known-good release INSIDE the window to search out from"
            );
        }

        // Probe bookkeeping. `$pending` short-circuits every subsequent probe:
        // once the record is exhausted the search has exactly one question,
        // and asking a second would invent an ordering the driver never ran.
        $probes = [];
        $pending = null;
        $unresolved = [];
        $probe = static function (int $position, string $arm) use (
            &$probes,
            &$pending,
            &$unresolved,
            $all,
            $outcomes
        ): ?string {
            if ($pending !== null) {
                return null;
            }
            $version = $all[$position]['version'];
            foreach ($probes as $seen) {
                if ($seen['version'] === $version) {
                    return $seen['outcome'];
                }
            }
            if (!isset($outcomes[$version])) {
                $pending = ['version' => $version, 'arm' => $arm];
                return null;
            }
            $row = $outcomes[$version];
            $probes[] = [
                'version' => $version,
                'arm' => $arm,
                'outcome' => $row['outcome'],
                'signature' => $row['signature'],
            ];
            if ($row['outcome'] === self::OUTCOME_UNRESOLVED) {
                $unresolved[] = $version;
            }
            return $row['outcome'];
        };

        $window = [
            'from' => $fromVersion,
            'to' => $toVersion,
            'anchor' => $options['anchor'],
            'releases' => $highBound - $lowBound + 1,
        ];
        $bound = 1 + self::ceilLog2($anchor - $lowBound + 1) + self::ceilLog2($highBound - $anchor + 1);

        $anchorOutcome = $probe($anchor, 'anchor');
        if ($pending !== null) {
            return self::document($slug, 'probe-required', $window, $probes, $bound, null, null, $pending, $options);
        }
        if ($unresolved !== []) {
            return self::document(
                $slug,
                'blocked',
                $window,
                $probes,
                $bound,
                null,
                self::unresolvedBlock($unresolved),
                null,
                $options
            );
        }
        if ($anchorOutcome !== self::OUTCOME_GREEN) {
            return self::document($slug, 'blocked', $window, $probes, $bound, null, [
                'reason' => 'anchor_not_green',
                'detail' => "the anchor release '{$options['anchor']}' probed '$anchorOutcome', so there is no known-good "
                    . 'release to bisect outward from; every boundary this search could report would rest on an '
                    . 'assumption the record already contradicts',
                'versions' => [$options['anchor']],
            ], null, $options);
        }

        // Leftmost-green over [$lowBound, $anchor]. The invariant is that
        // $high is always a release that probed green, which is what makes a
        // failing release structurally unreachable as the reported floor.
        $low = $lowBound;
        $high = $anchor;
        while ($low < $high) {
            $mid = intdiv($low + $high, 2);
            $outcome = $probe($mid, 'floor');
            if ($outcome === null || $outcome === self::OUTCOME_UNRESOLVED) {
                break;
            }
            if ($outcome === self::OUTCOME_GREEN) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }
        $floor = $high;

        if ($pending === null && $unresolved === []) {
            // Rightmost-green over [$anchor, $highBound]; the mirrored
            // invariant keeps $low green, so the ceiling is green too.
            $low = $anchor;
            $high = $highBound;
            while ($low < $high) {
                $mid = intdiv($low + $high + 1, 2);
                $outcome = $probe($mid, 'ceiling');
                if ($outcome === null || $outcome === self::OUTCOME_UNRESOLVED) {
                    break;
                }
                if ($outcome === self::OUTCOME_GREEN) {
                    $low = $mid;
                } else {
                    $high = $mid - 1;
                }
            }
            $ceiling = $low;
        } else {
            $ceiling = $anchor;
        }

        if ($pending !== null) {
            return self::document($slug, 'probe-required', $window, $probes, $bound, null, null, $pending, $options);
        }
        if ($unresolved !== []) {
            return self::document(
                $slug,
                'blocked',
                $window,
                $probes,
                $bound,
                null,
                self::unresolvedBlock($unresolved),
                null,
                $options
            );
        }

        // Contiguity is an ASSUMPTION of bisection, so the record gets to
        // contradict it. Every recorded outcome inside the settled window is
        // consulted here, not merely the probed ones: an outcome the search
        // never needed is still evidence, and evidence that disproves the
        // window must block it rather than sit unread beside it.
        $contradictions = [];
        foreach ($outcomes as $row) {
            $position = $index[$row['version']];
            if ($position < $floor || $position > $ceiling || $row['outcome'] === self::OUTCOME_GREEN) {
                continue;
            }
            $contradictions[] = $row['version'];
        }
        if ($contradictions !== []) {
            return self::document($slug, 'blocked', $window, $probes, $bound, null, [
                'reason' => 'non_monotone_outcomes',
                'detail' => 'the recorded outcomes place a non-green release INSIDE the bisected window '
                    . $all[$floor]['version'] . '..' . $all[$ceiling]['version'] . ', which disproves the contiguity '
                    . 'bisection assumes; a narrower guess would be a guess, so no boundary is proposed',
                'versions' => $contradictions,
            ], null, $options);
        }

        $boundary = [
            'floor' => $all[$floor]['version'],
            'ceiling' => $all[$ceiling]['version'],
            // A window edge is not a transition. "Green at least this far
            // down" and "the release below this one fails" are different
            // claims, and only the second is a boundary.
            'floor_transition_evidenced' => $floor > $lowBound,
            'ceiling_transition_evidenced' => $ceiling < $highBound,
        ];

        return self::document($slug, 'complete', $window, $probes, $bound, $boundary, null, null, $options, $all, $index);
    }

    /**
     * @param list<array{version:string, arm:string, outcome:string, signature:string}> $probes
     * @param array<string,mixed>|null $boundary
     * @param array<string,mixed>|null $blocked
     * @param array{version:string, arm:string}|null $pending
     * @param array<string,mixed> $options
     * @param list<array<string,mixed>> $all
     * @param array<string,int> $index
     * @return array<string,mixed>
     */
    private static function document(
        string $slug,
        string $status,
        array $window,
        array $probes,
        int $bound,
        ?array $boundary,
        ?array $blocked,
        ?array $pending,
        array $options,
        array $all = [],
        array $index = []
    ): array {
        $document = [
            'format' => self::FORMAT,
            'slug' => $slug,
            'status' => $status,
            'window' => $window,
            'probes' => $probes,
            'probe_count' => count($probes),
            'probe_bound' => $bound,
            'boundary' => $boundary,
            'blocked' => $blocked,
            'next_probe' => $pending,
            'proposed_lock_rows' => $boundary === null ? null : self::proposeLockRows($slug, $probes, $all, $index),
            'declared' => self::declaredBlock($options, $boundary),
            'review_required' => self::REVIEW_REQUIRED,
            'evidence_limits' => self::evidenceLimits($status, $boundary, $probes, $window),
        ];
        return $document;
    }

    /**
     * A lock fragment with the lock's own two top-level namespaces, so the
     * committed jq program in `fetch-artifact.sh:10-45` validates it as-is —
     * that program pins `keys == ["plugins","themes"]`, so a fragment carrying
     * only the one namespace it filled would be refused by the schema it is
     * meant to extend.
     *
     * One row per PROBED release, which is the whole record and not a summary:
     * a green probe is a `certified-boundary` (an exact version proven to
     * install, round-trip and recapture byte-identically), a failing probe is a
     * `refusal-fixture` (an exact version proven not to). That mapping is read
     * off the artifact library rather than invented — every bisection-shaped
     * block in it today is exactly this: the greens it certified and the
     * adjacent failures that bracket them.
     *
     * @param list<array{version:string, arm:string, outcome:string, signature:string}> $probes
     * @param list<array<string,mixed>> $all
     * @param array<string,int> $index
     * @return array{plugins:array<string,array<string,array<string,string>>>, themes:array<string,mixed>}
     */
    private static function proposeLockRows(string $slug, array $probes, array $all, array $index): array {
        $rows = [];
        foreach ($probes as $probe) {
            $entry = $all[$index[$probe['version']]];
            $row = [
                'url' => $entry['url'],
                'sha256' => $entry['sha256'],
                'role' => $probe['outcome'] === self::OUTCOME_GREEN ? self::ROLE_CERTIFIED : self::ROLE_REFUSAL,
            ];
            if (isset($entry['archive_root'])) {
                $row['archive_root'] = $entry['archive_root'];
            }
            $rows[$probe['version']] = $row;
        }
        // `themes` is an empty OBJECT, not an empty array: the jq program
        // requires `.themes | type == "object"` (fetch-artifact.sh:29-35), and
        // an empty PHP array re-encodes as `[]` — which that schema refuses.
        return ['plugins' => [$slug => $rows], 'themes' => new \stdClass()];
    }

    /**
     * @param array<string,mixed> $options
     * @param array<string,mixed>|null $boundary
     * @return array<string,mixed>|null
     */
    private static function declaredBlock(array $options, ?array $boundary): ?array {
        $range = $options['declared_range'] ?? null;
        if ($range === null) {
            return null;
        }
        $block = [
            'manifest' => $options['manifest'],
            'range' => $range,
            // `CodeCompatibility.php:759-761` is the semantics every code-half
            // check applies: min inclusive, max EXCLUSIVE. Restated here
            // because a reviewer comparing an evidenced ceiling against a
            // declared max will otherwise assume the wrong end is closed.
            'interval' => 'min inclusive, max exclusive',
            'widens_declared_claim' => null,
            'unevidenced_declared_span' => null,
        ];
        if ($boundary === null) {
            return $block;
        }
        $floorIn = version_compare($boundary['floor'], $range['min'], '>=')
            && version_compare($boundary['floor'], $range['max'], '<');
        $ceilingIn = version_compare($boundary['ceiling'], $range['min'], '>=')
            && version_compare($boundary['ceiling'], $range['max'], '<');
        $block['widens_declared_claim'] = !($floorIn && $ceilingIn);
        // The declared span this search did NOT evidence. It is the recurring
        // cost AdapterContractGrammar's refusal creates, and naming it is the
        // point: a range is not proven by proving two versions inside it.
        $block['unevidenced_declared_span'] = [
            'below_floor' => version_compare($range['min'], $boundary['floor'], '<')
                ? $range['min'] . '..<' . $boundary['floor'] : null,
            'above_ceiling' => version_compare($boundary['ceiling'], $range['max'], '<')
                ? '>' . $boundary['ceiling'] . '..' . $range['max'] : null,
        ];
        return $block;
    }

    /**
     * Printed in the document on EVERY run, complete or blocked, for the same
     * reason `duo lint-tree` prints its deferred classes unconditionally
     * (`LintTree.php:41-51`): a tool that discloses its limits only when
     * something goes wrong lets silence read as "everything was checked".
     *
     * @param list<array<string,mixed>> $probes
     * @param array<string,mixed>|null $boundary
     * @param array<string,mixed> $window
     * @return list<string>
     */
    private static function evidenceLimits(string $status, ?array $boundary, array $probes, array $window): array {
        $limits = [
            'The window interior is PROBED, not exhausted: ' . count($probes) . ' of '
                . $window['releases'] . ' candidate release(s) were run. A release between the floor and the '
                . 'ceiling that no probe touched is unevidenced, not proven green.',
            'Bisection assumes the green releases are contiguous. The search refuses '
                . '(non_monotone_outcomes) when a recorded outcome contradicts that, but silence from an '
                . 'unprobed release is not evidence for it.',
            'Each outcome is one recorded pair run against one seed hook. It is evidence about that '
                . 'plugin content shape, not about every shape a site can hold.',
        ];
        if ($status === 'complete' && $boundary !== null) {
            if ($boundary['floor_transition_evidenced'] !== true) {
                $limits[] = 'The floor is the candidate window\'s own low edge: nothing below '
                    . $boundary['floor'] . ' was probed, so this is "green at least this far down", '
                    . 'not an evidenced transition.';
            }
            if ($boundary['ceiling_transition_evidenced'] !== true) {
                $limits[] = 'The ceiling is the candidate window\'s own high edge: nothing above '
                    . $boundary['ceiling'] . ' was probed, so this is "green at least this far up", '
                    . 'not an evidenced transition.';
            }
        }
        return $limits;
    }

    /**
     * @param list<string> $versions
     * @return array<string,mixed>
     */
    private static function unresolvedBlock(array $versions): array {
        return [
            'reason' => 'artifact_unresolved',
            'detail' => 'a pinned artifact could not be fetched or its digest did not verify. That is a fact about '
                . 'the DOWNLOAD, not about the plugin, so it is reported here rather than folded into boot-fatal: '
                . 'treating an unreachable mirror as a failing release would let an outage move a boundary. '
                . 'Resolve the artifact and re-run — the outcomes already recorded are still valid.',
            'versions' => $versions,
        ];
    }

    private const REVIEW_REQUIRED = [
        'manifest_edit' => 'not-performed',
        'files' => [
            'adapter-packages/<name>/package/manifest.json',
            'adapter-packages/<name>/package/disposition.json',
        ],
        'why' => 'The range and its restatement are ONE reviewed human edit. ManifestDispositions.php:632-637 '
            . 'refuses the pair the moment they disagree ("versions disagree with its manifest contract"), and '
            . 'the package payload is adapter identity: every deployed site holding a '
            . 'compiled artifact starts refusing compiled_artifact_manifest_mismatch until it is recompiled and '
            . 're-pinned. This document is evidence FOR that review, never an input to it.',
    ];

    /**
     * Public because `AdapterProposals` — the scheduled job that runs this
     * planner over a whole ledger directory — reads the SAME two documents. A
     * second parser for them would be a second grammar, and the day the two
     * disagreed by one rule (a missing digest, a mis-ordered list) the job
     * would fold a candidate set this command refuses.
     *
     * @return array<string,mixed>
     */
    public static function readReleaseList(string $path): array {
        $data = self::readDocument($path, self::RELEASES_FORMAT, 'release list');
        $slug = $data['slug'] ?? null;
        if (!is_string($slug) || preg_match('/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/D', $slug) !== 1) {
            throw new \RuntimeException("release list '$path' must carry a 'slug' matching the artifact-lock slug grammar");
        }
        foreach (['source', 'recorded_at'] as $provenance) {
            $value = $data[$provenance] ?? null;
            if (!is_string($value) || trim($value) === '') {
                throw new \RuntimeException(
                    "release list '$path' must carry a non-empty '$provenance' — a recorded list with no "
                    . 'provenance is indistinguishable from an invented one, and this tool never scrapes'
                );
            }
        }
        $releases = $data['releases'] ?? null;
        if (!is_array($releases) || !array_is_list($releases) || $releases === []) {
            throw new \RuntimeException("release list '$path' must carry a non-empty 'releases' array");
        }
        $seen = [];
        $previous = null;
        $normalized = [];
        foreach ($releases as $position => $entry) {
            $where = "release list '$path' entry #$position";
            if (!is_array($entry) || array_is_list($entry)) {
                throw new \RuntimeException("$where must be an object");
            }
            $version = $entry['version'] ?? null;
            if (!is_string($version) || preg_match('/^[0-9A-Za-z][0-9A-Za-z._-]*$/D', $version) !== 1) {
                throw new \RuntimeException("$where must carry a 'version' matching the artifact-lock version grammar");
            }
            if (isset($seen[$version])) {
                throw new \RuntimeException("$where repeats version '$version'; a release appears once");
            }
            $seen[$version] = true;
            $url = $entry['url'] ?? null;
            if (!is_string($url) || preg_match('/^https:\/\/[^\s\'"]+$/D', $url) !== 1) {
                throw new \RuntimeException("$where must carry an https 'url' with no whitespace or quotes");
            }
            $sha256 = $entry['sha256'] ?? null;
            if (!is_string($sha256) || preg_match('/^[0-9a-f]{64}$/D', $sha256) !== 1) {
                throw new \RuntimeException(
                    "$where must carry a 64-hex 'sha256'. Every candidate carries its digest or the list is "
                    . 'refused: a half-recorded list would force this tool to choose between fabricating a lock '
                    . 'row and silently dropping one, and it does neither'
                );
            }
            $normalized[$position] = ['version' => $version, 'url' => $url, 'sha256' => $sha256];
            $archiveRoot = $entry['archive_root'] ?? null;
            if ($archiveRoot !== null) {
                if (!is_string($archiveRoot) || preg_match('/^[a-z0-9][a-z0-9._-]*[a-z0-9]$/D', $archiveRoot) !== 1) {
                    throw new \RuntimeException("$where declares an 'archive_root' that is not a lock-shaped slug");
                }
                $normalized[$position]['archive_root'] = $archiveRoot;
            }
            // A mis-ordered list does not fail loudly; it bisects to the wrong
            // boundary. So the recorded order must agree with version_compare()
            // or the list is refused by name, with the pair that disagrees.
            if ($previous !== null && version_compare($previous, $version, '>=')) {
                throw new \RuntimeException(
                    "$where records '$version' after '$previous', but version_compare() orders them the other way. "
                    . 'The list is the release ORDER a bisection walks; a mis-ordered one silently reports the '
                    . 'wrong boundary, so it is refused rather than sorted'
                );
            }
            $previous = $version;
        }
        return [
            'slug' => $slug,
            'source' => (string) $data['source'],
            'recorded_at' => (string) $data['recorded_at'],
            'releases' => array_values($normalized),
        ];
    }

    /**
     * Public for the same reason `readReleaseList()` above is: one grammar for
     * the outcome record, whether one subject is being bisected by hand or the
     * whole ledger is being re-bisected by the scheduled job.
     *
     * @return array<string, array{version:string, outcome:string, signature:string}>
     */
    public static function readOutcomeTable(string $path, string $slug): array {
        $data = self::readDocument($path, self::OUTCOMES_FORMAT, 'outcome table');
        if (($data['slug'] ?? null) !== $slug) {
            throw new \RuntimeException(
                "outcome table '$path' records slug '" . var_export($data['slug'] ?? null, true)
                . "' but the release list is for '$slug'; a boundary assembled from two plugins' outcomes "
                . 'would be a fabrication, so the pair must agree'
            );
        }
        $rows = $data['outcomes'] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) {
            throw new \RuntimeException("outcome table '$path' must carry an 'outcomes' array");
        }
        $table = [];
        foreach ($rows as $position => $row) {
            $where = "outcome table '$path' entry #$position";
            if (!is_array($row) || array_is_list($row)) {
                throw new \RuntimeException("$where must be an object");
            }
            $version = $row['version'] ?? null;
            if (!is_string($version) || $version === '') {
                throw new \RuntimeException("$where must carry a 'version'");
            }
            if (isset($table[$version])) {
                throw new \RuntimeException("$where repeats version '$version'; a release has one outcome");
            }
            $outcome = $row['outcome'] ?? null;
            if (!is_string($outcome) || !in_array($outcome, self::OUTCOMES, true)) {
                throw new \RuntimeException(
                    "$where must carry an 'outcome' of " . implode(' | ', self::OUTCOMES)
                );
            }
            $signature = $row['signature'] ?? null;
            if (!is_string($signature) || trim($signature) === '') {
                throw new \RuntimeException(
                    "$where must carry a non-empty 'signature'. The deliverable of this search is the sentence "
                    . '"release R round-trips; R-1 fatals with THIS signature" — an outcome with no signature '
                    . 'is a verdict with no evidence behind it'
                );
            }
            $table[$version] = ['version' => $version, 'outcome' => $outcome, 'signature' => $signature];
        }
        return $table;
    }

    /**
     * Read-only, and the only thing this command ever reads out of
     * the selected adapter package. It exists so the document can state the delta a reviewer
     * actually acts on; nothing here writes, and no code path in this file
     * opens a manifest for writing.
     *
     * @return array{min:string, max:string}
     */
    private static function readDeclaredRange(string $dir, string $name): array {
        $root = rtrim($dir, '/');
        if (is_dir($root . '/adapter-packages') || is_dir($root . '/platform/adapter-library')) {
            $package = AdapterLibrary::fromSourceTree($root)->package($name);
            if ($package === null) {
                throw new \RuntimeException("--manifest '$name' has no adapter package under $root");
            }
            $path = $package->manifestPath();
        } else {
            // `--manifests` remains an explicit compatibility input for an
            // archived flat library; the production default never takes it.
            $path = $root . '/' . $name . '.json';
        }
        if (!is_file($path)) {
            throw new \RuntimeException("--manifest '$name' has no library file at $path");
        }
        $data = Canon::decode(self::readFile($path));
        if (!is_array($data)) {
            throw new \RuntimeException("manifest '$path' is not an object");
        }
        $range = $data['version_range'] ?? null;
        if (!is_array($range) || !is_string($range['min'] ?? null) || !is_string($range['max'] ?? null)) {
            throw new \RuntimeException(
                "manifest '$name' declares no {min,max} 'version_range'. AdapterContractGrammar.php:73-79 "
                . 'refuses a plugin claim without one, so a manifest reaching this point with none is not '
                . 'loadable and there is nothing to compare evidence against'
            );
        }
        return ['min' => $range['min'], 'max' => $range['max']];
    }

    /**
     * @return array<string,mixed>
     */
    private static function readDocument(string $path, string $format, string $label): array {
        if (!is_file($path)) {
            throw new \RuntimeException("$label '$path' does not exist");
        }
        $data = Canon::decode(self::readFile($path));
        if (!is_array($data) || array_is_list($data)) {
            throw new \RuntimeException("$label '$path' is not a JSON object");
        }
        if (($data['format'] ?? null) !== $format) {
            throw new \RuntimeException(
                "$label '$path' declares format " . var_export($data['format'] ?? null, true)
                . " but this command reads $format"
            );
        }
        return $data;
    }

    private static function readFile(string $path): string {
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException("could not read '$path'");
        }
        return $raw;
    }

    /** The smallest number of halvings that resolves $count candidates. */
    private static function ceilLog2(int $count): int {
        $steps = 0;
        while ($count > 1) {
            $count = intdiv($count + 1, 2);
            $steps++;
        }
        return $steps;
    }

    /**
     * @param list<string> $args
     * @return array{releases:string, outcomes:?string, anchor:string, from:?string, to:?string, manifest:?string, manifests:string, format:string}
     */
    private static function options(array $args): array {
        $values = ['--manifests' => dirname(__DIR__, 3), '--format' => 'human'];
        $seen = [];
        foreach ($args as $arg) {
            if ($arg === 'boundary' && !isset($seen['boundary'])) {
                // The sub-verb itself, kept in argv by the router.
                $seen['boundary'] = true;
                continue;
            }
            if (!str_starts_with($arg, '--')) {
                throw new \RuntimeException("unexpected argument '$arg'");
            }
            $flag = explode('=', $arg, 2)[0];
            if (!in_array($flag, self::FLAGS, true)) {
                throw new \RuntimeException("unsupported flag '$flag'");
            }
            // A repeated flag is refused rather than last-wins, the posture
            // every host verb here takes (AdapterCatalog.php:230-241): a second
            // --outcomes silently replacing the first would search a record the
            // operator did not name and report it as though they had.
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
        foreach (['--releases', '--anchor'] as $required) {
            if (!isset($values[$required])) {
                throw new \RuntimeException(
                    "$required is required" . ($required === '--anchor'
                        ? ' — bisection searches OUTWARD from a release believed green, so there is no '
                        . 'meaningful default; name the version this adapter is certified at today'
                        : '')
                );
            }
        }
        if (!in_array($values['--format'], ['human', 'json'], true)) {
            throw new \RuntimeException("--format must be 'human' or 'json'");
        }
        return [
            'releases' => $values['--releases'],
            'outcomes' => $values['--outcomes'] ?? null,
            'anchor' => $values['--anchor'],
            'from' => $values['--from'] ?? null,
            'to' => $values['--to'] ?? null,
            'manifest' => $values['--manifest'] ?? null,
            'manifests' => $values['--manifests'],
            'format' => $values['--format'],
        ];
    }

    /** @param array<string,mixed> $document */
    private static function render(array $document): void {
        $window = (array) $document['window'];
        echo 'boundary ' . (string) $document['slug'] . ': ' . (string) $document['status']
            . ' over ' . (int) $window['releases'] . ' release(s) '
            . (string) $window['from'] . '..' . (string) $window['to']
            . ' (anchor ' . (string) $window['anchor'] . ")\n";
        foreach ((array) $document['probes'] as $probe) {
            $probe = (array) $probe;
            echo 'probe ' . (string) $probe['version'] . ' [' . (string) $probe['arm'] . ']: '
                . (string) $probe['outcome'] . ' — ' . (string) $probe['signature'] . "\n";
        }
        echo 'probes: ' . (int) $document['probe_count'] . ' of at most ' . (int) $document['probe_bound']
            . " for this window\n";

        $next = $document['next_probe'] ?? null;
        if (is_array($next)) {
            echo 'next: probe ' . (string) $next['version'] . ' (' . (string) $next['arm'] . " arm)\n";
        }
        $blocked = $document['blocked'] ?? null;
        if (is_array($blocked)) {
            fwrite(STDERR, 'duo: adapter boundary: ' . (string) $blocked['reason'] . ': '
                . (string) $blocked['detail'] . "\n");
            fwrite(STDERR, 'duo: adapter boundary: releases: '
                . implode(', ', array_map('strval', (array) $blocked['versions'])) . "\n");
        }
        $boundary = $document['boundary'] ?? null;
        if (is_array($boundary)) {
            echo 'floor: ' . (string) $boundary['floor']
                . ($boundary['floor_transition_evidenced'] === true ? ' (transition evidenced)' : ' (window edge)')
                . "\n";
            echo 'ceiling: ' . (string) $boundary['ceiling']
                . ($boundary['ceiling_transition_evidenced'] === true ? ' (transition evidenced)' : ' (window edge)')
                . "\n";
        }
        $declared = $document['declared'] ?? null;
        if (is_array($declared)) {
            $range = (array) $declared['range'];
            echo 'declared: ' . (string) $declared['manifest'] . ' ' . (string) $range['min']
                . ' <= v < ' . (string) $range['max']
                . ($declared['widens_declared_claim'] === true
                    ? ' — EVIDENCE FALLS OUTSIDE THE DECLARED RANGE' : '') . "\n";
            $span = $declared['unevidenced_declared_span'] ?? null;
            if (is_array($span)) {
                foreach (['below_floor', 'above_ceiling'] as $side) {
                    if (is_string($span[$side] ?? null)) {
                        echo 'unevidenced: ' . (string) $span[$side] . ' of the declared range carries no probe'
                            . " in this search\n";
                    }
                }
            }
        }
        $rows = $document['proposed_lock_rows'] ?? null;
        if (is_array($rows)) {
            echo "proposed package artifact rows (evidence for review, not an edit):\n";
            echo Canon::encode($rows);
        }
        foreach ((array) $document['evidence_limits'] as $limit) {
            echo 'limit: ' . (string) $limit . "\n";
        }
        $review = (array) $document['review_required'];
        echo 'review: manifest edit ' . (string) $review['manifest_edit'] . ' — '
            . implode(' + ', array_map('strval', (array) $review['files']))
            . " remain a reviewed human edit\n";
    }

    private static function refuse(bool $json, int $code, string $reason, string $message, string $remediation): int {
        if ($json) {
            CommandOutput::renderRefusalJson('adapter boundary', $reason, $message, $remediation);
            return $code;
        }
        fwrite(STDERR, 'duo: adapter boundary: ' . $message . "\n");
        fwrite(STDERR, 'duo: adapter boundary: remedy: ' . $remediation . "\n");
        return $code;
    }
}
