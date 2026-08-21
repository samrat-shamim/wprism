<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Orchestrator\CloudPreviewTransport;
use Duo\Orchestrator\EnvironmentLifecycleCanon;
use Duo\Orchestrator\PortablePreviewMaterializer;
use Duo\Orchestrator\PreviewCommand;
use Duo\Orchestrator\PreviewRunJournal;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cli/src/Environment/PreviewRunJournal.php';
require_once DUO_REPO_ROOT . '/cli/src/Command/PreviewCommand.php';

#[CoversNothing]
final class PreviewRunJournalTest extends TestCase {
    private string $scratch;
    private string|false $previousTestMode;

    protected function setUp(): void {
        $this->previousTestMode = getenv('DUO_TEST_MODE');
        putenv('DUO_TEST_MODE=1');
        $this->scratch = sys_get_temp_dir() . '/duo-preview-journal-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
    }

    protected function tearDown(): void {
        $this->previousTestMode === false
            ? putenv('DUO_TEST_MODE')
            : putenv('DUO_TEST_MODE=' . $this->previousTestMode);
        if (!isset($this->scratch) || (!file_exists($this->scratch) && !is_link($this->scratch))) {
            return;
        }
        $this->remove($this->scratch);
    }

    public function testLoopbackHttpControlEndpointsAreAvailableOnlyInTestMode(): void {
        putenv('DUO_TEST_MODE');
        try {
            $this->unreachablePreviewTransport('production-http');
            self::fail('production cloud-preview transport accepted a loopback HTTP endpoint');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('must use HTTPS', $error->getMessage());
        } finally {
            putenv('DUO_TEST_MODE=1');
        }

        try {
            $this->unreachablePreviewTransport('hostname-http', 'localhost');
            self::fail('test cloud-preview transport accepted a hostname instead of a loopback interface');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('must use HTTPS', $error->getMessage());
        }
    }

    public function testUnansweredPollIntentResumesWithTheSameOperationAndSequence(): void {
        $base = $this->scratch . '/journal';
        $journal = new PreviewRunJournal($base);
        $first = $journal->synchronizedTarget(
            'preview',
            fn (): array => $journal->resumeOrStart('preview', $this->intent())
        );
        $journal->append($first['operation_id'], 'origin-poll-intent', ['poll_sequence' => 0]);

        $reopened = new PreviewRunJournal($base);
        $resumed = $reopened->synchronizedTarget(
            'preview',
            fn (): array => $reopened->resumeOrStart('preview', $this->intent())
        );

        self::assertTrue($resumed['resumed']);
        self::assertSame($first['operation_id'], $resumed['operation_id']);
        $poll = PreviewRunJournal::eventData($resumed['events'], 'origin-poll-intent');
        self::assertSame(['poll_sequence' => 0], $poll);
        self::assertSame(0700, fileperms($base) & 0777);
        self::assertSame(
            0600,
            fileperms($base . '/targets/' . hash('sha256', 'preview') . '.lock') & 0777
        );
    }

