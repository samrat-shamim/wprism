<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

use Duo\Tooling\OfflineCorpus;
use PHPUnit\Framework\TestCase;

/**
 * Pin the derivation that owns the offline corpus.
 *
 * What is at risk is the CURRENCY of one committed file. tools/offline-corpus.mk
 * carries `regress-offline-corpus`'s whole prerequisite list and the one count
 * both status lines print, and the Makefile pulls it in with `include`. A stale
 * one is a gate that runs a different set of suites than the one it reports --
 * exactly the drift that let the two hand-typed counts reach 294 against 293
 * before this landed. `php tools/offline-corpus.php` is the fix for every
 * currency failure this class reports, and `make release-gate` fails the same
 * way.
 *
 * The refusal tests are the other half and the more important one: derivation
 * is only stronger than the convention it replaced if it cannot quietly skip an
 * input. Each of the three refusals in tools/offline-corpus.php's header is
 * driven here against a synthetic tree carrying exactly that defect, so a green
 * line means the detector fired on a planted defect, not that nothing was
 * found. The estate-level version of the same property (a suite deleted from
 * the generated include is refused by name) is also self-tested in
 * sandbox/tests/offline/guards/regress_bundle_coverage.sh, against a copy of
 * the real tree rather than a synthetic one.
 */
final class OfflineCorpusTest extends TestCase
{
    /** @var list<string> */
    private array $roots = [];

