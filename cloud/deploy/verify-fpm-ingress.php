#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace Duo\Cloud\Deploy;

/** A closed proof must distinguish an unproved premise from a failed route. */
final class FpmIngressRefusal extends \RuntimeException {
}

/**
 * Disposable two-worker proof of the production control-edge topology.
 *
 * This deliberately starts two independent FPM masters: sharing one master
 * would not exercise the per-worker service and socket boundary installed by
 * duo-cloud-php-fpm@.service. Every process is launched directly, recorded by
 * its proc handle, bounded, and stopped before its private tree is removed.
 */
final class FpmIngressVerifier {
    public const CONTRACT_FORMAT = 'duo-cloud-fpm-ingress-verifier-contract/v1';
    public const PROOF_FORMAT = 'duo-cloud-fpm-ingress-proof/v1';
    public const WORKER_FORMAT = 'duo-cloud-fpm-ingress-worker/v1';
    public const MAX_WALL_SECONDS = 40;

    private const ACTIVE_DEADLINE_SECONDS = 27;
    private const CLEANUP_DEADLINE_SECONDS = 10;
    private const OUTPUT_LIMIT = 1048576;

    /** @var array<string,resource> */
    private array $processes = [];
    /** @var array<string,string> */
    private array $logs = [];
    /** @var list<string> */
    private array $socketPaths = [];
    private bool $green = false;
    private ?string $root = null;
    private ?string $rootBase = null;

    /** @return array<string,mixed> */
    public static function contract(): array {
        return [
            'format' => self::CONTRACT_FORMAT,
            'max_wall_seconds' => self::MAX_WALL_SECONDS,
            'premises' => [
                'caddy-v2',
                'curl',
                'non-root-posix-identity',
                'php-fpm-8.3-or-newer',
            ],
            'probes' => [
                'distinct-worker-config-digests',
                'host-a-only-worker-a',
                'host-b-only-worker-b',
                'known-host-unknown-path-exact-404',
                'no-cross-marker-or-source-disclosure',
                'socket-directory-owner-group-mode-traversal',
                'unknown-host-exact-404',
            ],
            'state' => 'described',
        ];
    }

    /**
     * @param list<string> $arguments
     * @return array{caddy:?string,contract:bool,curl:?string,php_fpm:?string}
     */
    public static function parseArguments(array $arguments): array {
        $parsed = [
            'caddy' => null,
            'contract' => false,
            'curl' => null,
            'php_fpm' => null,
        ];
        foreach ($arguments as $argument) {
            if ($argument === '--contract') {
                if ($parsed['contract']) {
                    throw new FpmIngressRefusal('duplicate --contract option');
                }
                $parsed['contract'] = true;
                continue;
            }
            $matched = false;
            foreach (['caddy', 'curl', 'php-fpm'] as $option) {
                $prefix = '--' . $option . '=';
                if (!str_starts_with($argument, $prefix)) {
                    continue;
                }
                $key = str_replace('-', '_', $option);
                if ($parsed[$key] !== null) {
                    throw new FpmIngressRefusal("duplicate --$option option");
                }
                $value = substr($argument, strlen($prefix));
                if ($value === '') {
                    throw new FpmIngressRefusal("--$option requires an absolute executable path");
                }
                $parsed[$key] = $value;
                $matched = true;
                break;
            }
            if (!$matched) {
                throw new FpmIngressRefusal('unknown verifier option');
            }
        }
        if ($parsed['contract'] && array_filter([
            $parsed['caddy'], $parsed['curl'], $parsed['php_fpm'],
        ], static fn (?string $value): bool => $value !== null) !== []) {
            throw new FpmIngressRefusal('--contract cannot be combined with executable options');
        }
        return $parsed;
    }

    /** @param array{caddy:?string,contract:bool,curl:?string,php_fpm:?string} $options @return array<string,mixed> */
    public function run(array $options): array {
        $this->assertPosixIdentity();
        $activeDeadline = self::monotonicNow() + self::ACTIVE_DEADLINE_SECONDS;
        $primary = null;
        $evidence = null;
        try {
            $executables = [
                'caddy' => $this->resolveExecutable($options['caddy'], ['caddy']),
                'curl' => $this->resolveExecutable($options['curl'], ['curl']),
                'php_fpm' => $this->resolveExecutable(
                    $options['php_fpm'],
                    ['php-fpm8.3', 'php-fpm']
                ),
            ];
            $binaries = $this->binaryEvidence($executables, $activeDeadline);
            $phpIni = realpath(__DIR__ . '/php-fpm.ini');
            $phpIniSha256 = is_string($phpIni) ? hash_file('sha256', $phpIni) : false;
            if (!is_string($phpIni) || !is_file($phpIni) || !is_string($phpIniSha256)) {
                throw new FpmIngressRefusal('shipped PHP-FPM ini premise is unavailable');
            }
            $this->root = $this->createRoot();
            $uid = posix_geteuid();
            $gid = posix_getegid();
            $user = posix_getpwuid($uid);
            $group = posix_getgrgid($gid);
            if (!is_array($user) || !is_string($user['name'] ?? null)
                || preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]*\z/D', $user['name']) !== 1
                || !is_array($group) || !is_string($group['name'] ?? null)
                || preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]*\z/D', $group['name']) !== 1) {
                throw new FpmIngressRefusal('invoking POSIX user or group name is not config-safe');
            }
            $paths = $this->prepareTree($uid, $gid);
            $this->socketPaths = [$paths['socket_site-a'], $paths['socket_site-b']];
            $markers = [
                'site-a' => bin2hex(random_bytes(32)),
                'site-b' => bin2hex(random_bytes(32)),
            ];
            $hosts = [
                'site-a' => 'site-a.control.example.invalid',
                'site-b' => 'site-b.control.example.invalid',
            ];
            $configurationDigests = [];
            foreach (['site-a', 'site-b'] as $worker) {
                $configuration = self::canonicalJson([
                    'format' => 'duo-cloud-fpm-ingress-worker-config/v1',
                    'host' => $hosts[$worker],
                    'marker' => $markers[$worker],
                    'worker' => $worker,
                ]) . "\n";
                $this->writeExclusive($paths["config_$worker"], $configuration, 0400);
                $configurationDigests[$worker] = hash('sha256', $configuration);
                $fpm = self::fpmConfig(
                    $worker,
                    $user['name'],
                    $group['name'],
                    $paths["socket_$worker"],
                    $paths["pid_$worker"],
                    $paths["fpm_log_$worker"],
                    $paths["config_$worker"],
                    $configurationDigests[$worker]
                );
                $this->writeExclusive($paths["fpm_conf_$worker"], $fpm, 0400);
                $this->logs["fpm-authority-$worker"] = $paths["fpm_log_$worker"];
            }
            if (hash_equals($markers['site-a'], $markers['site-b'])
                || hash_equals($configurationDigests['site-a'], $configurationDigests['site-b'])) {
                throw new FpmIngressRefusal('worker authorities did not receive distinct identities');
            }
            $script = self::workerScript();
            if (!str_starts_with($script, "<?php\n") || str_starts_with($script, '#!')) {
                throw new FpmIngressRefusal('worker proof script has a shebang or preheader');
            }
            $this->writeExclusive($paths['script'], $script, 0400);

