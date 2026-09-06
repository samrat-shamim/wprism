<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use WPrism\Tooling\OfflineRunner;
use WPrism\Tooling\OfflineScenarioDelegation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins the three pure halves of tools/offline.php that a wrong answer would
 * make silently dangerous rather than loudly broken.
 *
 * 1. The diagnostics regex. tools/offline.php replaces
 *    sandbox/tests/offline_diagnostics_guard.sh's whole-run grep with a
 *    per-suite one, so if this pattern ever drifts from the guard's, the
 *    runner reports green on a run `make regress-offline-all` would reject.
 *    The negative case is the interesting one: shell suites legitimately print
 *    `Warning: fetch_artifact: retrying ...`, and only the absent PHP source
 *    location tells it apart from a real unprefixed PHP warning.
 * 2. The make-database parser. The work list is derived from make so the
 *    Makefile stays the single source of truth (changing it costs a
 *    certification round). A parser that silently drops targets would shrink
 *    the gate without any visible symptom, so it is pinned both on a fixture
 *    and against the real corpus's the Makefile-declared leaves.
 * 3. LPT ordering. On this corpus one suite dominates the makespan, so
 *    dispatch order is the whole speedup; an ordering bug costs minutes and
 *    looks like nothing.
 * 4. The last-run merge. `--rerun-failed` reads last-run.json, so a document
 *    that a partial run replaced rather than merged makes that flag report a
 *    false green while real failures survive.
 *
 * The pure functions are exercised in-process; the CLI contract is exercised
 * out-of-process for the same reason AffectedTest gives -- an entry point that
 * shells out to make and writes sandbox/tmp/ has no business doing so as a
 * side effect of loading a test file.
 */
final class OfflineRunnerTest extends TestCase
{
    /**
     * The expected offline leaf count comes from the `regress-offline-all: N
     * offline suites green` line, which tools/offline-corpus.php generates
     * into tools/offline-corpus.mk from the suite files on disk (and
     * sandbox/tests/offline/guards/regress_bundle_coverage.sh checks against
     * the prerequisite graph), so a corpus change is never mirrored by hand
     * here. The generated include is read directly rather than through the
     * Makefile's `include`: this assertion exists to catch a leaf count that
     * disagrees with the status line, and resolving the include with the same
     * code the runner uses would make it agree with itself.
     */
    private static function expectedOfflineLeafCount(): int
    {
        $generated = (string) file_get_contents(dirname(__DIR__, 2) . '/tools/offline-corpus.mk');
        self::assertSame(
            1,
            preg_match('/regress-offline-all:\s+(\d+)\s+offline suites green/', $generated, $m),
            'tools/offline-corpus.mk must carry exactly one regress-offline-all status count'
        );

        return (int) $m[1];
    }

    public static function setUpBeforeClass(): void
    {
        // PHP strips `#!...` only from the ENTRY script, never from an include,
        // so a plain require of an executable tool would print its shebang and
        // trip phpunit.xml.dist's beStrictAboutOutputDuringTests. The file is
        // otherwise side-effect free: its main() is behind the same
        // SCRIPT_FILENAME guard recovery/rollback-control.php uses.
        if (!class_exists(OfflineRunner::class, false)) {
            ob_start();
            require_once self::repoRoot() . '/tools/offline.php';
            ob_end_clean();
        }
    }

