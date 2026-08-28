#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Validate or render the COMPUTED evidence grade that sits beside the reviewed
 * certification word (spec/repo-format.md § v3.18).
 *
 * Usage:
 *   php tools/adapter-grade.php render     # write the aggregate projection to stdout
 *   php tools/adapter-grade.php --check    # validate all package-owned evidence inputs
 *   php tools/adapter-grade.php            # same as --check
 *
 * WHAT PROBLEM THIS SOLVES
 * ------------------------
 * `adapter-packages/<name>/package/disposition.json` carries a three-value status
 * (`certified`, `experimental`, `excluded`) and every read surface projects it
 * BINARY: `AdapterRegistry::report():427-429` raises
 * `authored_state_not_certified` for anything that is not the exact string
 * `certified`, and `AdapterSources::claim():4231-4241` writes `uncertified`
 * over a signed claim the repository pin does not bind exactly — one word for
 * every distance from the one accepted answer. So an
 * operator choosing between two third-party adapters for the same plugin reads
 * one word for both — and the only way to say "this one carries far more
 * evidence" is to widen what `certified` means, which is the one thing that
 * must never happen.
 *
 * Three machine-readable evidence records already exist and project into
 * nothing:
 *
 *   coverage breadth  adapter-packages/<slug>/evidence/production-readiness.json
 *                     (core: platform/adapter-evidence/production-readiness.json) —
 *                     which of the 12 reviewed scenario families carry files
 *   exercise depth    the certification bundle's per-test pass map, reaching a
 *                     claim as `provenance.proof.bundle.exercised` +
 *                     `.tests` (AdapterCertification::verifyBundleManifest()
 *                     proves every cited test is a named PASSING bundle test,
 *                     and derivedDisposition() carries the map into
 *                     `provenance.proof.bundle`)
 *   platform reach    platform/adapter-library/capabilities/platform.json's per-axis
 *                     `verified` series, against the cells the claim states
 *                     after § v3.5 narrowing — the same cells a certificate
 *                     binds as exercised since § v3.6/WP-4.7
 *
 * This file is the one definition of what those three add up to, and can
 * project that definition into prose on demand. It is
 * tools/capability-doc.php's discipline (:11-58) and
 * tools/engine-gap-doc.php's shape, deliberately, because the repo has one
 * generated-document pattern rather than three.
 *
 * FOUR PROPERTIES, AND WHY EACH IS LOAD-BEARING
 * ---------------------------------------------
 *   1. COMPUTED, NEVER AUTHORED. Nothing here reads a stored verdict: every
 *      grade in the rendered document is derived from the evidence documents
 *      on THIS run, and grade_assert_underived() refuses any input carrying an
 *      authored `grade` member by name. A graded claim that could be written
 *      down would be a second, weaker spelling of the disposition status — a
 *      marketing number with a review's authority.
 *   2. IT SITS BESIDE THE WORD, NEVER OVER IT. The rendered table prints the
 *      reviewed status verbatim in its own column and this file touches no
 *      shipped byte: docs/capabilities.md, the dispositions, the claim
 *      projection and every refusal message are exactly where they were, so
 *      `certified` means today what it meant before this document existed.
 *   3. NO EVIDENCE, NO GRADE. `grade_combine()` returns null unless at least
 *      one EXERCISE axis (breadth or depth) is present. Platform reach alone
 *      cannot mint a grade: an adapter that has been exercised by nobody still
 *      "states" the whole reviewed boundary through
 *      `narrowed_environment()`'s default, and grading that would hand a fresh
 *      unreviewed adapter a number for having declared nothing.
 *   4. SILENT IS NOT ZERO. An axis with no evidence DOCUMENT for this subject
 *      is `silent` and drops out of the arithmetic; an axis whose document
 *      records zero exercised units is `none` and drags the grade down. This
 *      is AdapterSources::certification_evidence()'s own distinction ("`[]` is
 *      'this bundle exercised no named artifact', `null` is 'the list could
 *      not be read'"), applied one level up: collapsing them would score every
 *      shipped manifest `none` merely because a shipped manifest has no
 *      certification bundle, and score an unexercised certificate the same as
 *      a whole library.
 *
 * WHY THE MODEL LIVES IN tools/ AND NOT IN agent/src
 * --------------------------------------------------
 * Two of the three inputs are not shipped — `cli/src/Onboarding/Adopt.php`
 * embeds the projected adapter library inside `agent/`, so the readiness ledger under
 * sandbox/ reaches no site — and the grade has exactly one reader today: `make
 * release-gate` running this file. That is the same argument
 * tools/capability-doc.php makes for keeping its directory-wide coverage check
 * here ("an authoring property with exactly one reader"), and the same place
 * tools/wire-surface.php keeps the irreversibility register: a decision model
 * about shipped artifacts, projected and gated, shipping nothing. A runtime
 * reader is a later, separate decision with its own output-byte cost; putting
 * the class in agent/src first would ship a definition nothing on a site calls.
 *
 * WHAT IS NOT RE-DERIVED HERE
 * ---------------------------
 * The § v3.5 narrowing rule. `ManifestDispositions::narrowed_environment()` is
 * required and called, not reimplemented, for exactly the reason WP-4.7 gave
 * when the certificate signer started calling it: a second copy of "which
 * cells does this claim state" drifts silently from the one the agent
 * enforces, and the reach axis would then grade a claim the agent does not
 * make. Whether each family/test/cell is real is likewise somebody else's
 * assertion — sandbox/tests/offline/adapter/regress_adapter_production_
 * readiness.php proves every cited evidence file exists and every family is
 * accounted for exactly once, and the certification path proves every cited
 * test passed. This file counts what those have already proved.
 */

