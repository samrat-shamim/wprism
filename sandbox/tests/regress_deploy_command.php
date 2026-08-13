<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/DeployCommand.php';

use Duo\Orchestrator\DeployCommand;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;

function fail_deploy_command(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}
function assert_deploy_command(bool $condition, string $message): void {
    if (!$condition) fail_deploy_command($message);
}

final class DeployCommandDriver implements EnvironmentDriver {
    /** @var list<string> */
    public array $events = [];
    /** @var list<array<int,string>> */
    public array $calls = [];
    public int $preflightExit = 0;
    public int $stageExit = 0;
    public bool $dispositionBlocked = false;

    public function name(): string { return 'deploy-fixture'; }
    public function driverId(): string { return 'deploy-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'deploy fixture'; }
    public function captureRaw(string $script): array {
        $this->events[] = 'raw:mkdir';
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }
    public function captureWp(array $wpArgs): array {
        $this->calls[] = $wpArgs;
        $command = $this->command($wpArgs);
        $this->events[] = 'capture:' . $command;
        $hash = str_repeat('a', 64);
        $revision = str_repeat('b', 64);
        if ($command === 'compile') {
            $summary = [
                'artifact_hash' => $hash,
                'code' => ['code_revision' => $revision, 'format' => 1],
            ];
            if ($this->dispositionBlocked) {
                $summary['resolved_adapters'] = [[
                    'name' => 'fixture-adapter',
                    'disposition' => ['source' => 'fixture'],
                    'capability' => [
                        'status' => 'candidate',
                        'reason' => 'fixture evidence is stale',
                        'evidence' => ['status' => 'candidate'],
                    ],
                ]];
            }
            return ['exit' => 0, 'stdout' => json_encode($summary, JSON_THROW_ON_ERROR), 'stderr' => ''];
        }
        if ($command === 'code-preflight') {
            if ($this->preflightExit !== 0) {
                return ['exit' => $this->preflightExit, 'stdout' => 'runtime refusal', 'stderr' => ''];
            }
            return ['exit' => 0, 'stdout' => json_encode([
                'format' => 'duo-code-runtime/v1', 'enabled' => true, 'compatible' => true,
                'code_revision' => $revision,
                'target' => ['php' => '8.3', 'wordpress' => '6.8', 'source' => 'target-control-plane'],
                'requirements' => [], 'diagnostics' => [],
            ], JSON_THROW_ON_ERROR), 'stderr' => ''];
        }
        if ($command === 'promotion-begin') return ['exit' => 0, 'stdout' => 'begun', 'stderr' => ''];
        fail_deploy_command("unexpected capture command $command");
    }
    public function streamWp(array $wpArgs): int {
        $this->calls[] = $wpArgs;
        $command = $this->command($wpArgs);
        $phase = $this->option($wpArgs, '--lifecycle-phase=');
        $this->events[] = 'stream:' . $command . ($phase === null ? '' : ':' . $phase);
        return $command === 'code-stage' ? $this->stageExit : 0;
    }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('deploy-fixture', 'deploy-fixture', $operation, []);
    }
    private function command(array $args): string {
        $index = array_search('duo', $args, true);
        if (!is_int($index) || !isset($args[$index + 1])) fail_deploy_command('driver did not receive a duo command');
        return $args[$index + 1];
    }
    private function option(array $args, string $prefix): ?string {
        foreach ($args as $arg) {
            if (str_starts_with($arg, $prefix)) return substr($arg, strlen($prefix));
        }
        return null;
    }
}

/** @return array{exit:int,callbacks:list<string>} */
function run_deploy_command(DeployCommandDriver $driver, array $extra, ?int $scopeExit = null): array {
    $callbacks = [];
    $exit = DeployCommand::run(
        $driver,
        $extra,
        static function (array $args) use (&$callbacks, $scopeExit): ?int {
            $callbacks[] = 'scope';
            return $scopeExit;
        },
        static function (EnvironmentDriver $transport) use (&$callbacks): bool {
            $callbacks[] = 'fence:' . $transport->name();
            return true;
        },
        static function () use (&$callbacks): string {
            $callbacks[] = 'run-id';
            return 'deploy-command-test';
        },
        static function (EnvironmentDriver $transport, array $begin, string $owner, string $hash) use (&$callbacks): void {
            $callbacks[] = "compensate:$owner:$hash:" . (int) ($begin['exit'] ?? -1);
        },
        static function (EnvironmentDriver $transport, string $owner, string $hash) use (&$callbacks): bool {
            $callbacks[] = "abort:$owner:$hash";
            return true;
        }
    );
    return ['exit' => $exit, 'callbacks' => $callbacks];
}

function option_deploy_command(array $args, string $prefix): ?string {
    foreach ($args as $arg) {
        if (str_starts_with($arg, $prefix)) return substr($arg, strlen($prefix));
    }
    return null;
}

