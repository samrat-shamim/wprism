<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\ControlRefusal;
use Duo\Cloud\HostAuthorityBusy;
use Duo\Cloud\ProductionConfig;
use Duo\Cloud\ProductionDeploymentProofs;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/runtime/ProductionDeploymentProofs.php';

#[CoversNothing]
final class ProductionDeploymentProofsTest extends TestCase {
    private string $scratch;
    private string $hostRoot;
    /** @var array<string,string> */
    private array $installedConfigurationSha256s = [];
    private int $now = 1_000_000;
    private string $boot = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-deployment-proofs-'
            . bin2hex(random_bytes(8));
        $this->hostRoot = $this->scratch . '/host';
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(mkdir($this->hostRoot, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
        self::assertTrue(chmod($this->hostRoot, 0700));
        $this->scratch = (string) realpath($this->scratch);
        $this->hostRoot = (string) realpath($this->hostRoot);
        $this->installHostAuthorityConfigurationFixture();
    }

    protected function tearDown(): void {
        $this->remove($this->scratch);
    }

    public function testTwoWorkerAndRotationProofsNeverCrossAdmitSharedHostState(): void {
        [$configA, $runtimeA] = $this->config('a');
        [$configB, $runtimeB] = $this->config('b');
        $proofsA = $this->proofs($configA);
        $proofsB = $this->proofs($configB);
        $proofsA->publishRuntimeImage($runtimeA);
        $proofsB->publishRuntimeImage($runtimeB);
        $proofsA->beginLinuxHostVerification();
        $proofsA->publishLinuxHost($this->linuxHostProof($configA));
        $proofsB->beginLinuxHostVerification();
        $proofsB->publishLinuxHost($this->linuxHostProof($configB));
        $this->publishInstalled([$configA, $configB]);

        self::assertNotSame($proofsA->runtimeImagePath(), $proofsB->runtimeImagePath());
        self::assertNotSame($proofsA->linuxHostPath(), $proofsB->linuxHostPath());
        self::assertNotSame($proofsA->installedFpmPath(), $proofsB->installedFpmPath());
        self::assertSame(
            $proofsA->installedFpmControlPath(),
            $proofsB->installedFpmControlPath()
        );
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            $proofsA->assertCurrent()['identity_sha256']
        );
        self::assertMatchesRegularExpression(
            '/\A[a-f0-9]{64}\z/D',
            $proofsB->assertCurrent()['identity_sha256']
        );

        ProductionDeploymentProofs::invalidateInstalledFpmWorker(
            $this->hostRoot,
            $configB->sha256(),
            function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid()
        );
        self::assertSame(
            ProductionDeploymentProofs::FORMAT,
            $proofsA->assertCurrent()['format']
        );
        $this->assertRefused(static fn (): array => $proofsB->assertCurrent(), 'proof file');
        $this->publishInstalled([$configA, $configB]);

        [$configC, $runtimeC] = $this->config('c');
        $proofsC = $this->proofs($configC);
        $proofsC->publishRuntimeImage($runtimeC);
        $proofsC->beginLinuxHostVerification();
        $proofsC->publishLinuxHost($this->linuxHostProof($configC));
        $this->publishInstalled([$configB, $configC]);

        $this->assertRefused(static fn (): array => $proofsA->assertCurrent(), 'proof file');
        self::assertSame(
            ProductionDeploymentProofs::FORMAT,
            $proofsB->assertCurrent()['format']
        );
        self::assertSame(
            ProductionDeploymentProofs::FORMAT,
            $proofsC->assertCurrent()['format']
        );
        self::assertFileExists($proofsA->linuxHostPath());
    }

    public function testBootExpiryTamperAndCrashedRenewalAllRefuseReadiness(): void {
        [$config, $runtime] = $this->config('expiry');
        $proofs = $this->proofs($config);
        $proofs->publishRuntimeImage($runtime);
        $hostProof = $this->linuxHostProof($config);
        $this->assertRefused(
            static function () use ($proofs, $hostProof): array {
                $proofs->publishLinuxHost($hostProof);
                return [];
            },
            'proof file'
        );
        $firstIntent = $proofs->beginLinuxHostVerification();
        $this->now += 300;
        self::assertSame($firstIntent, $proofs->beginLinuxHostVerification());
        $this->now -= 300;
        $this->now += ProductionDeploymentProofs::LINUX_HOST_VERIFICATION_SECONDS;
        $this->assertRefused(
            static function () use ($proofs, $hostProof): array {
                $proofs->publishLinuxHost($hostProof);
                return [];
            },
            'did not terminate'
        );
        $this->now -= ProductionDeploymentProofs::LINUX_HOST_VERIFICATION_SECONDS;
        $misbound = $this->linuxHostProof($config);
        $misbound['image_id'] = 'sha256:' . hash('sha256', 'different-local-image');
        $this->assertRefused(
            static function () use ($proofs, $misbound): array {
                $proofs->publishLinuxHost($misbound);
                return [];
            },
            'exact production artifacts'
        );
        $proofs->publishLinuxHost($this->linuxHostProof($config));
        $this->publishInstalled([$config]);
        self::assertSame(ProductionDeploymentProofs::FORMAT, $proofs->assertCurrent()['format']);

        $originalBoot = $this->boot;
        $this->boot = 'ffffffff-1111-4222-8333-444444444444';
        $this->assertRefused(static fn (): array => $proofs->assertCurrent(), 'receipt');
        $this->boot = $originalBoot;

        $this->now += ProductionDeploymentProofs::INSTALLED_FPM_TTL_SECONDS;
        $this->assertRefused(static fn (): array => $proofs->assertCurrent(), 'installed FPM');
        $this->now -= ProductionDeploymentProofs::INSTALLED_FPM_TTL_SECONDS;
        $this->publishInstalled([$config]);

        $receiptPath = $proofs->installedFpmPath();
        $receipt = CanonicalJson::decodeObject((string) file_get_contents($receiptPath));
        $receipt['unknown'] = true;
        self::assertNotFalse(file_put_contents(
            $receiptPath,
            CanonicalJson::encode($receipt) . "\n"
        ));
        self::assertTrue(chmod($receiptPath, 0600));
        $this->assertRefused(static fn (): array => $proofs->assertCurrent(), 'missing or unknown');

        $this->publishInstalled([$config]);
        $proofs->beginLinuxHostVerification();
        self::assertSame(ProductionDeploymentProofs::FORMAT, $proofs->assertCurrent()['format']);
        $this->now += ProductionDeploymentProofs::LINUX_HOST_VERIFICATION_SECONDS;
        $this->assertRefused(static fn (): array => $proofs->assertCurrent(), 'did not terminate');
        $this->now -= ProductionDeploymentProofs::LINUX_HOST_VERIFICATION_SECONDS;
        $proofs->failLinuxHostVerification();
        $this->assertRefused(static fn (): array => $proofs->assertCurrent(), 'verification failed');
        self::assertFileDoesNotExist($proofs->linuxHostPath());

        $installedWorkerPath = $proofs->installedFpmPath();
        ProductionDeploymentProofs::invalidateInstalledFpm(
            $this->hostRoot,
            function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid()
        );
        self::assertFileDoesNotExist($proofs->installedFpmControlPath());
        self::assertFileExists($installedWorkerPath);
        $this->assertRefused(static fn (): array => $proofs->assertCurrent(), 'verification failed');

        ProductionDeploymentProofs::invalidateInstalledFpmWorker(
            $this->hostRoot,
            $config->sha256(),
            function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid()
        );
        self::assertFileDoesNotExist($installedWorkerPath);
    }

