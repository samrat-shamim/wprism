<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

final class EnvironmentLifecycleJournalFsyncProbe {
    /** @var list<string> */
    private static array $paths = [];
    private static ?string $failurePath = null;

    public static function begin(?string $failurePath = null): void {
        self::$paths = [];
        self::$failurePath = $failurePath;
    }

    public static function reset(): void {
        self::$paths = [];
        self::$failurePath = null;
    }

    /** @return list<string> */
    public static function paths(): array {
        return self::$paths;
    }

    /** @param resource $handle */
    public static function fsync($handle): bool {
        $metadata = stream_get_meta_data($handle);
        $path = $metadata['uri'] ?? null;
        if (is_string($path)) {
            self::$paths[] = $path;
        }
        if ($path === self::$failurePath) {
            self::$failurePath = null;
            return false;
        }
        return \fsync($handle);
    }
}

/** @param resource $handle */
function fsync($handle): bool {
    return EnvironmentLifecycleJournalFsyncProbe::fsync($handle);
}

namespace Duo\Tests\Cloud;

use Duo\Orchestrator\EnvironmentLifecycleJournal;
use Duo\Orchestrator\EnvironmentLifecycleJournalFsyncProbe;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/cli/src/Environment/EnvironmentLifecycle.php';

final class EnvironmentLifecycleJournalTest extends TestCase {
    private string $root;

    protected function setUp(): void {
        EnvironmentLifecycleJournalFsyncProbe::reset();
        $this->root = sys_get_temp_dir() . '/duo-environment-journal-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
    }

    protected function tearDown(): void {
        EnvironmentLifecycleJournalFsyncProbe::reset();
        self::removeTree($this->root);
    }

    public function testOneThousandReapedRunsRetainOnlyLatestExactTargetHistory(): void {
        $journal = new EnvironmentLifecycleJournal($this->root . '/journal');
        $lastOperation = '';
        $lastReceipt = [];
        for ($cycle = 1; $cycle <= 1000; $cycle++) {
            $lastOperation = self::operation($cycle);
            $journal->start($lastOperation, self::runRecord($lastOperation, $cycle));
            $journal->append($lastOperation, 'complete', [
                'receipt_sha256' => hash('sha256', 'complete-' . $cycle),
            ]);
            $lastReceipt = ['receipt_sha256' => hash('sha256', 'reaped-' . $cycle)];
            $journal->append($lastOperation, 'reaped', $lastReceipt);

            $latest = $journal->latestForTarget('preview');
            self::assertSame($lastOperation, $latest['operation_id'] ?? null);
            self::assertSame($lastReceipt, self::eventData($latest['events'] ?? [], 'reaped'));
            self::assertCount(1, self::directories($this->root . '/journal/runs'));
            self::assertSame([], self::directories($this->root . '/journal/tombstones'));
        }

        $reopened = new EnvironmentLifecycleJournal($this->root . '/journal');
        $latest = $reopened->latestForTarget('preview');
        self::assertSame($lastOperation, $latest['operation_id'] ?? null);
        self::assertSame($lastReceipt, self::eventData($latest['events'] ?? [], 'reaped'));
        self::assertCount(1, self::directories($this->root . '/journal/runs'));
    }

    public function testReapRecoversADeletionTombstoneBeforePublishingTerminalIndex(): void {
        $base = $this->root . '/journal';
        $journal = new EnvironmentLifecycleJournal($base);
        $first = self::operation(1100);
        $journal->start($first, self::runRecord($first, 1));
        $journal->append($first, 'reaped', ['receipt_sha256' => hash('sha256', 'first')]);

        $second = self::operation(1101);
        $journal->start($second, self::runRecord($second, 2));
        $tombstone = $base . '/tombstones/' . hash('sha256', 'preview') . '.' . $first;
        self::assertTrue(rename($journal->runDir($first), $tombstone));

        $expected = ['receipt_sha256' => hash('sha256', 'second')];
        $journal->append($second, 'reaped', $expected);
        $beforeReplay = count($journal->events($second));
        $journal->append($second, 'reaped', $expected);
        self::assertCount($beforeReplay, $journal->events($second));
        self::assertDirectoryDoesNotExist($tombstone);
        self::assertDirectoryDoesNotExist($journal->runDir($first));
        self::assertDirectoryExists($journal->runDir($second));
        self::assertSame($expected, self::eventData(
            $journal->latestForTarget('preview')['events'] ?? [],
            'reaped'
        ));
    }