    public function testSleepCycleLimitStillPermitsWakeAndReapButRefusesFurtherSleepBeforeContact(): void {
        $completed = $this->completedPreview('bounded-journal');
        $journal = $completed['journal'];
        $operationId = $completed['operation_id'];
        $identity = $completed['identity'];

        $limit = (new \ReflectionClass(PreviewCommand::class))
            ->getReflectionConstant('MAX_SLEEP_CYCLES')?->getValue();
        if (!is_int($limit)) {
            self::fail('preview sleep cycle limit is not an integer');
        }
        self::assertSame(24, $limit);
        $lastSleepOperation = '';
        for ($cycle = 1; $cycle <= $limit; $cycle++) {
            $lastSleepOperation = '20260820-000000-'
                . str_pad(dechex($cycle), 24, '0', STR_PAD_LEFT);
            $journal->append($operationId, 'sleep-cycle-intent', [
                'cycle' => $cycle,
                'identity' => $identity,
                'operation_id' => $lastSleepOperation,
            ]);
        }
        $journal->append($operationId, 'slept', [
            'operation_id' => $lastSleepOperation,
            'result' => ['sleep_state' => 'asleep'],
        ]);

        $transport = $this->unreachablePreviewTransport('bounded');

        $wake = new \ReflectionMethod(PreviewCommand::class, 'sleepTransition');
        try {
            $wake->invoke(null, $transport, $journal, 'preview', 'wake');
            self::fail('wake at the closed cycle limit did not reach its provider');
        } catch (\ReflectionException $error) {
            throw $error;
        } catch (\Throwable $error) {
            self::assertSame(
                'cloud preview lifecycle request failed; remote output is redacted',
                $error->getMessage(),
                'wake must remain provider-retryable at the cycle limit'
            );
        }

        $journal->append($operationId, 'sleep-fence-released', [
            'operation_id' => $lastSleepOperation,
            'result' => ['state' => 'released'],
        ]);
        $latest = $journal->latestForTarget('preview');
        if (!is_array($latest)) {
            self::fail('bounded preview run disappeared before reap reconciliation');
        }
        $reconcile = new \ReflectionMethod(PreviewCommand::class, 'reconcileSleepForReap');
        try {
            $reconcile->invoke(null, $transport, $journal, $latest, 'preview');
            self::fail('reap at the closed cycle limit did not reach its provider');
        } catch (\ReflectionException $error) {
            throw $error;
        } catch (\Throwable $error) {
            self::assertSame(
                'cloud preview lifecycle request failed; remote output is redacted',
                $error->getMessage(),
                'reap must remain provider-retryable at the cycle limit'
            );
        }

        $eventFiles = glob($journal->runDir($operationId) . '/events/*.json') ?: [];

        $method = new \ReflectionMethod(PreviewCommand::class, 'sleepTransition');
        try {
            $method->invoke(null, $transport, $journal, 'preview', 'sleep');
            self::fail('a sleep cycle beyond the closed per-generation limit was accepted');
        } catch (\ReflectionException $error) {
            throw $error;
        } catch (\Throwable $error) {
            self::assertSame(
                'preview sleep cycle history is exhausted; reap the generation',
                $error->getMessage()
            );
        }
        self::assertSame(
            $eventFiles,
            glob($journal->runDir($operationId) . '/events/*.json') ?: [],
            'cycle exhaustion must refuse before either provider contact or another immutable event'
        );
    }

    public function testStartedReapBlocksCachedWakeAndAReplacementSleepCycle(): void {
        $completed = $this->completedPreview('reaping-journal');
        $journal = $completed['journal'];
        $operationId = $completed['operation_id'];
        $identity = $completed['identity'];
        $sleepOperation = '20260820-000000-' . str_repeat('e', 24);
        $journal->append($operationId, 'sleep-cycle-intent', [
            'cycle' => 1,
            'identity' => $identity,
            'operation_id' => $sleepOperation,
        ]);
        $woken = $identity + [
            '_provider' => ['id' => 'bounded-provider', 'protocol' => 1],
            '_response_sha256' => hash('sha256', 'woken provider response'),
            'sleep_state' => 'awake',
        ];
        $journal->append($operationId, 'slept', [
            'operation_id' => $sleepOperation,
            'result' => $identity + ['sleep_state' => 'asleep'],
        ]);
        $journal->append($operationId, 'woken', [
            'operation_id' => $sleepOperation,
            'result' => $woken,
        ]);
        $journal->append($operationId, 'sleep-fence-released', [
            'operation_id' => $sleepOperation,
            'result' => ['state' => 'released'],
        ]);
        $journal->append($operationId, 'reap-intent', [
            'target_environment' => 'preview',
        ]);
        $before = glob($journal->runDir($operationId) . '/events/*.json') ?: [];
        $transport = $this->unreachablePreviewTransport('reaping');
        $transition = new \ReflectionMethod(PreviewCommand::class, 'sleepTransition');

        foreach (['sleep', 'wake'] as $action) {
            try {
                $transition->invoke(null, $transport, $journal, 'preview', $action);
                self::fail("$action was accepted after durable preview reap intent");
            } catch (\ReflectionException $error) {
                throw $error;
            } catch (\Throwable $error) {
                self::assertSame(
                    'preview reap is incomplete; retry `duo preview reap` exactly',
                    $error->getMessage()
                );
            }
        }
        self::assertSame(
            $before,
            glob($journal->runDir($operationId) . '/events/*.json') ?: [],
            'reap gating must precede stale wake replay or a replacement sleep intent'
        );
    }

