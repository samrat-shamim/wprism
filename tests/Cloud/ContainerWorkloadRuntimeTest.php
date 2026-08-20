<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\CommandRunner;
use Duo\Cloud\ContainerArgvProcessRunner;
use Duo\Cloud\ContainerWorkloadRuntime;
use Duo\Cloud\ControlAuthority;
use Duo\Cloud\ControlAuthorityMutationPublisher;
use Duo\Cloud\ControlRefusal;
use Duo\Cloud\FileAuthorityStore;
use Duo\Cloud\NativeContainerArgvProcessRunner;
use Duo\Cloud\PreviewSlotLifecycle;
use Duo\Cloud\ProductionConfig;
use Duo\Cloud\ProductionFleetConfig;
use Duo\Cloud\ProductionFleetReaper;
use Duo\Cloud\ReviewedPreviewBaseProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2) . '/cloud/src/ContainerWorkloadRuntime.php';
require_once dirname(__DIR__, 2) . '/cloud/runtime/ProductionFleetReaper.php';

/** @internal */
final class ContainerRuntimeRefusingCommandRunner implements CommandRunner {
    public int $calls = 0;

    public function run(array $request): array {
        $this->calls++;
        throw new ControlRefusal('test janitor command runner is unreachable');
    }
}

/** @internal Preserves offline credential ownership while exercising native argv artifacts. */
final class ContainerRuntimeDelegatingProcessRunner implements ContainerArgvProcessRunner {
    public function __construct(private ContainerArgvProcessRunner $runner) {}

    public function run(
        array $argv,
        ?string $stdinFile = null,
        ?int $timeoutSeconds = null
    ): array {
        return $this->runner->run($argv, $stdinFile, $timeoutSeconds);
    }
}

/**
 * Docker itself is intentionally not a unit-test prerequisite. This fake is a
 * deterministic model of Docker's inspected state and the route-helper wire
 * contract, so every security-sensitive argv and every read-back proof remains
 * asserted on developer machines that have neither a daemon nor root access.
 */
final class ContainerWorkloadRuntimeTest extends TestCase {
    private const CONFIG = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const PLATFORM = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    private const REVIEW = 'dddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddddd';
    private const IMAGE = 'registry.example.test/duo/wordpress@sha256:'
        . 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const REMOTE_NAME = 'duo-origin';
    public const REMOTE_URL = 'https://git.example.test/duo/site.git';
    private const REF_PREFIX = 'refs/heads/duo-preview/';
    private const TEST_CREDENTIAL_SECRET = 'duo-private-test-secret';
    private const TEST_CREDENTIAL_HELPER = "#!/bin/sh\nprintf 'password=duo-private-test-secret\\n'\n";

    private string $root;
    private string $repository;
    private string $snapshots;
    private string $credentialHelper;
    private ContainerRuntimeFakeRunner $runner;
    private ContainerWorkloadRuntime $runtime;
    private int $entropyCall = 0;

    protected function setUp(): void {
        $this->root = sys_get_temp_dir() . '/duo-container-runtime-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
        self::assertTrue(chmod($this->root, 0700));
        $this->repository = $this->root . '/repository';
        $this->snapshots = $this->root . '/snapshots';
        self::assertTrue(mkdir($this->repository, 0700));
        self::assertTrue(mkdir($this->repository . '/.git', 0700));
        self::assertTrue(mkdir($this->snapshots, 0700));
        $this->credentialHelper = $this->root . '/git-credential-duo';
        self::assertSame(
            strlen(self::TEST_CREDENTIAL_HELPER),
            file_put_contents($this->credentialHelper, self::TEST_CREDENTIAL_HELPER)
        );
        self::assertTrue(chmod($this->credentialHelper, 0700));
        $this->runner = new ContainerRuntimeFakeRunner(
            self::CONFIG,
            self::IMAGE,
            $this->repository
        );
        $this->runtime = $this->newRuntime($this->runner);
    }

    protected function tearDown(): void {
        self::removeTree($this->root . '-host-preflight');
        self::removeTree($this->root);
    }

    public function testExactIsolatedCreateMaterializeRouteAndOrderedReapOmitSecrets(): void {
        self::assertInstanceOf(ReviewedPreviewBaseProvider::class, $this->runtime);
        $reviewedBase = $this->runtime->reviewedBaseDescriptor();
        self::assertSame(
            ['format', 'image_digest', 'platform_fingerprint_sha256', 'review_receipt_sha256'],
            array_keys($reviewedBase)
        );
        self::assertSame('duo-reviewed-preview-base/v1', $reviewedBase['format']);
        self::assertSame(substr(self::IMAGE, (int) strrpos(self::IMAGE, '@') + 1), $reviewedBase['image_digest']);
        self::assertSame(self::PLATFORM, $reviewedBase['platform_fingerprint_sha256']);
        self::assertSame(self::REVIEW, $reviewedBase['review_receipt_sha256']);
        $containment = $this->runtime->reviewedBaseContainmentDescriptor();
        self::assertSame('duo-reviewed-preview-base-containment/v1', $containment['format']);
        self::assertSame($reviewedBase, $containment['reviewed_base']);
        self::assertSame(
            'generation-private-files-readonly-mount-readback/v1',
            $containment['secrets_evidence']
        );
        self::assertSame(
            'host-nft-input-forward-default-deny-readback/v1',
            $containment['egress_evidence']
        );
        self::assertSame(
            'credential-free-route-authority-readback/v1',
            $containment['routing_evidence']
        );
        self::assertSame(
            'dm-crypt-xfs-project-quota-exact-readback/v1',
            $containment['storage_evidence']
        );
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $containment['descriptor_sha256']);
        $lease = self::lease('tenant-a', 'site-a', 1, self::operation(1));
        $absence = $this->runtime->verifyAbsent($lease);
        self::assertTrue($absence['absent']);

        $provisioned = $this->runtime->provision($lease);
        self::assertMatchesRegularExpression(
            '#^https://p-[a-f0-9]{40}\.preview\.example\.test$#D',
            $provisioned['url']
        );
        $create = $this->runner->firstCall('container', 'create');
        self::assertNotNull($create);
        $token = substr($lease['resource_id'], strlen('cloud-slot-'));
        self::assertSame(
            'duo-preview-' . $token . '-g0000000001',
            ContainerRuntimeFakeRunner::optionValue($create['argv'], '--name')
        );
        self::assertContains('--read-only', $create['argv']);
        self::assertContains('ALL', $create['argv']);
        self::assertContains('no-new-privileges=true', $create['argv']);
        self::assertContains('--pids-limit', $create['argv']);
        self::assertContains('--memory', $create['argv']);
        self::assertContains('--cpus', $create['argv']);
        self::assertSame(
            '192.0.2.53',
            ContainerRuntimeFakeRunner::optionValue($create['argv'], '--dns')
        );
        self::assertSame(['timeout:1', 'attempts:1'], ContainerRuntimeFakeRunner::options(
            $create['argv'],
            '--dns-opt'
        ));
        self::assertSame(
            '.',
            ContainerRuntimeFakeRunner::optionValue($create['argv'], '--dns-search')
        );
        self::assertContains(self::IMAGE, $create['argv']);
        self::assertContains('--clean-base', $create['argv']);
        self::assertContains(
            '/run:rw,noexec,nosuid,nodev,size=16777216,mode=0700,uid=10001,gid=10001',
            $create['argv']
        );
        self::assertContains(
            '/tmp:rw,noexec,nosuid,nodev,size=67108864,mode=0700,uid=10001,gid=10001',
            $create['argv']
        );
        self::assertNotContains('--privileged', $create['argv']);
        self::assertStringNotContainsString('docker.sock', implode("\0", $create['argv']));

        $networkCreate = $this->runner->firstCall('network', 'create');
        self::assertNotNull($networkCreate);
        self::assertContains('--internal', $networkCreate['argv']);
        self::assertContains('com.docker.network.bridge.enable_ip_masquerade=false', $networkCreate['argv']);
        self::assertContains('com.docker.network.bridge.enable_icc=false', $networkCreate['argv']);
        self::assertContains('--ipv6=false', $networkCreate['argv']);
        self::assertContains(
            'com.docker.network.bridge.name=duo' . substr(
                hash('sha256', 'duo-preview-net-' . $token . '-g0000000001'),
                0,
                12
            ),
            $networkCreate['argv']
        );
        self::assertNotNull($this->runner->firstCall('bind', '--config-sha256'));
        self::assertSame(
            'duo-preview-net-' . $token . '-g0000000001',
            $networkCreate['argv'][count($networkCreate['argv']) - 1]
        );
        $volumeCreates = $this->runner->callsMatching('volume', 'create');
        self::assertCount(2, $volumeCreates);
        self::assertSame(
            [
                'duo-preview-db-' . $token . '-g0000000001',
                'duo-preview-fs-' . $token . '-g0000000001',
            ],
            array_map(
                static fn (array $call): string => $call['argv'][count($call['argv']) - 1],
                $volumeCreates
            )
        );

