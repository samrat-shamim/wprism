<?php
/**
 * Offline characterization for `duo adapter proposals` — the scheduled
 * re-bisection job, the range-bump PROPOSAL it emits, and the derived adapter
 * freshness the census ranks.
 *
 * ## What is actually driven here
 *
 * `AdapterProposals::project()` and the real `duo adapter proposals` /
 * `duo census` executables. The bisection underneath is `AdapterBoundary`'s
 * own `search()` — the same planner `regress_adapter_boundary_search.php`
 * characterizes — so nothing here re-implements a search; this suite is about
 * what the JOB does with N of them.
 *
 * ## The property the whole design rests on, and how it is proven
 *
 * A supported range lives in TWO files, and
 * `ManifestDispositions.php:632-637` refuses the pair the moment they
 * disagree: it compares `Canon::encode()` of `manifests/<name>.json`'s
 * `version_range` against `Canon::encode()` of the disposition's
 * `supported_versions.range`, so the agreement is on canonical BYTES.
 *
 * So the proposal is asserted three ways, strongest last:
 *
 *   1. the two emitted edits name the two files and the two pointers;
 *   2. their canonical encodings are equal;
 *   3. the REAL validator accepts the pair — `ManifestDispositions::assert_entry()`,
 *      the same static every projection-time reader calls
 *      (`ManifestDispositions.php:171-173`), run over the manifest and entry
 *      with both edits applied.
 *
 * And the failing-before proof for (3) is the mutation that motivates the
 * whole shape: apply ONLY the manifest edit and the same validator throws
 * "versions disagree with its manifest contract". A proposal that emitted one
 * edit would hand a reviewer a library that does not load.
 *
 * ## What is refused rather than proposed
 *
 * Five refusals, each with a case here and each asserted to emit NO proposal
 * at all: `no_green_probe` (the record never reached a green release, so there
 * is no anchor and no endpoint), `bisection_incomplete` (probe-required, and
 * blocked-on-`artifact_unresolved`), `declared_pair_disagrees` (the library
 * already fails the validator, so there is no base to edit), and
 * `proposal_contains_failing_release` — the one that is about the proposal
 * rather than the search. The bisector refuses a contradiction inside the
 * window it SETTLED; a proposal keeps the DECLARED floor when the evidenced
 * floor is higher, and a recorded failure in that span is outside the settled
 * window. Case 4e builds exactly that shape (a failing 1.1.0 below an
 * evidenced floor of 1.2.0, inside a declared min of 1.0.0) and asserts the
 * range is refused rather than proposed over it.
 *
 * ## Freshness is derived or it is nothing
 *
 * `last_verified` is the newest release that probed green — the shape
 * `manifests/capabilities/platform.json` already uses per axis, a VERSION and
 * not a date. This suite recomputes it independently from the outcome table
 * and compares, exercises all four classes (`unrecorded`, `unverified`,
 * `behind`, `current`), and asserts a ledger document carrying its own
 * `last_verified` is REFUSED: an input allowed to state its own freshness
 * would let the adapter nobody has probed declare itself current.
 *
 * ## And the two negative properties
 *
 * No manifest byte moves — every file under `manifests/` is hashed before and
 * after, across the in-process projections AND the real subprocess runs,
 * including the run that reads the shipped library. The proposals source is
 * also grepped for a write primitive, because "it never writes" is cheaper to
 * keep true than to re-derive from a digest comparison every time.
 *
 * The census keeps its redaction property: `fleet_health` is a WHITELIST
 * projection of the health document, so a hostile freshness row carrying a
 * site URL contributes no key. Asserted with a sentinel that must not appear
 * anywhere in the census output.
 */
declare(strict_types=1);

// From offline/adapter/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

$repoRoot = dirname(__DIR__, 4);
require_once $repoRoot . '/agent/src/Kernel/Canon.php';
require_once $repoRoot . '/agent/src/Policy/ManifestDispositions.php';
require_once $repoRoot . '/cli/src/Command/CommandOutput.php';
require_once $repoRoot . '/cli/src/Adapter/AdapterBoundary.php';
require_once $repoRoot . '/cli/src/Adapter/AdapterProposals.php';

use Duo\Canon;
use Duo\ManifestDispositions;
use Duo\Orchestrator\AdapterBoundary;
use Duo\Orchestrator\AdapterProposals;

/** @return array<string,string> path => sha256 for every file under manifests/ */
function bp_manifest_digests(string $root): array {
    $digests = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/manifests', FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $file) {
        if ($file->isFile()) {
            $digests[$file->getPathname()] = (string) hash_file('sha256', $file->getPathname());
        }
    }
    ksort($digests);
    return $digests;
}

$manifestsBefore = bp_manifest_digests($repoRoot);

// Scratch under sandbox/tmp/ — gitignored, per AGENTS.md rule 3 — with a
// per-process suffix, because the corpus runs concurrently and a fixed path
// would let two runs of this suite read each other's ledger.
$scratch = $repoRoot . '/sandbox/tmp/proposals_' . getmypid() . '_' . bin2hex(random_bytes(4));

