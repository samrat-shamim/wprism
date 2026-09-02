<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pins tools/affected.php against the contract tools/offline.php's
 * `--changed[=BASE]` flag depends on (stdout is one leaf or checked scoped
 * task name per line, nothing else) plus a handful of known changed-file -> suite
 * mappings read directly out of the real sandbox/tests corpus.
 *
 * Every case here uses --paths=... so it is hermetic against the working
 * tree, which other work packages mutate concurrently in this repo. The
 * script is run out-of-process (proc_open): it is a CLI entry point that
 * shells out to `make`/`git` and writes sandbox/tmp/affected-index.json, none
 * of which belongs happening as a side effect of requiring a test file
 * in-process.
 *
 * A second group of cases requires tools/affected.php IN-process and calls
 * its extractors directly. That is safe and deliberate: the file's bottom
 * guard only runs af_main() when SCRIPT_FILENAME resolves to itself (never
 * under phpunit), and the extractors under test are pure text -> paths
 * functions that neither shell out nor write anything. Pinning them at unit
 * level is the only way to state the literal source shapes they must
 * recognise -- a CLI-level assertion can only say "some suite was selected",
 * which passes for the wrong reason as soon as any other signal happens to
 * cover the same file.
 *
 * These assertions intentionally do not pin an exact suite count for any
 * broadly-required file (agent/src/Kernel/Canon.php, CommandRefusal.php, ...): the
 * dependency index is a real static analysis over ~600 source and test
 * files that will legitimately grow or shrink by a few suites as the corpus
 * evolves. What must stay true, and what these assert, is that specific
 * known suites are always present and the two structural contracts
 * (Makefile => the whole corpus, a suite file => itself) always hold.
 */
