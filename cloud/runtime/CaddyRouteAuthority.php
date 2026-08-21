<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/RouteAuthorityConfig.php';
require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/ContainerWorkloadRuntime.php';
require_once dirname(__DIR__) . '/src/ControlRefusal.php';
require_once dirname(__DIR__) . '/src/FileAuthorityStore.php';
require_once dirname(__DIR__) . '/src/HostAuthorityBusy.php';

/**
 * Dedicated-host Caddy authority for exact, TLS-verified preview routes.
 *
 * The authority owns the complete Caddy JSON document. Applying intent is
 * durable before reload, so a killed bind/unbind is reconciled by its replay.
 */
final class CaddyRouteAuthority {
    private const HOST_PREFLIGHT_FORMAT = 'duo-cloud-route-authority-host-preflight/v1';
    private const OUTPUT_FORMAT = 'duo-cloud-preview-route-state/v1';
    private const STORE_FORMAT = 'duo-cloud-route-authority-store/v1';
    private const WORKER_PREFLIGHT_FORMAT = 'duo-cloud-route-authority-worker-preflight/v1';
    private const CONFIG_LIMIT = 1048576;

    private FileAuthorityStore $store;
    private string $activeConfigPath;

    public function __construct(
        private RouteAuthorityConfig $config,
        private ContainerArgvProcessRunner $runner
    ) {
        $stateRoot = $config->get('state_root');
        if (!is_string($stateRoot)) {
            throw new ControlRefusal('route authority state root is invalid');
        }
        $this->store = new FileAuthorityStore($stateRoot . '/routes.json');
        $this->activeConfigPath = $stateRoot . '/caddy-active.json';
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function execute(string $action, array $input): array {
        if (!in_array($action, [
            'bind', 'host-preflight', 'inspect', 'unbind', 'worker-preflight',
        ], true)) {
            throw new ControlRefusal('route authority action is outside the closed protocol');
        }
        if ($action === 'host-preflight') {
            self::exactKeys($input, [], 'route authority host preflight input');
            return $this->hostPreflight();
        }
        if ($action === 'worker-preflight') {
            return $this->workerPreflight($input);
        }
        $common = $this->commonInput($input, $action === 'bind');
        return $this->store->locked(function (AuthorityStateSession $session) use (
            $action,
            $common
        ): array {
            $state = $this->state($session->state());
            $routeId = $common['route_id'];
            $existing = $state['routes'][$routeId] ?? null;

            if ($action === 'bind') {
                $record = $this->boundRecord($common);
                if (is_array($existing)) {
                    $this->assertSameBinding($existing, $record);
                } else {
                    $existing = $record + ['state' => 'applying-bind'];
                    $state['routes'][$routeId] = $existing;
                    $this->assertConfigSize($state, $routeId, true);
                    $session->save($state);
                }
                if (($existing['state'] ?? null) === 'applying-unbind') {
                    throw new ControlRefusal('route is already revoking a previous exact binding');
                }
                $this->assertUpstream($existing);
                $existing['state'] = 'applying-bind';
                $state['routes'][$routeId] = $existing;
                $session->save($state);
                $this->applyAndVerify($state, $routeId, true);
                $this->assertUpstream($existing);
                $existing['state'] = 'bound';
                $state['routes'][$routeId] = $existing;
                $session->save($state);
                return $this->output($existing);
            }

            if (is_array($existing)
                && (($existing['configuration_sha256'] ?? null) !== $common['configuration_sha256']
                    || ($existing['reviewed_base_sha256'] ?? null) !== $common['reviewed_base_sha256'])) {
                throw new ControlRefusal('route id belongs to a different registered runtime principal');
            }

            if ($action === 'unbind') {
                if (!is_array($existing)) {
                    $this->applyAndVerify($state, $routeId, false);
                    return $this->absent($common);
                }
                $existing['state'] = 'applying-unbind';
                $state['routes'][$routeId] = $existing;
                $session->save($state);
                $this->applyAndVerify($state, $routeId, false);
                unset($state['routes'][$routeId]);
                $session->save($state);
                return $this->absent($common);
            }

            if (!is_array($existing)) {
                $this->verifyActiveConfig($state, $routeId, false);
                return $this->absent($common);
            }
            if (($existing['state'] ?? null) === 'applying-unbind') {
                $this->applyAndVerify($state, $routeId, false);
                unset($state['routes'][$routeId]);
                $session->save($state);
                return $this->absent($common);
            }
            $this->assertUpstream($existing);
            if (($existing['state'] ?? null) === 'applying-bind') {
                $this->applyAndVerify($state, $routeId, true);
                $existing['state'] = 'bound';
                $state['routes'][$routeId] = $existing;
                $session->save($state);
            } else {
                $this->verifyActiveConfig($state, $routeId, true);
                $this->verifyTls($existing['host']);
            }
            return $this->output($existing);
        });
    }

    /** @return array<string,mixed> */
    private function hostPreflight(): array {
        try {
            return $this->store->locked(function (AuthorityStateSession $session): array {
                $state = $this->state($session->state());
                foreach ($state['routes'] as $routeId => $record) {
                    $record = $this->assertedRecord($routeId, $record);
                    if (($record['state'] ?? null) === 'applying-unbind') {
                        unset($state['routes'][$routeId]);
                        continue;
                    }
                    $state['routes'][$routeId] = $record;
                }
                $this->applyAndVerify($state, '', false);
                foreach ($state['routes'] as &$record) {
                    $record['state'] = 'bound';
                }
                unset($record);
                $session->save($state);
                return [
                    'format' => self::HOST_PREFLIGHT_FORMAT,
                    'principals' => $this->config->get('principals'),
                    'routes' => count($state['routes']),
                    'state' => 'ready',
                ];
            });
        } catch (ControlRefusal $error) {
            if ($error->getMessage() === 'authority store lock is busy') {
                throw new HostAuthorityBusy('route authority lock is busy', 0, $error);
            }
            throw $error;
        }
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function workerPreflight(array $input): array {
        self::exactKeys($input, [
            'configuration_sha256', 'reviewed_base_sha256',
        ], 'route authority worker preflight input');
        $configurationSha256 = self::sha256(
            $input['configuration_sha256'] ?? null,
            'runtime configuration'
        );
        $reviewedBaseSha256 = self::sha256(
            $input['reviewed_base_sha256'] ?? null,
            'reviewed base'
        );
        $this->config->principal($configurationSha256, $reviewedBaseSha256);

        try {
            /** @var array<string,array<string,mixed>> $routes */
            $routes = $this->store->locked(function (AuthorityStateSession $session) use (
                $configurationSha256,
                $reviewedBaseSha256
            ): array {
                $state = $this->state($session->state());
                $snapshot = [];
                foreach ($state['routes'] as $routeId => $candidate) {
                    if (!is_array($candidate)
                        || ($candidate['configuration_sha256'] ?? null) !== $configurationSha256
                        || ($candidate['reviewed_base_sha256'] ?? null) !== $reviewedBaseSha256) {
                        continue;
                    }
                    $record = $this->assertedRecord($routeId, $candidate);
                    if (($record['state'] ?? null) !== 'bound') {
                        throw new ControlRefusal(
                            'route authority worker preflight found an unstable principal binding'
                        );
                    }
                    $snapshot[$routeId] = $record;
                }
                ksort($snapshot, SORT_STRING);
                return $snapshot;
            });
        } catch (ControlRefusal $error) {
            if ($error->getMessage() === 'authority store lock is busy') {
                throw new HostAuthorityBusy('route authority lock is busy', 0, $error);
            }
            throw $error;
        }

        foreach ($routes as $record) {
            $this->assertUpstream($record);
            $this->verifyTls($record['host']);
        }
        $bindings = [];
        foreach ($routes as $record) {
            $bindings[] = [
                'host' => $record['host'],
                'route_id' => $record['route_id'],
                'upstream_container' => $record['upstream_container'],
                'upstream_ip' => $record['upstream_ip'],
                'upstream_network' => $record['upstream_network'],
                'upstream_port' => $record['upstream_port'],
            ];
        }
        return [
            'configuration_sha256' => $configurationSha256,
            'format' => self::WORKER_PREFLIGHT_FORMAT,
            'reviewed_base_sha256' => $reviewedBaseSha256,
            'route_bindings_sha256' => hash(
                'sha256',
                "duo-cloud-route-authority-worker-preflight-bindings/v1\0"
                    . CanonicalJson::encode($bindings)
            ),
            'routes' => count($routes),
            'state' => 'ready',
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function commonInput(array $input, bool $binding): array {
        $expected = ['configuration_sha256', 'reviewed_base_sha256', 'route_id'];
        if ($binding) {
            $expected = array_merge($expected, [
                'host', 'upstream_container', 'upstream_network', 'upstream_port',
            ]);
        }
        self::exactKeys($input, $expected, 'route authority input');
        $configurationSha256 = self::sha256(
            $input['configuration_sha256'] ?? null,
            'runtime configuration'
        );
        $reviewedBaseSha256 = self::sha256(
            $input['reviewed_base_sha256'] ?? null,
            'reviewed base'
        );
        $principal = $this->config->principal($configurationSha256, $reviewedBaseSha256);
        $routeId = $input['route_id'] ?? null;
        if (!is_string($routeId)
            || preg_match('/\Aduo-preview-route-([a-f0-9]{64})\z/D', $routeId, $match) !== 1) {
            throw new ControlRefusal('route authority route id is invalid');
        }
        $result = [
            'configuration_sha256' => $configurationSha256,
            'reviewed_base_sha256' => $reviewedBaseSha256,
            'route_id' => $routeId,
        ];
        if (!$binding) {
            return $result;
        }
        $token = $match[1];
        $host = $input['host'] ?? null;
        $container = $input['upstream_container'] ?? null;
        $network = $input['upstream_network'] ?? null;
        $port = $input['upstream_port'] ?? null;
        $domain = $principal['preview_domain'];
        $expectedHost = 'p-' . substr($token, 0, 40) . '.' . $domain;
        $generationPattern = '-g([0-9]{10})';
        if ($host !== $expectedHost
            || !is_string($container)
            || preg_match('/\Aduo-preview-' . $token . $generationPattern . '\z/D', $container, $containerMatch) !== 1
            || !is_string($network)
            || preg_match('/\Aduo-preview-net-' . $token . $generationPattern . '\z/D', $network, $networkMatch) !== 1
            || $containerMatch[1] !== $networkMatch[1]
            || $port !== 8080) {
            throw new ControlRefusal('route authority binding does not match its derived generation identity');
        }
        return $result + [
            'host' => $host,
            'upstream_container' => $container,
            'upstream_network' => $network,
            'upstream_port' => $port,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function boundRecord(array $input): array {
        $inspection = $this->upstreamInspection(
            $input['upstream_container'],
            $input['upstream_network'],
            $input['upstream_port']
        );
        return $input + ['upstream_ip' => $inspection['ip']];
    }

    /** @param array<string,mixed> $record */
    private function assertUpstream(array $record): void {
        $inspection = $this->upstreamInspection(
            $record['upstream_container'],
            $record['upstream_network'],
            $record['upstream_port']
        );
        if (!hash_equals($record['upstream_ip'], $inspection['ip'])) {
            throw new ControlRefusal('route authority upstream address changed after binding intent');
        }
    }

    /** @return array{ip:string} */
    private function upstreamInspection(string $containerName, string $networkName, int $port): array {
        $engine = $this->descriptorPath('container_engine');
        $container = $this->jsonCommand([
            $engine, 'container', 'inspect', '--format', '{{json .}}', $containerName,
        ], 'route upstream container inspection');
        $networks = $container['NetworkSettings']['Networks'] ?? null;
        $networkKeys = is_array($networks) ? array_keys($networks) : [];
        $ip = is_array($networks) && is_array($networks[$networkName] ?? null)
            ? ($networks[$networkName]['IPAddress'] ?? null)
            : null;
        if (($container['Name'] ?? null) !== '/' . $containerName
            || ($container['State']['Running'] ?? null) !== true
            || ($container['Config']['User'] ?? null) !== '10001:10001'
            || !is_array($container['Config']['ExposedPorts'] ?? null)
            || !array_key_exists($port . '/tcp', $container['Config']['ExposedPorts'])
            || ($container['HostConfig']['NetworkMode'] ?? null) !== $networkName
            || $networkKeys !== [$networkName]
            || !is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || str_starts_with($ip, '127.') || $ip === '0.0.0.0') {
            throw new ControlRefusal('route authority cannot prove exact running upstream containment');
        }
        $network = $this->jsonCommand([
            $engine, 'network', 'inspect', '--format', '{{json .}}', $networkName,
        ], 'route upstream network inspection');
        $containers = $network['Containers'] ?? null;
        $containerId = $container['Id'] ?? null;
        $member = is_array($containers) && is_string($containerId)
            ? ($containers[$containerId] ?? null)
            : null;
        $memberAddress = is_array($member) ? ($member['IPv4Address'] ?? null) : null;
        $memberIp = is_string($memberAddress) ? explode('/', $memberAddress, 2)[0] : null;
        if (($network['Name'] ?? null) !== $networkName
            || ($network['Driver'] ?? null) !== 'bridge'
            || ($network['Internal'] ?? null) !== true
            || ($network['Options']['com.docker.network.bridge.enable_ip_masquerade'] ?? null) !== 'false'
            || ($network['Options']['com.docker.network.bridge.enable_icc'] ?? null) !== 'false'
            || !is_array($member) || ($member['Name'] ?? null) !== $containerName
            || $memberIp !== $ip) {
            throw new ControlRefusal('route authority cannot prove exact internal upstream network');
        }
        return ['ip' => $ip];
    }

    /** @param array<string,mixed> $state */
    private function applyAndVerify(array $state, string $routeId, bool $expectedBound): void {
        $document = $this->caddyDocument($state, $routeId, $expectedBound);
        $this->writeActiveConfig(CanonicalJson::encode($document) . "\n");
        $result = $this->runner->run([
            $this->descriptorPath('curl'),
            '--silent', '--show-error', '--fail', '--max-time', '10',
            '--header', 'Content-Type: application/json',
            '--request', 'POST', '--data-binary', '@-',
            'http://127.0.0.1:2019/load',
        ], $this->activeConfigPath);
        $this->successful($result, 'Caddy route reload');
        if ($result['stdout'] !== '') {
            throw new ControlRefusal('Caddy route reload returned an unexpected response body');
        }
        $this->verifyActiveConfig($state, $routeId, $expectedBound);
        if ($expectedBound) {
            $record = $state['routes'][$routeId];
            $this->verifyTls($record['host']);
        }
    }

    /** @param array<string,mixed> $state */
    private function verifyActiveConfig(array $state, string $routeId, bool $expectedBound): void {
        $expected = $this->caddyDocument($state, $routeId, $expectedBound);
        $result = $this->runner->run([
            $this->descriptorPath('curl'),
            '--silent', '--show-error', '--max-time', '10',
            '--header', 'Accept: application/json',
            (string) $this->config->get('admin_endpoint'),
        ]);
        $this->successful($result, 'Caddy configuration readback');
        try {
            $actual = json_decode($result['stdout'], true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new ControlRefusal('Caddy configuration readback was not JSON', 0, $error);
        }
        if (!is_array($actual) || array_is_list($actual)
            || CanonicalJson::encode($actual) !== CanonicalJson::encode($expected)) {
            throw new ControlRefusal('Caddy active configuration differs from exact route authority state');
        }
    }

    private function verifyTls(string $host): void {
        $result = $this->runner->run([
            $this->descriptorPath('curl'),
            '--silent', '--show-error', '--max-time', '15',
            '--proto', '=https', '--tlsv1.2', '--resolve', $host . ':443:127.0.0.1',
            '--write-out', "duo-cloud-route-proof/v1\n%{ssl_verify_result}\n%{remote_ip}\n%{http_code}\n",
            'https://' . $host . '/__duo/health',
        ]);
        $this->successful($result, 'preview HTTPS route probe');
        if ($result['stdout'] !== "duo-cloud-preview-runtime-health/v1\n"
            . "duo-cloud-route-proof/v1\n0\n127.0.0.1\n200\n") {
            throw new ControlRefusal(
                'preview HTTPS route did not prove trusted TLS and the reviewed runtime health body'
            );
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function caddyDocument(array $state, string $targetRouteId, bool $targetBound): array {
        $routes = [];
        $records = $state['routes'];
        ksort($records, SORT_STRING);
        foreach ($records as $routeId => $record) {
            if (!is_array($record)) {
                throw new ControlRefusal('route authority contains an invalid route record');
            }
            $include = $routeId === $targetRouteId
                ? $targetBound
                : ($record['state'] ?? null) !== 'applying-unbind';
            if (!$include) {
                continue;
            }
            $routes[] = [
                '@id' => $routeId,
                'handle' => [[
                    'handler' => 'reverse_proxy',
                    'upstreams' => [['dial' => $record['upstream_ip'] . ':' . $record['upstream_port']]],
                ]],
                'match' => [['host' => [$record['host']]]],
                'terminal' => true,
            ];
        }
        return [
            'admin' => ['listen' => '127.0.0.1:2019'],
            'apps' => ['http' => ['servers' => ['duo_previews' => [
                'automatic_https' => ['disable_redirects' => true],
                'listen' => [':443'],
                'routes' => $routes,
                'tls_connection_policies' => [['protocol_min' => 'tls1.2']],
            ]]]],
        ];
    }

    private function writeActiveConfig(string $bytes): void {
        if (strlen($bytes) > self::CONFIG_LIMIT) {
            throw new ControlRefusal('Caddy route configuration exceeds its byte limit');
        }
        $this->reconcileActiveConfigTemporary();
        $temporary = $this->activeConfigPath . '.tmp';
        $handle = null;
        try {
            $umask = umask(0077);
            try {
                $handle = @fopen($temporary, 'x+b');
            } finally {
                umask($umask);
            }
            if (!is_resource($handle) || !chmod($temporary, 0600)) {
                throw new ControlRefusal('Caddy route configuration temporary could not be protected');
            }
            $opened = fstat($handle);
            $named = @lstat($temporary);
            if (!is_array($opened) || !is_array($named)
                || !self::sameConfigFile($opened, $named)
                || (int) ($opened['nlink'] ?? 0) !== 1
                || (DIRECTORY_SEPARATOR === '/' && ((int) $opened['mode'] & 0777) !== 0600)
                || (function_exists('posix_geteuid')
                    && (int) $opened['uid'] !== posix_geteuid())) {
                throw new ControlRefusal('Caddy route configuration temporary changed while opening');
            }
            $remaining = $bytes;
            while ($remaining !== '') {
                $written = fwrite($handle, $remaining);
                if (!is_int($written) || $written < 1) {
                    throw new ControlRefusal('Caddy route configuration could not be written');
                }
                $remaining = substr($remaining, $written);
            }
            if (!fflush($handle) || (function_exists('fsync') && !fsync($handle)) || !fclose($handle)) {
                $handle = null;
                throw new ControlRefusal('Caddy route configuration could not be synchronized');
            }
            $handle = null;
            if (!rename($temporary, $this->activeConfigPath)) {
                throw new ControlRefusal('Caddy route configuration could not be atomically published');
            }
            $this->assertActiveConfigFile($this->activeConfigPath, 'published');
            $this->syncActiveConfigDirectory();
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
            if (file_exists($temporary)) {
                @unlink($temporary);
            }
        }
    }

    private function reconcileActiveConfigTemporary(): void {
        $temporary = $this->activeConfigPath . '.tmp';
        clearstatcache(true, $temporary);
        $before = @lstat($temporary);
        if ($before === false) {
            return;
        }
        $this->assertActiveConfigFile($temporary, 'stale temporary');
        if ((int) ($before['size'] ?? -1) < 0 || (int) $before['size'] > self::CONFIG_LIMIT) {
            throw new ControlRefusal('Caddy route configuration stale temporary has an invalid size');
        }
        $handle = @fopen($temporary, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal('Caddy route configuration stale temporary could not be opened');
        }
        try {
            $opened = fstat($handle);
            clearstatcache(true, $temporary);
            $after = @lstat($temporary);
            if (!is_array($opened) || !is_array($after)
                || !self::sameConfigFile($before, $opened)
                || !self::sameConfigFile($before, $after)
                || !@unlink($temporary)) {
                throw new ControlRefusal('Caddy route configuration stale temporary changed during recovery');
            }
            $unlinked = fstat($handle);
            clearstatcache(true, $temporary);
            if (!is_array($unlinked) || @lstat($temporary) !== false
                || (int) $unlinked['dev'] !== (int) $opened['dev']
                || (int) $unlinked['ino'] !== (int) $opened['ino']
                || (int) ($unlinked['nlink'] ?? -1) !== 0) {
                throw new ControlRefusal('Caddy route configuration stale temporary changed while removing');
            }
        } finally {
            fclose($handle);
        }
        $this->syncActiveConfigDirectory();
    }

    private function assertActiveConfigFile(string $path, string $phase): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path)
            || ((int) $stat['mode'] & 0170000) !== 0100000
            || (int) ($stat['nlink'] ?? 0) !== 1
            || (DIRECTORY_SEPARATOR === '/' && ((int) $stat['mode'] & 0777) !== 0600)
            || (function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid())) {
            throw new ControlRefusal(
                "Caddy route configuration $phase must be a process-owned single-link mode-0600 file"
            );
        }
    }

    private function syncActiveConfigDirectory(): void {
        if (!function_exists('fsync')) {
            return;
        }
        $directory = @fopen(dirname($this->activeConfigPath), 'rb');
        if (!is_resource($directory)) {
            throw new ControlRefusal('Caddy route configuration directory could not be opened');
        }
        $synced = @fsync($directory);
        $closed = fclose($directory);
        if (!$synced || !$closed) {
            throw new ControlRefusal('Caddy route configuration directory could not be synchronized');
        }
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function sameConfigFile(array $left, array $right): bool {
        return (int) $left['dev'] === (int) $right['dev']
            && (int) $left['ino'] === (int) $right['ino']
            && (int) $left['mode'] === (int) $right['mode']
            && (int) $left['uid'] === (int) $right['uid']
            && (int) ($left['nlink'] ?? 0) === (int) ($right['nlink'] ?? 0)
            && (int) $left['size'] === (int) $right['size'];
    }

    /** @param array<string,mixed> $state */
    private function assertConfigSize(array $state, string $routeId, bool $bound): void {
        $bytes = CanonicalJson::encode($this->caddyDocument($state, $routeId, $bound)) . "\n";
        if (strlen($bytes) > self::CONFIG_LIMIT) {
            throw new ControlRefusal('Caddy route configuration exceeds its byte limit');
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private function state(array $state): array {
        if ($state === []) {
            return ['format' => self::STORE_FORMAT, 'routes' => []];
        }
        self::exactKeys($state, ['format', 'routes'], 'route authority state');
        if (($state['format'] ?? null) !== self::STORE_FORMAT
            || !is_array($state['routes'] ?? null)
            || (array_is_list($state['routes']) && $state['routes'] !== [])) {
            throw new ControlRefusal('route authority state is invalid');
        }
        return $state;
    }

    /** @return array<string,mixed> */
    private function assertedRecord(mixed $routeId, mixed $record): array {
        if (!is_string($routeId) || !is_array($record) || array_is_list($record)) {
            throw new ControlRefusal('route authority contains an invalid route record');
        }
        self::exactKeys($record, [
            'configuration_sha256', 'host', 'reviewed_base_sha256', 'route_id', 'state',
            'upstream_container', 'upstream_ip', 'upstream_network', 'upstream_port',
        ], 'route authority record');
        $binding = [
            'configuration_sha256' => $record['configuration_sha256'] ?? null,
            'host' => $record['host'] ?? null,
            'reviewed_base_sha256' => $record['reviewed_base_sha256'] ?? null,
            'route_id' => $record['route_id'] ?? null,
            'upstream_container' => $record['upstream_container'] ?? null,
            'upstream_network' => $record['upstream_network'] ?? null,
            'upstream_port' => $record['upstream_port'] ?? null,
        ];
        $validated = $this->commonInput($binding, true);
        foreach ($validated as $field => $value) {
            if (($record[$field] ?? null) !== $value) {
                throw new ControlRefusal('route authority record differs from its derived binding');
            }
        }
        $ip = $record['upstream_ip'] ?? null;
        if ($routeId !== $record['route_id']
            || !is_string($ip)
            || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
            || str_starts_with($ip, '127.') || $ip === '0.0.0.0'
            || !in_array($record['state'] ?? null, [
                'applying-bind', 'applying-unbind', 'bound',
            ], true)) {
            throw new ControlRefusal('route authority record identity or state is invalid');
        }
        return $record;
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private function assertSameBinding(array $left, array $right): void {
        foreach (array_keys($right) as $field) {
            if (($left[$field] ?? null) !== $right[$field]) {
                throw new ControlRefusal('route id is already bound to a foreign generation');
            }
        }
        if (!in_array($left['state'] ?? null, ['applying-bind', 'bound'], true)) {
            throw new ControlRefusal('route id has a conflicting durable transition');
        }
    }

    /** @param array<string,mixed> $record @return array<string,mixed> */
    private function output(array $record): array {
        return [
            'configuration_sha256' => $record['configuration_sha256'],
            'format' => self::OUTPUT_FORMAT,
            'host' => $record['host'],
            'reviewed_base_sha256' => $record['reviewed_base_sha256'],
            'route_id' => $record['route_id'],
            'state' => 'bound',
            'upstream_container' => $record['upstream_container'],
            'upstream_network' => $record['upstream_network'],
            'upstream_port' => $record['upstream_port'],
        ];
    }

    /** @param array<string,mixed> $common @return array<string,mixed> */
    private function absent(array $common): array {
        return [
            'configuration_sha256' => $common['configuration_sha256'],
            'format' => self::OUTPUT_FORMAT,
            'reviewed_base_sha256' => $common['reviewed_base_sha256'],
            'route_id' => $common['route_id'],
            'state' => 'absent',
        ];
    }

    /** @return array<string,mixed> */
    private function jsonCommand(array $argv, string $label): array {
        $result = $this->runner->run($argv);
        $this->successful($result, $label);
        try {
            $decoded = json_decode(trim($result['stdout']), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new ControlRefusal("$label was not JSON", 0, $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)) {
            throw new ControlRefusal("$label did not return an object");
        }
        return $decoded;
    }

    /** @param array{exit:int,stderr:string,stdout:string} $result */
    private function successful(array $result, string $label): void {
        if ($result['exit'] !== 0 || $result['stderr'] !== '') {
            throw new ControlRefusal("$label failed through the closed process boundary");
        }
    }

    private function descriptorPath(string $field): string {
        $descriptor = $this->config->get($field);
        if (!is_array($descriptor) || !is_string($descriptor['path'] ?? null)) {
            throw new ControlRefusal('route authority executable descriptor is invalid');
        }
        return $descriptor['path'];
    }

    private static function sha256(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new ControlRefusal("route authority $label is not lowercase SHA-256");
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
