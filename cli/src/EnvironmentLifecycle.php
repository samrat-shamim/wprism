<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

/**
 * Optional host-owned lifecycle provider for branch environments.
 *
 * This is deliberately separate from EnvironmentDriver. A transport can run
 * WP-CLI without having authority to clone production data, allocate a host
 * resource, set a resource TTL, or destroy anything. The provider speaks only
 * in opaque snapshot/resource identities; it never interprets WordPress or
 * plugin state.
 */
final class EnvironmentProviderCapability {
    public const SNAPSHOT_SET_ABORT = 'snapshot.set.abort';
    public const SNAPSHOT_SET_CREATE = 'snapshot.set.create';
    public const SNAPSHOT_SET_PREPARE = 'snapshot.set.prepare';
    public const SNAPSHOT_SET_READ = 'snapshot.set.read';
    public const SNAPSHOT_SET_RESTORE = 'snapshot.set.restore';
    public const ENVIRONMENT_INSPECT = 'environment.inspect';
    public const ENVIRONMENT_ATTACH = 'environment.attach';
    public const ENVIRONMENT_CREATE = 'environment.create';
    public const ENVIRONMENT_DESTROY = 'environment.destroy';
    public const ENVIRONMENT_DETACH = 'environment.detach';
    /** Provider-held, operation-idempotent fence around target mutations. */
    public const ENVIRONMENT_MUTATION_ACQUIRE = 'environment.mutation.acquire';
    public const ENVIRONMENT_MUTATION_READ = 'environment.mutation.read';
    public const ENVIRONMENT_MUTATION_RELEASE = 'environment.mutation.release';
    public const ENVIRONMENT_TTL = 'environment.ttl';
    /** Readback is distinct from setting a TTL; expiry/reuse is never inferred. */
    public const ENVIRONMENT_TTL_READ = 'environment.ttl.read';
    public const URL_DISCOVER = 'environment.url.discover';
    public const URL_SET = 'environment.url.set';
    public const REPOSITORY_MATERIALIZE = 'repository.materialize';
    public const OPERATION_RECEIPTS = 'operation.receipts';

    /** @return list<string> */
    public static function all(): array {
        $all = [
            self::SNAPSHOT_SET_ABORT, self::SNAPSHOT_SET_CREATE, self::SNAPSHOT_SET_PREPARE,
            self::SNAPSHOT_SET_READ, self::SNAPSHOT_SET_RESTORE,
            self::ENVIRONMENT_INSPECT, self::ENVIRONMENT_ATTACH, self::ENVIRONMENT_CREATE,
            self::ENVIRONMENT_DESTROY, self::ENVIRONMENT_DETACH,
            self::ENVIRONMENT_MUTATION_ACQUIRE, self::ENVIRONMENT_MUTATION_READ,
            self::ENVIRONMENT_MUTATION_RELEASE, self::ENVIRONMENT_TTL, self::ENVIRONMENT_TTL_READ,
            self::URL_DISCOVER, self::URL_SET, self::REPOSITORY_MATERIALIZE,
            self::OPERATION_RECEIPTS,
        ];
        sort($all, SORT_STRING);
        return $all;
    }
}

/** Canonical, digest-bound provider capability evidence. */
final class EnvironmentProviderCapabilityReport {
    public const FORMAT = 'duo-branch-environment-capabilities/v1';

    /** @param list<string> $supported */
    public function __construct(
        private string $environment,
        private string $providerId,
        private int $providerProtocol,
        private array $supported
    ) {
        $unknown = array_diff($supported, EnvironmentProviderCapability::all());
        if ($unknown !== []) {
            throw new \RuntimeException(
                "environment provider '$providerId' declared unknown capability '" . reset($unknown) . "'"
            );
        }
        if (count($supported) !== count(array_unique($supported))) {
            throw new \RuntimeException("environment provider '$providerId' repeated a capability");
        }
        sort($this->supported, SORT_STRING);
    }

    /** @param list<string> $required */
    public function require(array $required, string $operation): void {
        $unknown = array_diff($required, EnvironmentProviderCapability::all());
        if ($unknown !== []) {
            throw new \RuntimeException("unknown environment-provider requirement '" . reset($unknown) . "'");
        }
        $missing = array_values(array_diff($required, $this->supported));
        if ($missing !== []) {
            sort($missing, SORT_STRING);
            throw new \RuntimeException(
                "environment provider '{$this->providerId}' cannot $operation; missing " . implode(', ', $missing)
            );
        }
    }

    /** @return array<string,mixed> */
    public function toArray(): array {
        $body = [
            'capabilities' => $this->supported,
            'environment' => $this->environment,
            'format' => self::FORMAT,
            'provider' => ['id' => $this->providerId, 'protocol' => $this->providerProtocol],
        ];
        $body['digest'] = 'sha256:' . hash('sha256', EnvironmentLifecycleCanon::encode($body));
        return $body;
    }

    public function digest(): string {
        return (string) $this->toArray()['digest'];
    }

    public function providerId(): string {
        return $this->providerId;
    }

    /**
     * Stable machine-local pin for one named environment/provider pairing.
     *
     * The capability digest deliberately participates in the pin. A resumed
     * privileged operation must not silently continue after an operator has
     * pointed the same logical environment at another provider contract, or
     * after that provider has withdrawn a capability needed by the journaled
     * operation.
     *
     * @return array{capabilities_sha256:string,environment:string,provider:array{id:string,protocol:int}}
     */
    public function pin(): array {
        $body = $this->toArray();
        return [
            'capabilities_sha256' => (string) $body['digest'],
            'environment' => $this->environment,
            'provider' => [
                'id' => $this->providerId,
                'protocol' => $this->providerProtocol,
            ],
        ];
    }
}

/**
 * Machine-local direct-argv provider client.
 *
 * Requests and responses are one canonical JSON object plus a newline. The
 * provider receives no shell command, and provider output is redacted on all
 * failures because it may contain host or production-data diagnostics.
 */
final class CommandEnvironmentProvider {
    public const REQUEST_FORMAT = 'duo-branch-environment-provider-request/v1';
    public const RESPONSE_FORMAT = 'duo-branch-environment-provider-response/v1';
    private const OUTPUT_LIMIT = 1048576;
    /** @var ?array{id:string,protocol:int} */
    private ?array $negotiatedProvider = null;

    /** @param list<string> $command */
    private function __construct(
        private string $environment,
        private array $command,
        private int $timeoutSeconds
    ) {}

    /** @param array<string,mixed> $environmentConfig */
    public static function fromEnvironment(string $environment, array $environmentConfig): self {
        $cfg = $environmentConfig['environment_provider'] ?? null;
        if (!is_array($cfg) || array_is_list($cfg)) {
            throw new \RuntimeException(
                "env '$environment': branch materialization requires machine-local environment_provider configuration"
            );
        }
        if (($environmentConfig['_machine_local'] ?? false) !== true) {
            throw new \RuntimeException(
                "env '$environment': environment_provider is privileged host configuration and is allowed only in .duo-envs.json"
            );
        }
        self::assertExactKeys($cfg, ['command', 'timeout_seconds'], "env '$environment': environment_provider");
        $command = $cfg['command'] ?? null;
        if (!is_array($command) || !array_is_list($command) || $command === []) {
            throw new \RuntimeException("env '$environment': environment_provider.command must be a non-empty argv array");
        }
        foreach ($command as $index => $arg) {
            if (!is_string($arg) || $arg === '' || str_contains($arg, "\0")) {
                throw new \RuntimeException("env '$environment': environment_provider.command[$index] is invalid");
            }
        }
        if ($command[0][0] !== '/') {
            throw new \RuntimeException("env '$environment': environment_provider executable must be absolute");
        }
        $timeout = $cfg['timeout_seconds'] ?? null;
        if (!is_int($timeout) || $timeout < 1 || $timeout > 60) {
            throw new \RuntimeException("env '$environment': environment_provider.timeout_seconds must be 1..60");
        }
        return new self($environment, array_values($command), $timeout);
    }

