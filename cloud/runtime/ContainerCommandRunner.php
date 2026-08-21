<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once dirname(__DIR__) . '/src/CanonicalJson.php';
require_once dirname(__DIR__) . '/src/CommandRunner.php';
require_once dirname(__DIR__) . '/src/ContainerWorkloadRuntime.php';
require_once dirname(__DIR__) . '/src/ControlRefusal.php';
require_once dirname(__DIR__) . '/src/ImmutableOciReference.php';
require_once __DIR__ . '/RuntimeSlotLock.php';

/** Execute a signed command only in the exact sealed container generation. */
final class ContainerCommandRunner implements CommandRunner {
    private const MANIFEST_FORMAT = 'duo-cloud-container-workload-state/v1';
    private const MANIFEST_LIMIT = 1048576;
    private const PRIVATE_INPUT_LIMIT = 2097152;

    private string $inputRoot;

    public function __construct(
        private ContainerArgvProcessRunner $runner,
        private string $engineBinary,
        private string $image,
        private string $configurationSha256,
        private string $reviewedBaseSha256,
        private string $stateRoot,
        private int $timeoutSeconds
    ) {
        self::absolutePath($engineBinary, 'container engine');
        if (!ImmutableOciReference::valid($image)) {
            throw new ControlRefusal('command runner image is not an immutable OCI digest reference');
        }
        self::sha256($configurationSha256, 'command runner configuration digest');
        self::sha256($reviewedBaseSha256, 'command runner reviewed base digest');
        if ($timeoutSeconds < 1 || $timeoutSeconds > 300) {
            throw new ControlRefusal('command runner timeout is outside its closed range');
        }
        $real = realpath($stateRoot);
        if (!is_string($real) || $real === '/' || is_link($stateRoot)) {
            throw new ControlRefusal('command runner state root is not canonical');
        }
        self::privateDirectory($real, 'command runner state root');
        $this->stateRoot = $real;
        $this->inputRoot = $real . '/command-inputs';
        if (!is_dir($this->inputRoot)
            && (!mkdir($this->inputRoot, 0700) || !chmod($this->inputRoot, 0700))) {
            throw new ControlRefusal('command runner input root could not be created privately');
        }
        self::privateDirectory($this->inputRoot, 'command runner input root');
    }

    public function run(array $request): array {
        $identity = $this->requestIdentity($request);
        $slot = $this->stateRoot . '/slots/' . $identity['resource_token'];
        return RuntimeSlotLock::exclusive($slot, function () use ($identity, $request): array {
            // Authority is re-read only after acquiring the lifecycle's exact
            // slot lock, then held through mandatory process recycling. A
            // signed command can therefore neither observe nor create a
            // half-materialized generation.
            $manifest = $this->readManifest($identity['resource_token']);
            $this->assertManifest($manifest, $request, $identity);
            $this->reconcilePrivateInput($identity['resource_token']);
            $commandDeadline = self::monotonicSeconds() + $this->timeoutSeconds;
            $container = $this->assertContainer($manifest, $commandDeadline);
            $startedAt = (string) $container['State']['StartedAt'];
            $pid = (int) $container['State']['Pid'];

            if ($request['action'] === 'wp') {
                $argv = [
                    $this->engineBinary,
                    'container',
                    'exec',
                    '--user',
                    '10001:10001',
                    $manifest['container_name'],
                    '/opt/duo/bin/duo-preview-command',
                    'wp',
                    '--',
                ];
                foreach ($request['input']['argv'] as $argument) {
                    $argv[] = $argument;
                }
                try {
                    return $this->command($argv, null, $commandDeadline);
                } finally {
                    $this->recycleContainer(
                        $manifest,
                        $pid,
                        $startedAt,
                        self::monotonicSeconds() + $this->timeoutSeconds
                    );
                }
            }

            $input = $this->privateInput($identity['resource_token'], $request['input']['script']);
            try {
                try {
                    return $this->command([
                        $this->engineBinary,
                        'container',
                        'exec',
                        '--interactive',
                        '--user',
                        '10001:10001',
                        $manifest['container_name'],
                        '/opt/duo/bin/duo-preview-command',
                        'raw',
                    ], $input, $commandDeadline);
                } finally {
                    $this->recycleContainer(
                        $manifest,
                        $pid,
                        $startedAt,
                        self::monotonicSeconds() + $this->timeoutSeconds
                    );
                }
            } finally {
                $this->removePrivateInput($input, 'completed');
            }
        });
    }

