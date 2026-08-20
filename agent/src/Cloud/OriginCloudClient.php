<?php
declare(strict_types=1);

namespace Duo;

if (!class_exists(Canon::class, false)) {
    require_once __DIR__ . '/../Kernel/Canon.php';
}

/**
 * One authenticated refusal produced by the paired cloud service.
 *
 * Only the closed refusal vocabulary is exposed. Transport failures and
 * malformed or unsigned HTTP error bodies remain ordinary RuntimeExceptions,
 * so callers cannot accidentally treat an unauthenticated string as policy.
 */
final class OriginCloudRefusal extends \RuntimeException {
    public function __construct(
        private string $reasonCode,
        private bool $retryable,
        private string $requestFormat,
        private string $requestSha256
    ) {
        parent::__construct("duo: cloud origin request refused ($reasonCode)");
    }

    public function reasonCode(): string {
        return $this->reasonCode;
    }

    public function retryable(): bool {
        return $this->retryable;
    }

    public function requestFormat(): string {
        return $this->requestFormat;
    }

    public function requestSha256(): string {
        return $this->requestSha256;
    }
}

/**
 * Strict outbound client for the credential-free Duo Cloud origin endpoint.
 *
 * Public methods name the complete protocol surface. No caller can provide a
 * URL path, query, redirect policy, authorization header, request id, action,
 * script, or argv. Every request is a canonical, detached-Ed25519 envelope;
 * every accepted response independently binds the exact request hash and all
 * endpoint identities to the configured service key.
 */
final class OriginCloudClient {
    public const ENVELOPE_FORMAT = 'duo-cloud-origin-signed-envelope/v1';

    private const PAIR_BEGIN_PATH = '/v1/origin/pair/begin';
    private const PAIR_POLL_PATH = '/v1/origin/pair/poll';
    private const DEMAND_POLL_PATH = '/v1/origin/demand/poll';
    private const ANNOUNCE_PATH = '/v1/origin/export/announce';
    private const MISSING_PATH = '/v1/origin/export/missing';
    private const CHUNK_PATH = '/v1/origin/export/chunk';
    private const COMMIT_PATH = '/v1/origin/export/commit';
    private const ROTATE_PATH = '/v1/origin/key/rotate';
    private const REVOKE_PATH = '/v1/origin/revoke';

    private const PAIR_BEGIN_REQUEST = 'duo-cloud-origin-pair-begin-request/v1';
    private const PAIR_BEGIN_RESPONSE = 'duo-cloud-origin-pair-begin-response/v1';
    private const PAIR_POLL_REQUEST = 'duo-cloud-origin-pair-poll-request/v1';
    private const PAIR_POLL_RESPONSE = 'duo-cloud-origin-pair-poll-response/v1';
    private const DEMAND_POLL_REQUEST = 'duo-cloud-origin-demand-poll-request/v1';
    private const DEMAND_POLL_RESPONSE = 'duo-cloud-origin-demand-poll-response/v1';
    private const DEMAND_FORMAT = 'duo-cloud-origin-export-demand/v1';
    private const ANNOUNCE_REQUEST = 'duo-cloud-origin-export-announce-request/v1';
    private const ANNOUNCE_RESPONSE = 'duo-cloud-origin-export-announce-response/v1';
    private const MISSING_REQUEST = 'duo-cloud-origin-export-missing-request/v1';
    private const MISSING_RESPONSE = 'duo-cloud-origin-export-missing-response/v1';
    private const CHUNK_REQUEST = 'duo-cloud-origin-export-chunk-request/v1';
    private const CHUNK_RESPONSE = 'duo-cloud-origin-export-chunk-response/v1';
    private const COMMIT_REQUEST = 'duo-cloud-origin-export-commit-request/v1';
    private const COMMIT_RESPONSE = 'duo-cloud-origin-export-commit-response/v1';
    private const ROTATE_REQUEST = 'duo-cloud-origin-key-rotation-request/v1';
    private const ROTATE_RESPONSE = 'duo-cloud-origin-key-rotation-response/v1';
    private const POSSESSION_FORMAT = 'duo-cloud-origin-key-possession/v1';
    private const REVOKE_REQUEST = 'duo-cloud-origin-revoke-request/v1';
    private const REVOKE_RESPONSE = 'duo-cloud-origin-revoke-response/v1';
    private const REFUSAL_FORMAT = 'duo-cloud-origin-refusal/v1';

    private const GENERAL_BODY_LIMIT = 1048576;
    private const CHUNK_BODY_LIMIT = 2097152;
    private const CHUNK_BYTES = 1048576;
    private const MAX_SAFE_INTEGER = 9007199254740991;
    private const MAX_TIMEOUT_SECONDS = 30;

    /** @var list<string> */
    private const REFUSAL_CODES = [
        'chunk_not_announced',
        'commit_incomplete',
        'demand_expired',
        'manifest_conflict',
        'origin_revoked',
        'pairing_code_rejected',
        'rate_limited',
        'recovery_required',
        'stale_demand_generation',
        'stale_origin_generation',
    ];

    private string $baseEndpoint;
    private string $serviceKeyId;
    private string $servicePublicKey;
    private string $originSecretKey;
    private string $originPublicKey;
    private string $originKeyId;
    private int $timeoutSeconds;
    /** @var ?\Closure(string,string,int,int):array<string,mixed> */
    private ?\Closure $testExchange;

