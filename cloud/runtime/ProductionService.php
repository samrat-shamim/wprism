<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/ContainerCommandRunner.php';
require_once __DIR__ . '/ExpiredPreviewJanitor.php';
require_once __DIR__ . '/OperatorDiagnostics.php';
require_once __DIR__ . '/ProductionConfig.php';
require_once __DIR__ . '/ProductionDeploymentProofs.php';
require_once __DIR__ . '/ProductionHostPreflightReceipt.php';
require_once __DIR__ . '/ProductionPreflightReceipt.php';
require_once dirname(__DIR__) . '/src/CloudHttpRouter.php';
require_once dirname(__DIR__) . '/src/CommandRunner.php';
require_once dirname(__DIR__) . '/src/ContainerWorkloadRuntime.php';
require_once dirname(__DIR__) . '/src/ControlAuthority.php';
require_once dirname(__DIR__) . '/src/FileAuthorityStore.php';
require_once dirname(__DIR__) . '/src/HostAuthorityBusy.php';
require_once dirname(__DIR__) . '/src/MutationAuthorityPublisher.php';
require_once dirname(__DIR__) . '/src/OriginAuthority.php';
require_once dirname(__DIR__) . '/src/OriginControllerGateway.php';
require_once dirname(__DIR__) . '/src/OriginFileBlobStore.php';
require_once dirname(__DIR__) . '/src/PreviewLifecycleGateway.php';
require_once dirname(__DIR__) . '/src/PreviewSlotLifecycle.php';

/** @internal Cleanup authority must never acquire a workload-command capability. */
final class ExpiredPreviewJanitorCommandRunner implements CommandRunner {
    public function run(array $request): array {
        throw new ControlRefusal('expired preview janitor cannot execute workload commands');
    }
}

/** Dependency-free assembly for one tenant/site production worker. */
final class ProductionService {
    private const CONTROLLER_KEY_INSTALLATION_FORMAT = 'duo-cloud-controller-key-installation/v1';
    private const CONTROLLER_KEY_LIMIT = 10000;
    public const PREFLIGHT_DEADLINE_SECONDS = 15;

    private function __construct(
        private ProductionConfig $config,
        private CloudHttpRouter $router,
        private ExpiredPreviewJanitor $janitor,
        private OriginAuthority $origin,
        private PreviewSlotLifecycle $lifecycle,
        private ControlAuthority $control,
        private FileAuthorityStore $controlStore,
        private FileAuthorityStore $controllerKeyInstallationStore,
        private ContainerArgvProcessRunner $processRunner,
        private ProductionDeploymentProofs $deploymentProofs,
        private ProductionHostPreflightReceipt $hostPreflightReceipt,
        private ProductionPreflightReceipt $preflightReceipt
    ) {}

    public static function fromConfigFile(
        string $path,
        string $expectedSha256,
        bool $requireCgroup = true
    ): self {
        $config = ProductionConfig::load($path, $expectedSha256);
        $runtimeConfig = $config->runtime();
        $serviceConfig = $config->service();
        $remote = $runtimeConfig['repository_remote'];

        $authorityRoot = $config->stateDirectory('authority');
        $runtimeRoot = $config->stateDirectory('runtime');
        $originBlobRoot = $config->stateDirectory('origin-blobs');

        $responseSecret = $config->keyBytes(
            $serviceConfig['response_signing_key'],
            SODIUM_CRYPTO_SIGN_SECRETKEYBYTES,
            SODIUM_CRYPTO_SIGN_SECRETKEYBYTES,
            'response signing key'
        );
        $deviceDigestKey = $config->keyBytes(
            $serviceConfig['device_digest_key'],
            32,
            64,
            'device digest key'
        );

        try {
            $processRunner = new NativeContainerArgvProcessRunner(
                $runtimeConfig['process_timeout_seconds'],
                $runtimeConfig['process_launcher']['path'],
                true,
                $requireCgroup
            );
            $runtime = new ContainerWorkloadRuntime(
                $processRunner,
                $runtimeConfig['container_engine']['path'],
                $runtimeConfig['route_authority']['path'],
                $runtimeConfig['firewall_authority']['path'],
                $runtimeConfig['storage_authority']['path'],
                $runtimeConfig['git']['path'],
                $runtimeConfig['image'],
                $runtimeConfig['seccomp_profile_file'],
                $runtimeConfig['seccomp_profile_sha256'],
                $config->sha256(),
                $runtimeConfig['platform_fingerprint_sha256'],
                $runtimeConfig['review_receipt_sha256'],
                $runtimeRoot,
                $runtimeConfig['repository_source'],
                $remote['name'],
                $remote['url'],
                $remote['url_sha256'],
                $remote['allowed_ref_prefix'],
                $remote['credential_helper'],
                $remote['credential_helper_sha256'],
                $runtimeConfig['snapshot_object_root'],
                $runtimeConfig['preview_domain'],
                $runtimeConfig['workload_repository_path'],
                $runtimeConfig['memory_bytes'],
                $runtimeConfig['nano_cpus'],
                $runtimeConfig['pids_limit']
            );
            $reviewedBase = $runtime->reviewedBaseContainmentDescriptor();
            $commandRunner = new ContainerCommandRunner(
                $processRunner,
                $runtimeConfig['container_engine']['path'],
                $runtimeConfig['image'],
                $config->sha256(),
                $reviewedBase['descriptor_sha256'],
                $runtimeRoot,
                $runtimeConfig['process_timeout_seconds']
            );
            $controlStore = new FileAuthorityStore($authorityRoot . '/control.json');
            $control = new ControlAuthority(
                $controlStore,
                $commandRunner,
                $serviceConfig['response_key_id'],
                $responseSecret
            );

            $origin = new OriginAuthority(
                new FileAuthorityStore($authorityRoot . '/origin.json'),
                new OriginFileBlobStore($originBlobRoot),
                $serviceConfig['response_key_id'],
                $responseSecret,
                $deviceDigestKey
            );
            $lifecycleStore = new FileAuthorityStore($authorityRoot . '/lifecycle.json');
            $lifecycle = new PreviewSlotLifecycle(
                $lifecycleStore,
                $runtime,
                new ControlAuthorityMutationPublisher($control)
            );
            $lifecycleGateway = new PreviewLifecycleGateway(
                $lifecycle,
                $control,
                $serviceConfig['response_key_id'],
                $responseSecret
            );
            $originController = new OriginControllerGateway(
                $origin,
                $control,
                new FileAuthorityStore($authorityRoot . '/origin-controller.json'),
                $serviceConfig['response_key_id'],
                $responseSecret
            );
            $diagnostics = new OperatorDiagnostics();
            $router = new CloudHttpRouter(
                $lifecycleGateway,
                $control,
                $origin,
                $originController,
                [$diagnostics, 'record']
            );
            $requiredPathsSha256 = hash(
                'sha256',
                "duo-cloud-required-durable-paths/v1\0"
                    . CanonicalJson::encode($config->requiredDurablePaths())
            );

            return new self(
                $config,
                $router,
                new ExpiredPreviewJanitor($lifecycleStore, $lifecycle),
                $origin,
                $lifecycle,
                $control,
                $controlStore,
                new FileAuthorityStore(
                    $config->stateDirectory('preflight') . '/controller-keys.json'
                ),
                $processRunner,
                new ProductionDeploymentProofs($config),
                new ProductionHostPreflightReceipt(
                    new FileAuthorityStore($config->hostPreflightRoot() . '/receipt.json'),
                    $config->hostAuthoritySha256()
                ),
                new ProductionPreflightReceipt(
                    new FileAuthorityStore($config->stateDirectory('preflight') . '/receipt.json'),
                    $config->sha256(),
                    $runtimeConfig['image'],
                    $runtimeConfig['seccomp_profile_sha256'],
                    $requiredPathsSha256,
                    $config->hostAuthoritySha256()
                )
            );
        } finally {
            sodium_memzero($responseSecret);
            sodium_memzero($deviceDigestKey);
        }
    }