    /** @param array<string,mixed> $request @return array{resource_token:string} */
    private function requestIdentity(array $request): array {
        foreach (['action', 'input', 'operation_id', 'request_id', 'site_id', 'target', 'tenant_id'] as $field) {
            if (!array_key_exists($field, $request)) {
                throw new ControlRefusal('command runner request is missing authority fields');
            }
        }
        if (!in_array($request['action'], ['raw', 'wp'], true)
            || !is_array($request['input']) || array_is_list($request['input'])
            || !is_array($request['target']) || array_is_list($request['target'])) {
            throw new ControlRefusal('command runner request action or input is invalid');
        }
        foreach (['operation_id', 'request_id', 'site_id', 'tenant_id'] as $field) {
            self::identifier($request[$field] ?? null, "command runner $field");
        }
        self::sha256($request['request_id'], 'command runner request id');
        $target = $request['target'];
        self::exactKeys($target, [
            'environment_identity', 'lease_generation', 'lease_id', 'mutation_generation',
            'mutation_id', 'mutation_owner', 'mutation_receipt_sha256',
            'ownership_receipt_sha256', 'resource_id',
        ], 'command runner target');
        foreach (['environment_identity', 'lease_id', 'mutation_id', 'mutation_owner', 'resource_id'] as $field) {
            self::identifier($target[$field] ?? null, "command runner target $field");
        }
        foreach (['lease_generation', 'mutation_generation'] as $field) {
            if (!is_int($target[$field] ?? null) || $target[$field] < 1) {
                throw new ControlRefusal("command runner target $field is invalid");
            }
        }
        self::sha256($target['ownership_receipt_sha256'] ?? null, 'command runner ownership receipt');
        self::sha256($target['mutation_receipt_sha256'] ?? null, 'command runner mutation receipt');
        if ($target['mutation_owner'] !== 'duo-env-materialize-' . $request['operation_id']) {
            throw new ControlRefusal('command runner mutation owner is not bound to the operation');
        }
        $expectedResource = 'cloud-slot-' . hash(
            'sha256',
            "duo-cloud-preview-physical-slot/v1\0{$request['tenant_id']}\0{$request['site_id']}"
        );
        if ($target['resource_id'] !== $expectedResource) {
            throw new ControlRefusal('command runner resource does not belong to the signed tenant and site');
        }
        if ($request['action'] === 'wp') {
            self::exactKeys($request['input'], ['argv'], 'command runner WordPress input');
            if (!is_array($request['input']['argv']) || !array_is_list($request['input']['argv'])
                || $request['input']['argv'] === []) {
                throw new ControlRefusal('command runner WordPress argv is invalid');
            }
            foreach ($request['input']['argv'] as $argument) {
                self::argument($argument);
            }
        } else {
            self::exactKeys($request['input'], ['script'], 'command runner raw input');
            if (!is_string($request['input']['script']) || $request['input']['script'] === ''
                || strlen($request['input']['script']) > self::PRIVATE_INPUT_LIMIT
                || str_contains($request['input']['script'], "\0")) {
                throw new ControlRefusal('command runner raw script is invalid');
            }
        }
        return ['resource_token' => substr($expectedResource, strlen('cloud-slot-'))];
    }

    /** @return array<string,mixed> */
    private function readManifest(string $resourceToken): array {
        $path = $this->stateRoot . '/slots/' . $resourceToken . '/active.json';
        clearstatcache(true, $path);
        $before = @lstat($path);
        if (!is_array($before) || is_link($path) || ($before['mode'] & 0170000) !== 0100000
            || (DIRECTORY_SEPARATOR === '/' && ($before['mode'] & 0077) !== 0)
            || (int) ($before['size'] ?? 0) < 2 || (int) $before['size'] > self::MANIFEST_LIMIT) {
            throw new ControlRefusal('command runner has no private active runtime manifest');
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal('command runner active runtime manifest could not be opened');
        }
        $opened = fstat($handle);
        $bytes = stream_get_contents($handle, self::MANIFEST_LIMIT + 1);
        $closed = fclose($handle);
        clearstatcache(true, $path);
        $after = @lstat($path);
        if (!is_array($opened) || !is_array($after) || !is_string($bytes) || !$closed
            || !self::sameFile($before, $opened) || !self::sameFile($before, $after)) {
            throw new ControlRefusal('command runner active runtime manifest changed while reading');
        }
        $manifest = CanonicalJson::decodeObject($bytes, self::MANIFEST_LIMIT);
        if ($bytes !== CanonicalJson::encode($manifest) . "\n") {
            throw new ControlRefusal('command runner active runtime manifest is not canonical');
        }
        return $manifest;
    }

