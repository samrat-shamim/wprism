#!/usr/bin/env php
<?php
declare(strict_types=1);

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\ContainerArgvProcessRunner;
use Duo\Cloud\ControlRefusal;
use Duo\Cloud\HostFirewallAuthority;
use Duo\Cloud\HostAuthorityBusy;
use Duo\Cloud\NativeContainerArgvProcessRunner;
use Duo\Cloud\ProductionConfig;
use Duo\Cloud\ProductionDeploymentProofs;

$linuxHostSourcePaths = [
    'deploy/verify-linux-host-boundaries.php' => __FILE__,
    'runtime/FirewallClientConfig.php' => dirname(__DIR__) . '/runtime/FirewallClientConfig.php',
    'runtime/HostFirewallAuthority.php' => dirname(__DIR__) . '/runtime/HostFirewallAuthority.php',
    'runtime/ProductionConfig.php' => dirname(__DIR__) . '/runtime/ProductionConfig.php',
    'runtime/ProductionDeploymentProofs.php' => dirname(__DIR__)
        . '/runtime/ProductionDeploymentProofs.php',
    'runtime/RuntimeSlotLock.php' => dirname(__DIR__) . '/runtime/RuntimeSlotLock.php',
    'src/CanonicalJson.php' => dirname(__DIR__) . '/src/CanonicalJson.php',
    'src/ContainerWorkloadRuntime.php' => dirname(__DIR__) . '/src/ContainerWorkloadRuntime.php',
    'src/ControlRefusal.php' => dirname(__DIR__) . '/src/ControlRefusal.php',
    'src/HostAuthorityBusy.php' => dirname(__DIR__) . '/src/HostAuthorityBusy.php',
    'src/ImmutableOciReference.php' => dirname(__DIR__) . '/src/ImmutableOciReference.php',
    'src/WorkloadRuntime.php' => dirname(__DIR__) . '/src/WorkloadRuntime.php',
    'src/WorkloadSecurityInspection.php' => dirname(__DIR__) . '/src/WorkloadSecurityInspection.php',
];
$linuxHostSourceIdentities = [];
try {
    foreach ($linuxHostSourcePaths as $name => $path) {
        $linuxHostSourceIdentities[$name] = observedHostProofFile(
            $path,
            "Linux host verifier source $name",
            2097152,
            false
        );
    }
} catch (Throwable) {
    fwrite(STDERR, "duo-cloud-linux-host-boundaries: refused\n");
    exit(70);
}

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ContainerWorkloadRuntime.php';
require_once dirname(__DIR__) . '/src/HostAuthorityBusy.php';
require_once dirname(__DIR__) . '/runtime/FirewallClientConfig.php';
require_once dirname(__DIR__) . '/runtime/HostFirewallAuthority.php';
require_once dirname(__DIR__) . '/runtime/ProductionConfig.php';
require_once dirname(__DIR__) . '/runtime/ProductionDeploymentProofs.php';

const DNS_SERVER = '192.0.2.53';
const HEALTH = "duo-cloud-preview-runtime-health/v1\n";
const STORAGE_PROOF_FORMAT = 'duo-cloud-xfs-quota-host-preflight-proof/v1';
const STORAGE_PROOF_BYTES = 67108864;
const STORAGE_PROOF_INODES = 1024;
const PROOF_LOCK = '/run/duo-cloud-host-proof/authority.lock';

final class LinuxHostProofTransientBusy extends RuntimeException {
}

/** @return resource */
function acquireProofLock() {
    $handle = @fopen(PROOF_LOCK, 'c+b');
    if (!is_resource($handle) || !chmod(PROOF_LOCK, 0600)
        || !flock($handle, LOCK_EX | LOCK_NB)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        throw new LinuxHostProofTransientBusy('another host-boundary proof is active');
    }
    return $handle;
}

/** @return array<string,string|bool> */
function options(array $argv): array {
    array_shift($argv);
    $result = [];
    while ($argv !== []) {
        $flag = array_shift($argv);
        if (!is_string($flag) || preg_match('/\A--[a-z][a-z0-9-]*\z/D', $flag) !== 1
            || array_key_exists($flag, $result)) {
            throw new RuntimeException('host verifier options are malformed or repeated');
        }
        if ($flag === '--allow-nonproduction-missing-apparmor') {
            $result[$flag] = true;
            continue;
        }
        $value = array_shift($argv);
        if (!is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw new RuntimeException('host verifier option value is invalid');
        }
        $result[$flag] = $value;
    }
    $expected = [
        '--configuration-file', '--configuration-sha256',
        '--host-preflight-root',
        '--synthetic-rotation-configuration-sha256',
    ];
    foreach ($expected as $flag) {
        if (!isset($result[$flag]) || !is_string($result[$flag])) {
            throw new RuntimeException("host verifier requires $flag");
        }
    }
    foreach (array_keys($result) as $flag) {
        if (!in_array($flag, [...$expected, '--allow-nonproduction-missing-apparmor'], true)) {
            throw new RuntimeException('host verifier option is unknown');
        }
    }
    return $result;
}

/** @return array{containers:list<string>,firewalls:list<array<string,mixed>>,networks:list<array{network_name:string}>,scratch:string,storage:string,volumes:list<string>} */
function hostProofCleanupPlan(string $configuration, string $syntheticRotation): array {
    foreach ([$configuration, $syntheticRotation] as $sha256) {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
            throw new RuntimeException('host proof cleanup configuration identity is invalid');
        }
    }
    $token = hash(
        'sha256',
        "duo-cloud-linux-host-boundary-proof-run/v1\0" . $configuration
    );
    $networks = [];
    $firewalls = [];
    foreach ([$configuration, $syntheticRotation] as $index => $bindingConfiguration) {
        $generation = $index + 1;
        $networkName = 'duo-preview-net-'
            . hash('sha256', $token . '-network-' . $generation)
            . '-g' . sprintf('%010d', $generation);
        $network = ['network_name' => $networkName];
        $networks[] = $network;
        $firewalls[] = [
            'configuration_sha256' => $bindingConfiguration,
            'network' => $network,
        ];
    }
    $proofId = hash('sha256', "duo-cloud-host-storage-proof/v1\0" . $configuration);
    return [
        'containers' => [
            'duo-proof-net-' . substr($token, 0, 16),
            'duo-proof-quota-' . substr($token, 0, 16),
            'duo-proof-inodes-' . substr($token, 0, 16),
        ],
        'firewalls' => $firewalls,
        'networks' => $networks,
        'scratch' => '/run/duo-cloud-host-proof/work-' . substr($token, 0, 32),
        'storage' => $proofId,
        'volumes' => ['duo-proof-db-' . $proofId, 'duo-proof-fs-' . $proofId],
    ];
}

/** @return array{exit:int,stderr:string,stdout:string} */
function mustRun(
    ContainerArgvProcessRunner $runner,
    array $argv,
    string $label,
    ?int $timeout = null
): array {
    $result = $runner->run($argv, null, $timeout);
    if (HostAuthorityBusy::matchesProcessResult(
        $result,
        HostAuthorityBusy::FIREWALL_CLIENT_STDERR
    ) || HostAuthorityBusy::matchesProcessResult(
        $result,
        HostAuthorityBusy::STORAGE_CLIENT_STDERR
    )) {
        throw new LinuxHostProofTransientBusy("$label authority is busy");
    }
    if ($result['exit'] !== 0 || $result['stderr'] !== '') {
        throw new RuntimeException("$label refused");
    }
    return $result;
}

