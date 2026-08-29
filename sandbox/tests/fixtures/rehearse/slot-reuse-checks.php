<?php
/**
 * Offline product-client proof for one reusable physical rehearsal slot.
 *
 * The real provider is driven through CommandEnvironmentProvider, so every
 * successful response crosses the shipped canonical-byte, closed-key and
 * action-result validation boundary. Fake docker/pair executables expose the
 * physical side effects without needing Docker, WordPress or a network.
 *
 * usage: php slot-reuse-checks.php <scratch-dir>
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/check.php';
require_once dirname(__DIR__, 4) . '/cli/src/Environment/EnvironmentLifecycle.php';

use WPrism\Orchestrator\CommandEnvironmentProvider;

$scratch = $argv[1] ?? '';
if ($scratch === '') {
    fwrite(STDERR, "usage: slot-reuse-checks.php <scratch-dir>\n");
    exit(2);
}

$root = dirname(__DIR__, 4);
$providerScript = $root . '/tools/reference-env-provider.php';
$makeConfig = __DIR__ . '/make-provider-config.php';
$configPath = $scratch . '/provider.json';
$stateRoot = $scratch . '/state';
$fakeBin = $scratch . '/bin';
$actionLog = $scratch . '/physical-actions.log';
$started = $scratch . '/slot-started';
$failPair = $scratch . '/fail-pair-up';
$failClear = $scratch . '/fail-side-clear';
$slowPair = $scratch . '/slow-pair-up';
$pairEntered = $scratch . '/pair-up-entered';
$slowClear = $scratch . '/slow-side-clear';
$clearEntered = $scratch . '/side-clear-entered';
$fakePair = $scratch . '/pair.sh';
$composeDir = $scratch . '/compose';
$sourceRepo = $composeDir . '/siterepo/mup1';
$targetRepo = $composeDir . '/siterepo/mup2';

foreach ([$stateRoot, $fakeBin, $sourceRepo, $targetRepo] as $directory) {
    if (!is_dir($directory) && !mkdir($directory, 0700, true)) {
        fwrite(STDERR, "FAIL: could not create slot-reuse fixture directory\n");
        exit(1);
    }
}

$docker = <<<'SH'
#!/bin/sh
printf 'docker' >> "$WPRISM_SLOT_ACTION_LOG"
printf ' <%s>' "$@" >> "$WPRISM_SLOT_ACTION_LOG"
printf '\n' >> "$WPRISM_SLOT_ACTION_LOG"
if [ "${1:-}" = port ]; then
  [ -f "$WPRISM_SLOT_STARTED" ] || exit 33
  case "${2:-}" in
    *wp1*) printf '0.0.0.0:8181\n' ;;
    *)     printf '0.0.0.0:8182\n' ;;
  esac
fi
if [ -f "$WPRISM_SLOT_FAIL_CLEAR" ]; then
  case " $* " in
    *" rm -rf /var/www/html/wp-content/uploads "*) exit 45 ;;
  esac
fi
if [ -f "$WPRISM_SLOT_SLOW_CLEAR" ]; then
  case " $* " in
    *" rm -rf /var/www/html/wp-content/uploads "*)
      : > "$WPRISM_SLOT_CLEAR_ENTERED"
      while [ -f "$WPRISM_SLOT_SLOW_CLEAR" ]; do sleep 0.05; done
      ;;
  esac
fi
exit 0
SH;
$pair = <<<'SH'
#!/bin/sh
printf 'pair' >> "$WPRISM_SLOT_ACTION_LOG"
printf ' <%s>' "$@" >> "$WPRISM_SLOT_ACTION_LOG"
printf '\n' >> "$WPRISM_SLOT_ACTION_LOG"
case "${1:-}" in
  up)
    [ ! -f "$WPRISM_SLOT_FAIL_PAIR" ] || exit 44
    if [ -f "$WPRISM_SLOT_SLOW_PAIR" ]; then
      : > "$WPRISM_SLOT_PAIR_ENTERED"
      while [ -f "$WPRISM_SLOT_SLOW_PAIR" ]; do sleep 0.05; done
    fi
    : > "$WPRISM_SLOT_STARTED"
    ;;
  destroy)
    rm -f "$WPRISM_SLOT_STARTED"
    ;;
esac
exit 0
SH;

file_put_contents($fakeBin . '/docker', $docker . "\n");
file_put_contents($fakePair, $pair . "\n");
chmod($fakeBin . '/docker', 0700);
chmod($fakePair, 0700);

$make = proc_open(
    [PHP_BINARY, $makeConfig, $configPath],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $makePipes,
    null,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($make)) {
    fwrite(STDERR, "FAIL: could not start provider config writer\n");
    exit(1);
}
fclose($makePipes[1]);
fclose($makePipes[2]);
if (proc_close($make) !== 0) {
    fwrite(STDERR, "FAIL: could not write provider config\n");
    exit(1);
}

$config = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
$config['pair_script'] = $fakePair;
$config['state_root'] = $stateRoot;
$config['controller_repo'] = $scratch . '/origin.git';
$config['compose_dir'] = $composeDir;
$config['compose_files'] = [$composeDir . '/pair.yml', $composeDir . '/pair.http.yml'];
$config['environments']['mup1']['repo'] = $sourceRepo;
$config['environments']['mup2']['repo'] = $targetRepo;
file_put_contents($composeDir . '/pair.yml', "services: {}\n");
file_put_contents($composeDir . '/pair.http.yml', "services: {}\n");
file_put_contents(
    $configPath,
    json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n"
);

putenv('PATH=' . $fakeBin . PATH_SEPARATOR . (string) getenv('PATH'));
putenv('WPRISM_SLOT_ACTION_LOG=' . $actionLog);
putenv('WPRISM_SLOT_STARTED=' . $started);
putenv('WPRISM_SLOT_FAIL_PAIR=' . $failPair);
putenv('WPRISM_SLOT_FAIL_CLEAR=' . $failClear);
putenv('WPRISM_SLOT_SLOW_PAIR=' . $slowPair);
putenv('WPRISM_SLOT_PAIR_ENTERED=' . $pairEntered);
putenv('WPRISM_SLOT_SLOW_CLEAR=' . $slowClear);
putenv('WPRISM_SLOT_CLEAR_ENTERED=' . $clearEntered);

/** @return CommandEnvironmentProvider */
function slot_provider(string $providerScript, string $configPath, string $environment, string $label): CommandEnvironmentProvider {
    $provider = CommandEnvironmentProvider::fromEnvironment($environment, [
        '_machine_local' => true,
        'environment_provider' => [
            'command' => [PHP_BINARY, $providerScript, $configPath],
            'timeout_seconds' => 20,
        ],
    ]);
    $provider->capabilities('slot-capabilities-' . $label . '-00000001');
    return $provider;
}