    /** @param array<string,mixed> $manifest @param array<string,mixed> $request @param array{resource_token:string} $identity */
    private function assertManifest(array $manifest, array $request, array $identity): void {
        $target = $request['target'];
        $generation = str_pad((string) $target['lease_generation'], 10, '0', STR_PAD_LEFT);
        $container = 'duo-preview-' . $identity['resource_token'] . '-g' . $generation;
        if (($manifest['format'] ?? null) !== self::MANIFEST_FORMAT
            || ($manifest['configuration_sha256'] ?? null) !== $this->configurationSha256
            || ($manifest['reviewed_base_sha256'] ?? null) !== $this->reviewedBaseSha256
            || ($manifest['image'] ?? null) !== $this->image
            || ($manifest['container_name'] ?? null) !== $container
            || ($manifest['resource_id'] ?? null) !== $target['resource_id']
            || ($manifest['tenant_id'] ?? null) !== $request['tenant_id']
            || ($manifest['site_id'] ?? null) !== $request['site_id']
            || ($manifest['environment_identity'] ?? null) !== $target['environment_identity']
            || ($manifest['lease_generation'] ?? null) !== $target['lease_generation']
            || ($manifest['lease_id'] ?? null) !== $target['lease_id']
            || ($manifest['ownership_receipt_sha256'] ?? null) !== $target['ownership_receipt_sha256']
            || ($manifest['state'] ?? null) !== 'present'
            || ($manifest['execution_state'] ?? null) !== 'running') {
            throw new ControlRefusal('command runner manifest is stale, foreign, or not running');
        }
        $mutation = $manifest['last_mutation'] ?? null;
        if (!is_array($mutation) || array_is_list($mutation)
            || ($mutation['generation'] ?? null) !== $target['mutation_generation']
            || ($mutation['id'] ?? null) !== $target['mutation_id']
            || ($mutation['owner'] ?? null) !== $target['mutation_owner']
            || ($mutation['receipt_sha256'] ?? null) !== $target['mutation_receipt_sha256']) {
            throw new ControlRefusal('command runner manifest does not hold the exact mutation fence');
        }
    }

