<?php
declare(strict_types=1);

// issue #3294: offline certification for target-owned maintenance exclusion and
// the WordPress-independent recovery adapter executor.

require dirname(__DIR__, 4) . '/recovery/rollback-control.php';

use WPrism\Recovery\RecoveryExecutor;
use WPrism\Recovery\RollbackControl;

$tmp = sys_get_temp_dir() . '/wprism-recovery-regress-' . bin2hex(random_bytes(8));
$keyId = 'recovery-test-key';
$keypair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($keypair);
$public = sodium_crypto_sign_publickey($keypair);

function recovery_fail(string $message): never {
    fwrite(STDERR, "FAIL: $message\n");
    exit(1);
}

function recovery_ok(bool $condition, string $message): void {
    if (!$condition) {
        recovery_fail($message);
    }
    echo "ok: $message\n";
}

function recovery_refuses(callable $operation, string $message): void {
    try {
        $operation();
    } catch (Throwable $e) {
        echo "ok: $message\n";
        return;
    }
    recovery_fail($message);
}

function recovery_remove_tree(string $path): void {
    if (is_link($path) || is_file($path)) {
        @unlink($path);
        return;
    }
    if (!is_dir($path)) {
        return;
    }
    foreach (scandir($path) ?: [] as $name) {
        if ($name !== '.' && $name !== '..') {
            recovery_remove_tree($path . '/' . $name);
        }
    }
    @rmdir($path);
}

function recovery_write(string $path, string $bytes, int $mode = 0600): void {
    if (file_put_contents($path, $bytes) !== strlen($bytes)) {
        recovery_fail("could not write $path");
    }
    chmod($path, $mode);
}

/** @return array<string,mixed> */
function recovery_submit_exclusion(string $root, array $payload, string $keyId, string $secret): array {
    $path = tempnam(sys_get_temp_dir(), 'wprism-exclusion-request-');
    if ($path === false) {
        recovery_fail('could not allocate exclusion request');
    }
    recovery_write($path, RollbackControl::canonical(RollbackControl::sign($payload, $keyId, $secret)) . "\n");
    try {
        return RecoveryExecutor::handleExclusionRequest($root, $path);
    } finally {
        @unlink($path);
    }
}

/** @return array<string,mixed> */
function recovery_receipt(array $status, string $tokenHash): array {
    $hash = static fn(string $value): string => hash('sha256', $value);
    return [
        'adapter_versions_sha256' => $hash('adapters'),
        'artifact_hash' => $hash('artifact'),
        'checkpoint_sha256' => $hash('checkpoint'),
        'claim_ttl_seconds' => 30,
        'code_release_metadata_sha256' => $hash('code-release'),
        'created_at' => '2026-01-01T00:00:00Z',
        'encryption_key_id' => 'age-key-test',
        'exclusion_token_sha256' => $tokenHash,
        'format' => RollbackControl::RECEIPT_FORMAT,
        'generation' => 1,
        'ledger_session_sha256' => $hash('ledger'),
        'lifecycle_receipts_sha256' => $hash('lifecycle'),
        'owner' => 'controller:test',
        'prior_code_descriptor_sha256' => $hash('code'),
        'prior_verifier_inputs_sha256' => $hash('verifiers'),
        'receipt_id' => str_repeat('a', 48),
        'resources_inventory_sha256' => $hash('resources'),
        'retention_until' => '2027-01-01T00:00:00Z',
        'runtime_fingerprints_sha256' => $hash('runtime'),
        'signing_key_id' => 'recovery-test-key',
        'target_id' => (string) $status['target_id'],
        'uploads_inventory_sha256' => $hash('uploads'),
    ];
}

