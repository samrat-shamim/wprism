<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\CommandRunner;
use Duo\Cloud\ControlAuthority;
use Duo\Cloud\ControlAuthorityMutationPublisher;
use Duo\Cloud\ControlRefusal;
use Duo\Cloud\FileAuthorityStore;
use Duo\Cloud\MutationAuthorityPublisher;
use Duo\Cloud\PreviewSlotLifecycle;
use Duo\Cloud\RepositorySyncRuntime;
use Duo\Cloud\WorkloadRuntime;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/cloud/src/PreviewSlotLifecycle.php';

final class PreviewSlotLifecycleTest extends TestCase {
    private string $root;
    private SlotRecordingRuntime $runtime;
    private PreviewSlotLifecycle $lifecycle;
    private int $now = 1893456000;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/duo-preview-slot-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
        $this->runtime = new SlotRecordingRuntime();
        $this->lifecycle = new PreviewSlotLifecycle(
            new FileAuthorityStore($this->root . '/lifecycle.json'),
            $this->runtime,
            null,
            fn (): int => $this->now
        );
    }

    protected function tearDown(): void {
        foreach (glob($this->root . '/*') ?: [] as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        @rmdir($this->root);
    }

    public function testCompatibleEvidenceExactReplayAndReleasedOrForeignFencesExecuteNothing(): void {
        $operation = self::operation(1);
        $identity = $this->create('tenant-a', 'site-a', $operation);
        self::assertSame('present', $identity['presence']);
        self::assertSame(1, $identity['lease_generation']);
        $afterCreate = count($this->runtime->calls);
        self::assertSame(
            $identity,
            $this->lifecycle->perform(
                'create',
                'tenant-a',
                'site-a',
                $operation,
                self::createInput('preview')
            )
        );
        self::assertCount($afterCreate, $this->runtime->calls);
        self::assertSame(0600, fileperms($this->root . '/lifecycle.json') & 0777);

        $other = $this->create('tenant-a', 'site-b', self::operation(2));
        self::assertNotSame($identity['resource_id'], $other['resource_id']);

        $fence = $this->acquire('tenant-a', 'site-a', $operation, $identity);
        self::assertSame('held', $fence['state']);
        $heldInput = self::identityInput($identity) + self::mutationInput($fence);
        self::assertSame(
            $fence,
            $this->lifecycle->perform(
                'mutation-read',
                'tenant-a',
                'site-a',
                $operation,
                $heldInput
            )
        );
        $restoreInput = $heldInput + [
            'database_sha256' => hash('sha256', 'database'),
            'media_sha256' => hash('sha256', 'media'),
            'snapshot_set_id' => 'snapshot-set-0001',
        ];
        $restored = $this->lifecycle->perform(
            'snapshot-restore',
            'tenant-a',
            'site-a',
            $operation,
            $restoreInput
        );
        self::assertSame('snapshot-set-0001', $restored['snapshot_set_id']);
        $restoreCalls = $this->runtime->callCount('restoreSnapshot');
        self::assertSame(
            $restored,
            $this->lifecycle->perform(
                'snapshot-restore',
                'tenant-a',
                'site-a',
                $operation,
                $restoreInput
            )
        );
        self::assertSame($restoreCalls, $this->runtime->callCount('restoreSnapshot'));

        $changedRestore = $restoreInput;
        $changedRestore['snapshot_set_id'] = 'snapshot-set-0002';
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'snapshot-restore',
                'tenant-a',
                'site-a',
                $operation,
                $changedRestore
            ),
            'changed canonical input'
        );
        self::assertSame($restoreCalls, $this->runtime->callCount('restoreSnapshot'));

        $repositoryAuthority = $this->runtime->repositoryAuthorityDescriptor();
        $branchRef = 'refs/heads/duo-preview/' . $operation;
        $publicationReceipt = hash('sha256', 'candidate publication');
        $repositorySyncInput = $heldInput + [
            'branch_commit' => str_repeat('a', 40),
            'branch_ref' => $branchRef,
            'candidate_publication_receipt_sha256' => $publicationReceipt,
            'repository_authority_sha256' => $repositoryAuthority['descriptor_sha256'],
        ];
        $repositorySync = $this->lifecycle->perform(
            'repository-sync',
            'tenant-a',
            'site-a',
            $operation,
            $repositorySyncInput
        );
        self::assertSame($publicationReceipt, $repositorySync['candidate_publication_receipt_sha256']);
        $syncCalls = $this->runtime->callCount('syncRepository');
        self::assertSame($repositorySync, $this->lifecycle->perform(
            'repository-sync',
            'tenant-a',
            'site-a',
            $operation,
            $repositorySyncInput
        ));
        self::assertSame($syncCalls, $this->runtime->callCount('syncRepository'));
        $changedSync = $repositorySyncInput;
        $changedSync['branch_ref'] .= '-changed';
        $this->assertRefused(fn (): array => $this->lifecycle->perform(
            'repository-sync',
            'tenant-a',
            'site-a',
            $operation,
            $changedSync
        ), 'changed canonical input');
        self::assertSame($syncCalls, $this->runtime->callCount('syncRepository'));
        $repository = $this->lifecycle->perform(
            'repository-materialize',
            'tenant-a',
            'site-a',
            $operation,
            $heldInput + [
                'branch_commit' => str_repeat('a', 40),
                'branch_ref' => $branchRef,
                'expected_repository_authority_sha256' =>
                    $repositoryAuthority['descriptor_sha256'],
                'expected_repository_sync_receipt_sha256' =>
                    $repositorySync['repository_sync_receipt_sha256'],
                'repo_path' => '/srv/preview/repository',
            ]
        );
        self::assertSame(str_repeat('a', 40), $repository['branch_commit']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $repository['repository_receipt_sha256']);

        $url = $this->lifecycle->perform(
            'url-set',
            'tenant-a',
            'site-a',
            $operation,
            $heldInput + ['url' => $identity['url']]
        );
        self::assertSame($identity['url'], $url['url']);

        $ttl = $this->lifecycle->perform(
            'ttl-set',
            'tenant-a',
            'site-a',
            $operation,
            $heldInput + ['ttl_seconds' => 3600]
        );
        self::assertSame('active', $ttl['ttl_state']);
        $this->now += 7200;
        $ttlRead = $this->lifecycle->perform(
            'ttl-read',
            'tenant-a',
            'site-a',
            $operation,
            $heldInput + [
                'expected_expires_at' => $ttl['expires_at'],
                'expected_ttl_generation' => $ttl['ttl_generation'],
                'expected_ttl_lease_id' => $ttl['ttl_lease_id'],
                'expected_ttl_receipt_sha256' => $ttl['ttl_receipt_sha256'],
            ]
        );
        self::assertSame($ttl, $ttlRead);
        self::assertSame(0, $this->runtime->callCount('revokeRouting'));
        self::assertSame(0, $this->runtime->callCount('deleteState'));

        $released = $this->lifecycle->perform(
            'mutation-release',
            'tenant-a',
            'site-a',
            $operation,
            $heldInput
        );
        self::assertSame('released', $released['state']);
        $releasedRead = $this->lifecycle->perform(
            'mutation-read',
            'tenant-a',
            'site-a',
            self::operation(6),
            self::identityInput($identity) + self::mutationInput($released)
        );
        self::assertSame('released', $releasedRead['state']);
        self::assertSame($released['mutation_receipt_sha256'], $releasedRead['mutation_receipt_sha256']);
        $beforeStale = count($this->runtime->calls);
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'repository-materialize',
                'tenant-a',
                'site-a',
                self::operation(3),
                $heldInput + [
                    'branch_commit' => str_repeat('b', 40),
                    'branch_ref' => 'refs/heads/stale',
                    'expected_repository_authority_sha256' => hash('sha256', 'stale authority'),
                    'expected_repository_sync_receipt_sha256' => hash('sha256', 'stale sync'),
                    'repo_path' => '/srv/preview/repository',
                ]
            ),
            'exact allowed mutation fence'
        );
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'url-set',
                'tenant-a',
                'site-b',
                self::operation(4),
                $heldInput + ['url' => $identity['url']]
            ),
            'current lifecycle identity'
        );
        self::assertCount($beforeStale, $this->runtime->calls);

        $reapFence = $this->lifecycle->perform(
            'mutation-acquire',
            'tenant-a',
            'site-a',
            self::operation(7),
            self::identityInput($identity) + [
                'mutation_owner' => 'duo-env-reap-' . $operation,
            ]
        );
        self::assertSame(2, $reapFence['mutation_generation']);
        self::assertSame('duo-env-reap-' . $operation, $reapFence['mutation_owner']);

        $inspected = $this->lifecycle->perform(
            'inspect',
            'tenant-a',
            'site-a',
            self::operation(5),
            self::identityInput($identity) + ['role' => 'target']
        );
        self::assertSame('present', $inspected['presence']);
    }

    public function testStaleReapReplayCannotTouchAReusedGeneration(): void {
        $firstOperation = self::operation(10);
        $first = $this->create('tenant-a', 'site-a', $firstOperation);
        $firstFence = $this->acquire('tenant-a', 'site-a', $firstOperation, $first);
        $destroyInput = self::identityInput($first) + self::mutationInput($firstFence) + [
            'compare_and_reap' => true,
        ];
        $destroyed = $this->lifecycle->perform(
            'destroy',
            'tenant-a',
            'site-a',
            $firstOperation,
            $destroyInput
        );
        self::assertSame('destroyed', $destroyed['disposition']);

        $second = $this->create('tenant-a', 'site-a', self::operation(11));
        self::assertSame($first['resource_id'], $second['resource_id']);
        self::assertSame(2, $second['lease_generation']);
        self::assertSame(3, $this->runtime->callCount('verifyAbsent'));
        $beforeReplay = count($this->runtime->calls);
        self::assertSame(
            $destroyed,
            $this->lifecycle->perform(
                'destroy',
                'tenant-a',
                'site-a',
                $firstOperation,
                $destroyInput
            )
        );
        self::assertCount($beforeReplay, $this->runtime->calls);

        $changed = $destroyInput;
        $changed['expected_mutation_id'] .= '-changed';
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'destroy',
                'tenant-a',
                'site-a',
                $firstOperation,
                $changed
            ),
            'changed canonical input'
        );
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'destroy',
                'tenant-a',
                'site-a',
                self::operation(12),
                $destroyInput
            ),
            'current lifecycle identity'
        );
        self::assertCount($beforeReplay, $this->runtime->calls);

        $inspected = $this->lifecycle->perform(
            'inspect',
            'tenant-a',
            'site-a',
            self::operation(13),
            self::identityInput($second) + ['role' => 'target']
        );
        self::assertSame('present', $inspected['presence']);
        self::assertSame(2, $inspected['lease_generation']);
    }

    public function testPartialReapBlocksInspectionAndReuseThenResumesOnlyTheRemainingStages(): void {
        $operation = self::operation(20);
        $identity = $this->create('tenant-a', 'site-a', $operation);
        $fence = $this->acquire('tenant-a', 'site-a', $operation, $identity);
        $destroyInput = self::identityInput($identity) + self::mutationInput($fence) + [
            'compare_and_reap' => true,
        ];
        $this->runtime->failNext('revokeExecution');
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'destroy',
                'tenant-a',
                'site-a',
                $operation,
                $destroyInput
            ),
            'execution revocation outcome is indeterminate'
        );
        self::assertSame(1, $this->runtime->callCount('revokeRouting'));
        self::assertSame(1, $this->runtime->callCount('revokeExecution'));
        self::assertSame(0, $this->runtime->callCount('deleteState'));

        $beforeBlocked = count($this->runtime->calls);
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'inspect',
                'tenant-a',
                'site-a',
                self::operation(21),
                self::identityInput($identity) + ['role' => 'target']
            ),
            'reap is incomplete'
        );
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'create',
                'tenant-a',
                'site-a',
                self::operation(22),
                self::createInput('preview')
            ),
            'already owned or has incomplete reap'
        );
        self::assertCount($beforeBlocked, $this->runtime->calls);

        $changed = $destroyInput;
        $changed['expected_mutation_id'] .= '-changed';
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'destroy',
                'tenant-a',
                'site-a',
                $operation,
                $changed
            ),
            'changed canonical input'
        );
        self::assertCount($beforeBlocked, $this->runtime->calls);

        $destroyed = $this->lifecycle->perform(
            'destroy',
            'tenant-a',
            'site-a',
            $operation,
            $destroyInput
        );
        self::assertSame('destroyed', $destroyed['disposition']);
        self::assertSame(1, $this->runtime->callCount('revokeRouting'));
        self::assertSame(2, $this->runtime->callCount('revokeExecution'));
        self::assertSame(1, $this->runtime->callCount('deleteState'));
        self::assertSame(
            ['revokeRouting', 'revokeExecution', 'revokeExecution', 'deleteState'],
            array_values(array_filter(
                array_column($this->runtime->calls, 'method'),
                static fn (string $method): bool => in_array($method, [
                    'revokeRouting', 'revokeExecution', 'deleteState',
                ], true)
            ))
        );
    }

    public function testCommandInFlightCompletesBeforeFenceReleasePublishesAndStaleCommandRefuses(): void {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('pcntl is required for the cross-process lock-order regression');
        }
        $controllerKeys = sodium_crypto_sign_keypair();
        $controllerSecret = sodium_crypto_sign_secretkey($controllerKeys);
        $controllerPublic = sodium_crypto_sign_publickey($controllerKeys);
        $responseKeys = sodium_crypto_sign_keypair();
        $runner = new BlockingSlotCommandRunner(
            $this->root . '/command-started',
            $this->root . '/command-allow',
            $this->root . '/command-calls'
        );
        $control = new ControlAuthority(
            new FileAuthorityStore($this->root . '/control.json'),
            $runner,
            'service-response-v1',
            sodium_crypto_sign_secretkey($responseKeys)
        );
        $control->registerRequestKey('controller-key-v1', 'tenant-a', 'site-a', $controllerPublic);
        $releaseAttempted = $this->root . '/release-attempted';
        $publisher = new SignalingAuthorityPublisher(
            new ControlAuthorityMutationPublisher($control),
            $releaseAttempted
        );
        $this->lifecycle = new PreviewSlotLifecycle(
            new FileAuthorityStore($this->root . '/integrated-lifecycle.json'),
            $this->runtime,
            $publisher,
            fn (): int => $this->now
        );
        $operation = self::operation(30);
        $identity = $this->create('tenant-a', 'site-a', $operation);
        $fence = $this->acquire('tenant-a', 'site-a', $operation, $identity);
        $target = [
            'environment_identity' => $fence['environment_identity'],
            'lease_generation' => $fence['lease_generation'],
            'lease_id' => $fence['lease_id'],
            'mutation_generation' => $fence['mutation_generation'],
            'mutation_id' => $fence['mutation_id'],
            'mutation_owner' => $fence['mutation_owner'],
            'mutation_receipt_sha256' => $fence['mutation_receipt_sha256'],
            'ownership_receipt_sha256' => $fence['ownership_receipt_sha256'],
            'resource_id' => $fence['resource_id'],
        ];
        $request = self::signedCommand($operation, $target, $controllerSecret, 'blocking-command', 0);

        $commandPid = pcntl_fork();
        self::assertNotSame(-1, $commandPid);
        if ($commandPid === 0) {
            try {
                $control->handle($request);
                exit(0);
            } catch (\Throwable) {
                exit(70);
            }
        }
        self::waitForFile($this->root . '/command-started');

        $releaseDone = $this->root . '/release-done';
        $releaseInput = self::identityInput($identity) + self::mutationInput($fence);
        $releaseStarted = microtime(true);
        $releasePid = pcntl_fork();
        self::assertNotSame(-1, $releasePid);
        if ($releasePid === 0) {
            try {
                $this->lifecycle->perform(
                    'mutation-release',
                    'tenant-a',
                    'site-a',
                    $operation,
                    $releaseInput
                );
                file_put_contents($releaseDone, "released\n");
                exit(0);
            } catch (\Throwable) {
                exit(71);
            }
        }
        self::waitForFile($releaseAttempted);
        pcntl_waitpid($releasePid, $releaseStatus);
        self::assertLessThan(2.0, microtime(true) - $releaseStarted);
        self::assertSame(71, pcntl_wexitstatus($releaseStatus));
        self::assertFileDoesNotExist($releaseDone, 'release published while a command still held command authority');
        file_put_contents($this->root . '/command-allow', "continue\n");
        pcntl_waitpid($commandPid, $commandStatus);
        self::assertSame(0, pcntl_wexitstatus($commandStatus));
        $released = $this->lifecycle->perform(
            'mutation-release',
            'tenant-a',
            'site-a',
            $operation,
            $releaseInput
        );
        file_put_contents($releaseDone, "released\n");
        self::assertSame('released', $released['state']);
        self::assertFileExists($releaseDone);
        self::assertSame(1, self::lineCount($this->root . '/command-calls'));

        $stale = self::signedCommand($operation, $target, $controllerSecret, 'after-release', 1);
        $this->assertRefused(
            fn (): string => $control->handle($stale),
            'exact current held preview authority'
        );
        self::assertSame(1, self::lineCount($this->root . '/command-calls'));
    }

    public function testReapFenceNeverBecomesFreshCommandAuthority(): void {
        $responseKeys = sodium_crypto_sign_keypair();
        $control = new ControlAuthority(
            new FileAuthorityStore($this->root . '/reap-control.json'),
            new class() implements CommandRunner {
                public function run(array $request): array {
                    throw new \RuntimeException('reap authority must never dispatch a command');
                }
            },
            'service-response-v1',
            sodium_crypto_sign_secretkey($responseKeys)
        );
        $publisher = new ControlAuthorityMutationPublisher($control);
        $this->lifecycle = new PreviewSlotLifecycle(
            new FileAuthorityStore($this->root . '/reap-lifecycle.json'),
            $this->runtime,
            $publisher,
            fn (): int => $this->now
        );
        $materializeOperation = self::operation(40);
        $identity = $this->create('tenant-a', 'site-a', $materializeOperation);
        $materialFence = $this->acquire('tenant-a', 'site-a', $materializeOperation, $identity);
        $released = $this->lifecycle->perform(
            'mutation-release',
            'tenant-a',
            'site-a',
            $materializeOperation,
            self::identityInput($identity) + self::mutationInput($materialFence)
        );
        self::assertSame('released', $released['state']);
        $releasedAuthority = $control->currentAuthority('tenant-a', 'site-a');
        self::assertSame('released', $releasedAuthority['state'] ?? null);

        $reapOperation = self::operation(41);
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'mutation-acquire',
                'tenant-a',
                'site-a',
                $reapOperation,
                self::identityInput($identity) + [
                    'mutation_owner' => 'duo-env-reap-' . self::operation(42),
                ]
            ),
            'matching reap principal'
        );
        $reapFence = $this->lifecycle->perform(
            'mutation-acquire',
            'tenant-a',
            'site-a',
            $reapOperation,
            self::identityInput($identity) + [
                'mutation_owner' => 'duo-env-reap-' . $materializeOperation,
            ]
        );
        self::assertGreaterThan($materialFence['mutation_generation'], $reapFence['mutation_generation']);
        self::assertSame(
            CanonicalJson::encode($releasedAuthority),
            CanonicalJson::encode($control->currentAuthority('tenant-a', 'site-a')),
            'provider cleanup authority must not replace the released command tombstone'
        );
        $destroyed = $this->lifecycle->perform(
            'destroy',
            'tenant-a',
            'site-a',
            $reapOperation,
            self::identityInput($identity) + self::mutationInput($reapFence) + [
                'compare_and_reap' => true,
            ]
        );
        self::assertSame('destroyed', $destroyed['disposition']);
        self::assertSame(
            CanonicalJson::encode($releasedAuthority),
            CanonicalJson::encode($control->currentAuthority('tenant-a', 'site-a'))
        );
    }

    public function testSleepFenceMustRetainTheMaterializeOperationLineage(): void {
        $responseKeys = sodium_crypto_sign_keypair();
        $control = new ControlAuthority(
            new FileAuthorityStore($this->root . '/sleep-control.json'),
            new class() implements CommandRunner {
                public function run(array $request): array {
                    throw new \RuntimeException('sleep authority must never dispatch a command');
                }
            },
            'service-response-v1',
            sodium_crypto_sign_secretkey($responseKeys)
        );
        $this->lifecycle = new PreviewSlotLifecycle(
            new FileAuthorityStore($this->root . '/sleep-lifecycle.json'),
            $this->runtime,
            new ControlAuthorityMutationPublisher($control),
            fn (): int => $this->now
        );
        $materializeOperation = self::operation(45);
        $identity = $this->create('tenant-a', 'site-a', $materializeOperation);
        $materialFence = $this->acquire(
            'tenant-a',
            'site-a',
            $materializeOperation,
            $identity
        );
        $released = $this->lifecycle->perform(
            'mutation-release',
            'tenant-a',
            'site-a',
            $materializeOperation,
            self::identityInput($identity) + self::mutationInput($materialFence)
        );
        self::assertSame('released', $released['state']);
        $releasedAuthority = $control->currentAuthority('tenant-a', 'site-a');
        self::assertSame('released', $releasedAuthority['state'] ?? null);

        $beforeForeign = count($this->runtime->calls);
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'mutation-acquire',
                'tenant-a',
                'site-a',
                self::operation(46),
                self::identityInput($identity) + [
                    'mutation_owner' => 'duo-env-sleep-' . self::operation(47),
                ]
            ),
            'matching reap principal'
        );
        self::assertCount($beforeForeign, $this->runtime->calls);

        $sleepFence = $this->lifecycle->perform(
            'mutation-acquire',
            'tenant-a',
            'site-a',
            self::operation(48),
            self::identityInput($identity) + [
                'mutation_owner' => 'duo-env-sleep-' . $materializeOperation,
            ]
        );
        self::assertSame('held', $sleepFence['state']);
        self::assertSame(
            'duo-env-sleep-' . $materializeOperation,
            $sleepFence['mutation_owner']
        );
        self::assertGreaterThan(
            $materialFence['mutation_generation'],
            $sleepFence['mutation_generation']
        );
        self::assertSame(
            CanonicalJson::encode($releasedAuthority),
            CanonicalJson::encode($control->currentAuthority('tenant-a', 'site-a')),
            'a sleep fence must retain the released materialization command tombstone'
        );
    }

    public function testOperationJournalStaysBoundedAcrossOneThousandSlotGenerations(): void {
        $twoBackOperation = null;
        $twoBackDestroyInput = null;
        $terminalOperation = null;
        $terminalDestroyInput = null;
        $terminalDestroyed = null;

        for ($generation = 1; $generation < 1000; $generation++) {
            $operation = self::operation(1000 + $generation);
            $identity = $this->create('tenant-a', 'site-a', $operation);
            $fence = $this->acquire('tenant-a', 'site-a', $operation, $identity);
            $destroyInput = self::identityInput($identity) + self::mutationInput($fence) + [
                'compare_and_reap' => true,
            ];
            $destroyed = $this->lifecycle->perform(
                'destroy',
                'tenant-a',
                'site-a',
                $operation,
                $destroyInput
            );
            if ($generation === 998) {
                $twoBackOperation = $operation;
                $twoBackDestroyInput = $destroyInput;
            } elseif ($generation === 999) {
                $terminalOperation = $operation;
                $terminalDestroyInput = $destroyInput;
                $terminalDestroyed = $destroyed;
            }
        }
        $current = $this->create('tenant-a', 'site-a', self::operation(2000));
        self::assertSame(1000, $current['lease_generation']);

        self::assertIsString($terminalOperation);
        self::assertIsArray($terminalDestroyInput);
        self::assertIsArray($terminalDestroyed);
        $beforeReplay = count($this->runtime->calls);
        self::assertSame($terminalDestroyed, $this->lifecycle->perform(
            'destroy',
            'tenant-a',
            'site-a',
            $terminalOperation,
            $terminalDestroyInput
        ));
        self::assertCount($beforeReplay, $this->runtime->calls);

        self::assertIsString($twoBackOperation);
        self::assertIsArray($twoBackDestroyInput);
        $this->assertRefused(fn (): array => $this->lifecycle->perform(
            'destroy',
            'tenant-a',
            'site-a',
            $twoBackOperation,
            $twoBackDestroyInput
        ), 'current lifecycle identity');
        self::assertCount($beforeReplay, $this->runtime->calls);

        $state = CanonicalJson::decodeObject(
            (string) file_get_contents($this->root . '/lifecycle.json')
        );
        self::assertCount(4, $state['operations']);
        self::assertLessThan(16384, filesize($this->root . '/lifecycle.json'));
    }

    public function testOperationCountCapRefusesBeforeRuntimeInspection(): void {
        $identity = $this->create('tenant-a', 'site-a', self::operation(3000));
        $firstOperation = self::operation(3001);
        $first = null;
        for ($offset = 0; $offset < 123; $offset++) {
            $operation = self::operation(3001 + $offset);
            $inspection = $this->lifecycle->perform(
                'inspect',
                'tenant-a',
                'site-a',
                $operation,
                self::identityInput($identity) + ['role' => 'target']
            );
            if ($operation === $firstOperation) {
                $first = $inspection;
            }
        }
        $beforeRefusal = $this->runtime->callCount('inspect');
        $this->assertRefused(fn (): array => $this->lifecycle->perform(
            'inspect',
            'tenant-a',
            'site-a',
            self::operation(3124),
            self::identityInput($identity) + ['role' => 'target']
        ), 'cleanup-reserved per-generation limit');
        self::assertSame($beforeRefusal, $this->runtime->callCount('inspect'));
        self::assertIsArray($first);
        self::assertSame($first, $this->lifecycle->perform(
            'inspect',
            'tenant-a',
            'site-a',
            $firstOperation,
            self::identityInput($identity) + ['role' => 'target']
        ));
        self::assertSame($beforeRefusal, $this->runtime->callCount('inspect'));

        $fence = $this->acquire('tenant-a', 'site-a', self::operation(3125), $identity);
        self::assertSame('destroyed', $this->lifecycle->perform(
            'destroy',
            'tenant-a',
            'site-a',
            self::operation(3126),
            self::identityInput($identity) + self::mutationInput($fence) + [
                'compare_and_reap' => true,
            ]
        )['disposition']);
    }

    public function testIncompleteTerminalOperationMustReplayBeforeSlotReuse(): void {
        $operation = self::operation(4000);
        $identity = $this->create('tenant-a', 'site-a', $operation);
        $inspectOperation = self::operation(4001);
        $inspectInput = self::identityInput($identity) + ['role' => 'target'];
        $this->runtime->failNext('inspect');
        $this->assertRefused(fn (): array => $this->lifecycle->perform(
            'inspect',
            'tenant-a',
            'site-a',
            $inspectOperation,
            $inspectInput
        ), 'inspection outcome is indeterminate');

        $fence = $this->acquire('tenant-a', 'site-a', $operation, $identity);
        $this->lifecycle->perform(
            'destroy',
            'tenant-a',
            'site-a',
            $operation,
            self::identityInput($identity) + self::mutationInput($fence) + [
                'compare_and_reap' => true,
            ]
        );
        $beforeBlockedCreate = count($this->runtime->calls);
        $this->assertRefused(fn (): array => $this->create(
            'tenant-a',
            'site-a',
            self::operation(4002)
        ), 'indeterminate lifecycle operation');
        self::assertCount($beforeBlockedCreate, $this->runtime->calls);

        self::assertSame('absent', $this->lifecycle->perform(
            'inspect',
            'tenant-a',
            'site-a',
            $inspectOperation,
            $inspectInput
        )['presence']);
        self::assertSame(
            2,
            $this->create('tenant-a', 'site-a', self::operation(4002))['lease_generation']
        );
    }

    public function testExpiredDestroySupersedesInterruptedFencePublicationAndRelease(): void {
        $publisher = new OneShotFailureAuthorityPublisher();
        $this->lifecycle = new PreviewSlotLifecycle(
            new FileAuthorityStore($this->root . '/interrupted-fence-lifecycle.json'),
            $this->runtime,
            $publisher,
            fn (): int => $this->now
        );

        $materializeOperation = self::operation(4900);
        $identity = $this->create('tenant-a', 'site-a', $materializeOperation);
        $materialFence = $this->acquire(
            'tenant-a',
            'site-a',
            $materializeOperation,
            $identity
        );
        $ttlOperation = self::operation(4901);
        $ttl = $this->lifecycle->perform(
            'ttl-set',
            'tenant-a',
            'site-a',
            $ttlOperation,
            self::identityInput($identity) + self::mutationInput($materialFence) + [
                'ttl_seconds' => 60,
            ]
        );
        $this->lifecycle->perform(
            'mutation-release',
            'tenant-a',
            'site-a',
            $materializeOperation,
            self::identityInput($identity) + self::mutationInput($materialFence)
        );

        $reapAcquireOperation = self::operation(4902);
        $reapAcquireInput = self::identityInput($identity) + [
            'mutation_owner' => 'duo-env-reap-' . $materializeOperation,
        ];
        $publisher->failNextHold();
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'mutation-acquire',
                'tenant-a',
                'site-a',
                $reapAcquireOperation,
                $reapAcquireInput
            ),
            'command authority hold outcome is indeterminate'
        );
        $this->now += 61;
        $expiredAcquireDestroy = $this->lifecycle->reapExpired(
            'tenant-a',
            'site-a',
            self::operation(4903),
            self::identityInput($identity) + ['compare_and_reap' => true],
            self::ttlComparison($ttl, $ttlOperation)
        );
        self::assertSame('destroyed', $expiredAcquireDestroy['disposition']);
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'mutation-acquire',
                'tenant-a',
                'site-a',
                $reapAcquireOperation,
                $reapAcquireInput
            ),
            'superseded by exact destroy'
        );
        self::assertSame(
            2,
            $this->create('tenant-a', 'site-a', self::operation(4904))['lease_generation']
        );

        $releaseIdentity = $this->create('tenant-a', 'site-b', self::operation(4910));
        $releaseOperation = self::operation(4911);
        $releaseFence = $this->acquire(
            'tenant-a',
            'site-b',
            $releaseOperation,
            $releaseIdentity
        );
        $releaseTtlOperation = self::operation(4912);
        $releaseTtl = $this->lifecycle->perform(
            'ttl-set',
            'tenant-a',
            'site-b',
            $releaseTtlOperation,
            self::identityInput($releaseIdentity) + self::mutationInput($releaseFence) + [
                'ttl_seconds' => 60,
            ]
        );
        $releaseInput = self::identityInput($releaseIdentity)
            + self::mutationInput($releaseFence);
        $publisher->failNextRelease();
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'mutation-release',
                'tenant-a',
                'site-b',
                $releaseOperation,
                $releaseInput
            ),
            'command authority release outcome is indeterminate'
        );
        $this->now += 61;
        $expiredReleaseDestroy = $this->lifecycle->reapExpired(
            'tenant-a',
            'site-b',
            self::operation(4913),
            self::identityInput($releaseIdentity) + ['compare_and_reap' => true],
            self::ttlComparison($releaseTtl, $releaseTtlOperation)
        );
        self::assertSame('destroyed', $expiredReleaseDestroy['disposition']);
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'mutation-release',
                'tenant-a',
                'site-b',
                $releaseOperation,
                $releaseInput
            ),
            'superseded by exact destroy'
        );
        self::assertSame(
            2,
            $this->create('tenant-a', 'site-b', self::operation(4914))['lease_generation']
        );
    }

    public function testSleepWakePreserveGenerationAndRecoverLostResponsesBeforeExactDestroy(): void {
        $createOperation = self::operation(5000);
        $identity = $this->create('tenant-a', 'site-a', $createOperation);
        $fence = $this->acquire('tenant-a', 'site-a', self::operation(5001), $identity);
        $heldInput = self::identityInput($identity) + self::mutationInput($fence);
        $sleepOperation = self::operation(5002);

        $this->runtime->loseNextResponse('revokeExecution');
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'sleep',
                'tenant-a',
                'site-a',
                $sleepOperation,
                $heldInput
            ),
            'sleep execution revocation outcome is indeterminate'
        );
        self::assertSame(
            ['execution' => false, 'present' => true, 'routing' => false, 'url' => $identity['url']],
            $this->runtime->resourceState($identity['resource_id'])
        );
        $callsBeforeBlockedCommand = count($this->runtime->calls);
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'snapshot-restore',
                'tenant-a',
                'site-a',
                self::operation(5003),
                $heldInput + [
                    'database_sha256' => hash('sha256', 'sleep database'),
                    'media_sha256' => hash('sha256', 'sleep media'),
                    'snapshot_set_id' => 'sleep-snapshot',
                ]
            ),
            'sleep/wake transition is incomplete'
        );
        self::assertCount($callsBeforeBlockedCommand, $this->runtime->calls);

        $slept = $this->lifecycle->perform(
            'sleep',
            'tenant-a',
            'site-a',
            $sleepOperation,
            $heldInput
        );
        self::assertSame('asleep', $slept['sleep_state']);
        foreach (self::identityInput($identity) as $inputKey => $expected) {
            $resultKey = substr($inputKey, strlen('expected_'));
            self::assertSame($expected, $slept[$resultKey]);
        }
        self::assertSame(1, $this->runtime->callCount('revokeRouting'));
        self::assertSame(2, $this->runtime->callCount('revokeExecution'));
        $afterSleep = count($this->runtime->calls);
        self::assertSame($slept, $this->lifecycle->perform(
            'sleep',
            'tenant-a',
            'site-a',
            $sleepOperation,
            $heldInput
        ));
        self::assertCount($afterSleep, $this->runtime->calls);

        $changedSleep = $heldInput;
        $changedSleep['expected_mutation_receipt_sha256'] = hash('sha256', 'changed sleep fence');
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'sleep',
                'tenant-a',
                'site-a',
                $sleepOperation,
                $changedSleep
            ),
            'changed canonical input'
        );
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'snapshot-restore',
                'tenant-a',
                'site-a',
                self::operation(5004),
                $heldInput + [
                    'database_sha256' => hash('sha256', 'asleep database'),
                    'media_sha256' => hash('sha256', 'asleep media'),
                    'snapshot_set_id' => 'asleep-snapshot',
                ]
            ),
            'is asleep'
        );

        $wakeOperation = self::operation(5005);
        $this->runtime->loseNextResponse('configureUrl');
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'wake',
                'tenant-a',
                'site-a',
                $wakeOperation,
                $heldInput
            ),
            'wake URL routing restore outcome is indeterminate'
        );
        self::assertSame(
            ['execution' => true, 'present' => true, 'routing' => true, 'url' => $identity['url']],
            $this->runtime->resourceState($identity['resource_id'])
        );
        $woken = $this->lifecycle->perform(
            'wake',
            'tenant-a',
            'site-a',
            $wakeOperation,
            $heldInput
        );
        self::assertSame('awake', $woken['sleep_state']);
        self::assertSame($identity['lease_generation'], $woken['lease_generation']);
        self::assertSame($identity['lease_id'], $woken['lease_id']);
        self::assertSame($identity['ownership_receipt_sha256'], $woken['ownership_receipt_sha256']);
        self::assertSame(1, $this->runtime->callCount('resumeExecution'));
        self::assertSame(2, $this->runtime->callCount('configureUrl'));
        $afterWake = count($this->runtime->calls);
        self::assertSame($woken, $this->lifecycle->perform(
            'wake',
            'tenant-a',
            'site-a',
            $wakeOperation,
            $heldInput
        ));
        self::assertCount($afterWake, $this->runtime->calls);

        $secondSleep = self::operation(5006);
        self::assertSame('asleep', $this->lifecycle->perform(
            'sleep',
            'tenant-a',
            'site-a',
            $secondSleep,
            $heldInput
        )['sleep_state']);
        $interruptedWake = self::operation(5007);
        $this->runtime->loseNextResponse('resumeExecution');
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'wake',
                'tenant-a',
                'site-a',
                $interruptedWake,
                $heldInput
            ),
            'wake execution resume outcome is indeterminate'
        );
        $destroyOperation = self::operation(5008);
        $destroyInput = $heldInput + ['compare_and_reap' => true];
        $destroyed = $this->lifecycle->perform(
            'destroy',
            'tenant-a',
            'site-a',
            $destroyOperation,
            $destroyInput
        );
        self::assertSame('destroyed', $destroyed['disposition']);
        self::assertFalse($this->runtime->resourceState($identity['resource_id'])['present']);
        self::assertSame($destroyed, $this->lifecycle->perform(
            'destroy',
            'tenant-a',
            'site-a',
            $destroyOperation,
            $destroyInput
        ));

        $afterDestroy = count($this->runtime->calls);
        $this->assertRefused(
            fn (): array => $this->lifecycle->perform(
                'wake',
                'tenant-a',
                'site-a',
                $interruptedWake,
                $heldInput
            ),
            'superseded by exact destroy'
        );
        self::assertCount($afterDestroy, $this->runtime->calls);

        $replacement = $this->create('tenant-a', 'site-a', self::operation(5009));
        self::assertSame(2, $replacement['lease_generation']);
        self::assertSame($identity['resource_id'], $replacement['resource_id']);
    }

    public function testExpiredServiceReapsAnAsleepGenerationWithoutWakingIt(): void {
        $identity = $this->create('tenant-a', 'site-a', self::operation(5100));
        $fence = $this->acquire('tenant-a', 'site-a', self::operation(5101), $identity);
        $heldInput = self::identityInput($identity) + self::mutationInput($fence);
        $ttlOperation = self::operation(5102);
        $ttl = $this->lifecycle->perform(
            'ttl-set',
            'tenant-a',
            'site-a',
            $ttlOperation,
            $heldInput + ['ttl_seconds' => 60]
        );
        self::assertSame('asleep', $this->lifecycle->perform(
            'sleep',
            'tenant-a',
            'site-a',
            self::operation(5103),
            $heldInput
        )['sleep_state']);
        $this->now += 61;
        $reapOperation = self::operation(5104);
        $destroyInput = self::identityInput($identity) + ['compare_and_reap' => true];
        $expectedTtl = [
            'expires_at' => $ttl['expires_at'],
            'generation' => $ttl['ttl_generation'],
            'lease_id' => $ttl['ttl_lease_id'],
            'operation_id' => $ttlOperation,
            'receipt_sha256' => $ttl['ttl_receipt_sha256'],
            'state' => 'active',
        ];
        $destroyed = $this->lifecycle->reapExpired(
            'tenant-a',
            'site-a',
            $reapOperation,
            $destroyInput,
            $expectedTtl
        );
        self::assertSame('destroyed', $destroyed['disposition']);
        self::assertSame(0, $this->runtime->callCount('resumeExecution'));
        self::assertSame($destroyed, $this->lifecycle->reapExpired(
            'tenant-a',
            'site-a',
            $reapOperation,
            $destroyInput,
            $expectedTtl
        ));

        $beforeControllerConvergence = count($this->runtime->calls);
        $controllerOperation = self::operation(5105);
        $controllerInput = $heldInput + ['compare_and_reap' => true];
        $controllerDestroyed = $this->lifecycle->perform(
            'destroy',
            'tenant-a',
            'site-a',
            $controllerOperation,
            $controllerInput
        );
        self::assertSame($destroyed, $controllerDestroyed);
        self::assertSame($controllerDestroyed, $this->lifecycle->perform(
            'destroy',
            'tenant-a',
            'site-a',
            $controllerOperation,
            $controllerInput
        ));
        self::assertCount(
            $beforeControllerConvergence,
            $this->runtime->calls,
            'controller convergence after service TTL absence must perform no physical destroy'
        );
        self::assertSame(
            2,
            $this->create('tenant-a', 'site-a', self::operation(5106))['lease_generation']
        );
    }

    /** @return array<string,mixed> */
    private function create(string $tenantId, string $siteId, string $operationId): array {
        return $this->lifecycle->perform(
            'create',
            $tenantId,
            $siteId,
            $operationId,
            self::createInput('preview')
        );
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private function acquire(string $tenantId, string $siteId, string $operationId, array $identity): array {
        return $this->lifecycle->perform(
            'mutation-acquire',
            $tenantId,
            $siteId,
            $operationId,
            self::identityInput($identity) + [
                'mutation_owner' => 'duo-env-materialize-' . $operationId,
            ]
        );
    }

    /** @return array<string,mixed> */
    private static function createInput(string $environment): array {
        return [
            'intent_sha256' => hash('sha256', 'intent:' . $environment),
            'mode' => 'create',
            'target_environment' => $environment,
        ];
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private static function identityInput(array $identity): array {
        return [
            'expected_environment_identity' => $identity['environment_identity'],
            'expected_lease_generation' => $identity['lease_generation'],
            'expected_lease_id' => $identity['lease_id'],
            'expected_ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
            'expected_resource_id' => $identity['resource_id'],
        ];
    }

    /** @param array<string,mixed> $fence @return array<string,mixed> */
    private static function mutationInput(array $fence): array {
        return [
            'expected_mutation_generation' => $fence['mutation_generation'],
            'expected_mutation_id' => $fence['mutation_id'],
            'expected_mutation_owner' => $fence['mutation_owner'],
            'expected_mutation_receipt_sha256' => $fence['mutation_receipt_sha256'],
        ];
    }

    /** @param array<string,mixed> $ttl @return array<string,mixed> */
    private static function ttlComparison(array $ttl, string $operationId): array {
        return [
            'expires_at' => $ttl['expires_at'],
            'generation' => $ttl['ttl_generation'],
            'lease_id' => $ttl['ttl_lease_id'],
            'operation_id' => $operationId,
            'receipt_sha256' => $ttl['ttl_receipt_sha256'],
            'state' => $ttl['ttl_state'],
        ];
    }

    private static function operation(int $number): string {
        return '20260818-' . str_pad((string) $number, 6, '0', STR_PAD_LEFT)
            . '-' . str_pad(dechex($number), 24, '0', STR_PAD_LEFT);
    }

    /** @param array<string,mixed> $target */
    private static function signedCommand(
        string $operationId,
        array $target,
        string $secret,
        string $phase,
        int $index
    ): string {
        $payload = [
            'action' => 'raw',
            'command_index' => $index,
            'command_phase' => $phase,
            'environment' => 'preview',
            'format' => 'duo-cloud-preview-control-request/v1',
            'input' => ['script' => 'printf controlled'],
            'operation_id' => $operationId,
            'request_id' => hash(
                'sha256',
                "duo-cloud-preview-command/v1\0tenant-a\0site-a\0$operationId\0$phase\0$index"
            ),
            'site_id' => 'site-a',
            'target' => $target,
            'tenant_id' => 'tenant-a',
        ];
        return CanonicalJson::encode([
            'format' => 'duo-cloud-preview-signed-envelope/v1',
            'key_id' => 'controller-key-v1',
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                CanonicalJson::encode($payload),
                $secret
            )),
        ]) . "\n";
    }

    private static function waitForFile(string $path): void {
        $deadline = microtime(true) + 5.0;
        while (!is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($path);
    }

    private static function lineCount(string $path): int {
        $lines = is_file($path) ? file($path, FILE_IGNORE_NEW_LINES) : false;
        return is_array($lines) ? count($lines) : 0;
    }

    /** @param callable():mixed $call */
    private function assertRefused(callable $call, string $messageFragment): void {
        try {
            $call();
            self::fail('expected preview-slot lifecycle refusal');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString($messageFragment, $error->getMessage());
        }
    }
}