function bp_rmtree(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                bp_rmtree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
}
register_shutdown_function(static function () use ($scratch): void {
    bp_rmtree($scratch);
});

function bp_write(string $path, array $document): void {
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
        throw new RuntimeException("could not create fixture directory: $dir");
    }
    file_put_contents($path, Canon::encode($document));
}

/**
 * Run the real `duo` executable.
 *
 * @param list<string> $args
 * @return array{exit:int,stdout:string,stderr:string}
 */
function bp_duo(string $repoRoot, array $args): array {
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, $repoRoot . '/cli/duo'], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $repoRoot,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) {
        throw new RuntimeException('could not start the duo executable');
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
}

/** A deterministic 64-hex string that is visibly a fixture, never a real digest. */
function bp_digest(string $seed): string {
    return hash('sha256', 'duo-proposals-fixture/' . $seed);
}

/**
 * A recorded release list in the bisector's own shape.
 *
 * @param list<string> $versions
 * @return array<string,mixed>
 */
function bp_release_list(string $slug, array $versions): array {
    $rows = [];
    foreach ($versions as $version) {
        $rows[] = [
            'version' => $version,
            'url' => 'https://fixtures.invalid/' . $slug . '.' . $version . '.zip',
            'sha256' => bp_digest($slug . '/' . $version),
        ];
    }
    return [
        'format' => AdapterBoundary::RELEASES_FORMAT,
        'slug' => $slug,
        'source' => 'suite-derived fixture; every name here is invented',
        'recorded_at' => '2026-08-24',
        'releases' => $rows,
    ];
}

/**
 * @param array<string,string> $outcomes version => outcome
 * @return array<string,mixed>
 */
function bp_outcome_table(string $slug, array $outcomes): array {
    $rows = [];
    foreach ($outcomes as $version => $outcome) {
        $rows[] = [
            'version' => (string) $version,
            'outcome' => $outcome,
            'signature' => 'recorded fixture outcome ' . $outcome . ' for ' . $version,
        ];
    }
    return ['format' => AdapterBoundary::OUTCOMES_FORMAT, 'slug' => $slug, 'outcomes' => $rows];
}

/**
 * Publish one ledger entry as the two files the job reads off disk.
 *
 * @param list<string> $versions
 * @param array<string,string> $outcomes
 */
function bp_publish_ledger(string $dir, string $slug, array $versions, array $outcomes): void {
    bp_write($dir . '/' . $slug . AdapterProposals::RELEASES_SUFFIX, bp_release_list($slug, $versions));
    if ($outcomes !== []) {
        bp_write($dir . '/' . $slug . AdapterProposals::OUTCOMES_SUFFIX, bp_outcome_table($slug, $outcomes));
    }
}

/**
 * The in-process ledger shape `project()` takes, built through the SAME readers
 * the command uses so a fixture cannot be shaped in a way the product path
 * would refuse.
 *
 * @param list<string> $versions
 * @param array<string,string> $outcomes
 * @return array{releases:array<string,mixed>,outcomes:array<string,mixed>,sources:array<string,string>}
 */
function bp_ledger_entry(string $scratch, string $slug, array $versions, array $outcomes): array {
    $dir = $scratch . '/ledger_' . hash('crc32b', $slug . implode(',', $versions) . implode(',', $outcomes));
    bp_publish_ledger($dir, $slug, $versions, $outcomes);
    $ledger = AdapterProposals::readLedger($dir);
    return $ledger[$slug];
}

/**
 * One adapter row of the library shape `project()` takes. Every name is
 * INVENTED, the discipline `sandbox/tests/fixtures/assess/make-fixture.php:20-24`
 * states: a fixture using a real ecosystem's names tempts the next author to
 * match it in production code.
 *
 * @return array{name:string,plugin:string,slug:string,range:array{min:string,max:string},supported_versions:?array<string,mixed>}
 */
function bp_adapter(string $name, array $range, ?array $supported = null): array {
    $plugin = $name . '/' . $name . '.php';
    return [
        'name' => $name,
        'plugin' => $plugin,
        'slug' => $name,
        'range' => $range,
        'supported_versions' => $supported ?? ['plugin' => $plugin, 'range' => $range],
    ];
}

// ================================================== 1. the shipped library

// The job over this checkout's own manifests and its own committed ledger,
// through the real executable. The counts are DERIVED here rather than
// hard-coded: the subject is the invariants, and a suite that pinned "14
// adapters" would fail on the next adapter rather than on a defect.
$pinned = 0;
foreach (glob($repoRoot . '/manifests/*.json') ?: [] as $path) {
    // No `dispositions.json` skip: WP-4.4 moved the reviewed claim source into
    // manifests/dispositions/, which this glob does not match.
    $manifest = Canon::decode((string) file_get_contents($path));
    if (is_array($manifest) && is_string($manifest['plugin'] ?? null)
        && is_array($manifest['version_range'] ?? null)) {
        $pinned++;
    }
}

