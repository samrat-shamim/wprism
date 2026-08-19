<?php
/**
 * Offline invariant — every Makefile recipe that names a sandbox/tests path is
 * shaped, spelled and located the way the tooling that reads those recipes
 * assumes.
 *
 * WHY THIS EXISTS
 * ---------------
 * sandbox/tests/regress_bundle_coverage.sh answers one direction: does every
 * suite FILE have a Makefile entry. Nothing answered the other direction, and
 * three consumers depend on it:
 *
 *   1. tools/offline.php:250 extracts each leaf's scripts with the literal
 *      regex `#sandbox/tests/[A-Za-z0-9_./-]+\.(?:sh|php)#` against recipes it
 *      read from `make -p`, which prints them UNEXPANDED. A path written as
 *      `sandbox/tests/$(SUITE_DIR)/regress_x.php` therefore matches nothing at
 *      all -- the regex stops dead at `$` -- so that target reports zero
 *      scripts, and needsSerialGroup() (:280) then scans zero files for the
 *      absolute scratch-path literals under a fixed system temp directory that
 *      force a suite into the mutually exclusive serial group. (Spelling that
 *      path here would put this suite in the serial group itself: that scan
 *      reads comments too.) The suite silently becomes "parallel-safe" and
 *      two `-j8` workers corrupt each other's scratch file. That is a
 *      non-reproducible failure produced by a Makefile edit nothing else
 *      checks, which is why the no-variable rule is asserted here as a shape
 *      rule rather than left to review.
 *   2. tools/affected.php maps a target to its suite file by NAME
 *      (`regress-foo-bar` <-> `regress_foo_bar.{php,sh}`). A recipe whose
 *      target disagrees with its file's basename selects for nothing under
 *      `--changed`, announced only as a NOTICE on stderr.
 *   3. `make <target>` itself, which fails at run time -- not at review time --
 *      if the file the recipe names does not exist.
 *
 * WHY IT IS THE FIRST SUITE IN A SUBDIRECTORY
 * --------------------------------------------
 * Deliberate. The estate is moving from ~330 flat files into
 * sandbox/tests/{offline/<domain>,live,grind,certify,spike}/, and every
 * consumer above enumerated sandbox/tests non-recursively, which fails OPEN:
 * a nested suite was not seen, so it was not checked, and nothing went red.
 * This file living at sandbox/tests/offline/guards/ means the recursion in
 * regress_bundle_coverage.sh and tools/affected.php is exercised by the real
 * corpus on every run, not only by their own synthetic fixtures.
 *
 * Clause 4 (the class-prefix invariant) is written to pass against today's
 * entirely flat estate and to bite progressively as files move: a file still
 * at the sandbox/tests ROOT is exempt, and only a file already under
 * offline/, live/, grind/, certify/ or spike/ has to prove it is wired into a
 * target of the matching class. So this guard cannot block the waves, and no
 * wave can land a file in the wrong class.
 *
 * WHY THE SELF-TESTS
 * ------------------
 * Same reason regress_bundle_coverage.sh carries its own: a guard that reports
 * "no violations" is indistinguishable from a guard that has stopped looking.
 * Each clause below is first driven against a synthetic Makefile and a
 * synthetic tree carrying exactly that defect, and only then against the real
 * ./Makefile -- so a green line at the bottom means the detector fired on a
 * planted defect minutes earlier, not merely that nothing was found.
 *
 * Pure text scan of a Makefile plus is_file() -- no `make`, no docker, no
 * WordPress. It parses the Makefile itself rather than shelling out to
 * regress_bundle_coverage.sh: that suite's Python check answers a different
 * question and exposes no reusable interface, so the prerequisite-expansion
 * approach is ported here (same continuation handling, same
 * code-half-unit/regress-offline-corpus expansion) rather than invoked.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../lib/check.php';

/**
 * Targets whose recipe is deliberately NOT a bare
 * `php sandbox/tests/<file>.php` / `bash sandbox/tests/<file>.sh`.
 *
 * Enumerated by name, not by pattern: a pattern ("anything with an env
 * prefix") would let a new odd recipe in unnoticed, which is the whole class
 * of drift this file guards. Every entry is a target whose suite needs
 * parameters make can only pass as environment, plus the one guarded wrapper.
 * All of them are live or grind targets except regress-offline-all itself, so
 * none is read by tools/offline.php's serial-group scan.
 */