final class SlotRecordingRuntime implements WorkloadRuntime, RepositorySyncRuntime {
    /** @var list<array{method:string,resource_id:string}> */
    public array $calls = [];
    /** @var array<string,array{execution:bool,present:bool,routing:bool,url:string}> */
    private array $resources = [];
    /** @var array<string,int> */
    private array $failures = [];
    /** @var array<string,int> */
    private array $lostResponses = [];

    public function failNext(string $method): void {
        $this->failures[$method] = ($this->failures[$method] ?? 0) + 1;
    }

    public function loseNextResponse(string $method): void {
        $this->lostResponses[$method] = ($this->lostResponses[$method] ?? 0) + 1;
    }

    public function callCount(string $method): int {
        return count(array_filter(
            $this->calls,
            static fn (array $call): bool => $call['method'] === $method
        ));
    }

    /** @return array{execution:bool,present:bool,routing:bool,url:string} */
    public function resourceState(string $resourceId): array {
        return $this->resource(['resource_id' => $resourceId]);
    }

    public function inspect(array $lease): array {
        $this->record('inspect', $lease);
        $resource = $this->resource($lease);
        return [
            'evidence_sha256' => $this->evidence('inspect', $lease),
            'presence' => $resource['present'] ? 'present' : 'absent',
            'url' => $resource['url'],
        ];
    }