$shipped = bp_duo($repoRoot, ['adapter', 'proposals', '--format=json']);
duo_check_same(AdapterProposals::EXIT_OK, $shipped['exit'], 'the job runs over the shipped library and its committed ledger');
$shippedDocument = json_decode($shipped['stdout'], true);
duo_check(is_array($shippedDocument), 'and emits a decodable document');
duo_check_same(AdapterProposals::FORMAT, $shippedDocument['format'], 'declaring its own format');
duo_check_same($pinned, $shippedDocument['ledger']['adapters'], "every one of the $pinned manifests declaring a plugin AND a version_range is a subject");
duo_check_same(
    $pinned,
    count($shippedDocument['freshness']),
    'and every one of them gets a freshness row, including the ones with no recorded ledger at all — '
    . 'an unmeasured adapter is the loudest staleness there is and is invisible until it is counted'
);

// The invariants, over whatever the shipped ledger happens to hold today.
foreach ($shippedDocument['proposals'] as $row) {
    duo_check_json_equal(
        Canon::encode($row['edits'][0]['proposed']),
        Canon::encode($row['edits'][1]['proposed']['range']),
        'shipped proposal for ' . $row['adapter'] . ': both edits agree on the canonical bytes the validator compares'
    );
}
foreach ($shippedDocument['refused'] as $row) {
    duo_check(
        in_array($row['reason'], [
            'no_green_probe',
            'bisection_incomplete',
            'declared_pair_disagrees',
            'proposal_contains_failing_release',
        ], true),
        'shipped refusal for ' . $row['adapter'] . ' names a reason from the closed set: ' . $row['reason']
    );
}

// The one committed ledger today is a release list with no outcome table
// beside it, and that is exactly the `no_green_probe` shape: a candidate set
// nobody has probed proposes nothing.
$acf = null;
foreach ($shippedDocument['refused'] as $row) {
    if ($row['adapter'] === 'acf') {
        $acf = $row;
    }
}
duo_check(is_array($acf), 'the committed acf release list is a subject of the job');
duo_check_same('no_green_probe', $acf['reason'], 'and with no outcomes recorded beside it, it proposes nothing');

// ================================================ 2. the proposal's shape

// Declared 1.0.0 <= v < 1.4.0; the record proves green through 1.5.0 and
// 1.6.0 fatal, so the evidenced ceiling is 1.5.0 and the exclusive bound that
// admits it and nothing more is the next RECORDED release, 1.6.0.
$versions = ['0.9.0', '1.0.0', '1.1.0', '1.2.0', '1.3.0', '1.4.0', '1.5.0', '1.6.0'];
$outcomes = [
    '0.9.0' => AdapterBoundary::OUTCOME_BOOT_FATAL,
    '1.0.0' => AdapterBoundary::OUTCOME_GREEN,
    '1.1.0' => AdapterBoundary::OUTCOME_GREEN,
    '1.2.0' => AdapterBoundary::OUTCOME_GREEN,
    '1.3.0' => AdapterBoundary::OUTCOME_GREEN,
    '1.4.0' => AdapterBoundary::OUTCOME_GREEN,
    '1.5.0' => AdapterBoundary::OUTCOME_GREEN,
    '1.6.0' => AdapterBoundary::OUTCOME_BOOT_FATAL,
];
$declared = ['min' => '1.0.0', 'max' => '1.4.0'];
$forms = bp_adapter('fixture-forms', $declared);
$document = AdapterProposals::project(
    [$forms],
    ['fixture-forms' => bp_ledger_entry($scratch, 'fixture-forms', $versions, $outcomes)]
);

duo_check_same(1, count($document['proposals']), 'evidence beyond the declared max produces exactly one proposal');
$proposal = $document['proposals'][0];
duo_check_same('proposed', $proposal['status'], 'and it is a proposal, not a no-op row');
duo_check_same('1.5.0', $proposal['evidence']['ceiling'], 'the evidenced ceiling is the newest green release');
duo_check_same('1.0.0', $proposal['evidence']['floor'], 'and the floor is the oldest green one');
duo_check_same('1.5.0', $proposal['evidence']['anchor'], 'the anchor is DERIVED from the record — the newest green probe, not a flag a human typed');
duo_check_same(
    ['min' => '1.0.0', 'max' => '1.6.0'],
    $proposal['proposed_range'],
    'max moves to the next RECORDED release after the ceiling: exclusive, so it admits 1.5.0 and nothing past it, '
    . 'and it is a version somebody recorded rather than one the job invented'
);