/** @return array{exit:int,result:array<string,mixed>,error:string,provider_error:string} */
function slot_call(
    CommandEnvironmentProvider $provider,
    string $stateRoot,
    string $action,
    string $operation,
    array $input
): array {
    $errorPath = $stateRoot . '/provider-errors.log';
    $before = (string) @file_get_contents($errorPath);
    try {
        return [
            'exit' => 0,
            'result' => $provider->perform($action, $operation, $input),
            'error' => '',
            'provider_error' => '',
        ];
    } catch (Throwable $error) {
        $after = (string) @file_get_contents($errorPath);
        return [
            'exit' => 1,
            'result' => [],
            'error' => $error->getMessage(),
            'provider_error' => substr($after, strlen($before)),
        ];
    }
}

/** @param mixed $value @return mixed */
function slot_normalize(mixed $value): mixed {
    if (!is_array($value)) return $value;
    if (array_is_list($value)) return array_map('slot_normalize', $value);
    ksort($value, SORT_STRING);
    foreach ($value as $key => $item) $value[$key] = slot_normalize($item);
    return $value;
}

/** @param array<string,mixed> $input @return array<string,mixed> */
function slot_request(string $environment, string $action, string $operation, array $input): array {
    return [
        'action' => $action,
        'environment' => $environment,
        'format' => 'wprism-branch-environment-provider-request/v1',
        'input' => $input,
        'operation_id' => $operation,
    ];
}

/** @return array{exit:int,stdout:string,stderr:string,decoded:?array<string,mixed>} */
function slot_raw(string $providerScript, string $configPath, array $request, bool $plan = false): array {
    $command = [PHP_BINARY, $providerScript];
    if ($plan) $command[] = '--print-plan';
    $command[] = $configPath;
    $process = proc_open(
        $command,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) throw new RuntimeException('could not start reference provider');
    $bytes = json_encode(slot_normalize($request), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    fwrite($pipes[0], $bytes);
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exit = proc_close($process);
    $decoded = null;
    if ($exit === 0) {
        $value = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($value) && !array_is_list($value)) $decoded = $value;
    }
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'decoded' => $decoded];
}

