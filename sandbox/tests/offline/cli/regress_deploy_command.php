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
    public int $schemaStatusExit = 0;
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
    public bool $schemaDeclared = false;
    public bool $schemaRequired = false;
    public bool $lifecycleSettlementDeclared = false;
    public bool $leaseActive = false;
    public bool $abortSucceeds = true;
    public bool $abortRemovesLease = true;
    public bool $probeContenderAfterTerminalProvider = false;
    public bool $contenderPassedInitialFence = false;
    public ?int $contenderBeginExit = null;
    public ?bool $leaseActiveAtProviderComplete = null;

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
            return ['exit' => 0, 'stdout' => json_encode([
                'format' => 'wprism-lifecycle-status/v1',
                'reasons' => $this->lifecycleChangeRequired ? ['inactive_in_environment'] : [],
                'required' => $this->lifecycleChangeRequired,
            ], JSON_THROW_ON_ERROR), 'stderr' => ''];
        }
        if ($command === 'schema-status') {
            if ($this->schemaStatusExit !== 0) {
                return [
                    'exit' => $this->schemaStatusExit,
                    'stdout' => '',
                    'stderr' => 'schema table is absent but durable canonical history remains',
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
        if ($this->lifecycleSettlementDeclared
            && ($this->codeChangeRequired || $this->lifecycleChangeRequired || $this->schemaRequired)) {
            $phases[] = 'lifecycle-settle';
        }
        $lifecycleRequired = $this->codeChangeRequired
            || $this->lifecycleChangeRequired
            || ($this->schemaRequired && $this->lifecycleSettlementDeclared);
        if ($phases !== [] && $lifecycleRequired) {
            $phases = array_merge(['lifecycle-retire', 'lifecycle-activate'], $phases);
        }
        return $phases;
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
$schemaLoss = new DeployCommandDriver();
$schemaLoss->codeEnabled = false;
$schemaLoss->codeChangeRequired = false;
$schemaLoss->lifecycleChangeRequired = true;
$schemaLoss->schemaDeclared = true;
$schemaLoss->schemaRequired = true;
$schemaLoss->lifecycleSettlementDeclared = true;
$schemaLoss->schemaStatusExit = 29;
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
