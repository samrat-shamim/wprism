<?php
declare(strict_types=1);

namespace Duo\Orchestrator;

require_once __DIR__ . '/CloudCommittedOriginExport.php';

/**
 * Signed controller client for one closed cloud-origin export endpoint.
 *
 * Public methods are the entire action vocabulary. No caller can supply a
 * route, callback, arbitrary action, command, or chunk identity outside the
 * committed manifest. A result becomes semantic production truth only after
 * all signed responses and exact agent-canonical artifact bytes verify.
 */
final class CloudOriginExportClient {
    private const PATH = '/v1/origin/controller/export';
    private const ENVELOPE_FORMAT = 'duo-cloud-origin-controller-signed-envelope/v1';
    private const REQUEST_FORMAT = 'duo-cloud-origin-controller-request/v1';
    private const RESPONSE_FORMAT = 'duo-cloud-origin-controller-response/v1';
    private const RESPONSE_LIMIT = 2097152;

    private string $endpoint;
    private string $requestSecretKey;
    /** @var ?\Closure(string,string):array<string,mixed> */
    private ?\Closure $testExchange;

    /**
     * @param ?callable(string,string):array<string,mixed> $testExchange
     */
    public function __construct(
        string $endpoint,
        private string $tenantId,
        private string $siteId,
        private string $requestKeyId,
        string $requestSecretKey,
        private string $responseKeyId,
        private string $responsePublicKey,
        private int $timeoutSeconds = 30,
        ?callable $testExchange = null
    ) {
        $this->endpoint = self::endpoint($endpoint);
        self::identifier($tenantId, 'cloud origin tenant id');
        self::identifier($siteId, 'cloud origin site id');
        self::identifier($requestKeyId, 'cloud origin request key id');
        self::identifier($responseKeyId, 'cloud origin response key id');
        if (strlen($requestSecretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('cloud origin request signing key is not Ed25519');
        }
        if (strlen($responsePublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \RuntimeException('cloud origin response verification key is not Ed25519');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > 300) {
            throw new \RuntimeException('cloud origin request timeout must be between one and 300 seconds');
        }
        $this->requestSecretKey = $requestSecretKey;
        $this->testExchange = $testExchange === null ? null : \Closure::fromCallable($testExchange);
    }

    public function __destruct() {
        if ($this->requestSecretKey !== '') {
            sodium_memzero($this->requestSecretKey);
        }
    }

    /** @return array<string,mixed> */
    public function requestPortableExport(string $expectedCommit, string $operationId): array {
        self::commit($expectedCommit, 'cloud origin expected production commit');
        self::identifier($operationId, 'cloud origin operation id');
        $result = $this->transact('demand-create', $operationId, [
            'expected_production_commit' => $expectedCommit,
        ]);
        self::exactKeys($result, ['demand'], 'cloud origin demand-create result');
        if (!is_array($result['demand']) || array_is_list($result['demand'])) {
            throw new \RuntimeException('cloud origin demand-create result is malformed');
        }
        self::demand($result['demand'], $expectedCommit);
        return $result['demand'];
    }

    /**
     * @param array<string,mixed> $demand
     * @return array<string,mixed>
     */
    public function pollPortableExport(array $demand, string $operationId, int $pollSequence): array {
        self::demand($demand);
        self::identifier($operationId, 'cloud origin operation id');
        if ($pollSequence < 0) {
            throw new \RuntimeException('cloud origin poll sequence must be non-negative');
        }
        $result = $this->transact('demand-status', $operationId, [
            'demand_generation' => $demand['demand_generation'],
            'demand_id' => $demand['demand_id'],
            'expected_production_commit' => $demand['expected_production_commit'],
            'poll_sequence' => $pollSequence,
        ]);
        self::exactKeys($result, ['poll_sequence', 'status'], 'cloud origin demand-status result');
        if ($result['poll_sequence'] !== $pollSequence
            || !is_array($result['status']) || array_is_list($result['status'])) {
            throw new \RuntimeException('cloud origin demand-status result is malformed');
        }
        $status = $result['status'];
        self::exactKeys($status, [
            'demand_generation', 'demand_id', 'expected_production_commit',
            'export_id', 'manifest_sha256', 'state',
        ], 'cloud origin demand status');
        if ($status['demand_generation'] !== $demand['demand_generation']
            || $status['demand_id'] !== $demand['demand_id']
            || $status['expected_production_commit'] !== $demand['expected_production_commit']
            || !in_array($status['state'] ?? null, [
                'committed', 'demanded', 'expired', 'revoked', 'uploading',
            ], true)) {
            throw new \RuntimeException('cloud origin status is not bound to its exact demand');
        }
        if ($status['export_id'] !== null) {
            self::sha256($status['export_id'], 'cloud origin status export id');
            self::sha256($status['manifest_sha256'], 'cloud origin status manifest hash');
        } elseif ($status['manifest_sha256'] !== null || in_array($status['state'], ['committed', 'uploading'], true)) {
            throw new \RuntimeException('cloud origin status has an invalid export identity');
        }
        if ($status['state'] === 'committed' && $status['export_id'] === null) {
            throw new \RuntimeException('cloud origin committed status has no export identity');
        }
        return $status;
    }

    /**
     * Fetch and reconstruct one exact committed export. The status argument is
     * deliberately required so an old or foreign export id cannot be supplied
     * independently of the polled demand lifecycle.
     *
     * @param array<string,mixed> $demand
     * @param array<string,mixed> $status
     */
    public function readCommittedExport(
        array $demand,
        array $status,
        string $operationId
    ): CloudCommittedOriginExport {
        self::demand($demand);
        self::identifier($operationId, 'cloud origin operation id');
        if (($status['state'] ?? null) !== 'committed'
            || ($status['demand_generation'] ?? null) !== $demand['demand_generation']
            || ($status['demand_id'] ?? null) !== $demand['demand_id']
            || ($status['expected_production_commit'] ?? null) !== $demand['expected_production_commit']) {
            throw new \RuntimeException('cloud origin export read requires the exact committed demand status');
        }
        $exportId = self::sha256($status['export_id'] ?? null, 'cloud origin committed export id');
        $manifestHash = self::sha256(
            $status['manifest_sha256'] ?? null,
            'cloud origin committed manifest hash'
        );
        $identity = [
            'demand_generation' => $demand['demand_generation'],
            'demand_id' => $demand['demand_id'],
            'expected_production_commit' => $demand['expected_production_commit'],
            'export_id' => $exportId,
        ];
        $manifestResult = $this->transact('manifest-read', $operationId, $identity);
        self::exactKeys($manifestResult, ['export_id', 'manifest'], 'cloud origin manifest-read result');
        if ($manifestResult['export_id'] !== $exportId
            || !is_array($manifestResult['manifest']) || array_is_list($manifestResult['manifest'])
            || ($manifestResult['manifest']['manifest_sha256'] ?? null) !== $manifestHash) {
            throw new \RuntimeException('cloud origin manifest response changed the committed export identity');
        }
        $manifest = $manifestResult['manifest'];
        $bytes = '';
        foreach ($manifest['chunks'] ?? [] as $index => $descriptor) {
            if (!is_array($descriptor) || array_is_list($descriptor) || ($descriptor['index'] ?? null) !== $index) {
                throw new \RuntimeException('cloud origin manifest chunk sequence is malformed');
            }
            $chunkResult = $this->transact('chunk-read', $operationId, $identity + [
                'chunk_index' => $index,
                'manifest_sha256' => $manifestHash,
            ]);
            self::exactKeys($chunkResult, [
                'chunk_base64', 'chunk_index', 'chunk_sha256', 'chunk_size',
                'export_id', 'manifest_sha256',
            ], 'cloud origin chunk-read result');
            $chunk = self::base64($chunkResult['chunk_base64'] ?? null, 'cloud origin chunk bytes');
            if ($chunkResult['chunk_index'] !== $index
                || $chunkResult['chunk_sha256'] !== ($descriptor['sha256'] ?? null)
                || $chunkResult['chunk_size'] !== ($descriptor['size'] ?? null)
                || $chunkResult['chunk_size'] !== strlen($chunk)
                || $chunkResult['export_id'] !== $exportId
                || $chunkResult['manifest_sha256'] !== $manifestHash
                || !hash_equals((string) $chunkResult['chunk_sha256'], hash('sha256', $chunk))) {
                throw new \RuntimeException('cloud origin chunk response does not match its manifest descriptor');
            }
            $bytes .= $chunk;
            if (strlen($bytes) > 67108864) {
                throw new \RuntimeException('cloud origin reconstructed export exceeds 64 MiB');
            }
        }
        return CloudCommittedOriginExport::fromWire(
            'duo-cloud-origin:' . hash(
                'sha256',
                "duo-cloud-origin-environment/v1\0{$this->tenantId}\0{$this->siteId}"
            ),
            $manifest,
            $bytes
        );
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function transact(string $action, string $operationId, array $input): array {
        $requestId = self::requestId(
            $this->requestKeyId,
            $this->tenantId,
            $this->siteId,
            $operationId,
            $action,
            $input
        );
        $payload = [
            'action' => $action,
            'format' => self::REQUEST_FORMAT,
            'input' => $input,
            'operation_id' => $operationId,
            'request_id' => $requestId,
            'site_id' => $this->siteId,
            'tenant_id' => $this->tenantId,
        ];
        $payloadBytes = self::encode($payload);
        $request = self::encode([
            'format' => self::ENVELOPE_FORMAT,
            'key_id' => $this->requestKeyId,
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                $payloadBytes,
                $this->requestSecretKey
            )),
        ]) . "\n";
        $responseBytes = $this->post($request);
        $response = self::decodeCanonicalObject($responseBytes, self::RESPONSE_LIMIT, 'cloud origin response');
        self::exactKeys($response, ['format', 'key_id', 'payload', 'signature'], 'cloud origin response envelope');
        if (($response['format'] ?? null) !== self::ENVELOPE_FORMAT
            || ($response['key_id'] ?? null) !== $this->responseKeyId
            || !is_array($response['payload'] ?? null) || array_is_list($response['payload'])) {
            throw new \RuntimeException('cloud origin response envelope is malformed');
        }
        $responsePayload = $response['payload'];
        $responseSignature = self::base64($response['signature'] ?? null, 'cloud origin response signature');
        if (strlen($responseSignature) !== SODIUM_CRYPTO_SIGN_BYTES
            || !sodium_crypto_sign_verify_detached(
                $responseSignature,
                self::encode($responsePayload),
                $this->responsePublicKey
            )) {
            throw new \RuntimeException('cloud origin response signature is invalid');
        }
        self::exactKeys($responsePayload, [
            'action', 'format', 'operation_id', 'request_sha256', 'result',
            'site_id', 'status', 'tenant_id',
        ], 'cloud origin response payload');
        if ($responsePayload['format'] !== self::RESPONSE_FORMAT
            || $responsePayload['action'] !== $action
            || $responsePayload['operation_id'] !== $operationId
            || $responsePayload['request_sha256'] !== hash('sha256', $payloadBytes)
            || $responsePayload['site_id'] !== $this->siteId
            || $responsePayload['status'] !== 'ok'
            || $responsePayload['tenant_id'] !== $this->tenantId
            || !is_array($responsePayload['result'])
            || (array_is_list($responsePayload['result']) && $responsePayload['result'] !== [])) {
            throw new \RuntimeException('cloud origin response is not bound to its signed request');
        }
        return $responsePayload['result'];
    }

    private function post(string $body): string {
        if ($this->testExchange !== null) {
            $result = ($this->testExchange)($this->endpoint, $body);
            self::exactKeys(
                $result,
                ['body', 'effective_url', 'redirected', 'status'],
                'cloud origin test exchange'
            );
            if (($result['status'] ?? null) !== 200 || ($result['effective_url'] ?? null) !== $this->endpoint
                || ($result['redirected'] ?? null) !== false || !is_string($result['body'] ?? null)) {
                throw new \RuntimeException('cloud origin request was refused; remote output is redacted');
            }
            return $result['body'];
        }

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
        $stream = @fopen($this->endpoint, 'rb', false, $context);
        if (!is_resource($stream)) {
            throw new \RuntimeException('cloud origin request failed; remote output is redacted');
        }
        $metadata = stream_get_meta_data($stream);
        $headers = is_array($metadata['wrapper_data'] ?? null) ? $metadata['wrapper_data'] : [];
        $status = null;
        $contentType = null;
        foreach ($headers as $header) {
            if (is_string($header)
                && preg_match('#^HTTP/\S+\s+([0-9]{3})(?:\s|$)#D', $header, $match) === 1) {
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
                throw new \RuntimeException('cloud origin response timed out');
            }
            $seconds = (int) floor($remaining);
            $microseconds = max(1, (int) floor(($remaining - $seconds) * 1000000));
            if (!stream_set_timeout($stream, $seconds, $microseconds)) {
                fclose($stream);
                throw new \RuntimeException('cloud origin response deadline could not be enforced');
            }
            $chunk = fread($stream, 65536);
            if (!is_string($chunk)) {
                fclose($stream);
                throw new \RuntimeException('cloud origin response could not be read');
            }
            if ($chunk === '' && !feof($stream)) {
                fclose($stream);
                throw new \RuntimeException('cloud origin response stalled before EOF');
            }
            $bytes .= $chunk;
            if (strlen($bytes) > self::RESPONSE_LIMIT) {
                fclose($stream);
                throw new \RuntimeException('cloud origin response exceeded its byte limit');
            }
        }
        fclose($stream);
        if ($status !== 200 || $contentType !== 'application/json') {
            throw new \RuntimeException('cloud origin request was refused; remote output is redacted');
        }
        return $bytes;
    }

    /** @param array<string,mixed> $demand */
    private static function demand(array $demand, ?string $expectedCommit = null): void {
        self::exactKeys($demand, [
            'chunk_size', 'demand_generation', 'demand_id', 'expires_at',
            'expected_production_commit', 'format', 'nonce', 'retention_deadline', 'snapshot_mode',
        ], 'cloud origin portable demand');
        if (($demand['format'] ?? null) !== 'duo-cloud-origin-export-demand/v1'
            || ($demand['snapshot_mode'] ?? null) !== 'portable-refresh'
            || ($demand['chunk_size'] ?? null) !== 1048576
            || !is_int($demand['demand_generation'] ?? null) || $demand['demand_generation'] < 1
            || !is_int($demand['expires_at'] ?? null) || $demand['expires_at'] < 1
            || !is_int($demand['retention_deadline'] ?? null)
            || $demand['retention_deadline'] <= $demand['expires_at']) {
            throw new \RuntimeException('cloud origin portable demand is malformed');
        }
        self::sha256($demand['demand_id'] ?? null, 'cloud origin demand id');
        self::commit($demand['expected_production_commit'] ?? null, 'cloud origin demand expected commit');
        $nonce = self::base64($demand['nonce'] ?? null, 'cloud origin demand nonce');
        if (strlen($nonce) !== 32
            || ($expectedCommit !== null && $demand['expected_production_commit'] !== $expectedCommit)) {
            throw new \RuntimeException('cloud origin portable demand is not bound to the requested commit');
        }
    }

    /** @param array<string,mixed> $input */
    private static function requestId(
        string $keyId,
        string $tenantId,
        string $siteId,
        string $operationId,
        string $action,
        array $input
    ): string {
        return hash(
            'sha256',
            "duo-cloud-origin-controller-request/v1\0$keyId\0$tenantId\0$siteId\0"
                . "$operationId\0$action\0" . hash('sha256', self::encode($input))
        );
    }

    private static function endpoint(string $endpoint): string {
        $parts = parse_url($endpoint);
        if ($endpoint === '' || strlen($endpoint) > 2048 || str_contains($endpoint, "\r")
            || str_contains($endpoint, "\n") || filter_var($endpoint, FILTER_VALIDATE_URL) === false
            || !is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query'])
            || isset($parts['fragment']) || ($parts['path'] ?? '') !== self::PATH) {
            throw new \RuntimeException('cloud origin endpoint must be the credential-free controller export route');
        }
        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower((string) $parts['host']);
        $testLoopback = getenv('DUO_TEST_MODE') === '1' && self::loopbackHost($host);
        if ($scheme !== 'https' && !($scheme === 'http' && $testLoopback)) {
            throw new \RuntimeException('cloud origin endpoint must use HTTPS');
        }
        return $endpoint;
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

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("$label has unknown or missing fields");
        }
    }

