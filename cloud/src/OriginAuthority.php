<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/ControlRefusal.php';
require_once __DIR__ . '/CanonicalJson.php';
require_once __DIR__ . '/FileAuthorityStore.php';
require_once __DIR__ . '/OriginBlobStore.php';
require_once __DIR__ . '/OriginAgentCanon.php';
require_once __DIR__ . '/OriginControllerReader.php';
require_once __DIR__ . '/OriginProtocol.php';
require_once __DIR__ . '/OriginStateRefusal.php';

/**
 * Closed service-side authority for paired production-origin exports.
 *
 * The FileAuthorityStore is the authority journal only. Chunk request bodies
 * and assembled artifacts live exclusively behind OriginBlobStore; the
 * journal records an exact request digest before any blob write/publication.
 */
final class OriginAuthority implements OriginControllerReader {
    private const STORE_FORMAT = 'duo-cloud-origin-authority-store/v1';
    private const REQUEST_LIMIT = 2097152;
    private const RESPONSE_LIMIT = 2097152;
    private const CHUNK_SIZE = 1048576;
    private const MAX_EXPORT_SIZE = 67108864;
    private const RETAINED_EXPORTS_PER_SITE_LIMIT = 8;
    private const RETAINED_EXPORT_BYTES_PER_SITE_LIMIT = 1073741824;
    private const RETAINED_EXPORT_OVERHEAD_BYTES = 1048576;
    private const POLL_AFTER_SECONDS = 2;
    private const PORTABLE_DEMAND_TTL_SECONDS = 600;
    private const PORTABLE_RETENTION_SECONDS = 86400;
    private const CONTROLLER_DEMANDS_PER_GENERATION_LIMIT = 2;
    private const CONTROLLER_DEMANDS_PER_GENERATION_BYTES_LIMIT = 65536;
    private const ENDPOINT_RECEIPTS_PER_GENERATION_LIMIT = 512;
    private const ENDPOINT_RECEIPTS_PER_GENERATION_BYTES_LIMIT = 8388608;
    private const MAX_CACHED_RESPONSE_BASE64_BYTES = 2796204;
    private const DEVICE_CODES_PER_SITE_LIMIT = 8;
    private const PAIRINGS_PER_SITE_LIMIT = 8;
    private const ORIGIN_KEYS_PER_SITE_LIMIT = 10;
    private const TERMINAL_PAIRING_RETENTION_SECONDS = 86400;

    private FileAuthorityStore $store;
    private OriginBlobStore $blobs;
    private string $serviceKeyId;
    private string $serviceSecretKey;
    private string $deviceDigestKey;
    /** @var \Closure():int */
    private \Closure $clock;

