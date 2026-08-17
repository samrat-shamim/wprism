<?php
declare(strict_types=1);

namespace Duo\Tests\Tooling;

use PHPUnit\Framework\TestCase;

/**
 * Pin the closure-impact reporter against the cases that matter.
 *
 * The reporter never reimplements closure membership — it calls
 * \Duo\ScopedCertificationBundle::subjectInputPaths(). These tests therefore
 * assert the *intersection* behaviour and the reporter's contract with CI
 * (exit codes, JSON shape), not the closure rules themselves. They use
 * --paths=… so they are hermetic: no dependency on the working tree's state,
 * which other agents mutate concurrently.
 *
 * testDeletedPathInsideWalkedTreeExpiresEverySubject is the exception to
 * "intersection behaviour": subjectInputPaths() only enumerates files that
 * exist on disk, so a deleted path can never intersect, and the reporter has
 * a separate conservative counter-check (ci_deleted_closure_hazards()) for
 * exactly that case. That test pins the counter-check, not the intersection.
 *
 * The script is run out-of-process (proc_open) because it is a CLI entry point
 * with its own bootstrap: requiring it in-process would define DUO_* constants
 * and an is_multisite() stub into the test runner.
 */
final class CertImpactTest extends TestCase
{
    private const SUBJECT_COUNT = 9;

    private static function repoRoot(): string
    {
        $env = getenv('DUO_REPO_ROOT');
        return is_string($env) && $env !== '' ? $env : dirname(__DIR__, 2);
    }

    /** @return array{status:int,stdout:string,stderr:string} */
    private static function invoke(array $args): array
    {
        $repo = self::repoRoot();
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($repo . '/tools/cert-impact.php');
        foreach ($args as $arg) {
            $cmd .= ' ' . escapeshellarg((string) $arg);
        }
        $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $pipes = [];
        $process = proc_open($cmd, $descriptors, $pipes, $repo);
        self::assertIsResource($process, 'could not launch tools/cert-impact.php');
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $status = proc_close($process);
        return ['status' => $status, 'stdout' => $stdout, 'stderr' => $stderr];
    }

    /** @return array<string,mixed> */
    private static function json(array $args): array
    {
        $result = self::invoke($args);
        self::assertSame(0, $result['status'], 'reporter exited non-zero: ' . $result['stderr']);
        $decoded = json_decode($result['stdout'], true);
        self::assertIsArray($decoded, 'reporter did not emit JSON: ' . $result['stdout']);
        return $decoded;
    }

    /** @param array<string,mixed> $report */
    private static function expiredKeys(array $report): array
    {
        $expired = [];
        foreach ($report['subjects'] as $key => $row) {
            if ($row['expired'] === true) {
                $expired[] = $key;
            }
        }
        sort($expired, SORT_STRING);
        return $expired;
    }

    public function testAgentSourceExpiresEverySubject(): void
    {
        $report = self::json(['--paths=agent/src/Kernel/Canon.php', '--json']);
        self::assertCount(self::SUBJECT_COUNT, $report['subjects']);
        self::assertCount(self::SUBJECT_COUNT, self::expiredKeys($report));
        foreach ($report['subjects'] as $key => $row) {
            self::assertSame(['agent/src/Kernel/Canon.php'], $row['paths'], "subject $key");
        }
        self::assertSame([], $report['free_zone']);
        self::assertSame(1, $report['categories']['agent']);
        self::assertStringContainsString('9/9 subjects expire', $report['summary']);
    }

    public function testRegressionSuiteIsFreeZone(): void
    {
        $report = self::json(['--paths=sandbox/tests/regress_canon.php', '--json']);
        self::assertSame([], self::expiredKeys($report));
        self::assertSame(['sandbox/tests/regress_canon.php'], $report['free_zone']);
        self::assertStringContainsString('0/9 subjects expire', $report['summary']);
    }

    public function testMakefileExpiresEverySubjectAsMakefileCategory(): void
    {
        $report = self::json(['--paths=Makefile', '--json']);
        self::assertCount(self::SUBJECT_COUNT, self::expiredKeys($report));
        self::assertSame(1, $report['categories']['makefile']);
        self::assertSame(0, $report['categories']['agent']);
        self::assertSame([], $report['free_zone']);
    }

    /**
     * The intersection-only approach misses deletions: subjectInputPaths()
     * (the sole closure authority) only enumerates files that EXIST on disk,
     * so a deleted file can never appear in any subject's path list and a
     * naive `isset($changedIndex[$path])` test always misses it — reporting
     * 0/9 expire when ScopedCertificationBundle::assertCurrent() would
     * correctly expire all nine because the recorded closure is now longer
     * than the current one. `agent/src/Gone.php` never existed, so this is
     * hermetic; it stands in for any real deletion inside the walked tree.
     */
    public function testDeletedPathInsideWalkedTreeExpiresEverySubject(): void
    {
        $report = self::json(['--paths=agent/src/Gone.php', '--json']);
        self::assertCount(self::SUBJECT_COUNT, self::expiredKeys($report));
        foreach ($report['subjects'] as $key => $row) {
            self::assertSame(['agent/src/Gone.php'], $row['paths'], "subject $key");
        }
        self::assertSame([], $report['free_zone']);
        self::assertStringContainsString('9/9 subjects expire', $report['summary']);
    }

    /** A deleted path outside the walked trees and the fixed named-input list stays free-zone. */
    public function testDeletedPathOutsideClosureStaysFreeZone(): void
    {
        $report = self::json(['--paths=docs/README-gone-nonexistent.md', '--json']);
        self::assertSame([], self::expiredKeys($report));
        self::assertSame(['docs/README-gone-nonexistent.md'], $report['free_zone']);
    }

    public function testUnknownDocumentIsFreeZone(): void
    {
        $result = self::invoke(['--paths=docs/README-nonexistent.md']);
        self::assertSame(0, $result['status']);
        self::assertStringContainsString('docs/README-nonexistent.md', $result['stdout']);
        self::assertStringContainsString('free-zone changes (1 path(s)', $result['stdout']);
        self::assertStringContainsString('0/9 subjects expire', $result['stdout']);
    }

    public function testFailOnImpactIsTheOnlyNonZeroExit(): void
    {
        self::assertSame(1, self::invoke(['--paths=Makefile', '--json', '--fail-on-impact'])['status']);
        self::assertSame(0, self::invoke(['--paths=README.md', '--json', '--fail-on-impact'])['status']);
        self::assertSame(2, self::invoke(['--nonsense'])['status']);
    }
}
