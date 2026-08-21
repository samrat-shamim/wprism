<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\ControlRefusal;
use Duo\Cloud\ProductionConfig;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/runtime/ProductionConfig.php';

#[CoversNothing]
final class ProductionConfigTest extends TestCase {
    private string $scratch;
    private string $hostPreflightRoot;

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-production-config-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
        $this->hostPreflightRoot = sys_get_temp_dir()
            . '/duo-production-host-preflight-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->hostPreflightRoot, 0700));
        self::assertTrue(chmod($this->hostPreflightRoot, 0700));
        foreach (['repo', 'snapshots', 'state'] as $directory) {
            self::assertTrue(mkdir($this->scratch . '/' . $directory, 0700));
            self::assertTrue(chmod($this->scratch . '/' . $directory, 0700));
        }
    }

    protected function tearDown(): void {
        $this->remove($this->scratch);
        $this->remove($this->hostPreflightRoot);
    }

    public function testLoadsOneSitePinnedProductionConfigurationAndCreatesOnlyDerivedState(): void {
        [$path, $sha] = $this->configuration();
        $config = ProductionConfig::load($path, $sha);

        self::assertSame($sha, $config->sha256());
        self::assertSame(['site_id' => 'site-a', 'tenant_id' => 'tenant-a'], $config->principal());
        self::assertSame(realpath($this->scratch . '/state'), $config->stateRoot());
        self::assertSame(realpath($this->hostPreflightRoot), $config->hostPreflightRoot());
        self::assertSame($this->scratch, $config->authorityConfigRoot());
        $authority = $config->stateDirectory('authority');
        self::assertDirectoryExists($authority);
        self::assertSame(0, fileperms($authority) & 0077);
        self::assertSame('origin-controller-key', $config->controllerKeys()[0]['key_id']);
        self::assertContains(realpath($this->hostPreflightRoot), $config->requiredDurablePaths());
    }

    public function testRefusesChangedConfigurationUnknownFieldsAndCrossSiteKeys(): void {
        [$path, $sha, $document] = $this->configuration();
        file_put_contents($path, CanonicalJson::encode($document + ['unknown' => true]) . "\n");
        $this->expectException(ControlRefusal::class);
        ProductionConfig::load($path, $sha);
    }

    public function testRefusesMultipleSitesBecauseRepositorySourceIsOneSiteAuthority(): void {
        [$path, , $document] = $this->configuration();
        $second = $document['controller_keys'][0];
        $second['key_id'] = 'second-key';
        $second['site_id'] = 'site-b';
        $document['controller_keys'][] = $second;
        [$path, $sha] = $this->writeConfig($document, $path);

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('one tenant and site');
        ProductionConfig::load($path, $sha);
    }

    public function testRefusesGroupReadableSigningSecretEvenWhenDigestMatches(): void {
        [$path, $sha, $document] = $this->configuration();
        $secret = $document['service']['response_signing_key']['path'];
        self::assertTrue(chmod($secret, 0640));

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('approved pinned regular file');
        ProductionConfig::load($path, $sha);
    }

    public function testRootAuthorityJournalsNeedCanonicalIdentityButNotWorkerReadAccess(): void {
        if (DIRECTORY_SEPARATOR !== '/') {
            self::markTestSkipped('Unix directory access modes are required');
        }
        [, , $document] = $this->configuration();
        $firewall = $this->scratch . '/firewall-root-journal';
        $storage = $this->scratch . '/storage-root-journal';
        self::assertTrue(mkdir($firewall, 0700));
        self::assertTrue(mkdir($storage, 0700));
        self::assertTrue(chmod($firewall, 0000));
        self::assertTrue(chmod($storage, 0000));
        $document['host_durable_paths']['firewall_authority_state_root'] = $firewall;
        $document['host_durable_paths']['storage_authority_state_root'] = $storage;
        [$path, $sha] = $this->writeConfig($document);
        try {
            $durable = ProductionConfig::load($path, $sha)->requiredDurablePaths();
            self::assertContains(realpath($firewall), $durable);
            self::assertContains(realpath($storage), $durable);
        } finally {
            chmod($firewall, 0700);
            chmod($storage, 0700);
        }
    }

    public function testFleetInspectionDoesNotCreateOrReadWorkerLocalState(): void {
        [, , $document] = $this->configuration();
        $workerRoot = $this->scratch . '/not-created-worker';
        $document['state_root'] = $workerRoot . '/state';
        $document['runtime']['repository_source'] = $workerRoot . '/repo';
        $document['runtime']['snapshot_object_root'] = $workerRoot . '/snapshots';
        $document['runtime']['image'] = 'localhost:5000/duo/wordpress@sha256:'
            . hash('sha256', 'image-with-port');
        $document['service']['response_signing_key'] = [
            'path' => $workerRoot . '/keys/response',
            'sha256' => hash('sha256', 'absent-response-key'),
        ];
        $document['service']['device_digest_key'] = [
            'path' => $workerRoot . '/keys/device',
            'sha256' => hash('sha256', 'absent-device-key'),
        ];
        $document['controller_keys'][0]['public_key'] = [
            'path' => $workerRoot . '/keys/controller',
            'sha256' => hash('sha256', 'absent-controller-key'),
        ];
        [$path, $sha] = $this->writeConfig($document, $this->scratch . '/fleet-only.json');

        $inspected = ProductionConfig::inspectForFleet($path, $sha);
        self::assertSame($workerRoot, $inspected->workerRoot());
        self::assertSame($sha, $inspected->sha256());
        self::assertDirectoryDoesNotExist($workerRoot);
        try {
            ProductionConfig::load($path, $sha);
            self::fail('full worker assembly accepted absent local state');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('production state root', $error->getMessage());
        }
        self::assertDirectoryDoesNotExist($workerRoot);
    }

    public function testReapLoaderIgnoresOnlyMaterialThatCleanupCannotConsume(): void {
        [$path, , $document] = $this->configuration();
        $credentialHelper = $this->executable('credential-helper');
        $document['runtime']['repository_remote']['credential_helper'] = $credentialHelper['path'];
        $document['runtime']['repository_remote']['credential_helper_sha256'] = $credentialHelper['sha256'];
        [$path, $sha] = $this->writeConfig($document, $path);

        foreach ([
            $document['service']['response_signing_key']['path'],
            $document['service']['device_digest_key']['path'],
            $document['controller_keys'][0]['public_key']['path'],
            $credentialHelper['path'],
        ] as $unrelatedFile) {
            self::assertTrue(unlink($unrelatedFile));
        }
        $this->remove($document['runtime']['snapshot_object_root']);

        $config = ProductionConfig::loadForReap($path, $sha);
        self::assertSame($sha, $config->sha256());
        self::assertSame(realpath($this->scratch . '/repo'), realpath(
            $config->runtime()['repository_source']
        ));
        try {
            ProductionConfig::load($path, $sha);
            self::fail('normal service assembly accepted missing service-only material');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('snapshots', $error->getMessage());
        }
    }

    public function testReapLoaderStillRequiresEveryCleanupArtifactAtItsRetiringPin(): void {
        [$path, $sha, $document] = $this->configuration();
        $engine = $document['runtime']['container_engine']['path'];
        self::assertSame(8, file_put_contents($engine, "changed\n"));
        self::assertTrue(chmod($engine, 0700));

        try {
            ProductionConfig::loadForReap($path, $sha);
            self::fail('cleanup accepted engine bytes that differ from the retiring pin');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('differs from its pin', $error->getMessage());
        }

        self::assertTrue(unlink($engine));
        self::assertTrue(mkdir($engine, 0700));
        try {
            ProductionConfig::loadForReap($path, $sha);
            self::fail('cleanup substituted another path for its retiring engine artifact');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('container engine', $error->getMessage());
        }
    }

    /** @return array{string,string,array<string,mixed>} */
    private function configuration(): array {
        $engine = $this->executable('engine');
        $firewall = $this->executable('firewall');
        $git = $this->executable('git');
        $processLauncher = ['path' => PHP_BINARY, 'sha256' => (string) hash_file('sha256', PHP_BINARY)];
        $route = $this->executable('route');
        $storage = $this->executable('storage');
        $signing = $this->key('response', random_bytes(64));
        $device = $this->key('device', random_bytes(32));
        $public = $this->key('controller-public', random_bytes(32));
        $contract = DUO_REPO_ROOT . '/cloud/deploy/runtime-image-contract.json';
        $seccomp = DUO_REPO_ROOT . '/cloud/deploy/seccomp-profile.json';
        $remoteUrl = 'https://git.example.test/tenant-a/site-a.git';
        $document = [
            'controller_keys' => [[
                'key_id' => 'origin-controller-key',
                'public_key' => $public,
                'site_id' => 'site-a',
                'tenant_id' => 'tenant-a',
            ]],
            'format' => ProductionConfig::FORMAT,
            'host_preflight_root' => $this->hostPreflightRoot,
            'host_durable_paths' => [
                'authority_config_root' => $this->scratch,
                'control_proxy_config_root' => $this->scratch,
                'control_proxy_data_root' => $this->scratch,
                'firewall_authority_state_root' => $this->scratch . '/state',
                'route_authority_state_root' => $this->scratch . '/state',
                'route_proxy_config_root' => $this->scratch,
                'route_proxy_data_root' => $this->scratch,
                'storage_authority_state_root' => $this->scratch . '/state',
            ],
            'runtime' => [
                'container_engine' => $engine,
                'firewall_authority' => $firewall,
                'git' => $git,
                'image' => 'registry.example.test/duo/wordpress@sha256:' . hash('sha256', 'image'),
                'memory_bytes' => 536870912,
                'nano_cpus' => 500000000,
                'pids_limit' => 128,
                'platform_fingerprint_sha256' => hash('sha256', 'platform'),
                'preview_domain' => 'preview.example.test',
                'process_launcher' => $processLauncher,
                'process_timeout_seconds' => 30,
                'repository_remote' => [
                    'allowed_ref_prefix' => 'refs/heads/duo-preview/',
                    'credential_helper' => $git['path'],
                    'credential_helper_sha256' => $git['sha256'],
                    'name' => 'duo-cloud',
                    'url' => $remoteUrl,
                    'url_sha256' => hash(
                        'sha256',
                        "duo-cloud-repository-remote-url/v1\0$remoteUrl"
                    ),
                ],
                'repository_source' => $this->scratch . '/repo',
                'review_receipt_sha256' => hash('sha256', 'review'),
                'route_authority' => $route,
                'runtime_contract_file' => $contract,
                'runtime_contract_sha256' => hash_file('sha256', $contract),
                'seccomp_profile_file' => $seccomp,
                'seccomp_profile_sha256' => hash_file('sha256', $seccomp),
                'snapshot_object_root' => $this->scratch . '/snapshots',
                'storage_authority' => $storage,
                'workload_repository_path' => '/srv/duo/repository',
            ],
            'service' => [
                'device_digest_key' => $device,
                'response_key_id' => 'service-key-v1',
                'response_signing_key' => $signing,
            ],
            'state_root' => $this->scratch . '/state',
        ];
        [$path, $sha] = $this->writeConfig($document);
        return [$path, $sha, $document];
    }

    /** @param array<string,mixed> $document @return array{string,string} */
    private function writeConfig(array $document, ?string $path = null): array {
        $path ??= $this->scratch . '/service.json';
        $bytes = CanonicalJson::encode($document) . "\n";
        file_put_contents($path, $bytes);
        self::assertTrue(chmod($path, 0600));
        return [$path, hash('sha256', $bytes)];
    }

    /** @return array{path:string,sha256:string} */
    private function executable(string $name): array {
        $path = $this->scratch . '/' . $name;
        $bytes = "#!/bin/sh\nexit 0\n";
        file_put_contents($path, $bytes);
        self::assertTrue(chmod($path, 0700));
        return ['path' => $path, 'sha256' => hash('sha256', $bytes)];
    }

    /** @return array{path:string,sha256:string} */
    private function key(string $name, string $bytes): array {
        $path = $this->scratch . '/' . $name . '.key';
        $encoded = base64_encode($bytes) . "\n";
        file_put_contents($path, $encoded);
        self::assertTrue(chmod($path, 0600));
        return ['path' => $path, 'sha256' => hash('sha256', $encoded)];
    }

    private function remove(string $path): void {
        if (!is_dir($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                $this->remove($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