    public static function janitorFromConfigFile(
        string $path,
        string $expectedSha256,
        bool $requireCgroup = true,
        ?ContainerArgvProcessRunner $processRunner = null
    ): ExpiredPreviewJanitor {
        $config = ProductionConfig::loadForReap($path, $expectedSha256);
        $runtimeConfig = $config->runtime();
        $remote = $runtimeConfig['repository_remote'];
        $authorityRoot = $config->stateDirectory('authority');
        $runtimeRoot = $config->stateDirectory('runtime');
        $processRunner ??= new NativeContainerArgvProcessRunner(
            $runtimeConfig['process_timeout_seconds'],
            $runtimeConfig['process_launcher']['path'],
            true,
            $requireCgroup
        );
        $runtime = ContainerWorkloadRuntime::forExpiredPreviewReap(
            $processRunner,
            $runtimeConfig['container_engine']['path'],
            $runtimeConfig['route_authority']['path'],
            $runtimeConfig['firewall_authority']['path'],
            $runtimeConfig['storage_authority']['path'],
            $runtimeConfig['git']['path'],
            $runtimeConfig['image'],
            $runtimeConfig['seccomp_profile_file'],
            $runtimeConfig['seccomp_profile_sha256'],
            $config->sha256(),
            $runtimeConfig['platform_fingerprint_sha256'],
            $runtimeConfig['review_receipt_sha256'],
            $runtimeRoot,
            $runtimeConfig['repository_source'],
            $remote['name'],
            $remote['url'],
            $remote['url_sha256'],
            $remote['allowed_ref_prefix'],
            $remote['credential_helper'],
            $remote['credential_helper_sha256'],
            $runtimeConfig['snapshot_object_root'],
            $runtimeConfig['preview_domain'],
            $runtimeConfig['workload_repository_path'],
            $runtimeConfig['memory_bytes'],
            $runtimeConfig['nano_cpus'],
            $runtimeConfig['pids_limit']
        );
        $control = new ControlAuthority(
            new FileAuthorityStore($authorityRoot . '/control.json'),
            new ExpiredPreviewJanitorCommandRunner(),
            'expired-preview-janitor',
            str_repeat("\0", SODIUM_CRYPTO_SIGN_SECRETKEYBYTES)
        );
        $lifecycleStore = new FileAuthorityStore($authorityRoot . '/lifecycle.json');
        $lifecycle = new PreviewSlotLifecycle(
            $lifecycleStore,
            $runtime,
            new ControlAuthorityMutationPublisher($control)
        );
        return new ExpiredPreviewJanitor($lifecycleStore, $lifecycle);
    }

    public function router(): CloudHttpRouter {
        return $this->router;
    }

    public function janitor(): ExpiredPreviewJanitor {
        return $this->janitor;
    }

    public function origin(): OriginAuthority {
        return $this->origin;
    }

    /** @return array{site_id:string,tenant_id:string} */
    public function principal(): array {
        return $this->config->principal();
    }

    /** @return array<string,mixed> */
    public function deploymentDescriptor(): array {
        $runtime = $this->config->runtime();
        return [
            'configuration_sha256' => $this->config->sha256(),
            'format' => 'duo-cloud-production-service/v1',
            'image' => $runtime['image'],
            'principal' => $this->principal(),
            'repository_authority' => $this->lifecycle->repositoryAuthorityDescriptor(),
            'reviewed_base_containment' => $this->lifecycle->reviewedBaseContainmentDescriptor(),
            'state_root' => $this->config->stateRoot(),
        ];
    }

    /** @return array<string,mixed> */
    public function preflight(): array {
        return $this->workerPreflight();
    }

    public function hostAuthoritySha256(): string {
        return $this->config->hostAuthoritySha256();
    }

    /** @return array<string,mixed> */
    public function hostWorkerDescriptor(string $workerId): array {
        $descriptor = $this->config->fleetWorkerDescriptor($workerId);
        return [
            'configuration_role' => 'current',
            'configuration_sha256' => $descriptor['configuration_sha256'],
            'preview_domain' => $descriptor['preview_domain'],
            'reviewed_base_sha256' => $descriptor['reviewed_base_sha256'],
            'worker_id' => $workerId,
            'worker_root' => $descriptor['worker_root'],
        ];
    }

    /** @param list<array<string,mixed>> $workers @return array<string,mixed> */
    public static function refreshFleetHostPreflight(
        ProductionConfig $config,
        array $workers,
        bool $requireCgroup = true
    ): array {
        $config->assertHostPreflightRuntime();
        $runtime = $config->runtime();
        $runner = new NativeContainerArgvProcessRunner(
            self::PREFLIGHT_DEADLINE_SECONDS,
            $runtime['process_launcher']['path'],
            true,
            $requireCgroup
        );
        $receipt = new ProductionHostPreflightReceipt(
            new FileAuthorityStore($config->hostPreflightRoot() . '/receipt.json'),
            $config->hostAuthoritySha256()
        );
        return self::runHostPreflight($config, $runner, $receipt, $workers);
    }

    public static function invalidateFleetHostPreflight(ProductionConfig $config): void {
        $config->assertHostPreflightRuntime();
        (new ProductionHostPreflightReceipt(
            new FileAuthorityStore($config->hostPreflightRoot() . '/receipt.json'),
            $config->hostAuthoritySha256()
        ))->invalidate();
    }