// The two edits, in the two files, at the two pointers.
duo_check_same(2, count($proposal['edits']), 'a range bump is TWO edits, because the range lives in two files');
duo_check_same('manifests/fixture-forms.json', $proposal['edits'][0]['file'], 'the manifest edit names the manifest');
duo_check_same('/version_range', $proposal['edits'][0]['pointer'], 'at /version_range');
duo_check_same(
    'manifests/dispositions/fixture-forms.json',
    $proposal['edits'][1]['file'],
    'the second edit names the reviewed restatement — that adapter\'s OWN document since WP-4.4'
);
duo_check_same(
    '/supported_versions',
    $proposal['edits'][1]['pointer'],
    'at a pointer into the entry itself, no longer through a whole-library `/manifests/<name>` prefix'
);
duo_check_json_equal(
    Canon::encode($proposal['edits'][0]['proposed']),
    Canon::encode($proposal['edits'][1]['proposed']['range']),
    'and the two agree on CANONICAL BYTES — the comparison ManifestDispositions.php:632-637 actually makes'
);
duo_check_same(
    'fixture-forms/fixture-forms.php',
    $proposal['edits'][1]['proposed']['plugin'],
    'the disposition edit restates the MANIFEST\'s plugin, the other half of that same check'
);
duo_check_same(
    hash('sha256', Canon::encode($proposal['proposed_range'])),
    $proposal['canon']['range_sha256'],
    'the document publishes the canonical digest of the range both edits encode'
);

// ============================ 3. the REAL validator accepts the edited pair

/**
 * A manifest and a reviewed entry in the shape `validate_entry()` demands
 * (agent/src/Policy/ManifestDispositions.php:533-637), so the pair below is
 * judged by the product's own validator rather than by a re-reading of it.
 */
function bp_manifest(array $range): array {
    return [
        'name' => 'fixture-forms',
        'plugin' => 'fixture-forms/fixture-forms.php',
        'version_range' => $range,
        'options' => ['fixture_forms_settings' => ['class' => 'authored']],
    ];
}

function bp_entry(array $range): array {
    return [
        'capabilities' => [
            'deletion_semantics' => ['supported' => [], 'unsupported' => ['fixture entity deletes']],
            'entity_sections' => [],
            'field_sections' => ['options'],
            'lifecycle_phases' => ['activate', 'verify'],
            'operations' => ['capture', 'plan', 'apply'],
        ],
        'default_authored_keyspaces' => [],
        'evidence' => [
            'bundle_schema' => 'duo-subject-certification-bundle/v1',
            'tests' => ['conformance-fixture'],
        ],
        'reason' => 'Fixture disposition for the boundary-proposals suite; no product claim.',
        'status' => 'certified',
        'supported_versions' => ['plugin' => 'fixture-forms/fixture-forms.php', 'range' => $range],
        'unsupported' => [[
            'operation' => 'all',
            'reason' => 'Duo v1 refuses multisite, so this fixture claims none of it.',
            'surface' => 'multisite',
        ]],
    ];
}

// The base library the proposal is an edit ONTO: it must load before the edit,
// or "the edit still loads" would prove nothing.
ManifestDispositions::assert_entry('fixture-forms', bp_entry($declared), bp_manifest($declared));
duo_check(true, 'the unedited fixture pair passes the real validator, so the edit below has a valid base');

// Both edits applied, exactly as the document proposes them.
$editedManifest = bp_manifest($declared);
$editedManifest['version_range'] = $proposal['edits'][0]['proposed'];
$editedEntry = bp_entry($declared);
$editedEntry['supported_versions'] = $proposal['edits'][1]['proposed'];
$accepted = true;
try {
    ManifestDispositions::assert_entry('fixture-forms', $editedEntry, $editedManifest);
} catch (Throwable $t) {
    $accepted = false;
    duo_check_detail('validator refused the proposed pair: ' . $t->getMessage());
}
duo_check($accepted, 'the REAL ManifestDispositions validator accepts the pair with BOTH proposed edits applied');

// The failing-before proof for that shape: one edit alone bricks the library.
duo_check_throws(
    static function () use ($editedManifest): void {
        ManifestDispositions::assert_entry('fixture-forms', bp_entry(['min' => '1.0.0', 'max' => '1.4.0']), $editedManifest);
    },
    RuntimeException::class,
    'applying ONLY the manifest edit is refused by the validator — which is why the proposal carries both',
    'versions disagree with its manifest contract'
);
duo_check_throws(
    static function () use ($editedEntry, $declared): void {
        ManifestDispositions::assert_entry('fixture-forms', $editedEntry, bp_manifest($declared));
    },
    RuntimeException::class,
    'and applying ONLY the disposition edit is refused the same way',
    'versions disagree with its manifest contract'
);

// ======================== 4. a ceiling at the end of the recorded list

// Same record with 1.6.0 removed: the ceiling 1.5.0 is now the NEWEST recorded
// release, so there is no next version to make an exclusive bound out of and
// max does not move. Inventing one would claim releases nobody recorded.
$short = array_slice($versions, 0, 7);
$shortOutcomes = $outcomes;
unset($shortOutcomes['1.6.0']);
$edge = AdapterProposals::project(
    [$forms],
    ['fixture-forms' => bp_ledger_entry($scratch, 'fixture-forms', $short, $shortOutcomes)]
);
duo_check_same([], $edge['proposals'], 'with no recorded release above the ceiling, nothing is proposed');
duo_check_same(1, count($edge['unchanged']), 'the adapter reports as unchanged instead');
$limits = implode(' ', $edge['unchanged'][0]['limits']);
duo_check(
    str_contains($limits, 'newest RECORDED release'),
    'and the row says WHY the max did not move, rather than leaving the reader to infer the evidence agreed'
);

