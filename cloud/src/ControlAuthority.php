<?php
declare(strict_types=1);

namespace Duo\Cloud;

require_once __DIR__ . '/ControlRefusal.php';
require_once __DIR__ . '/CanonicalJson.php';
require_once __DIR__ . '/CommandRunner.php';
require_once __DIR__ . '/FileAuthorityStore.php';

/**
 * Durable signed-command authority for isolated Duo Cloud preview workloads.
 *
 * A command is durably reserved before runner dispatch. If the process dies
 * after dispatch but before receipt publication, that request remains
 * `executing` and cannot be run a second time: an operator must reconcile the
 * worker rather than turning an uncertain side effect into a duplicate one.
 */
final class ControlAuthority {
    private const ENVELOPE_FORMAT = 'duo-cloud-preview-signed-envelope/v1';
    private const REQUEST_FORMAT = 'duo-cloud-preview-control-request/v1';
    private const RESPONSE_FORMAT = 'duo-cloud-preview-control-response/v1';
    private const STORE_FORMAT = 'duo-cloud-preview-authority-store/v1';
    private const RESPONSE_LIMIT = 16777216;
    private const RECEIPTS_PER_GENERATION_LIMIT = 256;
    private const RECEIPTS_PER_GENERATION_BYTES_LIMIT = 25165824;
    private const MAX_CACHED_RESPONSE_BASE64_BYTES = 22369624;

    private FileAuthorityStore $store;
    private CommandRunner $runner;
    private string $responseKeyId;
    private string $responseSecretKey;

    public function __construct(
        FileAuthorityStore $store,
        CommandRunner $runner,
        string $responseKeyId,
        string $responseSecretKey
    ) {
        self::identifier($responseKeyId, 'response key id');
        if (strlen($responseSecretKey) !== SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new ControlRefusal('response signing key is not an Ed25519 secret key');
        }
        $this->store = $store;
        $this->runner = $runner;
        $this->responseKeyId = $responseKeyId;
        $this->responseSecretKey = $responseSecretKey;
    }

    public function __destruct() {
        if ($this->responseSecretKey !== '') {
            sodium_memzero($this->responseSecretKey);
        }
    }

    /**
     * Bind one controller request key to exactly one tenant/site principal.
     * Rotation uses a new key id; an existing id can only be replayed exactly.
     */
    public function registerRequestKey(
        string $keyId,
        string $tenantId,
        string $siteId,
        string $publicKey
    ): void {
        self::identifier($keyId, 'request key id');
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        if (strlen($publicKey) !== SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES) {
            throw new ControlRefusal('request verification key is not an Ed25519 public key');
        }
        $record = [
            'key_id' => $keyId,
            'public_key' => base64_encode($publicKey),
            'site_id' => $siteId,
            'state' => 'active',
            'tenant_id' => $tenantId,
        ];
        $this->store->locked(function (AuthorityStateSession $session) use ($keyId, $record): void {
            $state = self::normalizedState($session->state());
            self::assertState($state);
            $existing = $state['request_keys'][$keyId] ?? null;
            if ($existing !== null) {
                if (!is_array($existing)
                    || CanonicalJson::encode($existing) !== CanonicalJson::encode($record)) {
                    throw new ControlRefusal('request key id is already bound to different authority');
                }
                return;
            }
            $state['request_keys'][$keyId] = $record;
            $session->save($state);
        });
    }

    public function revokeRequestKey(string $keyId, string $tenantId, string $siteId): void {
        self::identifier($keyId, 'request key id');
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        $this->store->locked(function (AuthorityStateSession $session) use ($keyId, $tenantId, $siteId): void {
            $state = self::normalizedState($session->state());
            self::assertState($state);
            $record = $state['request_keys'][$keyId] ?? null;
            if (!is_array($record)
                || ($record['tenant_id'] ?? null) !== $tenantId
                || ($record['site_id'] ?? null) !== $siteId) {
                throw new ControlRefusal('request key does not belong to the named tenant and site');
            }
            if (($record['state'] ?? null) === 'revoked') {
                return;
            }
            $record['state'] = 'revoked';
            $state['request_keys'][$keyId] = $record;
            $session->save($state);
        });
    }

