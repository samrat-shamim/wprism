<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Canon;
use Duo\OriginCloudClient;
use Duo\OriginCloudRefusal;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/agent/src/Cloud/OriginCloudClient.php';

/** @internal */
final class ClientWireServer {
    public string $secretKey;
    public string $publicKey;
    public string $keyId = 'service-key-v1';
    /** @var list<array{body:string,path:string,payload:array<string,mixed>}> */
    public array $calls = [];

    public function __construct() {
        $pair = sodium_crypto_sign_keypair();
        $this->secretKey = sodium_crypto_sign_secretkey($pair);
        $this->publicKey = sodium_crypto_sign_publickey($pair);
    }

    public function __destruct() {
        if ($this->secretKey !== '') {
            sodium_memzero($this->secretKey);
        }
    }

    /**
     * @param callable(string,array<string,mixed>,string):array<string,mixed> $responder
     * @return \Closure(string,string,int,int):array<string,mixed>
     */
    public function exchange(callable $responder): \Closure {
        return function (string $url, string $body, int $timeout, int $limit) use ($responder): array {
            TestCase::assertGreaterThanOrEqual(1, $timeout);
            TestCase::assertLessThanOrEqual(30, $timeout);
            TestCase::assertSame(1048576, $limit);
            $path = parse_url($url, PHP_URL_PATH);
            TestCase::assertIsString($path);
            $envelope = self::decode($body);
            TestCase::assertSame(
                ['format', 'key_id', 'payload', 'signature'],
                self::keys($envelope)
            );
            TestCase::assertSame(OriginCloudClient::ENVELOPE_FORMAT, $envelope['format']);
            TestCase::assertIsArray($envelope['payload']);
            $payload = $envelope['payload'];
            $this->calls[] = ['body' => $body, 'path' => $path, 'payload' => $payload];
            $response = $responder($path, $payload, hash('sha256', self::encode($payload)));
            return [
                'body' => $this->signed($response),
                'effective_url' => $url,
                'redirected' => false,
                'status' => 200,
            ];
        };
    }

    /** @param array<string,mixed> $payload */
    public function signed(array $payload, ?string $keyId = null, ?string $secretKey = null): string {
        $secretKey ??= $this->secretKey;
        return self::encode([
            'format' => OriginCloudClient::ENVELOPE_FORMAT,
            'key_id' => $keyId ?? $this->keyId,
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached(self::encode($payload), $secretKey)),
        ]) . "\n";
    }

    /** @return array<string,mixed> */
    public static function decode(string $bytes): array {
        $decoded = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        TestCase::assertIsArray($decoded);
        TestCase::assertFalse(array_is_list($decoded));
        TestCase::assertSame(self::encode($decoded) . "\n", $bytes);
        return $decoded;
    }

    public static function encode(mixed $value): string {
        if (is_array($value)) {
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            foreach ($value as $key => $child) {
                $value[$key] = self::normalize($child);
            }
        }
        return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
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

    /** @param array<string,mixed> $value @return list<string> */
    public static function keys(array $value): array {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        return $keys;
    }

    /** @param list<string|int> $fields */
    public static function id(string $domain, array $fields): string {
        return hash('sha256', implode("\0", array_map(static fn (string|int $v): string => (string) $v, [
            $domain,
            ...$fields,
        ])));
    }
}

#[CoversNothing]
final class OriginCloudClientTest extends TestCase {
    private string|false $priorTestMode;
    private string $originSecret;
    private string $originPublic;
    private ClientWireServer $server;

    protected function setUp(): void {
        $this->priorTestMode = getenv('DUO_TEST_MODE');
        putenv('DUO_TEST_MODE=1');
        $pair = sodium_crypto_sign_keypair();
        $this->originSecret = sodium_crypto_sign_secretkey($pair);
        $this->originPublic = sodium_crypto_sign_publickey($pair);
        $this->server = new ClientWireServer();
    }

    protected function tearDown(): void {
        if ($this->priorTestMode === false) {
            putenv('DUO_TEST_MODE');
        } else {
            putenv('DUO_TEST_MODE=' . $this->priorTestMode);
        }
        if (isset($this->originSecret) && $this->originSecret !== '') {
            sodium_memzero($this->originSecret);
        }
    }