    public function testPublisherRecoversOneDeterministicCrashResidueAndRefusesContention(): void {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('pcntl is required for the publication crash checkpoint');
        }
        [$config, $runtime] = $this->config('crash');
        $proofs = $this->proofs($config);
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            putenv('DUO_TEST_DEPLOYMENT_PROOF_KILL_PHASE=temporary-synchronized');
            $proofs->publishRuntimeImage($runtime);
            exit(99);
        }
        $status = 0;
        self::assertSame($pid, pcntl_waitpid($pid, $status));
        self::assertTrue(pcntl_wifsignaled($status));
        self::assertSame(SIGKILL, pcntl_wtermsig($status));
        self::assertFileExists($proofs->runtimeImagePath() . '.tmp');

        putenv('DUO_TEST_DEPLOYMENT_PROOF_KILL_PHASE');
        $proofs->publishRuntimeImage($runtime);
        self::assertFileDoesNotExist($proofs->runtimeImagePath() . '.tmp');
        self::assertFileExists($proofs->runtimeImagePath());

        $lock = fopen($this->hostRoot . '/runtime-image-proof-publication.lock', 'c+b');
        self::assertIsResource($lock);
        self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
        try {
            $this->assertRefused(
                static function () use ($proofs, $runtime): array {
                    $proofs->publishRuntimeImage($runtime);
                    return [];
                },
                'lock is busy'
            );
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::assertFileExists($proofs->runtimeImagePath());
    }

    public function testStrictReceiptRejectsFailedNestedBoundaryEvidence(): void {
        [$config, $runtime] = $this->config('nested');
        $proofs = $this->proofs($config);
        $proofs->publishRuntimeImage($runtime);
        $proofs->beginLinuxHostVerification();
        $valid = $this->linuxHostProof($config);
        self::assertSame([
            'container_engine_sha256',
            'firewall_authority_configuration_sha256',
            'firewall_authority_sha256',
            'firewall_client_configuration_sha256',
            'php_closure_sha256',
            'process_launcher_sha256',
            'seccomp_profile_sha256',
            'storage_authority_configuration_sha256',
            'storage_authority_sha256',
            'storage_client_configuration_sha256',
            'verifier_sha256',
        ], array_keys($valid['artifact_sha256s']));
        $mutations = [
            static function (array $proof): array {
                $proof['apparmor'] = 'missing';
                return $proof;
            },
            static function (array $proof): array {
                $proof['cgroup']['timeout_descendant'] = 'escaped';
                return $proof;
            },
            static function (array $proof): array {
                $proof['firewall']['dns_udp'] = 'allowed';
                return $proof;
            },
            static function (array $proof): array {
                $proof['storage']['hard_bytes']++;
                return $proof;
            },
            static function (array $proof): array {
                $proof['synthetic_rotation_configuration_sha256'] = hash('sha256', 'unbound');
                return $proof;
            },
        ];
        foreach ($mutations as $mutate) {
            $candidate = $mutate($valid);
            $this->assertRefused(
                static function () use ($proofs, $candidate): array {
                    $proofs->publishLinuxHost($candidate);
                    return [];
                },
                'Linux host boundary proof'
            );
        }
    }

    public function testStrictPublicationRefusesAnArtifactRenamedAfterProofCapture(): void {
        [$config, $runtime] = $this->config('artifact-rename');
        $proofs = $this->proofs($config);
        $proofs->publishRuntimeImage($runtime);
        $proofs->beginLinuxHostVerification();
        $proof = $this->linuxHostProof($config);
        $engine = $config->runtime()['container_engine']['path'];
        $replacement = $engine . '.replacement';
        $bytes = "changed engine bytes\n";
        self::assertSame(strlen($bytes), file_put_contents($replacement, $bytes));
        self::assertTrue(chmod($replacement, 0700));
        self::assertTrue(rename($replacement, $engine));

        $this->assertRefused(
            static function () use ($proofs, $proof): array {
                $proofs->publishLinuxHost($proof);
                return [];
            },
            'differs from its configuration pin'
        );
        self::assertFileDoesNotExist($proofs->linuxHostPath());
    }

    public function testStrictPublisherRollsBackReceiptAfterAtomicConfigSwap(): void {
        [$config, $runtime] = $this->config('authority-config-swap');
        $clockCalls = 0;
        $publicationLockHeld = false;
        $receiptPublished = false;
        $receiptPath = $this->hostRoot . '/linux-host-boundaries-proof.'
            . $config->sha256() . '.json';
        $clock = function () use (
            &$clockCalls,
            &$publicationLockHeld,
            &$receiptPublished,
            $receiptPath
        ): int {
            $clockCalls++;
            if ($clockCalls === 4) {
                self::assertFileExists($receiptPath);
                $receiptPublished = true;
                $contender = fopen(
                    $this->hostRoot . '/linux-host-boundaries-publication.lock',
                    'r+b'
                );
                self::assertIsResource($contender);
                $publicationLockHeld = !flock($contender, LOCK_EX | LOCK_NB);
                if (!$publicationLockHeld) {
                    flock($contender, LOCK_UN);
                }
                fclose($contender);
                $path = $this->scratch . '/firewall-client.json';
                $replacement = $path . '.replacement';
                self::assertTrue(copy($path, $replacement));
                self::assertTrue(chmod($replacement, 0600));
                self::assertTrue(rename($replacement, $path));
            }
            return $this->now;
        };
        $proofs = new ProductionDeploymentProofs(
            $config,
            $clock,
            fn (): string => $this->boot,
            DUO_REPO_ROOT . '/cloud/deploy'
        );
        $proofs->publishRuntimeImage($runtime);
        $proofs->beginLinuxHostVerification();

        $this->assertRefused(
            fn (): array => $proofs->publishLinuxHost($this->linuxHostProof($config)),
            'artifacts changed during publication'
        );

        self::assertSame(4, $clockCalls);
        self::assertTrue($receiptPublished);
        self::assertTrue($publicationLockHeld);
        self::assertFileDoesNotExist($proofs->linuxHostPath());
    }

    public function testReadinessRejectsInstalledAuthorityConfigurationRotation(): void {
        [$config, $runtime] = $this->config('authority-config-readiness');
        $proofs = $this->proofs($config);
        $proofs->publishRuntimeImage($runtime);
        $proofs->beginLinuxHostVerification();
        $proofs->publishLinuxHost($this->linuxHostProof($config));
        $this->publishInstalled([$config]);
        self::assertSame(ProductionDeploymentProofs::FORMAT, $proofs->assertCurrent()['format']);

        $path = $this->scratch . '/firewall-client.json';
        $document = CanonicalJson::decodeObject((string) file_get_contents($path));
        $document['process_timeout_seconds'] = 59;
        $bytes = CanonicalJson::encode($document) . "\n";
        $replacement = $path . '.replacement';
        self::assertSame(strlen($bytes), file_put_contents($replacement, $bytes));
        self::assertTrue(chmod($replacement, 0600));
        self::assertTrue(rename($replacement, $path));
        $pin = $this->scratch . '/firewall-client.sha256';
        $pinReplacement = $pin . '.replacement';
        self::assertSame(65, file_put_contents($pinReplacement, hash('sha256', $bytes) . "\n"));
        self::assertTrue(chmod($pinReplacement, 0600));
        self::assertTrue(rename($pinReplacement, $pin));

        $this->assertRefused(static fn (): array => $proofs->assertCurrent(), 'exact production');
    }

    public function testReadinessRejectsAtomicInstalledConfigurationSwapAfterCapture(): void {
        [$config, $runtime] = $this->config('authority-config-readiness-swap');
        $proofs = $this->proofs($config);
        $proofs->publishRuntimeImage($runtime);
        $proofs->beginLinuxHostVerification();
        $proofs->publishLinuxHost($this->linuxHostProof($config));
        $this->publishInstalled([$config]);

        $clockCalls = 0;
        $readiness = new ProductionDeploymentProofs(
            $config,
            function () use (&$clockCalls, $proofs): int {
                $clockCalls++;
                if ($clockCalls === 1) {
                    self::assertFileExists($proofs->linuxHostPath());
                    foreach (['firewall-client.json' => 0600, 'firewall-client.sha256' => 0600] as $name => $mode) {
                        $path = $this->scratch . '/' . $name;
                        $replacement = $path . '.replacement';
                        self::assertTrue(copy($path, $replacement));
                        self::assertTrue(chmod($replacement, $mode));
                        self::assertTrue(rename($replacement, $path));
                    }
                }
                return $this->now;
            },
            fn (): string => $this->boot,
            DUO_REPO_ROOT . '/cloud/deploy'
        );

        $this->assertRefused(
            static fn (): array => $readiness->assertCurrent(),
            'changed during verification'
        );
        self::assertSame(1, $clockCalls);
    }

    public function testReadinessRejectsAtomicInstalledArtifactSwapAfterCapture(): void {
        [$config, $runtime] = $this->config('installed-artifact-readiness-swap');
        $deployRoot = $this->installedArtifactFixture();
        $proofs = new ProductionDeploymentProofs(
            $config,
            fn (): int => $this->now,
            fn (): string => $this->boot,
            $deployRoot
        );
        $proofs->publishRuntimeImage($runtime);
        $proofs->beginLinuxHostVerification();
        $proofs->publishLinuxHost($this->linuxHostProof($config, $deployRoot));
        $this->publishInstalled([$config], 99, $deployRoot);

        $clockCalls = 0;
        $readiness = new ProductionDeploymentProofs(
            $config,
            function () use (&$clockCalls, $deployRoot): int {
                $clockCalls++;
                if ($clockCalls === 1) {
                    $path = $deployRoot . '/php-fpm.ini';
                    $replacement = $path . '.replacement';
                    self::assertTrue(copy($path, $replacement));
                    self::assertTrue(chmod($replacement, 0600));
                    self::assertTrue(rename($replacement, $path));
                }
                return $this->now;
            },
            fn (): string => $this->boot,
            $deployRoot
        );

        $this->assertRefused(
            static fn (): array => $readiness->assertCurrent(),
            'installed FPM proof artifact snapshot changed before commit'
        );
        self::assertSame(1, $clockCalls);
    }

    public function testStrictPublisherRollsBackReceiptAfterAtomicRuntimeArtifactSwap(): void {
        [$config, $runtime] = $this->config('runtime-artifact-swap');
        $clockCalls = 0;
        $receiptPublished = false;
        $receiptPath = $this->hostRoot . '/linux-host-boundaries-proof.'
            . $config->sha256() . '.json';
        $clock = function () use (
            &$clockCalls,
            &$receiptPublished,
            $config,
            $receiptPath
        ): int {
            $clockCalls++;
            if ($clockCalls === 4) {
                self::assertFileExists($receiptPath);
                $receiptPublished = true;
                $path = $config->runtime()['container_engine']['path'];
                $replacement = $path . '.replacement';
                self::assertTrue(copy($path, $replacement));
                self::assertTrue(chmod($replacement, 0700));
                self::assertTrue(rename($replacement, $path));
            }
            return $this->now;
        };
        $proofs = new ProductionDeploymentProofs(
            $config,
            $clock,
            fn (): string => $this->boot,
            DUO_REPO_ROOT . '/cloud/deploy'
        );
        $proofs->publishRuntimeImage($runtime);
        $proofs->beginLinuxHostVerification();

        $this->assertRefused(
            fn (): array => $proofs->publishLinuxHost($this->linuxHostProof($config)),
            'artifacts changed during publication'
        );

        self::assertSame(4, $clockCalls);
        self::assertTrue($receiptPublished);
        self::assertFileDoesNotExist($proofs->linuxHostPath());
    }

    public function testInstalledFleetCommitKeepsOldWorkersCurrentAcrossMidPublicationDeath(): void {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('pcntl is required for the publication crash checkpoint');
        }
        [$configA, $runtimeA] = $this->config('transaction-a');
        [$configB, $runtimeB] = $this->config('transaction-b');
        $proofsA = $this->proofs($configA);
        $proofsB = $this->proofs($configB);
        foreach ([[$proofsA, $configA, $runtimeA], [$proofsB, $configB, $runtimeB]] as $row) {
            $row[0]->publishRuntimeImage($row[2]);
            $row[0]->beginLinuxHostVerification();
            $row[0]->publishLinuxHost($this->linuxHostProof($row[1]));
        }
        $this->publishInstalled([$configA, $configB], 99);
        $oldControl = (string) file_get_contents($proofsA->installedFpmControlPath());

        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            putenv('DUO_TEST_INSTALLED_FPM_KILL_AFTER_WORKER=1');
            $this->publishInstalled([$configA, $configB], 199);
            exit(99);
        }
        $status = 0;
        self::assertSame($pid, pcntl_waitpid($pid, $status));
        self::assertTrue(pcntl_wifsignaled($status));
        self::assertSame(SIGKILL, pcntl_wtermsig($status));
        self::assertSame($oldControl, file_get_contents($proofsA->installedFpmControlPath()));
        self::assertSame(ProductionDeploymentProofs::FORMAT, $proofsA->assertCurrent()['format']);
        self::assertSame(ProductionDeploymentProofs::FORMAT, $proofsB->assertCurrent()['format']);

        putenv('DUO_TEST_INSTALLED_FPM_KILL_AFTER_WORKER');
        $this->publishInstalled([$configA, $configB], 199);
        self::assertNotSame($oldControl, file_get_contents($proofsA->installedFpmControlPath()));
        self::assertSame(ProductionDeploymentProofs::FORMAT, $proofsA->assertCurrent()['format']);
        self::assertSame(ProductionDeploymentProofs::FORMAT, $proofsB->assertCurrent()['format']);
    }

    public function testInstalledPublisherRevokesNewGenerationWhenArtifactChangesAtCommitEnd(): void {
        [$config] = $this->config('installed-end-binding');
        $deployRoot = $this->installedArtifactFixture();
        $clockCalls = 0;
        $clock = function () use (&$clockCalls, $deployRoot): int {
            $clockCalls++;
            if ($clockCalls === 3) {
                $path = $deployRoot . '/php-fpm.ini';
                $replacement = $path . '.replacement';
                $bytes = "changed installed FPM artifact\n";
                self::assertSame(strlen($bytes), file_put_contents($replacement, $bytes));
                self::assertTrue(chmod($replacement, 0600));
                self::assertTrue(rename($replacement, $path));
            }
            return $this->now;
        };

        try {
            $this->publishInstalled([$config], 299, $deployRoot, $clock);
            self::fail('changed installed FPM artifacts retained a current receipt');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString(
                'artifacts changed during publication',
                $error->getMessage()
            );
        }

        self::assertSame(3, $clockCalls);
        self::assertFileDoesNotExist(
            $this->hostRoot . '/' . ProductionDeploymentProofs::INSTALLED_FPM_CONTROL_FILE
        );
        self::assertSame(
            [],
            glob(
                $this->hostRoot . '/installed-fpm-ingress-proof.'
                    . $config->sha256() . '.*.json'
            ) ?: []
        );
    }

    public function testFailedAndExpiredStrictAttemptsCanRestartWithoutRevivingOldEvidence(): void {
        [$config, $runtime] = $this->config('attempt-restart');
        $proofs = $this->proofs($config);
        $proofs->publishRuntimeImage($runtime);
        $first = $proofs->beginLinuxHostVerification();
        $this->now += ProductionDeploymentProofs::LINUX_HOST_VERIFICATION_SECONDS;
        $second = $proofs->beginLinuxHostVerification();
        self::assertSame($this->now, $second['started_at']);
        self::assertNotSame($first, $second);
        self::assertFileDoesNotExist($proofs->linuxHostPath());
        $proofs->publishLinuxHost($this->linuxHostProof($config));
        self::assertFileExists($proofs->linuxHostPath());

        $proofs->beginLinuxHostVerification();
        $proofs->failLinuxHostVerification();
        self::assertFileDoesNotExist($proofs->linuxHostPath());
        $this->now++;
        $third = $proofs->beginLinuxHostVerification();
        self::assertSame($this->now, $third['started_at']);
        $proofs->publishLinuxHost($this->linuxHostProof($config));
        self::assertFileExists($proofs->linuxHostPath());
    }

    public function testFleetNamespaceReconciliationBoundsOneHundredRotationsAndRetainsTwoPins(): void {
        $configurationKeep = [
            hash('sha256', 'configuration-current'),
            hash('sha256', 'configuration-retiring'),
        ];
        $runtimeKeep = [
            hash('sha256', 'runtime-current'),
            hash('sha256', 'runtime-retiring'),
        ];
        sort($configurationKeep, SORT_STRING);
        sort($runtimeKeep, SORT_STRING);
        foreach ($runtimeKeep as $sha256) {
            $this->namespaceNode("runtime-image-proof.$sha256.json", "runtime\n");
        }
        foreach ($configurationKeep as $sha256) {
            $this->namespaceNode("linux-host-boundaries-proof.$sha256.json", "linux\n");
            $this->namespaceNode(
                "linux-host-boundaries-proof.$sha256.json.intent.json",
                "intent\n"
            );
        }

        for ($rotation = 0; $rotation < 100; $rotation++) {
            $runtime = hash('sha256', "obsolete-runtime-$rotation");
            $configuration = hash('sha256', "obsolete-configuration-$rotation");
            foreach ([
                "runtime-image-proof.$runtime.json" => "runtime\n",
                "runtime-image-proof.$runtime.json.tmp" => 'partial',
                "runtime-image-proof.$runtime.json.publish.lock" => '',
                "linux-host-boundaries-proof.$configuration.json" => "linux\n",
                "linux-host-boundaries-proof.$configuration.json.tmp" => 'partial',
                "linux-host-boundaries-proof.$configuration.json.publish.lock" => '',
                "linux-host-boundaries-proof.$configuration.json.intent.json" => "intent\n",
                "linux-host-boundaries-proof.$configuration.json.intent.json.tmp" => 'partial',
                "linux-host-boundaries-proof.$configuration.json.intent.json.publish.lock" => '',
            ] as $name => $bytes) {
                $this->namespaceNode($name, $bytes);
            }
        }

        $this->reconcileNamespace($configurationKeep, $runtimeKeep);

        $expected = [
            'linux-host-boundaries-publication.lock',
            'runtime-image-proof-publication.lock',
        ];
        foreach ($configurationKeep as $sha256) {
            $expected[] = "linux-host-boundaries-proof.$sha256.json";
            $expected[] = "linux-host-boundaries-proof.$sha256.json.intent.json";
        }
        foreach ($runtimeKeep as $sha256) {
            $expected[] = "runtime-image-proof.$sha256.json";
        }
        sort($expected, SORT_STRING);
        $actual = array_values(array_filter(
            scandir($this->hostRoot) ?: [],
            static fn (string $entry): bool => $entry !== '.' && $entry !== '..'
        ));
        sort($actual, SORT_STRING);
        self::assertSame($expected, $actual);
    }

    public function testEveryFixedOrLegacyHeldLockIsTransientWithZeroDeletion(): void {
        $configurationKeep = [hash('sha256', 'configuration-keep')];
        $runtimeKeep = [hash('sha256', 'runtime-keep')];
        $executionLock = $this->executionLockPath();
        $runtimeLock = $this->hostRoot . '/runtime-image-proof-publication.lock';
        $linuxLock = $this->hostRoot . '/linux-host-boundaries-publication.lock';
        $cases = [
            'execution' => $executionLock,
            'runtime' => $runtimeLock,
            'linux' => $linuxLock,
            'legacy' => $this->hostRoot . '/runtime-image-proof.'
                . hash('sha256', 'legacy-held') . '.json.publish.lock',
        ];
        foreach ($cases as $case => $lockPath) {
            $obsolete = $this->namespaceNode(
                'runtime-image-proof.' . hash('sha256', "obsolete-$case") . '.json',
                "obsolete\n"
            );
            if (!file_exists($lockPath)) {
                self::assertSame(0, file_put_contents($lockPath, ''));
                self::assertTrue(chmod($lockPath, 0600));
            }
            $lock = fopen($lockPath, 'r+b');
            self::assertIsResource($lock);
            self::assertTrue(flock($lock, LOCK_EX | LOCK_NB));
            try {
                try {
                    $this->reconcileNamespace($configurationKeep, $runtimeKeep);
                    self::fail("held $case lock authorized namespace deletion");
                } catch (HostAuthorityBusy $error) {
                    self::assertStringContainsString('busy', $error->getMessage());
                }
                self::assertFileExists($obsolete);
            } finally {
                flock($lock, LOCK_UN);
                fclose($lock);
            }
            self::assertTrue(unlink($obsolete));
            if ($case === 'legacy') {
                self::assertTrue(unlink($lockPath));
            }
        }
    }

    public function testUnknownAndHardlinkedManagedNodesRefuseBeforeAnyDeletion(): void {
        $configurationKeep = [hash('sha256', 'configuration-keep')];
        $runtimeKeep = [hash('sha256', 'runtime-keep')];
        $obsolete = $this->namespaceNode(
            'runtime-image-proof.' . hash('sha256', 'obsolete-safe-node') . '.json',
            "obsolete\n"
        );
        $unknown = $this->namespaceNode('runtime-image-proof.not-a-digest.json', "unknown\n");
        $this->assertRefused(
            fn (): array => $this->reconcileAsArray($configurationKeep, $runtimeKeep),
            'unknown managed name'
        );
        self::assertFileExists($obsolete);
        self::assertFileDoesNotExist($this->executionLockPath());
        self::assertTrue(unlink($unknown));

        $hardlink = $this->scratch . '/managed-hardlink';
        self::assertTrue(link($obsolete, $hardlink));
        $this->assertRefused(
            fn (): array => $this->reconcileAsArray($configurationKeep, $runtimeKeep),
            'private single-link file'
        );
        self::assertFileExists($obsolete);
        self::assertFileDoesNotExist($this->executionLockPath());
        self::assertTrue(unlink($hardlink));
    }

    public function testKilledReconciliationReleasesLocksAndNextPassConverges(): void {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('pcntl is required for reconciliation crash evidence');
        }
        $configurationKeep = [hash('sha256', 'configuration-keep')];
        $runtimeKeep = [hash('sha256', 'runtime-keep')];
        $obsolete = [];
        for ($index = 0; $index < 5; $index++) {
            $obsolete[] = $this->namespaceNode(
                'runtime-image-proof.' . hash('sha256', "crash-$index") . '.json',
                "obsolete\n"
            );
        }
        $this->executionLockPath();
        $pid = pcntl_fork();
        self::assertGreaterThanOrEqual(0, $pid);
        if ($pid === 0) {
            putenv('DUO_TEST_DEPLOYMENT_PROOF_RECONCILE_KILL_AFTER=2');
            $this->reconcileNamespace($configurationKeep, $runtimeKeep);
            exit(99);
        }
        self::assertSame($pid, pcntl_waitpid($pid, $status));
        self::assertTrue(pcntl_wifsignaled($status));
        self::assertSame(SIGKILL, pcntl_wtermsig($status));
        $remaining = array_values(array_filter(
            $obsolete,
            static fn (string $path): bool => file_exists($path)
        ));
        self::assertNotSame([], $remaining);
        self::assertLessThan(count($obsolete), count($remaining));

        $this->reconcileNamespace($configurationKeep, $runtimeKeep);
        foreach ($obsolete as $path) {
            self::assertFileDoesNotExist($path);
        }
    }

    private function namespaceNode(string $name, string $bytes): string {
        $path = $this->hostRoot . '/' . $name;
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        self::assertTrue(chmod($path, 0600));
        return $path;
    }

    private function executionLockPath(): string {
        $directory = $this->scratch . '/execution-lock';
        if (!is_dir($directory)) {
            self::assertTrue(mkdir($directory, 0700));
            self::assertTrue(chmod($directory, 0700));
        }
        return $directory . '/authority.lock';
    }

    /** @param list<string> $configurationKeep @param list<string> $runtimeKeep */
    private function reconcileNamespace(array $configurationKeep, array $runtimeKeep): void {
        $hostStat = lstat($this->hostRoot);
        self::assertIsArray($hostStat);
        $method = new \ReflectionMethod(
            ProductionDeploymentProofs::class,
            'reconcileFleetNamespaceAt'
        );
        $method->invoke(
            null,
            $this->hostRoot,
            $configurationKeep,
            $runtimeKeep,
            (int) $hostStat['uid'],
            (int) $hostStat['gid'],
            $this->executionLockPath(),
            $this->hostRoot . '/runtime-image-proof-publication.lock',
            $this->hostRoot . '/linux-host-boundaries-publication.lock'
        );
    }

    /**
     * @param list<string> $configurationKeep
     * @param list<string> $runtimeKeep
     * @return array<string,mixed>
     */
    private function reconcileAsArray(array $configurationKeep, array $runtimeKeep): array {
        $this->reconcileNamespace($configurationKeep, $runtimeKeep);
        return [];
    }

    /** @return array{ProductionConfig,array<string,mixed>} */
    private function config(string $id): array {
        $image = 'registry.example.test/duo/wordpress@sha256:' . hash('sha256', "image-$id");
        $seccomp = hash('sha256', "seccomp-$id");
        $runtimeProof = $this->runtimeImageProof($image, $seccomp);
        $review = $runtimeProof['proof_receipt_sha256'];
        $workerRoot = $this->scratch . "/workers/$id";
        $path = $this->scratch . "/$id.json";
        $artifactRoot = $this->scratch . '/artifacts';
        if (!is_dir($artifactRoot)) {
            self::assertTrue(mkdir($artifactRoot, 0700));
        }
        $descriptor = static function (string $name) use ($artifactRoot, $id): array {
            $artifactPath = "$artifactRoot/$id-$name";
            $bytes = "$name-$id\n";
            self::assertSame(strlen($bytes), file_put_contents($artifactPath, $bytes));
            self::assertTrue(chmod($artifactPath, 0700));
            return ['path' => $artifactPath, 'sha256' => hash('sha256', $bytes)];
        };
        $seccompPath = "$artifactRoot/$id-seccomp.json";
        $seccompBytes = "seccomp-$id";
        self::assertSame(strlen($seccompBytes), file_put_contents($seccompPath, $seccompBytes));
        self::assertTrue(chmod($seccompPath, 0600));
        $remote = "https://git.example.test/tenant-$id/site-$id.git";
        $document = [
            'controller_keys' => [[
                'key_id' => "key-$id",
                'public_key' => [
                    'path' => "/var/lib/duo-cloud/config/$id-public.key",
                    'sha256' => hash('sha256', "public-$id"),
                ],
                'site_id' => "site-$id",
                'tenant_id' => "tenant-$id",
            ]],
            'format' => ProductionConfig::FORMAT,
            'host_durable_paths' => [
                'authority_config_root' => $this->scratch,
                'control_proxy_config_root' => $this->scratch,
                'control_proxy_data_root' => $this->scratch,
                'firewall_authority_state_root' => $this->scratch,
                'route_authority_state_root' => $this->scratch,
                'route_proxy_config_root' => $this->scratch,
                'route_proxy_data_root' => $this->scratch,
                'storage_authority_state_root' => $this->scratch,
            ],
            'host_preflight_root' => $this->hostRoot,
            'runtime' => [
                'container_engine' => $descriptor('engine'),
                'firewall_authority' => $descriptor('firewall'),
                'git' => $descriptor('git'),
                'image' => $image,
                'memory_bytes' => 536870912,
                'nano_cpus' => 500000000,
                'pids_limit' => 128,
                'platform_fingerprint_sha256' => hash('sha256', "platform-$id"),
                'preview_domain' => "preview-$id.example.test",
                'process_launcher' => $descriptor('php'),
                'process_timeout_seconds' => 90,
                'repository_remote' => [
                    'allowed_ref_prefix' => 'refs/heads/duo-preview/',
                    'credential_helper' => "/opt/duo-cloud/test/git-$id",
                    'credential_helper_sha256' => hash('sha256', "git-helper-$id"),
                    'name' => 'duo-cloud',
                    'url' => $remote,
                    'url_sha256' => hash('sha256', "duo-cloud-repository-remote-url/v1\0$remote"),
                ],
                'repository_source' => "$workerRoot/repository",
                'review_receipt_sha256' => $review,
                'route_authority' => $descriptor('route'),
                'runtime_contract_file' => '/opt/duo-cloud/deploy/runtime-image-contract.json',
                'runtime_contract_sha256' => hash('sha256', "contract-$id"),
                'seccomp_profile_file' => $seccompPath,
                'seccomp_profile_sha256' => $seccomp,
                'snapshot_object_root' => "$workerRoot/snapshots",
                'storage_authority' => $descriptor('storage'),
                'workload_repository_path' => '/srv/duo/repository',
            ],
            'service' => [
                'device_digest_key' => [
                    'path' => "/var/lib/duo-cloud/config/$id-device.key",
                    'sha256' => hash('sha256', "device-$id"),
                ],
                'response_key_id' => "service-$id",
                'response_signing_key' => [
                    'path' => "/var/lib/duo-cloud/config/$id-response.key",
                    'sha256' => hash('sha256', "response-$id"),
                ],
            ],
            'state_root' => "$workerRoot/state",
        ];
        $bytes = CanonicalJson::encode($document) . "\n";
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        self::assertTrue(chmod($path, 0600));
        return [ProductionConfig::inspectForFleet($path, hash('sha256', $bytes)), $runtimeProof];
    }

    /** @return array<string,mixed> */
    private function runtimeImageProof(string $image, string $seccomp): array {
        $proof = [
            'background_process_fence' => 'real-runner-kill-dead-restart-ready-no-orphan-pid-or-write',
            'cleanup' => 'exact',
            'format' => 'duo-cloud-runtime-image-proof/v1',
            'fresh_volume_ownership' => '10001:10001:0700',
            'helpers' => 'executable',
            'immutable_health' => 'duo-cloud-preview-runtime-health/v1',
            'image_id' => 'sha256:' . hash('sha256', "image-id\0$image"),
            'immutable_image' => $image,
            'implicit_volumes' => 0,
            'runtime_ready' => true,
            'seccomp_ioctl_policy' => 'native-compat-project-mutation-denied-control-allowed',
            'seccomp_profile_sha256' => $seccomp,
            'secrets_readable_as' => '10001:10001',
            'wordpress_policy' => 'staging-cron-and-file-mods-disabled',
        ];
        $proof['proof_receipt_sha256'] = hash(
            'sha256',
            "duo-cloud-runtime-image-proof-receipt/v1\0" . CanonicalJson::encode($proof)
        );
        return $proof;
    }

    /** @return array<string,mixed> */
    private function linuxHostProof(
        ProductionConfig $config,
        ?string $deployRoot = null
    ): array {
        $deployRoot ??= DUO_REPO_ROOT . '/cloud/deploy';
        $runtime = $config->runtime();
        $artifacts = [
            'container_engine_sha256' => $runtime['container_engine']['sha256'],
            'firewall_authority_sha256' => $runtime['firewall_authority']['sha256'],
            'php_closure_sha256' => $this->linuxHostPhpClosureSha256($deployRoot),
            'process_launcher_sha256' => $runtime['process_launcher']['sha256'],
            'seccomp_profile_sha256' => $runtime['seccomp_profile_sha256'],
            'storage_authority_sha256' => $runtime['storage_authority']['sha256'],
            'verifier_sha256' => (string) hash_file(
                'sha256',
                $deployRoot . '/verify-linux-host-boundaries.php'
            ),
        ] + $this->installedConfigurationSha256s;
        ksort($artifacts, SORT_STRING);
        return [
            'apparmor' => 'docker-default-enforced',
            'artifact_sha256s' => $artifacts,
            'cgroup' => [
                'success_descendant' => 'reaped',
                'timeout_descendant' => 'reaped',
            ],
            'cleanup' => 'exact',
            'configuration_file' => $config->configPath(),
            'configuration_sha256' => $config->sha256(),
            'container_engine_path' => $runtime['container_engine']['path'],
            'container_engine_sha256' => $runtime['container_engine']['sha256'],
            'engine_version' => '29.4.2',
            'firewall' => [
                'apparmor' => 'docker-default-enforced',
                'dns_tcp' => 'denied',
                'dns_udp' => 'denied',
                'external_tcp' => 'denied',
                'gateway_tcp' => 'denied',
                'gateway_udp' => 'denied',
                'host_ingress' => 'exact-health',
                'multi_binding' => 'preserved',
            ],
            'format' => 'duo-cloud-linux-host-boundary-proof/v1',
            'image_id' => 'sha256:' . hash('sha256', "image-id\0" . $runtime['image']),
            'image_reference' => $runtime['image'],
            'production_ready' => true,
            'proof_scope' => 'production',
            'seccomp_profile_path' => $runtime['seccomp_profile_file'],
            'seccomp_profile_sha256' => $runtime['seccomp_profile_sha256'],
            'storage' => [
                'hard_bytes' => 67108864,
                'hard_inodes' => 1024,
                'ioctl_mutation' => 'denied',
                'proof_receipt_sha256' => hash('sha256', 'storage-proof'),
                'quota_state' => 'hard-enforced',
            ],
            'storage_worker_root' => $config->workerRoot(),
            'synthetic_rotation_configuration_sha256' => hash(
                'sha256',
                "duo-cloud-linux-host-boundary-proof-synthetic-rotation/v1\0"
                    . $config->sha256()
            ),
            'synthetic_rotation_scope' => 'firewall-multi-binding-only',
        ];
    }

    private function linuxHostPhpClosureSha256(?string $deployRoot = null): string {
        $deployRoot ??= DUO_REPO_ROOT . '/cloud/deploy';
        $digests = [];
        foreach (ProductionDeploymentProofs::linuxHostVerifierSourcePaths(
            $deployRoot
        ) as $name => $path) {
            $digests[$name] = (string) hash_file('sha256', $path);
        }
        return ProductionDeploymentProofs::linuxHostVerifierClosureSha256($digests);
    }

    private function installHostAuthorityConfigurationFixture(): void {
        $descriptors = $this->scratch . '/installed-authority-artifacts';
        self::assertTrue(mkdir($descriptors, 0700));
        $descriptor = static function (string $name, ?string $bytes = null) use ($descriptors): array {
            $path = $descriptors . '/' . $name;
            $bytes ??= "$name\n";
            self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
            self::assertTrue(chmod($path, 0700));
            return ['path' => $path, 'sha256' => hash('sha256', $bytes)];
        };
        $interpreter = $descriptor('php');
        $sudo = $descriptor('sudo');
        $closure = $descriptor('authority-closure.php', "<?php\n");
        $firewallAuthority = $descriptor(
            'firewall-authority',
            '#!' . $interpreter['path'] . "\n<?php\n"
        );
        $storageAuthority = $descriptor(
            'storage-authority',
            '#!' . $interpreter['path'] . "\n<?php\n"
        );
        $firewallEngine = $descriptor('firewall-engine');
        $firewallIp = $descriptor('ip');
        $firewallNft = $descriptor('nft');
        $storageEngine = $descriptor('storage-engine');
        $cryptsetup = $descriptor('cryptsetup');
        $dmsetup = $descriptor('dmsetup');
        $findmnt = $descriptor('findmnt');
        $xfsIo = $descriptor('xfs-io');
        $xfsQuota = $descriptor('xfs-quota');

        $client = static function (array $authority) use (
            $closure,
            $interpreter,
            $sudo
        ): array {
            $authorityClosure = [$authority, $closure];
            usort(
                $authorityClosure,
                static fn (array $left, array $right): int => strcmp($left['path'], $right['path'])
            );
            return [
                'authority' => $authority,
                'closure' => $authorityClosure,
                'format' => 'duo-cloud-firewall-client-config/v1',
                'interpreter' => $interpreter,
                'process_timeout_seconds' => 60,
                'sudo' => $sudo,
            ];
        };
        $documents = [
            'firewall-authority' => [
                'container_engine' => $firewallEngine,
                'format' => 'duo-cloud-host-firewall-config/v1',
                'ip' => $firewallIp,
                'nft' => $firewallNft,
                'principals' => [],
                'process_timeout_seconds' => 5,
                'state_root' => $this->scratch,
            ],
            'firewall-client' => $client($firewallAuthority),
            'storage-authority' => [
                'backing_device' => '/dev/fixture',
                'cipher' => 'aes-xts-plain64',
                'configuration_sha256s' => [],
                'container_engine' => $storageEngine,
                'cryptsetup' => $cryptsetup,
                'dmsetup' => $dmsetup,
                'docker_root' => $this->scratch,
                'durable_paths' => [],
                'filesystem_uuid' => '00000000-0000-4000-8000-000000000000',
                'findmnt' => $findmnt,
                'format' => 'duo-cloud-host-storage-config/v1',
                'key_location' => 'keyring',
                'key_size_bits' => 512,
                'limits' => [],
                'luks_uuid' => '00000000-0000-4000-8000-000000000000',
                'mapper_name' => 'fixture',
                'mapper_path' => '/dev/mapper/fixture',
                'mapper_size_sectors' => 131072,
                'mapper_uuid' => 'fixture',
                'mountpoint' => $this->scratch,
                'payload_offset_sectors' => 8,
                'process_timeout_seconds' => 10,
                'sector_size_bytes' => 512,
                'service_uids' => [],
                'state_root' => $this->scratch,
                'worker_roots' => [],
                'xfs_io' => $xfsIo,
                'xfs_quota' => $xfsQuota,
            ],
            'storage-client' => $client($storageAuthority),
        ];
        foreach ($documents as $name => $document) {
            $bytes = CanonicalJson::encode($document) . "\n";
            $sha256 = hash('sha256', $bytes);
            self::assertSame(
                strlen($bytes),
                file_put_contents($this->scratch . "/$name.json", $bytes)
            );
            self::assertTrue(chmod($this->scratch . "/$name.json", 0600));
            self::assertSame(
                65,
                file_put_contents($this->scratch . "/$name.sha256", $sha256 . "\n")
            );
            self::assertTrue(chmod($this->scratch . "/$name.sha256", 0600));
            $this->installedConfigurationSha256s[
                str_replace('-', '_', $name) . '_configuration_sha256'
            ] = $sha256;
        }
        ksort($this->installedConfigurationSha256s, SORT_STRING);
    }

    private function installedArtifactFixture(): string {
        $cloudRoot = $this->scratch . '/installed-artifact-fixture';
        foreach (['deploy', 'runtime', 'src'] as $directory) {
            self::assertTrue(mkdir($cloudRoot . '/' . $directory, 0700, true));
        }
        $relativePaths = [
            'deploy/duo-cloud-control-caddy.service',
            'deploy/duo-cloud-php-fpm@.service',
            'deploy/php-fpm-pool.conf.example',
            'deploy/php-fpm.ini',
            'deploy/verify-installed-fpm-ingress.php',
            'deploy/verify-linux-host-boundaries.php',
            'runtime/FirewallClientConfig.php',
            'runtime/HostFirewallAuthority.php',
            'runtime/ProductionConfig.php',
            'runtime/ProductionDeploymentProofs.php',
            'runtime/RuntimeSlotLock.php',
            'src/CanonicalJson.php',
            'src/ContainerWorkloadRuntime.php',
            'src/ControlRefusal.php',
            'src/HostAuthorityBusy.php',
            'src/ImmutableOciReference.php',
            'src/WorkloadRuntime.php',
            'src/WorkloadSecurityInspection.php',
        ];
        foreach ($relativePaths as $relativePath) {
            $destination = $cloudRoot . '/' . $relativePath;
            self::assertTrue(copy(
                DUO_REPO_ROOT . '/cloud/' . $relativePath,
                $destination
            ));
            self::assertTrue(chmod($destination, 0600));
        }
        return $cloudRoot . '/deploy';
    }

    /** @param list<ProductionConfig> $configs @param callable():int|null $clock */
    private function publishInstalled(
        array $configs,
        int $controlPid = 99,
        ?string $deployRoot = null,
        ?callable $clock = null
    ): void {
        $deployRoot ??= DUO_REPO_ROOT . '/cloud/deploy';
        $artifacts = [
            'control_unit_sha256' => (string) hash_file(
                'sha256',
                $deployRoot . '/duo-cloud-control-caddy.service'
            ),
            'php_fpm_ini_sha256' => (string) hash_file(
                'sha256',
                $deployRoot . '/php-fpm.ini'
            ),
            'php_fpm_pool_template_sha256' => (string) hash_file(
                'sha256',
                $deployRoot . '/php-fpm-pool.conf.example'
            ),
            'php_fpm_unit_sha256' => (string) hash_file(
                'sha256',
                $deployRoot . '/duo-cloud-php-fpm@.service'
            ),
            'verifier_sha256' => (string) hash_file(
                'sha256',
                $deployRoot . '/verify-installed-fpm-ingress.php'
            ),
        ];
        $closureDigests = [];
        foreach (ProductionDeploymentProofs::installedFpmVerifierSourcePaths(
            $deployRoot
        ) as $name => $path) {
            $closureDigests[$name] = (string) hash_file('sha256', $path);
        }
        $artifacts['php_closure_sha256'] =
            ProductionDeploymentProofs::installedFpmVerifierClosureSha256($closureDigests);
        ksort($artifacts, SORT_STRING);
        $workers = [];
        $configurationSha256s = [];
        foreach ($configs as $index => $config) {
            $id = 'worker-' . chr(ord('a') + $index);
            $configurationSha256s[] = $config->sha256();
            $workers[] = [
                'control_host' => "$id.control.example.test",
                'fpm_configuration_sha256' => hash('sha256', "fpm-$id"),
                'live_route_status' => 403,
                'process' => ['gid' => 10001, 'pid' => 100 + $index, 'uid' => 10001],
                'socket' => [
                    'group' => 10001,
                    'inode' => 200 + $index,
                    'mode' => '0660',
                    'owner' => 10001,
                    'path' => "/run/duo-cloud/$id/php-fpm.sock",
                ],
                'worker_configuration_sha256' => $config->sha256(),
                'worker_id' => $id,
            ];
        }
        sort($configurationSha256s, SORT_STRING);
        $proof = [
            'artifact_sha256s' => $artifacts,
            'configuration_sha256' => hash('sha256', CanonicalJson::encode($configurationSha256s)),
            'control_caddy' => [
                'configuration_sha256' => hash('sha256', 'caddy'),
                'gid' => 10002,
                'pid' => $controlPid,
                'supplementary_socket_gid' => 10001,
                'uid' => 10002,
            ],
            'format' => 'duo-cloud-installed-fpm-ingress-proof/v1',
            'max_wall_seconds' => 45,
            'php_fpm_ini_sha256' => $artifacts['php_fpm_ini_sha256'],
            'state' => 'ready',
            'workers' => $workers,
        ];
        $proof['proof_receipt_sha256'] = hash(
            'sha256',
            "duo-cloud-installed-fpm-ingress-proof-receipt/v1\0"
                . CanonicalJson::encode($proof)
        );
        ProductionDeploymentProofs::publishInstalledFpm(
            $this->hostRoot,
            $proof,
            $configurationSha256s,
            function_exists('posix_geteuid') ? posix_geteuid() : (int) getmyuid(),
            function_exists('posix_getegid') ? posix_getegid() : (int) getmygid(),
            $clock ?? fn (): int => $this->now,
            fn (): string => $this->boot,
            $deployRoot
        );
    }

    private function proofs(ProductionConfig $config): ProductionDeploymentProofs {
        return new ProductionDeploymentProofs(
            $config,
            fn (): int => $this->now,
            fn (): string => $this->boot,
            DUO_REPO_ROOT . '/cloud/deploy'
        );
    }

    /** @param callable():array<string,mixed> $operation */
    private function assertRefused(callable $operation, string $message): void {
        try {
            $operation();
            self::fail('invalid deployment proof remained admitted');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString($message, $error->getMessage());
        }
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
