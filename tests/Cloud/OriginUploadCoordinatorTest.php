<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Canon;
use Duo\Cloud\FileAuthorityStore;
use Duo\Cloud\OriginAuthority;
use Duo\Cloud\OriginFileBlobStore;
use Duo\Cloud\OriginProtocol;
use Duo\OriginCloudClient;
use Duo\OriginPairing;
use Duo\OriginPairingStateStore;
use Duo\OriginUploadCoordinator;
use Duo\OriginUploadJournal;
use Duo\OriginUploadSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/agent/src/Cloud/OriginUploadCoordinator.php';
require_once DUO_REPO_ROOT . '/cloud/src/OriginAuthority.php';
require_once DUO_REPO_ROOT . '/cloud/src/OriginFileBlobStore.php';
require_once DUO_REPO_ROOT . '/cli/src/Command/OriginCommand.php';

if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}
if (!defined('DUO_CONTROL_PLANE')) {
    define('DUO_CONTROL_PLANE', true);
}

/** Real authority adapter that can drop one response after server commit. */
final class OriginUploadAuthorityExchange {
    /** @var array<string,int> */
    public array $loseAfterCommit = [];
    /** @var array<string,list<string>> */
    public array $requests = [];

    public function __construct(private OriginAuthority $authority) {
    }

    /** @return array<string,mixed> */
    public function __invoke(string $url, string $body, int $timeout, int $limit): array {
        if ($timeout < 1 || $limit < 1048576) {
            throw new \RuntimeException('test exchange received invalid transport bounds');
        }
        $path = parse_url($url, PHP_URL_PATH);
        if (!is_string($path)) {
            throw new \RuntimeException('test exchange received an invalid URL');
        }
        $this->requests[$path][] = $body;
        $response = $this->authority->handle($path, $body);
        if (($this->loseAfterCommit[$path] ?? 0) > 0) {
            $this->loseAfterCommit[$path]--;
            throw new \RuntimeException("simulated lost response at $path");
        }
        return [
            'body' => $response,
            'effective_url' => $url,
            'redirected' => false,
            'status' => 200,
        ];
    }

    /** @return list<array<string,mixed>> */
    public function payloads(string $path): array {
        $payloads = [];
        foreach ($this->requests[$path] ?? [] as $request) {
            $decoded = json_decode($request, true, 64, JSON_THROW_ON_ERROR);
            if (!is_array($decoded) || !is_array($decoded['payload'] ?? null)) {
                throw new \RuntimeException('test exchange captured a malformed signed envelope');
            }
            $payloads[] = $decoded['payload'];
        }
        return $payloads;
    }
}

/** Deterministic immutable artifact with a crash immediately after capture. */
final class OriginUploadFixtureSource implements OriginUploadSource {
    public int $sealCalls = 0;
    public int $liveReads = 0;
    public int $chunkReads = 0;
    public int $discardCalls = 0;
    public ?string $phaseAtFirstLiveRead = null;
    public bool $crashAfterFirstSeal = true;
    public bool $crashBeforeFirstChunkRead = false;
    public bool $crashBeforeFirstDiscard = true;
    public bool $discarded = false;
    private bool $sealed = false;
    /** @var list<string> */
    private array $chunks;
    /** @var array<string,mixed> */
    private array $manifest;

    /** @param array<string,mixed> $demand */
    public function __construct(
        private string $journalPath,
        private string $expectedSessionId,
        array $demand,
        string $marker
    ) {
        $artifactHash = hash('sha256', 'artifact');
        $revisionHash = hash('sha256', 'revision');
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
            'records' => ['fixture' => ['payload' => str_repeat('A', 1048576) . $marker]],
            'repository' => [
                'artifact_hash' => $artifactHash,
                'code_revision' => null,
                'revision_hash' => $revisionHash,
            ],
            'warnings' => [],
        ];
        $export['snapshot_hash'] = hash('sha256', Canon::encode($export));
        $bytes = Canon::encode($export);
        $this->chunks = [substr($bytes, 0, 1048576), substr($bytes, 1048576)];
        $offset = 0;
        $descriptors = [];
        foreach ($this->chunks as $index => $chunk) {
            $descriptors[] = [
                'index' => $index,
                'offset' => $offset,
                'sha256' => hash('sha256', $chunk),
                'size' => strlen($chunk),
            ];
            $offset += strlen($chunk);
        }
        $this->manifest = [
            'artifact_hash' => $artifactHash,
            'chunks' => $descriptors,
            'code_revision' => null,
            'expected_production_commit' => $demand['expected_production_commit'],
            'export_sha256' => hash('sha256', $bytes),
            'export_size' => strlen($bytes),
            'format' => OriginProtocol::MANIFEST_FORMAT,
            'generation' => $demand['demand_generation'],
            'repository_revision_hash' => $revisionHash,
            'snapshot_hash' => $export['snapshot_hash'],
        ];
        $this->manifest['manifest_sha256'] = hash('sha256', Canon::encode($this->manifest));
    }

    public function seal(string $repository, string $sessionId, string $expectedCommit): void {
        $this->sealCalls++;
        if ($sessionId !== $this->expectedSessionId
            || $expectedCommit !== $this->manifest['expected_production_commit']) {
            throw new \RuntimeException('fixture source received the wrong sealed identity');
        }
        if (!$this->sealed) {
            $state = json_decode((string) file_get_contents($this->journalPath), true, 64, JSON_THROW_ON_ERROR);
            $this->phaseAtFirstLiveRead = is_array($state) && is_string($state['phase'] ?? null)
                ? $state['phase']
                : null;
            $this->liveReads++;
            $this->sealed = true;
            if ($this->crashAfterFirstSeal) {
                $this->crashAfterFirstSeal = false;
                throw new \RuntimeException('simulated death after immutable capture');
            }
        }
    }

    /** @return array<string,mixed> */
    public function manifest(
        string $repository,
        string $sessionId,
        string $expectedCommit,
        int $demandGeneration
    ): array {
        if (!$this->sealed || $sessionId !== $this->expectedSessionId
            || $expectedCommit !== $this->manifest['expected_production_commit']
            || $demandGeneration !== $this->manifest['generation']) {
            throw new \RuntimeException('fixture source manifest request escaped its sealed identity');
        }
        return $this->manifest;
    }

    public function readChunk(
        string $repository,
        string $sessionId,
        string $expectedCommit,
        int $index,
        string $sha256
    ): string {
        $this->chunkReads++;
        if ($this->crashBeforeFirstChunkRead) {
            $this->crashBeforeFirstChunkRead = false;
            throw new \RuntimeException('simulated death before first chunk request');
        }
        $chunk = $this->chunks[$index] ?? null;
        if (!$this->sealed || $sessionId !== $this->expectedSessionId
            || $expectedCommit !== $this->manifest['expected_production_commit']
            || !is_string($chunk) || !hash_equals($sha256, hash('sha256', $chunk))) {
            throw new \RuntimeException('fixture source chunk request escaped its sealed identity');
        }
        return $chunk;
    }

    public function discard(string $repository, string $sessionId, string $expectedCommit): void {
        $this->discardCalls++;
        if (!$this->sealed || $sessionId !== $this->expectedSessionId
            || $expectedCommit !== $this->manifest['expected_production_commit']) {
            throw new \RuntimeException('fixture source discard escaped its sealed identity');
        }
        if ($this->crashBeforeFirstDiscard) {
            $this->crashBeforeFirstDiscard = false;
            throw new \RuntimeException('simulated death after cloud commit before local cleanup');
        }
        $this->discarded = true;
    }

    /** @return list<string> */
    public function chunks(): array {
        return $this->chunks;
    }

    /** @return array<string,mixed> */
    public function wireManifest(): array {
        return $this->manifest;
    }
}

