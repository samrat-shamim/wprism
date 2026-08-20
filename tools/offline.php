#!/usr/bin/env php
<?php

declare(strict_types=1);

namespace Duo\Tooling;

/**
 * Parallel driver for the offline (no-docker) regress corpus -- WP-1.
 *
 * WHY THIS EXISTS
 * ---------------
 * `make regress-offline-all` is the local merge gate. Serially it costs ~509 s,
 * which is long enough that people stop running it before pushing. Measured on
 * this clone, `make -k -j8 regress-offline-corpus` finishes the same work in
 * ~97 s with zero parallel flakes, so the wall time is a scheduling problem,
 * not a correctness one. This script buys that speedup while fixing the three
 * things raw `make -j` gets wrong for a test corpus:
 *
 *   1. `make -j` interleaves suite output into one stream, so a failure is a
 *      needle in ~500 KB of hay. Here every suite gets its own log file and
 *      failures are reported by name with a tail.
 *   2. `offline_diagnostics_guard.sh` greps the WHOLE run for PHP diagnostics.
 *      It can therefore tell you a diagnostic happened but not which suite
 *      emitted it. This script applies the EXACT same regex per suite, so the
 *      offender is named. That is strictly stronger, never weaker: a run this
 *      script calls green cannot contain a line the guard would have caught.
 *   3. `make -j` has no notion of which suites must not overlap. Some suites
 *      hard-code fixed `/tmp/...` paths (not mktemp), so two of them running at
 *      once fight over the same inode. Those are detected by scanning the suite
 *      source and put in a mutually exclusive "serial group".
 *
 * WHY THE WORK LIST COMES FROM MAKE
 * ---------------------------------
 * The Makefile is the single source of truth for what "offline" means, and
 * `sandbox/tests/offline/guards/regress_bundle_coverage.sh` independently proves no suite file
 * exists without a bundle entry. Duplicating the list here would create a
 * second, silently-drifting definition and would need a certification round to
 * change. So the list is read back out of make's own rules database
 * (`make -pn --no-print-directory regress-offline-corpus`), where line
 * continuations are already resolved, and expanded transitively from the
 * `regress-offline-corpus` root. Leaves (targets with no prerequisites of their
 * own) are the suites; the two aggregators in the closure --
 * `regress-offline-corpus` and `code-half-unit` -- are never executed because
 * their entire recipe is an `@echo` status line.
 *
 * EXPECTED COUNT. The corpus has 218 direct prerequisites (217 suites plus the
 * `code-half-unit` aggregator) and `code-half-unit` contributes 22 more, of
 * which exactly one (`regress-code-descriptor-compiler`) is already named
 * directly by the corpus. 217 + 22 - 1 = 238 unique leaves, which is the same
 * number the Makefile's own status line and regress-bundle-coverage assert.
 * A mismatch is reported as a notice, never a hard failure: the number is a
 * tripwire for parser drift, not a policy.
 *
 * WHY EACH SUITE IS RUN THROUGH `make <target>` AND NOT ITS RAW COMMAND
 * --------------------------------------------------------------------
 * Some recipes are multi-line and carry `VAR=... ` environment prefixes, and
 * recipes reference make variables. Re-implementing recipe expansion here would
 * be a second, wrong copy of make. A per-target `make` spawn costs ~30 ms
 * against a median suite time of 0.10 s, which is a fair price for never being
 * wrong about what a target actually runs.
 *
 * CONSTRAINTS THIS FILE RESPECTS
 * ------------------------------
 * - Plain PHP, no composer runtime dependencies; must run on PHP 8.3+.
 * - Touches nothing under agent/, cli/, sandbox/bin/, sandbox/tests/ or the
 *   Makefile: a byte change in any of those expires 9 certifications. All
 *   scratch state lives under sandbox/tmp/ (gitignored).
 * - Requirable without side effects, so tests/Tooling/OfflineRunnerTest.php can
 *   unit-test the parser/classifier halves (same `SCRIPT_FILENAME` guard idiom
 *   as recovery/rollback-control.php).
 */
final class OfflineRunner
{
    /**
     * Byte-for-byte the regex from sandbox/tests/offline_diagnostics_guard.sh.
     *
     * PHP CLI prints a diagnostic twice when display_errors=1: once to stderr
     * with the `PHP ` prefix and once to stdout without it. The second form is
     * only distinguishable from an intentional shell message such as
     * `Warning: fetch_artifact: retrying ...` by its source-location suffix,
     * hence the `( in | on line )` requirement on the unprefixed alternative.
     * Do not "tidy" this pattern: divergence from the guard would let this
     * runner call a run green that `make regress-offline-all` calls red.
     */
    public const DIAGNOSTIC_REGEX =
        '/^(PHP (Deprecated|Warning|Fatal error|Parse error):|((Deprecated|Warning|Fatal error|Parse error):.*( in | on line )))/';

    /**
     * Tripwire for make-database parser drift: the Makefile's own
     * `regress-offline-all: N offline suites green` status line (kept truthful
     * by sandbox/tests/offline/guards/regress_bundle_coverage.sh) is the single source of the
     * expected leaf count, so a bundle change never has to be mirrored here.
     */
    public static function expectedLeaves(string $repoRoot): ?int
    {
        $makefile = @file_get_contents($repoRoot . '/Makefile');
        if (!is_string($makefile)) {
            return null;
        }
        if (preg_match('/regress-offline-all:\s+(\d+)\s+offline suites green/', $makefile, $m) !== 1) {
            return null;
        }

        return (int) $m[1];
    }

    public const ROOT_TARGET = 'regress-offline-corpus';

    /**
     * An absolute `/tmp/...` literal, which is what makes two suites collide.
     *
     * The left boundary is "not a path character" rather than "a quote". A
     * quote-anchored pattern misses the dominant shell shape, which is an
     * UNQUOTED redirection or assignment: `sandbox/tests/offline/guards/regress_bundle_coverage.sh`
     * writes `2>/tmp/coverage_real.log` and reads it back, a fixed inode that
     * no amount of per-worker TMPDIR isolation can redirect, yet it scanned as
     * parallel-safe. Excluding `[A-Za-z0-9_./-]` before the literal is what
     * keeps `/var/tmp/`, `"$TMPDIR/tmp/"` and a relative `./tmp/` out: only a
     * genuinely absolute `/tmp/` counts, because only an absolute path escapes
     * the TMPDIR this runner exports.
     *
     * It over-matches a `mktemp /tmp/x.XXXXXX` TEMPLATE, which is collision-free
     * and does not need the serial group. That direction is deliberate: a false
     * serial costs wall time, a false parallel corrupts a suite's scratch file
     * and produces a failure nobody can reproduce.
     */
    private const HARDCODED_TMP_REGEX = '#(^|[^A-Za-z0-9_./-])/tmp/#';

    // ---------------------------------------------------------------- parsing

