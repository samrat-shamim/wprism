#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace Duo\Cloud\Deploy;

/**
 * @return array{path:string,sha256:string,stat:array<string,int>}
 */
function observedInstalledFpmProofFile(string $path, string $label, int $limit): array {
    return readStableInstalledFpmProofFile($path, null, $label, $limit);
}

/**
 * @param array{path:string,sha256:string,stat:array<string,int>} $identity
 */
function revalidateInstalledFpmProofFile(
    array $identity,
    string $label,
    int $limit
): void {
    $current = readStableInstalledFpmProofFile(
        $identity['path'],
        $identity['sha256'],
        $label,
        $limit
    );
    if ($current['stat'] !== $identity['stat']) {
        throw new InstalledFpmIngressRefusal("$label changed during installed proof");
    }
}

/**
 * The source closure is read before require_once executes any of its bytes.
 * Root ownership is mandatory in production; effective-UID ownership keeps the
 * same path testable from an unprivileged source checkout.
 *
 * @return array{path:string,sha256:string,stat:array<string,int>}
 */
function readStableInstalledFpmProofFile(
    string $path,
    ?string $expectedSha256,
    string $label,
    int $limit
): array {
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")
        || str_contains($path, '//') || str_ends_with($path, '/')
        || preg_match('#(?:^|/)\.\.?(/|$)#D', $path) === 1
        || $limit < 1 || $limit > 2097152
        || ($expectedSha256 !== null
            && preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256) !== 1)) {
        throw new InstalledFpmIngressRefusal("$label descriptor is invalid");
    }
    clearstatcache(true, $path);
    $before = @lstat($path);
    $canonical = realpath($path);
    $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    $owner = is_array($before) ? (int) ($before['uid'] ?? -1) : -1;
    if (!is_array($before) || !is_string($canonical) || $canonical !== $path || is_link($path)
        || ($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0022) !== 0
        || ($effectiveUid !== null && $owner !== 0 && $owner !== $effectiveUid)
        || (int) ($before['nlink'] ?? 0) !== 1
        || (int) $before['size'] < 1 || (int) $before['size'] > $limit) {
        throw new InstalledFpmIngressRefusal("$label is not one protected regular file");
    }
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new InstalledFpmIngressRefusal("$label could not be opened");
    }
    $opened = fstat($handle);
    $context = hash_init('sha256');
    $read = hash_update_stream($context, $handle, $limit + 1);
    $finished = fstat($handle);
    $closed = fclose($handle);
    clearstatcache(true, $path);
    $after = @lstat($path);
    $actualSha256 = hash_final($context);
    if (!is_int($read) || $read < 1 || $read > $limit || !is_array($opened)
        || !is_array($finished) || !is_array($after) || !$closed
        || !sameInstalledFpmProofFile($before, $opened)
        || !sameInstalledFpmProofFile($before, $finished)
        || !sameInstalledFpmProofFile($before, $after)
        || ($expectedSha256 !== null && !hash_equals($expectedSha256, $actualSha256))) {
        throw new InstalledFpmIngressRefusal("$label changed while it was read");
    }
    $stat = [];
    foreach (['dev', 'gid', 'ino', 'mode', 'mtime', 'ctime', 'nlink', 'size', 'uid'] as $field) {
        $stat[$field] = (int) $before[$field];
    }
    return ['path' => $path, 'sha256' => $actualSha256, 'stat' => $stat];
}

/** @param array<string,mixed> $left @param array<string,mixed> $right */
function sameInstalledFpmProofFile(array $left, array $right): bool {
    foreach (['dev', 'gid', 'ino', 'mode', 'mtime', 'ctime', 'nlink', 'size', 'uid'] as $field) {
        if ((int) ($left[$field] ?? -1) !== (int) ($right[$field] ?? -2)) {
            return false;
        }
    }
    return true;
}

/** @var array<string,string> $installedFpmSourcePaths */
$installedFpmSourcePaths = [
    'deploy/verify-installed-fpm-ingress.php' => __FILE__,
    'runtime/ProductionConfig.php' => dirname(__DIR__) . '/runtime/ProductionConfig.php',
    'runtime/ProductionDeploymentProofs.php' => dirname(__DIR__)
        . '/runtime/ProductionDeploymentProofs.php',
    'src/CanonicalJson.php' => dirname(__DIR__) . '/src/CanonicalJson.php',
    'src/ControlRefusal.php' => dirname(__DIR__) . '/src/ControlRefusal.php',
    'src/HostAuthorityBusy.php' => dirname(__DIR__) . '/src/HostAuthorityBusy.php',
    'src/ImmutableOciReference.php' => dirname(__DIR__) . '/src/ImmutableOciReference.php',
];
/** @var array<string,array{path:string,sha256:string,stat:array<string,int>}> $installedFpmSourceIdentities */
$installedFpmSourceIdentities = [];
try {
    // The proof unit deliberately denies writable/executable mappings. Disable
    // PCRE JIT before the first protected-path validation invokes preg_match().
    if (@ini_set('pcre.jit', '0') === false || ini_get('pcre.jit') !== '0') {
        throw new \RuntimeException('PCRE JIT could not be disabled');
    }
    foreach ($installedFpmSourcePaths as $name => $path) {
        $installedFpmSourceIdentities[$name] = observedInstalledFpmProofFile(
            $path,
            "installed FPM verifier source $name",
            2097152
        );
    }
} catch (\Throwable) {
    fwrite(STDERR, "duo-cloud-installed-fpm-ingress: refused\n");
    exit(70);
}

require_once dirname(__DIR__) . '/runtime/ProductionDeploymentProofs.php';

class InstalledFpmIngressRefusal extends \RuntimeException {
}

/** A fleet-global proof succeeded, but one or more worker-local boundaries refused. */
final class InstalledFpmIngressWorkerRefusal extends InstalledFpmIngressRefusal {
}

/**
 * Read-only proof of the installed systemd/FPM/control-edge boundary.
 *
 * The disposable verifier proves request semantics without privileged host
 * state. This verifier closes the separate deployment premise: the active
 * units, rendered configs, live identities, and Unix socket access must all
 * agree with one root-owned, pinned descriptor before it emits readiness.
 */
final class InstalledFpmIngressVerifier {
    public const CONFIGURATION_FORMAT = 'duo-cloud-installed-fpm-ingress-config/v1';
    public const CONTRACT_FORMAT = 'duo-cloud-installed-fpm-ingress-contract/v1';
    public const PROOF_FORMAT = 'duo-cloud-installed-fpm-ingress-proof/v1';
    public const CONFIGURATION_PATH = '/var/lib/duo-cloud/config/installed-fpm-ingress.json';
    public const MAX_WALL_SECONDS = 45;

    private const CADDY_CONFIG = '/opt/duo-cloud/deploy/control-edge.Caddyfile';
    private const CONTROL_UNIT = 'duo-cloud-control-caddy.service';
    private const FPM_CONFIG_ROOT = '/var/lib/duo-cloud/config/workers';
    private const PHP_FPM_INI = '/opt/duo-cloud/deploy/php-fpm.ini';
    private const SOCKET_ROOT = '/run/duo-cloud';
    private const OUTPUT_LIMIT = 1048576;
    private const WORKER_LIMIT = 32;

    /** @var list<string> */
    private const CONTROL_PATHS = [
        '/v1/preview/control',
        '/v1/preview/lifecycle',
        '/v1/origin/controller/export',
        '/v1/origin/pair/begin',
        '/v1/origin/pair/poll',
        '/v1/origin/demand/poll',
        '/v1/origin/export/announce',
        '/v1/origin/export/missing',
        '/v1/origin/export/chunk',
        '/v1/origin/export/commit',
        '/v1/origin/key/rotate',
        '/v1/origin/revoke',
    ];

    /** @var array<string,array{path:string,sha256:string,stat:array<string,int>}> */
    private array $snapshots = [];
    /** @var array<string,list<string>> */
    private array $workerSnapshotLabels = [];
    /** @var array<string,string> */
    private array $executableCache = [];
    private float $deadline = 0.0;

