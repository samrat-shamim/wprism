<?php
/**
 * Offline contract for WP-5.4 / spec/repo-format.md § v3.18: the COMPUTED
 * evidence grade that sits BESIDE the reviewed certification word.
 *
 * WHAT WAS MEASURED, AND WHY IT NEEDED A RIDER
 * --------------------------------------------
 * `adapter-packages/<name>/package/disposition.json` carries a three-value
 * status and every read surface projects it BINARY. Measured on the shipped
 * historical 17-subject library: 16 printed the same
 * word, `certified`. Their evidence is not the same. `acf` carries 11 of its
 * 11 applicable scenario families; `polylang` and `woocommerce` carry 12 of 12
 * — and an operator choosing between two adapters for one plugin cannot see
 * that difference anywhere, because the only vocabulary available is the word
 * itself. The structural consequence is the dangerous one: the ONLY way to say
 * "this one carries far more evidence" was to widen what `certified` means.
 * PART 6 independently pins the current source census, including withdrawals.
 *
 * Three machine-readable records already answered it and projected into
 * nothing. `tools/adapter-grade.php` is the one definition of what they add up
 * to, renders the current aggregate only on request, and validates the
 * package-owned inputs in `make release-gate`. This suite is the acceptance
 * for the five properties that make the number a derivation rather than a
 * second status:
 *
 *   1. COMPUTED. Every grade is arithmetic over the three evidence documents
 *      on THIS call (PART 1), and it MOVES the moment any of them moves
 *      (PART 2) — including back, since nothing is memoised.
 *   2. NO EVIDENCE, NO GRADE (PART 3). Platform reach alone cannot mint one:
 *      an adapter nobody exercised still states the whole reviewed boundary
 *      through `narrowed_environment()`'s default, so grading that would hand
 *      a fresh unreviewed adapter a number for having declared nothing.
 *   3. NEVER AUTHORED (PART 4). An input carrying a `grade` member is refused
 *      BY NAME — not ignored, because a member somebody could write down would
 *      be read by the next reader that wanted one, and from that moment the
 *      number is an assertion wearing a derivation's clothes.
 *   4. THE WEAKEST AXIS, NEVER AN AVERAGE (PART 5). Averaging would let a wide
 *      platform claim compensate for missing scenario evidence, which is
 *      exactly the arithmetic that turns an evidence summary into a marketing
 *      number.
 *   5. `certified` IS UNTOUCHED (PART 6), re-measured through
 *      `ManifestDispositions::report()` — the shipped read surface — and by
 *      proving the whole grade model reaches no shipped byte.
 *
 * PART 7 is the gate: `--check` recomputes and validates source records without
 * a checked-in aggregate, while `render` writes the exact current projection
 * only to stdout. Invalid source bites; a valid package evidence edit passes
 * without a central projection edit and visibly moves the rendered grade.
 *
 * WHY THE MODEL IS EXERCISED WHERE IT LIVES
 * -----------------------------------------
 * `tools/adapter-grade.php`, in-process. Two of its three inputs are not
 * shipped — `cli/src/Onboarding/Adopt.php` tars exactly `agent recovery`, so
 * the readiness ledger under sandbox/ reaches no site — and the model has one
 * validator/render command. The functions are called
 * directly for the axis arithmetic (a subprocess per case would pay a PHP boot
 * for a number this process can read), and the GATE half runs the real
 * `php tools/adapter-grade.php --check` against a mutated copy of the tree, so
 * the command an operator actually runs is the one measured.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

$wprismRoot = dirname(__DIR__, 4);

// PHP strips `#!…` only from the ENTRY script, never from an include, so a
// plain require of an executable tool prints its shebang into this suite's
// stdout — the same reason regress_adapter_test_kit.php:55-61 wraps its own.
// The file is otherwise side-effect free: `grade_main()` sits behind a
// SCRIPT_FILENAME guard, and the `$repo` it defines at include scope is this
// repository root (deliberately not read below; `$wprismRoot` is this suite's).
ob_start();
require_once $wprismRoot . '/tools/adapter-grade.php';
ob_end_clean();

use WPrism\AdapterLibrary;
use WPrism\ManifestDispositions;
use WPrism\Tooling\AdapterProductionReadiness;

/** @return array<string,mixed> */
$readJson = static function (string $path): array {
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded)) {
        throw new RuntimeException('not a JSON object: ' . $path);
    }
    return $decoded;
};