use Duo\AdapterLibrary;
use Duo\ManifestDispositions;
use Duo\Tooling\AdapterProductionReadiness;

$repo = dirname(__DIR__);

// ManifestDispositions for narrowed_environment() — the one rule this
// projection must not own a second copy of. Canon.php comes with it and is
// not optional: ManifestDispositions calls Canon:: at :294, :465 and :1048
// and deliberately requires nothing itself (":76 — `require` here would grow
// the load graph of every context that only …"), so the caller carries its
// dependency, exactly as agent/duo.php does. Nothing else of the agent loads.
require_once $repo . '/agent/src/Kernel/Canon.php';
require_once $repo . '/agent/src/Policy/AdapterLibrary.php';
require_once $repo . '/agent/src/Policy/ManifestDispositions.php';
require_once $repo . '/tools/src/AdapterProductionReadiness.php';

const GRADE_LEDGER_FORMAT = 'duo-adapter-production-readiness/v1';
const GRADE_PLATFORM_FORMAT = 'duo-platform-boundary/v1';

/** The three axes, in the order every projection prints them. */
const GRADE_AXES = ['coverage_breadth', 'exercise_depth', 'platform_reach'];

/**
 * The two axes that say something was EXERCISED. Platform reach says how far
 * across the reviewed boundary a claim extends, which is a property of the
 * claim rather than of anyone's work, so it qualifies a grade and can never
 * mint one (property 3 above).
 */
const GRADE_EXERCISE_AXES = ['coverage_breadth', 'exercise_depth'];

/** Weakest first: the grade is its weakest present axis, so this order IS the combination rule. */
const GRADE_STATES = ['none', 'partial', 'complete'];

/** The member an evidence document may never carry: a grade is derived on every call or it is nothing. */
const GRADE_AUTHORED_KEY = 'grade';

function grade_fail(string $message): never {
    fwrite(STDERR, "adapter grade: $message\n");
    exit(1);
}

/** @return array<string,mixed> */
function grade_read_json(string $path): array {
    if (!is_file($path)) {
        throw new RuntimeException('missing JSON file: ' . $path);
    }
    $decoded = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($decoded) || array_is_list($decoded)) {
        throw new RuntimeException('JSON root must be an object: ' . $path);
    }
    return $decoded;
}

function grade_library(string $repo): AdapterLibrary {
    return AdapterLibrary::fromSourceTree($repo);
}

/**
 * Refuse an authored grade anywhere in the inputs.
 *
 * The rule this enforces is the whole difference between a computed axis and a
 * second status enum: a `grade` member somebody could write into a disposition
 * would be read by the next reader that wanted one, and from that moment the
 * number would be an assertion wearing a derivation's clothes. Refusing by
 * NAME (rather than ignoring the member) is what makes the property visible to
 * whoever tried.
 *
 * @param array<string,mixed> $document
 */
function grade_assert_underived(array $document, string $where): void {
    if (array_key_exists(GRADE_AUTHORED_KEY, $document)) {
        throw new RuntimeException(
            "an evidence grade is derived from evidence on every call and may not be authored; $where carries an "
            . 'authored `' . GRADE_AUTHORED_KEY . '` member'
        );
    }
}