    /** @return array<string,mixed> */
    public static function contract(): array {
        return [
            'format' => self::CONTRACT_FORMAT,
            'max_wall_seconds' => self::MAX_WALL_SECONDS,
            'premises' => [
                'linux-systemd-host',
                'root-proof-identity',
                'root-owned-pinned-descriptor',
                'fixed-distinct-service-identities',
            ],
            'probes' => [
                'active-byte-identical-no-drop-in-units',
                'active-process-identities-and-executables',
                'rendered-configs-pinned-before-service-start',
                'exact-control-host-to-worker-socket-topology',
                'exact-empty-404-fallback',
                'socket-owner-group-mode-and-path',
                'live-control-edge-to-every-worker-socket',
            ],
            'state' => 'described',
        ];
    }

    /**
     * @param list<string> $arguments
     * @return array{configuration_file:?string,configuration_sha256:?string,contract:bool,host_preflight_root:?string}
     */
    public static function parseArguments(array $arguments): array {
        $result = [
            'configuration_file' => null,
            'configuration_sha256' => null,
            'contract' => false,
            'host_preflight_root' => null,
        ];
        foreach ($arguments as $argument) {
            if ($argument === '--contract') {
                if ($result['contract']) {
                    throw new InstalledFpmIngressRefusal('duplicate --contract option');
                }
                $result['contract'] = true;
                continue;
            }
            $matched = false;
            foreach ([
                'configuration-file', 'configuration-sha256', 'host-preflight-root',
            ] as $option) {
                $prefix = "--$option=";
                if (!str_starts_with($argument, $prefix)) {
                    continue;
                }
                $key = str_replace('-', '_', $option);
                if ($result[$key] !== null) {
                    throw new InstalledFpmIngressRefusal("duplicate --$option option");
                }
                $value = substr($argument, strlen($prefix));
                if ($value === '') {
                    throw new InstalledFpmIngressRefusal("--$option requires a value");
                }
                $result[$key] = $value;
                $matched = true;
                break;
            }
            if (!$matched) {
                throw new InstalledFpmIngressRefusal('unknown installed verifier option');
            }
        }
        if ($result['contract']) {
            if ($result['configuration_file'] !== null || $result['configuration_sha256'] !== null
                || $result['host_preflight_root'] !== null) {
                throw new InstalledFpmIngressRefusal(
                    '--contract cannot be combined with configuration options'
                );
            }
            return $result;
        }
        if ($result['configuration_file'] !== self::CONFIGURATION_PATH
            || !is_string($result['configuration_sha256'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $result['configuration_sha256']) !== 1
            || !self::isAbsolutePath($result['host_preflight_root'])) {
            throw new InstalledFpmIngressRefusal(
                'installed verifier requires its exact configuration path and lowercase SHA-256'
            );
        }
        return $result;
    }

    /**
     * @return array{
     *   control_caddy_sha256:string,
     *   format:string,
     *   host_preflight_root:string,
     *   php_fpm_ini_sha256:string,
     *   workers:list<array{
     *     control_host:string,
     *     fpm_configuration_sha256:string,
     *     worker_configuration_sha256:string,
     *     worker_id:string
     *   }>
     * }
     */
    public static function parseConfiguration(string $bytes): array {
        if (!str_ends_with($bytes, "\n") || strlen($bytes) > 262144) {
            throw new InstalledFpmIngressRefusal(
                'installed FPM proof configuration is unterminated or oversized'
            );
        }
        try {
            $document = json_decode($bytes, true, 32, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new InstalledFpmIngressRefusal(
                'installed FPM proof configuration is not JSON',
                0,
                $error
            );
        }
        if (!is_array($document) || array_is_list($document)
            || array_keys($document) !== [
                'control_caddy_sha256', 'format', 'host_preflight_root',
                'php_fpm_ini_sha256', 'workers',
            ]
            || $document['format'] !== self::CONFIGURATION_FORMAT
            || !self::isSha256($document['control_caddy_sha256'] ?? null)
            || !self::isAbsolutePath($document['host_preflight_root'] ?? null)
            || !self::isSha256($document['php_fpm_ini_sha256'] ?? null)
            || !is_array($document['workers']) || !array_is_list($document['workers'])
            || $document['workers'] === [] || count($document['workers']) > self::WORKER_LIMIT) {
            throw new InstalledFpmIngressRefusal(
                'installed FPM proof configuration has an invalid closed schema'
            );
        }
        $previous = null;
        $hosts = [];
        foreach ($document['workers'] as $worker) {
            if (!is_array($worker) || array_is_list($worker)
                || array_keys($worker) !== [
                    'control_host', 'fpm_configuration_sha256',
                    'worker_configuration_sha256', 'worker_id',
                ]) {
                throw new InstalledFpmIngressRefusal(
                    'installed FPM proof worker descriptor has an invalid closed schema'
                );
            }
            $workerId = $worker['worker_id'] ?? null;
            $host = $worker['control_host'] ?? null;
            if (!is_string($workerId)
                || preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/D', $workerId) !== 1
                || ($previous !== null && strcmp($previous, $workerId) >= 0)
                || !is_string($host) || strlen($host) > 253
                || preg_match(
                    '/\A(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z][a-z0-9-]{1,62}\z/D',
                    $host
                ) !== 1
                || isset($hosts[$host])
                || !self::isSha256($worker['fpm_configuration_sha256'] ?? null)
                || !self::isSha256($worker['worker_configuration_sha256'] ?? null)) {
                throw new InstalledFpmIngressRefusal(
                    'installed FPM proof worker identity or pin is invalid'
                );
            }
            $hosts[$host] = true;
            $previous = $workerId;
        }
        if ($bytes !== self::canonicalJson($document) . "\n") {
            throw new InstalledFpmIngressRefusal(
                'installed FPM proof configuration is not canonical JSON'
            );
        }
        /** @var array{control_caddy_sha256:string,format:string,host_preflight_root:string,php_fpm_ini_sha256:string,workers:list<array{control_host:string,fpm_configuration_sha256:string,worker_configuration_sha256:string,worker_id:string}>} $document */
        return $document;
    }

    /**
     * @param array{configuration_file:?string,configuration_sha256:?string,contract:bool,host_preflight_root:?string} $options
     * @return array<string,mixed>
     */
    public function run(array $options): array {
        global $installedFpmSourceIdentities, $installedFpmSourcePaths;

        $started = self::monotonicNow();
        $this->deadline = $started + self::MAX_WALL_SECONDS;
        $this->assertInstalledPremises();
        if (!is_string($options['configuration_file'])
            || !is_string($options['configuration_sha256'])
            || !is_string($options['host_preflight_root'])) {
            throw new InstalledFpmIngressRefusal('installed proof configuration is unavailable');
        }
        $declaredSourcePaths = \Duo\Cloud\ProductionDeploymentProofs::installedFpmVerifierSourcePaths(__DIR__);
        if ($declaredSourcePaths !== $installedFpmSourcePaths) {
            throw new InstalledFpmIngressRefusal(
                'installed FPM verifier PHP closure registry differs from loaded sources'
            );
        }
        $loadedSources = [];
        foreach (get_included_files() as $included) {
            $canonicalIncluded = realpath($included);
            if (is_string($canonicalIncluded)
                && str_starts_with($canonicalIncluded, dirname(__DIR__) . '/')) {
                $loadedSources[] = $canonicalIncluded;
            }
        }
        $expectedSources = array_values($installedFpmSourcePaths);
        sort($loadedSources, SORT_STRING);
        sort($expectedSources, SORT_STRING);
        if ($loadedSources !== $expectedSources) {
            throw new InstalledFpmIngressRefusal(
                'installed FPM verifier loaded PHP closure is incomplete or expanded'
            );
        }
        $sourceDigests = [];
        foreach ($installedFpmSourceIdentities as $name => $identity) {
            $sourceDigests[$name] = $identity['sha256'];
        }
        $phpClosureSha256 = \Duo\Cloud\ProductionDeploymentProofs::installedFpmVerifierClosureSha256($sourceDigests);
        $configurationFile = $this->pinnedFile(
            $options['configuration_file'],
            $options['configuration_sha256'],
            262144,
            'installed proof configuration'
        );
        $configuration = self::parseConfiguration($configurationFile['bytes']);
        if ($configuration['host_preflight_root'] !== $options['host_preflight_root']) {
            throw new InstalledFpmIngressRefusal(
                'installed proof host preflight root differs from its service authority'
            );
        }

        $controlUnitArtifact = $this->unpinnedFile(
            __DIR__ . '/duo-cloud-control-caddy.service',
            262144,
            'shipped control Caddy systemd unit'
        );
        $fpmUnitArtifact = $this->unpinnedFile(
            __DIR__ . '/duo-cloud-php-fpm@.service',
            262144,
            'shipped PHP-FPM systemd unit'
        );
        $phpIni = $this->pinnedFile(
            self::PHP_FPM_INI,
            $configuration['php_fpm_ini_sha256'],
            262144,
            'installed PHP-FPM ini'
        );
        $poolTemplate = $this->unpinnedFile(
            __DIR__ . '/php-fpm-pool.conf.example',
            262144,
            'shipped PHP-FPM pool template'
        );
        $artifactSha256s = [
            'control_unit_sha256' => $controlUnitArtifact['sha256'],
            'php_closure_sha256' => $phpClosureSha256,
            'php_fpm_ini_sha256' => $phpIni['sha256'],
            'php_fpm_pool_template_sha256' => $poolTemplate['sha256'],
            'php_fpm_unit_sha256' => $fpmUnitArtifact['sha256'],
            'verifier_sha256' => $sourceDigests['deploy/verify-installed-fpm-ingress.php'],
        ];
        $identities = $this->serviceIdentities();
        $controlUnit = $this->inspectUnit(
            self::CONTROL_UNIT,
            __DIR__ . '/duo-cloud-control-caddy.service',
            'duo-cloud-edge',
            'duo-cloud-edge',
            'duo-cloud',
            '/usr/bin/caddy',
            $identities
        );
        $caddyConfig = $this->pinnedFile(
            self::CADDY_CONFIG,
            $configuration['control_caddy_sha256'],
            1048576,
            'installed control Caddy configuration'
        );
        self::assertPredatesStart(
            $caddyConfig['stat']['ctime'],
            $controlUnit['active_enter_epoch'],
            'installed control Caddy configuration'
        );

        $this->executable('/usr/sbin/php-fpm8.3', 'installed shared PHP-FPM executable');
        $workers = [];
        $workerUnits = [];
        $expectedRoutes = [];
        $socketSnapshots = [];
        foreach ($configuration['workers'] as $worker) {
            $expectedRoutes[$worker['control_host']] = self::SOCKET_ROOT
                . '/' . $worker['worker_id'] . '/php-fpm.sock';
        }
        $localFailures = [];
        foreach ($configuration['workers'] as $worker) {
            $workerId = $worker['worker_id'];
            $snapshotLabelsBefore = array_keys($this->snapshots);
            try {
                $unit = $this->inspectUnit(
                    "duo-cloud-php-fpm@$workerId.service",
                    __DIR__ . '/duo-cloud-php-fpm@.service',
                    'duo-cloud',
                    'duo-cloud',
                    'docker',
                    '/usr/sbin/php-fpm8.3',
                    $identities
                );
                $workerUnits[$workerId] = $unit;
                $fpmPath = self::FPM_CONFIG_ROOT . "/$workerId-fpm.conf";
                $fpm = $this->pinnedFile(
                    $fpmPath,
                    $worker['fpm_configuration_sha256'],
                    262144,
                    "$workerId rendered FPM configuration"
                );
                self::assertPredatesStart(
                    $fpm['stat']['ctime'],
                    $unit['active_enter_epoch'],
                    "$workerId rendered FPM configuration"
                );
                self::assertPredatesStart(
                    $phpIni['stat']['ctime'],
                    $unit['active_enter_epoch'],
                    'installed PHP-FPM ini'
                );
                self::assertFpmConfiguration(
                    $fpm['bytes'],
                    $workerId,
                    $worker['worker_configuration_sha256'],
                    $poolTemplate['bytes']
                );
                $this->pinnedFile(
                    self::FPM_CONFIG_ROOT . "/$workerId.json",
                    $worker['worker_configuration_sha256'],
                    2097152,
                    "$workerId worker configuration"
                );
                $socket = $expectedRoutes[$worker['control_host']];
                $socketStat = self::assertSocketContract(
                    self::SOCKET_ROOT . "/$workerId",
                    $socket,
                    $identities['duo-cloud']['uid'],
                    $identities['duo-cloud']['gid']
                );
                $socketSnapshots[$worker['control_host']] = [
                    'label' => "$workerId FPM socket",
                    'path' => $socket,
                    'stat' => $socketStat,
                ];
                $workers[$workerId] = [
                    'control_host' => $worker['control_host'],
                    'fpm_configuration_sha256' => $worker['fpm_configuration_sha256'],
                    'process' => [
                        'gid' => $identities['duo-cloud']['gid'],
                        'pid' => $unit['pid'],
                        'uid' => $identities['duo-cloud']['uid'],
                    ],
                    'socket' => [
                        'group' => $socketStat['gid'],
                        'inode' => $socketStat['ino'],
                        'mode' => '0660',
                        'owner' => $socketStat['uid'],
                        'path' => $socket,
                    ],
                    'worker_configuration_sha256' => $worker['worker_configuration_sha256'],
                    'worker_id' => $workerId,
                ];
                $this->rememberWorkerSnapshots($workerId, $snapshotLabelsBefore);
            } catch (\Throwable $error) {
                $this->invalidateWorkerReceipt($configuration, $worker);
                $this->rememberWorkerSnapshots($workerId, $snapshotLabelsBefore);
                $this->forgetWorkerSnapshots($workerId);
                $localFailures[] = "$workerId: " . $error->getMessage();
            }
        }

        $adapted = $this->adaptCaddy(self::CADDY_CONFIG);
        self::assertAdaptedCaddy($adapted, $expectedRoutes);
        $liveStatuses = [];
        foreach ($configuration['workers'] as $worker) {
            $workerId = $worker['worker_id'];
            if (!isset($workers[$workerId])) {
                continue;
            }
            $host = $worker['control_host'];
            try {
                $liveStatuses[$host] = $this->probeWorkerRefusal($host);
                self::assertSocketUnchanged(
                    $expectedRoutes[$host],
                    $socketSnapshots[$host]['stat'],
                    $socketSnapshots[$host]['label']
                );
                $workers[$workerId]['live_route_status'] = $liveStatuses[$host];
            } catch (\Throwable $error) {
                $this->invalidateWorkerReceipt($configuration, $worker);
                $this->forgetWorkerSnapshots($workerId);
                unset($workers[$workerId], $workerUnits[$workerId]);
                $localFailures[] = "$workerId: " . $error->getMessage();
            }
        }
        $this->probeEmptyNotFound('unregistered.control.example.invalid', '/');
        $firstHost = array_key_first($expectedRoutes);
        if (!is_string($firstHost)) {
            throw new InstalledFpmIngressRefusal('installed control host set is empty');
        }
        $this->probeEmptyNotFound($firstHost, '/v1/preview/not-reviewed');
        $controlUnitAfter = $this->inspectUnit(
            self::CONTROL_UNIT,
            __DIR__ . '/duo-cloud-control-caddy.service',
            'duo-cloud-edge',
            'duo-cloud-edge',
            'duo-cloud',
            '/usr/bin/caddy',
            $identities
        );
        if ($controlUnitAfter !== $controlUnit) {
            throw new InstalledFpmIngressRefusal(
                'control Caddy unit identity changed during installed proof'
            );
        }
        foreach ($configuration['workers'] as $worker) {
            $workerId = $worker['worker_id'];
            if (!isset($workers[$workerId])) {
                continue;
            }
            try {
                $unitAfter = $this->inspectUnit(
                    "duo-cloud-php-fpm@$workerId.service",
                    __DIR__ . '/duo-cloud-php-fpm@.service',
                    'duo-cloud',
                    'duo-cloud',
                    'docker',
                    '/usr/sbin/php-fpm8.3',
                    $identities
                );
                if ($unitAfter !== $workerUnits[$workerId]) {
                    throw new InstalledFpmIngressRefusal(
                        "$workerId PHP-FPM unit identity changed during installed proof"
                    );
                }
            } catch (\Throwable $error) {
                $this->invalidateWorkerReceipt($configuration, $worker);
                $this->forgetWorkerSnapshots($workerId);
                unset($workers[$workerId]);
                $localFailures[] = "$workerId: " . $error->getMessage();
            }
        }
        $workerConfigurationSha256s = array_column(
            $configuration['workers'],
            'worker_configuration_sha256'
        );
        sort($workerConfigurationSha256s, SORT_STRING);
        $controlProof = [
            'artifact_sha256s' => $artifactSha256s,
            'configuration_sha256' => $options['configuration_sha256'],
            'control_caddy' => [
                'configuration_sha256' => $configuration['control_caddy_sha256'],
                'gid' => $identities['duo-cloud-edge']['gid'],
                'pid' => $controlUnit['pid'],
                'supplementary_socket_gid' => $identities['duo-cloud']['gid'],
                'uid' => $identities['duo-cloud-edge']['uid'],
            ],
            'format' => 'duo-cloud-installed-fpm-ingress-control-proof/v1',
            'max_wall_seconds' => self::MAX_WALL_SECONDS,
            'php_fpm_ini_sha256' => $configuration['php_fpm_ini_sha256'],
            'state' => 'ready',
            'worker_configuration_sha256s' => $workerConfigurationSha256s,
        ];
        $controlProof['proof_receipt_sha256'] = hash(
            'sha256',
            "duo-cloud-installed-fpm-ingress-control-proof/v1\0"
                . self::canonicalJson($controlProof)
        );
        $workerProofs = [];
        foreach ($workers as $worker) {
            $workerProof = $worker + [
                'format' => 'duo-cloud-installed-fpm-ingress-worker-proof/v1',
                'state' => 'ready',
            ];
            $workerProof['proof_receipt_sha256'] = hash(
                'sha256',
                "duo-cloud-installed-fpm-ingress-worker-proof/v1\0"
                    . self::canonicalJson($workerProof)
            );
            $workerProofs[$worker['worker_configuration_sha256']] = $workerProof;
        }
        $this->assertSnapshotsUnchanged();
        foreach ($installedFpmSourceIdentities as $name => $identity) {
            revalidateInstalledFpmProofFile(
                $identity,
                "installed FPM verifier source $name",
                2097152
            );
        }
        if (self::monotonicNow() >= $this->deadline) {
            throw new InstalledFpmIngressRefusal('installed FPM proof exceeded its wall bound');
        }
        \Duo\Cloud\ProductionDeploymentProofs::publishInstalledFpmEvidence(
            $configuration['host_preflight_root'],
            $controlProof,
            $workerProofs,
            $workerConfigurationSha256s,
            $identities['duo-cloud']['uid'],
            $identities['duo-cloud']['gid']
        );
        if (self::monotonicNow() - $started > self::MAX_WALL_SECONDS) {
            throw new InstalledFpmIngressRefusal('installed FPM proof exceeded its wall bound');
        }
        if ($localFailures !== []) {
            throw new InstalledFpmIngressWorkerRefusal(implode('; ', $localFailures));
        }
        $workers = array_values($workers);
        $proof = [
            'artifact_sha256s' => $artifactSha256s,
            'configuration_sha256' => $options['configuration_sha256'],
            'control_caddy' => $controlProof['control_caddy'],
            'format' => self::PROOF_FORMAT,
            'max_wall_seconds' => self::MAX_WALL_SECONDS,
            'php_fpm_ini_sha256' => $configuration['php_fpm_ini_sha256'],
            'state' => 'ready',
            'workers' => $workers,
        ];
        $proof['proof_receipt_sha256'] = hash(
            'sha256',
            "duo-cloud-installed-fpm-ingress-proof-receipt/v1\0" . self::canonicalJson($proof)
        );
        return $proof;
    }

    public static function assertFpmConfiguration(
        string $bytes,
        string $workerId,
        string $workerConfigurationSha256,
        string $template
    ): void {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,31}\z/D', $workerId) !== 1
            || !self::isSha256($workerConfigurationSha256)
            || strlen($bytes) > 262144 || !str_ends_with($bytes, "\n")
            || strlen($template) > 262144 || !str_ends_with($template, "\n")
            || substr_count($template, 'REPLACE_WORKER') < 1
            || substr_count($template, 'REPLACE_WITH_LOWERCASE_SHA256') !== 1) {
            throw new InstalledFpmIngressRefusal('rendered FPM configuration premise is invalid');
        }
        $expected = str_replace(
            ['REPLACE_WORKER', 'REPLACE_WITH_LOWERCASE_SHA256'],
            [$workerId, $workerConfigurationSha256],
            $template
        );
        if ($bytes !== $expected) {
            throw new InstalledFpmIngressRefusal(
                "$workerId rendered FPM configuration differs from the normative template"
            );
        }
    }

