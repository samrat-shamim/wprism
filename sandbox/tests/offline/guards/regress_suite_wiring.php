<?php
/**
 * Offline invariant — every Makefile recipe that names a sandbox/tests path is
 * shaped, spelled and located the way the tooling that reads those recipes
 * assumes.
 *
 * WHY THIS EXISTS
 * ---------------
 * sandbox/tests/offline/guards/regress_bundle_coverage.sh answers one direction: does every
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
 * Clause 4 (the class invariant) is written to pass against today's
 * entirely flat estate and to bite progressively as files move: a file still
 * at the sandbox/tests ROOT is exempt, and only a file already under
 * offline/, live/, grind/, certify/ or spike/ has to prove it is wired into a
 * target of the matching class. So this guard cannot block the waves, and no
 * wave can land a file in the wrong class.
 *
 * A class directory holds two kinds of file and they cannot be judged by one
 * rule, which W2 is where it first bites. A SUITE is something make runs:
 * `live/regress_widgets.sh` is wired to `regress-widgets`, and if it were not
 * wired at all it would sit in live/ looking like part of the live estate
 * while running nowhere. A HELPER is everything else a suite needs beside it --
 * `grind/grind_ecommerce_developer.matrix.json` is read by two offline suites,
 * `spike/cli_smoke.sh` is a hand-run docker smoke whose target `cli-smoke`
 * predates the class names entirely. Requiring a helper to be wired would
 * refuse the ratified layout; requiring nothing of it would let an offline
 * recipe quietly run a file that sits in live/. So clause 4 splits:
 *
 *   - a suite must be wired, and wired to a target of its own class;
 *   - a helper need not be wired at all, but no recipe of a DIFFERENT
 *     determinable class may name it.
 *
 * A file is a suite when BOTH halves hold, and each half rules out a real
 * member of the ratified layout that the other would misjudge:
 *
 *   - its basename claims a class: `regress_`, `grind_`, `certify_` or
 *     `spike_`, ending in .php or .sh. `spike/cli_status_truth.sh` and
 *     `spike/check_guide_commands.sh` deliberately decline that prefix and
 *     say so in their own headers ("deliberately NOT named regress_* and
 *     deliberately has no Makefile target"); declining the name is how this
 *     estate declines a target, so demanding one of them would refuse a file
 *     for being exactly what it says it is. It is also what keeps
 *     `grind/grind_ecommerce_developer.matrix.json` -- data, not a program --
 *     and the three legacy `cli_*`/`lint_*` smokes out of the suite rules;
 *     `cli-smoke` carries no class prefix and never will.
 *   - no OTHER file's code runs it. `regress_fatal_mutations.php` claims the
 *     name and is still not a suite: `regress_fatal_mutations_unit.sh` runs
 *     it and it has no target of its own, which is why
 *     regress_bundle_coverage.sh:81-134 exempts it. That definition -- a
 *     helper is a file some other file's CODE runs, `php <name>` or
 *     `bash <name>` -- is the estate's single answer, ported here
 *     (wiring_invoked_elsewhere()) for the same reason the prerequisite
 *     expansion is: that suite exposes no reusable interface. Ported, not
 *     re-decided; if the two ever disagree, this one is wrong.
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
    // live targets with an explicit pair plus a WPRISM_EXPECTED_SOURCE_SHA
    // candidate gate, all passed as environment.
    'regress-database-boundary-live',
    'regress-core-ssh-deletion',
    'regress-env-set',
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
 * The execution classes that have a directory of their own.
 *
 * offline/ and live/ are absent from WIRING_CLASS_PREFIXES because neither is
 * decided by a prefix -- `regress-` fronts both -- but all five are class
 * directories for the purpose of "which files does clause 4 look at".
 */
const WIRING_CLASS_DIRS = ['certify', 'grind', 'live', 'offline', 'spike'];

/**
 * The four basename prefixes with which a file claims to be a suite of a
 * class. A file in a class directory that carries none of them is a helper
 * (see the header): data, substrate, or a hand-run smoke that declined a
 * target on purpose.
 */
const WIRING_SUITE_PREFIXES = ['certify_', 'grind_', 'regress_', 'spike_'];