final class AffectedTest extends TestCase
{
    /**
     * The expected offline leaf count comes from the `regress-offline-all: N
     * offline suites green` line, which tools/offline-corpus.php generates
     * into tools/offline-corpus.mk from the suite files on disk (and
     * sandbox/tests/offline/guards/regress_bundle_coverage.sh checks against
     * the prerequisite graph), so a corpus change is never mirrored by hand
     * here.
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

    private static function repoRoot(): string
    {
        $env = getenv('WPRISM_REPO_ROOT');
        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    /** @param list<string> $args
     * @return array{status:int,stdout:string,stderr:string}
     */
    private static function invoke(array $args): array
    {
        $repo = self::repoRoot();
        $cmd = [PHP_BINARY, $repo . '/tools/affected.php', ...$args];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $repo);
        self::assertIsResource($process, 'could not launch tools/affected.php');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param list<string> $args
     * @return array{status:int,stdout:string,stderr:string}
     */
    private static function invokeWithMemoryLimit(array $args, string $limit): array
    {
        $repo = self::repoRoot();
        $cmd = [PHP_BINARY, '-d', 'memory_limit=' . $limit, $repo . '/tools/affected.php', ...$args];
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $repo);
        self::assertIsResource($process, 'could not launch memory-bounded tools/affected.php');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @param list<string> $args
     * @return array{status:int,path:string,stderr:string}
     */
    private static function invokeWithMemoryLimitToFile(array $args, string $limit): array
    {
        $repo = self::repoRoot();
        $path = tempnam(sys_get_temp_dir(), 'wprism-affected-output-');
        self::assertIsString($path, 'could not allocate affected.php output fixture');
        $cmd = [PHP_BINARY, '-d', 'memory_limit=' . $limit, $repo . '/tools/affected.php', ...$args];
        $descriptors = [1 => ['file', $path, 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($cmd, $descriptors, $pipes, $repo);
        self::assertIsResource($process, 'could not launch file-backed memory-bounded tools/affected.php');
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $status = proc_close($process);

        return ['status' => $status, 'path' => $path, 'stderr' => $stderr];
    }

    /** @param list<string> $args
     * @return list<string> non-empty trimmed lines
     */
    private static function targets(array $args): array
    {
        $result = self::invoke($args);
        self::assertSame(0, $result['status'], 'affected.php exited non-zero: ' . $result['stderr']);
        $lines = [];
        foreach (explode("\n", $result['stdout']) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $lines[] = $line;
            }
        }
        return $lines;
    }

    public function testPathSafetySelectsItsOwnSuite(): void
    {
        $targets = self::targets(['--paths=agent/src/Kernel/PathSafety.php']);
        self::assertContains('regress-path-safety', $targets);
        self::assertGreaterThanOrEqual(10, count($targets));
    }

    public function testCommandRefusalSelectsCliJsonRefusals(): void
    {
        $targets = self::targets(['--paths=agent/src/Kernel/CommandRefusal.php']);
        self::assertContains('regress-cli-json-refusals', $targets);
    }

    public function testCommandOutputSelectsCommandOutputSuite(): void
    {
        $targets = self::targets(['--paths=cli/src/Command/CommandOutput.php']);
        self::assertContains('regress-command-output', $targets);
    }

    public function testApplyFieldMaterializerSelectsItsOwnSuite(): void
    {
        $targets = self::targets(['--paths=agent/src/Apply/ApplyFieldMaterializer.php']);
        self::assertContains('regress-apply-field-materializer', $targets);
    }

    public function testDeployPlannerSelectsItsOwnSuite(): void
    {
        $targets = self::targets(['--paths=agent/src/Promotion/DeployPlanner.php']);
        self::assertContains('regress-deploy-planner', $targets);
    }

    public function testTableSchemaSelectsItsOwnSuite(): void
    {
        $targets = self::targets(['--paths=agent/src/Kernel/TableSchema.php']);
        self::assertContains('regress-table-schema', $targets);
    }

    public function testTextTokenizerSelectsItsOwnSuite(): void
    {
        $targets = self::targets(['--paths=agent/src/Kernel/TextTokenizer.php']);
        self::assertContains('regress-text-tokenizer', $targets);
    }

    public function testEnvironmentCommandOptionsSelectsItsOwnSuite(): void
    {
        $targets = self::targets(['--paths=cli/src/Command/EnvironmentCommandOptions.php']);
        self::assertContains('regress-environment-command-options', $targets);
    }

    public function testUrlQueryReferenceCodecSelectsItsOwnSuite(): void
    {
        $targets = self::targets(['--paths=agent/src/Kernel/UrlQueryReferenceCodec.php']);
        self::assertContains('regress-url-query-reference-codec', $targets);
    }

    public function testLintFindingSelectsAKnownDependentSuite(): void
    {
        // LintFinding.php has no same-named suite; it's a shared value type
        // pulled in by several reference scanners. Assert one concrete,
        // stable dependent rather than the file's own (non-existent) suite.
        $targets = self::targets(['--paths=agent/src/Review/LintFinding.php']);
        self::assertContains('regress-block-reference-scanner', $targets);
    }

    public function testCanonSelectsAtLeastFortySuites(): void
    {
        // agent/src/Kernel/Canon.php is the encode/decode primitive nearly every
        // suite touches directly or transitively -- the broadest possible
        // fan-out sample.
        $targets = self::targets(['--paths=agent/src/Kernel/Canon.php']);
        self::assertGreaterThanOrEqual(40, count($targets));
    }

    public function testMakefileSelectsTheWholeOfflineCorpus(): void
    {
        $targets = self::targets(['--paths=Makefile']);
        self::assertCount(self::expectedOfflineLeafCount(), $targets);
        self::assertContains('regress-path-safety', $targets);
    }

    public function testIntegrationScenarioMetadataSelectsTheWholeOfflineAggregate(): void
    {
        $targets = self::targets([
            '--paths=integration-scenarios/polylang-tec-rewrite-coinstall/scenario.json',
        ]);

        self::assertCount(self::expectedOfflineLeafCount(), $targets);
        self::assertContains('regress-adapter-packages', $targets);
    }

    public function testIntegrationScenarioOfflinePathAddsItsCheckedTaskToClosedFullSelection(): void
    {
        $path = 'integration-scenarios/woocommerce-rewrite-coinstall/tests/offline/'
            . 'regress_woocommerce_hierarchy_lookups.php';
        $result = self::invoke(['--paths=' . $path, '--json', '--explain']);
        self::assertSame(0, $result['status'], $result['stderr']);
        $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        $target = 'integration-scenario:woocommerce-rewrite-coinstall:offline:'
            . 'regress_woocommerce_hierarchy_lookups.php';

        self::assertCount(self::expectedOfflineLeafCount() + 1, $decoded['targets']);
        self::assertSame($decoded['targets'], array_values(array_unique($decoded['targets'])));
        self::assertContains($target, $decoded['targets']);
        self::assertSame([[
            'target' => $target,
            'kind' => 'integration-scenario',
            'command' => ['php', $path],
        ]], $decoded['tasks']);
        self::assertSame(
            [$target],
            array_values(array_unique(array_column(array_filter(
                $decoded['explain'],
                static fn(array $row): bool => $row['target'] === $target
            ), 'target')))
        );
    }

    public function testToolsChangeAlsoSelectsTheWholeCorpus(): void
    {
        // affected.php cannot trust its own output once its own logic (or
        // its sibling driver's) has changed underneath it.
        $targets = self::targets(['--paths=tools/affected.php']);
        self::assertCount(self::expectedOfflineLeafCount(), $targets);
    }

    public function testAllListsTheWholeOfflineCorpus(): void
    {
        $targets = self::targets(['--all']);
        self::assertCount(self::expectedOfflineLeafCount(), $targets);
        self::assertSame($targets, array_unique($targets), 'target list must be unique');
        $sorted = $targets;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $targets, 'target list must be sorted');
    }

    public function testSuiteFileChangeHonorsClosedFullDecision(): void
    {
        $targets = self::targets(['--paths=sandbox/tests/offline/cli/regress_command_output.php']);
        self::assertCount(self::expectedOfflineLeafCount(), $targets);
        self::assertContains('regress-command-output', $targets);
    }

    public function testNestedSuiteFileSelectsItsOwnTarget(): void
    {
        // AdapterChangeScope does not assign sandbox files a narrower owner,
        // so its closed full decision wins over the static index. The target
        // still has to be in the resulting corpus; the pure primary-map cases
        // below pin recursive discovery independently.
        $targets = self::targets(['--paths=sandbox/tests/offline/guards/regress_suite_wiring.php']);
        self::assertCount(self::expectedOfflineLeafCount(), $targets);
        self::assertContains('regress-suite-wiring', $targets);
    }

