<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\ControlRefusal;
use Duo\Cloud\FileAuthorityStore;
use Duo\Cloud\ExpiredPreviewJanitor;
use Duo\Cloud\ExpiredPreviewJanitorCommandRunner;
use Duo\Cloud\HostAuthorityBusy;
use Duo\Cloud\NativeContainerArgvProcessRunner;
use Duo\Cloud\OperatorDiagnostics;
use Duo\Cloud\ProductionConfig;
use Duo\Cloud\ProductionDeploymentProofs;
use Duo\Cloud\ProductionFleetConfig;
use Duo\Cloud\ProductionFleetPreflight;
use Duo\Cloud\ProductionHostPreflightReceipt;
use Duo\Cloud\ProductionService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/runtime/ProductionService.php';
require_once DUO_REPO_ROOT . '/cloud/runtime/ProductionFleetPreflight.php';

#[CoversNothing]
final class ProductionServiceTest extends TestCase {
    private string $scratch;
    private string $hostPreflightRoot;
    private string $configPath;
    private string $configSha256;
    /** @var array<string,ProductionConfig> */
    private array $productionConfigs = [];
    /** @var array<string,string> */
    private array $installedConfigurationSha256s = [];

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-production-service-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
        $this->scratch = (string) realpath($this->scratch);
        $this->hostPreflightRoot = sys_get_temp_dir()
            . '/duo-production-host-preflight-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->hostPreflightRoot, 0700));
        self::assertTrue(chmod($this->hostPreflightRoot, 0700));
        $this->hostPreflightRoot = (string) realpath($this->hostPreflightRoot);
        foreach (['repo', 'snapshots', 'state'] as $directory) {
            self::assertTrue(mkdir($this->scratch . '/' . $directory, 0700));
            self::assertTrue(chmod($this->scratch . '/' . $directory, 0700));
        }
        $this->installHostAuthorityConfigurationFixture();
        [$this->configPath, $this->configSha256] = $this->configuration();
    }

    protected function tearDown(): void {
        $this->remove($this->scratch);
        $this->remove($this->hostPreflightRoot);
    }

    public function testProductionAssemblyDefersKeyInstallationAndExposesPinnedDescriptors(): void {
        $service = ProductionService::fromConfigFile($this->configPath, $this->configSha256, false);
        $descriptor = $service->deploymentDescriptor();

        self::assertSame('duo-cloud-production-service/v1', $descriptor['format']);
        self::assertSame($this->configSha256, $descriptor['configuration_sha256']);
        self::assertSame(
            ['site_id' => 'site-a', 'tenant_id' => 'tenant-a'],
            $descriptor['principal']
        );
        self::assertSame(
            'duo-cloud-repository-authority/v1',
            $descriptor['repository_authority']['format']
        );
        self::assertSame(
            'duo-reviewed-preview-base-containment/v1',
            $descriptor['reviewed_base_containment']['format']
        );
        self::assertFileDoesNotExist($this->scratch . '/state/authority/control.json');
        self::assertFileDoesNotExist($this->scratch . '/state/preflight/controller-keys.json');
        self::assertDirectoryExists($this->scratch . '/state/origin-blobs/objects');
    }

    public function testDeploymentProofLossInvalidatesPreflightAndPublicReadiness(): void {
        $service = ProductionService::fromConfigFile(
            $this->configPath,
            $this->configSha256,
            false
        );
        $this->refreshSingle($service, $this->configSha256);
        self::assertSame(
            'duo-cloud-production-preflight-receipt/v1',
            $service->assertReadyToServe()['format']
        );

        $config = $this->productionConfigs[$this->configSha256];
        $proofs = new ProductionDeploymentProofs($config);
        self::assertTrue(unlink($proofs->runtimeImagePath()));
        try {
            $service->preflight();
            self::fail('missing runtime image evidence left worker preflight ready');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('deployment proof file', $error->getMessage());
        }
        try {
            $service->assertReadyToServe();
            self::fail('missing runtime image evidence left public readiness active');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('deployment proof file', $error->getMessage());
        }

        $runtime = $config->runtime();
        $proofs->publishRuntimeImage($this->runtimeImageProof(
            $runtime['image'],
            $runtime['seccomp_profile_sha256']
        ));
        try {
            $service->assertReadyToServe();
            self::fail('restored deployment evidence revived an invalidated worker lease');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('preflight receipt', $error->getMessage());
        }
        self::assertSame('ready', $service->preflight()['state']);
    }

    public function testMinimalJanitorAssemblyCannotDependOnOrExecuteServingMaterial(): void {
        $document = $this->configurationDocument();
        $credentialHelper = $this->executable('credential-helper');
        $document['runtime']['repository_remote']['credential_helper'] = $credentialHelper['path'];
        $document['runtime']['repository_remote']['credential_helper_sha256'] = $credentialHelper['sha256'];
        [$path, $sha] = $this->writeConfiguration($document, 'reap-only.json');

        foreach ([
            $document['service']['response_signing_key']['path'],
            $document['service']['device_digest_key']['path'],
            $document['controller_keys'][0]['public_key']['path'],
            $credentialHelper['path'],
        ] as $unrelatedFile) {
            self::assertTrue(unlink($unrelatedFile));
        }
        $this->remove($document['runtime']['snapshot_object_root']);

        self::assertInstanceOf(
            ExpiredPreviewJanitor::class,
            ProductionService::janitorFromConfigFile($path, $sha)
        );
        $entrypoint = $this->execute('duo-cloud-reap-expired', ['--limit', '7'], $path, $sha);
        self::assertSame(0, $entrypoint['exit']);
        self::assertSame('', $entrypoint['stderr']);
        self::assertSame([
            'format' => 'duo-cloud-expired-preview-sweep/v1',
            'result' => ['examined' => 0, 'reaped' => 0, 'refused' => 0],
        ], CanonicalJson::decodeObject($entrypoint['stdout']));
        self::assertDirectoryExists($this->scratch . '/state/authority');
        self::assertDirectoryExists($this->scratch . '/state/runtime');
        try {
            ProductionService::fromConfigFile($path, $sha, false);
            self::fail('normal production assembly accepted missing serving material');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('snapshots', $error->getMessage());
        }

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('janitor cannot execute workload commands');
        (new ExpiredPreviewJanitorCommandRunner())->run([]);
    }

    public function testInstalledControllerKeysDoNotContendWithRecurringServiceWork(): void {
        $service = ProductionService::fromConfigFile($this->configPath, $this->configSha256, false);
        $this->refreshSingle($service, $this->configSha256);
        self::assertFileExists($this->scratch . '/state/authority/control.json');
        self::assertFileExists($this->scratch . '/state/preflight/controller-keys.json');

        $lockPath = $this->scratch . '/state/authority/control.json.lock';
        $holder = proc_open([PHP_BINARY, '-r', <<<'PHP'
$path = $_SERVER['argv'][1] ?? '';
$handle = is_string($path) ? fopen($path, 'c+b') : false;
if (!is_resource($handle) || !chmod($path, 0600) || !flock($handle, LOCK_EX)) {
    fwrite(STDERR, "lock refused\n");
    exit(70);
}
fwrite(STDOUT, "locked\n");
fflush(STDOUT);
stream_get_contents(STDIN);
flock($handle, LOCK_UN);
fclose($handle);
PHP, $lockPath], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, ['LANG' => 'C'], ['bypass_shell' => true]);
        self::assertIsResource($holder);
        self::assertSame("locked\n", fgets($pipes[1]));

        $started = microtime(true);
        try {
            $fresh = ProductionService::fromConfigFile($this->configPath, $this->configSha256, false);
            self::assertSame(
                'duo-cloud-production-preflight-receipt/v1',
                $fresh->assertReadyToServe()['format']
            );
            self::assertSame('ready', $fresh->preflight()['state']);
            self::assertSame(0, $fresh->origin()->reapExpiredExports(1));
            self::assertSame(
                ['examined' => 0, 'reaped' => 0, 'refused' => 0],
                $fresh->janitor()->sweep(1)
            );
        } finally {
            fclose($pipes[0]);
            $holderStdout = stream_get_contents($pipes[1]);
            $holderStderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $holderExit = proc_close($holder);
        }
        self::assertLessThan(4.0, microtime(true) - $started);
        self::assertSame('', $holderStdout);
        self::assertSame('', $holderStderr);
        self::assertSame(0, $holderExit);
    }

    public function testControllerKeyRotationRevokesRemovedIdsAndForbidsReuse(): void {
        $this->refreshSingle(
            ProductionService::fromConfigFile($this->configPath, $this->configSha256, false),
            $this->configSha256
        );

        $rotatedPublic = $this->key('controller-public-rotated', random_bytes(32));
        $rotatedDocument = $this->configurationDocument();
        $rotatedDocument['controller_keys'] = [[
            'key_id' => 'origin-controller-key-rotated',
            'public_key' => $rotatedPublic,
            'site_id' => 'site-a',
            'tenant_id' => 'tenant-a',
        ]];
        [$rotatedPath, $rotatedSha256] = $this->writeConfiguration(
            $rotatedDocument,
            'service-rotated.json'
        );
        $rotated = ProductionService::fromConfigFile($rotatedPath, $rotatedSha256, false);
        try {
            $rotated->assertReadyToServe();
            self::fail('changed controller registry reused the previous preflight receipt');
        } catch (ControlRefusal $error) {
            self::assertSame(
                'controller keys are not installed for the active production configuration',
                $error->getMessage()
            );
        }
        $this->primeHostRegistry(['site-a' => $rotated]);
        $this->refreshHost($rotated, $rotatedPath, $rotatedSha256);
        self::assertSame('ready', $rotated->preflight()['state']);

        $control = $this->authorityState('control.json');
        self::assertSame('revoked', $control['request_keys']['origin-controller-key']['state']);
        self::assertSame(
            'active',
            $control['request_keys']['origin-controller-key-rotated']['state']
        );
        $installation = $this->authorityState('../preflight/controller-keys.json');
        self::assertSame('installed', $installation['state']);
        self::assertSame(['origin-controller-key'], $installation['retired_key_ids']);
        self::assertSame($rotatedSha256, $installation['configuration_sha256']);

        $reused = ProductionService::fromConfigFile($this->configPath, $this->configSha256, false);
        try {
            $this->primeHostRegistry(['site-a' => $reused]);
            $this->refreshHost($reused, $this->configPath, $this->configSha256);
            $reused->preflight();
            self::fail('a retired controller key id was reactivated');
        } catch (ControlRefusal $error) {
            self::assertSame('retired controller key ids cannot be reused', $error->getMessage());
        }
        $control = $this->authorityState('control.json');
        self::assertSame(
            'active',
            $control['request_keys']['origin-controller-key-rotated']['state']
        );
    }

    public function testControllerKeyBytesCannotChangeUnderAnExistingId(): void {
        $this->refreshSingle(
            ProductionService::fromConfigFile($this->configPath, $this->configSha256, false),
            $this->configSha256
        );

        $changedDocument = $this->configurationDocument();
        $changedDocument['controller_keys'][0]['public_key'] = $this->key(
            'controller-public-changed',
            random_bytes(32)
        );
        [$changedPath, $changedSha256] = $this->writeConfiguration(
            $changedDocument,
            'service-changed-key.json'
        );
        try {
            $changed = ProductionService::fromConfigFile($changedPath, $changedSha256, false);
            $this->primeHostRegistry(['site-a' => $changed]);
            $this->refreshHost($changed, $changedPath, $changedSha256);
            $changed->preflight();
            self::fail('controller key bytes changed under an installed id');
        } catch (ControlRefusal $error) {
            self::assertSame('controller key rotation must use a new key id', $error->getMessage());
        }
        $control = $this->authorityState('control.json');
        self::assertSame('active', $control['request_keys']['origin-controller-key']['state']);
        $installation = $this->authorityState('../preflight/controller-keys.json');
        self::assertSame($this->configSha256, $installation['configuration_sha256']);
        self::assertSame('installed', $installation['state']);
    }

    public function testOperatorAndJanitorExecutablesRunThroughProductionAssembly(): void {
        if (!$this->delegatedCgroupAvailable()) {
            $preflight = $this->execute('duo-cloud-preflight', []);
            self::assertSame(70, $preflight['exit']);
            self::assertSame('', $preflight['stdout']);
            self::assertSame("duo-cloud-preflight: refused\n", $preflight['stderr']);
            foreach (glob(DUO_REPO_ROOT . '/cloud/bin/*') ?: [] as $binary) {
                if (basename($binary) === 'duo-cloud-http') {
                    self::assertFalse(is_executable($binary), 'FastCGI source must not carry an executable shebang');
                } else {
                    self::assertTrue(is_executable($binary), basename($binary) . ' must be executable');
                }
            }
            return;
        }
        $hostService = ProductionService::fromConfigFile(
            $this->configPath,
            $this->configSha256,
            false
        );
        $this->primeHostRegistry(['site-a' => $hostService]);
        $this->refreshHost($hostService, $this->configPath, $this->configSha256);
        $preflight = $this->execute('duo-cloud-preflight', []);
        self::assertSame(0, $preflight['exit']);
        self::assertSame('', $preflight['stderr']);
        self::assertSame(
            'duo-cloud-production-preflight/v1',
            CanonicalJson::decodeObject($preflight['stdout'])['format']
        );
        self::assertFileExists($this->scratch . '/state/preflight/receipt.json');

        $admin = $this->execute('duo-cloud-origin-admin', ['issue-device-code', '--ttl', '30']);
        self::assertSame(0, $admin['exit']);
        self::assertSame('', $admin['stderr']);
        $issued = CanonicalJson::decodeObject($admin['stdout']);
        self::assertSame('duo-cloud-origin-device-code/v1', $issued['format']);
        self::assertSame('tenant-a', $issued['tenant_id']);
        self::assertSame('site-a', $issued['site_id']);
        self::assertMatchesRegularExpression('/\A[A-Z2-7]{5}(?:-[A-Z2-7]{5}){3}\z/D', $issued['device_code']);

        $origin = $this->execute('duo-cloud-reap-origin', ['--limit', '7']);
        self::assertSame(0, $origin['exit']);
        self::assertSame('', $origin['stderr']);
        self::assertSame(
            ['format' => 'duo-cloud-origin-retention-sweep/v1', 'reaped' => 0],
            CanonicalJson::decodeObject($origin['stdout'])
        );

        $preview = $this->execute('duo-cloud-reap-expired', ['--limit', '7']);
        self::assertSame(0, $preview['exit']);
        self::assertSame('', $preview['stderr']);
        self::assertSame([
            'format' => 'duo-cloud-expired-preview-sweep/v1',
            'result' => ['examined' => 0, 'reaped' => 0, 'refused' => 0],
        ], CanonicalJson::decodeObject($preview['stdout']));

        foreach (glob(DUO_REPO_ROOT . '/cloud/bin/*') ?: [] as $binary) {
            self::assertTrue(is_executable($binary), basename($binary) . ' must be executable');
        }
    }

    private function delegatedCgroupAvailable(): bool {
        try {
            $result = (new NativeContainerArgvProcessRunner(5, PHP_BINARY, true, true))
                ->run(['/usr/bin/true']);
            return $result['exit'] === 0 && $result['stdout'] === '' && $result['stderr'] === '';
        } catch (ControlRefusal) {
            return false;
        }
    }

    public function testHttpExecutableRequiresCurrentActivePreflightReceipt(): void {
        $before = $this->execute('duo-cloud-http', []);
        self::assertSame(0, $before['exit']);
        $this->assertOperatorDiagnostic($before['stderr'], 'cloud-http-startup');
        self::assertSame("{\"error\":\"service_unavailable\"}\n", $before['stdout']);

        if ($this->delegatedCgroupAvailable()) {
            $hostService = ProductionService::fromConfigFile(
                $this->configPath,
                $this->configSha256,
                false
            );
            $this->primeHostRegistry(['site-a' => $hostService]);
            $this->refreshHost($hostService, $this->configPath, $this->configSha256);
            self::assertSame(0, $this->execute('duo-cloud-preflight', [])['exit']);
        } else {
            $service = ProductionService::fromConfigFile(
                $this->configPath,
                $this->configSha256,
                false
            );
            $this->primeHostRegistry(['site-a' => $service]);
            $this->refreshHost($service, $this->configPath, $this->configSha256);
            self::assertSame(
                'ready',
                $service->preflight()['state']
            );
        }
        $ready = $this->execute('duo-cloud-http', []);
        self::assertSame(0, $ready['exit']);
        self::assertSame('', $ready['stderr']);
        self::assertSame("{\"error\":\"request_refused\"}\n", $ready['stdout']);

        $receiptPath = $this->scratch . '/state/preflight/receipt.json';
        $receipt = CanonicalJson::decodeObject((string) file_get_contents($receiptPath));
        $receipt['issued_at'] = time() - 601;
        $receipt['expires_at'] = time() - 1;
        self::assertNotFalse(file_put_contents($receiptPath, CanonicalJson::encode($receipt) . "\n"));
        self::assertTrue(chmod($receiptPath, 0600));
        $stale = $this->execute('duo-cloud-http', []);
        self::assertSame("{\"error\":\"service_unavailable\"}\n", $stale['stdout']);
        $this->assertOperatorDiagnostic($stale['stderr'], 'cloud-http-startup');
    }

    /** @return array<string,mixed> */
    private function refreshSingle(ProductionService $service, string $configurationSha256): array {
        self::assertSame($configurationSha256, $service->deploymentDescriptor()['configuration_sha256']);
        $this->primeHostRegistry(['site-a' => $service]);
        $this->refreshHost($service, $this->configPath, $configurationSha256);
        return $service->preflight();
    }

    private function refreshHost(
        ProductionService $service,
        string $configurationFile,
        string $configurationSha256
    ): void {
        ProductionService::refreshFleetHostPreflight(
            ProductionConfig::inspectForFleet($configurationFile, $configurationSha256),
            [$service->hostWorkerDescriptor('site-a')],
            false
        );
    }

    /** @param array<string,ProductionService> $services */
    private function primeHostRegistry(array $services): void {
        $workers = [];
        foreach ($services as $workerId => $service) {
            $workers[] = $service->hostWorkerDescriptor($workerId);
        }
        $this->primeHostDescriptors($workers);
    }

    /** @param list<array<string,mixed>> $workers */
    private function primeHostDescriptors(array $workers): void {
        $current = [];
        foreach ($workers as $worker) {
            $sha256 = $worker['configuration_sha256'] ?? null;
            if (!is_string($sha256) || !isset($this->productionConfigs[$sha256])) {
                continue;
            }
            $config = $this->productionConfigs[$sha256];
            $proofs = new ProductionDeploymentProofs($config);
            $proofs->beginLinuxHostVerification();
            $proofs->publishLinuxHost($this->linuxHostProof($config));
            if (($worker['configuration_role'] ?? null) === 'current') {
                $current[(string) $worker['worker_id']] = $config;
            }
        }
        if ($current !== []) {
            ksort($current, SORT_STRING);
            $this->publishInstalledFpmProof($current);
        }
        $firewall = [];
        $routes = [];
        $storage = [];
        foreach ($workers as $worker) {
            $workerId = $worker['worker_id'];
            $firewall[$workerId] ??= [
                'configuration_sha256s' => [], 'service_uid' => 10001, 'worker_id' => $workerId,
            ];
            $firewall[$workerId]['configuration_sha256s'][] = $worker['configuration_sha256'];
            $routes[] = [
                'preview_domain' => $worker['preview_domain'],
                'reviewed_base_sha256' => $worker['reviewed_base_sha256'],
                'runtime_configuration_sha256' => $worker['configuration_sha256'],
            ];
            $storage[] = [
                'configuration_sha256' => $worker['configuration_sha256'],
                'worker_root' => $worker['worker_root'],
                'worker_root_sha256' => hash(
                    'sha256',
                    "duo-cloud-worker-root/v1\0" . $worker['worker_root']
                ),
            ];
        }
        foreach ($firewall as &$principal) {
            sort($principal['configuration_sha256s'], SORT_STRING);
        }
        unset($principal);
        $firewall = array_values($firewall);
        usort($firewall, static fn (array $left, array $right): int => strcmp(
            $left['worker_id'],
            $right['worker_id']
        ));
        usort($routes, static fn (array $left, array $right): int => strcmp(
            $left['runtime_configuration_sha256'] . "\0" . $left['reviewed_base_sha256'],
            $right['runtime_configuration_sha256'] . "\0" . $right['reviewed_base_sha256']
        ));
        usort($storage, static fn (array $left, array $right): int => strcmp(
            $left['configuration_sha256'],
            $right['configuration_sha256']
        ));
        $bytes = CanonicalJson::encode([
            'firewall_principals' => $firewall,
            'route_principals' => $routes,
            'storage_workers' => $storage,
        ]) . "\n";
        self::assertSame(
            strlen($bytes),
            file_put_contents($this->scratch . '/host-registry.json', $bytes)
        );
        self::assertTrue(chmod($this->scratch . '/host-registry.json', 0600));
    }

    /** @return array<string,mixed> */
    private function hostAdmission(
        ProductionConfig $config,
        string $workerId,
        string $role
    ): array {
        $descriptor = $config->fleetWorkerDescriptor($workerId);
        return [
            'configuration_role' => $role,
            'configuration_sha256' => $descriptor['configuration_sha256'],
            'preview_domain' => $descriptor['preview_domain'],
            'reviewed_base_sha256' => $descriptor['reviewed_base_sha256'],
            'worker_id' => $descriptor['worker_id'],
            'worker_root' => $descriptor['worker_root'],
        ];
    }

    public function testFleetHostAdmissionExactlyMatchesDeclaredRetiringConfiguration(): void {
        $retiringDocument = $this->configurationDocument();
        $retiringDocument['runtime']['image'] = 'registry.example.test/duo/wordpress@sha256:'
            . hash('sha256', 'retiring image');
        [$retiringPath, $retiringSha256] = $this->writeConfiguration(
            $retiringDocument,
            'service-retiring.json'
        );
        $current = ProductionService::fromConfigFile(
            $this->configPath,
            $this->configSha256,
            false
        );
        $retiring = ProductionConfig::inspectForFleet($retiringPath, $retiringSha256);
        $retiringAdmission = $this->hostAdmission($retiring, 'site-a', 'retiring');
        $this->primeHostDescriptors([
            $current->hostWorkerDescriptor('site-a'),
            $retiringAdmission,
        ]);

        $declared = [
            'format' => ProductionFleetConfig::FORMAT,
            'workers' => [[
                'configuration_file' => $this->configPath,
                'configuration_sha256' => $this->configSha256,
                'retiring_configuration' => [
                    'configuration_file' => $retiringPath,
                    'configuration_sha256' => $retiringSha256,
                ],
                'worker_id' => 'site-a',
            ]],
        ];
        $declaredBytes = CanonicalJson::encode($declared) . "\n";
        $declaredPath = $this->scratch . '/fleet-with-retiring.json';
        self::assertSame(
            strlen($declaredBytes),
            file_put_contents($declaredPath, $declaredBytes)
        );
        self::assertTrue(chmod($declaredPath, 0600));
        $refresh = new ProductionFleetPreflight(
            ProductionFleetConfig::load($declaredPath, hash('sha256', $declaredBytes)),
            false
        );
        self::assertSame('host_ready', $refresh->refresh()['state']);
        $receipt = new ProductionHostPreflightReceipt(
            new FileAuthorityStore($this->hostPreflightRoot . '/receipt.json'),
            $current->hostAuthoritySha256()
        );
        self::assertSame('ready', $receipt->assertCurrent($this->configSha256)['state']);
        self::assertSame('ready', $receipt->assertCurrent($retiringSha256)['state']);

        $syntheticFirewallOnly = hash('sha256', 'synthetic firewall rotation proof');
        $registryPath = $this->scratch . '/host-registry.json';
        $registry = CanonicalJson::decodeObject((string) file_get_contents($registryPath));
        $registry['firewall_principals'][0]['configuration_sha256s'][] = $syntheticFirewallOnly;
        sort($registry['firewall_principals'][0]['configuration_sha256s'], SORT_STRING);
        $registryBytes = CanonicalJson::encode($registry) . "\n";
        self::assertSame(
            strlen($registryBytes),
            file_put_contents($registryPath, $registryBytes)
        );
        self::assertTrue(chmod($registryPath, 0600));
        self::assertSame('host_ready', $refresh->refresh()['state']);
        try {
            $receipt->assertCurrent($syntheticFirewallOnly);
            self::fail('a firewall-only proof identity entered the host admission receipt');
        } catch (ControlRefusal $error) {
            self::assertSame(
                'production host preflight receipt does not admit this worker',
                $error->getMessage()
            );
        }

        $withoutRetiring = $declared;
        $withoutRetiring['workers'][0]['retiring_configuration'] = null;
        $withoutRetiringBytes = CanonicalJson::encode($withoutRetiring) . "\n";
        $withoutRetiringPath = $this->scratch . '/fleet-without-retiring.json';
        self::assertSame(
            strlen($withoutRetiringBytes),
            file_put_contents($withoutRetiringPath, $withoutRetiringBytes)
        );
        self::assertTrue(chmod($withoutRetiringPath, 0600));
        try {
            (new ProductionFleetPreflight(
                ProductionFleetConfig::load(
                    $withoutRetiringPath,
                    hash('sha256', $withoutRetiringBytes)
                ),
                false
            ))->refresh();
            self::fail('an undeclared fully registered retiring configuration was admitted');
        } catch (ControlRefusal $error) {
            self::assertSame(
                'host authority admission intersection differs from the fleet manifest',
                $error->getMessage()
            );
        }

        $registry = CanonicalJson::decodeObject((string) file_get_contents($registryPath));
        $registry['route_principals'][] = [
            'preview_domain' => $retiringAdmission['preview_domain'],
            'reviewed_base_sha256' => hash('sha256', 'wrong retiring reviewed base'),
            'runtime_configuration_sha256' => $retiringSha256,
        ];
        usort($registry['route_principals'], static fn (array $left, array $right): int => strcmp(
            $left['runtime_configuration_sha256'] . "\0" . $left['reviewed_base_sha256'],
            $right['runtime_configuration_sha256'] . "\0" . $right['reviewed_base_sha256']
        ));
        $registryBytes = CanonicalJson::encode($registry) . "\n";
        self::assertSame(
            strlen($registryBytes),
            file_put_contents($registryPath, $registryBytes)
        );
        self::assertTrue(chmod($registryPath, 0600));
        try {
            $refresh->refresh();
            self::fail('an additive route grant bypassed the exact retiring reviewed base');
        } catch (ControlRefusal $error) {
            self::assertSame(
                'fleet worker is absent from the exact route principal registry',
                $error->getMessage()
            );
        }
    }

    public function testFleetRejectsRetiringLauncherRotationBeforeHostEffects(): void {
        $retiringDocument = $this->configurationDocument();
        $retiringDocument['runtime']['process_launcher'] = $this->executable(
            'retiring-process-launcher'
        );
        [$retiringPath, $retiringSha256] = $this->writeConfiguration(
            $retiringDocument,
            'service-retiring-launcher.json'
        );
        $fleetBytes = CanonicalJson::encode([
            'format' => ProductionFleetConfig::FORMAT,
            'workers' => [[
                'configuration_file' => $this->configPath,
                'configuration_sha256' => $this->configSha256,
                'retiring_configuration' => [
                    'configuration_file' => $retiringPath,
                    'configuration_sha256' => $retiringSha256,
                ],
                'worker_id' => 'site-a',
            ]],
        ]) . "\n";
        $fleetPath = $this->scratch . '/fleet-retiring-launcher.json';
        self::assertSame(strlen($fleetBytes), file_put_contents($fleetPath, $fleetBytes));
        self::assertTrue(chmod($fleetPath, 0600));
        $globalLog = $this->scratch . '/global-preflight.log';
        self::assertSame(0, file_put_contents($globalLog, ''));

        try {
            (new ProductionFleetPreflight(
                ProductionFleetConfig::load($fleetPath, hash('sha256', $fleetBytes)),
                false
            ))->refresh();
            self::fail('retiring launcher rotation reached shared host effects');
        } catch (ControlRefusal $error) {
            self::assertSame(
                'fleet retiring configuration changes the worker host identity',
                $error->getMessage()
            );
        }
        self::assertSame('', file_get_contents($globalLog));
    }

    public function testFleetLifecycleAuthorityContentionPreservesOnlyAnExactCurrentReceipt(): void {
        $service = ProductionService::fromConfigFile(
            $this->configPath,
            $this->configSha256,
            false
        );
        $this->primeHostRegistry(['site-a' => $service]);
        $fleetBytes = CanonicalJson::encode([
            'format' => ProductionFleetConfig::FORMAT,
            'workers' => [[
                'configuration_file' => $this->configPath,
                'configuration_sha256' => $this->configSha256,
                'retiring_configuration' => null,
                'worker_id' => 'site-a',
            ]],
        ]) . "\n";
        $fleetPath = $this->scratch . '/contention-fleet.json';
        self::assertSame(strlen($fleetBytes), file_put_contents($fleetPath, $fleetBytes));
        self::assertTrue(chmod($fleetPath, 0600));
        $diagnostics = [];
        $refresh = new ProductionFleetPreflight(
            ProductionFleetConfig::load($fleetPath, hash('sha256', $fleetBytes)),
            false,
            new OperatorDiagnostics(static function (string $line) use (&$diagnostics): void {
                $diagnostics[] = $line;
            })
        );
        self::assertSame('host_ready', $refresh->refresh()['state']);
        self::assertSame('ready', $service->preflight()['state']);
        $receipt = new ProductionHostPreflightReceipt(
            new FileAuthorityStore($this->hostPreflightRoot . '/receipt.json'),
            $service->hostAuthoritySha256()
        );

        foreach ([
            'firewall' => 'firewall reconcile authority lock is busy',
            'route' => 'route host preflight authority lock is busy',
        ] as $authority => $expected) {
            $holder = $this->startAuthorityLifecycle($authority);
            try {
                try {
                    $refresh->refresh();
                    self::fail("fleet host preflight overlapped the $authority lifecycle writer");
                } catch (HostAuthorityBusy $error) {
                    self::assertSame($expected, $error->getMessage());
                }
                self::assertSame(
                    'ready',
                    $receipt->assertCurrent($this->configSha256)['state']
                );
                self::assertSame(
                    'duo-cloud-production-preflight-receipt/v1',
                    $service->assertReadyToServe()['format']
                );
            } finally {
                $this->stopAuthorityLifecycle($holder);
            }
        }

        $holder = $this->startAuthorityLifecycle('route');
        try {
            try {
                $service->preflight();
                self::fail('worker preflight overlapped the route lifecycle writer');
            } catch (HostAuthorityBusy $error) {
                self::assertSame(
                    'route worker preflight authority lock is busy',
                    $error->getMessage()
                );
            }
            self::assertSame(
                'duo-cloud-production-preflight-receipt/v1',
                $service->assertReadyToServe()['format']
            );
        } finally {
            $this->stopAuthorityLifecycle($holder);
        }

        $workerReceiptPath = $this->scratch . '/state/preflight/receipt.json';
        $workerReceipt = CanonicalJson::decodeObject((string) file_get_contents(
            $workerReceiptPath
        ));
        $workerReceipt['issued_at'] = time() - 61;
        $workerReceipt['expires_at'] = time() - 1;
        $workerReceiptBytes = CanonicalJson::encode($workerReceipt) . "\n";
        self::assertSame(
            strlen($workerReceiptBytes),
            file_put_contents($workerReceiptPath, $workerReceiptBytes)
        );
        self::assertTrue(chmod($workerReceiptPath, 0600));
        $holder = $this->startAuthorityLifecycle('route');
        try {
            try {
                $service->preflight();
                self::fail('route contention preserved an expired worker receipt');
            } catch (ControlRefusal $error) {
                self::assertSame(
                    'route authority contention found no current worker receipt',
                    $error->getMessage()
                );
            }
        } finally {
            $this->stopAuthorityLifecycle($holder);
        }
        $workerReceipt = CanonicalJson::decodeObject((string) file_get_contents(
            $workerReceiptPath
        ));
        self::assertSame(0, $workerReceipt['expires_at']);
        self::assertSame(str_repeat('0', 64), $workerReceipt['evidence_sha256']);

        self::assertSame(1, file_put_contents($this->scratch . '/false-busy-firewall', '1'));
        try {
            $refresh->refresh();
            self::fail('exit 75 with a non-protocol stderr preserved host readiness');
        } catch (ControlRefusal $error) {
            self::assertSame('firewall reconcile refused', $error->getMessage());
        }
        $state = CanonicalJson::decodeObject((string) file_get_contents(
            $this->hostPreflightRoot . '/receipt.json'
        ));
        self::assertSame('invalid', $state['state']);
        self::assertTrue(unlink($this->scratch . '/false-busy-firewall'));
        self::assertSame('host_ready', $refresh->refresh()['state']);

        $state = CanonicalJson::decodeObject((string) file_get_contents(
            $this->hostPreflightRoot . '/receipt.json'
        ));
        $state['configuration_sha256s'][] = hash('sha256', 'undeclared former worker');
        sort($state['configuration_sha256s'], SORT_STRING);
        $stateBytes = CanonicalJson::encode($state) . "\n";
        self::assertSame(
            strlen($stateBytes),
            file_put_contents($this->hostPreflightRoot . '/receipt.json', $stateBytes)
        );
        self::assertTrue(chmod($this->hostPreflightRoot . '/receipt.json', 0600));
        $holder = $this->startAuthorityLifecycle('route');
        try {
            try {
                $refresh->refresh();
                self::fail('authority contention preserved a non-exact fleet receipt');
            } catch (ControlRefusal $error) {
                self::assertSame(
                    'host authority contention found no current exact fleet receipt',
                    $error->getMessage()
                );
            }
        } finally {
            $this->stopAuthorityLifecycle($holder);
        }
        $state = CanonicalJson::decodeObject((string) file_get_contents(
            $this->hostPreflightRoot . '/receipt.json'
        ));
        self::assertSame('invalid', $state['state']);
        self::assertCount(4, $diagnostics);
    }

    public function testMaximumFleetHostRefreshNeverRunsDelayedWorkerChecks(): void {
        $workers = [];
        $descriptors = [];
        for ($index = 0; $index < 32; $index++) {
            $workerId = sprintf('site-%02d', $index);
            $document = $this->configurationDocument();
            $document['controller_keys'][0]['site_id'] = $workerId;
            $workerRoot = $this->scratch . '/absent-' . $workerId;
            $document['runtime']['repository_source'] = $workerRoot . '/repo';
            $document['runtime']['snapshot_object_root'] = $workerRoot . '/snapshots';
            $document['state_root'] = $workerRoot . '/state';
            [$path, $sha256] = $this->writeConfiguration($document, "$workerId.json");
            $workers[] = [
                'configuration_file' => $path,
                'configuration_sha256' => $sha256,
                'retiring_configuration' => null,
                'worker_id' => $workerId,
            ];
            $config = ProductionConfig::inspectForFleet($path, $sha256);
            $descriptor = $config->fleetWorkerDescriptor($workerId);
            $descriptors[] = [
                'configuration_role' => 'current',
                'configuration_sha256' => $descriptor['configuration_sha256'],
                'preview_domain' => $descriptor['preview_domain'],
                'reviewed_base_sha256' => $descriptor['reviewed_base_sha256'],
                'worker_id' => $descriptor['worker_id'],
                'worker_root' => $descriptor['worker_root'],
            ];
        }
        $this->primeHostDescriptors($descriptors);
        $fleetBytes = CanonicalJson::encode([
            'format' => ProductionFleetConfig::FORMAT,
            'workers' => $workers,
        ]) . "\n";
        $fleetPath = $this->scratch . '/maximum-fleet.json';
        self::assertSame(strlen($fleetBytes), file_put_contents($fleetPath, $fleetBytes));
        self::assertTrue(chmod($fleetPath, 0600));
        self::assertSame(1, file_put_contents($this->scratch . '/delay-worker-preflight', '1'));

        $started = microtime(true);
        $result = (new ProductionFleetPreflight(
            ProductionFleetConfig::load($fleetPath, hash('sha256', $fleetBytes)),
            false
        ))->refresh();
        self::assertLessThan(5.0, microtime(true) - $started);
        self::assertSame('host_ready', $result['state']);
        self::assertCount(32, $result['workers']);
        self::assertSame(array_fill(0, 32, 'admitted'), array_column($result['workers'], 'state'));
        foreach ($workers as $worker) {
            self::assertDirectoryDoesNotExist(
                $this->scratch . '/absent-' . $worker['worker_id'] . '/state'
            );
        }
    }

    public function testFleetRejectsDuplicatePrincipalAndOverlappingRootsBeforeHostEffects(): void {
        foreach (['principal', 'root'] as $case) {
            $document = $this->configurationDocument();
            if ($case === 'principal') {
                $workerRoot = $this->scratch . '/absent-duplicate-principal';
            } else {
                $document['controller_keys'][0]['site_id'] = 'site-b';
                $workerRoot = $this->scratch . '/nested-worker';
            }
            $document['runtime']['repository_source'] = $workerRoot . '/repo';
            $document['runtime']['snapshot_object_root'] = $workerRoot . '/snapshots';
            $document['state_root'] = $workerRoot . '/state';
            [$secondPath, $secondSha256] = $this->writeConfiguration(
                $document,
                "duplicate-$case.json"
            );
            $fleetBytes = CanonicalJson::encode([
                'format' => ProductionFleetConfig::FORMAT,
                'workers' => [[
                    'configuration_file' => $this->configPath,
                    'configuration_sha256' => $this->configSha256,
                    'retiring_configuration' => null,
                    'worker_id' => 'site-a',
                ], [
                    'configuration_file' => $secondPath,
                    'configuration_sha256' => $secondSha256,
                    'retiring_configuration' => null,
                    'worker_id' => 'site-b',
                ]],
            ]) . "\n";
            $fleetPath = $this->scratch . "/duplicate-$case-fleet.json";
            self::assertSame(strlen($fleetBytes), file_put_contents($fleetPath, $fleetBytes));
            self::assertTrue(chmod($fleetPath, 0600));
            $globalLog = $this->scratch . '/global-preflight.log';
            self::assertSame(0, file_put_contents($globalLog, ''));
            $diagnostics = [];
            try {
                (new ProductionFleetPreflight(
                    ProductionFleetConfig::load($fleetPath, hash('sha256', $fleetBytes)),
                    false,
                    new OperatorDiagnostics(static function (string $line) use (&$diagnostics): void {
                        $diagnostics[] = $line;
                    })
                ))->refresh();
                self::fail("duplicate fleet $case reached host effects");
            } catch (ControlRefusal $error) {
                self::assertStringContainsString(
                    $case === 'principal' ? 'principal is duplicated' : 'roots overlap',
                    $error->getMessage()
                );
            }
            self::assertSame('', file_get_contents($globalLog));
            self::assertCount(1, $diagnostics);
            self::assertDirectoryDoesNotExist($workerRoot . '/state');
            $receipt = CanonicalJson::decodeObject((string) file_get_contents(
                $this->hostPreflightRoot . '/receipt.json'
            ));
            self::assertSame('invalid', $receipt['state']);
        }
    }

    public function testFleetRefreshRunsHostOnceAndIsolatesAWorkerLocalRefusal(): void {
        $secondaryRoot = sys_get_temp_dir() . '/duo-production-worker-b-' . bin2hex(random_bytes(8));
        foreach ([$secondaryRoot, "$secondaryRoot/repo", "$secondaryRoot/snapshots", "$secondaryRoot/state"] as $directory) {
            self::assertTrue(mkdir($directory, 0700));
            self::assertTrue(chmod($directory, 0700));
        }
        $secondaryRoot = (string) realpath($secondaryRoot);
        try {
            $secondary = $this->configurationDocument();
            $secondary['controller_keys'][0]['site_id'] = 'site-b';
            $secondary['runtime']['repository_source'] = "$secondaryRoot/repo";
            $secondary['runtime']['snapshot_object_root'] = "$secondaryRoot/snapshots";
            $secondary['state_root'] = "$secondaryRoot/state";
            [$secondaryPath, $secondarySha256] = $this->writeConfiguration(
                $secondary,
                'service-b.json'
            );
            $serviceA = ProductionService::fromConfigFile(
                $this->configPath,
                $this->configSha256,
                false
            );
            $serviceB = ProductionService::fromConfigFile($secondaryPath, $secondarySha256, false);
            $this->primeHostRegistry(['site-a' => $serviceA, 'site-b' => $serviceB]);
            $fleet = [
            'format' => ProductionFleetConfig::FORMAT,
            'workers' => [[
                'configuration_file' => $this->configPath,
                'configuration_sha256' => $this->configSha256,
                'retiring_configuration' => null,
                'worker_id' => 'site-a',
            ], [
                'configuration_file' => $secondaryPath,
                'configuration_sha256' => $secondarySha256,
                'retiring_configuration' => null,
                'worker_id' => 'site-b',
            ]],
            ];
            $fleetBytes = CanonicalJson::encode($fleet) . "\n";
            $fleetPath = $this->scratch . '/fleet.json';
            self::assertSame(strlen($fleetBytes), file_put_contents($fleetPath, $fleetBytes));
            self::assertTrue(chmod($fleetPath, 0600));
            $diagnostics = [];
            $refresh = new ProductionFleetPreflight(
            ProductionFleetConfig::load($fleetPath, hash('sha256', $fleetBytes)),
            false,
            new OperatorDiagnostics(static function (string $line) use (&$diagnostics): void {
                $diagnostics[] = $line;
            })
            );
            $globalLog = $this->scratch . '/global-preflight.log';
            self::assertSame(0, file_put_contents($globalLog, ''));

            $ready = $refresh->refresh();
            self::assertSame('host_ready', $ready['state']);
            self::assertSame(['admitted', 'admitted'], array_column($ready['workers'], 'state'));
            self::assertSame(
            ["firewall:reconcile\n", "firewall:preflight\n", "route:host-preflight\n"],
            file($globalLog)
            );
            self::assertFileExists($this->hostPreflightRoot . '/receipt.json');
            self::assertSame('ready', (new ProductionHostPreflightReceipt(
                new FileAuthorityStore($this->hostPreflightRoot . '/receipt.json'),
                $serviceA->hostAuthoritySha256()
            ))->assertCurrent($this->configSha256)['state']);
            $maintenanceLockPath = $this->hostPreflightRoot . '/'
                . ProductionFleetConfig::MAINTENANCE_STATE_FILE . '.lock';
            $maintenanceLock = fopen($maintenanceLockPath, 'c+b');
            self::assertIsResource($maintenanceLock);
            self::assertTrue(chmod($maintenanceLockPath, 0600));
            self::assertTrue(flock($maintenanceLock, LOCK_EX | LOCK_NB));
            try {
                try {
                    $refresh->refresh();
                    self::fail('host refresh overlapped fleet maintenance');
                } catch (ControlRefusal $error) {
                    self::assertSame('fleet maintenance lock is busy', $error->getMessage());
                }
                self::assertSame(
                    'ready',
                    (new ProductionHostPreflightReceipt(
                        new FileAuthorityStore($this->hostPreflightRoot . '/receipt.json'),
                        $serviceA->hostAuthoritySha256()
                    ))->assertCurrent($this->configSha256)['state']
                );
            } finally {
                flock($maintenanceLock, LOCK_UN);
                fclose($maintenanceLock);
            }
            foreach ([$serviceA, $serviceB] as $service) {
                try {
                    $service->assertReadyToServe();
                    self::fail('host admission alone created a worker-local readiness receipt');
                } catch (ControlRefusal $error) {
                    self::assertStringContainsString('controller key', $error->getMessage());
                }
            }

            self::assertSame('ready', $serviceA->preflight()['state']);
            self::assertSame('ready', $serviceB->preflight()['state']);
            ProductionDeploymentProofs::invalidateInstalledFpmWorker(
                $this->hostPreflightRoot,
                $secondarySha256,
                function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid()
            );
            self::assertSame(
                'duo-cloud-production-preflight-receipt/v1',
                $serviceA->assertReadyToServe()['format']
            );
            try {
                $serviceB->assertReadyToServe();
                self::fail('worker B FPM proof loss invalidated neither worker');
            } catch (ControlRefusal $error) {
                self::assertStringContainsString('deployment proof file', $error->getMessage());
            }
            $this->publishInstalledFpmProof([
                'site-a' => $this->productionConfigs[$this->configSha256],
                'site-b' => $this->productionConfigs[$secondarySha256],
            ]);
            self::assertSame(1, file_put_contents(
                $this->scratch . '/fail-storage-' . $secondarySha256,
                '1'
            ));
            try {
                $serviceB->preflight();
                self::fail('worker B local storage drift retained readiness');
            } catch (ControlRefusal $error) {
                self::assertStringContainsString('storage preflight', $error->getMessage());
            }
            self::assertSame(
                'duo-cloud-production-preflight-receipt/v1',
                $serviceA->assertReadyToServe()['format']
            );
            try {
                $serviceB->assertReadyToServe();
                self::fail('failed worker B retained its local receipt');
            } catch (ControlRefusal $error) {
                self::assertStringContainsString('receipt is absent, stale, or changed', $error->getMessage());
            }
            self::assertSame('host_ready', $refresh->refresh()['state']);
            self::assertSame(
                'duo-cloud-production-preflight-receipt/v1',
                $serviceA->assertReadyToServe()['format']
            );

            $hostPath = $this->hostPreflightRoot . '/receipt.json';
            $host = CanonicalJson::decodeObject((string) file_get_contents($hostPath));
            $host['issued_at'] = time() - 901;
            $host['expires_at'] = time() - 1;
            self::assertNotFalse(file_put_contents($hostPath, CanonicalJson::encode($host) . "\n"));
            self::assertTrue(chmod($hostPath, 0600));
            foreach ([$serviceA, $serviceB] as $service) {
            try {
                    $service->assertReadyToServe();
                self::fail('expired host receipt did not invalidate worker readiness');
            } catch (ControlRefusal $error) {
                self::assertStringContainsString('host preflight receipt', $error->getMessage());
            }
            }

            self::assertTrue(unlink($this->scratch . '/fail-storage-' . $secondarySha256));
            self::assertSame('host_ready', $refresh->refresh()['state']);
            self::assertSame('ready', $serviceB->preflight()['state']);
            self::assertSame(1, file_put_contents(
                $this->scratch . '/fail-route-' . $secondarySha256,
                '1'
            ));
            try {
                $serviceB->preflight();
                self::fail('worker B route failure retained readiness');
            } catch (ControlRefusal $error) {
                self::assertStringContainsString('route worker preflight', $error->getMessage());
            }
            self::assertSame(
                'duo-cloud-production-preflight-receipt/v1',
                $serviceA->assertReadyToServe()['format']
            );
            self::assertSame('host_ready', $refresh->refresh()['state']);
            self::assertTrue(unlink($this->scratch . '/fail-route-' . $secondarySha256));
            self::assertSame(1, file_put_contents($this->scratch . '/fail-host-preflight', '1'));
            try {
                $refresh->refresh();
                self::fail('known host authority failure should refuse the fleet refresh');
            } catch (ControlRefusal $error) {
                self::assertStringContainsString('firewall reconcile', $error->getMessage());
            }
            foreach ([$serviceA, $serviceB] as $service) {
                try {
                    $service->assertReadyToServe();
                    self::fail('known host failure left a worker ready');
                } catch (ControlRefusal $error) {
                    self::assertStringContainsString('host preflight receipt', $error->getMessage());
                }
            }

            self::assertTrue(unlink($this->scratch . '/fail-host-preflight'));
            self::assertSame('host_ready', $refresh->refresh()['state']);
            $originalConfig = file_get_contents($this->configPath);
            self::assertIsString($originalConfig);
            try {
                self::assertSame(8, file_put_contents($this->configPath, "invalid\n"));
                self::assertTrue(chmod($this->configPath, 0600));
                try {
                    $refresh->refresh();
                    self::fail('invalid first fleet config retained shared host readiness');
                } catch (ControlRefusal $error) {
                    self::assertStringContainsString('differs from its pin', $error->getMessage());
                }
                $host = CanonicalJson::decodeObject((string) file_get_contents(
                    $this->hostPreflightRoot . '/receipt.json'
                ));
                self::assertSame('invalid', $host['state']);
            } finally {
                self::assertSame(
                    strlen($originalConfig),
                    file_put_contents($this->configPath, $originalConfig)
                );
                self::assertTrue(chmod($this->configPath, 0600));
            }
        } finally {
            $this->remove($secondaryRoot);
        }
    }

    /**
     * @return array{
     *     pipes:array{0:resource,1:resource,2:resource},
     *     process:resource
     * }
     */
    private function startAuthorityLifecycle(string $authority): array {
        self::assertContains($authority, ['firewall', 'route']);
        $pipes = [];
        $process = proc_open([
            $this->scratch . '/' . $authority,
            'bind',
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, ['LANG' => 'C'], ['bypass_shell' => true]);
        self::assertIsResource($process);
        self::assertCount(3, $pipes);
        self::assertIsResource($pipes[0]);
        self::assertIsResource($pipes[1]);
        self::assertIsResource($pipes[2]);
        self::assertSame("locked\n", fgets($pipes[1]));
        return ['pipes' => $pipes, 'process' => $process];
    }

    /**
     * @param array{
     *     pipes:array{0:resource,1:resource,2:resource},
     *     process:resource
     * } $holder
     */
    private function stopAuthorityLifecycle(array $holder): void {
        fclose($holder['pipes'][0]);
        $stdout = stream_get_contents($holder['pipes'][1]);
        $stderr = stream_get_contents($holder['pipes'][2]);
        fclose($holder['pipes'][1]);
        fclose($holder['pipes'][2]);
        self::assertSame(0, proc_close($holder['process']));
        self::assertSame('', $stdout);
        self::assertSame('', $stderr);
    }

    /** @return array{string,string} */
    private function configuration(): array {
        $remoteUrl = 'https://git.example.test/tenant-a/site-a.git';
        $remoteDigest = hash('sha256', "duo-cloud-repository-remote-url/v1\0$remoteUrl");
        $engine = $this->executable('engine');
        $firewall = $this->executable('firewall');
        $git = $this->executable('git', $remoteDigest);
        $route = $this->executable('route');
        $storage = $this->executable('storage');
        $keypair = sodium_crypto_sign_keypair();
        $signing = $this->key('response', sodium_crypto_sign_secretkey($keypair));
        $device = $this->key('device', random_bytes(32));
        $public = $this->key('controller-public', random_bytes(32));
        $contract = DUO_REPO_ROOT . '/cloud/deploy/runtime-image-contract.json';
        $seccomp = DUO_REPO_ROOT . '/cloud/deploy/seccomp-profile.json';
        $document = [
            'controller_keys' => [[
                'key_id' => 'origin-controller-key',
                'public_key' => $public,
                'site_id' => 'site-a',
                'tenant_id' => 'tenant-a',
            ]],
            'format' => ProductionConfig::FORMAT,
            'host_preflight_root' => $this->hostPreflightRoot,
            'host_durable_paths' => [
                'authority_config_root' => $this->scratch,
                'control_proxy_config_root' => $this->scratch,
                'control_proxy_data_root' => $this->scratch,
                'firewall_authority_state_root' => $this->scratch . '/state',
                'route_authority_state_root' => $this->scratch . '/state',
                'route_proxy_config_root' => $this->scratch,
                'route_proxy_data_root' => $this->scratch,
                'storage_authority_state_root' => $this->scratch . '/state',
            ],
            'runtime' => [
                'container_engine' => $engine,
                'firewall_authority' => $firewall,
                'git' => $git,
                'image' => 'registry.example.test/duo/wordpress@sha256:' . hash('sha256', 'image'),
                'memory_bytes' => 536870912,
                'nano_cpus' => 500000000,
                'pids_limit' => 128,
                'platform_fingerprint_sha256' => hash('sha256', 'platform'),
                'preview_domain' => 'preview.example.test',
                'process_launcher' => [
                    'path' => PHP_BINARY,
                    'sha256' => (string) hash_file('sha256', PHP_BINARY),
                ],
                'process_timeout_seconds' => 30,
                'repository_remote' => [
                    'allowed_ref_prefix' => 'refs/heads/duo-preview/',
                    'credential_helper' => $git['path'],
                    'credential_helper_sha256' => $git['sha256'],
                    'name' => 'duo-cloud',
                    'url' => $remoteUrl,
                    'url_sha256' => $remoteDigest,
                ],
                'repository_source' => $this->scratch . '/repo',
                'review_receipt_sha256' => hash('sha256', 'review'),
                'route_authority' => $route,
                'runtime_contract_file' => $contract,
                'runtime_contract_sha256' => hash_file('sha256', $contract),
                'seccomp_profile_file' => $seccomp,
                'seccomp_profile_sha256' => hash_file('sha256', $seccomp),
                'snapshot_object_root' => $this->scratch . '/snapshots',
                'storage_authority' => $storage,
                'workload_repository_path' => '/srv/duo/repository',
            ],
            'service' => [
                'device_digest_key' => $device,
                'response_key_id' => 'service-key-v1',
                'response_signing_key' => $signing,
            ],
            'state_root' => $this->scratch . '/state',
        ];
        return $this->writeConfiguration($document, 'service.json');
    }

    /** @param array<string,mixed> $document @return array{string,string} */
    private function writeConfiguration(array $document, string $name): array {
        $runtimeProof = $this->runtimeImageProof(
            $document['runtime']['image'],
            $document['runtime']['seccomp_profile_sha256']
        );
        $document['runtime']['review_receipt_sha256'] = $runtimeProof['proof_receipt_sha256'];
        $bytes = CanonicalJson::encode($document) . "\n";
        $path = $this->scratch . '/' . $name;
        self::assertNotFalse(file_put_contents($path, $bytes));
        self::assertTrue(chmod($path, 0600));
        $sha256 = hash('sha256', $bytes);
        $config = ProductionConfig::inspectForFleet($path, $sha256);
        $this->productionConfigs[$sha256] = $config;
        (new ProductionDeploymentProofs($config))->publishRuntimeImage($runtimeProof);
        return [$path, $sha256];
    }

    /** @return array<string,mixed> */
    private function runtimeImageProof(string $image, string $seccompSha256): array {
        $proof = [
            'background_process_fence' => 'real-runner-kill-dead-restart-ready-no-orphan-pid-or-write',
            'cleanup' => 'exact',
            'format' => 'duo-cloud-runtime-image-proof/v1',
            'fresh_volume_ownership' => '10001:10001:0700',
            'helpers' => 'executable',
            'immutable_health' => 'duo-cloud-preview-runtime-health/v1',
            'image_id' => 'sha256:' . hash('sha256', "production-test-image-id\0$image"),
            'immutable_image' => $image,
            'implicit_volumes' => 0,
            'runtime_ready' => true,
            'seccomp_ioctl_policy' => 'native-compat-project-mutation-denied-control-allowed',
            'seccomp_profile_sha256' => $seccompSha256,
            'secrets_readable_as' => '10001:10001',
            'wordpress_policy' => 'staging-cron-and-file-mods-disabled',
        ];
        $proof['proof_receipt_sha256'] = hash(
            'sha256',
            "duo-cloud-runtime-image-proof-receipt/v1\0" . CanonicalJson::encode($proof)
        );
        return $proof;
    }

    /** @return array<string,mixed> */
    private function linuxHostProof(ProductionConfig $config): array {
        $runtime = $config->runtime();
        $artifacts = [
            'container_engine_sha256' => $runtime['container_engine']['sha256'],
            'firewall_authority_sha256' => $runtime['firewall_authority']['sha256'],
            'php_closure_sha256' => $this->linuxHostPhpClosureSha256(),
            'process_launcher_sha256' => $runtime['process_launcher']['sha256'],
            'seccomp_profile_sha256' => $runtime['seccomp_profile_sha256'],
            'storage_authority_sha256' => $runtime['storage_authority']['sha256'],
            'verifier_sha256' => (string) hash_file(
                'sha256',
                DUO_REPO_ROOT . '/cloud/deploy/verify-linux-host-boundaries.php'
            ),
        ] + $this->installedConfigurationSha256s;
        ksort($artifacts, SORT_STRING);
        return [
            'apparmor' => 'docker-default-enforced',
            'artifact_sha256s' => $artifacts,
            'cgroup' => [
                'success_descendant' => 'reaped',
                'timeout_descendant' => 'reaped',
            ],
            'cleanup' => 'exact',
            'configuration_file' => $config->configPath(),
            'configuration_sha256' => $config->sha256(),
            'container_engine_path' => $runtime['container_engine']['path'],
            'container_engine_sha256' => $runtime['container_engine']['sha256'],
            'engine_version' => '29.4.2',
            'firewall' => [
                'apparmor' => 'docker-default-enforced',
                'dns_tcp' => 'denied',
                'dns_udp' => 'denied',
                'external_tcp' => 'denied',
                'gateway_tcp' => 'denied',
                'gateway_udp' => 'denied',
                'host_ingress' => 'exact-health',
                'multi_binding' => 'preserved',
            ],
            'format' => 'duo-cloud-linux-host-boundary-proof/v1',
            'image_id' => 'sha256:' . hash(
                'sha256',
                "production-test-image-id\0" . $runtime['image']
            ),
            'image_reference' => $runtime['image'],
            'production_ready' => true,
            'proof_scope' => 'production',
            'seccomp_profile_path' => $runtime['seccomp_profile_file'],
            'seccomp_profile_sha256' => $runtime['seccomp_profile_sha256'],
            'storage' => [
                'hard_bytes' => 67108864,
                'hard_inodes' => 1024,
                'ioctl_mutation' => 'denied',
                'proof_receipt_sha256' => hash('sha256', 'production-storage-proof'),
                'quota_state' => 'hard-enforced',
            ],
            'storage_worker_root' => $config->workerRoot(),
            'synthetic_rotation_configuration_sha256' => hash(
                'sha256',
                "duo-cloud-linux-host-boundary-proof-synthetic-rotation/v1\0"
                    . $config->sha256()
            ),
            'synthetic_rotation_scope' => 'firewall-multi-binding-only',
        ];
    }

    private function linuxHostPhpClosureSha256(): string {
        $digests = [];
        foreach (ProductionDeploymentProofs::linuxHostVerifierSourcePaths(
            DUO_REPO_ROOT . '/cloud/deploy'
        ) as $name => $path) {
            $digests[$name] = (string) hash_file('sha256', $path);
        }
        return ProductionDeploymentProofs::linuxHostVerifierClosureSha256($digests);
    }

    private function installHostAuthorityConfigurationFixture(): void {
        $descriptors = $this->scratch . '/installed-authority-artifacts';
        self::assertTrue(mkdir($descriptors, 0700));
        $descriptor = static function (string $name, ?string $bytes = null) use ($descriptors): array {
            $path = $descriptors . '/' . $name;
            $bytes ??= "$name\n";
            self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
            self::assertTrue(chmod($path, 0700));
            return ['path' => $path, 'sha256' => hash('sha256', $bytes)];
        };
        $interpreter = $descriptor('php');
        $sudo = $descriptor('sudo');
        $closure = $descriptor('authority-closure.php', "<?php\n");
        $firewallAuthority = $descriptor(
            'firewall-authority',
            '#!' . $interpreter['path'] . "\n<?php\n"
        );
        $storageAuthority = $descriptor(
            'storage-authority',
            '#!' . $interpreter['path'] . "\n<?php\n"
        );
        $client = static function (array $authority) use (
            $closure,
            $interpreter,
            $sudo
        ): array {
            $authorityClosure = [$authority, $closure];
            usort(
                $authorityClosure,
                static fn (array $left, array $right): int => strcmp($left['path'], $right['path'])
            );
            return [
                'authority' => $authority,
                'closure' => $authorityClosure,
                'format' => 'duo-cloud-firewall-client-config/v1',
                'interpreter' => $interpreter,
                'process_timeout_seconds' => 60,
                'sudo' => $sudo,
            ];
        };
        $documents = [
            'firewall-authority' => [
                'container_engine' => $descriptor('firewall-engine'),
                'format' => 'duo-cloud-host-firewall-config/v1',
                'ip' => $descriptor('ip'),
                'nft' => $descriptor('nft'),
                'principals' => [],
                'process_timeout_seconds' => 5,
                'state_root' => $this->scratch,
            ],
            'firewall-client' => $client($firewallAuthority),
            'storage-authority' => [
                'backing_device' => '/dev/fixture',
                'cipher' => 'aes-xts-plain64',
                'configuration_sha256s' => [],
                'container_engine' => $descriptor('storage-engine'),
                'cryptsetup' => $descriptor('cryptsetup'),
                'dmsetup' => $descriptor('dmsetup'),
                'docker_root' => $this->scratch,
                'durable_paths' => [],
                'filesystem_uuid' => '00000000-0000-4000-8000-000000000000',
                'findmnt' => $descriptor('findmnt'),
                'format' => 'duo-cloud-host-storage-config/v1',
                'key_location' => 'keyring',
                'key_size_bits' => 512,
                'limits' => [],
                'luks_uuid' => '00000000-0000-4000-8000-000000000000',
                'mapper_name' => 'fixture',
                'mapper_path' => '/dev/mapper/fixture',
                'mapper_size_sectors' => 131072,
                'mapper_uuid' => 'fixture',
                'mountpoint' => $this->scratch,
                'payload_offset_sectors' => 8,
                'process_timeout_seconds' => 10,
                'sector_size_bytes' => 512,
                'service_uids' => [],
                'state_root' => $this->scratch,
                'worker_roots' => [],
                'xfs_io' => $descriptor('xfs-io'),
                'xfs_quota' => $descriptor('xfs-quota'),
            ],
            'storage-client' => $client($storageAuthority),
        ];
        foreach ($documents as $name => $document) {
            $bytes = CanonicalJson::encode($document) . "\n";
            $sha256 = hash('sha256', $bytes);
            self::assertSame(
                strlen($bytes),
                file_put_contents($this->scratch . "/$name.json", $bytes)
            );
            self::assertTrue(chmod($this->scratch . "/$name.json", 0600));
            self::assertSame(
                65,
                file_put_contents($this->scratch . "/$name.sha256", $sha256 . "\n")
            );
            self::assertTrue(chmod($this->scratch . "/$name.sha256", 0600));
            $this->installedConfigurationSha256s[
                str_replace('-', '_', $name) . '_configuration_sha256'
            ] = $sha256;
        }
        ksort($this->installedConfigurationSha256s, SORT_STRING);
    }

    /** @param array<string,ProductionConfig> $configs */
    private function publishInstalledFpmProof(array $configs): void {
        $artifacts = [
            'control_unit_sha256' => (string) hash_file(
                'sha256',
                DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-control-caddy.service'
            ),
            'php_fpm_ini_sha256' => (string) hash_file(
                'sha256',
                DUO_REPO_ROOT . '/cloud/deploy/php-fpm.ini'
            ),
            'php_fpm_pool_template_sha256' => (string) hash_file(
                'sha256',
                DUO_REPO_ROOT . '/cloud/deploy/php-fpm-pool.conf.example'
            ),
            'php_fpm_unit_sha256' => (string) hash_file(
                'sha256',
                DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-php-fpm@.service'
            ),
            'verifier_sha256' => (string) hash_file(
                'sha256',
                DUO_REPO_ROOT . '/cloud/deploy/verify-installed-fpm-ingress.php'
            ),
        ];
        $closureDigests = [];
        foreach (ProductionDeploymentProofs::installedFpmVerifierSourcePaths(
            DUO_REPO_ROOT . '/cloud/deploy'
        ) as $name => $path) {
            $closureDigests[$name] = (string) hash_file('sha256', $path);
        }
        $artifacts['php_closure_sha256'] =
            ProductionDeploymentProofs::installedFpmVerifierClosureSha256($closureDigests);
        ksort($artifacts, SORT_STRING);
        $workers = [];
        $configurationSha256s = [];
        $index = 0;
        foreach ($configs as $workerId => $config) {
            $configurationSha256s[] = $config->sha256();
            $workers[] = [
                'control_host' => "$workerId.control.example.test",
                'fpm_configuration_sha256' => hash('sha256', "fpm-$workerId"),
                'live_route_status' => 403,
                'process' => ['gid' => 10001, 'pid' => 1000 + $index, 'uid' => 10001],
                'socket' => [
                    'group' => 10001,
                    'inode' => 2000 + $index,
                    'mode' => '0660',
                    'owner' => 10001,
                    'path' => "/run/duo-cloud/$workerId/php-fpm.sock",
                ],
                'worker_configuration_sha256' => $config->sha256(),
                'worker_id' => $workerId,
            ];
            $index++;
        }
        sort($configurationSha256s, SORT_STRING);
        $proof = [
            'artifact_sha256s' => $artifacts,
            'configuration_sha256' => hash('sha256', CanonicalJson::encode($configurationSha256s)),
            'control_caddy' => [
                'configuration_sha256' => hash('sha256', 'test-control-caddy'),
                'gid' => 10002,
                'pid' => 900,
                'supplementary_socket_gid' => 10001,
                'uid' => 10002,
            ],
            'format' => 'duo-cloud-installed-fpm-ingress-proof/v1',
            'max_wall_seconds' => 45,
            'php_fpm_ini_sha256' => $artifacts['php_fpm_ini_sha256'],
            'state' => 'ready',
            'workers' => $workers,
        ];
        $proof['proof_receipt_sha256'] = hash(
            'sha256',
            "duo-cloud-installed-fpm-ingress-proof-receipt/v1\0"
                . CanonicalJson::encode($proof)
        );
        ProductionDeploymentProofs::publishInstalledFpm(
            $this->hostPreflightRoot,
            $proof,
            $configurationSha256s,
            function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid(),
            function_exists('posix_getegid') ? posix_getegid() : (int) getmygid(),
            null,
            null,
            DUO_REPO_ROOT . '/cloud/deploy'
        );
    }

    /** @return array<string,mixed> */
    private function configurationDocument(): array {
        $bytes = file_get_contents($this->configPath);
        self::assertIsString($bytes);
        return CanonicalJson::decodeObject($bytes);
    }

    /** @return array<string,mixed> */
    private function authorityState(string $name): array {
        $bytes = file_get_contents($this->scratch . '/state/authority/' . $name);
        self::assertIsString($bytes);
        return CanonicalJson::decodeObject($bytes);
    }

    /** @return array{exit:int,stderr:string,stdout:string} */
    private function execute(
        string $name,
        array $arguments,
        ?string $configPath = null,
        ?string $configSha256 = null
    ): array {
        $path = DUO_REPO_ROOT . '/cloud/bin/' . $name;
        // The FastCGI source cannot carry a shebang: FPM would emit it before
        // the constant public response. CLI-focused tests invoke that one
        // source through the pinned interpreter explicitly.
        $command = $name === 'duo-cloud-http'
            ? array_merge([PHP_BINARY, $path], $arguments)
            : array_merge([$path], $arguments);
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'rb'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, null, [
            'DUO_CLOUD_CONFIG_FILE' => $configPath ?? $this->configPath,
            'DUO_CLOUD_CONFIG_SHA256' => $configSha256 ?? $this->configSha256,
            'LANG' => 'C',
            'PATH' => (string) getenv('PATH'),
        ], ['bypass_shell' => true]);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        self::assertIsString($stdout);
        self::assertIsString($stderr);
        return ['exit' => $exit, 'stderr' => $stderr, 'stdout' => $stdout];
    }

    /** @return array{path:string,sha256:string} */
    private function executable(string $name, ?string $remoteDigest = null): array {
        $path = $this->scratch . '/' . $name;
        $header = '#!' . PHP_BINARY . "\n<?php\ndeclare(strict_types=1);\n";
        $bytes = $header . match ($name) {
            'engine' => <<<'PHP'
$action = $_SERVER['argv'][1] ?? '';
if ($action === 'info') {
    fwrite(STDOUT, "{\"ServerVersion\":\"29.4.2\",\"SecurityOptions\":[\"name=apparmor\",\"name=seccomp,profile=builtin\"],\"DockerRootDir\":\"/encrypted/docker\"}\n");
    exit(0);
}
if ($action === 'run') {
    $arguments = array_slice($_SERVER['argv'], 2);
    $pair = static function (string $flag, string $value) use ($arguments): bool {
        for ($index = 0; $index + 1 < count($arguments); $index++) {
            if ($arguments[$index] === $flag && $arguments[$index + 1] === $value) {
                return true;
            }
        }
        return false;
    };
    $shell = $arguments[count($arguments) - 1] ?? '';
    if (!in_array('--read-only', $arguments, true)
        || !$pair('--pull', 'never') || !$pair('--network', 'none')
        || !$pair('--cap-drop', 'ALL') || !$pair('--security-opt', 'no-new-privileges=true')
        || !array_filter($arguments, static fn (string $value): bool => str_starts_with($value, 'seccomp=/'))
        || !$pair('--pids-limit', '32') || !$pair('--memory', '134217728')
        || !$pair('--memory-swap', '134217728') || !$pair('--shm-size', '8388608')
        || !$pair('--ulimit', 'nofile=256:256') || !$pair('--user', '10001:10001')
        || !is_string($shell) || !str_contains($shell, "^Seccomp:")
        || !str_contains($shell, "docker-default (enforce)")
        || !str_contains($shell, '/opt/duo/bin/containment-canary')) {
        exit(70);
    }
    fwrite(STDOUT, "duo-cloud-seccomp-ioctl-canary/v1\n");
    exit(0);
}
exit(70);
PHP,
            'firewall' => str_replace(
                [
                    '__AUTHORITY_LOCK__', '__FALSE_BUSY__', '__GLOBAL_LOG__',
                    '__HOST_FAIL__', '__REGISTRY__',
                ],
                [
                    $this->scratch . '/firewall-authority.lock',
                    $this->scratch . '/false-busy-firewall',
                    $this->scratch . '/global-preflight.log',
                    $this->scratch . '/fail-host-preflight',
                    $this->scratch . '/host-registry.json',
                ],
                <<<'PHP'
$action = $_SERVER['argv'][1] ?? '';
$authorityLock = @fopen('__AUTHORITY_LOCK__', 'c+b');
if (!is_resource($authorityLock) || !chmod('__AUTHORITY_LOCK__', 0600)) {
    exit(70);
}
if ($action === 'bind') {
    if (!flock($authorityLock, LOCK_EX)) {
        exit(70);
    }
    fwrite(STDOUT, "locked\n");
    fflush(STDOUT);
    stream_get_contents(STDIN);
    flock($authorityLock, LOCK_UN);
    fclose($authorityLock);
    exit(0);
}
if (in_array($action, ['preflight', 'reconcile'], true)) {
    $wouldBlock = 0;
    if (!flock($authorityLock, LOCK_EX | LOCK_NB, $wouldBlock)) {
        fclose($authorityLock);
        if ($wouldBlock === 1) {
            fwrite(STDERR, "duo-cloud-firewall-client: busy\n");
            exit(75);
        }
        exit(70);
    }
}
if ($action === 'reconcile' && is_file('__FALSE_BUSY__')) {
    fwrite(STDERR, "duo-cloud-firewall-client: not-busy\n");
    exit(75);
}
if ($action !== 'worker-preflight') {
    file_put_contents('__GLOBAL_LOG__', "firewall:$action\n", FILE_APPEND);
}
if (is_file('__HOST_FAIL__')) {
    exit(70);
}
$registry = json_decode((string) @file_get_contents('__REGISTRY__'), true);
if (!is_array($registry) || !is_array($registry['firewall_principals'] ?? null)) {
    exit(70);
}
if ($action === 'reconcile') {
    fwrite(STDOUT, json_encode(['bindings' => 0, 'format' => 'duo-cloud-host-firewall-state/v1',
        'principals' => $registry['firewall_principals'], 'state' => 'reconciled'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}
if ($action === 'preflight') {
    fwrite(STDOUT, json_encode(['bindings' => 0, 'format' => 'duo-cloud-host-firewall-state/v1',
        'principals' => $registry['firewall_principals'], 'state' => 'ready'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}
if ($action === 'worker-preflight') {
    $configuration = $_SERVER['argv'][3] ?? null;
    if (($_SERVER['argv'][2] ?? null) !== '--config-sha256'
        || !is_string($configuration)
        || preg_match('/\A[a-f0-9]{64}\z/D', $configuration) !== 1) {
        exit(70);
    }
    fwrite(STDOUT, json_encode([
        'bindings' => 0,
        'bindings_sha256' => hash('sha256', "duo-cloud-host-firewall-worker-preflight-bindings/v1\0[]"),
        'configuration_sha256' => $configuration,
        'format' => 'duo-cloud-host-firewall-worker-preflight/v1',
        'state' => 'ready',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}
exit(70);
PHP
            ),
            'git' => str_replace('__REMOTE_DIGEST__', (string) $remoteDigest, <<<'PHP'
if (($_SERVER['argv'][1] ?? '') !== 'verify') {
    exit(70);
}
fwrite(STDOUT, "{\"format\":\"duo-cloud-git-credential-provider-status/v1\",\"ready\":true,\"remote_url_sha256\":\"__REMOTE_DIGEST__\"}\n");
PHP),
            'route' => str_replace(
                [
                    '__AUTHORITY_LOCK__', '__DELAY_WORKER__', '__FAIL_ROUTE_ROOT__',
                    '__GLOBAL_LOG__', '__REGISTRY__',
                ],
                [
                    $this->scratch . '/route-authority.lock',
                    $this->scratch . '/delay-worker-preflight',
                    $this->scratch . '/fail-route-',
                    $this->scratch . '/global-preflight.log',
                    $this->scratch . '/host-registry.json',
                ],
                <<<'PHP'
$action = $_SERVER['argv'][1] ?? '';
$authorityLock = @fopen('__AUTHORITY_LOCK__', 'c+b');
if (!is_resource($authorityLock) || !chmod('__AUTHORITY_LOCK__', 0600)) {
    exit(70);
}
if ($action === 'bind') {
    if (!flock($authorityLock, LOCK_EX)) {
        exit(70);
    }
    fwrite(STDOUT, "locked\n");
    fflush(STDOUT);
    stream_get_contents(STDIN);
    flock($authorityLock, LOCK_UN);
    fclose($authorityLock);
    exit(0);
}
if (in_array($action, ['host-preflight', 'worker-preflight'], true)) {
    $wouldBlock = 0;
    if (!flock($authorityLock, LOCK_EX | LOCK_NB, $wouldBlock)) {
        fclose($authorityLock);
        if ($wouldBlock === 1) {
            fwrite(STDERR, "duo-cloud-route-authority: busy\n");
            exit(75);
        }
        exit(70);
    }
}
if ($action !== 'worker-preflight') {
    file_put_contents('__GLOBAL_LOG__', "route:$action\n", FILE_APPEND);
}
$registry = json_decode((string) @file_get_contents('__REGISTRY__'), true);
if (!is_array($registry) || !is_array($registry['route_principals'] ?? null)) {
    exit(70);
}
if ($action === 'host-preflight') {
    fwrite(STDOUT, json_encode(['format' => 'duo-cloud-route-authority-host-preflight/v1',
        'principals' => $registry['route_principals'], 'routes' => 0, 'state' => 'ready'],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}
if ($action === 'worker-preflight') {
    if (is_file('__DELAY_WORKER__')) {
        usleep(250000);
    }
    $configuration = $_SERVER['argv'][3] ?? null;
    $reviewed = $_SERVER['argv'][5] ?? null;
    if (($_SERVER['argv'][2] ?? null) !== '--config-sha256'
        || ($_SERVER['argv'][4] ?? null) !== '--reviewed-base-sha256'
        || !is_string($configuration) || !is_string($reviewed)
        || preg_match('/\A[a-f0-9]{64}\z/D', $configuration) !== 1
        || preg_match('/\A[a-f0-9]{64}\z/D', $reviewed) !== 1) {
        exit(70);
    }
    if (is_file('__FAIL_ROUTE_ROOT__' . $configuration)) {
        exit(70);
    }
    fwrite(STDOUT, json_encode([
        'configuration_sha256' => $configuration,
        'format' => 'duo-cloud-route-authority-worker-preflight/v1',
        'reviewed_base_sha256' => $reviewed,
        'route_bindings_sha256' => hash('sha256', "duo-cloud-route-authority-worker-preflight-bindings/v1\0[]"),
        'routes' => 0,
        'state' => 'ready',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}
exit(70);
PHP
            ),
            'storage', 'storage-b' => str_replace(
                ['__FAIL_ROOT__', '__REGISTRY__'],
                [$this->scratch . '/fail-storage-', $this->scratch . '/host-registry.json'],
                <<<'PHP'
$action = $_SERVER['argv'][1] ?? '';
$registry = json_decode((string) @file_get_contents('__REGISTRY__'), true);
if ($action === 'fleet-preflight') {
    if (!is_array($registry) || !is_array($registry['storage_workers'] ?? null)) {
        exit(70);
    }
    fwrite(STDOUT, json_encode(['bindings' => 0, 'docker_root' => '/encrypted/docker',
        'durable_paths' => [],
        'format' => 'duo-cloud-xfs-quota-fleet-preflight/v1', 'state' => 'ready',
        'workers' => $registry['storage_workers']],
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
    exit(0);
}
$configuration = (string) ($_SERVER['argv'][3] ?? '');
if (is_file('__FAIL_ROOT__' . $configuration)) {
    exit(70);
}
if ($action !== 'preflight') {
    exit(70);
}
if (($_SERVER['argv'][2] ?? '') !== '--config-sha256'
    || preg_match('/\A[a-f0-9]{64}\z/D', $configuration) !== 1) {
    exit(70);
}
$paths = [];
for ($index = 4; $index < count($_SERVER['argv']); $index += 2) {
    if (($_SERVER['argv'][$index] ?? '') !== '--required-path'
        || !is_string($_SERVER['argv'][$index + 1] ?? null)) {
        exit(70);
    }
    $paths[] = $_SERVER['argv'][$index + 1];
}
if ($paths === []) {
    exit(70);
}
$canonical = json_encode($paths, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
$digest = hash('sha256', "duo-cloud-required-durable-paths/v1\0" . $canonical);
$workerRoot = null;
foreach ($paths as $path) {
    if (basename($path) === 'repo') {
        $workerRoot = dirname($path);
    }
}
if (!is_string($workerRoot)) {
    exit(70);
}
$workerDigest = hash('sha256', "duo-cloud-worker-root/v1\0" . $workerRoot);
fwrite(STDOUT, '{"bindings":0,"docker_root":"/encrypted/docker","format":"duo-cloud-xfs-quota-storage-state/v1","required_paths_sha256":"'
    . $digest . '","state":"ready","worker_root_sha256":"' . $workerDigest . '"}' . "\n");
PHP
            ),
            default => "exit(70);\n",
        };
        $bytes .= "\n";
        self::assertNotFalse(file_put_contents($path, $bytes));
        self::assertTrue(chmod($path, 0700));
        return ['path' => $path, 'sha256' => hash('sha256', $bytes)];
    }

    /** @return array{path:string,sha256:string} */
    private function key(string $name, string $bytes): array {
        $path = $this->scratch . '/' . $name . '.key';
        $encoded = base64_encode($bytes) . "\n";
        self::assertNotFalse(file_put_contents($path, $encoded));
        self::assertTrue(chmod($path, 0600));
        return ['path' => $path, 'sha256' => hash('sha256', $encoded)];
    }

    private function assertOperatorDiagnostic(string $bytes, string $boundary): void {
        $diagnostic = CanonicalJson::decodeObject($bytes);
        self::assertSame('duo-cloud-operator-diagnostic/v1', $diagnostic['format']);
        self::assertSame($boundary, $diagnostic['boundary']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\z/D', $diagnostic['correlation_id']);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{64}\z/D', $diagnostic['reason_sha256']);
        self::assertSame(CanonicalJson::encode($diagnostic) . "\n", $bytes);
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