    public function provision(array $lease): array {
        $this->record('provision', $lease);
        $resource = $this->resource($lease);
        $resource['execution'] = true;
        $resource['present'] = true;
        $resource['routing'] = true;
        $this->resources[$lease['resource_id']] = $resource;
        return [
            'evidence_sha256' => $this->evidence('provision', $lease),
            'url' => $resource['url'],
        ];
    }

    public function restoreSnapshot(array $authority, array $snapshot): array {
        $this->record('restoreSnapshot', $authority);
        $this->requirePresent($authority);
        return ['evidence_sha256' => $this->evidence('restoreSnapshot', $authority, $snapshot)];
    }

    public function repositoryAuthorityDescriptor(): array {
        $basis = [
            'credential_helper_sha256' => hash('sha256', 'slot credential helper'),
            'format' => 'duo-cloud-repository-authority/v1',
            'ref_prefix' => 'refs/heads/duo-preview/',
            'remote_url_sha256' => hash('sha256', 'slot remote URL'),
        ];
        return ['descriptor_sha256' => hash(
            'sha256',
            "duo-cloud-repository-authority/v1\0" . CanonicalJson::encode($basis)
        )] + $basis;
    }

    public function syncRepository(array $authority, array $repository): array {
        $this->record('syncRepository', $authority);
        $this->requirePresent($authority);
        return ['evidence_sha256' => $this->evidence('syncRepository', $authority, $repository)];
    }