    public function testPrimaryTargetMapCarriesRepoRelativePathsNotBasenames(): void
    {
        self::loadTool();
        $primary = af_primary_targets(self::repoRoot());

        // The map's VALUE is the path. af_build_index() used to rebuild one as
        // 'sandbox/tests/' . $basename, which names a file that does not exist
        // for every suite in a subdirectory.
        self::assertSame(
            'sandbox/tests/offline/guards/regress_suite_wiring.php',
            $primary['regress-suite-wiring'] ?? null
        );
        self::assertSame(
            'sandbox/tests/offline/cli/regress_command_output.php',
            $primary['regress-command-output'] ?? null
        );
        foreach ($primary as $target => $path) {
            self::assertFileExists(self::repoRoot() . '/' . $path, "primary file for $target");
        }
    }

    public function testSuiteEnumerationIsRecursiveOverASyntheticTree(): void
    {
        // Hermetic counterpart to the two cases above: they pin the real
        // corpus, which W1-W3 will rearrange, while this states the rule
        // itself against a tree this test owns.
        self::loadTool();
        $root = self::syntheticTestsTree([
            'sandbox/tests/regress_flat.php',
            'sandbox/tests/offline/domain/regress_nested.php',
            'sandbox/tests/live/regress_deeply/nested.php',
            'sandbox/tests/offline/domain/not_a_suite.php',
        ]);
        try {
            // Keyed by basename, in sorted-path order. `regress_deeply/` is a
            // directory whose NAME matches the suite pattern and whose file's
            // does not: the pattern applies to the filename, never the path.
            self::assertSame(
                [
                    'regress_nested.php' => 'sandbox/tests/offline/domain/regress_nested.php',
                    'regress_flat.php' => 'sandbox/tests/regress_flat.php',
                ],
                af_suite_files($root)
            );
        } finally {
            self::removeTree($root);
        }
    }

    public function testBasenameIndexIsRecursiveAndKeepsEverySameNamedCandidate(): void
    {
        self::loadTool();
        $index = af_basename_index(self::repoRoot());

        // Recursive: a file two levels below sandbox/tests resolves at all.
        self::assertSame(
            ['sandbox/tests/offline/guards/regress_suite_wiring.php'],
            $index['regress_suite_wiring.php'] ?? null
        );
        // Multi-valued: regress_rehearse_provider.sh runs `php
        // "$FIX/make-fixture.php"`, where $FIX is a shell variable this scan
        // does not evaluate. Two real files answer to that basename, so both
        // are kept -- picking one would link the suite to the fixture it does
        // NOT run and drop the one it does.
        self::assertSame(
            [
                'sandbox/tests/fixtures/assess/make-fixture.php',
                'sandbox/tests/fixtures/rehearse/make-fixture.php',
            ],
            $index['make-fixture.php'] ?? null
        );
        self::assertSame(['sandbox/tests/lib/check.php'], $index['check.php'] ?? null);
    }

    public function testEitherSameNamedFixtureSelectsTheSuiteThatRunsIt(): void
    {
        // The consequence of the multi-valued index, at CLI level: before the
        // recursion neither of these two-levels-down fixtures selected
        // anything at all.
        self::assertContains(
            'regress-rehearse-provider',
            self::targets(['--paths=sandbox/tests/fixtures/rehearse/make-fixture.php'])
        );
        self::assertContains(
            'regress-assess-bounds',
            self::targets(['--paths=sandbox/tests/fixtures/assess/make-fixture.php'])
        );
    }

    public function testSandboxRelativeSuiteReferenceSelectsTheSuiteThatNamesIt(): void
    {
        // The proven coverage edge of issue #3482, at CLI level. Nothing in the
        // index referenced this grind: the only offline suite that guards it,
        // regress_grind_r1c_manifest_preserve.sh, cds to sandbox/ (:12) and so
        // spells it `G=tests/grind/grind_r1c_agency.sh` (:17) -- a token with
        // no root-directory prefix and no __DIR__ base, invisible to every
        // extractor this tool had. Editing the grind selected NOTHING at all.
        $targets = self::targets(['--paths=sandbox/tests/grind/grind_r1c_agency.sh']);
        self::assertContains('regress-grind-r1c-manifest-preserve', $targets);
        // regress_proof_legacy_pair.sh:116 names all three r1 grinds the same
        // way on one line, which is the multi-token case on the same rule.
        self::assertContains('regress-proof-legacy-pair', $targets);

        // The other class of consumer in the inventory: the certify matrices,
        // named only sandbox-relatively by the premise inventory (78 tokens in
        // regress_target_observation_premises.sh alone).
        self::assertContains(
            'regress-target-observation-premises',
            self::targets(['--paths=sandbox/tests/certify/certify_deletion_matrix.sh'])
        );
    }