/**
 * One axis record: the units that carry evidence, the units that do not, the
 * units this axis structurally does not count, and the sentence naming exactly
 * where all three came from.
 *
 * `state` is derived here and nowhere else, from the two counts alone:
 * `complete` is "every unit this axis counts is exercised" — a statement about
 * COVERAGE OF THE COUNTED SET, never about quality, and never a synonym for a
 * reviewed status.
 *
 * @param list<string> $exercised
 * @param list<string> $outstanding
 * @param list<string> $excluded
 * @return array{axis:string,state:string,exercised:list<string>,outstanding:list<string>,excluded:list<string>,basis:string}
 */
function grade_axis(string $axis, array $exercised, array $outstanding, array $excluded, string $basis): array {
    if (!in_array($axis, GRADE_AXES, true)) {
        throw new RuntimeException("unknown grade axis '$axis'");
    }
    sort($exercised, SORT_STRING);
    sort($outstanding, SORT_STRING);
    sort($excluded, SORT_STRING);

    return [
        'axis' => $axis,
        'state' => $exercised === [] ? 'none' : ($outstanding === [] ? 'complete' : 'partial'),
        'exercised' => array_values($exercised),
        'outstanding' => array_values($outstanding),
        'excluded' => array_values($excluded),
        'basis' => $basis,
    ];
}

/**
 * COVERAGE BREADTH — which of the reviewed scenario families carry evidence.
 *
 * The taxonomy is read out of the ledger's own `scenario_families` rather than
 * restated here: the 12 families are that document's vocabulary, and a second
 * copy would let this projection keep counting a family the reviewers renamed.
 *
 * `not_applicable` is EXCLUDED from both counts rather than scored as a gap.
 * docs/agents/adapter-production-readiness.md admits that bucket only with a
 * concrete structural reason ("a missing primitive is `blocked`, not
 * `not_applicable`"), so a family in it is one this adapter cannot have — and
 * penalising an adapter for a family its surface does not contain would make
 * the axis measure adapter shape rather than evidence.
 *
 * @param ?array<string,mixed> $ledger the decoded readiness ledger, or null when none is readable
 * @return ?array{axis:string,state:string,exercised:list<string>,outstanding:list<string>,excluded:list<string>,basis:string}
 */
function grade_coverage_breadth(?array $ledger, string $name): ?array {
    if ($ledger === null) {
        return null;
    }
    $entry = $ledger['adapters'][$name] ?? null;
    if (!is_array($entry) || array_is_list($entry)) {
        // SILENT, not zero: the ledger reviews no row for this subject, which
        // is a different fact from a row that reviews it and finds nothing.
        return null;
    }
    grade_assert_underived($entry, "the readiness ledger row for '$name'");
    $families = $ledger['scenario_families'] ?? null;
    if (!is_array($families) || !array_is_list($families) || $families === []) {
        throw new RuntimeException('the readiness ledger declares no scenario family taxonomy');
    }
    $families = array_map('strval', $families);

    $buckets = [];
    foreach (['covered', 'gaps', 'blocked', 'not_applicable'] as $bucket) {
        $value = $entry[$bucket] ?? null;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new RuntimeException("readiness row '$name'.$bucket must be an object keyed by scenario family");
        }
        foreach (array_keys($value) as $family) {
            if (!in_array((string) $family, $families, true)) {
                throw new RuntimeException(
                    "readiness row '$name'.$bucket names '$family', which the ledger's own taxonomy does not declare"
                );
            }
        }
        $buckets[$bucket] = $value;
    }

    $exercised = [];
    $outstanding = [];
    $excluded = [];
    foreach ($families as $family) {
        $covered = $buckets['covered'][$family] ?? null;
        if (is_array($covered) && $covered !== []) {
            $exercised[] = $family;
        } elseif (array_key_exists($family, $buckets['not_applicable'])) {
            $excluded[] = $family;
        } elseif (array_key_exists($family, $buckets['gaps']) || array_key_exists($family, $buckets['blocked'])) {
            $outstanding[] = $family;
        } else {
            // The ledger's own guard already refuses an unaccounted family
            // (regress_adapter_production_readiness.php). Refusing again here
            // rather than silently dropping it is the difference between a
            // count that is wrong and a count that says so.
            throw new RuntimeException("readiness row '$name' accounts for no bucket for family '$family'");
        }
    }

    return grade_axis(
        'coverage_breadth',
        $exercised,
        $outstanding,
        $excluded,
        ($name === 'core'
            ? 'platform/adapter-evidence/production-readiness.json'
            : 'adapter-packages/' . $name . '/evidence/production-readiness.json')
        . ': the reviewed scenario families '
        . 'whose `covered` bucket names at least one evidence file, against the ledger\'s own taxonomy minus the '
        . 'families reviewed `not_applicable`'
    );
}