/** A file make could plausibly be asked to run; a .json data file could not. */
const WIRING_RUNNABLE_SUFFIXES = ['.php', '.sh'];

/** Does this path's basename claim to be a suite make runs? */
function wiring_claims_suite_name(string $token): bool
{
    $basename = basename($token);
    $runnable = false;
    foreach (WIRING_RUNNABLE_SUFFIXES as $suffix) {
        $runnable = $runnable || str_ends_with($basename, $suffix);
    }
    if (!$runnable) {
        return false;
    }
    foreach (WIRING_SUITE_PREFIXES as $prefix) {
        if (str_starts_with($basename, $prefix)) {
            return true;
        }
    }

    return false;
}

/**
 * Makefile text with every `include` directive folded in, in the order make
 * would read them.
 *
 * `regress-offline-corpus`'s prerequisite list and both status counts are
 * generated into tools/offline-corpus.mk and pulled in with `include`
 * (tools/offline-corpus.php states why), so a scan that reads ./Makefile
 * alone now sees a corpus with no prerequisites at all -- every clause-4
 * judgement below would report "not in the corpus closure" about a suite the
 * gate runs. That is a fail-CLOSED direction rather than a fail-open one, but
 * it is still wrong, so the fold happens before anything is parsed.
 *
 * Variables and globs in an include path are skipped rather than resolved:
 * this is not make, and guessing at a path it cannot read literally would
 * silently drop a rule. tools/offline-corpus.php refuses to generate against
 * such a path for the same reason.
 */
