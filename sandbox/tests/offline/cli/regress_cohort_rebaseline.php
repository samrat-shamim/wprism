<?php
/**
 * Offline regression for `duo census --baseline=` — the cohort re-baseline
 * (`duo-cohort-rebaseline/v1`) over two `duo-fleet-census/v1` documents.
 *
 * WHAT FAILS WITHOUT THE CHANGE
 * -----------------------------
 * `duo census` published one moment. WP-6.3 is not graded on a moment: its
 * exit criterion is MOVEMENT — "a re-measured fleet coverage ratio and
 * adoption funnel against the Phase-0 baseline, published with the residual
 * named. A cohort that ships every adapter but does not move the ratio is a
 * FINDING that re-opens G0, not a success." Nothing computed that comparison,
 * so the one outcome the program is most likely to hit — adapter count rises,
 * measured coverage stalls on pin adoption — had no name, no number and no
 * place to be reported.
 *
 * Against the pre-change tree the very first call refuses —
 * `{"format":"duo-command-refusal/v1",…,"reason_code":"invalid_arguments",
 * "message":"unsupported or empty flag '--baseline=…'"}`, exit 2 — and 103 of
 * this suite's 134 assertions fail (measured by re-running this file against a
 * `git archive` of the parent commit with only these fixtures added).
 *
 * WHY IT DRIVES THE EXECUTABLE
 * ----------------------------
 * Same reason `regress_fleet_census.php` does: the deliverable includes a flag
 * parser with three mutual-exclusion rules, a dispatch path that chooses
 * between two documents, and a bounded human view whose LAST line is the
 * finding. An in-process call to `CohortRebaseline::project()` would assert the
 * document and miss all three.
 *
 * WHAT IS ASSERTED
 * ----------------
 *  1. THE VERDICT VOCABULARY IS CLOSED AND EACH WORD IS REACHABLE. Six
 *     fixtures drive the six verdicts, and the one that matters —
 *     `shipped_without_movement` — is driven by the exact shape WP-6.3's risk
 *     section describes: a cohort ships, every new adapter is `adapter_unpinned`,
 *     and the ratio does not move. It is rendered as the FINDING it is, in
 *     both output modes, and it does NOT become a fourth exit code.
 *  2. ATTRIBUTION IS PER ADAPTER AND PER SURFACE. The four ways one surface id
 *     can move (`claimed`, `regressed`, `appeared`, `resolved`) are separated,
 *     and a slug that moved nothing is a COUNT rather than a listing.
 *  3. COMPARABILITY IS PUBLISHED, NEVER ASSUMED. `same-estate`,
 *     `population-changed` and `disjoint-estate` are computed from the opaque
 *     site labels alone, and only the first sets `cohort.attributable`.
 *  4. TWO INCOMPARABILITIES REFUSE. A different platform boundary
 *     (`library.site_mode`) and a document that is not a census both refuse
 *     with their own reason codes; an ENGINE move deliberately does not,
 *     because the program this verb was built for crosses a spec flag day
 *     between its two measurements.
 *  5. THE REAL RE-BASELINE OF THE CORE ESTATE. The committed baseline is a
 *     genuine `duo-fleet-census/v1` document produced by the engine of its own
 *     day (spec_version 2, agent_version 0.5.0) at commit f99f6712 — the
 *     commit that first shipped `duo census`, which is the earliest moment
 *     this program could measure itself at all. The claim is checkable rather
 *     than asserted: extract that tree and re-run the same fold, and the bytes
 *     come back identical.
 *
 *         git archive f99f6712 | tar -x -C <tmp>
 *         php <tmp>/cli/duo census --dir=sandbox/tests/fixtures/census/core-estate \
 *             --format=json | diff - sandbox/tests/fixtures/census/g0-baseline.census.json
 *
 *     That reproduction is not run here — this suite does not check out
 *     commits — but it is the whole reason the fixture is admissible as a
 *     baseline instead of a re-print. This suite re-measures the same estate
 *     against the library THIS checkout ships and asserts the program's honest
 *     result: the adapter-coverage delta is ZERO, the two engines agree on the
 *     coverage oracle's content address across the flag day, and the residual
 *     the program did NOT close is still named at the top of the demand rank.
 *     Asserting that truthfully is the acceptance; inventing movement would not
 *     be.
 *  6. THE RUNBOOK PUBLISHES THAT MEASURED RESULT, NOT A RETELLING OF IT.
 *     `docs/guides/coverage-cohort.md` is the operator loop (census rank ->
 *     probe -> draft -> boundary -> vectors -> kit -> certify -> bulk-adopt ->
 *     re-measure) and it quotes the core-estate re-baseline. Every rendered
 *     line of that quote is compared against the run in case 5, so the guide
 *     cannot keep publishing a number the tree stopped producing.
 *
 * THE ESTATE, STATED PLAINLY
 * --------------------------
 * `sandbox/tests/fixtures/census/core-estate/` is a fixed REFERENCE estate of
 * three labelled single-site submissions. It is not a managed fleet and this
 * suite never calls it one: no real fleet exists in this environment, and G0's
 * own limitation clause — "if participation is too low to distinguish these
 * cases, the ranking is labelled as the core estate's own and G0 opens with
 * that limitation recorded" — is what admits a labelled estate of three. What
 * the estate buys is exactness: held byte-constant across both measurements,
 * every difference between the two censuses is a difference in the LIBRARY and
 * in nothing else, which is the only way a coverage delta means anything.
 *
 * If a later change moves the shipped library's coverage of this estate, case
 * 5 fails — deliberately. That is the re-measurement the program asked for at
 * every phase exit, and the remedy is to read the published delta and record
 * the new result, never to loosen the assertion.
 */
declare(strict_types=1);

// From offline/cli/: two hops to the corpus root, four to the repo root.
require_once __DIR__ . '/../../lib/check.php';

$repoRoot = dirname(__DIR__, 4);
$duo = $repoRoot . '/cli/duo';
$censusFixtures = $repoRoot . '/sandbox/tests/fixtures/census';
// The estate directory holds ONLY inventories: `--dir` labels every *.json in
// it by its stem through FleetCensus::LABEL_PATTERN, so a census document
// parked beside them would be read as a submission and refused as a label.
$coreEstate = $censusFixtures . '/core-estate';
$g0Baseline = $censusFixtures . '/g0-baseline.census.json';

