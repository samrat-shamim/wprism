<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ControlRefusal.php';
require_once dirname(__DIR__) . '/src/ImmutableOciReference.php';

/**
 * Immutable, closed production configuration for one Duo Cloud service worker.
 *
 * The deployment supplies both the file and its out-of-band SHA-256 pin. Key
 * bytes never enter this document: it binds private files by path and digest,
 * then re-checks ownership, mode, inode, and digest every time bytes are read.
 */
final class ProductionConfig {
    public const FORMAT = 'duo-cloud-production-config/v1';
    public const RUNTIME_CONTRACT_FORMAT = 'duo-cloud-runtime-image-contract/v1';

    private const CONFIG_LIMIT = 1048576;
    private const KEY_LIMIT = 1024;

    /** @param array<string,mixed> $document */
    private function __construct(
        private array $document,
        private string $sha256,
        private string $stateRoot,
        private string $hostPreflightRoot,
        private string $configPath
    ) {}

    public static function load(string $path, string $expectedSha256): self {
        $inspected = self::inspectForFleet($path, $expectedSha256);
        $document = $inspected->document;
        $stateRoot = self::privateStateRoot($document['state_root'] ?? null);
        $hostPreflightRoot = self::privateStateRoot($document['host_preflight_root'] ?? null);
        self::validateWorkerRoot($stateRoot, $hostPreflightRoot, $document['runtime']);

        /** @var array<string,mixed> $runtime */
        $runtime = $document['runtime'];
        self::executable($runtime['container_engine'], 'container engine');
        self::executable($runtime['firewall_authority'], 'firewall authority');
        self::executable($runtime['storage_authority'], 'storage authority');
        self::executable($runtime['git'], 'Git');
        self::executable($runtime['route_authority'], 'route authority');
        self::readRuntimeContract(
            $runtime['runtime_contract_file'],
            $runtime['runtime_contract_sha256']
        );
        self::readPinnedRegularFile(
            $runtime['seccomp_profile_file'],
            $runtime['seccomp_profile_sha256'],
            self::CONFIG_LIMIT,
            false,
            'seccomp profile'
        );
        self::approvedDirectory($runtime['repository_source'], 'repository source');
        self::approvedDirectory($runtime['snapshot_object_root'], 'snapshot object root');
        self::executable([
            'path' => $runtime['repository_remote']['credential_helper'],
            'sha256' => $runtime['repository_remote']['credential_helper_sha256'],
        ], 'repository credential helper');
        foreach ($document['host_durable_paths'] as $name => $durablePath) {
            self::hostDurableDirectory(
                $durablePath,
                str_replace('_', ' ', (string) $name),
                in_array($name, [
                    'firewall_authority_state_root', 'storage_authority_state_root',
                ], true)
            );
        }
        self::readKeyDescriptor(
            $document['service']['response_signing_key'],
            64,
            64,
            'response signing key'
        );
        self::readKeyDescriptor(
            $document['service']['device_digest_key'],
            32,
            64,
            'device digest key'
        );
        foreach ($document['controller_keys'] as $index => $key) {
            self::readKeyDescriptor($key['public_key'], 32, 32, "controller public key $index");
        }

        return new self(
            $document,
            $expectedSha256,
            $stateRoot,
            $hostPreflightRoot,
            $inspected->configPath
        );
    }