    /**
     * The exchange seam is deliberately unavailable outside DUO_TEST_MODE.
     * It receives (exact URL, exact request bytes, timeout, response limit)
     * and must return exactly status/body/effective_url/redirected.
     *
     * @param ?callable(string,string,int,int):array<string,mixed> $testExchange
     */
    public function __construct(
        string $baseEndpoint,
        string $serviceKeyId,
        string $servicePublicKey,
        string $originSecretKey,
        ?callable $testExchange = null,
        int $timeoutSeconds = 15
    ) {
        self::assertSodium();
        $this->baseEndpoint = self::testMode()
            ? self::normalizeTestEndpoint($baseEndpoint)
            : self::normalizeProductionEndpoint(
                $baseEndpoint,
                $serviceKeyId,
                $servicePublicKey
            );
        $this->serviceKeyId = self::identifier($serviceKeyId, 'service key id');
        if (strlen($servicePublicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \RuntimeException('duo: cloud origin service key is not an Ed25519 public key');
        }
        if (strlen($originSecretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('duo: cloud origin key is not an Ed25519 secret key');
        }
        if ($timeoutSeconds < 1 || $timeoutSeconds > self::MAX_TIMEOUT_SECONDS) {
            throw new \RuntimeException('duo: cloud origin timeout must be between 1 and 30 seconds');
        }
        if ($testExchange !== null && !self::testMode()) {
            throw new \RuntimeException('duo: cloud origin exchange injection requires DUO_TEST_MODE');
        }

        $this->servicePublicKey = $servicePublicKey;
        $this->originSecretKey = $originSecretKey;
        $this->originPublicKey = sodium_crypto_sign_publickey_from_secretkey($originSecretKey);
        $this->originKeyId = self::deriveOriginKeyId($this->originPublicKey);
        $this->timeoutSeconds = $timeoutSeconds;
        $this->testExchange = $testExchange === null ? null : \Closure::fromCallable($testExchange);
    }

    /**
     * No production hostname/service-key descriptor is owned by this release.
     * Keeping this factory closed prevents wp-config or site data from turning
     * a test endpoint into production authority.
     */
    public static function production(): self {
        throw new \RuntimeException('duo: Duo Cloud origin endpoint is not pinned by this release');
    }

    public static function normalizeTestEndpoint(string $endpoint): string {
        if (!self::testMode()) {
            throw new \RuntimeException('duo: cloud origin endpoint override requires DUO_TEST_MODE');
        }
        return self::baseEndpoint($endpoint);
    }

    /**
     * Resolve the target-owned wp-config trust pin used outside test mode.
     * Endpoint and service key are public verification material; keeping them
     * in immutable constants prevents a database/plugin setting from silently
     * redirecting production export authority.
     *
     * @return array{endpoint:string,service_key_id:string,service_public_key:string}
     */
    public static function productionDescriptor(): array {
        $names = [
            'DUO_CLOUD_ORIGIN_ENDPOINT',
            'DUO_CLOUD_ORIGIN_SERVICE_KEY_ID',
            'DUO_CLOUD_ORIGIN_SERVICE_PUBLIC_KEY',
        ];
        foreach ($names as $name) {
            if (!defined($name) || !is_string(constant($name)) || constant($name) === '') {
                throw new \RuntimeException(
                    'duo: Duo Cloud origin endpoint and service key are not pinned in wp-config'
                );
            }
        }
        $endpoint = self::baseEndpoint((string) constant('DUO_CLOUD_ORIGIN_ENDPOINT'));
        $keyId = self::identifier(
            (string) constant('DUO_CLOUD_ORIGIN_SERVICE_KEY_ID'),
            'service key id'
        );
        $encoded = (string) constant('DUO_CLOUD_ORIGIN_SERVICE_PUBLIC_KEY');
        $publicKey = base64_decode($encoded, true);
        if (!is_string($publicKey)
            || strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES
            || base64_encode($publicKey) !== $encoded) {
            throw new \RuntimeException('duo: configured cloud origin service key is not canonical Ed25519');
        }
        return [
            'endpoint' => $endpoint,
            'service_key_id' => $keyId,
            'service_public_key' => $publicKey,
        ];
    }

    public static function normalizeProductionEndpoint(
        string $endpoint,
        string $serviceKeyId,
        string $servicePublicKey
    ): string {
        if (self::testMode()) {
            throw new \RuntimeException('duo: production cloud origin trust cannot be selected in test mode');
        }
        $configured = self::productionDescriptor();
        if (!hash_equals($configured['endpoint'], self::baseEndpoint($endpoint))
            || !hash_equals($configured['service_key_id'], $serviceKeyId)
            || !hash_equals($configured['service_public_key'], $servicePublicKey)) {
            throw new \RuntimeException('duo: cloud origin client differs from the wp-config trust pin');
        }
        return $configured['endpoint'];
    }

    public function __destruct() {
        if ($this->originSecretKey !== '') {
            sodium_memzero($this->originSecretKey);
        }
    }

    /** @return array<string,mixed> */
    public function __debugInfo(): array {
        return [
            'base_endpoint' => $this->baseEndpoint,
            'origin_key_id' => $this->originKeyId,
            'service_key_id' => $this->serviceKeyId,
            'timeout_seconds' => $this->timeoutSeconds,
        ];
    }

    /** @return never */
    public function __serialize(): array {
        throw new \RuntimeException('duo: cloud origin clients containing private keys cannot be serialized');
    }

    public function __clone(): void {
        throw new \RuntimeException('duo: cloud origin clients containing private keys cannot be cloned');
    }

    public function originKeyId(): string {
        return $this->originKeyId;
    }

    public function originPublicKey(): string {
        return $this->originPublicKey;
    }

    public function serviceKeyId(): string {
        return $this->serviceKeyId;
    }

    public static function deriveOriginKeyId(string $publicKey): string {
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new \RuntimeException('duo: cloud origin public key is not Ed25519');
        }
        return hash('sha256', "duo-cloud-origin-key/v1\0" . $publicKey);
    }

    public static function rotationId(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        string $newOriginKeyId
    ): string {
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        self::positiveInteger($originGeneration, 'origin generation');
        self::sha256($newOriginKeyId, 'new origin key id');
        return self::requestId('duo-cloud-origin-key-rotation/v1', [
            $tenantId,
            $siteId,
            (string) $originGeneration,
            $newOriginKeyId,
        ]);
    }

    public static function revocationId(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        string $reason
    ): string {
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        self::positiveInteger($originGeneration, 'origin generation');
        if (!in_array($reason, ['administrator_requested', 'uninstall'], true)) {
            throw new \RuntimeException('duo: cloud origin revocation reason is not allowed');
        }
        return self::requestId('duo-cloud-origin-revoke/v1', [
            $tenantId,
            $siteId,
            (string) $originGeneration,
            $reason,
        ]);
    }

    /**
     * @param array<string,mixed> $connector
     * @return array<string,mixed>
     */
    public function pairBegin(string $deviceCode, array $connector, string $pairAttemptId): array {
        self::deviceCode($deviceCode);
        self::connector($connector);
        self::hexId($pairAttemptId, 32, 'pair attempt id');
        $requestId = self::requestId(
            'duo-cloud-origin-pair-begin/v1',
            [$pairAttemptId, $this->originKeyId]
        );
        $payload = [
            'connector' => $connector,
            'device_code' => $deviceCode,
            'format' => self::PAIR_BEGIN_REQUEST,
            'origin_key' => [
                'algorithm' => 'Ed25519',
                'key_id' => $this->originKeyId,
                'public_key' => base64_encode($this->originPublicKey),
            ],
            'pair_attempt_id' => $pairAttemptId,
            'request_id' => $requestId,
        ];

        return $this->transact(
            self::PAIR_BEGIN_PATH,
            $payload,
            self::PAIR_BEGIN_RESPONSE,
            [
                'expires_at', 'format', 'origin_key_id', 'pair_attempt_id',
                'pairing_id', 'poll_after_seconds', 'request_sha256', 'state',
            ],
            function (array $response) use ($pairAttemptId): void {
                self::timestamp($response['expires_at'] ?? null, 'pair expiry');
                if (($response['origin_key_id'] ?? null) !== $this->originKeyId
                    || ($response['pair_attempt_id'] ?? null) !== $pairAttemptId
                    || ($response['state'] ?? null) !== 'pending') {
                    throw new \RuntimeException('duo: cloud origin pair-begin response identity does not match');
                }
                self::identifier($response['pairing_id'] ?? null, 'pairing id');
                self::pollAfter($response['poll_after_seconds'] ?? null, true);
            }
        );
    }

    /** @return array<string,mixed> */
    public function pairPoll(string $pairAttemptId, string $pairingId, int $pollSequence): array {
        self::hexId($pairAttemptId, 32, 'pair attempt id');
        self::identifier($pairingId, 'pairing id');
        self::nonNegativeInteger($pollSequence, 'pair poll sequence');
        $payload = [
            'format' => self::PAIR_POLL_REQUEST,
            'origin_key_id' => $this->originKeyId,
            'pair_attempt_id' => $pairAttemptId,
            'pairing_id' => $pairingId,
            'poll_sequence' => $pollSequence,
            'request_id' => self::requestId(
                'duo-cloud-origin-pair-poll/v1',
                [$pairAttemptId, $pairingId, (string) $pollSequence]
            ),
        ];

        return $this->transact(
            self::PAIR_POLL_PATH,
            $payload,
            self::PAIR_POLL_RESPONSE,
            [
                'expires_at', 'format', 'origin_key_id', 'pair_attempt_id',
                'pairing', 'pairing_id', 'poll_after_seconds', 'poll_sequence',
                'request_sha256', 'state',
            ],
            function (array $response) use ($pairAttemptId, $pairingId, $pollSequence): void {
                self::timestamp($response['expires_at'] ?? null, 'pair expiry');
                if (($response['origin_key_id'] ?? null) !== $this->originKeyId
                    || ($response['pair_attempt_id'] ?? null) !== $pairAttemptId
                    || ($response['pairing_id'] ?? null) !== $pairingId
                    || ($response['poll_sequence'] ?? null) !== $pollSequence
                    || !in_array($response['state'] ?? null, ['pending', 'paired', 'denied', 'expired'], true)) {
                    throw new \RuntimeException('duo: cloud origin pair-poll response identity does not match');
                }
                $pending = $response['state'] === 'pending';
                self::pollAfter($response['poll_after_seconds'] ?? null, $pending);
                if ($response['state'] === 'paired') {
                    if (!is_array($response['pairing'] ?? null) || array_is_list($response['pairing'])) {
                        throw new \RuntimeException('duo: cloud origin paired response has no pairing authority');
                    }
                    self::pairingAuthority(
                        $response['pairing'],
                        $this->serviceKeyId,
                        hash('sha256', $this->servicePublicKey)
                    );
                } elseif (($response['pairing'] ?? null) !== null) {
                    throw new \RuntimeException('duo: cloud origin non-paired response contains pairing authority');
                }
            }
        );
    }

    /** @return array<string,mixed> */
    public function demandPoll(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $afterDemandGeneration,
        int $pollSequence
    ): array {
        self::authorityIdentity($tenantId, $siteId, $originGeneration);
        self::nonNegativeInteger($afterDemandGeneration, 'demand generation cursor');
        self::nonNegativeInteger($pollSequence, 'demand poll sequence');
        $payload = [
            'after_demand_generation' => $afterDemandGeneration,
            'format' => self::DEMAND_POLL_REQUEST,
            'origin_generation' => $originGeneration,
            'origin_key_id' => $this->originKeyId,
            'poll_sequence' => $pollSequence,
            'request_id' => self::requestId('duo-cloud-origin-demand-poll/v1', [
                $tenantId,
                $siteId,
                (string) $originGeneration,
                $this->originKeyId,
                (string) $afterDemandGeneration,
                (string) $pollSequence,
            ]),
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ];

        return $this->transact(
            self::DEMAND_POLL_PATH,
            $payload,
            self::DEMAND_POLL_RESPONSE,
            [
                'after_demand_generation', 'demand', 'format', 'origin_generation',
                'origin_key_id', 'poll_after_seconds', 'poll_sequence',
                'request_sha256', 'site_id', 'state', 'tenant_id',
            ],
            function (array $response) use (
                $tenantId,
                $siteId,
                $originGeneration,
                $afterDemandGeneration,
                $pollSequence
            ): void {
                self::matchingAuthority(
                    $response,
                    $tenantId,
                    $siteId,
                    $originGeneration,
                    $this->originKeyId
                );
                if (($response['after_demand_generation'] ?? null) !== $afterDemandGeneration
                    || ($response['poll_sequence'] ?? null) !== $pollSequence
                    || !in_array($response['state'] ?? null, ['idle', 'demanded', 'revoked'], true)) {
                    throw new \RuntimeException('duo: cloud origin demand-poll response identity does not match');
                }
                self::pollAfter($response['poll_after_seconds'] ?? null, $response['state'] === 'idle');
                if ($response['state'] === 'demanded') {
                    if (!is_array($response['demand'] ?? null) || array_is_list($response['demand'])) {
                        throw new \RuntimeException('duo: cloud origin demanded response has no demand');
                    }
                    self::demand(
                        $response['demand'],
                        $afterDemandGeneration,
                        $tenantId,
                        $siteId,
                        $originGeneration
                    );
                } elseif (($response['demand'] ?? null) !== null) {
                    throw new \RuntimeException('duo: cloud origin non-demanded response contains a demand');
                }
            }
        );
    }

    /**
     * @param array<string,mixed> $manifest verified OriginStore manifest
     * @return array<string,mixed>
     */
    public function announce(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $demandId,
        array $manifest
    ): array {
        self::authorityIdentity($tenantId, $siteId, $originGeneration);
        self::positiveInteger($demandGeneration, 'demand generation');
        self::identifier($demandId, 'demand id');
        $manifestSha256 = self::manifestReference($manifest);
        if ($manifest['generation'] !== $demandGeneration) {
            throw new \RuntimeException('duo: cloud origin manifest generation does not match the demand');
        }
        $payload = [
            'demand_generation' => $demandGeneration,
            'demand_id' => $demandId,
            'format' => self::ANNOUNCE_REQUEST,
            'manifest' => $manifest,
            'origin_generation' => $originGeneration,
            'origin_key_id' => $this->originKeyId,
            'request_id' => self::requestId('duo-cloud-origin-export-announce/v1', [
                $tenantId,
                $siteId,
                (string) $originGeneration,
                $this->originKeyId,
                (string) $demandGeneration,
                $demandId,
                $manifestSha256,
            ]),
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ];

        return $this->transact(
            self::ANNOUNCE_PATH,
            $payload,
            self::ANNOUNCE_RESPONSE,
            [
                'demand_generation', 'demand_id', 'export_id', 'format',
                'manifest_sha256', 'origin_generation', 'origin_key_id',
                'request_sha256', 'site_id', 'state', 'tenant_id',
            ],
            function (array $response) use (
                $tenantId,
                $siteId,
                $originGeneration,
                $demandGeneration,
                $demandId,
                $manifestSha256
            ): void {
                self::matchingExport(
                    $response,
                    $tenantId,
                    $siteId,
                    $originGeneration,
                    $this->originKeyId,
                    $demandGeneration,
                    $demandId,
                    $manifestSha256
                );
                if (($response['state'] ?? null) !== 'announced') {
                    throw new \RuntimeException('duo: cloud origin announce response is not announced');
                }
                $expectedExportId = self::requestId('duo-cloud-origin-export/v1', [
                    $tenantId,
                    $siteId,
                    (string) $originGeneration,
                    (string) $demandGeneration,
                    $demandId,
                    $manifestSha256,
                ]);
                if (($response['export_id'] ?? null) !== $expectedExportId) {
                    throw new \RuntimeException('duo: cloud origin announce response export id is invalid');
                }
            }
        );
    }

    /**
     * The announced chunk set is local sealed evidence. It is not sent again,
     * but is required here so a signed response still cannot name a hash that
     * was absent from the immutable manifest.
     *
     * @param list<string> $announcedChunkSha256
     * @return array<string,mixed>
     */
    public function missing(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $demandId,
        string $exportId,
        string $manifestSha256,
        int $querySequence,
        array $announcedChunkSha256
    ): array {
        self::exportIdentity(
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $demandId,
            $exportId,
            $manifestSha256
        );
        self::nonNegativeInteger($querySequence, 'missing query sequence');
        self::sortedUniqueHashes($announcedChunkSha256, 'announced chunk hashes');
        $payload = [
            'demand_generation' => $demandGeneration,
            'demand_id' => $demandId,
            'export_id' => $exportId,
            'format' => self::MISSING_REQUEST,
            'manifest_sha256' => $manifestSha256,
            'origin_generation' => $originGeneration,
            'origin_key_id' => $this->originKeyId,
            'query_sequence' => $querySequence,
            'request_id' => self::requestId('duo-cloud-origin-export-missing/v1', [
                $tenantId,
                $siteId,
                (string) $originGeneration,
                $this->originKeyId,
                (string) $demandGeneration,
                $demandId,
                $exportId,
                $manifestSha256,
                (string) $querySequence,
            ]),
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ];

        return $this->transact(
            self::MISSING_PATH,
            $payload,
            self::MISSING_RESPONSE,
            [
                'demand_generation', 'demand_id', 'export_id', 'format',
                'manifest_sha256', 'missing_chunk_sha256', 'origin_generation',
                'origin_key_id', 'query_sequence', 'request_sha256', 'site_id',
                'tenant_id',
            ],
            function (array $response) use (
                $tenantId,
                $siteId,
                $originGeneration,
                $demandGeneration,
                $demandId,
                $exportId,
                $manifestSha256,
                $querySequence,
                $announcedChunkSha256
            ): void {
                self::matchingExport(
                    $response,
                    $tenantId,
                    $siteId,
                    $originGeneration,
                    $this->originKeyId,
                    $demandGeneration,
                    $demandId,
                    $manifestSha256,
                    $exportId
                );
                if (($response['query_sequence'] ?? null) !== $querySequence
                    || !is_array($response['missing_chunk_sha256'] ?? null)
                    || !array_is_list($response['missing_chunk_sha256'])) {
                    throw new \RuntimeException('duo: cloud origin missing response is malformed');
                }
                self::sortedUniqueHashes($response['missing_chunk_sha256'], 'missing chunk hashes');
                $announced = array_fill_keys($announcedChunkSha256, true);
                foreach ($response['missing_chunk_sha256'] as $hash) {
                    if (!isset($announced[$hash])) {
                        throw new \RuntimeException('duo: cloud origin missing response names an unannounced chunk');
                    }
                }
            }
        );
    }

    /** @return array<string,mixed> */
    public function putChunk(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $demandId,
        string $exportId,
        string $manifestSha256,
        string $chunkSha256,
        string $chunkBytes
    ): array {
        self::exportIdentity(
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $demandId,
            $exportId,
            $manifestSha256
        );
        self::sha256($chunkSha256, 'chunk hash');
        $chunkSize = strlen($chunkBytes);
        if ($chunkSize < 1 || $chunkSize > self::CHUNK_BYTES
            || !hash_equals($chunkSha256, hash('sha256', $chunkBytes))) {
            throw new \RuntimeException('duo: cloud origin chunk bytes do not match their bounded hash');
        }
        $payload = [
            'chunk_base64' => base64_encode($chunkBytes),
            'chunk_sha256' => $chunkSha256,
            'chunk_size' => $chunkSize,
            'demand_generation' => $demandGeneration,
            'demand_id' => $demandId,
            'export_id' => $exportId,
            'format' => self::CHUNK_REQUEST,
            'manifest_sha256' => $manifestSha256,
            'origin_generation' => $originGeneration,
            'origin_key_id' => $this->originKeyId,
            'request_id' => self::requestId(
                'duo-cloud-origin-export-chunk/v1',
                [
                    $tenantId,
                    $siteId,
                    (string) $originGeneration,
                    $this->originKeyId,
                    (string) $demandGeneration,
                    $demandId,
                    $exportId,
                    $manifestSha256,
                    $chunkSha256,
                    (string) $chunkSize,
                ]
            ),
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ];

        return $this->transact(
            self::CHUNK_PATH,
            $payload,
            self::CHUNK_RESPONSE,
            [
                'chunk_sha256', 'chunk_size', 'demand_generation', 'demand_id',
                'export_id', 'format', 'manifest_sha256', 'origin_generation',
                'origin_key_id', 'request_sha256', 'site_id', 'state', 'tenant_id',
            ],
            function (array $response) use (
                $tenantId,
                $siteId,
                $originGeneration,
                $demandGeneration,
                $demandId,
                $exportId,
                $manifestSha256,
                $chunkSha256,
                $chunkSize
            ): void {
                self::matchingExport(
                    $response,
                    $tenantId,
                    $siteId,
                    $originGeneration,
                    $this->originKeyId,
                    $demandGeneration,
                    $demandId,
                    $manifestSha256,
                    $exportId
                );
                if (($response['chunk_sha256'] ?? null) !== $chunkSha256
                    || ($response['chunk_size'] ?? null) !== $chunkSize
                    || ($response['state'] ?? null) !== 'stored') {
                    throw new \RuntimeException('duo: cloud origin chunk response does not match stored bytes');
                }
            },
            self::CHUNK_BODY_LIMIT
        );
    }

    /** @return array<string,mixed> */
    public function commit(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $demandId,
        string $exportId,
        string $manifestSha256
    ): array {
        self::exportIdentity(
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $demandId,
            $exportId,
            $manifestSha256
        );
        $payload = [
            'demand_generation' => $demandGeneration,
            'demand_id' => $demandId,
            'export_id' => $exportId,
            'format' => self::COMMIT_REQUEST,
            'manifest_sha256' => $manifestSha256,
            'origin_generation' => $originGeneration,
            'origin_key_id' => $this->originKeyId,
            'request_id' => self::requestId(
                'duo-cloud-origin-export-commit/v1',
                [
                    $tenantId,
                    $siteId,
                    (string) $originGeneration,
                    $this->originKeyId,
                    (string) $demandGeneration,
                    $demandId,
                    $exportId,
                    $manifestSha256,
                ]
            ),
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ];

        return $this->transact(
            self::COMMIT_PATH,
            $payload,
            self::COMMIT_RESPONSE,
            [
                'commit_receipt_sha256', 'demand_generation', 'demand_id',
                'export_id', 'format', 'manifest_sha256', 'origin_generation',
                'origin_key_id', 'request_sha256', 'retention_deadline', 'site_id',
                'snapshot_hash', 'state', 'tenant_id',
            ],
            function (array $response) use (
                $tenantId,
                $siteId,
                $originGeneration,
                $demandGeneration,
                $demandId,
                $exportId,
                $manifestSha256
            ): void {
                self::matchingExport(
                    $response,
                    $tenantId,
                    $siteId,
                    $originGeneration,
                    $this->originKeyId,
                    $demandGeneration,
                    $demandId,
                    $manifestSha256,
                    $exportId
                );
                if (($response['state'] ?? null) !== 'committed') {
                    throw new \RuntimeException('duo: cloud origin commit response is not committed');
                }
                self::sha256($response['commit_receipt_sha256'] ?? null, 'commit receipt hash');
                self::sha256($response['snapshot_hash'] ?? null, 'committed snapshot hash');
                self::timestamp($response['retention_deadline'] ?? null, 'retention deadline');
            }
        );
    }

    /**
     * The current origin key signs the request. The new key independently
     * signs the exact possession statement, preventing rotation to an
     * unowned or substituted public key.
     *
     * @return array<string,mixed>
     */
    public function rotate(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $nextOriginGeneration,
        string $newOriginSecretKey
    ): array {
        self::authorityIdentity($tenantId, $siteId, $originGeneration);
        if ($nextOriginGeneration !== $originGeneration + 1
            || $nextOriginGeneration > self::MAX_SAFE_INTEGER) {
            throw new \RuntimeException('duo: cloud origin key rotation must advance exactly one generation');
        }
        if (strlen($newOriginSecretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new \RuntimeException('duo: new cloud origin key is not an Ed25519 secret key');
        }
        $newPublicKey = sodium_crypto_sign_publickey_from_secretkey($newOriginSecretKey);
        $newKeyId = self::deriveOriginKeyId($newPublicKey);
        if (hash_equals($this->originKeyId, $newKeyId)) {
            throw new \RuntimeException('duo: cloud origin key rotation requires a distinct key');
        }
        $rotationId = self::rotationId(
            $tenantId,
            $siteId,
            $originGeneration,
            $newKeyId
        );
        $proofStatement = [
            'format' => self::POSSESSION_FORMAT,
            'new_origin_key_id' => $newKeyId,
            'next_origin_generation' => $nextOriginGeneration,
            'origin_generation' => $originGeneration,
            'rotation_id' => $rotationId,
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ];
        $payload = [
            'format' => self::ROTATE_REQUEST,
            'new_key' => [
                'algorithm' => 'Ed25519',
                'key_id' => $newKeyId,
                'public_key' => base64_encode($newPublicKey),
            ],
            'new_key_proof' => base64_encode(sodium_crypto_sign_detached(
                self::canonicalEncode($proofStatement),
                $newOriginSecretKey
            )),
            'next_origin_generation' => $nextOriginGeneration,
            'origin_generation' => $originGeneration,
            'origin_key_id' => $this->originKeyId,
            'request_id' => $rotationId,
            'rotation_id' => $rotationId,
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ];

        return $this->transact(
            self::ROTATE_PATH,
            $payload,
            self::ROTATE_RESPONSE,
            [
                'format', 'new_origin_key_id', 'next_origin_generation',
                'origin_generation', 'origin_key_id', 'request_sha256',
                'rotation_id', 'rotation_receipt_sha256', 'site_id', 'state',
                'tenant_id',
            ],
            function (array $response) use (
                $tenantId,
                $siteId,
                $originGeneration,
                $nextOriginGeneration,
                $rotationId,
                $newKeyId
            ): void {
                self::matchingAuthority(
                    $response,
                    $tenantId,
                    $siteId,
                    $originGeneration,
                    $this->originKeyId
                );
                if (($response['next_origin_generation'] ?? null) !== $nextOriginGeneration
                    || ($response['new_origin_key_id'] ?? null) !== $newKeyId
                    || ($response['rotation_id'] ?? null) !== $rotationId
                    || ($response['state'] ?? null) !== 'rotated') {
                    throw new \RuntimeException('duo: cloud origin rotation response identity does not match');
                }
                self::sha256($response['rotation_receipt_sha256'] ?? null, 'rotation receipt hash');
            }
        );
    }

    /** @return array<string,mixed> */
    public function revoke(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        string $reason
    ): array {
        self::authorityIdentity($tenantId, $siteId, $originGeneration);
        if (!in_array($reason, ['administrator_requested', 'uninstall'], true)) {
            throw new \RuntimeException('duo: cloud origin revocation reason is not allowed');
        }
        $revocationId = self::revocationId($tenantId, $siteId, $originGeneration, $reason);
        $payload = [
            'format' => self::REVOKE_REQUEST,
            'origin_generation' => $originGeneration,
            'origin_key_id' => $this->originKeyId,
            'reason' => $reason,
            'request_id' => $revocationId,
            'revocation_id' => $revocationId,
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ];

        return $this->transact(
            self::REVOKE_PATH,
            $payload,
            self::REVOKE_RESPONSE,
            [
                'format', 'origin_generation', 'origin_key_id', 'request_sha256',
                'revocation_id', 'revocation_receipt_sha256', 'site_id', 'state',
                'tenant_id',
            ],
            function (array $response) use (
                $tenantId,
                $siteId,
                $originGeneration,
                $revocationId
            ): void {
                self::matchingAuthority(
                    $response,
                    $tenantId,
                    $siteId,
                    $originGeneration,
                    $this->originKeyId
                );
                if (($response['revocation_id'] ?? null) !== $revocationId
                    || ($response['state'] ?? null) !== 'revoked') {
                    throw new \RuntimeException('duo: cloud origin revocation response identity does not match');
                }
                self::sha256($response['revocation_receipt_sha256'] ?? null, 'revocation receipt hash');
            }
        );
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $responseKeys
     * @param callable(array<string,mixed>):void $validate
     * @return array<string,mixed>
     */
    private function transact(
        string $path,
        array $payload,
        string $responseFormat,
        array $responseKeys,
        callable $validate,
        int $requestLimit = self::GENERAL_BODY_LIMIT
    ): array {
        $payloadBytes = self::canonicalEncode($payload);
        $requestHash = hash('sha256', $payloadBytes);
        $envelope = [
            'format' => self::ENVELOPE_FORMAT,
            'key_id' => $this->originKeyId,
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                $payloadBytes,
                $this->originSecretKey
            )),
        ];
        $requestBytes = self::canonicalEncode($envelope) . "\n";
        if (strlen($requestBytes) > $requestLimit) {
            throw new \RuntimeException('duo: cloud origin request exceeds its endpoint byte limit');
        }
        $url = $this->baseEndpoint . $path;
        $exchange = $this->exchange($url, $requestBytes, self::GENERAL_BODY_LIMIT);
        self::exactKeys($exchange, ['body', 'effective_url', 'redirected', 'status'], 'HTTP exchange');
        if (!is_int($exchange['status'] ?? null)
            || !is_string($exchange['body'] ?? null)
            || !is_string($exchange['effective_url'] ?? null)
            || !is_bool($exchange['redirected'] ?? null)) {
            throw new \RuntimeException('duo: cloud origin HTTP exchange returned an invalid result');
        }
        if ($exchange['redirected'] || $exchange['effective_url'] !== $url
            || ($exchange['status'] >= 300 && $exchange['status'] <= 399)) {
            throw new \RuntimeException('duo: cloud origin HTTP redirects are forbidden');
        }
        if ($exchange['status'] >= 400 && $exchange['status'] <= 499) {
            // Malformed, unknown-key, and bad-signature requests deliberately
            // receive unsigned generic 4xx bodies. Never surface or parse them.
            throw new \RuntimeException('duo: cloud origin request was rejected');
        }
        if ($exchange['status'] !== 200) {
            throw new \RuntimeException('duo: cloud origin service returned a non-success status');
        }
        if ($exchange['body'] === '' || strlen($exchange['body']) > self::GENERAL_BODY_LIMIT) {
            throw new \RuntimeException('duo: cloud origin response exceeds its endpoint byte limit');
        }

        $response = $this->verifiedEnvelope($exchange['body']);
        if (($response['format'] ?? null) === self::REFUSAL_FORMAT) {
            $this->throwSignedRefusal($response, (string) $payload['format'], $requestHash);
        }
        self::exactKeys($response, $responseKeys, 'cloud origin response');
        if (($response['format'] ?? null) !== $responseFormat
            || !is_string($response['request_sha256'] ?? null)
            || !hash_equals($requestHash, $response['request_sha256'])) {
            throw new \RuntimeException('duo: cloud origin response does not bind the exact request');
        }
        $validate($response);
        return $response;
    }

    /** @return array<string,mixed> */
    private function verifiedEnvelope(string $bytes): array {
        $envelope = self::canonicalDecodeObject($bytes, self::GENERAL_BODY_LIMIT);
        self::exactKeys($envelope, ['format', 'key_id', 'payload', 'signature'], 'signed response envelope');
        if (($envelope['format'] ?? null) !== self::ENVELOPE_FORMAT
            || ($envelope['key_id'] ?? null) !== $this->serviceKeyId
            || !is_array($envelope['payload'] ?? null)
            || array_is_list($envelope['payload'])) {
            throw new \RuntimeException('duo: cloud origin response envelope identity is invalid');
        }
        $signature = self::canonicalBase64(
            $envelope['signature'] ?? null,
            SODIUM_CRYPTO_SIGN_BYTES,
            'service signature'
        );
        $payloadBytes = self::canonicalEncode($envelope['payload']);
        if (!sodium_crypto_sign_verify_detached(
            $signature,
            $payloadBytes,
            $this->servicePublicKey
        )) {
            throw new \RuntimeException('duo: cloud origin service signature is invalid');
        }
        return $envelope['payload'];
    }

    /** @param array<string,mixed> $payload */
    private function throwSignedRefusal(array $payload, string $requestFormat, string $requestHash): never {
        self::exactKeys(
            $payload,
            ['format', 'reason_code', 'request_format', 'request_sha256', 'retryable'],
            'signed cloud origin refusal'
        );
        if (($payload['format'] ?? null) !== self::REFUSAL_FORMAT
            || ($payload['request_format'] ?? null) !== $requestFormat
            || !is_string($payload['request_sha256'] ?? null)
            || !hash_equals($requestHash, $payload['request_sha256'])
            || !is_string($payload['reason_code'] ?? null)
            || !in_array($payload['reason_code'], self::REFUSAL_CODES, true)
            || !is_bool($payload['retryable'] ?? null)) {
            throw new \RuntimeException('duo: cloud origin HTTP refusal is not an authorized signed refusal');
        }
        throw new OriginCloudRefusal(
            $payload['reason_code'],
            $payload['retryable'],
            $payload['request_format'],
            $payload['request_sha256']
        );
    }

    /** @return array<string,mixed> */
    private function exchange(string $url, string $requestBytes, int $responseLimit): array {
        if ($this->testExchange !== null) {
            $result = ($this->testExchange)($url, $requestBytes, $this->timeoutSeconds, $responseLimit);
            if (!is_array($result) || array_is_list($result)) {
                throw new \RuntimeException('duo: cloud origin test exchange result is malformed');
            }
            return $result;
        }
        return $this->streamExchange($url, $requestBytes, $responseLimit);
    }

    /** @return array<string,mixed> */
    private function streamExchange(string $url, string $requestBytes, int $responseLimit): array {
        $context = stream_context_create([
            'http' => [
                'content' => $requestBytes,
                'follow_location' => 0,
                'header' => "Accept: application/json\r\nContent-Type: application/json\r\nConnection: close\r\n",
                'ignore_errors' => true,
                'max_redirects' => 0,
                'method' => 'POST',
                'protocol_version' => 1.1,
                'timeout' => $this->timeoutSeconds,
            ],
            'ssl' => [
                'allow_self_signed' => false,
                'capture_peer_cert' => false,
                'disable_compression' => true,
                'SNI_enabled' => true,
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $started = microtime(true);
        $handle = @fopen($url, 'rb', false, $context);
        if (!is_resource($handle)) {
            throw new \RuntimeException('duo: cloud origin HTTPS request failed');
        }
        try {
            $body = '';
            while (!feof($handle)) {
                $remaining = $this->timeoutSeconds - (microtime(true) - $started);
                if ($remaining <= 0) {
                    throw new \RuntimeException('duo: cloud origin HTTPS request timed out');
                }
                stream_set_timeout($handle, (int) $remaining, (int) (($remaining - (int) $remaining) * 1000000));
                $chunk = fread($handle, min(8192, $responseLimit + 1 - strlen($body)));
                if (!is_string($chunk)) {
                    throw new \RuntimeException('duo: cloud origin HTTPS response could not be read');
                }
                $body .= $chunk;
                if (strlen($body) > $responseLimit) {
                    throw new \RuntimeException('duo: cloud origin HTTPS response exceeds its byte limit');
                }
                $meta = stream_get_meta_data($handle);
                if (($meta['timed_out'] ?? false) === true) {
                    throw new \RuntimeException('duo: cloud origin HTTPS request timed out');
                }
            }
            $meta = stream_get_meta_data($handle);
        } finally {
            fclose($handle);
        }
        $headers = $meta['wrapper_data'] ?? null;
        if (!is_array($headers) || !isset($headers[0]) || !is_string($headers[0])
            || preg_match('/\AHTTP\/\d(?:\.\d)? ([0-9]{3})(?: |\z)/D', $headers[0], $match) !== 1) {
            throw new \RuntimeException('duo: cloud origin HTTPS response status is malformed');
        }
        $status = (int) $match[1];
        return [
            'body' => $body,
            'effective_url' => $url,
            'redirected' => false,
            'status' => $status,
        ];
    }

    /** @return array<string,mixed> */
    private static function canonicalDecodeObject(string $bytes, int $limit): array {
        if ($bytes === '' || strlen($bytes) > $limit || !str_ends_with($bytes, "\n")) {
            throw new \RuntimeException('duo: cloud origin response is empty or exceeds its canonical limit');
        }
        try {
            $decoded = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\Throwable $error) {
            throw new \RuntimeException('duo: cloud origin response JSON is malformed', 0, $error);
        }
        if (!is_array($decoded) || array_is_list($decoded)
            || self::canonicalEncode($decoded) . "\n" !== $bytes) {
            throw new \RuntimeException('duo: cloud origin response JSON is not canonical');
        }
        return $decoded;
    }

    private static function canonicalEncode(mixed $value): string {
        try {
            return json_encode(
                self::canonicalize($value, 0),
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
        } catch (\RuntimeException $error) {
            throw $error;
        } catch (\Throwable $error) {
            throw new \RuntimeException('duo: cloud origin value cannot be encoded canonically', 0, $error);
        }
    }

    private static function canonicalize(mixed $value, int $depth): mixed {
        if ($depth > 63 || is_float($value) || is_object($value) || is_resource($value)) {
            throw new \RuntimeException('duo: cloud origin canonical JSON contains an unsupported value');
        }
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            foreach (array_keys($value) as $key) {
                if (!is_string($key)) {
                    throw new \RuntimeException('duo: cloud origin canonical object has a non-string key');
                }
            }
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = self::canonicalize($child, $depth + 1);
        }
        return $value;
    }

    private static function baseEndpoint(string $endpoint): string {
        if ($endpoint === '' || strlen($endpoint) > 2048 || str_contains($endpoint, "\0")) {
            throw new \RuntimeException('duo: cloud origin base endpoint is malformed');
        }
        $parts = parse_url($endpoint);
        if (!is_array($parts)) {
            throw new \RuntimeException('duo: cloud origin base endpoint is malformed');
        }
        $expectedKeys = ['host', 'scheme'];
        if (array_key_exists('port', $parts)) {
            $expectedKeys[] = 'port';
        }
        if (array_key_exists('path', $parts)) {
            $expectedKeys[] = 'path';
        }
        self::exactKeys($parts, $expectedKeys, 'cloud origin base endpoint');
        $scheme = $parts['scheme'] ?? null;
        $host = $parts['host'] ?? null;
        $path = $parts['path'] ?? '';
        if (!is_string($scheme) || !is_string($host) || $host === ''
            || ($path !== '' && $path !== '/')
            || (isset($parts['port']) && (!is_int($parts['port'])
                || $parts['port'] < 1 || $parts['port'] > 65535))) {
            throw new \RuntimeException('duo: cloud origin base endpoint is malformed');
        }
        if ($scheme !== 'https') {
            if ($scheme !== 'http' || !self::testMode() || !self::loopbackHost($host)) {
                throw new \RuntimeException('duo: cloud origin endpoint requires verified HTTPS');
            }
        }
        $authority = str_contains($host, ':') ? '[' . $host . ']' : strtolower($host);
        if (isset($parts['port'])) {
            $authority .= ':' . $parts['port'];
        }
        return $scheme . '://' . $authority;
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

    /** @param array<string,mixed> $connector */
    private static function connector(array $connector): void {
        self::exactKeys($connector, [
            'agent_version', 'home_url_sha256', 'installation_id', 'multisite',
            'php_version', 'site_url_sha256', 'wordpress_version',
        ], 'cloud origin connector');
        foreach (['agent_version', 'php_version', 'wordpress_version'] as $key) {
            self::boundedText($connector[$key] ?? null, 1, 64, "connector $key");
        }
        self::sha256($connector['home_url_sha256'] ?? null, 'connector home URL hash');
        self::identifier($connector['installation_id'] ?? null, 'connector installation id');
        if (!is_bool($connector['multisite'] ?? null)) {
            throw new \RuntimeException('duo: cloud origin connector multisite flag is malformed');
        }
        self::sha256($connector['site_url_sha256'] ?? null, 'connector site URL hash');
    }

    /** @param array<string,mixed> $pairing */
    private static function pairingAuthority(
        array $pairing,
        string $serviceKeyId,
        string $servicePublicKeySha256
    ): void {
        self::exactKeys($pairing, [
            'demand_generation', 'origin_generation', 'service_key_id',
            'service_public_key_sha256', 'site_id', 'tenant_id',
        ], 'cloud origin pairing authority');
        self::nonNegativeIntegerValue($pairing['demand_generation'] ?? null, 'paired demand generation');
        self::positiveIntegerValue($pairing['origin_generation'] ?? null, 'paired origin generation');
        self::identifier($pairing['site_id'] ?? null, 'paired site id');
        self::identifier($pairing['tenant_id'] ?? null, 'paired tenant id');
        if (($pairing['service_key_id'] ?? null) !== $serviceKeyId
            || !is_string($pairing['service_public_key_sha256'] ?? null)
            || !hash_equals($servicePublicKeySha256, $pairing['service_public_key_sha256'])) {
            throw new \RuntimeException('duo: cloud origin pairing does not bind the pinned service key');
        }
    }

    /** @param array<string,mixed> $demand */
    private static function demand(
        array $demand,
        int $afterDemandGeneration,
        string $tenantId,
        string $siteId,
        int $originGeneration
    ): void {
        self::exactKeys($demand, [
            'chunk_size', 'demand_generation', 'demand_id', 'expires_at',
            'expected_production_commit', 'format', 'nonce', 'retention_deadline',
            'snapshot_mode',
        ], 'cloud origin export demand');
        if (($demand['format'] ?? null) !== self::DEMAND_FORMAT
            || ($demand['snapshot_mode'] ?? null) !== 'portable-refresh'
            || ($demand['chunk_size'] ?? null) !== self::CHUNK_BYTES) {
            throw new \RuntimeException('duo: cloud origin export demand mode is unsupported');
        }
        self::positiveIntegerValue($demand['demand_generation'] ?? null, 'demand generation');
        if ($demand['demand_generation'] !== $afterDemandGeneration + 1) {
            throw new \RuntimeException('duo: cloud origin demand is not the next generation');
        }
        self::identifier($demand['demand_id'] ?? null, 'demand id');
        self::timestamp($demand['expires_at'] ?? null, 'demand expiry');
        self::timestamp($demand['retention_deadline'] ?? null, 'demand retention deadline');
        if ($demand['retention_deadline'] <= $demand['expires_at']) {
            throw new \RuntimeException('duo: cloud origin demand retention does not outlive its expiry');
        }
        if (!is_string($demand['expected_production_commit'] ?? null)
            || preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D', $demand['expected_production_commit']) !== 1) {
            throw new \RuntimeException('duo: cloud origin expected production commit is malformed');
        }
        self::canonicalBase64($demand['nonce'] ?? null, 32, 'demand nonce');
        $expectedId = self::requestId('duo-cloud-origin-demand/v1', [
            $tenantId,
            $siteId,
            (string) $originGeneration,
            (string) $demand['demand_generation'],
            (string) $demand['nonce'],
            (string) $demand['expected_production_commit'],
        ]);
        if (!hash_equals($expectedId, (string) $demand['demand_id'])) {
            throw new \RuntimeException('duo: cloud origin demand id does not bind its exact authority');
        }
    }

    /** @param array<string,mixed> $manifest */
    private static function manifestReference(array $manifest): string {
        if (array_is_list($manifest)) {
            throw new \RuntimeException('duo: cloud origin manifest must be an object');
        }
        self::exactKeys($manifest, [
            'artifact_hash', 'chunks', 'code_revision', 'expected_production_commit',
            'export_sha256', 'export_size', 'format', 'generation',
            'manifest_sha256', 'repository_revision_hash', 'snapshot_hash',
        ], 'cloud origin manifest');
        if (($manifest['format'] ?? null) !== 'duo-cloud-origin-export-manifest/v1') {
            throw new \RuntimeException('duo: cloud origin manifest format is unsupported');
        }
        foreach ([
            'artifact_hash', 'export_sha256', 'manifest_sha256',
            'repository_revision_hash', 'snapshot_hash',
        ] as $key) {
            self::sha256($manifest[$key] ?? null, "manifest $key");
        }
        if ($manifest['code_revision'] !== null) {
            self::sha256($manifest['code_revision'], 'manifest code revision');
        }
        if (!is_string($manifest['expected_production_commit'] ?? null)
            || preg_match(
                '/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D',
                $manifest['expected_production_commit']
            ) !== 1) {
            throw new \RuntimeException('duo: cloud origin manifest production commit is malformed');
        }
        self::positiveIntegerValue($manifest['generation'] ?? null, 'manifest generation');
        if (!is_int($manifest['export_size'] ?? null)
            || $manifest['export_size'] < 1 || $manifest['export_size'] > 67108864
            || !is_array($manifest['chunks'] ?? null) || !array_is_list($manifest['chunks'])
            || $manifest['chunks'] === []) {
            throw new \RuntimeException('duo: cloud origin manifest artifact bounds are malformed');
        }
        $offset = 0;
        $count = count($manifest['chunks']);
        foreach ($manifest['chunks'] as $position => $chunk) {
            if (!is_array($chunk) || array_is_list($chunk)) {
                throw new \RuntimeException('duo: cloud origin manifest chunk is malformed');
            }
            self::exactKeys($chunk, ['index', 'offset', 'sha256', 'size'], 'cloud origin manifest chunk');
            self::sha256($chunk['sha256'] ?? null, 'manifest chunk hash');
            if (($chunk['index'] ?? null) !== $position || ($chunk['offset'] ?? null) !== $offset
                || !is_int($chunk['size'] ?? null) || $chunk['size'] < 1
                || $chunk['size'] > self::CHUNK_BYTES
                || ($position < $count - 1 && $chunk['size'] !== self::CHUNK_BYTES)) {
                throw new \RuntimeException('duo: cloud origin manifest chunk sequence is malformed');
            }
            $offset += $chunk['size'];
        }
        if ($offset !== $manifest['export_size']) {
            throw new \RuntimeException('duo: cloud origin manifest chunks do not match its export size');
        }
        $claimedHash = $manifest['manifest_sha256'];
        $hashBasis = $manifest;
        unset($hashBasis['manifest_sha256']);
        if (!hash_equals($claimedHash, hash('sha256', Canon::encode($hashBasis)))) {
            throw new \RuntimeException('duo: cloud origin manifest hash does not verify');
        }
        if (strlen(self::canonicalEncode($manifest)) > self::GENERAL_BODY_LIMIT - 4096) {
            throw new \RuntimeException('duo: cloud origin manifest exceeds its request bound');
        }
        return $manifest['manifest_sha256'];
    }

    private static function authorityIdentity(string $tenantId, string $siteId, int $originGeneration): void {
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        self::positiveInteger($originGeneration, 'origin generation');
    }

    private static function exportIdentity(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $demandId,
        string $exportId,
        string $manifestSha256
    ): void {
        self::authorityIdentity($tenantId, $siteId, $originGeneration);
        self::positiveInteger($demandGeneration, 'demand generation');
        self::identifier($demandId, 'demand id');
        self::identifier($exportId, 'export id');
        self::sha256($manifestSha256, 'manifest hash');
    }

    /** @param array<string,mixed> $response */
    private static function matchingAuthority(
        array $response,
        string $tenantId,
        string $siteId,
        int $originGeneration,
        string $originKeyId
    ): void {
        if (($response['tenant_id'] ?? null) !== $tenantId
            || ($response['site_id'] ?? null) !== $siteId
            || ($response['origin_generation'] ?? null) !== $originGeneration
            || ($response['origin_key_id'] ?? null) !== $originKeyId) {
            throw new \RuntimeException('duo: cloud origin response authority does not match the request');
        }
    }

    /** @param array<string,mixed> $response */
    private static function matchingExport(
        array $response,
        string $tenantId,
        string $siteId,
        int $originGeneration,
        string $originKeyId,
        int $demandGeneration,
        string $demandId,
        string $manifestSha256,
        ?string $exportId = null
    ): void {
        self::matchingAuthority($response, $tenantId, $siteId, $originGeneration, $originKeyId);
        if (($response['demand_generation'] ?? null) !== $demandGeneration
            || ($response['demand_id'] ?? null) !== $demandId
            || ($response['manifest_sha256'] ?? null) !== $manifestSha256
            || ($exportId !== null && ($response['export_id'] ?? null) !== $exportId)) {
            throw new \RuntimeException('duo: cloud origin export response identity does not match');
        }
    }

    /** @param list<mixed> $hashes */
    private static function sortedUniqueHashes(array $hashes, string $label): void {
        $prior = null;
        foreach ($hashes as $hash) {
            self::sha256($hash, $label);
            if ($prior !== null && strcmp($prior, $hash) >= 0) {
                throw new \RuntimeException("duo: cloud origin $label must be sorted and unique");
            }
            $prior = $hash;
        }
    }

    /** @param list<string> $parts */
    private static function requestId(string $domain, array $parts): string {
        return hash('sha256', implode("\0", array_merge([$domain], $parts)));
    }

    private static function identifier(mixed $value, string $label): string {
        if (!is_string($value)
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value) !== 1) {
            throw new \RuntimeException("duo: cloud origin $label is malformed");
        }
        return $value;
    }

    private static function hexId(mixed $value, int $length, string $label): string {
        if (!is_string($value)
            || strlen($value) !== $length
            || preg_match('/\A[a-f0-9]+\z/D', $value) !== 1) {
            throw new \RuntimeException("duo: cloud origin $label is malformed");
        }
        return $value;
    }

    private static function sha256(mixed $value, string $label): string {
        return self::hexId($value, 64, $label);
    }

    private static function deviceCode(string $code): void {
        if (preg_match('/\A[A-Z2-7]{5}(?:-[A-Z2-7]{5}){3}\z/D', $code) !== 1) {
            throw new \RuntimeException('duo: cloud origin device code is malformed');
        }
    }

    private static function boundedText(mixed $value, int $minimum, int $maximum, string $label): string {
        if (!is_string($value) || strlen($value) < $minimum || strlen($value) > $maximum
            || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) {
            throw new \RuntimeException("duo: cloud origin $label is malformed");
        }
        return $value;
    }

    private static function timestamp(mixed $value, string $label): int {
        if (!is_int($value) || $value < 1 || $value > self::MAX_SAFE_INTEGER) {
            throw new \RuntimeException("duo: cloud origin $label is malformed");
        }
        return $value;
    }

    private static function pollAfter(mixed $value, bool $mustWait): int {
        if (!is_int($value) || $value < ($mustWait ? 1 : 0) || $value > 300) {
            throw new \RuntimeException('duo: cloud origin poll interval is malformed');
        }
        return $value;
    }

    private static function positiveInteger(int $value, string $label): int {
        if ($value < 1 || $value > self::MAX_SAFE_INTEGER) {
            throw new \RuntimeException("duo: cloud origin $label is out of range");
        }
        return $value;
    }

    private static function nonNegativeInteger(int $value, string $label): int {
        if ($value < 0 || $value > self::MAX_SAFE_INTEGER) {
            throw new \RuntimeException("duo: cloud origin $label is out of range");
        }
        return $value;
    }

    private static function positiveIntegerValue(mixed $value, string $label): int {
        if (!is_int($value)) {
            throw new \RuntimeException("duo: cloud origin $label is malformed");
        }
        return self::positiveInteger($value, $label);
    }

    private static function nonNegativeIntegerValue(mixed $value, string $label): int {
        if (!is_int($value)) {
            throw new \RuntimeException("duo: cloud origin $label is malformed");
        }
        return self::nonNegativeInteger($value, $label);
    }

    private static function canonicalBase64(mixed $value, int $bytes, string $label): string {
        if (!is_string($value)) {
            throw new \RuntimeException("duo: cloud origin $label is malformed");
        }
        $decoded = base64_decode($value, true);
        if (!is_string($decoded) || strlen($decoded) !== $bytes || base64_encode($decoded) !== $value) {
            throw new \RuntimeException("duo: cloud origin $label is not canonical base64");
        }
        return $decoded;
    }

    /** @param array<string,mixed> $value @param list<string> $expected */
    private static function exactKeys(array $value, array $expected, string $label): void {
        $actual = array_keys($value);
        sort($actual, SORT_STRING);
        sort($expected, SORT_STRING);
        if ($actual !== $expected) {
            throw new \RuntimeException("duo: $label has unexpected fields");
        }
    }

    private static function assertSodium(): void {
        if (!function_exists('sodium_crypto_sign_detached')
            || !defined('SODIUM_CRYPTO_SIGN_SECRETKEYBYTES')) {
            throw new \RuntimeException('duo: cloud origin requires the sodium Ed25519 extension');
        }
    }

    private static function testMode(): bool {
        return getenv('DUO_TEST_MODE') === '1';
    }
}