/** @return array<string,mixed> */
function canonicalCommand(
    ContainerArgvProcessRunner $runner,
    array $argv,
    string $label,
    ?int $timeout = null
): array {
    $bytes = mustRun($runner, $argv, $label, $timeout)['stdout'];
    if (!str_ends_with($bytes, "\n")) {
        throw new RuntimeException("$label omitted its canonical terminator");
    }
    $document = CanonicalJson::decodeObject($bytes, 1048576);
    if ($bytes !== CanonicalJson::encode($document) . "\n") {
        throw new RuntimeException("$label response is not canonical");
    }
    return $document;
}

/** @return array<string,mixed> */
function jsonCommand(
    ContainerArgvProcessRunner $runner,
    array $argv,
    string $label,
    ?int $timeout = null
): array {
    $bytes = mustRun($runner, $argv, $label, $timeout)['stdout'];
    if ($bytes === '' || strlen($bytes) > 1048576) {
        throw new RuntimeException("$label JSON response is empty or oversized");
    }
    try {
        $document = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        throw new RuntimeException("$label response is not JSON", 0, $error);
    }
    if (!is_array($document) || array_is_list($document)) {
        throw new RuntimeException("$label JSON response is not an object");
    }
    return $document;
}

/** @param array<string,mixed> $left @param array<string,mixed> $right */
function sameFile(array $left, array $right): bool {
    foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'uid', 'gid', 'mtime', 'ctime'] as $field) {
        if ((int) ($left[$field] ?? -1) !== (int) ($right[$field] ?? -2)) {
            return false;
        }
    }
    return true;
}

/**
 * The fleet inspection deliberately checks schema without touching host bytes.
 * A boundary proof executes those bytes, so it closes that seam against the
 * exact descriptor before the first child is started.
 *
 * @param mixed $descriptor
 * @return array{path:string,sha256:string,snapshot:array<string,int>}
 */
function pinnedRuntimeFile(
    mixed $descriptor,
    string $label,
    int $limit,
    bool $executable
): array {
    if (!is_array($descriptor) || array_is_list($descriptor)) {
        throw new RuntimeException("$label descriptor is invalid");
    }
    $keys = array_keys($descriptor);
    sort($keys, SORT_STRING);
    $path = $descriptor['path'] ?? null;
    $sha256 = $descriptor['sha256'] ?? null;
    if ($keys !== ['path', 'sha256'] || !is_string($path) || $path === '' || $path[0] !== '/'
        || str_contains($path, "\0") || str_contains($path, '//') || str_ends_with($path, '/')
        || preg_match('#(?:^|/)\.\.?(/|$)#D', $path) === 1
        || !is_string($sha256) || preg_match('/\A[a-f0-9]{64}\z/D', $sha256) !== 1) {
        throw new RuntimeException("$label descriptor is invalid");
    }
    return readStableHostProofFile($path, $sha256, $label, $limit, $executable);
}

/** @return array{path:string,sha256:string,snapshot:array<string,int>} */
function observedHostProofFile(
    string $path,
    string $label,
    int $limit,
    bool $executable
): array {
    return readStableHostProofFile($path, null, $label, $limit, $executable);
}

/** @return array{path:string,sha256:string,snapshot:array<string,int>} */
function readStableHostProofFile(
    string $path,
    ?string $expectedSha256,
    string $label,
    int $limit,
    bool $executable
): array {
    if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")
        || str_contains($path, '//') || str_ends_with($path, '/')
        || preg_match('#(?:^|/)\.\.?(/|$)#D', $path) === 1
        || $limit < 1 || $limit > 67108864
        || ($expectedSha256 !== null
            && preg_match('/\A[a-f0-9]{64}\z/D', $expectedSha256) !== 1)) {
        throw new RuntimeException("$label descriptor is invalid");
    }
    clearstatcache(true, $path);
    $before = @lstat($path);
    $canonical = realpath($path);
    $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    $owner = is_array($before) ? (int) ($before['uid'] ?? -1) : -1;
    if (!is_array($before) || !is_string($canonical) || $canonical !== $path || is_link($path)
        || ($before['mode'] & 0170000) !== 0100000 || ($before['mode'] & 0022) !== 0
        || ($effectiveUid !== null && $owner !== 0 && $owner !== $effectiveUid)
        || (int) $before['size'] < 1 || (int) $before['size'] > $limit
        || ($executable && !is_executable($path))) {
        throw new RuntimeException("$label is not an approved pinned regular file");
    }
    $handle = @fopen($path, 'rb');
    if (!is_resource($handle)) {
        throw new RuntimeException("$label could not be opened");
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
        || !sameFile($before, $opened) || !sameFile($before, $finished)
        || !sameFile($before, $after)
        || ($expectedSha256 !== null && !hash_equals($expectedSha256, $actualSha256))) {
        throw new RuntimeException("$label changed while reading or differs from its pin");
    }
    $snapshot = [];
    foreach (['dev', 'ino', 'mode', 'nlink', 'size', 'uid', 'gid', 'mtime', 'ctime'] as $field) {
        $snapshot[$field] = (int) $before[$field];
    }
    return ['path' => $path, 'sha256' => $actualSha256, 'snapshot' => $snapshot];
}

/** @param array{path:string,sha256:string,snapshot:array<string,int>} $identity */
function revalidateHostProofFile(
    array $identity,
    string $label,
    int $limit,
    bool $executable
): void {
    $current = readStableHostProofFile(
        $identity['path'],
        $identity['sha256'],
        $label,
        $limit,
        $executable
    );
    if ($current['snapshot'] !== $identity['snapshot']) {
        throw new RuntimeException("$label changed during host verification");
    }
}

function writePhpSource(string $path, string $body): void {
    // The production proof root is /run (noexec), so only the pinned PHP CLI may execute this source.
    if (file_put_contents($path, $body) !== strlen($body) || !chmod($path, 0600)) {
        throw new RuntimeException('host verifier child could not be sealed');
    }
}

function removeTree(string $path): void {
    if (!file_exists($path) && !is_link($path)) {
        return;
    }
    if (is_dir($path) && !is_link($path)) {
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                removeTree($path . '/' . $entry);
            }
        }
        if (!rmdir($path)) {
            throw new RuntimeException('host verifier directory cleanup failed');
        }
        return;
    }
    if (!unlink($path)) {
        throw new RuntimeException('host verifier file cleanup failed');
    }
}

