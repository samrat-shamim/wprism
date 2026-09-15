<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../../cli/src/Command/DeployCommand.php';

use WPrism\Orchestrator\DeployCommand;
use WPrism\Orchestrator\CodeDeploy;
use WPrism\Orchestrator\DriverCapabilityReport;
use WPrism\Orchestrator\EnvironmentDriver;

function fail_deploy_command(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}
function assert_deploy_command(bool $condition, string $message): void {
    if (!$condition) fail_deploy_command($message);
}

final class DeployCommandDriver implements EnvironmentDriver {
    /** @var list<string> */
    public array $events = [];
    /** @var list<array<int,string>> */
    public array $calls = [];
    public int $preflightExit = 0;
    public int $codeBaselineExit = 0;
    public int $schemaStatusExit = 0;
    public int $storageStatusExit = 0;
    public int $storageVerifyExit = 0;
    public int $stageExit = 0;
    public int $exportExit = 0;
    public int $providerBeginExit = 0;
    public int $providerAdvanceExit = 0;
    public int $providerCompleteExit = 0;
    public int $lifecycleSettleExit = 0;
    public bool $providerDebtActive = false;
    public bool $dispositionBlocked = false;
    public bool $codeEnabled = true;
    public bool $codeChangeRequired = true;
    public bool $lifecycleChangeRequired = false;
    public string $codeBaselineState = 'exact';
    public bool $malformedCodeBaselineReceipt = false;
    public int $lostCodeBaselineResponses = 0;
    public int $malformedCodeBaselineResponses = 0;
    /** @var list<string> */
    public array $codeWarnings = [];
    public bool $schemaDeclared = false;
    public bool $schemaRequired = false;
    public bool $lifecycleSettlementDeclared = false;
    public bool $storagePrerequisiteDeclared = false;
    public bool $storagePrerequisiteAutomatic = false;
    public bool $storagePrerequisiteRequired = false;
    public bool $storageSettlementSatisfiesPrerequisite = true;
    public bool $leaseActive = false;
    public bool $abortSucceeds = true;
    public bool $abortRemovesLease = true;
    public bool $probeContenderAfterTerminalProvider = false;
    public bool $contenderPassedInitialFence = false;
    public ?int $contenderBeginExit = null;
    public ?bool $leaseActiveAtProviderComplete = null;
    /** @var array<string,mixed>|null */
    private ?array $committedCodeBaselineReceipt = null;
    private int $storageStatusCalls = 0;