    /** @param ?callable():int $clock */
    public function __construct(
        FileAuthorityStore $store,
        OriginBlobStore $blobs,
        string $serviceKeyId,
        string $serviceSecretKey,
        string $deviceDigestKey,
        ?callable $clock = null
    ) {
        self::identifier($serviceKeyId, 'service key id');
        if (strlen($serviceSecretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new ControlRefusal('service signing key is not an Ed25519 secret key');
        }
        if (strlen($deviceDigestKey) < 32) {
            throw new ControlRefusal('device-code digest key must contain at least 32 bytes');
        }
        $this->store = $store;
        $this->blobs = $blobs;
        $this->serviceKeyId = $serviceKeyId;
        $this->serviceSecretKey = $serviceSecretKey;
        $this->deviceDigestKey = $deviceDigestKey;
        $this->clock = $clock === null
            ? static fn (): int => time()
            : \Closure::fromCallable($clock);
        $this->now();
    }

    public function __destruct() {
        if ($this->serviceSecretKey !== '') {
            sodium_memzero($this->serviceSecretKey);
        }
        if ($this->deviceDigestKey !== '') {
            sodium_memzero($this->deviceDigestKey);
        }
    }

    /**
     * Mint one user-facing code. Only its HMAC digest crosses the state-store
     * boundary; the returned plaintext has no second read API.
     *
     * @return array{device_code:string,expires_at:int}
     */
    public function issueDeviceCode(string $tenantId, string $siteId, int $ttlSeconds = 600): array {
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        if ($ttlSeconds < 1 || $ttlSeconds > 600) {
            throw new ControlRefusal('device-code lifetime must be between one and 600 seconds');
        }
        $expiresAt = $this->now() + $ttlSeconds;
        for ($attempt = 0; $attempt < 8; $attempt++) {
            $code = self::randomDeviceCode();
            $digest = $this->deviceCodeDigest($code);
            $inserted = $this->store->locked(function (AuthorityStateSession $session) use (
                $digest,
                $tenantId,
                $siteId,
                $expiresAt
            ): bool {
                $state = self::state($session->state());
                $compacted = self::compactRetainedState($state, $this->now());
                if (isset($state['device_codes'][$digest])) {
                    if ($compacted) {
                        $session->save($state);
                    }
                    return false;
                }
                try {
                    self::assertNewDeviceCodeCapacity($state['device_codes'], $tenantId, $siteId);
                } catch (ControlRefusal $error) {
                    if ($compacted) {
                        $session->save($state);
                    }
                    throw $error;
                }
                $state['device_codes'][$digest] = [
                    'consumed_pairing_id' => null,
                    'digest' => $digest,
                    'expires_at' => $expiresAt,
                    'site_id' => $siteId,
                    'state' => 'issued',
                    'tenant_id' => $tenantId,
                ];
                $session->save($state);
                return true;
            });
            if ($inserted) {
                return ['device_code' => $code, 'expires_at' => $expiresAt];
            }
        }
        throw new ControlRefusal('device code could not be minted without a collision');
    }

    /** Mark a still-pending pairing denied through the authenticated service plane. */
    public function denyPairing(string $pairingId): void {
        self::sha256($pairingId, 'pairing id');
        $this->store->locked(function (AuthorityStateSession $session) use ($pairingId): void {
            $state = self::state($session->state());
            $compacted = self::compactRetainedState($state, $this->now());
            $pairing = self::pairingRecord($state['pairings'][$pairingId] ?? null, $pairingId);
            if ($pairing['state'] === 'denied') {
                if ($compacted) {
                    $session->save($state);
                }
                return;
            }
            if ($pairing['state'] !== 'pending') {
                throw new ControlRefusal('only a pending origin pairing can be denied');
            }
            $pairing['state'] = 'denied';
            $state['pairings'][$pairingId] = $pairing;
            $key = self::keyRecord($state['keys'][$pairing['origin_key_id']] ?? null, $pairing['origin_key_id']);
            if ($key['state'] === 'pending') {
                $key['state'] = 'expired';
                $state['keys'][$key['key_id']] = $key;
            }
            $siteKey = self::siteKey($pairing['tenant_id'], $pairing['site_id']);
            $site = $state['sites'][$siteKey] ?? null;
            if (is_array($site) && ($site['pending_pairing_id'] ?? null) === $pairingId) {
                $site['pending_pairing_id'] = null;
                $state['sites'][$siteKey] = $site;
            }
            $session->save($state);
        });
    }

    /**
     * Publish exactly one next-generation demand from the trusted service
     * plane. The supplied object is already the closed wire demand.
     *
     * @param array<string,mixed> $demand
     */
    public function publishDemand(string $tenantId, string $siteId, array $demand): void {
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        self::demand($demand);
        $this->store->locked(function (AuthorityStateSession $session) use (
            $tenantId,
            $siteId,
            $demand
        ): void {
            $state = self::state($session->state());
            self::compactRetainedState($state, $this->now());
            $siteKey = self::siteKey($tenantId, $siteId);
            $site = self::siteRecord($state['sites'][$siteKey] ?? null, $tenantId, $siteId);
            if ($site['state'] !== 'active') {
                throw new ControlRefusal('origin site is not active for demand publication');
            }
            $current = $site['demand'];
            if (is_array($current) && is_array($current['demand'] ?? null)
                && CanonicalJson::encode($current['demand']) === CanonicalJson::encode($demand)) {
                return;
            }
            if (is_array($current) && ($current['state'] ?? null) === 'active') {
                if ((int) ($current['demand']['expires_at'] ?? 0) > $this->now()) {
                    throw new ControlRefusal('origin site already has an active export demand');
                }
                $current['state'] = 'expired';
                $site['demand'] = $current;
                $site['last_terminal_generation'] = (int) $current['demand']['demand_generation'];
            }
            self::assertNewDemandCapacity($site);
            $expectedGeneration = $site['last_terminal_generation'] + 1;
            if ($demand['demand_generation'] !== $expectedGeneration) {
                throw new ControlRefusal('demand generation is not last_terminal_generation plus one');
            }
            if ($demand['expires_at'] <= $this->now()) {
                throw new ControlRefusal('new export demand is already expired');
            }
            $expectedId = OriginProtocol::demandId(
                $tenantId,
                $siteId,
                $site['origin_generation'],
                $demand['demand_generation'],
                $demand['nonce'],
                $demand['expected_production_commit']
            );
            if (!hash_equals($expectedId, $demand['demand_id'])) {
                throw new ControlRefusal('demand id does not bind its tenant, site, generations, nonce, and commit');
            }
            $site['demand'] = [
                'demand' => $demand,
                'origin_generation' => $site['origin_generation'],
                'state' => 'active',
            ];
            $site['demand_generation'] = $demand['demand_generation'];
            $state['sites'][$siteKey] = $site;
            self::compactRetainedState($state, $this->now());
            self::assertControllerDemandBounds($state['controller_demands']);
            $session->save($state);
        });
    }

    /**
     * Create exactly one next-generation portable demand for a controller
     * request. Generation, nonce, expiry, retention, and demand id are all
     * minted here; the authenticated controller chooses only the exact Git
     * commit. The request record closes the crash window between publication
     * and a gateway replay receipt.
     *
     * @return array<string,mixed>
     */
    public function requestPortableDemand(
        string $tenantId,
        string $siteId,
        string $requestId,
        string $expectedProductionCommit
    ): array {
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        self::sha256($requestId, 'controller demand request id');
        self::commitOid($expectedProductionCommit, 'expected production commit');

        return $this->store->locked(function (AuthorityStateSession $session) use (
            $tenantId,
            $siteId,
            $requestId,
            $expectedProductionCommit
        ): array {
            $state = self::state($session->state());
            $compacted = self::compactRetainedState($state, $this->now());
            $existing = $state['controller_demands'][$requestId] ?? null;
            if ($existing !== null) {
                $record = self::controllerDemandRecord($existing, $requestId);
                if ($record['tenant_id'] !== $tenantId || $record['site_id'] !== $siteId
                    || $record['expected_production_commit'] !== $expectedProductionCommit) {
                    throw new ControlRefusal('controller demand request id was replayed with changed identity');
                }
                if ($compacted) {
                    $session->save($state);
                }
                return $record['demand'];
            }

            $siteKey = self::siteKey($tenantId, $siteId);
            $site = self::siteRecord($state['sites'][$siteKey] ?? null, $tenantId, $siteId);
            if ($site['state'] !== 'active') {
                throw new ControlRefusal('origin site is not active for portable export');
            }
            $current = $site['demand'];
            if (is_array($current) && ($current['state'] ?? null) === 'active') {
                if ((int) ($current['demand']['expires_at'] ?? 0) > $this->now()) {
                    throw new ControlRefusal('origin site already has an active export demand');
                }
                $current['state'] = 'expired';
                $site['demand'] = $current;
                $site['last_terminal_generation'] = (int) $current['demand']['demand_generation'];
            }
            self::assertNewDemandCapacity($site);

            $generation = $site['last_terminal_generation'] + 1;
            $now = $this->now();
            $nonce = base64_encode(random_bytes(32));
            $demand = [
                'chunk_size' => self::CHUNK_SIZE,
                'demand_generation' => $generation,
                'demand_id' => OriginProtocol::demandId(
                    $tenantId,
                    $siteId,
                    $site['origin_generation'],
                    $generation,
                    $nonce,
                    $expectedProductionCommit
                ),
                'expires_at' => $now + self::PORTABLE_DEMAND_TTL_SECONDS,
                'expected_production_commit' => $expectedProductionCommit,
                'format' => OriginProtocol::DEMAND_FORMAT,
                'nonce' => $nonce,
                'retention_deadline' => $now + self::PORTABLE_RETENTION_SECONDS,
                'snapshot_mode' => 'portable-refresh',
            ];
            self::demand($demand);
            $site['demand'] = [
                'demand' => $demand,
                'origin_generation' => $site['origin_generation'],
                'state' => 'active',
            ];
            $site['demand_generation'] = $generation;
            $state['sites'][$siteKey] = $site;
            $state['controller_demands'][$requestId] = [
                'demand' => $demand,
                'expected_production_commit' => $expectedProductionCommit,
                'request_id' => $requestId,
                'site_id' => $siteId,
                'tenant_id' => $tenantId,
            ];
            self::compactRetainedState($state, $now);
            self::assertControllerDemandBounds($state['controller_demands']);
            $session->save($state);
            return $demand;
        });
    }

    /**
     * Read state for one exact controller-minted demand without exposing
     * whether any foreign tenant/site/demand tuple exists.
     *
     * @return array<string,mixed>
     */
    public function readPortableDemandStatus(
        string $tenantId,
        string $siteId,
        int $demandGeneration,
        string $demandId,
        string $expectedProductionCommit
    ): array {
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        self::positive($demandGeneration, 'demand generation');
        self::sha256($demandId, 'demand id');
        self::commitOid($expectedProductionCommit, 'expected production commit');

        return $this->store->locked(function (AuthorityStateSession $session) use (
            $tenantId,
            $siteId,
            $demandGeneration,
            $demandId,
            $expectedProductionCommit
        ): array {
            $state = self::state($session->state());
            if (self::compactRetainedState($state, $this->now())) {
                $session->save($state);
            }
            self::controllerDemand(
                $state,
                $tenantId,
                $siteId,
                $demandGeneration,
                $demandId,
                $expectedProductionCommit
            );
            $site = self::controllerPortableSite($state, $tenantId, $siteId);
            $status = $site['state'] === 'revoked' ? 'revoked' : null;
            $exportId = null;
            $manifestSha256 = null;
            foreach ($site['exports'] as $candidateId => $candidate) {
                $export = self::exportRecord($candidate, (string) $candidateId);
                if ($export['demand_generation'] !== $demandGeneration
                    || $export['demand_id'] !== $demandId) {
                    continue;
                }
                $exportId = $export['export_id'];
                $manifestSha256 = $export['manifest_sha256'];
                $status = match (true) {
                    $export['retention_deadline'] <= $this->now() => 'expired',
                    $export['state'] === 'committed' => 'committed',
                    $export['state'] === 'revoked' => 'revoked',
                    default => $export['state'] === 'reaping' ? 'expired' : 'uploading',
                };
                break;
            }
            if ($status === null) {
                $current = $site['demand'];
                if (!is_array($current) || !is_array($current['demand'] ?? null)
                    || ($current['demand']['demand_generation'] ?? null) !== $demandGeneration
                    || ($current['demand']['demand_id'] ?? null) !== $demandId) {
                    throw new ControlRefusal('portable export demand is unavailable');
                }
                if (($current['state'] ?? null) === 'active'
                    && (int) $current['demand']['expires_at'] <= $this->now()) {
                    $current['state'] = 'expired';
                    $site['demand'] = $current;
                    $site['last_terminal_generation'] = $demandGeneration;
                    $state['sites'][self::siteKey($tenantId, $siteId)] = $site;
                    $session->save($state);
                }
                $status = match ($current['state']) {
                    'active' => 'demanded',
                    'committed' => 'committed',
                    'expired' => 'expired',
                    'revoked' => 'revoked',
                    default => throw new ControlRefusal('portable export demand is unavailable'),
                };
            }
            return [
                'demand_generation' => $demandGeneration,
                'demand_id' => $demandId,
                'expected_production_commit' => $expectedProductionCommit,
                'export_id' => $exportId,
                'manifest_sha256' => $manifestSha256,
                'state' => $status,
            ];
        });
    }

    /**
     * Delete bounded expired export scopes under the same authority lock that
     * serializes origin writes. The reaping state is persisted before blob
     * deletion, so a killed worker resumes the exact generation-fenced scope.
     */
    public function reapExpiredExports(int $limit = 100): int {
        if ($limit < 1 || $limit > 1000) {
            throw new ControlRefusal('origin export reap limit must be between one and 1000');
        }
        $reaped = 0;
        while ($reaped < $limit) {
            $didReap = $this->store->locked(function (AuthorityStateSession $session): bool {
                $state = self::state($session->state());
                $compacted = self::compactRetainedState($state, $this->now());
                $candidate = null;
                $siteKeys = array_keys($state['sites']);
                sort($siteKeys, SORT_STRING);
                foreach ($siteKeys as $siteKey) {
                    $site = $state['sites'][$siteKey] ?? null;
                    if (!is_array($site)) {
                        throw new ControlRefusal('origin site authority is unavailable');
                    }
                    $tenantId = $site['tenant_id'] ?? null;
                    $siteId = $site['site_id'] ?? null;
                    if (!is_string($tenantId) || !is_string($siteId)) {
                        throw new ControlRefusal('origin site authority is unavailable');
                    }
                    $site = self::siteRecord($site, $tenantId, $siteId);
                    $exportIds = array_keys($site['exports']);
                    sort($exportIds, SORT_STRING);
                    foreach ($exportIds as $exportId) {
                        $export = self::exportRecord($site['exports'][$exportId] ?? null, $exportId);
                        if ($export['state'] !== 'reaping'
                            && $export['retention_deadline'] > $this->now()) {
                            continue;
                        }
                        $candidate = [$siteKey, $site, $exportId, $export];
                        break 2;
                    }
                }
                if ($candidate === null) {
                    if ($compacted) {
                        $session->save($state);
                    }
                    return false;
                }

                [$siteKey, $site, $exportId, $export] = $candidate;
                if ($export['state'] !== 'reaping') {
                    $export['state'] = 'reaping';
                    $site['exports'][$exportId] = $export;
                    $state['sites'][$siteKey] = $site;
                    $session->save($state);
                }
                $this->blobs->deleteExport(
                    $site['tenant_id'],
                    $site['site_id'],
                    $export['origin_generation'],
                    $export['demand_generation'],
                    $exportId
                );
                unset($site['exports'][$exportId]);
                $state['sites'][$siteKey] = $site;
                $session->save($state);
                return true;
            });
            if (!$didReap) {
                break;
            }
            $reaped++;
        }
        return $reaped;
    }

    /**
     * Handle one exact closed origin endpoint and return canonical signed bytes.
     * Malformed, unknown-key, and invalid-signature requests throw a redacted
     * ControlRefusal for the HTTP adapter to map to a fixed unsigned 4xx body.
     */
    public function handle(string $path, string $requestBytes): string {
        if (!in_array($path, self::paths(), true)) {
            throw new ControlRefusal('origin endpoint is not in the closed protocol');
        }
        $envelope = CanonicalJson::decodeObject($requestBytes, self::REQUEST_LIMIT);
        self::exactKeys($envelope, ['format', 'key_id', 'payload', 'signature'], 'origin signed request');
        if (($envelope['format'] ?? null) !== OriginProtocol::ENVELOPE_FORMAT
            || !is_array($envelope['payload'] ?? null) || array_is_list($envelope['payload'])) {
            throw new ControlRefusal('origin signed request envelope is malformed');
        }
        $keyId = self::sha256($envelope['key_id'] ?? null, 'origin envelope key id');
        $payload = $envelope['payload'];
        self::request($path, $payload);
        if (($payload['origin_key_id'] ?? $payload['origin_key']['key_id'] ?? null) !== $keyId) {
            throw new ControlRefusal('origin envelope key does not match the request identity');
        }
        $signature = self::base64($envelope['signature'] ?? null, SODIUM_CRYPTO_SIGN_BYTES, 'origin signature');
        $payloadBytes = CanonicalJson::encode($payload);
        $requestHash = hash('sha256', $payloadBytes);
        try {
            return match ($path) {
                OriginProtocol::PAIR_BEGIN_PATH => $this->pairBegin(
                    $payload,
                    $payloadBytes,
                    $signature,
                    $requestHash
                ),
                OriginProtocol::PAIR_POLL_PATH => $this->pairPoll(
                    $payload,
                    $payloadBytes,
                    $signature,
                    $requestHash
                ),
                default => $this->activeRequest(
                    $path,
                    $payload,
                    $payloadBytes,
                    $signature,
                    $requestHash
                ),
            };
        } catch (OriginStateRefusal $refusal) {
            return $this->signed([
                'format' => OriginProtocol::REFUSAL,
                'reason_code' => $refusal->reasonCode(),
                'request_format' => $payload['format'],
                'request_sha256' => $requestHash,
                'retryable' => $refusal->retryable(),
            ]);
        }
    }

    public function readCommittedManifest(string $tenantId, string $siteId, string $exportId): array {
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        self::sha256($exportId, 'export id');
        return $this->store->locked(function (AuthorityStateSession $session) use (
            $tenantId,
            $siteId,
            $exportId
        ): array {
            $state = self::state($session->state());
            $site = self::controllerSite($state, $tenantId, $siteId);
            $export = self::controllerExport($site, $exportId);
            $this->assertRetained($export);
            return $export['manifest'];
        });
    }

    /** @return array<string,mixed> */
    public function readPortableManifest(
        string $tenantId,
        string $siteId,
        int $demandGeneration,
        string $demandId,
        string $expectedProductionCommit,
        string $exportId
    ): array {
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        self::positive($demandGeneration, 'demand generation');
        self::sha256($demandId, 'demand id');
        self::commitOid($expectedProductionCommit, 'expected production commit');
        self::sha256($exportId, 'export id');

        return $this->store->locked(function (AuthorityStateSession $session) use (
            $tenantId,
            $siteId,
            $demandGeneration,
            $demandId,
            $expectedProductionCommit,
            $exportId
        ): array {
            $state = self::state($session->state());
            self::controllerDemand(
                $state,
                $tenantId,
                $siteId,
                $demandGeneration,
                $demandId,
                $expectedProductionCommit
            );
            $site = self::controllerPortableSite($state, $tenantId, $siteId);
            $export = self::controllerPortableExport(
                $site,
                $demandGeneration,
                $demandId,
                $expectedProductionCommit,
                $exportId
            );
            $this->assertRetained($export);
            return $export['manifest'];
        });
    }

    public function readCommittedChunk(
        string $tenantId,
        string $siteId,
        string $exportId,
        int $index
    ): string {
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        self::sha256($exportId, 'export id');
        if ($index < 0) {
            throw new ControlRefusal('committed export chunk index is invalid');
        }
        return $this->store->locked(function (AuthorityStateSession $session) use (
            $tenantId,
            $siteId,
            $exportId,
            $index
        ): string {
            $state = self::state($session->state());
            $site = self::controllerSite($state, $tenantId, $siteId);
            $export = self::controllerExport($site, $exportId);
            $this->assertRetained($export);
            $descriptor = $export['manifest']['chunks'][$index] ?? null;
            if (!is_array($descriptor) || ($descriptor['index'] ?? null) !== $index) {
                throw new ControlRefusal('committed export is unavailable');
            }
            try {
                $bytes = $this->blobs->getChunk(
                    $tenantId,
                    $siteId,
                    $export['origin_generation'],
                    $export['demand_generation'],
                    $exportId,
                    $descriptor['sha256']
                );
            } catch (\Throwable $error) {
                throw new ControlRefusal('committed export is unavailable', 0, $error);
            }
            if (!is_string($bytes) || strlen($bytes) !== $descriptor['size']
                || !hash_equals($descriptor['sha256'], hash('sha256', $bytes))) {
                throw new ControlRefusal('committed export is unavailable');
            }
            return $bytes;
        });
    }

    public function readPortableChunk(
        string $tenantId,
        string $siteId,
        int $demandGeneration,
        string $demandId,
        string $expectedProductionCommit,
        string $exportId,
        string $manifestSha256,
        int $index
    ): string {
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        self::positive($demandGeneration, 'demand generation');
        self::sha256($demandId, 'demand id');
        self::commitOid($expectedProductionCommit, 'expected production commit');
        self::sha256($exportId, 'export id');
        self::sha256($manifestSha256, 'manifest hash');
        if ($index < 0) {
            throw new ControlRefusal('committed export is unavailable');
        }

        return $this->store->locked(function (AuthorityStateSession $session) use (
            $tenantId,
            $siteId,
            $demandGeneration,
            $demandId,
            $expectedProductionCommit,
            $exportId,
            $manifestSha256,
            $index
        ): string {
            $state = self::state($session->state());
            self::controllerDemand(
                $state,
                $tenantId,
                $siteId,
                $demandGeneration,
                $demandId,
                $expectedProductionCommit
            );
            $site = self::controllerPortableSite($state, $tenantId, $siteId);
            $export = self::controllerPortableExport(
                $site,
                $demandGeneration,
                $demandId,
                $expectedProductionCommit,
                $exportId
            );
            $this->assertRetained($export);
            if (!hash_equals($export['manifest_sha256'], $manifestSha256)) {
                throw new ControlRefusal('committed export is unavailable');
            }
            $descriptor = $export['manifest']['chunks'][$index] ?? null;
            if (!is_array($descriptor) || ($descriptor['index'] ?? null) !== $index) {
                throw new ControlRefusal('committed export is unavailable');
            }
            try {
                $bytes = $this->blobs->getChunk(
                    $tenantId,
                    $siteId,
                    $export['origin_generation'],
                    $export['demand_generation'],
                    $exportId,
                    $descriptor['sha256']
                );
            } catch (\Throwable $error) {
                throw new ControlRefusal('committed export is unavailable', 0, $error);
            }
            if (!is_string($bytes) || strlen($bytes) !== $descriptor['size']
                || !hash_equals($descriptor['sha256'], hash('sha256', $bytes))) {
                throw new ControlRefusal('committed export is unavailable');
            }
            return $bytes;
        });
    }

    /** @return list<string> */
    private static function paths(): array {
        return [
            OriginProtocol::PAIR_BEGIN_PATH,
            OriginProtocol::PAIR_POLL_PATH,
            OriginProtocol::DEMAND_POLL_PATH,
            OriginProtocol::ANNOUNCE_PATH,
            OriginProtocol::MISSING_PATH,
            OriginProtocol::CHUNK_PATH,
            OriginProtocol::COMMIT_PATH,
            OriginProtocol::ROTATE_PATH,
            OriginProtocol::REVOKE_PATH,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function pairBegin(
        array $payload,
        string $payloadBytes,
        string $signature,
        string $requestHash
    ): string {
        $originKey = $payload['origin_key'];
        $publicKey = self::base64(
            $originKey['public_key'],
            SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            'pairing origin public key'
        );
        if (!sodium_crypto_sign_verify_detached($signature, $payloadBytes, $publicKey)) {
            throw new ControlRefusal('origin pairing signature is invalid');
        }
        $keyId = $originKey['key_id'];
        $requestId = $payload['request_id'];
        $receiptKey = self::receiptKey($keyId, $requestId);
        $deviceDigest = $this->deviceCodeDigest($payload['device_code']);

        return $this->store->locked(function (AuthorityStateSession $session) use (
            $payload,
            $publicKey,
            $keyId,
            $requestId,
            $requestHash,
            $receiptKey,
            $deviceDigest
        ): string {
            $state = self::state($session->state());
            $compacted = self::compactRetainedState($state, $this->now());
            $receipt = self::receipt($state['receipts'][$receiptKey] ?? null, $receiptKey);
            if ($receipt !== null) {
                self::assertReplay($receipt, OriginProtocol::PAIR_BEGIN_PATH, $keyId, $requestId, $requestHash);
                if ($receipt['status'] === 'complete') {
                    if ($compacted) {
                        $session->save($state);
                    }
                    return self::cachedResponse($receipt);
                }
            }

            $storedCode = $state['device_codes'][$deviceDigest] ?? null;
            if (!is_array($storedCode) || array_is_list($storedCode)) {
                throw new OriginStateRefusal('pairing_code_rejected');
            }
            $code = self::deviceCodeRecord($storedCode, $deviceDigest);
            if ($code['state'] !== 'issued' || $code['expires_at'] <= $this->now()) {
                throw new OriginStateRefusal('pairing_code_rejected');
            }
            $tenantId = $code['tenant_id'];
            $siteId = $code['site_id'];
            $siteKey = self::siteKey($tenantId, $siteId);
            $site = $state['sites'][$siteKey] ?? null;
            if ($site !== null) {
                $site = self::siteRecord($site, $tenantId, $siteId);
                if ($site['pending_pairing_id'] !== null || self::siteBusy($site, $this->now())) {
                    throw new ControlRefusal('origin site is not idle for pairing');
                }
            }
            $existingKey = $state['keys'][$keyId] ?? null;
            if ($existingKey !== null) {
                throw new ControlRefusal('origin key id was already used');
            }
            if (self::releaseTransitionCapacity(
                $state,
                $tenantId,
                $siteId,
                true,
                $this->now()
            )) {
                $compacted = true;
            }
            try {
                self::assertNewPairingCapacity($state['pairings'], $tenantId, $siteId);
                self::assertNewOriginKeyCapacity($state['keys'], $tenantId, $siteId);
            } catch (ControlRefusal $error) {
                if ($compacted) {
                    $session->save($state);
                }
                throw $error;
            }
            $originGeneration = is_array($site) ? $site['origin_generation'] + 1 : 1;
            if (!is_array($site)) {
                $site = [
                    'active_key_id' => null,
                    'demand' => null,
                    'demand_generation' => 0,
                    'exports' => [],
                    'last_terminal_generation' => 0,
                    'origin_generation' => 0,
                    'pending_pairing_id' => null,
                    'site_id' => $siteId,
                    'state' => 'unpaired',
                    'tenant_id' => $tenantId,
                ];
                $state['sites'][$siteKey] = $site;
            }
            $scope = self::receiptScope(
                'origin',
                $tenantId,
                $siteId,
                $originGeneration,
                0,
                $code['expires_at']
            );
            if ($receipt === null) {
                self::reserve(
                    $state,
                    $receiptKey,
                    OriginProtocol::PAIR_BEGIN_PATH,
                    $keyId,
                    $requestId,
                    $requestHash,
                    $scope
                );
                $session->save($state);
            } else {
                self::assertReceiptScope($receipt, $scope);
            }
            $pairingId = hash(
                'sha256',
                "duo-cloud-origin-pairing/v1\0$tenantId\0$siteId\0"
                    . $payload['pair_attempt_id'] . "\0$keyId\0" . bin2hex(random_bytes(32))
            );
            $pairing = [
                'expires_at' => $code['expires_at'],
                'origin_generation' => $originGeneration,
                'origin_key_id' => $keyId,
                'pair_attempt_id' => $payload['pair_attempt_id'],
                'pairing_id' => $pairingId,
                'site_id' => $siteId,
                'state' => 'pending',
                'tenant_id' => $tenantId,
            ];
            $state['pairings'][$pairingId] = $pairing;
            $state['keys'][$keyId] = [
                'generation' => $originGeneration,
                'key_id' => $keyId,
                'public_key' => base64_encode($publicKey),
                'site_id' => $siteId,
                'state' => 'pending',
                'tenant_id' => $tenantId,
            ];
            $code['consumed_pairing_id'] = $pairingId;
            $code['state'] = 'consumed';
            $state['device_codes'][$deviceDigest] = $code;
            $site['pending_pairing_id'] = $pairingId;
            $state['sites'][$siteKey] = $site;
            $response = $this->signed([
                'expires_at' => $pairing['expires_at'],
                'format' => OriginProtocol::PAIR_BEGIN_RESPONSE,
                'origin_key_id' => $keyId,
                'pair_attempt_id' => $payload['pair_attempt_id'],
                'pairing_id' => $pairingId,
                'poll_after_seconds' => self::POLL_AFTER_SECONDS,
                'request_sha256' => $requestHash,
                'state' => 'pending',
            ]);
            self::complete($state, $receiptKey, $response);
            $session->save($state);
            return $response;
        });
    }

    /** @param array<string,mixed> $payload */
    private function pairPoll(
        array $payload,
        string $payloadBytes,
        string $signature,
        string $requestHash
    ): string {
        $keyId = $payload['origin_key_id'];
        $requestId = $payload['request_id'];
        $receiptKey = self::receiptKey($keyId, $requestId);
        return $this->store->locked(function (AuthorityStateSession $session) use (
            $payload,
            $payloadBytes,
            $signature,
            $requestHash,
            $keyId,
            $requestId,
            $receiptKey
        ): string {
            $state = self::state($session->state());
            $compacted = self::compactRetainedState($state, $this->now());
            $pairing = self::pairingRecord(
                $state['pairings'][$payload['pairing_id']] ?? null,
                $payload['pairing_id']
            );
            if ($pairing['origin_key_id'] !== $keyId
                || $pairing['pair_attempt_id'] !== $payload['pair_attempt_id']) {
                throw new ControlRefusal('pair poll does not name its exact pending pairing');
            }
            $key = self::keyRecord($state['keys'][$keyId] ?? null, $keyId);
            $publicKey = self::base64(
                $key['public_key'],
                SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
                'origin verification key'
            );
            if (!sodium_crypto_sign_verify_detached($signature, $payloadBytes, $publicKey)) {
                throw new ControlRefusal('origin pair-poll signature is invalid');
            }
            $siteKey = self::siteKey($pairing['tenant_id'], $pairing['site_id']);
            $site = $state['sites'][$siteKey] ?? null;
            $receipt = self::receipt($state['receipts'][$receiptKey] ?? null, $receiptKey);
            if ($receipt !== null) {
                self::assertReplay($receipt, OriginProtocol::PAIR_POLL_PATH, $keyId, $requestId, $requestHash);
                if ($receipt['status'] === 'complete') {
                    if ($compacted) {
                        $session->save($state);
                    }
                    return self::cachedResponse($receipt);
                }
            }
            if ($pairing['state'] === 'paired') {
                $site = self::siteRecord($site, $pairing['tenant_id'], $pairing['site_id']);
                if ($site['state'] === 'revoked') {
                    throw new OriginStateRefusal('origin_revoked');
                }
                if ($site['active_key_id'] !== $keyId
                    || $site['origin_generation'] !== $pairing['origin_generation']
                    || $key['state'] !== 'active') {
                    throw new OriginStateRefusal('stale_origin_generation');
                }
            }
            $scope = self::receiptScope(
                'origin',
                $pairing['tenant_id'],
                $pairing['site_id'],
                $pairing['origin_generation'],
                0,
                $pairing['expires_at']
            );
            if ($receipt === null) {
                self::reserve(
                    $state,
                    $receiptKey,
                    OriginProtocol::PAIR_POLL_PATH,
                    $keyId,
                    $requestId,
                    $requestHash,
                    $scope
                );
                $session->save($state);
            } else {
                self::assertReceiptScope($receipt, $scope);
            }

            $now = $this->now();
            if ($pairing['state'] === 'pending' && $pairing['expires_at'] <= $now) {
                $pairing['state'] = 'expired';
                $key['state'] = 'expired';
                $state['keys'][$keyId] = $key;
                if (is_array($site) && ($site['pending_pairing_id'] ?? null) === $pairing['pairing_id']) {
                    $site['pending_pairing_id'] = null;
                    $state['sites'][$siteKey] = $site;
                }
            } elseif ($pairing['state'] === 'pending') {
                $site = $this->activatePairing($state, $pairing, $key);
                $pairing['state'] = 'paired';
            }
            $state['pairings'][$pairing['pairing_id']] = $pairing;
            $pairingPayload = null;
            if ($pairing['state'] === 'paired') {
                $pairingPayload = [
                    'demand_generation' => $site['demand_generation'],
                    'origin_generation' => $site['origin_generation'],
                    'service_key_id' => $this->serviceKeyId,
                    'service_public_key_sha256' => hash(
                        'sha256',
                        sodium_crypto_sign_publickey_from_secretkey($this->serviceSecretKey)
                    ),
                    'site_id' => $pairing['site_id'],
                    'tenant_id' => $pairing['tenant_id'],
                ];
            }
            $response = $this->signed([
                'expires_at' => $pairing['expires_at'],
                'format' => OriginProtocol::PAIR_POLL_RESPONSE,
                'origin_key_id' => $keyId,
                'pair_attempt_id' => $pairing['pair_attempt_id'],
                'pairing' => $pairingPayload,
                'pairing_id' => $pairing['pairing_id'],
                'poll_after_seconds' => self::POLL_AFTER_SECONDS,
                'poll_sequence' => $payload['poll_sequence'],
                'request_sha256' => $requestHash,
                'state' => $pairing['state'],
            ]);
            self::complete($state, $receiptKey, $response);
            $session->save($state);
            return $response;
        });
    }

    /** @param array<string,mixed> $payload */
    private function activeRequest(
        string $path,
        array $payload,
        string $payloadBytes,
        string $signature,
        string $requestHash
    ): string {
        $keyId = $payload['origin_key_id'];
        $requestId = $payload['request_id'];
        $receiptKey = self::receiptKey($keyId, $requestId);
        return $this->store->locked(function (AuthorityStateSession $session) use (
            $path,
            $payload,
            $payloadBytes,
            $signature,
            $requestHash,
            $keyId,
            $requestId,
            $receiptKey
        ): string {
            $state = self::state($session->state());
            $compacted = self::compactRetainedState($state, $this->now());
            $key = self::keyRecord($state['keys'][$keyId] ?? null, $keyId);
            $publicKey = self::base64(
                $key['public_key'],
                SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
                'origin verification key'
            );
            if (!sodium_crypto_sign_verify_detached($signature, $payloadBytes, $publicKey)) {
                throw new ControlRefusal('origin request signature is invalid');
            }
            if ($key['tenant_id'] !== $payload['tenant_id'] || $key['site_id'] !== $payload['site_id']) {
                throw new ControlRefusal('origin key is not bound to the signed tenant and site');
            }
            $receipt = self::receipt($state['receipts'][$receiptKey] ?? null, $receiptKey);
            if ($receipt !== null) {
                self::assertReplay($receipt, $path, $keyId, $requestId, $requestHash);
                if ($receipt['status'] === 'complete') {
                    if ($compacted) {
                        $session->save($state);
                    }
                    return self::cachedResponse($receipt);
                }
            }

            $siteKey = self::siteKey($payload['tenant_id'], $payload['site_id']);
            $site = self::siteRecord(
                $state['sites'][$siteKey] ?? null,
                $payload['tenant_id'],
                $payload['site_id']
            );
            if ($site['state'] === 'revoked') {
                throw new OriginStateRefusal('origin_revoked');
            }
            if ($site['active_key_id'] !== $keyId || $key['state'] !== 'active'
                || $site['origin_generation'] !== $payload['origin_generation']
                || $key['generation'] !== $payload['origin_generation']) {
                throw new OriginStateRefusal('stale_origin_generation');
            }
            $scope = $this->activeReceiptScope($path, $payload, $site);
            if ($receipt === null) {
                self::reserve(
                    $state,
                    $receiptKey,
                    $path,
                    $keyId,
                    $requestId,
                    $requestHash,
                    $scope
                );
                $session->save($state);
            } else {
                self::assertReceiptScope($receipt, $scope);
            }

            $responsePayload = match ($path) {
                OriginProtocol::DEMAND_POLL_PATH => $this->demandPollResponse($payload, $site, $requestHash),
                OriginProtocol::ANNOUNCE_PATH => $this->announceResponse($payload, $site, $requestHash),
                OriginProtocol::MISSING_PATH => $this->missingResponse($payload, $site, $requestHash),
                OriginProtocol::CHUNK_PATH => $this->chunkResponse($payload, $site, $requestHash),
                OriginProtocol::COMMIT_PATH => $this->commitResponse($payload, $site, $requestHash),
                OriginProtocol::ROTATE_PATH => $this->rotateResponse($payload, $state, $site, $requestHash),
                OriginProtocol::REVOKE_PATH => $this->revokeResponse($payload, $state, $site, $requestHash),
                default => throw new ControlRefusal('origin endpoint dispatch escaped its closed set'),
            };
            $state['sites'][$siteKey] = $site;
            $response = $this->signed($responsePayload);
            self::complete($state, $receiptKey, $response);
            $session->save($state);
            return $response;
        });
    }

    /**
     * @param array<string,mixed> $state
     * @param array<string,mixed> $pairing
     * @param array<string,mixed> $key
     * @return array<string,mixed>
     */
    private function activatePairing(array &$state, array $pairing, array $key): array {
        $siteKey = self::siteKey($pairing['tenant_id'], $pairing['site_id']);
        $existing = $state['sites'][$siteKey] ?? null;
        if ($existing === null) {
            $site = [
                'active_key_id' => null,
                'demand' => null,
                'demand_generation' => 0,
                'exports' => [],
                'last_terminal_generation' => 0,
                'origin_generation' => 0,
                'pending_pairing_id' => null,
                'site_id' => $pairing['site_id'],
                'state' => 'unpaired',
                'tenant_id' => $pairing['tenant_id'],
            ];
        } else {
            $site = self::siteRecord($existing, $pairing['tenant_id'], $pairing['site_id']);
            if ($site['pending_pairing_id'] !== $pairing['pairing_id']) {
                throw new ControlRefusal('pending pairing lost its site-wide reservation');
            }
            if (is_array($site['demand']) && ($site['demand']['state'] ?? null) === 'active') {
                if ((int) ($site['demand']['demand']['expires_at'] ?? 0) > $this->now()) {
                    throw new ControlRefusal('origin site became busy before pairing activation');
                }
                $site['demand']['state'] = 'expired';
                $site['last_terminal_generation'] = (int) $site['demand']['demand']['demand_generation'];
            }
        }
        if ($pairing['origin_generation'] !== $site['origin_generation'] + 1) {
            throw new ControlRefusal('pairing no longer advances the site origin generation');
        }
        $oldKeyId = $site['active_key_id'];
        if (is_string($oldKeyId)) {
            $oldKey = self::keyRecord($state['keys'][$oldKeyId] ?? null, $oldKeyId);
            $oldKey['state'] = 'stale';
            $state['keys'][$oldKeyId] = $oldKey;
        }
        $key['state'] = 'active';
        $state['keys'][$key['key_id']] = $key;
        $site['active_key_id'] = $key['key_id'];
        $site['origin_generation'] = $pairing['origin_generation'];
        $site['pending_pairing_id'] = null;
        $site['state'] = 'active';
        $state['sites'][$siteKey] = $site;
        return $site;
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $site @return array<string,mixed> */
    private function demandPollResponse(array $payload, array &$site, string $requestHash): array {
        $demand = null;
        $state = 'idle';
        $current = $site['demand'];
        if (is_array($current) && ($current['state'] ?? null) === 'active') {
            if ((int) $current['demand']['expires_at'] <= $this->now()) {
                $current['state'] = 'expired';
                $site['demand'] = $current;
                $site['last_terminal_generation'] = (int) $current['demand']['demand_generation'];
            } elseif ($current['origin_generation'] === $site['origin_generation']
                && $current['demand']['demand_generation'] > $payload['after_demand_generation']) {
                $demand = $current['demand'];
                $state = 'demanded';
            }
        }
        return [
            'after_demand_generation' => $payload['after_demand_generation'],
            'demand' => $demand,
            'format' => OriginProtocol::DEMAND_POLL_RESPONSE,
            'origin_generation' => $site['origin_generation'],
            'origin_key_id' => $site['active_key_id'],
            'poll_after_seconds' => self::POLL_AFTER_SECONDS,
            'poll_sequence' => $payload['poll_sequence'],
            'request_sha256' => $requestHash,
            'site_id' => $site['site_id'],
            'state' => $state,
            'tenant_id' => $site['tenant_id'],
        ];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $site @return array<string,mixed> */
    private function announceResponse(array $payload, array &$site, string $requestHash): array {
        $demand = $this->activeDemand($payload, $site);
        $manifest = $payload['manifest'];
        self::manifest($manifest);
        if ($manifest['generation'] !== $payload['demand_generation']
            || $manifest['expected_production_commit'] !== $demand['expected_production_commit']) {
            throw new OriginStateRefusal('manifest_conflict');
        }
        $manifestSha256 = $manifest['manifest_sha256'];
        $exportId = OriginProtocol::exportId(
            $site['tenant_id'],
            $site['site_id'],
            $site['origin_generation'],
            $payload['demand_generation'],
            $payload['demand_id'],
            $manifestSha256
        );
        foreach ($site['exports'] as $existing) {
            if (is_array($existing)
                && ($existing['demand_generation'] ?? null) === $payload['demand_generation']
                && ($existing['demand_id'] ?? null) === $payload['demand_id']
                && ($existing['manifest_sha256'] ?? null) !== $manifestSha256) {
                throw new OriginStateRefusal('manifest_conflict');
            }
        }
        $existing = $site['exports'][$exportId] ?? null;
        if ($existing !== null) {
            $existing = self::exportRecord($existing, $exportId);
            if (CanonicalJson::encode($existing['manifest']) !== CanonicalJson::encode($manifest)) {
                throw new OriginStateRefusal('manifest_conflict');
            }
        } else {
            self::assertNewExportCapacity($site, $manifest['export_size']);
            $site['exports'][$exportId] = [
                'demand_generation' => $payload['demand_generation'],
                'demand_id' => $payload['demand_id'],
                'export_id' => $exportId,
                'manifest' => $manifest,
                'manifest_sha256' => $manifestSha256,
                'origin_generation' => $payload['origin_generation'],
                'retention_deadline' => $demand['retention_deadline'],
                'state' => 'announced',
            ];
        }
        return [
            'demand_generation' => $payload['demand_generation'],
            'demand_id' => $payload['demand_id'],
            'export_id' => $exportId,
            'format' => OriginProtocol::ANNOUNCE_RESPONSE,
            'manifest_sha256' => $manifestSha256,
            'origin_generation' => $payload['origin_generation'],
            'origin_key_id' => $payload['origin_key_id'],
            'request_sha256' => $requestHash,
            'site_id' => $payload['site_id'],
            'state' => 'announced',
            'tenant_id' => $payload['tenant_id'],
        ];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $site @return array<string,mixed> */
    private function missingResponse(array $payload, array &$site, string $requestHash): array {
        $this->activeDemand($payload, $site);
        $export = $this->activeExport($payload, $site);
        $missing = [];
        foreach ($export['manifest']['chunks'] as $descriptor) {
            try {
                $bytes = $this->blobs->getChunk(
                    $site['tenant_id'],
                    $site['site_id'],
                    $export['origin_generation'],
                    $export['demand_generation'],
                    $export['export_id'],
                    $descriptor['sha256']
                );
            } catch (\Throwable) {
                throw new OriginStateRefusal('recovery_required');
            }
            if ($bytes === null) {
                $missing[$descriptor['sha256']] = true;
                continue;
            }
            if (strlen($bytes) !== $descriptor['size']
                || !hash_equals($descriptor['sha256'], hash('sha256', $bytes))) {
                throw new OriginStateRefusal('recovery_required');
            }
        }
        $digests = array_keys($missing);
        sort($digests, SORT_STRING);
        return [
            'demand_generation' => $payload['demand_generation'],
            'demand_id' => $payload['demand_id'],
            'export_id' => $payload['export_id'],
            'format' => OriginProtocol::MISSING_RESPONSE,
            'manifest_sha256' => $payload['manifest_sha256'],
            'missing_chunk_sha256' => $digests,
            'origin_generation' => $payload['origin_generation'],
            'origin_key_id' => $payload['origin_key_id'],
            'query_sequence' => $payload['query_sequence'],
            'request_sha256' => $requestHash,
            'site_id' => $payload['site_id'],
            'tenant_id' => $payload['tenant_id'],
        ];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $site @return array<string,mixed> */
    private function chunkResponse(array $payload, array &$site, string $requestHash): array {
        $this->activeDemand($payload, $site);
        $export = $this->activeExport($payload, $site);
        $bytes = self::base64($payload['chunk_base64'], $payload['chunk_size'], 'origin export chunk');
        if (!hash_equals($payload['chunk_sha256'], hash('sha256', $bytes))) {
            throw new ControlRefusal('origin export chunk digest does not verify');
        }
        $announced = false;
        foreach ($export['manifest']['chunks'] as $descriptor) {
            if ($descriptor['sha256'] === $payload['chunk_sha256']
                && $descriptor['size'] === $payload['chunk_size']) {
                $announced = true;
                break;
            }
        }
        if (!$announced) {
            throw new OriginStateRefusal('chunk_not_announced');
        }
        try {
            $this->blobs->putChunk(
                $site['tenant_id'],
                $site['site_id'],
                $export['origin_generation'],
                $export['demand_generation'],
                $export['export_id'],
                $payload['chunk_sha256'],
                $bytes
            );
            $readback = $this->blobs->getChunk(
                $site['tenant_id'],
                $site['site_id'],
                $export['origin_generation'],
                $export['demand_generation'],
                $export['export_id'],
                $payload['chunk_sha256']
            );
        } catch (\Throwable) {
            throw new OriginStateRefusal('recovery_required');
        }
        if (!is_string($readback) || $readback !== $bytes
            || !hash_equals($payload['chunk_sha256'], hash('sha256', $readback))) {
            throw new OriginStateRefusal('recovery_required');
        }
        return [
            'chunk_sha256' => $payload['chunk_sha256'],
            'chunk_size' => $payload['chunk_size'],
            'demand_generation' => $payload['demand_generation'],
            'demand_id' => $payload['demand_id'],
            'export_id' => $payload['export_id'],
            'format' => OriginProtocol::CHUNK_RESPONSE,
            'manifest_sha256' => $payload['manifest_sha256'],
            'origin_generation' => $payload['origin_generation'],
            'origin_key_id' => $payload['origin_key_id'],
            'request_sha256' => $requestHash,
            'site_id' => $payload['site_id'],
            'state' => 'stored',
            'tenant_id' => $payload['tenant_id'],
        ];
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $site @return array<string,mixed> */
    private function commitResponse(array $payload, array &$site, string $requestHash): array {
        $demand = $this->activeDemand($payload, $site);
        $export = $this->activeExport($payload, $site);
        $bytes = '';
        foreach ($export['manifest']['chunks'] as $descriptor) {
            try {
                $chunk = $this->blobs->getChunk(
                    $site['tenant_id'],
                    $site['site_id'],
                    $export['origin_generation'],
                    $export['demand_generation'],
                    $export['export_id'],
                    $descriptor['sha256']
                );
            } catch (\Throwable) {
                throw new OriginStateRefusal('recovery_required');
            }
            if ($chunk === null) {
                throw new OriginStateRefusal('commit_incomplete', true);
            }
            if (strlen($chunk) !== $descriptor['size']
                || !hash_equals($descriptor['sha256'], hash('sha256', $chunk))) {
                throw new OriginStateRefusal('recovery_required');
            }
            $bytes .= $chunk;
            if (strlen($bytes) > self::MAX_EXPORT_SIZE) {
                throw new OriginStateRefusal('recovery_required');
            }
        }
        if (strlen($bytes) !== $export['manifest']['export_size']
            || !hash_equals($export['manifest']['export_sha256'], hash('sha256', $bytes))) {
            throw new OriginStateRefusal('recovery_required');
        }
        self::canonicalProductionExport($bytes, $export['manifest']);
        try {
            $this->blobs->publishArtifact(
                $site['tenant_id'],
                $site['site_id'],
                $export['origin_generation'],
                $export['demand_generation'],
                $export['export_id'],
                $bytes
            );
            $readback = $this->blobs->getArtifact(
                $site['tenant_id'],
                $site['site_id'],
                $export['origin_generation'],
                $export['demand_generation'],
                $export['export_id']
            );
        } catch (\Throwable) {
            throw new OriginStateRefusal('recovery_required');
        }
        if (!is_string($readback) || $readback !== $bytes
            || !hash_equals($export['manifest']['export_sha256'], hash('sha256', $readback))) {
            throw new OriginStateRefusal('recovery_required');
        }
        $export['state'] = 'committed';
        $site['exports'][$export['export_id']] = $export;
        $site['demand']['state'] = 'committed';
        $site['last_terminal_generation'] = $export['demand_generation'];
        $receiptBasis = [
            'demand_generation' => $payload['demand_generation'],
            'demand_id' => $payload['demand_id'],
            'export_id' => $payload['export_id'],
            'manifest_sha256' => $payload['manifest_sha256'],
            'origin_generation' => $payload['origin_generation'],
            'origin_key_id' => $payload['origin_key_id'],
            'retention_deadline' => $demand['retention_deadline'],
            'site_id' => $payload['site_id'],
            'snapshot_hash' => $export['manifest']['snapshot_hash'],
            'tenant_id' => $payload['tenant_id'],
        ];
        return [
            'commit_receipt_sha256' => hash(
                'sha256',
                "duo-cloud-origin-commit-receipt/v1\0" . CanonicalJson::encode($receiptBasis)
            ),
            'demand_generation' => $payload['demand_generation'],
            'demand_id' => $payload['demand_id'],
            'export_id' => $payload['export_id'],
            'format' => OriginProtocol::COMMIT_RESPONSE,
            'manifest_sha256' => $payload['manifest_sha256'],
            'origin_generation' => $payload['origin_generation'],
            'origin_key_id' => $payload['origin_key_id'],
            'request_sha256' => $requestHash,
            'retention_deadline' => $demand['retention_deadline'],
            'site_id' => $payload['site_id'],
            'snapshot_hash' => $export['manifest']['snapshot_hash'],
            'state' => 'committed',
            'tenant_id' => $payload['tenant_id'],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $state
     * @param array<string,mixed> $site
     * @return array<string,mixed>
     */
    private function rotateResponse(array $payload, array &$state, array &$site, string $requestHash): array {
        if (self::siteBusy($site, $this->now())) {
            throw new OriginStateRefusal('rate_limited', true);
        }
        $newKey = $payload['new_key'];
        $newKeyId = $newKey['key_id'];
        if (isset($state['keys'][$newKeyId])) {
            throw new ControlRefusal('rotation new key id was already used');
        }
        self::releaseTransitionCapacity(
            $state,
            $site['tenant_id'],
            $site['site_id'],
            false,
            $this->now()
        );
        self::assertNewOriginKeyCapacity($state['keys'], $site['tenant_id'], $site['site_id']);
        $newPublic = self::base64(
            $newKey['public_key'],
            SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            'rotation new origin public key'
        );
        $proofPayload = [
            'format' => OriginProtocol::ROTATE_PROOF,
            'new_origin_key_id' => $newKeyId,
            'next_origin_generation' => $payload['next_origin_generation'],
            'origin_generation' => $payload['origin_generation'],
            'rotation_id' => $payload['rotation_id'],
            'site_id' => $payload['site_id'],
            'tenant_id' => $payload['tenant_id'],
        ];
        $proofSignature = self::base64(
            $payload['new_key_proof'],
            SODIUM_CRYPTO_SIGN_BYTES,
            'rotation new-key proof signature'
        );
        if (!sodium_crypto_sign_verify_detached(
            $proofSignature,
            CanonicalJson::encode($proofPayload),
            $newPublic
        )) {
            throw new ControlRefusal('rotation new-key proof signature is invalid');
        }
        if ($payload['next_origin_generation'] !== $site['origin_generation'] + 1) {
            throw new OriginStateRefusal('stale_origin_generation');
        }
        $oldKeyId = $site['active_key_id'];
        $oldKey = self::keyRecord($state['keys'][$oldKeyId] ?? null, $oldKeyId);
        $oldKey['state'] = 'stale';
        $state['keys'][$oldKeyId] = $oldKey;
        $state['keys'][$newKeyId] = [
            'generation' => $payload['next_origin_generation'],
            'key_id' => $newKeyId,
            'public_key' => base64_encode($newPublic),
            'site_id' => $site['site_id'],
            'state' => 'active',
            'tenant_id' => $site['tenant_id'],
        ];
        $site['active_key_id'] = $newKeyId;
        $site['origin_generation'] = $payload['next_origin_generation'];
        $receiptBasis = [
            'new_origin_key_id' => $newKeyId,
            'next_origin_generation' => $payload['next_origin_generation'],
            'origin_generation' => $payload['origin_generation'],
            'origin_key_id' => $payload['origin_key_id'],
            'rotation_id' => $payload['rotation_id'],
            'site_id' => $payload['site_id'],
            'tenant_id' => $payload['tenant_id'],
        ];
        return [
            'format' => OriginProtocol::ROTATE_RESPONSE,
            'next_origin_generation' => $payload['next_origin_generation'],
            'new_origin_key_id' => $newKeyId,
            'origin_generation' => $payload['origin_generation'],
            'origin_key_id' => $payload['origin_key_id'],
            'request_sha256' => $requestHash,
            'rotation_id' => $payload['rotation_id'],
            'rotation_receipt_sha256' => hash(
                'sha256',
                "duo-cloud-origin-rotation-receipt/v1\0" . CanonicalJson::encode($receiptBasis)
            ),
            'site_id' => $payload['site_id'],
            'state' => 'rotated',
            'tenant_id' => $payload['tenant_id'],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $state
     * @param array<string,mixed> $site
     * @return array<string,mixed>
     */
    private function revokeResponse(array $payload, array &$state, array &$site, string $requestHash): array {
        $keyId = $site['active_key_id'];
        $key = self::keyRecord($state['keys'][$keyId] ?? null, $keyId);
        $key['state'] = 'revoked';
        $state['keys'][$keyId] = $key;
        $site['state'] = 'revoked';
        if (is_array($site['demand']) && ($site['demand']['state'] ?? null) === 'active') {
            $site['demand']['state'] = 'revoked';
            $site['last_terminal_generation'] = (int) $site['demand']['demand']['demand_generation'];
        }
        foreach ($site['exports'] as $exportId => $export) {
            if (is_array($export) && ($export['state'] ?? null) !== 'reaping') {
                $export['state'] = 'revoked';
                $export['retention_deadline'] = min(
                    (int) ($export['retention_deadline'] ?? $this->now()),
                    $this->now()
                );
                $site['exports'][$exportId] = $export;
            }
        }
        return [
            'format' => OriginProtocol::REVOKE_RESPONSE,
            'origin_generation' => $payload['origin_generation'],
            'origin_key_id' => $payload['origin_key_id'],
            'request_sha256' => $requestHash,
            'revocation_id' => $payload['revocation_id'],
            'revocation_receipt_sha256' => hash(
                'sha256',
                "duo-cloud-origin-revocation-receipt/v1\0"
                    . $payload['tenant_id'] . "\0" . $payload['site_id'] . "\0"
                    . $payload['origin_generation'] . "\0" . $payload['origin_key_id'] . "\0"
                    . $payload['revocation_id'] . "\0" . $payload['reason']
            ),
            'site_id' => $payload['site_id'],
            'state' => 'revoked',
            'tenant_id' => $payload['tenant_id'],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $site
     * @return array<string,mixed>
     */
    private function activeReceiptScope(string $path, array $payload, array $site): array {
        if (in_array($path, [OriginProtocol::ROTATE_PATH, OriginProtocol::REVOKE_PATH], true)) {
            return self::receiptScope(
                'origin',
                $site['tenant_id'],
                $site['site_id'],
                $payload['origin_generation'],
                0,
                $this->now() + self::PORTABLE_RETENTION_SECONDS
            );
        }
        $generation = $path === OriginProtocol::DEMAND_POLL_PATH
            ? $site['demand_generation']
            : $payload['demand_generation'];
        $retentionDeadline = $this->now() + self::PORTABLE_RETENTION_SECONDS;
        $current = $site['demand'];
        if (is_array($current) && is_array($current['demand'] ?? null)
            && ($current['demand']['demand_generation'] ?? null) === $generation) {
            $retentionDeadline = $current['demand']['retention_deadline'];
        }
        return self::receiptScope(
            'demand',
            $site['tenant_id'],
            $site['site_id'],
            $payload['origin_generation'],
            $generation,
            $retentionDeadline
        );
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $site @return array<string,mixed> */
    private function activeDemand(array $payload, array $site): array {
        if ($payload['demand_generation'] !== $site['demand_generation']) {
            throw new OriginStateRefusal('stale_demand_generation');
        }
        $record = $site['demand'];
        if (!is_array($record) || ($record['state'] ?? null) !== 'active'
            || ($record['origin_generation'] ?? null) !== $site['origin_generation']
            || !is_array($record['demand'] ?? null)) {
            throw new OriginStateRefusal('stale_demand_generation');
        }
        $demand = $record['demand'];
        if ($demand['demand_generation'] !== $payload['demand_generation']
            || $demand['demand_id'] !== $payload['demand_id']) {
            throw new OriginStateRefusal('stale_demand_generation');
        }
        if ($demand['expires_at'] <= $this->now()) {
            throw new OriginStateRefusal('demand_expired');
        }
        return $demand;
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $site @return array<string,mixed> */
    private function activeExport(array $payload, array $site): array {
        $export = $site['exports'][$payload['export_id']] ?? null;
        if ($export === null) {
            throw new OriginStateRefusal('chunk_not_announced');
        }
        $export = self::exportRecord($export, $payload['export_id']);
        if ($export['state'] !== 'announced'
            || $export['origin_generation'] !== $payload['origin_generation']
            || $export['demand_generation'] !== $payload['demand_generation']
            || $export['demand_id'] !== $payload['demand_id']
            || $export['manifest_sha256'] !== $payload['manifest_sha256']) {
            throw new OriginStateRefusal('chunk_not_announced');
        }
        return $export;
    }

    /** @param array<string,mixed> $payload */
    private static function request(string $path, array $payload): void {
        match ($path) {
            OriginProtocol::PAIR_BEGIN_PATH => self::pairBeginRequest($payload),
            OriginProtocol::PAIR_POLL_PATH => self::pairPollRequest($payload),
            OriginProtocol::DEMAND_POLL_PATH => self::demandPollRequest($payload),
            OriginProtocol::ANNOUNCE_PATH => self::announceRequest($payload),
            OriginProtocol::MISSING_PATH => self::missingRequest($payload),
            OriginProtocol::CHUNK_PATH => self::chunkRequest($payload),
            OriginProtocol::COMMIT_PATH => self::commitRequest($payload),
            OriginProtocol::ROTATE_PATH => self::rotateRequest($payload),
            OriginProtocol::REVOKE_PATH => self::revokeRequest($payload),
            default => throw new ControlRefusal('origin request escaped its closed endpoint set'),
        };
    }

    /** @param array<string,mixed> $payload */
    private static function pairBeginRequest(array $payload): void {
        self::exactKeys($payload, [
            'connector', 'device_code', 'format', 'origin_key', 'pair_attempt_id', 'request_id',
        ], 'origin pair-begin request');
        if (($payload['format'] ?? null) !== OriginProtocol::PAIR_BEGIN_REQUEST
            || !is_array($payload['connector'] ?? null) || array_is_list($payload['connector'])
            || !is_array($payload['origin_key'] ?? null) || array_is_list($payload['origin_key'])) {
            throw new ControlRefusal('origin pair-begin request is malformed');
        }
        self::connector($payload['connector']);
        self::deviceCode($payload['device_code'] ?? null);
        self::hex($payload['pair_attempt_id'] ?? null, 32, 'pair attempt id');
        self::exactKeys($payload['origin_key'], ['algorithm', 'key_id', 'public_key'], 'origin key');
        $publicKey = self::base64(
            $payload['origin_key']['public_key'] ?? null,
            SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            'origin public key'
        );
        $keyId = self::sha256($payload['origin_key']['key_id'] ?? null, 'origin key id');
        if (($payload['origin_key']['algorithm'] ?? null) !== 'Ed25519'
            || !hash_equals(OriginProtocol::originKeyId($publicKey), $keyId)) {
            throw new ControlRefusal('origin key identity is invalid');
        }
        $expected = OriginProtocol::pairBeginRequestId($payload['pair_attempt_id'], $keyId);
        if (($payload['request_id'] ?? null) !== $expected) {
            throw new ControlRefusal('origin pair-begin request id is invalid');
        }
    }

    /** @param array<string,mixed> $payload */
    private static function pairPollRequest(array $payload): void {
        self::exactKeys($payload, [
            'format', 'origin_key_id', 'pair_attempt_id', 'pairing_id', 'poll_sequence', 'request_id',
        ], 'origin pair-poll request');
        if (($payload['format'] ?? null) !== OriginProtocol::PAIR_POLL_REQUEST) {
            throw new ControlRefusal('origin pair-poll request format is invalid');
        }
        $keyId = self::sha256($payload['origin_key_id'] ?? null, 'origin key id');
        self::hex($payload['pair_attempt_id'] ?? null, 32, 'pair attempt id');
        $pairingId = self::sha256($payload['pairing_id'] ?? null, 'pairing id');
        $sequence = self::nonNegative($payload['poll_sequence'] ?? null, 'pair poll sequence');
        $expected = OriginProtocol::pairPollRequestId($payload['pair_attempt_id'], $pairingId, $sequence);
        if (($payload['request_id'] ?? null) !== $expected) {
            throw new ControlRefusal('origin pair-poll request id is invalid');
        }
        self::sha256($keyId, 'origin key id');
    }

    /** @param array<string,mixed> $payload */
    private static function demandPollRequest(array $payload): void {
        self::exactKeys($payload, [
            'after_demand_generation', 'format', 'origin_generation', 'origin_key_id',
            'poll_sequence', 'request_id', 'site_id', 'tenant_id',
        ], 'origin demand-poll request');
        if (($payload['format'] ?? null) !== OriginProtocol::DEMAND_POLL_REQUEST) {
            throw new ControlRefusal('origin demand-poll request format is invalid');
        }
        [$tenantId, $siteId, $originGeneration, $keyId] = self::originIdentity($payload);
        $after = self::nonNegative($payload['after_demand_generation'] ?? null, 'demand cursor');
        $sequence = self::nonNegative($payload['poll_sequence'] ?? null, 'demand poll sequence');
        $expected = OriginProtocol::demandPollRequestId(
            $tenantId,
            $siteId,
            $originGeneration,
            $keyId,
            $after,
            $sequence
        );
        if (($payload['request_id'] ?? null) !== $expected) {
            throw new ControlRefusal('origin demand-poll request id is invalid');
        }
    }

    /** @param array<string,mixed> $payload */
    private static function announceRequest(array $payload): void {
        self::exactKeys($payload, [
            'demand_generation', 'demand_id', 'format', 'manifest', 'origin_generation',
            'origin_key_id', 'request_id', 'site_id', 'tenant_id',
        ], 'origin export-announce request');
        if (($payload['format'] ?? null) !== OriginProtocol::ANNOUNCE_REQUEST
            || !is_array($payload['manifest'] ?? null) || array_is_list($payload['manifest'])) {
            throw new ControlRefusal('origin export-announce request is malformed');
        }
        [$tenantId, $siteId, $originGeneration, $keyId] = self::originIdentity($payload);
        $demandGeneration = self::positive($payload['demand_generation'] ?? null, 'demand generation');
        $demandId = self::sha256($payload['demand_id'] ?? null, 'demand id');
        self::manifest($payload['manifest']);
        $expected = OriginProtocol::announceRequestId(
            $tenantId,
            $siteId,
            $originGeneration,
            $keyId,
            $demandGeneration,
            $demandId,
            $payload['manifest']['manifest_sha256']
        );
        if (($payload['request_id'] ?? null) !== $expected) {
            throw new ControlRefusal('origin export-announce request id is invalid');
        }
    }

    /** @param array<string,mixed> $payload */
    private static function missingRequest(array $payload): void {
        self::exactKeys($payload, [
            'demand_generation', 'demand_id', 'export_id', 'format', 'manifest_sha256',
            'origin_generation', 'origin_key_id', 'query_sequence', 'request_id',
            'site_id', 'tenant_id',
        ], 'origin export-missing request');
        if (($payload['format'] ?? null) !== OriginProtocol::MISSING_REQUEST) {
            throw new ControlRefusal('origin export-missing request format is invalid');
        }
        [$tenantId, $siteId, $originGeneration, $keyId] = self::originIdentity($payload);
        [$demandGeneration, $demandId, $exportId, $manifestSha256] = self::exportIdentity($payload);
        $sequence = self::nonNegative($payload['query_sequence'] ?? null, 'missing query sequence');
        $expected = OriginProtocol::missingRequestId(
            $tenantId,
            $siteId,
            $originGeneration,
            $keyId,
            $demandGeneration,
            $demandId,
            $exportId,
            $manifestSha256,
            $sequence
        );
        if (($payload['request_id'] ?? null) !== $expected) {
            throw new ControlRefusal('origin export-missing request id is invalid');
        }
    }

    /** @param array<string,mixed> $payload */
    private static function chunkRequest(array $payload): void {
        self::exactKeys($payload, [
            'chunk_base64', 'chunk_sha256', 'chunk_size', 'demand_generation',
            'demand_id', 'export_id', 'format', 'manifest_sha256', 'origin_generation',
            'origin_key_id', 'request_id', 'site_id', 'tenant_id',
        ], 'origin export-chunk request');
        if (($payload['format'] ?? null) !== OriginProtocol::CHUNK_REQUEST) {
            throw new ControlRefusal('origin export-chunk request format is invalid');
        }
        [$tenantId, $siteId, $originGeneration, $keyId] = self::originIdentity($payload);
        [$demandGeneration, $demandId, $exportId, $manifestSha256] = self::exportIdentity($payload);
        $chunkSha256 = self::sha256($payload['chunk_sha256'] ?? null, 'chunk digest');
        $chunkSize = self::positive($payload['chunk_size'] ?? null, 'chunk size');
        if ($chunkSize > self::CHUNK_SIZE) {
            throw new ControlRefusal('origin export chunk exceeds one MiB');
        }
        $bytes = self::base64($payload['chunk_base64'] ?? null, $chunkSize, 'origin export chunk');
        if (!hash_equals($chunkSha256, hash('sha256', $bytes))) {
            throw new ControlRefusal('origin export chunk digest does not verify');
        }
        $expected = OriginProtocol::chunkRequestId(
            $tenantId,
            $siteId,
            $originGeneration,
            $keyId,
            $demandGeneration,
            $demandId,
            $exportId,
            $manifestSha256,
            $chunkSha256,
            $chunkSize
        );
        if (($payload['request_id'] ?? null) !== $expected) {
            throw new ControlRefusal('origin export-chunk request id is invalid');
        }
    }

    /** @param array<string,mixed> $payload */
    private static function commitRequest(array $payload): void {
        self::exactKeys($payload, [
            'demand_generation', 'demand_id', 'export_id', 'format', 'manifest_sha256',
            'origin_generation', 'origin_key_id', 'request_id', 'site_id', 'tenant_id',
        ], 'origin export-commit request');
        if (($payload['format'] ?? null) !== OriginProtocol::COMMIT_REQUEST) {
            throw new ControlRefusal('origin export-commit request format is invalid');
        }
        [$tenantId, $siteId, $originGeneration, $keyId] = self::originIdentity($payload);
        [$demandGeneration, $demandId, $exportId, $manifestSha256] = self::exportIdentity($payload);
        $expected = OriginProtocol::commitRequestId(
            $tenantId,
            $siteId,
            $originGeneration,
            $keyId,
            $demandGeneration,
            $demandId,
            $exportId,
            $manifestSha256
        );
        if (($payload['request_id'] ?? null) !== $expected) {
            throw new ControlRefusal('origin export-commit request id is invalid');
        }
    }

    /** @param array<string,mixed> $payload */
    private static function rotateRequest(array $payload): void {
        self::exactKeys($payload, [
            'format', 'new_key', 'new_key_proof', 'next_origin_generation',
            'origin_generation', 'origin_key_id', 'request_id', 'rotation_id',
            'site_id', 'tenant_id',
        ], 'origin key-rotation request');
        if (($payload['format'] ?? null) !== OriginProtocol::ROTATE_REQUEST
            || !is_array($payload['new_key'] ?? null) || array_is_list($payload['new_key'])) {
            throw new ControlRefusal('origin key-rotation request is malformed');
        }
        [$tenantId, $siteId, $originGeneration] = self::originIdentity($payload);
        $next = self::positive($payload['next_origin_generation'] ?? null, 'next origin generation');
        if ($next !== $originGeneration + 1) {
            throw new ControlRefusal('origin key rotation must advance exactly one generation');
        }
        self::exactKeys($payload['new_key'], ['algorithm', 'key_id', 'public_key'], 'new origin key');
        $newPublic = self::base64(
            $payload['new_key']['public_key'] ?? null,
            SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            'new origin public key'
        );
        $newKeyId = self::sha256($payload['new_key']['key_id'] ?? null, 'new origin key id');
        if (($payload['new_key']['algorithm'] ?? null) !== 'Ed25519'
            || !hash_equals($newKeyId, OriginProtocol::originKeyId($newPublic))
            || hash_equals($newKeyId, $payload['origin_key_id'])) {
            throw new ControlRefusal('new origin key identity is invalid');
        }
        self::base64($payload['new_key_proof'] ?? null, SODIUM_CRYPTO_SIGN_BYTES, 'new-key proof');
        $rotationId = OriginProtocol::rotationRequestId(
            $tenantId,
            $siteId,
            $originGeneration,
            $newKeyId
        );
        if (($payload['rotation_id'] ?? null) !== $rotationId
            || ($payload['request_id'] ?? null) !== $rotationId) {
            throw new ControlRefusal('origin key-rotation ids are invalid');
        }
    }

    /** @param array<string,mixed> $payload */
    private static function revokeRequest(array $payload): void {
        self::exactKeys($payload, [
            'format', 'origin_generation', 'origin_key_id', 'reason', 'request_id',
            'revocation_id', 'site_id', 'tenant_id',
        ], 'origin revoke request');
        if (($payload['format'] ?? null) !== OriginProtocol::REVOKE_REQUEST
            || !in_array($payload['reason'] ?? null, ['administrator_requested', 'uninstall'], true)) {
            throw new ControlRefusal('origin revoke request is malformed');
        }
        [$tenantId, $siteId, $originGeneration] = self::originIdentity($payload);
        $revocationId = OriginProtocol::revokeRequestId(
            $tenantId,
            $siteId,
            $originGeneration,
            $payload['reason']
        );
        if (($payload['revocation_id'] ?? null) !== $revocationId
            || ($payload['request_id'] ?? null) !== $revocationId) {
            throw new ControlRefusal('origin revocation ids are invalid');
        }
    }

    /** @param array<string,mixed> $payload @return array{string,string,int,string} */
    private static function originIdentity(array $payload): array {
        return [
            self::identifier($payload['tenant_id'] ?? null, 'tenant id'),
            self::identifier($payload['site_id'] ?? null, 'site id'),
            self::positive($payload['origin_generation'] ?? null, 'origin generation'),
            self::sha256($payload['origin_key_id'] ?? null, 'origin key id'),
        ];
    }

    /** @param array<string,mixed> $payload @return array{int,string,string,string} */
    private static function exportIdentity(array $payload): array {
        return [
            self::positive($payload['demand_generation'] ?? null, 'demand generation'),
            self::sha256($payload['demand_id'] ?? null, 'demand id'),
            self::sha256($payload['export_id'] ?? null, 'export id'),
            self::sha256($payload['manifest_sha256'] ?? null, 'manifest hash'),
        ];
    }

    /** @param array<string,mixed> $connector */
    private static function connector(array $connector): void {
        self::exactKeys($connector, [
            'agent_version', 'home_url_sha256', 'installation_id', 'multisite',
            'php_version', 'site_url_sha256', 'wordpress_version',
        ], 'origin connector');
        foreach (['agent_version', 'php_version', 'wordpress_version'] as $field) {
            if (!is_string($connector[$field] ?? null) || $connector[$field] === ''
                || strlen($connector[$field]) > 64 || preg_match('//u', $connector[$field]) !== 1
                || str_contains($connector[$field], "\0")) {
                throw new ControlRefusal("origin connector $field is invalid");
            }
        }
        self::sha256($connector['home_url_sha256'] ?? null, 'connector home URL hash');
        self::identifier($connector['installation_id'] ?? null, 'connector installation id');
        self::sha256($connector['site_url_sha256'] ?? null, 'connector site URL hash');
        if (!is_bool($connector['multisite'] ?? null)) {
            throw new ControlRefusal('origin connector multisite value is invalid');
        }
    }

    /** @param array<string,mixed> $demand */
    private static function demand(array $demand): void {
        self::exactKeys($demand, [
            'chunk_size', 'demand_generation', 'demand_id', 'expires_at',
            'expected_production_commit', 'format', 'nonce', 'retention_deadline',
            'snapshot_mode',
        ], 'origin export demand');
        if (($demand['format'] ?? null) !== OriginProtocol::DEMAND_FORMAT
            || ($demand['snapshot_mode'] ?? null) !== 'portable-refresh'
            || ($demand['chunk_size'] ?? null) !== self::CHUNK_SIZE) {
            throw new ControlRefusal('origin export demand mode is invalid');
        }
        self::positive($demand['demand_generation'] ?? null, 'demand generation');
        self::sha256($demand['demand_id'] ?? null, 'demand id');
        $expiresAt = self::timestamp($demand['expires_at'] ?? null, 'demand expiry');
        $retention = self::timestamp($demand['retention_deadline'] ?? null, 'demand retention deadline');
        if ($retention <= $expiresAt) {
            throw new ControlRefusal('demand retention deadline must outlive its expiry');
        }
        self::commitOid($demand['expected_production_commit'] ?? null, 'expected production commit');
        self::base64($demand['nonce'] ?? null, 32, 'demand nonce');
    }

    /** @param array<string,mixed> $manifest */
    private static function manifest(array $manifest): void {
        self::exactKeys($manifest, [
            'artifact_hash', 'chunks', 'code_revision', 'expected_production_commit',
            'export_sha256', 'export_size', 'format', 'generation', 'manifest_sha256',
            'repository_revision_hash', 'snapshot_hash',
        ], 'origin export manifest');
        if (($manifest['format'] ?? null) !== OriginProtocol::MANIFEST_FORMAT
            || !is_array($manifest['chunks'] ?? null) || !array_is_list($manifest['chunks'])
            || $manifest['chunks'] === []) {
            throw new ControlRefusal('origin export manifest is malformed');
        }
        self::sha256($manifest['artifact_hash'] ?? null, 'manifest artifact hash');
        if (($manifest['code_revision'] ?? null) !== null) {
            self::sha256($manifest['code_revision'], 'manifest code revision');
        }
        self::commitOid($manifest['expected_production_commit'] ?? null, 'manifest expected commit');
        self::sha256($manifest['export_sha256'] ?? null, 'manifest export hash');
        $exportSize = self::positive($manifest['export_size'] ?? null, 'manifest export size');
        if ($exportSize > self::MAX_EXPORT_SIZE) {
            throw new ControlRefusal('origin export manifest exceeds its artifact limit');
        }
        self::positive($manifest['generation'] ?? null, 'manifest generation');
        self::sha256($manifest['repository_revision_hash'] ?? null, 'manifest repository revision hash');
        self::sha256($manifest['snapshot_hash'] ?? null, 'manifest snapshot hash');
        $claimedManifestHash = self::sha256($manifest['manifest_sha256'] ?? null, 'manifest hash');
        $basis = $manifest;
        unset($basis['manifest_sha256']);
        if (!hash_equals($claimedManifestHash, hash('sha256', OriginAgentCanon::encode($basis)))) {
            throw new ControlRefusal('origin export manifest hash does not verify');
        }
        $offset = 0;
        $last = count($manifest['chunks']) - 1;
        foreach ($manifest['chunks'] as $position => $chunk) {
            if (!is_array($chunk) || array_is_list($chunk)) {
                throw new ControlRefusal('origin export manifest chunk is malformed');
            }
            self::exactKeys($chunk, ['index', 'offset', 'sha256', 'size'], 'origin manifest chunk');
            $index = self::nonNegative($chunk['index'] ?? null, 'manifest chunk index');
            $chunkOffset = self::nonNegative($chunk['offset'] ?? null, 'manifest chunk offset');
            $size = self::positive($chunk['size'] ?? null, 'manifest chunk size');
            self::sha256($chunk['sha256'] ?? null, 'manifest chunk digest');
            if ($index !== $position || $chunkOffset !== $offset || $size > self::CHUNK_SIZE
                || ($position < $last && $size !== self::CHUNK_SIZE)) {
                throw new ControlRefusal('origin export manifest chunk sequence is invalid');
            }
            $offset += $size;
            if ($offset > self::MAX_EXPORT_SIZE) {
                throw new ControlRefusal('origin export manifest chunk sequence exceeds its limit');
            }
        }
        if ($offset !== $exportSize) {
            throw new ControlRefusal('origin export manifest chunks do not cover the declared size');
        }
    }

    /** @param array<string,mixed> $manifest */
    private static function canonicalProductionExport(string $bytes, array $manifest): void {
        try {
            $exportTree = json_decode($bytes, false, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $export = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\Throwable) {
            throw new OriginStateRefusal('recovery_required');
        }
        if (!$exportTree instanceof \stdClass || !is_array($export) || array_is_list($export)) {
            throw new OriginStateRefusal('recovery_required');
        }
        try {
            if (OriginAgentCanon::encode($exportTree) !== $bytes) {
                throw new OriginStateRefusal('recovery_required');
            }
        } catch (ControlRefusal) {
            throw new OriginStateRefusal('recovery_required');
        }
        self::exactProductionKeys($export);
        if (($export['format'] ?? null) !== 'duo-refresh-production/v1'
            || !is_array($export['deletions'] ?? null) || !array_is_list($export['deletions'])
            || !is_array($export['media'] ?? null)
            || (array_is_list($export['media']) && $export['media'] !== [])
            || !is_array($export['policy'] ?? null) || array_is_list($export['policy'])
            || !is_array($export['records'] ?? null)
            || (array_is_list($export['records']) && $export['records'] !== [])
            || !is_array($export['repository'] ?? null) || array_is_list($export['repository'])
            || !is_array($export['warnings'] ?? null) || !array_is_list($export['warnings'])
            || ($export['completed_code'] !== null
                && (!is_array($export['completed_code']) || array_is_list($export['completed_code'])))) {
            throw new OriginStateRefusal('recovery_required');
        }
        try {
            self::exactKeys(
                $export['repository'],
                ['artifact_hash', 'code_revision', 'revision_hash'],
                'production export repository'
            );
            $artifactHash = self::sha256($export['repository']['artifact_hash'] ?? null, 'export artifact hash');
            $revisionHash = self::sha256($export['repository']['revision_hash'] ?? null, 'export revision hash');
            $codeRevision = $export['repository']['code_revision'] ?? null;
            if ($codeRevision !== null) {
                self::sha256($codeRevision, 'export code revision');
            }
            $snapshotHash = self::sha256($export['snapshot_hash'] ?? null, 'export snapshot hash');
        } catch (ControlRefusal) {
            throw new OriginStateRefusal('recovery_required');
        }
        $basisTree = clone $exportTree;
        unset($basisTree->snapshot_hash);
        try {
            $expectedSnapshotHash = hash('sha256', OriginAgentCanon::encode($basisTree));
        } catch (ControlRefusal) {
            throw new OriginStateRefusal('recovery_required');
        }
        if (!hash_equals($snapshotHash, $expectedSnapshotHash)
            || !hash_equals($manifest['snapshot_hash'], $snapshotHash)
            || !hash_equals($manifest['artifact_hash'], $artifactHash)
            || !hash_equals($manifest['repository_revision_hash'], $revisionHash)
            || $manifest['code_revision'] !== $codeRevision
            || !hash_equals($manifest['export_sha256'], hash('sha256', $bytes))) {
            throw new OriginStateRefusal('recovery_required');
        }
    }

    /** @param array<string,mixed> $export */
    private static function exactProductionKeys(array $export): void {
        try {
            self::exactKeys($export, [
                'completed_code', 'deletions', 'format', 'media', 'policy', 'records',
                'repository', 'snapshot_hash', 'warnings',
            ], 'production export');
        } catch (ControlRefusal) {
            throw new OriginStateRefusal('recovery_required');
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function state(array $state): array {
        if ($state === []) {
            return [
                'controller_demands' => [],
                'device_codes' => [],
                'format' => self::STORE_FORMAT,
                'keys' => [],
                'pairings' => [],
                'receipts' => [],
                'sites' => [],
            ];
        }
        self::exactKeys(
            $state,
            ['controller_demands', 'device_codes', 'format', 'keys', 'pairings', 'receipts', 'sites'],
            'origin authority store'
        );
        if (($state['format'] ?? null) !== self::STORE_FORMAT) {
            throw new ControlRefusal('origin authority store format is invalid');
        }
        foreach (['controller_demands', 'device_codes', 'keys', 'pairings', 'receipts', 'sites'] as $field) {
            if (!is_array($state[$field] ?? null)
                || (array_is_list($state[$field]) && $state[$field] !== [])) {
                throw new ControlRefusal("origin authority store $field map is invalid");
            }
        }
        foreach ($state['controller_demands'] as $requestId => $record) {
            if (!is_string($requestId)) {
                throw new ControlRefusal('origin authority controller demand map is invalid');
            }
            self::controllerDemandRecord($record, $requestId);
        }
        self::assertControllerDemandBounds($state['controller_demands']);
        foreach ($state['receipts'] as $receiptKey => $receipt) {
            if (!is_string($receiptKey)) {
                throw new ControlRefusal('origin request receipt map is corrupt');
            }
            self::receipt($receipt, $receiptKey);
        }
        self::assertEndpointReceiptBounds($state['receipts']);
        return $state;
    }

    /** @return array<string,mixed> */
    private static function controllerDemandRecord(mixed $record, string $requestId): array {
        if (!is_array($record) || array_is_list($record)) {
            throw new ControlRefusal('controller demand request record is corrupt');
        }
        self::exactKeys($record, [
            'demand', 'expected_production_commit', 'request_id', 'site_id', 'tenant_id',
        ], 'controller demand request record');
        if (($record['request_id'] ?? null) !== $requestId
            || !is_array($record['demand'] ?? null) || array_is_list($record['demand'])) {
            throw new ControlRefusal('controller demand request record is corrupt');
        }
        self::sha256($requestId, 'controller demand request id');
        self::identifier($record['tenant_id'] ?? null, 'controller demand tenant id');
        self::identifier($record['site_id'] ?? null, 'controller demand site id');
        self::commitOid(
            $record['expected_production_commit'] ?? null,
            'controller demand expected production commit'
        );
        self::demand($record['demand']);
        if ($record['demand']['expected_production_commit'] !== $record['expected_production_commit']) {
            throw new ControlRefusal('controller demand request record is corrupt');
        }
        return $record;
    }

    /** @param array<string,mixed> $state */
    private static function compactRetainedState(array &$state, int $now): bool {
        $changed = false;
        foreach ($state['pairings'] as $pairingId => $candidate) {
            $pairing = self::pairingRecord($candidate, (string) $pairingId);
            if ($pairing['state'] === 'pending' && $pairing['expires_at'] <= $now) {
                $pairing['state'] = 'expired';
                $state['pairings'][$pairingId] = $pairing;
                $siteKey = self::siteKey($pairing['tenant_id'], $pairing['site_id']);
                $site = self::siteRecord(
                    $state['sites'][$siteKey] ?? null,
                    $pairing['tenant_id'],
                    $pairing['site_id']
                );
                if ($site['pending_pairing_id'] === $pairingId) {
                    $site['pending_pairing_id'] = null;
                    $state['sites'][$siteKey] = $site;
                }
                $changed = true;
            }
            if (in_array($pairing['state'], ['denied', 'expired'], true)
                && isset($state['keys'][$pairing['origin_key_id']])) {
                $key = self::keyRecord(
                    $state['keys'][$pairing['origin_key_id']],
                    $pairing['origin_key_id']
                );
                // STORE_FORMAT stayed v1 when denial began expiring its key. Normalize
                // older denied rows before retention so their orphaned `pending` keys
                // cannot become permanent previous-generation transition blockers.
                if ($key['state'] === 'pending') {
                    $key['state'] = 'expired';
                    $state['keys'][$key['key_id']] = $key;
                    $changed = true;
                }
            }
        }

        foreach ($state['controller_demands'] as $requestId => $candidate) {
            $record = self::controllerDemandRecord($candidate, (string) $requestId);
            $site = self::siteRecord(
                $state['sites'][self::siteKey($record['tenant_id'], $record['site_id'])] ?? null,
                $record['tenant_id'],
                $record['site_id']
            );
            $generation = $record['demand']['demand_generation'];
            $currentGeneration = $site['demand_generation'];
            $currentActive = is_array($site['demand'])
                && ($site['demand']['state'] ?? null) === 'active';
            $keepCurrent = $generation === $currentGeneration
                && ($currentActive || $record['demand']['retention_deadline'] > $now);
            $keepTerminal = $currentGeneration > 0
                && $generation === $currentGeneration - 1
                && $record['demand']['retention_deadline'] > $now;
            if (!$keepCurrent && !$keepTerminal) {
                unset($state['controller_demands'][$requestId]);
                $changed = true;
            }
        }

        foreach ($state['receipts'] as $receiptKey => $candidate) {
            $receipt = self::receipt($candidate, (string) $receiptKey);
            if ($receipt === null) {
                throw new ControlRefusal('origin request receipt is corrupt');
            }
            $site = self::siteRecord(
                $state['sites'][self::siteKey($receipt['tenant_id'], $receipt['site_id'])] ?? null,
                $receipt['tenant_id'],
                $receipt['site_id']
            );
            $keep = false;
            if ($receipt['scope'] === 'origin') {
                $currentOriginGeneration = $site['origin_generation'];
                if (is_string($site['pending_pairing_id'])) {
                    $pairing = self::pairingRecord(
                        $state['pairings'][$site['pending_pairing_id']] ?? null,
                        $site['pending_pairing_id']
                    );
                    $currentOriginGeneration = max(
                        $currentOriginGeneration,
                        $pairing['origin_generation']
                    );
                } elseif ($site['state'] === 'unpaired'
                    && $receipt['endpoint'] === OriginProtocol::PAIR_BEGIN_PATH
                    && $receipt['status'] === 'reserved') {
                    $currentOriginGeneration++;
                }
                $currentTerminal = $site['state'] === 'revoked';
                $keep = $receipt['origin_generation'] === $currentOriginGeneration
                    && (!$currentTerminal || $receipt['retention_deadline'] > $now);
                $keep = $keep || ($currentOriginGeneration > 1
                    && $receipt['origin_generation'] === $currentOriginGeneration - 1
                    && $receipt['retention_deadline'] > $now);
            } elseif ($receipt['origin_generation'] === $site['origin_generation']) {
                $currentDemandActive = is_array($site['demand'])
                    && ($site['demand']['state'] ?? null) === 'active';
                $keep = $receipt['demand_generation'] === $site['demand_generation']
                    && ($currentDemandActive || $receipt['retention_deadline'] > $now);
                $keep = $keep || ($site['demand_generation'] > 0
                    && $receipt['demand_generation'] === $site['demand_generation'] - 1
                    && $receipt['retention_deadline'] > $now);
            }
            if (!$keep) {
                unset($state['receipts'][$receiptKey]);
                $changed = true;
            }
        }

        foreach ($state['device_codes'] as $digest => $candidate) {
            $record = self::deviceCodeRecord($candidate, (string) $digest);
            if ($record['state'] === 'consumed' || $record['expires_at'] <= $now) {
                unset($state['device_codes'][$digest]);
                $changed = true;
            }
        }
        if (self::trimDeviceCodeCapacity($state['device_codes'])) {
            $changed = true;
        }

        $receiptKeyIds = [];
        $pairPollReceiptKeyIds = [];
        foreach ($state['receipts'] as $receiptKey => $candidate) {
            $receipt = self::receipt($candidate, (string) $receiptKey);
            if ($receipt === null) {
                throw new ControlRefusal('origin request receipt is corrupt');
            }
            $receiptKeyIds[$receipt['key_id']] = true;
            if ($receipt['endpoint'] === OriginProtocol::PAIR_POLL_PATH) {
                $pairPollReceiptKeyIds[$receipt['key_id']] = true;
            }
        }
        $protectedPairingIds = [];
        foreach ($state['pairings'] as $pairingId => $candidate) {
            $pairing = self::pairingRecord($candidate, (string) $pairingId);
            $site = self::siteRecord(
                $state['sites'][self::siteKey($pairing['tenant_id'], $pairing['site_id'])] ?? null,
                $pairing['tenant_id'],
                $pairing['site_id']
            );
            $protected = $pairing['state'] === 'pending'
                || $site['pending_pairing_id'] === $pairingId
                || ($pairing['state'] === 'paired'
                    && $pairing['origin_generation'] === $site['origin_generation'])
                || isset($pairPollReceiptKeyIds[$pairing['origin_key_id']]);
            $keep = $protected
                || (in_array($pairing['state'], ['denied', 'expired'], true)
                    && $pairing['expires_at'] > $now - self::TERMINAL_PAIRING_RETENTION_SECONDS);
            if (!$keep) {
                unset($state['pairings'][$pairingId]);
                $changed = true;
                continue;
            }
            if ($protected) {
                $protectedPairingIds[$pairingId] = true;
            }
        }
        if (self::trimPairingCapacity($state, $protectedPairingIds)) {
            $changed = true;
        }

        $retainedPairingKeyIds = [];
        foreach ($state['pairings'] as $pairingId => $candidate) {
            $pairing = self::pairingRecord($candidate, (string) $pairingId);
            $retainedPairingKeyIds[$pairing['origin_key_id']] = true;
        }

        $previousStaleKeyIds = [];
        foreach ($state['keys'] as $keyId => $candidate) {
            $key = self::keyRecord($candidate, $keyId);
            $site = self::siteRecord(
                $state['sites'][self::siteKey($key['tenant_id'], $key['site_id'])] ?? null,
                $key['tenant_id'],
                $key['site_id']
            );
            if ($key['state'] !== 'stale' || $site['origin_generation'] < 2
                || $key['generation'] !== $site['origin_generation'] - 1) {
                continue;
            }
            $group = self::siteKey($key['tenant_id'], $key['site_id']);
            if (!isset($previousStaleKeyIds[$group])
                || strcmp($keyId, $previousStaleKeyIds[$group]) < 0) {
                $previousStaleKeyIds[$group] = $keyId;
            }
        }

        foreach ($state['keys'] as $keyId => $candidate) {
            $key = self::keyRecord($candidate, $keyId);
            $site = self::siteRecord(
                $state['sites'][self::siteKey($key['tenant_id'], $key['site_id'])] ?? null,
                $key['tenant_id'],
                $key['site_id']
            );
            $group = self::siteKey($key['tenant_id'], $key['site_id']);
            $keep = $key['state'] === 'active'
                || $site['active_key_id'] === $keyId
                || isset($receiptKeyIds[$keyId])
                || isset($retainedPairingKeyIds[$keyId])
                || ($previousStaleKeyIds[$group] ?? null) === $keyId;
            if (!$keep) {
                unset($state['keys'][$keyId]);
                $changed = true;
            }
        }
        self::assertControllerDemandBounds($state['controller_demands']);
        self::assertEndpointReceiptBounds($state['receipts']);
        return $changed;
    }

    /** @param array<string,mixed> $records */
    private static function trimDeviceCodeCapacity(array &$records): bool {
        $groups = [];
        foreach ($records as $digest => $candidate) {
            $record = self::deviceCodeRecord($candidate, (string) $digest);
            $group = self::siteKey($record['tenant_id'], $record['site_id']);
            $groups[$group][] = ['expires_at' => $record['expires_at'], 'id' => (string) $digest];
        }
        $changed = false;
        foreach ($groups as $recordsForSite) {
            if (count($recordsForSite) <= self::DEVICE_CODES_PER_SITE_LIMIT) {
                continue;
            }
            usort($recordsForSite, static function (array $left, array $right): int {
                return $left['expires_at'] <=> $right['expires_at']
                    ?: strcmp($left['id'], $right['id']);
            });
            $remove = count($recordsForSite) - self::DEVICE_CODES_PER_SITE_LIMIT;
            foreach (array_slice($recordsForSite, 0, $remove) as $record) {
                unset($records[$record['id']]);
                $changed = true;
            }
        }
        return $changed;
    }

    /** @param array<string,mixed> $state @param array<string,true> $protected */
    private static function trimPairingCapacity(array &$state, array $protected): bool {
        $groups = [];
        foreach ($state['pairings'] as $pairingId => $candidate) {
            $pairing = self::pairingRecord($candidate, (string) $pairingId);
            $siteKey = self::siteKey($pairing['tenant_id'], $pairing['site_id']);
            $groups[$siteKey]['records'][] = [
                'expires_at' => $pairing['expires_at'],
                'id' => (string) $pairingId,
                'protected' => isset($protected[$pairingId]),
            ];
        }
        $changed = false;
        foreach ($groups as $group) {
            $remove = count($group['records']) - self::PAIRINGS_PER_SITE_LIMIT;
            if ($remove < 1) {
                continue;
            }
            $candidates = array_values(array_filter(
                $group['records'],
                static fn(array $record): bool => !$record['protected']
            ));
            usort($candidates, static function (array $left, array $right): int {
                return $left['expires_at'] <=> $right['expires_at']
                    ?: strcmp($left['id'], $right['id']);
            });
            foreach (array_slice($candidates, 0, $remove) as $record) {
                unset($state['pairings'][$record['id']]);
                $changed = true;
            }
        }
        return $changed;
    }

    /** @param array<string,mixed> $state */
    private static function releaseTransitionCapacity(
        array &$state,
        string $tenantId,
        string $siteId,
        bool $needsPairing,
        int $now
    ): bool {
        $changed = false;
        while (true) {
            $pairingCount = self::authorityRecordCount(
                $state['pairings'],
                $tenantId,
                $siteId,
                'pairing'
            );
            $keyCount = self::authorityRecordCount($state['keys'], $tenantId, $siteId, 'key');
            if ((!$needsPairing || $pairingCount < self::PAIRINGS_PER_SITE_LIMIT)
                && $keyCount < self::ORIGIN_KEYS_PER_SITE_LIMIT) {
                break;
            }
            $pairPollReceiptKeyIds = [];
            foreach ($state['receipts'] as $receiptKey => $candidate) {
                $receipt = self::receipt($candidate, (string) $receiptKey);
                if ($receipt === null) {
                    throw new ControlRefusal('origin request receipt is corrupt');
                }
                if ($receipt['endpoint'] === OriginProtocol::PAIR_POLL_PATH) {
                    $pairPollReceiptKeyIds[$receipt['key_id']] = true;
                }
            }
            $site = self::siteRecord(
                $state['sites'][self::siteKey($tenantId, $siteId)] ?? null,
                $tenantId,
                $siteId
            );
            $candidates = [];
            foreach ($state['pairings'] as $pairingId => $candidate) {
                $pairing = self::pairingRecord($candidate, (string) $pairingId);
                if ($pairing['tenant_id'] !== $tenantId || $pairing['site_id'] !== $siteId
                    || !in_array($pairing['state'], ['denied', 'expired'], true)
                    || $site['pending_pairing_id'] === $pairingId
                    || isset($pairPollReceiptKeyIds[$pairing['origin_key_id']])) {
                    continue;
                }
                $candidates[] = [
                    'expires_at' => $pairing['expires_at'],
                    'id' => (string) $pairingId,
                ];
            }
            usort($candidates, static function (array $left, array $right): int {
                return $left['expires_at'] <=> $right['expires_at']
                    ?: strcmp($left['id'], $right['id']);
            });
            if ($candidates === []) {
                break;
            }
            unset($state['pairings'][$candidates[0]['id']]);
            self::compactRetainedState($state, $now);
            $changed = true;
        }
        return $changed;
    }

    /** @param array<string,mixed> $records */
    private static function assertNewDeviceCodeCapacity(array $records, string $tenantId, string $siteId): void {
        if (self::authorityRecordCount($records, $tenantId, $siteId, 'device-code')
            >= self::DEVICE_CODES_PER_SITE_LIMIT) {
            throw new ControlRefusal('origin device-code capacity is exhausted');
        }
    }

    /** @param array<string,mixed> $records */
    private static function assertNewPairingCapacity(array $records, string $tenantId, string $siteId): void {
        if (self::authorityRecordCount($records, $tenantId, $siteId, 'pairing')
            >= self::PAIRINGS_PER_SITE_LIMIT) {
            throw new ControlRefusal('origin pairing capacity is exhausted');
        }
    }

    /** @param array<string,mixed> $records */
    private static function assertNewOriginKeyCapacity(array $records, string $tenantId, string $siteId): void {
        if (self::authorityRecordCount($records, $tenantId, $siteId, 'key')
            >= self::ORIGIN_KEYS_PER_SITE_LIMIT) {
            throw new ControlRefusal('origin key capacity is exhausted');
        }
    }

    /** @param array<string,mixed> $records */
    private static function authorityRecordCount(
        array $records,
        string $tenantId,
        string $siteId,
        string $kind
    ): int {
        $count = 0;
        foreach ($records as $recordId => $candidate) {
            $record = self::authorityRecord($candidate, (string) $recordId, $kind);
            if ($record['tenant_id'] === $tenantId && $record['site_id'] === $siteId) {
                $count++;
            }
        }
        return $count;
    }

    /** @return array<string,mixed> */
    private static function authorityRecord(mixed $record, string $recordId, string $kind): array {
        return match ($kind) {
            'device-code' => self::deviceCodeRecord($record, $recordId),
            'key' => self::keyRecord($record, $recordId),
            'pairing' => self::pairingRecord($record, $recordId),
            default => throw new ControlRefusal('origin authority record kind is invalid'),
        };
    }

    /** @param array<string,mixed> $records */
    private static function assertControllerDemandBounds(array $records): void {
        $groups = [];
        foreach ($records as $requestId => $candidate) {
            $record = self::controllerDemandRecord($candidate, (string) $requestId);
            $group = $record['tenant_id'] . "\0" . $record['site_id'] . "\0"
                . $record['demand']['demand_generation'];
            $groups[$group]['count'] = ($groups[$group]['count'] ?? 0) + 1;
            $groups[$group]['bytes'] = ($groups[$group]['bytes'] ?? 0)
                + strlen((string) $requestId) + strlen(CanonicalJson::encode($record));
        }
        foreach ($groups as $group) {
            if ($group['count'] > self::CONTROLLER_DEMANDS_PER_GENERATION_LIMIT) {
                throw new ControlRefusal('controller demand receipt count exceeds its per-generation limit');
            }
            if ($group['bytes'] > self::CONTROLLER_DEMANDS_PER_GENERATION_BYTES_LIMIT) {
                throw new ControlRefusal('controller demand receipt bytes exceed its per-generation limit');
            }
        }
    }

    /** @param array<string,mixed> $receipts */
    private static function assertEndpointReceiptBounds(array $receipts): void {
        $groups = [];
        foreach ($receipts as $receiptKey => $candidate) {
            $receipt = self::receipt($candidate, (string) $receiptKey);
            if ($receipt === null) {
                throw new ControlRefusal('origin request receipt is corrupt');
            }
            $group = $receipt['scope'] . "\0" . $receipt['tenant_id'] . "\0"
                . $receipt['site_id'] . "\0" . $receipt['origin_generation'] . "\0"
                . $receipt['demand_generation'];
            $groups[$group]['count'] = ($groups[$group]['count'] ?? 0) + 1;
            $groups[$group]['bytes'] = ($groups[$group]['bytes'] ?? 0)
                + strlen((string) $receiptKey) + strlen(CanonicalJson::encode($receipt));
        }
        foreach ($groups as $group) {
            if ($group['count'] > self::ENDPOINT_RECEIPTS_PER_GENERATION_LIMIT) {
                throw new ControlRefusal('origin endpoint receipt count exceeds its per-generation limit');
            }
            if ($group['bytes'] > self::ENDPOINT_RECEIPTS_PER_GENERATION_BYTES_LIMIT) {
                throw new ControlRefusal('origin endpoint receipt bytes exceed its per-generation limit');
            }
        }
    }

    /** @param array<string,mixed> $receipts */
    private static function assertEndpointDispatchCapacity(array $receipts, string $reservedReceiptKey): void {
        self::assertEndpointReceiptBounds($receipts);
        $reserved = self::receipt($receipts[$reservedReceiptKey] ?? null, $reservedReceiptKey);
        if ($reserved === null || $reserved['status'] !== 'reserved') {
            throw new ControlRefusal('origin request receipt reservation is corrupt');
        }
        $reservedGroup = $reserved['scope'] . "\0" . $reserved['tenant_id'] . "\0"
            . $reserved['site_id'] . "\0" . $reserved['origin_generation'] . "\0"
            . $reserved['demand_generation'];
        $bytes = 0;
        foreach ($receipts as $receiptKey => $candidate) {
            $receipt = self::receipt($candidate, (string) $receiptKey);
            if ($receipt === null) {
                throw new ControlRefusal('origin request receipt is corrupt');
            }
            $group = $receipt['scope'] . "\0" . $receipt['tenant_id'] . "\0"
                . $receipt['site_id'] . "\0" . $receipt['origin_generation'] . "\0"
                . $receipt['demand_generation'];
            if ($group === $reservedGroup) {
                $bytes += strlen((string) $receiptKey) + strlen(CanonicalJson::encode($receipt));
            }
        }
        if ($bytes + self::MAX_CACHED_RESPONSE_BASE64_BYTES + 512
            > self::ENDPOINT_RECEIPTS_PER_GENERATION_BYTES_LIMIT) {
            throw new ControlRefusal(
                'origin endpoint has insufficient receipt bytes for the maximum response'
            );
        }
    }

    /** @return array<string,mixed> */
    private static function receiptScope(
        string $scope,
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        int $retentionDeadline
    ): array {
        if (!in_array($scope, ['demand', 'origin'], true)
            || ($scope === 'origin' && $demandGeneration !== 0)) {
            throw new ControlRefusal('origin request receipt scope is invalid');
        }
        self::identifier($tenantId, 'receipt tenant id');
        self::identifier($siteId, 'receipt site id');
        self::positive($originGeneration, 'receipt origin generation');
        self::nonNegative($demandGeneration, 'receipt demand generation');
        self::timestamp($retentionDeadline, 'receipt retention deadline');
        return [
            'demand_generation' => $demandGeneration,
            'origin_generation' => $originGeneration,
            'retention_deadline' => $retentionDeadline,
            'scope' => $scope,
            'site_id' => $siteId,
            'tenant_id' => $tenantId,
        ];
    }

    /** @param array<string,mixed> $receipt @param array<string,mixed> $scope */
    private static function assertReceiptScope(array $receipt, array $scope): void {
        foreach ($scope as $field => $value) {
            if (($receipt[$field] ?? null) !== $value) {
                throw new ControlRefusal('origin request receipt scope is corrupt');
            }
        }
    }

    /** @return array<string,mixed> */
    private static function deviceCodeRecord(mixed $record, string $digest): array {
        if (!is_array($record) || array_is_list($record)) {
            throw new ControlRefusal('device code is unknown, expired, or already consumed');
        }
        self::exactKeys($record, [
            'consumed_pairing_id', 'digest', 'expires_at', 'site_id', 'state', 'tenant_id',
        ], 'device-code record');
        if (($record['digest'] ?? null) !== $digest
            || !in_array($record['state'] ?? null, ['issued', 'consumed'], true)
            || (($record['consumed_pairing_id'] ?? null) !== null
                && !self::isSha256($record['consumed_pairing_id']))) {
            throw new ControlRefusal('device-code record is corrupt');
        }
        self::sha256($record['digest'], 'device-code digest');
        self::timestamp($record['expires_at'] ?? null, 'device-code expiry');
        self::identifier($record['tenant_id'] ?? null, 'device-code tenant id');
        self::identifier($record['site_id'] ?? null, 'device-code site id');
        return $record;
    }

    /** @return array<string,mixed> */
    private static function pairingRecord(mixed $record, string $pairingId): array {
        if (!is_array($record) || array_is_list($record)) {
            throw new ControlRefusal('origin pairing is unavailable');
        }
        self::exactKeys($record, [
            'expires_at', 'origin_generation', 'origin_key_id', 'pair_attempt_id',
            'pairing_id', 'site_id', 'state', 'tenant_id',
        ], 'origin pairing record');
        if (($record['pairing_id'] ?? null) !== $pairingId
            || !in_array($record['state'] ?? null, ['pending', 'paired', 'denied', 'expired'], true)) {
            throw new ControlRefusal('origin pairing record is corrupt');
        }
        self::sha256($pairingId, 'pairing id');
        self::timestamp($record['expires_at'] ?? null, 'pairing expiry');
        self::positive($record['origin_generation'] ?? null, 'pairing origin generation');
        self::sha256($record['origin_key_id'] ?? null, 'pairing origin key id');
        self::hex($record['pair_attempt_id'] ?? null, 32, 'pair attempt id');
        self::identifier($record['tenant_id'] ?? null, 'pairing tenant id');
        self::identifier($record['site_id'] ?? null, 'pairing site id');
        return $record;
    }

    /** @return array<string,mixed> */
    private static function keyRecord(mixed $record, mixed $keyId): array {
        $keyId = self::sha256($keyId, 'origin key id');
        if (!is_array($record) || array_is_list($record)) {
            throw new ControlRefusal('origin request key is unknown');
        }
        self::exactKeys(
            $record,
            ['generation', 'key_id', 'public_key', 'site_id', 'state', 'tenant_id'],
            'origin key record'
        );
        if (($record['key_id'] ?? null) !== $keyId
            || !in_array($record['state'] ?? null, ['active', 'expired', 'pending', 'revoked', 'stale'], true)) {
            throw new ControlRefusal('origin key record is corrupt');
        }
        self::positive($record['generation'] ?? null, 'origin key generation');
        self::base64($record['public_key'] ?? null, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'origin key');
        self::identifier($record['tenant_id'] ?? null, 'origin key tenant id');
        self::identifier($record['site_id'] ?? null, 'origin key site id');
        return $record;
    }

    /** @return array<string,mixed> */
    private static function siteRecord(mixed $record, string $tenantId, string $siteId): array {
        if (!is_array($record) || array_is_list($record)) {
            throw new ControlRefusal('origin site authority is unavailable');
        }
        self::exactKeys($record, [
            'active_key_id', 'demand', 'demand_generation', 'exports',
            'last_terminal_generation', 'origin_generation', 'pending_pairing_id',
            'site_id', 'state', 'tenant_id',
        ], 'origin site record');
        if (($record['tenant_id'] ?? null) !== $tenantId || ($record['site_id'] ?? null) !== $siteId
            || !in_array($record['state'] ?? null, ['active', 'revoked', 'unpaired'], true)
            || (($record['active_key_id'] ?? null) !== null && !self::isSha256($record['active_key_id']))
            || (($record['pending_pairing_id'] ?? null) !== null
                && !self::isSha256($record['pending_pairing_id']))
            || !is_array($record['exports'] ?? null)
            || (array_is_list($record['exports']) && $record['exports'] !== [])) {
            throw new ControlRefusal('origin site record is corrupt');
        }
        self::identifier($tenantId, 'site tenant id');
        self::identifier($siteId, 'site id');
        $originGeneration = self::nonNegative($record['origin_generation'] ?? null, 'site origin generation');
        $demandGeneration = self::nonNegative($record['demand_generation'] ?? null, 'site demand generation');
        $lastTerminal = self::nonNegative(
            $record['last_terminal_generation'] ?? null,
            'site last terminal generation'
        );
        if ($lastTerminal > $demandGeneration || ($record['state'] === 'active' && $originGeneration < 1)) {
            throw new ControlRefusal('origin site generations are corrupt');
        }
        if ($record['demand'] !== null) {
            if (!is_array($record['demand']) || array_is_list($record['demand'])) {
                throw new ControlRefusal('origin site demand record is corrupt');
            }
            self::exactKeys($record['demand'], ['demand', 'origin_generation', 'state'], 'site demand record');
            if (!is_array($record['demand']['demand'] ?? null)
                || array_is_list($record['demand']['demand'])
                || !in_array($record['demand']['state'] ?? null, [
                    'active', 'committed', 'expired', 'revoked',
                ], true)) {
                throw new ControlRefusal('origin site demand record is corrupt');
            }
            self::demand($record['demand']['demand']);
            self::positive($record['demand']['origin_generation'] ?? null, 'demand origin generation');
        }
        if (count($record['exports']) > self::RETAINED_EXPORTS_PER_SITE_LIMIT) {
            throw new ControlRefusal('origin site retained export capacity is corrupt');
        }
        $retainedBytes = 0;
        foreach ($record['exports'] as $exportId => $export) {
            if (!is_string($exportId)) {
                throw new ControlRefusal('origin site export map is corrupt');
            }
            $export = self::exportRecord($export, $exportId);
            $retainedBytes += self::exportReservationBytes($export['manifest']['export_size']);
            if ($retainedBytes > self::RETAINED_EXPORT_BYTES_PER_SITE_LIMIT) {
                throw new ControlRefusal('origin site retained export capacity is corrupt');
            }
        }
        return $record;
    }

    /** @return array<string,mixed> */
    private static function exportRecord(mixed $record, string $exportId): array {
        if (!is_array($record) || array_is_list($record)) {
            throw new ControlRefusal('origin export record is corrupt');
        }
        self::exactKeys($record, [
            'demand_generation', 'demand_id', 'export_id', 'manifest',
            'manifest_sha256', 'origin_generation', 'retention_deadline', 'state',
        ], 'origin export record');
        if (($record['export_id'] ?? null) !== $exportId
            || !in_array($record['state'] ?? null, ['announced', 'committed', 'reaping', 'revoked'], true)
            || !is_array($record['manifest'] ?? null) || array_is_list($record['manifest'])) {
            throw new ControlRefusal('origin export record is corrupt');
        }
        self::sha256($exportId, 'export id');
        self::positive($record['demand_generation'] ?? null, 'export demand generation');
        self::sha256($record['demand_id'] ?? null, 'export demand id');
        self::positive($record['origin_generation'] ?? null, 'export origin generation');
        self::timestamp($record['retention_deadline'] ?? null, 'export retention deadline');
        self::sha256($record['manifest_sha256'] ?? null, 'export manifest hash');
        self::manifest($record['manifest']);
        if ($record['manifest']['manifest_sha256'] !== $record['manifest_sha256']) {
            throw new ControlRefusal('origin export manifest identity is corrupt');
        }
        return $record;
    }

    /** @return ?array<string,mixed> */
    private static function receipt(mixed $receipt, string $receiptKey): ?array {
        if ($receipt === null) {
            return null;
        }
        if (!is_array($receipt) || array_is_list($receipt)) {
            throw new ControlRefusal('origin request receipt is corrupt');
        }
        $status = $receipt['status'] ?? null;
        $keys = $status === 'complete'
            ? [
                'demand_generation', 'endpoint', 'key_id', 'origin_generation', 'request_id',
                'request_sha256', 'response_base64', 'response_sha256', 'retention_deadline',
                'scope', 'site_id', 'status', 'tenant_id',
            ]
            : [
                'demand_generation', 'endpoint', 'key_id', 'origin_generation', 'request_id',
                'request_sha256', 'retention_deadline', 'scope', 'site_id', 'status', 'tenant_id',
            ];
        self::exactKeys($receipt, $keys, 'origin request receipt');
        $keyId = self::sha256($receipt['key_id'] ?? null, 'receipt key id');
        $requestId = self::sha256($receipt['request_id'] ?? null, 'receipt request id');
        self::sha256($receipt['request_sha256'] ?? null, 'receipt request hash');
        if ($receiptKey !== self::receiptKey($keyId, $requestId)
            || !in_array($receipt['endpoint'] ?? null, self::paths(), true)
            || !in_array($receipt['scope'] ?? null, ['demand', 'origin'], true)
            || !in_array($status, ['complete', 'reserved'], true)) {
            throw new ControlRefusal('origin request receipt identity is corrupt');
        }
        self::identifier($receipt['tenant_id'] ?? null, 'receipt tenant id');
        self::identifier($receipt['site_id'] ?? null, 'receipt site id');
        self::positive($receipt['origin_generation'] ?? null, 'receipt origin generation');
        self::nonNegative($receipt['demand_generation'] ?? null, 'receipt demand generation');
        self::timestamp($receipt['retention_deadline'] ?? null, 'receipt retention deadline');
        if ($receipt['scope'] === 'origin' && $receipt['demand_generation'] !== 0) {
            throw new ControlRefusal('origin request receipt scope is corrupt');
        }
        if ($status === 'complete') {
            self::sha256($receipt['response_sha256'] ?? null, 'receipt response hash');
            self::cachedResponse($receipt);
        }
        return $receipt;
    }

    /** @param array<string,mixed> $state */
    private static function reserve(
        array &$state,
        string $receiptKey,
        string $endpoint,
        string $keyId,
        string $requestId,
        string $requestHash,
        array $scope
    ): void {
        $state['receipts'][$receiptKey] = $scope + [
            'endpoint' => $endpoint,
            'key_id' => $keyId,
            'request_id' => $requestId,
            'request_sha256' => $requestHash,
            'status' => 'reserved',
        ];
        self::assertEndpointDispatchCapacity($state['receipts'], $receiptKey);
    }

    /** @param array<string,mixed> $state */
    private static function complete(array &$state, string $receiptKey, string $response): void {
        $receipt = self::receipt($state['receipts'][$receiptKey] ?? null, $receiptKey);
        if ($receipt === null || $receipt['status'] !== 'reserved') {
            throw new ControlRefusal('origin response has no durable request reservation');
        }
        $receipt['response_base64'] = base64_encode($response);
        $receipt['response_sha256'] = hash('sha256', $response);
        $receipt['status'] = 'complete';
        $state['receipts'][$receiptKey] = $receipt;
        self::assertEndpointReceiptBounds($state['receipts']);
    }

    /** @param array<string,mixed> $receipt */
    private static function assertReplay(
        array $receipt,
        string $endpoint,
        string $keyId,
        string $requestId,
        string $requestHash
    ): void {
        if ($receipt['endpoint'] !== $endpoint || $receipt['key_id'] !== $keyId
            || $receipt['request_id'] !== $requestId
            || !hash_equals($receipt['request_sha256'], $requestHash)) {
            throw new ControlRefusal('origin request id was replayed with changed signed bytes');
        }
    }

    /** @param array<string,mixed> $receipt */
    private static function cachedResponse(array $receipt): string {
        $encoded = $receipt['response_base64'] ?? null;
        $expectedHash = $receipt['response_sha256'] ?? null;
        $response = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_string($response) || base64_encode($response) !== $encoded
            || !is_string($expectedHash) || !hash_equals($expectedHash, hash('sha256', $response))) {
            throw new ControlRefusal('cached signed origin response is corrupt');
        }
        CanonicalJson::decodeObject($response, self::RESPONSE_LIMIT);
        return $response;
    }

    /** @param array<string,mixed> $payload */
    private function signed(array $payload): string {
        $payloadBytes = CanonicalJson::encode($payload);
        $envelope = [
            'format' => OriginProtocol::ENVELOPE_FORMAT,
            'key_id' => $this->serviceKeyId,
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                $payloadBytes,
                $this->serviceSecretKey
            )),
        ];
        $bytes = CanonicalJson::encode($envelope) . "\n";
        if (strlen($bytes) > self::RESPONSE_LIMIT) {
            throw new ControlRefusal('signed origin response exceeds its byte limit');
        }
        return $bytes;
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function controllerSite(array $state, string $tenantId, string $siteId): array {
        $siteKey = self::siteKey($tenantId, $siteId);
        try {
            return self::siteRecord($state['sites'][$siteKey] ?? null, $tenantId, $siteId);
        } catch (\Throwable $error) {
            throw new ControlRefusal('committed export is unavailable', 0, $error);
        }
    }

    /** @param array<string,mixed> $site @return array<string,mixed> */
    private static function controllerExport(array $site, string $exportId): array {
        try {
            $export = self::exportRecord($site['exports'][$exportId] ?? null, $exportId);
        } catch (\Throwable $error) {
            throw new ControlRefusal('committed export is unavailable', 0, $error);
        }
        if ($export['state'] !== 'committed') {
            throw new ControlRefusal('committed export is unavailable');
        }
        return $export;
    }

    /** @param array<string,mixed> $export */
    private function assertRetained(array $export): void {
        if ($export['retention_deadline'] <= $this->now()) {
            throw new ControlRefusal('committed export is unavailable');
        }
    }

    /**
     * @param array<string,mixed> $state
     * @return array<string,mixed>
     */
    private static function controllerDemand(
        array $state,
        string $tenantId,
        string $siteId,
        int $demandGeneration,
        string $demandId,
        string $expectedProductionCommit
    ): array {
        foreach ($state['controller_demands'] as $requestId => $candidate) {
            try {
                $record = self::controllerDemandRecord($candidate, (string) $requestId);
            } catch (\Throwable $error) {
                throw new ControlRefusal('portable export demand is unavailable', 0, $error);
            }
            $demand = $record['demand'];
            if ($record['tenant_id'] === $tenantId && $record['site_id'] === $siteId
                && $demand['demand_generation'] === $demandGeneration
                && $demand['demand_id'] === $demandId
                && $record['expected_production_commit'] === $expectedProductionCommit) {
                return $record;
            }
        }
        throw new ControlRefusal('portable export demand is unavailable');
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function controllerPortableSite(array $state, string $tenantId, string $siteId): array {
        try {
            return self::siteRecord(
                $state['sites'][self::siteKey($tenantId, $siteId)] ?? null,
                $tenantId,
                $siteId
            );
        } catch (\Throwable $error) {
            throw new ControlRefusal('portable export demand is unavailable', 0, $error);
        }
    }

    /**
     * @param array<string,mixed> $site
     * @return array<string,mixed>
     */
    private static function controllerPortableExport(
        array $site,
        int $demandGeneration,
        string $demandId,
        string $expectedProductionCommit,
        string $exportId
    ): array {
        try {
            $export = self::exportRecord($site['exports'][$exportId] ?? null, $exportId);
        } catch (\Throwable $error) {
            throw new ControlRefusal('committed export is unavailable', 0, $error);
        }
        if ($export['state'] !== 'committed'
            || $export['demand_generation'] !== $demandGeneration
            || $export['demand_id'] !== $demandId
            || $export['manifest']['generation'] !== $demandGeneration
            || $export['manifest']['expected_production_commit'] !== $expectedProductionCommit) {
            throw new ControlRefusal('committed export is unavailable');
        }
        return $export;
    }

    /** @param array<string,mixed> $site */
    private static function siteBusy(array $site, int $now): bool {
        return is_array($site['demand'] ?? null)
            && ($site['demand']['state'] ?? null) === 'active'
            && (int) ($site['demand']['demand']['expires_at'] ?? 0) > $now;
    }

    /** @param array<string,mixed> $site */
    private static function assertNewDemandCapacity(array $site): void {
        [$count, $bytes] = self::retainedExportUsage($site);
        $reservation = self::exportReservationBytes(self::MAX_EXPORT_SIZE);
        if ($count >= self::RETAINED_EXPORTS_PER_SITE_LIMIT
            || $bytes > self::RETAINED_EXPORT_BYTES_PER_SITE_LIMIT - $reservation) {
            throw new ControlRefusal('origin retained export capacity is exhausted');
        }
    }

    /** @param array<string,mixed> $site */
    private static function assertNewExportCapacity(array $site, int $exportSize): void {
        [$count, $bytes] = self::retainedExportUsage($site);
        $reservation = self::exportReservationBytes($exportSize);
        if ($count >= self::RETAINED_EXPORTS_PER_SITE_LIMIT
            || $bytes > self::RETAINED_EXPORT_BYTES_PER_SITE_LIMIT - $reservation) {
            throw new OriginStateRefusal('rate_limited', true);
        }
    }

    /** @param array<string,mixed> $site @return array{int,int} */
    private static function retainedExportUsage(array $site): array {
        $count = 0;
        $bytes = 0;
        foreach (($site['exports'] ?? []) as $exportId => $record) {
            if (!is_string($exportId)) {
                throw new ControlRefusal('origin site export map is corrupt');
            }
            $export = self::exportRecord($record, $exportId);
            $count++;
            $bytes += self::exportReservationBytes($export['manifest']['export_size']);
            if ($count > self::RETAINED_EXPORTS_PER_SITE_LIMIT
                || $bytes > self::RETAINED_EXPORT_BYTES_PER_SITE_LIMIT) {
                throw new ControlRefusal('origin site retained export capacity is corrupt');
            }
        }
        return [$count, $bytes];
    }

    private static function exportReservationBytes(int $exportSize): int {
        if ($exportSize < 1 || $exportSize > self::MAX_EXPORT_SIZE) {
            throw new ControlRefusal('origin export reservation size is invalid');
        }
        // Chunks and the assembled artifact can coexist for the signed
        // retention window. One MiB per export bounds its manifest, directory
        // entries, and filesystem allocation overhead without trusting origin
        // supplied accounting.
        return ($exportSize * 2) + self::RETAINED_EXPORT_OVERHEAD_BYTES;
    }

    private static function siteKey(string $tenantId, string $siteId): string {
        return hash('sha256', "duo-cloud-origin-site/v1\0$tenantId\0$siteId");
    }

    private static function receiptKey(string $keyId, string $requestId): string {
        return hash('sha256', "duo-cloud-origin-receipt/v1\0$keyId\0$requestId");
    }

    private function deviceCodeDigest(string $deviceCode): string {
        return hash_hmac(
            'sha256',
            "duo-cloud-origin-device-code/v1\0$deviceCode",
            $this->deviceDigestKey
        );
    }

    private static function randomDeviceCode(): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $symbols = '';
        for ($index = 0; $index < 20; $index++) {
            $symbols .= $alphabet[random_int(0, 31)];
        }
        return implode('-', str_split($symbols, 5));
    }

    private function now(): int {
        $now = ($this->clock)();
        if ($now < 1 || $now > 9007199254740991) {
            throw new ControlRefusal('origin authority clock returned an invalid timestamp');
        }
        return $now;
    }

    private static function deviceCode(mixed $value): string {
        if (!is_string($value)
            || preg_match('/^[A-Z2-7]{5}(?:-[A-Z2-7]{5}){3}$/D', $value) !== 1) {
            throw new ControlRefusal('origin device code is malformed');
        }
        return $value;
    }

    private static function identifier(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) !== 1) {
            throw new ControlRefusal("$label is invalid");
        }
        return $value;
    }

    private static function sha256(mixed $value, string $label): string {
        if (!self::isSha256($value)) {
            throw new ControlRefusal("$label must be lowercase SHA-256");
        }
        return $value;
    }

    private static function isSha256(mixed $value): bool {
        return is_string($value) && preg_match('/^[a-f0-9]{64}$/D', $value) === 1;
    }

    private static function hex(mixed $value, int $length, string $label): string {
        if (!is_string($value) || preg_match('/^[a-f0-9]{' . $length . '}$/D', $value) !== 1) {
            throw new ControlRefusal("$label must be $length lowercase hexadecimal characters");
        }
        return $value;
    }

    private static function commitOid(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/^(?:[a-f0-9]{40}|[a-f0-9]{64})$/D', $value) !== 1) {
            throw new ControlRefusal("$label must be a lowercase 40- or 64-hex Git oid");
        }
        return $value;
    }

    private static function nonNegative(mixed $value, string $label): int {
        if (!is_int($value) || $value < 0 || $value > 9007199254740991) {
            throw new ControlRefusal("$label must be a non-negative safe integer");
        }
        return $value;
    }

    private static function positive(mixed $value, string $label): int {
        $value = self::nonNegative($value, $label);
        if ($value < 1) {
            throw new ControlRefusal("$label must be positive");
        }
        return $value;
    }

    private static function timestamp(mixed $value, string $label): int {
        return self::positive($value, $label);
    }

    private static function base64(mixed $value, int $length, string $label): string {
        $decoded = is_string($value) ? base64_decode($value, true) : false;
        if (!is_string($decoded) || strlen($decoded) !== $length || base64_encode($decoded) !== $value) {
            throw new ControlRefusal("$label is not canonical base64 of the required size");
        }
        return $decoded;
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