    /** @return array<string,mixed> */
    private static function decodeCanonicalObject(string $bytes, int $limit, string $label): array {
        if ($bytes === '' || strlen($bytes) > $limit) {
            throw new \RuntimeException("$label is empty or exceeds its byte limit");
        }
        try {
            $value = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $error) {
            throw new \RuntimeException("$label is not valid JSON", 0, $error);
        }
        if (!is_array($value) || array_is_list($value) || self::encode($value) . "\n" !== $bytes) {
            throw new \RuntimeException("$label is not canonical JSON");
        }
        return $value;
    }

    private static function encode(mixed $value): string {
        try {
            return json_encode(
                self::canonicalize($value),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\Throwable $error) {
            throw new \RuntimeException('cloud origin value is not canonical JSON', 0, $error);
        }
    }

    private static function canonicalize(mixed $value): mixed {
        if (is_float($value) || is_object($value) || is_resource($value)) {
            throw new \RuntimeException('cloud origin canonical JSON contains an unsupported value');
        }
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

    private static function identifier(mixed $value, string $label): string {
        if (!is_string($value)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new \RuntimeException("$label is invalid");
        }
        return $value;
    }

    private static function sha256(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new \RuntimeException("$label must be lowercase SHA-256");
        }
        return $value;
    }

    private static function commit(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D', $value) !== 1) {
            throw new \RuntimeException("$label is malformed");
        }
        return $value;
    }

    private static function base64(mixed $value, string $label): string {
        $decoded = is_string($value) ? base64_decode($value, true) : false;
        if (!is_string($decoded) || base64_encode($decoded) !== $value) {
            throw new \RuntimeException("$label is not canonical base64");
        }
        return $decoded;
    }
}