    /** @param array<string,mixed> $manifest */
    private function assertContainer(array $manifest, float $deadline): array {
        $result = $this->runBefore($deadline, [
            $this->engineBinary,
            'container',
            'inspect',
            '--format',
            '{{json .}}',
            $manifest['container_name'],
        ]);
        if ($result['exit'] !== 0 || trim($result['stderr']) !== '') {
            throw new ControlRefusal('command runner could not inspect the exact workload container');
        }
        try {
            $container = json_decode(trim($result['stdout']), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new ControlRefusal('command runner container inspection is not JSON', 0, $error);
        }
        if (!is_array($container) || array_is_list($container)) {
            throw new ControlRefusal('command runner container inspection is not an object');
        }
        $config = $container['Config'] ?? null;
        $host = $container['HostConfig'] ?? null;
        $labels = is_array($config) ? ($config['Labels'] ?? null) : null;
        $security = is_array($host) ? ($host['SecurityOpt'] ?? null) : null;
        $capDrop = is_array($host) ? ($host['CapDrop'] ?? null) : null;
        $portBindings = is_array($host) ? ($host['PortBindings'] ?? null) : null;
        if (($container['Name'] ?? null) !== '/' . $manifest['container_name']
            || ($container['State']['Running'] ?? null) !== true
            || !is_int($container['State']['Pid'] ?? null)
            || $container['State']['Pid'] < 1
            || !is_string($container['State']['StartedAt'] ?? null)
            || $container['State']['StartedAt'] === ''
            || !is_array($config) || ($config['Image'] ?? null) !== $this->image
            || ($config['User'] ?? null) !== '10001:10001'
            || !is_array($labels)
            || ($labels['duo.cloud.configuration-sha256'] ?? null) !== $this->configurationSha256
            || ($labels['duo.cloud.resource-id'] ?? null) !== $manifest['resource_id']
            || ($labels['duo.cloud.lease-generation'] ?? null) !== (string) $manifest['lease_generation']
            || ($labels['duo.cloud.reviewed-base-sha256'] ?? null) !== $this->reviewedBaseSha256
            || !is_array($host) || ($host['ReadonlyRootfs'] ?? null) !== true
            || ($host['Privileged'] ?? null) !== false
            || !is_array($security)
            || !in_array('no-new-privileges', $security, true)
                && !in_array('no-new-privileges=true', $security, true)
            || !is_array($capDrop) || !in_array('ALL', $capDrop, true)
            || is_array($portBindings) && $portBindings !== []) {
            throw new ControlRefusal('command runner cannot prove the exact contained workload');
        }
        foreach (($container['Mounts'] ?? []) as $mount) {
            if (!is_array($mount)
                || str_contains(strtolower((string) ($mount['Source'] ?? '')), 'docker.sock')
                || str_contains(strtolower((string) ($mount['Destination'] ?? '')), 'docker.sock')) {
                throw new ControlRefusal('command runner found a forbidden workload mount');
            }
        }
        return $container;
    }

    /** @param array<string,mixed> $manifest */
    private function recycleContainer(
        array $manifest,
        int $previousPid,
        string $previousStartedAt,
        float $deadline
    ): void {
        $name = $manifest['container_name'];
        $stopped = $this->runBefore($deadline, [
            $this->engineBinary,
            'container',
            'kill',
            '--signal',
            'KILL',
            $name,
        ]);
        if ($stopped['exit'] !== 0 || $stopped['stderr'] !== '' || $stopped['stdout'] !== $name . "\n") {
            throw new ControlRefusal('command runner could not kill the workload process boundary');
        }
        $terminal = $this->containerInspection($name, 'post-command stopped container', $deadline);
        if (($terminal['State']['Running'] ?? null) !== false
            || ($terminal['State']['Paused'] ?? null) !== false
            || ($terminal['State']['Restarting'] ?? null) !== false
            || ($terminal['State']['Dead'] ?? null) !== false
            || ($terminal['State']['Pid'] ?? null) !== 0) {
            throw new ControlRefusal('command runner cannot prove every command-generation process is dead');
        }
        $started = $this->runBefore($deadline, [
            $this->engineBinary,
            'container',
            'start',
            $name,
        ]);
        if ($started['exit'] !== 0 || $started['stderr'] !== '' || $started['stdout'] !== $name . "\n") {
            throw new ControlRefusal('command runner could not restart the reviewed workload boundary');
        }
        $ready = null;
        while (self::monotonicSeconds() < $deadline) {
            $result = $this->runBefore($deadline, [
                $this->engineBinary,
                'container',
                'exec',
                '--user',
                '10001:10001',
                $name,
                '/opt/duo/bin/runtime-status',
                '--format',
                'duo-cloud-preview-runtime-status/v1',
                '--config-sha256',
                $this->configurationSha256,
                '--lease-generation',
                (string) $manifest['lease_generation'],
                '--reviewed-base-sha256',
                $this->reviewedBaseSha256,
            ]);
            if ($result['exit'] === 0 && $result['stderr'] === '') {
                try {
                    $candidate = json_decode(trim($result['stdout']), true, 32, JSON_THROW_ON_ERROR);
                } catch (\Throwable) {
                    $candidate = null;
                }
                if (is_array($candidate) && !array_is_list($candidate)) {
                    self::exactKeys($candidate, [
                        'clean_base', 'configuration_sha256', 'format', 'lease_generation',
                        'ready', 'reviewed_base_sha256',
                    ], 'command runner restarted runtime status');
                    if (($candidate['format'] ?? null) !== 'duo-cloud-preview-runtime-status/v1'
                        || ($candidate['configuration_sha256'] ?? null) !== $this->configurationSha256
                        || ($candidate['lease_generation'] ?? null) !== $manifest['lease_generation']
                        || ($candidate['reviewed_base_sha256'] ?? null) !== $this->reviewedBaseSha256
                        || !is_bool($candidate['ready'] ?? null)
                        || !is_bool($candidate['clean_base'] ?? null)) {
                        throw new ControlRefusal('command runner restart returned foreign runtime readiness');
                    }
                    if ($candidate['ready'] === true) {
                        $ready = $candidate;
                        break;
                    }
                }
            }
            $remaining = $deadline - self::monotonicSeconds();
            if ($remaining > 0) {
                usleep((int) min(250000, max(1, $remaining * 1000000)));
            }
        }
        if (!is_array($ready)) {
            throw new ControlRefusal('command runner restart did not reach reviewed runtime readiness');
        }
        $running = $this->assertContainer($manifest, $deadline);
        if (($running['State']['Pid'] ?? null) === $previousPid
            || ($running['State']['StartedAt'] ?? null) === $previousStartedAt) {
            throw new ControlRefusal('command runner cannot prove a new reviewed process generation');
        }
    }

    /** @return array<string,mixed> */
    private function containerInspection(string $name, string $label, float $deadline): array {
        $result = $this->runBefore($deadline, [
            $this->engineBinary,
            'container',
            'inspect',
            '--format',
            '{{json .}}',
            $name,
        ]);
        if ($result['exit'] !== 0 || $result['stderr'] !== '') {
            throw new ControlRefusal("command runner could not inspect $label");
        }
        try {
            $inspection = json_decode(trim($result['stdout']), true, 64, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new ControlRefusal("command runner $label is not JSON", 0, $error);
        }
        if (!is_array($inspection) || array_is_list($inspection)) {
            throw new ControlRefusal("command runner $label is not an object");
        }
        return $inspection;
    }

    /** @param non-empty-list<string> $argv @return array{exit:int,stderr:string,stdout:string} */
    private function command(array $argv, ?string $stdinFile, float $deadline): array {
        $result = $this->runBefore($deadline, $argv, $stdinFile);
        if ($result['exit'] < 0 || $result['exit'] > 255
            || preg_match('//u', $result['stdout']) !== 1 || preg_match('//u', $result['stderr']) !== 1) {
            throw new ControlRefusal('workload command result is outside the signed response contract');
        }
        return $result;
    }

    /** @param non-empty-list<string> $argv @return array{exit:int,stderr:string,stdout:string} */
    private function runBefore(float $deadline, array $argv, ?string $stdinFile = null): array {
        $remaining = $deadline - self::monotonicSeconds();
        if ($remaining <= 0) {
            throw new ControlRefusal('command runner aggregate wall deadline expired');
        }
        $timeout = max(1, min($this->timeoutSeconds, (int) ceil($remaining)));
        return $this->runner->run($argv, $stdinFile, $timeout);
    }

    private static function monotonicSeconds(): float {
        return hrtime(true) / 1000000000;
    }

    private function privateInput(string $resourceToken, string $bytes): string {
        $path = $this->privateInputPath($resourceToken);
        $previousUmask = umask(0077);
        try {
            $handle = @fopen($path, 'x+b');
        } finally {
            umask($previousUmask);
        }
        if (!is_resource($handle)) {
            throw new ControlRefusal('command runner private input could not be created');
        }
        try {
            if (!chmod($path, 0600)) {
                throw new ControlRefusal('command runner private input could not be protected');
            }
            self::assertOpenedPrivateInput($handle, $path, 'command runner private input');
            if (fwrite($handle, $bytes) !== strlen($bytes)
                || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
                throw new ControlRefusal('command runner private input could not be written');
            }
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($path);
            throw $error;
        }
        if (!fclose($handle)) {
            @unlink($path);
            throw new ControlRefusal('command runner private input could not be closed');
        }
        return $path;
    }

    private function reconcilePrivateInput(string $resourceToken): void {
        $this->removePrivateInput($this->privateInputPath($resourceToken), 'stale');
    }

    private function privateInputPath(string $resourceToken): string {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $resourceToken) !== 1) {
            throw new ControlRefusal('command runner private input resource token is invalid');
        }
        return $this->inputRoot . '/' . $resourceToken . '.input';
    }

