<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/RebaseCommand.php';

use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\RebaseCommand;

function fail_rebase_command(string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
function assert_rebase_command(bool $ok, string $message): void { if (!$ok) fail_rebase_command($message); }

final class RebaseCommandDriver implements EnvironmentDriver {
    public int $rawCalls = 0;
    public int $wpCalls = 0;
    public function name(): string { return 'rebase-command-fixture'; }
    public function driverId(): string { return 'rebase-command-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'rebase fixture'; }
    public function captureRaw(string $script): array { $this->rawCalls++; return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected target contact']; }
    public function captureWp(array $wpArgs): array { $this->wpCalls++; return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected target contact']; }
    public function streamWp(array $wpArgs): int { $this->wpCalls++; return 99; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport { return DriverCapabilityReport::forDriver('rebase-command-fixture', 'rebase-command-fixture', $operation, []); }
}

$driver = new RebaseCommandDriver();
$exit = RebaseCommand::run($driver, ['--interactive']);
assert_rebase_command($exit === 1, 'non-TTY interactive resolution refuses with a bounded failure');
assert_rebase_command($driver->rawCalls === 0 && $driver->wpCalls === 0, 'rebase parser/refusal occurs before target contact');

$abort = new RebaseCommandDriver();
$abortExit = RebaseCommand::run($abort, ['--abort=run-1', '--production-ref=main']);
assert_rebase_command($abortExit === 1, 'abort rejects mixed production flags before workflow contact');
assert_rebase_command($abort->rawCalls === 0 && $abort->wpCalls === 0, 'abort grammar refusal has no target side effect');

$source = file_get_contents(__DIR__ . '/../../cli/duo');
assert_rebase_command(is_string($source) && str_contains($source, 'return RebaseCommand::run($t, $extra);'), 'cli/duo retains only the rebase facade');
assert_rebase_command(!str_contains($source, 'function rebase_flags(') && !str_contains($source, 'function interactive_tty_available('), 'rebase parser helpers moved out of cli/duo');
assert_rebase_command((new ReflectionMethod(RebaseCommand::class, 'run'))->isStatic(), 'rebase handler exposes a standalone static boundary');
echo "PASS: rebase command\n";
