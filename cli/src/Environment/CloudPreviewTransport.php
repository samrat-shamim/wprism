<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/../Transport/Transport.php';
require_once __DIR__ . '/EnvironmentLifecycle.php';
require_once __DIR__ . '/ImmutableOciReference.php';
require_once __DIR__ . '/../Refresh/CloudOriginExportClient.php';

/**
 * Signed control path to one Duo Cloud preview lease.
 *
 * The endpoint and controller key are machine-local authority. A provider
 * owns allocation; this driver remains inert until EnvironmentMaterializer
 * binds the exact resource generation and held mutation fence returned by
 * that provider. The cloud service must compare that tuple before executing
 * arbitrary WordPress or shell code inside the isolated preview workload.
 */
final class CloudPreviewTransport extends Transport implements ProviderLeaseBoundEnvironmentDriver, EnvironmentProviderClient {
    private const ENVELOPE_FORMAT = 'duo-cloud-preview-signed-envelope/v1';
    private const REQUEST_FORMAT = 'duo-cloud-preview-control-request/v1';
    private const RESPONSE_FORMAT = 'duo-cloud-preview-control-response/v1';
    private const LIFECYCLE_REQUEST_FORMAT = 'duo-cloud-preview-lifecycle-request/v1';
    private const LIFECYCLE_RESPONSE_FORMAT = 'duo-cloud-preview-lifecycle-response/v1';
    private const RESPONSE_LIMIT = 16777216;

    private string $endpoint;
    private string $lifecycleEndpoint;
    private string $tenantId;
    private string $siteId;
    private string $requestKeyId;
    private string $requestSecretKey;
    private string $responseKeyId;
    private string $responsePublicKey;
    private int $timeoutSeconds;
    /** @var ?array<string,mixed> */
    private ?array $binding = null;
    private ?string $commandPhase = null;
    private int $commandIndex = 0;
    /** @var array<string,true> */
    private array $begunPhases = [];
    private bool $released = false;
    /** @var ?array{id:string,protocol:int} */
    private ?array $negotiatedProvider = null;
    /** @var ?array<string,mixed> */
    private ?array $reviewedBaseContainment = null;
    /** @var ?array<string,mixed> */
    private ?array $repositoryAuthority = null;

    /** @param array<string,mixed> $cfg */
    public function __construct(string $name, array $cfg) {
        parent::__construct($name, $cfg);
        if (($cfg['_machine_local'] ?? false) !== true) {
            throw new \RuntimeException(
                "env '$name': cloud-preview authority is allowed only in an untracked .duo-envs.json"
            );
        }
        $cloud = $cfg['cloud_preview'] ?? null;
        if (!is_array($cloud) || array_is_list($cloud)) {
            throw new \RuntimeException("env '$name': cloud-preview transport requires a cloud_preview object");
        }
        self::assertExactKeys(
            $cloud,
            [
                'control_endpoint', 'lifecycle_endpoint', 'request_key_id', 'request_signing_key',
                'response_key_id', 'response_public_key', 'site_id',
                'tenant_id', 'timeout_seconds',
            ],
            "env '$name': cloud_preview"
        );
        $this->endpoint = self::endpoint($name, $cloud['control_endpoint'] ?? null, 'control_endpoint');
        $this->lifecycleEndpoint = self::endpoint(
            $name,
            $cloud['lifecycle_endpoint'] ?? null,
            'lifecycle_endpoint'
        );
        $this->tenantId = self::identifier($cloud['tenant_id'] ?? null, "env '$name': cloud_preview.tenant_id");
        $this->siteId = self::identifier($cloud['site_id'] ?? null, "env '$name': cloud_preview.site_id");
        $this->requestKeyId = self::identifier(
            $cloud['request_key_id'] ?? null,
            "env '$name': cloud_preview.request_key_id"
        );
        $this->responseKeyId = self::identifier(
            $cloud['response_key_id'] ?? null,
            "env '$name': cloud_preview.response_key_id"
        );
        $timeout = $cloud['timeout_seconds'] ?? null;
        if (!is_int($timeout) || $timeout < 1 || $timeout > 300) {
            throw new \RuntimeException("env '$name': cloud_preview.timeout_seconds must be 1..300");
        }
        $this->timeoutSeconds = $timeout;

        $requestKeyPath = self::keyPath($name, $cfg, $cloud, 'request_signing_key');
        $responseKeyPath = self::keyPath($name, $cfg, $cloud, 'response_public_key');
        $this->requestSecretKey = self::key(
            $name,
            $requestKeyPath,
            SODIUM_CRYPTO_SIGN_SECRETKEYBYTES,
            'request signing key',
            true
        );
        $this->responsePublicKey = self::key(
            $name,
            $responseKeyPath,
            SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            'response public key',
            false
        );
        // EnvironmentLifecycle journals driverId before target contact. Bind
        // it to routing and both public trust anchors so a same-named config
        // cannot resume an operation against another tenant, site, service,
        // or rotated controller identity.
        $this->driverId = 'cloud-preview:' . hash('sha256', self::encode([
            'control_endpoint' => $this->endpoint,
            'lifecycle_endpoint' => $this->lifecycleEndpoint,
            'request_key_id' => $this->requestKeyId,
            'request_public_key_sha256' => hash(
                'sha256',
                sodium_crypto_sign_publickey_from_secretkey($this->requestSecretKey)
            ),
            'response_key_id' => $this->responseKeyId,
            'response_public_key_sha256' => hash('sha256', $this->responsePublicKey),
            'site_id' => $this->siteId,
            'tenant_id' => $this->tenantId,
        ]));
    }