    public function testUnpublishedTemporaryRunIsIgnoredAfterInterruption(): void {
        $base = $this->scratch . '/journal';
        $journal = new PreviewRunJournal($base);
        $temporary = $base . '/runs/interrupted.tmp';
        self::assertSame(7, file_put_contents($temporary, 'partial'));
        self::assertTrue(chmod($temporary, 0600));

        $run = $journal->resumeOrStart('preview', $this->intent());

        self::assertFalse($run['resumed']);
        self::assertFileExists($journal->runDir($run['operation_id']) . '/run.json');
    }

    public function testInterruptedEventWriteIsReconciledBeforeTheRealAppend(): void {
        $base = $this->scratch . '/journal';
        $journal = new PreviewRunJournal($base);
        $run = $journal->resumeOrStart('preview', $this->intent());
        $events = $journal->runDir($run['operation_id']) . '/events';
        $temporary = $events . '/0002-origin-poll-intent.json.tmp';
        self::assertSame(7, file_put_contents($temporary, 'partial'));
        self::assertTrue(chmod($temporary, 0600));

        $journal->append($run['operation_id'], 'origin-poll-intent', ['poll_sequence' => 0]);

        self::assertFileExists($events . '/0002-origin-poll-intent.json');
        self::assertFileDoesNotExist($temporary);
        self::assertSame([], glob($events . '/*.tmp') ?: []);
    }

    public function testPublishedRunLinkResidueIsReconciledBeforeResume(): void {
        $base = $this->scratch . '/journal';
        $journal = new PreviewRunJournal($base);
        $run = $journal->resumeOrStart('preview', $this->intent());
        $runPath = $journal->runDir($run['operation_id']) . '/run.json';
        $temporary = $runPath . '.tmp';
        self::assertTrue(link($runPath, $temporary));
        self::assertSame(2, lstat($runPath)['nlink'] ?? 0);

        $resumed = $journal->resumeOrStart('preview', $this->intent());

        self::assertTrue($resumed['resumed']);
        self::assertSame($run['operation_id'], $resumed['operation_id']);
        self::assertFileDoesNotExist($temporary);
        self::assertSame(1, lstat($runPath)['nlink'] ?? 0);
    }

    public function testSymlinkedEventCrashResidueIsRefusedWithoutUnlinkingItsTarget(): void {
        $base = $this->scratch . '/journal';
        $journal = new PreviewRunJournal($base);
        $run = $journal->resumeOrStart('preview', $this->intent());
        $temporary = $journal->runDir($run['operation_id'])
            . '/events/0002-origin-poll-intent.json.tmp';
        $outside = $this->scratch . '/outside-event';
        self::assertSame(8, file_put_contents($outside, 'retained'));
        self::assertTrue(chmod($outside, 0600));
        self::assertTrue(symlink($outside, $temporary));

        try {
            $journal->append($run['operation_id'], 'origin-poll-intent', ['poll_sequence' => 0]);
            self::fail('symlinked preview crash residue was accepted');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('temporary preview journal is unsafe', $error->getMessage());
            self::assertSame('retained', file_get_contents($outside));
            self::assertTrue(is_link($temporary));
        } finally {
            if (is_link($temporary)) {
                self::assertTrue(unlink($temporary));
            }
            if (is_file($outside)) {
                self::assertTrue(unlink($outside));
            }
        }
    }

    public function testSymlinkedJournalRootIsRefused(): void {
        $real = $this->scratch . '/real';
        self::assertTrue(mkdir($real, 0700));
        self::assertTrue(chmod($real, 0700));
        $link = $this->scratch . '/journal';
        self::assertTrue(symlink($real, $link));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not private and controller-owned');
        new PreviewRunJournal($link);
    }

    public function testWorldWritableJournalRootIsRefusedOnReopen(): void {
        $base = $this->scratch . '/journal';
        new PreviewRunJournal($base);
        self::assertTrue(chmod($base, 0777));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not private and controller-owned');
        new PreviewRunJournal($base);
    }

