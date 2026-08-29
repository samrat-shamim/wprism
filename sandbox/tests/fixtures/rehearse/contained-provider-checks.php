<?php
/** Product-client checks for the reference provider's contained-preview mode. */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/lib/check.php';
require_once dirname(__DIR__, 4) . '/cli/src/Environment/EnvironmentLifecycle.php';

use WPrism\Orchestrator\CommandEnvironmentProvider;

$scratch = $argv[1] ?? '';
if ($scratch === '') {
    fwrite(STDERR, "usage: contained-provider-checks.php <scratch-dir>\n");
    exit(2);
}
$root = dirname(__DIR__, 4);
$providerScript = $root . '/tools/reference-env-provider.php';
$makeConfig = __DIR__ . '/make-provider-config.php';
$stateRoot = $scratch . '/state';
$compose = $scratch . '/compose';
$bin = $scratch . '/bin';
$physicalState = $scratch . '/physical.json';
$physicalLog = $scratch . '/physical.ndjson';
$drift = $scratch . '/drift';
$cliDrift = $scratch . '/cli-drift';
$configPath = $scratch . '/provider.json';
foreach ([$stateRoot, $compose . '/siterepo/mup1', $compose . '/siterepo/mup2', $bin] as $directory) {
    if (!mkdir($directory, 0700, true) && !is_dir($directory)) throw new RuntimeException("could not create '$directory'");
}
foreach (['adapter_packages', 'agent', 'platform'] as $source) {
    $path = $scratch . '/runtime-' . $source;
    if (!mkdir($path, 0700)) throw new RuntimeException("could not create '$path'");
    file_put_contents($path . '/identity.txt', $source . "\n");
}
file_put_contents($scratch . '/runtime-agent/wprism-loader.php', "<?php\n");
file_put_contents($physicalState, "{\"database\":false,\"proxy\":false,\"wordpress\":false}\n");
symlink(__DIR__ . '/fake-contained-docker.php', $bin . '/docker');

$make = proc_open(
    [PHP_BINARY, $makeConfig, $configPath, '', 'contained'],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    null,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($make)) throw new RuntimeException('could not write contained provider config');
fclose($pipes[1]);
fclose($pipes[2]);
if (proc_close($make) !== 0) throw new RuntimeException('contained provider config writer failed');
$config = json_decode((string) file_get_contents($configPath), true, 512, JSON_THROW_ON_ERROR);
$config['compose_dir'] = $compose;
$config['compose_files'] = [$root . '/sandbox/pair.yml', $root . '/sandbox/pair.http.yml'];
$config['controller_repo'] = $scratch . '/origin.git';
$config['pair_script'] = $root . '/sandbox/bin/pair.sh';
$config['state_root'] = $stateRoot;
$config['environments']['mup1']['repo'] = $compose . '/siterepo/mup1';
$config['environments']['mup2']['repo'] = $compose . '/siterepo/mup2';
$config['contained_preview']['runtime_sources'] = [
    'adapter_packages' => $scratch . '/runtime-adapter_packages',
    'agent' => $scratch . '/runtime-agent',
    'platform' => $scratch . '/runtime-platform',
];
file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

putenv('PATH=' . $bin . PATH_SEPARATOR . (string) getenv('PATH'));
putenv('WPRISM_CONTAINED_FAKE_STATE=' . $physicalState);
putenv('WPRISM_CONTAINED_FAKE_LOG=' . $physicalLog);
putenv('WPRISM_CONTAINED_FAKE_DRIFT=' . $drift);
putenv('WPRISM_CONTAINED_FAKE_CLI_DRIFT=' . $cliDrift);

$provider = CommandEnvironmentProvider::fromEnvironment('mup2', [
    '_machine_local' => true,
    'environment_provider' => [
        'command' => [PHP_BINARY, $providerScript, $configPath],
        'timeout_seconds' => 30,
    ],
]);
$operation = 'contained-preview-operation-0000000000000001';
$capabilities = $provider->capabilities($operation)->toArray()['capabilities'];
wprism_check(in_array('environment.containment.verify', $capabilities, true), 'contained-preview mode advertises environment.containment.verify');
wprism_check(!in_array('environment.attach', $capabilities, true), 'contained-preview mode refuses retroactive attach authority');
wprism_check(!in_array('environment.detach', $capabilities, true), 'contained-preview mode pairs create only with exact destroy');

$createInput = ['mode' => 'create'];
touch($cliDrift);
try {
    $provider->perform('create', $operation, $createInput);
    wprism_check(false, 'a CLI Docker-socket mount refuses before preview boot');
} catch (Throwable) {
    wprism_check(true, 'a CLI Docker-socket mount refuses before preview boot');
}
$failedPhysical = json_decode((string) file_get_contents($physicalState), true, 512, JSON_THROW_ON_ERROR);
wprism_check_same(false, $failedPhysical['database'] ?? null, 'a hostile rendered CLI model refuses before database boot');
wprism_check_same(false, $failedPhysical['wordpress'] ?? null, 'a hostile rendered CLI model refuses before WordPress boot');
unlink($cliDrift);
$create = $provider->perform('create', $operation, $createInput);
wprism_check_same('present', $create['presence'] ?? null, 'contained preview is created through the real provider client');
wprism_check_same('wprism-mup-contained-preview', $create['resource_id'] ?? null, 'contained preview has a topology-specific resource identity');
$identity = [
    'expected_environment_identity' => $create['environment_identity'],
    'expected_lease_generation' => $create['lease_generation'],
    'expected_lease_id' => $create['lease_id'],
    'expected_ownership_receipt_sha256' => $create['ownership_receipt_sha256'],
    'expected_resource_id' => $create['resource_id'],
];
$fence = $provider->perform('mutation-acquire', $operation, $identity + ['mutation_owner' => 'contained-rehearsal-owner-0001']);
$fenced = $identity + [
    'expected_mutation_generation' => $fence['mutation_generation'],
    'expected_mutation_id' => $fence['mutation_id'],
    'expected_mutation_owner' => $fence['mutation_owner'],
    'expected_mutation_receipt_sha256' => $fence['mutation_receipt_sha256'],
];
$containmentInput = $fenced + ['profile' => 'agency-rehearsal-v1'];
$proof = $provider->perform('containment-verify', $operation, $containmentInput);
foreach ([
    'credential_isolation', 'http_egress_default_denied', 'mail_default_denied',
    'payment_default_denied', 'queue_default_denied', 'webhook_default_denied',
] as $control) {
    wprism_check_same(true, $proof[$control] ?? null, "contained preview proves $control through the protocol");
}
wprism_check_same('agency-rehearsal-v1', $proof['profile'] ?? null, 'contained proof names the closed rehearsal profile');
$repeat = $provider->perform('containment-verify', $operation, $containmentInput);
wprism_check_same($proof, $repeat, 'exact containment retry re-probes and returns the same receipt');

