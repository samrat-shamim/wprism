<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\ContainerArgvProcessRunner;
use Duo\Cloud\CanonicalJson;
use Duo\Cloud\ControlRefusal;
use Duo\Cloud\HostAuthorityBusy;
use Duo\Cloud\HostStorageConfig;
use Duo\Cloud\XfsQuotaStorageAuthority;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/runtime/XfsQuotaStorageAuthority.php';
require_once DUO_REPO_ROOT . '/cloud/runtime/HostStorageConfig.php';

#[CoversNothing]
final class XfsQuotaStorageAuthorityTest extends TestCase {
    private string $scratch;
    private string $configuration;
    private string $resourceId;
    private QuotaKernelFake $kernel;
    private XfsQuotaStorageAuthority $authority;

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-xfs-quota-' . bin2hex(random_bytes(8));
        foreach (['', '/encrypted', '/encrypted/docker', '/encrypted/docker/volumes',
                     '/encrypted/repository', '/encrypted/snapshots', '/encrypted/state',
                     '/encrypted/worker'] as $suffix) {
            self::assertTrue(mkdir($this->scratch . $suffix, 0700));
            self::assertTrue(chmod($this->scratch . $suffix, 0700));
        }
        $this->configuration = hash('sha256', 'configuration');
        $this->resourceId = 'cloud-slot-' . hash('sha256', 'resource');
        $this->kernel = new QuotaKernelFake(
            (string) realpath($this->scratch . '/encrypted'),
            (string) realpath($this->scratch . '/encrypted/docker'),
            $this->configuration,
            $this->resourceId
        );
        $this->authority = $this->newAuthority($this->scratch . '/encrypted/worker');
    }

    /**
     * @param list<array{configuration_sha256:string,path:string}>|null $workerRoots
     * @param list<string>|null $configurations
     */
    private function newAuthority(
        string $workerRoot,
        ?string $dockerRoot = null,
        ?array $workerRoots = null,
        ?array $configurations = null
    ): XfsQuotaStorageAuthority {
        return new XfsQuotaStorageAuthority(
            $this->kernel,
            '/bin/echo',
            '/usr/bin/true',
            '/bin/cat',
            '/usr/bin/false',
            '/usr/bin/find',
            '/usr/bin/env',
            $this->scratch,
            $this->scratch . '/encrypted',
            '/dev/mapper/duo_cloud',
            'duo_cloud',
            'CRYPT-LUKS2-0123456789abcdef0123456789abcdef-duo_cloud',
            '01234567-89ab-cdef-0123-456789abcdef',
            '/dev/loop7',
            'aes-xts-plain64',
            'keyring',
            512,
            512,
            32768,
            229376,
            '11111111-2222-3333-4444-555555555555',
            $dockerRoot ?? $this->scratch . '/encrypted/docker',
            [
                $this->scratch . '/encrypted/repository',
                $this->scratch . '/encrypted/snapshots',
                $this->scratch . '/encrypted/state',
            ],
            $workerRoots ?? [[
                'configuration_sha256' => $this->configuration,
                'path' => $workerRoot,
            ]],
            [
                'database_bytes' => 67108864,
                'database_inodes' => 1024,
                'filesystem_bytes' => 134217728,
                'filesystem_inodes' => 2048,
                'worker_bytes' => 268435456,
                'worker_inodes' => 4096,
            ],
            $configurations ?? [$this->configuration]
        );
    }

    protected function tearDown(): void {
        $this->remove($this->scratch);
    }

    public function testBindsReadsBackAndRemovesBothGenerationQuotas(): void {
        $binding = $this->binding();
        $this->kernel->addVolumes($binding);

        $bound = $this->authority->bind($binding);
        self::assertSame('bound', $bound['state']);
        self::assertCount(2, $bound['volumes']);
        self::assertSame(67108864, $bound['volumes'][0]['bytes']);
        self::assertSame(134217728, $bound['volumes'][1]['bytes']);
        self::assertSame('bound', $this->authority->inspect(
            $this->configuration,
            $this->resourceId,
            7
        )['state']);
        self::assertSame('ready', $this->authority->preflight()['state']);
        self::assertCount(3, $this->kernel->projects);

        self::assertSame('absent', $this->authority->unbind(
            $this->configuration,
            $this->resourceId,
            7
        )['state']);
        self::assertCount(1, $this->kernel->projects);
        self::assertSame('absent', $this->authority->inspect(
            $this->configuration,
            $this->resourceId,
            7
        )['state']);
    }

    public function testLiveShapedHostPreflightProofIsJournaledOutsideRuntimeNamespace(): void {
        $proofId = hash('sha256', 'host-preflight-proof');
        $this->kernel->addProofVolumes($proofId);

        $bound = $this->authority->proofBind($this->configuration, $proofId);
        self::assertSame(XfsQuotaStorageAuthority::PROOF_FORMAT, $bound['format']);
        self::assertSame('bound', $bound['state']);
        self::assertSame($proofId, $bound['proof_id']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $bound['receipt_sha256']);
        self::assertSame(67108864, $bound['volumes'][0]['bytes']);
        self::assertSame(1024, $bound['volumes'][0]['inodes']);
        self::assertSame(67108864, $bound['volumes'][1]['bytes']);
        self::assertSame(1024, $bound['volumes'][1]['inodes']);
        self::assertSame(
            $bound,
            $this->authority->proofInspect($this->configuration, $proofId)
        );
        self::assertSame('absent', $this->authority->inspect(
            $this->configuration,
            $this->resourceId,
            7
        )['state']);
        $state = CanonicalJson::decodeObject((string) file_get_contents($this->scratch . '/authority.json'));
        self::assertSame('host-preflight-proof', $state['bindings'][0]['purpose']);

        $absent = $this->authority->proofUnbind($this->configuration, $proofId);
        self::assertSame(XfsQuotaStorageAuthority::PROOF_FORMAT, $absent['format']);
        self::assertSame('absent', $absent['state']);
        self::assertCount(1, $this->kernel->projects);
    }

    public function testHostPreflightProofRefusesMissingOrUnknownOwnershipLabels(): void {
        foreach ([
            'missing' => ['duo.cloud.host-boundary-proof' => null],
            'unknown' => ['duo.cloud.unexpected' => 'present'],
        ] as $case => $labelOverrides) {
            $proofId = hash('sha256', "host-preflight-proof-$case-label");
            $this->kernel->addProofVolumes($proofId, $labelOverrides);

            try {
                $this->authority->proofBind($this->configuration, $proofId);
                self::fail("$case proof volume label should refuse");
            } catch (ControlRefusal $error) {
                self::assertSame(
                    'storage cannot prove exact Docker volume ownership and mountpoint',
                    $error->getMessage()
                );
            }
        }
    }

    public function testFreshProofUnbindInitializesExactWorkerQuotaAndReturnsAbsent(): void {
        $proofId = hash('sha256', 'fresh-host-preflight-proof');
        $workerRoot = (string) realpath($this->scratch . '/encrypted/worker');
        $workerProject = 1 + (hexdec(substr(hash(
            'sha256',
            "duo-cloud-xfs-worker-project/v3\0$workerRoot"
        ), 0, 8)) % 1073741823);

        self::assertSame([], $this->kernel->projects);
        self::assertSame([
            'configuration_sha256' => $this->configuration,
            'format' => XfsQuotaStorageAuthority::PROOF_FORMAT,
            'proof_id' => $proofId,
            'state' => 'absent',
        ], $this->authority->proofUnbind($this->configuration, $proofId));
        self::assertSame([
            $workerProject => [
                'bytes' => 262144,
                'inodes' => 4096,
                'mountpoint' => $workerRoot,
            ],
        ], $this->kernel->projects);
        $state = CanonicalJson::decodeObject((string) file_get_contents($this->scratch . '/authority.json'));
        self::assertSame([], $state['bindings']);
        self::assertSame([], $state['cleanup']);
        self::assertSame('stable', $state['phase']);
    }

    public function testInterruptedProofPublishReplaysExactJournalAndCleansUp(): void {
        $proofId = hash('sha256', 'interrupted-host-preflight-proof');
        $this->kernel->addProofVolumes($proofId);
        $this->kernel->failProofDescendantReadbackOnce = true;

        try {
            $this->authority->proofBind($this->configuration, $proofId);
            self::fail('interrupted proof publish should refuse');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('descendant membership differs', $error->getMessage());
        }
        $applying = CanonicalJson::decodeObject((string) file_get_contents($this->scratch . '/authority.json'));
        self::assertSame('applying', $applying['phase']);
        self::assertSame('host-preflight-proof', $applying['bindings'][0]['purpose']);

        $bound = $this->authority->proofBind($this->configuration, $proofId);
        self::assertSame('bound', $bound['state']);
        self::assertSame(
            $bound['receipt_sha256'],
            $this->authority->proofInspect($this->configuration, $proofId)['receipt_sha256']
        );
        self::assertSame(
            'absent',
            $this->authority->proofUnbind($this->configuration, $proofId)['state']
        );
        self::assertCount(1, $this->kernel->projects);
    }

    public function testKilledStatePublicationLeavesOneDestinationBoundResidueAndRetriesExactly(): void {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')
            || !function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and POSIX signals are required for the storage crash regression');
        }
        $binding = $this->binding();
        $this->kernel->addVolumes($binding);
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            putenv('DUO_TEST_XFS_STORAGE_KILL_PHASE=state-temporary-synchronized');
            $this->authority->bind($binding);
            exit(71);
        }
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifsignaled($status));
        self::assertSame(SIGKILL, pcntl_wtermsig($status));
        $temporary = $this->scratch . '/authority.json.tmp';
        self::assertFileExists($temporary);
        self::assertCount(1, glob($temporary . '*') ?: []);

        self::assertSame('bound', $this->authority->bind($binding)['state']);
        self::assertSame([], glob($temporary . '*') ?: []);
        self::assertSame('bound', $this->authority->inspect(
            $this->configuration,
            $this->resourceId,
            7
        )['state']);
        self::assertCount(3, $this->kernel->projects);
    }

    public function testUnsafeDeterministicStateResiduesRefuseWithoutFollowingOrUnlinkingThem(): void {
        self::assertSame('ready', $this->authority->preflight([], $this->configuration)['state']);
        $outside = $this->scratch . '/outside';
        self::assertSame(8, file_put_contents($outside, "outside\n"));
        self::assertTrue(chmod($outside, 0600));
        $temporary = $this->scratch . '/authority.json.tmp';
        self::assertTrue(symlink($outside, $temporary));
        try {
            $this->authority->inspect($this->configuration, $this->resourceId, 7);
            self::fail('symlinked storage state residue was accepted');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('must be a process-owned', $error->getMessage());
        }
        self::assertTrue(is_link($temporary));
        self::assertSame("outside\n", file_get_contents($outside));
        self::assertTrue(unlink($temporary));

        self::assertTrue(link($outside, $temporary));
        try {
            $this->authority->inspect($this->configuration, $this->resourceId, 7);
            self::fail('multiply-linked storage state residue was accepted');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('single-link', $error->getMessage());
        }
        self::assertSame("outside\n", file_get_contents($outside));
        self::assertFileExists($temporary);
        self::assertTrue(unlink($temporary));
    }

    public function testConcurrentHostProofRefusesWithoutWaitingForAuthorityLock(): void {
        $this->authority->preflight([], $this->configuration);
        $handle = fopen($this->scratch . '/authority.lock', 'c+b');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        $started = hrtime(true);
        try {
            $this->authority->proofInspect(
                $this->configuration,
                hash('sha256', 'concurrent-host-preflight-proof')
            );
            self::fail('concurrent proof should refuse');
        } catch (HostAuthorityBusy $error) {
            self::assertSame('storage authority lock is busy', $error->getMessage());
            self::assertLessThan(250000000, hrtime(true) - $started);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testUnsafeStorageLockPathIsPermanentNotTransient(): void {
        self::assertSame(
            'ready',
            $this->authority->preflight([], $this->configuration)['state']
        );
        self::assertTrue(mkdir($this->scratch . '/authority.lock', 0700));

        try {
            $this->authority->proofInspect(
                $this->configuration,
                hash('sha256', 'unsafe-storage-lock-path')
            );
            self::fail('directory at the storage lock path was accepted');
        } catch (ControlRefusal $error) {
            self::assertSame(
                'storage authority lock could not be acquired privately',
                $error->getMessage()
            );
        }
    }

    public function testChangedProjectQuotaRefusesBeforeAnyNewPublish(): void {
        $binding = $this->binding();
        $this->kernel->addVolumes($binding);
        $bound = $this->authority->bind($binding);
        $this->kernel->projects[$bound['volumes'][0]['project_id']]['bytes']++;

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('quota differs');
        $this->authority->preflight();
    }

    public function testWorkerRootGetsIndependentHardQuotaAndDriftRefusesOperations(): void {
        $ready = $this->authority->preflight([], $this->configuration);
        self::assertSame(268435456, $ready['worker_bytes']);
        self::assertSame(4096, $ready['worker_inodes']);
        $workerProject = $ready['worker_project_id'];
        self::assertSame(
            realpath($this->scratch . '/encrypted/worker'),
            $this->kernel->projects[$workerProject]['mountpoint']
        );
        self::assertSame(262144, $this->kernel->projects[$workerProject]['bytes']);
        $this->kernel->projects[$workerProject]['bytes']++;

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('quota differs');
        $this->authority->inspect($this->configuration, $this->resourceId, 7);
    }

    public function testMissingWorkerRootDoesNotInvalidateGlobalEncryptedStorageReadback(): void {
        self::assertTrue(rmdir($this->scratch . '/encrypted/worker'));
        $host = $this->authority->fleetPreflight();
        self::assertSame('duo-cloud-xfs-quota-fleet-preflight/v1', $host['format']);
        self::assertSame('ready', $host['state']);
        try {
            $this->authority->preflight([], $this->configuration);
            self::fail('missing selected worker root passed worker-local storage preflight');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('selected storage worker root', $error->getMessage());
        }
    }

    public function testCurrentAndRetiringConfigurationsShareOneWorkerQuotaIdentity(): void {
        $retiring = hash('sha256', 'retiring-configuration');
        $configurations = [$this->configuration, $retiring];
        sort($configurations, SORT_STRING);
        $workerRoot = (string) realpath($this->scratch . '/encrypted/worker');
        $workerRoots = array_map(static fn (string $configuration): array => [
            'configuration_sha256' => $configuration,
            'path' => $workerRoot,
        ], $configurations);
        $authority = $this->newAuthority(
            $workerRoot,
            null,
            $workerRoots,
            $configurations
        );

        self::assertCount(2, $authority->fleetPreflight()['workers']);
        $current = $authority->preflight([], $this->configuration);
        $old = $authority->preflight([], $retiring);
        self::assertSame($current['worker_project_id'], $old['worker_project_id']);
        self::assertSame($current['worker_root_sha256'], $old['worker_root_sha256']);
    }

    public function testWorkerAndFleetPreflightsIgnorePeerFailureAndGlobalWriterLock(): void {
        $configurationB = hash('sha256', 'configuration-b');
        $configurations = [$this->configuration, $configurationB];
        sort($configurations, SORT_STRING);
        $workerA = (string) realpath($this->scratch . '/encrypted/worker');
        $workerB = $this->scratch . '/encrypted/worker-b';
        self::assertTrue(mkdir($workerB, 0700));
        $workerRoots = [];
        foreach ($configurations as $configuration) {
            $workerRoots[] = [
                'configuration_sha256' => $configuration,
                'path' => $configuration === $this->configuration ? $workerA : $workerB,
            ];
        }
        $authority = $this->newAuthority($workerA, null, $workerRoots, $configurations);
        $resource = 'cloud-slot-' . hash('sha256', 'worker-b-slot');
        $binding = [
            'configuration_sha256' => $configurationB,
            'database_volume' => 'duo-preview-db-' . substr($resource, 11) . '-g0000000001',
            'filesystem_volume' => 'duo-preview-fs-' . substr($resource, 11) . '-g0000000001',
            'lease_generation' => 1,
            'resource_id' => $resource,
        ];
        $this->kernel->addVolumes($binding);
        $authority->bind($binding);
        self::assertTrue(rmdir($workerB));
        $handle = fopen($this->scratch . '/authority.lock', 'c+b');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        try {
            self::assertSame('ready', $authority->fleetPreflight()['state']);
            self::assertSame('ready', $authority->preflight([], $this->configuration)['state']);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
        try {
            $authority->preflight([], $configurationB);
            self::fail('missing worker B root passed its own storage preflight');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('selected storage worker root', $error->getMessage());
        }
    }

    public function testWorkerProjectSpaceIsDisjointAndUniqueAtClosedFleetScale(): void {
        $workerProjects = [];
        for ($index = 0; $index < 32; $index++) {
            $configuration = hash('sha256', 'worker-scale-' . $index);
            $path = (string) realpath($this->scratch . '/encrypted') . '/worker-' . $index;
            $project = 1 + (hexdec(substr(hash(
                'sha256',
                "duo-cloud-xfs-worker-project/v3\0$path"
            ), 0, 8)) % 1073741823);
            self::assertGreaterThanOrEqual(1, $project);
            self::assertLessThan(1073741824, $project);
            self::assertArrayNotHasKey($project, $workerProjects);
            $workerProjects[$project] = true;
        }
        self::assertCount(32, $workerProjects);

        $volumeProjects = [];
        for ($index = 0; $index < 64; $index++) {
            $configuration = hash('sha256', 'volume-scale-' . $index);
            $name = 'duo-preview-db-' . hash('sha256', 'resource-' . $index) . '-g0000000001';
            $project = 1073741824 + (hexdec(substr(hash(
                'sha256',
                "duo-cloud-xfs-project/v2\0$configuration\0$name"
            ), 0, 8)) % 1073741824);
            self::assertGreaterThanOrEqual(1073741824, $project);
            self::assertLessThanOrEqual(2147483647, $project);
            self::assertArrayNotHasKey($project, $volumeProjects);
            self::assertArrayNotHasKey($project, $workerProjects);
            $volumeProjects[$project] = true;
        }
    }

    public function testWorkerRootNestedInsideDockerRootRefusesBeforeXfsEffects(): void {
        $worker = $this->scratch . '/encrypted/docker/volumes/worker-bad';
        self::assertTrue(mkdir($worker, 0700));

        try {
            $this->newAuthority($worker);
            self::fail('worker root inside Docker root should refuse');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('worker root is absent, unsafe', $error->getMessage());
        }
        self::assertSame([], $this->kernel->projects);
    }

    public function testDockerRootNestedInsideWorkerRootRefusesBeforeXfsEffects(): void {
        $worker = $this->scratch . '/encrypted/shared';
        $docker = $worker . '/docker';
        self::assertTrue(mkdir($docker, 0700, true));

        try {
            $this->newAuthority($worker, $docker);
            self::fail('Docker root inside worker root should refuse');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('worker root is absent, unsafe', $error->getMessage());
        }
        self::assertSame([], $this->kernel->projects);
    }

    public function testClearedProjectInheritanceRefusesReadback(): void {
        $binding = $this->binding();
        $this->kernel->addVolumes($binding);
        $this->authority->bind($binding);
        $this->kernel->clearedProjectInheritance = true;

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('inheritance flag differs');
        $this->authority->preflight();
    }

    public function testDescendantProjectDriftRefusesReadback(): void {
        $binding = $this->binding();
        $this->kernel->addVolumes($binding);
        $this->authority->bind($binding);
        $this->kernel->descendantProjectDrift = true;

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('descendant membership differs');
        $this->authority->preflight();
    }

    public function testEncryptedMountIdentityIsRequiredForGlobalDockerSurface(): void {
        $this->kernel->wrongFilesystemUuid = true;

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('exact encrypted');
        $this->authority->preflight();
    }

    public function testLuks2CryptTargetParametersCannotBeForgedByMapperUuid(): void {
        $this->kernel->wrongCipher = true;

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('mapping parameters differ');
        $this->authority->preflight();
    }

    public function testClosedPinnedHostConfigurationBindsAllDurableAndExecutableAuthorities(): void {
        $binary = $this->scratch . '/host-tool';
        self::assertNotFalse(file_put_contents(
            $binary,
            "#!/bin/sh\n" . str_repeat('#', 1048576) . "\nexit 0\n"
        ));
        self::assertTrue(chmod($binary, 0700));
        $descriptor = ['path' => $binary, 'sha256' => (string) hash_file('sha256', $binary)];
        $durable = [
            $this->scratch . '/encrypted/repository',
            $this->scratch . '/encrypted/snapshots',
            $this->scratch . '/encrypted/state',
        ];
        sort($durable, SORT_STRING);
        $document = [
            'backing_device' => '/dev/loop7',
            'cipher' => 'aes-xts-plain64',
            'configuration_sha256s' => [$this->configuration],
            'container_engine' => $descriptor,
            'cryptsetup' => $descriptor,
            'dmsetup' => $descriptor,
            'docker_root' => $this->scratch . '/encrypted/docker',
            'durable_paths' => $durable,
            'filesystem_uuid' => '11111111-2222-3333-4444-555555555555',
            'findmnt' => $descriptor,
            'format' => HostStorageConfig::FORMAT,
            'key_location' => 'keyring',
            'key_size_bits' => 512,
            'limits' => [
                'database_bytes' => 67108864,
                'database_inodes' => 1024,
                'filesystem_bytes' => 134217728,
                'filesystem_inodes' => 2048,
                'worker_bytes' => 268435456,
                'worker_inodes' => 4096,
            ],
            'mapper_name' => 'duo_cloud',
            'mapper_path' => '/dev/mapper/duo_cloud',
            'mapper_uuid' => 'CRYPT-LUKS2-0123456789abcdef0123456789abcdef-duo_cloud',
            'mapper_size_sectors' => 229376,
            'mountpoint' => $this->scratch . '/encrypted',
            'luks_uuid' => '01234567-89ab-cdef-0123-456789abcdef',
            'process_timeout_seconds' => 5,
            'payload_offset_sectors' => 32768,
            'sector_size_bytes' => 512,
            'service_uids' => [10001],
            'state_root' => $this->scratch,
            'worker_roots' => [[
                'configuration_sha256' => $this->configuration,
                'path' => $this->scratch . '/encrypted/worker',
            ]],
            'xfs_io' => $descriptor,
            'xfs_quota' => $descriptor,
        ];
        $bytes = CanonicalJson::encode($document) . "\n";
        $path = $this->scratch . '/host-storage.json';
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        self::assertTrue(chmod($path, 0600));
        $config = HostStorageConfig::load($path, hash('sha256', $bytes));
        self::assertSame('/dev/mapper/duo_cloud', $config->string('mapper_path'));
        self::assertSame([10001], $config->serviceUids());
        self::assertSame(134217728, $config->limits()['filesystem_bytes']);

        self::assertSame(strlen($bytes) + 1, file_put_contents($path, rtrim($bytes) . " \n"));
        $this->expectException(ControlRefusal::class);
        HostStorageConfig::load($path, hash('sha256', $bytes));
    }

    /** @return array<string,mixed> */
    private function binding(): array {
        $token = substr($this->resourceId, strlen('cloud-slot-'));
        return [
            'configuration_sha256' => $this->configuration,
            'database_volume' => 'duo-preview-db-' . $token . '-g0000000007',
            'filesystem_volume' => 'duo-preview-fs-' . $token . '-g0000000007',
            'lease_generation' => 7,
            'resource_id' => $this->resourceId,
        ];
    }

    private function remove(string $path): void {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}

final class QuotaKernelFake implements ContainerArgvProcessRunner {
    /** @var array<int,array{bytes:int,inodes:int,mountpoint:string}> */
    public array $projects = [];
    public bool $wrongFilesystemUuid = false;
    public bool $wrongCipher = false;
    public bool $clearedProjectInheritance = false;
    public bool $descendantProjectDrift = false;
    public bool $failProofDescendantReadbackOnce = false;
    /** @var array<string,array<string,mixed>> */
    private array $volumes = [];

    public function __construct(
        private string $mountpoint,
        private string $dockerRoot,
        private string $configuration,
        private string $resourceId
    ) {}

    /** @param array<string,mixed> $binding */
    public function addVolumes(array $binding): void {
        foreach (['database' => 'database_volume', 'filesystem' => 'filesystem_volume'] as $kind => $field) {
            $name = $binding[$field];
            $path = $this->dockerRoot . '/volumes/' . $name . '/_data';
            self::create($path);
            $this->volumes[$name] = [
                'Driver' => 'local',
                'Labels' => [
                    'duo.cloud.configuration-sha256' => $binding['configuration_sha256'],
                    'duo.cloud.data-kind' => $kind,
                    'duo.cloud.lease-generation' => (string) $binding['lease_generation'],
                    'duo.cloud.resource-id' => $binding['resource_id'],
                ],
                'Mountpoint' => $path,
                'Name' => $name,
            ];
        }
    }

    /** @param array<string,string|null> $labelOverrides */
    public function addProofVolumes(string $proofId, array $labelOverrides = []): void {
        foreach (['database' => 'db', 'filesystem' => 'fs'] as $kind => $short) {
            $name = 'duo-proof-' . $short . '-' . $proofId;
            $path = $this->dockerRoot . '/volumes/' . $name . '/_data';
            self::create($path);
            $labels = [
                'duo.cloud.configuration-sha256' => $this->configuration,
                'duo.cloud.data-kind' => $kind,
                'duo.cloud.host-boundary-proof' => $this->configuration,
                'duo.cloud.proof-id' => $proofId,
                'duo.cloud.storage-purpose' => 'host-preflight-proof',
            ];
            foreach ($labelOverrides as $label => $value) {
                if ($value === null) {
                    unset($labels[$label]);
                } else {
                    $labels[$label] = $value;
                }
            }
            $this->volumes[$name] = [
                'Driver' => 'local',
                'Labels' => $labels,
                'Mountpoint' => $path,
                'Name' => $name,
            ];
        }
    }

    public function run(
        array $argv,
        ?string $stdinFile = null,
        ?int $timeoutSeconds = null
    ): array {
        if ($argv[0] === '/bin/echo') {
            $name = $argv[count($argv) - 1];
            return isset($this->volumes[$name])
                ? self::result(0, json_encode($this->volumes[$name], JSON_THROW_ON_ERROR) . "\n")
                : self::result(1, '', 'missing volume');
        }
        if ($argv[0] === '/usr/bin/false') {
            return self::result(0, json_encode(['filesystems' => [[
                'fstype' => 'xfs',
                'options' => 'rw,relatime,attr2,inode64,prjquota',
                'source' => '/dev/mapper/duo_cloud',
                'target' => $this->mountpoint,
                'uuid' => $this->wrongFilesystemUuid
                    ? 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'
                    : '11111111-2222-3333-4444-555555555555',
            ]]], JSON_THROW_ON_ERROR) . "\n");
        }
        if ($argv[0] === '/usr/bin/true') {
            return self::result(
                0,
                "duo_cloud|CRYPT-LUKS2-0123456789abcdef0123456789abcdef-duo_cloud|252|0\n"
            );
        }
        if ($argv[0] === '/bin/cat') {
            if (($argv[1] ?? null) === 'status' && ($argv[2] ?? null) === 'duo_cloud') {
                $cipher = $this->wrongCipher ? 'null' : 'aes-xts-plain64';
                return self::result(0, str_replace('__CIPHER__', $cipher, <<<'STATUS'
/dev/mapper/duo_cloud is active.
  type:    LUKS2
  cipher:  __CIPHER__
  keysize: 512 bits
  key location: keyring
  device:  /dev/loop7
  sector size:  512
  offset:  32768 sectors
  size:    229376 sectors
  mode:    read/write
STATUS
                ) . "\n");
            }
            if (($argv[1] ?? null) === 'luksUUID'
                && array_slice($argv, 2) === ['--type', 'luks2', '/dev/loop7']) {
                return self::result(0, "01234567-89ab-cdef-0123-456789abcdef\n");
            }
            return self::result(2, '', 'unexpected cryptsetup fake argv');
        }
        if ($argv[0] === '/usr/bin/find') {
            $path = $argv[count($argv) - 1];
            foreach ($this->projects as $id => $project) {
                if ($project['mountpoint'] === $path) {
                    $flags = $this->clearedProjectInheritance ? '0x00000000' : '0x00000200';
                    return self::result(0, "fsxattr.xflags = $flags [P]\nfsxattr.projid = $id\n");
                }
            }
            return self::result(0, "fsxattr.projid = 0\n");
        }
        if ($argv[0] === '/usr/bin/env') {
            $command = $argv[3] ?? '';
            if (str_starts_with($command, 'project -s -p ')) {
                preg_match('/\Aproject -s -p (\S+) ([0-9]+)\z/D', $command, $match);
                $this->projects[(int) $match[2]] = [
                    'bytes' => 0,
                    'inodes' => 0,
                    'mountpoint' => $match[1],
                ];
                return self::result(0);
            }
            if (str_starts_with($command, 'limit -p bsoft=0 ')) {
                preg_match('/ ([0-9]+)\z/D', $command, $match);
                unset($this->projects[(int) $match[1]]);
                return self::result(0);
            }
            if (str_starts_with($command, 'project -C -p ')) {
                return self::result(0);
            }
            if (str_starts_with($command, 'project -c -p ')) {
                preg_match('/\Aproject -c -p (\S+) ([0-9]+)\z/D', $command, $match);
                $output = 'Checking project ' . $match[2] . ' (path ' . $match[1]
                    . ")...\nProcessed 1 (/etc/projects and cmdline) paths for project "
                    . $match[2] . " with recursion depth infinite (-1).\n";
                if ($this->descendantProjectDrift) {
                    $output .= "project inheritance mismatch below configured path\n";
                }
                if ($this->failProofDescendantReadbackOnce
                    && str_contains($match[1], '/duo-proof-')) {
                    $this->failProofDescendantReadbackOnce = false;
                    $output .= "interrupted proof readback\n";
                }
                return self::result(0, $output);
            }
            if (str_starts_with($command, 'limit -p bsoft=')) {
                preg_match('/bsoft=([0-9]+)k bhard=[0-9]+k isoft=([0-9]+) ihard=[0-9]+ ([0-9]+)\z/D', $command, $match);
                $id = (int) $match[3];
                $this->projects[$id]['bytes'] = (int) $match[1];
                $this->projects[$id]['inodes'] = (int) $match[2];
                return self::result(0);
            }
            if ($command === 'report -p -n -N -b -i') {
                $lines = [];
                foreach ($this->projects as $id => $project) {
                    $lines[] = '#' . $id . ' 0 ' . $project['bytes'] . ' ' . $project['bytes']
                        . ' 00 [--------] 0 ' . $project['inodes'] . ' ' . $project['inodes']
                        . ' 00 [--------]';
                }
                return self::result(0, implode("\n", $lines) . "\n");
            }
        }
        return self::result(2, '', 'unexpected quota fake argv');
    }

    private static function create(string $path): void {
        if (!is_dir($path)) {
            mkdir($path, 0700, true);
        }
    }

    /** @return array{exit:int,stderr:string,stdout:string} */
    private static function result(int $exit, string $stdout = '', string $stderr = ''): array {
        return ['exit' => $exit, 'stderr' => $stderr, 'stdout' => $stdout];
    }
}