    public function testSymlinkedImmutableRunFileIsRefused(): void {
        $base = $this->scratch . '/journal';
        $journal = new PreviewRunJournal($base);
        $run = $journal->resumeOrStart('preview', $this->intent());
        $runPath = $journal->runDir($run['operation_id']) . '/run.json';
        $outside = $this->scratch . '/outside.json';
        self::assertTrue(rename($runPath, $outside));
        self::assertTrue(symlink($outside, $runPath));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a stable private regular file');
        $journal->latestForTarget('preview');
    }

    public function testWorldWritableImmutableRunFileIsRefused(): void {
        $base = $this->scratch . '/journal';
        $journal = new PreviewRunJournal($base);
        $run = $journal->resumeOrStart('preview', $this->intent());
        $runPath = $journal->runDir($run['operation_id']) . '/run.json';
        self::assertTrue(chmod($runPath, 0666));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a stable private regular file');
        $journal->latestForTarget('preview');
    }

    public function testSpecialImmutableRunFileIsRefusedBeforeOpeningIt(): void {
        if (!function_exists('posix_mkfifo')) {
            self::markTestSkipped('POSIX FIFO support is required for this filesystem regression');
        }
        $base = $this->scratch . '/journal';
        $journal = new PreviewRunJournal($base);
        $run = $journal->resumeOrStart('preview', $this->intent());
        $runPath = $journal->runDir($run['operation_id']) . '/run.json';
        self::assertTrue(unlink($runPath));
        self::assertTrue(posix_mkfifo($runPath, 0600));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a stable private regular file');
        $journal->latestForTarget('preview');
    }

    public function testHardLinkedImmutableRunFileIsRefused(): void {
        $base = $this->scratch . '/journal';
        $journal = new PreviewRunJournal($base);
        $run = $journal->resumeOrStart('preview', $this->intent());
        $runPath = $journal->runDir($run['operation_id']) . '/run.json';
        $outside = $this->scratch . '/outside.json';
        self::assertTrue(link($runPath, $outside));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not a stable private regular file');
        $journal->latestForTarget('preview');
    }

    public function testTargetIndexAvoidsScanningForeignRunHistory(): void {
        $base = $this->scratch . '/journal';
        $journal = new PreviewRunJournal($base);
        $run = $journal->synchronizedTarget(
            'preview',
            fn (): array => $journal->resumeOrStart('preview', $this->intent())
        );
        $foreignOperation = '99991231-235959-ffffffffffffffffffffffff';
        $foreign = $base . '/runs/' . $foreignOperation;
        self::assertTrue(mkdir($foreign, 0700));
        self::assertTrue(chmod($foreign, 0700));
        self::assertSame(11, file_put_contents($foreign . '/run.json', 'malformed\n'));
        self::assertTrue(chmod($foreign . '/run.json', 0600));

        $latest = $journal->synchronizedTarget(
            'preview',
            fn (): ?array => $journal->latestForTarget('preview')
        );

        self::assertSame($run['operation_id'], $latest['operation_id'] ?? null);
    }

    public function testPartiallyDeletedReapTombstoneRecoversToTheLatestExactReceipt(): void {
        $base = $this->scratch . '/journal';
        $journal = new PreviewRunJournal($base);
        $first = $journal->synchronizedTarget(
            'preview',
            fn (): array => $journal->resumeOrStart('preview', $this->intent(1))
        );
        $journal->synchronizedTarget('preview', function () use ($journal, $first): void {
            $journal->append($first['operation_id'], 'reaped', [
                'receipt' => ['cycle' => 1, 'sha256' => hash('sha256', 'cycle-1')],
            ]);
        });
        $second = $journal->synchronizedTarget(
            'preview',
            fn (): array => $journal->resumeOrStart('preview', $this->intent(2))
        );
        $tombstone = $base . '/tombstones/' . hash('sha256', 'preview')
            . '.' . $first['operation_id'];
        self::assertTrue(rename($journal->runDir($first['operation_id']), $tombstone));
        self::assertTrue(unlink($tombstone . '/events/0001-prepared.json'));
        $expected = ['receipt' => ['cycle' => 2, 'sha256' => hash('sha256', 'cycle-2')]];

        $journal->synchronizedTarget('preview', function () use ($journal, $second, $expected): void {
            $journal->append($second['operation_id'], 'reaped', $expected);
        });
        $latest = $journal->synchronizedTarget(
            'preview',
            fn (): ?array => $journal->latestForTarget('preview')
        );

        self::assertSame($expected, PreviewRunJournal::eventData($latest['events'] ?? [], 'reaped'));
        self::assertFileDoesNotExist($tombstone);
        self::assertDirectoryDoesNotExist($journal->runDir($first['operation_id']));
        self::assertDirectoryExists($journal->runDir($second['operation_id']));
    }

