<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\CommandRunner;
use Duo\Cloud\ControlAuthority;
use Duo\Cloud\ControlRefusal;
use Duo\Cloud\FileAuthorityStore;
use Duo\Cloud\OriginAgentCanon;
use Duo\Cloud\OriginAuthority;
use Duo\Cloud\OriginControllerGateway;
use Duo\Cloud\OriginFileBlobStore;
use Duo\OriginCloudClient;
use Duo\Orchestrator\CloudOriginExportClient;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\RefreshProductionSource;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/src/OriginControllerGateway.php';
require_once DUO_REPO_ROOT . '/cloud/src/OriginFileBlobStore.php';
require_once DUO_REPO_ROOT . '/agent/src/Kernel/Canon.php';
require_once DUO_REPO_ROOT . '/agent/src/Cloud/OriginCloudClient.php';
require_once DUO_REPO_ROOT . '/cli/src/Transport/EnvironmentDriver.php';
require_once DUO_REPO_ROOT . '/cli/src/Refresh/CloudOriginExportClient.php';

/** @internal */
final class OriginControllerNoopRunner implements CommandRunner {
    public function run(array $request): array {
        throw new \RuntimeException('origin controller identity checks must not execute workload commands');
    }
}

#[CoversNothing]
final class OriginControllerGatewayTest extends TestCase {
    private const NOW = 2000000000;
    private const COMMIT = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const OPERATION = 'preview-export-operation-a';
    private const ENDPOINT = 'http://127.0.0.1/v1/origin/controller/export';

    private string $scratch;
    private string $serviceSecret;
    private string $servicePublic;
    private string $controllerSecret;
    private string $controllerPublic;
    private string $originSecret;
    private int $now = self::NOW;
    private string|false $previousTestMode;
    private OriginAuthority $origin;
    private ControlAuthority $controllerKeys;
    private OriginControllerGateway $gateway;
    private OriginCloudClient $originClient;
    private CloudOriginExportClient $controllerClient;

    protected function setUp(): void {
        $this->previousTestMode = getenv('DUO_TEST_MODE');
        putenv('DUO_TEST_MODE=1');
        $this->scratch = sys_get_temp_dir() . '/duo-origin-controller-gateway-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
        self::assertTrue(mkdir($this->scratch . '/blobs', 0700));
        self::assertTrue(chmod($this->scratch . '/blobs', 0700));

        $service = sodium_crypto_sign_keypair();
        $this->serviceSecret = sodium_crypto_sign_secretkey($service);
        $this->servicePublic = sodium_crypto_sign_publickey($service);
        $controller = sodium_crypto_sign_keypair();
        $this->controllerSecret = sodium_crypto_sign_secretkey($controller);
        $this->controllerPublic = sodium_crypto_sign_publickey($controller);
        $originKeypair = sodium_crypto_sign_keypair();
        $this->originSecret = sodium_crypto_sign_secretkey($originKeypair);

        $this->origin = new OriginAuthority(
            new FileAuthorityStore($this->scratch . '/origin.json'),
            new OriginFileBlobStore($this->scratch . '/blobs'),
            'service-key-v1',
            $this->serviceSecret,
            random_bytes(32),
            fn (): int => $this->now
        );
        $this->controllerKeys = new ControlAuthority(
            new FileAuthorityStore($this->scratch . '/controller-keys.json'),
            new OriginControllerNoopRunner(),
            'service-key-v1',
            $this->serviceSecret
        );
        $this->controllerKeys->registerRequestKey(
            'controller-key-v1',
            'tenant-a',
            'site-a',
            $this->controllerPublic
        );
        $this->gateway = new OriginControllerGateway(
            $this->origin,
            $this->controllerKeys,
            new FileAuthorityStore($this->scratch . '/controller-receipts.json'),
            'service-key-v1',
            $this->serviceSecret,
            fn (): int => $this->now
        );

        $this->originClient = new OriginCloudClient(
            'http://127.0.0.1',
            'service-key-v1',
            $this->servicePublic,
            $this->originSecret,
            fn (string $url, string $body): array => $this->originExchange($url, $body)
        );
        $this->pairOrigin();
        $this->controllerClient = $this->controllerClient('tenant-a', 'site-a');
    }

    protected function tearDown(): void {
        unset(
            $this->controllerClient,
            $this->originClient,
            $this->gateway,
            $this->controllerKeys,
            $this->origin
        );
        if (isset($this->scratch) && is_dir($this->scratch)) {
            $this->removeTree($this->scratch);
        }
        foreach (['serviceSecret', 'controllerSecret', 'originSecret'] as $property) {
            if (isset($this->{$property}) && $this->{$property} !== '') {
                sodium_memzero($this->{$property});
            }
        }
        $this->previousTestMode === false
            ? putenv('DUO_TEST_MODE')
            : putenv('DUO_TEST_MODE=' . $this->previousTestMode);
    }