    public function testSandboxRelativeExtractorResolvesOnlyInsideSandboxAndOnlyWhenItLands(): void
    {
        // The rule itself, stated against a tree this test owns, so it survives
        // the corpus being rearranged again. The authority for the rule is the
        // prose block above ms_sandbox_relative_tail() in
        // tools/codemod/move-suites.php; this pins the reader's half of it.
        self::loadTool();
        $root = self::syntheticTestsTree([
            'sandbox/tests/grind/grind_x.sh',
            'sandbox/tests/offline/guards/regress_x.sh',
            'tests/Tooling/SomeToolTest.php',
        ]);
        try {
            $suite = 'sandbox/tests/offline/guards/regress_x.sh';

            // Resolves: the token lands on a real corpus file.
            self::assertSame(
                ['sandbox/tests/grind/grind_x.sh'],
                af_extract_sandbox_relative_paths($root, $suite, 'G=tests/grind/grind_x.sh')
            );

            // Does NOT resolve: sandbox/tests/Tooling/SomeToolTest.php does not
            // exist, so the repo-root PHPUnit file this names is not a corpus
            // reference and contributes no edge.
            self::assertSame(
                [],
                af_extract_sandbox_relative_paths($root, $suite, 'see tests/Tooling/SomeToolTest.php')
            );

            // Not governed: the same token in a file OUTSIDE sandbox/ means the
            // repo-root tree, never the corpus.
            self::assertSame(
                [],
                af_extract_sandbox_relative_paths($root, 'tools/affected.php', 'G=tests/grind/grind_x.sh')
            );

            // The lookbehind's job: a repo-relative mention must not ALSO read
            // as a sandbox-relative candidate resolving to
            // sandbox/sandbox/tests/... The rooted regex already indexes it.
            self::assertSame(
                [],
                af_extract_sandbox_relative_paths($root, $suite, 'bash sandbox/tests/grind/grind_x.sh')
            );
        } finally {
            self::removeTree($root);
        }
    }

    public function testDifferentlyNamedShWrapperSelectsItsWrapperTarget(): void
    {
        // regress_fatal_mutations_unit.sh wraps the differently-named helper
        // regress_fatal_mutations.php; a change to the helper must still
        // select the .sh wrapper's target, not a (non-existent) target
        // derived from the helper's own filename.
        $targets = self::targets(['--paths=sandbox/tests/offline/apply/regress_fatal_mutations.php']);
        self::assertContains('regress-fatal-mutations-unit', $targets);
    }

