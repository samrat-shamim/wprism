<?php
declare(strict_types=1);

// DUO-3296: offline certification for immutable descriptor-bound releases,
// atomic pointer selection/restore, crash retries, tamper refusal, and
// receipt/generation/retention fences.

require dirname(__DIR__, 2) . '/recovery/rollback-control.php';

use Duo\Recovery\CodeRelease;
use Duo\Recovery\RecoveryExecutor;
use Duo\Recovery\RollbackControl;

$tmp = sys_get_temp_dir() . '/duo-code-release-regress-' . bin2hex(random_bytes(8));
$keyId = 'code-release-test';
$pair = sodium_crypto_sign_keypair();
$secret = sodium_crypto_sign_secretkey($pair);
$public = sodium_crypto_sign_publickey($pair);

function cr_fail(string $message): never { fwrite(STDERR, "FAIL: $message\n"); exit(1); }
function cr_ok(bool $condition, string $message): void { if (!$condition) cr_fail($message); echo "ok: $message\n"; }
function cr_refuses(callable $call, string $message): void { try { $call(); } catch (Throwable $e) { echo "ok: $message [{$e->getMessage()}]\n"; return; } cr_fail($message); }
function cr_write(string $path, string $bytes, int $mode = 0600): void { $dir = dirname($path); if (!is_dir($dir)) mkdir($dir, 0700, true); if (file_put_contents($path, $bytes) !== strlen($bytes)) cr_fail("write $path"); chmod($path, $mode); }
function cr_remove(string $path): void { if (is_link($path) || is_file($path)) { @unlink($path); return; } if (!is_dir($path)) return; foreach (scandir($path) ?: [] as $name) if ($name !== '.' && $name !== '..') cr_remove($path . '/' . $name); @rmdir($path); }
/** @return array<string,mixed> */
function cr_signed(string $root, array $payload, string $kind, string $keyId, string $secret): array {
    $path = tempnam(sys_get_temp_dir(), 'duo-code-release-request-'); if ($path === false) cr_fail('temp request');
    cr_write($path, RollbackControl::canonical(RollbackControl::sign($payload, $keyId, $secret)) . "\n");
    try { return $kind === 'release' ? CodeRelease::handleRequest($root, $path) : RecoveryExecutor::handleExclusionRequest($root, $path); } finally { @unlink($path); }
}
/** @return array<string,mixed> */
function cr_authority(string $root, array $event, ?array $receipt, string $keyId, string $secret): array {
    $request = ['action' => $receipt === null ? 'append' : 'claim', 'event' => RollbackControl::sign($event, $keyId, $secret), 'receipt' => $receipt === null ? null : RollbackControl::sign($receipt, $keyId, $secret)];
    $path = tempnam(sys_get_temp_dir(), 'duo-authority-request-'); if ($path === false) cr_fail('temp authority');
    cr_write($path, RollbackControl::canonical($request) . "\n"); try { return RollbackControl::handleRequest($root, $path); } finally { @unlink($path); }
}
/** @return array<string,mixed> */
function cr_event(array $receipt, array $status, string $state, string $operationStatus, string $operationId, string $timestamp, string $input, string $result): array {
    $seconds = strtotime($timestamp); if ($seconds === false) cr_fail('timestamp');
    return ['artifact_hash' => $receipt['artifact_hash'], 'attempt' => 1, 'claim_epoch' => 1,
        'claim_expires_at' => gmdate('Y-m-d\TH:i:s\Z', $seconds + 30), 'claimant' => 'worker-a',
        'format' => RollbackControl::EVENT_FORMAT, 'generation' => $receipt['generation'], 'input_sha256' => $input,
        'operation_id' => $operationId, 'operation_status' => $operationStatus, 'owner' => $receipt['owner'],
        'previous_event_sha256' => $status['head_event_sha256'] ?? str_repeat('0', 64), 'receipt_id' => $receipt['receipt_id'],
        'result_sha256' => $result, 'sequence' => isset($status['sequence']) ? (int) $status['sequence'] + 1 : 1,
        'signing_key_id' => $receipt['signing_key_id'], 'state' => $state, 'target_id' => $receipt['target_id'], 'timestamp' => $timestamp];
}
/** @return array<string,mixed> */
function cr_descriptor(string $role, string $release, string $artifact, string $revision, int $generation, string $fileHash): array {
    return ['artifact_hash' => $artifact, 'code_revision' => $revision,
        'files' => [['path' => 'wp-content/plugins/acme', 'sha256' => hash('sha256', ''), 'type' => 'directory'], ['path' => 'wp-content/plugins/acme/acme.php', 'sha256' => $fileHash, 'type' => 'file']],
        'format' => 'duo-code-release-descriptor/v1', 'generation' => $generation,
        'owned_roots' => ['wp-content/plugins/acme'], 'release_id' => $release, 'role' => $role];
}