const WIRING_SHAPE_EXCEPTIONS = [
    // The corpus's own guarded wrapper: the recipe runs the diagnostics guard
    // and passes $(MAKE) through to it, so the sandbox/tests path it names is
    // the guard, not a suite.
    'regress-offline-all',
    // grind targets: pair name, ports and scenario selection arrive as env.
    'grind-adapter-walk',
    'grind-adoption',
    'grind-ecommerce-developer-live',
    'grind-mup',
    // live targets with an explicit pair plus a DUO_EXPECTED_SOURCE_SHA
    // candidate gate, all passed as environment.
    'regress-scope-chain-stability',
    'regress-scoped-apply-live',
    'regress-ssh-adopt',
];

/**
 * Targets whose name deliberately does NOT derive from their suite file's
 * basename.
 *
 * Two groups, both predating the naming rule: the short spike- and grind-
 * names (the file carries a descriptive suffix the target drops -- `spike-a`
 * runs spike_a_round_trip.sh, `grind-ecommerce-developer-live` runs
 * grind_ecommerce_developer.sh), and regress-offline-all, whose recipe names
 * the diagnostics guard rather than a suite of its own.
 *
 * A target here is invisible to tools/affected.php's target->file mapping and
 * therefore selects for nothing under `--changed`. That is accepted only
 * because every entry is a live, grind or spike target that `--changed` never
 * runs anyway; it would NOT be acceptable for an offline suite, which is why
 * the list is a closed set of names rather than an inferred pattern.
 */
const WIRING_NAME_EXCEPTIONS = [
    'grind-code-half-first-sync',
    'grind-ecommerce-developer-live',
    'grind-r1a',
    'grind-r1b',
    'grind-r1c',
    'grind-r3a',
    'grind-r3b',
    'regress-offline-all',
    'spike-a',
    'spike-b',
    'spike-c',
    'spike-d',
    'spike-e',
];

/** Directory under sandbox/tests -> the target-name prefix its suites must carry. */
const WIRING_CLASS_PREFIXES = [
    'certify' => 'certify-',
    'grind' => 'grind-',
    'spike' => 'spike-',
];

/**
 * Makefile text -> {target => prerequisite text} and {target => recipe lines},
 * with backslash continuations folded onto one logical line.
 *
 * Continuations are folded because make hands the whole continued line to one
 * shell, so `ENV="x" \` + `bash sandbox/tests/y.sh` is a single recipe whose
 * shape must be judged as a whole -- judging the fragments separately would
 * report the env prefix and the invocation as two unrelated recipes.
 *
 * @return array{prereqs: array<string,string>, recipes: array<string,list<string>>}
 */
function wiring_parse_makefile(string $text): array
{
    $lines = explode("\n", $text);
    $count = count($lines);
    $prereqs = [];
    $recipes = [];
    $current = null;
    for ($i = 0; $i < $count; $i++) {
        $joined = $lines[$i];
        while (str_ends_with(rtrim($joined), '\\') && $i + 1 < $count) {
            $joined = rtrim(rtrim($joined), '\\') . ' ' . ltrim($lines[++$i]);
        }
        if ($joined !== '' && $joined[0] === "\t") {
            if ($current !== null) {
                $recipes[$current][] = substr($joined, 1);
            }
            continue;
        }
        // A blank or comment line does NOT close a recipe -- make ignores both
        // inside one, and this Makefile does interleave them -- so neither
        // resets the current target. Anything else that is not a rule (a
        // variable assignment, an `include`) does.
        if ($joined === '' || $joined[0] === '#') {
            continue;
        }
        // `target: prereqs`, never `VAR := value` / `VAR = value`.
        if (preg_match('/^([^\s:=#][^:=]*):(?!=)(.*)$/', $joined, $m) !== 1) {
            $current = null;
            continue;
        }
        $current = trim($m[1]);
        $prereqs[$current] ??= trim($m[2]);
        $recipes[$current] ??= [];
    }

    return ['prereqs' => $prereqs, 'recipes' => $recipes];
}

