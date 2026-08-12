<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/DoctorCommand.php';

use Duo\Orchestrator\AdoptionTransport;
use Duo\Orchestrator\DriverCapability;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\DoctorCommand;
use Duo\Orchestrator\EnvironmentDriver;

function fail_doctor_command(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_doctor_command(bool $condition, string $message): void {
    if (!$condition) fail_doctor_command($message);
}

final class UnreachableDoctorDriver implements EnvironmentDriver {
    public int $rawCalls = 0;
    public int $wpCalls = 0;
    public function name(): string { return 'fixture'; }
    public function driverId(): string { return 'fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'fixture'; }
    public function captureRaw(string $script): array {
        $this->rawCalls++;
        return ['exit' => 7, 'stdout' => '', 'stderr' => 'fixture transport down'];
    }
    public function captureWp(array $wpArgs): array {
        $this->wpCalls++;
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected target contact'];
    }
    public function streamWp(array $wpArgs): int { return 99; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('fixture', 'fixture', $operation, []);
    }
}

class HealthyDoctorDriver implements EnvironmentDriver {
    public int $rawCalls = 0;
    public int $wpCalls = 0;

    public function __construct(private bool $agentPresent = true) {}

    public function name(): string { return 'healthy-fixture'; }
    public function driverId(): string { return 'healthy-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'healthy fixture'; }
    public function captureRaw(string $script): array {
        $this->rawCalls++;
        if ($script === 'echo duo-reachable') return ['exit' => 0, 'stdout' => "duo-reachable\n", 'stderr' => ''];
        if (str_contains($script, 'git ls-files --error-unmatch .duo-env-values.json')) {
            return ['exit' => 0, 'stdout' => "duo-untracked\n", 'stderr' => ''];
        }
        if (str_contains($script, 'site.duo.json')) {
            return ['exit' => 0, 'stdout' => "duo-repo-ok\n", 'stderr' => ''];
        }
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected raw probe'];
    }
    public function captureWp(array $wpArgs): array {
        $this->wpCalls++;
        if ($wpArgs === ['core', 'is-installed']) return ['exit' => 0, 'stdout' => "\n", 'stderr' => ''];
        $snippet = (string) ($wpArgs[1] ?? '');
        if (str_contains($snippet, 'class_exists')) {
            return ['exit' => 0, 'stdout' => ($this->agentPresent ? "duo-ok\n" : "duo-missing\n"), 'stderr' => ''];
        }
        if (str_contains($snippet, 'DISALLOW_FILE_MODS')) return ['exit' => 0, 'stdout' => "duo-unset\n", 'stderr' => ''];
        if (str_contains($snippet, 'PHP_VERSION')) return ['exit' => 0, 'stdout' => "8.3.33|11.8.8|mariadb|7.0.2\n", 'stderr' => ''];
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected WordPress probe'];
    }
    public function streamWp(array $wpArgs): int { return 99; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('healthy-fixture', 'healthy-fixture', $operation, []);
    }
}

class AdoptableDoctorDriver extends HealthyDoctorDriver implements AdoptionTransport {
    public function __construct(bool $agentPresent = true, private bool $adoptionAuthorized = true) {
        parent::__construct($agentPresent);
    }
    public function wpPath(): string { return '/fixture/wordpress'; }
    public function bootstrapCapability(): array {
        return $this->adoptionAuthorized
            ? ['supported' => true, 'reason' => '', 'remediation' => '']
            : [
                'supported' => false,
                'reason' => 'fixture bootstrap is not authorized',
                'remediation' => 'authorize bootstrap in the untracked machine-local overlay',
            ];
    }
    public function uploadFile(string $localPath, string $remotePath): array {
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected upload'];
    }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        $supported = [
            DriverCapability::ATTACH => true,
            DriverCapability::RAW_CONTROL => true,
            DriverCapability::WP_CONTROL => true,
        ];
        $unsupported = [];
        if ($this->adoptionAuthorized) {
            $supported[DriverCapability::BOOTSTRAP] = true;
            $supported[DriverCapability::CODE_TRANSFER] = true;
        } else {
            $detail = [
                'reason' => 'fixture bootstrap is not authorized',
                'remediation' => 'authorize bootstrap in the untracked machine-local overlay',
            ];
            $unsupported[DriverCapability::BOOTSTRAP] = $detail;
            $unsupported[DriverCapability::CODE_TRANSFER] = $detail;
        }
        return DriverCapabilityReport::forDriver(
            'healthy-fixture', 'healthy-fixture', $operation, $supported, $unsupported
        );
    }
}

$driver = new UnreachableDoctorDriver();
ob_start();
$exit = DoctorCommand::run($driver);
$output = (string) ob_get_clean();
assert_doctor_command($exit === 1, 'unreachable doctor exits with failure');
assert_doctor_command($driver->rawCalls === 1, 'doctor performs only the reachability probe before refusing');
assert_doctor_command($driver->wpCalls === 0, 'doctor does not contact WordPress after reachability fails');
assert_doctor_command(str_contains($output, '[FAIL] transport reachable — fixture transport down'), 'doctor renders the transport failure');
assert_doctor_command(str_contains($output, '[FAIL] WordPress installed — skipped: transport unreachable'), 'doctor renders skipped downstream checks');

$healthy = new HealthyDoctorDriver();
ob_start();
$healthyExit = DoctorCommand::run($healthy);
$healthyOutput = (string) ob_get_clean();
assert_doctor_command($healthyExit === 0, 'healthy doctor exits successfully');
assert_doctor_command($healthy->rawCalls === 3, 'healthy doctor performs each raw probe exactly once');
assert_doctor_command($healthy->wpCalls === 4, 'healthy doctor performs each WordPress probe exactly once');
assert_doctor_command(str_contains($healthyOutput, '[PASS] transport reachable'), 'healthy doctor renders a pass row');
assert_doctor_command(str_contains($healthyOutput, '[WARN] DISALLOW_FILE_MODS set'), 'healthy doctor renders advisory warning');

$missingAgent = new AdoptableDoctorDriver(false);
ob_start();
$missingAgentExit = DoctorCommand::run($missingAgent);
$missingAgentOutput = (string) ob_get_clean();
assert_doctor_command($missingAgentExit === 1, 'pre-adoption doctor remains a blocking failure');
assert_doctor_command(str_contains(
    $missingAgentOutput,
    "[FAIL] duo agent present — agent class not found (wp eval returned 'duo-missing'); next step: duo adopt 'healthy-fixture'"
), 'pre-adoption doctor prints the exact host-side adopt command');

$unauthorizedLocalAgent = new AdoptableDoctorDriver(false, false);
ob_start();
$unauthorizedLocalExit = DoctorCommand::run($unauthorizedLocalAgent);
$unauthorizedLocalOutput = (string) ob_get_clean();
assert_doctor_command($unauthorizedLocalExit === 1, 'missing agent remains blocking when local adoption lacks authorization');
assert_doctor_command(
    str_contains($unauthorizedLocalOutput, "host-side duo adopt is not ready for driver 'healthy-fixture'")
        && str_contains($unauthorizedLocalOutput, 'authorize bootstrap in the untracked machine-local overlay')
        && !str_contains($unauthorizedLocalOutput, 'next step: duo adopt'),
    'adoption-capable doctor relays its target-free capability remediation instead of suggesting a blocked adopt'
);

$missingDockerAgent = new HealthyDoctorDriver(false);
ob_start();
$missingDockerAgentExit = DoctorCommand::run($missingDockerAgent);
$missingDockerAgentOutput = (string) ob_get_clean();
assert_doctor_command($missingDockerAgentExit === 1, 'missing agent remains blocking when host-side adoption is unavailable');
assert_doctor_command(
    str_contains($missingDockerAgentOutput, "host-side duo adopt is unavailable for driver 'healthy-fixture'")
        && str_contains($missingDockerAgentOutput, "install or mount the Duo agent through that environment's control plane")
        && !str_contains($missingDockerAgentOutput, "next step: duo adopt"),
    'non-adoptable doctor directs the operator to the environment control plane instead of an impossible adopt command'
);

ob_start();
DoctorCommand::render([
    'ok' => true,
    'checks' => [
        ['label' => 'hard check', 'ok' => true, 'detail' => ''],
        ['label' => 'advisory check', 'ok' => false, 'detail' => 'recommended setting missing', 'advisory' => true],
    ],
]);
$rendered = (string) ob_get_clean();
assert_doctor_command(str_contains($rendered, '[PASS] hard check'), 'render preserves pass rows');
assert_doctor_command(str_contains($rendered, '[WARN] advisory check'), 'render labels advisory failures as warnings');
assert_doctor_command(str_contains($rendered, 'recommended setting missing'), 'render preserves advisory detail');

echo "PASS: doctor command\n";