    public function materializeRepository(array $authority, array $repository): array {
        $this->record('materializeRepository', $authority);
        $this->requirePresent($authority);
        return ['evidence_sha256' => $this->evidence('materializeRepository', $authority, $repository)];
    }

    public function configureUrl(array $authority, string $url): array {
        $this->record('configureUrl', $authority);
        $resource = $this->requirePresent($authority);
        if ($resource['url'] !== $url) {
            throw new \RuntimeException('recording runtime URL mismatch');
        }
        if (!$resource['execution']) {
            throw new \RuntimeException('recording runtime routed without execution');
        }
        $resource['routing'] = true;
        $this->resources[$authority['resource_id']] = $resource;
        $this->afterEffect('configureUrl');
        return ['evidence_sha256' => $this->evidence('configureUrl', $authority, ['url' => $url])];
    }

    public function revokeRouting(array $authority): array {
        $this->record('revokeRouting', $authority);
        $resource = $this->requirePresent($authority);
        $resource['routing'] = false;
        $this->resources[$authority['resource_id']] = $resource;
        $this->afterEffect('revokeRouting');
        return ['evidence_sha256' => $this->evidence('revokeRouting', $authority)];
    }

    public function revokeExecution(array $authority): array {
        $this->record('revokeExecution', $authority);
        $resource = $this->requirePresent($authority);
        if ($resource['routing']) {
            throw new \RuntimeException('recording runtime execution revoked before routing');
        }
        $resource['execution'] = false;
        $this->resources[$authority['resource_id']] = $resource;
        $this->afterEffect('revokeExecution');
        return ['evidence_sha256' => $this->evidence('revokeExecution', $authority)];
    }