    public function environment(): string {
        return $this->environment;
    }

    public function capabilities(string $operationId): EnvironmentProviderCapabilityReport {
        $response = $this->call('capabilities', $operationId, []);
        self::assertExactKeys($response['result'], ['capabilities'], 'capabilities result');
        $capabilities = $response['result']['capabilities'];
        if (!is_array($capabilities) || !array_is_list($capabilities)) {
            throw new \RuntimeException('environment provider capabilities must be a list');
        }
        foreach ($capabilities as $capability) {
            if (!is_string($capability)) {
                throw new \RuntimeException('environment provider capability IDs must be strings');
            }
        }
        $provider = ['id' => (string) $response['provider']['id'], 'protocol' => (int) $response['provider']['protocol']];
        if ($this->negotiatedProvider !== null && $this->negotiatedProvider !== $provider) {
            throw new \RuntimeException('environment provider identity changed during capability negotiation');
        }
        $this->negotiatedProvider = $provider;
        return new EnvironmentProviderCapabilityReport(
            $this->environment,
            $provider['id'],
            $provider['protocol'],
            array_values($capabilities)
        );
    }

    /**
     * Invoke one provider-owned operation and validate its closed response.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed> validated result evidence
     */
    public function perform(string $action, string $operationId, array $input): array {
        if ($this->negotiatedProvider === null) {
            throw new \RuntimeException('environment provider capabilities must be negotiated before operations');
        }
        $response = $this->call($action, $operationId, $input);
        if ($response['provider'] !== $this->negotiatedProvider) {
            throw new \RuntimeException('environment provider identity changed after capability negotiation');
        }
        self::validateActionResult($action, $response['result']);
        return $response['result'] + [
            '_provider' => $response['provider'],
            '_response_sha256' => hash('sha256', EnvironmentLifecycleCanon::encode($response)),
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function call(string $action, string $operationId, array $input): array {
        self::assertAction($action);
        self::assertIdentifier($operationId, 'operation id');
        if (array_is_list($input) && $input !== []) {
            throw new \RuntimeException('environment provider input must be an object');
        }
        $request = [
            'action' => $action,
            'environment' => $this->environment,
            'format' => self::REQUEST_FORMAT,
            'input' => $input,
            'operation_id' => $operationId,
        ];
        $requestBytes = EnvironmentLifecycleCanon::encode($request) . "\n";
        $pipes = [];
        $process = @proc_open(
            $this->command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('could not start environment provider');
        }
        if (fwrite($pipes[0], $requestBytes) !== strlen($requestBytes)) {
            self::stop($process, $pipes);
            throw new \RuntimeException('could not send environment provider request');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $stdout = '';
        $stderr = '';
        $observedExit = null;
        $deadline = microtime(true) + $this->timeoutSeconds;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            if (strlen($stdout) + strlen($stderr) > self::OUTPUT_LIMIT) {
                self::stop($process, $pipes);
                throw new \RuntimeException('environment provider output exceeded the redacted evidence limit');
            }
            $status = proc_get_status($process);
            if (!$status['running']) {
                $observedExit = (int) $status['exitcode'];
                break;
            }
            if (microtime(true) >= $deadline) {
                self::stop($process, $pipes);
                throw new \RuntimeException('environment provider timed out; provider output is redacted');
            }
            usleep(10000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        $stderr .= (string) stream_get_contents($pipes[2]);
        if (strlen($stdout) + strlen($stderr) > self::OUTPUT_LIMIT) {
            self::stop($process, $pipes);
            throw new \RuntimeException('environment provider output exceeded the redacted evidence limit');
        }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $closed = proc_close($process);
        $exit = $observedExit ?? $closed;
        if ($exit !== 0) {
            throw new \RuntimeException('environment provider failed; provider output is redacted');
        }
        try {
            $response = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException('environment provider returned malformed JSON');
        }
        if (!is_array($response) || array_is_list($response)
            || EnvironmentLifecycleCanon::encode($response) . "\n" !== $stdout) {
            throw new \RuntimeException('environment provider returned noncanonical evidence');
        }
        self::assertExactKeys(
            $response,
            ['action', 'environment', 'format', 'operation_id', 'provider', 'result', 'status'],
            'environment provider response'
        );
        if ($response['format'] !== self::RESPONSE_FORMAT || $response['action'] !== $action
            || $response['environment'] !== $this->environment
            || $response['operation_id'] !== $operationId || $response['status'] !== 'ok') {
            throw new \RuntimeException('environment provider response is not bound to the request');
        }
        if (!is_array($response['provider']) || array_is_list($response['provider'])) {
            throw new \RuntimeException('environment provider identity is malformed');
        }
        self::assertExactKeys($response['provider'], ['id', 'protocol'], 'environment provider identity');
        self::assertProviderId($response['provider']['id'] ?? null);
        if (($response['provider']['protocol'] ?? null) !== 1) {
            throw new \RuntimeException('environment provider protocol must be 1');
        }
        if (!is_array($response['result']) || (array_is_list($response['result']) && $response['result'] !== [])) {
            throw new \RuntimeException('environment provider result must be an object');
        }
        return $response;
    }

    /** @param array<string,mixed> $result */
    private static function validateActionResult(string $action, array $result): void {
        if ($action === 'capabilities') {
            self::assertExactKeys($result, ['capabilities'], 'capabilities result');
            return;
        }
        $identityKeys = [
            'environment_identity', 'lease_generation', 'lease_id',
            'ownership_receipt_sha256', 'resource_id', 'url',
        ];
        if (in_array($action, ['inspect', 'attach', 'create'], true)) {
            self::assertExactKeys($result, array_merge($identityKeys, ['presence']), "$action result");
            self::validateIdentity($result);
            if (!in_array($result['presence'], ['present', 'absent'], true)
                || in_array($action, ['attach', 'create'], true) && $result['presence'] !== 'present') {
                throw new \RuntimeException("environment provider $action returned invalid presence");
            }
            return;
        }
        if ($action === 'snapshot-prepare') {
            self::assertExactKeys($result, [
                'lease_generation', 'lease_id', 'lease_receipt_sha256',
                'snapshot_session_id', 'source_identity',
            ], 'snapshot-prepare result');
            self::assertPositiveInt($result['lease_generation'] ?? null, 'source snapshot lease generation');
            self::assertIdentifier($result['lease_id'] ?? null, 'source snapshot lease id');
            self::assertHash($result['lease_receipt_sha256'] ?? null, 'source snapshot lease receipt');
            self::assertIdentifier($result['snapshot_session_id'] ?? null, 'snapshot session id');
            self::assertIdentifier($result['source_identity'] ?? null, 'source identity');
            return;
        }
        if (in_array($action, ['snapshot-create', 'snapshot-read'], true)) {
            $keys = [
                'database_sha256', 'lease_generation', 'lease_id', 'lease_receipt_sha256',
                'media_sha256', 'retention_receipt_sha256', 'semantic_snapshot_sha256',
                'snapshot_session_id', 'snapshot_set_id', 'snapshot_set_receipt_sha256', 'source_identity',
            ];
            if ($action === 'snapshot-read') {
                $keys[] = 'immutable';
            }
            self::assertExactKeys($result, $keys, "$action result");
            self::validateSnapshotSet($result);
            if ($action === 'snapshot-read' && ($result['immutable'] ?? null) !== true) {
                throw new \RuntimeException('environment provider snapshot-read did not prove immutable readback');
            }
            return;
        }
        if ($action === 'snapshot-abort') {
            self::assertExactKeys($result, [
                'disposition', 'lease_generation', 'lease_id', 'lease_receipt_sha256',
                'snapshot_session_id', 'source_identity',
            ], 'snapshot-abort result');
            if (($result['disposition'] ?? null) !== 'aborted') {
                throw new \RuntimeException('environment provider snapshot-abort returned wrong disposition');
            }
            self::assertPositiveInt($result['lease_generation'] ?? null, 'source snapshot lease generation');
            self::assertIdentifier($result['lease_id'] ?? null, 'source snapshot lease id');
            self::assertHash($result['lease_receipt_sha256'] ?? null, 'source snapshot lease receipt');
            self::assertIdentifier($result['snapshot_session_id'] ?? null, 'snapshot session id');
            self::assertIdentifier($result['source_identity'] ?? null, 'source identity');
            return;
        }
        if ($action === 'snapshot-restore') {
            self::assertExactKeys($result, array_merge($identityKeys, ['snapshot_set_id']), 'snapshot-restore result');
            self::validateIdentity($result);
            self::assertIdentifier($result['snapshot_set_id'] ?? null, 'snapshot set id');
            return;
        }
        if ($action === 'repository-materialize') {
            self::assertExactKeys($result, array_merge($identityKeys, ['branch_commit', 'repository_receipt_sha256']), 'repository-materialize result');
            self::validateIdentity($result);
            self::assertGitOid($result['branch_commit'] ?? null);
            self::assertHash($result['repository_receipt_sha256'] ?? null, 'repository receipt');
            return;
        }
        if ($action === 'url-set') {
            self::assertExactKeys($result, $identityKeys, 'url-set result');
            self::validateIdentity($result);
            return;
        }
        if (in_array($action, ['mutation-acquire', 'mutation-read', 'mutation-release'], true)) {
            self::assertExactKeys($result, array_merge($identityKeys, [
                'mutation_generation', 'mutation_id', 'mutation_owner', 'mutation_receipt_sha256', 'state',
            ]), "$action result");
            self::validateIdentity($result);
            self::validateMutation($result, $action);
            return;
        }
        if (in_array($action, ['ttl-set', 'ttl-read'], true)) {
            self::assertExactKeys($result, array_merge($identityKeys, [
                'expires_at', 'ttl_generation', 'ttl_lease_id', 'ttl_receipt_sha256', 'ttl_state',
            ]), "$action result");
            self::validateIdentity($result);
            self::assertTimestamp($result['expires_at'] ?? null);
            self::assertPositiveInt($result['ttl_generation'] ?? null, 'ttl generation');
            self::assertIdentifier($result['ttl_lease_id'] ?? null, 'ttl lease id');
            self::assertHash($result['ttl_receipt_sha256'] ?? null, 'TTL receipt');
            if (($result['ttl_state'] ?? null) !== 'active') {
                throw new \RuntimeException("environment provider $action did not return an active TTL lease");
            }
            return;
        }
        if (in_array($action, ['destroy', 'detach'], true)) {
            self::assertExactKeys($result, [
                'absence_proof_sha256', 'disposition', 'environment_identity',
                'lease_generation', 'lease_id', 'ownership_receipt_sha256', 'resource_id',
            ], "$action result");
            foreach (['environment_identity', 'resource_id', 'lease_id'] as $key) {
                self::assertIdentifier($result[$key] ?? null, str_replace('_', ' ', $key));
            }
            self::assertPositiveInt($result['lease_generation'] ?? null, 'lease generation');
            self::assertHash($result['ownership_receipt_sha256'] ?? null, 'ownership receipt');
            self::assertHash($result['absence_proof_sha256'] ?? null, 'absence proof');
            if ($result['disposition'] !== ($action === 'destroy' ? 'destroyed' : 'detached')) {
                throw new \RuntimeException("environment provider $action returned wrong disposition");
            }
            return;
        }
        throw new \RuntimeException("unknown environment provider action '$action'");
    }

    /** @param array<string,mixed> $result */
    private static function validateSnapshotSet(array $result): void {
        foreach ([
            'database_sha256', 'lease_receipt_sha256', 'media_sha256', 'retention_receipt_sha256',
            'semantic_snapshot_sha256', 'snapshot_set_receipt_sha256',
        ] as $key) {
            self::assertHash($result[$key] ?? null, str_replace('_', ' ', $key));
        }
        self::assertPositiveInt($result['lease_generation'] ?? null, 'source snapshot lease generation');
        self::assertIdentifier($result['lease_id'] ?? null, 'source snapshot lease id');
        self::assertIdentifier($result['snapshot_session_id'] ?? null, 'snapshot session id');
        self::assertIdentifier($result['snapshot_set_id'] ?? null, 'snapshot set id');
        self::assertIdentifier($result['source_identity'] ?? null, 'source identity');
    }

    /** @param array<string,mixed> $result */
    private static function validateMutation(array $result, string $action): void {
        self::assertPositiveInt($result['mutation_generation'] ?? null, 'mutation generation');
        self::assertIdentifier($result['mutation_id'] ?? null, 'mutation id');
        self::assertIdentifier($result['mutation_owner'] ?? null, 'mutation owner');
        self::assertHash($result['mutation_receipt_sha256'] ?? null, 'mutation receipt');
        $state = $result['state'] ?? null;
        if (!in_array($state, ['held', 'released'], true)
            || ($action === 'mutation-acquire' && $state !== 'held')
            || ($action === 'mutation-release' && $state !== 'released')) {
            throw new \RuntimeException("environment provider $action returned an invalid mutation fence state");
        }
    }

    /** @param array<string,mixed> $result */
    private static function validateIdentity(array $result): void {
        foreach (['environment_identity', 'resource_id', 'lease_id'] as $key) {
            self::assertIdentifier($result[$key] ?? null, str_replace('_', ' ', $key));
        }
        self::assertPositiveInt($result['lease_generation'] ?? null, 'lease generation');
        self::assertHash($result['ownership_receipt_sha256'] ?? null, 'ownership receipt');
        if (!is_string($result['url'] ?? null) || filter_var($result['url'], FILTER_VALIDATE_URL) === false) {
            throw new \RuntimeException('environment provider URL is invalid');
        }
    }

    private static function assertAction(string $action): void {
        if (!in_array($action, [
            'capabilities', 'inspect', 'attach', 'create', 'snapshot-prepare', 'snapshot-create', 'snapshot-abort',
            'snapshot-read', 'snapshot-restore', 'repository-materialize', 'url-set',
            'mutation-acquire', 'mutation-read', 'mutation-release', 'ttl-set', 'ttl-read',
            'destroy', 'detach',
        ], true)) {
            throw new \RuntimeException("unknown environment provider action '$action'");
        }
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("$label has missing or unknown fields");
        }
    }

    private static function assertProviderId(mixed $value): void {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9._:@+-]{1,128}$/D', $value) !== 1) {
            throw new \RuntimeException('environment provider id is invalid');
        }
    }

    private static function assertIdentifier(mixed $value, string $label): void {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9._:@+-]{8,256}$/D', $value) !== 1) {
            throw new \RuntimeException("environment provider $label is invalid");
        }
    }

    private static function assertHash(mixed $value, string $label): void {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \RuntimeException("environment provider $label must be a SHA-256 digest");
        }
    }

    private static function assertGitOid(mixed $value): void {
        if (!is_string($value) || preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $value) !== 1) {
            throw new \RuntimeException('environment provider branch commit is invalid');
        }
    }

    private static function assertPositiveInt(mixed $value, string $label): void {
        if (!is_int($value) || $value < 1) {
            throw new \RuntimeException("environment provider $label must be a positive integer");
        }
    }

    private static function assertTimestamp(mixed $value): void {
        if (!is_string($value)) {
            throw new \RuntimeException('environment provider expiry must be canonical UTC seconds');
        }
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        if (!$time || $time->format('Y-m-d\TH:i:s\Z') !== $value) {
            throw new \RuntimeException('environment provider expiry must be canonical UTC seconds');
        }
    }

    /** @param resource $process @param array<int,mixed> $pipes */
    private static function stop($process, array $pipes): void {
        @proc_terminate($process, 9);
        foreach ($pipes as $pipe) {
            if (is_resource($pipe)) {
                @fclose($pipe);
            }
        }
        @proc_close($process);
    }
}