    public function describe(): string {
        return "cloud-preview control_endpoint={$this->endpoint} lifecycle_endpoint={$this->lifecycleEndpoint} tenant_id={$this->tenantId} site_id={$this->siteId} repo_path={$this->repoPath}";
    }

    public function __destruct() {
        if ($this->requestSecretKey !== '') {
            sodium_memzero($this->requestSecretKey);
        }
    }

    public function environment(): string {
        return $this->name;
    }

    public function capabilities(string $operationId): EnvironmentProviderCapabilityReport {
        $evidence = $this->lifecycleCapabilities($operationId);
        $capabilities = $evidence['capabilities'];
        $provider = $evidence['provider'];
        return new EnvironmentProviderCapabilityReport(
            $this->name,
            $provider['id'],
            $provider['protocol'],
            $capabilities
        );
    }

    /**
     * Fetch the service-signed clean-base containment attestation without
     * creating or acquiring a preview slot.
     *
     * @return array<string,mixed>
     */
    public function reviewedBaseContainment(string $operationId): array {
        return $this->lifecycleCapabilities($operationId)['reviewed_base_containment'];
    }

    /**
     * Fetch the service-signed credential-free Git publication authority.
     * Repeated reads are compared with the first signed descriptor so recovery
     * cannot silently switch remote or ref authority.
     *
     * @return array<string,mixed>
     */
    public function repositoryAuthority(string $operationId): array {
        return $this->lifecycleCapabilities($operationId)['repository_authority'];
    }

    /**
     * Reuse this transport's paired controller identity for committed origin
     * export reads. Both preview endpoints must name one exact service
     * authority before the fixed origin route can be derived.
     */
    public function originExportClient(): CloudOriginExportClient {
        $control = self::serviceAuthority($this->endpoint, '/v1/preview/control', 'control');
        $lifecycle = self::serviceAuthority(
            $this->lifecycleEndpoint,
            '/v1/preview/lifecycle',
            'lifecycle'
        );
        foreach (['scheme', 'host', 'port'] as $field) {
            if ($control[$field] !== $lifecycle[$field]) {
                throw new \RuntimeException(
                    'cloud preview control and lifecycle endpoints must share one service authority'
                );
            }
        }
        $host = str_contains($control['host'], ':') ? '[' . $control['host'] . ']' : $control['host'];
        $defaultPort = $control['scheme'] === 'https' ? 443 : 80;
        $authority = $control['scheme'] . '://' . $host
            . ($control['port'] === $defaultPort ? '' : ':' . $control['port']);
        return new CloudOriginExportClient(
            $authority . '/v1/origin/controller/export',
            $this->tenantId,
            $this->siteId,
            $this->requestKeyId,
            $this->requestSecretKey,
            $this->responseKeyId,
            $this->responsePublicKey,
            $this->timeoutSeconds
        );
    }