    /**
     * Parse `make -p` output into {target => prerequisites} and
     * {target => recipe lines}.
     *
     * Only the `# Files` section is considered: everything before it is the
     * variable dump and the built-in implicit-rule catalogue (`%:: SCCS/s.%`
     * and friends), which would otherwise be mistaken for targets. Within the
     * section make has already flattened backslash continuations onto one
     * line, which is the entire reason this is parsed instead of the Makefile.
     *
     * The first definition of a target wins; make prints per-target variable
     * blocks as comments, so nothing else can shadow it.
     *
     * @return array{prereqs: array<string, list<string>>, recipes: array<string, list<string>>}
     */
    public static function parseMakeDatabase(string $text): array
    {
        $prereqs = [];
        $recipes = [];
        $inFiles = false;
        $current = null;

        foreach (preg_split('/\R/', $text) as $line) {
            if (!$inFiles) {
                if (rtrim($line) === '# Files') {
                    $inFiles = true;
                }
                continue;
            }
            // The database ends where make starts dumping its hash-table and
            // VPATH statistics.
            if (str_starts_with($line, '# files hash-table stats:')
                || str_starts_with($line, '# VPATH Search Paths')
                || str_starts_with($line, '# Finished Make data base')) {
                break;
            }
            if ($line === '') {
                $current = null;
                continue;
            }
            if ($line[0] === "\t") {
                if ($current !== null) {
                    $recipes[$current][] = substr($line, 1);
                }
                continue;
            }
            if ($line[0] === '#' || $line[0] === ' ') {
                continue;
            }
            // `target: prereqs`, never `VAR := value` and never `VAR = value`.
            if (!preg_match('/^([^:=#\s][^:=]*):(?!=)(.*)$/', $line, $m)) {
                $current = null;
                continue;
            }
            $target = trim($m[1]);
            $rest = $m[2];
            // Double-colon rules leave a stray leading `:`.
            if ($rest !== '' && $rest[0] === ':') {
                $rest = substr($rest, 1);
            }
            $current = $target;
            if (!array_key_exists($target, $prereqs)) {
                $prereqs[$target] = preg_split('/\s+/', trim($rest), -1, PREG_SPLIT_NO_EMPTY) ?: [];
                $recipes[$target] = [];
            }
        }

        return ['prereqs' => $prereqs, 'recipes' => $recipes];
    }

    /**
     * Depth-first expansion of $root into its leaf targets, de-duplicated and
     * in make's own prerequisite order.
     *
     * A leaf is a target with no prerequisites in this closure. Aggregators
     * (`regress-offline-corpus`, `code-half-unit`) therefore drop out by
     * construction rather than by name, which keeps this correct if the
     * Makefile grows another intermediate bundle.
     *
     * @param array<string, list<string>> $prereqs
     * @return list<string>
     */
    public static function expandLeaves(array $prereqs, string $root): array
    {
        $seen = [];
        $leaves = [];
        $walk = static function (string $target) use (&$walk, $prereqs, &$seen, &$leaves): void {
            if (isset($seen[$target])) {
                return;
            }
            $seen[$target] = true;
            $children = $prereqs[$target] ?? [];
            if ($children === []) {
                $leaves[] = $target;
                return;
            }
            foreach ($children as $child) {
                $walk($child);
            }
        };
        $walk($root);

        return $leaves;
    }