    public function resumeExecution(array $authority): array {
        $this->record('resumeExecution', $authority);
        $resource = $this->requirePresent($authority);
        if ($resource['routing']) {
            throw new \RuntimeException('recording runtime resumed while routing remained present');
        }
        $resource['execution'] = true;
        $this->resources[$authority['resource_id']] = $resource;
        $this->afterEffect('resumeExecution');
        return ['evidence_sha256' => $this->evidence('resumeExecution', $authority)];
    }

    public function deleteState(array $authority): array {
        $this->record('deleteState', $authority);
        $resource = $this->requirePresent($authority);
        if ($resource['routing'] || $resource['execution']) {
            throw new \RuntimeException('recording runtime deleted before revocation');
        }
        $resource['present'] = false;
        $this->resources[$authority['resource_id']] = $resource;
        $this->afterEffect('deleteState');
        return ['evidence_sha256' => $this->evidence('deleteState', $authority)];
    }

    public function verifyAbsent(array $lease): array {
        $this->record('verifyAbsent', $lease);
        $resource = $this->resource($lease);
        return [
            'absence_proof_sha256' => $this->evidence('verifyAbsent', $lease, [
                'absent' => !$resource['present'],
            ]),
            'absent' => !$resource['present'],
        ];
    }

    /** @param array<string,mixed> $binding */
    private function record(string $method, array $binding): void {
        $this->calls[] = ['method' => $method, 'resource_id' => (string) $binding['resource_id']];
        $remaining = $this->failures[$method] ?? 0;
        if ($remaining > 0) {
            $this->failures[$method] = $remaining - 1;
            throw new \RuntimeException("injected $method failure");
        }
    }