    public function testUnknownFileHonorsClosedFullDecision(): void
    {
        $result = self::invoke(['--paths=README-nonexistent-xyz.md']);
        self::assertSame(0, $result['status']);
        $targets = preg_split('/\R/', trim($result['stdout']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        self::assertCount(self::expectedOfflineLeafCount(), $targets);
        self::assertSame('', $result['stderr']);
    }

    public function testQuietDoesNotChangeClosedFullSelection(): void
    {
        $result = self::invoke(['--paths=README-nonexistent-xyz.md', '--quiet']);
        self::assertSame(0, $result['status']);
        self::assertSame('', $result['stderr']);
        $targets = preg_split('/\R/', trim($result['stdout']), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        self::assertCount(self::expectedOfflineLeafCount(), $targets);
    }

    public function testExplainFormatNamesTargetChangedFileAndReason(): void
    {
        $result = self::invoke(['--paths=agent/src/Kernel/PathSafety.php', '--explain']);
        self::assertSame(0, $result['status']);
        self::assertMatchesRegularExpression(
            '/^regress-path-safety <- agent\/src\/Kernel\/PathSafety\.php \(why: (require|self|class|path|dir:\S+)\)$/m',
            $result['stdout']
        );
    }

    public function testJsonModeEmitsValidJsonWithTargetsKey(): void
    {
        $result = self::invoke(['--paths=agent/src/Kernel/PathSafety.php', '--json']);
        self::assertSame(0, $result['status']);
        $decoded = json_decode($result['stdout'], true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('targets', $decoded);
        self::assertContains('regress-path-safety', $decoded['targets']);
    }

    public function testLargeClosedFullJsonSelectionDoesNotMaterializeUnrequestedExplanationRows(): void
    {
        $paths = [];
        for ($index = 0; $index < 800; $index++) {
            $paths[] = sprintf('docs/affected-scale-%04d.md', $index);
        }

        $result = self::invokeWithMemoryLimit([
            '--paths=' . implode(',', $paths),
            '--json',
        ], '128M');
        self::assertSame(0, $result['status'], $result['stderr']);
        $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);

        self::assertCount(self::expectedOfflineLeafCount(), $decoded['targets']);
        self::assertSame($paths, $decoded['changed_files']);
        self::assertArrayNotHasKey('explain', $decoded);
    }

    public function testClosedFullJsonExplainRetainsEveryRequestedTargetPathReason(): void
    {
        $path = 'docs/affected-explain-probe.md';
        $result = self::invoke(['--paths=' . $path, '--json', '--explain']);
        self::assertSame(0, $result['status'], $result['stderr']);
        $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(
            json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
            $result['stdout'],
            'streaming must retain the established pretty-printed JSON bytes'
        );

        self::assertCount(self::expectedOfflineLeafCount(), $decoded['explain']);
        self::assertSame($decoded['targets'], array_column($decoded['explain'], 'target'));
        self::assertSame(
            array_fill(0, self::expectedOfflineLeafCount(), $path),
            array_column($decoded['explain'], 'file')
        );
        self::assertSame(
            ['closed-full:uncovered_path'],
            array_values(array_unique(array_column($decoded['explain'], 'why')))
        );
    }

    public function testLargeClosedFullJsonExplainStreamsBelowSupportedMemoryCeiling(): void
    {
        $paths = [];
        for ($index = 0; $index < 800; $index++) {
            $paths[] = sprintf('docs/affected-explain-scale-%04d.md', $index);
        }

        $result = self::invokeWithMemoryLimitToFile([
            '--paths=' . implode(',', $paths),
            '--json',
            '--explain',
        ], '128M');
        try {
            self::assertSame(0, $result['status'], $result['stderr']);
            self::assertSame('', $result['stderr']);

            $handle = fopen($result['path'], 'r');
            self::assertIsResource($handle);
            $rows = 0;
            $whyRows = 0;
            while (($line = fgets($handle)) !== false) {
                $rows += str_contains($line, '"target":') ? 1 : 0;
                $whyRows += str_contains($line, '"why": "closed-full:uncovered_path"') ? 1 : 0;
            }
            fclose($handle);

            $expectedRows = self::expectedOfflineLeafCount() * count($paths);
            self::assertSame($expectedRows, $rows);
            self::assertSame($expectedRows, $whyRows);
            self::assertStringEndsWith("\n}\n", (string) file_get_contents(
                $result['path'],
                false,
                null,
                max(0, (int) filesize($result['path']) - 3)
            ));
        } finally {
            @unlink($result['path']);
        }
    }

    public function testUnknownOptionExitsTwo(): void
    {
        self::assertSame(2, self::invoke(['--nonsense'])['status']);
    }

    public function testExplicitPathsRejectLossyNormalizationBeforeOwnershipClassification(): void
    {
        foreach ([
            'adapter-packages/acf/../woocommerce/package/manifest.json',
            'adapter-packages\\acf\\package\\manifest.json',
            ' adapter-packages/acf/package/manifest.json',
            'adapter-packages/acf//package/manifest.json',
            '',
        ] as $path) {
            $result = self::invoke(['--paths=' . $path]);
            self::assertSame(2, $result['status'], "non-canonical path unexpectedly selected work: $path");
            self::assertSame('', $result['stdout']);
            self::assertStringContainsString('non-canonical repo-relative path', $result['stderr']);
        }
    }

    // ------------------------------------------------------------------
    // Extractor-level cases. Each pins a literal source shape that was
    // silently invisible to the index and therefore selected nothing.
    // ------------------------------------------------------------------

    private static function loadTool(): void
    {
        if (!function_exists('af_extract_paths')) {
            require_once self::repoRoot() . '/tools/affected.php';
        }
    }

    /**
     * A scratch repo root holding exactly $relativePaths, directories created
     * as needed. Used by the cases that state a walk's RULE rather than pin
     * the real corpus, so they survive W1-W3 moving files around.
     *
     * @param list<string> $relativePaths
     */
    private static function syntheticTestsTree(array $relativePaths): string
    {
        $root = (string) tempnam(sys_get_temp_dir(), 'wprism-affected-test-');
        unlink($root);
        foreach ($relativePaths as $relative) {
            $full = $root . '/' . $relative;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0o777, true);
            }
            file_put_contents($full, "<?php // synthetic\n");
        }

        return $root;
    }

    private static function removeTree(string $path): void
    {
        if (is_dir($path) && !is_link($path)) {
            foreach (scandir($path) ?: [] as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    self::removeTree($path . '/' . $entry);
                }
            }
            rmdir($path);

            return;
        }
        if (file_exists($path)) {
            unlink($path);
        }
    }

    /** @return list<string> */
    private static function extractedPaths(string $text): array
    {
        self::loadTool();
        $out = [];
        foreach (af_extract_paths(self::repoRoot(), $text) as $ref) {
            $out[] = $ref['path'];
        }
        sort($out, SORT_STRING);
        return $out;
    }

    /**
     * The four spellings that dominate this repo. Every one of them puts a
     * `/`, `.` or `-` immediately in front of the root directory, which the
     * original negative lookbehind excluded -- so all four extracted nothing
     * and sandbox/lib, scripts/ and every `$root . '/agent/...'` require were
     * invisible to the index.
     *
     * @return array<string,array{string,string}>
     */
    public static function rootedPathShapes(): array
    {
        return [
            'dir-relative require' => [
                "require __DIR__ . '/../../agent/src/Kernel/Canon.php';",
                'agent/src/Kernel/Canon.php',
            ],
            'variable-rooted require' => [
                "require \$root . '/agent/src/Repository/IdentityBackup.php';",
                'agent/src/Repository/IdentityBackup.php',
            ],
            'shell double-quoted root' => [
                'LOCK_LIB="$ROOT/sandbox/lib/pair_budget_lock.sh"',
                'sandbox/lib/pair_budget_lock.sh',
            ],
            'bare relative climb' => [
                'HELPER=../../scripts/close-gate-check.sh',
                'scripts/close-gate-check.sh',
            ],
        ];
    }

    #[DataProvider('rootedPathShapes')]
    public function testRootedPathTokensSurviveALeadingSlashOrDot(string $line, string $expected): void
    {
        self::assertContains($expected, self::extractedPaths($line));
    }

