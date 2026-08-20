<?php
declare(strict_types=1);

namespace Duo\Tests\Cloud;

use Duo\Cloud\CanonicalJson;
use Duo\Cloud\ContainerArgvProcessRunner;
use Duo\Cloud\ContainerCommandRunner;
use Duo\Cloud\ControlRefusal;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

require_once DUO_REPO_ROOT . '/cloud/runtime/ContainerCommandRunner.php';

#[CoversNothing]
final class ContainerCommandRunnerTest extends TestCase {
    private const IMAGE = 'registry.example.test/duo/wordpress@sha256:'
        . 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';
    private const CONFIG = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const REVIEWED = 'cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';

    private string $scratch;
    private string $resourceId;
    private string $container;
    private CommandRecycleFakeRunner $process;
    private ContainerCommandRunner $runner;

    protected function setUp(): void {
        $this->scratch = sys_get_temp_dir() . '/duo-command-runner-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        self::assertTrue(chmod($this->scratch, 0700));
        $this->resourceId = 'cloud-slot-' . hash(
            'sha256',
            "duo-cloud-preview-physical-slot/v1\0tenant-a\0site-a"
        );
        $token = substr($this->resourceId, strlen('cloud-slot-'));
        $slot = $this->scratch . '/slots/' . $token;
        self::assertTrue(mkdir($slot, 0700, true));
        self::assertTrue(chmod($this->scratch . '/slots', 0700));
        self::assertTrue(chmod($slot, 0700));
        $this->container = 'duo-preview-' . $token . '-g0000000001';
        $manifest = [
            'configuration_sha256' => self::CONFIG,
            'container_name' => $this->container,
            'environment_identity' => 'environment-a',
            'execution_state' => 'running',
            'format' => 'duo-cloud-container-workload-state/v1',
            'image' => self::IMAGE,
            'last_mutation' => [
                'generation' => 1,
                'id' => 'mutation-a',
                'owner' => 'duo-env-materialize-operation-a',
                'receipt_sha256' => hash('sha256', 'mutation'),
            ],
            'lease_generation' => 1,
            'lease_id' => 'lease-a',
            'ownership_receipt_sha256' => hash('sha256', 'ownership'),
            'resource_id' => $this->resourceId,
            'reviewed_base_sha256' => self::REVIEWED,
            'site_id' => 'site-a',
            'state' => 'present',
            'tenant_id' => 'tenant-a',
        ];
        self::assertNotFalse(file_put_contents(
            $slot . '/active.json',
            CanonicalJson::encode($manifest) . "\n"
        ));
        self::assertTrue(chmod($slot . '/active.json', 0600));
        $this->process = new CommandRecycleFakeRunner(
            $this->container,
            self::IMAGE,
            self::CONFIG,
            self::REVIEWED,
            $this->resourceId
        );
        $this->runner = new ContainerCommandRunner(
            $this->process,
            '/usr/bin/docker',
            self::IMAGE,
            self::CONFIG,
            self::REVIEWED,
            $this->scratch,
            5
        );
    }

    protected function tearDown(): void {
        $this->remove($this->scratch);
    }

    public function testVerifierEphemeralRegistryDigestCrossesTheCommandBoundary(): void {
        $image = '127.0.0.1:49152/duo-cloud-preview-proof@sha256:'
            . 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

        $runner = new ContainerCommandRunner(
            $this->process,
            '/usr/bin/docker',
            $image,
            self::CONFIG,
            self::REVIEWED,
            $this->scratch,
            5
        );

        self::assertInstanceOf(ContainerCommandRunner::class, $runner);
    }