    /**
     * @param array{host_preflight_root:string,workers:list<array{control_host:string,fpm_configuration_sha256:string,worker_configuration_sha256:string,worker_id:string}>} $configuration
     * @param array{control_host:string,fpm_configuration_sha256:string,worker_configuration_sha256:string,worker_id:string} $worker
     */
    private function invalidateWorkerReceipt(array $configuration, array $worker): void {
        try {
            \Duo\Cloud\ProductionDeploymentProofs::invalidateInstalledFpmWorker(
                $configuration['host_preflight_root'],
                $worker['worker_configuration_sha256'],
                10001
            );
        } catch (\Throwable $error) {
            throw new InstalledFpmIngressRefusal(
                $worker['worker_id'] . ' failed receipt could not be invalidated exactly',
                0,
                $error
            );
        }
    }

    private function forgetWorkerSnapshots(string $workerId): void {
        foreach ($this->workerSnapshotLabels[$workerId] ?? [] as $label) {
            unset($this->snapshots[$label]);
        }
        unset($this->workerSnapshotLabels[$workerId]);
    }

    /** @param list<string> $labelsBefore */
    private function rememberWorkerSnapshots(string $workerId, array $labelsBefore): void {
        $this->workerSnapshotLabels[$workerId] = array_values(array_diff(
            array_keys($this->snapshots),
            $labelsBefore
        ));
    }