    /**
     * Authenticate a controller request for a sibling closed service API.
     *
     * Lifecycle authorization remains inside PreviewSlotLifecycle; this
     * method grants only tenant/site identity, never resource or mutation
     * authority. The caller supplies its already-canonical payload bytes so
     * the exact signed representation is the one verified here.
     */
    public function authorizeRequestKey(
        string $keyId,
        string $tenantId,
        string $siteId,
        string $payloadBytes,
        string $signature
    ): void {
        self::identifier($keyId, 'request key id');
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        if ($payloadBytes === '' || strlen($payloadBytes) > 1048576
            || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            throw new ControlRefusal('controller identity request is malformed');
        }
        $this->store->locked(function (AuthorityStateSession $session) use (
            $keyId,
            $tenantId,
            $siteId,
            $payloadBytes,
            $signature
        ): void {
            $state = self::normalizedState($session->state());
            self::assertState($state);
            $key = $state['request_keys'][$keyId] ?? null;
            if (!is_array($key)
                || ($key['state'] ?? null) !== 'active'
                || ($key['tenant_id'] ?? null) !== $tenantId
                || ($key['site_id'] ?? null) !== $siteId) {
                throw new ControlRefusal('request key is not active for the signed tenant and site');
            }
            $publicKey = self::canonicalKey(
                $key['public_key'] ?? null,
                SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
                'request key'
            );
            if (!sodium_crypto_sign_verify_detached($signature, $payloadBytes, $publicKey)) {
                throw new ControlRefusal('request signature is invalid');
            }
        });
    }

    /** @param array<string,mixed> $target */
    public function holdAuthority(
        string $tenantId,
        string $siteId,
        string $operationId,
        array $target
    ): void {
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        self::operationId($operationId);
        self::target($target, $operationId);
        $siteKey = self::siteKey($tenantId, $siteId);
        $this->store->locked(function (AuthorityStateSession $session) use (
            $siteKey,
            $tenantId,
            $siteId,
            $operationId,
            $target
        ): void {
            $state = self::normalizedState($session->state());
            self::assertState($state);
            $site = $state['sites'][$siteKey] ?? null;
            if ($site !== null && (!is_array($site)
                || ($site['tenant_id'] ?? null) !== $tenantId
                || ($site['site_id'] ?? null) !== $siteId)) {
                throw new ControlRefusal('tenant/site authority key collision');
            }
            $current = is_array($site) ? ($site['current'] ?? null) : null;
            if (is_array($current) && ($current['state'] ?? null) === 'held') {
                if (($current['operation_id'] ?? null) === $operationId
                    && CanonicalJson::encode($current['target'] ?? null) === CanonicalJson::encode($target)) {
                    return;
                }
                throw new ControlRefusal('tenant/site already has a different held preview authority');
            }
            $lastLeaseGeneration = is_array($site) ? ($site['last_lease_generation'] ?? 0) : 0;
            $lastMutationGeneration = is_array($site) ? ($site['last_mutation_generation'] ?? 0) : 0;
            if (!is_int($lastLeaseGeneration) || !is_int($lastMutationGeneration)
                || $target['lease_generation'] < $lastLeaseGeneration) {
                throw new ControlRefusal('new preview authority cannot move the site lease generation backward');
            }
            if ($target['lease_generation'] === $lastLeaseGeneration) {
                $previousTarget = is_array($current) ? ($current['target'] ?? null) : null;
                if (!is_array($previousTarget)) {
                    throw new ControlRefusal('same-lease preview authority has no prior resource identity');
                }
                foreach ([
                    'environment_identity', 'lease_id', 'ownership_receipt_sha256', 'resource_id',
                ] as $field) {
                    if (($target[$field] ?? null) !== ($previousTarget[$field] ?? null)) {
                        throw new ControlRefusal("same-lease preview authority changed resource identity '$field'");
                    }
                }
                if ($target['mutation_generation'] <= $lastMutationGeneration) {
                    throw new ControlRefusal('same-lease preview authority must advance the site mutation generation');
                }
            }
            $terminal = null;
            if (is_array($site) && is_array($current)
                && ($current['state'] ?? null) === 'released') {
                $terminal = [
                    'authority' => $current,
                    'receipts' => $site['receipts'],
                ];
            }
            $state['sites'][$siteKey] = [
                'current' => [
                    'operation_id' => $operationId,
                    'state' => 'held',
                    'target' => $target,
                ],
                'last_lease_generation' => $target['lease_generation'],
                'last_mutation_generation' => $target['mutation_generation'],
                // New requests can use only the held fence. The immediately
                // preceding released fence remains as an exact-response
                // replay window and is replaced on the next transition.
                'receipts' => [],
                'site_id' => $siteId,
                'terminal' => $terminal,
                'tenant_id' => $tenantId,
            ];
            $session->save($state);
        });
    }