    public function testPairBeginIsCanonicalSignedAndExactlyRetryDeterministic(): void {
        $client = $this->client($this->server->exchange(function (
            string $path,
            array $payload,
            string $requestHash
        ): array {
            self::assertSame('/v1/origin/pair/begin', $path);
            self::assertSame([
                'connector', 'device_code', 'format', 'origin_key', 'pair_attempt_id', 'request_id',
            ], ClientWireServer::keys($payload));
            self::assertSame([
                'algorithm', 'key_id', 'public_key',
            ], ClientWireServer::keys($payload['origin_key']));
            self::assertSame('Ed25519', $payload['origin_key']['algorithm']);
            self::assertSame(base64_encode($this->originPublic), $payload['origin_key']['public_key']);
            self::assertSame(
                ClientWireServer::id('duo-cloud-origin-pair-begin/v1', [
                    $payload['pair_attempt_id'],
                    $payload['origin_key']['key_id'],
                ]),
                $payload['request_id']
            );
            return [
                'expires_at' => 1800000600,
                'format' => 'duo-cloud-origin-pair-begin-response/v1',
                'origin_key_id' => $payload['origin_key']['key_id'],
                'pair_attempt_id' => $payload['pair_attempt_id'],
                'pairing_id' => 'pairing-a',
                'poll_after_seconds' => 2,
                'request_sha256' => $requestHash,
                'state' => 'pending',
            ];
        }));
        $attempt = str_repeat('a', 32);
        $connector = $this->connector();

        $first = $client->pairBegin('ABCDE-FGHIJ-KLMNO-PQRST', $connector, $attempt);
        $second = $client->pairBegin('ABCDE-FGHIJ-KLMNO-PQRST', $connector, $attempt);

        self::assertSame($first, $second);
        self::assertCount(2, $this->server->calls);
        self::assertSame($this->server->calls[0]['body'], $this->server->calls[1]['body']);
        self::assertStringEndsWith("\n", $this->server->calls[0]['body']);
        self::assertSame(1, substr_count($this->server->calls[0]['body'], "\n"));
        self::assertStringNotContainsString(base64_encode($this->originSecret), $this->server->calls[0]['body']);
    }

    public function testWrongSignatureKeyHashIdentityAndRedirectAreAllRejected(): void {
        $this->assertPairPollRejected(function (array $payload, string $hash): string {
            $other = sodium_crypto_sign_keypair();
            $response = $this->pairPollResponse($payload, $hash);
            return $this->server->signed(
                $response,
                $this->server->keyId,
                sodium_crypto_sign_secretkey($other)
            );
        }, 'signature is invalid');

        $this->assertPairPollRejected(
            fn (array $payload, string $hash): string => $this->server->signed(
                $this->pairPollResponse($payload, $hash),
                'foreign-service-key'
            ),
            'envelope identity is invalid'
        );

        $this->assertPairPollRejected(function (array $payload, string $hash): string {
            $response = $this->pairPollResponse($payload, $hash);
            $response['request_sha256'] = str_repeat('f', 64);
            return $this->server->signed($response);
        }, 'bind the exact request');

        $this->assertPairPollRejected(function (array $payload, string $hash): string {
            $response = $this->pairPollResponse($payload, $hash);
            $response['origin_key_id'] = str_repeat('f', 64);
            return $this->server->signed($response);
        }, 'identity does not match');

        $foreignSite = $this->client($this->server->exchange(function (
            string $path,
            array $payload,
            string $requestHash
        ): array {
            return [
                'after_demand_generation' => $payload['after_demand_generation'],
                'demand' => null,
                'format' => 'duo-cloud-origin-demand-poll-response/v1',
                'origin_generation' => $payload['origin_generation'],
                'origin_key_id' => $payload['origin_key_id'],
                'poll_after_seconds' => 2,
                'poll_sequence' => $payload['poll_sequence'],
                'request_sha256' => $requestHash,
                'site_id' => 'foreign-site',
                'state' => 'idle',
                'tenant_id' => $payload['tenant_id'],
            ];
        }));
        $this->assertRuntimeException(
            fn (): array => $foreignSite->demandPoll('tenant-a', 'site-a', 1, 0, 0),
            'authority does not match'
        );

        $client = $this->client(function (string $url): array {
            return [
                'body' => 'redirect body must not be parsed',
                'effective_url' => $url . '/elsewhere',
                'redirected' => true,
                'status' => 302,
            ];
        });
        $this->assertRuntimeException(
            fn (): array => $client->pairPoll(str_repeat('a', 32), 'pairing-a', 0),
            'redirects are forbidden'
        );
    }