$adapterLibrary = AdapterLibrary::fromSourceTree($wprismRoot);
$platformDocument = $readJson($adapterLibrary->platformBoundaryPath());
$boundary = $platformDocument['platform'];
$shippedLedger = AdapterProductionReadiness::load($wprismRoot);
$families = array_map('strval', $shippedLedger['scenario_families']);
$gradesRender = grade_build($wprismRoot);

wprism_check_same(12, count($families), 'the readiness ledger still declares the 12 reviewed scenario families the breadth axis counts against');

/**
 * A readiness ledger holding one synthetic subject, with every family placed
 * in exactly one bucket — which is the ledger's own accounting rule
 * (regress_adapter_production_readiness.php owns it; the grade REFUSES an
 * unaccounted family rather than silently counting a smaller denominator).
 */
$ledgerFor = static function (array $covered, array $gaps, array $blocked, array $notApplicable) use ($shippedLedger): array {
    return [
        'format' => $shippedLedger['format'],
        'scenario_families' => $shippedLedger['scenario_families'],
        'adapters' => [
            'acme' => [
                'readiness' => 'partial',
                'covered' => array_fill_keys($covered, ['sandbox/conformance/checks/acme.sh']),
                'gaps' => array_fill_keys($gaps, 'no evidence yet'),
                'blocked' => array_fill_keys($blocked, 'blocked on a missing primitive'),
                'not_applicable' => array_fill_keys($notApplicable, 'the declared surface has none'),
            ],
        ],
    ];
};

// 5 covered, 4 gaps, 2 blocked, 1 not_applicable — 12 families, one bucket each.
$mixedLedger = $ledgerFor(
    array_slice($families, 0, 5),
    array_slice($families, 5, 4),
    array_slice($families, 9, 2),
    array_slice($families, 11, 1)
);

echo "PART 1 — the three axes are COMPUTED, and each names its own input\n";

$breadth = grade_coverage_breadth($mixedLedger, 'acme');
wprism_check_same('partial', $breadth['state'], 'coverage breadth over a mixed ledger row is partial');
wprism_check_same(5, count($breadth['exercised']), '...5 families carry evidence files');
wprism_check_same(6, count($breadth['outstanding']), '...6 are outstanding, which is `gaps` and `blocked` together: both mean the evidence is not there yet');
wprism_check_same(1, count($breadth['excluded']), '...and the 1 family reviewed `not_applicable` leaves BOTH counts rather than scoring as a gap — penalising an adapter for a family its surface cannot contain would make the axis measure adapter SHAPE instead of evidence');

$bundleProof = [
    'digest' => str_repeat('a', 64),
    'exercised' => true,
    'force_hatches' => [],
    'git_revision' => str_repeat('b', 40),
    'schema' => 'wprism-subject-certification-bundle/v1',
    'signed_at' => '2026-08-24T00:00:00+00:00',
    'tests' => ['t-alpha', 't-beta', 't-gamma'],
];
$depth = grade_exercise_depth($bundleProof, 'acme');
wprism_check_same('complete', $depth['state'], 'exercise depth over a bundle proof that recorded three named passing tests is complete');
wprism_check_same(['t-alpha', 't-beta', 't-gamma'], $depth['exercised'], '...naming the tests, because the COUNT is the discriminating fact `certified` cannot carry: three cited tests and forty are two different claims');

$unexercisedProof = ['digest' => str_repeat('c', 64), 'exercised' => false, 'tests' => []];
$depthNone = grade_exercise_depth($unexercisedProof, 'acme');
wprism_check_same('none', $depthNone['state'], 'a bundle that recorded `exercised: false` grades NONE — a record that says nothing was done, which is not the same fact as no record at all');
wprism_check_same(null, grade_exercise_depth(null, 'acme'), '...and a subject with no `provenance.proof.bundle` at all is SILENT (null), the distinction AdapterSources::certification_evidence() draws between `[]` and `null`');