/** Append-only, machine-local evidence for materialization/reap recovery. */
final class EnvironmentLifecycleJournal {
    public const RUN_FORMAT = 'duo-branch-environment-run/v1';
    public const EVENT_FORMAT = 'duo-branch-environment-event/v1';

    public function __construct(private string $base) {
        $this->base = rtrim($base, '/');
        foreach ([$this->base, $this->base . '/runs', $this->base . '/targets'] as $path) {
            if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
                throw new \RuntimeException("could not create environment journal '$path'");
            }
        }
    }

    /**
     * Serialize host-side decisions for one logical target while the provider
     * holds the authoritative cross-host fence. The target name never enters
     * a filesystem path directly, so a registry typo cannot escape the
     * journal root. This lock is an optimization/correctness aid for one
     * controller; it is not substituted for the provider mutation fence.
     */
    public function synchronizedTarget(string $targetEnvironment, callable $callback): mixed {
        if ($targetEnvironment === '' || str_contains($targetEnvironment, "\0")) {
            throw new \RuntimeException('invalid environment journal target');
        }
        $path = $this->base . '/targets/' . hash('sha256', $targetEnvironment) . '.lock';
        $lock = fopen($path, 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException("could not lock environment target '$targetEnvironment'");
        }
        try {
            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @param array<string,mixed> $run */
    public function start(string $operationId, array $run): void {
        self::assertOperationId($operationId);
        self::assertExactRun($run, $operationId);
        $dir = $this->runDir($operationId);
        if (is_dir($dir)) {
            throw new \RuntimeException("environment operation '$operationId' already exists");
        }
        if (!mkdir($dir . '/events', 0700, true) && !is_dir($dir . '/events')) {
            throw new \RuntimeException("could not create environment operation '$operationId'");
        }
        $this->writeImmutable($dir . '/run.json', $run);
        $this->append($operationId, 'prepared', ['intent_sha256' => $run['intent_sha256']]);
    }

    /** @return array<string,mixed> */
    public function read(string $operationId): array {
        self::assertOperationId($operationId);
        $run = $this->readCanonical($this->runDir($operationId) . '/run.json', 'environment run');
        self::assertExactRun($run, $operationId);
        return $run;
    }

    /** @param array<string,mixed> $data */
    public function append(string $operationId, string $event, array $data): string {
        self::assertOperationId($operationId);
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $event) !== 1 || (array_is_list($data) && $data !== [])) {
            throw new \RuntimeException('invalid environment journal event');
        }
        $dir = $this->runDir($operationId);
        if (!is_dir($dir . '/events')) {
            throw new \RuntimeException("environment operation '$operationId' has no journal");
        }
        $lock = fopen($dir . '/.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) {
            throw new \RuntimeException("could not lock environment operation '$operationId'");
        }
        try {
            $events = glob($dir . '/events/*.json') ?: [];
            sort($events, SORT_STRING);
            $sequence = count($events) + 1;
            $previous = $events === []
                ? str_repeat('0', 64)
                : hash_file('sha256', $events[count($events) - 1]);
            if (!is_string($previous)) {
                throw new \RuntimeException('could not hash prior environment event');
            }
            $record = [
                'data' => $data,
                'event' => $event,
                'format' => self::EVENT_FORMAT,
                'operation_id' => $operationId,
                'previous_event_sha256' => $previous,
                'sequence' => $sequence,
                'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            ];
            $path = sprintf('%s/events/%04d-%s.json', $dir, $sequence, $event);
            $this->writeImmutable($path, $record);
            return $path;
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** @return list<array<string,mixed>> */
    public function events(string $operationId): array {
        self::assertOperationId($operationId);
        $paths = glob($this->runDir($operationId) . '/events/*.json') ?: [];
        sort($paths, SORT_STRING);
        $events = [];
        $previous = str_repeat('0', 64);
        foreach ($paths as $index => $path) {
            $event = $this->readCanonical($path, 'environment event');
            self::assertExactEvent($event, $operationId, $index + 1);
            if (($event['operation_id'] ?? null) !== $operationId
                || !hash_equals($previous, (string) ($event['previous_event_sha256'] ?? ''))) {
                throw new \RuntimeException("environment operation '$operationId' has a broken event chain");
            }
            $hash = hash_file('sha256', $path);
            if (!is_string($hash)) {
                throw new \RuntimeException('could not hash environment event');
            }
            $previous = $hash;
            $events[] = $event;
        }
        return $events;
    }

    /** @return ?array{operation_id:string,run:array<string,mixed>,events:list<array<string,mixed>>} */
    public function latestForTarget(string $targetEnvironment): ?array {
        $paths = glob($this->base . '/runs/*/run.json') ?: [];
        sort($paths, SORT_STRING);
        for ($i = count($paths) - 1; $i >= 0; $i--) {
            $run = $this->readCanonical($paths[$i], 'environment run');
            $operationId = basename(dirname($paths[$i]));
            self::assertExactRun($run, $operationId);
            if (($run['target_environment'] ?? null) !== $targetEnvironment) {
                continue;
            }
            return ['operation_id' => $operationId, 'run' => $run, 'events' => $this->events($operationId)];
        }
        return null;
    }

    public function runDir(string $operationId): string {
        self::assertOperationId($operationId);
        return $this->base . '/runs/' . $operationId;
    }

    /** @param array<string,mixed> $run */
    private static function assertExactRun(array $run, string $operationId): void {
        $expected = [
            'branch_commit', 'branch_ref', 'created_at', 'format', 'intent_sha256',
            'mode', 'operation_id', 'source_environment', 'target_environment', 'ttl_seconds',
        ];
        $actual = array_keys($run);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected || $run['format'] !== self::RUN_FORMAT
            || $run['operation_id'] !== $operationId
            || !is_string($run['source_environment']) || $run['source_environment'] === ''
            || !is_string($run['target_environment']) || $run['target_environment'] === ''
            || !is_string($run['branch_ref']) || $run['branch_ref'] === ''
            || !is_string($run['branch_commit'])
            || preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $run['branch_commit']) !== 1
            || !is_string($run['intent_sha256']) || preg_match('/^[a-f0-9]{64}$/D', $run['intent_sha256']) !== 1
            || !in_array($run['mode'], ['attach', 'create'], true)
            || !is_int($run['ttl_seconds']) || $run['ttl_seconds'] < 0
            || !is_string($run['created_at'])) {
            throw new \RuntimeException('environment run record is malformed');
        }
    }

    /** @param array<string,mixed> $event */
    private static function assertExactEvent(array $event, string $operationId, int $sequence): void {
        $expected = [
            'data', 'event', 'format', 'operation_id', 'previous_event_sha256', 'sequence', 'timestamp',
        ];
        $actual = array_keys($event);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected
            || $event['format'] !== self::EVENT_FORMAT
            || $event['operation_id'] !== $operationId
            || $event['sequence'] !== $sequence
            || !is_string($event['event'])
            || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $event['event']) !== 1
            || !is_array($event['data'])
            || !is_string($event['previous_event_sha256'])
            || preg_match('/^[a-f0-9]{64}$/D', $event['previous_event_sha256']) !== 1
            || !self::isCanonicalUtc($event['timestamp'] ?? null)) {
            throw new \RuntimeException("environment operation '$operationId' has a malformed event");
        }
    }

    private static function isCanonicalUtc(mixed $value): bool {
        if (!is_string($value)) return false;
        $time = \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:s\Z', $value, new \DateTimeZone('UTC'));
        return $time !== false && $time->format('Y-m-d\TH:i:s\Z') === $value;
    }

    private function writeImmutable(string $path, array $record): void {
        $bytes = EnvironmentLifecycleCanon::encode($record) . "\n";
        if (is_file($path)) {
            if (file_get_contents($path) === $bytes) {
                return;
            }
            throw new \RuntimeException("immutable environment journal record differs at '$path'");
        }
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(8));
        if (file_put_contents($tmp, $bytes, LOCK_EX) !== strlen($bytes)) {
            @unlink($tmp);
            throw new \RuntimeException("could not write environment journal '$path'");
        }
        @chmod($tmp, 0600);
        if (@link($tmp, $path)) {
            @unlink($tmp);
            return;
        }
        $same = is_file($path) && file_get_contents($path) === $bytes;
        @unlink($tmp);
        if (!$same) {
            throw new \RuntimeException("could not publish immutable environment journal '$path'");
        }
    }

    /** @return array<string,mixed> */
    private function readCanonical(string $path, string $label): array {
        $bytes = @file_get_contents($path);
        try {
            $value = is_string($bytes) ? json_decode($bytes, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable $e) {
            throw new \RuntimeException("$label is malformed JSON");
        }
        if (!is_array($value) || array_is_list($value)
            || EnvironmentLifecycleCanon::encode($value) . "\n" !== $bytes) {
            throw new \RuntimeException("$label is not canonical JSON");
        }
        return $value;
    }

    private static function assertOperationId(string $operationId): void {
        if (preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', $operationId) !== 1) {
            throw new \RuntimeException('invalid environment operation id');
        }
    }
}

