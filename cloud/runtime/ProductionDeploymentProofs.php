<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ControlRefusal.php';
require_once dirname(__DIR__) . '/src/HostAuthorityBusy.php';
require_once __DIR__ . '/ProductionConfig.php';

/** Durable, exact deployment evidence consumed by preflight and public readiness. */
final class ProductionDeploymentProofs {
    public const FORMAT = 'duo-cloud-production-deployment-proofs/v1';
    public const LINUX_HOST_FORMAT = 'duo-cloud-linux-host-boundary-proof-receipt/v1';
    public const INSTALLED_FPM_CONTROL_FORMAT =
        'duo-cloud-installed-fpm-ingress-control-proof-receipt/v1';
    public const INSTALLED_FPM_FORMAT = 'duo-cloud-installed-fpm-ingress-worker-proof-receipt/v1';
    public const LINUX_HOST_TTL_SECONDS = 604800;
    public const INSTALLED_FPM_TTL_SECONDS = 90;
    public const LINUX_HOST_VERIFICATION_SECONDS = 900;

    public const INSTALLED_FPM_CONTROL_FILE = 'installed-fpm-ingress-control-proof.json';

    private const DOCUMENT_LIMIT = 2097152;
    private const FLEET_NAMESPACE_LIMIT = 64;
    private const FLEET_NAMESPACE_LEGACY_LOCK_LIMIT = 512;
    private const FLEET_NAMESPACE_NODE_LIMIT = 4096;
    private const LINUX_HOST_EXECUTION_LOCK = '/run/duo-cloud-host-proof/authority.lock';
    private const LINUX_HOST_PUBLICATION_LOCK = 'linux-host-boundaries-publication.lock';
    private const RUNTIME_IMAGE_PUBLICATION_LOCK = 'runtime-image-proof-publication.lock';
    private const INSTALLED_AUTHORITY_CONFIG_ROOT = '/var/lib/duo-cloud/config';

    private \Closure $clock;
    private \Closure $bootId;
    private string $deployRoot;

    /** @param callable():int|null $clock @param callable():string|null $bootId */
    public function __construct(
        private ProductionConfig $config,
        ?callable $clock = null,
        ?callable $bootId = null,
        ?string $deployRoot = null
    ) {
        $this->clock = $clock === null
            ? static fn (): int => time()
            : \Closure::fromCallable($clock);
        $this->bootId = $bootId === null
            ? \Closure::fromCallable([self::class, 'currentBootId'])
            : \Closure::fromCallable($bootId);
        $candidate = $deployRoot ?? dirname(__DIR__) . '/deploy';
        $real = realpath($candidate);
        if (!is_string($real) || !is_dir($real) || is_link($candidate)) {
            throw new ControlRefusal('production deployment proof artifact root is invalid');
        }
        $this->deployRoot = $real;
    }

    public function linuxHostPath(): string {
        return $this->config->hostPreflightRoot() . '/linux-host-boundaries-proof.'
            . $this->config->sha256() . '.json';
    }

    public function runtimeImagePath(): string {
        return $this->config->hostPreflightRoot() . '/runtime-image-proof.'
            . $this->config->runtime()['review_receipt_sha256'] . '.json';
    }

    public function installedFpmPath(): string {
        $control = self::readCanonicalPrivateFile($this->installedFpmControlPath());
        self::assertSha256(
            $control['proof_sha256'] ?? null,
            'installed FPM control proof digest'
        );
        return self::installedFpmWorkerPath(
            $this->config->hostPreflightRoot(),
            $this->config->sha256(),
            $control['proof_sha256']
        );
    }

    public function installedFpmControlPath(): string {
        return $this->config->hostPreflightRoot() . '/' . self::INSTALLED_FPM_CONTROL_FILE;
    }

    /** @return array<string,string> */
    public static function linuxHostVerifierSourcePaths(string $deployRoot): array {
        $root = realpath($deployRoot);
        if (!is_string($root) || !is_dir($root) || is_link($deployRoot)) {
            throw new ControlRefusal('Linux host verifier source root is invalid');
        }
        $cloud = dirname($root);
        return [
            'deploy/verify-linux-host-boundaries.php' => $root
                . '/verify-linux-host-boundaries.php',
            'runtime/FirewallClientConfig.php' => $cloud . '/runtime/FirewallClientConfig.php',
            'runtime/HostFirewallAuthority.php' => $cloud . '/runtime/HostFirewallAuthority.php',
            'runtime/ProductionConfig.php' => $cloud . '/runtime/ProductionConfig.php',
            'runtime/ProductionDeploymentProofs.php' => $cloud
                . '/runtime/ProductionDeploymentProofs.php',
            'runtime/RuntimeSlotLock.php' => $cloud . '/runtime/RuntimeSlotLock.php',
            'src/CanonicalJson.php' => $cloud . '/src/CanonicalJson.php',
            'src/ContainerWorkloadRuntime.php' => $cloud . '/src/ContainerWorkloadRuntime.php',
            'src/ControlRefusal.php' => $cloud . '/src/ControlRefusal.php',
            'src/HostAuthorityBusy.php' => $cloud . '/src/HostAuthorityBusy.php',
            'src/ImmutableOciReference.php' => $cloud . '/src/ImmutableOciReference.php',
            'src/WorkloadRuntime.php' => $cloud . '/src/WorkloadRuntime.php',
            'src/WorkloadSecurityInspection.php' => $cloud . '/src/WorkloadSecurityInspection.php',
        ];
    }

