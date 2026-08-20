<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/CanonicalJson.php';
require_once __DIR__ . '/ControlAuthority.php';
require_once __DIR__ . '/FileAuthorityStore.php';
require_once __DIR__ . '/OriginAuthority.php';

/**
 * Closed mutually-authenticated controller boundary for portable exports.
 *
 * The action vocabulary contains no path, callback, URL, command, or free-form
 * operation. Controller identity comes from ControlAuthority; origin pairing,
 * demand generations, immutable objects, and tenant isolation remain owned by
 * OriginAuthority. Small mutable responses are journaled so an exact poll
 * sequence or demand creation replay returns the original signed bytes.
 */
final class OriginControllerGateway {
    public const PATH = '/v1/origin/controller/export';
    public const ENVELOPE_FORMAT = 'duo-cloud-origin-controller-signed-envelope/v1';
    public const REQUEST_FORMAT = 'duo-cloud-origin-controller-request/v1';
    public const RESPONSE_FORMAT = 'duo-cloud-origin-controller-response/v1';

    private const STORE_FORMAT = 'duo-cloud-origin-controller-receipts/v2';
    private const REQUEST_LIMIT = 1048576;
    private const RESPONSE_LIMIT = 2097152;
    private const GENERATIONS_PER_SITE_LIMIT = 2;
    private const RECEIPTS_PER_GENERATION_LIMIT = 256;
    private const RECEIPTS_PER_GENERATION_BYTES_LIMIT = 4194304;
    private const MAX_CACHED_RESPONSE_BASE64_BYTES = 2796204;

    private string $responseSecretKey;
    /** @var \Closure():int */
    private \Closure $clock;