/**
 * Product orchestration for "fresh production snapshot + branch delta".
 *
 * Providers own physical host resources and opaque snapshot sets. Refresh
 * owns semantic B/P/W materialization. The supplied promotion callback owns
 * the existing code-stage/lifecycle/finalize/apply transaction. Keeping those
 * three boundaries explicit prevents infrastructure code from learning
 * WordPress or plugin semantics.
 */
final class EnvironmentMaterializer {
    /**
     * @param array{branch:string,create:bool,ttl_seconds:int} $options
     * @param callable(EnvironmentDriver):int $promote
     * @return array<string,mixed>
     */
    public static function materialize(
        EnvironmentDriver $sourceDriver,
        EnvironmentDriver $targetDriver,
        CommandEnvironmentProvider $sourceProvider,
        CommandEnvironmentProvider $targetProvider,
        EnvironmentLifecycleJournal $journal,
        array $options,
        callable $promote
    ): array {
        $optionKeys = array_keys($options);
        sort($optionKeys, SORT_STRING);
        if ($optionKeys !== ['branch', 'create', 'ttl_seconds']
            || !is_string($options['branch']) || $options['branch'] === ''
            || !is_bool($options['create'])
            || !is_int($options['ttl_seconds']) || $options['ttl_seconds'] < 0
            || $options['ttl_seconds'] > 2592000
            || ($options['ttl_seconds'] > 0 && $options['ttl_seconds'] < 60)) {
            throw new \RuntimeException('branch materialization options are malformed');
        }
        $root = self::repositoryRoot();
        self::assertCleanBranch($root);
        $requestedCommit = self::gitStdout($root, ['rev-parse', '--verify', $options['branch'] . '^{commit}']);
        $head = self::gitStdout($root, ['rev-parse', '--verify', 'HEAD^{commit}']);
        if (!hash_equals($requestedCommit, $head)) {
            throw new \RuntimeException(
                '--branch must resolve to the clean branch currently checked out; switch to that branch before materializing'
            );
        }
        if ($sourceDriver->name() === $targetDriver->name()) {
            throw new \RuntimeException('source and target environments must be distinct');
        }
        $mode = $options['create'] ? 'create' : 'attach';
        $intent = [
            'branch_commit' => $requestedCommit,
            'branch_ref' => $options['branch'],
            'mode' => $mode,
            'source_environment' => $sourceDriver->name(),
            'target_environment' => $targetDriver->name(),
            'ttl_seconds' => $options['ttl_seconds'],
        ];
        $intentSha = hash('sha256', EnvironmentLifecycleCanon::encode($intent));
        $latest = $journal->latestForTarget($targetDriver->name());
        $operationId = self::operationId();
        $events = [];
        if ($latest !== null && !self::hasEvent($latest['events'], 'reaped')) {
            if (!hash_equals((string) ($latest['run']['intent_sha256'] ?? ''), $intentSha)) {
                throw new \RuntimeException(
                    "target '{$targetDriver->name()}' already has an unreaped environment operation; reap it before changing intent"
                );
            }
            $operationId = $latest['operation_id'];
            $events = $latest['events'];
            $completed = self::lastEvent($events, 'complete');
            if ($completed !== null) {
                return $completed['data'] + ['operation_id' => $operationId, 'resumed' => true];
            }
        } else {
            $journal->start($operationId, [
                'branch_commit' => $requestedCommit,
                'branch_ref' => $options['branch'],
                'created_at' => gmdate('Y-m-d\TH:i:s\Z'),
                'format' => EnvironmentLifecycleJournal::RUN_FORMAT,
                'intent_sha256' => $intentSha,
                'mode' => $mode,
                'operation_id' => $operationId,
                'source_environment' => $sourceDriver->name(),
                'target_environment' => $targetDriver->name(),
                'ttl_seconds' => $options['ttl_seconds'],
            ]);
            $events = $journal->events($operationId);
        }

        try {
            // Composite, target-free preflight precedes the first physical
            // source snapshot and every target mutation.
            $sourceCapabilities = $sourceProvider->capabilities($operationId);
            $sourceCapabilities->require([
                EnvironmentProviderCapability::ENVIRONMENT_INSPECT,
                EnvironmentProviderCapability::SNAPSHOT_SET_CREATE,
                EnvironmentProviderCapability::SNAPSHOT_SET_READ,
                EnvironmentProviderCapability::OPERATION_RECEIPTS,
            ], 'materialize a production snapshot');
            $targetRequired = [
                EnvironmentProviderCapability::ENVIRONMENT_INSPECT,
                EnvironmentProviderCapability::SNAPSHOT_SET_RESTORE,
                EnvironmentProviderCapability::REPOSITORY_MATERIALIZE,
                EnvironmentProviderCapability::URL_DISCOVER,
                EnvironmentProviderCapability::URL_SET,
                EnvironmentProviderCapability::OPERATION_RECEIPTS,
                $mode === 'create'
                    ? EnvironmentProviderCapability::ENVIRONMENT_CREATE
                    : EnvironmentProviderCapability::ENVIRONMENT_ATTACH,
                $mode === 'create'
                    ? EnvironmentProviderCapability::ENVIRONMENT_DESTROY
                    : EnvironmentProviderCapability::ENVIRONMENT_DETACH,
            ];
            if ($options['ttl_seconds'] > 0) {
                $targetRequired[] = EnvironmentProviderCapability::ENVIRONMENT_TTL;
            }
            $targetCapabilities = $targetProvider->capabilities($operationId);
            $targetCapabilities->require($targetRequired, "materialize a $mode branch environment");
            self::requireDriver($sourceDriver, 'refresh');
            self::requireDriver($targetDriver, 'promote');
            $journal->append($operationId, 'preflight', [
                'source_capabilities' => $sourceCapabilities->digest(),
                'target_capabilities' => $targetCapabilities->digest(),
            ]);

            $sourceIdentity = $sourceProvider->perform('inspect', $operationId, ['role' => 'source']);
            if (($sourceIdentity['presence'] ?? null) !== 'present') {
                throw new \RuntimeException('production source provider reports that the environment is absent');
            }
            $journal->append($operationId, 'source-inspected', self::publicEvidence($sourceIdentity));

            $candidateEvent = self::lastEvent($events, 'semantic-candidate');
            if ($candidateEvent === null) {
                $productionCommit = self::productionCommit($sourceDriver);
                $candidateRef = 'duo/materialize/' . $operationId;
                if (self::refExists($root, $candidateRef)) {
                    throw new \RuntimeException(
                        "owned candidate ref '$candidateRef' exists without a materialization journal event; refusing uncertain reuse"
                    );
                }
                $candidate = Refresh::rebase($sourceDriver, $productionCommit, $candidateRef);
                $plan = self::readJson((string) $candidate['plan_path'], 'refresh plan');
                $semantic = [
                    'branch_commit' => (string) $candidate['head'],
                    'candidate_ref' => $candidateRef,
                    'plan_hash' => (string) ($plan['plan_hash'] ?? ''),
                    'plan_path' => (string) $candidate['plan_path'],
                    'production_commit' => $productionCommit,
                    'semantic_snapshot_sha256' => (string) ($plan['context']['production_snapshot_hash'] ?? ''),
                ];
                foreach (['plan_hash', 'semantic_snapshot_sha256'] as $key) {
                    self::assertHash($semantic[$key], str_replace('_', ' ', $key));
                }
                $journal->append($operationId, 'semantic-candidate', $semantic);
            } else {
                $semantic = $candidateEvent['data'];
                if (!self::refMatches($root, (string) $semantic['candidate_ref'], (string) $semantic['branch_commit'])) {
                    throw new \RuntimeException('journaled semantic candidate ref no longer resolves to its exact commit');
                }
            }

            $snapshot = $sourceProvider->perform('snapshot-create', $operationId, [
                'expected_semantic_snapshot_sha256' => $semantic['semantic_snapshot_sha256'],
                'production_commit' => $semantic['production_commit'],
                'source_environment_identity' => $sourceIdentity['environment_identity'],
            ]);
            if (!hash_equals(
                (string) $semantic['semantic_snapshot_sha256'],
                (string) $snapshot['semantic_snapshot_sha256']
            ) || !hash_equals(
                (string) $sourceIdentity['environment_identity'],
                (string) $snapshot['source_identity']
            )) {
                throw new \RuntimeException('physical DB/media snapshot set is not coherent with semantic production truth');
            }
            $journal->append($operationId, 'snapshot-created', self::publicEvidence($snapshot));

            $targetIdentity = $targetProvider->perform($mode, $operationId, [
                'intent_sha256' => $intentSha,
                'mode' => $mode,
                'target_environment' => $targetDriver->name(),
            ]);
            self::requirePresence($targetIdentity);
            $journal->append($operationId, 'target-acquired', self::publicEvidence($targetIdentity) + ['mode' => $mode]);

            $restore = $targetProvider->perform('snapshot-restore', $operationId, [
                'database_sha256' => $snapshot['database_sha256'],
                'expected_environment_identity' => $targetIdentity['environment_identity'],
                'expected_lease_generation' => $targetIdentity['lease_generation'],
                'expected_lease_id' => $targetIdentity['lease_id'],
                'expected_ownership_receipt_sha256' => $targetIdentity['ownership_receipt_sha256'],
                'expected_resource_id' => $targetIdentity['resource_id'],
                'media_sha256' => $snapshot['media_sha256'],
                'snapshot_set_id' => $snapshot['snapshot_set_id'],
            ]);
            self::assertSameIdentity($targetIdentity, $restore);
            if (!hash_equals((string) $snapshot['snapshot_set_id'], (string) $restore['snapshot_set_id'])) {
                throw new \RuntimeException('target restored another snapshot set');
            }
            $journal->append($operationId, 'snapshot-restored', self::publicEvidence($restore));

            $repository = $targetProvider->perform('repository-materialize', $operationId, [
                'branch_commit' => $semantic['branch_commit'],
                'branch_ref' => $semantic['candidate_ref'],
                'expected_environment_identity' => $targetIdentity['environment_identity'],
                'expected_lease_generation' => $targetIdentity['lease_generation'],
                'expected_lease_id' => $targetIdentity['lease_id'],
                'expected_ownership_receipt_sha256' => $targetIdentity['ownership_receipt_sha256'],
                'expected_resource_id' => $targetIdentity['resource_id'],
                'repo_path' => $targetDriver->repoPath(),
            ]);
            self::assertSameIdentity($targetIdentity, $repository);
            if (!hash_equals((string) $semantic['branch_commit'], (string) $repository['branch_commit'])) {
                throw new \RuntimeException('provider materialized another branch commit');
            }
            $journal->append($operationId, 'repository-materialized', self::publicEvidence($repository));

            // The pre-restore target URL is provider-owned environment state,
            // never canonical branch data. Restore may have imported the
            // production URL, so set and read it back before user lifecycle.
            $url = $targetProvider->perform('url-set', $operationId, [
                'expected_environment_identity' => $targetIdentity['environment_identity'],
                'expected_lease_generation' => $targetIdentity['lease_generation'],
                'expected_lease_id' => $targetIdentity['lease_id'],
                'expected_ownership_receipt_sha256' => $targetIdentity['ownership_receipt_sha256'],
                'expected_resource_id' => $targetIdentity['resource_id'],
                'url' => $targetIdentity['url'],
            ]);
            self::assertSameIdentity($targetIdentity, $url);
            if (!hash_equals((string) $targetIdentity['url'], (string) $url['url'])) {
                throw new \RuntimeException('target URL readback does not match its provider-owned URL');
            }
            $journal->append($operationId, 'url-set', self::publicEvidence($url));

            $artifactPath = rtrim($targetDriver->repoPath(), '/') . '/.duo/artifacts/materialize-' . $operationId . '.json';
            $mkdir = $targetDriver->captureRaw('mkdir -p ' . escapeshellarg(dirname($artifactPath)));
            if (($mkdir['exit'] ?? 1) !== 0) {
                throw new \RuntimeException('could not create target materialization artifact directory');
            }
            $compiled = CodeDeploy::compile($targetDriver, $targetDriver->repoPath(), $artifactPath);
            if (($compiled['exit'] ?? 1) !== 0 || !is_array($compiled['summary'] ?? null)) {
                throw new \RuntimeException('branch candidate did not compile on the target');
            }
            $release = self::releaseIdentity($compiled['summary']);
            $journal->append($operationId, 'release-compiled', $release);

            $promotionExit = $promote($targetDriver);
            if ($promotionExit !== 0) {
                throw new \RuntimeException("branch code/state promotion failed with exit $promotionExit");
            }
            $verifyArtifact = rtrim($targetDriver->repoPath(), '/') . '/.duo/artifacts/materialize-verify-' . $operationId . '.json';
            $verifiedCompile = CodeDeploy::compile($targetDriver, $targetDriver->repoPath(), $verifyArtifact);
            if (($verifiedCompile['exit'] ?? 1) !== 0 || !is_array($verifiedCompile['summary'] ?? null)) {
                throw new \RuntimeException('could not recompile the promoted branch release for verification');
            }
            $verifiedRelease = self::releaseIdentity($verifiedCompile['summary']);
            if ($verifiedRelease !== $release) {
                throw new \RuntimeException('target repository changed between frozen promotion and release verification');
            }
            $planResult = $targetDriver->captureWp([
                'duo', 'plan', '--repo=' . $targetDriver->repoPath(), '--format=json',
            ]);
            if (($planResult['exit'] ?? 1) !== 0) {
                throw new \RuntimeException('could not verify branch environment convergence');
            }
            try {
                $finalPlan = json_decode(trim((string) $planResult['stdout']), true, 512, JSON_THROW_ON_ERROR);
            } catch (\Throwable $e) {
                throw new \RuntimeException('branch environment convergence returned malformed JSON');
            }
            if (!is_array($finalPlan) || !PlanSummary::render($finalPlan)['ok']) {
                throw new \RuntimeException('branch environment did not converge to a clean code/state plan');
            }
            $journal->append($operationId, 'release-converged', $release);

            $finalIdentity = $targetProvider->perform('inspect', $operationId, [
                'expected_environment_identity' => $targetIdentity['environment_identity'],
                'expected_lease_generation' => $targetIdentity['lease_generation'],
                'expected_lease_id' => $targetIdentity['lease_id'],
                'expected_ownership_receipt_sha256' => $targetIdentity['ownership_receipt_sha256'],
                'expected_resource_id' => $targetIdentity['resource_id'],
                'role' => 'target',
            ]);
            self::requirePresence($finalIdentity);
            self::assertSameIdentity($targetIdentity, $finalIdentity);
            if (!hash_equals((string) $targetIdentity['url'], (string) $finalIdentity['url'])) {
                throw new \RuntimeException('target URL changed during branch promotion');
            }

            $ttl = null;
            if ($options['ttl_seconds'] > 0) {
                $ttl = $targetProvider->perform('ttl-set', $operationId, [
                    'expected_environment_identity' => $targetIdentity['environment_identity'],
                    'expected_lease_generation' => $targetIdentity['lease_generation'],
                    'expected_lease_id' => $targetIdentity['lease_id'],
                    'expected_ownership_receipt_sha256' => $targetIdentity['ownership_receipt_sha256'],
                    'expected_resource_id' => $targetIdentity['resource_id'],
                    'ttl_seconds' => $options['ttl_seconds'],
                ]);
                self::assertSameIdentity($targetIdentity, $ttl);
                $journal->append($operationId, 'ttl-set', self::publicEvidence($ttl));
            }

            $receipt = [
                'branch_commit' => $semantic['branch_commit'],
                'code_revision' => $release['code_revision'],
                'environment_identity' => $targetIdentity['environment_identity'],
                'expires_at' => $ttl['expires_at'] ?? null,
                'format' => 'duo-branch-environment-receipt/v1',
                'lease_generation' => $targetIdentity['lease_generation'],
                'lease_id' => $targetIdentity['lease_id'],
                'mode' => $mode,
                'operation_id' => $operationId,
                'outer_artifact_hash' => $release['outer_artifact_hash'],
                'ownership_receipt_sha256' => $targetIdentity['ownership_receipt_sha256'],
                'plan_hash' => $semantic['plan_hash'],
                'provider' => $finalIdentity['_provider'],
                'repository_receipt_sha256' => $repository['repository_receipt_sha256'],
                'resource_id' => $targetIdentity['resource_id'],
                'snapshot_set_id' => $snapshot['snapshot_set_id'],
                'source_capabilities' => $sourceCapabilities->digest(),
                'state_revision' => $release['state_revision'],
                'target_capabilities' => $targetCapabilities->digest(),
                'url' => $finalIdentity['url'],
            ];
            $receipt['receipt_sha256'] = hash('sha256', EnvironmentLifecycleCanon::encode($receipt));
            $journal->append($operationId, 'complete', $receipt);
            return $receipt + ['resumed' => $latest !== null];
        } catch (\Throwable $e) {
            $journal->append($operationId, 'stopped', ['reason' => $e->getMessage()]);
            throw $e;
        }
    }