    public function testRawCommandAlwaysKillsProvesDeadRestartsAndWaitsForReviewedReadiness(): void {
        $result = $this->runner->run($this->request('raw', ['script' => "sleep 10 &\necho done\n"]));

        self::assertSame(['exit' => 0, 'stderr' => '', 'stdout' => "done\n"], $result);
        self::assertSame("sleep 10 &\necho done\n", $this->process->rawInput);
        $operations = array_map(
            static fn (array $call): string => implode(' ', array_slice($call['argv'], 1, 3)),
            $this->process->calls
        );
        self::assertContains('container kill --signal', $operations);
        self::assertContains('container start ' . $this->container, $operations);
        self::assertTrue($this->process->provedStopped);
        self::assertSame(2002, $this->process->pid);
        self::assertGreaterThanOrEqual(2, $this->process->readinessPolls);
        self::assertGreaterThanOrEqual(2, $this->process->inspectionsAfterCommand);
        self::assertSame([], glob($this->scratch . '/command-inputs/*') ?: []);
    }

    public function testAnyNextCommandRemovesOneSlotBoundCrashInput(): void {
        $token = substr($this->resourceId, strlen('cloud-slot-'));
        $stale = $this->scratch . '/command-inputs/' . $token . '.input';
        self::assertSame(16, file_put_contents($stale, "private residue\n"));
        self::assertTrue(chmod($stale, 0600));

        $result = $this->runner->run($this->request('wp', ['argv' => ['option', 'get', 'home']]));

        self::assertSame(0, $result['exit']);
        self::assertFileDoesNotExist($stale);
        self::assertSame([], glob($this->scratch . '/command-inputs/*') ?: []);
    }

    public function testUnsafeSlotBoundCrashInputRefusesBeforeDockerWithoutFollowingIt(): void {
        $token = substr($this->resourceId, strlen('cloud-slot-'));
        $stale = $this->scratch . '/command-inputs/' . $token . '.input';
        $outside = $this->scratch . '/outside-input';
        self::assertSame(8, file_put_contents($outside, "outside\n"));
        self::assertTrue(chmod($outside, 0600));
        self::assertTrue(symlink($outside, $stale));

        try {
            $this->runner->run($this->request('wp', ['argv' => ['option', 'get', 'home']]));
            self::fail('unsafe command input was accepted');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString('stale private input must be', $error->getMessage());
        }
        self::assertSame([], $this->process->calls);
        self::assertSame("outside\n", file_get_contents($outside));
        self::assertTrue(is_link($stale));
    }

    public function testRawCommandRefusesInputAboveTheBoundBeforeDocker(): void {
        try {
            $this->runner->run($this->request('raw', ['script' => str_repeat('x', 2097153)]));
            self::fail('oversized command input was accepted');
        } catch (ControlRefusal $error) {
            self::assertSame('command runner raw script is invalid', $error->getMessage());
        }
        self::assertSame([], $this->process->calls);
        self::assertSame([], glob($this->scratch . '/command-inputs/*') ?: []);
    }

    public function testWpFailureStillCrossesRestartBoundaryBeforeReturning(): void {
        $this->process->commandExit = 7;
        $result = $this->runner->run($this->request('wp', ['argv' => ['option', 'get', 'home']]));

        self::assertSame(7, $result['exit']);
        self::assertTrue($this->process->provedStopped);
        self::assertSame(2002, $this->process->pid);
    }

    public function testRefusesReceiptWhenStoppedInspectionCannotProvePidZero(): void {
        $this->process->foreignStoppedPid = true;

        $this->expectException(ControlRefusal::class);
        $this->expectExceptionMessage('every command-generation process is dead');
        $this->runner->run($this->request('raw', ['script' => "echo done\n"]));
    }