    public function testRootedPathRegexStillRejectsMidIdentifierMatches(): void
    {
        // The lookbehind's only job: `myagent/src/Canon.php` is a different
        // (non-existent) file and must not register agent/src/Kernel/Canon.php.
        self::assertSame([], self::extractedPaths("require 'myagent/src/Canon.php';"));
    }

    public function testNormalizePathCollapsesDotDotWithoutEscapingTheRoot(): void
    {
        self::loadTool();
        self::assertSame('agent/src/Repository/Ledger.php', af_normalize_path('sandbox/tests/../../agent/src/Repository/Ledger.php'));
        self::assertSame('agent/src/Kernel/Canon.php', af_normalize_path('./agent/./src/Kernel/Canon.php'));
        // A climb that would leave the repo is clamped, never emitted.
        self::assertSame('etc/passwd', af_normalize_path('../../../etc/passwd'));
        self::assertSame('', af_normalize_path('sandbox/..'));
    }

    public function testGitDiffParserPreservesRenameIdentityAndFlattensDependencyPaths(): void
    {
        self::loadTool();
        $parsed = af_parse_diff_name_status(
            "R100\0agent/src/Kernel/Old.php\0cli/src/Kernel/New.php\0"
                . "M\0agent/src/Kernel/Canon.php\0"
        );

        self::assertSame([
            ['from' => 'agent/src/Kernel/Old.php', 'to' => 'cli/src/Kernel/New.php'],
            'agent/src/Kernel/Canon.php',
        ], $parsed['changes']);
        self::assertSame([
            'agent/src/Kernel/Old.php',
            'cli/src/Kernel/New.php',
            'agent/src/Kernel/Canon.php',
        ], $parsed['paths']);
        self::assertSame(
            'cross_root_rename',
            \WPrism\Tooling\AdapterChangeScopeDecision::decide($parsed['changes'])['reason_code']
        );
    }

    public function testGitDiffDiscoveryFailureRefusesInsteadOfReturningNoChanges(): void
    {
        self::loadTool();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('git diff --name-status -M -z failed');
        af_git_diff_changes(self::repoRoot(), 'refs/heads/wprism-definitely-missing');
    }

    public function testGitStatusDiscoveryFailureRefusesInsteadOfReturningNoChanges(): void
    {
        self::loadTool();
        $root = self::syntheticTestsTree([]);
        mkdir($root, 0o777, true);
        try {
            af_git_status_changes($root);
            self::fail('a non-repository status query returned an empty green change set');
        } catch (\RuntimeException $failure) {
            self::assertStringContainsString('git status --porcelain=v1 -z failed', $failure->getMessage());
        } finally {
            self::removeTree($root);
        }
    }

    public function testGitStatusParserUsesDestinationThenSourceRenameOrder(): void
    {
        self::loadTool();
        $parsed = af_parse_porcelain_status(
            "R  cli/src/Kernel/New.php\0agent/src/Kernel/Old.php\0"
                . "?? adapter-packages/acf/tests/offline/regress_new.php\0"
        );

        self::assertSame([
            ['from' => 'agent/src/Kernel/Old.php', 'to' => 'cli/src/Kernel/New.php'],
            'adapter-packages/acf/tests/offline/regress_new.php',
        ], $parsed['changes']);
        self::assertSame([
            'agent/src/Kernel/Old.php',
            'cli/src/Kernel/New.php',
            'adapter-packages/acf/tests/offline/regress_new.php',
        ], $parsed['paths']);
    }

    public function testGitDiscoveryRefusesEveryLossyRawPathShape(): void
    {
        self::loadTool();
        $records = [
            'diff path' => static fn(): array => af_parse_diff_name_status(
                "A\0 adapter-packages/acf/rogue.php\0"
            ),
            'diff rename source' => static fn(): array => af_parse_diff_name_status(
                "R100\0adapter-packages/acf/old.php \0adapter-packages/acf/new.php\0"
            ),
            'diff copy destination' => static fn(): array => af_parse_diff_name_status(
                "C100\0adapter-packages/acf/source.php\0adapter-packages\\acf/copy.php\0"
            ),
            'status path' => static fn(): array => af_parse_porcelain_status(
                "??  adapter-packages/acf/rogue.php\0"
            ),
            'status rename source' => static fn(): array => af_parse_porcelain_status(
                "R  adapter-packages/acf/new.php\0adapter-packages/acf/old.php \0"
            ),
            'status copy destination' => static fn(): array => af_parse_porcelain_status(
                "C  adapter-packages\\acf/copy.php\0adapter-packages/acf/source.php\0"
            ),
        ];

        foreach ($records as $label => $parse) {
            $message = null;
            try {
                $parse();
            } catch (\RuntimeException $failure) {
                $message = $failure->getMessage();
            }
            self::assertNotNull($message, "$label was lossily normalized instead of refused");
            self::assertStringContainsString('non-canonical repo-relative path from git', $message, $label);
        }
    }

