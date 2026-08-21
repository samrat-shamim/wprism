<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\OriginCloudClient;
use Duo\Cloud\CanonicalJson;
use Duo\Cloud\ControlRefusal;
use Duo\Cloud\FileAuthorityStore;
use Duo\Cloud\OriginAuthority;
use Duo\Cloud\OriginAgentCanon;
use Duo\Cloud\OriginBlobStore;
use Duo\Cloud\OriginFileBlobStore;
use Duo\Cloud\OriginProtocol;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/src/OriginAuthority.php';
require_once DUO_REPO_ROOT . '/cloud/src/OriginFileBlobStore.php';
require_once DUO_REPO_ROOT . '/agent/src/Kernel/Canon.php';
require_once DUO_REPO_ROOT . '/agent/src/Cloud/OriginCloudClient.php';

#[CoversNothing]
final class OriginAuthorityTest extends TestCase {
    private const NOW = 2000000000;

    private string $scratch;
    private string $statePath;
    private string $blobPath;
    private string $serviceSecret;
    private string $servicePublic;
    private string $originSecret;
    private string $originPublic;
    private string $originKeyId;
    private string $deviceDigestSecret;
    private int $now = self::NOW;
    private ReapInterruptingBlobStore $blobs;
    private OriginAuthority $authority;
    /** @var array<string,mixed> */
    private array $pairing;

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-origin-authority-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
        $this->blobPath = $this->scratch . '/blobs';
        self::assertTrue(mkdir($this->blobPath, 0700));
        self::assertTrue(chmod($this->blobPath, 0700));
        $this->statePath = $this->scratch . '/origin-state.json';