    /**
     * @param array<string,mixed> $document
     * @param array<string,string> $expectedRoutes host => socket
     */
    public static function assertAdaptedCaddy(array $document, array $expectedRoutes): void {
        if ($expectedRoutes === [] || count($expectedRoutes) > self::WORKER_LIMIT
            || ($document['admin'] ?? null) !== ['disabled' => true]
            || !is_array($document['apps'] ?? null)
            || array_keys($document['apps']) !== ['http']) {
            throw new InstalledFpmIngressRefusal('adapted control Caddy boundary is not closed');
        }
        $servers = $document['apps']['http']['servers'] ?? null;
        if (!is_array($servers) || array_is_list($servers) || count($servers) !== 1) {
            throw new InstalledFpmIngressRefusal('adapted control Caddy has an invalid server set');
        }
        $server = reset($servers);
        if (!is_array($server)
            || !self::hasExactKeys($server, [
                'idle_timeout', 'listen', 'read_header_timeout', 'read_timeout', 'routes',
            ])
            || ($server['listen'] ?? null) !== ['127.0.0.1:8081']
            || ($server['read_timeout'] ?? null) !== 10000000000
            || ($server['read_header_timeout'] ?? null) !== 5000000000
            || ($server['idle_timeout'] ?? null) !== 30000000000
            || !is_array($server['routes']) || count($server['routes']) !== 1) {
            throw new InstalledFpmIngressRefusal(
                'adapted control Caddy is not loopback-only on its exact listener'
            );
        }
        $outer = $server['routes'][0];
        if (!is_array($outer) || !self::hasExactKeys($outer, ['handle', 'terminal'])
            || ($outer['terminal'] ?? null) !== true
            || !is_array($outer['handle']) || count($outer['handle']) !== 1
            || !is_array($outer['handle'][0] ?? null)
            || !self::hasExactKeys($outer['handle'][0], ['handler', 'routes'])
            || ($outer['handle'][0]['handler'] ?? null) !== 'subroute'
            || !is_array($outer['handle'][0]['routes'])
            || count($outer['handle'][0]['routes']) !== count($expectedRoutes) + 1) {
            throw new InstalledFpmIngressRefusal(
                'adapted control Caddy outer route topology is not exact'
            );
        }
        $routes = $outer['handle'][0]['routes'];
        $group = null;
        foreach (array_values($expectedRoutes) as $index => $socket) {
            $host = array_keys($expectedRoutes)[$index];
            $route = $routes[$index] ?? null;
            $match = $route['match'] ?? null;
            if (!is_array($route) || !self::hasExactKeys($route, ['group', 'handle', 'match'])
                || !is_string($route['group'] ?? null)
                || preg_match('/\Agroup[1-9][0-9]*\z/D', $route['group']) !== 1
                || ($group !== null && $route['group'] !== $group)
                || !is_array($match) || count($match) !== 1
                || !is_array($match[0] ?? null)
                || !self::hasExactKeys($match[0], ['host', 'path'])
                || ($match[0]['host'] ?? null) !== [$host]
                || ($match[0]['path'] ?? null) !== self::CONTROL_PATHS
                || !is_array($route['handle'] ?? null) || count($route['handle']) !== 1
                || !is_array($route['handle'][0] ?? null)
                || !self::hasExactKeys($route['handle'][0], ['handler', 'routes'])
                || ($route['handle'][0]['handler'] ?? null) !== 'subroute'
                || !is_array($route['handle'][0]['routes'])
                || count($route['handle'][0]['routes']) !== 1) {
                throw new InstalledFpmIngressRefusal(
                    'adapted control Caddy host matcher or subroute is not exact and closed'
                );
            }
            $group = $route['group'];
            $expectedProxy = [
                'handler' => 'reverse_proxy',
                'transport' => [
                    'env' => [
                        'SCRIPT_FILENAME' => '/opt/duo-cloud/bin/duo-cloud-http',
                        'SCRIPT_NAME' => '/duo-cloud-http',
                    ],
                    'protocol' => 'fastcgi',
                    'root' => '/opt/duo-cloud',
                ],
                'upstreams' => [[
                    'dial' => 'unix/' . $socket,
                ]],
            ];
            $inner = $route['handle'][0]['routes'][0];
            if (!is_array($inner) || !self::hasExactKeys($inner, ['handle'])
                || !is_array($inner['handle'])
                || self::canonicalJson($inner['handle']) !== self::canonicalJson([
                    ['handler' => 'request_body', 'max_size' => 2097152],
                    $expectedProxy,
                ])) {
                throw new InstalledFpmIngressRefusal(
                    'adapted control Caddy host has an additive handler or wrong worker socket'
                );
            }
        }
        $fallback = $routes[count($expectedRoutes)] ?? null;
        $expectedFallback = [
            'group' => $group,
            'handle' => [[
                'handler' => 'subroute',
                'routes' => [[
                    'handle' => [[
                        'handler' => 'static_response',
                        'status_code' => 404,
                    ]],
                ]],
            ]],
        ];
        if (self::canonicalJson($fallback) !== self::canonicalJson($expectedFallback)) {
            throw new InstalledFpmIngressRefusal(
                'adapted control Caddy fallback has an additive handler or is not exact empty 404'
            );
        }
    }