    public function testHeldLifecycleSlotLockRefusesBeforeDockerOrInputAndExactRetryRuns(): void {
        if (!function_exists('pcntl_fork') || !function_exists('pcntl_waitpid')) {
            self::markTestSkipped('pcntl is required for the shared slot-lock regression');
        }
        $token = substr($this->resourceId, strlen('cloud-slot-'));
        $lockPath = $this->scratch . '/slots/' . $token . '/runtime.lock';
        $started = $this->scratch . '/slot-lock-started';
        $release = $this->scratch . '/slot-lock-release';
        $pid = pcntl_fork();
        self::assertNotSame(-1, $pid);
        if ($pid === 0) {
            $handle = @fopen($lockPath, 'c+b');
            if (!is_resource($handle) || !chmod($lockPath, 0600)
                || !flock($handle, LOCK_EX | LOCK_NB)) {
                exit(70);
            }
            file_put_contents($started, "held\n");
            $deadline = microtime(true) + 5.0;
            while (!is_file($release) && microtime(true) < $deadline) {
                usleep(10000);
            }
            $released = is_file($release);
            flock($handle, LOCK_UN);
            fclose($handle);
            exit($released ? 0 : 71);
        }
        $this->waitForFile($started);

        $before = microtime(true);
        try {
            $this->runner->run($this->request('raw', ['script' => "echo blocked\n"]));
            self::fail('command runner bypassed the held lifecycle slot lock');
        } catch (ControlRefusal $error) {
            self::assertSame('runtime slot lock is busy', $error->getMessage());
        }
        self::assertLessThan(0.5, microtime(true) - $before);
        self::assertSame([], $this->process->calls);
        self::assertSame([], glob($this->scratch . '/command-inputs/*') ?: []);

        self::assertNotFalse(file_put_contents($release, "continue\n"));
        pcntl_waitpid($pid, $status);
        self::assertSame(0, pcntl_wexitstatus($status));
        self::assertSame(
            ['exit' => 0, 'stderr' => '', 'stdout' => "done\n"],
            $this->runner->run($this->request('raw', ['script' => "echo blocked\n"]))
        );
        self::assertNotSame([], $this->process->calls);
    }

    public function testRestartReadinessUsesOneAggregateWallBudget(): void {
        $this->process->readinessAlwaysPending = true;
        $this->process->readinessDelayMicroseconds = 50000;
        $bounded = new ContainerCommandRunner(
            $this->process,
            '/usr/bin/docker',
            self::IMAGE,
            self::CONFIG,
            self::REVIEWED,
            $this->scratch,
            1
        );

        $before = microtime(true);
        try {
            $bounded->run($this->request('wp', ['argv' => ['option', 'get', 'home']]));
            self::fail('restart readiness ignored its aggregate wall budget');
        } catch (ControlRefusal $error) {
            self::assertStringContainsString(
                'restart did not reach reviewed runtime readiness',
                $error->getMessage()
            );
        }
        self::assertLessThan(2.0, microtime(true) - $before);
        self::assertLessThan(10, $this->process->readinessPolls);
    }

    /** @param array<string,mixed> $input @return array<string,mixed> */
    private function request(string $action, array $input): array {
        return [
            'action' => $action,
            'input' => $input,
            'operation_id' => 'operation-a',
            'request_id' => hash('sha256', $action . CanonicalJson::encode($input)),
            'site_id' => 'site-a',
            'target' => [
                'environment_identity' => 'environment-a',
                'lease_generation' => 1,
                'lease_id' => 'lease-a',
                'mutation_generation' => 1,
                'mutation_id' => 'mutation-a',
                'mutation_owner' => 'duo-env-materialize-operation-a',
                'mutation_receipt_sha256' => hash('sha256', 'mutation'),
                'ownership_receipt_sha256' => hash('sha256', 'ownership'),
                'resource_id' => $this->resourceId,
            ],
            'tenant_id' => 'tenant-a',
        ];
    }