    public function testControllerOriginHttpEndpointRequiresTestModeAndALiteralLoopbackInterface(): void {
        putenv('DUO_TEST_MODE');
        try {
            $this->controllerClient('tenant-a', 'site-a');
            self::fail('production controller origin client accepted loopback HTTP');
        } catch (\RuntimeException $error) {
            self::assertSame('cloud origin endpoint must use HTTPS', $error->getMessage());
        } finally {
            putenv('DUO_TEST_MODE=1');
        }

        try {
            new CloudOriginExportClient(
                'http://localhost/v1/origin/controller/export',
                'tenant-a',
                'site-a',
                'controller-key-v1',
                $this->controllerSecret,
                'service-key-v1',
                $this->servicePublic,
                30,
                static fn (): array => []
            );
            self::fail('controller origin client accepted a hostname instead of a loopback interface');
        } catch (\RuntimeException $error) {
            self::assertSame('cloud origin endpoint must use HTTPS', $error->getMessage());
        }
    }

    public function testRealClientRequestsUploadsPollsAndReconstructsExactProductionBytes(): void {
        $demand = $this->controllerClient->requestPortableExport(self::COMMIT, self::OPERATION);
        self::assertSame(1, $demand['demand_generation']);
        self::assertSame(self::COMMIT, $demand['expected_production_commit']);
        self::assertSame(32, strlen((string) base64_decode($demand['nonce'], true)));

        $polledByOrigin = $this->originClient->demandPoll('tenant-a', 'site-a', 1, 0, 0);
        self::assertSame('demanded', $polledByOrigin['state']);
        self::assertSame(
            CanonicalJson::encode($demand),
            CanonicalJson::encode($polledByOrigin['demand'])
        );
        $statusBefore = $this->controllerClient->pollPortableExport($demand, self::OPERATION, 0);
        self::assertSame('demanded', $statusBefore['state']);

        [$exportBytes, $manifest] = $this->fixture($demand);
        $announced = $this->originClient->announce(
            'tenant-a',
            'site-a',
            1,
            $demand['demand_generation'],
            $demand['demand_id'],
            $manifest
        );
        $exportId = $announced['export_id'];
        foreach ($manifest['chunks'] as $descriptor) {
            $chunk = substr($exportBytes, $descriptor['offset'], $descriptor['size']);
            $stored = $this->originClient->putChunk(
                'tenant-a',
                'site-a',
                1,
                $demand['demand_generation'],
                $demand['demand_id'],
                $exportId,
                $manifest['manifest_sha256'],
                $descriptor['sha256'],
                $chunk
            );
            self::assertSame('stored', $stored['state']);
        }
        $committed = $this->originClient->commit(
            'tenant-a',
            'site-a',
            1,
            $demand['demand_generation'],
            $demand['demand_id'],
            $exportId,
            $manifest['manifest_sha256']
        );
        self::assertSame('committed', $committed['state']);

        // Sequence zero is an exact replay of the earlier mutable observation;
        // a new sequence is required to observe the committed transition.
        self::assertSame(
            $statusBefore,
            $this->controllerClient->pollPortableExport($demand, self::OPERATION, 0)
        );
        $status = $this->controllerClient->pollPortableExport($demand, self::OPERATION, 1);
        self::assertSame('committed', $status['state']);
        self::assertSame($exportId, $status['export_id']);

        $export = $this->controllerClient->readCommittedExport($demand, $status, self::OPERATION);
        self::assertInstanceOf(RefreshProductionSource::class, $export);
        self::assertNotInstanceOf(EnvironmentDriver::class, $export);
        self::assertSame($exportBytes, $export->canonicalBytes());
        self::assertSame(
            CanonicalJson::encode($manifest),
            CanonicalJson::encode($export->manifest())
        );
        self::assertSame(
            json_decode($exportBytes, true, 512, JSON_THROW_ON_ERROR),
            $export->readProductionSnapshot(self::COMMIT)
        );
        $export->assertProductionRevision(self::COMMIT);
        self::assertSame(
            $demand,
            $this->controllerClient->requestPortableExport(self::COMMIT, self::OPERATION)
        );
    }

