<?php
/**
 * Offline characterization for `wprism adapter boundary` — version-range
 * bisection that produces EVIDENCE and can never produce a manifest edit.
 *
 * ## What is actually driven here
 *
 * `AdapterBoundary::search()` — the real search, not a model of it. A probe is
 * a full pair round-trip (fetch the digest-pinned artifact, reset, install the
 * exact version, seed through `adapter-packages/<slug>/tests/certify/version-matrix.sh`,
 * capture, deploy, apply, recapture, require byte-identity), so the command is
 * a PLANNER over a recorded outcome table and this suite feeds it recorded
 * tables. Nothing here starts docker and nothing here reaches the network,
 * which is the same property the command itself has by construction: its
 * candidate set is a recorded `wprism-adapter-release-list/v1` document, never a
 * live scrape of a plugin's release history.
 *
 * ## The reproduction case, and exactly how strong it is
 *
 * The convention-discovered artifact library already IS a bisection result. Its
 * three roles are the vocabulary — `certified-boundary` for a version proven to
 * install and round-trip, `refusal-fixture` for one proven not to,
 * `exercise-fixture` for a version installed to exercise something with no
 * boundary claim attached. Fifteen of its seventeen plugin blocks have the shape
 * a bisection trace has: the greens, bracketed by the adjacent failures. So
 * this suite derives a release list and an outcome table from each such block
 * and asserts the search re-emits that block.
 *
 * What that proves, stated narrowly so nobody reads more into it: the search
 * PROBES exactly the versions the block records and maps each outcome to the
 * role the block gives it. The url/sha256/archive_root passthrough is asserted
 * too but is trivially true, since the derived release list is the block's own
 * rows. The load-bearing half is the probe SET — a search that walked the
 * window differently would probe a version the block does not have, or miss
 * one it does, and `paid-memberships-pro` (a green run bracketed by refusals on
 * BOTH sides) and `wordpress-seo` (an interior green the ceiling arm lands on)
 * are the two blocks where that is a real constraint rather than an accident of
 * three-element lists.
 *
 * The two blocks without a certified anchor are explicit: `wpforms-lite`
 * is experimental, and `duplicate-post` lost its managed-clone certification.
 * An exercise fixture records intent, not a certified version boundary; even
 * an adjacent refusal cannot make either block a bisection result to reproduce.
 *
 * ## The properties the synthetic cases exist for
 *
 * A three-element list cannot show O(log n), and no committed block contains
 * the contradictions the search has to refuse. Those run over synthetic lists
 * whose slug (`wprism-boundary-fixture`) and host (`fixtures.invalid`) say what
 * they are:
 *
 *   - 64 releases resolve in 11 probes against a 12-probe bound, and every
 *     probe count in the corpus is checked against the same bound.
 *   - a failing release is never proposed as a boundary — asserted over EVERY
 *     window of a list with a green run at [20, 44], including the windows
 *     whose edges are failures.
 *   - a recorded failure inside the settled window BLOCKS
 *     (`non_monotone_outcomes`) instead of narrowing by guess, and it does so
 *     from an outcome the search never probed, because an unread contradiction
 *     is still a contradiction.
 *   - `artifact-unresolved` blocks and is REPORTED by version. This is the
 *     silent-narrowing bug the four-outcome vocabulary exists to prevent: a
 *     404 or a digest mismatch is a fact about the download, and folding it
 *     into `boot-fatal` would let a mirror outage move a certified boundary.
 *
 * ## And the one negative property the whole design rests on
 *
 * No package payload is written. Asserted the only way worth asserting it:
 * every shipped adapter-library file is hashed before the suite runs and after, across the
 * in-process searches AND the real subprocess runs, including the run that
 * passes `--manifest=acf` and reads a range out of that very directory.
 */
declare(strict_types=1);

// From offline/adapter/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