            $port = $this->reserveLoopbackPort();
            $caddy = self::caddyConfig($port, $paths['script'], [
                'site-a' => ['host' => $hosts['site-a'], 'socket' => $paths['socket_site-a']],
                'site-b' => ['host' => $hosts['site-b'], 'socket' => $paths['socket_site-b']],
            ]);
            $this->writeExclusive($paths['caddyfile'], $caddy, 0400);

            foreach (['site-a', 'site-b'] as $worker) {
                $this->startProcess(
                    "php-fpm-$worker",
                    [
                        $executables['php_fpm'], '-c', $phpIni, '-F', '-O',
                        '-y', $paths["fpm_conf_$worker"],
                    ],
                    $paths['root'],
                    null,
                    $paths["process_log_php-fpm-$worker"]
                );
            }
            foreach (['site-a', 'site-b'] as $worker) {
                $this->waitForSocket(
                    $paths["socket_$worker"],
                    "php-fpm-$worker",
                    $activeDeadline
                );
            }
            self::assertSocketContract(
                $paths['runtime'],
                [
                    'site-a' => $paths['runtime_site-a'],
                    'site-b' => $paths['runtime_site-b'],
                ],
                [
                    'site-a' => $paths['socket_site-a'],
                    'site-b' => $paths['socket_site-b'],
                ],
                $uid,
                $gid
            );

            $environment = getenv();
            if (!is_array($environment)) {
                throw new FpmIngressRefusal('could not snapshot the Caddy process environment');
            }
            $environment['XDG_CONFIG_HOME'] = $paths['caddy_config'];
            $environment['XDG_DATA_HOME'] = $paths['caddy_data'];
            $this->startProcess(
                'caddy',
                [
                    $executables['caddy'], 'run', '--config', $paths['caddyfile'],
                    '--adapter', 'caddyfile',
                ],
                $paths['root'],
                $environment,
                $paths['process_log_caddy']
            );
            $this->waitForLoopback($port, $activeDeadline);

            $probeA = $this->probe(
                $executables['curl'],
                $port,
                $hosts['site-a'],
                '/v1/preview/control',
                'site-a',
                $activeDeadline
            );
            $probeB = $this->probe(
                $executables['curl'],
                $port,
                $hosts['site-b'],
                '/v1/preview/control',
                'site-b',
                $activeDeadline
            );
            $expectedA = self::workerBody(
                'site-a',
                $configurationDigests['site-a'],
                $markers['site-a']
            );
            $expectedB = self::workerBody(
                'site-b',
                $configurationDigests['site-b'],
                $markers['site-b']
            );
            self::assertWorkerProbe($probeA, $expectedA, $markers['site-b']);
            self::assertWorkerProbe($probeB, $expectedB, $markers['site-a']);

            $unknownHost = $this->probe(
                $executables['curl'],
                $port,
                'unknown.control.example.invalid',
                '/v1/preview/control',
                'unknown-host',
                $activeDeadline
            );
            self::assertNotFoundProbe($unknownHost, $markers);
            $unknownPath = $this->probe(
                $executables['curl'],
                $port,
                $hosts['site-a'],
                '/v1/preview/not-reviewed',
                'unknown-path',
                $activeDeadline
            );
            self::assertNotFoundProbe($unknownPath, $markers);
            $this->assertCleanRuntimeLogs();
            $this->assertBinaryIdentitiesUnchanged($executables, $binaries);