/**
 * EXERCISE DEPTH — the certification bundle's per-test pass map.
 *
 * The unit is a NAMED TEST the reviewed claim cites and the bundle records
 * `verdict: pass` for. Both halves are already proved before this counts them:
 * `verifyBundleManifest()` builds `$tests[$id] = true` only after refusing any
 * entry that is not a named passing test exactly once
 * (AdapterCertification.php:4392-4393), and `verifyRatification()` refuses a
 * disposition citing a test the bundle does not carry as passing. So
 * `complete` here means precisely "every test this claim cites was recorded
 * passing", and the discriminating information is the COUNT — two cited tests
 * and forty are two different claims that the word `certified` cannot tell
 * apart.
 *
 * THE INPUT IS `provenance.proof.bundle`, and naming the exact path matters
 * because two nearby objects both spell `tests`. `derivedDisposition()`
 * assembles that one (AdapterCertification.php:4894-4901) out of the verified
 * bundle: `exercised` is the bundle's own bool and `tests` the ratified
 * citation the bundle proved. The OTHER one — a hand-authored disposition's
 * `evidence.tests` — is a reviewer naming conformance suites, with no per-test
 * verdict record behind it anywhere. Reading that as depth would score a
 * reviewed citation as if a bundle had proved it, which is exactly the
 * conflation the three axes exist to prevent, so a subject with no
 * `provenance.proof.bundle` is SILENT — a different fact from a bundle that
 * recorded `exercised: false`.
 *
 * @param ?array<string,mixed> $bundleProof a claim's `provenance.proof.bundle`, or null when it has none
 * @return ?array{axis:string,state:string,exercised:list<string>,outstanding:list<string>,excluded:list<string>,basis:string}
 */
function grade_exercise_depth(?array $bundleProof, string $name): ?array {
    if ($bundleProof === null) {
        return null;
    }
    grade_assert_underived($bundleProof, "the bundle proof for '$name'");
    if (!array_key_exists('exercised', $bundleProof)) {
        return null;
    }
    if (!is_bool($bundleProof['exercised'])) {
        throw new RuntimeException("the bundle proof for '$name' must carry `exercised` as a bool");
    }
    $tests = $bundleProof['tests'] ?? [];
    if (!is_array($tests) || ($tests !== [] && !array_is_list($tests))) {
        throw new RuntimeException("the bundle proof for '$name' must carry `tests` as a list of test names");
    }
    $exercised = $bundleProof['exercised'] ? array_map('strval', $tests) : [];
    $unproved = $bundleProof['exercised'] ? [] : array_map('strval', $tests);

    return grade_axis(
        'exercise_depth',
        $exercised,
        $unproved,
        [],
        'the certification bundle\'s per-test pass map, reaching a claim as `provenance.proof.bundle.exercised` '
        . '+ `provenance.proof.bundle.tests` (AdapterCertification::verifyBundleManifest() admits only named '
        . 'passing tests; verifyRatification() refuses a claim citing one the bundle does not carry as passing)'
    );
}

/**
 * PLATFORM REACH — how much of the reviewed boundary this claim reaches,
 * counted in cells that carry a per-cell exercise witness.
 *
 * The unit is one cell of one compatibility axis that publishes a `verified`
 * series, because that series IS the machine-readable witness: each key is a
 * feature-release line and its value the exact patch a full live proof ran on
 * (platform/adapter-library/capabilities/platform.json's own notes). An axis without one is
 * EXCLUDED and named, never counted as a gap — the database axis says in the
 * document itself that it deliberately publishes no series because "each entry
 * already names exactly one measured line", and scoring that as unwitnessed
 * would be this projection contradicting the boundary it is reading.
 *
 * `$stated` is the cells the CLAIM states — `narrowed_environment()`'s output,
 * which is the whole boundary for an adapter that declares nothing and the
 * declared subset for one that narrows under § v3.5. For a certificate it is
 * the same set the signature binds as exercised (§ v3.6). A narrowing adapter
 * therefore reaches fewer cells, which is the true statement: reach is extent,
 * not a promise-keeping ratio.
 *
 * @param ?array<string,mixed> $stated the claim's environment assumptions, or null
 * @param array<string,mixed> $boundary the reviewed platform record
 * @return ?array{axis:string,state:string,exercised:list<string>,outstanding:list<string>,excluded:list<string>,basis:string}
 */