    public function testDirRelativeExtractorResolvesDotDotToACanonicalPath(): void
    {
        self::loadTool();
        $refs = af_extract_same_dir_requires(
            self::repoRoot(),
            'sandbox/tests/regress_example.php',
            "require_once __DIR__ . '/../../agent/src/Repository/Ledger.php';"
        );
        self::assertSame(['agent/src/Repository/Ledger.php'], $refs);
    }

    public function testDirnameBasesAreResolved(): void
    {
        self::loadTool();
        // regress_code_release.php's real shape: dirname(__DIR__) from
        // sandbox/tests is sandbox/, so '/tests/fixtures/x.php' lands back
        // inside sandbox/tests/fixtures.
        self::assertContains(
            'sandbox/tests/fixtures/code-release-provider.php',
            af_extract_dir_relative_paths(
                self::repoRoot(),
                'sandbox/tests/regress_example.php',
                "\$p = dirname(__DIR__) . '/tests/fixtures/code-release-provider.php';"
            )
        );
        // dirname(__DIR__, 2) climbs two levels, back to the repo root.
        self::assertContains(
            'sandbox/tests/lib/check.php',
            af_extract_dir_relative_paths(
                self::repoRoot(),
                'sandbox/tests/regress_example.php',
                "\$p = dirname(__DIR__, 2) . '/sandbox/tests/lib/check.php';"
            )
        );
        // The require-shaped variant of the same base.
        self::assertSame(
            ['sandbox/tests/lib/check.php'],
            af_extract_same_dir_requires(
                self::repoRoot(),
                'sandbox/tests/regress_example.php',
                "require_once dirname(__DIR__) . '/tests/lib/check.php';"
            )
        );
    }

    public function testEscapedClassTokenInsideAStringLiteralResolves(): void
    {
        self::loadTool();
        $classMap = af_class_map(self::repoRoot());
        // Load-boundary suites name the classes that must NOT be loaded as
        // string literals, where the source bytes carry two backslashes.
        $files = af_extract_class_files(
            "foreach (['WPrism\\\\Capture', 'WPrism\\\\Policy'] as \$forbidden) {}",
            $classMap
        );
        self::assertContains('agent/src/Capture/Capture.php', $files);
        self::assertContains('agent/src/Policy/Policy.php', $files);
        // The single-backslash (real namespace) form still works.
        self::assertContains('agent/src/Kernel/Canon.php', af_extract_class_files('use WPrism\\Canon;', $classMap));
    }

    public function testRootedDirectoryLiteralsBecomeDirectoryDependencies(): void
    {
        self::loadTool();
        $dirs = af_extract_dirs(
            self::repoRoot(),
            "\$dir = \$repo . '/adapter-packages';\nscandir(\$dir);"
        );
        self::assertContains('adapter-packages', $dirs);
        self::assertContains(
            'platform/adapter-library',
            af_extract_dirs(self::repoRoot(), "scandir(\$root . '/platform/adapter-library')")
        );
    }

    public function testDirectorySignalNeverSwallowsAPreciselyIndexedTree(): void
    {
        self::loadTool();
        // sandbox/tests is resolved file-by-file by the basename index; if a
        // token like this registered it as a directory dependency, every edit
        // to any test file would select that suite and the tool would
        // degenerate to --all.
        $dirs = af_extract_dirs(self::repoRoot(), 'cd "$ROOT/sandbox/tests" || exit 1');
        self::assertNotContains('sandbox/tests', $dirs);
        self::assertNotContains('sandbox', $dirs);
        // A bare prose word is not a path and must register nothing.
        self::assertSame([], af_extract_dirs(self::repoRoot(), 'See the docs and the spec for details.'));
    }

    public function testDirectorySignalAlsoRejectsSubdirectoriesOfAnIndexedTree(): void
    {
        // The same collapse, one level down: once suites live under
        // sandbox/tests/<class>/<domain>, a bare `cd` to one of those
        // directories must not make the whole domain a dependency of the suite
        // that wrote it. af_dir_signal_excluded()'s descendant arm is what
        // stops it; these directories exist on disk, so they reach that arm
        // rather than being dropped by the is_dir() gate before it.
        self::loadTool();
        foreach (['sandbox/tests/offline', 'sandbox/tests/offline/guards'] as $dir) {
            self::assertDirectoryExists(self::repoRoot() . '/' . $dir);
            self::assertSame(
                [],
                af_extract_dirs(self::repoRoot(), 'cd "$ROOT/' . $dir . '" || exit 1'),
                "$dir must never become a whole-directory dependency"
            );
        }
    }

    public function testAdapterPackageChangesSelectOnlyItsClosedPackageAndScenarioTasks(): void
    {
        foreach ([
            'adapter-packages/woocommerce/package/manifest.json',
            'adapter-packages/woocommerce/tests/offline/regress_future_probe.php',
        ] as $path) {
            self::assertSame([
                'adapter-package:woocommerce',
                'integration-scenario:rank-math-commerce-multilingual:offline:regress_rank_math_commerce_multilingual_contract.php',
                'integration-scenario:woocommerce-rewrite-coinstall:offline:regress_woocommerce_hierarchy_lookups.php',
            ], self::targets(['--paths=' . $path]));
        }
    }