// § v3.5 narrowing is CALLED, never reimplemented: this is the same projection
// AdapterCertification::signStatement() binds as a certificate's exercised
// cells (§ v3.6/WP-4.7), so the reach axis cannot grade a claim the agent does
// not make.
$statedWide = ManifestDispositions::narrowed_environment(['name' => 'acme', 'spec_version' => 3], $boundary);
$reachWide = grade_platform_reach($statedWide, $boundary);
wprism_check_same('complete', $reachWide['state'], 'platform reach for a claim that narrows nothing is complete');
wprism_check_same(
    ['php:8.3', 'php:8.4', 'wordpress:6.9', 'wordpress:7.0', 'wordpress:7.1'],
    $reachWide['exercised'],
    '...over exactly the boundary cells that publish a per-cell exercise witness'
);
wprism_check_same(
    ['database', 'filesystem', 'process'],
    $reachWide['excluded'],
    '...while the three axes that publish no `verified` series are EXCLUDED and named — the boundary document says the database axis omits one deliberately, so scoring it as unwitnessed would be this projection contradicting the document it reads'
);

$statedNarrow = ManifestDispositions::narrowed_environment(
    ['name' => 'acme', 'spec_version' => 3, 'environment' => ['php' => ['8.3'], 'wordpress' => ['7.1']]],
    $boundary
);
$reachNarrow = grade_platform_reach($statedNarrow, $boundary);
wprism_check_same('partial', $reachNarrow['state'], 'an adapter that NARROWS under § v3.5 reaches fewer cells');
wprism_check_same(['php:8.3', 'wordpress:7.1'], $reachNarrow['exercised'], '...exactly the cells it declared');
wprism_check_same(['php:8.4', 'wordpress:6.9', 'wordpress:7.0'], $reachNarrow['outstanding'], '...and reach is EXTENT, not a promise-keeping ratio: narrowing is honest, and it still reads as less reach');

// The one boundary/claim disagreement reach REFUSES instead of absorbing. A
// capability claim states exactly narrowed_environment()'s four members, so a
// `verified` series on any other axis has no per-claim witness — counting its
// cells as outstanding would drop every adapter's grade at once from one
// platform.json edit and report it as if the adapters had lost evidence.
$boundaryWithUnstatableSeries = $boundary;
$boundaryWithUnstatableSeries['compatibility']['filesystem']['verified'] = ['posix' => 'local-posix/v1'];
wprism_check_throws(
    static fn() => grade_platform_reach($statedWide, $boundaryWithUnstatableSeries),
    RuntimeException::class,
    'a boundary axis publishing an exercise series a claim cannot state is REFUSED, naming the axis and the two remedies — the decision goes in front of a reader rather than into every adapter\'s denominator',
    "axis 'filesystem' publishes a `verified` series that a capability claim cannot state"
);

// Naming the input per axis is an acceptance criterion, not documentation: a
// number whose provenance is not printed beside it is indistinguishable from
// one somebody typed.
foreach ([
    'coverage_breadth' => [
        'record' => $breadth,
        'token' => 'adapter-packages/acme/evidence/production-readiness.json',
    ],
    'exercise_depth' => ['record' => $depth, 'token' => 'provenance.proof.bundle.exercised'],
    'platform_reach' => [
        'record' => $reachWide,
        'token' => 'platform/adapter-library/capabilities/platform.json',
    ],
] as $axis => $case) {
    wprism_check(
        str_contains($case['record']['basis'], $case['token']),
        "the $axis axis names its own input in its `basis` (`{$case['token']}`), so the number is readable back to the document it came from"
    );
}

echo "\nPART 2 — the grade MOVES when the evidence moves, and is re-derived on every call\n";

$before = grade_of('acme', $mixedLedger, $bundleProof, $statedWide, $boundary);
wprism_check_same('partial', $before['state'], 'the combined grade over the mixed ledger is partial');
wprism_check_same(13, $before['exercised'], '...13 units exercised (5 families + 3 tests + 5 cells)');
wprism_check_same(19, $before['declared'], '...of 19 counted (11 families + 3 tests + 5 cells)');

