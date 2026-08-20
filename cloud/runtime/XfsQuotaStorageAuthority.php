<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ContainerWorkloadRuntime.php';
require_once dirname(__DIR__) . '/src/ControlRefusal.php';
require_once dirname(__DIR__) . '/src/HostAuthorityBusy.php';

/** Root host authority for encrypted XFS project-quota backed preview volumes. */
final class XfsQuotaStorageAuthority {
    public const FORMAT = 'duo-cloud-xfs-quota-storage-state/v1';
    public const PROOF_FORMAT = 'duo-cloud-xfs-quota-host-preflight-proof/v1';
    private const STORE_FORMAT = 'duo-cloud-xfs-quota-storage-store/v1';
    private const STATE_LIMIT = 1048576;
    private const RUNTIME_BINDING_LIMIT = 32;
    private const CONFIGURATION_LIMIT = 64;
    private const PROOF_BINDING_LIMIT = 1;
    private const BINDING_LIMIT = self::RUNTIME_BINDING_LIMIT + self::PROOF_BINDING_LIMIT;
    private const PURPOSE_RUNTIME = 'runtime-generation';
    private const PURPOSE_PROOF = 'host-preflight-proof';
    private const PROOF_BYTES = 67108864;
    private const PROOF_INODES = 1024;
    // XFS project IDs are positive signed 31-bit integers. Disjoint 30-bit
    // spaces prevent a trusted worker-root quota from aliasing a hostile
    // preview-volume quota while keeping collision probability negligible at
    // the closed fleet limits.
    private const WORKER_PROJECT_MIN = 1;
    private const WORKER_PROJECT_SPAN = 1073741823;
    private const VOLUME_PROJECT_MIN = 1073741824;
    private const VOLUME_PROJECT_SPAN = 1073741824;
    private string $directory;
    private string $statePath;
    private string $stateTemporaryPath;
    private string $lockPath;
    /** @var array<string,true> */
    private array $configurations = [];
    /** @var array<string,string> */
    private array $workerRoots = [];