/**
 * Every target reachable from $start through prerequisites, $start included.
 *
 * @param array<string,string> $prereqs
 * @return array<string,true>
 */
function wiring_closure(array $prereqs, string $start): array
{
    $seen = [];
    $pending = [$start];
    while ($pending !== []) {
        $target = array_pop($pending);
        if (isset($seen[$target])) {
            continue;
        }
        $seen[$target] = true;
        foreach (preg_split('/\s+/', $prereqs[$target] ?? '', -1, PREG_SPLIT_NO_EMPTY) ?: [] as $child) {
            $pending[] = $child;
        }
    }

    return $seen;
}

/**
 * Every sandbox/tests path token in one recipe line, as WRITTEN.
 *
 * Deliberately captures the raw token including any `$(VAR)` or `$<` in it,
 * rather than the literal-only shape tools/offline.php matches: the point of
 * the no-variable clause is to fail on exactly the tokens that tool would
 * silently skip, so this scan has to be able to see them.
 *
 * @return list<string>
 */
function wiring_path_tokens(string $recipe): array
{
    $out = [];
    if (preg_match_all('#sandbox/tests/[^\s"\'|;>]*#', $recipe, $m) === false) {
        return $out;
    }
    foreach ($m[0] as $token) {
        $out[] = $token;
    }

    return $out;
}

/**
 * The five clauses, run over one Makefile text against one repo root.
 *
 * Returns violations per clause rather than asserting inline so the same code
 * path serves both the self-tests (synthetic Makefile, synthetic tree) and the
 * real check. `recipes` is the number of recipe lines that named a
 * sandbox/tests path at all -- a scan that reached nothing must not read as a
 * clean bill of health.
 *
 * @return array{
 *   variables: list<string>, shape: list<string>, missing: list<string>,
 *   name: list<string>, class: list<string>, recipes: int, nested: int
 * }
 */
function wiring_violations(string $root, string $makefileText): array
{
    $parsed = wiring_parse_makefile($makefileText);
    $offlineClosure = wiring_closure($parsed['prereqs'], 'regress-offline-corpus')
        + wiring_closure($parsed['prereqs'], 'code-half-unit');

    // regress-live-list runs nothing; it PRINTS the live estate, one target
    // per echo line. That printed list is the only machine-readable record of
    // which targets are live, and regress_bundle_coverage.sh already treats it
    // as one.
    $liveNames = [];
    foreach ($parsed['recipes']['regress-live-list'] ?? [] as $recipe) {
        if (preg_match_all('/\b((?:regress|grind|certify)-[a-z0-9-]+)/', $recipe, $m)) {
            foreach ($m[1] as $name) {
                $liveNames[$name] = true;
            }
        }
    }

    $out = [
        'variables' => [], 'shape' => [], 'missing' => [],
        'name' => [], 'class' => [], 'recipes' => 0, 'nested' => 0,
    ];

    foreach ($parsed['recipes'] as $target => $recipeLines) {
        foreach ($recipeLines as $recipe) {
            $tokens = wiring_path_tokens($recipe);
            if ($tokens === []) {
                continue;
            }
            $out['recipes']++;

            // (5) No make variable and no automatic variable inside the path.
            foreach ($tokens as $token) {
                if (preg_match('#^sandbox/tests/[A-Za-z0-9_./-]+\.(?:php|sh)$#', $token) !== 1) {
                    $out['variables'][] = "$target: '$token'";
                }
            }

            // (1) One of the two canonical shapes, or a named exception.
            $canonical = preg_match(
                '#^(?:php|bash) sandbox/tests/[A-Za-z0-9_./-]+\.(?:php|sh)$#',
                trim($recipe)
            ) === 1;
            if (!$canonical && !in_array($target, WIRING_SHAPE_EXCEPTIONS, true)) {
                $out['shape'][] = "$target: " . trim($recipe);
            }

            foreach ($tokens as $token) {
                // (2) The file the recipe names has to exist. A missing file
                // short-circuits the two clauses below it: they would both
                // report on a name nobody can act on until the file is there.
                if (!is_file($root . '/' . $token)) {
                    $out['missing'][] = "$target: $token";
                    continue;
                }

                // (3) target name == basename stem with _ -> -.
                $stem = (string) preg_replace('/\.(?:php|sh)$/', '', basename($token));
                $expected = strtr($stem, '_', '-');
                if ($expected !== $target && !in_array($target, WIRING_NAME_EXCEPTIONS, true)) {
                    $out['name'][] = "$target: expected target '$expected' for $token";
                }

                // (4) Class-prefix invariant. A file still at the sandbox/tests
                // ROOT is exempt -- that exemption is what lets this guard pass
                // against the flat estate and start biting the moment a file
                // lands in a class directory.
                $relative = substr($token, strlen('sandbox/tests/'));
                if (!str_contains($relative, '/')) {
                    continue;
                }
                $out['nested']++;
                $class = substr($relative, 0, (int) strpos($relative, '/'));
                if ($class === 'offline') {
                    if (!isset($offlineClosure[$target])) {
                        $out['class'][] = "$target ($token) is under offline/ but is not in"
                            . " regress-offline-corpus's prerequisite closure";
                    }
                    if (isset($liveNames[$target])) {
                        $out['class'][] = "$target ($token) is under offline/ but is named in"
                            . ' regress-live-list';
                    }
                } elseif ($class === 'live') {
                    if (!isset($liveNames[$target])) {
                        $out['class'][] = "$target ($token) is under live/ but is not named in"
                            . ' regress-live-list';
                    }
                    if (isset($offlineClosure[$target])) {
                        $out['class'][] = "$target ($token) is under live/ but is in"
                            . " regress-offline-corpus's prerequisite closure";
                    }
                } elseif (isset(WIRING_CLASS_PREFIXES[$class])) {
                    $prefix = WIRING_CLASS_PREFIXES[$class];
                    if (!str_starts_with($target, $prefix)) {
                        $out['class'][] = "$target ($token) is under $class/ but its target does"
                            . " not start with '$prefix'";
                    }
                }
            }
        }
    }

    return $out;
}