    private static function repoRoot(): string
    {
        $env = getenv('WPRISM_REPO_ROOT');

        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    // ------------------------------------------------------------ diagnostics

    /** @return array<string, array{string}> */
    public static function rejectedDiagnosticProvider(): array
    {
        return [
            'stderr-prefixed warning' => ['PHP Warning:  x'],
            'stderr-prefixed deprecation' => ['PHP Deprecated:  strlen(): passing null is deprecated'],
            'stderr-prefixed fatal' => ['PHP Fatal error:  Uncaught RuntimeException: nope'],
            'stderr-prefixed parse error' => ['PHP Parse error:  syntax error, unexpected token'],
            'stdout deprecation with file location' => ['Deprecated: foo in bar.php on line 3'],
            'stdout warning with " in " location' => ['Warning: fopen(): failed in /a/b.php on line 9'],
            'stdout fatal with " on line " location' => ['Fatal error: Uncaught Error: boom on line 12'],
        ];
    }

    #[DataProvider('rejectedDiagnosticProvider')]
    public function testDiagnosticRegexRejectsPhpDiagnostics(string $line): void
    {
        self::assertSame([1 . ':' . $line], OfflineRunner::diagnosticLines($line));
    }

    /** @return array<string, array{string}> */
    public static function acceptedLineProvider(): array
    {
        return [
            // The exact shape offline_diagnostics_guard.sh's own comment names
            // as the reason the unprefixed alternative demands a source
            // location: a shell suite's retry notice is not a PHP diagnostic.
            'shell retry warning' => ['Warning: fetch_artifact: retrying ...'],
            'shell deprecation notice' => ['Deprecated: use wprism scope instead'],
            'diagnostic-looking text mid-line' => ['ok: PHP Warning: is only mentioned here'],
            'colourised suite pass line' => ['ok: no syntax errors'],
            'json payload' => ['    "reason": "Warning: something in a value"'],
            'empty line' => [''],
        ];
    }

    #[DataProvider('acceptedLineProvider')]
    public function testDiagnosticRegexAcceptsNonPhpDiagnostics(string $line): void
    {
        self::assertSame([], OfflineRunner::diagnosticLines($line));
    }

    public function testDiagnosticLinesAreNumberedLikeGrepEn(): void
    {
        $output = implode("\n", [
            'ok: first check',
            'Warning: fetch_artifact: retrying ...',
            'PHP Warning:  something real',
            'ok: last check',
        ]);

        self::assertSame(['3:PHP Warning:  something real'], OfflineRunner::diagnosticLines($output));
    }

    /**
     * The guard's pattern, extracted verbatim.
     *
     * The guard spells `grep -En <pattern> <file>` across backslash-continued
     * lines, so the continuations are joined (dropping the backslash and the
     * newline, exactly as the shell does) and the single-quoted argument is
     * then taken byte for byte. Nothing is normalised, because normalising is
     * what made the previous version of this test blind -- see
     * testDiagnosticRegexIsIdenticalToTheShellGuard().
     */
    private static function shellGuardPattern(): string
    {
        $guard = (string) file_get_contents(self::repoRoot() . '/sandbox/tests/offline_diagnostics_guard.sh');
        $joined = preg_replace('/\\\\\n/', '', $guard);
        self::assertIsString($joined);
        self::assertSame(
            1,
            preg_match("/grep\\s+-En\\s+'([^']*)'/", $joined, $m),
            'offline_diagnostics_guard.sh no longer calls `grep -En` with a single-quoted pattern; '
                . 'the runner/guard identity check cannot be evaluated'
        );

        return $m[1];
    }

    public function testDiagnosticLinesSplitRecordsOnLfOnlyLikeGrep(): void
    {
        // grep splits records on LF and nothing else. A bare CR is a progress
        // redraw, not a new record, so the suite below is one line as far as
        // `make regress-offline-all` is concerned and must be one line here:
        // treating it as two would fail a suite the gate calls green.
        $withCr = "progress\rWarning: hidden in /x.php on line 1\ndone\n";
        self::assertSame([], OfflineRunner::diagnosticLines($withCr));

        // Same for the other separators PCRE's \R accepts: form feed, vertical
        // tab, NEL and U+2028 are ordinary bytes to grep.
        foreach (["\x0c", "\x0b", "\xc2\x85", "\xe2\x80\xa8"] as $sep) {
            self::assertSame(
                [],
                OfflineRunner::diagnosticLines('x' . $sep . 'Warning: y in z.php on line 1'),
                'PCRE \\R separator ' . bin2hex($sep) . ' must not start a new grep record'
            );
        }

        // And the numbering must survive them, since the `<lineno>:` prefix is
        // what a reader takes back to the log file.
        self::assertSame(
            ['2:PHP Warning:  real'],
            OfflineRunner::diagnosticLines("a\rb\nPHP Warning:  real\n")
        );
    }

    public function testDiagnosticLinesTreatATrailingNewlineAsAClosedRecord(): void
    {
        // grep counts "a\n" as one line, not two; an unterminated final line
        // still counts. Both spellings must number identically.
        self::assertSame(['1:PHP Warning:  x'], OfflineRunner::diagnosticLines("PHP Warning:  x\n"));
        self::assertSame(['1:PHP Warning:  x'], OfflineRunner::diagnosticLines('PHP Warning:  x'));
        self::assertSame([], OfflineRunner::diagnosticLines(''));
        self::assertSame(['3:PHP Warning:  x'], OfflineRunner::diagnosticLines("a\n\nPHP Warning:  x\n"));
    }

    public function testDiagnosticRegexIsIdenticalToTheShellGuard(): void
    {
        // assertSame on the raw bytes, NOT a whitespace-normalised comparison.
        // Whitespace is the load-bearing part of this pattern: `( in | on line )`
        // is the entire reason a shell suite's `Warning: fetch_artifact:
        // retrying ...` is not a diagnostic, and `PHP (Deprecated|...` needs its
        // space to match PHP's own output at all. A normalising comparison
        // passes for both of those drifts -- one makes the runner fail a run the
        // gate calls green, the other makes it pass a run the gate calls red.
        self::assertSame(self::shellGuardPattern(), trim(OfflineRunner::DIAGNOSTIC_REGEX, '/'));
    }

    public function testTheExtractedGuardPatternIsTheOneThatActuallyDiscriminates(): void
    {
        // Proves the extraction is of the real pattern rather than of some
        // harmless prose, by running the guard's own bytes against the two
        // lines the identity exists to keep apart.
        $pattern = '/' . self::shellGuardPattern() . '/';

        self::assertSame(1, preg_match($pattern, 'Warning: fopen(): failed in /a/b.php on line 9'));
        self::assertSame(0, preg_match($pattern, 'Warning: fetch_artifact: retrying ...'));
        self::assertSame(1, preg_match($pattern, 'PHP Warning:  x'));
    }

    // ----------------------------------------------------------- make parsing

    private static function makeDatabaseFixture(): string
    {
        // Faithful to `make -p` output: a variable dump and the built-in
        // implicit-rule catalogue precede `# Files`, targets carry comment
        // annotations, recipes are tab-indented, and blank lines separate
        // entries. Prerequisite lists arrive already flattened -- the reason
        // this is parsed instead of the Makefile.
        return implode("\n", [
            '# GNU Make 3.81',
            '# Variables',
            'MAKEFLAGS := ',
            'CURDIR := /repo',
            '',
            '# Implicit Rules',
            '',
            '%:: SCCS/s.%',
            "\t\$(GET) \$(GFLAGS) \$<",
            '',
            '# Files',
            '',
            'not-in-the-closure:',
            "\techo unrelated",
            '',
            'root: bundle leafa',
            '#  Phony target (prerequisite of .PHONY).',
            "\t@echo \"root: 3 offline suites green\"",
            '',
            'bundle: leafb leafc leafa',
            '#  Phony target (prerequisite of .PHONY).',
            '#  commands to execute (from `Makefile\', line 42):',
            "\t@echo bundle",
            '',
            'leafa:',
            '# automatic',
            '# @ := leafa',
            '#  commands to execute (from `Makefile\', line 10):',
            "\tphp sandbox/tests/regress_leaf_a.php",
            '',
            'leafb:',
            "\tCERT_JOBS=\"\$(JOBS)\" \\",
            "\tbash sandbox/tests/regress_leaf_b.sh",
            '',
            'leafc:',
            "\tbash sandbox/tests/regress_leaf_c.sh",
            '',
            '# files hash-table stats:',
            '# Load=300/1024=29%',
            'ignored-after-the-section:',
            '',
        ]);
    }

    public function testParseMakeDatabaseReadsTargetsPrerequisitesAndRecipes(): void
    {
        $db = OfflineRunner::parseMakeDatabase(self::makeDatabaseFixture());

        self::assertSame(['bundle', 'leafa'], $db['prereqs']['root']);
        self::assertSame(['leafb', 'leafc', 'leafa'], $db['prereqs']['bundle']);
        self::assertSame([], $db['prereqs']['leafa']);
        self::assertSame(['php sandbox/tests/regress_leaf_a.php'], $db['recipes']['leafa']);
    }

    public function testParseMakeDatabaseIgnoresVariablesImplicitRulesAndTrailingSections(): void
    {
        $db = OfflineRunner::parseMakeDatabase(self::makeDatabaseFixture());

        self::assertArrayNotHasKey('MAKEFLAGS', $db['prereqs']);
        self::assertArrayNotHasKey('CURDIR', $db['prereqs']);
        self::assertArrayNotHasKey('%', $db['prereqs']);
        self::assertArrayNotHasKey('%:: SCCS/s.%', $db['prereqs']);
        self::assertArrayNotHasKey('ignored-after-the-section', $db['prereqs']);
    }

    public function testExpandLeavesDropsAggregatorsAndDeduplicates(): void
    {
        $db = OfflineRunner::parseMakeDatabase(self::makeDatabaseFixture());
        $leaves = OfflineRunner::expandLeaves($db['prereqs'], 'root');

        // Depth-first in make's own prerequisite order; `leafa` is named twice
        // (once by root, once by bundle) and must appear exactly once; the two
        // aggregators are absent because they have prerequisites, not because
        // they are named.
        self::assertSame(['leafb', 'leafc', 'leafa'], $leaves);
        self::assertNotContains('root', $leaves);
        self::assertNotContains('bundle', $leaves);
        self::assertNotContains('not-in-the-closure', $leaves);
    }

    public function testScriptsInRecipeSurvivesEnvPrefixesAndContinuations(): void
    {
        $db = OfflineRunner::parseMakeDatabase(self::makeDatabaseFixture());

        self::assertSame(
            ['sandbox/tests/regress_leaf_b.sh'],
            OfflineRunner::scriptsInRecipe($db['recipes']['leafb'])
        );
    }

    public function testScriptsInRecipeReadsSuitePathsInSubdirectories(): void
    {
        // The suite estate is moving into sandbox/tests/<class>/<domain>/.
        // scriptsInRecipe() feeds needsSerialGroup(), which decides whether a
        // suite may run concurrently, so a nested path it fails to extract is
        // not a cosmetic miss: the suite reports zero scripts, zero files are
        // scanned for fixed scratch paths, and it joins the parallel pool
        // whether or not that is safe.
        $db = OfflineRunner::parseMakeDatabase(implode("\n", [
            '# Files',
            '',
            'nested-leaf:',
            "\tphp sandbox/tests/offline/guards/regress_suite_wiring.php",
            '',
            'nested-with-env:',
            "\tPAIR=\"\$(PAIR)\" bash sandbox/tests/live/domain/regress_deep_leaf.sh",
            '',
        ]));

        self::assertSame(
            ['sandbox/tests/offline/guards/regress_suite_wiring.php'],
            OfflineRunner::scriptsInRecipe($db['recipes']['nested-leaf'])
        );
        self::assertSame(
            ['sandbox/tests/live/domain/regress_deep_leaf.sh'],
            OfflineRunner::scriptsInRecipe($db['recipes']['nested-with-env'])
        );
    }

    public function testTheRealCorpusContainsANestedLeafWhoseScriptResolves(): void
    {
        // The end-to-end version of the case above, against the real Makefile
        // rather than a fixture: regress-suite-wiring is the first offline
        // leaf below the top level, and tools/offline.php has to find its
        // script to run it at all.
        $result = self::invoke(['--list', '--explain', '--filter=regress-suite-wiring']);

        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertMatchesRegularExpression(
            '#^regress-suite-wiring\s+\S+\s+sandbox/tests/offline/guards/regress_suite_wiring\.php$#m',
            $result['stdout']
        );
    }

    /**
     * The premise behind scriptsInRecipe() carrying NO sandbox-relative needle.
     *
     * issue #3482 taught the other two path-aware tools to read a suite named
     * `tests/grind/x.sh` by a corpus file whose cwd is `sandbox/` (the rule is
     * stated above ms_sandbox_relative_tail() in tools/codemod/move-suites.php).
     * This tool was assessed and deliberately left alone, because its input is
     * Makefile recipes and make runs those from the repo root, so the spelling
     * cannot appear in one that works. That is a claim about the real Makefile,
     * not about this regex -- so it is asserted against the real Makefile, and
     * the assertion is shown to bite on a synthetic recipe that violates it.
     */
    public function testNoRealRecipeNamesASuiteSandboxRelatively(): void
    {
        $sandboxRelative = '#(?<![A-Za-z0-9_./-])tests/[A-Za-z0-9_./-]+\.(?:sh|php)#';

        // Recipe lines read from the Makefile itself -- a leading TAB is
        // exactly what make treats as recipe text -- rather than through
        // parseMakeDatabase(), which consumes `make -pn` database output.
        $offenders = [];
        $lines = 0;
        foreach (explode("\n", (string) file_get_contents(self::repoRoot() . '/Makefile')) as $n => $line) {
            if (!str_starts_with($line, "\t")) {
                continue;
            }
            $lines++;
            if (preg_match($sandboxRelative, $line) === 1) {
                $offenders[] = 'Makefile:' . ($n + 1) . ': ' . trim($line);
            }
        }
        self::assertGreaterThan(200, $lines, 'a scan that read nothing must not read as a clean bill of health');
        self::assertSame([], $offenders, 'a sandbox-relative path in a recipe names no file from make\'s cwd');

        // …and the check is not vacuous: this is what a violating recipe looks
        // like, and regress_suite_wiring.php clause 4 is what refuses it in the
        // corpus (the suite it names would enter no recipe's token list).
        $bad = OfflineRunner::parseMakeDatabase(implode("\n", [
            '# Files',
            '',
            'regress-cwd-confused:',
            "\tbash tests/live/regress_cwd_confused.sh",
            '',
        ]));
        self::assertSame(1, preg_match($sandboxRelative, $bad['recipes']['regress-cwd-confused'][0]));
        // The regex under test sees nothing in it, which is the whole point:
        // there is no file here for it to have missed.
        self::assertSame([], OfflineRunner::scriptsInRecipe($bad['recipes']['regress-cwd-confused']));
    }

    // -------------------------------------------------------- serial grouping

    public function testSerialGroupDetectsHardCodedTmpPathsIncludingPhpCompanion(): void
    {
        $root = self::scratchRoot();
        // The wrapper is clean; the companion the wrapper runs is not. Scanning
        // only the named script would call this suite parallel-safe.
        file_put_contents($root . '/sandbox/tests/regress_wrapped.sh', "php regress_wrapped.php\n");
        file_put_contents($root . '/sandbox/tests/regress_wrapped.php', "<?php \$p = '/tmp/wprism-fixed-name';\n");
        file_put_contents($root . '/sandbox/tests/regress_clean.sh', "d=\$(mktemp -d)\n");

        try {
            self::assertSame(
                ['sandbox/tests/regress_wrapped.php'],
                OfflineRunner::serialGroupEvidence($root, ['sandbox/tests/regress_wrapped.sh'])
            );
            self::assertTrue(
                OfflineRunner::needsSerialGroup($root, ['sandbox/tests/regress_wrapped.sh'])
            );
            self::assertFalse(
                OfflineRunner::needsSerialGroup($root, ['sandbox/tests/regress_clean.sh'])
            );
        } finally {
            self::removeScratchRoot($root);
        }
    }

    public function testSerialGroupKeysOnNestedSuitePathsAndTheirCompanions(): void
    {
        // serialGroupEvidence() derives the `.php` companion by string surgery
        // on the `.sh` path, so it has to hold up for a path with directories
        // in it: a nested wrapper whose companion carries the fixed scratch
        // path must still be found, and the evidence must name the companion
        // at its real nested path.
        $root = self::scratchRoot();
        $wrapper = 'sandbox/tests/offline/domain/regress_nested_wrapped.sh';
        file_put_contents($root . '/' . $wrapper, "php regress_nested_wrapped.php\n");
        file_put_contents(
            $root . '/sandbox/tests/offline/domain/regress_nested_wrapped.php',
            "<?php \$p = '/tmp/wprism-fixed-name';\n"
        );
        file_put_contents(
            $root . '/sandbox/tests/offline/domain/regress_nested_clean.php',
            "<?php \$d = sys_get_temp_dir();\n"
        );

        try {
            self::assertSame(
                ['sandbox/tests/offline/domain/regress_nested_wrapped.php'],
                OfflineRunner::serialGroupEvidence($root, [$wrapper])
            );
            self::assertTrue(OfflineRunner::needsSerialGroup($root, [$wrapper]));
            self::assertFalse(OfflineRunner::needsSerialGroup(
                $root,
                ['sandbox/tests/offline/domain/regress_nested_clean.php']
            ));
        } finally {
            self::removeScratchRoot($root);
        }
    }

    public function testSerialGroupDetectsUnquotedTmpRedirectionsAndAssignments(): void
    {
        // The shape a quote-anchored pattern could not see, and the one shell
        // suites actually use: an absolute path as a redirection target is as
        // fixed an inode as a quoted literal, and being absolute it also
        // ignores the per-worker TMPDIR this runner exports.
        $root = self::scratchRoot();
        $cases = [
            'redirect' => "if run_it 2>/tmp/coverage_real.log; then :; fi\n",
            'assignment' => "LOG=/tmp/wprism-fixed.log\n",
            'line start' => "/tmp/wprism-fixed-helper.sh --run\n",
            'quoted' => "cat '/tmp/wprism-fixed.log'\n",
            // The boundary withoutConcatenatedTmp() must NOT cross: a literal
            // that STARTS an expression is a genuine absolute path even though
            // a `.` appears later on the line.
            'concat tail, absolute head' => "<?php \$p = '/tmp/wprism-fixed' . \$suffix;\n",
        ];
        $clean = [
            'mktemp dir' => "d=\$(mktemp -d)\n",
            'var tmp' => "cat /var/tmp/other.log\n",
            'relative tmp' => "cat ./tmp/other.log\n",
            'tmpdir-derived' => "cat \"\$TMPDIR/tmp/other.log\"\n",
            // `<expr> . '/tmp/x'` is sandbox/tmp -- per-checkout scratch, not
            // the shared inode. Reading it as absolute serialised
            // regress-ideal-onboarding (82.7 s) and regress-policy-load-scale
            // (10.8 s) for nothing; see withoutConcatenatedTmp().
            'concatenated single-quote' => "<?php \$p = dirname(__DIR__, 3) . '/tmp/policy-load-scale';\n",
            'concatenated double-quote' => "<?php \$p = \$sandbox . \"/tmp/demo.env\";\n",
            'concatenated no space' => "<?php \$p = \$sandbox.'/tmp/demo.env';\n",
        ];

        try {
            foreach ($cases as $label => $body) {
                file_put_contents($root . '/sandbox/tests/regress_probe.sh', $body);
                self::assertTrue(
                    OfflineRunner::needsSerialGroup($root, ['sandbox/tests/regress_probe.sh']),
                    "an absolute /tmp/ path written as a $label must join the serial group"
                );
            }
            foreach ($clean as $label => $body) {
                file_put_contents($root . '/sandbox/tests/regress_probe.sh', $body);
                self::assertFalse(
                    OfflineRunner::needsSerialGroup($root, ['sandbox/tests/regress_probe.sh']),
                    "a $label scratch path honours TMPDIR and must stay parallel"
                );
            }
        } finally {
            self::removeScratchRoot($root);
        }
    }

    // ------------------------------------------------------------ run records

    /**
     * @param array<string,string> $rows target => 'ok'|'fail'
     * @return array<string,mixed>
     */
    private static function runDocument(string $selection, array $rows): array
    {
        $targets = [];
        foreach ($rows as $target => $status) {
            $targets[] = [
                'target' => $target,
                'status' => $status,
                'exit' => $status === 'ok' ? 0 : 1,
                'seconds' => 0.5,
                'diagnostics' => [],
                'log' => null,
            ];
        }

        return [
            'selection' => $selection,
            'targets' => $targets,
            'summary' => ['total' => count($targets), 'passed' => 0, 'failed' => 0, 'wall_seconds' => 1.0],
        ];
    }

    /**
     * @param array<string,mixed> $document
     * @return array<string,string> target => status, in document order
     */
    private static function statuses(array $document): array
    {
        $out = [];
        foreach ($document['targets'] as $row) {
            $out[$row['target']] = $row['status'];
        }

        return $out;
    }

    public function testAPartialRunMergesIntoThePreviousRecordInsteadOfReplacingIt(): void
    {
        // The scenario the merge exists for: a full run leaves two suites red,
        // the developer iterates on one of them and it goes green, and the
        // other must still be there for the next --rerun-failed.
        $prior = self::runDocument('full', ['a' => 'fail', 'b' => 'fail', 'c' => 'ok']);
        $merged = OfflineRunner::mergeRunDocuments($prior, self::runDocument('partial', ['b' => 'ok']));

        self::assertSame(['a' => 'fail', 'b' => 'ok', 'c' => 'ok'], self::statuses($merged));
        self::assertSame(3, $merged['summary']['total']);
        self::assertSame(2, $merged['summary']['passed']);
        self::assertSame(1, $merged['summary']['failed']);
        self::assertSame(1, $merged['summary']['ran_this_invocation']);
        self::assertSame('partial', $merged['selection']);
    }

    public function testMergedFailuresAccumulateAcrossSuccessivePartialRuns(): void
    {
        // --rerun-failed must select the UNION of what is still failing, so a
        // second partial run that reds a different suite may not drop the
        // first one's survivor.
        $doc = self::runDocument('full', ['a' => 'fail', 'b' => 'ok', 'c' => 'ok']);
        $doc = OfflineRunner::mergeRunDocuments($doc, self::runDocument('partial', ['b' => 'fail']));
        $doc = OfflineRunner::mergeRunDocuments($doc, self::runDocument('partial', ['c' => 'ok']));

        $failing = [];
        foreach ($doc['targets'] as $row) {
            if ($row['status'] === 'fail') {
                $failing[] = $row['target'];
            }
        }

        self::assertSame(['a', 'b'], $failing);
        self::assertSame(2, $doc['summary']['failed']);
    }

    public function testAFullRunReplacesTheRecordSoDroppedTargetsDoNotLinger(): void
    {
        // A full run has every live target in it, so keeping older rows would
        // resurrect suites the Makefile no longer defines.
        $prior = self::runDocument('partial', ['gone-from-the-makefile' => 'fail']);
        $merged = OfflineRunner::mergeRunDocuments($prior, self::runDocument('full', ['a' => 'ok', 'b' => 'ok']));

        self::assertSame(['a' => 'ok', 'b' => 'ok'], self::statuses($merged));
        self::assertSame(0, $merged['summary']['failed']);
    }

    public function testMergeToleratesAMissingOrMalformedPriorDocument(): void
    {
        // readJsonFile() returns null for a first run and for a truncated file;
        // neither may cost this run its own rows.
        $fresh = self::runDocument('partial', ['a' => 'fail']);

        self::assertSame(['a' => 'fail'], self::statuses(OfflineRunner::mergeRunDocuments(null, $fresh)));
        self::assertSame(
            ['a' => 'fail'],
            self::statuses(OfflineRunner::mergeRunDocuments(['targets' => 'not-a-list'], $fresh))
        );
        self::assertSame(
            ['a' => 'fail'],
            self::statuses(OfflineRunner::mergeRunDocuments(['targets' => [['no-target-key' => 1]]], $fresh))
        );
    }

    // --------------------------------------------------------- LPT scheduling

    public function testLptOrderPutsTheLongestSuitesFirst(): void
    {
        $order = OfflineRunner::lptOrder(
            ['fast', 'slow', 'middling'],
            ['fast' => 0.1, 'slow' => 74.0, 'middling' => 37.0]
        );

        self::assertSame(['slow', 'middling', 'fast'], $order);
    }

    public function testLptOrderScoresUncachedSuitesAtTheMedianAndKeepsTiesStable(): void
    {
        // Known durations are 1.0, 3.0, 5.0 -> median 3.0, which is what the
        // uncached `c` is worth. `c` therefore ties with `d` and, being earlier
        // in the input, must stay earlier in the output.
        $order = OfflineRunner::lptOrder(
            ['a', 'b', 'c', 'd'],
            ['a' => 1.0, 'b' => 5.0, 'd' => 3.0]
        );

        self::assertSame(['b', 'c', 'd', 'a'], $order);
    }

    public function testLptOrderWithNoCacheAtAllPreservesMakeOrder(): void
    {
        // A cold first run has nothing to schedule on; falling back to make's
        // own order keeps the run reproducible instead of arbitrary.
        self::assertSame(['x', 'y', 'z'], OfflineRunner::lptOrder(['x', 'y', 'z'], []));
    }

    // ---------------------------------------------------------- CLI contract

    public function testListEmitsExactlyTheOfflineCorpusLeaves(): void
    {
        $result = self::invoke(['--list']);
        self::assertSame(0, $result['status'], $result['stderr']);

        $targets = preg_split('/\R/', trim($result['stdout']), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        self::assertCount(self::expectedOfflineLeafCount(), $targets);
        self::assertSame($targets, array_values(array_unique($targets)));
        self::assertContains('regress-path-safety', $targets);
        self::assertContains('regress-code-compatibility', $targets, 'code-half-unit must be folded in');
        self::assertContains('regress-offline-diagnostics', $targets);
        // Aggregators have @echo-only recipes; running them would double-count.
        self::assertNotContains('code-half-unit', $targets);
        self::assertNotContains('regress-offline-corpus', $targets);
    }

    public function testFilterSelectsBySubstring(): void
    {
        $result = self::invoke(['--list', '--filter=path-safety,command-output']);
        self::assertSame(0, $result['status'], $result['stderr']);

        $targets = preg_split('/\R/', trim($result['stdout']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($targets);

        self::assertSame(['regress-command-output', 'regress-path-safety'], $targets);
    }

    public function testChangedAdapterListsOnlyItsPackageAndParticipantScenarioTasks(): void
    {
        $result = self::invoke([
            '--changed-paths=adapter-packages/polylang/package/manifest.json',
            '--list',
        ]);
        self::assertSame(0, $result['status'], $result['stderr']);

        $targets = preg_split('/\R/', trim($result['stdout']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        self::assertSame([
            'adapter-package:polylang',
            'integration-scenario:rank-math-commerce-multilingual:offline:regress_rank_math_commerce_multilingual_contract.php',
            'integration-scenario:rank-math-commerce-multilingual:offline:regress_source_native_premise.php',
            'integration-scenario:woocommerce-rewrite-coinstall:offline:regress_woocommerce_hierarchy_lookups.php',
        ], $targets);
        self::assertNotContains('regress-adapter-packages', $targets);
        self::assertStringContainsString(
            'advisory task (not executed by changed mode): '
                . 'integration-scenario:polylang-tec-rewrite-coinstall:live:',
            $result['stderr']
        );
        self::assertStringContainsString(
            'advisory task (not executed by changed mode): '
                . 'integration-scenario:rank-math-commerce-multilingual:live:',
            $result['stderr']
        );
        self::assertStringContainsString(
            'advisory task (not executed by changed mode): '
                . 'integration-scenario:woocommerce-rewrite-coinstall:live:',
            $result['stderr']
        );
    }

    public function testChangedScenarioIsDelegatedOutOfThePackageAggregateExactlyOnce(): void
    {
        $scenario = 'integration-scenario:woocommerce-rewrite-coinstall:offline:'
            . 'regress_woocommerce_hierarchy_lookups.php';
        $argv = OfflineScenarioDelegation::makeArgv('make', 'regress-adapter-packages', [
            'adapter-package:woocommerce',
            $scenario,
            'integration-scenario:woocommerce-rewrite-coinstall:live:'
                . 'regress_woocommerce_rewrite_coinstall.sh',
        ]);

        self::assertSame([
            'make',
            '--no-print-directory',
            OfflineScenarioDelegation::ENVIRONMENT . '=' . $scenario,
            'regress-adapter-packages',
        ], $argv);
        self::assertSame([$scenario], OfflineScenarioDelegation::decode(substr(
            $argv[2],
            strlen(OfflineScenarioDelegation::ENVIRONMENT) + 1
        )));
        self::assertSame(
            ['make', '--no-print-directory', 'regress-adapter-packages'],
            OfflineScenarioDelegation::makeArgv('make', 'regress-adapter-packages', [])
        );

        $catalog = ['scenarios' => [[
            'name' => 'woocommerce-rewrite-coinstall',
            'gates' => [[
                'class' => 'offline',
                'path' => 'integration-scenarios/woocommerce-rewrite-coinstall/tests/offline/'
                    . 'regress_woocommerce_hierarchy_lookups.php',
            ], [
                'class' => 'live',
                'path' => 'integration-scenarios/woocommerce-rewrite-coinstall/tests/live/'
                    . 'regress_woocommerce_rewrite_coinstall.sh',
            ]],
        ]]];
        self::assertSame(
            [$scenario => true],
            OfflineScenarioDelegation::checkedSet([$scenario], $catalog)
        );
        $makefile = (string) file_get_contents(self::repoRoot() . '/Makefile');
        self::assertStringContainsString(
            'ifneq ($(origin ' . OfflineScenarioDelegation::ENVIRONMENT . '),command line)',
            $makefile
        );
        self::assertStringContainsString(
            'unexport ' . OfflineScenarioDelegation::ENVIRONMENT,
            $makefile
        );
    }

    public function testChangedAdapterFilterCanSelectItsPackageTask(): void
    {
        $result = self::invoke([
            '--changed-paths=adapter-packages/polylang/package/manifest.json',
            '--filter=adapter-package:polylang',
            '--list',
        ]);

        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertSame("adapter-package:polylang\n", $result['stdout']);
    }

    public function testChangedUnknownPathRunsTheClosedFullOfflineSelection(): void
    {
        $result = self::invoke([
            '--changed-paths=future-root/new.php',
            '--list',
        ]);
        self::assertSame(0, $result['status'], $result['stderr']);

        $targets = preg_split('/\R/', trim($result['stdout']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        self::assertCount(self::expectedOfflineLeafCount(), $targets);
        self::assertContains('regress-adapter-packages', $targets);
    }

    public function testChangedAdapterExplainPrintsCheckedTaskCommands(): void
    {
        $result = self::invoke([
            '--changed-paths=adapter-packages/woocommerce/package/manifest.json',
            '--list',
            '--explain',
        ]);

        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertMatchesRegularExpression(
            '#^adapter-package:woocommerce\s+-\s+\S+php tools/adapter-package-tests\.php --adapter=woocommerce$#m',
            $result['stdout']
        );
        self::assertStringContainsString(
            'integration-scenarios/woocommerce-rewrite-coinstall/tests/offline/regress_woocommerce_hierarchy_lookups.php',
            $result['stdout']
        );
        self::assertStringContainsString(
            'integration-scenarios/woocommerce-rewrite-coinstall/tests/live/regress_woocommerce_rewrite_coinstall.sh',
            $result['stderr']
        );
        self::assertStringContainsString('4 selected task(s) from ', $result['stderr']);
    }

    public function testChangedAdapterFilterThatMatchesNoScopedTaskIsAnError(): void
    {
        $result = self::invoke([
            '--changed-paths=adapter-packages/woocommerce/package/manifest.json',
            '--filter=zzz-no-such-scoped-task',
        ]);

        self::assertSame(2, $result['status']);
        self::assertStringContainsString('nothing selected', $result['stderr']);
    }

    public function testExplainReportsANonEmptySerialGroup(): void
    {
        $result = self::invoke(['--list', '--explain']);
        self::assertSame(0, $result['status'], $result['stderr']);

        // Detected by scanning suite sources, so the exact membership moves
        // with the corpus; what must hold is that the mechanism finds the
        // fixed-`/tmp/` suites at all, and that a known one is among them.
        self::assertMatchesRegularExpression('/ \d+ in the serial group/', $result['stderr']);
        self::assertDoesNotMatchRegularExpression('/ 0 in the serial group/', $result['stderr']);
        self::assertMatchesRegularExpression(
            '/^regress-local-bootstrap\s+serial\s+\S/m',
            $result['stdout']
        );
    }

    private static function scratchRoot(): string
    {
        $root = (string) tempnam(sys_get_temp_dir(), 'wprism-offline-test-');
        unlink($root);
        mkdir($root . '/sandbox/tests/offline/domain', 0o777, true);

        return $root;
    }

    /** Recursive since scratchRoot() grew a nested suite directory. */
    private static function removeScratchRoot(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeScratchRoot($path . '/' . $entry);
                }
            }
            rmdir($path);

            return;
        }
        if (file_exists($path)) {
            unlink($path);
        }
    }

    public function testAFilterThatMatchesNothingIsStillAnError(): void
    {
        // The counterpart to --changed's benign empty selection: an empty
        // --filter is a typo or stale state, so it must keep exit 2 rather than
        // reporting a green zero-suite run.
        $result = self::invoke(['--filter=zzz-no-such-offline-suite']);

        self::assertSame(2, $result['status']);
        self::assertStringContainsString('nothing selected', $result['stderr']);
    }

    /**
     * @param list<string> $args
     * @return array{status: int, stdout: string, stderr: string}
     */
    private static function invoke(array $args): array
    {
        $repo = self::repoRoot();
        $process = proc_open(
            [PHP_BINARY, $repo . '/tools/offline.php', ...$args],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        self::assertIsResource($process, 'could not launch tools/offline.php');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