    private static function repoRoot(): string
    {
        $env = getenv('DUO_REPO_ROOT');

        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    public static function setUpBeforeClass(): void
    {
        // PHP strips `#!...` only from the ENTRY script, never from an include,
        // so a plain require of an executable tool would print its shebang and
        // trip phpunit.xml.dist's beStrictAboutOutputDuringTests. The file is
        // otherwise side-effect free: its main() is behind the same
        // SCRIPT_FILENAME guard recovery/rollback-control.php uses.
        if (!class_exists(OfflineCorpus::class, false)) {
            ob_start();
            require_once self::repoRoot() . '/tools/offline-corpus.php';
            ob_end_clean();
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->roots as $root) {
            self::removeTree($root);
        }
        $this->roots = [];
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

    /**
     * A synthetic repo root: two offline suites, one live suite, one helper
     * that its sibling runs, and a Makefile that reaches the corpus only
     * through the generated include.
     *
     * @param array<string,string> $extraFiles repo-relative path => contents
     */
    private function fixtureRoot(string $makefileTail = '', array $extraFiles = []): string
    {
        $root = sys_get_temp_dir() . '/duo-offline-corpus-' . bin2hex(random_bytes(6));
        $this->roots[] = $root;
        $files = [
            'sandbox/tests/offline_diagnostics_guard.sh' => "# synthetic guard\n",
            'sandbox/tests/offline/domain/regress_alpha.php' => "<?php\n// synthetic offline suite\n",
            'sandbox/tests/offline/domain/regress_beta.sh' => "# synthetic offline suite\n",
            'sandbox/tests/live/regress_live_one.sh' => "# synthetic live suite\n",
            'Makefile' => implode("\n", [
                '.PHONY: regress-offline-all regress-offline-corpus',
                '',
                'include tools/offline-corpus.mk',
                '',
                'code-half-unit:',
                "\t@echo code-half-unit",
                '',
                'regress-alpha:',
                "\tphp sandbox/tests/offline/domain/regress_alpha.php",
                '',
                'regress-beta:',
                "\tbash sandbox/tests/offline/domain/regress_beta.sh",
                '',
                'regress-live-one:',
                "\tbash sandbox/tests/live/regress_live_one.sh",
                '',
            ]) . $makefileTail,
        ];
        foreach ($extraFiles as $path => $contents) {
            $files[$path] = $contents;
        }
        foreach ($files as $path => $contents) {
            $full = $root . '/' . $path;
            if (!is_dir(dirname($full))) {
                mkdir(dirname($full), 0o777, true);
            }
            file_put_contents($full, $contents);
        }

        return $root;
    }

    /** @return array{status:int,stdout:string,stderr:string} */
    private static function invoke(array $args): array
    {
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(self::repoRoot() . '/tools/offline-corpus.php');
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg((string) $arg);
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open($cmd, $descriptors, $pipes, self::repoRoot());
        self::assertIsResource($process, "could not launch: $cmd");
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    // ------------------------------------------------------------- currency

    public function testCommittedIncludeIsCurrentWithTheTree(): void
    {
        $derived = OfflineCorpus::derive(self::repoRoot());
        self::assertSame([], $derived['refusals'], 'the shipped tree must derive without refusals');
        self::assertGreaterThan(200, count($derived['targets']), 'the real corpus is hundreds of suites');
        self::assertSame(
            (string) file_get_contents(self::repoRoot() . '/' . OfflineCorpus::INCLUDE_PATH),
            OfflineCorpus::render($derived['targets'], $derived['package_targets']),
            'tools/offline-corpus.mk is stale -- run: php tools/offline-corpus.php'
        );
    }

    public function testEveryDerivedTargetIsARealMakefileRule(): void
    {
        $root = self::repoRoot();
        $recipes = OfflineCorpus::parseRecipes(OfflineCorpus::makefileText($root));
        foreach (OfflineCorpus::derive($root)['targets'] as $target) {
            self::assertArrayHasKey($target, $recipes, "derived target '$target' has no Makefile rule");
        }
    }

    public function testBothStatusLinesCarryTheDerivedCount(): void
    {
        $derived = OfflineCorpus::derive(self::repoRoot());
        $rendered = OfflineCorpus::render($derived['targets'], $derived['package_targets']);
        $count = count($derived['targets']);
        self::assertStringContainsString(
            OfflineCorpus::GATE_TARGET . ": $count offline suites green",
            $rendered
        );
        self::assertStringContainsString(
            OfflineCorpus::CORPUS_TARGET . ": $count offline suites green",
            $rendered
        );
    }

    public function testRenderIsSortedAndDeterministic(): void
    {
        $targets = OfflineCorpus::derive(self::repoRoot())['targets'];
        $sorted = $targets;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $targets, 'derivation must emit a stable order or every diff is noise');
        self::assertSame(OfflineCorpus::render($targets), OfflineCorpus::render($targets));
    }

    // ---------------------------------------------------------- derivation

    public function testASyntheticTreeDerivesItsOfflineSuitesOnly(): void
    {
        $derived = OfflineCorpus::derive($this->fixtureRoot());
        self::assertSame([], $derived['refusals']);
        self::assertSame(['regress-alpha', 'regress-beta'], $derived['targets']);
    }

    public function testAHelperItsSiblingRunsIsNotDerivedAsItsOwnTarget(): void
    {
        // The `.sh` wrapper that runs a `.php` of the same stem is the estate's
        // real shape (offline/adapter/regress_adapter_sources.{sh,php}); both
        // claim one target name, so the helper must not derive one of its own.
        $root = $this->fixtureRoot('', [
            'sandbox/tests/offline/domain/regress_beta.sh' =>
                "# runs its own helper\nphp sandbox/tests/offline/domain/regress_gamma.php\n",
            'sandbox/tests/offline/domain/regress_gamma.php' => "<?php\n// helper, no target\n",
        ]);
        $derived = OfflineCorpus::derive($root);
        self::assertSame([], $derived['refusals']);
        self::assertSame(['regress-alpha', 'regress-beta'], $derived['targets']);
        self::assertSame(1, $derived['helpers']);
    }

    public function testOnlyOfflineClassSuitesEnterTheCorpus(): void
    {
        $derived = OfflineCorpus::derive($this->fixtureRoot());
        self::assertNotContains('regress-live-one', $derived['targets']);
    }

    public function testAdapterPackageOfflineSuitesGetGeneratedLeafRulesWithoutAMakefileEdit(): void
    {
        $root = $this->fixtureRoot('', [
            'adapter-packages/acf/tests/offline/regress_acf_owned.php' => "<?php\n",
            'adapter-packages/acf/tests/conformance/regress_acf_live.sh' => "#!/usr/bin/env bash\n",
            'adapter-packages/acf/tests/live/regress_acf_pair.sh' => "#!/usr/bin/env bash\n",
            'adapter-packages/acf/tests/spike/spike_acf_probe.sh' => "#!/usr/bin/env bash\n",
        ]);

        $derived = OfflineCorpus::derive($root);

        self::assertSame([], $derived['refusals']);
        self::assertContains('regress-acf-owned', $derived['targets']);
        self::assertSame([
            'path' => 'adapter-packages/acf/tests/offline/regress_acf_owned.php',
            'runtime' => 'php',
        ], $derived['package_targets']['regress-acf-owned']);
        $rendered = OfflineCorpus::render($derived['targets'], $derived['package_targets']);
        self::assertStringContainsString(
            "regress-acf-owned:\n\tphp adapter-packages/acf/tests/offline/regress_acf_owned.php\n",
            $rendered
        );
        self::assertNotContains('regress-acf-live', $derived['targets']);
        self::assertNotContains('regress-acf-pair', $derived['targets']);
        self::assertNotContains('spike-acf-probe', $derived['targets']);
        self::assertStringContainsString(
            "regress-acf-pair:\n\tbash adapter-packages/acf/tests/live/regress_acf_pair.sh\n",
            $rendered
        );
        self::assertStringContainsString(
            "spike-acf-probe:\n\tbash adapter-packages/acf/tests/spike/spike_acf_probe.sh\n",
            $rendered
        );
    }

    public function testAdapterPackageTargetCollisionIsRefused(): void
    {
        $root = $this->fixtureRoot(
            "\nregress-acf-owned:\n\t@echo unrelated\n",
            ['adapter-packages/acf/tests/offline/regress_acf_owned.php' => "<?php\n"]
        );

        $refusals = OfflineCorpus::derive($root)['refusals'];

        self::assertCount(1, $refusals);
        self::assertStringContainsString('R1 target collision', $refusals[0]);
        self::assertStringContainsString('regress-acf-owned', $refusals[0]);
    }

    public function testIncludeFoldReadsRulesOutOfTheIncludedFragment(): void
    {
        $root = $this->fixtureRoot('', [
            'tools/offline-corpus.mk' => "regress-from-include:\n\t@echo included\n",
        ]);
        $recipes = OfflineCorpus::parseRecipes(OfflineCorpus::makefileText($root));
        self::assertArrayHasKey(
            'regress-from-include',
            $recipes,
            'an `include`d rule must be visible or the corpus reads as empty'
        );
    }

    // ------------------------------------------------------------ refusals

    public function testR1RefusesASuiteNoRecipeRuns(): void
    {
        $root = $this->fixtureRoot('', [
            'sandbox/tests/offline/domain/regress_orphan.php' => "<?php\n// wired nowhere\n",
        ]);
        $refusals = OfflineCorpus::derive($root)['refusals'];
        self::assertCount(1, $refusals);
        self::assertStringContainsString('R1 no target', $refusals[0]);
        self::assertStringContainsString('offline/domain/regress_orphan.php', $refusals[0]);
        self::assertStringContainsString('regress-orphan', $refusals[0]);
    }

    public function testR1RefusesASuiteTwoTargetsClaim(): void
    {
        $root = $this->fixtureRoot(
            "\nregress-alpha-again:\n\tphp sandbox/tests/offline/domain/regress_alpha.php\n"
        );
        $refusals = OfflineCorpus::derive($root)['refusals'];
        self::assertCount(1, $refusals);
        self::assertStringContainsString('R1 ambiguous', $refusals[0]);
        self::assertStringContainsString('regress-alpha-again', $refusals[0]);
    }

    public function testR2RefusesACorpusTargetNamingAFileThatIsNotThere(): void
    {
        $root = $this->fixtureRoot();
        $makefile = (string) file_get_contents($root . '/Makefile');
        file_put_contents($root . '/Makefile', str_replace(
            "\tbash sandbox/tests/offline/domain/regress_beta.sh",
            "\tbash sandbox/tests/offline/domain/regress_beta.sh sandbox/tests/fixtures/gone.php",
            $makefile
        ));
        $refusals = OfflineCorpus::derive($root)['refusals'];
        self::assertCount(1, $refusals);
        self::assertStringContainsString('R2 missing file', $refusals[0]);
        self::assertStringContainsString('sandbox/tests/fixtures/gone.php', $refusals[0]);
    }

    public function testR3ASuiteCannotBeExcludedFromTheGeneratedInclude(): void
    {
        $root = $this->fixtureRoot();
        $written = self::invoke(['--root=' . $root]);
        self::assertSame(0, $written['status'], $written['stderr']);
        self::assertSame(0, self::invoke(['--check', '--root=' . $root])['status']);

        $include = $root . '/' . OfflineCorpus::INCLUDE_PATH;
        file_put_contents($include, str_replace(
            "\tregress-alpha \\\n",
            '',
            (string) file_get_contents($include)
        ));
        $checked = self::invoke(['--check', '--root=' . $root]);
        self::assertSame(1, $checked['status'], 'an excluded suite must refuse');
        self::assertStringContainsString('missing from ' . OfflineCorpus::INCLUDE_PATH, $checked['stderr']);
        self::assertStringContainsString('regress-alpha', $checked['stderr']);
        self::assertStringContainsString('cannot be excluded', $checked['stderr']);
    }

    public function testWriteIsIdempotent(): void
    {
        $root = $this->fixtureRoot();
        self::assertSame(0, self::invoke(['--root=' . $root])['status']);
        $first = (string) file_get_contents($root . '/' . OfflineCorpus::INCLUDE_PATH);
        $again = self::invoke(['--root=' . $root]);
        self::assertSame(0, $again['status']);
        self::assertStringContainsString('already current', $again['stdout']);
        self::assertSame($first, (string) file_get_contents($root . '/' . OfflineCorpus::INCLUDE_PATH));
    }

    public function testARootThatIsNotARepoIsRefused(): void
    {
        $result = self::invoke(['--check', '--root=' . sys_get_temp_dir()]);
        self::assertSame(2, $result['status']);
        self::assertStringContainsString('does not look like a repo root', $result['stderr']);
    }

    public function testTheRealTreeChecksClean(): void
    {
        $result = self::invoke(['--check']);
        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertStringContainsString('matches the tree', $result['stdout']);
    }
}