/** @return array<string,mixed> */
function recovery_event(
    array $receipt,
    array $status,
    string $state,
    string $operationStatus,
    string $operationId,
    int $attempt,
    string $claimant,
    int $epoch,
    string $timestamp,
    string $inputHash,
    string $resultHash
): array {
    $seconds = strtotime($timestamp);
    if ($seconds === false) {
        recovery_fail('invalid test timestamp');
    }
    return [
        'artifact_hash' => $receipt['artifact_hash'],
        'attempt' => $attempt,
        'claim_epoch' => $epoch,
        'claim_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $seconds + 30),
        'claimant' => $claimant,
        'format' => RollbackControl::EVENT_FORMAT,
        'generation' => $receipt['generation'],
        'input_sha256' => $inputHash,
        'operation_id' => $operationId,
        'operation_status' => $operationStatus,
        'owner' => $receipt['owner'],
        'previous_event_sha256' => $status['head_event_sha256'] ?? str_repeat('0', 64),
        'receipt_id' => $receipt['receipt_id'],
        'result_sha256' => $resultHash,
        'sequence' => isset($status['sequence']) ? (int) $status['sequence'] + 1 : 1,
        'signing_key_id' => $receipt['signing_key_id'],
        'state' => $state,
        'target_id' => $receipt['target_id'],
        'timestamp' => $timestamp,
    ];
}

/** @return array<string,mixed> */
function recovery_submit_event(string $root, array $event, ?array $receipt, string $keyId, string $secret): array {
    $request = [
        'action' => $receipt === null ? 'append' : 'claim',
        'event' => RollbackControl::sign($event, $keyId, $secret),
        'receipt' => $receipt === null ? null : RollbackControl::sign($receipt, $keyId, $secret),
    ];
    $path = tempnam(sys_get_temp_dir(), 'wprism-authority-request-');
    if ($path === false) {
        recovery_fail('could not allocate authority request');
    }
    recovery_write($path, RollbackControl::canonical($request) . "\n");
    try {
        return RollbackControl::handleRequest($root, $path);
    } finally {
        @unlink($path);
    }
}

/** @return array<string,mixed> */
function recovery_exclusion_payload(array $receipt, string $action, string $claimant, int $epoch, string $timestamp): array {
    return [
        'action' => $action,
        'artifact_hash' => $receipt['artifact_hash'],
        'claim_epoch' => $epoch,
        'claimant' => $claimant,
        'format' => 'wprism-exclusion-request/v1',
        'generation' => $receipt['generation'],
        'owner' => $receipt['owner'],
        'receipt_id' => $receipt['receipt_id'],
        'target_id' => $receipt['target_id'],
        'timestamp' => $timestamp,
    ];
}

$providerSource = <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
function canon(array $v): string { ksort($v, SORT_STRING); foreach ($v as $k => $x) { if (is_array($x)) { $v[$k] = array_is_list($x) ? array_map(fn($i) => is_array($i) ? json_decode(canon($i), true) : $i, $x) : json_decode(canon($x), true); } } return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$statePath = $argv[1];
$action = (string) ($request['action'] ?? '');
if (($request['format'] ?? '') !== 'wprism-exclusion-provider-request/v2') { fwrite(STDERR, "provider requires request v2\n"); exit(44); }
if ($action === 'keepalive' && is_file($statePath . '.fail-keepalive')) { fwrite(STDERR, "keepalive failed closed\n"); exit(41); }
$state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR) : null;
if ($action === 'probe') { $token = null; $providerState = 'ready'; }
elseif ($action === 'acquire') {
    if ($state !== null && $state['state'] === 'held' && $state['receipt_id'] !== $request['receipt_id']) { fwrite(STDERR, "foreign reservation\n"); exit(42); }
    $token = is_array($state) && $state['state'] === 'held'
        ? $state['token']
        : ('opaque-' . hash('sha256', canon($request)));
    $state = ['claim_epoch' => $request['claim_epoch'], 'claimant' => $request['claimant'], 'receipt_id' => $request['receipt_id'], 'state' => 'held', 'token' => $token];
    file_put_contents($statePath, canon($state) . "\n"); chmod($statePath, 0600); $providerState = 'held';
} else {
    if (!is_array($state) || !hash_equals((string) $state['token'], (string) ($request['token'] ?? '')) || $state['state'] !== 'held') { fwrite(STDERR, "not held\n"); exit(43); }
    $token = $state['token'];
    if ($action === 'adopt') { $state['claimant'] = $request['claimant']; $state['claim_epoch'] = $request['claim_epoch']; }
    if ($action === 'release') { $state['state'] = 'released'; $providerState = 'released'; } else { $providerState = 'held'; }
    file_put_contents($statePath, canon($state) . "\n"); chmod($statePath, 0600);
}
$scopes = ['background_jobs' => true, 'database_writers' => true, 'filesystem_writers' => true, 'package_updates' => true, 'public_traffic' => true];
if (is_file($statePath . '.bad-scopes')) { unset($scopes['database_writers']); }
$responseFormat = is_file($statePath . '.legacy-provider-format') ? 'wprism-exclusion-provider-response/v1' : 'wprism-exclusion-provider-response/v2';
$response = ['available' => true, 'disconnect_behavior' => 'remain_excluded', 'format' => $responseFormat, 'provider_id' => 'fixture-provider', 'provider_version' => '1.0.0', 'scopes' => $scopes, 'state' => $providerState, 'target_id' => $request['target_id'], 'token' => $token];
echo canon($response) . "\n";
PHP;