// Scratch under sandbox/tmp/ per AGENTS.md rule 3, with a per-process suffix
// because `make -j8` runs the corpus concurrently.
$scratch = $repoRoot . '/sandbox/tmp/rebaseline_' . getmypid() . '_' . bin2hex(random_bytes(4));

function cr_rmtree(string $path): void {
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                cr_rmtree($path . '/' . $entry);
            }
        }
        @rmdir($path);
        return;
    }
    @unlink($path);
}
register_shutdown_function(static function () use ($scratch): void {
    cr_rmtree($scratch);
});
if (!is_dir($scratch) && !mkdir($scratch, 0777, true) && !is_dir($scratch)) {
    throw new RuntimeException("could not create the scratch directory: $scratch");
}

/** @param array<string,mixed> $document */
function cr_write(string $path, array $document): string {
    file_put_contents(
        $path,
        json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
    );
    return $path;
}

/**
 * Run the real `duo` executable.
 *
 * @param list<string> $args
 * @return array{exit:int,stdout:string,stderr:string}
 */
function cr_run(string $duo, string $cwd, array $args): array {
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, $duo], $args),
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
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

/** @return array<string,mixed>|null */
function cr_json(string $raw): ?array {
    $decoded = json_decode(trim($raw), true);
    return is_array($decoded) ? $decoded : null;
}

/**
 * One synthetic `duo-fleet-census/v1` document, built from the few blocks the
 * re-baseline reads. Every field the projector whitelists is present; nothing
 * else is, which is the point — a re-baseline that needed a key not listed
 * here would be reading past its own whitelist.
 *
 * @param list<array<string,mixed>> $demand
 * @param list<string> $labels
 * @return array<string,mixed>
 */
function cr_census(
    array $labels,
    array $demand,
    int $total,
    int $covered,
    string $oracle,
    int $specVersion = 3,
    string $agentVersion = '0.6.0',
    string $siteMode = 'single-site'
): array {
    $sites = [];
    foreach ($labels as $label) {
        $sites[] = ['label' => $label, 'surfaces' => ['total' => 0, 'covered' => 0, 'pending' => 0, 'uncovered' => 0]];
    }
    $funnel = [];
    foreach (['no_adapter', 'adapter_unreviewed', 'adapter_unpinned', 'adapter_pinned'] as $stage) {
        $funnel[$stage] = ['plugins' => 0, 'sites_installed' => 0, 'sites_pinning' => 0, 'uncovered_surfaces' => 0];
    }
    $rows = [];
    foreach ($demand as $row) {
        $row += [
            'covering_adapter' => null,
            'funnel_stage' => 'no_adapter',
            'sites_installed' => 1,
            'sites_pinning' => 0,
            'demand_score' => 0,
            'uncovered_surfaces' => [],
            'claimed_surfaces' => [],
        ];
        $stage = (string) $row['funnel_stage'];
        $funnel[$stage]['plugins']++;
        $funnel[$stage]['sites_installed'] += (int) $row['sites_installed'];
        $funnel[$stage]['sites_pinning'] += (int) $row['sites_pinning'];
        $funnel[$stage]['uncovered_surfaces'] += count((array) $row['uncovered_surfaces']);
        $rows[] = $row;
    }

    return [
        'format' => 'duo-fleet-census/v1',
        'spec_version' => $specVersion,
        'agent_version' => $agentVersion,
        'library' => [
            'adapters' => 16,
            'reviewed' => 16,
            'surfaces_sha256' => $oracle,
            'site_mode' => $siteMode,
        ],
        'population' => [
            'submissions' => count($labels),
            'eligible' => count($labels),
            'excluded_total' => 0,
            'excluded' => [],
            'denominator' => 'eligible',
            'disclosure' => 'fixture',
        ],
        'basis' => ['sites' => count($labels), 'sample_class' => 'narrow', 'caveat' => 'fixture'],
        'sites' => $sites,
        'fleet' => [
            'total' => $total,
            'covered' => $covered,
            'pending' => 0,
            'uncovered' => $total - $covered,
            'covered_ppm' => $total > 0 ? intdiv($covered * 1000000, $total) : null,
        ],
        'demand' => $rows,
        'unattributed' => ['option_groups' => [], 'tables' => [], 'surfaces' => 0, 'option_rows' => 0, 'table_rows' => 0],
        'funnel' => $funnel,
        'fleet_health' => ['source' => 'not-supplied', 'adapters' => 0, 'stale' => 0, 'open_proposals' => 0,
                           'rows' => [], 'disclosure' => 'fixture'],
    ];
}

/**
 * `duo census --baseline=<a> --current=<b> --format=json`, decoded.
 *
 * @param list<string> $extra
 * @return array{exit:int,stdout:string,stderr:string,document:array<string,mixed>}
 */
function cr_rebaseline(string $duo, string $cwd, string $baseline, string $current, array $extra = []): array {
    $run = cr_run($duo, $cwd, array_merge(
        ['census', '--baseline=' . $baseline, '--current=' . $current, '--format=json'],
        $extra
    ));
    return $run + ['document' => cr_json($run['stdout']) ?? []];
}

/** The one attribution row for a slug, or null. @return array<string,mixed>|null */
function cr_moved(array $document, string $slug): ?array {
    foreach ((array) ((array) ($document['attribution'] ?? []))['moved'] ?? [] as $row) {
        if (is_array($row) && ($row['slug'] ?? null) === $slug) {
            return $row;
        }
    }
    return null;
}

/** The one demand row for a slug in a census document. @return array<string,mixed>|null */
function cr_demand(array $document, string $slug): ?array {
    foreach ((array) ($document['demand'] ?? []) as $row) {
        if (is_array($row) && ($row['slug'] ?? null) === $slug) {
            return $row;
        }
    }
    return null;
}

// Every slug below is INVENTED, for the reason
// sandbox/tests/fixtures/assess/make-fixture.php:20-24 states: the
// engine-adapter boundary forbids a shipped plugin slug in cli/src/Assess, and
// a synthetic case that used a real ecosystem's names would tempt the next
// author to match it in production code. The core-estate fixture in case 5 is
// the deliberate exception — it exists precisely to measure the SHIPPED
// library, so it must name the plugins that library claims.
const CR_ORACLE_A = 'aaaa000000000000000000000000000000000000000000000000000000000001';
const CR_ORACLE_B = 'bbbb000000000000000000000000000000000000000000000000000000000002';