// MOVE THE EVIDENCE: promote one gap family to covered, exactly as a reviewer
// landing a conformance file does.
$movedLedger = $mixedLedger;
$promoted = $families[5];
$movedLedger['adapters']['acme']['covered'][$promoted] = ['sandbox/conformance/checks/acme.sh'];
unset($movedLedger['adapters']['acme']['gaps'][$promoted]);
$after = grade_of('acme', $movedLedger, $bundleProof, $statedWide, $boundary);
wprism_check_same($before['exercised'] + 1, $after['exercised'], 'ONE new evidence file moves the grade by exactly one unit — the number is arithmetic over the documents, not a verdict beside them');
wprism_check_same($before['declared'], $after['declared'], '...with the denominator unmoved, because the family was already counted');

// AND BACK. A memo, a cache or a stored verdict would answer the SECOND value
// here; there is none, so the third call re-derives from the input it is
// handed.
$again = grade_of('acme', $mixedLedger, $bundleProof, $statedWide, $boundary);
wprism_check_same($before, $again, 'the same inputs answer the same grade on a later call in the SAME process, and the moved inputs did not stick — nothing is memoised, so a grade cannot be read back from anywhere');

// The full sweep: every axis complete makes the grade complete, and one axis
// falling makes it fall.
$allCovered = $ledgerFor($families, [], [], []);
$complete = grade_of('acme', $allCovered, $bundleProof, $statedWide, $boundary);
wprism_check_same('complete', $complete['state'], 'every counted unit exercised grades COMPLETE');
wprism_check_same(20, $complete['exercised'], '...20 of 20 (12 families + 3 tests + 5 cells)');
$fallen = grade_of('acme', $allCovered, $unexercisedProof, $statedWide, $boundary);
wprism_check_same('none', $fallen['state'], '...and the identical library with an UNEXERCISED bundle grades none: the evidence moved, so the grade moved');

echo "\nPART 3 — no evidence, no grade\n";

wprism_check_same(
    null,
    grade_of('acme', null, null, $statedWide, $boundary),
    'platform reach alone mints NO grade: it describes what a claim covers, not what anyone did, and a subject nobody exercised must read as ungraded rather than as a low grade'
);
$emptyLedger = ['format' => $shippedLedger['format'], 'scenario_families' => $shippedLedger['scenario_families'], 'adapters' => []];
wprism_check_same(
    null,
    grade_of('acme', $emptyLedger, null, $statedWide, $boundary),
    '...and a ledger that reviews no row for the subject is SILENT rather than zero, so it mints nothing either'
);
wprism_check_same(
    null,
    grade_of('acme', null, null, null, $boundary),
    '...a subject with nothing at all carries no grade'
);
$noEvidence = grade_of('acme', $ledgerFor([], array_slice($families, 0, 6), array_slice($families, 6, 6), []), null, $statedWide, $boundary);
wprism_check_same('none', $noEvidence['state'], 'a REVIEWED row that finds nothing covered is a different answer: `none` is a measurement, `no grade` is the absence of one');

// The shipped library carries the real case, and it reads the honest way.
wprism_check(
    str_contains($gradesRender, '| [wprism-agency-cpt](#wprism-agency-cpt) | excluded | no grade — no exercise evidence |'),
    'the current render carries the live example: wprism-agency-cpt is reviewed `excluded`, the readiness ledger holds no row for it, and its grade cell says NO GRADE rather than a zero'
);

echo "\nPART 4 — a grade may never be AUTHORED: refused BY NAME, in every input\n";