    public function testTamperForeignStaleAndRevokedRequestsNeverLeakCrossTenantState(): void {
        $demand = $this->controllerClient->requestPortableExport(self::COMMIT, self::OPERATION);

        $foreign = $this->controllerClient('tenant-b', 'site-a');
        $foreignMessage = $this->runtimeMessage(
            fn (): array => $foreign->requestPortableExport(self::COMMIT, self::OPERATION)
        );
        self::assertSame('cloud origin request was refused; remote output is redacted', $foreignMessage);

        $stale = $demand;
        $stale['demand_id'] = str_repeat('f', 64);
        $staleMessage = $this->runtimeMessage(
            fn (): array => $this->controllerClient->pollPortableExport($stale, self::OPERATION, 9)
        );
        self::assertSame($foreignMessage, $staleMessage);

        $tampered = $this->controllerClient(
            'tenant-a',
            'site-a',
            function (string $url, string $body): array {
                $response = $this->gateway->handle($body);
                $envelope = CanonicalJson::decodeObject($response);
                $envelope['signature'] = base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES));
                return [
                    'body' => CanonicalJson::encode($envelope) . "\n",
                    'effective_url' => $url,
                    'redirected' => false,
                    'status' => 200,
                ];
            }
        );
        self::assertSame(
            'cloud origin response signature is invalid',
            $this->runtimeMessage(
                fn (): array => $tampered->requestPortableExport(self::COMMIT, self::OPERATION)
            )
        );

        $this->controllerKeys->revokeRequestKey('controller-key-v1', 'tenant-a', 'site-a');
        $revokedMessage = $this->runtimeMessage(
            fn (): array => $this->controllerClient->requestPortableExport(self::COMMIT, self::OPERATION)
        );
        self::assertSame($foreignMessage, $revokedMessage);
        self::assertStringNotContainsString('tenant-a', $revokedMessage);
        self::assertStringNotContainsString('site-a', $revokedMessage);
        unset($foreign, $tampered);
    }

    public function testControllerAndOriginReceiptsStayBoundedAcrossOneThousandDemandGenerations(): void {
        $previousDemand = null;
        $previousOperation = null;
        $twoBackDemand = null;
        $twoBackOperation = null;

        for ($generation = 1; $generation <= 1000; $generation++) {
            $operation = 'preview-export-cycle-' . $generation;
            $demand = $this->controllerClient->requestPortableExport(self::COMMIT, $operation);
            self::assertSame($generation, $demand['demand_generation']);
            self::assertSame('demanded', $this->originClient->demandPoll(
                'tenant-a',
                'site-a',
                1,
                $generation - 1,
                $generation
            )['state']);
            self::assertSame(
                'demanded',
                $this->controllerClient->pollPortableExport($demand, $operation, 0)['state']
            );
            if ($generation === 998) {
                $twoBackDemand = $demand;
                $twoBackOperation = $operation;
            } elseif ($generation === 999) {
                $previousDemand = $demand;
                $previousOperation = $operation;
            }
            if ($generation < 1000) {
                $this->now = $demand['expires_at'] + 1;
            }
        }

        self::assertIsArray($previousDemand);
        self::assertIsString($previousOperation);
        self::assertSame(
            CanonicalJson::encode($previousDemand),
            CanonicalJson::encode($this->controllerClient->requestPortableExport(
                self::COMMIT,
                $previousOperation
            ))
        );
        self::assertSame(
            'demanded',
            $this->controllerClient->pollPortableExport(
                $previousDemand,
                $previousOperation,
                0
            )['state']
        );
        self::assertIsArray($twoBackDemand);
        self::assertIsString($twoBackOperation);
        self::assertSame(
            'cloud origin request was refused; remote output is redacted',
            $this->runtimeMessage(fn (): array => $this->controllerClient->requestPortableExport(
                self::COMMIT,
                $twoBackOperation
            ))
        );

        $originState = CanonicalJson::decodeObject(
            (string) file_get_contents($this->scratch . '/origin.json')
        );
        self::assertCount(2, $originState['controller_demands']);
        $originDemandReceipts = array_filter(
            $originState['receipts'],
            static fn (array $receipt): bool => $receipt['scope'] === 'demand'
        );
        self::assertCount(2, $originDemandReceipts);
        self::assertLessThan(32768, filesize($this->scratch . '/origin.json'));

        $gatewayState = CanonicalJson::decodeObject(
            (string) file_get_contents($this->scratch . '/controller-receipts.json')
        );
        $site = array_values($gatewayState['sites'])[0];
        self::assertCount(2, $site['generations']);
        foreach ($site['generations'] as $generation) {
            self::assertCount(2, $generation['receipts']);
        }
        self::assertLessThan(32768, filesize($this->scratch . '/controller-receipts.json'));
    }

    public function testControllerReceiptCountIsHardBoundedBeforeAUniquePoll(): void {
        $demand = $this->controllerClient->requestPortableExport(self::COMMIT, self::OPERATION);
        $first = null;
        for ($sequence = 0; $sequence < 255; $sequence++) {
            $status = $this->controllerClient->pollPortableExport(
                $demand,
                self::OPERATION,
                $sequence
            );
            if ($sequence === 0) {
                $first = $status;
            }
        }
        self::assertSame(
            'cloud origin request was refused; remote output is redacted',
            $this->runtimeMessage(fn (): array => $this->controllerClient->pollPortableExport(
                $demand,
                self::OPERATION,
                255
            ))
        );
        self::assertIsArray($first);
        self::assertSame(
            $first,
            $this->controllerClient->pollPortableExport($demand, self::OPERATION, 0)
        );
    }

    private function pairOrigin(): void {
        $issued = $this->origin->issueDeviceCode('tenant-a', 'site-a');
        $attempt = str_repeat('a', 32);
        $begin = $this->originClient->pairBegin($issued['device_code'], [
            'agent_version' => '1.0.0',
            'home_url_sha256' => hash('sha256', 'https://origin.example.test'),
            'installation_id' => 'origin-installation-a',
            'multisite' => false,
            'php_version' => PHP_VERSION,
            'site_url_sha256' => hash('sha256', 'https://origin.example.test'),
            'wordpress_version' => '6.9',
        ], $attempt);
        $paired = $this->originClient->pairPoll($attempt, $begin['pairing_id'], 0);
        self::assertSame('paired', $paired['state']);
    }

    /** @return array<string,mixed> */
    private function originExchange(string $url, string $body): array {
        $path = parse_url($url, PHP_URL_PATH);
        self::assertIsString($path);
        try {
            return [
                'body' => $this->origin->handle($path, $body),
                'effective_url' => $url,
                'redirected' => false,
                'status' => 200,
            ];
        } catch (ControlRefusal) {
            return [
                'body' => "{\"error\":\"request_refused\"}\n",
                'effective_url' => $url,
                'redirected' => false,
                'status' => 403,
            ];
        }
    }

    /**
     * @param ?callable(string,string):array<string,mixed> $exchange
     */
    private function controllerClient(
        string $tenantId,
        string $siteId,
        ?callable $exchange = null
    ): CloudOriginExportClient {
        $exchange ??= function (string $url, string $body): array {
            try {
                return [
                    'body' => $this->gateway->handle($body),
                    'effective_url' => $url,
                    'redirected' => false,
                    'status' => 200,
                ];
            } catch (ControlRefusal) {
                return [
                    'body' => "{\"error\":\"request_refused\"}\n",
                    'effective_url' => $url,
                    'redirected' => false,
                    'status' => 403,
                ];
            }
        };
        return new CloudOriginExportClient(
            self::ENDPOINT,
            $tenantId,
            $siteId,
            'controller-key-v1',
            $this->controllerSecret,
            'service-key-v1',
            $this->servicePublic,
            30,
            $exchange
        );
    }

    /** @param array<string,mixed> $demand @return array{string,array<string,mixed>} */
    private function fixture(array $demand): array {
        $content = str_repeat('portable-origin-state-', 56000);
        $repository = [
            'artifact_hash' => hash('sha256', 'repository-artifact'),
            'code_revision' => null,
            'revision_hash' => hash('sha256', 'repository-revision'),
        ];
        $snapshot = [
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
                    'content' => $content,
                    'hash' => hash('sha256', $content),
                    'identity' => 'options/core',
                    'path' => 'options/core.json',
                    'type' => 'options',
                ],
            ],
            'repository' => $repository,
            'warnings' => [],
        ];
        $snapshot['snapshot_hash'] = hash('sha256', OriginAgentCanon::encode($snapshot));
        $bytes = OriginAgentCanon::encode($snapshot);
        $chunks = [];
        for ($offset = 0, $index = 0; $offset < strlen($bytes); $offset += 1048576, $index++) {
            $chunk = substr($bytes, $offset, 1048576);
            $chunks[] = [
                'index' => $index,
                'offset' => $offset,
                'sha256' => hash('sha256', $chunk),
                'size' => strlen($chunk),
            ];
        }
        $manifest = [
            'artifact_hash' => $repository['artifact_hash'],
            'chunks' => $chunks,
            'code_revision' => null,
            'expected_production_commit' => $demand['expected_production_commit'],
            'export_sha256' => hash('sha256', $bytes),
            'export_size' => strlen($bytes),
            'format' => 'duo-cloud-origin-export-manifest/v1',
            'generation' => $demand['demand_generation'],
            'repository_revision_hash' => $repository['revision_hash'],
            'snapshot_hash' => $snapshot['snapshot_hash'],
        ];
        $manifest['manifest_sha256'] = hash('sha256', OriginAgentCanon::encode($manifest));
        return [$bytes, $manifest];
    }

    /** @param callable():mixed $callback */
    private function runtimeMessage(callable $callback): string {
        try {
            $callback();
            self::fail('expected cloud origin request to fail');
        } catch (\RuntimeException $error) {
            return $error->getMessage();
        }
    }

    private function removeTree(string $path): void {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            if ($entry->isDir() && !$entry->isLink()) {
                self::assertTrue(rmdir($entry->getPathname()));
            } else {
                self::assertTrue(unlink($entry->getPathname()));
            }
        }
        self::assertTrue(rmdir($path));
    }
}