    /** @param list<array<string,mixed>> $workers @return array<string,mixed> */
    private static function runHostPreflight(
        ProductionConfig $config,
        ContainerArgvProcessRunner $runner,
        ProductionHostPreflightReceipt $receipt,
        array $workers
    ): array {
        $workers = self::hostWorkers($workers);
        $started = hrtime(true);
        try {
            $runtime = $config->runtime();
            $firewallReconcile = self::hostCanonicalCommand($runner, $runtime, [
                $runtime['firewall_authority']['path'], 'reconcile',
            ], 'firewall reconcile', HostAuthorityBusy::FIREWALL_CLIENT_STDERR, $started);
            self::exactKeys($firewallReconcile, [
                'bindings', 'format', 'principals', 'state',
            ], 'firewall reconcile');
            if (($firewallReconcile['format'] ?? null) !== 'duo-cloud-host-firewall-state/v1'
                || ($firewallReconcile['state'] ?? null) !== 'reconciled'
                || !is_array($firewallReconcile['principals'] ?? null)) {
                throw new ControlRefusal('firewall reconcile did not return exact readiness');
            }
            $firewall = self::hostCanonicalCommand($runner, $runtime, [
                $runtime['firewall_authority']['path'], 'preflight',
            ], 'firewall preflight', HostAuthorityBusy::FIREWALL_CLIENT_STDERR, $started);
            self::exactKeys($firewall, [
                'bindings', 'format', 'principals', 'state',
            ], 'firewall preflight');
            if (($firewall['format'] ?? null) !== 'duo-cloud-host-firewall-state/v1'
                || ($firewall['state'] ?? null) !== 'ready'
                || CanonicalJson::encode($firewall['principals'] ?? null)
                    !== CanonicalJson::encode($firewallReconcile['principals'])) {
                throw new ControlRefusal('firewall preflight did not return exact readiness');
            }
            $route = self::hostCanonicalCommand($runner, $runtime, [
                $runtime['route_authority']['path'], 'host-preflight',
            ], 'route host preflight', HostAuthorityBusy::ROUTE_AUTHORITY_STDERR, $started);
            self::exactKeys($route, [
                'format', 'principals', 'routes', 'state',
            ], 'route host preflight');
            if (($route['format'] ?? null) !== 'duo-cloud-route-authority-host-preflight/v1'
                || ($route['state'] ?? null) !== 'ready'
                || !is_array($route['principals'] ?? null)) {
                throw new ControlRefusal('route host preflight did not return exact readiness');
            }
            $storage = self::hostCanonicalCommand($runner, $runtime, [
                $runtime['storage_authority']['path'], 'fleet-preflight',
            ], 'storage fleet preflight', null, $started);
            self::exactKeys($storage, [
                'bindings', 'docker_root', 'durable_paths', 'format', 'state', 'workers',
            ], 'storage fleet preflight');
            if (($storage['format'] ?? null) !== 'duo-cloud-xfs-quota-fleet-preflight/v1'
                || ($storage['state'] ?? null) !== 'ready') {
                throw new ControlRefusal('storage fleet preflight did not return exact readiness');
            }
            $configurationSha256s = self::assertHostRegistries(
                $workers,
                $firewall['principals'],
                $route['principals'],
                $storage
            );
            $dockerRoot = $storage['docker_root'] ?? null;
            $evidence = [
                'configuration_sha256s' => $configurationSha256s,
                'docker_root_sha256' => is_string($dockerRoot)
                    ? hash('sha256', "duo-cloud-docker-root/v1\0" . $dockerRoot)
                    : null,
                'firewall_bindings' => $firewall['bindings'] ?? null,
                'format' => 'duo-cloud-production-host-preflight/v1',
                'host_authority_sha256' => $config->hostAuthoritySha256(),
                'route_count' => $route['routes'] ?? null,
                'state' => 'ready',
                'storage_bindings' => $storage['bindings'] ?? null,
            ];
            if (!is_int($evidence['firewall_bindings']) || $evidence['firewall_bindings'] < 0
                || $evidence['firewall_bindings'] > 32
                || !is_int($evidence['route_count']) || $evidence['route_count'] < 0
                || $evidence['route_count'] > 32
                || !is_int($evidence['storage_bindings'])
                || $evidence['storage_bindings'] < 0 || $evidence['storage_bindings'] > 32
                || !is_string($evidence['docker_root_sha256'])) {
                throw new ControlRefusal('host preflight authority counts are invalid');
            }
            self::remainingPreflightSeconds($started);
            return $receipt->publish($evidence);
        } catch (HostAuthorityBusy $error) {
            $expectedConfigurations = array_column($workers, 'configuration_sha256');
            sort($expectedConfigurations, SORT_STRING);
            try {
                $current = $receipt->assertCurrent($expectedConfigurations[0]);
                if (($current['configuration_sha256s'] ?? null) !== $expectedConfigurations) {
                    throw new ControlRefusal(
                        'current host receipt does not exactly admit the fleet manifest'
                    );
                }
            } catch (\Throwable $receiptError) {
                $receipt->invalidate();
                throw new ControlRefusal(
                    'host authority contention found no current exact fleet receipt',
                    0,
                    $receiptError
                );
            }
            throw $error;
        } catch (\Throwable $error) {
            $receipt->invalidate();
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function workerPreflight(): array {
        $started = hrtime(true);
        try {
            $deploymentProofs = $this->deploymentProofs->assertCurrent();
            $hostReceipt = $this->hostPreflightReceipt->assertCurrent($this->config->sha256());
            $this->installControllerKeys();
            $runtime = $this->config->runtime();
            $requiredPaths = $this->config->requiredDurablePaths();
            $storageArgv = [
                $runtime['storage_authority']['path'], 'preflight',
                '--config-sha256', $this->config->sha256(),
            ];
            foreach ($requiredPaths as $requiredPath) {
                $storageArgv[] = '--required-path';
                $storageArgv[] = $requiredPath;
            }
            $storage = $this->canonicalCommand(
                $storageArgv,
                'storage preflight',
                self::remainingPreflightSeconds($started)
            );
            $requiredDigest = hash(
                'sha256',
                "duo-cloud-required-durable-paths/v1\0" . CanonicalJson::encode($requiredPaths)
            );
            if (($storage['format'] ?? null) !== 'duo-cloud-xfs-quota-storage-state/v1'
                || ($storage['state'] ?? null) !== 'ready'
                || ($storage['required_paths_sha256'] ?? null) !== $requiredDigest
                || ($storage['worker_root_sha256'] ?? null) !== hash(
                    'sha256',
                    "duo-cloud-worker-root/v1\0" . dirname($this->config->stateRoot())
                )) {
                throw new ControlRefusal('storage preflight did not bind every derived durable path');
            }

            $firewall = $this->canonicalCommand([
                $runtime['firewall_authority']['path'], 'worker-preflight',
                '--config-sha256', $this->config->sha256(),
            ], 'firewall worker preflight', self::remainingPreflightSeconds($started));
            self::exactKeys($firewall, [
                'bindings', 'bindings_sha256', 'configuration_sha256', 'format', 'state',
            ], 'firewall worker preflight');
            if (($firewall['configuration_sha256'] ?? null) !== $this->config->sha256()
                || ($firewall['format'] ?? null)
                    !== 'duo-cloud-host-firewall-worker-preflight/v1'
                || ($firewall['state'] ?? null) !== 'ready'
                || !is_int($firewall['bindings'] ?? null)
                || $firewall['bindings'] < 0 || $firewall['bindings'] > 32
                || !is_string($firewall['bindings_sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $firewall['bindings_sha256']) !== 1) {
                throw new ControlRefusal('firewall worker preflight did not return exact readiness');
            }

            $credential = $this->canonicalCommand([
                $runtime['repository_remote']['credential_helper'], 'verify',
            ], 'repository credential preflight', self::remainingPreflightSeconds($started));
            if (($credential['format'] ?? null) !== 'duo-cloud-git-credential-provider-status/v1'
                || ($credential['ready'] ?? null) !== true
                || ($credential['remote_url_sha256'] ?? null)
                    !== $runtime['repository_remote']['url_sha256']) {
                throw new ControlRefusal('repository credential preflight did not bind its remote');
            }
            $engine = $this->externalJsonCommand([
                $runtime['container_engine']['path'], 'info', '--format', '{{json .}}',
            ], 'container engine preflight', self::remainingPreflightSeconds($started));
            $security = $engine['SecurityOptions'] ?? null;
            if (!is_array($security) || !array_is_list($security)
                || !in_array('name=seccomp,profile=builtin', $security, true)
                || !in_array('name=apparmor', $security, true)
                || !is_string($engine['ServerVersion'] ?? null)
                || preg_match(
                    '/\A(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\.(?:0|[1-9][0-9]*)\z/D',
                    $engine['ServerVersion']
                ) !== 1
                || version_compare($engine['ServerVersion'], '26.0.0', '<')
                || !is_string($engine['DockerRootDir'] ?? null)
                || ($engine['DockerRootDir'] ?? '') === ''
                || ($engine['DockerRootDir'][0] ?? '') !== '/') {
                throw new ControlRefusal(
                    'container engine lacks the reviewed version, seccomp, and AppArmor boundary'
                );
            }
            if (($hostReceipt['docker_root_sha256'] ?? null) !== hash(
                'sha256',
                "duo-cloud-docker-root/v1\0" . $engine['DockerRootDir']
            )) {
                throw new ControlRefusal(
                    'container engine Docker root differs from encrypted storage authority'
                );
            }
            $canary = $this->processRunner->run([
                $runtime['container_engine']['path'], 'run', '--rm', '--pull', 'never',
                '--network', 'none', '--read-only', '--cap-drop', 'ALL',
                '--security-opt', 'no-new-privileges=true',
                '--security-opt', 'seccomp=' . $runtime['seccomp_profile_file'],
                '--pids-limit', '32', '--memory', '134217728', '--memory-swap', '134217728',
                '--shm-size', '8388608', '--ulimit', 'nofile=256:256',
                '--tmpfs', '/tmp:rw,noexec,nosuid,nodev,size=8388608,mode=0700,uid=10001,gid=10001',
                '--user', '10001:10001', '--entrypoint', '/bin/sh', $runtime['image'], '-ceu',
                <<<'SH'
grep -Eq '^CapEff:[[:space:]]+0+$' /proc/self/status
grep -Eq '^NoNewPrivs:[[:space:]]+1$' /proc/self/status
grep -Eq '^Seccomp:[[:space:]]+2$' /proc/self/status
test "$(cat /proc/self/attr/current)" = 'docker-default (enforce)'
/opt/duo/bin/containment-canary /tmp/duo-seccomp-canary
SH,
            ], null, min(
                self::remainingPreflightSeconds($started),
                $runtime['process_timeout_seconds']
            ));
            if ($canary['exit'] !== 0 || $canary['stderr'] !== ''
                || $canary['stdout'] !== "duo-cloud-seccomp-ioctl-canary/v1\n") {
                throw new ControlRefusal('active workload containment canary refused');
            }

            $reviewedBaseSha256 = $this->config->reviewedBaseSha256();
            $route = $this->canonicalCommand([
                $runtime['route_authority']['path'], 'worker-preflight',
                '--config-sha256', $this->config->sha256(),
                '--reviewed-base-sha256', $reviewedBaseSha256,
            ], 'route worker preflight', self::remainingPreflightSeconds($started),
                HostAuthorityBusy::ROUTE_AUTHORITY_STDERR);
            self::exactKeys($route, [
                'configuration_sha256', 'format', 'reviewed_base_sha256',
                'route_bindings_sha256', 'routes', 'state',
            ], 'route worker preflight');
            if (($route['configuration_sha256'] ?? null) !== $this->config->sha256()
                || ($route['format'] ?? null)
                    !== 'duo-cloud-route-authority-worker-preflight/v1'
                || ($route['reviewed_base_sha256'] ?? null) !== $reviewedBaseSha256
                || !is_string($route['route_bindings_sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $route['route_bindings_sha256']) !== 1
                || !is_int($route['routes'] ?? null)
                || $route['routes'] < 0 || $route['routes'] > 32
                || ($route['state'] ?? null) !== 'ready') {
                throw new ControlRefusal('route worker preflight did not return exact readiness');
            }

            $evidence = [
                'configuration_sha256' => $this->config->sha256(),
                'deployment_proofs_sha256' => $deploymentProofs['identity_sha256'],
                'firewall_bindings' => $firewall['bindings'],
                'firewall_bindings_sha256' => $firewall['bindings_sha256'],
                'format' => 'duo-cloud-production-preflight/v1',
                'host_authority_sha256' => $this->hostAuthoritySha256(),
                'required_paths_sha256' => $requiredDigest,
                'route_bindings_sha256' => $route['route_bindings_sha256'],
                'route_count' => $route['routes'],
                'state' => 'ready',
                'storage_bindings' => $hostReceipt['storage_bindings'] ?? null,
                'workload_canary_sha256' => hash(
                    'sha256',
                    "duo-cloud-active-containment-canary/v1\0" . $canary['stdout']
                ),
            ];
            self::remainingPreflightSeconds($started);
            $receipt = $this->preflightReceipt->publish($evidence);
            return $evidence + [
                'host_receipt_expires_at' => $hostReceipt['expires_at'],
                'receipt_expires_at' => $receipt['expires_at'],
                'receipt_sha256' => hash(
                    'sha256',
                    "duo-cloud-production-preflight-receipt/v1\0"
                        . CanonicalJson::encode($receipt)
                ),
            ];
        } catch (HostAuthorityBusy $error) {
            try {
                $currentProofs = $this->deploymentProofs->assertCurrent();
                $this->preflightReceipt->assertCurrent($currentProofs['identity_sha256']);
            } catch (\Throwable $receiptError) {
                $this->preflightReceipt->invalidate();
                throw new ControlRefusal(
                    'route authority contention found no current worker receipt',
                    0,
                    $receiptError
                );
            }
            throw $error;
        } catch (\Throwable $error) {
            $this->preflightReceipt->invalidate();
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function assertReadyToServe(): array {
        $this->assertControllerKeysInstalled();
        $deploymentProofs = $this->deploymentProofs->assertCurrent();
        $this->hostPreflightReceipt->assertCurrent($this->config->sha256());
        return $this->preflightReceipt->assertCurrent($deploymentProofs['identity_sha256']);
    }

    private function installControllerKeys(): void {
        $desired = $this->configuredControllerKeyRecords();
        $this->controllerKeyInstallationStore->locked(function (AuthorityStateSession $session) use ($desired): void {
            $raw = $session->state();
            $initial = $raw === [];
            if ($initial) {
                [$active, $retired] = $this->controlKeyRegistry();
                $state = self::controllerKeyInstallation(
                    'installed',
                    $this->config->sha256(),
                    $active,
                    [],
                    $retired
                );
            } else {
                $state = self::validateControllerKeyInstallation($raw);
            }

            if ($state['state'] === 'installing') {
                $this->reconcileControllerKeys($state['previous_keys'], $state['keys']);
                $state['previous_keys'] = [];
                $state['state'] = 'installed';
                $session->save($state);
            }

            $desiredSha256 = self::controllerKeyRegistrySha256($desired);
            // Production exposes no request-key mutation endpoint; command
            // traffic changes only site/receipt state in control.json. This
            // independent journal therefore keeps readiness off the command
            // lock without weakening the installed key-set comparison.
            if ($state['registry_sha256'] === $desiredSha256
                && CanonicalJson::encode($state['keys']) === CanonicalJson::encode($desired)) {
                if ($initial) {
                    // A pre-journal deployment can already contain the exact
                    // registry. Replaying every desired registration once
                    // validates the full authority document before trusting
                    // the new independent fast-path journal.
                    $this->reconcileControllerKeys([], $desired, true);
                }
                if ($state['configuration_sha256'] !== $this->config->sha256()) {
                    $state['configuration_sha256'] = $this->config->sha256();
                    $session->save($state);
                } elseif ($initial) {
                    $session->save($state);
                }
                return;
            }

            self::assertControllerKeyTransition($state, $desired);
            $retired = $state['retired_key_ids'];
            $desiredIds = array_fill_keys(array_column($desired, 'key_id'), true);
            foreach ($state['keys'] as $record) {
                if (!isset($desiredIds[$record['key_id']])) {
                    $retired[] = $record['key_id'];
                }
            }
            $retired = array_values(array_unique($retired));
            sort($retired, SORT_STRING);
            if (count($retired) > self::CONTROLLER_KEY_LIMIT) {
                throw new ControlRefusal('controller key retirement history reached its closed limit');
            }

            $pending = self::controllerKeyInstallation(
                'installing',
                $this->config->sha256(),
                $desired,
                $state['keys'],
                $retired
            );
            $session->save($pending);
            $this->reconcileControllerKeys($state['keys'], $desired, $initial);
            $pending['previous_keys'] = [];
            $pending['state'] = 'installed';
            $session->save($pending);
        });
    }

    private function assertControllerKeysInstalled(): void {
        $desired = $this->configuredControllerKeyRecords();
        $state = self::validateControllerKeyInstallation(
            $this->controllerKeyInstallationStore->stableRead()
        );
        if ($state['state'] !== 'installed'
            || $state['configuration_sha256'] !== $this->config->sha256()
            || $state['registry_sha256'] !== self::controllerKeyRegistrySha256($desired)
            || CanonicalJson::encode($state['keys']) !== CanonicalJson::encode($desired)) {
            throw new ControlRefusal('controller keys are not installed for the active production configuration');
        }
    }

    /** @return list<array{key_id:string,public_key:string,site_id:string,tenant_id:string}> */
    private function configuredControllerKeyRecords(): array {
        $records = [];
        foreach ($this->config->controllerKeys() as $index => $key) {
            $publicKey = $this->config->keyBytes(
                $key['public_key'],
                SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
                SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
                "controller public key $index"
            );
            try {
                $records[] = [
                    'key_id' => $key['key_id'],
                    'public_key' => base64_encode($publicKey),
                    'site_id' => $key['site_id'],
                    'tenant_id' => $key['tenant_id'],
                ];
            } finally {
                sodium_memzero($publicKey);
            }
        }
        usort(
            $records,
            static fn (array $left, array $right): int => strcmp($left['key_id'], $right['key_id'])
        );
        return $records;
    }

    /**
     * @return array{0:list<array{key_id:string,public_key:string,site_id:string,tenant_id:string}>,1:list<string>}
     */
    private function controlKeyRegistry(): array {
        return $this->controlStore->locked(static function (AuthorityStateSession $session): array {
            $state = $session->state();
            if ($state === []) {
                return [[], []];
            }
            self::exactKeys($state, ['format', 'request_keys', 'sites'], 'control authority state');
            if (($state['format'] ?? null) !== 'duo-cloud-preview-authority-store/v1'
                || !is_array($state['request_keys'] ?? null)
                || (array_is_list($state['request_keys']) && $state['request_keys'] !== [])) {
                throw new ControlRefusal('control authority request-key registry is corrupt');
            }
            $active = [];
            $retired = [];
            foreach ($state['request_keys'] as $keyId => $record) {
                if (!is_string($keyId) || !is_array($record) || array_is_list($record)) {
                    throw new ControlRefusal('control authority request-key registry is corrupt');
                }
                self::exactKeys(
                    $record,
                    ['key_id', 'public_key', 'site_id', 'state', 'tenant_id'],
                    'control authority request key'
                );
                $normalized = self::controllerKeyRecord([
                    'key_id' => $record['key_id'] ?? null,
                    'public_key' => $record['public_key'] ?? null,
                    'site_id' => $record['site_id'] ?? null,
                    'tenant_id' => $record['tenant_id'] ?? null,
                ]);
                if ($normalized['key_id'] !== $keyId
                    || !in_array($record['state'] ?? null, ['active', 'revoked'], true)) {
                    throw new ControlRefusal('control authority request-key registry is corrupt');
                }
                if ($record['state'] === 'active') {
                    $active[] = $normalized;
                } else {
                    $retired[] = $keyId;
                }
            }
            usort(
                $active,
                static fn (array $left, array $right): int => strcmp($left['key_id'], $right['key_id'])
            );
            sort($retired, SORT_STRING);
            if (count($active) > self::CONTROLLER_KEY_LIMIT
                || count($retired) > self::CONTROLLER_KEY_LIMIT) {
                throw new ControlRefusal('control authority request-key registry exceeds its closed limit');
            }
            return [$active, $retired];
        });
    }

    /**
     * @param list<array{key_id:string,public_key:string,site_id:string,tenant_id:string}> $previous
     * @param list<array{key_id:string,public_key:string,site_id:string,tenant_id:string}> $desired
     */
    private function reconcileControllerKeys(array $previous, array $desired, bool $verifyAllDesired = false): void {
        $previousById = [];
        foreach ($previous as $record) {
            $previousById[$record['key_id']] = $record;
        }
        $desiredById = [];
        foreach ($desired as $record) {
            $desiredById[$record['key_id']] = $record;
        }
        foreach ($previousById as $keyId => $record) {
            if (!isset($desiredById[$keyId])) {
                $this->control->revokeRequestKey($keyId, $record['tenant_id'], $record['site_id']);
            }
        }
        foreach ($desiredById as $keyId => $record) {
            if (!$verifyAllDesired && isset($previousById[$keyId])) {
                continue;
            }
            $publicKey = base64_decode($record['public_key'], true);
            if (!is_string($publicKey)) {
                throw new ControlRefusal('controller key installation contains invalid public key bytes');
            }
            try {
                $this->control->registerRequestKey(
                    $keyId,
                    $record['tenant_id'],
                    $record['site_id'],
                    $publicKey
                );
            } finally {
                sodium_memzero($publicKey);
            }
        }
    }

    /**
     * @param list<array{key_id:string,public_key:string,site_id:string,tenant_id:string}> $keys
     * @param list<array{key_id:string,public_key:string,site_id:string,tenant_id:string}> $previousKeys
     * @param list<string> $retiredKeyIds
     * @return array<string,mixed>
     */
    private static function controllerKeyInstallation(
        string $state,
        string $configurationSha256,
        array $keys,
        array $previousKeys,
        array $retiredKeyIds
    ): array {
        return [
            'configuration_sha256' => $configurationSha256,
            'format' => self::CONTROLLER_KEY_INSTALLATION_FORMAT,
            'keys' => $keys,
            'previous_keys' => $previousKeys,
            'registry_sha256' => self::controllerKeyRegistrySha256($keys),
            'retired_key_ids' => $retiredKeyIds,
            'state' => $state,
        ];
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function validateControllerKeyInstallation(array $state): array {
        self::exactKeys($state, [
            'configuration_sha256', 'format', 'keys', 'previous_keys', 'registry_sha256',
            'retired_key_ids', 'state',
        ], 'controller key installation');
        if (($state['format'] ?? null) !== self::CONTROLLER_KEY_INSTALLATION_FORMAT
            || !is_string($state['configuration_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $state['configuration_sha256']) !== 1
            || !in_array($state['state'] ?? null, ['installed', 'installing'], true)) {
            throw new ControlRefusal('controller key installation is corrupt');
        }
        $keys = self::controllerKeyRecords($state['keys'] ?? null, 'installed controller keys');
        $previous = self::controllerKeyRecords(
            $state['previous_keys'] ?? null,
            'previous controller keys',
            true
        );
        $retired = $state['retired_key_ids'] ?? null;
        if (!is_array($retired) || !array_is_list($retired)
            || count($retired) > self::CONTROLLER_KEY_LIMIT) {
            throw new ControlRefusal('controller key installation retirement history is corrupt');
        }
        $last = null;
        foreach ($retired as $keyId) {
            self::identifier($keyId, 'retired controller key id');
            if ($last !== null && strcmp($last, $keyId) >= 0) {
                throw new ControlRefusal('controller key installation retirement history is corrupt');
            }
            $last = $keyId;
        }
        $retiredMap = array_fill_keys($retired, true);
        foreach ($keys as $record) {
            if (isset($retiredMap[$record['key_id']])) {
                throw new ControlRefusal('controller key installation reactivated a retired key id');
            }
        }
        if (($state['state'] === 'installed' && $previous !== [])
            || !is_string($state['registry_sha256'] ?? null)
            || $state['registry_sha256'] !== self::controllerKeyRegistrySha256($keys)) {
            throw new ControlRefusal('controller key installation is corrupt');
        }
        $state['keys'] = $keys;
        $state['previous_keys'] = $previous;
        $state['retired_key_ids'] = $retired;
        return $state;
    }

    /**
     * @param mixed $value
     * @return list<array{key_id:string,public_key:string,site_id:string,tenant_id:string}>
     */
    private static function controllerKeyRecords(
        mixed $value,
        string $label,
        bool $emptyAllowed = false
    ): array {
        if (!is_array($value) || !array_is_list($value)
            || (!$emptyAllowed && $value === []) || count($value) > self::CONTROLLER_KEY_LIMIT) {
            throw new ControlRefusal("$label are not a bounded canonical list");
        }
        $records = [];
        $last = null;
        foreach ($value as $candidate) {
            if (!is_array($candidate) || array_is_list($candidate)) {
                throw new ControlRefusal("$label are corrupt");
            }
            $record = self::controllerKeyRecord($candidate);
            if ($last !== null && strcmp($last, $record['key_id']) >= 0) {
                throw new ControlRefusal("$label are not ordered by unique key id");
            }
            $last = $record['key_id'];
            $records[] = $record;
        }
        return $records;
    }

    /**
     * @param array<string,mixed> $record
     * @return array{key_id:string,public_key:string,site_id:string,tenant_id:string}
     */
    private static function controllerKeyRecord(array $record): array {
        self::exactKeys(
            $record,
            ['key_id', 'public_key', 'site_id', 'tenant_id'],
            'controller key installation record'
        );
        $keyId = self::identifier($record['key_id'] ?? null, 'controller key installation id');
        $tenantId = self::identifier(
            $record['tenant_id'] ?? null,
            'controller key installation tenant id'
        );
        $siteId = self::identifier($record['site_id'] ?? null, 'controller key installation site id');
        $encoded = $record['public_key'] ?? null;
        $publicKey = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_string($encoded) || !is_string($publicKey)
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || base64_encode($publicKey) !== $encoded) {
            throw new ControlRefusal('controller key installation public key is invalid');
        }
        sodium_memzero($publicKey);
        return [
            'key_id' => $keyId,
            'public_key' => $encoded,
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ];
    }

    /** @param array<string,mixed> $state @param list<array<string,string>> $desired */
    private static function assertControllerKeyTransition(array $state, array $desired): void {
        $current = [];
        foreach ($state['keys'] as $record) {
            $current[$record['key_id']] = $record;
        }
        $retired = array_fill_keys($state['retired_key_ids'], true);
        foreach ($desired as $record) {
            $keyId = $record['key_id'];
            if (isset($retired[$keyId])) {
                throw new ControlRefusal('retired controller key ids cannot be reused');
            }
            if (isset($current[$keyId])
                && CanonicalJson::encode($current[$keyId]) !== CanonicalJson::encode($record)) {
                throw new ControlRefusal('controller key rotation must use a new key id');
            }
        }
    }

    /** @param list<array<string,string>> $keys */
    private static function controllerKeyRegistrySha256(array $keys): string {
        return hash(
            'sha256',
            "duo-cloud-controller-key-registry/v1\0" . CanonicalJson::encode($keys)
        );
    }

    /** @param list<array<string,mixed>> $workers @return list<array<string,mixed>> */
    private static function hostWorkers(array $workers): array {
        if ($workers === [] || count($workers) > 64) {
            throw new ControlRefusal('host preflight worker registry is empty or unbounded');
        }
        $previousIdentity = null;
        $workerCount = 0;
        $workerTopology = [];
        $configurations = [];
        $roots = [];
        foreach ($workers as $worker) {
            if (!is_array($worker) || array_is_list($worker)) {
                throw new ControlRefusal('host preflight worker registry is malformed');
            }
            self::exactKeys($worker, [
                'configuration_role', 'configuration_sha256', 'preview_domain',
                'reviewed_base_sha256', 'worker_id', 'worker_root',
            ], 'host preflight worker');
            $workerId = $worker['worker_id'] ?? null;
            $role = $worker['configuration_role'] ?? null;
            $configuration = $worker['configuration_sha256'] ?? null;
            $reviewed = $worker['reviewed_base_sha256'] ?? null;
            $domain = $worker['preview_domain'] ?? null;
            $root = $worker['worker_root'] ?? null;
            $identity = is_string($workerId) && is_string($role)
                ? $workerId . "\0" . ($role === 'current' ? '0' : '1')
                : null;
            if (!is_string($workerId)
                || preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/D', $workerId) !== 1
                || !in_array($role, ['current', 'retiring'], true)
                || !is_string($identity)
                || ($previousIdentity !== null && strcmp($previousIdentity, $identity) >= 0)
                || !is_string($configuration)
                || preg_match('/\A[a-f0-9]{64}\z/D', $configuration) !== 1
                || isset($configurations[$configuration])
                || !is_string($reviewed)
                || preg_match('/\A[a-f0-9]{64}\z/D', $reviewed) !== 1
                || !is_string($domain)
                || preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}\z/D', $domain) !== 1
                || !is_string($root) || $root === '' || $root[0] !== '/') {
                throw new ControlRefusal('host preflight worker registry is not canonical');
            }
            if (!isset($workerTopology[$workerId])) {
                if ($role !== 'current' || ++$workerCount > 32) {
                    throw new ControlRefusal('host preflight worker registry is not canonical');
                }
                foreach ($roots as $existing) {
                    if (self::within($root, $existing) || self::within($existing, $root)) {
                        throw new ControlRefusal('host preflight worker roots overlap');
                    }
                }
                $workerTopology[$workerId] = [
                    'preview_domain' => $domain,
                    'retiring' => false,
                    'worker_root' => $root,
                ];
                $roots[] = $root;
            } else {
                $topology = $workerTopology[$workerId];
                if ($role !== 'retiring' || $topology['retiring']
                    || $domain !== $topology['preview_domain']
                    || $root !== $topology['worker_root']) {
                    throw new ControlRefusal(
                        'host preflight retiring worker topology is not canonical'
                    );
                }
                $workerTopology[$workerId]['retiring'] = true;
            }
            $configurations[$configuration] = true;
            $previousIdentity = $identity;
        }
        return $workers;
    }

    /**
     * @param list<array<string,mixed>> $workers
     * @param array<mixed> $firewallPrincipals
     * @param array<mixed> $routePrincipals
     * @param array<string,mixed> $storage
     * @return list<string>
     */
    private static function assertHostRegistries(
        array $workers,
        array $firewallPrincipals,
        array $routePrincipals,
        array $storage
    ): array {
        if (!array_is_list($firewallPrincipals) || $firewallPrincipals === []
            || count($firewallPrincipals) > 32
            || !array_is_list($routePrincipals) || $routePrincipals === []
            || count($routePrincipals) > 512
            || !is_array($storage['workers'] ?? null) || !array_is_list($storage['workers'])) {
            throw new ControlRefusal('host authority registries are malformed or unbounded');
        }
        $firewall = [];
        $firewallConfigurations = [];
        foreach ($firewallPrincipals as $principal) {
            if (!is_array($principal) || array_is_list($principal)) {
                throw new ControlRefusal('firewall preflight principal registry is malformed');
            }
            self::exactKeys(
                $principal,
                ['configuration_sha256s', 'service_uid', 'worker_id'],
                'firewall preflight principal'
            );
            $id = $principal['worker_id'] ?? null;
            $digests = $principal['configuration_sha256s'] ?? null;
            if (!is_string($id) || isset($firewall[$id]) || ($principal['service_uid'] ?? null) !== 10001
                || !is_array($digests) || !array_is_list($digests)) {
                throw new ControlRefusal('firewall preflight principal registry is invalid');
            }
            self::configurationSha256s($digests);
            foreach ($digests as $digest) {
                if (isset($firewallConfigurations[$digest])) {
                    throw new ControlRefusal(
                        'firewall preflight configuration is registered to multiple workers'
                    );
                }
                $firewallConfigurations[$digest] = $id;
            }
            $firewall[$id] = $digests;
        }
        $routes = [];
        foreach ($routePrincipals as $principal) {
            if (!is_array($principal) || array_is_list($principal)) {
                throw new ControlRefusal('route preflight principal registry is malformed');
            }
            self::exactKeys($principal, [
                'preview_domain', 'reviewed_base_sha256', 'runtime_configuration_sha256',
            ], 'route preflight principal');
            $configuration = $principal['runtime_configuration_sha256'] ?? null;
            $reviewed = $principal['reviewed_base_sha256'] ?? null;
            $domain = $principal['preview_domain'] ?? null;
            if (!is_string($configuration)
                || preg_match('/\A[a-f0-9]{64}\z/D', $configuration) !== 1
                || !is_string($reviewed)
                || preg_match('/\A[a-f0-9]{64}\z/D', $reviewed) !== 1
                || !is_string($domain)
                || preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}\z/D', $domain) !== 1) {
                throw new ControlRefusal('route preflight principal registry is invalid');
            }
            $routes[$configuration][] = $principal;
        }
        $storageWorkers = [];
        foreach ($storage['workers'] as $worker) {
            if (!is_array($worker) || array_is_list($worker)) {
                throw new ControlRefusal('storage preflight worker registry is malformed');
            }
            self::exactKeys($worker, [
                'configuration_sha256', 'worker_root', 'worker_root_sha256',
            ], 'storage preflight worker');
            $configuration = $worker['configuration_sha256'] ?? null;
            $root = $worker['worker_root'] ?? null;
            if (!is_string($configuration)
                || preg_match('/\A[a-f0-9]{64}\z/D', $configuration) !== 1
                || isset($storageWorkers[$configuration])
                || !is_string($root) || $root === '' || $root[0] !== '/'
                || ($worker['worker_root_sha256'] ?? null) !== hash(
                    'sha256',
                    "duo-cloud-worker-root/v1\0" . $root
                )) {
                throw new ControlRefusal('storage preflight worker registry is invalid');
            }
            $storageWorkers[$configuration] = $worker;
        }
        $expected = [];
        foreach ($workers as $worker) {
            $configuration = $worker['configuration_sha256'];
            $workerId = $worker['worker_id'];
            if (!isset($firewall[$workerId])
                || !in_array($configuration, $firewall[$workerId], true)) {
                throw new ControlRefusal('fleet worker is absent from the firewall principal registry');
            }
            $configurationRoutes = $routes[$configuration] ?? [];
            if (count($configurationRoutes) !== 1
                || ($configurationRoutes[0]['preview_domain'] ?? null)
                    !== $worker['preview_domain']
                || ($configurationRoutes[0]['reviewed_base_sha256'] ?? null)
                    !== $worker['reviewed_base_sha256']) {
                throw new ControlRefusal('fleet worker is absent from the exact route principal registry');
            }
            $stored = $storageWorkers[$configuration] ?? null;
            if (!is_array($stored) || ($stored['worker_root'] ?? null) !== $worker['worker_root']
                || ($firewallConfigurations[$configuration] ?? null) !== $workerId) {
                throw new ControlRefusal('fleet worker is absent from the exact storage registry');
            }
            $expected[$configuration] = true;
        }
        $admitted = [];
        foreach ($firewallConfigurations as $configuration => $_workerId) {
            if (isset($storageWorkers[$configuration], $routes[$configuration])) {
                $admitted[$configuration] = true;
            }
        }
        $configurations = array_keys($admitted);
        sort($configurations, SORT_STRING);
        $expectedConfigurations = array_keys($expected);
        sort($expectedConfigurations, SORT_STRING);
        if ($configurations === [] || count($configurations) > 64
            || $configurations !== $expectedConfigurations) {
            throw new ControlRefusal(
                'host authority admission intersection differs from the fleet manifest'
            );
        }
        return $expectedConfigurations;
    }

    private static function within(string $path, string $root): bool {
        return $path === $root || str_starts_with($path, $root . '/');
    }

    /**
     * @param array<string,mixed> $runtime
     * @param non-empty-list<string> $argv
     * @return array<string,mixed>
     */
    private static function hostCanonicalCommand(
        ContainerArgvProcessRunner $runner,
        array $runtime,
        array $argv,
        string $label,
        ?string $busyStderr,
        int $started
    ): array {
        $timeout = self::remainingPreflightSeconds($started);
        $configured = $runtime['process_timeout_seconds'] ?? null;
        if (!is_int($configured)) {
            throw new ControlRefusal('host preflight process timeout is unavailable');
        }
        $result = $runner->run($argv, null, min($timeout, $configured));
        if ($busyStderr !== null
            && HostAuthorityBusy::matchesProcessResult($result, $busyStderr)) {
            throw new HostAuthorityBusy("$label authority lock is busy");
        }
        if ($result['exit'] !== 0 || $result['stderr'] !== ''
            || !str_ends_with($result['stdout'], "\n")) {
            throw new ControlRefusal("$label refused");
        }
        $document = CanonicalJson::decodeObject($result['stdout'], 1048576);
        if ($result['stdout'] !== CanonicalJson::encode($document) . "\n") {
            throw new ControlRefusal("$label returned noncanonical evidence");
        }
        return $document;
    }

    private static function remainingPreflightSeconds(int $started): int {
        $elapsed = (hrtime(true) - $started) / 1_000_000_000;
        $remaining = self::PREFLIGHT_DEADLINE_SECONDS - $elapsed;
        if ($remaining <= 0) {
            throw new ControlRefusal('production preflight exceeded its aggregate deadline');
        }
        return max(1, (int) ceil($remaining));
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new ControlRefusal("$label has missing or unknown fields");
        }
    }

    private static function identifier(mixed $value, string $label): string {
        if (!is_string($value)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new ControlRefusal("$label is invalid");
        }
        return $value;
    }

    /** @param non-empty-list<string> $argv @return array<string,mixed> */
    private function canonicalCommand(
        array $argv,
        string $label,
        ?int $timeoutSeconds = null,
        ?string $busyStderr = null
    ): array {
        $result = $this->processRunner->run(
            $argv,
            null,
            $timeoutSeconds === null
                ? null
                : min($timeoutSeconds, $this->config->runtime()['process_timeout_seconds'])
        );
        if ($busyStderr !== null
            && HostAuthorityBusy::matchesProcessResult($result, $busyStderr)) {
            throw new HostAuthorityBusy("$label authority lock is busy");
        }
        if ($result['exit'] !== 0 || $result['stderr'] !== ''
            || !str_ends_with($result['stdout'], "\n")) {
            throw new ControlRefusal("$label refused");
        }
        $document = CanonicalJson::decodeObject($result['stdout'], 1048576);
        if ($result['stdout'] !== CanonicalJson::encode($document) . "\n") {
            throw new ControlRefusal("$label returned noncanonical evidence");
        }
        return $document;
    }

    /** @param non-empty-list<string> $argv @return array<string,mixed> */
    private function externalJsonCommand(
        array $argv,
        string $label,
        ?int $timeoutSeconds = null
    ): array {
        $result = $this->processRunner->run(
            $argv,
            null,
            $timeoutSeconds === null
                ? null
                : min($timeoutSeconds, $this->config->runtime()['process_timeout_seconds'])
        );
        if ($result['exit'] !== 0 || $result['stderr'] !== ''
            || $result['stdout'] === '' || strlen($result['stdout']) > 1048576) {
            throw new ControlRefusal("$label refused");
        }
        try {
            $document = json_decode($result['stdout'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new ControlRefusal("$label returned invalid JSON", 0, $error);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new ControlRefusal("$label returned a non-object JSON value");
        }
        return $document;
    }

    /** @param list<string> $digests */
    private static function configurationSha256s(array $digests): void {
        if ($digests === [] || count($digests) > 32) {
            throw new ControlRefusal('host preflight configuration registry is empty or unbounded');
        }
        $previous = null;
        foreach ($digests as $digest) {
            if (preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
                || ($previous !== null && strcmp($previous, $digest) >= 0)) {
                throw new ControlRefusal('host preflight configuration registry is not canonical');
            }
            $previous = $digest;
        }
    }
}