    private function afterEffect(string $method): void {
        $remaining = $this->lostResponses[$method] ?? 0;
        if ($remaining > 0) {
            $this->lostResponses[$method] = $remaining - 1;
            throw new \RuntimeException("injected lost $method response");
        }
    }

    /** @param array<string,mixed> $binding @return array{execution:bool,present:bool,routing:bool,url:string} */
    private function resource(array $binding): array {
        $resourceId = (string) $binding['resource_id'];
        return $this->resources[$resourceId] ?? [
            'execution' => false,
            'present' => false,
            'routing' => false,
            'url' => 'https://' . substr(hash('sha256', $resourceId), 0, 24) . '.preview.invalid',
        ];
    }

    /** @param array<string,mixed> $binding @return array{execution:bool,present:bool,routing:bool,url:string} */
    private function requirePresent(array $binding): array {
        $resource = $this->resource($binding);
        if (!$resource['present']) {
            throw new \RuntimeException('recording runtime resource is absent');
        }
        return $resource;
    }

    /** @param array<string,mixed> $binding @param array<string,mixed> $detail */
    private function evidence(string $stage, array $binding, array $detail = []): string {
        return hash('sha256', CanonicalJson::encode([
            'binding' => $binding,
            'detail' => $detail,
            'stage' => $stage,
        ]));
    }
}