#[CoversNothing]
final class OriginUploadCoordinatorTest extends TestCase {
    private const NOW = 2000000000;

    private string|false $priorTestMode;
    private string $scratch;
    private string $serviceSecret;
    private string $servicePublic;
    private OriginAuthority $authority;
    private OriginUploadAuthorityExchange $exchange;
    private int $now;

    protected function setUp(): void {
        $this->priorTestMode = getenv('DUO_TEST_MODE');
        putenv('DUO_TEST_MODE=1');
        $this->now = self::NOW;
        $this->scratch = sys_get_temp_dir() . '/duo-origin-upload-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
        self::assertTrue(mkdir($this->scratch . '/blobs', 0700));
        self::assertTrue(mkdir($this->scratch . '/repository', 0700));
        $service = sodium_crypto_sign_keypair();
        $this->serviceSecret = sodium_crypto_sign_secretkey($service);
        $this->servicePublic = sodium_crypto_sign_publickey($service);
        $this->authority = new OriginAuthority(
            new FileAuthorityStore($this->scratch . '/authority.json'),
            new OriginFileBlobStore($this->scratch . '/blobs'),
            'service-key-v1',
            $this->serviceSecret,
            random_bytes(32),
            fn (): int => $this->now
        );
        $this->exchange = new OriginUploadAuthorityExchange($this->authority);
    }

    protected function tearDown(): void {
        unset($this->authority);
        if (isset($this->scratch) && is_dir($this->scratch)) {
            $this->removeTree($this->scratch);
        }
        if (isset($this->serviceSecret) && $this->serviceSecret !== '') {
            sodium_memzero($this->serviceSecret);
        }
        if ($this->priorTestMode === false) {
            putenv('DUO_TEST_MODE');
        } else {
            putenv('DUO_TEST_MODE=' . $this->priorTestMode);
        }
    }

    public function testRealPairingAuthorityAndUploadRecoverEveryLostResponseWithoutRecapture(): void {
        [$pairing, $deviceCode, $originSecret] = $this->pairedOrigin();
        try {
            $nonce = base64_encode(str_repeat('n', 32));
            $commit = str_repeat('a', 40);
            $demand = [
                'chunk_size' => 1048576,
                'demand_generation' => 1,
                'demand_id' => OriginProtocol::demandId('tenant-a', 'site-a', 1, 1, $nonce, $commit),
                'expires_at' => self::NOW + 300,
                'expected_production_commit' => $commit,
                'format' => OriginProtocol::DEMAND_FORMAT,
                'nonce' => $nonce,
                'retention_deadline' => self::NOW + 3600,
                'snapshot_mode' => 'portable-refresh',
            ];
            $this->authority->publishDemand('tenant-a', 'site-a', $demand);

            $journal = new OriginUploadJournal($this->scratch . '/upload-journal');
            $expectedSession = OriginProtocol::sessionId(
                'tenant-a',
                'site-a',
                1,
                1,
                $demand['demand_id'],
                $commit
            );
            $marker = 'TOP-SECRET-CHUNK-CONTENT';
            $source = new OriginUploadFixtureSource(
                $journal->statePath(),
                $expectedSession,
                $demand,
                $marker
            );
            $coordinator = new OriginUploadCoordinator(
                $pairing,
                $journal,
                $this->scratch . '/repository',
                $source
            );
            foreach ([
                OriginProtocol::DEMAND_POLL_PATH,
                OriginProtocol::ANNOUNCE_PATH,
                OriginProtocol::MISSING_PATH,
                OriginProtocol::CHUNK_PATH,
                OriginProtocol::COMMIT_PATH,
            ] as $path) {
                $this->exchange->loseAfterCommit[$path] = 1;
            }

            $this->assertFailsWith($coordinator, 'simulated lost response at ' . OriginProtocol::DEMAND_POLL_PATH);
            self::assertSame('demand_polling', $this->journalState($journal)['phase']);

            $this->assertFailsWith($coordinator, 'simulated death after immutable capture');
            $accepted = $this->journalState($journal);
            self::assertSame('accepted', $accepted['phase']);
            self::assertSame($expectedSession, $accepted['active']['session_id']);
            self::assertSame('accepted', $source->phaseAtFirstLiveRead);
            self::assertSame(1, $source->liveReads);

            $this->assertFailsWith($coordinator, 'simulated lost response at ' . OriginProtocol::ANNOUNCE_PATH);
            self::assertSame('announcing', $this->journalState($journal)['phase']);
            self::assertSame(2, $source->sealCalls);
            self::assertSame(1, $source->liveReads, 'a sealed retry must not recapture WordPress/Git/DB');

            $this->assertFailsWith($coordinator, 'simulated lost response at ' . OriginProtocol::MISSING_PATH);
            self::assertSame('missing_polling', $this->journalState($journal)['phase']);

            $this->assertFailsWith($coordinator, 'simulated lost response at ' . OriginProtocol::CHUNK_PATH);
            self::assertSame('uploading', $this->journalState($journal)['phase']);

            $this->assertFailsWith($coordinator, 'simulated lost response at ' . OriginProtocol::COMMIT_PATH);
            self::assertSame('committing', $this->journalState($journal)['phase']);
            self::assertSame(1, $source->liveReads);

            $this->assertFailsWith(
                $coordinator,
                'simulated death after cloud commit before local cleanup'
            );
            self::assertSame('cleaning', $this->journalState($journal)['phase']);
            self::assertSame(1, $source->discardCalls);
            self::assertFalse($source->discarded);

            $committed = $coordinator->run();
            self::assertSame('committed', $committed['remote_state']);
            self::assertSame('idle', $committed['phase']);
            self::assertSame(1, $committed['after_demand_generation']);
            self::assertIsArray($committed['last_commit']);
            self::assertSame(
                $source->wireManifest()['snapshot_hash'],
                $committed['last_commit']['snapshot_hash']
            );
            self::assertSame(2, $source->discardCalls);
            self::assertTrue($source->discarded);

            $demandRequests = $this->exchange->requests[OriginProtocol::DEMAND_POLL_PATH];
            self::assertSame($demandRequests[0], $demandRequests[1]);
            $announceRequests = $this->exchange->requests[OriginProtocol::ANNOUNCE_PATH];
            self::assertSame($announceRequests[0], $announceRequests[1]);
            $missingRequests = $this->exchange->requests[OriginProtocol::MISSING_PATH];
            self::assertSame($missingRequests[0], $missingRequests[1]);
            $chunkRequests = $this->exchange->requests[OriginProtocol::CHUNK_PATH];
            self::assertSame($chunkRequests[0], $chunkRequests[1]);
            $commitRequests = $this->exchange->requests[OriginProtocol::COMMIT_PATH];
            self::assertSame($commitRequests[0], $commitRequests[1]);

            $missingPayloads = $this->exchange->payloads(OriginProtocol::MISSING_PATH);
            self::assertSame([0, 0, 1, 2], array_column($missingPayloads, 'query_sequence'));
            self::assertSame(1, $source->liveReads);
            self::assertSame(3, $source->chunkReads, 'only a lost chunk response re-reads sealed bytes');

            $idle = $coordinator->run();
            self::assertSame('idle', $idle['remote_state']);
            $demandPayloads = $this->exchange->payloads(OriginProtocol::DEMAND_POLL_PATH);
            self::assertSame([0, 0, 1], array_column($demandPayloads, 'poll_sequence'));
            self::assertSame([0, 0, 1], array_column($demandPayloads, 'after_demand_generation'));

            foreach ($source->chunks() as $index => $chunk) {
                self::assertSame(
                    $chunk,
                    $this->authority->readCommittedChunk(
                        'tenant-a',
                        'site-a',
                        $committed['last_commit']['export_id'],
                        $index
                    )
                );
            }
            $journalBytes = (string) file_get_contents($journal->statePath());
            $outputBytes = Canon::encode([$committed, $coordinator->status()]);
            foreach ([$marker, $deviceCode, $originSecret, base64_encode($originSecret)] as $forbidden) {
                self::assertStringNotContainsString($forbidden, $journalBytes);
                self::assertStringNotContainsString($forbidden, $outputBytes);
            }
            self::assertSame(0700, fileperms(dirname($journal->statePath())) & 0777);
            self::assertSame(0600, fileperms($journal->statePath()) & 0777);
            self::assertSame(0600, fileperms(dirname($journal->statePath()) . '/upload.lock') & 0777);
        } finally {
            sodium_memzero($originSecret);
        }
    }