$authored = static fn(array $document): array => $document + ['grade' => 'A+'];
wprism_check_throws(
    static fn() => grade_coverage_breadth(
        ['format' => $shippedLedger['format'], 'scenario_families' => $shippedLedger['scenario_families'], 'adapters' => ['acme' => $authored($mixedLedger['adapters']['acme'])]],
        'acme'
    ),
    RuntimeException::class,
    'a readiness ledger ROW carrying an authored `grade` is refused',
    'authored `grade` member'
);
wprism_check_throws(
    static fn() => grade_exercise_depth($authored($bundleProof), 'acme'),
    RuntimeException::class,
    '...so is a bundle proof carrying one',
    'derived from evidence on every call'
);
wprism_check_throws(
    static fn() => grade_platform_reach($authored($statedWide), $boundary),
    RuntimeException::class,
    '...so is a stated environment carrying one',
    'authored `grade` member'
);
// The other half of "never authored": there is nowhere for an authored grade
// to come FROM either. `grade_build()` returns prose; it does not name or write
// a destination a later reader could mistake for a source of truth.
wprism_check(
    str_starts_with($gradesRender, "# Adapter evidence grades\n"),
    'grade_build() returns the aggregate prose in memory'
);
wprism_check_same(
    $gradesRender,
    grade_build($wprismRoot),
    '...rendered twice in one process from the same tree, byte for byte: the projection is a pure function of its inputs, so re-deriving it can never be the thing that changes it'
);

echo "\nPART 5 — the grade is its WEAKEST axis, never an average\n";

$lopsided = grade_of('acme', $ledgerFor([], $families, [], []), $bundleProof, $statedWide, $boundary);
wprism_check_same('none', $lopsided['state'], 'coverage none + depth complete + reach complete grades NONE');
wprism_check_same(8, $lopsided['exercised'], '...even though 8 of 20 units are exercised, which an average would have reported as a respectable fraction');
wprism_check_same(20, $lopsided['declared'], '...out of 20');
wprism_check(
    $lopsided['axes']['exercise_depth']['state'] === 'complete'
        && $lopsided['axes']['platform_reach']['state'] === 'complete',
    '...and the two complete axes are still printed complete: the weakest axis decides the headline, it does not erase what the others measured'
);

echo "\nPART 6 — `certified` is untouched: the shipped word, byte for byte\n";

$manifests = [];
foreach ($adapterLibrary->packages() as $package) {
    $manifests[] = $readJson($package->manifestPath());
}
$dispositions = ManifestDispositions::load_library($adapterLibrary);
wprism_check(is_object($dispositions), 'the shipped disposition library still loads through the product path');
$report = $dispositions->report($manifests);

$statuses = [];
foreach ($report['manifests'] as $row) {
    $statuses[(string) $row['name']] = (string) $row['status'];
}
ksort($statuses, SORT_STRING);
// WPForms is a non-authorizing preview; Yoast Duplicate Post's managed-clone
// claim was withdrawn. Neither may count beside the 16 certified subjects.
wprism_check_same(
    ['certified' => 18, 'excluded' => 1, 'experimental' => 4],
    (static function (array $words): array {
        $counts = array_count_values($words);
        ksort($counts, SORT_STRING);
        return $counts;
    })(array_values($statuses)),
    'ManifestDispositions::report() projects the reviewed word verbatim over 23 subjects, including both non-authorizing capsules'
);
wprism_check(
    str_contains(
        (string) file_get_contents($wprismRoot . '/agent/src/Policy/ManifestDispositions.php'),
        "in_array(\$status, ['certified', 'experimental', 'excluded'], true)"
    ),
    '...and the census preserves all three reviewed statuses accepted by the engine'
);
wprism_check_same('certified', $statuses['acf'], '...`certified` still means `certified`');

$findGrade = static function (mixed $node) use (&$findGrade): bool {
    if (!is_array($node)) {
        return false;
    }
    foreach ($node as $key => $value) {
        if ($key === 'grade' || $findGrade($value)) {
            return true;
        }
    }
    return false;
};
wprism_check(!$findGrade($report), 'and no `grade` member appears anywhere in the reviewed report: the grade sits BESIDE the word in an on-demand projection, never inside the claim');

