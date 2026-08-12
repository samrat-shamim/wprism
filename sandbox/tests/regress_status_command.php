<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/StatusCommand.php';

use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\PlanContract;
use Duo\Orchestrator\StatusCommand;

function fail_status_command(string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
function assert_status_command(bool $condition, string $message): void { if (!$condition) fail_status_command($message); }

final class StatusCommandDriver implements EnvironmentDriver {
    public int $calls = 0;
    public function __construct(private string $mode) {}
    public function name(): string { return 'status-fixture'; }
    public function driverId(): string { return 'status-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'status fixture'; }
    public function captureRaw(string $script): array { return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function captureWp(array $wpArgs): array {
        $this->calls++;
        if ($this->mode === 'failure') return ['exit' => 9, 'stdout' => '', 'stderr' => 'plan unavailable'];
        if ($this->mode === 'malformed') return ['exit' => 0, 'stdout' => '{}', 'stderr' => ''];
        $plan = [];
        foreach (PlanContract::requiredBuckets() as $bucket) $plan[$bucket] = [];
        return ['exit' => 0, 'stdout' => json_encode($plan) . "\n", 'stderr' => ''];
    }
    public function streamWp(array $wpArgs): int { return 99; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('status-fixture', 'status-fixture', $operation, []);
    }
}

$plan = new StatusCommandDriver('plan');
$authorityCalls = 0;
ob_start();
$planExit = StatusCommand::run(
    $plan,
    [],
    static function (string $code, string $message, string $remediation): void { echo "[$code] $message\n"; },
    static function (array $refusal): void { echo "refused\n"; },
    static function (EnvironmentDriver $driver) use (&$authorityCalls): bool { $authorityCalls++; return true; }
);
$planOutput = (string) ob_get_clean();
assert_status_command($planExit === 0, 'complete clean plan exits successfully');
assert_status_command($plan->calls === 1 && $authorityCalls === 1, 'status performs one plan read and authority check');
assert_status_command(str_contains($planOutput, 'plan:'), 'status renders the canonical plan summary');

$malformed = new StatusCommandDriver('malformed');
ob_start();
$malformedExit = StatusCommand::run(
    $malformed,
    [],
    static function (string $c, string $m, string $r): void {},
    static function (array $r): void {},
    static fn(EnvironmentDriver $d): bool => true
);
$malformedOutput = (string) ob_get_clean();
assert_status_command($malformedExit === 1 && !str_contains($malformedOutput, 'plan:'), 'malformed plan refuses before readiness rendering');

$failure = new StatusCommandDriver('failure');
$failureExit = StatusCommand::run(
    $failure,
    [],
    static function (string $c, string $m, string $r): void {},
    static function (array $r): void {},
    static fn(EnvironmentDriver $d): bool => true
);
assert_status_command($failureExit === 9, 'status preserves target plan failure exit');

echo "PASS: status command\n";
