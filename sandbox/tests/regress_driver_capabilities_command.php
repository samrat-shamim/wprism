<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/EnvironmentDriver.php';
require_once __DIR__ . '/../../cli/src/DriverCapabilitiesCommand.php';

use Duo\Orchestrator\DriverCapability;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\DriverCapabilitiesCommand;
use Duo\Orchestrator\EnvironmentDriver;

function fail_driver_capabilities(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_driver_capabilities(bool $condition, string $message): void {
    if (!$condition) fail_driver_capabilities($message);
}

final class FixtureDriverCapabilitiesDriver implements EnvironmentDriver {
    /** @var list<string> */
    public array $operations = [];
    public bool $blocked = false;
    public bool $throws = false;

    public function name(): string { return 'fixture'; }
    public function driverId(): string { return 'fixture-driver'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'fixture driver'; }
    public function captureRaw(string $script): array { return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function captureWp(array $wpArgs): array { return ['exit' => 0, 'stdout' => '', 'stderr' => '']; }
    public function streamWp(array $wpArgs): int { return 0; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        $this->operations[] = $operation;
        if ($this->throws) throw new RuntimeException('fixture capability failure');
        $supported = $this->blocked ? [] : [DriverCapability::ATTACH => true];
        $details = $this->blocked ? [
            DriverCapability::ATTACH => [
                'reason' => 'fixture refuses attach',
                'remediation' => 'repair the fixture',
            ],
        ] : [];
        return DriverCapabilityReport::forDriver('fixture', 'fixture-driver', $operation, $supported, $details);
    }
}

$driver = new FixtureDriverCapabilitiesDriver();

ob_start();
$ready = DriverCapabilitiesCommand::run($driver, ['--operation=attach', '--format=json']);
$json = (string) ob_get_clean();
$record = json_decode(trim($json), true);
assert_driver_capabilities($ready === 0, 'ready report exits successfully');
assert_driver_capabilities(is_array($record), 'JSON report is an object');
assert_driver_capabilities(($record['operation'] ?? null) === 'attach', 'operation is passed exactly');
assert_driver_capabilities($driver->operations === ['attach'], 'driver is contacted once for the requested operation');

ob_start();
$duplicate = DriverCapabilitiesCommand::run($driver, ['--format=json', '--format=json']);
$duplicateOutput = (string) ob_get_clean();
assert_driver_capabilities($duplicate === 1, 'duplicate format refuses');
assert_driver_capabilities($duplicateOutput === '', 'duplicate format does not emit a report');

$driver->blocked = true;
ob_start();
$blocked = DriverCapabilitiesCommand::run($driver, []);
$human = (string) ob_get_clean();
assert_driver_capabilities($blocked === 1, 'blocked report exits with refusal');
assert_driver_capabilities(str_contains($human, 'BLOCKED'), 'human report renders blocked state');
assert_driver_capabilities(str_contains($human, 'fixture refuses attach'), 'human report renders reason');
assert_driver_capabilities(str_contains($human, 'remediation: repair the fixture'), 'human report renders remediation');

$driver->throws = true;
ob_start();
$failed = DriverCapabilitiesCommand::run($driver, []);
$failedOutput = (string) ob_get_clean();
assert_driver_capabilities($failed === 1, 'driver exception refuses');
assert_driver_capabilities($failedOutput === '', 'driver exception does not emit a partial report');

$stderr = ''; // The direct seam intentionally verifies return/output; stderr is the CLI channel.
echo "PASS: driver capabilities command\n";