$repoRoot = dirname(__DIR__, 4);
require_once $repoRoot . '/agent/src/Kernel/Canon.php';
require_once $repoRoot . '/agent/src/Policy/AdapterLibrary.php';
require_once $repoRoot . '/cli/src/Command/CommandOutput.php';
require_once $repoRoot . '/cli/src/Adapter/AdapterBoundary.php';

use WPrism\AdapterLibrary;
use WPrism\Canon;
use WPrism\Orchestrator\AdapterBoundary;

/** @return array<string,string> path => sha256 for every shipped adapter-library file */
function boundary_manifest_digests(string $root): array {
    $digests = [];
    foreach (AdapterLibrary::fromSourceTree($root)->scanFiles() as $file) {
        $digests[$file] = (string) hash_file('sha256', $file);
    }
    ksort($digests);
    return $digests;
}

$manifestsBefore = boundary_manifest_digests($repoRoot);

/**
 * A release list in the command's own recorded shape.
 *
 * @param list<array<string,mixed>> $releases
 * @return array<string,mixed>
 */
function boundary_release_list(string $slug, array $releases, string $source = 'suite-derived'): array {
    return [
        'format' => AdapterBoundary::RELEASES_FORMAT,
        'slug' => $slug,
        'source' => $source,
        'recorded_at' => '2026-08-24',
        'releases' => $releases,
    ];
}

/** A deterministic 64-hex string that is visibly a fixture, never a real digest. */
function boundary_fixture_digest(string $seed): string {
    return hash('sha256', 'wprism-boundary-fixture/' . $seed);
}

/**
 * @param list<string> $versions
 * @return list<array<string,mixed>>
 */
function boundary_fixture_releases(array $versions): array {
    $rows = [];
    foreach ($versions as $version) {
        $rows[] = [
            'version' => $version,
            'url' => 'https://fixtures.invalid/wprism-boundary-fixture.' . $version . '.zip',
            'sha256' => boundary_fixture_digest($version),
        ];
    }
    return $rows;
}

/**
 * @param array<string,string> $outcomes version => outcome
 * @return array<string, array{version:string, outcome:string, signature:string}>
 */
function boundary_outcome_table(array $outcomes): array {
    $table = [];
    foreach ($outcomes as $version => $outcome) {
        $table[(string) $version] = [
            'version' => (string) $version,
            'outcome' => $outcome,
            'signature' => 'recorded fixture outcome ' . $outcome . ' for ' . $version,
        ];
    }
    return $table;
}

// ------------------------------------------------------------------ 1. the lock

require_once $repoRoot . '/tools/src/ArtifactLibrary.php';
$lock = \WPrism\Tooling\ArtifactLibrary::load($repoRoot);
wprism_check(isset($lock['plugins']), 'the convention-discovered artifact library reads');

$bisectionShaped = [];
$exerciseOnly = [];
foreach ((array) $lock['plugins'] as $slug => $block) {
    $roles = array_column((array) $block, 'role');
    if (in_array(AdapterBoundary::ROLE_CERTIFIED, $roles, true)) {
        $bisectionShaped[$slug] = (array) $block;
    } else {
        $exerciseOnly[$slug] = $roles;
    }
}
// #561 moved the-events-calendar out of the exercise-only column: its block now
// carries certified-boundary roles on 6.17.2/6.17.3 and a refusal-fixture on
// 6.17.1, so it is a bisection RESULT and the loops below reproduce it like any
// other certified block. Duplicate Post's withdrawn claim now joins WPForms
// outside this certified-anchor set; its adjacent refusal does not restore it.
wprism_check_same(
    17,
    count($bisectionShaped),
    '17 of the 21 committed plugin blocks carry a certified-boundary role and are therefore bisection results'
);
wprism_check_same(
    ['block-visibility', 'download-manager', 'duplicate-post', 'wpforms-lite'],
    array_keys($exerciseOnly),
    'the blocks that are not bisection results are exercise-fixture only, so the search never proposes them as a boundary'
);