    public function __construct(
        private OriginAuthority $origin,
        private ControlAuthority $controllerKeys,
        private FileAuthorityStore $receipts,
        private string $responseKeyId,
        string $responseSecretKey,
        ?callable $clock = null
    ) {
        self::identifier($responseKeyId, 'origin controller response key id');
        if (strlen($responseSecretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new ControlRefusal('origin controller response key is not Ed25519');
        }
        $this->responseSecretKey = $responseSecretKey;
        $this->clock = $clock === null
            ? static fn (): int => time()
            : \Closure::fromCallable($clock);
        $this->now();
    }

    public function __destruct() {
        if ($this->responseSecretKey !== '') {
            sodium_memzero($this->responseSecretKey);
        }
    }

    public function handle(string $requestBytes): string {
        $envelope = CanonicalJson::decodeObject($requestBytes, self::REQUEST_LIMIT);
        self::exactKeys(
            $envelope,
            ['format', 'key_id', 'payload', 'signature'],
            'origin controller envelope'
        );
        if (($envelope['format'] ?? null) !== self::ENVELOPE_FORMAT
            || !is_array($envelope['payload'] ?? null) || array_is_list($envelope['payload'])) {
            throw new ControlRefusal('origin controller envelope is malformed');
        }
        $keyId = self::identifier($envelope['key_id'] ?? null, 'origin controller request key id');
        $payload = $envelope['payload'];
        self::payload($payload);
        $payloadBytes = CanonicalJson::encode($payload);
        $signature = self::signature($envelope['signature'] ?? null);
        $this->controllerKeys->authorizeRequestKey(
            $keyId,
            $payload['tenant_id'],
            $payload['site_id'],
            $payloadBytes,
            $signature
        );
        $expectedRequestId = self::requestId(
            $keyId,
            $payload['tenant_id'],
            $payload['site_id'],
            $payload['operation_id'],
            $payload['action'],
            $payload['input']
        );
        if (!hash_equals($expectedRequestId, $payload['request_id'])) {
            throw new ControlRefusal('origin controller request id does not bind its exact input');
        }
        if (in_array($payload['action'], ['demand-create', 'demand-status'], true)) {
            return $this->replayedOrExecute($keyId, $payload, $payloadBytes);
        }
        return $this->signedResponse($payload, $payloadBytes, $this->execute($payload));
    }

    /**
     * @param array<string,mixed> $payload
     */
    private function replayedOrExecute(string $keyId, array $payload, string $payloadBytes): string {
        return $this->receipts->locked(function (AuthorityStateSession $session) use (
            $keyId,
            $payload,
            $payloadBytes
        ): string {
            $state = self::receiptState($session->state());
            $requestId = $payload['request_id'];
            $siteKey = self::siteKey($payload['tenant_id'], $payload['site_id']);
            $site = $state['sites'][$siteKey] ?? self::emptyReceiptSite(
                $payload['tenant_id'],
                $payload['site_id']
            );
            $compacted = self::compactReceiptSite($site, $this->now());
            $existing = self::findReceipt($site, $requestId);
            if ($existing !== null) {
                $receipt = self::receipt($existing, $requestId);
                if ($receipt['key_id'] !== $keyId
                    || !hash_equals($receipt['request_sha256'], hash('sha256', $payloadBytes))) {
                    throw new ControlRefusal('origin controller request id was replayed with changed bytes');
                }
                if ($compacted) {
                    $state['sites'][$siteKey] = $site;
                    $session->save($state);
                }
                return self::cachedResponse($receipt);
            }

            $generation = null;
            if ($payload['action'] === 'demand-status') {
                $generation = self::receiptGeneration(
                    $site,
                    $payload['input']['demand_generation'],
                    $payload['input']['demand_id']
                );
                self::assertReceiptCapacity($generation);
            }
            $result = $this->execute($payload);
            $response = $this->signedResponse($payload, $payloadBytes, $result);
            if ($payload['action'] === 'demand-create') {
                $demand = $result['demand'] ?? null;
                if (!is_array($demand) || array_is_list($demand)) {
                    throw new ControlRefusal('origin controller demand result is malformed');
                }
                $demandGeneration = self::positive(
                    $demand['demand_generation'] ?? null,
                    'origin controller demand generation'
                );
                $demandId = self::sha256(
                    $demand['demand_id'] ?? null,
                    'origin controller demand id'
                );
                $retentionDeadline = self::timestamp(
                    $demand['retention_deadline'] ?? null,
                    'origin controller demand retention deadline'
                );
                foreach ($site['generations'] as &$prior) {
                    $prior['state'] = 'terminal';
                }
                unset($prior);
                $generationKey = self::generationKey($demandGeneration);
                $existingGeneration = $site['generations'][$generationKey] ?? null;
                if ($existingGeneration !== null) {
                    $generation = self::generationRecord($existingGeneration, $demandGeneration);
                    if ($generation['demand_id'] !== $demandId
                        || $generation['retention_deadline'] !== $retentionDeadline) {
                        throw new ControlRefusal('origin controller demand generation changed identity');
                    }
                    $generation['state'] = 'active';
                } else {
                    $generation = [
                        'demand_id' => $demandId,
                        'receipts' => [],
                        'retention_deadline' => $retentionDeadline,
                        'state' => 'active',
                    ];
                }
                $site['current_generation'] = $demandGeneration;
            } else {
                if (!is_array($generation)) {
                    throw new ControlRefusal('origin controller receipt generation is unavailable');
                }
                $demandGeneration = $payload['input']['demand_generation'];
                $generationKey = self::generationKey($demandGeneration);
                $status = $result['status']['state'] ?? null;
                if (in_array($status, ['committed', 'expired', 'revoked'], true)) {
                    $generation['state'] = 'terminal';
                }
            }
            $generation['receipts'][$requestId] = [
                'action' => $payload['action'],
                'key_id' => $keyId,
                'request_id' => $requestId,
                'request_sha256' => hash('sha256', $payloadBytes),
                'response_base64' => base64_encode($response),
                'response_sha256' => hash('sha256', $response),
            ];
            self::assertGenerationBounds($generation);
            $site['generations'][$generationKey] = $generation;
            self::compactReceiptSite($site, $this->now());
            self::assertReceiptSite($siteKey, $site);
            $state['sites'][$siteKey] = $site;
            $session->save($state);
            return $response;
        });
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function execute(array $payload): array {
        $input = $payload['input'];
        return match ($payload['action']) {
            'demand-create' => [
                'demand' => $this->origin->requestPortableDemand(
                    $payload['tenant_id'],
                    $payload['site_id'],
                    $payload['request_id'],
                    $input['expected_production_commit']
                ),
            ],
            'demand-status' => [
                'poll_sequence' => $input['poll_sequence'],
                'status' => $this->origin->readPortableDemandStatus(
                    $payload['tenant_id'],
                    $payload['site_id'],
                    $input['demand_generation'],
                    $input['demand_id'],
                    $input['expected_production_commit']
                ),
            ],
            'manifest-read' => [
                'export_id' => $input['export_id'],
                'manifest' => $this->origin->readPortableManifest(
                    $payload['tenant_id'],
                    $payload['site_id'],
                    $input['demand_generation'],
                    $input['demand_id'],
                    $input['expected_production_commit'],
                    $input['export_id']
                ),
            ],
            'chunk-read' => $this->chunkResult($payload, $input),
            default => throw new ControlRefusal('origin controller action escaped its closed set'),
        };
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    private function chunkResult(array $payload, array $input): array {
        $bytes = $this->origin->readPortableChunk(
            $payload['tenant_id'],
            $payload['site_id'],
            $input['demand_generation'],
            $input['demand_id'],
            $input['expected_production_commit'],
            $input['export_id'],
            $input['manifest_sha256'],
            $input['chunk_index']
        );
        return [
            'chunk_base64' => base64_encode($bytes),
            'chunk_index' => $input['chunk_index'],
            'chunk_sha256' => hash('sha256', $bytes),
            'chunk_size' => strlen($bytes),
            'export_id' => $input['export_id'],
            'manifest_sha256' => $input['manifest_sha256'],
        ];
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $result
     */
    private function signedResponse(array $request, string $payloadBytes, array $result): string {
        $payload = [
            'action' => $request['action'],
            'format' => self::RESPONSE_FORMAT,
            'operation_id' => $request['operation_id'],
            'request_sha256' => hash('sha256', $payloadBytes),
            'result' => $result,
            'site_id' => $request['site_id'],
            'status' => 'ok',
            'tenant_id' => $request['tenant_id'],
        ];
        $envelope = [
            'format' => self::ENVELOPE_FORMAT,
            'key_id' => $this->responseKeyId,
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                CanonicalJson::encode($payload),
                $this->responseSecretKey
            )),
        ];
        $bytes = CanonicalJson::encode($envelope) . "\n";
        if (strlen($bytes) > self::RESPONSE_LIMIT) {
            throw new ControlRefusal('origin controller response exceeds its byte limit');
        }
        return $bytes;
    }

    /** @param array<string,mixed> $payload */
    private static function payload(array $payload): void {
        self::exactKeys($payload, [
            'action', 'format', 'input', 'operation_id', 'request_id', 'site_id', 'tenant_id',
        ], 'origin controller request');
        $action = $payload['action'] ?? null;
        if (($payload['format'] ?? null) !== self::REQUEST_FORMAT
            || !in_array($action, ['chunk-read', 'demand-create', 'demand-status', 'manifest-read'], true)
            || !is_array($payload['input'] ?? null) || array_is_list($payload['input'])) {
            throw new ControlRefusal('origin controller request is malformed');
        }
        self::identifier($payload['tenant_id'] ?? null, 'origin controller tenant id');
        self::identifier($payload['site_id'] ?? null, 'origin controller site id');
        self::identifier($payload['operation_id'] ?? null, 'origin controller operation id');
        self::sha256($payload['request_id'] ?? null, 'origin controller request id');
        self::input((string) $action, $payload['input']);
    }

    /** @param array<string,mixed> $input */
    private static function input(string $action, array $input): void {
        $keys = match ($action) {
            'demand-create' => ['expected_production_commit'],
            'demand-status' => [
                'demand_generation', 'demand_id', 'expected_production_commit', 'poll_sequence',
            ],
            'manifest-read' => [
                'demand_generation', 'demand_id', 'expected_production_commit', 'export_id',
            ],
            'chunk-read' => [
                'chunk_index', 'demand_generation', 'demand_id', 'expected_production_commit',
                'export_id', 'manifest_sha256',
            ],
            default => throw new ControlRefusal('origin controller input action is invalid'),
        };
        self::exactKeys($input, $keys, 'origin controller action input');
        self::commit($input['expected_production_commit'] ?? null);
        if ($action !== 'demand-create') {
            self::positive($input['demand_generation'] ?? null, 'origin controller demand generation');
            self::sha256($input['demand_id'] ?? null, 'origin controller demand id');
        }
        if ($action === 'demand-status') {
            self::nonNegative($input['poll_sequence'] ?? null, 'origin controller poll sequence');
        }
        if (in_array($action, ['manifest-read', 'chunk-read'], true)) {
            self::sha256($input['export_id'] ?? null, 'origin controller export id');
        }
        if ($action === 'chunk-read') {
            self::sha256($input['manifest_sha256'] ?? null, 'origin controller manifest hash');
            self::nonNegative($input['chunk_index'] ?? null, 'origin controller chunk index');
        }
    }

    /** @param array<string,mixed> $input */
    public static function requestId(
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
                . "$operationId\0$action\0" . hash('sha256', CanonicalJson::encode($input))
        );
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function receiptState(array $state): array {
        if ($state === []) {
            return ['format' => self::STORE_FORMAT, 'sites' => []];
        }
        self::exactKeys($state, ['format', 'sites'], 'origin controller receipt store');
        if (($state['format'] ?? null) !== self::STORE_FORMAT
            || !is_array($state['sites'] ?? null)
            || (array_is_list($state['sites']) && $state['sites'] !== [])) {
            throw new ControlRefusal('origin controller receipt store is corrupt');
        }
        foreach ($state['sites'] as $siteKey => $site) {
            self::assertReceiptSite($siteKey, $site);
        }
        return $state;
    }

    /** @return array<string,mixed> */
    private static function emptyReceiptSite(string $tenantId, string $siteId): array {
        return [
            'current_generation' => 0,
            'generations' => [],
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ];
    }

    private static function assertReceiptSite(mixed $siteKey, mixed $site): void {
        if (!is_string($siteKey) || !is_array($site) || array_is_list($site)) {
            throw new ControlRefusal('origin controller receipt site is corrupt');
        }
        self::exactKeys(
            $site,
            ['current_generation', 'generations', 'site_id', 'tenant_id'],
            'origin controller receipt site'
        );
        $tenantId = self::identifier($site['tenant_id'] ?? null, 'origin controller receipt tenant id');
        $siteId = self::identifier($site['site_id'] ?? null, 'origin controller receipt site id');
        $currentGeneration = self::nonNegative(
            $site['current_generation'] ?? null,
            'origin controller current demand generation'
        );
        if ($siteKey !== self::siteKey($tenantId, $siteId)
            || !is_array($site['generations'] ?? null)
            || (array_is_list($site['generations']) && $site['generations'] !== [])
            || count($site['generations']) > self::GENERATIONS_PER_SITE_LIMIT) {
            throw new ControlRefusal('origin controller receipt site is corrupt');
        }
        foreach ($site['generations'] as $generationKey => $generation) {
            if (!is_string($generationKey)) {
                throw new ControlRefusal('origin controller receipt generation map is corrupt');
            }
            $number = self::generationNumber($generationKey);
            $record = self::generationRecord($generation, $number);
            if ($record['state'] === 'active' && $number !== $currentGeneration) {
                throw new ControlRefusal('origin controller active receipt generation is not current');
            }
        }
        if (($site['generations'] === [] && $currentGeneration !== 0)
            || ($site['generations'] !== []
                && !isset($site['generations'][self::generationKey($currentGeneration)]))) {
            throw new ControlRefusal('origin controller current receipt generation is unavailable');
        }
    }

    /** @return array<string,mixed> */
    private static function generationRecord(mixed $generation, int $number): array {
        if (!is_array($generation) || array_is_list($generation)) {
            throw new ControlRefusal('origin controller receipt generation is corrupt');
        }
        self::exactKeys(
            $generation,
            ['demand_id', 'receipts', 'retention_deadline', 'state'],
            'origin controller receipt generation'
        );
        self::positive($number, 'origin controller receipt generation');
        self::sha256($generation['demand_id'] ?? null, 'origin controller receipt demand id');
        self::timestamp(
            $generation['retention_deadline'] ?? null,
            'origin controller receipt retention deadline'
        );
        if (!in_array($generation['state'] ?? null, ['active', 'terminal'], true)
            || !is_array($generation['receipts'] ?? null)
            || (array_is_list($generation['receipts']) && $generation['receipts'] !== [])) {
            throw new ControlRefusal('origin controller receipt generation is corrupt');
        }
        foreach ($generation['receipts'] as $requestId => $receipt) {
            if (!is_string($requestId)) {
                throw new ControlRefusal('origin controller receipt map is corrupt');
            }
            self::receipt($receipt, $requestId);
        }
        self::assertGenerationBounds($generation);
        return $generation;
    }

    /** @param array<string,mixed> $site */
    private static function compactReceiptSite(array &$site, int $now): bool {
        $changed = false;
        $currentGeneration = $site['current_generation'];
        $keys = array_keys($site['generations']);
        usort(
            $keys,
            static fn (string $left, string $right): int => self::generationNumber($right)
                <=> self::generationNumber($left)
        );
        $terminalKept = false;
        foreach ($keys as $generationKey) {
            $number = self::generationNumber($generationKey);
            $generation = self::generationRecord($site['generations'][$generationKey], $number);
            $keep = false;
            if ($number === $currentGeneration) {
                $keep = $generation['state'] === 'active' || $generation['retention_deadline'] > $now;
            } elseif ($number < $currentGeneration && !$terminalKept
                && $generation['retention_deadline'] > $now) {
                $generation['state'] = 'terminal';
                $site['generations'][$generationKey] = $generation;
                $terminalKept = true;
                $keep = true;
            }
            if (!$keep) {
                unset($site['generations'][$generationKey]);
                $changed = true;
            }
        }
        if ($site['generations'] === []) {
            if ($site['current_generation'] !== 0) {
                $site['current_generation'] = 0;
                $changed = true;
            }
        } elseif (!isset($site['generations'][self::generationKey($site['current_generation'])])) {
            $remaining = array_map(self::generationNumber(...), array_keys($site['generations']));
            $site['current_generation'] = max($remaining);
            $changed = true;
        }
        return $changed;
    }

    /** @param array<string,mixed> $site @return ?array<string,mixed> */
    private static function findReceipt(array $site, string $requestId): ?array {
        foreach ($site['generations'] as $generation) {
            if (is_array($generation) && isset($generation['receipts'][$requestId])) {
                $receipt = $generation['receipts'][$requestId];
                return is_array($receipt) ? $receipt : null;
            }
        }
        return null;
    }

    /** @param array<string,mixed> $site @return array<string,mixed> */
    private static function receiptGeneration(array $site, int $number, string $demandId): array {
        $generation = self::generationRecord(
            $site['generations'][self::generationKey($number)] ?? null,
            $number
        );
        if (!hash_equals($generation['demand_id'], $demandId)) {
            throw new ControlRefusal('origin controller receipt generation changed identity');
        }
        return $generation;
    }

    /** @param array<string,mixed> $generation */
    private static function assertReceiptCapacity(array $generation): void {
        self::assertGenerationBounds($generation);
        if (count($generation['receipts']) >= self::RECEIPTS_PER_GENERATION_LIMIT) {
            throw new ControlRefusal('origin controller receipt count reached its per-generation limit');
        }
        if (strlen(CanonicalJson::encode($generation['receipts']))
            + self::MAX_CACHED_RESPONSE_BASE64_BYTES + 512
            > self::RECEIPTS_PER_GENERATION_BYTES_LIMIT) {
            throw new ControlRefusal(
                'origin controller has insufficient receipt bytes for the maximum response'
            );
        }
    }

    /** @param array<string,mixed> $generation */
    private static function assertGenerationBounds(array $generation): void {
        if (count($generation['receipts']) > self::RECEIPTS_PER_GENERATION_LIMIT) {
            throw new ControlRefusal('origin controller receipt count exceeds its per-generation limit');
        }
        if (strlen(CanonicalJson::encode($generation['receipts']))
            > self::RECEIPTS_PER_GENERATION_BYTES_LIMIT) {
            throw new ControlRefusal('origin controller receipt bytes exceed its per-generation limit');
        }
    }

    /** @return array<string,mixed> */
    private static function receipt(mixed $receipt, string $requestId): array {
        if (!is_array($receipt) || array_is_list($receipt)) {
            throw new ControlRefusal('origin controller receipt is corrupt');
        }
        self::exactKeys($receipt, [
            'action', 'key_id', 'request_id', 'request_sha256', 'response_base64', 'response_sha256',
        ], 'origin controller receipt');
        if (($receipt['request_id'] ?? null) !== $requestId) {
            throw new ControlRefusal('origin controller receipt identity is corrupt');
        }
        self::identifier($receipt['key_id'] ?? null, 'origin controller receipt key id');
        if (!in_array($receipt['action'] ?? null, ['demand-create', 'demand-status'], true)) {
            throw new ControlRefusal('origin controller receipt action is corrupt');
        }
        self::sha256($requestId, 'origin controller receipt request id');
        self::sha256($receipt['request_sha256'] ?? null, 'origin controller receipt request hash');
        self::sha256($receipt['response_sha256'] ?? null, 'origin controller receipt response hash');
        self::cachedResponse($receipt);
        return $receipt;
    }

    /** @param array<string,mixed> $receipt */
    private static function cachedResponse(array $receipt): string {
        $encoded = $receipt['response_base64'] ?? null;
        $response = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_string($response) || base64_encode($response) !== $encoded
            || !hash_equals((string) $receipt['response_sha256'], hash('sha256', $response))) {
            throw new ControlRefusal('origin controller cached response is corrupt');
        }
        CanonicalJson::decodeObject($response, self::RESPONSE_LIMIT);
        return $response;
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

    private static function siteKey(string $tenantId, string $siteId): string {
        return hash('sha256', "duo-cloud-origin-controller-site/v1\0$tenantId\0$siteId");
    }

    private static function generationKey(int $generation): string {
        self::positive($generation, 'origin controller receipt generation');
        return 'g-' . $generation;
    }

    private static function generationNumber(string $key): int {
        if (preg_match('/\Ag-([1-9][0-9]*)\z/D', $key, $matches) !== 1) {
            throw new ControlRefusal('origin controller receipt generation map is corrupt');
        }
        $number = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!is_int($number)) {
            throw new ControlRefusal('origin controller receipt generation map is corrupt');
        }
        return $number;
    }

    private static function sha256(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{64}\z/D', $value) !== 1) {
            throw new ControlRefusal("$label is not lowercase SHA-256");
        }
        return $value;
    }