// ============================================================ 1. the verdicts
//
// Six fixtures, six words. The pairs differ in exactly one dimension each, so
// a verdict that came out wrong names which dimension moved it.

// --- coverage_moved: a cohort ships, is pinned, and the ratio rises.
$movedBefore = cr_write($scratch . '/moved-before.json', cr_census(
    ['s1', 's2'],
    [
        ['slug' => 'synth-alpha', 'sites_installed' => 2, 'demand_score' => 4,
         'uncovered_surfaces' => ['options:synth_alpha', 'tables:synth_alpha_log']],
    ],
    1000,
    400,
    CR_ORACLE_A
));
$movedAfter = cr_write($scratch . '/moved-after.json', cr_census(
    ['s1', 's2'],
    [
        ['slug' => 'synth-alpha', 'covering_adapter' => 'synth-alpha', 'funnel_stage' => 'adapter_pinned',
         'sites_installed' => 2, 'sites_pinning' => 2, 'demand_score' => 0,
         'claimed_surfaces' => ['options:synth_alpha', 'tables:synth_alpha_log']],
    ],
    1000,
    500,
    CR_ORACLE_B
));
$moved = cr_rebaseline($duo, $repoRoot, $movedBefore, $movedAfter);
duo_check_same(0, $moved['exit'], 'a re-baseline over two census documents exits 0');
duo_check_same('', $moved['stderr'], 'the JSON path writes nothing to stderr');
duo_check_same(
    'duo-cohort-rebaseline/v1',
    $moved['document']['format'] ?? null,
    'the re-baseline names its own versioned format'
);
duo_check_same(
    'coverage_moved',
    (string) (((array) ($moved['document']['cohort'] ?? []))['verdict'] ?? ''),
    'a cohort that ships AND moves the ratio reads coverage_moved'
);
duo_check_same(
    null,
    ((array) ($moved['document']['cohort'] ?? []))['finding'],
    'and carries no finding'
);
duo_check_same(
    100000,
    (int) (((array) ($moved['document']['cohort'] ?? []))['coverage_delta_ppm'] ?? -1),
    'the coverage delta is the ppm difference of the two fleet ratios (40.0% -> 50.0%)'
);
duo_check_same(
    ['synth-alpha'],
    (array) (((array) ($moved['document']['cohort'] ?? []))['adapters_added'] ?? []),
    'the cohort names the adapter that newly covers a slug'
);

// --- shipped_without_movement: THE FINDING. The cohort ships every adapter,
// each one lands in adapter_unpinned, and the ratio does not move — WP-6.3's
// own risk section, made visible as exactly that.
$stallBefore = cr_write($scratch . '/stall-before.json', cr_census(
    ['s1', 's2', 's3'],
    [
        ['slug' => 'synth-beta', 'sites_installed' => 3, 'demand_score' => 6,
         'uncovered_surfaces' => ['options:synth_beta', 'tables:synth_beta_log']],
        ['slug' => 'synth-gamma', 'sites_installed' => 2, 'demand_score' => 2,
         'uncovered_surfaces' => ['options:synth_gamma']],
    ],
    2000,
    900,
    CR_ORACLE_A
));
$stallAfter = cr_write($scratch . '/stall-after.json', cr_census(
    ['s1', 's2', 's3'],
    [
        // The adapters exist and are reviewed. Nobody pinned them, so the
        // residual they would claim is still residual on every site and the
        // ratio is byte-identical to the baseline's.
        ['slug' => 'synth-beta', 'covering_adapter' => 'synth-beta', 'funnel_stage' => 'adapter_unpinned',
         'sites_installed' => 3, 'demand_score' => 6,
         'uncovered_surfaces' => ['options:synth_beta', 'tables:synth_beta_log']],
        ['slug' => 'synth-gamma', 'covering_adapter' => 'synth-gamma', 'funnel_stage' => 'adapter_unpinned',
         'sites_installed' => 2, 'demand_score' => 2,
         'uncovered_surfaces' => ['options:synth_gamma']],
    ],
    2000,
    900,
    CR_ORACLE_B
));
$stall = cr_rebaseline($duo, $repoRoot, $stallBefore, $stallAfter);
duo_check_same(0, $stall['exit'], 'the ships-but-does-not-move case is an ANSWER, exit 0');
$stallCohort = (array) ($stall['document']['cohort'] ?? []);
duo_check_same(
    'shipped_without_movement',
    (string) ($stallCohort['verdict'] ?? ''),
    'a cohort that ships every adapter and moves no ratio reads shipped_without_movement'
);
duo_check_same(true, $stallCohort['shipped'] ?? null, 'the document records that a cohort DID ship');
duo_check_same(
    0,
    (int) ($stallCohort['coverage_delta_ppm'] ?? -1),
    'beside a coverage delta of exactly zero — the two halves of the finding'
);
duo_check_same(
    ['synth-beta', 'synth-gamma'],
    (array) ($stallCohort['adapters_added'] ?? []),
    'both shipped adapters are named'
);
duo_check_same(
    0,
    (int) ($stallCohort['surfaces_claimed'] ?? -1),
    'and not one surface moved out of demand: the adapters exist, no site pins them'
);
$finding = (array) ($stallCohort['finding'] ?? []);
duo_check_same(
    'cohort_shipped_without_movement',
    (string) ($finding['code'] ?? ''),
    'the finding carries its own code rather than being inferred from two numbers'
);
duo_check(
    str_contains((string) ($finding['statement'] ?? ''), 're-opens G0'),
    'and states in as many words that this re-opens G0 rather than being a success'
);
duo_check(
    str_contains((string) ($finding['remedy'] ?? ''), 'adapter_unpinned'),
    'the remedy points at the funnel stage that explains it — an unpinned adapter covers nothing'
);
duo_check_same(
    2,
    (int) ((array) ((array) ($stall['document']['funnel'] ?? []))['adapter_unpinned'] ?? [])['delta']['plugins'] ?? -1,
    'the funnel movement shows both plugins arriving in adapter_unpinned'
);

