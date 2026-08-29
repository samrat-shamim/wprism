<?php

declare(strict_types=1);

namespace WPrism\Tests\Tooling;

use WPrism\Tooling\AdapterPackageMakeTarget;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageMakeTarget.php';

final class AdapterPackageMakeTargetTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        $this->scratch = sys_get_temp_dir() . '/wprism-adapter-make-target-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch . '/adapter-packages', 0777, true));
        $root = realpath($this->scratch);
        self::assertNotFalse($root);
        $this->scratch = $root;
    }

    protected function tearDown(): void
    {
        self::removeTree($this->scratch);
    }

    public function testCurrentCompatibilityTargetResolvesWithoutAMakefilePackagePath(): void
    {
        $repo = dirname(__DIR__, 2);

        $test = AdapterPackageMakeTarget::resolve($repo, 'regress-woocommerce-contract');

        self::assertSame('woocommerce', $test['adapter']);
        self::assertSame('offline', $test['class']);
        self::assertSame(
            'adapter-packages/woocommerce/tests/offline/regress_woocommerce_contract.php',
            $test['path']
        );
        $makefile = (string) file_get_contents($repo . '/Makefile');
        self::assertDoesNotMatchRegularExpression(
            '/^\t[^\n]*adapter-packages\/[a-z0-9-]+\/tests\//m',
            $makefile
        );
    }

    public function testSpikeEAggregateResolvesThePackageOwnedAcfSpike(): void
    {
        $repo = dirname(__DIR__, 2);

        $test = AdapterPackageMakeTarget::resolve($repo, 'spike-e-acf');

        self::assertSame('acf', $test['adapter']);
        self::assertSame('spike', $test['class']);
        self::assertSame(
            'adapter-packages/acf/tests/spike/spike_e_acf.sh',
            $test['path']
        );

        $make = self::runMakeDry('spike-e');
        self::assertSame(0, $make['status'], $make['stdout'] . $make['stderr']);
        self::assertStringContainsString(
            'php tools/adapter-package-make-target.php --target-from-make',
            $make['stdout']
        );
    }

    public function testSuiteAdditionAndRenameNeedNoCentralProjection(): void
    {
        $first = $this->suite('new-adapter', 'offline', 'regress_first_name.php');

        $resolved = AdapterPackageMakeTarget::resolve($this->scratch, 'regress-first-name');
        self::assertSame('adapter-packages/new-adapter/tests/offline/regress_first_name.php', $resolved['path']);

        $renamed = dirname($first) . '/regress_renamed_suite.php';
        self::assertTrue(rename($first, $renamed));
        try {
            AdapterPackageMakeTarget::resolve($this->scratch, 'regress-first-name');
            self::fail('A removed suite retained a synthetic central target');
        } catch (RuntimeException $failure) {
            self::assertStringContainsString('No adapter package suite maps', $failure->getMessage());
        }

        $resolved = AdapterPackageMakeTarget::resolve($this->scratch, 'regress-renamed-suite');
        self::assertSame('adapter-packages/new-adapter/tests/offline/regress_renamed_suite.php', $resolved['path']);
        self::assertFileDoesNotExist($this->scratch . '/Makefile');
    }

    public function testDuplicateSuiteTargetsAcrossPackagesRefuseLoudly(): void
    {
        $this->suite('alpha', 'offline', 'regress_shared.php');
        $this->suite('beta', 'live', 'regress_shared.sh');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Adapter package make target 'regress-shared' is ambiguous");
        AdapterPackageMakeTarget::resolve($this->scratch, 'regress-shared');
    }

    public function testCompatibilityCliPreservesRepositoryRootWorkingDirectory(): void
    {
        $marker = $this->scratch . '/cwd.txt';
        $this->suite(
            'fixture',
            'offline',
            'regress_cli_probe.php',
            '<?php file_put_contents(' . var_export($marker, true) . ', getcwd());'
        );
        $repo = dirname(__DIR__, 2);
        $process = proc_open(
            [
                PHP_BINARY,
                $repo . '/tools/adapter-package-make-target.php',
                '--repo=' . $this->scratch,
                '--target=regress-cli-probe',
            ],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), $stdout . $stderr);
        self::assertSame($this->scratch, file_get_contents($marker));
    }

    public function testMakeDispatcherKeepsMetacharacterGoalOutOfShellSyntax(): void
    {
        $process = self::runMake('regress-no-such-suite;printf WPRISM_INJECTED');

        self::assertNotSame(0, $process['status'], $process['stdout'] . $process['stderr']);
        self::assertStringNotContainsString('WPRISM_INJECTED', $process['stdout']);
        self::assertStringContainsString('Adapter package make target is not canonical', $process['stderr']);
    }

    public function testMakeDispatcherPreservesUniqueAliasAndUnknownRefusal(): void
    {
        $known = self::runMake('regress-elementor-dead-guard');
        self::assertSame(0, $known['status'], $known['stdout'] . $known['stderr']);
        self::assertStringContainsString('REGRESS_ELEMENTOR_DEAD_GUARD PASSED', $known['stdout']);

        $unknown = self::runMake('regress-no-such-suite');
        self::assertNotSame(0, $unknown['status'], $unknown['stdout'] . $unknown['stderr']);
        self::assertStringContainsString(
            "No adapter package suite maps to make target 'regress-no-such-suite'",
            $unknown['stderr']
        );
    }

    /** @return array{status:int,stdout:string,stderr:string} */
    private static function runMake(string $target): array
    {
        $repo = dirname(__DIR__, 2);
        $process = proc_open(
            ['make', '--no-print-directory', $target],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array{status:int,stdout:string,stderr:string} */
    private static function runMakeDry(string $target): array
    {
        $repo = dirname(__DIR__, 2);
        $process = proc_open(
            ['make', '--no-print-directory', '--just-print', $target],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $repo
        );
        self::assertIsResource($process);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['status' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private function suite(string $slug, string $class, string $name, string $bytes = '<?php'): string
    {
        $directory = $this->scratch . "/adapter-packages/$slug/tests/$class";
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0777, true));
        }
        $path = $directory . '/' . $name;
        self::assertNotFalse(file_put_contents($path, $bytes));
        return $path;
    }

    private static function removeTree(string $path): void
    {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_link($path) || !is_dir($path)) {
            unlink($path);
            return;
        }
        foreach (array_diff(scandir($path) ?: [], ['.', '..']) as $entry) {
            self::removeTree($path . '/' . $entry);
        }
        rmdir($path);
    }
}