    /**
     * @param list<string> $durablePaths
     * @param list<string> $configurationSha256s
     * @param list<array{configuration_sha256:string,path:string}> $workerRoots
     * @param array{database_bytes:int,database_inodes:int,filesystem_bytes:int,filesystem_inodes:int,worker_bytes:int,worker_inodes:int} $limits
     */
    public function __construct(
        private ContainerArgvProcessRunner $runner,
        private string $engineBinary,
        private string $dmsetupBinary,
        private string $cryptsetupBinary,
        private string $findmntBinary,
        private string $xfsIoBinary,
        private string $xfsQuotaBinary,
        string $stateRoot,
        private string $mountpoint,
        private string $mapperPath,
        private string $mapperName,
        private string $mapperUuid,
        private string $luksUuid,
        private string $backingDevice,
        private string $cipher,
        private string $keyLocation,
        private int $keySizeBits,
        private int $sectorSizeBytes,
        private int $payloadOffsetSectors,
        private int $mapperSizeSectors,
        private string $filesystemUuid,
        private string $dockerRoot,
        private array $durablePaths,
        array $workerRoots,
        private array $limits,
        array $configurationSha256s
    ) {
        foreach ([
            'container engine' => $engineBinary,
            'cryptsetup' => $cryptsetupBinary,
            'dmsetup' => $dmsetupBinary,
            'findmnt' => $findmntBinary,
            'xfs_io' => $xfsIoBinary,
            'xfs_quota' => $xfsQuotaBinary,
        ] as $label => $binary) {
            self::absoluteExecutable($binary, $label);
        }
        $configuredMountpoint = $mountpoint;
        $this->mountpoint = self::canonicalDirectory($mountpoint, 'encrypted XFS mountpoint');
        $this->dockerRoot = self::canonicalDirectory($dockerRoot, 'Docker root');
        self::commandPath($this->mountpoint, 'encrypted XFS mountpoint');
        self::commandPath($this->dockerRoot, 'Docker root');
        if (!self::within($this->dockerRoot, $this->mountpoint)) {
            throw new ControlRefusal('Docker root is outside the encrypted XFS mountpoint');
        }
        if (!is_string($mapperPath) || $mapperPath !== '/dev/mapper/' . $mapperName
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._+-]{0,127}\z/D', $mapperName) !== 1
            || preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D', $luksUuid) !== 1
            || $mapperUuid !== 'CRYPT-LUKS2-' . str_replace('-', '', $luksUuid) . '-' . $mapperName
            || preg_match('#\A/dev/[A-Za-z0-9._/+:-]+\z#D', $backingDevice) !== 1
            || preg_match('/\A[a-z0-9][a-z0-9._+-]{1,63}\z/D', $cipher) !== 1
            || $keyLocation !== 'keyring'
            || !in_array($keySizeBits, [256, 512], true)
            || !in_array($sectorSizeBytes, [512, 4096], true)
            || $payloadOffsetSectors < 8 || $mapperSizeSectors < 131072
            || preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D', $filesystemUuid) !== 1) {
            throw new ControlRefusal('encrypted XFS device identity is invalid');
        }
        self::canonicalPaths($durablePaths);
        $normalizedDurable = [];
        foreach ($durablePaths as $path) {
            $path = self::normalizeConfiguredPath(
                $path,
                $configuredMountpoint,
                $this->mountpoint
            );
            if (!self::within($path, $this->mountpoint)) {
                throw new ControlRefusal('durable path is outside the encrypted XFS mountpoint');
            }
            $normalizedDurable[] = $path;
        }
        if (count(array_unique($normalizedDurable)) !== count($normalizedDurable)) {
            throw new ControlRefusal('durable path identities are duplicated after canonicalization');
        }
        sort($normalizedDurable, SORT_STRING);
        $this->durablePaths = $normalizedDurable;
        self::limits($limits);
        if ($configurationSha256s === [] || count($configurationSha256s) > self::CONFIGURATION_LIMIT) {
            throw new ControlRefusal('storage configuration registry is empty or unbounded');
        }
        $previous = null;
        foreach ($configurationSha256s as $configurationSha256) {
            self::sha256($configurationSha256, 'storage configuration digest');
            if ($previous !== null && strcmp($previous, $configurationSha256) >= 0) {
                throw new ControlRefusal('storage configuration registry is not canonical and unique');
            }
            $this->configurations[$configurationSha256] = true;
            $previous = $configurationSha256;
        }
        $previous = null;
        foreach ($workerRoots as $workerRoot) {
            if (!is_array($workerRoot) || array_is_list($workerRoot)) {
                throw new ControlRefusal('storage worker root registry is malformed');
            }
            self::exactKeys($workerRoot, ['configuration_sha256', 'path']);
            $configuration = $workerRoot['configuration_sha256'] ?? null;
            $path = $workerRoot['path'] ?? null;
            self::sha256($configuration, 'storage worker root configuration digest');
            if (!isset($this->configurations[$configuration])
                || ($previous !== null && strcmp($previous, $configuration) >= 0)
                || !is_string($path) || $path === '' || str_contains($path, "\0")) {
                throw new ControlRefusal('storage worker root registry is not canonical and registered');
            }
            $path = self::normalizeConfiguredPath(
                $path,
                $configuredMountpoint,
                $this->mountpoint
            );
            if (!self::within($path, $this->mountpoint)
                || $path === $this->mountpoint || self::within($this->dockerRoot, $path)
                || self::within($path, $this->dockerRoot)) {
                throw new ControlRefusal(
                    'storage worker root is absent, unsafe, or outside encrypted storage'
                );
            }
            foreach ($this->workerRoots as $existing) {
                if ($path !== $existing
                    && (self::within($path, $existing) || self::within($existing, $path))) {
                    throw new ControlRefusal('storage worker roots overlap');
                }
            }
            $this->workerRoots[$configuration] = $path;
            $previous = $configuration;
        }
        if (array_keys($this->workerRoots) !== array_keys($this->configurations)) {
            throw new ControlRefusal('storage worker root registry does not cover every configuration');
        }
        $workerProjects = [];
        foreach ($this->workerRoots as $configuration => $path) {
            $project = self::workerProjectId($path);
            if (isset($workerProjects[$project]) && $workerProjects[$project] !== $path) {
                throw new ControlRefusal('storage worker root project identity is duplicated');
            }
            $workerProjects[$project] = $path;
        }
        $root = self::privateRoot($stateRoot);
        $this->directory = $root;
        $this->statePath = $root . '/authority.json';
        $this->stateTemporaryPath = $this->statePath . '.tmp';
        $this->lockPath = $root . '/authority.lock';
    }

    /** @return array<string,mixed> */
    /** @param list<string> $requiredPaths */
    public function preflight(array $requiredPaths = [], ?string $configurationSha256 = null): array {
        $this->assertGlobalEncryptedSurface();
        if ($configurationSha256 === null && count($this->configurations) === 1) {
            $configurationSha256 = array_key_first($this->configurations);
        }
        $configuration = $this->configuration($configurationSha256);
        $worker = $this->workerQuota($configuration);
        $this->setQuota($worker);
        self::canonicalPaths($requiredPaths === [] ? $this->durablePaths : $requiredPaths);
        $required = [];
        foreach ($requiredPaths === [] ? $this->durablePaths : $requiredPaths as $path) {
            $real = realpath($path);
            if (!is_string($real) || !self::within($real, $this->mountpoint)) {
                throw new ControlRefusal('required durable path is absent or outside encrypted storage');
            }
            $covered = false;
            foreach ($this->durablePaths as $configured) {
                if (self::within($real, $configured)) {
                    $covered = true;
                    break;
                }
            }
            if (!$covered) {
                throw new ControlRefusal('required durable path is absent from storage authority configuration');
            }
            $required[] = $real;
            $this->assertEncryptedMount($real);
        }
        $required = array_values(array_unique($required));
        sort($required, SORT_STRING);
        $state = $this->stableReadState();
        $this->verifyWorkerBindings($state, $configuration);
        return $this->readyOutput(
            count($state['bindings']),
            $required,
            $worker
        );
    }

    /** @return array<string,mixed> */
    public function fleetPreflight(): array {
        $this->assertGlobalEncryptedSurface();
        $state = $this->stableReadState();
        self::assertBindingSet($state['bindings']);
        $workers = [];
        foreach ($this->workerRoots as $configuration => $path) {
            $workers[] = [
                'configuration_sha256' => $configuration,
                'worker_root' => $path,
                'worker_root_sha256' => hash(
                    'sha256',
                    "duo-cloud-worker-root/v1\0" . $path
                ),
            ];
        }
        $runtimeBindings = 0;
        foreach ($state['bindings'] as $binding) {
            if (($binding['purpose'] ?? null) === self::PURPOSE_RUNTIME) {
                $runtimeBindings++;
            }
        }
        return [
            'bindings' => $runtimeBindings,
            'docker_root' => $this->dockerRoot,
            'durable_paths' => $this->durablePaths,
            'format' => 'duo-cloud-xfs-quota-fleet-preflight/v1',
            'state' => 'ready',
            'workers' => $workers,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function bind(array $input): array {
        $binding = $this->binding($input);
        return $this->bindBinding($binding, false);
    }

    /** @return array<string,mixed> */
    public function proofBind(string $configurationSha256, string $proofId): array {
        $binding = $this->proofBinding($configurationSha256, $proofId);
        return $this->bindBinding($binding, true);
    }

    /** @param array<string,mixed> $binding @return array<string,mixed> */
    private function bindBinding(array $binding, bool $proof): array {
        $this->assertGlobalEncryptedSurface();
        $this->setQuota($this->workerQuota($binding['configuration_sha256']));
        return $this->locked(function (array $state) use ($binding, $proof): array {
            $map = self::bindingMap($state['bindings']);
            $key = self::bindingKey($binding);
            if (isset($map[$key])
                && CanonicalJson::encode($map[$key]) !== CanonicalJson::encode($binding)) {
                throw new ControlRefusal('storage generation is already bound to different volumes');
            }
            $map[$key] = $binding;
            $bindings = array_values($map);
            self::sortBindings($bindings);
            self::assertBindingSet($bindings);
            $this->publish($bindings, []);
            return $proof ? self::proofBoundOutput($binding) : self::boundOutput($binding);
        });
    }

    /** @return array<string,mixed> */
    public function inspect(string $configurationSha256, string $resourceId, int $generation): array {
        $this->configuration($configurationSha256);
        self::resource($resourceId, $generation);
        $this->assertQuota($this->workerQuota($configurationSha256));
        return $this->locked(function (array $state) use (
            $configurationSha256,
            $resourceId,
            $generation
        ): array {
            $key = self::key(self::PURPOSE_RUNTIME, $configurationSha256, $resourceId, $generation);
            $map = self::bindingMap($state['bindings']);
            return isset($map[$key])
                ? self::boundOutput($map[$key])
                : self::absentOutput($configurationSha256, $resourceId, $generation);
        });
    }

    /** @return array<string,mixed> */
    public function proofInspect(string $configurationSha256, string $proofId): array {
        $this->configuration($configurationSha256);
        self::proofId($proofId);
        $this->assertQuota($this->workerQuota($configurationSha256));
        return $this->locked(function (array $state) use ($configurationSha256, $proofId): array {
            $resourceId = self::proofResourceId($proofId);
            $key = self::key(self::PURPOSE_PROOF, $configurationSha256, $resourceId, 0);
            $map = self::bindingMap($state['bindings']);
            return isset($map[$key])
                ? self::proofBoundOutput($map[$key])
                : self::proofAbsentOutput($configurationSha256, $proofId);
        });
    }

    /** @return array<string,mixed> */
    public function unbind(string $configurationSha256, string $resourceId, int $generation): array {
        $this->configuration($configurationSha256);
        self::resource($resourceId, $generation);
        $this->assertQuota($this->workerQuota($configurationSha256));
        return $this->locked(function (array $state) use (
            $configurationSha256,
            $resourceId,
            $generation
        ): array {
            $key = self::key(self::PURPOSE_RUNTIME, $configurationSha256, $resourceId, $generation);
            $map = self::bindingMap($state['bindings']);
            $cleanup = isset($map[$key]) ? [$map[$key]] : [];
            unset($map[$key]);
            $bindings = array_values($map);
            self::sortBindings($bindings);
            $this->publish($bindings, $cleanup);
            return self::absentOutput($configurationSha256, $resourceId, $generation);
        });
    }

    /** @return array<string,mixed> */
    public function proofUnbind(string $configurationSha256, string $proofId): array {
        $this->configuration($configurationSha256);
        self::proofId($proofId);
        $this->setQuota($this->workerQuota($configurationSha256));
        return $this->locked(function (array $state) use ($configurationSha256, $proofId): array {
            $resourceId = self::proofResourceId($proofId);
            $key = self::key(self::PURPOSE_PROOF, $configurationSha256, $resourceId, 0);
            $map = self::bindingMap($state['bindings']);
            $cleanup = isset($map[$key]) ? [$map[$key]] : [];
            unset($map[$key]);
            $bindings = array_values($map);
            self::sortBindings($bindings);
            $this->publish($bindings, $cleanup);
            return self::proofAbsentOutput($configurationSha256, $proofId);
        });
    }

    /** @param callable(array<string,mixed>):array<string,mixed> $callback @return array<string,mixed> */
    private function locked(callable $callback, bool $verifyPhysical = true): array {
        $handle = @fopen($this->lockPath, 'c+b');
        if (!is_resource($handle) || !chmod($this->lockPath, 0600)) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw new ControlRefusal('storage authority lock could not be acquired privately');
        }
        $wouldBlock = 0;
        if (!flock($handle, LOCK_EX | LOCK_NB, $wouldBlock)) {
            fclose($handle);
            if ($wouldBlock === 1) {
                throw new HostAuthorityBusy('storage authority lock is busy');
            }
            throw new ControlRefusal('storage authority lock could not be acquired privately');
        }
        try {
            // The host-global writer lock makes one destination-bound name the
            // complete crash-residue set. The committed journal remains the
            // only replay authority; an unpublished temporary is discarded.
            $this->reconcileStateTemporary();
            $state = $this->readState();
            if ($state['phase'] === 'applying') {
                if (!$verifyPhysical) {
                    throw new ControlRefusal('storage authority has an incomplete physical transition');
                }
                $this->apply($state['bindings'], $state['cleanup']);
                $state = self::stableState($state['bindings']);
                $this->writeState($state);
            } elseif ($verifyPhysical) {
                $this->verifyBindings($state['bindings']);
            } else {
                self::assertBindingSet($state['bindings']);
            }
            return $callback($state);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /** @param list<array<string,mixed>> $bindings @param list<array<string,mixed>> $cleanup */
    private function publish(array $bindings, array $cleanup): void {
        if (count($bindings) > self::BINDING_LIMIT || count($cleanup) > 1) {
            throw new ControlRefusal('storage binding registry is over its closed limit');
        }
        $this->writeState([
            'bindings' => $bindings,
            'cleanup' => $cleanup,
            'format' => self::STORE_FORMAT,
            'phase' => 'applying',
        ]);
        $this->apply($bindings, $cleanup);
        $this->writeState(self::stableState($bindings));
    }

    /** @param list<array<string,mixed>> $bindings @param list<array<string,mixed>> $cleanup */
    private function apply(array $bindings, array $cleanup): void {
        foreach ($cleanup as $binding) {
            foreach ($binding['volumes'] as $volume) {
                $this->clearQuota($volume);
            }
        }
        foreach ($bindings as $binding) {
            foreach ($binding['volumes'] as $volume) {
                $this->setQuota($volume);
            }
        }
        $this->verifyBindings($bindings);
    }

    /** @param list<array<string,mixed>> $bindings */
    private function verifyBindings(array $bindings): void {
        self::assertBindingSet($bindings);
        $projects = [];
        $volumes = [];
        $workerConfigurations = [];
        foreach ($bindings as $binding) {
            $workerConfigurations[$binding['configuration_sha256']] = true;
            foreach ($binding['volumes'] as $volume) {
                if (isset($projects[$volume['project_id']]) || isset($volumes[$volume['name']])) {
                    throw new ControlRefusal('storage binding project or volume identity is duplicated');
                }
                $projects[$volume['project_id']] = true;
                $volumes[$volume['name']] = true;
                $expected = $this->volume(
                    $binding['purpose'],
                    $binding['configuration_sha256'],
                    $binding['resource_id'],
                    $binding['lease_generation'],
                    $volume['kind'],
                    $volume['name'],
                    $volume['bytes'],
                    $volume['inodes']
                );
                if (CanonicalJson::encode($expected) !== CanonicalJson::encode($volume)) {
                    throw new ControlRefusal('storage Docker volume differs from durable authority');
                }
                $this->assertQuota($volume);
            }
        }
        foreach (array_keys($workerConfigurations) as $configuration) {
            $this->assertQuota($this->workerQuota($configuration));
        }
    }

    /** @param array<string,mixed> $state */
    private function verifyWorkerBindings(array $state, string $configuration): void {
        self::assertBindingSet($state['bindings']);
        foreach ($state['cleanup'] as $binding) {
            if (($binding['configuration_sha256'] ?? null) === $configuration) {
                throw new ControlRefusal('selected storage worker has an incomplete physical transition');
            }
        }
        foreach ($state['bindings'] as $binding) {
            if (($binding['configuration_sha256'] ?? null) !== $configuration) {
                continue;
            }
            foreach ($binding['volumes'] as $volume) {
                $expected = $this->volume(
                    $binding['purpose'],
                    $binding['configuration_sha256'],
                    $binding['resource_id'],
                    $binding['lease_generation'],
                    $volume['kind'],
                    $volume['name'],
                    $volume['bytes'],
                    $volume['inodes']
                );
                if (CanonicalJson::encode($expected) !== CanonicalJson::encode($volume)) {
                    throw new ControlRefusal('storage Docker volume differs from durable authority');
                }
                $this->assertQuota($volume);
            }
        }
    }

    /** @return array{bytes:int,inodes:int,mountpoint:string,project_id:int} */
    private function workerQuota(string $configuration): array {
        $this->configuration($configuration);
        $configured = $this->workerRoots[$configuration];
        $path = realpath($configured);
        if (!is_string($path) || $path !== $configured || is_link($configured)
            || !is_dir($path) || !self::within($path, $this->mountpoint)
            || $path === $this->mountpoint || self::within($this->dockerRoot, $path)
            || self::within($path, $this->dockerRoot)) {
            throw new ControlRefusal('selected storage worker root is absent or physically unsafe');
        }
        return [
            'bytes' => $this->limits['worker_bytes'],
            'inodes' => $this->limits['worker_inodes'],
            'mountpoint' => $path,
            'project_id' => self::workerProjectId($path),
        ];
    }

    private static function workerProjectId(string $path): int {
        return self::WORKER_PROJECT_MIN + (hexdec(substr(hash(
            'sha256',
            "duo-cloud-xfs-worker-project/v3\0$path"
        ), 0, 8)) % self::WORKER_PROJECT_SPAN);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function binding(array $input): array {
        self::exactKeys($input, [
            'configuration_sha256', 'database_volume', 'filesystem_volume',
            'lease_generation', 'resource_id',
        ]);
        $configuration = $this->configuration($input['configuration_sha256'] ?? null);
        $resourceId = $input['resource_id'] ?? null;
        $generation = $input['lease_generation'] ?? null;
        self::resource($resourceId, $generation);
        $token = substr($resourceId, strlen('cloud-slot-'));
        $suffix = '-g' . sprintf('%010d', $generation);
        $expected = [
            'database' => 'duo-preview-db-' . $token . $suffix,
            'filesystem' => 'duo-preview-fs-' . $token . $suffix,
        ];
        if (($input['database_volume'] ?? null) !== $expected['database']
            || ($input['filesystem_volume'] ?? null) !== $expected['filesystem']) {
            throw new ControlRefusal('storage volume names do not derive from generation authority');
        }
        $volumes = [];
        foreach ($expected as $kind => $name) {
            $bytes = $this->limits[$kind . '_bytes'];
            $inodes = $this->limits[$kind . '_inodes'];
            $volumes[] = $this->volume(
                self::PURPOSE_RUNTIME,
                $configuration,
                $resourceId,
                $generation,
                $kind,
                $name,
                $bytes,
                $inodes
            );
        }
        return [
            'configuration_sha256' => $configuration,
            'lease_generation' => $generation,
            'purpose' => self::PURPOSE_RUNTIME,
            'resource_id' => $resourceId,
            'volumes' => $volumes,
        ];
    }

    /** @return array<string,mixed> */
    private function proofBinding(string $configurationSha256, string $proofId): array {
        $configuration = $this->configuration($configurationSha256);
        self::proofId($proofId);
        $resourceId = self::proofResourceId($proofId);
        $expected = [
            'database' => 'duo-proof-db-' . $proofId,
            'filesystem' => 'duo-proof-fs-' . $proofId,
        ];
        $volumes = [];
        foreach ($expected as $kind => $name) {
            $volumes[] = $this->volume(
                self::PURPOSE_PROOF,
                $configuration,
                $resourceId,
                0,
                $kind,
                $name,
                self::PROOF_BYTES,
                self::PROOF_INODES
            );
        }
        return [
            'configuration_sha256' => $configuration,
            'lease_generation' => 0,
            'purpose' => self::PURPOSE_PROOF,
            'resource_id' => $resourceId,
            'volumes' => $volumes,
        ];
    }

    /** @return array<string,mixed> */
    private function volume(
        string $purpose,
        string $configuration,
        string $resourceId,
        int $generation,
        string $kind,
        string $name,
        int $bytes,
        int $inodes
    ): array {
        $result = $this->mustRun([
            $this->engineBinary, 'volume', 'inspect', '--format', '{{json .}}', $name,
        ], 'storage Docker volume readback');
        try {
            $volume = json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new ControlRefusal('storage Docker volume readback is not JSON', 0, $error);
        }
        $expectedMountpoint = $this->dockerRoot . '/volumes/' . $name . '/_data';
        $expectedLabels = $purpose === self::PURPOSE_PROOF
            ? [
                'duo.cloud.configuration-sha256' => $configuration,
                'duo.cloud.data-kind' => $kind,
                'duo.cloud.host-boundary-proof' => $configuration,
                'duo.cloud.proof-id' => substr($resourceId, strlen('host-proof-')),
                'duo.cloud.storage-purpose' => self::PURPOSE_PROOF,
            ]
            : [
                'duo.cloud.configuration-sha256' => $configuration,
                'duo.cloud.data-kind' => $kind,
                'duo.cloud.lease-generation' => (string) $generation,
                'duo.cloud.resource-id' => $resourceId,
            ];
        if (!is_array($volume) || array_is_list($volume)
            || ($volume['Name'] ?? null) !== $name || ($volume['Driver'] ?? null) !== 'local'
            || ($volume['Mountpoint'] ?? null) !== $expectedMountpoint
            || !is_array($volume['Labels'] ?? null)
            || CanonicalJson::encode($volume['Labels']) !== CanonicalJson::encode($expectedLabels)) {
            throw new ControlRefusal('storage cannot prove exact Docker volume ownership and mountpoint');
        }
        $projectId = self::projectId($configuration, $name);
        return [
            'bytes' => $bytes,
            'inodes' => $inodes,
            'kind' => $kind,
            'mountpoint' => $expectedMountpoint,
            'name' => $name,
            'project_id' => $projectId,
        ];
    }

    /** @param array<string,mixed> $volume */
    private function setQuota(array $volume): void {
        $this->assertEncryptedMount($volume['mountpoint']);
        $this->mustRun([
            $this->xfsQuotaBinary, '-x', '-c',
            'project -s -p ' . $volume['mountpoint'] . ' ' . $volume['project_id'],
            $this->mountpoint,
        ], 'XFS project assignment');
        $kilobytes = intdiv($volume['bytes'], 1024);
        $this->mustRun([
            $this->xfsQuotaBinary, '-x', '-c',
            'limit -p bsoft=' . $kilobytes . 'k bhard=' . $kilobytes . 'k isoft='
                . $volume['inodes'] . ' ihard=' . $volume['inodes'] . ' ' . $volume['project_id'],
            $this->mountpoint,
        ], 'XFS project quota publish');
        $this->assertQuota($volume);
    }

    /** @param array<string,mixed> $volume */
    private function clearQuota(array $volume): void {
        $this->mustRun([
            $this->xfsQuotaBinary, '-x', '-c',
            'limit -p bsoft=0 bhard=0 isoft=0 ihard=0 ' . $volume['project_id'],
            $this->mountpoint,
        ], 'XFS project quota clear');
        if (is_dir($volume['mountpoint']) && !is_link($volume['mountpoint'])) {
            $this->mustRun([
                $this->xfsQuotaBinary, '-x', '-c',
                'project -C -p ' . $volume['mountpoint'] . ' ' . $volume['project_id'],
                $this->mountpoint,
            ], 'XFS project assignment clear');
        }
    }

    /** @param array<string,mixed> $volume */
    private function assertQuota(array $volume): void {
        $this->assertEncryptedMount($volume['mountpoint']);
        $stat = $this->mustRun([
            $this->xfsIoBinary, '-c', 'stat -v', $volume['mountpoint'],
        ], 'XFS project id readback');
        if (preg_match('/^fsxattr\.projid = ([0-9]+)$/m', $stat['stdout'], $match) !== 1
            || (int) $match[1] !== $volume['project_id']) {
            throw new ControlRefusal('XFS project id differs from storage authority');
        }
        if (preg_match('/^fsxattr\.xflags = 0x([a-fA-F0-9]+)\b/m', $stat['stdout'], $flags) !== 1
            || (hexdec($flags[1]) & 0x00000200) !== 0x00000200) {
            throw new ControlRefusal('XFS project inheritance flag differs from storage authority');
        }
        $tree = $this->mustRun([
            $this->xfsQuotaBinary, '-x', '-c',
            'project -c -p ' . $volume['mountpoint'] . ' ' . $volume['project_id'],
            $this->mountpoint,
        ], 'XFS project descendant membership readback');
        $expectedTree = 'Checking project ' . $volume['project_id'] . ' (path '
            . $volume['mountpoint'] . ")...\nProcessed 1 (/etc/projects and cmdline) paths for project "
            . $volume['project_id'] . " with recursion depth infinite (-1).\n";
        if ($tree['stdout'] !== $expectedTree) {
            throw new ControlRefusal('XFS project descendant membership differs from storage authority');
        }
        $report = $this->mustRun([
            $this->xfsQuotaBinary, '-x', '-c', 'report -p -n -N -b -i', $this->mountpoint,
        ], 'XFS project quota readback');
        $pattern = '/^#' . preg_quote((string) $volume['project_id'], '/')
            . '\s+[0-9]+\s+([0-9]+)\s+([0-9]+)\s+[0-9-]+\s+\[[^\]]+\]'
            . '\s+[0-9]+\s+([0-9]+)\s+([0-9]+)\s+[0-9-]+\s+\[[^\]]+\]$/m';
        if (preg_match($pattern, $report['stdout'], $match) !== 1
            || (int) $match[1] !== intdiv($volume['bytes'], 1024)
            || (int) $match[2] !== intdiv($volume['bytes'], 1024)
            || (int) $match[3] !== $volume['inodes']
            || (int) $match[4] !== $volume['inodes']) {
            throw new ControlRefusal('XFS project byte or inode quota differs from storage authority');
        }
    }

    private function assertGlobalEncryptedSurface(): void {
        $this->assertEncryptedMount($this->dockerRoot);
        $result = $this->mustRun([
            $this->dmsetupBinary, 'info', '--noheadings', '--columns', '--separator', '|',
            '-o', 'name,uuid,major,minor', $this->mapperPath,
        ], 'dm-crypt identity readback');
        $parts = explode('|', trim($result['stdout']));
        if (count($parts) !== 4 || trim($parts[0]) !== $this->mapperName
            || trim($parts[1]) !== $this->mapperUuid
            || preg_match('/\A[0-9]+\z/D', trim($parts[2])) !== 1
            || preg_match('/\A[0-9]+\z/D', trim($parts[3])) !== 1) {
            throw new ControlRefusal('dm-crypt device identity differs from its pinned mapper UUID');
        }
        $status = $this->mustRun([
            $this->cryptsetupBinary, 'status', $this->mapperName,
        ], 'LUKS2 active mapping readback');
        $lines = explode("\n", rtrim($status['stdout'], "\n"));
        $header = array_shift($lines);
        if (!in_array($header, [
            $this->mapperPath . ' is active.',
            $this->mapperPath . ' is active and is in use.',
        ], true)) {
            throw new ControlRefusal('active dm-crypt mapping is not the pinned LUKS2 device');
        }
        $fields = [];
        foreach ($lines as $line) {
            if (preg_match('/\A\s+([a-z][a-z ]*):\s+(.+)\z/D', $line, $match) !== 1
                || isset($fields[$match[1]])) {
                throw new ControlRefusal('cryptsetup status readback is not canonical and unique');
            }
            $fields[$match[1]] = $match[2];
        }
        if (($fields['type'] ?? null) !== 'LUKS2'
            || ($fields['cipher'] ?? null) !== $this->cipher
            || ($fields['key location'] ?? null) !== $this->keyLocation
            || ($fields['keysize'] ?? null) !== $this->keySizeBits . ' bits'
            || ($fields['device'] ?? null) !== $this->backingDevice
            || ($fields['sector size'] ?? null) !== (string) $this->sectorSizeBytes
            || ($fields['offset'] ?? null) !== $this->payloadOffsetSectors . ' sectors'
            || ($fields['size'] ?? null) !== $this->mapperSizeSectors . ' sectors'
            || ($fields['mode'] ?? null) !== 'read/write') {
            throw new ControlRefusal('active dm-crypt mapping parameters differ from storage authority');
        }
        $uuid = $this->mustRun([
            $this->cryptsetupBinary, 'luksUUID', '--type', 'luks2', $this->backingDevice,
        ], 'LUKS2 header UUID readback');
        if ($uuid['stdout'] !== $this->luksUuid . "\n") {
            throw new ControlRefusal('LUKS2 header UUID differs from storage authority');
        }
    }

    private function assertEncryptedMount(string $path): void {
        $result = $this->mustRun([
            $this->findmntBinary, '--json', '--target', $path,
            '--output', 'TARGET,SOURCE,FSTYPE,OPTIONS,UUID',
        ], 'encrypted XFS mount readback');
        try {
            $document = json_decode($result['stdout'], true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new ControlRefusal('encrypted XFS mount readback is not JSON', 0, $error);
        }
        $filesystems = is_array($document) ? ($document['filesystems'] ?? null) : null;
        $filesystem = is_array($filesystems) && array_is_list($filesystems)
            && count($filesystems) === 1 ? $filesystems[0] : null;
        $options = is_array($filesystem) && is_string($filesystem['options'] ?? null)
            ? explode(',', $filesystem['options']) : [];
        if (!is_array($filesystem) || array_is_list($filesystem)
            || ($filesystem['target'] ?? null) !== $this->mountpoint
            || ($filesystem['source'] ?? null) !== $this->mapperPath
            || ($filesystem['fstype'] ?? null) !== 'xfs'
            || ($filesystem['uuid'] ?? null) !== $this->filesystemUuid
            || !in_array('prjquota', $options, true)) {
            throw new ControlRefusal('durable path is not on the exact encrypted project-quota XFS mount');
        }
    }

    /** @return array<string,mixed> */
    private function stableReadState(): array {
        $last = null;
        for ($attempt = 0; $attempt < 3; $attempt++) {
            try {
                return $this->readState();
            } catch (ControlRefusal $error) {
                $last = $error;
            }
        }
        throw new ControlRefusal(
            'storage authority state could not be read as one stable publication',
            0,
            $last
        );
    }

    /** @return array<string,mixed> */
    private function readState(): array {
        clearstatcache(true, $this->statePath);
        if (!file_exists($this->statePath) && !is_link($this->statePath)) {
            return self::stableState([]);
        }
        $before = @lstat($this->statePath);
        if (!is_array($before) || is_link($this->statePath)
            || ($before['mode'] & 0170000) !== 0100000
            || (int) ($before['nlink'] ?? 0) !== 1
            || (DIRECTORY_SEPARATOR === '/' && ($before['mode'] & 0777) !== 0600)
            || (function_exists('posix_geteuid') && (int) $before['uid'] !== posix_geteuid())) {
            throw new ControlRefusal('storage authority state is unavailable');
        }
        $handle = @fopen($this->statePath, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal('storage authority state is unavailable');
        }
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || !self::sameFile($before, $opened)
                || (int) $opened['size'] < 2 || (int) $opened['size'] > self::STATE_LIMIT) {
                throw new ControlRefusal('storage authority state changed while it was read');
            }
            $bytes = stream_get_contents($handle, self::STATE_LIMIT + 1);
        } finally {
            $closed = fclose($handle);
        }
        clearstatcache(true, $this->statePath);
        $after = @lstat($this->statePath);
        if (!is_array($after) || !is_string($bytes) || !$closed
            || strlen($bytes) > self::STATE_LIMIT || !self::sameFile($before, $after)) {
            throw new ControlRefusal('storage authority state changed while it was read');
        }
        $state = CanonicalJson::decodeObject($bytes, self::STATE_LIMIT);
        self::exactKeys($state, ['bindings', 'cleanup', 'format', 'phase']);
        if ($bytes !== CanonicalJson::encode($state) . "\n" || $state['format'] !== self::STORE_FORMAT
            || !is_array($state['bindings']) || !array_is_list($state['bindings'])
            || !is_array($state['cleanup']) || !array_is_list($state['cleanup'])
            || !in_array($state['phase'], ['applying', 'stable'], true)) {
            throw new ControlRefusal('storage authority state is not canonical');
        }
        $bindings = [];
        if (count($state['bindings']) > self::BINDING_LIMIT || count($state['cleanup']) > 1) {
            throw new ControlRefusal('storage authority binding registry is over its closed limit');
        }
        foreach ($state['bindings'] as $binding) {
            if (!is_array($binding) || array_is_list($binding)) {
                throw new ControlRefusal('storage authority binding state is invalid');
            }
            $bindings[] = $this->storedBinding($binding);
        }
        self::sortBindings($bindings);
        if (CanonicalJson::encode($bindings) !== CanonicalJson::encode($state['bindings'])) {
            throw new ControlRefusal('storage authority bindings are not canonical and unique');
        }
        $cleanup = [];
        foreach ($state['cleanup'] as $binding) {
            if (!is_array($binding) || array_is_list($binding)) {
                throw new ControlRefusal('storage authority cleanup state is invalid');
            }
            $cleanup[] = $this->storedBinding($binding);
        }
        if ($state['phase'] === 'stable' && $state['cleanup'] !== []) {
            throw new ControlRefusal('stable storage authority state retains cleanup intent');
        }
        if ($state['phase'] === 'applying' && $cleanup !== []
            && isset(self::bindingMap($bindings)[self::bindingKey($cleanup[0])])) {
            throw new ControlRefusal('storage cleanup intent remains in the desired binding set');
        }
        return ['bindings' => $bindings, 'cleanup' => $cleanup] + $state;
    }

    /** @param array<string,mixed> $binding @return array<string,mixed> */
    private function storedBinding(array $binding): array {
        self::exactKeys($binding, [
            'configuration_sha256', 'lease_generation', 'purpose', 'resource_id', 'volumes',
        ]);
        $this->configuration($binding['configuration_sha256'] ?? null);
        $purpose = $binding['purpose'] ?? null;
        if ($purpose === self::PURPOSE_RUNTIME) {
            self::resource($binding['resource_id'] ?? null, $binding['lease_generation'] ?? null);
        } elseif ($purpose === self::PURPOSE_PROOF) {
            $resourceId = $binding['resource_id'] ?? null;
            $generation = $binding['lease_generation'] ?? null;
            if (!is_string($resourceId) || !str_starts_with($resourceId, 'host-proof-')
                || $generation !== 0) {
                throw new ControlRefusal('storage proof identity is invalid');
            }
            self::proofId(substr($resourceId, strlen('host-proof-')));
        } else {
            throw new ControlRefusal('storage authority binding purpose is invalid');
        }
        if (!is_array($binding['volumes'] ?? null) || !array_is_list($binding['volumes'])
            || count($binding['volumes']) !== 2) {
            throw new ControlRefusal('storage authority volume binding is invalid');
        }
        $expectedKinds = ['database', 'filesystem'];
        foreach ($binding['volumes'] as $index => $volume) {
            if (!is_array($volume) || array_is_list($volume)) {
                throw new ControlRefusal('storage authority volume state is malformed');
            }
            self::exactKeys($volume, ['bytes', 'inodes', 'kind', 'mountpoint', 'name', 'project_id']);
            $kind = $expectedKinds[$index];
            $resourceId = $binding['resource_id'];
            $generation = $binding['lease_generation'];
            $name = $purpose === self::PURPOSE_PROOF
                ? 'duo-proof-' . ($kind === 'database' ? 'db' : 'fs') . '-'
                    . substr($resourceId, strlen('host-proof-'))
                : 'duo-preview-' . ($kind === 'database' ? 'db' : 'fs') . '-'
                    . substr($resourceId, strlen('cloud-slot-')) . '-g' . sprintf('%010d', $generation);
            $expected = [
                'bytes' => $purpose === self::PURPOSE_PROOF
                    ? self::PROOF_BYTES : $this->limits[$kind . '_bytes'],
                'inodes' => $purpose === self::PURPOSE_PROOF
                    ? self::PROOF_INODES : $this->limits[$kind . '_inodes'],
                'kind' => $kind,
                'mountpoint' => $this->dockerRoot . '/volumes/' . $name . '/_data',
                'name' => $name,
                'project_id' => self::projectId($binding['configuration_sha256'], $name),
            ];
            if (CanonicalJson::encode($volume) !== CanonicalJson::encode($expected)) {
                throw new ControlRefusal('storage authority volume state differs from derived authority');
            }
        }
        return $binding;
    }

    /** @param array<string,mixed> $state */
    private function writeState(array $state): void {
        $bytes = CanonicalJson::encode($state) . "\n";
        if (strlen($bytes) > self::STATE_LIMIT) {
            throw new ControlRefusal('storage authority state exceeds its durable byte limit');
        }
        $temporary = $this->stateTemporaryPath;
        $this->reconcileStateTemporary();
        $this->assertAbsentOrPrivateFile($this->statePath, 'storage authority state');
        $handle = $this->createStateTemporary($bytes);
        $published = false;
        try {
            self::testCheckpoint('state-temporary-synchronized');
            if (!@rename($temporary, $this->statePath)) {
                throw new ControlRefusal('storage authority state could not be published atomically');
            }
            $published = true;
            $this->assertOpenedPrivateFile(
                $handle,
                $this->statePath,
                'storage authority state',
                strlen($bytes)
            );
            $this->syncDirectory('storage authority state directory');
            if (!fclose($handle)) {
                $handle = null;
                throw new ControlRefusal('storage authority state could not be synchronized');
            }
            $handle = null;
            $readback = $this->readPrivateBytes(
                $this->statePath,
                'storage authority state',
                2,
                self::STATE_LIMIT
            );
            if (!hash_equals(hash('sha256', $bytes), hash('sha256', $readback))) {
                throw new ControlRefusal('storage authority durable state readback differs after publish');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (!$published && (file_exists($temporary) || is_link($temporary))) {
                $this->reconcileStateTemporary();
            }
        }
    }

    /** @return resource */
    private function createStateTemporary(string $bytes) {
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($this->stateTemporaryPath, 'x+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($handle)) {
            throw new ControlRefusal('storage authority state could not be created privately');
        }
        try {
            $this->assertOpenedPrivateFile(
                $handle,
                $this->stateTemporaryPath,
                'storage authority state temporary file',
                0
            );
            self::writeAll($handle, $bytes);
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new ControlRefusal('storage authority state could not be synchronized');
            }
            $this->assertOpenedPrivateFile(
                $handle,
                $this->stateTemporaryPath,
                'storage authority state temporary file',
                strlen($bytes)
            );
        } catch (\Throwable $error) {
            try {
                $this->removeOpenedTemporary(
                    $handle,
                    $this->stateTemporaryPath,
                    'storage authority state temporary file'
                );
            } finally {
                fclose($handle);
            }
            throw $error;
        }
        return $handle;
    }

    private function reconcileStateTemporary(): void {
        $path = $this->stateTemporaryPath;
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false) {
            return;
        }
        self::assertPrivateStat($before, $path, 'storage authority state temporary file');
        if ((int) ($before['size'] ?? -1) < 0 || (int) $before['size'] > self::STATE_LIMIT) {
            throw new ControlRefusal('storage authority state temporary file has an invalid size');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal('storage authority state temporary file could not be opened for recovery');
        }
        try {
            $this->removeOpenedTemporary(
                $handle,
                $path,
                'storage authority state temporary file'
            );
        } finally {
            fclose($handle);
        }
        $this->syncDirectory('storage authority state temporary file directory');
    }

    /** @param resource $handle */
    private function removeOpenedTemporary($handle, string $path, string $label): void {
        clearstatcache(true, $path);
        $before = @lstat($path);
        $opened = fstat($handle);
        if (!is_array($before) || !is_array($opened) || !self::sameFile($before, $opened)) {
            throw new ControlRefusal("$label changed during recovery");
        }
        self::assertPrivateStat($before, $path, $label);
        if (!@unlink($path)) {
            throw new ControlRefusal("$label could not be removed during recovery");
        }
        $unlinked = fstat($handle);
        clearstatcache(true, $path);
        if (!is_array($unlinked) || @lstat($path) !== false
            || (int) $unlinked['dev'] !== (int) $opened['dev']
            || (int) $unlinked['ino'] !== (int) $opened['ino']
            || (int) ($unlinked['nlink'] ?? -1) !== 0) {
            throw new ControlRefusal("$label changed while being removed");
        }
    }

    /** @param resource $handle */
    private function assertOpenedPrivateFile(
        $handle,
        string $path,
        string $label,
        int $expectedSize
    ): void {
        clearstatcache(true, $path);
        $named = @lstat($path);
        $opened = fstat($handle);
        if (!is_array($named) || !is_array($opened) || !self::sameFile($named, $opened)
            || (int) ($opened['size'] ?? -1) !== $expectedSize) {
            throw new ControlRefusal("$label changed while opening");
        }
        self::assertPrivateStat($opened, $path, $label);
    }

    /** @param array<string|int,mixed> $stat */
    private static function assertPrivateStat(array $stat, string $path, string $label): void {
        if (($stat['mode'] & 0170000) !== 0100000 || is_link($path)
            || (int) ($stat['nlink'] ?? 0) !== 1
            || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0777) !== 0600)
            || (function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid())) {
            throw new ControlRefusal(
                "$label must be a process-owned, single-link, mode-0600 regular file"
            );
        }
    }

    private function assertAbsentOrPrivateFile(string $path, string $label): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if ($stat !== false) {
            self::assertPrivateStat($stat, $path, $label);
        }
    }

    private function readPrivateBytes(
        string $path,
        string $label,
        int $minimum,
        int $maximum
    ): string {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before)) {
            throw new ControlRefusal("$label is unavailable");
        }
        self::assertPrivateStat($before, $path, $label);
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal("$label is unavailable");
        }
        try {
            $opened = fstat($handle);
            if (!is_array($opened) || !self::sameFile($before, $opened)
                || (int) $opened['size'] < $minimum || (int) $opened['size'] > $maximum) {
                throw new ControlRefusal("$label changed while opening");
            }
            $bytes = stream_get_contents($handle, $maximum + 1);
        } finally {
            $closed = fclose($handle);
        }
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_string($bytes) || !$closed || !is_array($after)
            || !self::sameFile($before, $after)) {
            throw new ControlRefusal("$label changed while reading");
        }
        return $bytes;
    }

    /** @param resource $handle */
    private static function writeAll($handle, string $bytes): void {
        $offset = 0;
        $length = strlen($bytes);
        while ($offset < $length) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) {
                throw new ControlRefusal('storage authority state could not be written completely');
            }
            $offset += $written;
        }
    }

    private function syncDirectory(string $label): void {
        if (!function_exists('fsync')) {
            return;
        }
        $handle = @fopen($this->directory, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal("$label could not be opened for synchronization");
        }
        $synced = @fsync($handle);
        $closed = fclose($handle);
        if (!$synced || !$closed) {
            throw new ControlRefusal("$label could not be synchronized");
        }
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function sameFile(array $left, array $right): bool {
        return (int) $left['dev'] === (int) $right['dev']
            && (int) $left['ino'] === (int) $right['ino']
            && (int) $left['mode'] === (int) $right['mode']
            && (int) $left['uid'] === (int) $right['uid']
            && (int) ($left['nlink'] ?? 0) === (int) ($right['nlink'] ?? 0)
            && (int) $left['size'] === (int) $right['size'];
    }

    private static function testCheckpoint(string $phase): void {
        if (!function_exists('posix_kill')
            || getenv('DUO_TEST_XFS_STORAGE_KILL_PHASE') !== $phase) {
            return;
        }
        posix_kill(getmypid(), SIGKILL);
        usleep(1000000);
        exit(137);
    }

    /** @param list<array<string,mixed>> $bindings @return array<string,mixed> */
    private static function stableState(array $bindings): array {
        return ['bindings' => $bindings, 'cleanup' => [], 'format' => self::STORE_FORMAT, 'phase' => 'stable'];
    }

    /** @param list<array<string,mixed>> $bindings */
    private static function sortBindings(array &$bindings): void {
        usort($bindings, static fn (array $left, array $right): int => strcmp(
            self::bindingKey($left),
            self::bindingKey($right)
        ));
    }

    /** @param list<array<string,mixed>> $bindings @return array<string,array<string,mixed>> */
    private static function bindingMap(array $bindings): array {
        $map = [];
        foreach ($bindings as $binding) {
            $key = self::bindingKey($binding);
            if (isset($map[$key])) {
                throw new ControlRefusal('storage authority binding identity is duplicated');
            }
            $map[$key] = $binding;
        }
        return $map;
    }

    /** @param list<array<string,mixed>> $bindings */
    private static function assertBindingSet(array $bindings): void {
        if (count($bindings) > self::BINDING_LIMIT) {
            throw new ControlRefusal('storage authority binding registry is over its closed limit');
        }
        $projects = [];
        $volumes = [];
        $runtimeBindings = 0;
        $proofBindings = 0;
        foreach ($bindings as $binding) {
            if (($binding['purpose'] ?? null) === self::PURPOSE_RUNTIME) {
                $runtimeBindings++;
            } elseif (($binding['purpose'] ?? null) === self::PURPOSE_PROOF) {
                $proofBindings++;
            } else {
                throw new ControlRefusal('storage authority binding purpose is invalid');
            }
            foreach ($binding['volumes'] as $volume) {
                if (isset($projects[$volume['project_id']]) || isset($volumes[$volume['name']])) {
                    throw new ControlRefusal('storage binding project or volume identity is duplicated');
                }
                $projects[$volume['project_id']] = true;
                $volumes[$volume['name']] = true;
            }
        }
        if ($runtimeBindings > self::RUNTIME_BINDING_LIMIT
            || $proofBindings > self::PROOF_BINDING_LIMIT) {
            throw new ControlRefusal('storage authority purpose registry is over its closed limit');
        }
    }

    private static function projectId(string $configuration, string $name): int {
        return self::VOLUME_PROJECT_MIN + (hexdec(substr(hash(
            'sha256',
            "duo-cloud-xfs-project/v2\0$configuration\0$name"
        ), 0, 8)) % self::VOLUME_PROJECT_SPAN);
    }

    /** @param array<string,mixed> $binding */
    private static function bindingKey(array $binding): string {
        return self::key(
            $binding['purpose'],
            $binding['configuration_sha256'],
            $binding['resource_id'],
            $binding['lease_generation']
        );
    }

    private static function key(
        string $purpose,
        string $configuration,
        string $resourceId,
        int $generation
    ): string {
        return $purpose . "\0" . $configuration . "\0" . $resourceId . "\0"
            . sprintf('%010d', $generation);
    }

    private function configuration(mixed $configuration): string {
        self::sha256($configuration, 'storage configuration digest');
        if (!isset($this->configurations[$configuration])) {
            throw new ControlRefusal('storage configuration is not registered');
        }
        return $configuration;
    }

    private static function resource(mixed $resourceId, mixed $generation): void {
        if (!is_string($resourceId)
            || preg_match('/\Acloud-slot-[a-f0-9]{64}\z/D', $resourceId) !== 1
            || !is_int($generation) || $generation < 1 || $generation > 9999999999) {
            throw new ControlRefusal('storage generation authority is invalid');
        }
    }

    private static function proofId(mixed $proofId): void {
        if (!is_string($proofId) || preg_match('/\A[a-f0-9]{64}\z/D', $proofId) !== 1) {
            throw new ControlRefusal('storage host-preflight proof identity is invalid');
        }
    }

    private static function proofResourceId(string $proofId): string {
        self::proofId($proofId);
        return 'host-proof-' . $proofId;
    }

    /** @param array<string,mixed> $binding @return array<string,mixed> */
    private static function boundOutput(array $binding): array {
        $output = $binding;
        unset($output['purpose']);
        return ['format' => self::FORMAT, 'state' => 'bound'] + $output;
    }

    /** @param array<string,mixed> $binding @return array<string,mixed> */
    private static function proofBoundOutput(array $binding): array {
        $proofId = substr($binding['resource_id'], strlen('host-proof-'));
        $receiptInput = [
            'configuration_sha256' => $binding['configuration_sha256'],
            'proof_id' => $proofId,
            'volumes' => $binding['volumes'],
        ];
        return [
            'configuration_sha256' => $binding['configuration_sha256'],
            'format' => self::PROOF_FORMAT,
            'proof_id' => $proofId,
            'receipt_sha256' => hash(
                'sha256',
                "duo-cloud-xfs-host-preflight-proof-receipt/v1\0" . CanonicalJson::encode($receiptInput)
            ),
            'state' => 'bound',
            'volumes' => $binding['volumes'],
        ];
    }

    /** @return array<string,mixed> */
    private static function proofAbsentOutput(string $configuration, string $proofId): array {
        return [
            'configuration_sha256' => $configuration,
            'format' => self::PROOF_FORMAT,
            'proof_id' => $proofId,
            'state' => 'absent',
        ];
    }

    /** @return array<string,mixed> */
    private static function absentOutput(string $configuration, string $resourceId, int $generation): array {
        return [
            'configuration_sha256' => $configuration,
            'format' => self::FORMAT,
            'lease_generation' => $generation,
            'resource_id' => $resourceId,
            'state' => 'absent',
        ];
    }

    /** @return array<string,mixed> */
    /** @param list<string> $requiredPaths */
    private function readyOutput(int $bindings, array $requiredPaths, array $worker): array {
        return [
            'bindings' => $bindings,
            'docker_root' => $this->dockerRoot,
            'durable_paths_sha256' => hash(
                'sha256',
                "duo-cloud-encrypted-durable-paths/v1\0" . CanonicalJson::encode($this->durablePaths)
            ),
            'filesystem_uuid' => $this->filesystemUuid,
            'format' => self::FORMAT,
            'mapper_uuid' => $this->mapperUuid,
            'mountpoint' => $this->mountpoint,
            'required_paths_sha256' => hash(
                'sha256',
                "duo-cloud-required-durable-paths/v1\0" . CanonicalJson::encode($requiredPaths)
            ),
            'state' => 'ready',
            'worker_bytes' => $worker['bytes'],
            'worker_inodes' => $worker['inodes'],
            'worker_project_id' => $worker['project_id'],
            'worker_root_sha256' => hash(
                'sha256',
                "duo-cloud-worker-root/v1\0" . $worker['mountpoint']
            ),
        ];
    }

    /** @return array{exit:int,stderr:string,stdout:string} */
    private function mustRun(array $argv, string $label): array {
        $result = $this->runner->run($argv);
        if ($result['exit'] !== 0 || $result['stderr'] !== '') {
            throw new ControlRefusal("$label failed");
        }
        return $result;
    }

    /** @param array<string,mixed> $limits */
    private static function limits(array $limits): void {
        self::exactKeys($limits, [
            'database_bytes', 'database_inodes', 'filesystem_bytes', 'filesystem_inodes',
            'worker_bytes', 'worker_inodes',
        ]);
        foreach (['database_bytes', 'filesystem_bytes', 'worker_bytes'] as $field) {
            if (!is_int($limits[$field] ?? null) || $limits[$field] < 67108864
                || $limits[$field] > 1099511627776 || $limits[$field] % 1024 !== 0) {
                throw new ControlRefusal('storage byte quota is outside its closed aligned range');
            }
        }
        foreach (['database_inodes', 'filesystem_inodes', 'worker_inodes'] as $field) {
            if (!is_int($limits[$field] ?? null) || $limits[$field] < 1024
                || $limits[$field] > 10000000) {
                throw new ControlRefusal('storage inode quota is outside its closed range');
            }
        }
    }

    /** @param list<string> $paths */
    private static function canonicalPaths(array $paths): void {
        if ($paths === [] || count($paths) > 256) {
            throw new ControlRefusal('durable path registry is empty or unbounded');
        }
        $previous = null;
        foreach ($paths as $path) {
            if (!is_string($path) || $path === '' || $path[0] !== '/' || str_contains($path, "\0")
                || str_contains($path, '//') || str_ends_with($path, '/')
                || preg_match('#(?:^|/)\.\.?(/|$)#D', $path) === 1
                || ($previous !== null && strcmp($previous, $path) >= 0)) {
                throw new ControlRefusal('durable path registry is not canonical and unique');
            }
            $previous = $path;
        }
    }

    private static function within(string $path, string $root): bool {
        return $root === '/' || $path === $root || str_starts_with($path, $root . '/');
    }

    private static function normalizeConfiguredPath(
        string $path,
        string $configuredMountpoint,
        string $canonicalMountpoint
    ): string {
        if (!self::within($path, $configuredMountpoint)) {
            return $path;
        }
        return $canonicalMountpoint . substr($path, strlen($configuredMountpoint));
    }

    private static function canonicalDirectory(string $path, string $label): string {
        $real = realpath($path);
        if (!is_string($real) || !is_dir($real) || is_link($path)) {
            throw new ControlRefusal("$label is not a canonical directory");
        }
        return $real;
    }

    private static function commandPath(string $path, string $label): void {
        if (preg_match('#\A/[A-Za-z0-9._/-]+\z#D', $path) !== 1
            || preg_match('#(?:\A|/)\.\.?(/|\z)#D', $path) === 1) {
            throw new ControlRefusal("$label cannot cross the closed XFS command grammar");
        }
    }

    private static function privateRoot(string $path): string {
        $real = self::canonicalDirectory($path, 'storage authority state root');
        $stat = @lstat($real);
        if (!is_array($stat) || ($stat['mode'] & 0077) !== 0
            || function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid()) {
            throw new ControlRefusal('storage authority state root is not private and process-owned');
        }
        return $real;
    }

    private static function sha256(mixed $value, string $label): void {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new ControlRefusal("$label is not lowercase SHA-256");
        }
    }

    private static function absoluteExecutable(string $path, string $label): void {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0") || !is_executable($path)) {
            throw new ControlRefusal("$label executable is invalid");
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new ControlRefusal('storage authority object has missing or unknown fields');
        }
    }
}