    /**
     * Validate only the immutable and physical inputs used by expired-preview cleanup.
     *
     * A worker must remain able to converge an already-authoritative generation
     * after unrelated serving material is rotated or lost. Cleanup still pins
     * every executable it can invoke, the reviewed seccomp bytes, and the Git
     * repository that can contain the generation-local candidate ref.
     */
    public static function loadForReap(string $path, string $expectedSha256): self {
        $inspected = self::inspectForFleet($path, $expectedSha256);
        $document = $inspected->document;
        $stateRoot = self::privateStateRoot($document['state_root'] ?? null);
        $hostPreflightRoot = $inspected->hostPreflightRoot;

        /** @var array<string,mixed> $runtime */
        $runtime = $document['runtime'];
        self::validateReapWorkerRoot($stateRoot, $hostPreflightRoot, $runtime);
        foreach ([
            'container_engine' => 'container engine',
            'firewall_authority' => 'firewall authority',
            'git' => 'Git',
            'process_launcher' => 'process launcher',
            'route_authority' => 'route authority',
            'storage_authority' => 'storage authority',
        ] as $field => $label) {
            self::executable($runtime[$field], $label);
        }
        self::readPinnedRegularFile(
            $runtime['seccomp_profile_file'],
            $runtime['seccomp_profile_sha256'],
            self::CONFIG_LIMIT,
            false,
            'seccomp profile'
        );
        self::approvedDirectory($runtime['repository_source'], 'repository source');

        return new self(
            $document,
            $expectedSha256,
            $stateRoot,
            $hostPreflightRoot,
            $inspected->configPath
        );
    }

    /**
     * Validate fleet identity and topology without touching worker-local paths.
     * Physical worker evidence belongs to the independently scheduled local
     * preflight, so one unavailable worker cannot revoke the shared host gate.
     */
    public static function inspectForFleet(string $path, string $expectedSha256): self {
        self::assertSha256($expectedSha256, 'production configuration pin');
        $bytes = self::readPinnedRegularFile(
            $path,
            $expectedSha256,
            self::CONFIG_LIMIT,
            false,
            'production configuration'
        );
        $document = CanonicalJson::decodeObject($bytes, self::CONFIG_LIMIT);
        if ($bytes !== CanonicalJson::encode($document) . "\n") {
            throw new ControlRefusal('production configuration is not canonical JSON with one trailing LF');
        }
        self::exactKeys(
            $document,
            [
                'controller_keys', 'format', 'host_durable_paths', 'host_preflight_root',
                'runtime', 'service', 'state_root',
            ],
            'production configuration'
        );
        if (($document['format'] ?? null) !== self::FORMAT) {
            throw new ControlRefusal('production configuration format is unsupported');
        }
        self::validateService($document['service'] ?? null);
        self::validateRuntime($document['runtime'] ?? null);
        self::validateHostDurablePaths($document['host_durable_paths'] ?? null);
        self::validateControllerKeys($document['controller_keys'] ?? null);
        $stateRoot = self::absoluteFilePath($document['state_root'] ?? null, 'production state root');
        $hostPreflightRoot = self::absoluteFilePath(
            $document['host_preflight_root'] ?? null,
            'production host preflight root'
        );
        self::validateWorkerRootPaths($stateRoot, $hostPreflightRoot, $document['runtime']);

        $configPath = realpath($path);
        if (!is_string($configPath)) {
            throw new ControlRefusal('production configuration path is not canonical');
        }
        return new self($document, $expectedSha256, $stateRoot, $hostPreflightRoot, $configPath);
    }

    public function sha256(): string {
        return $this->sha256;
    }

    public function stateRoot(): string {
        return $this->stateRoot;
    }

    public function hostPreflightRoot(): string {
        return $this->hostPreflightRoot;
    }

    public function configPath(): string {
        return $this->configPath;
    }

    public function workerRoot(): string {
        return dirname($this->stateRoot);
    }

    public function hostAuthoritySha256(): string {
        $runtime = $this->runtime();
        return hash(
            'sha256',
            "duo-cloud-production-host-authorities/v1\0" . CanonicalJson::encode([
                'firewall_authority' => $runtime['firewall_authority'],
                'route_authority' => $runtime['route_authority'],
                'storage_authority' => $runtime['storage_authority'],
            ])
        );
    }

    public function authorityConfigRoot(): string {
        return $this->document['host_durable_paths']['authority_config_root'];
    }