// ============================================== 5. every refusal, by name

// (a) no green probe at all.
$noGreen = AdapterProposals::project(
    [$forms],
    ['fixture-forms' => bp_ledger_entry($scratch, 'fixture-forms', $versions, [
        '1.0.0' => AdapterBoundary::OUTCOME_BOOT_FATAL,
        '1.5.0' => AdapterBoundary::OUTCOME_DIVERGES,
    ])]
);
duo_check_same([], $noGreen['proposals'], 'a bisection that never reached green proposes NOTHING');
duo_check_same('no_green_probe', $noGreen['refused'][0]['reason'], 'it is refused, and the refusal is named');

// (b) the record is incomplete — more probes are required.
$partial = AdapterProposals::project(
    [$forms],
    ['fixture-forms' => bp_ledger_entry($scratch, 'fixture-forms', $versions, [
        '1.5.0' => AdapterBoundary::OUTCOME_GREEN,
    ])]
);
duo_check_same([], $partial['proposals'], 'an unfinished search proposes nothing');
duo_check_same('bisection_incomplete', $partial['refused'][0]['reason'], 'and says the search did not run to completion');
duo_check(
    is_array($partial['refused'][0]['next_probe']),
    'carrying the ONE release to probe next — the bisector\'s exit-3 answer as a row, because a job over N '
    . 'adapters cannot express N next-probes as one process status'
);

// (c) an unresolvable artifact BLOCKS. A 404 or a digest mismatch is a fact
// about the download, and folding it into a failing release would let a mirror
// outage move a certified boundary.
$unresolved = $outcomes;
$unresolved['1.2.0'] = AdapterBoundary::OUTCOME_UNRESOLVED;
$blocked = AdapterProposals::project(
    [$forms],
    ['fixture-forms' => bp_ledger_entry($scratch, 'fixture-forms', $versions, $unresolved)]
);
duo_check_same([], $blocked['proposals'], 'an unresolvable artifact proposes nothing');
duo_check(
    str_contains($blocked['refused'][0]['detail'], 'artifact_unresolved'),
    'and the refusal carries the search\'s own blocking reason rather than a summary of it'
);

// (d) the library already fails the validator.
$mismatched = bp_adapter('fixture-forms', $declared, [
    'plugin' => 'fixture-forms/fixture-forms.php',
    'range' => ['min' => '1.0.0', 'max' => '9.9.9'],
]);
$broken = AdapterProposals::project(
    [$mismatched],
    ['fixture-forms' => bp_ledger_entry($scratch, 'fixture-forms', $versions, $outcomes)]
);
duo_check_same([], $broken['proposals'], 'a manifest and disposition that already disagree get no proposal');
duo_check_same(
    'declared_pair_disagrees',
    $broken['refused'][0]['reason'],
    'because there is no loadable base to propose an edit onto'
);

// (e) the range a proposal would name contains a recorded failing release.
// The evidenced floor is 1.2.0 (1.1.0 fatal), but min stays at the declared
// 1.0.0 — a floor is "green at least this far down" and raising it would
// retract a claim the record does not disprove. That leaves 1.1.0 inside the
// proposed range, OUTSIDE the window the bisector settled, and proposing over
// it is the one widening this job must never perform.
$hole = ['1.0.0', '1.1.0', '1.2.0', '1.3.0', '1.4.0', '1.5.0', '1.6.0'];
$holeOutcomes = [
    '1.0.0' => AdapterBoundary::OUTCOME_GREEN,
    '1.1.0' => AdapterBoundary::OUTCOME_BOOT_FATAL,
    '1.2.0' => AdapterBoundary::OUTCOME_GREEN,
    '1.3.0' => AdapterBoundary::OUTCOME_GREEN,
    '1.4.0' => AdapterBoundary::OUTCOME_GREEN,
    '1.5.0' => AdapterBoundary::OUTCOME_GREEN,
    '1.6.0' => AdapterBoundary::OUTCOME_BOOT_FATAL,
];
$holed = AdapterProposals::project(
    [$forms],
    ['fixture-forms' => bp_ledger_entry($scratch, 'fixture-forms', $hole, $holeOutcomes)]
);
duo_check_same([], $holed['proposals'], 'a range that would contain a recorded failing release is never proposed');
duo_check_same(
    'proposal_contains_failing_release',
    $holed['refused'][0]['reason'],
    'it is refused instead, by name'
);
duo_check(
    str_contains($holed['refused'][0]['detail'], '1.1.0'),
    'and the refusal names the release that disproves the range, so a reviewer can go probe it'
);

// ================================================ 6. derived freshness

