<?php

declare(strict_types=1);

namespace Duo\Tests\Tooling;

use Duo\Tooling\AdapterPackageTestDiscovery;
use Duo\Tooling\AdapterPackageTestRunner;
use Duo\Tooling\AdapterPackageTestsCommand;
use Duo\Tooling\AdapterPackageValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageTestDiscovery.php';
require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageTestRunner.php';
require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageTestsCommand.php';
require_once dirname(__DIR__, 2) . '/tools/src/AdapterPackageValidator.php';

final class AdapterPackageTestsTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/duo-adapter-package-tests-' . bin2hex(random_bytes(8));
        self::makeDirectory($this->root . '/adapter-packages');
        $canonical = realpath($this->root);
        self::assertNotFalse($canonical);
        $this->root = $canonical;
    }

    protected function tearDown(): void
    {
        self::removeTree($this->root);
    }

    public function testDiscoveryIsRecursiveDeterministicAndConfinedToTestsClass(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/zeta/regress_z.sh', "#!/usr/bin/env bash\n");
        self::write($package . '/tests/offline/regress_b.php', '<?php');
        self::write($package . '/tests/offline/alpha/regress_a.sh', "#!/usr/bin/env bash\n");
        self::write($package . '/tests/live/regress_live.php', '<?php');
        self::write($package . '/tests/certify/version-matrix.sh', "seed_acf_content() { :; }\n");
        self::write($package . '/tests/conformance/entry.json', "{}\n");
        self::write($package . '/tests/conformance/seed.sh', "seed_acf_content\n");
        self::write($package . '/fixtures/regress_fixture.php', '<?php throw new Exception;');
        self::write($package . '/evidence/regress_evidence.sh', 'exit 99');
        self::write($package . '/package/regress_payload.php', '<?php exit(99);');

        $found = AdapterPackageTestDiscovery::discover($this->root, 'acf');

        self::assertSame('offline', $found['class']);
        self::assertSame([
            'adapter-packages/acf/tests/offline/alpha/regress_a.sh',
            'adapter-packages/acf/tests/offline/regress_b.php',
            'adapter-packages/acf/tests/offline/zeta/regress_z.sh',
        ], array_column($found['tests'], 'path'));
        self::assertSame(['bash', 'php', 'bash'], array_column($found['tests'], 'runtime'));
    }

    public function testCurrentAcfCapsuleValidatesWithoutReadingSiblingPackages(): void
    {
        $result = AdapterPackageValidator::validate(dirname(__DIR__, 2), 'acf');

        self::assertSame(AdapterPackageValidator::FORMAT, $result['format']);
        self::assertSame('acf', $result['adapter']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $result['digest']);
        self::assertSame(['conformance-acf', 'exact-artifact-version-matrix'], $result['evidence_tests']);
        self::assertContains('closed-library', $result['checks']);
        self::assertContains('adapter-identity', $result['checks']);
        self::assertContains('evidence-wiring:2', $result['checks']);
    }

    /** @return iterable<string,array{0:string}> */
    public static function invalidSlugs(): iterable
    {
        yield 'empty' => [''];
        yield 'uppercase' => ['ACF'];
        yield 'underscore' => ['bad_slug'];
        yield 'traversal' => ['../acf'];
        yield 'slash' => ['vendor/acf'];
    }

    #[DataProvider('invalidSlugs')]
    public function testInvalidSlugFailsClosed(string $slug): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('slug is not canonical');
        AdapterPackageTestDiscovery::discover($this->root, $slug);
    }

    public function testMissingPackageFailsClosed(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("package 'acf' is missing");
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testMissingOfflineDirectoryFailsClosed(): void
    {
        $package = $this->package('acf', false);
        self::write($package . '/tests/live/regress_live.php', '<?php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no tests/offline directory');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testEmptyOfflineDirectoryFailsClosed(): void
    {
        $this->package('acf');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('has no class-named *.php/.sh suites');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testUnknownTestClassEntryFailsClosed(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_ok.php', '<?php');
        self::write($package . '/tests/fixtures/data.json', '{}');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown adapter test class entry');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    /** @return iterable<string,array{0:string}> */
    public static function unknownTestFiles(): iterable
    {
        yield 'ordinary fixture' => ['data.json'];
        yield 'wrong prefix' => ['test_product.php'];
        yield 'wrong extension' => ['regress_product.txt'];
        yield 'hidden file' => ['.regress_hidden.php'];
        yield 'empty regression name' => ['regress_.php'];
    }

    #[DataProvider('unknownTestFiles')]
    public function testUnknownFileInAClassFailsClosed(string $filename): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_ok.php', '<?php');
        self::write($package . '/tests/offline/' . $filename, 'unknown');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown adapter test file');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testUnknownFileInAnotherClassStillFailsThePackageBoundary(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_ok.php', '<?php');
        self::write($package . '/tests/live/notes.md', 'not a suite');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unknown adapter test file');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testSymlinkedTestFileFailsClosed(): void
    {
        $package = $this->package('acf');
        self::assertTrue(symlink('/etc/hosts', $package . '/tests/offline/regress_escape.php'));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('must not be a symbolic link');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testSymlinkedPackageCannotEscapeTheRepository(): void
    {
        $outside = $this->root . '-outside';
        self::makeDirectory($outside . '/tests/offline');
        self::write($outside . '/tests/offline/regress_escape.php', '<?php');
        self::assertTrue(symlink($outside, $this->root . '/adapter-packages/acf'));
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('is not an ordinary directory');
            AdapterPackageTestDiscovery::discover($this->root, 'acf');
        } finally {
            self::removeTree($outside);
        }
    }

    public function testSpecialTestNodeFailsClosed(): void
    {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('posix_mkfifo is unavailable');
        }
        $package = $this->package('acf');
        self::assertTrue(posix_mkfifo($package . '/tests/offline/regress_fifo.php', 0600));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not an ordinary file or directory');
        AdapterPackageTestDiscovery::discover($this->root, 'acf');
    }

    public function testRunnerExecutesEveryOfflineTestAndAggregatesOutputAndExits(): void
    {
        $package = $this->package('acf');
        self::write(
            $package . '/tests/offline/regress_01_pass.php',
            '<?php fwrite(STDOUT, "php-out\\n"); fwrite(STDERR, "php-err\\n");'
        );
        self::write(
            $package . '/tests/offline/regress_02_fail.sh',
            "#!/usr/bin/env bash\nprintf 'bash-out\\n'\nprintf 'bash-err\\n' >&2\nexit 3\n"
        );
        self::write(
            $package . '/tests/offline/nested/regress_03_after.php',
            '<?php fwrite(STDOUT, "after-out\\n");'
        );

        $run = AdapterPackageTestRunner::run($this->root, 'acf');

        self::assertSame(AdapterPackageTestRunner::FORMAT, $run['format']);
        self::assertSame('failed', $run['status']);
        self::assertSame(1, $run['exit_code']);
        self::assertSame([
            'adapter-packages/acf/tests/offline/nested/regress_03_after.php',
            'adapter-packages/acf/tests/offline/regress_01_pass.php',
            'adapter-packages/acf/tests/offline/regress_02_fail.sh',
        ], array_column($run['tests'], 'path'));
        self::assertSame([0, 0, 3], array_column($run['tests'], 'exit_code'));
        self::assertSame(["after-out\n", "php-out\n", "bash-out\n"], array_column($run['tests'], 'stdout'));
        self::assertSame(['', "php-err\n", "bash-err\n"], array_column($run['tests'], 'stderr'));
    }

    public function testListAndJsonAreDryRuns(): void
    {
        $package = $this->package('acf');
        $marker = $this->root . '/executed';
        self::write(
            $package . '/tests/offline/regress_marker.php',
            '<?php file_put_contents(' . var_export($marker, true) . ', "ran");'
        );

        $listed = self::invoke([
            '--repo=' . $this->root,
            '--adapter=acf',
            '--list',
        ]);
        self::assertSame(0, $listed['status'], $listed['stderr']);
        self::assertStringContainsString('php adapter-packages/acf/tests/offline/regress_marker.php', $listed['stdout']);
        self::assertFileDoesNotExist($marker);

        $json = self::invoke([
            '--repo=' . $this->root,
            '--adapter=acf',
            '--json',
        ]);
        self::assertSame(0, $json['status'], $json['stderr']);
        $plan = json_decode($json['stdout'], true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($plan);
        self::assertSame(AdapterPackageTestsCommand::PLAN_FORMAT, $plan['format']);
        self::assertSame('acf', $plan['adapter']);
        self::assertSame([
            'path' => 'adapter-packages/acf/tests/offline/regress_marker.php',
            'runtime' => 'php',
            'command' => ['php', 'adapter-packages/acf/tests/offline/regress_marker.php'],
        ], $plan['tests'][0]);
        self::assertFileDoesNotExist($marker);
    }

    public function testCliExecutionReturnsAggregateFailureAfterRunningLaterTests(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_01_fail.sh', "exit 9\n");
        self::write($package . '/tests/offline/regress_02_after.php', '<?php echo "after\\n";');

        $result = self::invoke(['--repo=' . $this->root, '--adapter=acf']);

        self::assertSame(1, $result['status']);
        self::assertStringContainsString('after', $result['stdout']);
        self::assertStringContainsString('1 passed, 1 failed', $result['stdout']);
        self::assertStringContainsString('exited 9', $result['stderr']);
    }

    public function testOtherClassesCanBeListedButNotExecuted(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_offline.php', '<?php');
        self::write($package . '/tests/conformance/regress_contract.sh', "exit 0\n");

        $listed = self::invoke([
            '--repo=' . $this->root,
            '--adapter=acf',
            '--class=conformance',
            '--list',
        ]);
        self::assertSame(0, $listed['status'], $listed['stderr']);
        self::assertStringContainsString('conformance (1)', $listed['stdout']);

        $run = self::invoke([
            '--repo=' . $this->root,
            '--adapter=acf',
            '--class=conformance',
        ]);
        self::assertSame(2, $run['status']);
        self::assertStringContainsString('execution is available only for --class=offline', $run['stderr']);
    }

    public function testSpikeUsesItsClassPrefixAndCanBeListed(): void
    {
        $package = $this->package('acf');
        self::write($package . '/tests/offline/regress_offline.php', '<?php');
        self::write($package . '/tests/spike/spike_acf_probe.sh', "exit 0\n");

        $listed = self::invoke([
            '--repo=' . $this->root,
            '--adapter=acf',
            '--class=spike',
            '--list',
        ]);

        self::assertSame(0, $listed['status'], $listed['stderr']);
        self::assertStringContainsString('acf spike (1)', $listed['stdout']);
        self::assertStringContainsString('bash adapter-packages/acf/tests/spike/spike_acf_probe.sh', $listed['stdout']);
    }

    public function testDiscoveryFailureIsNeverRenderedAsAnEmptyGreenList(): void
    {
        $result = self::invoke([
            '--repo=' . $this->root,
            '--adapter=missing',
            '--json',
        ]);

        self::assertSame(1, $result['status']);
        self::assertSame('', $result['stdout']);
        self::assertStringContainsString("package 'missing' is missing", $result['stderr']);
    }

    /**
     * @param list<string> $arguments
     * @return array{status:int,stdout:string,stderr:string}
     */
    private static function invoke(array $arguments): array
    {
        $repo = dirname(__DIR__, 2);
        $process = proc_open(
            [PHP_BINARY, $repo . '/tools/adapter-package-tests.php', ...$arguments],
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

    private function package(string $slug, bool $offline = true): string
    {
        $package = $this->root . '/adapter-packages/' . $slug;
        self::makeDirectory($package . '/tests');
        if ($offline) {
            self::makeDirectory($package . '/tests/offline');
        }
        return $package;
    }

    private static function write(string $path, string $bytes): void
    {
        self::makeDirectory(dirname($path));
        self::assertNotFalse(file_put_contents($path, $bytes));
    }

    private static function makeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            self::assertTrue(mkdir($path, 0777, true));
        }
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
        $entries = scandir($path);
        if ($entries !== false) {
            foreach (array_diff($entries, ['.', '..']) as $entry) {
                self::removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