    public function reviewedBaseSha256(): string {
        $runtime = $this->runtime();
        $image = $runtime['image'];
        if (!is_string($image) || !ImmutableOciReference::valid($image)) {
            throw new ControlRefusal('production workload image is not an immutable OCI reference');
        }
        $reviewedBase = [
            'format' => 'duo-reviewed-preview-base/v1',
            'image_digest' => ImmutableOciReference::digest($image),
            'platform_fingerprint_sha256' => $runtime['platform_fingerprint_sha256'],
            'review_receipt_sha256' => $runtime['review_receipt_sha256'],
        ];
        $basis = [
            'egress_evidence' => 'host-nft-input-forward-default-deny-readback/v1',
            'format' => 'duo-reviewed-preview-base-containment/v1',
            'image_reference' => $image,
            'reviewed_base' => $reviewedBase,
            'routing_evidence' => 'credential-free-route-authority-readback/v1',
            'runtime_configuration_sha256' => $this->sha256,
            'seccomp_profile_sha256' => $runtime['seccomp_profile_sha256'],
            'secrets_evidence' => 'generation-private-files-readonly-mount-readback/v1',
            'storage_evidence' => 'dm-crypt-xfs-project-quota-exact-readback/v1',
        ];
        return hash(
            'sha256',
            "duo-reviewed-preview-base-containment/v1\0" . CanonicalJson::encode($basis)
        );
    }