    public function testReplayOfPriorSignedResponseAgainstNewSequenceIsRejected(): void {
        $cached = null;
        $client = $this->client(function (string $url, string $body) use (&$cached): array {
            $request = ClientWireServer::decode($body);
            $payload = $request['payload'];
            if ($cached === null) {
                $cached = $this->server->signed($this->pairPollResponse(
                    $payload,
                    hash('sha256', ClientWireServer::encode($payload))
                ));
            }
            return [
                'body' => $cached,
                'effective_url' => $url,
                'redirected' => false,
                'status' => 200,
            ];
        });

        $client->pairPoll(str_repeat('a', 32), 'pairing-a', 0);
        $this->assertRuntimeException(
            fn (): array => $client->pairPoll(str_repeat('a', 32), 'pairing-a', 1),
            'bind the exact request'
        );
    }

    public function testAllActiveEndpointSchemasIdsAndRotationProofAreClosed(): void {
        $manifest = $this->manifest();
        $chunk = 'sealed-export-bytes';
        $chunkHash = hash('sha256', $chunk);
        $manifest['chunks'][0]['sha256'] = $chunkHash;
        $manifest['chunks'][0]['size'] = strlen($chunk);
        $manifest['export_sha256'] = $chunkHash;
        $manifest['export_size'] = strlen($chunk);
        $basis = $manifest;
        unset($basis['manifest_sha256']);
        $manifest['manifest_sha256'] = hash('sha256', Canon::encode($basis));
        $expected = [
            '/v1/origin/demand/poll',
            '/v1/origin/export/announce',
            '/v1/origin/export/missing',
            '/v1/origin/export/chunk',
            '/v1/origin/export/commit',
            '/v1/origin/key/rotate',
            '/v1/origin/revoke',
        ];
        $client = $this->client($this->server->exchange(function (
            string $path,
            array $payload,
            string $requestHash
        ) use ($manifest): array {
            return match ($path) {
                '/v1/origin/demand/poll' => $this->demandResponse($payload, $requestHash),
                '/v1/origin/export/announce' => $this->announceResponse($payload, $requestHash),
                '/v1/origin/export/missing' => $this->missingResponse($payload, $requestHash, $manifest),
                '/v1/origin/export/chunk' => $this->chunkResponse($payload, $requestHash),
                '/v1/origin/export/commit' => $this->commitResponse($payload, $requestHash),
                '/v1/origin/key/rotate' => $this->rotationResponse($payload, $requestHash),
                '/v1/origin/revoke' => $this->revocationResponse($payload, $requestHash),
                default => throw new \RuntimeException('unexpected fixture endpoint'),
            };
        }));
        $demandId = ClientWireServer::id('duo-cloud-origin-demand/v1', [
            'tenant-a', 'site-a', 1, 1, base64_encode(str_repeat('n', 32)), str_repeat('a', 40),
        ]);

        $demand = $client->demandPoll('tenant-a', 'site-a', 1, 0, 0);
        self::assertSame($demandId, $demand['demand']['demand_id']);
        $announced = $client->announce('tenant-a', 'site-a', 1, 1, $demandId, $manifest);
        $exportId = $announced['export_id'];
        $missing = $client->missing(
            'tenant-a',
            'site-a',
            1,
            1,
            $demandId,
            $exportId,
            $manifest['manifest_sha256'],
            0,
            [$chunkHash]
        );
        self::assertSame([$chunkHash], $missing['missing_chunk_sha256']);
        $client->putChunk(
            'tenant-a',
            'site-a',
            1,
            1,
            $demandId,
            $exportId,
            $manifest['manifest_sha256'],
            $chunkHash,
            $chunk
        );
        $client->commit(
            'tenant-a',
            'site-a',
            1,
            1,
            $demandId,
            $exportId,
            $manifest['manifest_sha256']
        );
        $newPair = sodium_crypto_sign_keypair();
        $newSecret = sodium_crypto_sign_secretkey($newPair);
        try {
            $client->rotate('tenant-a', 'site-a', 1, 2, $newSecret);
        } finally {
            sodium_memzero($newSecret);
        }
        $client->revoke('tenant-a', 'site-a', 1, 'administrator_requested');

        self::assertSame($expected, array_column($this->server->calls, 'path'));
        foreach ($this->server->calls as $call) {
            $payload = $call['payload'];
            self::assertArrayNotHasKey('action', $payload);
            self::assertArrayNotHasKey('argv', $payload);
            self::assertArrayNotHasKey('path', $payload);
            self::assertArrayNotHasKey('script', $payload);
        }
    }