// The lock authors its rows oldest-first inside each block, which is the order
// a bisection walks. Asserting it here means the derivation below is reading a
// property the file already has rather than imposing one on it.
foreach ($bisectionShaped as $slug => $block) {
    $versions = array_keys($block);
    $sorted = $versions;
    usort($sorted, static fn(string $a, string $b): int => version_compare($a, $b));
    wprism_check_same($sorted, $versions, "$slug rows are already in release order in the committed lock");
}

// ------------------------------------------------ 2. every block reproduces

foreach ($bisectionShaped as $slug => $block) {
    $releases = [];
    $outcomes = [];
    $anchor = null;
    foreach ($block as $version => $entry) {
        $row = ['version' => (string) $version, 'url' => $entry['url'], 'sha256' => $entry['sha256']];
        if (isset($entry['archive_root'])) {
            $row['archive_root'] = $entry['archive_root'];
        }
        $releases[] = $row;
        // The role IS the recorded outcome, and the signature says so rather
        // than inventing a fatal message no run produced.
        $green = $entry['role'] === AdapterBoundary::ROLE_CERTIFIED;
        $outcomes[(string) $version] = [
            'version' => (string) $version,
            'outcome' => $green ? AdapterBoundary::OUTCOME_GREEN : AdapterBoundary::OUTCOME_BOOT_FATAL,
            'signature' => 'replayed from the committed role ' . (string) $entry['role']
                . ' in the package-owned artifact library',
        ];
        // The anchor is the oldest certified version — the release a reviewer
        // already knows is green, which is what a real bisection starts from.
        if ($green && $anchor === null) {
            $anchor = (string) $version;
        }
    }

    $document = AdapterBoundary::search(
        boundary_release_list((string) $slug, $releases, 'package-owned artifact library'),
        $outcomes,
        ['anchor' => $anchor]
    );

    wprism_check_same('complete', $document['status'], "$slug: the search completes");
    wprism_check(
        $document['probe_count'] <= $document['probe_bound'],
        "$slug: {$document['probe_count']} probe(s) within the O(log releases) bound of {$document['probe_bound']}"
    );
    // Compared as encoded bytes on both sides: `themes` is an empty stdClass
    // (the jq schema requires an object), and two distinct empty objects are
    // never `===` to each other in PHP.
    wprism_check_json_equal(
        Canon::encode(['plugins' => [$slug => $block], 'themes' => new stdClass()]),
        Canon::encode($document['proposed_lock_rows']),
        "$slug: the proposal reproduces the committed lock block exactly — same versions, same roles, same pins"
    );
}

// The two blocks where the probe SET is a real constraint rather than an
// artefact of a three-element list, called out by name so a future change to
// the walk cannot quietly pass by shrinking what is checked.
$pmpro = $bisectionShaped['paid-memberships-pro'];
wprism_check_same(
    ['3.8.1', '3.8.2', '3.8.3', '3.8.4'],
    array_keys($pmpro),
    'paid-memberships-pro brackets its green run with refusals on BOTH sides — the ceiling arm has to find the upper one'
);
$yoast = $bisectionShaped['wordpress-seo'];
wprism_check_same(
    ['27.9', '28.0', '28.2', '28.3'],
    array_keys($yoast),
    'wordpress-seo carries an interior green (28.2) the ceiling arm lands on before settling'
);

// ------------------------------------------------- 3. O(log releases) probes

$versions = [];
for ($i = 1; $i <= 64; $i++) {
    $versions[] = '1.' . $i . '.0';
}
$greenLow = 20;   // '1.21.0'
$greenHigh = 44;  // '1.45.0'
$outcomeMap = [];
foreach ($versions as $position => $version) {
    $outcomeMap[$version] = ($position >= $greenLow && $position <= $greenHigh)
        ? AdapterBoundary::OUTCOME_GREEN
        : AdapterBoundary::OUTCOME_BOOT_FATAL;
}
$wide = boundary_release_list('wprism-boundary-fixture', boundary_fixture_releases($versions));
$wideOutcomes = boundary_outcome_table($outcomeMap);