    public function testTerminalIndexWithoutFinalEventResumesExactReapReplay(): void {
        $base = $this->scratch . '/journal';
        $journal = new PreviewRunJournal($base);
        $run = $journal->synchronizedTarget(
            'preview',
            fn (): array => $journal->resumeOrStart('preview', $this->intent())
        );
        $expected = [
            'receipt' => ['cycle' => 7, 'sha256' => hash('sha256', 'terminal-replay')],
        ];
        $journal->synchronizedTarget('preview', function () use ($journal, $run, $expected): void {
            $journal->append($run['operation_id'], 'reaped', $expected);
        });
        $reaped = $journal->runDir($run['operation_id']) . '/events/0002-reaped.json';
        self::assertTrue(unlink($reaped));

        $reopened = new PreviewRunJournal($base);
        $interrupted = $reopened->synchronizedTarget(
            'preview',
            fn (): ?array => $reopened->latestForTarget('preview')
        );
        self::assertNull(PreviewRunJournal::eventData($interrupted['events'] ?? [], 'reaped'));
        $reopened->synchronizedTarget('preview', function () use ($reopened, $run, $expected): void {
            $reopened->append($run['operation_id'], 'reaped', $expected);
        });
        $latest = $reopened->synchronizedTarget(
            'preview',
            fn (): ?array => $reopened->latestForTarget('preview')
        );

        self::assertSame($run['operation_id'], $latest['operation_id'] ?? null);
        self::assertSame($expected, PreviewRunJournal::eventData($latest['events'] ?? [], 'reaped'));
    }

    public function testOneThousandReapedCyclesRemainBoundedAndReplayTheLatestExactly(): void {
        $base = $this->scratch . '/journal';
        $journal = new PreviewRunJournal($base);
        $expected = [];
        $lastOperationId = '';
        for ($cycle = 0; $cycle < 1000; $cycle++) {
            $run = $journal->synchronizedTarget(
                'preview',
                fn (): array => $journal->resumeOrStart('preview', $this->intent($cycle))
            );
            self::assertFalse($run['resumed']);
            $lastOperationId = $run['operation_id'];
            $expected = [
                'receipt' => [
                    'cycle' => $cycle,
                    'sha256' => hash('sha256', 'preview-reap-cycle-' . $cycle),
                ],
            ];
            $journal->synchronizedTarget('preview', function () use ($journal, $lastOperationId, $expected): void {
                $journal->append($lastOperationId, 'reaped', $expected);
            });
            $latest = $journal->synchronizedTarget(
                'preview',
                fn (): ?array => $journal->latestForTarget('preview')
            );
            self::assertSame($lastOperationId, $latest['operation_id'] ?? null);
            self::assertSame($expected, PreviewRunJournal::eventData($latest['events'] ?? [], 'reaped'));
            self::assertCount(1, array_values(array_filter(
                glob($base . '/runs/*') ?: [],
                static fn (string $path): bool => is_dir($path)
            )));
            self::assertSame([], array_values(array_diff(
                scandir($base . '/tombstones') ?: [],
                ['.', '..']
            )));
        }

        $reopened = new PreviewRunJournal($base);
        $latest = $reopened->synchronizedTarget(
            'preview',
            fn (): ?array => $reopened->latestForTarget('preview')
        );
        self::assertSame($lastOperationId, $latest['operation_id'] ?? null);
        self::assertSame($expected, PreviewRunJournal::eventData($latest['events'] ?? [], 'reaped'));
        self::assertCount(2, glob($base . '/targets/*') ?: []);
        self::assertCount(4, $this->regularFiles($base . '/runs/' . $lastOperationId));
    }