// Direct execution proves the extracted handler still owns the full public
// deploy phase graph without loading cli/duo or starting a shell process.
$happy = new DeployCommandDriver();
$happyResult = run_deploy_command($happy, ['--force-code-mismatch', '--force-code-drift']);
assert_deploy_command($happyResult['exit'] === 0, 'code-enabled deploy command succeeds');
assert_deploy_command(
    $happy->events === [
        'raw:mkdir', 'capture:compile', 'capture:code-preflight', 'capture:promotion-begin',
        'stream:code-stage', 'stream:deploy:retire', 'stream:deploy:activate', 'stream:code-finalize',
    ],
    'direct handler preserves compile/preflight/begin/stage/lifecycle/finalize order'
);
assert_deploy_command(
    $happyResult['callbacks'] === ['scope', 'fence:deploy-fixture', 'run-id'],
    'successful handler performs only scope, fence, and run-id collaboration'
);
$owner = option_deploy_command($happy->calls[2], '--promotion-owner=');
$hash = option_deploy_command($happy->calls[2], '--artifact-hash=');
assert_deploy_command($owner === 'deploy-command-test' && $hash === str_repeat('a', 64), 'begin receives the generated owner and compiled hash');
foreach (array_slice($happy->calls, 3) as $call) {
    assert_deploy_command(
        option_deploy_command($call, '--promotion-owner=') === $owner
            && option_deploy_command($call, '--artifact-hash=') === $hash,
        'every mutating deploy phase retains the one owner and artifact hash'
    );
}
assert_deploy_command(
    in_array('--force-code-mismatch', $happy->calls[4], true)
        && in_array('--force-code-drift', $happy->calls[4], true)
        && in_array('--force-code-mismatch', $happy->calls[5], true)
        && in_array('--force-code-drift', $happy->calls[5], true),
    'only lifecycle phases receive the public force flags'
);

// A scope refusal precedes the rollback fence and every target operation.
$scoped = new DeployCommandDriver();
$scopedResult = run_deploy_command($scoped, ['--scope-contract=/tmp/contract'], 2);
assert_deploy_command($scopedResult['exit'] === 2 && $scopedResult['callbacks'] === ['scope'], 'scope refusal returns before every other deploy collaborator');
assert_deploy_command($scoped->events === [], 'scope refusal makes no target contact');

// Caller-owned artifact and lease flags fail before the fence or mkdir, or the
// command could sever its immutable-artifact binding before target contact.
$forged = new DeployCommandDriver();
$forgedResult = run_deploy_command($forged, ['--repo=/forged']);
assert_deploy_command($forgedResult['exit'] === 1 && $forgedResult['callbacks'] === ['scope'], 'forged host binding refuses before fence');
assert_deploy_command($forged->events === [], 'forged host binding makes no target contact');

// Capability evidence is checked before preflight, lease acquisition, or a
// mutation phase; candidate claims never enter the deploy state machine.
$blocked = new DeployCommandDriver();
$blocked->dispositionBlocked = true;
$blockedResult = run_deploy_command($blocked, []);
assert_deploy_command($blockedResult['exit'] === 1, 'uncertified adapter disposition blocks deploy');
assert_deploy_command($blocked->events === ['raw:mkdir', 'capture:compile'], 'uncertified disposition refuses before preflight and promotion-begin');

// Runtime preflight happens before the lease, so its failure has no invented
// cleanup path and stops before begin/stage/lifecycle.
$preflight = new DeployCommandDriver();
$preflight->preflightExit = 17;
$preflightResult = run_deploy_command($preflight, []);
assert_deploy_command($preflightResult['exit'] === 17, 'runtime preflight exit propagates unchanged');
assert_deploy_command($preflight->events === ['raw:mkdir', 'capture:compile', 'capture:code-preflight'], 'runtime preflight failure stops before promotion-begin');
assert_deploy_command($preflightResult['callbacks'] === ['scope', 'fence:deploy-fixture', 'run-id'], 'preflight failure performs no lease cleanup fiction');

// A post-begin stage failure uses the injected shared exact-abort primitive and
// cannot continue into lifecycle/finalize phases.
$stage = new DeployCommandDriver();
$stage->stageExit = 8;
$stageResult = run_deploy_command($stage, []);
assert_deploy_command($stageResult['exit'] === 8, 'stage exit propagates unchanged');
assert_deploy_command(
    $stage->events === ['raw:mkdir', 'capture:compile', 'capture:code-preflight', 'capture:promotion-begin', 'stream:code-stage'],
    'stage failure stops later lifecycle and finalize phases'
);
assert_deploy_command(
    $stageResult['callbacks'] === [
        'scope', 'fence:deploy-fixture', 'run-id',
        'abort:deploy-command-test:' . str_repeat('a', 64),
    ],
    'stage failure delegates exact cleanup to the shared promotion primitive'
);

echo "PASS: deploy command\n";