/** @return array{success_descendant:string,timeout_descendant:string} */
function proveCgroup(
    ContainerArgvProcessRunner $runner,
    string $root,
    string $phpCli
): array {
    $successMarker = $root . '/success-orphan';
    $successReady = $root . '/success-ready';
    $success = $root . '/success-child';
    writePhpSource($success, <<<'PHP'
<?php
$child = pcntl_fork();
if ($child < 0) exit(71);
if ($child === 0) {
    if (posix_setsid() < 0) exit(72);
    if (file_put_contents($argv[2], "ready\n") !== 6) exit(73);
    usleep(1500000);
    file_put_contents($argv[1], "escaped\n");
    exit(0);
}
for ($attempt = 0; $attempt < 50; $attempt++) {
    clearstatcache(true, $argv[2]);
    if (@file_get_contents($argv[2]) === "ready\n") exit(0);
    usleep(10000);
}
exit(74);
PHP);
    try {
        $result = $runner->run(
            [$phpCli, $success, $successMarker, $successReady],
            null,
            4
        );
        if ($result !== ['exit' => 0, 'stderr' => '', 'stdout' => '']) {
            throw new RuntimeException('detached successful child failed before cgroup enforcement');
        }
        throw new RuntimeException('detached successful child escaped the transaction');
    } catch (ControlRefusal $error) {
        if ($error->getMessage() !== 'direct argv process left a descendant after completion') {
            throw $error;
        }
    }
    if (@file_get_contents($successReady) !== "ready\n") {
        throw new RuntimeException('detached successful child did not establish its descendant');
    }
    usleep(1700000);
    if (file_exists($successMarker)) {
        throw new RuntimeException('detached successful child published after refusal');
    }

    $timeoutMarker = $root . '/timeout-orphan';
    $timeoutReady = $root . '/timeout-ready';
    $timeout = $root . '/timeout-child';
    writePhpSource($timeout, <<<'PHP'
<?php
$child = pcntl_fork();
if ($child < 0) exit(71);
if ($child === 0) {
    if (posix_setsid() < 0) exit(72);
    if (file_put_contents($argv[2], "ready\n") !== 6) exit(73);
    usleep(2000000);
    file_put_contents($argv[1], "escaped\n");
    exit(0);
}
for ($attempt = 0; $attempt < 50; $attempt++) {
    clearstatcache(true, $argv[2]);
    if (@file_get_contents($argv[2]) === "ready\n") {
        sleep(30);
        exit(0);
    }
    usleep(10000);
}
exit(74);
PHP);
    try {
        $result = $runner->run(
            [$phpCli, $timeout, $timeoutMarker, $timeoutReady],
            null,
            1
        );
        if ($result !== ['exit' => 0, 'stderr' => '', 'stdout' => '']) {
            throw new RuntimeException('detached timed child failed before cgroup enforcement');
        }
        throw new RuntimeException('detached timed child escaped the transaction');
    } catch (ControlRefusal $error) {
        if ($error->getMessage() !== 'direct argv process exceeded its wall timeout') {
            throw $error;
        }
    }
    if (@file_get_contents($timeoutReady) !== "ready\n") {
        throw new RuntimeException('detached timed child did not establish its descendant');
    }
    usleep(2200000);
    if (file_exists($timeoutMarker)) {
        throw new RuntimeException('detached timed child published after refusal');
    }
    return ['success_descendant' => 'reaped', 'timeout_descendant' => 'reaped'];
}

/** @return array{bridge_name:string,gateway:string,network_id:string,network_name:string,subnet:string} */
function createNetwork(
    NativeContainerArgvProcessRunner $runner,
    string $engine,
    string $name,
    string $bridge,
    string $proofConfiguration
): array {
    mustRun($runner, [
        $engine, 'network', 'create', '--driver', 'bridge', '--internal', '--ipv6=false',
        '--label', 'duo.cloud.host-boundary-proof=' . $proofConfiguration,
        '--opt', 'com.docker.network.bridge.enable_ip_masquerade=false',
        '--opt', 'com.docker.network.bridge.enable_icc=false',
        '--opt', 'com.docker.network.bridge.name=' . $bridge, $name,
    ], 'proof network create');
    $inspect = jsonCommand($runner, [
        $engine, 'network', 'inspect', '--format', '{{json .}}', $name,
    ], 'proof network inspect');
    $ipam = $inspect['IPAM']['Config'][0] ?? null;
    $options = $inspect['Options'] ?? null;
    if (($inspect['Name'] ?? null) !== $name || ($inspect['Driver'] ?? null) !== 'bridge'
        || ($inspect['Internal'] ?? null) !== true || ($inspect['EnableIPv6'] ?? null) !== false
        || !is_array($ipam) || !is_array($options)
        || ($options['com.docker.network.bridge.name'] ?? null) !== $bridge
        || ($options['com.docker.network.bridge.enable_ip_masquerade'] ?? null) !== 'false'
        || ($options['com.docker.network.bridge.enable_icc'] ?? null) !== 'false'
        || !is_string($inspect['Id'] ?? null) || !is_string($ipam['Gateway'] ?? null)
        || !is_string($ipam['Subnet'] ?? null)) {
        throw new RuntimeException('proof network readback differs from the exact fence');
    }
    return [
        'bridge_name' => $bridge,
        'gateway' => $ipam['Gateway'],
        'network_id' => $inspect['Id'],
        'network_name' => $name,
        'subnet' => $ipam['Subnet'],
    ];
}

/** @return non-empty-list<string> */
function firewallArgv(
    string $client,
    string $action,
    string $configuration,
    array $network
): array {
    $argv = [
        $client, $action, '--config-sha256', $configuration,
        '--network-name', $network['network_name'],
    ];
    if ($action !== 'bind') {
        return $argv;
    }
    return [...$argv,
        '--bridge-name', $network['bridge_name'], '--gateway', $network['gateway'],
        '--network-id', $network['network_id'], '--subnet', $network['subnet'],
    ];
}

/** @return array{pid:int,tcp_port:int,udp_port:int} */
function hostListener(): array {
    $tcp = stream_socket_server('tcp://0.0.0.0:0', $tcpError, $tcpMessage);
    $udp = stream_socket_server('udp://0.0.0.0:0', $udpError, $udpMessage, STREAM_SERVER_BIND);
    if (!is_resource($tcp) || !is_resource($udp)) {
        throw new RuntimeException("host proof listener failed: $tcpError/$udpError");
    }
    $tcpAddress = stream_socket_get_name($tcp, false);
    $udpAddress = stream_socket_get_name($udp, false);
    $tcpSeparator = is_string($tcpAddress) ? strrpos($tcpAddress, ':') : false;
    $udpSeparator = is_string($udpAddress) ? strrpos($udpAddress, ':') : false;
    $tcpPort = is_int($tcpSeparator) ? (int) substr($tcpAddress, $tcpSeparator + 1) : 0;
    $udpPort = is_int($udpSeparator) ? (int) substr($udpAddress, $udpSeparator + 1) : 0;
    if ($tcpPort < 1 || $udpPort < 1) {
        fclose($tcp);
        fclose($udp);
        throw new RuntimeException('host proof listener ports are invalid');
    }
    stream_set_blocking($tcp, false);
    stream_set_blocking($udp, false);
    $pid = pcntl_fork();
    if ($pid < 0) {
        fclose($tcp);
        fclose($udp);
        throw new RuntimeException('host proof listener could not fork');
    }
    if ($pid === 0) {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, static function (): void { exit(0); });
        while (true) {
            $read = [$tcp, $udp];
            $write = null;
            $except = null;
            @stream_select($read, $write, $except, 1);
            $client = @stream_socket_accept($tcp, 0);
            if (is_resource($client)) {
                fwrite($client, "HTTP/1.1 200 OK\r\nContent-Length: 2\r\n\r\nok");
                fclose($client);
            }
            $peer = null;
            $datagram = @stream_socket_recvfrom($udp, 1024, 0, $peer);
            if (is_string($datagram) && $datagram !== '' && is_string($peer)) {
                @stream_socket_sendto($udp, "echo\n", 0, $peer);
            }
        }
    }
    fclose($tcp);
    fclose($udp);
    return ['pid' => $pid, 'tcp_port' => $tcpPort, 'udp_port' => $udpPort];
}

function stopListener(?array $listener): void {
    if ($listener === null) {
        return;
    }
    @posix_kill($listener['pid'], SIGTERM);
    $deadline = hrtime(true) + 1_000_000_000;
    do {
        if (pcntl_waitpid($listener['pid'], $status, WNOHANG) === $listener['pid']) {
            return;
        }
        usleep(10000);
    } while (hrtime(true) < $deadline);
    @posix_kill($listener['pid'], SIGKILL);
    pcntl_waitpid($listener['pid'], $status);
}