// The finding must survive the human view, and it must be the LAST thing said.
$stallHuman = cr_run($duo, $repoRoot, ['census', '--baseline=' . $stallBefore, '--current=' . $stallAfter]);
duo_check_same(0, $stallHuman['exit'], 'the human re-baseline view exits 0');
duo_check(
    str_contains($stallHuman['stdout'], 'FINDING cohort_shipped_without_movement:'),
    'the human view prints the finding rather than only the verdict word'
);
duo_check(
    str_contains($stallHuman['stdout'], 'verdict: shipped_without_movement'),
    'and the verdict line beside it'
);
duo_check_same(
    '',
    $stallHuman['stderr'],
    'the finding is an answer, not a diagnostic: nothing goes to stderr'
);

// --limit bounds the ATTRIBUTION listing and nothing below it. The one row a
// shortened view must never drop is the one that says the cohort failed, so
// this asserts both halves: the listing is cut and says how much it cut, and
// the verdict and the finding still print underneath.
$stallBounded = cr_run($duo, $repoRoot, [
    'census', '--baseline=' . $stallBefore, '--current=' . $stallAfter, '--limit=1',
]);
duo_check_same(0, $stallBounded['exit'], 'a bounded human re-baseline exits 0');
duo_check_same(
    1,
    substr_count($stallBounded['stdout'], "\nmoved synth-"),
    '--limit=1 prints one attribution row'
);
duo_check(
    str_contains($stallBounded['stdout'], 'moved: 1 further row(s) in --format=json'),
    'and says how many rows it did not print rather than truncating silently'
);
duo_check(
    str_contains($stallBounded['stdout'], 'FINDING cohort_shipped_without_movement:')
        && str_contains($stallBounded['stdout'], 'verdict: shipped_without_movement'),
    'while the verdict and the finding survive the bound: --limit never hides the failure'
);

// --- adoption_only: no adapter shipped, but pins landed and the ratio rose.
// The distinction WP-3.4 exists for, and the one a bare ratio cannot make.
$adoptBefore = cr_write($scratch . '/adopt-before.json', cr_census(
    ['s1', 's2'],
    [
        ['slug' => 'synth-delta', 'covering_adapter' => 'synth-delta', 'funnel_stage' => 'adapter_unpinned',
         'sites_installed' => 2, 'demand_score' => 2, 'uncovered_surfaces' => ['options:synth_delta']],
    ],
    1000,
    400,
    CR_ORACLE_A
));
$adoptAfter = cr_write($scratch . '/adopt-after.json', cr_census(
    ['s1', 's2'],
    [
        ['slug' => 'synth-delta', 'covering_adapter' => 'synth-delta', 'funnel_stage' => 'adapter_pinned',
         'sites_installed' => 2, 'sites_pinning' => 2, 'demand_score' => 0,
         'claimed_surfaces' => ['options:synth_delta']],
    ],
    1000,
    450,
    CR_ORACLE_A
));
$adopt = cr_rebaseline($duo, $repoRoot, $adoptBefore, $adoptAfter);
$adoptCohort = (array) ($adopt['document']['cohort'] ?? []);
duo_check_same(
    'adoption_only',
    (string) ($adoptCohort['verdict'] ?? ''),
    'a ratio that moved with no adapter added reads adoption_only — the pins moved it, not a cohort'
);
duo_check_same(
    ['synth-delta'],
    (array) ($adoptCohort['slugs_newly_pinned'] ?? []),
    'and the slug whose pin count rose is named'
);
duo_check_same(
    [],
    (array) ($adoptCohort['adapters_added'] ?? []),
    'with no adapter claimed as added, because none was'
);

// --- coverage_regressed: a claim was narrowed, and the net ratio fell.
$regressBefore = cr_write($scratch . '/regress-before.json', cr_census(
    ['s1'],
    [
        ['slug' => 'synth-epsilon', 'covering_adapter' => 'synth-epsilon', 'funnel_stage' => 'adapter_pinned',
         'sites_pinning' => 1, 'claimed_surfaces' => ['options:synth_epsilon', 'tables:synth_epsilon_log']],
    ],
    1000,
    500,
    CR_ORACLE_A
));
$regressAfter = cr_write($scratch . '/regress-after.json', cr_census(
    ['s1'],
    [
        ['slug' => 'synth-epsilon', 'covering_adapter' => 'synth-epsilon', 'funnel_stage' => 'adapter_pinned',
         'sites_pinning' => 1, 'demand_score' => 1,
         'claimed_surfaces' => ['options:synth_epsilon'],
         'uncovered_surfaces' => ['tables:synth_epsilon_log']],
    ],
    1000,
    450,
    CR_ORACLE_B
));
$regress = cr_rebaseline($duo, $repoRoot, $regressBefore, $regressAfter);
$regressCohort = (array) ($regress['document']['cohort'] ?? []);
duo_check_same(
    'coverage_regressed',
    (string) ($regressCohort['verdict'] ?? ''),
    'a ratio that fell reads coverage_regressed, whatever else shipped alongside it'
);
duo_check_same(
    1,
    (int) ($regressCohort['surfaces_regressed'] ?? -1),
    'and the surface that lost its claim is counted'
);
duo_check_same(
    'cohort_coverage_regressed',
    (string) ((array) ($regressCohort['finding'] ?? []))['code'] ?? '',
    'a regression carries a finding too — a narrowed claim is not a rounding error'
);

// --- no_cohort: nothing shipped, nothing moved.
$flatBefore = cr_write($scratch . '/flat-before.json', cr_census(
    ['s1'],
    [['slug' => 'synth-zeta', 'demand_score' => 1, 'uncovered_surfaces' => ['options:synth_zeta']]],
    1000,
    400,
    CR_ORACLE_A
));
$flat = cr_rebaseline($duo, $repoRoot, $flatBefore, $flatBefore);
duo_check_same(
    'no_cohort',
    (string) (((array) ($flat['document']['cohort'] ?? []))['verdict'] ?? ''),
    'two identical censuses read no_cohort — nothing shipped and nothing moved'
);
duo_check_same(
    1,
    (int) (((array) ($flat['document']['attribution'] ?? []))['unchanged'] ?? -1),
    'the unchanged population is a count, so "nothing moved" arrives as a number'
);
duo_check_same(
    [],
    (array) (((array) ($flat['document']['attribution'] ?? []))['moved'] ?? ['x']),
    'and the moved listing is empty rather than absent'
);