    /** @param array<string,mixed> $target */
    public function releaseAuthority(
        string $tenantId,
        string $siteId,
        string $operationId,
        array $target
    ): void {
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        self::operationId($operationId);
        self::target($target, $operationId);
        $siteKey = self::siteKey($tenantId, $siteId);
        $this->store->locked(function (AuthorityStateSession $session) use (
            $siteKey,
            $tenantId,
            $siteId,
            $operationId,
            $target
        ): void {
            $state = self::normalizedState($session->state());
            self::assertState($state);
            $site = $state['sites'][$siteKey] ?? null;
            if (!is_array($site)
                || ($site['tenant_id'] ?? null) !== $tenantId
                || ($site['site_id'] ?? null) !== $siteId
                || !is_array($site['current'] ?? null)) {
                throw new ControlRefusal('tenant/site has no current preview authority');
            }
            $current = $site['current'];
            if (($current['operation_id'] ?? null) !== $operationId
                || CanonicalJson::encode($current['target'] ?? null) !== CanonicalJson::encode($target)) {
                throw new ControlRefusal('release does not match the current preview authority');
            }
            if (($current['state'] ?? null) === 'released') {
                return;
            }
            if (($current['state'] ?? null) !== 'held') {
                throw new ControlRefusal('current preview authority has an invalid state');
            }
            $site['current']['state'] = 'released';
            $state['sites'][$siteKey] = $site;
            $session->save($state);
        });
    }

    /** @return ?array{operation_id:string,state:string,target:array<string,mixed>} */
    public function currentAuthority(string $tenantId, string $siteId): ?array {
        self::identifier($tenantId, 'tenant id');
        self::identifier($siteId, 'site id');
        $siteKey = self::siteKey($tenantId, $siteId);
        return $this->store->locked(function (AuthorityStateSession $session) use (
            $siteKey,
            $tenantId,
            $siteId
        ): ?array {
            $state = self::normalizedState($session->state());
            self::assertState($state);
            $site = $state['sites'][$siteKey] ?? null;
            if ($site === null) {
                return null;
            }
            if (!is_array($site)
                || ($site['tenant_id'] ?? null) !== $tenantId
                || ($site['site_id'] ?? null) !== $siteId
                || !is_array($site['current'] ?? null)) {
                throw new ControlRefusal('tenant/site authority record is corrupt');
            }
            return $site['current'];
        });
    }