$adapterSource = <<<'PHP'
#!/usr/bin/env php
<?php
declare(strict_types=1);
function canon_adapter(array $v): string { ksort($v, SORT_STRING); foreach ($v as $k => $x) { if (is_array($x)) { $v[$k] = array_is_list($x) ? array_map(fn($i) => is_array($i) ? json_decode(canon_adapter($i), true) : $i, $x) : json_decode(canon_adapter($x), true); } } return json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR); }
$request = json_decode((string) stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR);
$adapter = (string) $request['adapter'];
$execute = ($request['action'] ?? '') === 'execute';
$result = $execute ? hash('sha256', $adapter . ':' . (string) $request['input_sha256']) : null;
echo canon_adapter(['adapter' => $adapter, 'adapter_version' => '1.0.0', 'available' => true, 'format' => 'wprism-recovery-adapter-response/v1', 'input_sha256' => $request['input_sha256'], 'loads_site_code' => false, 'result_sha256' => $result, 'status' => $execute ? 'completed' : 'ready']) . "\n";
PHP;

try {
    mkdir($tmp, 0700, true);
    $provider = $tmp . '/provider.php';
    $adapter = $tmp . '/adapter.php';
    $providerState = $tmp . '/provider-state.json';
    recovery_write($provider, $providerSource . "\n", 0700);
    recovery_write($adapter, $adapterSource . "\n", 0700);
    $root = $tmp . '/host/.wprism/control';
    $initial = RollbackControl::initialize($root);
    RollbackControl::installPublicKey($root, $keyId, base64_encode($public));

    $commands = [];
    foreach (['code_restore', 'database_restore', 'prior_verify', 'storage_restore'] as $name) {
        $commands[$name] = [PHP_BINARY, $adapter];
    }
    $config = ['adapters' => $commands, 'exclusion_provider' => [PHP_BINARY, $provider, $providerState], 'format' => 'wprism-recovery-config/v1', 'timeout_seconds' => 2];
    $configPath = $tmp . '/config.json';
    recovery_write($configPath, RollbackControl::canonical($config) . "\n");
    RecoveryExecutor::configureFromFile($root, $configPath);
    $probe = RecoveryExecutor::probe($root);
    recovery_ok($probe['scopes'] === ['background_jobs' => true, 'database_writers' => true, 'filesystem_writers' => true, 'package_updates' => true, 'public_traffic' => true], 'preflight attests traffic, every database writer, jobs, package updates, and filesystem writers');
    recovery_ok(count($probe['adapters']) === 4, 'preflight probes every named raw recovery adapter');

    touch($providerState . '.legacy-provider-format');
    recovery_refuses(fn() => RecoveryExecutor::probe($root), 'legacy v1 provider evidence is rejected before generation mutation');
    unlink($providerState . '.legacy-provider-format');
    touch($providerState . '.bad-scopes');
    recovery_refuses(fn() => RecoveryExecutor::probe($root), 'missing database-writer attestation blocks before generation mutation');
    unlink($providerState . '.bad-scopes');
    recovery_ok(RollbackControl::status($root)['generation'] === 0, 'failed preflight leaves rollback authority unmodified');

    $receipt = recovery_receipt($initial, str_repeat('0', 64));
    $acquire = recovery_submit_exclusion($root, recovery_exclusion_payload($receipt, 'acquire', 'worker-a', 1, '2026-01-01T00:00:00Z'), $keyId, $secret);
    $receipt['exclusion_token_sha256'] = $acquire['token_sha256'];
    recovery_ok($acquire['state'] === 'held', 'acquisition persists a fail-closed exclusion reservation');
    recovery_ok(
        RecoveryExecutor::decorateStatus($root, RollbackControl::status($root))['exclusion_reservation']['reserved_at']
            === '2026-01-01T00:00:00Z',
        'pre-receipt reservation exposes its stable retry timestamp without the opaque token'
    );
    recovery_ok((fileperms($root . '/exclusion.json') & 0077) === 0, 'opaque provider token is protected mode 0600');
    recovery_ok(!str_contains((string) file_get_contents($root . '/target.json'), 'opaque-'), 'opaque provider token never enters authority metadata');

    $badReceipt = $receipt;
    $badReceipt['exclusion_token_sha256'] = hash('sha256', 'wrong-token');
    $emptyStatus = ['head_event_sha256' => str_repeat('0', 64)];
    $first = recovery_event($badReceipt, $emptyStatus, 'prepared', 'state_transition', 'promotion-claim', 1, 'worker-a', 1, '2026-01-01T00:00:00Z', hash('sha256', 'claim'), str_repeat('0', 64));
    recovery_refuses(fn() => recovery_submit_event($root, $first, $badReceipt, $keyId, $secret), 'claim refuses a receipt not bound to the held token hash');

    $first = recovery_event($receipt, $emptyStatus, 'prepared', 'state_transition', 'promotion-claim', 1, 'worker-a', 1, '2026-01-01T00:00:00Z', hash('sha256', 'claim'), str_repeat('0', 64));
    $status = recovery_submit_event($root, $first, $receipt, $keyId, $secret);
    recovery_ok($status['state'] === 'prepared', 'signed prepared receipt claims only after exact exclusion verification');
    $decorated = RecoveryExecutor::decorateStatus($root, $status);
    recovery_ok($decorated['exclusion_state'] === 'held', 'SSH disconnect at prepared preserves exclusion');

    $clock = 1;
    foreach (['promoting', 'verifying_new', 'rollback_pending', 'rolling_back'] as $state) {
        $timestamp = sprintf('2026-01-01T00:00:%02dZ', $clock++);
        $event = recovery_event($receipt, $status, $state, 'state_transition', 'transition-' . $state, 1, 'worker-a', 1, $timestamp, hash('sha256', $state), str_repeat('0', 64));
        $status = recovery_submit_event($root, $event, null, $keyId, $secret);
        recovery_ok(RecoveryExecutor::decorateStatus($root, $status)['exclusion_state'] === 'held', "SSH disconnect at $state preserves exclusion");
    }

    $input = $tmp . '/database-restore.json';
    recovery_write($input, RollbackControl::canonical(['checkpoint' => hash('sha256', 'checkpoint')]) . "\n");
    $inputHash = hash_file('sha256', $input);
    $prepared = recovery_event($receipt, $status, 'rolling_back', 'prepared', 'database_restore', 1, 'worker-a', 1, '2026-01-01T00:00:05Z', (string) $inputHash, str_repeat('0', 64));
    $status = recovery_submit_event($root, $prepared, null, $keyId, $secret);
    $brokenBootstrap = $tmp . '/wp-config.php';
    recovery_write($brokenBootstrap, "<?php throw new RuntimeException('must never load');\n");
    $execution = RecoveryExecutor::execute($root, 'database_restore', 'database_restore', 1, 'worker-a', 1, $input);
    recovery_ok($execution['ok'] === true && preg_match('/^[0-9a-f]{64}$/', $execution['result_sha256']) === 1, 'prepared database restore runs through the isolated argv executor');
    recovery_ok(is_file($brokenBootstrap), 'broken WordPress bootstrap is irrelevant to raw recovery execution');
    $wrongInput = $tmp . '/wrong.json';
    recovery_write($wrongInput, RollbackControl::canonical(['checkpoint' => 'wrong']) . "\n");
    recovery_refuses(fn() => RecoveryExecutor::execute($root, 'database_restore', 'database_restore', 1, 'worker-a', 1, $wrongInput), 'executor refuses input not authorized by the signed prepared event');
    $completed = recovery_event($receipt, $status, 'rolling_back', 'completed', 'database_restore', 1, 'worker-a', 1, '2026-01-01T00:00:06Z', (string) $inputHash, (string) $execution['result_sha256']);
    $status = recovery_submit_event($root, $completed, null, $keyId, $secret);

    foreach (['code_restore', 'storage_restore'] as $index => $adapterName) {
        $adapterInput = $tmp . '/' . $adapterName . '.json';
        recovery_write($adapterInput, RollbackControl::canonical(['descriptor' => hash('sha256', $adapterName)]) . "\n");
        $adapterHash = (string) hash_file('sha256', $adapterInput);
        $second = 7 + ($index * 2);
        $prepared = recovery_event($receipt, $status, 'rolling_back', 'prepared', $adapterName, 1, 'worker-a', 1, sprintf('2026-01-01T00:00:%02dZ', $second), $adapterHash, str_repeat('0', 64));
        $status = recovery_submit_event($root, $prepared, null, $keyId, $secret);
        $adapterResult = RecoveryExecutor::execute($root, $adapterName, $adapterName, 1, 'worker-a', 1, $adapterInput);
        $completed = recovery_event($receipt, $status, 'rolling_back', 'completed', $adapterName, 1, 'worker-a', 1, sprintf('2026-01-01T00:00:%02dZ', $second + 1), $adapterHash, (string) $adapterResult['result_sha256']);
        $status = recovery_submit_event($root, $completed, null, $keyId, $secret);
    }
    recovery_ok(true, 'isolated executor reaches prepared code and storage restore adapters');

    touch($providerState . '.fail-keepalive');
    $keepalive = recovery_exclusion_payload($receipt, 'keepalive', 'worker-a', 1, '2026-01-01T00:00:20Z');
    recovery_refuses(fn() => recovery_submit_exclusion($root, $keepalive, $keyId, $secret), 'failed keepalive reports failure without releasing exclusion');
    unlink($providerState . '.fail-keepalive');
    recovery_ok(RecoveryExecutor::decorateStatus($root, $status)['exclusion_state'] === 'held', 'failed keepalive leaves target fail-closed and adoptable');

    $takeover = recovery_event($receipt, $status, 'rolling_back', 'takeover', 'operator-takeover', 1, 'rescuer', 2, '2026-01-01T01:00:00Z', hash('sha256', 'takeover'), str_repeat('0', 64));
    $status = recovery_submit_event($root, $takeover, null, $keyId, $secret);
    recovery_refuses(fn() => RecoveryExecutor::decorateStatus($root, $status), 'authority takeover stays non-green until provider token adoption');
    $adopt = recovery_exclusion_payload($receipt, 'adopt', 'rescuer', 2, '2026-01-01T01:00:01Z');
    recovery_submit_exclusion($root, $adopt, $keyId, $secret);
    recovery_submit_exclusion($root, $adopt, $keyId, $secret);
    recovery_ok(RecoveryExecutor::decorateStatus($root, $status)['exclusion_state'] === 'held', 'new authorized controller adopts the held token without WordPress');

    $release = recovery_exclusion_payload($receipt, 'release', 'rescuer', 2, '2026-01-01T01:00:02Z');
    recovery_refuses(fn() => recovery_submit_exclusion($root, $release, $keyId, $secret), 'release is impossible before signed committed or rolled_back state');
    $verifyPrior = recovery_event($receipt, $status, 'verifying_prior', 'state_transition', 'verify-prior', 1, 'rescuer', 2, '2026-01-01T01:00:03Z', hash('sha256', 'prior'), str_repeat('0', 64));
    $status = recovery_submit_event($root, $verifyPrior, null, $keyId, $secret);
    recovery_ok(RecoveryExecutor::decorateStatus($root, $status)['exclusion_state'] === 'held', 'SSH disconnect at verifying_prior preserves exclusion');
    $priorInput = $tmp . '/prior-verify.json';
    recovery_write($priorInput, RollbackControl::canonical(['verifiers' => ['receipt', 'code', 'database', 'storage']]) . "\n");
    $priorHash = (string) hash_file('sha256', $priorInput);
    $priorPrepared = recovery_event($receipt, $status, 'verifying_prior', 'prepared', 'prior_verify', 1, 'rescuer', 2, '2026-01-01T01:00:04Z', $priorHash, str_repeat('0', 64));
    $status = recovery_submit_event($root, $priorPrepared, null, $keyId, $secret);
    $priorResult = RecoveryExecutor::execute($root, 'prior_verify', 'prior_verify', 1, 'rescuer', 2, $priorInput);
    $priorCompleted = recovery_event($receipt, $status, 'verifying_prior', 'completed', 'prior_verify', 1, 'rescuer', 2, '2026-01-01T01:00:05Z', $priorHash, (string) $priorResult['result_sha256']);
    $status = recovery_submit_event($root, $priorCompleted, null, $keyId, $secret);
    recovery_ok($priorResult['ok'] === true, 'isolated prior verifier completes from signed prepared inputs');
    $rolledBack = recovery_event($receipt, $status, 'rolled_back', 'state_transition', 'rollback-complete', 1, 'rescuer', 2, '2026-01-01T01:00:06Z', hash('sha256', 'done'), str_repeat('0', 64));
    $status = recovery_submit_event($root, $rolledBack, null, $keyId, $secret);
    $released = recovery_submit_exclusion($root, recovery_exclusion_payload($receipt, 'release', 'rescuer', 2, '2026-01-01T01:00:07Z'), $keyId, $secret);
    recovery_ok($released['state'] === 'released', 'signed rolled_back terminal authority permits provider release');
    recovery_ok(RecoveryExecutor::decorateStatus($root, $status)['exclusion_state'] === 'released', 'terminal status verifies durable release evidence');

    $badConfig = $config;
    $badConfig['adapters']['code_restore'] = ['/definitely/missing/wprism-adapter'];
    $badPath = $tmp . '/bad-config.json';
    recovery_write($badPath, RollbackControl::canonical($badConfig) . "\n");
    recovery_refuses(fn() => RecoveryExecutor::configureFromFile($root, $badPath), 'unavailable recovery dependency blocks configuration');

    $receipt2 = $receipt;
    $receipt2['artifact_hash'] = hash('sha256', 'artifact-2');
    $receipt2['generation'] = 2;
    $receipt2['receipt_id'] = str_repeat('b', 48);
    $next = recovery_submit_exclusion($root, recovery_exclusion_payload($receipt2, 'acquire', 'worker-b', 1, '2026-01-01T01:00:08Z'), $keyId, $secret);
    $reserved = RecoveryExecutor::decorateStatus($root, $status);
    recovery_ok($next['generation'] === 2 && $reserved['exclusion_reservation']['generation'] === 2, 'released terminal generation can reserve the exact next generation');

    echo "PASS: fatal-safe recovery executor + exclusion contract regression\n";
} finally {
    sodium_memzero($secret);
    recovery_remove_tree($tmp);
}