function grade_platform_reach(?array $stated, array $boundary): ?array {
    if ($stated === null) {
        return null;
    }
    grade_assert_underived($stated, 'the stated environment');
    $compatibility = $boundary['compatibility'] ?? null;
    if (!is_array($compatibility) || array_is_list($compatibility) || $compatibility === []) {
        throw new RuntimeException('the platform boundary declares no compatibility axis to reach');
    }
    ksort($compatibility, SORT_STRING);

    $exercised = [];
    $outstanding = [];
    $excluded = [];
    foreach ($compatibility as $axis => $record) {
        $axis = (string) $axis;
        $series = is_array($record) ? ($record['verified'] ?? null) : null;
        if (!is_array($series) || array_is_list($series) || $series === []) {
            $excluded[] = $axis;
            continue;
        }
        if (!array_key_exists($axis, $stated)) {
            // REFUSE RATHER THAN ABSORB. A capability claim states exactly the
            // four members `narrowed_environment()` builds (site_mode, php,
            // database, wordpress — ManifestDispositions.php:676-681, and
            // ENVIRONMENT_AXES:121 is the same closed list). An axis that
            // publishes a per-cell exercise witness the claim cannot state
            // would otherwise count every one of its cells as outstanding for
            // EVERY adapter at once — a fleet-wide grade drop caused by a
            // platform.json edit, reported as if the adapters had lost
            // evidence. The remedy is a decision (widen what a claim states,
            // or leave the axis witnessed by its note the way `database` is),
            // and a decision belongs in front of a reader.
            throw new RuntimeException(
                "the platform boundary axis '$axis' publishes a `verified` series that a capability claim cannot "
                . 'state — reach counts only cells a claim can name, so widen what a claim states or leave this '
                . 'axis witnessed by its note'
            );
        }
        $statedSeries = is_array($stated[$axis]['verified'] ?? null) ? $stated[$axis]['verified'] : [];
        foreach (array_keys($series) as $cell) {
            $unit = $axis . ':' . $cell;
            if (array_key_exists((string) $cell, $statedSeries)) {
                $exercised[] = $unit;
            } else {
                $outstanding[] = $unit;
            }
        }
    }

    return grade_axis(
        'platform_reach',
        $exercised,
        $outstanding,
        $excluded,
        'platform/adapter-library/capabilities/platform.json → compatibility.*.verified: the exercised-series cells this claim '
        . 'states after § v3.5 narrowing (ManifestDispositions::narrowed_environment(), the same projection a '
        . 'certificate binds as its exercised cells under § v3.6); an axis publishing no `verified` series carries '
        . 'no per-cell witness and is excluded rather than counted against the claim'
    );
}

/**
 * Combine the axes into the one comparable record, or refuse to mint a grade.
 *
 * The grade is its WEAKEST present axis. Averaging would let a wide platform
 * claim compensate for missing scenario evidence, which is the exact
 * arithmetic that turns an evidence summary into a marketing number; the
 * weakest axis is the sentence an operator actually needs ("this is as far as
 * the evidence goes").
 *
 * THERE IS NO `format` MEMBER, deliberately. A `duo-adapter-grade/vN` string
 * would name a wire generation that does not exist: this record is never
 * serialised, never signed and never written to disk — the only thing that
 * leaves this process is the rendered prose. `platform/adapter-library/capabilities/platform.json`'s own note
 * states the rule being followed ("an invariant with
 * no reader is decoration"), and a format identifier with no document to
 * identify is the same defect with a heavier cost, since the next reader would
 * reasonably take it for a contract.
 *
 * @param array<string,?array<string,mixed>> $axes axis name => axis record or null (silent)
 * @return ?array{state:string,axes:array<string,array<string,mixed>>,silent:list<string>,exercised:int,declared:int,axes_present:int}
 */