    public function testPlatformChangesStillReachTheirAggregateReaders(): void
    {
        $platform = self::targets(['--paths=platform/adapter-library/core/manifest.json']);
        self::assertContains('regress-manifest-dispositions', $platform);
        self::assertContains('regress-adapter-packages', $platform);
    }

    public function testAdapterJsonCarriesCheckedCommandsAndParticipantReasons(): void
    {
        $result = self::invoke([
            '--paths=adapter-packages/polylang/package/manifest.json',
            '--json',
            '--explain',
        ]);
        self::assertSame(0, $result['status'], $result['stderr']);
        $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);

        self::assertSame('adapter-package:polylang', $decoded['tasks'][0]['target']);
        self::assertSame(
            ['php', 'tools/adapter-package-tests.php', '--adapter=polylang'],
            $decoded['tasks'][0]['command']
        );
        self::assertSame(
            ['adapter-package', 'participant-scenario', 'participant-scenario'],
            array_column($decoded['explain'], 'why')
        );
        self::assertSame([
            'integration-scenario:polylang-tec-rewrite-coinstall:live:regress_polylang_tec_rewrite_coinstall.sh',
            'integration-scenario:rank-math-commerce-multilingual:live:regress_rank_math_commerce_multilingual.sh',
            'integration-scenario:woocommerce-rewrite-coinstall:live:regress_woocommerce_rewrite_coinstall.sh',
        ], array_column($decoded['advisories'], 'target'));
    }

    public function testEngineChangesConservativelySelectPackageConsumers(): void
    {
        $result = self::invoke([
            '--paths=agent/src/Kernel/PathSafety.php',
            '--json',
            '--explain',
        ]);
        self::assertSame(0, $result['status'], $result['stderr']);
        $decoded = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);

        self::assertContains('regress-adapter-packages', $decoded['targets']);
        self::assertContains('adapter-sdk-conservative', array_column($decoded['explain'], 'why'));
    }

    public function testSharedShellLibrariesSelectTheirConsumers(): void
    {
        $targets = self::targets(['--paths=sandbox/lib/pair_bootstrap.sh']);
        self::assertContains('regress-pair-bootstrap-unit', $targets);
        self::assertContains('regress-pair-candidate-source', $targets);
        self::assertContains(
            'regress-close-gate-parent-count',
            self::targets(['--paths=scripts/close-gate-check.sh'])
        );
    }

    public function testFreshProcessWorkerFileSelectsItsCallersSuites(): void
    {
        // cli/src/Refresh/RefreshPlan.php holds `$worker = __DIR__ .
        // '/RefreshPlanCompile.php';` and runs it as a separate process --
        // an edge the require-only source graph could not see.
        $targets = self::targets(['--paths=cli/src/Refresh/RefreshPlanCompile.php']);
        self::assertNotSame([], $targets);
        self::assertContains('regress-refresh-compile-refs', $targets);
    }

    public function testStoredIndexContainsNoUnreachableDotDotRefs(): void
    {
        // Selection is exact string equality against a path git printed, and
        // git only ever prints the collapsed spelling, so any stored ref
        // containing '..' is provably dead weight that silently matches
        // nothing. af_build_index() warns on stderr; assert both.
        $result = self::invoke(['--paths=Makefile', '--rebuild-index', '--quiet']);
        self::assertSame(0, $result['status']);
        self::assertStringNotContainsString('non-canonical ref', $result['stderr']);

        $cache = self::repoRoot() . '/sandbox/tmp/affected-index.json';
        self::assertFileExists($cache);
        $decoded = json_decode((string) file_get_contents($cache), true);
        self::assertIsArray($decoded);
        $offenders = [];
        foreach ($decoded['index']['targets'] ?? [] as $target => $data) {
            foreach (array_keys($data['refs'] ?? []) as $path) {
                if (str_contains('/' . $path . '/', '/../')) {
                    $offenders[] = $target . ' -> ' . $path;
                }
            }
        }
        self::assertSame([], $offenders, 'index holds refs no changed file can ever equal');
    }

    public function testFingerprintCoversTheSelectorItselfAndEveryGatedRootDir(): void
    {
        self::loadTool();
        $root = self::repoRoot();
        $inputs = af_index_inputs($root);
        // The index is a product of this file's extraction regexes as much as
        // of the corpus; without it, fixing an extractor leaves the cached
        // (pre-fix) answer being served at exit 0.
        self::assertContains($root . '/tools/affected.php', $inputs);
        self::assertContains($root . '/Makefile', $inputs);

        // Every root dir the extractors gate through is_file()/is_dir():
        // adding or removing a file there flips index entries.
        foreach ([
            'adapter-packages',
            'platform',
            'integration-scenarios',
            'docs',
            'scripts',
            'spec',
            'sandbox/bin',
            'sandbox/conformance',
        ] as $dir) {
            $prefix = $root . '/' . $dir . '/';
            $covered = false;
            foreach ($inputs as $file) {
                if (str_starts_with($file, $prefix)) {
                    $covered = true;
                    break;
                }
            }
            self::assertTrue($covered, "fingerprint must cover $dir");
        }

        // sandbox/tmp holds the cache itself; including it would make every
        // write self-invalidate the entry it just produced.
        foreach ($inputs as $file) {
            self::assertStringNotContainsString('/sandbox/tmp/', $file);
        }
    }
}