// The whole model reaches no shipped byte. This is the strongest form of "the
// certification word is unchanged": there is nothing under the drop-in, the
// orchestrator, the recovery runtime or the adapter library that could have
// changed it (AGENTS.md rule 2 — package payload bytes are adapter identity).
$shippedMentions = [];
foreach (['agent', 'cli', 'recovery', 'adapter-packages', 'platform'] as $tree) {
    $walk = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($wprismRoot . '/' . $tree, FilesystemIterator::SKIP_DOTS));
    foreach ($walk as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $body = (string) file_get_contents($file->getPathname());
        foreach (['adapter-grade.php', 'adapter-grades.md', 'wprism-adapter-grade'] as $token) {
            if (str_contains($body, $token)) {
                $shippedMentions[] = str_replace($wprismRoot . '/', '', $file->getPathname()) . ': ' . $token;
            }
        }
    }
}
wprism_check_same([], $shippedMentions, 'no shipped file names the grade model at all — it ships nothing, so no adapter digest, no pin and no refusal message moved');

// Every grade the render prints stands beside the disposition's OWN status,
// verbatim. A projection that re-spelled the reviewed word would be replacing
// it, which is the one thing this rider may not do.
$dispositionStatuses = [];
foreach ($adapterLibrary->packages() as $package) {
    $dispositionStatuses[$package->name()] = (string) ($readJson($package->dispositionPath())['status'] ?? '');
}
$rowMismatches = [];
foreach ($dispositionStatuses as $name => $status) {
    if (!str_contains($gradesRender, '| [' . $name . '](#' . $name . ') | ' . $status . ' | ')) {
        $rowMismatches[] = $name;
    }
}
wprism_check_same([], $rowMismatches, 'every row of the rendered aggregate prints the reviewed status verbatim in its own column, for all ' . count($dispositionStatuses) . ' subjects');
wprism_check(
    str_contains($gradesRender, '**A grade is computed; a status is reviewed. They are different claims and neither replaces the other.**'),
    '...and the render says so in its first sentence, because a reader who mistakes one for the other is the whole risk this rider carries'
);

// BESIDE means reachable from where the word is. The capability guide is where
// an operator reads the status, so it carries the render command and stable
// model pointer as fixed prose, never a grade VALUE or aggregate inventory.
$capabilities = (string) file_get_contents($wprismRoot . '/docs/capabilities.md');
wprism_check(
    str_contains($capabilities, '`php tools/adapter-grade.php render`')
        && str_contains($capabilities, '[adapter-grades.md](adapter-grades.md)'),
    'the capability guide — where the reviewed word is actually read — points at the on-demand computed grade and its stable definition'
);
wprism_check(
    !str_contains($capabilities, 'complete · ') && !str_contains($capabilities, 'partial · '),
    '...and carries no grade VALUE or central aggregate, so a package edit cannot make this guide stale'
);

// The graded axis discriminates where the word cannot — the measurement that
// motivated the rider, taken on the shipped library rather than asserted.
wprism_check(
    str_contains($gradesRender, '| [acf](#acf) | certified | complete · 16/16 units')
        && str_contains($gradesRender, '| [polylang](#polylang) | certified | complete · 17/17 units'),
    'TWO ADAPTERS, ONE WORD, DIFFERENT EVIDENCE: acf and polylang are both `certified` and both grade complete, but over 16/16 versus 17/17 units — the difference an operator could not see before, now visible without widening what `certified` means'
);

echo "\nPART 7 — the release gate validates sources; render is stdout-only\n";

$run = static function (array $argv): array {
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($argv, $descriptors, $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('cannot start ' . implode(' ', $argv));
    }
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
};

$shipped = $run([PHP_BINARY, $wprismRoot . '/tools/adapter-grade.php', '--check']);
wprism_check_same(0, $shipped['exit'], '`php tools/adapter-grade.php --check` — a make release-gate step — passes on the shipped tree');

$makefile = (string) file_get_contents($wprismRoot . '/Makefile');
wprism_check(
    preg_match('/^release-gate:\n(?:\t.*\n)*\tphp tools\/adapter-grade\.php --check\n/m', $makefile) === 1,
    'and the release-gate recipe actually runs it, so every release recomputes and validates the package-owned evidence'
);