    /**
     * Suite scripts named by a recipe, e.g.
     * `sandbox/tests/offline/<domain>/regress_x.sh`.
     *
     * Recipes may carry env prefixes and continuations; matching the path
     * shape is robust to both and does not require re-expanding make syntax.
     *
     * DUO-3482 assessed this regex for the sandbox-relative spelling the other
     * two path-aware tools were blind to -- a corpus file whose cwd is
     * `sandbox/` names a suite `tests/grind/x.sh`, and the rule for reading
     * that is stated in the block above `ms_sandbox_relative_tail()` in
     * tools/codemod/move-suites.php. This consumer CANNOT encounter it, so it
     * carries no code for it. Two independent reasons, both structural:
     *
     *   - The input is not file text. It is `$db['recipes'][$target]` from
     *     `make -pn`, i.e. Makefile recipe lines only (offline.php:607); the
     *     one place this tool reads suite BYTES is serialGroupEvidence()
     *     below, which greps them for `/tmp/…` and never for a suite path.
     *   - make runs every recipe with the cwd it was started in, the repo
     *     root, so a recipe spelled `bash tests/live/regress_x.sh` does not
     *     name a file this tool failed to see -- it names no file at all and
     *     the target dies at `No such file or directory`. Before it could even
     *     get that far, regress_suite_wiring.php refuses it: wiring_path_tokens()
     *     (:299-310) finds no `sandbox/tests/…` token in that recipe, so the
     *     suite it runs never enters $namedByRecipe and clause 4 (:607-611)
     *     reports "<file> is in a class directory but no recipe names it".
     *     OfflineRunnerTest pins that premise against the real Makefile.
     *
     * Adding the second needle here would therefore be dead code guarding a
     * state two other checks make unreachable, which is the kind of defensive
     * branch that later reads as evidence the state is possible.
     *
     * @param list<string> $recipeLines
     * @return list<string>
     */
    public static function scriptsInRecipe(array $recipeLines): array
    {
        $found = [];
        foreach ($recipeLines as $line) {
            if (preg_match_all('#sandbox/tests/[A-Za-z0-9_./-]+\.(?:sh|php)#', $line, $m)) {
                foreach ($m[0] as $path) {
                    $found[$path] = true;
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Does this suite need the mutually exclusive serial group?
     *
     * A suite qualifies when its source names an absolute `/tmp/...` path --
     * quoted or not, since `2>/tmp/x.log` is as fixed an inode as `'/tmp/x'`
     * and is the commoner shell spelling -- rather than deriving its scratch
     * from `mktemp`/`sys_get_temp_dir()`, which honour the per-worker TMPDIR.
     * Two such suites running at once would share the same inode and
     * corrupt each other; that is a property of the suite, not of this runner,
     * so it is detected by scanning rather than by maintaining a hand-written
     * deny-list that would rot. A `regress_*.sh` file is usually a lint+run
     * wrapper around a same-basename `regress_*.php`, so the companion is
     * scanned too -- otherwise the wrapper looks clean while the real work
     * collides.
     *
     * Serial-group suites still overlap freely with ordinary suites; only
     * serial-against-serial is forbidden.
     *
     * @param list<string> $scripts repo-relative paths
     */
    public static function needsSerialGroup(string $repoRoot, array $scripts): bool
    {
        foreach (self::serialGroupEvidence($repoRoot, $scripts) as $unused) {
            return true;
        }

        return false;
    }

    /**
     * Which files made a suite serial, for `--explain`.
     *
     * @param list<string> $scripts
     * @return list<string>
     */
    public static function serialGroupEvidence(string $repoRoot, array $scripts): array
    {
        $candidates = [];
        foreach ($scripts as $script) {
            $candidates[$script] = true;
            if (str_ends_with($script, '.sh')) {
                $candidates[substr($script, 0, -3) . '.php'] = true;
            }
        }

        $hits = [];
        foreach (array_keys($candidates) as $rel) {
            $abs = $repoRoot . '/' . $rel;
            if (!is_file($abs)) {
                continue;
            }
            $body = @file_get_contents($abs);
            if ($body !== false && preg_match(self::HARDCODED_TMP_REGEX, $body) === 1) {
                $hits[] = $rel;
            }
        }

        return $hits;
    }

    // ----------------------------------------------------------- diagnostics

    /**
     * Lines of $output the offline diagnostics guard would reject, formatted
     * `<lineno>:<line>` exactly as `grep -En` renders them.
     *
     * Records are split on LF and on nothing else, because that is the only
     * separator `grep -En` knows. `preg_split('/\R/')` also splits on CR,
     * CRLF, VT, FF, NEL and U+2028/2029: a suite drawing a progress bar with a
     * bare `\r` would then be failed HERE while `make regress-offline-all`
     * stays green -- a runner-only false failure -- and every `<lineno>:`
     * prefix after such a byte would disagree with what the guard prints. A
     * trailing LF closes the final record rather than opening an empty one,
     * which is grep's counting too.
     *
     * @return list<string>
     */
    public static function diagnosticLines(string $output): array
    {
        $lines = explode("\n", $output);
        $last = count($lines) - 1;
        if ($lines[$last] === '') {
            unset($lines[$last]);
        }

        $hits = [];
        $lineNo = 0;
        foreach ($lines as $line) {
            $lineNo++;
            if (preg_match(self::DIAGNOSTIC_REGEX, $line) === 1) {
                $hits[] = $lineNo . ':' . $line;
            }
        }

        return $hits;
    }

    // ----------------------------------------------------------- run records

    /**
     * Fold a finished run into the previously recorded one.
     *
     * `last-run.json` is what `--rerun-failed` reads, so it must be the record
     * of every target whose latest observed status is known -- not a transcript
     * of whichever slice the last invocation happened to select. Replacing it
     * wholesale loses failures: a full run reports 5 red suites, the developer
     * iterates with `--filter=one-suite`, that green partial run overwrites the
     * file with its single row, and the next `--rerun-failed` reports "nothing
     * to rerun" while five suites are still broken. Merging by target instead
     * makes `--rerun-failed` after any number of partial runs select the union
     * of everything still failing. (writeDurations() merges for the same
     * reason; this is that policy applied to the half that gates a decision.)
     *
     * A run whose `selection` is `full` DOES replace: every live target is
     * present in it, so preserving older rows would only resurrect targets the
     * Makefile has since dropped.
     *
     * The summary is recomputed over the merged rows, since totals that
     * described one slice would be a lie about the document they head. The
     * timing fields still describe the invocation that produced them, and
     * `ran_this_invocation` says how many of the rows it actually touched.
     *
     * @param array<mixed>|null $prior document read back from last-run.json
     * @param array<string,mixed> $fresh this run's document
     * @return array<string,mixed>
     */
    public static function mergeRunDocuments(?array $prior, array $fresh): array
    {
        $rows = [];
        if (($fresh['selection'] ?? null) !== 'full' && $prior !== null) {
            $priorRows = $prior['targets'] ?? null;
            if (is_array($priorRows)) {
                foreach ($priorRows as $row) {
                    if (is_array($row) && isset($row['target']) && is_string($row['target'])) {
                        $rows[$row['target']] = $row;
                    }
                }
            }
        }

        $ran = 0;
        $freshRows = $fresh['targets'] ?? null;
        if (is_array($freshRows)) {
            foreach ($freshRows as $row) {
                if (is_array($row) && isset($row['target']) && is_string($row['target'])) {
                    $rows[$row['target']] = $row;
                    $ran++;
                }
            }
        }
        ksort($rows);

        $passed = 0;
        foreach ($rows as $row) {
            if (($row['status'] ?? null) === 'ok') {
                $passed++;
            }
        }

        $summary = $fresh['summary'] ?? null;
        if (!is_array($summary)) {
            $summary = [];
        }
        $summary['total'] = count($rows);
        $summary['passed'] = $passed;
        $summary['failed'] = count($rows) - $passed;
        $summary['ran_this_invocation'] = $ran;

        $merged = $fresh;
        $merged['targets'] = array_values($rows);
        $merged['summary'] = $summary;

        return $merged;
    }

    // -------------------------------------------------------------- ordering

    /**
     * Longest-Processing-Time-first ordering.
     *
     * With a fixed worker count the makespan is dominated by whatever long pole
     * gets started last, so the cached-slowest suites must be dispatched first.
     * On this corpus the poles are extreme: regress_pair_bootstrap_unit.sh at
     * 74 s and regress_bundle_coverage.sh at 37 s against a 0.10 s median, so
     * starting them late costs more than everything else combined.
     *
     * Suites with no cached duration get the median of the known ones (or 0.0
     * when nothing is cached, which makes a cold first run keep make's own
     * order). Placing unknowns at the median rather than at 0 stops a
     * newly-added slow suite from being permanently scheduled last.
     *
     * Ties keep input order: PHP's sort has been stable since 8.0, so the
     * result is deterministic and a cold run is reproducible.
     *
     * @param list<string> $targets
     * @param array<string, float> $durations
     * @return list<string>
     */
    public static function lptOrder(array $targets, array $durations, ?float $unknownWeight = null): array
    {
        if ($unknownWeight === null) {
            $known = [];
            foreach ($targets as $target) {
                if (isset($durations[$target])) {
                    $known[] = (float) $durations[$target];
                }
            }
            sort($known);
            $count = count($known);
            $unknownWeight = $count === 0
                ? 0.0
                : ($count % 2 === 1 ? $known[intdiv($count, 2)] : ($known[$count / 2 - 1] + $known[$count / 2]) / 2.0);
        }

        $ordered = $targets;
        usort($ordered, static function (string $a, string $b) use ($durations, $unknownWeight): int {
            $wa = isset($durations[$a]) ? (float) $durations[$a] : $unknownWeight;
            $wb = isset($durations[$b]) ? (float) $durations[$b] : $unknownWeight;

            return $wb <=> $wa;
        });

        return array_values($ordered);
    }
}

/**
 * Everything that touches the filesystem, processes, or argv.
 *
 * Kept separate from OfflineRunner so the pure halves above stay unit-testable
 * without a repo checkout.
 */
final class OfflineRunnerCli
{
    private string $repoRoot;
    private string $stateDir;
    private string $logDir;
    private string $tmpRoot;
    private string $make;

    /**
     * `full` when the selection covered every leaf target, else `partial`.
     * Recorded in last-run.json because it is what tells the next merge
     * whether older rows are still meaningful -- see
     * OfflineRunner::mergeRunDocuments().
     */
    private string $selectionMode = 'full';

    /**
     * Set when an EMPTY selection is the correct answer rather than a mistake,
     * to the sentence explaining which. `--changed` over a docs-only diff and
     * `--rerun-failed` with nothing red are both "no work, and that is
     * correct"; an empty `--filter` is a typo and keeps exit 2.
     */
    private ?string $emptySelectionIsBenign = null;

    /** @var array<string,mixed> */
    private array $opt;

    public function __construct(string $repoRoot)
    {
        $this->repoRoot = $repoRoot;
        $this->stateDir = $repoRoot . '/sandbox/tmp/offline-runner';
        $this->logDir = $this->stateDir . '/logs';
        // Per-worker TMPDIRs deliberately live OUTSIDE the worktree, not under
        // sandbox/tmp/. Measured: with TMPDIR inside the repo, four suites fail
        // that pass serially -- regress-environment-lifecycle is the clearest,
        // because cli/src/Environment/Registry.php refuses "a nested site.duo.json outside
        // Git worktree root", which is true of any scratch tree placed inside
        // the checkout. That is a real property of the code under test, not a
        // harness bug, so isolation moves rather than switching off. Keyed by
        // the repo path so two clones never share a worker slot.
        $tmp = getenv('TMPDIR');
        $systemTmp = ($tmp === false || $tmp === '') ? sys_get_temp_dir() : rtrim($tmp, '/');
        $this->tmpRoot = $systemTmp . '/duo-offline-runner-' . substr(sha1($repoRoot), 0, 12);
        $make = getenv('MAKE');
        $this->make = ($make === false || $make === '') ? 'make' : $make;
        $this->opt = [];
    }

    /** @param list<string> $argv */
    public function run(array $argv): int
    {
        try {
            $this->opt = $this->parseArgs(array_slice($argv, 1));
        } catch (\InvalidArgumentException $e) {
            fwrite(STDERR, 'tools/offline.php: ' . $e->getMessage() . "\n");

            return 2;
        }
        if ($this->opt['help']) {
            echo $this->usage();

            return 0;
        }

        $quiet = $this->opt['json_stdout'];

        $db = $this->loadMakeDatabase();
        $leaves = OfflineRunner::expandLeaves($db['prereqs'], OfflineRunner::ROOT_TARGET);
        if ($leaves === []) {
            fwrite(STDERR, 'tools/offline.php: make database yielded no leaf targets for '
                . OfflineRunner::ROOT_TARGET . " -- parser drift?\n");

            return 2;
        }
        $expectedLeaves = OfflineRunner::expectedLeaves($this->repoRoot);
        if ($expectedLeaves !== null && count($leaves) !== $expectedLeaves) {
            fwrite(STDERR, sprintf(
                'tools/offline.php: NOTICE leaf count is %d, but the Makefile status line says %d '
                . "(regress_bundle_coverage.sh will say which is right)\n",
                count($leaves),
                $expectedLeaves
            ));
        }

        /** @var array<string, list<string>> $scripts */
        $scripts = [];
        /** @var array<string, list<string>> $serialEvidence */
        $serialEvidence = [];
        foreach ($leaves as $target) {
            $scripts[$target] = OfflineRunner::scriptsInRecipe($db['recipes'][$target] ?? []);
            $serialEvidence[$target] = OfflineRunner::serialGroupEvidence($this->repoRoot, $scripts[$target]);
        }

        $selected = $this->select($leaves);
        if ($selected === null) {
            return 2;
        }
        // Compared against the full leaf list rather than inferred from which
        // flags were passed: a --filter that happens to match everything really
        // did cover the corpus, and a partial run must never claim otherwise.
        $this->selectionMode = $selected === $leaves ? 'full' : 'partial';

        if ($this->opt['list']) {
            foreach ($selected as $target) {
                if ($this->opt['verbose'] || $this->opt['explain']) {
                    printf(
                        "%-56s %-7s %s\n",
                        $target,
                        $serialEvidence[$target] !== [] ? 'serial' : '-',
                        $serialEvidence[$target] !== []
                            ? implode(', ', $serialEvidence[$target])
                            : implode(', ', $scripts[$target])
                    );
                } else {
                    echo $target, "\n";
                }
            }
            if ($this->opt['verbose'] || $this->opt['explain']) {
                $serialCount = count(array_filter($serialEvidence, static fn (array $e): bool => $e !== []));
                fwrite(STDERR, sprintf(
                    "tools/offline.php: %d selected of %d leaf targets; %d in the serial group\n",
                    count($selected),
                    count($leaves),
                    $serialCount
                ));
            }

            return 0;
        }

        if (!$quiet) {
            printf(
                "tools/offline.php: %d leaf targets from %s (%d selected)\n",
                count($leaves),
                OfflineRunner::ROOT_TARGET,
                count($selected)
            );
        }
        /** @var list<array{target: string, argv: list<string>, serial: bool}> $tasks */
        $tasks = [];
        $durations = $this->loadDurations();
        foreach (OfflineRunner::lptOrder($selected, $durations) as $target) {
            $tasks[] = [
                'target' => $target,
                'argv' => [$this->make, '--no-print-directory', $target],
                'serial' => $serialEvidence[$target] !== [],
            ];
        }
        foreach ($this->extraTasks() as $extra) {
            $tasks[] = $extra;
        }
        if ($tasks === []) {
            if ($this->emptySelectionIsBenign === null) {
                fwrite(STDERR, "tools/offline.php: nothing selected\n");

                return 2;
            }
            // A correct empty answer, reported as a green zero-suite run so
            // that `php tools/offline.php --changed && git push` survives a
            // README edit. report() still runs: it refreshes last-run.json
            // through the merge, which is what keeps a previous run's failures
            // visible to the next --rerun-failed.
            $this->prepareStateDirs(0);
            $notice = 'tools/offline.php: ' . $this->emptySelectionIsBenign . "\n";
            if ($quiet) {
                fwrite(STDERR, $notice);
            } else {
                echo $notice;
            }

            return $this->report([], 0.0, (int) $this->opt['jobs']);
        }

        return $this->execute($tasks, $durations);
    }

    // ----------------------------------------------------------------- argv

    /**
     * @param list<string> $args
     * @return array<string,mixed>
     */
    private function parseArgs(array $args): array
    {
        $opt = [
            'jobs' => 0,
            'list' => false,
            'explain' => false,
            'verbose' => false,
            'serial' => false,
            'slowest' => 0,
            'json_file' => null,
            'json_stdout' => false,
            'rerun_failed' => false,
            'filter' => [],
            'changed' => false,
            'changed_base' => null,
            'extras' => false,
            'tmpdir_isolation' => true,
            'help' => false,
        ];

        for ($i = 0; $i < count($args); $i++) {
            $arg = $args[$i];
            if ($arg === '--help' || $arg === '-h') {
                $opt['help'] = true;
            } elseif ($arg === '--list') {
                $opt['list'] = true;
            } elseif ($arg === '--explain') {
                $opt['explain'] = true;
            } elseif ($arg === '--verbose' || $arg === '-v') {
                $opt['verbose'] = true;
            } elseif ($arg === '--serial') {
                $opt['serial'] = true;
            } elseif ($arg === '--extras') {
                $opt['extras'] = true;
            } elseif ($arg === '--rerun-failed') {
                $opt['rerun_failed'] = true;
            } elseif ($arg === '--no-tmpdir-isolation') {
                $opt['tmpdir_isolation'] = false;
            } elseif ($arg === '--slowest') {
                $opt['slowest'] = 20;
            } elseif (str_starts_with($arg, '--slowest=')) {
                $opt['slowest'] = max(1, (int) substr($arg, 10));
            } elseif ($arg === '--json') {
                $opt['json_stdout'] = true;
            } elseif (str_starts_with($arg, '--json=')) {
                $opt['json_file'] = substr($arg, 7);
            } elseif (str_starts_with($arg, '--filter=')) {
                $opt['filter'] = preg_split('/\s*,\s*/', substr($arg, 9), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            } elseif ($arg === '--changed') {
                $opt['changed'] = true;
            } elseif (str_starts_with($arg, '--changed=')) {
                $opt['changed'] = true;
                $opt['changed_base'] = substr($arg, 10);
            } elseif ($arg === '-j' || $arg === '--jobs') {
                if (!isset($args[$i + 1])) {
                    throw new \InvalidArgumentException('-j requires a number');
                }
                $opt['jobs'] = max(1, (int) $args[++$i]);
            } elseif (str_starts_with($arg, '-j')) {
                $opt['jobs'] = max(1, (int) substr($arg, 2));
            } elseif (str_starts_with($arg, '--jobs=')) {
                $opt['jobs'] = max(1, (int) substr($arg, 7));
            } else {
                throw new \InvalidArgumentException("unknown option: $arg (try --help)");
            }
        }

        if ($opt['serial']) {
            $opt['jobs'] = 1;
        }
        if ($opt['jobs'] < 1) {
            $opt['jobs'] = $this->detectCpus();
        }

        return $opt;
    }

    private function usage(): string
    {
        return <<<TXT
usage: php tools/offline.php [options]

Runs every leaf target of `make regress-offline-corpus` in parallel, one
process per suite, and fails a suite on a non-zero exit OR on any PHP
diagnostic that sandbox/tests/offline_diagnostics_guard.sh would reject.

  -j N, --jobs=N        worker count (default: detected CPU count)
  --serial              equivalent to -j1
  --list                print the selected targets, one per line, and exit
  --explain             with --list, also show serial-group membership
  --filter=a,b          only targets whose name contains one of these
  --rerun-failed        every target whose LAST RECORDED result was a failure
                        (last-run.json is merged, so a green partial run in
                        between does not erase an earlier failure)
  --changed[=BASE]      delegate selection to `php tools/affected.php --base=BASE`
                        (a diff that affects no offline suite says so and
                        exits 0; an empty --filter stays exit 2)
  --extras              also run check_guide_commands.sh and, if installed,
                        vendor/bin/phpunit as extra pseudo-suites
  --slowest[=N]         print the N slowest suites (default 20)
  --verbose             print full logs of failures (not just the tail)
  --json[=FILE]         write machine-readable results; bare --json writes the
                        JSON document to stdout INSTEAD of the human report
  --no-tmpdir-isolation do not give each worker its own TMPDIR
  -h, --help            this text

State lives in sandbox/tmp/offline-runner/ (gitignored): logs/<target>.log,
durations.json (LPT scheduling cache) and last-run.json -- the latter two are
merged per target across runs, never replaced. Per-worker TMPDIRs live under
the system temp dir, never inside the checkout: several suites refuse a
scratch tree located inside the git worktree.

TXT;
    }

    private function detectCpus(): int
    {
        foreach ([['nproc'], ['sysctl', '-n', 'hw.ncpu']] as $probe) {
            $out = @shell_exec(implode(' ', array_map('escapeshellarg', $probe)) . ' 2>/dev/null');
            if (is_string($out) && (int) trim($out) > 0) {
                return (int) trim($out);
            }
        }

        return 4;
    }

    // ------------------------------------------------------------ selection

    /**
     * @param list<string> $leaves
     * @return list<string>|null null on a fatal selection error
     */
    private function select(array $leaves): ?array
    {
        $selected = $leaves;

        if ($this->opt['rerun_failed']) {
            $last = $this->readJsonFile($this->stateDir . '/last-run.json');
            if ($last === null) {
                fwrite(STDERR, 'tools/offline.php: no previous run recorded ('
                    . $this->stateDir . "/last-run.json missing)\n");

                return null;
            }
            // last-run.json is merged, never replaced (see
            // OfflineRunner::mergeRunDocuments()), so these rows are the union
            // of every target whose latest observed status is a failure --
            // including ones a later partial run did not select. That is what
            // makes this flag safe to script.
            $failed = [];
            $rows = $last['targets'] ?? null;
            foreach (is_array($rows) ? $rows : [] as $row) {
                if (is_array($row) && ($row['status'] ?? '') === 'fail' && isset($row['target'])) {
                    $failed[] = (string) $row['target'];
                }
            }
            if ($failed === []) {
                // Genuinely nothing red anywhere, not merely nothing red in the
                // last slice. Handled by the caller (which prints through the
                // --json-aware reporter) rather than by an exit() here, because
                // a bare echo corrupts the machine-readable document.
                $this->emptySelectionIsBenign = 'previous run had no failures -- nothing to rerun';

                return [];
            }
            $selected = array_values(array_intersect($selected, $failed));
        }

        if ($this->opt['changed']) {
            $affected = $this->repoRoot . '/tools/affected.php';
            if (!is_file($affected)) {
                fwrite(STDERR, "tools/offline.php: --changed needs tools/affected.php, which does not exist yet.\n"
                    . "tools/offline.php: it is a separate work package; run without --changed, or use --filter=.\n");

                return null;
            }
            $cmd = [PHP_BINARY, $affected];
            if ($this->opt['changed_base'] !== null) {
                $cmd[] = '--base=' . $this->opt['changed_base'];
            }
            $result = $this->captureCommand($cmd);
            if ($result['exit'] !== 0) {
                fwrite(STDERR, "tools/offline.php: tools/affected.php failed (exit {$result['exit']}):\n"
                    . $result['output'] . "\n");

                return null;
            }
            // One target per line on stdout; anything with whitespace in it is
            // prose, not a target, so it is dropped rather than guessed at.
            $names = [];
            foreach (preg_split('/\R/', trim($result['stdout']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $line) {
                $line = trim($line);
                if ($line !== '' && !preg_match('/\s/', $line)) {
                    $names[] = $line;
                }
            }
            $unknown = array_values(array_diff($names, $leaves));
            if ($unknown !== []) {
                fwrite(STDERR, 'tools/offline.php: NOTICE tools/affected.php named '
                    . count($unknown) . ' target(s) outside the offline corpus, ignoring: '
                    . implode(' ', array_slice($unknown, 0, 8)) . "\n");
            }
            $selected = array_values(array_intersect($selected, $names));
            if ($selected === []) {
                // A docs-only, tooling-only or .gitignore-only diff really does
                // affect no offline suite. That is the selector answering
                // correctly, so it must not look like a failure to a pre-push
                // hook or an `&&` chain.
                $this->emptySelectionIsBenign = 'no affected offline suites for this diff';
            }
        }

        if ($this->opt['filter'] !== []) {
            $before = $selected;
            $selected = array_values(array_filter($selected, function (string $target): bool {
                foreach ($this->opt['filter'] as $needle) {
                    if (str_contains($target, $needle)) {
                        return true;
                    }
                }

                return false;
            }));
            if ($selected === [] && $before !== []) {
                // A filter that matches nothing is a typo or stale state, never
                // a legitimate "no work": keep exit 2 even if an earlier stage
                // had excused an empty set.
                $this->emptySelectionIsBenign = null;
            }
        }

        return $selected;
    }

    /** @return list<array{target: string, argv: list<string>, serial: bool}> */
    private function extraTasks(): array
    {
        if (!$this->opt['extras']) {
            return [];
        }
        $extras = [];
        $guide = $this->repoRoot . '/sandbox/tests/spike/check_guide_commands.sh';
        if (is_file($guide)) {
            $extras[] = [
                'target' => 'extras-check-guide-commands',
                'argv' => ['bash', 'sandbox/tests/spike/check_guide_commands.sh'],
                'serial' => false,
            ];
        } else {
            fwrite(STDERR, "tools/offline.php: NOTICE --extras: sandbox/tests/spike/check_guide_commands.sh missing, skipped\n");
        }
        if (is_file($this->repoRoot . '/vendor/bin/phpunit')) {
            $extras[] = [
                'target' => 'extras-phpunit',
                'argv' => ['vendor/bin/phpunit'],
                'serial' => false,
            ];
        } else {
            fwrite(STDERR, "tools/offline.php: NOTICE --extras: vendor/bin/phpunit not installed, skipped\n");
        }

        return $extras;
    }

    // -------------------------------------------------------------- make db

    /** @return array{prereqs: array<string, list<string>>, recipes: array<string, list<string>>} */
    private function loadMakeDatabase(): array
    {
        $result = $this->captureCommand(
            [$this->make, '-pn', '--no-print-directory', OfflineRunner::ROOT_TARGET]
        );
        // `make -pn` on a fully phony bundle exits 0; a non-zero exit here means
        // the Makefile itself is broken, which is worth reporting rather than
        // silently producing an empty work list.
        if ($result['exit'] !== 0 && !str_contains($result['output'], '# Files')) {
            fwrite(STDERR, "tools/offline.php: `{$this->make} -pn --no-print-directory "
                . OfflineRunner::ROOT_TARGET . "` failed:\n" . $result['output'] . "\n");
            exit(2);
        }

        return OfflineRunner::parseMakeDatabase($result['output']);
    }

    /**
     * Run $argv to completion and return its two output streams separately.
     *
     * WHY THE STREAMS STAY APART. tools/affected.php's contract is "one target
     * name per line on stdout", and it uses stderr for its `no suite covers:
     * <path>` notices. Folding the two together would turn those notices into
     * bogus target names.
     *
     * WHY BOTH PIPES ARE DRAINED CONCURRENTLY. Reading pipe 1 to EOF before
     * touching pipe 2 deadlocks the moment a child fills the stderr pipe
     * buffer (64 KB on this platform, measured: an identical proc_open shape
     * completes at 65536 bytes of stderr and hangs indefinitely at 70000)
     * while its stdout is still open -- the child blocks in write(2) on fd 2,
     * the parent blocks in read(2) on fd 1, and neither can move. That is not
     * hypothetical here: `php tools/affected.php --base=<root commit>` already
     * emits ~25 KB of stderr notices BEFORE its first byte of stdout, and that
     * volume grows with every uncovered file added to the repo. `php
     * tools/offline.php --changed` is the documented iteration command, so the
     * failure mode would be a hang with no output and no timeout.
     *
     * stream_select() over both non-blocking pipes removes the ordering
     * dependency entirely. If the selector itself errors (EINTR and friends),
     * the loop stops and finishes with the blocking read: no worse than the
     * behaviour this replaces, and it cannot spin.
     *
     * @param list<string> $argv
     * @return array{exit: int, stdout: string, stderr: string, output: string}
     */
    private function captureCommand(array $argv): array
    {
        $desc = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($argv, $desc, $pipes, $this->repoRoot, $this->childEnv(null));
        if (!is_resource($proc)) {
            $why = 'could not spawn ' . implode(' ', $argv);

            return ['exit' => 127, 'stdout' => '', 'stderr' => $why, 'output' => $why];
        }

        $buffers = [1 => '', 2 => ''];
        $open = [1 => $pipes[1], 2 => $pipes[2]];
        foreach ($open as $stream) {
            stream_set_blocking($stream, false);
        }
        while ($open !== []) {
            $read = array_values($open);
            $write = null;
            $except = null;
            $ready = @stream_select($read, $write, $except, 1, 0);
            if ($ready === false) {
                break;
            }
            if ($ready === 0) {
                continue;
            }
            foreach ($read as $stream) {
                $fd = array_search($stream, $open, true);
                if ($fd === false) {
                    continue;
                }
                $chunk = fread($stream, 65536);
                if (is_string($chunk) && $chunk !== '') {
                    $buffers[$fd] .= $chunk;
                    continue;
                }
                if (feof($stream)) {
                    unset($open[$fd]);
                }
            }
        }
        // Anything still open only gets here via a stream_select() error; a
        // plain blocking read is then the best remaining option.
        foreach ($open as $fd => $stream) {
            stream_set_blocking($stream, true);
            $buffers[$fd] .= (string) stream_get_contents($stream);
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($proc);

        return [
            'exit' => $exit,
            'stdout' => $buffers[1],
            'stderr' => $buffers[2],
            'output' => $buffers[1] . $buffers[2],
        ];
    }

    /**
     * Child environment.
     *
     * MAKEFLAGS/MAKELEVEL are stripped so a sub-make never believes it is a
     * recursive child of a jobserver this script does not run; TMPDIR is
     * per-worker so suites that call mktemp/sys_get_temp_dir() cannot collide
     * on a shared scratch name. The per-worker directory is a sibling of the
     * system temp dir, never a path inside the checkout -- see the constructor.
     *
     * @return array<string,string>
     */
    private function childEnv(?string $tmpdir): array
    {
        $env = getenv();
        unset($env['MAKEFLAGS'], $env['MAKELEVEL'], $env['MFLAGS']);
        if ($tmpdir !== null) {
            $env['TMPDIR'] = $tmpdir;
        }

        return array_map('strval', $env);
    }

    // ------------------------------------------------------------ execution

    /**
     * @param list<array{target: string, argv: list<string>, serial: bool}> $tasks
     * @param array<string,float> $durations
     */
    private function execute(array $tasks, array $durations): int
    {
        $quiet = $this->opt['json_stdout'];
        $jobs = min((int) $this->opt['jobs'], count($tasks));
        $this->prepareStateDirs($jobs);

        if (!$quiet) {
            printf(
                "tools/offline.php: running %d suites with %d worker(s)%s\n\n",
                count($tasks),
                $jobs,
                $this->opt['tmpdir_isolation'] ? ', per-worker TMPDIR' : ', shared TMPDIR'
            );
        }

        $queue = $tasks;
        /** @var array<int, array<string,mixed>> $slots */
        $slots = [];
        $serialRunning = false;
        $results = [];
        $wallStart = microtime(true);

        while ($queue !== [] || $slots !== []) {
            // Dispatch: scan the queue for the first task allowed to start now.
            while (count($slots) < $jobs && $queue !== []) {
                $picked = null;
                foreach ($queue as $idx => $task) {
                    if ($task['serial'] && $serialRunning) {
                        continue;
                    }
                    $picked = $idx;
                    break;
                }
                if ($picked === null) {
                    break; // only serial work left and a serial suite is running
                }
                $task = $queue[$picked];
                unset($queue[$picked]);
                $queue = array_values($queue);
                $slot = $this->freeSlot($slots, $jobs);
                $started = $this->spawn($task, $slot);
                if ($started === null) {
                    $results[] = [
                        'target' => $task['target'],
                        'status' => 'fail',
                        'exit' => 127,
                        'seconds' => 0.0,
                        'diagnostics' => ['could not spawn ' . implode(' ', $task['argv'])],
                        'log' => null,
                    ];
                    continue;
                }
                $slots[$slot] = $started;
                if ($task['serial']) {
                    $serialRunning = true;
                }
            }

            if ($slots === []) {
                // Defensive: with no worker running nothing can be blocking a
                // serial pick, so an empty pool plus a non-empty queue would be
                // a scheduler bug, not a wait state. Fail loud instead of
                // spinning.
                if ($queue !== []) {
                    fwrite(STDERR, 'tools/offline.php: scheduler deadlock with '
                        . count($queue) . " task(s) queued\n");
                    // Every queued task is recorded as a failure, not merely
                    // logged: report() derives the exit code from $results
                    // alone, so breaking out silently would let a run that
                    // never executed part of the corpus print "N/N offline
                    // suites green" and exit 0 -- a green gate for work that
                    // did not happen. Exit -1 marks "never dispatched", which
                    // no child can produce, and lands the target in
                    // last-run.json so --rerun-failed picks it back up.
                    foreach ($queue as $task) {
                        $results[] = [
                            'target' => $task['target'],
                            'status' => 'fail',
                            'exit' => -1,
                            'seconds' => 0.0,
                            'diagnostics' => ['not dispatched: scheduler deadlock'],
                            'log' => null,
                        ];
                    }
                    $queue = [];
                    break;
                }
                continue;
            }

            // Non-blocking poll. 5 ms costs nothing against a 0.10 s median
            // suite and keeps a freed worker from idling measurably.
            usleep(5000);
            foreach ($slots as $slot => $running) {
                $status = proc_get_status($running['proc']);
                if ($status['running']) {
                    continue;
                }
                $exit = (int) $status['exitcode'];
                fclose($running['handle']);
                proc_close($running['proc']);
                unset($slots[$slot]);
                if ($running['serial']) {
                    $serialRunning = false;
                }
                $seconds = microtime(true) - $running['started'];
                $output = (string) @file_get_contents($running['log']);
                $diagnostics = OfflineRunner::diagnosticLines($output);
                $ok = $exit === 0 && $diagnostics === [];
                $results[] = [
                    'target' => $running['target'],
                    'status' => $ok ? 'ok' : 'fail',
                    'exit' => $exit,
                    'seconds' => round($seconds, 3),
                    'diagnostics' => $diagnostics,
                    'log' => $running['log'],
                ];
                $durations[$running['target']] = round($seconds, 3);
                if (!$quiet) {
                    echo $this->progressLine($ok, $seconds, $running['target'], $exit, $diagnostics);
                    flush();
                }
            }
        }

        $wall = microtime(true) - $wallStart;
        $this->writeDurations($durations);

        return $this->report($results, $wall, $jobs);
    }

    /**
     * @param array<int, array<string,mixed>> $slots
     */
    private function freeSlot(array $slots, int $jobs): int
    {
        for ($i = 0; $i < $jobs; $i++) {
            if (!isset($slots[$i])) {
                return $i;
            }
        }

        return 0; // unreachable: callers check count($slots) < $jobs first
    }

    /**
     * @param array{target: string, argv: list<string>, serial: bool} $task
     * @return array<string,mixed>|null
     */
    private function spawn(array $task, int $slot): ?array
    {
        $log = $this->logDir . '/' . $task['target'] . '.log';
        // ONE handle passed as both fd 1 and fd 2 so the child shares a single
        // file offset -- byte-identical to the guard's `>"$OUT" 2>&1`, and
        // therefore identically ordered. Two separate ['file', ...] descriptors
        // would each own an offset and clobber each other.
        $handle = @fopen($log, 'w');
        if ($handle === false) {
            return null;
        }
        $tmpdir = $this->opt['tmpdir_isolation'] ? $this->tmpRoot . '/worker-' . $slot : null;
        $proc = @proc_open(
            $task['argv'],
            [0 => ['file', '/dev/null', 'r'], 1 => $handle, 2 => $handle],
            $pipes,
            $this->repoRoot,
            $this->childEnv($tmpdir)
        );
        if (!is_resource($proc)) {
            fclose($handle);

            return null;
        }

        return [
            'proc' => $proc,
            'handle' => $handle,
            'target' => $task['target'],
            'serial' => $task['serial'],
            'log' => $log,
            'started' => microtime(true),
        ];
    }

    /** @param list<string> $diagnostics */
    private function progressLine(bool $ok, float $seconds, string $target, int $exit, array $diagnostics): string
    {
        if ($ok) {
            return sprintf("ok    %6.2fs %s\n", $seconds, $target);
        }
        $why = [];
        if ($exit !== 0) {
            $why[] = 'exit ' . $exit;
        }
        if ($diagnostics !== []) {
            $why[] = 'php diagnostics';
        }

        return sprintf("FAIL  %6.2fs %s (%s)\n", $seconds, $target, implode('; ', $why));
    }

    // --------------------------------------------------------------- report

    /** @param list<array<string,mixed>> $results */
    private function report(array $results, float $wall, int $jobs): int
    {
        $passed = 0;
        $failed = [];
        $sum = 0.0;
        foreach ($results as $row) {
            $sum += (float) $row['seconds'];
            if ($row['status'] === 'ok') {
                $passed++;
            } else {
                $failed[] = $row;
            }
        }
        $total = count($results);

        $document = [
            // Which slice of the corpus this invocation covered. The merge in
            // writeLastRun() reads it back to decide whether rows it is not
            // replacing are still meaningful.
            'selection' => $this->selectionMode,
            'targets' => $results,
            'summary' => [
                'total' => $total,
                'passed' => $passed,
                'failed' => count($failed),
                'wall_seconds' => round($wall, 3),
                'suite_seconds' => round($sum, 3),
                'effective_parallelism' => $wall > 0.0 ? round($sum / $wall, 2) : 0.0,
                'workers' => $jobs,
                'tmpdir_isolation' => (bool) $this->opt['tmpdir_isolation'],
                'generated_at' => gmdate('c'),
            ],
        ];
        // The emitted document describes THIS run; only the on-disk record is
        // merged with previous ones, because a --json consumer asked what just
        // happened, not what is known about the corpus.
        $json = $this->encodeJson($document);
        $this->writeLastRun($document);
        if ($json !== null && $this->opt['json_file'] !== null) {
            @file_put_contents((string) $this->opt['json_file'], $json);
        }
        if ($json !== null && $this->opt['json_stdout']) {
            echo $json;

            return count($failed) === 0 ? 0 : 1;
        }

        echo "\n";
        printf(
            "summary: %d passed, %d failed of %d in %.2fs wall (%.2fs of suite time, %.1fx effective parallelism on %d workers)\n",
            $passed,
            count($failed),
            $total,
            $wall,
            $sum,
            $wall > 0.0 ? $sum / $wall : 0.0,
            $jobs
        );

        if ($this->opt['slowest'] > 0) {
            $slow = $results;
            usort($slow, static fn (array $a, array $b): int => $b['seconds'] <=> $a['seconds']);
            $n = min((int) $this->opt['slowest'], count($slow));
            printf("\nslowest %d suites:\n", $n);
            for ($i = 0; $i < $n; $i++) {
                printf("  %7.2fs %s\n", $slow[$i]['seconds'], $slow[$i]['target']);
            }
        }

        foreach ($failed as $row) {
            printf("\n--- FAIL %s (exit %d)\n", $row['target'], $row['exit']);
            if ($row['diagnostics'] !== []) {
                // A row with no log never produced suite output at all (spawn
                // failure, or never dispatched), so its `diagnostics` carry the
                // reason rather than grep hits -- labelling those as guard
                // rejections would send the reader looking for a PHP error that
                // was never printed.
                echo $row['log'] === null
                    ? "    the suite did not run:\n"
                    : "    php diagnostics rejected by offline_diagnostics_guard.sh's regex:\n";
                foreach ($row['diagnostics'] as $line) {
                    echo '      ', $line, "\n";
                }
            }
            if ($row['log'] !== null && is_file((string) $row['log'])) {
                $lines = preg_split('/\R/', rtrim((string) file_get_contents((string) $row['log']), "\n")) ?: [];
                $tail = $this->opt['verbose'] ? $lines : array_slice($lines, -40);
                if (!$this->opt['verbose'] && count($lines) > 40) {
                    printf("    (last 40 of %d lines)\n", count($lines));
                }
                foreach ($tail as $line) {
                    echo '    | ', $line, "\n";
                }
                echo '    log: ', $row['log'], "\n";
            }
        }

        printf("\ntools/offline.php: %d/%d offline suites green\n", $passed, $total);

        if ($json === null && ($this->opt['json_stdout'] || $this->opt['json_file'] !== null)) {
            // The caller asked for a machine-readable document and did not get
            // one. Exiting 0 would tell a script that the file it is about to
            // parse can be trusted; the human report above is the fallback.
            return 2;
        }

        return count($failed) === 0 ? 0 : 1;
    }

    /**
     * Encode a run document, or report why it could not be encoded.
     *
     * Diagnostics are raw bytes copied out of a suite log, so a suite that
     * exercises a binary payload -- or a truncated multibyte write from a
     * killed child -- can hand json_encode() invalid UTF-8, which makes it
     * return false. The previous `json_encode(...) . "\n"` turned that false
     * into the one-byte document "\n": --json printed a bare newline to a
     * consumer expecting an object, last-run.json was truncated to the same,
     * and the next --rerun-failed then aborted with "no previous run recorded".
     * All three were silent. JSON_INVALID_UTF8_SUBSTITUTE keeps such a line as
     * U+FFFD rather than failing, and a false return that survives even that is
     * reported and written nowhere.
     *
     * @param array<string,mixed> $document
     */
    private function encodeJson(array $document): ?string
    {
        $json = json_encode(
            $document,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );
        if ($json === false) {
            fwrite(STDERR, 'tools/offline.php: could not encode the run as JSON ('
                . json_last_error_msg() . "); nothing was written\n");

            return null;
        }

        return $json . "\n";
    }

    /**
     * Record this run in last-run.json, merged with what was already there.
     *
     * See OfflineRunner::mergeRunDocuments() for why merging rather than
     * replacing is the safety property: --rerun-failed reads this file, so a
     * green partial run that overwrote it would erase a previous run's
     * failures and let the next --rerun-failed report a false green.
     *
     * @param array<string,mixed> $document
     */
    private function writeLastRun(array $document): void
    {
        $merged = OfflineRunner::mergeRunDocuments(
            $this->readJsonFile($this->stateDir . '/last-run.json'),
            $document
        );
        $json = $this->encodeJson($merged);
        if ($json === null) {
            // Leaving the previous document in place beats truncating it: the
            // failures it records are still the best answer available.
            return;
        }
        @file_put_contents($this->stateDir . '/last-run.json', $json);
    }

    // ----------------------------------------------------------------- misc

    private function prepareStateDirs(int $jobs): void
    {
        foreach ([$this->stateDir, $this->logDir, $this->tmpRoot] as $dir) {
            if (!is_dir($dir) && !@mkdir($dir, 0o777, true) && !is_dir($dir)) {
                fwrite(STDERR, "tools/offline.php: cannot create $dir\n");
                exit(2);
            }
        }
        if (!$this->opt['tmpdir_isolation']) {
            return;
        }
        // Fresh per run: a leftover worker TMPDIR would let a previous run's
        // debris masquerade as this run's state.
        for ($i = 0; $i < $jobs; $i++) {
            $dir = $this->tmpRoot . '/worker-' . $i;
            $this->removeTree($dir);
            @mkdir($dir, 0o777, true);
        }
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $path) {
            /** @var \SplFileInfo $path */
            if ($path->isDir() && !$path->isLink()) {
                @rmdir($path->getPathname());
            } else {
                @unlink($path->getPathname());
            }
        }
        @rmdir($dir);
    }

    /** @return array<string,float> */
    private function loadDurations(): array
    {
        $data = $this->readJsonFile($this->stateDir . '/durations.json');
        if (!is_array($data)) {
            return [];
        }
        $out = [];
        foreach ($data as $target => $seconds) {
            if (is_string($target) && (is_int($seconds) || is_float($seconds))) {
                $out[$target] = (float) $seconds;
            }
        }

        return $out;
    }

    /** @param array<string,float> $durations */
    private function writeDurations(array $durations): void
    {
        // Merged, never replaced: a --filter run must not evict the cached
        // times of the suites it did not select, or the next full run schedules
        // blind.
        $merged = $this->loadDurations();
        foreach ($durations as $target => $seconds) {
            $merged[$target] = $seconds;
        }
        ksort($merged);
        $json = $this->encodeJson($merged);
        if ($json === null) {
            return;
        }
        @file_put_contents($this->stateDir . '/durations.json', $json);
    }

    /** @return array<mixed>|null */
    private function readJsonFile(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $data = json_decode($raw, true);

        return is_array($data) ? $data : null;
    }
}

/** @param list<string> $argv */
function offline_main(array $argv): int
{
    $repoRoot = dirname(__DIR__);
    if (!@chdir($repoRoot)) {
        fwrite(STDERR, "tools/offline.php: cannot chdir to $repoRoot\n");

        return 2;
    }

    return (new OfflineRunnerCli($repoRoot))->run($argv);
}

if (isset($_SERVER['SCRIPT_FILENAME']) && realpath((string) $_SERVER['SCRIPT_FILENAME']) === __FILE__) {
    /** @var list<string> $cliArgv */
    $cliArgv = $_SERVER['argv'] ?? [];
    exit(offline_main($cliArgv));
}