    /** @return array<string,mixed> */
    public static function reap(
        EnvironmentDriver $targetDriver,
        CommandEnvironmentProvider $targetProvider,
        EnvironmentLifecycleJournal $journal
    ): array {
        $latest = $journal->latestForTarget($targetDriver->name());
        if ($latest === null) {
            throw new \RuntimeException("target '{$targetDriver->name()}' has no materialization receipt");
        }
        $operationId = $latest['operation_id'];
        $already = self::lastEvent($latest['events'], 'reaped');
        if ($already !== null) {
            return $already['data'] + ['operation_id' => $operationId, 'resumed' => true];
        }
        $acquired = self::lastEvent($latest['events'], 'target-acquired');
        if ($acquired === null) {
            throw new \RuntimeException('materialization never acquired a target; no resource is safe to reap');
        }
        $identity = $acquired['data'];
        $mode = (string) ($identity['mode'] ?? '');
        $action = $mode === 'create' ? 'destroy' : ($mode === 'attach' ? 'detach' : '');
        if ($action === '') {
            throw new \RuntimeException('materialization receipt has no valid target ownership mode');
        }
        $capabilities = $targetProvider->capabilities($operationId);
        $capabilities->require([
            EnvironmentProviderCapability::ENVIRONMENT_INSPECT,
            EnvironmentProviderCapability::OPERATION_RECEIPTS,
            $action === 'destroy'
                ? EnvironmentProviderCapability::ENVIRONMENT_DESTROY
                : EnvironmentProviderCapability::ENVIRONMENT_DETACH,
        ], "reap a $mode branch environment");

        $expected = self::identityInput($identity);
        $current = $targetProvider->perform('inspect', $operationId, $expected + ['role' => 'target']);
        self::requirePresence($current);
        self::assertSameIdentity($identity, $current);
        $result = $targetProvider->perform($action, $operationId, $expected + [
            'compare_and_reap' => true,
        ]);
        self::assertSameIdentity($identity, $result);

        $semantic = self::lastEvent($latest['events'], 'semantic-candidate');
        if ($semantic !== null) {
            $root = self::repositoryRoot();
            $ref = (string) ($semantic['data']['candidate_ref'] ?? '');
            $commit = (string) ($semantic['data']['branch_commit'] ?? '');
            if ($ref !== '' && self::refExists($root, $ref)) {
                self::git($root, ['update-ref', '-d', 'refs/heads/' . $ref, $commit]);
            }
        }
        $receipt = [
            'absence_proof_sha256' => $result['absence_proof_sha256'],
            'disposition' => $result['disposition'],
            'environment_identity' => $identity['environment_identity'],
            'format' => 'duo-branch-environment-reap/v1',
            'operation_id' => $operationId,
            'resource_id' => $identity['resource_id'],
        ];
        $receipt['receipt_sha256'] = hash('sha256', EnvironmentLifecycleCanon::encode($receipt));
        $journal->append($operationId, 'reaped', $receipt);
        return $receipt + ['resumed' => false];
    }