$document = AdapterBoundary::search($wide, $wideOutcomes, ['anchor' => $versions[32]]);
wprism_check_same('complete', $document['status'], '64 releases: the search completes');
wprism_check_same($versions[$greenLow], $document['boundary']['floor'], '64 releases: the floor is the true lowest green');
wprism_check_same($versions[$greenHigh], $document['boundary']['ceiling'], '64 releases: the ceiling is the true highest green');
wprism_check_same(
    12,
    $document['probe_bound'],
    '64 releases: the stated bound is 1 + ceil(log2 33) + ceil(log2 32) = 12'
);
wprism_check_same(11, $document['probe_count'], '64 releases: 11 probes, not the 64 an exhaustive sweep would cost');
wprism_check_same(
    true,
    $document['boundary']['floor_transition_evidenced'] && $document['boundary']['ceiling_transition_evidenced'],
    '64 releases: both transitions are evidenced, because a failing release was probed on each side'
);

// ------------------------- 4. a failing release is NEVER proposed as a boundary

$windowsChecked = 0;
foreach ([0, 5, 19, 20, 21, 30] as $low) {
    foreach ([43, 44, 45, 50, 63] as $high) {
        if ($low > 32 || $high < 32) {
            continue;
        }
        $windowsChecked++;
        $result = AdapterBoundary::search($wide, $wideOutcomes, [
            'anchor' => $versions[32],
            'from' => $versions[$low],
            'to' => $versions[$high],
        ]);
        wprism_check_same('complete', $result['status'], "window $low..$high completes");
        $floor = $result['boundary']['floor'];
        $ceiling = $result['boundary']['ceiling'];
        wprism_check_same(
            [AdapterBoundary::OUTCOME_GREEN, AdapterBoundary::OUTCOME_GREEN],
            [$outcomeMap[$floor], $outcomeMap[$ceiling]],
            "window $low..$high: both proposed endpoints are releases that probed GREEN"
        );
        // Narrower-or-equal to the truth, at both ends, in every window.
        wprism_check(
            version_compare($floor, $versions[$greenLow], '>=') && version_compare($ceiling, $versions[$greenHigh], '<='),
            "window $low..$high: the proposal is narrower-or-equal to the true green run"
        );
        foreach ((array) $result['proposed_lock_rows']['plugins']['wprism-boundary-fixture'] as $version => $row) {
            $expected = $outcomeMap[(string) $version] === AdapterBoundary::OUTCOME_GREEN
                ? AdapterBoundary::ROLE_CERTIFIED
                : AdapterBoundary::ROLE_REFUSAL;
            wprism_check_same($expected, $row['role'], "window $low..$high: $version carries the role its outcome implies");
        }
    }
}
wprism_check_same(
    30,
    $windowsChecked,
    'thirty windows were searched, including ones whose own edges are failures'
);

// ------------------------------- 5. a contradiction blocks, it never narrows

// The contradiction sits at a release the walk never probes, so the block has
// to come from reading the whole recorded table rather than only the trace.
$poisoned = $wideOutcomes;
$poisoned[$versions[35]] = [
    'version' => $versions[35],
    'outcome' => AdapterBoundary::OUTCOME_DIVERGES,
    'signature' => 'recapture differed at wp_options/wprism-fixture.json',
];
$clean = AdapterBoundary::search($wide, $wideOutcomes, ['anchor' => $versions[32]]);
wprism_check(
    !in_array($versions[35], array_column((array) $clean['probes'], 'version'), true),
    'the poisoned release is one the unpoisoned walk never probes'
);
$blocked = AdapterBoundary::search($wide, $poisoned, ['anchor' => $versions[32]]);
wprism_check_same('blocked', $blocked['status'], 'a non-green release inside the settled window blocks the search');
wprism_check_same('non_monotone_outcomes', $blocked['blocked']['reason'], 'and does so by that name');
wprism_check_same([$versions[35]], $blocked['blocked']['versions'], 'naming the release that disproves contiguity');
wprism_check_same(null, $blocked['proposed_lock_rows'], 'a blocked search proposes no lock rows at all');
wprism_check_same(null, $blocked['boundary'], 'and reports no boundary — a narrower guess would still be a guess');