        $service = sodium_crypto_sign_keypair();
        $this->serviceSecret = sodium_crypto_sign_secretkey($service);
        $this->servicePublic = sodium_crypto_sign_publickey($service);
        $origin = sodium_crypto_sign_keypair();
        $this->originSecret = sodium_crypto_sign_secretkey($origin);
        $this->originPublic = sodium_crypto_sign_publickey($origin);
        $this->originKeyId = OriginProtocol::originKeyId($this->originPublic);
        $this->deviceDigestSecret = random_bytes(32);
        $this->blobs = new ReapInterruptingBlobStore(new OriginFileBlobStore($this->blobPath));
        $this->authority = new OriginAuthority(
            new FileAuthorityStore($this->statePath),
            $this->blobs,
            'service-key-v1',
            $this->serviceSecret,
            $this->deviceDigestSecret,
            fn (): int => $this->now
        );
        $this->pairing = $this->pair();
    }

    protected function tearDown(): void {
        unset($this->authority);
        if (isset($this->scratch) && is_dir($this->scratch)) {
            $this->removeTree($this->scratch);
        }
        foreach (['serviceSecret', 'originSecret', 'deviceDigestSecret'] as $property) {
            if (isset($this->{$property}) && $this->{$property} !== '') {
                sodium_memzero($this->{$property});
            }
        }
    }

    public function testPairingConsumesOnlyAKeyedDigestAndReplaysLostResponsesExactly(): void {
        $issued = $this->authority->issueDeviceCode('tenant-b', 'site-b', 300);
        $secondPair = sodium_crypto_sign_keypair();
        $secondSecret = sodium_crypto_sign_secretkey($secondPair);
        $secondPublic = sodium_crypto_sign_publickey($secondPair);
        $secondKeyId = OriginProtocol::originKeyId($secondPublic);
        $attempt = str_repeat('b', 32);
        $payload = $this->pairBeginPayload($issued['device_code'], $attempt, $secondPublic, $secondKeyId);
        $request = $this->signed($payload, $secondSecret, $secondKeyId);
        $first = $this->authority->handle(OriginProtocol::PAIR_BEGIN_PATH, $request);
        $second = $this->authority->handle(OriginProtocol::PAIR_BEGIN_PATH, $request);
        self::assertSame($first, $second);

        $changed = $payload;
        $changed['connector']['wordpress_version'] = 'changed-but-same-request-id';
        $this->assertGenericRefusal(fn (): string => $this->authority->handle(
            OriginProtocol::PAIR_BEGIN_PATH,
            $this->signed($changed, $secondSecret, $secondKeyId)
        ));

        $reuse = $this->pairBeginPayload(
            $issued['device_code'],
            str_repeat('c', 32),
            $secondPublic,
            $secondKeyId
        );
        $rejected = $this->payload($this->authority->handle(
            OriginProtocol::PAIR_BEGIN_PATH,
            $this->signed($reuse, $secondSecret, $secondKeyId)
        ));
        self::assertSame(OriginProtocol::REFUSAL, $rejected['format']);
        self::assertSame('pairing_code_rejected', $rejected['reason_code']);
        self::assertSame(OriginProtocol::PAIR_BEGIN_REQUEST, $rejected['request_format']);
        self::assertFalse($rejected['retryable']);

        $unknown = $this->pairBeginPayload(
            'ZZZZZ-ZZZZZ-ZZZZZ-ZZZZZ',
            str_repeat('d', 32),
            $secondPublic,
            $secondKeyId
        );
        $unknownRefusal = $this->payload($this->authority->handle(
            OriginProtocol::PAIR_BEGIN_PATH,
            $this->signed($unknown, $secondSecret, $secondKeyId)
        ));
        self::assertSame('pairing_code_rejected', $unknownRefusal['reason_code']);
        self::assertFalse($unknownRefusal['retryable']);

        $state = (string) file_get_contents($this->statePath);
        self::assertStringNotContainsString($issued['device_code'], $state);
        self::assertStringNotContainsString($this->deviceDigestSecret, $state);
        self::assertStringNotContainsString($this->serviceSecret, $state);
        self::assertSame(0600, fileperms($this->statePath) & 0777);
        self::assertSame(0600, fileperms($this->statePath . '.lock') & 0777);
        sodium_memzero($secondSecret);
    }

    public function testPairingAuthorityRecordsAreBoundedAndReleasedWithoutLosingCurrentGeneration(): void {
        for ($index = 0; $index < 8; $index++) {
            $this->authority->issueDeviceCode('tenant-capacity', 'site-codes');
        }
        try {
            $this->authority->issueDeviceCode('tenant-capacity', 'site-codes');
            self::fail('a ninth live device code was accepted for one site');
        } catch (ControlRefusal $error) {
            self::assertSame('origin device-code capacity is exhausted', $error->getMessage());
        }

        $this->now += 601;
        self::assertSame(0, $this->authority->reapExpiredExports(1));
        $this->authority->issueDeviceCode('tenant-capacity', 'site-codes');
        self::assertSame(1, $this->authorityRecordCount('device_codes', 'tenant-capacity', 'site-codes'));

        for ($index = 0; $index < 8; $index++) {
            $keypair = sodium_crypto_sign_keypair();
            $secret = sodium_crypto_sign_secretkey($keypair);
            $public = sodium_crypto_sign_publickey($keypair);
            $keyId = OriginProtocol::originKeyId($public);
            $issued = $this->authority->issueDeviceCode('tenant-capacity', 'site-pairings');
            $attempt = str_pad(dechex($index + 1), 32, '0', STR_PAD_LEFT);
            $begin = $this->payload($this->authority->handle(
                OriginProtocol::PAIR_BEGIN_PATH,
                $this->signed(
                    $this->pairBeginPayload($issued['device_code'], $attempt, $public, $keyId),
                    $secret,
                    $keyId
                )
            ));
            $this->authority->denyPairing($begin['pairing_id']);
            sodium_memzero($secret);
        }
        $overflowKeypair = sodium_crypto_sign_keypair();
        $overflowSecret = sodium_crypto_sign_secretkey($overflowKeypair);
        $overflowPublic = sodium_crypto_sign_publickey($overflowKeypair);
        $overflowKeyId = OriginProtocol::originKeyId($overflowPublic);
        $overflowCode = $this->authority->issueDeviceCode('tenant-capacity', 'site-pairings');
        $overflowBegin = $this->payload($this->authority->handle(
            OriginProtocol::PAIR_BEGIN_PATH,
            $this->signed(
                $this->pairBeginPayload(
                    $overflowCode['device_code'],
                    str_repeat('f', 32),
                    $overflowPublic,
                    $overflowKeyId
                ),
                $overflowSecret,
                $overflowKeyId
            )
        ));
        self::assertSame('pending', $overflowBegin['state']);
        $this->authority->denyPairing($overflowBegin['pairing_id']);
        sodium_memzero($overflowSecret);
        self::assertSame(8, $this->authorityRecordCount('pairings', 'tenant-capacity', 'site-pairings'));
        self::assertSame(8, $this->authorityRecordCount('keys', 'tenant-capacity', 'site-pairings'));

        $this->now += 87001;
        self::assertSame(0, $this->authority->reapExpiredExports(1));
        self::assertSame(0, $this->authorityRecordCount('pairings', 'tenant-capacity', 'site-pairings'));
        self::assertSame(0, $this->authorityRecordCount('keys', 'tenant-capacity', 'site-pairings'));

        $currentGeneration = 1;
        $currentSecret = $this->originSecret;
        for ($generation = 2; $generation <= 1002; $generation++) {
            $keypair = sodium_crypto_sign_keypair();
            $nextSecret = sodium_crypto_sign_secretkey($keypair);
            $nextPublic = sodium_crypto_sign_publickey($keypair);
            $nextKeyId = OriginProtocol::originKeyId($nextPublic);
            $issued = $this->authority->issueDeviceCode('tenant-a', 'site-a');
            $attempt = str_pad(dechex($generation), 32, '0', STR_PAD_LEFT);
            $begin = $this->payload($this->authority->handle(
                OriginProtocol::PAIR_BEGIN_PATH,
                $this->signed(
                    $this->pairBeginPayload($issued['device_code'], $attempt, $nextPublic, $nextKeyId),
                    $nextSecret,
                    $nextKeyId
                )
            ));
            $poll = [
                'format' => OriginProtocol::PAIR_POLL_REQUEST,
                'origin_key_id' => $nextKeyId,
                'pair_attempt_id' => $attempt,
                'pairing_id' => $begin['pairing_id'],
                'poll_sequence' => 0,
                'request_id' => OriginProtocol::pairPollRequestId($attempt, $begin['pairing_id'], 0),
            ];
            $paired = $this->payload($this->authority->handle(
                OriginProtocol::PAIR_POLL_PATH,
                $this->signed($poll, $nextSecret, $nextKeyId)
            ));
            self::assertSame($generation, $paired['pairing']['origin_generation']);
            if ($currentSecret !== $this->originSecret) {
                sodium_memzero($currentSecret);
            }
            $currentSecret = $nextSecret;
            $currentGeneration = $generation;
        }
        self::assertSame(0, $this->authority->reapExpiredExports(1));
        self::assertLessThanOrEqual(2, $this->authorityRecordCount('pairings', 'tenant-a', 'site-a'));
        self::assertLessThanOrEqual(2, $this->authorityRecordCount('keys', 'tenant-a', 'site-a'));
        $currentState = $this->readAuthorityState();
        $site = $currentState['sites'][hash(
            'sha256',
            "duo-cloud-origin-site/v1\0tenant-a\0site-a"
        )];
        self::assertSame($currentGeneration, $site['origin_generation']);
        self::assertArrayHasKey($site['active_key_id'], $currentState['keys']);
        sodium_memzero($currentSecret);
    }

    public function testExpiredPendingPairingReleasesItsSiteReservationAndStillReturnsExpired(): void {
        $keypair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($keypair);
        $public = sodium_crypto_sign_publickey($keypair);
        $keyId = OriginProtocol::originKeyId($public);
        $attempt = str_repeat('e', 32);
        $issued = $this->authority->issueDeviceCode('tenant-expiry', 'site-expiry', 1);
        $begin = $this->payload($this->authority->handle(
            OriginProtocol::PAIR_BEGIN_PATH,
            $this->signed(
                $this->pairBeginPayload($issued['device_code'], $attempt, $public, $keyId),
                $secret,
                $keyId
            )
        ));
        $poll = [
            'format' => OriginProtocol::PAIR_POLL_REQUEST,
            'origin_key_id' => $keyId,
            'pair_attempt_id' => $attempt,
            'pairing_id' => $begin['pairing_id'],
            'poll_sequence' => 0,
            'request_id' => OriginProtocol::pairPollRequestId($attempt, $begin['pairing_id'], 0),
        ];

        $this->now++;
        $expiredRequest = $this->signed($poll, $secret, $keyId);
        $expired = $this->authority->handle(OriginProtocol::PAIR_POLL_PATH, $expiredRequest);
        self::assertSame('expired', $this->payload($expired)['state']);
        self::assertSame($expired, $this->authority->handle(OriginProtocol::PAIR_POLL_PATH, $expiredRequest));

        $replacement = sodium_crypto_sign_keypair();
        $replacementSecret = sodium_crypto_sign_secretkey($replacement);
        $replacementPublic = sodium_crypto_sign_publickey($replacement);
        $replacementKeyId = OriginProtocol::originKeyId($replacementPublic);
        $replacementCode = $this->authority->issueDeviceCode('tenant-expiry', 'site-expiry');
        $replacementBegin = $this->payload($this->authority->handle(
            OriginProtocol::PAIR_BEGIN_PATH,
            $this->signed(
                $this->pairBeginPayload(
                    $replacementCode['device_code'],
                    str_repeat('d', 32),
                    $replacementPublic,
                    $replacementKeyId
                ),
                $replacementSecret,
                $replacementKeyId
            )
        ));
        self::assertSame('pending', $replacementBegin['state']);
        sodium_memzero($secret);
        sodium_memzero($replacementSecret);
    }

    public function testPreCapacityStoreCompactsWithoutBlockingOtherSitesOrRequiredReplays(): void {
        $initialState = $this->readAuthorityState();
        $previousPairingId = null;
        foreach ($initialState['pairings'] as $pairingId => $pairing) {
            if (($pairing['tenant_id'] ?? null) === 'tenant-a'
                && ($pairing['site_id'] ?? null) === 'site-a'
                && ($pairing['origin_generation'] ?? null) === 1) {
                $previousPairingId = $pairingId;
                break;
            }
        }
        self::assertIsString($previousPairingId);
        $previousPoll = [
            'format' => OriginProtocol::PAIR_POLL_REQUEST,
            'origin_key_id' => $this->originKeyId,
            'pair_attempt_id' => str_repeat('a', 32),
            'pairing_id' => $previousPairingId,
            'poll_sequence' => 0,
            'request_id' => OriginProtocol::pairPollRequestId(
                str_repeat('a', 32),
                $previousPairingId,
                0
            ),
        ];
        $previousPollRequest = $this->signed(
            $previousPoll,
            $this->originSecret,
            $this->originKeyId
        );
        $previousPollResponse = $this->authority->handle(
            OriginProtocol::PAIR_POLL_PATH,
            $previousPollRequest
        );

        $currentPair = sodium_crypto_sign_keypair();
        $currentSecret = sodium_crypto_sign_secretkey($currentPair);
        $currentPublic = sodium_crypto_sign_publickey($currentPair);
        $currentKeyId = OriginProtocol::originKeyId($currentPublic);
        $currentAttempt = str_repeat('b', 32);
        $currentCode = $this->authority->issueDeviceCode('tenant-a', 'site-a');
        $currentBeginPayload = $this->pairBeginPayload(
            $currentCode['device_code'],
            $currentAttempt,
            $currentPublic,
            $currentKeyId
        );
        $currentBeginRequest = $this->signed($currentBeginPayload, $currentSecret, $currentKeyId);
        $currentBeginResponse = $this->authority->handle(
            OriginProtocol::PAIR_BEGIN_PATH,
            $currentBeginRequest
        );
        $currentBegin = $this->payload($currentBeginResponse);
        $currentPoll = [
            'format' => OriginProtocol::PAIR_POLL_REQUEST,
            'origin_key_id' => $currentKeyId,
            'pair_attempt_id' => $currentAttempt,
            'pairing_id' => $currentBegin['pairing_id'],
            'poll_sequence' => 0,
            'request_id' => OriginProtocol::pairPollRequestId(
                $currentAttempt,
                $currentBegin['pairing_id'],
                0
            ),
        ];
        $currentPollRequest = $this->signed($currentPoll, $currentSecret, $currentKeyId);
        $currentPollResponse = $this->authority->handle(
            OriginProtocol::PAIR_POLL_PATH,
            $currentPollRequest
        );
        self::assertSame(2, $this->payload($currentPollResponse)['pairing']['origin_generation']);

        for ($index = 0; $index < 8; $index++) {
            $this->authority->issueDeviceCode('tenant-legacy', 'site-codes');
        }
        $state = $this->readAuthorityState();
        $legacyCodeDigests = [];
        for ($index = 0; $index < 4; $index++) {
            $digest = hash('sha256', "legacy-device-code-$index");
            $legacyCodeDigests[] = $digest;
            $state['device_codes'][$digest] = [
                'consumed_pairing_id' => null,
                'digest' => $digest,
                'expires_at' => $this->now + 100 + $index,
                'site_id' => 'site-codes',
                'state' => 'issued',
                'tenant_id' => 'tenant-legacy',
            ];
        }

        $legacyPairingIds = [];
        for ($index = 0; $index < 12; $index++) {
            $keypair = sodium_crypto_sign_keypair();
            $public = sodium_crypto_sign_publickey($keypair);
            $keyId = OriginProtocol::originKeyId($public);
            $pairingId = hash('sha256', "legacy-denied-pairing-$index");
            $legacyPairingIds[] = $pairingId;
            $state['keys'][$keyId] = [
                'generation' => 1,
                'key_id' => $keyId,
                'public_key' => base64_encode($public),
                'site_id' => 'site-a',
                'state' => 'pending',
                'tenant_id' => 'tenant-a',
            ];
            $state['pairings'][$pairingId] = [
                'expires_at' => $this->now + 100 + $index,
                'origin_generation' => 1,
                'origin_key_id' => $keyId,
                'pair_attempt_id' => str_pad(dechex($index + 1), 32, '0', STR_PAD_LEFT),
                'pairing_id' => $pairingId,
                'site_id' => 'site-a',
                'state' => 'denied',
                'tenant_id' => 'tenant-a',
            ];
        }
        $this->writeAuthorityState($state);

        $this->authority->issueDeviceCode('unrelated-tenant', 'unrelated-site');
        $compacted = $this->readAuthorityState();
        self::assertSame(8, $this->authorityRecordCount('device_codes', 'tenant-legacy', 'site-codes'));
        foreach ($legacyCodeDigests as $digest) {
            self::assertArrayNotHasKey($digest, $compacted['device_codes']);
        }
        self::assertSame(8, $this->authorityRecordCount('pairings', 'tenant-a', 'site-a'));
        self::assertSame(8, $this->authorityRecordCount('keys', 'tenant-a', 'site-a'));
        $expectedLegacyPairings = array_slice($legacyPairingIds, 6);
        sort($expectedLegacyPairings, SORT_STRING);
        $retainedLegacyPairings = array_values(array_filter(
            $legacyPairingIds,
            static fn(string $pairingId): bool => isset($compacted['pairings'][$pairingId])
        ));
        sort($retainedLegacyPairings, SORT_STRING);
        self::assertSame($expectedLegacyPairings, $retainedLegacyPairings);
        foreach ($retainedLegacyPairings as $pairingId) {
            $keyId = $compacted['pairings'][$pairingId]['origin_key_id'];
            self::assertSame('expired', $compacted['keys'][$keyId]['state']);
        }
        self::assertSame(
            $previousPollResponse,
            $this->authority->handle(OriginProtocol::PAIR_POLL_PATH, $previousPollRequest)
        );
        self::assertSame(
            $currentBeginResponse,
            $this->authority->handle(OriginProtocol::PAIR_BEGIN_PATH, $currentBeginRequest)
        );
        self::assertSame(
            $currentPollResponse,
            $this->authority->handle(OriginProtocol::PAIR_POLL_PATH, $currentPollRequest)
        );

        $nextPair = sodium_crypto_sign_keypair();
        $nextSecret = sodium_crypto_sign_secretkey($nextPair);
        $nextPublic = sodium_crypto_sign_publickey($nextPair);
        $nextKeyId = OriginProtocol::originKeyId($nextPublic);
        $nextAttempt = str_repeat('c', 32);
        $nextCode = $this->authority->issueDeviceCode('tenant-a', 'site-a');
        $nextBegin = $this->payload($this->authority->handle(
            OriginProtocol::PAIR_BEGIN_PATH,
            $this->signed(
                $this->pairBeginPayload(
                    $nextCode['device_code'],
                    $nextAttempt,
                    $nextPublic,
                    $nextKeyId
                ),
                $nextSecret,
                $nextKeyId
            )
        ));
        $nextPoll = [
            'format' => OriginProtocol::PAIR_POLL_REQUEST,
            'origin_key_id' => $nextKeyId,
            'pair_attempt_id' => $nextAttempt,
            'pairing_id' => $nextBegin['pairing_id'],
            'poll_sequence' => 0,
            'request_id' => OriginProtocol::pairPollRequestId(
                $nextAttempt,
                $nextBegin['pairing_id'],
                0
            ),
        ];
        $nextPaired = $this->payload($this->authority->handle(
            OriginProtocol::PAIR_POLL_PATH,
            $this->signed($nextPoll, $nextSecret, $nextKeyId)
        ));
        self::assertSame(3, $nextPaired['pairing']['origin_generation']);
        self::assertLessThanOrEqual(8, $this->authorityRecordCount('pairings', 'tenant-a', 'site-a'));
        self::assertLessThanOrEqual(10, $this->authorityRecordCount('keys', 'tenant-a', 'site-a'));
        sodium_memzero($currentSecret);
        sodium_memzero($nextSecret);
    }

    public function testWrongSignatureTenantSiteAndGenerationNeverCrossAuthority(): void {
        $poll = $this->demandPollPayload(0, 0);
        $foreign = sodium_crypto_sign_keypair();
        $foreignSecret = sodium_crypto_sign_secretkey($foreign);
        $this->assertGenericRefusal(fn (): string => $this->authority->handle(
            OriginProtocol::DEMAND_POLL_PATH,
            $this->signed($poll, $foreignSecret, $this->originKeyId)
        ));

        $wrongTenant = $poll;
        $wrongTenant['tenant_id'] = 'tenant-b';
        $wrongTenant['request_id'] = OriginProtocol::demandPollRequestId(
            'tenant-b',
            'site-a',
            1,
            $this->originKeyId,
            0,
            0
        );
        $this->assertGenericRefusal(fn (): string => $this->authority->handle(
            OriginProtocol::DEMAND_POLL_PATH,
            $this->signed($wrongTenant, $this->originSecret, $this->originKeyId)
        ));

        $stale = $poll;
        $stale['origin_generation'] = 2;
        $stale['request_id'] = OriginProtocol::demandPollRequestId(
            'tenant-a',
            'site-a',
            2,
            $this->originKeyId,
            0,
            0
        );
        $refusal = $this->payload($this->authority->handle(
            OriginProtocol::DEMAND_POLL_PATH,
            $this->signed($stale, $this->originSecret, $this->originKeyId)
        ));
        self::assertSame(OriginProtocol::REFUSAL, $refusal['format']);
        self::assertSame('stale_origin_generation', $refusal['reason_code']);
        self::assertFalse($refusal['retryable']);
        sodium_memzero($foreignSecret);
    }

    public function testDemandManifestMissingChunkCommitAndControllerReadAreExact(): void {
        $demand = $this->publishDemand();
        $pollRequest = $this->signed($this->demandPollPayload(0, 1), $this->originSecret, $this->originKeyId);
        $firstPoll = $this->authority->handle(OriginProtocol::DEMAND_POLL_PATH, $pollRequest);
        self::assertSame($firstPoll, $this->authority->handle(OriginProtocol::DEMAND_POLL_PATH, $pollRequest));
        $poll = $this->payload($firstPoll);
        self::assertSame('demanded', $poll['state']);
        self::assertSame(CanonicalJson::encode($demand), CanonicalJson::encode($poll['demand']));

        [$exportBytes, $manifest] = $this->exportFixture($demand);
        [$exportId, $announceRequest] = $this->announce($demand, $manifest);
        self::assertSame(
            $announceRequest,
            $this->authority->handle(
                OriginProtocol::ANNOUNCE_PATH,
                $this->signed($this->announcePayload($demand, $manifest), $this->originSecret, $this->originKeyId)
            )
        );

        $missing = $this->payload($this->authority->handle(
            OriginProtocol::MISSING_PATH,
            $this->signed(
                $this->missingPayload($demand, $exportId, $manifest['manifest_sha256'], 0),
                $this->originSecret,
                $this->originKeyId
            )
        ));
        self::assertSame([$manifest['chunks'][0]['sha256']], $missing['missing_chunk_sha256']);

        $chunkPayload = $this->chunkPayload($demand, $exportId, $manifest, $exportBytes);
        $chunkRequest = $this->signed($chunkPayload, $this->originSecret, $this->originKeyId);
        $stored = $this->authority->handle(OriginProtocol::CHUNK_PATH, $chunkRequest);
        self::assertSame($stored, $this->authority->handle(OriginProtocol::CHUNK_PATH, $chunkRequest));

        $noneMissing = $this->payload($this->authority->handle(
            OriginProtocol::MISSING_PATH,
            $this->signed(
                $this->missingPayload($demand, $exportId, $manifest['manifest_sha256'], 1),
                $this->originSecret,
                $this->originKeyId
            )
        ));
        self::assertSame([], $noneMissing['missing_chunk_sha256']);

        $commitPayload = $this->commitPayload($demand, $exportId, $manifest['manifest_sha256']);
        $commitRequest = $this->signed($commitPayload, $this->originSecret, $this->originKeyId);
        $commit = $this->authority->handle(OriginProtocol::COMMIT_PATH, $commitRequest);
        self::assertSame($commit, $this->authority->handle(OriginProtocol::COMMIT_PATH, $commitRequest));
        self::assertSame('committed', $this->payload($commit)['state']);
        self::assertSame(
            CanonicalJson::encode($manifest),
            CanonicalJson::encode($this->authority->readCommittedManifest('tenant-a', 'site-a', $exportId))
        );
        self::assertSame($exportBytes, $this->authority->readCommittedChunk('tenant-a', 'site-a', $exportId, 0));

        $state = (string) file_get_contents($this->statePath);
        self::assertStringNotContainsString('state-secret-sentinel', $state);
        self::assertStringNotContainsString((string) $chunkPayload['chunk_base64'], $state);
    }

    public function testRetentionDeadlineFailsClosedAndDeletionResumesAfterLostResponse(): void {
        $demand = $this->publishDemand();
        [$exportBytes, $manifest] = $this->exportFixture($demand);
        [$exportId] = $this->announce($demand, $manifest);
        $this->authority->handle(
            OriginProtocol::CHUNK_PATH,
            $this->signed(
                $this->chunkPayload($demand, $exportId, $manifest, $exportBytes),
                $this->originSecret,
                $this->originKeyId
            )
        );
        $commitPayload = $this->commitPayload($demand, $exportId, $manifest['manifest_sha256']);
        $commitRequest = $this->signed($commitPayload, $this->originSecret, $this->originKeyId);
        $commitResponse = $this->authority->handle(OriginProtocol::COMMIT_PATH, $commitRequest);

        self::assertSame(0, $this->authority->reapExpiredExports(1));
        self::assertSame($exportBytes, $this->authority->readCommittedChunk(
            'tenant-a',
            'site-a',
            $exportId,
            0
        ));

        $this->now = $demand['retention_deadline'];
        try {
            $this->authority->readCommittedManifest('tenant-a', 'site-a', $exportId);
            self::fail('expected retained export to become unavailable at its deadline');
        } catch (ControlRefusal $error) {
            self::assertSame('committed export is unavailable', $error->getMessage());
        }
        $this->blobs->interruptNextDeleteAfterRemoval();
        try {
            $this->authority->reapExpiredExports(1);
            self::fail('expected simulated lost deletion response');
        } catch (ControlRefusal $error) {
            self::assertSame('simulated lost origin blob deletion response', $error->getMessage());
        }
        self::assertSame([], $this->regularFiles($this->blobPath . '/objects'));
        self::assertStringContainsString('"state":"reaping"', (string) file_get_contents($this->statePath));

        self::assertSame(1, $this->authority->reapExpiredExports(1));
        self::assertSame(0, $this->authority->reapExpiredExports(1));
        self::assertStringNotContainsString($exportId, (string) file_get_contents($this->statePath));
        $expiredResponse = $this->authority->handle(
            OriginProtocol::COMMIT_PATH,
            $commitRequest
        );
        self::assertNotSame($commitResponse, $expiredResponse);
        $expiredReplay = $this->payload($expiredResponse);
        self::assertSame(OriginProtocol::REFUSAL, $expiredReplay['format']);
        self::assertSame('stale_demand_generation', $expiredReplay['reason_code']);
        try {
            $this->authority->readCommittedChunk('tenant-a', 'site-a', $exportId, 0);
            self::fail('expected reaped export bytes to remain unavailable');
        } catch (ControlRefusal $error) {
            self::assertSame('committed export is unavailable', $error->getMessage());
        }
    }

    public function testRetainedExportCountIsBoundedBeforeAnotherOriginCapture(): void {
        $lastRequestId = '';
        $lastDemand = [];
        for ($generation = 1; $generation <= 8; $generation++) {
            $lastRequestId = hash('sha256', "retained-export-count-$generation");
            $demand = $this->authority->requestPortableDemand(
                'tenant-a',
                'site-a',
                $lastRequestId,
                str_repeat('a', 40)
            );
            [$bytes, $manifest] = $this->exportFixture($demand);
            [$exportId] = $this->announce($demand, $manifest);
            if ($generation === 1) {
                // One announced, partially uploaded scope must consume the
                // same retention reservation as a committed artifact.
                $this->now += 601;
                continue;
            }
            $this->authority->handle(
                OriginProtocol::CHUNK_PATH,
                $this->signed(
                    $this->chunkPayload($demand, $exportId, $manifest, $bytes),
                    $this->originSecret,
                    $this->originKeyId
                )
            );
            $commit = $this->authority->handle(
                OriginProtocol::COMMIT_PATH,
                $this->signed(
                    $this->commitPayload($demand, $exportId, $manifest['manifest_sha256']),
                    $this->originSecret,
                    $this->originKeyId
                )
            );
            self::assertSame('committed', $this->payload($commit)['state']);
            $lastDemand = $demand;
        }

        $before = hash_file('sha256', $this->statePath);
        try {
            $this->authority->requestPortableDemand(
                'tenant-a',
                'site-a',
                hash('sha256', 'retained-export-count-overflow'),
                str_repeat('a', 40)
            );
            self::fail('a ninth retained export demand was accepted');
        } catch (ControlRefusal $error) {
            self::assertSame('origin retained export capacity is exhausted', $error->getMessage());
        }
        self::assertSame($before, hash_file('sha256', $this->statePath));
        self::assertSame(
            CanonicalJson::encode($lastDemand),
            CanonicalJson::encode($this->authority->requestPortableDemand(
                'tenant-a',
                'site-a',
                $lastRequestId,
                str_repeat('a', 40)
            ))
        );

        $this->now = self::NOW + 90000;
        self::assertSame(8, $this->authority->reapExpiredExports(100));
        $next = $this->authority->requestPortableDemand(
            'tenant-a',
            'site-a',
            hash('sha256', 'retained-export-count-after-reap'),
            str_repeat('a', 40)
        );
        self::assertSame(9, $next['demand_generation']);
    }

    public function testRetainedExportByteReservationIsBoundedAndReleasedByReap(): void {
        for ($generation = 1; $generation <= 7; $generation++) {
            $demand = $this->authority->requestPortableDemand(
                'tenant-a',
                'site-a',
                hash('sha256', "retained-export-bytes-$generation"),
                str_repeat('a', 40)
            );
            [, $manifest] = $this->exportFixture($demand);
            $this->announce($demand, $this->maxSizedManifest($manifest));
            $this->now += 601;
        }

        try {
            $this->authority->requestPortableDemand(
                'tenant-a',
                'site-a',
                hash('sha256', 'retained-export-bytes-overflow'),
                str_repeat('a', 40)
            );
            self::fail('aggregate retained-export reservation was exceeded');
        } catch (ControlRefusal $error) {
            self::assertSame('origin retained export capacity is exhausted', $error->getMessage());
        }

        $this->now = self::NOW + 86400;
        self::assertSame(1, $this->authority->reapExpiredExports(1));
        $next = $this->authority->requestPortableDemand(
            'tenant-a',
            'site-a',
            hash('sha256', 'retained-export-bytes-after-reap'),
            str_repeat('a', 40)
        );
        self::assertSame(8, $next['demand_generation']);
    }

    public function testEndpointReceiptCountIsHardBoundedWithinOneDemandGeneration(): void {
        $demand = $this->publishDemand();
        $firstRequest = null;
        $firstResponse = null;
        for ($sequence = 0; $sequence < 512; $sequence++) {
            $request = $this->signed(
                $this->demandPollPayload(0, $sequence),
                $this->originSecret,
                $this->originKeyId
            );
            $response = $this->authority->handle(OriginProtocol::DEMAND_POLL_PATH, $request);
            if ($sequence === 0) {
                $firstRequest = $request;
                $firstResponse = $response;
            }
        }
        $this->assertGenericRefusal(fn (): string => $this->authority->handle(
            OriginProtocol::DEMAND_POLL_PATH,
            $this->signed(
                $this->demandPollPayload(0, 512),
                $this->originSecret,
                $this->originKeyId
            )
        ));
        self::assertIsString($firstRequest);
        self::assertSame(
            $firstResponse,
            $this->authority->handle(OriginProtocol::DEMAND_POLL_PATH, $firstRequest)
        );
        self::assertSame(1, $demand['demand_generation']);
    }

    public function testManifestConflictIncompleteCommitAndChunkTamperAreSignedRefusals(): void {
        $demand = $this->publishDemand();
        [$exportBytes, $manifest] = $this->exportFixture($demand);
        [$exportId] = $this->announce($demand, $manifest);

        $changed = $manifest;
        $changed['artifact_hash'] = hash('sha256', 'different-artifact');
        unset($changed['manifest_sha256']);
        $changed['manifest_sha256'] = hash('sha256', OriginAgentCanon::encode($changed));
        $conflict = $this->payload($this->authority->handle(
            OriginProtocol::ANNOUNCE_PATH,
            $this->signed($this->announcePayload($demand, $changed), $this->originSecret, $this->originKeyId)
        ));
        self::assertSame('manifest_conflict', $conflict['reason_code']);

        $commit = $this->payload($this->authority->handle(
            OriginProtocol::COMMIT_PATH,
            $this->signed(
                $this->commitPayload($demand, $exportId, $manifest['manifest_sha256']),
                $this->originSecret,
                $this->originKeyId
            )
        ));
        self::assertSame('commit_incomplete', $commit['reason_code']);
        self::assertTrue($commit['retryable']);

        $this->authority->handle(
            OriginProtocol::CHUNK_PATH,
            $this->signed(
                $this->chunkPayload($demand, $exportId, $manifest, $exportBytes),
                $this->originSecret,
                $this->originKeyId
            )
        );
        $chunkFiles = $this->regularFiles($this->blobPath . '/objects');
        $chunkFiles = array_values(array_filter(
            $chunkFiles,
            static fn (string $path): bool => basename($path) === $manifest['chunks'][0]['sha256']
        ));
        self::assertCount(1, $chunkFiles);
        self::assertSame(strlen($exportBytes), file_put_contents($chunkFiles[0], str_repeat('x', strlen($exportBytes))));
        self::assertTrue(chmod($chunkFiles[0], 0600));
        $tampered = $this->payload($this->authority->handle(
            OriginProtocol::MISSING_PATH,
            $this->signed(
                $this->missingPayload($demand, $exportId, $manifest['manifest_sha256'], 7),
                $this->originSecret,
                $this->originKeyId
            )
        ));
        self::assertSame('recovery_required', $tampered['reason_code']);
    }

    public function testRotationRequiresNewKeyProofAndOnlyRotateRevokeReceiptsSurviveTombstones(): void {
        $idlePollPayload = $this->demandPollPayload(0, 8);
        $idlePollRequest = $this->signed($idlePollPayload, $this->originSecret, $this->originKeyId);
        $this->authority->handle(OriginProtocol::DEMAND_POLL_PATH, $idlePollRequest);

        $newPair = sodium_crypto_sign_keypair();
        $newSecret = sodium_crypto_sign_secretkey($newPair);
        $newPublic = sodium_crypto_sign_publickey($newPair);
        $newKeyId = OriginProtocol::originKeyId($newPublic);
        $rotationId = OriginProtocol::rotationRequestId('tenant-a', 'site-a', 1, $newKeyId);
        $proof = [
            'format' => OriginProtocol::ROTATE_PROOF,
            'new_origin_key_id' => $newKeyId,
            'next_origin_generation' => 2,
            'origin_generation' => 1,
            'rotation_id' => $rotationId,
            'site_id' => 'site-a',
            'tenant_id' => 'tenant-a',
        ];
        $rotation = [
            'format' => OriginProtocol::ROTATE_REQUEST,
            'new_key' => [
                'algorithm' => 'Ed25519',
                'key_id' => $newKeyId,
                'public_key' => base64_encode($newPublic),
            ],
            'new_key_proof' => base64_encode(sodium_crypto_sign_detached(
                CanonicalJson::encode($proof),
                $newSecret
            )),
            'next_origin_generation' => 2,
            'origin_generation' => 1,
            'origin_key_id' => $this->originKeyId,
            'request_id' => $rotationId,
            'rotation_id' => $rotationId,
            'site_id' => 'site-a',
            'tenant_id' => 'tenant-a',
        ];
        $rotationRequest = $this->signed($rotation, $this->originSecret, $this->originKeyId);
        $rotated = $this->authority->handle(OriginProtocol::ROTATE_PATH, $rotationRequest);
        self::assertSame($rotated, $this->authority->handle(OriginProtocol::ROTATE_PATH, $rotationRequest));
        self::assertSame('rotated', $this->payload($rotated)['state']);

        $stale = $this->payload($this->authority->handle(OriginProtocol::DEMAND_POLL_PATH, $idlePollRequest));
        self::assertSame('stale_origin_generation', $stale['reason_code']);

        $revocationId = OriginProtocol::revokeRequestId(
            'tenant-a',
            'site-a',
            2,
            'administrator_requested'
        );
        $revoke = [
            'format' => OriginProtocol::REVOKE_REQUEST,
            'origin_generation' => 2,
            'origin_key_id' => $newKeyId,
            'reason' => 'administrator_requested',
            'request_id' => $revocationId,
            'revocation_id' => $revocationId,
            'site_id' => 'site-a',
            'tenant_id' => 'tenant-a',
        ];
        $revokeRequest = $this->signed($revoke, $newSecret, $newKeyId);
        $revoked = $this->authority->handle(OriginProtocol::REVOKE_PATH, $revokeRequest);
        self::assertSame($revoked, $this->authority->handle(OriginProtocol::REVOKE_PATH, $revokeRequest));
        $revokedPayload = $this->payload($revoked);
        self::assertSame('revoked', $revokedPayload['state']);
        self::assertArrayHasKey('revocation_receipt_sha256', $revokedPayload);

        $after = $this->demandPollPayload(0, 9, 2, $newKeyId);
        $afterResponse = $this->payload($this->authority->handle(
            OriginProtocol::DEMAND_POLL_PATH,
            $this->signed($after, $newSecret, $newKeyId)
        ));
        self::assertSame('origin_revoked', $afterResponse['reason_code']);
        sodium_memzero($newSecret);
    }

    public function testControllerReadDoesNotExposeCrossTenantPresence(): void {
        $demand = $this->publishDemand();
        [$bytes, $manifest] = $this->exportFixture($demand);
        [$exportId] = $this->announce($demand, $manifest);
        $this->authority->handle(
            OriginProtocol::CHUNK_PATH,
            $this->signed(
                $this->chunkPayload($demand, $exportId, $manifest, $bytes),
                $this->originSecret,
                $this->originKeyId
            )
        );
        $this->authority->handle(
            OriginProtocol::COMMIT_PATH,
            $this->signed(
                $this->commitPayload($demand, $exportId, $manifest['manifest_sha256']),
                $this->originSecret,
                $this->originKeyId
            )
        );
        foreach ([
            ['tenant-b', 'site-a', $exportId],
            ['tenant-a', 'site-b', $exportId],
            ['tenant-a', 'site-a', str_repeat('a', 64)],
        ] as [$tenantId, $siteId, $candidateId]) {
            try {
                $this->authority->readCommittedManifest($tenantId, $siteId, $candidateId);
                self::fail('expected unavailable controller export');
            } catch (ControlRefusal $error) {
                self::assertSame('committed export is unavailable', $error->getMessage());
            }
        }
    }

    public function testFilesystemBlobStoreRejectsSymlinkRootsAndProtectsPublishedBytes(): void {
        $link = $this->scratch . '/blob-link';
        self::assertTrue(symlink($this->blobPath, $link));
        try {
            new OriginFileBlobStore($link);
            self::fail('expected a symlink blob root to be refused');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('non-symlink directory', $error->getMessage());
        }
        self::assertTrue(unlink($link));

        $demand = $this->publishDemand();
        [$bytes, $manifest] = $this->exportFixture($demand);
        [$exportId] = $this->announce($demand, $manifest);
        $this->authority->handle(
            OriginProtocol::CHUNK_PATH,
            $this->signed(
                $this->chunkPayload($demand, $exportId, $manifest, $bytes),
                $this->originSecret,
                $this->originKeyId
            )
        );
        foreach ($this->regularFiles($this->blobPath) as $file) {
            self::assertFalse(is_link($file));
            self::assertSame(0600, fileperms($file) & 0777);
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->blobPath, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                self::assertSame(0700, $entry->getPerms() & 0777);
            }
        }
    }

    public function testKilledBlobWritesRemainBoundedAndExactRetryThenReapConverges(): void {
        if (!function_exists('posix_kill')) {
            self::markTestSkipped('posix is required for the blob-store SIGKILL regression');
        }
        $demand = $this->publishDemand();
        [$bytes, $manifest] = $this->exportFixture($demand);
        [$exportId] = $this->announce($demand, $manifest);
        $source = $this->scratch . '/killed-origin-blob-source';
        self::assertSame(strlen($bytes), file_put_contents($source, $bytes));
        self::assertTrue(chmod($source, 0600));
        $sha256 = $manifest['chunks'][0]['sha256'];
        $temporary = null;

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->killBlobChunkWrite($source, $demand['demand_generation'], $exportId, $sha256);
            $files = $this->regularFiles($this->blobPath . '/objects');
            self::assertCount(1, $files);
            self::assertSame(strlen($bytes), filesize($files[0]));
            self::assertStringEndsWith($sha256 . '.write', $files[0]);
            $temporary ??= $files[0];
            self::assertSame($temporary, $files[0]);
        }

        $stored = $this->payload($this->authority->handle(
            OriginProtocol::CHUNK_PATH,
            $this->signed(
                $this->chunkPayload($demand, $exportId, $manifest, $bytes),
                $this->originSecret,
                $this->originKeyId
            )
        ));
        self::assertSame('stored', $stored['state']);
        $files = $this->regularFiles($this->blobPath . '/objects');
        self::assertCount(1, $files);
        self::assertSame($sha256, basename($files[0]));

        $secondBytes = $bytes . 'second-destination';
        $secondSource = $this->scratch . '/killed-origin-blob-second-source';
        self::assertSame(strlen($secondBytes), file_put_contents($secondSource, $secondBytes));
        self::assertTrue(chmod($secondSource, 0600));
        $secondSha256 = hash('sha256', $secondBytes);
        $this->killBlobChunkWrite(
            $secondSource,
            $demand['demand_generation'],
            $exportId,
            $secondSha256
        );
        self::assertCount(2, $this->regularFiles($this->blobPath . '/objects'));

        $this->now = $demand['retention_deadline'];
        self::assertSame(1, $this->authority->reapExpiredExports(1));
        self::assertSame([], $this->regularFiles($this->blobPath . '/objects'));
        self::assertSame(0, $this->authority->reapExpiredExports(1));
    }

    public function testRealOriginClientInteroperatesAcrossDemandAndExportSuccessPath(): void {
        $previous = getenv('DUO_TEST_MODE');
        putenv('DUO_TEST_MODE=1');
        try {
            $client = new OriginCloudClient(
                'http://127.0.0.1',
                'service-key-v1',
                $this->servicePublic,
                $this->originSecret,
                function (string $url, string $request): array {
                    $path = parse_url($url, PHP_URL_PATH);
                    self::assertIsString($path);
                    try {
                        return [
                            'body' => $this->authority->handle($path, $request),
                            'effective_url' => $url,
                            'redirected' => false,
                            'status' => 200,
                        ];
                    } catch (ControlRefusal) {
                        return [
                            'body' => "{\"error\":\"invalid_request\"}\n",
                            'effective_url' => $url,
                            'redirected' => false,
                            'status' => 403,
                        ];
                    }
                }
            );
            $demand = $this->publishDemand();
            self::assertSame('demanded', $client->demandPoll('tenant-a', 'site-a', 1, 0, 0)['state']);
            [$bytes, $manifest] = $this->exportFixture($demand);
            $announced = $client->announce(
                'tenant-a',
                'site-a',
                1,
                1,
                $demand['demand_id'],
                $manifest
            );
            $exportId = $announced['export_id'];
            self::assertSame([$manifest['chunks'][0]['sha256']], $client->missing(
                'tenant-a',
                'site-a',
                1,
                1,
                $demand['demand_id'],
                $exportId,
                $manifest['manifest_sha256'],
                0,
                [$manifest['chunks'][0]['sha256']]
            )['missing_chunk_sha256']);
            self::assertSame('stored', $client->putChunk(
                'tenant-a',
                'site-a',
                1,
                1,
                $demand['demand_id'],
                $exportId,
                $manifest['manifest_sha256'],
                $manifest['chunks'][0]['sha256'],
                $bytes
            )['state']);
            self::assertSame('committed', $client->commit(
                'tenant-a',
                'site-a',
                1,
                1,
                $demand['demand_id'],
                $exportId,
                $manifest['manifest_sha256']
            )['state']);
            unset($client);
        } finally {
            $previous === false ? putenv('DUO_TEST_MODE') : putenv('DUO_TEST_MODE=' . $previous);
        }
    }

    /** @return array<string,mixed> */
    private function pair(): array {
        $issued = $this->authority->issueDeviceCode('tenant-a', 'site-a');
        $attempt = str_repeat('a', 32);
        $begin = $this->payload($this->authority->handle(
            OriginProtocol::PAIR_BEGIN_PATH,
            $this->signed(
                $this->pairBeginPayload($issued['device_code'], $attempt),
                $this->originSecret,
                $this->originKeyId
            )
        ));
        self::assertSame('pending', $begin['state']);
        $poll = [
            'format' => OriginProtocol::PAIR_POLL_REQUEST,
            'origin_key_id' => $this->originKeyId,
            'pair_attempt_id' => $attempt,
            'pairing_id' => $begin['pairing_id'],
            'poll_sequence' => 0,
            'request_id' => OriginProtocol::pairPollRequestId($attempt, $begin['pairing_id'], 0),
        ];
        $paired = $this->payload($this->authority->handle(
            OriginProtocol::PAIR_POLL_PATH,
            $this->signed($poll, $this->originSecret, $this->originKeyId)
        ));
        self::assertSame('paired', $paired['state']);
        self::assertIsArray($paired['pairing']);
        return $paired['pairing'];
    }

    /** @return array<string,mixed> */
    private function readAuthorityState(): array {
        $state = json_decode((string) file_get_contents($this->statePath), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($state);
        return $state;
    }

    /** @param array<string,mixed> $state */
    private function writeAuthorityState(array $state): void {
        $bytes = CanonicalJson::encode($state) . "\n";
        self::assertSame(strlen($bytes), file_put_contents($this->statePath, $bytes, LOCK_EX));
        self::assertTrue(chmod($this->statePath, 0600));
    }

    private function authorityRecordCount(string $field, string $tenantId, string $siteId): int {
        $state = $this->readAuthorityState();
        self::assertIsArray($state[$field] ?? null);
        $count = 0;
        foreach ($state[$field] as $record) {
            if (($record['tenant_id'] ?? null) === $tenantId && ($record['site_id'] ?? null) === $siteId) {
                $count++;
            }
        }
        return $count;
    }

    /** @return array<string,mixed> */
    private function pairBeginPayload(
        string $deviceCode,
        string $attempt,
        ?string $publicKey = null,
        ?string $keyId = null
    ): array {
        $publicKey ??= $this->originPublic;
        $keyId ??= OriginProtocol::originKeyId($publicKey);
        return [
            'connector' => [
                'agent_version' => '1.0.0',
                'home_url_sha256' => hash('sha256', 'https://example.test'),
                'installation_id' => 'installation-a',
                'multisite' => false,
                'php_version' => PHP_VERSION,
                'site_url_sha256' => hash('sha256', 'https://example.test'),
                'wordpress_version' => '6.9',
            ],
            'device_code' => $deviceCode,
            'format' => OriginProtocol::PAIR_BEGIN_REQUEST,
            'origin_key' => [
                'algorithm' => 'Ed25519',
                'key_id' => $keyId,
                'public_key' => base64_encode($publicKey),
            ],
            'pair_attempt_id' => $attempt,
            'request_id' => OriginProtocol::pairBeginRequestId($attempt, $keyId),
        ];
    }

    /** @return array<string,mixed> */
    private function demandPollPayload(
        int $afterGeneration,
        int $sequence,
        int $originGeneration = 1,
        ?string $originKeyId = null
    ): array {
        $originKeyId ??= $this->originKeyId;
        return [
            'after_demand_generation' => $afterGeneration,
            'format' => OriginProtocol::DEMAND_POLL_REQUEST,
            'origin_generation' => $originGeneration,
            'origin_key_id' => $originKeyId,
            'poll_sequence' => $sequence,
            'request_id' => OriginProtocol::demandPollRequestId(
                'tenant-a',
                'site-a',
                $originGeneration,
                $originKeyId,
                $afterGeneration,
                $sequence
            ),
            'site_id' => 'site-a',
            'tenant_id' => 'tenant-a',
        ];
    }

    /** @return array<string,mixed> */
    private function publishDemand(): array {
        $nonce = base64_encode(str_repeat('n', 32));
        $commit = str_repeat('a', 40);
        $demand = [
            'chunk_size' => 1048576,
            'demand_generation' => 1,
            'demand_id' => OriginProtocol::demandId(
                'tenant-a',
                'site-a',
                1,
                1,
                $nonce,
                $commit
            ),
            'expires_at' => self::NOW + 300,
            'expected_production_commit' => $commit,
            'format' => OriginProtocol::DEMAND_FORMAT,
            'nonce' => $nonce,
            'retention_deadline' => self::NOW + 3600,
            'snapshot_mode' => 'portable-refresh',
        ];
        $this->authority->publishDemand('tenant-a', 'site-a', $demand);
        return $demand;
    }

    /** @param array<string,mixed> $demand @return array{string,array<string,mixed>} */
    private function exportFixture(array $demand): array {
        $repository = [
            'artifact_hash' => hash('sha256', 'repository-artifact'),
            'code_revision' => null,
            'revision_hash' => hash('sha256', 'repository-revision'),
        ];
        $export = [
            'completed_code' => null,
            'deletions' => [],
            'format' => 'duo-refresh-production/v1',
            'media' => [],
            'policy' => [
                'manifest_hash' => hash('sha256', 'manifest'),
                'resolved_adapters' => [],
                'site_hash' => hash('sha256', 'site'),
            ],
            'records' => [
                'options/core' => [
                    'content' => 'state-secret-sentinel',
                    'hash' => hash('sha256', 'state-secret-sentinel'),
                    'identity' => 'options/core',
                    'path' => 'options/core.json',
                    'type' => 'options',
                ],
            ],
            'repository' => $repository,
            'warnings' => [],
        ];
        $export['snapshot_hash'] = hash('sha256', OriginAgentCanon::encode($export));
        $bytes = OriginAgentCanon::encode($export);
        $manifest = [
            'artifact_hash' => $repository['artifact_hash'],
            'chunks' => [[
                'index' => 0,
                'offset' => 0,
                'sha256' => hash('sha256', $bytes),
                'size' => strlen($bytes),
            ]],
            'code_revision' => null,
            'expected_production_commit' => $demand['expected_production_commit'],
            'export_sha256' => hash('sha256', $bytes),
            'export_size' => strlen($bytes),
            'format' => OriginProtocol::MANIFEST_FORMAT,
            'generation' => $demand['demand_generation'],
            'repository_revision_hash' => $repository['revision_hash'],
            'snapshot_hash' => $export['snapshot_hash'],
        ];
        $manifest['manifest_sha256'] = hash('sha256', OriginAgentCanon::encode($manifest));
        return [$bytes, $manifest];
    }

    /** @param array<string,mixed> $manifest @return array<string,mixed> */
    private function maxSizedManifest(array $manifest): array {
        $chunks = [];
        for ($index = 0; $index < 64; $index++) {
            $chunks[] = [
                'index' => $index,
                'offset' => $index * 1048576,
                'sha256' => hash('sha256', "retained-capacity-chunk-$index"),
                'size' => 1048576,
            ];
        }
        $manifest['chunks'] = $chunks;
        $manifest['export_sha256'] = hash('sha256', 'retained-capacity-max-export');
        $manifest['export_size'] = 67108864;
        unset($manifest['manifest_sha256']);
        $manifest['manifest_sha256'] = hash('sha256', OriginAgentCanon::encode($manifest));
        return $manifest;
    }

    /** @param array<string,mixed> $demand @param array<string,mixed> $manifest @return array{string,string} */
    private function announce(array $demand, array $manifest): array {
        $response = $this->authority->handle(
            OriginProtocol::ANNOUNCE_PATH,
            $this->signed($this->announcePayload($demand, $manifest), $this->originSecret, $this->originKeyId)
        );
        $payload = $this->payload($response);
        self::assertSame('announced', $payload['state']);
        return [$payload['export_id'], $response];
    }

    /** @param array<string,mixed> $demand @param array<string,mixed> $manifest @return array<string,mixed> */
    private function announcePayload(array $demand, array $manifest): array {
        return [
            'demand_generation' => $demand['demand_generation'],
            'demand_id' => $demand['demand_id'],
            'format' => OriginProtocol::ANNOUNCE_REQUEST,
            'manifest' => $manifest,
            'origin_generation' => 1,
            'origin_key_id' => $this->originKeyId,
            'request_id' => OriginProtocol::announceRequestId(
                'tenant-a',
                'site-a',
                1,
                $this->originKeyId,
                $demand['demand_generation'],
                $demand['demand_id'],
                $manifest['manifest_sha256']
            ),
            'site_id' => 'site-a',
            'tenant_id' => 'tenant-a',
        ];
    }

    /** @param array<string,mixed> $demand @return array<string,mixed> */
    private function missingPayload(
        array $demand,
        string $exportId,
        string $manifestSha256,
        int $sequence
    ): array {
        return [
            'demand_generation' => $demand['demand_generation'],
            'demand_id' => $demand['demand_id'],
            'export_id' => $exportId,
            'format' => OriginProtocol::MISSING_REQUEST,
            'manifest_sha256' => $manifestSha256,
            'origin_generation' => 1,
            'origin_key_id' => $this->originKeyId,
            'query_sequence' => $sequence,
            'request_id' => OriginProtocol::missingRequestId(
                'tenant-a',
                'site-a',
                1,
                $this->originKeyId,
                $demand['demand_generation'],
                $demand['demand_id'],
                $exportId,
                $manifestSha256,
                $sequence
            ),
            'site_id' => 'site-a',
            'tenant_id' => 'tenant-a',
        ];
    }

    /** @param array<string,mixed> $demand @param array<string,mixed> $manifest @return array<string,mixed> */
    private function chunkPayload(
        array $demand,
        string $exportId,
        array $manifest,
        string $bytes
    ): array {
        $sha256 = hash('sha256', $bytes);
        $size = strlen($bytes);
        return [
            'chunk_base64' => base64_encode($bytes),
            'chunk_sha256' => $sha256,
            'chunk_size' => $size,
            'demand_generation' => $demand['demand_generation'],
            'demand_id' => $demand['demand_id'],
            'export_id' => $exportId,
            'format' => OriginProtocol::CHUNK_REQUEST,
            'manifest_sha256' => $manifest['manifest_sha256'],
            'origin_generation' => 1,
            'origin_key_id' => $this->originKeyId,
            'request_id' => OriginProtocol::chunkRequestId(
                'tenant-a',
                'site-a',
                1,
                $this->originKeyId,
                $demand['demand_generation'],
                $demand['demand_id'],
                $exportId,
                $manifest['manifest_sha256'],
                $sha256,
                $size
            ),
            'site_id' => 'site-a',
            'tenant_id' => 'tenant-a',
        ];
    }

    /** @param array<string,mixed> $demand @return array<string,mixed> */
    private function commitPayload(array $demand, string $exportId, string $manifestSha256): array {
        return [
            'demand_generation' => $demand['demand_generation'],
            'demand_id' => $demand['demand_id'],
            'export_id' => $exportId,
            'format' => OriginProtocol::COMMIT_REQUEST,
            'manifest_sha256' => $manifestSha256,
            'origin_generation' => 1,
            'origin_key_id' => $this->originKeyId,
            'request_id' => OriginProtocol::commitRequestId(
                'tenant-a',
                'site-a',
                1,
                $this->originKeyId,
                $demand['demand_generation'],
                $demand['demand_id'],
                $exportId,
                $manifestSha256
            ),
            'site_id' => 'site-a',
            'tenant_id' => 'tenant-a',
        ];
    }

    /** @param array<string,mixed> $payload */
    private function signed(array $payload, string $secretKey, string $keyId): string {
        $envelope = [
            'format' => OriginProtocol::ENVELOPE_FORMAT,
            'key_id' => $keyId,
            'payload' => $payload,
            'signature' => base64_encode(sodium_crypto_sign_detached(
                CanonicalJson::encode($payload),
                $secretKey
            )),
        ];
        return CanonicalJson::encode($envelope) . "\n";
    }

    /** @return array<string,mixed> */
    private function payload(string $response): array {
        $envelope = CanonicalJson::decodeObject($response, 2097152);
        self::assertSame(OriginProtocol::ENVELOPE_FORMAT, $envelope['format']);
        self::assertSame('service-key-v1', $envelope['key_id']);
        $signature = base64_decode((string) $envelope['signature'], true);
        self::assertIsString($signature);
        self::assertTrue(sodium_crypto_sign_verify_detached(
            $signature,
            CanonicalJson::encode($envelope['payload']),
            $this->servicePublic
        ));
        self::assertIsArray($envelope['payload']);
        return $envelope['payload'];
    }

    /** @param callable():mixed $call */
    private function assertGenericRefusal(callable $call): void {
        try {
            $call();
            self::fail('expected generic unsigned origin refusal');
        } catch (ControlRefusal) {
            self::assertTrue(true);
        }
    }

    /** @return list<string> */
    private function regularFiles(string $root): array {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $entry) {
            if ($entry->isFile()) {
                $files[] = $entry->getPathname();
            }
        }
        return $files;
    }

    private function killBlobChunkWrite(
        string $source,
        int $demandGeneration,
        string $exportId,
        string $sha256
    ): void {
        $storeFile = DUO_REPO_ROOT . '/cloud/src/OriginFileBlobStore.php';
        $script = <<<'PHP'
putenv('DUO_TEST_MODE=1');
putenv('DUO_TEST_ORIGIN_BLOB_KILL_PHASE=temporary-synchronized');
require_once $argv[1];
$bytes = file_get_contents($argv[3]);
if (!is_string($bytes)) {
    exit(23);
}
$store = new \Duo\Cloud\OriginFileBlobStore($argv[2]);
$store->putChunk('tenant-a', 'site-a', 1, (int) $argv[4], $argv[5], $argv[6], $bytes);
exit(24);
PHP;
        $pipes = [];
        $process = proc_open(
            [
                PHP_BINARY,
                '-r',
                $script,
                $storeFile,
                $this->blobPath,
                $source,
                (string) $demandGeneration,
                $exportId,
                $sha256,
            ],
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
        $status = proc_get_status($process);
        for ($poll = 0; $status['running'] && $poll < 100; $poll++) {
            usleep(10000);
            $status = proc_get_status($process);
        }
        proc_close($process);
        self::assertSame('', $stdout);
        self::assertSame('', $stderr);
        self::assertFalse($status['running']);
        self::assertTrue($status['signaled']);
        self::assertSame(9, $status['termsig']);
    }

    private function removeTree(string $root): void {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $path = $entry->getPathname();
            self::assertTrue($entry->isDir() && !$entry->isLink() ? rmdir($path) : unlink($path));
        }
        self::assertTrue(rmdir($root));
    }
}