    public function name(): string { return 'deploy-fixture'; }
    public function driverId(): string { return 'deploy-fixture'; }
    public function repoPath(): string { return '/fixture/repo'; }
    public function describe(): string { return 'deploy fixture'; }
    public function captureRaw(string $script): array {
        if (str_contains($script, 'provider-settlement-advance')) {
            preg_match("/--phase='([^']+)'/", $script, $match);
            $phase = (string) ($match[1] ?? 'unknown');
            $this->events[] = 'raw:provider-settlement-advance:' . $phase;
            return [
                'exit' => $this->providerAdvanceExit,
                'stdout' => '',
                'stderr' => $this->providerAdvanceExit === 0 ? '' : 'provider phase advance failed',
            ];
        }
        if (str_contains($script, 'provider-settlement-complete')) {
            $this->events[] = 'raw:provider-settlement-complete';
            $this->leaseActiveAtProviderComplete = $this->leaseActive;
            if ($this->providerCompleteExit === 0) {
                $this->providerDebtActive = false;
            }
            return [
                'exit' => $this->providerCompleteExit,
                'stdout' => '',
                'stderr' => $this->providerCompleteExit === 0 ? '' : 'provider completion failed',
            ];
        }
        $this->events[] = 'raw:mkdir';
        return ['exit' => 0, 'stdout' => '', 'stderr' => ''];
    }
    public function captureWp(array $wpArgs): array {
        $this->calls[] = $wpArgs;
        if (count(array_filter(
            $wpArgs,
            static fn(string $arg): bool => str_contains($arg, 'ProviderSettlementIntent::begin')
        )) === 1) {
            $this->events[] = 'capture:provider-settlement-begin';
            if ($this->providerBeginExit === 0) {
                $this->providerDebtActive = true;
            }
            return [
                'exit' => $this->providerBeginExit,
                'stdout' => json_encode([
                    'cipher_sha256' => str_repeat('c', 64),
                    'format' => 'wprism-provider-settlement-intent/v1',
                    'phases' => $this->providerPhases(),
                    'resumed' => false,
                ], JSON_THROW_ON_ERROR),
                'stderr' => $this->providerBeginExit === 0 ? '' : 'provider begin failed',
            ];
        }
        // The deploy checkpoint is a `wp db export`, not a `wp wprism <verb>`, so
        // it is recognised by shape before the wprism-command lookup below.
        if (($wpArgs[0] ?? null) === 'db' && ($wpArgs[1] ?? null) === 'export') {
            $this->events[] = 'capture:db-export';
            return ['exit' => $this->exportExit, 'stdout' => (string) ($wpArgs[2] ?? ''), 'stderr' => ''];
        }
        $command = $this->command($wpArgs);
        $this->events[] = 'capture:' . $command;
        $hash = str_repeat('a', 64);
        $revision = str_repeat('b', 64);
        if ($command === 'compile') {
            if ($this->providerDebtActive) {
                return [
                    'exit' => 75,
                    'stdout' => '',
                    'stderr' => 'incomplete provider settlement blocks repository observation and mutation',
                ];
            }
            $summary = [
                'artifact_hash' => $hash,
            ];
            if ($this->codeEnabled) {
                $summary['code'] = ['code_revision' => $revision, 'format' => 1];
            }
            $summary['effects_inventory'] = [];
            if ($this->schemaDeclared) {
                $summary['effects_inventory'][] = ['phase' => 'schema-settle'];
            }
            if ($this->lifecycleSettlementDeclared) {
                $summary['effects_inventory'][] = ['phase' => 'lifecycle-settle'];
            }
            if ($this->storagePrerequisiteDeclared) {
                $settlement = $this->storagePrerequisiteAutomatic ? 'lifecycle-settle' : 'manual';
                $summary['storage_prerequisites_inventory'] = [[
                    'equals' => '1.0.0',
                    'manifest' => 'storage-fixture',
                    'option' => 'fixture_storage_version',
                    'settlement' => $settlement,
                ]];
                if ($this->storagePrerequisiteAutomatic) {
                    $summary['effects_inventory'][] = [
                        'manifest' => 'storage-fixture',
                        'phase' => 'lifecycle-settle',
                        'effect' => [
                            'kind' => 'database',
                            'mode' => 'restorable',
                            'selector' => [
                                'scope' => 'database_checkpoint',
                                'type' => 'option',
                                'value' => 'fixture_storage_version',
                            ],
                        ],
                    ];
                }
            }
            if ($this->dispositionBlocked) {
                $summary['resolved_adapters'] = [[
                    'name' => 'fixture-adapter',
                    'disposition' => ['source' => 'fixture'],
                    'capability' => [
                        'status' => 'candidate',
                        'reason' => 'fixture evidence is stale',
                        'evidence' => ['status' => 'candidate'],
                    ],
                ]];
            }
            return ['exit' => 0, 'stdout' => json_encode($summary, JSON_THROW_ON_ERROR), 'stderr' => ''];
        }
        if ($command === 'code-preflight') {
            if ($this->preflightExit !== 0) {
                return ['exit' => $this->preflightExit, 'stdout' => 'runtime refusal', 'stderr' => ''];
            }
            return ['exit' => 0, 'stdout' => json_encode([
                'format' => 'wprism-code-runtime/v1', 'enabled' => true,
                'change_required' => $this->codeChangeRequired, 'compatible' => true,
                'code_revision' => $revision,
                'target' => ['php' => '8.3', 'wordpress' => '6.8', 'source' => 'target-control-plane'],
                'requirements' => [], 'diagnostics' => [],
            ], JSON_THROW_ON_ERROR), 'stderr' => ''];
        }
        if ($command === 'lifecycle-status') {
            $drift = $this->codeBaselineState === 'drift' ? $this->codeDriftRows() : [];
            return ['exit' => 0, 'stdout' => json_encode([
                'format' => 'wprism-lifecycle-status/v2',
                'reasons' => $this->lifecycleChangeRequired ? ['inactive_in_environment'] : [],
                'required' => $this->lifecycleChangeRequired,
                'baseline_state' => $this->codeBaselineState,
                'code_drift' => $drift,
                'code_boundary_sha256' => str_repeat('c', 64),
                'findings_sha256' => str_repeat('d', 64),
                'observation_sha256' => str_repeat('e', 64),
                'warnings' => $this->codeWarnings,
            ], JSON_THROW_ON_ERROR), 'stderr' => ''];
        }
        if ($command === 'code-baseline-accept') {
            $operationId = (string) $this->option($wpArgs, '--operation-id=');
            $artifactHash = (string) $this->option($wpArgs, '--artifact-hash=');
            $observation = (string) $this->option($wpArgs, '--expected-observation-sha256=');
            $expectedState = (string) $this->option($wpArgs, '--expected-baseline-state=');
            if ($this->committedCodeBaselineReceipt === null) {
                $drift = $this->codeBaselineState === 'drift' ? $this->codeDriftRows() : [];
                $this->committedCodeBaselineReceipt = [
                    'format' => 'wprism-code-baseline-acceptance/v2',
                    'operation_id' => $operationId,
                    'artifact_hash' => $artifactHash,
                    'observation_sha256' => $observation,
                    'outcome' => $expectedState === 'absent' ? 'initialized' : 'accepted',
                    'replayed' => false,
                    'before_baseline_sha256' => $expectedState === 'absent' ? null : str_repeat('f', 64),
                    'baseline_sha256' => str_repeat('1', 64),
                    'code_drift' => $drift,
                ];
                $this->codeBaselineState = 'exact';
            } else {
                $this->committedCodeBaselineReceipt['replayed'] = true;
            }
            if ($this->codeBaselineExit !== 0) {
                return [
                    'exit' => $this->codeBaselineExit,
                    'stdout' => '',
                    'stderr' => 'fixture baseline acceptance response was lost',
                ];
            }
            if ($this->lostCodeBaselineResponses > 0) {
                $this->lostCodeBaselineResponses--;
                return [
                    'exit' => 28,
                    'stdout' => '',
                    'stderr' => 'fixture baseline acceptance response was lost after commit',
                ];
            }
            if ($this->malformedCodeBaselineReceipt) {
                return ['exit' => 0, 'stdout' => '{"accepted":true}', 'stderr' => ''];
            }
            if ($this->malformedCodeBaselineResponses > 0) {
                $this->malformedCodeBaselineResponses--;
                return ['exit' => 0, 'stdout' => '{"accepted":true}', 'stderr' => ''];
            }
            return [
                'exit' => 0,
                'stdout' => json_encode($this->committedCodeBaselineReceipt, JSON_THROW_ON_ERROR),
                'stderr' => '',
            ];
        }
        if ($command === 'schema-status') {
            if ($this->schemaStatusExit !== 0) {
                return [
                    'exit' => $this->schemaStatusExit,
                    'stdout' => json_encode([
                        'format' => 'wprism-command-refusal/v1',
                        'ok' => false,
                        'command' => 'schema-status',
                        'error' => 'schema_status_failed',
                        'reason_code' => 'schema_status_failed',
                        'message' => 'schema-status refused at an unclassified safety gate',
                        'remediation' => 'correct the named schema-status blocker, then retry the command',
                        'details_redacted' => true,
                    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
                    'stderr' => " Container wprism-fixture-cli2-run-private Creating\n"
                        . " Container wprism-fixture-cli2-run-private Created\n",
                ];
            }
            $presenceOnly = in_array('--presence-only', $wpArgs, true);
            return ['exit' => 0, 'stdout' => json_encode([
                'declared' => $this->schemaDeclared,
                'format' => 'wprism-schema-settlement-status/v1',
                'mode' => $presenceOnly ? 'presence' : 'exact',
                'required' => $this->schemaRequired,
                'state' => $this->schemaRequired
                    ? 'required'
                    : ($presenceOnly ? 'present' : 'ready'),
                'tables' => $this->schemaDeclared ? [[
                    'present' => !$this->schemaRequired,
                    'table' => 'rank_math_internal_links',
                ]] : [],
            ], JSON_THROW_ON_ERROR), 'stderr' => ''];
        }
        if ($command === 'storage-prerequisite-status') {
            $this->storageStatusCalls++;
            $exit = $this->storageStatusCalls === 1
                ? $this->storageStatusExit
                : $this->storageVerifyExit;
            if ($exit !== 0) {
                return ['exit' => $exit, 'stdout' => '', 'stderr' => 'storage prerequisite read failed'];
            }
            $rows = $this->storagePrerequisiteDeclared ? [[
                'manifest' => 'storage-fixture',
                'option' => 'fixture_storage_version',
                'ready' => !$this->storagePrerequisiteRequired,
                'settlement' => $this->storagePrerequisiteAutomatic ? 'lifecycle-settle' : 'manual',
            ]] : [];
            return ['exit' => 0, 'stdout' => json_encode([
                'declared' => $rows !== [],
                'format' => 'wprism-storage-prerequisite-status/v1',
                'prerequisites' => $rows,
                'required' => $this->storagePrerequisiteRequired,
                'state' => $rows === [] ? 'none' : ($this->storagePrerequisiteRequired ? 'required' : 'ready'),
            ], JSON_THROW_ON_ERROR), 'stderr' => ''];
        }
        if ($command === 'checkpoint-target') {
            return ['exit' => 0, 'stdout' => json_encode([
                'database_target_sha256' => str_repeat('d', 64),
                'format' => 'wprism-database-target/v1',
            ], JSON_THROW_ON_ERROR), 'stderr' => ''];
        }
        if ($command === 'promotion-begin') {
            if ($this->leaseActive) {
                return ['exit' => 73, 'stdout' => '', 'stderr' => 'promotion lock held by another owner'];
            }
            $this->leaseActive = true;
            return ['exit' => 0, 'stdout' => 'begun', 'stderr' => ''];
        }
        fail_deploy_command("unexpected capture command $command");
    }
    public function captureWpPipeline(array $producer, array $consumer): array {
        $this->calls[] = $producer;
        $this->calls[] = $consumer;
        $this->events[] = 'capture:db-export';
        return ['exit' => $this->exportExit, 'stdout' => '', 'stderr' => ''];
    }
    public function streamWp(array $wpArgs): int {
        $this->calls[] = $wpArgs;
        $command = $this->command($wpArgs);
        $phase = $this->option($wpArgs, '--lifecycle-phase=');
        $this->events[] = 'stream:' . $command . ($phase === null ? '' : ':' . $phase);
        if ($command === 'code-stage') return $this->stageExit;
        if ($command === 'lifecycle-settle') {
            if ($this->lifecycleSettleExit === 0
                && $this->storagePrerequisiteAutomatic
                && $this->storageSettlementSatisfiesPrerequisite) {
                $this->storagePrerequisiteRequired = false;
            }
            if ($this->lifecycleSettleExit === 0 && in_array('--release-on-success', $wpArgs, true)) {
                $this->leaseActive = false;
            }
            if ($this->probeContenderAfterTerminalProvider) {
                $this->contenderBeginExit = $this->leaseActive ? 73 : 0;
            }
            return $this->lifecycleSettleExit;
        }
        if ($command === 'schema-settle' && in_array('--release-on-success', $wpArgs, true)) {
            $this->leaseActive = false;
        }
        if (($command === 'code-finalize' || ($command === 'deploy' && $phase === 'activate'))
            && !in_array('--promotion-hold', $wpArgs, true)) {
            $this->leaseActive = false;
        }
        return 0;
    }
    public function wpInstruction(array $wpArgs): string { return implode(' ', $wpArgs); }
    public function capabilityReport(string $operation): DriverCapabilityReport {
        return DriverCapabilityReport::forDriver('deploy-fixture', 'deploy-fixture', $operation, []);
    }
    private function command(array $args): string {
        $index = array_search('wprism', $args, true);
        if (!is_int($index) || !isset($args[$index + 1])) fail_deploy_command('driver did not receive a wprism command');
        return $args[$index + 1];
    }
    private function option(array $args, string $prefix): ?string {
        foreach ($args as $arg) {
            if (str_starts_with($arg, $prefix)) return substr($arg, strlen($prefix));
        }
        return null;
    }
    /** @return list<string> */
    private function providerPhases(): array {
        $phases = [];
        if ($this->schemaRequired) $phases[] = 'schema-settle';
        $lifecycleSettlementDeclared = $this->lifecycleSettlementDeclared
            || $this->storagePrerequisiteAutomatic;
        $storageSettlementRequired = $this->storagePrerequisiteAutomatic
            && $this->storagePrerequisiteRequired;
        $storagePrerequisitesOnly = $storageSettlementRequired
            && !$this->codeChangeRequired
            && !$this->lifecycleChangeRequired
            && !$this->schemaRequired;
        if ($lifecycleSettlementDeclared
            && ($this->codeChangeRequired || $this->lifecycleChangeRequired
                || $this->schemaRequired || $storageSettlementRequired)) {
            $phases[] = $storagePrerequisitesOnly
                ? 'storage-prerequisite-settle'
                : 'lifecycle-settle';
        }
        $lifecycleRequired = $this->codeChangeRequired
            || $this->lifecycleChangeRequired
            || ($this->schemaRequired && $lifecycleSettlementDeclared)
            || $storageSettlementRequired;
        if ($phases !== [] && $lifecycleRequired) {
            $phases = array_merge(['lifecycle-retire', 'lifecycle-activate'], $phases);
        }
        return $phases;
    }

    /** @return list<array<string,string>> */
    private function codeDriftRows(): array {
        return [[
            'issue' => 'code_drift',
            'kind' => 'plugin',
            'plugin' => 'fixture/fixture.php',
            'installed_version' => '2.0.0',
            'recorded_version' => '1.0.0',
            'message' => 'fixture/fixture.php changed from 1.0.0 to 2.0.0 outside WPrism',
        ]];
    }

    public function abortLease(): bool {
        if ($this->abortRemovesLease) {
            $this->leaseActive = false;
        }
        return $this->abortSucceeds;
    }
}

/** @return array{exit:int,callbacks:list<string>} */
function run_deploy_command(DeployCommandDriver $driver, array $extra, ?int $scopeExit = null): array {
    $callbacks = [];
    $exit = DeployCommand::run(
        $driver,
        $extra,
        static function (array $args) use (&$callbacks, $scopeExit): ?int {
            $callbacks[] = 'scope';
            return $scopeExit;
        },
        static function (EnvironmentDriver $transport) use (&$callbacks): bool {
            $callbacks[] = 'fence:' . $transport->name();
            return true;
        },
        static function () use (&$callbacks): string {
            $callbacks[] = 'run-id';
            return 'deploy-command-test';
        },
        static function (EnvironmentDriver $transport, array $begin, string $owner, string $hash) use (&$callbacks): void {
            $callbacks[] = "compensate:$owner:$hash:" . (int) ($begin['exit'] ?? -1);
        },
        static function (EnvironmentDriver $transport, string $owner, string $hash) use (&$callbacks, $driver): bool {
            $callbacks[] = "abort:$owner:$hash";
            return $driver->abortLease();
        },
        // issue #3525: the recovery callback takes no owner/hash any more — the
        // guidance names `wprism recover <env> --restore=<id>`, whose `<id>` is
        // the checkpoint basename, so nothing PRINTED needs the lease pair.
        static function (
            EnvironmentDriver $transport,
            string $checkpoint,
            bool $codeMayHaveChanged
        ) use (&$callbacks): void {
            $callbacks[] = "recovery:$checkpoint:" . ($codeMayHaveChanged ? 'code' : 'nocode');
        }
    );
    return ['exit' => $exit, 'callbacks' => $callbacks];
}

function option_deploy_command(array $args, string $prefix): ?string {
    foreach ($args as $arg) {
        if (str_starts_with($arg, $prefix)) return substr($arg, strlen($prefix));
    }
    return null;
}

function schema_loss_deploy_driver(): DeployCommandDriver {
    $driver = new DeployCommandDriver();
    $driver->codeEnabled = false;
    $driver->codeChangeRequired = false;
    $driver->lifecycleChangeRequired = true;
    $driver->schemaDeclared = true;
    $driver->schemaRequired = true;
    $driver->lifecycleSettlementDeclared = true;
    $driver->schemaStatusExit = 29;
    return $driver;
}

function baseline_drift_deploy_driver(): DeployCommandDriver {
    $driver = new DeployCommandDriver();
    $driver->codeEnabled = false;
    $driver->codeChangeRequired = false;
    $driver->codeBaselineState = 'drift';
    return $driver;
}

if (($argv[1] ?? '') === '--schema-loss-output-child') {
    $childResult = run_deploy_command(schema_loss_deploy_driver(), []);
    exit($childResult['exit']);
}
if (($argv[1] ?? '') === '--baseline-output-child') {
    $childResult = run_deploy_command(baseline_drift_deploy_driver(), ['--force-code-drift']);
    exit($childResult['exit']);
}

$authorizedBeginRefused = false;
try {
    CodeDeploy::beginArgs('/fixture/repo', 'authorized-owner', str_repeat('a', 64), [
        'operation_id' => 'release-operation',
        'repo_path' => '/different/repo',
        'source_commit' => str_repeat('b', 40),
        'source_tree' => str_repeat('c', 40),
    ]);
} catch (\InvalidArgumentException $failure) {
    $authorizedBeginRefused = str_contains($failure->getMessage(), 'does not match');
}
assert_deploy_command(
    $authorizedBeginRefused,
    'authorized promotion transport refuses a repository different from its consumed release binding'
);

// Direct execution proves the extracted handler still owns the full public
// deploy phase graph without loading cli/wprism or starting a shell process.
$happy = new DeployCommandDriver();
$happyResult = run_deploy_command($happy, ['--force-code-mismatch', '--force-code-drift']);
assert_deploy_command($happyResult['exit'] === 0, 'code-enabled deploy command succeeds');
assert_deploy_command(
    $happy->events === [
        'raw:mkdir', 'capture:compile', 'capture:code-preflight', 'capture:promotion-begin',
        'capture:checkpoint-target', 'capture:db-export',
        'stream:code-stage', 'stream:deploy:retire', 'stream:deploy:activate',
        'stream:lifecycle-settle', 'stream:code-finalize',
    ],
    'direct handler preserves compile/preflight/begin/checkpoint/stage/lifecycle/finalize order'
);
assert_deploy_command(
    in_array('checkpoint-target', $happy->calls[3], true)
        && array_slice($happy->calls[4], -3) === ['db', 'export', '-']
        && str_contains(implode(' ', $happy->calls[4]), 'DatabaseTargetIdentity::fromWordPressConfig')
        && in_array(
            '--output=/fixture/repo/.wprism/checkpoints/deploy-deploy-command-test.sql.enc',
            $happy->calls[5],
            true
        )
        && in_array('--database-target-sha256=' . str_repeat('d', 64), $happy->calls[5], true),
    'the checkpoint preflights one database target and streams it into bound ciphertext without a plaintext file'
);
assert_deploy_command(
    $happyResult['callbacks'] === ['scope', 'fence:deploy-fixture', 'run-id'],
    'successful handler performs only scope, fence, and run-id collaboration'
);
$owner = option_deploy_command($happy->calls[2], '--promotion-owner=');
$hash = option_deploy_command($happy->calls[2], '--artifact-hash=');
assert_deploy_command($owner === 'deploy-command-test' && $hash === str_repeat('a', 64), 'begin receives the generated owner and compiled hash');
// From the code-stage call on: neither side of the encrypted export pipeline
// carries lease flags because the lease belongs to the surrounding phase.
foreach (array_slice($happy->calls, 6) as $call) {
    assert_deploy_command(
        option_deploy_command($call, '--promotion-owner=') === $owner
            && option_deploy_command($call, '--artifact-hash=') === $hash,
        'every mutating deploy phase retains the one owner and artifact hash'
    );
}
assert_deploy_command(
    in_array('--force-code-mismatch', $happy->calls[7], true)
        && in_array('--force-code-drift', $happy->calls[7], true)
        && in_array('--force-code-mismatch', $happy->calls[8], true)
        && in_array('--force-code-drift', $happy->calls[8], true),
    'only lifecycle phases receive the public force flags'
);

// --no-checkpoint is the byte-identical pre-change world: today's event list,
// today's exit path, and no recovery guidance for a dump nobody took.
$optOut = new DeployCommandDriver();
$optOutResult = run_deploy_command($optOut, ['--no-checkpoint']);
assert_deploy_command($optOutResult['exit'] === 0, '--no-checkpoint deploy command succeeds');
assert_deploy_command(
    $optOut->events === [
        'raw:mkdir', 'capture:compile', 'capture:code-preflight', 'capture:promotion-begin',
        'stream:code-stage', 'stream:deploy:retire', 'stream:deploy:activate',
        'stream:lifecycle-settle', 'stream:code-finalize',
    ],
    '--no-checkpoint reproduces the pre-change event list verbatim'
);
assert_deploy_command(
    $optOutResult['callbacks'] === ['scope', 'fence:deploy-fixture', 'run-id'],
    '--no-checkpoint performs the same collaboration the pre-change handler did'
);

// A failed export aborts the lease it took and starts nothing after it.
$export = new DeployCommandDriver();
$export->exportExit = 23;
$exportResult = run_deploy_command($export, []);
assert_deploy_command($exportResult['exit'] === 23, 'checkpoint export exit propagates unchanged');
assert_deploy_command(
    $export->events === [
        'raw:mkdir', 'capture:compile', 'capture:code-preflight', 'capture:promotion-begin',
        'capture:checkpoint-target', 'capture:db-export',
    ],
    'a failed checkpoint runs no stream: phase at all'
);
assert_deploy_command(
    $exportResult['callbacks'] === [
        'scope', 'fence:deploy-fixture', 'run-id',
        'abort:deploy-command-test:' . str_repeat('a', 64),
    ],
    'a failed checkpoint aborts the exact lease and prints no guidance for a dump that does not exist'
);

// A scope refusal precedes the rollback fence and every target operation.
$scoped = new DeployCommandDriver();
$scopedResult = run_deploy_command($scoped, ['--scope-contract=/tmp/contract'], 2);
assert_deploy_command($scopedResult['exit'] === 2 && $scopedResult['callbacks'] === ['scope'], 'scope refusal returns before every other deploy collaborator');
assert_deploy_command($scoped->events === [], 'scope refusal makes no target contact');

// Caller-owned artifact and lease flags fail before the fence or mkdir, or the
// command could sever its immutable-artifact binding before target contact.
$forged = new DeployCommandDriver();
$forgedResult = run_deploy_command($forged, ['--repo=/forged']);
assert_deploy_command($forgedResult['exit'] === 1 && $forgedResult['callbacks'] === ['scope'], 'forged host binding refuses before fence');
assert_deploy_command($forged->events === [], 'forged host binding makes no target contact');

// Capability evidence is checked before preflight, lease acquisition, or a
// mutation phase; candidate claims never enter the deploy state machine.
$blocked = new DeployCommandDriver();
$blocked->dispositionBlocked = true;
$blockedResult = run_deploy_command($blocked, []);
assert_deploy_command($blockedResult['exit'] === 1, 'uncertified adapter disposition blocks deploy');
assert_deploy_command($blocked->events === ['raw:mkdir', 'capture:compile'], 'uncertified disposition refuses before preflight and promotion-begin');

// Runtime preflight happens before the lease, so its failure has no invented
// cleanup path and stops before begin/stage/lifecycle.
$preflight = new DeployCommandDriver();
$preflight->preflightExit = 17;
$preflightResult = run_deploy_command($preflight, []);
assert_deploy_command($preflightResult['exit'] === 17, 'runtime preflight exit propagates unchanged');
assert_deploy_command($preflight->events === ['raw:mkdir', 'capture:compile', 'capture:code-preflight'], 'runtime preflight failure stops before promotion-begin');
assert_deploy_command($preflightResult['callbacks'] === ['scope', 'fence:deploy-fixture', 'run-id'], 'preflight failure performs no lease cleanup fiction');

// Target preflight proves the compiled descriptor and payload already match.
// A code-only deploy then has no release work: it must not take a lease,
// checkpoint, stage, or invoke extension lifecycle hooks.
$unchanged = new DeployCommandDriver();
$unchanged->codeChangeRequired = false;
$unchangedResult = run_deploy_command($unchanged, []);
assert_deploy_command($unchangedResult['exit'] === 0, 'unchanged-code deploy succeeds as a verified no-op');
assert_deploy_command(
    $unchanged->events === [
        'raw:mkdir', 'capture:compile', 'capture:code-preflight', 'capture:lifecycle-status',
    ],
    'unchanged target code stops before promotion-begin/checkpoint and every lifecycle hook'
);
assert_deploy_command(
    count(array_filter($unchanged->events, static fn(string $event): bool => str_contains($event, 'deploy:'))) === 0,
    'unchanged-code deploy invokes neither retirement nor activation'
);

// Code-version acceptance is its own work axis. Without explicit consent the
// host refuses from read-only v2 evidence before a lease, checkpoint, provider,
// lifecycle hook, or baseline write.
$driftRefused = baseline_drift_deploy_driver();
$driftRefusedResult = run_deploy_command($driftRefused, []);
assert_deploy_command($driftRefusedResult['exit'] === 1, 'descriptor-free code drift refuses without consent');
assert_deploy_command(
    $driftRefused->events === ['raw:mkdir', 'capture:compile', 'capture:lifecycle-status'],
    'unforced drift stops at the read-only deployment preflight'
);
assert_deploy_command($driftRefused->codeBaselineState === 'drift', 'unforced drift leaves the fixture baseline unchanged');

// With consent, exactly one isolated baseline command runs. It does not invent
// lifecycle/provider effects or a recovery checkpoint for one idempotent KV
// replacement.
$driftAccepted = baseline_drift_deploy_driver();
$driftAcceptedResult = run_deploy_command($driftAccepted, ['--force-code-drift']);
assert_deploy_command($driftAcceptedResult['exit'] === 0, 'descriptor-free forced drift acceptance succeeds');
assert_deploy_command(
    $driftAccepted->events === [
        'raw:mkdir', 'capture:compile', 'capture:lifecycle-status', 'capture:code-baseline-accept',
    ],
    'forced drift selects only the isolated baseline phase'
);
assert_deploy_command($driftAccepted->codeBaselineState === 'exact', 'the baseline phase consumes its exact drift finding');
$baselineCall = $driftAccepted->calls[2];
assert_deploy_command(
    in_array('code-baseline-accept', $baselineCall, true)
        && in_array('--force-code-drift', $baselineCall, true)
        && in_array('--skip-plugins', $baselineCall, true)
        && in_array('--skip-themes', $baselineCall, true)
        && count(array_filter(
            $baselineCall,
            static fn(string $arg): bool => str_starts_with($arg, '--exec=')
                && str_contains($arg, 'WPRISM_CONTROL_PLANE')
        )) === 1,
    'baseline acceptance carries consent through the isolated control-plane bootstrap'
);
assert_deploy_command(
    count(array_filter(
        $driftAccepted->events,
        static fn(string $event): bool => str_contains($event, 'promotion-begin')
            || str_contains($event, 'checkpoint')
            || str_contains($event, 'deploy:')
            || str_contains($event, 'settle')
    )) === 0,
    'baseline-only acceptance takes no host lease, checkpoint, lifecycle, or provider phase'
);

$baselineOutputProcess = proc_open(
    [PHP_BINARY, __FILE__, '--baseline-output-child'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $baselineOutputPipes
);
assert_deploy_command(is_resource($baselineOutputProcess), 'baseline output child starts');
$baselineStdout = (string) stream_get_contents($baselineOutputPipes[1]);
$baselineStderr = (string) stream_get_contents($baselineOutputPipes[2]);
fclose($baselineOutputPipes[1]);
fclose($baselineOutputPipes[2]);
$baselineOutputStatus = proc_close($baselineOutputProcess);
assert_deploy_command($baselineOutputStatus === 0, 'baseline output child succeeds');
assert_deploy_command(
    $baselineStdout === "deploy phase: compile\ndeploy phase: lifecycle-status\ndeploy phase: code-baseline-accept\n"
        . "deploy complete: code-baseline-accept; no code descriptor\n",
    'baseline-only output names the selected phase and honest terminal result'
);
assert_deploy_command(
    substr_count($baselineStderr, 'FORCED past code_drift') === 1
        && str_contains($baselineStderr, 'fixture/fixture.php changed from 1.0.0 to 2.0.0'),
    'one forced baseline finding produces exactly one operator warning'
);

// A missing or malformed receipt cannot be called success. The operation is
// retry-safe and the handler never falls through to a completion line.
$baselineLost = baseline_drift_deploy_driver();
$baselineLost->codeBaselineExit = 28;
$baselineLostResult = run_deploy_command($baselineLost, ['--force-code-drift']);
assert_deploy_command($baselineLostResult['exit'] === 28, 'lost baseline response preserves the target exit');
assert_deploy_command(
    array_slice($baselineLost->events, -1) === ['capture:code-baseline-accept'],
    'lost baseline response stops at the acceptance boundary'
);
$baselineMalformed = baseline_drift_deploy_driver();
$baselineMalformed->malformedCodeBaselineReceipt = true;
$baselineMalformedResult = run_deploy_command($baselineMalformed, ['--force-code-drift']);
assert_deploy_command($baselineMalformedResult['exit'] === 1, 'malformed baseline receipt refuses completion');

// Lifecycle work still owns its terminal baseline publication. Combining
// drift with an activation mismatch therefore runs the real fresh-process
// lifecycle sequence and never the baseline-only phase.
$driftWithLifecycle = baseline_drift_deploy_driver();
$driftWithLifecycle->lifecycleChangeRequired = true;
$driftWithLifecycleResult = run_deploy_command($driftWithLifecycle, ['--force-code-drift']);
assert_deploy_command($driftWithLifecycleResult['exit'] === 0, 'forced drift composes with lifecycle work');
assert_deploy_command(
    $driftWithLifecycle->events === [
        'raw:mkdir', 'capture:compile', 'capture:lifecycle-status', 'capture:promotion-begin',
        'capture:checkpoint-target', 'capture:db-export',
        'stream:deploy:retire', 'stream:deploy:activate',
    ],
    'combined drift/lifecycle work keeps the fresh lifecycle path and skips baseline-only acceptance'
);
assert_deploy_command(
    in_array('--force-code-drift', $driftWithLifecycle->calls[6], true)
        && in_array('--force-code-drift', $driftWithLifecycle->calls[7], true),
    'combined lifecycle phases both retain the explicit drift consent for their locked rechecks'
);

// Both v2 documents are closed contracts. Old format, inconsistent booleans,
// duplicate rows, and invented warning text all fail rather than degrading.
$validDriftRow = [
    'issue' => 'code_drift',
    'kind' => 'plugin',
    'plugin' => 'fixture/fixture.php',
    'installed_version' => '2.0.0',
    'recorded_version' => '1.0.0',
    'message' => 'fixture drift',
];
$validThemeDriftRow = [
    'issue' => 'code_baseline_missing',
    'kind' => 'theme',
    'theme' => 'fixture-theme',
    'installed_version' => '3.1.0',
    'recorded_version' => '',
    'message' => 'fixture theme has no baseline',
];
$validThemeStatus = CodeDeploy::lifecycleStatusResult([
    'exit' => 0,
    'stdout' => json_encode([
        'baseline_state' => 'drift',
        'code_boundary_sha256' => str_repeat('1', 64),
        'code_drift' => [$validThemeDriftRow],
        'findings_sha256' => str_repeat('2', 64),
        'format' => 'wprism-lifecycle-status/v2',
        'observation_sha256' => str_repeat('3', 64),
        'reasons' => [],
        'required' => false,
        'warnings' => [],
    ], JSON_THROW_ON_ERROR),
    'stderr' => '',
]);
assert_deploy_command(
    $validThemeStatus['code_drift'] === [$validThemeDriftRow],
    'strict lifecycle status accepts one closed missing-theme baseline row'
);
foreach ([
    [
        'baseline_state' => 'exact', 'code_boundary_sha256' => str_repeat('1', 64),
        'code_drift' => [], 'findings_sha256' => str_repeat('2', 64),
        'format' => 'wprism-lifecycle-status/v1', 'observation_sha256' => str_repeat('3', 64),
        'reasons' => [], 'required' => false, 'warnings' => [],
    ],
    [
        'baseline_state' => 'exact', 'code_boundary_sha256' => str_repeat('1', 64),
        'code_drift' => [$validDriftRow], 'findings_sha256' => str_repeat('2', 64),
        'format' => 'wprism-lifecycle-status/v2', 'observation_sha256' => str_repeat('3', 64),
        'reasons' => [], 'required' => false, 'warnings' => [],
    ],
    [
        'baseline_state' => 'drift', 'code_boundary_sha256' => str_repeat('1', 64),
        'code_drift' => [$validDriftRow, $validDriftRow], 'findings_sha256' => str_repeat('2', 64),
        'format' => 'wprism-lifecycle-status/v2', 'observation_sha256' => str_repeat('3', 64),
        'reasons' => [], 'required' => false, 'warnings' => [],
    ],
    [
        'baseline_state' => 'drift', 'code_boundary_sha256' => str_repeat('1', 64),
        'code_drift' => [[...$validThemeDriftRow, 'installed_version' => "3.1.0\nforged"]],
        'findings_sha256' => str_repeat('2', 64), 'format' => 'wprism-lifecycle-status/v2',
        'observation_sha256' => str_repeat('3', 64), 'reasons' => [], 'required' => false,
        'warnings' => [],
    ],
    [
        'baseline_state' => 'drift', 'code_boundary_sha256' => str_repeat('1', 64),
        'code_drift' => [[...$validThemeDriftRow, 'message' => "fixture\033[31mforged"]],
        'findings_sha256' => str_repeat('2', 64), 'format' => 'wprism-lifecycle-status/v2',
        'observation_sha256' => str_repeat('3', 64), 'reasons' => [], 'required' => false,
        'warnings' => [],
    ],
    [
        'baseline_state' => 'exact', 'code_boundary_sha256' => str_repeat('1', 64),
        'code_drift' => [], 'findings_sha256' => str_repeat('2', 64),
        'format' => 'wprism-lifecycle-status/v2', 'observation_sha256' => str_repeat('3', 64),
        'reasons' => [], 'required' => false,
        'warnings' => ["FORCED past code_mismatch: forged\nline"],
    ],
] as $malformedStatus) {
    $refused = false;
    try {
        CodeDeploy::lifecycleStatusResult([
            'exit' => 0,
            'stdout' => json_encode($malformedStatus, JSON_THROW_ON_ERROR),
            'stderr' => '',
        ]);
    } catch (RuntimeException $failure) {
        $refused = str_contains($failure->getMessage(), 'malformed lifecycle preflight evidence');
    }
    assert_deploy_command($refused, 'malformed lifecycle/baseline status evidence is refused closed');
}
$badAcceptance = [
    'artifact_hash' => str_repeat('a', 64),
    'baseline_sha256' => str_repeat('b', 64),
    'before_baseline_sha256' => str_repeat('c', 64),
    'code_drift' => [[...$validDriftRow, 'message' => "forged\nreceipt"]],
    'format' => 'wprism-code-baseline-acceptance/v2',
    'observation_sha256' => str_repeat('d', 64),
    'operation_id' => 'deploy-command-test',
    'outcome' => 'accepted',
    'replayed' => false,
];
$badAcceptanceRefused = false;
try {
    CodeDeploy::codeBaselineAcceptResult([
        'exit' => 0,
        'stdout' => json_encode($badAcceptance, JSON_THROW_ON_ERROR),
        'stderr' => '',
    ]);
} catch (RuntimeException $failure) {
    $badAcceptanceRefused = str_contains($failure->getMessage(), 'malformed code-baseline acceptance evidence');
}
assert_deploy_command($badAcceptanceRefused, 'baseline receipt rows reject control-character output injection');

// Storage settlement needs two independent proofs: the immutable artifact
// binds the same-manifest effect coverage, and the target returns only exact
// coordinates plus a value-redacted readiness verdict.
$storageEffect = [
    'manifest' => 'storage-fixture',
    'phase' => 'lifecycle-settle',
    'effect' => [
        'kind' => 'database', 'mode' => 'restorable',
        'selector' => [
            'scope' => 'database_checkpoint', 'type' => 'option',
            'value' => 'fixture_storage_version',
        ],
    ],
];
$storageInventory = [[
    'equals' => '1.0.0', 'manifest' => 'storage-fixture',
    'option' => 'fixture_storage_version', 'settlement' => 'lifecycle-settle',
]];
$storageCompile = [
    'effects_inventory' => [$storageEffect],
    'storage_prerequisites_inventory' => $storageInventory,
];
assert_deploy_command(
    CodeDeploy::storagePrerequisitesInventory($storageCompile) === $storageInventory,
    'host accepts an exact prerequisite inventory derived from same-manifest restorable effects'
);
foreach (['settlement', 'effect', 'duplicate', 'equals'] as $fault) {
    $malformed = $storageCompile;
    if ($fault === 'settlement') $malformed['storage_prerequisites_inventory'][0]['settlement'] = 'manual';
    if ($fault === 'effect') $malformed['effects_inventory'][0]['effect']['selector']['value'] = 'other';
    if ($fault === 'duplicate') $malformed['storage_prerequisites_inventory'][] = $storageInventory[0];
    if ($fault === 'equals') $malformed['storage_prerequisites_inventory'][0]['equals'] = "forged\nvalue";
    $refused = false;
    try { CodeDeploy::storagePrerequisitesInventory($malformed); } catch (RuntimeException) { $refused = true; }
    assert_deploy_command($refused, "$fault cannot forge compiled storage settlement authority");
}
$storageStatusDocument = [
    'declared' => true,
    'format' => 'wprism-storage-prerequisite-status/v1',
    'prerequisites' => [[
        'manifest' => 'storage-fixture', 'option' => 'fixture_storage_version',
        'ready' => false, 'settlement' => 'lifecycle-settle',
    ]],
    'required' => true,
    'state' => 'required',
];
assert_deploy_command(
    CodeDeploy::storagePrerequisiteStatusResult([
        'exit' => 0, 'stdout' => json_encode($storageStatusDocument, JSON_THROW_ON_ERROR), 'stderr' => '',
    ], $storageInventory) === $storageStatusDocument,
    'host accepts one closed, value-redacted prerequisite status document'
);
foreach (['format', 'coordinate', 'value', 'required', 'state'] as $fault) {
    $malformed = $storageStatusDocument;
    if ($fault === 'format') $malformed['format'] = 'wprism-storage-prerequisite-status/v0';
    if ($fault === 'coordinate') $malformed['prerequisites'][0]['option'] = 'other';
    if ($fault === 'value') $malformed['prerequisites'][0]['value'] = '1.0.0';
    if ($fault === 'required') $malformed['required'] = false;
    if ($fault === 'state') $malformed['state'] = 'ready';
    $refused = false;
    try {
        CodeDeploy::storagePrerequisiteStatusResult([
            'exit' => 0, 'stdout' => json_encode($malformed, JSON_THROW_ON_ERROR), 'stderr' => '',
        ], $storageInventory);
    } catch (RuntimeException) { $refused = true; }
    assert_deploy_command($refused, "$fault cannot forge storage prerequisite readiness");
}

$manualStorage = new DeployCommandDriver();
$manualStorage->codeEnabled = false;
$manualStorage->codeChangeRequired = false;
$manualStorage->storagePrerequisiteDeclared = true;
$manualStorage->storagePrerequisiteRequired = true;
$manualStorageResult = run_deploy_command($manualStorage, []);
assert_deploy_command($manualStorageResult['exit'] === 1,
    'unmet manual storage debt refuses host deployment');
assert_deploy_command($manualStorage->events === [
    'raw:mkdir', 'capture:compile', 'capture:lifecycle-status',
    'capture:storage-prerequisite-status',
], 'manual storage debt refuses before a lease, checkpoint, lifecycle hook, or provider invocation');

$readyStorage = new DeployCommandDriver();
$readyStorage->codeEnabled = false;
$readyStorage->codeChangeRequired = false;
$readyStorage->storagePrerequisiteDeclared = true;
$readyStorageResult = run_deploy_command($readyStorage, []);
assert_deploy_command($readyStorageResult['exit'] === 0, 'ready storage preserves an unchanged-code no-op');
assert_deploy_command($readyStorage->events === [
    'raw:mkdir', 'capture:compile', 'capture:lifecycle-status',
    'capture:storage-prerequisite-status',
], 'ready storage adds only its exact read-only preflight to an unchanged deployment');

$automaticStorage = new DeployCommandDriver();
$automaticStorage->codeEnabled = false;
$automaticStorage->codeChangeRequired = false;
$automaticStorage->storagePrerequisiteDeclared = true;
$automaticStorage->storagePrerequisiteAutomatic = true;
$automaticStorage->storagePrerequisiteRequired = true;
$automaticStorageResult = run_deploy_command($automaticStorage, []);
assert_deploy_command($automaticStorageResult['exit'] === 0,
    'effect-covered storage debt settles on unchanged code');
assert_deploy_command($automaticStorage->events === [
    'raw:mkdir', 'capture:compile', 'capture:lifecycle-status',
    'capture:storage-prerequisite-status', 'capture:promotion-begin',
    'capture:checkpoint-target', 'capture:db-export', 'capture:provider-settlement-begin',
    'stream:deploy:retire', 'raw:provider-settlement-advance:lifecycle-retire',
    'stream:deploy:activate', 'raw:provider-settlement-advance:lifecycle-activate',
    'stream:lifecycle-settle', 'capture:storage-prerequisite-status',
    'raw:provider-settlement-advance:storage-prerequisite-settle', 'raw:provider-settlement-complete',
], 'unchanged-code storage settlement uses one checkpoint, ordered lifecycle children, exact postcheck, and durable completion');
$automaticStorageSettleCalls = array_values(array_filter(
    $automaticStorage->calls,
    static fn(array $call): bool => in_array('lifecycle-settle', $call, true)
));
assert_deploy_command(
    count($automaticStorageSettleCalls) === 1
        && in_array('--storage-prerequisites-only', $automaticStorageSettleCalls[0], true),
    'storage-only debt selects the effect-covering action subset on the target'
);
assert_deploy_command(!$automaticStorage->providerDebtActive && !$automaticStorage->leaseActive,
    'successful storage settlement clears provider debt before releasing its lease');
assert_deploy_command(count(array_filter($automaticStorage->events,
    static fn(string $event): bool => str_contains($event, 'code-stage')
        || str_contains($event, 'code-finalize'))) === 0,
    'storage-only settlement never enters code materialization');

$schemaAndStorage = new DeployCommandDriver();
$schemaAndStorage->codeEnabled = false;
$schemaAndStorage->codeChangeRequired = false;
$schemaAndStorage->schemaDeclared = true;
$schemaAndStorage->schemaRequired = true;
$schemaAndStorage->storagePrerequisiteDeclared = true;
$schemaAndStorage->storagePrerequisiteAutomatic = true;
$schemaAndStorage->storagePrerequisiteRequired = true;
$schemaAndStorageResult = run_deploy_command($schemaAndStorage, []);
assert_deploy_command($schemaAndStorageResult['exit'] === 0,
    'schema and storage debt settle in one host transaction');
assert_deploy_command(array_slice($schemaAndStorage->events, 0, 6) === [
    'raw:mkdir', 'capture:compile', 'capture:lifecycle-status', 'capture:schema-status',
    'capture:storage-prerequisite-status', 'capture:promotion-begin',
] && array_slice($schemaAndStorage->events, -6) === [
    'stream:schema-settle', 'raw:provider-settlement-advance:schema-settle',
    'stream:lifecycle-settle', 'capture:storage-prerequisite-status',
    'raw:provider-settlement-advance:lifecycle-settle', 'raw:provider-settlement-complete',
], 'schema settlement precedes lifecycle migration and one final cursor proof clears the combined debt');
$schemaAndStorageSettleCalls = array_values(array_filter(
    $schemaAndStorage->calls,
    static fn(array $call): bool => in_array('lifecycle-settle', $call, true)
));
assert_deploy_command(
    count($schemaAndStorageSettleCalls) === 1
        && !in_array('--storage-prerequisites-only', $schemaAndStorageSettleCalls[0], true),
    'schema transition retains the complete lifecycle settlement phase'
);

$codeAndStorage = new DeployCommandDriver();
$codeAndStorage->storagePrerequisiteDeclared = true;
$codeAndStorageResult = run_deploy_command($codeAndStorage, []);
assert_deploy_command($codeAndStorageResult['exit'] === 0,
    'a ready prerequisite composes with code materialization');
$codeAndStorageTail = array_slice($codeAndStorage->events, -4);
assert_deploy_command($codeAndStorageTail === [
    'stream:deploy:activate', 'stream:lifecycle-settle',
    'capture:storage-prerequisite-status', 'stream:code-finalize',
], 'code finalization waits for a fresh physical prerequisite postcheck');

$storageNoCheckpoint = new DeployCommandDriver();
$storageNoCheckpoint->codeEnabled = false;
$storageNoCheckpoint->codeChangeRequired = false;
$storageNoCheckpoint->storagePrerequisiteDeclared = true;
$storageNoCheckpoint->storagePrerequisiteAutomatic = true;
$storageNoCheckpoint->storagePrerequisiteRequired = true;
$storageNoCheckpointResult = run_deploy_command($storageNoCheckpoint, ['--no-checkpoint']);
assert_deploy_command($storageNoCheckpointResult['exit'] === 1,
    'automatic storage settlement cannot bypass its recovery checkpoint');
assert_deploy_command(array_slice($storageNoCheckpoint->events, -1) === ['capture:storage-prerequisite-status']
    && !$storageNoCheckpoint->leaseActive,
    '--no-checkpoint refuses before the storage settlement lease');

$unsettledStorage = new DeployCommandDriver();
$unsettledStorage->codeEnabled = false;
$unsettledStorage->codeChangeRequired = false;
$unsettledStorage->storagePrerequisiteDeclared = true;
$unsettledStorage->storagePrerequisiteAutomatic = true;
$unsettledStorage->storagePrerequisiteRequired = true;
$unsettledStorage->storageSettlementSatisfiesPrerequisite = false;
$unsettledStorageResult = run_deploy_command($unsettledStorage, []);
assert_deploy_command($unsettledStorageResult['exit'] === 1,
    'provider success cannot replace the exact storage postcondition');
assert_deploy_command(array_slice($unsettledStorage->events, -2) === [
    'stream:lifecycle-settle', 'capture:storage-prerequisite-status',
], 'an unmet postcondition is detected before provider progress or completion is recorded');
assert_deploy_command($unsettledStorage->providerDebtActive,
    'an unmet postcondition retains database-external recovery debt');
assert_deploy_command(array_slice($unsettledStorageResult['callbacks'], -2) === [
    'abort:deploy-command-test:' . str_repeat('a', 64),
    'recovery:/fixture/repo/.wprism/checkpoints/deploy-deploy-command-test.sql.enc:nocode',
], 'an unmet storage postcondition points at the exact pre-provider checkpoint');

$unreadableStoragePostimage = new DeployCommandDriver();
$unreadableStoragePostimage->codeEnabled = false;
$unreadableStoragePostimage->codeChangeRequired = false;
$unreadableStoragePostimage->storagePrerequisiteDeclared = true;
$unreadableStoragePostimage->storagePrerequisiteAutomatic = true;
$unreadableStoragePostimage->storagePrerequisiteRequired = true;
$unreadableStoragePostimage->storageVerifyExit = 26;
$unreadableStoragePostimageResult = run_deploy_command($unreadableStoragePostimage, []);
assert_deploy_command($unreadableStoragePostimageResult['exit'] === 26,
    'a failed physical postimage read preserves the target exit');
assert_deploy_command($unreadableStoragePostimage->providerDebtActive,
    'an unreadable storage postimage cannot clear provider recovery debt');

// A repository can intentionally omit a code descriptor while still owning
// the target plugin's activation, schema installation and derived-state
// settlement. This is Rank Math's public authoring path: the target already
// has vendor bytes, but starts inactive and without its native tables.
$stateOnly = new DeployCommandDriver();
$stateOnly->codeEnabled = false;
$stateOnly->codeChangeRequired = false;
$stateOnly->lifecycleChangeRequired = true;
$stateOnly->schemaDeclared = true;
$stateOnly->schemaRequired = true;
$stateOnly->lifecycleSettlementDeclared = true;
$stateOnly->contenderPassedInitialFence = true;
$stateOnly->probeContenderAfterTerminalProvider = true;
$stateOnlyResult = run_deploy_command($stateOnly, []);
assert_deploy_command($stateOnlyResult['exit'] === 0, 'state-only inactive-plugin deploy succeeds');
assert_deploy_command(
    $stateOnly->events === [
        'raw:mkdir', 'capture:compile', 'capture:lifecycle-status', 'capture:schema-status',
        'capture:promotion-begin', 'capture:checkpoint-target', 'capture:db-export',
        'capture:provider-settlement-begin',
        'stream:deploy:retire', 'raw:provider-settlement-advance:lifecycle-retire',
        'stream:deploy:activate', 'raw:provider-settlement-advance:lifecycle-activate',
        'stream:schema-settle', 'raw:provider-settlement-advance:schema-settle',
        'stream:lifecycle-settle', 'raw:provider-settlement-advance:lifecycle-settle',
        'raw:provider-settlement-complete',
    ],
    'state-only deploy durably orders lifecycle activation before native schema and derived-state settlement'
);
$stateRetire = $stateOnly->calls[8];
$stateActivate = $stateOnly->calls[9];
$stateSchema = $stateOnly->calls[10];
$stateSettle = $stateOnly->calls[11];
$stateCheckpoint = '/fixture/repo/.wprism/checkpoints/deploy-deploy-command-test.sql.enc';
assert_deploy_command(
    str_contains(implode(' ', $stateOnly->calls[7]), 'PromotionLock::with_existing_lease_fence')
        && str_contains(implode(' ', $stateOnly->calls[7]), 'ProviderSettlementIntent::begin'),
    'provider debt is published while continuing the exact target lease fence'
);
assert_deploy_command(
    in_array('--checkpoint=' . $stateCheckpoint, $stateRetire, true)
        && in_array('--checkpoint=' . $stateCheckpoint, $stateActivate, true),
    'both lifecycle hooks authenticate through the exact provider-settlement checkpoint'
);
assert_deploy_command(
    !in_array('--materializing-code', $stateRetire, true)
        && !in_array('--materializing-code', $stateActivate, true),
    'state-only lifecycle phases never claim host code materialization'
);
assert_deploy_command(
    in_array('--promotion-hold', $stateActivate, true)
        && in_array('--after-code-transition', $stateSchema, true)
        && !in_array('--release-on-success', $stateSchema, true)
        && !in_array('--release-on-success', $stateSettle, true),
    'externally tracked provider children cannot release the host lease before durable completion'
);
assert_deploy_command(
    $stateOnly->contenderPassedInitialFence
        && $stateOnly->contenderBeginExit === 73
        && $stateOnly->leaseActiveAtProviderComplete === true,
    'a contender whose initial fence read is stale still cannot acquire between the terminal provider and external completion'
);
assert_deploy_command(
    !$stateOnly->leaseActive
        && array_slice($stateOnlyResult['callbacks'], -1) === [
            'abort:deploy-command-test:' . str_repeat('a', 64),
        ],
    'the host releases the exact state-only lease only after external provider completion'
);
assert_deploy_command(
    count(array_filter(
        $stateOnly->events,
        static fn(string $event): bool => str_contains($event, 'code-stage')
            || str_contains($event, 'code-finalize')
    )) === 0,
    'state-only deploy never enters either code mutation boundary'
);

// A provider failure after schema completion is not a no-op on retry. The
// database-external settlement record retains the completed schema phase and
// fences the next compile until the exact checkpoint is recovered.
$providerFailure = new DeployCommandDriver();
$providerFailure->codeEnabled = false;
$providerFailure->codeChangeRequired = false;
$providerFailure->lifecycleChangeRequired = true;
$providerFailure->schemaDeclared = true;
$providerFailure->schemaRequired = true;
$providerFailure->lifecycleSettlementDeclared = true;
$providerFailure->lifecycleSettleExit = 31;
$providerFailureResult = run_deploy_command($providerFailure, []);
assert_deploy_command($providerFailureResult['exit'] === 31, 'state-only lifecycle provider refusal propagates unchanged');
assert_deploy_command(
    array_slice($providerFailure->events, -8) === [
        'capture:provider-settlement-begin',
        'stream:deploy:retire',
        'raw:provider-settlement-advance:lifecycle-retire',
        'stream:deploy:activate',
        'raw:provider-settlement-advance:lifecycle-activate',
        'stream:schema-settle',
        'raw:provider-settlement-advance:schema-settle',
        'stream:lifecycle-settle',
    ],
    'provider refusal follows one durably recorded schema completion and no later provider progress'
);
assert_deploy_command($providerFailure->providerDebtActive, 'failed provider settlement remains database-external debt');
assert_deploy_command(
    $providerFailureResult['callbacks'] === [
        'scope', 'fence:deploy-fixture', 'run-id',
        'abort:deploy-command-test:' . str_repeat('a', 64),
        'recovery:/fixture/repo/.wprism/checkpoints/deploy-deploy-command-test.sql.enc:nocode',
    ],
    'state-only provider failure guides database recovery without claiming code moved'
);
$providerRetry = run_deploy_command($providerFailure, []);
assert_deploy_command($providerRetry['exit'] === 75, 'unrecovered provider debt blocks retry before no-op preflight');
assert_deploy_command(
    array_slice($providerFailure->events, -2) === ['raw:mkdir', 'capture:compile'],
    'retry cannot inspect lifecycle or schema while provider debt remains'
);

// Missing authored schema with durable canonical history is loss, not an
// installation opportunity. The agent reports it during read-only status so
// the host never takes a lease, checkpoints the damaged database, or prints
// recovery guidance for an unusable checkpoint.
$schemaLoss = schema_loss_deploy_driver();
$schemaLossResult = run_deploy_command($schemaLoss, []);
assert_deploy_command($schemaLossResult['exit'] === 29, 'authored schema-loss refusal propagates unchanged');
assert_deploy_command(
    $schemaLoss->events === [
        'raw:mkdir', 'capture:compile', 'capture:lifecycle-status', 'capture:schema-status',
    ],
    'authored schema loss refuses before promotion-begin, checkpoint, and every mutation phase'
);
assert_deploy_command(
    $schemaLossResult['callbacks'] === ['scope', 'fence:deploy-fixture', 'run-id'],
    'preflight schema loss invents neither lease cleanup nor checkpoint recovery guidance'
);
$schemaLossProcess = proc_open(
    [PHP_BINARY, __FILE__, '--schema-loss-output-child'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $schemaLossPipes
);
assert_deploy_command(is_resource($schemaLossProcess), 'schema-loss output child starts');
$schemaLossStdout = (string) stream_get_contents($schemaLossPipes[1]);
$schemaLossStderr = (string) stream_get_contents($schemaLossPipes[2]);
fclose($schemaLossPipes[1]);
fclose($schemaLossPipes[2]);
$schemaLossStatus = proc_close($schemaLossProcess);
assert_deploy_command($schemaLossStatus === 29, 'schema-loss output child preserves the target refusal exit');
assert_deploy_command(
    $schemaLossStdout === "deploy phase: compile\ndeploy phase: lifecycle-status\ndeploy phase: schema-status\n",
    'schema-loss output stops at the same read-only phase boundary'
);
assert_deploy_command(
    str_contains($schemaLossStderr, 'wprism: deploy: schema readiness preflight failed; no target mutation occurred')
        && str_contains($schemaLossStderr, 'Container wprism-fixture-cli2-run-private Creating')
        && str_contains($schemaLossStderr, '"format":"wprism-command-refusal/v1"')
        && str_contains($schemaLossStderr, '"command":"schema-status"')
        && str_contains($schemaLossStderr, '"details_redacted":true'),
    'deploy renders the stable preflight line, transport chatter, and actual redacted schema-status envelope'
);
assert_deploy_command(
    substr_count($schemaLossStderr, ".wprism/refusals/") === 1
        && str_contains($schemaLossStderr, "the target's schema-status refusal was redacted")
        && !str_contains($schemaLossStderr, 'durable canonical history remains'),
    'deploy points once to private evidence without disclosing the authored-loss sentence'
);

// A post-begin stage failure uses the injected shared exact-abort primitive and
// cannot continue into lifecycle/finalize phases.
$stage = new DeployCommandDriver();
$stage->stageExit = 8;
$stageResult = run_deploy_command($stage, []);
assert_deploy_command($stageResult['exit'] === 8, 'stage exit propagates unchanged');
assert_deploy_command(
    $stage->events === [
        'raw:mkdir', 'capture:compile', 'capture:code-preflight', 'capture:promotion-begin',
        'capture:checkpoint-target', 'capture:db-export', 'stream:code-stage',
    ],
    'stage failure stops later lifecycle and finalize phases'
);
assert_deploy_command(
    $stageResult['callbacks'] === [
        'scope', 'fence:deploy-fixture', 'run-id',
        'abort:deploy-command-test:' . str_repeat('a', 64),
        'recovery:/fixture/repo/.wprism/checkpoints/deploy-deploy-command-test.sql.enc:code',
    ],
    'stage failure delegates exact cleanup to the shared promotion primitive, then guides recovery of its checkpoint'
);

echo "PASS: deploy command\n";