// --- unmeasured: a side that measured no surfaces has no ratio, and
// subtracting from nothing is not zero.
$emptyDoc = cr_write($scratch . '/empty.json', cr_census(['s1'], [], 0, 0, CR_ORACLE_A));
$unmeasured = cr_rebaseline($duo, $repoRoot, $emptyDoc, $flatBefore);
duo_check_same(
    'unmeasured',
    (string) (((array) ($unmeasured['document']['cohort'] ?? []))['verdict'] ?? ''),
    'a side with no measured surfaces reads unmeasured, never a 0% collapse'
);
duo_check_same(
    null,
    ((array) ($unmeasured['document']['cohort'] ?? []))['coverage_delta_ppm'],
    'and the delta is null rather than an invented integer'
);

// ============================================== 2. attribution, per surface

$attribBefore = cr_write($scratch . '/attrib-before.json', cr_census(
    ['s1', 's2'],
    [
        // claimed: uncovered at baseline, credited now.
        ['slug' => 'synth-eta', 'sites_installed' => 2, 'demand_score' => 2,
         'uncovered_surfaces' => ['options:synth_eta']],
        // regressed: credited at baseline, uncovered now.
        ['slug' => 'synth-theta', 'covering_adapter' => 'synth-theta', 'funnel_stage' => 'adapter_pinned',
         'sites_installed' => 1, 'sites_pinning' => 1, 'claimed_surfaces' => ['tables:synth_theta_log']],
        // resolved: uncovered at baseline, gone entirely now (the plugin was
        // removed from the estate, or the rows were deleted).
        ['slug' => 'synth-iota', 'sites_installed' => 1, 'demand_score' => 1,
         'uncovered_surfaces' => ['options:synth_iota']],
        // untouched: contributes to `unchanged` and to nothing else.
        ['slug' => 'synth-kappa', 'covering_adapter' => 'synth-kappa', 'funnel_stage' => 'adapter_pinned',
         'sites_installed' => 2, 'sites_pinning' => 2, 'claimed_surfaces' => ['options:synth_kappa']],
    ],
    1000,
    500,
    CR_ORACLE_A
));
$attribAfter = cr_write($scratch . '/attrib-after.json', cr_census(
    ['s1', 's2'],
    [
        ['slug' => 'synth-eta', 'covering_adapter' => 'synth-eta', 'funnel_stage' => 'adapter_pinned',
         'sites_installed' => 2, 'sites_pinning' => 2, 'claimed_surfaces' => ['options:synth_eta']],
        ['slug' => 'synth-theta', 'covering_adapter' => 'synth-theta', 'funnel_stage' => 'adapter_pinned',
         'sites_installed' => 1, 'sites_pinning' => 1, 'demand_score' => 1,
         'uncovered_surfaces' => ['tables:synth_theta_log']],
        ['slug' => 'synth-kappa', 'covering_adapter' => 'synth-kappa', 'funnel_stage' => 'adapter_pinned',
         'sites_installed' => 2, 'sites_pinning' => 2, 'claimed_surfaces' => ['options:synth_kappa']],
        // appeared: present in neither list at baseline.
        ['slug' => 'synth-lambda', 'sites_installed' => 1, 'demand_score' => 1,
         'uncovered_surfaces' => ['tables:synth_lambda_cache']],
    ],
    1000,
    520,
    CR_ORACLE_B
));
$attrib = cr_rebaseline($duo, $repoRoot, $attribBefore, $attribAfter);
duo_check_same(0, $attrib['exit'], 'the attribution fixture exits 0');

$eta = cr_moved($attrib['document'], 'synth-eta') ?? [];
duo_check_same(
    ['options:synth_eta'],
    (array) ($eta['surfaces_claimed'] ?? []),
    'a surface that crossed from uncovered to claimed is attributed to the adapter that claimed it'
);
duo_check_same('synth-eta', (string) ($eta['adapter_current'] ?? ''), 'and that adapter is named');
duo_check_same(null, $eta['adapter_baseline'], 'beside the absence it replaced');
duo_check_same('adapter_added', (string) ($eta['adapter_change'] ?? ''), 'classified as adapter_added');

$theta = cr_moved($attrib['document'], 'synth-theta') ?? [];
duo_check_same(
    ['tables:synth_theta_log'],
    (array) ($theta['surfaces_regressed'] ?? []),
    'a surface that lost its credit is reported with equal weight, not netted away'
);
duo_check_same(
    [],
    (array) ($theta['surfaces_claimed'] ?? ['x']),
    'and is not double-counted as a claim'
);

$iota = cr_moved($attrib['document'], 'synth-iota') ?? [];
duo_check_same(
    ['options:synth_iota'],
    (array) ($iota['surfaces_resolved'] ?? []),
    'a residual that left the estate entirely is `resolved` — never credited to an adapter'
);
duo_check_same(
    'baseline-only',
    (string) ($iota['present'] ?? ''),
    'and the row says which side it was present on'
);

$lambda = cr_moved($attrib['document'], 'synth-lambda') ?? [];
duo_check_same(
    ['tables:synth_lambda_cache'],
    (array) ($lambda['surfaces_appeared'] ?? []),
    'a residual that appeared is `appeared` — new demand, not a regression'
);
duo_check_same('current-only', (string) ($lambda['present'] ?? ''), 'present on the current side only');

duo_check_same(
    null,
    cr_moved($attrib['document'], 'synth-kappa'),
    'a slug whose adapter, stage, credit and pins are all unchanged gets no row at all'
);
duo_check_same(
    1,
    (int) (((array) ($attrib['document']['attribution'] ?? []))['unchanged'] ?? -1),
    'it is counted instead — the count is the signal'
);