    /**
     * Verify, authorize, durably reserve, execute, sign, and cache one command.
     * Returns the canonical signed response including its single trailing LF.
     */
    public function handle(string $requestBytes): string {
        $envelope = CanonicalJson::decodeObject($requestBytes);
        self::exactKeys($envelope, ['format', 'key_id', 'payload', 'signature'], 'signed request');
        if (($envelope['format'] ?? null) !== self::ENVELOPE_FORMAT
            || !is_array($envelope['payload'] ?? null)
            || array_is_list($envelope['payload'])) {
            throw new ControlRefusal('signed request envelope is malformed');
        }
        $keyId = self::identifier($envelope['key_id'] ?? null, 'request key id');
        $payload = $envelope['payload'];
        self::requestPayload($payload);
        $signature = self::signature($envelope['signature'] ?? null, 'request signature');
        $payloadBytes = CanonicalJson::encode($payload);
        $requestHash = hash('sha256', $payloadBytes);
        $tenantId = $payload['tenant_id'];
        $siteId = $payload['site_id'];
        $requestId = $payload['request_id'];
        $siteKey = self::siteKey($tenantId, $siteId);
        $receiptKey = self::receiptKey($keyId, $requestId);

        return $this->store->locked(function (AuthorityStateSession $session) use (
            $keyId,
            $payload,
            $payloadBytes,
            $signature,
            $requestHash,
            $tenantId,
            $siteId,
            $requestId,
            $siteKey,
            $receiptKey
        ): string {
            $state = self::normalizedState($session->state());
            self::assertState($state);
            $key = $state['request_keys'][$keyId] ?? null;
            if (!is_array($key)
                || ($key['state'] ?? null) !== 'active'
                || ($key['tenant_id'] ?? null) !== $tenantId
                || ($key['site_id'] ?? null) !== $siteId) {
                throw new ControlRefusal('request key is not active for the signed tenant and site');
            }
            $publicKey = self::canonicalKey($key['public_key'] ?? null, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'request key');
            if (!sodium_crypto_sign_verify_detached($signature, $payloadBytes, $publicKey)) {
                throw new ControlRefusal('request signature is invalid');
            }
            $site = $state['sites'][$siteKey] ?? null;
            if (!is_array($site)
                || ($site['tenant_id'] ?? null) !== $tenantId
                || ($site['site_id'] ?? null) !== $siteId) {
                throw new ControlRefusal('signed tenant and site have no preview authority');
            }
            $current = $site['current'] ?? null;
            $terminal = $site['terminal'] ?? null;
            $currentMatches = is_array($current)
                && ($current['operation_id'] ?? null) === $payload['operation_id']
                && CanonicalJson::encode($current['target'] ?? null) === CanonicalJson::encode($payload['target']);
            $terminalAuthority = is_array($terminal) ? ($terminal['authority'] ?? null) : null;
            $terminalMatches = is_array($terminalAuthority)
                && ($terminalAuthority['operation_id'] ?? null) === $payload['operation_id']
                && CanonicalJson::encode($terminalAuthority['target'] ?? null)
                    === CanonicalJson::encode($payload['target']);
            if (!$currentMatches && !$terminalMatches) {
                throw new ControlRefusal('request does not name the exact current held preview authority');
            }
            $receipts = $terminalMatches ? $terminal['receipts'] : $site['receipts'];
            $receipt = $receipts[$receiptKey] ?? null;
            if ($receipt !== null) {
                if (!is_array($receipt)
                    || ($receipt['key_id'] ?? null) !== $keyId
                    || ($receipt['request_id'] ?? null) !== $requestId
                    || !hash_equals((string) ($receipt['request_sha256'] ?? ''), $requestHash)) {
                    throw new ControlRefusal('request id was replayed with changed signed bytes');
                }
                if (($receipt['status'] ?? null) === 'complete') {
                    return self::cachedResponse($receipt);
                }
                throw new ControlRefusal('request has an indeterminate prior execution and cannot be dispatched again');
            }
            if ($terminalMatches || ($current['state'] ?? null) !== 'held') {
                throw new ControlRefusal('request does not name the exact current held preview authority');
            }

            $site['receipts'][$receiptKey] = [
                'key_id' => $keyId,
                'request_id' => $requestId,
                'request_sha256' => $requestHash,
                'status' => 'executing',
            ];
            self::assertDispatchReceiptCapacity($site['receipts']);
            $state['sites'][$siteKey] = $site;
            $session->save($state);

            try {
                $result = $this->runner->run($payload);
                self::commandResult($result);
                $response = $this->signedResponse($payload, $requestHash, $result);
            } catch (\Throwable $error) {
                throw new ControlRefusal(
                    'workload command outcome is indeterminate; its durable reservation was retained',
                    0,
                    $error
                );
            }

            $site = $state['sites'][$siteKey];
            $site['receipts'][$receiptKey] = [
                'key_id' => $keyId,
                'request_id' => $requestId,
                'request_sha256' => $requestHash,
                'response_base64' => base64_encode($response),
                'response_sha256' => hash('sha256', $response),
                'status' => 'complete',
            ];
            self::assertReceiptSetBounds($site['receipts'], 'current preview authority');
            $state['sites'][$siteKey] = $site;
            $session->save($state);
            return $response;
        });
    }