// ------------------- 6. an unresolvable artifact is REPORTED, never absent

$unresolvable = $wideOutcomes;
$unresolvable[$versions[20]] = [
    'version' => $versions[20],
    'outcome' => AdapterBoundary::OUTCOME_UNRESOLVED,
    'signature' => 'artifact-cache-fetch.sh: sha256 mismatch after bounded download',
];
$reported = AdapterBoundary::search($wide, $unresolvable, ['anchor' => $versions[32]]);
wprism_check_same('blocked', $reported['status'], 'an unresolvable artifact blocks the search');
wprism_check_same('artifact_unresolved', $reported['blocked']['reason'], 'by its own reason code, never boot-fatal');
wprism_check_same([$versions[20]], $reported['blocked']['versions'], 'and the version is named for the operator to fix');
wprism_check_same(null, $reported['boundary'], 'no boundary is reported from a record with an unresolved probe');
// The narrowing bug this vocabulary prevents: had the unresolved probe been
// folded into boot-fatal, the search would have completed and moved the floor.
$narrowed = $unresolvable;
$narrowed[$versions[20]]['outcome'] = AdapterBoundary::OUTCOME_BOOT_FATAL;
$wouldHaveNarrowed = AdapterBoundary::search($wide, $narrowed, ['anchor' => $versions[32]]);
wprism_check_same(
    $versions[21],
    $wouldHaveNarrowed['boundary']['floor'],
    'treating an unreachable download as a failing release WOULD move the floor by one — which is why it does not'
);

// ------------------------------------------- 7. the anchor must be green

$badAnchor = AdapterBoundary::search($wide, $wideOutcomes, ['anchor' => $versions[5]]);
wprism_check_same('blocked', $badAnchor['status'], 'a non-green anchor blocks');
wprism_check_same('anchor_not_green', $badAnchor['blocked']['reason'], 'there is no known-good release to search outward from');

// ------------------------------------------- 8. the planner asks one question

$partial = AdapterBoundary::search($wide, [], ['anchor' => $versions[32]]);
wprism_check_same('probe-required', $partial['status'], 'an empty record asks for a probe rather than guessing');
wprism_check_same($versions[32], $partial['next_probe']['version'], 'and the first question is the anchor itself');
wprism_check_same('anchor', $partial['next_probe']['arm'], 'named by the arm that asked');

// Drive the planner the way sandbox/bin/adapter-boundary.sh does: run it, add
// the one outcome it asked for, run it again. It must terminate on the same
// trace the all-at-once search produced.
$accumulated = [];
$steps = 0;
while (true) {
    $step = AdapterBoundary::search($wide, $accumulated, ['anchor' => $versions[32]]);
    if ($step['status'] !== 'probe-required') {
        break;
    }
    $version = $step['next_probe']['version'];
    $accumulated[$version] = [
        'version' => $version,
        'outcome' => $outcomeMap[$version],
        'signature' => 'driver probe of ' . $version,
    ];
    $steps++;
    wprism_check($steps <= 13, "the driver loop stays inside the stated probe bound (step $steps)");
}
wprism_check_same('complete', $step['status'], 'the incremental driver loop terminates complete');
wprism_check_same(11, $steps, 'and spends exactly the 11 probes the all-at-once search spends');
wprism_check_same(
    array_column((array) $document['probes'], 'version'),
    array_column((array) $step['probes'], 'version'),
    'on the identical trace — the planner is stateless, so replay and one-shot agree'
);