/** @return array{apparmor:string,dns_tcp:string,dns_udp:string,external_tcp:string,gateway_tcp:string,gateway_udp:string,host_ingress:string,multi_binding:string} */
function proveFirewall(
    NativeContainerArgvProcessRunner $runner,
    array $configurations,
    string $engine,
    string $firewallClient,
    string $image,
    string $seccomp,
    bool $expectAppArmor,
    string $token,
    array &$cleanup
): array {
    $networks = [];
    foreach ([1, 2] as $generation) {
        $configuration = $configurations[$generation - 1] ?? null;
        if (!is_string($configuration)) {
            throw new RuntimeException('firewall proof configuration registry is incomplete');
        }
        $name = 'duo-preview-net-' . hash('sha256', $token . '-network-' . $generation)
            . '-g' . sprintf('%010d', $generation);
        $bridge = HostFirewallAuthority::bridgeName($name);
        // Network creation can succeed even when inspect or its response is
        // lost, so register its deterministic removal identity first.
        $cleanup['networks'][] = ['network_name' => $name];
        $network = createNetwork(
            $runner,
            $engine,
            $name,
            $bridge,
            $configurations[0]
        );
        $network['configuration_sha256'] = $configuration;
        $networks[] = $network;
        // A bind may take effect even when its response is lost. Publish the
        // exact idempotent unbind intent before crossing that authority seam.
        $cleanup['firewalls'][] = [
            'configuration_sha256' => $configuration,
            'network' => $network,
        ];
        $bound = canonicalCommand(
            $runner,
            firewallArgv($firewallClient, 'bind', $configuration, $network),
            'firewall bind'
        );
        if (($bound['format'] ?? null) !== 'duo-cloud-host-firewall-state/v1'
            || ($bound['state'] ?? null) !== 'bound') {
            throw new RuntimeException('firewall bind response is invalid');
        }
    }
    foreach ($networks as $network) {
        $networkConfiguration = $network['configuration_sha256'] ?? null;
        if (!is_string($networkConfiguration)) {
            throw new RuntimeException('firewall proof network principal is invalid');
        }
        $readback = canonicalCommand(
            $runner,
            firewallArgv($firewallClient, 'inspect', $networkConfiguration, $network),
            'firewall inspect'
        );
        if (($readback['state'] ?? null) !== 'bound') {
            throw new RuntimeException('firewall multi-binding readback was clobbered');
        }
    }

    $listener = hostListener();
    $cleanup['listener'] = $listener;
    $container = 'duo-proof-net-' . substr($token, 0, 16);
    $cleanup['containers'][] = $container;
    $server = <<<'PHP'
$server=stream_socket_server('tcp://0.0.0.0:8080',$errno,$message);
if(!is_resource($server))exit(70);
while(true){$client=stream_socket_accept($server,30);if(!is_resource($client))continue;
$line=fgets($client);while(is_string($header=fgets($client))&&$header!=="\r\n"){}
$body="duo-cloud-preview-runtime-health/v1\n";
fwrite($client,"HTTP/1.1 200 OK\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);fclose($client);}
PHP;
    mustRun($runner, [
        $engine, 'container', 'create', '--name', $container, '--pull', 'never',
        '--label', 'duo.cloud.host-boundary-proof=' . $configurations[0],
        '--user', '10001:10001', '--read-only', '--cap-drop', 'ALL',
        '--security-opt', 'no-new-privileges=true', '--security-opt', 'seccomp=' . $seccomp,
        '--network', $networks[0]['network_name'], '--dns', DNS_SERVER,
        '--dns-search', '.', '--dns-opt', 'timeout:1', '--dns-opt', 'attempts:1',
        '--pids-limit', '32', '--memory', '134217728', '--memory-swap', '134217728',
        '--shm-size', '8388608', '--ulimit', 'nofile=256:256',
        '--tmpfs', '/tmp:rw,noexec,nosuid,nodev,size=8388608,mode=0700,uid=10001,gid=10001',
        '--entrypoint', '/usr/local/bin/php', $image, '-r', $server,
    ], 'firewall proof container create');
    mustRun($runner, [$engine, 'container', 'start', $container], 'firewall proof container start');
    $inspection = jsonCommand($runner, [
        $engine, 'container', 'inspect', '--format', '{{json .}}', $container,
    ], 'firewall proof container inspect');
    $host = $inspection['HostConfig'] ?? null;
    $ip = $inspection['NetworkSettings']['Networks'][$networks[0]['network_name']]['IPAddress'] ?? null;
    $apparmorProfile = $inspection['AppArmorProfile'] ?? null;
    if (!is_array($host) || ($host['Dns'] ?? null) !== [DNS_SERVER]
        || ($host['DnsSearch'] ?? null) !== ['.']
        || ($host['DnsOptions'] ?? null) !== ['timeout:1', 'attempts:1']
        || !is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || ($expectAppArmor && $apparmorProfile !== 'docker-default')
        || (!$expectAppArmor && $apparmorProfile !== '')) {
        throw new RuntimeException('firewall proof container DNS/network readback differs');
    }
    $activeProfile = $runner->run([
        $engine, 'container', 'exec', '--user', '10001:10001', $container,
        '/bin/cat', '/proc/1/attr/current',
    ]);
    if (($expectAppArmor && ($activeProfile['exit'] !== 0 || $activeProfile['stderr'] !== ''
            || $activeProfile['stdout'] !== "docker-default (enforce)\n"))
        || (!$expectAppArmor && ($activeProfile['exit'] === 0
            || $activeProfile['stdout'] !== ''))) {
        throw new RuntimeException('workload active AppArmor profile differs');
    }

    $negative = <<<'PHP'
ini_set('default_socket_timeout','2');
function udp_response(string $host,int $port,string $payload):bool{
    $socket=@stream_socket_client("udp://$host:$port",$e,$m,1);
    if(!is_resource($socket))return false;
    stream_set_timeout($socket,1);fwrite($socket,$payload);$reply=fread($socket,512);fclose($socket);
    return is_string($reply)&&$reply!=='';
}
$gateway=$argv[1];$tcpPort=(int)$argv[2];$udpPort=(int)$argv[3];
$host=@stream_socket_client("tcp://$gateway:$tcpPort",$e,$m,1);
if(is_resource($host))exit(10);
if(udp_response($gateway,$udpPort,"probe\n"))exit(11);
$external=@stream_socket_client('tcp://1.1.1.1:80',$e,$m,1);
if(is_resource($external))exit(12);
$dnsTcp=@stream_socket_client('tcp://1.1.1.1:53',$e,$m,1);
if(is_resource($dnsTcp))exit(13);
$query=pack('n6',0x4455,0x0100,1,0,0,0)."\x07example\x03com\x00".pack('n2',1,1);
if(udp_response('1.1.1.1',53,$query))exit(14);
$resolved=gethostbyname('example.com');
if($resolved!=='example.com')exit(15);
echo "gateway-tcp=denied\ngateway-udp=denied\nexternal-tcp=denied\ndns-tcp=denied\ndns-udp=denied\n";
PHP;
    $denied = mustRun($runner, [
        $engine, 'container', 'exec', '--user', '10001:10001', $container,
        '/usr/local/bin/php', '-r', $negative, '--',
        $networks[0]['gateway'], (string) $listener['tcp_port'], (string) $listener['udp_port'],
    ], 'workload egress denial', 10)['stdout'];
    if ($denied !== "gateway-tcp=denied\ngateway-udp=denied\nexternal-tcp=denied\n"
        . "dns-tcp=denied\ndns-udp=denied\n") {
        throw new RuntimeException('workload egress denial output is invalid');
    }

    $body = null;
    $context = stream_context_create(['http' => ['timeout' => 1.0]]);
    for ($attempt = 0; $attempt < 40; $attempt++) {
        $candidate = @file_get_contents(
            'http://' . $ip . ':8080/__duo/health',
            false,
            $context
        );
        if (is_string($candidate)) {
            $body = $candidate;
            break;
        }
        usleep(50000);
    }
    if ($body !== HEALTH) {
        throw new RuntimeException('host-to-workload health ingress failed');
    }

    canonicalCommand(
        $runner,
        firewallArgv(
            $firewallClient,
            'unbind',
            $networks[1]['configuration_sha256'],
            $networks[1]
        ),
        'second firewall unbind'
    );
    array_pop($cleanup['firewalls']);
    $first = canonicalCommand(
        $runner,
        firewallArgv(
            $firewallClient,
            'inspect',
            $networks[0]['configuration_sha256'],
            $networks[0]
        ),
        'remaining firewall inspect'
    );
    if (($first['state'] ?? null) !== 'bound') {
        throw new RuntimeException('second firewall unbind clobbered the remaining site');
    }
    return [
        'apparmor' => $expectAppArmor ? 'docker-default-enforced' : 'missing',
        'dns_tcp' => 'denied',
        'dns_udp' => 'denied',
        'external_tcp' => 'denied',
        'gateway_tcp' => 'denied',
        'gateway_udp' => 'denied',
        'host_ingress' => 'exact-health',
        'multi_binding' => 'preserved',
    ];
}

/** @return non-empty-list<string> */
function storageProofArgv(
    string $client,
    string $action,
    string $configuration,
    string $proofId
): array {
    return [
        $client, 'proof-' . $action, '--config-sha256', $configuration,
        '--proof-id', $proofId,
    ];
}

/** @return array{hard_bytes:int,hard_inodes:int,ioctl_mutation:string,proof_receipt_sha256:string,quota_state:string} */
function proveStorage(
    NativeContainerArgvProcessRunner $runner,
    string $configuration,
    string $engine,
    string $storageClient,
    string $image,
    string $seccomp,
    string $storageRoot,
    string $token,
    array &$cleanup
): array {
    $preflight = canonicalCommand($runner, [
        $storageClient, 'preflight', '--config-sha256', $configuration,
        '--required-path', $storageRoot,
    ], 'storage encrypted-root preflight');
    if (($preflight['format'] ?? null) !== 'duo-cloud-xfs-quota-storage-state/v1'
        || ($preflight['state'] ?? null) !== 'ready') {
        throw new RuntimeException('storage encrypted-root preflight response is invalid');
    }
    $hard = STORAGE_PROOF_BYTES;
    $hardInodes = STORAGE_PROOF_INODES;
    // The proof identity is stable per registered worker. A SIGKILL after the
    // journaled bind is therefore recovered by the next exact run rather than
    // consuming the singleton proof namespace with an undiscoverable nonce.
    $proofId = hash('sha256', "duo-cloud-host-storage-proof/v1\0" . $configuration);
    $database = 'duo-proof-db-' . $proofId;
    $filesystem = 'duo-proof-fs-' . $proofId;
    $cleanup['storage'] = $proofId;
    foreach (['database' => $database, 'filesystem' => $filesystem] as $kind => $volume) {
        // Volume creation can succeed before its stdout is delivered.
        $cleanup['volumes'][] = $volume;
        mustRun($runner, [
            $engine, 'volume', 'create', '--driver', 'local',
            '--label', 'duo.cloud.configuration-sha256=' . $configuration,
            '--label', 'duo.cloud.data-kind=' . $kind,
            '--label', 'duo.cloud.host-boundary-proof=' . $configuration,
            '--label', 'duo.cloud.proof-id=' . $proofId,
            '--label', 'duo.cloud.storage-purpose=host-preflight-proof',
            $volume,
        ], 'quota proof volume create');
    }
    // As with nft, a lost sudo response must not strand project assignments.
    $bound = canonicalCommand(
        $runner,
        storageProofArgv($storageClient, 'bind', $configuration, $proofId),
        'storage quota bind'
    );
    $receipt = $bound['receipt_sha256'] ?? null;
    if (($bound['format'] ?? null) !== STORAGE_PROOF_FORMAT
        || ($bound['state'] ?? null) !== 'bound'
        || !is_string($receipt) || preg_match('/\A[a-f0-9]{64}\z/D', $receipt) !== 1) {
        throw new RuntimeException('storage quota bind response is invalid');
    }

    $fillContainer = 'duo-proof-quota-' . substr($token, 0, 16);
    $cleanup['containers'][] = $fillContainer;
    $script = <<<'SH'
set -eu
test "$(id -u)" = 10001
test "$(stat -c '%u:%g:%a' /var/lib/duo/database)" = '10001:10001:700'
grep -Eq '^CapEff:[[:space:]]+0+$' /proc/self/status
grep -Eq '^NoNewPrivs:[[:space:]]+1$' /proc/self/status
grep -Eq '^Seccomp:[[:space:]]+2$' /proc/self/status
/opt/duo/bin/containment-canary /var/lib/duo/database/containment-canary
hard="$1"
step=1048576
count=0
while next=$((count + 1)) && error=$(/usr/bin/fallocate -l "$step" "/var/lib/duo/database/chunk-$next" 2>&1); do
    count="$next"
done
allocated=$((count * step))
test "$allocated" -ge $((hard - 2 * step))
test "$allocated" -le "$hard"
if error=$(/bin/dd if=/dev/zero of=/var/lib/duo/database/final-fill bs=4096 count=512 conv=fsync 2>&1); then exit 73; fi
set -- $(/bin/df -B1 --output=size,used,avail /var/lib/duo/database | /usr/bin/tail -n 1)
test "$1" -eq "$hard"
test "$2" -ge $((hard - step))
test "$3" -le "$step"
if error=$(/bin/dd if=/dev/zero of=/var/lib/duo/database/beyond-hard bs=4096 count=1 conv=fsync 2>&1); then exit 74; fi
printf 'quota-bytes=%s\n' "$2"
SH;
    $result = mustRun($runner, [
        $engine, 'container', 'run', '--name', $fillContainer, '--pull', 'never', '--read-only',
        '--label', 'duo.cloud.host-boundary-proof=' . $configuration,
        '--user', '10001:10001', '--cap-drop', 'ALL', '--security-opt',
        'no-new-privileges=true', '--security-opt', 'seccomp=' . $seccomp,
        '--network', 'none', '--pids-limit', '32', '--memory', '134217728',
        '--memory-swap', '134217728', '--shm-size', '8388608',
        '--ulimit', 'nofile=256:256', '--mount',
        'type=volume,source=' . $database . ',target=/var/lib/duo/database',
        '--entrypoint', '/bin/sh', $image, '-ceu', $script, '--', (string) $hard,
    ], 'capless quota fill', 45);
    if (!str_starts_with($result['stdout'], "duo-cloud-seccomp-ioctl-canary/v1\nquota-bytes=")) {
        throw new RuntimeException('capless quota/ioctl proof output is invalid');
    }

    $inodeContainer = 'duo-proof-inodes-' . substr($token, 0, 16);
    $cleanup['containers'][] = $inodeContainer;
    $inodeScript = <<<'SH'
set -eu
hard="$1"
test "$(stat -c '%u:%g:%a' /var/lib/duo/wordpress)" = '10001:10001:700'
count=0
while :; do
    next=$((count + 1))
    if /usr/bin/touch "/var/lib/duo/wordpress/i-$next" 2>/dev/null; then
        count="$next"
        continue
    fi
    break
done
test "$count" -eq $((hard - 1))
if /usr/bin/touch /var/lib/duo/wordpress/beyond-hard 2>/dev/null; then exit 74; fi
printf 'quota-inodes=%s\n' "$count"
SH;
    $inodeResult = mustRun($runner, [
        $engine, 'container', 'run', '--name', $inodeContainer, '--pull', 'never', '--read-only',
        '--label', 'duo.cloud.host-boundary-proof=' . $configuration,
        '--user', '10001:10001', '--cap-drop', 'ALL', '--security-opt',
        'no-new-privileges=true', '--security-opt', 'seccomp=' . $seccomp,
        '--network', 'none', '--pids-limit', '32', '--memory', '134217728',
        '--memory-swap', '134217728', '--shm-size', '8388608',
        '--ulimit', 'nofile=256:256', '--mount',
        'type=volume,source=' . $filesystem . ',target=/var/lib/duo/wordpress',
        '--entrypoint', '/bin/sh', $image, '-ceu', $inodeScript, '--', (string) $hardInodes,
    ], 'capless quota inode fill', 60);
    if (!str_starts_with($inodeResult['stdout'], 'quota-inodes=')) {
        throw new RuntimeException('capless inode quota proof output is invalid');
    }
    $readback = canonicalCommand(
        $runner,
        storageProofArgv($storageClient, 'inspect', $configuration, $proofId),
        'storage quota post-fill inspect'
    );
    if (($readback['format'] ?? null) !== STORAGE_PROOF_FORMAT
        || ($readback['state'] ?? null) !== 'bound'
        || ($readback['receipt_sha256'] ?? null) !== $receipt) {
        throw new RuntimeException('storage post-fill quota readback differs');
    }
    return [
        'hard_bytes' => $hard,
        'hard_inodes' => $hardInodes,
        'ioctl_mutation' => 'denied',
        'proof_receipt_sha256' => $receipt,
        'quota_state' => 'hard-enforced',
    ];
}

function removeHostProofDockerResource(
    ContainerArgvProcessRunner $runner,
    string $engine,
    string $kind,
    string $name,
    string $configuration
): void {
    if (!in_array($kind, ['container', 'network', 'volume'], true)
        || preg_match('/\A[a-z0-9][a-z0-9_.-]{0,127}\z/D', $name) !== 1
        || preg_match('/\A[a-f0-9]{64}\z/D', $configuration) !== 1) {
        throw new RuntimeException('host proof Docker cleanup identity is invalid');
    }
    $list = match ($kind) {
        'container' => [
            $engine, 'container', 'ls', '--all', '--filter', 'name=^/' . $name . '$',
            '--format', '{{.Names}}',
        ],
        'network' => [
            $engine, 'network', 'ls', '--filter', 'name=^' . $name . '$',
            '--format', '{{.Name}}',
        ],
        'volume' => [
            $engine, 'volume', 'ls', '--filter', 'name=^' . $name . '$',
            '--format', '{{.Name}}',
        ],
    };
    $present = mustRun($runner, $list, "$kind startup cleanup readback")['stdout'];
    if ($present === '') {
        return;
    }
    if ($present !== $name . "\n") {
        throw new RuntimeException("host proof $kind cleanup readback is ambiguous");
    }
    $inspection = jsonCommand($runner, [
        $engine, $kind, 'inspect', '--format', '{{json .}}', $name,
    ], "$kind startup cleanup inspection");
    $actualName = $kind === 'container'
        ? ltrim((string) ($inspection['Name'] ?? ''), '/')
        : ($inspection['Name'] ?? null);
    $labels = $kind === 'container'
        ? ($inspection['Config']['Labels'] ?? null)
        : ($inspection['Labels'] ?? null);
    if ($actualName !== $name || !is_array($labels)
        || ($labels['duo.cloud.host-boundary-proof'] ?? null) !== $configuration) {
        throw new RuntimeException("host proof $kind cleanup found foreign state");
    }
    $remove = match ($kind) {
        'container' => [$engine, 'container', 'rm', '--force', $name],
        'network' => [$engine, 'network', 'rm', $name],
        'volume' => [$engine, 'volume', 'rm', $name],
    };
    $removed = $runner->run($remove, null, 10);
    if ($removed['exit'] !== 0 || $removed['stderr'] !== '') {
        throw new RuntimeException("host proof $kind cleanup failed");
    }
    if (mustRun($runner, $list, "$kind terminal cleanup readback")['stdout'] !== '') {
        throw new RuntimeException("host proof $kind remained after cleanup");
    }
}

function reconcileHostProofScratch(string $path): void {
    if (preg_match('#\A/run/duo-cloud-host-proof/work-[a-f0-9]{32}\z#D', $path) !== 1) {
        throw new RuntimeException('host proof scratch cleanup identity is invalid');
    }
    clearstatcache(true, $path);
    $stat = @lstat($path);
    if ($stat === false) {
        return;
    }
    $uid = function_exists('posix_geteuid') ? posix_geteuid() : null;
    if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0040000
        || ($stat['mode'] & 0077) !== 0
        || ($uid !== null && (int) ($stat['uid'] ?? -1) !== $uid)) {
        throw new RuntimeException('host proof scratch cleanup found foreign state');
    }
    removeTree($path);
}

/** @param array<string,mixed> $cleanup */
function cleanup(
    ContainerArgvProcessRunner $runner,
    string $configuration,
    string $engine,
    string $firewallClient,
    string $storageClient,
    array $cleanup,
    bool $allowTransientBusy = false
): void {
    $failed = false;
    $busy = false;
    $storageBusy = false;
    $firewallBusy = false;
    stopListener($cleanup['listener'] ?? null);
    foreach (array_reverse($cleanup['containers'] ?? []) as $container) {
        try {
            if (!is_string($container)) {
                throw new RuntimeException('container cleanup identity is invalid');
            }
            removeHostProofDockerResource(
                $runner,
                $engine,
                'container',
                $container,
                $configuration
            );
        } catch (LinuxHostProofTransientBusy) {
            $busy = true;
        } catch (Throwable) {
            $failed = true;
        }
    }
    if (is_string($cleanup['storage'] ?? null)) {
        $proofId = $cleanup['storage'];
        try {
            $result = canonicalCommand(
                $runner,
                storageProofArgv($storageClient, 'unbind', $configuration, $proofId),
                'storage cleanup unbind'
            );
            $failed = $failed || ($result['state'] ?? null) !== 'absent';
        } catch (LinuxHostProofTransientBusy) {
            $busy = true;
            $storageBusy = true;
        } catch (Throwable) {
            $failed = true;
        }
    }
    foreach ($storageBusy ? [] : array_reverse($cleanup['volumes'] ?? []) as $volume) {
        try {
            if (!is_string($volume)) {
                throw new RuntimeException('volume cleanup identity is invalid');
            }
            removeHostProofDockerResource(
                $runner,
                $engine,
                'volume',
                $volume,
                $configuration
            );
        } catch (Throwable) {
            $failed = true;
        }
    }
    foreach (array_reverse($cleanup['firewalls'] ?? []) as $binding) {
        try {
            $network = $binding['network'] ?? null;
            $bindingConfiguration = $binding['configuration_sha256'] ?? null;
            if (!is_array($network) || !is_string($bindingConfiguration)) {
                throw new RuntimeException('firewall cleanup identity is invalid');
            }
            $result = canonicalCommand(
                $runner,
                firewallArgv($firewallClient, 'unbind', $bindingConfiguration, $network),
                'firewall cleanup unbind'
            );
            $failed = $failed || ($result['state'] ?? null) !== 'absent';
        } catch (LinuxHostProofTransientBusy) {
            $busy = true;
            $firewallBusy = true;
        } catch (Throwable) {
            $failed = true;
        }
    }
    foreach ($firewallBusy ? [] : array_reverse($cleanup['networks'] ?? []) as $network) {
        try {
            $networkName = $network['network_name'] ?? null;
            if (!is_string($networkName)) {
                throw new RuntimeException('network cleanup identity is invalid');
            }
            removeHostProofDockerResource(
                $runner,
                $engine,
                'network',
                $networkName,
                $configuration
            );
        } catch (Throwable) {
            $failed = true;
        }
    }
    if ($busy && !$allowTransientBusy) {
        $failed = true;
    }
    if ($failed) {
        throw new RuntimeException('host verifier could not prove exact cleanup');
    }
    if ($busy) {
        throw new LinuxHostProofTransientBusy('host verifier cleanup authority is busy');
    }
}

/** @param array<string,mixed> $cleanup */
function finalizeHostProofCleanup(
    ContainerArgvProcessRunner $runner,
    string $configuration,
    string $engine,
    string $firewallClient,
    string $storageClient,
    array $cleanup,
    string $scratch
): void {
    $cleanupError = null;
    try {
        cleanup(
            $runner,
            $configuration,
            $engine,
            $firewallClient,
            $storageClient,
            $cleanup,
            true
        );
    } catch (Throwable $error) {
        $cleanupError = $error;
    }
    try {
        removeTree($scratch);
    } catch (Throwable $error) {
        throw new RuntimeException('host verifier could not prove exact cleanup', 0, $error);
    }
    if ($cleanupError instanceof Throwable) {
        throw $cleanupError;
    }
}

if (defined('DUO_LINUX_HOST_VERIFIER_LIBRARY_ONLY')) {
    return;
}

$deploymentProofs = null;
$strictHostRoot = null;
$strictConfiguration = null;
try {
    $proofLock = acquireProofLock();
    $declaredLinuxHostSources = ProductionDeploymentProofs::linuxHostVerifierSourcePaths(__DIR__);
    if ($declaredLinuxHostSources !== $linuxHostSourcePaths) {
        throw new RuntimeException('host verifier PHP closure registry differs from loaded sources');
    }
    $loadedLinuxHostSources = [];
    foreach (get_included_files() as $included) {
        $canonicalIncluded = realpath($included);
        if (is_string($canonicalIncluded)
            && str_starts_with($canonicalIncluded, dirname(__DIR__) . '/')) {
            $loadedLinuxHostSources[] = $canonicalIncluded;
        }
    }
    $expectedLinuxHostSources = array_values($linuxHostSourcePaths);
    sort($loadedLinuxHostSources, SORT_STRING);
    sort($expectedLinuxHostSources, SORT_STRING);
    if ($loadedLinuxHostSources !== $expectedLinuxHostSources) {
        throw new RuntimeException('host verifier loaded PHP closure is incomplete or expanded');
    }
    $linuxHostSourceDigests = [];
    foreach ($linuxHostSourceIdentities as $name => $identity) {
        $linuxHostSourceDigests[$name] = $identity['sha256'];
    }
    $linuxHostPhpClosureSha256 = ProductionDeploymentProofs::linuxHostVerifierClosureSha256(
        $linuxHostSourceDigests
    );
    $options = options($_SERVER['argv'] ?? []);
    $configurationFile = $options['--configuration-file'];
    $configuration = $options['--configuration-sha256'];
    $hostPreflightRoot = $options['--host-preflight-root'];
    $syntheticRotation = $options['--synthetic-rotation-configuration-sha256'];
    if (!is_string($configurationFile) || !is_string($configuration)
        || !is_string($hostPreflightRoot)
        || !is_string($syntheticRotation) || !function_exists('posix_geteuid')
        || posix_geteuid() !== 10001) {
        throw new RuntimeException('host verifier installation or identity is invalid');
    }
    $nonproductionDiagnostic = (
        $options['--allow-nonproduction-missing-apparmor'] ?? false
    ) === true;
    if (!$nonproductionDiagnostic) {
        $strictHostRoot = $hostPreflightRoot;
        $strictConfiguration = $configuration;
    }
    $canonicalConfigurationFile = realpath($configurationFile);
    if (!is_string($canonicalConfigurationFile)
        || $canonicalConfigurationFile !== $configurationFile) {
        throw new RuntimeException('host verifier production configuration path is not canonical');
    }
    $configurationIdentity = pinnedRuntimeFile(
        ['path' => $configurationFile, 'sha256' => $configuration],
        'production configuration',
        1048576,
        false
    );
    $productionConfig = ProductionConfig::inspectForFleet($configurationFile, $configuration);
    if ($productionConfig->hostPreflightRoot() !== $hostPreflightRoot) {
        throw new RuntimeException('host verifier preflight root differs from production configuration');
    }
    if ($productionConfig->authorityConfigRoot() !== '/var/lib/duo-cloud/config') {
        throw new RuntimeException(
            'host verifier authority configuration root differs from installed clients'
        );
    }
    $installedConfigurationSnapshot =
        ProductionDeploymentProofs::linuxHostInstalledConfigurationSnapshot(
            $productionConfig
        );
    if (!$nonproductionDiagnostic) {
        $deploymentProofs = new ProductionDeploymentProofs($productionConfig);
        $deploymentProofs->beginLinuxHostVerification();
    }
    $runtime = $productionConfig->runtime();
    $configuration = $productionConfig->sha256();
    $expectedSyntheticRotation = hash(
        'sha256',
        "duo-cloud-linux-host-boundary-proof-synthetic-rotation/v1\0" . $configuration
    );
    if (!hash_equals($expectedSyntheticRotation, $syntheticRotation)) {
        throw new RuntimeException('host verifier synthetic rotation identity is invalid');
    }
    $engineIdentity = pinnedRuntimeFile(
        $runtime['container_engine'] ?? null,
        'container engine',
        67108864,
        true
    );
    $processLauncherIdentity = pinnedRuntimeFile(
        $runtime['process_launcher'] ?? null,
        'process launcher',
        67108864,
        true
    );
    $firewallClientIdentity = pinnedRuntimeFile(
        $runtime['firewall_authority'] ?? null,
        'firewall client',
        67108864,
        true
    );
    $storageClientIdentity = pinnedRuntimeFile(
        $runtime['storage_authority'] ?? null,
        'storage client',
        67108864,
        true
    );
    $seccompIdentity = pinnedRuntimeFile([
        'path' => $runtime['seccomp_profile_file'] ?? null,
        'sha256' => $runtime['seccomp_profile_sha256'] ?? null,
    ], 'seccomp profile', 1048576, false);
    $imageReference = $runtime['image'] ?? null;
    $storageRoot = $productionConfig->workerRoot();
    $canonicalStorageRoot = realpath($storageRoot);
    if (!is_string($imageReference) || !is_string($canonicalStorageRoot)
        || $canonicalStorageRoot !== $storageRoot) {
        throw new RuntimeException('host verifier derived runtime identity is invalid');
    }
    $engineBinary = $engineIdentity['path'];
    $processLauncher = $processLauncherIdentity['path'];
    $firewallClient = $firewallClientIdentity['path'];
    $storageClient = $storageClientIdentity['path'];
    $seccomp = $seccompIdentity['path'];
    $activePhpBinary = realpath(PHP_BINARY);
    if (!is_string($activePhpBinary) || $activePhpBinary !== $processLauncher) {
        throw new RuntimeException('host verifier interpreter differs from production configuration');
    }
    $identityRunner = new NativeContainerArgvProcessRunner(
        30,
        $processLauncher,
        true,
        true
    );
    $engine = jsonCommand(
        $identityRunner,
        [$engineBinary, 'info', '--format', '{{json .}}'],
        'container engine info'
    );
    $security = $engine['SecurityOptions'] ?? null;
    $hasAppArmor = is_array($security) && in_array('name=apparmor', $security, true);
    if ((!is_array($security) || !in_array('name=seccomp,profile=builtin', $security, true)
            || !is_string($engine['ServerVersion'] ?? null)
            || version_compare($engine['ServerVersion'], '26.0.0', '<'))
        || (!$hasAppArmor && !$nonproductionDiagnostic)) {
        throw new RuntimeException('host verifier requires the reviewed Docker version, seccomp, and AppArmor');
    }
    $imageReadback = jsonCommand(
        $identityRunner,
        [$engineBinary, 'image', 'inspect', '--format', '{{json .}}', $imageReference],
        'immutable runtime image inspect'
    );
    $imageId = $imageReadback['Id'] ?? null;
    $repositoryDigests = $imageReadback['RepoDigests'] ?? null;
    if (!is_string($imageId) || preg_match('/\Asha256:[a-f0-9]{64}\z/D', $imageId) !== 1
        || !is_array($repositoryDigests) || !array_is_list($repositoryDigests)
        || !in_array($imageReference, $repositoryDigests, true)) {
        throw new RuntimeException('immutable runtime image reference readback differs');
    }

    $runner = new NativeContainerArgvProcessRunner(60, $processLauncher, true, true);
    $recovery = hostProofCleanupPlan($configuration, $syntheticRotation);
    cleanup(
        $runner,
        $configuration,
        $engineBinary,
        $firewallClient,
        $storageClient,
        $recovery,
        true
    );
    $root = $recovery['scratch'];
    if (!is_string($root)) {
        throw new RuntimeException('host verifier recovery scratch identity is invalid');
    }
    reconcileHostProofScratch($root);
    if (!mkdir($root, 0700) || !chmod($root, 0700)) {
        throw new RuntimeException('host verifier private root could not be created');
    }
    $cleanup = ['containers' => [], 'firewalls' => [], 'networks' => [], 'volumes' => []];
    $proof = null;
    $terminalExit = $nonproductionDiagnostic ? 78 : 0;
    try {
        $token = hash(
            'sha256',
            "duo-cloud-linux-host-boundary-proof-run/v1\0" . $configuration
        );
        $cgroup = proveCgroup($runner, $root, $processLauncher);
        $firewall = proveFirewall(
            $runner,
            [$configuration, $syntheticRotation],
            $engineBinary,
            $firewallClient,
            $imageReference,
            $seccomp,
            $hasAppArmor,
            $token,
            $cleanup
        );
        $storage = proveStorage(
            $runner,
            $configuration,
            $engineBinary,
            $storageClient,
            $imageReference,
            $seccomp,
            $storageRoot,
            $token,
            $cleanup
        );
        $artifactSha256s = [
            'container_engine_sha256' => $engineIdentity['sha256'],
            'firewall_authority_sha256' => $firewallClientIdentity['sha256'],
            'php_closure_sha256' => $linuxHostPhpClosureSha256,
            'process_launcher_sha256' => $processLauncherIdentity['sha256'],
            'seccomp_profile_sha256' => $seccompIdentity['sha256'],
            'storage_authority_sha256' => $storageClientIdentity['sha256'],
            'verifier_sha256' => $linuxHostSourceDigests[
                'deploy/verify-linux-host-boundaries.php'
            ],
        ] + $installedConfigurationSnapshot['artifact_sha256s'];
        ksort($artifactSha256s, SORT_STRING);
        $proof = [
            'apparmor' => $firewall['apparmor'],
            'artifact_sha256s' => $artifactSha256s,
            'cgroup' => $cgroup,
            'configuration_file' => $canonicalConfigurationFile,
            'configuration_sha256' => $configuration,
            'container_engine_path' => $engineBinary,
            'container_engine_sha256' => $engineIdentity['sha256'],
            'engine_version' => $engine['ServerVersion'],
            'firewall' => $firewall,
            'format' => 'duo-cloud-linux-host-boundary-proof/v1',
            'image_id' => $imageId,
            'image_reference' => $imageReference,
            'production_ready' => $hasAppArmor && !$nonproductionDiagnostic,
            'proof_scope' => $nonproductionDiagnostic ? 'nonproduction-diagnostic' : 'production',
            'seccomp_profile_path' => $seccomp,
            'seccomp_profile_sha256' => $seccompIdentity['sha256'],
            'storage' => $storage,
            'storage_worker_root' => $storageRoot,
            'synthetic_rotation_configuration_sha256' => $syntheticRotation,
            'synthetic_rotation_scope' => 'firewall-multi-binding-only',
        ];
    } finally {
        finalizeHostProofCleanup(
            $runner,
            $configuration,
            $engineBinary,
            $firewallClient,
            $storageClient,
            $cleanup,
            $root
        );
    }
    if (!is_array($proof)) {
        throw new RuntimeException('host verifier proof was not produced');
    }
    foreach ([
        [$configurationIdentity, 'production configuration', 1048576, false],
        [$engineIdentity, 'container engine', 67108864, true],
        [$processLauncherIdentity, 'process launcher', 67108864, true],
        [$firewallClientIdentity, 'firewall client', 67108864, true],
        [$storageClientIdentity, 'storage client', 67108864, true],
        [$seccompIdentity, 'seccomp profile', 1048576, false],
    ] as [$identity, $label, $limit, $executable]) {
        revalidateHostProofFile($identity, $label, $limit, $executable);
    }
    foreach ($linuxHostSourceIdentities as $name => $identity) {
        revalidateHostProofFile(
            $identity,
            "Linux host verifier source $name",
            2097152,
            false
        );
    }
    $proof['cleanup'] = 'exact';
    ProductionDeploymentProofs::assertLinuxHostInstalledConfigurationSnapshotCurrent(
        $installedConfigurationSnapshot
    );
    if (!$nonproductionDiagnostic) {
        if (!$deploymentProofs instanceof ProductionDeploymentProofs) {
            throw new RuntimeException('host verifier durable proof authority is unavailable');
        }
        $receipt = $deploymentProofs->publishLinuxHost($proof);
        $proof['durable_receipt_sha256'] = $receipt['receipt_sha256'];
    }
    fwrite(STDOUT, CanonicalJson::encode($proof) . "\n");
    flock($proofLock, LOCK_UN);
    fclose($proofLock);
    exit($terminalExit);
} catch (LinuxHostProofTransientBusy) {
    fwrite(STDERR, "duo-cloud-linux-host-boundaries: busy\n");
    exit(HostAuthorityBusy::EXIT_STATUS);
} catch (Throwable) {
    if ($deploymentProofs instanceof ProductionDeploymentProofs) {
        try {
            $deploymentProofs->failLinuxHostVerification();
        } catch (Throwable) {
        }
    } elseif (is_string($strictHostRoot) && is_string($strictConfiguration)) {
        try {
            ProductionDeploymentProofs::invalidateLinuxHostAt(
                $strictHostRoot,
                $strictConfiguration,
                10001
            );
        } catch (Throwable) {
        }
    }
    fwrite(STDERR, "duo-cloud-linux-host-boundaries: refused\n");
    exit(70);
}