    public function testSignedExpiredDemandCleansItsSpoolAndAllowsTheNextGeneration(): void {
        [$pairing, , $originSecret] = $this->pairedOrigin();
        try {
            $commitOne = str_repeat('b', 40);
            $nonceOne = base64_encode(str_repeat('e', 32));
            $demandOne = [
                'chunk_size' => 1048576,
                'demand_generation' => 1,
                'demand_id' => OriginProtocol::demandId(
                    'tenant-a', 'site-a', 1, 1, $nonceOne, $commitOne
                ),
                'expires_at' => self::NOW + 300,
                'expected_production_commit' => $commitOne,
                'format' => OriginProtocol::DEMAND_FORMAT,
                'nonce' => $nonceOne,
                'retention_deadline' => self::NOW + 3600,
                'snapshot_mode' => 'portable-refresh',
            ];
            $this->authority->publishDemand('tenant-a', 'site-a', $demandOne);
            $journal = new OriginUploadJournal($this->scratch . '/expiry-journal');
            $sessionOne = OriginProtocol::sessionId(
                'tenant-a', 'site-a', 1, 1, $demandOne['demand_id'], $commitOne
            );
            $sourceOne = new OriginUploadFixtureSource(
                $journal->statePath(), $sessionOne, $demandOne, 'EXPIRED-ORIGIN-BYTES'
            );
            $sourceOne->crashAfterFirstSeal = false;
            $sourceOne->crashBeforeFirstChunkRead = true;
            $sourceOne->crashBeforeFirstDiscard = false;
            $coordinatorOne = new OriginUploadCoordinator(
                $pairing, $journal, $this->scratch . '/repository', $sourceOne
            );
            $this->assertFailsWith($coordinatorOne, 'simulated death before first chunk request');
            $uploading = $this->journalState($journal);
            self::assertSame('uploading', $uploading['phase']);
            self::assertIsInt($uploading['active']['chunk_index']);
            self::assertIsString($uploading['active']['chunk_sha256']);

            $this->now = self::NOW + 301;
            $expired = $coordinatorOne->run();
            self::assertSame('demand_expired', $expired['remote_state']);
            self::assertSame('idle', $expired['phase']);
            $this->assertControllerDocument('export', $expired);
            self::assertTrue($sourceOne->discarded);
            self::assertSame(1, $sourceOne->discardCalls);
            self::assertCount(
                1,
                $this->exchange->requests[OriginProtocol::CHUNK_PATH] ?? [],
                'the expired chunk request must refuse before storage and transition to cleanup'
            );
            self::assertNull($this->journalState($journal)['active']);

            $commitTwo = str_repeat('c', 40);
            $nonceTwo = base64_encode(str_repeat('f', 32));
            $demandTwo = [
                'chunk_size' => 1048576,
                'demand_generation' => 2,
                'demand_id' => OriginProtocol::demandId(
                    'tenant-a', 'site-a', 1, 2, $nonceTwo, $commitTwo
                ),
                'expires_at' => $this->now + 300,
                'expected_production_commit' => $commitTwo,
                'format' => OriginProtocol::DEMAND_FORMAT,
                'nonce' => $nonceTwo,
                'retention_deadline' => $this->now + 3600,
                'snapshot_mode' => 'portable-refresh',
            ];
            $this->authority->publishDemand('tenant-a', 'site-a', $demandTwo);
            $sessionTwo = OriginProtocol::sessionId(
                'tenant-a', 'site-a', 1, 2, $demandTwo['demand_id'], $commitTwo
            );
            $sourceTwo = new OriginUploadFixtureSource(
                $journal->statePath(), $sessionTwo, $demandTwo, 'NEXT-ORIGIN-BYTES'
            );
            $sourceTwo->crashAfterFirstSeal = false;
            $sourceTwo->crashBeforeFirstDiscard = false;
            $coordinatorTwo = new OriginUploadCoordinator(
                $pairing, $journal, $this->scratch . '/repository', $sourceTwo
            );
            $committed = $coordinatorTwo->run();
            self::assertSame('committed', $committed['remote_state']);
            self::assertSame(2, $committed['after_demand_generation']);
            self::assertTrue($sourceTwo->discarded);
            self::assertSame(1, $sourceTwo->liveReads);
        } finally {
            sodium_memzero($originSecret);
        }
    }