// ------------------------------- 9. the recorded inputs are validated, loudly

/**
 * @param list<string> $args
 * @return array{stdout:string,stderr:string,status:int}
 */
function boundary_cli(string $root, array $args): array {
    $command = [PHP_BINARY, $root . '/cli/wprism', 'adapter', 'boundary'];
    foreach ($args as $arg) {
        $command[] = $arg;
    }
    $process = proc_open(
        implode(' ', array_map('escapeshellarg', $command)),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        return ['stdout' => '', 'stderr' => 'proc_open failed', 'status' => 127];
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['stdout' => $stdout, 'stderr' => $stderr, 'status' => proc_close($process)];
}

$tmp = sys_get_temp_dir() . '/wprism-boundary-' . getmypid() . '-' . bin2hex(random_bytes(4));
if (!mkdir($tmp, 0700, true) && !is_dir($tmp)) {
    fwrite(STDERR, "FAIL: could not create scratch directory\n");
    exit(1);
}
register_shutdown_function(static function () use ($tmp): void {
    foreach ((array) glob($tmp . '/*') as $file) {
        @unlink((string) $file);
    }
    @rmdir($tmp);
});

$acfBlock = $bisectionShaped['advanced-custom-fields'];
$acfReleases = [];
foreach ($acfBlock as $version => $entry) {
    $acfReleases[] = ['version' => (string) $version, 'url' => $entry['url'], 'sha256' => $entry['sha256']];
}
$releasePath = $tmp . '/releases.json';
file_put_contents($releasePath, Canon::encode(
    boundary_release_list('advanced-custom-fields', $acfReleases, 'adapter-packages/acf/evidence/artifacts.lock.json')
));
$outcomePath = $tmp . '/outcomes.json';
file_put_contents($outcomePath, Canon::encode([
    'format' => AdapterBoundary::OUTCOMES_FORMAT,
    'slug' => 'advanced-custom-fields',
    'outcomes' => [
        ['version' => '5.12.6', 'outcome' => 'boot-fatal', 'signature' => 'replayed from the committed refusal-fixture role'],
        ['version' => '6.0.0', 'outcome' => 'green', 'signature' => 'replayed from the committed certified-boundary role'],
        ['version' => '6.8.7', 'outcome' => 'green', 'signature' => 'replayed from the committed certified-boundary role'],
    ],
]));

$run = boundary_cli($repoRoot, [
    '--releases=' . $releasePath,
    '--outcomes=' . $outcomePath,
    '--anchor=6.0.0',
    '--manifest=acf',
    '--format=json',
]);
wprism_check_same(AdapterBoundary::EXIT_OK, $run['status'], 'the real subprocess exits 0 on a completed search');
$cliDocument = json_decode($run['stdout'], true);
wprism_check(is_array($cliDocument), 'and prints one parseable document on stdout');
wprism_check_same(AdapterBoundary::FORMAT, $cliDocument['format'], 'in the declared wire format');
wprism_check_json_equal(
    Canon::encode(['plugins' => ['advanced-custom-fields' => $acfBlock], 'themes' => new stdClass()]),
    json_encode($cliDocument['proposed_lock_rows']),
    'the subprocess reproduces the committed ACF block through the real command path'
);
wprism_check_json_equal(
    ['min' => '6.0.0', 'max' => '7.0.0'],
    (array) $cliDocument['declared']['range'],
    'and reports the acf manifest range it read, read-only, for the reviewer to compare against'
);
wprism_check_same(false, $cliDocument['declared']['widens_declared_claim'], 'the evidence sits inside the declared range');
wprism_check_same(
    '>6.8.7..7.0.0',
    $cliDocument['declared']['unevidenced_declared_span']['above_ceiling'],
    'and the declared span this search did NOT evidence is named rather than left implied'
);
wprism_check_same(
    'not-performed',
    $cliDocument['review_required']['manifest_edit'],
    'the document says on its face that no manifest was edited'
);

// The proposal is accepted by the shipped fragment parser, not by a copy.
// Decoded a second time WITHOUT assoc, and re-encoded from that: the schema
// requires `.themes | type == "object"`, and an assoc decode turns the
// command's own `{}` into a PHP `[]` that re-encodes as `[]`. The bytes on
// stdout are what is being validated, so they are what gets written here.
$rowsPath = $tmp . '/rows.json';
$cliObject = json_decode($run['stdout']);
file_put_contents($rowsPath, (string) json_encode($cliObject->proposed_lock_rows));
$validatedRows = \WPrism\Tooling\ArtifactLibrary::loadFragment($rowsPath);
wprism_check_same(['advanced-custom-fields'], array_keys($validatedRows['plugins']),
    'the shipped artifact-fragment parser accepts the proposed rows unmodified');

// Exit-code contract, end to end.
$needsProbe = boundary_cli($repoRoot, ['--releases=' . $releasePath, '--anchor=6.0.0', '--format=json']);
wprism_check_same(
    AdapterBoundary::EXIT_PROBE_REQUIRED,
    $needsProbe['status'],
    'exit 3 means "probe this next" — a shell loop that never parsed the JSON still cannot mistake it for done'
);
$usage = boundary_cli($repoRoot, ['--anchor=6.0.0']);
wprism_check_same(AdapterBoundary::EXIT_USAGE, $usage['status'], 'a missing --releases is exit 2');

// A mis-ordered list does not fail on its own; it silently bisects to the
// wrong boundary. So it is refused by name.
$misordered = $tmp . '/misordered.json';
file_put_contents($misordered, Canon::encode(boundary_release_list(
    'advanced-custom-fields',
    [$acfReleases[0], $acfReleases[2], $acfReleases[1]]
)));
$refused = boundary_cli($repoRoot, ['--releases=' . $misordered, '--anchor=6.0.0', '--format=json']);
wprism_check_same(AdapterBoundary::EXIT_BLOCKED, $refused['status'], 'a mis-ordered release list is refused, not sorted');
wprism_check(
    str_contains($refused['stdout'], 'version_compare'),
    'and the refusal names version_compare as the authority that disagreed'
);

// A candidate with no recorded digest would force a choice between fabricating
// a lock row and silently dropping one. It is refused instead.
$undigested = $tmp . '/undigested.json';
$stripped = $acfReleases;
unset($stripped[1]['sha256']);
file_put_contents($undigested, Canon::encode(boundary_release_list('advanced-custom-fields', $stripped)));
$refusedDigest = boundary_cli($repoRoot, ['--releases=' . $undigested, '--anchor=6.0.0', '--format=json']);
wprism_check_same(AdapterBoundary::EXIT_BLOCKED, $refusedDigest['status'], 'a candidate with no sha256 is refused');
wprism_check(
    str_contains($refusedDigest['stdout'], '64-hex'),
    'naming the digest the recorded list has to carry for every candidate'
);

// An outcome with no signature is a verdict with no evidence behind it.
$unsigned = $tmp . '/unsigned.json';
file_put_contents($unsigned, Canon::encode([
    'format' => AdapterBoundary::OUTCOMES_FORMAT,
    'slug' => 'advanced-custom-fields',
    'outcomes' => [['version' => '6.0.0', 'outcome' => 'green']],
]));
$refusedSignature = boundary_cli($repoRoot, [
    '--releases=' . $releasePath,
    '--outcomes=' . $unsigned,
    '--anchor=6.0.0',
    '--format=json',
]);
wprism_check_same(AdapterBoundary::EXIT_BLOCKED, $refusedSignature['status'], 'an outcome with no signature is refused');

// Two plugins' evidence can never be assembled into one boundary.
$crossed = $tmp . '/crossed.json';
file_put_contents($crossed, Canon::encode([
    'format' => AdapterBoundary::OUTCOMES_FORMAT,
    'slug' => 'contact-form-7',
    'outcomes' => [['version' => '6.0.0', 'outcome' => 'green', 'signature' => 'wrong plugin']],
]));
$refusedSlug = boundary_cli($repoRoot, [
    '--releases=' . $releasePath,
    '--outcomes=' . $crossed,
    '--anchor=6.0.0',
    '--format=json',
]);
wprism_check_same(AdapterBoundary::EXIT_BLOCKED, $refusedSlug['status'], 'a release list and an outcome table for different plugins are refused');

// An outcome for a release the list does not carry means the two documents
// describe different candidate sets — dropping the row would discard real
// evidence and hide it from the contiguity check.
$stray = $tmp . '/stray.json';
file_put_contents($stray, Canon::encode([
    'format' => AdapterBoundary::OUTCOMES_FORMAT,
    'slug' => 'advanced-custom-fields',
    'outcomes' => [
        ['version' => '6.0.0', 'outcome' => 'green', 'signature' => 'anchor'],
        ['version' => '6.4.0', 'outcome' => 'boot-fatal', 'signature' => 'a release this list does not carry'],
    ],
]));
$refusedStray = boundary_cli($repoRoot, [
    '--releases=' . $releasePath,
    '--outcomes=' . $stray,
    '--anchor=6.0.0',
    '--format=json',
]);
wprism_check_same(
    AdapterBoundary::EXIT_BLOCKED,
    $refusedStray['status'],
    'an outcome naming a release the list does not carry is refused, never ignored'
);
wprism_check(
    str_contains($refusedStray['stdout'], 'different candidate sets'),
    'and the refusal says which mismatch it found'
);

// ------------------- 9b. the committed recorded inputs cannot drift from the lock

// The package-owned release list `sandbox/bin/adapter-boundary.sh` actually
// runs against is committed, so it is checked here rather than trusted: it
// must still resolve to the same block the lock records, through the real command.
$committedReleases = $repoRoot . '/adapter-packages/acf/fixtures/boundary/releases.json';
$committedRun = boundary_cli($repoRoot, [
    '--releases=' . $committedReleases,
    '--outcomes=' . $outcomePath,
    '--anchor=6.0.0',
    '--format=json',
]);
wprism_check_same(AdapterBoundary::EXIT_OK, $committedRun['status'], 'the committed ACF release list drives a complete search');
$committedObject = json_decode($committedRun['stdout']);
wprism_check_json_equal(
    Canon::encode(['plugins' => ['advanced-custom-fields' => $acfBlock], 'themes' => new stdClass()]),
    (string) json_encode($committedObject->proposed_lock_rows),
    'and reproduces the committed lock block, so the recorded list cannot drift from the pins it was taken from'
);

// The probe's site policy is a reviewed input, and the claim it stands behind
// is "the same round-trip the certify matrix already certifies". That is only
// true while the two files agree byte for byte.
$committedPolicy = (string) file_get_contents($repoRoot . '/adapter-packages/acf/fixtures/boundary/site.wprism.json');
$certifySource = (string) file_get_contents($repoRoot . '/adapter-packages/acf/tests/certify/version-matrix.sh');
wprism_check(
    str_contains($certifySource, $committedPolicy),
    'the boundary probe runs ACF under byte-identical policy to its package-owned certify workflow, so a bisection '
    . 'and a certification are claiming the same thing'
);

// ------------------------------------------------ 10. nothing wrote a manifest

wprism_check_same(
    $manifestsBefore,
    boundary_manifest_digests($repoRoot),
    'no adapter-package or platform-library byte moved — the range and its disposition stay a reviewed human edit'
);

wprism_check_summary('adapter boundary search');
