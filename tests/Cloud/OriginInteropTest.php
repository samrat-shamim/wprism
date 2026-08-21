<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Canon;
use Duo\Cloud\CanonicalJson;
use Duo\Cloud\FileAuthorityStore;
use Duo\Cloud\OriginAuthority;
use Duo\Cloud\OriginFileBlobStore;
use Duo\Cloud\OriginProtocol;
use Duo\OriginCloudClient;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/agent/src/Cloud/OriginCloudClient.php';
require_once DUO_REPO_ROOT . '/cloud/src/OriginAuthority.php';
require_once DUO_REPO_ROOT . '/cloud/src/OriginFileBlobStore.php';

/**
 * The endpoint-specific unit fixtures deliberately test adversarial responses
 * in isolation. This test binds the real dependency-free connector to the
 * real cloud authority so a field, hash-domain, or response-shape divergence
 * cannot leave both independent fixtures green.
 */
#[CoversNothing]
final class OriginInteropTest extends TestCase {
    private const NOW = 2000000000;

    private string|false $priorTestMode;
    private string $scratch;
    private string $serviceSecret;
    private string $servicePublic;
    private string $originSecret;
    private OriginAuthority $authority;

    protected function setUp(): void {
        $this->priorTestMode = getenv('DUO_TEST_MODE');
        putenv('DUO_TEST_MODE=1');
        $this->scratch = sys_get_temp_dir() . '/duo-origin-interop-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700, true));
        self::assertTrue(mkdir($this->scratch . '/blobs', 0700));
        $service = sodium_crypto_sign_keypair();
        $this->serviceSecret = sodium_crypto_sign_secretkey($service);
        $this->servicePublic = sodium_crypto_sign_publickey($service);
        $origin = sodium_crypto_sign_keypair();
        $this->originSecret = sodium_crypto_sign_secretkey($origin);
        $this->authority = new OriginAuthority(
            new FileAuthorityStore($this->scratch . '/authority.json'),
            new OriginFileBlobStore($this->scratch . '/blobs'),
            'service-key-v1',
            $this->serviceSecret,
            random_bytes(32),
            static fn (): int => self::NOW
        );
    }

    protected function tearDown(): void {
        unset($this->authority);
        if (isset($this->scratch) && is_dir($this->scratch)) {
            $this->removeTree($this->scratch);
        }
        foreach (['serviceSecret', 'originSecret'] as $property) {
            if (isset($this->{$property}) && $this->{$property} !== '') {
                sodium_memzero($this->{$property});
            }
        }
        if ($this->priorTestMode === false) {
            putenv('DUO_TEST_MODE');
        } else {
            putenv('DUO_TEST_MODE=' . $this->priorTestMode);
        }
    }

    public function testConnectorAndAuthorityCompleteOneExactOriginLifecycle(): void {
        $client = $this->client($this->originSecret);
        $issued = $this->authority->issueDeviceCode('tenant-a', 'site-a');
        $attempt = str_repeat('a', 32);
        $begin = $client->pairBegin($issued['device_code'], $this->connector(), $attempt);
        $paired = $client->pairPoll($attempt, $begin['pairing_id'], 0);
        self::assertSame('paired', $paired['state']);
        self::assertSame('tenant-a', $paired['pairing']['tenant_id']);
        self::assertSame('site-a', $paired['pairing']['site_id']);

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
        $polled = $client->demandPoll('tenant-a', 'site-a', 1, 0, 0);
        self::assertSame('demanded', $polled['state']);
        self::assertSame(CanonicalJson::encode($demand), CanonicalJson::encode($polled['demand']));

        [$exportBytes, $manifest] = $this->exportFixture($demand);
        $announced = $client->announce('tenant-a', 'site-a', 1, 1, $demand['demand_id'], $manifest);
        $exportId = $announced['export_id'];
        $chunkHash = $manifest['chunks'][0]['sha256'];
        $missing = $client->missing(
            'tenant-a',
            'site-a',
            1,
            1,
            $demand['demand_id'],
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
            $demand['demand_id'],
            $exportId,
            $manifest['manifest_sha256'],
            $chunkHash,
            $exportBytes
        );
        $committed = $client->commit(
            'tenant-a',
            'site-a',
            1,
            1,
            $demand['demand_id'],
            $exportId,
            $manifest['manifest_sha256']
        );
        self::assertSame('committed', $committed['state']);
        self::assertSame(
            CanonicalJson::encode($manifest),
            CanonicalJson::encode($this->authority->readCommittedManifest('tenant-a', 'site-a', $exportId))
        );
        self::assertSame(
            $exportBytes,
            $this->authority->readCommittedChunk('tenant-a', 'site-a', $exportId, 0)
        );

        $next = sodium_crypto_sign_keypair();
        $nextSecret = sodium_crypto_sign_secretkey($next);
        try {
            $rotated = $client->rotate('tenant-a', 'site-a', 1, 2, $nextSecret);
            self::assertSame('rotated', $rotated['state']);
            $nextClient = $this->client($nextSecret);
            $revoked = $nextClient->revoke('tenant-a', 'site-a', 2, 'administrator_requested');
            self::assertSame('revoked', $revoked['state']);
        } finally {
            sodium_memzero($nextSecret);
        }
    }

    private function client(string $secret): OriginCloudClient {
        return new OriginCloudClient(
            'http://127.0.0.1:31337',
            'service-key-v1',
            $this->servicePublic,
            $secret,
            function (string $url, string $request, int $timeout, int $limit): array {
                self::assertGreaterThanOrEqual(1, $timeout);
                self::assertGreaterThanOrEqual(1, strlen($request));
                self::assertGreaterThanOrEqual(1048576, $limit);
                $path = parse_url($url, PHP_URL_PATH);
                self::assertIsString($path);
                return [
                    'body' => $this->authority->handle($path, $request),
                    'effective_url' => $url,
                    'redirected' => false,
                    'status' => 200,
                ];
            }
        );
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

    /** @return array{string,array<string,mixed>} */
    private function exportFixture(array $demand): array {
        $repository = [
            'artifact_hash' => hash('sha256', 'artifact'),
            'code_revision' => null,
            'revision_hash' => hash('sha256', 'revision'),
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
            'records' => [],
            'repository' => $repository,
            'warnings' => [],
        ];
        $export['snapshot_hash'] = hash('sha256', Canon::encode($export));
        $bytes = Canon::encode($export);
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
        $manifest['manifest_sha256'] = hash('sha256', Canon::encode($manifest));
        return [$bytes, $manifest];
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
