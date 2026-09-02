<?php
/**
 * issue #3351 slice 18: direct host-command boundary coverage for `wprism env`.
 *
 * EnvironmentLifecycle retains the durable provider/journal/recovery state
 * machine, and regress_environment_lifecycle.php continues to exercise the
 * public CLI product path. This test pins the newly extracted command owner:
 * it is callable without cli/wprism, keeps the early public-intent refusal ahead
 * of privileged registry/provider setup, and owns exact reap-source and
 * receipt projection behavior rather than leaving a second copy in the facade.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Command/EnvironmentCommand.php';
require_once __DIR__ . '/../../../../cli/src/Command/RehearseCommand.php';

use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\EnvironmentDriver;
use WPrism\Orchestrator\EnvironmentCommand;
use WPrism\Orchestrator\RehearseCommand;

$failures = [];
$check = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
        return;
    }
    echo "ok: $message\n";
};

final class EnvironmentCommandRehearseDriver implements EnvironmentDriver {
    public function name(): string { return 'preview'; }
    public function driverId(): string { return 'preview-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'preview fixture'; }
    public function captureRaw(string $script): array { return ['exit' => 1, 'stdout' => '', 'stderr' => 'must not run']; }
    public function captureWp(array $wpArgs): array { return ['exit' => 1, 'stdout' => '', 'stderr' => 'must not run']; }
    public function streamWp(array $wpArgs): int { return 1; }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('preview-fixture', 'preview-fixture', $operation, []);
    }
}

$prepared = [
    'run' => ['source_environment' => 'production'],
    'events' => [['event' => 'snapshot-prepared']],
];
$check(
    EnvironmentCommand::reapSourceName($prepared) === 'production',
    'unfinished prepared source snapshot keeps the exact journaled source provider available for abort'
);

$check(
    EnvironmentCommand::reapSourceName([
        'run' => ['source_environment' => 'production'],
        'events' => [['event' => 'target-acquired']],
    ]) === null,
    'target acquisition suppresses source-provider recovery during reap'
);
$check(
    EnvironmentCommand::reapSourceName([
        'run' => ['source_environment' => 'production'],
        'events' => [['event' => 'snapshot-read']],
    ]) === null,
    'immutable snapshot read suppresses source-provider recovery during reap'
);
$check(
    EnvironmentCommand::reapSourceName([
        'run' => ['source_environment' => 'production'],
        'events' => [],
    ]) === null,
    'a run without a prepared source snapshot never invents a source-provider cleanup action'
);

try {
    EnvironmentCommand::reapSourceName([
        'run' => [],
        'events' => [['event' => 'snapshot-prepared']],
    ]);
    $check(false, 'prepared snapshot without journaled source environment refuses');
} catch (RuntimeException $e) {
    $check(
        str_contains($e->getMessage(), 'unfinished source snapshot has no journaled source environment'),
        'prepared snapshot without journaled source environment refuses'
    );
}

$materialized = [
    'operation_id' => 'operation-001',
    'resource_id' => 'resource-001',
    'mode' => 'create',
    'branch_commit' => str_repeat('a', 40),
    'snapshot_set_id' => 'snapshot-001',
    'code_revision' => str_repeat('b', 64),
    'state_revision' => str_repeat('c', 64),
    'outer_artifact_hash' => str_repeat('d', 64),
    'url' => 'https://branch.example.test',
    'expires_at' => '2030-01-02T03:04:05Z',
    'receipt_sha256' => str_repeat('e', 64),
];
ob_start();
EnvironmentCommand::renderReceipt($materialized, false, 'materialize');
$human = (string) ob_get_clean();
$check(
    str_contains($human, 'environment materialize complete: operation=operation-001 resource=resource-001')
        && str_contains($human, 'mode=create branch=' . str_repeat('a', 40) . ' snapshot=snapshot-001')
        && str_contains($human, 'release code=' . str_repeat('b', 64) . ' state=' . str_repeat('c', 64)
            . ' outer=' . str_repeat('d', 64))
        && str_contains($human, 'url=https://branch.example.test expires_at=2030-01-02T03:04:05Z')
        && str_contains($human, 'receipt=' . str_repeat('e', 64)),
    'direct materialize receipt preserves all established human projection lines'
);

ob_start();
EnvironmentCommand::renderReceipt([
    'operation_id' => 'operation-002',
    'resource_id' => 'resource-002',
    'disposition' => 'detached',
    'absence_proof_sha256' => str_repeat('f', 64),
    'receipt_sha256' => str_repeat('0', 64),
], true, 'reap');
$json = (string) ob_get_clean();
$decoded = json_decode($json, true);
$check(
    is_array($decoded) && ($decoded['disposition'] ?? null) === 'detached'
        && ($decoded['absence_proof_sha256'] ?? null) === str_repeat('f', 64),
    'direct reap receipt preserves the complete machine-readable receipt'
);

$exit = EnvironmentCommand::run(
    ['materialize', 'branch', '--from', 'production'],
    null,
    static fn(mixed $_driver, array $_context): int => throw new RuntimeException('promotion callback must not run for malformed intent')
);
$check($exit === 1, 'direct command invocation refuses incomplete intent before registry/provider/journal or promotion work');

ob_start();
$jsonExit = EnvironmentCommand::run(
    ['materialize', 'branch', '--from', 'production', '--format=json'],
    null,
    static fn(mixed $_driver, array $_context): int => throw new RuntimeException('promotion callback must not run for malformed intent')
);
$jsonRefusal = json_decode((string) ob_get_clean(), true);
$check(
    $jsonExit === 1
        && is_array($jsonRefusal)
        && ($jsonRefusal['format'] ?? null) === 'wprism-command-refusal/v1'
        && ($jsonRefusal['command'] ?? null) === 'env materialize'
        && ($jsonRefusal['reason_code'] ?? null) === 'branch_environment_operation_failed',
    'machine materialization failure emits one stable refusal instead of a prose-only stderr gap'
);

ob_start();
$rehearseExit = RehearseCommand::run(
    new EnvironmentCommandRehearseDriver(),
    ['--from=production', '--branch=feature/refusal', '--format=json'],
    '/definitely-absent-wprism-environment-registry.json',
    dirname(__DIR__, 4),
    static fn(mixed $_driver, array $_context): int => throw new RuntimeException('promotion must not run'),
);
$rehearseOutput = (string) ob_get_clean();
$rehearseRefusal = json_decode($rehearseOutput, true);
$check(
    $rehearseExit === 1
        && is_array($rehearseRefusal)
        && ($rehearseRefusal['format'] ?? null) === 'wprism-command-refusal/v1'
        && ($rehearseRefusal['reason_code'] ?? null) === 'branch_environment_operation_failed'
        && substr_count($rehearseOutput, '"format"') === 1,
    'JSON rehearsal forwards its nested materialization refusal as exactly one machine document'
);

$facade = (string) file_get_contents(__DIR__ . '/../../../../cli/wprism');
$command = (string) file_get_contents(__DIR__ . '/../../../../cli/src/Command/EnvironmentCommand.php');
$facadeStart = strpos($facade, 'function cmd_environment(');
$facadeEnd = $facadeStart === false ? false : strpos($facade, "\n}\n", $facadeStart);
$facadeBody = $facadeStart === false || $facadeEnd === false ? '' : substr($facade, $facadeStart, $facadeEnd - $facadeStart + 3);
$check(
    str_contains($facadeBody, 'return EnvironmentCommand::run(')
        && str_contains($facadeBody, 'cmd_promote_frozen')
        && str_contains($facadeBody, 'EnvironmentCommand::targetBootstrap(dirname(__DIR__))')
        && !str_contains($facadeBody, 'Registry::load')
        && !str_contains($facadeBody, 'EnvironmentMaterializer::'),
    'cli/wprism is a thin environment facade with explicit adoption and frozen-promotion handoffs'
);
$check(
    str_contains($command, 'EnvironmentCommandOptions::materialize($args)')
        && strpos($command, 'EnvironmentCommandOptions::materialize($args)') < strpos($command, 'Registry::load(')
        && str_contains($command, 'EnvironmentMaterializer::materialize(')
        && str_contains($command, 'EnvironmentMaterializer::reap(')
        && str_contains($command, '$targetDriver instanceof AdoptionTransport ? $targetBootstrap : null')
        && str_contains($command, 'Adopt::distributionDigest($sourceRoot)')
        && str_contains($command, 'Adopt::install($driver, $sourceRoot,'),
    'command parses public intent before privileged setup and composes adoption only for an authorized target transport'
);

$environment = (string) file_get_contents(__DIR__ . '/../../../../cli/src/Environment/EnvironmentLifecycle.php');
$check(
    !str_contains($environment, '/../Onboarding/')
        && !str_contains($environment, 'Adopt::')
        && str_contains($environment, "recordIntent(\$journal, \$operationId, 'target-agent-bootstrap'")
        && str_contains($environment, "phaseData(\$journal, \$operationId, 'target-agent-bootstrapped')"),
    'environment engine journals the bootstrap receipt without depending on onboarding implementation'
);

if ($failures !== []) {
    fwrite(STDERR, "FAIL:\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}

echo "PASS: environment command boundary regression\n";