    /**
     * @return array{
     *   capabilities:list<string>,
     *   provider:array{id:string,protocol:int},
     *   repository_authority:array<string,mixed>,
     *   reviewed_base_containment:array<string,mixed>
     * }
     */
    private function lifecycleCapabilities(string $operationId): array {
        $response = $this->lifecycleCall('capabilities', $operationId, []);
        self::assertExactKeys(
            $response['result'],
            ['capabilities', 'repository_authority', 'reviewed_base_containment'],
            'cloud preview lifecycle capabilities'
        );
        $capabilities = $response['result']['capabilities'];
        if (!is_array($capabilities) || !array_is_list($capabilities)) {
            throw new \RuntimeException('cloud preview lifecycle capabilities must be a list');
        }
        foreach ($capabilities as $capability) {
            if (!is_string($capability)) {
                throw new \RuntimeException('cloud preview lifecycle capability IDs must be strings');
            }
        }
        $containment = $response['result']['reviewed_base_containment'];
        if (!is_array($containment) || array_is_list($containment)) {
            throw new \RuntimeException('cloud preview lifecycle reviewed-base containment must be an object');
        }
        self::assertReviewedBaseContainment($containment);
        $repositoryAuthority = $response['result']['repository_authority'];
        if (!is_array($repositoryAuthority) || array_is_list($repositoryAuthority)) {
            throw new \RuntimeException('cloud preview lifecycle repository authority must be an object');
        }
        self::assertRepositoryAuthority($repositoryAuthority);
        $provider = $response['provider'];
        if ($this->negotiatedProvider !== null && $this->negotiatedProvider !== $provider) {
            throw new \RuntimeException('cloud preview lifecycle provider identity changed during negotiation');
        }
        if ($this->reviewedBaseContainment !== null
            && self::encode($this->reviewedBaseContainment) !== self::encode($containment)) {
            throw new \RuntimeException('cloud preview reviewed-base containment changed during negotiation');
        }
        if ($this->repositoryAuthority !== null
            && self::encode($this->repositoryAuthority) !== self::encode($repositoryAuthority)) {
            throw new \RuntimeException('cloud preview repository authority changed during negotiation');
        }
        $this->negotiatedProvider = $provider;
        $this->reviewedBaseContainment = $containment;
        $this->repositoryAuthority = $repositoryAuthority;
        return [
            'capabilities' => array_values($capabilities),
            'provider' => $provider,
            'repository_authority' => $repositoryAuthority,
            'reviewed_base_containment' => $containment,
        ];
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    public function perform(string $action, string $operationId, array $input): array {
        if ($this->negotiatedProvider === null) {
            throw new \RuntimeException('cloud preview lifecycle capabilities must be negotiated before operations');
        }
        $response = $this->lifecycleCall($action, $operationId, $input);
        if ($response['provider'] !== $this->negotiatedProvider) {
            throw new \RuntimeException('cloud preview lifecycle provider identity changed after negotiation');
        }
        CommandEnvironmentProvider::validateActionResult($action, $response['result']);
        return $response['result'] + [
            '_provider' => $response['provider'],
            '_response_sha256' => $response['response_sha256'],
        ];
    }

    /**
     * @param array<string,mixed> $identity
     * @param array<string,mixed> $mutationFence
     */
    public function bindProviderLease(string $operationId, array $identity, array $mutationFence): void {
        if ($this->released) {
            throw new \RuntimeException('cloud preview driver cannot bind after its provider lease was released');
        }
        $operationId = self::identifier($operationId, 'cloud preview operation id');
        $identityKeys = [
            'environment_identity', 'lease_generation', 'lease_id',
            'ownership_receipt_sha256', 'resource_id', 'url',
        ];
        $mutationKeys = [
            'mutation_generation', 'mutation_id', 'mutation_owner',
            'mutation_receipt_sha256', 'state',
        ];
        $boundIdentity = self::selectExact($identity, $identityKeys, 'cloud preview provider identity');
        $boundMutation = self::selectExact($mutationFence, $mutationKeys, 'cloud preview mutation fence');
        foreach ($identityKeys as $key) {
            if (($mutationFence[$key] ?? null) !== $boundIdentity[$key]) {
                throw new \RuntimeException("cloud preview mutation fence does not match provider identity '$key'");
            }
        }
        foreach (['environment_identity', 'lease_id', 'resource_id'] as $key) {
            self::identifier($boundIdentity[$key], "cloud preview $key");
        }
        if (!is_int($boundIdentity['lease_generation']) || $boundIdentity['lease_generation'] < 1) {
            throw new \RuntimeException('cloud preview lease_generation must be a positive integer');
        }
        self::sha256($boundIdentity['ownership_receipt_sha256'], 'cloud preview ownership receipt');
        self::publicUrl($boundIdentity['url']);
        foreach (['mutation_id', 'mutation_owner'] as $key) {
            self::identifier($boundMutation[$key], "cloud preview $key");
        }
        if (!is_int($boundMutation['mutation_generation']) || $boundMutation['mutation_generation'] < 1) {
            throw new \RuntimeException('cloud preview mutation_generation must be a positive integer');
        }
        self::sha256($boundMutation['mutation_receipt_sha256'], 'cloud preview mutation receipt');
        if ($boundMutation['state'] !== 'held') {
            throw new \RuntimeException('cloud preview commands require a held mutation fence');
        }
        $binding = [
            'identity' => $boundIdentity,
            'mutation_fence' => $boundMutation,
            'operation_id' => $operationId,
        ];
        if ($this->binding !== null
            && self::encode($this->binding) !== self::encode($binding)) {
            throw new \RuntimeException('cloud preview driver cannot be rebound to a different provider lease');
        }
        $this->binding = $binding;
    }

    public function beginProviderCommandPhase(string $phase): void {
        if ($this->binding === null || $this->released) {
            throw new \RuntimeException('cloud preview command phase requires a live bound provider lease');
        }
        if (preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $phase) !== 1) {
            throw new \RuntimeException('cloud preview command phase is invalid');
        }
        if (isset($this->begunPhases[$phase])) {
            throw new \RuntimeException("cloud preview command phase '$phase' already began on this driver instance");
        }
        $this->begunPhases[$phase] = true;
        $this->commandPhase = $phase;
        $this->commandIndex = 0;
    }