function grade_combine(array $axes): ?array {
    $present = [];
    $silent = [];
    foreach (GRADE_AXES as $axis) {
        $record = $axes[$axis] ?? null;
        if (is_array($record)) {
            $present[$axis] = $record;
        } else {
            $silent[] = $axis;
        }
    }
    $exerciseAxes = array_intersect(array_keys($present), GRADE_EXERCISE_AXES);
    if ($exerciseAxes === []) {
        // NO EVIDENCE, NO GRADE. Reach alone describes what a claim covers,
        // not what anyone did, and a subject nobody exercised must read as
        // ungraded rather than as a low grade — a number would still be a
        // number, and the point of this document is that a grade always stands
        // on evidence.
        return null;
    }

    $state = 'complete';
    $exercised = 0;
    $declared = 0;
    foreach ($present as $record) {
        $exercised += count($record['exercised']);
        $declared += count($record['exercised']) + count($record['outstanding']);
        if (array_search($record['state'], GRADE_STATES, true) < array_search($state, GRADE_STATES, true)) {
            $state = $record['state'];
        }
    }

    return [
        'state' => $state,
        'axes' => $present,
        'silent' => $silent,
        'exercised' => $exercised,
        'declared' => $declared,
        'axes_present' => count($present),
    ];
}

/**
 * The whole derivation for one subject, in one call — which is the only way a
 * grade is ever obtained. There is no cache, no memo and no stored verdict to
 * read back: move any input and the next call answers differently.
 *
 * @param ?array<string,mixed> $ledger
 * @param ?array<string,mixed> $evidence
 * @param ?array<string,mixed> $stated
 * @param array<string,mixed> $boundary
 * @return ?array<string,mixed>
 */
function grade_of(string $name, ?array $ledger, ?array $evidence, ?array $stated, array $boundary): ?array {
    return grade_combine([
        'coverage_breadth' => grade_coverage_breadth($ledger, $name),
        'exercise_depth' => grade_exercise_depth($evidence, $name),
        'platform_reach' => grade_platform_reach($stated, $boundary),
    ]);
}

/**
 * The one-line human spelling, defined once so the table cell and any other
 * reader cannot drift into two vocabularies for one number.
 *
 * @param ?array<string,mixed> $grade
 */
function grade_line(?array $grade): string {
    if ($grade === null) {
        return 'no grade — no exercise evidence';
    }
    return $grade['state'] . ' · ' . $grade['exercised'] . '/' . $grade['declared'] . ' units · '
        . $grade['axes_present'] . ' of ' . count(GRADE_AXES) . ' axes';
}

/** The per-axis cell for the detail table: counts plus the units, or the reason the axis is silent. */
function grade_axis_cell(?array $axis): string {
    if ($axis === null) {
        return '—';
    }
    $counted = count($axis['exercised']) + count($axis['outstanding']);

    return $axis['state'] . ' ' . count($axis['exercised']) . '/' . $counted;
}

function grade_units(array $units): string {
    return $units === [] ? 'none' : '`' . implode('`, `', $units) . '`';
}

/**
 * Every graded subject, in the order the document prints them.
 *
 * The subject set is AdapterLibrary's closed package inventory, so a manifest
 * cannot reach this document without its package-owned disposition and a
 * reviewed subject cannot be left out — the same exact-set property
 * tools/capability-doc.php holds, asked here of the grade rather than prose.
 *
 * @return list<array<string,mixed>>
 */
function grade_subjects(string $repo, ?AdapterLibrary $library = null): array {
    $library ??= grade_library($repo);
    $platformDocument = grade_read_json($library->platformBoundaryPath());
    if (($platformDocument['format'] ?? null) !== GRADE_PLATFORM_FORMAT
        || !is_array($platformDocument['platform'] ?? null)) {
        throw new RuntimeException('the platform boundary has an unsupported or malformed root');
    }
    $boundary = $platformDocument['platform'];
    grade_assert_underived($boundary, 'the platform boundary');

    $ledger = AdapterProductionReadiness::load($repo);
    if (($ledger['format'] ?? null) !== GRADE_LEDGER_FORMAT || !is_array($ledger['adapters'] ?? null)) {
        throw new RuntimeException('the readiness ledger has an unsupported or malformed root');
    }
    grade_assert_underived($ledger, 'the readiness ledger');

    $subjects = [];
    $reviewed = [];
    foreach ($library->packages() as $package) {
        $name = $package->name();
        $reviewed[$name] = true;
        $disposition = grade_read_json($package->dispositionPath());
        grade_assert_underived($disposition, "the disposition for '$name'");
        $manifest = grade_read_json($package->manifestPath());
        $subjects[] = [
            'name' => $name,
            'status' => (string) ($disposition['status'] ?? 'unsupported'),
            'tests' => is_array($disposition['evidence']['tests'] ?? null)
                ? array_map('strval', $disposition['evidence']['tests'])
                : [],
            'grade' => grade_of(
                $name,
                $ledger,
                // A shipped disposition is hand-authored and carries no
                // `provenance` at all — the reviewed CITATION under `evidence`
                // is a reviewer naming suites, not a per-test verdict record —
                // so the depth axis is silent for every row here by
                // construction (property 4). The member is minted by
                // derivedDisposition() on a site certificate's claim, which is
                // the population the axis was built for.
                is_array($disposition['provenance']['proof']['bundle'] ?? null)
                    ? $disposition['provenance']['proof']['bundle']
                    : null,
                ManifestDispositions::narrowed_environment($manifest, $boundary),
                $boundary
            ),
        ];
    }

    foreach (array_keys($ledger['adapters']) as $name) {
        if (!isset($reviewed[(string) $name])) {
            throw new RuntimeException(
                "the readiness ledger reviews '$name', which no disposition document names — the grade would "
                . 'describe a subject this library does not claim'
            );
        }
    }
    // By SUBJECT name, not by filename: sorting paths puts `yoast-duplicate-
    // post` before `yoast` (`-` sorts below `.`), which is a filesystem
    // artefact rather than an order a reader would expect.
    usort($subjects, static fn(array $a, array $b): int => strcmp((string) $a['name'], (string) $b['name']));

    return $subjects;
}