/** The newest green in a recorded table, computed here so the row is compared against an independent derivation. */
function bp_newest_green(array $versions, array $outcomes): ?string {
    $newest = null;
    foreach ($versions as $version) {
        if (($outcomes[$version] ?? null) === AdapterBoundary::OUTCOME_GREEN) {
            $newest = $version;
        }
    }
    return $newest;
}

$fresh = $document['freshness'][0];
duo_check_same('fixture-forms', $fresh['adapter'], 'freshness is per adapter');
duo_check_same(
    bp_newest_green($versions, $outcomes),
    $fresh['last_verified'],
    'last_verified is the newest release that PROBED GREEN — a version, the shape platform.json already uses '
    . 'per axis, not a date no probe record could know'
);
duo_check_same('1.6.0', $fresh['newest_recorded_release'], 'beside the newest release anybody recorded');
duo_check_same(1, $fresh['releases_behind'], 'so the proof is one recorded release behind');
duo_check_same(1, $fresh['failing_newer'], 'and that release is one the record says fails');
duo_check_same(0, $fresh['unprobed_newer'], 'with nothing newer left unprobed');
duo_check_same('behind', $fresh['freshness_class'], 'which is the `behind` class');
duo_check_same(true, $fresh['stale'], 'and stale');
duo_check_same(true, $fresh['open_proposal'], 'with an open proposal against it');

// `current`: nothing recorded is newer than the newest green.
$current = AdapterProposals::project(
    [bp_adapter('fixture-forms', ['min' => '1.0.0', 'max' => '2.0.0'])],
    ['fixture-forms' => bp_ledger_entry($scratch, 'fixture-forms', ['1.0.0', '1.1.0'], [
        '1.0.0' => AdapterBoundary::OUTCOME_GREEN,
        '1.1.0' => AdapterBoundary::OUTCOME_GREEN,
    ])]
);
duo_check_same('current', $current['freshness'][0]['freshness_class'], 'an adapter proven through its newest recorded release is `current`');
duo_check_same(false, $current['freshness'][0]['stale'], 'and not stale');

// `behind` via an UNPROBED newer release — the ordinary staleness a scheduled
// job exists to find: upstream shipped, and nobody has run the pair yet.
$unprobedDoc = AdapterProposals::project(
    [bp_adapter('fixture-forms', ['min' => '1.0.0', 'max' => '2.0.0'])],
    ['fixture-forms' => bp_ledger_entry($scratch, 'fixture-forms', ['1.0.0', '1.1.0'], [
        '1.0.0' => AdapterBoundary::OUTCOME_GREEN,
    ])]
);
duo_check_same('behind', $unprobedDoc['freshness'][0]['freshness_class'], 'a recorded release nobody probed makes the adapter `behind`');
duo_check_same(1, $unprobedDoc['freshness'][0]['unprobed_newer'], 'and it is counted as unprobed, not as failing');

// `unrecorded` and `unverified`.
$classes = AdapterProposals::project(
    [bp_adapter('fixture-gallery', ['min' => '1.0.0', 'max' => '2.0.0']), $forms],
    ['fixture-forms' => bp_ledger_entry($scratch, 'fixture-forms', ['1.0.0'], [
        '1.0.0' => AdapterBoundary::OUTCOME_BOOT_FATAL,
    ])]
);
$byAdapter = [];
foreach ($classes['freshness'] as $row) {
    $byAdapter[$row['adapter']] = $row;
}
duo_check_same('unrecorded', $byAdapter['fixture-gallery']['freshness_class'], 'an adapter with no recorded release list is `unrecorded`');
duo_check_same(null, $byAdapter['fixture-gallery']['last_verified'], 'with no last_verified at all');
duo_check_same(true, $byAdapter['fixture-gallery']['stale'], 'and it is stale: never bisected is the loudest staleness there is');
duo_check_same('unverified', $byAdapter['fixture-forms']['freshness_class'], 'a candidate set with no green probe is `unverified`');

// Freshness can never be hand-asserted. The readers normalize and drop unknown
// keys, so this is checked against the RAW ledger document.
$hostileLedger = $scratch . '/hand-asserted';
bp_publish_ledger($hostileLedger, 'fixture-forms', ['1.0.0'], ['1.0.0' => AdapterBoundary::OUTCOME_GREEN]);
$handAsserted = Canon::decode((string) file_get_contents($hostileLedger . '/fixture-forms' . AdapterProposals::RELEASES_SUFFIX));
$handAsserted['last_verified'] = '9.9.9';
bp_write($hostileLedger . '/fixture-forms' . AdapterProposals::RELEASES_SUFFIX, $handAsserted);
$refusedHand = bp_duo($repoRoot, ['adapter', 'proposals', '--ledger=' . $hostileLedger, '--format=json']);
duo_check_same(
    AdapterProposals::EXIT_REFUSED,
    $refusedHand['exit'],
    'a ledger document asserting its own last_verified is REFUSED — an input allowed to state its freshness '
    . 'would let the adapter nobody has probed declare itself current'
);
duo_check(
    str_contains($refusedHand['stdout'], 'DERIVED from probe outcomes'),
    'and the refusal says the fact is derived, not recorded'
);