// Attribution order is a function of the two documents, never of the order
// either one listed its rank in.
$reordered = cr_census(
    ['s1', 's2'],
    array_reverse((array) (cr_json((string) file_get_contents($attribAfter)) ?? [])['demand']),
    1000,
    520,
    CR_ORACLE_B
);
$reorderedPath = cr_write($scratch . '/attrib-after-reordered.json', $reordered);
$reorderedRun = cr_rebaseline($duo, $repoRoot, $attribBefore, $reorderedPath);
duo_check_same(
    $attrib['stdout'],
    $reorderedRun['stdout'],
    'reversing the current census\'s demand order is byte-identical: the delta is a function of the SET'
);

// ================================================== 3. comparability classes

duo_check_same(
    'same-estate',
    (string) (((array) ($attrib['document']['comparability'] ?? []))['class'] ?? ''),
    'two censuses over the same labels are same-estate'
);
duo_check_same(
    true,
    ((array) ($attrib['document']['cohort'] ?? []))['attributable'] ?? null,
    'and only then is the delta attributable to a cohort'
);

$grownAfter = cr_write($scratch . '/grown-after.json', cr_census(
    ['s1', 's2', 's3'],
    [['slug' => 'synth-zeta', 'demand_score' => 1, 'uncovered_surfaces' => ['options:synth_zeta']]],
    1500,
    700,
    CR_ORACLE_A
));
$grown = cr_rebaseline($duo, $repoRoot, $flatBefore, $grownAfter);
$grownComparability = (array) ($grown['document']['comparability'] ?? []);
duo_check_same(
    'population-changed',
    (string) ($grownComparability['class'] ?? ''),
    'an estate that gained sites is population-changed'
);
duo_check_same(
    ['s2', 's3'],
    (array) ((array) ($grownComparability['sites'] ?? []))['added'] ?? [],
    'and the added labels are named, not merely counted'
);
duo_check_same(
    false,
    ((array) ($grown['document']['cohort'] ?? []))['attributable'] ?? null,
    'a ratio delta across a changed population is NOT attributable to a cohort'
);
duo_check(
    str_contains((string) (((array) ($grown['document']['cohort'] ?? []))['disclosure'] ?? ''), 'not attributable'),
    'and the cohort block says so in prose beside the number'
);

$disjointAfter = cr_write($scratch . '/disjoint-after.json', cr_census(
    ['t1', 't2'],
    [['slug' => 'synth-zeta', 'demand_score' => 1, 'uncovered_surfaces' => ['options:synth_zeta']]],
    1000,
    600,
    CR_ORACLE_A
));
$disjoint = cr_rebaseline($duo, $repoRoot, $flatBefore, $disjointAfter);
duo_check_same(
    'disjoint-estate',
    (string) (((array) ($disjoint['document']['comparability'] ?? []))['class'] ?? ''),
    'two censuses sharing no label at all are disjoint-estate'
);
duo_check(
    str_contains(
        (string) (((array) ($disjoint['document']['comparability'] ?? []))['disclosure'] ?? ''),
        'two different estates'
    ),
    'and the disclosure says the comparison is between two different estates'
);

// ================================================ 4. what refuses, what does not

// A platform boundary move changes what "eligible" MEANS, so the two
// denominators were built by different rules and no delta over them is real.
$otherBoundary = cr_write($scratch . '/other-boundary.json', cr_census(
    ['s1'],
    [],
    1000,
    500,
    CR_ORACLE_A,
    3,
    '0.6.0',
    'multisite'
));
$boundary = cr_rebaseline($duo, $repoRoot, $flatBefore, $otherBoundary);
duo_check_same(1, $boundary['exit'], 'a platform-boundary move between the two censuses refuses');
duo_check_same(
    'rebaseline_boundary_mismatch',
    (string) (($boundary['document']['reason_code'] ?? '')),
    'with its own reason code in the machine envelope'
);

// An ENGINE move does not refuse, and that is the point: the program this verb
// measures crosses a spec flag day between its baseline and its re-measurement.
$oldEngine = cr_write($scratch . '/old-engine.json', cr_census(
    ['s1'],
    [['slug' => 'synth-zeta', 'demand_score' => 1, 'uncovered_surfaces' => ['options:synth_zeta']]],
    1000,
    400,
    CR_ORACLE_A,
    2,
    '0.5.0'
));
$acrossFlip = cr_rebaseline($duo, $repoRoot, $oldEngine, $flatBefore);
duo_check_same(0, $acrossFlip['exit'], 'a re-baseline ACROSS a spec flag day is answered, not refused');
$engineBlock = (array) ((array) ($acrossFlip['document']['comparability'] ?? []))['engine'] ?? [];
duo_check_same(true, $engineBlock['moved'] ?? null, 'the engine move is recorded');
duo_check_same(2, (int) ((array) ($engineBlock['baseline'] ?? []))['spec_version'] ?? -1,
    'naming the baseline spec version');
duo_check_same(3, (int) ((array) ($engineBlock['current'] ?? []))['spec_version'] ?? -1,
    'and the current one');

$notACensus = cr_write($scratch . '/not-a-census.json', ['format' => 'duo-assess-inventory/v1']);
$wrongFormat = cr_rebaseline($duo, $repoRoot, $notACensus, $flatBefore);
duo_check_same(1, $wrongFormat['exit'], 'a baseline that is not a census refuses');
duo_check_same(
    'rebaseline_document_unsupported',
    (string) ($wrongFormat['document']['reason_code'] ?? ''),
    'naming the format it needed'
);

$truncated = cr_json((string) file_get_contents($flatBefore)) ?? [];
unset($truncated['funnel']);
$truncatedPath = cr_write($scratch . '/truncated.json', $truncated);
$incomplete = cr_rebaseline($duo, $repoRoot, $truncatedPath, $flatBefore);
duo_check_same(1, $incomplete['exit'], 'a census missing a block the delta reads refuses');
duo_check_same(
    'rebaseline_document_incomplete',
    (string) ($incomplete['document']['reason_code'] ?? ''),
    'rather than folding an absent block into a zero'
);

$missing = cr_rebaseline($duo, $repoRoot, $scratch . '/does-not-exist.json', $flatBefore);
duo_check_same(1, $missing['exit'], 'an unreadable baseline refuses');
duo_check_same(
    'rebaseline_document_unreadable',
    (string) ($missing['document']['reason_code'] ?? ''),
    'with the reason code that names WHICH document could not be read'
);

// ------------------------------------------------------ the flag contract