    /** @return array<string,mixed> */
    private function intent(int $cycle = 0): array {
        return [
            'candidate_branch' => 'duo-preview/feature-' . $cycle,
            'origin_environment' => 'production',
            'production_commit' => str_repeat('a', 40),
            'production_ref' => 'refs/heads/main',
            'source_branch' => 'feature/site-' . $cycle,
            'source_commit' => hash('sha1', 'preview-source-' . $cycle),
            'target_driver_id' => 'cloud-preview:' . str_repeat('c', 64),
            'target_environment' => 'preview',
            'ttl_seconds' => 3600,
        ];
    }

    /**
     * @return array{
     *   identity:array<string,mixed>,
     *   journal:PreviewRunJournal,
     *   operation_id:string
     * }
     */
    private function completedPreview(string $directory): array {
        $journal = new PreviewRunJournal($this->scratch . '/' . $directory);
        $run = $journal->resumeOrStart('preview', $this->intent());
        $operationId = $run['operation_id'];
        $identity = [
            'environment_identity' => 'cloud-environment-identity-0001',
            'lease_generation' => 1,
            'lease_id' => 'cloud-lease-identity-0001',
            'ownership_receipt_sha256' => hash('sha256', 'bounded preview owner'),
            'resource_id' => 'cloud-preview-slot-0001',
            'url' => 'https://bounded-preview.example.test',
        ];
        $receiptBasis = $identity + [
            'format' => PortablePreviewMaterializer::RECEIPT_FORMAT,
            'operation_id' => $operationId,
            'target_provider' => ['id' => 'bounded-provider', 'protocol' => 1],
        ];
        $journal->append($operationId, 'complete', ['receipt' => $receiptBasis + [
            'receipt_sha256' => hash(
                'sha256',
                EnvironmentLifecycleCanon::encode($receiptBasis)
            ),
            'resumed' => false,
        ]]);
        return [
            'identity' => $identity,
            'journal' => $journal,
            'operation_id' => $operationId,
        ];
    }

    private function unreachablePreviewTransport(string $name, string $host = '127.0.0.1'): CloudPreviewTransport {
        $keys = $this->scratch . '/keys-' . $name;
        self::assertTrue(mkdir($keys, 0700));
        $requestPair = sodium_crypto_sign_keypair();
        $responsePair = sodium_crypto_sign_keypair();
        $requestKey = $keys . '/request.key';
        $responseKey = $keys . '/response.key';
        $requestBytes = base64_encode(sodium_crypto_sign_secretkey($requestPair)) . "\n";
        $responseBytes = base64_encode(sodium_crypto_sign_publickey($responsePair)) . "\n";
        self::assertSame(strlen($requestBytes), file_put_contents($requestKey, $requestBytes));
        self::assertSame(strlen($responseBytes), file_put_contents($responseKey, $responseBytes));
        self::assertTrue(chmod($requestKey, 0600));
        self::assertTrue(chmod($responseKey, 0600));
        return new CloudPreviewTransport('preview', [
            '_dir' => $this->scratch,
            '_machine_local' => true,
            'cloud_preview' => [
                'control_endpoint' => "http://$host:1/v1/preview/control",
                'lifecycle_endpoint' => "http://$host:1/v1/preview/lifecycle",
                'request_key_id' => $name . '-request-key',
                'request_signing_key' => $requestKey,
                'response_key_id' => $name . '-response-key',
                'response_public_key' => $responseKey,
                'site_id' => $name . '-site',
                'tenant_id' => $name . '-tenant',
                'timeout_seconds' => 1,
            ],
            'repo_path' => '/srv/duo/repository',
            'transport' => 'cloud-preview',
        ]);
    }

    /** @return list<string> */
    private function regularFiles(string $path): array {
        $files = [];
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                array_push($files, ...$this->regularFiles($child));
            } else {
                $files[] = $child;
            }
        }
        sort($files, SORT_STRING);
        return $files;
    }

    private function remove(string $path): void {
        if (is_link($path) || is_file($path) || !is_dir($path)) {
            self::assertTrue(unlink($path));
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') continue;
            $this->remove($path . '/' . $entry);
        }
        self::assertTrue(rmdir($path));
    }
}