    public function testRepeatedFailureRecordingIsIdempotentAndCapped(): void {
        $journal = new EnvironmentLifecycleJournal($this->root . '/journal');
        $operation = self::operation(1200);
        $journal->start($operation, self::runRecord($operation, 1));
        $same = ['reason' => 'provider remains unavailable'];
        $firstPath = $journal->append($operation, 'stopped', $same);
        for ($retry = 0; $retry < 1000; $retry++) {
            self::assertSame($firstPath, $journal->append($operation, 'stopped', $same));
        }
        self::assertCount(2, $journal->events($operation));

        for ($failure = 0; $failure < 32; $failure++) {
            $journal->append($operation, 'stopped', ['reason' => 'provider failure ' . $failure]);
        }
        $events = $journal->events($operation);
        self::assertCount(17, $events);
        self::assertCount(16, array_filter(
            $events,
            static fn (array $event): bool => ($event['event'] ?? null) === 'stopped'
        ));
    }

    public function testDurableInstallingIndexTemporaryResumesTheExactRun(): void {
        $base = $this->root . '/journal';
        $journal = new EnvironmentLifecycleJournal($base);
        $operation = self::operation(1300);
        $journal->start($operation, self::runRecord($operation, 1));
        $index = $base . '/targets/' . hash('sha256', 'preview') . '.json';
        $document = json_decode((string) file_get_contents($index), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($document);
        $document['phase'] = 'installing';
        $temporary = $index . '.next';
        self::assertGreaterThan(0, file_put_contents(
            $temporary,
            \Duo\Orchestrator\EnvironmentLifecycleCanon::encode($document) . "\n"
        ));
        self::assertTrue(chmod($temporary, 0600));
        self::assertTrue(unlink($index));

        $reopened = new EnvironmentLifecycleJournal($base);
        $latest = $reopened->latestForTarget('preview');
        self::assertSame($operation, $latest['operation_id'] ?? null);
        self::assertCount(1, $latest['events'] ?? []);
        self::assertSame('prepared', $latest['events'][0]['event'] ?? null);
        self::assertFileDoesNotExist($temporary);
        self::assertFileExists($index);
    }

    public function testRunParentSyncFailureCannotPublishAnActiveTargetIndex(): void {
        if (!function_exists('fsync')) {
            self::markTestSkipped('fsync is required for the run-parent durability regression');
        }
        $base = $this->root . '/journal';
        $journal = new EnvironmentLifecycleJournal($base);
        $operation = self::operation(1350);
        $runRoot = $base . '/runs';
        $indexPath = $base . '/targets/' . hash('sha256', 'preview') . '.json';
        EnvironmentLifecycleJournalFsyncProbe::begin($runRoot);

        try {
            $journal->start($operation, self::runRecord($operation, 1));
            self::fail('run-parent fsync failure published an active target index');
        } catch (\RuntimeException $error) {
            self::assertSame(
                "environment journal directory '$runRoot' could not be synchronized",
                $error->getMessage()
            );
        }

        $interrupted = json_decode((string) file_get_contents($indexPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('installing', $interrupted['phase'] ?? null);
        self::assertDirectoryExists($journal->runDir($operation));

        EnvironmentLifecycleJournalFsyncProbe::begin();
        $latest = $journal->latestForTarget('preview');
        $paths = EnvironmentLifecycleJournalFsyncProbe::paths();
        $runBarrier = array_search($runRoot, $paths, true);
        $activeIndexFlush = array_search($indexPath . '.next', $paths, true);
        self::assertIsInt($runBarrier, 'recovery did not retry the run-parent durability barrier');
        self::assertIsInt($activeIndexFlush, 'recovery did not durably flush the active target index');
        self::assertTrue(
            $runBarrier < $activeIndexFlush,
            'the active target index was flushed before its run directory entry'
        );
        self::assertSame($operation, $latest['operation_id'] ?? null);
        $active = json_decode((string) file_get_contents($indexPath), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('active', $active['phase'] ?? null);
    }

    public function testLatestTargetReadWaitsForTheWriterLockBeforeReconcilingItsTemporary(): void {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('pcntl is required for the target-index concurrency regression');
        }
        $sockets = @stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if (!is_array($sockets)) {
            self::markTestSkipped('Unix socket pairs are required for the target-index concurrency regression');
        }

        $base = $this->root . '/journal';
        $journal = new EnvironmentLifecycleJournal($base);
        $operation = self::operation(1400);
        $journal->start($operation, self::runRecord($operation, 1));
        $journal->append($operation, 'reaped', ['receipt_sha256' => hash('sha256', 'reaped')]);
        $index = $base . '/targets/' . hash('sha256', 'preview') . '.json';
        $temporary = $index . '.next';

        [$parentSocket, $childSocket] = $sockets;
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            fclose($parentSocket);
            if (fread($childSocket, 1) !== 'G' || fwrite($childSocket, 'A') !== 1) {
                exit(70);
            }
            fflush($childSocket);
            try {
                $latest = $journal->latestForTarget('preview');
                $message = 'OK:' . ($latest['operation_id'] ?? 'missing') . "\n";
            } catch (\Throwable $error) {
                $message = 'ERROR:' . $error->getMessage() . "\n";
            }
            $written = fwrite($childSocket, $message);
            fclose($childSocket);
            exit($written === strlen($message) ? 0 : 71);
        }
        fclose($childSocket);

        $acknowledgement = '';
        $earlyResponse = '';
        $temporarySurvived = false;
        $ready = -1;
        try {
            $journal->synchronizedTarget('preview', function () use (
                $journal,
                $operation,
                $parentSocket,
                $temporary,
                &$acknowledgement,
                &$earlyResponse,
                &$ready,
                &$temporarySurvived
            ): void {
                file_put_contents($temporary, '{"incomplete":true');
                chmod($temporary, 0600);
                fwrite($parentSocket, 'G');
                fflush($parentSocket);
                $acknowledgement = (string) fread($parentSocket, 1);
                stream_set_blocking($parentSocket, false);
                $read = [$parentSocket];
                $write = null;
                $except = null;
                $ready = stream_select($read, $write, $except, 0, 250000);
                if ($ready === 1) {
                    $earlyResponse = (string) stream_get_contents($parentSocket);
                }
                $temporarySurvived = file_exists($temporary) || is_link($temporary);
                if ($temporarySurvived) {
                    unlink($temporary);
                }

                // start() and latestForTarget() both protect themselves, while
                // product orchestration already holds this same target lock.
                $latest = $journal->latestForTarget('preview');
                self::assertSame($operation, $latest['operation_id'] ?? null);
            });
            stream_set_blocking($parentSocket, true);
            stream_set_timeout($parentSocket, 5);
            $response = $earlyResponse . (string) stream_get_contents($parentSocket);
        } finally {
            if (file_exists($temporary) || is_link($temporary)) {
                unlink($temporary);
            }
            fclose($parentSocket);
            pcntl_waitpid($pid, $status);
        }

        self::assertSame('A', $acknowledgement);
        self::assertSame(0, $ready, 'latestForTarget escaped the held target lock');
        self::assertSame('', $earlyResponse);
        self::assertTrue($temporarySurvived, 'latestForTarget removed an in-flight writer temporary');
        self::assertSame("OK:$operation\n", $response);
        self::assertTrue(pcntl_wifexited($status));
        self::assertSame(0, pcntl_wexitstatus($status));
    }

    public function testSymlinkedTombstoneRootRefusesBeforeDeletingExternalState(): void {
        $base = $this->root . '/linked-journal';
        self::assertTrue(mkdir($base . '/runs', 0700, true));
        self::assertTrue(mkdir($base . '/targets', 0700));
        $outside = $this->root . '/outside-tombstones';
        $entry = str_repeat('a', 64) . '.' . self::operation(1500);
        self::assertTrue(mkdir($outside . '/' . $entry, 0700, true));
        $sentinel = $outside . '/' . $entry . '/sentinel';
        self::assertSame(9, file_put_contents($sentinel, 'retain-me'));
        self::assertTrue(chmod($sentinel, 0600));
        self::assertTrue(symlink($outside, $base . '/tombstones'));

        try {
            new EnvironmentLifecycleJournal($base);
            self::fail('symlinked environment tombstone root was accepted');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('not private and controller-owned', $error->getMessage());
        }
        self::assertTrue(is_link($base . '/tombstones'));
        self::assertSame('retain-me', file_get_contents($sentinel));
    }

    /** @return array<string,mixed> */
    private static function runRecord(string $operationId, int $cycle): array {
        return [
            'branch_commit' => hash('sha1', 'branch-' . $cycle),
            'branch_ref' => 'feature/preview-' . $cycle,
            'created_at' => '2026-08-20T00:00:00Z',
            'format' => EnvironmentLifecycleJournal::RUN_FORMAT,
            'intent_sha256' => hash('sha256', 'intent-' . $cycle),
            'mode' => 'create',
            'operation_id' => $operationId,
            'source_environment' => 'production',
            'target_environment' => 'preview',
            'ttl_seconds' => 3600,
        ];
    }

    private static function operation(int $number): string {
        return '20260820-000000-' . str_pad(dechex($number), 24, '0', STR_PAD_LEFT);
    }

    /** @param list<array<string,mixed>> $events @return ?array<string,mixed> */
    private static function eventData(array $events, string $name): ?array {
        for ($index = count($events) - 1; $index >= 0; $index--) {
            if (($events[$index]['event'] ?? null) === $name) {
                return is_array($events[$index]['data'] ?? null) ? $events[$index]['data'] : null;
            }
        }
        return null;
    }

    /** @return list<string> */
    private static function directories(string $path): array {
        return array_values(array_filter(
            glob($path . '/*') ?: [],
            static fn (string $candidate): bool => is_dir($candidate)
        ));
    }

    private static function removeTree(string $path): void {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
