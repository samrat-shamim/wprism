<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\CatalogException;
use Duo\EngineeringPlatform\Runner;
use PHPUnit\Framework\TestCase;

/**
 * @phpstan-import-type CatalogAggregate from \Duo\EngineeringPlatform\Catalog
 * @phpstan-import-type Suite from \Duo\EngineeringPlatform\Catalog
 * @phpstan-type TestReceipt array{
 *     authority:string,
 *     catalog_scope:array{kind:string,owner:?string},
 *     execution_plan:list<mixed>,
 *     dependency_lock_sha256:string,
 *     state:string,
 *     results:list<array{log_path:?string,message:?string,timed_out:bool,signal:?int,cleanup:string}>
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

        self::assertSame(0, $exit);
        $receipt = $this->receipt($result);
        self::assertSame('non_authorizing_partial', $receipt['authority']);
        self::assertSame(['kind' => 'partial_owner', 'owner' => 'thread-1'], $receipt['catalog_scope']);
        self::assertNotEmpty($receipt['execution_plan']);
        self::assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/D', (string) $receipt['dependency_lock_sha256']);
        $first = $receipt['results'][0];
        $logPath = $this->root . '/' . $first['log_path'];
        $this->cleanupPaths[] = dirname($logPath);
        $log = file_get_contents($logPath);
        self::assertIsString($log);
        self::assertStringNotContainsString($this->root, $log);
        self::assertStringNotContainsString('review-sentinel-must-not-leak', $log);
        self::assertSame(0600, fileperms($logPath) & 0777);
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
        self::assertLessThan(4.0, (hrtime(true) - $started) / 1_000_000_000);
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
        self::assertLessThan(4.0, (hrtime(true) - $started) / 1_000_000_000);
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
            || !is_string($decoded['state'] ?? null)
            || !is_array($decoded['results'] ?? null) || !array_is_list($decoded['results'])) {
            throw new \RuntimeException('runner test received a malformed receipt');
        }
        $results = [];
        foreach ($decoded['results'] as $result) {
            if (!is_array($result)
                || !(is_string($result['log_path'] ?? null) || ($result['log_path'] ?? null) === null)
                || !(is_string($result['message'] ?? null) || ($result['message'] ?? null) === null)
                || !is_bool($result['timed_out'] ?? null)
                || !(is_int($result['signal'] ?? null) || ($result['signal'] ?? null) === null)
                || !is_string($result['cleanup'] ?? null)) {
                throw new \RuntimeException('runner test received a malformed suite result');
            }
            $results[] = [
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
            'state' => $decoded['state'],
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