/** @return array{process:resource,stdout:resource,stderr:resource} */
function slot_raw_start(string $providerScript, string $configPath, array $request): array {
    $process = proc_open(
        [PHP_BINARY, $providerScript, $configPath],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        null,
        null,
        ['bypass_shell' => true]
    );
    if (!is_resource($process)) throw new RuntimeException('could not start concurrent reference provider');
    $bytes = json_encode(slot_normalize($request), JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    fwrite($pipes[0], $bytes);
    fclose($pipes[0]);
    return ['process' => $process, 'stdout' => $pipes[1], 'stderr' => $pipes[2]];
}

/**
 * @param array{process:resource,stdout:resource,stderr:resource} $running
 * @return array{exit:int,stdout:string,stderr:string,decoded:?array<string,mixed>}
 */
function slot_raw_finish(array $running): array {
    $stdout = (string) stream_get_contents($running['stdout']);
    $stderr = (string) stream_get_contents($running['stderr']);
    fclose($running['stdout']);
    fclose($running['stderr']);
    $exit = proc_close($running['process']);
    $decoded = null;
    if ($exit === 0) {
        $value = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
        if (is_array($value) && !array_is_list($value)) $decoded = $value;
    }
    return ['exit' => $exit, 'stdout' => $stdout, 'stderr' => $stderr, 'decoded' => $decoded];
}

/** @param array<string,mixed> $identity @return array<string,mixed> */
function slot_identity_input(array $identity): array {
    return [
        'expected_environment_identity' => $identity['environment_identity'],
        'expected_lease_generation' => $identity['lease_generation'],
        'expected_lease_id' => $identity['lease_id'],
        'expected_ownership_receipt_sha256' => $identity['ownership_receipt_sha256'],
        'expected_resource_id' => $identity['resource_id'],
    ];
}

/** @param array<string,mixed> $fence @return array<string,mixed> */
function slot_mutation_input(array $fence): array {
    return [
        'expected_mutation_generation' => $fence['mutation_generation'],
        'expected_mutation_id' => $fence['mutation_id'],
        'expected_mutation_owner' => $fence['mutation_owner'],
        'expected_mutation_receipt_sha256' => $fence['mutation_receipt_sha256'],
    ];
}

function slot_physical_log(string $actionLog): string {
    return (string) @file_get_contents($actionLog);
}

function slot_media_clear_count(string $actionLog): int {
    return substr_count(slot_physical_log($actionLog), 'rm -rf /var/www/html/wp-content/uploads');
}

function slot_database_command_count(string $actionLog): int {
    return substr_count(slot_physical_log($actionLog), 'docker <exec> <-i> <wprism-shared-db> <mariadb> <-uroot> <-proot>');
}

function slot_pair_up_count(string $actionLog): int {
    return substr_count(slot_physical_log($actionLog), 'pair <up>');
}

/** @param array{exit:int,result:array<string,mixed>,error:string,provider_error:string} $result */
function slot_refusal(array $result, string $needle, string $label): void {
    wprism_check_same(1, $result['exit'], $label);
    wprism_check(str_contains($result['provider_error'], $needle), $label . ' names the refused invariant');
}

$operationA = 'preview-operation-a-000000000000000000000001';
$operationB = 'preview-operation-b-000000000000000000000002';
$operationD = 'preview-operation-d-000000000000000000000004';
$operationBusy = 'preview-operation-busy-00000000000000000003';
$operationFence = 'preview-operation-fence-0000000000000000004';
$operationForged = 'preview-operation-forged-000000000000000003';
$operationReapB = 'preview-operation-reap-b-0000000000000000005';

$target = slot_provider($providerScript, $configPath, 'mup2', 'target');

$createdA = slot_call($target, $stateRoot, 'create', $operationA, ['mode' => 'create']);
wprism_check_same(0, $createdA['exit'], 'the first preview acquires the reusable physical slot through the shipped provider client');
$identityA = $createdA['result'];
wprism_check_same(1, $identityA['lease_generation'] ?? null, 'the first preview owns generation 1');
wprism_check(is_file($started), 'acquisition starts the stopped physical slot before URL discovery');
wprism_check_same(1, slot_media_clear_count($actionLog), 'generation-1 create clears media exactly once');
wprism_check_same(2, slot_database_command_count($actionLog), 'generation-1 create clears and recreates its database exactly once');

$replayedCreateA = slot_call($target, $stateRoot, 'create', $operationA, ['mode' => 'create']);
wprism_check_same(0, $replayedCreateA['exit'], 'a lost generation-1 create response is retryable through the shipped provider client');
wprism_check_same($identityA['_response_sha256'] ?? null, $replayedCreateA['result']['_response_sha256'] ?? null, 'a repeated create returns the exact generation-1 provider response');
wprism_check_same(1, slot_media_clear_count($actionLog), 'replaying create does not clear populated media');
wprism_check_same(2, slot_database_command_count($actionLog), 'replaying create does not clear the populated database');

unlink($started);
$pairUpsBeforeMissingRetry = slot_pair_up_count($actionLog);
$missingPresentRetry = slot_call($target, $stateRoot, 'create', $operationA, ['mode' => 'create']);
slot_refusal($missingPresentRetry, 'reference provider command failed', 'a present-lease retry refuses when its physical slot disappeared');
wprism_check_same($pairUpsBeforeMissingRetry, slot_pair_up_count($actionLog), 'a present-lease retry never reallocates a disappeared pair');
wprism_check_same(1, slot_media_clear_count($actionLog), 'a disappeared present-lease retry does not clear media');
wprism_check_same(2, slot_database_command_count($actionLog), 'a disappeared present-lease retry does not clear the database');
touch($started);

$beforeBusy = slot_physical_log($actionLog);
$busy = slot_call($target, $stateRoot, 'create', $operationBusy, ['mode' => 'create']);
slot_refusal($busy, 'preview slot is already owned', 'another branch cannot acquire an occupied preview slot');
wprism_check_same($beforeBusy, slot_physical_log($actionLog), 'a refused concurrent acquisition executes no physical command');

$healthyActiveState = (string) file_get_contents($stateRoot . '/state.json');
unlink($stateRoot . '/state.json');
$beforeMissingState = slot_physical_log($actionLog);
$missingStateAcquire = slot_call($target, $stateRoot, 'create', $operationBusy, ['mode' => 'create']);
slot_refusal($missingStateAcquire, 'state is missing after initialization', 'missing durable state cannot reset an occupied slot to generation zero');
wprism_check_same($beforeMissingState, slot_physical_log($actionLog), 'missing provider state refuses before physical mutation');
$missingStatePlan = slot_raw($providerScript, $configPath, slot_request('mup2', 'create', $operationBusy, ['mode' => 'create']), true);
wprism_check_same(1, $missingStatePlan['exit'], 'dry-run cannot plan generation zero after initialized state is lost');
wprism_check(str_contains($missingStatePlan['stderr'], 'state is missing after initialization'), 'dry-run names the same missing-state invariant as live acquisition');
wprism_check_same($beforeMissingState, slot_physical_log($actionLog), 'missing-state dry-run executes no physical command');
file_put_contents($stateRoot . '/state.json', $healthyActiveState);

$phaseState = json_decode($healthyActiveState, true, 512, JSON_THROW_ON_ERROR);
foreach ($phaseState['acquisitions'] as &$candidate) {
    if (($candidate['operation_id'] ?? null) === $operationA) $candidate['state'] = 'acquiring';
}
unset($candidate);
file_put_contents($stateRoot . '/state.json', json_encode($phaseState, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$beforePhaseMismatch = slot_physical_log($actionLog);
$phaseMismatch = slot_call($target, $stateRoot, 'create', $operationA, ['mode' => 'create']);
slot_refusal($phaseMismatch, 'history differs from current ownership', 'acquisition and resource phases must agree before retry');
wprism_check_same($beforePhaseMismatch, slot_physical_log($actionLog), 'phase-mismatched retry executes no physical command');
file_put_contents($stateRoot . '/state.json', $healthyActiveState);

$missingResourceState = json_decode($healthyActiveState, true, 512, JSON_THROW_ON_ERROR);
unset($missingResourceState['resources']['wprism-mup-wp2']);
file_put_contents($stateRoot . '/state.json', json_encode($missingResourceState, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$beforeMissingResource = slot_physical_log($actionLog);
$missingResource = slot_call($target, $stateRoot, 'create', $operationBusy, ['mode' => 'create']);
slot_refusal($missingResource, 'resource state is missing beneath its initialized authority', 'initialized authority cannot fall back to a virgin resource row');
wprism_check_same($beforeMissingResource, slot_physical_log($actionLog), 'missing resource row refuses before physical mutation');
file_put_contents($stateRoot . '/state.json', $healthyActiveState);

$driftConfigPath = $scratch . '/provider-drift.json';
$driftConfig = $config;
$driftConfig['environments']['mup2']['port'] = 8282;
file_put_contents($driftConfigPath, json_encode($driftConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$driftTarget = slot_provider($providerScript, $driftConfigPath, 'mup2', 'drift');
$beforeDrift = slot_physical_log($actionLog);
$driftedInspect = slot_call(
    $driftTarget,
    $stateRoot,
    'inspect',
    $operationA,
    slot_identity_input($identityA) + ['role' => 'target']
);
slot_refusal($driftedInspect, 'state authority is bound to another configured target', 'an active lease refuses changed configured mutation topology');
wprism_check_same($beforeDrift, slot_physical_log($actionLog), 'configured-topology drift refuses before any physical command');

$aliasConfigPath = $scratch . '/provider-alias.json';
$aliasConfig = $config;
$aliasConfig['environments']['mup2']['database'] = $aliasConfig['environments']['mup1']['database'];
file_put_contents($aliasConfigPath, json_encode($aliasConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$alias = slot_raw($providerScript, $aliasConfigPath, slot_request('mup2', 'capabilities', $operationA, []));
wprism_check_same(1, $alias['exit'], 'configured endpoints cannot alias the source side');
wprism_check(str_contains($alias['stderr'], 'database does not belong to its pair side'), 'endpoint-alias refusal names the noncanonical database');

$pairScopeConfigPath = $scratch . '/provider-pair-scope.json';
$pairScopeConfig = $config;
$pairScopeConfig['destroy_scope'] = 'pair';
file_put_contents($pairScopeConfigPath, json_encode($pairScopeConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$pairScope = slot_raw($providerScript, $pairScopeConfigPath, slot_request('mup2', 'capabilities', $operationA, []));
wprism_check_same(1, $pairScope['exit'], 'one target lease cannot authorize pair-wide source deletion');
wprism_check(str_contains($pairScope['stderr'], 'one target lease cannot authorize pair-wide source deletion'), 'pair-scope refusal names the missing source authority');

$pathConfigPath = $scratch . '/provider-path-alias.json';
$pathConfig = $config;
$pathConfig['environments']['mup2']['repo'] = $composeDir . '/siterepo/other/../mup2';
file_put_contents($pathConfigPath, json_encode($pathConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$pathAlias = slot_raw($providerScript, $pathConfigPath, slot_request('mup2', 'capabilities', $operationA, []));
wprism_check_same(1, $pathAlias['exit'], 'destructive repository paths must be lexically normalized');
wprism_check(str_contains($pathAlias['stderr'], 'must be lexically normalized'), 'path-alias refusal names the normalization invariant');

$sourceStateRoot = $sourceRepo . '/provider-state';
mkdir($sourceStateRoot, 0700, true);
$sourceStateConfigPath = $scratch . '/provider-source-state-overlap.json';
$sourceStateConfig = $config;
$sourceStateConfig['state_root'] = $sourceStateRoot;
file_put_contents($sourceStateConfigPath, json_encode($sourceStateConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$sourceStateOverlap = slot_raw($providerScript, $sourceStateConfigPath, slot_request('mup2', 'capabilities', $operationA, []));
wprism_check_same(1, $sourceStateOverlap['exit'], 'provider-owned state cannot live inside the source repository');
wprism_check(str_contains($sourceStateOverlap['stderr'], 'state authority path') && str_contains($sourceStateOverlap['stderr'], 'overlaps the source repo'), 'source-state overlap refusal names the authority boundary');

$foreignPairConfigPath = $scratch . '/provider-foreign-pair.json';
$foreignPairConfig = $config;
$foreignPairConfig['pair'] = 'other';
$foreignPairConfig['source_environment'] = 'other1';
$foreignPairConfig['environments'] = [
    'other1' => [
        'role' => 'source', 'side' => 1, 'port' => 8181,
        'container' => 'wprism-other-wp1-1', 'service' => 'cli1',
        'database' => 'wp_other1', 'repo' => $composeDir . '/siterepo/other1',
    ],
    'other2' => [
        'role' => 'target', 'side' => 2, 'port' => 8182,
        'container' => 'wprism-other-wp2-1', 'service' => 'cli2',
        'database' => 'wp_other2', 'repo' => $composeDir . '/siterepo/other2',
    ],
];
foreach ([$composeDir . '/siterepo/other1', $composeDir . '/siterepo/other2'] as $directory) {
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException('could not create foreign pair fixture repo');
}
file_put_contents($foreignPairConfigPath, json_encode($foreignPairConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$foreignPair = slot_provider($providerScript, $foreignPairConfigPath, 'other2', 'foreign-pair');
$beforeForeignPair = slot_physical_log($actionLog);
$foreignPairAcquire = slot_call($foreignPair, $stateRoot, 'create', $operationBusy, ['mode' => 'create']);
slot_refusal($foreignPairAcquire, 'state authority is bound to another configured target', 'one state root cannot mint a second resource id over an occupied slot');
wprism_check_same($beforeForeignPair, slot_physical_log($actionLog), 'foreign resource-id acquisition refuses before physical mutation');

$source = slot_provider($providerScript, $configPath, 'mup1', 'source');
$sourceInspect = slot_call($source, $stateRoot, 'inspect', $operationA, ['role' => 'source']);
wprism_check_same(0, $sourceInspect['exit'], 'the source remains inspectable after target lease rotation was introduced');
$sourceIdentity = 'wprism-pair-mup-side-1';
wprism_check_same(
    'pair-lease-' . substr(hash('sha256', $sourceIdentity), 0, 20),
    $sourceInspect['result']['lease_id'] ?? null,
    'source lease identity remains byte-compatible with the pre-slot provider'
);
wprism_check_same(
    hash('sha256', 'ownership:' . $sourceIdentity),
    $sourceInspect['result']['ownership_receipt_sha256'] ?? null,
    'source ownership evidence remains byte-compatible with the pre-slot provider'
);
$sourceDriftConfigPath = $scratch . '/provider-source-drift.json';
$sourceDriftConfig = $config;
$sourceDriftConfig['compose_files'][1] = $composeDir . '/pair.changed.yml';
file_put_contents($composeDir . '/pair.changed.yml', "services: {}\n");
file_put_contents($sourceDriftConfigPath, json_encode($sourceDriftConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$sourceDrift = slot_provider($providerScript, $sourceDriftConfigPath, 'mup1', 'source-drift');
$beforeSourceDrift = slot_physical_log($actionLog);
$sourcePrepare = slot_call(
    $sourceDrift,
    $stateRoot,
    'snapshot-prepare',
    $operationA,
    slot_identity_input($sourceInspect['result']) + ['snapshot_session_id' => 'source-session-000000000001']
);
slot_refusal($sourcePrepare, 'source topology differs from the inspected operation', 'snapshot preparation refuses source topology changed after inspect');
wprism_check_same($beforeSourceDrift, slot_physical_log($actionLog), 'source-topology drift refuses before snapshot commands');
$beforeSourceDestroy = slot_physical_log($actionLog);
$sourceDestroy = slot_call($source, $stateRoot, 'destroy', $operationA, []);
slot_refusal($sourceDestroy, 'requires the configured target role', 'a destructive target action cannot run against the source side');
wprism_check_same($beforeSourceDestroy, slot_physical_log($actionLog), 'source-role destruction refuses before any physical command');

$planInput = slot_identity_input($identityA) + ['url' => $identityA['url']];
$plan = slot_raw($providerScript, $configPath, slot_request('mup2', 'url-set', $operationA, $planInput), true);
wprism_check_same(0, $plan['exit'], '--print-plan validates the real active generation-1 identity from provider state');
wprism_check_same(true, $plan['decoded']['identity_input_checked'] ?? null, 'the active target plan records that its real lease identity was checked');
$foreignPlanInput = $planInput;
$foreignPlanInput['expected_lease_generation'] = 99;
$foreignPlan = slot_raw($providerScript, $configPath, slot_request('mup2', 'url-set', $operationA, $foreignPlanInput), true);
wprism_check_same(1, $foreignPlan['exit'], '--print-plan refuses a foreign generation instead of validating a fabricated target identity');
wprism_check(str_contains($foreignPlan['stderr'], "identity input differs at 'expected_lease_generation'"), 'the plan refusal names the foreign generation');
$retryCreatePlan = slot_raw($providerScript, $configPath, slot_request('mup2', 'create', $operationA, ['mode' => 'create']), true);
wprism_check_same(0, $retryCreatePlan['exit'], '--print-plan accepts the exact active create retry');
wprism_check_same(
    [['argv' => ['docker', 'port', 'wprism-mup-wp2-1', '80/tcp']]],
    $retryCreatePlan['decoded']['commands'] ?? null,
    'an active create retry plans only the non-mutating physical-presence proof'
);
$busyPlan = slot_raw($providerScript, $configPath, slot_request('mup2', 'create', $operationBusy, ['mode' => 'create']), true);
wprism_check_same(1, $busyPlan['exit'], '--print-plan refuses a foreign acquisition while the slot is occupied');
wprism_check(str_contains($busyPlan['stderr'], 'preview slot is already owned'), 'the foreign acquisition plan names slot ownership');

$fenceAResponse = slot_call(
    $target,
    $stateRoot,
    'mutation-acquire',
    $operationA,
    slot_identity_input($identityA) + ['mutation_owner' => 'preview-owner-a']
);
wprism_check_same(0, $fenceAResponse['exit'], 'generation 1 acquires its mutation fence');
$fenceA = $fenceAResponse['result'];
$foreignFence = slot_call(
    $target,
    $stateRoot,
    'mutation-acquire',
    $operationFence,
    slot_identity_input($identityA) + ['mutation_owner' => 'preview-owner-foreign']
);
slot_refusal($foreignFence, 'already has a held mutation fence', 'one active preview lease cannot hold two mutation fences');

$destroyAInput = slot_identity_input($identityA) + slot_mutation_input($fenceA) + ['compare_and_reap' => true];
$beforeWrongMode = slot_physical_log($actionLog);
$wrongDetachA = slot_call($target, $stateRoot, 'detach', $operationA, $destroyAInput);
slot_refusal($wrongDetachA, "acquired with 'create' must be reaped with 'destroy'", 'a created slot cannot be detached');
wprism_check_same($beforeWrongMode, slot_physical_log($actionLog), 'a reap-mode mismatch executes no physical command');

$destroyedA = slot_call($target, $stateRoot, 'destroy', $operationA, $destroyAInput);
wprism_check_same(0, $destroyedA['exit'], 'generation 1 is reaped through compare-and-reap');
wprism_check_same(2, slot_media_clear_count($actionLog), 'generation-1 reap clears media exactly once');
wprism_check_same(4, slot_database_command_count($actionLog), 'generation-1 reap clears its database exactly once');

$alteredAInput = $destroyAInput;
$alteredAInput['compare_and_reap'] = false;
$beforeAltered = slot_physical_log($actionLog);
$alteredA = slot_call($target, $stateRoot, 'destroy', $operationA, $alteredAInput);
slot_refusal($alteredA, 'preview slot has no active owner', 'the terminal operation id with changed input is not a receipt replay');
wprism_check_same($beforeAltered, slot_physical_log($actionLog), 'an altered terminal replay executes no physical command');

$createdB = slot_call($target, $stateRoot, 'create', $operationB, ['mode' => 'create']);
wprism_check_same(0, $createdB['exit'], 'a second preview reuses the physical slot');
$identityB = $createdB['result'];
wprism_check_same(2, $identityB['lease_generation'] ?? null, 'slot reuse rotates to generation 2');
wprism_check_same($identityA['environment_identity'] ?? null, $identityB['environment_identity'] ?? null, 'slot reuse retains the logical environment identity');
wprism_check_same($identityA['resource_id'] ?? null, $identityB['resource_id'] ?? null, 'slot reuse retains the physical resource id');
wprism_check(
    ($identityB['lease_id'] ?? null) !== ($identityA['lease_id'] ?? null)
        && ($identityB['ownership_receipt_sha256'] ?? null) !== ($identityA['ownership_receipt_sha256'] ?? null),
    'slot reuse rotates the configured-topology-bound lease and ownership receipt'
);
wprism_check_same(3, slot_media_clear_count($actionLog), 'generation-2 acquisition starts from one clean media tree');
wprism_check_same(6, slot_database_command_count($actionLog), 'generation-2 acquisition starts from one clean database');

$branchBMarker = $targetRepo . '/branch-b.marker';
file_put_contents($branchBMarker, "generation-b\n");
$beforeOldReplay = slot_physical_log($actionLog);
$oldReplay = slot_call($target, $stateRoot, 'destroy', $operationA, $destroyAInput);
wprism_check_same(0, $oldReplay['exit'], 'an exact lost generation-1 reap response remains retryable after reuse');
wprism_check_same($destroyedA['result']['_response_sha256'] ?? null, $oldReplay['result']['_response_sha256'] ?? null, 'the lost reap returns the exact generation-1 provider response');
wprism_check_same($beforeOldReplay, slot_physical_log($actionLog), 'replaying the old reap executes no generation-2 physical command');
wprism_check_same("generation-b\n", @file_get_contents($branchBMarker), 'the cached old reap preserves generation-2 repository bytes');

$forgedOld = slot_call($target, $stateRoot, 'destroy', $operationForged, $destroyAInput);
slot_refusal($forgedOld, "identity input differs at 'expected_lease_generation'", 'a new request carrying the stale generation-1 lease refuses');
wprism_check_same($beforeOldReplay, slot_physical_log($actionLog), 'the stale generation-1 refusal executes no physical command');
wprism_check_same("generation-b\n", @file_get_contents($branchBMarker), 'the stale generation-1 refusal preserves generation-2 repository bytes');

$busyAfterReplay = slot_call($target, $stateRoot, 'create', $operationBusy, ['mode' => 'create']);
slot_refusal($busyAfterReplay, 'preview slot is already owned', 'the cached old reap does not release generation 2 for another branch');

$inspectB = slot_call($target, $stateRoot, 'inspect', $operationB, slot_identity_input($identityB) + ['role' => 'target']);
wprism_check_same(0, $inspectB['exit'], 'generation 2 remains inspectable after stale traffic');
wprism_check_same(2, $inspectB['result']['lease_generation'] ?? null, 'inspect still reports generation 2');

$fenceBResponse = slot_call(
    $target,
    $stateRoot,
    'mutation-acquire',
    $operationB,
    slot_identity_input($identityB) + ['mutation_owner' => 'preview-owner-b']
);
wprism_check_same(0, $fenceBResponse['exit'], 'generation 2 acquires its mutation fence');
$fenceB = $fenceBResponse['result'];
$releasedB = slot_call(
    $target,
    $stateRoot,
    'mutation-release',
    $operationB,
    slot_identity_input($identityB) + slot_mutation_input($fenceB)
);
wprism_check_same(0, $releasedB['exit'], 'generation 2 can release its materialization fence');
$destroyWithReleased = slot_call(
    $target,
    $stateRoot,
    'destroy',
    $operationB,
    slot_identity_input($identityB) + slot_mutation_input($releasedB['result']) + ['compare_and_reap' => true]
);
slot_refusal($destroyWithReleased, 'mutation fence is not held', 'a released fence cannot authorize destructive reap');

$reapFenceB = slot_call(
    $target,
    $stateRoot,
    'mutation-acquire',
    $operationReapB,
    slot_identity_input($identityB) + ['mutation_owner' => 'preview-owner-b-reap']
);
wprism_check_same(0, $reapFenceB['exit'], 'generation 2 acquires a distinct reap fence after release');
$destroyBInput = slot_identity_input($identityB) + slot_mutation_input($reapFenceB['result']) + ['compare_and_reap' => true];
$beforeFailedReap = slot_physical_log($actionLog);
touch($failClear);
$failedDestroyB = slot_call($target, $stateRoot, 'destroy', $operationReapB, $destroyBInput);
slot_refusal($failedDestroyB, 'reference provider command failed', 'a partial physical reap persists an incomplete reap intent');
$afterFailedReap = slot_physical_log($actionLog);
wprism_check($afterFailedReap !== $beforeFailedReap, 'the injected reap failure occurs after physical cleanup begins');
$foreignReapDuringRecovery = slot_call($target, $stateRoot, 'destroy', $operationForged, $destroyBInput);
slot_refusal($foreignReapDuringRecovery, 'reap retry differs from its persisted intent', 'a different reap request cannot resume partial cleanup');
wprism_check_same($afterFailedReap, slot_physical_log($actionLog), 'a foreign reap request executes no additional physical cleanup');
$blockedDuringReap = slot_call($target, $stateRoot, 'inspect', $operationB, slot_identity_input($identityB) + ['role' => 'target']);
slot_refusal($blockedDuringReap, 'reap is incomplete', 'an incompletely reaped slot cannot be inspected as usable');
$foreignDuringReap = slot_call($target, $stateRoot, 'create', $operationBusy, ['mode' => 'create']);
slot_refusal($foreignDuringReap, 'reap is incomplete', 'an incompletely reaped slot cannot be acquired by another branch');
wprism_check_same($afterFailedReap, slot_physical_log($actionLog), 'non-reap traffic executes no physical command while reap recovery is required');
unlink($failClear);
$destroyedB = slot_call($target, $stateRoot, 'destroy', $operationReapB, $destroyBInput);
wprism_check_same(0, $destroyedB['exit'], 'the exact generation-2 reap resumes after partial cleanup');
wprism_check(!is_file($branchBMarker), 'the authorized generation-2 reap clears its repository');

$healthyAbsentState = (string) file_get_contents($stateRoot . '/state.json');
$corruptAbsentState = json_decode($healthyAbsentState, true, 512, JSON_THROW_ON_ERROR);
foreach ($corruptAbsentState['acquisitions'] as $key => $candidate) {
    if (($candidate['operation_id'] ?? null) === $operationB) unset($corruptAbsentState['acquisitions'][$key]);
}
file_put_contents($stateRoot . '/state.json', json_encode($corruptAbsentState, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$beforeAbsentCorruption = slot_physical_log($actionLog);
$corruptAbsentAcquire = slot_call($target, $stateRoot, 'create', $operationBusy, ['mode' => 'create']);
slot_refusal($corruptAbsentAcquire, 'no terminal acquisition history', 'an absent slot with corrupt lineage cannot rotate to another generation');
wprism_check_same($beforeAbsentCorruption, slot_physical_log($actionLog), 'corrupt absent lineage refuses before physical acquisition');
file_put_contents($stateRoot . '/state.json', $healthyAbsentState);

$beforeRetiredA = slot_physical_log($actionLog);
$retiredA = slot_call($target, $stateRoot, 'create', $operationA, ['mode' => 'create']);
slot_refusal($retiredA, 'terminal preview-slot acquisition cannot be resurrected', 'an old acquisition cannot resurrect after later generations terminate');
wprism_check_same($beforeRetiredA, slot_physical_log($actionLog), 'retired acquisition replay executes no physical command');

unlink($started);
$pairUpsBeforeAttach = slot_pair_up_count($actionLog);
$missingAttach = slot_call($target, $stateRoot, 'attach', $operationD, ['mode' => 'attach']);
slot_refusal($missingAttach, 'reference provider command failed', 'attach refuses when no independently provisioned physical slot exists');
wprism_check_same($pairUpsBeforeAttach, slot_pair_up_count($actionLog), 'attach never allocates a missing pair');
$stateAfterMissingAttach = json_decode((string) file_get_contents($stateRoot . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
wprism_check_same(2, $stateAfterMissingAttach['resources']['wprism-mup-wp2']['generation'] ?? null, 'failed attach publishes no new lease generation');
touch($started);
$attachedD = slot_call($target, $stateRoot, 'attach', $operationD, ['mode' => 'attach']);
wprism_check_same(0, $attachedD['exit'], 'a pre-existing physical slot can be reused through attach');
$identityD = $attachedD['result'];
wprism_check_same(3, $identityD['lease_generation'] ?? null, 'successful attach rotates the reusable slot to generation 3');
$fenceD = slot_call(
    $target,
    $stateRoot,
    'mutation-acquire',
    $operationD,
    slot_identity_input($identityD) + ['mutation_owner' => 'preview-owner-d']
);
wprism_check_same(0, $fenceD['exit'], 'the attached generation acquires its mutation fence');
$wrongDestroyD = slot_call(
    $target,
    $stateRoot,
    'destroy',
    $operationD,
    slot_identity_input($identityD) + slot_mutation_input($fenceD['result']) + ['compare_and_reap' => true]
);
slot_refusal($wrongDestroyD, "acquired with 'attach' must be reaped with 'detach'", 'an attached slot cannot be destroyed');
$detachD = slot_call(
    $target,
    $stateRoot,
    'detach',
    $operationD,
    slot_identity_input($identityD) + slot_mutation_input($fenceD['result']) + ['compare_and_reap' => true]
);
wprism_check_same(0, $detachD['exit'], 'an attached slot is released through detach');
unlink($started);
$beforeAbsentInspect = slot_physical_log($actionLog);
$absentD = slot_call($target, $stateRoot, 'inspect', $operationD, slot_identity_input($identityD) + ['role' => 'target']);
wprism_check_same('absent', $absentD['result']['presence'] ?? null, 'a stopped reusable slot remains inspectable as absent');
wprism_check_same($beforeAbsentInspect, slot_physical_log($actionLog), 'absent inspect does not call docker port on a stopped slot');

$operationCrash = 'preview-operation-crash-00000000000000000006';
touch($failPair);
$crashed = slot_call($target, $stateRoot, 'create', $operationCrash, ['mode' => 'create']);
slot_refusal($crashed, 'reference provider command failed', 'a failed pair converge leaves an acquiring intent instead of releasing the slot');
$beforeForeignCrash = slot_physical_log($actionLog);
$foreignDuringCrash = slot_call($target, $stateRoot, 'create', $operationBusy, ['mode' => 'create']);
slot_refusal($foreignDuringCrash, 'preview slot is already owned', 'another operation cannot take an acquiring slot');
wprism_check_same($beforeForeignCrash, slot_physical_log($actionLog), 'foreign acquisition during crash recovery executes no physical command');
unlink($failPair);
$resumedCrash = slot_call($target, $stateRoot, 'create', $operationCrash, ['mode' => 'create']);
wprism_check_same(0, $resumedCrash['exit'], 'the exact crashed acquisition resumes and completes');
wprism_check_same(4, $resumedCrash['result']['lease_generation'] ?? null, 'crash recovery retains the persisted generation instead of allocating another');
$crashFence = slot_call(
    $target,
    $stateRoot,
    'mutation-acquire',
    $operationCrash,
    slot_identity_input($resumedCrash['result']) + ['mutation_owner' => 'preview-owner-crash']
);
$crashStatePath = $stateRoot . '/state.json';
$healthyCrashState = (string) file_get_contents($crashStatePath);
$corruptCrashState = json_decode($healthyCrashState, true, 512, JSON_THROW_ON_ERROR);
foreach ($corruptCrashState['acquisitions'] as $key => $candidate) {
    if (($candidate['operation_id'] ?? null) === $operationCrash) unset($corruptCrashState['acquisitions'][$key]);
}
file_put_contents($crashStatePath, json_encode($corruptCrashState, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$crashMarker = $targetRepo . '/crash-generation.marker';
file_put_contents($crashMarker, "crash-generation\n");
$beforeCorruptReap = slot_physical_log($actionLog);
$corruptReapInput = slot_identity_input($resumedCrash['result'])
    + slot_mutation_input($crashFence['result'])
    + ['compare_and_reap' => true];
$corruptReap = slot_call($target, $stateRoot, 'destroy', $operationCrash, $corruptReapInput);
slot_refusal($corruptReap, 'reap has no acquisition history', 'corrupt acquisition history refuses before destructive reap');
wprism_check_same($beforeCorruptReap, slot_physical_log($actionLog), 'corrupt acquisition history executes no physical command');
wprism_check_same("crash-generation\n", @file_get_contents($crashMarker), 'corrupt acquisition history preserves repository bytes');
file_put_contents($crashStatePath, $healthyCrashState);
$crashDestroy = slot_call(
    $target,
    $stateRoot,
    'destroy',
    $operationCrash,
    $corruptReapInput
);
wprism_check_same(0, $crashDestroy['exit'], 'the resumed acquisition can be reaped normally');

$raceOperationA = 'preview-race-operation-a-00000000000000000001';
$raceOperationB = 'preview-race-operation-b-00000000000000000002';
@unlink($pairEntered);
touch($slowPair);
$pairUpsBeforeRace = slot_pair_up_count($actionLog);
$mediaBeforeRace = slot_media_clear_count($actionLog);
$databaseBeforeRace = slot_database_command_count($actionLog);
$raceA = slot_raw_start($providerScript, $configPath, slot_request('mup2', 'create', $raceOperationA, ['mode' => 'create']));
// The barrier is the observable event or the child's death, never a wall
// clock. issue #3492: the 2.0s budget this replaced expired under load — 16
// concurrent copies of this suite on a 10-core host, 1 in 16 — and the suite
// then asserted against a slot the provider had not reached yet. The child
// touches $pairEntered before it blocks in the injected seam, so it either
// arrives or exits and this loop always ends.
while (!is_file($pairEntered) && (proc_get_status($raceA['process'])['running'] ?? false)) usleep(20000);
wprism_check(is_file($pairEntered), 'one concurrent acquisition reaches physical allocation while holding the provider state lock');
$beforeBlockedContender = slot_physical_log($actionLog);
$raceB = slot_raw_start($providerScript, $configPath, slot_request('mup2', 'create', $raceOperationB, ['mode' => 'create']));
usleep(200000);
$blockedStatus = proc_get_status($raceB['process']);
wprism_check(($blockedStatus['running'] ?? false) === true, 'the second controller remains blocked while the first holds the state lock');
wprism_check_same($beforeBlockedContender, slot_physical_log($actionLog), 'the blocked controller reaches no physical command before lock handoff');
unlink($slowPair);
$raceResultA = slot_raw_finish($raceA);
$raceResultB = slot_raw_finish($raceB);
$raceExits = [$raceResultA['exit'], $raceResultB['exit']];
sort($raceExits, SORT_NUMERIC);
wprism_check_same([0, 1], $raceExits, 'two simultaneous acquisitions produce exactly one owner and one refusal');
wprism_check_same($pairUpsBeforeRace + 1, slot_pair_up_count($actionLog), 'concurrent acquisition allocates the physical pair exactly once');
wprism_check_same($mediaBeforeRace + 1, slot_media_clear_count($actionLog), 'concurrent acquisition clears target media exactly once');
wprism_check_same($databaseBeforeRace + 2, slot_database_command_count($actionLog), 'concurrent acquisition clears the target database exactly once');
$raceWinner = $raceResultA['exit'] === 0 ? $raceResultA : $raceResultB;
$raceLoser = $raceResultA['exit'] === 0 ? $raceResultB : $raceResultA;
$raceWinnerOperation = $raceResultA['exit'] === 0 ? $raceOperationA : $raceOperationB;
wprism_check(str_contains($raceLoser['stderr'], 'preview slot is already owned'), 'the losing concurrent controller names the active owner');
$raceIdentity = $raceWinner['decoded']['result'] ?? [];
$raceCleanupOperation = 'preview-race-cleanup-000000000000000000001';
$raceFence = slot_call(
    $target,
    $stateRoot,
    'mutation-acquire',
    $raceCleanupOperation,
    slot_identity_input($raceIdentity) + ['mutation_owner' => 'preview-race-cleanup-owner']
);
wprism_check_same(0, $raceFence['exit'], 'the sole concurrent winner owns the generation used for cleanup');
$raceDestroy = slot_call(
    $target,
    $stateRoot,
    'destroy',
    $raceCleanupOperation,
    slot_identity_input($raceIdentity) + slot_mutation_input($raceFence['result']) + ['compare_and_reap' => true]
);
wprism_check_same(0, $raceDestroy['exit'], "the concurrent winner '$raceWinnerOperation' reaps normally");

$orphanAcquireOperation = 'preview-orphan-acquire-0000000000000000001';
$orphanReapOperation = 'preview-orphan-reap-0000000000000000000002';
$orphanCreated = slot_call($target, $stateRoot, 'create', $orphanAcquireOperation, ['mode' => 'create']);
wprism_check_same(0, $orphanCreated['exit'], 'the orphan-child regression acquires a fresh preview generation');
$orphanFence = slot_call(
    $target,
    $stateRoot,
    'mutation-acquire',
    $orphanReapOperation,
    slot_identity_input($orphanCreated['result']) + ['mutation_owner' => 'preview-orphan-reap-owner']
);
$orphanDestroyInput = slot_identity_input($orphanCreated['result'])
    + slot_mutation_input($orphanFence['result'])
    + ['compare_and_reap' => true];
@unlink($clearEntered);
touch($slowClear);
$orphanReap = slot_raw_start(
    $providerScript,
    $configPath,
    slot_request('mup2', 'destroy', $orphanReapOperation, $orphanDestroyInput)
);
// Same barrier discipline as the acquisition race above: wait for the seam the
// fake docker announces, or for the provider parent to exit. issue #3492 saw the
// 2.0s budget this replaced expire under load, after which the SIGKILL landed
// before the destructive child existed and the orphan the next two assertions
// are about was never created.
while (!is_file($clearEntered) && (proc_get_status($orphanReap['process'])['running'] ?? false)) usleep(20000);
wprism_check(is_file($clearEntered), 'destructive child reaches the injected timeout seam after durable reap intent');
wprism_check(proc_terminate($orphanReap['process'], 9), 'the regression forcibly terminates the provider parent at the client-timeout seam');
usleep(100000);
$beforeOrphanRetry = slot_physical_log($actionLog);
$orphanRetry = slot_raw_start(
    $providerScript,
    $configPath,
    slot_request('mup2', 'destroy', $orphanReapOperation, $orphanDestroyInput)
);
usleep(200000);
$orphanRetryStatus = proc_get_status($orphanRetry['process']);
wprism_check(($orphanRetryStatus['running'] ?? false) === true, 'exact retry waits while the killed parent\'s destructive child retains the physical lock');
wprism_check_same($beforeOrphanRetry, slot_physical_log($actionLog), 'no retry command overlaps the orphaned destructive child');
unlink($slowClear);
$orphanKilledResult = slot_raw_finish($orphanReap);
$orphanRetryResult = slot_raw_finish($orphanRetry);
wprism_check($orphanKilledResult['exit'] !== 0, 'the killed provider parent publishes no terminal receipt');
wprism_check_same(0, $orphanRetryResult['exit'], 'the exact reap resumes only after the orphaned child exits');
wprism_check_same('destroyed', $orphanRetryResult['decoded']['result']['disposition'] ?? null, 'orphan-safe retry publishes the terminal absence receipt');

$malformedStateRoot = $scratch . '/malformed-state';
mkdir($malformedStateRoot, 0700, true);
$malformedConfigPath = $scratch . '/provider-malformed-state.json';
$malformedConfig = $config;
$malformedConfig['state_root'] = $malformedStateRoot;
file_put_contents($malformedConfigPath, json_encode($malformedConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
file_put_contents($malformedStateRoot . '/state.initialized', "wprism-reference-env-provider-state/v1\n");
file_put_contents($malformedStateRoot . '/state.json', json_encode([
    'acquisitions' => [],
    'fences' => [],
    'reap_receipts' => [],
    'resources' => [['unexpected-numeric-key']],
    'sessions' => [],
    'slot_authorities' => [],
    'snapshots' => [],
    'source_inspections' => [],
    'ttls' => [],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$malformedTarget = slot_provider($providerScript, $malformedConfigPath, 'mup2', 'malformed-state');
$beforeMalformedState = slot_physical_log($actionLog);
$malformedInspect = slot_call($malformedTarget, $malformedStateRoot, 'inspect', 'malformed-state-inspect-00000000001', ['role' => 'target']);
slot_refusal($malformedInspect, "state 'resources' is malformed", 'numeric-key state maps fail closed after capability negotiation');
wprism_check_same($beforeMalformedState, slot_physical_log($actionLog), 'malformed state refuses before physical inspection');

$malformedMarkerRoot = $scratch . '/malformed-marker';
mkdir($malformedMarkerRoot . '/state.initialized', 0700, true);
$malformedMarkerConfigPath = $scratch . '/provider-malformed-marker.json';
$malformedMarkerConfig = $config;
$malformedMarkerConfig['state_root'] = $malformedMarkerRoot;
file_put_contents($malformedMarkerConfigPath, json_encode($malformedMarkerConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$malformedMarkerTarget = slot_provider($providerScript, $malformedMarkerConfigPath, 'mup2', 'malformed-marker');
$beforeMalformedMarker = slot_physical_log($actionLog);
$malformedMarkerInspect = slot_call($malformedMarkerTarget, $malformedMarkerRoot, 'inspect', 'malformed-marker-inspect-0000000001', ['role' => 'target']);
slot_refusal($malformedMarkerInspect, 'initialization marker is malformed', 'a non-file initialization marker fails closed');
$malformedMarkerPlan = slot_raw($providerScript, $malformedMarkerConfigPath, slot_request('mup2', 'create', 'malformed-marker-plan-0000000000001', ['mode' => 'create']), true);
wprism_check_same(1, $malformedMarkerPlan['exit'], 'dry-run also refuses a malformed initialization marker');
wprism_check(str_contains($malformedMarkerPlan['stderr'], 'initialization marker is malformed'), 'dry-run names the malformed marker invariant');
wprism_check_same($beforeMalformedMarker, slot_physical_log($actionLog), 'malformed marker refuses without physical inspection');

$legacyStateRoot = $scratch . '/legacy-state';
mkdir($legacyStateRoot, 0700, true);
$legacyConfigPath = $scratch . '/provider-legacy.json';
$legacyConfig = $config;
$legacyConfig['state_root'] = $legacyStateRoot;
file_put_contents($legacyConfigPath, json_encode($legacyConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
file_put_contents($legacyStateRoot . '/state.json', json_encode([
    'fences' => [],
    'resources' => ['mup2' => ['mode' => 'destroy', 'operation_id' => 'legacy-operation-0000000000000001', 'state' => 'absent']],
    'sessions' => [],
    'snapshots' => [],
    'ttls' => [],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$legacyTarget = slot_provider($providerScript, $legacyConfigPath, 'mup2', 'legacy');
$beforeLegacy = slot_physical_log($actionLog);
$legacyInspect = slot_call($legacyTarget, $legacyStateRoot, 'inspect', 'legacy-inspect-000000000000000001', ['role' => 'target']);
slot_refusal($legacyInspect, 'legacy preview-slot state has no acquisition history', 'an absent legacy tombstone fails closed instead of guessing its retired acquisition');
wprism_check_same($beforeLegacy, slot_physical_log($actionLog), 'legacy absent-state refusal executes no physical command');

$legacyActiveRoot = $scratch . '/legacy-active-state';
mkdir($legacyActiveRoot, 0700, true);
$legacyActiveConfigPath = $scratch . '/provider-legacy-active.json';
$legacyActiveConfig = $config;
$legacyActiveConfig['state_root'] = $legacyActiveRoot;
file_put_contents($legacyActiveConfigPath, json_encode($legacyActiveConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
file_put_contents($legacyActiveRoot . '/state.json', json_encode([
    'fences' => [],
    'resources' => ['mup2' => ['mode' => 'create', 'operation_id' => 'legacy-active-00000000000000001', 'state' => 'present']],
    'sessions' => [],
    'snapshots' => [],
    'ttls' => [],
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$legacyActiveTarget = slot_provider($providerScript, $legacyActiveConfigPath, 'mup2', 'legacy-active');
$beforeLegacyActive = slot_physical_log($actionLog);
$legacyActive = slot_call($legacyActiveTarget, $legacyActiveRoot, 'inspect', 'legacy-active-inspect-00000000001', ['role' => 'target']);
slot_refusal($legacyActive, 'legacy preview-slot state has no acquisition history', 'legacy active state fails closed with an explicit remediation');
wprism_check_same($beforeLegacyActive, slot_physical_log($actionLog), 'legacy active-state refusal executes no physical command');

wprism_check_summary('reusable preview slot');
