<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/ScopeCommand.php';

use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\ScopeCommand;

function fail_scope_command(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_scope_command(bool $condition, string $message): void {
    if (!$condition) {
        fail_scope_command($message);
    }
}

final class ScopeCommandDriver implements EnvironmentDriver {
    public int $streamCalls = 0;
    public int $captureRawCalls = 0;
    public int $captureWpCalls = 0;
    /** @var list<string> */
    public array $streamArgs = [];

    public function name(): string { return 'scope-fixture'; }
    public function driverId(): string { return 'scope-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'scope fixture'; }
    public function captureRaw(string $script): array {
        $this->captureRawCalls++;
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }
    public function captureWp(array $wpArgs): array {
        $this->captureWpCalls++;
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }
    public function streamWp(array $wpArgs): int {
        $this->streamCalls++;
        $this->streamArgs = $wpArgs;
        return 17;
    }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('scope-fixture', 'scope-fixture', $operation, []);
    }
}

$driver = new ScopeCommandDriver();
$exit = ScopeCommand::run($driver, ['--roots=all', '--contract', '--format=json']);
assert_scope_command($exit === 17, 'scope preserves the transport exit code');
assert_scope_command($driver->streamCalls === 1, 'scope performs exactly one streamed target call');
assert_scope_command($driver->captureRawCalls === 0 && $driver->captureWpCalls === 0,
    'scope does not perform an extra raw or captured target call');
assert_scope_command(count(array_filter(
    $driver->streamArgs,
    static fn(string $arg): bool => str_starts_with($arg, '--exec=')
)) === 1, 'scope installs exactly one control-plane bootstrap');
assert_scope_command(
    str_contains($driver->streamArgs[0] ?? '', 'DUO_CONTROL_PLANE')
        && str_contains($driver->streamArgs[0] ?? '', 'WPMU_PLUGIN_DIR'),
    'scope uses the protected control-plane bootstrap');
assert_scope_command(
    ($driver->streamArgs[1] ?? null) === '--skip-plugins'
        && ($driver->streamArgs[2] ?? null) === '--skip-themes'
        && ($driver->streamArgs[3] ?? null) === 'duo'
        && ($driver->streamArgs[4] ?? null) === 'scope'
        && ($driver->streamArgs[5] ?? null) === '--repo=/fixture/repo'
        && array_slice($driver->streamArgs, 6) === ['--roots=all', '--contract', '--format=json'],
    'scope preserves control flags, repository identity, and user arguments in order'
);

echo "PASS: scope command\n";