    public function testAdministratorRevokeAbandonsActiveSpoolBeforeExactRemoteReplay(): void {
        [$pairing, , $originSecret] = $this->pairedOrigin();
        try {
            $commit = str_repeat('d', 40);
            $nonce = base64_encode(str_repeat('r', 32));
            $demand = [
                'chunk_size' => 1048576,
                'demand_generation' => 1,
                'demand_id' => OriginProtocol::demandId(
                    'tenant-a', 'site-a', 1, 1, $nonce, $commit
                ),
                'expires_at' => self::NOW + 300,
                'expected_production_commit' => $commit,
                'format' => OriginProtocol::DEMAND_FORMAT,
                'nonce' => $nonce,
                'retention_deadline' => self::NOW + 3600,
                'snapshot_mode' => 'portable-refresh',
            ];
            $this->authority->publishDemand('tenant-a', 'site-a', $demand);
            $journal = new OriginUploadJournal($this->scratch . '/revoke-journal');
            $sessionId = OriginProtocol::sessionId(
                'tenant-a', 'site-a', 1, 1, $demand['demand_id'], $commit
            );
            $source = new OriginUploadFixtureSource(
                $journal->statePath(), $sessionId, $demand, 'REVOKED-ORIGIN-BYTES'
            );
            $source->crashAfterFirstSeal = false;
            $source->crashBeforeFirstChunkRead = true;
            $coordinator = new OriginUploadCoordinator(
                $pairing, $journal, $this->scratch . '/repository', $source
            );

            $this->assertFailsWith($coordinator, 'simulated death before first chunk request');
            self::assertSame('uploading', $this->journalState($journal)['phase']);
            $replacement = $this->authority->issueDeviceCode('tenant-a', 'site-a');
            $beginCalls = count($this->exchange->requests[OriginProtocol::PAIR_BEGIN_PATH] ?? []);
            try {
                $coordinator->beginPairing($replacement['device_code'], $this->connector());
                self::fail('active upload journal allowed replacement pairing');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'duo: cloud origin pairing requires an idle upload journal',
                    $error->getMessage()
                );
            }
            self::assertCount(
                $beginCalls,
                $this->exchange->requests[OriginProtocol::PAIR_BEGIN_PATH] ?? [],
                'replacement pairing must not contact Cloud before sealed-byte cleanup'
            );

            try {
                $coordinator->revoke();
                self::fail('expected local revoke cleanup crash');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'simulated death after cloud commit before local cleanup',
                    $error->getMessage()
                );
            }
            $cleaning = $this->journalState($journal);
            self::assertSame('cleaning', $cleaning['phase']);
            self::assertSame('administrator_requested', $cleaning['active']['terminal_reason']);
            self::assertSame('administrator_requested', $cleaning['revocation_reason']);
            self::assertNull($cleaning['active']['chunk_index']);
            self::assertNull($cleaning['active']['chunk_sha256']);
            self::assertFalse($source->discarded);
            self::assertSame('paired', $pairing->status()['phase']);
            $cleaningStatus = $coordinator->status();
            self::assertSame('cleaning', $cleaningStatus['phase']);
            $validator = new \ReflectionMethod(\Duo\Orchestrator\OriginCommand::class, 'validDocument');
            self::assertTrue($validator->invoke(null, [
                'format' => 'duo-cloud-origin-command/v1',
                'operation' => 'status',
                'result' => [
                    'pairing' => $pairing->status(),
                    'upload' => $cleaningStatus,
                ],
            ], 'status'), 'public origin status accepts a durable cleanup recovery phase');
            self::assertArrayNotHasKey(
                OriginProtocol::REVOKE_PATH,
                $this->exchange->requests,
                'remote authority must survive until local sealed bytes are absent'
            );

