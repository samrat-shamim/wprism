<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/PromoteCommand.php';

use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\PromoteCommand;

function fail_promote_command(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}
function assert_promote_command(bool $condition, string $message): void {
    if (!$condition) fail_promote_command($message);
}

final class PromoteCommandDriver implements EnvironmentDriver {
    public function name(): string { return 'promote-fixture'; }
    public function driverId(): string { return 'promote-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'promote fixture'; }
    public function captureRaw(string $script): array { return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function captureWp(array $wpArgs): array { return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function streamWp(array $wpArgs): int { return 0; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('promote-fixture', 'promote-fixture', $operation, []);
    }
}

$driver = new PromoteCommandDriver();
$ordinaryCalls = [];
$scopedCalls = [];
$ordinary = static function (EnvironmentDriver $received, array $args, ?array $frozen) use (&$ordinaryCalls): array {
    $ordinaryCalls[] = [$received, $args, $frozen];
    return ['format' => 'duo-promotion-result/v1'];
};
$scoped = static function (EnvironmentDriver $received, array $args) use (&$scopedCalls): int {
    $scopedCalls[] = [$received, $args];
    return 17;
};

// The foundation quarantine precedes both ordinary and scoped routing. It is
// the recovery-drift safety disposition, not a new promotion result mode.
ob_start();
$ordinaryExit = PromoteCommand::run($driver, ['--force-code-drift', '--format=json'], $scoped, $ordinary);
$ordinaryJson = (string) ob_get_clean();
$ordinaryPayload = json_decode($ordinaryJson, true);
assert_promote_command($ordinaryExit === 1, 'ordinary promotion is loudly quarantined');
assert_promote_command(
    is_array($ordinaryPayload)
        && ($ordinaryPayload['format'] ?? null) === 'duo-command-refusal/v1'
        && ($ordinaryPayload['reason_code'] ?? null) === 'promotion_recovery_decision_unbound',
    'ordinary JSON refusal has the stable value-free recovery-decision code'
);

ob_start();
$scopedExit = PromoteCommand::run(
    $driver,
    ['--scope-contract=/tmp/contract', '--format=json'],
    $scoped,
    $ordinary
);
$scopedPayload = json_decode((string) ob_get_clean(), true);
assert_promote_command($scopedExit === 1, 'scoped public promotion is quarantined at the same pre-routing boundary');
assert_promote_command(
    is_array($scopedPayload) && ($scopedPayload['reason_code'] ?? null) === 'promotion_recovery_decision_unbound',
    'scoped JSON refusal uses the identical stable safety reason'
);
assert_promote_command(
    $ordinaryCalls === [] && $scopedCalls === [],
    'no ordinary/scoped callback or target workflow runs behind the quarantine'
);

echo "PASS: promote command\n";