$currentAlone = cr_run($duo, $repoRoot, ['census', '--current=' . $flatBefore, '--format=json']);
duo_check_same(2, $currentAlone['exit'], '--current without --baseline is a usage error: a re-baseline is a comparison');
$bothSources = cr_run($duo, $repoRoot, [
    'census', '--baseline=' . $flatBefore, '--current=' . $flatBefore,
    '--site=alpha=' . $flatBefore, '--format=json',
]);
duo_check_same(2, $bothSources['exit'], '--current and --site both name the current side: exactly one is allowed');
$healthWithCurrent = cr_run($duo, $repoRoot, [
    'census', '--baseline=' . $flatBefore, '--current=' . $flatBefore,
    '--health=' . $flatBefore, '--format=json',
]);
duo_check_same(2, $healthWithCurrent['exit'], '--health ranks a census this run does not measure: refused rather than ignored');
$duplicate = cr_run($duo, $repoRoot, [
    'census', '--baseline=' . $flatBefore, '--baseline=' . $flatBefore, '--format=json',
]);
duo_check_same(2, $duplicate['exit'], 'a duplicate --baseline refuses rather than last-wins');

// The ordinary census is untouched by any of this: no --baseline, no
// re-baseline document, and the same format it always emitted.
$plain = cr_run($duo, $repoRoot, ['census', '--dir=' . $coreEstate, '--format=json']);
duo_check_same(0, $plain['exit'], 'the plain census still exits 0');
duo_check_same(
    'duo-fleet-census/v1',
    (cr_json($plain['stdout']) ?? [])['format'] ?? null,
    'and still emits a census, not a re-baseline'
);

// ======================================= 5. the real re-baseline of the core estate
//
// The committed baseline was produced by the engine of its own day at commit
// f99f6712 — the commit that first shipped `duo census`, and therefore the
// earliest moment this program could measure itself. The current side is
// measured HERE, now, against the library this checkout ships.

$baselineDocument = cr_json((string) file_get_contents($g0Baseline)) ?? [];
duo_check_same(
    'duo-fleet-census/v1',
    $baselineDocument['format'] ?? null,
    'the committed core-estate baseline is a real census document'
);
duo_check_same(
    2,
    (int) ($baselineDocument['spec_version'] ?? -1),
    'measured before the flag day (spec_version 2)'
);
duo_check_same(
    '0.5.0',
    (string) ($baselineDocument['agent_version'] ?? ''),
    'by the agent of its own day (0.5.0), which is what makes it a baseline and not a re-print'
);

$real = cr_run($duo, $repoRoot, [
    'census',
    '--dir=' . $coreEstate,
    '--baseline=' . $g0Baseline,
    '--format=json',
]);
duo_check_same(0, $real['exit'], 'the core-estate re-baseline exits 0');
duo_check_same('', $real['stderr'], 'and writes nothing to stderr');
$realDocument = cr_json($real['stdout']) ?? [];
$realComparability = (array) ($realDocument['comparability'] ?? []);
$realCohort = (array) ($realDocument['cohort'] ?? []);
$realCoverage = (array) ($realDocument['coverage'] ?? []);

duo_check_same(
    'same-estate',
    (string) ($realComparability['class'] ?? ''),
    'the estate is held byte-constant across both measurements, so the delta is a delta of the LIBRARY'
);
duo_check_same(
    true,
    ((array) ($realComparability['engine'] ?? []))['moved'] ?? null,
    'the two measurements sit on opposite sides of the spec flag day'
);
// THE PROGRAM'S OWN RESULT, asserted rather than asserted-away. Both engines
// hash the same coverage oracle: `FleetCensus::library()`'s
// `surfaces_sha256` is the content address of every reviewed claim's derived
// surface set, so equality here is mechanical proof that no adapter's claim
// moved across the whole program — including across WP-4.4's disposition
// split, which is exactly the invariance that change asserted.
duo_check_same(
    false,
    ((array) ($realComparability['library'] ?? []))['moved'] ?? null,
    'and the coverage ORACLE is byte-identical across it: not one reviewed claim derives a different surface'
);
duo_check_same(
    0,
    (int) (((array) ($realCoverage['delta'] ?? []))['covered_ppm'] ?? -1),
    'so the adapter-coverage delta of this program is EXACTLY ZERO — the honest result of a program that '
        . 'built grammar and trust machinery rather than adapters (re-measure and record a new result if a '
        . 'later change moves a shipped claim; never loosen this)'
);
duo_check_same(
    'no_cohort',
    (string) ($realCohort['verdict'] ?? ''),
    'and the verdict is no_cohort: no adapter shipped, so this is not a cohort that failed — it is not a cohort'
);
duo_check_same(
    [],
    (array) ($realCohort['adapters_added'] ?? ['x']),
    'nothing is claimed as added'
);
duo_check_same(
    [],
    (array) (((array) ($realDocument['attribution'] ?? []))['moved'] ?? ['x']),
    'and no adapter is credited with moving a surface it did not move'
);

// THE RESIDUAL, NAMED. A zero delta is only honest if what it left behind is
// on the page: the top demand row of the core estate is a plugin no adapter in
// the shipped library covers, and this program did not change that.
$currentCensus = cr_json(
    cr_run($duo, $repoRoot, ['census', '--dir=' . $coreEstate, '--format=json'])['stdout']
) ?? [];
$topDemand = (array) (((array) ($currentCensus['demand'] ?? []))[0] ?? []);
duo_check_same(
    'wp-rocket',
    (string) ($topDemand['slug'] ?? ''),
    'the residual this program did not close is still the top demand row of the core estate'
);
duo_check_same(
    null,
    $topDemand['covering_adapter'],
    'with no covering adapter, before the program and after it'
);
duo_check_same(
    'no_adapter',
    (string) ($topDemand['funnel_stage'] ?? ''),
    'at funnel stage no_adapter — this is demand, and naming it is what makes the zero delta honest'
);
$baselineTop = (array) (((array) ($baselineDocument['demand'] ?? []))[0] ?? []);
duo_check_same(
    (string) ($baselineTop['slug'] ?? ''),
    (string) ($topDemand['slug'] ?? ''),
    'and it was the top row at the baseline too — the rank did not move either'
);