    /** @param array<string,string> $digests */
    public static function linuxHostVerifierClosureSha256(array $digests): string {
        $expected = array_keys(self::linuxHostVerifierSourcePaths(dirname(__DIR__) . '/deploy'));
        $actual = array_keys($digests);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new ControlRefusal('Linux host verifier PHP closure is incomplete');
        }
        ksort($digests, SORT_STRING);
        foreach ($digests as $digest) {
            self::assertSha256($digest, 'Linux host verifier PHP source digest');
        }
        return hash(
            'sha256',
            "duo-cloud-linux-host-verifier-php-closure/v1\0" . CanonicalJson::encode($digests)
        );
    }

    /**
     * @return array{
     *     artifact_sha256s:array<string,string>,
     *     configurations:array<string,FirewallClientConfig>
     * }
     */
    public static function linuxHostInstalledConfigurationSnapshot(
        ProductionConfig $config
    ): array {
        require_once __DIR__ . '/FirewallClientConfig.php';
        $configRoot = $config->authorityConfigRoot();
        $canonicalConfigRoot = realpath($configRoot);
        if (!is_string($canonicalConfigRoot) || $canonicalConfigRoot !== $configRoot
            || !is_dir($configRoot) || is_link($configRoot)) {
            throw new ControlRefusal(
                'Linux host installed authority configuration root is invalid'
            );
        }
        // Object-level tests use a private fixture root. The strict executable
        // separately requires the shipped fixed root before producing effects.
        $ownerUid = $configRoot === self::INSTALLED_AUTHORITY_CONFIG_ROOT
            ? 0
            : self::effectiveUid();
        $installed = [
            'firewall_authority' => FirewallClientConfig::inspectInstalledAuthority(
                'firewall',
                $configRoot . '/firewall-authority.json',
                $configRoot . '/firewall-authority.sha256',
                $ownerUid
            ),
            'firewall_client' => FirewallClientConfig::loadInstalled(
                $configRoot . '/firewall-client.json',
                $configRoot . '/firewall-client.sha256',
                $ownerUid
            ),
            'storage_authority' => FirewallClientConfig::inspectInstalledAuthority(
                'storage',
                $configRoot . '/storage-authority.json',
                $configRoot . '/storage-authority.sha256',
                $ownerUid
            ),
            'storage_client' => FirewallClientConfig::loadInstalled(
                $configRoot . '/storage-client.json',
                $configRoot . '/storage-client.sha256',
                $ownerUid
            ),
        ];
        $digests = [];
        foreach ($installed as $name => $configuration) {
            $digests[$name . '_configuration_sha256'] =
                $configuration->configurationSha256();
        }
        ksort($digests, SORT_STRING);
        return ['artifact_sha256s' => $digests, 'configurations' => $installed];
    }

    /**
     * @param array{
     *     artifact_sha256s:array<string,string>,
     *     configurations:array<string,FirewallClientConfig>
     * } $snapshot
     */
    public static function assertLinuxHostInstalledConfigurationSnapshotCurrent(
        array $snapshot
    ): void {
        $configurations = $snapshot['configurations'] ?? null;
        $digests = $snapshot['artifact_sha256s'] ?? null;
        $names = [
            'firewall_authority', 'firewall_client', 'storage_authority', 'storage_client',
        ];
        if (!is_array($configurations) || array_is_list($configurations)
            || !is_array($digests) || array_is_list($digests)
            || array_keys($configurations) !== $names
            || array_keys($digests) !== [
                'firewall_authority_configuration_sha256',
                'firewall_client_configuration_sha256',
                'storage_authority_configuration_sha256',
                'storage_client_configuration_sha256',
            ]) {
            throw new ControlRefusal(
                'Linux host installed authority configuration snapshot is invalid'
            );
        }
        foreach ($configurations as $name => $configuration) {
            if (!$configuration instanceof FirewallClientConfig
                || ($digests[$name . '_configuration_sha256'] ?? null)
                    !== $configuration->configurationSha256()) {
                throw new ControlRefusal(
                    'Linux host installed authority configuration snapshot is invalid'
                );
            }
            $configuration->assertCurrent();
        }
    }

    /** @return array<string,string> */
    public static function installedFpmVerifierSourcePaths(string $deployRoot): array {
        $root = realpath($deployRoot);
        if (!is_string($root) || !is_dir($root) || is_link($deployRoot)) {
            throw new ControlRefusal('installed FPM verifier source root is invalid');
        }
        $cloud = dirname($root);
        return [
            'deploy/verify-installed-fpm-ingress.php' => $root
                . '/verify-installed-fpm-ingress.php',
            'runtime/ProductionConfig.php' => $cloud . '/runtime/ProductionConfig.php',
            'runtime/ProductionDeploymentProofs.php' => $cloud
                . '/runtime/ProductionDeploymentProofs.php',
            'src/CanonicalJson.php' => $cloud . '/src/CanonicalJson.php',
            'src/ControlRefusal.php' => $cloud . '/src/ControlRefusal.php',
            'src/HostAuthorityBusy.php' => $cloud . '/src/HostAuthorityBusy.php',
            'src/ImmutableOciReference.php' => $cloud . '/src/ImmutableOciReference.php',
        ];
    }

    /** @param array<string,string> $digests */
    public static function installedFpmVerifierClosureSha256(array $digests): string {
        $expected = array_keys(
            self::installedFpmVerifierSourcePaths(dirname(__DIR__) . '/deploy')
        );
        $actual = array_keys($digests);
        sort($expected, SORT_STRING);
        sort($actual, SORT_STRING);
        if ($actual !== $expected) {
            throw new ControlRefusal('installed FPM verifier PHP closure is incomplete');
        }
        ksort($digests, SORT_STRING);
        foreach ($digests as $digest) {
            self::assertSha256($digest, 'installed FPM verifier PHP source digest');
        }
        return hash(
            'sha256',
            "duo-cloud-installed-fpm-verifier-php-closure/v1\0"
                . CanonicalJson::encode($digests)
        );
    }

    public function invalidateLinuxHost(): void {
        self::removePublication(
            $this->linuxHostPath(),
            self::effectiveUid(),
            'linux-host-boundaries-publication.lock'
        );
    }

    /** @return array<string,mixed> */
    public function beginLinuxHostVerification(): array {
        $now = ($this->clock)();
        $bootId = ($this->bootId)();
        self::assertClockAndBoot($now, $bootId);
        clearstatcache(true, $this->linuxHostIntentPath());
        if (@lstat($this->linuxHostIntentPath()) !== false) {
            $existing = self::readCanonicalPrivateFile($this->linuxHostIntentPath());
            self::exactKeys($existing, [
                'boot_id', 'configuration_sha256', 'deadline_at', 'format',
                'started_at', 'state',
            ], 'Linux host boundary proof verification intent');
            $state = $existing['state'] ?? null;
            $started = $existing['started_at'] ?? null;
            $deadline = $existing['deadline_at'] ?? null;
            $validShape = ($existing['format'] ?? null)
                    === 'duo-cloud-linux-host-boundary-proof-intent/v1'
                && ($existing['configuration_sha256'] ?? null) === $this->config->sha256()
                && in_array($state, ['failed', 'verifying'], true)
                && is_int($started) && $started > 0
                && is_int($deadline)
                && (($state === 'verifying'
                        && $deadline === $started + self::LINUX_HOST_VERIFICATION_SECONDS)
                    || ($state === 'failed' && $deadline === $started));
            if (!$validShape) {
                throw new ControlRefusal(
                    'Linux host boundary proof verification intent is invalid'
                );
            }
            if ($state === 'verifying' && ($existing['boot_id'] ?? null) === $bootId
                && $now >= $started && $now < $deadline) {
                return $existing;
            }
            // A failed, expired, or prior-boot attempt must revoke the old
            // receipt before a fresh intent can make readiness permissive.
            $this->failLinuxHostVerification();
            $now = ($this->clock)();
            $bootId = ($this->bootId)();
            self::assertClockAndBoot($now, $bootId);
        }
        $intent = [
            'boot_id' => $bootId,
            'configuration_sha256' => $this->config->sha256(),
            'deadline_at' => $now + self::LINUX_HOST_VERIFICATION_SECONDS,
            'format' => 'duo-cloud-linux-host-boundary-proof-intent/v1',
            'started_at' => $now,
            'state' => 'verifying',
        ];
        self::publish(
            $this->linuxHostIntentPath(),
            $intent,
            self::effectiveUid(),
            self::effectiveGid(),
            false,
            'linux-host-boundaries-publication.lock'
        );
        return $intent;
    }

    public function failLinuxHostVerification(): void {
        $now = ($this->clock)();
        $bootId = ($this->bootId)();
        self::assertClockAndBoot($now, $bootId);
        self::publish(
            $this->linuxHostIntentPath(),
            [
                'boot_id' => $bootId,
                'configuration_sha256' => $this->config->sha256(),
                'deadline_at' => $now,
                'format' => 'duo-cloud-linux-host-boundary-proof-intent/v1',
                'started_at' => $now,
                'state' => 'failed',
            ],
            self::effectiveUid(),
            self::effectiveGid(),
            false,
            'linux-host-boundaries-publication.lock'
        );
        $this->invalidateLinuxHost();
    }

    public static function invalidateLinuxHostAt(
        string $hostPreflightRoot,
        string $configurationSha256,
        int $ownerUid
    ): void {
        self::assertSha256($configurationSha256, 'Linux host proof configuration digest');
        $root = self::privateRoot($hostPreflightRoot, $ownerUid);
        self::removePublication(
            $root . '/linux-host-boundaries-proof.' . $configurationSha256 . '.json',
            $ownerUid,
            'linux-host-boundaries-publication.lock'
        );
    }

    public static function invalidateInstalledFpm(string $hostPreflightRoot, int $ownerUid): void {
        $root = self::privateRoot($hostPreflightRoot, $ownerUid);
        self::removePublication($root . '/' . self::INSTALLED_FPM_CONTROL_FILE, $ownerUid);
    }

    public static function invalidateInstalledFpmWorker(
        string $hostPreflightRoot,
        string $configurationSha256,
        int $ownerUid
    ): void {
        self::assertSha256($configurationSha256, 'installed FPM worker configuration digest');
        $root = self::privateRoot($hostPreflightRoot, $ownerUid);
        $rootStat = @lstat($root);
        if (!is_array($rootStat) || !is_int($rootStat['gid'] ?? null)) {
            throw new ControlRefusal('production deployment proof root identity is unavailable');
        }
        $lock = self::acquireInstalledFpmTransactionLock(
            $root,
            $ownerUid,
            $rootStat['gid']
        );
        try {
            self::removeInstalledFpmWorkerVersions($root, $configurationSha256, $ownerUid);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Bound the two versioned host-proof families to the fleet registry's
     * current and one-retiring identities. The caller already holds the one
     * fleet-maintenance lock; the three fixed locks below close every writer
     * without creating a lock per historical version.
     *
     * @param list<string> $configurationSha256s sorted, unique current+retiring pins
     * @param list<string> $runtimeReviewSha256s sorted, unique current+retiring receipts
     */
    public static function reconcileFleetNamespace(
        string $hostPreflightRoot,
        array $configurationSha256s,
        array $runtimeReviewSha256s,
        int $ownerUid,
        int $ownerGid
    ): void {
        $root = self::privateRoot($hostPreflightRoot, $ownerUid);
        $rootStat = @lstat($root);
        if (!is_array($rootStat) || (int) ($rootStat['gid'] ?? -1) !== $ownerGid) {
            throw new ControlRefusal(
                'production deployment proof root group identity is invalid'
            );
        }
        self::assertFleetNamespaceKeepSet(
            $configurationSha256s,
            'configuration'
        );
        self::assertFleetNamespaceKeepSet(
            $runtimeReviewSha256s,
            'runtime review receipt'
        );

        // The execution lock is outside the proof root. Resolve and validate
        // its systemd-owned RuntimeDirectory before even enumerating managed
        // names, so a missing or substituted /run authority cannot authorize
        // cleanup in the durable host root.
        self::assertFleetNamespaceLockPath(
            self::LINUX_HOST_EXECUTION_LOCK,
            $ownerUid,
            $ownerGid,
            'Linux host verifier execution lock'
        );
        self::reconcileFleetNamespaceAt(
            $root,
            $configurationSha256s,
            $runtimeReviewSha256s,
            $ownerUid,
            $ownerGid,
            self::LINUX_HOST_EXECUTION_LOCK,
            $root . '/' . self::RUNTIME_IMAGE_PUBLICATION_LOCK,
            $root . '/' . self::LINUX_HOST_PUBLICATION_LOCK
        );
    }

    /**
     * Persist the static image proof after deployment has placed the exact
     * immutable reference named by this worker on the target host.
     *
     * @param array<string,mixed> $proof
     */
    public function publishRuntimeImage(array $proof): void {
        $this->validateRuntimeImageProof($proof);
        self::publish(
            $this->runtimeImagePath(),
            $proof,
            self::effectiveUid(),
            self::effectiveGid(),
            false,
            'runtime-image-proof-publication.lock'
        );
    }

    public function installRuntimeImage(string $sourcePath, string $expectedSha256): void {
        self::assertSha256($expectedSha256, 'runtime image proof file digest');
        $proof = self::readCanonicalPrivateFile($sourcePath);
        $bytes = CanonicalJson::encode($proof) . "\n";
        if (!hash_equals($expectedSha256, hash('sha256', $bytes))) {
            throw new ControlRefusal('runtime image proof file differs from its installation pin');
        }
        $this->publishRuntimeImage($proof);
    }

    /** @param array<string,mixed> $proof @return array<string,mixed> */
    public function publishLinuxHost(array $proof): array {
        $runtimeImage = $this->readRuntimeImageProof();
        $artifactSnapshot = $this->linuxHostArtifactSnapshot();
        $artifacts = $artifactSnapshot['artifacts'];
        $this->validateLinuxHostProof($proof, $artifacts);
        if (($proof['image_id'] ?? null) !== ($runtimeImage['image_id'] ?? null)) {
            throw new ControlRefusal(
                'Linux host boundary proof does not bind exact production artifacts'
            );
        }
        [$now, $bootId] = $this->requireCurrentLinuxHostVerificationIntent();
        $receipt = [
            'artifacts' => $artifacts,
            'boot_id' => $bootId,
            'expires_at' => $now + self::LINUX_HOST_TTL_SECONDS,
            'format' => self::LINUX_HOST_FORMAT,
            'issued_at' => $now,
            'proof' => $proof,
            'proof_sha256' => self::documentSha256(
                'duo-cloud-linux-host-boundary-proof/v1',
                $proof
            ),
            'runtime_image_proof_receipt_sha256' => $runtimeImage['proof_receipt_sha256'],
        ];
        $receipt['receipt_sha256'] = self::receiptSha256(self::LINUX_HOST_FORMAT, $receipt);
        $ownerUid = self::effectiveUid();
        $ownerGid = self::effectiveGid();
        $lock = self::acquireLinuxHostPublicationLock(
            $this->config->hostPreflightRoot(),
            $ownerUid,
            $ownerGid
        );
        try {
            $this->requireCurrentLinuxHostVerificationIntent();
            self::publish(
                $this->linuxHostPath(),
                $receipt,
                $ownerUid,
                $ownerGid,
                true,
                self::LINUX_HOST_PUBLICATION_LOCK
            );
            try {
                $this->requireCurrentLinuxHostVerificationIntent();
                $this->assertLinuxHostArtifactSnapshotCurrent($artifactSnapshot);
            } catch (\Throwable $error) {
                // Keep the family lock through rollback: contention cannot
                // strand the just-published receipt for changed host bytes.
                self::removePublicationUnderTransaction(
                    $this->linuxHostPath(),
                    $ownerUid
                );
                throw new ControlRefusal(
                    'Linux host proof artifacts changed during publication',
                    0,
                    $error
                );
            }
            self::removePublicationUnderTransaction(
                $this->linuxHostIntentPath(),
                $ownerUid
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        return $receipt;
    }

    /**
     * The root verifier publishes into the service-owned host root, then hands
     * the inode to the service identity before the atomic rename.
     *
     * @param array<string,mixed> $proof
     * @param list<string> $workerConfigurationSha256s
     * @param callable():int|null $clock
     * @param callable():string|null $bootId
     * @return array<string,mixed>
     */
    public static function publishInstalledFpm(
        string $hostPreflightRoot,
        array $proof,
        array $workerConfigurationSha256s,
        int $ownerUid,
        int $ownerGid,
        ?callable $clock = null,
        ?callable $bootId = null,
        ?string $deployRoot = null
    ): array {
        $artifacts = self::installedFpmArtifacts($deployRoot ?? dirname(__DIR__) . '/deploy');
        self::validateInstalledFpmProof($proof, $workerConfigurationSha256s, $artifacts);
        $controlProof = [
            'artifact_sha256s' => $proof['artifact_sha256s'],
            'configuration_sha256' => $proof['configuration_sha256'],
            'control_caddy' => $proof['control_caddy'],
            'format' => 'duo-cloud-installed-fpm-ingress-control-proof/v1',
            'max_wall_seconds' => $proof['max_wall_seconds'],
            'php_fpm_ini_sha256' => $proof['php_fpm_ini_sha256'],
            'state' => 'ready',
            'worker_configuration_sha256s' => $workerConfigurationSha256s,
        ];
        $controlProof['proof_receipt_sha256'] = self::receiptSha256(
            'duo-cloud-installed-fpm-ingress-control-proof/v1',
            $controlProof
        );
        $workerProofs = [];
        foreach ($proof['workers'] as $worker) {
            $workerProof = $worker + [
                'format' => 'duo-cloud-installed-fpm-ingress-worker-proof/v1',
                'state' => 'ready',
            ];
            $workerProof['proof_receipt_sha256'] = self::receiptSha256(
                'duo-cloud-installed-fpm-ingress-worker-proof/v1',
                $workerProof
            );
            $workerProofs[$worker['worker_configuration_sha256']] = $workerProof;
        }
        return self::publishInstalledFpmEvidence(
            $hostPreflightRoot,
            $controlProof,
            $workerProofs,
            $workerConfigurationSha256s,
            $ownerUid,
            $ownerGid,
            $clock,
            $bootId,
            $deployRoot
        );
    }

    /**
     * Publish versioned worker evidence before atomically committing its one
     * fleet-global control identity. Missing workers remain local refusals.
     *
     * @param array<string,mixed> $controlProof
     * @param array<string,array<string,mixed>> $workerProofs configuration SHA-256 => proof
     * @param list<string> $workerConfigurationSha256s
     * @param callable():int|null $clock
     * @param callable():string|null $bootId
     * @return array<string,mixed>
     */
    public static function publishInstalledFpmEvidence(
        string $hostPreflightRoot,
        array $controlProof,
        array $workerProofs,
        array $workerConfigurationSha256s,
        int $ownerUid,
        int $ownerGid,
        ?callable $clock = null,
        ?callable $bootId = null,
        ?string $deployRoot = null
    ): array {
        self::assertConfigurationSha256s($workerConfigurationSha256s);
        if (($controlProof['worker_configuration_sha256s'] ?? null)
                !== $workerConfigurationSha256s) {
            throw new ControlRefusal(
                'installed FPM control proof does not bind the exact worker fleet'
            );
        }
        $artifactSnapshot = self::installedFpmArtifactSnapshot(
            $deployRoot ?? dirname(__DIR__) . '/deploy'
        );
        $artifacts = $artifactSnapshot['artifacts'];
        self::validateInstalledFpmControlProof($controlProof, $artifacts);
        foreach ($workerProofs as $configurationSha256 => $workerProof) {
            if (!is_string($configurationSha256)
                || !in_array($configurationSha256, $workerConfigurationSha256s, true)) {
                throw new ControlRefusal('installed FPM worker proof is outside the exact fleet');
            }
            self::validateInstalledFpmWorkerProof($workerProof, $configurationSha256);
        }
        $controlProofSha256 = self::documentSha256(
            'duo-cloud-installed-fpm-ingress-control-proof/v1',
            $controlProof
        );
        $transactionNow = $clock === null ? time() : $clock();
        if ($transactionNow < 1) {
            throw new ControlRefusal('installed FPM proof clock is invalid');
        }
        $root = self::privateRoot($hostPreflightRoot, $ownerUid);
        $lock = self::acquireInstalledFpmTransactionLock($root, $ownerUid, $ownerGid);
        try {
            self::reconcileInstalledFpmWorkerVersions(
                $root,
                self::currentInstalledFpmControlProofSha256($root, $ownerUid),
                $ownerUid,
                $transactionNow
            );
            $published = 0;
            foreach ($workerConfigurationSha256s as $configurationSha256) {
                $workerProof = $workerProofs[$configurationSha256] ?? null;
                if (is_array($workerProof)) {
                    self::publishInstalledFpmWorker(
                        $root,
                        $controlProofSha256,
                        $workerProof,
                        $configurationSha256,
                        $ownerUid,
                        $ownerGid,
                        $clock,
                        $bootId
                    );
                } else {
                    self::removePublicationIfPresent(
                        self::installedFpmWorkerPath(
                            $root,
                            $configurationSha256,
                            $controlProofSha256
                        ),
                        $ownerUid
                    );
                }
                $published++;
                self::installedFpmCheckpoint($published);
            }
            $controlReceipt = self::publishInstalledFpmControlReceipt(
                $root,
                $controlProof,
                $artifacts,
                $ownerUid,
                $ownerGid,
                $clock,
                $bootId
            );
            try {
                self::assertInstalledFpmArtifactSnapshotCurrent($artifactSnapshot);
            } catch (\Throwable $error) {
                // The transaction lock prevents another installed generation
                // from becoming current between this comparison and exact
                // invalidation. Revoke control first, then its workers: a
                // crash can leave only non-current worker residue, never a
                // readiness-admitted control receipt for changed bytes.
                self::invalidateInstalledFpmGeneration(
                    $root,
                    $controlProofSha256,
                    $workerConfigurationSha256s,
                    $ownerUid
                );
                throw new ControlRefusal(
                    'installed FPM proof artifacts changed during publication',
                    0,
                    $error
                );
            }
            self::reconcileInstalledFpmWorkerVersions(
                $root,
                $controlProofSha256,
                $ownerUid,
                $transactionNow
            );
            return $controlReceipt;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<string,mixed> $proof
     * @param callable():int|null $clock
     * @param callable():string|null $bootId
     * @return array<string,mixed>
     */
    public static function publishInstalledFpmControl(
        string $hostPreflightRoot,
        array $proof,
        int $ownerUid,
        int $ownerGid,
        ?callable $clock = null,
        ?callable $bootId = null,
        ?string $deployRoot = null
    ): array {
        $artifactSnapshot = self::installedFpmArtifactSnapshot(
            $deployRoot ?? dirname(__DIR__) . '/deploy'
        );
        $artifacts = $artifactSnapshot['artifacts'];
        $root = self::privateRoot($hostPreflightRoot, $ownerUid);
        $lock = self::acquireInstalledFpmTransactionLock($root, $ownerUid, $ownerGid);
        try {
            $receipt = self::publishInstalledFpmControlReceipt(
                $root,
                $proof,
                $artifacts,
                $ownerUid,
                $ownerGid,
                $clock,
                $bootId
            );
            try {
                self::assertInstalledFpmArtifactSnapshotCurrent($artifactSnapshot);
            } catch (\Throwable $error) {
                self::invalidateInstalledFpmGeneration(
                    $root,
                    self::documentSha256(
                        'duo-cloud-installed-fpm-ingress-control-proof/v1',
                        $proof
                    ),
                    [],
                    $ownerUid
                );
                throw new ControlRefusal(
                    'installed FPM proof artifacts changed during publication',
                    0,
                    $error
                );
            }
            return $receipt;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * @param array<string,mixed> $proof
     * @param array<string,string> $artifacts
     * @param callable():int|null $clock
     * @param callable():string|null $bootId
     * @return array<string,mixed>
     */
    private static function publishInstalledFpmControlReceipt(
        string $root,
        array $proof,
        array $artifacts,
        int $ownerUid,
        int $ownerGid,
        ?callable $clock,
        ?callable $bootId
    ): array {
        self::validateInstalledFpmControlProof($proof, $artifacts);
        $now = $clock === null ? time() : $clock();
        $currentBoot = $bootId === null ? self::currentBootId() : $bootId();
        self::assertClockAndBoot($now, $currentBoot);
        $receipt = [
            'artifacts' => $artifacts,
            'boot_id' => $currentBoot,
            'expires_at' => $now + self::INSTALLED_FPM_TTL_SECONDS,
            'format' => self::INSTALLED_FPM_CONTROL_FORMAT,
            'issued_at' => $now,
            'proof' => $proof,
            'proof_sha256' => self::documentSha256(
                'duo-cloud-installed-fpm-ingress-control-proof/v1',
                $proof
            ),
        ];
        $receipt['receipt_sha256'] = self::receiptSha256(
            self::INSTALLED_FPM_CONTROL_FORMAT,
            $receipt
        );
        self::publish(
            $root . '/' . self::INSTALLED_FPM_CONTROL_FILE,
            $receipt,
            $ownerUid,
            $ownerGid
        );
        return $receipt;
    }

    /**
     * @param array<string,mixed> $proof
     * @param callable():int|null $clock
     * @param callable():string|null $bootId
     * @return array<string,mixed>
     */
    public static function publishInstalledFpmWorker(
        string $hostPreflightRoot,
        string $controlProofSha256,
        array $proof,
        string $configurationSha256,
        int $ownerUid,
        int $ownerGid,
        ?callable $clock = null,
        ?callable $bootId = null
    ): array {
        self::assertSha256($controlProofSha256, 'installed FPM control proof digest');
        self::assertSha256($configurationSha256, 'installed FPM worker configuration digest');
        self::validateInstalledFpmWorkerProof($proof, $configurationSha256);
        $now = $clock === null ? time() : $clock();
        $currentBoot = $bootId === null ? self::currentBootId() : $bootId();
        self::assertClockAndBoot($now, $currentBoot);
        $root = self::privateRoot($hostPreflightRoot, $ownerUid);
        $receipt = [
            'boot_id' => $currentBoot,
            'configuration_sha256' => $configurationSha256,
            'control_proof_sha256' => $controlProofSha256,
            'expires_at' => $now + self::INSTALLED_FPM_TTL_SECONDS,
            'format' => self::INSTALLED_FPM_FORMAT,
            'issued_at' => $now,
            'proof' => $proof,
            'proof_sha256' => self::documentSha256(
                'duo-cloud-installed-fpm-ingress-worker-proof/v1',
                $proof
            ),
        ];
        $receipt['receipt_sha256'] = self::receiptSha256(self::INSTALLED_FPM_FORMAT, $receipt);
        self::publish(
            self::installedFpmWorkerPath(
                $root,
                $configurationSha256,
                $controlProofSha256
            ),
            $receipt,
            $ownerUid,
            $ownerGid,
            true
        );
        return $receipt;
    }

    /** @return array<string,mixed> */
    public function assertCurrent(): array {
        $runtimeImage = $this->readRuntimeImageProof();
        $this->assertLinuxHostIntentAllowsReadiness();
        $linuxHost = self::readCanonicalPrivateFile($this->linuxHostPath());
        $installedControl = null;
        $installedFpm = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $candidateControl = self::readCanonicalPrivateFile($this->installedFpmControlPath());
            self::assertSha256(
                $candidateControl['proof_sha256'] ?? null,
                'installed FPM control proof digest'
            );
            try {
                $candidateWorker = self::readCanonicalPrivateFile(
                    self::installedFpmWorkerPath(
                        $this->config->hostPreflightRoot(),
                        $this->config->sha256(),
                        $candidateControl['proof_sha256']
                    )
                );
            } catch (ControlRefusal $error) {
                $controlAfter = self::readCanonicalPrivateFile(
                    $this->installedFpmControlPath()
                );
                if ($candidateControl !== $controlAfter && $attempt === 0) {
                    continue;
                }
                throw $error;
            }
            $controlAfter = self::readCanonicalPrivateFile($this->installedFpmControlPath());
            if ($candidateControl === $controlAfter) {
                $installedControl = $candidateControl;
                $installedFpm = $candidateWorker;
                break;
            }
        }
        if (!is_array($installedControl) || !is_array($installedFpm)) {
            throw new ControlRefusal('installed FPM proof changed during readiness validation');
        }
        $linuxArtifactSnapshot = $this->linuxHostArtifactSnapshot();
        $installedArtifactSnapshot = self::installedFpmArtifactSnapshot($this->deployRoot);
        $now = ($this->clock)();
        $bootId = ($this->bootId)();
        self::assertClockAndBoot($now, $bootId);

        $linuxArtifacts = $linuxArtifactSnapshot['artifacts'];
        $this->validateLinuxHostReceipt($linuxHost, $linuxArtifacts, $runtimeImage, $now, $bootId);
        $installedArtifacts = $installedArtifactSnapshot['artifacts'];
        $this->validateInstalledFpmControlReceipt(
            $installedControl,
            $installedArtifacts,
            $now,
            $bootId
        );
        $this->validateInstalledFpmReceipt(
            $installedFpm,
            $installedControl,
            $now,
            $bootId
        );

        $identity = [
            'installed_fpm_control_proof_sha256' => $installedControl['proof_sha256'],
            'installed_fpm_worker_proof_sha256' => $installedFpm['proof_sha256'],
            'linux_host_proof_sha256' => $linuxHost['proof_sha256'],
            'runtime_image_proof_receipt_sha256' => $runtimeImage['proof_receipt_sha256'],
        ];
        $current = [
            'format' => self::FORMAT,
            'identity_sha256' => self::documentSha256(self::FORMAT, $identity),
            'installed_fpm_control_receipt_sha256' => $installedControl['receipt_sha256'],
            'installed_fpm_receipt_sha256' => $installedFpm['receipt_sha256'],
            'linux_host_receipt_sha256' => $linuxHost['receipt_sha256'],
            'runtime_image_proof_receipt_sha256' => $runtimeImage['proof_receipt_sha256'],
        ];
        $this->assertLinuxHostArtifactSnapshotCurrent($linuxArtifactSnapshot);
        self::assertInstalledFpmArtifactSnapshotCurrent($installedArtifactSnapshot);
        return $current;
    }

    private function linuxHostIntentPath(): string {
        return $this->linuxHostPath() . '.intent.json';
    }

    private function assertLinuxHostIntentAllowsReadiness(): void {
        clearstatcache(true, $this->linuxHostIntentPath());
        if (@lstat($this->linuxHostIntentPath()) === false) {
            return;
        }
        $this->requireCurrentLinuxHostVerificationIntent();
    }

    /** @return array{int,string,array<string,mixed>} */
    private function requireCurrentLinuxHostVerificationIntent(): array {
        $intent = self::readCanonicalPrivateFile($this->linuxHostIntentPath());
        self::exactKeys($intent, [
            'boot_id', 'configuration_sha256', 'deadline_at', 'format',
            'started_at', 'state',
        ], 'Linux host boundary proof verification intent');
        $now = ($this->clock)();
        $bootId = ($this->bootId)();
        if (($intent['format'] ?? null)
                !== 'duo-cloud-linux-host-boundary-proof-intent/v1'
            || ($intent['state'] ?? null) !== 'verifying'
            || ($intent['boot_id'] ?? null) !== $bootId
            || ($intent['configuration_sha256'] ?? null) !== $this->config->sha256()
            || !is_int($intent['started_at'] ?? null)
            || !is_int($intent['deadline_at'] ?? null)
            || $intent['started_at'] < 1
            || $intent['deadline_at']
                !== $intent['started_at'] + self::LINUX_HOST_VERIFICATION_SECONDS
            || $now < $intent['started_at'] || $now >= $intent['deadline_at']) {
            throw new ControlRefusal(
                'Linux host boundary proof verification failed or did not terminate'
            );
        }
        return [$now, $bootId, $intent];
    }

    /** @return array<string,mixed> */
    private function readRuntimeImageProof(): array {
        $proof = self::readCanonicalPrivateFile($this->runtimeImagePath());
        $this->validateRuntimeImageProof($proof);
        return $proof;
    }

    /** @param array<string,mixed> $proof */
    private function validateRuntimeImageProof(array $proof): void {
        self::exactKeys($proof, [
            'background_process_fence', 'cleanup', 'format', 'fresh_volume_ownership',
            'helpers', 'immutable_health', 'image_id', 'immutable_image',
            'implicit_volumes', 'proof_receipt_sha256', 'runtime_ready',
            'seccomp_ioctl_policy', 'seccomp_profile_sha256', 'secrets_readable_as',
            'wordpress_policy',
        ], 'runtime image proof');
        $runtime = $this->config->runtime();
        $receipt = $proof['proof_receipt_sha256'] ?? null;
        $basis = $proof;
        unset($basis['proof_receipt_sha256']);
        if (($proof['format'] ?? null) !== 'duo-cloud-runtime-image-proof/v1'
            || ($proof['background_process_fence'] ?? null)
                !== 'real-runner-kill-dead-restart-ready-no-orphan-pid-or-write'
            || ($proof['cleanup'] ?? null) !== 'exact'
            || ($proof['fresh_volume_ownership'] ?? null) !== '10001:10001:0700'
            || ($proof['helpers'] ?? null) !== 'executable'
            || ($proof['immutable_health'] ?? null) !== 'duo-cloud-preview-runtime-health/v1'
            || !is_string($proof['image_id'] ?? null)
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $proof['image_id']) !== 1
            || ($proof['immutable_image'] ?? null) !== ($runtime['image'] ?? null)
            || ($proof['implicit_volumes'] ?? null) !== 0
            || ($proof['runtime_ready'] ?? null) !== true
            || ($proof['seccomp_ioctl_policy'] ?? null)
                !== 'native-compat-project-mutation-denied-control-allowed'
            || ($proof['seccomp_profile_sha256'] ?? null)
                !== ($runtime['seccomp_profile_sha256'] ?? null)
            || ($proof['secrets_readable_as'] ?? null) !== '10001:10001'
            || ($proof['wordpress_policy'] ?? null)
                !== 'staging-cron-and-file-mods-disabled'
            || !is_string($receipt)
            || preg_match('/\A[a-f0-9]{64}\z/D', $receipt) !== 1
            || !hash_equals(
                hash(
                    'sha256',
                    "duo-cloud-runtime-image-proof-receipt/v1\0" . CanonicalJson::encode($basis)
                ),
                $receipt
            )
            || !hash_equals((string) ($runtime['review_receipt_sha256'] ?? ''), $receipt)) {
            throw new ControlRefusal(
                'runtime image proof is absent, changed, or does not bind the configured image'
            );
        }
    }

    /**
     * @return array{
     *     artifacts:array<string,string>,
     *     files:array<string,array{
     *         path:string,
     *         sha256:string,
     *         stat:array<string|int,mixed>
     *     }>,
     *     installed_configurations:array<string,FirewallClientConfig>
     * }
     */
    private function linuxHostArtifactSnapshot(): array {
        $runtime = $this->config->runtime();
        $bindings = [
            'container_engine_sha256' => [
                $runtime['container_engine']['path'] ?? null,
                $runtime['container_engine']['sha256'] ?? null,
                'container engine',
            ],
            'firewall_authority_sha256' => [
                $runtime['firewall_authority']['path'] ?? null,
                $runtime['firewall_authority']['sha256'] ?? null,
                'firewall authority',
            ],
            'process_launcher_sha256' => [
                $runtime['process_launcher']['path'] ?? null,
                $runtime['process_launcher']['sha256'] ?? null,
                'process launcher',
            ],
            'seccomp_profile_sha256' => [
                $runtime['seccomp_profile_file'] ?? null,
                $runtime['seccomp_profile_sha256'] ?? null,
                'seccomp profile',
            ],
            'storage_authority_sha256' => [
                $runtime['storage_authority']['path'] ?? null,
                $runtime['storage_authority']['sha256'] ?? null,
                'storage authority',
            ],
        ];
        $artifacts = [];
        $files = [];
        foreach ($bindings as $key => [$path, $digest, $label]) {
            if (!is_string($path) || !is_string($digest) || !is_string($label)) {
                throw new ControlRefusal('Linux host proof artifact binding is invalid');
            }
            self::assertSha256($digest, 'Linux host proof artifact digest');
            $files[$key] = self::protectedArtifactSnapshot($path, $label);
            if (!hash_equals($digest, $files[$key]['sha256'])) {
                throw new ControlRefusal('Linux host proof artifact differs from its configuration pin');
            }
            $artifacts[$key] = $digest;
        }
        $closureDigests = [];
        foreach (self::linuxHostVerifierSourcePaths($this->deployRoot) as $name => $path) {
            $files[$name] = self::protectedArtifactSnapshot(
                $path,
                "Linux host verifier source $name"
            );
            $closureDigests[$name] = $files[$name]['sha256'];
        }
        $artifacts['php_closure_sha256'] = self::linuxHostVerifierClosureSha256($closureDigests);
        $artifacts['verifier_sha256'] = $closureDigests['deploy/verify-linux-host-boundaries.php'];
        $installed = self::linuxHostInstalledConfigurationSnapshot($this->config);
        $artifacts += $installed['artifact_sha256s'];
        ksort($artifacts, SORT_STRING);
        return [
            'artifacts' => $artifacts,
            'files' => $files,
            'installed_configurations' => $installed['configurations'],
        ];
    }

    /**
     * @param array{
     *     artifacts:array<string,string>,
     *     files:array<string,array{
     *         path:string,
     *         sha256:string,
     *         stat:array<string|int,mixed>
     *     }>,
     *     installed_configurations:array<string,FirewallClientConfig>
     * } $snapshot
     */
    private function assertLinuxHostArtifactSnapshotCurrent(array $snapshot): void {
        foreach ($snapshot['files'] as $name => $file) {
            $current = self::protectedArtifactSnapshot(
                $file['path'],
                "Linux host proof artifact $name"
            );
            if (!hash_equals($file['sha256'], $current['sha256'])
                || !self::sameFile($file['stat'], $current['stat'])) {
                throw new ControlRefusal(
                    'Linux host proof artifact snapshot changed before commit'
                );
            }
        }
        self::assertLinuxHostInstalledConfigurationSnapshotCurrent([
            'artifact_sha256s' => array_intersect_key(
                $snapshot['artifacts'],
                array_fill_keys([
                    'firewall_authority_configuration_sha256',
                    'firewall_client_configuration_sha256',
                    'storage_authority_configuration_sha256',
                    'storage_client_configuration_sha256',
                ], true)
            ),
            'configurations' => $snapshot['installed_configurations'],
        ]);
    }

    /** @return array<string,string> */
    private static function installedFpmArtifacts(string $deployRoot): array {
        return self::installedFpmArtifactSnapshot($deployRoot)['artifacts'];
    }

    /**
     * @return array{
     *     artifacts:array<string,string>,
     *     files:array<string,array{
     *         path:string,
     *         sha256:string,
     *         stat:array<string|int,mixed>
     *     }>
     * }
     */
    private static function installedFpmArtifactSnapshot(string $deployRoot): array {
        $root = realpath($deployRoot);
        if (!is_string($root) || !is_dir($root) || is_link($deployRoot)) {
            throw new ControlRefusal('installed FPM proof artifact root is invalid');
        }
        $paths = [
            'deploy/duo-cloud-control-caddy.service' => $root
                . '/duo-cloud-control-caddy.service',
            'deploy/duo-cloud-php-fpm@.service' => $root . '/duo-cloud-php-fpm@.service',
            'deploy/php-fpm-pool.conf.example' => $root . '/php-fpm-pool.conf.example',
            'deploy/php-fpm.ini' => $root . '/php-fpm.ini',
        ];
        $paths += self::installedFpmVerifierSourcePaths($root);
        ksort($paths, SORT_STRING);
        $files = [];
        foreach ($paths as $name => $path) {
            $files[$name] = self::protectedArtifactSnapshot(
                $path,
                "installed FPM artifact $name"
            );
        }
        $closureDigests = [];
        foreach (self::installedFpmVerifierSourcePaths($root) as $name => $_path) {
            $closureDigests[$name] = $files[$name]['sha256'];
        }
        $artifacts = [
            'control_unit_sha256' => $files[
                'deploy/duo-cloud-control-caddy.service'
            ]['sha256'],
            'php_closure_sha256' => self::installedFpmVerifierClosureSha256(
                $closureDigests
            ),
            'php_fpm_ini_sha256' => $files['deploy/php-fpm.ini']['sha256'],
            'php_fpm_pool_template_sha256' => $files[
                'deploy/php-fpm-pool.conf.example'
            ]['sha256'],
            'php_fpm_unit_sha256' => $files[
                'deploy/duo-cloud-php-fpm@.service'
            ]['sha256'],
            'verifier_sha256' => $closureDigests[
                'deploy/verify-installed-fpm-ingress.php'
            ],
        ];
        ksort($artifacts, SORT_STRING);
        return ['artifacts' => $artifacts, 'files' => $files];
    }

    /**
     * @return array{path:string,sha256:string,stat:array<string|int,mixed>}
     */
    private static function protectedArtifactSnapshot(string $path, string $label): array {
        clearstatcache(true, $path);
        $before = @lstat($path);
        $digest = @hash_file('sha256', $path);
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_array($before) || !is_array($after) || is_link($path)
            || ($before['mode'] & 0170000) !== 0100000
            || ($before['mode'] & 0022) !== 0 || !is_string($digest)
            || !self::sameFile($before, $after)) {
            throw new ControlRefusal("$label is not one stable protected artifact");
        }
        return ['path' => $path, 'sha256' => $digest, 'stat' => $before];
    }

    /**
     * @param array{
     *     artifacts:array<string,string>,
     *     files:array<string,array{
     *         path:string,
     *         sha256:string,
     *         stat:array<string|int,mixed>
     *     }>
     * } $snapshot
     */
    private static function assertInstalledFpmArtifactSnapshotCurrent(array $snapshot): void {
        foreach ($snapshot['files'] as $name => $file) {
            $current = self::protectedArtifactSnapshot(
                $file['path'],
                "installed FPM artifact $name"
            );
            if (!hash_equals($file['sha256'], $current['sha256'])
                || !self::sameFile($file['stat'], $current['stat'])) {
                throw new ControlRefusal(
                    'installed FPM proof artifact snapshot changed before commit'
                );
            }
        }
    }

    /** @param array<string,mixed> $proof @param array<string,string> $artifacts */
    private function validateLinuxHostProof(array $proof, array $artifacts): void {
        self::exactKeys($proof, [
            'apparmor', 'artifact_sha256s', 'cgroup', 'cleanup', 'configuration_file',
            'configuration_sha256', 'container_engine_path', 'container_engine_sha256',
            'engine_version', 'firewall', 'format', 'image_id', 'image_reference',
            'production_ready', 'proof_scope', 'seccomp_profile_path',
            'seccomp_profile_sha256', 'storage', 'storage_worker_root',
            'synthetic_rotation_configuration_sha256', 'synthetic_rotation_scope',
        ], 'Linux host boundary proof');
        $runtime = $this->config->runtime();
        if (($proof['format'] ?? null) !== 'duo-cloud-linux-host-boundary-proof/v1'
            || ($proof['apparmor'] ?? null) !== 'docker-default-enforced'
            || ($proof['production_ready'] ?? null) !== true
            || ($proof['proof_scope'] ?? null) !== 'production'
            || ($proof['cleanup'] ?? null) !== 'exact'
            || ($proof['configuration_file'] ?? null) !== $this->config->configPath()
            || ($proof['configuration_sha256'] ?? null) !== $this->config->sha256()
            || ($proof['container_engine_path'] ?? null)
                !== ($runtime['container_engine']['path'] ?? null)
            || ($proof['container_engine_sha256'] ?? null)
                !== ($runtime['container_engine']['sha256'] ?? null)
            || !is_string($proof['engine_version'] ?? null)
            || preg_match('/\A[0-9]+(?:\.[0-9]+){1,3}(?:[-+][A-Za-z0-9.-]+)?\z/D', $proof['engine_version']) !== 1
            || version_compare($proof['engine_version'], '26.0.0', '<')
            || ($proof['image_reference'] ?? null) !== ($runtime['image'] ?? null)
            || !is_string($proof['image_id'] ?? null)
            || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $proof['image_id']) !== 1
            || ($proof['seccomp_profile_path'] ?? null)
                !== ($runtime['seccomp_profile_file'] ?? null)
            || ($proof['seccomp_profile_sha256'] ?? null)
                !== ($runtime['seccomp_profile_sha256'] ?? null)
            || ($proof['artifact_sha256s'] ?? null) !== $artifacts
            || ($proof['storage_worker_root'] ?? null) !== $this->config->workerRoot()
            || ($proof['synthetic_rotation_configuration_sha256'] ?? null) !== hash(
                'sha256',
                "duo-cloud-linux-host-boundary-proof-synthetic-rotation/v1\0"
                    . $this->config->sha256()
            )
            || ($proof['synthetic_rotation_scope'] ?? null)
                !== 'firewall-multi-binding-only') {
            throw new ControlRefusal('Linux host boundary proof does not bind exact production artifacts');
        }
        $cgroup = $proof['cgroup'] ?? null;
        $firewall = $proof['firewall'] ?? null;
        $storage = $proof['storage'] ?? null;
        if (!is_array($cgroup) || array_is_list($cgroup)
            || !self::hasExactKeys($cgroup, ['success_descendant', 'timeout_descendant'])
            || $cgroup !== ['success_descendant' => 'reaped', 'timeout_descendant' => 'reaped']
            || !is_array($firewall) || array_is_list($firewall)
            || !self::hasExactKeys($firewall, [
                'apparmor', 'dns_tcp', 'dns_udp', 'external_tcp', 'gateway_tcp',
                'gateway_udp', 'host_ingress', 'multi_binding',
            ])
            || $firewall !== [
                'apparmor' => 'docker-default-enforced',
                'dns_tcp' => 'denied',
                'dns_udp' => 'denied',
                'external_tcp' => 'denied',
                'gateway_tcp' => 'denied',
                'gateway_udp' => 'denied',
                'host_ingress' => 'exact-health',
                'multi_binding' => 'preserved',
            ]
            || !is_array($storage) || array_is_list($storage)
            || !self::hasExactKeys($storage, [
                'hard_bytes', 'hard_inodes', 'ioctl_mutation',
                'proof_receipt_sha256', 'quota_state',
            ])
            || ($storage['hard_bytes'] ?? null) !== 67108864
            || ($storage['hard_inodes'] ?? null) !== 1024
            || ($storage['ioctl_mutation'] ?? null) !== 'denied'
            || !is_string($storage['proof_receipt_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $storage['proof_receipt_sha256']) !== 1
            || ($storage['quota_state'] ?? null) !== 'hard-enforced') {
            throw new ControlRefusal('Linux host boundary proof contains failed boundary evidence');
        }
    }

    /**
     * @param array<string,mixed> $receipt
     * @param array<string,string> $artifacts
     * @param array<string,mixed> $runtimeImage
     */
    private function validateLinuxHostReceipt(
        array $receipt,
        array $artifacts,
        array $runtimeImage,
        int $now,
        string $bootId
    ): void {
        self::exactKeys($receipt, [
            'artifacts', 'boot_id', 'expires_at', 'format', 'issued_at', 'proof',
            'proof_sha256', 'receipt_sha256', 'runtime_image_proof_receipt_sha256',
        ], 'Linux host boundary proof receipt');
        $proof = $receipt['proof'] ?? null;
        if (!is_array($proof) || array_is_list($proof)) {
            throw new ControlRefusal('Linux host boundary proof receipt is invalid');
        }
        $this->validateLinuxHostProof($proof, $artifacts);
        if (($receipt['format'] ?? null) !== self::LINUX_HOST_FORMAT
            || ($receipt['artifacts'] ?? null) !== $artifacts
            || ($receipt['boot_id'] ?? null) !== $bootId
            || ($receipt['runtime_image_proof_receipt_sha256'] ?? null)
                !== ($runtimeImage['proof_receipt_sha256'] ?? null)
            || ($proof['image_id'] ?? null) !== ($runtimeImage['image_id'] ?? null)
            || ($receipt['proof_sha256'] ?? null) !== self::documentSha256(
                'duo-cloud-linux-host-boundary-proof/v1',
                $proof
            )
            || !self::currentWindow($receipt, $now, self::LINUX_HOST_TTL_SECONDS)
            || !self::validReceiptSha256(self::LINUX_HOST_FORMAT, $receipt)) {
            throw new ControlRefusal('Linux host boundary proof receipt is absent, stale, or changed');
        }
    }

    /**
     * @param array<string,mixed> $proof
     * @param list<string> $workerConfigurationSha256s
     * @param array<string,string> $artifacts
     */
    private static function validateInstalledFpmProof(
        array $proof,
        array $workerConfigurationSha256s,
        array $artifacts
    ): void {
        self::exactKeys($proof, [
            'artifact_sha256s', 'configuration_sha256', 'control_caddy', 'format',
            'max_wall_seconds', 'php_fpm_ini_sha256', 'proof_receipt_sha256',
            'state', 'workers',
        ], 'installed FPM ingress proof');
        $workers = $proof['workers'] ?? null;
        $proofReceipt = $proof['proof_receipt_sha256'] ?? null;
        $proofBasis = $proof;
        unset($proofBasis['proof_receipt_sha256']);
        if (($proof['format'] ?? null) !== 'duo-cloud-installed-fpm-ingress-proof/v1'
            || ($proof['state'] ?? null) !== 'ready'
            || ($proof['max_wall_seconds'] ?? null) !== 45
            || !is_string($proof['configuration_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $proof['configuration_sha256']) !== 1
            || ($proof['artifact_sha256s'] ?? null) !== $artifacts
            || ($proof['php_fpm_ini_sha256'] ?? null) !== $artifacts['php_fpm_ini_sha256']
            || !is_string($proofReceipt)
            || preg_match('/\A[a-f0-9]{64}\z/D', $proofReceipt) !== 1
            || !hash_equals(
                hash(
                    'sha256',
                    "duo-cloud-installed-fpm-ingress-proof-receipt/v1\0"
                        . CanonicalJson::encode($proofBasis)
                ),
                $proofReceipt
            )
            || !is_array($workers) || !array_is_list($workers)) {
            throw new ControlRefusal('installed FPM ingress proof does not bind exact artifacts');
        }
        $control = $proof['control_caddy'] ?? null;
        if (!is_array($control) || array_is_list($control)) {
            throw new ControlRefusal('installed FPM ingress control proof is invalid');
        }
        self::exactKeys($control, [
            'configuration_sha256', 'gid', 'pid', 'supplementary_socket_gid', 'uid',
        ], 'installed FPM ingress control proof');
        if (!is_string($control['configuration_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $control['configuration_sha256']) !== 1
            || !self::positiveInt($control['gid'] ?? null)
            || !self::positiveInt($control['pid'] ?? null)
            || ($control['supplementary_socket_gid'] ?? null) !== 10001
            || !self::positiveInt($control['uid'] ?? null)
            || ($control['uid'] ?? null) === 10001) {
            throw new ControlRefusal('installed FPM ingress control identity is invalid');
        }
        $actual = [];
        foreach ($workers as $worker) {
            if (!is_array($worker) || array_is_list($worker)
                || !self::hasExactKeys($worker, [
                    'control_host', 'fpm_configuration_sha256', 'live_route_status',
                    'process', 'socket', 'worker_configuration_sha256', 'worker_id',
                ])
                || !is_string($worker['worker_configuration_sha256'] ?? null)
                || preg_match(
                    '/\A[a-f0-9]{64}\z/D',
                    $worker['worker_configuration_sha256']
                ) !== 1
                || !is_string($worker['fpm_configuration_sha256'] ?? null)
                || preg_match('/\A[a-f0-9]{64}\z/D', $worker['fpm_configuration_sha256']) !== 1
                || !is_string($worker['worker_id'] ?? null)
                || preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/D', $worker['worker_id']) !== 1
                || !is_string($worker['control_host'] ?? null)
                || !in_array($worker['live_route_status'] ?? null, [403, 503], true)) {
                throw new ControlRefusal('installed FPM ingress proof worker identity is invalid');
            }
            $process = $worker['process'] ?? null;
            $socket = $worker['socket'] ?? null;
            if (!is_array($process) || array_is_list($process)
                || !self::hasExactKeys($process, ['gid', 'pid', 'uid'])
                || ($process['gid'] ?? null) !== 10001
                || !self::positiveInt($process['pid'] ?? null)
                || ($process['uid'] ?? null) !== 10001
                || !is_array($socket) || array_is_list($socket)
                || !self::hasExactKeys($socket, [
                    'group', 'inode', 'mode', 'owner', 'path',
                ])
                || ($socket['group'] ?? null) !== 10001
                || !self::positiveInt($socket['inode'] ?? null)
                || ($socket['mode'] ?? null) !== '0660'
                || ($socket['owner'] ?? null) !== 10001
                || !is_string($socket['path'] ?? null)
                || $socket['path'] !== '/run/duo-cloud/' . $worker['worker_id'] . '/php-fpm.sock') {
                throw new ControlRefusal('installed FPM ingress proof worker boundary is invalid');
            }
            $actual[] = $worker['worker_configuration_sha256'];
        }
        sort($actual, SORT_STRING);
        if ($actual !== $workerConfigurationSha256s) {
            throw new ControlRefusal('installed FPM ingress proof does not bind the exact worker fleet');
        }
    }

    /** @param array<string,mixed> $proof @param array<string,string> $artifacts */
    private static function validateInstalledFpmControlProof(
        array $proof,
        array $artifacts
    ): void {
        self::exactKeys($proof, [
            'artifact_sha256s', 'configuration_sha256', 'control_caddy', 'format',
            'max_wall_seconds', 'php_fpm_ini_sha256', 'proof_receipt_sha256',
            'state', 'worker_configuration_sha256s',
        ], 'installed FPM ingress control proof');
        $workers = $proof['worker_configuration_sha256s'] ?? null;
        $proofReceipt = $proof['proof_receipt_sha256'] ?? null;
        $proofBasis = $proof;
        unset($proofBasis['proof_receipt_sha256']);
        if (($proof['format'] ?? null)
                !== 'duo-cloud-installed-fpm-ingress-control-proof/v1'
            || ($proof['state'] ?? null) !== 'ready'
            || ($proof['max_wall_seconds'] ?? null) !== 45
            || !is_string($proof['configuration_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $proof['configuration_sha256']) !== 1
            || ($proof['artifact_sha256s'] ?? null) !== $artifacts
            || ($proof['php_fpm_ini_sha256'] ?? null) !== $artifacts['php_fpm_ini_sha256']
            || !is_string($proofReceipt)
            || preg_match('/\A[a-f0-9]{64}\z/D', $proofReceipt) !== 1
            || !hash_equals(
                self::receiptSha256(
                    'duo-cloud-installed-fpm-ingress-control-proof/v1',
                    $proofBasis
                ),
                $proofReceipt
            )
            || !is_array($workers) || !array_is_list($workers)) {
            throw new ControlRefusal(
                'installed FPM ingress control proof does not bind exact artifacts'
            );
        }
        self::assertConfigurationSha256s($workers);
        $control = $proof['control_caddy'] ?? null;
        if (!is_array($control) || array_is_list($control)) {
            throw new ControlRefusal('installed FPM ingress control proof is invalid');
        }
        self::exactKeys($control, [
            'configuration_sha256', 'gid', 'pid', 'supplementary_socket_gid', 'uid',
        ], 'installed FPM ingress control process proof');
        if (!is_string($control['configuration_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $control['configuration_sha256']) !== 1
            || !self::positiveInt($control['gid'] ?? null)
            || !self::positiveInt($control['pid'] ?? null)
            || ($control['supplementary_socket_gid'] ?? null) !== 10001
            || !self::positiveInt($control['uid'] ?? null)
            || ($control['uid'] ?? null) === 10001) {
            throw new ControlRefusal('installed FPM ingress control identity is invalid');
        }
    }

    /** @param array<string,mixed> $proof */
    private static function validateInstalledFpmWorkerProof(
        array $proof,
        string $configurationSha256
    ): void {
        self::exactKeys($proof, [
            'control_host', 'format', 'fpm_configuration_sha256', 'live_route_status',
            'process', 'proof_receipt_sha256', 'socket', 'state',
            'worker_configuration_sha256', 'worker_id',
        ], 'installed FPM ingress worker proof');
        $proofReceipt = $proof['proof_receipt_sha256'] ?? null;
        $proofBasis = $proof;
        unset($proofBasis['proof_receipt_sha256']);
        if (($proof['format'] ?? null)
                !== 'duo-cloud-installed-fpm-ingress-worker-proof/v1'
            || ($proof['state'] ?? null) !== 'ready'
            || ($proof['worker_configuration_sha256'] ?? null) !== $configurationSha256
            || !is_string($proof['fpm_configuration_sha256'] ?? null)
            || preg_match('/\A[a-f0-9]{64}\z/D', $proof['fpm_configuration_sha256']) !== 1
            || !is_string($proof['worker_id'] ?? null)
            || preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/D', $proof['worker_id']) !== 1
            || !is_string($proof['control_host'] ?? null)
            || !in_array($proof['live_route_status'] ?? null, [403, 503], true)
            || !is_string($proofReceipt)
            || preg_match('/\A[a-f0-9]{64}\z/D', $proofReceipt) !== 1
            || !hash_equals(
                self::receiptSha256(
                    'duo-cloud-installed-fpm-ingress-worker-proof/v1',
                    $proofBasis
                ),
                $proofReceipt
            )) {
            throw new ControlRefusal('installed FPM ingress worker identity is invalid');
        }
        $process = $proof['process'] ?? null;
        $socket = $proof['socket'] ?? null;
        if (!is_array($process) || array_is_list($process)
            || !self::hasExactKeys($process, ['gid', 'pid', 'uid'])
            || ($process['gid'] ?? null) !== 10001
            || !self::positiveInt($process['pid'] ?? null)
            || ($process['uid'] ?? null) !== 10001
            || !is_array($socket) || array_is_list($socket)
            || !self::hasExactKeys($socket, ['group', 'inode', 'mode', 'owner', 'path'])
            || ($socket['group'] ?? null) !== 10001
            || !self::positiveInt($socket['inode'] ?? null)
            || ($socket['mode'] ?? null) !== '0660'
            || ($socket['owner'] ?? null) !== 10001
            || !is_string($socket['path'] ?? null)
            || $socket['path'] !== '/run/duo-cloud/' . $proof['worker_id'] . '/php-fpm.sock') {
            throw new ControlRefusal('installed FPM ingress worker boundary is invalid');
        }
    }

    private static function positiveInt(mixed $value): bool {
        return is_int($value) && $value > 0;
    }

    private static function installedFpmWorkerPath(
        string $root,
        string $configurationSha256,
        string $controlProofSha256
    ): string {
        self::assertSha256($configurationSha256, 'installed FPM worker configuration digest');
        self::assertSha256($controlProofSha256, 'installed FPM control proof digest');
        return $root . '/installed-fpm-ingress-proof.' . $configurationSha256
            . '.' . $controlProofSha256 . '.json';
    }

    /** @return resource */
    private static function acquireLinuxHostPublicationLock(
        string $hostPreflightRoot,
        int $ownerUid,
        int $ownerGid
    ) {
        $root = self::privateRoot($hostPreflightRoot, $ownerUid);
        $path = $root . '/' . self::LINUX_HOST_PUBLICATION_LOCK;
        clearstatcache(true, $path);
        if (@lstat($path) !== false) {
            self::assertPublicationFile($path, $ownerUid, 'Linux host publication lock');
        }
        $previousUmask = umask(0077);
        try {
            $lock = @fopen($path, 'c+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($lock) || !@chmod($path, 0600)
            || !@chown($path, $ownerUid) || !@chgrp($path, $ownerGid)
            || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new ControlRefusal('Linux host proof publication is busy');
        }
        self::assertPublicationFile($path, $ownerUid, 'Linux host publication lock');
        return $lock;
    }

    /** @return resource */
    private static function acquireInstalledFpmTransactionLock(
        string $root,
        int $ownerUid,
        int $ownerGid
    ) {
        $path = $root . '/installed-fpm-ingress-publication.lock';
        clearstatcache(true, $path);
        if (@lstat($path) !== false) {
            self::assertPublicationFile($path, $ownerUid, 'installed FPM publication lock');
        }
        $previousUmask = umask(0077);
        try {
            $lock = @fopen($path, 'c+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($lock) || !@chmod($path, 0600)
            || !@chown($path, $ownerUid) || !@chgrp($path, $ownerGid)
            || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new ControlRefusal('installed FPM proof publication is busy');
        }
        self::assertPublicationFile($path, $ownerUid, 'installed FPM publication lock');
        return $lock;
    }

    private static function currentInstalledFpmControlProofSha256(
        string $root,
        int $ownerUid
    ): ?string {
        $path = $root . '/' . self::INSTALLED_FPM_CONTROL_FILE;
        clearstatcache(true, $path);
        if (@lstat($path) === false) {
            return null;
        }
        $receipt = self::readCanonicalPrivateFile($path, $ownerUid);
        self::assertSha256(
            $receipt['proof_sha256'] ?? null,
            'installed FPM control proof digest'
        );
        return $receipt['proof_sha256'];
    }

    /** @param list<string> $configurationSha256s */
    private static function invalidateInstalledFpmGeneration(
        string $root,
        string $controlProofSha256,
        array $configurationSha256s,
        int $ownerUid
    ): void {
        self::assertSha256($controlProofSha256, 'installed FPM control proof digest');
        if (self::currentInstalledFpmControlProofSha256($root, $ownerUid)
                === $controlProofSha256) {
            self::removePublicationIfPresent(
                $root . '/' . self::INSTALLED_FPM_CONTROL_FILE,
                $ownerUid
            );
        }
        foreach ($configurationSha256s as $configurationSha256) {
            self::assertSha256(
                $configurationSha256,
                'installed FPM worker configuration digest'
            );
            self::removePublicationIfPresent(
                self::installedFpmWorkerPath(
                    $root,
                    $configurationSha256,
                    $controlProofSha256
                ),
                $ownerUid
            );
        }
    }

    private static function removeInstalledFpmWorkerVersions(
        string $root,
        string $configurationSha256,
        int $ownerUid
    ): void {
        $prefix = 'installed-fpm-ingress-proof.' . $configurationSha256 . '.';
        foreach (scandir($root) ?: [] as $entry) {
            if (!str_starts_with($entry, $prefix)) {
                continue;
            }
            if (preg_match(
                '/\Ainstalled-fpm-ingress-proof\.[a-f0-9]{64}\.[a-f0-9]{64}'
                    . '\.json(?:\.publish\.lock|\.tmp)?\z/D',
                $entry
            ) !== 1) {
                throw new ControlRefusal('installed FPM worker proof residue is malformed');
            }
            self::removePublicationArtifactUnderTransaction(
                $root . '/' . $entry,
                $ownerUid
            );
        }
    }

    private static function reconcileInstalledFpmWorkerVersions(
        string $root,
        ?string $activeControlProofSha256,
        int $ownerUid,
        int $now
    ): void {
        foreach (scandir($root) ?: [] as $entry) {
            if (!str_starts_with($entry, 'installed-fpm-ingress-proof.')) {
                continue;
            }
            if (preg_match(
                '/\Ainstalled-fpm-ingress-proof\.([a-f0-9]{64})\.([a-f0-9]{64})'
                    . '\.json(\.publish\.lock|\.tmp)?\z/D',
                $entry,
                $match
            ) !== 1) {
                throw new ControlRefusal('installed FPM worker proof residue is malformed');
            }
            if (($match[3] ?? '') !== '') {
                self::removePublicationArtifactUnderTransaction(
                    $root . '/' . $entry,
                    $ownerUid
                );
                continue;
            }
            if ($activeControlProofSha256 !== null
                && hash_equals($activeControlProofSha256, $match[2])) {
                continue;
            }
            $receipt = self::readCanonicalPrivateFile($root . '/' . $entry, $ownerUid);
            if (!is_int($receipt['expires_at'] ?? null)
                || $receipt['expires_at'] > $now) {
                continue;
            }
            self::removePublicationUnderTransaction($root . '/' . $entry, $ownerUid);
        }
    }

    private static function removePublicationIfPresent(string $path, int $ownerUid): void {
        clearstatcache(true, $path);
        if (@lstat($path) !== false) {
            self::removePublicationUnderTransaction($path, $ownerUid);
        }
    }

    private static function removePublicationUnderTransaction(string $path, int $ownerUid): void {
        self::removePublicationArtifactUnderTransaction($path, $ownerUid);
    }

    private static function removePublicationArtifactUnderTransaction(
        string $path,
        int $ownerUid
    ): void {
        $directory = self::privateRoot(dirname($path), $ownerUid);
        clearstatcache(true, $path);
        if (@lstat($path) === false) {
            return;
        }
        self::assertPublicationFile($path, $ownerUid, 'retired installed FPM publication');
        if (!@unlink($path)) {
            throw new ControlRefusal('retired installed FPM publication could not be removed');
        }
        clearstatcache(true, $path);
        if (@lstat($path) !== false) {
            throw new ControlRefusal('retired installed FPM publication remained after cleanup');
        }
        self::syncDirectory($directory);
    }

    private static function installedFpmCheckpoint(int $publishedWorkers): void {
        $expected = getenv('DUO_TEST_INSTALLED_FPM_KILL_AFTER_WORKER');
        if (!is_string($expected) || $expected !== (string) $publishedWorkers
            || !function_exists('posix_kill')) {
            return;
        }
        posix_kill(getmypid(), SIGKILL);
        usleep(1000000);
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function hasExactKeys(array $value, array $expected): bool {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        return $actual === $expected;
    }

    /** @param array<string,mixed> $receipt @param array<string,string> $artifacts */
    private function validateInstalledFpmControlReceipt(
        array $receipt,
        array $artifacts,
        int $now,
        string $bootId
    ): void {
        self::exactKeys($receipt, [
            'artifacts', 'boot_id', 'expires_at', 'format', 'issued_at', 'proof',
            'proof_sha256', 'receipt_sha256',
        ], 'installed FPM ingress control proof receipt');
        $proof = $receipt['proof'] ?? null;
        if (!is_array($proof) || array_is_list($proof)) {
            throw new ControlRefusal('installed FPM ingress control proof receipt is invalid');
        }
        self::validateInstalledFpmControlProof($proof, $artifacts);
        $workers = $proof['worker_configuration_sha256s'];
        if (($receipt['format'] ?? null) !== self::INSTALLED_FPM_CONTROL_FORMAT
            || ($receipt['artifacts'] ?? null) !== $artifacts
            || ($receipt['boot_id'] ?? null) !== $bootId
            || !in_array($this->config->sha256(), $workers, true)
            || ($receipt['proof_sha256'] ?? null) !== self::documentSha256(
                'duo-cloud-installed-fpm-ingress-control-proof/v1',
                $proof
            )
            || !self::currentWindow($receipt, $now, self::INSTALLED_FPM_TTL_SECONDS)
            || !self::validReceiptSha256(self::INSTALLED_FPM_CONTROL_FORMAT, $receipt)) {
            throw new ControlRefusal(
                'installed FPM ingress control proof receipt is absent, stale, or changed'
            );
        }
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $controlReceipt */
    private function validateInstalledFpmReceipt(
        array $receipt,
        array $controlReceipt,
        int $now,
        string $bootId
    ): void {
        self::exactKeys($receipt, [
            'boot_id', 'configuration_sha256', 'control_proof_sha256', 'expires_at',
            'format', 'issued_at', 'proof', 'proof_sha256', 'receipt_sha256',
        ], 'installed FPM ingress worker proof receipt');
        $proof = $receipt['proof'] ?? null;
        if (!is_array($proof) || array_is_list($proof)) {
            throw new ControlRefusal('installed FPM ingress worker proof receipt is invalid');
        }
        self::validateInstalledFpmWorkerProof($proof, $this->config->sha256());
        if (($receipt['format'] ?? null) !== self::INSTALLED_FPM_FORMAT
            || ($receipt['configuration_sha256'] ?? null) !== $this->config->sha256()
            || ($receipt['boot_id'] ?? null) !== $bootId
            || ($receipt['control_proof_sha256'] ?? null)
                !== ($controlReceipt['proof_sha256'] ?? null)
            || ($receipt['proof_sha256'] ?? null) !== self::documentSha256(
                'duo-cloud-installed-fpm-ingress-worker-proof/v1',
                $proof
            )
            || !self::currentWindow($receipt, $now, self::INSTALLED_FPM_TTL_SECONDS)
            || !self::validReceiptSha256(self::INSTALLED_FPM_FORMAT, $receipt)) {
            throw new ControlRefusal(
                'installed FPM ingress worker proof receipt is absent, stale, or changed'
            );
        }
    }

    /** @param array<string,mixed> $receipt */
    private static function currentWindow(array $receipt, int $now, int $ttl): bool {
        return is_int($receipt['issued_at'] ?? null)
            && is_int($receipt['expires_at'] ?? null)
            && $receipt['issued_at'] > 0
            && $receipt['expires_at'] === $receipt['issued_at'] + $ttl
            && $now >= $receipt['issued_at']
            && $now < $receipt['expires_at'];
    }

    /** @param array<string,mixed> $receipt */
    private static function validReceiptSha256(string $format, array $receipt): bool {
        $actual = $receipt['receipt_sha256'] ?? null;
        if (!is_string($actual) || preg_match('/\A[a-f0-9]{64}\z/D', $actual) !== 1) {
            return false;
        }
        $basis = $receipt;
        unset($basis['receipt_sha256']);
        return hash_equals(self::receiptSha256($format, $basis), $actual);
    }

    /** @param array<string,mixed> $basis */
    private static function receiptSha256(string $format, array $basis): string {
        unset($basis['receipt_sha256']);
        return hash('sha256', $format . "\0" . CanonicalJson::encode($basis));
    }

    /** @param array<string,mixed> $document */
    private static function documentSha256(string $format, array $document): string {
        return hash('sha256', $format . "\0" . CanonicalJson::encode($document));
    }

    /** @param list<string> $sha256s */
    private static function assertConfigurationSha256s(array $sha256s): void {
        if ($sha256s === [] || count($sha256s) > 64) {
            throw new ControlRefusal('installed FPM proof worker configuration set is invalid');
        }
        $previous = null;
        foreach ($sha256s as $sha256) {
            self::assertSha256($sha256, 'installed FPM worker configuration digest');
            if ($previous !== null && strcmp($previous, $sha256) >= 0) {
                throw new ControlRefusal('installed FPM proof worker configurations are not unique and sorted');
            }
            $previous = $sha256;
        }
    }

    private static function assertSha256(mixed $value, string $label): void {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new ControlRefusal("$label is invalid");
        }
    }

    private static function assertClockAndBoot(int $now, string $bootId): void {
        if ($now < 1 || preg_match('/\A(?:[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}|nonlinux-[a-f0-9]{64})\z/D', $bootId) !== 1) {
            throw new ControlRefusal('production deployment proof clock or boot identity is invalid');
        }
    }

    public static function currentBootId(): string {
        $path = '/proc/sys/kernel/random/boot_id';
        $bytes = @file_get_contents($path);
        if (is_string($bytes)) {
            $bootId = trim($bytes);
            if (preg_match('/\A[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\z/D', $bootId) === 1) {
                return $bootId;
            }
        }
        if (PHP_OS_FAMILY !== 'Linux') {
            return 'nonlinux-' . hash('sha256', php_uname('a'));
        }
        throw new ControlRefusal('Linux boot identity is unavailable');
    }

    private static function effectiveUid(): int {
        return function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid();
    }

    private static function effectiveGid(): int {
        return function_exists('posix_getegid') ? posix_getegid() : (int) getmygid();
    }

    /** @param list<string> $sha256s */
    private static function assertFleetNamespaceKeepSet(array $sha256s, string $label): void {
        if (!array_is_list($sha256s) || $sha256s === []
            || count($sha256s) > self::FLEET_NAMESPACE_LIMIT) {
            throw new ControlRefusal(
                "production deployment proof $label keep set is not bounded"
            );
        }
        $previous = null;
        foreach ($sha256s as $sha256) {
            self::assertSha256($sha256, "$label keep identity");
            if ($previous !== null && strcmp($previous, $sha256) >= 0) {
                throw new ControlRefusal(
                    "production deployment proof $label keep set is not sorted and unique"
                );
            }
            $previous = $sha256;
        }
    }

    /**
     * @param list<string> $configurationSha256s
     * @param list<string> $runtimeReviewSha256s
     */
    private static function reconcileFleetNamespaceAt(
        string $root,
        array $configurationSha256s,
        array $runtimeReviewSha256s,
        int $ownerUid,
        int $ownerGid,
        string $executionLockPath,
        string $runtimeLockPath,
        string $linuxLockPath
    ): void {
        $root = self::privateRoot($root, $ownerUid);
        $rootStat = @lstat($root);
        if (!is_array($rootStat) || (int) ($rootStat['gid'] ?? -1) !== $ownerGid) {
            throw new ControlRefusal(
                'production deployment proof root group identity is invalid'
            );
        }
        self::assertFleetNamespaceKeepSet($configurationSha256s, 'configuration');
        self::assertFleetNamespaceKeepSet($runtimeReviewSha256s, 'runtime review receipt');
        if ($runtimeLockPath !== $root . '/' . self::RUNTIME_IMAGE_PUBLICATION_LOCK
            || $linuxLockPath !== $root . '/' . self::LINUX_HOST_PUBLICATION_LOCK) {
            throw new ControlRefusal('production deployment proof family lock paths are invalid');
        }
        foreach ([
            [$executionLockPath, 'Linux host verifier execution lock'],
            [$runtimeLockPath, 'runtime image proof family lock'],
            [$linuxLockPath, 'Linux host proof family lock'],
        ] as [$lockPath, $label]) {
            self::assertFleetNamespaceLockPath(
                $lockPath,
                $ownerUid,
                $ownerGid,
                $label
            );
        }

        $configurationKeep = array_fill_keys($configurationSha256s, true);
        $runtimeKeep = array_fill_keys($runtimeReviewSha256s, true);
        // Refuse a malformed or unsafe managed node before lock-file creation.
        // A second complete scan after all locks are held is the deletion plan.
        $preLockPlan = self::fleetNamespacePlan(
            $root,
            $configurationKeep,
            $runtimeKeep,
            $ownerUid,
            $ownerGid
        );

        $locks = [];
        try {
            foreach ([
                [$executionLockPath, 'Linux host verifier execution lock'],
                [$runtimeLockPath, 'runtime image proof family lock'],
                [$linuxLockPath, 'Linux host proof family lock'],
            ] as [$lockPath, $label]) {
                $locks[] = self::acquireFleetNamespaceLock(
                    $lockPath,
                    $ownerUid,
                    $ownerGid,
                    $label
                );
            }

            // Pre-release builds used one sidecar per publication. They never
            // shipped, but accepting those exact residues makes interrupted
            // upgrades recoverable. Acquire the bounded complete set after
            // the fixed family locks and hold it through deletion: even a
            // test-held legacy inode is transient, never partial cleanup.
            foreach ($preLockPlan['legacy_locks'] as $legacyLockPath) {
                $locks[] = self::acquireExistingFleetNamespaceLock(
                    $legacyLockPath,
                    $ownerUid,
                    $ownerGid,
                    'legacy proof publication lock'
                );
            }

            $plan = self::fleetNamespacePlan(
                $root,
                $configurationKeep,
                $runtimeKeep,
                $ownerUid,
                $ownerGid
            );
            if ($plan['legacy_locks'] !== $preLockPlan['legacy_locks']) {
                throw new ControlRefusal(
                    'production deployment proof legacy lock set changed during reconciliation'
                );
            }
            $removed = 0;
            foreach ($plan['delete'] as $node) {
                self::removeFleetNamespaceNode(
                    $node['path'],
                    $node['stat'],
                    $ownerUid,
                    $ownerGid
                );
                $removed++;
                self::fleetNamespaceCheckpoint($removed);
            }
            if ($removed !== 0) {
                // One directory fsync makes the whole bounded unlink batch
                // durable; a SIGKILL before it is repaired by the next pass.
                self::syncDirectory($root);
            }
        } finally {
            foreach (array_reverse($locks) as $lock) {
                @flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function assertFleetNamespaceLockPath(
        string $path,
        int $ownerUid,
        int $ownerGid,
        string $label
    ): void {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")
            || str_contains($path, '//') || str_ends_with($path, '/')) {
            throw new ControlRefusal("production deployment proof $label path is invalid");
        }
        $directory = dirname($path);
        $real = realpath($directory);
        $stat = @lstat($directory);
        if (!is_string($real) || $real !== $directory || !is_array($stat)
            || is_link($directory) || ($stat['mode'] & 0170000) !== 0040000
            || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0077) !== 0)
            || (int) ($stat['uid'] ?? -1) !== $ownerUid
            || (int) ($stat['gid'] ?? -1) !== $ownerGid) {
            throw new ControlRefusal(
                "production deployment proof $label directory is not private"
            );
        }
        clearstatcache(true, $path);
        if (@lstat($path) !== false) {
            self::assertFleetNamespaceFile($path, $ownerUid, $ownerGid, $label, true);
        }
    }

    /** @return resource */
    private static function acquireFleetNamespaceLock(
        string $path,
        int $ownerUid,
        int $ownerGid,
        string $label
    ) {
        self::assertFleetNamespaceLockPath($path, $ownerUid, $ownerGid, $label);
        $previousUmask = umask(0077);
        try {
            $lock = @fopen($path, 'c+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($lock)) {
            throw new ControlRefusal("production deployment proof $label could not be opened");
        }
        try {
            if (!@chmod($path, 0600) || !@chown($path, $ownerUid)
                || !@chgrp($path, $ownerGid)) {
                throw new ControlRefusal(
                    "production deployment proof $label could not be protected"
                );
            }
            self::assertOpenedFleetNamespaceFile(
                $lock,
                $path,
                $ownerUid,
                $ownerGid,
                $label,
                true
            );
            $wouldBlock = 0;
            if (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
                if ($wouldBlock === 1) {
                    throw new HostAuthorityBusy(
                        "production deployment proof $label is busy"
                    );
                }
                throw new ControlRefusal(
                    "production deployment proof $label could not be acquired"
                );
            }
            self::assertOpenedFleetNamespaceFile(
                $lock,
                $path,
                $ownerUid,
                $ownerGid,
                $label,
                true
            );
            return $lock;
        } catch (\Throwable $error) {
            fclose($lock);
            throw $error;
        }
    }

    /** @return resource */
    private static function acquireExistingFleetNamespaceLock(
        string $path,
        int $ownerUid,
        int $ownerGid,
        string $label
    ) {
        self::assertFleetNamespaceFile($path, $ownerUid, $ownerGid, $label, true);
        $lock = @fopen($path, 'r+b');
        if (!is_resource($lock)) {
            throw new ControlRefusal("production deployment proof $label could not be opened");
        }
        try {
            self::assertOpenedFleetNamespaceFile(
                $lock,
                $path,
                $ownerUid,
                $ownerGid,
                $label,
                true
            );
            $wouldBlock = 0;
            if (!flock($lock, LOCK_EX | LOCK_NB, $wouldBlock)) {
                if ($wouldBlock === 1) {
                    throw new HostAuthorityBusy(
                        "production deployment proof $label is busy"
                    );
                }
                throw new ControlRefusal(
                    "production deployment proof $label could not be acquired"
                );
            }
            self::assertOpenedFleetNamespaceFile(
                $lock,
                $path,
                $ownerUid,
                $ownerGid,
                $label,
                true
            );
            return $lock;
        } catch (\Throwable $error) {
            fclose($lock);
            throw $error;
        }
    }

    /**
     * @param array<string,true> $configurationKeep
     * @param array<string,true> $runtimeKeep
     * @return array{
     *     delete:list<array{path:string,stat:array<string|int,mixed>}>,
     *     legacy_locks:list<string>
     * }
     */
    private static function fleetNamespacePlan(
        string $root,
        array $configurationKeep,
        array $runtimeKeep,
        int $ownerUid,
        int $ownerGid
    ): array {
        $entries = @scandir($root);
        if (!is_array($entries)) {
            throw new ControlRefusal('production deployment proof namespace could not be scanned');
        }
        $plan = [];
        $legacyLocks = [];
        $managedCount = 0;
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root . '/' . $entry;
            if ($entry === self::RUNTIME_IMAGE_PUBLICATION_LOCK
                || $entry === self::LINUX_HOST_PUBLICATION_LOCK) {
                self::assertFleetNamespaceFile(
                    $path,
                    $ownerUid,
                    $ownerGid,
                    'proof family lock',
                    true
                );
                continue;
            }

            $keep = false;
            $managed = false;
            if (preg_match(
                '/\Aruntime-image-proof\.([a-f0-9]{64})\.json'
                    . '(\.tmp|\.publish\.lock)?\z/D',
                $entry,
                $match
            ) === 1) {
                $managed = true;
                $suffix = $match[2] ?? '';
                $keep = $suffix === '' && isset($runtimeKeep[$match[1]]);
            } elseif (preg_match(
                '/\Alinux-host-boundaries-proof\.([a-f0-9]{64})\.json'
                    . '(\.tmp|\.publish\.lock|\.intent\.json(?:\.tmp|\.publish\.lock)?)?\z/D',
                $entry,
                $match
            ) === 1) {
                $managed = true;
                $suffix = $match[2] ?? '';
                $keep = in_array($suffix, ['', '.intent.json'], true)
                    && isset($configurationKeep[$match[1]]);
            } elseif (str_starts_with($entry, 'runtime-image-proof')
                || str_starts_with($entry, 'linux-host-boundaries-proof')) {
                throw new ControlRefusal(
                    'production deployment proof namespace contains an unknown managed name'
                );
            }
            if (!$managed) {
                continue;
            }

            $managedCount++;
            if ($managedCount > self::FLEET_NAMESPACE_NODE_LIMIT) {
                throw new ControlRefusal(
                    'production deployment proof namespace exceeds its bounded node limit'
                );
            }

            $lockSidecar = str_ends_with($entry, '.publish.lock');
            $stat = self::assertFleetNamespaceFile(
                $path,
                $ownerUid,
                $ownerGid,
                'managed namespace node',
                $lockSidecar
            );
            if ($lockSidecar) {
                $legacyLocks[] = $path;
                if (count($legacyLocks) > self::FLEET_NAMESPACE_LEGACY_LOCK_LIMIT) {
                    throw new ControlRefusal(
                        'production deployment proof namespace exceeds its bounded legacy lock limit'
                    );
                }
            }
            if (!$keep) {
                $plan[] = ['path' => $path, 'stat' => $stat];
            }
        }
        usort(
            $plan,
            static fn (array $left, array $right): int => strcmp($left['path'], $right['path'])
        );
        sort($legacyLocks, SORT_STRING);
        return ['delete' => $plan, 'legacy_locks' => $legacyLocks];
    }

    /** @return array<string|int,mixed> */
    private static function assertFleetNamespaceFile(
        string $path,
        int $ownerUid,
        int $ownerGid,
        string $label,
        bool $empty
    ): array {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0100000
            || (int) ($stat['nlink'] ?? 0) !== 1
            || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0777) !== 0600)
            || (int) ($stat['uid'] ?? -1) !== $ownerUid
            || (int) ($stat['gid'] ?? -1) !== $ownerGid
            || ($empty && (int) ($stat['size'] ?? -1) !== 0)) {
            throw new ControlRefusal(
                "production deployment proof $label is not a private single-link file"
            );
        }
        return $stat;
    }

    /** @param resource $handle */
    private static function assertOpenedFleetNamespaceFile(
        $handle,
        string $path,
        int $ownerUid,
        int $ownerGid,
        string $label,
        bool $empty
    ): void {
        $pathStat = self::assertFleetNamespaceFile(
            $path,
            $ownerUid,
            $ownerGid,
            $label,
            $empty
        );
        $opened = fstat($handle);
        if (!is_array($opened) || !self::sameFleetNamespaceFile($pathStat, $opened)) {
            throw new ControlRefusal(
                "production deployment proof $label changed while opening"
            );
        }
    }

    /**
     * @param array<string|int,mixed> $planned
     */
    private static function removeFleetNamespaceNode(
        string $path,
        array $planned,
        int $ownerUid,
        int $ownerGid
    ): void {
        $lockSidecar = str_ends_with($path, '.publish.lock');
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal(
                'production deployment proof obsolete namespace node could not be opened'
            );
        }
        try {
            self::assertOpenedFleetNamespaceFile(
                $handle,
                $path,
                $ownerUid,
                $ownerGid,
                'obsolete namespace node',
                $lockSidecar
            );
            $opened = fstat($handle);
            if (!is_array($opened) || !self::sameFleetNamespaceFile($planned, $opened)
                || !@unlink($path)) {
                throw new ControlRefusal(
                    'production deployment proof obsolete namespace node changed before removal'
                );
            }
            clearstatcache(true, $path);
            $unlinked = fstat($handle);
            if (!is_array($unlinked) || @lstat($path) !== false
                || (int) ($unlinked['nlink'] ?? -1) !== 0
                || (int) ($opened['dev'] ?? -1) !== (int) ($unlinked['dev'] ?? -2)
                || (int) ($opened['ino'] ?? -1) !== (int) ($unlinked['ino'] ?? -2)) {
                throw new ControlRefusal(
                    'production deployment proof obsolete namespace node changed while removing'
                );
            }
        } finally {
            fclose($handle);
        }
    }

    private static function fleetNamespaceCheckpoint(int $removed): void {
        $killAfter = getenv('DUO_TEST_DEPLOYMENT_PROOF_RECONCILE_KILL_AFTER');
        if (!function_exists('posix_kill') || !is_string($killAfter)
            || !ctype_digit($killAfter) || (int) $killAfter !== $removed) {
            return;
        }
        posix_kill(getmypid(), SIGKILL);
        usleep(1000000);
        exit(137);
    }

    /**
     * @param array<string|int,mixed> $left
     * @param array<string|int,mixed> $right
     */
    private static function sameFleetNamespaceFile(array $left, array $right): bool {
        foreach (['dev', 'ino', 'mode', 'uid', 'gid', 'nlink', 'size', 'mtime'] as $field) {
            if ((int) ($left[$field] ?? -1) !== (int) ($right[$field] ?? -2)) {
                return false;
            }
        }
        return true;
    }

    private static function privateRoot(string $path, int $ownerUid): string {
        $real = realpath($path);
        $stat = @lstat($path);
        if (!is_string($real) || $real !== $path || !is_array($stat) || is_link($path)
            || ($stat['mode'] & 0170000) !== 0040000
            || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0077) !== 0)
            || (int) ($stat['uid'] ?? -1) !== $ownerUid) {
            throw new ControlRefusal('production deployment proof root must be service-owned and private');
        }
        return $real;
    }

    /** @return array<string,mixed> */
    private static function readCanonicalPrivateFile(string $path, ?int $ownerUid = null): array {
        clearstatcache(true, $path);
        $before = @lstat($path);
        $uid = $ownerUid ?? self::effectiveUid();
        if (!is_array($before) || is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || (int) ($before['nlink'] ?? 0) !== 1
            || (DIRECTORY_SEPARATOR === '/' && ($before['mode'] & 0777) !== 0600)
            || (int) ($before['uid'] ?? -1) !== $uid
            || (int) ($before['size'] ?? -1) < 2
            || (int) $before['size'] > self::DOCUMENT_LIMIT) {
            throw new ControlRefusal('production deployment proof file is absent or not private');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal('production deployment proof file could not be opened');
        }
        $opened = fstat($handle);
        $bytes = stream_get_contents($handle, self::DOCUMENT_LIMIT + 1);
        $closed = fclose($handle);
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_array($opened) || !is_array($after) || !is_string($bytes) || !$closed
            || !self::sameFile($before, $opened) || !self::sameFile($before, $after)
            || !str_ends_with($bytes, "\n") || strlen($bytes) > self::DOCUMENT_LIMIT) {
            throw new ControlRefusal('production deployment proof changed while reading');
        }
        $document = CanonicalJson::decodeObject($bytes, self::DOCUMENT_LIMIT);
        if ($bytes !== CanonicalJson::encode($document) . "\n") {
            throw new ControlRefusal('production deployment proof is not canonical JSON');
        }
        return $document;
    }

    /** @param array<string,mixed> $document */
    private static function publish(
        string $path,
        array $document,
        int $ownerUid,
        int $ownerGid,
        bool $transactionLocked = false,
        ?string $lockFamily = null
    ): void {
        $directory = self::privateRoot(dirname($path), $ownerUid);
        $bytes = CanonicalJson::encode($document) . "\n";
        if (strlen($bytes) > self::DOCUMENT_LIMIT) {
            throw new ControlRefusal('production deployment proof exceeds its byte limit');
        }
        if ($lockFamily !== null
            && preg_match('/\A[a-z][a-z0-9-]{0,63}\.lock\z/D', $lockFamily) !== 1) {
            throw new ControlRefusal('production deployment proof lock family is invalid');
        }
        $lockPath = $lockFamily === null
            ? $path . '.publish.lock'
            : $directory . '/' . $lockFamily;
        $temporary = $path . '.tmp';
        $lock = null;
        $handle = null;
        try {
            if (!$transactionLocked) {
                clearstatcache(true, $lockPath);
                if (@lstat($lockPath) !== false) {
                    self::assertPublicationFile($lockPath, $ownerUid, 'publication lock');
                }
                $previousUmask = umask(0077);
                try {
                    $lock = @fopen($lockPath, 'c+b');
                } finally {
                    umask($previousUmask);
                }
                if (!is_resource($lock) || !@chmod($lockPath, 0600)
                    || !@chown($lockPath, $ownerUid) || !@chgrp($lockPath, $ownerGid)
                    || !flock($lock, LOCK_EX | LOCK_NB)) {
                    throw new ControlRefusal('production deployment proof publication lock is busy');
                }
                self::assertPublicationFile($lockPath, $ownerUid, 'publication lock');
            }
            self::reconcileTemporaryPublication($temporary, $ownerUid, $directory);
            clearstatcache(true, $path);
            if (@lstat($path) !== false) {
                self::assertPublicationFile($path, $ownerUid, 'current publication');
            }
            $previousUmask = umask(0077);
            try {
                $handle = @fopen($temporary, 'x+b');
            } finally {
                umask($previousUmask);
            }
            if (!is_resource($handle) || !@chmod($temporary, 0600)
                || !@chown($temporary, $ownerUid) || !@chgrp($temporary, $ownerGid)) {
                throw new ControlRefusal('production deployment proof temporary file could not be sealed');
            }
            self::assertOpenedPublicationFile(
                $handle,
                $temporary,
                $ownerUid,
                'publication temporary file'
            );
            self::writeAll($handle, $bytes);
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))
                || !self::publicationCheckpoint('temporary-synchronized')
                || !fclose($handle)) {
                $handle = null;
                throw new ControlRefusal('production deployment proof could not be synchronized');
            }
            $handle = null;
            if (!@rename($temporary, $path)) {
                throw new ControlRefusal('production deployment proof could not be atomically published');
            }
            self::publicationCheckpoint('published-before-directory-sync');
            self::readCanonicalPrivateFile($path, $ownerUid);
            self::syncDirectory($directory);
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (is_resource($lock)) {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
        }
    }

    private static function reconcileTemporaryPublication(
        string $path,
        int $ownerUid,
        string $directory
    ): void {
        clearstatcache(true, $path);
        if (@lstat($path) === false) {
            return;
        }
        self::assertPublicationFile($path, $ownerUid, 'stale publication temporary file');
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal('stale deployment proof publication could not be opened');
        }
        try {
            self::assertOpenedPublicationFile(
                $handle,
                $path,
                $ownerUid,
                'stale publication temporary file'
            );
            $opened = fstat($handle);
            if (!@unlink($path)) {
                throw new ControlRefusal('stale deployment proof publication could not be removed');
            }
            clearstatcache(true, $path);
            $unlinked = fstat($handle);
            if (!is_array($opened) || !is_array($unlinked) || @lstat($path) !== false
                || (int) ($unlinked['nlink'] ?? -1) !== 0
                || (int) $opened['dev'] !== (int) $unlinked['dev']
                || (int) $opened['ino'] !== (int) $unlinked['ino']) {
                throw new ControlRefusal('stale deployment proof publication changed while removing');
            }
        } finally {
            fclose($handle);
        }
        self::syncDirectory($directory);
    }

    private static function assertPublicationFile(string $path, int $ownerUid, string $label): void {
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0100000
            || (int) ($stat['nlink'] ?? 0) !== 1
            || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0777) !== 0600)
            || (int) ($stat['uid'] ?? -1) !== $ownerUid) {
            throw new ControlRefusal("production deployment proof $label is not private");
        }
    }

    /** @param resource $handle */
    private static function assertOpenedPublicationFile(
        $handle,
        string $path,
        int $ownerUid,
        string $label
    ): void {
        $pathStat = @lstat($path);
        $opened = fstat($handle);
        if (!is_array($pathStat) || !is_array($opened)
            || !self::sameFile($pathStat, $opened)
            || (int) ($opened['uid'] ?? -1) !== $ownerUid
            || (DIRECTORY_SEPARATOR === '/' && ($opened['mode'] & 0777) !== 0600)) {
            throw new ControlRefusal("production deployment proof $label changed while opening");
        }
    }

    private static function publicationCheckpoint(string $phase): bool {
        if (!function_exists('posix_kill')
            || getenv('DUO_TEST_DEPLOYMENT_PROOF_KILL_PHASE') !== $phase) {
            return true;
        }
        posix_kill(getmypid(), SIGKILL);
        usleep(1000000);
        return false;
    }

    private static function removePublication(
        string $path,
        int $ownerUid,
        ?string $lockFamily = null
    ): void {
        $directory = self::privateRoot(dirname($path), $ownerUid);
        if ($lockFamily !== null
            && preg_match('/\A[a-z][a-z0-9-]{0,63}\.lock\z/D', $lockFamily) !== 1) {
            throw new ControlRefusal('production deployment proof lock family is invalid');
        }
        $lockPath = $lockFamily === null
            ? $path . '.publish.lock'
            : $directory . '/' . $lockFamily;
        clearstatcache(true, $lockPath);
        if (@lstat($lockPath) !== false) {
            self::assertPublicationFile($lockPath, $ownerUid, 'publication lock');
        }
        $previousUmask = umask(0077);
        try {
            $lock = @fopen($lockPath, 'c+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($lock) || !@chmod($lockPath, 0600)
            || !@chown($lockPath, $ownerUid) || !flock($lock, LOCK_EX | LOCK_NB)) {
            if (is_resource($lock)) {
                fclose($lock);
            }
            throw new ControlRefusal('production deployment proof invalidation lock is busy');
        }
        try {
            self::assertPublicationFile($lockPath, $ownerUid, 'publication lock');
            clearstatcache(true, $path);
            $before = @lstat($path);
            if ($before === false) {
                return;
            }
            self::assertPublicationFile($path, $ownerUid, 'current publication');
            if (!@unlink($path)) {
                throw new ControlRefusal('production deployment proof could not be invalidated exactly');
            }
            clearstatcache(true, $path);
            if (@lstat($path) !== false) {
                throw new ControlRefusal('production deployment proof remained after invalidation');
            }
            self::syncDirectory($directory);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private static function syncDirectory(string $directory): void {
        if (!function_exists('fsync')) {
            return;
        }
        $handle = @fopen($directory, 'rb');
        if (!is_resource($handle) || !@fsync($handle) || !fclose($handle)) {
            throw new ControlRefusal('production deployment proof directory could not be synchronized');
        }
    }

    /** @param resource $handle */
    private static function writeAll($handle, string $bytes): void {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $written = fwrite($handle, substr($bytes, $offset));
            if (!is_int($written) || $written < 1) {
                throw new ControlRefusal('production deployment proof write was incomplete');
            }
            $offset += $written;
        }
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

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function sameFile(array $left, array $right): bool {
        foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'uid'] as $field) {
            if ((int) ($left[$field] ?? -1) !== (int) ($right[$field] ?? -2)) {
                return false;
            }
        }
        return true;
    }
}