    /** @return array<string,mixed> */
    private static function releaseIdentity(array $summary): array {
        $artifact = (string) ($summary['artifact_hash'] ?? '');
        $state = (string) ($summary['revision_hash'] ?? '');
        $code = (string) ($summary['code']['code_revision'] ?? hash('sha256', 'duo:no-code-descriptor'));
        self::assertHash($artifact, 'outer artifact hash');
        self::assertHash($state, 'state revision');
        self::assertHash($code, 'code revision');
        return [
            'code_revision' => $code,
            'outer_artifact_hash' => $artifact,
            'state_revision' => $state,
        ];
    }

    private static function productionCommit(EnvironmentDriver $source): string {
        $result = $source->captureRaw(
            'git -C ' . escapeshellarg($source->repoPath()) . ' rev-parse --verify HEAD^{commit}'
        );
        $commit = trim((string) ($result['stdout'] ?? ''));
        if (($result['exit'] ?? 1) !== 0 || preg_match('/^[a-f0-9]{40}(?:[a-f0-9]{24})?$/D', $commit) !== 1) {
            throw new \RuntimeException('could not verify production environment Git commit');
        }
        return $commit;
    }

    private static function requireDriver(EnvironmentDriver $driver, string $operation): void {
        $report = $driver->capabilityReport($operation);
        if (!$report->ready()) {
            $missing = array_map(
                static fn(array $row): string => (string) $row['capability'],
                $report->blockers()
            );
            throw new \RuntimeException(
                "driver '{$driver->driverId()}' cannot $operation; missing " . implode(', ', $missing)
            );
        }
    }