/** @param list<array<string,mixed>> $subjects */
function grade_render(array $subjects, string $repo, ?AdapterLibrary $library = null): string {
    $out = "# Adapter evidence grades\n\n";
    $out .= '<!-- Rendered on demand by tools/adapter-grade.php from adapter package readiness records + '
        . 'adapter-packages/*/package/disposition.json + platform/adapter-library/capabilities/platform.json; '
        . "this aggregate is not a checked-in adapter edit point. -->\n\n";
    $out .= '**A grade is computed; a status is reviewed. They are different claims and neither replaces the '
        . 'other.** [docs/capabilities.md](capabilities.md) carries the reviewed word — `certified`, '
        . '`experimental`, `excluded` — which means exactly what it meant before this document existed: declared '
        . 'by the manifest, reviewed in its sibling `package/disposition.json` by a human who wrote down why, and exercised '
        . 'by the named conformance suites. The grade beside it is arithmetic over evidence records that already '
        . 'existed, re-derived on every run of `tools/adapter-grade.php` and stored nowhere. Nothing here widens, '
        . 'narrows or qualifies a status, and no grade is an endorsement: a `complete` grade says every unit the '
        . "three axes count is exercised, and says nothing at all about how good the adapter is.\n\n";
    $out .= "## The three axes\n\n";
    $out .= "| Axis | Unit | Where it comes from |\n|---|---|---|\n";
    $out .= '| Coverage breadth | one reviewed scenario family | `adapter-packages/*/evidence/production-readiness.json` '
        . '(core: `platform/adapter-evidence/production-readiness.json`) '
        . "— the families whose `covered` bucket names evidence files, against that ledger's own 12-family "
        . "taxonomy minus the families reviewed `not_applicable` |\n";
    $out .= '| Exercise depth | one named test recorded passing | the certification bundle\'s per-test pass map, '
        . 'reaching a claim as `provenance.proof.bundle.exercised` + `.tests`; a hand-authored disposition cites '
        . "reviewed suites and carries no per-test verdict record, so this axis is silent for every row below |\n";
    $out .= '| Platform reach | one exercised-series cell | `platform/adapter-library/capabilities/platform.json` — the '
        . '`verified` cells the claim states after § v3.5 narrowing, which is the same set a certificate binds as '
        . "exercised under § v3.6; an axis publishing no series carries no per-cell witness and is excluded |\n\n";
    $out .= 'An axis with no evidence document for a subject is **silent** and leaves the arithmetic; an axis '
        . 'whose document records nothing exercised is **none** and drags the grade down. The grade is its '
        . 'weakest present axis — `none`, `partial` or `complete` — and a subject with no exercise axis at all '
        . '(neither breadth nor depth) carries **no grade**, because platform reach describes what a claim covers '
        . "rather than what anyone did.\n\n";

    $out .= "## Grades\n\n";
    $out .= "| Manifest | Reviewed status | Grade | Coverage breadth | Exercise depth | Platform reach |\n";
    $out .= "|---|---|---|---|---|---|\n";
    foreach ($subjects as $subject) {
        $grade = $subject['grade'];
        $axes = is_array($grade) ? $grade['axes'] : [];
        $out .= '| [' . $subject['name'] . '](#' . $subject['name'] . ') | ' . $subject['status'] . ' | '
            . grade_line($grade) . ' | '
            . grade_axis_cell($axes['coverage_breadth'] ?? null) . ' | '
            . grade_axis_cell($axes['exercise_depth'] ?? null) . ' | '
            . grade_axis_cell($axes['platform_reach'] ?? null) . " |\n";
    }
    $out .= "\n";

    foreach ($subjects as $subject) {
        $grade = $subject['grade'];
        $out .= '## ' . $subject['name'] . "\n\n";
        $out .= '**Reviewed status: ' . $subject['status'] . '. Grade: ' . grade_line($grade) . ".**\n\n";
        if ($grade === null) {
            $out .= 'No exercise axis carries evidence for this subject: the readiness ledger reviews no row for '
                . 'it and no certification bundle records a per-test verdict. A grade is therefore not minted — '
                . "an ungraded subject is the honest answer, and a low grade would not be.\n\n";
            continue;
        }
        foreach (GRADE_AXES as $axisName) {
            $axis = $grade['axes'][$axisName] ?? null;
            $label = ucfirst(str_replace('_', ' ', $axisName));
            if ($axis === null) {
                $out .= '- **' . $label . ':** silent — no evidence document of this kind exists for this '
                    . "subject, so the axis counts nothing rather than counting zero.\n";
                continue;
            }
            $counted = count($axis['exercised']) + count($axis['outstanding']);
            $out .= '- **' . $label . ':** ' . $axis['state'] . ', ' . count($axis['exercised']) . ' of '
                . $counted . '. Exercised: ' . grade_units($axis['exercised']) . '.';
            if ($axis['outstanding'] !== []) {
                $out .= ' Outstanding: ' . grade_units($axis['outstanding']) . '.';
            }
            if ($axis['excluded'] !== []) {
                $out .= ' Not counted: ' . grade_units($axis['excluded']) . '.';
            }
            $out .= ' Input: ' . $axis['basis'] . ".\n";
        }
        if ($subject['tests'] !== []) {
            $out .= '- **Reviewed citation:** ' . grade_units($subject['tests'])
                . ' — named by the disposition, not a per-test verdict record, which is why the depth axis is '
                . "silent rather than complete.\n";
        }
        $out .= "\n";
    }

    $out .= "## What this document cannot tell you\n\n";
    $out .= 'The grade counts units; it does not read them. A family is `covered` because a reviewer named '
        . 'evidence files and `sandbox/tests/offline/adapter/regress_adapter_production_readiness.php` proved '
        . 'those files exist — not because this document judged them. A cited test passed because the '
        . 'certification path refused every claim citing one that did not. Two adapters with the same grade can '
        . 'still be very different adapters, and the reviewed reason in '
        . "[docs/capabilities.md](capabilities.md) is where that difference is written down.\n\n";
    $out .= 'Generated from ' . count($subjects) . ' reviewed subjects against agent '
        . grade_agent_version($repo, $library) . ".\n";

    return $out;
}