// ================================ 7. the census surfaces this as fleet health

const BP_SENTINEL = 'SENTINEL-MUST-NOT-TRAVEL.invalid';

$library = $scratch . '/library';
$range = ['min' => '1.0.0', 'max' => '1.4.0'];
bp_write($library . '/fixture-forms.json', [
    'name' => 'fixture-forms',
    'plugin' => 'fixture-forms/fixture-forms.php',
    'version_range' => $range,
    'options' => ['fixture_forms_settings' => ['class' => 'authored']],
]);
bp_write($library . '/fixture-gallery.json', [
    'name' => 'fixture-gallery',
    'plugin' => 'fixture-gallery/fixture-gallery.php',
    'version_range' => ['min' => '1.0.0', 'max' => '2.0.0'],
    'options' => ['fixture_gallery_settings' => ['class' => 'authored']],
]);
// One document per subject since WP-4.4 (spec/repo-format.md § v3.4).
bp_write($library . '/dispositions/fixture-forms.json', bp_entry($range));
bp_write($library . '/dispositions/fixture-gallery.json', (static function (): array {
    $entry = bp_entry(['min' => '1.0.0', 'max' => '2.0.0']);
    $entry['supported_versions']['plugin'] = 'fixture-gallery/fixture-gallery.php';
    return $entry;
})());
// platform.json is COPIED, not written: ManifestDispositions::platform_boundary()
// refuses a boundary whose agent/spec version disagrees with the loaded agent,
// and a hand-written one would need editing on every version bump.
if (!is_dir($library . '/capabilities') && !mkdir($library . '/capabilities', 0777, true)) {
    throw new RuntimeException('could not create the fixture platform directory');
}
copy($repoRoot . '/manifests/capabilities/platform.json', $library . '/capabilities/platform.json');

// A ledger where the pinned adapter is behind and the unpinned one is current,
// so the exposure rank has something to order.
$censusLedger = $scratch . '/census-ledger';
bp_publish_ledger($censusLedger, 'fixture-forms', $versions, $outcomes);
bp_publish_ledger($censusLedger, 'fixture-gallery', ['1.0.0'], ['1.0.0' => AdapterBoundary::OUTCOME_GREEN]);

$health = bp_duo($repoRoot, [
    'adapter', 'proposals',
    '--ledger=' . $censusLedger,
    '--manifests=' . $library,
    '--format=json',
]);
duo_check_same(AdapterProposals::EXIT_OK, $health['exit'], 'the job runs over a fixture library and ledger');
$healthPath = $scratch . '/health.json';
file_put_contents($healthPath, $health['stdout']);
$healthDocument = json_decode($health['stdout'], true);
duo_check_same(1, $healthDocument['ledger']['open_proposals'], 'one adapter has an open range proposal');
duo_check_same(1, $healthDocument['ledger']['stale_adapters'], 'and exactly one of the two is stale');

// Two sites, both installing both plugins; only one pins the stale adapter.
function bp_inventory(array $plugins, array $pins): array {
    $pluginRows = [];
    foreach ($plugins as $slug) {
        $pluginRows[] = ['basename' => "$slug/$slug.php", 'name' => 'Fixture', 'version' => '1.4.0', 'active' => true];
    }
    $pinRows = [];
    foreach ($pins as $pin) {
        $pinRows[] = ['name' => $pin, 'source' => 'shipped', 'adapter_digest' => str_repeat('a', 64), 'status' => 'certified'];
    }
    return [
        'format' => 'duo-assess-inventory/v1',
        'spec_version' => 2,
        'agent_version' => '0.5.0',
        'target' => [
            'site_mode' => 'single-site',
            'home' => 'https://' . BP_SENTINEL,
            'siteurl' => 'https://' . BP_SENTINEL,
        ],
        'plugins' => $pluginRows,
        'policy' => ['manifests' => $pinRows, 'surface_groups' => []],
        'coverage' => [
            'format' => 'duo-coverage-report/v1',
            'options' => [
                'total' => 10,
                'captured' => 8,
                'declared_excluded' => 0,
                'pending' => 0,
                'invisible_total' => 2,
                'invisible_transient' => 0,
                'invisible_other' => 2,
                'invisible_groups' => [['prefix' => 'fixture_forms', 'count' => 2, 'probable_owner' => 'fixture-forms']],
            ],
            'tables' => ['live_total' => 12, 'core_total' => 12, 'undeclared_total' => 0, 'undeclared' => []],
        ],
    ];
}

bp_write($scratch . '/sites/alpha.json', bp_inventory(['fixture-forms', 'fixture-gallery'], ['fixture-forms']));
bp_write($scratch . '/sites/beta.json', bp_inventory(['fixture-forms', 'fixture-gallery'], []));

