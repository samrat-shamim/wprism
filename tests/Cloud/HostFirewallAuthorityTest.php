<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\ContainerArgvProcessRunner;
use Duo\Cloud\ControlRefusal;
use Duo\Cloud\HostAuthorityBusy;
use Duo\Cloud\HostFirewallAuthority;
use Duo\Cloud\HostFirewallConfig;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/runtime/HostFirewallAuthority.php';
require_once DUO_REPO_ROOT . '/cloud/runtime/HostFirewallConfig.php';

#[CoversNothing]
final class HostFirewallAuthorityTest extends TestCase {
    private string $scratch;
    private FirewallKernelFake $kernel;
    private HostFirewallAuthority $authority;
    private HostFirewallAuthority $authorityB;
    private string $configurationA;
    private string $configurationB;

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-firewall-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
        $this->configurationA = hash('sha256', 'configuration-a');
        $this->configurationB = hash('sha256', 'configuration-b');
        $principals = [
            [
                'configuration_sha256s' => [$this->configurationA],
                'service_uid' => 20001,
                'worker_id' => 'site-a',
            ],
            [
                'configuration_sha256s' => [$this->configurationB],
                'service_uid' => 20001,
                'worker_id' => 'site-b',
            ],
        ];
        usort($principals, static fn (array $left, array $right): int => strcmp(
            $left['worker_id'],
            $right['worker_id']
        ));
        $this->kernel = new FirewallKernelFake();
        $this->authority = new HostFirewallAuthority(
            $this->kernel,
            '/bin/echo',
            '/usr/bin/true',
            '/usr/bin/false',
            $this->scratch,
            $principals,
            20001
        );
        $this->authorityB = new HostFirewallAuthority(
            $this->kernel,
            '/bin/echo',
            '/usr/bin/true',
            '/usr/bin/false',
            $this->scratch,
            $principals,
            20001
        );
    }

    protected function tearDown(): void {
        $this->remove($this->scratch);
    }

    public function testTwoPrincipalsBindAndOneUnbindCannotClobberTheOther(): void {
        $bindingA = $this->binding($this->configurationA, 'a', '172.30.0.0/24', '172.30.0.1');
        $bindingB = $this->binding($this->configurationB, 'b', '172.31.0.0/24', '172.31.0.1');
        $this->kernel->addBridge($bindingA);
        $this->kernel->addBridge($bindingB);

        self::assertSame('bound', $this->authority->bind($bindingA)['state']);
        self::assertSame('bound', $this->authorityB->bind($bindingB)['state']);
        self::assertSame(6, $this->kernel->ruleCount());
        self::assertStringNotContainsString('ip saddr', $this->kernel->lastBatch());
        self::assertSame(6, substr_count($this->kernel->lastBatch(), 'iifname'));
        self::assertSame('bound', $this->authority->inspect(
            $this->configurationA,
            $bindingA['network_name']
        )['state']);

        self::assertSame('absent', $this->authority->unbind(
            $this->configurationA,
            $bindingA['network_name']
        )['state']);
        self::assertSame(3, $this->kernel->ruleCount());
        self::assertSame('bound', $this->authorityB->inspect(
            $this->configurationB,
            $bindingB['network_name']
        )['state']);
        self::assertSame(1, $this->authority->preflight()['bindings']);

        self::assertSame('bound', $this->authority->inspect(
            $this->configurationB,
            $bindingB['network_name']
        )['state']);
    }

    public function testChangedKernelReadbackRefusesBeforeMutatingDedicatedTable(): void {
        $binding = $this->binding($this->configurationA, 'c', '172.32.0.0/24', '172.32.0.1');
        $this->kernel->addBridge($binding);
        $this->authority->bind($binding);
        $this->kernel->tamperFirstComment();

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('readback differs');
        $this->authority->inspect($this->configurationA, $binding['network_name']);
    }

    public function testSemanticRuleOrChainWeakeningRefusesExactReadback(): void {
        $binding = $this->binding($this->configurationA, 'semantic', '172.34.0.0/24', '172.34.0.1');
        $this->kernel->addBridge($binding);
        $this->authority->bind($binding);
        $this->kernel->removeFirstInterfaceMatch();

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('semantic readback');
        $this->authority->inspect($this->configurationA, $binding['network_name']);
    }

    public function testHeldGlobalLockRefusesWithoutWaiting(): void {
        $handle = fopen($this->scratch . '/authority.lock', 'c+b');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        $started = microtime(true);
        try {
            $this->authority->preflight();
            self::fail('held firewall lock did not refuse');
        } catch (HostAuthorityBusy $error) {
            self::assertSame('firewall authority lock is busy', $error->getMessage());
            self::assertLessThan(0.25, microtime(true) - $started);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testUnsafeFirewallLockPathIsPermanentNotTransient(): void {
        self::assertTrue(mkdir($this->scratch . '/authority.lock', 0700));

        try {
            $this->authority->preflight();
            self::fail('directory at the firewall lock path was accepted');
        } catch (ControlRefusal $error) {
            self::assertSame(
                'firewall authority lock could not be acquired privately',
                $error->getMessage()
            );
        }
    }

    public function testWorkerPreflightReadsStableSnapshotWithoutTakingGlobalLock(): void {
        $binding = $this->binding($this->configurationA, 'local-lock', '172.40.0.0/24', '172.40.0.1');
        $this->kernel->addBridge($binding);
        $this->authority->bind($binding);
        $handle = fopen($this->scratch . '/authority.lock', 'c+b');
        self::assertIsResource($handle);
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));
        $started = microtime(true);
        try {
            $result = $this->authority->workerPreflight($this->configurationA);
            self::assertSame('ready', $result['state']);
            self::assertSame(1, $result['bindings']);
            self::assertLessThan(0.25, microtime(true) - $started);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testTrustedControlPlaneReapsRetiringConfigAcrossSharedServiceUid(): void {
        $retiring = hash('sha256', 'configuration-a-retiring');
        $rotation = [$retiring, $this->configurationA];
        sort($rotation, SORT_STRING);
        $principals = [[
            'configuration_sha256s' => $rotation,
            'service_uid' => 20001,
            'worker_id' => 'site-a',
        ], [
            'configuration_sha256s' => [$this->configurationB],
            'service_uid' => 20001,
            'worker_id' => 'site-b',
        ]];
        $authority = new HostFirewallAuthority(
            $this->kernel,
            '/bin/echo',
            '/usr/bin/true',
            '/usr/bin/false',
            $this->scratch,
            $principals,
            20001
        );
        $binding = $this->binding($retiring, 'rotation', '172.35.0.0/24', '172.35.0.1');
        $this->kernel->addBridge($binding);
        self::assertSame('bound', $authority->bind($binding)['state']);
        self::assertSame('absent', $authority->unbind($retiring, $binding['network_name'])['state']);

        self::assertSame('absent', $authority->inspect(
            $this->configurationB,
            $binding['network_name']
        )['state']);
    }

    public function testCrashAfterNftPublishRecoversApplyingJournalAndExactRules(): void {
        $binding = $this->binding($this->configurationA, 'd', '172.33.0.0/24', '172.33.0.1');
        $this->kernel->addBridge($binding);
        $this->kernel->failReadbackAfterApply = true;
        try {
            $this->authority->bind($binding);
            self::fail('simulated post-publish readback loss did not refuse');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('ruleset readback', $error->getMessage());
        }

        self::assertSame('ready', $this->authority->preflight()['state']);
        self::assertSame('bound', $this->authority->inspect(
            $this->configurationA,
            $binding['network_name']
        )['state']);
        self::assertSame(3, $this->kernel->ruleCount());
    }

    public function testExplicitStartupReconcileRestoresStableRulesLostAcrossHostReboot(): void {
        $binding = $this->binding($this->configurationA, 'reboot', '172.36.0.0/24', '172.36.0.1');
        $this->kernel->addBridge($binding);
        $this->authority->bind($binding);
        $this->kernel->loseOwnedTable();
        try {
            $this->authority->preflight();
            self::fail('ordinary preflight accepted missing stable firewall rules');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('absent', $error->getMessage());
        }

        self::assertSame('reconciled', $this->authority->reconcile()['state']);
        self::assertSame(3, $this->kernel->ruleCount());
        self::assertSame('ready', $this->authority->preflight()['state']);
    }

    public function testMissingWorkerBridgeIsLocalWhileHostAndPeerStayReady(): void {
        $bindingA = $this->binding($this->configurationA, 'local-a', '172.38.0.0/24', '172.38.0.1');
        $bindingB = $this->binding($this->configurationB, 'local-b', '172.39.0.0/24', '172.39.0.1');
        $this->kernel->addBridge($bindingA);
        $this->kernel->addBridge($bindingB);
        $this->authority->bind($bindingA);
        $this->authorityB->bind($bindingB);
        self::assertSame(1, $this->authority->workerPreflight($this->configurationA)['bindings']);
        self::assertSame(1, $this->authorityB->workerPreflight($this->configurationB)['bindings']);

        $this->kernel->removeBridge($bindingB['bridge_name']);
        self::assertSame('reconciled', $this->authority->reconcile()['state']);
        self::assertSame('ready', $this->authority->preflight()['state']);
        self::assertSame('ready', $this->authority->workerPreflight(
            $this->configurationA
        )['state']);
        try {
            $this->authorityB->workerPreflight($this->configurationB);
            self::fail('missing worker B bridge passed its principal-scoped preflight');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('network readback', $error->getMessage());
        }
    }

    public function testCrashBeforeUnbindPublishRecoversFromOldRuleSuperset(): void {
        $bindingA = $this->binding($this->configurationA, 'old-a', '172.37.0.0/24', '172.37.0.1');
        $bindingB = $this->binding($this->configurationB, 'old-b', '172.38.0.0/24', '172.38.0.1');
        $this->kernel->addBridge($bindingA);
        $this->kernel->addBridge($bindingB);
        $this->authority->bind($bindingA);
        $this->authorityB->bind($bindingB);
        $this->kernel->failNextApplyBeforePublish = true;
        try {
            $this->authority->unbind($this->configurationA, $bindingA['network_name']);
            self::fail('simulated pre-publish loss did not refuse');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('publish failed', $error->getMessage());
        }

        self::assertSame('ready', $this->authorityB->preflight()['state']);
        self::assertSame(3, $this->kernel->ruleCount());
        self::assertSame('bound', $this->authorityB->inspect(
            $this->configurationB,
            $bindingB['network_name']
        )['state']);
    }

    public function testKilledStatePublicationLeavesOneDestinationBoundResidueAndRetriesExactly(): void {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')
            || !function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and POSIX signals are required for the firewall crash regression');
        }
        $binding = $this->binding($this->configurationA, 'state-kill', '172.41.0.0/24', '172.41.0.1');
        $this->kernel->addBridge($binding);
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            putenv('DUO_TEST_HOST_FIREWALL_KILL_PHASE=state-temporary-synchronized');
            $this->authority->bind($binding);
            exit(71);
        }
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifsignaled($status));
        self::assertSame(SIGKILL, pcntl_wtermsig($status));
        $temporary = $this->scratch . '/authority.json.tmp';
        self::assertFileExists($temporary);
        self::assertCount(1, glob($temporary . '*') ?: []);

        self::assertSame('bound', $this->authority->bind($binding)['state']);
        self::assertSame([], glob($temporary . '*') ?: []);
        self::assertSame(3, $this->kernel->ruleCount());
    }

    public function testKilledNftTransactionLeavesOneBoundedBatchAndApplyingJournalRetriesExactly(): void {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')
            || !function_exists('posix_kill')) {
            self::markTestSkipped('pcntl and POSIX signals are required for the firewall crash regression');
        }
        $binding = $this->binding($this->configurationA, 'nft-kill', '172.42.0.0/24', '172.42.0.1');
        $this->kernel->addBridge($binding);
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            putenv('DUO_TEST_HOST_FIREWALL_KILL_PHASE=nft-batch-synchronized');
            $this->authority->bind($binding);
            exit(72);
        }
        pcntl_waitpid($pid, $status);
        self::assertTrue(pcntl_wifsignaled($status));
        self::assertSame(SIGKILL, pcntl_wtermsig($status));
        $batch = $this->scratch . '/authority.nft';
        self::assertFileExists($batch);
        self::assertCount(1, glob($this->scratch . '/*.nft*') ?: []);
        $applying = CanonicalJson::decodeObject(
            (string) file_get_contents($this->scratch . '/authority.json')
        );
        self::assertSame('applying', $applying['phase']);

        self::assertSame('ready', $this->authority->preflight()['state']);
        self::assertFileDoesNotExist($batch);
        self::assertSame('bound', $this->authority->inspect(
            $this->configurationA,
            $binding['network_name']
        )['state']);
        self::assertSame(3, $this->kernel->ruleCount());
    }

    public function testUnsafeDeterministicResiduesRefuseWithoutFollowingThem(): void {
        $outside = $this->scratch . '/outside';
        self::assertSame(8, file_put_contents($outside, "outside\n"));
        self::assertTrue(chmod($outside, 0600));
        foreach (['authority.json.tmp', 'authority.nft'] as $residue) {
            $path = $this->scratch . '/' . $residue;
            self::assertTrue(symlink($outside, $path));
            try {
                $this->authority->preflight();
                self::fail("unsafe $residue residue was accepted");
            } catch (ControlRefusal $error) {
                self::assertStringContainsString('must be a process-owned', $error->getMessage());
            }
            self::assertTrue(is_link($path));
            self::assertSame("outside\n", file_get_contents($outside));
            self::assertTrue(unlink($path));
        }
    }

    public function testClosedPinnedConfigRefusesUnknownFields(): void {
        $nft = $this->executable('nft');
        $ip = $this->executable('ip');
        $engine = $this->executable('engine');
        $principals = [
            [
                'configuration_sha256s' => [$this->configurationA],
                'service_uid' => 20001,
                'worker_id' => 'site-a',
            ],
            [
                'configuration_sha256s' => [$this->configurationB],
                'service_uid' => 20001,
                'worker_id' => 'site-b',
            ],
        ];
        usort($principals, static fn (array $left, array $right): int => strcmp(
            $left['worker_id'],
            $right['worker_id']
        ));
        $document = [
            'container_engine' => $engine,
            'format' => HostFirewallConfig::FORMAT,
            'ip' => $ip,
            'nft' => $nft,
            'principals' => $principals,
            'process_timeout_seconds' => 5,
            'state_root' => $this->scratch,
        ];
        $path = $this->scratch . '/firewall.json';
        $bytes = CanonicalJson::encode($document) . "\n";
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        self::assertTrue(chmod($path, 0600));
        $config = HostFirewallConfig::load($path, hash('sha256', $bytes));
        self::assertSame($principals, $config->principals());

        $document['unknown'] = true;
        $changed = CanonicalJson::encode($document) . "\n";
        self::assertSame(strlen($changed), file_put_contents($path, $changed));
        $this->expectException(ControlRefusal::class);
        HostFirewallConfig::load($path, hash('sha256', $changed));
    }

    /** @return array<string,string> */
    private function binding(string $configuration, string $suffix, string $subnet, string $gateway): array {
        $token = hash('sha256', 'network-' . $suffix);
        $name = 'duo-preview-net-' . $token . '-g0000000001';
        return [
            'bridge_name' => HostFirewallAuthority::bridgeName($name),
            'configuration_sha256' => $configuration,
            'gateway' => $gateway,
            'network_id' => hash('sha256', 'id-' . $suffix),
            'network_name' => $name,
            'subnet' => $subnet,
        ];
    }

    /** @return array{path:string,sha256:string} */
    private function executable(string $name): array {
        $path = $this->scratch . '/' . $name;
        $bytes = "#!/bin/sh\n" . ($name === 'engine' ? str_repeat('#', 1048576) : '')
            . "\nexit 0\n";
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        self::assertTrue(chmod($path, 0700));
        return ['path' => $path, 'sha256' => hash('sha256', $bytes)];
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

final class FirewallKernelFake implements ContainerArgvProcessRunner {
    /** @var array<string,array<string,string>> */
    private array $bridges = [];
    /** @var list<array<string,mixed>> */
    private array $owned = [];
    private string $lastBatch = '';
    public bool $failReadbackAfterApply = false;
    public bool $failNextApplyBeforePublish = false;
    private bool $justApplied = false;

    /** @param array<string,string> $binding */
    public function addBridge(array $binding): void {
        $this->bridges[$binding['bridge_name']] = $binding;
    }

    public function removeBridge(string $bridge): void {
        unset($this->bridges[$bridge]);
    }

    public function ruleCount(): int {
        return count(array_filter(
            $this->owned,
            static fn (array $object): bool => isset($object['rule'])
        ));
    }

    public function lastBatch(): string {
        return $this->lastBatch;
    }

    public function tamperFirstComment(): void {
        foreach ($this->owned as &$object) {
            if (isset($object['rule'])) {
                $object['rule']['comment'] = 'foreign';
                return;
            }
        }
    }

    public function removeFirstInterfaceMatch(): void {
        foreach ($this->owned as &$object) {
            if (isset($object['rule']['expr'][0]['match'])) {
                array_shift($object['rule']['expr']);
                return;
            }
        }
    }

    public function loseOwnedTable(): void {
        $this->owned = [];
    }

    public function run(
        array $argv,
        ?string $stdinFile = null,
        ?int $timeoutSeconds = null
    ): array {
        if ($argv[0] === '/bin/echo') {
            $name = $argv[count($argv) - 1];
            foreach ($this->bridges as $binding) {
                if ($binding['network_name'] === $name) {
                    return self::result(0, json_encode([
                        'Driver' => 'bridge',
                        'EnableIPv6' => false,
                        'IPAM' => ['Config' => [[
                            'Gateway' => $binding['gateway'],
                            'Subnet' => $binding['subnet'],
                        ]]],
                        'Id' => $binding['network_id'],
                        'Internal' => true,
                        'Name' => $binding['network_name'],
                        'Options' => [
                            'com.docker.network.bridge.enable_icc' => 'false',
                            'com.docker.network.bridge.enable_ip_masquerade' => 'false',
                            'com.docker.network.bridge.name' => $binding['bridge_name'],
                        ],
                    ], JSON_THROW_ON_ERROR) . "\n");
                }
            }
            return self::result(1, '', 'missing Docker network');
        }
        if ($argv[0] === '/usr/bin/false') {
            return $this->ip($argv);
        }
        if ($argv[0] !== '/usr/bin/true') {
            return self::result(127, '', 'unexpected executable');
        }
        if (($argv[1] ?? null) === '--json') {
            if ($this->justApplied && $this->failReadbackAfterApply) {
                $this->justApplied = false;
                $this->failReadbackAfterApply = false;
                return self::result(1, '', 'lost readback');
            }
            return self::result(0, json_encode([
                'nftables' => array_merge([['metainfo' => ['json_schema_version' => 1]]], $this->owned),
            ], JSON_THROW_ON_ERROR) . "\n");
        }
        if (($argv[1] ?? null) === '--check' && ($argv[2] ?? null) === '--file') {
            return self::result(0);
        }
        if (($argv[1] ?? null) === '--file') {
            if ($this->failNextApplyBeforePublish) {
                $this->failNextApplyBeforePublish = false;
                return self::result(1, '', 'publish failed');
            }
            $bytes = file_get_contents($argv[2]);
            if (!is_string($bytes)) {
                return self::result(2, '', 'missing batch');
            }
            $this->apply($bytes);
            $this->justApplied = true;
            return self::result(0);
        }
        return self::result(2, '', 'unexpected nft argv');
    }

    /** @return array{exit:int,stderr:string,stdout:string} */
    private function ip(array $argv): array {
        $bridge = $argv[count($argv) - 1];
        if (!isset($this->bridges[$bridge])) {
            return self::result(1, '', 'missing bridge');
        }
        if (in_array('link', $argv, true)) {
            return self::result(0, json_encode([[
                'ifname' => $bridge,
                'linkinfo' => ['info_kind' => 'bridge'],
            ]], JSON_THROW_ON_ERROR) . "\n");
        }
        $binding = $this->bridges[$bridge];
        $prefix = (int) explode('/', $binding['subnet'], 2)[1];
        return self::result(0, json_encode([[
            'addr_info' => [[
                'family' => 'inet',
                'local' => $binding['gateway'],
                'prefixlen' => $prefix,
            ]],
            'ifname' => $bridge,
        ]], JSON_THROW_ON_ERROR) . "\n");
    }

    private function apply(string $batch): void {
        $this->lastBatch = $batch;
        $this->owned = [];
        if (!str_contains($batch, 'add table inet duo_cloud_preview')) {
            return;
        }
        $this->owned[] = ['table' => [
            'family' => 'inet', 'handle' => 1, 'name' => 'duo_cloud_preview',
        ]];
        foreach (['input', 'forward'] as $index => $chain) {
            $this->owned[] = ['chain' => [
                'family' => 'inet',
                'handle' => $index + 2,
                'hook' => $chain,
                'name' => $chain,
                'policy' => 'accept',
                'prio' => -5,
                'table' => 'duo_cloud_preview',
                'type' => 'filter',
            ]];
        }
        preg_match_all(
            '/add rule inet duo_cloud_preview (input|forward) iifname "([^"]+)"'
                . '(?: ct state established accept| drop) comment "([^"]+)"/',
            $batch,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $index => $match) {
            $expression = [[
                'match' => [
                    'left' => ['meta' => ['key' => 'iifname']],
                    'op' => '==',
                    'right' => $match[2],
                ],
            ]];
            if (str_ends_with($match[3], 'input-established')) {
                $expression[] = [
                    'match' => [
                        'left' => ['ct' => ['key' => 'state']],
                        'op' => 'in',
                        'right' => 'established',
                    ],
                ];
                $expression[] = ['accept' => null];
            } else {
                $expression[] = ['drop' => null];
            }
            $this->owned[] = ['rule' => [
                'chain' => $match[1],
                'comment' => $match[3],
                'expr' => $expression,
                'family' => 'inet',
                'handle' => $index + 4,
                'table' => 'duo_cloud_preview',
            ]];
        }
    }

    /** @return array{exit:int,stderr:string,stdout:string} */
    private static function result(int $exit, string $stdout = '', string $stderr = ''): array {
        return ['exit' => $exit, 'stderr' => $stderr, 'stdout' => $stdout];
    }
}