    private function waitForFile(string $path): void {
        $deadline = microtime(true) + 5.0;
        while (!is_file($path) && microtime(true) < $deadline) {
            usleep(10000);
        }
        self::assertFileExists($path);
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

final class CommandRecycleFakeRunner implements ContainerArgvProcessRunner {
    /** @var list<array{argv:list<string>,stdin:?string}> */
    public array $calls = [];
    public string $rawInput = '';
    public int $commandExit = 0;
    public bool $foreignStoppedPid = false;
    public bool $provedStopped = false;
    public bool $readinessAlwaysPending = false;
    public int $readinessDelayMicroseconds = 0;
    public int $pid = 1001;
    public int $inspectionsAfterCommand = 0;
    public int $readinessPolls = 0;
    private bool $running = true;
    private string $startedAt = '2026-08-18T00:00:00.000000000Z';
    private bool $commandRan = false;

    public function __construct(
        private string $container,
        private string $image,
        private string $configuration,
        private string $reviewedBase,
        private string $resourceId
    ) {}

    public function run(
        array $argv,
        ?string $stdinFile = null,
        ?int $timeoutSeconds = null
    ): array {
        $this->calls[] = ['argv' => $argv, 'stdin' => $stdinFile];
        if (($argv[1] ?? null) === 'container' && ($argv[2] ?? null) === 'inspect') {
            if ($this->commandRan) {
                $this->inspectionsAfterCommand++;
            }
            return $this->success(json_encode($this->inspection(), JSON_THROW_ON_ERROR));
        }
        if (($argv[1] ?? null) === 'container' && ($argv[2] ?? null) === 'exec') {
            if (in_array('/opt/duo/bin/runtime-status', $argv, true)) {
                $this->readinessPolls++;
                if ($this->readinessDelayMicroseconds > 0) {
                    usleep($this->readinessDelayMicroseconds);
                }
                return $this->success(CanonicalJson::encode([
                    'clean_base' => false,
                    'configuration_sha256' => $this->configuration,
                    'format' => 'duo-cloud-preview-runtime-status/v1',
                    'lease_generation' => 1,
                    'ready' => !$this->readinessAlwaysPending && $this->readinessPolls > 1,
                    'reviewed_base_sha256' => $this->reviewedBase,
                ]) . "\n");
            }
            $this->commandRan = true;
            if ($stdinFile !== null) {
                $contents = file_get_contents($stdinFile);
                $this->rawInput = is_string($contents) ? $contents : '';
            }
            return ['exit' => $this->commandExit, 'stderr' => '', 'stdout' => "done\n"];
        }
        if (($argv[1] ?? null) === 'container' && ($argv[2] ?? null) === 'kill') {
            $this->running = false;
            $this->pid = $this->foreignStoppedPid ? 9999 : 0;
            return $this->success($this->container . "\n");
        }
        if (($argv[1] ?? null) === 'container' && ($argv[2] ?? null) === 'start') {
            $this->provedStopped = $this->pid === 0;
            $this->running = true;
            $this->pid = 2002;
            $this->startedAt = '2026-08-18T00:00:01.000000000Z';
            return $this->success($this->container . "\n");
        }
        return ['exit' => 127, 'stderr' => 'unexpected', 'stdout' => ''];
    }

    /** @return array<string,mixed> */
    private function inspection(): array {
        return [
            'Config' => [
                'Image' => $this->image,
                'Labels' => [
                    'duo.cloud.configuration-sha256' => $this->configuration,
                    'duo.cloud.lease-generation' => '1',
                    'duo.cloud.resource-id' => $this->resourceId,
                    'duo.cloud.reviewed-base-sha256' => $this->reviewedBase,
                ],
                'User' => '10001:10001',
            ],
            'HostConfig' => [
                'CapDrop' => ['ALL'],
                'PortBindings' => [],
                'Privileged' => false,
                'ReadonlyRootfs' => true,
                'SecurityOpt' => ['no-new-privileges=true'],
            ],
            'Mounts' => [],
            'Name' => '/' . $this->container,
            'State' => [
                'Dead' => false,
                'Paused' => false,
                'Pid' => $this->pid,
                'Restarting' => false,
                'Running' => $this->running,
                'StartedAt' => $this->startedAt,
            ],
        ];
    }

    /** @return array{exit:int,stderr:string,stdout:string} */
    private function success(string $stdout): array {
        return ['exit' => 0, 'stderr' => '', 'stdout' => $stdout];
    }
}
