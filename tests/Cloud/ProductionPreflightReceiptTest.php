<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\ControlRefusal;
use Duo\Cloud\FileAuthorityStore;
use Duo\Cloud\ProductionHostPreflightReceipt;
use Duo\Cloud\ProductionPreflightReceipt;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/runtime/ProductionHostPreflightReceipt.php';
require_once DUO_REPO_ROOT . '/cloud/runtime/ProductionPreflightReceipt.php';

#[CoversNothing]
final class ProductionPreflightReceiptTest extends TestCase {
    private string $scratch;

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-preflight-receipts-'
            . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
        $this->scratch = (string) realpath($this->scratch);
    }

    protected function tearDown(): void {
        foreach (glob($this->scratch . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->scratch);
    }

    public function testSharedAndLocalLeasesStayReadableAcrossRefreshWriterLocks(): void {
        $now = 1_000;
        $configuration = hash('sha256', 'configuration');
        $deploymentProofs = hash('sha256', 'deployment-proofs');
        $hostAuthority = hash('sha256', 'host-authority');
        $host = new ProductionHostPreflightReceipt(
            new FileAuthorityStore($this->scratch . '/host.json'),
            $hostAuthority,
            static function () use (&$now): int {
                return $now;
            }
        );
        $local = new ProductionPreflightReceipt(
            new FileAuthorityStore($this->scratch . '/local.json'),
            $configuration,
            'localhost:5000/duo/wordpress@sha256:' . hash('sha256', 'image'),
            hash('sha256', 'seccomp'),
            hash('sha256', 'paths'),
            $hostAuthority,
            static function () use (&$now): int {
                return $now;
            }
        );
        $host->publish($this->hostEvidence($configuration, $hostAuthority, 1));
        $local->publish([
            'deployment_proofs_sha256' => $deploymentProofs,
            'host_authority_sha256' => $hostAuthority,
            'state' => 'ready',
        ]);

        $hostLock = fopen($this->scratch . '/host.json.lock', 'c+b');
        $localLock = fopen($this->scratch . '/local.json.lock', 'c+b');
        self::assertIsResource($hostLock);
        self::assertIsResource($localLock);
        self::assertTrue(flock($hostLock, LOCK_EX | LOCK_NB));
        self::assertTrue(flock($localLock, LOCK_EX | LOCK_NB));
        try {
            $started = microtime(true);
            self::assertSame('ready', $host->assertCurrent($configuration)['state']);
            self::assertSame(1_060, $local->assertCurrent($deploymentProofs)['expires_at']);
            self::assertLessThan(0.25, microtime(true) - $started);
        } finally {
            flock($hostLock, LOCK_UN);
            flock($localLock, LOCK_UN);
            fclose($hostLock);
            fclose($localLock);
        }

        $now = 1_020;
        $host->publish($this->hostEvidence($configuration, $hostAuthority, 2));
        self::assertSame(1_080, $host->assertCurrent($configuration)['expires_at']);
        self::assertSame(1_060, $local->assertCurrent($deploymentProofs)['expires_at']);
    }

    public function testDetectedFailureInvalidatesOnlyItsOwnAtomicGateAndCrashExpiresQuickly(): void {
        self::assertSame(60, ProductionHostPreflightReceipt::TTL_SECONDS);
        self::assertSame(60, ProductionPreflightReceipt::TTL_SECONDS);
        $now = 2_000;
        $configuration = hash('sha256', 'configuration');
        $deploymentProofs = hash('sha256', 'deployment-proofs');
        $hostAuthority = hash('sha256', 'host-authority');
        $host = new ProductionHostPreflightReceipt(
            new FileAuthorityStore($this->scratch . '/host.json'),
            $hostAuthority,
            static function () use (&$now): int {
                return $now;
            }
        );
        $local = new ProductionPreflightReceipt(
            new FileAuthorityStore($this->scratch . '/local.json'),
            $configuration,
            'registry.example.test/duo/wordpress@sha256:' . hash('sha256', 'image'),
            hash('sha256', 'seccomp'),
            hash('sha256', 'paths'),
            $hostAuthority,
            static function () use (&$now): int {
                return $now;
            }
        );
        $host->publish($this->hostEvidence($configuration, $hostAuthority, 1));
        $local->publish([
            'deployment_proofs_sha256' => $deploymentProofs,
            'host_authority_sha256' => $hostAuthority,
            'state' => 'ready',
        ]);

        $local->invalidate();
        self::assertSame('ready', $host->assertCurrent($configuration)['state']);
        $this->assertRefused(static fn (): array => $local->assertCurrent($deploymentProofs));

        $local->publish([
            'deployment_proofs_sha256' => $deploymentProofs,
            'host_authority_sha256' => $hostAuthority,
            'state' => 'ready',
        ]);
        $host->invalidate();
        self::assertSame(2_060, $local->assertCurrent($deploymentProofs)['expires_at']);
        $this->assertRefused(static fn (): array => $host->assertCurrent($configuration));

        $host->publish($this->hostEvidence($configuration, $hostAuthority, 2));
        $now = 2_060;
        $this->assertRefused(static fn (): array => $local->assertCurrent($deploymentProofs));
        $this->assertRefused(static fn (): array => $host->assertCurrent($configuration));
    }

    /** @return array<string,mixed> */
    private function hostEvidence(string $configuration, string $authority, int $generation): array {
        return [
            'configuration_sha256s' => [$configuration],
            'docker_root_sha256' => hash('sha256', 'docker-root'),
            'firewall_bindings' => $generation,
            'host_authority_sha256' => $authority,
            'route_count' => 0,
            'storage_bindings' => 0,
        ];
    }

    /** @param callable():array<string,mixed> $operation */
    private function assertRefused(callable $operation): void {
        try {
            $operation();
            self::fail('stale or invalid readiness gate remained current');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('receipt', $error->getMessage());
        }
    }
}