function wiring_resolve_includes(string $root, string $text, int $depth = 0): string
{
    if ($depth > 4) {
        return $text;
    }
    $out = [];
    foreach (explode("\n", $text) as $line) {
        $out[] = $line;
        if (preg_match('/^(-?)include\s+(.+)$/', $line, $m) !== 1) {
            continue;
        }
        foreach (preg_split('/\s+/', trim($m[2]), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $included) {
            if (preg_match('#^[A-Za-z0-9_./-]+$#', $included) !== 1) {
                continue;
            }
            if (!is_file($root . '/' . $included)) {
                continue;
            }
            $out[] = wiring_resolve_includes(
                $root,
                (string) file_get_contents($root . '/' . $included),
                $depth + 1
            );
        }
    }

    return implode("\n", $out);
}

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
 * Every file under a sandbox/tests class directory, as the repo-relative token
 * a recipe would have to write to name it.
 *
 * Recursive: offline/ is one directory per domain, so a non-recursive read
 * would see the domain directories and none of the suites inside them -- the
 * same fail-open shape regress_bundle_coverage.sh:35-42 recursed to close.
 *
 * @return list<string>
 */
function wiring_class_files(string $root): array
{
    $out = [];
    foreach (WIRING_CLASS_DIRS as $class) {
        $base = $root . '/sandbox/tests/' . $class;
        if (!is_dir($base)) {
            continue;
        }
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($walk as $entry) {
            if ($entry instanceof SplFileInfo && $entry->isFile() && !$entry->isLink()) {
                $out[] = 'sandbox/tests/' . $class
                    . substr(str_replace('\\', '/', $entry->getPathname()), strlen($base));
            }
        }
    }
    sort($out, SORT_STRING);

    return $out;
}

/**
 * Which of $basenames some OTHER file's code runs, `php <name>` / `bash <name>`.
 *
 * Ported from regress_bundle_coverage.sh:81-134, deliberately including its
 * two judgement calls: only full-line comments are stripped (a doc-comment
 * mention must not read as an invocation, an inline trailing comment is left
 * alone), and an optional `<path>/` prefix is accepted so a cross-directory
 * `php ../apply/regress_x.php` counts the same as a bare sibling name. Erring
 * toward "invoked" is the safe direction here too: it exempts a file from the
 * wiring requirement rather than inventing a violation nobody can act on.
 *
 * Only the candidates are searched for, because the answer is needed only for
 * a suite-shaped file that no recipe names -- normally none.
 *
 * @param list<string> $basenames
 * @return array<string,true>
 */
function wiring_invoked_elsewhere(string $root, array $basenames): array
{
    if ($basenames === []) {
        return [];
    }
    $tests = $root . '/sandbox/tests';
    if (!is_dir($tests)) {
        return [];
    }
    // Longest first, so a basename that is a strict string prefix of another
    // cannot capture a match that belongs to the longer name.
    usort($basenames, static fn(string $a, string $b): int => strlen($b) <=> strlen($a));
    $pattern = '#(?:php|bash)\s+(?:\S*/)?(' . implode('|', array_map(
        static fn(string $b): string => preg_quote($b, '#'),
        $basenames
    )) . ')\b#';

    $found = [];
    $walk = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($tests, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($walk as $entry) {
        if (!$entry instanceof SplFileInfo || !$entry->isFile() || $entry->isLink()) {
            continue;
        }
        $self = $entry->getFilename();
        $code = '';
        foreach (explode("\n", (string) file_get_contents($entry->getPathname())) as $line) {
            if (preg_match('/^\s*#/', $line) !== 1) {
                $code .= $line . "\n";
            }
        }
        if (preg_match_all($pattern, $code, $m) === false) {
            continue;
        }
        foreach ($m[1] as $name) {
            // A file naming itself is not another file running it.
            if ($name !== $self) {
                $found[$name] = true;
            }
        }
    }

    return $found;
}

/**
 * The execution class a target belongs to, or null when nothing states one.
 *
 * Prefix first, then the two membership lists: `grind-mup` carries a grind
 * prefix AND a regress-live-list row (the list prints five grind-/certify-
 * rows for operators), and its prefix is the deliberate statement of class.
 *
 * null is a real answer, not a failure -- `cli-smoke`, `lint-smoke` and
 * `cli-triage-smoke` are hand-run targets named before the class directories
 * existed. A target with no class cannot contradict a file's directory, so it
 * is allowed to name a helper anywhere; what it may NOT do is name a suite,
 * because a suite in a class directory must be wired to that class.
 *
 * @param array<string,true> $offlineClosure
 * @param array<string,true> $liveNames
 */
function wiring_target_class(string $target, array $offlineClosure, array $liveNames): ?string
{
    foreach (WIRING_CLASS_PREFIXES as $class => $prefix) {
        if (str_starts_with($target, $prefix)) {
            return $class;
        }
    }
    if (isset($offlineClosure[$target])) {
        return 'offline';
    }
    if (isset($liveNames[$target])) {
        return 'live';
    }

    return null;
}

/**
 * The six clauses, run over one Makefile text against one repo root.
 *
 * Returns violations per clause rather than asserting inline so the same code
 * path serves both the self-tests (synthetic Makefile, synthetic tree) and the
 * real check. `recipes` is the number of recipe lines that named a
 * sandbox/tests path at all -- a scan that reached nothing must not read as a
 * clean bill of health.
 *
 * @return array{
 *   variables: list<string>, shape: list<string>, missing: list<string>,
 *   name: list<string>, class: list<string>, unwired: list<string>,
 *   recipes: int, nested: int, helpers: int
 * }
 */
function wiring_violations(string $root, string $makefileText): array
{
    $parsed = wiring_parse_makefile(wiring_resolve_includes($root, $makefileText));
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

    // Suite or helper, decided once for every file in a class directory, by
    // the two-part definition in the header: the basename claims a class, and
    // no other file's code runs it.
    $classFiles = wiring_class_files($root);
    $claimants = [];
    foreach ($classFiles as $token) {
        if (wiring_claims_suite_name($token)) {
            $claimants[$token] = basename($token);
        }
    }
    $invoked = wiring_invoked_elsewhere($root, array_values(array_unique($claimants)));
    $suiteFiles = [];
    foreach ($claimants as $token => $basename) {
        if (!isset($invoked[$basename])) {
            $suiteFiles[$token] = true;
        }
    }

    $out = [
        'variables' => [], 'shape' => [], 'missing' => [],
        'name' => [], 'class' => [], 'unwired' => [],
        'recipes' => 0, 'nested' => 0,
        'helpers' => count($classFiles) - count($suiteFiles),
    ];

    // Which class-directory files a recipe names at all: the other half of
    // clause 4, answered below once every recipe has been read.
    $namedByRecipe = [];

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

                // (4) Class invariant. A file still at the sandbox/tests ROOT
                // is exempt -- that exemption is what lets this guard pass
                // against the flat estate and start biting the moment a file
                // lands in a class directory.
                $relative = substr($token, strlen('sandbox/tests/'));
                if (!str_contains($relative, '/')) {
                    continue;
                }
                $out['nested']++;
                $namedByRecipe[$token][$target] = true;
                $class = substr($relative, 0, (int) strpos($relative, '/'));

                // A helper is judged by the class of the target that runs it,
                // not by the class rules a suite answers to: `cli-smoke` states
                // no class, so it may run spike/cli_smoke.sh, while an offline
                // recipe naming a file that sits in live/ is the drift this
                // half of the clause exists to refuse.
                if (!isset($suiteFiles[$token])) {
                    $targetClass = wiring_target_class($target, $offlineClosure, $liveNames);
                    if ($targetClass !== null && $targetClass !== $class) {
                        $out['class'][] = "$target ($token) is a helper under $class/ but the target"
                            . " that names it is $targetClass";
                    }
                    continue;
                }

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

    // The other direction, and the one no check answered before: the estate
    // read from DISK rather than from the Makefile. Every clause above starts
    // at a recipe, so a suite that no recipe names is invisible to all of them
    // -- it sits in live/ or grind/ looking like part of that estate and runs
    // nowhere. regress_bundle_coverage.sh catches this for `regress_*` names
    // only; grind_*, certify_* and spike_* files are outside its glob
    // entirely, so before this loop a `grind_x.sh` with no target was checked
    // by nothing at all.
    foreach (array_keys($suiteFiles) as $token) {
        if (!isset($namedByRecipe[$token])) {
            $out['unwired'][] = "$token is in a class directory but no recipe names it";
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

/**
 * The synthetic tree the fixture Makefile refers to. Caller removes it.
 *
 * The base tree is exactly consistent with wiring_fixture_makefile(): every
 * class-directory suite in it is wired. That is forced by the disk-side half
 * of clause 4 -- a file planted for one scenario would be an unwired suite in
 * every other -- so a scenario that needs an extra file passes it in as
 * `relative path => contents` rather than the tree carrying everything.
 *
 * The two base helpers are not incidental: `live/pair_helper.sh` (a runnable
 * file whose name claims no class) and `grind/grind_data.matrix.json` (data)
 * are both unwired on purpose, and every scenario asserting "no violations"
 * is therefore also asserting that a helper is never asked for a target.
 *
 * @param array<string,string> $extra
 */
function wiring_fixture_root(array $extra = []): string
{
    $root = (string) tempnam(sys_get_temp_dir(), 'wprism-wiring-');
    unlink($root);
    $files = [
        '/sandbox/tests/offline_diagnostics_guard.sh' => null,
        '/sandbox/tests/regress_flat_root.php' => null,
        '/sandbox/tests/regress_flat_orphan.php' => null,
        '/sandbox/tests/regress_flat_other.php' => null,
        '/sandbox/tests/offline/domain/regress_nested_offline.php' => null,
        '/sandbox/tests/live/regress_nested_live.sh' => null,
        '/sandbox/tests/live/pair_helper.sh' => null,
        '/sandbox/tests/grind/grind_data.matrix.json' => null,
    ];
    foreach ($extra as $path => $contents) {
        $files[$path] = $contents;
    }
    foreach ($files as $file => $contents) {
        $dir = dirname($root . $file);
        if (!is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }
        file_put_contents($root . $file, $contents ?? "# synthetic wiring fixture\n");
    }

    return $root;
}

/**
 * Run $fn against a fixture tree carrying $extra, and remove the tree after.
 *
 * @param array<string,string> $extra
 */
function wiring_with_root(array $extra, callable $fn): void
{
    $root = wiring_fixture_root($extra);
    try {
        $fn($root);
    } finally {
        wiring_remove_tree($root);
    }
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

    wprism_check_same(
        [
            'variables' => [], 'shape' => [], 'missing' => [],
            'name' => [], 'class' => [], 'unwired' => [],
        ],
        [
            'variables' => $clean['variables'], 'shape' => $clean['shape'],
            'missing' => $clean['missing'], 'name' => $clean['name'],
            'class' => $clean['class'], 'unwired' => $clean['unwired'],
        ],
        'self-test: a correctly wired synthetic Makefile produces no violations'
    );
    wprism_check_same(5, $clean['recipes'], 'self-test: the synthetic scan reached all five suite recipes');
    wprism_check_same(2, $clean['nested'], 'self-test: two synthetic suites are in class directories');
    // The base tree carries live/pair_helper.sh and grind/grind_data.matrix.json,
    // both unwired: the clean result above is also the proof that neither was
    // asked for a target.
    wprism_check_same(2, $clean['helpers'], 'self-test: both synthetic helpers were classified as helpers');

    // regress-flat-orphan is in neither the offline closure nor the live list,
    // and produced no violation above: that is the root exemption. Move the
    // same file under offline/ and clause 4 must fire -- this pair is the
    // whole "passes flat today, bites as files move" contract.
    $orphanNested = str_replace(
        'php sandbox/tests/regress_flat_orphan.php',
        'php sandbox/tests/offline/domain/regress_flat_orphan.php',
        $base
    );
    wiring_with_root(
        ['/sandbox/tests/offline/domain/regress_flat_orphan.php' => null],
        static function (string $root) use ($orphanNested): void {
            $nested = wiring_violations($root, $orphanNested);
            wprism_check(
                count($nested['class']) === 1
                    && str_contains($nested['class'][0], 'regress-flat-orphan')
                    && str_contains($nested['class'][0], 'not in'),
                'self-test: a suite under offline/ outside the corpus closure is refused'
            );
        }
    );

    // The mirror: a live suite that IS in the offline closure is claimed by
    // both classes, which no suite may be.
    $liveInCorpus = str_replace(
        "\tregress-nested-offline",
        "\tregress-nested-offline regress-nested-live",
        $base
    );
    $both = wiring_violations($fixtureRoot, $liveInCorpus);
    wprism_check(
        count($both['class']) === 1 && str_contains($both['class'][0], 'is under live/ but is in'),
        'self-test: a suite under live/ that is also an offline prerequisite is refused'
    );

    // A grind/ file under a regress- target: the prefix rule for the three
    // enumerated class directories.
    $wrongClass = $base . "\nregress-wrong-class:\n\tbash sandbox/tests/grind/regress_wrong_class.sh\n";
    wiring_with_root(
        ['/sandbox/tests/grind/regress_wrong_class.sh' => null],
        static function (string $root) use ($wrongClass): void {
            $classViolations = wiring_violations($root, $wrongClass)['class'];
            wprism_check(
                count($classViolations) === 1 && str_contains($classViolations[0], "start with 'grind-'"),
                'self-test: a suite under grind/ wired to a non-grind target is refused'
            );
        }
    );

    // ---- the helper half of clause 4, which the W2 move is the first to need.

    // A helper that no recipe names is not a gap. live/pair_helper.sh is in
    // every fixture tree; this asserts the disk-side loop SAW it and let it
    // be, rather than never having looked -- the same reason `nested` and
    // `recipes` are counted.
    wprism_check(
        $clean['unwired'] === [] && $clean['helpers'] === 2,
        'self-test: an unwired helper under live/ is not required to have a target'
    );

    // The refusal that exemption must not cost: an OFFLINE recipe running a
    // file that sits in live/. The target is in the corpus closure, so its
    // class is offline and the file's directory says live -- exactly the
    // "which gate runs this" confusion the class directories exist to end.
    $offlineNamesLiveHelper = str_replace(
        "\tregress-nested-offline",
        "\tregress-nested-offline pair-helper",
        $base
    ) . "\npair-helper:\n\tbash sandbox/tests/live/pair_helper.sh\n";
    $helperClaimed = wiring_violations($fixtureRoot, $offlineNamesLiveHelper)['class'];
    wprism_check(
        count($helperClaimed) === 1
            && str_contains($helperClaimed[0], 'is a helper under live/')
            && str_contains($helperClaimed[0], 'is offline'),
        'self-test: a helper under live/ named by an offline recipe is refused'
    );

    // The disk-side half: a suite-shaped file in a class directory that no
    // recipe names at all. Every clause above starts from a recipe, so before
    // this loop existed the file was checked by nothing.
    wiring_with_root(
        ['/sandbox/tests/live/regress_unwired_live.sh' => null],
        static function (string $root) use ($base): void {
            $unwired = wiring_violations($root, $base)['unwired'];
            wprism_check(
                count($unwired) === 1 && str_contains($unwired[0], 'live/regress_unwired_live.sh'),
                'self-test: a suite-shaped file under live/ that no recipe names is refused'
            );
        }
    );

    // ...and the escape that keeps that from refusing the estate's real
    // shape: regress_fatal_mutations.php claims a suite name, has no target,
    // and is run by regress_fatal_mutations_unit.sh. Same shape here.
    wiring_with_root(
        [
            '/sandbox/tests/offline/domain/regress_invoked_helper.php' => null,
            '/sandbox/tests/offline/domain/regress_nested_offline.php' =>
                "<?php\n// a wrapper that runs its own helper\nexec('php regress_invoked_helper.php');\n",
        ],
        static function (string $root) use ($base): void {
            $found = wiring_violations($root, $base);
            wprism_check(
                $found['unwired'] === [] && $found['helpers'] === 3,
                'self-test: a suite-shaped file another suite runs needs no target of its own'
            );
        }
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
    wprism_check(
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
    wprism_check(
        count($shape) === 1 && str_contains($shape[0], 'regress-flat-root'),
        'self-test: a non-canonical recipe shape on an unlisted target is refused'
    );

    // ... and the same shape IS accepted for a listed exception, which is what
    // keeps the exception list meaningful rather than decorative.
    $exceptionShaped = $base . "\nregress-ssh-adopt:\n\tADOPT_FIXTURE=x bash sandbox/tests/regress_flat_other.php\n";
    wprism_check_same(
        [],
        wiring_violations($fixtureRoot, $exceptionShaped)['shape'],
        'self-test: a listed shape exception may carry an env prefix'
    );

    // Clause 2: a recipe naming a file that does not exist.
    $absent = str_replace('regress_flat_root.php', 'regress_flat_absent.php', $base);
    $missing = wiring_violations($fixtureRoot, $absent)['missing'];
    wprism_check(
        count($missing) === 1 && str_contains($missing[0], 'regress_flat_absent.php'),
        'self-test: a recipe naming a file that does not exist is refused'
    );

    // The include fold. The real corpus rule is generated into
    // tools/offline-corpus.mk, so every clause below rests on this: move the
    // corpus rule and its one suite into an included file and the result must
    // be identical to having written them inline.
    $corpusInclude = implode("\n", [
        'regress-offline-corpus: code-half-unit \\',
        "\tregress-nested-offline",
        "\t@echo corpus",
        '',
        'regress-nested-offline:',
        "\tphp sandbox/tests/offline/domain/regress_nested_offline.php",
        '',
    ]);
    $viaInclude = str_replace(
        [
            "regress-offline-corpus: code-half-unit \\\n\tregress-nested-offline\n\t@echo corpus",
            "regress-nested-offline:\n\tphp sandbox/tests/offline/domain/regress_nested_offline.php",
        ],
        ['include fixture-corpus.mk', ''],
        $base
    );
    wprism_check(
        str_contains($viaInclude, 'include fixture-corpus.mk')
            && !str_contains($viaInclude, 'regress-offline-corpus: code-half-unit'),
        'self-test: the include fixture actually moved the corpus rule out of the Makefile'
    );
    // Without the fold the corpus has no prerequisites at all, so every suite
    // under offline/ would be reported as outside its own class's closure.
    // This is the failure the fold exists to prevent, asserted before the
    // scenario that must not show it.
    wprism_check(
        wiring_closure(wiring_parse_makefile($viaInclude)['prereqs'], 'regress-offline-corpus')
            === ['regress-offline-corpus' => true],
        'self-test: an unfolded include leaves the corpus closure empty'
    );
    wiring_with_root(
        ['/fixture-corpus.mk' => $corpusInclude],
        static function (string $root) use ($viaInclude): void {
            $folded = wiring_violations($root, $viaInclude);
            wprism_check(
                $folded['class'] === [] && $folded['unwired'] === [] && $folded['missing'] === [],
                'self-test: a suite wired only inside an included Makefile fragment is accepted'
            );
            wprism_check_same(2, $folded['nested'], 'self-test: the folded scan still reached both nested suites');
        }
    );

    // Clause 3: a target whose name does not derive from its file's basename.
    $misnamed = str_replace(
        "\tphp sandbox/tests/regress_flat_root.php",
        "\tphp sandbox/tests/regress_flat_other.php",
        $base
    );
    $names = wiring_violations($fixtureRoot, $misnamed)['name'];
    wprism_check(
        count($names) === 1 && str_contains($names[0], "expected target 'regress-flat-other'"),
        'self-test: a target whose name disagrees with its suite file is refused'
    );
} finally {
    wiring_remove_tree($fixtureRoot);
}

// --------------------------------------------------------------- real check

$root = dirname(__DIR__, 4);
$makefile = (string) file_get_contents($root . '/Makefile');
$parsed = wiring_parse_makefile(wiring_resolve_includes($root, $makefile));
$found = wiring_violations($root, $makefile);

// A scan that reached nothing must not read as a clean bill of health.
wprism_check(
    $found['recipes'] > 300,
    "the scan reached the Makefile's recipes ({$found['recipes']} name a sandbox/tests path)"
);

$report = static function (string $key, string $message) use ($found): void {
    wprism_check($found[$key] === [], $message);
    foreach ($found[$key] as $violation) {
        wprism_check_detail($violation);
    }
};

$report('variables', 'no sandbox/tests recipe path contains a make variable');
if ($found['variables'] !== []) {
    wprism_check_detail('tools/offline.php:250 matches literal paths against UNEXPANDED `make -p`');
    wprism_check_detail('recipes; a variable there hides the suite from its serial-group collision scan');
}
$report('shape', 'every sandbox/tests recipe is `php <file>.php` / `bash <file>.sh` or a named exception');
$report('missing', 'every sandbox/tests path a recipe names exists on disk');
$report('name', "every target's name matches its suite file's basename, or is a named exception");
$report('class', 'every suite in a class directory is wired into a target of that class');
$report('unwired', 'every suite in a class directory is named by some recipe');

// The exception lists are closed sets, not advisory. An entry that no longer
// names a real target is a stale exemption: it would keep exempting nothing
// while the reader believes it still covers something.
foreach (WIRING_SHAPE_EXCEPTIONS as $target) {
    wprism_check(
        isset($parsed['recipes'][$target]),
        "shape exception '$target' still names a real Makefile target"
    );
}
foreach (WIRING_NAME_EXCEPTIONS as $target) {
    wprism_check(
        isset($parsed['recipes'][$target]),
        "name exception '$target' still names a real Makefile target"
    );
}

// This file is itself the proof that the nested case is exercised by the real
// corpus rather than only by the synthetic fixture above: it is under
// offline/, so the clause-4 branch ran against it, and it is a
// regress-offline-corpus prerequisite, so the whole scan runs on every
// `make regress-offline-all`.
wprism_check(
    $found['nested'] > 0,
    "clause 4 saw at least one real suite in a class directory ({$found['nested']})"
);
// The disk-side half has no recipe to start from, so nothing else would notice
// if it enumerated an empty tree: state the count it actually walked.
wprism_check(
    count(wiring_class_files($root)) > 0,
    'clause 4 walked the class directories on disk ('
        . count(wiring_class_files($root)) . " files, {$found['helpers']} of them helpers)"
);
wprism_check(
    isset(wiring_closure($parsed['prereqs'], 'regress-offline-corpus')['regress-suite-wiring']),
    "this suite is itself in regress-offline-corpus's prerequisite closure"
);

wprism_check_summary('regress-suite-wiring');
