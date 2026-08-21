<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\OriginCloudClient;
use Duo\OriginPairing;
use Duo\OriginPairingStateStore;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/agent/src/Cloud/OriginPairing.php';

/** @internal */
final class PairingWireServer {
    public string $secretKey;
    public string $publicKey;
    public string $keyId = 'service-key-v1';
    /** @var array<string,list<string>> */
    public array $calls = [];
    /** @var array<string,bool> */
    public array $loseOnce = [];
    /** @var array<string,bool> */
    public array $rejectedDeviceCodes = [];
    public string $pollState = 'paired';
    public bool $tamperServiceSignatureOnce = false;
    /** @var array<string,string> raw origin public keys by id */
    private array $originKeys = [];
    /** @var array<string,array{hash:string,response:string}> */
    private array $receipts = [];

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

    /** @return array<string,mixed> */
    public function exchange(string $url, string $body, int $timeout, int $limit): array {
        TestCase::assertGreaterThanOrEqual(1, $timeout);
        TestCase::assertSame(1048576, $limit);
        $path = parse_url($url, PHP_URL_PATH);
        TestCase::assertIsString($path);
        $this->calls[$path] ??= [];
        $this->calls[$path][] = $body;
        $envelope = $this->decode($body);
        $payload = $envelope['payload'];
        TestCase::assertIsArray($payload);
        $keyId = $envelope['key_id'];
        TestCase::assertIsString($keyId);
        if ($path === '/v1/origin/pair/begin') {
            $public = base64_decode($payload['origin_key']['public_key'], true);
            TestCase::assertIsString($public);
            TestCase::assertSame(OriginCloudClient::deriveOriginKeyId($public), $keyId);
            $this->originKeys[$keyId] = $public;
        }
        TestCase::assertArrayHasKey($keyId, $this->originKeys);
        $signature = base64_decode($envelope['signature'], true);
        TestCase::assertIsString($signature);
        TestCase::assertTrue(sodium_crypto_sign_verify_detached(
            $signature,
            $this->encode($payload),
            $this->originKeys[$keyId]
        ));
        $requestHash = hash('sha256', $this->encode($payload));
        $receiptKey = $keyId . ':' . $payload['request_id'];
        $receipt = $this->receipts[$receiptKey] ?? null;
        if ($receipt !== null) {
            TestCase::assertSame($receipt['hash'], $requestHash, 'request id was replayed with changed bytes');
            $response = $receipt['response'];
        } else {
            $responsePayload = $this->response($path, $payload, $requestHash);
            $response = $this->signed($responsePayload);
            $this->receipts[$receiptKey] = ['hash' => $requestHash, 'response' => $response];
        }
        if (($this->loseOnce[$path] ?? false) === true) {
            $this->loseOnce[$path] = false;
            throw new \RuntimeException('fixture lost response after durable service completion');
        }
        if ($this->tamperServiceSignatureOnce) {
            $this->tamperServiceSignatureOnce = false;
            $tampered = $this->decode($response);
            $other = sodium_crypto_sign_keypair();
            $tampered['signature'] = base64_encode(sodium_crypto_sign_detached(
                $this->encode($tampered['payload']),
                sodium_crypto_sign_secretkey($other)
            ));
            $response = $this->encode($tampered) . "\n";
        }
        return [
            'body' => $response,
            'effective_url' => $url,
            'redirected' => false,
            'status' => 200,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function response(string $path, array $payload, string $requestHash): array {
        return match ($path) {
            '/v1/origin/pair/begin' => $this->beginResponse($payload, $requestHash),
            '/v1/origin/pair/poll' => $this->pollResponse($payload, $requestHash),
            '/v1/origin/key/rotate' => $this->rotationResponse($payload, $requestHash),
            '/v1/origin/revoke' => [
                'format' => 'duo-cloud-origin-revoke-response/v1',
                'origin_generation' => $payload['origin_generation'],
                'origin_key_id' => $payload['origin_key_id'],
                'request_sha256' => $requestHash,
                'revocation_id' => $payload['revocation_id'],
                'revocation_receipt_sha256' => hash('sha256', 'revocation-receipt'),
                'site_id' => $payload['site_id'],
                'state' => 'revoked',
                'tenant_id' => $payload['tenant_id'],
            ],
            default => throw new \RuntimeException('unexpected pairing fixture endpoint'),
        };
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function beginResponse(array $payload, string $requestHash): array {
        if (($this->rejectedDeviceCodes[$payload['device_code']] ?? false) === true) {
            return [
                'format' => 'duo-cloud-origin-refusal/v1',
                'reason_code' => 'pairing_code_rejected',
                'request_format' => $payload['format'],
                'request_sha256' => $requestHash,
                'retryable' => false,
            ];
        }
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
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function pollResponse(array $payload, string $requestHash): array {
        TestCase::assertContains($this->pollState, ['denied', 'expired', 'paired']);
        return [
            'expires_at' => 1800000600,
            'format' => 'duo-cloud-origin-pair-poll-response/v1',
            'origin_key_id' => $payload['origin_key_id'],
            'pair_attempt_id' => $payload['pair_attempt_id'],
            'pairing' => $this->pollState === 'paired' ? [
                'demand_generation' => 0,
                'origin_generation' => 1,
                'service_key_id' => $this->keyId,
                'service_public_key_sha256' => hash('sha256', $this->publicKey),
                'site_id' => 'site-a',
                'tenant_id' => 'tenant-a',
            ] : null,
            'pairing_id' => $payload['pairing_id'],
            'poll_after_seconds' => 2,
            'poll_sequence' => $payload['poll_sequence'],
            'request_sha256' => $requestHash,
            'state' => $this->pollState,
        ];
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    private function rotationResponse(array $payload, string $requestHash): array {
        $newPublic = base64_decode($payload['new_key']['public_key'], true);
        TestCase::assertIsString($newPublic);
        $newKeyId = OriginCloudClient::deriveOriginKeyId($newPublic);
        TestCase::assertSame($newKeyId, $payload['new_key']['key_id']);
        TestCase::assertSame($payload['rotation_id'], $payload['request_id']);
        $proof = base64_decode($payload['new_key_proof'], true);
        TestCase::assertIsString($proof);
        $statement = [
            'format' => 'duo-cloud-origin-key-possession/v1',
            'new_origin_key_id' => $newKeyId,
            'next_origin_generation' => $payload['next_origin_generation'],
            'origin_generation' => $payload['origin_generation'],
            'rotation_id' => $payload['rotation_id'],
            'site_id' => $payload['site_id'],
            'tenant_id' => $payload['tenant_id'],
        ];
        TestCase::assertTrue(sodium_crypto_sign_verify_detached(
            $proof,
            $this->encode($statement),
            $newPublic
        ));
        // Retain the old key for the exact lost-response replay, while making
        // the new key available to subsequent paired-client requests.
        $this->originKeys[$newKeyId] = $newPublic;
        return [
            'format' => 'duo-cloud-origin-key-rotation-response/v1',
            'new_origin_key_id' => $newKeyId,
            'next_origin_generation' => $payload['next_origin_generation'],
            'origin_generation' => $payload['origin_generation'],
            'origin_key_id' => $payload['origin_key_id'],
            'request_sha256' => $requestHash,
            'rotation_id' => $payload['rotation_id'],
            'rotation_receipt_sha256' => hash('sha256', 'rotation-receipt'),
            'site_id' => $payload['site_id'],
            'state' => 'rotated',
            'tenant_id' => $payload['tenant_id'],
        ];
    }

    /** @param array<string,mixed> $payload */
    private function signed(array $payload): string {
        return $this->encode([
            'format' => OriginCloudClient::ENVELOPE_FORMAT,
            'key_id' => $this->keyId,
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                $this->encode($payload),
                $this->secretKey
            )),
        ]) . "\n";
    }

    /** @return array<string,mixed> */
    private function decode(string $bytes): array {
        $decoded = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        TestCase::assertIsArray($decoded);
        TestCase::assertSame($this->encode($decoded) . "\n", $bytes);
        return $decoded;
    }

    private function encode(mixed $value): string {
        return json_encode(
            $this->normalize($value),
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    }

    private function normalize(mixed $value): mixed {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        foreach ($value as $key => $child) {
            $value[$key] = $this->normalize($child);
        }
        return $value;
    }
}

#[CoversNothing]
final class OriginPairingTest extends TestCase {
    private string|false $priorTestMode;
    private string $scratch;
    private string $stateDirectory;
    private string $storageKey;
    private PairingWireServer $server;
    private OriginPairingStateStore $store;
    private OriginPairing $pairing;

    protected function setUp(): void {
        $this->priorTestMode = getenv('DUO_TEST_MODE');
        putenv('DUO_TEST_MODE=1');
        $this->scratch = sys_get_temp_dir() . '/duo-origin-pairing-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
        $this->stateDirectory = $this->scratch . '/private';
        $this->storageKey = random_bytes(64);
        $this->server = new PairingWireServer();
        $this->store = new OriginPairingStateStore($this->stateDirectory, $this->storageKey);
        $this->pairing = new OriginPairing(
            $this->store,
            'https://origin.example.test',
            $this->server->keyId,
            $this->server->publicKey,
            $this->server->exchange(...)
        );
    }

    protected function tearDown(): void {
        unset($this->pairing, $this->store);
        if (isset($this->stateDirectory) && is_dir($this->stateDirectory)) {
            $entries = scandir($this->stateDirectory);
            self::assertIsArray($entries);
            foreach (array_diff($entries, ['.', '..']) as $entry) {
                $path = $this->stateDirectory . '/' . $entry;
                if (is_file($path)) {
                    self::assertTrue(unlink($path));
                }
            }
            self::assertTrue(rmdir($this->stateDirectory));
        }
        if (isset($this->scratch) && is_dir($this->scratch)) {
            self::assertTrue(rmdir($this->scratch));
        }
        if (isset($this->storageKey) && $this->storageKey !== '') {
            sodium_memzero($this->storageKey);
        }
        if ($this->priorTestMode === false) {
            putenv('DUO_TEST_MODE');
        } else {
            putenv('DUO_TEST_MODE=' . $this->priorTestMode);
        }
    }

    public function testLostBeginAndPollResponsesReplayExactBytesAndActivateOnlyAfterSignedPoll(): void {
        $this->server->loseOnce['/v1/origin/pair/begin'] = true;
        $this->assertRuntimeException(
            fn (): array => $this->pairing->begin($this->deviceCode(), $this->connector()),
            'lost response'
        );
        self::assertSame('beginning', $this->pairing->status()['phase']);
        self::assertNull($this->pairing->status()['pairing']);
        $ciphertext = (string) file_get_contents($this->store->statePath());
        self::assertStringNotContainsString($this->deviceCode(), $ciphertext);
        self::assertStringNotContainsString('installation-a', $ciphertext);
        self::assertSame(0700, fileperms($this->stateDirectory) & 0777);
        self::assertSame(0600, fileperms($this->store->statePath()) & 0777);
        self::assertSame(0600, fileperms($this->stateDirectory . '/pairing.lock') & 0777);

        $begin = $this->pairing->begin($this->deviceCode(), $this->connector());
        self::assertSame('pending', $begin['state']);
        self::assertCount(2, $this->server->calls['/v1/origin/pair/begin']);
        self::assertSame(
            $this->server->calls['/v1/origin/pair/begin'][0],
            $this->server->calls['/v1/origin/pair/begin'][1]
        );

        $this->server->loseOnce['/v1/origin/pair/poll'] = true;
        $this->assertRuntimeException(fn (): array => $this->pairing->poll(), 'lost response');
        $unknown = $this->pairing->status();
        self::assertSame('polling', $unknown['phase']);
        self::assertNull($unknown['pairing']);
        $this->assertRuntimeException(
            fn (): OriginCloudClient => $this->pairing->pairedClient(),
            'requires active signed pairing'
        );

        $paired = $this->pairing->poll();
        self::assertSame('paired', $paired['state']);
        self::assertSame('paired', $this->pairing->status()['phase']);
        self::assertSame('site-a', $this->pairing->status()['pairing']['site_id']);
        self::assertCount(2, $this->server->calls['/v1/origin/pair/poll']);
        self::assertSame(
            $this->server->calls['/v1/origin/pair/poll'][0],
            $this->server->calls['/v1/origin/pair/poll'][1]
        );
    }

    public function testWrongServiceSignatureNeverActivatesAndExactPollCanRecover(): void {
        $this->pairing->begin($this->deviceCode(), $this->connector());
        $this->server->tamperServiceSignatureOnce = true;
        $this->assertRuntimeException(fn (): array => $this->pairing->poll(), 'signature is invalid');
        self::assertSame('polling', $this->pairing->status()['phase']);
        self::assertNull($this->pairing->status()['pairing']);
        $this->assertRuntimeException(
            fn (): OriginCloudClient => $this->pairing->pairedClient(),
            'requires active signed pairing'
        );

        // The retry is byte-identical; only the authentic cached service
        // response can cross the durable activation boundary.
        $response = $this->pairing->poll();
        self::assertSame('paired', $response['state']);
        self::assertSame('site-a', $response['pairing']['site_id']);
        self::assertSame(
            $this->server->calls['/v1/origin/pair/poll'][0],
            $this->server->calls['/v1/origin/pair/poll'][1]
        );
    }

    public function testLostRotationAndRevocationResponsesPreserveIntentAndRemoveActiveKey(): void {
        $this->pairing->begin($this->deviceCode(), $this->connector());
        $this->pairing->poll();
        $oldKeyId = $this->pairing->status()['origin_key_id'];

        $this->server->loseOnce['/v1/origin/key/rotate'] = true;
        $this->assertRuntimeException(fn (): array => $this->pairing->rotate(), 'lost response');
        self::assertSame('rotating', $this->pairing->status()['phase']);
        $this->assertRuntimeException(
            fn (): OriginCloudClient => $this->pairing->pairedClient(),
            'requires active signed pairing'
        );
        $rotation = $this->pairing->rotate();
        self::assertSame('rotated', $rotation['state']);
        self::assertNotSame($oldKeyId, $this->pairing->status()['origin_key_id']);
        self::assertSame(2, $this->pairing->status()['pairing']['origin_generation']);
        self::assertCount(2, $this->server->calls['/v1/origin/key/rotate']);
        self::assertSame(
            $this->server->calls['/v1/origin/key/rotate'][0],
            $this->server->calls['/v1/origin/key/rotate'][1]
        );

        $this->server->loseOnce['/v1/origin/revoke'] = true;
        $this->assertRuntimeException(fn (): array => $this->pairing->revoke(), 'lost response');
        self::assertSame('revoking', $this->pairing->status()['phase']);
        $revoked = $this->pairing->revoke();
        self::assertSame('revoked', $revoked['state']);
        self::assertSame('revoked', $this->pairing->status()['phase']);
        self::assertCount(2, $this->server->calls['/v1/origin/revoke']);
        self::assertSame(
            $this->server->calls['/v1/origin/revoke'][0],
            $this->server->calls['/v1/origin/revoke'][1]
        );
        $this->assertRuntimeException(
            fn (): OriginCloudClient => $this->pairing->pairedClient(),
            'requires active signed pairing'
        );
        $ciphertext = (string) file_get_contents($this->store->statePath());
        self::assertStringNotContainsString((string) $oldKeyId, $ciphertext);
    }

    public function testFreshDeviceCodeReplacesOnlyTerminalDeniedAndExpiredAttempts(): void {
        $firstCode = $this->deviceCode();
        $secondCode = 'BCDEF-GHIJK-LMNOP-QRSTU';
        $thirdCode = 'CDEFG-HIJKL-MNOPQ-RSTUV';
        $connector = $this->connector();

        $this->server->pollState = 'denied';
        $this->pairing->begin($firstCode, $connector);
        self::assertSame('denied', $this->pairing->poll()['state']);
        $first = $this->pairing->status();
        self::assertSame('denied', $first['phase']);

        // The consumed code remains an exact replay, while a different
        // one-time code is explicit authority for a fresh key and attempt.
        $this->pairing->begin($firstCode, $connector);
        self::assertCount(1, $this->server->calls['/v1/origin/pair/begin']);
        $this->server->pollState = 'expired';
        $this->pairing->begin($secondCode, $connector);
        $second = $this->pairing->status();
        self::assertSame('pending', $second['phase']);
        self::assertNotSame($first['origin_key_id'], $second['origin_key_id']);
        self::assertNotSame($first['pair_attempt_id'], $second['pair_attempt_id']);
        self::assertCount(2, $this->server->calls['/v1/origin/pair/begin']);
        self::assertSame('expired', $this->pairing->poll()['state']);

        $this->server->pollState = 'paired';
        $this->pairing->begin($thirdCode, $connector);
        $third = $this->pairing->status();
        self::assertSame('pending', $third['phase']);
        self::assertNotSame($second['origin_key_id'], $third['origin_key_id']);
        self::assertNotSame($second['pair_attempt_id'], $third['pair_attempt_id']);
        self::assertSame('paired', $this->pairing->poll()['state']);
        self::assertSame('paired', $this->pairing->status()['phase']);
    }

    public function testSignedRejectedReplacementCanBeReplayedThenReplacedWithoutStateDeletion(): void {
        $firstCode = $this->deviceCode();
        $rejectedCode = 'BCDEF-GHIJK-LMNOP-QRSTU';
        $replacementCode = 'CDEFG-HIJKL-MNOPQ-RSTUV';
        $connector = $this->connector();

        $this->server->pollState = 'denied';
        $this->pairing->begin($firstCode, $connector);
        self::assertSame('denied', $this->pairing->poll()['state']);

        $this->server->rejectedDeviceCodes[$rejectedCode] = true;
        try {
            $this->pairing->begin($rejectedCode, $connector);
            self::fail('signed rejected device code was accepted');
        } catch (\Duo\OriginCloudRefusal $refusal) {
            self::assertSame('pairing_code_rejected', $refusal->reasonCode());
            self::assertFalse($refusal->retryable());
        }
        self::assertSame('rejected', $this->pairing->status()['phase']);
        self::assertCount(2, $this->server->calls['/v1/origin/pair/begin']);

        try {
            $this->pairing->begin($rejectedCode, $connector);
            self::fail('rejected device code did not replay its terminal refusal');
        } catch (\Duo\OriginCloudRefusal $refusal) {
            self::assertSame('pairing_code_rejected', $refusal->reasonCode());
        }
        self::assertCount(
            2,
            $this->server->calls['/v1/origin/pair/begin'],
            'a rejected code replay must not contact the authority again'
        );

        $this->server->pollState = 'paired';
        $this->pairing->begin($replacementCode, $connector);
        self::assertSame('paired', $this->pairing->poll()['state']);
        self::assertSame('paired', $this->pairing->status()['phase']);
    }

    public function testDeviceCodeIsRequiredAsMethodInputAndNeverStoredOrReturned(): void {
        $this->assertRuntimeException(
            fn (): array => $this->pairing->begin('not-a-device-code', $this->connector()),
            'device code is malformed'
        );
        self::assertSame('unpaired', $this->pairing->status()['phase']);
        self::assertFileDoesNotExist($this->store->statePath());

        $this->pairing->begin($this->deviceCode(), $this->connector());
        $status = $this->pairing->status();
        self::assertSame([
            'origin_key_id', 'pair_attempt_id', 'pairing', 'pairing_id', 'phase',
        ], $this->sortedKeys($status));
        self::assertStringNotContainsString($this->deviceCode(), json_encode($status, JSON_THROW_ON_ERROR));
        $ciphertext = (string) file_get_contents($this->store->statePath());
        self::assertStringNotContainsString($this->deviceCode(), $ciphertext);
        self::assertStringNotContainsString(hash('sha256', $this->deviceCode()), $ciphertext);
    }

    public function testInterruptedPairingStateWriteIsReconciledBeforeTheRealPublicWrite(): void {
        $temporary = $this->store->statePath() . '.tmp';
        self::assertSame(22, file_put_contents($temporary, 'interrupted-ciphertext'));
        self::assertTrue(chmod($temporary, 0600));

        $response = $this->pairing->begin($this->deviceCode(), $this->connector());

        self::assertSame('pending', $response['state']);
        self::assertFileExists($this->store->statePath());
        self::assertFileDoesNotExist($temporary);
        self::assertSame([], glob($this->stateDirectory . '/*.tmp') ?: []);
    }

    public function testSymlinkedPairingStateCrashResidueIsRefusedWithoutUnlinkingItsTarget(): void {
        $outside = $this->scratch . '/outside-pairing-state';
        self::assertSame(16, file_put_contents($outside, 'outside-retained'));
        self::assertTrue(chmod($outside, 0600));
        $temporary = $this->store->statePath() . '.tmp';
        self::assertTrue(symlink($outside, $temporary));

        try {
            $this->assertRuntimeException(
                fn (): array => $this->pairing->status(),
                'temporary state is unsafe'
            );
            self::assertSame('outside-retained', file_get_contents($outside));
            self::assertTrue(is_link($temporary));
        } finally {
            if (is_link($temporary)) {
                self::assertTrue(unlink($temporary));
            }
            if (is_file($outside)) {
                self::assertTrue(unlink($outside));
            }
        }
    }

    public function testProductionFactoryUsesOnlyWpConfigTrustAndProtectedRepositoryState(): void {
        $repository = $this->scratch . '/production-repository';
        self::assertTrue(mkdir($repository, 0700));
        self::assertTrue(mkdir($repository . '/.duo', 0700));
        self::assertTrue(mkdir($repository . '/.duo/control', 0700));
        $pair = sodium_crypto_sign_keypair();
        $servicePublicKey = sodium_crypto_sign_publickey($pair);
        $source = DUO_REPO_ROOT . '/agent/src/Cloud/OriginPairing.php';
        $script = <<<'PHP'
putenv('DUO_TEST_MODE');
define('DUO_CLOUD_ORIGIN_ENDPOINT', 'https://preview.duo.example');
define('DUO_CLOUD_ORIGIN_SERVICE_KEY_ID', 'service-key-v1');
define('DUO_CLOUD_ORIGIN_SERVICE_PUBLIC_KEY', $argv[2]);
define('AUTH_KEY', str_repeat('a', 64));
define('SECURE_AUTH_KEY', str_repeat('b', 64));
define('LOGGED_IN_KEY', str_repeat('c', 64));
define('NONCE_KEY', str_repeat('d', 64));
require $argv[1];
try {
    $pairing = \Duo\OriginPairing::production($argv[3]);
    $status = $pairing->status();
    $keypair = sodium_crypto_sign_keypair();
    try {
        new \Duo\OriginCloudClient(
            'https://attacker.invalid',
            DUO_CLOUD_ORIGIN_SERVICE_KEY_ID,
            base64_decode(DUO_CLOUD_ORIGIN_SERVICE_PUBLIC_KEY, true),
            sodium_crypto_sign_secretkey($keypair)
        );
        throw new RuntimeException('mismatched production endpoint was accepted');
    } catch (RuntimeException $error) {
        if (!str_contains($error->getMessage(), 'differs from the wp-config trust pin')) {
            throw $error;
        }
    }
    echo json_encode([
        'phase' => $status['phase'],
        'pairing_mode' => fileperms($argv[3] . '/.duo/control/cloud-origin/pairing') & 0777,
        'sessions_mode' => fileperms($argv[3] . '/.duo/control/cloud-origin/sessions') & 0777,
    ], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage());
    exit(23);
}
PHP;
        try {
            $pipes = [];
            $process = proc_open(
                [PHP_BINARY, '-r', $script, $source, base64_encode($servicePublicKey), $repository],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                null,
                ['bypass_shell' => true]
            );
            self::assertIsResource($process);
            fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $stderr);
            self::assertSame(
                ['phase' => 'unpaired', 'pairing_mode' => 0700, 'sessions_mode' => 0700],
                json_decode((string) $stdout, true, 8, JSON_THROW_ON_ERROR)
            );
        } finally {
            if (is_dir($repository)) {
                self::removeTree($repository);
            }
        }
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

    private function deviceCode(): string {
        return 'ABCDE-FGHIJ-KLMNO-PQRST';
    }

    /** @param array<string,mixed> $value @return list<string> */
    private function sortedKeys(array $value): array {
        $keys = array_keys($value);
        sort($keys, SORT_STRING);
        return $keys;
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

    private static function removeTree(string $path): void {
        $entries = scandir($path);
        self::assertIsArray($entries);
        foreach (array_diff($entries, ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                self::removeTree($child);
            } else {
                self::assertTrue(unlink($child));
            }
        }
        self::assertTrue(rmdir($path));
    }
}