$gateRoot = rtrim(sys_get_temp_dir(), '/') . '/wprism_regress_graded_claim_' . getmypid() . '_' . bin2hex(random_bytes(4));
$copyTree = static function (string $from, string $to) use (&$copyTree): void {
    if (!is_dir($to) && !mkdir($to, 0o700, true) && !is_dir($to)) {
        throw new RuntimeException('cannot create ' . $to);
    }
    foreach (scandir($from) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $source = $from . '/' . $entry;
        is_dir($source) ? $copyTree($source, $to . '/' . $entry) : copy($source, $to . '/' . $entry);
    }
};
$place = static function (string $relative) use ($wprismRoot, $gateRoot): void {
    $target = $gateRoot . '/' . $relative;
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0o700, true) && !is_dir(dirname($target))) {
        throw new RuntimeException('cannot create ' . dirname($target));
    }
    copy($wprismRoot . '/' . $relative, $target);
};

// Everything the tool opens, and nothing else: the agent readers and their
// dependencies, the manifest library, package-owned readiness records and the
// tool. There is deliberately no docs/ tree or aggregate projection to check.
$copyTree($wprismRoot . '/adapter-packages', $gateRoot . '/adapter-packages');
$copyTree($wprismRoot . '/platform', $gateRoot . '/platform');
$place('agent/src/Kernel/Canon.php');
$place('agent/src/Kernel/ManifestExecutableLoader.php');
$place('agent/src/Policy/AdapterLibrary.php');
$place('agent/src/Policy/AdapterPackage.php');
$place('agent/src/Policy/ManifestDispositions.php');
$place('tools/adapter-grade.php');
$place('tools/src/AdapterProductionReadiness.php');
foreach ($shippedLedger['adapters'] as $readiness) {
    foreach ($readiness['covered'] as $evidencePaths) {
        foreach ($evidencePaths as $evidencePath) {
            $place((string) $evidencePath);
        }
    }
}

$snapshotTree = static function (string $root): array {
    $snapshot = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $snapshot[$relative] = hash_file('sha256', $file->getPathname());
        }
    }
    ksort($snapshot, SORT_STRING);
    return $snapshot;
};

$gate = [PHP_BINARY, $gateRoot . '/tools/adapter-grade.php', '--check'];
$renderCommand = [PHP_BINARY, $gateRoot . '/tools/adapter-grade.php', 'render'];
$beforeCommands = $snapshotTree($gateRoot);
$baseline = $run($gate);
wprism_check_same(0, $baseline['exit'], 'the unmutated copy passes, so every refusal below is the mutation and not the copy');
wprism_check_same(
    "adapter grade check: package-owned evidence and computed grade inputs agree\n",
    $baseline['stdout'],
    '`--check` reports source validation rather than comparing a stored aggregate'
);
$baselineRender = $run($renderCommand);
wprism_check_same(0, $baselineRender['exit'], '`render` succeeds against the same source-only tree');
wprism_check(
    str_starts_with($baselineRender['stdout'], "# Adapter evidence grades\n")
        && str_contains($baselineRender['stdout'], '| [acf](#acf) | certified | complete · 16/16 units'),
    '`render` emits the current aggregate to stdout'
);
wprism_check_same(
    grade_build($gateRoot),
    $baselineRender['stdout'],
    'the CLI render is byte-identical to the in-process model over the same source tree'
);
wprism_check_same(
    $beforeCommands,
    $snapshotTree($gateRoot),
    '`--check` and `render` write no source or projection file'
);
wprism_check(
    !file_exists($gateRoot . '/docs/adapter-grades.md') && !is_dir($gateRoot . '/docs'),
    'neither command requires or creates a central adapter-grade projection'
);

