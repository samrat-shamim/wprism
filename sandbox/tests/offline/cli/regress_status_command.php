<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Command/StatusCommand.php';

use Duo\Orchestrator\DriverCapabilityReport;
use Duo\Orchestrator\EnvironmentDriver;
use Duo\Orchestrator\PlanContract;
use Duo\Orchestrator\PlanSummary;
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
        if ($this->mode === 'env-missing') {
            $name = 'outfitters_catalog_gateway_secret';
            $plan['env_missing'] = [['name' => $name, 'required' => true]];
            $plan['warnings'] = [
                "env_missing: option '$name' is required and not yet provisioned on "
                    . "this environment — see 'wp duo env-set --name=$name --stdin'",
                'env_missing: separate diagnostic must remain visible',
            ];
        }
        if ($this->mode === 'unsafe-env-missing') {
            $plan['env_missing'] = [['name' => "unsafe\0option", 'required' => true]];
        }
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

$envMissing = new StatusCommandDriver('env-missing');
ob_start();
$envMissingExit = StatusCommand::run(
    $envMissing,
    [],
    static function (string $c, string $m, string $r): void {},
    static function (array $r): void {},
    static fn(EnvironmentDriver $d): bool => true
);
$envMissingOutput = (string) ob_get_clean();
assert_status_command($envMissingExit === 1, 'required env value keeps status non-ready');
assert_status_command(
    str_contains(
        $envMissingOutput,
        'run: `duo env-set status-fixture --name=outfitters_catalog_gateway_secret --stdin`'
    ),
    'host status renders the exact environment-bound secret remediation'
);
assert_status_command(
    !str_contains($envMissingOutput, 'wp duo env-set'),
    'host status does not mix target-side env-set advice into host remediation'
);
assert_status_command(
    str_contains($envMissingOutput, 'WARNING: env_missing: separate diagnostic must remain visible'),
    'host status suppresses only the exact duplicate target warning'
);

$customRegistryMissing = new StatusCommandDriver('env-missing');
ob_start();
$customRegistryExit = StatusCommand::run(
    $customRegistryMissing,
    [],
    static function (string $c, string $m, string $r): void {},
    static function (array $r): void {},
    static fn(EnvironmentDriver $d): bool => true,
    '/tmp/custom registry.json'
);
$customRegistryOutput = (string) ob_get_clean();
assert_status_command($customRegistryExit === 1, 'custom-registry env value keeps status non-ready');
assert_status_command(
    str_contains(
        $customRegistryOutput,
        "run: `duo '--envs-file=/tmp/custom registry.json' env-set status-fixture "
            . '--name=outfitters_catalog_gateway_secret --stdin`'
    ),
    'status remediation preserves the exact operator-selected registry binding'
);

$unsafeRegistryMissing = new StatusCommandDriver('env-missing');
ob_start();
$unsafeRegistryExit = StatusCommand::run(
    $unsafeRegistryMissing,
    [],
    static function (string $c, string $m, string $r): void {},
    static function (array $r): void {},
    static fn(EnvironmentDriver $d): bool => true,
    "/tmp/registry\nINJECT"
);
$unsafeRegistryOutput = (string) ob_get_clean();
assert_status_command(
    $unsafeRegistryExit === 1
        && str_contains($unsafeRegistryOutput, 'correct the selected environment registry path')
        && !str_contains($unsafeRegistryOutput, 'INJECT')
        && !str_contains($unsafeRegistryOutput, "\x1b"),
    'unsafe selected registry produces bounded one-line remediation without reproducing it'
);

$hostSource = (string) file_get_contents(__DIR__ . '/../../../../cli/duo');
assert_status_command(
    str_contains($hostSource, "'status' => cmd_status(\$transport, \$extra, \$envsFileOverride)")
        && str_contains($hostSource, 'cmd_status($driver, [], $envsFileOverride)'),
    'public status and init follow-up both preserve the selected registry through the thin facade'
);

$unsafeEnvMissing = new StatusCommandDriver('unsafe-env-missing');
ob_start();
$unsafeEnvMissingExit = StatusCommand::run(
    $unsafeEnvMissing,
    [],
    static function (string $c, string $m, string $r): void {},
    static function (array $r): void {},
    static fn(EnvironmentDriver $d): bool => true
);
$unsafeEnvMissingOutput = (string) ob_get_clean();
assert_status_command($unsafeEnvMissingExit === 1, 'control-bearing option name remains non-ready');
assert_status_command(
    str_contains($unsafeEnvMissingOutput, '<invalid-name>')
        && str_contains($unsafeEnvMissingOutput, 'cannot render a safe command')
        && !str_contains($unsafeEnvMissingOutput, "unsafe\0option"),
    'control-bearing option name produces bounded one-line remediation instead of throwing'
);

$unsafeEnvironmentPlan = [];
foreach (PlanContract::requiredBuckets() as $bucket) $unsafeEnvironmentPlan[$bucket] = [];
$unsafeEnvironmentPlan['env_missing'] = [['name' => 'secret_name', 'required' => true]];
$unsafeEnvironmentLines = implode("\n", PlanSummary::render(
    $unsafeEnvironmentPlan,
    [],
    '--envs-file=/tmp/other'
)['lines']);
assert_status_command(
    str_contains($unsafeEnvironmentLines, 'cannot render a safe command')
        && !str_contains($unsafeEnvironmentLines, 'duo env-set --envs-file='),
    'option-looking environment never renders as a positional command token'
);

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
