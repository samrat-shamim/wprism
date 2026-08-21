<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\ProductionHostPreflightReceipt;
use Duo\Cloud\ProductionDeploymentProofs;
use Duo\Cloud\ProductionPreflightReceipt;
use Duo\Cloud\ProductionService;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/runtime/ProductionPreflightReceipt.php';
require_once DUO_REPO_ROOT . '/cloud/runtime/ProductionService.php';

#[CoversNothing]
final class DeploymentArtifactsTest extends TestCase {
    public function testDeploymentProofTimersRenewEveryBootBoundReceiptForTheFleet(): void {
        $installedTimer = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-verify-installed-fpm-ingress.timer'
        );
        $hostTimer = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-verify-linux-host-boundaries@.timer'
        );
        $hostService = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-verify-linux-host-boundaries@.service'
        );
        $diagnostic = (string) file_get_contents(
            DUO_REPO_ROOT
                . '/cloud/deploy/duo-cloud-diagnose-linux-host-boundaries-nonproduction.service'
        );
        self::assertSame(90, ProductionDeploymentProofs::INSTALLED_FPM_TTL_SECONDS);
        self::assertSame(604800, ProductionDeploymentProofs::LINUX_HOST_TTL_SECONDS);
        self::assertStringContainsString('OnUnitInactiveSec=30s', $installedTimer);
        self::assertStringContainsString(
            'Unit=duo-cloud-verify-installed-fpm-ingress.service',
            $installedTimer
        );
        self::assertStringContainsString('OnUnitInactiveSec=12h', $hostTimer);
        self::assertStringContainsString(
            'Unit=duo-cloud-verify-linux-host-boundaries@%i.service',
            $hostTimer
        );
        self::assertStringContainsString(
            'EnvironmentFile=/var/lib/duo-cloud/config/workers/%i-host-proof.env',
            $hostService
        );
        self::assertStringContainsString('RuntimeDirectoryPreserve=yes', $hostService);
        self::assertStringContainsString('StartLimitIntervalSec=0', $hostService);
        self::assertStringContainsString('Restart=on-failure', $hostService);
        self::assertStringContainsString('RestartPreventExitStatus=70 78', $hostService);
        self::assertStringContainsString('RestartSec=30s', $hostService);
        self::assertStringContainsString('RuntimeDirectoryPreserve=yes', $diagnostic);
        self::assertFileDoesNotExist(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-verify-linux-host-boundaries.service'
        );
        self::assertTrue(is_executable(
            DUO_REPO_ROOT . '/cloud/bin/duo-cloud-install-runtime-image-proof'
        ));
    }

    public function testPreflightTimerRefreshesWithoutSupervisorPreemption(): void {
        $hostTimer = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-preflight-fleet.timer'
        );
        $hostService = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-preflight-fleet.service'
        );
        $workerTimer = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-preflight@.timer'
        );
        $workerService = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-preflight@.service'
        );
        $pool = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/php-fpm-pool.conf.example'
        );
        $ttls = [
            ProductionHostPreflightReceipt::TTL_SECONDS,
            ProductionPreflightReceipt::TTL_SECONDS,
        ];

        self::assertSame([60, 60], $ttls);
        foreach ([$hostTimer, $workerTimer] as $timer) {
            preg_match('/^OnUnitInactiveSec=([1-9][0-9]*)s$/m', $timer, $interval);
            self::assertSame('20', $interval[1] ?? null);
            self::assertLessThanOrEqual(intdiv(min($ttls), 2), (int) $interval[1]);
        }
        self::assertStringContainsString('Unit=duo-cloud-preflight-fleet.service', $hostTimer);
        self::assertStringContainsString('Unit=duo-cloud-preflight@%i.service', $workerTimer);
        foreach ([$hostService, $workerService] as $service) {
            self::assertStringContainsString('Type=oneshot', $service);
            self::assertStringContainsString('SupplementaryGroups=docker', $service);
            self::assertStringContainsString('Delegate=yes', $service);
            self::assertStringContainsString('TimeoutStartSec=18s', $service);
            self::assertStringContainsString('StandardError=journal', $service);
        }
        self::assertSame(15, ProductionService::PREFLIGHT_DEADLINE_SECONDS);
        self::assertStringContainsString('duo-cloud-preflight-fleet', $hostService);
        self::assertStringNotContainsString('/workers/%i.env', $hostService);
        self::assertStringContainsString('/workers/%i.env', $workerService);
        self::assertStringContainsString('ExecStart=/opt/duo-cloud/bin/duo-cloud-preflight', $workerService);
        self::assertStringContainsString('request_terminate_timeout = 0', $pool);
        self::assertStringContainsString("[global]\nerror_log = syslog", $pool);
        self::assertStringContainsString('syslog.ident = duo-cloud-fpm-REPLACE_WORKER', $pool);
        self::assertStringContainsString('php_admin_value[max_execution_time] = 0', $pool);
    }

    public function testFleetReaperOwnsThePreservedStrictProofLockRuntime(): void {
        $service = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-reap-expired-fleet.service'
        );
        $source = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/runtime/ProductionDeploymentProofs.php'
        );
        self::assertStringContainsString('RuntimeDirectory=duo-cloud-host-proof', $service);
        self::assertStringContainsString('RuntimeDirectoryMode=0700', $service);
        self::assertStringContainsString('RuntimeDirectoryPreserve=yes', $service);
        self::assertStringContainsString(
            'ReadWritePaths=/var/lib/duo-cloud /run/duo-cloud-host-proof',
            $service
        );
        self::assertStringContainsString('TimeoutStartSec=0', $service);
        self::assertStringContainsString(
            "private const LINUX_HOST_EXECUTION_LOCK = '/run/duo-cloud-host-proof/authority.lock'",
            $source
        );
    }

    public function testFleetManifestExampleIsAdmittedByEverySharedAuthorityExample(): void {
        $fleet = json_decode((string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/fleet-config.json.example'
        ), true, 32, JSON_THROW_ON_ERROR);
        $firewall = json_decode((string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/firewall-authority.json.example'
        ), true, 32, JSON_THROW_ON_ERROR);
        $route = json_decode((string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/route-authority.json.example'
        ), true, 32, JSON_THROW_ON_ERROR);
        $storage = json_decode((string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/storage-authority.json.example'
        ), true, 32, JSON_THROW_ON_ERROR);

        self::assertSame('/usr/bin/ip', $firewall['ip']['path']);

        $firewallWorkers = [];
        foreach ($firewall['principals'] as $principal) {
            $firewallWorkers[$principal['worker_id']] = $principal['configuration_sha256s'];
        }
        $routeConfigurations = array_column(
            $route['principals'],
            'runtime_configuration_sha256'
        );
        $storageConfigurations = array_column(
            $storage['worker_roots'],
            'configuration_sha256'
        );
        foreach ($fleet['workers'] as $worker) {
            self::assertArrayHasKey('retiring_configuration', $worker);
            self::assertNull($worker['retiring_configuration']);
            self::assertContains(
                $worker['configuration_sha256'],
                $firewallWorkers[$worker['worker_id']] ?? []
            );
            self::assertContains($worker['configuration_sha256'], $routeConfigurations);
            self::assertContains($worker['configuration_sha256'], $storageConfigurations);
        }
        self::assertSame($storage['configuration_sha256s'], $storageConfigurations);

        $readme = (string) file_get_contents(DUO_REPO_ROOT . '/cloud/README.md');
        $prose = preg_replace('/\s+/', ' ', $readme);
        self::assertIsString($prose);
        self::assertStringContainsString(
            'Never introduce a second retiring config before completing that drain',
            $prose
        );
        self::assertStringContainsString(
            'Retain every cleanup-required immutable artifact named by the retiring config',
            $prose
        );
        self::assertStringContainsString(
            'drain and reap the old generation before replacement',
            $prose
        );
    }

    public function testReferencedServicesAndNonOverlappingJanitorSchedulesShip(): void {
        $phpFpm = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-php-fpm@.service'
        );
        self::assertStringContainsString('Type=notify', $phpFpm);
        self::assertStringContainsString('php-fpm8.3 --nodaemonize', $phpFpm);
        self::assertStringContainsString('RuntimeDirectory=duo-cloud/%i', $phpFpm);
        self::assertStringContainsString('RuntimeDirectoryMode=0751', $phpFpm);
        self::assertStringContainsString('%i-fpm.conf', $phpFpm);
        self::assertStringContainsString('Delegate=yes', $phpFpm);
        self::assertStringContainsString('SupplementaryGroups=docker', $phpFpm);
        self::assertStringNotContainsString('NoNewPrivileges=true', $phpFpm);
        self::assertStringContainsString('TimeoutStopSec=0', $phpFpm);

        $fleetReapService = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-reap-expired-fleet.service'
        );
        $fleetReapTimer = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-reap-expired-fleet.timer'
        );
        self::assertStringContainsString('Type=oneshot', $fleetReapService);
        self::assertStringContainsString('TimeoutStartSec=0', $fleetReapService);
        self::assertStringContainsString('SupplementaryGroups=docker', $fleetReapService);
        self::assertStringContainsString(
            '/var/lib/duo-cloud/config/fleet.env',
            $fleetReapService
        );
        self::assertStringContainsString(
            'ExecStart=/opt/duo-cloud/bin/duo-cloud-reap-expired-fleet',
            $fleetReapService
        );
        self::assertStringContainsString('OnUnitInactiveSec=20s', $fleetReapTimer);
        self::assertStringNotContainsString('OnUnitActiveSec=', $fleetReapTimer);
        self::assertStringContainsString(
            'Unit=duo-cloud-reap-expired-fleet.service',
            $fleetReapTimer
        );
        self::assertFileDoesNotExist(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-reap-expired@.service'
        );
        self::assertFileDoesNotExist(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-reap-expired@.timer'
        );

        $originService = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-reap-origin@.service'
        );
        $originTimer = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-reap-origin@.timer'
        );
        self::assertStringContainsString('Type=oneshot', $originService);
        self::assertStringContainsString('TimeoutStartSec=0', $originService);
        self::assertStringContainsString('SupplementaryGroups=docker', $originService);
        self::assertStringContainsString(
            '/var/lib/duo-cloud/config/workers/%i.env',
            $originService
        );
        self::assertStringContainsString('OnUnitInactiveSec=45s', $originTimer);
        self::assertStringNotContainsString('OnUnitActiveSec=', $originTimer);
        self::assertStringContainsString(
            'Unit=duo-cloud-reap-origin@%i.service',
            $originTimer
        );
    }

    public function testLegacyPreviewReaperMigrationDrainsBeforeSingletonStarts(): void {
        $readme = (string) file_get_contents(DUO_REPO_ROOT . '/cloud/README.md');
        $disableTimer = strpos(
            $readme,
            'systemctl disable --now "duo-cloud-reap-expired@${worker}.timer"'
        );
        $waitForService = strpos(
            $readme,
            'state=$(systemctl show --property=ActiveState --value'
        );
        $removeLegacyUnits = strpos(
            $readme,
            'rm -f /etc/systemd/system/duo-cloud-reap-expired@.timer'
        );
        $reloadUnits = strpos($readme, 'systemctl daemon-reload');
        $startSingleton = strpos(
            $readme,
            'systemctl enable --now duo-cloud-reap-expired-fleet.timer'
        );

        foreach ([$disableTimer, $waitForService, $removeLegacyUnits, $reloadUnits, $startSingleton] as $step) {
            self::assertIsInt($step);
        }
        $failClosed = strpos($readme, 'set -euo pipefail');
        self::assertIsInt($failClosed);
        self::assertLessThan($disableTimer, $failClosed);
        self::assertLessThan($waitForService, $disableTimer);
        self::assertLessThan($removeLegacyUnits, $waitForService);
        self::assertLessThan($reloadUnits, $removeLegacyUnits);
        self::assertLessThan($startSingleton, $reloadUnits);
        self::assertStringContainsString(
            'Never stop, kill, or restart an active legacy service.',
            $readme
        );
        self::assertStringContainsString('active|activating|deactivating|reloading) sleep 1 ;;', $readme);
        self::assertStringContainsString('inactive) break ;;', $readme);
        self::assertStringContainsString('legacy reaper timer ${worker} stayed ${timer_state}', $readme);
        self::assertStringContainsString(
            'systemctl is-enabled --quiet "duo-cloud-reap-expired@${worker}.timer"',
            $readme
        );
        self::assertStringContainsString(
            '`TimeoutStartSec=0`, so systemd imposes no start deadline.',
            $readme
        );
        self::assertStringNotContainsString('no asynchronous supervisor kill', $readme);
    }

    public function testLinuxHostVerifierIsExecutableAndCoversTheLiveBoundaries(): void {
        $path = DUO_REPO_ROOT . '/cloud/deploy/verify-linux-host-boundaries.php';
        $verifier = (string) file_get_contents($path);

        self::assertTrue(is_executable($path));
        self::assertStringStartsWith("#!/usr/bin/env php\n<?php\n", $verifier);
        foreach ([
            'duo-cloud-linux-host-boundary-proof/v1',
            'duo-cloud-seccomp-ioctl-canary/v1',
            'gateway-tcp=denied',
            'gateway-udp=denied',
            'external-tcp=denied',
            'dns-tcp=denied',
            'dns-udp=denied',
            'host-to-workload health ingress failed',
            'firewall multi-binding readback was clobbered',
            'capless quota fill',
            'capless quota inode fill',
            'duo-cloud-xfs-quota-host-preflight-proof/v1',
            'proof_receipt_sha256',
            '/run/duo-cloud-host-proof/authority.lock',
            'LOCK_EX | LOCK_NB',
            'host verifier could not prove exact cleanup',
        ] as $evidence) {
            self::assertStringContainsString($evidence, $verifier);
        }

        $service = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-verify-linux-host-boundaries@.service'
        );
        self::assertStringContainsString('User=duo-cloud', $service);
        self::assertStringContainsString('SupplementaryGroups=docker', $service);
        self::assertStringContainsString(
            'EnvironmentFile=/var/lib/duo-cloud/config/workers/%i-host-proof.env',
            $service
        );
        self::assertStringContainsString('RuntimeDirectory=duo-cloud-host-proof', $service);
        self::assertStringContainsString('Delegate=yes', $service);
        self::assertStringContainsString('TimeoutStartSec=0', $service);
        self::assertStringNotContainsString('NoNewPrivileges=true', $service);
        foreach (file(
            DUO_REPO_ROOT . '/cloud/deploy/worker-host-proof.env.example',
            FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
        ) ?: [] as $line) {
            if (str_starts_with($line, '#')) {
                continue;
            }
            self::assertMatchesRegularExpression(
                '/\ADUO_CLOUD_PROOF_[A-Z0-9_]+=(?:REPLACE_.*|sha256:REPLACE_.*|\/.*)\z/D',
                $line
            );
        }

        $readme = (string) file_get_contents(DUO_REPO_ROOT . '/cloud/README.md');
        self::assertStringContainsString('dedicated nonproduction Linux host', $readme);
    }

    public function testControlEdgeContainsOnlyTheClosedExactHttpRoutes(): void {
        $caddy = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/control-edge.Caddyfile.example'
        );
        foreach ([
            '/v1/preview/control',
            '/v1/preview/lifecycle',
            '/v1/origin/controller/export',
            '/v1/origin/pair/begin',
            '/v1/origin/pair/poll',
            '/v1/origin/demand/poll',
            '/v1/origin/export/announce',
            '/v1/origin/export/missing',
            '/v1/origin/export/chunk',
            '/v1/origin/export/commit',
            '/v1/origin/key/rotate',
            '/v1/origin/revoke',
        ] as $path) {
            self::assertStringContainsString($path, $caddy);
        }
        self::assertStringNotContainsString('/v1/origin/*', $caddy);
        self::assertStringContainsString('admin off', $caddy);
        self::assertStringContainsString("http://:8081 {\n\tbind 127.0.0.1", $caddy);
        self::assertStringContainsString('max_size 2MiB', $caddy);
        self::assertStringContainsString(
            'env SCRIPT_FILENAME /opt/duo-cloud/bin/duo-cloud-http',
            $caddy
        );
        $front = (string) file_get_contents(DUO_REPO_ROOT . '/cloud/bin/duo-cloud-http');
        self::assertStringStartsWith("<?php\ndeclare(strict_types=1);\n", $front);
        self::assertStringNotContainsString('#!', substr($front, 0, 64));
        self::assertFalse(is_executable(DUO_REPO_ROOT . '/cloud/bin/duo-cloud-http'));
        $routeInitial = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/route-caddy-initial.json'
        );
        $routeDocument = json_decode(
            $routeInitial,
            true,
            32,
            JSON_THROW_ON_ERROR
        );
        self::assertSame('127.0.0.1:2019', $routeDocument['admin']['listen']);
        self::assertSame(
            [['protocol_min' => 'tls1.2']],
            $routeDocument['apps']['http']['servers']['duo_previews']['tls_connection_policies']
        );
        $routeService = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-route-caddy.service'
        );
        self::assertStringContainsString(
            'caddy run --environ --config /opt/duo-cloud/deploy/route-caddy-initial.json',
            $routeService
        );
        self::assertStringNotContainsString('--adapter', $routeService);
    }

    public function testTwoControlHostsMapToDisjointWorkerSocketsWithNoFallback(): void {
        $caddy = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/control-edge.Caddyfile.example'
        );
        $siteAStart = strpos($caddy, '@worker_site_a');
        $siteBStart = strpos($caddy, '@worker_site_b');
        $fallback = strrpos($caddy, "\thandle {");
        self::assertIsInt($siteAStart);
        self::assertIsInt($siteBStart);
        self::assertIsInt($fallback);
        self::assertLessThan($siteBStart, $siteAStart);
        self::assertLessThan($fallback, $siteBStart);
        $siteA = substr($caddy, $siteAStart, $siteBStart - $siteAStart);
        $siteB = substr($caddy, $siteBStart, $fallback - $siteBStart);
        self::assertStringContainsString('host site-a.control.example.invalid', $siteA);
        self::assertStringContainsString('/run/duo-cloud/site-a/php-fpm.sock', $siteA);
        self::assertStringNotContainsString('site-b', $siteA);
        self::assertStringContainsString('host site-b.control.example.invalid', $siteB);
        self::assertStringContainsString('/run/duo-cloud/site-b/php-fpm.sock', $siteB);
        self::assertStringNotContainsString('site-a', $siteB);
        self::assertStringContainsString("\thandle {\n\t\trespond 404", substr($caddy, $fallback));

        $pool = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/php-fpm-pool.conf.example'
        );
        $controlService = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/duo-cloud-control-caddy.service'
        );
        self::assertStringContainsString('/run/duo-cloud/REPLACE_WORKER/php-fpm.sock', $pool);
        self::assertStringContainsString('workers/REPLACE_WORKER.json', $pool);
        self::assertStringContainsString('listen.owner = duo-cloud', $pool);
        self::assertStringContainsString('listen.group = duo-cloud', $pool);
        self::assertStringContainsString('listen.mode = 0660', $pool);
        self::assertStringContainsString('SupplementaryGroups=duo-cloud', $controlService);
        self::assertStringNotContainsString('ExecReload=', $controlService);
        self::assertStringNotContainsString('127.0.0.1:2019', $controlService);
    }

    public function testExampleConfigurationsShareOneEncryptedDurableLayout(): void {
        $production = json_decode((string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/production-config.json.example'
        ), true, 64, JSON_THROW_ON_ERROR);
        $storage = json_decode((string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/storage-authority.json.example'
        ), true, 64, JSON_THROW_ON_ERROR);
        self::assertSame('/var/lib/duo-cloud', $storage['mountpoint']);
        self::assertSame('/var/lib/duo-cloud/docker', $storage['docker_root']);
        self::assertSame(['/var/lib/duo-cloud'], $storage['durable_paths']);

        $durable = array_values($production['host_durable_paths']);
        $durable[] = $production['state_root'];
        $durable[] = $production['runtime']['repository_source'];
        $durable[] = $production['runtime']['snapshot_object_root'];
        $durable[] = $production['service']['device_digest_key']['path'];
        $durable[] = $production['service']['response_signing_key']['path'];
        $durable[] = $production['controller_keys'][0]['public_key']['path'];
        foreach ($durable as $path) {
            self::assertStringStartsWith('/var/lib/duo-cloud/', $path . '/');
        }
        foreach (glob(DUO_REPO_ROOT . '/cloud/deploy/*.json.example') ?: [] as $path) {
            $bytes = (string) file_get_contents($path);
            self::assertStringContainsString('REPLACE_', $bytes);
            self::assertIsArray(json_decode($bytes, true, 64, JSON_THROW_ON_ERROR));
        }
    }

    public function testPrivilegedClosureExamplesCoverContainerRuntimeDependencies(): void {
        foreach (['firewall', 'storage'] as $authority) {
            $document = json_decode((string) file_get_contents(
                DUO_REPO_ROOT . "/cloud/deploy/$authority-client.json.example"
            ), true, 64, JSON_THROW_ON_ERROR);
            $paths = array_column($document['closure'], 'path');
            $sorted = $paths;
            sort($sorted, SORT_STRING);
            self::assertSame($sorted, $paths);
            self::assertSame(count($paths), count(array_unique($paths)));
            self::assertContains('/opt/duo-cloud/runtime/RuntimeSlotLock.php', $paths);
            if ($authority === 'firewall') {
                self::assertContains(
                    '/opt/duo-cloud/runtime/FirewallAuthorityArguments.php',
                    $paths
                );
            }
            self::assertContains('/opt/duo-cloud/src/ContainerWorkloadRuntime.php', $paths);
            self::assertContains('/opt/duo-cloud/src/HostAuthorityBusy.php', $paths);
            self::assertContains('/opt/duo-cloud/src/ImmutableOciReference.php', $paths);
            self::assertContains('/opt/duo-cloud/src/WorkloadSecurityInspection.php', $paths);
        }
    }

    public function testSeccompProfileHasClosedIoctlAndSocketPoliciesForEveryAbi(): void {
        $profile = json_decode((string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/deploy/seccomp-profile.json'
        ), true, 64, JSON_THROW_ON_ERROR);
        $broadNames = $profile['syscalls'][0]['names'];
        self::assertNotContains('ioctl', $broadNames);
        self::assertNotContains('socket', $broadNames);
        self::assertNotContains('socketcall', $broadNames);

        $socketRules = [];
        $ioctlRules = [];
        foreach ($profile['syscalls'] as $rule) {
            if (($rule['names'] ?? null) === ['socket']) {
                $socketRules[] = $rule['args'][0] ?? null;
            }
            if (($rule['names'] ?? null) === ['ioctl']) {
                $ioctlRules[] = $rule['args'][0] ?? null;
            }
        }
        self::assertSame([
            ['index' => 0, 'value' => 38, 'op' => 'SCMP_CMP_LT'],
            ['index' => 0, 'value' => 39, 'op' => 'SCMP_CMP_EQ'],
            ['index' => 0, 'value' => 40, 'op' => 'SCMP_CMP_GT'],
        ], $socketRules);
        self::assertSame([
            ['index' => 1, 'value' => 21505, 'op' => 'SCMP_CMP_EQ'],
            ['index' => 1, 'value' => 21531, 'op' => 'SCMP_CMP_EQ'],
            ['index' => 1, 'value' => 3223348747, 'op' => 'SCMP_CMP_EQ'],
        ], $ioctlRules);
        self::assertSame([
            'SCMP_ARCH_X86', 'SCMP_ARCH_X32',
        ], $profile['archMap'][0]['subArchitectures']);

        $canary = (string) file_get_contents(
            DUO_REPO_ROOT . '/cloud/runtime/image/containment-canary.c'
        );
        foreach (['FS_IOC_FSSETXATTR', 'FS_IOC_SETFLAGS', 'DUO_FS_IOC32_SETFLAGS'] as $name) {
            self::assertStringContainsString($name, $canary);
        }
        self::assertStringContainsString('__NR_socketcall', $canary);
        self::assertStringContainsString('denied_socket(38)', $canary);
        self::assertStringContainsString('denied_socket(40)', $canary);
    }
}