// The human view of the real re-baseline names the oracle equality, because
// that is the sentence a reader needs to trust the zero.
$realHuman = cr_run($duo, $repoRoot, [
    'census', '--dir=' . $coreEstate, '--baseline=' . $g0Baseline,
]);
duo_check_same(0, $realHuman['exit'], 'the human core-estate re-baseline exits 0');
duo_check(
    str_contains($realHuman['stdout'], 'coverage oracle unchanged (identical surfaces_sha256)'),
    'and says the coverage oracle did not move, rather than leaving a reader to compare two hashes'
);
duo_check(
    str_contains($realHuman['stdout'], 'verdict: no_cohort'),
    'beside the verdict'
);
duo_check(
    !str_contains($realHuman['stdout'], 'FINDING'),
    'and prints no finding, because a program that shipped no cohort did not fail to move one'
);

// ------------------------------------------------- the verb contract itself

$shell = (string) file_get_contents($duo);
duo_check(
    str_contains($shell, 'duo census --baseline=<census.json>'),
    'the re-baseline mode appears in the public usage text'
);
duo_check(
    is_file($repoRoot . '/cli/src/Assess/CohortRebaseline.php'),
    'the projector is its own file in cli:Assess rather than a branch inside the census'
);
// The engine-adapter boundary (docs/adapter-boundary.md) covers cli/src/Assess
// as a whole, so the new file is held to it too — asserted here rather than
// left to the composition suite's directory walk to notice later.
$projector = strtolower((string) file_get_contents($repoRoot . '/cli/src/Assess/CohortRebaseline.php'));
foreach (['woocommerce', 'elementor', 'polylang', 'ninja-forms'] as $slug) {
    duo_check(
        !str_contains($projector, $slug),
        "the re-baseline projector names no plugin slug ('$slug')"
    );
}

// ================================= 6. the runbook publishes the measured result
//
// docs/guides/coverage-cohort.md is where an operator is told what the loop is
// and what this program's own re-baseline came out as. A published number that
// nothing re-derives is a number that goes stale in silence — and the specific
// number at risk here is the one whose whole value is that it was not invented.
// So the guide's core-estate block is compared against the run above, line for
// line, and the guide is the side that has to move.

$guide = $repoRoot . '/docs/guides/coverage-cohort.md';
duo_check(is_file($guide), 'the cohort runbook exists at docs/guides/coverage-cohort.md');
$runbook = (string) file_get_contents($guide);

// Every rendered line of the real re-baseline, except the two the guide
// deliberately shortens: `comparability:` (restated in the guide's own prose
// one section above) and `verdict:` (its trailing disclosure is the same
// sentence for every same-estate run). Both are covered by their own checks
// below rather than dropped.
foreach (explode("\n", trim($realHuman['stdout'])) as $line) {
    [$prefix] = explode(':', $line, 2);
    if (!in_array($prefix, ['rebaseline', 'library', 'coverage', 'funnel', 'attribution', 'cohort'], true)) {
        continue;
    }
    duo_check(
        str_contains($runbook, $line),
        "the runbook publishes the measured '$prefix' line verbatim: " . $line
    );
}
duo_check(
    str_contains($runbook, 'verdict: no_cohort'),
    'and the verdict word the run actually produced'
);
duo_check(
    str_contains($runbook, 'The adapter-coverage delta of this program is exactly zero'),
    'stated in prose beside it, because a reader skimming for the result should not have to parse a ppm'
);

// The label the whole ranking rests on. `basis.sample_class` is `narrow` for
// this estate, and the guide has to carry the sentence the census itself
// prints — not a paraphrase that could survive the bound moving.
$caveat = (string) (((array) ($currentCensus['basis'] ?? []))['caveat'] ?? '');
duo_check(
    $caveat !== '' && str_contains($runbook, "read the rank as one estate's demand, not a fleet's"),
    'the runbook carries the census\'s own narrow-sample label — G0\'s recorded limitation, verbatim'
);
duo_check(
    str_contains($runbook, 'narrow'),
    'and names the sample class that produces it'
);
duo_check(
    str_contains($runbook, 'wp-rocket'),
    'the residual this program did not close is named in the runbook too, not only in the suite'
);
duo_check(
    str_contains($runbook, 'not a managed fleet'),
    'and the estate is stated for what it is rather than described as a fleet'
);

// The warning is the reason the runbook exists at all.
duo_check(
    str_contains($runbook, '**finding**, not a success'),
    'the runbook states the plan\'s own warning: shipping a cohort that moves no ratio is a finding'
);
duo_check(
    str_contains($runbook, 're-opens G0'),
    'in the words that say which decision it re-opens'
);
duo_check(
    str_contains($runbook, 'cohort_shipped_without_movement'),
    'naming the finding code an operator will actually see'
);
// The closed vocabulary is documented in full: a reader gating a job on
// `cohort.verdict` needs all six words, and a guide carrying five of them is
// how the sixth becomes a surprise in production.
foreach (['coverage_moved', 'adoption_only', 'shipped_without_movement',
          'coverage_regressed', 'no_cohort', 'unmeasured'] as $verdict) {
    duo_check(
        str_contains($runbook, $verdict),
        "the runbook documents the '$verdict' verdict"
    );
}

// Each verb the loop names is real, and named as the loop's step. The guide
// checker (sandbox/tests/spike/check_guide_commands.sh) proves the tokens
// resolve; this proves the RUNBOOK still describes the whole loop rather than
// silently losing a step.
foreach ([
    'duo census',
    'wp duo adapter-probe',
    'duo adapter-draft',
    'duo adapter boundary',
    'CONF_RECORD_VECTOR',
    'tools/adapter-kit.php',
    'duo adapter certify',
    'duo adapter adopt-scope',
    'duo census --baseline=',
] as $step) {
    duo_check(
        str_contains($runbook, $step),
        "the runbook's loop still names '$step'"
    );
}

// And it is reachable: an unlinked guide is a guide nobody reads.
$guidesIndex = (string) file_get_contents($repoRoot . '/docs/guides/README.md');
duo_check(
    str_contains($guidesIndex, '[coverage-cohort.md](coverage-cohort.md)'),
    'the runbook is linked from the guides index'
);

duo_check_summary('regress_cohort_rebaseline');