// ---------------------------------------------------------------- self-tests

/**
 * A miniature but structurally complete Makefile: a guarded offline wrapper, a
 * corpus with a code-half-unit arm, one flat suite, one nested offline suite,
 * one nested live suite named in regress-live-list, and one flat suite wired
 * nowhere (the clause-4 root exemption, which must produce no violation).
 */
function wiring_fixture_makefile(): string
{
    return implode("\n", [
        '.PHONY: regress-offline-all regress-offline-corpus',
        '',
        'regress-offline-all:',
        "\t@bash sandbox/tests/offline_diagnostics_guard.sh \"\$(MAKE)\" regress-offline-corpus",
        "\t@echo \"regress-offline-all: 3 offline suites green\"",
        '',
        'regress-offline-corpus: code-half-unit \\',
        "\tregress-nested-offline",
        "\t@echo corpus",
        '',
        'code-half-unit: regress-flat-root',
        '',
        'regress-flat-root:',
        "\tphp sandbox/tests/regress_flat_root.php",
        '',
        'regress-nested-offline:',
        "\tphp sandbox/tests/offline/domain/regress_nested_offline.php",
        '',
        'regress-flat-orphan:',
        "\tphp sandbox/tests/regress_flat_orphan.php",
        '',
        'regress-nested-live:',
        "\tbash sandbox/tests/live/regress_nested_live.sh",
        '',
        'regress-live-list:',
        "\t@echo \"  regress-nested-live      pair fixture\"",
        '',
    ]);
}

/** The synthetic tree the fixture Makefile refers to. Caller removes it. */
function wiring_fixture_root(): string
{
    $root = (string) tempnam(sys_get_temp_dir(), 'duo-wiring-');
    unlink($root);
    foreach (['', '/offline/domain', '/live', '/grind'] as $sub) {
        mkdir($root . '/sandbox/tests' . $sub, 0o777, true);
    }
    foreach ([
        '/sandbox/tests/offline_diagnostics_guard.sh',
        '/sandbox/tests/regress_flat_root.php',
        '/sandbox/tests/regress_flat_orphan.php',
        '/sandbox/tests/regress_flat_other.php',
        '/sandbox/tests/offline/domain/regress_nested_offline.php',
        '/sandbox/tests/offline/domain/regress_flat_orphan.php',
        '/sandbox/tests/live/regress_nested_live.sh',
        '/sandbox/tests/grind/regress_wrong_class.sh',
    ] as $file) {
        file_put_contents($root . $file, "# synthetic wiring fixture\n");
    }

    return $root;
}