    private static function commit(mixed $value): string {
        if (!is_string($value) || preg_match('/\A[a-f0-9]{40}(?:[a-f0-9]{24})?\z/D', $value) !== 1) {
            throw new ControlRefusal('origin controller expected production commit is invalid');
        }
        return $value;
    }

    private static function positive(mixed $value, string $label): int {
        if (!is_int($value) || $value < 1) {
            throw new ControlRefusal("$label is invalid");
        }
        return $value;
    }

    private static function nonNegative(mixed $value, string $label): int {
        if (!is_int($value) || $value < 0) {
            throw new ControlRefusal("$label is invalid");
        }
        return $value;
    }

    private static function timestamp(mixed $value, string $label): int {
        if (!is_int($value) || $value < 1 || $value > 9007199254740991) {
            throw new ControlRefusal("$label is invalid");
        }
        return $value;
    }

    private function now(): int {
        return self::timestamp(($this->clock)(), 'origin controller clock');
    }

    private static function signature(mixed $value): string {
        $decoded = is_string($value) ? base64_decode($value, true) : false;
        if (!is_string($decoded) || strlen($decoded) !== SODIUM_CRYPTO_SIGN_BYTES
            || base64_encode($decoded) !== $value) {
            throw new ControlRefusal('origin controller signature is invalid');
        }
        return $decoded;
    }
}