    /** @param array<string,mixed> $expected @param array<string,mixed> $actual */
    private static function assertSameIdentity(array $expected, array $actual): void {
        foreach (['environment_identity', 'resource_id', 'lease_id', 'lease_generation', 'ownership_receipt_sha256'] as $key) {
            if (($expected[$key] ?? null) !== ($actual[$key] ?? null)) {
                throw new \RuntimeException("environment provider $key changed; refusing stale or foreign resource mutation");
            }
        }
    }

    /** @param array<string,mixed> $identity */
    private static function requirePresence(array $identity): void {
        if (($identity['presence'] ?? 'present') !== 'present') {
            throw new \RuntimeException('environment provider reports that the target resource is absent');
        }
    }

    /** @param array<string,mixed> $identity @return array<string,mixed> */
    private static function identityInput(array $identity): array {
        return [
            'expected_environment_identity' => $identity['environment_identity'],
            'expected_lease_generation' => $identity['lease_generation'],
            'expected_lease_id' => $identity['lease_id'],
            'expected_ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
            'expected_resource_id' => $identity['resource_id'],
        ];
    }

    /** Provider-private response digest is useful operational evidence; no raw secret output is recorded. */
    private static function publicEvidence(array $result): array {
        return $result;
    }

    /** @param list<array<string,mixed>> $events */
    private static function hasEvent(array $events, string $name): bool {
        return self::lastEvent($events, $name) !== null;
    }