$census = bp_duo($repoRoot, [
    'census',
    '--dir=' . $scratch . '/sites',
    '--manifests=' . $library,
    '--health=' . $healthPath,
    '--format=json',
]);
duo_check_same(0, $census['exit'], 'the census folds the two submissions with the health document beside them');
$censusDocument = json_decode($census['stdout'], true);
$fleetHealth = $censusDocument['fleet_health'];
duo_check_same('duo-adapter-boundary-proposals/v1', $fleetHealth['source'], 'the census names the document its health rows came from');
duo_check_same(2, count($fleetHealth['rows']), 'one row per adapter in the health document');
duo_check_same(1, $fleetHealth['stale'], 'one of them is stale');
duo_check_same(1, $fleetHealth['open_proposals'], 'and one has an open proposal');

// The redaction property, through the health document this time: the census
// reads names and counts out of it and nothing else. Asserted BEFORE the rank
// assertions below, so a projection that stopped whitelisting fails on the
// redaction it broke rather than on whatever shape the pass-through happened
// to put in the next row it reads.
$hostileHealth = json_decode($health['stdout'], true);
$hostileHealth['freshness'][0]['home'] = 'https://' . BP_SENTINEL;
$hostileHealth['freshness'][0]['site_label'] = BP_SENTINEL;
$hostilePath = $scratch . '/hostile-health.json';
file_put_contents($hostilePath, Canon::encode($hostileHealth));
$hostileCensus = bp_duo($repoRoot, [
    'census',
    '--dir=' . $scratch . '/sites',
    '--manifests=' . $library,
    '--health=' . $hostilePath,
    '--format=json',
]);
duo_check_same(0, $hostileCensus['exit'], 'a health document carrying extra fields still folds');
duo_check(
    !str_contains($hostileCensus['stdout'], BP_SENTINEL),
    'and NOTHING outside the whitelist reaches the census document — fleet_health reads names and counts, the '
    . 'same discipline Coverage.php:24-36 holds all the way through an inventory'
);

$top = $fleetHealth['rows'][0];
duo_check_same('fixture-forms', $top['adapter'], 'the stale adapter one site PINS ranks first');
duo_check_same(2, $top['sites_installed'], 'joined to the census\'s own install count');
duo_check_same(1, $top['sites_pinning'], 'and its pin count');
duo_check_same(
    $top['sites_pinning'] * $top['releases_behind'],
    $top['exposure_score'],
    'ranked by sites pinning x releases behind: an adapter nobody pins is a backlog item, one eleven sites pin '
    . 'whose proof is four releases old is eleven blocked deploys one upstream release from now'
);
// Compared as canonical JSON: the document round-tripped through
// `Canon::encode()`, which ksorts every object (Canon.php:38-58), so a
// key-order comparison here would be asserting the encoder's behaviour.
duo_check_json_equal(
    ['min' => '1.0.0', 'max' => '1.6.0'],
    $top['open_proposal'],
    'and the open range proposal rides the row, where the operator already looks'
);

// No health document is a disclosed absence, never an implied "nothing is stale".
$noHealth = bp_duo($repoRoot, [
    'census',
    '--dir=' . $scratch . '/sites',
    '--manifests=' . $library,
    '--format=json',
]);
$noHealthDocument = json_decode($noHealth['stdout'], true);
duo_check_same('not-supplied', $noHealthDocument['fleet_health']['source'], 'a census with no health document says so');
duo_check_same([], $noHealthDocument['fleet_health']['rows'], 'and ranks nothing');
duo_check(
    str_contains($noHealthDocument['fleet_health']['disclosure'], 'no adapter freshness is known'),
    'stating the absence rather than letting an empty block read as a clean bill of health'
);

// A document of the wrong format is refused rather than partially read.
$wrongFormat = $scratch . '/wrong.json';
file_put_contents($wrongFormat, Canon::encode(['format' => 'duo-fleet-census/v1', 'freshness' => []]));
$refusedFormat = bp_duo($repoRoot, [
    'census',
    '--dir=' . $scratch . '/sites',
    '--manifests=' . $library,
    '--health=' . $wrongFormat,
    '--format=json',
]);
duo_check_same(1, $refusedFormat['exit'], 'a --health document of the wrong format is refused');
duo_check(
    str_contains($refusedFormat['stdout'], 'census_health_unsupported'),
    'with a typed reason code, not a partial read'
);

// ============================================= 8. it has no write path

$source = (string) file_get_contents($repoRoot . '/cli/src/Adapter/AdapterProposals.php');
foreach (['file_put_contents', 'fopen', 'fwrite($', 'unlink', 'rename(', 'mkdir', 'copy('] as $primitive) {
    duo_check(
        !str_contains($source, $primitive),
        "AdapterProposals.php opens no write path ($primitive): the proposal is evidence FOR a reviewed edit, "
        . 'never the edit'
    );
}

duo_check_same(
    $manifestsBefore,
    bp_manifest_digests($repoRoot),
    'and no byte under manifests/ moved across the whole suite — including the runs that read the shipped '
    . 'library — because a byte there is adapter identity and a job that moved one would re-pin the fleet'
);

duo_check_summary('adapter boundary proposals');