            $evidence = [
                'binaries' => $binaries,
                'configuration_sha256' => [
                    'site-a' => $configurationDigests['site-a'],
                    'site-b' => $configurationDigests['site-b'],
                ],
                'format' => self::PROOF_FORMAT,
                'identity' => ['gid' => $gid, 'uid' => $uid],
                'max_wall_seconds' => self::MAX_WALL_SECONDS,
                'php_fpm_ini_sha256' => $phpIniSha256,
                'probes' => [
                    ['host' => $hosts['site-a'], 'status' => 200, 'worker' => 'site-a'],
                    ['host' => $hosts['site-b'], 'status' => 200, 'worker' => 'site-b'],
                    ['host' => 'unknown.control.example.invalid', 'status' => 404],
                    ['host' => $hosts['site-a'], 'path' => '/v1/preview/not-reviewed', 'status' => 404],
                ],
                'socket_contract' => [
                    'directory_mode' => '0750',
                    'group' => $gid,
                    'mode' => '0660',
                    'owner' => $uid,
                    'pools' => 2,
                    'sockets' => 2,
                ],
                'state' => 'ready',
            ];
            $this->green = true;
        } catch (\Throwable $error) {
            $diagnostic = $this->failureDiagnostics();
            $primary = new FpmIngressRefusal(
                $error->getMessage() . $diagnostic,
                0,
                $error
            );
        }

        try {
            $this->cleanup(self::monotonicNow() + self::CLEANUP_DEADLINE_SECONDS);
        } catch (\Throwable $cleanupError) {
            $message = 'verifier could not prove exact cleanup: ' . $cleanupError->getMessage();
            if ($primary !== null) {
                $message .= ' after: ' . $primary->getMessage();
            }
            throw new FpmIngressRefusal($message, 0, $cleanupError);
        }
        if ($primary !== null) {
            if ($primary instanceof FpmIngressRefusal) {
                throw $primary;
            }
            throw new FpmIngressRefusal('unexpected verifier failure', 0, $primary);
        }
        if (!is_array($evidence)) {
            throw new FpmIngressRefusal('verifier produced no proof evidence');
        }
        $evidence['cleanup'] = 'exact';
        $evidence['proof_receipt_sha256'] = hash(
            'sha256',
            "duo-cloud-fpm-ingress-proof-receipt/v1\0" . self::canonicalJson($evidence)
        );
        return $evidence;
    }

    /** @return string */
    public static function workerScript(): string {
        return <<<'PHP'
<?php
declare(strict_types=1);

const DUO_FPM_SOURCE_SENTINEL_DO_NOT_DISCLOSE = 'duo-fpm-source-sentinel';

function refuse_worker_request(): never {
    http_response_code(500);
    header('Content-Type: application/json');
    echo "{\"format\":\"duo-cloud-fpm-ingress-worker-refusal/v1\"}\n";
    exit;
}

$worker = getenv('DUO_CLOUD_INGRESS_WORKER');
$path = getenv('DUO_CLOUD_CONFIG_FILE');
$digest = getenv('DUO_CLOUD_CONFIG_SHA256');
if (!is_string($worker) || !in_array($worker, ['site-a', 'site-b'], true)
    || !is_string($path) || !str_starts_with($path, '/')
    || !is_string($digest) || preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1
    || ($_SERVER['REQUEST_URI'] ?? null) !== '/v1/preview/control') {
    refuse_worker_request();
}
$bytes = @file_get_contents($path);
if (!is_string($bytes) || strlen($bytes) > 4096 || !hash_equals($digest, hash('sha256', $bytes))) {
    refuse_worker_request();
}
try {
    $config = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR);
} catch (Throwable) {
    refuse_worker_request();
}
if (!is_array($config) || array_is_list($config)) {
    refuse_worker_request();
}
$keys = array_keys($config);
sort($keys, SORT_STRING);
if ($keys !== ['format', 'host', 'marker', 'worker']
    || ($config['format'] ?? null) !== 'duo-cloud-fpm-ingress-worker-config/v1'
    || ($config['worker'] ?? null) !== $worker
    || ($config['host'] ?? null) !== ($_SERVER['HTTP_HOST'] ?? null)
    || !is_string($config['marker'] ?? null)
    || preg_match('/\A[a-f0-9]{64}\z/D', $config['marker']) !== 1) {
    refuse_worker_request();
}
header('Content-Type: application/json');
echo '{"configuration_sha256":"' . $digest
    . '","format":"duo-cloud-fpm-ingress-worker/v1","marker":"'
    . $config['marker'] . '","worker":"' . $worker . "\"}\n";