        $authority = self::authority($lease, 1, self::operation(1));
        $commit = str_repeat('c', 40);
        $repositoryAuthority = $this->runtime->repositoryAuthorityDescriptor();
        $operationRef = self::REF_PREFIX . self::operation(1);
        $this->runner->remoteRefs[$operationRef] = $commit;
        $sync = $this->runtime->syncRepository($authority, [
            'branch_commit' => $commit,
            'branch_ref' => $operationRef,
            'candidate_publication_receipt_sha256' => hash('sha256', 'publication'),
            'repository_authority_sha256' => $repositoryAuthority['descriptor_sha256'],
        ]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $sync['evidence_sha256']);
        $fetches = self::callsContaining($this->runner->calls, 'fetch');
        self::assertCount(1, $fetches);
        $remoteReadback = self::callsContaining($this->runner->calls, 'ls-remote');
        self::assertCount(1, $remoteReadback);
        self::assertSame(
            ['/usr/bin/git', '-C', realpath($this->repository)],
            array_slice($fetches[0]['argv'], 0, 3),
            'repository sync must invoke Git once before the pinned repository path'
        );
        $approvedCredentialHelper = realpath($this->credentialHelper);
        self::assertIsString($approvedCredentialHelper);
        foreach ([
            'credential.helper=',
            'credential.' . self::REMOTE_URL . '.helper=' . $approvedCredentialHelper,
            'credential.interactive=never',
            'credential.useHttpPath=true',
        ] as $configuration) {
            self::assertContains($configuration, $remoteReadback[0]['argv']);
            self::assertContains($configuration, $fetches[0]['argv']);
        }
        $recordedCalls = CanonicalJson::encode($this->runner->calls);
        self::assertStringNotContainsString(self::TEST_CREDENTIAL_SECRET, $recordedCalls);
        self::assertStringNotContainsString('password=', $recordedCalls);
        self::assertSame($sync, $this->runtime->syncRepository($authority, [
            'branch_commit' => $commit,
            'branch_ref' => $operationRef,
            'candidate_publication_receipt_sha256' => hash('sha256', 'publication'),
            'repository_authority_sha256' => $repositoryAuthority['descriptor_sha256'],
        ]));
        self::assertCount(1, self::callsContaining($this->runner->calls, 'fetch'));
        $repository = $this->runtime->materializeRepository($authority, [
            'branch_commit' => $commit,
            'branch_ref' => $operationRef,
            'repo_path' => '/srv/duo/repository',
        ]);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $repository['evidence_sha256']);
        $archive = $this->runner->firstGitArchive();
        self::assertNotNull($archive);
        self::assertSame(realpath($this->repository), $archive['argv'][2]);
        self::assertNotContains('--remote', $archive['argv']);
        self::assertNotContains('origin', $archive['argv']);
        $materialize = $this->runner->firstExecStage('materialize-repository');
        self::assertNotNull($materialize);
        self::assertNotNull($materialize['stdin_file']);
        self::assertStringStartsWith(realpath($this->root) . '/state/slots/', $materialize['stdin_file']);

        $configured = $this->runtime->configureUrl($authority, $provisioned['url']);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $configured['evidence_sha256']);
        $rebind = $this->runner->firstExecStage('url-rebind');
        self::assertNotNull($rebind);
        self::assertSame(
            $provisioned['url'],
            ContainerRuntimeFakeRunner::optionValue($rebind['argv'], '--url')
        );
        $bind = $this->runner->firstCall(
            'bind', '--config-sha256', self::CONFIG, '--route-id'
        );
        self::assertNotNull($bind);
        self::assertLessThan(
            self::callIndex($this->runner->calls, $bind),
            self::callIndex($this->runner->calls, $rebind)
        );
        self::assertNotContains('--username', $bind['argv']);
        self::assertNotContains('--password', $bind['argv']);
        self::assertNotContains('--token', $bind['argv']);
        self::assertStringNotContainsString('@', $provisioned['url']);
        self::assertSame(
            parse_url($provisioned['url'], PHP_URL_HOST),
            ContainerRuntimeFakeRunner::optionValue($bind['argv'], '--host')
        );
        self::assertSame(
            $containment['descriptor_sha256'],
            ContainerRuntimeFakeRunner::optionValue($bind['argv'], '--reviewed-base-sha256')
        );

        $credentialContents = self::credentialContents($this->root . '/state');
        self::assertCount(3, $credentialContents);
        foreach (array_keys($credentialContents) as $credentialPath) {
            $stat = lstat($credentialPath);
            self::assertIsArray($stat);
            self::assertSame(0600, $stat['mode'] & 0777);
            if (function_exists('posix_geteuid')) {
                self::assertSame(posix_geteuid(), $stat['uid']);
                self::assertSame(posix_getegid(), $stat['gid']);
            }
        }
        $credentialDirectory = dirname((string) array_key_first($credentialContents));
        $directoryStat = lstat($credentialDirectory);
        self::assertIsArray($directoryStat);
        self::assertSame(0700, $directoryStat['mode'] & 0777);
        $serializedBoundary = CanonicalJson::encode([
            'calls' => $this->runner->calls,
            'configured' => $configured,
            'provisioned' => $provisioned,
            'repository' => $repository,
        ]);
        foreach ($credentialContents as $secret) {
            self::assertStringNotContainsString(trim($secret), $serializedBoundary);
        }
        foreach ($this->runner->calls as $call) {
            self::assertIsArray($call['argv']);
            self::assertNotSame([], $call['argv']);
            self::assertStringStartsWith('/', $call['argv'][0]);
            self::assertNotContains($call['argv'][0], ['/bin/sh', '/bin/bash', '/usr/bin/env']);
        }

        $start = count($this->runner->calls);
        $this->runtime->revokeRouting($authority);
        $this->runtime->revokeExecution($authority);
        $this->runtime->deleteState($authority);
        $afterReap = array_slice($this->runner->calls, $start);
        $positions = self::operationPositions($afterReap);
        self::assertLessThan($positions['stop'], $positions['unbind']);
        self::assertLessThan($positions['container-rm'], $positions['stop']);
        self::assertLessThan($positions['first-volume-rm'], $positions['container-rm']);
        self::assertLessThan($positions['firewall-unbind'], $positions['first-volume-rm']);
        self::assertLessThan($positions['network-rm'], $positions['firewall-unbind']);
        self::assertSame([], self::credentialContents($this->root . '/state'));

        $terminal = $this->runtime->verifyAbsent($lease);
        self::assertTrue($terminal['absent']);
        self::assertSame([], $this->runner->containers);
        self::assertSame([], $this->runner->volumes);
        self::assertSame([], $this->runner->networks);
        self::assertSame([], $this->runner->routes);
        self::assertSame([], $this->runner->localRefs);
        self::assertCount(1, self::callsContaining($this->runner->calls, 'update-ref'));
    }

    public function testTenantSlotsAndGenerationsNeverShareNamesCredentialsOrUrls(): void {
        $first = self::lease('tenant-a', 'site-a', 1, self::operation(10));
        $second = self::lease('tenant-b', 'site-a', 1, self::operation(11));
        $firstResult = $this->runtime->provision($first);
        $firstNames = array_keys($this->runner->containers + $this->runner->volumes + $this->runner->networks);
        $firstCredentials = array_keys(self::credentialContents($this->root . '/state'));

        $secondResult = $this->runtime->provision($second);
        $allNames = array_keys($this->runner->containers + $this->runner->volumes + $this->runner->networks);
        $secondNames = array_values(array_diff($allNames, $firstNames));
        $allCredentials = array_keys(self::credentialContents($this->root . '/state'));
        $secondCredentials = array_values(array_diff($allCredentials, $firstCredentials));

        self::assertNotSame($firstResult['url'], $secondResult['url']);
        self::assertNotSame([], $firstNames);
        self::assertNotSame([], $secondNames);
        self::assertSame([], array_intersect($firstNames, $secondNames));
        self::assertCount(3, $firstCredentials);
        self::assertCount(3, $secondCredentials);
        self::assertSame([], array_intersect($firstCredentials, $secondCredentials));

        $firstGenerationNames = $firstNames;
        $authority = self::authority($first, 1, self::operation(10));
        $this->runtime->revokeRouting($authority);
        $this->runtime->revokeExecution($authority);
        $this->runtime->deleteState($authority);
        $next = self::lease('tenant-a', 'site-a', 2, self::operation(12));
        $nextResult = $this->runtime->provision($next);
        self::assertSame($firstResult['url'], $nextResult['url']);
        $nextNames = array_keys($this->runner->containers + $this->runner->volumes + $this->runner->networks);
        self::assertSame([], array_intersect($firstGenerationNames, $nextNames));
    }

    public function testReapRemovesHardDeathArchiveAndRefusesForeignGenerationResidue(): void {
        $firstLease = self::lease('tenant-a', 'site-residue', 1, self::operation(13));
        $slotToken = substr($firstLease['resource_id'], strlen('cloud-slot-'));
        $preManifestGeneration = $this->root . '/state/slots/' . $slotToken
            . '/generations/0000000001';
        self::assertTrue(mkdir($preManifestGeneration, 0700, true));
        $preManifestArchive = $preManifestGeneration . '/repository-' . str_repeat('a', 40) . '.tar';
        self::assertSame(7, file_put_contents($preManifestArchive, 'partial'));
        $this->runtime->provision($firstLease);
        self::assertFileDoesNotExist($preManifestArchive);
        $firstCredentials = self::credentialContents($this->root . '/state');
        self::assertNotSame([], $firstCredentials);
        $firstGeneration = dirname(dirname((string) array_key_first($firstCredentials)));
        $archive = $firstGeneration . '/repository-' . str_repeat('a', 40) . '.tar';
        self::assertSame(4096, file_put_contents($archive, str_repeat('x', 4096)));
        self::assertTrue(chmod($archive, 0644));

        $firstAuthority = self::authority($firstLease, 1, self::operation(13));
        $this->runtime->revokeRouting($firstAuthority);
        $this->runtime->revokeExecution($firstAuthority);
        $this->runtime->deleteState($firstAuthority);
        self::assertDirectoryDoesNotExist($firstGeneration);
        self::assertTrue($this->runtime->verifyAbsent($firstLease)['absent']);

        $secondLease = self::lease('tenant-a', 'site-residue', 2, self::operation(14));
        $this->runtime->provision($secondLease);
        $secondCredentials = self::credentialContents($this->root . '/state');
        self::assertNotSame([], $secondCredentials);
        $secondGeneration = dirname(dirname((string) array_key_first($secondCredentials)));
        $foreign = $secondGeneration . '/foreign-state';
        self::assertSame(7, file_put_contents($foreign, 'foreign'));

        $secondAuthority = self::authority($secondLease, 1, self::operation(14));
        $this->runtime->revokeRouting($secondAuthority);
        $this->runtime->revokeExecution($secondAuthority);
        $this->assertRefused(
            fn (): array => $this->runtime->deleteState($secondAuthority),
            'generation state directory contains foreign state'
        );
        self::assertFileExists($foreign);
        self::assertTrue(unlink($foreign));
        $this->runtime->deleteState($secondAuthority);
        self::assertDirectoryDoesNotExist($secondGeneration);
        self::assertTrue($this->runtime->verifyAbsent($secondLease)['absent']);
    }

    public function testOneThousandReapedGenerationsLeaveNoGenerationDirectories(): void {
        for ($generation = 1; $generation <= 1000; $generation++) {
            $operation = self::operation(3000 + $generation);
            $lease = self::lease('tenant-a', 'site-bounded', $generation, $operation);
            $this->runtime->provision($lease);
            $authority = self::authority($lease, 1, $operation);
            $this->runtime->revokeRouting($authority);
            $this->runtime->revokeExecution($authority);
            $this->runtime->deleteState($authority);
            self::assertTrue($this->runtime->verifyAbsent($lease)['absent']);
        }

        $slots = glob($this->root . '/state/slots/*') ?: [];
        self::assertCount(1, $slots);
        self::assertSame([], glob($slots[0] . '/generations/*') ?: []);
        self::assertSame([], glob($slots[0] . '/active.json') ?: []);
    }

    public function testSleepRetainsExactGenerationSubstrateAndWakeRecreatesOnlyExecutionAndRoute(): void {
        $lease = self::lease('tenant-a', 'site-sleep', 1, self::operation(15));
        $provisioned = $this->runtime->provision($lease);
        $authority = self::authority($lease, 1, self::operation(15));
        $this->runtime->configureUrl($authority, $provisioned['url']);

        $credentials = self::credentialContents($this->root . '/state');
        $containers = $this->runner->containers;
        $networks = $this->runner->networks;
        $volumes = $this->runner->volumes;
        $firewalls = $this->runner->firewalls;
        $storages = $this->runner->storages;
        self::assertCount(1, $containers);
        self::assertCount(1, $this->runner->routes);

        $this->runtime->revokeRouting($authority);
        $this->runtime->revokeExecution($authority);
        self::assertSame([], $this->runner->containers);
        self::assertSame([], $this->runner->routes);
        self::assertSame($credentials, self::credentialContents($this->root . '/state'));
        self::assertSame($networks, $this->runner->networks);
        self::assertSame($volumes, $this->runner->volumes);
        self::assertSame($firewalls, $this->runner->firewalls);
        self::assertSame($storages, $this->runner->storages);

        $this->runner->runtimeCleanBase = false;
        $resumed = $this->runtime->resumeExecution($authority);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $resumed['evidence_sha256']);
        self::assertSame($containers, $this->runner->containers);
        self::assertSame([], $this->runner->routes);
        self::assertSame($credentials, self::credentialContents($this->root . '/state'));
        self::assertSame($networks, $this->runner->networks);
        self::assertSame($volumes, $this->runner->volumes);
        self::assertSame($firewalls, $this->runner->firewalls);
        self::assertSame($storages, $this->runner->storages);
        self::assertSame($resumed, $this->runtime->resumeExecution($authority));

        $this->runtime->configureUrl($authority, $provisioned['url']);
        self::assertCount(1, $this->runner->routes);
        self::assertSame('present', $this->runtime->inspect($lease)['presence']);
        self::assertSame($credentials, self::credentialContents($this->root . '/state'));
        self::assertSame($volumes, $this->runner->volumes);
    }

    public function testStaleAndForeignAuthorityRefuseBeforeAnyProcessBoundary(): void {
        $lease = self::lease('tenant-a', 'site-a', 1, self::operation(20));
        $this->runtime->provision($lease);
        $first = self::authority($lease, 1, self::operation(20));
        $this->runtime->configureUrl($first, self::url('tenant-a', 'site-a'));
        $second = self::authority($lease, 2, self::operation(21));
        $this->runtime->configureUrl($second, self::url('tenant-a', 'site-a'));

        $before = count($this->runner->calls);
        $this->assertRefused(
            fn (): array => $this->runtime->configureUrl($first, self::url('tenant-a', 'site-a')),
            'stale mutation generation'
        );
        self::assertCount($before, $this->runner->calls);

        $changed = $second;
        $changed['mutation_id'] = 'mutation-foreign';
        $this->assertRefused(
            fn (): array => $this->runtime->configureUrl($changed, self::url('tenant-a', 'site-a')),
            'foreign fence tuple'
        );
        self::assertCount($before, $this->runner->calls);

        $foreign = $lease;
        $foreign['tenant_id'] = 'tenant-b';
        $this->assertRefused(fn (): array => $this->runtime->inspect($foreign), 'does not belong');
        self::assertCount($before, $this->runner->calls);

        $this->assertRefused(
            fn (): array => $this->runtime->materializeRepository($second, [
                'branch_commit' => str_repeat('d', 40),
                'branch_ref' => 'refs/heads/feature',
                'repo_path' => '/tmp/caller-selected',
            ]),
            'configured workload path'
        );
        self::assertCount($before, $this->runner->calls);
    }

    public function testContainmentProofFailsClosedBeforeVolumesOrContainerExist(): void {
        $runner = new ContainerRuntimeFakeRunner(self::CONFIG, self::IMAGE, $this->repository);
        $runner->proveInternalNetwork = false;
        $runtime = $this->newRuntime($runner);
        $lease = self::lease('tenant-a', 'site-fail', 1, self::operation(30));

        $this->assertRefused(
            fn (): array => $runtime->provision($lease),
            'default-deny outbound network containment'
        );
        self::assertCount(1, $runner->networks);
        self::assertSame([], $runner->volumes);
        self::assertSame([], $runner->containers);
        self::assertNull($runner->firstCall('volume', 'create'));
        self::assertNull($runner->firstCall('container', 'create'));
    }

    public function testDockerInlineSeccompReadbackMustMatchThePinnedProfile(): void {
        $lease = self::lease('tenant-a', 'site-seccomp', 1, self::operation(31));
        $this->runtime->provision($lease);
        self::assertCount(1, $this->runner->containers);
        $name = array_key_first($this->runner->containers);
        self::assertIsString($name);
        $security = $this->runner->containers[$name]['HostConfig']['SecurityOpt'] ?? null;
        self::assertIsArray($security);
        self::assertStringStartsWith('seccomp={', $security[1]);

        $profile = json_decode(substr($security[1], strlen('seccomp=')), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($profile);
        $profile['defaultAction'] = 'SCMP_ACT_ALLOW';
        $security[1] = 'seccomp=' . json_encode($profile, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->runner->containers[$name]['HostConfig']['SecurityOpt'] = $security;

        $this->assertRefused(
            fn (): array => $this->runtime->inspect($lease),
            'exact isolated workload configuration'
        );
    }

    public function testInspectReconcilesOneDestinationBoundManifestCrashTemporary(): void {
        $lease = self::lease('tenant-a', 'site-manifest-temp', 1, self::operation(32));
        $this->runtime->provision($lease);
        $manifests = glob(realpath($this->root) . '/state/slots/*/active.json');
        self::assertIsArray($manifests);
        self::assertCount(1, $manifests);
        $temporary = $manifests[0] . '.tmp';
        self::assertSame(8, file_put_contents($temporary, "partial\n"));
        self::assertTrue(chmod($temporary, 0600));

        self::assertSame('present', $this->runtime->inspect($lease)['presence']);
        self::assertFileDoesNotExist($temporary);
        self::assertSame([], glob($manifests[0] . '.tmp*') ?: []);
    }

    public function testUnsafeManifestTemporaryRefusesWithoutFollowingIt(): void {
        $lease = self::lease('tenant-a', 'site-manifest-unsafe', 1, self::operation(33));
        $this->runtime->provision($lease);
        $manifests = glob(realpath($this->root) . '/state/slots/*/active.json');
        self::assertIsArray($manifests);
        self::assertCount(1, $manifests);
        $outside = $this->root . '/outside-manifest';
        self::assertSame(8, file_put_contents($outside, "outside\n"));
        self::assertTrue(chmod($outside, 0600));
        self::assertTrue(symlink($outside, $manifests[0] . '.tmp'));

        $this->assertRefused(
            fn (): array => $this->runtime->inspect($lease),
            'stale temporary manifest must be'
        );
        self::assertSame("outside\n", file_get_contents($outside));
        self::assertTrue(is_link($manifests[0] . '.tmp'));
    }

    public function testCredentialDeletionResumesAfterDurablePartialClear(): void {
        $lease = self::lease('tenant-a', 'site-recovery', 1, self::operation(40));
        $authority = self::authority($lease, 1, self::operation(40));
        $this->runtime->provision($lease);
        $this->runtime->revokeRouting($authority);
        $this->runtime->revokeExecution($authority);

        $manifests = glob(realpath($this->root) . '/state/slots/*/active.json');
        self::assertIsArray($manifests);
        self::assertCount(1, $manifests);
        $bytes = file_get_contents($manifests[0]);
        self::assertIsString($bytes);
        $manifest = CanonicalJson::decodeObject($bytes);
        $manifest['state'] = 'deleting-credentials';
        self::assertSame(
            strlen(CanonicalJson::encode($manifest) . "\n"),
            file_put_contents($manifests[0], CanonicalJson::encode($manifest) . "\n")
        );
        self::assertTrue(chmod($manifests[0], 0600));
        $credentials = array_keys(self::credentialContents($this->root . '/state'));
        self::assertCount(3, $credentials);
        self::assertTrue(unlink($credentials[0]));

        $deleted = $this->runtime->deleteState($authority);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $deleted['evidence_sha256']);
        self::assertTrue($this->runtime->verifyAbsent($lease)['absent']);
        self::assertSame([], self::credentialContents($this->root . '/state'));
    }

    public function testRepositorySyncRefDeletionResumesAndDeleteReplayDoesNotRetainHistory(): void {
        $operation = self::operation(50);
        $lease = self::lease('tenant-a', 'site-sync-recovery', 1, $operation);
        $authority = self::authority($lease, 1, $operation);
        $this->runtime->provision($lease);
        $commit = str_repeat('e', 40);
        $ref = self::REF_PREFIX . $operation;
        $this->runner->remoteRefs[$ref] = $commit;
        $descriptor = $this->runtime->repositoryAuthorityDescriptor();
        $this->runtime->syncRepository($authority, [
            'branch_commit' => $commit,
            'branch_ref' => $ref,
            'candidate_publication_receipt_sha256' => hash('sha256', 'recovery publication'),
            'repository_authority_sha256' => $descriptor['descriptor_sha256'],
        ]);
        self::assertCount(1, $this->runner->localRefs);
        $this->runtime->revokeRouting($authority);
        $this->runtime->revokeExecution($authority);

        $manifests = glob(realpath($this->root) . '/state/slots/*/active.json');
        self::assertIsArray($manifests);
        self::assertCount(1, $manifests);
        $bytes = file_get_contents($manifests[0]);
        self::assertIsString($bytes);
        $manifest = CanonicalJson::decodeObject($bytes);
        self::assertIsArray($manifest['repository_sync']);
        $manifest['repository_sync']['state'] = 'deleting';
        self::assertSame(
            strlen(CanonicalJson::encode($manifest) . "\n"),
            file_put_contents($manifests[0], CanonicalJson::encode($manifest) . "\n")
        );
        self::assertTrue(chmod($manifests[0], 0600));

        $first = $this->runtime->deleteState($authority);
        self::assertSame([], $this->runner->localRefs);
        $deletions = self::callsContaining($this->runner->calls, 'update-ref');
        self::assertCount(1, $deletions);
        self::assertSame($first, $this->runtime->deleteState($authority));
        self::assertCount(1, self::callsContaining($this->runner->calls, 'update-ref'));
    }

    public function testCredentialHelperSubstitutionAndRemoteRefChangeRefuseBeforeFetch(): void {
        $operation = self::operation(60);
        $lease = self::lease('tenant-a', 'site-auth-refusal', 1, $operation);
        $authority = self::authority($lease, 1, $operation);
        $this->runtime->provision($lease);
        $descriptor = $this->runtime->repositoryAuthorityDescriptor();
        $commit = str_repeat('f', 40);
        $ref = self::REF_PREFIX . $operation;
        $this->runner->remoteRefs[$ref] = str_repeat('a', 40);

        self::assertSame(
            strlen("#!/bin/sh\nexit 77\n"),
            file_put_contents($this->credentialHelper, "#!/bin/sh\nexit 77\n")
        );
        self::assertTrue(chmod($this->credentialHelper, 0700));
        $this->assertRefused(fn (): array => $this->runtime->syncRepository($authority, [
            'branch_commit' => $commit,
            'branch_ref' => $ref,
            'candidate_publication_receipt_sha256' => hash('sha256', 'auth publication'),
            'repository_authority_sha256' => $descriptor['descriptor_sha256'],
        ]), 'credential helper differs');
        self::assertCount(0, self::callsContaining($this->runner->calls, 'fetch'));

        self::assertSame(
            strlen(self::TEST_CREDENTIAL_HELPER),
            file_put_contents($this->credentialHelper, self::TEST_CREDENTIAL_HELPER)
        );
        self::assertTrue(chmod($this->credentialHelper, 0700));
        $this->assertRefused(fn (): array => $this->runtime->syncRepository($authority, [
            'branch_commit' => $commit,
            'branch_ref' => $ref,
            'candidate_publication_receipt_sha256' => hash('sha256', 'auth publication'),
            'repository_authority_sha256' => $descriptor['descriptor_sha256'],
        ]), 'does not resolve');
        self::assertCount(0, self::callsContaining($this->runner->calls, 'fetch'));
        self::assertSame([], $this->runner->localRefs);
    }

    public function testLostRouteBindResponseCannotWedgeIdempotentRevocation(): void {
        $lease = self::lease('tenant-route', 'site-route', 1, self::operation(90));
        $provisioned = $this->runtime->provision($lease);
        $authority = self::authority($lease, 1, self::operation(90));
        $this->runner->loseNextRouteBindResponse = true;
        try {
            $this->runtime->configureUrl($authority, $provisioned['url']);
            self::fail('lost route bind response did not refuse local manifest advancement');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('route binding', $error->getMessage());
        }
        self::assertCount(1, $this->runner->routes);

        $this->runtime->revokeRouting($authority);
        self::assertSame([], $this->runner->routes);
    }

    public function testHeldSlotLockRefusesLifecycleMutationBeforeProcessBoundaryAndRetrySucceeds(): void {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('pcntl is required for the runtime slot-lock contention regression');
        }
        $lease = self::lease('tenant-lock', 'site-lock', 1, self::operation(90));
        $this->runtime->provision($lease);
        $token = substr($lease['resource_id'], strlen('cloud-slot-'));
        $lockPath = $this->root . '/state/slots/' . $token . '/runtime.lock';
        $started = $this->root . '/runtime-lock-started';
        $release = $this->root . '/runtime-lock-release';
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            $handle = @fopen($lockPath, 'c+b');
            if (!is_resource($handle) || !chmod($lockPath, 0600)
                || !flock($handle, LOCK_EX | LOCK_NB)) {
                exit(70);
            }
            file_put_contents($started, "held\n");
            $deadline = microtime(true) + 5.0;
            while (!is_file($release) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $released = is_file($release);
            flock($handle, LOCK_UN);
            fclose($handle);
            exit($released ? 0 : 71);
        }
        self::waitForFile($started);
        $calls = count($this->runner->calls);
        $before = microtime(true);
        $this->assertRefused(fn (): array => $this->runtime->inspect($lease), 'runtime slot lock is busy');
        self::assertLessThan(0.5, microtime(true) - $before);
        self::assertCount($calls, $this->runner->calls);

        self::assertNotFalse(file_put_contents($release, "continue\n"));
        pcntl_waitpid($pid, $status);
        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertSame('present', $this->runtime->inspect($lease)['presence']);
        self::assertGreaterThan($calls, count($this->runner->calls));
    }

    public function testNativeRunnerTimesOutAndKillsTheWholeChildProcessGroup(): void {
        $marker = $this->root . '/orphan-mutation';
        $script = $this->root . '/timeout-process-group.php';
        $source = '#!' . PHP_BINARY . "\n<?php\n"
            . "pcntl_async_signals(true);\n"
            . "pcntl_signal(SIGTERM, SIG_IGN);\n"
            . '$child = pcntl_fork();' . "\n"
            . "if (\$child === 0) { usleep(4000000); file_put_contents(\$argv[1], 'orphan'); exit(0); }\n"
            . "sleep(30);\n";
        self::assertSame(strlen($source), file_put_contents($script, $source));
        self::assertTrue(chmod($script, 0700));
        $runner = new NativeContainerArgvProcessRunner(1);
        try {
            $runner->run([$script, $marker]);
            self::fail('expected direct argv runner wall timeout');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('wall timeout', $error->getMessage());
        }
        usleep(3200000);
        self::assertFileDoesNotExist($marker);
    }

    public function testNativeRunnerUsesPinnedPhpCliForEnvShebangWithoutPath(): void {
        $script = $this->root . '/env-php-entrypoint';
        $source = "#!/usr/bin/env php\n<?php fwrite(STDOUT, \"pinned-cli\\n\");\n";
        self::assertSame(strlen($source), file_put_contents($script, $source));
        self::assertTrue(chmod($script, 0700));

        $result = (new NativeContainerArgvProcessRunner(5, PHP_BINARY))->run([$script]);

        self::assertSame(0, $result['exit']);
        self::assertSame('', $result['stderr']);
        self::assertSame("pinned-cli\n", $result['stdout']);
    }

    public function testReviewedDescriptorAcceptsAnImmutableRegistryPort(): void {
        $image = 'registry.example.test:5443/duo/wordpress@sha256:'
            . 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        $runtime = $this->newRuntime($this->runner, $image);

        self::assertSame($image, $runtime->reviewedBaseContainmentDescriptor()['image_reference']);
    }

    public function testDefaultFleetReaperConvergesRetiringHeldGenerationWithoutAncillaryMaterial(): void {
        $cleanupStatePath = $this->root . '/cleanup-state.json';
        [$configurationPath, $configurationSha256, $document] =
            $this->reapConfiguration($cleanupStatePath);
        $runner = new ContainerRuntimeFakeRunner(
            $configurationSha256,
            self::IMAGE,
            $this->repository
        );
        $runtime = $this->newRuntimeForConfiguration($runner, $configurationSha256);
        $now = time() - 120;
        $authorityRoot = $this->root . '/state/authority';
        self::assertTrue(mkdir($authorityRoot, 0700));
        self::assertTrue(chmod($authorityRoot, 0700));
        $lifecycleStore = new FileAuthorityStore($authorityRoot . '/lifecycle.json');
        $controlStore = new FileAuthorityStore($authorityRoot . '/control.json');
        $commandRunner = new ContainerRuntimeRefusingCommandRunner();
        $control = new ControlAuthority(
            $controlStore,
            $commandRunner,
            'test-janitor',
            str_repeat("\0", SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)
        );
        $lifecycle = new PreviewSlotLifecycle(
            $lifecycleStore,
            $runtime,
            new ControlAuthorityMutationPublisher($control),
            static function () use (&$now): int {
                return $now;
            }
        );
        $operation = self::operation(90);
        $identity = $lifecycle->perform('create', 'tenant-a', 'site-a', $operation, [
            'intent_sha256' => hash('sha256', 'minimal-reap-intent'),
            'mode' => 'create',
            'target_environment' => 'preview',
        ]);
        $fence = $lifecycle->perform(
            'mutation-acquire',
            'tenant-a',
            'site-a',
            $operation,
            self::identityInput($identity) + [
                'mutation_owner' => 'duo-env-materialize-' . $operation,
            ]
        );
        $git = new NativeContainerArgvProcessRunner(10, PHP_BINARY, true, false);
        self::assertSame(0, $git->run(['/usr/bin/git', '-C', $this->repository, 'init'])['exit']);
        $tracked = $this->repository . '/preview.txt';
        self::assertSame(8, file_put_contents($tracked, "preview\n"));
        self::assertSame(0, $git->run([
            '/usr/bin/git', '-C', $this->repository, 'add', '--', 'preview.txt',
        ])['exit']);
        self::assertSame(0, $git->run([
            '/usr/bin/git', '-C', $this->repository,
            '-c', 'user.name=Duo Test',
            '-c', 'user.email=duo@example.test',
            'commit', '--no-gpg-sign', '-m', 'preview candidate',
        ])['exit']);
        $commitResult = $git->run([
            '/usr/bin/git', '-C', $this->repository, 'rev-parse', '--verify', 'HEAD^{commit}',
        ]);
        self::assertSame(0, $commitResult['exit']);
        $commit = trim($commitResult['stdout']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{40}\z/D', $commit);
        $branchRef = self::REF_PREFIX . $operation;
        $runner->remoteRefs[$branchRef] = $commit;
        $repositoryAuthority = $runtime->repositoryAuthorityDescriptor();
        $lifecycle->perform(
            'repository-sync',
            'tenant-a',
            'site-a',
            $operation,
            self::identityInput($identity) + self::mutationInput($fence) + [
                'branch_commit' => $commit,
                'branch_ref' => $branchRef,
                'candidate_publication_receipt_sha256' => hash('sha256', 'publication'),
                'repository_authority_sha256' => $repositoryAuthority['descriptor_sha256'],
            ]
        );
        $localRef = array_key_first($runner->localRefs);
        self::assertIsString($localRef);
        self::assertSame(0, $git->run([
            '/usr/bin/git', '-C', $this->repository, 'update-ref', $localRef, $commit,
        ])['exit']);
        $lifecycle->perform(
            'url-set',
            'tenant-a',
            'site-a',
            $operation,
            self::identityInput($identity) + self::mutationInput($fence) + [
                'url' => $identity['url'],
            ]
        );
        $ttl = $lifecycle->perform(
            'ttl-set',
            'tenant-a',
            'site-a',
            $operation,
            self::identityInput($identity) + self::mutationInput($fence) + ['ttl_seconds' => 60]
        );
        self::assertLessThan(time(), strtotime($ttl['expires_at']));
        $physical = [
            'containers' => $runner->containers,
            'firewalls' => $runner->firewalls,
            'networks' => $runner->networks,
            'routes' => $runner->routes,
            'storages' => $runner->storages,
            'volumes' => $runner->volumes,
        ];
        $physicalBytes = CanonicalJson::encode($physical) . "\n";
        self::assertSame(
            strlen($physicalBytes),
            file_put_contents($cleanupStatePath, $physicalBytes)
        );
        self::assertTrue(chmod($cleanupStatePath, 0600));
        self::assertNotSame([], $runner->containers);
        self::assertNotSame([], $runner->routes);
        self::assertNotSame([], $runner->storages);
        self::assertNotSame([], $runner->firewalls);
        self::assertTrue(unlink($this->credentialHelper));
        self::removeTree($this->snapshots);
        foreach ([
            $document['service']['response_signing_key']['path'],
            $document['service']['device_digest_key']['path'],
            $document['controller_keys'][0]['public_key']['path'],
        ] as $serviceFile) {
            self::assertTrue(unlink($serviceFile));
        }

        $hostPreflightRoot = $this->root . '-host-preflight';
        self::assertTrue(mkdir($hostPreflightRoot, 0700));
        self::assertTrue(chmod($hostPreflightRoot, 0700));
        $currentDocument = $document;
        $currentDocument['service']['response_key_id'] = 'service-key-v2';
        $currentBytes = CanonicalJson::encode($currentDocument) . "\n";
        $currentPath = $this->root . '/current-production.json';
        self::assertSame(strlen($currentBytes), file_put_contents($currentPath, $currentBytes));
        self::assertTrue(chmod($currentPath, 0600));
        $currentSha256 = hash('sha256', $currentBytes);
        self::assertNotSame($configurationSha256, $currentSha256);
        $fleetBytes = CanonicalJson::encode([
            'format' => ProductionFleetConfig::FORMAT,
            'workers' => [[
                'configuration_file' => $currentPath,
                'configuration_sha256' => $currentSha256,
                'retiring_configuration' => [
                    'configuration_file' => $configurationPath,
                    'configuration_sha256' => $configurationSha256,
                ],
                'worker_id' => 'worker-a',
            ]],
        ]) . "\n";
        $fleetPath = $this->root . '/fleet.json';
        self::assertSame(strlen($fleetBytes), file_put_contents($fleetPath, $fleetBytes));
        self::assertTrue(chmod($fleetPath, 0600));
        $fleet = ProductionFleetConfig::load($fleetPath, hash('sha256', $fleetBytes));
        $sweep = (new ProductionFleetReaper(
            $fleet,
            null,
            null,
            false,
            new ContainerRuntimeDelegatingProcessRunner(
                new NativeContainerArgvProcessRunner(30, PHP_BINARY, true, false)
            ),
            static function (
                string $_hostRoot,
                array $_configurationSha256s,
                array $_runtimeReviewSha256s,
                int $_ownerUid,
                int $_ownerGid
            ): void {}
        ))->sweep();
        self::assertSame('duo-cloud-expired-preview-fleet-sweep/v1', $sweep['format']);
        self::assertSame('complete', $sweep['state']);
        self::assertSame(1, $sweep['examined']);
        self::assertSame(1, $sweep['reaped']);
        self::assertSame(0, $sweep['refused']);
        self::assertSame(0, $sweep['worker_refusals']);
        self::assertSame($configurationSha256, $sweep['workers'][0]['configuration_sha256']);
        self::assertSame('worker-a', $sweep['workers'][0]['worker_id']);
        self::assertSame('complete', $sweep['workers'][0]['state']);
        self::assertSame(0, $commandRunner->calls);
        self::assertSame(
            'released',
            $control->currentAuthority('tenant-a', 'site-a')['state'] ?? null
        );
        $after = CanonicalJson::decodeObject((string) file_get_contents($cleanupStatePath));
        foreach (['containers', 'firewalls', 'networks', 'routes', 'storages', 'volumes'] as $kind) {
            self::assertSame([], $after[$kind], "$kind did not converge to absence");
        }
        self::assertSame(1, $git->run([
            '/usr/bin/git', '-C', $this->repository,
            'rev-parse', '--verify', '--quiet', $localRef,
        ])['exit']);
        $lifecycleState = CanonicalJson::decodeObject((string) file_get_contents(
            $authorityRoot . '/lifecycle.json'
        ));
        $site = array_values($lifecycleState['sites'])[0] ?? null;
        self::assertIsArray($site);
        self::assertNull($site['current']);
        self::assertIsArray($site['terminal_absence']);
        self::assertSame([], glob($this->root . '/state/runtime/slots/*/active.json') ?: []);
        self::assertSame([], self::credentialContents($this->root . '/state/runtime'));
    }

    private function newRuntime(
        ContainerRuntimeFakeRunner $runner,
        string $image = self::IMAGE
    ): ContainerWorkloadRuntime {
        return new ContainerWorkloadRuntime(
            $runner,
            '/usr/bin/docker',
            '/usr/local/bin/duo-preview-router',
            '/usr/local/bin/duo-preview-firewall',
            '/usr/local/bin/duo-preview-storage',
            '/usr/bin/git',
            $image,
            DUO_REPO_ROOT . '/cloud/deploy/seccomp-profile.json',
            (string) hash_file('sha256', DUO_REPO_ROOT . '/cloud/deploy/seccomp-profile.json'),
            self::CONFIG,
            self::PLATFORM,
            self::REVIEW,
            $this->root . '/state',
            $this->repository,
            self::REMOTE_NAME,
            self::REMOTE_URL,
            hash('sha256', "duo-cloud-repository-remote-url/v1\0" . self::REMOTE_URL),
            self::REF_PREFIX,
            $this->credentialHelper,
            (string) hash_file('sha256', $this->credentialHelper),
            $this->snapshots,
            'preview.example.test',
            '/srv/duo/repository',
            1073741824,
            1000000000,
            256,
            function (int $length): string {
                ++$this->entropyCall;
                $block = hash('sha256', 'runtime-test-entropy-' . $this->entropyCall, true);
                return substr(str_repeat($block, (int) ceil($length / strlen($block))), 0, $length);
            }
        );
    }

    private function newRuntimeForConfiguration(
        ContainerRuntimeFakeRunner $runner,
        string $configurationSha256
    ): ContainerWorkloadRuntime {
        return new ContainerWorkloadRuntime(
            $runner,
            '/usr/bin/docker',
            '/usr/local/bin/duo-preview-router',
            '/usr/local/bin/duo-preview-firewall',
            '/usr/local/bin/duo-preview-storage',
            '/usr/bin/git',
            self::IMAGE,
            DUO_REPO_ROOT . '/cloud/deploy/seccomp-profile.json',
            (string) hash_file('sha256', DUO_REPO_ROOT . '/cloud/deploy/seccomp-profile.json'),
            $configurationSha256,
            self::PLATFORM,
            self::REVIEW,
            $this->root . '/state/runtime',
            $this->repository,
            self::REMOTE_NAME,
            self::REMOTE_URL,
            hash('sha256', "duo-cloud-repository-remote-url/v1\0" . self::REMOTE_URL),
            self::REF_PREFIX,
            $this->credentialHelper,
            (string) hash_file('sha256', $this->credentialHelper),
            $this->snapshots,
            'preview.example.test',
            '/srv/duo/repository',
            1073741824,
            1000000000,
            256,
            function (int $length): string {
                ++$this->entropyCall;
                return str_repeat(chr(0x50 + $this->entropyCall), $length);
            }
        );
    }

    /** @return array{string,string,array<string,mixed>} */
    private function reapConfiguration(string $cleanupStatePath): array {
        $engine = $this->cleanupExecutable('engine', $cleanupStatePath);
        $firewall = $this->cleanupExecutable('firewall', $cleanupStatePath);
        $route = $this->cleanupExecutable('route', $cleanupStatePath);
        $storage = $this->cleanupExecutable('storage', $cleanupStatePath);
        $git = '/usr/bin/git';
        $remoteUrl = self::REMOTE_URL;
        $response = $this->productionKey('reap-response', random_bytes(64));
        $device = $this->productionKey('reap-device', random_bytes(32));
        $controller = $this->productionKey('reap-controller', random_bytes(32));
        $contract = DUO_REPO_ROOT . '/cloud/deploy/runtime-image-contract.json';
        $seccomp = DUO_REPO_ROOT . '/cloud/deploy/seccomp-profile.json';
        $document = [
            'controller_keys' => [[
                'key_id' => 'controller-key-v1',
                'public_key' => $controller,
                'site_id' => 'site-a',
                'tenant_id' => 'tenant-a',
            ]],
            'format' => ProductionConfig::FORMAT,
            'host_durable_paths' => [
                'authority_config_root' => $this->root,
                'control_proxy_config_root' => $this->root,
                'control_proxy_data_root' => $this->root,
                'firewall_authority_state_root' => $this->root . '/state',
                'route_authority_state_root' => $this->root . '/state',
                'route_proxy_config_root' => $this->root,
                'route_proxy_data_root' => $this->root,
                'storage_authority_state_root' => $this->root . '/state',
            ],
            'host_preflight_root' => $this->root . '-host-preflight',
            'runtime' => [
                'container_engine' => $engine,
                'firewall_authority' => $firewall,
                'git' => [
                    'path' => $git,
                    'sha256' => (string) hash_file('sha256', $git),
                ],
                'image' => self::IMAGE,
                'memory_bytes' => 1073741824,
                'nano_cpus' => 1000000000,
                'pids_limit' => 256,
                'platform_fingerprint_sha256' => self::PLATFORM,
                'preview_domain' => 'preview.example.test',
                'process_launcher' => [
                    'path' => PHP_BINARY,
                    'sha256' => (string) hash_file('sha256', PHP_BINARY),
                ],
                'process_timeout_seconds' => 30,
                'repository_remote' => [
                    'allowed_ref_prefix' => self::REF_PREFIX,
                    'credential_helper' => $this->credentialHelper,
                    'credential_helper_sha256' => (string) hash_file(
                        'sha256',
                        $this->credentialHelper
                    ),
                    'name' => self::REMOTE_NAME,
                    'url' => $remoteUrl,
                    'url_sha256' => hash(
                        'sha256',
                        "duo-cloud-repository-remote-url/v1\0$remoteUrl"
                    ),
                ],
                'repository_source' => $this->repository,
                'review_receipt_sha256' => self::REVIEW,
                'route_authority' => $route,
                'runtime_contract_file' => $contract,
                'runtime_contract_sha256' => (string) hash_file('sha256', $contract),
                'seccomp_profile_file' => $seccomp,
                'seccomp_profile_sha256' => (string) hash_file('sha256', $seccomp),
                'snapshot_object_root' => $this->snapshots,
                'storage_authority' => $storage,
                'workload_repository_path' => '/srv/duo/repository',
            ],
            'service' => [
                'device_digest_key' => $device,
                'response_key_id' => 'service-key-v1',
                'response_signing_key' => $response,
            ],
            'state_root' => $this->root . '/state',
        ];
        $bytes = CanonicalJson::encode($document) . "\n";
        $path = $this->root . '/reap-production.json';
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        self::assertTrue(chmod($path, 0600));
        return [$path, hash('sha256', $bytes), $document];
    }

    /** @return array{path:string,sha256:string} */
    private function productionKey(string $name, string $bytes): array {
        $encoded = base64_encode($bytes) . "\n";
        $path = $this->root . '/' . $name . '.key';
        self::assertSame(strlen($encoded), file_put_contents($path, $encoded));
        self::assertTrue(chmod($path, 0600));
        return ['path' => $path, 'sha256' => hash('sha256', $encoded)];
    }

    /** @return array{path:string,sha256:string} */
    private function cleanupExecutable(string $role, string $statePath): array {
        $source = '#!' . PHP_BINARY . "\n<?php\ndeclare(strict_types=1);\n"
            . 'require_once ' . var_export(DUO_REPO_ROOT . '/cloud/src/CanonicalJson.php', true) . ";\n"
            . '$role = ' . var_export($role, true) . ";\n"
            . '$statePath = ' . var_export($statePath, true) . ";\n"
            . <<<'PHP'
$bytes = file_get_contents($statePath);
if (!is_string($bytes)) {
    exit(70);
}
$state = Duo\Cloud\CanonicalJson::decodeObject($bytes);
$save = static function () use (&$state, $statePath): void {
    $bytes = Duo\Cloud\CanonicalJson::encode($state) . "\n";
    if (file_put_contents($statePath, $bytes) !== strlen($bytes) || !chmod($statePath, 0600)) {
        exit(70);
    }
};
$emit = static function (array $value): void {
    fwrite(STDOUT, Duo\Cloud\CanonicalJson::encode($value) . "\n");
};
$option = static function (string $name): ?string {
    $position = array_search($name, $_SERVER['argv'], true);
    return is_int($position) && is_string($_SERVER['argv'][$position + 1] ?? null)
        ? $_SERVER['argv'][$position + 1]
        : null;
};
$action = $_SERVER['argv'][2] ?? null;
if ($role === 'engine') {
    $kind = $_SERVER['argv'][1] ?? null;
    $map = is_string($kind) ? $kind . 's' : '';
    if (!isset($state[$map]) || !is_array($state[$map])) {
        exit(70);
    }
    if ($action === 'ls') {
        $filter = $option('--filter');
        $name = is_string($filter) && preg_match('/\Aname=\^(.*)\$\z/D', $filter, $match) === 1
            ? $match[1]
            : null;
        if (!is_string($name)) {
            exit(70);
        }
        if ($kind === 'container' && str_starts_with($name, '/')) {
            $name = substr($name, 1);
        }
        if (isset($state[$map][$name])) {
            fwrite(STDOUT, $name . "\n");
        }
        exit(0);
    }
    $name = $_SERVER['argv'][count($_SERVER['argv']) - 1] ?? null;
    if (!is_string($name)) {
        exit(70);
    }
    if ($action === 'inspect') {
        if (!is_array($state[$map][$name] ?? null)) {
            exit(1);
        }
        $emit($state[$map][$name]);
        exit(0);
    }
    if ($kind === 'container' && $action === 'stop') {
        if (!is_array($state['containers'][$name] ?? null)) {
            exit(1);
        }
        $state['containers'][$name]['State']['Running'] = false;
        $save();
        exit(0);
    }
    if ($action === 'rm') {
        unset($state[$map][$name]);
        $save();
        exit(0);
    }
    exit(70);
}
if ($role === 'route') {
    $routeId = $option('--route-id');
    if (!is_string($routeId)) {
        exit(70);
    }
    if (($_SERVER['argv'][1] ?? null) === 'unbind') {
        unset($state['routes'][$routeId]);
        $save();
        exit(0);
    }
    if (($_SERVER['argv'][1] ?? null) === 'inspect') {
        $emit(is_array($state['routes'][$routeId] ?? null) ? $state['routes'][$routeId] : [
            'configuration_sha256' => $option('--config-sha256'),
            'format' => 'duo-cloud-preview-route-state/v1',
            'reviewed_base_sha256' => $option('--reviewed-base-sha256'),
            'route_id' => $routeId,
            'state' => 'absent',
        ]);
        exit(0);
    }
    exit(70);
}
if ($role === 'firewall') {
    $configuration = $option('--config-sha256');
    $network = $option('--network-name');
    if (!is_string($configuration) || !is_string($network)) {
        exit(70);
    }
    $key = $configuration . "\0" . $network;
    if (($_SERVER['argv'][1] ?? null) === 'unbind') {
        unset($state['firewalls'][$key]);
        $save();
    } elseif (($_SERVER['argv'][1] ?? null) !== 'inspect') {
        exit(70);
    }
    $emit(is_array($state['firewalls'][$key] ?? null) ? $state['firewalls'][$key] : [
        'configuration_sha256' => $configuration,
        'format' => 'duo-cloud-host-firewall-state/v1',
        'network_name' => $network,
        'state' => 'absent',
    ]);
    exit(0);
}
if ($role === 'storage') {
    $configuration = $option('--config-sha256');
    $generation = $option('--lease-generation');
    $resource = $option('--resource-id');
    if (!is_string($configuration) || !is_string($generation) || !is_string($resource)) {
        exit(70);
    }
    $key = $configuration . "\0" . $resource . "\0" . (int) $generation;
    if (($_SERVER['argv'][1] ?? null) === 'unbind') {
        unset($state['storages'][$key]);
        $save();
    } elseif (($_SERVER['argv'][1] ?? null) !== 'inspect') {
        exit(70);
    }
    $emit(is_array($state['storages'][$key] ?? null) ? $state['storages'][$key] : [
        'configuration_sha256' => $configuration,
        'format' => 'duo-cloud-xfs-quota-storage-state/v1',
        'lease_generation' => (int) $generation,
        'resource_id' => $resource,
        'state' => 'absent',
    ]);
    exit(0);
}
exit(70);
PHP;
        $path = $this->root . '/cleanup-' . $role;
        self::assertSame(strlen($source), file_put_contents($path, $source));
        self::assertTrue(chmod($path, 0700));
        return ['path' => $path, 'sha256' => hash('sha256', $source)];
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

    /** @return array<string,mixed> */
    private static function lease(string $tenant, string $site, int $generation, string $operation): array {
        $resource = 'cloud-slot-' . hash(
            'sha256',
            "duo-cloud-preview-physical-slot/v1\0$tenant\0$site"
        );
        return [
            'environment_identity' => 'cloud-environment-' . hash('sha256', "$tenant\0$site"),
            'lease_generation' => $generation,
            'lease_id' => 'cloud-lease-' . hash('sha256', "$tenant\0$site\0$generation\0$operation"),
            'operation_id' => $operation,
            'ownership_receipt_sha256' => hash('sha256', "ownership\0$tenant\0$site\0$generation"),
            'resource_id' => $resource,
            'site_id' => $site,
            'tenant_id' => $tenant,
        ];
    }

    /** @param array<string,mixed> $lease @return array<string,mixed> */
    private static function authority(array $lease, int $generation, string $operation): array {
        $lease['operation_id'] = $operation;
        return $lease + [
            'mutation_generation' => $generation,
            'mutation_id' => 'mutation-' . $generation,
            'mutation_owner' => 'duo-env-materialize-' . $operation,
            'mutation_receipt_sha256' => hash('sha256', 'mutation-receipt-' . $generation),
        ];
    }

    private static function operation(int $number): string {
        return '20260818-040000-' . str_pad(dechex($number), 24, '0', STR_PAD_LEFT);
    }

    private static function waitForFile(string $path): void {
        $deadline = microtime(true) + 5.0;
        while (!is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($path);
    }

    private static function url(string $tenant, string $site): string {
        $resource = hash('sha256', "duo-cloud-preview-physical-slot/v1\0$tenant\0$site");
        return 'https://p-' . substr($resource, 0, 40) . '.preview.example.test';
    }

    /** @return array<string,string> */
    private static function credentialContents(string $stateRoot): array {
        $contents = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($stateRoot, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || !str_contains($file->getPathname(), '/credentials/')) {
                continue;
            }
            $bytes = file_get_contents($file->getPathname());
            self::assertIsString($bytes);
            $contents[$file->getPathname()] = $bytes;
        }
        return $contents;
    }

    /** @param list<array{argv:list<string>,stdin_file:?string}> $calls @return array<string,int> */
    private static function operationPositions(array $calls): array {
        $positions = [];
        foreach ($calls as $index => $call) {
            $argv = $call['argv'];
            if (($argv[0] ?? null) === '/usr/local/bin/duo-preview-router'
                && ($argv[1] ?? null) === 'unbind') {
                $positions['unbind'] = $index;
            } elseif (($argv[1] ?? null) === 'container' && ($argv[2] ?? null) === 'stop') {
                $positions['stop'] = $index;
            } elseif (($argv[1] ?? null) === 'container' && ($argv[2] ?? null) === 'rm') {
                $positions['container-rm'] = $index;
            } elseif (($argv[1] ?? null) === 'volume' && ($argv[2] ?? null) === 'rm'
                && !isset($positions['first-volume-rm'])) {
                $positions['first-volume-rm'] = $index;
            } elseif (($argv[0] ?? null) === '/usr/local/bin/duo-preview-firewall'
                && ($argv[1] ?? null) === 'unbind') {
                $positions['firewall-unbind'] = $index;
            } elseif (($argv[1] ?? null) === 'network' && ($argv[2] ?? null) === 'rm') {
                $positions['network-rm'] = $index;
            }
        }
        self::assertSame(
            ['unbind', 'stop', 'container-rm', 'first-volume-rm', 'firewall-unbind', 'network-rm'],
            array_keys($positions)
        );
        return $positions;
    }

    /** @param list<array{argv:list<string>,stdin_file:?string}> $calls @param array{argv:list<string>,stdin_file:?string} $needle */
    private static function callIndex(array $calls, array $needle): int {
        foreach ($calls as $index => $call) {
            if ($call === $needle) {
                return $index;
            }
        }
        self::fail('expected process call was not recorded');
    }

    /** @param list<array{argv:list<string>,stdin_file:?string}> $calls @return list<array{argv:list<string>,stdin_file:?string}> */
    private static function callsContaining(array $calls, string $token): array {
        return array_values(array_filter(
            $calls,
            static fn (array $call): bool => in_array($token, $call['argv'], true)
        ));
    }

    private function assertRefused(callable $call, string $message): void {
        try {
            $call();
            self::fail('expected the container runtime to refuse');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
    }

    private static function removeTree(string $root): void {
        if (!is_dir($root)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $path) {
            if ($path->isDir() && !$path->isLink()) {
                @rmdir($path->getPathname());
            } else {
                @unlink($path->getPathname());
            }
        }
        @rmdir($root);
    }
}

/** Deterministic Docker/route/Git model used only by ContainerWorkloadRuntimeTest. */
final class ContainerRuntimeFakeRunner implements ContainerArgvProcessRunner {
    /** @var list<array{argv:list<string>,stdin_file:?string}> */
    public array $calls = [];
    /** @var array<string,array<string,mixed>> */
    public array $containers = [];
    /** @var array<string,array<string,mixed>> */
    public array $networks = [];
    /** @var array<string,array<string,mixed>> */
    public array $volumes = [];
    /** @var array<string,array<string,mixed>> */
    public array $routes = [];
    /** @var array<string,array<string,mixed>> */
    public array $firewalls = [];
    /** @var array<string,array<string,mixed>> */
    public array $storages = [];
    /** @var array<string,string> */
    public array $remoteRefs = [];
    /** @var array<string,string> */
    public array $localRefs = [];
    public bool $proveInternalNetwork = true;
    public bool $loseNextRouteBindResponse = false;
    public bool $runtimeCleanBase = true;
    private string $configurationSha256;
    private string $image;
    private string $repository;
    private ?string $lastCommit = null;

    public function __construct(string $configurationSha256, string $image, string $repository) {
        $this->configurationSha256 = $configurationSha256;
        $this->image = $image;
        $this->repository = $repository;
    }

    public function run(
        array $argv,
        ?string $stdinFile = null,
        ?int $timeoutSeconds = null
    ): array {
        $this->calls[] = ['argv' => $argv, 'stdin_file' => $stdinFile];
        if ($argv[0] === '/usr/bin/docker') {
            return $this->docker($argv, $stdinFile);
        }
        if ($argv[0] === '/usr/local/bin/duo-preview-router') {
            return $this->router($argv);
        }
        if ($argv[0] === '/usr/local/bin/duo-preview-firewall') {
            return $this->firewall($argv);
        }
        if ($argv[0] === '/usr/local/bin/duo-preview-storage') {
            return $this->storage($argv);
        }
        if ($argv[0] === '/usr/bin/git') {
            return $this->git($argv);
        }
        return self::result(99, '', 'unexpected executable');
    }

    /** @return ?array{argv:list<string>,stdin_file:?string} */
    public function firstCall(string ...$tokens): ?array {
        foreach ($this->calls as $call) {
            $matched = true;
            foreach ($tokens as $offset => $token) {
                if (($call['argv'][$offset + 1] ?? null) !== $token) {
                    $matched = false;
                    break;
                }
            }
            if ($matched) {
                return $call;
            }
        }
        return null;
    }

    /** @return list<array{argv:list<string>,stdin_file:?string}> */
    public function callsMatching(string ...$tokens): array {
        $matches = [];
        foreach ($this->calls as $call) {
            foreach ($tokens as $offset => $token) {
                if (($call['argv'][$offset + 1] ?? null) !== $token) {
                    continue 2;
                }
            }
            $matches[] = $call;
        }
        return $matches;
    }

    /** @return ?array{argv:list<string>,stdin_file:?string} */
    public function firstGitArchive(): ?array {
        foreach ($this->calls as $call) {
            if ($call['argv'][0] === '/usr/bin/git' && in_array('archive', $call['argv'], true)) {
                return $call;
            }
        }
        return null;
    }

    /** @return ?array{argv:list<string>,stdin_file:?string} */
    public function firstExecStage(string $stage): ?array {
        foreach ($this->calls as $call) {
            if (($call['argv'][1] ?? null) === 'container'
                && ($call['argv'][2] ?? null) === 'exec'
                && in_array('/opt/duo/bin/' . $stage, $call['argv'], true)) {
                return $call;
            }
        }
        return null;
    }

    /** @param list<string> $argv @return array{exit:int,stderr:string,stdout:string} */
    private function docker(array $argv, ?string $stdinFile): array {
        $kind = $argv[1] ?? '';
        $action = $argv[2] ?? '';
        if ($action === 'ls') {
            $name = self::filterName(self::option($argv, '--filter'));
            $map = match ($kind) {
                'container' => $this->containers,
                'network' => $this->networks,
                'volume' => $this->volumes,
                default => [],
            };
            return self::result(0, isset($map[$name]) ? $name . "\n" : '');
        }
        if ($action === 'inspect') {
            $name = $argv[count($argv) - 1];
            $map = match ($kind) {
                'container' => $this->containers,
                'network' => $this->networks,
                'volume' => $this->volumes,
                default => [],
            };
            return isset($map[$name])
                ? self::result(0, json_encode($map[$name], JSON_THROW_ON_ERROR) . "\n")
                : self::result(1, '', 'not found');
        }
        if ($kind === 'network' && $action === 'create') {
            $name = $argv[count($argv) - 1];
            $this->networks[$name] = [
                'Attachable' => false,
                'Driver' => 'bridge',
                'EnableIPv6' => false,
                'IPAM' => ['Config' => [[
                    'Gateway' => '172.30.0.1',
                    'Subnet' => '172.30.0.0/24',
                ]]],
                'Id' => hash('sha256', $name),
                'Internal' => $this->proveInternalNetwork,
                'Labels' => self::labels($argv),
                'Name' => $name,
                'Options' => [
                    'com.docker.network.bridge.enable_icc' => 'false',
                    'com.docker.network.bridge.enable_ip_masquerade' => 'false',
                    'com.docker.network.bridge.name' => 'duo' . substr(hash('sha256', $name), 0, 12),
                ],
            ];
            return self::result(0, $name . "\n");
        }
        if ($kind === 'volume' && $action === 'create') {
            $name = $argv[count($argv) - 1];
            $this->volumes[$name] = [
                'Driver' => 'local',
                'Labels' => self::labels($argv),
                'Name' => $name,
            ];
            return self::result(0, $name . "\n");
        }
        if ($kind === 'container' && $action === 'create') {
            $name = self::option($argv, '--name');
            $network = self::option($argv, '--network');
            $mounts = [];
            foreach (self::options($argv, '--mount') as $specification) {
                $fields = [];
                foreach (explode(',', $specification) as $part) {
                    if (str_contains($part, '=')) {
                        [$key, $value] = explode('=', $part, 2);
                        $fields[$key] = $value;
                    } else {
                        $fields[$part] = true;
                    }
                }
                $mount = [
                    'Destination' => $fields['target'],
                    'RW' => !isset($fields['readonly']),
                    'Source' => $fields['source'],
                    'Type' => $fields['type'],
                ];
                if ($fields['type'] === 'volume') {
                    $mount['Name'] = $fields['source'];
                }
                $mounts[] = $mount;
            }
            $this->containers[$name] = [
                'Config' => [
                    'Cmd' => [
                        'serve',
                        '--clean-base',
                        '--config-sha256',
                        self::option($argv, '--config-sha256'),
                        '--lease-generation',
                        self::option($argv, '--lease-generation'),
                        '--reviewed-base-sha256',
                        self::option($argv, '--reviewed-base-sha256'),
                    ],
                    'Env' => ['PATH=/usr/local/bin:/usr/bin'],
                    'Entrypoint' => ['/opt/duo/bin/duo-preview-runtime'],
                    'Image' => $this->image,
                    'Labels' => self::labels($argv),
                    'User' => '10001:10001',
                ],
                'HostConfig' => [
                    'AutoRemove' => false,
                    'CapAdd' => null,
                    'CapDrop' => ['ALL'],
                    'CgroupnsMode' => 'private',
                    'DeviceRequests' => null,
                    'Devices' => [],
                    'Dns' => self::options($argv, '--dns'),
                    'DnsOptions' => self::options($argv, '--dns-opt'),
                    'DnsSearch' => self::options($argv, '--dns-search'),
                    'IpcMode' => 'private',
                    'LogConfig' => ['Config' => [], 'Type' => 'none'],
                    'Memory' => (int) self::option($argv, '--memory'),
                    'MemorySwap' => (int) self::option($argv, '--memory-swap'),
                    'NanoCpus' => 1000000000,
                    'NetworkMode' => $network,
                    'PidMode' => '',
                    'PidsLimit' => (int) self::option($argv, '--pids-limit'),
                    'PortBindings' => [],
                    'Privileged' => false,
                    'MaskedPaths' => [
                        '/proc/acpi',
                        '/proc/asound',
                        '/proc/interrupts',
                        '/proc/kcore',
                        '/proc/keys',
                        '/proc/latency_stats',
                        '/proc/sched_debug',
                        '/proc/scsi',
                        '/proc/timer_list',
                        '/proc/timer_stats',
                        '/sys/devices/virtual/powercap',
                        '/sys/firmware',
                    ],
                    'ReadonlyPaths' => [
                        '/proc/bus',
                        '/proc/fs',
                        '/proc/irq',
                        '/proc/sys',
                        '/proc/sysrq-trigger',
                    ],
                    'ReadonlyRootfs' => true,
                    'Runtime' => 'runc',
                    'SecurityOpt' => self::securityOptions($argv),
                    'ShmSize' => (int) self::option($argv, '--shm-size'),
                    'Tmpfs' => [
                        '/run' => 'rw,noexec,nosuid,nodev,size=16777216,mode=0700,uid=10001,gid=10001',
                        '/tmp' => 'rw,noexec,nosuid,nodev,size=67108864,mode=0700,uid=10001,gid=10001',
                    ],
                    'Ulimits' => [[
                        'Hard' => 4096,
                        'Name' => 'nofile',
                        'Soft' => 4096,
                    ]],
                ],
                'Mounts' => $mounts,
                'Name' => '/' . $name,
                'NetworkSettings' => ['Networks' => [$network => ['IPAddress' => '172.30.0.2']]],
                'AppArmorProfile' => 'docker-default',
                'State' => ['Running' => false],
            ];
            return self::result(0, $name . "\n");
        }
        if ($kind === 'container' && $action === 'start') {
            $name = $argv[3];
            $this->containers[$name]['State']['Running'] = true;
            return self::result(0, $name . "\n");
        }
        if ($kind === 'container' && $action === 'stop') {
            $name = $argv[count($argv) - 1];
            $this->containers[$name]['State']['Running'] = false;
            return self::result(0, $name . "\n");
        }
        if ($kind === 'container' && $action === 'rm') {
            $name = $argv[3];
            unset($this->containers[$name]);
            return self::result(0, $name . "\n");
        }
        if ($kind === 'volume' && $action === 'rm') {
            $name = $argv[3];
            unset($this->volumes[$name]);
            return self::result(0, $name . "\n");
        }
        if ($kind === 'network' && $action === 'rm') {
            $name = $argv[3];
            unset($this->networks[$name]);
            return self::result(0, $name . "\n");
        }
        if ($kind === 'container' && $action === 'exec') {
            return $this->exec($argv, $stdinFile);
        }
        return self::result(99, '', 'unexpected docker argv');
    }

    /** @param list<string> $argv @return array{exit:int,stderr:string,stdout:string} */
    private function exec(array $argv, ?string $stdinFile): array {
        foreach (['runtime-status', 'materialize-repository', 'restore-database', 'restore-media', 'url-rebind'] as $stage) {
            if (!in_array('/opt/duo/bin/' . $stage, $argv, true)) {
                continue;
            }
            if ($stage === 'runtime-status') {
                $container = $argv[array_search('--user', $argv, true) + 2];
                $generation = (int) $this->containers[$container]['Config']['Labels'][
                    'duo.cloud.lease-generation'
                ];
                return self::canonical([
                    'clean_base' => $this->runtimeCleanBase,
                    'configuration_sha256' => $this->configurationSha256,
                    'format' => 'duo-cloud-preview-runtime-status/v1',
                    'lease_generation' => $generation,
                    'ready' => true,
                    'reviewed_base_sha256' => $this->containers[$container]['Config']['Labels'][
                        'duo.cloud.reviewed-base-sha256'
                    ],
                ]);
            }
            if ($stage !== 'url-rebind' && ($stdinFile === null || !is_file($stdinFile))) {
                return self::result(2, '', 'missing stdin artifact');
            }
            if ($stage === 'url-rebind' && ($stdinFile !== null
                || filter_var(self::option($argv, '--url'), FILTER_VALIDATE_URL) === false)) {
                return self::result(2, '', 'invalid URL rebind');
            }
            return self::canonical([
                'configuration_sha256' => $this->configurationSha256,
                'format' => 'duo-cloud-preview-runtime-stage/v1',
                'reviewed_base_sha256' => self::option($argv, '--reviewed-base-sha256'),
                'stage' => $stage,
                'state' => 'complete',
            ]);
        }
        return self::result(99, '', 'unexpected container exec');
    }

    /** @param list<string> $argv @return array{exit:int,stderr:string,stdout:string} */
    private function router(array $argv): array {
        $action = $argv[1];
        $routeId = self::option($argv, '--route-id');
        if ($action === 'bind') {
            $this->routes[$routeId] = [
                'configuration_sha256' => self::option($argv, '--config-sha256'),
                'format' => 'duo-cloud-preview-route-state/v1',
                'host' => self::option($argv, '--host'),
                'route_id' => $routeId,
                'reviewed_base_sha256' => self::option($argv, '--reviewed-base-sha256'),
                'state' => 'bound',
                'upstream_container' => self::option($argv, '--upstream-container'),
                'upstream_network' => self::option($argv, '--upstream-network'),
                'upstream_port' => (int) self::option($argv, '--upstream-port'),
            ];
            if ($this->loseNextRouteBindResponse) {
                $this->loseNextRouteBindResponse = false;
                return self::result(1, '', 'lost route response');
            }
            return self::result(0);
        }
        if ($action === 'unbind') {
            unset($this->routes[$routeId]);
            return self::result(0);
        }
        if ($action === 'inspect') {
            return isset($this->routes[$routeId])
                ? self::canonical($this->routes[$routeId])
                : self::canonical([
                    'configuration_sha256' => self::option($argv, '--config-sha256'),
                    'format' => 'duo-cloud-preview-route-state/v1',
                    'reviewed_base_sha256' => self::option($argv, '--reviewed-base-sha256'),
                    'route_id' => $routeId,
                    'state' => 'absent',
                ]);
        }
        return self::result(99, '', 'unexpected router argv');
    }

    /** @param list<string> $argv @return array{exit:int,stderr:string,stdout:string} */
    private function firewall(array $argv): array {
        $action = $argv[1];
        $configuration = self::option($argv, '--config-sha256');
        $networkName = self::option($argv, '--network-name');
        $key = $configuration . "\0" . $networkName;
        if ($action === 'bind') {
            $this->firewalls[$key] = [
                'bridge_name' => self::option($argv, '--bridge-name'),
                'configuration_sha256' => $configuration,
                'format' => 'duo-cloud-host-firewall-state/v1',
                'gateway' => self::option($argv, '--gateway'),
                'network_id' => self::option($argv, '--network-id'),
                'network_name' => $networkName,
                'state' => 'bound',
                'subnet' => self::option($argv, '--subnet'),
            ];
            return self::canonical($this->firewalls[$key]);
        }
        if ($action === 'unbind') {
            unset($this->firewalls[$key]);
            return self::canonical([
                'configuration_sha256' => $configuration,
                'format' => 'duo-cloud-host-firewall-state/v1',
                'network_name' => $networkName,
                'state' => 'absent',
            ]);
        }
        if ($action === 'inspect') {
            return isset($this->firewalls[$key])
                ? self::canonical($this->firewalls[$key])
                : self::canonical([
                    'configuration_sha256' => $configuration,
                    'format' => 'duo-cloud-host-firewall-state/v1',
                    'network_name' => $networkName,
                    'state' => 'absent',
                ]);
        }
        return self::result(99, '', 'unexpected firewall argv');
    }

    /** @param list<string> $argv @return array{exit:int,stderr:string,stdout:string} */
    private function storage(array $argv): array {
        $action = $argv[1] ?? '';
        $configuration = self::option($argv, '--config-sha256');
        $generation = (int) self::option($argv, '--lease-generation');
        $resourceId = self::option($argv, '--resource-id');
        $key = $configuration . "\0" . $resourceId . "\0" . $generation;
        if ($action === 'bind') {
            $database = self::option($argv, '--database-volume');
            $filesystem = self::option($argv, '--filesystem-volume');
            $this->storages[$key] = [
                'configuration_sha256' => $configuration,
                'format' => 'duo-cloud-xfs-quota-storage-state/v1',
                'lease_generation' => $generation,
                'resource_id' => $resourceId,
                'state' => 'bound',
                'volumes' => [[
                    'bytes' => 67108864,
                    'inodes' => 1024,
                    'kind' => 'database',
                    'mountpoint' => '/var/lib/docker/volumes/' . $database . '/_data',
                    'name' => $database,
                    'project_id' => 100001,
                ], [
                    'bytes' => 134217728,
                    'inodes' => 2048,
                    'kind' => 'filesystem',
                    'mountpoint' => '/var/lib/docker/volumes/' . $filesystem . '/_data',
                    'name' => $filesystem,
                    'project_id' => 100002,
                ]],
            ];
            return self::canonical($this->storages[$key]);
        }
        if ($action === 'unbind') {
            unset($this->storages[$key]);
        }
        if (isset($this->storages[$key])) {
            return self::canonical($this->storages[$key]);
        }
        return self::canonical([
            'configuration_sha256' => $configuration,
            'format' => 'duo-cloud-xfs-quota-storage-state/v1',
            'lease_generation' => $generation,
            'resource_id' => $resourceId,
            'state' => 'absent',
        ]);
    }

    /** @param list<string> $argv @return array{exit:int,stderr:string,stdout:string} */
    private function git(array $argv): array {
        if (in_array('remote', $argv, true) && in_array('get-url', $argv, true)) {
            return self::result(0, ContainerWorkloadRuntimeTest::REMOTE_URL . "\n");
        }
        if (in_array('ls-remote', $argv, true)) {
            $ref = $argv[count($argv) - 1];
            return isset($this->remoteRefs[$ref])
                ? self::result(0, $this->remoteRefs[$ref] . "\t" . $ref . "\n")
                : self::result(0);
        }
        if (in_array('fetch', $argv, true)) {
            $mapping = $argv[count($argv) - 1];
            [$remoteRef, $localRef] = explode(':', $mapping, 2);
            if (!isset($this->remoteRefs[$remoteRef])) {
                return self::result(1, '', 'missing remote ref');
            }
            $this->localRefs[$localRef] = $this->remoteRefs[$remoteRef];
            return self::result(0);
        }
        if (in_array('update-ref', $argv, true) && in_array('-d', $argv, true)) {
            $delete = array_search('-d', $argv, true);
            $ref = $argv[$delete + 1];
            $expected = $argv[$delete + 2];
            if (($this->localRefs[$ref] ?? null) !== $expected) {
                return self::result(1, '', 'changed local ref');
            }
            unset($this->localRefs[$ref]);
            return self::result(0);
        }
        if (in_array('--git-common-dir', $argv, true)) {
            return self::result(0, $this->repository . '/.git' . "\n");
        }
        if (in_array('cat-file', $argv, true)) {
            $argument = $argv[count($argv) - 1];
            $this->lastCommit = substr($argument, 0, strpos($argument, '^{commit}'));
            return self::result(0);
        }
        if (in_array('--verify', $argv, true)) {
            $argument = $argv[count($argv) - 1];
            $ref = str_ends_with($argument, '^{commit}')
                ? substr($argument, 0, -strlen('^{commit}'))
                : $argument;
            if (isset($this->localRefs[$ref])) {
                return self::result(0, $this->localRefs[$ref] . "\n");
            }
            if (in_array('--quiet', $argv, true)) {
                return self::result(1);
            }
            return self::result(0, $this->lastCommit . "\n");
        }
        if (in_array('archive', $argv, true)) {
            foreach ($argv as $argument) {
                if (str_starts_with($argument, '--output=')) {
                    $path = substr($argument, strlen('--output='));
                    file_put_contents($path, str_repeat("duo-archive\0", 64));
                    return self::result(0);
                }
            }
        }
        return self::result(99, '', 'unexpected git argv');
    }

    /** @param list<string> $argv */
    private static function option(array $argv, string $option): string {
        $position = array_search($option, $argv, true);
        if (!is_int($position) || !isset($argv[$position + 1])) {
            throw new \LogicException("missing fake option $option");
        }
        return $argv[$position + 1];
    }

    /** @param list<string> $argv */
    public static function optionValue(array $argv, string $option): string {
        return self::option($argv, $option);
    }

    /** @param list<string> $argv @return list<string> */
    public static function options(array $argv, string $option): array {
        $values = [];
        foreach ($argv as $index => $argument) {
            if ($argument === $option && isset($argv[$index + 1])) {
                $values[] = $argv[$index + 1];
            }
        }
        return $values;
    }

    /** @param list<string> $argv @return list<string> */
    private static function securityOptions(array $argv): array {
        $options = self::options($argv, '--security-opt');
        foreach ($options as $index => $option) {
            if (!str_starts_with($option, 'seccomp=')) {
                continue;
            }
            $path = substr($option, strlen('seccomp='));
            $profile = json_decode((string) file_get_contents($path), true, 64, JSON_THROW_ON_ERROR);
            $options[$index] = 'seccomp=' . json_encode(
                $profile,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        }
        return $options;
    }

    /** @param list<string> $argv @return array<string,string> */
    private static function labels(array $argv): array {
        $labels = [];
        foreach (self::options($argv, '--label') as $label) {
            [$key, $value] = explode('=', $label, 2);
            $labels[$key] = $value;
        }
        return $labels;
    }

    private static function filterName(string $filter): string {
        $name = preg_replace('/^name=\^\/?|\$$/', '', $filter);
        if (!is_string($name)) {
            throw new \LogicException('invalid fake name filter');
        }
        return $name;
    }

    /** @param array<string,mixed> $object @return array{exit:int,stderr:string,stdout:string} */
    private static function canonical(array $object): array {
        return self::result(0, CanonicalJson::encode($object) . "\n");
    }

    /** @return array{exit:int,stderr:string,stdout:string} */
    private static function result(int $exit, string $stdout = '', string $stderr = ''): array {
        return ['exit' => $exit, 'stderr' => $stderr, 'stdout' => $stdout];
    }
}
