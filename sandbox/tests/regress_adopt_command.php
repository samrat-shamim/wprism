<?php
declare(strict_types=1);

require_once __DIR__ . '/../../cli/src/Command/AdoptCommand.php';

use Duo\Orchestrator\AdoptionTransport;
use Duo\Orchestrator\AdoptCommand;
use Duo\Orchestrator\DriverCapability;
use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;

function fail_adopt_command(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function assert_adopt_command(bool $condition, string $message): void {
    if (!$condition) {
        fail_adopt_command($message);
    }
}

final class AdoptCommandPlainDriver implements EnvironmentDriver {
    public int $calls = 0;

    public function name(): string { return 'plain-fixture'; }
    public function driverId(): string { return 'plain-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'plain fixture'; }
    public function captureRaw(string $script): array {
        $this->calls++;
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected target contact'];
    }
    public function captureWp(array $wpArgs): array {
        $this->calls++;
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected target contact'];
    }
    public function streamWp(array $wpArgs): int { $this->calls++; return 99; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('plain-fixture', 'plain-fixture', $operation, []);
    }
}

final class AdoptCommandFakeTransport implements AdoptionTransport, EnvironmentDriver {
    /** @var list<string> */
    public array $rawScripts = [];
    /** @var list<array> */
    public array $wpArgs = [];
    public int $uploadCalls = 0;

    public function __construct(private string $sourceRoot, private bool $failUpload = false) {}

    public function bootstrapCapability(): array {
        return ['supported' => true, 'reason' => 'fixture bootstrap authority', 'remediation' => ''];
    }
    public function name(): string { return 'fake-adoption'; }
    public function driverId(): string { return 'fake-adoption'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function wpPath(): string { return '/fixture/wp'; }
    public function describe(): string { return 'fake adoption fixture'; }

    public function captureRaw(string $script): array {
        $this->rawScripts[] = $script;
        if ($script === 'echo duo-reachable') {
            return ['exit' => 0, 'stdout' => "duo-reachable\n", 'stderr' => ''];
        }
        if (str_contains($script, 'git ls-files --error-unmatch .duo-env-values.json')) {
            return ['exit' => 0, 'stdout' => "duo-untracked\n", 'stderr' => ''];
        }
        if (str_contains($script, 'site.duo.json')) {
            return ['exit' => 0, 'stdout' => "duo-repo-ok\n", 'stderr' => ''];
        }
        if (str_contains($script, 'archive=') && str_contains($script, 'agent_new=')) {
            return ['exit' => 0, 'stdout' => "duo-repo-created\n", 'stderr' => ''];
        }
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }

    public function captureWp(array $args): array {
        $this->wpArgs[] = $args;
        if ($args === ['core', 'is-installed']) {
            return ['exit' => 0, 'stdout' => "\n", 'stderr' => ''];
        }
        $snippet = (string) ($args[1] ?? '');
        if (str_contains($snippet, 'WPMU_PLUGIN_DIR')) {
            return ['exit' => 0, 'stdout' => "/fixture/mu\n", 'stderr' => ''];
        }
        if (str_contains($snippet, 'DUO_AGENT_VERSION')) {
            $source = (string) file_get_contents($this->sourceRoot . '/agent/duo.php');
            preg_match("/define\\(\\s*'DUO_AGENT_VERSION'\\s*,\\s*'([^']+)'\\s*\\)/", $source, $match);
            return ['exit' => 0, 'stdout' => ($match[1] ?? 'unknown') . "\n", 'stderr' => ''];
        }
        if (str_contains($snippet, 'duo-policy-ok')) {
            return ['exit' => 0, 'stdout' => "duo-policy-ok\n", 'stderr' => ''];
        }
        if (str_contains($snippet, 'class_exists')) {
            return ['exit' => 0, 'stdout' => "duo-ok\n", 'stderr' => ''];
        }
        if (str_contains($snippet, 'DISALLOW_FILE_MODS')) {
            return ['exit' => 0, 'stdout' => "duo-set\n", 'stderr' => ''];
        }
        if (str_contains($snippet, 'PHP_VERSION')) {
            return ['exit' => 0, 'stdout' => "8.3.33|11.8.8|mariadb|7.0.2\n", 'stderr' => ''];
        }
        return ['exit' => 99, 'stdout' => '', 'stderr' => 'unexpected WordPress probe'];
    }

    public function uploadFile(string $localPath, string $remotePath): array {
        $this->uploadCalls++;
        if ($this->failUpload) {
            return ['exit' => 73, 'stdout' => '', 'stderr' => 'fixture upload refused'];
        }
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }

    public function streamWp(array $wpArgs): int { return 99; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver(
            'fake-adoption',
            'fake-adoption',
            $operation,
            [
                DriverCapability::ATTACH => true,
                DriverCapability::RAW_CONTROL => true,
                DriverCapability::WP_CONTROL => true,
                DriverCapability::BOOTSTRAP => true,
                DriverCapability::CODE_TRANSFER => true,
            ]
        );
    }
}

$sourceRoot = dirname(__DIR__, 2);

$plain = new AdoptCommandPlainDriver();
$extraExit = AdoptCommand::run($plain, ['--unexpected'], $sourceRoot);
assert_adopt_command($extraExit === 1, 'extra adopt arguments refuse before any target call');
assert_adopt_command($plain->calls === 0, 'extra-argument refusal is target-free');

$plainExit = AdoptCommand::run($plain, [], $sourceRoot);
assert_adopt_command($plainExit === 1, 'non-adoption drivers refuse the adopt command');
assert_adopt_command($plain->calls === 0, 'transport-capability refusal is target-free');

$failed = new AdoptCommandFakeTransport($sourceRoot, true);
ob_start();
$failedExit = AdoptCommand::run($failed, [], $sourceRoot);
$failedOutput = (string) ob_get_clean();
assert_adopt_command($failedExit === 73, 'archive upload failure preserves the transport exit code');
assert_adopt_command(str_contains($failedOutput, 'adopt phase: staged install + transactional doctor'), 'failure prints the adopt phase');
assert_adopt_command($failed->uploadCalls === 1, 'failed adoption stops at the first upload');
assert_adopt_command(count($failed->rawScripts) === 1, 'upload failure performs only the transport preflight');
assert_adopt_command(count($failed->wpArgs) === 2, 'upload failure performs only the WordPress preflight');

$healthy = new AdoptCommandFakeTransport($sourceRoot);
ob_start();
$healthyExit = AdoptCommand::run($healthy, [], $sourceRoot);
$healthyOutput = (string) ob_get_clean();
assert_adopt_command($healthyExit === 0, 'successful adoption returns zero after doctor verification');
assert_adopt_command(str_contains($healthyOutput, 'adopt: installed agent '), 'success reports the installed agent');
assert_adopt_command(str_contains($healthyOutput, '[PASS] transport reachable'), 'success renders the doctor result');
assert_adopt_command(str_contains($healthyOutput, '[WARN] DISALLOW_FILE_MODS set') === false, 'healthy fixture does not invent an advisory warning');
assert_adopt_command($healthy->uploadCalls === 1, 'successful adoption uploads one archive');
assert_adopt_command(count($healthy->rawScripts) === 9, 'adoption plus doctor performs the bounded raw probe set (' . count($healthy->rawScripts) . ')');
assert_adopt_command(count($healthy->wpArgs) === 8, 'adoption plus doctor performs the bounded WordPress probe set (' . count($healthy->wpArgs) . ')');

echo "PASS: adopt command\n";