PHP;
    }

    public static function fpmConfig(
        string $worker,
        string $user,
        string $group,
        string $socket,
        string $pid,
        string $log,
        string $configuration,
        string $configurationSha256
    ): string {
        foreach ([$socket, $pid, $log, $configuration] as $path) {
            self::assertConfigPath($path);
        }
        if (!in_array($worker, ['site-a', 'site-b'], true)
            || preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]*\z/D', $user) !== 1
            || preg_match('/\A[A-Za-z_][A-Za-z0-9_.-]*\z/D', $group) !== 1
            || preg_match('/\A[a-f0-9]{64}\z/D', $configurationSha256) !== 1) {
            throw new FpmIngressRefusal('FPM proof authority input is invalid');
        }
        return "[global]\n"
            . "pid = $pid\n"
            . "error_log = $log\n"
            . "log_level = notice\n"
            . "daemonize = no\n\n"
            . "[duo-cloud-$worker]\n"
            . "user = $user\n"
            . "group = $group\n"
            . "pm = static\n"
            . "pm.max_children = 1\n"
            . "pm.max_requests = 10\n"
            . "request_terminate_timeout = 10s\n"
            . "listen = $socket\n"
            . "listen.owner = $user\n"
            . "listen.group = $group\n"
            . "listen.mode = 0660\n"
            . "clear_env = yes\n"
            . "catch_workers_output = yes\n"
            . "decorate_workers_output = no\n"
            . "security.limit_extensions =\n"
            . "php_admin_flag[display_errors] = off\n"
            . "php_admin_flag[display_startup_errors] = off\n"
            . "php_admin_flag[expose_php] = off\n"
            . "php_admin_flag[log_errors] = on\n"
            . "php_admin_value[max_execution_time] = 5\n"
            . "php_admin_value[error_log] = $log\n"
            . "env[DUO_CLOUD_INGRESS_WORKER] = $worker\n"
            . "env[DUO_CLOUD_CONFIG_FILE] = $configuration\n"
            . "env[DUO_CLOUD_CONFIG_SHA256] = $configurationSha256\n";
    }

    /** @param array<string,array{host:string,socket:string}> $workers */
    public static function caddyConfig(int $port, string $script, array $workers): string {
        self::assertConfigPath($script);
        if ($port < 1024 || $port > 65535 || array_keys($workers) !== ['site-a', 'site-b']) {
            throw new FpmIngressRefusal('Caddy proof listener or worker set is invalid');
        }
        $sections = '';
        foreach ($workers as $worker => $authority) {
            self::assertConfigPath($authority['socket']);
            if ($authority['host'] !== "$worker.control.example.invalid") {
                throw new FpmIngressRefusal('Caddy proof host does not match its worker');
            }
            $matcher = str_replace('-', '_', $worker);
            $sections .= "\t@worker_$matcher {\n"
                . "\t\thost {$authority['host']}\n"
                . "\t\tpath /v1/preview/control\n"
                . "\t}\n"
                . "\thandle @worker_$matcher {\n"
                . "\t\trequest_body {\n"
                . "\t\t\tmax_size 2MiB\n"
                . "\t\t}\n"
                . "\t\treverse_proxy unix/{$authority['socket']} {\n"
                . "\t\t\ttransport fastcgi {\n"
                . "\t\t\t\troot " . dirname($script) . "\n"
                . "\t\t\t\tenv SCRIPT_FILENAME $script\n"
                . "\t\t\t\tenv SCRIPT_NAME /ingress.php\n"
                . "\t\t\t}\n"
                . "\t\t}\n"
                . "\t}\n\n";
        }
        return "{\n"
            . "\tadmin off\n"
            . "\tservers {\n"
            . "\t\tprotocols h1\n"
            . "\t\ttimeouts {\n"
            . "\t\t\tread_body 3s\n"
            . "\t\t\tread_header 3s\n"
            . "\t\t\tidle 5s\n"
            . "\t\t}\n"
            . "\t}\n"
            . "}\n\n"
            . "http://:$port {\n"
            . "\tbind 127.0.0.1\n"
            . $sections
            . "\thandle {\n"
            . "\t\trespond 404\n"
            . "\t}\n"
            . "}\n";
    }

    /**
     * @param array{body:string,headers:string,remote_ip:string,status:int} $probe
     */
    public static function assertWorkerProbe(
        array $probe,
        string $expectedBody,
        string $foreignMarker
    ): void {
        if ($probe['status'] !== 200 || $probe['remote_ip'] !== '127.0.0.1'
            || $probe['body'] !== $expectedBody
            || !str_starts_with($probe['headers'], "HTTP/1.1 200 OK\r\n")
            || preg_match('/^Content-Type: application\/json(?:;[^\r\n]*)?\r$/mi', $probe['headers']) !== 1
            || preg_match('/^X-Powered-By:/mi', $probe['headers']) === 1
            || str_contains($probe['body'], $foreignMarker)) {
            throw new FpmIngressRefusal(sprintf(
                'control host did not reach only its exact FPM authority'
                    . ' (status=%d remote=%s body=%d/%s expected=%d/%s headers=%s)',
                $probe['status'],
                $probe['remote_ip'],
                strlen($probe['body']),
                hash('sha256', $probe['body']),
                strlen($expectedBody),
                hash('sha256', $expectedBody),
                str_replace(["\r", "\n"], ['', '|'], trim($probe['headers']))
            ));
        }
        foreach (['#!', '<?php', 'Content-Type:', 'Status:', 'DUO_FPM_SOURCE_SENTINEL_DO_NOT_DISCLOSE'] as $disclosure) {
            if (str_contains($probe['body'], $disclosure)) {
                throw new FpmIngressRefusal('FPM response disclosed a shebang, preheader, or source body');
            }
        }
    }

    /** @param array{body:string,headers:string,remote_ip:string,status:int} $probe @param array<string,string> $markers */
    public static function assertNotFoundProbe(array $probe, array $markers): void {
        if ($probe['status'] !== 404 || $probe['remote_ip'] !== '127.0.0.1'
            || $probe['body'] !== ''
            || !str_starts_with($probe['headers'], "HTTP/1.1 404 Not Found\r\n")) {
            throw new FpmIngressRefusal('unregistered control route did not return an exact empty 404');
        }
        foreach ($markers as $marker) {
            if (str_contains($probe['headers'] . $probe['body'], $marker)) {
                throw new FpmIngressRefusal('unregistered control route disclosed a worker marker');
            }
        }
    }

    /** @param array<string,string> $directories @param array<string,string> $sockets */
    public static function assertSocketContract(
        string $runtime,
        array $directories,
        array $sockets,
        int $uid,
        int $gid
    ): void {
        self::assertFilesystemNode($runtime, 'dir', 0750, $uid, $gid, 'runtime directory');
        if (array_keys($directories) !== ['site-a', 'site-b']
            || array_keys($sockets) !== ['site-a', 'site-b']) {
            throw new FpmIngressRefusal('socket proof worker set is invalid');
        }
        foreach (['site-a', 'site-b'] as $worker) {
            self::assertFilesystemNode(
                $directories[$worker],
                'dir',
                0750,
                $uid,
                $gid,
                "$worker socket directory"
            );
            self::assertFilesystemNode(
                $sockets[$worker],
                'socket',
                0660,
                $uid,
                $gid,
                "$worker socket"
            );
            if (dirname($sockets[$worker]) !== $directories[$worker]
                || dirname($directories[$worker]) !== $runtime) {
                throw new FpmIngressRefusal('socket traversal path escaped its exact worker directory');
            }
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
            throw new FpmIngressRefusal('proof evidence was not canonical JSON', 0, $error);
        }
    }

    private function assertPosixIdentity(): void {
        foreach (['posix_geteuid', 'posix_getegid', 'posix_getpwuid', 'posix_getgrgid'] as $function) {
            if (!function_exists($function)) {
                throw new FpmIngressRefusal('POSIX identity functions are unavailable');
            }
        }
        if (posix_geteuid() === 0) {
            throw new FpmIngressRefusal('verifier must run as the non-root FPM service identity');
        }
    }

    /** @param list<string> $candidates */
    private function resolveExecutable(?string $configured, array $candidates): string {
        if ($configured !== null) {
            return $this->assertExecutable($configured);
        }
        $path = getenv('PATH');
        if (!is_string($path)) {
            throw new FpmIngressRefusal('PATH is unavailable; pass absolute executable paths');
        }
        foreach (explode(PATH_SEPARATOR, $path) as $directory) {
            if (!str_starts_with($directory, '/')) {
                continue;
            }
            foreach ($candidates as $candidate) {
                $executable = rtrim($directory, '/') . '/' . $candidate;
                if (is_file($executable) && is_executable($executable)) {
                    return $this->assertExecutable($executable);
                }
            }
        }
        throw new FpmIngressRefusal(
            'required executable is unavailable; pass its absolute path explicitly'
        );
    }

    private function assertExecutable(string $path): string {
        if (!str_starts_with($path, '/') || str_contains($path, "\0")) {
            throw new FpmIngressRefusal('executable path is not absolute');
        }
        $real = realpath($path);
        if (!is_string($real) || !is_file($real) || !is_executable($real)) {
            throw new FpmIngressRefusal('executable path is not a runnable regular file');
        }
        return $real;
    }

    /** @param array<string,string> $executables @return array<string,array<string,mixed>> */
    private function binaryEvidence(array $executables, float $deadline): array {
        $commands = [
            'caddy' => [$executables['caddy'], 'version'],
            'curl' => [$executables['curl'], '--version'],
            'php_fpm' => [$executables['php_fpm'], '-v'],
        ];
        $evidence = [];
        foreach ($commands as $name => $command) {
            $result = $this->runProcess($command, $deadline);
            if ($result['exit'] !== 0 || $result['stderr'] !== '') {
                throw new FpmIngressRefusal("$name version premise failed");
            }
            $firstLine = strtok($result['stdout'], "\r\n");
            if (!is_string($firstLine) || $firstLine === '') {
                throw new FpmIngressRefusal("$name version premise returned no identity");
            }
            if ($name === 'caddy' && !self::caddyV2Version($firstLine)) {
                throw new FpmIngressRefusal('Caddy premise is not a supported v2 build');
            }
            if ($name === 'curl' && !str_starts_with($firstLine, 'curl ')) {
                throw new FpmIngressRefusal('curl premise returned an unknown identity');
            }
            if ($name === 'php_fpm') {
                if (preg_match('/\APHP ([0-9]+)\.([0-9]+)\.[0-9]+ \(fpm-fcgi\)/D', $firstLine, $match) !== 1
                    || (int) $match[1] !== 8 || (int) $match[2] < 3) {
                    throw new FpmIngressRefusal('PHP-FPM premise is older than the supported 8.3 floor');
                }
            }
            $stat = lstat($executables[$name]);
            $sha256 = hash_file('sha256', $executables[$name]);
            if (!is_array($stat) || !is_string($sha256)) {
                throw new FpmIngressRefusal("$name binary identity could not be read");
            }
            $evidence[$name] = [
                'device' => $stat['dev'],
                'inode' => $stat['ino'],
                'path' => $executables[$name],
                'sha256' => $sha256,
                'version' => $firstLine,
            ];
        }
        return $evidence;
    }

    private static function caddyV2Version(string $line): bool {
        // Ubuntu's maintained package prints `2.x.y`; upstream release builds
        // print `v2.x.y`. Both identify the same closed major-version premise.
        return preg_match('/\Av?2\.[0-9]+\.[0-9]+(?:[ -]|\z)/D', $line) === 1;
    }

    /** @param array<string,string> $executables @param array<string,array<string,mixed>> $expected */
    private function assertBinaryIdentitiesUnchanged(array $executables, array $expected): void {
        foreach ($executables as $name => $path) {
            clearstatcache(true, $path);
            $stat = lstat($path);
            $sha256 = hash_file('sha256', $path);
            if (!is_array($stat) || !is_string($sha256)
                || $stat['dev'] !== $expected[$name]['device']
                || $stat['ino'] !== $expected[$name]['inode']
                || !hash_equals((string) $expected[$name]['sha256'], $sha256)) {
                throw new FpmIngressRefusal("$name binary identity changed during proof");
            }
        }
    }

    private function createRoot(): string {
        $candidates = ['/tmp', sys_get_temp_dir()];
        $base = null;
        foreach ($candidates as $candidate) {
            $resolved = realpath($candidate);
            if (is_string($resolved) && is_dir($resolved) && is_writable($resolved)
                && str_starts_with($resolved, '/')
                && preg_match('/\A[A-Za-z0-9._\/-]+\z/D', $resolved) === 1) {
                $base = $resolved;
                break;
            }
        }
        if (!is_string($base)) {
            throw new FpmIngressRefusal('system temporary directory is not config-safe');
        }
        $this->rootBase = $base;
        for ($attempt = 0; $attempt < 8; ++$attempt) {
            $root = $base . '/duo-cloud-fpm-ingress-' . posix_geteuid() . '-'
                . bin2hex(random_bytes(12));
            $umask = umask(0077);
            try {
                $created = @mkdir($root, 0700);
            } finally {
                umask($umask);
            }
            if ($created && chgrp($root, posix_getegid()) && chmod($root, 0700)) {
                return $root;
            }
        }
        throw new FpmIngressRefusal('private verifier root could not be created');
    }

    /** @return array<string,string> */
    private function prepareTree(int $uid, int $gid): array {
        if ($this->root === null) {
            throw new FpmIngressRefusal('private verifier root is unavailable');
        }
        $paths = [
            'root' => $this->root,
            'config' => $this->root . '/config',
            'runtime' => $this->root . '/run',
            'runtime_site-a' => $this->root . '/run/site-a',
            'runtime_site-b' => $this->root . '/run/site-b',
            'docroot' => $this->root . '/www',
            'logs' => $this->root . '/logs',
            'caddy_config' => $this->root . '/caddy-config',
            'caddy_data' => $this->root . '/caddy-data',
        ];
        foreach (['config', 'docroot', 'logs', 'caddy_config', 'caddy_data'] as $name) {
            $this->makeDirectory($paths[$name], 0700);
        }
        $this->makeDirectory($paths['runtime'], 0750);
        $this->makeDirectory($paths['runtime_site-a'], 0750);
        $this->makeDirectory($paths['runtime_site-b'], 0750);
        self::assertFilesystemNode($paths['runtime'], 'dir', 0750, $uid, $gid, 'runtime directory');
        foreach (['site-a', 'site-b'] as $worker) {
            self::assertFilesystemNode(
                $paths["runtime_$worker"],
                'dir',
                0750,
                $uid,
                $gid,
                "$worker runtime directory"
            );
            $paths["config_$worker"] = $paths['config'] . "/$worker.json";
            $paths["fpm_conf_$worker"] = $paths['config'] . "/$worker-fpm.conf";
            $paths["socket_$worker"] = $paths["runtime_$worker"] . '/php-fpm.sock';
            $paths["pid_$worker"] = $paths["runtime_$worker"] . '/php-fpm.pid';
            $paths["fpm_log_$worker"] = $paths['logs'] . "/$worker-fpm.log";
            $paths["process_log_php-fpm-$worker"] = $paths['logs'] . "/$worker-process.log";
        }
        $paths['script'] = $paths['docroot'] . '/ingress.php';
        $paths['caddyfile'] = $paths['config'] . '/Caddyfile';
        $paths['process_log_caddy'] = $paths['logs'] . '/caddy-process.log';
        return $paths;
    }

    private function makeDirectory(string $path, int $mode): void {
        $umask = umask(0077);
        try {
            $created = @mkdir($path, $mode);
        } finally {
            umask($umask);
        }
        if (!$created || !chgrp($path, posix_getegid()) || !chmod($path, $mode)) {
            throw new FpmIngressRefusal('private verifier directory could not be protected');
        }
    }

    private function writeExclusive(string $path, string $bytes, int $mode): void {
        $handle = null;
        try {
            $umask = umask(0077);
            try {
                $handle = @fopen($path, 'x+b');
            } finally {
                umask($umask);
            }
            if (!is_resource($handle) || !chmod($path, $mode)) {
                throw new FpmIngressRefusal('private verifier file could not be protected');
            }
            $remaining = $bytes;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if (!is_int($written) || $written < 1) {
                    throw new FpmIngressRefusal('private verifier file could not be written');
                }
                $remaining = substr($remaining, $written);
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new FpmIngressRefusal('private verifier file could not be synchronized');
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    private function reserveLoopbackPort(): int {
        $errorCode = 0;
        $errorMessage = '';
        $server = @stream_socket_server(
            'tcp://127.0.0.1:0',
            $errorCode,
            $errorMessage,
            STREAM_SERVER_BIND | STREAM_SERVER_LISTEN
        );
        if (!is_resource($server)) {
            throw new FpmIngressRefusal('loopback proof port could not be reserved');
        }
        try {
            $address = stream_socket_get_name($server, false);
        } finally {
            fclose($server);
        }
        if (!is_string($address)
            || preg_match('/\A127\.0\.0\.1:([1-9][0-9]{3,4})\z/D', $address, $match) !== 1
            || (int) $match[1] > 65535) {
            throw new FpmIngressRefusal('loopback proof port assignment was invalid');
        }
        return (int) $match[1];
    }

    /** @param list<string> $argv @param array<string,string>|null $environment */
    private function startProcess(
        string $name,
        array $argv,
        string $workingDirectory,
        ?array $environment,
        string $log
    ): void {
        if (isset($this->processes[$name])) {
            throw new FpmIngressRefusal("$name process was already started");
        }
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $log, 'ab'],
            2 => ['file', $log, 'ab'],
        ];
        $process = @proc_open(
            $argv,
            $descriptors,
            $pipes,
            $workingDirectory,
            $environment,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new FpmIngressRefusal("$name process could not be started directly");
        }
        $this->processes[$name] = $process;
        $this->logs[$name] = $log;
    }

    private function waitForSocket(string $socket, string $process, float $deadline): void {
        while (self::monotonicNow() < $deadline) {
            $this->assertProcessRunning($process);
            clearstatcache(true, $socket);
            if (file_exists($socket) && filetype($socket) === 'socket') {
                return;
            }
            usleep(20000);
        }
        throw new FpmIngressRefusal("$process did not publish its socket before the deadline");
    }

    private function waitForLoopback(int $port, float $deadline): void {
        while (self::monotonicNow() < $deadline) {
            $this->assertProcessRunning('caddy');
            $errorCode = 0;
            $errorMessage = '';
            $connection = @stream_socket_client(
                "tcp://127.0.0.1:$port",
                $errorCode,
                $errorMessage,
                0.1,
                STREAM_CLIENT_CONNECT
            );
            if (is_resource($connection)) {
                fclose($connection);
                return;
            }
            usleep(20000);
        }
        throw new FpmIngressRefusal('Caddy control edge did not bind loopback before the deadline');
    }

    private function assertProcessRunning(string $name): void {
        $process = $this->processes[$name] ?? null;
        if (!is_resource($process)) {
            throw new FpmIngressRefusal("$name process ownership was lost");
        }
        $status = proc_get_status($process);
        if (!is_array($status) || ($status['running'] ?? false) !== true) {
            $diagnostic = $this->logDiagnostic($name);
            throw new FpmIngressRefusal("$name process exited before proof$diagnostic");
        }
    }

    /** @return array{body:string,headers:string,remote_ip:string,status:int} */
    private function probe(
        string $curl,
        int $port,
        string $host,
        string $path,
        string $label,
        float $deadline
    ): array {
        if ($this->root === null
            || preg_match('/\A[A-Za-z0-9.-]+\z/D', $host) !== 1
            || preg_match('/\A\/[A-Za-z0-9._\/-]+\z/D', $path) !== 1
            || preg_match('/\A[A-Za-z0-9._-]+\z/D', $label) !== 1) {
            throw new FpmIngressRefusal('HTTP proof input is invalid');
        }
        $headers = $this->root . "/logs/probe-$label.headers";
        $body = $this->root . "/logs/probe-$label.body";
        $result = $this->runProcess([
            $curl,
            '--silent', '--show-error', '--noproxy', '*', '--proto', '=http',
            '--http1.1', '--max-time', '3', '--request', 'POST',
            '--header', "Host: $host", '--header', 'Content-Type: application/json',
            '--data-binary', '{}', '--dump-header', $headers, '--output', $body,
            '--write-out', "%{http_code}\n%{remote_ip}\n",
            "http://127.0.0.1:$port$path",
        ], $deadline);
        if ($result['exit'] !== 0 || $result['stderr'] !== '') {
            throw new FpmIngressRefusal("$label HTTP proof failed through curl");
        }
        $parts = explode("\n", $result['stdout']);
        $headerBytes = @file_get_contents($headers);
        $bodyBytes = @file_get_contents($body);
        if (count($parts) !== 3 || $parts[2] !== ''
            || preg_match('/\A[1-5][0-9]{2}\z/D', $parts[0]) !== 1
            || filter_var($parts[1], FILTER_VALIDATE_IP) === false
            || !is_string($headerBytes) || strlen($headerBytes) > 65536
            || !is_string($bodyBytes) || strlen($bodyBytes) > 65536) {
            throw new FpmIngressRefusal("$label HTTP proof returned an invalid observation");
        }
        return [
            'body' => $bodyBytes,
            'headers' => $headerBytes,
            'remote_ip' => $parts[1],
            'status' => (int) $parts[0],
        ];
    }

    /** @param list<string> $argv @return array{exit:int,stderr:string,stdout:string} */
    private function runProcess(array $argv, float $deadline): array {
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = @proc_open($argv, $descriptors, $pipes, null, null, ['bypass_shell' => true]);
        if (!is_resource($process) || !isset($pipes[1], $pipes[2])
            || !is_resource($pipes[1]) || !is_resource($pipes[2])) {
            if (is_resource($process)) {
                proc_terminate($process, 15);
                proc_close($process);
            }
            throw new FpmIngressRefusal('direct proof subprocess could not be started');
        }
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $exit = -1;
        $safeToClose = true;
        try {
            while (true) {
                foreach ([1, 2] as $stream) {
                    $chunk = stream_get_contents($pipes[$stream]);
                    if (is_string($chunk) && $chunk !== '') {
                        if ($stream === 1) {
                            $stdout .= $chunk;
                        } else {
                            $stderr .= $chunk;
                        }
                    }
                }
                if (strlen($stdout) > self::OUTPUT_LIMIT || strlen($stderr) > self::OUTPUT_LIMIT) {
                    throw new FpmIngressRefusal('proof subprocess exceeded its output limit');
                }
                $status = proc_get_status($process);
                if (!is_array($status)) {
                    throw new FpmIngressRefusal('proof subprocess status was unavailable');
                }
                if (!$status['running']) {
                    $exit = (int) $status['exitcode'];
                    foreach ([1, 2] as $stream) {
                        stream_set_blocking($pipes[$stream], true);
                        $chunk = stream_get_contents($pipes[$stream]);
                        if (is_string($chunk)) {
                            if ($stream === 1) {
                                $stdout .= $chunk;
                            } else {
                                $stderr .= $chunk;
                            }
                        }
                    }
                    break;
                }
                if (self::monotonicNow() >= $deadline) {
                    throw new FpmIngressRefusal('proof subprocess exceeded the wall deadline');
                }
                usleep(20000);
            }
        } catch (\Throwable $error) {
            proc_terminate($process, 15);
            $stopDeadline = min($deadline + 1.0, self::monotonicNow() + 1.0);
            while (self::monotonicNow() < $stopDeadline) {
                $status = proc_get_status($process);
                if (!is_array($status) || !$status['running']) {
                    break;
                }
                usleep(20000);
            }
            $status = proc_get_status($process);
            if (is_array($status) && $status['running']) {
                proc_terminate($process, 9);
                $killDeadline = min($deadline + 2.0, self::monotonicNow() + 1.0);
                while (self::monotonicNow() < $killDeadline) {
                    $status = proc_get_status($process);
                    if (!is_array($status) || !$status['running']) {
                        break;
                    }
                    usleep(20000);
                }
            }
            $status = proc_get_status($process);
            if (is_array($status) && $status['running']) {
                $safeToClose = false;
                $this->processes['direct-' . get_resource_id($process)] = $process;
                throw new FpmIngressRefusal(
                    'exact direct proof subprocess survived TERM and KILL',
                    0,
                    $error
                );
            }
            throw $error;
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            if ($safeToClose) {
                $closed = proc_close($process);
                if ($exit < 0 && $closed >= 0) {
                    $exit = $closed;
                }
            }
        }
        return ['exit' => $exit, 'stderr' => $stderr, 'stdout' => $stdout];
    }

    private function assertCleanRuntimeLogs(): void {
        foreach ($this->logs as $name => $path) {
            $bytes = @file_get_contents($path);
            if (!is_string($bytes) || strlen($bytes) > self::OUTPUT_LIMIT) {
                throw new FpmIngressRefusal("$name runtime log was unavailable or unbounded");
            }
            if ($name === 'caddy') {
                $normalShutdownWarning = false;
                $cleanShutdown = false;
                foreach (preg_split('/\r?\n/', trim($bytes)) ?: [] as $line) {
                    if ($line === '') {
                        continue;
                    }
                    try {
                        $record = json_decode($line, true, 16, JSON_THROW_ON_ERROR);
                    } catch (\Throwable $error) {
                        throw new FpmIngressRefusal('Caddy runtime emitted a non-JSON log record', 0, $error);
                    }
                    if (!is_array($record) || array_is_list($record)) {
                        throw new FpmIngressRefusal('Caddy runtime log record was not an object');
                    }
                    $level = $record['level'] ?? null;
                    if ($level === 'info') {
                        if (($record['msg'] ?? null) === 'shutdown complete'
                            && ($record['signal'] ?? null) === 'SIGTERM'
                            && ($record['exit_code'] ?? null) === 0) {
                            $cleanShutdown = true;
                        }
                        continue;
                    }
                    if ($level === 'warn'
                        && ($record['logger'] ?? null) === 'admin'
                        && ($record['msg'] ?? null) === 'admin endpoint disabled') {
                        continue;
                    }
                    // Caddy 2.10.2 assigns warn severity to its documented,
                    // exit-code-zero SIGTERM acknowledgement; every other
                    // warning remains a refusal and shutdown is read again.
                    if ($level === 'warn'
                        && ($record['msg'] ?? null) === 'exiting; byeee!! 👋'
                        && ($record['signal'] ?? null) === 'SIGTERM') {
                        $normalShutdownWarning = true;
                        continue;
                    }
                    throw new FpmIngressRefusal('Caddy runtime emitted an unreviewed warning or error');
                }
                if ($normalShutdownWarning && !$cleanShutdown) {
                    throw new FpmIngressRefusal('Caddy runtime did not pair SIGTERM with exit code zero');
                }
                continue;
            }
            if (preg_match('/(?:^|[\s\"])(?:alert|critical|emergency|error|warning|warn)(?:[\s:\"]|$)/i', $bytes) === 1) {
                throw new FpmIngressRefusal("$name runtime emitted a warning or error");
            }
        }
    }

    private function logDiagnostic(string $name): string {
        $path = $this->logs[$name] ?? null;
        if (!is_string($path)) {
            return '';
        }
        $bytes = @file_get_contents($path);
        if (!is_string($bytes) || $bytes === '') {
            return '';
        }
        $tail = substr($bytes, -2048);
        $tail = preg_replace('/[^\x20-\x7e\r\n\t]/', '?', $tail);
        return is_string($tail) ? ': ' . trim($tail) : '';
    }

    private function failureDiagnostics(): string {
        $diagnostics = '';
        foreach (array_keys($this->logs) as $name) {
            $diagnostic = $this->logDiagnostic($name);
            if ($diagnostic !== '') {
                $diagnostics .= "\n$name$diagnostic";
            }
            if (strlen($diagnostics) > 8192) {
                return substr($diagnostics, 0, 8192) . "\n[diagnostics truncated]";
            }
        }
        return $diagnostics;
    }

    private function cleanup(float $deadline): void {
        $names = array_reverse(array_keys($this->processes));
        foreach ($names as $name) {
            $process = $this->processes[$name];
            $status = proc_get_status($process);
            if (is_array($status) && $status['running']) {
                proc_terminate($process, 15);
            }
        }
        $graceDeadline = min($deadline - 2.0, self::monotonicNow() + 5.0);
        while (self::monotonicNow() < $graceDeadline) {
            $running = false;
            foreach ($names as $name) {
                $status = proc_get_status($this->processes[$name]);
                if (is_array($status) && $status['running']) {
                    $running = true;
                    break;
                }
            }
            if (!$running) {
                break;
            }
            usleep(20000);
        }
        foreach ($names as $name) {
            $status = proc_get_status($this->processes[$name]);
            if (is_array($status) && $status['running']) {
                proc_terminate($this->processes[$name], 9);
            }
        }
        while (self::monotonicNow() < $deadline) {
            $running = false;
            foreach ($names as $name) {
                $status = proc_get_status($this->processes[$name]);
                if (is_array($status) && $status['running']) {
                    $running = true;
                    break;
                }
            }
            if (!$running) {
                break;
            }
            usleep(20000);
        }
        $survivors = [];
        foreach ($names as $name) {
            $process = $this->processes[$name];
            $status = proc_get_status($process);
            if (is_array($status) && $status['running']) {
                $survivors[] = $name;
                continue;
            }
            proc_close($process);
            unset($this->processes[$name]);
        }
        if ($survivors !== []) {
            throw new FpmIngressRefusal(
                'owned process survived cleanup deadline: ' . implode(',', $survivors)
            );
        }
        if ($this->green) {
            foreach ($this->socketPaths as $socket) {
                clearstatcache(true, $socket);
                if (file_exists($socket)) {
                    throw new FpmIngressRefusal('FPM master left its worker socket after shutdown');
                }
            }
            $this->assertCleanRuntimeLogs();
        }
        if ($this->root === null) {
            return;
        }
        $root = $this->root;
        $base = $this->rootBase;
        if (!is_string($base)
            || preg_match('/\Aduo-cloud-fpm-ingress-[0-9]+-[a-f0-9]{24}\z/D', basename($root)) !== 1
            || dirname($root) !== $base
            || !is_dir($root)
            || fileowner($root) !== posix_geteuid()) {
            throw new FpmIngressRefusal('private verifier cleanup target identity is invalid');
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if (self::monotonicNow() >= $deadline) {
                throw new FpmIngressRefusal('private verifier cleanup exceeded its deadline');
            }
            $path = $item->getPathname();
            $removed = $item->isDir() && !$item->isLink() ? @rmdir($path) : @unlink($path);
            if (!$removed) {
                throw new FpmIngressRefusal('private verifier cleanup could not remove an owned node');
            }
        }
        if (!@rmdir($root)) {
            throw new FpmIngressRefusal('private verifier root could not be removed');
        }
        clearstatcache(true, $root);
        if (file_exists($root)) {
            throw new FpmIngressRefusal('private verifier root remained after cleanup');
        }
        $this->root = null;
        $this->rootBase = null;
        $this->socketPaths = [];
        $this->green = false;
    }

    private static function assertConfigPath(string $path): void {
        if (!str_starts_with($path, '/')
            || preg_match('/\A[A-Za-z0-9._\/-]+\z/D', $path) !== 1
            || str_contains($path, '//') || str_contains($path, '/../')) {
            throw new FpmIngressRefusal('proof config path is unsafe');
        }
    }

    private static function monotonicNow(): float {
        return hrtime(true) / 1_000_000_000;
    }

    private static function assertFilesystemNode(
        string $path,
        string $type,
        int $mode,
        int $uid,
        int $gid,
        string $label
    ): void {
        clearstatcache(true, $path);
        $stat = lstat($path);
        $actualType = filetype($path);
        if (!is_array($stat) || $actualType !== $type
            || ($stat['mode'] & 0777) !== $mode
            || (int) $stat['uid'] !== $uid || (int) $stat['gid'] !== $gid) {
            $actual = is_array($stat)
                ? sprintf('%s/%04o/%d/%d', (string) $actualType, $stat['mode'] & 0777, $stat['uid'], $stat['gid'])
                : 'unreadable';
            throw new FpmIngressRefusal(
                sprintf(
                    '%s owner, group, mode, or type is invalid (expected %s/%04o/%d/%d; got %s)',
                    $label,
                    $type,
                    $mode,
                    $uid,
                    $gid,
                    $actual
                )
            );
        }
        if ($type === 'dir' && (($mode & 0100) === 0 || ($mode & 0010) === 0)) {
            throw new FpmIngressRefusal("$label does not preserve owner and group traversal");
        }
    }

    private static function workerBody(
        string $worker,
        string $configurationSha256,
        string $marker
    ): string {
        return self::canonicalJson([
            'configuration_sha256' => $configurationSha256,
            'format' => self::WORKER_FORMAT,
            'marker' => $marker,
            'worker' => $worker,
        ]) . "\n";
    }
}

/** @param list<string> $arguments */
function fpmIngressMain(array $arguments): int {
    try {
        $options = FpmIngressVerifier::parseArguments($arguments);
        $output = $options['contract']
            ? FpmIngressVerifier::contract()
            : (new FpmIngressVerifier())->run($options);
        fwrite(STDOUT, FpmIngressVerifier::canonicalJson($output) . "\n");
        return 0;
    } catch (\Throwable $error) {
        fwrite(STDERR, FpmIngressVerifier::PROOF_FORMAT . ' refusal: ' . $error->getMessage() . "\n");
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
    exit(fpmIngressMain($arguments));
}
