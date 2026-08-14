<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\Runner;
use Duo\EngineeringPlatform\Selection;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type CatalogAggregate from \Duo\EngineeringPlatform\Catalog
 * @phpstan-import-type Suite from \Duo\EngineeringPlatform\Catalog
 * @phpstan-type TestReceipt array{
 *     authority:string,
 *     catalog_scope:array{kind:string,owner:?string},
 *     execution_plan:list<mixed>,
 *     dependency_lock_sha256:string,
 *     toolchain:array<string,string>,
 *     platform_contract:array{profile_id:string,contract_sha256:string},
 *     state:string,
 *     message:?string,
 *     interrupt_signal:?int,
 *     results:list<array{id:string,log_path:?string,message:?string,timed_out:bool,signal:?int,cleanup:string}>
 * }
 */
final class RunnerTest extends TestCase
{
    private string $root;

    /** @var list<string> */
    private array $cleanupPaths = [];

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 5);
    }

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanupPaths) as $path) {
            $this->removeTree($path);
        }
    }

    public function testPartialReceiptIsBoundAndRedactsAmbientIdentity(): void
    {
        $result = $this->resultPath('partial');
        $suite = $this->suite(
            'runner-redaction-fixture',
            ['php', '-r', 'echo getcwd(), "\n", (getenv("DUO_SECRET_SENTINEL") ?: "absent"), "\n";'],
        );
        $catalog = $this->catalog($suite, 'runner-partial-profile', $result);
        putenv('DUO_SECRET_SENTINEL=review-sentinel-must-not-leak');
        try {
            $exit = (new Runner($this->root, $catalog, $result, 'thread-1'))->run([], 'runner-partial-profile');
        } finally {
            putenv('DUO_SECRET_SENTINEL');
        }

        self::assertSame(0, $exit, (string) file_get_contents($this->root . '/' . $result));
        $receipt = $this->receipt($result);
        self::assertSame('non_authorizing_partial', $receipt['authority']);
        self::assertSame(['kind' => 'partial_owner', 'owner' => 'thread-1'], $receipt['catalog_scope']);
        self::assertNotEmpty($receipt['execution_plan']);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/D', (string) $receipt['dependency_lock_sha256']);
        foreach (['php', 'required.php', 'runner.process-entry', 'runner.setsid', 'suite.runner-redaction-fixture.executable'] as $binding) {
            self::assertArrayHasKey($binding, $receipt['toolchain']);
            self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/D', $receipt['toolchain'][$binding]);
        }
        self::assertMatchesRegularExpression(
            '/^(development|certified)-(linux|darwin)-(x86_64|aarch64)-php-8\\.[23]$/D',
            $receipt['platform_contract']['profile_id'],
        );
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/D', $receipt['platform_contract']['contract_sha256']);
        self::assertArrayNotHasKey('php_version', $receipt['platform_contract']);
        self::assertArrayNotHasKey('os_family', $receipt['platform_contract']);
        self::assertArrayNotHasKey('architecture', $receipt['platform_contract']);
        $first = $receipt['results'][0];
        $logPath = $this->root . '/' . $first['log_path'];
        $this->cleanupPaths[] = dirname($logPath);
        $log = file_get_contents($logPath);
        self::assertIsString($log);
        self::assertStringNotContainsString($this->root, $log);
        self::assertStringNotContainsString('review-sentinel-must-not-leak', $log);
        self::assertSame(0600, fileperms($logPath) & 0777);
    }

    public function testExplicitSuiteRunIsNonAuthorizingDiagnostic(): void
    {
        $result = $this->resultPath('explicit-diagnostic');
        $suite = $this->suite('runner-explicit-fixture', ['php', '-r', 'exit(0);']);
        $catalog = $this->catalog($suite, 'runner-unused-profile', $result);

        self::assertSame(0, (new Runner($this->root, $catalog, $result))->run([$suite['id']], null));
        $receipt = $this->receipt($result);
        self::assertSame('non_authorizing_diagnostic', $receipt['authority']);
        self::assertSame('pass', $receipt['state']);
        $this->trackLogDirectory($receipt);
    }

    public function testResolvedEmptyChangedSelectionProducesNotApplicableReceipt(): void
    {
        $result = $this->resultPath('changed-not-applicable');
        $suite = $this->suite('runner-unselected-fixture', ['php', '-r', 'exit(0);']);
        $catalog = $this->catalog($suite, 'runner-unused-profile', $result);
        $head = $this->gitHead();
        $selection = (new Selection($this->root, $catalog))->changed($head, $head);

        self::assertSame(0, (new Runner($this->root, $catalog, $result, null, $selection))->run([], null));
        $receipt = $this->receipt($result);
        self::assertSame('non_authorizing_advisory', $receipt['authority']);
        self::assertSame('not_applicable', $receipt['state']);
        self::assertSame('selector.changed-paths', $receipt['results'][0]['id'] ?? null);
    }

    public function testShardRunIsNonAuthorizingAndBindsItsPlan(): void
    {
        $result = $this->resultPath('shard');
        $suite = $this->suite('runner-shard-fixture', ['php', '-r', 'exit(0);']);
        $catalog = $this->catalog($suite, 'runner-shard-profile', $result);
        $shard = [
            'candidate_sha' => $this->gitHead(),
            'profile_id' => 'runner-shard-profile',
            'plan_sha256' => 'sha256:' . str_repeat('a', 64),
            'index' => 0,
            'count' => 1,
            'estimated_duration_ms' => 1000,
            'suite_ids' => [$suite['id']],
        ];

        self::assertSame(0, (new Runner($this->root, $catalog, $result, null, null, $shard))->run([$suite['id']], null));
        $receipt = $this->receipt($result);
        self::assertSame('non_authorizing_shard', $receipt['authority']);
        self::assertSame('pass', $receipt['state']);
        $this->trackLogDirectory($receipt);
    }

    public function testRunnerPublishesJUnitAndTapReports(): void
    {
        $result = $this->resultPath('reports');
        $directory = dirname($result);
        $junit = $directory . '/junit.xml';
        $tap = $directory . '/results.tap';
        $suite = $this->suite('runner-report-fixture', ['php', '-r', 'exit(0);']);
        $catalog = $this->catalog($suite, 'runner-report-profile', $result);

        self::assertSame(0, (new Runner($this->root, $catalog, $result, null, null, null, $junit, $tap))
            ->run([$suite['id']], null));
        self::assertStringContainsString('<testsuite name="duo" tests="1"', (string) file_get_contents($this->root . '/' . $junit));
        self::assertStringContainsString("TAP version 13\n1..1\n", (string) file_get_contents($this->root . '/' . $tap));
        $receipt = $this->receipt($result);
        self::assertSame('pass', $receipt['state']);
        $this->trackLogDirectory($receipt);
    }

    public function testUnsafeResultPathIsRejected(): void
    {
        $suite = $this->suite('runner-path-fixture', ['php', '-r', 'exit(0);']);
        $catalog = $this->catalog($suite, 'runner-path-profile', 'artifacts/test-results/path/result.json');

        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('unsafe result or artifact path');
        new Runner($this->root, $catalog, '../outside.json');
    }

    public function testMissingDeclaredOutputFailsAndIsRecorded(): void
    {
        $result = $this->resultPath('missing-output');
        $missing = 'artifacts/test-results/runner-tests/missing-' . bin2hex(random_bytes(4)) . '.json';
        $suite = $this->suite('runner-output-fixture', ['php', '-r', 'exit(0);'], [$missing]);
        $catalog = $this->catalog($suite, 'runner-output-profile', $result);

        self::assertSame(1, (new Runner($this->root, $catalog, $result))->run([], 'runner-output-profile'));
        $receipt = $this->receipt($result);
        self::assertSame('fail', $receipt['state']);
        self::assertStringContainsString('declared output was not produced', (string) $receipt['results'][0]['message']);
        $this->trackLogDirectory($receipt);
    }

    public function testTimeoutTerminatesTheProcessGroup(): void
    {
        $result = $this->resultPath('timeout');
        $suite = $this->suite('runner-timeout-fixture', ['php', '-r', 'sleep(10);'], [], 1);
        $catalog = $this->catalog($suite, 'runner-timeout-profile', $result);
        $started = hrtime(true);

        self::assertSame(1, (new Runner($this->root, $catalog, $result))->run([], 'runner-timeout-profile'));
        $receipt = $this->receipt($result);
        self::assertSame('fail', $receipt['state']);
        self::assertTrue($receipt['results'][0]['timed_out']);
        // One second of suite time plus two bounded process-group cleanup
        // passes and whole-workspace fingerprints can exceed four seconds on
        // slower filesystems while remaining deterministically bounded.
        self::assertLessThan(8.0, (hrtime(true) - $started) / 1_000_000_000);
        $this->trackLogDirectory($receipt);
    }

    public function testBackgroundDescendantCannotProduceAPass(): void
    {
        $result = $this->resultPath('descendant');
        $program = '$pid = pcntl_fork(); if ($pid === 0) { sleep(30); exit(0); } echo $pid, "\n";';
        $suite = $this->suite('runner-descendant-fixture', ['php', '-r', $program]);
        $catalog = $this->catalog($suite, 'runner-descendant-profile', $result);
        $started = hrtime(true);

        self::assertSame(1, (new Runner($this->root, $catalog, $result))->run([], 'runner-descendant-profile'));
        $receipt = $this->receipt($result);
        self::assertNotSame('pass', $receipt['state']);
        // Descendant cleanup uses the same two bounded group-termination
        // passes and workspace fingerprints as the timeout path above.
        self::assertLessThan(8.0, (hrtime(true) - $started) / 1_000_000_000);
        $this->trackLogDirectory($receipt);
    }

    public function testSignalTerminationIsRecorded(): void
    {
        $result = $this->resultPath('signal');
        $suite = $this->suite(
            'runner-signal-fixture',
            ['php', '-r', 'posix_kill(getmypid(), SIGTERM); usleep(100000);'],
        );
        $catalog = $this->catalog($suite, 'runner-signal-profile', $result);

        self::assertSame(1, (new Runner($this->root, $catalog, $result))->run([], 'runner-signal-profile'));
        $receipt = $this->receipt($result);
        self::assertSame(15, $receipt['results'][0]['signal']);
        self::assertSame('fail', $receipt['state']);
        $this->trackLogDirectory($receipt);
    }

    public function testSignalToRunnerCannotPublishPass(): void
    {
        $result = $this->resultPath('runner-signal');
        $suite = $this->suite('runner-process-signal-fixture', ['php', '-r', 'sleep(10);']);
        $catalog = $this->catalog($suite, 'runner-process-signal-profile', $result);
        $signaler = pcntl_fork();
        self::assertNotSame(-1, $signaler);
        if ($signaler === 0) {
            usleep(250000);
            posix_kill(posix_getppid(), SIGTERM);
            exit(0);
        }

        try {
            self::assertSame(143, (new Runner($this->root, $catalog, $result))->run([], 'runner-process-signal-profile'));
        } finally {
            pcntl_waitpid($signaler, $status);
        }
        $receipt = $this->receipt($result);
        self::assertSame('infra_error', $receipt['state']);
        self::assertSame('runner interrupted', $receipt['message']);
        self::assertSame(SIGTERM, $receipt['interrupt_signal']);
        $this->trackLogDirectory($receipt);
    }

    public function testUnavailableOwnerExportIsInfrastructureError(): void
    {
        $result = $this->resultPath('owner-export-unavailable');
        $suite = $this->suite('runner-owner-export-fixture', ['php', '-r', 'exit(69);']);
        $catalog = $this->catalog($suite, 'runner-owner-export-profile', $result);

        self::assertSame(1, (new Runner($this->root, $catalog, $result))->run([], 'runner-owner-export-profile'));
        $receipt = $this->receipt($result);
        self::assertSame('infra_error', $receipt['state']);
        self::assertStringContainsString('owner export is unavailable', (string) $receipt['results'][0]['message']);
        $this->trackLogDirectory($receipt);
    }

    public function testReadOnlyModeDetectsChangesToAlreadyPresentFiles(): void
    {
        $fixture = $this->root . '/.runner-integrity-fixture';
        file_put_contents($fixture, "before\n");
        try {
            $result = $this->resultPath('workspace-integrity');
            $suite = $this->suite(
                'runner-workspace-fixture',
                ['php', '-r', 'file_put_contents(".runner-integrity-fixture", "after\\n");'],
            );
            $catalog = $this->catalog($suite, 'runner-workspace-profile', $result);

            self::assertSame(1, (new Runner($this->root, $catalog, $result))->run([], 'runner-workspace-profile'));
            $receipt = $this->receipt($result);
            self::assertSame('infra_error', $receipt['state']);
            self::assertStringContainsString('outside permitted output roots', (string) $receipt['results'][0]['message']);
            $this->trackLogDirectory($receipt);
        } finally {
            if (is_file($fixture)) {
                unlink($fixture);
            }
        }
    }

    public function testReadOnlyModeRejectsUndeclaredDurableArtifacts(): void
    {
        $undeclared = 'artifacts/test-results/runner-tests/undeclared-' . bin2hex(random_bytes(4)) . '.txt';
        $this->cleanupPaths[] = $this->root . '/' . $undeclared;
        $result = $this->resultPath('undeclared-artifact');
        $program = 'mkdir("artifacts/test-results/runner-tests", 0700, true);'
            . 'file_put_contents("' . $undeclared . '", "undeclared\\n");';
        $suite = $this->suite('runner-undeclared-artifact-fixture', ['php', '-r', $program]);
        $catalog = $this->catalog($suite, 'runner-undeclared-artifact-profile', $result);

        self::assertSame(1, (new Runner($this->root, $catalog, $result))->run([], 'runner-undeclared-artifact-profile'));
        $receipt = $this->receipt($result);
        self::assertSame('infra_error', $receipt['state']);
        self::assertStringContainsString('outside permitted output roots', (string) $receipt['results'][0]['message']);
        $this->trackLogDirectory($receipt);
    }

    public function testReadOnlyModeRejectsPhpunitCacheMutation(): void
    {
        $cacheDirectory = '.phpunit.cache/runner-tests-' . bin2hex(random_bytes(4));
        $this->cleanupPaths[] = $this->root . '/' . $cacheDirectory;
        $result = $this->resultPath('phpunit-cache-mutation');
        $program = 'mkdir("' . $cacheDirectory . '", 0700, true);'
            . 'file_put_contents("' . $cacheDirectory . '/result", "undeclared\\n");';
        $suite = $this->suite('runner-phpunit-cache-fixture', ['php', '-r', $program]);
        $catalog = $this->catalog($suite, 'runner-phpunit-cache-profile', $result);

        self::assertSame(1, (new Runner($this->root, $catalog, $result))->run([], 'runner-phpunit-cache-profile'));
        $receipt = $this->receipt($result);
        self::assertSame('infra_error', $receipt['state']);
        self::assertStringContainsString('outside permitted output roots', (string) $receipt['results'][0]['message']);
        $this->trackLogDirectory($receipt);
    }

    public function testReadOnlyModeRejectsDirectoryOnlyMutation(): void
    {
        $directory = 'artifacts/test-results/runner-tests/undeclared-directory-' . bin2hex(random_bytes(4));
        $this->cleanupPaths[] = $this->root . '/' . $directory;
        $result = $this->resultPath('undeclared-directory');
        $suite = $this->suite(
            'runner-undeclared-directory-fixture',
            ['php', '-r', 'mkdir("' . $directory . '", 0700, true);'],
        );
        $catalog = $this->catalog($suite, 'runner-undeclared-directory-profile', $result);

        self::assertSame(1, (new Runner($this->root, $catalog, $result))->run([], 'runner-undeclared-directory-profile'));
        $receipt = $this->receipt($result);
        self::assertSame('infra_error', $receipt['state']);
        self::assertStringContainsString('outside permitted output roots', (string) $receipt['results'][0]['message']);
        $this->trackLogDirectory($receipt);
    }

    public function testExclusiveModeAllowsUnboundMutationUnderWorkspaceLock(): void
    {
        $directory = 'artifacts/test-results/runner-tests/exclusive-directory-' . bin2hex(random_bytes(4));
        $this->cleanupPaths[] = $this->root . '/' . $directory;
        $result = $this->resultPath('exclusive-directory');
        $suite = $this->suite(
            'runner-exclusive-directory-fixture',
            ['php', '-r', 'mkdir("' . $directory . '", 0700, true);'],
            [],
            10,
            'exclusive',
        );
        $catalog = $this->catalog($suite, 'runner-exclusive-directory-profile', $result);

        self::assertSame(0, (new Runner($this->root, $catalog, $result))->run([], 'runner-exclusive-directory-profile'));
        $receipt = $this->receipt($result);
        self::assertSame('pass', $receipt['state']);
        $this->trackLogDirectory($receipt);
    }

    public function testReadOnlyModeProtectsVendorAndDist(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $vendorFixture = $this->root . '/vendor/.runner-integrity-' . $suffix;
        $distFixture = $this->root . '/dist/.runner-integrity-' . $suffix;
        if (!is_dir($this->root . '/dist')) {
            mkdir($this->root . '/dist', 0700);
            $this->cleanupPaths[] = $this->root . '/dist';
        }
        $this->cleanupPaths[] = $vendorFixture;
        $this->cleanupPaths[] = $distFixture;
        $result = $this->resultPath('protected-roots');
        $program = 'file_put_contents("vendor/.runner-integrity-' . $suffix . '", "changed\\n");'
            . 'file_put_contents("dist/.runner-integrity-' . $suffix . '", "changed\\n");';
        $suite = $this->suite('runner-protected-roots-fixture', ['php', '-r', $program]);
        $catalog = $this->catalog($suite, 'runner-protected-roots-profile', $result);

        self::assertSame(1, (new Runner($this->root, $catalog, $result))->run([], 'runner-protected-roots-profile'));
        $receipt = $this->receipt($result);
        self::assertSame('infra_error', $receipt['state']);
        self::assertStringContainsString('outside permitted output roots', (string) $receipt['results'][0]['message']);
        $this->trackLogDirectory($receipt);
    }

    public function testExecutableTamperingFailsClosedEvenInExclusiveMode(): void
    {
        $relative = 'vendor/.runner-executable-' . bin2hex(random_bytes(4));
        $absolute = $this->root . '/' . $relative;
        $this->cleanupPaths[] = $absolute;
        file_put_contents(
            $absolute,
            "#!/usr/bin/env php\n<?php file_put_contents(__FILE__, \"#!/usr/bin/env php\\n<?php exit(0);\\n\");\n",
        );
        chmod($absolute, 0700);
        $result = $this->resultPath('executable-tampering');
        $suite = $this->suite('runner-executable-tampering-fixture', [$relative], [], 10, 'exclusive');
        $catalog = $this->catalog($suite, 'runner-executable-tampering-profile', $result);

        self::assertSame(1, (new Runner($this->root, $catalog, $result))->run([], 'runner-executable-tampering-profile'));
        $receipt = $this->receipt($result);
        self::assertSame('infra_error', $receipt['state']);
        self::assertStringContainsString('bound execution input changed', (string) $receipt['results'][1]['message']);
        $this->trackLogDirectory($receipt);
    }

    public function testIsolatedCopyRetainsOnlyDeclaredArtifact(): void
    {
        $result = $this->resultPath('isolated-copy');
        $artifact = 'artifacts/test-results/runner-tests/isolated-' . bin2hex(random_bytes(4)) . '.txt';
        $this->cleanupPaths[] = $this->root . '/' . $artifact;
        $program = 'mkdir("artifacts/test-results/runner-tests", 0700, true);'
            . 'file_put_contents("' . $artifact . '", "isolated\\n");';
        $suite = $this->suite(
            'runner-isolated-fixture',
            ['php', '-r', $program],
            [$artifact],
            10,
            'isolated_copy',
        );
        $catalog = $this->catalog($suite, 'runner-isolated-profile', $result);

        self::assertSame(0, (new Runner($this->root, $catalog, $result))->run([], 'runner-isolated-profile'));
        self::assertSame("isolated\n", file_get_contents($this->root . '/' . $artifact));
        $receipt = $this->receipt($result);
        self::assertSame('pass', $receipt['state']);
        $this->trackLogDirectory($receipt);
    }

    /**
     * @param list<string> $command
     * @param list<string> $expectedOutputs
     * @return Suite
     */
    private function suite(
        string $id,
        array $command,
        array $expectedOutputs = [],
        int $timeout = 10,
        string $workspaceMode = 'read_only',
    ): array {
        return [
            'id' => $id,
            'command' => $command,
            'layer' => 'platform',
            'owner' => 'thread-1',
            'timeout_seconds' => $timeout,
            'parallel_safe' => false,
            'resource_locks' => [],
            'temporary_directory' => 'unique',
            'workspace_mode' => $workspaceMode,
            'required_tools' => ['php'],
            'required_services' => [],
            'covered_paths' => [],
            'covered_contracts' => [],
            'evidence_inputs' => [],
            'evidence_role' => 'none',
            'required_review_gate' => null,
            'environment_class' => 'offline',
            'expected_outputs' => $expectedOutputs,
        ];
    }

    /**
     * @param Suite $suite
     * @return CatalogAggregate
     */
    private function catalog(array $suite, string $profileId, string $result): array
    {
        return [
            'format' => 'duo-test-catalog/v1',
            'suites' => [$suite],
            'inventory' => [],
            'profiles' => [[
                'id' => $profileId,
                'owner' => 'thread-1',
                'suite_ids' => [$suite['id']],
                'environment_class' => 'offline',
                'blocking' => true,
                'expected_outputs' => [$result],
                'evidence_staleness' => 'forbidden',
            ]],
            'source_fragments' => [],
        ];
    }

    private function resultPath(string $name): string
    {
        $directory = 'artifacts/test-results/runner-tests/' . $name . '-' . bin2hex(random_bytes(4));
        $this->cleanupPaths[] = $this->root . '/' . $directory;
        return $directory . '/result.json';
    }

    private function gitHead(): string
    {
        $process = proc_open(
            ['git', 'rev-parse', 'HEAD'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $this->root,
            ['PATH' => (string) getenv('PATH'), 'LC_ALL' => 'C'],
            ['bypass_shell' => true],
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('cannot resolve runner-test HEAD');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout)) {
            throw new \RuntimeException('cannot resolve runner-test HEAD');
        }
        $sha = trim($stdout);
        if (preg_match('/^[a-f0-9]{40}$/D', $sha) !== 1) {
            throw new \RuntimeException('runner-test HEAD is not a full SHA');
        }
        return $sha;
    }

    /** @return TestReceipt */
    private function receipt(string $relative): array
    {
        $bytes = file_get_contents($this->root . '/' . $relative);
        self::assertIsString($bytes);
        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)
            || !is_string($decoded['authority'] ?? null)
            || !is_array($decoded['catalog_scope'] ?? null)
            || !is_string($decoded['catalog_scope']['kind'] ?? null)
            || !(is_string($decoded['catalog_scope']['owner'] ?? null) || ($decoded['catalog_scope']['owner'] ?? null) === null)
            || !is_array($decoded['execution_plan'] ?? null) || !array_is_list($decoded['execution_plan'])
            || !is_string($decoded['dependency_lock_sha256'] ?? null)
            || !is_array($decoded['toolchain'] ?? null)
            || !is_array($decoded['platform_contract'] ?? null)
            || !is_string($decoded['platform_contract']['profile_id'] ?? null)
            || !is_string($decoded['platform_contract']['contract_sha256'] ?? null)
            || !is_string($decoded['state'] ?? null)
            || !(is_string($decoded['message'] ?? null) || ($decoded['message'] ?? null) === null)
            || !(is_int($decoded['interrupt_signal'] ?? null) || ($decoded['interrupt_signal'] ?? null) === null)
            || !is_array($decoded['results'] ?? null) || !array_is_list($decoded['results'])) {
            throw new \RuntimeException('runner test received a malformed receipt');
        }
        $toolchain = [];
        foreach ($decoded['toolchain'] as $key => $digest) {
            if (!is_string($key) || !is_string($digest)) {
                throw new \RuntimeException('runner test received malformed toolchain bindings');
            }
            $toolchain[$key] = $digest;
        }
        $results = [];
        foreach ($decoded['results'] as $result) {
            if (!is_array($result)
                || !is_string($result['id'] ?? null)
                || !(is_string($result['log_path'] ?? null) || ($result['log_path'] ?? null) === null)
                || !(is_string($result['message'] ?? null) || ($result['message'] ?? null) === null)
                || !is_bool($result['timed_out'] ?? null)
                || !(is_int($result['signal'] ?? null) || ($result['signal'] ?? null) === null)
                || !is_string($result['cleanup'] ?? null)) {
                throw new \RuntimeException('runner test received a malformed suite result');
            }
            $results[] = [
                'id' => $result['id'],
                'log_path' => $result['log_path'] ?? null,
                'message' => $result['message'] ?? null,
                'timed_out' => $result['timed_out'],
                'signal' => $result['signal'] ?? null,
                'cleanup' => $result['cleanup'],
            ];
        }
        return [
            'authority' => $decoded['authority'],
            'catalog_scope' => [
                'kind' => $decoded['catalog_scope']['kind'],
                'owner' => $decoded['catalog_scope']['owner'] ?? null,
            ],
            'execution_plan' => $decoded['execution_plan'],
            'dependency_lock_sha256' => $decoded['dependency_lock_sha256'],
            'toolchain' => $toolchain,
            'platform_contract' => [
                'profile_id' => $decoded['platform_contract']['profile_id'],
                'contract_sha256' => $decoded['platform_contract']['contract_sha256'],
            ],
            'state' => $decoded['state'],
            'message' => $decoded['message'] ?? null,
            'interrupt_signal' => $decoded['interrupt_signal'] ?? null,
            'results' => $results,
        ];
    }

    /** @param array<string,mixed> $receipt */
    private function trackLogDirectory(array $receipt): void
    {
        $results = $receipt['results'] ?? null;
        if (!is_array($results) || !is_array($results[0] ?? null) || !is_string($results[0]['log_path'] ?? null)) {
            return;
        }
        $this->cleanupPaths[] = dirname($this->root . '/' . $results[0]['log_path']);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (file_exists($path)) {
                unlink($path);
            }
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->removeTree($path . '/' . $entry);
            }
        }
        rmdir($path);
    }
}
