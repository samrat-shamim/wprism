<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/Command/PromoteCommand.php';

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

// Ordinary promotion preserves the public argv and binds no frozen context.
$ordinaryExit = PromoteCommand::run($driver, ['--force-code-drift'], $scoped, $ordinary);
assert_promote_command($ordinaryExit === 1, 'an ordinary result envelope keeps the historical integer fallback');
assert_promote_command(count($ordinaryCalls) === 1 && count($scopedCalls) === 0, 'ordinary promotion selects only the ordinary callback');
assert_promote_command($ordinaryCalls[0][0] === $driver, 'ordinary callback receives the selected environment driver');
assert_promote_command($ordinaryCalls[0][1] === ['--force-code-drift'], 'ordinary callback receives flags in original order');
assert_promote_command($ordinaryCalls[0][2] === null, 'public promotion does not invent a frozen context');

// A concrete scope contract flag selects the separate signed scoped profile
// before ordinary promotion can inspect or contact the target.
$scopedExit = PromoteCommand::run(
    $driver,
    ['--scope-contract=/tmp/contract', '--format=json'],
    $scoped,
    static function (): never { fail_promote_command('ordinary callback ran for scoped promotion'); }
);
assert_promote_command($scopedExit === 17, 'scoped callback exit propagates unchanged');
assert_promote_command(count($scopedCalls) === 1, 'scoped promotion selects exactly one callback');
assert_promote_command($scopedCalls[0][0] === $driver, 'scoped callback receives the selected environment driver');
assert_promote_command(
    $scopedCalls[0][1] === ['--scope-contract=/tmp/contract', '--format=json'],
    'scoped callback receives the complete original argv'
);

// PassthroughCommand owns the prefix grammar, including malformed scope flag
// shapes; routing must preserve that refusal path for the scoped handler.
$malformedScope = PromoteCommand::run(
    $driver,
    ['--scope-contractX'],
    static fn(EnvironmentDriver $received, array $args): int => 23,
    static function (): never { fail_promote_command('ordinary callback ran for malformed scope flag'); }
);
assert_promote_command($malformedScope === 23, 'any scope-contract-prefixed argument remains on the scoped refusal path');

// A normal integer from the ordinary state machine still propagates exactly.
$ordinaryInteger = PromoteCommand::run(
    $driver,
    [],
    static function (): never { fail_promote_command('scoped callback ran without a scope flag'); },
    static fn(EnvironmentDriver $received, array $args, ?array $frozen): int => 9
);
assert_promote_command($ordinaryInteger === 9, 'ordinary integer exit propagates unchanged');

echo "PASS: promote command\n";