/** Test-only lost-response seam around the real private filesystem store. */
final class ReapInterruptingBlobStore implements OriginBlobStore {
    private bool $interruptDelete = false;

    public function __construct(private OriginBlobStore $delegate) {}

    public function interruptNextDeleteAfterRemoval(): void {
        $this->interruptDelete = true;
    }

    public function putChunk(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId,
        string $sha256,
        string $bytes
    ): void {
        $this->delegate->putChunk(
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $exportId,
            $sha256,
            $bytes
        );
    }

    public function getChunk(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId,
        string $sha256
    ): ?string {
        return $this->delegate->getChunk(
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $exportId,
            $sha256
        );
    }

    public function publishArtifact(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId,
        string $bytes
    ): void {
        $this->delegate->publishArtifact(
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $exportId,
            $bytes
        );
    }

    public function getArtifact(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId
    ): ?string {
        return $this->delegate->getArtifact(
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $exportId
        );
    }

    public function deleteExport(
        string $tenantId,
        string $siteId,
        int $originGeneration,
        int $demandGeneration,
        string $exportId
    ): void {
        $this->delegate->deleteExport(
            $tenantId,
            $siteId,
            $originGeneration,
            $demandGeneration,
            $exportId
        );
        if ($this->interruptDelete) {
            $this->interruptDelete = false;
            throw new ControlRefusal('simulated lost origin blob deletion response');
        }
    }
}