$state = json_decode((string) file_get_contents($stateRoot . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
$record = $state['containments'][$create['resource_id']] ?? null;
wprism_check(is_array($record), 'atomic provider state retains the containment receipt preimage');
wprism_check_same($operation, $record['preimage']['operation_id'] ?? null, 'containment receipt binds the exact operation');
wprism_check_same($create['lease_id'], $record['preimage']['lease_id'] ?? null, 'containment receipt binds the exact lease');
wprism_check_same($fence['mutation_id'], $record['preimage']['mutation_id'] ?? null, 'containment receipt binds the held mutation fence');
wprism_check_same(
    $proof['containment_receipt_sha256'] ?? null,
    hash('sha256', json_encode($record['preimage'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
    'containment receipt is sha256 over its canonical persisted preimage'
);
wprism_check_same(0600, fileperms($stateRoot . '/state.json') & 0777, 'provider receipt state is mode 0600');
$preimageBytes = json_encode($record['preimage'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
wprism_check(!str_contains($preimageBytes, (string) $state['resources'][$create['resource_id']]['contained_runtime']['database_password']), 'public containment preimage contains no lease database secret');

$request = [
    'action' => 'containment-verify', 'environment' => 'mup2',
    'format' => 'wprism-branch-environment-provider-request/v1',
    'input' => $containmentInput, 'operation_id' => $operation,
];
$beforeState = hash_file('sha256', $stateRoot . '/state.json');
$process = proc_open(
    [PHP_BINARY, $providerScript, '--print-plan', $configPath],
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $planPipes,
    null,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($process)) throw new RuntimeException('could not run containment dry-run');
fwrite($planPipes[0], json_encode($request, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
fclose($planPipes[0]);
$planBytes = (string) stream_get_contents($planPipes[1]);
$planError = (string) stream_get_contents($planPipes[2]);
fclose($planPipes[1]);
fclose($planPipes[2]);
wprism_check_same(0, proc_close($process), 'contained verification has an executable read-only dry-run plan: ' . trim($planError));
$plan = json_decode($planBytes, true, 512, JSON_THROW_ON_ERROR);
wprism_check_same(false, $plan['executed'] ?? null, 'contained verification dry run never claims execution');
wprism_check_same($beforeState, hash_file('sha256', $stateRoot . '/state.json'), 'contained verification dry run writes no provider state');

$bad = $containmentInput;
$bad['expected_mutation_receipt_sha256'] = str_repeat('0', 64);
$commandsBefore = count(file($physicalLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
try {
    $provider->perform('containment-verify', $operation, $bad);
    wprism_check(false, 'a foreign fence receipt refuses containment');
} catch (Throwable) {
    wprism_check(true, 'a foreign fence receipt refuses containment');
}
$commandsAfter = count(file($physicalLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
wprism_check_same($commandsBefore, $commandsAfter, 'foreign fence evidence refuses before any Docker probe');

touch($drift);
try {
    $provider->perform('containment-verify', $operation, $containmentInput);
    wprism_check(false, 'an added shared-network attachment refuses exact receipt retry');
} catch (Throwable) {
    wprism_check(true, 'an added shared-network attachment refuses exact receipt retry');
}
unlink($drift);
$afterDrift = json_decode((string) file_get_contents($stateRoot . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
wprism_check_same($proof['containment_receipt_sha256'], $afterDrift['containments'][$create['resource_id']]['receipt_sha256'] ?? null, 'topology drift cannot mint a replacement containment receipt');

$destroyInput = $fenced + ['compare_and_reap' => true];
$destroy = $provider->perform('destroy', $operation, $destroyInput);
wprism_check_same('destroyed', $destroy['disposition'] ?? null, 'contained preview is destroyed with its network and volumes');
$terminal = json_decode((string) file_get_contents($stateRoot . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
wprism_check(!isset($terminal['containments'][$create['resource_id']]), 'reap removes the active containment record');
wprism_check(!isset($terminal['resources'][$create['resource_id']]['contained_runtime']), 'reap removes lease database credentials from durable resource state');
$logBeforeReplay = hash_file('sha256', $physicalLog);
wprism_check_same($destroy, $provider->perform('destroy', $operation, $destroyInput), 'exact reap retry returns the terminal receipt');
wprism_check_same($logBeforeReplay, hash_file('sha256', $physicalLog), 'terminal reap retry executes no Docker command');

wprism_check_summary('contained reference provider');