    /** @param array<string,mixed> $payload @param array{exit:int,stderr:string,stdout:string} $result */
    private function signedResponse(array $payload, string $requestHash, array $result): string {
        $responsePayload = [
            'action' => $payload['action'],
            'environment' => $payload['environment'],
            'format' => self::RESPONSE_FORMAT,
            'operation_id' => $payload['operation_id'],
            'request_sha256' => $requestHash,
            'result' => $result,
            'site_id' => $payload['site_id'],
            'target' => $payload['target'],
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
        $bytes = CanonicalJson::encode($response) . "\n";
        if (strlen($bytes) > self::RESPONSE_LIMIT) {
            throw new ControlRefusal('signed command response exceeds the controller evidence limit');
        }
        return $bytes;
    }

    /** @param array<string,mixed> $receipt */
    private static function cachedResponse(array $receipt): string {
        $encoded = $receipt['response_base64'] ?? null;
        $expectedHash = $receipt['response_sha256'] ?? null;
        $response = is_string($encoded) ? base64_decode($encoded, true) : false;
        if (!is_string($response) || base64_encode($response) !== $encoded
            || !is_string($expectedHash) || !hash_equals($expectedHash, hash('sha256', $response))) {
            throw new ControlRefusal('cached signed response is corrupt');
        }
        CanonicalJson::decodeObject($response, self::RESPONSE_LIMIT);
        return $response;
    }

    /** @param array<string,mixed> $payload */
    private static function requestPayload(array $payload): void {
        self::exactKeys($payload, [
            'action', 'command_index', 'command_phase', 'environment', 'format',
            'input', 'operation_id', 'request_id', 'site_id', 'target', 'tenant_id',
        ], 'control request payload');
        if (($payload['format'] ?? null) !== self::REQUEST_FORMAT
            || !in_array($payload['action'] ?? null, ['raw', 'wp'], true)) {
            throw new ControlRefusal('control request format or action is invalid');
        }
        self::environment($payload['environment'] ?? null);
        $tenantId = self::identifier($payload['tenant_id'] ?? null, 'tenant id');
        $siteId = self::identifier($payload['site_id'] ?? null, 'site id');
        $operationId = self::operationId($payload['operation_id'] ?? null);
        $phase = $payload['command_phase'] ?? null;
        $index = $payload['command_index'] ?? null;
        if (!is_string($phase) || preg_match('/^[a-z][a-z0-9-]{0,63}$/D', $phase) !== 1
            || !is_int($index) || $index < 0) {
            throw new ControlRefusal('control command phase or index is invalid');
        }
        $expectedId = hash(
            'sha256',
            "duo-cloud-preview-command/v1\0$tenantId\0$siteId\0$operationId\0$phase\0$index"
        );
        if (($payload['request_id'] ?? null) !== $expectedId) {
            throw new ControlRefusal('control request id is not derived from its journal command phase and index');
        }
        if (!is_array($payload['target'] ?? null) || array_is_list($payload['target'])) {
            throw new ControlRefusal('control request target must be an object');
        }
        self::target($payload['target'], $operationId);
        if (!is_array($payload['input'] ?? null) || array_is_list($payload['input'])) {
            throw new ControlRefusal('control request input must be an object');
        }
        $input = $payload['input'];
        if ($payload['action'] === 'raw') {
            self::exactKeys($input, ['script'], 'raw command input');
            if (!is_string($input['script'] ?? null) || $input['script'] === ''
                || str_contains($input['script'], "\0")) {
                throw new ControlRefusal('raw command script is invalid');
            }
            return;
        }
        self::exactKeys($input, ['argv'], 'WordPress command input');
        $arguments = $input['argv'] ?? null;
        if (!is_array($arguments) || !array_is_list($arguments) || $arguments === []) {
            throw new ControlRefusal('WordPress command argv is invalid');
        }
        foreach ($arguments as $argument) {
            if (!is_string($argument) || $argument === '' || str_contains($argument, "\0")) {
                throw new ControlRefusal('WordPress command argv contains an invalid argument');
            }
        }
    }

    /** @param array<string,mixed> $target */
    private static function target(array $target, string $operationId): void {
        self::exactKeys($target, [
            'environment_identity', 'lease_generation', 'lease_id', 'mutation_generation',
            'mutation_id', 'mutation_owner', 'mutation_receipt_sha256',
            'ownership_receipt_sha256', 'resource_id',
        ], 'preview target');
        foreach (['environment_identity', 'lease_id', 'mutation_id', 'mutation_owner', 'resource_id'] as $field) {
            self::identifier($target[$field] ?? null, "preview target $field");
        }
        foreach (['lease_generation', 'mutation_generation'] as $field) {
            if (!is_int($target[$field] ?? null) || $target[$field] < 1) {
                throw new ControlRefusal("preview target $field must be a positive integer");
            }
        }
        foreach (['mutation_receipt_sha256', 'ownership_receipt_sha256'] as $field) {
            self::sha256($target[$field] ?? null, "preview target $field");
        }
        if ($target['mutation_owner'] !== 'duo-env-materialize-' . $operationId) {
            throw new ControlRefusal('preview target mutation owner is not bound to the materialization operation');
        }
    }

    /** @param array<string,mixed> $result */
    private static function commandResult(array $result): void {
        self::exactKeys($result, ['exit', 'stderr', 'stdout'], 'workload command result');
        if (!is_int($result['exit'] ?? null) || $result['exit'] < 0 || $result['exit'] > 255
            || !is_string($result['stdout'] ?? null) || !is_string($result['stderr'] ?? null)
            || preg_match('//u', $result['stdout']) !== 1 || preg_match('//u', $result['stderr']) !== 1) {
            throw new ControlRefusal('workload command result is malformed or not UTF-8');
        }
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function normalizedState(array $state): array {
        return $state === [] ? [
            'format' => self::STORE_FORMAT,
            'request_keys' => [],
            'sites' => [],
        ] : $state;
    }

    /** @param array<string,mixed> $state */
    private static function assertState(array $state): void {
        self::exactKeys($state, ['format', 'request_keys', 'sites'], 'authority store');
        if (($state['format'] ?? null) !== self::STORE_FORMAT
            || !is_array($state['request_keys'] ?? null)
            || !is_array($state['sites'] ?? null)
            || (array_is_list($state['request_keys']) && $state['request_keys'] !== [])
            || (array_is_list($state['sites']) && $state['sites'] !== [])) {
            throw new ControlRefusal('authority store shape or format is invalid');
        }
        foreach ($state['request_keys'] as $keyId => $record) {
            if (!is_string($keyId) || !is_array($record) || array_is_list($record)) {
                throw new ControlRefusal('authority request-key map is invalid');
            }
            self::exactKeys($record, ['key_id', 'public_key', 'site_id', 'state', 'tenant_id'], 'request key record');
            self::identifier($keyId, 'request key id');
            if (($record['key_id'] ?? null) !== $keyId
                || !in_array($record['state'] ?? null, ['active', 'revoked'], true)) {
                throw new ControlRefusal('request key record identity or state is invalid');
            }
            self::identifier($record['tenant_id'] ?? null, 'request key tenant id');
            self::identifier($record['site_id'] ?? null, 'request key site id');
            self::canonicalKey($record['public_key'] ?? null, SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES, 'request key');
        }
        foreach ($state['sites'] as $siteKey => $site) {
            if (!is_string($siteKey) || !is_array($site) || array_is_list($site)) {
                throw new ControlRefusal('authority site map is invalid');
            }
            self::exactKeys(
                $site,
                [
                    'current', 'last_lease_generation', 'last_mutation_generation',
                    'receipts', 'site_id', 'terminal', 'tenant_id',
                ],
                'site authority record'
            );
            $tenantId = self::identifier($site['tenant_id'] ?? null, 'site authority tenant id');
            $siteId = self::identifier($site['site_id'] ?? null, 'site authority site id');
            if ($siteKey !== self::siteKey($tenantId, $siteId)
                || !is_int($site['last_lease_generation'] ?? null)
                || $site['last_lease_generation'] < 1
                || !is_int($site['last_mutation_generation'] ?? null)
                || $site['last_mutation_generation'] < 1
                || !is_array($site['current'] ?? null)
                || array_is_list($site['current'])
                || !is_array($site['receipts'] ?? null)
                || (array_is_list($site['receipts']) && $site['receipts'] !== [])) {
                throw new ControlRefusal('site authority record shape is invalid');
            }
            $current = $site['current'];
            self::exactKeys($current, ['operation_id', 'state', 'target'], 'current preview authority');
            $operationId = self::operationId($current['operation_id'] ?? null);
            if (!in_array($current['state'] ?? null, ['held', 'released'], true)
                || !is_array($current['target'] ?? null)
                || array_is_list($current['target'])) {
                throw new ControlRefusal('current preview authority state or target is invalid');
            }
            self::target($current['target'], $operationId);
            if ($current['target']['lease_generation'] !== $site['last_lease_generation']) {
                throw new ControlRefusal('site authority generation does not match its current target');
            }
            if ($current['target']['mutation_generation'] !== $site['last_mutation_generation']) {
                throw new ControlRefusal('site mutation generation does not match its current target');
            }
            foreach ($site['receipts'] as $receiptKey => $receipt) {
                self::assertReceipt($receiptKey, $receipt);
            }
            self::assertReceiptSetBounds($site['receipts'], 'current preview authority');
            if ($site['terminal'] !== null) {
                if (!is_array($site['terminal']) || array_is_list($site['terminal'])) {
                    throw new ControlRefusal('terminal preview authority record is invalid');
                }
                self::exactKeys($site['terminal'], ['authority', 'receipts'], 'terminal preview authority');
                $terminal = $site['terminal']['authority'] ?? null;
                $terminalReceipts = $site['terminal']['receipts'] ?? null;
                if (!is_array($terminal) || array_is_list($terminal)
                    || !is_array($terminalReceipts)
                    || (array_is_list($terminalReceipts) && $terminalReceipts !== [])) {
                    throw new ControlRefusal('terminal preview authority record is invalid');
                }
                self::exactKeys($terminal, ['operation_id', 'state', 'target'], 'terminal authority fence');
                $terminalOperationId = self::operationId($terminal['operation_id'] ?? null);
                if (($terminal['state'] ?? null) !== 'released'
                    || !is_array($terminal['target'] ?? null) || array_is_list($terminal['target'])) {
                    throw new ControlRefusal('terminal preview authority record is invalid');
                }
                self::target($terminal['target'], $terminalOperationId);
                foreach ($terminalReceipts as $receiptKey => $receipt) {
                    self::assertReceipt($receiptKey, $receipt);
                }
                self::assertReceiptSetBounds($terminalReceipts, 'terminal preview authority');
            }
        }
    }

    /** @param array<string,mixed> $receipts */
    private static function assertReceiptSetBounds(array $receipts, string $label): void {
        if (count($receipts) > self::RECEIPTS_PER_GENERATION_LIMIT) {
            throw new ControlRefusal("$label receipt count exceeds its per-generation limit");
        }
        if (strlen(CanonicalJson::encode($receipts)) > self::RECEIPTS_PER_GENERATION_BYTES_LIMIT) {
            throw new ControlRefusal("$label receipt bytes exceed its per-generation limit");
        }
    }

    /** @param array<string,mixed> $receipts */
    private static function assertDispatchReceiptCapacity(array $receipts): void {
        self::assertReceiptSetBounds($receipts, 'current preview authority');
        if (strlen(CanonicalJson::encode($receipts))
            + self::MAX_CACHED_RESPONSE_BASE64_BYTES + 512
            > self::RECEIPTS_PER_GENERATION_BYTES_LIMIT) {
            throw new ControlRefusal(
                'current preview authority has insufficient receipt bytes for the maximum response'
            );
        }
    }

    private static function assertReceipt(mixed $receiptKey, mixed $receipt): void {
        if (!is_string($receiptKey) || preg_match('/^[a-f0-9]{64}$/D', $receiptKey) !== 1
            || !is_array($receipt) || array_is_list($receipt)) {
            throw new ControlRefusal('command receipt map is invalid');
        }
        $status = $receipt['status'] ?? null;
        $keys = $status === 'complete'
            ? ['key_id', 'request_id', 'request_sha256', 'response_base64', 'response_sha256', 'status']
            : ['key_id', 'request_id', 'request_sha256', 'status'];
        self::exactKeys($receipt, $keys, 'command receipt');
        $keyId = self::identifier($receipt['key_id'] ?? null, 'receipt key id');
        $requestId = self::sha256($receipt['request_id'] ?? null, 'receipt request id');
        self::sha256($receipt['request_sha256'] ?? null, 'receipt request hash');
        if ($receiptKey !== self::receiptKey($keyId, $requestId)
            || !in_array($status, ['executing', 'complete'], true)) {
            throw new ControlRefusal('command receipt identity or status is invalid');
        }
        if ($status === 'complete') {
            self::sha256($receipt['response_sha256'] ?? null, 'receipt response hash');
            self::cachedResponse($receipt);
        }
    }

    private static function siteKey(string $tenantId, string $siteId): string {
        return hash('sha256', "duo-cloud-preview-site/v1\0$tenantId\0$siteId");
    }

    private static function receiptKey(string $keyId, string $requestId): string {
        return hash('sha256', "duo-cloud-preview-receipt/v1\0$keyId\0$requestId");
    }

    private static function environment(mixed $value): string {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$/D', $value) !== 1) {
            throw new ControlRefusal('environment name is invalid');
        }
        return $value;
    }

    private static function identifier(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{0,127}$/D', $value) !== 1) {
            throw new ControlRefusal("$label is invalid");
        }
        return $value;
    }

    private static function operationId(mixed $value): string {
        if (!is_string($value) || preg_match('/^[0-9]{8}-[0-9]{6}-[a-f0-9]{24}$/D', $value) !== 1) {
            throw new ControlRefusal('operation id is invalid');
        }
        return $value;
    }

    private static function sha256(mixed $value, string $label): string {
        if (!is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new ControlRefusal("$label must be lowercase SHA-256");
        }
        return $value;
    }

    private static function signature(mixed $value, string $label): string {
        return self::canonicalKey($value, SODIUM_CRYPTO_SIGN_BYTES, $label);
    }

    private static function canonicalKey(mixed $value, int $length, string $label): string {
        $decoded = is_string($value) ? base64_decode($value, true) : false;
        if (!is_string($decoded) || strlen($decoded) !== $length || base64_encode($decoded) !== $value) {
            throw new ControlRefusal("$label is not canonical base64 key material");
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