function wiring_remove_tree(string $path): void
{
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                wiring_remove_tree($path . '/' . $entry);
            }
        }
        rmdir($path);

        return;
    }
    if (file_exists($path)) {
        unlink($path);
    }
}

$fixtureRoot = wiring_fixture_root();

try {
    $base = wiring_fixture_makefile();
    $clean = wiring_violations($fixtureRoot, $base);

    duo_check_same(
        ['variables' => [], 'shape' => [], 'missing' => [], 'name' => [], 'class' => []],
        [
            'variables' => $clean['variables'], 'shape' => $clean['shape'],
            'missing' => $clean['missing'], 'name' => $clean['name'], 'class' => $clean['class'],
        ],
        'self-test: a correctly wired synthetic Makefile produces no violations'
    );
    duo_check_same(5, $clean['recipes'], 'self-test: the synthetic scan reached all five suite recipes');
    duo_check_same(2, $clean['nested'], 'self-test: two synthetic suites are in class directories');

    // regress-flat-orphan is in neither the offline closure nor the live list,
    // and produced no violation above: that is the root exemption. Move the
    // same file under offline/ and clause 4 must fire -- this pair is the
    // whole "passes flat today, bites as files move" contract.
    $orphanNested = str_replace(
        'php sandbox/tests/regress_flat_orphan.php',
        'php sandbox/tests/offline/domain/regress_flat_orphan.php',
        $base
    );
    $nested = wiring_violations($fixtureRoot, $orphanNested);
    duo_check(
        count($nested['class']) === 1
            && str_contains($nested['class'][0], 'regress-flat-orphan')
            && str_contains($nested['class'][0], 'not in'),
        'self-test: a suite under offline/ outside the corpus closure is refused'
    );

    // The mirror: a live suite that IS in the offline closure is claimed by
    // both classes, which no suite may be.
    $liveInCorpus = str_replace(
        "\tregress-nested-offline",
        "\tregress-nested-offline regress-nested-live",
        $base
    );
    $both = wiring_violations($fixtureRoot, $liveInCorpus);
    duo_check(
        count($both['class']) === 1 && str_contains($both['class'][0], 'is under live/ but is in'),
        'self-test: a suite under live/ that is also an offline prerequisite is refused'
    );

    // A grind/ file under a regress- target: the prefix rule for the three
    // enumerated class directories.
    $wrongClass = $base . "\nregress-wrong-class:\n\tbash sandbox/tests/grind/regress_wrong_class.sh\n";
    $classViolations = wiring_violations($fixtureRoot, $wrongClass)['class'];
    duo_check(
        count($classViolations) === 1 && str_contains($classViolations[0], "start with 'grind-'"),
        'self-test: a suite under grind/ wired to a non-grind target is refused'
    );

    // Clause 5, the one tools/offline.php cannot survive: a make variable in
    // the path. `make -p` prints it unexpanded, offline.php's literal regex
    // skips it, and the suite silently leaves the serial group.
    $variablePath = str_replace(
        'php sandbox/tests/offline/domain/regress_nested_offline.php',
        'php sandbox/tests/$(DOMAIN)/regress_nested_offline.php',
        $base
    );
    $variables = wiring_violations($fixtureRoot, $variablePath)['variables'];
    duo_check(
        count($variables) === 1 && str_contains($variables[0], '$(DOMAIN)'),
        'self-test: a make variable inside a sandbox/tests recipe path is refused'
    );

    // Clause 1: an env prefix on a target that is not a named exception.
    $envPrefixed = str_replace(
        "\tphp sandbox/tests/regress_flat_root.php",
        "\tFIXTURE=1 php sandbox/tests/regress_flat_root.php",
        $base
    );
    $shape = wiring_violations($fixtureRoot, $envPrefixed)['shape'];
    duo_check(
        count($shape) === 1 && str_contains($shape[0], 'regress-flat-root'),
        'self-test: a non-canonical recipe shape on an unlisted target is refused'
    );

    // ... and the same shape IS accepted for a listed exception, which is what
    // keeps the exception list meaningful rather than decorative.
    $exceptionShaped = $base . "\nregress-ssh-adopt:\n\tADOPT_FIXTURE=x bash sandbox/tests/regress_flat_other.php\n";
    duo_check_same(
        [],
        wiring_violations($fixtureRoot, $exceptionShaped)['shape'],
        'self-test: a listed shape exception may carry an env prefix'
    );

    // Clause 2: a recipe naming a file that does not exist.
    $absent = str_replace('regress_flat_root.php', 'regress_flat_absent.php', $base);
    $missing = wiring_violations($fixtureRoot, $absent)['missing'];
    duo_check(
        count($missing) === 1 && str_contains($missing[0], 'regress_flat_absent.php'),
        'self-test: a recipe naming a file that does not exist is refused'
    );

    // Clause 3: a target whose name does not derive from its file's basename.
    $misnamed = str_replace(
        "\tphp sandbox/tests/regress_flat_root.php",
        "\tphp sandbox/tests/regress_flat_other.php",
        $base
    );
    $names = wiring_violations($fixtureRoot, $misnamed)['name'];
    duo_check(
        count($names) === 1 && str_contains($names[0], "expected target 'regress-flat-other'"),
        'self-test: a target whose name disagrees with its suite file is refused'
    );
} finally {
    wiring_remove_tree($fixtureRoot);
}

