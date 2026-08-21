<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/CanonicalJson.php';
require_once __DIR__ . '/ControlAuthority.php';
require_once __DIR__ . '/PreviewSlotLifecycle.php';

/**
 * Mutually authenticated controller boundary for preview-slot lifecycle calls.
 *
 * This is intentionally distinct from the workload command protocol. A
 * controller key proves tenant/site identity here; PreviewSlotLifecycle still
 * owns every resource/generation/fence comparison and replay receipt.
 */
final class PreviewLifecycleGateway {
    public const ENVELOPE_FORMAT = 'duo-cloud-preview-signed-envelope/v1';
    public const REQUEST_FORMAT = 'duo-cloud-preview-lifecycle-request/v1';
    public const RESPONSE_FORMAT = 'duo-cloud-preview-lifecycle-response/v1';
    public const PROVIDER_ID = 'duo-cloud-preview';
    public const PROVIDER_PROTOCOL = 1;

    private string $responseSecretKey;

    public function __construct(
        private PreviewSlotLifecycle $lifecycle,
        private ControlAuthority $controllerKeys,
        private string $responseKeyId,
        string $responseSecretKey
    ) {
        self::identifier($responseKeyId, 'response key id');
        if (strlen($responseSecretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new ControlRefusal('preview lifecycle response key is not Ed25519');
        }
        $this->responseSecretKey = $responseSecretKey;
    }

    public function __destruct() {
        if ($this->responseSecretKey !== '') {
            sodium_memzero($this->responseSecretKey);
        }
    }

    /** Return a canonical signed response with exactly one trailing LF. */
    public function handle(string $requestBytes): string {
        $envelope = CanonicalJson::decodeObject($requestBytes);
        self::exactKeys($envelope, ['format', 'key_id', 'payload', 'signature'], 'lifecycle envelope');
        if (($envelope['format'] ?? null) !== self::ENVELOPE_FORMAT
            || !is_array($envelope['payload'] ?? null) || array_is_list($envelope['payload'])) {
            throw new ControlRefusal('preview lifecycle envelope is malformed');
        }
        $keyId = self::identifier($envelope['key_id'] ?? null, 'request key id');
        $payload = $envelope['payload'];
        self::payload($payload);
        $signature = self::base64Signature($envelope['signature'] ?? null);
        $payloadBytes = CanonicalJson::encode($payload);
        $this->controllerKeys->authorizeRequestKey(
            $keyId,
            $payload['tenant_id'],
            $payload['site_id'],
            $payloadBytes,
            $signature
        );
        $expectedRequestId = hash(
            'sha256',
            "duo-cloud-preview-lifecycle/v1\0{$payload['tenant_id']}\0{$payload['site_id']}\0"
                . "{$payload['environment']}\0{$payload['operation_id']}\0{$payload['action']}\0"
                . hash('sha256', CanonicalJson::encode($payload['input']))
        );
        if (!hash_equals($expectedRequestId, $payload['request_id'])) {
            throw new ControlRefusal('preview lifecycle request id does not bind its exact input');
        }
        $result = $payload['action'] === 'capabilities'
            ? [
                'capabilities' => self::capabilities(),
                'repository_authority' => $this->lifecycle->repositoryAuthorityDescriptor(),
                'reviewed_base_containment' => $this->lifecycle->reviewedBaseContainmentDescriptor(),
            ]
            : $this->lifecycle->perform(
                $payload['action'],
                $payload['tenant_id'],
                $payload['site_id'],
                $payload['operation_id'],
                $payload['input']
            );
        $responsePayload = [
            'action' => $payload['action'],
            'environment' => $payload['environment'],
            'format' => self::RESPONSE_FORMAT,
            'operation_id' => $payload['operation_id'],
            'provider' => ['id' => self::PROVIDER_ID, 'protocol' => self::PROVIDER_PROTOCOL],
            'request_sha256' => hash('sha256', $payloadBytes),
            'result' => $result,
            'site_id' => $payload['site_id'],
            'status' => 'ok',
            'tenant_id' => $payload['tenant_id'],
        ];
        $response = [
            'format' => self::ENVELOPE_FORMAT,
            'key_id' => $this->responseKeyId,
            'payload' => $responsePayload,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                CanonicalJson::encode($responsePayload),
                $this->responseSecretKey
            )),
        ];
        return CanonicalJson::encode($response) . "\n";
    }

    /** @return list<string> */
    private static function capabilities(): array {
        $capabilities = [
            'environment.create',
            'environment.destroy',
            'environment.inspect',
            'environment.mutation.acquire',
            'environment.mutation.read',
            'environment.mutation.release',
            'environment.sleep',
            'environment.ttl',
            'environment.ttl.read',
            'environment.url.discover',
            'environment.url.set',
            'environment.wake',
            'operation.receipts',
            'repository.materialize',
            'repository.sync',
            'snapshot.set.restore',
        ];
        sort($capabilities, SORT_STRING);
        return $capabilities;
    }

    /** @param array<string,mixed> $payload */
    private static function payload(array $payload): void {
        self::exactKeys($payload, [
            'action', 'environment', 'format', 'input', 'operation_id',
            'request_id', 'site_id', 'tenant_id',
        ], 'lifecycle request payload');
        if (($payload['format'] ?? null) !== self::REQUEST_FORMAT
            || !in_array($payload['action'] ?? null, [
                'capabilities', 'create', 'destroy', 'inspect', 'mutation-acquire',
                'mutation-read', 'mutation-release', 'repository-materialize',
                'repository-sync', 'sleep', 'snapshot-restore', 'ttl-read', 'ttl-set',
                'url-set', 'wake',
            ], true)
            || !is_array($payload['input'] ?? null)
            || (array_is_list($payload['input']) && $payload['input'] !== [])) {
            throw new ControlRefusal('preview lifecycle request payload is malformed');
        }
        foreach (['environment', 'operation_id', 'site_id', 'tenant_id'] as $field) {
            self::identifier($payload[$field] ?? null, "lifecycle $field");
        }
        self::sha256($payload['request_id'] ?? null, 'lifecycle request id');
        if ($payload['action'] === 'capabilities' && $payload['input'] !== []) {
            throw new ControlRefusal('preview lifecycle capabilities input must be empty');
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

    private static function base64Signature(mixed $value): string {
        $decoded = is_string($value) ? base64_decode($value, true) : false;
        if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_BYTES
            || base64_encode($decoded) !== $value) {
            throw new ControlRefusal('preview lifecycle request signature is invalid');
        }
        return $decoded;
    }
}