    /** @return array<string,string> */
    public static function parseUnitProperties(string $bytes): array {
        if ($bytes === '' || strlen($bytes) > 65536 || !str_ends_with($bytes, "\n")) {
            throw new InstalledFpmIngressRefusal('systemd unit properties are empty or oversized');
        }
        $properties = [];
        foreach (explode("\n", rtrim($bytes, "\n")) as $line) {
            $pair = explode('=', $line, 2);
            if (count($pair) !== 2 || preg_match('/\A[A-Za-z][A-Za-z0-9]*\z/D', $pair[0]) !== 1
                || isset($properties[$pair[0]])) {
                throw new InstalledFpmIngressRefusal('systemd unit properties are malformed');
            }
            $properties[$pair[0]] = $pair[1];
        }
        return $properties;
    }

    /** @param array<string,string> $properties */
    public static function assertUnitExecutionBinding(
        array $properties,
        string $executable
    ): int {
        if (!isset($properties['MainPID'], $properties['ExecMainPID'], $properties['ExecStart'])
            || preg_match('/\A[1-9][0-9]*\z/D', $properties['MainPID']) !== 1
            || preg_match('/\A[1-9][0-9]*\z/D', $properties['ExecMainPID']) !== 1
            || $properties['ExecMainPID'] !== $properties['MainPID']) {
            throw new InstalledFpmIngressRefusal(
                'active systemd MainPID and ExecMainPID are invalid or disagree'
            );
        }
        $pid = (int) $properties['MainPID'];
        $matches = [];
        if (substr_count($properties['ExecStart'], '{ path=') !== 1
            || !str_contains($properties['ExecStart'], "{ path=$executable ;")
            || !str_contains($properties['ExecStart'], "argv[]=$executable ")
            || preg_match_all('/; pid=([0-9]+) ;/', $properties['ExecStart'], $matches) !== 1
            || !isset($matches[1][0])
            || ((int) $matches[1][0] !== 0 && (int) $matches[1][0] !== $pid)) {
            throw new InstalledFpmIngressRefusal(
                'active systemd ExecStart does not bind its configured executable'
            );
        }
        return $pid;
    }

    public static function assertProcessIdentity(
        string $status,
        int $uid,
        int $gid,
        int $supplementaryGid
    ): void {
        $fields = [];
        foreach (explode("\n", $status) as $line) {
            if (preg_match('/\A(Uid|Gid|Groups):\s*(.*)\z/D', $line, $match) === 1) {
                if (isset($fields[$match[1]])) {
                    throw new InstalledFpmIngressRefusal('process identity repeats a status field');
                }
                $fields[$match[1]] = preg_split('/\s+/', trim($match[2]));
            }
        }
        $actualGroups = is_array($fields['Groups'] ?? null)
            ? array_values(array_unique(array_map('intval', $fields['Groups'])))
            : [];
        sort($actualGroups, SORT_NUMERIC);
        $expectedGroups = array_values(array_unique([$gid, $supplementaryGid]));
        sort($expectedGroups, SORT_NUMERIC);
        if (!is_array($fields['Uid'] ?? null) || count($fields['Uid']) !== 4
            || array_map('intval', $fields['Uid']) !== [$uid, $uid, $uid, $uid]
            || !is_array($fields['Gid'] ?? null) || count($fields['Gid']) !== 4
            || array_map('intval', $fields['Gid']) !== [$gid, $gid, $gid, $gid]
            || $actualGroups !== $expectedGroups) {
            throw new InstalledFpmIngressRefusal(
                'active process UID, GID, or supplementary socket group is invalid'
            );
        }
    }

    /**
     * @param array{
     *   duo-cloud:array{gid:int,uid:int},
     *   duo-cloud-edge:array{gid:int,uid:int},
     *   docker:array{gid:int,uid:int}
     * } $identities
     */
    public static function assertServiceIdentityContract(array $identities): void {
        if (($identities['duo-cloud']['uid'] ?? null) !== 10001
            || ($identities['duo-cloud']['gid'] ?? null) !== 10001
            || !is_int($identities['duo-cloud-edge']['uid'] ?? null)
            || !is_int($identities['duo-cloud-edge']['gid'] ?? null)
            || $identities['duo-cloud-edge']['uid'] < 1
            || $identities['duo-cloud-edge']['gid'] < 1
            || $identities['duo-cloud-edge']['uid'] === 10001
            || $identities['duo-cloud-edge']['gid'] === 10001
            || !is_int($identities['docker']['gid'] ?? null)
            || $identities['docker']['gid'] < 1
            || $identities['docker']['gid'] === 10001
            || $identities['docker']['gid'] === $identities['duo-cloud-edge']['gid']) {
            throw new InstalledFpmIngressRefusal(
                'installed service, edge, and Docker numeric identities are not fixed and disjoint'
            );
        }
    }