    /** @return array<string,mixed> */
    public function fleetWorkerDescriptor(string $workerId): array {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/D', $workerId) !== 1) {
            throw new ControlRefusal('production fleet worker id is invalid');
        }
        $runtime = $this->runtime();
        return [
            'configuration_sha256' => $this->sha256,
            'preview_domain' => $runtime['preview_domain'],
            'principal' => $this->principal(),
            'reviewed_base_sha256' => $this->reviewedBaseSha256(),
            'state_root' => $this->stateRoot,
            'worker_id' => $workerId,
            'worker_root' => $this->workerRoot(),
        ];
    }

    /**
     * Pin only the executables and private root required by the host-global
     * coordinator. No worker key, repository, snapshot, or quota path is read.
     */
    public function assertHostPreflightRuntime(): void {
        self::privateStateRoot($this->hostPreflightRoot);
        $runtime = $this->runtime();
        self::executable($runtime['process_launcher'], 'process launcher');
        self::executable($runtime['firewall_authority'], 'firewall authority');
        self::executable($runtime['route_authority'], 'route authority');
        self::executable($runtime['storage_authority'], 'storage authority');
    }

    /** @return list<string> */
    public function requiredDurablePaths(): array {
        $runtime = $this->runtime();
        $service = $this->service();
        $paths = [
            $this->configPath,
            $this->hostPreflightRoot,
            $this->stateRoot,
            (string) realpath($runtime['repository_source']),
            (string) realpath($runtime['snapshot_object_root']),
            (string) realpath($service['device_digest_key']['path']),
            (string) realpath($service['response_signing_key']['path']),
        ];
        foreach ($this->controllerKeys() as $key) {
            $paths[] = (string) realpath($key['public_key']['path']);
        }
        foreach ($this->document['host_durable_paths'] as $name => $path) {
            $paths[] = self::hostDurableDirectory(
                $path,
                (string) $name,
                in_array($name, [
                    'firewall_authority_state_root', 'storage_authority_state_root',
                ], true)
            );
        }
        foreach ([
            '/var/lib/duo-cloud/config/repository-credential.json',
            '/var/lib/duo-cloud/config/repository-credential.sha256',
            '/var/lib/duo-cloud/config/repository-token',
        ] as $fixedPrivatePath) {
            if (file_exists($fixedPrivatePath) && !is_link($fixedPrivatePath)) {
                $real = realpath($fixedPrivatePath);
                if (is_string($real)) {
                    $paths[] = $real;
                }
            }
        }
        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);
        return $paths;
    }

    /** @return array<string,mixed> */
    public function runtime(): array {
        /** @var array<string,mixed> */
        return $this->document['runtime'];
    }

    /** @return array<string,mixed> */
    public function service(): array {
        /** @var array<string,mixed> */
        return $this->document['service'];
    }

    /** @return list<array<string,mixed>> */
    public function controllerKeys(): array {
        /** @var list<array<string,mixed>> */
        return $this->document['controller_keys'];
    }

    /** @return array{site_id:string,tenant_id:string} */
    public function principal(): array {
        $first = $this->document['controller_keys'][0];
        return ['site_id' => $first['site_id'], 'tenant_id' => $first['tenant_id']];
    }

    public function stateDirectory(string $name): string {
        if (preg_match('/\A[a-z][a-z0-9-]{0,63}\z/D', $name) !== 1) {
            throw new ControlRefusal('production state directory name is invalid');
        }
        $path = $this->stateRoot . '/' . $name;
        if (!file_exists($path) && !is_link($path)) {
            if (!mkdir($path, 0700) || !chmod($path, 0700)) {
                throw new ControlRefusal('production state directory could not be created privately');
            }
        }
        self::privateDirectory($path, 'production state directory');
        return $path;
    }

    /** @param array<string,mixed> $descriptor */
    public function keyBytes(array $descriptor, int $minimum, int $maximum, string $label): string {
        return self::readKeyDescriptor($descriptor, $minimum, $maximum, $label);
    }

    /** @param mixed $value */
    private static function validateService(mixed $value): void {
        if (!is_array($value) || array_is_list($value)) {
            throw new ControlRefusal('production service configuration must be an object');
        }
        self::exactKeys(
            $value,
            ['device_digest_key', 'response_key_id', 'response_signing_key'],
            'production service configuration'
        );
        self::identifier($value['response_key_id'] ?? null, 'response key id');
        self::keyDescriptor($value['response_signing_key'] ?? null, 'response signing key');
        self::keyDescriptor($value['device_digest_key'] ?? null, 'device digest key');
    }

    /** @param mixed $value */
    private static function validateHostDurablePaths(mixed $value): void {
        if (!is_array($value) || array_is_list($value)) {
            throw new ControlRefusal('production host durable paths must be an object');
        }
        self::exactKeys($value, [
            'authority_config_root', 'control_proxy_config_root', 'control_proxy_data_root',
            'firewall_authority_state_root', 'route_authority_state_root',
            'route_proxy_config_root', 'route_proxy_data_root', 'storage_authority_state_root',
        ], 'production host durable paths');
        foreach ($value as $name => $path) {
            self::absoluteFilePath($path, 'production ' . str_replace('_', ' ', (string) $name));
        }
    }

    /** @param mixed $value */
    private static function validateRuntime(mixed $value): void {
        if (!is_array($value) || array_is_list($value)) {
            throw new ControlRefusal('production runtime configuration must be an object');
        }
        self::exactKeys($value, [
            'container_engine', 'firewall_authority', 'git', 'image', 'memory_bytes', 'nano_cpus',
            'pids_limit', 'platform_fingerprint_sha256', 'preview_domain', 'process_launcher',
            'process_timeout_seconds',
            'repository_remote', 'repository_source', 'review_receipt_sha256', 'route_authority',
            'runtime_contract_file', 'runtime_contract_sha256', 'seccomp_profile_file',
            'seccomp_profile_sha256', 'snapshot_object_root',
            'storage_authority',
            'workload_repository_path',
        ], 'production runtime configuration');
        foreach (['container_engine', 'firewall_authority', 'git', 'process_launcher', 'route_authority', 'storage_authority'] as $field) {
            self::executableDescriptor($value[$field] ?? null, str_replace('_', ' ', $field));
        }
        if (!ImmutableOciReference::valid($value['image'] ?? null)) {
            throw new ControlRefusal('production workload image is not an immutable lowercase OCI digest reference');
        }
        foreach (['platform_fingerprint_sha256', 'review_receipt_sha256', 'runtime_contract_sha256', 'seccomp_profile_sha256'] as $field) {
            self::assertSha256($value[$field] ?? null, str_replace('_', ' ', $field));
        }
        self::absoluteFilePath($value['runtime_contract_file'] ?? null, 'runtime contract file');
        self::absoluteFilePath($value['seccomp_profile_file'] ?? null, 'seccomp profile file');
        self::absoluteFilePath($value['repository_source'] ?? null, 'repository source');
        self::absoluteFilePath($value['snapshot_object_root'] ?? null, 'snapshot object root');
        self::repositoryRemote($value['repository_remote'] ?? null);
        if (!is_string($value['preview_domain'] ?? null)
            || preg_match('/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}\z/D', $value['preview_domain']) !== 1
            || strlen($value['preview_domain']) > 253) {
            throw new ControlRefusal('production preview domain is invalid');
        }
        if (!is_string($value['workload_repository_path'] ?? null)
            || preg_match('#\A/[A-Za-z0-9._/-]+\z#D', $value['workload_repository_path']) !== 1
            || preg_match('#(?:^|/)\.\.?(/|$)#D', $value['workload_repository_path']) === 1) {
            throw new ControlRefusal('production workload repository path is invalid');
        }
        self::integerRange($value['memory_bytes'] ?? null, 268435456, 17179869184, 'memory bytes');
        self::integerRange($value['nano_cpus'] ?? null, 100000000, 8000000000, 'nano CPUs');
        self::integerRange($value['pids_limit'] ?? null, 32, 4096, 'PID limit');
        self::integerRange($value['process_timeout_seconds'] ?? null, 1, 300, 'process timeout seconds');
    }

    /** @param mixed $value */
    private static function repositoryRemote(mixed $value): void {
        if (!is_array($value) || array_is_list($value)) {
            throw new ControlRefusal('production repository remote must be an object');
        }
        self::exactKeys(
            $value,
            [
                'allowed_ref_prefix', 'credential_helper', 'credential_helper_sha256',
                'name', 'url', 'url_sha256',
            ],
            'production repository remote'
        );
        if (!is_string($value['name'] ?? null)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._-]{0,63}\z/D', $value['name']) !== 1) {
            throw new ControlRefusal('production repository remote name is invalid');
        }
        self::absoluteFilePath($value['credential_helper'] ?? null, 'repository credential helper');
        self::assertSha256(
            $value['credential_helper_sha256'] ?? null,
            'repository credential helper digest'
        );
        $url = $value['url'] ?? null;
        $parts = is_string($url) ? parse_url($url) : false;
        if (!is_array($parts) || ($parts['scheme'] ?? null) !== 'https'
            || !is_string($parts['host'] ?? null) || strtolower($parts['host']) !== $parts['host']
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])
            || !is_string($parts['path'] ?? null) || $parts['path'] === '' || $parts['path'][0] !== '/'
            || str_ends_with($url, '/') || str_contains($url, "\0")) {
            throw new ControlRefusal('production repository remote URL is not canonical credential-free HTTPS');
        }
        self::assertSha256($value['url_sha256'] ?? null, 'repository remote URL digest');
        $expected = hash('sha256', "duo-cloud-repository-remote-url/v1\0$url");
        if (!hash_equals($expected, $value['url_sha256'])) {
            throw new ControlRefusal('production repository remote URL differs from its authority digest');
        }
        $prefix = $value['allowed_ref_prefix'] ?? null;
        if (!is_string($prefix)
            || preg_match('#\Arefs/heads/[A-Za-z0-9][A-Za-z0-9._/-]*/\z#D', $prefix) !== 1
            || str_contains($prefix, '..') || str_contains($prefix, '//')) {
            throw new ControlRefusal('production repository remote ref prefix is invalid');
        }
    }

    /** @param mixed $value */
    private static function validateControllerKeys(mixed $value): void {
        if (!is_array($value) || !array_is_list($value) || $value === [] || count($value) > 10000) {
            throw new ControlRefusal('production controller key registry must be a non-empty bounded list');
        }
        $ids = [];
        $principal = null;
        foreach ($value as $index => $key) {
            if (!is_array($key) || array_is_list($key)) {
                throw new ControlRefusal("production controller key $index must be an object");
            }
            self::exactKeys($key, ['key_id', 'public_key', 'site_id', 'tenant_id'], "controller key $index");
            $keyId = self::identifier($key['key_id'] ?? null, "controller key $index id");
            self::identifier($key['tenant_id'] ?? null, "controller key $index tenant id");
            self::identifier($key['site_id'] ?? null, "controller key $index site id");
            self::keyDescriptor($key['public_key'] ?? null, "controller key $index public key");
            if (isset($ids[$keyId])) {
                throw new ControlRefusal('production controller key ids must be unique');
            }
            $keyPrincipal = [$key['tenant_id'], $key['site_id']];
            if ($principal !== null && $keyPrincipal !== $principal) {
                // ContainerWorkloadRuntime intentionally has one approved Git
                // source. One service/state root is therefore one site; fleet
                // isolation supplies additional workers instead of sharing it.
                throw new ControlRefusal('production service controller keys must bind one tenant and site');
            }
            $principal = $keyPrincipal;
            $ids[$keyId] = true;
        }
    }

    /** @param mixed $value */
    private static function executableDescriptor(mixed $value, string $label): void {
        if (!is_array($value) || array_is_list($value)) {
            throw new ControlRefusal("production $label descriptor must be an object");
        }
        self::exactKeys($value, ['path', 'sha256'], "production $label descriptor");
        self::absoluteFilePath($value['path'] ?? null, "$label path");
        self::assertSha256($value['sha256'] ?? null, "$label digest");
    }

    /** @param array<string,mixed> $descriptor */
    private static function executable(array $descriptor, string $label): void {
        $path = $descriptor['path'];
        $expected = $descriptor['sha256'];
        self::readPinnedRegularFile($path, $expected, 67108864, false, $label);
        if (!is_executable($path)) {
            throw new ControlRefusal("production $label is not executable");
        }
    }

    /** @param mixed $value */
    private static function keyDescriptor(mixed $value, string $label): void {
        if (!is_array($value) || array_is_list($value)) {
            throw new ControlRefusal("production $label descriptor must be an object");
        }
        self::exactKeys($value, ['path', 'sha256'], "production $label descriptor");
        self::absoluteFilePath($value['path'] ?? null, "$label path");
        self::assertSha256($value['sha256'] ?? null, "$label digest");
    }

    /** @param array<string,mixed> $descriptor */
    private static function readKeyDescriptor(array $descriptor, int $minimum, int $maximum, string $label): string {
        $encoded = self::readPinnedRegularFile(
            $descriptor['path'],
            $descriptor['sha256'],
            self::KEY_LIMIT,
            true,
            $label
        );
        if (!str_ends_with($encoded, "\n") || substr_count($encoded, "\n") !== 1) {
            throw new ControlRefusal("production $label is not canonical base64 with one trailing LF");
        }
        $line = substr($encoded, 0, -1);
        $bytes = base64_decode($line, true);
        if (!is_string($bytes) || base64_encode($bytes) !== $line
            || strlen($bytes) < $minimum || strlen($bytes) > $maximum) {
            throw new ControlRefusal("production $label has invalid canonical key bytes");
        }
        return $bytes;
    }

    private static function readRuntimeContract(string $path, string $expectedSha256): void {
        $bytes = self::readPinnedRegularFile(
            $path,
            $expectedSha256,
            self::CONFIG_LIMIT,
            false,
            'runtime image contract'
        );
        $contract = CanonicalJson::decodeObject($bytes, self::CONFIG_LIMIT);
        if ($bytes !== CanonicalJson::encode($contract) . "\n") {
            throw new ControlRefusal('runtime image contract is not canonical JSON with one trailing LF');
        }
        self::exactKeys($contract, [
            'command_helper', 'entrypoint', 'format', 'helpers', 'listen_port',
            'mounts', 'network', 'uid_gid',
        ], 'runtime image contract');
        $expected = [
            'command_helper' => '/opt/duo/bin/duo-preview-command',
            'entrypoint' => '/opt/duo/bin/duo-preview-runtime',
            'format' => self::RUNTIME_CONTRACT_FORMAT,
            'helpers' => [
                'materialize-repository' => '/opt/duo/bin/materialize-repository',
                'restore-database' => '/opt/duo/bin/restore-database',
                'restore-media' => '/opt/duo/bin/restore-media',
                'runtime-status' => '/opt/duo/bin/runtime-status',
                'url-rebind' => '/opt/duo/bin/url-rebind',
            ],
            'listen_port' => 8080,
            'mounts' => [
                'database' => '/var/lib/duo/database',
                'filesystem' => '/var/lib/duo/wordpress',
                'secrets' => '/run/secrets/duo',
            ],
            'network' => 'internal-bridge-no-masquerade/v1',
            'uid_gid' => '10001:10001',
        ];
        if (CanonicalJson::encode($contract) !== CanonicalJson::encode($expected)) {
            throw new ControlRefusal('runtime image contract differs from the reviewed closed helper protocol');
        }
    }

    private static function privateStateRoot(mixed $value): string {
        $path = self::absoluteFilePath($value, 'production state root');
        self::privateDirectory($path, 'production state root');
        $real = realpath($path);
        if (!is_string($real) || $real === '/') {
            throw new ControlRefusal('production state root is not canonical');
        }
        return $real;
    }

    /** @param array<string,mixed> $runtime */
    private static function validateWorkerRoot(
        string $stateRoot,
        string $hostPreflightRoot,
        array $runtime
    ): void {
        $workerRoot = dirname($stateRoot);
        if ($workerRoot === '/' || !is_dir($workerRoot) || is_link($workerRoot)) {
            throw new ControlRefusal('production worker root is not a canonical directory');
        }
        if (self::within($workerRoot, $hostPreflightRoot)
            || self::within($hostPreflightRoot, $workerRoot)) {
            throw new ControlRefusal('production host preflight root overlaps a worker root');
        }
        foreach (['repository_source', 'snapshot_object_root'] as $field) {
            $path = realpath($runtime[$field]);
            if (!is_string($path)
                || ($path !== $workerRoot && !str_starts_with($path, $workerRoot . '/'))) {
                throw new ControlRefusal(
                    'production state, repository, and snapshots must share one quota worker root'
                );
            }
        }
    }

    /** @param array<string,mixed> $runtime */
    private static function validateReapWorkerRoot(
        string $stateRoot,
        string $hostPreflightRoot,
        array $runtime
    ): void {
        $workerRoot = dirname($stateRoot);
        if ($workerRoot === '/' || !is_dir($workerRoot) || is_link($workerRoot)) {
            throw new ControlRefusal('production worker root is not a canonical directory');
        }
        if (self::within($workerRoot, $hostPreflightRoot)
            || self::within($hostPreflightRoot, $workerRoot)) {
            throw new ControlRefusal('production host preflight root overlaps a worker root');
        }
        $repository = realpath($runtime['repository_source']);
        if (!is_string($repository) || !self::within($repository, $workerRoot)) {
            throw new ControlRefusal(
                'production state and repository must share one quota worker root during reap'
            );
        }
    }

    /** @param array<string,mixed> $runtime */
    private static function validateWorkerRootPaths(
        string $stateRoot,
        string $hostPreflightRoot,
        array $runtime
    ): void {
        $workerRoot = dirname($stateRoot);
        if ($workerRoot === '/' || self::within($workerRoot, $hostPreflightRoot)
            || self::within($hostPreflightRoot, $workerRoot)) {
            throw new ControlRefusal('production host preflight root overlaps a worker root');
        }
        foreach (['repository_source', 'snapshot_object_root'] as $field) {
            $path = $runtime[$field];
            if (!is_string($path) || !self::within($path, $workerRoot)) {
                throw new ControlRefusal(
                    'production state, repository, and snapshots must share one quota worker root'
                );
            }
        }
    }

    private static function within(string $path, string $root): bool {
        return $path === $root || str_starts_with($path, $root . '/');
    }

    private static function approvedDirectory(mixed $value, string $label): string {
        $path = self::absoluteFilePath($value, $label);
        $real = realpath($path);
        if (!is_string($real) || $real === '/' || is_link($path) || !is_dir($real) || !is_readable($real)) {
            throw new ControlRefusal("production $label is not a canonical readable directory");
        }
        return $real;
    }

    private static function hostDurableDirectory(
        mixed $value,
        string $label,
        bool $rootAuthorityOnly
    ): string {
        if (!$rootAuthorityOnly) {
            return self::approvedDirectory($value, $label);
        }
        $path = self::absoluteFilePath($value, $label);
        clearstatcache(true, $path);
        $stat = @lstat($path);
        $real = realpath($path);
        if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0040000
            || !is_string($real) || $real === '/') {
            throw new ControlRefusal(
                "production $label is not a canonical root-authority directory"
            );
        }
        return $real;
    }

    private static function privateDirectory(string $path, string $label): void {
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0040000
            || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0077) !== 0)
            || !self::ownedByService($stat) || !is_readable($path) || !is_writable($path)) {
            throw new ControlRefusal("$label must be service-owned and mode 0700 or stricter");
        }
    }

    private static function readPinnedRegularFile(
        string $path,
        string $expectedSha256,
        int $limit,
        bool $private,
        string $label
    ): string {
        self::absoluteFilePath($path, $label);
        self::assertSha256($expectedSha256, "$label digest");
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0022) !== 0 || !self::ownedByService($before)
            || ($private && DIRECTORY_SEPARATOR === '/' && ($before['mode'] & 0077) !== 0)
            || (int) $before['size'] < 1 || (int) $before['size'] > $limit) {
            throw new ControlRefusal("production $label is not an approved pinned regular file");
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal("production $label could not be opened");
        }
        $opened = fstat($handle);
        $bytes = stream_get_contents($handle, $limit + 1);
        $closed = fclose($handle);
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_array($opened) || !is_array($after) || !is_string($bytes) || !$closed
            || !self::sameFile($before, $opened) || !self::sameFile($before, $after)
            || strlen($bytes) > $limit || !hash_equals($expectedSha256, hash('sha256', $bytes))) {
            throw new ControlRefusal("production $label changed while reading or differs from its pin");
        }
        return $bytes;
    }

    /** @param array<string,mixed> $stat */
    private static function ownedByService(array $stat): bool {
        if (!function_exists('posix_geteuid')) {
            return true;
        }
        $owner = (int) ($stat['uid'] ?? -1);
        return $owner === posix_geteuid() || $owner === 0;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function sameFile(array $left, array $right): bool {
        return (int) ($left['dev'] ?? -1) === (int) ($right['dev'] ?? -2)
            && (int) ($left['ino'] ?? -1) === (int) ($right['ino'] ?? -2)
            && (int) ($left['size'] ?? -1) === (int) ($right['size'] ?? -2)
            && (int) ($left['mtime'] ?? -1) === (int) ($right['mtime'] ?? -2);
    }

    private static function absoluteFilePath(mixed $value, string $label): string {
        if (!is_string($value) || $value === '' || $value[0] !== '/' || str_contains($value, "\0")
            || str_contains($value, '//')
            || preg_match('#(?:^|/)\.\.?(/|$)#D', $value) === 1 || str_ends_with($value, '/')) {
            throw new ControlRefusal("production $label path is invalid");
        }
        return $value;
    }

    private static function identifier(mixed $value, string $label): string {
        if (!is_string($value)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new ControlRefusal("production $label is invalid");
        }
        return $value;
    }

    private static function assertSha256(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new ControlRefusal("production $label is not lowercase SHA-256");
        }
        return $value;
    }

    private static function integerRange(mixed $value, int $minimum, int $maximum, string $label): int {
        if (!is_int($value) || $value < $minimum || $value > $maximum) {
            throw new ControlRefusal("production $label is outside its closed range");
        }
        return $value;
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
}