// --------------------------------------------------------------- real check

$root = dirname(__DIR__, 4);
$makefile = (string) file_get_contents($root . '/Makefile');
$parsed = wiring_parse_makefile($makefile);
$found = wiring_violations($root, $makefile);

// A scan that reached nothing must not read as a clean bill of health.
duo_check(
    $found['recipes'] > 300,
    "the scan reached the Makefile's recipes ({$found['recipes']} name a sandbox/tests path)"
);

$report = static function (string $key, string $message) use ($found): void {
    duo_check($found[$key] === [], $message);
    foreach ($found[$key] as $violation) {
        duo_check_detail($violation);
    }
};

$report('variables', 'no sandbox/tests recipe path contains a make variable');
if ($found['variables'] !== []) {
    duo_check_detail('tools/offline.php:250 matches literal paths against UNEXPANDED `make -p`');
    duo_check_detail('recipes; a variable there hides the suite from its serial-group collision scan');
}
$report('shape', 'every sandbox/tests recipe is `php <file>.php` / `bash <file>.sh` or a named exception');
$report('missing', 'every sandbox/tests path a recipe names exists on disk');
$report('name', "every target's name matches its suite file's basename, or is a named exception");
$report('class', 'every suite in a class directory is wired into a target of that class');

// The exception lists are closed sets, not advisory. An entry that no longer
// names a real target is a stale exemption: it would keep exempting nothing
// while the reader believes it still covers something.
foreach (WIRING_SHAPE_EXCEPTIONS as $target) {
    duo_check(
        isset($parsed['recipes'][$target]),
        "shape exception '$target' still names a real Makefile target"
    );
}
foreach (WIRING_NAME_EXCEPTIONS as $target) {
    duo_check(
        isset($parsed['recipes'][$target]),
        "name exception '$target' still names a real Makefile target"
    );
}

// This file is itself the proof that the nested case is exercised by the real
// corpus rather than only by the synthetic fixture above: it is under
// offline/, so the clause-4 branch ran against it, and it is a
// regress-offline-corpus prerequisite, so the whole scan runs on every
// `make regress-offline-all`.
duo_check(
    $found['nested'] > 0,
    "clause 4 saw at least one real suite in a class directory ({$found['nested']})"
);
duo_check(
    isset(wiring_closure($parsed['prereqs'], 'regress-offline-corpus')['regress-suite-wiring']),
    "this suite is itself in regress-offline-corpus's prerequisite closure"
);

duo_check_summary('regress-suite-wiring');