    /** @param array<string,mixed> $expectedStat @param array<string,mixed> $actualStat */
    public static function assertExecutableIdentity(
        string $expectedPath,
        string $actualTarget,
        array $expectedStat,
        array $actualStat
    ): void {
        if ($actualTarget !== $expectedPath
            || ((int) ($expectedStat['mode'] ?? 0) & 0170000) !== 0100000
            || ((int) ($actualStat['mode'] ?? 0) & 0170000) !== 0100000
            || (int) ($actualStat['dev'] ?? -1) !== (int) ($expectedStat['dev'] ?? -2)
            || (int) ($actualStat['ino'] ?? -1) !== (int) ($expectedStat['ino'] ?? -2)) {
            throw new InstalledFpmIngressRefusal(
                'active systemd MainPID executable target, device, or inode is invalid'
            );
        }
    }

    public static function canonicalJson(mixed $value): string {
        $normalize = static function (mixed $candidate) use (&$normalize): mixed {
            if (!is_array($candidate)) {
                return $candidate;
            }
            if (array_is_list($candidate)) {
                return array_map($normalize, $candidate);
            }
            ksort($candidate, SORT_STRING);
            foreach ($candidate as $key => $item) {
                $candidate[$key] = $normalize($item);
            }
            return $candidate;
        };
        try {
            return json_encode(
                $normalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            );
        } catch (\Throwable $error) {
            throw new InstalledFpmIngressRefusal('installed proof evidence is not JSON', 0, $error);
        }
    }

    private function assertInstalledPremises(): void {
        if (PHP_OS_FAMILY !== 'Linux' || !is_dir('/run/systemd/system')) {
            throw new InstalledFpmIngressRefusal(
                'installed FPM proof requires the production Linux systemd host'
            );
        }
        foreach (['posix_geteuid', 'posix_getgrnam', 'posix_getpwnam'] as $function) {
            if (!function_exists($function)) {
                throw new InstalledFpmIngressRefusal(
                    'installed FPM proof requires POSIX identity primitives'
                );
            }
        }
        if (posix_geteuid() !== 0) {
            throw new InstalledFpmIngressRefusal(
                'installed FPM proof must run only as its root systemd proof service'
            );
        }
    }

    /**
     * @return array{
     *   duo-cloud:array{gid:int,uid:int},
     *   duo-cloud-edge:array{gid:int,uid:int},
     *   docker:array{gid:int,uid:int}
     * }
     */
    private function serviceIdentities(): array {
        $result = [];
        foreach (['duo-cloud', 'duo-cloud-edge'] as $name) {
            $user = posix_getpwnam($name);
            $group = posix_getgrnam($name);
            if (!is_array($user) || !is_array($group)
                || !is_int($user['uid'] ?? null) || $user['uid'] < 1
                || !is_int($user['gid'] ?? null) || $user['gid'] !== $group['gid']) {
                throw new InstalledFpmIngressRefusal("installed $name identity is invalid");
            }
            $result[$name] = ['gid' => $group['gid'], 'uid' => $user['uid']];
        }
        $docker = posix_getgrnam('docker');
        if (!is_array($docker) || !is_int($docker['gid'] ?? null) || $docker['gid'] < 1) {
            throw new InstalledFpmIngressRefusal('installed docker group is invalid');
        }
        $result['docker'] = ['gid' => $docker['gid'], 'uid' => 0];
        self::assertServiceIdentityContract($result);
        /** @var array{duo-cloud:array{gid:int,uid:int},duo-cloud-edge:array{gid:int,uid:int},docker:array{gid:int,uid:int}} $result */
        return $result;
    }

    /**
     * @param array{duo-cloud:array{gid:int,uid:int},duo-cloud-edge:array{gid:int,uid:int},docker:array{gid:int,uid:int}} $identities
     * @return array{active_enter_epoch:int,pid:int}
     */
    private function inspectUnit(
        string $unit,
        string $canonicalFragment,
        string $user,
        string $group,
        string $supplementaryGroup,
        string $executable,
        array $identities
    ): array {
        if (preg_match('/\Aduo-cloud-(?:control-caddy|php-fpm@[a-z0-9][a-z0-9-]{0,31})\.service\z/D', $unit) !== 1) {
            throw new InstalledFpmIngressRefusal('installed systemd unit name is invalid');
        }
        $systemctl = $this->executable('/usr/bin/systemctl', 'systemctl');
        $result = $this->command([
            $systemctl,
            'show',
            '--no-pager',
            '--property=LoadState',
            '--property=ActiveState',
            '--property=SubState',
            '--property=FragmentPath',
            '--property=DropInPaths',
            '--property=NeedDaemonReload',
            '--property=MainPID',
            '--property=ExecMainPID',
            '--property=ActiveEnterTimestamp',
            '--property=ExecStart',
            '--property=User',
            '--property=Group',
            '--property=SupplementaryGroups',
            '--property=UMask',
            $unit,
        ], 5);
        if ($result['exit'] !== 0 || $result['stderr'] !== '') {
            throw new InstalledFpmIngressRefusal("$unit systemd inspection refused");
        }
        $properties = self::parseUnitProperties($result['stdout']);
        if (!self::hasExactKeys($properties, [
            'ActiveEnterTimestamp', 'ActiveState', 'DropInPaths', 'FragmentPath',
            'ExecMainPID', 'ExecStart', 'Group', 'LoadState', 'MainPID',
            'NeedDaemonReload', 'SubState', 'SupplementaryGroups', 'UMask', 'User',
        ]) || $properties['LoadState'] !== 'loaded'
            || $properties['ActiveState'] !== 'active'
            || $properties['SubState'] !== 'running'
            || $properties['DropInPaths'] !== ''
            || $properties['NeedDaemonReload'] !== 'no'
            || $properties['User'] !== $user || $properties['Group'] !== $group
            || $properties['SupplementaryGroups'] !== $supplementaryGroup
            || $properties['UMask'] !== '0077') {
            throw new InstalledFpmIngressRefusal(
                "$unit is inactive, overridden, stale, or running with the wrong identity"
            );
        }
        $pid = self::assertUnitExecutionBinding($properties, $executable);
        $started = strtotime($properties['ActiveEnterTimestamp']);
        if (!is_int($started) || $started < 1 || $started > time() + 1) {
            throw new InstalledFpmIngressRefusal("$unit active timestamp is invalid");
        }
        $expectedFragment = $this->unpinnedFile(
            $canonicalFragment,
            262144,
            "$unit shipped fragment"
        );
        $fragment = $this->pinnedFile(
            $properties['FragmentPath'],
            $expectedFragment['sha256'],
            262144,
            "$unit loaded fragment"
        );
        self::assertPredatesStart(
            $fragment['stat']['ctime'],
            $started,
            "$unit loaded fragment"
        );
        $status = @file_get_contents("/proc/$pid/status");
        if (!is_string($status) || strlen($status) > 262144) {
            throw new InstalledFpmIngressRefusal("$unit process status is unavailable");
        }
        self::assertProcessIdentity(
            $status,
            $identities[$user]['uid'],
            $identities[$group]['gid'],
            $identities[$supplementaryGroup]['gid']
        );
        $expectedExecutable = $this->executable($executable, "$unit executable");
        $executableStat = @lstat($expectedExecutable);
        $actualExecutable = @readlink("/proc/$pid/exe");
        $actualExecutableStat = @stat("/proc/$pid/exe");
        if (!is_array($executableStat) || !is_string($actualExecutable)
            || !is_array($actualExecutableStat)) {
            throw new InstalledFpmIngressRefusal("$unit executable identity is unavailable");
        }
        self::assertExecutableIdentity(
            $expectedExecutable,
            $actualExecutable,
            $executableStat,
            $actualExecutableStat
        );
        self::assertPredatesStart(
            (int) $executableStat['ctime'],
            $started,
            "$unit executable"
        );
        return ['active_enter_epoch' => $started, 'pid' => $pid];
    }