final class BlockingSlotCommandRunner implements CommandRunner {
    public function __construct(
        private string $started,
        private string $allow,
        private string $calls
    ) {}

    public function run(array $request): array {
        file_put_contents($this->started, "started\n");
        $deadline = microtime(true) + 5.0;
        while (!is_file($this->allow) && microtime(true) < $deadline) {
            usleep(10000);
        }
        if (!is_file($this->allow)) {
            throw new \RuntimeException('blocking command timed out');
        }
        file_put_contents($this->calls, "called\n", FILE_APPEND | LOCK_EX);
        return ['exit' => 0, 'stderr' => '', 'stdout' => 'controlled'];
    }
}

final class OneShotFailureAuthorityPublisher implements MutationAuthorityPublisher {
    private bool $failHold = false;
    private bool $failRelease = false;

    public function failNextHold(): void {
        $this->failHold = true;
    }

    public function failNextRelease(): void {
        $this->failRelease = true;
    }

    public function hold(
        string $tenantId,
        string $siteId,
        string $operationId,
        array $target
    ): void {
        unset($tenantId, $siteId, $operationId, $target);
        if ($this->failHold) {
            $this->failHold = false;
            throw new \RuntimeException('injected authority hold failure');
        }
    }

    public function release(
        string $tenantId,
        string $siteId,
        string $operationId,
        array $target
    ): void {
        unset($tenantId, $siteId, $operationId, $target);
        if ($this->failRelease) {
            $this->failRelease = false;
            throw new \RuntimeException('injected authority release failure');
        }
    }
}

final class SignalingAuthorityPublisher implements MutationAuthorityPublisher {
    public function __construct(
        private MutationAuthorityPublisher $inner,
        private string $releaseAttempted
    ) {}

    public function hold(string $tenantId, string $siteId, string $operationId, array $target): void {
        $this->inner->hold($tenantId, $siteId, $operationId, $target);
    }

    public function release(string $tenantId, string $siteId, string $operationId, array $target): void {
        file_put_contents($this->releaseAttempted, "attempted\n");
        $this->inner->release($tenantId, $siteId, $operationId, $target);
    }
}
