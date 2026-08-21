<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\ControlRefusal;
use Duo\Cloud\OperatorDiagnostics;
use Duo\Cloud\ProductionConfig;
use Duo\Cloud\ProductionFleetConfig;
use Duo\Cloud\ProductionFleetReaper;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/runtime/ProductionFleetReaper.php';

#[CoversNothing]
final class ProductionFleetReaperTest extends TestCase {
    private string $scratch;
    private string $hostPreflightRoot;
    private string $phpSha256;

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-production-fleet-reaper-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        $this->hostPreflightRoot = $this->scratch . '/host-preflight';
        self::assertTrue(mkdir($this->hostPreflightRoot, 0700));
        $this->phpSha256 = (string) hash_file('sha256', PHP_BINARY);
    }

    protected function tearDown(): void {
        $this->removeTree($this->scratch);
    }

    public function testOneFleetPassVisitsThirtyTwoWorkersSeriallyAndContinuesAfterRefusal(): void {
        $fleet = $this->fleet(32);
        $visited = [];
        $active = 0;
        $peak = 0;
        $diagnostics = [];
        $result = ($this->reaper(
            $fleet,
            static function (array $worker) use (&$visited, &$active, &$peak): array {
                $active++;
                $peak = max($peak, $active);
                $visited[] = $worker['worker_id'];
                try {
                    if ($worker['worker_id'] === 'worker-16') {
                        throw new \RuntimeException('secret worker failure detail');
                    }
                    return ['examined' => 1, 'reaped' => 1, 'refused' => 0];
                } finally {
                    $active--;
                }
            },
            new OperatorDiagnostics(static function (string $line) use (&$diagnostics): void {
                $diagnostics[] = $line;
            })
        ))->sweep();

        self::assertSame(1, $peak);
        self::assertCount(32, $visited);
        self::assertSame('worker-01', $visited[0]);
        self::assertSame('worker-32', $visited[31]);
        self::assertSame('duo-cloud-expired-preview-fleet-sweep/v1', $result['format']);
        self::assertSame('degraded', $result['state']);
        self::assertSame(31, $result['examined']);
        self::assertSame(31, $result['reaped']);
        self::assertSame(0, $result['refused']);
        self::assertSame(1, $result['worker_refusals']);
        self::assertSame('refused', $result['workers'][15]['state']);
        self::assertSame(0, $result['workers'][15]['examined']);
        self::assertSame(0, $result['workers'][15]['refused']);
        self::assertSame($result['examined'], $result['reaped'] + $result['refused']);
        self::assertCount(1, $diagnostics);
        self::assertStringNotContainsString('secret worker failure detail', $diagnostics[0]);
        self::assertStringNotContainsString('secret worker failure detail', CanonicalJson::encode($result));
    }

    public function testMalformedWorkerAccountingIsScreenedAndDoesNotSkipLaterWorker(): void {
        $fleet = $this->fleet(2);
        $visited = [];
        $result = ($this->reaper(
            $fleet,
            static function (array $worker) use (&$visited): array {
                $visited[] = $worker['worker_id'];
                return $worker['worker_id'] === 'worker-01'
                    ? ['examined' => 1, 'reaped' => 0, 'refused' => 0]
                    : ['examined' => 0, 'reaped' => 0, 'refused' => 0];
            },
            new OperatorDiagnostics(static function (string $line): void {})
        ))->sweep();

        self::assertSame(['worker-01', 'worker-02'], $visited);
        self::assertSame('degraded', $result['state']);
        self::assertSame(0, $result['examined']);
        self::assertSame(0, $result['reaped']);
        self::assertSame(0, $result['refused']);
        self::assertSame(1, $result['worker_refusals']);
        self::assertSame('refused', $result['workers'][0]['state']);
        self::assertSame('complete', $result['workers'][1]['state']);
    }

    public function testRetiringConfigurationReapsOldGenerationBeforeCurrentIdentityIsTried(): void {
        [$fleet, $current, $retiring] = $this->rotatingFleet();
        $activeConfiguration = $retiring['configuration_sha256'];
        $visited = [];
        $result = ($this->reaper(
            $fleet,
            static function (array $worker) use (&$activeConfiguration, &$visited): array {
                $visited[] = $worker['configuration_sha256'];
                if (!hash_equals($activeConfiguration, $worker['configuration_sha256'])) {
                    return ['examined' => 1, 'reaped' => 0, 'refused' => 1];
                }
                $activeConfiguration = '';
                return ['examined' => 1, 'reaped' => 1, 'refused' => 0];
            }
        ))->sweep();

        self::assertSame([$retiring['configuration_sha256']], $visited);
        self::assertSame('', $activeConfiguration);
        self::assertSame('complete', $result['state']);
        self::assertSame(1, $result['examined']);
        self::assertSame(1, $result['reaped']);
        self::assertSame(0, $result['refused']);
        self::assertSame(0, $result['worker_refusals']);
        self::assertSame(
            $retiring['configuration_sha256'],
            $result['workers'][0]['configuration_sha256']
        );
        self::assertNotSame($current['configuration_sha256'], $visited[0]);
    }

    public function testCurrentConfigurationReapsAfterRetiringIdentityRefuses(): void {
        [$fleet, $current, $retiring] = $this->rotatingFleet();
        $activeConfiguration = $current['configuration_sha256'];
        $visited = [];
        $diagnostics = [];
        $result = ($this->reaper(
            $fleet,
            static function (array $worker) use (&$activeConfiguration, &$visited): array {
                $visited[] = $worker['configuration_sha256'];
                if (!hash_equals($activeConfiguration, $worker['configuration_sha256'])) {
                    return ['examined' => 1, 'reaped' => 0, 'refused' => 1];
                }
                $activeConfiguration = '';
                return ['examined' => 1, 'reaped' => 1, 'refused' => 0];
            },
            new OperatorDiagnostics(static function (string $line) use (&$diagnostics): void {
                $diagnostics[] = $line;
            })
        ))->sweep();

        self::assertSame([
            $retiring['configuration_sha256'],
            $current['configuration_sha256'],
        ], $visited);
        self::assertSame('', $activeConfiguration);
        self::assertSame('complete', $result['state']);
        self::assertSame(1, $result['examined']);
        self::assertSame(1, $result['reaped']);
        self::assertSame(0, $result['refused']);
        self::assertSame(0, $result['worker_refusals']);
        self::assertSame($current['configuration_sha256'], $result['workers'][0]['configuration_sha256']);
        self::assertSame([], $diagnostics);
    }

    public function testRetiringCandidateRefusalIsPreservedWhenCurrentFindsNothing(): void {
        [$fleet, $current, $retiring] = $this->rotatingFleet();
        $visited = [];
        $result = ($this->reaper(
            $fleet,
            static function (array $worker) use (&$visited, $retiring): array {
                $visited[] = $worker['configuration_sha256'];
                return $worker['configuration_sha256'] === $retiring['configuration_sha256']
                    ? ['examined' => 1, 'reaped' => 0, 'refused' => 1]
                    : ['examined' => 0, 'reaped' => 0, 'refused' => 0];
            }
        ))->sweep();

        self::assertSame([
            $retiring['configuration_sha256'],
            $current['configuration_sha256'],
        ], $visited);
        self::assertSame('degraded', $result['state']);
        self::assertSame(1, $result['examined']);
        self::assertSame(0, $result['reaped']);
        self::assertSame(1, $result['refused']);
        self::assertSame(0, $result['worker_refusals']);
        self::assertSame(
            $retiring['configuration_sha256'],
            $result['workers'][0]['configuration_sha256']
        );
    }

    public function testMissingRetiringConfigurationRefusesBeforeProofOrWorkerEffects(): void {
        [$fleet, , $retiring] = $this->rotatingFleet();
        self::assertTrue(unlink($retiring['configuration_file']));
        $visited = [];
        $reconciled = 0;
        try {
            ($this->reaper(
                $fleet,
                static function (array $worker) use (&$visited): array {
                    $visited[] = $worker['configuration_sha256'];
                    return ['examined' => 0, 'reaped' => 0, 'refused' => 0];
                },
                null,
                static function (
                    string $_hostRoot,
                    array $_configurationSha256s,
                    array $_runtimeReviewSha256s,
                    int $_ownerUid,
                    int $_ownerGid
                ) use (&$reconciled): void {
                    $reconciled++;
                }
            ))->sweep();
            self::fail('an incomplete retiring keep set authorized namespace cleanup');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('production configuration', $error->getMessage());
        }

        self::assertSame([], $visited);
        self::assertSame(0, $reconciled);
    }

    public function testNamespaceReconciliationUsesExactCurrentAndRetiringKeepSetBeforeWorkers(): void {
        [$fleet, $current, $retiring] = $this->rotatingFleet();
        $currentConfig = ProductionConfig::inspectForFleet(
            $current['configuration_file'],
            $current['configuration_sha256']
        );
        $retiringConfig = ProductionConfig::inspectForFleet(
            $retiring['configuration_file'],
            $retiring['configuration_sha256']
        );
        $configurationKeep = [
            $currentConfig->sha256(),
            $retiringConfig->sha256(),
        ];
        $runtimeKeep = [
            (string) $currentConfig->runtime()['review_receipt_sha256'],
            (string) $retiringConfig->runtime()['review_receipt_sha256'],
        ];
        sort($configurationKeep, SORT_STRING);
        $runtimeKeep = array_values(array_unique($runtimeKeep));
        sort($runtimeKeep, SORT_STRING);
        $hostStat = lstat($this->hostPreflightRoot);
        self::assertIsArray($hostStat);
        $expectedHostRoot = $this->hostPreflightRoot;
        $phase = 'prepared';
        $result = ($this->reaper(
            $fleet,
            static function (array $_worker) use (&$phase): array {
                self::assertContains($phase, ['reconciled', 'worker']);
                $phase = 'worker';
                return ['examined' => 0, 'reaped' => 0, 'refused' => 0];
            },
            null,
            static function (
                string $hostRoot,
                array $configurationSha256s,
                array $runtimeReviewSha256s,
                int $ownerUid,
                int $ownerGid
            ) use (
                &$phase,
                $configurationKeep,
                $runtimeKeep,
                $hostStat,
                $expectedHostRoot
            ): void {
                self::assertSame('prepared', $phase);
                self::assertSame($expectedHostRoot, $hostRoot);
                self::assertSame($configurationKeep, $configurationSha256s);
                self::assertSame($runtimeKeep, $runtimeReviewSha256s);
                self::assertSame((int) $hostStat['uid'], $ownerUid);
                self::assertSame((int) $hostStat['gid'], $ownerGid);
                $phase = 'reconciled';
            }
        ))->sweep();

        self::assertSame('worker', $phase);
        self::assertSame('complete', $result['state']);
    }

    public function testRetiringAssemblyExceptionCannotBecomeCompleteOrLeakDiagnostic(): void {
        [$fleet, $current, $retiring] = $this->rotatingFleet();
        $visited = [];
        $diagnostics = [];
        $secret = 'retiring-secret-material';
        $result = ($this->reaper(
            $fleet,
            static function (array $worker) use (&$visited, $retiring, $secret): array {
                $visited[] = $worker['configuration_sha256'];
                if ($worker['configuration_sha256'] === $retiring['configuration_sha256']) {
                    throw new \RuntimeException($secret);
                }
                return ['examined' => 0, 'reaped' => 0, 'refused' => 0];
            },
            new OperatorDiagnostics(static function (string $line) use (&$diagnostics): void {
                $diagnostics[] = $line;
            })
        ))->sweep();

        self::assertSame([
            $retiring['configuration_sha256'],
            $current['configuration_sha256'],
        ], $visited);
        self::assertSame('degraded', $result['state']);
        self::assertSame(0, $result['examined']);
        self::assertSame(0, $result['refused']);
        self::assertSame(1, $result['worker_refusals']);
        self::assertCount(1, $diagnostics);
        self::assertStringNotContainsString($secret, $diagnostics[0]);
        self::assertStringNotContainsString($secret, CanonicalJson::encode($result));
    }

    public function testRetiringConfigurationMustIdentifySameWorkerBeforeAnyEffect(): void {
        $current = $this->workerConfiguration('worker-01', 'current');
        $retiring = $this->workerConfiguration('worker-01', 'retiring', 'different-site');
        $fleet = $this->writeFleet([[
            'configuration_file' => $current['configuration_file'],
            'configuration_sha256' => $current['configuration_sha256'],
            'retiring_configuration' => $retiring,
            'worker_id' => 'worker-01',
        ]]);
        $effects = 0;

        try {
            ($this->reaper(
                $fleet,
                static function (array $worker) use (&$effects): array {
                    $effects++;
                    return ['examined' => 0, 'reaped' => 0, 'refused' => 0];
                }
            ))->sweep();
            self::fail('retiring identity for a different principal reached janitor effects');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('does not identify the current worker', $error->getMessage());
        }
        self::assertSame(0, $effects);
    }

    public function testDuplicatePrincipalAndNestedRootsRefuseBeforeAnyEffect(): void {
        $cases = [];
        $first = $this->workerConfiguration('worker-01', 'topology-a', 'site-a');
        $cases['principal'] = [$first, $this->workerConfiguration(
            'worker-02',
            'topology-b',
            'site-a'
        )];
        $cases['roots overlap'] = [
            $this->workerConfiguration('worker-01', 'root-a', 'site-a'),
            $this->workerConfiguration(
                'worker-02',
                'root-b',
                'site-b',
                $this->scratch . '/workers/worker-01/nested-worker'
            ),
        ];

        foreach ($cases as $reason => [$left, $right]) {
            $fleet = $this->writeFleet([[
                'configuration_file' => $left['configuration_file'],
                'configuration_sha256' => $left['configuration_sha256'],
                'retiring_configuration' => null,
                'worker_id' => 'worker-01',
            ], [
                'configuration_file' => $right['configuration_file'],
                'configuration_sha256' => $right['configuration_sha256'],
                'retiring_configuration' => null,
                'worker_id' => 'worker-02',
            ]], 'invalid-topology-' . str_replace(' ', '-', $reason));
            $effects = 0;
            try {
                ($this->reaper(
                    $fleet,
                    static function (array $worker) use (&$effects): array {
                        $effects++;
                        return ['examined' => 0, 'reaped' => 0, 'refused' => 0];
                    }
                ))->sweep();
                self::fail("fleet with duplicate $reason reached janitor effects");
            } catch (ControlRefusal $error) {
                self::assertStringContainsString($reason, $error->getMessage());
            }
            self::assertSame(0, $effects);
        }
    }

    public function testFleetApplicationLockRefusesConcurrentProcessBeforeEffects(): void {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('pcntl is required for process-level lock evidence');
        }
        $fleet = $this->fleet(1);
        $entered = $this->scratch . '/first-entered';
        $release = $this->scratch . '/release-first';
        $firstResult = $this->scratch . '/first-result';
        $secondResult = $this->scratch . '/second-result';
        $breach = $this->scratch . '/second-entered';

        $firstPid = pcntl_fork();
        self::assertNotSame(-1, $firstPid);
        if ($firstPid === 0) {
            try {
                ($this->reaper(
                    $fleet,
                    static function (array $worker) use ($entered, $release): array {
                        file_put_contents($entered, "entered\n", LOCK_EX);
                        $deadline = hrtime(true) + 5_000_000_000;
                        while (!file_exists($release) && hrtime(true) < $deadline) {
                            usleep(10000);
                        }
                        if (!file_exists($release)) {
                            throw new \RuntimeException('first process release deadline elapsed');
                        }
                        return ['examined' => 0, 'reaped' => 0, 'refused' => 0];
                    }
                ))->sweep();
                file_put_contents($firstResult, "complete\n", LOCK_EX);
            } catch (\Throwable $error) {
                file_put_contents($firstResult, 'failed:' . get_class($error) . "\n", LOCK_EX);
            }
            exit(0);
        }

        $secondPid = null;
        try {
            $this->waitForFile($entered);
            $secondPid = pcntl_fork();
            self::assertNotSame(-1, $secondPid);
            if ($secondPid === 0) {
                try {
                    ($this->reaper(
                        $fleet,
                        static function (array $worker) use ($breach): array {
                            file_put_contents($breach, "entered\n", LOCK_EX);
                            return ['examined' => 0, 'reaped' => 0, 'refused' => 0];
                        }
                    ))->sweep();
                    file_put_contents($secondResult, "unexpected-complete\n", LOCK_EX);
                } catch (ControlRefusal $error) {
                    file_put_contents($secondResult, $error->getMessage() . "\n", LOCK_EX);
                }
                exit(0);
            }
            self::assertSame($secondPid, pcntl_waitpid($secondPid, $secondStatus));
            $secondPid = null;
        } finally {
            file_put_contents($release, "release\n", LOCK_EX);
            self::assertSame($firstPid, pcntl_waitpid($firstPid, $firstStatus));
            if (is_int($secondPid) && $secondPid > 0) {
                pcntl_waitpid($secondPid, $ignoredStatus);
            }
        }

        self::assertSame("complete\n", file_get_contents($firstResult));
        self::assertSame("authority store lock is busy\n", file_get_contents($secondResult));
        self::assertFileDoesNotExist($breach);
    }

    public function testFleetSchemaIsClosedBoundedAndRejectsDuplicateConfigurationIdentities(): void {
        $current = $this->workerConfiguration('worker-01', 'current');
        $retiring = $this->workerConfiguration('worker-01', 'retiring');
        $worker = [
            'configuration_file' => $current['configuration_file'],
            'configuration_sha256' => $current['configuration_sha256'],
            'retiring_configuration' => $retiring,
            'worker_id' => 'worker-01',
        ];
        $loaded = $this->writeFleet([$worker]);
        self::assertSame($retiring, $loaded->workers()[0]['retiring_configuration']);

        $missingSlot = $worker;
        unset($missingSlot['retiring_configuration']);
        $this->assertFleetRejected([$missingSlot], 'missing or unknown fields', 'missing-slot');

        $second = $this->workerConfiguration('worker-02', 'current');
        foreach ([
            'path' => [
                'configuration_file' => $current['configuration_file'],
                'configuration_sha256' => hash('sha256', 'different-path-pin'),
            ],
            'pin' => [
                'configuration_file' => $second['configuration_file'],
                'configuration_sha256' => $current['configuration_sha256'],
            ],
        ] as $case => $duplicate) {
            $candidate = $worker;
            $candidate['retiring_configuration'] = $duplicate;
            $this->assertFleetRejected([$candidate], 'not bounded and unique', "duplicate-$case");
        }

        $hardlink = $this->scratch . '/worker-01-hardlink.json';
        self::assertTrue(link($current['configuration_file'], $hardlink));
        $candidate = $worker;
        $candidate['retiring_configuration'] = [
            'configuration_file' => $hardlink,
            'configuration_sha256' => hash('sha256', 'different-inode-pin'),
        ];
        $this->assertFleetRejected([$candidate], 'file identity is invalid or duplicated', 'inode');
        self::assertTrue(unlink($hardlink));

        self::assertCount(32, $this->fleet(32, true)->workers());
        $this->assertFleetRejected($this->workerRows(33, false), 'not canonical and bounded', '33-workers');
    }

    private function reaper(
        ProductionFleetConfig $fleet,
        ?callable $workerSweep = null,
        ?OperatorDiagnostics $diagnostics = null,
        ?callable $namespaceReconciler = null
    ): ProductionFleetReaper {
        return new ProductionFleetReaper(
            $fleet,
            $workerSweep,
            $diagnostics,
            true,
            null,
            $namespaceReconciler ?? static function (
                string $_hostRoot,
                array $_configurationSha256s,
                array $_runtimeReviewSha256s,
                int $_ownerUid,
                int $_ownerGid
            ): void {}
        );
    }

    private function fleet(int $count, bool $retiring = false): ProductionFleetConfig {
        return $this->writeFleet($this->workerRows($count, $retiring));
    }

    /** @return array{0:ProductionFleetConfig,1:array<string,string>,2:array<string,string>} */
    private function rotatingFleet(): array {
        $current = $this->workerConfiguration('worker-01', 'current');
        $retiring = $this->workerConfiguration('worker-01', 'retiring');
        $fleet = $this->writeFleet([[
            'configuration_file' => $current['configuration_file'],
            'configuration_sha256' => $current['configuration_sha256'],
            'retiring_configuration' => $retiring,
            'worker_id' => 'worker-01',
        ]]);
        return [$fleet, $current, $retiring];
    }

    /** @return list<array<string,mixed>> */
    private function workerRows(int $count, bool $retiring): array {
        $workers = [];
        for ($index = 1; $index <= $count; $index++) {
            $workerId = sprintf('worker-%02d', $index);
            $current = $this->workerConfiguration($workerId, 'current');
            $workers[] = [
                'configuration_file' => $current['configuration_file'],
                'configuration_sha256' => $current['configuration_sha256'],
                'retiring_configuration' => $retiring
                    ? $this->workerConfiguration($workerId, 'retiring')
                    : null,
                'worker_id' => $workerId,
            ];
        }
        return $workers;
    }

    /** @param list<array<string,mixed>> $workers */
    private function writeFleet(array $workers, string $name = 'fleet'): ProductionFleetConfig {
        $document = CanonicalJson::encode([
            'format' => ProductionFleetConfig::FORMAT,
            'workers' => $workers,
        ]) . "\n";
        $path = $this->scratch . "/$name-" . bin2hex(random_bytes(4)) . '.json';
        self::assertSame(strlen($document), file_put_contents($path, $document));
        self::assertTrue(chmod($path, 0600));
        return ProductionFleetConfig::load($path, hash('sha256', $document));
    }

    /** @param list<array<string,mixed>> $workers */
    private function assertFleetRejected(array $workers, string $reason, string $name): void {
        try {
            $this->writeFleet($workers, $name);
            self::fail("fleet $name was accepted");
        } catch (ControlRefusal $error) {
            self::assertStringContainsString($reason, $error->getMessage());
        }
    }

    /** @return array{configuration_file:string,configuration_sha256:string} */
    private function workerConfiguration(
        string $workerId,
        string $revision,
        ?string $siteId = null,
        ?string $workerRoot = null
    ): array {
        $workerRoot ??= $this->scratch . '/workers/' . $workerId;
        $remoteUrl = "https://git.example.test/tenant-a/$workerId.git";
        $executable = [
            'path' => PHP_BINARY,
            'sha256' => $this->phpSha256,
        ];
        $document = [
            'controller_keys' => [[
                'key_id' => 'origin-controller-key',
                'public_key' => [
                    'path' => "$workerRoot/keys/controller",
                    'sha256' => hash('sha256', "controller-$workerId"),
                ],
                'site_id' => $siteId ?? $workerId,
                'tenant_id' => 'tenant-a',
            ]],
            'format' => ProductionConfig::FORMAT,
            'host_durable_paths' => [
                'authority_config_root' => $this->scratch . '/authority-config',
                'control_proxy_config_root' => $this->scratch . '/control-proxy-config',
                'control_proxy_data_root' => $this->scratch . '/control-proxy-data',
                'firewall_authority_state_root' => $this->scratch . '/firewall-state',
                'route_authority_state_root' => $this->scratch . '/route-state',
                'route_proxy_config_root' => $this->scratch . '/route-proxy-config',
                'route_proxy_data_root' => $this->scratch . '/route-proxy-data',
                'storage_authority_state_root' => $this->scratch . '/storage-state',
            ],
            'host_preflight_root' => $this->hostPreflightRoot,
            'runtime' => [
                'container_engine' => $executable,
                'firewall_authority' => $executable,
                'git' => $executable,
                'image' => 'registry.example.test/duo/wordpress@sha256:'
                    . hash('sha256', "image-$revision"),
                'memory_bytes' => 536870912,
                'nano_cpus' => 500000000,
                'pids_limit' => 128,
                'platform_fingerprint_sha256' => hash('sha256', 'platform'),
                'preview_domain' => 'preview.example.test',
                'process_launcher' => $executable,
                'process_timeout_seconds' => 30,
                'repository_remote' => [
                    'allowed_ref_prefix' => 'refs/heads/duo-preview/',
                    'credential_helper' => PHP_BINARY,
                    'credential_helper_sha256' => $this->phpSha256,
                    'name' => 'duo-cloud',
                    'url' => $remoteUrl,
                    'url_sha256' => hash(
                        'sha256',
                        "duo-cloud-repository-remote-url/v1\0$remoteUrl"
                    ),
                ],
                'repository_source' => "$workerRoot/repository",
                'review_receipt_sha256' => hash('sha256', "review-$revision"),
                'route_authority' => $executable,
                'runtime_contract_file' => $this->scratch . '/absent-runtime-contract.json',
                'runtime_contract_sha256' => hash('sha256', 'runtime-contract'),
                'seccomp_profile_file' => $this->scratch . '/absent-seccomp.json',
                'seccomp_profile_sha256' => hash('sha256', 'seccomp'),
                'snapshot_object_root' => "$workerRoot/snapshots",
                'storage_authority' => $executable,
                'workload_repository_path' => '/srv/duo/repository',
            ],
            'service' => [
                'device_digest_key' => [
                    'path' => "$workerRoot/keys/device",
                    'sha256' => hash('sha256', "device-$workerId"),
                ],
                'response_key_id' => 'service-key-v1',
                'response_signing_key' => [
                    'path' => "$workerRoot/keys/response",
                    'sha256' => hash('sha256', "response-$workerId"),
                ],
            ],
            'state_root' => "$workerRoot/state",
        ];
        $bytes = CanonicalJson::encode($document) . "\n";
        $path = $this->scratch . "/$workerId-$revision-" . ($siteId ?? $workerId) . '.json';
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        self::assertTrue(chmod($path, 0600));
        return [
            'configuration_file' => $path,
            'configuration_sha256' => hash('sha256', $bytes),
        ];
    }

    private function waitForFile(string $path): void {
        $deadline = hrtime(true) + 5_000_000_000;
        while (!file_exists($path) && hrtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($path);
    }

    private function removeTree(string $path): void {
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && !is_link($child)) {
                $this->removeTree($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