    /** @param array<string,mixed> $releasedFence */
    public function releaseProviderLease(string $operationId, array $releasedFence): void {
        if ($this->binding === null || $this->released) {
            throw new \RuntimeException('cloud preview driver has no live provider lease to release');
        }
        if ($operationId !== $this->binding['operation_id']) {
            throw new \RuntimeException('cloud preview release operation does not match its bound provider lease');
        }
        $identity = $this->binding['identity'];
        $held = $this->binding['mutation_fence'];
        if (!is_array($identity) || !is_array($held)) {
            throw new \RuntimeException('cloud preview driver binding is malformed');
        }
        foreach (['environment_identity', 'lease_generation', 'lease_id', 'ownership_receipt_sha256', 'resource_id', 'url'] as $key) {
            if (($releasedFence[$key] ?? null) !== ($identity[$key] ?? null)) {
                throw new \RuntimeException("cloud preview released fence changed provider identity '$key'");
            }
        }
        foreach (['mutation_generation', 'mutation_id', 'mutation_owner'] as $key) {
            if (($releasedFence[$key] ?? null) !== ($held[$key] ?? null)) {
                throw new \RuntimeException("cloud preview released fence changed mutation identity '$key'");
            }
        }
        self::sha256($releasedFence['mutation_receipt_sha256'] ?? null, 'cloud preview released mutation receipt');
        if (($releasedFence['state'] ?? null) !== 'released') {
            throw new \RuntimeException('cloud preview provider lease can be revoked only by a released fence');
        }
        $this->binding = null;
        $this->commandPhase = null;
        $this->released = true;
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    public function captureRaw(string $script): array {
        if ($script === '' || str_contains($script, "\0")) {
            throw new \RuntimeException('cloud preview raw command must be non-empty and contain no NUL byte');
        }
        return $this->execute('raw', ['script' => $script]);
    }

    /** @return array{exit:int,stdout:string,stderr:string} */
    public function captureWp(array $wpArgs): array {
        if ($wpArgs === []) {
            throw new \RuntimeException('cloud preview WP command requires at least one argument');
        }
        foreach ($wpArgs as $index => $arg) {
            if (!is_string($arg) || $arg === '' || str_contains($arg, "\0")) {
                throw new \RuntimeException("cloud preview WP argument $index is invalid");
            }
        }
        return $this->execute('wp', ['argv' => array_values($wpArgs)]);
    }

    public function streamWp(array $wpArgs): int {
        $result = $this->captureWp($wpArgs);
        fwrite(STDOUT, $result['stdout']);
        fwrite(STDERR, $result['stderr']);
        return $result['exit'];
    }

    public function wpInstruction(array $wpArgs): string {
        return "Duo Cloud preview {$this->siteId}/{$this->name}: wp " . self::tokens($wpArgs);
    }

    protected function wpCommand(array $wpArgs): string {
        throw new \LogicException('cloud-preview commands use the signed control protocol');
    }

    protected function rawCommand(string $script): string {
        throw new \LogicException('cloud-preview commands use the signed control protocol');
    }

    /** @param array<string,mixed> $input @return array{exit:int,stdout:string,stderr:string} */
    private function execute(string $action, array $input): array {
        if ($this->binding === null || $this->released) {
            throw new \RuntimeException('cloud preview driver is not bound to a live provider lease and held mutation fence');
        }
        if ($this->commandPhase === null) {
            throw new \RuntimeException('cloud preview driver has no journal-owned command phase');
        }
        $commandIndex = $this->commandIndex++;
        $requestId = hash(
            'sha256',
            "duo-cloud-preview-command/v1\0{$this->tenantId}\0{$this->siteId}\0"
                . $this->binding['operation_id'] . "\0{$this->commandPhase}\0$commandIndex"
        );
        $identity = $this->binding['identity'];
        $mutation = $this->binding['mutation_fence'];
        if (!is_array($identity) || !is_array($mutation)) {
            throw new \RuntimeException('cloud preview driver binding is malformed');
        }
        $target = [
            'environment_identity' => $identity['environment_identity'],
            'lease_generation' => $identity['lease_generation'],
            'lease_id' => $identity['lease_id'],
            'mutation_generation' => $mutation['mutation_generation'],
            'mutation_id' => $mutation['mutation_id'],
            'mutation_owner' => $mutation['mutation_owner'],
            'mutation_receipt_sha256' => $mutation['mutation_receipt_sha256'],
            'ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
            'resource_id' => $identity['resource_id'],
        ];
        $payload = [
            'action' => $action,
            'command_index' => $commandIndex,
            'command_phase' => $this->commandPhase,
            'environment' => $this->name,
            'format' => self::REQUEST_FORMAT,
            'input' => $input,
            'operation_id' => $this->binding['operation_id'],
            'request_id' => $requestId,
            'site_id' => $this->siteId,
            'target' => $target,
            'tenant_id' => $this->tenantId,
        ];
        $payloadBytes = self::encode($payload);
        $request = [
            'format' => self::ENVELOPE_FORMAT,
            'key_id' => $this->requestKeyId,
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached($payloadBytes, $this->requestSecretKey)),
        ];
        $response = $this->post($this->endpoint, self::encode($request) . "\n", 'control');
        self::assertExactKeys(
            $response,
            ['format', 'key_id', 'payload', 'signature'],
            'cloud preview signed response'
        );
        if ($response['format'] !== self::ENVELOPE_FORMAT
            || $response['key_id'] !== $this->responseKeyId
            || !is_array($response['payload'] ?? null)
            || array_is_list($response['payload'])) {
            throw new \RuntimeException('cloud preview control response is not signed by the paired service key');
        }
        $responsePayload = $response['payload'];
        $signature = is_string($response['signature'] ?? null)
            ? base64_decode($response['signature'], true)
            : false;
        if (!is_string($signature)
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || base64_encode($signature) !== ($response['signature'] ?? null)
            || !sodium_crypto_sign_verify_detached(
                $signature,
                self::encode($responsePayload),
                $this->responsePublicKey
            )) {
            throw new \RuntimeException('cloud preview control response signature is invalid');
        }
        self::assertExactKeys(
            $responsePayload,
            [
                'action', 'environment', 'format', 'operation_id', 'request_sha256',
                'result', 'site_id', 'target', 'tenant_id',
            ],
            'cloud preview control response payload'
        );
        if ($responsePayload['format'] !== self::RESPONSE_FORMAT
            || $responsePayload['action'] !== $action
            || $responsePayload['environment'] !== $this->name
            || $responsePayload['operation_id'] !== $this->binding['operation_id']
            || $responsePayload['request_sha256'] !== hash('sha256', $payloadBytes)
            || $responsePayload['site_id'] !== $this->siteId
            || $responsePayload['tenant_id'] !== $this->tenantId
            || self::encode($responsePayload['target']) !== self::encode($target)) {
            throw new \RuntimeException('cloud preview control response is not bound to its signed request');
        }
        $result = $responsePayload['result'] ?? null;
        if (!is_array($result) || array_is_list($result)) {
            throw new \RuntimeException('cloud preview control result is malformed');
        }
        self::assertExactKeys($result, ['exit', 'stderr', 'stdout'], 'cloud preview control result');
        if (!is_int($result['exit']) || $result['exit'] < 0 || $result['exit'] > 255
            || !is_string($result['stdout']) || !is_string($result['stderr'])) {
            throw new \RuntimeException('cloud preview control result has invalid command output');
        }
        return $result;
    }