    /** @return array{bytes:string,path:string,sha256:string,stat:array<string,int>} */
    private function pinnedFile(
        string $path,
        string $expectedSha256,
        int $limit,
        string $label
    ): array {
        if (!self::isSha256($expectedSha256)) {
            throw new InstalledFpmIngressRefusal("$label pin is invalid");
        }
        $file = $this->unpinnedFile($path, $limit, $label);
        if (!hash_equals($expectedSha256, $file['sha256'])) {
            throw new InstalledFpmIngressRefusal("$label does not match its SHA-256 pin");
        }
        return $file;
    }

    /** @return array{bytes:string,path:string,sha256:string,stat:array<string,int>} */
    private function unpinnedFile(string $path, int $limit, string $label): array {
        if (!str_starts_with($path, '/') || str_contains($path, "\0")
            || realpath($path) !== $path || is_link($path)) {
            throw new InstalledFpmIngressRefusal("$label path is not canonical");
        }
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || ($before['mode'] & 0170000) !== 0100000
            || (int) $before['uid'] !== 0 || ($before['mode'] & 0022) !== 0
            || (int) $before['size'] < 1 || (int) $before['size'] > $limit) {
            throw new InstalledFpmIngressRefusal("$label is not a protected root-owned regular file");
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new InstalledFpmIngressRefusal("$label could not be opened");
        }
        try {
            $opened = fstat($handle);
            $bytes = stream_get_contents($handle, $limit + 1);
        } finally {
            fclose($handle);
        }
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_array($opened) || !is_string($bytes) || strlen($bytes) > $limit
            || !is_array($after) || !self::sameFile($before, $opened)
            || !self::sameFile($before, $after)) {
            throw new InstalledFpmIngressRefusal("$label changed while it was read");
        }
        $stat = self::statIdentity($before);
        $sha256 = hash('sha256', $bytes);
        $this->snapshots[$label] = ['path' => $path, 'sha256' => $sha256, 'stat' => $stat];
        return ['bytes' => $bytes, 'path' => $path, 'sha256' => $sha256, 'stat' => $stat];
    }

    private function executable(string $path, string $label): string {
        $canonical = realpath($path);
        if (!is_string($canonical) || !is_file($canonical) || !is_executable($canonical)) {
            throw new InstalledFpmIngressRefusal("$label is not an installed executable");
        }
        if (isset($this->executableCache[$canonical])) {
            return $this->executableCache[$canonical];
        }
        $file = $this->unpinnedFile($canonical, 134217728, $label);
        $this->executableCache[$canonical] = $file['path'];
        return $file['path'];
    }

    /** @return array{exit:int,stderr:string,stdout:string} */
    private function command(array $argv, int $timeoutSeconds): array {
        $process = @proc_open(
            $argv,
            [
                0 => ['file', '/dev/null', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            null,
            [
                'HOME' => '/nonexistent',
                'LC_ALL' => 'C',
                'LANG' => 'C',
                'PATH' => '/usr/sbin:/usr/bin:/sbin:/bin',
            ],
            ['bypass_shell' => true]
        );
        if (!is_resource($process) || !is_resource($pipes[1] ?? null)
            || !is_resource($pipes[2] ?? null)) {
            throw new InstalledFpmIngressRefusal('installed proof child process could not start');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $deadline = min(self::monotonicNow() + $timeoutSeconds, $this->deadline);
        $exit = -1;
        $timedOut = false;
        while (true) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            if (is_string($out)) {
                $stdout .= $out;
            }
            if (is_string($err)) {
                $stderr .= $err;
            }
            if (strlen($stdout) > self::OUTPUT_LIMIT || strlen($stderr) > self::OUTPUT_LIMIT) {
                @proc_terminate($process, 9);
                $timedOut = true;
                break;
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $exit = $status['exitcode'];
                break;
            }
            if (self::monotonicNow() >= $deadline) {
                @proc_terminate($process, 15);
                usleep(100000);
                $status = proc_get_status($process);
                if ($status['running']) {
                    @proc_terminate($process, 9);
                }
                $timedOut = true;
                break;
            }
            usleep(10000);
        }
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        if (is_string($out)) {
            $stdout .= $out;
        }
        if (is_string($err)) {
            $stderr .= $err;
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        if ($exit < 0) {
            $exit = $closed;
        }
        if ($timedOut) {
            throw new InstalledFpmIngressRefusal('installed proof child process exceeded its bound');
        }
        return ['exit' => $exit, 'stderr' => $stderr, 'stdout' => $stdout];
    }

    /** @return array<string,mixed> */
    private function adaptCaddy(string $configuration): array {
        $caddy = $this->executable('/usr/bin/caddy', 'Caddy');
        $result = $this->command([
            $caddy, 'adapt', '--config', $configuration, '--adapter', 'caddyfile',
        ], 5);
        if ($result['exit'] !== 0 || $result['stderr'] !== ''
            || $result['stdout'] === '' || strlen($result['stdout']) > self::OUTPUT_LIMIT) {
            throw new InstalledFpmIngressRefusal('installed control Caddy configuration did not adapt cleanly');
        }
        try {
            $document = json_decode($result['stdout'], true, 128, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new InstalledFpmIngressRefusal('adapted control Caddy output is not JSON', 0, $error);
        }
        if (!is_array($document) || array_is_list($document)) {
            throw new InstalledFpmIngressRefusal('adapted control Caddy output is not an object');
        }
        return $document;
    }

    /**
     * @return array{dev:int,gid:int,ino:int,mode:int,mtime:int,ctime:int,size:int,uid:int}
     */
    private static function assertSocketContract(
        string $directory,
        string $socket,
        int $uid,
        int $gid
    ): array {
        clearstatcache(true, $directory);
        $directoryStat = @lstat($directory);
        if (!is_array($directoryStat) || @filetype($directory) !== 'dir'
            || ($directoryStat['mode'] & 0777) !== 0751
            || (int) $directoryStat['uid'] !== $uid || (int) $directoryStat['gid'] !== $gid
            || dirname($directory) !== self::SOCKET_ROOT) {
            throw new InstalledFpmIngressRefusal(
                'installed worker runtime directory owner, group, mode, or path is invalid'
            );
        }
        clearstatcache(true, $socket);
        $socketStat = @lstat($socket);
        if (!is_array($socketStat) || @filetype($socket) !== 'socket'
            || ($socketStat['mode'] & 0777) !== 0660
            || (int) $socketStat['uid'] !== $uid || (int) $socketStat['gid'] !== $gid
            || dirname($socket) !== $directory) {
            throw new InstalledFpmIngressRefusal(
                'installed FPM socket owner, group, mode, type, or path is invalid'
            );
        }
        return self::statIdentity($socketStat);
    }

    /** @param array<string,int> $expected */
    private static function assertSocketUnchanged(string $socket, array $expected, string $label): void {
        clearstatcache(true, $socket);
        $actual = @lstat($socket);
        if (!is_array($actual) || !self::sameFile($expected, $actual)
            || @filetype($socket) !== 'socket') {
            throw new InstalledFpmIngressRefusal("$label changed during the edge access proof");
        }
    }

    private function probeWorkerRefusal(string $host): int {
        if (preg_match('/\A[a-z0-9.-]+\z/D', $host) !== 1) {
            throw new InstalledFpmIngressRefusal('control-edge worker probe host is invalid');
        }
        $body = '{}';
        $request = "POST /v1/preview/control HTTP/1.1\r\n"
            . "Host: $host\r\n"
            . "Content-Type: application/json\r\n"
            . 'Content-Length: ' . strlen($body) . "\r\n"
            . "Connection: close\r\n\r\n$body";
        $response = $this->loopbackRequest($request);
        $parts = explode("\r\n\r\n", $response, 2);
        $status = null;
        if (count($parts) === 2
            && preg_match('/\AHTTP\/1\.[01] (403|503)(?: [^\r\n]*)?\r\n/D', $parts[0] . "\r\n", $match) === 1) {
            $status = (int) $match[1];
        }
        $expectedBody = match ($status) {
            403 => "{\"error\":\"request_refused\"}\n",
            503 => "{\"error\":\"service_unavailable\"}\n",
            default => null,
        };
        $headers = self::responseHeaders($parts[0] ?? '');
        if (!is_string($expectedBody) || ($parts[1] ?? null) !== $expectedBody
            || ($headers['content-type'] ?? null) !== 'application/json'
            || ($headers['content-length'] ?? null) !== (string) strlen($expectedBody)
            || ($headers['cache-control'] ?? null) !== 'no-store'
            || ($headers['x-content-type-options'] ?? null) !== 'nosniff'
            || isset($headers['x-powered-by'])) {
            throw new InstalledFpmIngressRefusal(
                'live control host did not reach its FPM worker and exact Duo refusal boundary'
            );
        }
        return $status;
    }

    private function probeEmptyNotFound(string $host, string $path): void {
        if (preg_match('/\A[a-z0-9.-]+\z/D', $host) !== 1
            || preg_match('#\A/[A-Za-z0-9._/-]*\z#D', $path) !== 1) {
            throw new InstalledFpmIngressRefusal('control-edge 404 probe input is invalid');
        }
        $request = "GET $path HTTP/1.1\r\nHost: $host\r\nConnection: close\r\n\r\n";
        $response = $this->loopbackRequest($request);
        $parts = explode("\r\n\r\n", $response, 2);
        if (count($parts) !== 2
            || preg_match('/\AHTTP\/1\.[01] 404(?: [^\r\n]*)?\r\n/D', $parts[0] . "\r\n") !== 1
            || $parts[1] !== '') {
            throw new InstalledFpmIngressRefusal(
                'installed control edge did not return its exact empty 404 fallback'
            );
        }
    }

    private function loopbackRequest(string $request): string {
        $remaining = $this->deadline - self::monotonicNow();
        if ($remaining <= 0) {
            throw new InstalledFpmIngressRefusal('installed FPM proof exceeded its wall bound');
        }
        $timeout = min(2.0, $remaining);
        $errorCode = 0;
        $errorMessage = '';
        $client = @stream_socket_client(
            'tcp://127.0.0.1:8081',
            $errorCode,
            $errorMessage,
            $timeout,
            STREAM_CLIENT_CONNECT
        );
        if (!is_resource($client)) {
            throw new InstalledFpmIngressRefusal('installed control edge is not reachable on loopback');
        }
        try {
            $seconds = (int) floor($timeout);
            $microseconds = (int) (($timeout - $seconds) * 1000000);
            stream_set_timeout($client, $seconds, $microseconds);
            if (fwrite($client, $request) !== strlen($request)) {
                throw new InstalledFpmIngressRefusal('installed control-edge probe could not be sent');
            }
            $response = stream_get_contents($client, 65537);
            $metadata = stream_get_meta_data($client);
        } finally {
            fclose($client);
        }
        if (!is_string($response) || strlen($response) > 65536
            || ($metadata['timed_out'] ?? true) !== false) {
            throw new InstalledFpmIngressRefusal('installed control-edge probe was incomplete');
        }
        return $response;
    }

    /** @return array<string,string> */
    private static function responseHeaders(string $bytes): array {
        $lines = explode("\r\n", $bytes);
        array_shift($lines);
        $headers = [];
        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $pair = explode(':', $line, 2);
            $name = strtolower(trim($pair[0] ?? ''));
            $value = trim($pair[1] ?? '');
            if ($name === '' || $value === '' || isset($headers[$name])) {
                throw new InstalledFpmIngressRefusal(
                    'installed control-edge response headers are malformed or repeated'
                );
            }
            $headers[$name] = $value;
        }
        return $headers;
    }

    private function assertSnapshotsUnchanged(): void {
        foreach ($this->snapshots as $label => $snapshot) {
            if (self::monotonicNow() >= $this->deadline) {
                throw new InstalledFpmIngressRefusal('installed FPM proof exceeded its wall bound');
            }
            clearstatcache(true, $snapshot['path']);
            $stat = @lstat($snapshot['path']);
            $sha256 = @hash_file('sha256', $snapshot['path']);
            if (!is_array($stat) || !is_string($sha256)
                || !self::sameFile($snapshot['stat'], $stat)
                || !hash_equals($snapshot['sha256'], $sha256)) {
                throw new InstalledFpmIngressRefusal("$label changed during installed proof");
            }
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function hasExactKeys(array $value, array $expected): bool {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        sort($expected, SORT_STRING);
        return $keys === $expected;
    }

    private static function assertPredatesStart(int $ctime, int $started, string $label): void {
        if ($ctime < 1 || $ctime > $started) {
            throw new InstalledFpmIngressRefusal(
                "$label changed after its active service started; restart before proof"
            );
        }
    }

    private static function isSha256(mixed $value): bool {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/D', $value) === 1;
    }

    private static function isAbsolutePath(mixed $value): bool {
        return is_string($value) && $value !== '' && $value[0] === '/'
            && !str_contains($value, "\0") && !str_contains($value, '//')
            && !str_ends_with($value, '/')
            && preg_match('#(?:^|/)\.\.?(/|$)#D', $value) !== 1;
    }

    /** @param array<string,mixed> $stat @return array<string,int> */
    private static function statIdentity(array $stat): array {
        return [
            'dev' => (int) $stat['dev'],
            'gid' => (int) $stat['gid'],
            'ino' => (int) $stat['ino'],
            'mode' => (int) $stat['mode'],
            'mtime' => (int) $stat['mtime'],
            'ctime' => (int) $stat['ctime'],
            'nlink' => (int) $stat['nlink'],
            'size' => (int) $stat['size'],
            'uid' => (int) $stat['uid'],
        ];
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function sameFile(array $left, array $right): bool {
        foreach (['dev', 'gid', 'ino', 'mode', 'mtime', 'ctime', 'nlink', 'size', 'uid'] as $key) {
            if ((int) ($left[$key] ?? -1) !== (int) ($right[$key] ?? -2)) {
                return false;
            }
        }
        return true;
    }

    private static function monotonicNow(): float {
        return hrtime(true) / 1_000_000_000;
    }
}

/** @param list<string> $arguments */
function installedFpmIngressMain(array $arguments): int {
    $options = null;
    try {
        if (@ini_set('pcre.jit', '0') === false || ini_get('pcre.jit') !== '0') {
            throw new InstalledFpmIngressRefusal('installed proof could not disable PCRE JIT');
        }
        $options = InstalledFpmIngressVerifier::parseArguments($arguments);
        $output = $options['contract']
            ? InstalledFpmIngressVerifier::contract()
            : (new InstalledFpmIngressVerifier())->run($options);
        fwrite(STDOUT, InstalledFpmIngressVerifier::canonicalJson($output) . "\n");
        return 0;
    } catch (\Throwable $error) {
        if (!$error instanceof InstalledFpmIngressWorkerRefusal
            && is_array($options) && ($options['contract'] ?? true) === false
            && is_string($options['host_preflight_root'] ?? null)) {
            try {
                \Duo\Cloud\ProductionDeploymentProofs::invalidateInstalledFpm(
                    $options['host_preflight_root'],
                    10001
                );
            } catch (\Throwable) {
                // The short receipt expiry is the terminal bound when an
                // already-failing invocation cannot remove the old inode.
            }
        }
        fwrite(
            STDERR,
            InstalledFpmIngressVerifier::PROOF_FORMAT . ' refusal: ' . $error->getMessage() . "\n"
        );
        return 70;
    }
}

$script = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($script) && realpath($script) === __FILE__) {
    $arguments = $_SERVER['argv'] ?? [];
    if (!is_array($arguments)) {
        $arguments = [];
    }
    $arguments = array_values(array_filter(
        array_slice($arguments, 1),
        static fn (mixed $argument): bool => is_string($argument)
    ));
    exit(installedFpmIngressMain($arguments));
}