/** The agent version the boundary restates, read from the boundary rather than re-derived (AGENTS.md rule 8). */
function grade_agent_version(string $repo, ?AdapterLibrary $library = null): string {
    $library ??= grade_library($repo);
    $platform = grade_read_json($library->platformBoundaryPath());

    return (string) ($platform['platform']['agent_version'] ?? '');
}

function grade_build(string $repo): string {
    $library = grade_library($repo);
    return grade_render(grade_subjects($repo, $library), $repo, $library);
}

function grade_run(string $repo, bool $render): void {
    $projection = grade_build($repo);
    if ($render) {
        fwrite(STDOUT, $projection);
        return;
    }
    fwrite(STDOUT, "adapter grade check: package-owned evidence and computed grade inputs agree\n");
}

function grade_main(string $repo, array $argv): void {
    try {
        $command = $argv[1] ?? '--check';
        if ($command === 'render') {
            grade_run($repo, true);
        } elseif ($command === '--check' || $command === 'check') {
            grade_run($repo, false);
        } else {
            throw new RuntimeException('usage: php tools/adapter-grade.php [render|--check]');
        }
    } catch (Throwable $e) {
        grade_fail($e->getMessage());
    }
}

// Only as a CLI entry point: requiring this file from a suite or a PHPUnit
// self-test must define the model without regenerating or exiting (the same
// guard shape tools/engine-gap-doc.php uses).
if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    $cliArgv = $_SERVER['argv'] ?? [];
    grade_main($repo, is_array($cliArgv) ? array_map('strval', $cliArgv) : []);
}