    public function testSignedRefusalUsesHttp200AndUnsigned4xxBodyIsNeverExposed(): void {
        $client = $this->client($this->server->exchange(function (
            string $path,
            array $payload,
            string $requestHash
        ): array {
            return [
                'format' => 'duo-cloud-origin-refusal/v1',
                'reason_code' => 'rate_limited',
                'request_format' => $payload['format'],
                'request_sha256' => $requestHash,
                'retryable' => true,
            ];
        }));
        try {
            $client->demandPoll('tenant-a', 'site-a', 1, 0, 0);
            self::fail('Expected signed refusal');
        } catch (OriginCloudRefusal $error) {
            self::assertSame('rate_limited', $error->reasonCode());
            self::assertTrue($error->retryable());
        }

        $generic = $this->client(function (string $url): array {
            return [
                'body' => 'SHOULD_NOT_LEAK attacker-controlled diagnostic',
                'effective_url' => $url,
                'redirected' => false,
                'status' => 403,
            ];
        });
        try {
            $generic->demandPoll('tenant-a', 'site-a', 1, 0, 0);
            self::fail('Expected generic rejection');
        } catch (\RuntimeException $error) {
            self::assertStringNotContainsString('SHOULD_NOT_LEAK', $error->getMessage());
            self::assertSame('duo: cloud origin request was rejected', $error->getMessage());
        }
    }

    public function testEndpointOverrideAndHttpAreTestOnlyAndProductionFailsClosed(): void {
        putenv('DUO_TEST_MODE');
        $this->assertRuntimeException(
            fn (): OriginCloudClient => new OriginCloudClient(
                'https://example.invalid',
                $this->server->keyId,
                $this->server->publicKey,
                $this->originSecret
            ),
            'not pinned in wp-config'
        );
        $this->assertRuntimeException(
            fn (): OriginCloudClient => OriginCloudClient::production(),
            'not pinned by this release'
        );
        putenv('DUO_TEST_MODE=1');
        $this->assertRuntimeException(
            fn (): OriginCloudClient => new OriginCloudClient(
                'http://192.0.2.10',
                $this->server->keyId,
                $this->server->publicKey,
                $this->originSecret
            ),
            'requires verified HTTPS'
        );
        $this->assertRuntimeException(
            fn (): OriginCloudClient => new OriginCloudClient(
                'https://example.invalid?callback=https://attacker.invalid',
                $this->server->keyId,
                $this->server->publicKey,
                $this->originSecret
            ),
            'unexpected fields'
        );
        $loopback = new OriginCloudClient(
            'http://127.0.0.1:8080',
            $this->server->keyId,
            $this->server->publicKey,
            $this->originSecret,
            static fn (): array => []
        );
        self::assertSame(OriginCloudClient::deriveOriginKeyId($this->originPublic), $loopback->originKeyId());
    }

    /** @param callable(string,string,int,int):array<string,mixed> $exchange */
    private function client(callable $exchange): OriginCloudClient {
        return new OriginCloudClient(
            'https://origin.example.test',
            $this->server->keyId,
            $this->server->publicKey,
            $this->originSecret,
            $exchange
        );
    }

