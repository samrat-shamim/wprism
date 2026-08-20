<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CaddyRouteAuthority;
use Duo\Cloud\CanonicalJson;
use Duo\Cloud\ContainerArgvProcessRunner;
use Duo\Cloud\ControlRefusal;
use Duo\Cloud\HostAuthorityBusy;
use Duo\Cloud\RouteAuthorityConfig;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/runtime/CaddyRouteAuthority.php';

#[CoversNothing]
final class CaddyRouteAuthorityTest extends TestCase {
    private string $scratch;
    private string $configurationSha256;
    private string $reviewedBaseSha256;
    private string $token;

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-route-authority-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
        self::assertTrue(mkdir($this->scratch . '/state', 0700));
        self::assertTrue(chmod($this->scratch . '/state', 0700));
        $this->configurationSha256 = hash('sha256', 'runtime-config');
        $this->reviewedBaseSha256 = hash('sha256', 'reviewed-base');
        $this->token = hash('sha256', 'tenant/site');
    }

    protected function tearDown(): void {
        $this->remove($this->scratch);
    }

    public function testBindInspectAndUnbindProveExactContainerNetworkCaddyAndTlsState(): void {
        $runner = new CaddyRouteFakeRunner();
        $config = $this->config();
        $authority = new CaddyRouteAuthority($config, $runner);
        $input = $this->bindInput('0000000001');

        $bound = $authority->execute('bind', $input);
        self::assertSame('bound', $bound['state']);
        self::assertSame($input['host'], $bound['host']);
        self::assertSame($input['upstream_container'], $bound['upstream_container']);
        self::assertSame(8080, $bound['upstream_port']);
        self::assertGreaterThanOrEqual(1, $runner->tlsProbes);
        self::assertStringContainsString($input['host'], $runner->activeConfig);
        self::assertSame(1, $runner->loadRequests);
        self::assertStringContainsString(
            '"tls_connection_policies":[{"protocol_min":"tls1.2"}]',
            $runner->activeConfig
        );
        self::assertSame([
            'format' => 'duo-cloud-route-authority-host-preflight/v1',
            'principals' => $config->get('principals'),
            'routes' => 1,
            'state' => 'ready',
        ], $authority->execute('host-preflight', []));
        $worker = $authority->execute('worker-preflight', $this->workerInput());
        self::assertSame($this->configurationSha256, $worker['configuration_sha256']);
        self::assertSame('duo-cloud-route-authority-worker-preflight/v1', $worker['format']);
        self::assertSame($this->reviewedBaseSha256, $worker['reviewed_base_sha256']);
        self::assertSame(hash(
            'sha256',
            "duo-cloud-route-authority-worker-preflight-bindings/v1\0"
                . CanonicalJson::encode([[
                    'host' => $input['host'],
                    'route_id' => $input['route_id'],
                    'upstream_container' => $input['upstream_container'],
                    'upstream_ip' => '172.31.0.2',
                    'upstream_network' => $input['upstream_network'],
                    'upstream_port' => 8080,
                ]])
        ), $worker['route_bindings_sha256']);
        self::assertSame(1, $worker['routes']);
        self::assertSame('ready', $worker['state']);

        self::assertSame($bound, $authority->execute('inspect', $this->commonInput()));
        $absent = $authority->execute('unbind', $this->commonInput());
        self::assertSame('absent', $absent['state']);
        self::assertStringNotContainsString($input['host'], $runner->activeConfig);
        self::assertSame($absent, $authority->execute('inspect', $this->commonInput()));
    }

    public function testHostPreflightReportsOnlyExactRouteLockContentionAsBusy(): void {
        $authority = new CaddyRouteAuthority($this->config(), new CaddyRouteFakeRunner());
        $lockPath = $this->scratch . '/state/routes.json.lock';
        $handle = fopen($lockPath, 'c+b');
        self::assertIsResource($handle);
        self::assertTrue(chmod($lockPath, 0600));
        self::assertTrue(flock($handle, LOCK_EX | LOCK_NB));

        try {
            foreach ([
                'host-preflight' => [],
                'worker-preflight' => $this->workerInput(),
            ] as $action => $input) {
                try {
                    $authority->execute($action, $input);
                    self::fail("$action overlapped a route lifecycle writer");
                } catch (HostAuthorityBusy $error) {
                    self::assertSame('route authority lock is busy', $error->getMessage());
                    self::assertInstanceOf(ControlRefusal::class, $error->getPrevious());
                }
            }
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    public function testLostBindResponseResumesDurableIntentButForeignGenerationIsRefused(): void {
        $runner = new CaddyRouteFakeRunner();
        $runner->refuseNextTlsProbe = true;
        $authority = new CaddyRouteAuthority($this->config(), $runner);

        try {
            $authority->execute('bind', $this->bindInput('0000000001'));
            self::fail('first TLS probe should fail after durable applying intent');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('HTTPS route probe', $error->getMessage());
        }
        self::assertSame('bound', $authority->execute('bind', $this->bindInput('0000000001'))['state']);

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('foreign generation');
        $authority->execute('bind', $this->bindInput('0000000002'));
    }

    public function testRefusesBindingThatDoesNotDeriveFromRouteIdentity(): void {
        $authority = new CaddyRouteAuthority($this->config(), new CaddyRouteFakeRunner());
        $input = $this->bindInput('0000000001');
        $input['host'] = 'foreign.preview.example.test';

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('derived generation identity');
        $authority->execute('bind', $input);
    }

    public function testCaddyGatewayErrorCannotMasqueradeAsAHealthyPreviewRoute(): void {
        $runner = new CaddyRouteFakeRunner();
        $runner->gatewayFailureNextTlsProbe = true;
        $authority = new CaddyRouteAuthority($this->config(), $runner);

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('reviewed runtime health body');
        $authority->execute('bind', $this->bindInput('0000000001'));
    }

    public function testHostGlobalAuthorityKeepsSecondSiteWhenFirstSiteUnbinds(): void {
        $secondConfiguration = hash('sha256', 'runtime-config-two');
        $secondReviewedBase = hash('sha256', 'reviewed-base-two');
        $secondToken = hash('sha256', 'tenant-two/site-two');
        $runner = new CaddyRouteFakeRunner();
        $authority = new CaddyRouteAuthority($this->config([[
            'preview_domain' => 'second-preview.example.test',
            'reviewed_base_sha256' => $secondReviewedBase,
            'runtime_configuration_sha256' => $secondConfiguration,
        ]]), $runner);
        $first = $this->bindInput('0000000001');
        $secondCommon = [
            'configuration_sha256' => $secondConfiguration,
            'reviewed_base_sha256' => $secondReviewedBase,
            'route_id' => 'duo-preview-route-' . $secondToken,
        ];
        $second = $secondCommon + [
            'host' => 'p-' . substr($secondToken, 0, 40) . '.second-preview.example.test',
            'upstream_container' => 'duo-preview-' . $secondToken . '-g0000000003',
            'upstream_network' => 'duo-preview-net-' . $secondToken . '-g0000000003',
            'upstream_port' => 8080,
        ];

        self::assertSame('bound', $authority->execute('bind', $first)['state']);
        self::assertSame('bound', $authority->execute('bind', $second)['state']);
        self::assertStringContainsString($first['host'], $runner->activeConfig);
        self::assertStringContainsString($second['host'], $runner->activeConfig);

        self::assertSame('absent', $authority->execute('unbind', $this->commonInput())['state']);
        self::assertStringNotContainsString($first['host'], $runner->activeConfig);
        self::assertStringContainsString($second['host'], $runner->activeConfig);
        self::assertSame('bound', $authority->execute('inspect', $secondCommon)['state']);

        $foreign = $this->commonInput();
        $foreign['route_id'] = $secondCommon['route_id'];
        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('different registered runtime principal');
        $authority->execute('unbind', $foreign);
    }

    public function testHostPreflightMakesNoContainerNetworkOrTlsHealthCalls(): void {
        $runner = new CaddyRouteFakeRunner();
        $config = $this->config();
        $authority = new CaddyRouteAuthority($config, $runner);
        $input = $this->bindInput('0000000001');
        $authority->execute('bind', $input);
        $runner->activeConfig = "{}\n";
        $runner->resetProbeCounts();

        self::assertSame([
            'format' => 'duo-cloud-route-authority-host-preflight/v1',
            'principals' => $config->get('principals'),
            'routes' => 1,
            'state' => 'ready',
        ], $authority->execute('host-preflight', []));
        self::assertSame(0, $runner->containerInspections);
        self::assertSame(0, $runner->networkInspections);
        self::assertSame(0, $runner->tlsProbes);
        self::assertSame(1, $runner->loadRequests);
        self::assertStringContainsString($input['host'], $runner->activeConfig);
    }

    public function testHostPreflightRemovesOneDestinationBoundCrashTemporary(): void {
        $temporary = $this->scratch . '/state/caddy-active.json.tmp';
        self::assertSame(19, file_put_contents($temporary, "interrupted config\n"));
        self::assertTrue(chmod($temporary, 0600));
        $authority = new CaddyRouteAuthority($this->config(), new CaddyRouteFakeRunner());

        self::assertSame('ready', $authority->execute('host-preflight', [])['state']);
        self::assertFileDoesNotExist($temporary);
        self::assertSame([], glob($temporary . '*') ?: []);
        self::assertFileExists($this->scratch . '/state/caddy-active.json');
    }

    public function testUnsafeActiveConfigTemporaryRefusesWithoutFollowingIt(): void {
        $outside = $this->scratch . '/outside';
        $temporary = $this->scratch . '/state/caddy-active.json.tmp';
        self::assertSame(8, file_put_contents($outside, "outside\n"));
        self::assertTrue(chmod($outside, 0600));
        self::assertTrue(symlink($outside, $temporary));
        $authority = new CaddyRouteAuthority($this->config(), new CaddyRouteFakeRunner());

        try {
            $authority->execute('host-preflight', []);
            self::fail('unsafe Caddy temporary was accepted');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('stale temporary must be', $error->getMessage());
        }
        self::assertSame("outside\n", file_get_contents($outside));
        self::assertTrue(is_link($temporary));
    }

    public function testWorkerFailureIsPrincipalScopedAndCannotInvalidateHostOrPeerProof(): void {
        $secondConfiguration = hash('sha256', 'runtime-config-two');
        $secondReviewedBase = hash('sha256', 'reviewed-base-two');
        $secondToken = hash('sha256', 'tenant-two/site-two');
        $secondHost = 'p-' . substr($secondToken, 0, 40) . '.second-preview.example.test';
        $secondWorker = [
            'configuration_sha256' => $secondConfiguration,
            'reviewed_base_sha256' => $secondReviewedBase,
        ];
        $runner = new CaddyRouteFakeRunner();
        $config = $this->config([[
            'preview_domain' => 'second-preview.example.test',
            'reviewed_base_sha256' => $secondReviewedBase,
            'runtime_configuration_sha256' => $secondConfiguration,
        ]]);
        $authority = new CaddyRouteAuthority($config, $runner);
        $authority->execute('bind', $this->bindInput('0000000001'));
        $authority->execute('bind', $secondWorker + [
            'host' => $secondHost,
            'route_id' => 'duo-preview-route-' . $secondToken,
            'upstream_container' => 'duo-preview-' . $secondToken . '-g0000000002',
            'upstream_network' => 'duo-preview-net-' . $secondToken . '-g0000000002',
            'upstream_port' => 8080,
        ]);
        $runner->gatewayFailureHosts[$secondHost] = true;

        try {
            $authority->execute('worker-preflight', $secondWorker);
            self::fail('the second principal gateway failure must refuse its worker proof');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('reviewed runtime health body', $error->getMessage());
        }
        $runner->resetProbeCounts();
        self::assertSame('ready', $authority->execute('host-preflight', [])['state']);
        self::assertSame(0, $runner->containerInspections);
        self::assertSame(0, $runner->networkInspections);
        self::assertSame(0, $runner->tlsProbes);

        $first = $authority->execute('worker-preflight', $this->workerInput());
        self::assertSame(1, $first['routes']);
        self::assertSame('ready', $first['state']);
    }

    public function testConcurrentWorkerProbesRunOutsideTheSharedAuthorityLock(): void {
        $secondConfiguration = hash('sha256', 'runtime-config-two');
        $secondReviewedBase = hash('sha256', 'reviewed-base-two');
        $secondToken = hash('sha256', 'tenant-two/site-two');
        $secondWorker = [
            'configuration_sha256' => $secondConfiguration,
            'reviewed_base_sha256' => $secondReviewedBase,
        ];
        $runner = new CaddyRouteFakeRunner();
        $authority = new CaddyRouteAuthority($this->config([[
            'preview_domain' => 'second-preview.example.test',
            'reviewed_base_sha256' => $secondReviewedBase,
            'runtime_configuration_sha256' => $secondConfiguration,
        ]]), $runner);
        $authority->execute('bind', $this->bindInput('0000000001'));
        $authority->execute('bind', $secondWorker + [
            'host' => 'p-' . substr($secondToken, 0, 40) . '.second-preview.example.test',
            'route_id' => 'duo-preview-route-' . $secondToken,
            'upstream_container' => 'duo-preview-' . $secondToken . '-g0000000002',
            'upstream_network' => 'duo-preview-net-' . $secondToken . '-g0000000002',
            'upstream_port' => 8080,
        ]);

        $nested = null;
        $runner->resetProbeCounts();
        $runner->onNextContainerInspection = function () use (
            $authority,
            $secondWorker,
            &$nested
        ): void {
            $nested = $authority->execute('worker-preflight', $secondWorker);
        };
        $first = $authority->execute('worker-preflight', $this->workerInput());

        self::assertIsArray($nested);
        self::assertSame('ready', $nested['state']);
        self::assertSame('ready', $first['state']);
        self::assertSame(2, $runner->containerInspections);
        self::assertSame(2, $runner->networkInspections);
    }

    public function testPreflightProtocolRejectsLegacyActionsAndUnknownInput(): void {
        $authority = new CaddyRouteAuthority($this->config(), new CaddyRouteFakeRunner());

        try {
            $authority->execute('preflight', []);
            self::fail('the legacy global preflight action must be outside the protocol');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('outside the closed protocol', $error->getMessage());
        }
        try {
            $authority->execute('host-preflight', ['configuration_sha256' => $this->configurationSha256]);
            self::fail('host preflight must not accept worker-scoped input');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('missing or unknown fields', $error->getMessage());
        }

        $worker = $this->workerInput();
        $worker['route_id'] = 'duo-preview-route-' . $this->token;
        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('missing or unknown fields');
        $authority->execute('worker-preflight', $worker);
    }

    /** @param list<array<string,string>> $additionalPrincipals */
    private function config(array $additionalPrincipals = []): RouteAuthorityConfig {
        $engine = $this->executable('docker');
        $curl = $this->executable('curl');
        $principals = array_merge([[
            'preview_domain' => 'preview.example.test',
            'reviewed_base_sha256' => $this->reviewedBaseSha256,
            'runtime_configuration_sha256' => $this->configurationSha256,
        ]], $additionalPrincipals);
        usort($principals, static fn (array $left, array $right): int => strcmp(
            $left['runtime_configuration_sha256'] . "\0" . $left['reviewed_base_sha256'],
            $right['runtime_configuration_sha256'] . "\0" . $right['reviewed_base_sha256']
        ));
        $document = [
            'admin_endpoint' => 'http://127.0.0.1:2019/config/',
            'container_engine' => $engine,
            'curl' => $curl,
            'format' => RouteAuthorityConfig::FORMAT,
            'principals' => $principals,
            'process_timeout_seconds' => 30,
            'state_root' => $this->scratch . '/state',
        ];
        $bytes = CanonicalJson::encode($document) . "\n";
        $path = $this->scratch . '/route.json';
        self::assertNotFalse(file_put_contents($path, $bytes));
        self::assertTrue(chmod($path, 0600));
        return RouteAuthorityConfig::load($path, hash('sha256', $bytes));
    }

    /** @return array<string,mixed> */
    private function bindInput(string $generation): array {
        return $this->commonInput() + [
            'host' => 'p-' . substr($this->token, 0, 40) . '.preview.example.test',
            'upstream_container' => 'duo-preview-' . $this->token . '-g' . $generation,
            'upstream_network' => 'duo-preview-net-' . $this->token . '-g' . $generation,
            'upstream_port' => 8080,
        ];
    }

    /** @return array<string,mixed> */
    private function commonInput(): array {
        return [
            'configuration_sha256' => $this->configurationSha256,
            'reviewed_base_sha256' => $this->reviewedBaseSha256,
            'route_id' => 'duo-preview-route-' . $this->token,
        ];
    }

    /** @return array<string,mixed> */
    private function workerInput(): array {
        return [
            'configuration_sha256' => $this->configurationSha256,
            'reviewed_base_sha256' => $this->reviewedBaseSha256,
        ];
    }

    /** @return array{path:string,sha256:string} */
    private function executable(string $name): array {
        $path = $this->scratch . '/' . $name;
        $bytes = "#!/bin/sh\nexit 0\n";
        self::assertNotFalse(file_put_contents($path, $bytes));
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

final class CaddyRouteFakeRunner implements ContainerArgvProcessRunner {
    public string $activeConfig = '';
    public int $containerInspections = 0;
    /** @var array<string,bool> */
    public array $gatewayFailureHosts = [];
    public int $tlsProbes = 0;
    public bool $refuseNextTlsProbe = false;
    public bool $gatewayFailureNextTlsProbe = false;
    public int $loadRequests = 0;
    public int $networkInspections = 0;
    public ?\Closure $onNextContainerInspection = null;

    public function resetProbeCounts(): void {
        $this->containerInspections = 0;
        $this->loadRequests = 0;
        $this->networkInspections = 0;
        $this->tlsProbes = 0;
    }

    public function run(
        array $argv,
        ?string $stdinFile = null,
        ?int $timeoutSeconds = null
    ): array {
        if (($argv[1] ?? null) === 'container' && ($argv[2] ?? null) === 'inspect') {
            $this->containerInspections++;
            $callback = $this->onNextContainerInspection;
            $this->onNextContainerInspection = null;
            if ($callback instanceof \Closure) {
                $callback();
            }
            $name = $argv[5];
            $network = str_replace('duo-preview-', 'duo-preview-net-', $name);
            return $this->success(json_encode([
                'Config' => ['ExposedPorts' => ['8080/tcp' => new \stdClass()], 'User' => '10001:10001'],
                'HostConfig' => ['NetworkMode' => $network],
                'Id' => hash('sha256', $name),
                'Name' => '/' . $name,
                'NetworkSettings' => ['Networks' => [$network => ['IPAddress' => '172.31.0.2']]],
                'State' => ['Running' => true],
            ], JSON_THROW_ON_ERROR));
        }
        if (($argv[1] ?? null) === 'network' && ($argv[2] ?? null) === 'inspect') {
            $this->networkInspections++;
            $network = $argv[5];
            $container = str_replace('duo-preview-net-', 'duo-preview-', $network);
            $id = hash('sha256', $container);
            return $this->success(json_encode([
                'Containers' => [$id => [
                    'IPv4Address' => '172.31.0.2/16',
                    'Name' => $container,
                ]],
                'Driver' => 'bridge',
                'Internal' => true,
                'Name' => $network,
                'Options' => [
                    'com.docker.network.bridge.enable_icc' => 'false',
                    'com.docker.network.bridge.enable_ip_masquerade' => 'false',
                ],
            ], JSON_THROW_ON_ERROR));
        }
        if (($argv[count($argv) - 1] ?? null) === 'http://127.0.0.1:2019/load') {
            $this->loadRequests++;
            $bytes = is_string($stdinFile) ? file_get_contents($stdinFile) : false;
            if (!is_string($bytes)) {
                return ['exit' => 1, 'stderr' => 'missing config', 'stdout' => ''];
            }
            $this->activeConfig = $bytes;
            return $this->success('');
        }
        if (in_array('--resolve', $argv, true)) {
            $this->tlsProbes++;
            $resolveIndex = array_search('--resolve', $argv, true);
            $resolution = is_int($resolveIndex) ? ($argv[$resolveIndex + 1] ?? null) : null;
            $host = is_string($resolution) ? explode(':', $resolution, 2)[0] : null;
            if ($this->refuseNextTlsProbe) {
                $this->refuseNextTlsProbe = false;
                return ['exit' => 60, 'stderr' => 'untrusted', 'stdout' => ''];
            }
            if ($this->gatewayFailureNextTlsProbe
                || (is_string($host) && isset($this->gatewayFailureHosts[$host]))) {
                $this->gatewayFailureNextTlsProbe = false;
                return $this->success("duo-cloud-preview-runtime-health/v1\n"
                    . "duo-cloud-route-proof/v1\n0\n127.0.0.1\n502\n");
            }
            return $this->success("duo-cloud-preview-runtime-health/v1\n"
                . "duo-cloud-route-proof/v1\n0\n127.0.0.1\n200\n");
        }
        if (($argv[count($argv) - 1] ?? null) === 'http://127.0.0.1:2019/config/') {
            return $this->success($this->activeConfig);
        }
        return ['exit' => 127, 'stderr' => 'unexpected argv', 'stdout' => ''];
    }

    /** @return array{exit:int,stderr:string,stdout:string} */
    private function success(string $stdout): array {
        return ['exit' => 0, 'stderr' => '', 'stdout' => $stdout];
    }
}