            $this->exchange->loseAfterCommit[OriginProtocol::REVOKE_PATH] = 1;
            try {
                $coordinator->revoke();
                self::fail('expected lost remote revoke response');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'simulated lost response at ' . OriginProtocol::REVOKE_PATH,
                    $error->getMessage()
                );
            }
            $locallyAbandoned = $this->journalState($journal);
            self::assertSame('cleaning', $locallyAbandoned['phase']);
            self::assertSame(
                'administrator_requested',
                $locallyAbandoned['active']['terminal_reason']
            );
            self::assertSame(
                'administrator_requested',
                $locallyAbandoned['revocation_reason']
            );
            self::assertTrue($source->discarded);
            self::assertSame('revoking', $pairing->status()['phase']);

            $status = $coordinator->revoke();
            self::assertSame('revoked', $status['phase']);
            self::assertSame(3, $source->discardCalls);
            self::assertNull($this->journalState($journal)['revocation_reason']);
            $requests = $this->exchange->requests[OriginProtocol::REVOKE_PATH];
            self::assertCount(2, $requests);
            self::assertSame($requests[0], $requests[1]);

            $liveReads = $source->liveReads;
            $sealCalls = $source->sealCalls;
            $this->assertFailsWith(
                $coordinator,
                'duo: cloud origin upload requires active signed pairing'
            );
            self::assertSame($liveReads, $source->liveReads);
            self::assertSame($sealCalls, $source->sealCalls);

            $revokedKeyId = $pairing->status()['origin_key_id'];
            $pending = $coordinator->beginPairing(
                $replacement['device_code'],
                $this->connector()
            );
            self::assertSame('pending', $pending['phase']);
            self::assertNotSame($revokedKeyId, $pending['origin_key_id']);
            $paired = $pairing->poll();
            self::assertSame('paired', $paired['state']);
            self::assertSame(2, $paired['pairing']['origin_generation']);
            $idle = $coordinator->run();
            self::assertSame('idle', $idle['remote_state']);
            self::assertSame(2, $idle['origin_generation']);
            self::assertSame('idle', $this->journalState($journal)['phase']);
            self::assertTrue($source->discarded);

            $cliSource = file_get_contents(DUO_REPO_ROOT . '/agent/src/Command/Cli.php');
            self::assertIsString($cliSource);
            $rotateStart = strpos($cliSource, 'public function origin_rotate(');
            $cliStart = strpos($cliSource, 'public function origin_revoke(');
            $uninstallStart = strpos($cliSource, 'public function origin_uninstall(');
            $cliEnd = strpos(
                $cliSource,
                'private static function origin_control_plane',
                (int) $uninstallStart
            );
            self::assertIsInt($rotateStart);
            self::assertIsInt($cliStart);
            self::assertIsInt($uninstallStart);
            self::assertIsInt($cliEnd);
            foreach ([
                'rotate' => substr($cliSource, $rotateStart, $cliStart - $rotateStart),
                'revoke' => substr($cliSource, $cliStart, $uninstallStart - $cliStart),
                'uninstall' => substr($cliSource, $uninstallStart, $cliEnd - $uninstallStart),
            ] as $operation => $cliBody) {
                self::assertStringContainsString('OriginUploadCoordinator::production(', $cliBody);
                self::assertStringContainsString("\$coordinator->$operation()", $cliBody);
                self::assertStringContainsString("origin_output('$operation'", $cliBody);
                self::assertStringNotContainsString('OriginPairing::production(', $cliBody);
            }

            $pairStart = strpos($cliSource, 'public function origin_pair(');
            $pairEnd = strpos($cliSource, 'public function origin_export(', (int) $pairStart);
            self::assertIsInt($pairStart);
            self::assertIsInt($pairEnd);
            $pairBody = substr($cliSource, $pairStart, $pairEnd - $pairStart);
            self::assertStringContainsString('OriginUploadCoordinator::production(', $pairBody);
            self::assertStringContainsString('$coordinator->beginPairing(', $pairBody);
        } finally {
            sodium_memzero($originSecret);
        }
    }

    public function testRotationRequiresIdleJournalAndRecoversTheExactLostResponse(): void {
        [$pairing, , $originSecret] = $this->pairedOrigin();
        try {
            $journal = new OriginUploadJournal($this->scratch . '/rotation-journal');
            $coordinator = new OriginUploadCoordinator(
                $pairing,
                $journal,
                $this->scratch . '/repository'
            );
            $idle = $coordinator->run();
            self::assertSame('idle', $idle['phase']);
            self::assertSame(1, $idle['origin_generation']);

            $this->exchange->loseAfterCommit[OriginProtocol::DEMAND_POLL_PATH] = 1;
            $this->assertFailsWith(
                $coordinator,
                'simulated lost response at ' . OriginProtocol::DEMAND_POLL_PATH
            );
            self::assertSame('demand_polling', $this->journalState($journal)['phase']);
            try {
                $coordinator->rotate();
                self::fail('a non-idle upload journal allowed key rotation');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'duo: cloud origin key rotation requires an idle upload journal',
                    $error->getMessage()
                );
            }
            self::assertArrayNotHasKey(
                OriginProtocol::ROTATE_PATH,
                $this->exchange->requests,
                'rotation must refuse before contacting Cloud when demand polling is durable'
            );
            self::assertSame('idle', $coordinator->run()['remote_state']);

            $oldKeyId = $pairing->status()['origin_key_id'];
            $this->exchange->loseAfterCommit[OriginProtocol::ROTATE_PATH] = 1;
            try {
                $coordinator->rotate();
                self::fail('expected lost origin rotation response');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'simulated lost response at ' . OriginProtocol::ROTATE_PATH,
                    $error->getMessage()
                );
            }
            self::assertSame('rotating', $pairing->status()['phase']);
            self::assertSame(1, $this->journalState($journal)['origin_generation']);

            $rotated = $coordinator->rotate();
            self::assertSame('paired', $rotated['phase']);
            self::assertSame(2, $rotated['pairing']['origin_generation']);
            self::assertNotSame($oldKeyId, $rotated['origin_key_id']);
            $rotationRequests = $this->exchange->requests[OriginProtocol::ROTATE_PATH];
            self::assertCount(2, $rotationRequests);
            self::assertSame($rotationRequests[0], $rotationRequests[1]);
            $bound = $this->journalState($journal);
            self::assertSame('idle', $bound['phase']);
            self::assertSame(2, $bound['origin_generation']);
            self::assertSame($rotated['origin_key_id'], $bound['origin_key_id']);
            $this->assertControllerDocument('rotate', $rotated);
            $wrongRotation = $rotated;
            $wrongRotation['phase'] = 'revoked';
            $this->assertControllerRejects('rotate', $wrongRotation);

            self::assertSame($rotated, $coordinator->rotate());
            self::assertCount(2, $this->exchange->requests[OriginProtocol::ROTATE_PATH]);
            $after = $coordinator->run();
            self::assertSame('idle', $after['remote_state']);
            self::assertSame(2, $after['origin_generation']);
        } finally {
            sodium_memzero($originSecret);
        }
    }

    public function testRotationBindsAFreshUploadJournalToTheNewGeneration(): void {
        [$pairing, , $originSecret] = $this->pairedOrigin();
        try {
            $journal = new OriginUploadJournal($this->scratch . '/fresh-rotation-journal');
            $coordinator = new OriginUploadCoordinator(
                $pairing,
                $journal,
                $this->scratch . '/repository'
            );
            self::assertFileDoesNotExist($journal->statePath());

            $rotated = $coordinator->rotate();
            self::assertSame('paired', $rotated['phase']);
            self::assertSame(2, $rotated['pairing']['origin_generation']);
            $state = $this->journalState($journal);
            self::assertSame('idle', $state['phase']);
            self::assertSame(2, $state['origin_generation']);
            self::assertSame($rotated['origin_key_id'], $state['origin_key_id']);
            $this->assertControllerDocument('rotate', $rotated);
        } finally {
            sodium_memzero($originSecret);
        }
    }

    public function testRotationRebindsACompletedPairingOnceAndRefusesMultiGenerationDrift(): void {
        [$pairing, , $originSecret] = $this->pairedOrigin();
        try {
            $journal = new OriginUploadJournal($this->scratch . '/rotation-rebind-journal');
            $coordinator = new OriginUploadCoordinator(
                $pairing,
                $journal,
                $this->scratch . '/repository'
            );
            self::assertSame(1, $coordinator->run()['origin_generation']);

            // This is the durable state after process death between the pairing
            // store's completed N+1 response and the upload-journal rebind.
            self::assertSame('rotated', $pairing->rotate()['state']);
            self::assertSame(2, $pairing->status()['pairing']['origin_generation']);
            self::assertSame(1, $this->journalState($journal)['origin_generation']);
            self::assertCount(1, $this->exchange->requests[OriginProtocol::ROTATE_PATH]);

            $recovered = $coordinator->rotate();
            self::assertSame('paired', $recovered['phase']);
            self::assertSame(2, $recovered['pairing']['origin_generation']);
            self::assertSame(2, $this->journalState($journal)['origin_generation']);
            self::assertCount(
                1,
                $this->exchange->requests[OriginProtocol::ROTATE_PATH],
                'post-pairing crash recovery must not advance the key to N+2'
            );

            self::assertSame('revoked', $pairing->revoke()['state']);
            $replacement = $this->authority->issueDeviceCode('tenant-a', 'site-a');
            self::assertSame(
                'pending',
                $coordinator->beginPairing(
                    $replacement['device_code'],
                    $this->connector()
                )['phase']
            );
            self::assertSame('paired', $pairing->poll()['state']);
            self::assertSame(3, $pairing->status()['pairing']['origin_generation']);
            $freshPairRotation = $coordinator->rotate();
            self::assertSame('paired', $freshPairRotation['phase']);
            self::assertSame(4, $pairing->status()['pairing']['origin_generation']);
            self::assertSame(4, $this->journalState($journal)['origin_generation']);
            self::assertCount(
                2,
                $this->exchange->requests[OriginProtocol::ROTATE_PATH],
                'a fresh re-pair at N+1 must rotate to N+2 rather than masquerade as recovery'
            );

            self::assertSame('revoked', $pairing->revoke()['state']);
            $secondReplacement = $this->authority->issueDeviceCode('tenant-a', 'site-a');
            self::assertSame(
                'pending',
                $coordinator->beginPairing(
                    $secondReplacement['device_code'],
                    $this->connector()
                )['phase']
            );
            self::assertSame('paired', $pairing->poll()['state']);
            self::assertSame(5, $pairing->status()['pairing']['origin_generation']);
            self::assertSame('rotated', $pairing->rotate()['state']);
            self::assertSame(6, $pairing->status()['pairing']['origin_generation']);
            self::assertSame(4, $this->journalState($journal)['origin_generation']);
            $rotationRequests = count($this->exchange->requests[OriginProtocol::ROTATE_PATH]);

            try {
                $coordinator->rotate();
                self::fail('multi-generation upload authority drift was silently rebound');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'duo: cloud origin upload authority drift is not one completed rotation',
                    $error->getMessage()
                );
            }
            self::assertSame(
                $rotationRequests,
                count($this->exchange->requests[OriginProtocol::ROTATE_PATH]),
                'drift refusal must precede another Cloud rotation request'
            );
            self::assertSame(4, $this->journalState($journal)['origin_generation']);
        } finally {
            sodium_memzero($originSecret);
        }
    }

    public function testUninstallCleansTheActiveSpoolBeforeExactSignedRevocationReplay(): void {
        [$pairing, , $originSecret] = $this->pairedOrigin();
        try {
            $commit = str_repeat('f', 40);
            $nonce = base64_encode(str_repeat('u', 32));
            $demand = [
                'chunk_size' => 1048576,
                'demand_generation' => 1,
                'demand_id' => OriginProtocol::demandId(
                    'tenant-a', 'site-a', 1, 1, $nonce, $commit
                ),
                'expires_at' => self::NOW + 300,
                'expected_production_commit' => $commit,
                'format' => OriginProtocol::DEMAND_FORMAT,
                'nonce' => $nonce,
                'retention_deadline' => self::NOW + 3600,
                'snapshot_mode' => 'portable-refresh',
            ];
            $this->authority->publishDemand('tenant-a', 'site-a', $demand);
            $journal = new OriginUploadJournal($this->scratch . '/uninstall-journal');
            $sessionId = OriginProtocol::sessionId(
                'tenant-a', 'site-a', 1, 1, $demand['demand_id'], $commit
            );
            $source = new OriginUploadFixtureSource(
                $journal->statePath(),
                $sessionId,
                $demand,
                'UNINSTALL-PRIVATE-SPOOL'
            );
            $source->crashAfterFirstSeal = false;
            $source->crashBeforeFirstChunkRead = true;
            $coordinator = new OriginUploadCoordinator(
                $pairing,
                $journal,
                $this->scratch . '/repository',
                $source
            );
            $this->assertFailsWith($coordinator, 'simulated death before first chunk request');

            try {
                $coordinator->uninstall();
                self::fail('expected uninstall spool cleanup crash');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'simulated death after cloud commit before local cleanup',
                    $error->getMessage()
                );
            }
            $cleaning = $this->journalState($journal);
            self::assertSame('cleaning', $cleaning['phase']);
            self::assertSame('uninstall', $cleaning['active']['terminal_reason']);
            self::assertSame('uninstall', $cleaning['revocation_reason']);
            self::assertFalse($source->discarded);
            self::assertArrayNotHasKey(OriginProtocol::REVOKE_PATH, $this->exchange->requests);

            $this->exchange->loseAfterCommit[OriginProtocol::REVOKE_PATH] = 1;
            try {
                $coordinator->uninstall();
                self::fail('expected lost uninstall revocation response');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'simulated lost response at ' . OriginProtocol::REVOKE_PATH,
                    $error->getMessage()
                );
            }
            self::assertTrue($source->discarded);
            $lostResponse = $this->journalState($journal);
            self::assertSame('cleaning', $lostResponse['phase']);
            self::assertSame('uninstall', $lostResponse['active']['terminal_reason']);
            self::assertSame('uninstall', $lostResponse['revocation_reason']);
            self::assertSame('revoking', $pairing->status()['phase']);

            $uninstalled = $coordinator->uninstall();
            self::assertSame('revoked', $uninstalled['phase']);
            $this->assertControllerDocument('uninstall', $uninstalled);
            $wrongUninstall = $uninstalled;
            $wrongUninstall['phase'] = 'paired';
            $this->assertControllerRejects('uninstall', $wrongUninstall);
            $requests = $this->exchange->requests[OriginProtocol::REVOKE_PATH];
            self::assertCount(2, $requests);
            self::assertSame($requests[0], $requests[1]);
            $payloads = $this->exchange->payloads(OriginProtocol::REVOKE_PATH);
            self::assertSame(['uninstall', 'uninstall'], array_column($payloads, 'reason'));
            self::assertSame(3, $source->discardCalls);
            self::assertNull($this->journalState($journal)['revocation_reason']);
            self::assertSame($uninstalled, $coordinator->uninstall());
            self::assertCount(2, $this->exchange->requests[OriginProtocol::REVOKE_PATH]);

            try {
                $coordinator->revoke();
                self::fail('administrator revoke replaced completed uninstall authority');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'duo: cloud origin pairing was revoked with different authority',
                    $error->getMessage()
                );
            }
        } finally {
            sodium_memzero($originSecret);
        }
    }

    public function testUninstallCleanupIntentSurvivesFailureBeforePairingIntent(): void {
        [$pairing, , $originSecret] = $this->pairedOrigin();
        try {
            $commit = str_repeat('9', 40);
            $nonce = base64_encode(str_repeat('x', 32));
            $demand = [
                'chunk_size' => 1048576,
                'demand_generation' => 1,
                'demand_id' => OriginProtocol::demandId(
                    'tenant-a', 'site-a', 1, 1, $nonce, $commit
                ),
                'expires_at' => self::NOW + 300,
                'expected_production_commit' => $commit,
                'format' => OriginProtocol::DEMAND_FORMAT,
                'nonce' => $nonce,
                'retention_deadline' => self::NOW + 3600,
                'snapshot_mode' => 'portable-refresh',
            ];
            $this->authority->publishDemand('tenant-a', 'site-a', $demand);
            $journal = new OriginUploadJournal($this->scratch . '/uninstall-intent-journal');
            $sessionId = OriginProtocol::sessionId(
                'tenant-a', 'site-a', 1, 1, $demand['demand_id'], $commit
            );
            $source = new OriginUploadFixtureSource(
                $journal->statePath(),
                $sessionId,
                $demand,
                'UNINSTALL-INTENT-PRIVATE-SPOOL'
            );
            $source->crashAfterFirstSeal = false;
            $source->crashBeforeFirstChunkRead = true;
            $source->crashBeforeFirstDiscard = false;
            $coordinator = new OriginUploadCoordinator(
                $pairing,
                $journal,
                $this->scratch . '/repository',
                $source
            );
            $this->assertFailsWith($coordinator, 'simulated death before first chunk request');

            $pairingReflection = new \ReflectionObject($pairing);
            $storeProperty = $pairingReflection->getProperty('store');
            $store = $storeProperty->getValue($pairing);
            self::assertInstanceOf(OriginPairingStateStore::class, $store);
            $storeReflection = new \ReflectionObject($store);
            $lockProperty = $storeReflection->getProperty('lockPath');
            $lockPath = $lockProperty->getValue($store);
            self::assertIsString($lockPath);
            self::assertTrue(unlink($lockPath));
            $outside = $this->scratch . '/pairing-lock-substitute';
            self::assertSame(8, file_put_contents($outside, 'retained'));
            self::assertTrue(symlink($outside, $lockPath));

            try {
                $coordinator->uninstall();
                self::fail('pairing-intent failure cleared the durable uninstall cleanup');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'duo: cloud origin pairing lock path is unsafe',
                    $error->getMessage()
                );
            }
            $cleaning = $this->journalState($journal);
            self::assertSame('cleaning', $cleaning['phase']);
            self::assertSame('uninstall', $cleaning['active']['terminal_reason']);
            self::assertSame('uninstall', $cleaning['revocation_reason']);
            self::assertTrue($source->discarded);
            self::assertSame(1, $source->discardCalls);
            self::assertArrayNotHasKey(OriginProtocol::REVOKE_PATH, $this->exchange->requests);

            self::assertTrue(unlink($lockPath));
            try {
                $coordinator->run();
                self::fail('ordinary export cleared a durable uninstall cleanup intent');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'duo: cloud origin upload cleanup requires its matching revocation command',
                    $error->getMessage()
                );
            }
            self::assertSame(1, $source->discardCalls);
            try {
                $coordinator->revoke();
                self::fail('administrator revoke replaced a durable uninstall cleanup intent');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'duo: cloud origin upload cleanup binds another revocation reason',
                    $error->getMessage()
                );
            }
            self::assertSame(1, $source->discardCalls);
            self::assertSame('paired', $pairing->status()['phase']);
            self::assertArrayNotHasKey(OriginProtocol::REVOKE_PATH, $this->exchange->requests);

            $uninstalled = $coordinator->uninstall();
            self::assertSame('revoked', $uninstalled['phase']);
            $terminal = $this->journalState($journal);
            self::assertSame('idle', $terminal['phase']);
            self::assertNull($terminal['revocation_reason']);
            self::assertSame(2, $source->discardCalls);
            self::assertSame(
                ['uninstall'],
                array_column($this->exchange->payloads(OriginProtocol::REVOKE_PATH), 'reason')
            );
        } finally {
            sodium_memzero($originSecret);
        }
    }

    public function testDemandPollingRevocationIntentSurvivesFailureBeforePairingIntent(): void {
        [$pairing, , $originSecret] = $this->pairedOrigin();
        try {
            $journal = new OriginUploadJournal($this->scratch . '/poll-revocation-intent-journal');
            $coordinator = new OriginUploadCoordinator(
                $pairing,
                $journal,
                $this->scratch . '/repository'
            );
            self::assertSame('idle', $coordinator->run()['remote_state']);
            $this->exchange->loseAfterCommit[OriginProtocol::DEMAND_POLL_PATH] = 1;
            $this->assertFailsWith(
                $coordinator,
                'simulated lost response at ' . OriginProtocol::DEMAND_POLL_PATH
            );
            self::assertSame('demand_polling', $this->journalState($journal)['phase']);
            $pollRequests = count($this->exchange->requests[OriginProtocol::DEMAND_POLL_PATH]);

            $pairingReflection = new \ReflectionObject($pairing);
            $storeProperty = $pairingReflection->getProperty('store');
            $store = $storeProperty->getValue($pairing);
            self::assertInstanceOf(OriginPairingStateStore::class, $store);
            $storeReflection = new \ReflectionObject($store);
            $lockProperty = $storeReflection->getProperty('lockPath');
            $lockPath = $lockProperty->getValue($store);
            self::assertIsString($lockPath);
            self::assertTrue(unlink($lockPath));
            self::assertTrue(symlink($this->scratch . '/authority.json', $lockPath));

            try {
                $coordinator->uninstall();
                self::fail('pairing-intent failure erased the polling uninstall authority');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'duo: cloud origin pairing lock path is unsafe',
                    $error->getMessage()
                );
            }
            $intent = $this->journalState($journal);
            self::assertSame('idle', $intent['phase']);
            self::assertNull($intent['active']);
            self::assertSame('uninstall', $intent['revocation_reason']);
            self::assertArrayNotHasKey(OriginProtocol::REVOKE_PATH, $this->exchange->requests);

            self::assertTrue(unlink($lockPath));
            try {
                $coordinator->run();
                self::fail('origin export escaped a durable polling uninstall authority');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'duo: cloud origin upload cleanup requires its matching revocation command',
                    $error->getMessage()
                );
            }
            self::assertSame(
                $pollRequests,
                count($this->exchange->requests[OriginProtocol::DEMAND_POLL_PATH])
            );
            try {
                $coordinator->rotate();
                self::fail('origin rotation escaped a durable polling uninstall authority');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'duo: cloud origin key rotation requires an idle upload journal',
                    $error->getMessage()
                );
            }
            self::assertArrayNotHasKey(OriginProtocol::ROTATE_PATH, $this->exchange->requests);
            try {
                $coordinator->revoke();
                self::fail('administrator revoke replaced the polling uninstall authority');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'duo: cloud origin upload cleanup binds another revocation reason',
                    $error->getMessage()
                );
            }
            self::assertArrayNotHasKey(OriginProtocol::REVOKE_PATH, $this->exchange->requests);

            $uninstalled = $coordinator->uninstall();
            self::assertSame('revoked', $uninstalled['phase']);
            $terminal = $this->journalState($journal);
            self::assertSame('idle', $terminal['phase']);
            self::assertNull($terminal['revocation_reason']);
            self::assertSame(
                ['uninstall'],
                array_column($this->exchange->payloads(OriginProtocol::REVOKE_PATH), 'reason')
            );
        } finally {
            sodium_memzero($originSecret);
        }
    }

    public function testRevokeRepairsASealedSessionLeftByTheFormerDirectCommandPath(): void {
        [$pairing, , $originSecret] = $this->pairedOrigin();
        try {
            $commit = str_repeat('e', 40);
            $nonce = base64_encode(str_repeat('s', 32));
            $demand = [
                'chunk_size' => 1048576,
                'demand_generation' => 1,
                'demand_id' => OriginProtocol::demandId(
                    'tenant-a', 'site-a', 1, 1, $nonce, $commit
                ),
                'expires_at' => self::NOW + 300,
                'expected_production_commit' => $commit,
                'format' => OriginProtocol::DEMAND_FORMAT,
                'nonce' => $nonce,
                'retention_deadline' => self::NOW + 3600,
                'snapshot_mode' => 'portable-refresh',
            ];
            $this->authority->publishDemand('tenant-a', 'site-a', $demand);
            $journal = new OriginUploadJournal($this->scratch . '/former-revoke-journal');
            $sessionId = OriginProtocol::sessionId(
                'tenant-a', 'site-a', 1, 1, $demand['demand_id'], $commit
            );
            $source = new OriginUploadFixtureSource(
                $journal->statePath(), $sessionId, $demand, 'FORMER-REVOKE-ORIGIN-BYTES'
            );
            $coordinator = new OriginUploadCoordinator(
                $pairing, $journal, $this->scratch . '/repository', $source
            );
            $this->assertFailsWith($coordinator, 'simulated death after immutable capture');
            $accepted = $this->journalState($journal);
            self::assertSame('accepted', $accepted['phase']);
            self::assertNull($accepted['active']['manifest_sha256']);

            $pairing->revoke();
            self::assertSame('revoked', $pairing->status()['phase']);
            self::assertFalse($source->discarded);

            try {
                $coordinator->revoke();
                self::fail('expected cleanup crash for the formerly leaked spool');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'simulated death after cloud commit before local cleanup',
                    $error->getMessage()
                );
            }
            $cleaning = $this->journalState($journal);
            self::assertSame('cleaning', $cleaning['phase']);
            self::assertSame('administrator_requested', $cleaning['active']['terminal_reason']);
            self::assertNull($cleaning['active']['manifest_sha256']);

            $status = $coordinator->revoke();
            self::assertSame('revoked', $status['phase']);
            self::assertTrue($source->discarded);
            self::assertSame('idle', $this->journalState($journal)['phase']);
            self::assertCount(1, $this->exchange->requests[OriginProtocol::REVOKE_PATH]);
        } finally {
            sodium_memzero($originSecret);
        }
    }

    public function testSignedRemoteRevocationBecomesTerminalBeforeFreshGenerationPairing(): void {
        [$pairing, , $originSecret] = $this->pairedOrigin();
        try {
            $remote = new OriginCloudClient(
                'http://127.0.0.1:31337',
                'service-key-v1',
                $this->servicePublic,
                $originSecret,
                $this->exchange
            );
            self::assertSame(
                'revoked',
                $remote->revoke('tenant-a', 'site-a', 1, 'uninstall')['state']
            );
            self::assertSame('paired', $pairing->status()['phase']);

            $journal = new OriginUploadJournal($this->scratch . '/remote-revoke-journal');
            $coordinator = new OriginUploadCoordinator(
                $pairing,
                $journal,
                $this->scratch . '/repository'
            );
            try {
                $coordinator->run();
                self::fail('remotely revoked origin continued polling demands');
            } catch (\Duo\OriginCloudRefusal $refusal) {
                self::assertSame('origin_revoked', $refusal->reasonCode());
                self::assertFalse($refusal->retryable());
            }
            self::assertSame('demand_polling', $this->journalState($journal)['phase']);
            self::assertSame('paired', $pairing->status()['phase']);

            $inactive = $coordinator->revoke();
            self::assertSame('remote_inactive', $inactive['phase']);
            $this->assertControllerDocument('revoke', $inactive);
            self::assertSame('idle', $this->journalState($journal)['phase']);
            $same = $coordinator->revoke();
            self::assertSame($inactive, $same);

            $replacement = $this->authority->issueDeviceCode('tenant-a', 'site-a');
            $pending = $coordinator->beginPairing(
                $replacement['device_code'],
                $this->connector()
            );
            self::assertSame('pending', $pending['phase']);
            $paired = $pairing->poll();
            self::assertSame('paired', $paired['state']);
            self::assertSame(2, $paired['pairing']['origin_generation']);
            $idle = $coordinator->run();
            self::assertSame('idle', $idle['remote_state']);
            self::assertSame(2, $idle['origin_generation']);
        } finally {
            sodium_memzero($originSecret);
        }
    }

    public function testControllerAcceptsWireValidRevokedDemandPollWithZeroInterval(): void {
        // OriginCloudClient accepts zero for non-idle demand-poll states, and
        // OriginUploadCoordinator::view publishes that exact terminal value.
        $this->assertControllerDocument('export', [
            'active_demand_generation' => null,
            'after_demand_generation' => 0,
            'last_commit' => null,
            'origin_generation' => 1,
            'phase' => 'idle',
            'poll_after_seconds' => 0,
            'poll_sequence' => 1,
            'remote_state' => 'revoked',
            'site_id' => 'site-a',
            'tenant_id' => 'tenant-a',
        ]);
    }

    public function testJournalRefusesSymlinkDirectoryAndStatePath(): void {
        self::assertTrue(mkdir($this->scratch . '/actual-journal', 0700));
        self::assertTrue(symlink($this->scratch . '/actual-journal', $this->scratch . '/linked-journal'));
        try {
            new OriginUploadJournal($this->scratch . '/linked-journal');
            self::fail('symlink journal directory was accepted');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('directory is unsafe', $error->getMessage());
        }

        $journal = new OriginUploadJournal($this->scratch . '/safe-journal');
        $journal->locked(static function ($session): void {
            $session->save(['format' => 'test']);
        });
        self::assertTrue(unlink($journal->statePath()));
        self::assertTrue(symlink($this->scratch . '/authority.json', $journal->statePath()));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('state path is unsafe');
        $journal->locked(static fn ($session) => $session->state());
    }

    public function testInterruptedUploadJournalWriteIsReconciledBeforeTheRealStoreWrite(): void {
        $journal = new OriginUploadJournal($this->scratch . '/residue-journal');
        $temporary = $journal->statePath() . '.tmp';
        self::assertSame(7, file_put_contents($temporary, 'partial'));
        self::assertTrue(chmod($temporary, 0600));
        $expected = ['format' => 'upload-residue-regression/v1', 'sequence' => 1];

        $journal->locked(static function ($session) use ($expected): void {
            $session->save($expected);
        });

        self::assertFileExists($journal->statePath());
        self::assertFileDoesNotExist($temporary);
        self::assertSame([], glob(dirname($journal->statePath()) . '/*.tmp') ?: []);
        self::assertSame(
            $expected,
            $journal->locked(static fn ($session): ?array => $session->state())
        );
    }

    public function testSymlinkedUploadJournalCrashResidueIsRefusedWithoutUnlinkingTarget(): void {
        $journal = new OriginUploadJournal($this->scratch . '/unsafe-residue-journal');
        $outside = $this->scratch . '/outside-upload-journal';
        self::assertSame(8, file_put_contents($outside, 'retained'));
        self::assertTrue(chmod($outside, 0600));
        $temporary = $journal->statePath() . '.tmp';
        self::assertTrue(symlink($outside, $temporary));

        try {
            $journal->locked(static fn (): null => null);
            self::fail('symlinked upload journal crash residue was accepted');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('temporary state is unsafe', $error->getMessage());
            self::assertSame('retained', file_get_contents($outside));
            self::assertTrue(is_link($temporary));
        }
    }

    public function testControlPlaneGuardPrecedesJournalPathsAndCoordinatorState(): void {
        $file = DUO_REPO_ROOT . '/agent/src/Cloud/OriginUploadCoordinator.php';
        $script = 'require ' . var_export($file, true) . ';'
            . '$class = new ReflectionClass(\\Duo\\OriginUploadCoordinator::class);'
            . '$coordinator = $class->newInstanceWithoutConstructor();'
            . 'try { $coordinator->run(); } catch (Throwable $error) {'
            . 'fwrite(STDOUT, $error->getMessage()); exit(23); } exit(24);';
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, '-r', $script],
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
        self::assertSame(23, proc_close($process), (string) $stderr);
        self::assertSame(
            'duo: cloud origin upload requires the isolated WP-CLI control plane',
            $stdout
        );
    }

    /** @return array{OriginPairing,string,string} */
    private function pairedOrigin(): array {
        $issued = $this->authority->issueDeviceCode('tenant-a', 'site-a');
        $pairing = new OriginPairing(
            new OriginPairingStateStore($this->scratch . '/pairing', str_repeat('k', 32)),
            'http://127.0.0.1:31337',
            'service-key-v1',
            $this->servicePublic,
            $this->exchange
        );
        $pairing->begin($issued['device_code'], $this->connector());
        $polled = $pairing->poll();
        self::assertSame('paired', $polled['state']);

        $reflection = new \ReflectionObject($pairing);
        $storeProperty = $reflection->getProperty('store');
        $store = $storeProperty->getValue($pairing);
        self::assertInstanceOf(OriginPairingStateStore::class, $store);
        $secret = $store->locked(static function ($session): string {
            $state = $session->state();
            if (!is_array($state) || !is_string($state['origin_secret_key'] ?? null)) {
                throw new \RuntimeException('paired fixture has no origin secret');
            }
            $decoded = base64_decode($state['origin_secret_key'], true);
            if (!is_string($decoded)) {
                throw new \RuntimeException('paired fixture origin secret is malformed');
            }
            return $decoded;
        });
        return [$pairing, $issued['device_code'], $secret];
    }

    /** @return array<string,mixed> */
    private function connector(): array {
        return [
            'agent_version' => '1.0.0',
            'home_url_sha256' => hash('sha256', 'https://example.test'),
            'installation_id' => 'installation-a',
            'multisite' => false,
            'php_version' => PHP_VERSION,
            'site_url_sha256' => hash('sha256', 'https://example.test'),
            'wordpress_version' => '7.0.3',
        ];
    }

    private function assertFailsWith(OriginUploadCoordinator $coordinator, string $message): void {
        try {
            $coordinator->run();
            self::fail("expected coordinator failure: $message");
        } catch (\RuntimeException $error) {
            self::assertSame($message, $error->getMessage());
        }
    }

    /** @param array<string,mixed> $result */
    private function assertControllerDocument(string $operation, array $result): void {
        $method = new \ReflectionMethod(\Duo\Orchestrator\OriginCommand::class, 'validDocument');
        self::assertTrue($method->invoke(null, [
            'format' => 'duo-cloud-origin-command/v1',
            'operation' => $operation,
            'result' => $result,
        ], $operation));
    }

    /** @param array<string,mixed> $result */
    private function assertControllerRejects(string $operation, array $result): void {
        $method = new \ReflectionMethod(\Duo\Orchestrator\OriginCommand::class, 'validDocument');
        self::assertFalse($method->invoke(null, [
            'format' => 'duo-cloud-origin-command/v1',
            'operation' => $operation,
            'result' => $result,
        ], $operation));
    }

    /** @return array<string,mixed> */
    private function journalState(OriginUploadJournal $journal): array {
        $decoded = json_decode((string) file_get_contents($journal->statePath()), true, 64, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        return $decoded;
    }

    private function removeTree(string $path): void {
        $items = scandir($path);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