    /** @return array<string,mixed> */
    private function connector(): array {
        return [
            'agent_version' => '0.5.0',
            'home_url_sha256' => hash('sha256', 'https://example.test'),
            'installation_id' => 'installation-a',
            'multisite' => false,
            'php_version' => '8.3.0',
            'site_url_sha256' => hash('sha256', 'https://example.test/wp'),
            'wordpress_version' => '6.7.0',
        ];
    }

    /** @return array<string,mixed> */
    private function pairPollResponse(array $payload, string $hash): array {
        return [
            'expires_at' => 1800000600,
            'format' => 'duo-cloud-origin-pair-poll-response/v1',
            'origin_key_id' => $payload['origin_key_id'],
            'pair_attempt_id' => $payload['pair_attempt_id'],
            'pairing' => null,
            'pairing_id' => $payload['pairing_id'],
            'poll_after_seconds' => 2,
            'poll_sequence' => $payload['poll_sequence'],
            'request_sha256' => $hash,
            'state' => 'pending',
        ];
    }

    /** @param callable(array<string,mixed>,string):string $body */
    private function assertPairPollRejected(callable $body, string $message): void {
        $client = $this->client(function (string $url, string $request) use ($body): array {
            $decoded = ClientWireServer::decode($request);
            $payload = $decoded['payload'];
            return [
                'body' => $body($payload, hash('sha256', ClientWireServer::encode($payload))),
                'effective_url' => $url,
                'redirected' => false,
                'status' => 200,
            ];
        });
        $this->assertRuntimeException(
            fn (): array => $client->pairPoll(str_repeat('a', 32), 'pairing-a', 0),
            $message
        );
    }

    /** @return array<string,mixed> */
    private function demandResponse(array $payload, string $hash): array {
        self::assertSame([
            'after_demand_generation', 'format', 'origin_generation', 'origin_key_id',
            'poll_sequence', 'request_id', 'site_id', 'tenant_id',
        ], ClientWireServer::keys($payload));
        $nonce = base64_encode(str_repeat('n', 32));
        $commit = str_repeat('a', 40);
        $demandGeneration = $payload['after_demand_generation'] + 1;
        $demandId = ClientWireServer::id('duo-cloud-origin-demand/v1', [
            $payload['tenant_id'],
            $payload['site_id'],
            $payload['origin_generation'],
            $demandGeneration,
            $nonce,
            $commit,
        ]);
        self::assertSame(
            ClientWireServer::id('duo-cloud-origin-demand-poll/v1', [
                $payload['tenant_id'],
                $payload['site_id'],
                $payload['origin_generation'],
                $payload['origin_key_id'],
                $payload['after_demand_generation'],
                $payload['poll_sequence'],
            ]),
            $payload['request_id']
        );
        return [
            'after_demand_generation' => $payload['after_demand_generation'],
            'demand' => [
                'chunk_size' => 1048576,
                'demand_generation' => $demandGeneration,
                'demand_id' => $demandId,
                'expires_at' => 1800000300,
                'expected_production_commit' => $commit,
                'format' => 'duo-cloud-origin-export-demand/v1',
                'nonce' => $nonce,
                'retention_deadline' => 1800086400,
                'snapshot_mode' => 'portable-refresh',
            ],
            'format' => 'duo-cloud-origin-demand-poll-response/v1',
            'origin_generation' => $payload['origin_generation'],
            'origin_key_id' => $payload['origin_key_id'],
            'poll_after_seconds' => 2,
            'poll_sequence' => $payload['poll_sequence'],
            'request_sha256' => $hash,
            'site_id' => $payload['site_id'],
            'state' => 'demanded',
            'tenant_id' => $payload['tenant_id'],
        ];
    }