    /** @param list<array<string,mixed>> $events @return ?array<string,mixed> */
    private static function lastEvent(array $events, string $name): ?array {
        for ($i = count($events) - 1; $i >= 0; $i--) {
            if (($events[$i]['event'] ?? null) === $name) {
                return $events[$i];
            }
        }
        return null;
    }

    /** @return array<string,mixed> */
    private static function readJson(string $path, string $label): array {
        $bytes = @file_get_contents($path);
        try {
            $value = is_string($bytes) ? json_decode($bytes, true, 512, JSON_THROW_ON_ERROR) : null;
        } catch (\Throwable $e) {
            throw new \RuntimeException("$label is malformed JSON");
        }
        if (!is_array($value)) {
            throw new \RuntimeException("$label is missing or malformed");
        }
        return $value;
    }

    private static function repositoryRoot(): string {
        return self::gitStdout(getcwd() ?: '.', ['rev-parse', '--show-toplevel']);
    }

    private static function assertCleanBranch(string $root): void {
        if (self::gitStdout($root, ['symbolic-ref', '--short', 'HEAD']) === '') {
            throw new \RuntimeException('branch materialization requires an attached source branch');
        }
        if (trim(self::gitStdout($root, ['status', '--porcelain=v1', '--untracked-files=all'])) !== '') {
            throw new \RuntimeException('branch materialization requires a clean source checkout');
        }
    }

    private static function refExists(string $root, string $branch): bool {
        $result = self::run(['git', '-C', $root, 'show-ref', '--verify', '--quiet', 'refs/heads/' . $branch]);
        if ($result['exit'] === 0) return true;
        if ($result['exit'] === 1) return false;
        throw new \RuntimeException("could not inspect local candidate ref '$branch'");
    }

    private static function refMatches(string $root, string $branch, string $commit): bool {
        if (!self::refExists($root, $branch)) return false;
        return hash_equals($commit, self::gitStdout($root, ['rev-parse', '--verify', 'refs/heads/' . $branch . '^{commit}']));
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    private static function run(array $command): array {
        $process = proc_open(
            $command,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            null,
            null,
            ['bypass_shell' => true]
        );
        if (!is_resource($process)) {
            return ['exit' => 255, 'stdout' => '', 'stderr' => 'could not start process'];
        }
        fclose($pipes[0]);
        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        return ['exit' => proc_close($process), 'stdout' => $stdout, 'stderr' => $stderr];
    }

    private static function git(string $cwd, array $args): void {
        $result = self::run(array_merge(['git', '-C', $cwd], $args));
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ' failed');
        }
    }

    private static function gitStdout(string $cwd, array $args): string {
        $result = self::run(array_merge(['git', '-C', $cwd], $args));
        if ($result['exit'] !== 0) {
            throw new \RuntimeException('git ' . implode(' ', $args) . ' failed');
        }
        return trim($result['stdout']);
    }

    private static function assertHash(string $value, string $label): void {
        if (preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \RuntimeException("$label must be a SHA-256 digest");
        }
    }

    private static function operationId(): string {
        return gmdate('Ymd-His') . '-' . bin2hex(random_bytes(12));
    }
}

/** Shared deterministic encoding for provider evidence and the host journal. */
final class EnvironmentLifecycleCanon {
    public static function encode(mixed $value): string {
        return json_encode(
            self::normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private static function normalize(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::normalize($child);
        }
        return $value;
    }
}