// Move one package's evidence VALIDLY. The gate still passes, no central file
// is touched, and only the on-demand render moves.
$ledgerPath = $gateRoot . '/adapter-packages/acf/evidence/production-readiness.json';
$ledgerBytes = (string) file_get_contents($ledgerPath);
$mutatedLedger = json_decode($ledgerBytes, true, 512, JSON_THROW_ON_ERROR);
unset($mutatedLedger['covered']['deletion']);
$mutatedLedger['gaps']['deletion'] = 'evidence withdrawn';
$mutatedLedger['readiness'] = 'unready';
file_put_contents($ledgerPath, json_encode($mutatedLedger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$editedSource = $snapshotTree($gateRoot);
$movedEvidenceCheck = $run($gate);
wprism_check_same(0, $movedEvidenceCheck['exit'], 'a valid edit to one package-owned readiness record needs no central projection update');
$movedEvidenceRender = $run($renderCommand);
wprism_check_same(0, $movedEvidenceRender['exit'], 'the valid package edit remains renderable');
wprism_check(
    str_contains($movedEvidenceRender['stdout'], '| [acf](#acf) | certified | partial · 15/16 units')
        && !str_contains($movedEvidenceRender['stdout'], '| [acf](#acf) | certified | complete · 16/16 units'),
    'the on-demand render re-derives the moved ACF grade directly from its package evidence'
);
wprism_check_same(
    $editedSource,
    $snapshotTree($gateRoot),
    'checking and rendering the package edit writes nothing else'
);
wprism_check(
    !file_exists($gateRoot . '/docs/adapter-grades.md'),
    'the package evidence edit requires no central projection file'
);

// Invalid package evidence bites at its source rather than masquerading as a
// stale prose problem.
$invalidLedger = json_decode($ledgerBytes, true, 512, JSON_THROW_ON_ERROR);
unset($invalidLedger['covered']['deletion']);
file_put_contents($ledgerPath, json_encode($invalidLedger, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$invalidEvidenceCheck = $run($gate);
wprism_check($invalidEvidenceCheck['exit'] !== 0, 'THE GATE BITES ON INVALID SOURCE: an unaccounted evidence family is refused');
wprism_check(
    str_contains($invalidEvidenceCheck['stderr'], "readiness record 'acf' does not account for every scenario family"),
    '...naming the package record and broken accounting rule rather than a central document'
);
$invalidEvidenceRender = $run($renderCommand);
wprism_check(
    $invalidEvidenceRender['exit'] !== 0
        && str_contains($invalidEvidenceRender['stderr'], "readiness record 'acf' does not account for every scenario family"),
    '`render` validates the same source before emitting a grade'
);

file_put_contents($ledgerPath, $ledgerBytes);
$acfDispositionPath = $gateRoot . '/adapter-packages/acf/package/disposition.json';
$acfDispositionBytes = (string) file_get_contents($acfDispositionPath);
$authoredDisposition = json_decode($acfDispositionBytes, true, 512, JSON_THROW_ON_ERROR);
$authoredDisposition['grade'] = 'complete';
file_put_contents($acfDispositionPath, json_encode($authoredDisposition, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$authoredRun = $run($gate);
wprism_check($authoredRun['exit'] !== 0, 'THE GATE BITES ON AN AUTHORED GRADE: a disposition carrying a `grade` member is refused rather than obeyed');
wprism_check(
    str_contains($authoredRun['stderr'], 'may not be authored')
        && str_contains($authoredRun['stderr'], "the disposition for 'acf'"),
    '...naming the member and the document that carries it, so whoever wrote it is told why it cannot exist'
);

file_put_contents($acfDispositionPath, $acfDispositionBytes);
$misboundRecord = json_decode($ledgerBytes, true, 512, JSON_THROW_ON_ERROR);
$misboundRecord['adapter'] = 'acme-not-reviewed';
file_put_contents($ledgerPath, json_encode($misboundRecord, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
$misboundRun = $run($gate);
wprism_check($misboundRun['exit'] !== 0, 'and a package readiness record bound to another subject is refused: one capsule cannot author a grade row for another');
wprism_check(
    str_contains($misboundRun['stderr'], "identity does not match 'acf'"),
    '...naming the capsule whose record identity disagrees'
);

$rmTree = static function (string $dir) use (&$rmTree): void {
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        is_dir($path) ? $rmTree($path) : @unlink($path);
    }
    @rmdir($dir);
};
$rmTree($gateRoot);
wprism_check(!is_dir($gateRoot), 'the scratch gate root is removed');

wprism_check_summary('regress_graded_claim');