try {
    mkdir($tmp, 0700, true);
    $root = $tmp . '/site/.duo/control'; $initial = RollbackControl::initialize($root); RollbackControl::installPublicKey($root, $keyId, base64_encode($public));
    $releaseRoot = $tmp . '/releases'; $pointer = $tmp . '/current'; $providerState = $tmp . '/provider-state';
    $priorFile = $releaseRoot . '/release-prior/wp-content/plugins/acme/acme.php';
    $desiredFile = $releaseRoot . '/release-desired-1/wp-content/plugins/acme/acme.php';
    cr_write($priorFile, "<?php echo 'prior';\n", 0644); cr_write($desiredFile, "<?php echo 'desired';\n", 0644); cr_write($pointer, "release-prior\n");
    $provider = dirname(__DIR__) . '/tests/fixtures/code-release-provider.php';
    $exclusion = dirname(__DIR__) . '/tests/fixtures/recovery-exclusion-provider.php';
    $adapter = dirname(__DIR__) . '/tests/fixtures/recovery-adapter.php';
    $adapters = []; foreach (['code_restore', 'database_restore', 'prior_verify', 'storage_restore'] as $name) $adapters[$name] = [PHP_BINARY, $adapter];
    $config = ['adapters' => $adapters, 'code_release_provider' => [PHP_BINARY, $provider, $providerState, $releaseRoot, $pointer],
        'exclusion_provider' => [PHP_BINARY, $exclusion, $tmp . '/exclusion.json'], 'format' => 'duo-recovery-config/v1', 'timeout_seconds' => 3];
    $configPath = $tmp . '/config.json'; cr_write($configPath, RollbackControl::canonical($config) . "\n"); RecoveryExecutor::configureFromFile($root, $configPath);
    $probe = RecoveryExecutor::probe($root);
    cr_ok(($probe['code_release']['build_resolution_off_target'] ?? false) === true && ($probe['code_release']['target_git_history'] ?? true) === false, 'preflight requires off-target build/resolution with no target Git or registry credentials');

    $artifact = hash('sha256', 'artifact-1'); $revision = hash('sha256', 'code-1'); $receiptId = str_repeat('c', 48);
    $desiredDescriptor = cr_descriptor('desired', 'release-desired-1', $artifact, $revision, 1, (string) hash_file('sha256', $desiredFile));
    $desiredDescriptorHash = hash('sha256', RollbackControl::canonical($desiredDescriptor) . "\n");
    $exclusionPayload = ['action' => 'acquire', 'artifact_hash' => $artifact, 'claim_epoch' => 1, 'claimant' => 'worker-a', 'format' => 'duo-exclusion-request/v1', 'generation' => 1, 'owner' => 'controller:test', 'receipt_id' => $receiptId, 'target_id' => $initial['target_id'], 'timestamp' => '2020-01-01T00:00:00Z'];
    cr_signed($root, $exclusionPayload, 'exclusion', $keyId, $secret);
    $prepare = ['action' => 'prepare', 'artifact_hash' => $artifact, 'claim_epoch' => 1, 'claimant' => 'worker-a', 'desired_code_revision' => $revision, 'desired_descriptor_sha256' => $desiredDescriptorHash, 'format' => 'duo-code-release-request/v1', 'generation' => 1, 'owner' => 'controller:test', 'receipt_id' => $receiptId, 'retention_until' => '2020-01-02T00:00:00Z', 'target_id' => $initial['target_id'], 'timestamp' => '2020-01-01T00:00:00Z'];
    $wrong = $prepare; $wrong['target_id'] = str_repeat('f', 32); cr_refuses(fn() => cr_signed($root, $wrong, 'release', $keyId, $secret), 'wrong target refuses before release upload');
    $wrong = $prepare; $wrong['generation'] = 2; cr_refuses(fn() => cr_signed($root, $wrong, 'release', $keyId, $secret), 'wrong target generation refuses before release upload');
    rename($releaseRoot . '/release-prior', $releaseRoot . '/release-prior-missing'); cr_refuses(fn() => cr_signed($root, $prepare, 'release', $keyId, $secret), 'missing exact prior release refuses automatic rollback preparation'); rename($releaseRoot . '/release-prior-missing', $releaseRoot . '/release-prior');
    foreach (['kill-before-upload', 'kill-after-upload', 'kill-after-verification'] as $boundary) { touch($providerState . '.' . $boundary); cr_refuses(fn() => cr_signed($root, $prepare, 'release', $keyId, $secret), "disconnect at $boundary remains unprepared and retryable"); }
    $prepared = cr_signed($root, $prepare, 'release', $keyId, $secret);
    cr_ok($prepared['ok'] === true && $prepared['prior_release_id'] === 'release-prior', 'prepare publishes complete immutable desired/prior descriptors and retains the exact prior pointer');
    cr_ok(RecoveryExecutor::decorateStatus($root, RollbackControl::status($root))['automatic_code_rollback'] === true, 'configured certified target advertises automatic code rollback readiness');

    $h = static fn(string $v): string => hash('sha256', $v);
    $reservation = RecoveryExecutor::decorateStatus($root, RollbackControl::status($root))['exclusion_reservation'];
    $receipt = ['adapter_versions_sha256' => $h('adapters'), 'artifact_hash' => $artifact, 'checkpoint_sha256' => $h('checkpoint'), 'claim_ttl_seconds' => 30, 'code_release_metadata_sha256' => $prepared['code_release_metadata_sha256'],
        'created_at' => '2020-01-01T00:00:00Z', 'encryption_key_id' => 'kms-key', 'exclusion_token_sha256' => $reservation['token_sha256'],
        'format' => RollbackControl::RECEIPT_FORMAT, 'generation' => 1, 'ledger_session_sha256' => $h('ledger'), 'lifecycle_receipts_sha256' => $h('lifecycle'),
        'owner' => 'controller:test', 'prior_code_descriptor_sha256' => $prepared['prior_code_descriptor_sha256'], 'prior_verifier_inputs_sha256' => $h('prior-verifier'),
        'receipt_id' => $receiptId, 'resources_inventory_sha256' => $h('resources'), 'retention_until' => '2020-01-02T00:00:00Z',
        'runtime_fingerprints_sha256' => $h('runtime'), 'signing_key_id' => $keyId, 'target_id' => $initial['target_id'], 'uploads_inventory_sha256' => $h('uploads')];
    $bad = $receipt; $bad['prior_code_descriptor_sha256'] = $h('substituted-prior'); $first = cr_event($bad, [], 'prepared', 'state_transition', 'promotion-claim', '2020-01-01T00:00:00Z', $h('claim'), str_repeat('0', 64));
    cr_refuses(fn() => cr_authority($root, $first, $bad, $keyId, $secret), 'signed receipt refuses a substituted prior descriptor');
    $first = cr_event($receipt, [], 'prepared', 'state_transition', 'promotion-claim', '2020-01-01T00:00:00Z', $h('claim'), str_repeat('0', 64)); $status = cr_authority($root, $first, $receipt, $keyId, $secret);
    $projected = RollbackControl::status($root); cr_ok($projected['code_release_metadata_sha256'] === $prepared['code_release_metadata_sha256'] && $projected['prior_code_descriptor_sha256'] === $prepared['prior_code_descriptor_sha256'], 'public status projects the v2 code-release receipt fields needed by a fresh SSH controller');
    $desiredDescriptorPath = dirname($root) . '/rollback/' . $receiptId . '/artifacts/desired-code-descriptor.json'; $descriptorBytes = (string) file_get_contents($desiredDescriptorPath); file_put_contents($desiredDescriptorPath, $descriptorBytes . ' ');
    cr_refuses(fn() => RecoveryExecutor::decorateStatus($root, $status), 'active status becomes non-green when an immutable release descriptor is tampered'); cr_write($desiredDescriptorPath, $descriptorBytes);
    $metadataPath = dirname($root) . '/rollback/' . $receiptId . '/code-release-metadata.json'; $metadataBytes = (string) file_get_contents($metadataPath); $tamperedMetadata = json_decode($metadataBytes, true, 512, JSON_THROW_ON_ERROR); $tamperedMetadata['provider_version'] = '1.0.1'; cr_write($metadataPath, RollbackControl::canonical($tamperedMetadata) . "\n");
    cr_refuses(fn() => RecoveryExecutor::decorateStatus($root, $status), 'signed receipt makes combined release metadata tampering non-green'); cr_write($metadataPath, $metadataBytes);
    $status = cr_authority($root, cr_event($receipt, $status, 'promoting', 'state_transition', 'promotion-start', '2020-01-01T00:00:01Z', $h('promoting'), str_repeat('0', 64)), null, $keyId, $secret);

    $selectInput = $tmp . '/select.json'; cr_write($selectInput, RollbackControl::canonical(['artifact_hash' => $artifact, 'code_release_metadata_sha256' => $prepared['code_release_metadata_sha256'], 'expected_from_pointer_sha256' => $prepared['prior_pointer_sha256'], 'format' => 'duo-code-release-operation/v1', 'generation' => 1, 'operation' => 'select_desired', 'owner' => 'controller:test', 'receipt_id' => $receiptId, 'target_id' => $initial['target_id']]) . "\n"); $selectHash = (string) hash_file('sha256', $selectInput);
    $status = cr_authority($root, cr_event($receipt, $status, 'promoting', 'prepared', 'code_select', '2020-01-01T00:00:02Z', $selectHash, str_repeat('0', 64)), null, $keyId, $secret);
    $desiredBytes = (string) file_get_contents($desiredFile); file_put_contents($desiredFile, 'changed'); cr_refuses(fn() => RecoveryExecutor::execute($root, 'code_select', 'code_select', 1, 'worker-a', 1, $selectInput), 'changed release file refuses before selection'); cr_ok(trim((string) file_get_contents($pointer)) === 'release-prior', 'changed release refusal leaves the selected pointer untouched'); cr_write($desiredFile, $desiredBytes, 0644);
    cr_write(dirname($desiredFile) . '/unrecorded.php', 'x', 0644); cr_refuses(fn() => RecoveryExecutor::execute($root, 'code_select', 'code_select', 1, 'worker-a', 1, $selectInput), 'unrecorded owned path refuses before selection'); unlink(dirname($desiredFile) . '/unrecorded.php');
    unlink($desiredFile); symlink($priorFile, $desiredFile); cr_refuses(fn() => RecoveryExecutor::execute($root, 'code_select', 'code_select', 1, 'worker-a', 1, $selectInput), 'symlink refuses before selection'); unlink($desiredFile); cr_write($desiredFile, $desiredBytes, 0644);
    cr_write($pointer, "foreign-writer\n"); cr_refuses(fn() => RecoveryExecutor::execute($root, 'code_select', 'code_select', 1, 'worker-a', 1, $selectInput), 'concurrent pointer writer refuses'); cr_write($pointer, "release-prior\n");
    touch($providerState . '.kill-after-pointer'); cr_refuses(fn() => RecoveryExecutor::execute($root, 'code_select', 'code_select', 1, 'worker-a', 1, $selectInput), 'disconnect after pointer swap stays non-green with open operation');
    cr_ok(trim((string) file_get_contents($pointer)) === 'release-desired-1', 'atomic pointer swap survives controller disconnect');
    $selected = RecoveryExecutor::execute($root, 'code_select', 'code_select', 1, 'worker-a', 1, $selectInput);
    $status = cr_authority($root, cr_event($receipt, $status, 'promoting', 'completed', 'code_select', '2020-01-01T00:00:03Z', $selectHash, $selected['result_sha256']), null, $keyId, $secret);
    cr_ok($selected['ok'] === true, 'retry verifies the already-selected exact desired descriptor and completes idempotently');

    $status = cr_authority($root, cr_event($receipt, $status, 'rollback_pending', 'state_transition', 'promotion-failed', '2020-01-01T00:00:04Z', $h('failed'), str_repeat('0', 64)), null, $keyId, $secret);
    $status = cr_authority($root, cr_event($receipt, $status, 'rolling_back', 'state_transition', 'rollback-start', '2020-01-01T00:00:05Z', $h('rollback'), str_repeat('0', 64)), null, $keyId, $secret);
    $restoreInput = $tmp . '/restore.json'; cr_write($restoreInput, RollbackControl::canonical(['artifact_hash' => $artifact, 'code_release_metadata_sha256' => $prepared['code_release_metadata_sha256'], 'expected_from_pointer_sha256' => $prepared['desired_pointer_sha256'], 'format' => 'duo-code-release-operation/v1', 'generation' => 1, 'operation' => 'restore_prior', 'owner' => 'controller:test', 'receipt_id' => $receiptId, 'target_id' => $initial['target_id']]) . "\n"); $restoreHash = (string) hash_file('sha256', $restoreInput);
    $status = cr_authority($root, cr_event($receipt, $status, 'rolling_back', 'prepared', 'code_restore', '2020-01-01T00:00:06Z', $restoreHash, str_repeat('0', 64)), null, $keyId, $secret);
    touch($providerState . '.kill-after-pointer'); cr_refuses(fn() => RecoveryExecutor::execute($root, 'code_restore', 'code_restore', 1, 'worker-a', 1, $restoreInput), 'disconnect after rollback pointer swap remains retryable');
    $restored = RecoveryExecutor::execute($root, 'code_restore', 'code_restore', 1, 'worker-a', 1, $restoreInput);
    $status = cr_authority($root, cr_event($receipt, $status, 'rolling_back', 'completed', 'code_restore', '2020-01-01T00:00:07Z', $restoreHash, $restored['result_sha256']), null, $keyId, $secret);
    cr_ok(trim((string) file_get_contents($pointer)) === 'release-prior', 'rollback retry atomically reselects and verifies every prior descriptor hash');
    $status = cr_authority($root, cr_event($receipt, $status, 'verifying_prior', 'state_transition', 'prior-verification', '2020-01-01T00:00:08Z', $h('verify'), str_repeat('0', 64)), null, $keyId, $secret);
    $status = cr_authority($root, cr_event($receipt, $status, 'rolled_back', 'state_transition', 'rollback-complete', '2020-01-01T00:00:09Z', $h('done'), str_repeat('0', 64)), null, $keyId, $secret);
    $delete = $prepare; $delete['action'] = 'delete'; $delete['timestamp'] = '2020-01-01T23:59:59Z';
    cr_refuses(fn() => cr_signed($root, $delete, 'release', $keyId, $secret), 'retained prior release cannot be deleted before rollback window');
    $delete['timestamp'] = '2020-01-02T00:00:00Z'; cr_refuses(fn() => cr_signed($root, $delete, 'release', $keyId, $secret), 'selected prior release cannot be deleted even after retention');

    // A second generation commits its desired release, making its retained
    // prior eligible for deletion. Kill after physical deletion but before
    // Duo's tombstone, then prove the exact retry converges.
    $releaseExclusion = $exclusionPayload; $releaseExclusion['action'] = 'release'; $releaseExclusion['timestamp'] = '2020-01-02T00:00:01Z';
    cr_signed($root, $releaseExclusion, 'exclusion', $keyId, $secret);
    $desired2File = $releaseRoot . '/release-desired-2/wp-content/plugins/acme/acme.php'; cr_write($desired2File, "<?php echo 'desired-2';\n", 0644);
    $artifact2 = $h('artifact-2'); $revision2 = $h('code-2'); $receiptId2 = str_repeat('d', 48);
    $desiredDescriptor2 = cr_descriptor('desired', 'release-desired-2', $artifact2, $revision2, 2, (string) hash_file('sha256', $desired2File));
    $desiredDescriptorHash2 = hash('sha256', RollbackControl::canonical($desiredDescriptor2) . "\n");
    $exclusion2 = ['action' => 'acquire', 'artifact_hash' => $artifact2, 'claim_epoch' => 1, 'claimant' => 'worker-a', 'format' => 'duo-exclusion-request/v1', 'generation' => 2, 'owner' => 'controller:test', 'receipt_id' => $receiptId2, 'target_id' => $initial['target_id'], 'timestamp' => '2020-01-03T00:00:00Z'];
    cr_signed($root, $exclusion2, 'exclusion', $keyId, $secret);
    $prepare2 = ['action' => 'prepare', 'artifact_hash' => $artifact2, 'claim_epoch' => 1, 'claimant' => 'worker-a', 'desired_code_revision' => $revision2, 'desired_descriptor_sha256' => $desiredDescriptorHash2, 'format' => 'duo-code-release-request/v1', 'generation' => 2, 'owner' => 'controller:test', 'receipt_id' => $receiptId2, 'retention_until' => '2020-01-04T00:00:00Z', 'target_id' => $initial['target_id'], 'timestamp' => '2020-01-03T00:00:00Z'];
    $prepared2 = cr_signed($root, $prepare2, 'release', $keyId, $secret);
    $reservation2 = RecoveryExecutor::decorateStatus($root, RollbackControl::status($root))['exclusion_reservation'];
    $receipt2 = $receipt; foreach (['artifact_hash' => $artifact2, 'checkpoint_sha256' => $h('checkpoint-2'), 'code_release_metadata_sha256' => $prepared2['code_release_metadata_sha256'], 'created_at' => '2020-01-03T00:00:00Z', 'exclusion_token_sha256' => $reservation2['token_sha256'], 'generation' => 2, 'prior_code_descriptor_sha256' => $prepared2['prior_code_descriptor_sha256'], 'receipt_id' => $receiptId2, 'retention_until' => '2020-01-04T00:00:00Z'] as $key => $value) $receipt2[$key] = $value;
    $first2 = cr_event($receipt2, [], 'prepared', 'state_transition', 'promotion-claim', '2020-01-03T00:00:00Z', $h('claim-2'), str_repeat('0', 64)); $status2 = cr_authority($root, $first2, $receipt2, $keyId, $secret);
    $status2 = cr_authority($root, cr_event($receipt2, $status2, 'promoting', 'state_transition', 'promotion-start', '2020-01-03T00:00:01Z', $h('promoting-2'), str_repeat('0', 64)), null, $keyId, $secret);
    $selectInput2 = $tmp . '/select-2.json'; cr_write($selectInput2, RollbackControl::canonical(['artifact_hash' => $artifact2, 'code_release_metadata_sha256' => $prepared2['code_release_metadata_sha256'], 'expected_from_pointer_sha256' => $prepared2['prior_pointer_sha256'], 'format' => 'duo-code-release-operation/v1', 'generation' => 2, 'operation' => 'select_desired', 'owner' => 'controller:test', 'receipt_id' => $receiptId2, 'target_id' => $initial['target_id']]) . "\n"); $selectHash2 = (string) hash_file('sha256', $selectInput2);
    $status2 = cr_authority($root, cr_event($receipt2, $status2, 'promoting', 'prepared', 'code_select', '2020-01-03T00:00:02Z', $selectHash2, str_repeat('0', 64)), null, $keyId, $secret);
    $selected2 = RecoveryExecutor::execute($root, 'code_select', 'code_select', 1, 'worker-a', 1, $selectInput2);
    $status2 = cr_authority($root, cr_event($receipt2, $status2, 'promoting', 'completed', 'code_select', '2020-01-03T00:00:03Z', $selectHash2, $selected2['result_sha256']), null, $keyId, $secret);
    $status2 = cr_authority($root, cr_event($receipt2, $status2, 'verifying_new', 'state_transition', 'verify-new', '2020-01-03T00:00:04Z', $h('verify-new-2'), str_repeat('0', 64)), null, $keyId, $secret);
    $status2 = cr_authority($root, cr_event($receipt2, $status2, 'committed', 'state_transition', 'promotion-complete', '2020-01-03T00:00:05Z', $h('committed-2'), str_repeat('0', 64)), null, $keyId, $secret);
    $delete2 = $prepare2; $delete2['action'] = 'delete'; $delete2['timestamp'] = '2020-01-04T00:00:00Z'; touch($providerState . '.kill-after-delete');
    cr_refuses(fn() => cr_signed($root, $delete2, 'release', $keyId, $secret), 'disconnect after retained-prior deletion stays retryable until tombstone publication');
    $deleted2 = cr_signed($root, $delete2, 'release', $keyId, $secret);
    cr_ok($deleted2['ok'] === true && $deleted2['prior_release_id'] === 'release-prior', 'terminal deletion retry proves prior absence and publishes an idempotent tombstone');
    cr_ok(cr_signed($root, $delete2, 'release', $keyId, $secret) === $deleted2, 'code release deletion retry returns exact tombstone evidence');

    echo "PASS: immutable atomic SSH code release regression\n";
} finally {
    sodium_memzero($secret); cr_remove($tmp);
}
