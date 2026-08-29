<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Command/PendingCommand.php';

use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\EnvironmentDriver;
use WPrism\Orchestrator\PendingCommand;

function fail_pending_command(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}
function assert_pending_command(bool $condition, string $message): void {
    if (!$condition) fail_pending_command($message);
}

final class PendingCommandDriver implements EnvironmentDriver {
    public int $captureCalls = 0;
    public int $streamCalls = 0;
    public string $mode;
    public function __construct(string $mode) { $this->mode = $mode; }
    public function name(): string { return 'pending-fixture'; }
    public function driverId(): string { return 'pending-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'pending fixture'; }
    public function captureRaw(string $script): array { return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function captureWp(array $wpArgs): array {
        $this->captureCalls++;
        if ($this->mode === 'failure') {
            return ['exit' => 7, 'stdout' => '', 'stderr' => 'queue unavailable'];
        }
        if ($this->mode === 'malformed') {
            return ['exit' => 0, 'stdout' => "not-json\n", 'stderr' => ''];
        }
        $items = $this->mode === 'items'
            ? [['section' => 'options', 'key' => 'fixture_option', 'proposal' => 'runtime', 'evidence' => [], 'ref_hint' => null]]
            : [];
        return ['exit' => 0, 'stdout' => json_encode($items) . "\n", 'stderr' => ''];
    }
    public function streamWp(array $wpArgs): int { $this->streamCalls++; return 23; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('pending-fixture', 'pending-fixture', $operation, []);
    }
}

$empty = new PendingCommandDriver('empty');
ob_start();
$emptyExit = PendingCommand::run($empty, []);
$emptyOutput = (string) ob_get_clean();
assert_pending_command($emptyExit === 0, 'empty queue exits successfully');
assert_pending_command($empty->captureCalls === 1, 'empty queue fetches once');
assert_pending_command($emptyOutput === "review queue is empty\n", 'empty queue keeps exact output');

$items = new PendingCommandDriver('items');
ob_start();
$itemsExit = PendingCommand::run($items, []);
$itemsOutput = (string) ob_get_clean();
assert_pending_command($itemsExit === 0, 'populated queue exits successfully');
assert_pending_command(str_contains($itemsOutput, 'fixture_option'), 'populated queue uses the shared Pending renderer');

$json = new PendingCommandDriver('empty');
assert_pending_command(PendingCommand::run($json, ['--format=json']) === 23, 'JSON mode preserves streamed agent exit');
assert_pending_command($json->streamCalls === 1 && $json->captureCalls === 0, 'JSON mode streams without parsing or duplicate fetch');

$bad = new PendingCommandDriver('failure');
ob_start();
$badExit = PendingCommand::run($bad, []);
$badOutput = (string) ob_get_clean();
assert_pending_command($badExit === 7, 'queue transport failure preserves agent exit');
assert_pending_command($badOutput === '', 'queue failure does not print a human table');

$malformed = new PendingCommandDriver('malformed');
ob_start();
$malformedExit = PendingCommand::run($malformed, []);
ob_end_clean();
assert_pending_command($malformedExit === 1, 'malformed queue JSON refuses');

echo "PASS: pending command\n";