    /** @param array<string,mixed> $input @return array{provider:array{id:string,protocol:int},response_sha256:string,result:array<string,mixed>} */
    private function lifecycleCall(string $action, string $operationId, array $input): array {
        self::identifier($operationId, 'cloud preview lifecycle operation id');
        if (!is_array($input) || (array_is_list($input) && $input !== [])) {
            throw new \RuntimeException('cloud preview lifecycle input must be an object');
        }
        $requestId = hash(
            'sha256',
            "duo-cloud-preview-lifecycle/v1\0{$this->tenantId}\0{$this->siteId}\0{$this->name}\0"
                . "$operationId\0$action\0" . hash('sha256', self::encode($input))
        );
        $payload = [
            'action' => $action,
            'environment' => $this->name,
            'format' => self::LIFECYCLE_REQUEST_FORMAT,
            'input' => $input,
            'operation_id' => $operationId,
            'request_id' => $requestId,
            'site_id' => $this->siteId,
            'tenant_id' => $this->tenantId,
        ];
        $payloadBytes = self::encode($payload);
        $request = [
            'format' => self::ENVELOPE_FORMAT,
            'key_id' => $this->requestKeyId,
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached($payloadBytes, $this->requestSecretKey)),
        ];
        $response = $this->post(
            $this->lifecycleEndpoint,
            self::encode($request) . "\n",
            'lifecycle'
        );
        self::assertExactKeys(
            $response,
            ['format', 'key_id', 'payload', 'signature'],
            'cloud preview lifecycle signed response'
        );
        if (($response['format'] ?? null) !== self::ENVELOPE_FORMAT
            || ($response['key_id'] ?? null) !== $this->responseKeyId
            || !is_array($response['payload'] ?? null)
            || array_is_list($response['payload'])) {
            throw new \RuntimeException('cloud preview lifecycle response is not signed by the paired service key');
        }
        $responsePayload = $response['payload'];
        $signature = is_string($response['signature'] ?? null)
            ? base64_decode($response['signature'], true)
            : false;
        if (!is_string($signature)
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES
            || base64_encode($signature) !== ($response['signature'] ?? null)
            || !sodium_crypto_sign_verify_detached(
                $signature,
                self::encode($responsePayload),
                $this->responsePublicKey
            )) {
            throw new \RuntimeException('cloud preview lifecycle response signature is invalid');
        }
        self::assertExactKeys(
            $responsePayload,
            [
                'action', 'environment', 'format', 'operation_id', 'provider',
                'request_sha256', 'result', 'site_id', 'status', 'tenant_id',
            ],
            'cloud preview lifecycle response payload'
        );
        $provider = $responsePayload['provider'] ?? null;
        if ($responsePayload['format'] !== self::LIFECYCLE_RESPONSE_FORMAT
            || $responsePayload['action'] !== $action
            || $responsePayload['environment'] !== $this->name
            || $responsePayload['operation_id'] !== $operationId
            || $responsePayload['request_sha256'] !== hash('sha256', $payloadBytes)
            || $responsePayload['site_id'] !== $this->siteId
            || $responsePayload['status'] !== 'ok'
            || $responsePayload['tenant_id'] !== $this->tenantId
            || !is_array($provider) || array_is_list($provider)
            || !is_string($provider['id'] ?? null)
            || preg_match('/^[A-Za-z0-9._:@+-]{1,128}$/D', $provider['id']) !== 1
            || !is_int($provider['protocol']) || $provider['protocol'] < 1) {
            throw new \RuntimeException('cloud preview lifecycle response is not bound to its signed request');
        }
        self::assertExactKeys($provider, ['id', 'protocol'], 'cloud preview lifecycle provider');
        $result = $responsePayload['result'] ?? null;
        if (!is_array($result) || (array_is_list($result) && $result !== [])) {
            throw new \RuntimeException('cloud preview lifecycle result is malformed');
        }
        return [
            'provider' => $provider,
            'response_sha256' => hash('sha256', self::encode($response)),
            'result' => $result,
        ];
    }

    /** @return array<string,mixed> */
    private function post(string $endpoint, string $body, string $boundary): array {
        $deadline = microtime(true) + $this->timeoutSeconds;
        $context = stream_context_create([
            'http' => [
                'content' => $body,
                'follow_location' => 0,
                'header' => "Content-Type: application/json\r\nAccept: application/json\r\nConnection: close\r\n",
                'ignore_errors' => true,
                'method' => 'POST',
                'timeout' => $this->timeoutSeconds,
            ],
            'ssl' => [
                'allow_self_signed' => false,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $stream = @fopen($endpoint, 'rb', false, $context);
        if (!is_resource($stream)) {
            throw new \RuntimeException("cloud preview $boundary request failed; remote output is redacted");
        }
        $metadata = stream_get_meta_data($stream);
        $headers = is_array($metadata['wrapper_data'] ?? null) ? $metadata['wrapper_data'] : [];
        $status = null;
        $contentType = null;
        foreach ($headers as $header) {
            if (is_string($header) && preg_match('#^HTTP/\S+\s+([0-9]{3})(?:\s|$)#D', $header, $match) === 1) {
                $status = (int) $match[1];
            }
            if (is_string($header) && stripos($header, 'Content-Type:') === 0) {
                $contentType = strtolower(trim(substr($header, strlen('Content-Type:'))));
            }
        }
        $bytes = '';
        while (!feof($stream)) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                fclose($stream);
                throw new \RuntimeException("cloud preview $boundary response timed out");
            }
            $seconds = (int) floor($remaining);
            $microseconds = max(1, (int) floor(($remaining - $seconds) * 1000000));
            if (!stream_set_timeout($stream, $seconds, $microseconds)) {
                fclose($stream);
                throw new \RuntimeException("cloud preview $boundary response deadline could not be enforced");
            }
            $chunk = fread($stream, 65536);
            if (!is_string($chunk)) {
                fclose($stream);
                throw new \RuntimeException("cloud preview $boundary response could not be read");
            }
            if ($chunk === '' && !feof($stream)) {
                $readMetadata = stream_get_meta_data($stream);
                fclose($stream);
                throw new \RuntimeException(
                    !empty($readMetadata['timed_out'])
                        ? "cloud preview $boundary response timed out"
                        : "cloud preview $boundary response stalled before EOF"
                );
            }
            $bytes .= $chunk;
            if (strlen($bytes) > self::RESPONSE_LIMIT) {
                fclose($stream);
                throw new \RuntimeException("cloud preview $boundary response exceeded the evidence limit");
            }
        }
        fclose($stream);
        if ($status !== 200 || $contentType !== 'application/json') {
            throw new \RuntimeException("cloud preview $boundary request was refused; remote output is redacted");
        }
        try {
            $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            throw new \RuntimeException("cloud preview $boundary returned malformed JSON");
        }
        if (!is_array($decoded) || array_is_list($decoded)
            || self::encode($decoded) . "\n" !== $bytes) {
            throw new \RuntimeException("cloud preview $boundary returned noncanonical evidence");
        }
        return $decoded;
    }

    /**
     * @return array{host:string,port:int,scheme:string}
     */
    private static function serviceAuthority(string $endpoint, string $path, string $label): array {
        $parts = parse_url($endpoint);
        if (!is_array($parts) || ($parts['path'] ?? '') !== $path
            || !is_string($parts['scheme'] ?? null) || !is_string($parts['host'] ?? null)) {
            throw new \RuntimeException("cloud preview $label endpoint must use exact path '$path'");
        }
        $scheme = strtolower($parts['scheme']);
        $host = strtolower($parts['host']);
        $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
        if (!is_int($port) || $port < 1 || $port > 65535) {
            throw new \RuntimeException("cloud preview $label endpoint has an invalid service port");
        }
        return ['host' => $host, 'port' => $port, 'scheme' => $scheme];
    }

    /** @param array<string,mixed> $descriptor */
    private static function assertReviewedBaseContainment(array $descriptor): void {
        self::assertExactKeys($descriptor, [
            'descriptor_sha256', 'egress_evidence', 'format', 'image_reference',
            'reviewed_base', 'routing_evidence', 'runtime_configuration_sha256',
            'seccomp_profile_sha256', 'secrets_evidence', 'storage_evidence',
        ], 'cloud preview reviewed-base containment');
        if (($descriptor['format'] ?? null) !== 'duo-reviewed-preview-base-containment/v1'
            || ($descriptor['egress_evidence'] ?? null) !== 'host-nft-input-forward-default-deny-readback/v1'
            || ($descriptor['routing_evidence'] ?? null) !== 'credential-free-route-authority-readback/v1'
            || ($descriptor['secrets_evidence'] ?? null)
                !== 'generation-private-files-readonly-mount-readback/v1'
            || ($descriptor['storage_evidence'] ?? null)
                !== 'dm-crypt-xfs-project-quota-exact-readback/v1'
            || !ImmutableOciReference::valid($descriptor['image_reference'] ?? null)) {
            throw new \RuntimeException('cloud preview reviewed-base containment evidence is malformed');
        }
        self::sha256(
            $descriptor['descriptor_sha256'] ?? null,
            'cloud preview reviewed-base containment descriptor'
        );
        self::sha256(
            $descriptor['runtime_configuration_sha256'] ?? null,
            'cloud preview reviewed-base runtime configuration'
        );
        self::sha256(
            $descriptor['seccomp_profile_sha256'] ?? null,
            'cloud preview reviewed-base seccomp profile'
        );
        $base = $descriptor['reviewed_base'] ?? null;
        if (!is_array($base) || array_is_list($base)) {
            throw new \RuntimeException('cloud preview reviewed-base containment has no reviewed base');
        }
        self::assertExactKeys($base, [
            'format', 'image_digest', 'platform_fingerprint_sha256', 'review_receipt_sha256',
        ], 'cloud preview reviewed base');
        $reference = (string) $descriptor['image_reference'];
        $at = strrpos($reference, '@');
        if (($base['format'] ?? null) !== 'duo-reviewed-preview-base/v1'
            || !is_string($base['image_digest'] ?? null)
            || preg_match('/^sha256:[a-f0-9]{64}$/D', $base['image_digest']) !== 1
            || !is_int($at)
            || substr($reference, $at + 1) !== $base['image_digest']) {
            throw new \RuntimeException('cloud preview reviewed-base containment image does not match');
        }
        self::sha256(
            $base['platform_fingerprint_sha256'] ?? null,
            'cloud preview reviewed-base platform fingerprint'
        );
        self::sha256(
            $base['review_receipt_sha256'] ?? null,
            'cloud preview reviewed-base review receipt'
        );
        $basis = $descriptor;
        $claimed = (string) $basis['descriptor_sha256'];
        unset($basis['descriptor_sha256']);
        $expected = hash(
            'sha256',
            "duo-reviewed-preview-base-containment/v1\0" . self::encode($basis)
        );
        if (!hash_equals($expected, $claimed)) {
            throw new \RuntimeException('cloud preview reviewed-base containment hash does not verify');
        }
    }

    /** @param array<string,mixed> $descriptor */
    private static function assertRepositoryAuthority(array $descriptor): void {
        self::assertExactKeys(
            $descriptor,
            [
                'credential_helper_sha256', 'descriptor_sha256', 'format',
                'ref_prefix', 'remote_url_sha256',
            ],
            'cloud preview repository authority'
        );
        if (($descriptor['format'] ?? null) !== 'duo-cloud-repository-authority/v1'
            || !is_string($descriptor['ref_prefix'] ?? null)
            || strlen($descriptor['ref_prefix']) > 384
            || preg_match(
                '#^refs/heads/[A-Za-z0-9][A-Za-z0-9._/-]*/$#D',
                $descriptor['ref_prefix']
            ) !== 1
            || str_contains($descriptor['ref_prefix'], '..')
            || str_contains($descriptor['ref_prefix'], '//')
            || str_contains($descriptor['ref_prefix'], '@{')
            || str_contains($descriptor['ref_prefix'], '.lock/')) {
            throw new \RuntimeException('cloud preview repository authority is malformed');
        }
        self::sha256($descriptor['descriptor_sha256'] ?? null, 'cloud preview repository authority');
        self::sha256(
            $descriptor['credential_helper_sha256'] ?? null,
            'cloud preview repository credential helper'
        );
        self::sha256($descriptor['remote_url_sha256'] ?? null, 'cloud preview repository remote URL');
        $basis = $descriptor;
        $claimed = (string) $basis['descriptor_sha256'];
        unset($basis['descriptor_sha256']);
        $expected = hash(
            'sha256',
            "duo-cloud-repository-authority/v1\0" . self::encode($basis)
        );
        if (!hash_equals($expected, $claimed)) {
            throw new \RuntimeException('cloud preview repository authority hash does not verify');
        }
    }

    /** @param array<string,mixed> $cfg @param array<string,mixed> $cloud */
    private static function keyPath(string $environment, array $cfg, array $cloud, string $field): string {
        $path = $cloud[$field] ?? null;
        if (!is_string($path) || $path === '' || str_contains($path, "\0")) {
            throw new \RuntimeException("env '$environment': cloud_preview.$field must be a non-empty path");
        }
        $base = (string) ($cfg['_dir'] ?? '.');
        $joined = $path[0] === '/' ? $path : rtrim($base, '/') . '/' . $path;
        $parent = realpath(dirname($joined));
        if (!is_string($parent) || $parent === '') {
            throw new \RuntimeException("env '$environment': cloud preview $field parent directory is unavailable");
        }
        // Canonicalize only the parent. Resolving the whole path would follow
        // a final symlink before key() can prove that the trust anchor itself
        // is an ordinary file.
        return rtrim($parent, '/') . '/' . basename($joined);
    }

    private static function key(
        string $environment,
        string $path,
        int $length,
        string $label,
        bool $private
    ): string {
        $pathStat = @lstat($path);
        if (!is_array($pathStat) || ($pathStat['mode'] & 0170000) !== 0100000 || is_link($path)) {
            throw new \RuntimeException("env '$environment': cloud preview $label must be a regular non-symlink file");
        }
        if ($private && DIRECTORY_SEPARATOR === '/' && ($pathStat['mode'] & 0777) !== 0600) {
            throw new \RuntimeException("env '$environment': cloud preview $label must have mode 0600");
        }
        if (!$private && DIRECTORY_SEPARATOR === '/' && ($pathStat['mode'] & 0022) !== 0) {
            throw new \RuntimeException("env '$environment': cloud preview $label must not be group/world writable");
        }
        if (function_exists('posix_geteuid') && (int) $pathStat['uid'] !== posix_geteuid()) {
            throw new \RuntimeException("env '$environment': cloud preview $label must be owned by the controller user");
        }
        $parentStat = @lstat(dirname($path));
        if (!is_array($parentStat) || ($parentStat['mode'] & 0170000) !== 0040000
            || (DIRECTORY_SEPARATOR === '/' && ($parentStat['mode'] & 0022) !== 0)
            || (function_exists('posix_geteuid') && !in_array((int) $parentStat['uid'], [0, posix_geteuid()], true))) {
            throw new \RuntimeException("env '$environment': cloud preview $label parent directory is not controller-owned and protected");
        }
        if ((int) $pathStat['size'] < 2 || (int) $pathStat['size'] > 1024) {
            throw new \RuntimeException("env '$environment': cloud preview $label has invalid size");
        }
        $handle = @fopen($path, 'rb');
        if (!is_resource($handle)) {
            throw new \RuntimeException("env '$environment': cloud preview $label could not be read");
        }
        $openedStat = fstat($handle);
        if (!is_array($openedStat)
            || ($openedStat['mode'] & 0170000) !== 0100000
            || (int) $openedStat['dev'] !== (int) $pathStat['dev']
            || (int) $openedStat['ino'] !== (int) $pathStat['ino']
            || (int) $openedStat['size'] !== (int) $pathStat['size']
            || (int) $openedStat['mode'] !== (int) $pathStat['mode']
            || (int) $openedStat['uid'] !== (int) $pathStat['uid']) {
            fclose($handle);
            throw new \RuntimeException("env '$environment': cloud preview $label changed while opening");
        }
        $raw = stream_get_contents($handle, 1025);
        $closed = fclose($handle);
        $finalStat = @lstat($path);
        if (!is_string($raw) || !$closed || !is_array($finalStat)
            || (int) $finalStat['dev'] !== (int) $pathStat['dev']
            || (int) $finalStat['ino'] !== (int) $pathStat['ino']
            || (int) $finalStat['size'] !== (int) $pathStat['size']
            || (int) $finalStat['mode'] !== (int) $pathStat['mode']
            || (int) $finalStat['uid'] !== (int) $pathStat['uid']) {
            throw new \RuntimeException("env '$environment': cloud preview $label changed while reading");
        }
        $encoded = trim($raw);
        $key = base64_decode($encoded, true);
        if (!is_string($key) || base64_encode($key) !== $encoded || strlen($key) !== $length) {
            throw new \RuntimeException("env '$environment': cloud preview $label must be canonical base64 Ed25519");
        }
        return $key;
    }

    /** @param mixed $value */
    private static function endpoint(string $environment, $value, string $field): string {
        $parts = is_string($value) ? parse_url($value) : false;
        if (!is_string($value) || $value === '' || strlen($value) > 2048
            || str_contains($value, "\r") || str_contains($value, "\n")
            || filter_var($value, FILTER_VALIDATE_URL) === false
            || !is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException("env '$environment': cloud_preview.$field must be a credential-free HTTPS URL");
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $testLoopback = getenv('DUO_TEST_MODE') === '1' && self::loopbackHost($host);
        if ($scheme !== 'https' && !($scheme === 'http' && $testLoopback)) {
            throw new \RuntimeException("env '$environment': cloud_preview.$field must use HTTPS");
        }
        return $value;
    }

    private static function loopbackHost(string $host): bool {
        if ($host === '::1') {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return false;
        }
        return str_starts_with($host, '127.');
    }

    /** @param mixed $value */
    private static function identifier($value, string $label): string {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) !== 1) {
            throw new \RuntimeException("$label is invalid");
        }
        return $value;
    }

    /** @param mixed $value */
    private static function sha256($value, string $label): string {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \RuntimeException("$label must be lowercase SHA-256");
        }
        return $value;
    }

    /** @param mixed $value */
    private static function publicUrl($value): string {
        $parts = is_string($value) ? parse_url($value) : false;
        if (!is_string($value) || !is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || strtolower((string) $parts['scheme']) !== 'https'
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new \RuntimeException('cloud preview provider URL is not a credential-free HTTPS base URL');
        }
        return $value;
    }

    /** @param array<string,mixed> $value @param list<string> $keys @return array<string,mixed> */
    private static function selectExact(array $value, array $keys, string $label): array {
        $selected = [];
        foreach ($keys as $key) {
            if (!array_key_exists($key, $value)) {
                throw new \RuntimeException("$label is missing '$key'");
            }
            $selected[$key] = $value[$key];
        }
        return $selected;
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function assertExactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("$label has unknown or missing fields");
        }
    }

    /** @param mixed $value */
    private static function canonicalize($value) {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalize($child);
        }
        return $value;
    }

    /** @param mixed $value */
    private static function encode($value): string {
        try {
            return json_encode(
                self::canonicalize($value),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $e) {
            throw new \RuntimeException('cloud preview control value is not canonical JSON', 0, $e);
        }
    }
}
