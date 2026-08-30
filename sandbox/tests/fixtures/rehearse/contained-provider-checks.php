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
$failAfterDown = $scratch . '/fail-after-down';
$failAbsenceProbe = $scratch . '/fail-absence-probe';
$configPath = $scratch . '/provider.json';
$policyPath = $scratch . '/sanitization-policy.json';
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
copy(__DIR__ . '/contained-sanitization-policy.json', $policyPath);
chmod($policyPath, 0600);
$config['contained_preview']['sanitization_policy'] = $policyPath;
$config['contained_preview']['sanitization_policy_sha256'] = hash_file('sha256', $policyPath);
file_put_contents($configPath, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");

putenv('PATH=' . $bin . PATH_SEPARATOR . (string) getenv('PATH'));
putenv('WPRISM_CONTAINED_FAKE_STATE=' . $physicalState);
putenv('WPRISM_CONTAINED_FAKE_LOG=' . $physicalLog);
putenv('WPRISM_CONTAINED_FAKE_DRIFT=' . $drift);
putenv('WPRISM_CONTAINED_FAKE_CLI_DRIFT=' . $cliDrift);
putenv('WPRISM_CONTAINED_FAKE_FAIL_AFTER_DOWN=' . $failAfterDown);
putenv('WPRISM_CONTAINED_FAKE_FAIL_ABSENCE_PROBE=' . $failAbsenceProbe);

$providerFor = static function (string $environment, ?string $path = null) use ($configPath, $providerScript): CommandEnvironmentProvider {
    return CommandEnvironmentProvider::fromEnvironment($environment, [
        '_machine_local' => true,
        'environment_provider' => ['command' => [PHP_BINARY, $providerScript, $path ?? $configPath], 'timeout_seconds' => 30],
    ]);
};
foreach (['cli_image', 'database_image', 'proxy_image', 'wordpress_image'] as $imageKey) {
    $mutableImageConfig = $config;
    $mutableImageConfig['contained_preview'][$imageKey] = 'example.invalid/runtime:mutable';
    $mutableImagePath = $scratch . '/mutable-' . $imageKey . '-provider.json';
    file_put_contents(
        $mutableImagePath,
        json_encode($mutableImageConfig, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)
    );
    try {
        $providerFor('mup2', $mutableImagePath)->capabilities('contained-mutable-image-' . $imageKey);
        wprism_check(false, "contained mode refuses mutable $imageKey tags");
    } catch (Throwable) {
        wprism_check(true, "contained mode refuses mutable $imageKey tags");
    }
}
$badModeRoot = $scratch . '/bad-mode-state';
mkdir($badModeRoot, 0755);
$badModeConfig = $config;
$badModeConfig['state_root'] = $badModeRoot;
$badModePath = $scratch . '/bad-mode-provider.json';
file_put_contents($badModePath, json_encode($badModeConfig, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
try {
    $providerFor('mup2', $badModePath)->capabilities('contained-invalid-root-operation-0001');
    wprism_check(false, 'contained mode refuses a state root broader than 0700');
} catch (Throwable) {
    wprism_check(true, 'contained mode refuses a state root broader than 0700');
}
$stateLink = $scratch . '/state-link';
symlink($stateRoot, $stateLink);
$linkConfig = $config;
$linkConfig['state_root'] = $stateLink;
$linkConfigPath = $scratch . '/linked-state-provider.json';
file_put_contents($linkConfigPath, json_encode($linkConfig, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
try {
    $providerFor('mup2', $linkConfigPath)->capabilities('contained-invalid-root-operation-0002');
    wprism_check(false, 'contained mode refuses a symlink state root');
} catch (Throwable) {
    wprism_check(true, 'contained mode refuses a symlink state root');
}
$emptyPolicy = json_decode((string) file_get_contents($policyPath), true, 512, JSON_THROW_ON_ERROR);
$emptyPolicy['database']['options'] = [];
$emptyPolicy['media'] = [];
$emptyPolicyPath = $scratch . '/empty-sanitization-policy.json';
file_put_contents($emptyPolicyPath, json_encode($emptyPolicy, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
chmod($emptyPolicyPath, 0600);
$emptyPolicyRoot = $scratch . '/empty-policy-state';
mkdir($emptyPolicyRoot, 0700);
$emptyPolicyConfig = $config;
$emptyPolicyConfig['state_root'] = $emptyPolicyRoot;
$emptyPolicyConfig['contained_preview']['sanitization_policy'] = $emptyPolicyPath;
$emptyPolicyConfig['contained_preview']['sanitization_policy_sha256'] = hash_file('sha256', $emptyPolicyPath);
$emptyPolicyConfigPath = $scratch . '/empty-policy-provider.json';
file_put_contents($emptyPolicyConfigPath, json_encode($emptyPolicyConfig, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
$emptyCapabilities = $providerFor('mup2', $emptyPolicyConfigPath)->capabilities('contained-empty-policy-operation-0001')->toArray()['capabilities'];
wprism_check(in_array('environment.containment.verify', $emptyCapabilities, true), 'an exhaustive reviewed inventory may legitimately contain zero option and media locators');

$provider = $providerFor('mup2');
$sourceProvider = $providerFor('mup1');
$operation = 'contained-preview-operation-0000000000000001';
$capabilities = $provider->capabilities($operation)->toArray()['capabilities'];
wprism_check(in_array('environment.containment.verify', $capabilities, true), 'contained-preview mode advertises environment.containment.verify');
wprism_check(!in_array('environment.attach', $capabilities, true), 'contained-preview mode refuses retroactive attach authority');
wprism_check(!in_array('environment.detach', $capabilities, true), 'contained-preview mode pairs create only with exact destroy');

$createInput = ['mode' => 'create'];
$sentinel = $scratch . '/runtime-authority-sentinel';
file_put_contents($sentinel, "authority\n");
chmod($sentinel, 0640);
$runtimeLink = $scratch . '/runtime-agent/authority-link';
symlink($sentinel, $runtimeLink);
try {
    $provider->perform('create', $operation, $createInput);
    wprism_check(false, 'a symlink in staged runtime authority refuses before preview boot');
} catch (Throwable) {
    wprism_check(true, 'a symlink in staged runtime authority refuses before preview boot');
}
clearstatcache(true, $sentinel);
wprism_check_same(0640, fileperms($sentinel) & 0777, 'runtime symlink refusal never chmods its external referent');
$failedPhysical = json_decode((string) file_get_contents($physicalState), true, 512, JSON_THROW_ON_ERROR);
wprism_check_same(false, $failedPhysical['database'] ?? null, 'runtime symlink refuses before database boot');
unlink($runtimeLink);
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
$sourceProvider->capabilities($operation);
$sourceIdentity = $sourceProvider->perform('inspect', $operation, ['role' => 'source']);
$sourceIdentityInput = [
    'expected_environment_identity' => $sourceIdentity['environment_identity'],
    'expected_lease_generation' => $sourceIdentity['lease_generation'],
    'expected_lease_id' => $sourceIdentity['lease_id'],
    'expected_ownership_receipt_sha256' => $sourceIdentity['ownership_receipt_sha256'],
    'expected_resource_id' => $sourceIdentity['resource_id'],
];
$prepared = $sourceProvider->perform('snapshot-prepare', $operation, $sourceIdentityInput + [
    'snapshot_session_id' => 'contained-preview-snapshot-session-0001',
]);
$sessionInput = [
    'expected_snapshot_session_id' => $prepared['snapshot_session_id'],
    'expected_source_identity' => $prepared['source_identity'],
    'expected_source_lease_generation' => $prepared['lease_generation'],
    'expected_source_lease_id' => $prepared['lease_id'],
    'expected_source_lease_receipt_sha256' => $prepared['lease_receipt_sha256'],
];
$snapshot = $sourceProvider->perform('snapshot-create', $operation, $sessionInput + [
    'expected_semantic_snapshot_sha256' => hash('sha256', 'contained-offline-semantic-snapshot'),
    'production_commit' => str_repeat('a', 40),
]);
$snapshot = $sourceProvider->perform('snapshot-read', $operation, $sessionInput + [
    'expected_snapshot_set_id' => $snapshot['snapshot_set_id'],
    'expected_snapshot_set_receipt_sha256' => $snapshot['snapshot_set_receipt_sha256'],
]);
$snapshotState = json_decode((string) file_get_contents($stateRoot . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
$snapshotKey = 'mup1|' . $operation;
$preparedPath = (string) $snapshotState['sessions'][$snapshotKey]['path'];
$databasePath = (string) $snapshotState['snapshots'][$snapshotKey]['database_path'];
$mediaPath = (string) $snapshotState['snapshots'][$snapshotKey]['media_path'];
$snapshotDatabase = (string) file_get_contents($databasePath);
wprism_check_same(0700, fileperms($preparedPath) & 0777, 'contained prepared snapshot directory is mode 0700');
wprism_check_same(0600, fileperms($preparedPath . '/database.sql') & 0777, 'contained prepared database is mode 0600');
wprism_check_same(0700, fileperms(dirname($databasePath)) & 0777, 'contained immutable snapshot directory is mode 0700');
wprism_check_same(0600, fileperms($databasePath) & 0777, 'contained immutable database is mode 0600');
wprism_check_same(0700, fileperms($mediaPath) & 0777, 'contained immutable media directory is mode 0700');
wprism_check(!str_contains($snapshotDatabase, 'source-payment-secret-0001'), 'source payment credential is absent before durable snapshot publication');
wprism_check(!str_contains($snapshotDatabase, 'source-mail-secret-0001'), 'source mail credential is absent before durable snapshot publication');
wprism_check(str_contains($snapshotDatabase, 'sandbox-payment-disabled'), 'snapshot contains the reviewed sandbox payment rebind');
wprism_check(str_contains($snapshotDatabase, 'sandbox-mail-disabled'), 'snapshot contains the reviewed sandbox mail rebind');
wprism_check(!str_contains($snapshotDatabase, 'source-password-hash-0001'), 'source WordPress password hash is absent before durable publication');
wprism_check(!str_contains($snapshotDatabase, 'source-activation-key-0001'), 'source WordPress activation key is absent before durable publication');
wprism_check(!str_contains($snapshotDatabase, 'source-session-token-0001'), 'source WordPress sessions are absent before durable publication');
wprism_check(!str_contains($snapshotDatabase, 'source-application-password-0001'), 'source WordPress application passwords are absent before durable publication');
wprism_check(str_contains($snapshotDatabase, '!wprism-sandbox-disabled!'), 'all WordPress passwords are rebound to a non-authenticating sandbox value');
wprism_check(str_contains($snapshotDatabase, 'ordinary_profile'), 'ordinary usermeta survives auth sanitization');
wprism_check(!file_exists($mediaPath . '/private/source-media-secret.txt'), 'reviewed media credential is absent from the durable snapshot');
wprism_check(is_file($mediaPath . '/ordinary.txt'), 'ordinary media survives exact-locator sanitization');

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
$restoreInput = $fenced + [
    'database_sha256' => $snapshot['database_sha256'],
    'media_sha256' => $snapshot['media_sha256'],
    'snapshot_set_id' => $snapshot['snapshot_set_id'],
];
$nestedDisposalLink = $preparedPath . '/injected-authority-link';
$topLevelDisposalLink = $stateRoot . '/create-media-' . hash('sha256', $snapshotKey);
symlink($sentinel, $nestedDisposalLink);
symlink($sentinel, $topLevelDisposalLink);
$restored = $provider->perform('snapshot-restore', $operation, $restoreInput);
wprism_check_same($snapshot['snapshot_set_id'], $restored['snapshot_set_id'] ?? null, 'contained restore accepts only its proved sanitized snapshot');
$afterRestore = json_decode((string) file_get_contents($stateRoot . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
wprism_check(!isset($afterRestore['sessions'][$snapshotKey]), 'successful restore disposes the exact prepared session state');
wprism_check(!isset($afterRestore['snapshots'][$snapshotKey]), 'successful restore disposes the exact immutable snapshot state');
wprism_check(!file_exists($preparedPath) && !file_exists(dirname($databasePath)), 'successful restore logically deletes exact provider snapshot paths');
clearstatcache(true, $sentinel);
wprism_check_same(0640, fileperms($sentinel) & 0777, 'snapshot cleanup never chmods top-level or nested symlink referents');
wprism_check(!is_link($nestedDisposalLink) && !is_link($topLevelDisposalLink), 'snapshot cleanup unlinks injected top-level and nested links themselves');
$restoreRecord = $afterRestore['restores'][$create['resource_id'] . '|' . $operation] ?? null;
wprism_check_same('disposed-success', $restoreRecord['state'] ?? null, 'successful restore journals application before logical disposal');
$postRestoreProbeCount = count(file($physicalLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
wprism_check_same(
    $proof,
    $provider->perform('containment-verify', $operation, $containmentInput),
    'exact containment retry after snapshot disposal returns the persisted receipt'
);
wprism_check(
    count(file($physicalLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) > $postRestoreProbeCount,
    'exact containment retry after snapshot disposal re-probes current topology'
);
$physicalAfterRestore = json_decode((string) file_get_contents($physicalState), true, 512, JSON_THROW_ON_ERROR);
$restoredDump = (string) ($physicalAfterRestore['restored_dump'] ?? '');
wprism_check(!str_contains($restoredDump, 'source-payment-secret-0001'), 'preview database cannot read the source payment credential');
wprism_check(!str_contains($restoredDump, 'source-mail-secret-0001'), 'preview database cannot read the source mail credential');
wprism_check(!str_contains($restoredDump, 'independent-target-secret-0001'), 'preview database cannot read an independent-target credential');
wprism_check(str_contains($restoredDump, 'sandbox-payment-disabled'), 'preview database reads the sandbox payment rebind');
wprism_check(str_contains($restoredDump, '!wprism-sandbox-disabled!'), 'preview database reads only the disabled WordPress password value');
wprism_check(!str_contains($restoredDump, 'source-session-token-0001'), 'preview database cannot read source WordPress sessions');
wprism_check(!str_contains($restoredDump, 'source-application-password-0001'), 'preview database cannot read source application passwords');
$mutationCommandsBeforeRetry = array_values(array_filter(
    file($physicalLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    static function (string $line): bool {
        $argv = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $joined = is_array($argv) ? implode(' ', array_map('strval', $argv)) : '';
        return str_contains($joined, 'exec mariadb -uroot') || str_starts_with($joined, 'cp ');
    }
));
wprism_check_same($restored, $provider->perform('snapshot-restore', $operation, $restoreInput), 'exact restore retry returns the journaled result after disposal');
$mutationCommandsAfterRetry = array_values(array_filter(
    file($physicalLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
    static function (string $line): bool {
        $argv = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        $joined = is_array($argv) ? implode(' ', array_map('strval', $argv)) : '';
        return str_contains($joined, 'exec mariadb -uroot') || str_starts_with($joined, 'cp ');
    }
));
wprism_check_same($mutationCommandsBeforeRetry, $mutationCommandsAfterRetry, 'exact restore retry never reapplies database or media bytes');

$state = json_decode((string) file_get_contents($stateRoot . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
$record = $state['containments'][$create['resource_id']] ?? null;
wprism_check(is_array($record), 'atomic provider state retains the containment receipt preimage');
wprism_check_same($operation, $record['preimage']['operation_id'] ?? null, 'containment receipt binds the exact operation');
wprism_check_same($create['lease_id'], $record['preimage']['lease_id'] ?? null, 'containment receipt binds the exact lease');
wprism_check_same($fence['mutation_id'], $record['preimage']['mutation_id'] ?? null, 'containment receipt binds the held mutation fence');
wprism_check_same('sanitized-snapshot', $record['preimage']['snapshot_sanitization']['admission'] ?? null, 'containment receipt proves sanitized snapshot admission');
wprism_check_same($snapshot['snapshot_set_id'], $record['preimage']['snapshot_sanitization']['snapshot_set_id'] ?? null, 'containment receipt binds the exact sanitized snapshot set');
wprism_check_same(hash_file('sha256', $policyPath), $record['preimage']['snapshot_sanitization']['policy_sha256'] ?? null, 'containment receipt binds the pinned reviewed policy');
wprism_check_same(
    $proof['containment_receipt_sha256'] ?? null,
    hash('sha256', json_encode($record['preimage'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
    'containment receipt is sha256 over its canonical persisted preimage'
);
wprism_check_same(0600, fileperms($stateRoot . '/state.json') & 0777, 'provider receipt state is mode 0600');
$preimageBytes = json_encode($record['preimage'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
wprism_check(!str_contains($preimageBytes, (string) $state['resources'][$create['resource_id']]['contained_runtime']['database_password']), 'public containment preimage contains no lease database secret');
$writeProviderState = static function (array $value) use ($stateRoot): void {
    file_put_contents(
        $stateRoot . '/state.json',
        json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
        LOCK_EX
    );
    chmod($stateRoot . '/state.json', 0600);
};
$lastProviderError = static function () use ($stateRoot): string {
    $lines = file($stateRoot . '/provider-errors.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    return $lines === [] ? '' : (string) $lines[count($lines) - 1];
};
$commandsBeforeReplayRefusals = count(file($physicalLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
$changedReplay = $containmentInput + ['topology_only' => true];
try {
    $provider->perform('containment-verify', $operation, $changedReplay);
    wprism_check(false, 'post-restore containment replay refuses changed input');
} catch (Throwable) {
    wprism_check(str_contains($lastProviderError(), 'retry differs from its persisted request'), 'post-restore containment replay refuses changed input before probing');
}
try {
    $provider->perform('containment-verify', 'contained-preview-operation-foreign-replay-0001', $containmentInput);
    wprism_check(false, 'post-restore containment replay refuses another operation');
} catch (Throwable) {
    wprism_check(str_contains($lastProviderError(), 'retry differs from its persisted request'), 'post-restore containment replay refuses another operation before probing');
}

$restoreKey = $create['resource_id'] . '|' . $operation;
$tampered = $state;
unset($tampered['restores'][$restoreKey]);
$writeProviderState($tampered);
try {
    $provider->perform('containment-verify', $operation, $containmentInput);
    wprism_check(false, 'post-restore containment replay refuses an absent restore receipt');
} catch (Throwable) {
    wprism_check(str_contains($lastProviderError(), 'no matching restore receipt'), 'post-restore containment replay requires a matching restore receipt before probing');
}
$writeProviderState($state);

$tampered = $state;
$tampered['restores'][$restoreKey]['state'] = 'restored';
$writeProviderState($tampered);
try {
    $provider->perform('containment-verify', $operation, $containmentInput);
    wprism_check(false, 'post-restore containment replay refuses a nonterminal restore receipt');
} catch (Throwable) {
    wprism_check(str_contains($lastProviderError(), 'exact successful terminal restore'), 'post-restore containment replay refuses a nonterminal restore before probing');
}
$writeProviderState($state);

$tampered = $state;
$tampered['restores'][$restoreKey]['input_sha256'] = str_repeat('0', 64);
$writeProviderState($tampered);
try {
    $provider->perform('containment-verify', $operation, $containmentInput);
    wprism_check(false, 'post-restore containment replay refuses a foreign restore request receipt');
} catch (Throwable) {
    wprism_check(str_contains($lastProviderError(), 'restore request differs'), 'post-restore containment replay binds the disposed restore to the admitted snapshot');
}
$writeProviderState($state);

$tampered = $state;
$tampered['containments'][$create['resource_id']]['receipt_sha256'] = str_repeat('0', 64);
$writeProviderState($tampered);
try {
    $provider->perform('containment-verify', $operation, $containmentInput);
    wprism_check(false, 'post-restore containment replay refuses corrupted persisted proof');
} catch (Throwable) {
    wprism_check(str_contains($lastProviderError(), 'verification evidence is malformed'), 'post-restore containment replay validates its persisted canonical preimage and receipt');
}
$writeProviderState($state);
wprism_check_same(
    $commandsBeforeReplayRefusals,
    count(file($physicalLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []),
    'changed, foreign, partial and corrupted post-restore replays refuse before Docker probes'
);
$publicEvidence = (string) file_get_contents($stateRoot . '/state.json')
    . (string) file_get_contents($stateRoot . '/actions.ndjson')
    . json_encode([$prepared, $snapshot, $proof, $restored], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
foreach ([
    'source-payment-secret-0001', 'source-mail-secret-0001', "source-media-secret-0001\n",
    'source-password-hash-0001', 'source-activation-key-0001', 'source-session-token-0001',
    'source-application-password-0001',
] as $secret) {
    wprism_check(!str_contains($publicEvidence, $secret), 'provider evidence contains no source credential plaintext');
    wprism_check(!str_contains($publicEvidence, hash('sha256', $secret)), 'provider evidence contains no per-credential digest oracle');
}

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
    $driftError = $lastProviderError();
    wprism_check(
        (str_contains($driftError, 'extra network attachment') || str_contains($driftError, 'live topology drifted'))
            && !str_contains($driftError, 'sanitized immutable snapshot'),
        'post-restore topology drift refuses on live topology rather than disposed snapshot absence'
    );
}
unlink($drift);
$afterDrift = json_decode((string) file_get_contents($stateRoot . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
wprism_check_same($proof['containment_receipt_sha256'], $afterDrift['containments'][$create['resource_id']]['receipt_sha256'] ?? null, 'topology drift cannot mint a replacement containment receipt');
$cronCanary = $scratch . '/cron-canary-ran';
$cronProcess = proc_open(
    [PHP_BINARY, '-r', 'define("DOING_CRON",true);require $argv[1];file_put_contents($argv[2],"ran");', $root . '/sandbox/containment/block-cron.php', $cronCanary],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $cronPipes,
    null,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($cronProcess)) throw new RuntimeException('could not run staged cron guard canary');
fclose($cronPipes[1]);
fclose($cronPipes[2]);
wprism_check_same(0, proc_close($cronProcess), 'staged cron guard terminates a direct DOING_CRON bootstrap cleanly');
wprism_check(!file_exists($cronCanary), 'staged MU cron guard prevents the canary hook from running');
$wpCliCronCanary = $scratch . '/wp-cli-cron-canary-ran';
$wpCliCronProcess = proc_open(
    [
        PHP_BINARY,
        '-r',
        'define("WP_CLI",true);$_SERVER["argv"]=["wp","--path=/var/www/html","cron","event","run","--all"];require $argv[1];file_put_contents($argv[2],"ran");',
        $root . '/sandbox/containment/block-cron.php',
        $wpCliCronCanary,
    ],
    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $wpCliCronPipes,
    null,
    null,
    ['bypass_shell' => true]
);
if (!is_resource($wpCliCronProcess)) throw new RuntimeException('could not run staged WP-CLI cron guard canary');
$wpCliCronOutput = (string) stream_get_contents($wpCliCronPipes[1]);
$wpCliCronError = (string) stream_get_contents($wpCliCronPipes[2]);
fclose($wpCliCronPipes[1]);
fclose($wpCliCronPipes[2]);
wprism_check_same(75, proc_close($wpCliCronProcess), 'staged cron guard makes direct WP-CLI cron execution fail');
wprism_check_same('', $wpCliCronOutput, 'direct WP-CLI cron denial emits no misleading success output');
wprism_check_same(
    "Error: contained preview refuses WP-CLI cron event execution.\n",
    $wpCliCronError,
    'direct WP-CLI cron denial emits one operator-readable refusal'
);
wprism_check(!file_exists($wpCliCronCanary), 'direct WP-CLI cron denial prevents the canary hook from running');
$nginx = (string) file_get_contents($root . '/sandbox/containment/nginx.conf');
wprism_check(preg_match('~location = /wp-cron\\.php\\s*\\{\\s*return 404;\\s*\\}~', $nginx) === 1, 'proxy configuration blocks direct wp-cron.php before PHP');

$destroyInput = $fenced + ['compare_and_reap' => true];
$destroy = $provider->perform('destroy', $operation, $destroyInput);
wprism_check_same('destroyed', $destroy['disposition'] ?? null, 'contained preview is destroyed with its network and volumes');
$terminal = json_decode((string) file_get_contents($stateRoot . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
wprism_check(!isset($terminal['containments'][$create['resource_id']]), 'reap removes the active containment record');
wprism_check(!isset($terminal['resources'][$create['resource_id']]['contained_runtime']), 'reap removes lease database credentials from durable resource state');
$logBeforeReplay = hash_file('sha256', $physicalLog);
wprism_check_same($destroy, $provider->perform('destroy', $operation, $destroyInput), 'exact reap retry returns the terminal receipt');
wprism_check_same($logBeforeReplay, hash_file('sha256', $physicalLog), 'terminal reap retry executes no Docker command');

$reapOperation = 'contained-preview-operation-0000000000000002';
$sourceIdentity2 = $sourceProvider->perform('inspect', $reapOperation, ['role' => 'source']);
$sourceInput2 = [
    'expected_environment_identity' => $sourceIdentity2['environment_identity'],
    'expected_lease_generation' => $sourceIdentity2['lease_generation'],
    'expected_lease_id' => $sourceIdentity2['lease_id'],
    'expected_ownership_receipt_sha256' => $sourceIdentity2['ownership_receipt_sha256'],
    'expected_resource_id' => $sourceIdentity2['resource_id'],
];
$prepared2 = $sourceProvider->perform('snapshot-prepare', $reapOperation, $sourceInput2 + [
    'snapshot_session_id' => 'contained-preview-snapshot-session-0002',
]);
$sessionInput2 = [
    'expected_snapshot_session_id' => $prepared2['snapshot_session_id'],
    'expected_source_identity' => $prepared2['source_identity'],
    'expected_source_lease_generation' => $prepared2['lease_generation'],
    'expected_source_lease_id' => $prepared2['lease_id'],
    'expected_source_lease_receipt_sha256' => $prepared2['lease_receipt_sha256'],
];
$snapshot2 = $sourceProvider->perform('snapshot-create', $reapOperation, $sessionInput2 + [
    'expected_semantic_snapshot_sha256' => hash('sha256', 'contained-offline-reap-snapshot'),
]);
$snapshot2 = $sourceProvider->perform('snapshot-read', $reapOperation, $sessionInput2 + [
    'expected_snapshot_set_id' => $snapshot2['snapshot_set_id'],
    'expected_snapshot_set_receipt_sha256' => $snapshot2['snapshot_set_receipt_sha256'],
]);
$create2 = $provider->perform('create', $reapOperation, ['mode' => 'create']);
$identity2 = [
    'expected_environment_identity' => $create2['environment_identity'],
    'expected_lease_generation' => $create2['lease_generation'],
    'expected_lease_id' => $create2['lease_id'],
    'expected_ownership_receipt_sha256' => $create2['ownership_receipt_sha256'],
    'expected_resource_id' => $create2['resource_id'],
];
$fence2 = $provider->perform('mutation-acquire', $reapOperation, $identity2 + ['mutation_owner' => 'contained-reap-owner-0002']);
$fenced2 = $identity2 + [
    'expected_mutation_generation' => $fence2['mutation_generation'],
    'expected_mutation_id' => $fence2['mutation_id'],
    'expected_mutation_owner' => $fence2['mutation_owner'],
    'expected_mutation_receipt_sha256' => $fence2['mutation_receipt_sha256'],
];
$provider->perform('containment-verify', $reapOperation, $fenced2 + ['profile' => 'agency-rehearsal-v1']);
$beforeUnrestoredReap = json_decode((string) file_get_contents($stateRoot . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
$key2 = 'mup1|' . $reapOperation;
$preparedPath2 = (string) $beforeUnrestoredReap['sessions'][$key2]['path'];
$snapshotPath2 = dirname((string) $beforeUnrestoredReap['snapshots'][$key2]['database_path']);
$runtimeRoot2 = (string) $beforeUnrestoredReap['resources'][$create2['resource_id']]['contained_runtime']['runtime_root'];
$databasePassword2 = (string) $beforeUnrestoredReap['resources'][$create2['resource_id']]['contained_runtime']['database_password'];
$destroyInput2 = $fenced2 + ['compare_and_reap' => true];
touch($failAfterDown);
try {
    $provider->perform('destroy', $reapOperation, $destroyInput2);
    wprism_check(false, 'an injected provider death after physical teardown interrupts reap');
} catch (Throwable) {
    wprism_check(true, 'an injected provider death after physical teardown interrupts reap before terminal state');
}
$interruptedReap = json_decode((string) file_get_contents($stateRoot . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
$interruptedPhysical = json_decode((string) file_get_contents($physicalState), true, 512, JSON_THROW_ON_ERROR);
wprism_check_same('reaping', $interruptedReap['resources'][$create2['resource_id']]['state'] ?? null, 'post-down interruption retains the exact durable reap intent');
wprism_check(
    ($interruptedPhysical['database'] ?? null) === false
        && ($interruptedPhysical['wordpress'] ?? null) === false
        && ($interruptedPhysical['proxy'] ?? null) === false,
    'post-down interruption leaves the contained Docker topology physically absent'
);
$reapProbeCommands = count(file($physicalLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
try {
    $provider->perform('destroy', $reapOperation, $destroyInput2 + ['unexpected' => true]);
    wprism_check(false, 'interrupted reap refuses changed retry input');
} catch (Throwable) {
    wprism_check(true, 'interrupted reap refuses changed retry input');
}
try {
    $provider->perform('destroy', 'contained-preview-operation-foreign-reap-0001', $destroyInput2);
    wprism_check(false, 'interrupted reap refuses another operation');
} catch (Throwable) {
    wprism_check(true, 'interrupted reap refuses another operation');
}
$foreignFence2 = $destroyInput2;
$foreignFence2['expected_mutation_receipt_sha256'] = str_repeat('0', 64);
try {
    $provider->perform('destroy', $reapOperation, $foreignFence2);
    wprism_check(false, 'interrupted reap refuses a foreign mutation fence');
} catch (Throwable) {
    wprism_check(true, 'interrupted reap refuses a foreign mutation fence');
}
wprism_check_same(
    $reapProbeCommands,
    count(file($physicalLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []),
    'changed operation, input and fence refuse interrupted reap before Docker absence probes'
);
$interruptedStateSha = hash_file('sha256', $stateRoot . '/state.json');
touch($failAbsenceProbe);
try {
    $provider->perform('destroy', $reapOperation, $destroyInput2);
    wprism_check(false, 'interrupted reap refuses when Docker cannot prove physical absence');
} catch (Throwable) {
    wprism_check(true, 'interrupted reap does not interpret a failed Docker query as physical absence');
}
unlink($failAbsenceProbe);
wprism_check_same($interruptedStateSha, hash_file('sha256', $stateRoot . '/state.json'), 'an ambiguous absence probe cannot advance durable reap state');
$partialPhysical = $interruptedPhysical;
$partialPhysical['database'] = true;
file_put_contents($physicalState, json_encode($partialPhysical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
try {
    $provider->perform('destroy', $reapOperation, $destroyInput2);
    wprism_check(false, 'interrupted reap refuses an ambiguous partial live topology');
} catch (Throwable) {
    wprism_check(true, 'interrupted reap never treats an ambiguous partial live topology as absent');
}
file_put_contents($physicalState, json_encode($interruptedPhysical, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n");
$resumedDestroy2 = $provider->perform('destroy', $reapOperation, $destroyInput2);
wprism_check_same('destroyed', $resumedDestroy2['disposition'] ?? null, 'exact reap retry finalizes an already absent contained project');
$afterUnrestoredReap = json_decode((string) file_get_contents($stateRoot . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
wprism_check(!isset($afterUnrestoredReap['sessions'][$key2], $afterUnrestoredReap['snapshots'][$key2]), 'reap disposes its exact unrestored snapshot/session state');
wprism_check(!file_exists($preparedPath2) && !file_exists($snapshotPath2), 'reap logically deletes its exact unrestored snapshot/session bytes');
wprism_check(!isset($afterUnrestoredReap['resources'][$create2['resource_id']]['contained_runtime']), 'resumed reap removes plaintext lease credentials from provider state');
wprism_check(!is_dir($runtimeRoot2), 'resumed reap removes the exact lease runtime tree');
$driverAfterReap2 = (string) file_get_contents($stateRoot . '/contained-preview.env');
wprism_check(!str_contains($driverAfterReap2, $databasePassword2), 'resumed reap replaces the plaintext driver credential file');
wprism_check_same($resumedDestroy2, $provider->perform('destroy', $reapOperation, $destroyInput2), 'resumed reap publishes an idempotent terminal receipt');

$abortOperation = 'contained-preview-operation-0000000000000003';
$sourceIdentity3 = $sourceProvider->perform('inspect', $abortOperation, ['role' => 'source']);
$sourceInput3 = [
    'expected_environment_identity' => $sourceIdentity3['environment_identity'],
    'expected_lease_generation' => $sourceIdentity3['lease_generation'],
    'expected_lease_id' => $sourceIdentity3['lease_id'],
    'expected_ownership_receipt_sha256' => $sourceIdentity3['ownership_receipt_sha256'],
    'expected_resource_id' => $sourceIdentity3['resource_id'],
];
$prepared3 = $sourceProvider->perform('snapshot-prepare', $abortOperation, $sourceInput3 + [
    'snapshot_session_id' => 'contained-preview-snapshot-session-0003',
]);
$sessionInput3 = [
    'expected_snapshot_session_id' => $prepared3['snapshot_session_id'],
    'expected_source_identity' => $prepared3['source_identity'],
    'expected_source_lease_generation' => $prepared3['lease_generation'],
    'expected_source_lease_id' => $prepared3['lease_id'],
    'expected_source_lease_receipt_sha256' => $prepared3['lease_receipt_sha256'],
];
$snapshot3 = $sourceProvider->perform('snapshot-create', $abortOperation, $sessionInput3 + [
    'expected_semantic_snapshot_sha256' => hash('sha256', 'contained-offline-abort-snapshot'),
]);
$sourceProvider->perform('snapshot-read', $abortOperation, $sessionInput3 + [
    'expected_snapshot_set_id' => $snapshot3['snapshot_set_id'],
    'expected_snapshot_set_receipt_sha256' => $snapshot3['snapshot_set_receipt_sha256'],
]);
$beforeAbort = json_decode((string) file_get_contents($stateRoot . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
$key3 = 'mup1|' . $abortOperation;
$preparedPath3 = (string) $beforeAbort['sessions'][$key3]['path'];
$snapshotPath3 = dirname((string) $beforeAbort['snapshots'][$key3]['database_path']);
$aborted3 = $sourceProvider->perform('snapshot-abort', $abortOperation, $sessionInput3);
$afterAbort = json_decode((string) file_get_contents($stateRoot . '/state.json'), true, 512, JSON_THROW_ON_ERROR);
wprism_check(!isset($afterAbort['sessions'][$key3], $afterAbort['snapshots'][$key3], $afterAbort['source_inspections'][$key3]), 'snapshot-abort disposes its exact active session/set state');
wprism_check(!file_exists($preparedPath3) && !file_exists($snapshotPath3), 'snapshot-abort logically deletes its exact session/set bytes');
wprism_check_same($aborted3, $sourceProvider->perform('snapshot-abort', $abortOperation, $sessionInput3), 'snapshot-abort exact retry returns its retained nonsecret disposal receipt');

wprism_check_summary('contained reference provider');