    private function removePrivateInput(string $path, string $phase): void {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false) {
            return;
        }
        self::assertPrivateInput($path, "command runner $phase private input");
        if ((int) ($before['size'] ?? -1) < 0
            || (int) $before['size'] > self::PRIVATE_INPUT_LIMIT) {
            throw new ControlRefusal("command runner $phase private input has an invalid size");
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new ControlRefusal("command runner $phase private input could not be opened");
        }
        try {
            self::assertOpenedPrivateInput($handle, $path, "command runner $phase private input");
            $opened = fstat($handle);
            clearstatcache(true, $path);
            $after = @lstat($path);
            if (!is_array($opened) || !is_array($after)
                || !self::samePrivateInput($before, $opened)
                || !self::samePrivateInput($before, $after)
                || !@unlink($path)) {
                throw new ControlRefusal("command runner $phase private input changed during removal");
            }
            $unlinked = fstat($handle);
            clearstatcache(true, $path);
            if (!is_array($unlinked) || @lstat($path) !== false
                || (int) $unlinked['dev'] !== (int) $opened['dev']
                || (int) $unlinked['ino'] !== (int) $opened['ino']
                || (int) ($unlinked['nlink'] ?? -1) !== 0) {
                throw new ControlRefusal("command runner $phase private input changed while being removed");
            }
        } finally {
            fclose($handle);
        }
        $this->syncInputDirectory();
    }

    private static function assertPrivateInput(string $path, string $label): void {
        clearstatcache(true, $path);
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path)
            || ((int) $stat['mode'] & 0170000) !== 0100000
            || (int) ($stat['nlink'] ?? 0) !== 1
            || (DIRECTORY_SEPARATOR === '/' && ((int) $stat['mode'] & 0777) !== 0600)
            || (function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid())) {
            throw new ControlRefusal("$label must be a process-owned single-link mode-0600 file");
        }
    }

    /** @param resource $handle */
    private static function assertOpenedPrivateInput($handle, string $path, string $label): void {
        clearstatcache(true, $path);
        $named = @lstat($path);
        $opened = fstat($handle);
        if (!is_array($named) || !is_array($opened)
            || !self::samePrivateInput($named, $opened)) {
            throw new ControlRefusal("$label changed while opening");
        }
        self::assertPrivateInput($path, $label);
    }

    /** @param array<string|int,mixed> $left @param array<string|int,mixed> $right */
    private static function samePrivateInput(array $left, array $right): bool {
        return (int) $left['dev'] === (int) $right['dev']
            && (int) $left['ino'] === (int) $right['ino']
            && (int) $left['mode'] === (int) $right['mode']
            && (int) $left['uid'] === (int) $right['uid']
            && (int) ($left['nlink'] ?? 0) === (int) ($right['nlink'] ?? 0)
            && (int) $left['size'] === (int) $right['size'];
    }

    private function syncInputDirectory(): void {
        if (!function_exists('fsync')) {
            return;
        }
        $directory = @fopen($this->inputRoot, 'rb');
        if (!is_resource($directory)) {
            throw new ControlRefusal('command runner input root could not be opened for synchronization');
        }
        $synced = @fsync($directory);
        $closed = fclose($directory);
        if (!$synced || !$closed) {
            throw new ControlRefusal('command runner input root could not be synchronized');
        }
    }

    private static function argument(mixed $value): string {
        if (!is_string($value) || $value === '' || str_contains($value, "\0")) {
            throw new ControlRefusal('command runner argument is invalid');
        }
        return $value;
    }

    private static function identifier(mixed $value, string $label): string {
        if (!is_string($value)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new ControlRefusal("$label is invalid");
        }
        return $value;
    }

    private static function sha256(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new ControlRefusal("$label is not lowercase SHA-256");
        }
        return $value;
    }

    private static function absolutePath(string $path, string $label): void {
        if ($path === '' || $path[0] !== '/' || str_contains($path, "\0")
            || preg_match('#(?:^|/)\.\.?(/|$)#D', $path) === 1) {
            throw new ControlRefusal("$label path is invalid");
        }
    }

    private static function privateDirectory(string $path, string $label): void {
        $stat = @lstat($path);
        if (!is_array($stat) || is_link($path) || ($stat['mode'] & 0170000) !== 0040000
            || (DIRECTORY_SEPARATOR === '/' && ($stat['mode'] & 0077) !== 0)
            || (function_exists('posix_geteuid') && (int) $stat['uid'] !== posix_geteuid())) {
            throw new ControlRefusal("$label is not a private process-owned directory");
        }
    }

    /** @param array<string,mixed> $left @param array<string,mixed> $right */
    private static function sameFile(array $left, array $right): bool {
        return (int) ($left['dev'] ?? -1) === (int) ($right['dev'] ?? -2)
            && (int) ($left['ino'] ?? -1) === (int) ($right['ino'] ?? -2)
            && (int) ($left['size'] ?? -1) === (int) ($right['size'] ?? -2)
            && (int) ($left['mtime'] ?? -1) === (int) ($right['mtime'] ?? -2);
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