    /** @return array<string,mixed> */
    private function announceResponse(array $payload, string $hash): array {
        self::assertSame([
            'demand_generation', 'demand_id', 'format', 'manifest', 'origin_generation',
            'origin_key_id', 'request_id', 'site_id', 'tenant_id',
        ], ClientWireServer::keys($payload));
        $manifestHash = $payload['manifest']['manifest_sha256'];
        self::assertSame(
            ClientWireServer::id('duo-cloud-origin-export-announce/v1', [
                $payload['tenant_id'],
                $payload['site_id'],
                $payload['origin_generation'],
                $payload['origin_key_id'],
                $payload['demand_generation'],
                $payload['demand_id'],
                $manifestHash,
            ]),
            $payload['request_id']
        );
        $exportId = ClientWireServer::id('duo-cloud-origin-export/v1', [
            $payload['tenant_id'],
            $payload['site_id'],
            $payload['origin_generation'],
            $payload['demand_generation'],
            $payload['demand_id'],
            $manifestHash,
        ]);
        return [
            'demand_generation' => $payload['demand_generation'],
            'demand_id' => $payload['demand_id'],
            'export_id' => $exportId,
            'format' => 'duo-cloud-origin-export-announce-response/v1',
            'manifest_sha256' => $manifestHash,
            'origin_generation' => $payload['origin_generation'],
            'origin_key_id' => $payload['origin_key_id'],
            'request_sha256' => $hash,
            'site_id' => $payload['site_id'],
            'state' => 'announced',
            'tenant_id' => $payload['tenant_id'],
        ];
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private function missingResponse(array $payload, string $hash, array $manifest): array {
        self::assertSame(
            ClientWireServer::id('duo-cloud-origin-export-missing/v1', [
                $payload['tenant_id'], $payload['site_id'], $payload['origin_generation'],
                $payload['origin_key_id'], $payload['demand_generation'], $payload['demand_id'],
                $payload['export_id'], $payload['manifest_sha256'], $payload['query_sequence'],
            ]),
            $payload['request_id']
        );
        return [
            'demand_generation' => $payload['demand_generation'],
            'demand_id' => $payload['demand_id'],
            'export_id' => $payload['export_id'],
            'format' => 'duo-cloud-origin-export-missing-response/v1',
            'manifest_sha256' => $payload['manifest_sha256'],
            'missing_chunk_sha256' => [$manifest['chunks'][0]['sha256']],
            'origin_generation' => $payload['origin_generation'],
            'origin_key_id' => $payload['origin_key_id'],
            'query_sequence' => $payload['query_sequence'],
            'request_sha256' => $hash,
            'site_id' => $payload['site_id'],
            'tenant_id' => $payload['tenant_id'],
        ];
    }

    /** @return array<string,mixed> */
    private function chunkResponse(array $payload, string $hash): array {
        $decoded = base64_decode($payload['chunk_base64'], true);
        self::assertIsString($decoded);
        self::assertSame($payload['chunk_size'], strlen($decoded));
        self::assertSame($payload['chunk_sha256'], hash('sha256', $decoded));
        self::assertSame(
            ClientWireServer::id('duo-cloud-origin-export-chunk/v1', [
                $payload['tenant_id'], $payload['site_id'], $payload['origin_generation'],
                $payload['origin_key_id'], $payload['demand_generation'], $payload['demand_id'],
                $payload['export_id'], $payload['manifest_sha256'], $payload['chunk_sha256'],
                $payload['chunk_size'],
            ]),
            $payload['request_id']
        );
        $response = $payload;
        unset($response['chunk_base64'], $response['request_id']);
        $response['format'] = 'duo-cloud-origin-export-chunk-response/v1';
        $response['request_sha256'] = $hash;
        $response['state'] = 'stored';
        return $response;
    }

    /** @return array<string,mixed> */
    private function commitResponse(array $payload, string $hash): array {
        self::assertSame(
            ClientWireServer::id('duo-cloud-origin-export-commit/v1', [
                $payload['tenant_id'], $payload['site_id'], $payload['origin_generation'],
                $payload['origin_key_id'], $payload['demand_generation'], $payload['demand_id'],
                $payload['export_id'], $payload['manifest_sha256'],
            ]),
            $payload['request_id']
        );
        $response = $payload;
        unset($response['request_id']);
        $response['commit_receipt_sha256'] = hash('sha256', 'commit-receipt');
        $response['format'] = 'duo-cloud-origin-export-commit-response/v1';
        $response['request_sha256'] = $hash;
        $response['retention_deadline'] = 1800086400;
        $response['snapshot_hash'] = str_repeat('4', 64);
        $response['state'] = 'committed';
        return $response;
    }

    /** @return array<string,mixed> */
    private function rotationResponse(array $payload, string $hash): array {
        self::assertSame([
            'format', 'new_key', 'new_key_proof', 'next_origin_generation',
            'origin_generation', 'origin_key_id', 'request_id', 'rotation_id',
            'site_id', 'tenant_id',
        ], ClientWireServer::keys($payload));
        self::assertSame($payload['rotation_id'], $payload['request_id']);
        self::assertSame(
            ClientWireServer::id('duo-cloud-origin-key-rotation/v1', [
                $payload['tenant_id'], $payload['site_id'], $payload['origin_generation'],
                $payload['new_key']['key_id'],
            ]),
            $payload['rotation_id']
        );
        $newPublic = base64_decode($payload['new_key']['public_key'], true);
        $proof = base64_decode($payload['new_key_proof'], true);
        self::assertIsString($newPublic);
        self::assertIsString($proof);
        $statement = [
            'format' => 'duo-cloud-origin-key-possession/v1',
            'new_origin_key_id' => $payload['new_key']['key_id'],
            'next_origin_generation' => $payload['next_origin_generation'],
            'origin_generation' => $payload['origin_generation'],
            'rotation_id' => $payload['rotation_id'],
            'site_id' => $payload['site_id'],
            'tenant_id' => $payload['tenant_id'],
        ];
        self::assertTrue(sodium_crypto_sign_verify_detached(
            $proof,
            ClientWireServer::encode($statement),
            $newPublic
        ));
        return [
            'format' => 'duo-cloud-origin-key-rotation-response/v1',
            'new_origin_key_id' => $payload['new_key']['key_id'],
            'next_origin_generation' => $payload['next_origin_generation'],
            'origin_generation' => $payload['origin_generation'],
            'origin_key_id' => $payload['origin_key_id'],
            'request_sha256' => $hash,
            'rotation_id' => $payload['rotation_id'],
            'rotation_receipt_sha256' => hash('sha256', 'rotation-receipt'),
            'site_id' => $payload['site_id'],
            'state' => 'rotated',
            'tenant_id' => $payload['tenant_id'],
        ];
    }

    /** @return array<string,mixed> */
    private function revocationResponse(array $payload, string $hash): array {
        self::assertSame($payload['revocation_id'], $payload['request_id']);
        self::assertSame(
            ClientWireServer::id('duo-cloud-origin-revoke/v1', [
                $payload['tenant_id'], $payload['site_id'], $payload['origin_generation'], $payload['reason'],
            ]),
            $payload['revocation_id']
        );
        return [
            'format' => 'duo-cloud-origin-revoke-response/v1',
            'origin_generation' => $payload['origin_generation'],
            'origin_key_id' => $payload['origin_key_id'],
            'request_sha256' => $hash,
            'revocation_id' => $payload['revocation_id'],
            'revocation_receipt_sha256' => hash('sha256', 'revocation-receipt'),
            'site_id' => $payload['site_id'],
            'state' => 'revoked',
            'tenant_id' => $payload['tenant_id'],
        ];
    }

    /** @return array<string,mixed> */
    private function manifest(): array {
        $manifest = [
            'artifact_hash' => str_repeat('1', 64),
            'chunks' => [[
                'index' => 0,
                'offset' => 0,
                'sha256' => str_repeat('2', 64),
                'size' => 1,
            ]],
            'code_revision' => null,
            'expected_production_commit' => str_repeat('a', 40),
            'export_sha256' => str_repeat('2', 64),
            'export_size' => 1,
            'format' => 'duo-cloud-origin-export-manifest/v1',
            'generation' => 1,
            'repository_revision_hash' => str_repeat('3', 64),
            'snapshot_hash' => str_repeat('4', 64),
        ];
        $manifest['manifest_sha256'] = hash('sha256', Canon::encode($manifest));
        return $manifest;
    }

    /** @param callable():mixed $callback */
    private function assertRuntimeException(callable $callback, string $message): void {
        try {
            $callback();
            self::fail('Expected RuntimeException');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
    }
}
