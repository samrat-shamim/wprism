<?php

declare(strict_types=1);

namespace Duo\EngineeringPlatform\Tests;

use Duo\EngineeringPlatform\Aggregate;
use Duo\EngineeringPlatform\CatalogException;
use PHPUnit\Framework\TestCase;

final class AggregateTest extends TestCase
{
    private string $root;
    private string $directory;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 5);
        $this->directory = 'artifacts/test-results/aggregate-tests/' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->root . '/' . $this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root . '/' . $this->directory);
    }

    public function testOnlyCompletePassingDependencySetPasses(): void
    {
        $dependencies = $this->passingDependencies();
        $evaluation = (new Aggregate($this->root))->evaluate('pr', $dependencies);

        self::assertSame(0, $evaluation['exit']);
        self::assertSame('pass', $evaluation['receipt']['state'] ?? null);
        $rows = $evaluation['receipt']['dependencies'] ?? null;
        if (!is_array($rows)) {
            throw new \LogicException('passing aggregate test result lacks dependencies');
        }
        self::assertCount(8, $rows);
    }

    public function testMissingDependencySetIsRejectedBeforeEvaluation(): void
    {
        $dependencies = $this->passingDependencies();
        unset($dependencies['check']);

        $this->expectException(CatalogException::class);
        $this->expectExceptionMessage('dependency set differs');
        (new Aggregate($this->root))->evaluate('pr', $dependencies);
    }

    public function testAbsentResultCannotDisappearIntoAggregateSuccess(): void
    {
        $dependencies = $this->passingDependencies();
        unlink($this->root . '/' . $dependencies['offline-shards']);
        $evaluation = (new Aggregate($this->root))->evaluate('pr', $dependencies);

        self::assertSame(1, $evaluation['exit']);
        self::assertSame('fail', $evaluation['receipt']['state'] ?? null);
        $rows = $evaluation['receipt']['dependencies'] ?? null;
        if (!is_array($rows)) {
            throw new \LogicException('aggregate test result lacks dependencies');
        }
        $offline = array_values(array_filter(
            $rows,
            static fn(mixed $row): bool => is_array($row) && ($row['id'] ?? null) === 'offline-shards',
        ));
        self::assertSame('infra_error', $offline[0]['state'] ?? null);
    }

    public function testEvidenceChildReceiptsBindTheirEvidenceCommitField(): void
    {
        $head = $this->headSha();
        $formats = [
            'assembly-reproducibility' => 'duo-assembly-reproducibility/v1',
            'final-integration-close-gate' => 'duo-final-integration-close-gate/v1',
            'release-family' => 'duo-release-family-check/v1',
        ];
        $dependencies = [];
        foreach (['assembly-reproducibility', 'final-integration-close-gate', 'generated-agreement', 'release-family', 'release-gate'] as $id) {
            $relative = $this->directory . '/' . $id . '.json';
            $receipt = [
                'format' => $formats[$id] ?? 'duo-command-result/v1',
                'candidate_sha' => isset($formats[$id]) ? str_repeat('a', 40) : $head,
                'state' => 'pass',
            ];
            if (isset($formats[$id])) {
                $receipt['evidence_child_sha'] = $head;
            }
            file_put_contents($this->root . '/' . $relative, json_encode($receipt, JSON_THROW_ON_ERROR) . "\n");
            $dependencies[$id] = $relative;
        }

        $evaluation = (new Aggregate($this->root))->evaluate('evidence-child', $dependencies);

        self::assertSame(0, $evaluation['exit']);
        self::assertSame('pass', $evaluation['receipt']['state'] ?? null);
    }

    /** @return array<string,string> */
    private function passingDependencies(): array
    {
        $ids = [
            'build-dist', 'changed-impact', 'check', 'evidence-staleness',
            'loader-runtime', 'offline-shards', 'unit-php-8.2', 'unit-php-8.3',
        ];
        $candidate = $this->headSha();
        $dependencies = [];
        foreach ($ids as $id) {
            $relative = $this->directory . '/' . $id . '.json';
            file_put_contents($this->root . '/' . $relative, json_encode([
                'format' => 'duo-test-run-receipt/v1',
                'candidate_sha' => $candidate,
                'state' => 'pass',
            ], JSON_THROW_ON_ERROR) . "\n");
            $dependencies[$id] = $relative;
        }
        return $dependencies;
    }

    private function headSha(): string
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
            throw new \RuntimeException('cannot resolve aggregate-test HEAD');
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        if (proc_close($process) !== 0 || !is_string($stdout)) {
            throw new \RuntimeException('cannot resolve aggregate-test HEAD');
        }
        return trim($stdout);
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path) || is_link($path)) {
            if (file_exists($path) || is_link($path)) {
                unlink($path);
            }
            return;
        }
        $entries = scandir($path);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . '/' . $entry);
                }
            }
        }
        rmdir($path);
    }
}
